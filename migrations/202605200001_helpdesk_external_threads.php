<?php

return [
    'id' => '202605200001',
    'name' => 'helpdesk_external_threads',
    'description' => 'Add mapping between HelpDesk tickets and external messenger threads (Telegram/MAX).',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS helpdesk_external_threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(24) NOT NULL,
                external_chat_id VARCHAR(128) NOT NULL,
                external_user_id VARCHAR(128) NULL,
                ticket_id INT NOT NULL,
                last_external_message_id VARCHAR(128) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_channel_chat (channel, external_chat_id),
                INDEX idx_ticket (ticket_id),
                CONSTRAINT fk_helpdesk_external_threads_ticket
                    FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];

