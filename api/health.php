<?php

function appHealthBuildMeta(): array {
    return [
        'service' => defined('APP_NAME') ? APP_NAME : 'app',
        'version' => defined('APP_VERSION') ? APP_VERSION : null,
        'timestamp' => gmdate('c'),
    ];
}

function appHealthCheckDirectory(string $projectRoot, string $relativePath, bool $needsWrite): array {
    $fullPath = $projectRoot . '/' . $relativePath;
    $exists = is_dir($fullPath);
    $readable = $exists && is_readable($fullPath);
    $writable = $exists && is_writable($fullPath);
    $ok = $exists && $readable && (!$needsWrite || $writable);

    return [
        'name' => $relativePath,
        'type' => 'directory',
        'required' => $needsWrite ? 'read-write' : 'read',
        'ok' => $ok,
        'details' => [
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
        ],
    ];
}

function appHealthCheckFile(string $projectRoot, string $relativePath): array {
    $fullPath = $projectRoot . '/' . $relativePath;
    $exists = is_file($fullPath);
    $readable = $exists && is_readable($fullPath);
    $ok = $exists && $readable;

    return [
        'name' => $relativePath,
        'type' => 'file',
        'required' => 'read',
        'ok' => $ok,
        'details' => [
            'exists' => $exists,
            'readable' => $readable,
        ],
    ];
}

function appHealthCheckDatabase(): array {
    try {
        $pdo = getPDO();
        $stmt = $pdo->query('SELECT 1');
        $result = $stmt ? $stmt->fetchColumn() : false;

        return [
            'ok' => ((string)$result === '1'),
            'details' => [
                'query' => 'SELECT 1',
            ],
            'pdo' => $pdo,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'details' => [
                'error' => 'database_unavailable',
            ],
            'pdo' => null,
        ];
    }
}

function appBuildHealthPayload(): array {
    return [
        'success' => true,
        'status' => 'ok',
        'kind' => 'liveness',
        'meta' => appHealthBuildMeta(),
        'checks' => [
            [
                'name' => 'router',
                'ok' => true,
                'details' => [
                    'bootstrap' => 'loaded',
                ],
            ],
        ],
    ];
}

function appBuildReadyPayload(): array {
    $projectRoot = dirname(__DIR__);
    $databaseCheck = appHealthCheckDatabase();
    $pdo = $databaseCheck['pdo'];

    $checks = [
        [
            'name' => 'database',
            'ok' => $databaseCheck['ok'],
            'details' => $databaseCheck['details'],
        ],
        appHealthCheckDirectory($projectRoot, 'uploads', true),
        appHealthCheckDirectory($projectRoot, 'backups', true),
        appHealthCheckDirectory($projectRoot, 'runtime', true),
        appHealthCheckDirectory($projectRoot, 'api/logs', true),
        appHealthCheckDirectory($projectRoot, 'migrations', false),
        appHealthCheckFile($projectRoot, 'manifest.json'),
    ];

    if ($pdo instanceof PDO) {
        try {
            $schemaStatus = getAppSchemaVersionStatus($pdo);
            $checks[] = [
                'name' => 'schema_status',
                'ok' => true,
                'details' => [
                    'known_migrations' => $schemaStatus['known_migrations'] ?? null,
                    'applied_migrations' => $schemaStatus['applied_migrations'] ?? null,
                    'pending_migrations' => $schemaStatus['pending_migrations'] ?? null,
                    'current_schema_version' => $schemaStatus['current_schema_version'] ?? null,
                    'target_schema_version' => $schemaStatus['target_schema_version'] ?? null,
                ],
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'name' => 'schema_status',
                'ok' => false,
                'details' => [
                    'error' => 'schema_status_unavailable',
                ],
            ];
        }
    }

    foreach (appSecurityBuildRuntimeChecks($projectRoot) as $securityCheck) {
        $checks[] = $securityCheck;
    }

    $failedChecks = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok'])));

    return [
        'success' => !$failedChecks,
        'status' => $failedChecks ? 'fail' : 'ok',
        'kind' => 'readiness',
        'meta' => appHealthBuildMeta(),
        'checks' => $checks,
    ];
}

function handleHealthEndpoint(string $resource, string $method): void {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        jsonResponse([
            'success' => false,
            'status' => 'fail',
            'error' => 'Method not allowed',
        ], 405);
    }

    if ($resource === 'health') {
        jsonResponse(appBuildHealthPayload(), 200);
    }

    if ($resource === 'ready') {
        $payload = appBuildReadyPayload();
        jsonResponse($payload, $payload['status'] === 'ok' ? 200 : 503);
    }

    jsonResponse([
        'success' => false,
        'status' => 'fail',
        'error' => 'Endpoint не найден',
    ], 404);
}
