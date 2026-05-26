<?php
/**
 * api/users.php - CRUD пользователей и управление правами
 * 
 * Эндпоинты:
 * - GET /api/users - список пользователей
 * - GET /api/users/:id - пользователь по ID
 * - POST /api/users - создание пользователя
 * - PUT /api/users/:id - обновление пользователя
 * - DELETE /api/users/:id - удаление пользователя
 * - PATCH /api/users/:id/password - смена пароля
 */

/**
 * Обработка запросов к /api/users/*
 * @param string $method HTTP метод
 * @param string|null $action Действие
 * @param mixed $id ID ресурса
 */
function handleUsers(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    require_once __DIR__ . '/roles.php';

    // Ensure system root role exists even if DB was partially initialized
    if ($currentUser && $currentUser['role'] === 'root') {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'root' LIMIT 1");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $rootPermissions = [
                'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'projects' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'departments' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'files' => ['view' => true, 'upload' => true, 'edit' => true, 'delete' => true],
                'knowledge' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'chat' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true, 'forward' => true, 'create_group' => true],
                'mail' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true],
                'crm' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'export' => true, 'stages_manage' => true],
                'leader' => ['view' => true, 'shifts_manage' => true, 'export' => true],
                'users' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'admin' => ['full' => true]
            ];
            $stmt = $pdo->prepare("INSERT INTO roles (name, description, icon, permissions, is_system) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([
                'root',
                'Полный доступ ко всей системе',
                'shield',
                json_encode($rootPermissions, JSON_UNESCAPED_UNICODE)
            ]);
        }
    }
    
    // Проверка авторизации для всех операций
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $canViewUsers = hasAdminAccess($currentUser) || hasPermission($currentUser, 'users.view');

    $loadUserById = static function(PDO $pdo, int $userId): ?array {
        $stmt = $pdo->prepare("SELECT id, login, full_name, role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    };

    $loadRoleByName = static function(PDO $pdo, string $roleName): ?array {
        $stmt = $pdo->prepare("SELECT name, is_system FROM roles WHERE name = ? LIMIT 1");
        $stmt->execute([$roleName]);
        $row = $stmt->fetch();
        return $row ?: null;
    };
    
    // GET /api/users - список всех пользователей
    if ($method === 'GET' && $action === null) {
        if (!$canViewUsers) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $stmt = $pdo->prepare(" 
            SELECT u.id, u.login, u.email, u.full_name, u.role, u.department_id, u.created_at, u.last_login,
                   d.name as department_name,
                   COALESCE(GROUP_CONCAT(DISTINCT ud.department_id ORDER BY ud.department_id SEPARATOR ','), '') as department_ids,
                   COALESCE(GROUP_CONCAT(DISTINCT dd.name ORDER BY dd.name SEPARATOR ', '), '') as department_names
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN user_departments ud ON ud.user_id = u.id
            LEFT JOIN departments dd ON dd.id = ud.department_id
            GROUP BY u.id, u.login, u.email, u.full_name, u.role, u.department_id, u.created_at, u.last_login, d.name
            ORDER BY u.created_at DESC
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $users]);
        exit;
    }

    // GET /api/users/roles - список всех доступных ролей
    if ($method === 'GET' && $action === 'roles') {
        if (!$canViewUsers) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        // Root видит все роли, остальные — кроме root
        if ($currentUser['role'] === 'root') {
            $stmt = $pdo->prepare("SELECT id, name, description, is_system FROM roles ORDER BY name");
            $stmt->execute();
        } else {
            // Возвращаем все роли, кроме root (для выбора при создании/редактировании пользователя)
            $stmt = $pdo->prepare("SELECT id, name, description, is_system FROM roles WHERE name != 'root' ORDER BY name");
            $stmt->execute();
        }
        $roles = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $roles]);
        exit;
    }
    
    // GET /api/users/:id - получение пользователя по ID
    if ($method === 'GET' && $action !== null) {
        if (!$canViewUsers) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $userId = (int)$action;
        
        $stmt = $pdo->prepare(" 
            SELECT u.id, u.login, u.email, u.full_name, u.role, u.department_id, u.created_at, u.last_login,
                   d.name as department_name,
                   COALESCE(GROUP_CONCAT(DISTINCT ud.department_id ORDER BY ud.department_id SEPARATOR ','), '') as department_ids,
                   COALESCE(GROUP_CONCAT(DISTINCT dd.name ORDER BY dd.name SEPARATOR ', '), '') as department_names
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN user_departments ud ON ud.user_id = u.id
            LEFT JOIN departments dd ON dd.id = ud.department_id
            WHERE u.id = ?
            GROUP BY u.id, u.login, u.email, u.full_name, u.role, u.department_id, u.created_at, u.last_login, d.name
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $user]);
        exit;
    }
    
    // POST /api/users - создание пользователя (только администраторы и root)
    if ($method === 'POST' && $action === null) {
        if (!hasPermission($currentUser, 'users.create')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['login']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите логин и пароль']);
            exit;
        }

        // Логин и email могут быть разными. Если логин похож на email — используем его как email.
        $email = $data['email'] ?? null;
        if (!$email && filter_var($data['login'], FILTER_VALIDATE_EMAIL)) {
            $email = $data['login'];
        }

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный формат email']);
            exit;
        }

        // Валидация сложности пароля
        if (strlen($data['password']) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов']);
            exit;
        }

        // Проверка существования пользователя
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->execute([$data['login']]);

        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пользователь с таким логином уже существует']);
            exit;
        }
        
        // Привилегированные роли требуют admin.full; root остаётся break-glass fallback.
        $role = trim((string)($data['role'] ?? 'employee'));
        if ($role === '') {
            $role = 'employee';
        }
        if (!$loadRoleByName($pdo, $role)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Указанная роль не найдена']);
            exit;
        }
        if ($role === 'root' && ($currentUser['role'] ?? null) !== 'root') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только root может создавать root-пользователей']);
            exit;
        }
        if ($role !== 'root' && hasPermission(['role' => $role], 'admin.full') && !hasAdminAccess($currentUser)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут создавать привилегированные роли']);
            exit;
        }
        
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $departmentIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['department_ids'] ?? null) ? $data['department_ids'] : []))));
        $primaryDepartmentId = null;
        if (array_key_exists('department_id', $data) && $data['department_id'] !== '' && $data['department_id'] !== null) {
            $primaryDepartmentId = (int)$data['department_id'];
        } elseif (!empty($departmentIds)) {
            $primaryDepartmentId = (int)$departmentIds[0];
        }

        $stmt = $pdo->prepare(" 
            INSERT INTO users (login, email, password_hash, full_name, role, department_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['login'],
            $email,
            $passwordHash,
            $data['full_name'] ?? '',
            $role,
            $primaryDepartmentId
        ]);
        
        $newUserId = $pdo->lastInsertId();

        auditLog($pdo, 'entity.user.created', [
            'actor' => $currentUser,
            'target_type' => 'user',
            'target_id' => (string)$newUserId,
            'summary' => 'Создан пользователь',
            'details' => [
                'login' => $data['login'],
                'full_name' => $data['full_name'] ?? '',
                'role' => $role,
                'department_id' => $primaryDepartmentId,
                'department_ids' => $departmentIds,
                'email_present' => $email !== null && $email !== '',
            ],
        ]);

        if (!empty($departmentIds)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO user_departments (user_id, department_id) VALUES (?, ?)");
            foreach ($departmentIds as $deptId) {
                $stmt->execute([$newUserId, $deptId]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newUserId,
                'login' => $data['login'],
                'email' => $email,
                'message' => 'Пользователь успешно создан'
            ]
        ]);
        exit;
    }
    
    // PUT /api/users/:id - обновление пользователя
    if ($method === 'PUT' && $action !== null) {
        $userId = (int)$action;
        $targetUserBefore = $loadUserById($pdo, $userId);
        if (!$targetUserBefore) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }

        // Проверка прав: можно редактировать только себя или (при наличии users.edit) любого
        if ($currentUser['id'] !== $userId && !hasPermission($currentUser, 'users.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $updates = [];
        $params = [];

        if (isset($data['full_name'])) {
            $updates[] = "full_name = ?";
            $params[] = $data['full_name'];
        }

        $departmentIds = null;
        if (array_key_exists('department_ids', $data)) {
            $departmentIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['department_ids']) ? $data['department_ids'] : []))));
            $primaryDepartmentId = !empty($departmentIds) ? (int)$departmentIds[0] : null;
            $updates[] = "department_id = ?";
            $params[] = $primaryDepartmentId;
        } elseif (isset($data['department_id'])) {
            $updates[] = "department_id = ?";
            $params[] = $data['department_id'];
        }

        if (array_key_exists('email', $data)) {
            $email = $data['email'];
            if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Некорректный формат email']);
                exit;
            }
            $updates[] = "email = ?";
            $params[] = ($email === '' ? null : $email);
        }

        // Смена роли — отдельное право (users.edit) + ограничение списка
        if (isset($data['role']) && hasPermission($currentUser, 'users.edit')) {
            $requestedRole = trim((string)$data['role']);
            if ($requestedRole === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите роль']);
                exit;
            }

            if (!$loadRoleByName($pdo, $requestedRole)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Указанная роль не найдена']);
                exit;
            }

            if ($requestedRole === 'root' && ($currentUser['role'] ?? null) !== 'root') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только root может назначать root-пользователя']);
                exit;
            }

            if ($requestedRole !== 'root' && hasPermission(['role' => $requestedRole], 'admin.full') && !hasPermission($currentUser, 'admin.full') && ($currentUser['role'] ?? null) !== 'root') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут назначать привилегированные роли']);
                exit;
            }

            $updates[] = "role = ?";
            $params[] = $requestedRole;
            $newRole = $requestedRole;
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нет данных для обновления']);
            exit;
        }
        
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($departmentIds !== null) {
            $stmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = ?");
            $stmt->execute([$userId]);
            if (!empty($departmentIds)) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO user_departments (user_id, department_id) VALUES (?, ?)");
                foreach ($departmentIds as $deptId) {
                    $stmt->execute([$userId, $deptId]);
                }
            }
        }

        if (isset($newRole) && (string)($targetUserBefore['role'] ?? '') !== (string)$newRole) {
            auditLog($pdo, 'rbac.user_role.changed', [
                'actor' => $currentUser,
                'target_type' => 'user',
                'target_id' => (string)$userId,
                'summary' => 'Изменена роль пользователя',
                'details' => [
                    'target_login' => $targetUserBefore['login'] ?? null,
                    'old_role' => $targetUserBefore['role'] ?? null,
                    'new_role' => $newRole,
                ],
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Пользователь обновлён']);
        exit;
    }
    
    // DELETE /api/users/:id - удаление пользователя
    if ($method === 'DELETE' && $action !== null) {
        if (!hasPermission($currentUser, 'users.delete')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления пользователя']);
            exit;
        }

        $userId = (int)$action;

        $targetUser = $loadUserById($pdo, $userId);
        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }

        // Нельзя удалить самого себя
        if ($userId === $currentUser['id']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить самого себя']);
            exit;
        }

        // Root нельзя удалять через обычный интерфейс даже другому root.
        if (($targetUser['role'] ?? null) === 'root' || ($targetUser['login'] ?? null) === 'root') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить root-пользователя']);
            exit;
        }

        // Базовая защита от удаления системных технических аккаунтов.
        if (strpos((string)($targetUser['login'] ?? ''), 'system_') === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить системного пользователя']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        if ($stmt->rowCount() < 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }

        auditLog($pdo, 'entity.user.deleted', [
            'actor' => $currentUser,
            'target_type' => 'user',
            'target_id' => (string)$userId,
            'summary' => 'Удалён пользователь',
            'details' => [
                'target_login' => $targetUser['login'] ?? null,
                'target_full_name' => $targetUser['full_name'] ?? null,
                'target_role' => $targetUser['role'] ?? null,
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Пользователь удалён']);
        exit;
    }

    // PATCH /api/users/:id/password - смена пароля
    if ($method === 'PATCH' && $action === 'password') {
        $userId = (int)$id;

        // Проверка прав
        if ($currentUser['id'] !== $userId && !hasPermission($currentUser, 'users.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $targetUserBefore = $loadUserById($pdo, $userId);
        if (!$targetUserBefore) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите новый пароль']);
            exit;
        }
        
        if (strlen($data['password']) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов']);
            exit;
        }
        
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);

        auditLog($pdo, 'entity.user.password_changed', [
            'actor' => $currentUser,
            'target_type' => 'user',
            'target_id' => (string)$userId,
            'summary' => 'Изменён пароль пользователя',
            'details' => [
                'target_login' => $targetUserBefore['login'] ?? null,
                'changed_by_self' => (int)$currentUser['id'] === (int)$userId,
            ],
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Пароль изменён']);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
