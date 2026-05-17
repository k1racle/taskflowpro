<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runner is CLI-only." . PHP_EOL);
    exit(1);
}

function smokePrintUsage(): void {
    echo "TaskFlow smoke runner v1\n";
    echo "Usage:\n";
    echo "  php tools/smoke.php --base-url=http://localhost\n";
    echo "  php tools/smoke.php --base-url=https://example.com --timeout=10\n";
    echo "\n";
    echo "Options:\n";
    echo "  --base-url=<url>   Base application URL, for example http://localhost\n";
    echo "  --timeout=<sec>    HTTP timeout per request in seconds (default: 5)\n";
    echo "  --help             Show this help\n";
    echo "\n";
    echo "Exit codes:\n";
    echo "  0 - all required checks passed\n";
    echo "  1 - at least one required check failed\n";
    echo "\n";
    echo "Notes:\n";
    echo "  - pending_migrations in /api/ready is reported as warning, not hard fail\n";
    echo "  - this is release verification foundation, not a full test suite\n";
}

function smokeParseOptions(array $args): array {
    $options = [];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (strncmp($arg, '--', 2) !== 0) {
            throw new InvalidArgumentException('Unknown argument: ' . $arg);
        }

        $pair = explode('=', substr($arg, 2), 2);
        $key = $pair[0] ?? '';
        $value = $pair[1] ?? '1';

        if ($key === '') {
            throw new InvalidArgumentException('Invalid option: ' . $arg);
        }

        $options[$key] = $value;
    }

    return $options;
}

function smokeBaseUrl(array $options): string {
    $baseUrl = trim((string)($options['base-url'] ?? ''));
    if ($baseUrl === '') {
        throw new InvalidArgumentException('Option --base-url is required.');
    }

    if (!preg_match('#^https?://#i', $baseUrl)) {
        throw new InvalidArgumentException('Option --base-url must start with http:// or https://');
    }

    return rtrim($baseUrl, '/');
}

function smokeTimeout(array $options): int {
    $raw = (string)($options['timeout'] ?? '5');
    if ($raw === '' || !ctype_digit($raw)) {
        throw new InvalidArgumentException('Option --timeout must be a positive integer.');
    }

    $timeout = (int)$raw;
    if ($timeout <= 0) {
        throw new InvalidArgumentException('Option --timeout must be greater than zero.');
    }

    return $timeout;
}

function smokeFetch(string $url, int $timeout): array {
    $headers = [];
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => $timeout,
            'header' => implode("\r\n", [
                'Accept: application/json, text/html;q=0.9,*/*;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'User-Agent: TaskFlowSmoke/1.0',
            ]),
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    foreach ($responseHeaders as $headerLine) {
        if (stripos($headerLine, 'HTTP/') === 0 && preg_match('#\s(\d{3})\s#', $headerLine, $matches)) {
            $status = (int)$matches[1];
            return [
                'ok' => true,
                'status' => $status,
                'body' => is_string($body) ? $body : '',
                'headers' => $responseHeaders,
            ];
        }
    }

    return [
        'ok' => false,
        'status' => 0,
        'body' => is_string($body) ? $body : '',
        'headers' => $headers,
    ];
}

function smokeJsonDecode(string $body): ?array {
    if ($body === '') {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function smokeFindCheck(array $payload, string $name): ?array {
    foreach (($payload['checks'] ?? []) as $check) {
        if (($check['name'] ?? null) === $name && is_array($check)) {
            return $check;
        }
    }

    return null;
}

function smokeSummarizeHttpCheck(string $label, array $response, callable $assert): array {
    if (!$response['ok']) {
        return [
            'label' => $label,
            'ok' => false,
            'severity' => 'fail',
            'message' => 'No HTTP response received.',
        ];
    }

    return $assert($response);
}

function smokeCheckHealth(string $baseUrl, int $timeout): array {
    $response = smokeFetch($baseUrl . '/api/health', $timeout);

    return smokeSummarizeHttpCheck('/api/health', $response, static function (array $response): array {
        $payload = smokeJsonDecode($response['body']);
        $status = $payload['status'] ?? null;
        $kind = $payload['kind'] ?? null;

        $ok = $response['status'] === 200 && $status === 'ok' && $kind === 'liveness';

        return [
            'label' => '/api/health',
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'fail',
            'message' => $ok
                ? 'Liveness endpoint returned HTTP 200 and status=ok.'
                : 'Expected HTTP 200 with JSON status=ok and kind=liveness.',
            'details' => [
                'http_status' => $response['status'],
                'status' => $status,
                'kind' => $kind,
            ],
        ];
    });
}

function smokeCheckReady(string $baseUrl, int $timeout): array {
    $response = smokeFetch($baseUrl . '/api/ready', $timeout);

    return smokeSummarizeHttpCheck('/api/ready', $response, static function (array $response): array {
        $payload = smokeJsonDecode($response['body']);
        $status = $payload['status'] ?? null;
        $kind = $payload['kind'] ?? null;
        $schemaStatus = is_array($payload) ? smokeFindCheck($payload, 'schema_status') : null;
        $pendingMigrations = $schemaStatus['details']['pending_migrations'] ?? null;

        $ok = $response['status'] === 200 && $status === 'ok' && $kind === 'readiness';
        $message = $ok
            ? 'Readiness endpoint returned HTTP 200 and status=ok.'
            : 'Expected HTTP 200 with JSON status=ok and kind=readiness.';

        if ($ok && is_numeric($pendingMigrations) && (int)$pendingMigrations > 0) {
            $message .= ' pending_migrations=' . (int)$pendingMigrations . ' (warning for release flow context).';
        }

        return [
            'label' => '/api/ready',
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'fail',
            'message' => $message,
            'details' => [
                'http_status' => $response['status'],
                'status' => $status,
                'kind' => $kind,
                'pending_migrations' => $pendingMigrations,
            ],
            'warnings' => ($ok && is_numeric($pendingMigrations) && (int)$pendingMigrations > 0)
                ? ['pending_migrations=' . (int)$pendingMigrations]
                : [],
        ];
    });
}

function smokeCheckUiShell(string $baseUrl, int $timeout): array {
    $response = smokeFetch($baseUrl . '/', $timeout);

    return smokeSummarizeHttpCheck('GET /', $response, static function (array $response): array {
        $body = $response['body'];
        $hasTitle = stripos($body, '<title>TaskFlow Pro</title>') !== false;
        $hasAppShell = stripos($body, 'x-data="app()"') !== false;
        $hasManifestLink = stripos($body, 'manifest.json') !== false;
        $ok = $response['status'] === 200 && $hasTitle && $hasAppShell && $hasManifestLink;

        return [
            'label' => 'GET /',
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'fail',
            'message' => $ok
                ? 'Base UI shell responded with expected HTML markers.'
                : 'Expected HTTP 200 and HTML containing TaskFlow shell markers.',
            'details' => [
                'http_status' => $response['status'],
                'has_title' => $hasTitle,
                'has_app_shell' => $hasAppShell,
                'has_manifest_link' => $hasManifestLink,
            ],
        ];
    });
}

function smokeCheckManifest(string $baseUrl, int $timeout): array {
    $response = smokeFetch($baseUrl . '/manifest.json', $timeout);

    return smokeSummarizeHttpCheck('/manifest.json', $response, static function (array $response): array {
        $payload = smokeJsonDecode($response['body']);
        $name = $payload['name'] ?? null;
        $startUrl = $payload['start_url'] ?? null;
        $ok = $response['status'] === 200 && $name === 'TaskFlow Pro' && is_string($startUrl);

        return [
            'label' => '/manifest.json',
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'fail',
            'message' => $ok
                ? 'Manifest is publicly reachable and looks valid.'
                : 'Expected HTTP 200 and JSON manifest payload with application name.',
            'details' => [
                'http_status' => $response['status'],
                'name' => $name,
                'start_url' => $startUrl,
            ],
        ];
    });
}

function smokeCheckLicenseStatus(string $baseUrl, int $timeout): array {
    $response = smokeFetch($baseUrl . '/api/license/status', $timeout);

    return smokeSummarizeHttpCheck('/api/license/status', $response, static function (array $response): array {
        $payload = smokeJsonDecode($response['body']);
        $success = $payload['success'] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $enabled = $data['enabled'] ?? null;
        $valid = $data['valid'] ?? null;
        $ok = $response['status'] === 200 && $success === true && ($enabled === false || $valid === true);

        return [
            'label' => '/api/license/status',
            'ok' => $ok,
            'severity' => $ok ? 'ok' : 'fail',
            'message' => $ok
                ? 'License status endpoint is reachable and current host is allowed.'
                : 'Expected HTTP 200 and valid license status for current host.',
            'details' => [
                'http_status' => $response['status'],
                'enabled' => $enabled,
                'valid' => $valid,
                'request_domain' => $data['request_domain'] ?? null,
                'licensed_domain' => $data['licensed_domain'] ?? null,
            ],
        ];
    });
}

function smokePrintCheck(array $check): void {
    $prefix = $check['ok'] ? '[OK] ' : '[FAIL] ';
    echo $prefix . $check['label'] . ' - ' . $check['message'] . PHP_EOL;

    foreach (($check['details'] ?? []) as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }

        echo '  ' . $key . ': ' . $value . PHP_EOL;
    }

    foreach (($check['warnings'] ?? []) as $warning) {
        echo '  warning: ' . $warning . PHP_EOL;
    }
}

function smokePrintSummary(array $checks): void {
    $failures = 0;
    $warnings = 0;

    foreach ($checks as $check) {
        if (empty($check['ok'])) {
            $failures++;
        }

        $warnings += count($check['warnings'] ?? []);
    }

    echo PHP_EOL;
    echo 'Summary:' . PHP_EOL;
    echo '  checks: ' . count($checks) . PHP_EOL;
    echo '  failures: ' . $failures . PHP_EOL;
    echo '  warnings: ' . $warnings . PHP_EOL;
    echo '  result: ' . ($failures === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
}

try {
    $options = smokeParseOptions(array_slice($argv, 1));

    if (isset($options['help'])) {
        smokePrintUsage();
        exit(0);
    }

    $baseUrl = smokeBaseUrl($options);
    $timeout = smokeTimeout($options);

    echo 'Smoke target: ' . $baseUrl . PHP_EOL;
    echo 'Timeout: ' . $timeout . 's' . PHP_EOL;
    echo PHP_EOL;

    $checks = [
        smokeCheckHealth($baseUrl, $timeout),
        smokeCheckReady($baseUrl, $timeout),
        smokeCheckUiShell($baseUrl, $timeout),
        smokeCheckManifest($baseUrl, $timeout),
        smokeCheckLicenseStatus($baseUrl, $timeout),
    ];

    foreach ($checks as $check) {
        smokePrintCheck($check);
    }

    smokePrintSummary($checks);

    foreach ($checks as $check) {
        if (empty($check['ok'])) {
            exit(1);
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke runner failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
