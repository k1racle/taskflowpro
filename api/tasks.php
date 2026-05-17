<?php
/**
 * api/tasks.php - Управление задачами
 *
 * Эндпоинты:
 * - GET /api/tasks - список задач
 * - GET /api/tasks/:id - задача по ID
 * - POST /api/tasks - создание задачи
 * - PUT /api/tasks/:id - обновление задачи
 * - DELETE /api/tasks/:id - удаление задачи
 * - PATCH /api/tasks/:id/status - смена статуса
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/crm.php';

/**
 * Обработка запросов к /api/tasks/*
 */
function handleTasks(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/tasks - список задач
    if ($method === 'GET' && $action === null) {
        if (!hasPermission($currentUser, 'tasks.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $filters = [];
        $params = [];

        // Фильтр по проекту
        if (isset($_GET['project_id']) && is_numeric($_GET['project_id'])) {
            $filters[] = "t.project_id = ?";
            $params[] = (int)$_GET['project_id'];
        }

        // Фильтр по статусу
        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $filters[] = "t.status = ?";
            $params[] = $_GET['status'];
        }

        // Фильтр по отделу (для пользователей без доступа ко всем отделам)
        if (isset($_GET['department_id']) && is_numeric($_GET['department_id'])) {
            $filters[] = "p.department_id = ?";
            $params[] = (int)$_GET['department_id'];
        }

        // Задачи текущего пользователя
        if (isset($_GET['my_tasks'])) {
            $filters[] = "t.assigned_to = ?";
            $params[] = $currentUser['id'];
        }

        $isFullAdmin = ($currentUser['role'] === 'root') || hasPermission($currentUser, 'admin.full');

        // Ограничение видимости для пользователей без полного админ-доступа
        if (!$isFullAdmin) {
            $deptIds = getUserDepartmentIds($currentUser['id']);
            if (!empty($deptIds)) {
                $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
                $filters[] = "p.department_id IN ($placeholders)";
                $params = array_merge($params, $deptIds);
            } else {
                // Нет доступа ни к одному отделу - показываем только назначенные задачи
                $filters[] = "t.assigned_to = ?";
                $params[] = $currentUser['id'];
            }
        }

        $whereClause = empty($filters) ? '' : 'WHERE ' . implode(' AND ', $filters);

        $sql = "
            SELECT t.*,
                   p.name as project_name,
                   u.full_name as assignee_name,
                   u.avatar as assignee_avatar,
                   ts.color as status_color,
                   c.name as client_name,
                   d.title as deal_title,
                   d.client_id as deal_client_id,
                   dc.name as deal_client_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN task_stages ts ON t.status = ts.name
            LEFT JOIN crm_clients c ON t.client_id = c.id
            LEFT JOIN crm_deals d ON t.deal_id = d.id
            LEFT JOIN crm_clients dc ON d.client_id = dc.id
            $whereClause
            ORDER BY
                CASE t.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END,
                t.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();

        // Декодируем чек-листы и добавляем файлы
        foreach ($tasks as &$task) {
            if ($task['checklist']) {
                $task['checklist'] = json_decode($task['checklist'], true);
            }
            // Получаем файлы задачи
            $stmt = $pdo->prepare("SELECT f.* FROM files f WHERE f.task_id = ?");
            $stmt->execute([$task['id']]);
            $task['files'] = $stmt->fetchAll();

            // Получаем отделы задачи
            $stmt = $pdo->prepare("
                SELECT d.id, d.name 
                FROM task_departments td 
                JOIN departments d ON td.department_id = d.id 
                WHERE td.task_id = ?
            ");
            $stmt->execute([$task['id']]);
            $task['departments'] = $stmt->fetchAll();

            // Получаем ответственных
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.avatar 
                FROM task_responsibles tr 
                JOIN users u ON tr.user_id = u.id 
                WHERE tr.task_id = ?
            ");
            $stmt->execute([$task['id']]);
            $task['responsibles'] = $stmt->fetchAll();
        }

        echo json_encode(['success' => true, 'data' => $tasks]);
        exit;
    }

    // GET /api/tasks/:id/history - история изменений задачи (ПРОВЕРКА РАНЬШЕ получения задачи!)
    if ($method === 'GET' && $action !== null && is_numeric($action) && ($id === 'history' || $action === 'history')) {
        error_log('HISTORY endpoint detected: action=' . $action . ', id=' . $id);
        $taskId = (int)$action;

        $stmt = $pdo->prepare("
            SELECT h.*,
                   u.full_name as user_name,
                   u.avatar as user_avatar
            FROM task_history h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.task_id = ?
            ORDER BY h.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$taskId]);
        $history = $stmt->fetchAll();

        error_log('HISTORY result: ' . count($history) . ' records');
        echo json_encode(['success' => true, 'data' => $history]);
        exit;
    }

    // GET /api/tasks/:id - получение задачи
    if ($method === 'GET' && ((is_numeric($action) && $action !== null) || (is_numeric($id) && $action === null))) {
        $taskId = is_numeric($action) ? (int)$action : (int)$id;

        $stmt = $pdo->prepare("
            SELECT t.*,
                   p.name as project_name,
                   p.department_id,
                   u.full_name as assignee_name,
                   u.avatar as assignee_avatar,
                   ts.color as status_color,
                   c.name as client_name,
                   d.title as deal_title,
                   d.client_id as deal_client_id,
                   dc.name as deal_client_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN task_stages ts ON t.status = ts.name
            LEFT JOIN crm_clients c ON t.client_id = c.id
            LEFT JOIN crm_deals d ON t.deal_id = d.id
            LEFT JOIN crm_clients dc ON d.client_id = dc.id
            WHERE t.id = ?
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
            exit;
        }

        // Проверка прав на просмотр
        if (!hasPermission($currentUser, 'tasks.view') || !canViewTask($currentUser, $task)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к задаче']);
            exit;
        }

        // Декодируем чек-лист
        if ($task['checklist']) {
            $task['checklist'] = json_decode($task['checklist'], true);
        }

        // Получаем отделы задачи
        $stmt = $pdo->prepare("
            SELECT d.id, d.name 
            FROM task_departments td 
            JOIN departments d ON td.department_id = d.id 
            WHERE td.task_id = ?
        ");
        $stmt->execute([$taskId]);
        $task['departments'] = $stmt->fetchAll();

        // Получаем ответственных
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.avatar 
            FROM task_responsibles tr 
            JOIN users u ON tr.user_id = u.id 
            WHERE tr.task_id = ?
        ");
        $stmt->execute([$taskId]);
        $task['responsibles'] = $stmt->fetchAll();

        // Получаем файлы
        $stmt = $pdo->prepare("SELECT f.* FROM files f WHERE f.task_id = ?");
        $stmt->execute([$taskId]);
        $task['files'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $task]);
        exit;
    }

    // POST /api/tasks - создание задачи
    if ($method === 'POST' && $action === null) {
        if (!hasPermission($currentUser, 'tasks.create')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для создания задачи']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['title'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название задачи']);
            exit;
        }

        // Получаем проект для проверки прав
        $deptId = null;
        if (!empty($data['project_id'])) {
            $stmt = $pdo->prepare("SELECT department_id FROM projects WHERE id = ?");
            $stmt->execute([$data['project_id']]);
            $project = $stmt->fetch();
            $deptId = $project ? $project['department_id'] : null;
        }

        // Проверка прав на создание (legacy department-scope logic остаётся как дополнительный фильтр)
        if (!canCreateTask($currentUser, $deptId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для создания задачи']);
            exit;
        }

        $status = $data['status'] ?? 'Новая';
        $stmt = $pdo->prepare("SELECT name FROM task_stages WHERE name = ?");
        $stmt->execute([$status]);
        if (!$stmt->fetch()) {
            $status = 'Новая';
        }

        $stmt = $pdo->prepare("
            INSERT INTO tasks (project_id, client_id, deal_id, created_by, title, description, status, priority, deadline, assigned_to, checklist)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $checklistJson = isset($data['checklist']) ? json_encode($data['checklist'], JSON_UNESCAPED_UNICODE) : null;
        $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int)$data['project_id'] : null;
        $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int)$data['client_id'] : null;
        $dealId = isset($data['deal_id']) && $data['deal_id'] !== '' ? (int)$data['deal_id'] : null;
        $assignedTo = isset($data['assigned_to']) && $data['assigned_to'] !== '' ? (int)$data['assigned_to'] : null;
        $deadline = isset($data['deadline']) && $data['deadline'] !== '' ? $data['deadline'] : null;

        $stmt->execute([
            $projectId,
            $clientId,
            $dealId,
            $currentUser['id'], // created_by
            $data['title'],
            $data['description'] ?? '',
            $status,
            $data['priority'] ?? 'medium',
            $deadline,
            $assignedTo,
            $checklistJson
        ]);

        $newTaskId = $pdo->lastInsertId();

        // Отправляем уведомления назначенному пользователю
        if ($assignedTo) {
            sendTaskNotification($pdo, $assignedTo, $currentUser, $newTaskId, $data['title'], 'new_task');
        }

        if (!empty($data['responsible_ids']) && is_array($data['responsible_ids'])) {
            foreach ($data['responsible_ids'] as $responsibleUserId) {
                if (is_numeric($responsibleUserId) && (int)$responsibleUserId !== (int)$assignedTo) {
                    sendTaskNotification($pdo, (int)$responsibleUserId, $currentUser, (int)$newTaskId, $data['title'], 'new_task');
                }
            }
        }

        // Создаём подпапку задачи внутри папки проекта
        try {
            if (!empty($data['project_id'])) {
                $projectId = (int)$data['project_id'];
                $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
                $stmt->execute([$projectId]);
                $p = $stmt->fetch();
                $projectFolderId = ensureProjectDiskFolder($pdo, $projectId, (string)($p['name'] ?? ('Проект #' . $projectId)), (int)$currentUser['id']);
                ensureTaskDiskFolder($pdo, $projectFolderId, (int)$newTaskId, (string)$data['title'], (int)$currentUser['id']);
            }
        } catch (Exception $e) {
            // ignore
        }

        // Сохраняем отделы задачи
        if (!empty($data['department_ids']) && is_array($data['department_ids'])) {
            $stmt = $pdo->prepare("INSERT INTO task_departments (task_id, department_id) VALUES (?, ?)");
            foreach ($data['department_ids'] as $deptId) {
                if (is_numeric($deptId)) {
                    $stmt->execute([$newTaskId, (int)$deptId]);
                }
            }
        }

        // Сохраняем ответственных
        if (!empty($data['responsible_ids']) && is_array($data['responsible_ids'])) {
            $stmt = $pdo->prepare("INSERT INTO task_responsibles (task_id, user_id) VALUES (?, ?)");
            foreach ($data['responsible_ids'] as $userId) {
                if (is_numeric($userId)) {
                    $stmt->execute([$newTaskId, (int)$userId]);
                }
            }
        }

        // Сохраняем в историю
        saveTaskHistory($pdo, $newTaskId, $currentUser['id'], 'created', 'task', null, 'Задача создана');

        // CRM activity
        if (!empty($clientId)) {
            crmLog($pdo, 'client', (int)$clientId, 'task_create', (int)$currentUser['id'], 'Создана задача по клиенту', ['task_id' => (int)$newTaskId]);
        }
        if (!empty($dealId)) {
            crmLog($pdo, 'deal', (int)$dealId, 'task_create', (int)$currentUser['id'], 'Создана задача по сделке', ['task_id' => (int)$newTaskId]);
        }
        crmLog($pdo, 'task', (int)$newTaskId, 'create', (int)$currentUser['id'], 'Создана задача', ['client_id' => $clientId, 'deal_id' => $dealId]);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newTaskId,
                'title' => $data['title'],
                'status' => $status,
                'message' => 'Задача успешно создана'
            ]
        ]);
        exit;
    }

    // PUT /api/tasks/:id - обновление задачи
    // Router normally puts id into $id (action=null), but keep backward compatibility.
    if ($method === 'PUT' && ((is_numeric($id) && $action === null) || (is_numeric($action) && $id === null))) {
        $taskId = is_numeric($id) ? (int)$id : (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        // Получаем задачу для проверки прав
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'tasks.edit') || !canEditTask($currentUser, $task)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для редактирования задачи']);
            exit;
        }

        $updates = [];
        $params = [];

        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = $data['title'];
        }

        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = $data['description'];
        }

        if (isset($data['project_id'])) {
            $updates[] = "project_id = ?";
            $params[] = $data['project_id'];
        }

        if (isset($data['client_id'])) {
            $updates[] = "client_id = ?";
            $params[] = ($data['client_id'] === '' || $data['client_id'] === null) ? null : (int)$data['client_id'];
        }

        if (isset($data['deal_id'])) {
            $updates[] = "deal_id = ?";
            $params[] = ($data['deal_id'] === '' || $data['deal_id'] === null) ? null : (int)$data['deal_id'];
        }

        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }

        if (isset($data['priority'])) {
            $updates[] = "priority = ?";
            $params[] = $data['priority'];
        }

        if (isset($data['deadline'])) {
            $updates[] = "deadline = ?";
            $params[] = $data['deadline'];
        }

        if (isset($data['assigned_to'])) {
            $updates[] = "assigned_to = ?";
            $params[] = $data['assigned_to'];
        }

        if (isset($data['checklist'])) {
            $updates[] = "checklist = ?";
            $params[] = json_encode($data['checklist'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['timer_seconds'])) {
            $updates[] = "timer_seconds = ?";
            $params[] = (int)$data['timer_seconds'];
        }

        // Support substage selection from UI
        if (array_key_exists('current_substage_id', $data)) {
            $updates[] = "current_substage_id = ?";
            $params[] = ($data['current_substage_id'] === '' || $data['current_substage_id'] === null)
                ? null
                : (int)$data['current_substage_id'];
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нет данных для обновления']);
            exit;
        }

        $params[] = $taskId;
        $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // CRM activity: link changes
        if (array_key_exists('client_id', $data)) {
            $newClientId = ($data['client_id'] === '' || $data['client_id'] === null) ? null : (int)$data['client_id'];
            if ((int)($task['client_id'] ?? 0) !== (int)($newClientId ?? 0)) {
                if ($newClientId) {
                    crmLog($pdo, 'client', $newClientId, 'task_link', (int)$currentUser['id'], 'Привязана задача', ['task_id' => $taskId]);
                }
            }
        }

        if (array_key_exists('deal_id', $data)) {
            $newDealId = ($data['deal_id'] === '' || $data['deal_id'] === null) ? null : (int)$data['deal_id'];
            if ((int)($task['deal_id'] ?? 0) !== (int)($newDealId ?? 0)) {
                if ($newDealId) {
                    crmLog($pdo, 'deal', $newDealId, 'task_link', (int)$currentUser['id'], 'Привязана задача', ['task_id' => $taskId]);
                }
            }
        }

        // Обновляем отделы задачи
        if (isset($data['department_ids'])) {
            // Удаляем старые
            $pdo->prepare("DELETE FROM task_departments WHERE task_id = ?")->execute([$taskId]);
            // Добавляем новые
            if (is_array($data['department_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO task_departments (task_id, department_id) VALUES (?, ?)");
                foreach ($data['department_ids'] as $deptId) {
                    if (is_numeric($deptId)) {
                        $stmt->execute([$taskId, (int)$deptId]);
                    }
                }
            }
        }

        // Обновляем ответственных
        if (isset($data['responsible_ids'])) {
            // Удаляем старые
            $pdo->prepare("DELETE FROM task_responsibles WHERE task_id = ?")->execute([$taskId]);
            // Добавляем новые
            if (is_array($data['responsible_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO task_responsibles (task_id, user_id) VALUES (?, ?)");
                foreach ($data['responsible_ids'] as $userId) {
                    if (is_numeric($userId)) {
                        $stmt->execute([$taskId, (int)$userId]);
                    }
                }
            }
        }

        $newAssignee = array_key_exists('assigned_to', $data)
            ? (($data['assigned_to'] === '' || $data['assigned_to'] === null) ? null : (int)$data['assigned_to'])
            : (isset($task['assigned_to']) ? (int)$task['assigned_to'] : null);

        // Сохраняем в историю изменения статуса
        if (isset($data['status'])) {
            saveTaskHistory($pdo, $taskId, $currentUser['id'], 'status_changed', 'status', null, $data['status']);
            if (!empty($newAssignee) && (int)$newAssignee !== (int)$currentUser['id']) {
                sendTaskNotification($pdo, (int)$newAssignee, $currentUser, $taskId, (string)($data['title'] ?? $task['title']), 'status_changed');
            }
        }
        if (isset($data['priority'])) {
            $priorityTranslate = ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий', 'urgent' => 'Срочный'];
            $priorityRu = $priorityTranslate[$data['priority']] ?? $data['priority'];
            saveTaskHistory($pdo, $taskId, $currentUser['id'], 'priority_changed', 'priority', null, $priorityRu);
        }
        if (array_key_exists('assigned_to', $data) && !empty($newAssignee) && (int)$newAssignee !== (int)$currentUser['id']) {
            sendTaskNotification($pdo, (int)$newAssignee, $currentUser, $taskId, (string)($data['title'] ?? $task['title']), 'new_task');
        }

        echo json_encode(['success' => true, 'message' => 'Задача обновлена']);
        exit;
    }

    // DELETE /api/tasks/:id - удаление задачи
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $taskId = (int)$action;

        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'tasks.delete') || !canDeleteTask($currentUser, $task)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления задачи']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);

        auditLog($pdo, 'entity.task.deleted', [
            'actor' => $currentUser,
            'target_type' => 'task',
            'target_id' => (string)$taskId,
            'summary' => 'Удалена задача',
            'details' => [
                'title' => $task['title'] ?? null,
                'status' => $task['status'] ?? null,
                'project_id' => $task['project_id'] ?? null,
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Задача удалена']);
        exit;
    }

    // PATCH /api/tasks/:id/status - смена статуса
    if ($method === 'PATCH' && $action !== null && is_numeric($action) && $id === 'status') {
        $taskId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['status'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите статус']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'tasks.edit') || !canEditTask($currentUser, $task)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $taskId]);

        // Сохраняем в историю
        saveTaskHistory($pdo, $taskId, $currentUser['id'], 'status_changed', 'status', null, $data['status']);

        if (!empty($task['assigned_to']) && (int)$task['assigned_to'] !== (int)$currentUser['id']) {
            sendTaskNotification($pdo, (int)$task['assigned_to'], $currentUser, $taskId, (string)$task['title'], 'status_changed');
        }

        echo json_encode(['success' => true, 'data' => ['status' => $data['status']]]);
        exit;
    }

    // PATCH /api/tasks/:id/checklist - обновление чек-листа
    if ($method === 'PATCH' && $action !== null && is_numeric($action) && $id === 'checklist') {
        $taskId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['checklist'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите чек-лист']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE tasks SET checklist = ? WHERE id = ?");
        $stmt->execute([json_encode($data['checklist'], JSON_UNESCAPED_UNICODE), $taskId]);

        echo json_encode(['success' => true, 'data' => ['checklist' => $data['checklist']]]);
        exit;
    }

    // PATCH /api/tasks/:id/timer - обновление таймера
    if ($method === 'PATCH' && $action !== null && is_numeric($action) && $id === 'timer') {
        $taskId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['seconds'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите секунды']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE tasks SET timer_seconds = timer_seconds + ? WHERE id = ?");
        $stmt->execute([(int)$data['seconds'], $taskId]);

        // Получаем новое значение
        $stmt = $pdo->prepare("SELECT timer_seconds FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        echo json_encode(['success' => true, 'data' => ['timer_seconds' => $task['timer_seconds']]]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Обработка запросов к /api/stages/*
 */
function handleStages(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    require_once __DIR__ . '/roles.php';

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/stages - список этапов
    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("SELECT * FROM task_stages ORDER BY `order` ASC");
        $stmt->execute();
        $stages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $stages]);
        exit;
    }

    // POST /api/stages - создание этапа
    if ($method === 'POST' && $action === null) {
        if (!hasPermission($currentUser, 'admin.full')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только администраторы могут создавать этапы']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название этапа']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO task_stages (name, color, `order`)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['color'] ?? '#6B7280',
            $data['order'] ?? 0
        ]);

        $stageId = (int)$pdo->lastInsertId();

        auditLog($pdo, 'task.stage.created', [
            'actor' => $currentUser,
            'target_type' => 'task_stage',
            'target_id' => (string)$stageId,
            'summary' => 'Создан этап',
            'details' => [
                'name' => $data['name'],
                'color' => $data['color'] ?? '#6B7280',
                'order' => (int)($data['order'] ?? 0),
            ],
        ]);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $stageId,
                'name' => $data['name'],
                'message' => 'Этап успешно создан'
            ]
        ]);
        exit;
    }

    // PUT /api/stages/:id - обновление этапа
    if ($method === 'PUT' && is_numeric($action)) {
        if (!hasPermission($currentUser, 'admin.full')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только администраторы могут редактировать этапы']);
            exit;
        }

        $stageId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim((string)($data['name'] ?? ''));

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название этапа']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE task_stages SET name = ?, color = ?, `order` = ? WHERE id = ?");
        $stmt->execute([
            $name,
            $data['color'] ?? '#6B7280',
            (int)($data['order'] ?? 0),
            $stageId,
        ]);

        auditLog($pdo, 'task.stage.updated', [
            'actor' => $currentUser,
            'target_type' => 'task_stage',
            'target_id' => (string)$stageId,
            'summary' => 'Обновлён этап',
            'details' => [
                'name' => $name,
                'color' => $data['color'] ?? '#6B7280',
                'order' => (int)($data['order'] ?? 0),
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Этап обновлён']);
        exit;
    }

    // DELETE /api/stages/:id - удаление этапа
    if ($method === 'DELETE' && is_numeric($action)) {
        if (!hasPermission($currentUser, 'admin.full')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только администраторы могут удалять этапы']);
            exit;
        }

        $stageId = (int)$action;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status = (SELECT name FROM task_stages WHERE id = ?)");
        $stmt->execute([$stageId]);
        $tasksCount = (int)$stmt->fetchColumn();

        if ($tasksCount > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить этап: есть задачи в этом этапе']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM task_stages WHERE id = ?");
        $stmt->execute([$stageId]);

        auditLog($pdo, 'task.stage.deleted', [
            'actor' => $currentUser,
            'target_type' => 'task_stage',
            'target_id' => (string)$stageId,
            'summary' => 'Удалён этап',
            'details' => [
                'stage_id' => $stageId,
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Этап удалён']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Сохранить запись в историю изменений задачи
 */
function saveTaskHistory($pdo, $taskId, $userId, $action, $fieldName = null, $oldValue = null, $newValue = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO task_history (task_id, user_id, action, field_name, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$taskId, $userId, $action, $fieldName, $oldValue, $newValue]);
    } catch (Exception $e) {
        // Игнорируем ошибки истории
    }
}

/**
 * Отправить уведомление о задаче (Email + Telegram)
 */
function sendTaskNotification($pdo, $recipientId, $sender, $taskId, $taskTitle, $type = 'new_task'): void {
    try {
        $messageMap = [
            'new_task' => 'Вам назначена задача: ' . $taskTitle,
            'status_changed' => 'Изменён статус задачи: ' . $taskTitle,
            'comment' => 'Новый комментарий по задаче: ' . $taskTitle,
            'mention' => 'Вас упомянули в задаче: ' . $taskTitle,
        ];

        createNotification($pdo, [
            'user_id' => (int)$recipientId,
            'sender_id' => isset($sender['id']) ? (int)$sender['id'] : null,
            'message' => $messageMap[$type] ?? ('Обновление по задаче: ' . $taskTitle),
            'type' => 'task',
            'related_id' => (int)$taskId,
        ]);

        // Получаем настройки получателя
        $stmt = $pdo->prepare("SELECT id, full_name, email, notification_settings FROM users WHERE id = ?");
        $stmt->execute([$recipientId]);
        $recipient = $stmt->fetch();
        
        if (!$recipient) return;
        
        $settings = !empty($recipient['notification_settings']) 
            ? json_decode($recipient['notification_settings'], true) 
            : ['email' => ['new_tasks' => true], 'telegram' => ['new_tasks' => true]];
        
        // Определяем тип уведомления
        $subjectMap = [
            'new_task' => '📋 Новая задача',
            'status_changed' => '🔄 Статус изменён',
            'comment' => '💬 Комментарий',
            'mention' => '🔔 Упоминание'
        ];
        
        $subject = $subjectMap[$type] ?? 'Уведомление';
        $taskUrl = getBaseUrl() . '/?view=tasks&id=' . $taskId;
        
        // Email уведомление
        if (!empty($settings['email'][$type]) && !empty($recipient['email'])) {
            $mailStmt = $pdo->prepare("SELECT * FROM mail_settings WHERE user_id = ? OR user_id IS NULL ORDER BY user_id DESC LIMIT 1");
            $mailStmt->execute([null]);
            $mailSettings = $mailStmt->fetch();
            
            if ($mailSettings && !empty($mailSettings['smtp_host'])) {
                $message = "
                    <html>
                    <head><style>body{font-family:Arial,sans-serif;} .container{max-width:600px;margin:0 auto;} .header{background:linear-gradient(135deg,#3B82F6,#8B5CF6);color:white;padding:20px;border-radius:10px 10px 0 0;} .content{padding:20px;background:#f9fafb;} .button{display:inline-block;padding:12px 24px;background:#3B82F6;color:white;text-decoration:none;border-radius:8px;margin-top:15px;} .footer{padding:15px;text-align:center;color:#6b7280;font-size:12px;}</style></head>
                    <body>
                        <div class='container'>
                            <div class='header'><h2 style='margin:0'>{$subject}</h2></div>
                            <div class='content'>
                                <p>Здравствуйте, <strong>{$recipient['full_name']}</strong>!</p>
                                <p><strong>{$sender['full_name']}</strong> создал(а) для вас новую задачу:</p>
                                <p style='background:white;padding:15px;border-radius:8px;border-left:4px solid #3B82F6'><strong>{$taskTitle}</strong></p>
                                <a href='{$taskUrl}' class='button'>Открыть задачу</a>
                            </div>
                            <div class='footer'>TaskFlow Pro • " . date('Y') . "</div>
                        </div>
                    </body>
                    </html>
                ";
                
                // Используем PHP mail() или SMTP библиотеку если подключена
                $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: TaskFlow Pro <noreply@taskflow>\r\n";
                @mail($recipient['email'], $subject, $message, $headers);
            }
        }
        
        // Telegram уведомление
        if (!empty($settings['telegram'][$type])) {
            $tgStmt = $pdo->prepare("SELECT * FROM telegram_settings WHERE enabled = 1 LIMIT 1");
            $tgStmt->execute();
            $tgSettings = $tgStmt->fetch();
            
            if ($tgSettings && !empty($tgSettings['bot_token']) && !empty($tgSettings['chat_id'])) {
                $tgMessage = "{$subject}\n\n" .
                    "👤 <b>От:</b> {$sender['full_name']}\n" .
                    "📋 <b>Задача:</b> {$taskTitle}\n\n" .
                    "<a href='{$taskUrl}'>Открыть задачу</a>";
                
                $url = "https://api.telegram.org/bot{$tgSettings['bot_token']}/sendMessage";
                $data = [
                    'chat_id' => $tgSettings['chat_id'],
                    'text' => $tgMessage,
                    'parse_mode' => 'HTML'
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    } catch (Exception $e) {
        // Игнорируем ошибки уведомлений
    }
}

/**
 * Получить базовый URL приложения
 */
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = str_replace('/api', '', $path);
    return $protocol . $host . $path;
}

/**
 * Получить историю изменений задачи
 */
function getTaskHistory($taskId): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT h.*,
               u.full_name as user_name,
               u.avatar as user_avatar
        FROM task_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.task_id = ?
        ORDER BY h.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$taskId]);
    $history = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $history]);
}

/**
 * Подэтапы задач (Task Substages API) - СПРАВОЧНИК
 */

// GET /api/task-substages - получить справочник подэтапов
function getTaskSubstagesDict() {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM task_substages ORDER BY `order` ASC");
    $stmt->execute();
    $substages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $substages]);
}

// POST /api/task-substages - добавить подэтап в справочник
function createTaskSubstageDict($data) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }
    
    if (empty($data['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите название подэтапа']);
        exit;
    }
    
    // Получаем максимальный order
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) as max_order FROM task_substages");
    $stmt->execute();
    $maxOrder = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        INSERT INTO task_substages (name, color, `order`)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$data['name'], $data['color'] ?? '#6B7280', $maxOrder + 1]);

    $substageId = (int)$pdo->lastInsertId();

    auditLog($pdo, 'task.substage.created', [
        'actor' => $currentUser,
        'target_type' => 'task_substage',
        'target_id' => (string)$substageId,
        'summary' => 'Создан подэтап',
        'details' => [
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6B7280',
            'order' => (int)$maxOrder + 1,
        ],
    ]);

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $substageId,
            'name' => $data['name']
        ]
    ]);
}

// PUT /api/task-substages/:id - обновить подэтап
function updateTaskSubstageDict($id, $data) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }
    
    $updates = [];
    $params = [];
    
    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $params[] = $data['name'];
    }
    
    if (isset($data['color'])) {
        $updates[] = "color = ?";
        $params[] = $data['color'];
    }
    
    if (isset($data['order'])) {
        $updates[] = "`order` = ?";
        $params[] = $data['order'];
    }
    
    if (!empty($updates)) {
        $params[] = $id;
        $sql = "UPDATE task_substages SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $auditDetails = [
            'substage_id' => (int)$id,
        ];
        if (isset($data['name'])) {
            $auditDetails['name'] = $data['name'];
        }
        if (isset($data['color'])) {
            $auditDetails['color'] = $data['color'];
        }
        if (array_key_exists('order', $data)) {
            $auditDetails['order'] = (int)$data['order'];
        }

        auditLog($pdo, 'task.substage.updated', [
            'actor' => $currentUser,
            'target_type' => 'task_substage',
            'target_id' => (string)$id,
            'summary' => 'Обновлён подэтап',
            'details' => $auditDetails,
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Подэтап обновлён']);
}

// DELETE /api/task-substages/:id - удалить подэтап
function deleteTaskSubstageDict($id) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM task_substages WHERE id = ?");
    $stmt->execute([$id]);

    auditLog($pdo, 'task.substage.deleted', [
        'actor' => $currentUser,
        'target_type' => 'task_substage',
        'target_id' => (string)$id,
        'summary' => 'Удалён подэтап',
        'details' => [
            'substage_id' => (int)$id,
        ],
    ]);

    echo json_encode(['success' => true, 'message' => 'Подэтап удалён']);
}
