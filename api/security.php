<?php

function appSecurityRequestIsHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }

    $forwardedSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    if ($forwardedSsl === 'on') {
        return true;
    }

    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function appSecurityIsLocalRequest(): bool {
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    if ($remoteAddr === '') {
        return false;
    }

    return in_array($remoteAddr, ['127.0.0.1', '::1'], true);
}

function appSecurityApplyApiHeaders(?string $requestOrigin = null): void {
    if (headers_sent()) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-site');

    if (appSecurityRequestIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $requestOrigin = $requestOrigin ?? (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($requestOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
}

function appSecurityGetCookieOptions(int $expires): array {
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => appSecurityRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function appSecurityReadConfigValue(string $constantName): ?string {
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath) || !is_readable($configPath)) {
        return null;
    }

    $contents = file_get_contents($configPath);
    if ($contents === false) {
        return null;
    }

    $pattern = "/define\('" . preg_quote($constantName, '/') . "',\s*(.+?)\);/";
    if (!preg_match($pattern, $contents, $matches)) {
        return null;
    }

    $value = trim($matches[1]);
    if ($value === '') {
        return null;
    }

    if (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === '\'' && substr($value, -1) === '\'')) {
        return stripcslashes(substr($value, 1, -1));
    }

    return $value;
}

function appSecurityIsConfigured(): bool {
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        return false;
    }

    $dbName = defined('DB_NAME') ? trim((string)DB_NAME) : trim((string)(appSecurityReadConfigValue('DB_NAME') ?? ''));
    $jwtSecret = defined('JWT_SECRET') ? trim((string)JWT_SECRET) : trim((string)(appSecurityReadConfigValue('JWT_SECRET') ?? ''));

    return $dbName !== '' && $jwtSecret !== '';
}

function appSecurityEnsureInstallerAvailable(): void {
    if (!appSecurityIsConfigured()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo 'Installer is disabled because the application is already configured.';
    exit;
}

function appSecurityEnsureLocalDebugAccess(): void {
    if (appSecurityIsLocalRequest()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "Debug endpoint is available from localhost only.\n";
    exit;
}

function appSecurityBuildRuntimeChecks(string $projectRoot): array {
    $configPath = $projectRoot . '/api/config.php';
    $jwtSecret = defined('JWT_SECRET') ? trim((string)JWT_SECRET) : trim((string)(appSecurityReadConfigValue('JWT_SECRET') ?? ''));

    return [
        [
            'name' => 'config_present',
            'ok' => is_file($configPath) && is_readable($configPath),
            'details' => [
                'path' => 'api/config.php',
            ],
        ],
        [
            'name' => 'jwt_secret_configured',
            'ok' => $jwtSecret !== '',
            'details' => [
                'configured' => $jwtSecret !== '',
            ],
        ],
        [
            'name' => 'https_detection',
            'ok' => true,
            'details' => [
                'request_https' => appSecurityRequestIsHttps(),
                'hsts_active_for_request' => appSecurityRequestIsHttps(),
            ],
        ],
        [
            'name' => 'installer_exposed',
            'ok' => !is_file($projectRoot . '/install.php') || !appSecurityIsConfigured(),
            'details' => [
                'install_php_present' => is_file($projectRoot . '/install.php'),
                'auto_blocked_after_config' => true,
                'manual_cleanup_recommended' => is_file($projectRoot . '/install.php') && appSecurityIsConfigured(),
            ],
        ],
        [
            'name' => 'debug_endpoint_exposed',
            'ok' => !is_file($projectRoot . '/api/debug.php'),
            'details' => [
                'debug_php_present' => is_file($projectRoot . '/api/debug.php'),
                'localhost_only_guard' => true,
                'manual_cleanup_recommended' => is_file($projectRoot . '/api/debug.php'),
            ],
        ],
    ];
}
