<?php

return [
    'id' => '202606100003',
    'name' => 'license_tiers',
    'description' => 'License tier settings and commercial request table.',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS license_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                company VARCHAR(255) NOT NULL,
                tier_requested VARCHAR(32) NULL,
                message TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $settings = [
            ['license_tier', 'free'],
            ['license_expires_at', ''],
            ['license_support_priority', 'low'],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?, ?)");
        foreach ($settings as $s) {
            $stmt->execute($s);
        }
    },
];
