<?php
/**
 * api/auth-login.php - Вход в систему (прямой доступ без .htaccess)
 */
require_once __DIR__ . '/security.php';

appSecurityApplyApiHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo = getPDO();
    $result = appAuthProcessLogin($pdo, is_array($data) ? $data : [], 'auth-login.php');

    http_response_code($result['http_status']);
    if ($result['http_status'] !== 200) {
        echo json_encode($result['body']);
        exit;
    }

    $token = $result['token'];
    $user = $result['user'];

    // Устанавливаем cookie с токеном
    setcookie('jwt_token', $token, appSecurityGetCookieOptions(time() + JWT_EXPIRY));

    unset($user['password_hash']);

    echo json_encode([
        'success' => true,
        'data' => [
            'token' => $token,
            'user' => $user
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?>
