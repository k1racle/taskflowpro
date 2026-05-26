<?php
/**
 * install.php - Полная установка TaskFlow Pro
 * 
 * Создаёт все таблицы включая систему ролей и прав доступа
 */

// Отключаем вывод ошибок (логируем в файл)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/api/security.php';
require_once __DIR__ . '/api/migrations.php';
require_once __DIR__ . '/api/booking-schema.php';

appSecurityEnsureInstallerAvailable();

$step = $_GET['step'] ?? 'form';
$message = '';
$messageType = '';

function checkEnvironment(): array {
    $checks = [];

    $checks[] = [
        'name' => 'PHP imap extension',
        'ok' => function_exists('imap_open'),
        'hint' => 'Нужен для входящей почты и сохранения копий в Sent (IMAP APPEND).',
    ];

    $checks[] = [
        'name' => 'PHP OpenSSL extension',
        'ok' => function_exists('openssl_encrypt') && function_exists('openssl_decrypt'),
        'hint' => 'Нужен для шифрования паролей почты и TLS.',
    ];

    $checks[] = [
        'name' => 'PHP fileinfo extension',
        'ok' => function_exists('finfo_open'),
        'hint' => 'Желательно для корректного определения MIME типов вложений.',
    ];

    $uploadsDir = __DIR__ . '/uploads';
    $mailUploadsDir = __DIR__ . '/uploads/mail';
    $documentsUploadsDir = __DIR__ . '/uploads/documents';
    $documentsPackagesDir = $documentsUploadsDir . '/packages';
    $runtimeDir = __DIR__ . '/runtime';
    $apiLogsDir = __DIR__ . '/api/logs';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }
    if (!is_dir($mailUploadsDir)) {
        @mkdir($mailUploadsDir, 0755, true);
    }
    if (!is_dir($documentsUploadsDir)) {
        @mkdir($documentsUploadsDir, 0755, true);
    }
    if (!is_dir($documentsPackagesDir)) {
        @mkdir($documentsPackagesDir, 0755, true);
    }
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
    }
    if (!is_dir($apiLogsDir)) {
        @mkdir($apiLogsDir, 0755, true);
    }

    $checks[] = [
        'name' => 'uploads/ writable',
        'ok' => is_dir($uploadsDir) && is_writable($uploadsDir),
        'hint' => 'Нужны права на запись для аватаров/файлов/почтовых вложений.',
    ];

    $checks[] = [
        'name' => 'uploads/mail/ writable',
        'ok' => is_dir($mailUploadsDir) && is_writable($mailUploadsDir),
        'hint' => 'Нужны права на запись для вложений почты.',
    ];

    $checks[] = [
        'name' => 'uploads/documents/ writable',
        'ok' => is_dir($documentsUploadsDir) && is_writable($documentsUploadsDir),
        'hint' => 'Нужны права на запись для сгенерированных документов и ZIP-пакетов.',
    ];

    $checks[] = [
        'name' => 'uploads/documents/packages/ writable',
        'ok' => is_dir($documentsPackagesDir) && is_writable($documentsPackagesDir),
        'hint' => 'Нужны права на запись для пакетной генерации документов.',
    ];

    $checks[] = [
        'name' => 'runtime/ writable',
        'ok' => is_dir($runtimeDir) && is_writable($runtimeDir),
        'hint' => 'Нужен для bootstrap lock и временных runtime-данных.',
    ];

    $checks[] = [
        'name' => 'api/logs/ writable',
        'ok' => is_dir($apiLogsDir) && is_writable($apiLogsDir),
        'hint' => 'Нужен для логов API.',
    ];

    $checks[] = [
        'name' => 'docker entrypoint ready',
        'ok' => is_file(__DIR__ . '/docker-entrypoint.sh') || is_file(__DIR__ . '/docker-entrypoint.sh'),
        'hint' => 'Нужен для коробочного контейнерного деплоя.',
    ];

    $checks[] = [
        'name' => 'admin health endpoint',
        'ok' => is_file(__DIR__ . '/api/index.php') && is_file(__DIR__ . '/api/health.php'),
        'hint' => 'Нужен для диагностики готовности и support-check.',
    ];

    return $checks;
}

$envChecks = checkEnvironment();
$hasEnvErrors = false;
foreach ($envChecks as $c) {
    if (!$c['ok']) {
        $hasEnvErrors = true;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'install') {
    if ($hasEnvErrors) {
        $message = 'Ошибка окружения: не выполнены требования (imap/openssl/права на uploads). Исправьте и повторите.';
        $messageType = 'error';
        $step = 'form';
    }

    $host = trim($_POST['host'] ?? 'db');
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    $dbname = trim($_POST['dbname'] ?? 'taskflow');
    $rootPass = $_POST['root_pass'] ?? '';
    $licenseDomain = trim($_POST['license_domain'] ?? '');

    if (empty($user)) {
        $message = 'Ошибка: укажите имя пользователя MySQL';
        $messageType = 'error';
        $step = 'form';
    } elseif (empty($rootPass)) {
        $message = 'Ошибка: укажите пароль для root';
        $messageType = 'error';
        $step = 'form';
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Создаём базу данных
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                // Игнорируем ошибку
            }
            $pdo->exec("USE `$dbname`");
            
            // Создаём все таблицы
            createTables($pdo);

            // Создаём root пользователя с паролем
            createAdmin($pdo, $rootPass);

            // Добавляем данные
            seedData($pdo);

            // Фиксируем актуальные applied migrations и доводим схему
            appApplyMigrations($pdo);
            
            // Создаём bootstrap lock вместо перезаписи файлов репозитория.
            createBootstrapLock($host, $user, $pass, $dbname, $licenseDomain);
            
            $message = 'Установка успешно завершена!';
            $messageType = 'success';
            $step = 'success';
            
        } catch (Throwable $e) {
            $message = 'Ошибка установки: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
            $step = 'form';
        }
    }
}

function createTables(PDO $pdo): void {
    // Ensure proper encoding for emoji everywhere
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // ignore
    }

    // Основные таблицы
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(100) UNIQUE NOT NULL,
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255),
            role VARCHAR(50) DEFAULT 'employee',
            department_id INT,
            phone VARCHAR(50),
            avatar VARCHAR(255),
            bio TEXT,
            birthday DATE NULL COMMENT 'День рождения сотрудника',
            weather_city VARCHAR(100) DEFAULT 'Москва' COMMENT 'Город для виджета погоды',
            notification_settings JSON COMMENT 'Настройки уведомлений (email, telegram)',
            customer_segment ENUM('lead', 'regular', 'vip', 'blacklist') DEFAULT 'regular' COMMENT 'Сегмент клиента',
            blacklisted_at TIMESTAMP NULL COMMENT 'Дата добавления в черный список',
            blacklist_reason TEXT COMMENT 'Причина черного списка',
            vip_discount INT DEFAULT 0 COMMENT 'VIP скидка в процентах',
            passport_series VARCHAR(10) COMMENT 'Серия паспорта',
            passport_number VARCHAR(10) COMMENT 'Номер паспорта',
            passport_issued_by VARCHAR(255) COMMENT 'Кем выдан паспорт',
            passport_issue_date DATE COMMENT 'Дата выдачи паспорта',
            registration_address TEXT COMMENT 'Адрес регистрации',
            company_name VARCHAR(255) COMMENT 'Название компании',
            company_inn VARCHAR(20) COMMENT 'ИНН компании',
            need_documents TINYINT DEFAULT 0 COMMENT 'Нужны документы',
            communication_preference ENUM('email', 'phone', 'telegram', 'whatsapp') DEFAULT 'phone' COMMENT 'Предпочтительный способ связи',
            consent_to_data TINYINT DEFAULT 0 COMMENT 'Согласие на обработку данных',
            consent_to_marketing TINYINT DEFAULT 0 COMMENT 'Согласие на маркетинг',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            last_activity TIMESTAMP NULL,
            INDEX idx_login (login),
            INDEX idx_email (email),
            INDEX idx_department (department_id),
            INDEX idx_segment (customer_segment)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            description TEXT,
            icon VARCHAR(50) DEFAULT 'building',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            department_id INT NULL,
            priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
            progress INT DEFAULT 0,
            deadline DATE,
            timer_seconds INT DEFAULT 0,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_by (created_by),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ============================================
    // Справочники (создаём ДО основных таблиц)
    // ============================================

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_stages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_substages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Справочник подэтапов задач'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_deal_substages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Справочник подэтапов сделок'
    ");

    // ============================================
    // Основные таблицы
    // ============================================

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT,
            client_id INT NULL,
            deal_id INT NULL,
            created_by INT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(100) NOT NULL,
            current_substage_id INT NULL,
            priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
            deadline DATE,
            assigned_to INT,
            department_id INT NULL COMMENT 'Основной отдел задачи',
            timer_seconds INT DEFAULT 0,
            checklist JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            INDEX idx_client (client_id),
            INDEX idx_deal (deal_id),
            INDEX idx_status (status),
            INDEX idx_substage (current_substage_id),
            INDEX idx_assigned (assigned_to),
            INDEX idx_department (department_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (current_substage_id) REFERENCES task_substages(id) ON DELETE SET NULL,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ============================================
    // CRM: Clients / Pipelines / Deals / Activity
    // ============================================

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            legal_name_full VARCHAR(255) NULL,
            legal_name_short VARCHAR(255) NULL,
            inn VARCHAR(32) NULL,
            kpp VARCHAR(32) NULL,
            ogrn VARCHAR(32) NULL,
            type ENUM('person','company') DEFAULT 'person',
            email VARCHAR(255) NULL,
            phone VARCHAR(80) NULL,
            site VARCHAR(255) NULL,
            address TEXT NULL,
            legal_address TEXT NULL,
            postal_address TEXT NULL,
            signer_name VARCHAR(255) NULL,
            signer_position VARCHAR(255) NULL,
            signer_authority VARCHAR(255) NULL,
            bank_name VARCHAR(255) NULL,
            bik VARCHAR(32) NULL,
            checking_account VARCHAR(64) NULL,
            correspondent_account VARCHAR(64) NULL,
            tags JSON NULL,
            status VARCHAR(50) DEFAULT 'active',
            customer_segment ENUM('lead', 'regular', 'vip', 'blacklist') DEFAULT 'regular' COMMENT 'Сегмент клиента',
            notes LONGTEXT NULL,
            custom_fields JSON NULL,
            created_by INT NULL,
            owner_id INT NULL,
            user_id INT NULL COMMENT 'Связь с пользователем (клиентом, если есть)',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_email (email),
            INDEX idx_phone (phone),
            INDEX idx_status (status),
            INDEX idx_segment (customer_segment),
            INDEX idx_owner (owner_id),
            INDEX idx_user_id (user_id),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_client_contacts (
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
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_pipelines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_default (is_default),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_pipeline_stages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pipeline_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            color VARCHAR(20) DEFAULT '#3B82F6',
            `order` INT DEFAULT 0,
            is_won TINYINT(1) DEFAULT 0,
            is_lost TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pipeline (pipeline_id),
            INDEX idx_order (`order`),
            FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_deals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            pipeline_id INT NOT NULL,
            stage_id INT NOT NULL,
            current_substage_id INT NULL,
            title VARCHAR(255) NOT NULL,
            amount DECIMAL(14,2) DEFAULT 0,
            currency CHAR(3) DEFAULT 'RUB',
            probability INT DEFAULT 0,
            expected_close_date DATE NULL,
            owner_id INT NULL,
            description LONGTEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            won_recorded_at DATETIME NULL,
            won_recorded_month DATE NULL,
            INDEX idx_client (client_id),
            INDEX idx_pipeline (pipeline_id),
            INDEX idx_stage (stage_id),
            INDEX idx_substage (current_substage_id),
            INDEX idx_owner (owner_id),
            INDEX idx_expected_close (expected_close_date),
            INDEX idx_deleted_at (deleted_at),
            INDEX idx_won_recorded_at (won_recorded_at),
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE,
            FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE CASCADE,
            FOREIGN KEY (stage_id) REFERENCES crm_pipeline_stages(id) ON DELETE RESTRICT,
            FOREIGN KEY (current_substage_id) REFERENCES crm_deal_substages(id) ON DELETE SET NULL,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_deal_substages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Справочник подэтапов сделок'
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS crm_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM('client','deal','task') NOT NULL,
            entity_id INT NOT NULL,
            action VARCHAR(80) NOT NULL,
            message TEXT NULL,
            user_id INT NULL,
            meta JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS crm_client_monthly_sales (
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
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_widget_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            config_json LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_site_widget_profile_slug (slug),
            INDEX idx_site_widget_profiles_active (is_active),
            INDEX idx_site_widget_profiles_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Профили внешних виджетов сайта для разных сайтов и каналов'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT NULL,
            category VARCHAR(100) NULL,
            content LONGTEXT NOT NULL,
            output_format VARCHAR(20) NOT NULL DEFAULT 'html',
            source_origin VARCHAR(20) NOT NULL DEFAULT 'inline',
            source_path VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_document_templates_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_generations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NULL,
            client_id INT NOT NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'single',
            source_entity_type VARCHAR(50) NULL,
            source_entity_id INT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL DEFAULT 'text/html',
            size_bytes INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_document_generations_client (client_id),
            INDEX idx_document_generations_template (template_id),
            INDEX idx_document_generations_source (source_entity_type, source_entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            `key` VARCHAR(100) NOT NULL,
            value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_key (user_id, `key`),
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS telegram_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_token VARCHAR(255),
            chat_id VARCHAR(100),
            enabled TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Booking schema depends on users/crm_clients and is safe to ensure only after base tables exist.
    ensureBookingModuleSchema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255),
            mime_type VARCHAR(100),
            size INT,
            folder_id INT NULL,
            task_id INT NULL,
            project_id INT NULL,
            uploaded_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_folder (folder_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS file_folders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            name VARCHAR(255) NOT NULL,
            project_id INT NULL,
            task_id INT NULL,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_parent (parent_id),
            INDEX idx_project (project_id),
            INDEX idx_task (task_id),
            FOREIGN KEY (parent_id) REFERENCES file_folders(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            parent_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            parent_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(100),
            old_value TEXT,
            new_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS knowledge_base (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(20) NOT NULL DEFAULT 'article',
            title VARCHAR(255) NOT NULL,
            content LONGTEXT,
            url TEXT,
            question TEXT,
            answer LONGTEXT,
            department_id INT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(100),
            old_value TEXT,
            new_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_task (task_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Таблицы для множественных отделов и ответственных
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_departments (
            task_id INT NOT NULL,
            department_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, department_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
            INDEX idx_department (department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_responsibles (
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, user_id),
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_departments (
            project_id INT NOT NULL,
            department_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, department_id),
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
            INDEX idx_department (department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Таблицы системы ролей (источник роли пользователя: users.role)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            icon VARCHAR(50) DEFAULT 'shield',
            permissions JSON,
            is_system TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, permission_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
            INDEX idx_permission (permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sender_id INT,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'info',
            related_id INT,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_departments (
            user_id INT NOT NULL,
            department_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, department_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ============================================
    // Shifts / Time tracking (team-wide)
    // ============================================

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shift_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            status ENUM('working','break','ended') NOT NULL DEFAULT 'working',
            break_seconds INT NOT NULL DEFAULT 0,
            worked_seconds INT NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_started (started_at),
            INDEX idx_status (status),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shift_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            user_id INT NOT NULL,
            type ENUM('start','break_start','break_end','end','note') NOT NULL,
            occurred_at DATETIME NOT NULL,
            meta JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_occurred (occurred_at),
            FOREIGN KEY (session_id) REFERENCES shift_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Графики работы (work schedules)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            created_by INT NOT NULL,
            schedule_date DATE NOT NULL,
            shift_start TIME NULL COMMENT 'Начало смены',
            shift_end TIME NULL COMMENT 'Конец смены',
            break_start TIME NULL COMMENT 'Начало перерыва',
            break_end TIME NULL COMMENT 'Конец перерыва',
            is_day_off TINYINT(1) DEFAULT 0 COMMENT 'Выходной день',
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_date (user_id, schedule_date),
            INDEX idx_user (user_id),
            INDEX idx_date (schedule_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Графики работы сотрудников'
    ");

    // Таблицы для чата (обновлённые)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('private', 'group', 'project') DEFAULT 'private',
            name VARCHAR(255) NULL,
            avatar VARCHAR(255) NULL,
            user1_id INT NULL,
            user2_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type (type),
            INDEX idx_users (user1_id, user2_id),
            FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            sender_id INT NULL,
            recipient_id INT NULL,
            message TEXT NOT NULL,
            message_type ENUM('text', 'file', 'voice', 'task', 'project', 'sticker', 'image') DEFAULT 'text',
            voice_duration INT NULL COMMENT 'Длительность голосового сообщения в секундах',
            voice_waveform TEXT NULL COMMENT 'JSON для визуализации волны',
            file_name VARCHAR(255) NULL COMMENT 'Имя файла',
            file_url VARCHAR(512) NULL COMMENT 'URL к файлу',
            mime_type VARCHAR(100) NULL COMMENT 'MIME тип файла',
            sticker_id INT NULL COMMENT 'ID стикера',
            sticker_url VARCHAR(512) NULL COMMENT 'URL/emoji стикера',
            sticker_type VARCHAR(20) DEFAULT 'emoji' COMMENT 'Тип стикера: emoji/image',
            task_id INT NULL COMMENT 'ID задачи',
            task_title VARCHAR(255) NULL COMMENT 'Название задачи',
            task_status VARCHAR(50) NULL COMMENT 'Статус задачи',
            task_priority VARCHAR(50) NULL COMMENT 'Приоритет задачи',
            project_id INT NULL COMMENT 'ID проекта',
            project_name VARCHAR(255) NULL COMMENT 'Название проекта',
            project_priority VARCHAR(50) NULL COMMENT 'Приоритет проекта',
            is_read TINYINT(1) DEFAULT 0,
            status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
            reply_to_id INT NULL COMMENT 'ID сообщения для ответа',
            edited_at DATETIME NULL COMMENT 'Время редактирования',
            deleted_at DATETIME NULL COMMENT 'Время удаления (soft delete)',
            forwarded_from_id INT NULL COMMENT 'ID оригинального сообщения при пересылке',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_room (room_id),
            INDEX idx_sender (sender_id),
            INDEX idx_recipient (recipient_id),
            INDEX idx_reply_to (reply_to_id),
            INDEX idx_forwarded_from (forwarded_from_id),
            INDEX idx_task_id (task_id),
            INDEX idx_project_id (project_id),
            INDEX idx_sticker_id (sticker_id),
            INDEX idx_file_url (file_url),
            FULLTEXT INDEX ft_message (message),
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (reply_to_id) REFERENCES chat_messages(id) ON DELETE SET NULL,
            FOREIGN KEY (forwarded_from_id) REFERENCES chat_messages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Сообщения чата'
    ");

    // Force utf8mb4 on chat tables for emoji (best-effort for reinstalls)
    try { $pdo->exec("ALTER TABLE chat_rooms CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_room_members CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE chat_messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) {}

    // Участники групповых чатов
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_room_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('member', 'admin') DEFAULT 'member',
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            typing_until DATETIME NULL,
            online_until DATETIME NULL,
            UNIQUE KEY unique_member (room_id, user_id),
            INDEX idx_room (room_id),
            INDEX idx_user (user_id),
            INDEX idx_online_until (online_until),
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Backward/forward compatibility for existing installs (best-effort)
    try {
        $pdo->exec("ALTER TABLE chat_room_members ADD COLUMN online_until DATETIME NULL");
    } catch (Exception $e) {
        // column may already exist
    }

    // ============================================
    // HELPDESK - ЗАЯВКИ КЛИЕНТОВ
    // ============================================

    // Статусы заявок
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_statuses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Справочник статусов заявок'
    ");

    // Категории заявок
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            icon VARCHAR(50) DEFAULT 'inbox',
            color VARCHAR(20) DEFAULT '#3B82F6',
            `order` INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (`order`),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Категории заявок'
    ");

    // Заявки
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_number VARCHAR(20) UNIQUE NOT NULL COMMENT 'Номер заявки для клиента',
            client_name VARCHAR(255) NOT NULL COMMENT 'Имя клиента',
            client_email VARCHAR(255) NULL COMMENT 'Email клиента',
            client_phone VARCHAR(50) NULL COMMENT 'Телефон клиента',
            client_company VARCHAR(255) NULL COMMENT 'Компания клиента',
            category_id INT NULL,
            status_id INT NOT NULL DEFAULT 1,
            subject VARCHAR(500) NOT NULL COMMENT 'Тема заявки',
            description TEXT NOT NULL COMMENT 'Описание проблемы',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            budget DECIMAL(14,2) NULL COMMENT 'Бюджет заявки',
            deadline DATE NULL COMMENT 'Желаемый срок',
            source VARCHAR(50) DEFAULT 'web' COMMENT 'Источник: web, email, api',
            assigned_to INT NULL COMMENT 'Ответственный сотрудник',
            assigned_department_id INT NULL COMMENT 'Ответственный отдел',
            crm_client_id INT NULL COMMENT 'Связанный клиент CRM',
            crm_deal_id INT NULL COMMENT 'Связанная сделка',
            task_id INT NULL COMMENT 'Связанная задача',
            project_id INT NULL COMMENT 'Связанный проект',
            resolution TEXT NULL COMMENT 'Решение проблемы',
            resolved_at DATETIME NULL,
            resolved_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ticket_number (ticket_number),
            INDEX idx_status (status_id),
            INDEX idx_category (category_id),
            INDEX idx_priority (priority),
            INDEX idx_assigned (assigned_to),
            INDEX idx_created (created_at),
            INDEX idx_client_email (client_email),
            FOREIGN KEY (category_id) REFERENCES helpdesk_categories(id) ON DELETE SET NULL,
            FOREIGN KEY (status_id) REFERENCES helpdesk_statuses(id) ON DELETE RESTRICT,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_department_id) REFERENCES departments(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Заявки клиентов'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS widget_chat_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_token VARCHAR(64) NOT NULL,
            profile_slug VARCHAR(120) NULL,
            room_id INT NOT NULL,
            ticket_id INT NULL,
            guest_user_id INT NULL,
            operator_user_id INT NOT NULL,
            visitor_name VARCHAR(255) NULL,
            visitor_email VARCHAR(255) NULL,
            visitor_phone VARCHAR(50) NULL,
            visitor_company VARCHAR(255) NULL,
            page_url VARCHAR(1000) NULL,
            page_title VARCHAR(255) NULL,
            last_guest_message_at DATETIME NULL,
            last_operator_message_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_widget_chat_public_token (public_token),
            INDEX idx_widget_chat_room (room_id),
            INDEX idx_widget_chat_ticket (ticket_id),
            INDEX idx_widget_chat_guest (guest_user_id),
            INDEX idx_widget_chat_operator (operator_user_id),
            INDEX idx_widget_chat_updated (updated_at),
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE SET NULL,
            FOREIGN KEY (guest_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (operator_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Публичные сессии чата виджета сайта'
    ");

    // Комментарии к заявкам
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL COMMENT 'Коммент от сотрудника',
            is_internal TINYINT(1) DEFAULT 0 COMMENT 'Внутренний комментарий (не виден клиенту)',
            message TEXT NOT NULL,
            attachments JSON NULL COMMENT 'Массив файлов',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            INDEX idx_user (user_id),
            INDEX idx_internal (is_internal),
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Комментарии к заявкам'
    ");

    // История изменений заявок
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL COMMENT 'type: status_changed, assigned, comment, created, etc.',
            field_name VARCHAR(100) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            meta JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at),
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'История изменений заявок'
    ");

    // Файлы заявок
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            file_size INT NULL,
            file_path VARCHAR(512) NOT NULL,
            uploaded_by INT NULL,
            is_internal TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            INDEX idx_uploader (uploaded_by),
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Вложения к заявкам'
    ");

    // Шаблоны ответов
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            category_id INT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_category (category_id),
            FOREIGN KEY (category_id) REFERENCES helpdesk_categories(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Шаблоны ответов'
    ");

    // Настройки HelpDesk
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS helpdesk_settings (
            `key` VARCHAR(100) PRIMARY KEY,
            value TEXT NOT NULL,
            description VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Создаём дефолтные статусы
    $defaultStatuses = [
        ['name' => 'Новая', 'color' => '#3B82F6', 'order' => 1, 'is_default' => 1],
        ['name' => 'В работе', 'color' => '#F59E0B', 'order' => 2, 'is_default' => 0],
        ['name' => 'Ожидает ответа клиента', 'color' => '#8B5CF6', 'order' => 3, 'is_default' => 0],
        ['name' => 'Ожидает решения', 'color' => '#06B6D4', 'order' => 4, 'is_default' => 0],
        ['name' => 'Решена', 'color' => '#10B981', 'order' => 5, 'is_default' => 0],
        ['name' => 'Закрыта', 'color' => '#6B7280', 'order' => 6, 'is_default' => 0],
        ['name' => 'Отклонена', 'color' => '#EF4444', 'order' => 7, 'is_default' => 0],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO helpdesk_statuses (name, color, `order`, is_default) VALUES (?, ?, ?, ?)");
    foreach ($defaultStatuses as $s) {
        $stmt->execute([$s['name'], $s['color'], $s['order'], $s['is_default']]);
    }

    // Создаём дефолтные категории (обновлено 6 категорий)
    // Используем SET NAMES для корректного сохранения emoji
    $pdo->exec("SET NAMES utf8mb4");
    
    $defaultCategories = [
        ['name' => 'Консультация менеджера', 'icon' => '💼', 'description' => 'Помощь в выборе решения', 'color' => '#3B82F6', 'order' => 1],
        ['name' => 'Заявка на прайс', 'icon' => '📋', 'description' => 'Получить прайс-лист', 'color' => '#10B981', 'order' => 2],
        ['name' => 'Партнерство', 'icon' => '🤝', 'description' => 'Сотрудничество и партнёрство', 'color' => '#8B5CF6', 'order' => 3],
        ['name' => 'Техподдержка', 'icon' => '🔧', 'description' => 'Технические вопросы', 'color' => '#F59E0B', 'order' => 4],
        ['name' => 'Биллинг', 'icon' => '💳', 'description' => 'Оплата и счета', 'color' => '#EF4444', 'order' => 5],
        ['name' => 'Другое', 'icon' => '📝', 'description' => 'Прочие вопросы', 'color' => '#6B7280', 'order' => 6],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO helpdesk_categories (name, icon, description, color, `order`, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($defaultCategories as $c) {
        $stmt->execute([$c['name'], $c['icon'], $c['description'] ?? null, $c['color'], $c['order']]);
    }

    // Backward/forward compatibility for existing installs (best-effort)
    try {
        $pdo->exec("ALTER TABLE chat_room_members ADD COLUMN online_until DATETIME NULL");
    } catch (Exception $e) {
        // column may already exist
    }
    try {
        $pdo->exec("ALTER TABLE chat_room_members ADD INDEX idx_online_until (online_until)");
    } catch (Exception $e) {
        // index may already exist
    }

    // Добавляем department_id в tasks если нет
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN department_id INT NULL COMMENT 'Основной отдел задачи' AFTER assigned_to");
        $pdo->exec("ALTER TABLE tasks ADD INDEX idx_department (department_id)");
        $pdo->exec("ALTER TABLE tasks ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // column may already exist
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages MODIFY COLUMN recipient_id INT NULL");
    } catch (Exception $e) {
        // already nullable or constraint differs
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages DROP FOREIGN KEY chat_messages_ibfk_2");
    } catch (Exception $e) {
        // foreign key name may differ
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages DROP FOREIGN KEY fk_chat_messages_sender");
    } catch (Exception $e) {
        // foreign key may not exist or already replaced
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages DROP FOREIGN KEY fk_chat_messages_recipient");
    } catch (Exception $e) {
        // foreign key may not exist or already replaced
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages MODIFY COLUMN sender_id INT NULL");
    } catch (Exception $e) {
        // already nullable
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages ADD CONSTRAINT fk_chat_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // already exists or legacy fk retained
    }

    try {
        $pdo->exec("ALTER TABLE chat_messages ADD CONSTRAINT fk_chat_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // already exists or legacy fk retained
    }

    try {
        $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($databaseName !== '') {
            $widgetGuestColumnStmt = $pdo->prepare(" 
                SELECT IS_NULLABLE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'widget_chat_sessions' AND COLUMN_NAME = 'guest_user_id'
                LIMIT 1
            ");
            $widgetGuestColumnStmt->execute([$databaseName]);
            $widgetGuestColumn = $widgetGuestColumnStmt->fetch();

            $widgetGuestFkStmt = $pdo->prepare(" 
                SELECT kcu.CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = ?
                  AND kcu.TABLE_NAME = 'widget_chat_sessions'
                  AND kcu.COLUMN_NAME = 'guest_user_id'
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY kcu.CONSTRAINT_NAME ASC
            ");
            $widgetGuestFkStmt->execute([$databaseName]);
            foreach ($widgetGuestFkStmt->fetchAll(PDO::FETCH_COLUMN) as $constraintName) {
                if (is_string($constraintName) && $constraintName !== '') {
                    $pdo->exec("ALTER TABLE widget_chat_sessions DROP FOREIGN KEY `{$constraintName}`");
                }
            }

            if (($widgetGuestColumn['IS_NULLABLE'] ?? 'NO') !== 'YES') {
                $pdo->exec("ALTER TABLE widget_chat_sessions MODIFY COLUMN guest_user_id INT NULL");
            }

            $widgetGuestFkStmt->execute([$databaseName]);
            if (!$widgetGuestFkStmt->fetchColumn()) {
                $pdo->exec("ALTER TABLE widget_chat_sessions ADD CONSTRAINT fk_widget_chat_sessions_guest FOREIGN KEY (guest_user_id) REFERENCES users(id) ON DELETE SET NULL");
            }
        }
    } catch (Exception $e) {
        // best-effort reconciliation for widget_chat_sessions.guest_user_id
    }

    try {
        $pdo->exec("ALTER TABLE widget_chat_sessions DROP FOREIGN KEY fk_widget_chat_sessions_operator");
    } catch (Exception $e) {
        // foreign key may not exist or already replaced
    }

    try {
        $pdo->exec("ALTER TABLE widget_chat_sessions ADD CONSTRAINT fk_widget_chat_sessions_operator FOREIGN KEY (operator_user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (Exception $e) {
        // already exists or legacy fk retained
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms DROP FOREIGN KEY chat_rooms_ibfk_1");
    } catch (Exception $e) {
        // foreign key name may differ
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms DROP FOREIGN KEY chat_rooms_ibfk_2");
    } catch (Exception $e) {
        // foreign key name may differ
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms DROP FOREIGN KEY fk_chat_rooms_user1");
    } catch (Exception $e) {
        // foreign key may not exist or already replaced
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms DROP FOREIGN KEY fk_chat_rooms_user2");
    } catch (Exception $e) {
        // foreign key may not exist or already replaced
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms ADD CONSTRAINT fk_chat_rooms_user1 FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // already exists or legacy fk retained
    }

    try {
        $pdo->exec("ALTER TABLE chat_rooms ADD CONSTRAINT fk_chat_rooms_user2 FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // already exists or legacy fk retained
    }

    // Таблица для пересылки сообщений
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_forwards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            from_room_id INT NOT NULL,
            to_room_id INT NOT NULL,
            forwarded_by INT NOT NULL,
            forwarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_message (message_id),
            INDEX idx_from_room (from_room_id),
            INDEX idx_to_room (to_room_id),
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (from_room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (to_room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (forwarded_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Таблица для статусов прочтения
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_message_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_read (message_id, user_id),
            INDEX idx_message (message_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Таблица для стикеров (коллекция стикеров)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_stickers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL COMMENT 'Название стикера',
            url VARCHAR(512) NOT NULL COMMENT 'URL к изображению стикера',
            category VARCHAR(50) DEFAULT 'default' COMMENT 'Категория стикера',
            sort_order INT DEFAULT 0 COMMENT 'Порядок отображения',
            is_active TINYINT(1) DEFAULT 1 COMMENT 'Активен ли стикер',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_sort (sort_order),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Коллекция стикеров'
    ");

    // Добавляем дефолтные стикеры
    $stickers = [
        ['name' => 'Привет', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712009.png', 'category' => 'default', 'sort_order' => 1],
        ['name' => 'Спасибо', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712035.png', 'category' => 'default', 'sort_order' => 2],
        ['name' => 'OK', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712069.png', 'category' => 'default', 'sort_order' => 3],
        ['name' => 'Смех', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png', 'category' => 'default', 'sort_order' => 4],
        ['name' => 'Любовь', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712135.png', 'category' => 'default', 'sort_order' => 5],
        ['name' => 'Грусть', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712169.png', 'category' => 'default', 'sort_order' => 6],
        ['name' => 'Злость', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712209.png', 'category' => 'default', 'sort_order' => 7],
        ['name' => 'Удивление', 'url' => 'https://cdn-icons-png.flaticon.com/512/4712/4712235.png', 'category' => 'default', 'sort_order' => 8],
    ];

    $stmt = $pdo->prepare("INSERT INTO chat_stickers (name, url, category, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($stickers as $sticker) {
        $stmt->execute([$sticker['name'], $sticker['url'], $sticker['category'], $sticker['sort_order']]);
    }
    
    // Таблица для аудио/видео звонков
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caller_id INT NOT NULL,
            recipient_id INT NOT NULL,
            room_id INT NULL,
            call_type ENUM('audio', 'video') NOT NULL,
            status ENUM('calling', 'accepted', 'declined', 'missed', 'ended') NOT NULL DEFAULT 'calling',
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            duration_seconds INT DEFAULT 0,
            is_seen TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_caller (caller_id),
            INDEX idx_recipient (recipient_id),
            INDEX idx_room (room_id),
            INDEX idx_status (status),
            FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Звонки'
    ");
    
    // Таблица для WebRTC сессий (обмен SDP)
    // IMPORTANT: session_type must match API (offer/answer/ice)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webrtc_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            call_id INT NOT NULL,
            session_type ENUM('offer', 'answer', 'ice') NOT NULL,
            sdp_data TEXT NULL,
            candidate_data TEXT NULL,
            is_processed TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_call (call_id),
            INDEX idx_type (session_type),
            FOREIGN KEY (call_id) REFERENCES chat_calls(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'WebRTC сессии'
    ");

    // Best-effort schema fix for existing installs where session_type differs
    try {
        $pdo->exec("ALTER TABLE webrtc_sessions MODIFY session_type ENUM('offer','answer','ice') NOT NULL");
    } catch (Exception $e) {}
    
    // Таблица для встреч (как в Zoom)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meetings (
            id VARCHAR(36) PRIMARY KEY,
            created_by INT NOT NULL,
            meeting_type ENUM('audio', 'video', 'presentation') NOT NULL DEFAULT 'video',
            topic VARCHAR(255) NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            duration_seconds INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_creator (created_by),
            INDEX idx_active (is_active),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Встречи'
    ");
    
    // Участники встреч
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meeting_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id VARCHAR(36) NOT NULL,
            user_id INT NULL,
            email VARCHAR(255) NULL,
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            duration_seconds INT DEFAULT 0,
            INDEX idx_meeting (meeting_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Участники встреч'
    ");
    
    // Офлайн сообщения WebSocket
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS websocket_offline_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message_data TEXT NOT NULL,
            is_delivered TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_delivered (is_delivered),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            room_id VARCHAR(36) NOT NULL UNIQUE,
            host_id INT NOT NULL,
            guest_pin_hash VARCHAR(255) NULL,
            guest_pin_enc TEXT NULL,
            status ENUM('waiting', 'active', 'ended', 'cancelled') DEFAULT 'waiting',
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            settings JSON NULL,
            INDEX idx_room (room_id),
            INDEX idx_host (host_id),
            INDEX idx_status (status),
            INDEX idx_host_status (host_id, status),
            FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Видеоконференции'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conference_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            user_id INT NULL,
            guest_name VARCHAR(100) NULL,
            guest_email VARCHAR(100) NULL,
            role ENUM('host', 'co-host', 'participant') DEFAULT 'participant',
            status ENUM('waiting', 'joined', 'left', 'rejected') DEFAULT 'waiting',
            joined_at DATETIME NULL,
            left_at DATETIME NULL,
            video_enabled TINYINT(1) DEFAULT 1,
            audio_enabled TINYINT(1) DEFAULT 1,
            INDEX idx_conference (conference_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_conference_user (conference_id, user_id),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conference_join_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            participant_id INT NOT NULL,
            guest_name VARCHAR(100) NOT NULL,
            guest_email VARCHAR(100),
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by INT NULL,
            INDEX idx_conference (conference_id),
            INDEX idx_participant (participant_id),
            INDEX idx_status (status),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conference_chat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            sender_id INT NULL,
            sender_name VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conference (conference_id),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NULL,
            recipient_id INT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            imap_folder VARCHAR(255) NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_html TINYINT(1) DEFAULT 0,
            folder ENUM('inbox', 'sent', 'drafts', 'spam', 'trash') DEFAULT 'inbox',
            is_read TINYINT(1) DEFAULT 0,
            is_starred TINYINT(1) DEFAULT 0,
            has_attachments TINYINT(1) DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_id),
            INDEX idx_recipient (recipient_id),
            INDEX idx_imap_folder (imap_folder),
            INDEX idx_folder (folder),
            INDEX idx_read (is_read),
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Backward-compatible migration: add column if table already exists
    try {
        $col = $pdo->query("SHOW COLUMNS FROM mail_messages LIKE 'imap_folder'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE mail_messages ADD COLUMN imap_folder VARCHAR(255) NULL AFTER recipient_email");
            $pdo->exec("ALTER TABLE mail_messages ADD INDEX idx_imap_folder (imap_folder)");
        }
    } catch (Throwable $e) {
        // ignore migration errors in installer
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(512) NOT NULL,
            mime_type VARCHAR(100),
            file_size BIGINT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email_id),
            FOREIGN KEY (email_id) REFERENCES mail_messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            smtp_host VARCHAR(255) NOT NULL,
            smtp_port INT DEFAULT 587,
            smtp_username VARCHAR(255),
            smtp_password VARCHAR(255) NOT NULL,
            smtp_encryption ENUM('none', 'tls', 'ssl') DEFAULT 'tls',
            imap_host VARCHAR(255) NULL,
            imap_port INT DEFAULT 993,
            imap_encryption ENUM('none', 'tls', 'ssl') DEFAULT 'ssl',
            mail_signature TEXT NULL,
            display_name VARCHAR(255) NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_default (is_default),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // IMAP синхронизация: хранение UID/папок, чтобы не тянуть всё каждый раз
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_imap_folders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_email VARCHAR(255) NOT NULL,
            folder VARCHAR(255) NOT NULL,
            delimiter VARCHAR(10) NULL,
            attributes INT DEFAULT 0,
            last_uid INT DEFAULT 0,
            last_sync_at DATETIME NULL,
            UNIQUE KEY uniq_user_folder (user_id, account_email, folder),
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_imap_uids (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_email VARCHAR(255) NOT NULL,
            folder VARCHAR(255) NOT NULL,
            uid INT NOT NULL,
            message_id VARCHAR(255) NULL,
            mail_message_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_uid (user_id, account_email, folder, uid),
            INDEX idx_user (user_id),
            INDEX idx_message (mail_message_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (mail_message_id) REFERENCES mail_messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Добавляем тестовые данные для звонков
    $pdo->exec("INSERT IGNORE INTO chat_calls (id, caller_id, recipient_id, call_type, status) VALUES (1, 1, 1, 'audio', 'ended')");

    // Расширение пользователей (CRM поля) — безопасное добавление колонок
    $userColumns = [
        ['customer_segment', "ENUM('lead', 'regular', 'vip', 'blacklist') DEFAULT 'regular' COMMENT 'Сегмент клиента' AFTER role"],
        ['blacklisted_at', "TIMESTAMP NULL COMMENT 'Дата добавления в черный список' AFTER customer_segment"],
        ['blacklist_reason', "TEXT COMMENT 'Причина черного списка' AFTER blacklisted_at"],
        ['vip_discount', "INT DEFAULT 0 COMMENT 'VIP скидка в процентах' AFTER blacklist_reason"],
        ['birthday', "DATE NULL COMMENT 'День рождения сотрудника' AFTER phone"],
        ['passport_series', "VARCHAR(10) COMMENT 'Серия паспорта' AFTER birthday"],
        ['passport_number', "VARCHAR(10) COMMENT 'Номер паспорта' AFTER passport_series"],
        ['passport_issued_by', "VARCHAR(255) COMMENT 'Кем выдан паспорт' AFTER passport_number"],
        ['passport_issue_date', "DATE COMMENT 'Дата выдачи паспорта' AFTER passport_issued_by"],
        ['registration_address', "TEXT COMMENT 'Адрес регистрации' AFTER passport_issue_date"],
        ['company_name', "VARCHAR(255) COMMENT 'Название компании' AFTER registration_address"],
        ['company_inn', "VARCHAR(20) COMMENT 'ИНН компании' AFTER company_name"],
        ['need_documents', "TINYINT DEFAULT 0 COMMENT 'Нужны документы' AFTER company_inn"],
        ['communication_preference', "ENUM('email', 'phone', 'telegram', 'whatsapp') DEFAULT 'phone' COMMENT 'Предпочтительный способ связи' AFTER need_documents"],
        ['consent_to_data', "TINYINT DEFAULT 0 COMMENT 'Согласие на обработку данных' AFTER communication_preference"],
        ['consent_to_marketing', "TINYINT DEFAULT 0 COMMENT 'Согласие на маркетинг' AFTER consent_to_data"],
        ['last_activity', "TIMESTAMP NULL AFTER last_login"],
    ];

    foreach ($userColumns as [$colName, $colDef]) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$colName` $colDef");
        } catch (Exception $e) {
            // Колонка уже существует — пропускаем
        }
    }

    // Индексы
    try { $pdo->exec("ALTER TABLE users ADD INDEX idx_segment (customer_segment)"); } catch (Exception $e) {}
    // ============================================
    // WebRTC таблицы для видеоконференций
    // ============================================

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webrtc_offers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            from_participant_id INT NOT NULL,
            to_participant_id INT NOT NULL,
            sdp_offer LONGTEXT NOT NULL,
            status ENUM('pending', 'answered', 'rejected', 'expired') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            answered_at TIMESTAMP NULL,
            INDEX idx_conference (conference_id),
            INDEX idx_from (from_participant_id),
            INDEX idx_to (to_participant_id),
            INDEX idx_status (status),
            INDEX idx_conference_status (conference_id, status),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE,
            FOREIGN KEY (from_participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE,
            FOREIGN KEY (to_participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'WebRTC SDP оферы для видеоконференций'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webrtc_ice_candidates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            from_participant_id INT NOT NULL,
            to_participant_id INT NULL,
            candidate LONGTEXT NOT NULL,
            sdp_mid VARCHAR(255),
            sdp_mline_index INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conference (conference_id),
            INDEX idx_from (from_participant_id),
            INDEX idx_to (to_participant_id),
            INDEX idx_conference_created (conference_id, created_at),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE,
            FOREIGN KEY (from_participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE,
            FOREIGN KEY (to_participant_id) REFERENCES conference_participants(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'WebRTC ICE кандидаты для видеоконференций'
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webrtc_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conference_id INT NOT NULL,
            offer_id INT NOT NULL,
            from_participant_id INT NOT NULL,
            to_participant_id INT NOT NULL,
            sdp_answer LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conference (conference_id),
            INDEX idx_offer (offer_id),
            INDEX idx_from (from_participant_id),
            INDEX idx_to (to_participant_id),
            INDEX idx_conference_offer (conference_id, offer_id),
            FOREIGN KEY (conference_id) REFERENCES conferences(id) ON DELETE CASCADE,
            FOREIGN KEY (offer_id) REFERENCES webrtc_offers(id) ON DELETE CASCADE,
            FOREIGN KEY (from_participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE,
            FOREIGN KEY (to_participant_id) REFERENCES conference_participants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'WebRTC SDP ответы для видеоконференций'
    ");
}

function createAdmin(PDO $pdo, string $password): void {
    $login = 'root';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = 'Root User';
    $role = 'root';

    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE login = ? LIMIT 1");
    $checkStmt->execute([$login]);
    $existingUser = $checkStmt->fetch();

    if ($existingUser) {
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET email = ?, password_hash = ?, full_name = ?, role = ?
            WHERE id = ?
        ");
        // root login не email. Поле email оставляем пустым.
        $updateStmt->execute([null, $passwordHash, $fullName, $role, (int)$existingUser['id']]);
        error_log("Installer: existing root user found, credentials updated for user_id=" . (int)$existingUser['id']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (login, email, password_hash, full_name, role)
        VALUES (?, ?, ?, ?, ?)
    ");
    // root login не email. Поле email оставляем пустым.
    $stmt->execute([$login, null, $passwordHash, $fullName, $role]);
    error_log("Installer: root user created with user_id=" . (int)$pdo->lastInsertId());
}

function seedData(PDO $pdo): void {
    $siteWidgetDefaults = [
        'api_base' => '',
        'position' => 'right',
        'contact_url' => '',
        'contact_label' => 'Написать в чат',
        'contact_description' => 'Диалог откроется прямо на сайте и сразу попадет в CRM-чат команды.',
        'form_width' => 480,
        'form_height' => 760,
        'chat_width' => 420,
        'chat_height' => 760,
        'chat_title' => 'Команда на связи',
        'chat_description' => 'Ответим в CRM-чате или оформим обращение в HelpDesk без перехода на другие каналы.',
        'brand_color' => '#2563eb',
        'brand_button_text' => '💬',
        'brand_form_title' => 'Оставить обращение',
        'brand_form_description' => 'Опишите задачу в форме, и мы сразу зарегистрируем обращение в HelpDesk.'
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO site_widget_profiles (name, slug, is_active, config_json)
         SELECT ?, ?, 1, ?
         WHERE NOT EXISTS (SELECT 1 FROM site_widget_profiles WHERE slug = ?)"
    );
    $stmt->execute([
        'Основной профиль',
        'default',
        json_encode($siteWidgetDefaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'default'
    ]);

    $pdo->exec("UPDATE site_widget_profiles SET is_active = CASE WHEN slug = 'default' THEN 1 ELSE 0 END WHERE slug = 'default' OR is_active = 1");

    // Этапы задач
    $stages = [
        ['name' => 'Новая', 'color' => '#3B82F6', 'order' => 1],
        ['name' => 'В работе', 'color' => '#F59E0B', 'order' => 2],
        ['name' => 'На проверке', 'color' => '#8B5CF6', 'order' => 3],
        ['name' => 'Готово', 'color' => '#10B981', 'order' => 4],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO task_stages (name, color, `order`) VALUES (?, ?, ?)");
    foreach ($stages as $stage) {
        $stmt->execute([$stage['name'], $stage['color'], $stage['order']]);
    }

    // CRM: базовая воронка продаж
    $pdo->exec("INSERT IGNORE INTO crm_pipelines (id, name, is_default, created_by) VALUES (1, 'Основная воронка', 1, 1)");

    $crmStages = [
        ['pipeline_id' => 1, 'name' => 'Новая заявка', 'color' => '#3B82F6', 'order' => 1, 'is_won' => 0, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Квалификация', 'color' => '#8B5CF6', 'order' => 2, 'is_won' => 0, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Коммерческое предложение', 'color' => '#F59E0B', 'order' => 3, 'is_won' => 0, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Переговоры', 'color' => '#06B6D4', 'order' => 4, 'is_won' => 0, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Договор', 'color' => '#6366F1', 'order' => 5, 'is_won' => 0, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Успешно реализовано', 'color' => '#10B981', 'order' => 6, 'is_won' => 1, 'is_lost' => 0],
        ['pipeline_id' => 1, 'name' => 'Потеряно', 'color' => '#EF4444', 'order' => 7, 'is_won' => 0, 'is_lost' => 1],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO crm_pipeline_stages (pipeline_id, name, color, `order`, is_won, is_lost) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($crmStages as $s) {
        $stmt->execute([$s['pipeline_id'], $s['name'], $s['color'], $s['order'], $s['is_won'], $s['is_lost']]);
    }
    
    // Настройки
    $settings = [
        ['key' => 'company_name', 'value' => 'TaskFlow Pro'],
        ['key' => 'primary_color', 'value' => '#3B82F6'],
        ['key' => 'accent_color', 'value' => '#8B5CF6'],
        ['key' => 'font_family', 'value' => 'Inter, system-ui, sans-serif'],
        ['key' => 'logo_base64', 'value' => ''],
        ['key' => 'theme', 'value' => 'light'],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, value) VALUES (?, ?)");
    foreach ($settings as $setting) {
        $stmt->execute([$setting['key'], $setting['value']]);
    }
    
    // Telegram настройки
    $pdo->exec("INSERT IGNORE INTO telegram_settings (id, bot_token, chat_id, enabled) VALUES (1, '', '', 0)");
    
    // Настройки погоды (OpenWeatherMap)
    $pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('weather_api_key', '427fb0d97beab5341712d7cdca451f68'), ('weather_city', 'Москва')");

    // ============================================
    // РОЛИ И ПРАВА ДОСТУПА (упрощённая система: 3 роли)
    // ============================================

    // 1. ROOT — Полный доступ ко всему и всегда (администрирование + всё)
    $rootPermissions = [
        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'projects' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'departments' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'files' => ['view' => true, 'upload' => true, 'edit' => true, 'delete' => true],
        'knowledge' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'chat' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true, 'forward' => true, 'create_group' => true],
        'mail' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true],
        'crm' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'export' => true, 'stages_manage' => true],
        'leader' => ['view' => true, 'shifts_manage' => true, 'export' => true],
        'admin' => ['full' => true]
    ];

    // 2. РУКОВОДИТЕЛЬ — Все интерфейсы кроме администрирования
    // НЕ может: управлять ролями, настройками Telegram, логотипом/названием компании
    // МОЖЕТ: задачи, проекты, CRM, бронирования, сотрудники (просмотр/редактирование), смены, дашборд руководителя
    $leaderPermissions = [
        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'projects' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'departments' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'files' => ['view' => true, 'upload' => true, 'edit' => true, 'delete' => true],
        'knowledge' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'chat' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true, 'forward' => true, 'create_group' => true],
        'mail' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => true],
        'crm' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'export' => true, 'stages_manage' => true],
        'leader' => ['view' => true, 'shifts_manage' => true, 'export' => true],
        'users' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'admin' => ['full' => false]
    ];

    // 3. СОТРУДНИК — Все интерфейсы кроме «Руководитель» и «Администрирование»
    // НЕ может: дашборд руководителя, управление сменами, администрирование, управление ролями
    // МОЖЕТ: задачи, проекты, CRM, бронирования, файлы, знания, чат, почта, отделы
    $employeePermissions = [
        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'projects' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'departments' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'files' => ['view' => true, 'upload' => true, 'edit' => true, 'delete' => false],
        'knowledge' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'chat' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => false, 'forward' => true, 'create_group' => true],
        'mail' => ['view' => true, 'send' => true, 'edit' => true, 'delete' => false],
        'crm' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false, 'export' => true, 'stages_manage' => false],
        'leader' => ['view' => false, 'shifts_manage' => false, 'export' => false],
        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'admin' => ['full' => false]
    ];

    // Создаём системные роли
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO roles (name, description, icon, permissions, is_system)
        VALUES (?, ?, ?, ?, ?)
    ");

    // ROOT
    $stmt->execute([
        'root',
        'Полный доступ ко всей системе, включая администрирование',
        'shield',
        json_encode($rootPermissions),
        1
    ]);

    // LEGACY ADMINISTRATOR
    $stmt->execute([
        'administrator',
        'Legacy-администратор с полным административным доступом',
        'shield',
        json_encode($rootPermissions),
        1
    ]);

    // РУКОВОДИТЕЛЬ
    $stmt->execute([
        'leader',
        'Все интерфейсы кроме администрирования (роли, Telegram, логотип)',
        'chart-bar',
        json_encode($leaderPermissions),
        1
    ]);

    // СОТРУДНИК
    $stmt->execute([
        'employee',
        'Все интерфейсы кроме «Руководитель» и «Администрирование»',
        'user',
        json_encode($employeePermissions),
        1
    ]);

    // Роли назначаются через users.role (одна роль на пользователя).
    
    // Базовые права доступа
    $permissions = [
        // Отделы
        ['code' => 'departments.view', 'name' => 'Просмотр отделов', 'category' => 'departments', 'description' => 'Может просматривать отделы'],
        ['code' => 'departments.create', 'name' => 'Создание отделов', 'category' => 'departments', 'description' => 'Может создавать отделы'],
        ['code' => 'departments.edit', 'name' => 'Редактирование отделов', 'category' => 'departments', 'description' => 'Может редактировать отделы'],
        ['code' => 'departments.delete', 'name' => 'Удаление отделов', 'category' => 'departments', 'description' => 'Может удалять отделы'],

        // Проекты
        ['code' => 'projects.view', 'name' => 'Просмотр проектов', 'category' => 'projects', 'description' => 'Может просматривать проекты'],
        ['code' => 'projects.create', 'name' => 'Создание проектов', 'category' => 'projects', 'description' => 'Может создавать проекты'],
        ['code' => 'projects.edit', 'name' => 'Редактирование проектов', 'category' => 'projects', 'description' => 'Может редактировать проекты'],
        ['code' => 'projects.delete', 'name' => 'Удаление проектов', 'category' => 'projects', 'description' => 'Может удалять проекты'],

        // Задачи
        ['code' => 'tasks.view', 'name' => 'Просмотр задач', 'category' => 'tasks', 'description' => 'Может просматривать задачи'],
        ['code' => 'tasks.create', 'name' => 'Создание задач', 'category' => 'tasks', 'description' => 'Может создавать задачи'],
        ['code' => 'tasks.edit', 'name' => 'Редактирование задач', 'category' => 'tasks', 'description' => 'Может редактировать задачи'],
        ['code' => 'tasks.delete', 'name' => 'Удаление задач', 'category' => 'tasks', 'description' => 'Может удалять задачи'],

        // Пользователи
        ['code' => 'users.view', 'name' => 'Просмотр пользователей', 'category' => 'users', 'description' => 'Может просматривать пользователей'],
        ['code' => 'users.create', 'name' => 'Создание пользователей', 'category' => 'users', 'description' => 'Может создавать пользователей'],
        ['code' => 'users.edit', 'name' => 'Редактирование пользователей', 'category' => 'users', 'description' => 'Может редактировать пользователей'],
        ['code' => 'users.delete', 'name' => 'Удаление пользователей', 'category' => 'users', 'description' => 'Может удалять пользователей'],

        // Чат
        ['code' => 'chat.view', 'name' => 'Просмотр чатов', 'category' => 'chat', 'description' => 'Может просматривать чаты'],
        ['code' => 'chat.send', 'name' => 'Отправка сообщений', 'category' => 'chat', 'description' => 'Может отправлять сообщения'],
        ['code' => 'chat.edit', 'name' => 'Редактирование сообщений', 'category' => 'chat', 'description' => 'Может редактировать свои сообщения'],
        ['code' => 'chat.delete', 'name' => 'Удаление сообщений', 'category' => 'chat', 'description' => 'Может удалять сообщения'],
        ['code' => 'chat.forward', 'name' => 'Пересылка сообщений', 'category' => 'chat', 'description' => 'Может пересылать сообщения'],
        ['code' => 'chat.create_group', 'name' => 'Создание групп', 'category' => 'chat', 'description' => 'Может создавать групповые чаты'],

        // Администрирование
        ['code' => 'admin.full', 'name' => 'Полный доступ (админ)', 'category' => 'admin', 'description' => 'Доступ ко всем административным разделам и операциям'],

        // CRM
        ['code' => 'crm.view', 'name' => 'Просмотр CRM', 'category' => 'crm', 'description' => 'Может просматривать CRM'],
        ['code' => 'crm.create', 'name' => 'Создание в CRM', 'category' => 'crm', 'description' => 'Может создавать клиентов/сделки'],
        ['code' => 'crm.edit', 'name' => 'Редактирование CRM', 'category' => 'crm', 'description' => 'Может редактировать клиентов/сделки'],
        ['code' => 'crm.delete', 'name' => 'Удаление в CRM', 'category' => 'crm', 'description' => 'Может удалять клиентов/сделки'],
        ['code' => 'crm.export', 'name' => 'Экспорт CRM', 'category' => 'crm', 'description' => 'Может экспортировать клиентов/сделки'],
        ['code' => 'crm.stages.manage', 'name' => 'Управление этапами CRM', 'category' => 'crm', 'description' => 'Может создавать/редактировать/удалять этапы и менять порядок'],
        
        // Руководитель
        ['code' => 'leader.view', 'name' => 'Просмотр дашборда руководителя', 'category' => 'leader', 'description' => 'Может просматривать дашборд руководителя'],
        ['code' => 'leader.shifts.manage', 'name' => 'Управление сменами', 'category' => 'leader', 'description' => 'Может управлять сменами сотрудников'],
        ['code' => 'leader.export', 'name' => 'Экспорт отчётов', 'category' => 'leader', 'description' => 'Может экспортировать отчёты по сменам и задачам']
    ];
    
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (code, name, category, description)
        VALUES (?, ?, ?, ?)
    ");
    
    foreach ($permissions as $perm) {
        $stmt->execute([$perm['code'], $perm['name'], $perm['category'], $perm['description']]);
    }

    // ============================================
    // RBAC: привязка прав к ролям (role_permissions)
    // ============================================

    // root получает admin.full (остальное root покрывает break-glass-логикой в API)
    // manager/employee/leader — соответствующие наборы прав
    $rolePermissionMap = [
        'root' => [
            'admin.full'
        ],
        'administrator' => [
            'admin.full'
        ],
        'leader' => [
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
            'departments.view', 'departments.create', 'departments.edit', 'departments.delete',
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'crm.view', 'crm.create', 'crm.edit', 'crm.delete', 'crm.export', 'crm.stages.manage',
            'leader.view', 'leader.shifts.manage', 'leader.export'
        ],
        'employee' => [
            'tasks.view', 'tasks.create', 'tasks.edit',
            'projects.view', 'projects.create', 'projects.edit',
            'departments.view',
            'users.view',
            'crm.view', 'crm.create', 'crm.edit'
        ]
    ];

    $stmtRoleId = $pdo->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
    $stmtPermId = $pdo->prepare("SELECT id FROM permissions WHERE code = ? LIMIT 1");
    $stmtInsertRp = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($rolePermissionMap as $roleName => $permCodes) {
        $stmtRoleId->execute([$roleName]);
        $roleId = $stmtRoleId->fetchColumn();
        if (!$roleId) continue;

        foreach ($permCodes as $code) {
            $stmtPermId->execute([$code]);
            $permId = $stmtPermId->fetchColumn();
            if (!$permId) continue;
            $stmtInsertRp->execute([$roleId, $permId]);
        }
    }

    // ============================================
    // ТЕСТОВЫЕ ДАННЫЕ: Услуги (активности)
    // ============================================

    // Типы услуг
    $pdo->exec("
        INSERT INTO booking_service_types (type_key, type_name, icon, description, sort_order, is_active) VALUES
        ('snowmobile', 'Снегоходы', 'snowflake', 'Прокат и экскурсии на снегоходах', 1, 1),
        ('bbq', 'Мангалы и барбекю', 'flame', 'Аренда мангалов и наборы для барбекю', 2, 1),
        ('bike', 'Велосипеды', 'bicycle', 'Прокат велосипедов и велопрогулки', 3, 1),
        ('tour', 'Экскурсии', 'map', 'Организованные экскурсии и туры', 4, 1),
        ('equipment', 'Спортивный инвентарь', 'tool', 'Прокат лыж, тюбингов и другого инвентаря', 5, 1),
        ('other', 'Другие услуги', 'star', 'Дополнительные услуги', 6, 1)
        ON DUPLICATE KEY UPDATE type_name=VALUES(type_name)
    ");

    // Дополнительные услуги
    $pdo->exec("
        INSERT INTO booking_extra_services (type_id, service_name, description, base_price, unit, min_quantity, max_quantity, is_active, sort_order)
        SELECT st.id, svc.service_name, svc.description, svc.base_price, svc.unit, svc.min_quantity, svc.max_quantity, svc.is_active, svc.sort_order
        FROM (
            SELECT 'snowmobile' as type_key, 'Прокат снегохода' as service_name, 'Прокат снегохода для поездок по лесу' as description, 5000 as base_price, 'hour' as unit, 1 as min_quantity, 5 as max_quantity, 1 as is_active, 1 as sort_order
            UNION ALL SELECT 'snowmobile', 'Экскурсия на снегоходах', 'Групповая экскурсия на снегоходах по живописным местам', 8000, 'person', 1, 10, 1, 2
            UNION ALL SELECT 'bbq', 'Аренда мангала', 'Мангал для приготовления шашлыков', 1000, 'day', 1, 3, 1, 3
            UNION ALL SELECT 'bbq', 'Набор для барбекю', 'Решетка, шампуры, уголь, розжиг', 500, 'piece', 1, 5, 1, 4
            UNION ALL SELECT 'bbq', 'Дрова для камина', 'Охапка дров для камина или костра', 300, 'piece', 1, 10, 1, 5
            UNION ALL SELECT 'bike', 'Прокат велосипедов', 'Горный велосипед для прогулок', 800, 'day', 1, 10, 1, 6
            UNION ALL SELECT 'tour', 'Экскурсия на квадроциклах', 'Путешествие на квадроциклах по бездорожью', 6000, 'person', 1, 8, 1, 7
            UNION ALL SELECT 'tour', 'Рыболовная экскурсия', 'Рыбалка с инструктором', 4000, 'person', 1, 6, 1, 8
            UNION ALL SELECT 'equipment', 'Аренда лыж', 'Комплект лыж с ботинками', 500, 'day', 1, 10, 1, 9
            UNION ALL SELECT 'equipment', 'Аренда тюбинга', 'Ватрушка для катания с горки', 300, 'day', 1, 10, 1, 10
            UNION ALL SELECT 'other', 'Баня на дровах', 'Русская баня на дровах (до 4 человек)', 3000, 'hour', 1, 5, 1, 11
            UNION ALL SELECT 'other', 'Завтрак в номер', 'Континентальный завтрак в номер', 1500, 'person', 1, 10, 1, 12
        ) as svc
        JOIN booking_service_types st ON svc.type_key = st.type_key
        ON DUPLICATE KEY UPDATE service_name=VALUES(service_name)
    ");

}

function createBootstrapLock($host, $user, $pass, $dbname, $licenseDomain = ''): void {
    $jwtSecret = bin2hex(random_bytes(32));
    $licenseDomain = trim((string)$licenseDomain);
    $appEncKey = bin2hex(random_bytes(32));
    $lockDir = __DIR__ . '/runtime';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $lockPath = $lockDir . '/install.lock';
    $payload = [
        'installed_at' => gmdate('c'),
        'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
        'db_host' => $host,
        'db_name' => $dbname,
        'db_user' => $user,
        'db_pass' => $pass,
        'jwt_secret' => $jwtSecret,
        'app_enc_key' => $appEncKey,
        'license_domain' => $licenseDomain,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать bootstrap lock');
    }

    if (file_put_contents($lockPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать runtime/install.lock');
    }

    @chmod($lockPath, 0660);
}
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка TaskFlow Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { color-scheme: dark; }
        :root {
            --lg-radius: 16px;
            --lg-radius-lg: 24px;
            --lg-blur: blur(18px);
        }
        .installer-shell {
            width: 100%;
            max-width: 520px;
        }
        .installer-card {
            border-radius: var(--lg-radius-lg);
            box-shadow: var(--lg-shadow-lg);
        }
        .installer-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .installer-logo {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.1);
            box-shadow: 0 18px 52px rgba(0,0,0,.45);
            overflow: hidden;
        }
        .installer-title {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--lg-text-primary);
        }
        .installer-subtitle {
            margin-top: -4px;
            font-size: 13px;
            color: var(--lg-text-secondary);
        }
        .installer-bg {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 14px;
            background:
                radial-gradient(900px 520px at 20% 10%, rgba(10, 132, 255, 0.22), transparent 60%),
                radial-gradient(900px 620px at 80% 20%, rgba(124, 58, 237, 0.20), transparent 55%),
                radial-gradient(780px 680px at 50% 92%, rgba(48, 209, 88, 0.12), transparent 60%),
                var(--lg-bg-primary);
        }
        .installer-input {
            border-radius: var(--lg-radius);
        }

        :root {
            --lg-bg-primary: #0F1117;
            --lg-bg-secondary: #151823;
            --lg-glass-bg: rgba(20, 24, 35, 0.32);
            --lg-glass-bg-hover: rgba(255, 255, 255, 0.07);
            --lg-text-primary: #F5F7FF;
            --lg-text-secondary: rgba(245, 247, 255, 0.68);
            --lg-text-tertiary: rgba(245, 247, 255, 0.48);
            --lg-border: rgba(255, 255, 255, 0.07);
            --lg-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
            --lg-shadow-lg: 0 18px 48px rgba(0, 0, 0, 0.38);
        }

        .liquid-glass-pro,
        .lg-modal-shell {
            position: relative;
            overflow: hidden;
            background: rgba(8, 10, 14, 0.72);
            border: 1px solid rgba(255,255,255,.09);
            box-shadow: var(--lg-shadow-lg);
            backdrop-filter: var(--lg-blur);
            -webkit-backdrop-filter: var(--lg-blur);
        }

        .ios-glass-button {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            color: var(--lg-text-primary);
            transition: background-color .2s ease, border-color .2s ease, transform .2s ease;
        }

        .ios-glass-button:hover {
            background: rgba(255,255,255,.1);
            border-color: rgba(255,255,255,.16);
        }

        .ios-glass-input {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.09);
            color: var(--lg-text-primary);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        }

        .ios-glass-input::placeholder {
            color: var(--lg-text-tertiary);
        }

        /* Prevent any accidental light mode overrides */
        html, body { background: var(--lg-bg-primary) !important; color: var(--lg-text-primary) !important; }

        body.dark .liquid-glass-pro,
        body.dark .liquid-glass-pro.lg-modal-shell {
            background: rgba(8, 10, 14, 0.72) !important;
            border: 1px solid rgba(255,255,255,.09) !important;
        }

        /* Ensure no light defaults leak into installer */
        .installer-card {
            box-shadow: var(--lg-shadow-lg) !important;
            backdrop-filter: var(--lg-blur);
            -webkit-backdrop-filter: var(--lg-blur);
        }

        /* Dark transparent shell like the main app */
        .installer-glass {
            /* Keep the installer card contrasty without depending on external glass styles */
            background:
                linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.015)),
                rgba(10, 12, 18, 0.52) !important;
            border: 1px solid rgba(255,255,255,.09) !important;
            box-shadow:
                0 26px 78px rgba(0,0,0,.62),
                inset 0 1px 0 rgba(255,255,255,.10),
                inset 0 0 0 1px rgba(255,255,255,.04);
        }

        /* The screenshot shows light-ish surfaces -> enforce a darker glass base */
        .installer-glass {
            background:
                radial-gradient(120% 140% at 18% 10%, rgba(10,132,255,.20), transparent 58%),
                radial-gradient(120% 140% at 82% 0%, rgba(124,58,237,.16), transparent 58%),
                linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.01)),
                rgba(8, 10, 14, 0.72) !important;
        }

        .installer-glass::before,
        .installer-glass::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            pointer-events: none;
            z-index: 0;
        }

        .installer-glass::before {
            background:
                radial-gradient(120% 140% at 18% 10%, rgba(10,132,255,.22), transparent 58%),
                radial-gradient(120% 140% at 82% 0%, rgba(124,58,237,.18), transparent 58%),
                radial-gradient(120% 140% at 50% 110%, rgba(48,209,88,.10), transparent 60%);
            opacity: .9;
        }

        .installer-glass::after {
            background: linear-gradient(180deg, rgba(255,255,255,.10), transparent 46%, rgba(0,0,0,.10));
            opacity: .35;
        }

        .installer-glass > * {
            position: relative;
            z-index: 1;
        }

        /* Chrome autofill can make inputs look "light" */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        textarea:-webkit-autofill,
        textarea:-webkit-autofill:hover,
        textarea:-webkit-autofill:focus,
        select:-webkit-autofill,
        select:-webkit-autofill:hover,
        select:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--lg-text-primary) !important;
            transition: background-color 9999s ease-in-out 0s;
            box-shadow: 0 0 0px 1000px rgba(8, 10, 14, 0.55) inset !important;
            border: 1px solid rgba(255,255,255,.10) !important;
        }
        .installer-card input,
        .installer-card select,
        .installer-card textarea {
            color: var(--lg-text-primary);
        }
        .installer-card label {
            color: var(--lg-text-secondary) !important;
        }
        .installer-card input::placeholder {
            color: var(--lg-text-tertiary);
        }
        .installer-note code {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 10px;
            padding: 2px 8px;
        }
    </style>
</head>
<body class="installer-bg">
    <div class="installer-shell">
        <div class="installer-hero">
            <div class="installer-logo">
                <img src="app.png" alt="TaskFlow Pro" class="w-16 h-16 object-contain">
            </div>
            <div class="installer-title">TaskFlow Pro</div>
            <div class="installer-subtitle">Настройка рабочего пространства</div>
        </div>

        <?php if ($step === 'form'): ?>
        <div class="liquid-glass-pro lg-modal-shell installer-card installer-glass p-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl border <?= $messageType === 'error' ? 'bg-red-500/15 text-red-200 border-red-500/25' : 'bg-emerald-500/15 text-emerald-200 border-emerald-500/25' ?>">
                <?= $message ?>
            </div>
            <?php endif; ?>

            <div class="mb-6 p-4 rounded-xl border <?= $hasEnvErrors ? 'border-red-500/25 bg-red-500/10' : 'border-emerald-500/25 bg-emerald-500/10' ?>">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold" style="color: var(--lg-text-primary)">Проверка окружения</div>
                    <button type="button"
                            class="px-3 py-2 rounded-xl ios-glass-button text-sm"
                            onclick="document.getElementById('envChecks').classList.toggle('hidden')">
                        Детали
                    </button>
                </div>

                <div id="envChecks" class="hidden mt-3 space-y-2">
                    <?php foreach ($envChecks as $c): ?>
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-sm" style="color: var(--lg-text-primary)">
                                <span class="font-medium"><?= htmlspecialchars($c['name']) ?></span>
                                <div class="text-xs" style="color: var(--lg-text-tertiary)"><?= htmlspecialchars($c['hint']) ?></div>
                            </div>
                            <div class="text-sm font-semibold <?= $c['ok'] ? 'text-emerald-200' : 'text-red-200' ?>">
                                <?= $c['ok'] ? 'OK' : 'FAIL' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($hasEnvErrors): ?>
                    <div class="mt-3 text-xs" style="color: rgba(220,38,38,.9)">Исправьте требования окружения и повторите установку.</div>
                <?php else: ?>
                    <div class="mt-3 text-xs" style="color: rgba(5,150,105,.9)">Сервер готов к установке.</div>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="?step=install" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Хост MySQL</label>
                    <input type="text" name="host" value="db" required
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input" placeholder="db">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Пользователь MySQL</label>
                    <input type="text" name="user" required
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input"
                           placeholder="root">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Пароль базы данных</label>
                    <input type="password" name="pass"
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Имя базы данных</label>
                    <input type="text" name="dbname" value="taskflow" required
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input">
                    <p class="text-xs mt-1" style="color: var(--lg-text-tertiary)">База данных будет создана автоматически.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Пароль для root</label>
                    <input type="password" name="root_pass" required minlength="6"
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input"
                           placeholder="Минимум 6 символов">
                    <p class="text-xs mt-1" style="color: var(--lg-text-tertiary)">Учётная запись администратора: `root`.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--lg-text-secondary)">Лицензия по домену (необязательно)</label>
                    <input type="text" name="license_domain"
                           class="w-full px-4 py-3 rounded-xl ios-glass-input installer-input"
                           placeholder="example.com">
                    <p class="text-xs mt-1" style="color: var(--lg-text-tertiary)">Оставьте поле пустым, если ограничение по домену не требуется.</p>
                </div>

                <button type="submit"
                        class="w-full py-3 px-4 rounded-xl font-semibold ios-glass-button">
                    Установить TaskFlow Pro
                </button>
            </form>

            <div class="mt-6 p-4 rounded-2xl border installer-note" style="background: rgba(47,124,246,.08); border-color: rgba(47,124,246,.14)">
                <p class="text-sm" style="color: var(--lg-text-secondary)">
                    Будет создан пользователь <code class="px-2 py-0.5 rounded border" style="background: rgba(255,255,255,.6); border-color: rgba(148,163,184,.26)">root</code> с указанным паролем.
                </p>
            </div>
        </div>
        
        <?php elseif ($step === 'success'): ?>
        <div class="liquid-glass-pro lg-modal-shell installer-card installer-glass p-8 text-center">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-[26px] mb-6 border" style="background: rgba(255,255,255,.72); border-color: rgba(255,255,255,.7)">
                <img src="app.png" alt="TaskFlow Pro" class="w-12 h-12 object-contain">
            </div>
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full mb-6 -mt-3" style="background: rgba(16,185,129,.16)">
                <svg class="w-10 h-10" style="color: rgba(52,211,153,.95)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            
            <h2 class="text-2xl font-bold mb-2" style="color: var(--lg-text-primary)">Установка завершена!</h2>
            <p class="mb-6" style="color: var(--lg-text-secondary)">Система готова к первому входу</p>

            <div class="rounded-xl p-4 mb-6 border" style="background: rgba(47,124,246,.08); border-color: rgba(47,124,246,.14)">
                <p class="text-sm font-medium mb-2" style="color: var(--lg-text-primary)">Учётная запись администратора создана.</p>
                <p class="text-xs" style="color: var(--lg-text-secondary)">Для входа используйте логин <code class="px-2 py-0.5 rounded border" style="background: rgba(255,255,255,.6); border-color: rgba(148,163,184,.26)">root</code> и указанный пароль.</p>
            </div>

            <a href="index.html"
               class="inline-flex items-center py-3 px-6 rounded-xl font-medium ios-glass-button">
                Перейти к приложению
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                </svg>
            </a>
        </div>
        <?php endif; ?>
        
        <div class="mt-8 text-center">
            <p class="text-sm" style="color: var(--lg-text-tertiary)">TaskFlow Pro © <?= date('Y') ?></p>
        </div>
    </div>
</body>
</html>
