<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/app_version.php';
require_once __DIR__ . '/../api/security.php';

function backupProjectRoot(): string {
    return dirname(__DIR__);
}

function backupDefaultBaseDir(): string {
    return backupProjectRoot() . '/backups';
}

function backupCriticalItems(): array {
    $root = backupProjectRoot();
    $bootstrapItem = backupBootstrapItem();

    $items = [
        [
            'key' => 'uploads',
            'type' => 'dir',
            'source' => $root . '/uploads',
            'target' => 'files/uploads',
            'required' => true,
            'description' => 'Uploaded user and app files',
        ],
        [
            'key' => 'docs',
            'type' => 'dir',
            'source' => $root . '/docs',
            'target' => 'files/docs',
            'required' => false,
            'description' => 'Document templates stored on disk',
        ],
        [
            'key' => 'manifest',
            'type' => 'file',
            'source' => $root . '/manifest.json',
            'target' => 'config/manifest.json',
            'required' => false,
            'description' => 'PWA manifest/delivery metadata',
        ],
    ];

    if ($bootstrapItem !== null) {
        $items[] = $bootstrapItem;
    }

    return $items;
}

function backupBootstrapItem(): ?array {
    $root = backupProjectRoot();
    $lock = appConfigReadInstallLock();
    if (is_array($lock) && trim((string)($lock['installed_at'] ?? '')) !== '') {
        return [
            'key' => 'install_lock',
            'type' => 'file',
            'source' => $root . '/runtime/install.lock',
            'target' => 'config/runtime/install.lock',
            'required' => true,
            'description' => 'Bootstrap lock with DB/JWT/license config',
        ];
    }

    $legacyDbName = trim((string)(appSecurityReadConfigValue('DB_NAME') ?? ''));
    $legacyJwtSecret = trim((string)(appSecurityReadConfigValue('JWT_SECRET') ?? ''));
    if ($legacyDbName !== '' && appSecurityHasStableSecret($legacyJwtSecret)) {
        return [
            'key' => 'legacy_config',
            'type' => 'file',
            'source' => $root . '/api/config.php',
            'target' => 'config/api/config.php',
            'required' => true,
            'description' => 'Legacy install-specific config.php',
        ];
    }

    return null;
}

function backupUsage(string $scriptName, bool $includeRestoreCommands = false): void {
    echo "TaskFlow backup/restore foundation v1\n";
    echo "Usage:\n";
    echo "  php {$scriptName} help\n";

    if ($scriptName === 'tools/backup.php') {
        echo "  php tools/backup.php plan [--output=backups]\n";
        echo "  php tools/backup.php create [--output=backups] [--label=my-tag]\n";
        return;
    }

    if ($includeRestoreCommands) {
        echo "  php tools/restore.php inspect <backup_dir>\n";
        echo "  php tools/restore.php restore-files <backup_dir> [--target-root=. ] [--dry-run|--force]\n";
        echo "  php tools/restore.php restore-db <backup_dir> [--dry-run|--force]\n";
        echo "  php tools/restore.php restore-all <backup_dir> [--target-root=. ] [--dry-run|--force]\n";
    }
}

function backupParseOptions(array $args): array {
    $options = [];

    foreach ($args as $arg) {
        if (strncmp($arg, '--', 2) !== 0) {
            continue;
        }

        $option = substr($arg, 2);
        $parts = explode('=', $option, 2);
        $key = $parts[0];
        $value = $parts[1] ?? true;
        $options[$key] = $value;
    }

    return $options;
}

function backupNormalizePath(string $path): string {
    return str_replace('\\', '/', $path);
}

function backupRelativePath(string $path, string $base): string {
    $path = rtrim(backupNormalizePath($path), '/');
    $base = rtrim(backupNormalizePath($base), '/');

    if (strncmp($path, $base . '/', strlen($base) + 1) === 0) {
        return substr($path, strlen($base) + 1);
    }

    return $path;
}

function backupEnsureDirectory(string $path): void {
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function backupBuildRunDir(string $baseDir, ?string $label = null): string {
    $stamp = gmdate('Ymd_His');
    $suffix = '';

    if ($label !== null && $label !== '') {
        $safeLabel = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label) ?: 'backup';
        $suffix = '_' . trim($safeLabel, '-');
    }

    return rtrim($baseDir, '/\\') . '/taskflow_backup_' . $stamp . $suffix;
}

function backupCountDirectoryStats(string $source): array {
    if (!is_dir($source)) {
        return ['files' => 0, 'directories' => 0, 'bytes' => 0];
    }

    $files = 0;
    $directories = 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $directories++;
            continue;
        }

        if ($item->isFile()) {
            $files++;
            $bytes += (int)$item->getSize();
        }
    }

    return ['files' => $files, 'directories' => $directories, 'bytes' => $bytes];
}

function backupCopyFile(string $source, string $target): int {
    backupEnsureDirectory(dirname($target));

    if (!copy($source, $target)) {
        throw new RuntimeException('Failed to copy file: ' . $source);
    }

    $size = filesize($target);
    return $size === false ? 0 : (int)$size;
}

function backupCopyDirectory(string $source, string $target): array {
    backupEnsureDirectory($target);

    $stats = ['files' => 0, 'directories' => 0, 'bytes' => 0];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = $iterator->getSubPathName();
        $destination = $target . '/' . backupNormalizePath($relative);

        if ($item->isDir()) {
            backupEnsureDirectory($destination);
            $stats['directories']++;
            continue;
        }

        backupEnsureDirectory(dirname($destination));
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Failed to copy file: ' . $item->getPathname());
        }

        $stats['files']++;
        $stats['bytes'] += (int)$item->getSize();
    }

    return $stats;
}

function backupDiscoverTables(PDO $pdo): array {
    $tables = [];
    $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            if (isset($row[0])) {
                $tables[] = (string)$row[0];
            }
        }
    }

    sort($tables, SORT_STRING);
    return $tables;
}

function backupFetchCreateTable(PDO $pdo, string $table): string {
    $stmt = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
        throw new RuntimeException('Failed to fetch CREATE TABLE for ' . $table);
    }

    $create = $row['Create Table'] ?? $row['Create View'] ?? null;
    if (!is_string($create) || $create === '') {
        throw new RuntimeException('Missing CREATE TABLE SQL for ' . $table);
    }

    return $create;
}

function backupSqlValue(PDO $pdo, mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return $pdo->quote((string)$value);
}

function backupDumpDatabase(PDO $pdo, string $targetFile): array {
    backupEnsureDirectory(dirname($targetFile));

    $tables = backupDiscoverTables($pdo);
    $handle = fopen($targetFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Failed to create DB dump file: ' . $targetFile);
    }

    fwrite($handle, "-- TaskFlow backup SQL dump\n");
    fwrite($handle, '-- Generated at ' . gmdate('c') . " UTC\n");
    fwrite($handle, "SET NAMES utf8mb4;\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $summary = [
        'tables' => count($tables),
        'rows' => 0,
        'bytes' => 0,
        'table_rows' => [],
    ];

    foreach ($tables as $table) {
        fwrite($handle, '-- Table: ' . $table . "\n");
        fwrite($handle, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n");
        fwrite($handle, backupFetchCreateTable($pdo, $table) . ";\n\n");

        $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $rowCount = 0;

        if ($stmt) {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns = array_map(
                    static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                    array_keys($row)
                );
                $values = array_map(
                    static fn(mixed $value): string => backupSqlValue($pdo, $value),
                    array_values($row)
                );

                $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
                fwrite($handle, $sql);
                $rowCount++;
            }
        }

        fwrite($handle, "\n");
        $summary['rows'] += $rowCount;
        $summary['table_rows'][$table] = $rowCount;
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    $size = filesize($targetFile);
    $summary['bytes'] = $size === false ? 0 : (int)$size;

    return $summary;
}

function backupWriteJson(string $path, array $payload): void {
    backupEnsureDirectory(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON for ' . $path);
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write file: ' . $path);
    }
}

function backupLoadManifest(string $backupDir): array {
    $path = rtrim($backupDir, '/\\') . '/manifest.json';
    if (!is_file($path)) {
        throw new RuntimeException('Backup manifest not found: ' . $path);
    }

    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read manifest: ' . $path);
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid manifest JSON: ' . $path);
    }

    return $data;
}

function backupListStatements(string $sql): array {
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            $buffer .= $char;
            continue;
        }

        if ($inBlockComment) {
            $buffer .= $char;
            if ($char === '*' && $next === '/') {
                $buffer .= $next;
                $i++;
                $inBlockComment = false;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $buffer .= $char;
                continue;
            }
        }

        if ($char === "'" && !$inDouble) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '"' && !$inSingle) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function backupRestoreDatabase(PDO $pdo, string $sqlFile): array {
    if (!is_file($sqlFile)) {
        throw new RuntimeException('SQL dump not found: ' . $sqlFile);
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('Failed to read SQL dump: ' . $sqlFile);
    }

    $statements = backupListStatements($sql);
    $executed = 0;

    foreach ($statements as $statement) {
        $trimmed = ltrim($statement);
        if ($trimmed === '' || strncmp($trimmed, '--', 2) === 0 || strncmp($trimmed, '#', 1) === 0) {
            continue;
        }

        $pdo->exec($statement);
        $executed++;
    }

    return ['statements' => $executed];
}

function backupFormatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return number_format($value, $unitIndex === 0 ? 0 : 2, '.', '') . ' ' . $units[$unitIndex];
}
