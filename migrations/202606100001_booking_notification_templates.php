<?php

return [
    'id' => '202606100001',
    'name' => 'booking_notification_templates',
    'description' => 'Create notification templates, logs, and booking reminders tables for the booking module.',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Шаблоны уведомлений
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notification_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event VARCHAR(64) NOT NULL COMMENT 'booking.created, booking.confirmed, booking.rejected, booking.reminder_24h, booking.reminder_1h',
                channel VARCHAR(16) NOT NULL COMMENT 'email, telegram, sms, internal',
                subject VARCHAR(255) NULL,
                body_html TEXT NULL,
                body_text TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_event_channel (event, channel),
                INDEX idx_event (event),
                INDEX idx_channel (channel),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Логи отправки уведомлений
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notification_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NULL,
                event VARCHAR(64) NOT NULL,
                channel VARCHAR(16) NOT NULL,
                recipient_type VARCHAR(32) NOT NULL COMMENT 'client, admin, manager, employee',
                recipient_id INT NULL,
                recipient_address VARCHAR(255) NULL COMMENT 'email, phone, chat_id',
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, failed',
                error_message TEXT NULL,
                sent_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event (event),
                INDEX idx_channel (channel),
                INDEX idx_status (status),
                INDEX idx_recipient (recipient_type, recipient_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Напоминания по заявкам
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_request_reminders (
                request_id INT NOT NULL PRIMARY KEY,
                reminder_24h_sent TINYINT(1) NOT NULL DEFAULT 0,
                reminder_1h_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_booking_request_reminders_request
                    FOREIGN KEY (request_id) REFERENCES booking_requests(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Расширение site_widget_profiles для booking-виджетов
        try {
            $pdo->exec("ALTER TABLE site_widget_profiles ADD COLUMN type VARCHAR(16) NOT NULL DEFAULT 'chat' AFTER slug");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE site_widget_profiles ADD COLUMN allowed_services_json LONGTEXT NULL AFTER type");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE site_widget_profiles ADD COLUMN custom_css_url VARCHAR(500) NULL AFTER allowed_services_json");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        // Добавляем source к booking_requests
        try {
            $pdo->exec("ALTER TABLE booking_requests ADD COLUMN source VARCHAR(32) NULL AFTER status");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE booking_requests ADD COLUMN page_url VARCHAR(1000) NULL AFTER source");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE booking_requests ADD COLUMN widget_profile_id INT NULL AFTER page_url");
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
                throw $e;
            }
        }

        // Таблица аналитики виджетов
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_widget_analytics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                widget_profile_id INT NULL,
                event VARCHAR(32) NOT NULL,
                page_url VARCHAR(1000) NULL,
                page_title VARCHAR(500) NULL,
                referrer VARCHAR(1000) NULL,
                user_agent_hash VARCHAR(64) NULL,
                session_id VARCHAR(64) NULL,
                ip_hash VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_widget_event (widget_profile_id, event),
                INDEX idx_widget_analytics_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default notification templates
        $templates = [
            [
                'event' => 'booking.created',
                'channel' => 'internal',
                'subject' => 'Новая заявка на запись',
                'body_html' => '<p>Новая заявка <strong>#{{request_number}}</strong> от {{client_name}}.</p><p>Услуги: {{services}}</p><p>Дата: {{datetime}}</p>',
                'body_text' => 'Новая заявка #{{request_number}} от {{client_name}}. Услуги: {{services}}. Дата: {{datetime}}.',
            ],
            [
                'event' => 'booking.created',
                'channel' => 'email',
                'subject' => 'Ваша заявка #{{request_number}} принята',
                'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>Ваша заявка на запись <strong>#{{request_number}}</strong> принята и ожидает подтверждения.</p><p>Услуги: {{services}}</p><p>Дата и время: {{datetime}}</p><p>Сумма: {{total_price}}</p>',
                'body_text' => 'Здравствуйте, {{client_name}}! Ваша заявка на запись #{{request_number}} принята и ожидает подтверждения. Услуги: {{services}}. Дата и время: {{datetime}}. Сумма: {{total_price}}.',
            ],
            [
                'event' => 'booking.created',
                'channel' => 'telegram',
                'subject' => null,
                'body_html' => null,
                'body_text' => "🔔 <b>Новая заявка на запись</b>\n\nНомер: #{{request_number}}\nКлиент: {{client_name}}\nТелефон: {{client_phone}}\nУслуги: {{services}}\nДата: {{datetime}}\nСумма: {{total_price}}",
            ],
            [
                'event' => 'booking.confirmed',
                'channel' => 'email',
                'subject' => 'Ваша запись #{{request_number}} подтверждена',
                'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>Ваша запись <strong>#{{request_number}}</strong> подтверждена.</p><p>Услуги: {{services}}</p><p>Дата и время: {{datetime}}</p><p>Сумма: {{total_price}}</p><p>Ждем вас!</p>',
                'body_text' => 'Здравствуйте, {{client_name}}! Ваша запись #{{request_number}} подтверждена. Услуги: {{services}}. Дата и время: {{datetime}}. Сумма: {{total_price}}. Ждем вас!',
            ],
            [
                'event' => 'booking.confirmed',
                'channel' => 'internal',
                'subject' => 'Заявка подтверждена',
                'body_html' => '<p>Заявка <strong>#{{request_number}}</strong> подтверждена.</p>',
                'body_text' => 'Заявка #{{request_number}} подтверждена.',
            ],
            [
                'event' => 'booking.rejected',
                'channel' => 'email',
                'subject' => 'Ваша заявка #{{request_number}} отклонена',
                'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>К сожалению, ваша заявка <strong>#{{request_number}}</strong> не может быть выполнена.</p><p>Комментарий: {{admin_comment}}</p>',
                'body_text' => 'Здравствуйте, {{client_name}}! К сожалению, ваша заявка #{{request_number}} не может быть выполнена. Комментарий: {{admin_comment}}.',
            ],
            [
                'event' => 'booking.reminder_24h',
                'channel' => 'email',
                'subject' => 'Напоминание: запись #{{request_number}} завтра',
                'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>Напоминаем, что завтра у вас запись <strong>#{{request_number}}</strong>.</p><p>Услуги: {{services}}</p><p>Дата и время: {{datetime}}</p><p>Сумма: {{total_price}}</p>',
                'body_text' => 'Здравствуйте, {{client_name}}! Напоминаем, что завтра у вас запись #{{request_number}}. Услуги: {{services}}. Дата и время: {{datetime}}. Сумма: {{total_price}}.',
            ],
            [
                'event' => 'booking.reminder_1h',
                'channel' => 'email',
                'subject' => 'Напоминание: запись #{{request_number}} через час',
                'body_html' => '<p>Здравствуйте, {{client_name}}!</p><p>Напоминаем, что через час у вас запись <strong>#{{request_number}}</strong>.</p><p>Услуги: {{services}}</p><p>Дата и время: {{datetime}}</p>',
                'body_text' => 'Здравствуйте, {{client_name}}! Напоминаем, что через час у вас запись #{{request_number}}. Услуги: {{services}}. Дата и время: {{datetime}}.',
            ],
        ];

        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO notification_templates
                (event, channel, subject, body_html, body_text, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, 1, ?)
        ");

        foreach ($templates as $index => $template) {
            $insertStmt->execute([
                $template['event'],
                $template['channel'],
                $template['subject'],
                $template['body_html'],
                $template['body_text'],
                $index,
            ]);
        }
    },
];
