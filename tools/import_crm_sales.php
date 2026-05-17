<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_admin_tools.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

function parseArgs(array $argv): array {
    $opts = [
        'file' => null,
        'sheet' => 'База клиентов',
        'clients-sheet' => 'Работа с АКБ',
        'dry-run' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $opts['dry-run'] = true;
            continue;
        }
        if (str_starts_with($arg, '--file=')) {
            $opts['file'] = substr($arg, 7);
            continue;
        }
        if (str_starts_with($arg, '--sheet=')) {
            $opts['sheet'] = substr($arg, 8);
            continue;
        }
        if (str_starts_with($arg, '--clients-sheet=')) {
            $opts['clients-sheet'] = substr($arg, 16);
            continue;
        }
    }

    if ($opts['file'] === null) {
        $matches = glob(__DIR__ . '/../old/*.{xlsx,xlsm,xltx,xltm}', GLOB_BRACE);
        if (count($matches) === 1) {
            $opts['file'] = $matches[0];
        }
    }

    return $opts;
}

function main(array $argv): int {
    $opts = parseArgs($argv);
    $result = crmToolsImportSales([
        'file' => $opts['file'],
        'sheet' => $opts['sheet'],
        'clients_sheet' => $opts['clients-sheet'],
        'dry_run' => $opts['dry-run'],
    ]);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
