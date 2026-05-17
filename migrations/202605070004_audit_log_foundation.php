<?php

return [
    'id' => '202605070004',
    'name' => 'audit_log_foundation',
    'description' => 'Create audit_log table for minimum viable audit trail foundation.',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            actor_user_id INT NULL,
            actor_login VARCHAR(190) NULL,
            actor_role VARCHAR(100) NULL,
            target_type VARCHAR(100) NULL,
            target_id VARCHAR(100) NULL,
            summary VARCHAR(255) NOT NULL,
            details_json LONGTEXT NULL,
            request_method VARCHAR(10) NULL,
            request_path VARCHAR(255) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_created_at (created_at),
            KEY idx_audit_event_type (event_type),
            KEY idx_audit_actor_user_id (actor_user_id),
            KEY idx_audit_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];

