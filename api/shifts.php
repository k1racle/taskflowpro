<?php
/**
 * api/shifts.php - Смены / учет времени
 *
 * Эндпоинты:
 * - GET    /api/shifts/me/today
 * - GET    /api/shifts/me/history
 * - POST   /api/shifts/me/start
 * - POST   /api/shifts/me/break-start
 * - POST   /api/shifts/me/break-end
 * - POST   /api/shifts/me/end
 * - POST   /api/shifts/me/note
 *
 * Руководитель:
 * - GET    /api/shifts/overview
 * - GET    /api/shifts/export?type=overview|sessions
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

function handleShifts(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $action = $action ?? '';
    $id = $id ?? '';

    // ===== Me =====
    if ($action === 'me') {
        if ($method === 'GET' && $id === 'today') {
            shiftsGetMyToday($pdo, (int)$currentUser['id']);
            exit;
        }
        if ($method === 'GET' && $id === 'history') {
            shiftsGetMyHistory($pdo, (int)$currentUser['id']);
            exit;
        }

        if ($method === 'POST' && $id === 'start') {
            shiftsStart($pdo, $currentUser);
            exit;
        }
        if ($method === 'POST' && $id === 'break-start') {
            shiftsBreakStart($pdo, $currentUser);
            exit;
        }
        if ($method === 'POST' && $id === 'break-end') {
            shiftsBreakEnd($pdo, $currentUser);
            exit;
        }
        if ($method === 'POST' && $id === 'end') {
            shiftsEnd($pdo, $currentUser);
            exit;
        }
        if ($method === 'POST' && $id === 'note') {
            $data = json_decode(file_get_contents('php://input'), true);
            shiftsAddNote($pdo, $currentUser, $data);
            exit;
        }
    }

    // ===== Overview (manager) =====
    if ($method === 'GET' && $action === 'overview') {
        shiftsOverview($pdo, $currentUser);
        exit;
    }

    if ($method === 'GET' && $action === 'export') {
        $type = (string)($_GET['type'] ?? 'overview');
        if ($type === 'sessions') {
            shiftsExportSessionsCsv($pdo, $currentUser);
        } else {
            shiftsExportOverviewCsv($pdo, $currentUser);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

function shiftsGetOpenSession(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $s = $stmt->fetch();
    return $s ?: null;
}

function shiftsNow(): string {
    return (new DateTime('now'))->format('Y-m-d H:i:s');
}

function shiftsSecondsBetween(string $from, string $to): int {
    $a = new DateTime($from);
    $b = new DateTime($to);
    $diff = $b->getTimestamp() - $a->getTimestamp();
    return max(0, (int)$diff);
}

function shiftsGetActiveBreakStartedAt(PDO $pdo, int $sessionId): ?string {
    $stmt = $pdo->prepare(
        "SELECT occurred_at FROM shift_events WHERE session_id=? AND type='break_start' ORDER BY occurred_at DESC LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $lastBreakStart = $stmt->fetchColumn();
    if (!$lastBreakStart) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT occurred_at FROM shift_events WHERE session_id=? AND type='break_end' AND occurred_at >= ? ORDER BY occurred_at DESC LIMIT 1"
    );
    $stmt->execute([$sessionId, $lastBreakStart]);
    $lastBreakEnd = $stmt->fetchColumn();

    return $lastBreakEnd ? null : (string)$lastBreakStart;
}

function shiftsStart(PDO $pdo, array $currentUser): void {
    $open = shiftsGetOpenSession($pdo, (int)$currentUser['id']);
    if ($open) {
        echo json_encode(['success' => true, 'data' => ['session' => $open]]);
        return;
    }
    $now = shiftsNow();
    $stmt = $pdo->prepare("INSERT INTO shift_sessions (user_id, started_at, status) VALUES (?, ?, 'working')");
    $stmt->execute([(int)$currentUser['id'], $now]);
    $sid = (int)$pdo->lastInsertId();
    shiftsLogEvent($pdo, $sid, (int)$currentUser['id'], 'start', $now, null);
    echo json_encode(['success' => true, 'data' => ['session_id' => $sid]]);
}

function shiftsBreakStart(PDO $pdo, array $currentUser): void {
    $open = shiftsGetOpenSession($pdo, (int)$currentUser['id']);
    if (!$open) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Смена не начата']);
        return;
    }
    if ($open['status'] === 'break') {
        echo json_encode(['success' => true]);
        return;
    }
    $now = shiftsNow();
    $pdo->prepare("UPDATE shift_sessions SET status='break' WHERE id=?")->execute([(int)$open['id']]);
    shiftsLogEvent($pdo, (int)$open['id'], (int)$currentUser['id'], 'break_start', $now, null);
    echo json_encode(['success' => true]);
}

function shiftsBreakEnd(PDO $pdo, array $currentUser): void {
    $open = shiftsGetOpenSession($pdo, (int)$currentUser['id']);
    if (!$open) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Смена не начата']);
        return;
    }

    // Find last break_start without break_end
    $breakStartedAt = shiftsGetActiveBreakStartedAt($pdo, (int)$open['id']);
    $now = shiftsNow();
    $add = $breakStartedAt ? shiftsSecondsBetween($breakStartedAt, $now) : 0;

    $pdo->prepare("UPDATE shift_sessions SET status='working', break_seconds=break_seconds+? WHERE id=?")->execute([$add, (int)$open['id']]);
    shiftsLogEvent($pdo, (int)$open['id'], (int)$currentUser['id'], 'break_end', $now, ['added_seconds' => $add]);
    echo json_encode(['success' => true, 'data' => ['added_break_seconds' => $add]]);
}

function shiftsEnd(PDO $pdo, array $currentUser): void {
    $open = shiftsGetOpenSession($pdo, (int)$currentUser['id']);
    if (!$open) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Смена не начата']);
        return;
    }
    $now = shiftsNow();

    $breakSeconds = (int)($open['break_seconds'] ?? 0);
    if (($open['status'] ?? '') === 'break') {
        $breakStartedAt = shiftsGetActiveBreakStartedAt($pdo, (int)$open['id']);
        if ($breakStartedAt) {
            $breakSeconds += shiftsSecondsBetween($breakStartedAt, $now);
        }
    }

    // Total duration - break_seconds
    $total = shiftsSecondsBetween((string)$open['started_at'], $now);
    $workedSeconds = max(0, $total - $breakSeconds);

    $pdo->prepare("UPDATE shift_sessions SET ended_at=?, status='ended', break_seconds=?, worked_seconds=? WHERE id=?")
        ->execute([$now, $breakSeconds, $workedSeconds, (int)$open['id']]);
    shiftsLogEvent($pdo, (int)$open['id'], (int)$currentUser['id'], 'end', $now, ['worked_seconds' => $workedSeconds, 'break_seconds' => $breakSeconds]);
    echo json_encode(['success' => true, 'data' => ['worked_seconds' => $workedSeconds]]);
}

function shiftsAddNote(PDO $pdo, array $currentUser, ?array $data): void {
    $data = is_array($data) ? $data : [];
    $note = trim((string)($data['note'] ?? ''));
    if ($note === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Пустая заметка']);
        return;
    }
    $open = shiftsGetOpenSession($pdo, (int)$currentUser['id']);
    if (!$open) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Смена не начата']);
        return;
    }
    $now = shiftsNow();
    $pdo->prepare("UPDATE shift_sessions SET note=? WHERE id=?")->execute([$note, (int)$open['id']]);
    shiftsLogEvent($pdo, (int)$open['id'], (int)$currentUser['id'], 'note', $now, ['note' => $note]);
    echo json_encode(['success' => true]);
}

function shiftsGetMyToday(PDO $pdo, int $userId): void {
    $today = (new DateTime('today'))->format('Y-m-d 00:00:00');
    $tomorrow = (new DateTime('tomorrow'))->format('Y-m-d 00:00:00');

    $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE user_id=? AND started_at >= ? AND started_at < ? ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$userId, $today, $tomorrow]);
    $session = $stmt->fetch();

    $open = shiftsGetOpenSession($pdo, $userId);
    if ($open && ($open['status'] ?? '') === 'break') {
        $open['current_break_started_at'] = shiftsGetActiveBreakStartedAt($pdo, (int)$open['id']);
    }

    echo json_encode(['success' => true, 'data' => ['today' => $session ?: null, 'open' => $open ?: null]]);
}

function shiftsGetMyHistory(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("SELECT * FROM shift_sessions WHERE user_id=? ORDER BY started_at DESC LIMIT 60");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function shiftsOverview(PDO $pdo, array $currentUser): void {
    // Keep backward compatibility: allow leaders/managers to view shifts overview.
    // Older builds call this for "leader dashboard".
    if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет доступа']);
        return;
    }

    $open = $pdo->query("SELECT s.*, u.full_name, u.avatar, u.department_id FROM shift_sessions s JOIN users u ON u.id=s.user_id WHERE s.ended_at IS NULL ORDER BY s.started_at ASC")->fetchAll();

    $burningTasks = $pdo->query("SELECT t.*, u.full_name as assignee_name, p.name as project_name FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN projects p ON p.id=t.project_id WHERE t.status <> 'Готово' AND t.deadline IS NOT NULL AND t.deadline < CURDATE() ORDER BY t.deadline ASC LIMIT 50")->fetchAll();

    $workingTasks = $pdo->query("SELECT t.*, u.full_name as assignee_name, p.name as project_name FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN projects p ON p.id=t.project_id WHERE t.status <> 'Готово' ORDER BY t.updated_at DESC LIMIT 50")->fetchAll();

    $projects = $pdo->query("SELECT p.*, d.name as department_name FROM projects p LEFT JOIN departments d ON d.id=p.department_id ORDER BY p.created_at DESC LIMIT 50")->fetchAll();

    // CRM dashboard snapshot
    try {
        $crm = [];
        $clients = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_clients")->fetch()['c'];
        $deals = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals")->fetch()['c'];
        $sum = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) as s FROM crm_deals")->fetch()['s'];
        $won = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE s.is_won=1")->fetch()['c'];
        $lost = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE s.is_lost=1")->fetch()['c'];
        $closed = $won + $lost;
        $winRate = $closed > 0 ? round(($won / $closed) * 100, 1) : 0;
        $crm = ['clients' => $clients, 'deals' => $deals, 'pipeline_sum' => $sum, 'win_rate' => $winRate];
    } catch (Throwable $e) {
        $crm = ['clients' => 0, 'deals' => 0, 'pipeline_sum' => 0, 'win_rate' => 0];
    }

    echo json_encode(['success' => true, 'data' => [
        'on_shift' => $open,
        'tasks_burning' => $burningTasks,
        'tasks_working' => $workingTasks,
        'projects' => $projects,
        'crm' => $crm,
    ]]);
}

function shiftsExportOverviewCsv(PDO $pdo, array $currentUser): void {
    if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.export') && !hasPermission($currentUser, 'leader.view')) {
        http_response_code(403);
        echo 'no access';
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shift_overview.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['session_id', 'user_id', 'full_name', 'status', 'started_at', 'break_seconds']);
    $rows = $pdo->query("SELECT s.id as session_id, s.user_id, u.full_name, s.status, s.started_at, s.break_seconds FROM shift_sessions s JOIN users u ON u.id=s.user_id WHERE s.ended_at IS NULL ORDER BY s.started_at ASC")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['session_id'], $r['user_id'], $r['full_name'], $r['status'], $r['started_at'], $r['break_seconds']]);
    }
    fclose($out);
}

function shiftsExportSessionsCsv(PDO $pdo, array $currentUser): void {
    if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.export') && !hasPermission($currentUser, 'leader.view')) {
        http_response_code(403);
        echo 'no access';
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shift_sessions.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['id', 'user_id', 'full_name', 'started_at', 'ended_at', 'status', 'worked_seconds', 'break_seconds', 'note']);
    $rows = $pdo->query("SELECT s.*, u.full_name FROM shift_sessions s JOIN users u ON u.id=s.user_id ORDER BY s.started_at DESC LIMIT 1000")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['user_id'], $r['full_name'], $r['started_at'], $r['ended_at'], $r['status'], $r['worked_seconds'], $r['break_seconds'], $r['note']]);
    }
    fclose($out);
}

function shiftsLogEvent(PDO $pdo, int $sessionId, int $userId, string $type, string $occurredAt, ?array $meta): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO shift_events (session_id, user_id, type, occurred_at, meta) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $userId, $type, $occurredAt, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) {
        // ignore
    }
}
