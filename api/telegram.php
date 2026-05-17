<?php
/**
 * api/telegram.php - Интеграция с Telegram для уведомлений
 * 
 * Эндпоинты:
 * - GET /api/telegram - получение настроек Telegram
 * - PUT /api/telegram - обновление настроек Telegram
 * - POST /api/telegram/test - тестовое сообщение
 * - POST /api/telegram/notify - отправка уведомления
 */

/**
 * Обработка запросов к /api/telegram/*
 * @param string $method HTTP метод
 * @param string|null $action Действие
 * @param mixed $id ID ресурса
 */
function handleTelegram(string $method, ?string $action, mixed $id): void {
    require_once __DIR__ . '/roles.php';

    $pdo = getPDO();
    $currentUser = getCurrentUser();
    $canManageTelegram = $currentUser ? hasAdminAccess($currentUser) : false;
    
    // Проверка авторизации для всех операций
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    // GET /api/telegram - получение настроек Telegram
    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("SELECT * FROM telegram_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch();
        
        if (!$settings) {
            // Создаём пустые настройки
            $pdo->exec("INSERT INTO telegram_settings (id, bot_token, chat_id, enabled) VALUES (1, '', '', 0)");
            $settings = ['id' => 1, 'bot_token' => '', 'chat_id' => '', 'enabled' => 0];
        }
        
        // Не возвращаем токен, если пользователь не администратор
        if (!$canManageTelegram) {
            unset($settings['bot_token']);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $settings
        ]);
        exit;
    }
    
    // PUT /api/telegram - обновление настроек Telegram (только администраторы)
    if ($method === 'PUT' && $action === null) {
        if (!$canManageTelegram) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки Telegram']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ожидается JSON объект']);
            exit;
        }
        
        $updates = [];
        $params = [];
        $updatedFields = [];
        
        if (isset($data['bot_token'])) {
            $updates[] = "bot_token = ?";
            $params[] = $data['bot_token'];
            $updatedFields[] = 'bot_token';
        }
        
        if (isset($data['chat_id'])) {
            $updates[] = "chat_id = ?";
            $params[] = $data['chat_id'];
            $updatedFields[] = 'chat_id';
        }
        
        if (isset($data['enabled'])) {
            $updates[] = "enabled = ?";
            $params[] = $data['enabled'] ? 1 : 0;
            $updatedFields[] = 'enabled';
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нет данных для обновления']);
            exit;
        }
        
        $params[] = 1; // id = 1
        $sql = "UPDATE telegram_settings SET " . implode(', ', $updates) . " WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        auditLog($pdo, 'settings.telegram.updated', [
            'actor' => $currentUser,
            'target_type' => 'telegram_settings',
            'target_id' => '1',
            'summary' => 'Обновлены настройки Telegram',
            'details' => [
                'updated_fields' => array_values(array_unique($updatedFields)),
                'enabled' => array_key_exists('enabled', $data) ? (bool)$data['enabled'] : !empty($settings['enabled']),
                'bot_token_set' => array_key_exists('bot_token', $data)
                    ? trim((string)$data['bot_token']) !== ''
                    : !empty($settings['bot_token']),
                'chat_id_set' => array_key_exists('chat_id', $data)
                    ? trim((string)$data['chat_id']) !== ''
                    : !empty($settings['chat_id']),
            ],
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Настройки Telegram обновлены']);
        exit;
    }
    
    // POST /api/telegram/test - отправка тестового сообщения
    if ($method === 'POST' && $action === 'test') {
        if (!$canManageTelegram) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут тестировать Telegram']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT bot_token, chat_id, enabled FROM telegram_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch();
        if (!$settings) {
            $pdo->exec("INSERT INTO telegram_settings (id, bot_token, chat_id, enabled) VALUES (1, '', '', 0)");
            $settings = ['id' => 1, 'bot_token' => '', 'chat_id' => '', 'enabled' => 0];
        }
        $auditDetails = [
            'enabled' => !empty($settings['enabled']),
            'bot_token_set' => !empty($settings['bot_token']),
            'chat_id_set' => !empty($settings['chat_id']),
        ];
        
        if (empty($settings['bot_token']) || empty($settings['chat_id'])) {
            auditLog($pdo, 'integration.telegram.tested', [
                'actor' => $currentUser,
                'target_type' => 'telegram_settings',
                'target_id' => '1',
                'summary' => 'Проверка Telegram-интеграции',
                'details' => $auditDetails + [
                    'success' => false,
                    'error' => 'Настройте bot_token и chat_id перед тестированием',
                ],
            ]);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Настройте bot_token и chat_id перед тестированием'
            ]);
            exit;
        }
        
        $result = sendTelegramMessage(
            $settings['bot_token'],
            $settings['chat_id'],
            "🔔 Тестовое уведомление от TaskFlow Pro\n\n" .
            "Если вы видите это сообщение, интеграция с Telegram настроена правильно!\n\n" .
            "📅 " . date('d.m.Y H:i')
        );
        
        if ($result['success']) {
            auditLog($pdo, 'integration.telegram.tested', [
                'actor' => $currentUser,
                'target_type' => 'telegram_settings',
                'target_id' => '1',
                'summary' => 'Проверка Telegram-интеграции',
                'details' => $auditDetails + [
                    'success' => true,
                    'error' => null,
                ],
            ]);
            echo json_encode([
                'success' => true,
                'message' => 'Тестовое сообщение отправлено'
            ]);
        } else {
            auditLog($pdo, 'integration.telegram.tested', [
                'actor' => $currentUser,
                'target_type' => 'telegram_settings',
                'target_id' => '1',
                'summary' => 'Проверка Telegram-интеграции',
                'details' => $auditDetails + [
                    'success' => false,
                    'error' => $result['error'] ?? 'Неизвестная ошибка',
                ],
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка отправки: ' . ($result['error'] ?? 'Неизвестная ошибка')
            ]);
        }
        exit;
    }
    
    // POST /api/telegram/notify - отправка уведомления о задаче/проекте
    if ($method === 'POST' && $action === 'notify') {
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }

        if (!$canManageTelegram) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут отправлять Telegram-уведомления']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT bot_token, chat_id, enabled FROM telegram_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch();
        if (!$settings) {
            $pdo->exec("INSERT INTO telegram_settings (id, bot_token, chat_id, enabled) VALUES (1, '', '', 0)");
            $settings = ['id' => 1, 'bot_token' => '', 'chat_id' => '', 'enabled' => 0];
        }
        
        if (!$settings['enabled']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Уведомления Telegram отключены'
            ]);
            exit;
        }
        
        if (empty($settings['bot_token']) || empty($settings['chat_id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Telegram не настроен'
            ]);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            auditLog($pdo, 'integration.telegram.notified', [
                'actor' => $currentUser,
                'target_type' => 'telegram_settings',
                'target_id' => '1',
                'summary' => 'Отправка Telegram-уведомления',
                'details' => [
                    'success' => false,
                    'error' => 'Ожидается JSON объект',
                ],
            ]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ожидается JSON объект']);
            exit;
        }
        
        if (empty($data['message'])) {
            auditLog($pdo, 'integration.telegram.notified', [
                'actor' => $currentUser,
                'target_type' => 'telegram_settings',
                'target_id' => '1',
                'summary' => 'Отправка Telegram-уведомления',
                'details' => [
                    'success' => false,
                    'error' => 'Укажите сообщение',
                    'type' => $data['type'] ?? 'info',
                ],
            ]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите сообщение']);
            exit;
        }
        
        $message = buildNotificationMessage($data);
        
        $result = sendTelegramMessage(
            $settings['bot_token'],
            $settings['chat_id'],
            $message
        );

        auditLog($pdo, 'integration.telegram.notified', [
            'actor' => $currentUser,
            'target_type' => 'telegram_settings',
            'target_id' => '1',
            'summary' => 'Отправка Telegram-уведомления',
            'details' => [
                'type' => $data['type'] ?? 'info',
                'title_present' => !empty($data['title']),
                'message_length' => strlen((string)$message),
                'url_present' => !empty($data['url']),
                'success' => !empty($result['success']),
                'error' => $result['error'] ?? null,
            ],
        ]);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Уведомление отправлено'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка отправки: ' . ($result['error'] ?? 'Неизвестная ошибка')
            ]);
        }
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Отправка сообщения в Telegram
 * @param string $botToken Токен бота
 * @param string $chatId ID чата
 * @param string $message Текст сообщения
 * @param string $parseMode Режим парсинга (HTML, Markdown, MarkdownV2)
 * @return array ['success' => bool, 'error' => string|null]
 */
function sendTelegramMessage(string $botToken, string $chatId, string $message, string $parseMode = 'HTML'): array {
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => $parseMode
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200 || !isset($result['ok']) || !$result['ok']) {
        return [
            'success' => false,
            'error' => $result['description'] ?? 'Ошибка API Telegram'
        ];
    }
    
    return ['success' => true];
}

/**
 * Построение красивого сообщения уведомления
 * @param array $data Данные уведомления
 * @return string Форматированное сообщение
 */
function buildNotificationMessage(array $data): string {
    $type = $data['type'] ?? 'info';
    $title = $data['title'] ?? 'Уведомление';
    $message = $data['message'] ?? '';
    $url = $data['url'] ?? null;
    
    $emoji = match($type) {
        'task' => '📋',
        'project' => '📁',
        'urgent' => '🔴',
        'success' => '✅',
        'warning' => '⚠️',
        default => '🔔'
    };
    
    $text = "{$emoji} *{$title}*\n\n";
    $text .= $message . "\n";
    
    if ($url) {
        $text .= "\n🔗 <a href=\"{$url}\">Открыть в TaskFlow Pro</a>";
    }
    
    $text .= "\n\n<i>TaskFlow Pro</i> • " . date('d.m.Y H:i');
    
    return $text;
}
