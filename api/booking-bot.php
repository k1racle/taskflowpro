<?php
/**
 * api/booking-bot.php - Telegram chat-bot for booking appointments.
 * Stateless machine using booking_bot_sessions table.
 */

require_once __DIR__ . '/notification-service.php';

/**
 * Main entry point called from integrations webhook dispatcher.
 * Returns true if the message was handled by the booking bot
 * (i.e. integrations.php should NOT create a HelpDesk ticket).
 */
function handleBookingBot(PDO $pdo, array $payload): bool {
    $chatId = null;
    $fromId = null;
    $text = '';
    $messageId = null;

    // Handle callback_query (inline keyboard clicks)
    if (!empty($payload['callback_query'])) {
        $cb = $payload['callback_query'];
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $fromId = (string)($cb['from']['id'] ?? '');
        $text = (string)($cb['data'] ?? '');
        $messageId = (string)($cb['message']['message_id'] ?? '');
    } elseif (!empty($payload['message'])) {
        $msg = $payload['message'];
        $chatId = (string)($msg['chat']['id'] ?? '');
        $fromId = (string)($msg['from']['id'] ?? '');
        $text = (string)($msg['text'] ?? ($msg['caption'] ?? ''));
        $messageId = (string)($msg['message_id'] ?? '');
    }

    if (!$chatId) return false;

    // Check bot enabled
    $enabled = false;
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'booking_bot_telegram_enabled' LIMIT 1");
        $enabled = trim((string)$stmt->fetchColumn()) === '1';
    } catch (Throwable $e) {}
    if (!$enabled) return false;

    // Check if user wants to book or has active session
    $session = bookingBotGetOrCreateSession($pdo, $chatId);
    $step = $session['step'] ?? 'idle';
    $lowerText = mb_strtolower(trim($text));

    $isBookingCommand = in_array($lowerText, ['/book', '/start', 'запись', 'booking'], true);
    $hasActiveSession = $step !== 'idle';

    if (!$isBookingCommand && !$hasActiveSession) {
        // Not a booking interaction; let HelpDesk handle it
        return false;
    }

    // Ensure tables exist (best-effort)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_bot_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(16) NOT NULL DEFAULT 'telegram',
                external_chat_id VARCHAR(128) NOT NULL,
                step VARCHAR(32) NOT NULL DEFAULT 'idle',
                data_json LONGTEXT NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_channel_chat (channel, external_chat_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_bot_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NULL,
                channel VARCHAR(16) NOT NULL DEFAULT 'telegram',
                external_chat_id VARCHAR(128) NOT NULL,
                direction VARCHAR(8) NOT NULL,
                message TEXT NULL,
                step VARCHAR(32) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_chat (channel, external_chat_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}

    bookingBotLog($pdo, $session['id'] ?? null, $chatId, 'in', $text, $step);

    // Reset on /cancel or timeout
    if ($lowerText === '/cancel') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return true;
    }

    // Route by step
    switch ($step) {
        case 'idle':
            if ($isBookingCommand) {
                bookingBotStepWelcome($pdo, $session, $chatId);
            } else {
                // Should not reach here, but safety fallback
                return false;
            }
            break;
        case 'services':
            bookingBotStepServices($pdo, $session, $chatId, $text);
            break;
        case 'date':
            bookingBotStepDate($pdo, $session, $chatId, $text);
            break;
        case 'time':
            bookingBotStepTime($pdo, $session, $chatId, $text);
            break;
        case 'contacts':
            bookingBotStepContacts($pdo, $session, $chatId, $text);
            break;
        case 'confirm':
            bookingBotStepConfirm($pdo, $session, $chatId, $text);
            break;
        default:
            bookingBotSetStep($pdo, $chatId, 'idle', []);
            bookingBotSend($pdo, $chatId, 'Произошла ошибка. Напишите /book чтобы начать заново.');
    }

    return true;
}

/* ── Session helpers ─────────────────────────────────────────── */

function bookingBotGetOrCreateSession(PDO $pdo, string $chatId): array {
    $stmt = $pdo->prepare("SELECT * FROM booking_bot_sessions WHERE channel = 'telegram' AND external_chat_id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $stmt = $pdo->prepare("INSERT INTO booking_bot_sessions (channel, external_chat_id, step, data_json) VALUES ('telegram', ?, 'idle', ?)");
    $stmt->execute([$chatId, json_encode([], JSON_UNESCAPED_UNICODE)]);
    return [
        'id' => (int)$pdo->lastInsertId(),
        'channel' => 'telegram',
        'external_chat_id' => $chatId,
        'step' => 'idle',
        'data_json' => '[]',
    ];
}

function bookingBotSetStep(PDO $pdo, string $chatId, string $step, array $data): void {
    $stmt = $pdo->prepare("UPDATE booking_bot_sessions SET step = ?, data_json = ?, expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE channel = 'telegram' AND external_chat_id = ?");
    $stmt->execute([$step, json_encode($data, JSON_UNESCAPED_UNICODE), $chatId]);
}

function bookingBotGetData(array $session): array {
    $raw = $session['data_json'] ?? '[]';
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bookingBotLog(PDO $pdo, ?int $sessionId, string $chatId, string $direction, string $message, ?string $step = null): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO booking_bot_logs (session_id, channel, external_chat_id, direction, message, step) VALUES (?, 'telegram', ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $chatId, $direction, $message, $step]);
    } catch (Throwable $e) {
        error_log('bookingBotLog error: ' . $e->getMessage());
    }
}

/* ── Steps ───────────────────────────────────────────────────── */

function bookingBotStepWelcome(PDO $pdo, array $session, string $chatId): void {
    $welcome = 'Здравствуйте! Выберите услугу для записи:';
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'booking_bot_welcome_text' LIMIT 1");
        $val = $stmt->fetchColumn();
        if ($val) $welcome = (string)$val;
    } catch (Throwable $e) {}

    $services = bookingBotLoadServices($pdo);
    if (!$services) {
        bookingBotSend($pdo, $chatId, 'К сожалению, в данный момент услуги недоступны для записи.');
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        return;
    }

    $keyboard = [];
    foreach ($services as $svc) {
        $keyboard[] = [['text' => $svc['service_name'] . ' (' . $svc['price_rub'] . '₽)', 'callback_data' => 'svc_' . $svc['id']]];
    }
    $keyboard[] = [['text' => '❌ Отмена', 'callback_data' => 'cancel']];

    bookingBotSend($pdo, $chatId, $welcome, ['inline_keyboard' => $keyboard]);
    bookingBotSetStep($pdo, $chatId, 'services', ['service_ids' => []]);
}

function bookingBotStepServices(PDO $pdo, array $session, string $chatId, string $text): void {
    $data = bookingBotGetData($session);
    $serviceIds = $data['service_ids'] ?? [];

    if ($text === 'cancel') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return;
    }

    if (str_starts_with($text, 'svc_')) {
        $id = (int)substr($text, 4);
        if (!in_array($id, $serviceIds, true)) {
            $serviceIds[] = $id;
        }
        $data['service_ids'] = $serviceIds;
        bookingBotSetStep($pdo, $chatId, 'services', $data);

        $services = bookingBotLoadServices($pdo);
        $selectedNames = [];
        foreach ($services as $s) {
            if (in_array((int)$s['id'], $serviceIds, true)) {
                $selectedNames[] = $s['service_name'];
            }
        }

        $keyboard = [];
        foreach ($services as $svc) {
            $prefix = in_array((int)$svc['id'], $serviceIds, true) ? '✅ ' : '';
            $keyboard[] = [['text' => $prefix . $svc['service_name'] . ' (' . $svc['price_rub'] . '₽)', 'callback_data' => 'svc_' . $svc['id']]];
        }
        $keyboard[] = [['text' => '✅ Готово', 'callback_data' => 'done']];
        $keyboard[] = [['text' => '❌ Отмена', 'callback_data' => 'cancel']];

        $msg = 'Выбрано: ' . ($selectedNames ? implode(', ', $selectedNames) : 'ничего') . "\n\nВыберите ещё или нажмите Готово:";
        bookingBotSend($pdo, $chatId, $msg, ['inline_keyboard' => $keyboard]);
        return;
    }

    if ($text === 'done') {
        if (!$serviceIds) {
            bookingBotSend($pdo, $chatId, 'Пожалуйста, выберите хотя бы одну услугу.');
            return;
        }
        $services = bookingBotLoadServices($pdo);
        $selected = [];
        foreach ($services as $s) {
            if (in_array((int)$s['id'], $serviceIds, true)) {
                $selected[] = $s;
            }
        }
        $data['services'] = $selected;

        // Build date keyboard (next 7 days)
        $keyboard = [];
        $now = new DateTimeImmutable('now');
        for ($i = 0; $i < 7; $i++) {
            $d = $now->modify("+{$i} days");
            $label = $d->format('d.m.Y') . ' (' . bookingBotWeekdayRu((int)$d->format('w')) . ')';
            $keyboard[] = [['text' => $label, 'callback_data' => 'date_' . $d->format('Y-m-d')]];
        }
        $keyboard[] = [['text' => '❌ Отмена', 'callback_data' => 'cancel']];

        bookingBotSend($pdo, $chatId, "Отлично! Теперь выберите дату:\n(также можно ввести дату в формате ДД.ММ.ГГГГ)", ['inline_keyboard' => $keyboard]);
        bookingBotSetStep($pdo, $chatId, 'date', $data);
        return;
    }

    // Unknown input
    bookingBotSend($pdo, $chatId, 'Пожалуйста, используйте кнопки ниже.');
}

function bookingBotStepDate(PDO $pdo, array $session, string $chatId, string $text): void {
    $data = bookingBotGetData($session);
    $dateStr = '';

    if ($text === 'cancel') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return;
    }

    if (str_starts_with($text, 'date_')) {
        $dateStr = substr($text, 5);
    } else {
        // Try parse DD.MM.YYYY
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($text), $m)) {
            $dateStr = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
    }

    if (!$dateStr) {
        bookingBotSend($pdo, $chatId, 'Пожалуйста, выберите дату из списка или введите в формате ДД.ММ.ГГГГ');
        return;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
    if (!$dt || $dt->format('Y-m-d') !== $dateStr) {
        bookingBotSend($pdo, $chatId, 'Некорректная дата. Попробуйте ещё раз.');
        return;
    }

    // Reject past dates
    $today = new DateTimeImmutable('today');
    if ($dt < $today) {
        bookingBotSend($pdo, $chatId, 'Нельзя выбрать прошедшую дату. Попробуйте ещё раз.');
        return;
    }

    $data['date'] = $dateStr;

    // Build time slots from working hours
    $slots = bookingBotBuildTimeSlots($pdo, $dt);
    if (!$slots) {
        bookingBotSend($pdo, $chatId, 'К сожалению, на эту дату нет свободных слотов. Выберите другую дату.');
        return;
    }

    $keyboard = [];
    $row = [];
    foreach ($slots as $slot) {
        $row[] = ['text' => $slot, 'callback_data' => 'time_' . $slot];
        if (count($row) >= 3) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    if ($row) $keyboard[] = $row;
    $keyboard[] = [['text' => '❌ Отмена', 'callback_data' => 'cancel']];

    bookingBotSend($pdo, $chatId, 'Выберите время:', ['inline_keyboard' => $keyboard]);
    bookingBotSetStep($pdo, $chatId, 'time', $data);
}

function bookingBotStepTime(PDO $pdo, array $session, string $chatId, string $text): void {
    $data = bookingBotGetData($session);

    if ($text === 'cancel') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return;
    }

    if (!str_starts_with($text, 'time_')) {
        bookingBotSend($pdo, $chatId, 'Пожалуйста, выберите время из списка.');
        return;
    }

    $time = substr($text, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        bookingBotSend($pdo, $chatId, 'Некорректное время. Попробуйте ещё раз.');
        return;
    }

    $data['time'] = $time;
    bookingBotSetStep($pdo, $chatId, 'contacts', $data);
    bookingBotSend($pdo, $chatId, "Введите ваше имя и телефон в одном сообщении, например:\nИван Иванов +7 900 123-45-67");
}

function bookingBotStepContacts(PDO $pdo, array $session, string $chatId, string $text): void {
    $data = bookingBotGetData($session);

    if ($text === 'cancel') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return;
    }

    // Try to extract phone (7+ digits) and name (everything else)
    $phone = '';
    $name = '';

    // Look for + and digits
    if (preg_match('/(\+?[\d\s\-\(\)]+)/', $text, $m)) {
        $phoneRaw = preg_replace('/\D+/', '', $m[1]);
        if (strlen($phoneRaw) >= 7) {
            $phone = $m[1];
            $name = trim(str_replace($m[1], '', $text));
        }
    }

    if (!$phone) {
        bookingBotSend($pdo, $chatId, 'Не удалось распознать телефон. Пожалуйста, введите имя и телефон, например:\nИван Иванов +7 900 123-45-67');
        return;
    }

    if (!$name) {
        $name = 'Клиент Telegram';
    }

    $data['name'] = $name;
    $data['phone'] = $phone;

    // Build summary
    $date = $data['date'] ?? '';
    $time = $data['time'] ?? '';
    $services = $data['services'] ?? [];
    $svcNames = array_map(fn($s) => $s['service_name'] ?? '', $services);
    $totalPrice = array_sum(array_map(fn($s) => (float)($s['price_rub'] ?? 0), $services));

    $summary = "📋 Проверьте данные:\n";
    $summary .= "👤 Имя: {$name}\n";
    $summary .= "📞 Телефон: {$phone}\n";
    $summary .= "📅 Дата: " . date('d.m.Y', strtotime($date)) . "\n";
    $summary .= "⏰ Время: {$time}\n";
    $summary .= "🛎 Услуги: " . implode(', ', $svcNames) . "\n";
    $summary .= "💰 Сумма: " . number_format($totalPrice, 2, ',', ' ') . " ₽\n\n";
    $summary .= "Всё верно?";

    $keyboard = [
        [['text' => '✅ Да, записаться', 'callback_data' => 'confirm_yes']],
        [['text' => '❌ Нет, отменить', 'callback_data' => 'cancel']],
    ];

    bookingBotSend($pdo, $chatId, $summary, ['inline_keyboard' => $keyboard]);
    bookingBotSetStep($pdo, $chatId, 'confirm', $data);
}

function bookingBotStepConfirm(PDO $pdo, array $session, string $chatId, string $text): void {
    $data = bookingBotGetData($session);

    if ($text === 'cancel' || $text === 'confirm_no') {
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        bookingBotSend($pdo, $chatId, 'Запись отменена. Напишите /book чтобы начать заново.');
        return;
    }

    if ($text !== 'confirm_yes') {
        bookingBotSend($pdo, $chatId, 'Пожалуйста, используйте кнопки ниже.');
        return;
    }

    // Create booking request
    $requestId = bookingBotCreateRequest($pdo, $data);
    if (!$requestId) {
        bookingBotSend($pdo, $chatId, 'Произошла ошибка при создании заявки. Попробуйте позже.');
        bookingBotSetStep($pdo, $chatId, 'idle', []);
        return;
    }

    // Fetch fresh request for notification context
    $freshRequest = bookingBotFetchRequest($pdo, $requestId);
    if ($freshRequest) {
        try {
            $adminIds = bookingBotAdminRecipientIds($pdo);
            notificationServiceSendBooking($pdo, 'booking.created', $freshRequest, [
                'admin_ids' => $adminIds,
                'extra_context' => ['source' => 'telegram_bot'],
            ]);
        } catch (Throwable $e) {
            error_log('bookingBot notification error: ' . $e->getMessage());
        }
    }

    $requestNumber = $freshRequest['request_number'] ?? ('#' . $requestId);
    bookingBotSend($pdo, $chatId, "✅ Заявка {$requestNumber} создана! Мы свяжемся с вами для подтверждения.\n\nНапишите /book чтобы записаться снова.");
    bookingBotSetStep($pdo, $chatId, 'idle', []);
}

/* ── Data helpers ────────────────────────────────────────────── */

function bookingBotLoadServices(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, service_name, duration_minutes, price_rub FROM booking_service_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bookingBotBuildTimeSlots(PDO $pdo, DateTimeImmutable $date): array {
    $weekday = (int)$date->format('w'); // 0=Sun
    $slots = [];

    try {
        $stmt = $pdo->prepare("SELECT start_time, end_time FROM booking_working_hours WHERE weekday = ? AND is_working = 1 LIMIT 1");
        $stmt->execute([$weekday]);
        $wh = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wh || empty($wh['start_time']) || empty($wh['end_time'])) return [];

        $start = DateTimeImmutable::createFromFormat('H:i:s', $wh['start_time']) ?: DateTimeImmutable::createFromFormat('H:i', $wh['start_time']);
        $end = DateTimeImmutable::createFromFormat('H:i:s', $wh['end_time']) ?: DateTimeImmutable::createFromFormat('H:i', $wh['end_time']);
        if (!$start || !$end) return [];

        $slotStart = $start;
        while ($slotStart < $end) {
            $slotLabel = $slotStart->format('H:i');
            $slotDateTime = $date->setTime((int)$slotStart->format('H'), (int)$slotStart->format('i'));

            // Skip past slots for today
            $now = new DateTimeImmutable('now');
            if ($slotDateTime <= $now) {
                $slotStart = $slotStart->modify('+30 minutes');
                continue;
            }

            $slots[] = $slotLabel;
            $slotStart = $slotStart->modify('+30 minutes');
        }
    } catch (Throwable $e) {
        error_log('bookingBotBuildTimeSlots error: ' . $e->getMessage());
    }

    return $slots;
}

function bookingBotWeekdayRu(int $w): string {
    $map = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
    return $map[$w] ?? '';
}

function bookingBotAdminRecipientIds(PDO $pdo): array {
    $ids = [];
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'root' ORDER BY id ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[] = (int)$id; }
        $stmt = $pdo->query("SELECT DISTINCT u.id FROM users u JOIN roles r ON r.name = u.role JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'admin.full' ORDER BY u.id ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!in_array((int)$id, $ids, true)) $ids[] = (int)$id;
        }
    } catch (Throwable $e) {}
    return $ids;
}

function bookingBotCreateRequest(PDO $pdo, array $data): ?int {
    try {
        $services = $data['services'] ?? [];
        if (!$services) return null;

        $primaryService = $services[0];
        $totalDuration = array_sum(array_map(fn($s) => (int)($s['duration_minutes'] ?? 0), $services));
        $totalPrice = array_sum(array_map(fn($s) => (float)($s['price_rub'] ?? 0), $services));

        $date = $data['date'] ?? '';
        $time = $data['time'] ?? '';
        $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
        if (!$startAt) return null;
        $endAt = $startAt->modify("+{$totalDuration} minutes");

        $requestNumber = bookingBotGenerateRequestNumber($pdo);

        $stmt = $pdo->prepare("INSERT INTO booking_requests (
            request_number, service_type_id, crm_client_id, client_name, client_email, client_phone,
            preferred_datetime, preferred_end_at, total_duration_minutes, total_price_rub,
            status, created_by, notes, created_at
        ) VALUES (?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, 'pending', NULL, ?, NOW())");
        $stmt->execute([
            $requestNumber,
            (int)$primaryService['id'],
            substr((string)($data['name'] ?? ''), 0, 255),
            substr((string)($data['phone'] ?? ''), 0, 80),
            $startAt->format('Y-m-d H:i:s'),
            $endAt->format('Y-m-d H:i:s'),
            $totalDuration,
            round($totalPrice, 2),
            'Источник: Telegram bot',
        ]);

        $requestId = (int)$pdo->lastInsertId();

        // Link extra services
        if (count($services) > 1) {
            $linkStmt = $pdo->prepare("INSERT INTO booking_request_services (booking_request_id, service_type_id) VALUES (?, ?)");
            foreach ($services as $svc) {
                $linkStmt->execute([$requestId, (int)$svc['id']]);
            }
        }

        return $requestId;
    } catch (Throwable $e) {
        error_log('bookingBotCreateRequest error: ' . $e->getMessage());
        return null;
    }
}

function bookingBotGenerateRequestNumber(PDO $pdo): string {
    $now = new DateTimeImmutable('now');
    $prefix = 'BK-' . $now->format('Ymd');
    try {
        $stmt = $pdo->prepare("SELECT request_number FROM booking_requests WHERE request_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '-%']);
        $last = $stmt->fetchColumn();
        if ($last) {
            $suffix = (int)substr(strrchr((string)$last, '-'), 1);
            $next = str_pad((string)($suffix + 1), 4, '0', STR_PAD_LEFT);
            return $prefix . '-' . $next;
        }
    } catch (Throwable $e) {}
    return $prefix . '-0001';
}

function bookingBotFetchRequest(PDO $pdo, int $requestId): ?array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM booking_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Compose service_summary similar to booking.php
        $svcStmt = $pdo->prepare("SELECT st.service_name FROM booking_request_services rs JOIN booking_service_types st ON st.id = rs.service_type_id WHERE rs.booking_request_id = ? ORDER BY rs.id ASC");
        $svcStmt->execute([$requestId]);
        $names = $svcStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($names) {
            $row['service_summary'] = implode(', ', $names);
        } else {
            $row['service_summary'] = $row['service_type_name'] ?? 'Услуга';
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/* ── Telegram send helper ────────────────────────────────────── */

function bookingBotSend(PDO $pdo, string $chatId, string $text, ?array $replyMarkup = null): void {
    $token = '';
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'booking_bot_telegram_token' LIMIT 1");
        $token = trim((string)$stmt->fetchColumn());
    } catch (Throwable $e) {}
    // Fallback to omnichannel token if booking bot token is not set
    if (!$token) {
        try {
            $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'omni_tg_bot_token' LIMIT 1");
            $encrypted = $stmt->fetchColumn();
            if ($encrypted) {
                try {
                    $token = trim((string)(appDecrypt((string)$encrypted) ?? ''));
                } catch (Throwable $e) {
                    $token = trim((string)$encrypted);
                }
            }
        } catch (Throwable $e) {}
    }
    if (!$token) {
        error_log('bookingBotSend: no token configured');
        return;
    }

    $body = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup) {
        $body['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 8,
            'ignore_errors' => true,
        ]
    ];
    $ctx = stream_context_create($opts);
    @file_get_contents($url, false, $ctx);

    // Log outgoing
    bookingBotLog($pdo, null, $chatId, 'out', $text, null);
}
