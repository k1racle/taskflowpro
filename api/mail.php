<?php
/**
 * api/mail.php - Почтовый клиент (PHPMailer integration)
 *
 * Endpoints:
 * - GET    /api/mail/folders              - Папки почты
 * - GET    /api/mail/emails?folder=inbox  - Письма из папки
 * - GET    /api/mail/emails/:id           - Конкретное письмо
 * - POST   /api/mail/send                 - Отправить письмо
 * - POST   /api/mail/reply                - Ответить на письмо
 * - DELETE /api/mail/emails/:id           - Удалить письмо
 * - POST   /api/mail/move                 - Переместить письмо
 * - GET    /api/mail/accounts             - Почтовые аккаунты
 * - POST   /api/mail/accounts             - Добавить аккаунт
 * - PUT    /api/mail/accounts/:id         - Обновить аккаунт
 * - DELETE /api/mail/accounts/:id         - Удалить аккаунт
 */

// Подключаем Composer autoload для PHPMailer.
// На хостингах пути иногда отличаются, поэтому пробуем несколько вариантов.
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

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function handleMail(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    // Проверка на тест SMTP (без авторизации!)
    if ($method === 'POST' && $action === 'test') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['smtp_host']) || empty($data['smtp_username']) || empty($data['smtp_password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните SMTP настройки']);
            exit;
        }

        // Проверка что PHPMailer подключен
        if (!class_exists(PHPMailer::class)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'PHPMailer не установлен (vendor/autoload.php не найден или пакет не залит)',
            ]);
            exit;
        }

        try {
            $mail = new PHPMailer(true);

            // Настройки сервера
            $mail->isSMTP();
            $mail->Host = $data['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $data['smtp_username'] ?? $data['email'];
            $mail->Password = $data['smtp_password'];
            $mail->SMTPSecure = $data['smtp_encryption'] ?? 'tls';
            $mail->Port = $data['smtp_port'] ?? 587;

            // Тестовое письмо самому себе
            $mail->setFrom($data['email'] ?? 'test@taskflow.com', 'TaskFlow Test');
            $mail->addAddress($data['email'] ?? $data['smtp_username']);

            $mail->isHTML(false);
            $mail->Subject = 'TaskFlow Pro - Тест SMTP';
            $mail->Body = "Это тестовое письмо для проверки SMTP настроек.\n\nЕсли вы видите это сообщение - SMTP работает корректно!\n\nTaskFlow Pro";

            $mail->send();

            echo json_encode(['success' => true, 'message' => 'SMTP работает! Письмо отправлено.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка SMTP: ' . $e->getMessage()]);
        }
        exit;
    }

    // Для всех остальных endpoint'ов требуется авторизация
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $userId = $currentUser['id'];

    // ============================================
    // POST /api/mail/imap-test - Проверка IMAP
    // ============================================
    if ($method === 'POST' && $action === 'imap-test') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['imap_host']) || empty($data['imap_username']) || empty($data['imap_password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните IMAP настройки']);
            exit;
        }

        try {
            $host = $data['imap_host'];
            $port = (int)($data['imap_port'] ?? 993);
            $enc = $data['imap_encryption'] ?? 'ssl';

            $flags = '/imap';
            if ($enc === 'ssl') {
                $flags .= '/ssl';
            } elseif ($enc === 'tls') {
                $flags .= '/tls';
            } else {
                $flags .= '/notls';
            }

            // Не валим проверку на самоподписанные сертификаты (типично для хостинга)
            $flags .= '/novalidate-cert';

            $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);

            $imap = @imap_open($mailbox, $data['imap_username'], $data['imap_password'], OP_HALFOPEN, 1);
            if (!$imap) {
                $err = imap_last_error();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $err ?: 'Не удалось подключиться к IMAP']);
                exit;
            }

            imap_close($imap);
            echo json_encode(['success' => true, 'message' => 'IMAP подключение успешно']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'IMAP ошибка: ' . $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // GET /api/mail/folders - Папки почты
    // ============================================
    if ($method === 'GET' && $action === 'folders') {
        // Получаем количество писем по папкам
        $folders = [];
        
        // Входящие (непрочитанные)
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM mail_messages WHERE recipient_id = ? AND folder = 'inbox' AND is_read = 0");
        $stmt->execute([$userId]);
        $folders[] = ['id' => 'inbox', 'name' => 'Входящие', 'icon' => 'inbox', 'count' => $stmt->fetch()['count']];
        
        // Отправленные (всегда 0 непрочитанных)
        $stmt = $pdo->prepare("SELECT 0 as count");
        $stmt->execute();
        $folders[] = ['id' => 'sent', 'name' => 'Отправленные', 'icon' => 'send', 'count' => $stmt->fetch()['count']];
        
        // Черновики (всегда 0 непрочитанных)
        $stmt = $pdo->prepare("SELECT 0 as count");
        $stmt->execute();
        $folders[] = ['id' => 'drafts', 'name' => 'Черновики', 'icon' => 'draft', 'count' => $stmt->fetch()['count']];
        
        // Спам (непрочитанные)
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM mail_messages WHERE recipient_id = ? AND folder = 'spam' AND is_read = 0");
        $stmt->execute([$userId]);
        $folders[] = ['id' => 'spam', 'name' => 'Спам', 'icon' => 'alert', 'count' => $stmt->fetch()['count']];
        
        // Корзина (непрочитанные)
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM mail_messages WHERE recipient_id = ? AND folder = 'trash' AND is_read = 0");
        $stmt->execute([$userId]);
        $folders[] = ['id' => 'trash', 'name' => 'Корзина', 'icon' => 'trash', 'count' => $stmt->fetch()['count']];

        echo json_encode(['success' => true, 'data' => $folders]);
        exit;
    }

    // ============================================
    // GET /api/mail/emails - Письма из папки
    // ============================================
    // ============================================
    // GET /api/mail/emails/:id?via=query - fallback для хостингов с нестабильным роутингом
    // /api/mail/emails?id=123
    // ============================================
    if ($method === 'GET' && $action === 'emails' && isset($_GET['id']) && is_numeric($_GET['id'])) {
        $emailId = (int)$_GET['id'];

        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                s.full_name as sender_name,
                s.avatar as sender_avatar,
                r.full_name as recipient_name,
                r.avatar as recipient_avatar
            FROM mail_messages m
            LEFT JOIN users s ON m.sender_id = s.id
            LEFT JOIN users r ON m.recipient_id = r.id
            WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ? OR m.recipient_email = ?)
        ");
        $stmt->execute([$emailId, $userId, $userId, $currentUser['email'] ?? $currentUser['login']]);
        $email = $stmt->fetch();

        if (!$email) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Письмо не найдено']);
            exit;
        }

        if (!$email['is_read'] && (
            (isset($email['recipient_id']) && (int)$email['recipient_id'] === (int)$userId)
            || (
                empty($email['recipient_id'])
                && !empty($email['recipient_email'])
                && strcasecmp((string)$email['recipient_email'], (string)($currentUser['email'] ?? $currentUser['login'])) === 0
            )
        )) {
            $pdo->prepare("UPDATE mail_messages SET is_read = 1 WHERE id = ?")->execute([$emailId]);
        }

        $stmt = $pdo->prepare("SELECT id, file_name, file_path, mime_type, file_size, created_at FROM mail_attachments WHERE email_id = ? ORDER BY id ASC");
        $stmt->execute([$emailId]);
        $email['attachments'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $email]);
        exit;
    }

    if ($method === 'GET' && $action === 'emails') {
        // По желанию клиента можно подтянуть свежие письма с IMAP перед выдачей списка
        // /api/mail/emails?folder=inbox&sync=1
        if (!empty($_GET['sync'])) {
            try {
                $mailSettings = getUserMailSettings($pdo, (int)$userId);
                if (!empty($mailSettings['imap_host'])) {
                    $folderReq = $_GET['folder'] ?? 'inbox';
                    if ($folderReq === 'sent') {
                        // Авто определяем папку отправленных
                        $list = listImapFolders($mailSettings);
                        if (($list['success'] ?? false) && !empty($list['data'])) {
                            $candidates = guessImapSentFolders($list['data']);
                            foreach ($candidates as $cand) {
                                syncImapFolder($pdo, (int)$userId, $mailSettings, (string)$cand, 50);
                            }
                        }
                    } else {
                        syncImapFolder($pdo, (int)$userId, $mailSettings, 'INBOX', 50);
                    }
                }
            } catch (Throwable $e) {
                // Синк не должен ломать выдачу списка
                error_log('IMAP sync error: ' . $e->getMessage());
            }
        }

        $folder = $_GET['folder'] ?? 'inbox';
        $imapFolder = null;
        if (is_string($folder) && str_starts_with($folder, 'imap:')) {
            $imapFolder = substr($folder, 5);
            $folder = 'inbox';
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $starredOnly = !empty($_GET['starred']);
        $unreadOnly = !empty($_GET['unread']);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        $validFolders = ['inbox', 'sent', 'drafts', 'spam', 'trash'];
        if (!in_array($folder, $validFolders)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная папка']);
            exit;
        }

        $userEmail = $currentUser['email'] ?? $currentUser['login'];

        $whereSearch = '';
        $whereImap = '';
        $params = [$folder, $userId, $userEmail, $folder, $userId];

        if ($imapFolder) {
            $whereImap = " AND m.imap_folder = ? ";
            $params[] = $imapFolder;
        }

        if ($starredOnly) {
            $whereSearch .= " AND m.is_starred = 1 ";
        }

        if ($unreadOnly) {
            $whereSearch .= " AND m.is_read = 0 ";
        }

        if ($q !== '') {
            $whereSearch .= " AND (m.subject LIKE ? OR m.body LIKE ? OR m.sender_email LIKE ? OR m.sender_name LIKE ? OR m.recipient_email LIKE ? OR m.recipient_name LIKE ?) ";
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                CASE 
                    WHEN m.folder = 'inbox' THEN s.full_name
                    WHEN m.folder = 'sent' THEN r.full_name
                    ELSE NULL
                END as contact_name,
                CASE 
                    WHEN m.folder = 'inbox' THEN s.avatar
                    WHEN m.folder = 'sent' THEN r.avatar
                    ELSE NULL
                END as contact_avatar
            FROM mail_messages m
            LEFT JOIN users s ON m.sender_id = s.id
            LEFT JOIN users r ON m.recipient_id = r.id
            WHERE (
                m.folder = ?
                AND (
                    m.recipient_id = ?
                    OR (m.recipient_id IS NULL AND m.recipient_email = ?)
                )
                $whereImap
                $whereSearch
            )
            OR (
                m.folder = ?
                AND m.sender_id = ?
                " . ($q !== '' ? " AND (m.subject LIKE ? OR m.body LIKE ? OR m.sender_email LIKE ? OR m.sender_name LIKE ? OR m.recipient_email LIKE ? OR m.recipient_name LIKE ?) " : "") . "
            )
            ORDER BY m.sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $emails = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $emails]);
        exit;
    }

    // ============================================
    // POST /api/mail/sync - Синхронизация IMAP
    // body: { folder: 'INBOX' | 'Sent', limit?: 50 }
    // ============================================
    if ($method === 'POST' && $action === 'sync') {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder = $data['folder'] ?? 'INBOX';
        $limit = (int)($data['limit'] ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $mailSettings = getUserMailSettings($pdo, (int)$userId);

        // Авто-режим: сами найдём подходящую папку "Отправленные" у конкретного хостинга
        if ($folder === 'SENT_AUTO') {
            $list = listImapFolders($mailSettings);
            if (!($list['success'] ?? false)) {
                http_response_code(500);
                echo json_encode($list);
                exit;
            }

            $folders = $list['data'] ?? [];
            $candidates = guessImapSentFolders($folders);
            if (!$candidates) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Не удалось определить папку Отправленные.']);
                exit;
            }

            $totalInserted = 0;
            foreach ($candidates as $cand) {
                $r = syncImapFolder($pdo, (int)$userId, $mailSettings, (string)$cand, $limit);
                if (($r['success'] ?? false) && isset($r['inserted'])) {
                    $totalInserted += (int)$r['inserted'];
                }
            }
            echo json_encode(['success' => true, 'inserted' => $totalInserted, 'folders' => $candidates]);
            exit;
        }

        $result = syncImapFolder($pdo, (int)$userId, $mailSettings, (string)$folder, $limit);
        if (!($result['success'] ?? false)) {
            http_response_code(500);
        }
        echo json_encode($result);
        exit;
    }

    // ============================================
    // GET /api/mail/imap-folders - список папок IMAP
    // ============================================
    if ($method === 'GET' && $action === 'imap-folders') {
        $mailSettings = getUserMailSettings($pdo, (int)$userId);
        $result = listImapFolders($mailSettings);
        if (!($result['success'] ?? false)) {
            http_response_code(500);
        }
        echo json_encode($result);
        exit;
    }

    // ============================================
    // POST /api/mail/imap-folders - создать IMAP папку
    // body: { name: "MyFolder" | "INBOX.MyFolder" }
    // ============================================
    if ($method === 'POST' && $action === 'imap-folders') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указано имя папки']);
            exit;
        }

        $mailSettings = getUserMailSettings($pdo, (int)$userId);
        if (empty($mailSettings['imap_host']) || empty($mailSettings['smtp_username']) || empty($mailSettings['smtp_password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'IMAP не настроен']);
            exit;
        }
        if (!function_exists('imap_open')) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'PHP расширение imap не установлено']);
            exit;
        }

        try {
            $host = (string)$mailSettings['imap_host'];
            $port = (int)($mailSettings['imap_port'] ?? 993);
            $enc = (string)($mailSettings['imap_encryption'] ?? 'ssl');
            $username = (string)$mailSettings['smtp_username'];
            $password = (string)$mailSettings['smtp_password'];

            $mailbox = buildImapMailboxString($host, $port, $enc, 'INBOX');
            $imap = @imap_open($mailbox, $username, $password);
            if (!$imap) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => imap_last_error() ?: 'Не удалось подключиться к IMAP']);
                exit;
            }

            // Нормализуем имя: если не указали префикс — создаём в корне INBOX
            $folderName = $name;
            if (!str_contains($folderName, 'INBOX') && !str_contains($folderName, '.')) {
                $folderName = 'INBOX.' . $folderName;
            }

            $ref = sprintf('{%s:%d/imap%s/novalidate-cert}%s', $host, $port, $enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls'), $folderName);
            $ok = @imap_createmailbox($imap, imap_utf7_encode($ref));
            if (!$ok) {
                $err = imap_last_error() ?: 'Не удалось создать папку';
                imap_close($imap);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }

            imap_close($imap);
            echo json_encode(['success' => true, 'data' => ['folder' => $folderName]]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'IMAP ошибка: ' . $e->getMessage()]);
            exit;
        }
    }

    // ============================================
    // GET /api/mail/emails/:id - Конкретное письмо
    // ============================================
    if ($method === 'GET' && $action === 'emails' && is_numeric($id)) {
        $emailId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                s.full_name as sender_name,
                s.avatar as sender_avatar,
                r.full_name as recipient_name,
                r.avatar as recipient_avatar
            FROM mail_messages m
            LEFT JOIN users s ON m.sender_id = s.id
            LEFT JOIN users r ON m.recipient_id = r.id
            WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ? OR m.recipient_email = ?)
        ");
        $stmt->execute([$emailId, $userId, $userId, $currentUser['email'] ?? $currentUser['login']]);
        $email = $stmt->fetch();

        if (!$email) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Письмо не найдено']);
            exit;
        }

        // Помечаем как прочитанное
        if (!$email['is_read'] && (
            (isset($email['recipient_id']) && (int)$email['recipient_id'] === (int)$userId)
            || (
                empty($email['recipient_id'])
                && !empty($email['recipient_email'])
                && strcasecmp((string)$email['recipient_email'], (string)($currentUser['email'] ?? $currentUser['login'])) === 0
            )
        )) {
            $stmt = $pdo->prepare("UPDATE mail_messages SET is_read = 1 WHERE id = ?");
            $stmt->execute([$emailId]);
        }

        // Вложения
        $stmt = $pdo->prepare("SELECT id, file_name, file_path, mime_type, file_size, created_at FROM mail_attachments WHERE email_id = ? ORDER BY id ASC");
        $stmt->execute([$emailId]);
        $email['attachments'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $email]);
        exit;
    }

    // ============================================
    // GET /api/mail/attachments/:id/download - скачать вложение
    // ============================================
    if ($method === 'GET' && $action === 'attachments' && is_numeric($id) && $subaction === 'download') {
        $attachmentId = (int)$id;
        $stmt = $pdo->prepare("
            SELECT a.*, m.sender_id, m.recipient_id
            FROM mail_attachments a
            INNER JOIN mail_messages m ON m.id = a.email_id
            WHERE a.id = ?
        ");
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch();
        if (!$att) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Вложение не найдено']);
            exit;
        }

        if ((int)$att['sender_id'] !== (int)$userId && (int)$att['recipient_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $absolutePath = realpath(__DIR__ . '/../' . $att['file_path']);
        if (!$absolutePath || !file_exists($absolutePath) || !is_file($absolutePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден на сервере']);
            exit;
        }

        header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($att['file_name']) . '"');
        header('Content-Length: ' . filesize($absolutePath));
        header('Cache-Control: private, max-age=0');
        readfile($absolutePath);
        exit;
    }

    // ============================================
    // POST /api/mail/send - Отправить письмо
    // ============================================
    if ($method === 'POST' && $action === 'send') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = str_contains($contentType, 'multipart/form-data');
        $data = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

        // Сохранение черновика
        if (!empty($data['save_as_draft'])) {
            $subject = (string)($data['subject'] ?? '');
            $body = (string)($data['body'] ?? '');
            $isHtml = !empty($data['is_html']) ? 1 : 0;
            $recipientEmail = trim((string)($data['recipient_email'] ?? ''));
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$recipientEmail]);
            $recipient = $stmt->fetch();
            $recipientId = $recipient ? (int)$recipient['id'] : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO mail_messages
                (sender_id, recipient_id, recipient_email, subject, body, is_html, folder, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, 'drafts', NOW())
            ");
            $stmt->execute([$userId, $recipientId, $recipientEmail ?: null, $subject, $body, $isHtml]);
            $emailId = (int)$pdo->lastInsertId();
            
            echo json_encode(['success' => true, 'data' => ['id' => $emailId]]);
            exit;
        }

        if (empty($data['recipient_email']) || empty($data['subject']) || !isset($data['body'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
            exit;
        }

        $recipientEmail = trim((string)$data['recipient_email']);
        $subject = (string)$data['subject'];
        $body = (string)$data['body'];
        $isHtml = !empty($data['is_html']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$recipientEmail]);
        $recipient = $stmt->fetch();
        $recipientId = $recipient ? (int)$recipient['id'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO mail_messages 
            (sender_id, recipient_id, recipient_email, subject, body, is_html, folder, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([$userId, $recipientId, $recipientEmail, $subject, $body, $isHtml]);
        $emailId = (int)$pdo->lastInsertId();

        // Сохраняем вложения (если есть)
        $savedAttachments = [];
        if ($isMultipart) {
            $savedAttachments = processOutgoingAttachments($pdo, (int)$userId, $emailId);
        }

        // Inline images (data URL) -> CID + вложения
        $inlineImages = [];
        if ($isHtml === 1) {
            $inlineRes = processOutgoingInlineImages($pdo, $emailId, $body);
            $body = (string)($inlineRes['html'] ?? $body);
            $inlineImages = $inlineRes['inline'] ?? [];
            // обновляем тело письма в БД (уже с cid:)
            $pdo->prepare("UPDATE mail_messages SET body = ?, is_html = 1 WHERE id = ?")->execute([$body, $emailId]);
        }

        // SMTP отправка (через сохранённые настройки пользователя)
        $smtpResult = null;
        if (!empty($data['use_smtp'])) {
            try {
                // Добавим inline-изображения (CID) в общий список вложений для отправки
                $attachmentsToSend = $savedAttachments;
                if ($inlineImages) {
                    foreach ($inlineImages as $img) {
                        if (!empty($img['file_path'])) {
                            $attachmentsToSend[] = [
                                'file_name' => $img['file_name'],
                                'file_path' => $img['file_path'],
                                'cid' => $img['cid'],
                                'inline' => true,
                            ];
                        }
                    }
                }

                $smtpResult = sendEmailViaUserSmtp($pdo, $userId, $recipientEmail, $subject, $body, $isHtml === 1, $attachmentsToSend);

                // Надёжно кладём копию в Sent через IMAP APPEND
                if (($smtpResult['success'] ?? false)) {
                    try {
                        $settings = getUserMailSettings($pdo, (int)$userId);
                        $smtpResult['imap_append_sent'] = imapAppendSent($pdo, (int)$userId, $settings, $subject, $body, $isHtml === 1, $attachmentsToSend, $recipientEmail);

                        // Если смогли получить UID — связываем серверное письмо с локальной записью,
                        // чтобы синк Sent не создавал дубли.
                        $append = $smtpResult['imap_append_sent'];
                        if (($append['success'] ?? false) && !empty($append['uid']) && !empty($append['folder'])) {
                            linkImapUidToLocalMessage(
                                $pdo,
                                (int)$userId,
                                (string)($settings['email'] ?? ''),
                                (string)$append['folder'],
                                (int)$append['uid'],
                                (int)$emailId,
                                (string)($append['message_id'] ?? null)
                            );
                        }
                    } catch (Throwable $e) {
                        $smtpResult['imap_append_sent'] = ['success' => false, 'error' => $e->getMessage()];
                    }
                }
            } catch (Throwable $e) {
                $smtpResult = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Локальная доставка для пользователей TaskFlow (как было)
        if ($recipientId) {
            $stmt = $pdo->prepare("
                INSERT INTO mail_messages 
                (sender_id, recipient_id, recipient_email, subject, body, is_html, folder, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, 'inbox', NOW())
            ");
            $stmt->execute([$userId, $recipientId, $recipientEmail, $subject, $body, $isHtml]);

            createNotification($pdo, [
                'user_id' => (int)$recipientId,
                'sender_id' => (int)$userId,
                'message' => 'Новое письмо',
                'type' => 'mail',
                'related_id' => (int)$emailId,
            ]);
        }

        echo json_encode(['success' => true, 'data' => ['id' => $emailId], 'smtp' => $smtpResult]);
        exit;
    }

    // ============================================
    // DELETE /api/mail/emails/:id - Удалить письмо
    // ============================================
    if ($method === 'DELETE' && $action === 'emails' && is_numeric($id)) {
        $emailId = (int)$id;

        $stmt = $pdo->prepare("SELECT folder FROM mail_messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
        $stmt->execute([$emailId, $userId, $userId]);
        $email = $stmt->fetch();

        if (!$email) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Письмо не найдено']);
            exit;
        }

        // Если письмо уже в корзине — удаляем окончательно
        if (($email['folder'] ?? '') === 'trash') {
            $stmt = $pdo->prepare("DELETE FROM mail_messages WHERE id = ?");
            $stmt->execute([$emailId]);
        } else {
            // Иначе перемещаем в корзину
            $stmt = $pdo->prepare("UPDATE mail_messages SET folder = 'trash' WHERE id = ?");
            $stmt->execute([$emailId]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // POST /api/mail/purge - Очистка папки (trash/spam)
    // body: { folder: 'trash' | 'spam' }
    // ============================================
    if ($method === 'POST' && $action === 'purge') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $folder = (string)($data['folder'] ?? '');
        if (!in_array($folder, ['trash', 'spam'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная папка']);
            exit;
        }

        if ($folder === 'trash') {
            $stmt = $pdo->prepare("DELETE FROM mail_messages WHERE folder = 'trash' AND (sender_id = ? OR recipient_id = ?)");
            $stmt->execute([$userId, $userId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM mail_messages WHERE folder = 'spam' AND recipient_id = ?");
            $stmt->execute([$userId]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // POST /api/mail/star - отметить письмо важным
    // body: { email_id: 123, is_starred: 1|0 }
    // ============================================
    if ($method === 'POST' && $action === 'star') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $emailId = (int)($data['email_id'] ?? 0);
        $isStarred = !empty($data['is_starred']) ? 1 : 0;
        if ($emailId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверный id письма']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM mail_messages WHERE id = ? AND (sender_id = ? OR recipient_id = ? OR recipient_email = ?)");
        $stmt->execute([$emailId, $userId, $userId, $currentUser['email'] ?? $currentUser['login']]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Письмо не найдено']);
            exit;
        }

        $pdo->prepare("UPDATE mail_messages SET is_starred = ? WHERE id = ?")->execute([$isStarred, $emailId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // POST /api/mail/read - пометить письмо прочитанным/непрочитанным
    // body: { email_id: 123, is_read: 1|0 }
    // ============================================
    if ($method === 'POST' && $action === 'read') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $emailId = (int)($data['email_id'] ?? 0);
        $isRead = isset($data['is_read']) ? ($data['is_read'] ? 1 : 0) : 1;
        
        if ($emailId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверный id письма']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM mail_messages WHERE id = ? AND (sender_id = ? OR recipient_id = ? OR recipient_email = ?)");
        $stmt->execute([$emailId, $userId, $userId, $currentUser['email'] ?? $currentUser['login']]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Письмо не найдено']);
            exit;
        }

        $pdo->prepare("UPDATE mail_messages SET is_read = ? WHERE id = ?")->execute([$isRead, $emailId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // POST /api/mail/move - Переместить письмо
    // ============================================
    if ($method === 'POST' && $action === 'move') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['email_id']) || empty($data['folder'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        $validFolders = ['inbox', 'sent', 'drafts', 'spam', 'trash'];
        if (!in_array($data['folder'], $validFolders)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная папка']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE mail_messages 
            SET folder = ?
            WHERE id = ? AND (sender_id = ? OR recipient_id = ?)
        ");
        $stmt->execute([$data['folder'], $data['email_id'], $userId, $userId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // GET /api/mail/accounts - Почтовые аккаунты
    // ============================================
    if ($method === 'GET' && $action === 'accounts') {
        $stmt = $pdo->prepare("
            SELECT id, email, smtp_host, smtp_port, created_at
            FROM mail_accounts
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $accounts]);
        exit;
    }

    // ============================================
    // POST /api/mail/accounts - Добавить аккаунт
    // ============================================
    if ($method === 'POST' && $action === 'accounts') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['email']) || empty($data['smtp_host']) || empty($data['smtp_password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO mail_accounts 
            (user_id, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $data['email'],
            $data['smtp_host'],
            $data['smtp_port'] ?? 587,
            $data['smtp_username'] ?? $data['email'],
            password_hash($data['smtp_password'], PASSWORD_DEFAULT),
            $data['smtp_encryption'] ?? 'tls'
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // PUT /api/mail/accounts/:id - Обновить аккаунт
    // ============================================
    if ($method === 'PUT' && $action === 'accounts' && is_numeric($id)) {
        $accountId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);

        // Проверка что аккаунт принадлежит пользователю
        $stmt = $pdo->prepare("SELECT id FROM mail_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Аккаунт не найден']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE mail_accounts 
            SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_encryption = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([
            $data['smtp_host'] ?? null,
            $data['smtp_port'] ?? 587,
            $data['smtp_username'] ?? null,
            $data['smtp_encryption'] ?? 'tls',
            $accountId,
            $userId
        ]);

        // Если есть пароль - обновляем отдельно
        if (!empty($data['smtp_password'])) {
            $stmt = $pdo->prepare("UPDATE mail_accounts SET smtp_password = ? WHERE id = ?");
            $stmt->execute([password_hash($data['smtp_password'], PASSWORD_DEFAULT), $accountId]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // DELETE /api/mail/accounts/:id - Удалить аккаунт
    // ============================================
    if ($method === 'DELETE' && $action === 'accounts' && is_numeric($id)) {
        $accountId = (int)$id;

        $stmt = $pdo->prepare("DELETE FROM mail_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

function getUserMailSettings(PDO $pdo, int $userId): array {
    // 1) Пытаемся взять из mail_accounts (каноничное хранилище)
    $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    if ($account) {
        return [
            'email' => $account['email'],
            'smtp_host' => $account['smtp_host'],
            'smtp_port' => (int)$account['smtp_port'],
            'smtp_username' => $account['smtp_username'] ?: $account['email'],
            'smtp_password' => appDecrypt($account['smtp_password'] ?? null),
            'smtp_encryption' => $account['smtp_encryption'] ?: 'tls',
            'display_name' => $account['display_name'] ?: 'TaskFlow Pro',
            'signature' => $account['mail_signature'] ?: '',
            'imap_host' => $account['imap_host'] ?? '',
            'imap_port' => (int)($account['imap_port'] ?? 993),
            'imap_encryption' => $account['imap_encryption'] ?? 'ssl',
        ];
    }

    // 2) Фолбек на user_settings (используется в UI настроек)
    $stmt = $pdo->prepare("SELECT `key`, value FROM user_settings WHERE user_id = ? AND `key` LIKE 'mail_%'");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $s = [];
    foreach ($rows as $row) {
        $s[$row['key']] = $row['value'];
    }

    return [
        'email' => $s['mail_email'] ?? '',
        'smtp_host' => $s['mail_host'] ?? '',
        'smtp_port' => (int)($s['mail_port'] ?? 587),
        'smtp_username' => $s['mail_smtp_username'] ?? ($s['mail_username'] ?? ($s['mail_email'] ?? '')),
        'smtp_password' => isset($s['mail_password']) ? appDecrypt($s['mail_password']) : null,
        'smtp_encryption' => $s['mail_encryption'] ?? 'tls',
        'display_name' => $s['mail_from_name'] ?? 'TaskFlow Pro',
        'signature' => $s['mail_signature'] ?? '',
        'imap_host' => $s['mail_imap_host'] ?? '',
        'imap_port' => (int)($s['mail_imap_port'] ?? 993),
        'imap_encryption' => $s['mail_imap_encryption'] ?? 'ssl',
    ];
}

function buildImapMailboxString(string $host, int $port, string $encryption, string $folder = 'INBOX'): string {
    $flags = '/imap';
    if ($encryption === 'ssl') {
        $flags .= '/ssl';
    } elseif ($encryption === 'tls') {
        $flags .= '/tls';
    } else {
        $flags .= '/notls';
    }
    $flags .= '/novalidate-cert';

    return sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
}

function decodeMimeHeader(?string $value): string {
    if (!$value) return '';
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return $decoded !== false ? $decoded : $value;
}

function decodeBodyToUtf8(string $body, ?string $charset): string {
    $charset = $charset ? strtoupper(trim($charset)) : '';
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
        return $body;
    }
    $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
    return $converted !== false ? $converted : $body;
}

function parsePartParameters($params): array {
    $out = [];
    if (is_array($params)) {
        foreach ($params as $p) {
            if (!empty($p->attribute)) {
                $out[strtolower($p->attribute)] = $p->value ?? '';
            }
        }
    }
    return $out;
}

function getPartData($imap, int $uid, string $partNumber, int $encoding): string {
    $data = (string)imap_fetchbody($imap, (string)$uid, $partNumber, FT_UID | FT_PEEK);
    if ($encoding === ENCBASE64) {
        return base64_decode($data) ?: '';
    }
    if ($encoding === ENCQUOTEDPRINTABLE) {
        return quoted_printable_decode($data);
    }
    return $data;
}

function sanitizeFileName(string $name): string {
    $name = trim($name);
    $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    if ($name === '') $name = 'attachment';
    return $name;
}

function saveMailAttachment(PDO $pdo, int $mailMessageId, string $fileName, string $mimeType, string $content): void {
    $baseDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR;
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    $safeName = sanitizeFileName($fileName);
    $storedName = 'm' . $mailMessageId . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
    $path = $baseDir . $storedName;
    file_put_contents($path, $content);

    $stmt = $pdo->prepare("INSERT INTO mail_attachments (email_id, file_name, file_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$mailMessageId, $safeName, 'uploads/mail/' . $storedName, $mimeType, strlen($content)]);

    $pdo->prepare("UPDATE mail_messages SET has_attachments = 1 WHERE id = ?")->execute([$mailMessageId]);
}

function parseMessageBodyAndAttachments(PDO $pdo, $imap, int $uid, int $mailMessageId): array {
    $structure = imap_fetchstructure($imap, (string)$uid, FT_UID);
    if (!$structure) {
        return ['text' => '', 'html' => null, 'attachments' => 0];
    }

    $bestText = '';
    $bestHtml = null;
    $attachments = 0;

    $walk = function($part, string $partNumber) use (&$walk, $pdo, $imap, $uid, $mailMessageId, &$bestText, &$bestHtml, &$attachments) {
        $type = $part->type ?? 0;
        $subtype = strtoupper($part->subtype ?? '');
        $encoding = (int)($part->encoding ?? 0);

        $disp = strtolower($part->disposition ?? '');
        $params = array_merge(parsePartParameters($part->parameters ?? []), parsePartParameters($part->dparameters ?? []));
        $charset = $params['charset'] ?? null;

        $isAttachment = ($disp === 'attachment' || $disp === 'inline') && (!empty($params['filename']) || !empty($params['name']));
        if ($isAttachment) {
            $fileName = decodeMimeHeader($params['filename'] ?? ($params['name'] ?? 'attachment'));
            $mimeType = ($type === 0 ? 'text/' : ($type === 5 ? 'image/' : 'application/')) . strtolower($subtype ?: 'octet-stream');
            $content = getPartData($imap, $uid, $partNumber, $encoding);
            saveMailAttachment($pdo, $mailMessageId, $fileName, $mimeType, $content);
            $attachments++;
        } elseif ($type === 0) {
            // text
            $content = getPartData($imap, $uid, $partNumber, $encoding);
            $content = decodeBodyToUtf8($content, $charset);
            if ($subtype === 'HTML') {
                if ($bestHtml === null || strlen($content) > strlen((string)$bestHtml)) {
                    $bestHtml = $content;
                }
            } else {
                if ($bestText === '' || strlen($content) > strlen($bestText)) {
                    $bestText = $content;
                }
            }
        }

        if (!empty($part->parts) && is_array($part->parts)) {
            $i = 1;
            foreach ($part->parts as $sub) {
                $walk($sub, $partNumber === '' ? (string)$i : ($partNumber . '.' . $i));
                $i++;
            }
        }
    };

    if (!empty($structure->parts) && is_array($structure->parts)) {
        $i = 1;
        foreach ($structure->parts as $p) {
            $walk($p, (string)$i);
            $i++;
        }
    } else {
        // простое письмо без multipart
        $body = (string)imap_body($imap, (string)$uid, FT_UID | FT_PEEK);
        $bestText = trim($body);
    }

    return ['text' => trim($bestText), 'html' => $bestHtml !== null ? trim((string)$bestHtml) : null, 'attachments' => $attachments];
}

function extractEmailAddress(?string $from): string {
    if (!$from) return '';
    // "Name <email@domain>"
    if (preg_match('/<([^>]+)>/', $from, $m)) {
        return trim($m[1]);
    }
    return trim($from);
}

function syncImapFolder(PDO $pdo, int $userId, array $mailSettings, string $folder, int $limit = 50): array {
    if (empty($mailSettings['imap_host']) || empty($mailSettings['smtp_username']) || empty($mailSettings['smtp_password'])) {
        return ['success' => false, 'error' => 'IMAP не настроен (host/логин/пароль)'];
    }

    if (!function_exists('imap_open')) {
        return ['success' => false, 'error' => 'PHP расширение imap не установлено'];
    }

    $host = (string)$mailSettings['imap_host'];
    $port = (int)($mailSettings['imap_port'] ?? 993);
    $enc = (string)($mailSettings['imap_encryption'] ?? 'ssl');
    $username = (string)$mailSettings['smtp_username'];
    $password = (string)$mailSettings['smtp_password'];

    $mailbox = buildImapMailboxString($host, $port, $enc, $folder);
    $imap = @imap_open($mailbox, $username, $password);
    if (!$imap) {
        return ['success' => false, 'error' => imap_last_error() ?: 'Не удалось подключиться к IMAP'];
    }

    // Последние N писем (по UID, чтобы потом можно было инкрементально)
    $uids = imap_sort($imap, SORTARRIVAL, 1, SE_UID);
    if (!is_array($uids)) $uids = [];
    $uids = array_slice($uids, 0, $limit);

    $inserted = 0;

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        $stmt = $pdo->prepare("SELECT 1 FROM mail_imap_uids WHERE user_id = ? AND account_email = ? AND folder = ? AND uid = ?");
        $stmt->execute([$userId, $mailSettings['email'], $folder, $uid]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        $overviewList = imap_fetch_overview($imap, (string)$uid, FT_UID);
        $ov = $overviewList && isset($overviewList[0]) ? $overviewList[0] : null;
        if (!$ov) continue;

        $fromRaw = $ov->from ?? '';
        $subjectRaw = $ov->subject ?? '';
        $dateRaw = $ov->date ?? '';
        $messageId = $ov->message_id ?? null;

        $fromEmail = extractEmailAddress($fromRaw);
        $subject = decodeMimeHeader($subjectRaw);

        // MIME разбор выполняем после вставки (нужен mailMessageId для сохранения вложений)

        $sentAt = null;
        if ($dateRaw) {
            $ts = strtotime($dateRaw);
            if ($ts) {
                $sentAt = date('Y-m-d H:i:s', $ts);
            }
        }

        $isRead = !empty($ov->seen) ? 1 : 0;

        // Пытаемся сопоставить sender_id/recipient_id по пользователям TaskFlow
        $senderId = null;
        if ($fromEmail) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$fromEmail]);
            $senderId = $stmt->fetchColumn();
            $senderId = $senderId ? (int)$senderId : null;
        }

        $recipientId = $userId;
        $recipientEmail = (string)$mailSettings['email'];

        $localFolder = ($folder === 'INBOX') ? 'inbox' : 'sent';

        // Сначала создаём письмо (тело обновим после, когда будет id для вложений)
        $stmt = $pdo->prepare("
            INSERT INTO mail_messages
            (sender_id, recipient_id, recipient_email, imap_folder, subject, body, is_html, folder, is_read, has_attachments, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
        ");

        // временно пустое тело, затем заполним
        $stmt->execute([
            $senderId,
            $recipientId,
            $recipientEmail,
            $folder,
            $subject ?: '(без темы)',
            '',
            0,
            $localFolder,
            $isRead,
            $sentAt ?? date('Y-m-d H:i:s'),
        ]);

        $mailMessageId = (int)$pdo->lastInsertId();

        $parsed = parseMessageBodyAndAttachments($pdo, $imap, $uid, $mailMessageId);
        $bodyToSave = $parsed['html'] ?? $parsed['text'];
        $isHtml = $parsed['html'] !== null ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE mail_messages SET body = ?, is_html = ? WHERE id = ?");
        $stmt->execute([mb_substr($bodyToSave, 0, 65000), $isHtml, $mailMessageId]);

        $stmt = $pdo->prepare("INSERT INTO mail_imap_uids (user_id, account_email, folder, uid, message_id, mail_message_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $mailSettings['email'], $folder, $uid, $messageId, $mailMessageId]);

        $inserted++;
    }

    imap_close($imap);
    return ['success' => true, 'inserted' => $inserted];
}

function listImapFolders(array $mailSettings): array {
    if (empty($mailSettings['imap_host']) || empty($mailSettings['smtp_username']) || empty($mailSettings['smtp_password'])) {
        return ['success' => false, 'error' => 'IMAP не настроен (host/логин/пароль)'];
    }
    if (!function_exists('imap_open')) {
        return ['success' => false, 'error' => 'PHP расширение imap не установлено'];
    }

    $host = (string)$mailSettings['imap_host'];
    $port = (int)($mailSettings['imap_port'] ?? 993);
    $enc = (string)($mailSettings['imap_encryption'] ?? 'ssl');
    $username = (string)$mailSettings['smtp_username'];
    $password = (string)$mailSettings['smtp_password'];

    $mailbox = buildImapMailboxString($host, $port, $enc, 'INBOX');
    $imap = @imap_open($mailbox, $username, $password);
    if (!$imap) {
        return ['success' => false, 'error' => imap_last_error() ?: 'Не удалось подключиться к IMAP'];
    }

    $ref = sprintf('{%s:%d/imap%s/novalidate-cert}', $host, $port, $enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls'));
    $foldersRaw = imap_list($imap, $ref, '*');
    $result = [];
    if (is_array($foldersRaw)) {
        foreach ($foldersRaw as $f) {
            // пример: {host:993/imap/ssl/novalidate-cert}INBOX.Sent
            $folder = preg_replace('/^\{[^}]+\}/', '', $f);
            $result[] = $folder;
        }
    }
    imap_close($imap);
    sort($result);
    return ['success' => true, 'data' => $result];
}

function guessImapSentFolders(array $folders): array {
    $candidates = [];
    foreach ($folders as $f) {
        $lower = mb_strtolower($f);
        // типичные варианты у хостингов/почтовиков
        $needles = ['sent', 'sent items', 'отправ', 'outbox', 'envoy', 'gesendet'];
        foreach ($needles as $n) {
            if (str_contains($lower, $n)) {
                $candidates[] = $f;
                break;
            }
        }
    }

    // если не нашли по ключевым словам — пробуем "INBOX.Sent"/"Sent" как дефолты
    if (!$candidates) {
        foreach (['Sent', 'INBOX.Sent', 'Sent Items'] as $fallback) {
            if (in_array($fallback, $folders, true)) {
                $candidates[] = $fallback;
            }
        }
    }

    // убираем дубли
    $candidates = array_values(array_unique($candidates));
    return $candidates;
}

function imapAppendSent(PDO $pdo, int $userId, array $mailSettings, string $subject, string $body, bool $isHtml, array $attachments, string $toEmail): array {
    if (!function_exists('imap_open')) {
        return ['success' => false, 'error' => 'PHP расширение imap не установлено'];
    }
    if (empty($mailSettings['imap_host']) || empty($mailSettings['smtp_username']) || empty($mailSettings['smtp_password'])) {
        return ['success' => false, 'error' => 'IMAP не настроен'];
    }

    $list = listImapFolders($mailSettings);
    if (!($list['success'] ?? false)) {
        return $list;
    }
    $folders = $list['data'] ?? [];
    $candidates = guessImapSentFolders($folders);
    if (!$candidates) {
        return ['success' => false, 'error' => 'Не удалось определить папку Отправленные'];
    }
    $sentFolder = $candidates[0];

    $host = (string)$mailSettings['imap_host'];
    $port = (int)($mailSettings['imap_port'] ?? 993);
    $enc = (string)($mailSettings['imap_encryption'] ?? 'ssl');
    $username = (string)$mailSettings['smtp_username'];
    $password = (string)$mailSettings['smtp_password'];

    $mailbox = buildImapMailboxString($host, $port, $enc, 'INBOX');
    $imap = @imap_open($mailbox, $username, $password);
    if (!$imap) {
        return ['success' => false, 'error' => imap_last_error() ?: 'Не удалось подключиться к IMAP'];
    }

    $fromEmail = (string)($mailSettings['email'] ?: $mailSettings['smtp_username']);
    $fromName = (string)($mailSettings['display_name'] ?: 'TaskFlow Pro');

    $boundaryMixed = 'tf_mixed_' . bin2hex(random_bytes(8));
    $boundaryAlt = 'tf_alt_' . bin2hex(random_bytes(8));
    $messageId = '<tf.' . bin2hex(random_bytes(12)) . '@taskflow.local>';

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromEmail}>";
    $headers[] = 'To: ' . $toEmail;
    $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
    $headers[] = 'Message-ID: ' . $messageId;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';

    $plain = $isHtml ? strip_tags($body) : $body;
    $html = $isHtml ? $body : null;

    $mime = implode("\r\n", $headers) . "\r\n\r\n";
    $mime .= "--{$boundaryMixed}\r\n";
    $mime .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

    // text/plain
    $mime .= "--{$boundaryAlt}\r\n";
    $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $mime .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $mime .= quoted_printable_encode($plain) . "\r\n\r\n";

    // text/html
    if ($html !== null) {
        $mime .= "--{$boundaryAlt}\r\n";
        $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mime .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $mime .= quoted_printable_encode($html) . "\r\n\r\n";
    }

    $mime .= "--{$boundaryAlt}--\r\n\r\n";

    // attachments
    foreach ($attachments as $att) {
        $abs = realpath(__DIR__ . '/../' . ($att['file_path'] ?? ''));
        if (!$abs || !is_file($abs)) continue;
        $fileName = (string)($att['file_name'] ?? basename($abs));
        $fileNameEnc = mb_encode_mimeheader($fileName, 'UTF-8');
        $content = file_get_contents($abs);
        if ($content === false) continue;
        $b64 = chunk_split(base64_encode($content));
        $mimeType = (string)($att['mime_type'] ?? 'application/octet-stream');

        $cid = $att['cid'] ?? null;
        $isInline = !empty($att['inline']) && $cid;
        $disp = $isInline ? 'inline' : 'attachment';

        $mime .= "--{$boundaryMixed}\r\n";
        $mime .= "Content-Type: {$mimeType}; name=\"{$fileNameEnc}\"\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n";
        $mime .= "Content-Disposition: {$disp}; filename=\"{$fileNameEnc}\"\r\n";
        if ($isInline) {
            $mime .= "Content-ID: <{$cid}>\r\n";
        }
        $mime .= "\r\n" . $b64 . "\r\n";
    }

    $mime .= "--{$boundaryMixed}--\r\n";

    $ref = sprintf('{%s:%d/imap%s/novalidate-cert}%s', $host, $port, $enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls'), $sentFolder);
    $ok = @imap_append($imap, $ref, $mime, "\\Seen");
    if (!$ok) {
        $err = imap_last_error() ?: 'IMAP APPEND error';
        imap_close($imap);
        return ['success' => false, 'error' => $err, 'folder' => $sentFolder];
    }

    // Пытаемся найти добавленное письмо в этой папке и вернуть UID
    $search = @imap_search($imap, 'HEADER Message-ID "' . addslashes(trim($messageId, '<>')) . '"', SE_UID);
    $uid = null;
    if (is_array($search) && !empty($search)) {
        rsort($search);
        $uid = (int)$search[0];
    }

    imap_close($imap);
    return ['success' => true, 'folder' => $sentFolder, 'message_id' => $messageId, 'uid' => $uid];
}

function linkImapUidToLocalMessage(PDO $pdo, int $userId, string $accountEmail, string $folder, int $uid, int $mailMessageId, ?string $messageId = null): void {
    $stmt = $pdo->prepare("INSERT IGNORE INTO mail_imap_uids (user_id, account_email, folder, uid, message_id, mail_message_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $accountEmail, $folder, $uid, $messageId, $mailMessageId]);
}

function sendEmailViaUserSmtp(PDO $pdo, int $userId, string $to, string $subject, string $body, bool $isHtml = false, array $attachments = []): array {
    if (!class_exists(PHPMailer::class)) {
        return ['success' => false, 'error' => 'PHPMailer не установлен'];
    }

    $settings = getUserMailSettings($pdo, $userId);
    if (empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
        return ['success' => false, 'error' => 'SMTP не настроен'];
    }
    if (empty($settings['smtp_password'])) {
        return ['success' => false, 'error' => 'Пароль SMTP не сохранён (нужен plaintext). Откройте настройки почты и сохраните пароль заново.'];
    }

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();
    $mail->Host = $settings['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $settings['smtp_username'];
    $mail->Password = $settings['smtp_password'];

    $enc = $settings['smtp_encryption'] ?? 'tls';
    if ($enc === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($enc === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }
    $mail->Port = (int)($settings['smtp_port'] ?? 587);

    $fromEmail = $settings['email'] ?: $settings['smtp_username'];
    $fromName = $settings['display_name'] ?: 'TaskFlow Pro';
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($to);

    $finalBody = $body;
    if (!empty($settings['signature'])) {
        if ($isHtml) {
            $sig = nl2br(htmlspecialchars($settings['signature'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $finalBody .= '<hr style="border:none;border-top:1px solid rgba(0,0,0,.12);margin:16px 0" />'
                . '<div style="font-size:12px;opacity:.85">' . $sig . '</div>';
        } else {
            $finalBody .= "\n\n--\n" . $settings['signature'];
        }
    }

    $mail->isHTML($isHtml);
    $mail->Subject = $subject;
    if ($isHtml) {
        $mail->Body = $finalBody;
        $mail->AltBody = strip_tags($finalBody);
    } else {
        $mail->Body = $finalBody;
    }

    foreach ($attachments as $att) {
        $abs = realpath(__DIR__ . '/../' . ($att['file_path'] ?? ''));
        if (!$abs || !is_file($abs)) continue;

        $name = (string)($att['file_name'] ?? basename($abs));
        $cid = $att['cid'] ?? null;
        $isInline = !empty($att['inline']) && $cid;

        if ($isInline) {
            $mail->addEmbeddedImage($abs, (string)$cid, $name);
        } else {
            $mail->addAttachment($abs, $name);
        }
    }

    try {
        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function processOutgoingAttachments(PDO $pdo, int $userId, int $mailMessageId): array {
    $attachments = [];
    if (empty($_FILES['attachments'])) {
        return $attachments;
    }

    $files = $_FILES['attachments'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    $maxFiles = 10;
    $maxSize = 15 * 1024 * 1024; // 15MB each

    for ($i = 0; $i < $count && $i < $maxFiles; $i++) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) continue;

        $tmp = $files['tmp_name'][$i] ?? null;
        $origName = (string)($files['name'][$i] ?? 'attachment');
        $size = (int)($files['size'][$i] ?? 0);
        if (!$tmp || $size <= 0 || $size > $maxSize) continue;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : 'application/octet-stream';
        if ($finfo) finfo_close($finfo);

        $baseDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR;
        if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $safeName = sanitizeFileName($origName);
        $storedName = 'out_m' . $mailMessageId . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
        $path = $baseDir . $storedName;

        if (!move_uploaded_file($tmp, $path)) continue;

        $relPath = 'uploads/mail/' . $storedName;
        $stmt = $pdo->prepare("INSERT INTO mail_attachments (email_id, file_name, file_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$mailMessageId, $safeName, $relPath, $mime, $size]);

        $attachments[] = [
            'file_name' => $safeName,
            'file_path' => $relPath,
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }

    if ($attachments) {
        $pdo->prepare("UPDATE mail_messages SET has_attachments = 1 WHERE id = ?")->execute([$mailMessageId]);
    }

    return $attachments;
}

function processOutgoingInlineImages(PDO $pdo, int $mailMessageId, string $htmlBody): array {
    // Ищем data URL картинки и превращаем их в CID вложения
    $inline = [];
    if (trim($htmlBody) === '') return ['html' => $htmlBody, 'inline' => $inline];

    $pattern = '/<img[^>]+src=["\'](data:image\/(png|jpe?g|gif|webp);base64,([^"\']+))["\'][^>]*>/i';
    $idx = 0;

    $htmlBody = preg_replace_callback($pattern, function($m) use ($pdo, $mailMessageId, &$inline, &$idx) {
        $fullDataUrl = $m[1];
        $ext = strtolower($m[2]);
        $b64 = $m[3];
        $bin = base64_decode($b64);
        if ($bin === false || strlen($bin) === 0) {
            return $m[0];
        }

        $idx++;
        $cid = 'inline' . $mailMessageId . '_' . $idx . '@taskflow';
        $fileName = 'image_' . $idx . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);

        // сохраняем как обычное вложение, чтобы было видно и в интерфейсе
        saveMailAttachment($pdo, $mailMessageId, $fileName, $mime, $bin);
        $inline[] = [
            'cid' => $cid,
            'file_name' => $fileName,
            // file_path обновим после сохранения (берём из БД)
        ];

        // заменяем src на cid
        return str_replace($fullDataUrl, 'cid:' . $cid, $m[0]);
    }, $htmlBody);

    // Подтянем file_path для новых inline вложений по имени
    if ($inline) {
        foreach ($inline as &$it) {
            $stmt = $pdo->prepare("SELECT file_path FROM mail_attachments WHERE email_id = ? AND file_name = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$mailMessageId, $it['file_name']]);
            $it['file_path'] = $stmt->fetchColumn() ?: null;
        }
        unset($it);
    }

    return ['html' => $htmlBody, 'inline' => $inline];
}
