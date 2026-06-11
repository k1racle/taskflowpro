<?php
/**
 * api/license.php - License management with tier-based feature gates.
 *
 * Logic:
 * - Domain-based validation (existing).
 * - Tier-based feature gates: free, pro, enterprise.
 * - Limits: max_users, booking module, white-label, etc.
 */

function normalizeHostname(string $host): string {
    $host = trim(strtolower($host));
    $host = preg_replace('/:\d+$/', '', $host);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    return $host;
}

function getRequestHostname(): string {
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    if ($host && str_contains($host, ',')) {
        $host = trim(explode(',', $host)[0]);
    }
    return normalizeHostname($host);
}

function isLicenseValid(): bool {
    $licensed = normalizeHostname((string)(LICENSE_DOMAIN ?? ''));
    if ($licensed === '') return true;

    $requestHost = getRequestHostname();
    if ($requestHost === '') return false;

    if ($requestHost === $licensed) return true;
    return str_ends_with($requestHost, '.' . $licensed);
}

function requireValidLicense(): void {
    $valid = isLicenseValid();
    if (!$valid) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Лицензия недействительна для этого домена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ── Tier / Feature Gates ──────────────────────────────────── */

function getLicenseTiers(): array {
    return [
        'free' => [
            'name' => 'Free',
            'max_users' => 2,
            'booking_enabled' => false,
            'white_label' => false,
            'telegram_bot' => false,
            'analytics' => false,
            'support_priority' => 'low',
        ],
        'pro' => [
            'name' => 'Pro',
            'max_users' => 10,
            'booking_enabled' => true,
            'white_label' => true,
            'telegram_bot' => true,
            'analytics' => true,
            'support_priority' => 'normal',
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'max_users' => null, // unlimited
            'booking_enabled' => true,
            'white_label' => true,
            'telegram_bot' => true,
            'analytics' => true,
            'support_priority' => 'high',
        ],
    ];
}

function getLicenseTier(PDO $pdo): array {
    $tierCode = 'free';
    $expiresAt = null;
    try {
        $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE `key` IN ('license_tier', 'license_expires_at')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key'] === 'license_tier') {
                $tierCode = trim((string)$row['value']) ?: 'free';
            }
            if ($row['key'] === 'license_expires_at') {
                $expiresAt = trim((string)$row['value']) ?: null;
            }
        }
    } catch (Throwable $e) {}

    $tiers = getLicenseTiers();
    $tier = $tiers[$tierCode] ?? $tiers['free'];
    $tier['code'] = $tierCode;
    $tier['expires_at'] = $expiresAt;
    $tier['expired'] = false;
    if ($expiresAt) {
        try {
            $tier['expired'] = (new DateTimeImmutable($expiresAt)) < new DateTimeImmutable('now');
        } catch (Throwable $e) {}
    }
    return $tier;
}

function isFeatureEnabled(PDO $pdo, string $feature): bool {
    $tier = getLicenseTier($pdo);
    if ($tier['expired']) {
        return false;
    }
    $key = $feature;
    if ($feature === 'booking') $key = 'booking_enabled';
    if ($feature === 'white_label') $key = 'white_label';
    if ($feature === 'telegram_bot') $key = 'telegram_bot';
    if ($feature === 'analytics') $key = 'analytics';
    return !empty($tier[$key]);
}

function checkLicenseLimit(PDO $pdo, string $limit, int $currentValue): bool {
    $tier = getLicenseTier($pdo);
    if ($tier['expired']) {
        return false;
    }
    if ($limit === 'max_users') {
        $max = $tier['max_users'] ?? null;
        if ($max === null) return true; // unlimited
        return $currentValue < $max;
    }
    return true;
}

function getLicenseTierMaxUsers(PDO $pdo): ?int {
    $tier = getLicenseTier($pdo);
    return $tier['expired'] ? 0 : ($tier['max_users'] ?? null);
}

/* ── API Handlers ──────────────────────────────────────────── */

function handleLicense(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();

    if ($method === 'GET' && $action === 'status') {
        $licensed = normalizeHostname((string)(LICENSE_DOMAIN ?? ''));
        $requestHost = getRequestHostname();
        $valid = isLicenseValid();
        $tier = getLicenseTier($pdo);

        echo json_encode([
            'success' => true,
            'data' => [
                'enabled' => ($licensed !== ''),
                'licensed_domain' => $licensed ?: null,
                'request_domain' => $requestHost ?: null,
                'valid' => $valid,
                'tier' => [
                    'code' => $tier['code'],
                    'name' => $tier['name'],
                    'expired' => $tier['expired'],
                    'expires_at' => $tier['expires_at'],
                    'max_users' => $tier['max_users'],
                    'booking_enabled' => $tier['booking_enabled'],
                    'white_label' => $tier['white_label'],
                    'telegram_bot' => $tier['telegram_bot'],
                    'analytics' => $tier['analytics'],
                    'support_priority' => $tier['support_priority'],
                ],
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'tier') {
        $tier = getLicenseTier($pdo);
        echo json_encode([
            'success' => true,
            'data' => $tier
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'request') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $company = trim((string)($data['company'] ?? ''));
        $tierRequested = trim((string)($data['tier_requested'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));

        if ($name === '' || $email === '' || $company === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите имя, email и компанию'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO license_requests (name, email, company, tier_requested, message, status, created_at) VALUES (?, ?, ?, ?, ?, 'new', NOW())");
            $stmt->execute([$name, $email, $company, $tierRequested, $message]);
        } catch (Throwable $e) {
            error_log('license request insert failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения заявки'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Notify admins via email/telegram if configured
        try {
            $companyName = 'TaskFlow Pro';
            $s = $pdo->query("SELECT value FROM settings WHERE `key` = 'company_name' LIMIT 1");
            $companyName = $s->fetchColumn() ?: $companyName;
            $subject = 'Новая заявка на коммерческое предложение — ' . $companyName;
            $body = "Имя: {$name}\nEmail: {$email}\nКомпания: {$company}\nТариф: {$tierRequested}\nСообщение: {$message}";
            require_once __DIR__ . '/notification-service.php';
            $templates = notificationServiceGetTemplates($pdo, 'license.request', 'email');
            if ($templates) {
                foreach ($templates as $t) {
                    $ctx = ['name' => $name, 'email' => $email, 'company' => $company, 'tier_requested' => $tierRequested, 'message' => $message];
                    $to = $t['recipient_email'] ?? null;
                    if ($to) {
                        notificationServiceSendEmail($pdo, $t, $ctx, $to, $t['subject'] ?? $subject);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('license request notification failed: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Заявка отправлена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден'], JSON_UNESCAPED_UNICODE);
}
