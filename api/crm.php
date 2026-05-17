<?php
/**
 * api/crm.php - CRM (клиенты/сделки/воронки/дашборд)
 */

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/../tools/crm_admin_tools.php';

function crmEnsureReferralSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (!appTableExists($pdo, 'crm_clients')) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM crm_clients') as $column) {
        $columns[] = (string)($column['Field'] ?? '');
    }

    if (!in_array('referral_code', $columns, true)) {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN referral_code VARCHAR(32) NULL AFTER notes");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_referral_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        referral_code VARCHAR(32) NOT NULL,
        external_source VARCHAR(32) NOT NULL DEFAULT 'woocommerce',
        external_order_id VARCHAR(64) NOT NULL,
        order_number VARCHAR(64) NULL,
        order_status VARCHAR(64) NULL,
        currency VARCHAR(16) NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        customer_email VARCHAR(255) NULL,
        customer_phone VARCHAR(80) NULL,
        order_created_at DATETIME NULL,
        attributed_at DATETIME NULL,
        payload_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_external_order (external_source, external_order_id),
        KEY idx_client_created (client_id, created_at),
        KEY idx_referral_code (referral_code),
        KEY idx_order_created_at (order_created_at),
        CONSTRAINT fk_crm_referral_orders_client FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_referral_visits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        referral_code VARCHAR(32) NOT NULL,
        external_source VARCHAR(32) NOT NULL DEFAULT 'woocommerce',
        landing_url TEXT NULL,
        referrer_url TEXT NULL,
        visitor_ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        visit_token VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_client_created (client_id, created_at),
        KEY idx_referral_code (referral_code),
        KEY idx_visit_token (visit_token),
        CONSTRAINT fk_crm_referral_visits_client FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_store_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        external_source VARCHAR(32) NOT NULL DEFAULT 'woocommerce',
        external_order_id VARCHAR(64) NOT NULL,
        referral_client_id INT NULL,
        referral_code VARCHAR(32) NULL,
        order_number VARCHAR(64) NULL,
        order_status VARCHAR(64) NULL,
        currency VARCHAR(16) NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        shipping_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_items_qty INT NOT NULL DEFAULT 0,
        customer_email VARCHAR(255) NULL,
        customer_phone VARCHAR(80) NULL,
        customer_name VARCHAR(255) NULL,
        order_created_at DATETIME NULL,
        synced_at DATETIME NULL,
        payload_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_store_external_order (external_source, external_order_id),
        KEY idx_store_created_at (order_created_at),
        KEY idx_store_status (order_status),
        KEY idx_store_referral_code (referral_code),
        KEY idx_store_referral_client (referral_client_id),
        CONSTRAINT fk_crm_store_orders_referral_client FOREIGN KEY (referral_client_id) REFERENCES crm_clients(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_store_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_order_id INT NOT NULL,
        external_item_id VARCHAR(64) NULL,
        product_id VARCHAR(64) NULL,
        variation_id VARCHAR(64) NULL,
        sku VARCHAR(128) NULL,
        item_name VARCHAR(255) NOT NULL,
        quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        currency VARCHAR(16) NULL,
        meta_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_store_order_items_order (store_order_id),
        KEY idx_store_order_items_product (product_id),
        CONSTRAINT fk_crm_store_order_items_order FOREIGN KEY (store_order_id) REFERENCES crm_store_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function crmGetReferralSecretFromRequest(): string {
    return trim((string)(
        $_SERVER['HTTP_X_REFERRAL_SECRET']
        ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
        ?? $_SERVER['HTTP_X_CRM_REFERRAL_SECRET']
        ?? ''
    ));
}

function crmGetReferralSharedSecret(): string {
    $settingsValue = trim((string)(crmLoadSystemSetting('referral_shared_secret') ?? ''));
    if ($settingsValue !== '') {
        try {
            $decryptedValue = trim((string)(appDecrypt($settingsValue) ?? ''));
            if ($decryptedValue !== '') {
                return $decryptedValue;
            }
        } catch (Throwable $e) {
            return $settingsValue;
        }
    }

    if (defined('REFERRAL_SHARED_SECRET')) {
        $legacyValue = trim((string)REFERRAL_SHARED_SECRET);
        if ($legacyValue !== '') {
            return $legacyValue;
        }
    }

    return trim((string)(getenv('REFERRAL_SHARED_SECRET') ?: ''));
}

function crmRequireReferralSecret(): void {
    $expectedSecret = crmGetReferralSharedSecret();

    if ($expectedSecret === '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Referral shared secret не настроен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $provided = crmGetReferralSecretFromRequest();
    if ($provided === '' || !hash_equals($expectedSecret, $provided)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Некорректный referral secret'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function crmGenerateReferralCode(): string {
    return strtoupper(bin2hex(random_bytes(4)));
}

function crmLoadSystemSetting(string $key): ?string {
    static $cache = [];

    $key = trim($key);
    if ($key === '') {
        return null;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = getPDO()->prepare('SELECT value FROM settings WHERE BINARY `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value === false ? null : (string)$value;
    } catch (Throwable $e) {
        $cache[$key] = null;
    }

    return $cache[$key];
}

function crmGetReferralWooCommerceBaseUrl(): string {
    $settingsValue = rtrim(trim((string)(crmLoadSystemSetting('referral_woocommerce_base_url') ?? '')), '/');
    if ($settingsValue !== '') {
        return $settingsValue;
    }

    if (defined('REFERRAL_WOOCOMMERCE_BASE_URL')) {
        return rtrim(trim((string)REFERRAL_WOOCOMMERCE_BASE_URL), '/');
    }

    return rtrim(trim((string)(getenv('REFERRAL_WOOCOMMERCE_BASE_URL') ?: '')), '/');
}

function crmGetReferralQueryParam(): string {
    if (defined('REFERRAL_QUERY_PARAM')) {
        $queryParam = trim((string)REFERRAL_QUERY_PARAM);
        return $queryParam !== '' ? $queryParam : 'ref';
    }

    $queryParam = trim((string)(getenv('REFERRAL_QUERY_PARAM') ?: 'ref'));
    return $queryParam !== '' ? $queryParam : 'ref';
}

function crmGetReferralLinkMessage(?string $code, ?string $link): ?string {
    $code = trim((string)$code);
    if ($code === '' || $link !== null) {
        return null;
    }

    return 'Referral code сгенерирован, но ссылка недоступна: не настроен URL WooCommerce-магазина в настройках или REFERRAL_WOOCOMMERCE_BASE_URL';
}

function crmBuildReferralLink(?string $code): ?string {
    $code = trim((string)$code);
    $baseUrl = crmGetReferralWooCommerceBaseUrl();
    if ($code === '' || $baseUrl === '') {
        return null;
    }

    $separator = strpos($baseUrl, '?') === false ? '?' : '&';
    return $baseUrl . $separator . rawurlencode(crmGetReferralQueryParam()) . '=' . rawurlencode($code);
}

function crmEnsureClientReferralCode(PDO $pdo, int $clientId, bool $forceRegenerate = false): string {
    $stmt = $pdo->prepare('SELECT referral_code FROM crm_clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $current = trim((string)$stmt->fetchColumn());
    if (!$forceRegenerate && $current !== '') {
        return $current;
    }

    do {
        $code = crmGenerateReferralCode();
        $checkStmt = $pdo->prepare('SELECT id FROM crm_clients WHERE referral_code = ? LIMIT 1');
        $checkStmt->execute([$code]);
        $exists = (int)$checkStmt->fetchColumn() > 0;
    } while ($exists);

    $updateStmt = $pdo->prepare('UPDATE crm_clients SET referral_code = ? WHERE id = ?');
    $updateStmt->execute([$code, $clientId]);

    return $code;
}

function crmFindClientByReferralCode(PDO $pdo, string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $client = $stmt->fetch();
    return $client ?: null;
}

function crmReferralOrderSummaryRow(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'external_source' => (string)($row['external_source'] ?? 'woocommerce'),
        'external_order_id' => (string)($row['external_order_id'] ?? ''),
        'order_number' => $row['order_number'] ?? null,
        'order_status' => $row['order_status'] ?? null,
        'currency' => $row['currency'] ?? null,
        'total_amount' => (float)($row['total_amount'] ?? 0),
        'customer_email' => $row['customer_email'] ?? null,
        'customer_phone' => $row['customer_phone'] ?? null,
        'order_created_at' => $row['order_created_at'] ?? null,
        'attributed_at' => $row['attributed_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function crmGetClientReferralData(PDO $pdo, int $clientId, ?array $client = null): array {
    if ($client === null) {
        $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch() ?: ['id' => $clientId];
    }

    $code = trim((string)($client['referral_code'] ?? ''));
    $link = crmBuildReferralLink($code);
    $linkMessage = crmGetReferralLinkMessage($code, $link);

    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS orders_count, COALESCE(SUM(total_amount), 0) AS orders_total, MAX(order_created_at) AS last_order_at FROM crm_referral_orders WHERE client_id = ?");
    $summaryStmt->execute([$clientId]);
    $summary = $summaryStmt->fetch() ?: [];

    $visitsStmt = $pdo->prepare("SELECT COUNT(*) AS visits_count FROM crm_referral_visits WHERE client_id = ?");
    $visitsStmt->execute([$clientId]);
    $visitsCount = (int)$visitsStmt->fetchColumn();

    $ordersStmt = $pdo->prepare("SELECT * FROM crm_referral_orders WHERE client_id = ? ORDER BY COALESCE(order_created_at, created_at) DESC, id DESC");
    $ordersStmt->execute([$clientId]);
    $orders = array_map('crmReferralOrderSummaryRow', $ordersStmt->fetchAll());

    return [
        'code' => $code !== '' ? $code : null,
        'link' => $link,
        'link_ready' => $link !== null,
        'link_message' => $linkMessage,
        'stats' => [
            'orders_count' => (int)($summary['orders_count'] ?? 0),
            'orders_total' => (float)($summary['orders_total'] ?? 0),
            'visits_count' => $visitsCount,
            'last_order_at' => $summary['last_order_at'] ?? null,
        ],
        'recent_orders' => array_slice($orders, 0, 5),
        'orders' => $orders,
    ];
}

function crmNormalizeReferralOrderPayload(array $payload): array {
    $referralCode = strtoupper(trim((string)($payload['referral_code'] ?? '')));
    $externalSource = trim((string)($payload['source'] ?? 'woocommerce')) ?: 'woocommerce';
    $externalOrderId = trim((string)($payload['external_order_id'] ?? ($payload['order_id'] ?? '')));
    $orderNumber = trim((string)($payload['order_number'] ?? ''));
    $currency = trim((string)($payload['currency'] ?? ''));
    $orderStatus = trim((string)($payload['order_status'] ?? ($payload['status'] ?? '')));
    $customerEmail = trim((string)($payload['customer_email'] ?? ''));
    $customerPhone = trim((string)($payload['customer_phone'] ?? ''));
    $totalAmount = isset($payload['total_amount']) ? (float)$payload['total_amount'] : (isset($payload['total']) ? (float)$payload['total'] : 0.0);
    $orderCreatedAt = trim((string)($payload['order_created_at'] ?? ($payload['date_created'] ?? '')));
    $attributedAt = trim((string)($payload['attributed_at'] ?? ''));

    return [
        'referral_code' => $referralCode,
        'external_source' => $externalSource,
        'external_order_id' => $externalOrderId,
        'order_number' => $orderNumber !== '' ? $orderNumber : null,
        'order_status' => $orderStatus !== '' ? $orderStatus : null,
        'currency' => $currency !== '' ? $currency : null,
        'customer_email' => $customerEmail !== '' ? $customerEmail : null,
        'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
        'total_amount' => $totalAmount,
        'order_created_at' => $orderCreatedAt !== '' ? $orderCreatedAt : null,
        'attributed_at' => $attributedAt !== '' ? $attributedAt : null,
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function crmNormalizeStoreOrderItems(array $payload): array {
    $items = $payload['order_items'] ?? $payload['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemName = trim((string)($item['name'] ?? $item['item_name'] ?? ''));
        if ($itemName === '') {
            continue;
        }

        $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
        $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : (isset($item['price']) ? (float)$item['price'] : 0.0);
        $lineTotal = isset($item['line_total']) ? (float)$item['line_total'] : (isset($item['total']) ? (float)$item['total'] : ($quantity * $unitPrice));

        $normalized[] = [
            'external_item_id' => crmNullableString($item, 'external_item_id', $item['id'] ?? null),
            'product_id' => crmNullableString($item, 'product_id', null),
            'variation_id' => crmNullableString($item, 'variation_id', null),
            'sku' => crmNullableString($item, 'sku', null),
            'item_name' => $itemName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'currency' => crmNullableString($item, 'currency', $payload['currency'] ?? null),
            'meta_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $normalized;
}

function crmNormalizeStoreOrderPayload(array $payload): array {
    $orderCreatedAt = trim((string)($payload['order_created_at'] ?? ($payload['date_created'] ?? '')));
    $referralCode = strtoupper(trim((string)($payload['referral_code'] ?? '')));
    $items = crmNormalizeStoreOrderItems($payload);
    $totalItemsQty = 0;
    foreach ($items as $item) {
        $totalItemsQty += (int)round((float)($item['quantity'] ?? 0));
    }

    return [
        'external_source' => trim((string)($payload['source'] ?? 'woocommerce')) ?: 'woocommerce',
        'external_order_id' => trim((string)($payload['external_order_id'] ?? ($payload['order_id'] ?? ''))),
        'referral_code' => $referralCode !== '' ? $referralCode : null,
        'order_number' => crmNullableString($payload, 'order_number', null),
        'order_status' => crmNullableString($payload, 'order_status', $payload['status'] ?? null),
        'currency' => crmNullableString($payload, 'currency', null),
        'total_amount' => isset($payload['total_amount']) ? (float)$payload['total_amount'] : (isset($payload['total']) ? (float)$payload['total'] : 0.0),
        'subtotal_amount' => isset($payload['subtotal_amount']) ? (float)$payload['subtotal_amount'] : (isset($payload['subtotal']) ? (float)$payload['subtotal'] : 0.0),
        'shipping_amount' => isset($payload['shipping_amount']) ? (float)$payload['shipping_amount'] : (isset($payload['shipping_total']) ? (float)$payload['shipping_total'] : 0.0),
        'discount_amount' => isset($payload['discount_amount']) ? (float)$payload['discount_amount'] : (isset($payload['discount_total']) ? (float)$payload['discount_total'] : 0.0),
        'total_items_qty' => $totalItemsQty,
        'customer_email' => crmNullableString($payload, 'customer_email', null),
        'customer_phone' => crmNullableString($payload, 'customer_phone', null),
        'customer_name' => crmNullableString($payload, 'customer_name', null),
        'order_created_at' => $orderCreatedAt !== '' ? $orderCreatedAt : null,
        'synced_at' => date('Y-m-d H:i:s'),
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'items' => $items,
    ];
}

function crmReplaceStoreOrderItems(PDO $pdo, int $storeOrderId, array $items): void {
    $deleteStmt = $pdo->prepare('DELETE FROM crm_store_order_items WHERE store_order_id = ?');
    $deleteStmt->execute([$storeOrderId]);

    if (!$items) {
        return;
    }

    $insertStmt = $pdo->prepare("INSERT INTO crm_store_order_items (
        store_order_id, external_item_id, product_id, variation_id, sku, item_name, quantity, unit_price, line_total, currency, meta_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($items as $item) {
        $insertStmt->execute([
            $storeOrderId,
            $item['external_item_id'],
            $item['product_id'],
            $item['variation_id'],
            $item['sku'],
            $item['item_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total'],
            $item['currency'],
            $item['meta_json'],
        ]);
    }
}

function crmUpsertStoreOrder(PDO $pdo, array $payload, ?array $referralClient = null): array {
    $normalized = crmNormalizeStoreOrderPayload($payload);
    if ($normalized['external_order_id'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'external_order_id обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingStmt = $pdo->prepare('SELECT * FROM crm_store_orders WHERE external_source = ? AND external_order_id = ? LIMIT 1');
    $existingStmt->execute([$normalized['external_source'], $normalized['external_order_id']]);
    $existing = $existingStmt->fetch();
    $referralClientId = $referralClient ? (int)($referralClient['id'] ?? 0) : null;

    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE crm_store_orders SET
            referral_client_id = ?,
            referral_code = ?,
            order_number = ?,
            order_status = ?,
            currency = ?,
            total_amount = ?,
            subtotal_amount = ?,
            shipping_amount = ?,
            discount_amount = ?,
            total_items_qty = ?,
            customer_email = ?,
            customer_phone = ?,
            customer_name = ?,
            order_created_at = ?,
            synced_at = ?,
            payload_json = ?
            WHERE id = ?");
        $updateStmt->execute([
            $referralClientId ?: null,
            $normalized['referral_code'],
            $normalized['order_number'],
            $normalized['order_status'],
            $normalized['currency'],
            $normalized['total_amount'],
            $normalized['subtotal_amount'],
            $normalized['shipping_amount'],
            $normalized['discount_amount'],
            $normalized['total_items_qty'],
            $normalized['customer_email'],
            $normalized['customer_phone'],
            $normalized['customer_name'],
            $normalized['order_created_at'],
            $normalized['synced_at'],
            $normalized['payload_json'],
            (int)$existing['id'],
        ]);
        crmReplaceStoreOrderItems($pdo, (int)$existing['id'], $normalized['items']);

        return [
            'store_order_id' => (int)$existing['id'],
            'external_order_id' => $normalized['external_order_id'],
            'created' => false,
        ];
    }

    $insertStmt = $pdo->prepare("INSERT INTO crm_store_orders (
        external_source, external_order_id, referral_client_id, referral_code, order_number, order_status, currency,
        total_amount, subtotal_amount, shipping_amount, discount_amount, total_items_qty,
        customer_email, customer_phone, customer_name, order_created_at, synced_at, payload_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([
        $normalized['external_source'],
        $normalized['external_order_id'],
        $referralClientId ?: null,
        $normalized['referral_code'],
        $normalized['order_number'],
        $normalized['order_status'],
        $normalized['currency'],
        $normalized['total_amount'],
        $normalized['subtotal_amount'],
        $normalized['shipping_amount'],
        $normalized['discount_amount'],
        $normalized['total_items_qty'],
        $normalized['customer_email'],
        $normalized['customer_phone'],
        $normalized['customer_name'],
        $normalized['order_created_at'],
        $normalized['synced_at'],
        $normalized['payload_json'],
    ]);

    $storeOrderId = (int)$pdo->lastInsertId();
    crmReplaceStoreOrderItems($pdo, $storeOrderId, $normalized['items']);

    return [
        'store_order_id' => $storeOrderId,
        'external_order_id' => $normalized['external_order_id'],
        'created' => true,
    ];
}

function crmUpsertReferralOrderForClient(PDO $pdo, array $payload, array $client): array {
    $normalized = crmNormalizeReferralOrderPayload($payload);
    if ($normalized['external_order_id'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'external_order_id обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingStmt = $pdo->prepare('SELECT * FROM crm_referral_orders WHERE external_source = ? AND external_order_id = ? LIMIT 1');
    $existingStmt->execute([$normalized['external_source'], $normalized['external_order_id']]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE crm_referral_orders SET
            client_id = ?,
            referral_code = ?,
            order_number = ?,
            order_status = ?,
            currency = ?,
            total_amount = ?,
            customer_email = ?,
            customer_phone = ?,
            order_created_at = ?,
            attributed_at = ?,
            payload_json = ?
            WHERE id = ?");
        $updateStmt->execute([
            (int)$client['id'],
            $normalized['referral_code'],
            $normalized['order_number'],
            $normalized['order_status'],
            $normalized['currency'],
            $normalized['total_amount'],
            $normalized['customer_email'],
            $normalized['customer_phone'],
            $normalized['order_created_at'],
            $normalized['attributed_at'] ?: ($existing['attributed_at'] ?? null) ?: date('Y-m-d H:i:s'),
            $normalized['payload_json'],
            (int)$existing['id'],
        ]);

        return [
            'client_id' => (int)$client['id'],
            'referral_code' => $normalized['referral_code'],
            'external_order_id' => $normalized['external_order_id'],
            'created' => false,
            'duplicate_protected' => true,
        ];
    }

    $insertStmt = $pdo->prepare("INSERT INTO crm_referral_orders (
        client_id, referral_code, external_source, external_order_id, order_number, order_status, currency, total_amount,
        customer_email, customer_phone, order_created_at, attributed_at, payload_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([
        (int)$client['id'],
        $normalized['referral_code'],
        $normalized['external_source'],
        $normalized['external_order_id'],
        $normalized['order_number'],
        $normalized['order_status'],
        $normalized['currency'],
        $normalized['total_amount'],
        $normalized['customer_email'],
        $normalized['customer_phone'],
        $normalized['order_created_at'],
        $normalized['attributed_at'] ?: date('Y-m-d H:i:s'),
        $normalized['payload_json'],
    ]);

    crmLog($pdo, 'client', (int)$client['id'], 'referral_order', null, 'Добавлен реферальный заказ', [
        'external_source' => $normalized['external_source'],
        'external_order_id' => $normalized['external_order_id'],
        'amount' => $normalized['total_amount'],
        'status' => $normalized['order_status'],
    ]);

    return [
        'client_id' => (int)$client['id'],
        'referral_code' => $normalized['referral_code'],
        'external_order_id' => $normalized['external_order_id'],
        'created' => true,
        'duplicate_protected' => false,
    ];
}

function crmUpsertReferralOrder(PDO $pdo, array $payload): array {
    $normalized = crmNormalizeReferralOrderPayload($payload);
    if ($normalized['referral_code'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'referral_code обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($normalized['external_order_id'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'external_order_id обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $client = crmFindClientByReferralCode($pdo, $normalized['referral_code']);
    if (!$client) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Referral code не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return crmUpsertReferralOrderForClient($pdo, $payload, $client);
}

function crmSyncWooCommerceOrder(PDO $pdo, array $payload): array {
    if (!empty($payload['is_connection_test']) || !empty($payload['test_mode'])) {
        return [
            'connection_test' => true,
            'accepted' => true,
        ];
    }

    $referralCode = strtoupper(trim((string)($payload['referral_code'] ?? '')));
    $referralClient = $referralCode !== '' ? crmFindClientByReferralCode($pdo, $referralCode) : null;

    $storeOrder = crmUpsertStoreOrder($pdo, $payload, $referralClient ?: null);
    $referralOrder = null;
    if ($referralClient) {
        $referralOrder = crmUpsertReferralOrderForClient($pdo, $payload, $referralClient);
    }

    return [
        'store_order' => $storeOrder,
        'referral_order' => $referralOrder,
        'referral_client_found' => $referralClient ? true : false,
        'referral_client_id' => $referralClient ? (int)$referralClient['id'] : null,
    ];
}

function crmGetWooCommerceApiCredentials(): array {
    $baseUrl = rtrim(trim((string)(crmLoadSystemSetting('referral_woocommerce_base_url') ?? '')), '/');
    $consumerKey = trim((string)(crmLoadSystemSetting('woocommerce_api_consumer_key') ?? ''));
    $consumerSecretRaw = trim((string)(crmLoadSystemSetting('woocommerce_api_consumer_secret') ?? ''));
    $consumerSecret = '';

    if ($consumerSecretRaw !== '') {
        try {
            $consumerSecret = trim((string)(appDecrypt($consumerSecretRaw) ?? ''));
        } catch (Throwable $e) {
            $consumerSecret = $consumerSecretRaw;
        }
    }

    return [
        'base_url' => $baseUrl,
        'consumer_key' => $consumerKey,
        'consumer_secret' => $consumerSecret,
    ];
}

function crmBuildWooCommerceBasicAuthHeader(string $consumerKey, string $consumerSecret): string {
    return 'Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret);
}

function crmBuildWooCommerceOrdersUrl(array $credentials, int $perPage, int $page, string $statusParam, bool $includeQueryAuth = false): string {
    $baseUrl = rtrim(trim((string)($credentials['base_url'] ?? '')), '/');
    $query = [
        'per_page' => $perPage,
        'page' => $page,
        'orderby' => 'date',
        'order' => 'desc',
        // WooCommerce REST expects either a single status or comma-separated list.
        // Passing array produces status[0]=... which may be ignored.
        'status' => $statusParam,
    ];

    if ($includeQueryAuth) {
        $query['consumer_key'] = (string)($credentials['consumer_key'] ?? '');
        $query['consumer_secret'] = (string)($credentials['consumer_secret'] ?? '');
    }

    return $baseUrl . '/wp-json/wc/v3/orders?' . http_build_query($query);
}

function crmHttpGetJson(string $url, array $headers = []): array {
    $body = '';
    $statusCode = 0;
    $requestHeaders = ['Accept: application/json'];

    foreach ($headers as $header) {
        $header = trim((string)$header);
        if ($header === '' || in_array($header, $requestHeaders, true)) {
            continue;
        }
        $requestHeaders[] = $header;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        $body = (string)curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === '' && $error !== '') {
            throw new RuntimeException('Ошибка запроса к WooCommerce API: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'ignore_errors' => true,
                'header' => implode("\r\n", $requestHeaders) . "\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $body = $body === false ? '' : (string)$body;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', (string)$headerLine, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('WooCommerce API вернул HTTP ' . $statusCode . ($body !== '' ? ': ' . trim(strip_tags($body)) : ''), $statusCode);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('WooCommerce API вернул некорректный JSON');
    }

    return $data;
}

function crmNormalizeStoreImportTrigger(mixed $trigger): string {
    $trigger = strtolower(trim((string)$trigger));
    return in_array($trigger, ['manual', 'retry', 'resync'], true) ? $trigger : 'manual';
}

function crmGetStoreSyncStatus(PDO $pdo): array {
    $credentials = crmGetWooCommerceApiCredentials();
    $configured = $credentials['base_url'] !== '' && $credentials['consumer_key'] !== '' && $credentials['consumer_secret'] !== '';
    $configError = $configured ? null : 'Не заполнены WooCommerce API URL / Consumer Key / Consumer Secret';

    $entries = auditReadRecent($pdo, 5, [
        'event_type' => 'crm.store.imported',
        'target_type' => 'crm_store_orders',
        'target_id' => 'bulk',
    ]);

    $recent = [];
    $lastSuccess = null;
    $lastFailure = null;

    foreach ($entries as $entry) {
        $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];
        $item = [
            'id' => isset($entry['id']) ? (int)$entry['id'] : null,
            'created_at' => $entry['created_at'] ?? null,
            'summary' => (string)($entry['summary'] ?? ''),
            'success' => !empty($details['success']),
            'trigger' => crmNormalizeStoreImportTrigger($details['trigger'] ?? 'manual'),
            'error' => isset($details['error']) ? trim((string)$details['error']) : null,
            'imported' => isset($details['imported']) ? (int)$details['imported'] : 0,
            'updated' => isset($details['updated']) ? (int)$details['updated'] : 0,
            'pages_fetched' => isset($details['pages_fetched']) ? (int)$details['pages_fetched'] : 0,
            'orders_fetched' => isset($details['orders_fetched']) ? (int)$details['orders_fetched'] : 0,
            'auth_mode' => isset($details['auth_mode']) ? trim((string)$details['auth_mode']) : null,
            'auth_fallback_used' => !empty($details['auth_fallback_used']),
        ];

        $recent[] = $item;
        if ($lastSuccess === null && $item['success']) {
            $lastSuccess = $item;
        }
        if ($lastFailure === null && !$item['success']) {
            $lastFailure = $item;
        }
    }

    $latest = $recent[0] ?? null;
    $state = 'idle';
    $label = 'Синхронизация WooCommerce еще не запускалась';
    $lastError = null;

    if (!$configured) {
        $state = 'not_configured';
        $label = 'WooCommerce API не настроен';
        $lastError = $configError;
    } elseif ($latest !== null) {
        $state = !empty($latest['success']) ? 'healthy' : 'failed';
        if ($state === 'healthy') {
            $label = 'Последняя синхронизация WooCommerce успешна';
        } else {
            $label = 'Последняя синхронизация WooCommerce завершилась с ошибкой';
            $lastError = $latest['error'] ?? ($lastFailure['error'] ?? null);
        }
    }

    return [
        'configured' => $configured,
        'state' => $state,
        'label' => $label,
        'latest' => $latest,
        'last_success_at' => $lastSuccess['created_at'] ?? null,
        'last_failure_at' => $lastFailure['created_at'] ?? null,
        'last_error' => $lastError,
        'recent' => $recent,
    ];
}

function crmMapWooCommerceOrderToStorePayload(array $order): array {
    $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    $lineItems = is_array($order['line_items'] ?? null) ? $order['line_items'] : [];
    $shippingTotal = isset($order['shipping_total']) ? (float)$order['shipping_total'] : 0.0;
    $discountTotal = isset($order['discount_total']) ? (float)$order['discount_total'] : 0.0;
    $lineItemsPayload = [];
    $subtotalAmount = 0.0;

    foreach ($lineItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
        $lineSubtotal = isset($item['subtotal']) ? (float)$item['subtotal'] : 0.0;
        $lineTotal = isset($item['total']) ? (float)$item['total'] : $lineSubtotal;
        $unitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0;
        $subtotalAmount += $lineSubtotal;

        $lineItemsPayload[] = [
            'id' => isset($item['id']) ? (string)$item['id'] : null,
            'product_id' => isset($item['product_id']) ? (string)$item['product_id'] : null,
            'variation_id' => isset($item['variation_id']) ? (string)$item['variation_id'] : null,
            'sku' => crmNullableString($item, 'sku', null),
            'name' => crmNullableString($item, 'name', ''),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'currency' => crmNullableString($order, 'currency', 'RUB'),
        ];
    }

    $customerName = trim(((string)($billing['first_name'] ?? '')) . ' ' . ((string)($billing['last_name'] ?? '')));
    $referralCode = '';
    foreach ((array)($order['meta_data'] ?? []) as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        if (($meta['key'] ?? '') === '_workhub_referral_code') {
            $referralCode = strtoupper(trim((string)($meta['value'] ?? '')));
            break;
        }
    }

    return [
        'source' => 'woocommerce',
        'referral_code' => $referralCode !== '' ? $referralCode : null,
        'external_order_id' => isset($order['id']) ? (string)$order['id'] : '',
        'order_number' => crmNullableString($order, 'number', null),
        'order_status' => crmNullableString($order, 'status', null),
        'currency' => crmNullableString($order, 'currency', 'RUB'),
        'total_amount' => isset($order['total']) ? (float)$order['total'] : 0.0,
        'subtotal_amount' => $subtotalAmount,
        'shipping_amount' => $shippingTotal,
        'discount_amount' => $discountTotal,
        'customer_email' => crmNullableString($billing, 'email', null),
        'customer_phone' => crmNullableString($billing, 'phone', null),
        'customer_name' => $customerName !== '' ? $customerName : null,
        'order_created_at' => crmNullableString($order, 'date_created', null),
        'order_items' => $lineItemsPayload,
    ];
}

function crmImportWooCommerceOrders(PDO $pdo, array $currentUser): void {
    if (!hasAdminAccess($currentUser)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Нет доступа'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $credentials = crmGetWooCommerceApiCredentials();
    $payload = json_decode(file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $trigger = crmNormalizeStoreImportTrigger($payload['trigger'] ?? 'manual');
    $actionLabel = $trigger === 'manual' ? 'Импорт заказов WooCommerce' : 'Повторный импорт заказов WooCommerce';

    if ($credentials['base_url'] === '' || $credentials['consumer_key'] === '' || $credentials['consumer_secret'] === '') {
        auditLog($pdo, 'crm.store.imported', [
            'actor' => $currentUser,
            'target_type' => 'crm_store_orders',
            'target_id' => 'bulk',
            'summary' => $actionLabel . ' не выполнен',
            'details' => [
                'success' => false,
                'trigger' => $trigger,
                'error' => 'Не заполнены WooCommerce API URL / Consumer Key / Consumer Secret',
            ],
        ]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не заполнены WooCommerce API URL / Consumer Key / Consumer Secret', 'data' => ['sync_status' => crmGetStoreSyncStatus($pdo), 'trigger' => $trigger]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $perPage = max(1, min(100, (int)($payload['per_page'] ?? 50)));
    $maxPages = max(1, min(20, (int)($payload['max_pages'] ?? 5)));
    $statuses = $payload['statuses'] ?? ['processing', 'completed', 'on-hold'];
    if (!is_array($statuses) || !$statuses) {
        $statuses = ['processing', 'completed', 'on-hold'];
    }

    $statusList = array_values(array_filter(array_map(static fn($status): string => trim((string)$status), $statuses), static fn(string $v): bool => $v !== ''));
    $statusParam = $statusList ? implode(',', $statusList) : 'any';
    $authMode = 'basic_auth';
    $basicAuthHeader = crmBuildWooCommerceBasicAuthHeader($credentials['consumer_key'], $credentials['consumer_secret']);

    $imported = 0;
    $updated = 0;
    $pagesFetched = 0;
    $ordersFetched = 0;
    $lastUrl = null;
    $auditBaseDetails = [
        'operation' => 'import_woocommerce_orders',
        'trigger' => $trigger,
        'status_filter' => $statusList,
        'per_page' => $perPage,
        'max_pages' => $maxPages,
        'auth_mode' => $authMode,
        'auth_fallback_used' => false,
    ];

    try {
        for ($page = 1; $page <= $maxPages; $page++) {
            $useQueryAuth = $authMode !== 'basic_auth';
            $url = crmBuildWooCommerceOrdersUrl($credentials, $perPage, $page, $statusParam, $useQueryAuth);
            $lastUrl = $url;

            try {
                $orders = $useQueryAuth ? crmHttpGetJson($url) : crmHttpGetJson($url, [$basicAuthHeader]);
            } catch (RuntimeException $e) {
                $statusCode = (int)$e->getCode();
                if ($authMode === 'basic_auth' && in_array($statusCode, [401, 403], true)) {
                    $authMode = 'query_auth_fallback';
                    $fallbackUrl = crmBuildWooCommerceOrdersUrl($credentials, $perPage, $page, $statusParam, true);
                    $lastUrl = $fallbackUrl;
                    $orders = crmHttpGetJson($fallbackUrl);
                } else {
                    throw $e;
                }
            }

            $pagesFetched++;

            if (!$orders) {
                break;
            }

            if (is_array($orders)) {
                $ordersFetched += count($orders);
            }

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }

                $mapped = crmMapWooCommerceOrderToStorePayload($order);
                $referralCode = strtoupper(trim((string)($mapped['referral_code'] ?? '')));
                $referralClient = $referralCode !== '' ? crmFindClientByReferralCode($pdo, $referralCode) : null;
                $result = crmUpsertStoreOrder($pdo, $mapped, $referralClient ?: null);
                if (!empty($result['created'])) {
                    $imported++;
                } else {
                    $updated++;
                }
            }

            if (count($orders) < $perPage) {
                break;
            }
        }

        $auditBaseDetails['auth_mode'] = $authMode;
        $auditBaseDetails['auth_fallback_used'] = $authMode !== 'basic_auth';

        auditLog($pdo, 'crm.store.imported', [
            'actor' => $currentUser,
            'target_type' => 'crm_store_orders',
            'target_id' => 'bulk',
            'summary' => $actionLabel,
            'details' => $auditBaseDetails + [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'pages_fetched' => $pagesFetched,
                'orders_fetched' => $ordersFetched,
            ],
        ]);

        $syncStatus = crmGetStoreSyncStatus($pdo);

        echo json_encode(['success' => true, 'data' => [
            'imported' => $imported,
            'updated' => $updated,
            'pages_fetched' => $pagesFetched,
            'orders_fetched' => $ordersFetched,
            'per_page' => $perPage,
            'max_pages' => $maxPages,
            'trigger' => $trigger,
            'auth_mode' => $authMode,
            'auth_fallback_used' => $authMode !== 'basic_auth',
            'sync_status' => $syncStatus,
        ]], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('crmImportWooCommerceOrders failed: ' . $e->getMessage());
        if ($lastUrl) {
            // do not log credentials
            $safeUrl = preg_replace('/([?&]consumer_secret=)[^&]+/i', '$1***', $lastUrl);
            $safeUrl = preg_replace('/([?&]consumer_key=)[^&]+/i', '$1***', $safeUrl);
            error_log('crmImportWooCommerceOrders url: ' . $safeUrl);
        }
        auditLog($pdo, 'crm.store.imported', [
            'actor' => $currentUser,
            'target_type' => 'crm_store_orders',
            'target_id' => 'bulk',
            'summary' => $actionLabel . ' завершился с ошибкой',
            'details' => $auditBaseDetails + [
                'success' => false,
                'imported' => $imported,
                'updated' => $updated,
                'pages_fetched' => $pagesFetched,
                'orders_fetched' => $ordersFetched,
                'error' => $e->getMessage(),
            ],
        ]);
        $syncStatus = crmGetStoreSyncStatus($pdo);
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Не удалось получить данные из WooCommerce: ' . $e->getMessage(), 'data' => ['trigger' => $trigger, 'sync_status' => $syncStatus]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function crmGetStoreOrderItems(PDO $pdo, array $orderIds): array {
    if (!$orderIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM crm_store_order_items WHERE store_order_id IN ($placeholders) ORDER BY id ASC");
    $stmt->execute($orderIds);

    $itemsByOrder = [];
    foreach ($stmt->fetchAll() as $row) {
        $orderId = (int)($row['store_order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }

        $itemsByOrder[$orderId][] = [
            'id' => (int)($row['id'] ?? 0),
            'external_item_id' => $row['external_item_id'] ?? null,
            'product_id' => $row['product_id'] ?? null,
            'variation_id' => $row['variation_id'] ?? null,
            'sku' => $row['sku'] ?? null,
            'item_name' => $row['item_name'] ?? '',
            'quantity' => (float)($row['quantity'] ?? 0),
            'unit_price' => (float)($row['unit_price'] ?? 0),
            'line_total' => (float)($row['line_total'] ?? 0),
            'currency' => $row['currency'] ?? null,
        ];
    }

    return $itemsByOrder;
}

function crmGetStoreAnalytics(PDO $pdo): void {
    $query = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $fromMonth = crmNormalizeMonthValue($_GET['from_month'] ?? null);
    $toMonth = crmNormalizeMonthValue($_GET['to_month'] ?? null);
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

    if ($fromMonth === null || $toMonth === null) {
        $latestMonth = $pdo->query("SELECT MAX(DATE_FORMAT(COALESCE(order_created_at, created_at), '%Y-%m-01')) AS max_month FROM crm_store_orders")->fetchColumn();
        if ($latestMonth) {
            $latest = new DateTimeImmutable((string)$latestMonth);
            if ($toMonth === null) {
                $toMonth = $latest->format('Y-m-01');
            }
            if ($fromMonth === null) {
                $fromMonth = $latest->modify('-11 months')->format('Y-m-01');
            }
        }
    }

    $filters = [];
    $params = [];
    if ($query !== '') {
        $filters[] = '(o.order_number LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ? OR o.customer_name LIKE ? OR o.referral_code LIKE ?)';
        $term = '%' . $query . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    if ($status !== '') {
        $filters[] = 'o.order_status = ?';
        $params[] = $status;
    }
    if ($fromMonth !== null) {
        $filters[] = 'DATE_FORMAT(COALESCE(o.order_created_at, o.created_at), "%Y-%m-01") >= ?';
        $params[] = $fromMonth;
    }
    if ($toMonth !== null) {
        $filters[] = 'DATE_FORMAT(COALESCE(o.order_created_at, o.created_at), "%Y-%m-01") <= ?';
        $params[] = $toMonth;
    }

    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

    $monthlyStmt = $pdo->prepare("SELECT DATE_FORMAT(COALESCE(o.order_created_at, o.created_at), '%Y-%m-01') AS sale_month, COALESCE(SUM(o.total_amount), 0) AS amount, COUNT(*) AS orders_count FROM crm_store_orders o $where GROUP BY sale_month ORDER BY sale_month ASC");
    $monthlyStmt->execute($params);
    $monthlyTotals = array_map(static function (array $row): array {
        return [
            'sale_month' => $row['sale_month'],
            'amount' => (float)($row['amount'] ?? 0),
            'orders_count' => (int)($row['orders_count'] ?? 0),
        ];
    }, $monthlyStmt->fetchAll());

    $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount), 0) AS total_amount, COUNT(*) AS orders_count, COUNT(DISTINCT o.customer_email) AS customers_count, COALESCE(SUM(o.total_items_qty), 0) AS total_items_qty, MAX(COALESCE(o.order_created_at, o.created_at)) AS last_order_at FROM crm_store_orders o $where");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];

    $latestAnalyticsMonth = $toMonth;
    if ($latestAnalyticsMonth === null && $monthlyTotals) {
        $latestAnalyticsMonth = (string)end($monthlyTotals)['sale_month'];
        reset($monthlyTotals);
    }

    $comparison = [
        'current_month' => null,
        'previous_month' => null,
        'current_amount' => 0.0,
        'previous_amount' => 0.0,
        'amount_delta' => 0.0,
        'amount_delta_percent' => null,
        'current_orders' => 0,
        'previous_orders' => 0,
        'orders_delta' => 0,
        'orders_delta_percent' => null,
    ];

    if ($latestAnalyticsMonth !== null) {
        $currentMonthObj = new DateTimeImmutable($latestAnalyticsMonth);
        $previousMonth = $currentMonthObj->modify('-1 month')->format('Y-m-01');
        $comparisonMap = [];
        foreach ($monthlyTotals as $row) {
            $comparisonMap[(string)$row['sale_month']] = $row;
        }

        $currentRow = $comparisonMap[$currentMonthObj->format('Y-m-01')] ?? ['amount' => 0, 'orders_count' => 0];
        $previousRow = $comparisonMap[$previousMonth] ?? ['amount' => 0, 'orders_count' => 0];
        $amountDelta = (float)$currentRow['amount'] - (float)$previousRow['amount'];
        $ordersDelta = (int)$currentRow['orders_count'] - (int)$previousRow['orders_count'];

        $comparison = [
            'current_month' => $currentMonthObj->format('Y-m-01'),
            'previous_month' => $previousMonth,
            'current_amount' => (float)$currentRow['amount'],
            'previous_amount' => (float)$previousRow['amount'],
            'amount_delta' => $amountDelta,
            'amount_delta_percent' => (float)$previousRow['amount'] != 0.0 ? round(($amountDelta / (float)$previousRow['amount']) * 100, 1) : null,
            'current_orders' => (int)$currentRow['orders_count'],
            'previous_orders' => (int)$previousRow['orders_count'],
            'orders_delta' => $ordersDelta,
            'orders_delta_percent' => (int)$previousRow['orders_count'] !== 0 ? round(($ordersDelta / (int)$previousRow['orders_count']) * 100, 1) : null,
        ];
    }

    $ordersStmt = $pdo->prepare("SELECT o.*, COUNT(i.id) AS items_count FROM crm_store_orders o LEFT JOIN crm_store_order_items i ON i.store_order_id = o.id $where GROUP BY o.id ORDER BY COALESCE(o.order_created_at, o.created_at) DESC, o.id DESC LIMIT $limit");
    $ordersStmt->execute($params);
    $ordersRows = $ordersStmt->fetchAll();
    $orderIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $ordersRows);
    $itemsByOrder = crmGetStoreOrderItems($pdo, array_values(array_filter($orderIds)));

    $orders = array_map(static function (array $row) use ($itemsByOrder): array {
        $orderId = (int)($row['id'] ?? 0);
        $items = $itemsByOrder[$orderId] ?? [];
        return [
            'id' => $orderId,
            'external_source' => (string)($row['external_source'] ?? 'woocommerce'),
            'external_order_id' => (string)($row['external_order_id'] ?? ''),
            'referral_client_id' => isset($row['referral_client_id']) ? (int)$row['referral_client_id'] : null,
            'referral_code' => $row['referral_code'] ?? null,
            'order_number' => $row['order_number'] ?? null,
            'order_status' => $row['order_status'] ?? null,
            'currency' => $row['currency'] ?? 'RUB',
            'total_amount' => (float)($row['total_amount'] ?? 0),
            'subtotal_amount' => (float)($row['subtotal_amount'] ?? 0),
            'shipping_amount' => (float)($row['shipping_amount'] ?? 0),
            'discount_amount' => (float)($row['discount_amount'] ?? 0),
            'total_items_qty' => (int)($row['total_items_qty'] ?? 0),
            'customer_email' => $row['customer_email'] ?? null,
            'customer_phone' => $row['customer_phone'] ?? null,
            'customer_name' => $row['customer_name'] ?? null,
            'order_created_at' => $row['order_created_at'] ?? null,
            'synced_at' => $row['synced_at'] ?? null,
            'items_count' => (int)($row['items_count'] ?? 0),
            'items' => $items,
        ];
    }, $ordersRows);

    $availableStatuses = $pdo->query("SELECT DISTINCT order_status FROM crm_store_orders WHERE order_status IS NOT NULL AND order_status <> '' ORDER BY order_status ASC")->fetchAll();
    $availableMonths = $pdo->query("SELECT DISTINCT DATE_FORMAT(COALESCE(order_created_at, created_at), '%Y-%m-01') AS sale_month FROM crm_store_orders ORDER BY sale_month DESC")->fetchAll();

    echo json_encode(['success' => true, 'data' => [
        'summary' => [
            'total_amount' => (float)($summary['total_amount'] ?? 0),
            'orders_count' => (int)($summary['orders_count'] ?? 0),
            'customers_count' => (int)($summary['customers_count'] ?? 0),
            'total_items_qty' => (int)($summary['total_items_qty'] ?? 0),
            'last_order_at' => $summary['last_order_at'] ?? null,
            'average_order_amount' => (int)($summary['orders_count'] ?? 0) > 0 ? round((float)$summary['total_amount'] / (int)$summary['orders_count'], 2) : 0,
        ],
        'period' => [
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
        ],
        'comparison' => $comparison,
        'monthly_totals' => $monthlyTotals,
        'orders' => $orders,
        'available_statuses' => array_map(static fn(array $row): string => (string)$row['order_status'], $availableStatuses),
        'available_months' => array_map(static fn(array $row): string => (string)$row['sale_month'], $availableMonths),
        'sync_status' => crmGetStoreSyncStatus($pdo),
    ]], JSON_UNESCAPED_UNICODE);
}

function crmCreateReferralVisit(PDO $pdo, array $payload): array {
    $referralCode = strtoupper(trim((string)($payload['referral_code'] ?? '')));
    if ($referralCode === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'referral_code обязателен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $client = crmFindClientByReferralCode($pdo, $referralCode);
    if (!$client) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Referral code не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO crm_referral_visits (
        client_id, referral_code, external_source, landing_url, referrer_url, visitor_ip, user_agent, visit_token
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int)$client['id'],
        $referralCode,
        trim((string)($payload['source'] ?? 'woocommerce')) ?: 'woocommerce',
        trim((string)($payload['landing_url'] ?? '')) ?: null,
        trim((string)($payload['referrer_url'] ?? '')) ?: null,
        trim((string)($payload['visitor_ip'] ?? '')) ?: null,
        trim((string)($payload['user_agent'] ?? '')) ?: null,
        trim((string)($payload['visit_token'] ?? '')) ?: null,
    ]);

    return [
        'client_id' => (int)$client['id'],
        'referral_code' => $referralCode,
        'visit_id' => (int)$pdo->lastInsertId(),
    ];
}

function crmRequireAdminToolsAccess(array $currentUser): void {
    if (!hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступно только администраторам']);
        exit;
    }
}

function crmHandleAdminTools(PDO $pdo, array $currentUser): void {
    crmRequireAdminToolsAccess($currentUser);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Некорректное тело запроса']);
        exit;
    }

    $operation = trim((string)($data['operation'] ?? ''));
    $mode = trim((string)($data['mode'] ?? 'dry-run'));
    $apply = $mode === 'apply';

    try {
        switch ($operation) {
            case 'import_sales':
                $result = crmToolsImportSales([
                    'file' => isset($data['file']) ? (string)$data['file'] : null,
                    'sheet' => isset($data['sheet']) ? (string)$data['sheet'] : 'База клиентов',
                    'clients_sheet' => isset($data['clients_sheet']) ? (string)$data['clients_sheet'] : 'Работа с АКБ',
                    'dry_run' => !$apply,
                ]);
                break;

            case 'diagnose_duplicates':
                $result = crmToolsDiagnoseDuplicates([
                    'client_id' => isset($data['client_id']) ? (int)$data['client_id'] : null,
                ]);
                $result['success'] = true;
                $result['mode'] = 'dry-run';
                break;

            case 'merge_duplicates':
                $result = crmToolsMergeDuplicates([
                    'apply' => $apply,
                    'client_id' => isset($data['client_id']) ? (int)$data['client_id'] : null,
                    'primary_id' => isset($data['primary_id']) ? (int)$data['primary_id'] : null,
                    'group_index' => isset($data['group_index']) ? (int)$data['group_index'] : null,
                    'all' => !empty($data['all']),
                    'log_source' => 'web',
                ]);
                break;

            default:
                auditLog($pdo, 'crm.admin_tools.executed', [
                    'actor' => $currentUser,
                    'target_type' => 'crm_admin_tool',
                    'target_id' => $operation,
                    'summary' => 'CRM админ-инструмент завершился с ошибкой',
                    'details' => [
                        'operation' => $operation,
                        'mode' => $apply ? 'apply' : 'dry-run',
                        'success' => false,
                        'error' => 'Неизвестная операция',
                    ],
                ]);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Неизвестная операция']);
                exit;
        }

        $result['mode'] = $apply ? 'apply' : 'dry-run';
        $result['requested_operation'] = $operation;
        $result['executed_by'] = [
            'id' => (int)($currentUser['id'] ?? 0),
            'role' => $currentUser['role'] ?? null,
            'full_name' => $currentUser['full_name'] ?? null,
        ];

        $auditDetails = [
            'operation' => $operation,
            'mode' => $result['mode'] ?? ($apply ? 'apply' : 'dry-run'),
            'success' => (bool)($result['success'] ?? true),
        ];
        if ($operation === 'import_sales') {
            $auditDetails['inserted_clients'] = (int)($result['inserted_clients'] ?? 0);
            $auditDetails['updated_clients'] = (int)($result['updated_clients'] ?? 0);
            $auditDetails['inserted_contacts'] = (int)($result['inserted_contacts'] ?? 0);
            $auditDetails['sales_rows'] = (int)($result['sales_rows'] ?? 0);
        } elseif ($operation === 'diagnose_duplicates') {
            $auditDetails['clients_total'] = (int)($result['clients_total'] ?? 0);
            $auditDetails['duplicate_groups_total'] = (int)($result['duplicate_groups_total'] ?? 0);
        } elseif ($operation === 'merge_duplicates') {
            $auditDetails['groups_found'] = (int)($result['groups_found'] ?? 0);
            $auditDetails['groups_processed'] = (int)($result['groups_processed'] ?? 0);
        }

        auditLog($pdo, 'crm.admin_tools.executed', [
            'actor' => $currentUser,
            'target_type' => 'crm_admin_tool',
            'target_id' => $operation,
            'summary' => 'CRM админ-инструмент: ' . $operation,
            'details' => $auditDetails,
        ]);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        auditLog($pdo, 'crm.admin_tools.executed', [
            'actor' => $currentUser,
            'target_type' => 'crm_admin_tool',
            'target_id' => $operation,
            'summary' => 'CRM админ-инструмент завершился с ошибкой',
            'details' => [
                'operation' => $operation,
                'mode' => $apply ? 'apply' : 'dry-run',
                'success' => false,
                'error' => $e->getMessage(),
            ],
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'operation' => $operation,
            'mode' => $apply ? 'apply' : 'dry-run',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function crmEnsureClientRequisitesSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $requiredColumns = [
        'legal_name_full' => "ALTER TABLE crm_clients ADD COLUMN legal_name_full VARCHAR(255) NULL AFTER name",
        'legal_name_short' => "ALTER TABLE crm_clients ADD COLUMN legal_name_short VARCHAR(255) NULL AFTER legal_name_full",
        'inn' => "ALTER TABLE crm_clients ADD COLUMN inn VARCHAR(32) NULL AFTER legal_name_short",
        'kpp' => "ALTER TABLE crm_clients ADD COLUMN kpp VARCHAR(32) NULL AFTER inn",
        'ogrn' => "ALTER TABLE crm_clients ADD COLUMN ogrn VARCHAR(32) NULL AFTER kpp",
        'legal_address' => "ALTER TABLE crm_clients ADD COLUMN legal_address TEXT NULL AFTER address",
        'postal_address' => "ALTER TABLE crm_clients ADD COLUMN postal_address TEXT NULL AFTER legal_address",
        'signer_name' => "ALTER TABLE crm_clients ADD COLUMN signer_name VARCHAR(255) NULL AFTER postal_address",
        'signer_position' => "ALTER TABLE crm_clients ADD COLUMN signer_position VARCHAR(255) NULL AFTER signer_name",
        'signer_authority' => "ALTER TABLE crm_clients ADD COLUMN signer_authority VARCHAR(255) NULL AFTER signer_position",
        'bank_name' => "ALTER TABLE crm_clients ADD COLUMN bank_name VARCHAR(255) NULL AFTER signer_authority",
        'bik' => "ALTER TABLE crm_clients ADD COLUMN bik VARCHAR(32) NULL AFTER bank_name",
        'checking_account' => "ALTER TABLE crm_clients ADD COLUMN checking_account VARCHAR(64) NULL AFTER bik",
        'correspondent_account' => "ALTER TABLE crm_clients ADD COLUMN correspondent_account VARCHAR(64) NULL AFTER checking_account",
    ];

    $existingColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM crm_clients") as $column) {
        $existingColumns[] = (string)($column['Field'] ?? '');
    }

    foreach ($requiredColumns as $columnName => $sql) {
        if (!in_array($columnName, $existingColumns, true)) {
            $pdo->exec($sql);
            $existingColumns[] = $columnName;
        }
    }

    $ensured = true;
}

function crmEnsureSalesSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_client_monthly_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        sale_month DATE NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        source_sheet VARCHAR(100) NULL,
        source_client_name VARCHAR(255) NULL,
        source_manager_name VARCHAR(255) NULL,
        source_total_amount DECIMAL(14,2) NULL,
        import_batch VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_month (client_id, sale_month),
        INDEX idx_sale_month (sale_month),
        INDEX idx_client_month (client_id, sale_month),
        INDEX idx_amount (amount),
        CONSTRAINT fk_crm_client_monthly_sales_client FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function crmEnsureDealsSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $requiredColumns = [
        'deleted_at' => "ALTER TABLE crm_deals ADD COLUMN deleted_at DATETIME NULL AFTER updated_at",
        'won_recorded_at' => "ALTER TABLE crm_deals ADD COLUMN won_recorded_at DATETIME NULL AFTER deleted_at",
        'won_recorded_month' => "ALTER TABLE crm_deals ADD COLUMN won_recorded_month DATE NULL AFTER won_recorded_at",
    ];

    $existingColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM crm_deals") as $column) {
        $existingColumns[] = (string)($column['Field'] ?? '');
    }

    foreach ($requiredColumns as $col => $sql) {
        if (!in_array($col, $existingColumns, true)) {
            $pdo->exec($sql);
        }
    }

    // Best-effort indexes (ignore if already exist)
    try {
        $pdo->exec("CREATE INDEX idx_crm_deals_deleted_at ON crm_deals(deleted_at)");
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec("CREATE INDEX idx_crm_deals_won_recorded_at ON crm_deals(won_recorded_at)");
    } catch (Throwable $e) {
        // ignore
    }

    $ensured = true;
}

function crmFirstDayOfMonth(string $ymdOrDateTime): string {
    $ts = strtotime($ymdOrDateTime);
    if ($ts === false) {
        return date('Y-m-01');
    }
    return date('Y-m-01', $ts);
}

function crmRecordWonDealSale(PDO $pdo, array $deal, array $currentUser): void {
    // Idempotency: only first time.
    if (!empty($deal['won_recorded_at'])) {
        return;
    }

    $amount = (float)($deal['amount'] ?? 0);
    $saleMonth = crmFirstDayOfMonth((string)($deal['updated_at'] ?? date('Y-m-d')));
    if ($amount <= 0) {
        // still mark as recorded to avoid repeated attempts on stage toggles
        $pdo->prepare("UPDATE crm_deals SET won_recorded_at=NOW(), won_recorded_month=? WHERE id=?")
            ->execute([$saleMonth, (int)$deal['id']]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO crm_client_monthly_sales (client_id, sale_month, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)");
    $stmt->execute([(int)$deal['client_id'], $saleMonth, $amount]);

    $pdo->prepare("UPDATE crm_deals SET won_recorded_at=NOW(), won_recorded_month=? WHERE id=?")
        ->execute([$saleMonth, (int)$deal['id']]);

    crmLog(
        $pdo,
        'deal',
        (int)$deal['id'],
        'won_record',
        (int)($currentUser['id'] ?? 0),
        'Сумма сделки добавлена в историю продаж клиента',
        ['amount' => $amount, 'sale_month' => $saleMonth]
    );
}

function crmEnsureClientContactsSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_client_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position VARCHAR(255) NULL,
        email VARCHAR(255) NULL,
        phone VARCHAR(80) NULL,
        is_primary TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client (client_id),
        INDEX idx_email (email),
        INDEX idx_phone (phone),
        CONSTRAINT fk_crm_client_contacts_client FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function crmNormalizeMonthValue(mixed $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $value, $matches)) {
        return sprintf('%04d-%02d-01', (int)$matches[1], (int)$matches[2]);
    }

    return null;
}

function crmNormalizeClientName(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = str_replace(['"', '«', '»'], '', $value);
    return trim($value);
}

function crmCanonicalizeClientName(string $value): string {
    $value = crmNormalizeClientName($value);
    $value = preg_replace('/\b(ооо|ооо\.|ип|ао|пао|зао|оао)\b/iu', ' ', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    return trim($value);
}

function crmCollectClientNameKeys(array $client): array {
    $normalized = [];
    $canonical = [];

    foreach (['name', 'legal_name_full', 'legal_name_short'] as $field) {
        $value = trim((string)($client[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        $normalizedKey = crmNormalizeClientName($value);
        if ($normalizedKey !== '') {
            $normalized[$normalizedKey] = true;
        }

        $canonicalKey = crmCanonicalizeClientName($value);
        if ($canonicalKey !== '') {
            $canonical[$canonicalKey] = true;
        }
    }

    return [
        'normalized' => array_keys($normalized),
        'canonical' => array_keys($canonical),
    ];
}

function crmResolveSalesClientIds(PDO $pdo, array $client): array {
    $keys = crmCollectClientNameKeys($client);
    $normalizedKeys = array_fill_keys($keys['normalized'], true);
    $canonicalKeys = array_fill_keys($keys['canonical'], true);
    $relatedIds = [];

    if (!$normalizedKeys && !$canonicalKeys) {
        return [(int)$client['id']];
    }

    $stmt = $pdo->query("SELECT DISTINCT c.id, c.name, c.legal_name_full, c.legal_name_short
        FROM crm_clients c
        JOIN crm_client_monthly_sales s ON s.client_id = c.id");

    foreach ($stmt->fetchAll() as $candidate) {
        $candidateId = (int)($candidate['id'] ?? 0);
        if ($candidateId <= 0) {
            continue;
        }

        if ($candidateId === (int)$client['id']) {
            $relatedIds[$candidateId] = true;
            continue;
        }

        $candidateKeys = crmCollectClientNameKeys($candidate);
        $matchesNormalized = array_intersect_key(array_fill_keys($candidateKeys['normalized'], true), $normalizedKeys);
        $matchesCanonical = array_intersect_key(array_fill_keys($candidateKeys['canonical'], true), $canonicalKeys);
        if ($matchesNormalized || $matchesCanonical) {
            $relatedIds[$candidateId] = true;
        }
    }

    $relatedIds[(int)$client['id']] = true;
    $resolved = array_map('intval', array_keys($relatedIds));
    sort($resolved);
    return $resolved;
}

function crmGetClientSalesData(PDO $pdo, int $clientId, ?array $client = null): array {
    if ($client === null) {
        $clientStmt = $pdo->prepare("SELECT id, name, legal_name_full, legal_name_short FROM crm_clients WHERE id=?");
        $clientStmt->execute([$clientId]);
        $client = $clientStmt->fetch() ?: ['id' => $clientId];
    }

    $relatedClientIds = crmResolveSalesClientIds($pdo, $client);
    $placeholders = implode(',', array_fill(0, count($relatedClientIds), '?'));

    $stmt = $pdo->prepare("SELECT s.client_id, c.name AS client_name, s.sale_month, s.amount, s.source_sheet, s.source_manager_name, s.source_total_amount
        FROM crm_client_monthly_sales s
        JOIN crm_clients c ON c.id = s.client_id
        WHERE s.client_id IN ({$placeholders})
        ORDER BY s.sale_month DESC, s.client_id ASC");
    $stmt->execute($relatedClientIds);
    $rows = $stmt->fetchAll();

    $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_sales, COUNT(*) AS months_count, MAX(sale_month) AS last_sale_month, MIN(sale_month) AS first_sale_month, COALESCE(AVG(NULLIF(amount, 0)), 0) AS average_monthly_sales FROM crm_client_monthly_sales WHERE client_id IN ({$placeholders})");
    $summaryStmt->execute($relatedClientIds);
    $summary = $summaryStmt->fetch() ?: [];

    $directSalesCount = 0;
    foreach ($rows as $row) {
        if ((int)($row['client_id'] ?? 0) === $clientId) {
            $directSalesCount++;
        }
    }

    return [
        'summary' => [
            'total_sales' => (float)($summary['total_sales'] ?? 0),
            'months_count' => (int)($summary['months_count'] ?? 0),
            'last_sale_month' => $summary['last_sale_month'] ?? null,
            'first_sale_month' => $summary['first_sale_month'] ?? null,
            'average_monthly_sales' => (float)($summary['average_monthly_sales'] ?? 0),
        ],
        'history' => array_map(static function (array $row): array {
            return [
                'client_id' => (int)($row['client_id'] ?? 0),
                'client_name' => $row['client_name'] ?? null,
                'sale_month' => $row['sale_month'],
                'amount' => (float)($row['amount'] ?? 0),
                'source_sheet' => $row['source_sheet'] ?? null,
                'source_manager_name' => $row['source_manager_name'] ?? null,
                'source_total_amount' => $row['source_total_amount'] !== null ? (float)$row['source_total_amount'] : null,
            ];
        }, $rows),
        'diagnostics' => [
            'requested_client_id' => $clientId,
            'matched_client_ids' => $relatedClientIds,
            'matched_clients_count' => count($relatedClientIds),
            'direct_sales_records' => $directSalesCount,
            'used_related_clients' => count($relatedClientIds) > 1,
        ],
    ];
}

function crmGetSalesAnalytics(PDO $pdo): void {
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $query = trim((string)($_GET['q'] ?? ''));
    $fromMonth = crmNormalizeMonthValue($_GET['from_month'] ?? null);
    $toMonth = crmNormalizeMonthValue($_GET['to_month'] ?? null);

    if ($fromMonth === null || $toMonth === null) {
        $latestMonth = $pdo->query("SELECT MAX(sale_month) AS max_month FROM crm_client_monthly_sales")->fetchColumn();
        if ($latestMonth) {
            $latest = new DateTimeImmutable((string)$latestMonth);
            if ($toMonth === null) {
                $toMonth = $latest->format('Y-m-01');
            }
            if ($fromMonth === null) {
                $fromMonth = $latest->modify('-11 months')->format('Y-m-01');
            }
        }
    }

    $filters = [];
    $params = [];
    if ($clientId !== null) {
        $filters[] = 's.client_id = ?';
        $params[] = $clientId;
    }
    if ($query !== '') {
        $filters[] = '(c.name LIKE ? OR c.legal_name_full LIKE ? OR c.legal_name_short LIKE ?)';
        $term = '%' . $query . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
    if ($fromMonth !== null) {
        $filters[] = 's.sale_month >= ?';
        $params[] = $fromMonth;
    }
    if ($toMonth !== null) {
        $filters[] = 's.sale_month <= ?';
        $params[] = $toMonth;
    }

    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

    $monthlyStmt = $pdo->prepare("SELECT s.sale_month, COALESCE(SUM(s.amount), 0) AS amount, COUNT(DISTINCT s.client_id) AS clients_count FROM crm_client_monthly_sales s JOIN crm_clients c ON c.id = s.client_id {$where} GROUP BY s.sale_month ORDER BY s.sale_month ASC");
    $monthlyStmt->execute($params);
    $monthlyTotals = array_map(static function (array $row): array {
        return [
            'sale_month' => $row['sale_month'],
            'amount' => (float)($row['amount'] ?? 0),
            'clients_count' => (int)($row['clients_count'] ?? 0),
        ];
    }, $monthlyStmt->fetchAll());

    $topStmt = $pdo->prepare("SELECT s.client_id, c.name AS client_name, COALESCE(SUM(s.amount), 0) AS total_amount, COUNT(*) AS months_count, MAX(s.sale_month) AS last_sale_month FROM crm_client_monthly_sales s JOIN crm_clients c ON c.id = s.client_id {$where} GROUP BY s.client_id, c.name ORDER BY total_amount DESC, c.name ASC LIMIT 20");
    $topStmt->execute($params);
    $topClients = array_map(static function (array $row): array {
        return [
            'client_id' => (int)$row['client_id'],
            'client_name' => $row['client_name'],
            'total_amount' => (float)($row['total_amount'] ?? 0),
            'months_count' => (int)($row['months_count'] ?? 0),
            'last_sale_month' => $row['last_sale_month'] ?? null,
        ];
    }, $topStmt->fetchAll());

    $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(s.amount), 0) AS total_amount, COUNT(DISTINCT s.client_id) AS clients_count, COUNT(*) AS records_count, MAX(s.sale_month) AS last_sale_month FROM crm_client_monthly_sales s JOIN crm_clients c ON c.id = s.client_id {$where}");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];

    $clientsList = $pdo->query("SELECT c.id, c.name FROM crm_clients c WHERE EXISTS (SELECT 1 FROM crm_client_monthly_sales s WHERE s.client_id = c.id) ORDER BY c.name ASC LIMIT 2000")->fetchAll();
    $availableMonths = $pdo->query("SELECT DISTINCT DATE_FORMAT(sale_month, '%Y-%m-01') AS sale_month FROM crm_client_monthly_sales ORDER BY sale_month DESC")->fetchAll();

    echo json_encode(['success' => true, 'data' => [
        'summary' => [
            'total_amount' => (float)($summary['total_amount'] ?? 0),
            'clients_count' => (int)($summary['clients_count'] ?? 0),
            'records_count' => (int)($summary['records_count'] ?? 0),
            'last_sale_month' => $summary['last_sale_month'] ?? null,
        ],
        'period' => [
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
        ],
        'monthly_totals' => $monthlyTotals,
        'top_clients' => $topClients,
        'clients' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name']], $clientsList),
        'available_months' => array_map(static fn(array $row): string => (string)$row['sale_month'], $availableMonths),
    ]]);
}

function crmNullableString(array $data, string $key, mixed $fallback = null): ?string {
    $value = array_key_exists($key, $data) ? $data[$key] : $fallback;
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function crmClientRequisitesPayload(array $data, ?array $fallback = null): array {
    $fallback = is_array($fallback) ? $fallback : [];

    return [
        'legal_name_full' => crmNullableString($data, 'legal_name_full', $fallback['legal_name_full'] ?? null),
        'legal_name_short' => crmNullableString($data, 'legal_name_short', $fallback['legal_name_short'] ?? null),
        'inn' => crmNullableString($data, 'inn', $fallback['inn'] ?? null),
        'kpp' => crmNullableString($data, 'kpp', $fallback['kpp'] ?? null),
        'ogrn' => crmNullableString($data, 'ogrn', $fallback['ogrn'] ?? null),
        'legal_address' => crmNullableString($data, 'legal_address', $fallback['legal_address'] ?? null),
        'postal_address' => crmNullableString($data, 'postal_address', $fallback['postal_address'] ?? null),
        'signer_name' => crmNullableString($data, 'signer_name', $fallback['signer_name'] ?? null),
        'signer_position' => crmNullableString($data, 'signer_position', $fallback['signer_position'] ?? null),
        'signer_authority' => crmNullableString($data, 'signer_authority', $fallback['signer_authority'] ?? null),
        'bank_name' => crmNullableString($data, 'bank_name', $fallback['bank_name'] ?? null),
        'bik' => crmNullableString($data, 'bik', $fallback['bik'] ?? null),
        'checking_account' => crmNullableString($data, 'checking_account', $fallback['checking_account'] ?? null),
        'correspondent_account' => crmNullableString($data, 'correspondent_account', $fallback['correspondent_account'] ?? null),
    ];
}

function handleCrm(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    crmEnsureClientRequisitesSchema($pdo);
    crmEnsureSalesSchema($pdo);
    crmEnsureClientContactsSchema($pdo);
    crmEnsureReferralSchema($pdo);
    crmEnsureDealsSchema($pdo);

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $action = $action ?? '';
    $subaction = $subaction ?? '';

    if ($method === 'GET' && $action === 'dashboard') {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        crmGetDashboard($pdo);
        exit;
    }

    if ($method === 'GET' && $action === 'sales') {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        crmGetSalesAnalytics($pdo);
        exit;
    }

    if ($method === 'GET' && $action === 'store') {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        crmGetStoreAnalytics($pdo);
        exit;
    }

    if ($method === 'POST' && $action === 'store' && $id === 'import') {
        crmImportWooCommerceOrders($pdo, $currentUser);
        exit;
    }

    if ($method === 'POST' && $action === 'admin-tools') {
        crmHandleAdminTools($pdo, $currentUser);
        exit;
    }

    if ($method === 'GET' && $action === 'search') {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            echo json_encode(['success' => true, 'data' => ['clients' => [], 'deals' => []]]);
            exit;
        }
        $term = '%' . $q . '%';
        $clients = $pdo->prepare("SELECT id, name, legal_name_full, legal_name_short, inn, type, status, email, phone FROM crm_clients WHERE name LIKE ? OR legal_name_full LIKE ? OR legal_name_short LIKE ? OR inn LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY updated_at DESC LIMIT 20");
        $clients->execute([$term, $term, $term, $term, $term, $term]);

        $deals = $pdo->prepare("SELECT d.id, d.title, d.amount, d.currency, d.probability, d.expected_close_date, c.name as client_name FROM crm_deals d JOIN crm_clients c ON c.id=d.client_id WHERE d.deleted_at IS NULL AND (d.title LIKE ? OR c.name LIKE ?) ORDER BY d.updated_at DESC LIMIT 20");
        $deals->execute([$term, $term]);

        echo json_encode(['success' => true, 'data' => ['clients' => $clients->fetchAll(), 'deals' => $deals->fetchAll()]]);
        exit;
    }

    // ===== Clients =====
    if ($action === 'clients' && $method === 'GET' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        crmListClients($pdo);
        exit;
    }

    if ($action === 'clients' && $method === 'POST' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.create')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        crmCreateClient($pdo, $currentUser, $data);
        exit;
    }

    if ($action === 'clients' && is_numeric($id)) {
        $clientId = (int)$id;
        if ($method === 'GET' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetClient($pdo, $clientId);
            exit;
        }
        if ($method === 'POST' && $subaction === 'referral-code') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $payload = json_decode(file_get_contents('php://input'), true);
            $forceRegenerate = !empty($payload['force_regenerate']);
            $code = crmEnsureClientReferralCode($pdo, $clientId, $forceRegenerate);
            $link = crmBuildReferralLink($code);
            echo json_encode(['success' => true, 'data' => [
                'code' => $code,
                'link' => $link,
                'link_ready' => $link !== null,
                'link_message' => crmGetReferralLinkMessage($code, $link),
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($method === 'GET' && $subaction === 'referrals') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => crmGetClientReferralData($pdo, $clientId)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($method === 'GET' && $subaction === 'sales') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => crmGetClientSalesData($pdo, $clientId)]);
            exit;
        }
        if ($method === 'PUT' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            crmUpdateClient($pdo, $currentUser, $clientId, $data);
            exit;
        }
        if ($method === 'DELETE' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmDeleteClient($pdo, $currentUser, $clientId);
            exit;
        }

        if ($subaction === 'contacts') {
            if ($method === 'GET') {
                if (!hasPermission($currentUser, 'crm.view')) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                    exit;
                }
                crmListContacts($pdo, $clientId);
                exit;
            }
            if ($method === 'POST') {
                if (!hasPermission($currentUser, 'crm.edit')) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                    exit;
                }
                $data = json_decode(file_get_contents('php://input'), true);
                crmCreateContact($pdo, $currentUser, $clientId, $data);
                exit;
            }
        }

        if ($subaction === 'activity' && $method === 'GET') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetActivity($pdo, 'client', $clientId);
            exit;
        }

        if ($subaction === 'tasks' && $method === 'GET') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetLinkedTasks($pdo, ['client_id' => $clientId]);
            exit;
        }

        if ($subaction === 'export' && $method === 'GET') {
            if (!hasPermission($currentUser, 'crm.export')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmExportClientsCsv($pdo, $currentUser);
            exit;
        }
    }

    // ===== Pipelines + stages =====
    if ($action === 'pipelines' && $method === 'GET' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $stmt = $pdo->query("SELECT * FROM crm_pipelines ORDER BY is_default DESC, id ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'pipelines' && $method === 'POST' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.stages.manage')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите название воронки']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO crm_pipelines (name, is_default, created_by) VALUES (?, 0, ?)");
        $stmt->execute([$name, $currentUser['id']]);
        $pipelineId = (int)$pdo->lastInsertId();
        crmLog($pdo, 'pipeline', $pipelineId, 'create', $currentUser['id'], "Создана воронка: {$name}");
        auditLog($pdo, 'crm.pipeline.created', [
            'actor' => $currentUser,
            'target_type' => 'crm_pipeline',
            'target_id' => (string)$pipelineId,
            'summary' => 'Создана воронка',
            'details' => [
                'name' => $name,
                'is_default' => false,
            ],
        ]);
        echo json_encode(['success' => true, 'data' => ['id' => $pipelineId]]);
        exit;
    }

    if ($action === 'pipelines' && is_numeric($id) && $subaction === 'stages') {
        $pipelineId = (int)$id;
        if ($method === 'GET') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM crm_pipeline_stages WHERE pipeline_id=? ORDER BY `order` ASC, id ASC");
            $stmt->execute([$pipelineId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }
        if ($method === 'POST') {
            if (!hasPermission($currentUser, 'crm.stages.manage')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите название этапа']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO crm_pipeline_stages (pipeline_id, name, color, `order`, is_won, is_lost) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $pipelineId,
                $name,
                $data['color'] ?? '#3B82F6',
                (int)($data['order'] ?? 0),
                !empty($data['is_won']) ? 1 : 0,
                !empty($data['is_lost']) ? 1 : 0,
            ]);
            $stageId = (int)$pdo->lastInsertId();
            $stageColor = (string)($data['color'] ?? '#3B82F6');
            $stageOrder = (int)($data['order'] ?? 0);
            $stageWon = !empty($data['is_won']) ? 1 : 0;
            $stageLost = !empty($data['is_lost']) ? 1 : 0;
            crmLog($pdo, 'pipeline', $pipelineId, 'stage_create', $currentUser['id'], "Добавлен этап: {$name}");
            auditLog($pdo, 'crm.pipeline_stage.created', [
                'actor' => $currentUser,
                'target_type' => 'crm_pipeline_stage',
                'target_id' => (string)$stageId,
                'summary' => 'Создан этап воронки',
                'details' => [
                    'pipeline_id' => $pipelineId,
                    'name' => $name,
                    'color' => $stageColor,
                    'order' => $stageOrder,
                    'is_won' => $stageWon,
                    'is_lost' => $stageLost,
                ],
            ]);
            echo json_encode(['success' => true, 'data' => ['id' => $stageId]]);
            exit;
        }
    }

    if ($action === 'pipelines' && is_numeric($id) && $subaction === 'stages' && $method === 'PATCH') {
        if (!hasPermission($currentUser, 'crm.stages.manage')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        // supports: PATCH /crm/pipelines/:id/stages  with { action: 'reorder', items: [{id,order}] }
        $pipelineId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);
        $op = (string)($data['action'] ?? '');

        if ($op === 'reorder') {
            $items = $data['items'] ?? [];
            if (!is_array($items)) $items = [];
            $stmt = $pdo->prepare("UPDATE crm_pipeline_stages SET `order`=? WHERE id=? AND pipeline_id=?");
            $auditItems = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                if (!isset($it['id']) || !is_numeric($it['id'])) continue;
                $ord = isset($it['order']) ? (int)$it['order'] : 0;
                $stmt->execute([$ord, (int)$it['id'], $pipelineId]);
                $auditItems[] = [
                    'id' => (int)$it['id'],
                    'order' => $ord,
                ];
            }
            crmLog($pdo, 'pipeline', $pipelineId, 'stage_reorder', (int)$currentUser['id'], 'Изменён порядок этапов');
            auditLog($pdo, 'crm.pipeline_stage.reordered', [
                'actor' => $currentUser,
                'target_type' => 'crm_pipeline',
                'target_id' => (string)$pipelineId,
                'summary' => 'Изменён порядок этапов',
                'details' => [
                    'items' => $auditItems,
                ],
            ]);
            echo json_encode(['success' => true]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неподдерживаемая операция']);
        exit;
    }

    // PUT/DELETE /crm/stages/:id (stage by id)
    if ($action === 'stages' && is_numeric($id)) {
        $stageId = (int)$id;
        if ($method === 'PUT') {
            if (!hasPermission($currentUser, 'crm.stages.manage')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM crm_pipeline_stages WHERE id = ? LIMIT 1");
            $stmt->execute([$stageId]);
            $beforeStage = $stmt->fetch();
            if (!$beforeStage) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Этап не найден']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите название этапа']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE crm_pipeline_stages SET name=?, color=?, `order`=?, is_won=?, is_lost=? WHERE id=?");
            $stmt->execute([
                $name,
                $data['color'] ?? '#3B82F6',
                (int)($data['order'] ?? 0),
                !empty($data['is_won']) ? 1 : 0,
                !empty($data['is_lost']) ? 1 : 0,
                $stageId,
            ]);
            auditLog($pdo, 'crm.pipeline_stage.updated', [
                'actor' => $currentUser,
                'target_type' => 'crm_pipeline_stage',
                'target_id' => (string)$stageId,
                'summary' => 'Обновлён этап воронки',
                'details' => [
                    'before' => $beforeStage,
                    'changes' => [
                        'name' => $name,
                        'color' => $data['color'] ?? '#3B82F6',
                        'order' => (int)($data['order'] ?? 0),
                        'is_won' => !empty($data['is_won']) ? 1 : 0,
                        'is_lost' => !empty($data['is_lost']) ? 1 : 0,
                    ],
                ],
            ]);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($method === 'DELETE') {
            if (!hasPermission($currentUser, 'crm.stages.manage')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM crm_pipeline_stages WHERE id = ? LIMIT 1");
            $stmt->execute([$stageId]);
            $beforeStage = $stmt->fetch();
            if (!$beforeStage) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Этап не найден']);
                exit;
            }
            // Protect: if there are deals in this stage, block
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM crm_deals WHERE stage_id=?");
            $stmt->execute([$stageId]);
            $cnt = (int)($stmt->fetch()['c'] ?? 0);
            if ($cnt > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Нельзя удалить этап: есть сделки в этом этапе']);
                exit;
            }
            $pdo->prepare("DELETE FROM crm_pipeline_stages WHERE id=?")->execute([$stageId]);
            auditLog($pdo, 'crm.pipeline_stage.deleted', [
                'actor' => $currentUser,
                'target_type' => 'crm_pipeline_stage',
                'target_id' => (string)$stageId,
                'summary' => 'Удалён этап воронки',
                'details' => [
                    'before' => $beforeStage,
                ],
            ]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // ===== Deals =====
    if ($action === 'deals' && $method === 'GET' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        crmListDeals($pdo);
        exit;
    }

    if ($action === 'deals' && $method === 'POST' && ($id === null || $id === '')) {
        if (!hasPermission($currentUser, 'crm.create')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        crmCreateDeal($pdo, $currentUser, $data);
        exit;
    }

    if ($action === 'deals' && is_numeric($id)) {
        $dealId = (int)$id;
        if ($method === 'GET' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetDeal($pdo, $dealId);
            exit;
        }
        if ($method === 'PUT' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            crmUpdateDeal($pdo, $currentUser, $dealId, $data);
            exit;
        }
        if ($method === 'DELETE' && ($subaction === null || $subaction === '')) {
            if (!hasPermission($currentUser, 'crm.delete')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmDeleteDeal($pdo, $currentUser, $dealId);
            exit;
        }
        if ($subaction === 'move' && $method === 'PATCH') {
            if (!hasPermission($currentUser, 'crm.edit')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            crmMoveDeal($pdo, $currentUser, $dealId, $data);
            exit;
        }
        if ($subaction === 'tasks' && $method === 'GET') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetLinkedTasks($pdo, ['deal_id' => $dealId]);
            exit;
        }
        if ($subaction === 'activity' && $method === 'GET') {
            if (!hasPermission($currentUser, 'crm.view')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Нет доступа']);
                exit;
            }
            crmGetActivity($pdo, 'deal', $dealId);
            exit;
        }
    }

    if ($action === 'export' && $method === 'GET') {
        if (!hasPermission($currentUser, 'crm.export')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }
        $type = (string)($_GET['type'] ?? 'clients');
        if ($type === 'deals') {
            crmExportDealsCsv($pdo, $currentUser);
        } else {
            crmExportClientsCsv($pdo, $currentUser);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

function crmListClients(PDO $pdo): void {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $tag = trim((string)($_GET['tag'] ?? ''));
    $ownerId = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;
    $segment = trim((string)($_GET['segment'] ?? ''));

    $filters = [];
    $params = [];

    if ($q !== '') {
        $filters[] = '(c.name LIKE ? OR c.legal_name_full LIKE ? OR c.legal_name_short LIKE ? OR c.inn LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.site LIKE ? OR c.address LIKE ? OR c.legal_address LIKE ? OR c.postal_address LIKE ?)';
        $term = '%' . $q . '%';
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term, $term, $term, $term, $term]);
    }
    if ($status !== '') {
        $filters[] = 'c.status = ?';
        $params[] = $status;
    }
    if ($ownerId !== null) {
        $filters[] = 'c.owner_id = ?';
        $params[] = $ownerId;
    }
    if ($tag !== '') {
        $filters[] = 'JSON_CONTAINS(COALESCE(c.tags, JSON_ARRAY()), JSON_QUOTE(?))';
        $params[] = $tag;
    }

    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
    $sql = "
        SELECT c.*, u.full_name as owner_name,
               COALESCE(cs.total_sales, 0) AS total_sales,
               cs.last_sale_month AS last_sale_month,
               COALESCE(cs.sales_records_count, 0) AS sales_records_count,
               COALESCE(cs.sales_records_180_count, 0) AS sales_records_180_count,
               ro.referral_orders_count,
               ro.referral_orders_total,
               rv.referral_visits_count,
               COALESCE(wd.won_deals_count, 0) AS won_deals_count,
               wd.last_won_deal_at AS last_won_deal_at,
               COALESCE(wd.won_deals_180_count, 0) AS won_deals_180_count
        FROM crm_clients c
        LEFT JOIN users u ON u.id = c.owner_id
        LEFT JOIN (
            SELECT client_id,
                   SUM(amount) AS total_sales,
                   MAX(sale_month) AS last_sale_month,
                   COUNT(*) AS sales_records_count,
                   SUM(CASE WHEN sale_month >= DATE_SUB(CURDATE(), INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS sales_records_180_count
            FROM crm_client_monthly_sales
            GROUP BY client_id
        ) cs ON cs.client_id = c.id
        LEFT JOIN (
            SELECT d.client_id,
                   COUNT(*) AS won_deals_count,
                   MAX(d.updated_at) AS last_won_deal_at,
                   SUM(CASE WHEN d.updated_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS won_deals_180_count
            FROM crm_deals d
            JOIN crm_pipeline_stages s ON s.id = d.stage_id
            WHERE s.is_won = 1 AND d.deleted_at IS NULL
            GROUP BY d.client_id
        ) wd ON wd.client_id = c.id
        LEFT JOIN (
            SELECT client_id, COUNT(*) AS referral_orders_count, COALESCE(SUM(total_amount), 0) AS referral_orders_total
            FROM crm_referral_orders
            GROUP BY client_id
        ) ro ON ro.client_id = c.id
        LEFT JOIN (
            SELECT client_id, COUNT(*) AS referral_visits_count
            FROM crm_referral_visits
            GROUP BY client_id
        ) rv ON rv.client_id = c.id
        {$where}
        ORDER BY c.updated_at DESC
        LIMIT 2000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    foreach ($items as &$c) {
        $c['tags'] = $c['tags'] ? json_decode($c['tags'], true) : [];
        $c['custom_fields'] = $c['custom_fields'] ? json_decode($c['custom_fields'], true) : [];
        $c['referral_code'] = trim((string)($c['referral_code'] ?? '')) ?: null;
        $c['referral_orders_count'] = (int)($c['referral_orders_count'] ?? 0);
        $c['referral_orders_total'] = (float)($c['referral_orders_total'] ?? 0);
        $c['referral_visits_count'] = (int)($c['referral_visits_count'] ?? 0);

        $c['sales_records_count'] = (int)($c['sales_records_count'] ?? 0);
        $c['sales_records_180_count'] = (int)($c['sales_records_180_count'] ?? 0);
        $c['won_deals_count'] = (int)($c['won_deals_count'] ?? 0);
        $c['won_deals_180_count'] = (int)($c['won_deals_180_count'] ?? 0);

        $totalOrders = $c['sales_records_count'] + $c['won_deals_count'];
        $orders180 = $c['sales_records_180_count'] + $c['won_deals_180_count'];

        $lastFromSales = !empty($c['last_sale_month']) ? strtotime((string)$c['last_sale_month']) : null;
        $lastFromDeals = !empty($c['last_won_deal_at']) ? strtotime((string)$c['last_won_deal_at']) : null;
        $lastOrderTs = null;
        if ($lastFromSales !== null) $lastOrderTs = $lastFromSales;
        if ($lastFromDeals !== null) $lastOrderTs = $lastOrderTs === null ? $lastFromDeals : max($lastOrderTs, $lastFromDeals);
        $inactiveThresholdTs = strtotime('-180 days');

        $clientSegment = 'never';
        if ($totalOrders > 0) {
            if ($orders180 >= 3) {
                $clientSegment = 'regular';
            } elseif ($lastOrderTs !== null && $lastOrderTs < $inactiveThresholdTs) {
                $clientSegment = 'inactive';
            } else {
                $clientSegment = 'rare';
            }
        }
        $c['segment'] = $clientSegment;
    }

    unset($c);

    if ($segment !== '') {
        $allowed = ['never' => true, 'inactive' => true, 'regular' => true, 'rare' => true];
        if (!isset($allowed[$segment])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный segment'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $items = array_values(array_filter($items, static fn(array $c): bool => (string)($c['segment'] ?? '') === $segment));
    }

    echo json_encode(['success' => true, 'data' => $items]);
}

function crmCreateClient(PDO $pdo, array $currentUser, ?array $data): void {
    $data = is_array($data) ? $data : [];
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите ФИО/название компании']);
        exit;
    }

    $tags = array_values(array_filter(array_map('trim', (array)($data['tags'] ?? [])), fn($v) => $v !== ''));
    $custom = $data['custom_fields'] ?? [];
    if (!is_array($custom)) $custom = [];

    $req = crmClientRequisitesPayload($data);

    $stmt = $pdo->prepare("INSERT INTO crm_clients (name, legal_name_full, legal_name_short, inn, kpp, ogrn, type, email, phone, site, address, legal_address, postal_address, signer_name, signer_position, signer_authority, bank_name, bik, checking_account, correspondent_account, tags, status, notes, custom_fields, created_by, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $name,
        $req['legal_name_full'],
        $req['legal_name_short'],
        $req['inn'],
        $req['kpp'],
        $req['ogrn'],
        in_array(($data['type'] ?? 'person'), ['person', 'company'], true) ? $data['type'] : 'person',
        crmNullableString($data, 'email'),
        crmNullableString($data, 'phone'),
        crmNullableString($data, 'site'),
        crmNullableString($data, 'address'),
        $req['legal_address'],
        $req['postal_address'],
        $req['signer_name'],
        $req['signer_position'],
        $req['signer_authority'],
        $req['bank_name'],
        $req['bik'],
        $req['checking_account'],
        $req['correspondent_account'],
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        $data['status'] ?? 'active',
        crmNullableString($data, 'notes'),
        json_encode($custom, JSON_UNESCAPED_UNICODE),
        $currentUser['id'],
        isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : $currentUser['id'],
    ]);
    $id = (int)$pdo->lastInsertId();
    crmLog($pdo, 'client', $id, 'create', $currentUser['id'], 'Создан клиент', ['name' => $name]);

    $ownerId = isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : (int)$currentUser['id'];
    if ($ownerId > 0 && $ownerId !== (int)$currentUser['id']) {
        createNotification($pdo, [
            'user_id' => $ownerId,
            'sender_id' => (int)$currentUser['id'],
            'message' => 'За вами закреплен новый CRM-клиент: ' . $name,
            'type' => 'crm',
            'related_id' => $id,
        ]);
    }

    echo json_encode(['success' => true, 'data' => ['id' => $id]]);
}

function crmGetClient(PDO $pdo, int $clientId): void {
    $stmt = $pdo->prepare("SELECT c.*, u.full_name as owner_name FROM crm_clients c LEFT JOIN users u ON u.id=c.owner_id WHERE c.id=?");
    $stmt->execute([$clientId]);
    $c = $stmt->fetch();
    if (!$c) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Клиент не найден']);
        exit;
    }
    $c['tags'] = $c['tags'] ? json_decode($c['tags'], true) : [];
    $c['custom_fields'] = $c['custom_fields'] ? json_decode($c['custom_fields'], true) : [];
    $c['documents_profile'] = [
        'display_name' => $c['legal_name_full'] ?: ($c['legal_name_short'] ?: $c['name']),
        'signer_label' => implode(', ', array_filter([$c['signer_name'] ?? '', $c['signer_position'] ?? ''])),
        'bank_details_filled' => count(array_filter([
            $c['bank_name'] ?? null,
            $c['bik'] ?? null,
            $c['checking_account'] ?? null,
            $c['correspondent_account'] ?? null,
        ])),
    ];

    $contacts = $pdo->prepare("SELECT * FROM crm_client_contacts WHERE client_id=? ORDER BY is_primary DESC, id DESC");
    $contacts->execute([$clientId]);

    $deals = $pdo->prepare("SELECT d.*, s.name as stage_name, s.color as stage_color FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.client_id=? AND d.deleted_at IS NULL ORDER BY d.updated_at DESC LIMIT 200");
    $deals->execute([$clientId]);
    
    $sales = crmGetClientSalesData($pdo, $clientId, $c);
    $referrals = crmGetClientReferralData($pdo, $clientId, $c);

    echo json_encode(['success' => true, 'data' => [
        'client' => $c, 
        'contacts' => $contacts->fetchAll(), 
        'deals' => $deals->fetchAll(),
        'sales' => $sales,
        'referrals' => $referrals,
    ]]);
}

function crmUpdateClient(PDO $pdo, array $currentUser, int $clientId, ?array $data): void {
    $data = is_array($data) ? $data : [];
    $stmt = $pdo->prepare("SELECT * FROM crm_clients WHERE id=?");
    $stmt->execute([$clientId]);
    $old = $stmt->fetch();
    if (!$old) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Клиент не найден']);
        exit;
    }

    $name = trim((string)($data['name'] ?? $old['name']));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите ФИО/название компании']);
        exit;
    }
    $tags = $data['tags'] ?? ($old['tags'] ? json_decode($old['tags'], true) : []);
    if (!is_array($tags)) $tags = [];
    $tags = array_values(array_filter(array_map('trim', $tags), fn($v) => $v !== ''));
    $custom = $data['custom_fields'] ?? ($old['custom_fields'] ? json_decode($old['custom_fields'], true) : []);
    if (!is_array($custom)) $custom = [];
    $req = crmClientRequisitesPayload($data, $old);

    $stmt = $pdo->prepare("UPDATE crm_clients SET name=?, legal_name_full=?, legal_name_short=?, inn=?, kpp=?, ogrn=?, type=?, email=?, phone=?, site=?, address=?, legal_address=?, postal_address=?, signer_name=?, signer_position=?, signer_authority=?, bank_name=?, bik=?, checking_account=?, correspondent_account=?, tags=?, status=?, notes=?, custom_fields=?, owner_id=? WHERE id=?");
    $stmt->execute([
        $name,
        $req['legal_name_full'],
        $req['legal_name_short'],
        $req['inn'],
        $req['kpp'],
        $req['ogrn'],
        in_array(($data['type'] ?? $old['type']), ['person', 'company'], true) ? ($data['type'] ?? $old['type']) : 'person',
        crmNullableString($data, 'email', $old['email']),
        crmNullableString($data, 'phone', $old['phone']),
        crmNullableString($data, 'site', $old['site']),
        crmNullableString($data, 'address', $old['address']),
        $req['legal_address'],
        $req['postal_address'],
        $req['signer_name'],
        $req['signer_position'],
        $req['signer_authority'],
        $req['bank_name'],
        $req['bik'],
        $req['checking_account'],
        $req['correspondent_account'],
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        $data['status'] ?? $old['status'],
        crmNullableString($data, 'notes', $old['notes']),
        json_encode($custom, JSON_UNESCAPED_UNICODE),
        isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : ($old['owner_id'] ?? null),
        $clientId,
    ]);
    crmLog($pdo, 'client', $clientId, 'update', $currentUser['id'], 'Обновлён клиент');
    echo json_encode(['success' => true, 'data' => ['id' => $clientId]]);
}

function crmDeleteClient(PDO $pdo, array $currentUser, int $clientId): void {
    crmLog($pdo, 'client', $clientId, 'delete', $currentUser['id'], 'Удалён клиент');
    $stmt = $pdo->prepare("DELETE FROM crm_clients WHERE id=?");
    $stmt->execute([$clientId]);
    echo json_encode(['success' => true]);
}

function crmListContacts(PDO $pdo, int $clientId): void {
    $stmt = $pdo->prepare("SELECT * FROM crm_client_contacts WHERE client_id=? ORDER BY is_primary DESC, id DESC");
    $stmt->execute([$clientId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function crmCreateContact(PDO $pdo, array $currentUser, int $clientId, ?array $data): void {
    $data = is_array($data) ? $data : [];
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите имя контакта']);
        exit;
    }
    $isPrimary = !empty($data['is_primary']) ? 1 : 0;
    if ($isPrimary) {
        $pdo->prepare("UPDATE crm_client_contacts SET is_primary=0 WHERE client_id=?")->execute([$clientId]);
    }
    $stmt = $pdo->prepare("INSERT INTO crm_client_contacts (client_id, name, position, email, phone, is_primary) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$clientId, $name, $data['position'] ?? null, $data['email'] ?? null, $data['phone'] ?? null, $isPrimary]);
    crmLog($pdo, 'client', $clientId, 'contact_create', $currentUser['id'], 'Добавлен контакт', ['contact_name' => $name]);
    echo json_encode(['success' => true, 'data' => ['id' => (int)$pdo->lastInsertId()]]);
}

function crmListDeals(PDO $pdo): void {
    $pipelineId = isset($_GET['pipeline_id']) && is_numeric($_GET['pipeline_id']) ? (int)$_GET['pipeline_id'] : null;
    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $q = trim((string)($_GET['q'] ?? ''));
    $ownerId = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    $filters = [];
    $params = [];
    $filters[] = 'd.deleted_at IS NULL';
    if ($pipelineId !== null) {
        $filters[] = 'd.pipeline_id=?';
        $params[] = $pipelineId;
    }
    if ($clientId !== null) {
        $filters[] = 'd.client_id=?';
        $params[] = $clientId;
    }
    if ($ownerId !== null) {
        $filters[] = 'd.owner_id=?';
        $params[] = $ownerId;
    }
    if ($q !== '') {
        $filters[] = '(d.title LIKE ? OR c.name LIKE ?)';
        $term = '%' . $q . '%';
        $params[] = $term;
        $params[] = $term;
    }
    $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

    $sql = "
        SELECT d.*, c.name as client_name, s.name as stage_name, s.color as stage_color, u.full_name as owner_name
        FROM crm_deals d
        JOIN crm_clients c ON c.id=d.client_id
        JOIN crm_pipeline_stages s ON s.id=d.stage_id
        LEFT JOIN users u ON u.id=d.owner_id
        {$where}
        ORDER BY d.updated_at DESC
        LIMIT 1000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function crmCreateDeal(PDO $pdo, array $currentUser, ?array $data): void {
    $data = is_array($data) ? $data : [];
    if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Нужен client_id']);
        exit;
    }
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите название сделки']);
        exit;
    }

    $pipelineId = isset($data['pipeline_id']) && is_numeric($data['pipeline_id']) ? (int)$data['pipeline_id'] : 1;
    $stageId = isset($data['stage_id']) && is_numeric($data['stage_id']) ? (int)$data['stage_id'] : null;

    if ($stageId === null) {
        $stmt = $pdo->prepare("SELECT id FROM crm_pipeline_stages WHERE pipeline_id=? ORDER BY `order` ASC, id ASC LIMIT 1");
        $stmt->execute([$pipelineId]);
        $row = $stmt->fetch();
        $stageId = $row ? (int)$row['id'] : 0;
    }
    if (!$stageId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не найден этап воронки']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO crm_deals (client_id, pipeline_id, stage_id, title, amount, currency, probability, expected_close_date, owner_id, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int)$data['client_id'],
        $pipelineId,
        $stageId,
        $title,
        (float)($data['amount'] ?? 0),
        $data['currency'] ?? 'RUB',
        (int)($data['probability'] ?? 0),
        $data['expected_close_date'] ?? null,
        isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : $currentUser['id'],
        $data['description'] ?? null,
        $currentUser['id'],
    ]);
    $id = (int)$pdo->lastInsertId();
    crmLog($pdo, 'deal', $id, 'create', $currentUser['id'], 'Создана сделка', ['title' => $title]);

    $ownerId = isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : (int)$currentUser['id'];
    if ($ownerId > 0 && $ownerId !== (int)$currentUser['id']) {
        createNotification($pdo, [
            'user_id' => $ownerId,
            'sender_id' => (int)$currentUser['id'],
            'message' => 'За вами закреплена новая CRM-сделка: ' . $title,
            'type' => 'crm',
            'related_id' => $id,
        ]);
    }

    echo json_encode(['success' => true, 'data' => ['id' => $id]]);
}

function crmGetDeal(PDO $pdo, int $dealId): void {
    $stmt = $pdo->prepare("SELECT d.*, c.name as client_name, s.name as stage_name, s.color as stage_color, p.name as pipeline_name, u.full_name as owner_name FROM crm_deals d JOIN crm_clients c ON c.id=d.client_id JOIN crm_pipeline_stages s ON s.id=d.stage_id JOIN crm_pipelines p ON p.id=d.pipeline_id LEFT JOIN users u ON u.id=d.owner_id WHERE d.id=? AND d.deleted_at IS NULL");
    $stmt->execute([$dealId]);
    $deal = $stmt->fetch();
    if (!$deal) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Сделка не найдена']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $deal]);
}

function crmUpdateDeal(PDO $pdo, array $currentUser, int $dealId, ?array $data): void {
    $data = is_array($data) ? $data : [];
    $stmt = $pdo->prepare("SELECT * FROM crm_deals WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$dealId]);
    $old = $stmt->fetch();
    if (!$old) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Сделка не найдена']);
        exit;
    }
    $title = trim((string)($data['title'] ?? $old['title']));
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите название сделки']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE crm_deals SET title=?, amount=?, currency=?, probability=?, expected_close_date=?, owner_id=?, description=? WHERE id=?");
    $stmt->execute([
        $title,
        (float)($data['amount'] ?? $old['amount']),
        $data['currency'] ?? $old['currency'],
        (int)($data['probability'] ?? $old['probability']),
        $data['expected_close_date'] ?? $old['expected_close_date'],
        isset($data['owner_id']) && is_numeric($data['owner_id']) ? (int)$data['owner_id'] : ($old['owner_id'] ?? null),
        $data['description'] ?? $old['description'],
        $dealId,
    ]);
    crmLog($pdo, 'deal', $dealId, 'update', $currentUser['id'], 'Обновлена сделка');
    echo json_encode(['success' => true]);
}

function crmMoveDeal(PDO $pdo, array $currentUser, int $dealId, ?array $data): void {
    $data = is_array($data) ? $data : [];
    if (empty($data['stage_id']) || !is_numeric($data['stage_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Нужен stage_id']);
        exit;
    }
    $newStageId = (int)$data['stage_id'];

    $stmt = $pdo->prepare("SELECT d.id, d.client_id, d.amount, d.currency, d.stage_id, d.pipeline_id, d.updated_at, d.won_recorded_at, d.won_recorded_month, s.name as stage_name, s.is_won as stage_is_won FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.id=? AND d.deleted_at IS NULL");
    $stmt->execute([$dealId]);
    $old = $stmt->fetch();
    if (!$old) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Сделка не найдена']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT name, is_won FROM crm_pipeline_stages WHERE id=? AND pipeline_id=?");
    $stmt->execute([$newStageId, (int)$old['pipeline_id']]);
    $newStage = $stmt->fetch();
    if (!$newStage) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Этап не найден в этой воронке']);
        exit;
    }

    $pdo->prepare("UPDATE crm_deals SET stage_id=? WHERE id=?")->execute([$newStageId, $dealId]);
    crmLog($pdo, 'deal', $dealId, 'stage_move', $currentUser['id'], 'Перемещение по этапам', ['from' => (int)$old['stage_id'], 'to' => $newStageId]);

    // If moved into won stage for the first time - record into monthly sales.
    $oldWasWon = !empty($old['stage_is_won']);
    $newIsWon = !empty($newStage['is_won']);
    if (!$oldWasWon && $newIsWon) {
        crmRecordWonDealSale($pdo, $old, $currentUser);
    }

    echo json_encode(['success' => true]);
}

function crmDeleteDeal(PDO $pdo, array $currentUser, int $dealId): void {
    $stmt = $pdo->prepare("SELECT id FROM crm_deals WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$dealId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Сделка не найдена']);
        exit;
    }

    crmLog($pdo, 'deal', $dealId, 'archive', $currentUser['id'], 'Сделка удалена из списка (архив)');
    $pdo->prepare("UPDATE crm_deals SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL")->execute([$dealId]);
    echo json_encode(['success' => true]);
}

function crmGetLinkedTasks(PDO $pdo, array $filter): void {
    $where = [];
    $params = [];
    if (isset($filter['client_id'])) {
        $where[] = 't.client_id=?';
        $params[] = (int)$filter['client_id'];
    }
    if (isset($filter['deal_id'])) {
        $where[] = 't.deal_id=?';
        $params[] = (int)$filter['deal_id'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT t.*, p.name as project_name, u.full_name as assignee_name, ts.color as status_color FROM tasks t LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN task_stages ts ON ts.name=t.status {$whereSql} ORDER BY t.created_at DESC LIMIT 200");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function crmGetActivity(PDO $pdo, string $entityType, int $entityId): void {
    $stmt = $pdo->prepare("SELECT a.*, u.full_name as user_name, u.avatar as user_avatar FROM crm_activity a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity_type=? AND a.entity_id=? ORDER BY a.created_at DESC LIMIT 100");
    $stmt->execute([$entityType, $entityId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
}

function crmGetDashboard(PDO $pdo): void {
    $clientsCount = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_clients")->fetch()['c'];
    $activeDeals = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals WHERE deleted_at IS NULL")->fetch()['c'];

    $sumPipeline = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) as s FROM crm_deals WHERE deleted_at IS NULL")->fetch()['s'];

    $won = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.deleted_at IS NULL AND s.is_won=1")->fetch()['c'];
    $lost = (int)$pdo->query("SELECT COUNT(*) as c FROM crm_deals d JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.deleted_at IS NULL AND s.is_lost=1")->fetch()['c'];
    $closed = $won + $lost;
    $winRate = $closed > 0 ? round(($won / $closed) * 100, 1) : 0;

    $byStageStmt = $pdo->query("SELECT s.id as stage_id, s.name, s.color, s.`order`, COUNT(d.id) as deals_count, COALESCE(SUM(d.amount),0) as amount_sum FROM crm_pipeline_stages s LEFT JOIN crm_deals d ON d.stage_id=s.id AND d.deleted_at IS NULL WHERE s.pipeline_id=1 GROUP BY s.id ORDER BY s.`order` ASC");
    $byStage = $byStageStmt->fetchAll();

    echo json_encode(['success' => true, 'data' => [
        'clients' => $clientsCount,
        'active_deals' => $activeDeals,
        'pipeline_sum' => $sumPipeline,
        'win_rate' => $winRate,
        'by_stage' => $byStage,
    ]]);
}

function crmExportClientsCsv(PDO $pdo, array $currentUser): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['id', 'name', 'type', 'status', 'email', 'phone', 'site', 'address', 'tags', 'notes', 'owner_id', 'created_at']);

    $stmt = $pdo->query("SELECT * FROM crm_clients ORDER BY id ASC");
    $rowsExported = 0;
    while ($r = $stmt->fetch()) {
        $tags = $r['tags'] ? implode(';', (array)json_decode($r['tags'], true)) : '';
        fputcsv($out, [$r['id'], $r['name'], $r['type'], $r['status'], $r['email'], $r['phone'], $r['site'], $r['address'], $tags, $r['notes'], $r['owner_id'], $r['created_at']]);
        $rowsExported++;
    }

    auditLog($pdo, 'crm.export.clients_csv', [
        'actor' => $currentUser,
        'target_type' => 'crm_export',
        'target_id' => 'clients',
        'summary' => 'Экспорт клиентов CSV',
        'details' => [
            'rows_exported' => $rowsExported,
        ],
    ]);

    fclose($out);
}

function crmExportDealsCsv(PDO $pdo, array $currentUser): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deals.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['id', 'title', 'client_id', 'client_name', 'pipeline_id', 'stage_id', 'stage_name', 'amount', 'currency', 'probability', 'expected_close_date', 'owner_id', 'created_at']);

    $stmt = $pdo->query("SELECT d.*, c.name as client_name, s.name as stage_name FROM crm_deals d JOIN crm_clients c ON c.id=d.client_id JOIN crm_pipeline_stages s ON s.id=d.stage_id WHERE d.deleted_at IS NULL ORDER BY d.id ASC");
    $rowsExported = 0;
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['id'], $r['title'], $r['client_id'], $r['client_name'], $r['pipeline_id'], $r['stage_id'], $r['stage_name'], $r['amount'], $r['currency'], $r['probability'], $r['expected_close_date'], $r['owner_id'], $r['created_at']]);
        $rowsExported++;
    }

    auditLog($pdo, 'crm.export.deals_csv', [
        'actor' => $currentUser,
        'target_type' => 'crm_export',
        'target_id' => 'deals',
        'summary' => 'Экспорт сделок CSV',
        'details' => [
            'rows_exported' => $rowsExported,
        ],
    ]);

    fclose($out);
}

function crmLog(PDO $pdo, string $entityType, int $entityId, string $action, ?int $userId, ?string $message, ?array $meta = null): void {
    // crm_activity currently supports entity_type client/deal/task.
    // We ignore unknown types silently.
    if (!in_array($entityType, ['client', 'deal', 'task'], true)) return;
    $stmt = $pdo->prepare("INSERT INTO crm_activity (entity_type, entity_id, action, message, user_id, meta) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $entityType,
        $entityId,
        $action,
        $message,
        $userId,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/**
 * Подэтапы сделок CRM - СПРАВОЧНИК
 */

// GET /api/crm-deal-substages - получить справочник подэтапов
function getCrmDealSubstagesDict() {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM crm_deal_substages ORDER BY `order` ASC");
    $stmt->execute();
    $substages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $substages]);
}

// POST /api/crm-deal-substages - добавить подэтап в справочник
function createCrmDealSubstageDict($data) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }
    
    if (empty($data['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Укажите название подэтапа']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) as max_order FROM crm_deal_substages");
    $stmt->execute();
    $maxOrder = $stmt->fetchColumn();
    $newOrder = ((int)$maxOrder) + 1;
    $color = (string)($data['color'] ?? '#6B7280');
    
    $stmt = $pdo->prepare("
        INSERT INTO crm_deal_substages (name, color, `order`)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$data['name'], $color, $newOrder]);

    $newId = (int)$pdo->lastInsertId();
    auditLog($pdo, 'crm.deal_substage.created', [
        'actor' => $currentUser,
        'target_type' => 'crm_deal_substage',
        'target_id' => (string)$newId,
        'summary' => 'Создан подэтап сделки',
        'details' => [
            'name' => $data['name'],
            'color' => $color,
            'order' => $newOrder,
        ],
    ]);
    
    echo json_encode([
        'success' => true,
        'data' => ['id' => $newId, 'name' => $data['name']]
    ]);
}

// PUT /api/crm-deal-substages/:id - обновить подэтап
function updateCrmDealSubstageDict($id, $data) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM crm_deal_substages WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    $beforeSubstage = $stmt->fetch();
    if (!$beforeSubstage) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Подэтап не найден']);
        exit;
    }
    
    $updates = [];
    $params = [];
    $changes = [];
    
    if (isset($data['name'])) { $updates[] = "name = ?"; $params[] = $data['name']; $changes['name'] = $data['name']; }
    if (isset($data['color'])) { $updates[] = "color = ?"; $params[] = $data['color']; $changes['color'] = $data['color']; }
    if (isset($data['order'])) { $updates[] = "`order` = ?"; $params[] = (int)$data['order']; $changes['order'] = (int)$data['order']; }
    
    if (!empty($updates)) {
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE crm_deal_substages SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        auditLog($pdo, 'crm.deal_substage.updated', [
            'actor' => $currentUser,
            'target_type' => 'crm_deal_substage',
            'target_id' => (string)$id,
            'summary' => 'Обновлён подэтап сделки',
            'details' => [
                'before' => $beforeSubstage,
                'changes' => $changes,
            ],
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Подэтап обновлён']);
}

// DELETE /api/crm-deal-substages/:id - удалить подэтап
function deleteCrmDealSubstageDict($id) {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser || !hasPermission($currentUser, 'admin.full')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Только администраторы']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM crm_deal_substages WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    $beforeSubstage = $stmt->fetch();
    if (!$beforeSubstage) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Подэтап не найден']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM crm_deal_substages WHERE id = ?");
    $stmt->execute([$id]);

    auditLog($pdo, 'crm.deal_substage.deleted', [
        'actor' => $currentUser,
        'target_type' => 'crm_deal_substage',
        'target_id' => (string)$id,
        'summary' => 'Удалён подэтап сделки',
        'details' => [
            'before' => $beforeSubstage,
        ],
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Подэтап удалён']);
}
