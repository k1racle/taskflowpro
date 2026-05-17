<?php
/**
 * api/work-schedules.php - Графики работы сотрудников
 *
 * Endpoints:
 * - GET    /api/work-schedules              - Список графиков (фильтры: user_id, date_from, date_to)
 * - POST   /api/work-schedules              - Создать/обновить график
 * - GET    /api/work-schedules/:id          - Получить график по ID
 * - DELETE /api/work-schedules/:id          - Удалить график
 * - POST   /api/work-schedules/bulk         - Массовое создание графиков
 * - GET    /api/work-schedules/user/:id     - Графики конкретного пользователя
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

function handleWorkSchedules(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $userId = $currentUser['id'];
    $isManager = hasPermission($currentUser, 'admin.full')
        || hasPermission($currentUser, 'leader.view')
        || hasPermission($currentUser, 'leader.shifts.manage')
        || in_array(($currentUser['role'] ?? null), ['manager'], true);

    $readJsonBody = function(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    };

    // ============================================
    // GET /api/work-schedules - Список графиков
    // ============================================
    if ($method === 'GET' && $action === null) {
        $filters = [];
        $params = [];

        // Фильтр по пользователю
        if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $filters[] = "ws.user_id = ?";
            $params[] = (int)$_GET['user_id'];
        }

        // Фильтр по дате
        if (isset($_GET['date_from'])) {
            $filters[] = "ws.schedule_date >= ?";
            $params[] = $_GET['date_from'];
        }
        if (isset($_GET['date_to'])) {
            $filters[] = "ws.schedule_date <= ?";
            $params[] = $_GET['date_to'];
        }

        // Не-менеджеры видят только свои графики
        if (!$isManager && empty($_GET['user_id'])) {
            $filters[] = "ws.user_id = ?";
            $params[] = $userId;
        }

        $whereClause = empty($filters) ? '' : 'WHERE ' . implode(' AND ', $filters);

        $stmt = $pdo->prepare("
            SELECT 
                ws.*,
                u.full_name as user_name,
                u.avatar as user_avatar,
                cu.full_name as created_by_name
            FROM work_schedules ws
            JOIN users u ON ws.user_id = u.id
            LEFT JOIN users cu ON ws.created_by = cu.id
            $whereClause
            ORDER BY ws.schedule_date DESC, u.full_name ASC
        ");
        $stmt->execute($params);
        $schedules = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $schedules]);
        exit;
    }

    // ============================================
    // GET /api/work-schedules/user/:id - Графики пользователя
    // ============================================
    if ($method === 'GET' && $action === 'user' && is_numeric($id)) {
        $targetUserId = (int)$id;

        // Проверка прав
        if (!$isManager && $targetUserId !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT 
                ws.*,
                u.full_name as user_name,
                u.avatar as user_avatar
            FROM work_schedules ws
            JOIN users u ON ws.user_id = u.id
            WHERE ws.user_id = ?
            ORDER BY ws.schedule_date DESC
        ");
        $stmt->execute([$targetUserId]);
        $schedules = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $schedules]);
        exit;
    }

    // ============================================
    // POST /api/work-schedules - Создать/обновить график
    // ============================================
    if ($method === 'POST' && $action === null) {
        $data = $readJsonBody();

        // Проверка прав
        if (!$isManager) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только менеджеры могут создавать графики']);
            exit;
        }

        if (empty($data['user_id']) || empty($data['schedule_date'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите user_id и schedule_date']);
            exit;
        }

        $scheduleDate = $data['schedule_date'];
        $shiftStart = isset($data['shift_start']) ? $data['shift_start'] : null;
        $shiftEnd = isset($data['shift_end']) ? $data['shift_end'] : null;
        $breakStart = isset($data['break_start']) ? $data['break_start'] : null;
        $breakEnd = isset($data['break_end']) ? $data['break_end'] : null;
        $isDayOff = isset($data['is_day_off']) ? (int)$data['is_day_off'] : 0;
        $note = $data['note'] ?? null;

        // Проверяем существующий график на эту дату
        $stmt = $pdo->prepare("SELECT id FROM work_schedules WHERE user_id = ? AND schedule_date = ?");
        $stmt->execute([$data['user_id'], $scheduleDate]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Обновляем
            $stmt = $pdo->prepare("
                UPDATE work_schedules SET
                    shift_start = ?,
                    shift_end = ?,
                    break_start = ?,
                    break_end = ?,
                    is_day_off = ?,
                    note = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $shiftStart,
                $shiftEnd,
                $breakStart,
                $breakEnd,
                $isDayOff,
                $note,
                $existing['id']
            ]);
            $scheduleId = $existing['id'];
            $message = 'График обновлен';
        } else {
            // Создаём
            $stmt = $pdo->prepare("
                INSERT INTO work_schedules 
                (user_id, created_by, schedule_date, shift_start, shift_end, break_start, break_end, is_day_off, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['user_id'],
                $userId,
                $scheduleDate,
                $shiftStart,
                $shiftEnd,
                $breakStart,
                $breakEnd,
                $isDayOff,
                $note
            ]);
            $scheduleId = $pdo->lastInsertId();
            $message = 'График создан';
        }

        echo json_encode([
            'success' => true,
            'data' => ['id' => $scheduleId, 'message' => $message]
        ]);
        exit;
    }

    // ============================================
    // POST /api/work-schedules/bulk - Массовое создание
    // ============================================
    if ($method === 'POST' && $action === 'bulk') {
        $data = $readJsonBody();

        if (!$isManager) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только менеджеры могут создавать графики']);
            exit;
        }

        if (empty($data['schedules']) || !is_array($data['schedules'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите schedules массив']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO work_schedules 
            (user_id, created_by, schedule_date, shift_start, shift_end, break_start, break_end, is_day_off, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                shift_start = VALUES(shift_start),
                shift_end = VALUES(shift_end),
                break_start = VALUES(break_start),
                break_end = VALUES(break_end),
                is_day_off = VALUES(is_day_off),
                note = VALUES(note)
        ");

        $created = 0;
        $updated = 0;

        foreach ($data['schedules'] as $schedule) {
            if (empty($schedule['user_id']) || empty($schedule['schedule_date'])) {
                continue;
            }

            // Проверяем существует ли
            $checkStmt = $pdo->prepare("SELECT id FROM work_schedules WHERE user_id = ? AND schedule_date = ?");
            $checkStmt->execute([$schedule['user_id'], $schedule['schedule_date']]);
            $exists = $checkStmt->fetch();

            $stmt->execute([
                $schedule['user_id'],
                $userId,
                $schedule['schedule_date'],
                $schedule['shift_start'] ?? null,
                $schedule['shift_end'] ?? null,
                $schedule['break_start'] ?? null,
                $schedule['break_end'] ?? null,
                $schedule['is_day_off'] ?? 0,
                $schedule['note'] ?? null
            ]);

            if ($exists) {
                $updated++;
            } else {
                $created++;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => ['created' => $created, 'updated' => $updated]
        ]);
        exit;
    }

    // ============================================
    // DELETE /api/work-schedules/:id - Удалить график
    // ============================================
    if ($method === 'DELETE' && is_numeric($id)) {
        $scheduleId = (int)$id;

        // Проверяем права
        $stmt = $pdo->prepare("SELECT user_id FROM work_schedules WHERE id = ?");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'График не найден']);
            exit;
        }

        if (!$isManager && $schedule['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM work_schedules WHERE id = ?");
        $stmt->execute([$scheduleId]);

        echo json_encode(['success' => true, 'message' => 'График удален']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
