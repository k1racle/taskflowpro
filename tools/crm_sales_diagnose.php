<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_admin_tools.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

function diagnoseParseArgs(array $argv): array
{
    $opts = [
        'client-id' => null,
        'json' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if (str_starts_with($arg, '--client-id=')) {
            $value = (int)substr($arg, 12);
            $opts['client-id'] = $value > 0 ? $value : null;
        }
    }

    return $opts;
}

try {
    $opts = diagnoseParseArgs($argv);
    $report = crmToolsDiagnoseDuplicates([
        'client_id' => $opts['client-id'],
    ]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
