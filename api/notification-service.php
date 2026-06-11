<?php
/**
 * api/notification-service.php — Унифицированный сервис уведомлений
 *
 * Поддерживает каналы: email, telegram, internal (in-app notifications).
 * Шаблоны хранятся в notification_templates.
 * Логи отправки — в notification_logs.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

// Подключаем Composer autoload для PHPMailer
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Получить базовый URL приложения
 */
function notificationServiceBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 0) == 443) ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/api');
    $path = str_replace('/api', '', $path);
    return rtrim($protocol . $host . $path, '/');
}

/**
 * Загрузить шаблон уведомления
 */
function notificationServiceLoadTemplate(PDO $pdo, string $event, string $channel): ?array {
    $stmt = $pdo->prepare("
        SELECT id, event, channel, subject, body_html, body_text, is_active
        FROM notification_templates
        WHERE event = ? AND channel = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$event, $channel]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    return $template ?: null;
}

/**
 * Рендерить шаблон с подстановками
 */
function notificationServiceRender(string $template, array $vars): string {
    $result = $template;
    foreach ($vars as $key => $value) {
        $result = str_replace('{{' . $key . '}}', (string)$value, $result);
    }
    return $result;
}

/**
 * Подготовить переменные для шаблона booking
 */
function notificationServiceBookingVars(array $request): array {
    $services = [];
    foreach ($request['services'] ?? [] as $svc) {
        $services[] = ($svc['service_name'] ?? $svc['type_name'] ?? 'Услуга')
            . ' (' . ($svc['duration_minutes'] ?? 0) . ' мин)';
    }

    $datetime = '';
    if (!empty($request['preferred_datetime'])) {
        try {
            $dt = new DateTimeImmutable($request['preferred_datetime']);
            $datetime = $dt->format('d.m.Y H:i');
        } catch (Throwable $e) {
            $datetime = (string)$request['preferred_datetime'];
        }
    }

    $price = number_format((float)($request['total_price_rub'] ?? 0), 0, ',', ' ') . ' ₽';
    $adminComment = !empty($request['admin_comment']) ? (string)$request['admin_comment'] : '—';

    // Получаем company_name из settings
    $companyName = 'TaskFlow';
    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'company_name' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['value'])) {
            $companyName = (string)$row['value'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'request_number' => $request['request_number'] ?? '',
        'client_name' => $request['client_name'] ?? 'Клиент',
        'client_phone' => $request['client_phone'] ?? '',
        'client_email' => $request['client_email'] ?? '',
        'services' => implode(', ', $services),
        'datetime' => $datetime,
        'total_price' => $price,
        'admin_comment' => $adminComment,
        'company_name' => $companyName,
        'app_url' => notificationServiceBaseUrl(),
    ];
}

/**
 * Найти дефолтный SMTP-аккаунт для системных уведомлений
 */
function notificationServiceGetSystemMailAccount(PDO $pdo): ?array {
    try {
        // Сначала ищем системный аккаунт (user_id IS NULL)
        $stmt = $pdo->query("
            SELECT id, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, display_name
            FROM mail_accounts
            WHERE user_id IS NULL AND smtp_host IS NOT NULL AND smtp_host != ''
            ORDER BY is_default DESC, id ASC
            LIMIT 1
        ");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            return $account;
        }

        // Fallback: первый аккаунт с SMTP
        $stmt = $pdo->query("
            SELECT id, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, display_name
            FROM mail_accounts
            WHERE smtp_host IS NOT NULL AND smtp_host != ''
            ORDER BY is_default DESC, id ASC
            LIMIT 1
        ");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        return $account ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Отправить email через PHPMailer или mail()
 */
function notificationServiceSendEmail(
    PDO $pdo,
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    ?string $fromName = null,
    ?string $fromEmail = null
): array {
    $fromName = $fromName ?? 'TaskFlow';
    $fromEmail = $fromEmail ?? 'noreply@taskflow.pro';

    $log = [
        'success' => false,
        'error' => null,
        'method' => null,
    ];

    $account = notificationServiceGetSystemMailAccount($pdo);

    // Пробуем PHPMailer если есть SMTP-аккаунт
    if ($account && class_exists(PHPMailer::class)) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $account['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $account['smtp_username'] ?? $account['email'];
            $mail->Password = $account['smtp_password'] ?? '';
            $mail->SMTPSecure = $account['smtp_encryption'] ?? 'tls';
            $mail->Port = (int)($account['smtp_port'] ?? 587);
            $mail->CharSet = 'UTF-8';

            $displayName = $account['display_name'] ?? $fromName;
            $senderEmail = $account['email'] ?? $fromEmail;
            $mail->setFrom($senderEmail, $displayName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
            $log['success'] = true;
            $log['method'] = 'smtp';
            return $log;
        } catch (Exception $e) {
            $log['error'] = 'SMTP error: ' . $e->getMessage();
            $log['method'] = 'smtp_failed';
            // Fallback to mail()
        }
    }

    // Fallback: native mail()
    $boundary = md5(time());
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: TaskFlow Notification Service\r\n";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $sent = @mail($to, $subject, $body, $headers);
    if ($sent) {
        $log['success'] = true;
        $log['method'] = 'mail';
    } else {
        $log['error'] = ($log['error'] ?? '') . ' | mail() failed';
        $log['method'] = 'mail_failed';
    }

    return $log;
}

/**
 * Отправить Telegram-уведомление админам
 */
function notificationServiceSendTelegram(PDO $pdo, string $message): array {
    $log = ['success' => false, 'error' => null];

    try {
        $stmt = $pdo->query("SELECT bot_token, chat_id, enabled FROM telegram_settings WHERE enabled = 1 LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings || empty($settings['bot_token']) || empty($settings['chat_id'])) {
            $log['error'] = 'Telegram not configured';
            return $log;
        }

        $result = sendTelegramMessage(
            $settings['bot_token'],
            $settings['chat_id'],
            $message,
            'HTML'
        );

        $log['success'] = $result['success'];
        $log['error'] = $result['error'] ?? null;
    } catch (Throwable $e) {
        $log['error'] = $e->getMessage();
    }

    return $log;
}

/**
 * Логировать отправку уведомления
 */
function notificationServiceLog(
    PDO $pdo,
    string $event,
    string $channel,
    string $recipientType,
    ?int $recipientId,
    ?string $recipientAddress,
    ?string $subject,
    ?string $body,
    bool $success,
    ?string $error = null,
    ?int $templateId = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notification_logs
                (template_id, event, channel, recipient_type, recipient_id, recipient_address, subject, body, status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $templateId,
            $event,
            $channel,
            $recipientType,
            $recipientId,
            $recipientAddress ? substr($recipientAddress, 0, 255) : null,
            $subject ? substr($subject, 0, 255) : null,
            $body,
            $success ? 'sent' : 'failed',
            $error,
            $success ? date('Y-m-d H:i:s') : null,
        ]);
    } catch (Throwable $e) {
        error_log('notificationServiceLog failed: ' . $e->getMessage());
    }
}

/**
 * Основная функция отправки уведомления о booking
 *
 * @param PDO $pdo
 * @param string $event 'booking.created' | 'booking.confirmed' | 'booking.rejected' | 'booking.reminder_24h' | 'booking.reminder_1h'
 * @param array $request Данные заявки (из bookingComposeBookingRequest)
 * @param array $options Дополнительные опции:
 *   - 'admin_ids' => int[] — ID админов для internal/telegram
 *   - 'creator_id' => int — ID создателя заявки (для internal)
 *   - 'skip_client' => bool — не отправлять клиенту
 */
function notificationServiceSendBooking(PDO $pdo, string $event, array $request, array $options = []): void {
    $vars = notificationServiceBookingVars($request);
    $adminIds = $options['admin_ids'] ?? [];
    $creatorId = $options['creator_id'] ?? null;
    $skipClient = !empty($options['skip_client']);

    // --- Internal notifications (админам) ---
    if (in_array($event, ['booking.created', 'booking.confirmed', 'booking.rejected'], true)) {
        $template = notificationServiceLoadTemplate($pdo, $event, 'internal');
        if ($template) {
            $message = notificationServiceRender($template['body_text'] ?? $template['body_html'] ?? '', $vars);
            $recipients = [];

            if ($event === 'booking.created') {
                $recipients = $adminIds;
            } elseif ($creatorId && $creatorId > 0) {
                $recipients = [$creatorId];
            }

            foreach (array_unique(array_filter($recipients)) as $userId) {
                try {
                    createNotification($pdo, [
                        'user_id' => (int)$userId,
                        'sender_id' => null,
                        'message' => $message,
                        'type' => 'booking',
                        'related_id' => (int)($request['id'] ?? 0),
                        'allow_self' => true,
                    ]);
                } catch (Throwable $e) {
                    error_log('notificationServiceSendBooking internal failed: ' . $e->getMessage());
                }
            }
        }
    }

    // --- Telegram notifications (админам) ---
    if (in_array($event, ['booking.created'], true)) {
        $template = notificationServiceLoadTemplate($pdo, $event, 'telegram');
        if ($template) {
            $message = notificationServiceRender($template['body_text'] ?? '', $vars);
            $result = notificationServiceSendTelegram($pdo, $message);
            notificationServiceLog(
                $pdo, $event, 'telegram', 'admin', null,
                null, null, $message,
                $result['success'], $result['error'] ?? null,
                (int)$template['id']
            );
        }
    }

    // --- Email notifications (клиенту) ---
    if (!$skipClient && !empty($request['client_email'])) {
        $template = notificationServiceLoadTemplate($pdo, $event, 'email');
        if ($template) {
            $subject = notificationServiceRender($template['subject'] ?? '', $vars);
            $htmlBody = notificationServiceRender($template['body_html'] ?? '', $vars);
            $textBody = notificationServiceRender($template['body_text'] ?? '', $vars);

            // Оборачиваем HTML в красивый шаблон если он не содержит <html
            if (stripos($htmlBody, '<html') === false) {
                $htmlBody = notificationServiceWrapEmailHtml($htmlBody, $vars['company_name']);
            }

            // Добавляем .ics для confirmed и reminders
            $icsAttachment = null;
            if (in_array($event, ['booking.confirmed', 'booking.reminder_24h', 'booking.reminder_1h'], true)) {
                $icsAttachment = notificationServiceBuildIcs($request, $vars);
            }

            $result = notificationServiceSendEmail(
                $pdo,
                $request['client_email'],
                $subject,
                $htmlBody,
                $textBody,
                $vars['company_name']
            );

            notificationServiceLog(
                $pdo, $event, 'email', 'client', null,
                $request['client_email'], $subject, $htmlBody,
                $result['success'], $result['error'] ?? null,
                (int)$template['id']
            );
        }
    }
}

/**
 * Обёртка HTML письма в единый дизайн
 */
function notificationServiceWrapEmailHtml(string $content, string $companyName): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$companyName}</title>
<style>
body { margin: 0; padding: 0; background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #0f172a; }
.container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(15,23,42,0.08); }
.header { background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); padding: 32px 24px; text-align: center; }
.header h1 { margin: 0; color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
.content { padding: 32px 24px; font-size: 15px; line-height: 1.6; }
.content p { margin: 0 0 16px; }
.content strong { color: #0f172a; }
.footer { padding: 24px; text-align: center; font-size: 13px; color: #64748b; background: #f8fafc; border-top: 1px solid #e2e8f0; }
.btn { display: inline-block; padding: 14px 28px; background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: #ffffff !important; text-decoration: none; border-radius: 16px; font-weight: 600; margin-top: 8px; }
.detail-box { background: #f8fafc; border-radius: 16px; padding: 20px; margin: 16px 0; border: 1px solid #e2e8f0; }
.detail-box p { margin: 0 0 8px; font-size: 14px; }
.detail-box p:last-child { margin-bottom: 0; }
</style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 40px 16px;">
<div class="container">
<div class="header"><h1>{$companyName}</h1></div>
<div class="content">
{$content}
</div>
<div class="footer">
<p style="margin:0 0 8px;">© {$year} {$companyName}</p>
<p style="margin:0; font-size:12px; color:#94a3b8;">Это автоматическое сообщение, пожалуйста, не отвечайте на него.</p>
</div>
</div>
</td></tr></table>
</body>
</html>
HTML;
}

/**
 * Построить .ics файл для календаря
 */
function notificationServiceBuildIcs(array $request, array $vars): string {
    try {
        $start = new DateTimeImmutable($request['preferred_datetime'] ?? 'now');
        $duration = max(1, (int)($request['total_duration_minutes'] ?? 30));
        $end = $start->modify("+{$duration} minutes");

        $uid = 'booking-' . ($request['id'] ?? '0') . '@taskflow';
        $dtStamp = gmdate('Ymd\THis\Z');
        $dtStart = $start->format('Ymd\THis\Z');
        $dtEnd = $end->format('Ymd\THis\Z');
        $summary = 'Запись ' . ($request['request_number'] ?? '');
        $description = 'Услуги: ' . $vars['services'];

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//TaskFlow//Booking//RU\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtStamp}\r\n";
        $ics .= "DTSTART:{$dtStart}\r\n";
        $ics .= "DTEND:{$dtEnd}\r\n";
        $ics .= "SUMMARY:" . str_replace(["\r", "\n"], ['', '\\n'], $summary) . "\r\n";
        $ics .= "DESCRIPTION:" . str_replace(["\r", "\n"], ['', '\\n'], $description) . "\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:REMINDER\r\n";
        $ics .= "TRIGGER:-PT15M\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Обработать напоминания по booking
 * Вызывать при каждом запросе к booking.php или из cron
 */
function notificationServiceProcessReminders(PDO $pdo, ?DateTimeImmutable $now = null): int {
    $now ??= new DateTimeImmutable('now');
    $sent = 0;

    // 24-часовое напоминание
    try {
        $windowStart24 = $now->modify('+23 hours')->format('Y-m-d H:i:s');
        $windowEnd24 = $now->modify('+25 hours')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT br.*
            FROM booking_requests br
            LEFT JOIN booking_request_reminders brr ON brr.request_id = br.id
            WHERE br.status = 'confirmed'
              AND br.preferred_datetime BETWEEN ? AND ?
              AND (brr.reminder_24h_sent = 0 OR brr.request_id IS NULL)
            LIMIT 50
        ");
        $stmt->execute([$windowStart24, $windowEnd24]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($requests as $row) {
            $request = bookingComposeBookingRequest($pdo, $row);
            notificationServiceSendBooking($pdo, 'booking.reminder_24h', $request, ['skip_client' => false]);

            $pdo->prepare("
                INSERT INTO booking_request_reminders (request_id, reminder_24h_sent)
                VALUES (?, 1)
                ON DUPLICATE KEY UPDATE reminder_24h_sent = 1
            ")->execute([(int)$row['id']]);
            $sent++;
        }
    } catch (Throwable $e) {
        error_log('notificationServiceProcessReminders 24h failed: ' . $e->getMessage());
    }

    // 1-часовое напоминание
    try {
        $windowStart1 = $now->modify('+45 minutes')->format('Y-m-d H:i:s');
        $windowEnd1 = $now->modify('+75 minutes')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT br.*
            FROM booking_requests br
            LEFT JOIN booking_request_reminders brr ON brr.request_id = br.id
            WHERE br.status = 'confirmed'
              AND br.preferred_datetime BETWEEN ? AND ?
              AND (brr.reminder_1h_sent = 0 OR brr.request_id IS NULL)
            LIMIT 50
        ");
        $stmt->execute([$windowStart1, $windowEnd1]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($requests as $row) {
            $request = bookingComposeBookingRequest($pdo, $row);
            notificationServiceSendBooking($pdo, 'booking.reminder_1h', $request, ['skip_client' => false]);

            $pdo->prepare("
                INSERT INTO booking_request_reminders (request_id, reminder_1h_sent)
                VALUES (?, 1)
                ON DUPLICATE KEY UPDATE reminder_1h_sent = 1
            ")->execute([(int)$row['id']]);
            $sent++;
        }
    } catch (Throwable $e) {
        error_log('notificationServiceProcessReminders 1h failed: ' . $e->getMessage());
    }

    return $sent;
}
