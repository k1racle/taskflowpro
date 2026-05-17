<?php
/**
 * api/tasks.php - Прямой доступ к задачам (обход mod_security)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

$method = $_SERVER['REQUEST_METHOD'];

// Некоторые хостинги блокируют PUT - принимаем POST как PUT
if ($method === 'POST' && isset($_GET['_method']) && $_GET['_method'] === 'PUT') {
    $method = 'PUT';
}
// Или передаём X-HTTP-Method-Override заголовок
if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] === 'PUT') {
    $method = 'PUT';
}

$currentUser = getCurrentUser();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$pdo = getPDO();

// GET /api/tasks.php - список задач
if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT t.*,
               p.name as project_name,
               u.full_name as assignee_name,
               ts.color as status_color,
               c.name as client_name,
               d.title as deal_title
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN task_stages ts ON t.status = ts.name
        LEFT JOIN crm_clients c ON t.client_id = c.id
        LEFT JOIN crm_deals d ON t.deal_id = d.id
        ORDER BY t.created_at DESC
    ");
    $tasks = $stmt->fetchAll();

    // Добавляем ответственных к каждой задаче
    foreach ($tasks as &$task) {
        $stmtResp = $pdo->prepare("
            SELECT u.id, u.full_name, u.login
            FROM task_responsibles tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.task_id = ?
        ");
        $stmtResp->execute([$task['id']]);
        $task['responsibles'] = $stmtResp->fetchAll();
    }

    echo json_encode(['success' => true, 'data' => $tasks]);
    exit;
}

// PUT /api/tasks.php?id=X - обновить задачу
if ($method === 'PUT') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите id']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    $params = [];

    if (isset($data['title'])) { $updates[] = "title = ?"; $params[] = $data['title']; }
    if (isset($data['description'])) { $updates[] = "description = ?"; $params[] = $data['description']; }
    if (isset($data['status'])) { $updates[] = "status = ?"; $params[] = $data['status']; }
    if (isset($data['priority'])) { $updates[] = "priority = ?"; $params[] = $data['priority']; }
    if (isset($data['current_substage_id'])) { $updates[] = "current_substage_id = ?"; $params[] = $data['current_substage_id']; }
    if (isset($data['department_id'])) { $updates[] = "department_id = ?"; $params[] = $data['department_id'] ?: null; }
    if (isset($data['assigned_to'])) { $updates[] = "assigned_to = ?"; $params[] = $data['assigned_to'] ?: null; }
    if (isset($data['deadline'])) { $updates[] = "deadline = ?"; $params[] = $data['deadline'] ?: null; }
    if (isset($data['client_id'])) { $updates[] = "client_id = ?"; $params[] = $data['client_id'] ?: null; }
    if (isset($data['deal_id'])) { $updates[] = "deal_id = ?"; $params[] = $data['deal_id'] ?: null; }

    if (!empty($updates)) {
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        
        // Сохраняем ответственных (множественный выбор)
        if (isset($data['responsible_ids']) && is_array($data['responsible_ids'])) {
            // Удаляем старых ответственных
            $stmt = $pdo->prepare("DELETE FROM task_responsibles WHERE task_id = ?");
            $stmt->execute([$id]);
            
            // Добавляем новых
            if (!empty($data['responsible_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO task_responsibles (task_id, user_id) VALUES (?, ?)");
                foreach ($data['responsible_ids'] as $userId) {
                    $stmt->execute([$id, $userId]);
                }
            }
        }
        
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Нет данных']);
    }
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
