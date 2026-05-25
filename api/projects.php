<?php
/**
 * api/projects.php - Управление проектами
 *
 * Эндпоинты:
 * - GET /api/projects - список проектов
 * - GET /api/projects/:id - проект по ID
 * - POST /api/projects - создание проекта
 * - PUT /api/projects/:id - обновление проекта
 * - DELETE /api/projects/:id - удаление проекта
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/disk.php';

/**
 * Обработка запросов к /api/projects/*
 */
function handleProjects(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/projects - список проектов
    if ($method === 'GET' && $action === null) {
        if (!hasPermission($currentUser, 'projects.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $departmentFilter = $_GET['department_id'] ?? null;

        // root и пользователи с admin.full / leader.view видят все проекты
        if (hasPermission($currentUser, 'admin.full') || hasPermission($currentUser, 'leader.view')) {
            $stmt = $pdo->prepare("
                SELECT p.*,
                       u.full_name as creator_name,
                       COUNT(t.id) as tasks_count,
                       SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed_tasks
                FROM projects p
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN tasks t ON p.id = t.project_id
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            $stmt->execute();
        } else {
            // Остальные видят только проекты в своих отделах
            $deptIds = getUserDepartmentIds($currentUser['id']);
            if (empty($deptIds)) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
            $stmt = $pdo->prepare("
                SELECT p.*,
                       u.full_name as creator_name,
                       COUNT(t.id) as tasks_count,
                       SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed_tasks
                FROM projects p
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN tasks t ON p.id = t.project_id
                INNER JOIN project_departments pd ON p.id = pd.project_id
                WHERE pd.department_id IN ($placeholders)
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            $stmt->execute($deptIds);
        }

        $projects = $stmt->fetchAll();

        // Добавляем отделы и участников к каждому проекту
        foreach ($projects as &$project) {
            $project['calculated_progress'] = $project['tasks_count'] > 0
                ? (int)round(($project['completed_tasks'] / $project['tasks_count']) * 100)
                : (int)$project['progress'];

            // Получаем отделы проекта
            $stmt = $pdo->prepare("
                SELECT d.id, d.name
                FROM project_departments pd
                JOIN departments d ON pd.department_id = d.id
                WHERE pd.project_id = ?
            ");
            $stmt->execute([$project['id']]);
            $project['departments'] = $stmt->fetchAll();

            // Получаем участников проекта (создатель + ответственные за задачи)
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.full_name, u.avatar, u.login
                FROM (
                    SELECT p.created_by as user_id
                    FROM projects p WHERE p.id = ?
                    UNION
                    SELECT t.assigned_to as user_id
                    FROM tasks t WHERE t.project_id = ? AND t.assigned_to IS NOT NULL
                ) as user_ids
                JOIN users u ON u.id = user_ids.user_id
                LIMIT 10
            ");
            $stmt->execute([$project['id'], $project['id']]);
            $project['members'] = $stmt->fetchAll();
        }

        echo json_encode(['success' => true, 'data' => $projects]);
        exit;
    }

    // GET /api/projects/:id - получение проекта
    if ($method === 'GET' && $action !== null && is_numeric($action) && $id === null) {
        $projectId = (int)$action;

        $stmt = $pdo->prepare("
            SELECT p.*,
                   u.full_name as creator_name
            FROM projects p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        // Проверка прав на просмотр
        if (!hasPermission($currentUser, 'projects.view') || !canViewProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к проекту']);
            exit;
        }

        // Получаем отделы проекта
        $stmt = $pdo->prepare("
            SELECT d.id, d.name 
            FROM project_departments pd 
            JOIN departments d ON pd.department_id = d.id 
            WHERE pd.project_id = ?
        ");
        $stmt->execute([$projectId]);
        $project['departments'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $project]);
        exit;
    }

    // POST /api/projects - создание проекта
    if ($method === 'POST' && $action === null) {
        if (!hasPermission($currentUser, 'projects.create')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для создания проекта']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название проекта']);
            exit;
        }

        // Поддержка множественных отделов
        $departmentIds = [];
        if (!empty($data['department_ids']) && is_array($data['department_ids'])) {
            $departmentIds = $data['department_ids'];
        } elseif (!empty($data['department_id'])) {
            // Обратная совместимость
            $departmentIds = [(int)$data['department_id']];
        }

        // root и пользователи с admin.full / leader.view могут создавать без отделов
        if (empty($departmentIds) && !hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите хотя бы один отдел']);
            exit;
        }

        // Проверка прав (по первому отделу)
        if (!empty($departmentIds) && (!canCreateProject($currentUser, $departmentIds[0]) || !hasPermission($currentUser, 'projects.create'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для создания проекта']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO projects (name, description, priority, deadline, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['priority'] ?? 'medium',
            $data['deadline'] ?? null,
            $currentUser['id']
        ]);

        $newProjectId = $pdo->lastInsertId();

        // Создаём папку проекта в диске
        try {
            ensureProjectDiskFolder($pdo, (int)$newProjectId, (string)$data['name'], (int)$currentUser['id']);
        } catch (Exception $e) {
            // ignore
        }

        // Сохраняем отделы проекта
        $stmt = $pdo->prepare("INSERT INTO project_departments (project_id, department_id) VALUES (?, ?)");
        foreach ($departmentIds as $deptId) {
            if (is_numeric($deptId)) {
                $stmt->execute([$newProjectId, (int)$deptId]);
            }
        }

        // Авто-группчат проекта: "Чат проекта \"...\"" + добавляем весь отдел(ы)
        try {
            // Создаём/получаем проектный чат через API чата (логика добавления участников уже там)
            require_once __DIR__ . '/chat.php';

            $chatRoomName = 'Чат проекта "' . (string)$data['name'] . '"';

            // Проверяем, не создан ли уже чат (на всякий случай)
            $chatCheck = $pdo->prepare("SELECT id FROM chat_rooms WHERE type = 'project' AND name = CONCAT('project_', ?) LIMIT 1");
            $chatCheck->execute([$newProjectId]);
            $existing = $chatCheck->fetch();

            if (!$existing) {
                // Создаём комнату
                $chatStmt = $pdo->prepare("INSERT INTO chat_rooms (type, name, avatar, created_by) VALUES ('project', ?, ?, ?)");
                // В chat.php доступ к проекту вычисляется через str_replace('project_', '', name)
                // поэтому в name храним project_<id>, а в avatar кладём красивое отображаемое имя
                $chatStmt->execute(['project_' . (int)$newProjectId, $chatRoomName, (int)$currentUser['id']]);
                $roomId = (int)$pdo->lastInsertId();

                // Добавляем участников: все пользователи отделов проекта
                $membersStmt = $pdo->prepare("
                    SELECT DISTINCT ud.user_id
                    FROM project_departments pd
                    JOIN user_departments ud ON pd.department_id = ud.department_id
                    WHERE pd.project_id = ?
                ");
                $membersStmt->execute([(int)$newProjectId]);
                $members = $membersStmt->fetchAll(PDO::FETCH_COLUMN);

                $insMember = $pdo->prepare("INSERT IGNORE INTO chat_room_members (room_id, user_id, role) VALUES (?, ?, 'member')");
                foreach ($members as $mid) {
                    $insMember->execute([$roomId, (int)$mid]);
                }

                // Создателя сделаем админом
                $pdo->prepare("INSERT INTO chat_room_members (room_id, user_id, role) VALUES (?, ?, 'admin') ON DUPLICATE KEY UPDATE role='admin'")
                    ->execute([$roomId, (int)$currentUser['id']]);
            }
        } catch (Throwable $e) {
            // Чат не должен ломать создание проекта
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newProjectId,
                'name' => $data['name'],
                'message' => 'Проект успешно создан'
            ]
        ]);
        exit;
    }

    // PUT /api/projects/:id - обновление проекта
    if ($method === 'PUT' && $action !== null && is_numeric($action)) {
        $projectId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        // Получаем проект для проверки прав
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'projects.edit') || !canEditProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для редактирования проекта']);
            exit;
        }

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название проекта']);
            exit;
        }

        // История: снимок ДО изменений
        $before = [
            'name' => $project['name'] ?? '',
            'description' => $project['description'] ?? '',
            'priority' => $project['priority'] ?? 'medium',
            'deadline' => $project['deadline'] ?? null
        ];

        $stmt = $pdo->prepare("
            UPDATE projects
            SET name = ?, description = ?, priority = ?, deadline = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['priority'] ?? 'medium',
            $data['deadline'] ?? null,
            $projectId
        ]);

        // Обновляем отделы проекта
        if (isset($data['department_ids'])) {
            // Удаляем старые
            $pdo->prepare("DELETE FROM project_departments WHERE project_id = ?")->execute([$projectId]);
            // Добавляем новые
            if (is_array($data['department_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO project_departments (project_id, department_id) VALUES (?, ?)");
                foreach ($data['department_ids'] as $deptId) {
                    if (is_numeric($deptId)) {
                        $stmt->execute([$projectId, (int)$deptId]);
                    }
                }
            }
        }

        // История: пишем изменённые поля
        try {
            $after = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'priority' => $data['priority'] ?? 'medium',
                'deadline' => $data['deadline'] ?? null
            ];

            $historyStmt = $pdo->prepare("
                INSERT INTO project_history (project_id, user_id, action, field_name, old_value, new_value)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach (['name', 'description', 'priority', 'deadline'] as $field) {
                $oldVal = $before[$field];
                $newVal = $after[$field];
                if ((string)$oldVal !== (string)$newVal) {
                    $historyStmt->execute([
                        $projectId,
                        $currentUser['id'],
                        'update',
                        $field,
                        is_null($oldVal) ? null : (string)$oldVal,
                        is_null($newVal) ? null : (string)$newVal
                    ]);
                }
            }
        } catch (Exception $e) {
            // История не должна ломать обновление
        }

        echo json_encode(['success' => true, 'message' => 'Проект обновлён']);
        exit;
    }

    // GET /api/projects/:id/history - история изменений проекта
    if ($method === 'GET' && $action !== null && is_numeric($action) && $id === 'history') {
        $projectId = (int)$action;

        // Проверяем доступ к проекту
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        if (!hasPermission($currentUser, 'projects.view') || !canViewProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к проекту']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT ph.*, u.full_name as user_name
            FROM project_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            WHERE ph.project_id = ?
            ORDER BY ph.created_at DESC
        ");
        $stmt->execute([$projectId]);
        $history = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $history]);
        exit;
    }

    // DELETE /api/projects/:id - удаление проекта
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $projectId = (int)$action;

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'projects.delete') || !canDeleteProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления проекта']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);

        auditLog($pdo, 'entity.project.deleted', [
            'actor' => $currentUser,
            'target_type' => 'project',
            'target_id' => (string)$projectId,
            'summary' => 'Удалён проект',
            'details' => [
                'name' => $project['name'] ?? null,
                'department_id' => $project['department_id'] ?? null,
                'status' => $project['status'] ?? null,
            ],
        ]);
        
        // Удаляем чат проекта
        try {
            $chatStmt = $pdo->prepare("SELECT id FROM chat_rooms WHERE type = 'project' AND name = CONCAT('Чат проекта: ', (SELECT name FROM projects WHERE id = ? LIMIT 1))");
            $chatStmt->execute([$projectId]);
            $chatRoom = $chatStmt->fetch();
            
            if ($chatRoom) {
                // Удаляем сообщения чата
                $pdo->prepare("DELETE FROM chat_messages WHERE room_id = ?")->execute([$chatRoom['id']]);
                // Удаляем участников
                $pdo->prepare("DELETE FROM chat_room_members WHERE room_id = ?")->execute([$chatRoom['id']]);
                // Удаляем сам чат
                $pdo->prepare("DELETE FROM chat_rooms WHERE id = ?")->execute([$chatRoom['id']]);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки (таблиц может не быть)
        }

        echo json_encode(['success' => true, 'message' => 'Проект удалён']);
        exit;
    }

    // GET /api/projects/:id/tasks - список задач проекта
    if ($method === 'GET' && $action !== null && is_numeric($action) && $id === 'tasks') {
        $projectId = (int)$action;

        // Сначала проверяем доступ к проекту
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        // Проверка прав на просмотр
        if (!hasPermission($currentUser, 'projects.view') || !canViewProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к проекту']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT t.*,
                   u.full_name as assignee_name,
                   u.avatar as assignee_avatar,
                   p.name as project_name
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.project_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$projectId]);
        $tasks = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $tasks]);
        exit;
    }

    // GET /api/projects/:id/files - список файлов проекта
    if ($method === 'GET' && $action !== null && is_numeric($action) && $id === 'files') {
        $projectId = (int)$action;

        // Сначала проверяем доступ к проекту
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Проект не найден']);
            exit;
        }

        // Проверка прав на просмотр
        if (!canViewProject($currentUser, $project)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к проекту']);
            exit;
        }

        // Получаем файлы проекта И файлы из задач проекта
        $stmt = $pdo->prepare("
            SELECT f.*,
                   u.full_name as uploader_name,
                   t.title as task_title
            FROM files f
            LEFT JOIN users u ON f.uploaded_by = u.id
            LEFT JOIN tasks t ON f.task_id = t.id
            WHERE f.project_id = ? OR (f.task_id IS NOT NULL AND t.project_id = ?)
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$projectId, $projectId]);
        $files = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $files]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
