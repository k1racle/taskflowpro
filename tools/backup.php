<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_lib.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is CLI-only." . PHP_EOL);
    exit(1);
}

function backupPrintPlan(array $items, string $baseDir, ?string $label): void {
    echo "Backup plan\n";
    echo 'output_base: ' . backupNormalizePath($baseDir) . PHP_EOL;
    echo 'backup_dir: ' . backupNormalizePath(backupBuildRunDir($baseDir, $label)) . PHP_EOL;

    foreach ($items as $item) {
        $exists = $item['type'] === 'dir' ? is_dir($item['source']) : is_file($item['source']);
        $status = $exists ? 'present' : ($item['required'] ? 'missing-required' : 'missing-optional');
        echo '- ' . $item['key'] . ' [' . $item['type'] . '] ' . $status . PHP_EOL;
        echo '  source: ' . backupNormalizePath($item['source']) . PHP_EOL;
        echo '  target: ' . $item['target'] . PHP_EOL;
    }

    echo '- database [sql] present' . PHP_EOL;
    echo '  source: mysql://' . DB_HOST . '/' . DB_NAME . PHP_EOL;
    echo '  target: db/database.sql' . PHP_EOL;
}

function backupCreate(array $items, string $baseDir, ?string $label): void {
    $backupDir = backupBuildRunDir($baseDir, $label);
    backupEnsureDirectory($backupDir);

    $manifest = [
        'format_version' => 1,
        'created_at_utc' => gmdate('c'),
        'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
        'project_root' => backupNormalizePath(backupProjectRoot()),
        'database' => [
            'driver' => 'mysql',
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'dump_file' => 'db/database.sql',
        ],
        'items' => [],
        'notes' => [
            'Restore assumes compatible application code is already deployed.',
            'This backup intentionally targets DB + critical runtime data, not a full project snapshot.',
        ],
    ];

    $dbTarget = $backupDir . '/db/database.sql';
    echo 'Dumping database to ' . backupNormalizePath($dbTarget) . PHP_EOL;
    $dbSummary = backupDumpDatabase(getPDO(), $dbTarget);
    $manifest['database']['tables'] = $dbSummary['tables'];
    $manifest['database']['rows'] = $dbSummary['rows'];
    $manifest['database']['bytes'] = $dbSummary['bytes'];
    $manifest['database']['table_rows'] = $dbSummary['table_rows'];

    foreach ($items as $item) {
        $exists = $item['type'] === 'dir' ? is_dir($item['source']) : is_file($item['source']);
        if (!$exists) {
            if ($item['required']) {
                throw new RuntimeException('Required backup item is missing: ' . $item['source']);
            }

            $manifest['items'][] = [
                'key' => $item['key'],
                'type' => $item['type'],
                'source' => backupRelativePath($item['source'], backupProjectRoot()),
                'target' => $item['target'],
                'included' => false,
                'required' => $item['required'],
                'description' => $item['description'],
            ];
            continue;
        }

        $target = $backupDir . '/' . $item['target'];
        echo 'Copying ' . $item['key'] . ' to ' . backupNormalizePath($target) . PHP_EOL;

        if ($item['type'] === 'file') {
            $bytes = backupCopyFile($item['source'], $target);
            $manifest['items'][] = [
                'key' => $item['key'],
                'type' => 'file',
                'source' => backupRelativePath($item['source'], backupProjectRoot()),
                'target' => $item['target'],
                'included' => true,
                'required' => $item['required'],
                'description' => $item['description'],
                'files' => 1,
                'directories' => 0,
                'bytes' => $bytes,
            ];
            continue;
        }

        $stats = backupCopyDirectory($item['source'], $target);
        $manifest['items'][] = [
            'key' => $item['key'],
            'type' => 'dir',
            'source' => backupRelativePath($item['source'], backupProjectRoot()),
            'target' => $item['target'],
            'included' => true,
            'required' => $item['required'],
            'description' => $item['description'],
            'files' => $stats['files'],
            'directories' => $stats['directories'],
            'bytes' => $stats['bytes'],
        ];
    }

    backupWriteJson($backupDir . '/manifest.json', $manifest);

    echo PHP_EOL;
    echo 'Backup created: ' . backupNormalizePath($backupDir) . PHP_EOL;
    echo 'Database: ' . $manifest['database']['tables'] . ' tables, ' . $manifest['database']['rows'] . ' rows, ' . backupFormatBytes((int)$manifest['database']['bytes']) . PHP_EOL;
    foreach ($manifest['items'] as $item) {
        echo '- ' . $item['key'] . ': ' . ($item['included'] ? 'included' : 'skipped') ;
        if (!empty($item['included'])) {
            echo ' (' . (int)($item['files'] ?? 0) . ' files, ' . backupFormatBytes((int)($item['bytes'] ?? 0)) . ')';
        }
        echo PHP_EOL;
    }
}

$command = $argv[1] ?? 'help';
$options = backupParseOptions(array_slice($argv, 2));
$baseDir = isset($options['output']) ? (string)$options['output'] : backupDefaultBaseDir();
$label = isset($options['label']) ? (string)$options['label'] : null;

if (!in_array($command, ['help', 'plan', 'create'], true)) {
    backupUsage('tools/backup.php');
    exit(1);
}

try {
    $items = backupCriticalItems();

    if ($command === 'help') {
        backupUsage('tools/backup.php');
        echo PHP_EOL;
        echo "Notes:\n";
        echo "  - Includes DB SQL dump plus critical runtime files only.\n";
        echo "  - Default output dir: backups/\n";
        exit(0);
    }

    if ($command === 'plan') {
        backupPrintPlan($items, $baseDir, $label);
        exit(0);
    }

    backupCreate($items, $baseDir, $label);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
