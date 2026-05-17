<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/migrations.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is CLI-only." . PHP_EOL);
    exit(1);
}

function migratePrintUsage(): void {
    echo "TaskFlow migration runner v1\n";
    echo "Usage:\n";
    echo "  php tools/migrate.php status\n";
    echo "  php tools/migrate.php list\n";
    echo "  php tools/migrate.php plan\n";
    echo "  php tools/migrate.php preflight\n";
    echo "  php tools/migrate.php migrate\n";
}

function migratePrintStatus(array $status): void {
    echo 'app_version: ' . ($status['app_version'] ?? '') . PHP_EOL;
    echo 'migration_table: ' . ($status['migration_table'] ?? '') . PHP_EOL;
    echo 'known_migrations: ' . (int)($status['known_migrations'] ?? 0) . PHP_EOL;
    echo 'applied_migrations: ' . (int)($status['applied_migrations'] ?? 0) . PHP_EOL;
    echo 'pending_migrations: ' . (int)($status['pending_migrations'] ?? 0) . PHP_EOL;
    echo 'current_schema_version: ' . (($status['current_schema_version'] ?? null) ?: 'none') . PHP_EOL;
    echo 'target_schema_version: ' . (($status['target_schema_version'] ?? null) ?: 'none') . PHP_EOL;
}


function migratePrintList(): void {
    $migrations = appLoadMigrations();
    foreach ($migrations as $migration) {
        echo $migration['id'] . ' ' . $migration['name'] . ' - ' . ($migration['description'] ?: 'no description') . PHP_EOL;
    }
}

function migratePrintPlan(array $plan): void {
    if (!$plan) {
        echo "No pending migrations." . PHP_EOL;
        return;
    }

    foreach ($plan as $migration) {
        echo $migration['id'] . ' ' . $migration['name'] . ' - ' . ($migration['description'] ?: 'no description') . ' [' . $migration['file'] . ']' . PHP_EOL;
    }
}

function migratePrintPreflight(array $preflight): void {
    foreach ($preflight['checks'] as $check) {
        echo ($check['ok'] ? '[OK] ' : '[FAIL] ') . $check['name'] . ': ' . $check['details'] . PHP_EOL;
    }
}

$command = $argv[1] ?? 'status';

if (!in_array($command, ['status', 'list', 'plan', 'preflight', 'migrate'], true)) {
    migratePrintUsage();
    exit(1);
}

try {
    $pdo = getPDO();

    if ($command === 'status') {
        appEnsureMigrationTable($pdo);
        migratePrintStatus(appGetSchemaStatus($pdo));
        exit(0);
    }

    if ($command === 'list') {
        migratePrintList();
        exit(0);
    }

    if ($command === 'plan') {
        appEnsureMigrationTable($pdo);
        migratePrintPlan(appBuildMigrationPlan($pdo));
        exit(0);
    }

    if ($command === 'preflight') {
        $preflight = appRunMigrationPreflight($pdo);
        migratePrintPreflight($preflight);
        exit($preflight['ok'] ? 0 : 1);
    }

    $result = appApplyMigrations(
        $pdo,
        static function (string $message): void {
            echo $message . PHP_EOL;
        }
    );

    if (!$result['applied']) {
        echo "No pending migrations." . PHP_EOL;
    } else {
        echo 'Applied migrations: ' . implode(', ', $result['applied']) . PHP_EOL;
    }

    migratePrintStatus(appGetSchemaStatus($pdo));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
