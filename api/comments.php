<?php
/**
 * api/comments.php - Комментарии к задачам
 * 
 * Эндпоинты:
 * - GET /api/comments?task_id=X - список комментариев задачи
 * - POST /api/comments - добавить комментарий
 * - PUT /api/comments/:id - обновить комментарий
 * - DELETE /api/comments/:id - удалить комментарий
 */

/**
 * Обработка запросов к /api/comments/*
 */
function handleComments(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    // GET /api/comments?task_id=X - список комментариев
    if ($method === 'GET' && $action === null) {
        $taskId = $_GET['task_id'] ?? null;
        
        if (!$taskId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите task_id']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   u.full_name as author_name,
                   u.avatar as author_avatar
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$taskId]);
        $comments = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $comments]);
        exit;
    }
    
    // POST /api/comments - добавить комментарий
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['task_id']) || empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите task_id и message']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO comments (task_id, user_id, message, parent_id)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['task_id'],
            $currentUser['id'],
            $data['message'],
            $data['parent_id'] ?? null
        ]);
        
        $commentId = $pdo->lastInsertId();
        
        // Сохраняем в историю задачи
        try {
            $stmt = $pdo->prepare("
                INSERT INTO task_history (task_id, user_id, action, field_name, new_value)
                VALUES (?, ?, 'comment_added', 'comment', ?)
            ");
            $stmt->execute([$data['task_id'], $currentUser['id'], 'Добавлен комментарий']);
        } catch (Exception $e) {
            // Игнорируем ошибки
        }

        // Создаём уведомление
        if (!empty($data['parent_id'])) {
            // Уведомляем автора родительского комментария
            $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
            $stmt->execute([$data['parent_id']]);
            $parent = $stmt->fetch();

            if ($parent && $parent['user_id'] !== $currentUser['id']) {
                createNotification($pdo, [
                    'user_id' => (int)$parent['user_id'],
                    'sender_id' => (int)$currentUser['id'],
                    'message' => 'Новый ответ на ваш комментарий',
                    'type' => 'comment',
                    'related_id' => (int)$commentId,
                ]);
            }
        } else {
            // Уведомляем исполнителя задачи
            $stmt = $pdo->prepare("SELECT assigned_to FROM tasks WHERE id = ?");
            $stmt->execute([$data['task_id']]);
            $task = $stmt->fetch();

            if ($task && $task['assigned_to'] && $task['assigned_to'] !== $currentUser['id']) {
                createNotification($pdo, [
                    'user_id' => (int)$task['assigned_to'],
                    'sender_id' => (int)$currentUser['id'],
                    'message' => 'Новый комментарий к задаче',
                    'type' => 'comment',
                    'related_id' => (int)$commentId,
                ]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $commentId,
                'message' => 'Комментарий добавлен'
            ]
        ]);
        exit;
    }
    
    // PUT /api/comments/:id - обновить комментарий
    if ($method === 'PUT' && $action !== null && is_numeric($action)) {
        $commentId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Проверка прав (только автор)
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Комментарий не найден']);
            exit;
        }
        
        if ($comment['user_id'] !== $currentUser['id'] && !hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }
        
        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите сообщение']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE comments SET message = ? WHERE id = ?");
        $stmt->execute([$data['message'], $commentId]);
        
        echo json_encode(['success' => true, 'message' => 'Комментарий обновлён']);
        exit;
    }
    
    // DELETE /api/comments/:id - удалить комментарий
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $commentId = (int)$action;
        
        // Проверка прав (автор или админ)
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Комментарий не найден']);
            exit;
        }
        
        if ($comment['user_id'] !== $currentUser['id'] && !hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        
        echo json_encode(['success' => true, 'message' => 'Комментарий удалён']);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
