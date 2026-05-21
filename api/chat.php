<?php
/**
 * api/chat.php - Онлайн чат / мессенджер с расширенными функциями
 *
 * Эндпоинты:
 * - GET /api/chat/rooms - список чатов
 * - GET /api/chat/rooms/:id/messages - сообщения чата
 * - POST /api/chat/rooms - создать чат (private/group/project)
 * - DELETE /api/chat/rooms/:id - удалить чат
 * - POST /api/chat/messages - отправить сообщение
 * - PUT /api/chat/messages/:id/read - прочитать сообщение
 * - PUT /api/chat/messages/:id - редактировать сообщение
 * - DELETE /api/chat/messages/:id - удалить сообщение
 * - POST /api/chat/messages/:id/forward - переслать сообщение
 * - POST /api/chat/typing - индикатор набора текста
 * - POST /api/chat/presence - статус онлайн пользователя (TTL)
 * - GET /api/chat/presence - онлайн/typing статусы по комнате
 * - POST /api/chat/webrtc - отправка offer/answer/ice
 * - GET /api/chat/webrtc - long-poll получение событий webrtc
 * - GET /api/chat/search - поиск по сообщениям
 * - POST /api/chat/messages - отправка голоса/файлов
 */

/**
 * Обработка запросов к /api/chat/*
 */
function handleChat(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
    } catch (Exception $e) {}

    // Some hosts/proxies strip Authorization on GET navigations.
    // Support token-in-query for debugging and direct browser calls.
    try {
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_GET['token'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$_GET['token'];
        }
    } catch (Exception $e) {}

    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $userId = $currentUser['id'];

    $ensureUsersLastActivityColumn = function() use ($pdo): void {
        ensureUsersLastActivityColumn($pdo);
    };

    try {
        $ensureUsersLastActivityColumn();
    } catch (Exception $e) {
        error_log('chat.php: failed to ensure users.last_activity: ' . $e->getMessage());
    }

    $chatMessageColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM chat_messages");
        $chatMessageColumns = array_map(static fn($r) => (string)$r['Field'], $stmt->fetchAll());
    } catch (Exception $e) {
        $chatMessageColumns = [];
    }

    $chatMemberColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM chat_room_members");
        $chatMemberColumns = array_map(static fn($r) => (string)$r['Field'], $stmt->fetchAll());
    } catch (Exception $e) {
        $chatMemberColumns = [];
    }

    require_once __DIR__ . '/disk.php';

    // ============================================
    // POST /api/chat/presence - обновить свой онлайн (TTL)
    // ============================================
    if ($method === 'POST' && $action === 'presence') {
        $data = json_decode(file_get_contents('php://input'), true);

        $roomId = isset($data['room_id']) ? (int)$data['room_id'] : null;
        $ttlSeconds = isset($data['ttl']) ? max(5, min(120, (int)$data['ttl'])) : 25;

        $untilSql = "DATE_ADD(NOW(), INTERVAL {$ttlSeconds} SECOND)";

        // Backward-compatible: older DBs may not have online_until yet
        if (!in_array('online_until', $chatMemberColumns, true)) {
            echo json_encode(['success' => true]);
            exit;
        }

        try {
            if ($roomId) {
                $stmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
                $stmt->execute([$roomId, $userId]);

                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE chat_room_members SET online_until = {$untilSql} WHERE room_id = ? AND user_id = ?");
                    $stmt->execute([$roomId, $userId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE chat_room_members SET online_until = {$untilSql} WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка presence']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // GET /api/chat/presence?room_id=... - онлайн/typing по комнате
    // ============================================
    if ($method === 'GET' && $action === 'presence') {
        $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
        if (!$roomId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите room_id']);
            exit;
        }

        $accessStmt = $pdo->prepare("SELECT type, user1_id, user2_id, name, created_by FROM chat_rooms WHERE id = ?");
        $accessStmt->execute([$roomId]);
        $room = $accessStmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $canAccess = false;
        if ($room['type'] === 'private') {
            $canAccess = ($room['user1_id'] === $userId || $room['user2_id'] === $userId);
        } elseif ($room['type'] === 'project') {
            $projectId = (int)str_replace('project_', '', (string)$room['name']);
            $projStmt = $pdo->prepare("
                SELECT 1 FROM project_departments pd
                JOIN user_departments ud ON pd.department_id = ud.department_id
                WHERE pd.project_id = ? AND ud.user_id = ?
                UNION
                SELECT 1 FROM projects WHERE id = ? AND created_by = ?
            ");
            $projStmt->execute([$projectId, $userId, $projectId, $userId]);
            $canAccess = (bool)$projStmt->fetch() || $room['created_by'] == $userId;
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$roomId, $userId]);
            $canAccess = (bool)$memberStmt->fetch();
        }

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
            exit;
        }

        $hasOnlineUntil = in_array('online_until', $chatMemberColumns, true);
        $hasTypingUntil = in_array('typing_until', $chatMemberColumns, true);

        $stmt = $pdo->prepare("
            SELECT crm.user_id,
                   " . ($hasOnlineUntil ? "(crm.online_until IS NOT NULL AND crm.online_until > NOW())" : "0") . " as is_online,
                   " . ($hasTypingUntil ? "(crm.typing_until IS NOT NULL AND crm.typing_until > NOW())" : "0") . " as is_typing
            FROM chat_room_members crm
            WHERE crm.room_id = ?
        ");
        $stmt->execute([$roomId]);
        $rows = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ============================================
    // WebRTC signaling (self-hosted)
    // POST /api/chat/webrtc - push offer/answer/ice
    // GET  /api/chat/webrtc?call_id=...&since_id=...&timeout=... - long-poll
    // ============================================
    if ($action === 'webrtc') {
        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);

            $callId = isset($data['call_id']) ? (int)$data['call_id'] : 0;
            $type = $data['type'] ?? null;
            $payload = $data['payload'] ?? null;

            if (!$callId || !in_array($type, ['offer', 'answer', 'ice'], true) || !$payload) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
                exit;
            }

            // access: only call participants
            $stmt = $pdo->prepare("SELECT caller_id, recipient_id FROM chat_calls WHERE id = ?");
            $stmt->execute([$callId]);
            $call = $stmt->fetch();
            if (!$call || ((int)$call['caller_id'] !== (int)$userId && (int)$call['recipient_id'] !== (int)$userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }

            $toUserId = ((int)$call['caller_id'] === (int)$userId) ? (int)$call['recipient_id'] : (int)$call['caller_id'];

            $stmt = $pdo->prepare("
                INSERT INTO webrtc_sessions (call_id, session_type, sdp_data, candidate_data, is_processed)
                VALUES (?, ?, ?, ?, 0)
            ");

            $sdpData = null;
            $candidateData = null;
            if ($type === 'offer' || $type === 'answer') {
                $sdpData = json_encode(['to_user_id' => $toUserId, 'from_user_id' => (int)$userId, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            } else {
                $candidateData = json_encode(['to_user_id' => $toUserId, 'from_user_id' => (int)$userId, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            }

            $stmt->execute([$callId, $type, $sdpData, $candidateData]);
            $insertId = (int)$pdo->lastInsertId();

            // Some existing DBs have a bad/default schema where session_type becomes '' (empty).
            // Enforce session_type after insert as a best-effort fix.
            try {
                $pdo->prepare("UPDATE webrtc_sessions SET session_type = ? WHERE id = ? AND (session_type IS NULL OR session_type = '')")
                    ->execute([$type, $insertId]);
            } catch (Exception $e) {
                // best-effort
            }

            // Verify what was actually written (helps diagnose DB/schema triggers)
            $writtenType = null;
            try {
                $check = $pdo->prepare("SELECT session_type FROM webrtc_sessions WHERE id = ?");
                $check->execute([$insertId]);
                $writtenType = $check->fetchColumn();
                if (($writtenType === null || $writtenType === '') && $type) {
                    $pdo->prepare("UPDATE webrtc_sessions SET session_type = ? WHERE id = ?")
                        ->execute([$type, $insertId]);
                    $check->execute([$insertId]);
                    $writtenType = $check->fetchColumn();
                }
            } catch (Exception $e) {
                $writtenType = null;
            }

            echo json_encode(['success' => true, 'data' => ['id' => $insertId, 'session_type_written' => $writtenType]]);
            exit;
        }

        if ($method === 'GET') {
            $callId = isset($_GET['call_id']) ? (int)$_GET['call_id'] : 0;
            $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
            $timeout = isset($_GET['timeout']) ? max(3, min(25, (int)$_GET['timeout'])) : 20;
            $debug = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

            if (!$callId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите call_id']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT caller_id, recipient_id FROM chat_calls WHERE id = ?");
            $stmt->execute([$callId]);
            $call = $stmt->fetch();
            if (!$call || ((int)$call['caller_id'] !== (int)$userId && (int)$call['recipient_id'] !== (int)$userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }

            $deadline = time() + $timeout;
            do {
                $stmt = $pdo->prepare("
                    SELECT id, session_type, sdp_data, candidate_data, created_at
                    FROM webrtc_sessions
                    WHERE call_id = ? AND id > ? AND is_processed = 0
                    ORDER BY id ASC
                    LIMIT 50
                ");
                $stmt->execute([$callId, $sinceId]);
                $rows = $stmt->fetchAll();

                $events = [];
                $lastId = $sinceId;
                foreach ($rows as $r) {
                    $dataJson = $r['session_type'] === 'ice' ? $r['candidate_data'] : $r['sdp_data'];
                    $decoded = $dataJson ? json_decode($dataJson, true) : null;
                    if (!$decoded) continue;

                    // deliver only to intended user
                    if (isset($decoded['to_user_id']) && (int)$decoded['to_user_id'] !== (int)$userId) {
                        continue;
                    }

                    $events[] = [
                        'id' => (int)$r['id'],
                        'type' => $r['session_type'],
                        'payload' => $decoded['data'] ?? null,
                        'from_user_id' => $decoded['from_user_id'] ?? null,
                        'created_at' => $r['created_at']
                    ];
                    $lastId = max($lastId, (int)$r['id']);
                }

                if (!empty($events)) {
                    // mark delivered for this receiver
                    $ids = array_map(fn($e) => (int)$e['id'], $events);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("UPDATE webrtc_sessions SET is_processed = 1 WHERE id IN ({$placeholders})")->execute($ids);

                    echo json_encode(['success' => true, 'data' => ['events' => $events, 'last_id' => $lastId]]);
                    exit;
                }

                usleep(300000);
            } while (time() < $deadline);

            if ($debug === 1) {
                // Debug info: show last few events regardless of receiver filter/processed
                try {
                    $dbgStmt = $pdo->prepare("SELECT id, session_type, sdp_data, candidate_data, is_processed, created_at FROM webrtc_sessions WHERE call_id = ? ORDER BY id DESC LIMIT 20");
                    $dbgStmt->execute([$callId]);
                    $dbgRows = $dbgStmt->fetchAll();
                    echo json_encode(['success' => true, 'data' => ['events' => [], 'last_id' => $sinceId, 'debug' => $dbgRows]]);
                    exit;
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'data' => ['events' => [], 'last_id' => $sinceId]]);
            exit;
        }
    }

    // ============================================
    // GET /api/chat/rooms - список чатов пользователя
    // ============================================
    if ($method === 'GET' && $action === 'rooms' && $subaction === null) {
        // Для групповых чатов - через chat_room_members
        $stmt = $pdo->prepare("
            SELECT 
                cr.id as room_id,
                cr.type,
                CASE WHEN cr.type = 'project' THEN cr.avatar ELSE cr.name END as room_name,
                cr.avatar as room_avatar,
                cr.created_at as room_created,
                -- Для приватных чатов - собеседник
                CASE WHEN cr.type = 'private' THEN
                    CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END
                ELSE NULL END as interlocutor_id,
                CASE WHEN cr.type = 'private' THEN
                    (SELECT full_name FROM users WHERE id = 
                        CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END
                    )
                ELSE NULL END as interlocutor_name,
                CASE WHEN cr.type = 'private' THEN
                    (SELECT avatar FROM users WHERE id = 
                        CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END
                    )
                ELSE cr.avatar END as interlocutor_avatar,
                CASE WHEN cr.type = 'private' THEN
                    (SELECT last_activity FROM users WHERE id = 
                        CASE WHEN cr.user1_id = ? THEN cr.user2_id ELSE cr.user1_id END
                    )
                ELSE NULL END as interlocutor_last_activity,
                -- Последнее сообщение
                (SELECT message FROM chat_messages WHERE room_id = cr.id AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM chat_messages WHERE room_id = cr.id AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1) as last_message_time,
                -- Непрочитанные (новая модель: через chat_message_reads)
                (SELECT COUNT(*)
                   FROM chat_messages m
                   LEFT JOIN widget_chat_sessions ws_unread ON ws_unread.room_id = m.room_id
                  WHERE m.room_id = cr.id
                    AND (
                        m.sender_id IS NULL
                        OR m.sender_id != ?
                        OR (
                            ws_unread.id IS NOT NULL
                            AND m.sender_id = ws_unread.operator_user_id
                            AND m.recipient_id = ws_unread.operator_user_id
                        )
                    )
                    AND m.deleted_at IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM chat_message_reads r
                        WHERE r.message_id = m.id AND r.user_id = ?
                    )
                ) as unread_count,
                -- Статус онлайн (для приватных)
                CASE WHEN cr.type = 'private' THEN
                    (SELECT typing_until IS NOT NULL AND typing_until > NOW() FROM chat_room_members WHERE room_id = cr.id AND user_id != ? LIMIT 1)
                ELSE NULL END as interlocutor_typing
            FROM chat_rooms cr
            LEFT JOIN chat_room_members crm ON crm.room_id = cr.id AND crm.user_id = ?
            WHERE cr.type = 'private' AND (cr.user1_id = ? OR cr.user2_id = ?)
               OR cr.type != 'private' AND crm.id IS NOT NULL
            ORDER BY last_message_time DESC
        ");
        $stmt->execute([
            $userId, $userId, $userId, $userId,
            $userId, $userId,
            $userId, $userId, $userId, $userId
        ]);
        $rooms = $stmt->fetchAll();

        try {
            $widgetSessionStmt = $pdo->query("SELECT room_id, visitor_name, visitor_email, visitor_phone, ticket_id FROM widget_chat_sessions");
            $widgetSessions = [];
            foreach ($widgetSessionStmt->fetchAll() as $sessionRow) {
                $widgetSessions[(int)$sessionRow['room_id']] = $sessionRow;
            }

            foreach ($rooms as &$roomRow) {
                $roomId = (int)($roomRow['room_id'] ?? 0);
                if (!isset($widgetSessions[$roomId])) {
                    continue;
                }

                $session = $widgetSessions[$roomId];
                $roomRow['is_widget_session'] = true;
                $roomRow['widget_ticket_id'] = !empty($session['ticket_id']) ? (int)$session['ticket_id'] : null;
                $roomRow['visitor_email'] = $session['visitor_email'] ?? null;
                $roomRow['visitor_phone'] = $session['visitor_phone'] ?? null;
                if (($roomRow['type'] ?? '') === 'private') {
                    $roomRow['interlocutor_name'] = $session['visitor_name'] ?: ($roomRow['interlocutor_name'] ?? 'Посетитель сайта');
                }
            }
            unset($roomRow);
        } catch (Exception $e) {
            // widget_chat_sessions may not exist yet
        }

        echo json_encode(['success' => true, 'data' => $rooms]);
        exit;
    }

    // ============================================
    // GET /api/chat/rooms/:id/messages - сообщения чата
    // Supports long-poll:
    //   /messages?since_id=123&timeout=20
    // ============================================
    if ($method === 'GET' && $action === 'rooms' && is_numeric($id) && $subaction === 'messages') {
        $roomId = (int)$id;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
        $timeout = isset($_GET['timeout']) ? max(3, min(25, (int)$_GET['timeout'])) : 0;

        // Проверка доступа
        $accessStmt = $pdo->prepare("
            SELECT id, type, name, user1_id, user2_id, created_by FROM chat_rooms WHERE id = ?
        ");
        $accessStmt->execute([$roomId]);
        $room = $accessStmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        if ($room['type'] === 'private') {
            if ((int)$room['user1_id'] !== (int)$userId && (int)$room['user2_id'] !== (int)$userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
                exit;
            }
        } elseif ($room['type'] === 'project') {
            // Для проектных чатов - доступ у всех участников проекта
            $projectId = (int)str_replace('project_', '', $room['name']);
            $projStmt = $pdo->prepare("
                SELECT 1 FROM project_departments pd
                JOIN user_departments ud ON pd.department_id = ud.department_id
                WHERE pd.project_id = ? AND ud.user_id = ?
                UNION
                SELECT 1 FROM tasks t
                JOIN task_responsibles tr ON t.id = tr.task_id
                WHERE t.project_id = ? AND tr.user_id = ?
                UNION
                SELECT 1 FROM projects WHERE id = ? AND (created_by = ? OR department_id IN (
                    SELECT department_id FROM user_departments WHERE user_id = ?
                ))
            ");
            $projStmt->execute([$projectId, $userId, $projectId, $userId, $projectId, $userId, $userId]);
            if (!$projStmt->fetch() && $room['created_by'] != $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа к чату проекта']);
                exit;
            }
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$roomId, $userId]);
            if (!$memberStmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
                exit;
            }
        }

        // Long-poll: wait until new messages appear after since_id.
        // In long-poll mode we do NOT mark messages as read automatically.
        if ($timeout > 0 && $sinceId > 0) {
            $timeout = max(1, min(25, (int)$timeout));
            @set_time_limit($timeout + 5);
            $started = microtime(true);
            $sleepUs = 250000; // 250ms

            // Prevent multiple concurrent long-poll loops per user+room.
            // This protects php-fpm workers and reduces DB load.
            $lockKey = 'tf_chat_lp:' . (string)$userId . ':' . (string)$roomId;
            $gotLock = false;
            try {
                $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                $lockStmt->execute([$lockKey]);
                $gotLock = (string)$lockStmt->fetchColumn() === '1';
            } catch (Throwable $e) {
                $gotLock = false;
            }
            if (!$gotLock) {
                // If another request is already long-polling, don't block. Return quickly.
                $timeout = 0;
            }

            try {
                while ((microtime(true) - $started) < $timeout) {
                    if (function_exists('connection_aborted') && connection_aborted()) break;
                    $stmt = $pdo->prepare("SELECT 1 FROM chat_messages WHERE room_id = ? AND deleted_at IS NULL AND id > ? LIMIT 1");
                    $stmt->execute([$roomId, $sinceId]);
                    if ($stmt->fetchColumn()) break;
                    // Add tiny jitter to reduce thundering herd effect.
                    usleep($sleepUs + random_int(0, 30000));
                }
            } finally {
                if ($gotLock) {
                    try {
                        $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                        $unlockStmt->execute([$lockKey]);
                    } catch (Throwable $e) {
                        // best-effort
                    }
                }
            }
        } else {
            // Фиксируем факт чтения всех входящих сообщений в комнате
            $stmt = $pdo->prepare("
                INSERT INTO chat_message_reads (message_id, user_id)
                SELECT cm.id, ?
                FROM chat_messages cm
                LEFT JOIN widget_chat_sessions ws_read ON ws_read.room_id = cm.room_id
                WHERE cm.room_id = ?
                  AND (
                      cm.sender_id IS NULL
                      OR cm.sender_id != ?
                      OR (
                          ws_read.id IS NOT NULL
                          AND cm.sender_id = ws_read.operator_user_id
                          AND cm.recipient_id = ws_read.operator_user_id
                      )
                  )
                  AND cm.deleted_at IS NULL
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ");
            $stmt->execute([$userId, $roomId, $userId]);

            // Старый fallback для инсталляций с recipient_id (безопасно, если строк нет)
            $stmt = $pdo->prepare("
                UPDATE chat_messages
                SET is_read = 1, status = 'read'
                WHERE room_id = ? AND sender_id != ? AND recipient_id = ?
            ");
            $stmt->execute([$roomId, $userId, $userId]);
        }

        $stmt = $pdo->prepare("
            SELECT cm.*,
                   CASE
                       WHEN ws.id IS NOT NULL
                        AND cm.sender_id = ws.operator_user_id
                        AND cm.recipient_id = ws.operator_user_id
                       THEN COALESCE(ws.visitor_name, 'Посетитель сайта')
                       ELSE COALESCE(u.full_name, ws.visitor_name, 'Посетитель сайта')
                   END as sender_name,
                   CASE
                       WHEN ws.id IS NOT NULL
                        AND cm.sender_id = ws.operator_user_id
                        AND cm.recipient_id = ws.operator_user_id
                       THEN NULL
                       ELSE u.avatar
                   END as sender_avatar,
                   ws.operator_user_id as operator_user_id,
                   EXISTS(
                       SELECT 1 FROM chat_message_reads cmr_self
                       WHERE cmr_self.message_id = cm.id AND cmr_self.user_id = ?
                   ) as _read_by_me,
                   EXISTS(
                       SELECT 1 FROM chat_message_reads cmr_other
                        WHERE cmr_other.message_id = cm.id AND cmr_other.user_id <> cm.sender_id
                   ) as _read_by_other,
                   -- Данные для reply
                   reply.message as reply_message,
                   reply.sender_id as reply_sender_id,
                   reply_u.full_name as reply_sender_name,
                   -- Данные для forwarded
                   fwd.message as fwd_message,
                   fwd_u.full_name as fwd_sender_name,
                   -- Данные для задачи
                   t.title as task_title, t.status as task_status, t.priority as task_priority,
                   -- Данные для проекта
                   p.name as project_name, p.priority as project_priority
            FROM chat_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            LEFT JOIN widget_chat_sessions ws ON ws.room_id = cm.room_id
            LEFT JOIN chat_messages reply ON cm.reply_to_id = reply.id
            LEFT JOIN users reply_u ON reply.sender_id = reply_u.id
            LEFT JOIN chat_messages fwd ON cm.forwarded_from_id = fwd.id
            LEFT JOIN users fwd_u ON fwd.sender_id = fwd_u.id
            LEFT JOIN tasks t ON cm.task_id = t.id
            LEFT JOIN projects p ON cm.project_id = p.id
            WHERE cm.room_id = ? AND cm.deleted_at IS NULL
            " . ($sinceId > 0 ? " AND cm.id > " . (int)$sinceId . "" : "") . "
            ORDER BY cm.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $roomId, $limit, $offset]);
        $messages = array_reverse($stmt->fetchAll());

        foreach ($messages as &$msg) {
            $isWidgetVisitorMessage = (
                !empty($msg['operator_user_id'])
                && (int)$msg['sender_id'] === (int)$msg['operator_user_id']
                && (int)($msg['recipient_id'] ?? 0) === (int)$msg['operator_user_id']
            );
            $isOwn = !$isWidgetVisitorMessage && ((int)$msg['sender_id'] === (int)$userId);
            $isRead = $isOwn ? ((int)$msg['_read_by_other'] === 1) : ((int)$msg['_read_by_me'] === 1);
            $msg['is_own'] = $isOwn ? 1 : 0;
            $msg['is_read'] = $isRead ? 1 : 0;
            $msg['status'] = $isRead ? 'read' : 'delivered';
            if ($isWidgetVisitorMessage) {
                $msg['sender_name'] = $msg['sender_name'] ?: 'Посетитель сайта';
                $msg['sender_avatar'] = null;
            }
            unset($msg['_read_by_me'], $msg['_read_by_other']);
        }
        unset($msg);

        echo json_encode(['success' => true, 'data' => $messages]);
        exit;
    }

    // ============================================
    // POST /api/chat/rooms - создать чат
    // ============================================
    if ($method === 'POST' && ($action === 'rooms' || $action === null)) {
        $data = json_decode(file_get_contents('php://input'), true);

        $type = $data['type'] ?? 'private';

        if ($type === 'private') {
            if (empty($data['user_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите собеседника']);
                exit;
            }

            $otherUserId = (int)$data['user_id'];

            // Проверяем существующий чат
            $stmt = $pdo->prepare("
                SELECT id FROM chat_rooms
                WHERE type = 'private' 
                  AND ((user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?))
            ");
            $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
            $room = $stmt->fetch();

            if ($room) {
                echo json_encode(['success' => true, 'data' => ['room_id' => $room['id'], 'created' => false]]);
                exit;
            }

            // Создаём новый приватный чат
            $stmt = $pdo->prepare("
                INSERT INTO chat_rooms (type, user1_id, user2_id, created_by)
                VALUES ('private', ?, ?, ?)
            ");
            $stmt->execute([$userId, $otherUserId, $userId]);

            echo json_encode([
                'success' => true,
                'data' => ['room_id' => $pdo->lastInsertId(), 'created' => true]
            ]);
            exit;
        }

        if ($type === 'group') {
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите название группы']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO chat_rooms (type, name, avatar, created_by)
                VALUES ('group', ?, ?, ?)
            ");
            $stmt->execute([$data['name'], $data['avatar'] ?? null, $userId]);
            $roomId = $pdo->lastInsertId();

            // Добавляем создателя как админа
            $stmt = $pdo->prepare("
                INSERT INTO chat_room_members (room_id, user_id, role)
                VALUES (?, ?, 'admin')
            ");
            $stmt->execute([$roomId, $userId]);

            // Добавляем участников
            if (!empty($data['members']) && is_array($data['members'])) {
                $memberStmt = $pdo->prepare("
                    INSERT INTO chat_room_members (room_id, user_id, role)
                    VALUES (?, ?, 'member')
                ");
                foreach ($data['members'] as $memberId) {
                    if ($memberId != $userId) {
                        $memberStmt->execute([$roomId, (int)$memberId]);
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'data' => ['room_id' => $roomId, 'created' => true]
            ]);
            exit;
        }

        if ($type === 'project') {
            if (empty($data['project_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите проект']);
                exit;
            }

            // Авто-создание чата проекта
            $projectId = (int)$data['project_id'];

            // Получаем название проекта
            $projStmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
            $projStmt->execute([$projectId]);
            $project = $projStmt->fetch();
            
            if (!$project) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Проект не найден']);
                exit;
            }

            // Проверяем существующий чат проекта
            $stmt = $pdo->prepare("
                SELECT id FROM chat_rooms WHERE type = 'project' AND name = CONCAT('project_', ?)
            ");
            $stmt->execute([$projectId]);
            $room = $stmt->fetch();

            if ($room) {
                echo json_encode(['success' => true, 'data' => ['room_id' => $room['id'], 'created' => false]]);
                exit;
            }

            // Создаём чат с именем проекта
            $stmt = $pdo->prepare("
                INSERT INTO chat_rooms (type, name, created_by)
                VALUES ('project', ?, ?)
            ");
            $chatName = 'Чат проекта: ' . $project['name'];
            $stmt->execute([$chatName, $userId]);
            $roomId = $pdo->lastInsertId();

            // Добавляем создателя как админа
            $memberStmt = $pdo->prepare("
                INSERT INTO chat_room_members (room_id, user_id, role)
                VALUES (?, ?, 'admin')
            ");
            $memberStmt->execute([$roomId, $userId]);

            // Добавляем всех участников проекта (из departments проекта)
            $membersStmt = $pdo->prepare("
                SELECT DISTINCT ud.user_id
                FROM project_departments pd
                JOIN user_departments ud ON pd.department_id = ud.department_id
                WHERE pd.project_id = ? AND ud.user_id != ?
            ");
            $membersStmt->execute([$projectId, $userId]);
            $projectMembers = $membersStmt->fetchAll(PDO::FETCH_COLUMN);

            $memberStmt = $pdo->prepare("
                INSERT INTO chat_room_members (room_id, user_id, role)
                VALUES (?, ?, 'member')
            ");
            foreach ($projectMembers as $memberId) {
                $memberStmt->execute([$roomId, (int)$memberId]);
            }
            
            // Добавляем ROOT пользователя если его еще нет
            $rootStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'root' LIMIT 1");
            $rootStmt->execute();
            $rootUser = $rootStmt->fetch();
            
            if ($rootUser && $rootUser['id'] != $userId) {
                // Проверяем не добавлен ли уже
                $checkRoot = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
                $checkRoot->execute([$roomId, $rootUser['id']]);
                if (!$checkRoot->fetch()) {
                    $memberStmt->execute([$roomId, $rootUser['id']]);
                }
            }

            echo json_encode([
                'success' => true,
                'data' => ['room_id' => $roomId, 'created' => true, 'name' => $chatName]
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неверный тип чата']);
    }

    // ============================================
    // DELETE /api/chat/rooms/:id - удалить чат
    // ============================================
    if ($method === 'DELETE' && $action === 'rooms' && is_numeric($id)) {
        $roomId = (int)$id;

        $stmt = $pdo->prepare("SELECT id, type, user1_id, user2_id, created_by FROM chat_rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $isPrivateParticipant = ((int)$room['user1_id'] === (int)$userId || (int)$room['user2_id'] === (int)$userId);
        $memberRole = null;
        if ($room['type'] !== 'private') {
            $memberStmt = $pdo->prepare("SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ? LIMIT 1");
            $memberStmt->execute([$roomId, $userId]);
            $memberRole = $memberStmt->fetchColumn();
        }

        $canDelete = false;
        if ($room['type'] === 'private') {
            $canDelete = $isPrivateParticipant;
        } else {
            $canDelete = ((int)$room['created_by'] === (int)$userId || $memberRole === 'admin');
        }

        if (!$canDelete) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет прав для удаления чата']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            try {
                $pdo->prepare("DELETE FROM conferences WHERE room_id = ?")->execute([(string)$roomId]);
            } catch (Exception $e) {
                // conferences may be absent or use unrelated room_id format on some installations
            }

            try {
                $pdo->prepare("DELETE FROM chat_forwards WHERE from_room_id = ? OR to_room_id = ?")->execute([$roomId, $roomId]);
            } catch (Exception $e) {
                // chat_forwards may be absent on some installations
            }

            try {
                $callIdsStmt = $pdo->prepare("SELECT id FROM chat_calls WHERE room_id = ?");
                $callIdsStmt->execute([$roomId]);
                $callIds = array_map('intval', $callIdsStmt->fetchAll(PDO::FETCH_COLUMN));
                if (!empty($callIds)) {
                    $callPlaceholders = implode(',', array_fill(0, count($callIds), '?'));
                    $pdo->prepare("DELETE FROM webrtc_sessions WHERE call_id IN ({$callPlaceholders})")
                        ->execute($callIds);
                }
                $pdo->prepare("DELETE FROM chat_calls WHERE room_id = ?")->execute([$roomId]);
            } catch (Exception $e) {
                // chat_calls / webrtc_sessions may be absent on some installations
            }

            try {
                $pdo->prepare("DELETE FROM widget_chat_sessions WHERE room_id = ?")->execute([$roomId]);
            } catch (Exception $e) {
                // widget_chat_sessions may be absent or room_id may be constrained differently
            }

            $deleteRoomStmt = $pdo->prepare("DELETE FROM chat_rooms WHERE id = ?");

            try {
                $deleteRoomStmt->execute([$roomId]);
            } catch (Exception $deleteRoomError) {
                // Fallback for legacy installs where FK cascade on chat tables is absent or inconsistent.
                $messageIdsStmt = $pdo->prepare("SELECT id FROM chat_messages WHERE room_id = ?");
                $messageIdsStmt->execute([$roomId]);
                $messageIds = array_map('intval', $messageIdsStmt->fetchAll(PDO::FETCH_COLUMN));

                if (!empty($messageIds)) {
                    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                    $pdo->prepare("DELETE FROM chat_message_reads WHERE message_id IN ({$placeholders})")
                        ->execute($messageIds);
                }

                $pdo->prepare("DELETE FROM chat_messages WHERE room_id = ?")->execute([$roomId]);
                $pdo->prepare("DELETE FROM chat_room_members WHERE room_id = ?")->execute([$roomId]);
                $deleteRoomStmt->execute([$roomId]);
            }

            if ($deleteRoomStmt->rowCount() < 1) {
                throw new RuntimeException('Chat room delete affected 0 rows');
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[chat.deleteRoom] room_id=' . $roomId . ' user_id=' . $userId . ' error=' . $e->getMessage());

            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Не удалось удалить чат']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Чат удалён']);
        exit;
    }

    // ============================================
    // POST /api/chat/messages - отправить сообщение
    // ============================================
    if ($method === 'POST' && $action === 'messages') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['room_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите чат']);
            exit;
        }

        $roomId = (int)$data['room_id'];
        $messageType = $data['message_type'] ?? 'text';
        $message = $data['message'] ?? '';
        $replyToId = $data['reply_to_id'] ?? null;
        $forwardedFromId = $data['forwarded_from_id'] ?? null;

        // Проверка доступа к чату
        $stmt = $pdo->prepare("SELECT type, user1_id, user2_id FROM chat_rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $canAccess = false;
        if ($room['type'] === 'private') {
            $canAccess = ((int)$room['user1_id'] === (int)$userId || (int)$room['user2_id'] === (int)$userId);
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$roomId, $userId]);
            $canAccess = (bool)$memberStmt->fetch();
        }

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
            exit;
        }

        // Для голосовых сообщений
        $voiceDuration = $data['voice_duration'] ?? null;
        $voiceWaveform = $data['voice_waveform'] ?? null;
        
        // Для файлов
        $fileName = $data['file_name'] ?? null;
        $fileUrl = $data['file_url'] ?? null;
        $mimeType = $data['mime_type'] ?? null;
        
        // Для стикеров
        $stickerId = $data['sticker_id'] ?? null;
        $stickerUrl = $data['sticker_url'] ?? null;
        $stickerType = $data['sticker_type'] ?? 'emoji';
        
        // Для задач и проектов
        $taskId = $data['task_id'] ?? null;
        $taskTitle = $data['task_title'] ?? null;
        $taskStatus = $data['task_status'] ?? null;
        $taskPriority = $data['task_priority'] ?? null;
        $projectId = $data['project_id'] ?? null;
        $projectName = $data['project_name'] ?? null;
        $projectPriority = $data['project_priority'] ?? null;

        // Валидация
        if ($messageType === 'text' && empty($message)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
            exit;
        }

        if ($messageType === 'voice' && !$voiceDuration) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная длительность голоса']);
            exit;
        }

        // Получаем получателей (для групповых)
        $recipients = [];
        if ($room['type'] === 'private') {
                $recipientId = ((int)$room['user1_id'] === (int)$userId) ? $room['user2_id'] : $room['user1_id'];
            $recipients[] = $recipientId;
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id != ?");
            $stmt->execute([$roomId, $userId]);
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Создаём сообщение (одна запись на комнату)
        $recipientNullable = true;
        if (in_array('recipient_id', $chatMessageColumns, true)) {
            try {
                $col = $pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'recipient_id'")->fetch();
                if ($col && isset($col['Null'])) {
                    $recipientNullable = (strtoupper((string)$col['Null']) === 'YES');
                }
            } catch (Exception $e) {
                $recipientNullable = true;
            }
        }

        $recipientValue = null;
        if (!$recipientNullable) {
            // Old schema: keep backward compatibility (store per-recipient)
            $recipientValue = ($room['type'] === 'private') ? ($recipients[0] ?? null) : null;
            if (!$recipientValue) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Схема БД не поддерживает recipient_id=NULL. Запустите миграцию 005.']);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO chat_messages
            (room_id, sender_id, recipient_id, message, message_type, voice_duration, voice_waveform,
             file_name, file_url, mime_type, sticker_id, sticker_url, sticker_type, task_id, project_id,
             task_title, task_status, task_priority, project_name, project_priority,
             reply_to_id, forwarded_from_id, is_read, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $roomId, $userId, $recipientValue, $message, $messageType,
            $voiceDuration, $voiceWaveform,
            $fileName, $fileUrl, $mimeType,
            $stickerId, $stickerUrl, $stickerType,
            $taskId, $projectId,
            $taskTitle, $taskStatus, $taskPriority,
            $projectName, $projectPriority,
            $replyToId, $forwardedFromId,
            0, 'delivered'
        ]);
        $messageId = (int)$pdo->lastInsertId();

        // Уведомления
        foreach ($recipients as $recipientId) {
            $notifText = $messageType === 'voice' ? 'Голосовое сообщение' :
                        ($messageType === 'file' ? 'Файл' : 'Новое сообщение в чате');
            createNotification($pdo, [
                'user_id' => (int)$recipientId,
                'sender_id' => (int)$userId,
                'message' => $notifText,
                'type' => 'chat',
                'related_id' => (int)$roomId,
            ]);
        }

        try {
            $widgetSessionStmt = $pdo->prepare("SELECT id, ticket_id, operator_user_id FROM widget_chat_sessions WHERE room_id = ? LIMIT 1");
            $widgetSessionStmt->execute([$roomId]);
            $widgetSession = $widgetSessionStmt->fetch();
            if ($widgetSession && !empty($widgetSession['ticket_id'])) {
                $fromOperator = ((int)$widgetSession['operator_user_id'] === (int)$userId);
                $commentStmt = $pdo->prepare("
                    INSERT INTO helpdesk_comments (ticket_id, user_id, is_internal, message, attachments)
                    VALUES (?, ?, 0, ?, NULL)
                ");
                $commentStmt->execute([
                    (int)$widgetSession['ticket_id'],
                    $fromOperator ? $userId : null,
                    $message
                ]);

                $commentId = (int)$pdo->lastInsertId();
                $historyStmt = $pdo->prepare("
                    INSERT INTO helpdesk_history (ticket_id, user_id, action, field_name, old_value, new_value, meta)
                    VALUES (?, ?, 'comment', NULL, NULL, ?, ?)
                ");
                $historyStmt->execute([
                    (int)$widgetSession['ticket_id'],
                    $fromOperator ? $userId : null,
                    (string)$commentId,
                    json_encode(['source' => $fromOperator ? 'widget-chat-operator' : 'widget-chat-visitor'], JSON_UNESCAPED_UNICODE)
                ]);

                $updateField = $fromOperator ? 'last_operator_message_at' : 'last_guest_message_at';
                $pdo->prepare("UPDATE widget_chat_sessions SET {$updateField} = NOW() WHERE id = ?")
                    ->execute([(int)$widgetSession['id']]);
            }
        } catch (Exception $e) {
            // best-effort sync for widget sessions
        }

        echo json_encode([
            'success' => true,
            'data' => ['id' => $messageId, 'message' => $message]
        ]);
        exit;
    }

    // ============================================
    // PUT /api/chat/messages/:id - редактировать сообщение
    // ============================================
    if ($method === 'PUT' && $action === 'messages' && is_numeric($id)) {
        $messageId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
            exit;
        }

        // Проверка: пользователь - отправитель
        $stmt = $pdo->prepare("SELECT sender_id FROM chat_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();

        if (!$msg || (int)$msg['sender_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет прав для редактирования']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE chat_messages 
            SET message = ?, edited_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$data['message'], $messageId]);

        echo json_encode(['success' => true, 'message' => 'Сообщение отредактировано']);
        exit;
    }

    // ============================================
    // DELETE /api/chat/messages/:id - удалить сообщение
    // ============================================
    if ($method === 'DELETE' && $action === 'messages' && is_numeric($id)) {
        $messageId = (int)$id;

        $stmt = $pdo->prepare("SELECT sender_id, room_id FROM chat_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();

        if (!$msg) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Сообщение не найдено']);
            exit;
        }

        // Проверка прав (отправитель или админ чата)
        $canDelete = ((int)$msg['sender_id'] === (int)$userId);
        
        if (!$canDelete) {
            $roomStmt = $pdo->prepare("
                SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ?
            ");
            $roomStmt->execute([$msg['room_id'], $userId]);
            $member = $roomStmt->fetch();
            $canDelete = ($member && $member['role'] === 'admin');
        }

        if (!$canDelete) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет прав для удаления']);
            exit;
        }

        // Мягкое удаление
        $stmt = $pdo->prepare("UPDATE chat_messages SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$messageId]);

        echo json_encode(['success' => true, 'message' => 'Сообщение удалено']);
        exit;
    }

    // ============================================
    // POST /api/chat/messages/:id/forward - переслать сообщение
    // ============================================
    if ($method === 'POST' && $action === 'messages' && is_numeric($id)) {
        $messageId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['to_room_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите чат для пересылки']);
            exit;
        }

        $toRoomId = (int)$data['to_room_id'];

        // Получаем исходное сообщение
        $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $originalMsg = $stmt->fetch();

        if (!$originalMsg) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Сообщение не найдено']);
            exit;
        }

        // Проверка доступа к целевому чату
        $accessStmt = $pdo->prepare("SELECT type, user1_id, user2_id FROM chat_rooms WHERE id = ?");
        $accessStmt->execute([$toRoomId]);
        $targetRoom = $accessStmt->fetch();

        if (!$targetRoom) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $canAccess = false;
        if ($targetRoom['type'] === 'private') {
            $canAccess = ($targetRoom['user1_id'] === $userId || $targetRoom['user2_id'] === $userId);
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$toRoomId, $userId]);
            $canAccess = (bool)$memberStmt->fetch();
        }

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
            exit;
        }

        // Создаём пересланное сообщение
        // Копируем все поля из оригинального сообщения
        $stmt = $pdo->prepare("
            INSERT INTO chat_messages
            (room_id, sender_id, recipient_id, message, message_type, voice_duration, voice_waveform,
             file_name, file_url, mime_type, sticker_id, sticker_url, sticker_type, task_id, project_id,
             task_title, task_status, task_priority, project_name, project_priority,
             forwarded_from_id, is_read, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered')
        ");

        // Получаем получателей в целевом чате
        if ($targetRoom['type'] === 'private') {
            $recipientId = ($targetRoom['user1_id'] === $userId) ? $targetRoom['user2_id'] : $targetRoom['user1_id'];
            $stmt->execute([
                $toRoomId, $userId, $recipientId,
                $originalMsg['message'], $originalMsg['message_type'],
                $originalMsg['voice_duration'], $originalMsg['voice_waveform'],
                $originalMsg['file_name'], $originalMsg['file_url'], $originalMsg['mime_type'],
                $originalMsg['sticker_id'], $originalMsg['sticker_url'], $originalMsg['sticker_type'] ?? 'emoji',
                $originalMsg['task_id'], $originalMsg['project_id'],
                $originalMsg['task_title'] ?? null, $originalMsg['task_status'] ?? null, $originalMsg['task_priority'] ?? null,
                $originalMsg['project_name'] ?? null, $originalMsg['project_priority'] ?? null,
                $messageId, 0
            ]);
        } else {
            $memberStmt = $pdo->prepare("SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id != ?");
            $memberStmt->execute([$toRoomId, $userId]);
            $recipients = $memberStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($recipients as $recipientId) {
                $stmt->execute([
                    $toRoomId, $userId, $recipientId,
                    $originalMsg['message'], $originalMsg['message_type'],
                    $originalMsg['voice_duration'], $originalMsg['voice_waveform'],
                    $originalMsg['file_name'], $originalMsg['file_url'], $originalMsg['mime_type'],
                    $originalMsg['sticker_id'], $originalMsg['sticker_url'], $originalMsg['sticker_type'] ?? 'emoji',
                    $originalMsg['task_id'], $originalMsg['project_id'],
                    $originalMsg['task_title'] ?? null, $originalMsg['task_status'] ?? null, $originalMsg['task_priority'] ?? null,
                    $originalMsg['project_name'] ?? null, $originalMsg['project_priority'] ?? null,
                    $messageId, 0
                ]);
            }
        }

        // Запись в chat_forwards
        $fwdStmt = $pdo->prepare("
            INSERT INTO chat_forwards (message_id, from_room_id, to_room_id, forwarded_by)
            VALUES (?, ?, ?, ?)
        ");
        $fwdStmt->execute([$messageId, $originalMsg['room_id'], $toRoomId, $userId]);

        echo json_encode(['success' => true, 'message' => 'Сообщение переслано']);
        exit;
    }

    // ============================================
    // PUT /api/chat/messages/:id/read - прочитать сообщение
    // ============================================
    if ($method === 'PUT' && $action === 'messages' && is_numeric($id)) {
        $messageId = (int)$id;

        $stmt = $pdo->prepare("
            UPDATE chat_messages
            SET is_read = 1, status = 'read'
            WHERE id = ? AND recipient_id = ?
        ");
        $stmt->execute([$messageId, $userId]);

        // Запись в chat_message_reads
        $stmt = $pdo->prepare("
            INSERT INTO chat_message_reads (message_id, user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ");
        $stmt->execute([$messageId, $userId]);

        echo json_encode(['success' => true, 'message' => 'Сообщение прочитано']);
        exit;
    }

    // ============================================
    // POST /api/chat/typing - индикатор набора текста
    // ============================================
    if ($method === 'POST' && $action === 'typing') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['room_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите чат']);
            exit;
        }

        $roomId = (int)$data['room_id'];
        $typingSeconds = 3; // Показываем индикатор 3 секунды

        // Проверяем членство
        $stmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute([$roomId, $userId]);

        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE chat_room_members
                SET typing_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE room_id = ? AND user_id = ?
            ");
            $stmt->execute([$typingSeconds, $roomId, $userId]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // GET /api/chat/search - поиск по сообщениям
    // ============================================
    if ($method === 'GET' && $action === 'search') {
        $query = $_GET['q'] ?? '';
        $roomId = $_GET['room_id'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

        if (strlen($query) < 2) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Введите минимум 2 символа']);
            exit;
        }

        // Поиск по своим чатам
        $sql = "
            SELECT cm.*, u.full_name as sender_name, cr.type as room_type
            FROM chat_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            LEFT JOIN widget_chat_sessions ws ON ws.room_id = cm.room_id
            JOIN chat_rooms cr ON cm.room_id = cr.id
            WHERE cm.deleted_at IS NULL
              AND (cm.message LIKE ? OR COALESCE(u.full_name, ws.visitor_name, 'Посетитель сайта') LIKE ?)
        ";

        $params = ["%$query%", "%$query%"];

        // Фильтр по чату
        if ($roomId) {
            $sql .= " AND cm.room_id = ?";
            $params[] = $roomId;
        }

        // Фильтр по доступным чатам
        $sql .= " AND (
            (cr.type = 'private' AND (cr.user1_id = ? OR cr.user2_id = ?))
            OR (cr.type != 'private' AND EXISTS (
                SELECT 1 FROM chat_room_members WHERE room_id = cr.id AND user_id = ?
            ))
        )";
        $params = array_merge($params, [$userId, $userId, $userId]);

        $sql .= " ORDER BY cm.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $messages]);
        exit;
    }

    // ============================================
    // GET /api/chat/users - список пользователей
    // ============================================
    if ($method === 'GET' && $action === 'users') {
        $stmt = $pdo->prepare("
            SELECT id, login, full_name, avatar, department_id
            FROM users
            WHERE id != ?
              AND login NOT LIKE 'widget_guest_%'
            ORDER BY full_name ASC
        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $users]);
        exit;
    }

    // ============================================
    // GET /api/chat/members/:room_id - участники чата
    // ============================================
    if ($method === 'GET' && $action === 'members' && is_numeric($id)) {
        $roomId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.avatar, u.login, crm.role, crm.typing_until IS NOT NULL AND crm.typing_until > NOW() as is_typing
            FROM chat_room_members crm
            JOIN users u ON crm.user_id = u.id
            WHERE crm.room_id = ?
            ORDER BY crm.role DESC, u.full_name ASC
        ");
        $stmt->execute([$roomId]);
        $members = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $members]);
        exit;
    }

    // ============================================
    // POST /api/chat/voice - отправка голосового сообщения
    // ============================================
    if ($method === 'POST' && $action === 'voice') {
        if (!isset($_POST['room_id']) || !isset($_POST['duration'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        $roomId = (int)$_POST['room_id'];
        $duration = (int)$_POST['duration'];
        $waveform = $_POST['waveform'] ?? null;

        if ($roomId < 1 || $duration < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Проверка доступа к чату
        $accessStmt = $pdo->prepare("SELECT type, user1_id, user2_id FROM chat_rooms WHERE id = ?");
        $accessStmt->execute([$roomId]);
        $room = $accessStmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $canAccess = false;
        if ($room['type'] === 'private') {
            $canAccess = ($room['user1_id'] === $userId || $room['user2_id'] === $userId);
        } elseif ($room['type'] === 'project') {
            // Для проектных чатов - доступ у всех участников проекта
            $projectId = (int)str_replace('project_', '', $room['name']);
            $projStmt = $pdo->prepare("
                SELECT 1 FROM project_departments pd
                JOIN user_departments ud ON pd.department_id = ud.department_id
                WHERE pd.project_id = ? AND ud.user_id = ?
                UNION
                SELECT 1 FROM projects WHERE id = ? AND created_by = ?
            ");
            $projStmt->execute([$projectId, $userId, $projectId, $userId]);
            $canAccess = (bool)$projStmt->fetch() || $room['created_by'] == $userId;
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$roomId, $userId]);
            $canAccess = (bool)$memberStmt->fetch();
        }

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
            exit;
        }

        // Обработка файла
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }

        $file = $_FILES['audio'];
        $uploadDir = __DIR__ . '/../uploads/voice/';

        // Создаём директорию если не существует
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Генерируем уникальное имя
        $fileName = 'voice_' . time() . '_' . uniqid() . '.webm';
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
            exit;
        }

        // Получатели (только для уведомлений)
        $recipients = [];
        if ($room['type'] === 'private') {
            $recipientId = ($room['user1_id'] === $userId) ? $room['user2_id'] : $room['user1_id'];
            $recipients[] = $recipientId;
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id != ?");
            $stmt->execute([$roomId, $userId]);
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Одна запись сообщения на комнату (recipient_id = NULL) — либо fallback на старую схему
        $recipientNullable = true;
        if (in_array('recipient_id', $chatMessageColumns, true)) {
            try {
                $col = $pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'recipient_id'")->fetch();
                if ($col && isset($col['Null'])) {
                    $recipientNullable = (strtoupper((string)$col['Null']) === 'YES');
                }
            } catch (Exception $e) {
                $recipientNullable = true;
            }
        }

        $recipientValue = null;
        if (!$recipientNullable) {
            $recipientValue = ($room['type'] === 'private') ? ($recipients[0] ?? null) : null;
            if (!$recipientValue) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Схема БД не поддерживает recipient_id=NULL. Запустите миграцию 005.']);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO chat_messages
            (room_id, sender_id, recipient_id, message, message_type, voice_duration, voice_waveform, file_url, is_read, status)
            VALUES (?, ?, ?, '', 'voice', ?, ?, ?, 0, 'delivered')
        ");
        $stmt->execute([
            $roomId,
            $userId,
            $recipientValue,
            $duration,
            $waveform,
            'uploads/voice/' . $fileName
        ]);
        $messageId = (int)$pdo->lastInsertId();

        // Уведомления
        foreach ($recipients as $recipientId) {
            createNotification($pdo, [
                'user_id' => (int)$recipientId,
                'sender_id' => (int)$userId,
                'message' => 'Голосовое сообщение',
                'type' => 'chat',
                'related_id' => (int)$roomId,
            ]);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $messageId,
                'file_url' => 'uploads/voice/' . $fileName,
                'message_type' => 'voice',
                'voice_duration' => $duration,
                'voice_waveform' => $waveform
            ]
        ]);
        exit;
    }

    // ============================================
    // POST /api/chat/files - отправка файла в чат
    // ============================================
    if ($method === 'POST' && $action === 'files') {
        if (empty($_POST['room_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите чат']);
            exit;
        }

        $roomId = (int)$_POST['room_id'];
        $fileType = $_POST['file_type'] ?? 'file';

        // Проверка доступа к чату
        $accessStmt = $pdo->prepare("SELECT type, user1_id, user2_id FROM chat_rooms WHERE id = ?");
        $accessStmt->execute([$roomId]);
        $room = $accessStmt->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Чат не найден']);
            exit;
        }

        $canAccess = false;
        if ($room['type'] === 'private') {
            $canAccess = ($room['user1_id'] === $userId || $room['user2_id'] === $userId);
        } elseif ($room['type'] === 'project') {
            $projectId = (int)str_replace('project_', '', $room['name']);
            $projStmt = $pdo->prepare("
                SELECT 1 FROM project_departments pd
                JOIN user_departments ud ON pd.department_id = ud.department_id
                WHERE pd.project_id = ? AND ud.user_id = ?
                UNION
                SELECT 1 FROM projects WHERE id = ? AND created_by = ?
            ");
            $projStmt->execute([$projectId, $userId, $projectId, $userId]);
            $canAccess = (bool)$projStmt->fetch() || $room['created_by'] == $userId;
        } else {
            $memberStmt = $pdo->prepare("SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?");
            $memberStmt->execute([$roomId, $userId]);
            $canAccess = (bool)$memberStmt->fetch();
        }

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа к чату']);
            exit;
        }

        // Обработка файла
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }

        $file = $_FILES['file'];
        $uploadDir = __DIR__ . '/../uploads/chat_files/';

        // Создаём директорию если не существует
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Генерируем уникальное имя
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'chat_' . time() . '_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
            exit;
        }

        // Определяем тип сообщения
        $mimeType = $file['type'] ?? 'application/octet-stream';
        $messageType = 'file';
        if ($fileType === 'image' || (is_string($mimeType) && strpos($mimeType, 'image/') === 0)) {
            $messageType = 'image';
        }

        // Также кладём в Disk: telegram/<user>
        $diskFileId = null;
        try {
            $folders = ensureTelegramDiskFolders($pdo, (int)$userId, $currentUser['full_name'] ?? null);
            $userFolderId = (int)$folders['user_id'];

            $stmt = $pdo->prepare("
                INSERT INTO files (name, original_name, mime_type, size, folder_id, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $fileName,
                $file['name'],
                $mimeType,
                (int)($file['size'] ?? 0),
                $userFolderId,
                (int)$userId
            ]);
            $diskFileId = (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            // Disk - best-effort
        }

        // Одна запись сообщения на комнату
        $stmt = $pdo->prepare("
            INSERT INTO chat_messages
            (room_id, sender_id, recipient_id, message, message_type, file_name, file_url, mime_type, is_read, status)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, 'delivered')
        ");
        $stmt->execute([
            $roomId, $userId,
            $file['name'],
            $messageType,
            $file['name'],
            'uploads/chat_files/' . $fileName,
            $mimeType
        ]);
        $messageId = (int)$pdo->lastInsertId();

        // Получатели (для уведомлений)
        $recipients = [];
        if ($room['type'] === 'private') {
            $recipientId = ($room['user1_id'] === $userId) ? $room['user2_id'] : $room['user1_id'];
            $recipients[] = $recipientId;
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id != ?");
            $stmt->execute([$roomId, $userId]);
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Уведомления
        foreach ($recipients as $recipientId) {
            $notifText = $messageType === 'image' ? 'Изображение' : 'Файл';
            createNotification($pdo, [
                'user_id' => (int)$recipientId,
                'sender_id' => (int)$userId,
                'message' => $notifText,
                'type' => 'chat',
                'related_id' => (int)$roomId,
            ]);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $messageId,
                'file_url' => 'uploads/chat_files/' . $fileName,
                'file_name' => $file['name'],
                'mime_type' => $mimeType,
                'message_type' => $messageType,
                'disk_file_id' => $diskFileId
            ]
        ]);
        exit;
    }

    // ============================================
    // GET /api/chat/calls - проверка входящих звонков
    // Supports long-poll:
    //   /api/chat/calls?timeout=20
    // ============================================
    if ($method === 'GET' && $action === 'calls') {
        try {
            $timeout = isset($_GET['timeout']) ? max(3, min(25, (int)$_GET['timeout'])) : 0;
            if ($timeout > 0) {
                @set_time_limit($timeout + 5);
                $started = microtime(true);
                $sleepUs = 250000; // 250ms

                // Prevent multiple concurrent long-poll loops per user.
                $lockKey = 'tf_calls_lp:' . (string)$userId;
                $gotLock = false;
                try {
                    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                    $lockStmt->execute([$lockKey]);
                    $gotLock = (string)$lockStmt->fetchColumn() === '1';
                } catch (Throwable $e) {
                    $gotLock = false;
                }
                if (!$gotLock) {
                    $timeout = 0;
                }

                try {
                    while ((microtime(true) - $started) < $timeout) {
                        if (function_exists('connection_aborted') && connection_aborted()) break;
                        $stmt = $pdo->prepare("SELECT 1 FROM chat_calls WHERE recipient_id = ? AND status = 'calling' AND is_seen = 0 LIMIT 1");
                        $stmt->execute([$userId]);
                        if ($stmt->fetchColumn()) break;
                        usleep($sleepUs + random_int(0, 30000));
                    }
                } finally {
                    if ($gotLock) {
                        try {
                            $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                            $unlockStmt->execute([$lockKey]);
                        } catch (Throwable $e) {
                            // best-effort
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("
                SELECT cc.*, u.full_name as caller_name
                FROM chat_calls cc
                JOIN users u ON cc.caller_id = u.id
                WHERE cc.recipient_id = ? AND cc.status = 'calling' AND cc.is_seen = 0
                ORDER BY cc.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $call = $stmt->fetch();

            if ($call) {
                // Помечаем как просмотренный
                $pdo->prepare("UPDATE chat_calls SET is_seen = 1 WHERE id = ?")->execute([$call['id']]);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'call_id' => $call['id'],
                        'caller_name' => $call['caller_name'],
                        'call_type' => $call['call_type'],
                        'room_id' => $call['room_id']
                    ]
                ]);
            } else {
                echo json_encode(['success' => true, 'data' => null]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'data' => null]);
        }
        exit;
    }

    // ============================================
    // POST /api/chat/calls - создать звонок
    // ============================================
    if ($method === 'POST' && $action === 'calls') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['recipient_id']) || empty($data['call_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Проверка доступа/получателя по комнате (если передали room_id)
        if (!empty($data['room_id'])) {
            $roomId = (int)$data['room_id'];
            $stmt = $pdo->prepare("SELECT type, user1_id, user2_id FROM chat_rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $room = $stmt->fetch();

            if ($room && $room['type'] === 'private') {
                $expected = ($room['user1_id'] == $userId) ? (int)$room['user2_id'] : (int)$room['user1_id'];
                if ($expected && (int)$data['recipient_id'] !== $expected) {
                    $data['recipient_id'] = $expected;
                }
            }
        }

        try {
            // Создаём звонок
            $stmt = $pdo->prepare("
                INSERT INTO chat_calls (caller_id, recipient_id, call_type, status, room_id, started_at)
                VALUES (?, ?, ?, 'calling', ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $data['recipient_id'],
                $data['call_type'],
                $data['room_id'] ?? null
            ]);
            
            $callId = $pdo->lastInsertId();

            // Создаём уведомление
            $notifText = $data['call_type'] === 'video' ? 'Видеозвонок' : 'Аудиозвонок';
            createNotification($pdo, [
                'user_id' => (int)$data['recipient_id'],
                'sender_id' => (int)$userId,
                'message' => $notifText,
                'type' => 'chat',
                'related_id' => (int)$callId,
            ]);

            echo json_encode([
                'success' => true,
                'data' => ['call_id' => $callId]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // PUT /api/chat/calls/:id - принять/отклонить звонок
    // ============================================
    if ($method === 'PUT' && $action === 'calls' && is_numeric($id)) {
        $callId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['status']) || !in_array($data['status'], ['accepted', 'declined'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверный статус']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE chat_calls 
                SET status = ?, ended_at = CASE WHEN ? = 'declined' THEN NOW() ELSE NULL END
                WHERE id = ? AND recipient_id = ?
            ");
            $stmt->execute([$data['status'], $data['status'], $callId, $userId]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // DELETE /api/chat/calls/:id - завершить звонок
    // ============================================
    if ($method === 'DELETE' && $action === 'calls' && is_numeric($id)) {
        $callId = (int)$id;

        try {
            $stmt = $pdo->prepare("
                UPDATE chat_calls 
                SET status = 'ended', ended_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
                WHERE id = ? AND (caller_id = ? OR recipient_id = ?)
            ");
            $stmt->execute([$callId, $userId, $userId]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // POST /api/chat/webrtc/session - сохранить SDP сессию
    // ============================================
    if ($method === 'POST' && $action === 'webrtc' && $subaction === 'session') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['call_id']) || empty($data['session_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO webrtc_sessions (call_id, session_type, sdp_data, candidate_data)
                VALUES (?, ?, ?, NULL)
            ");
            $stmt->execute([
                $data['call_id'],
                $data['session_type'],
                $data['sdp_data']
            ]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // POST /api/chat/webrtc/ice - отправить ICE кандидата
    // ============================================
    if ($method === 'POST' && $action === 'webrtc' && $subaction === 'ice') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['call_id']) || empty($data['candidate'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO webrtc_sessions (call_id, session_type, candidate_data)
                VALUES (?, 'ice-candidate', ?)
            ");
            $stmt->execute([
                $data['call_id'],
                json_encode($data['candidate'])
            ]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // GET /api/chat/webrtc/:call_id - получить SDP сессию
    // ============================================
    if ($method === 'GET' && $action === 'webrtc' && is_numeric($id)) {
        $callId = (int)$id;

        try {
            $stmt = $pdo->prepare("
                SELECT session_type, sdp_data, candidate_data
                FROM webrtc_sessions
                WHERE call_id = ? AND is_processed = 0
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$callId]);
            $session = $stmt->fetch();

            if ($session) {
                // Помечаем как обработанное
                $pdo->prepare("UPDATE webrtc_sessions SET is_processed = 1 WHERE call_id = ? AND session_type = ?")
                    ->execute([$callId, $session['session_type']]);
                
                $result = [
                    'type' => $session['session_type']
                ];
                
                if ($session['sdp_data']) {
                    $result['sdp'] = json_decode($session['sdp_data'], true);
                }
                
                if ($session['candidate_data']) {
                    $result['candidate'] = json_decode($session['candidate_data'], true);
                }
                
                echo json_encode(['success' => true, 'data' => $result]);
            } else {
                echo json_encode(['success' => true, 'data' => null]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
