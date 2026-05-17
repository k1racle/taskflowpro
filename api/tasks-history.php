<?php
/**
 * api/tasks-history.php - История изменений задачи (прямой доступ)
 * GET: ?task_id=X - история изменений
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$currentUser = getCurrentUser();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$pdo = getPDO();

// GET - история изменений
if ($method === 'GET') {
    $taskId = $_GET['task_id'] ?? null;
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите task_id']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            h.*,
            u.full_name as user_name
        FROM task_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.task_id = ?
        ORDER BY h.created_at ASC
    ");
    $stmt->execute([$taskId]);
    
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
?>
