<?php
/**
 * api/analytics.php - Аналитика для руководителя
 *
 * Эндпоинты:
 * - GET    /api/analytics/overview?period=current&compare=previous
 * - GET    /api/analytics/tasks?period=current&compare=previous
 * - GET    /api/analytics/shifts?period=current&compare=previous
 * - GET    /api/analytics/crm?period=current&compare=previous
 * - GET    /api/analytics/employees?period=current
 *
 * Периоды:
 * - current / previous (месяц)
 * - current_quarter / previous_quarter (квартал)
 * - current_year / previous_year (год)
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

function handleAnalytics(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет доступа']);
        return;
    }

    $period = $_GET['period'] ?? 'current';
    $compare = $_GET['compare'] ?? 'previous';

    // Вычисляем даты
    $dates = analyticsGetDateRanges($period, $compare);

    if ($method === 'GET' && $action === 'overview') {
        analyticsOverview($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'tasks') {
        analyticsTasks($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'shifts') {
        analyticsShifts($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'crm') {
        analyticsCRM($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'employees') {
        analyticsEmployees($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'tasks-by-project') {
        analyticsTasksByProject($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'tasks-by-user') {
        analyticsTasksByUser($pdo, $dates);
        exit;
    }

    if ($method === 'GET' && $action === 'shifts-by-user') {
        analyticsShiftsByUser($pdo, $dates);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Возвращает диапазоны дат для текущего и сравнительного периодов
 */
function analyticsGetDateRanges(string $period, string $compare): array {
    $now = new DateTime('now');
    $result = ['current' => [], 'compare' => []];

    switch ($period) {
        case 'month':
            $result['current'] = analyticsMonthRange($now);
            $result['compare'] = analyticsMonthRange((clone $now)->modify('-1 month'));
            break;

        case 'quarter':
            $result['current'] = analyticsQuarterRange($now);
            $result['compare'] = analyticsQuarterRange((clone $now)->modify('-3 months'));
            break;

        case 'year':
            $result['current'] = analyticsYearRange($now);
            $result['compare'] = analyticsYearRange((clone $now)->modify('-1 year'));
            break;

        case 'week':
            $result['current'] = analyticsWeekRange($now);
            $result['compare'] = analyticsWeekRange((clone $now)->modify('-1 week'));
            break;

        case 'custom':
            $result['current'] = [
                'from' => $_GET['from'] ?? date('Y-m-01'),
                'to' => $_GET['to'] ?? date('Y-m-d'),
            ];
            $result['compare'] = [
                'from' => $_GET['compare_from'] ?? '',
                'to' => $_GET['compare_to'] ?? '',
            ];
            break;

        default:
            $result['current'] = analyticsMonthRange($now);
            $result['compare'] = analyticsMonthRange((clone $now)->modify('-1 month'));
    }

    return $result;
}

function analyticsMonthRange(DateTime $date): array {
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $from = new DateTime("$year-$month-01");
    $to = (clone $from)->modify('last day of this month');
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
}

function analyticsQuarterRange(DateTime $date): array {
    $month = (int)$date->format('m');
    $quarterMonth = (($month - 1) - (($month - 1) % 3) + 1);
    $qDate = new DateTime($date->format('Y') . '-' . str_pad($quarterMonth, 2, '0', STR_PAD_LEFT) . '-01');
    $from = clone $qDate;
    $to = (clone $from)->modify('+3 months')->modify('-1 day');
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
}

function analyticsYearRange(DateTime $date): array {
    $year = $date->format('Y');
    return ['from' => "$year-01-01", 'to' => "$year-12-31"];
}

function analyticsWeekRange(DateTime $date): array {
    $dayOfWeek = (int)$date->format('N'); // 1=Mon, 7=Sun
    $from = (clone $date)->modify('-' . ($dayOfWeek - 1) . ' days');
    $to = (clone $from)->modify('+6 days');
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
}

/**
 * Общий обзор: задачи, смены, CRM — ключевые метрики
 */
function analyticsOverview(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $compare = $dates['compare'];

    $result = [
        'current' => analyticsCalcOverview($pdo, $current),
        'compare' => $compare['from'] ? analyticsCalcOverview($pdo, $compare) : null,
    ];

    echo json_encode(['success' => true, 'data' => $result]);
}

function analyticsCalcOverview(PDO $pdo, array $range): array {
    $from = $range['from'];
    $to = $range['to'];

    // Задачи — считаем ВСЕ задачи, но завершённые — те что закрыты в периоде
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_created,
            SUM(CASE WHEN status = 'Готово' THEN 1 ELSE 0 END) as total_completed,
            SUM(CASE WHEN status != 'Готово' AND status != 'Отменена' THEN 1 ELSE 0 END) as total_active,
            SUM(CASE WHEN deadline IS NOT NULL AND deadline < CURDATE() AND status != 'Готово' AND status != 'Отменена' THEN 1 ELSE 0 END) as total_burning
        FROM tasks
    ");
    $stmt->execute();
    $tasks = $stmt->fetch();

    // Смены
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT user_id) as unique_workers,
            COUNT(*) as total_sessions,
            COALESCE(SUM(worked_seconds), 0) as total_worked_seconds
        FROM shift_sessions
        WHERE started_at >= ? AND started_at <= ?
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $shifts = $stmt->fetch();

    // CRM — все клиенты и сделки
    try {
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM crm_clients) as new_clients,
                (SELECT COUNT(*) FROM crm_deals) as new_deals,
                (SELECT COALESCE(SUM(amount),0) FROM crm_deals) as deals_sum
        ");
        $stmt->execute([]);
        $crm = $stmt->fetch();
    } catch (Throwable $e) {
        $crm = ['new_clients' => 0, 'new_deals' => 0, 'deals_sum' => 0];
    }

    return [
        'tasks_created' => (int)$tasks['total_created'],
        'tasks_completed' => (int)$tasks['total_completed'],
        'tasks_active' => (int)$tasks['total_active'],
        'tasks_burning' => (int)$tasks['total_burning'],
        'unique_workers' => (int)$shifts['unique_workers'],
        'total_sessions' => (int)$shifts['total_sessions'],
        'total_worked_hours' => round(((int)$shifts['total_worked_seconds']) / 3600, 1),
        'new_clients' => (int)$crm['new_clients'],
        'new_deals' => (int)$crm['new_deals'],
        'deals_sum' => (float)$crm['deals_sum'],
    ];
}

/**
 * Аналитика по задачам: создание/завершение по дням, по статусам, по проектам
 */
function analyticsTasks(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $compare = $dates['compare'];

    // Задачи по дням (создание и завершение)
    $daily = analyticsTasksDaily($pdo, $current);
    $dailyCompare = $compare['from'] ? analyticsTasksDaily($pdo, $compare) : [];

    // По статусам
    $byStatus = analyticsTasksByStatus($pdo, $current);

    // По приоритетам
    $byPriority = analyticsTasksByPriority($pdo, $current);

    echo json_encode(['success' => true, 'data' => [
        'daily' => $daily,
        'daily_compare' => $dailyCompare,
        'by_status' => $byStatus,
        'by_priority' => $byPriority,
    ]]);
}

function analyticsTasksDaily(PDO $pdo, array $range): array {
    $stmt = $pdo->prepare("
        SELECT
            DATE(created_at) as day,
            COUNT(*) as created,
            (SELECT COUNT(*) FROM tasks t2 WHERE DATE(t2.updated_at) = DATE(t1.created_at) AND t2.status = 'Готово') as completed
        FROM tasks t1
        WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $stmt->execute([$range['from'], $range['to']]);
    return $stmt->fetchAll();
}

function analyticsTasksByStatus(PDO $pdo, array $range): array {
    // ВСЕ задачи, не только созданные в периоде
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as cnt
        FROM tasks
        GROUP BY status
        ORDER BY cnt DESC
    ");
    $stmt->execute([]);
    return $stmt->fetchAll();
}

function analyticsTasksByPriority(PDO $pdo, array $range): array {
    // ВСЕ задачи
    $stmt = $pdo->prepare("
        SELECT priority, COUNT(*) as cnt
        FROM tasks
        GROUP BY priority
        ORDER BY cnt DESC
    ");
    $stmt->execute([]);
    return $stmt->fetchAll();
}

/**
 * Аналитика по сменам: часы по дням, нарушения
 */
function analyticsShifts(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $compare = $dates['compare'];

    // Часы работы по дням
    $dailyHours = analyticsShiftsDailyHours($pdo, $current);
    $dailyHoursCompare = $compare['from'] ? analyticsShiftsDailyHours($pdo, $compare) : [];

    // Статистика смен
    $stats = analyticsShiftsStats($pdo, $current);
    $statsCompare = $compare['from'] ? analyticsShiftsStats($pdo, $compare) : null;

    // Нарушения (кто не вышел по графику)
    $violations = analyticsShiftsViolations($pdo, $current);

    echo json_encode(['success' => true, 'data' => [
        'daily_hours' => $dailyHours,
        'daily_hours_compare' => $dailyHoursCompare,
        'stats' => $stats,
        'stats_compare' => $statsCompare,
        'violations' => $violations,
    ]]);
}

function analyticsShiftsDailyHours(PDO $pdo, array $range): array {
    $stmt = $pdo->prepare("
        SELECT
            DATE(started_at) as day,
            ROUND(SUM(worked_seconds) / 3600, 1) as total_hours,
            COUNT(*) as sessions_count
        FROM shift_sessions
        WHERE started_at >= ? AND started_at <= ? AND ended_at IS NOT NULL
        GROUP BY DATE(started_at)
        ORDER BY day ASC
    ");
    $stmt->execute(["{$range['from']} 00:00:00", "{$range['to']} 23:59:59"]);
    return $stmt->fetchAll();
}

function analyticsShiftsStats(PDO $pdo, array $range): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_sessions,
            COUNT(DISTINCT user_id) as unique_workers,
            ROUND(SUM(worked_seconds) / 3600, 1) as total_hours,
            ROUND(AVG(worked_seconds) / 3600, 1) as avg_hours_per_session,
            SUM(CASE WHEN status = 'break' THEN 1 ELSE 0 END) as breaks_count
        FROM shift_sessions
        WHERE started_at >= ? AND started_at <= ?
    ");
    $stmt->execute(["{$range['from']} 00:00:00", "{$range['to']} 23:59:59"]);
    $row = $stmt->fetch();

    // Среднее время на одного сотрудника
    $uniqueWorkers = (int)$row['unique_workers'];
    $avgHoursPerWorker = $uniqueWorkers > 0 ? round(((float)$row['total_hours']) / $uniqueWorkers, 1) : 0;

    return [
        'total_sessions' => (int)$row['total_sessions'],
        'unique_workers' => $uniqueWorkers,
        'total_hours' => (float)$row['total_hours'],
        'avg_hours_per_session' => (float)$row['avg_hours_per_session'],
        'avg_hours_per_worker' => $avgHoursPerWorker,
    ];
}

function analyticsShiftsViolations(PDO $pdo, array $range): array {
    // Сравниваем work_schedules с shift_sessions
    $stmt = $pdo->prepare("
        SELECT
            ws.schedule_date,
            COUNT(*) as scheduled,
            COUNT(DISTINCT ss.user_id) as actually_worked
        FROM work_schedules ws
        LEFT JOIN shift_sessions ss ON ss.user_id = ws.user_id
            AND DATE(ss.started_at) = ws.schedule_date
            AND ss.ended_at IS NOT NULL
        WHERE ws.schedule_date >= ? AND ws.schedule_date <= ?
            AND ws.is_day_off = 0
        GROUP BY ws.schedule_date
        ORDER BY ws.schedule_date ASC
    ");
    $stmt->execute([$range['from'], $range['to']]);
    $rows = $stmt->fetchAll();

    $violations = [];
    foreach ($rows as $row) {
        $scheduled = (int)$row['scheduled'];
        $worked = (int)$row['actually_worked'];
        if ($worked < $scheduled) {
            $violations[] = [
                'date' => $row['schedule_date'],
                'scheduled' => $scheduled,
                'worked' => $worked,
                'missed' => $scheduled - $worked,
            ];
        }
    }

    return $violations;
}

/**
 * CRM аналитика
 */
function analyticsCRM(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $compare = $dates['compare'];

    $currentData = analyticsCRMSnapshot($pdo, $current);
    $compareData = $compare['from'] ? analyticsCRMSnapshot($pdo, $compare) : null;

    // Воронка по этапам
    try {
        $funnel = $pdo->query("
            SELECT s.name, COUNT(d.id) as cnt, COALESCE(SUM(d.amount), 0) as total_amount
            FROM crm_pipeline_stages s
            LEFT JOIN crm_deals d ON d.stage_id = s.id
            GROUP BY s.id, s.name
            ORDER BY s.sort_order ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $funnel = [];
    }

    echo json_encode(['success' => true, 'data' => [
        'current' => $currentData,
        'compare' => $compareData,
        'funnel' => $funnel,
    ]]);
}

function analyticsCRMSnapshot(PDO $pdo, array $range): array {
    try {
        // ВСЕ данные CRM, без фильтра по дате
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM crm_clients) as new_clients,
                (SELECT COUNT(*) FROM crm_deals) as new_deals,
                (SELECT COALESCE(SUM(amount),0) FROM crm_deals) as deals_sum,
                (SELECT COUNT(*) FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE s.is_won=1) as won_deals,
                (SELECT COUNT(*) FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE s.is_lost=1) as lost_deals
        ");
        $stmt->execute([]);
        $row = $stmt->fetch();

        $won = (int)$row['won_deals'];
        $lost = (int)$row['lost_deals'];
        $closed = $won + $lost;
        $winRate = $closed > 0 ? round(($won / $closed) * 100, 1) : 0;

        return [
            'new_clients' => (int)$row['new_clients'],
            'new_deals' => (int)$row['new_deals'],
            'deals_sum' => (float)$row['deals_sum'],
            'won_deals' => $won,
            'lost_deals' => $lost,
            'win_rate' => $winRate,
        ];
    } catch (Throwable $e) {
        return ['new_clients' => 0, 'new_deals' => 0, 'deals_sum' => 0, 'won_deals' => 0, 'lost_deals' => 0, 'win_rate' => 0];
    }
}

/**
 * Аналитика по сотрудникам
 */
function analyticsEmployees(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $from = $current['from'];
    $to = $current['to'];

    // Задачи по сотрудникам — ВСЕ задачи, не только созданные в периоде
    $stmt = $pdo->prepare("
        SELECT
            u.id, u.full_name, u.avatar,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN t.status != 'Готово' AND t.status != 'Отменена' THEN 1 ELSE 0 END) as active_tasks
        FROM users u
        LEFT JOIN tasks t ON t.assigned_to = u.id
        WHERE u.role != 'root'
        GROUP BY u.id, u.full_name, u.avatar
        ORDER BY completed_tasks DESC
    ");
    $stmt->execute([]);
    $taskStats = $stmt->fetchAll();

    // Часы работы по сотрудникам — за выбранный период
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            COUNT(ss.id) as sessions_count,
            ROUND(COALESCE(SUM(ss.worked_seconds), 0) / 3600, 1) as total_hours
        FROM users u
        LEFT JOIN shift_sessions ss ON ss.user_id = u.id AND ss.started_at >= ? AND ss.started_at <= ?
        WHERE u.role != 'root'
        GROUP BY u.id
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);
    $shiftStats = [];
    foreach ($stmt->fetchAll() as $row) {
        $shiftStats[$row['id']] = $row;
    }

    // Объединяем
    $employees = [];
    foreach ($taskStats as $ts) {
        $uid = $ts['id'];
        $completed = (int)$ts['completed_tasks'];
        $total = (int)$ts['total_tasks'];
        $efficiency = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $employees[] = [
            'id' => $uid,
            'full_name' => $ts['full_name'],
            'avatar' => $ts['avatar'],
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'active_tasks' => (int)$ts['active_tasks'],
            'efficiency' => $efficiency,
            'shift_sessions' => isset($shiftStats[$uid]) ? (int)$shiftStats[$uid]['sessions_count'] : 0,
            'shift_hours' => isset($shiftStats[$uid]) ? (float)$shiftStats[$uid]['total_hours'] : 0,
        ];
    }

    echo json_encode(['success' => true, 'data' => $employees]);
}

/**
 * Задачи по проектам
 */
function analyticsTasksByProject(PDO $pdo, array $dates): void {
    // ВСЕ задачи по проектам
    $stmt = $pdo->prepare("
        SELECT
            p.name as project_name,
            COUNT(t.id) as total,
            SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN t.status != 'Готово' AND t.status != 'Отменена' THEN 1 ELSE 0 END) as active
        FROM projects p
        LEFT JOIN tasks t ON t.project_id = p.id
        GROUP BY p.id, p.name
        ORDER BY total DESC
    ");
    $stmt->execute([]);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

/**
 * Задачи по пользователям
 */
function analyticsTasksByUser(PDO $pdo, array $dates): void {
    // ВСЕ задачи по пользователям
    $stmt = $pdo->prepare("
        SELECT
            u.full_name,
            COUNT(t.id) as total,
            SUM(CASE WHEN t.status = 'Готово' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN t.status != 'Готово' AND t.status != 'Отменена' THEN 1 ELSE 0 END) as active
        FROM users u
        LEFT JOIN tasks t ON t.assigned_to = u.id
        GROUP BY u.id, u.full_name
        ORDER BY total DESC
    ");
    $stmt->execute([]);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

/**
 * Смены по пользователям (для графика часов)
 */
function analyticsShiftsByUser(PDO $pdo, array $dates): void {
    $current = $dates['current'];
    $from = $current['from'];
    $to = $current['to'];

    $stmt = $pdo->prepare("
        SELECT
            u.full_name,
            DATE(ss.started_at) as day,
            ROUND(SUM(ss.worked_seconds) / 3600, 1) as hours
        FROM shift_sessions ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.started_at >= ? AND ss.started_at <= ? AND ss.ended_at IS NOT NULL
        GROUP BY u.id, DATE(ss.started_at)
        ORDER BY day ASC
    ");
    $stmt->execute(["$from 00:00:00", "$to 23:59:59"]);

    // Группируем по пользователю
    $byUser = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = $row['full_name'];
        if (!isset($byUser[$name])) {
            $byUser[$name] = [];
        }
        $byUser[$name][] = ['day' => $row['day'], 'hours' => (float)$row['hours']];
    }

    echo json_encode(['success' => true, 'data' => $byUser]);
}
