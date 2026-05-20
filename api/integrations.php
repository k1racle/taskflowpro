<?php
/**
 * api/integrations.php - Webhooks/integrations for external messengers.
 *
 * Endpoints (called via api/index.php router):
 * - POST /api/integrations/max/webhook?secret=...
 * - POST /api/integrations/telegram/webhook?secret=...
 */

function handleIntegrations(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();

    $ensureUtf8mb4 = static function () use ($pdo): void {
        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET CHARACTER SET utf8mb4");
        } catch (Throwable $e) {
            // best-effort
        }
    };

    $ensureUtf8mb4();

    $loadSetting = static function (string $key) use ($pdo): ?string {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE BINARY `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    };

    $loadSecretSetting = static function (string $key) use ($loadSetting): string {
        $stored = trim((string)($loadSetting($key) ?? ''));
        if ($stored === '') return '';
        try {
            return trim((string)(appDecrypt($stored) ?? ''));
        } catch (Throwable $e) {
            return $stored;
        }
    };

    $requireWebhookSecret = static function (string $expected) {
        $provided = trim((string)($_GET['secret'] ?? ''));
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
    };

    $readJson = static function (): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    };

    $findDefaultStatusId = static function () use ($pdo): int {
        try {
            $id = (int)($pdo->query("SELECT id FROM helpdesk_statuses ORDER BY `order` ASC, id ASC LIMIT 1")->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        } catch (Throwable $e) {}
        return 1;
    };

    $findDefaultCategoryId = static function () use ($pdo): ?int {
        try {
            $id = $pdo->query("SELECT id FROM helpdesk_categories WHERE is_active = 1 ORDER BY `order` ASC, id ASC LIMIT 1")->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    };

    $findOperatorUserId = static function () use ($pdo): ?int {
        $candidates = [
            "SELECT id FROM users WHERE role = 'root' ORDER BY id ASC LIMIT 1",
            "SELECT DISTINCT u.id FROM users u JOIN roles r ON r.name = u.role JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'admin.full' ORDER BY u.id ASC LIMIT 1",
            "SELECT id FROM users ORDER BY id ASC LIMIT 1"
        ];
        foreach ($candidates as $sql) {
            try {
                $value = $pdo->query($sql)->fetchColumn();
                if ($value) return (int)$value;
            } catch (Throwable $e) {}
        }
        return null;
    };

    $generateTicketNumber = static function () use ($pdo): string {
        $prefix = date('Ymd');
        $stmt = $pdo->prepare("SELECT ticket_number FROM helpdesk_tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '-%']);
        $last = $stmt->fetch();
        if ($last && !empty($last['ticket_number'])) {
            $lastNum = (int)substr(strrchr($last['ticket_number'], '-'), 1);
            $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
            return $prefix . '-' . $newNum;
        }
        return $prefix . '-0001';
    };

    $ensureExternalThreadsTable = static function () use ($pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS helpdesk_external_threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(24) NOT NULL,
                external_chat_id VARCHAR(128) NOT NULL,
                external_user_id VARCHAR(128) NULL,
                ticket_id INT NOT NULL,
                last_external_message_id VARCHAR(128) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_channel_chat (channel, external_chat_id),
                INDEX idx_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    };

    $ensureExternalThreadsTable();

    $findOrCreateThreadTicket = static function (string $channel, string $externalChatId, ?string $externalUserId, string $subject, string $firstMessage) use (
        $pdo,
        $generateTicketNumber,
        $findDefaultStatusId,
        $findDefaultCategoryId,
        $findOperatorUserId
    ): array {
        $stmt = $pdo->prepare("SELECT id, ticket_id FROM helpdesk_external_threads WHERE channel = ? AND external_chat_id = ? LIMIT 1");
        $stmt->execute([$channel, $externalChatId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['ticket_id'])) {
            return ['thread_id' => (int)$row['id'], 'ticket_id' => (int)$row['ticket_id'], 'created' => false];
        }

        $ticketNumber = $generateTicketNumber();
        $statusId = $findDefaultStatusId();
        $categoryId = $findDefaultCategoryId();
        $operatorId = $findOperatorUserId();

        $insert = $pdo->prepare(" 
            INSERT INTO helpdesk_tickets
            (ticket_number, client_name, client_email, client_phone, client_company, category_id,
             status_id, subject, description, priority, source, assigned_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $source = $channel === 'telegram' ? 'omni-telegram' : 'omni-max';
        $insert->execute([
            $ticketNumber,
            $subject,
            null,
            null,
            null,
            $categoryId,
            $statusId,
            $subject,
            $firstMessage,
            'medium',
            $source,
            $operatorId
        ]);

        $ticketId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO helpdesk_external_threads (channel, external_chat_id, external_user_id, ticket_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$channel, $externalChatId, $externalUserId, $ticketId]);
        $threadId = (int)$pdo->lastInsertId();

        return ['thread_id' => $threadId, 'ticket_id' => $ticketId, 'created' => true];
    };

    $appendTicketComment = static function (int $ticketId, string $message, array $meta = []) use ($pdo): int {
        $commentStmt = $pdo->prepare("INSERT INTO helpdesk_comments (ticket_id, user_id, is_internal, message, attachments) VALUES (?, ?, 0, ?, NULL)");
        $commentStmt->execute([$ticketId, null, $message]);
        $commentId = (int)$pdo->lastInsertId();

        if (!empty($meta)) {
            try {
                $historyStmt = $pdo->prepare("INSERT INTO helpdesk_history (ticket_id, user_id, action, field_name, old_value, new_value, meta) VALUES (?, ?, 'comment', NULL, NULL, ?, ?)");
                $historyStmt->execute([$ticketId, null, (string)$commentId, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            } catch (Throwable $e) {
                // best-effort
            }
        }

        return $commentId;
    };

    $updateThreadLastMessage = static function (string $channel, string $externalChatId, ?string $externalMessageId) use ($pdo): void {
        if (!$externalMessageId) return;
        $stmt = $pdo->prepare("UPDATE helpdesk_external_threads SET last_external_message_id = ? WHERE channel = ? AND external_chat_id = ?");
        $stmt->execute([(string)$externalMessageId, $channel, $externalChatId]);
    };

    $requireAdmin = static function (): array {
        // integrations endpoints are public by default (webhooks via secret),
        // so for admin-only utilities we check the current user explicitly.
        $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $hasAdmin = ($currentUser['role'] ?? null) === 'root' || (function_exists('hasAdminAccess') && hasAdminAccess($currentUser));
        if (!$hasAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $currentUser;
    };

    $httpRequestJson = static function (string $url, string $method, array $headers = [], ?array $body = null): array {
        $opts = [
            'http' => [
                'method' => $method,
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => ''
            ]
        ];

        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = $k . ': ' . $v;
        }
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hdrLines[] = 'Content-Type: application/json';
            $opts['http']['content'] = $json === false ? '{}' : $json;
        }
        if (!empty($hdrLines)) {
            $opts['http']['header'] = implode("\r\n", $hdrLines);
        }

        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;

        $respHeaders = null;
        if (function_exists('http_get_last_response_headers')) {
            try {
                $respHeaders = http_get_last_response_headers();
            } catch (Throwable $e) {
                $respHeaders = null;
            }
        }
        if (is_array($respHeaders) && !empty($respHeaders[0])) {
            if (preg_match('/\s(\d{3})\s/', (string)$respHeaders[0], $m)) {
                $status = (int)$m[1];
            }
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'status' => $status,
            'ok' => ($status >= 200 && $status < 300),
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => is_string($raw) ? $raw : ''
        ];
    };

    // Admin-only connectivity checks.
    if ($method === 'GET' && $id === 'ping' && in_array($action, ['telegram', 'max'], true)) {
        $requireAdmin();

        if ($action === 'telegram') {
            $token = $loadSecretSetting('omni_tg_bot_token');
            if ($token === '') {
                jsonResponse(['success' => false, 'error' => 'Токен Telegram не задан'], 400);
            }
            $res = $httpRequestJson('https://api.telegram.org/bot' . rawurlencode($token) . '/getMe', 'GET');
            $details = [];
            if (!$res['ok']) {
                if ($res['status'] > 0) $details[] = 'HTTP ' . $res['status'];
                if (is_array($res['data']) && isset($res['data']['description'])) $details[] = (string)$res['data']['description'];
            }
            jsonResponse([
                'success' => $res['ok'],
                'data' => [
                    'http_status' => $res['status'],
                    'response' => $res['data'],
                    'raw' => $res['raw']
                ],
                'error' => $res['ok'] ? null : ('Не удалось подключиться к Telegram API' . (!empty($details) ? (': ' . implode(' / ', $details)) : ''))
            ], $res['ok'] ? 200 : 400);
        }

        if ($action === 'max') {
            $token = $loadSecretSetting('omni_max_bot_token');
            if ($token === '') {
                jsonResponse(['success' => false, 'error' => 'Токен MAX не задан'], 400);
            }
            $res = $httpRequestJson('https://platform-api.max.ru/me', 'GET', [
                'Authorization' => $token
            ]);
            $details = [];
            if (!$res['ok']) {
                if ($res['status'] > 0) $details[] = 'HTTP ' . $res['status'];
                // MAX error format may vary; keep raw available.
                if (is_array($res['data']) && isset($res['data']['error'])) $details[] = (string)$res['data']['error'];
                if (is_array($res['data']) && isset($res['data']['message'])) $details[] = (string)$res['data']['message'];
            }
            jsonResponse([
                'success' => $res['ok'],
                'data' => [
                    'http_status' => $res['status'],
                    'response' => $res['data'],
                    'raw' => $res['raw']
                ],
                'error' => $res['ok'] ? null : ('Не удалось подключиться к MAX API' . (!empty($details) ? (': ' . implode(' / ', $details)) : ''))
            ], $res['ok'] ? 200 : 400);
        }
    }

    if ($method !== 'POST' || $id !== 'webhook' || !in_array($action, ['max', 'telegram'], true)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
        exit;
    }

    if ($action === 'telegram') {
        $enabled = trim((string)($loadSetting('omni_tg_enabled') ?? '0')) === '1';
        if (!$enabled) {
            echo json_encode(['success' => true]);
            exit;
        }
        $secret = $loadSecretSetting('omni_tg_webhook_secret');
        $requireWebhookSecret($secret);

        $payload = $readJson();

        // Telegram update format: use best-effort extraction.
        $msg = $payload['message'] ?? ($payload['edited_message'] ?? null);
        $chatId = $msg['chat']['id'] ?? null;
        $fromId = $msg['from']['id'] ?? null;
        $text = $msg['text'] ?? ($msg['caption'] ?? '');
        $messageId = $msg['message_id'] ?? null;
        if (!$chatId) {
            echo json_encode(['success' => true]);
            exit;
        }

        $subject = 'Telegram чат ' . (string)$chatId;
        if (!empty($msg['from']['username'])) {
            $subject = 'Telegram: @' . $msg['from']['username'];
        } elseif (!empty($msg['from']['first_name']) || !empty($msg['from']['last_name'])) {
            $subject = 'Telegram: ' . trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? ''));
        }

        $thread = $findOrCreateThreadTicket('telegram', (string)$chatId, $fromId !== null ? (string)$fromId : null, $subject, (string)$text);
        $appendTicketComment((int)$thread['ticket_id'], (string)$text, [
            'channel' => 'telegram',
            'telegram_chat_id' => $chatId,
            'telegram_user_id' => $fromId,
            'telegram_message_id' => $messageId
        ]);
        $updateThreadLastMessage('telegram', (string)$chatId, $messageId !== null ? (string)$messageId : null);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'max') {
        $enabled = trim((string)($loadSetting('omni_max_enabled') ?? '0')) === '1';
        if (!$enabled) {
            echo json_encode(['success' => true]);
            exit;
        }
        $secret = $loadSecretSetting('omni_max_webhook_secret');
        $requireWebhookSecret($secret);

        $payload = $readJson();

        // MAX webhook payload format may vary by subscription type.
        // We do best-effort extraction and store raw payload in meta.
        $chatId = $payload['chat_id'] ?? ($payload['chatId'] ?? null);
        $fromId = $payload['user_id'] ?? ($payload['from']['user_id'] ?? ($payload['from']['id'] ?? null));
        $text = $payload['text'] ?? ($payload['message']['text'] ?? ($payload['message'] ?? ''));
        $messageId = $payload['message_id'] ?? ($payload['messageId'] ?? ($payload['message']['message_id'] ?? null));

        if (!$chatId) {
            // don't fail provider - accept and log nothing
            echo json_encode(['success' => true]);
            exit;
        }

        $subject = 'MAX чат ' . (string)$chatId;
        if (!empty($payload['from']['username'])) {
            $subject = 'MAX: @' . $payload['from']['username'];
        }

        $thread = $findOrCreateThreadTicket('max', (string)$chatId, $fromId !== null ? (string)$fromId : null, $subject, (string)$text);
        $appendTicketComment((int)$thread['ticket_id'], (string)$text, [
            'channel' => 'max',
            'max_chat_id' => $chatId,
            'max_user_id' => $fromId,
            'max_message_id' => $messageId,
            'raw' => $payload
        ]);
        $updateThreadLastMessage('max', (string)$chatId, $messageId !== null ? (string)$messageId : null);

        echo json_encode(['success' => true]);
        exit;
    }
}
