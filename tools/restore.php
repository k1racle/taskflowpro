<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_lib.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is CLI-only." . PHP_EOL);
    exit(1);
}

function restoreResolveBackupDir(?string $arg): string {
    if ($arg === null || trim($arg) === '') {
        throw new RuntimeException('Backup directory argument is required.');
    }

    return rtrim((string)$arg, '/\\');
}

function restorePrintInspect(string $backupDir, array $manifest): void {
    echo 'backup_dir: ' . backupNormalizePath($backupDir) . PHP_EOL;
    echo 'created_at_utc: ' . (string)($manifest['created_at_utc'] ?? 'unknown') . PHP_EOL;
    echo 'app_version: ' . (string)($manifest['app_version'] ?? 'unknown') . PHP_EOL;
    echo 'database_dump: ' . (string)($manifest['database']['dump_file'] ?? 'db/database.sql') . PHP_EOL;
    echo 'database_host: ' . (string)($manifest['database']['host'] ?? 'unknown') . PHP_EOL;
    echo 'database_name: ' . (string)($manifest['database']['name'] ?? 'unknown') . PHP_EOL;
    echo 'database_tables: ' . (int)($manifest['database']['tables'] ?? 0) . PHP_EOL;
    echo 'database_rows: ' . (int)($manifest['database']['rows'] ?? 0) . PHP_EOL;
    echo "items:\n";

    foreach (($manifest['items'] ?? []) as $item) {
        echo '- ' . (string)$item['key'] . ': ' . (!empty($item['included']) ? 'included' : 'skipped') . PHP_EOL;
        echo '  target: ' . (string)$item['target'] . PHP_EOL;
        if (!empty($item['included'])) {
            echo '  files: ' . (int)($item['files'] ?? 0) . ', bytes: ' . backupFormatBytes((int)($item['bytes'] ?? 0)) . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo "Recommended sequence:\n";
    echo "  1. Put application into maintenance mode if possible.\n";
    echo "  2. Run restore-files with --dry-run, then --force.\n";
    echo "  3. Run restore-db with --dry-run, then --force.\n";
    echo "  4. Re-run php tools/migrate.php status and smoke-test the app.\n";
}

function restoreParseTargetRoot(array $options): string {
    if (!isset($options['target-root'])) {
        return backupProjectRoot();
    }

    return rtrim((string)$options['target-root'], '/\\');
}

function restoreDryRunEnabled(array $options): bool {
    return !isset($options['force']) || isset($options['dry-run']);
}

function restoreFiles(string $backupDir, array $manifest, string $targetRoot, bool $dryRun): void {
    $items = $manifest['items'] ?? [];
    echo ($dryRun ? '[dry-run] ' : '') . 'Restoring files into ' . backupNormalizePath($targetRoot) . PHP_EOL;

    foreach ($items as $item) {
        if (empty($item['included'])) {
            continue;
        }

        $source = $backupDir . '/' . $item['target'];
        $target = $targetRoot . '/' . ($item['source'] ?? '');

        echo '- ' . (string)$item['key'] . ': ' . backupNormalizePath($source) . ' -> ' . backupNormalizePath($target) . PHP_EOL;

        if ($dryRun) {
            continue;
        }

        if (($item['type'] ?? '') === 'file') {
            backupCopyFile($source, $target);
            continue;
        }

        backupCopyDirectory($source, $target);
    }
}

function restoreDatabase(string $backupDir, array $manifest, bool $dryRun): void {
    $dumpFile = $backupDir . '/' . (string)($manifest['database']['dump_file'] ?? 'db/database.sql');
    echo ($dryRun ? '[dry-run] ' : '') . 'Restoring database from ' . backupNormalizePath($dumpFile) . PHP_EOL;
    echo 'Target DB: mysql://' . DB_HOST . '/' . DB_NAME . PHP_EOL;

    if ($dryRun) {
        return;
    }

    $result = backupRestoreDatabase(getPDO(), $dumpFile);
    echo 'Executed SQL statements: ' . (int)$result['statements'] . PHP_EOL;
}

$command = $argv[1] ?? 'help';

if (!in_array($command, ['help', 'inspect', 'restore-files', 'restore-db', 'restore-all'], true)) {
    backupUsage('tools/restore.php', true);
    exit(1);
}

try {
    if ($command === 'help') {
        backupUsage('tools/restore.php', true);
        echo PHP_EOL;
        echo "Safety defaults:\n";
        echo "  - restore commands are dry-run unless --force is provided\n";
        echo "  - restore does not delete extra files outside backup scope\n";
        exit(0);
    }

    $backupDir = restoreResolveBackupDir($argv[2] ?? null);
    $manifest = backupLoadManifest($backupDir);
    $options = backupParseOptions(array_slice($argv, 3));

    if ($command === 'inspect') {
        restorePrintInspect($backupDir, $manifest);
        exit(0);
    }

    $dryRun = restoreDryRunEnabled($options);
    $targetRoot = restoreParseTargetRoot($options);

    if ($command === 'restore-files') {
        restoreFiles($backupDir, $manifest, $targetRoot, $dryRun);
        exit(0);
    }

    if ($command === 'restore-db') {
        restoreDatabase($backupDir, $manifest, $dryRun);
        exit(0);
    }

    restoreFiles($backupDir, $manifest, $targetRoot, $dryRun);
    restoreDatabase($backupDir, $manifest, $dryRun);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
