<?php
/**
 * api/booking.php - Booking API with multi-service requests and confirmation flow.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/booking-schema.php';
require_once __DIR__ . '/notification-service.php';

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

function bookingParseDateTime(mixed $value): ?DateTimeImmutable {
    $normalized = bookingNormalizeDateTime($value);
    if ($normalized === null) {
        return null;
    }

    try {
        return new DateTimeImmutable($normalized);
    } catch (Throwable $e) {
        return null;
    }
}

function bookingResolvePreferredDatetime(array $data): ?string {
    $preferredDatetime = bookingNormalizeDateTime($data['preferred_datetime'] ?? null);
    if ($preferredDatetime !== null) {
        return $preferredDatetime;
    }

    $preferredDate = bookingNormalizeString($data['preferred_date'] ?? $data['date'] ?? null, 32);
    $preferredTime = bookingNormalizeString($data['preferred_time'] ?? $data['time'] ?? null, 16);

    if ($preferredDate && $preferredTime) {
        return bookingNormalizeDateTime($preferredDate . ' ' . $preferredTime);
    }

    return null;
}

function bookingNormalizeServiceIdList(mixed $value): array {
    $ids = [];

    $append = static function (mixed $candidate) use (&$ids): void {
        if (is_array($candidate)) {
            if (array_key_exists('service_type_id', $candidate)) {
                $candidate = $candidate['service_type_id'];
            } elseif (array_key_exists('service_id', $candidate)) {
                $candidate = $candidate['service_id'];
            } elseif (array_key_exists('id', $candidate)) {
                $candidate = $candidate['id'];
            } else {
                return;
            }
        }

        if (!is_scalar($candidate)) {
            return;
        }

        $id = (int)$candidate;
        if ($id > 0) {
            $ids[$id] = true;
        }
    };

    if (is_array($value)) {
        foreach ($value as $item) {
            $append($item);
        }
    } elseif (is_scalar($value)) {
        $parts = preg_split('/[\s,]+/', trim((string)$value)) ?: [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $append($part);
            }
        }
    }

    return array_map('intval', array_keys($ids));
}

function bookingAliasServiceRow(array $row): array {
    $row['service_key'] = trim((string)($row['service_key'] ?? $row['type_key'] ?? ''));
    $row['service_name'] = trim((string)($row['service_name'] ?? $row['type_name'] ?? ''));

    if ($row['service_key'] === '') {
        $row['service_key'] = bookingNormalizeServiceKey($row['service_name'] !== '' ? $row['service_name'] : 'service');
    }

    if ($row['service_name'] === '') {
        $row['service_name'] = $row['service_key'];
    }

    $row['type_key'] = $row['service_key'];
    $row['type_name'] = $row['service_name'];
    $row['icon'] = trim((string)($row['icon'] ?? 'calendar')) ?: 'calendar';

    return $row;
}

function bookingLoadServiceCatalog(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, type_key, type_name, icon, description, duration_minutes, price_rub, discount_type, discount_value, promo_label, sort_order, is_active
        FROM booking_service_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, type_name ASC, id ASC");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $catalog = [];

    foreach ($rows as $row) {
        $row = bookingAliasServiceRow(bookingDecorateServiceRow($row));
        $catalog[(int)$row['id']] = $row;
    }

    return $catalog;
}

function bookingFetchServiceTypes(PDO $pdo): array {
    return array_values(bookingLoadServiceCatalog($pdo));
}

function bookingFetchWorkingHours(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, weekday, is_open, opens_at, closes_at, break_starts_at, break_ends_at, note, sort_order
        FROM booking_working_hours
        ORDER BY sort_order ASC, weekday ASC");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $hours = [];

    foreach ($rows as $row) {
        $hours[] = bookingDecorateWorkingHourRow($row);
    }

    return $hours;
}

function bookingWorkingHoursByWeekday(array $workingHours): array {
    $map = [];
    foreach ($workingHours as $row) {
        $weekday = (int)($row['weekday'] ?? 0);
        if ($weekday > 0) {
            $map[$weekday] = $row;
        }
    }

    return $map;
}

function bookingTimeToMinutes(?string $value): ?int {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
        return null;
    }

    return ((int)$matches[1] * 60) + (int)$matches[2];
}

function bookingValidateWorkingHoursSlot(array $workingHoursByWeekday, DateTimeImmutable $startAt, DateTimeImmutable $endAt): ?string {
    if ($endAt <= $startAt) {
        return 'Некорректное время записи';
    }

    if ($startAt->format('Y-m-d') !== $endAt->format('Y-m-d')) {
        return 'Запись должна укладываться в один рабочий день';
    }

    $weekday = (int)$startAt->format('N');
    $row = $workingHoursByWeekday[$weekday] ?? null;
    if (!$row || !(bool)($row['is_open'] ?? false)) {
        return 'На выбранный день запись недоступна';
    }

    $openMinutes = bookingTimeToMinutes($row['opens_at'] ?? null) ?? 0;
    $closeMinutes = bookingTimeToMinutes($row['closes_at'] ?? null) ?? (24 * 60);
    if ($closeMinutes <= $openMinutes) {
        return 'График работы настроен некорректно';
    }

    $startMinutes = ((int)$startAt->format('H') * 60) + (int)$startAt->format('i');
    $endMinutes = ((int)$endAt->format('H') * 60) + (int)$endAt->format('i');

    if ($startMinutes < $openMinutes || $endMinutes > $closeMinutes) {
        return 'Выбранное время выходит за пределы рабочих часов';
    }

    $breakStartMinutes = bookingTimeToMinutes($row['break_starts_at'] ?? null);
    $breakEndMinutes = bookingTimeToMinutes($row['break_ends_at'] ?? null);
    if ($breakStartMinutes !== null && $breakEndMinutes !== null && $breakEndMinutes > $breakStartMinutes) {
        if ($startMinutes < $breakEndMinutes && $endMinutes > $breakStartMinutes) {
            return 'Выбранное время попадает на перерыв';
        }
    }

    return null;
}

function bookingGenerateRequestNumber(PDO $pdo, ?DateTimeImmutable $now = null): string {
    $now ??= new DateTimeImmutable('now');
    $prefix = 'BK-' . $now->format('Ymd');
    $stmt = $pdo->prepare("SELECT request_number FROM booking_requests WHERE request_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$prefix . '-%']);
    $lastNumber = $stmt->fetchColumn();

    if ($lastNumber) {
        $suffixPart = strrchr((string)$lastNumber, '-');
        $suffix = $suffixPart !== false ? (int)substr($suffixPart, 1) : 0;
        $nextSuffix = str_pad((string)($suffix + 1), 4, '0', STR_PAD_LEFT);
    } else {
        $nextSuffix = '0001';
    }

    return $prefix . '-' . $nextSuffix;
}

function bookingDecorateRequestServiceRow(array $row): array {
    $row = bookingAliasServiceRow($row);
    $row['id'] = isset($row['id']) ? (int)$row['id'] : null;
    $row['booking_request_id'] = isset($row['booking_request_id']) ? (int)$row['booking_request_id'] : null;
    $row['service_type_id'] = isset($row['service_type_id']) ? (int)$row['service_type_id'] : null;
    $row['duration_minutes'] = max(0, (int)($row['duration_minutes'] ?? 0));
    $row['price_rub'] = round((float)($row['price_rub'] ?? 0), 2);
    $row['discount_type'] = strtolower(trim((string)($row['discount_type'] ?? 'none')));
    $row['discount_value'] = round((float)($row['discount_value'] ?? 0), 2);
    $row['effective_price_rub'] = round((float)($row['effective_price_rub'] ?? bookingServiceEffectivePrice($row)), 2);
    $row['discount_amount_rub'] = max(0, round($row['price_rub'] - $row['effective_price_rub'], 2));
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);

    return $row;
}

function bookingServiceCatalogToRequestServiceRow(array $service, int $bookingRequestId = 0, int $sortOrder = 1): array {
    $service = bookingAliasServiceRow(bookingDecorateServiceRow($service));

    return [
        'booking_request_id' => $bookingRequestId > 0 ? $bookingRequestId : null,
        'service_type_id' => (int)($service['id'] ?? 0),
        'service_key' => (string)($service['service_key'] ?? ''),
        'service_name' => (string)($service['service_name'] ?? ''),
        'icon' => (string)($service['icon'] ?? 'calendar'),
        'duration_minutes' => max(0, (int)($service['duration_minutes'] ?? 0)),
        'price_rub' => round((float)($service['price_rub'] ?? 0), 2),
        'discount_type' => (string)($service['discount_type'] ?? 'none'),
        'discount_value' => round((float)($service['discount_value'] ?? 0), 2),
        'effective_price_rub' => round((float)($service['effective_price_rub'] ?? bookingServiceEffectivePrice($service)), 2),
        'sort_order' => $sortOrder,
    ];
}

function bookingFetchBookingRequestServices(PDO $pdo, array $requestIds): array {
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    if (!$requestIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM booking_request_services WHERE booking_request_id IN ($placeholders) ORDER BY booking_request_id ASC, sort_order ASC, id ASC");
    $stmt->execute($requestIds);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $grouped = [];

    foreach ($rows as $row) {
        $row = bookingDecorateRequestServiceRow($row);
        $grouped[(int)$row['booking_request_id']][] = $row;
    }

    return $grouped;
}

function bookingRequestHoldExpiresAt(array $request): ?DateTimeImmutable {
    $holdExpiresAt = bookingParseDateTime($request['hold_expires_at'] ?? null);
    if ($holdExpiresAt instanceof DateTimeImmutable) {
        return $holdExpiresAt;
    }

    $status = bookingNormalizeStatus($request['status'] ?? null);
    if ($status !== 'pending') {
        return null;
    }

    $createdAt = bookingParseDateTime($request['created_at'] ?? null);
    if ($createdAt instanceof DateTimeImmutable) {
        return $createdAt->modify('+30 minutes');
    }

    return null;
}

function bookingRequestResolvedStatus(array $request, ?DateTimeImmutable $now = null): string {
    $status = bookingNormalizeStatus($request['status'] ?? null);
    if ($status !== 'pending') {
        return $status;
    }

    $now ??= new DateTimeImmutable('now');
    $holdExpiresAt = bookingRequestHoldExpiresAt($request);
    if ($holdExpiresAt instanceof DateTimeImmutable && $holdExpiresAt <= $now) {
        return 'expired';
    }

    return 'pending';
}

function bookingExpirePendingRequests(PDO $pdo, ?DateTimeImmutable $now = null): int {
    $now ??= new DateTimeImmutable('now');
    $stmt = $pdo->prepare("UPDATE booking_requests
        SET status = 'expired',
            hold_expires_at = COALESCE(hold_expires_at, DATE_ADD(created_at, INTERVAL 30 MINUTE))
        WHERE LOWER(status) IN ('new', 'pending')
          AND COALESCE(hold_expires_at, DATE_ADD(created_at, INTERVAL 30 MINUTE)) <= ?");
    $stmt->execute([$now->format('Y-m-d H:i:s')]);

    return (int)$stmt->rowCount();
}

function bookingComposeBookingRequest(PDO $pdo, array $request, array $services = [], array $serviceCatalog = [], ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now');

    $request['id'] = isset($request['id']) ? (int)$request['id'] : null;
    $request['service_type_id'] = isset($request['service_type_id']) ? (int)$request['service_type_id'] : null;
    $request['crm_client_id'] = isset($request['crm_client_id']) ? (int)$request['crm_client_id'] : null;
    $request['created_by'] = isset($request['created_by']) ? (int)$request['created_by'] : null;
    $request['reviewed_by'] = isset($request['reviewed_by']) ? (int)$request['reviewed_by'] : null;
    $request['status'] = bookingRequestResolvedStatus($request, $now);
    $request['preferred_datetime'] = bookingNormalizeString($request['preferred_datetime'] ?? null, 32);
    $request['preferred_end_at'] = bookingNormalizeString($request['preferred_end_at'] ?? null, 32);
    $request['hold_expires_at'] = bookingNormalizeString($request['hold_expires_at'] ?? null, 32);
    $request['confirmed_at'] = bookingNormalizeString($request['confirmed_at'] ?? null, 32);
    $request['client_name'] = bookingNormalizeString($request['client_name'] ?? null, 255);
    $request['client_email'] = bookingNormalizeString($request['client_email'] ?? null, 255);
    $request['client_phone'] = bookingNormalizeString($request['client_phone'] ?? null, 80);
    $request['client_company'] = bookingNormalizeString($request['client_company'] ?? null, 255);
    $request['notes'] = bookingNormalizeString($request['notes'] ?? null, 5000);
    $request['admin_comment'] = bookingNormalizeString($request['admin_comment'] ?? null, 5000);
    $request['created_by_name'] = bookingNormalizeString($request['created_by_name'] ?? null, 255);
    $request['reviewed_by_name'] = bookingNormalizeString($request['reviewed_by_name'] ?? null, 255);
    $request['total_duration_minutes'] = max(0, (int)($request['total_duration_minutes'] ?? 0));
    $request['total_price_rub'] = round((float)($request['total_price_rub'] ?? 0), 2);

    $normalizedServices = [];
    foreach ($services as $service) {
        $normalizedServices[] = bookingDecorateRequestServiceRow($service);
    }

    if (!$normalizedServices) {
        $fallbackService = null;
        $fallbackServiceId = (int)($request['service_type_id'] ?? 0);

        if ($fallbackServiceId > 0) {
            if (isset($serviceCatalog[$fallbackServiceId])) {
                $fallbackService = $serviceCatalog[$fallbackServiceId];
            } else {
                $stmt = $pdo->prepare("SELECT id, type_key, type_name, icon, description, duration_minutes, price_rub, discount_type, discount_value, promo_label, sort_order, is_active
                    FROM booking_service_types
                    WHERE id = ?
                    LIMIT 1");
                $stmt->execute([$fallbackServiceId]);
                $fallbackService = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($fallbackService)) {
                    $fallbackService = bookingAliasServiceRow(bookingDecorateServiceRow($fallbackService));
                }
            }
        }

        if (is_array($fallbackService)) {
            $normalizedServices[] = bookingDecorateRequestServiceRow(bookingServiceCatalogToRequestServiceRow($fallbackService, (int)($request['id'] ?? 0), 1));
        } elseif ($fallbackServiceId > 0) {
            $normalizedServices[] = bookingDecorateRequestServiceRow([
                'booking_request_id' => isset($request['id']) ? (int)$request['id'] : null,
                'service_type_id' => $fallbackServiceId,
                'service_key' => trim((string)($request['service_type_key'] ?? '')) ?: bookingNormalizeServiceKey('service'),
                'service_name' => trim((string)($request['service_type_name'] ?? '')) ?: 'Услуга',
                'icon' => trim((string)($request['service_type_icon'] ?? 'calendar')) ?: 'calendar',
                'duration_minutes' => max(0, (int)($request['total_duration_minutes'] ?? 0)),
                'price_rub' => round((float)($request['total_price_rub'] ?? 0), 2),
                'discount_type' => 'none',
                'discount_value' => 0,
                'effective_price_rub' => round((float)($request['total_price_rub'] ?? 0), 2),
                'sort_order' => 1,
            ]);
        }
    }

    $request['services'] = $normalizedServices;
    $request['service_count'] = count($normalizedServices);

    $serviceNames = [];
    $totalDuration = 0;
    $totalPrice = 0.0;
    foreach ($normalizedServices as $service) {
        $serviceNames[] = (string)($service['service_name'] ?? $service['type_name'] ?? '');
        $totalDuration += max(0, (int)($service['duration_minutes'] ?? 0));
        $totalPrice += max(0, (float)($service['effective_price_rub'] ?? 0));
    }

    $request['service_summary'] = trim(implode(', ', array_filter($serviceNames)));
    $request['total_duration_minutes'] = $request['total_duration_minutes'] > 0 ? $request['total_duration_minutes'] : $totalDuration;
    $request['total_price_rub'] = $request['total_price_rub'] > 0 ? $request['total_price_rub'] : round($totalPrice, 2);

    if ($normalizedServices) {
        $primary = $normalizedServices[0];
        $request['service_type_id'] = (int)($primary['service_type_id'] ?? $primary['id'] ?? $request['service_type_id'] ?? 0);
        $request['service_type_key'] = (string)($primary['service_key'] ?? $primary['type_key'] ?? '');
        $request['service_type_name'] = (string)($primary['service_name'] ?? $primary['type_name'] ?? '');
        $request['service_type_icon'] = (string)($primary['icon'] ?? 'calendar');
    } else {
        $request['service_type_key'] = trim((string)($request['service_type_key'] ?? ''));
        $request['service_type_name'] = trim((string)($request['service_type_name'] ?? ''));
        $request['service_type_icon'] = trim((string)($request['service_type_icon'] ?? '')) ?: 'calendar';
    }

    $request['is_pending'] = $request['status'] === 'pending';
    $request['is_confirmed'] = $request['status'] === 'confirmed';
    $request['is_rejected'] = $request['status'] === 'rejected';
    $request['is_expired'] = $request['status'] === 'expired';
    $request['hold_minutes_left'] = 0;

    if ($request['status'] === 'pending') {
        $holdExpiresAt = bookingRequestHoldExpiresAt($request);
        if ($holdExpiresAt instanceof DateTimeImmutable) {
            $request['hold_expires_at'] = $holdExpiresAt->format('Y-m-d H:i:s');
            $request['hold_minutes_left'] = max(0, (int)ceil(($holdExpiresAt->getTimestamp() - $now->getTimestamp()) / 60));
        }
    } elseif ($request['status'] === 'confirmed' || $request['status'] === 'rejected') {
        $request['hold_expires_at'] = null;
    } elseif ($request['status'] === 'expired') {
        $holdExpiresAt = bookingRequestHoldExpiresAt($request);
        if ($holdExpiresAt instanceof DateTimeImmutable) {
            $request['hold_expires_at'] = $holdExpiresAt->format('Y-m-d H:i:s');
        }
    }

    if ($request['status'] === 'confirmed' && empty($request['confirmed_at'])) {
        $confirmedAt = bookingParseDateTime($request['reviewed_at'] ?? null) ?? bookingParseDateTime($request['updated_at'] ?? null);
        if ($confirmedAt instanceof DateTimeImmutable) {
            $request['confirmed_at'] = $confirmedAt->format('Y-m-d H:i:s');
        }
    }

    return $request;
}

function bookingFetchBookingRequests(PDO $pdo, ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now');
    $serviceCatalog = bookingLoadServiceCatalog($pdo);
    $stmt = $pdo->query("SELECT
            br.*,
            bst.type_key AS service_type_key,
            bst.type_name AS service_type_name,
            bst.icon AS service_type_icon,
            created.full_name AS created_by_name,
            reviewer.full_name AS reviewed_by_name
        FROM booking_requests br
        LEFT JOIN booking_service_types bst ON bst.id = br.service_type_id
        LEFT JOIN users created ON created.id = br.created_by
        LEFT JOIN users reviewer ON reviewer.id = br.reviewed_by
        ORDER BY CASE LOWER(br.status)
            WHEN 'pending' THEN 0
            WHEN 'new' THEN 0
            WHEN 'confirmed' THEN 1
            WHEN 'approved' THEN 1
            WHEN 'rejected' THEN 2
            WHEN 'expired' THEN 3
            ELSE 4
        END, COALESCE(br.preferred_datetime, br.created_at) ASC, br.id DESC");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$rows) {
        return [];
    }

    $servicesMap = bookingFetchBookingRequestServices($pdo, array_column($rows, 'id'));
    $requests = [];
    foreach ($rows as $row) {
        $requestId = (int)($row['id'] ?? 0);
        $requests[] = bookingComposeBookingRequest($pdo, $row, $servicesMap[$requestId] ?? [], $serviceCatalog, $now);
    }

    return $requests;
}

function bookingFetchBookingStats(PDO $pdo): array {
    $stmt = $pdo->query("SELECT
            COUNT(*) AS total,
            COALESCE(SUM(LOWER(status) IN ('new', 'pending')), 0) AS pending_count,
            COALESCE(SUM(LOWER(status) IN ('approved', 'confirmed')), 0) AS confirmed_count,
            COALESCE(SUM(LOWER(status) = 'rejected'), 0) AS rejected_count,
            COALESCE(SUM(LOWER(status) = 'expired'), 0) AS expired_count
        FROM booking_requests");

    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];

    $pending = (int)($row['pending_count'] ?? 0);
    $confirmed = (int)($row['confirmed_count'] ?? 0);
    $rejected = (int)($row['rejected_count'] ?? 0);
    $expired = (int)($row['expired_count'] ?? 0);

    return [
        'total' => (int)($row['total'] ?? 0),
        'pending' => $pending,
        'confirmed' => $confirmed,
        'rejected' => $rejected,
        'expired' => $expired,
        'new' => $pending,
        'approved' => $confirmed,
    ];
}

function bookingFetchBookingRequest(PDO $pdo, int $id, ?DateTimeImmutable $now = null): ?array {
    if ($id <= 0) {
        return null;
    }

    $now ??= new DateTimeImmutable('now');
    $serviceCatalog = bookingLoadServiceCatalog($pdo);
    $stmt = $pdo->prepare("SELECT
            br.*,
            bst.type_key AS service_type_key,
            bst.type_name AS service_type_name,
            bst.icon AS service_type_icon,
            created.full_name AS created_by_name,
            reviewer.full_name AS reviewed_by_name
        FROM booking_requests br
        LEFT JOIN booking_service_types bst ON bst.id = br.service_type_id
        LEFT JOIN users created ON created.id = br.created_by
        LEFT JOIN users reviewer ON reviewer.id = br.reviewed_by
        WHERE br.id = ?
        LIMIT 1");
    $stmt->execute([$id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $servicesMap = bookingFetchBookingRequestServices($pdo, [$id]);
    return bookingComposeBookingRequest($pdo, $row, $servicesMap[$id] ?? [], $serviceCatalog, $now);
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

function bookingHandleCreate(PDO $pdo, ?array $currentUser, ?DateTimeImmutable $now = null): void {
    require_once __DIR__ . '/license.php';
    if (!isFeatureEnabled($pdo, 'booking')) {
        bookingRespond(['success' => false, 'error' => 'Модуль записи недоступен на текущем тарифе. Обратитесь в поддержку для подключения.'], 403);
    }
    $now ??= new DateTimeImmutable('now');
    $data = bookingReadJsonBody();

    $serviceIds = bookingNormalizeServiceIdList($data['service_type_ids'] ?? $data['service_ids'] ?? $data['services'] ?? $data['service_type_id'] ?? null);
    $clientName = bookingNormalizeString($data['client_name'] ?? $data['name'] ?? null, 255);
    $clientEmail = bookingNormalizeString($data['client_email'] ?? $data['email'] ?? null, 255);
    $clientPhone = bookingNormalizeString($data['client_phone'] ?? $data['phone'] ?? null, 80);
    $clientCompany = bookingNormalizeString($data['client_company'] ?? $data['company'] ?? null, 255);
    $preferredDatetime = bookingResolvePreferredDatetime($data);
    $notes = bookingNormalizeString($data['notes'] ?? $data['comment'] ?? null, 5000);
    $crmClientId = (int)($data['crm_client_id'] ?? $data['client_id'] ?? 0);
    $crmClientId = $crmClientId > 0 ? $crmClientId : null;
    $source = bookingNormalizeString($data['source'] ?? null, 32) ?? 'web';
    $pageUrl = bookingNormalizeString($data['page_url'] ?? null, 1000);
    $widgetProfileSlug = bookingNormalizeString($data['profile'] ?? $data['widget_profile'] ?? null, 120);
    $widgetProfileId = null;
    if ($widgetProfileSlug) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM site_widget_profiles WHERE slug = ? LIMIT 1");
            $stmt->execute([$widgetProfileSlug]);
            $widgetProfileId = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $widgetProfileId = null;
        }
    }

    if (!$clientName || !$clientPhone) {
        bookingRespond(['success' => false, 'error' => 'Укажите имя и телефон для связи'], 400);
    }

    if (!$serviceIds) {
        bookingRespond(['success' => false, 'error' => 'Выберите хотя бы одну услугу'], 400);
    }

    if (!$preferredDatetime) {
        bookingRespond(['success' => false, 'error' => 'Укажите желаемые дату и время'], 400);
    }

    $serviceCatalog = bookingLoadServiceCatalog($pdo);
    $selectedServices = [];
    foreach ($serviceIds as $serviceId) {
        if (!isset($serviceCatalog[$serviceId])) {
            bookingRespond(['success' => false, 'error' => 'Одна или несколько услуг недоступны'], 400);
        }
        $selectedServices[] = $serviceCatalog[$serviceId];
    }

    if (!$selectedServices) {
        bookingRespond(['success' => false, 'error' => 'Выберите хотя бы одну услугу'], 400);
    }

    $totalDurationMinutes = 0;
    $totalPriceRub = 0.0;
    foreach ($selectedServices as $service) {
        $totalDurationMinutes += max(0, (int)($service['duration_minutes'] ?? 0));
        $totalPriceRub += max(0, (float)($service['effective_price_rub'] ?? 0));
    }

    if ($totalDurationMinutes <= 0) {
        bookingRespond(['success' => false, 'error' => 'У выбранных услуг не задана длительность'], 400);
    }

    $startAt = bookingParseDateTime($preferredDatetime);
    if (!$startAt instanceof DateTimeImmutable) {
        bookingRespond(['success' => false, 'error' => 'Укажите корректные дату и время'], 400);
    }

    if ($startAt <= $now) {
        bookingRespond(['success' => false, 'error' => 'Выберите время в будущем'], 400);
    }

    $endAt = $startAt->modify('+' . $totalDurationMinutes . ' minutes');
    $workingHours = bookingWorkingHoursByWeekday(bookingFetchWorkingHours($pdo));
    $workingHoursError = bookingValidateWorkingHoursSlot($workingHours, $startAt, $endAt);
    if ($workingHoursError !== null) {
        bookingRespond(['success' => false, 'error' => $workingHoursError], 400);
    }

    $requestId = 0;
    $requestNumber = '';
    $holdExpiresAt = $now->modify('+30 minutes');
    $primaryService = $selectedServices[0];
    $createdBy = $currentUser['id'] ?? null;

    try {
        if (!$pdo->beginTransaction()) {
            bookingRespond(['success' => false, 'error' => 'Не удалось начать создание заявки'], 500);
        }

        $requestNumber = bookingGenerateRequestNumber($pdo, $now);
        $startValue = $startAt->format('Y-m-d H:i:s');
        $endValue = $endAt->format('Y-m-d H:i:s');
        $nowValue = $now->format('Y-m-d H:i:s');

        $conflictStmt = $pdo->prepare("SELECT br.id, br.request_number
            FROM booking_requests br
            WHERE br.preferred_datetime IS NOT NULL
              AND COALESCE(br.preferred_end_at, DATE_ADD(br.preferred_datetime, INTERVAL GREATEST(br.total_duration_minutes, 0) MINUTE)) > ?
              AND br.preferred_datetime < ?
              AND (
                    LOWER(br.status) IN ('confirmed', 'approved')
                    OR (
                        LOWER(br.status) IN ('pending', 'new')
                        AND COALESCE(br.hold_expires_at, DATE_ADD(br.created_at, INTERVAL 30 MINUTE)) > ?
                    )
              )
            ORDER BY br.preferred_datetime ASC, br.id ASC
            LIMIT 1
            FOR UPDATE");
        $conflictStmt->execute([$startValue, $endValue, $nowValue]);
        $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);
        if ($conflict) {
            $pdo->rollBack();
            bookingRespond(['success' => false, 'error' => 'Выбранное время уже занято'], 409);
        }

        $insertStmt = $pdo->prepare("INSERT INTO booking_requests (
            request_number,
            service_type_id,
            crm_client_id,
            client_name,
            client_email,
            client_phone,
            client_company,
            preferred_datetime,
            preferred_end_at,
            total_duration_minutes,
            total_price_rub,
            hold_expires_at,
            confirmed_at,
            notes,
            admin_comment,
            status,
            source,
            page_url,
            widget_profile_id,
            created_by,
            reviewed_by,
            reviewed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, 'pending', ?, ?, ?, ?, NULL, NULL)");
        $insertStmt->execute([
            $requestNumber,
            (int)($primaryService['id'] ?? 0),
            $crmClientId,
            $clientName,
            $clientEmail,
            $clientPhone,
            $clientCompany,
            $startValue,
            $endValue,
            $totalDurationMinutes,
            round($totalPriceRub, 2),
            $holdExpiresAt->format('Y-m-d H:i:s'),
            $notes,
            $source,
            $pageUrl,
            $widgetProfileId,
            $createdBy ? (int)$createdBy : null,
        ]);

        // Логируем аналитику виджета
        if ($widgetProfileId && $widgetProfileId > 0) {
            bookingLogWidgetAnalytics($pdo, $widgetProfileId, 'submit', $pageUrl, $_SERVER['HTTP_USER_AGENT'] ?? null);
        }

        $requestId = (int)$pdo->lastInsertId();

        $serviceInsertStmt = $pdo->prepare("INSERT INTO booking_request_services (
            booking_request_id,
            service_type_id,
            service_key,
            service_name,
            icon,
            duration_minutes,
            price_rub,
            discount_type,
            discount_value,
            effective_price_rub,
            sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($selectedServices as $index => $service) {
            $requestService = bookingServiceCatalogToRequestServiceRow($service, $requestId, $index + 1);
            $serviceInsertStmt->execute([
                $requestId,
                (int)($requestService['service_type_id'] ?? 0),
                (string)($requestService['service_key'] ?? ''),
                (string)($requestService['service_name'] ?? ''),
                (string)($requestService['icon'] ?? 'calendar'),
                (int)($requestService['duration_minutes'] ?? 0),
                round((float)($requestService['price_rub'] ?? 0), 2),
                (string)($requestService['discount_type'] ?? 'none'),
                round((float)($requestService['discount_value'] ?? 0), 2),
                round((float)($requestService['effective_price_rub'] ?? 0), 2),
                (int)($requestService['sort_order'] ?? ($index + 1)),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('bookingHandleCreate failed: ' . $e->getMessage());
        bookingRespond(['success' => false, 'error' => 'Не удалось создать заявку'], 500);
    }

    $freshRequest = bookingFetchBookingRequest($pdo, $requestId, $now);
    if (!$freshRequest) {
        $fallbackServices = [];
        foreach ($selectedServices as $index => $service) {
            $fallbackServices[] = bookingDecorateRequestServiceRow(bookingServiceCatalogToRequestServiceRow($service, $requestId, $index + 1));
        }

        $freshRequest = bookingComposeBookingRequest($pdo, [
            'id' => $requestId,
            'request_number' => $requestNumber,
            'service_type_id' => (int)($primaryService['id'] ?? 0),
            'crm_client_id' => $crmClientId,
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'client_phone' => $clientPhone,
            'client_company' => $clientCompany,
            'preferred_datetime' => $startValue,
            'preferred_end_at' => $endValue,
            'total_duration_minutes' => $totalDurationMinutes,
            'total_price_rub' => round($totalPriceRub, 2),
            'hold_expires_at' => $holdExpiresAt->format('Y-m-d H:i:s'),
            'confirmed_at' => null,
            'notes' => $notes,
            'admin_comment' => null,
            'status' => 'pending',
            'created_by' => $createdBy,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'service_type_key' => (string)($primaryService['service_key'] ?? ''),
            'service_type_name' => (string)($primaryService['service_name'] ?? ''),
            'service_type_icon' => (string)($primaryService['icon'] ?? 'calendar'),
            'created_by_name' => $currentUser['full_name'] ?? null,
            'reviewed_by_name' => null,
        ], $fallbackServices, $serviceCatalog, $now);
    }

    try {
        auditLog($pdo, 'booking.created', [
            'actor' => $currentUser,
            'target_type' => 'booking_request',
            'target_id' => (string)$requestId,
            'summary' => 'Создана заявка на запись ' . ($freshRequest['request_number'] ?? $requestNumber),
            'details' => [
                'request_number' => $freshRequest['request_number'] ?? $requestNumber,
                'service_type_id' => (int)($freshRequest['service_type_id'] ?? $primaryService['id'] ?? 0),
                'service_type_name' => $freshRequest['service_type_name'] ?? ($primaryService['service_name'] ?? null),
                'service_summary' => $freshRequest['service_summary'] ?? null,
                'service_count' => $freshRequest['service_count'] ?? count($selectedServices),
                'total_duration_minutes' => $freshRequest['total_duration_minutes'] ?? $totalDurationMinutes,
                'total_price_rub' => $freshRequest['total_price_rub'] ?? round($totalPriceRub, 2),
                'client_name' => $clientName,
                'client_email' => $clientEmail,
                'client_phone' => $clientPhone,
                'preferred_datetime' => $startValue,
                'preferred_end_at' => $endValue,
                'hold_expires_at' => $holdExpiresAt->format('Y-m-d H:i:s'),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('bookingHandleCreate audit failed: ' . $e->getMessage());
    }

    try {
        $recipientIds = bookingAdminRecipientIds($pdo);
        if ($recipientIds) {
            createNotifications($pdo, $recipientIds, [
                'sender_id' => $createdBy ? (int)$createdBy : null,
                'message' => 'Новая заявка на запись ' . ($freshRequest['request_number'] ?? $requestNumber),
                'type' => 'booking',
                'related_id' => $requestId,
                'allow_self' => false,
            ]);
        }
    } catch (Throwable $e) {
        error_log('bookingHandleCreate notification failed: ' . $e->getMessage());
    }

    // Отправляем уведомления
    try {
        $adminIds = bookingAdminRecipientIds($pdo);
        notificationServiceSendBooking($pdo, 'booking.created', $freshRequest, [
            'admin_ids' => $adminIds,
            'creator_id' => $createdBy ? (int)$createdBy : null,
        ]);
    } catch (Throwable $e) {
        error_log('bookingHandleCreate notification service failed: ' . $e->getMessage());
    }

    bookingRespond([
        'success' => true,
        'data' => $freshRequest,
        'message' => 'Заявка на запись создана и ожидает подтверждения',
    ], 201);
}

function bookingHandleDecision(PDO $pdo, ?array $currentUser, string $decision, ?DateTimeImmutable $now = null): void {
    require_once __DIR__ . '/license.php';
    if (!isFeatureEnabled($pdo, 'booking')) {
        bookingRespond(['success' => false, 'error' => 'Модуль записи недоступен на текущем тарифе. Обратитесь в поддержку для подключения.'], 403);
    }
    $now ??= new DateTimeImmutable('now');

    if (!$currentUser || !hasAdminAccess($currentUser)) {
        bookingRespond(['success' => false, 'error' => 'Только администраторы могут обрабатывать заявки'], 403);
    }

    $data = bookingReadJsonBody();
    $requestId = (int)($data['request_id'] ?? $data['id'] ?? 0);
    $adminComment = bookingNormalizeString($data['admin_comment'] ?? $data['comment'] ?? null, 5000);

    if ($requestId <= 0) {
        bookingRespond(['success' => false, 'error' => 'Укажите id заявки'], 400);
    }

    $decision = strtolower(trim($decision));
    if ($decision === 'approve') {
        $decision = 'confirm';
    }

    if ($decision !== 'confirm' && $decision !== 'reject') {
        bookingRespond(['success' => false, 'error' => 'Укажите действие confirm или reject'], 400);
    }

    $nowValue = $now->format('Y-m-d H:i:s');
    $targetStatus = $decision === 'confirm' ? 'confirmed' : 'rejected';
    $confirmedAt = $decision === 'confirm' ? $nowValue : null;

    $stmt = $pdo->prepare("UPDATE booking_requests
        SET status = ?,
            admin_comment = ?,
            reviewed_by = ?,
            reviewed_at = ?,
            confirmed_at = ?,
            hold_expires_at = NULL
        WHERE id = ?
          AND LOWER(status) IN ('new', 'pending')
          AND COALESCE(hold_expires_at, DATE_ADD(created_at, INTERVAL 30 MINUTE)) > ?");
    $stmt->execute([
        $targetStatus,
        $adminComment,
        (int)$currentUser['id'],
        $nowValue,
        $confirmedAt,
        $requestId,
        $nowValue,
    ]);

    if ((int)$stmt->rowCount() <= 0) {
        $existing = bookingFetchBookingRequest($pdo, $requestId, $now);
        if (!$existing) {
            bookingRespond(['success' => false, 'error' => 'Заявка не найдена'], 404);
        }

        if (($existing['status'] ?? '') === 'expired') {
            bookingRespond(['success' => false, 'error' => 'Срок ожидания заявки истек'], 409);
        }

        bookingRespond(['success' => false, 'error' => 'Заявка уже обработана'], 409);
    }

    $freshRequest = bookingFetchBookingRequest($pdo, $requestId, $now);
    if (!$freshRequest) {
        bookingRespond(['success' => false, 'error' => 'Заявка не найдена'], 404);
    }

    try {
        auditLog($pdo, $targetStatus === 'confirmed' ? 'booking.confirmed' : 'booking.rejected', [
            'actor' => $currentUser,
            'target_type' => 'booking_request',
            'target_id' => (string)$requestId,
            'summary' => 'Заявка ' . ($freshRequest['request_number'] ?? $requestId) . ' ' . ($targetStatus === 'confirmed' ? 'подтверждена' : 'отклонена'),
            'details' => [
                'status' => $targetStatus,
                'request_number' => $freshRequest['request_number'] ?? null,
                'service_type_name' => $freshRequest['service_type_name'] ?? null,
                'service_summary' => $freshRequest['service_summary'] ?? null,
                'admin_comment' => $adminComment,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('bookingHandleDecision audit failed: ' . $e->getMessage());
    }

    $creatorId = isset($freshRequest['created_by']) ? (int)$freshRequest['created_by'] : 0;
    if ($creatorId > 0 && $creatorId !== (int)$currentUser['id']) {
        try {
            createNotification($pdo, [
                'user_id' => $creatorId,
                'sender_id' => (int)$currentUser['id'],
                'message' => 'Ваша заявка на запись ' . ($freshRequest['request_number'] ?? $requestId) . ' ' . ($targetStatus === 'confirmed' ? 'подтверждена' : 'отклонена'),
                'type' => 'booking',
                'related_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            error_log('bookingHandleDecision notification failed: ' . $e->getMessage());
        }
    }

    // Отправляем уведомления
    try {
        notificationServiceSendBooking($pdo, $targetStatus === 'confirmed' ? 'booking.confirmed' : 'booking.rejected', $freshRequest, [
            'creator_id' => $creatorId > 0 ? $creatorId : null,
        ]);
    } catch (Throwable $e) {
        error_log('bookingHandleDecision notification service failed: ' . $e->getMessage());
    }

    bookingRespond([
        'success' => true,
        'data' => $freshRequest,
        'message' => $targetStatus === 'confirmed' ? 'Заявка подтверждена' : 'Заявка отклонена',
    ]);
}

function bookingHandleServiceUpsert(PDO $pdo, ?array $currentUser): void {
    require_once __DIR__ . '/license.php';
    if (!isFeatureEnabled($pdo, 'booking')) {
        bookingRespond(['success' => false, 'error' => 'Модуль записи недоступен на текущем тарифе. Обратитесь в поддержку для подключения.'], 403);
    }
    if (!$currentUser || !hasAdminAccess($currentUser)) {
        bookingRespond(['success' => false, 'error' => 'Только администраторы могут управлять услугами'], 403);
    }

    $data = bookingReadJsonBody();
    $id = (int)($data['id'] ?? 0);

    $typeKey = bookingNormalizeString($data['type_key'] ?? $data['service_key'] ?? null, 64);
    $typeName = bookingNormalizeString($data['type_name'] ?? $data['service_name'] ?? $data['name'] ?? null, 190);
    $icon = bookingNormalizeString($data['icon'] ?? null, 50) ?? 'calendar';
    $description = bookingNormalizeString($data['description'] ?? null, 1000) ?? '';
    $durationMinutes = max(0, (int)($data['duration_minutes'] ?? 0));
    $priceRub = round(max(0, (float)($data['price_rub'] ?? 0)), 2);

    $discountType = strtolower(trim((string)($data['discount_type'] ?? 'none')));
    if (!in_array($discountType, ['none', 'percent', 'amount'], true)) {
        $discountType = 'none';
    }
    $discountValue = round(max(0, (float)($data['discount_value'] ?? 0)), 2);
    $promoLabel = bookingNormalizeString($data['promo_label'] ?? null, 120);
    $sortOrder = (int)($data['sort_order'] ?? 0);
    $isActive = (int)($data['is_active'] ?? 1) === 1 ? 1 : 0;

    if (!$typeName) {
        bookingRespond(['success' => false, 'error' => 'Укажите название услуги'], 400);
    }

    if ($durationMinutes <= 0) {
        bookingRespond(['success' => false, 'error' => 'Укажите длительность услуги (мин)'], 400);
    }

    if (!$typeKey) {
        $typeKey = bookingNormalizeServiceKey($typeName);
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE booking_service_types
                SET type_key = ?,
                    type_name = ?,
                    icon = ?,
                    description = ?,
                    duration_minutes = ?,
                    price_rub = ?,
                    discount_type = ?,
                    discount_value = ?,
                    promo_label = ?,
                    sort_order = ?,
                    is_active = ?
                WHERE id = ?");
            $stmt->execute([
                $typeKey,
                $typeName,
                $icon,
                $description,
                $durationMinutes,
                $priceRub,
                $discountType,
                $discountValue,
                $promoLabel,
                $sortOrder,
                $isActive,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO booking_service_types
                (type_key, type_name, icon, description, duration_minutes, price_rub, discount_type, discount_value, promo_label, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $typeKey,
                $typeName,
                $icon,
                $description,
                $durationMinutes,
                $priceRub,
                $discountType,
                $discountValue,
                $promoLabel,
                $sortOrder,
                $isActive,
            ]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('bookingHandleServiceUpsert failed: ' . $e->getMessage());
        bookingRespond(['success' => false, 'error' => 'Не удалось сохранить услугу'], 500);
    }

    $service = null;
    try {
        $stmt = $pdo->prepare("SELECT id, type_key, type_name, icon, description, duration_minutes, price_rub, discount_type, discount_value, promo_label, sort_order, is_active
            FROM booking_service_types WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $service = null;
    }

    bookingRespond(['success' => true, 'data' => $service ? bookingAliasServiceRow(bookingDecorateServiceRow($service)) : null]);
}

function bookingHandleServiceDelete(PDO $pdo, ?array $currentUser): void {
    if (!$currentUser || !hasAdminAccess($currentUser)) {
        bookingRespond(['success' => false, 'error' => 'Только администраторы могут управлять услугами'], 403);
    }

    $data = bookingReadJsonBody();
    $id = (int)($data['id'] ?? $data['service_type_id'] ?? 0);
    if ($id <= 0) {
        bookingRespond(['success' => false, 'error' => 'Укажите id услуги'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM booking_requests WHERE service_type_id = ?');
        $stmt->execute([$id]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            bookingRespond(['success' => false, 'error' => 'Нельзя удалить услугу: она используется в заявках. Деактивируйте её.'], 409);
        }

        $pdo->prepare('DELETE FROM booking_request_services WHERE service_type_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM booking_service_types WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        error_log('bookingHandleServiceDelete failed: ' . $e->getMessage());
        bookingRespond(['success' => false, 'error' => 'Не удалось удалить услугу'], 500);
    }

    bookingRespond(['success' => true, 'data' => ['id' => $id]]);
}

function bookingHandleWorkingHoursUpsert(PDO $pdo, ?array $currentUser): void {
    require_once __DIR__ . '/license.php';
    if (!isFeatureEnabled($pdo, 'booking')) {
        bookingRespond(['success' => false, 'error' => 'Модуль записи недоступен на текущем тарифе. Обратитесь в поддержку для подключения.'], 403);
    }
    if (!$currentUser || !hasAdminAccess($currentUser)) {
        bookingRespond(['success' => false, 'error' => 'Только администраторы могут управлять расписанием'], 403);
    }

    $data = bookingReadJsonBody();
    $weekday = (int)($data['weekday'] ?? 0);
    if ($weekday < 1 || $weekday > 7) {
        bookingRespond(['success' => false, 'error' => 'Укажите weekday 1-7'], 400);
    }

    $isOpen = (int)($data['is_open'] ?? 0) === 1 ? 1 : 0;
    $opensAt = bookingNormalizeString($data['opens_at'] ?? null, 16);
    $closesAt = bookingNormalizeString($data['closes_at'] ?? null, 16);
    $breakStarts = bookingNormalizeString($data['break_starts_at'] ?? null, 16);
    $breakEnds = bookingNormalizeString($data['break_ends_at'] ?? null, 16);
    $note = bookingNormalizeString($data['note'] ?? null, 255);
    $sortOrder = (int)($data['sort_order'] ?? $weekday);

    // normalize to TIME-compatible strings
    $normTime = static function (?string $v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v) !== 1) return null;
        return strlen($v) === 5 ? ($v . ':00') : $v;
    };
    $opensAt = $normTime($opensAt);
    $closesAt = $normTime($closesAt);
    $breakStarts = $normTime($breakStarts);
    $breakEnds = $normTime($breakEnds);

    if ($isOpen && (!$opensAt || !$closesAt)) {
        bookingRespond(['success' => false, 'error' => 'Укажите opens_at и closes_at'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM booking_working_hours WHERE weekday = ? LIMIT 1');
        $stmt->execute([$weekday]);
        $id = (int)($stmt->fetchColumn() ?: 0);

        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE booking_working_hours
                SET is_open = ?, opens_at = ?, closes_at = ?, break_starts_at = ?, break_ends_at = ?, note = ?, sort_order = ?
                WHERE id = ?');
            $stmt->execute([$isOpen, $opensAt, $closesAt, $breakStarts, $breakEnds, $note, $sortOrder, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO booking_working_hours (weekday, is_open, opens_at, closes_at, break_starts_at, break_ends_at, note, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$weekday, $isOpen, $opensAt, $closesAt, $breakStarts, $breakEnds, $note, $sortOrder]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('bookingHandleWorkingHoursUpsert failed: ' . $e->getMessage());
        bookingRespond(['success' => false, 'error' => 'Не удалось сохранить расписание'], 500);
    }

    $hours = bookingFetchWorkingHours($pdo);
    bookingRespond(['success' => true, 'data' => $hours]);
}

/**
 * Рассчитать доступные слоты на дату
 */
function bookingCalculateSlots(PDO $pdo, string $date, array $serviceIds): array {
    $serviceCatalog = bookingLoadServiceCatalog($pdo);
    $totalDuration = 0;
    foreach ($serviceIds as $sid) {
        if (isset($serviceCatalog[$sid])) {
            $totalDuration += max(0, (int)($serviceCatalog[$sid]['duration_minutes'] ?? 0));
        }
    }
    if ($totalDuration <= 0) {
        $totalDuration = 30;
    }

    try {
        $dayStart = new DateTimeImmutable($date . ' 00:00:00');
    } catch (Throwable $e) {
        return [];
    }
    $weekday = (int)$dayStart->format('N');

    $workingHours = bookingWorkingHoursByWeekday(bookingFetchWorkingHours($pdo));
    $daySchedule = $workingHours[$weekday] ?? null;
    if (!$daySchedule || empty($daySchedule['is_open'])) {
        return [];
    }

    $openMin = bookingTimeToMinutes($daySchedule['opens_at'] ?? null) ?? 0;
    $closeMin = bookingTimeToMinutes($daySchedule['closes_at'] ?? null) ?? (24 * 60);
    $breakStart = bookingTimeToMinutes($daySchedule['break_starts_at'] ?? null);
    $breakEnd = bookingTimeToMinutes($daySchedule['break_ends_at'] ?? null);

    // Получаем подтвержденные и pending заявки на этот день
    $dayStartStr = $dayStart->format('Y-m-d H:i:s');
    $dayEndStr = $dayStart->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    $nowStr = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT preferred_datetime, preferred_end_at, total_duration_minutes, status, hold_expires_at, created_at
        FROM booking_requests
        WHERE preferred_datetime IS NOT NULL
          AND preferred_datetime < ?
          AND COALESCE(preferred_end_at, DATE_ADD(preferred_datetime, INTERVAL GREATEST(total_duration_minutes, 0) MINUTE)) > ?
          AND (
                LOWER(status) IN ('confirmed', 'approved')
                OR (
                    LOWER(status) IN ('pending', 'new')
                    AND COALESCE(hold_expires_at, DATE_ADD(created_at, INTERVAL 30 MINUTE)) > ?
                )
          )
    ");
    $stmt->execute([$dayEndStr, $dayStartStr, $nowStr]);
    $busyIntervals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = strtotime((string)$row['preferred_datetime']);
        $e = strtotime((string)($row['preferred_end_at'] ?? $row['preferred_datetime'])) + max(0, (int)($row['total_duration_minutes'] ?? 0)) * 60;
        if ($s && $e) {
            $busyIntervals[] = ['s' => $s, 'e' => $e];
        }
    }

    $slots = [];
    $step = 30; // шаг слотов в минутах
    for ($min = $openMin; $min + $totalDuration <= $closeMin; $min += $step) {
        $slotStart = $dayStart->setTime((int)floor($min / 60), $min % 60);
        $slotEnd = $slotStart->modify('+' . $totalDuration . ' minutes');

        // Пропускаем перерыв
        if ($breakStart !== null && $breakEnd !== null && $breakEnd > $breakStart) {
            $slotStartMin = ((int)$slotStart->format('H') * 60) + (int)$slotStart->format('i');
            $slotEndMin = ((int)$slotEnd->format('H') * 60) + (int)$slotEnd->format('i');
            if ($slotStartMin < $breakEnd && $slotEndMin > $breakStart) {
                continue;
            }
        }

        // Пропускаем прошедшее время
        if ($slotStart <= new DateTimeImmutable('now')) {
            continue;
        }

        // Проверяем конфликты
        $conflict = false;
        $slotStartTs = $slotStart->getTimestamp();
        $slotEndTs = $slotEnd->getTimestamp();
        foreach ($busyIntervals as $busy) {
            if ($slotStartTs < $busy['e'] && $slotEndTs > $busy['s']) {
                $conflict = true;
                break;
            }
        }

        if (!$conflict) {
            $slots[] = [
                'time' => $slotStart->format('H:i'),
                'datetime' => $slotStart->format('Y-m-d H:i:s'),
                'duration_minutes' => $totalDuration,
            ];
        }
    }

    return $slots;
}

/**
 * Записать аналитику виджета
 */
function bookingLogWidgetAnalytics(PDO $pdo, int $widgetProfileId, string $event, ?string $pageUrl = null, ?string $pageTitle = null, ?string $referrer = null, ?string $sessionId = null, ?string $userAgent = null): void {
    try {
        $uaHash = $userAgent ? hash('sha256', $userAgent) : null;
        $ipHash = !empty($_SERVER['REMOTE_ADDR']) ? hash('sha256', $_SERVER['REMOTE_ADDR']) : null;
        $stmt = $pdo->prepare("
            INSERT INTO booking_widget_analytics (widget_profile_id, event, page_url, page_title, referrer, user_agent_hash, session_id, ip_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $widgetProfileId,
            $event,
            $pageUrl ? substr($pageUrl, 0, 1000) : null,
            $pageTitle ? substr($pageTitle, 0, 500) : null,
            $referrer ? substr($referrer, 0, 1000) : null,
            $uaHash ? substr($uaHash, 0, 64) : null,
            $sessionId ? substr($sessionId, 0, 64) : null,
            $ipHash ? substr($ipHash, 0, 64) : null,
        ]);
    } catch (Throwable $e) {
        error_log('bookingLogWidgetAnalytics failed: ' . $e->getMessage());
    }
}

function handleBooking(string $method): void {
    $pdo = getPDO();

    try {
        ensureBookingModuleSchema($pdo);
    } catch (Throwable $e) {
        error_log('booking.php schema ensure failed: ' . $e->getMessage());
    }

    $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
    $now = new DateTimeImmutable('now');

    try {
        bookingExpirePendingRequests($pdo, $now);
    } catch (Throwable $e) {
        error_log('booking.php pending expiration failed: ' . $e->getMessage());
    }

    // Обрабатываем напоминания
    try {
        notificationServiceProcessReminders($pdo, $now);
    } catch (Throwable $e) {
        error_log('booking.php reminders failed: ' . $e->getMessage());
    }

    if ($method === 'GET') {
        $getAction = strtolower(trim((string)($_GET['action'] ?? '')));

        // Публичная конфигурация виджета (без авторизации)
        if ($getAction === 'widget-analytics') {
            // Логирование аналитики виджета (без авторизации)
            $data = json_decode(file_get_contents('php://input'), true);
            $widgetProfileId = (int)($data['widget_profile_id'] ?? 0);
            $event = bookingNormalizeString($data['event'] ?? null, 32);
            $analyticsPageUrl = bookingNormalizeString($data['page_url'] ?? null, 1000);
            $analyticsPageTitle = bookingNormalizeString($data['page_title'] ?? null, 500);
            $analyticsReferrer = bookingNormalizeString($data['referrer'] ?? null, 1000);
            $analyticsSessionId = bookingNormalizeString($data['session_id'] ?? null, 64);
            if ($widgetProfileId > 0 && $event) {
                bookingLogWidgetAnalytics($pdo, $widgetProfileId, $event, $analyticsPageUrl, $analyticsPageTitle, $analyticsReferrer, $analyticsSessionId, $_SERVER['HTTP_USER_AGENT'] ?? null);
            }
            bookingRespond(['success' => true]);
        }

        if ($getAction === 'widget-analytics-report') {
            // Admin-only analytics report
            if (!$currentUser || !hasAdminAccess($currentUser)) {
                bookingRespond(['success' => false, 'error' => 'Требуется авторизация'], 403);
            }
            $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
            $profileId = isset($_GET['profile_id']) && is_numeric($_GET['profile_id']) ? (int)$_GET['profile_id'] : null;
            $params = [];
            $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $days;
            if ($profileId) {
                $where .= " AND widget_profile_id = ?";
                $params[] = $profileId;
            }
            $stmt = $pdo->prepare("SELECT event, COUNT(*) as cnt FROM booking_widget_analytics {$where} GROUP BY event");
            $stmt->execute($params);
            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt2 = $pdo->prepare("SELECT DATE(created_at) as date, event, COUNT(*) as cnt FROM booking_widget_analytics {$where} GROUP BY DATE(created_at), event ORDER BY date DESC");
            $stmt2->execute($params);
            $daily = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            bookingRespond(['success' => true, 'data' => ['summary' => $summary, 'daily' => $daily, 'days' => $days]]);
        }

        if ($getAction === 'widget-config') {
            $profileSlug = trim((string)($_GET['profile'] ?? ''));
            $profile = null;
            if ($profileSlug !== '') {
                $stmt = $pdo->prepare("SELECT id, name, slug, type, config_json, allowed_services_json, custom_css_url FROM site_widget_profiles WHERE slug = ? LIMIT 1");
                $stmt->execute([$profileSlug]);
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$profile) {
                $stmt = $pdo->query("SELECT id, name, slug, type, config_json, allowed_services_json, custom_css_url FROM site_widget_profiles WHERE is_active = 1 LIMIT 1");
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            $config = [];
            if ($profile) {
                $config = json_decode((string)($profile['config_json'] ?? '{}'), true) ?: [];
                $config['profile_id'] = (int)$profile['id'];
                $config['profile_slug'] = $profile['slug'];
                $config['profile_name'] = $profile['name'];
                $config['type'] = $profile['type'] ?? 'chat';
                $config['allowed_service_ids'] = json_decode((string)($profile['allowed_services_json'] ?? '[]'), true) ?: [];
                $config['custom_css_url'] = $profile['custom_css_url'] ?? null;
            }

            $companyName = 'TaskFlow';
            $logoUrl = '';
            try {
                $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE `key` IN ('company_name', 'logo')");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($row['key'] === 'company_name') $companyName = (string)$row['value'];
                    if ($row['key'] === 'logo') $logoUrl = (string)$row['value'];
                }
            } catch (Throwable $e) {
                // ignore
            }

            $serviceTypes = bookingFetchServiceTypes($pdo);
            if (!empty($config['allowed_service_ids'])) {
                $allowed = array_map('intval', (array)$config['allowed_service_ids']);
                $serviceTypes = array_values(array_filter($serviceTypes, static fn($s) => in_array((int)($s['id'] ?? 0), $allowed, true)));
            }

            bookingRespond(['success' => true, 'data' => [
                'config' => $config,
                'company_name' => $companyName,
                'logo_url' => $logoUrl,
                'service_types' => $serviceTypes,
                'working_hours' => bookingFetchWorkingHours($pdo),
                'hold_minutes' => 30,
                'server_time' => $now->format('Y-m-d H:i:s'),
                'powered_by' => [
                    'enabled' => empty($config['hide_branding']),
                    'text' => 'Работает на базе TaskFlow',
                    'link' => 'https://taskflow.pro',
                ],
            ]]);
        }

        // Доступные слоты на дату
        if ($getAction === 'slots') {
            $date = trim((string)($_GET['date'] ?? ''));
            $serviceIds = [];
            if (!empty($_GET['service_ids'])) {
                $raw = is_array($_GET['service_ids']) ? $_GET['service_ids'] : explode(',', (string)$_GET['service_ids']);
                foreach ($raw as $id) {
                    $id = (int)$id;
                    if ($id > 0) $serviceIds[] = $id;
                }
            }

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                bookingRespond(['success' => false, 'error' => 'Укажите date в формате YYYY-MM-DD'], 400);
            }

            $slots = bookingCalculateSlots($pdo, $date, $serviceIds);
            bookingRespond(['success' => true, 'data' => ['date' => $date, 'slots' => $slots]]);
        }

        $serviceTypes = bookingFetchServiceTypes($pdo);
        $workingHours = bookingFetchWorkingHours($pdo);
        $canManage = (bool)($currentUser && hasAdminAccess($currentUser));
        $data = [
            'services' => $serviceTypes,
            'service_types' => $serviceTypes,
            'working_hours' => $workingHours,
            'hold_minutes' => 30,
            'server_time' => $now->format('Y-m-d H:i:s'),
            'can_manage' => $canManage,
        ];

        if ($currentUser) {
            $data['current_user'] = [
                'id' => (int)($currentUser['id'] ?? 0),
                'login' => (string)($currentUser['login'] ?? ''),
                'role' => (string)($currentUser['role'] ?? ''),
            ];
        }

        if ($canManage) {
            $requests = bookingFetchBookingRequests($pdo, $now);
            $stats = bookingFetchBookingStats($pdo);
            $data['requests'] = $requests;
            $data['booking_requests'] = $requests;
            $data['stats'] = $stats;
            $data['booking_stats'] = $stats;
        }

        bookingRespond(['success' => true, 'data' => $data]);
    }

    if ($method !== 'POST') {
        bookingRespond(['success' => false, 'error' => 'Метод не поддерживается'], 405);
    }

    $data = bookingReadJsonBody();
    $action = strtolower(trim((string)($data['action'] ?? ($_GET['action'] ?? $data['decision'] ?? ''))));

    // Public widget analytics logging (no auth required)
    if ($action === 'widget-analytics') {
        $widgetProfileId = (int)($data['widget_profile_id'] ?? 0);
        $event = bookingNormalizeString($data['event'] ?? null, 32);
        $analyticsPageUrl = bookingNormalizeString($data['page_url'] ?? null, 1000);
        $analyticsPageTitle = bookingNormalizeString($data['page_title'] ?? null, 500);
        $analyticsReferrer = bookingNormalizeString($data['referrer'] ?? null, 1000);
        $analyticsSessionId = bookingNormalizeString($data['session_id'] ?? null, 64);
        if ($widgetProfileId > 0 && $event) {
            bookingLogWidgetAnalytics($pdo, $widgetProfileId, $event, $analyticsPageUrl, $analyticsPageTitle, $analyticsReferrer, $analyticsSessionId, $_SERVER['HTTP_USER_AGENT'] ?? null);
        }
        bookingRespond(['success' => true]);
    }

    if ($action === 'approve') {
        $action = 'confirm';
    }

    if ($action === 'service_upsert') {
        bookingHandleServiceUpsert($pdo, $currentUser);
    }

    if ($action === 'service_delete') {
        bookingHandleServiceDelete($pdo, $currentUser);
    }

    if ($action === 'working_hours_upsert') {
        bookingHandleWorkingHoursUpsert($pdo, $currentUser);
    }

    if ($action === 'confirm' || $action === 'reject') {
        bookingHandleDecision($pdo, $currentUser, $action, $now);
    }

    bookingHandleCreate($pdo, $currentUser, $now);
}

handleBooking($_SERVER['REQUEST_METHOD'] ?? 'GET');
