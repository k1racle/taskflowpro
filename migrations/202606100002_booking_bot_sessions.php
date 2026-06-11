<?php

return [
    'id' => '202606100002',
    'name' => 'booking_bot_sessions',
    'description' => 'Create booking bot sessions and logs tables for Telegram chat bot.',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_bot_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(16) NOT NULL DEFAULT 'telegram',
                external_chat_id VARCHAR(128) NOT NULL,
                step VARCHAR(32) NOT NULL DEFAULT 'idle' COMMENT 'idle, services, date, time, contacts, confirm',
                data_json LONGTEXT NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_channel_chat (channel, external_chat_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_bot_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NULL,
                channel VARCHAR(16) NOT NULL DEFAULT 'telegram',
                external_chat_id VARCHAR(128) NOT NULL,
                direction VARCHAR(8) NOT NULL COMMENT 'in, out',
                message TEXT NULL,
                step VARCHAR(32) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_chat (channel, external_chat_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Settings for booking bot
        $settings = [
            ['booking_bot_telegram_enabled', '0'],
            ['booking_bot_telegram_token', ''],
            ['booking_bot_welcome_text', 'Здравствуйте! Я бот для записи на услуги. Напишите /book чтобы начать.'],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?, ?)");
        foreach ($settings as $s) {
            $stmt->execute($s);
        }
    },
];
