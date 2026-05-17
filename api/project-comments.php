<?php
/**
 * api/project-comments.php - Комментарии к проектам
 * 
 * Эндпоинты:
 * - GET /api/project-comments?project_id=X - список комментариев проекта
 * - POST /api/project-comments - добавить комментарий
 * - DELETE /api/project-comments/:id - удалить комментарий
 */

/**
 * Обработка запросов к /api/project-comments/*
 */
function handleProjectComments(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/project-comments?project_id=X - список комментариев
    if ($method === 'GET' && $action === null) {
        $projectId = $_GET['project_id'] ?? null;
        
        if (!$projectId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите project_id']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pc.*,
                   u.full_name as author_name,
                   u.avatar as author_avatar
            FROM project_comments pc
            JOIN users u ON pc.user_id = u.id
            WHERE pc.project_id = ?
            ORDER BY pc.created_at ASC
        ");
        $stmt->execute([$projectId]);
        $comments = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $comments]);
        exit;
    }

    // POST /api/project-comments - добавить комментарий
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['project_id']) || empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите project_id и message']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO project_comments (project_id, user_id, message, parent_id)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['project_id'],
            $currentUser['id'],
            $data['message'],
            $data['parent_id'] ?? null
        ]);

        $commentId = $pdo->lastInsertId();
        
        // Сохраняем в историю проекта
        try {
            $stmt = $pdo->prepare("
                INSERT INTO project_history (project_id, user_id, action, field_name, new_value)
                VALUES (?, ?, 'comment_added', 'comment', ?)
            ");
            $stmt->execute([$data['project_id'], $currentUser['id'], 'Добавлен комментарий']);
        } catch (Exception $e) {
            error_log('Project comment history error: ' . $e->getMessage());
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

    // DELETE /api/project-comments/:id - удалить комментарий
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $commentId = (int)$action;

        // Проверка прав (только автор или админ)
        $stmt = $pdo->prepare("SELECT user_id, project_id FROM project_comments WHERE id = ?");
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

        $stmt = $pdo->prepare("DELETE FROM project_comments WHERE id = ?");
        $stmt->execute([$commentId]);

        echo json_encode(['success' => true, 'message' => 'Комментарий удалён']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
