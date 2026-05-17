<?php

return [
    'id' => '202605070006',
    'name' => 'crm_referrals',
    'description' => 'Add referral code to crm_clients and create referral orders/visits tables.',
    'up' => static function (PDO $pdo): void {
        if (!appTableExists($pdo, 'crm_clients')) {
            return;
        }

        $existingColumns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM crm_clients') as $column) {
            $existingColumns[] = (string)($column['Field'] ?? '');
        }

        if (!in_array('referral_code', $existingColumns, true)) {
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
    },
];

