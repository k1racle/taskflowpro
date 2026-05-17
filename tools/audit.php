<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/audit.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is CLI-only." . PHP_EOL);
    exit(1);
}

function auditToolProjectRoot(): string {
    return dirname(__DIR__);
}

function auditToolParseOptions(array $args): array {
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

function auditToolNormalizePath(string $path): string {
    return str_replace('\\', '/', $path);
}

function auditToolUsage(): void {
    echo "TaskFlow audit retention/export tool v1\n";
    echo "Usage:\n";
    echo "  php tools/audit.php help\n";
    echo "  php tools/audit.php retention\n";
    echo "  php tools/audit.php export [--output=backups/audit_exports/audit-YYYYMMDD.jsonl] [--format=jsonl] [--limit=1000] [--event-type=auth.login.failed] [--actor=ivanov] [--login=ivanov] [--target-type=user] [--target-id=15] [--date-from=2026-05-01] [--date-to=2026-05-07]\n";
    echo "  php tools/audit.php prune [--before=2025-11-01] [--retention-days=180] [--dry-run|--force]\n";
    echo "  php tools/audit.php maintenance [--before=2025-11-01] [--retention-days=180] [--output=backups/audit_exports/audit-housekeeping.jsonl] [--dry-run|--force]\n";
}

function auditToolParseFilters(array $options): array {
    $filters = [];

    $map = [
        'event-type' => 'event_type',
        'actor' => 'actor_login',
        'actor-login' => 'actor_login',
        'login' => 'login',
        'target-type' => 'target_type',
        'target-id' => 'target_id',
        'ip-address' => 'ip_address',
    ];

    foreach ($map as $optionKey => $filterKey) {
        if (!array_key_exists($optionKey, $options)) {
            continue;
        }

        $value = appAuditNormalizeFilterString($options[$optionKey], $filterKey === 'ip_address' ? 64 : 190);
        if ($value !== null) {
            $filters[$filterKey] = $value;
        }
    }

    $dateFrom = appAuditParseDateFilter($options['date-from'] ?? ($options['from'] ?? null), false);
    if ($dateFrom !== null) {
        $filters['created_from'] = $dateFrom;
    }

    $dateTo = appAuditParseDateFilter($options['date-to'] ?? ($options['to'] ?? null), true);
    if ($dateTo !== null) {
        $filters['created_to'] = $dateTo;
    }

    return $filters;
}

function auditToolResolveExportPath(array $options): ?string {
    if (isset($options['stdout'])) {
        return null;
    }

    if (isset($options['output'])) {
        return rtrim((string)$options['output']);
    }

    $baseDir = auditToolProjectRoot() . '/backups/audit_exports';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Failed to create export directory: ' . $baseDir);
    }

    return $baseDir . '/audit_export_' . gmdate('Ymd_His') . '.jsonl';
}

function auditToolResolveMaintenanceExportPath(array $options, string $cutoff): string {
    if (isset($options['stdout'])) {
        throw new RuntimeException('maintenance mode does not support --stdout. Use file export for safe archive handoff.');
    }

    if (isset($options['output'])) {
        return rtrim((string)$options['output']);
    }

    $baseDir = auditToolProjectRoot() . '/backups/audit_exports';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Failed to create export directory: ' . $baseDir);
    }

    $safeCutoff = preg_replace('/[^0-9]/', '', $cutoff);
    if (!is_string($safeCutoff) || $safeCutoff === '') {
        $safeCutoff = gmdate('YmdHis');
    }

    return $baseDir . '/audit_housekeeping_before_' . $safeCutoff . '_' . gmdate('Ymd_His') . '.jsonl';
}

function auditToolResolveFormat(array $options): string {
    $format = strtolower(trim((string)($options['format'] ?? 'jsonl')));
    if (!in_array($format, ['jsonl'], true)) {
        throw new RuntimeException('Unsupported export format: ' . $format . '. Supported: jsonl');
    }

    return $format;
}

function auditToolResolveLimit(array $options): ?int {
    if (!isset($options['limit'])) {
        return null;
    }

    if (!is_numeric($options['limit'])) {
        throw new RuntimeException('Limit must be numeric.');
    }

    return max(1, (int)$options['limit']);
}

function auditToolWriteJsonLine($stream, array $row): void {
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode audit row to JSON.');
    }

    fwrite($stream, $encoded . PHP_EOL);
}

function auditToolEncodeSummaryValue($value): string {
    if ($value === null) {
        return 'none';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_scalar($value)) {
        return (string)$value;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded !== false ? $encoded : '[unserializable]';
}

function auditToolPrintSummary(array $summary): void {
    foreach ($summary as $key => $value) {
        echo $key . ': ' . auditToolEncodeSummaryValue($value) . PHP_EOL;
    }
}

function auditToolRunExport(PDO $pdo, array $options): int {
    $filters = auditToolParseFilters($options);
    $limit = auditToolResolveLimit($options);
    $format = auditToolResolveFormat($options);
    $outputPath = auditToolResolveExportPath($options);

    $stream = $outputPath === null ? STDOUT : fopen($outputPath, 'wb');
    if ($stream === false) {
        throw new RuntimeException('Failed to open export output.');
    }

    try {
        $exported = auditExport(
            $pdo,
            $filters,
            $limit,
            static function (array $row) use ($stream, $format): void {
                if ($format === 'jsonl') {
                    auditToolWriteJsonLine($stream, $row);
                }
            }
        );
    } finally {
        if ($outputPath !== null && is_resource($stream)) {
            fclose($stream);
        }
    }

    fwrite(STDERR, 'exported: ' . $exported . PHP_EOL);
    fwrite(STDERR, 'filters: ' . json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDERR, 'retention_days: ' . appAuditRetentionDays() . PHP_EOL);
    fwrite(STDERR, 'output: ' . ($outputPath !== null ? auditToolNormalizePath($outputPath) : 'stdout') . PHP_EOL);

    return $exported;
}

function auditToolResolvePruneCutoff(array $options): string {
    if (isset($options['before'])) {
        $cutoff = appAuditParseDateFilter($options['before'], false);
        if ($cutoff === null) {
            throw new RuntimeException('Invalid --before value. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS');
        }

        return $cutoff;
    }

    $retentionDays = null;
    if (isset($options['retention-days'])) {
        if (!is_numeric($options['retention-days'])) {
            throw new RuntimeException('retention-days must be numeric.');
        }
        $retentionDays = max(1, (int)$options['retention-days']);
    }

    return appAuditBuildRetentionCutoff($retentionDays);
}

function auditToolRunPrune(PDO $pdo, array $options): int {
    $dryRun = !isset($options['force']) || isset($options['dry-run']);
    $cutoff = auditToolResolvePruneCutoff($options);
    $result = auditPruneOlderThan($pdo, $cutoff, $dryRun);

    echo 'cutoff_before: ' . $cutoff . PHP_EOL;
    echo 'retention_days_default: ' . appAuditRetentionDays() . PHP_EOL;
    echo 'matching_rows: ' . (int)$result['count'] . PHP_EOL;
    echo 'oldest_created_at: ' . (($result['oldest_created_at'] ?? null) ?: 'none') . PHP_EOL;
    echo 'newest_created_at: ' . (($result['newest_created_at'] ?? null) ?: 'none') . PHP_EOL;

    if ($dryRun) {
        echo 'mode: dry-run' . PHP_EOL;
        echo 'deleted: 0' . PHP_EOL;
        echo 'next_step: rerun with --force after export if the candidate set looks correct' . PHP_EOL;
        return 0;
    }

    echo 'mode: force' . PHP_EOL;
    echo 'deleted: ' . (int)($result['deleted'] ?? 0) . PHP_EOL;
    return 0;
}

function auditToolBuildMaintenanceExportFilters(string $cutoff): array {
    $cutoffTs = strtotime($cutoff);
    if ($cutoffTs === false) {
        throw new RuntimeException('Failed to parse cutoff for maintenance export window.');
    }

    return [
        'created_to' => date('Y-m-d H:i:s', $cutoffTs - 1),
    ];
}

function auditToolRunMaintenance(PDO $pdo, array $options): int {
    $dryRun = !isset($options['force']) || isset($options['dry-run']);
    $cutoff = auditToolResolvePruneCutoff($options);
    $retentionOverride = isset($options['retention-days']) ? max(1, (int)$options['retention-days']) : null;
    $candidate = auditDescribePrunable($pdo, $cutoff);
    $exportPath = auditToolResolveMaintenanceExportPath($options, $cutoff);

    $summary = [
        'command' => 'maintenance',
        'mode' => $dryRun ? 'dry-run' : 'force',
        'retention_days_default' => appAuditRetentionDays(),
        'retention_days_effective' => $retentionOverride !== null ? $retentionOverride : appAuditRetentionDays(),
        'cutoff_before' => $cutoff,
        'candidate_rows' => (int)($candidate['count'] ?? 0),
        'oldest_created_at' => ($candidate['oldest_created_at'] ?? null) ?: 'none',
        'newest_created_at' => ($candidate['newest_created_at'] ?? null) ?: 'none',
        'planned_export_output' => auditToolNormalizePath($exportPath),
    ];

    if ($dryRun) {
        $summary['exported'] = 0;
        $summary['deleted'] = 0;
        $summary['status'] = 'report-only';
        $summary['next_step'] = $summary['candidate_rows'] > 0
            ? 'rerun with --force to export matching rows to file and then prune them'
            : 'nothing to prune under current retention window';
        auditToolPrintSummary($summary);
        return 0;
    }

    if ($summary['candidate_rows'] <= 0) {
        $summary['exported'] = 0;
        $summary['deleted'] = 0;
        $summary['status'] = 'nothing-to-do';
        $summary['next_step'] = 'no rows matched the current retention window';
        auditToolPrintSummary($summary);
        return 0;
    }

    $filters = auditToolBuildMaintenanceExportFilters($cutoff);
    $stream = fopen($exportPath, 'wb');
    if ($stream === false) {
        throw new RuntimeException('Failed to open maintenance export output.');
    }

    try {
        $exported = auditExport(
            $pdo,
            $filters,
            null,
            static function (array $row) use ($stream): void {
                auditToolWriteJsonLine($stream, $row);
            }
        );
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    $pruneResult = auditPruneOlderThan($pdo, $cutoff, false);

    $summary['export_filter_created_to'] = $filters['created_to'];
    $summary['exported'] = $exported;
    $summary['deleted'] = (int)($pruneResult['deleted'] ?? 0);
    $summary['status'] = ($exported === $summary['candidate_rows'] && $summary['deleted'] === $summary['candidate_rows'])
        ? 'completed'
        : 'completed-with-mismatch';
    $summary['next_step'] = 'archive or move the export file to your standard backup/offsite location';
    auditToolPrintSummary($summary);

    return 0;
}

function auditToolPrintRetention(): void {
    echo 'retention_days: ' . appAuditRetentionDays() . PHP_EOL;
    echo 'cutoff_before: ' . appAuditBuildRetentionCutoff() . PHP_EOL;
    echo 'recommended_sequence: maintenance --dry-run -> maintenance --force' . PHP_EOL;
    echo 'manual_sequence: export -> verify export -> prune --dry-run -> prune --force' . PHP_EOL;
}

$command = $argv[1] ?? 'help';
$options = auditToolParseOptions(array_slice($argv, 2));

if (!in_array($command, ['help', 'retention', 'export', 'prune', 'maintenance'], true)) {
    auditToolUsage();
    exit(1);
}

try {
    if ($command === 'help') {
        auditToolUsage();
        echo PHP_EOL;
        echo "Safety defaults:\n";
        echo "  - export writes JSONL to backups/audit_exports/ unless --stdout is used\n";
        echo "  - prune is dry-run unless --force is provided\n";
        echo "  - maintenance is dry-run unless --force is provided\n";
        echo "  - maintenance --force performs export-to-file first and prune second\n";
        echo "  - automatic archive rotation is not built in; export is the archive handoff\n";
        exit(0);
    }

    if ($command === 'retention') {
        auditToolPrintRetention();
        exit(0);
    }

    $pdo = getPDO();

    if ($command === 'export') {
        auditToolRunExport($pdo, $options);
        exit(0);
    }

    if ($command === 'maintenance') {
        auditToolRunMaintenance($pdo, $options);
        exit(0);
    }

    auditToolRunPrune($pdo, $options);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Audit tool failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
