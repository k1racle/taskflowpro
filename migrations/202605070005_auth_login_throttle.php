<?php

return [
    'id' => '202605070005',
    'name' => 'auth_login_throttle',
    'description' => 'Create auth_login_throttle table for minimum viable login abuse protection.',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_throttle (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            login_key VARCHAR(190) NOT NULL,
            login_value VARCHAR(190) NULL,
            ip_address VARCHAR(64) NOT NULL,
            failed_attempts INT NOT NULL DEFAULT 0,
            first_failed_at TIMESTAMP NULL DEFAULT NULL,
            last_failed_at TIMESTAMP NULL DEFAULT NULL,
            lock_expires_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_auth_login_throttle_scope (login_key, ip_address),
            KEY idx_auth_login_throttle_lock_expires_at (lock_expires_at),
            KEY idx_auth_login_throttle_last_failed_at (last_failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];
