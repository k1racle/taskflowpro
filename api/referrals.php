<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crm.php';

function handleReferrals(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    crmEnsureReferralSchema($pdo);

    $action = $action ?? '';
    $subaction = $subaction ?? '';

    if ($method === 'POST' && $action === 'webhook' && $id === 'woocommerce') {
        crmRequireReferralSecret();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректное тело запроса']);
            exit;
        }

        $result = crmSyncWooCommerceOrder($pdo, $data);
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'visit') {
        crmRequireReferralSecret();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректное тело запроса']);
            exit;
        }

        $result = crmCreateReferralVisit($pdo, $data);
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден'], JSON_UNESCAPED_UNICODE);
}
