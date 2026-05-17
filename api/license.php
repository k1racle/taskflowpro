<?php
/**
 * api/license.php - Простая лицензия по домену
 *
 * Логика:
 * - Если LICENSE_DOMAIN пустой -> лицензирование отключено (dev/local).
 * - Иначе сравниваем hostname запроса с LICENSE_DOMAIN.
 *
 * Эндпоинты:
 * - GET /api/license/status
 */

function normalizeHostname(string $host): string {
    $host = trim(strtolower($host));
    // Strip port if present
    $host = preg_replace('/:\d+$/', '', $host);
    // Strip leading www.
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    return $host;
}

function getRequestHostname(): string {
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    if ($host && str_contains($host, ',')) {
        $host = trim(explode(',', $host)[0]);
    }
    return normalizeHostname($host);
}

function isLicenseValid(): bool {
    $licensed = normalizeHostname((string)(LICENSE_DOMAIN ?? ''));
    if ($licensed === '') return true;

    $requestHost = getRequestHostname();
    if ($requestHost === '') return false;

    if ($requestHost === $licensed) return true;
    // Allow any subdomain of the licensed root domain.
    // Example: licensed=example.com -> allow a.example.com, b.c.example.com
    return str_ends_with($requestHost, '.' . $licensed);
}

function requireValidLicense(): void {
    $licensed = normalizeHostname((string)(LICENSE_DOMAIN ?? ''));
    $requestHost = getRequestHostname();
    $valid = isLicenseValid();

    if (!$valid) {
        error_log(sprintf(
            '[LICENSE] deny request_host=%s licensed_domain=%s uri=%s forwarded_host=%s',
            $requestHost !== '' ? $requestHost : '<empty>',
            $licensed !== '' ? $licensed : '<empty>',
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''
        ));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Лицензия недействительна для этого домена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function handleLicense(string $method, ?string $action, mixed $id): void {
    if ($method === 'GET' && $action === 'status') {
        $licensed = normalizeHostname((string)(LICENSE_DOMAIN ?? ''));
        $requestHost = getRequestHostname();
        $valid = isLicenseValid();

        error_log(sprintf(
            '[LICENSE] status enabled=%s valid=%s request_host=%s licensed_domain=%s uri=%s',
            $licensed !== '' ? 'true' : 'false',
            $valid ? 'true' : 'false',
            $requestHost !== '' ? $requestHost : '<empty>',
            $licensed !== '' ? $licensed : '<empty>',
            $_SERVER['REQUEST_URI'] ?? ''
        ));

        echo json_encode([
            'success' => true,
            'data' => [
                'enabled' => ($licensed !== ''),
                'licensed_domain' => $licensed ?: null,
                'request_domain' => $requestHost ?: null,
                'valid' => $valid,
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден'], JSON_UNESCAPED_UNICODE);
}

