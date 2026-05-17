<?php
/**
 * api/comments-direct.php - Комментарии к задачам (прямой доступ)
 * GET: ?task_id=X - список комментариев
 * POST: добавить комментарий
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
$taskId = $_GET['task_id'] ?? $_POST['task_id'] ?? null;

// GET - список комментариев
if ($method === 'GET') {
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите task_id']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name as user_name, u.avatar as user_avatar
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.task_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$taskId]);
    
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

// POST - добавить комментарий
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $taskId = $data['task_id'] ?? null;
    $message = $data['message'] ?? null;
    $parentId = $data['parent_id'] ?? null;
    
    if (!$taskId || !$message) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите task_id и message']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO comments (task_id, user_id, message, parent_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$taskId, $currentUser['id'], $message, $parentId]);
    
    echo json_encode(['success' => true, 'data' => ['id' => $pdo->lastInsertId()]]);
    exit;
}

// DELETE - удалить комментарий
if ($method === 'DELETE') {
    $commentId = $_GET['id'] ?? null;
    
    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите id']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);
    
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
?>
