<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/crm.php';
require_once __DIR__ . '/crm_admin_tools.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

function mergeParseArgs(array $argv): array
{
    $opts = [
        'apply' => false,
        'client-id' => null,
        'primary-id' => null,
        'group-index' => null,
        'all' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $opts['apply'] = true;
            continue;
        }
        if ($arg === '--all') {
            $opts['all'] = true;
            continue;
        }
        if (str_starts_with($arg, '--client-id=')) {
            $value = (int)substr($arg, 12);
            $opts['client-id'] = $value > 0 ? $value : null;
            continue;
        }
        if (str_starts_with($arg, '--primary-id=')) {
            $value = (int)substr($arg, 13);
            $opts['primary-id'] = $value > 0 ? $value : null;
            continue;
        }
        if (str_starts_with($arg, '--group-index=')) {
            $value = (int)substr($arg, 14);
            $opts['group-index'] = $value >= 0 ? $value : null;
        }
    }

    return $opts;
}

function mergeSelectGroups(array $groups, array $opts): array
{
    if ($opts['group-index'] !== null) {
        $index = (int)$opts['group-index'];
        if (!isset($groups[$index])) {
            throw new RuntimeException('Group index not found: ' . $index);
        }
        return [$groups[$index]];
    }

    if ($opts['all']) {
        return $groups;
    }

    return array_slice($groups, 0, 1);
}

function mergeBuildPreview(PDO $pdo, array $opts): array
{
    return crmToolsMergeBuildPreview($pdo, [
        'client_id' => $opts['client-id'],
        'primary_id' => $opts['primary-id'],
        'group_index' => $opts['group-index'],
        'all' => $opts['all'],
    ]);
}

try {
    $opts = mergeParseArgs($argv);
    $pdo = getPDO();
    $preview = mergeBuildPreview($pdo, $opts);

    if (($preview['selected_groups'] ?? 0) === 0) {
        echo json_encode([
            'success' => true,
            'apply' => $opts['apply'],
            'message' => 'No duplicate groups with monthly sales found for merge.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $result = crmToolsMergeDuplicates([
        'apply' => $opts['apply'],
        'client_id' => $opts['client-id'],
        'primary_id' => $opts['primary-id'],
        'group_index' => $opts['group-index'],
        'all' => $opts['all'],
        'log_source' => 'cli',
    ]);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
