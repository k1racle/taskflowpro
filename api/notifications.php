<?php
/**
 * api/notifications.php - Система внутренних уведомлений
 * 
 * Эндпоинты:
 * - GET /api/notifications - список уведомлений
 * - PUT /api/notifications/:id/read - отметить как прочитанное
 * - PUT /api/notifications/read-all - отметить все как прочитанные
 * - POST /api/notifications - создать уведомление
 * - GET /api/notifications/templates - список шаблонов
 * - GET /api/notifications/templates/:id - шаблон по ID
 * - PUT /api/notifications/templates/:id - обновить шаблон
 * - POST /api/notifications/templates/:id/test - тестовая отправка
 * - GET /api/notifications/logs - логи отправки
 */

/**
 * Обработка запросов к /api/notifications/*
 */
function handleNotifications(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    // GET /api/notifications - список уведомлений
    if ($method === 'GET' && $action === null) {
        $unreadOnly = isset($_GET['unread']);
        
        $sql = "
            SELECT n.*,
                   n.is_read AS `read`,
                   u.full_name as sender_name
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
        ";
        
        $params = [$currentUser['id']];
        
        if ($unreadOnly) {
            $sql .= " AND n.is_read = 0";
        }
        
        $sql .= " ORDER BY n.created_at DESC LIMIT 50";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $notifications]);
        exit;
    }
    
    // PUT /api/notifications/:id/read - отметить как прочитанное
    if ($method === 'PUT' && $action !== null && is_numeric($action)) {
        $notifId = (int)$action;
        
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notifId, $currentUser['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Уведомление прочитано']);
        exit;
    }
    
    // PUT /api/notifications/read-all - отметить все как прочитанные
    if ($method === 'PUT' && $action === 'read-all') {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$currentUser['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Все уведомления прочитаны']);
        exit;
    }
    
    // POST /api/notifications - создать уведомление
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id']) && empty($data['department_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите получателя']);
            exit;
        }
        
        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите сообщение']);
            exit;
        }
        
        $userIds = [];
        
        // Конкретному пользователю
        if (!empty($data['user_id'])) {
            $userIds[] = (int)$data['user_id'];
        }
        
        // Всем в отделе
        if (!empty($data['department_id'])) {
            $deptUsers = getDepartmentUserIds($pdo, (int)$data['department_id']);
            $userIds = array_merge($userIds, $deptUsers);
        }
        
        // Всем (админ или руководитель)
        if (!empty($data['all_users']) && (hasPermission($currentUser, 'admin.full') || hasPermission($currentUser, 'leader.view'))) {
            $stmt = $pdo->query("SELECT id FROM users");
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не найден ни один получатель']);
            exit;
        }
        
        $type = $data['type'] ?? 'info';
        $relatedId = $data['related_id'] ?? null;

        createNotifications($pdo, $userIds, [
            'sender_id' => $currentUser['id'],
            'message' => $data['message'],
            'type' => $type,
            'related_id' => $relatedId,
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Уведомление отправлено']);
        exit;
    }

    // Templates management (admin only)
    if ($action === 'templates' || $action === 'template') {
        if (!hasAdminAccess($currentUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только администраторы могут управлять шаблонами']);
            exit;
        }

        // GET /api/notifications/templates
        if ($method === 'GET' && ($action === 'templates' || $id === null)) {
            $eventFilter = isset($_GET['event']) ? trim((string)$_GET['event']) : null;
            $sql = "SELECT id, event, channel, subject, body_html, body_text, is_active, sort_order, created_at, updated_at FROM notification_templates";
            $params = [];
            if ($eventFilter) {
                $sql .= " WHERE event = ?";
                $params[] = $eventFilter;
            }
            $sql .= " ORDER BY event ASC, channel ASC, sort_order ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        // GET /api/notifications/templates/:id
        if ($method === 'GET' && is_numeric($id)) {
            $stmt = $pdo->prepare("SELECT * FROM notification_templates WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$id]);
            $template = $stmt->fetch();
            if (!$template) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Шаблон не найден']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $template]);
            exit;
        }

        // PUT /api/notifications/templates/:id
        if ($method === 'PUT' && is_numeric($id)) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ожидается JSON']);
                exit;
            }

            $allowed = ['subject', 'body_html', 'body_text', 'is_active'];
            $sets = [];
            $params = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $data)) {
                    $sets[] = "{$key} = ?";
                    $params[] = $data[$key];
                }
            }
            if (!$sets) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Нет данных для обновления']);
                exit;
            }
            $params[] = (int)$id;

            $stmt = $pdo->prepare("UPDATE notification_templates SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);

            $stmt = $pdo->prepare("SELECT * FROM notification_templates WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch()]);
            exit;
        }

        // POST /api/notifications/templates/:id/test
        if ($method === 'POST' && is_numeric($id)) {
            $data = json_decode(file_get_contents('php://input'), true);
            $testEmail = trim((string)($data['email'] ?? ''));
            if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите корректный email']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM notification_templates WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$id]);
            $template = $stmt->fetch();
            if (!$template) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Шаблон не найден']);
                exit;
            }

            require_once __DIR__ . '/notification-service.php';
            $vars = [
                'request_number' => 'BK-TEST-0001',
                'client_name' => 'Тестовый Клиент',
                'client_phone' => '+7 (999) 123-45-67',
                'services' => 'Тестовая услуга (60 мин)',
                'datetime' => date('d.m.Y H:i'),
                'total_price' => '5 000 ₽',
                'admin_comment' => 'Тестовый комментарий',
                'company_name' => 'TaskFlow',
                'app_url' => notificationServiceBaseUrl(),
            ];
            $subject = notificationServiceRender($template['subject'] ?? 'Тест', $vars);
            $htmlBody = notificationServiceRender($template['body_html'] ?? '', $vars);
            $textBody = notificationServiceRender($template['body_text'] ?? '', $vars);
            if (stripos($htmlBody, '<html') === false) {
                $htmlBody = notificationServiceWrapEmailHtml($htmlBody, $vars['company_name']);
            }

            $result = notificationServiceSendEmail($pdo, $testEmail, $subject, $htmlBody, $textBody);
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Тестовое письмо отправлено' : ('Ошибка: ' . ($result['error'] ?? 'unknown')),
                'method' => $result['method'] ?? null,
            ]);
            exit;
        }
    }

    // Logs (admin only)
    if ($action === 'logs' && $method === 'GET') {
        if (!hasAdminAccess($currentUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только администраторы могут просматривать логи']);
            exit;
        }

        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $eventFilter = isset($_GET['event']) ? trim((string)$_GET['event']) : null;
        $statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : null;

        $where = [];
        $params = [];
        if ($eventFilter) {
            $where[] = "event = ?";
            $params[] = $eventFilter;
        }
        if ($statusFilter) {
            $where[] = "status = ?";
            $params[] = $statusFilter;
        }
        $sql = "SELECT * FROM notification_logs";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
    exit;
}
