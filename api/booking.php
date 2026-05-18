<?php
/**
 * api/booking.php - Минимальный модуль онлайн-записи.
 *
 * Public:
 * - GET  /api/booking.php              - Справочник услуг для публичной формы
 * - POST /api/booking.php              - Создать заявку на запись
 *
 * Admin:
 * - GET  /api/booking.php              - Список заявок и статистика
 * - POST /api/booking.php {action}     - approve / reject
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/booking-schema.php';

appSecurityApplyApiHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function bookingReadJsonBody(): array {
    global $POST_DATA_CACHE;

    if (is_array($POST_DATA_CACHE)) {
        return $POST_DATA_CACHE;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        $POST_DATA_CACHE = [];
        return [];
    }

    $decoded = json_decode($raw, true);
    $POST_DATA_CACHE = is_array($decoded) ? $decoded : [];
    return $POST_DATA_CACHE;
}

function bookingNormalizeString(mixed $value, int $maxLength = 255): ?string {
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    return substr($value, 0, $maxLength);
}

function bookingNormalizeDateTime(mixed $value): ?string {
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime(str_replace('T', ' ', $value));
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function bookingGenerateRequestNumber(PDO $pdo): string {
    $prefix = 'BK-' . date('Ymd');
    $stmt = $pdo->prepare("SELECT request_number FROM booking_requests WHERE request_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '-%']);
    $lastNumber = $stmt->fetchColumn();

    if ($lastNumber) {
        $suffix = (int)substr(strrchr((string)$lastNumber, '-'), 1);
        $nextSuffix = str_pad((string)($suffix + 1), 4, '0', STR_PAD_LEFT);
    } else {
        $nextSuffix = '0001';
    }

    return $prefix . '-' . $nextSuffix;
}

function bookingFetchServiceTypes(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, type_key, type_name, icon, description, sort_order
        FROM booking_service_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, type_name ASC");

    return $stmt ? $stmt->fetchAll() : [];
}

function bookingFetchBookingRequests(PDO $pdo): array {
    $stmt = $pdo->query("SELECT
            br.*,
            bst.type_key AS service_type_key,
            bst.type_name AS service_type_name,
            bst.icon AS service_type_icon,
            created.full_name AS created_by_name,
            reviewer.full_name AS reviewed_by_name
        FROM booking_requests br
        JOIN booking_service_types bst ON bst.id = br.service_type_id
        LEFT JOIN users created ON created.id = br.created_by
        LEFT JOIN users reviewer ON reviewer.id = br.reviewed_by
        ORDER BY br.created_at DESC, br.id DESC");

    return $stmt ? $stmt->fetchAll() : [];
}

function bookingFetchBookingStats(PDO $pdo): array {
    $stmt = $pdo->query("SELECT
            COUNT(*) AS total,
            COALESCE(SUM(status = 'new'), 0) AS new_count,
            COALESCE(SUM(status = 'approved'), 0) AS approved_count,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected_count
        FROM booking_requests");

    $row = $stmt ? $stmt->fetch() : [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'new' => (int)($row['new_count'] ?? 0),
        'approved' => (int)($row['approved_count'] ?? 0),
        'rejected' => (int)($row['rejected_count'] ?? 0),
    ];
}

function bookingFetchBookingRequest(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT
            br.*,
            bst.type_key AS service_type_key,
            bst.type_name AS service_type_name,
            bst.icon AS service_type_icon,
            created.full_name AS created_by_name,
            reviewer.full_name AS reviewed_by_name
        FROM booking_requests br
        JOIN booking_service_types bst ON bst.id = br.service_type_id
        LEFT JOIN users created ON created.id = br.created_by
        LEFT JOIN users reviewer ON reviewer.id = br.reviewed_by
        WHERE br.id = ?
        LIMIT 1");
    $stmt->execute([$id]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function bookingAdminRecipientIds(PDO $pdo): array {
    $recipientIds = getUserIdsByRoles($pdo, ['root']);

    try {
        $stmt = $pdo->query("SELECT DISTINCT u.id
            FROM users u
            JOIN roles r ON r.name = u.role
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE p.code = 'admin.full'");
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []));
    } catch (Throwable $e) {
        // Best effort only.
    }

    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
    sort($recipientIds);
    return $recipientIds;
}

function bookingRespond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bookingHandleCreate(PDO $pdo, ?array $currentUser): void {
    $data = bookingReadJsonBody();

    $serviceTypeId = (int)($data['service_type_id'] ?? 0);
    $clientName = bookingNormalizeString($data['client_name'] ?? null, 255);
    $clientEmail = bookingNormalizeString($data['client_email'] ?? null, 255);
    $clientPhone = bookingNormalizeString($data['client_phone'] ?? null, 80);
    $clientCompany = bookingNormalizeString($data['client_company'] ?? null, 255);
    $preferredDatetime = bookingNormalizeDateTime($data['preferred_datetime'] ?? null);
    $notes = bookingNormalizeString($data['notes'] ?? null, 5000);

    if ($serviceTypeId <= 0 || !$clientName) {
        bookingRespond(['success' => false, 'error' => 'Укажите имя и услугу'], 400);
    }

    if (!$clientEmail && !$clientPhone) {
        bookingRespond(['success' => false, 'error' => 'Укажите телефон или email для связи'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, type_key, type_name FROM booking_service_types WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$serviceTypeId]);
    $serviceType = $stmt->fetch();

    if (!$serviceType) {
        bookingRespond(['success' => false, 'error' => 'Выбранная услуга недоступна'], 400);
    }

    $requestNumber = bookingGenerateRequestNumber($pdo);
    $createdBy = $currentUser['id'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO booking_requests (
            request_number,
            service_type_id,
            client_name,
            client_email,
            client_phone,
            client_company,
            preferred_datetime,
            notes,
            status,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', ?)");
    $stmt->execute([
        $requestNumber,
        $serviceTypeId,
        $clientName,
        $clientEmail,
        $clientPhone,
        $clientCompany,
        $preferredDatetime,
        $notes,
        $createdBy,
    ]);

    $requestId = (int)$pdo->lastInsertId();
    $request = bookingFetchBookingRequest($pdo, $requestId);

    auditLog($pdo, 'booking.created', [
        'actor' => $currentUser,
        'target_type' => 'booking_request',
        'target_id' => (string)$requestId,
        'summary' => 'Создана заявка на запись ' . $requestNumber,
        'details' => [
            'request_number' => $requestNumber,
            'service_type_id' => $serviceTypeId,
            'service_type_name' => $serviceType['type_name'],
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'client_phone' => $clientPhone,
            'preferred_datetime' => $preferredDatetime,
        ],
    ]);

    $recipientIds = bookingAdminRecipientIds($pdo);
    if ($recipientIds) {
        createNotifications($pdo, $recipientIds, [
            'sender_id' => $createdBy ? (int)$createdBy : null,
            'message' => 'Новая заявка на запись ' . $requestNumber,
            'type' => 'booking',
            'related_id' => $requestId,
            'allow_self' => false,
        ]);
    }

    bookingRespond([
        'success' => true,
        'data' => $request,
        'message' => 'Заявка на запись создана',
    ], 201);
}

function bookingHandleDecision(PDO $pdo, ?array $currentUser, string $decision): void {
    if (!$currentUser || !hasAdminAccess($currentUser)) {
        bookingRespond(['success' => false, 'error' => 'Только администраторы могут обрабатывать заявки'], 403);
    }

    $data = bookingReadJsonBody();
    $requestId = (int)($data['request_id'] ?? $data['id'] ?? 0);
    $adminComment = bookingNormalizeString($data['admin_comment'] ?? $data['comment'] ?? null, 5000);

    if ($requestId <= 0) {
        bookingRespond(['success' => false, 'error' => 'Укажите id заявки'], 400);
    }

    $request = bookingFetchBookingRequest($pdo, $requestId);
    if (!$request) {
        bookingRespond(['success' => false, 'error' => 'Заявка не найдена'], 404);
    }

    if (($request['status'] ?? 'new') !== 'new') {
        bookingRespond(['success' => false, 'error' => 'Заявка уже обработана'], 409);
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("UPDATE booking_requests
        SET status = ?, admin_comment = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?");
    $stmt->execute([
        $status,
        $adminComment,
        (int)$currentUser['id'],
        $requestId,
    ]);

    $freshRequest = bookingFetchBookingRequest($pdo, $requestId);

    auditLog($pdo, $status === 'approved' ? 'booking.approved' : 'booking.rejected', [
        'actor' => $currentUser,
        'target_type' => 'booking_request',
        'target_id' => (string)$requestId,
        'summary' => 'Заявка ' . ($freshRequest['request_number'] ?? $request['request_number'] ?? $requestId) . ' ' . ($status === 'approved' ? 'одобрена' : 'отклонена'),
        'details' => [
            'status' => $status,
            'request_number' => $freshRequest['request_number'] ?? $request['request_number'] ?? null,
            'service_type_name' => $freshRequest['service_type_name'] ?? $request['service_type_name'] ?? null,
            'admin_comment' => $adminComment,
        ],
    ]);

    $creatorId = isset($request['created_by']) ? (int)$request['created_by'] : 0;
    if ($creatorId > 0 && $creatorId !== (int)$currentUser['id']) {
        createNotification($pdo, [
            'user_id' => $creatorId,
            'sender_id' => (int)$currentUser['id'],
            'message' => 'Ваша заявка на запись ' . $request['request_number'] . ' ' . ($status === 'approved' ? 'одобрена' : 'отклонена'),
            'type' => 'booking',
            'related_id' => $requestId,
        ]);
    }

    bookingRespond([
        'success' => true,
        'data' => $freshRequest,
        'message' => $status === 'approved' ? 'Заявка одобрена' : 'Заявка отклонена',
    ]);
}

function handleBooking(string $method): void {
    $pdo = getPDO();

    try {
        ensureBookingModuleSchema($pdo);
    } catch (Throwable $e) {
        error_log('booking.php schema ensure failed: ' . $e->getMessage());
    }

    if ($method === 'POST') {
        bookingReadJsonBody();
    }

    $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;

    if ($method === 'GET') {
        $data = [
            'service_types' => bookingFetchServiceTypes($pdo),
            'can_manage' => (bool)($currentUser && hasAdminAccess($currentUser)),
        ];

        if ($currentUser && hasAdminAccess($currentUser)) {
            $data['requests'] = bookingFetchBookingRequests($pdo);
            $data['stats'] = bookingFetchBookingStats($pdo);
        }

        bookingRespond(['success' => true, 'data' => $data]);
    }

    if ($method !== 'POST') {
        bookingRespond(['success' => false, 'error' => 'Метод не поддерживается'], 405);
    }

    $data = bookingReadJsonBody();
    $action = strtolower(trim((string)($data['action'] ?? ($_GET['action'] ?? ''))));

    if ($action === 'approve' || $action === 'reject') {
        bookingHandleDecision($pdo, $currentUser, $action);
    }

    bookingHandleCreate($pdo, $currentUser);
}

handleBooking($_SERVER['REQUEST_METHOD'] ?? 'GET');
