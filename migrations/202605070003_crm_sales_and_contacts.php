<?php

return [
    'id' => '202605070003',
    'name' => 'crm_sales_and_contacts',
    'description' => 'Create crm_client_monthly_sales and crm_client_contacts tables.',
    'up' => static function (PDO $pdo): void {
        if (!appTableExists($pdo, 'crm_clients')) {
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
    },
];

