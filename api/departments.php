<?php
/**
 * api/departments.php - Управление отделами компании
 *
 * Эндпоинты:
 * - GET /api/departments - список отделов
 * - GET /api/departments/:id - отдел по ID
 * - POST /api/departments - создание отдела
 * - PUT /api/departments/:id - обновление отдела
 * - DELETE /api/departments/:id - удаление отдела
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

/**
 * Обработка запросов к /api/departments/*
 * @param string $method HTTP метод
 * @param string|null $action Действие
 * @param mixed $id ID ресурса
 */
function handleDepartments(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    // Проверка авторизации для всех операций
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/departments - список всех отделов (ПРОВЕРЯЕМ ПОСЛЕ special endpoints!)
    if ($method === 'GET' && $action === null) {
        if (!hasPermission($currentUser, 'departments.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        // root, administrator и руководитель видят все отделы
        if (hasPermission($currentUser, 'admin.full') || hasPermission($currentUser, 'leader.view')) {
            $stmt = $pdo->prepare("
                SELECT d.*,
                       (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) as employees_count,
                       (SELECT COUNT(DISTINCT p.id)
                        FROM projects p
                        LEFT JOIN project_departments pd ON p.id = pd.project_id
                        WHERE p.department_id = d.id OR pd.department_id = d.id) as projects_count,
                       (SELECT COUNT(DISTINCT t.id)
                        FROM tasks t
                        LEFT JOIN projects p ON t.project_id = p.id
                        LEFT JOIN project_departments pd ON p.id = pd.project_id
                        LEFT JOIN task_departments td ON t.id = td.task_id
                        WHERE p.department_id = d.id OR pd.department_id = d.id OR t.department_id = d.id OR td.department_id = d.id) as tasks_count
                FROM departments d
                ORDER BY d.name ASC
            ");
            $stmt->execute();
            $departments = $stmt->fetchAll();
        } else {
            // Менеджер и сотрудник видят только свои отделы
            $deptIds = getUserDepartmentIds($currentUser['id']);
            if (empty($deptIds)) {
                $departments = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT d.*,
                           (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) as employees_count,
                           (SELECT COUNT(DISTINCT p.id)
                            FROM projects p
                            LEFT JOIN project_departments pd ON p.id = pd.project_id
                            WHERE p.department_id = d.id OR pd.department_id = d.id) as projects_count,
                           (SELECT COUNT(DISTINCT t.id)
                            FROM tasks t
                            LEFT JOIN projects p ON t.project_id = p.id
                            LEFT JOIN project_departments pd ON p.id = pd.project_id
                            LEFT JOIN task_departments td ON t.id = td.task_id
                            WHERE p.department_id = d.id OR pd.department_id = d.id OR t.department_id = d.id OR td.department_id = d.id) as tasks_count
                    FROM departments d
                    WHERE d.id IN ($placeholders)
                    ORDER BY d.name ASC
                ");
                $stmt->execute($deptIds);
                $departments = $stmt->fetchAll();
            }
        }

        echo json_encode(['success' => true, 'data' => $departments]);
        exit;
    }

    // GET /api/departments/:id/employees - сотрудники отдела
    if ($method === 'GET' && $action !== null && $id === 'employees') {
        $deptId = (int)$action;
        error_log('Loading employees for department: ' . $deptId);

        $stmt = $pdo->prepare("
            SELECT u.id, u.login, u.full_name, u.avatar, u.last_login, u.role
            FROM users u
            WHERE u.department_id = ?
            ORDER BY u.full_name ASC
        ");
        $stmt->execute([$deptId]);
        $employees = $stmt->fetchAll();
        error_log('Found employees: ' . count($employees));

        echo json_encode(['success' => true, 'data' => $employees]);
        exit;
    }

    // GET /api/departments/:id/projects - проекты отдела
    if ($method === 'GET' && $action !== null && $id === 'projects') {
        $deptId = (int)$action;
        error_log('Loading projects for department: ' . $deptId);

        // Проекты отдела: из projects.department_id + из project_departments
        $stmt = $pdo->prepare("
            SELECT p.*,
                   u.full_name as creator_name,
                   COUNT(t.id) as tasks_count,
                   SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed_tasks
            FROM projects p
            LEFT JOIN users u ON p.created_by = u.id
            LEFT JOIN tasks t ON p.id = t.project_id
            LEFT JOIN project_departments pd ON p.id = pd.project_id
            WHERE p.department_id = ? OR pd.department_id = ?
            GROUP BY p.id, u.full_name
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$deptId, $deptId]);
        $projects = $stmt->fetchAll();
        
        error_log('SQL Query executed with deptId: ' . $deptId);
        error_log('Found projects count: ' . count($projects));
        error_log('Projects data: ' . json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Добавляем calculated_progress
        foreach ($projects as &$project) {
            $project['calculated_progress'] = $project['tasks_count'] > 0
                ? round(($project['completed_tasks'] / $project['tasks_count']) * 100)
                : 0;
        }

        echo json_encode(['success' => true, 'data' => $projects]);
        exit;
    }

    // GET /api/departments/:id/tasks - задачи отдела
    if ($method === 'GET' && $action !== null && $id === 'tasks') {
        $deptId = (int)$action;
        error_log('Loading tasks for department: ' . $deptId);

        // Задачи отдела: из projects отдела + из task_departments
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.*,
                   p.name as project_name,
                   u.full_name as assignee_name,
                   u.avatar as assignee_avatar,
                   ts.color as status_color
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN task_stages ts ON t.status = ts.name
            LEFT JOIN task_departments td ON t.id = td.task_id
            WHERE p.department_id = ? OR t.department_id = ? OR td.department_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$deptId, $deptId, $deptId]);
        $tasks = $stmt->fetchAll();
        error_log('Found tasks: ' . count($tasks));

        echo json_encode(['success' => true, 'data' => $tasks]);
        exit;
    }

    // GET /api/departments/:id - получение отдела по ID (ПРОВЕРЯЕМ ПОСЛЕ special endpoints!)
    if ($method === 'GET' && $action !== null && $id === null) {
        $deptId = (int)$action;

        $stmt = $pdo->prepare("
            SELECT d.*,
                   (SELECT COUNT(DISTINCT ud.user_id) 
                    FROM user_departments ud 
                    WHERE ud.department_id = d.id) as employees_count,
                   (SELECT COUNT(DISTINCT p.id) 
                    FROM projects p 
                    LEFT JOIN project_departments pd ON p.id = pd.project_id 
                    WHERE p.department_id = d.id OR pd.department_id = d.id) as projects_count
            FROM departments d
            WHERE d.id = ?
        ");
        $stmt->execute([$deptId]);
        $department = $stmt->fetch();

        if (!$department) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Отдел не найден']);
            exit;
        }

        // Проверка прав на просмотр
        if (!hasPermission($currentUser, 'departments.view') || !canViewDepartment($currentUser, $deptId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к отделу']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $department]);
        exit;
    }

    // POST /api/departments - создание отдела
    if ($method === 'POST' && $action === null) {
        if (!hasPermission($currentUser, 'departments.create') || !canCreateDepartment($currentUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для создания отдела']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название отдела']);
            exit;
        }

        // Проверка на дубликат
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
        $stmt->execute([$data['name']]);

        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Отдел с таким названием уже существует']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO departments (name, description, icon)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['icon'] ?? 'building'
        ]);

        $newDeptId = (int)$pdo->lastInsertId();

        auditLog($pdo, 'entity.department.created', [
            'actor' => $currentUser,
            'target_type' => 'department',
            'target_id' => (string)$newDeptId,
            'summary' => 'Создан отдел',
            'details' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon' => $data['icon'] ?? 'building',
            ],
        ]);
        
        // Добавляем создателя в отдел, если это не пользователь с полным доступом к структуре
        if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
            $stmt = $pdo->prepare(" 
                INSERT INTO user_departments (user_id, department_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$currentUser['id'], $newDeptId]);

            auditLog($pdo, 'rbac.user_department.added', [
                'actor' => $currentUser,
                'target_type' => 'user_department',
                'target_id' => $currentUser['id'] . ':' . $newDeptId,
                'summary' => 'Пользователь добавлен в отдел',
                'details' => [
                    'user_id' => (int)$currentUser['id'],
                    'department_id' => $newDeptId,
                    'self_service' => true,
                    'source' => 'department.create',
                ],
            ]);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newDeptId,
                'name' => $data['name'],
                'message' => 'Отдел успешно создан'
            ]
        ]);
        exit;
    }

    // PUT /api/departments/:id - обновление отдела
    if ($method === 'PUT' && $action !== null) {
        $deptId = (int)$action;
        
        // Проверка прав
        if (!hasPermission($currentUser, 'departments.edit') || !canEditDepartment($currentUser, $deptId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для редактирования отдела']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название отдела']);
            exit;
        }

        // Проверка на дубликат (исключая текущий отдел)
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
        $stmt->execute([$data['name'], $deptId]);
        
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Отдел с таким названием уже существует']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE departments
            SET name = ?, description = ?, icon = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['icon'] ?? 'building',
            $deptId
        ]);

        auditLog($pdo, 'entity.department.updated', [
            'actor' => $currentUser,
            'target_type' => 'department',
            'target_id' => (string)$deptId,
            'summary' => 'Обновлён отдел',
            'details' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon' => $data['icon'] ?? 'building',
            ],
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Отдел обновлён']);
        exit;
    }
    
    // DELETE /api/departments/:id - удаление отдела
    if ($method === 'DELETE' && $action !== null) {
        $deptId = (int)$action;
        
        // Проверка прав
        if (!hasPermission($currentUser, 'departments.delete') || !canDeleteDepartment($currentUser, $deptId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления отдела']);
            exit;
        }

        // Проверка наличия сотрудников в отделе
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_departments WHERE department_id = ?");
        $stmt->execute([$deptId]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Нельзя удалить отдел, в котором есть сотрудники',
                'employees_count' => $result['count']
            ]);
            exit;
        }

        // Проверка наличия проектов в отделе
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM projects WHERE department_id = ?");
        $stmt->execute([$deptId]);
        $result = $stmt->fetch();

        if ($result['count'] > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Нельзя удалить отдел, в котором есть проекты',
                'projects_count' => $result['count']
            ]);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$deptId]);

        auditLog($pdo, 'entity.department.deleted', [
            'actor' => $currentUser,
            'target_type' => 'department',
            'target_id' => (string)$deptId,
            'summary' => 'Удалён отдел',
            'details' => [
                'department_id' => $deptId,
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Отдел удалён']);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
