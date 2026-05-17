<?php

require_once __DIR__ . '/app_version.php';

function appMigrationTableName(): string {
    return 'schema_migrations';
}

function appMigrationsPath(): string {
    return dirname(__DIR__) . '/migrations';
}

function appEnsureMigrationTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . appMigrationTableName() . "` (
        `id` VARCHAR(32) NOT NULL,
        `name` VARCHAR(190) NOT NULL,
        `description` TEXT NULL,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function appTableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function appLoadMigrations(): array {
    $path = appMigrationsPath();
    if (!is_dir($path)) {
        return [];
    }

    $files = glob($path . '/*.php');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_STRING);

    $migrations = [];
    foreach ($files as $file) {
        $migration = require $file;
        if (!is_array($migration)) {
            throw new RuntimeException('Migration file must return array: ' . basename($file));
        }

        $id = trim((string)($migration['id'] ?? ''));
        $name = trim((string)($migration['name'] ?? ''));
        $up = $migration['up'] ?? null;

        if ($id === '' || $name === '' || !is_callable($up)) {
            throw new RuntimeException('Invalid migration metadata: ' . basename($file));
        }

        if (isset($migrations[$id])) {
            throw new RuntimeException('Duplicate migration id detected: ' . $id);
        }

        $migration['id'] = $id;
        $migration['name'] = $name;
        $migration['description'] = trim((string)($migration['description'] ?? ''));
        $migration['file'] = $file;
        $migrations[$id] = $migration;
    }

    ksort($migrations, SORT_STRING);
    return $migrations;
}

function appGetAppliedMigrationIds(PDO $pdo): array {
    if (!appTableExists($pdo, appMigrationTableName())) {
        return [];
    }

    $stmt = $pdo->query('SELECT id FROM `' . appMigrationTableName() . '` ORDER BY id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_map('strval', $rows ?: []));
}

function appGetPendingMigrations(PDO $pdo): array {
    $migrations = appLoadMigrations();
    $appliedIds = array_fill_keys(appGetAppliedMigrationIds($pdo), true);

    return array_values(array_filter(
        $migrations,
        static fn(array $migration): bool => !isset($appliedIds[$migration['id']])
    ));
}

function appTargetSchemaVersion(): ?string {
    $migrations = appLoadMigrations();
    if (!$migrations) {
        return null;
    }

    $ids = array_keys($migrations);
    return (string)end($ids);
}

function appCurrentSchemaVersion(PDO $pdo): ?string {
    $appliedIds = appGetAppliedMigrationIds($pdo);
    if (!$appliedIds) {
        return null;
    }

    return (string)end($appliedIds);
}

function appGetSchemaStatus(PDO $pdo): array {
    $migrations = appLoadMigrations();
    $appliedIds = appGetAppliedMigrationIds($pdo);
    $pending = appGetPendingMigrations($pdo);

    return [
        'app_version' => APP_VERSION,
        'migration_table' => appMigrationTableName(),
        'known_migrations' => count($migrations),
        'applied_migrations' => count($appliedIds),
        'pending_migrations' => count($pending),
        'current_schema_version' => $appliedIds ? (string)end($appliedIds) : null,
        'target_schema_version' => $migrations ? (string)array_key_last($migrations) : null,
    ];
}

function appDescribeMigration(array $migration): array {
    return [
        'id' => (string)$migration['id'],
        'name' => (string)$migration['name'],
        'description' => (string)($migration['description'] ?? ''),
        'file' => basename((string)($migration['file'] ?? '')),
    ];
}

function appBuildMigrationPlan(PDO $pdo): array {
    return array_values(array_map(
        'appDescribeMigration',
        appGetPendingMigrations($pdo)
    ));
}

function appRunMigrationPreflight(PDO $pdo): array {
    appEnsureMigrationTable($pdo);

    $migrations = appLoadMigrations();
    $knownIds = array_keys($migrations);
    $appliedIds = appGetAppliedMigrationIds($pdo);
    $pending = appGetPendingMigrations($pdo);
    $unknownAppliedIds = array_values(array_diff($appliedIds, $knownIds));

    $checks = [
        [
            'name' => 'migration_directory',
            'ok' => is_dir(appMigrationsPath()),
            'details' => appMigrationsPath(),
        ],
        [
            'name' => 'migration_table',
            'ok' => appTableExists($pdo, appMigrationTableName()),
            'details' => appMigrationTableName(),
        ],
        [
            'name' => 'known_migrations',
            'ok' => true,
            'details' => (string)count($migrations),
        ],
        [
            'name' => 'applied_migrations',
            'ok' => true,
            'details' => (string)count($appliedIds),
        ],
        [
            'name' => 'pending_migrations',
            'ok' => true,
            'details' => (string)count($pending),
        ],
        [
            'name' => 'unknown_applied_migrations',
            'ok' => !$unknownAppliedIds,
            'details' => $unknownAppliedIds ? implode(', ', $unknownAppliedIds) : 'none',
        ],
    ];

    foreach ($migrations as $migration) {
        $fileName = basename((string)$migration['file']);
        $expectedPrefix = $migration['id'] . '_';
        $checks[] = [
            'name' => 'file_naming:' . $migration['id'],
            'ok' => strncmp($fileName, $expectedPrefix, strlen($expectedPrefix)) === 0,
            'details' => $fileName,
        ];
    }

    foreach ($checks as $check) {
        if (!$check['ok']) {
            return [
                'ok' => false,
                'checks' => $checks,
                'pending' => array_values(array_map('appDescribeMigration', $pending)),
            ];
        }
    }

    return [
        'ok' => true,
        'checks' => $checks,
        'pending' => array_values(array_map('appDescribeMigration', $pending)),
    ];
}

function appRecordMigration(PDO $pdo, array $migration): void {
    $stmt = $pdo->prepare(
        'INSERT INTO `' . appMigrationTableName() . '` (id, name, description) VALUES (?, ?, ?)' 
    );
    $stmt->execute([
        $migration['id'],
        $migration['name'],
        $migration['description'] !== '' ? $migration['description'] : null,
    ]);
}

function appApplyMigrations(PDO $pdo, ?callable $logger = null): array {
    appEnsureMigrationTable($pdo);

    $pending = appGetPendingMigrations($pdo);
    $applied = [];

    foreach ($pending as $migration) {
        if ($logger) {
            $logger('Applying migration ' . $migration['id'] . ' (' . $migration['name'] . ')');
        }

        $migration['up']($pdo);
        appRecordMigration($pdo, $migration);
        $applied[] = $migration['id'];
    }

    return [
        'applied' => $applied,
        'current_schema_version' => appCurrentSchemaVersion($pdo),
        'target_schema_version' => appTargetSchemaVersion(),
    ];
}
