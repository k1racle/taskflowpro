<?php
/**
 * api/project-history.php - История изменений проекта
 * 
 * Эндпоинты:
 * - GET /api/project-history?project_id=X - история проекта
 * - POST /api/project-history - добавить запись в историю
 */

/**
 * Обработка запросов к /api/project-history/*
 */
function handleProjectHistory(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/project-history?project_id=X - история проекта
    if ($method === 'GET' && $action === null) {
        $projectId = $_GET['project_id'] ?? null;
        
        if (!$projectId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите project_id']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT ph.*,
                   u.full_name as user_name,
                   u.avatar as user_avatar
            FROM project_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            WHERE ph.project_id = ?
            ORDER BY ph.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$projectId]);
        $history = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $history]);
        exit;
    }

    // POST /api/project-history - добавить запись
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['project_id']) || empty($data['action'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите project_id и action']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO project_history (project_id, user_id, action, field_name, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['project_id'],
            $currentUser['id'],
            $data['action'],
            $data['field_name'] ?? null,
            $data['old_value'] ?? null,
            $data['new_value'] ?? null
        ]);

        echo json_encode(['success' => true, 'message' => 'История сохранена']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
