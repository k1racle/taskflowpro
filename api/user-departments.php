<?php
/**
 * api/user-departments.php - Управление связями пользователей и отделов
 *
 * Эндпоинты:
 * - GET /api/user-departments - список связей
 * - POST /api/user-departments - добавить пользователя в отдел
 * - DELETE /api/user-departments/:userId/:deptId - удалить пользователя из отдела
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

/**
 * Обработка запросов к /api/user-departments/*
 */
function handleUserDepartments(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/user-departments - список связей
    if ($method === 'GET' && $action === null) {
        $userId = $_GET['user_id'] ?? null;
        
        if ($userId) {
            if ((int)$userId !== (int)$currentUser['id'] && !hasPermission($currentUser, 'users.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
                exit;
            }

            // Получаем отделы конкретного пользователя
            $stmt = $pdo->prepare("
                SELECT ud.*, d.name as department_name
                FROM user_departments ud
                JOIN departments d ON ud.department_id = d.id
                WHERE ud.user_id = ?
            ");
            $stmt->execute([$userId]);
        } else {
            // Получаем все связи (только root и administrator)
            if (hasPermission($currentUser, 'users.view')) {
                $stmt = $pdo->prepare("
                    SELECT ud.*, d.name as department_name, u.full_name as user_name
                    FROM user_departments ud
                    JOIN departments d ON ud.department_id = d.id
                    JOIN users u ON ud.user_id = u.id
                    ORDER BY d.name, u.full_name
                ");
                $stmt->execute();
            } else {
                // Остальные видят только свои связи
                $stmt = $pdo->prepare("
                    SELECT ud.*, d.name as department_name
                    FROM user_departments ud
                    JOIN departments d ON ud.department_id = d.id
                    WHERE ud.user_id = ?
                ");
                $stmt->execute([$currentUser['id']]);
            }
        }
        
        $connections = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $connections]);
        exit;
    }

    // POST /api/user-departments - добавить пользователя в отдел
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['user_id']) || empty($data['department_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите user_id и department_id']);
            exit;
        }

        // Проверка прав
        if (!hasPermission($currentUser, 'users.edit') && (int)$data['user_id'] !== (int)$currentUser['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $stmt = $pdo->prepare(" 
            INSERT INTO user_departments (user_id, department_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([$data['user_id'], $data['department_id']]);

        auditLog($pdo, 'rbac.user_department.added', [
            'actor' => $currentUser,
            'target_type' => 'user_department',
            'target_id' => (string)((int)$data['user_id']) . ':' . (string)((int)$data['department_id']),
            'summary' => 'Пользователь добавлен в отдел',
            'details' => [
                'user_id' => (int)$data['user_id'],
                'department_id' => (int)$data['department_id'],
                'self_service' => (int)$data['user_id'] === (int)$currentUser['id'],
            ],
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Пользователь добавлен в отдел'
        ]);
        exit;
    }

    // DELETE /api/user-departments/:userId/:deptId - удалить пользователя из отдела
    if ($method === 'DELETE' && $action !== null) {
        // Разбираем action как userId/deptId
        $parts = explode('/', $action);
        if (count($parts) !== 2) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверный формат запроса']);
            exit;
        }

        $userId = (int)$parts[0];
        $deptId = (int)$parts[1];

        // Проверка прав
        if (!hasPermission($currentUser, 'users.edit') && $userId !== (int)$currentUser['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = ? AND department_id = ?");
        $stmt->execute([$userId, $deptId]);

        auditLog($pdo, 'rbac.user_department.removed', [
            'actor' => $currentUser,
            'target_type' => 'user_department',
            'target_id' => $userId . ':' . $deptId,
            'summary' => 'Пользователь удалён из отдела',
            'details' => [
                'user_id' => $userId,
                'department_id' => $deptId,
                'self_service' => $userId === (int)$currentUser['id'],
            ],
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Пользователь удалён из отдела'
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
