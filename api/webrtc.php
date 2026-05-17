<?php
/**
 * api/webrtc.php - WebRTC Signaling API для видеоконференций
 *
 * Endpoints:
 * - POST   /api/webrtc/offer              - Создать SDP офер
 * - GET    /api/webrtc/offers/:confId     - Получить оферы для конференции
 * - PUT    /api/webrtc/offer/:id/answer   - Ответить на офер
 * - GET    /api/webrtc/answer/:offerId    - Получить ответ на офер
 * - POST   /api/webrtc/ice                - Добавить ICE кандидат
 * - GET    /api/webrtc/ice/:confId        - Получить ICE кандидаты
 * - DELETE /api/webrtc/ice                - Удалить ICE кандидаты (очистка)
 * - DELETE /api/webrtc/session/:confId    - Очистить сессию (все сигналы)
 */

function handleWebRTC(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    $userId = $currentUser['id'] ?? null;

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $readJsonBody = function(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    };

    // ============================================
    // POST /api/webrtc/offer - Создать SDP офер
    // ============================================
    if ($method === 'POST' && $action === 'offer') {
        $data = $readJsonBody();

        if (empty($data['conference_id']) || empty($data['from_participant_id']) || 
            empty($data['to_participant_id']) || empty($data['sdp'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
            exit;
        }

        $conferenceId = (int)$data['conference_id'];
        $fromParticipantId = (int)$data['from_participant_id'];
        $toParticipantId = (int)$data['to_participant_id'];
        $sdpOffer = $data['sdp'];

        // Проверка доступа к конференции
        $stmt = $pdo->prepare("
            SELECT c.id FROM conferences c
            JOIN conference_participants p ON p.conference_id = c.id
            WHERE c.id = ? AND (p.user_id = ? OR p.id = ?)
        ");
        $stmt->execute([$conferenceId, $userId, $fromParticipantId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO webrtc_offers (conference_id, from_participant_id, to_participant_id, sdp_offer)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conferenceId, $fromParticipantId, $toParticipantId, $sdpOffer]);

        $offerId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'data' => ['offer_id' => $offerId]
        ]);
        exit;
    }

    // ============================================
    // GET /api/webrtc/offers/:confId - Получить оферы
    // ============================================
    if ($method === 'GET' && $action === 'offers' && is_numeric($id)) {
        $conferenceId = (int)$id;
        $participantId = $_GET['participant_id'] ?? null;

        // Проверка доступа
        $stmt = $pdo->prepare("
            SELECT id FROM conference_participants 
            WHERE conference_id = ? AND user_id = ?
        ");
        $stmt->execute([$conferenceId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $sql = "
            SELECT o.*, 
                   p1.guest_name as from_guest_name, p1.full_name as from_full_name,
                   p2.guest_name as to_guest_name, p2.full_name as to_full_name
            FROM webrtc_offers o
            JOIN conference_participants p1 ON o.from_participant_id = p1.id
            JOIN conference_participants p2 ON o.to_participant_id = p2.id
            WHERE o.conference_id = ? AND o.status = 'pending'
        ";

        // Только оферы для этого участника
        if ($participantId) {
            $sql .= " AND o.to_participant_id = " . (int)$participantId;
        }

        $sql .= " ORDER BY o.created_at DESC LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$conferenceId]);
        $offers = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $offers]);
        exit;
    }

    // ============================================
    // PUT /api/webrtc/offer/:id/answer - Ответить на офер
    // ============================================
    if ($method === 'PUT' && $action === 'offer' && is_numeric($id) && $subaction === 'answer') {
        $offerId = (int)$id;
        $data = $readJsonBody();

        if (empty($data['sdp']) || empty($data['from_participant_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
            exit;
        }

        $fromParticipantId = (int)$data['from_participant_id'];
        $sdpAnswer = $data['sdp'];

        // Получаем офер
        $stmt = $pdo->prepare("SELECT conference_id, to_participant_id FROM webrtc_offers WHERE id = ?");
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch();

        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Офер не найден']);
            exit;
        }

        // Проверка доступа
        $stmt = $pdo->prepare("
            SELECT id FROM conference_participants 
            WHERE conference_id = ? AND user_id = ?
        ");
        $stmt->execute([$offer['conference_id'], $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        // Создаём ответ
        $stmt = $pdo->prepare("
            INSERT INTO webrtc_answers (conference_id, offer_id, from_participant_id, to_participant_id, sdp_answer)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $offer['conference_id'], 
            $offerId, 
            $fromParticipantId, 
            $offer['to_participant_id'], 
            $sdpAnswer
        ]);

        // Обновляем статус офера
        $stmt = $pdo->prepare("UPDATE webrtc_offers SET status = 'answered', answered_at = NOW() WHERE id = ?");
        $stmt->execute([$offerId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // GET /api/webrtc/answer/:offerId - Получить ответ
    // ============================================
    if ($method === 'GET' && $action === 'answer' && is_numeric($id)) {
        $offerId = (int)$id;

        $stmt = $pdo->prepare("
            SELECT a.*, 
                   p1.guest_name as from_guest_name, p1.full_name as from_full_name,
                   p2.guest_name as to_guest_name, p2.full_name as to_full_name
            FROM webrtc_answers a
            WHERE a.offer_id = ?
            ORDER BY a.created_at DESC LIMIT 1
        ");
        $stmt->execute([$offerId]);
        $answer = $stmt->fetch();

        echo json_encode(['success' => true, 'data' => $answer]);
        exit;
    }

    // ============================================
    // POST /api/webrtc/ice - Добавить ICE кандидат
    // ============================================
    if ($method === 'POST' && $action === 'ice') {
        $data = $readJsonBody();

        if (empty($data['conference_id']) || empty($data['from_participant_id']) || 
            empty($data['candidate'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
            exit;
        }

        $conferenceId = (int)$data['conference_id'];
        $fromParticipantId = (int)$data['from_participant_id'];
        $toParticipantId = $data['to_participant_id'] ? (int)$data['to_participant_id'] : null;
        $candidate = $data['candidate'];
        $sdpMid = $data['sdp_mid'] ?? null;
        $sdpMLineIndex = $data['sdp_mline_index'] ?? null;

        // Проверка доступа
        $stmt = $pdo->prepare("
            SELECT id FROM conference_participants 
            WHERE conference_id = ? AND user_id = ?
        ");
        $stmt->execute([$conferenceId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO webrtc_ice_candidates 
            (conference_id, from_participant_id, to_participant_id, candidate, sdp_mid, sdp_mline_index)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $conferenceId, 
            $fromParticipantId, 
            $toParticipantId, 
            $candidate,
            $sdpMid,
            $sdpMLineIndex
        ]);

        $iceId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'data' => ['ice_id' => $iceId]
        ]);
        exit;
    }

    // ============================================
    // GET /api/webrtc/ice/:confId - Получить ICE кандидаты
    // ============================================
    if ($method === 'GET' && $action === 'ice' && is_numeric($id)) {
        $conferenceId = (int)$id;
        $participantId = $_GET['participant_id'] ?? null;
        $sinceId = $_GET['since_id'] ?? 0;

        // Проверка доступа
        $stmt = $pdo->prepare("
            SELECT id FROM conference_participants 
            WHERE conference_id = ? AND user_id = ?
        ");
        $stmt->execute([$conferenceId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $sql = "
            SELECT ice.*, 
                   p1.guest_name as from_guest_name, p1.full_name as from_full_name,
                   p2.guest_name as to_guest_name, p2.full_name as to_full_name
            FROM webrtc_ice_candidates ice
            JOIN conference_participants p1 ON ice.from_participant_id = p1.id
            LEFT JOIN conference_participants p2 ON ice.to_participant_id = p2.id
            WHERE ice.conference_id = ? AND ice.id > ?
        ";

        // Только кандидаты для этого участника
        if ($participantId) {
            $sql .= " AND (ice.to_participant_id = " . (int)$participantId . " OR ice.to_participant_id IS NULL)";
        }

        $sql .= " ORDER BY ice.id ASC LIMIT 500";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$conferenceId, $sinceId]);
        $candidates = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $candidates]);
        exit;
    }

    // ============================================
    // DELETE /api/webrtc/ice - Удалить ICE кандидаты
    // ============================================
    if ($method === 'DELETE' && $action === 'ice') {
        $data = $readJsonBody();
        $conferenceId = $data['conference_id'] ?? null;
        $participantId = $data['participant_id'] ?? null;

        if (!$conferenceId || !$participantId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
            exit;
        }

        // Проверка доступа (только хост может очищать)
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf || $conf['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может очищать сессию']);
            exit;
        }

        if ($participantId === 'all') {
            $stmt = $pdo->prepare("DELETE FROM webrtc_ice_candidates WHERE conference_id = ?");
            $stmt->execute([$conferenceId]);
        } else {
            $stmt = $pdo->prepare("
                DELETE FROM webrtc_ice_candidates 
                WHERE conference_id = ? AND from_participant_id = ?
            ");
            $stmt->execute([$conferenceId, $participantId]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ============================================
    // DELETE /api/webrtc/session/:confId - Очистить сессию
    // ============================================
    if ($method === 'DELETE' && $action === 'session' && is_numeric($id)) {
        $conferenceId = (int)$id;

        // Проверка доступа (только хост)
        $stmt = $pdo->prepare("SELECT host_id FROM conferences WHERE id = ?");
        $stmt->execute([$conferenceId]);
        $conf = $stmt->fetch();

        if (!$conf || $conf['host_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только хост может очищать сессию']);
            exit;
        }

        // Очищаем все сигналы
        $pdo->prepare("DELETE FROM webrtc_answers WHERE conference_id = ?")->execute([$conferenceId]);
        $pdo->prepare("DELETE FROM webrtc_offers WHERE conference_id = ?")->execute([$conferenceId]);
        $pdo->prepare("DELETE FROM webrtc_ice_candidates WHERE conference_id = ?")->execute([$conferenceId]);

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
