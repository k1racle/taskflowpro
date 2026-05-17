<?php
/**
 * api/conferences.php - Видеоконференции (Zoom-like)
 * 
 * Endpoints:
 * - GET    /api/conferences          - Список конференций пользователя
 * - POST   /api/conferences          - Создать конференцию
 * - GET    /api/conferences/:id      - Информация о конференции
 * - PUT    /api/conferences/:id      - Обновить конференцию
 * - DELETE /api/conferences/:id      - Удалить конференцию
 * - POST   /api/conferences/:id/start - Начать конференцию
 * - POST   /api/conferences/:id/end   - Завершить конференцию
 * - GET    /api/conferences/room/:room_id - Получить конференцию по room_id
 * - POST   /api/conferences/:id/join-request - Запрос на присоединение
 * - GET    /api/conferences/:id/join-requests - Получить запросы (для хоста)
 * - PUT    /api/conferences/:id/join-requests/:request_id - Одобрить/отклонить запрос
 * - GET    /api/conferences/:id/participants - Участники конференции
 * - POST   /api/conferences/:id/participants - Добавить участника
 * - PUT    /api/conferences/:id/participants/:participant_id - Обновить участника
 * - DELETE /api/conferences/:id/participants/:participant_id - Удалить участника
 * - GET    /api/conferences/:id/chat - Сообщения чата конференции
 * - POST   /api/conferences/:id/chat - Отправить сообщение в чат
 */

function handleConferences(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser && $method !== 'POST') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $userId = $currentUser['id'] ?? null;

    $readJsonBody = function(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    };

    $generatePin = function(int $length = 6): string {
        $length = max(4, min(10, $length));
        $min = (int)pow(10, $length - 1);
        $max = (int)pow(10, $length) - 1;
        return (string)random_int($min, $max);
    };

    $pinKey = function(): string {
        return hash('sha256', 'taskflow:conference-pin:' . (JWT_SECRET ?: 'fallback'), true);
    };

    $encryptPin = function(string $pin) use ($pinKey): string {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = random_bytes($ivLength);
        $cipher = openssl_encrypt($pin, 'aes-256-cbc', $pinKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Не удалось зашифровать PIN');
        }
        return base64_encode($iv . $cipher);
    };

    $decryptPin = function(?string $enc) use ($pinKey): ?string {
        if (!$enc) return null;
        $raw = base64_decode($enc, true);
        if ($raw === false) return null;
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($raw) <= $ivLength) return null;
        $iv = substr($raw, 0, $ivLength);
        $cipher = substr($raw, $ivLength);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $pinKey(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? null : (string)$plain;
    };

    // ============================================
    // GET /api/conferences - Список конференций
    // ============================================
    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.full_name as host_name,
                (SELECT COUNT(*) FROM conference_participants WHERE conference_id = c.id) as participants_count,
                (SELECT COUNT(*) FROM conference_join_requests WHERE conference_id = c.id AND status = 'pending') as pending_requests
            FROM conferences c
            JOIN users u ON c.host_id = u.id
            WHERE c.host_id = ?
               OR c.id IN (SELECT conference_id FROM conference_participants WHERE user_id = ?)
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        $conferences = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $conferences]);
        exit;
    }

    // ============================================
    // POST /api/conferences - Создать конференцию
    // ============================================
    if ($method === 'POST' && $action === null) {
        $data = $readJsonBody();

        if (empty($data['title'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название конференции']);
            exit;
        }

        $roomId = generateUUID();
        $settings = $data['settings'] ?? null;

        $guestPin = $generatePin();
        $guestPinHash = password_hash($guestPin, PASSWORD_DEFAULT);
        $guestPinEnc = $encryptPin($guestPin);

        $stmt = $pdo->prepare("
            INSERT INTO conferences (title, description, room_id, host_id, guest_pin_hash, guest_pin_enc, settings)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['title'],
            $data['description'] ?? null,
            $roomId,
            $userId,
            $guestPinHash,
            $guestPinEnc,
            $settings ? json_encode($settings) : null
        ]);

        $conferenceId = $pdo->lastInsertId();

        // Добавляем создателя как хоста
        $stmt = $pdo->prepare("
            INSERT INTO conference_participants (conference_id, user_id, role, status, joined_at)
            VALUES (?, ?, 'host', 'joined', NOW())
        ");
        $stmt->execute([$conferenceId, $userId]);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $conferenceId,
                'room_id' => $roomId,
                'join_url' => getBaseUrl() . '/conference-join.html?room=' . $roomId,
                'guest_pin' => $guestPin
            ]
        ]);
        exit;
    }

    // ============================================
    // GET /api/conferences/:id/guest-pin - Показать PIN (только хост)
    // ============================================
    if ($method === 'GET' && $action !== null && is_numeric($id) && $subaction === 'guest-pin') {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("SELECT id, host_id, guest_pin_enc FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        if ((int)$conf['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может смотреть PIN']);
            exit;
        }

        $pin = $decryptPin($conf['guest_pin_enc']);
        if (!$pin) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'PIN недоступен']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => ['guest_pin' => $pin]]);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/guest-pin/rotate - Сменить PIN (только хост)
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'guest-pin' && ($_GET['action'] ?? '') === 'rotate') {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("SELECT id, host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        if ((int)$conf['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может менять PIN']);
            exit;
        }

        $newPin = $generatePin();
        $newHash = password_hash($newPin, PASSWORD_DEFAULT);
        $newEnc = $encryptPin($newPin);

        $stmt = $pdo->prepare("UPDATE conferences SET guest_pin_hash = ?, guest_pin_enc = ? WHERE id = ?");
        $stmt->execute([$newHash, $newEnc, $conferenceId]);

        echo json_encode(['success' => true, 'data' => ['guest_pin' => $newPin]]);
        exit;
    }

    // ============================================
    // GET /api/conferences/room/:room_id - Получить по room_id
    // ============================================
    if ($method === 'GET' && $action === 'room' && !empty($id)) {
        $roomId = $id;

        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.full_name as host_name,
                u.avatar as host_avatar
            FROM conferences c
            JOIN users u ON c.host_id = u.id
            WHERE c.room_id = ?
        ");
        $stmt->execute([$roomId]);
        $conference = $stmt->fetch();

        if (!$conference) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $conference]);
        exit;
    }

    // ============================================
    // GET /api/conferences/:id - Информация о конференции
    // ============================================
    if ($method === 'GET' && $action !== null && is_numeric($id)) {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.full_name as host_name
            FROM conferences c
            JOIN users u ON c.host_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        // Проверка доступа
        $canAccess = ($conference['host_id'] == $userId) || 
                     $pdo->prepare("SELECT id FROM conference_participants WHERE conference_id = ? AND user_id = ? AND status = 'joined'")
                       ->execute([$conferenceId, $userId]) &&
                       $pdo->fetch();

        if (!$canAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $conference]);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/start - Начать конференцию
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'start') {
        $conferenceId = (int)$id;

        // Проверка что пользователь хост
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || $conference['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только создатель может начать конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE conferences 
            SET status = 'active', started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$conferenceId]);

        echo json_encode(['success' => true, 'message' => 'Конференция началась']);
        exit;
    }

    // ============================================
    // POST /api/conferences/start/:id - fallback роут (на случай нестабильного роутинга)
    // ============================================
    if ($method === 'POST' && $action === 'start' && is_numeric($id)) {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || (int)$conference['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только создатель может начать конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE conferences SET status = 'active', started_at = NOW() WHERE id = ?");
        $stmt->execute([$conferenceId]);

        echo json_encode(['success' => true, 'message' => 'Конференция началась']);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/end - Завершить конференцию
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'end') {
        $conferenceId = (int)$id;

        // Только хост может завершить
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();
        if (!$conf || (int)$conf['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только создатель может завершить конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE conferences 
            SET status = 'ended', ended_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$conferenceId]);

        // Удаляем всех участников
        $stmt = $pdo->prepare("DELETE FROM conference_participants WHERE conference_id = ? AND user_id != ?");
        $stmt->execute([$conferenceId, $userId]);

        echo json_encode(['success' => true, 'message' => 'Конференция завершена']);
        exit;
    }

    // ============================================
    // POST /api/conferences/end/:id - fallback роут
    // ============================================
    if ($method === 'POST' && $action === 'end' && is_numeric($id)) {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();
        if (!$conf || (int)$conf['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только создатель может завершить конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE conferences SET status = 'ended', ended_at = NOW() WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $stmt = $pdo->prepare("DELETE FROM conference_participants WHERE conference_id = ? AND user_id != ?");
        $stmt->execute([$conferenceId, $userId]);

        echo json_encode(['success' => true, 'message' => 'Конференция завершена']);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/join-request - Запрос на присоединение
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'join-request') {
        $conferenceId = (int)$id;
        $data = $readJsonBody();

        $guestPin = trim((string)($data['guest_pin'] ?? ''));

        $stmt = $pdo->prepare("SELECT id, guest_pin_hash FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        if (empty($guestPin) || empty($conf['guest_pin_hash']) || !password_verify($guestPin, $conf['guest_pin_hash'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Неверный PIN']);
            exit;
        }

        $guestName = $data['guest_name'] ?? ($currentUser['full_name'] ?? 'Гость');
        $guestEmail = $data['guest_email'] ?? null;
        $guestId = $currentUser['id'] ?? null;

        // Создаём участника со статусом waiting
        $stmt = $pdo->prepare("
            INSERT INTO conference_participants (conference_id, user_id, guest_name, guest_email, status)
            VALUES (?, ?, ?, ?, 'waiting')
        ");
        $stmt->execute([$conferenceId, $guestId, $guestName, $guestEmail]);
        $participantId = $pdo->lastInsertId();

        // Создаём запрос на присоединение
        $stmt = $pdo->prepare("
            INSERT INTO conference_join_requests (conference_id, participant_id, guest_name, guest_email)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conferenceId, $participantId, $guestName, $guestEmail]);

        // Уведомляем хоста
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        $notifText = $guestId ? 
            "Пользователь хочет присоединиться к конференции" :
            "Гость ($guestName) хочет присоединиться к конференции";

        createNotification($pdo, [
            'user_id' => (int)$conference['host_id'],
            'sender_id' => $guestId ? (int)$guestId : null,
            'message' => $notifText,
            'type' => 'conference',
            'related_id' => $conferenceId,
            'allow_self' => true,
        ]);

        echo json_encode([
            'success' => true,
            'data' => [
                'participant_id' => $participantId,
                'status' => 'waiting'
            ]
        ]);
        exit;
    }

    // ============================================
    // GET /api/conferences/:id/join-requests - Получить запросы
    // ============================================
    if ($method === 'GET' && $action !== null && is_numeric($id) && $subaction === 'join-requests') {
        $conferenceId = (int)$id;

        // Проверка что пользователь хост
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || $conference['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может просматривать запросы']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT r.*, p.user_id, p.guest_name, p.guest_email
            FROM conference_join_requests r
            JOIN conference_participants p ON r.participant_id = p.id
            WHERE r.conference_id = ? AND r.status = 'pending'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$conferenceId]);
        $requests = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $requests]);
        exit;
    }

    // ============================================
    // PUT /api/conferences/:id/join-requests/:request_id - Одобрить/отклонить
    // ============================================
    if ($method === 'PUT' && $subaction === 'join-requests' && is_numeric($id) && !empty($_GET['request_id'])) {
        
        $conferenceId = (int)$id;
        $requestId = (int)$_GET['request_id'];
        $data = json_decode(file_get_contents('php://input'), true);

        $status = $data['status'] ?? 'approved'; // approved или rejected

        // Проверка что пользователь хост
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || $conference['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может одобрять запросы']);
            exit;
        }

        // Обновляем запрос
        $stmt = $pdo->prepare("
            UPDATE conference_join_requests 
            SET status = ?, reviewed_at = NOW(), reviewed_by = ?
            WHERE id = ? AND conference_id = ?
        ");
        $stmt->execute([$status, $userId, $requestId, $conferenceId]);

        // Получаем participant_id
        $stmt = $pdo->prepare("SELECT participant_id FROM conference_join_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if ($status === 'approved') {
            // Обновляем участника
            $stmt = $pdo->prepare("
                UPDATE conference_participants 
                SET status = 'joined', joined_at = NOW()
                WHERE id = ? AND conference_id = ?
            ");
            $stmt->execute([$request['participant_id'], $conferenceId]);

            echo json_encode(['success' => true, 'message' => 'Запрос одобрен']);
        } else {
            // Удаляем участника
            $stmt = $pdo->prepare("DELETE FROM conference_participants WHERE id = ? AND conference_id = ?");
            $stmt->execute([$request['participant_id'], $conferenceId]);

            echo json_encode(['success' => true, 'message' => 'Запрос отклонён']);
        }

        exit;
    }

    // ============================================
    // GET /api/conferences/:id/participants - Участники
    // ============================================
    if ($method === 'GET' && $action !== null && is_numeric($id) && $subaction === 'participants') {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                u.full_name,
                u.avatar
            FROM conference_participants p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.conference_id = ?
            ORDER BY p.role DESC, p.joined_at ASC
        ");
        $stmt->execute([$conferenceId]);
        $participants = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $participants]);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/participants - Пригласить/добавить сотрудника
    // (PIN не нужен, только авторизованные)
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'participants') {
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }

        $conferenceId = (int)$id;
        $data = $readJsonBody();
        $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $role = $data['role'] ?? 'participant';

        if ($targetUserId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'user_id обязателен']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Конференция не найдена']);
            exit;
        }

        if ((int)$conf['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может приглашать участников']);
            exit;
        }

        $roleAllowed = in_array($role, ['participant', 'co-host', 'host'], true) ? $role : 'participant';
        if ($roleAllowed === 'host') $roleAllowed = 'participant';

        $stmt = $pdo->prepare("SELECT id FROM conference_participants WHERE conference_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$conferenceId, $targetUserId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE conference_participants SET role = ?, status = IF(status='rejected','waiting',status) WHERE id = ?");
            $stmt->execute([$roleAllowed, $existing['id']]);
            echo json_encode(['success' => true, 'data' => ['participant_id' => (int)$existing['id'], 'updated' => true]]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO conference_participants (conference_id, user_id, role, status)
            VALUES (?, ?, ?, 'waiting')
        ");
        $stmt->execute([$conferenceId, $targetUserId, $roleAllowed]);
        $participantId = (int)$pdo->lastInsertId();

        createNotification($pdo, [
            'user_id' => $targetUserId,
            'sender_id' => $userId,
            'message' => 'Вас пригласили в конференцию',
            'type' => 'conference',
            'related_id' => $conferenceId,
        ]);

        echo json_encode(['success' => true, 'data' => ['participant_id' => $participantId]]);
        exit;
    }

    // ============================================
    // GET /api/conferences/:id/chat - Чат конференции
    // ============================================
    if ($method === 'GET' && $action !== null && is_numeric($id) && $subaction === 'chat') {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT * FROM conference_chat
            WHERE conference_id = ?
            ORDER BY created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$conferenceId]);
        $messages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $messages]);
        exit;
    }

    // ============================================
    // POST /api/conferences/:id/chat - Отправить сообщение
    // ============================================
    if ($method === 'POST' && $action !== null && is_numeric($id) && $subaction === 'chat') {
        $conferenceId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
            exit;
        }

        $senderName = $currentUser['full_name'] ?? ($data['guest_name'] ?? 'Гость');

        $stmt = $pdo->prepare("
            INSERT INTO conference_chat (conference_id, sender_id, sender_name, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conferenceId, $userId, $senderName, $data['message']]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // DELETE /api/conferences/:id - Удалить конференцию
    // ============================================
    // Router maps /conferences/:id as { action=null, id=:id }
    if ($method === 'DELETE' && $action === null && $id !== null && is_numeric($id)) {
        $conferenceId = (int)$id;

        // Проверка что пользователь хост
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || $conference['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только создатель может удалить конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);

        echo json_encode(['success' => true, 'message' => 'Конференция удалена']);
        exit;
    }

    // ============================================
    // DELETE /api/conferences/delete/:id - fallback роут
    // ============================================
    if ($method === 'DELETE' && $action === 'delete' && $id !== null && is_numeric($id)) {
        $conferenceId = (int)$id;

        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conference = $stmt->fetch();

        if (!$conference || (int)$conference['host_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может удалить конференцию']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);

        echo json_encode(['success' => true, 'message' => 'Конференция удалена']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

// Вспомогательная функция для генерации UUID
function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// Вспомогательная функция для получения базового URL
function getBaseUrl(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);
    
    // Убираем /api из пути
    $path = str_replace('/api', '', $path);
    
    return rtrim($protocol . '://' . $host . $path, '/');
}
