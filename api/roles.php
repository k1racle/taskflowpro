<?php
/**
 * api/roles.php - Управление ролями и правами
 *
 * Эндпоинты:
 * - GET /api/roles - список ролей
 * - GET /api/roles/:id - роль по ID
 * - POST /api/roles - создание роли
 * - PUT /api/roles/:id - обновление роли
 * - DELETE /api/roles/:id - удаление роли
 * - GET /api/roles/:id/permissions - права роли
 * - PUT /api/roles/:id/permissions - обновление прав роли
 * - GET /api/permissions - список всех прав
 */

require_once __DIR__ . '/auth.php';

function hasAdminAccess(array $user): bool {
    if (!$user) {
        return false;
    }

    if (($user['role'] ?? null) === 'root') {
        return true;
    }

    return hasPermission($user, 'admin.full');
}

function getSystemRolePermissionCodes(string $roleName): array {
    static $map = [
        'root' => ['admin.full'],
        'employee' => [
            'tasks.view', 'tasks.create', 'tasks.edit',
            'projects.view', 'projects.create', 'projects.edit',
            'departments.view',
            'users.view',
            'crm.view', 'crm.create', 'crm.edit'
        ],
    ];

    if ($roleName === 'leader') {
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT code FROM permissions WHERE code <> 'admin.full' ORDER BY code");
            $codes = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            return array_values(array_filter(array_map('strval', $codes)));
        } catch (Throwable $e) {
            return [
                'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
                'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
                'departments.view', 'departments.create', 'departments.edit', 'departments.delete',
                'users.view', 'users.create', 'users.edit', 'users.delete',
                'chat.view', 'chat.send', 'chat.edit', 'chat.delete', 'chat.forward', 'chat.create_group',
                'crm.view', 'crm.create', 'crm.edit', 'crm.delete', 'crm.export', 'crm.stages.manage',
                'leader.view', 'leader.shifts.manage', 'leader.export'
            ];
        }
    }

    return $map[$roleName] ?? [];
}

function ensureSystemRootRole(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'root' LIMIT 1");
    $stmt->execute();
    if ($stmt->fetch()) return;

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

/**
 * Обработка запросов к /api/roles/*
 */
function handleRoles(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    ensureSystemRootRole($pdo);
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // Non-admin users still need to read roles list to select a role when creating users.
    // Limit management operations (create/update/delete/permissions) to admins.
    $isReadOnlyList = ($method === 'GET' && $action === null);
    if (!$isReadOnlyList && !hasAdminAccess($currentUser)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы могут управлять ролями']);
        exit;
    }

    // GET /api/roles - список ролей
    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("
            SELECT r.*,
                   (SELECT COUNT(*) FROM users u WHERE u.role = r.name) as users_count
            FROM roles r
            ORDER BY r.name
        ");
        $stmt->execute();
        $roles = $stmt->fetchAll();

        // Подтягиваем permission codes из role_permissions (источник истины для RBAC).
        $roleIds = array_values(array_filter(array_map(static function($r) {
            return isset($r['id']) ? (int)$r['id'] : null;
        }, $roles)));

        $rolePermissionCodesById = [];
        if (!empty($roleIds)) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = $pdo->prepare("
                SELECT rp.role_id, p.code
                FROM role_permissions rp
                JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id IN ($placeholders)
                ORDER BY p.category, p.name
            ");
            $stmt->execute($roleIds);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $rid = (int)($row['role_id'] ?? 0);
                $code = (string)($row['code'] ?? '');
                if ($rid < 1 || $code === '') continue;
                $rolePermissionCodesById[$rid] ??= [];
                $rolePermissionCodesById[$rid][] = $code;
            }
        }

        // Парсим JSON права для каждой роли
        foreach ($roles as &$role) {
            if ($role['permissions']) {
                $role['permissions'] = json_decode($role['permissions'], true);
            } else {
                $role['permissions'] = [];
            }

            $rid = isset($role['id']) ? (int)$role['id'] : 0;
            $role['permission_codes'] = $rid > 0 ? ($rolePermissionCodesById[$rid] ?? []) : [];
        }

        echo json_encode(['success' => true, 'data' => $roles]);
        exit;
    }

    // GET /api/roles/:id - роль по ID
    if ($method === 'GET' && $action !== null && is_numeric($action)) {
        $roleId = (int)$action;

        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();

        if (!$role) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Роль не найдена']);
            exit;
        }

        // Парсим JSON права
        if ($role['permissions']) {
            $role['permissions'] = json_decode($role['permissions'], true);
        } else {
            $role['permissions'] = [];
        }

        // RBAC source of truth: permission codes via role_permissions.
        $stmt = $pdo->prepare("
            SELECT p.code
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.category, p.name
        ");
        $stmt->execute([$roleId]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $role['permission_codes'] = array_values(array_filter(array_map('strval', $codes ?: [])));

        echo json_encode(['success' => true, 'data' => $role]);
        exit;
    }

    // POST /api/roles - создание роли
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название роли']);
            exit;
        }

        // Проверка на дубликат
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
        $stmt->execute([$data['name']]);

        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Роль с таким названием уже существует']);
            exit;
        }

        $requestedPermissionCodes = [];
        if (!empty($data['permissions']) && is_array($data['permissions'])) {
            $requestedPermissionCodes = array_values(array_filter(array_map('strval', $data['permissions'])));
        }
        $permissionScopes = is_array($data['permission_scopes'] ?? null) ? $data['permission_scopes'] : [];

        $stmt = $pdo->prepare("
            INSERT INTO roles (name, description, icon, permissions, is_system)
            VALUES (?, ?, ?, ?, 0)
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['icon'] ?? 'shield',
            json_encode($requestedPermissionCodes)
        ]);

        $newRoleId = $pdo->lastInsertId();

        // Если указаны права, добавляем их
        $appliedPermissionCodes = [];
        $skippedPermissionCodes = [];
        if (!empty($data['permissions']) && is_array($data['permissions'])) {
            foreach ($data['permissions'] as $permissionCode) {
                // Сначала находим ID права по коду
                $stmt = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
                $stmt->execute([$permissionCode]);
                $permId = $stmt->fetchColumn();
                if (!$permId) {
                    $skippedPermissionCodes[] = (string)$permissionCode;
                    continue;
                }

                $appliedPermissionCodes[] = (string)$permissionCode;
                $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                $stmt->execute([$newRoleId, (int)$permId]);
            }
        }

        auditLog($pdo, 'rbac.role.created', [
            'actor' => $currentUser,
            'target_type' => 'role',
            'target_id' => (string)$newRoleId,
            'summary' => 'Создана роль',
            'details' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'icon' => $data['icon'] ?? 'shield',
                'requested_permission_codes' => $requestedPermissionCodes,
                'applied_permission_codes' => array_values(array_unique($appliedPermissionCodes)),
                'skipped_permission_codes' => array_values(array_unique($skippedPermissionCodes)),
                'permission_scopes' => $permissionScopes,
            ],
        ]);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newRoleId,
                'name' => $data['name'],
                'message' => 'Роль успешно создана'
            ]
        ]);
        exit;
    }

    // PUT /api/roles/:id - обновление роли
    if ($method === 'PUT' && $action !== null && is_numeric($action)) {
        $roleId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        // Проверка на системную роль
        $stmt = $pdo->prepare("SELECT id, name, description, icon, permissions, is_system FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();

        if (!$role) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Роль не найдена']);
            exit;
        }

        // Только root может редактировать системные роли
        if ($role['is_system'] && ($currentUser['role'] ?? null) !== 'root') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только root может редактировать системные роли']);
            exit;
        }

        if (!empty($data['name'])) {
            // Проверка на дубликат
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ? AND id != ?");
            $stmt->execute([$data['name'], $roleId]);

            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Роль с таким названием уже существует']);
                exit;
            }

            // Обновляем все поля включая иконку
            $stmt = $pdo->prepare("
                UPDATE roles 
                SET name = ?, description = ?, icon = ?, permissions = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'], 
                $data['description'] ?? '', 
                $data['icon'] ?? 'shield',
                json_encode($data['permissions'] ?? []),
                $roleId
            ]);

            auditLog($pdo, 'rbac.role.updated', [
                'actor' => $currentUser,
                'target_type' => 'role',
                'target_id' => (string)$roleId,
                'summary' => 'Обновлена роль',
                'details' => [
                    'before' => [
                        'name' => $role['name'] ?? null,
                        'description' => $role['description'] ?? null,
                        'icon' => $role['icon'] ?? null,
                        'is_system' => !empty($role['is_system']),
                    ],
                    'after' => [
                        'name' => $data['name'],
                        'description' => $data['description'] ?? '',
                        'icon' => $data['icon'] ?? 'shield',
                    ],
                ],
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Роль обновлена']);
        exit;
    }

    // DELETE /api/roles/:id - удаление роли
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $roleId = (int)$action;

        // Проверка на системную роль
        $stmt = $pdo->prepare("SELECT id, name, description, icon, permissions, is_system FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();

        if (!$role) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Роль не найдена']);
            exit;
        }

        // Только root может удалять системные роли
        if ($role['is_system'] && ($currentUser['role'] ?? null) !== 'root') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только root может удалять системные роли']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);

        auditLog($pdo, 'rbac.role.deleted', [
            'actor' => $currentUser,
            'target_type' => 'role',
            'target_id' => (string)$roleId,
            'summary' => 'Удалена роль',
            'details' => [
                'before' => [
                    'name' => $role['name'] ?? null,
                    'description' => $role['description'] ?? null,
                    'icon' => $role['icon'] ?? null,
                    'is_system' => !empty($role['is_system']),
                ],
            ],
        ]);

        echo json_encode(['success' => true, 'message' => 'Роль удалена']);
        exit;
    }

    // PUT /api/roles/:id/permissions - обновление прав роли
    if ($method === 'PUT' && $action !== null) {
        $parts = explode('/', $action);
        if (isset($parts[1]) && $parts[1] === 'permissions') {
            $roleId = (int)$parts[0];
            $data = json_decode(file_get_contents('php://input'), true);
            $beforeStmt = $pdo->prepare(
                "SELECT p.code
                 FROM role_permissions rp
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ?
                 ORDER BY p.code"
            );
            $beforeStmt->execute([$roleId]);
            $beforePermissions = array_values(array_filter(array_map('strval', $beforeStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

            // Проверка на системную роль
            $stmt = $pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch();

            // Только root может изменять права системных ролей
            if ($role && $role['is_system'] && ($currentUser['role'] ?? null) !== 'root') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только root может изменять права системных ролей']);
                exit;
            }

            $permissionScopes = is_array($data['permission_scopes'] ?? null) ? $data['permission_scopes'] : [];

            // Удаляем старые права
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);

            // Добавляем новые
            $notFound = [];
            if (!empty($data['permissions']) && is_array($data['permissions'])) {
                foreach ($data['permissions'] as $permissionCode) {
                    // Сначала находим ID права по коду
                    $stmt = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
                    $stmt->execute([$permissionCode]);
                    $perm = $stmt->fetch();
                    
                    if ($perm) {
                        $stmt = $pdo->prepare("
                            INSERT INTO role_permissions (role_id, permission_id)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$roleId, $perm['id']]);
                    } else {
                        $notFound[] = $permissionCode;
                    }
                }
            }

            if (!empty($notFound)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'error' => 'Не найдены права: ' . implode(', ', $notFound),
                    'not_found' => $notFound
                ]);
                exit;
            }

            $afterStmt = $pdo->prepare(
                "SELECT p.code
                 FROM role_permissions rp
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ?
                 ORDER BY p.code"
            );
            $afterStmt->execute([$roleId]);
            $afterPermissions = array_values(array_filter(array_map('strval', $afterStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

            auditLog($pdo, 'rbac.role_permissions.changed', [
                'actor' => $currentUser,
                'target_type' => 'role',
                'target_id' => (string)$roleId,
                'summary' => 'Изменены права роли',
                'details' => [
                    'role_id' => $roleId,
                    'before_permissions' => $beforePermissions,
                    'after_permissions' => $afterPermissions,
                    'permission_scopes' => $permissionScopes,
                ],
            ]);

            echo json_encode(['success' => true, 'message' => 'Права обновлены']);
            exit;
        }
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * GET /api/permissions - список всех прав
 */
function handlePermissions(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   COUNT(DISTINCT rp.role_id) as roles_count
            FROM permissions p
            LEFT JOIN role_permissions rp ON p.id = rp.permission_id
            GROUP BY p.id
            ORDER BY p.category, p.name
        ");
        $stmt->execute();
        $permissions = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $permissions]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Проверка наличия права у пользователя
 */
function hasPermission($user, string $permissionCode): bool {
    $pdo = getPDO();
    $userRole = (string)($user['role'] ?? '');
    
    // Break-glass: root всегда имеет полный доступ.
    if ($userRole === 'root') {
        return true;
    }

    if ($userRole === '') {
        return false;
    }

    // One-role-per-user: права берём через users.role -> roles -> role_permissions.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM roles r
        JOIN role_permissions rp ON r.id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE r.name = ? AND p.code = ?
    ");

    $stmt->execute([$userRole, $permissionCode]);
    $result = $stmt->fetch();

    return (int)($result['count'] ?? 0) > 0;
}

/**
 * Проверка наличия любого права из списка
 */
function hasAnyPermission($user, array $permissionCodes): bool {
    foreach ($permissionCodes as $code) {
        if (hasPermission($user, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Получить все права пользователя
 */
function getUserPermissions($userId): array {
    $pdo = getPDO();

    // Break-glass: root всегда имеет полный доступ,
    // даже если таблицы ролей/прав ещё не заполнены.
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    if ($role === 'root') {
        return [[
            'code' => 'admin.full',
            'name' => 'Полный доступ (break-glass)',
            'category' => 'admin'
        ]];
    }

    if (!$role) {
        return [];
    }

    // One-role-per-user: права берём через users.role -> roles -> role_permissions.
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.code, p.name, p.category
        FROM roles r
        JOIN role_permissions rp ON r.id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE r.name = ?
        ORDER BY p.category, p.name
    ");

    $stmt->execute([(string)$role]);
    return $stmt->fetchAll();
}

/**
 * Получить все роли пользователя
 */
function getUserRoles($userId): array {
    $pdo = getPDO();

    // One-role-per-user: возвращаем роль из users.role.
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $roleName = $stmt->fetchColumn();
    if (!$roleName) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT r.* FROM roles r WHERE r.name = ? LIMIT 1");
    $stmt->execute([(string)$roleName]);
    $role = $stmt->fetch();
    return $role ? [$role] : [];
}

/**
 * GET /api/user-permissions - права пользователя
 */
function handleUserPermissions(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();

    $currentUser = getCurrentUser();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
        exit;
    }
    
    $userId = $_GET['user_id'] ?? null;
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите user_id']);
        exit;
    }

    // Нельзя смотреть чужие права, кроме root/admin (а root — всегда аварийный доступ).
    if ((int)$userId !== (int)$currentUser['id'] && !hasAdminAccess($currentUser)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
        exit;
    }
    
    $permissions = getUserPermissions($userId);
    echo json_encode(['success' => true, 'data' => $permissions]);
}
