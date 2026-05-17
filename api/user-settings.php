<?php
/**
 * api/user-settings.php - настройки пользователя (key/value)
 *
 * Эндпоинты:
 * - GET /api/user-settings?key=some_key
 * - PUT /api/user-settings (JSON: {key, value})
 */

function handleUserSettings(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $userId = (int)$currentUser['id'];

    if ($method === 'GET' && $action === null) {
        $key = trim((string)($_GET['key'] ?? ''));
        if ($key === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нужен параметр key']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id=? AND `key`=? LIMIT 1");
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'data' => ['key' => $key, 'value' => $row['value'] ?? null]]);
        exit;
    }

    if ($method === 'PUT' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = [];
        $key = trim((string)($data['key'] ?? ''));
        if ($key === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нужен key']);
            exit;
        }
        $value = (string)($data['value'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, `key`, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $stmt->execute([$userId, $key, $value]);
        echo json_encode(['success' => true, 'data' => ['key' => $key]]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

