<?php

require_once __DIR__ . '/migrations.php';

function appAuditTableExists(PDO $pdo): bool {
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    try {
        $checked = appTableExists($pdo, 'audit_log');
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}

function appAuditRequestPath(): string {
    $path = $_SERVER['REQUEST_URI'] ?? ($_SERVER['PATH_INFO'] ?? '');
    return substr((string)$path, 0, 255);
}

function appAuditIpAddress(): ?string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : null;
}

function appAuditUserAgent(): ?string {
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $userAgent !== '' ? substr($userAgent, 0, 500) : null;
}

function appAuditNormalizeScalar($value) {
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
    }

    return (string)$value;
}

function appAuditSanitizeArray(array $value): array {
    $result = [];
    foreach ($value as $key => $item) {
        $safeKey = is_int($key) ? $key : (string)$key;
        if (is_array($item)) {
            $result[$safeKey] = appAuditSanitizeArray($item);
            continue;
        }

        $result[$safeKey] = appAuditNormalizeScalar($item);
    }

    return $result;
}

function appAuditActorFromUser(?array $user): array {
    if (!$user) {
        return [
            'id' => null,
            'login' => null,
            'role' => null,
        ];
    }

    return [
        'id' => isset($user['id']) ? (int)$user['id'] : null,
        'login' => isset($user['login']) ? substr((string)$user['login'], 0, 190) : null,
        'role' => isset($user['role']) ? substr((string)$user['role'], 0, 100) : null,
    ];
}

function appAuditThrottleTableExists(PDO $pdo): bool {
    static $checked = null;

    if ($checked !== null) {
        return $checked;
    }

    try {
        $checked = appTableExists($pdo, 'auth_login_throttle');
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}

function appAuditClampLimit(mixed $value, int $default = 50, int $max = 200): int {
    $limit = is_numeric($value) ? (int)$value : $default;
    return max(1, min($limit, $max));
}

function appAuditRetentionDays(): int {
    return defined('AUDIT_RETENTION_DAYS') ? max(1, (int)AUDIT_RETENTION_DAYS) : 180;
}

function appAuditBuildRetentionCutoff(?int $retentionDays = null): string {
    $days = $retentionDays !== null ? max(1, $retentionDays) : appAuditRetentionDays();
    return date('Y-m-d H:i:s', time() - ($days * 86400));
}

function appAuditNormalizeFilterString(mixed $value, int $maxLength = 190): ?string {
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    return substr($value, 0, $maxLength);
}

function appAuditParseDateFilter(mixed $value, bool $endOfDay = false): ?string {
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        $timestamp += 86399;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function appAuditBuildRecentFilters(): array {
    $filters = [];

    $eventType = appAuditNormalizeFilterString($_GET['event_type'] ?? null, 100);
    if ($eventType !== null) {
        $filters['event_type'] = $eventType;
    }

    $actorLogin = appAuditNormalizeFilterString($_GET['actor_login'] ?? ($_GET['actor'] ?? null));
    if ($actorLogin !== null) {
        $filters['actor_login'] = $actorLogin;
    }

    $login = appAuditNormalizeFilterString($_GET['login'] ?? null);
    if ($login !== null) {
        $filters['login'] = $login;
    }

    $targetType = appAuditNormalizeFilterString($_GET['target_type'] ?? null, 100);
    if ($targetType !== null) {
        $filters['target_type'] = $targetType;
    }

    $targetId = appAuditNormalizeFilterString($_GET['target_id'] ?? null, 100);
    if ($targetId !== null) {
        $filters['target_id'] = $targetId;
    }

    $ipAddress = appAuditNormalizeFilterString($_GET['ip_address'] ?? null, 64);
    if ($ipAddress !== null) {
        $filters['ip_address'] = $ipAddress;
    }

    $createdFrom = appAuditParseDateFilter($_GET['created_from'] ?? ($_GET['from'] ?? null), false);
    if ($createdFrom !== null) {
        $filters['created_from'] = $createdFrom;
    }

    $createdTo = appAuditParseDateFilter($_GET['created_to'] ?? ($_GET['to'] ?? null), true);
    if ($createdTo !== null) {
        $filters['created_to'] = $createdTo;
    }

    return $filters;
}

function appAuditBuildRecentQuery(array $filters): array {
    $where = [];
    $params = [];

    if (isset($filters['event_type'])) {
        $where[] = 'event_type = ?';
        $params[] = $filters['event_type'];
    }

    if (isset($filters['actor_login'])) {
        $where[] = 'actor_login LIKE ?';
        $params[] = '%' . $filters['actor_login'] . '%';
    }

    if (isset($filters['login'])) {
        $where[] = '(actor_login LIKE ? OR details_json LIKE ?)';
        $params[] = '%' . $filters['login'] . '%';
        $params[] = '%"login":"' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['login']) . '%';
    }

    if (isset($filters['target_type'])) {
        $where[] = 'target_type = ?';
        $params[] = $filters['target_type'];
    }

    if (isset($filters['target_id'])) {
        $where[] = 'target_id = ?';
        $params[] = $filters['target_id'];
    }

    if (isset($filters['ip_address'])) {
        $where[] = 'ip_address = ?';
        $params[] = $filters['ip_address'];
    }

    if (isset($filters['created_from'])) {
        $where[] = 'created_at >= ?';
        $params[] = $filters['created_from'];
    }

    if (isset($filters['created_to'])) {
        $where[] = 'created_at <= ?';
        $params[] = $filters['created_to'];
    }

    return [
        'where_sql' => $where ? (' WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
    ];
}

function appAuditDecodeRow(array $row): array {
    $row['id'] = isset($row['id']) ? (int)$row['id'] : null;
    if (array_key_exists('actor_user_id', $row)) {
        $row['actor_user_id'] = isset($row['actor_user_id']) && $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null;
    }
    if (array_key_exists('failed_attempts', $row)) {
        $row['failed_attempts'] = isset($row['failed_attempts']) ? (int)$row['failed_attempts'] : 0;
    }
    if (array_key_exists('remaining_seconds', $row)) {
        $row['remaining_seconds'] = isset($row['remaining_seconds']) ? (int)$row['remaining_seconds'] : 0;
    }
    $row['details'] = null;
    if (!empty($row['details_json'])) {
        $decoded = json_decode((string)$row['details_json'], true);
        if (is_array($decoded)) {
            $row['details'] = $decoded;
        }
    }
    unset($row['details_json']);

    return $row;
}

function appAuditDecodeRows(array $rows): array {
    foreach ($rows as &$row) {
        $row = appAuditDecodeRow($row);
    }
    unset($row);

    return $rows;
}

function auditLog(PDO $pdo, string $eventType, array $payload = []): bool {
    if (!appAuditTableExists($pdo)) {
        return false;
    }

    $actor = appAuditActorFromUser($payload['actor'] ?? null);
    $targetType = isset($payload['target_type']) ? substr((string)$payload['target_type'], 0, 100) : null;
    $targetId = isset($payload['target_id']) ? substr((string)$payload['target_id'], 0, 100) : null;
    $summary = trim((string)($payload['summary'] ?? ''));
    if ($summary === '') {
        $summary = $eventType;
    }

    $details = $payload['details'] ?? null;
    $detailsJson = null;
    if (is_array($details)) {
        $detailsJson = json_encode(appAuditSanitizeArray($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (
                event_type, actor_user_id, actor_login, actor_role,
                target_type, target_id, summary, details_json,
                request_method, request_path, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        return $stmt->execute([
            substr($eventType, 0, 100),
            $actor['id'],
            $actor['login'],
            $actor['role'],
            $targetType,
            $targetId,
            substr($summary, 0, 255),
            $detailsJson,
            isset($_SERVER['REQUEST_METHOD']) ? substr((string)$_SERVER['REQUEST_METHOD'], 0, 10) : null,
            appAuditRequestPath(),
            appAuditIpAddress(),
            appAuditUserAgent(),
        ]);
    } catch (Throwable $e) {
        error_log('auditLog failed: ' . $e->getMessage());
        return false;
    }
}

function auditReadRecent(PDO $pdo, int $limit = 100, array $filters = []): array {
    if (!appAuditTableExists($pdo)) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $query = appAuditBuildRecentQuery($filters);
    $stmt = $pdo->prepare(
        'SELECT id, event_type, actor_user_id, actor_login, actor_role, target_type, target_id, summary,
                details_json, request_method, request_path, ip_address, user_agent, created_at
         FROM audit_log' . $query['where_sql'] . '
         ORDER BY id DESC
         LIMIT ' . $limit
    );
    $stmt->execute($query['params']);
    $rows = $stmt->fetchAll() ?: [];

    return appAuditDecodeRows($rows);
}

function auditReadActiveLockouts(PDO $pdo, int $limit = 100, ?string $loginFilter = null): array {
    if (!appAuditThrottleTableExists($pdo)) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $sql = 'SELECT id, login_value, login_key, ip_address, failed_attempts, first_failed_at, last_failed_at, lock_expires_at
            FROM auth_login_throttle
            WHERE lock_expires_at IS NOT NULL AND lock_expires_at > NOW()';
    $params = [];

    if ($loginFilter !== null) {
        $sql .= ' AND (login_value LIKE ? OR login_key LIKE ?)';
        $params[] = '%' . $loginFilter . '%';
        $params[] = '%' . $loginFilter . '%';
    }

    $sql .= ' ORDER BY lock_expires_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $now = time();

    foreach ($rows as &$row) {
        $row['id'] = isset($row['id']) ? (int)$row['id'] : null;
        $row['failed_attempts'] = isset($row['failed_attempts']) ? (int)$row['failed_attempts'] : 0;
        $row['login'] = $row['login_value'] ?? $row['login_key'] ?? null;
        $row['remaining_seconds'] = appAuthRemainingLockSeconds($row['lock_expires_at'] ?? null, $now);
        $row['locked'] = $row['remaining_seconds'] > 0;
        unset($row['login_key']);
    }
    unset($row);

    return $rows;
}

function auditExport(PDO $pdo, array $filters, ?int $limit, callable $writer): int {
    if (!appAuditTableExists($pdo)) {
        return 0;
    }

    $query = appAuditBuildRecentQuery($filters);
    $sql = 'SELECT id, event_type, actor_user_id, actor_login, actor_role, target_type, target_id, summary,
                   details_json, request_method, request_path, ip_address, user_agent, created_at
            FROM audit_log' . $query['where_sql'] . '
            ORDER BY id ASC';

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($query['params']);

    $exported = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $writer(appAuditDecodeRow($row));
        $exported++;
    }

    return $exported;
}

function auditDescribePrunable(PDO $pdo, string $cutoff): array {
    if (!appAuditTableExists($pdo)) {
        return [
            'count' => 0,
            'oldest_created_at' => null,
            'newest_created_at' => null,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS prune_count, MIN(created_at) AS oldest_created_at, MAX(created_at) AS newest_created_at
         FROM audit_log
         WHERE created_at < ?'
    );
    $stmt->execute([$cutoff]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'count' => isset($row['prune_count']) ? (int)$row['prune_count'] : 0,
        'oldest_created_at' => $row['oldest_created_at'] ?? null,
        'newest_created_at' => $row['newest_created_at'] ?? null,
    ];
}

function auditPruneOlderThan(PDO $pdo, string $cutoff, bool $dryRun = true): array {
    $summary = auditDescribePrunable($pdo, $cutoff);

    if ($dryRun || $summary['count'] <= 0) {
        $summary['deleted'] = 0;
        $summary['dry_run'] = true;
        return $summary;
    }

    $stmt = $pdo->prepare('DELETE FROM audit_log WHERE created_at < ?');
    $stmt->execute([$cutoff]);

    $summary['deleted'] = $stmt->rowCount();
    $summary['dry_run'] = false;
    return $summary;
}

function handleAudit(string $method, ?string $action, mixed $id): void {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/roles.php';

    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    if (!hasAdminAccess($currentUser)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
        exit;
    }

    if ($method === 'GET' && ($action === null || $action === 'recent')) {
        $limit = appAuditClampLimit($_GET['limit'] ?? null, 50, 200);
        $filters = appAuditBuildRecentFilters();
        echo json_encode([
            'success' => true,
            'data' => auditReadRecent($pdo, $limit, $filters),
            'meta' => [
                'limit' => $limit,
                'filters' => $filters,
                'retention' => [
                    'retention_days' => appAuditRetentionDays(),
                    'cutoff_before' => appAuditBuildRetentionCutoff(),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'lockouts') {
        $limit = appAuditClampLimit($_GET['limit'] ?? null, 50, 200);
        $login = appAuditNormalizeFilterString($_GET['login'] ?? null);
        echo json_encode([
            'success' => true,
            'data' => auditReadActiveLockouts($pdo, $limit, $login),
            'meta' => [
                'limit' => $limit,
                'filters' => [
                    'login' => $login,
                    'active_only' => true,
                ],
                'throttle_policy' => [
                    'max_attempts' => defined('AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS') ? AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS : null,
                    'window_seconds' => defined('AUTH_LOGIN_THROTTLE_WINDOW_SECONDS') ? AUTH_LOGIN_THROTTLE_WINDOW_SECONDS : null,
                    'lock_seconds' => defined('AUTH_LOGIN_THROTTLE_LOCK_SECONDS') ? AUTH_LOGIN_THROTTLE_LOCK_SECONDS : null,
                ],
                'retention' => [
                    'retention_days' => appAuditRetentionDays(),
                    'cutoff_before' => appAuditBuildRetentionCutoff(),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
