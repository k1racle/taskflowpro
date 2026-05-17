<?php
// api/debug.php - Отладка .htaccess
require_once __DIR__ . '/security.php';

appSecurityEnsureLocalDebugAccess();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo "=== ОТЛАДКА .htaccess ===\n\n";

echo "1. REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "2. QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'NOT SET') . "\n";
echo "3. PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'NOT SET') . "\n";
echo "4. _GET['endpoint']: " . ($_GET['endpoint'] ?? 'NOT SET') . "\n";
echo "5. _POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n";
echo "6. _SERVER['HTTP_AUTHORIZATION']: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET') . "\n";
echo "7. _SERVER['REDIRECT_HTTP_AUTHORIZATION']: " . ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NOT SET') . "\n";
echo "8. mod_rewrite loaded: " . ((function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules(), true)) ? 'YES' : 'NO/UNKNOWN') . "\n";
echo "9. .htaccess exists: " . (file_exists(__DIR__ . '/.htaccess') ? 'YES' : 'NO') . "\n";

// Читаем .htaccess
if (file_exists(__DIR__ . '/.htaccess')) {
    echo "\n10. .htaccess content:\n";
    echo "----\n";
    echo file_get_contents(__DIR__ . '/.htaccess');
    echo "----\n";
}
?>
