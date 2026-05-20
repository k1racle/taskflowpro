<?php
/**
 * api/omnichannel.php - Shared helpers for external messenger integrations (Telegram/MAX) with HelpDesk.
 */

function omniLoadSetting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE BINARY `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function omniLoadSecretSetting(PDO $pdo, string $key): string {
    $stored = trim((string)(omniLoadSetting($pdo, $key) ?? ''));
    if ($stored === '') return '';
    try {
        return trim((string)(appDecrypt($stored) ?? ''));
    } catch (Throwable $e) {
        return $stored;
    }
}

function omniFindHelpdeskThread(PDO $pdo, int $ticketId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM helpdesk_external_threads WHERE ticket_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function omniHttpPostJson(string $url, array $headers, array $payload, int $timeoutSeconds = 12): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to encode JSON'];
    }

    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ]
    ];

    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);

    $headersList = null;
    if (function_exists('http_get_last_response_headers')) {
        try {
            $headersList = http_get_last_response_headers();
        } catch (Throwable $e) {
            $headersList = null;
        }
    }

    // NOTE: $http_response_header is deprecated on newer PHP versions.
    // If http_get_last_response_headers() is not available, we keep status=0 (best-effort).

    $status = 0;
    if (is_array($headersList)) {
        foreach ($headersList as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    $data = null;
    if (is_string($resp) && $resp !== '') {
        $decoded = json_decode($resp, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $ok = $status >= 200 && $status < 300;
    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'raw' => is_string($resp) ? $resp : ''];
}

function omniSendTelegramMessage(PDO $pdo, string $botToken, string $chatId, string $text): array {
    $token = trim($botToken);
    if ($token === '') return ['ok' => false, 'status' => 0, 'error' => 'Telegram token empty'];
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    return omniHttpPostJson($url, [
        'Content-Type' => 'application/json'
    ], [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ], 12);
}

function omniSendMaxMessage(PDO $pdo, string $botToken, string $chatId, string $text): array {
    $token = trim($botToken);
    if ($token === '') return ['ok' => false, 'status' => 0, 'error' => 'MAX token empty'];

    // MAX API: POST https://platform-api.max.ru/messages?chat_id={chat_id}
    $url = 'https://platform-api.max.ru/messages?chat_id=' . urlencode($chatId);
    return omniHttpPostJson($url, [
        'Authorization' => $token,
        'Content-Type' => 'application/json'
    ], [
        'text' => $text
    ], 12);
}
