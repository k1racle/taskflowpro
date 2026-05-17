<?php
/**
 * api/notifications.php - Система внутренних уведомлений
 * 
 * Эндпоинты:
 * - GET /api/notifications - список уведомлений
 * - PUT /api/notifications/:id/read - отметить как прочитанное
 * - PUT /api/notifications/read-all - отметить все как прочитанные
 * - POST /api/notifications - создать уведомление
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
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
