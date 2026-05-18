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

function appSecurityInstallLockPath(string $projectRoot): string {
    return $projectRoot . '/runtime/install.lock';
}

function appSecurityReadInstallLock(string $projectRoot): ?array {
    $lockPath = appSecurityInstallLockPath($projectRoot);
    if (!is_file($lockPath) || !is_readable($lockPath)) {
        return null;
    }

    $contents = file_get_contents($lockPath);
    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function appSecurityHasStableSecret(string $secret): bool {
    $secret = trim($secret);
    return $secret !== '' && $secret !== 'change-me';
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

    if (preg_match('/^(?:-?\d+(?:\.\d+)?|true|false|null)$/i', $value)) {
        return strtolower($value) === 'null' ? null : $value;
    }

    return null;
}

function appSecurityReadBootstrapConfig(string $projectRoot): array {
    $installLock = appSecurityReadInstallLock($projectRoot);
    if ($installLock !== null) {
        $installedAt = trim((string)($installLock['installed_at'] ?? ''));
        $dbHost = trim((string)($installLock['db_host'] ?? ''));
        $dbName = trim((string)($installLock['db_name'] ?? ''));
        $dbUser = trim((string)($installLock['db_user'] ?? ''));
        $jwtSecret = trim((string)($installLock['jwt_secret'] ?? ''));

        return [
            'configured' => $installedAt !== '' && $dbHost !== '' && $dbName !== '' && $dbUser !== '' && appSecurityHasStableSecret($jwtSecret),
            'source' => 'runtime-lock',
            'install_lock_present' => true,
            'installed_at' => $installedAt !== '' ? $installedAt : null,
            'db_host' => $dbHost !== '' ? $dbHost : null,
            'db_name' => $dbName !== '' ? $dbName : null,
            'db_user' => $dbUser !== '' ? $dbUser : null,
            'db_pass_present' => trim((string)($installLock['db_pass'] ?? '')) !== '',
            'jwt_secret_configured' => appSecurityHasStableSecret($jwtSecret),
            'license_domain' => trim((string)($installLock['license_domain'] ?? '')) !== '' ? trim((string)$installLock['license_domain']) : null,
        ];
    }

    $legacyDbName = trim((string)(appSecurityReadConfigValue('DB_NAME') ?? ''));
    $legacyJwtSecret = trim((string)(appSecurityReadConfigValue('JWT_SECRET') ?? ''));
    $legacyDbHost = trim((string)(appSecurityReadConfigValue('DB_HOST') ?? ''));
    $legacyDbUser = trim((string)(appSecurityReadConfigValue('DB_USER') ?? ''));
    $legacyDbPass = trim((string)(appSecurityReadConfigValue('DB_PASS') ?? ''));
    $legacyLicenseDomain = trim((string)(appSecurityReadConfigValue('LICENSE_DOMAIN') ?? ''));

    if ($legacyDbName !== '' && appSecurityHasStableSecret($legacyJwtSecret)) {
        return [
            'configured' => true,
            'source' => 'legacy-config',
            'install_lock_present' => false,
            'installed_at' => null,
            'db_host' => $legacyDbHost !== '' ? $legacyDbHost : null,
            'db_name' => $legacyDbName,
            'db_user' => $legacyDbUser !== '' ? $legacyDbUser : null,
            'db_pass_present' => $legacyDbPass !== '',
            'jwt_secret_configured' => true,
            'license_domain' => $legacyLicenseDomain !== '' ? $legacyLicenseDomain : null,
        ];
    }

    $envDbHost = trim((string)(getenv('DB_HOST') ?: ''));
    $envDbName = trim((string)(getenv('DB_NAME') ?: ''));
    $envDbUser = trim((string)(getenv('DB_USER') ?: ''));
    $envDbPass = trim((string)(getenv('DB_PASS') ?: ''));
    $envJwtSecret = trim((string)(getenv('JWT_SECRET') ?: ''));
    $envLicenseDomain = trim((string)(getenv('LICENSE_DOMAIN') ?: ''));

    if ($envDbName !== '' && $envDbUser !== '' && $envDbPass !== '' && appSecurityHasStableSecret($envJwtSecret)) {
        return [
            'configured' => true,
            'source' => 'environment',
            'install_lock_present' => false,
            'installed_at' => null,
            'db_host' => $envDbHost !== '' ? $envDbHost : null,
            'db_name' => $envDbName,
            'db_user' => $envDbUser,
            'db_pass_present' => true,
            'jwt_secret_configured' => true,
            'license_domain' => $envLicenseDomain !== '' ? $envLicenseDomain : null,
        ];
    }

    return [
        'configured' => false,
        'source' => 'unconfigured',
        'install_lock_present' => false,
        'installed_at' => null,
        'db_host' => $envDbHost !== '' ? $envDbHost : null,
        'db_name' => $envDbName !== '' ? $envDbName : null,
        'db_user' => $envDbUser !== '' ? $envDbUser : null,
        'db_pass_present' => $envDbPass !== '',
        'jwt_secret_configured' => appSecurityHasStableSecret($envJwtSecret),
        'license_domain' => $envLicenseDomain !== '' ? $envLicenseDomain : null,
    ];
}

function appSecurityIsConfigured(): bool {
    $projectRoot = dirname(__DIR__);
    return (bool)(appSecurityReadBootstrapConfig($projectRoot)['configured'] ?? false);
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
    $bootstrap = appSecurityReadBootstrapConfig($projectRoot);

    return [
        [
            'name' => 'config_present',
            'ok' => is_file($configPath) && is_readable($configPath),
            'details' => [
                'path' => 'api/config.php',
            ],
        ],
        [
            'name' => 'bootstrap_configured',
            'ok' => (bool)($bootstrap['configured'] ?? false),
            'details' => [
                'source' => $bootstrap['source'] ?? null,
                'install_lock_present' => $bootstrap['install_lock_present'] ?? null,
                'installed_at' => $bootstrap['installed_at'] ?? null,
                'db_host' => $bootstrap['db_host'] ?? null,
                'db_name' => $bootstrap['db_name'] ?? null,
                'db_user' => $bootstrap['db_user'] ?? null,
                'db_pass_present' => $bootstrap['db_pass_present'] ?? null,
                'jwt_secret_configured' => $bootstrap['jwt_secret_configured'] ?? null,
                'license_domain' => $bootstrap['license_domain'] ?? null,
            ],
        ],
        [
            'name' => 'jwt_secret_configured',
            'ok' => (bool)($bootstrap['jwt_secret_configured'] ?? false),
            'details' => [
                'configured' => $bootstrap['jwt_secret_configured'] ?? false,
                'source' => $bootstrap['source'] ?? null,
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
            'ok' => true,
            'details' => [
                'install_php_present' => is_file($projectRoot . '/install.php'),
                'auto_blocked_after_config' => !appSecurityIsConfigured() || !is_file($projectRoot . '/install.php'),
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
