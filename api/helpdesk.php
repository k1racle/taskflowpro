<?php
/**
 * api/helpdesk.php - Управление заявками HelpDesk
 *
 * Endpoints:
 * - POST   /api/helpdesk/tickets          - Создать заявку (публичный + авторизованный)
 * - GET    /api/helpdesk/tickets          - Список заявок (авторизованный)
 * - GET    /api/helpdesk/tickets/:id      - Заявка по ID
 * - PUT    /api/helpdesk/tickets/:id      - Обновить заявку
 * - DELETE /api/helpdesk/tickets/:id      - Удалить заявку
 * - POST   /api/helpdesk/tickets/:id/assign - Назначить ответственного
 * - POST   /api/helpdesk/tickets/:id/status - Изменить статус
 * - POST   /api/helpdesk/tickets/:id/resolve - Завершить заявку
 * - GET    /api/helpdesk/tickets/:id/comments - Комментарии
 * - POST   /api/helpdesk/tickets/:id/comments - Добавить комментарий
 * - GET    /api/helpdesk/tickets/:id/history - История
 * - GET    /api/helpdesk/categories       - Список категорий (публичный)
 * - GET    /api/helpdesk/statuses         - Список статусов (публичный)
 * - GET    /api/helpdesk/stats            - Статистика
 * - POST   /api/helpdesk/tickets/:id/convert - Конвертировать в задачу/клиента/сделку
 */

// Для JSON API не выводим PHP warnings/notices в response body.
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS заголовки для прямого вызова api/helpdesk.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Обработка preflight OPTIONS запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Сначала config.php (чтобы был getPDO)
require_once __DIR__ . '/config.php';

// Потом auth.php (чтобы был getCurrentUser)
require_once __DIR__ . '/auth.php';

function handleHelpdesk(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();

    require_once __DIR__ . '/omnichannel.php';

    try {
        ensureUsersLastActivityColumn($pdo);
    } catch (Exception $e) {
        error_log('helpdesk.php: failed to ensure users.last_activity: ' . $e->getMessage());
    }

    // Для публичных endpoint'ов getCurrentUser() может вернуть null
    if (function_exists('getCurrentUser')) {
        $currentUser = getCurrentUser();
    } else {
        $currentUser = null;
    }

// GET /api/helpdesk/categories - Список категорий (публичный)
if ($method === 'GET' && $action === 'categories' && $id === null) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_categories WHERE is_active = 1 ORDER BY `order`");
        $stmt->execute();
        $categories = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $categories], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// GET /api/helpdesk/statuses - Список статусов (публичный)
if ($method === 'GET' && $action === 'statuses' && $id === null) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM helpdesk_statuses ORDER BY `order`");
        $stmt->execute();
        $statuses = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $statuses], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// GET /api/helpdesk/stats - Статистика
if ($method === 'GET' && $action === 'stats' && $id === null) {
    try {
        $stats = [];
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM helpdesk_tickets");
        $stats['total'] = $stmt->fetch()['count'];
        echo json_encode(['success' => true, 'data' => $stats], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

    $readJsonBody = function(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    };

    $generateTicketNumber = function() use ($pdo): string {
        $prefix = date('Ymd');
        $stmt = $pdo->prepare("SELECT ticket_number FROM helpdesk_tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '-%']);
        $last = $stmt->fetch();

        if ($last && !empty($last['ticket_number'])) {
            // Извлекаем номер после последнего дефиса
            $lastNum = (int)substr(strrchr($last['ticket_number'], '-'), 1);
            $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            // Если за сегодня не было заявок, проверяем вчерашние
            $yesterday = date('Ymd', strtotime('-1 day'));
            $stmt = $pdo->prepare("SELECT ticket_number FROM helpdesk_tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$yesterday . '-%']);
            $lastPrev = $stmt->fetch();
            
            if ($lastPrev && !empty($lastPrev['ticket_number'])) {
                // Берем последний номер и увеличиваем
                $lastNum = (int)substr(strrchr($lastPrev['ticket_number'], '-'), 1);
                $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
            } else {
                // Совсем первая заявка
                $newNum = '0001';
            }
        }

        return $prefix . '-' . $newNum;
    };

    $logHistory = function(int $ticketId, string $action, ?int $userId, ?string $fieldName, $oldValue, $newValue, array $meta = []) use ($pdo) {
        $stmt = $pdo->prepare(" 
            INSERT INTO helpdesk_history (ticket_id, user_id, action, field_name, old_value, new_value, meta)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ticketId,
            $userId,
            $action,
            $fieldName,
            $oldValue !== null ? (is_array($oldValue) ? json_encode($oldValue) : $oldValue) : null,
            $newValue !== null ? (is_array($newValue) ? json_encode($newValue) : $newValue) : null,
            !empty($meta) ? json_encode($meta) : null
        ]);
    };

    $ensureHelpdeskWidgetSupport = function() use ($pdo): int {
        static $ensured = false;
        static $defaultStatusId = 0;

        if ($ensured && $defaultStatusId > 0) {
            return $defaultStatusId;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_statuses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#6B7280',
            `order` INT DEFAULT 0,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (`order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Справочник статусов заявок'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_categories (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Категории заявок'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL,
            is_internal TINYINT(1) DEFAULT 0,
            message TEXT NOT NULL,
            attachments JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            INDEX idx_user (user_id),
            INDEX idx_internal (is_internal),
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Комментарии к заявкам'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            field_name VARCHAR(100) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            meta JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'История изменений заявок'");

        $defaultStatuses = [
            ['name' => 'Новая', 'color' => '#3B82F6', 'order' => 1, 'is_default' => 1],
            ['name' => 'В работе', 'color' => '#F59E0B', 'order' => 2, 'is_default' => 0],
            ['name' => 'Ожидает ответа клиента', 'color' => '#8B5CF6', 'order' => 3, 'is_default' => 0],
            ['name' => 'Ожидает решения', 'color' => '#06B6D4', 'order' => 4, 'is_default' => 0],
            ['name' => 'Решена', 'color' => '#10B981', 'order' => 5, 'is_default' => 0],
            ['name' => 'Закрыта', 'color' => '#6B7280', 'order' => 6, 'is_default' => 0],
            ['name' => 'Отклонена', 'color' => '#EF4444', 'order' => 7, 'is_default' => 0],
        ];
        $statusStmt = $pdo->prepare("INSERT IGNORE INTO helpdesk_statuses (name, color, `order`, is_default) VALUES (?, ?, ?, ?)");
        foreach ($defaultStatuses as $status) {
            $statusStmt->execute([$status['name'], $status['color'], $status['order'], $status['is_default']]);
        }

        $defaultCategories = [
            ['name' => 'Консультация менеджера', 'icon' => 'manager', 'description' => 'Помощь в выборе решения', 'color' => '#3B82F6', 'order' => 1],
            ['name' => 'Заявка на прайс', 'icon' => 'price', 'description' => 'Получить прайс-лист', 'color' => '#10B981', 'order' => 2],
            ['name' => 'Партнерство', 'icon' => 'partnership', 'description' => 'Сотрудничество и партнёрство', 'color' => '#8B5CF6', 'order' => 3],
            ['name' => 'Техподдержка', 'icon' => 'support', 'description' => 'Технические вопросы', 'color' => '#F59E0B', 'order' => 4],
            ['name' => 'Биллинг', 'icon' => 'billing', 'description' => 'Оплата и счета', 'color' => '#EF4444', 'order' => 5],
            ['name' => 'Другое', 'icon' => 'other', 'description' => 'Прочие вопросы', 'color' => '#6B7280', 'order' => 6],
        ];
        $categoryStmt = $pdo->prepare("INSERT IGNORE INTO helpdesk_categories (name, icon, description, color, `order`, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        foreach ($defaultCategories as $category) {
            $categoryStmt->execute([$category['name'], $category['icon'], $category['description'], $category['color'], $category['order']]);
        }

        $defaultStatusId = (int)$pdo->query("SELECT id FROM helpdesk_statuses WHERE is_default = 1 ORDER BY `order` ASC, id ASC LIMIT 1")->fetchColumn();
        if ($defaultStatusId <= 0) {
            $defaultStatusId = (int)$pdo->query("SELECT id FROM helpdesk_statuses ORDER BY `order` ASC, id ASC LIMIT 1")->fetchColumn();
        }

        if ($defaultStatusId <= 0) {
            throw new RuntimeException('Не удалось подготовить справочник статусов HelpDesk');
        }

        $ensured = true;
        return $defaultStatusId;
    };

    $normalizeText = function($value, int $maxLength = 1000): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value ?? '');

        if ($value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    };

    $getClientIp = function(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            foreach (explode(',', $candidate) as $part) {
                $ip = trim($part);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    };

    $checkWidgetRateLimit = function(string $ip) {
        $storageDir = __DIR__ . '/logs/widget-rate-limit';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $file = $storageDir . '/' . sha1($ip) . '.json';
        $now = time();
        $windowSeconds = 600;
        $maxAttempts = 8;
        $minIntervalSeconds = 5;

        $state = ['timestamps' => []];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded) && isset($decoded['timestamps']) && is_array($decoded['timestamps'])) {
                $state = $decoded;
            }
        }

        $timestamps = array_values(array_filter($state['timestamps'], function($ts) use ($now, $windowSeconds) {
            return is_numeric($ts) && ((int)$ts) >= ($now - $windowSeconds);
        }));

        $lastAttempt = !empty($timestamps) ? (int)end($timestamps) : 0;
        if ($lastAttempt > 0 && ($now - $lastAttempt) < $minIntervalSeconds) {
            return [false, 'Слишком частые отправки. Повторите через несколько секунд.'];
        }

        if (count($timestamps) >= $maxAttempts) {
            return [false, 'Превышен лимит заявок. Попробуйте позже.'];
        }

        $timestamps[] = $now;
        @file_put_contents($file, json_encode(['timestamps' => $timestamps], JSON_UNESCAPED_UNICODE));

        return [true, null];
    };

    $containsSuspiciousContent = function(?string $value): bool {
        if ($value === null || $value === '') {
            return false;
        }

        if (preg_match('/https?:\/\//iu', $value)) {
            return true;
        }

        if (preg_match('/\b(?:viagra|casino|loan|crypto|porn)\b/iu', $value)) {
            return true;
        }

        return false;
    };

    $resolveWidgetCategoryId = function(?string $topic) use ($pdo, $normalizeText, $ensureHelpdeskWidgetSupport): ?int {
        $ensureHelpdeskWidgetSupport();
        $topic = $normalizeText($topic, 100);
        if ($topic === null) {
            return null;
        }

        $normalizedTopic = function_exists('mb_strtolower') ? mb_strtolower($topic) : strtolower($topic);
        $topicMap = [
            'collaboration' => ['партнер', 'сотруднич'],
            'problem' => ['техподдерж', 'проблем', 'поддерж'],
            'service' => ['консульта', 'услуг', 'менеджер'],
            'other' => ['другое']
        ];

        $keywords = $topicMap[$normalizedTopic] ?? [$normalizedTopic];

        try {
            $stmt = $pdo->prepare("SELECT id, name, description FROM helpdesk_categories WHERE is_active = 1 ORDER BY `order`");
            $stmt->execute();
            $categories = $stmt->fetchAll();

            foreach ($categories as $category) {
                $haystack = trim(($category['name'] ?? '') . ' ' . ($category['description'] ?? ''));
                $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);

                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && strpos($haystack, $keyword) !== false) {
                        return (int)$category['id'];
                    }
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    };

    $ensureChatRoomsTable = function() use ($pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
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
            FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    };

    $ensureHelpdeskTicketsTable = function() use ($pdo, $ensureHelpdeskWidgetSupport): void {
        $ensureHelpdeskWidgetSupport();

        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_tickets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Заявки клиентов'");
    };

    $ensureWidgetChatSessionSchema = function() use ($pdo): void {
        static $schemaChecked = false;

        if ($schemaChecked) {
            return;
        }

        $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($databaseName === '') {
            throw new RuntimeException('Не удалось определить текущую БД для widget_chat_sessions');
        }

        $columnStmt = $pdo->prepare(" 
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'widget_chat_sessions' AND COLUMN_NAME = 'guest_user_id'
            LIMIT 1
        ");
        $columnStmt->execute([$databaseName]);
        $column = $columnStmt->fetch();

        if (!$column) {
            throw new RuntimeException('Колонка widget_chat_sessions.guest_user_id не найдена');
        }

        $fkStmt = $pdo->prepare(" 
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
               AND rc.TABLE_NAME = kcu.TABLE_NAME
               AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME = 'widget_chat_sessions'
              AND kcu.COLUMN_NAME = 'guest_user_id'
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME ASC
        ");
        $fkStmt->execute([$databaseName]);
        $foreignKeys = $fkStmt->fetchAll();

        error_log('helpdesk.php ensureWidgetChatSessionSchema before: ' . json_encode([
            'is_nullable' => $column['IS_NULLABLE'] ?? null,
            'column_type' => $column['COLUMN_TYPE'] ?? null,
            'foreign_keys' => array_map(static function(array $fk): array {
                return [
                    'name' => $fk['CONSTRAINT_NAME'] ?? null,
                    'referenced_table' => $fk['REFERENCED_TABLE_NAME'] ?? null,
                    'referenced_column' => $fk['REFERENCED_COLUMN_NAME'] ?? null,
                    'delete_rule' => $fk['DELETE_RULE'] ?? null,
                ];
            }, $foreignKeys),
        ], JSON_UNESCAPED_UNICODE));

        $droppedForeignKeys = [];
        foreach ($foreignKeys as $foreignKey) {
            $constraintName = $foreignKey['CONSTRAINT_NAME'] ?? '';
            if ($constraintName === '') {
                continue;
            }

            $pdo->exec("ALTER TABLE widget_chat_sessions DROP FOREIGN KEY `{$constraintName}`");
            $droppedForeignKeys[] = $constraintName;
        }

        $alterExecuted = false;
        if (($column['IS_NULLABLE'] ?? 'NO') !== 'YES') {
            $pdo->exec("ALTER TABLE widget_chat_sessions MODIFY COLUMN guest_user_id INT NULL");
            $alterExecuted = true;
        }

        $fkStmt->execute([$databaseName]);
        $remainingForeignKeys = $fkStmt->fetchAll();
        $hasExpectedForeignKey = false;
        foreach ($remainingForeignKeys as $foreignKey) {
            if (($foreignKey['REFERENCED_TABLE_NAME'] ?? null) === 'users'
                && ($foreignKey['REFERENCED_COLUMN_NAME'] ?? null) === 'id'
                && strtoupper((string)($foreignKey['DELETE_RULE'] ?? '')) === 'SET NULL') {
                $hasExpectedForeignKey = true;
                break;
            }
        }

        $foreignKeyAdded = false;
        if (!$hasExpectedForeignKey) {
            $pdo->exec("ALTER TABLE widget_chat_sessions ADD CONSTRAINT fk_widget_chat_sessions_guest FOREIGN KEY (guest_user_id) REFERENCES users(id) ON DELETE SET NULL");
            $foreignKeyAdded = true;
        }

        $columnStmt->execute([$databaseName]);
        $finalColumn = $columnStmt->fetch();
        $fkStmt->execute([$databaseName]);
        $finalForeignKeys = $fkStmt->fetchAll();

        error_log('helpdesk.php ensureWidgetChatSessionSchema after: ' . json_encode([
            'is_nullable' => $finalColumn['IS_NULLABLE'] ?? null,
            'column_type' => $finalColumn['COLUMN_TYPE'] ?? null,
            'dropped_foreign_keys' => $droppedForeignKeys,
            'alter_executed' => $alterExecuted,
            'foreign_key_added' => $foreignKeyAdded,
            'foreign_keys' => array_map(static function(array $fk): array {
                return [
                    'name' => $fk['CONSTRAINT_NAME'] ?? null,
                    'referenced_table' => $fk['REFERENCED_TABLE_NAME'] ?? null,
                    'referenced_column' => $fk['REFERENCED_COLUMN_NAME'] ?? null,
                    'delete_rule' => $fk['DELETE_RULE'] ?? null,
                ];
            }, $finalForeignKeys),
        ], JSON_UNESCAPED_UNICODE));

        if (($finalColumn['IS_NULLABLE'] ?? 'NO') !== 'YES') {
            throw new RuntimeException('Не удалось перевести widget_chat_sessions.guest_user_id в NULL');
        }

        $schemaChecked = true;
    };

    $ensureWidgetChatSessionsTable = function() use ($pdo, $ensureChatRoomsTable, $ensureHelpdeskTicketsTable, $ensureWidgetChatSessionSchema): void {
        $ensureChatRoomsTable();
        $ensureHelpdeskTicketsTable();

        $pdo->exec("CREATE TABLE IF NOT EXISTS widget_chat_sessions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Публичные сессии чата виджета сайта'");

        $ensureWidgetChatSessionSchema();
    };

    $ensureUsersLastActivityColumn = function() use ($pdo): void {
        ensureUsersLastActivityColumn($pdo);
    };

    $findWidgetOperatorId = function() use ($pdo): ?int {
        // Prefer break-glass root, then any user with admin.full, then a plain fallback
        // so widget ticket creation still works on sparse installations.
        $candidates = [
            "SELECT id FROM users WHERE role = 'root' ORDER BY id ASC LIMIT 1",
            "SELECT DISTINCT u.id FROM users u JOIN roles r ON r.name = u.role JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'admin.full' ORDER BY u.id ASC LIMIT 1",
            "SELECT id FROM users ORDER BY id ASC LIMIT 1"
        ];

        foreach ($candidates as $sql) {
            try {
                $value = $pdo->query($sql)->fetchColumn();
                if ($value) {
                    return (int)$value;
                }
            } catch (Throwable $e) {
                // best-effort fallback only
            }
        }

        return null;
    };

    $createWidgetChatTicket = function(array $data, int $operatorUserId) use ($pdo, $generateTicketNumber, $resolveWidgetCategoryId, $logHistory, $ensureHelpdeskWidgetSupport) {
        $ticketNumber = $generateTicketNumber();
        $defaultStatusId = $ensureHelpdeskWidgetSupport();
        $topic = $data['topic'] ?? 'other';
        $categoryId = $resolveWidgetCategoryId($topic);
        $subject = 'Чат с сайта: ' . (($data['page_title'] ?? '') ?: ($data['visitor_name'] ?? 'Новый диалог'));

        $descriptionParts = [];
        if (!empty($data['first_message'])) {
            $descriptionParts[] = 'Первое сообщение: ' . $data['first_message'];
        }
        if (!empty($data['visitor_company'])) {
            $descriptionParts[] = 'Компания: ' . $data['visitor_company'];
        }
        if (!empty($data['visitor_phone'])) {
            $descriptionParts[] = 'Телефон: ' . $data['visitor_phone'];
        }
        if (!empty($data['visitor_email'])) {
            $descriptionParts[] = 'Email: ' . $data['visitor_email'];
        }
        if (!empty($data['page_title'])) {
            $descriptionParts[] = 'Страница: ' . $data['page_title'];
        }
        if (!empty($data['page_url'])) {
            $descriptionParts[] = 'URL: ' . $data['page_url'];
        }

        $stmt = $pdo->prepare(" 
            INSERT INTO helpdesk_tickets
            (ticket_number, client_name, client_email, client_phone, client_company, category_id,
             status_id, subject, description, priority, source, assigned_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ticketNumber,
            $data['visitor_name'] ?? 'Посетитель сайта',
            $data['visitor_email'] ?? null,
            $data['visitor_phone'] ?? null,
            $data['visitor_company'] ?? null,
            $categoryId,
            $defaultStatusId,
            $subject,
            implode("\n", $descriptionParts),
            'medium',
            'widget-chat',
            $operatorUserId
        ]);

            $ticketId = (int)$pdo->lastInsertId();
            $logHistory($ticketId, 'created', null, null, null, null, ['source' => 'widget-chat']);

            createNotification($pdo, [
                'user_id' => $operatorUserId,
                'sender_id' => null,
                'message' => 'Новая заявка HelpDesk из чата сайта',
                'type' => 'helpdesk',
                'related_id' => $ticketId,
                'allow_self' => true,
            ]);

        if (!empty($data['first_message'])) {
            $commentStmt = $pdo->prepare("
                INSERT INTO helpdesk_comments (ticket_id, user_id, is_internal, message, attachments)
                VALUES (?, ?, 0, ?, NULL)
            ");
            $commentStmt->execute([$ticketId, null, $data['first_message']]);
            $logHistory($ticketId, 'comment', null, null, null, (int)$pdo->lastInsertId(), ['source' => 'widget-chat']);
        }

        return ['id' => $ticketId, 'ticket_number' => $ticketNumber];
    };

    $syncWidgetChatToHelpdesk = function(int $ticketId, string $message, bool $fromOperator, ?int $userId) use ($pdo, $logHistory): void {
        $commentStmt = $pdo->prepare("
            INSERT INTO helpdesk_comments (ticket_id, user_id, is_internal, message, attachments)
            VALUES (?, ?, ?, ?, NULL)
        ");
        $commentStmt->execute([
            $ticketId,
            $fromOperator ? $userId : null,
            0,
            $message
        ]);
        $logHistory($ticketId, 'comment', $fromOperator ? $userId : null, null, null, (int)$pdo->lastInsertId(), [
            'source' => $fromOperator ? 'widget-chat-operator' : 'widget-chat-visitor'
        ]);
    };

    $loadWidgetChatSessionByToken = function(string $token) use ($pdo, $ensureWidgetChatSessionsTable): ?array {
        $ensureWidgetChatSessionsTable();
        $stmt = $pdo->prepare("SELECT * FROM widget_chat_sessions WHERE public_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        return $session ?: null;
    };

    $loadWidgetChatSessionByRoomId = function(int $roomId) use ($pdo, $ensureWidgetChatSessionsTable): ?array {
        $ensureWidgetChatSessionsTable();
        $stmt = $pdo->prepare("SELECT * FROM widget_chat_sessions WHERE room_id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        $session = $stmt->fetch();
        return $session ?: null;
    };

    // POST /api/helpdesk/widget-chat/session - старт публичной чат-сессии виджета
    if ($method === 'POST' && $action === 'widget-chat' && $id === 'session') {
        try {
            $stage = 'read-body';
            $data = $readJsonBody();

            $honeypot = $normalizeText($data['website'] ?? null, 255);
            if ($honeypot !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Не удалось обработать чат'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ip = $getClientIp();
            [$allowedByRateLimit, $rateLimitError] = $checkWidgetRateLimit($ip);
            if (!$allowedByRateLimit) {
                http_response_code(429);
                echo json_encode(['success' => false, 'error' => $rateLimitError], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $visitorName = $normalizeText($data['name'] ?? null, 255);
            $visitorEmail = $normalizeText($data['email'] ?? null, 255);
            $visitorPhone = $normalizeText($data['phone'] ?? null, 50);
            $visitorCompany = $normalizeText($data['company'] ?? null, 255);
            $firstMessage = $normalizeText($data['message'] ?? $data['question'] ?? null, 5000);
            $pageUrl = $normalizeText($data['page_url'] ?? null, 1000);
            $pageTitle = $normalizeText($data['page_title'] ?? null, 255);
            $profileSlug = $normalizeText($data['profile'] ?? null, 120);
            $topic = $normalizeText($data['topic'] ?? 'other', 100) ?? 'other';

            if ($visitorName === null || $firstMessage === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите имя и первое сообщение'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($visitorEmail === null && $visitorPhone === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите телефон или email'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($visitorEmail !== null && !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите корректный email'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($containsSuspiciousContent($visitorName) || $containsSuspiciousContent($visitorCompany) || $containsSuspiciousContent($firstMessage)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Чат отклонен проверкой содержимого'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $operatorUserId = $findWidgetOperatorId();
            if (!$operatorUserId) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Не найден оператор для обработки чата'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stage = 'ensure-helpdesk-support';
            $ensureHelpdeskWidgetSupport();

            $stage = 'create-public-token';
            $publicToken = bin2hex(random_bytes(24));

            $stage = 'create-chat-room';
            $roomStmt = $pdo->prepare(" 
                INSERT INTO chat_rooms (type, user1_id, user2_id, created_by)
                VALUES ('private', ?, ?, ?)
            ");
            $roomStmt->execute([$operatorUserId, null, $operatorUserId]);
            $roomId = (int)$pdo->lastInsertId();

            $stage = 'create-helpdesk-ticket';
            $ticket = $createWidgetChatTicket([
                'visitor_name' => $visitorName,
                'visitor_email' => $visitorEmail,
                'visitor_phone' => $visitorPhone,
                'visitor_company' => $visitorCompany,
                'page_url' => $pageUrl,
                'page_title' => $pageTitle,
                'first_message' => $firstMessage,
                'topic' => $topic
            ], $operatorUserId);

            $stage = 'ensure-widget-chat-schema';
            $ensureWidgetChatSessionsTable();

            $stage = 'create-widget-session';
            $sessionStmt = $pdo->prepare(" 
                INSERT INTO widget_chat_sessions
                (public_token, profile_slug, room_id, ticket_id, guest_user_id, operator_user_id,
                 visitor_name, visitor_email, visitor_phone, visitor_company, page_url, page_title,
                 last_guest_message_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $sessionStmt->execute([
                $publicToken,
                $profileSlug,
                $roomId,
                $ticket['id'],
                null,
                $operatorUserId,
                $visitorName,
                $visitorEmail,
                $visitorPhone,
                $visitorCompany,
                $pageUrl,
                $pageTitle
            ]);

            $stage = 'create-first-chat-message';
            $insertMessageStmt = $pdo->prepare(" 
                INSERT INTO chat_messages (room_id, sender_id, recipient_id, message, message_type, is_read, status)
                VALUES (?, ?, ?, ?, 'text', 0, 'delivered')
            ");
            $insertMessageStmt->execute([$roomId, $operatorUserId, $operatorUserId, $firstMessage]);

            $stage = 'notify-operator';
            createNotification($pdo, [
                'user_id' => (int)$operatorUserId,
                'sender_id' => null,
                'message' => 'Новый диалог с сайта',
                'type' => 'chat',
                'related_id' => (int)$roomId,
            ]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'token' => $publicToken,
                    'room_id' => $roomId,
                    'ticket_id' => $ticket['id'],
                    'ticket_number' => $ticket['ticket_number']
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            if (in_array(($stage ?? null), ['ensure-widget-chat-schema', 'create-widget-session'], true)) {
                try {
                    $databaseName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
                    if ($databaseName !== '') {
                        $schemaStmt = $pdo->prepare(" 
                            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                            FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'widget_chat_sessions' AND COLUMN_NAME = 'guest_user_id'
                            LIMIT 1
                        ");
                        $schemaStmt->execute([$databaseName]);
                        $guestUserColumn = $schemaStmt->fetch();

                        $fkStmt = $pdo->prepare(" 
                            SELECT
                                kcu.CONSTRAINT_NAME,
                                kcu.REFERENCED_TABLE_NAME,
                                kcu.REFERENCED_COLUMN_NAME,
                                rc.DELETE_RULE
                            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                            LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                                ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                               AND rc.TABLE_NAME = kcu.TABLE_NAME
                               AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                            WHERE kcu.TABLE_SCHEMA = ?
                              AND kcu.TABLE_NAME = 'widget_chat_sessions'
                              AND kcu.COLUMN_NAME = 'guest_user_id'
                              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                            ORDER BY kcu.CONSTRAINT_NAME ASC
                        ");
                        $fkStmt->execute([$databaseName]);
                        $foreignKeys = $fkStmt->fetchAll();

                        error_log('helpdesk.php widget-chat/session guest_user_id contract: ' . json_encode([
                            'is_nullable' => $guestUserColumn['IS_NULLABLE'] ?? null,
                            'column_type' => $guestUserColumn['COLUMN_TYPE'] ?? null,
                            'column_default' => $guestUserColumn['COLUMN_DEFAULT'] ?? null,
                            'foreign_keys' => array_map(static function(array $fk): array {
                                return [
                                    'name' => $fk['CONSTRAINT_NAME'] ?? null,
                                    'referenced_table' => $fk['REFERENCED_TABLE_NAME'] ?? null,
                                    'referenced_column' => $fk['REFERENCED_COLUMN_NAME'] ?? null,
                                    'delete_rule' => $fk['DELETE_RULE'] ?? null,
                                ];
                            }, $foreignKeys),
                        ], JSON_UNESCAPED_UNICODE));
                    }
                } catch (Exception $schemaException) {
                    error_log('helpdesk.php widget-chat/session schema inspect failed: ' . $schemaException->getMessage());
                }
            }
            error_log('helpdesk.php widget-chat/session failed at ' . ($stage ?? 'unknown') . ': ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка запуска чата на этапе ' . ($stage ?? 'unknown') . ': ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // GET /api/helpdesk/widget-chat/messages?token=... - сообщения публичной сессии
    if ($method === 'GET' && $action === 'widget-chat' && $id === 'messages') {
        $token = $normalizeText($_GET['token'] ?? null, 64);
        if ($token === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите token'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $session = $loadWidgetChatSessionByToken($token);
        if (!$session) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Сессия чата не найдена'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $roomId = (int)$session['room_id'];
            $stmt = $pdo->prepare("
                SELECT id, sender_id, recipient_id, message, created_at
                FROM chat_messages
                WHERE room_id = ? AND deleted_at IS NULL
                ORDER BY id ASC
            LIMIT 200
        ");
        $stmt->execute([$roomId]);
        $messages = [];
            foreach ($stmt->fetchAll() as $row) {
                $isVisitorMessage = (
                    (int)$row['sender_id'] === (int)$session['operator_user_id']
                    && (int)$row['recipient_id'] === (int)$session['operator_user_id']
                );
                $messages[] = [
                    'id' => (int)$row['id'],
                    'message' => (string)$row['message'],
                    'created_at' => $row['created_at'],
                    'is_operator' => !$isVisitorMessage && ((int)$row['sender_id'] === (int)$session['operator_user_id'])
                ];
            }

        echo json_encode([
            'success' => true,
            'data' => [
                'room_id' => $roomId,
                'ticket_id' => (int)$session['ticket_id'],
                'messages' => $messages
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST /api/helpdesk/widget-chat/messages - отправка сообщения посетителем
    if ($method === 'POST' && $action === 'widget-chat' && $id === 'messages') {
        try {
            $data = $readJsonBody();
            $token = $normalizeText($data['token'] ?? null, 64);
            $message = $normalizeText($data['message'] ?? null, 5000);
            if ($token === null || $message === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите token и message'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $session = $loadWidgetChatSessionByToken($token);
            if (!$session) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Сессия чата не найдена'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($containsSuspiciousContent($message)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Сообщение отклонено проверкой содержимого'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO chat_messages (room_id, sender_id, recipient_id, message, message_type, is_read, status)
                VALUES (?, ?, ?, ?, 'text', 0, 'delivered')
            ");
            $stmt->execute([
                (int)$session['room_id'],
                (int)$session['operator_user_id'],
                (int)$session['operator_user_id'],
                $message
            ]);

            $pdo->prepare("UPDATE widget_chat_sessions SET last_guest_message_at = NOW() WHERE id = ?")
                ->execute([(int)$session['id']]);

            if (!empty($session['ticket_id'])) {
                $syncWidgetChatToHelpdesk((int)$session['ticket_id'], $message, false, null);
            }

            createNotification($pdo, [
                'user_id' => (int)$session['operator_user_id'],
                'sender_id' => null,
                'message' => 'Новое сообщение из виджета сайта',
                'type' => 'chat',
                'related_id' => (int)$session['room_id'],
            ]);

            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка отправки сообщения: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ============================================
    // ПУБЛИЧНЫЕ ENDPOINTS (без авторизации)
    // ============================================

    // POST /api/helpdesk/widget-ticket - Создать заявку из внешнего виджета
    if ($method === 'POST' && $action === 'widget-ticket' && $id === null) {
        try {
            $stage = 'read-body';
            $data = $readJsonBody();

            $honeypot = $normalizeText($data['website'] ?? $data['company_site'] ?? null, 255);
            if ($honeypot !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Не удалось обработать заявку'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ip = $getClientIp();
            [$allowedByRateLimit, $rateLimitError] = $checkWidgetRateLimit($ip);
            if (!$allowedByRateLimit) {
                http_response_code(429);
                echo json_encode(['success' => false, 'error' => $rateLimitError], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $clientName = $normalizeText($data['name'] ?? $data['client_name'] ?? null, 255);
            $clientCompany = $normalizeText($data['company'] ?? $data['client_company'] ?? null, 255);
            $clientPhone = $normalizeText($data['phone'] ?? $data['client_phone'] ?? null, 50);
            $clientEmail = $normalizeText($data['email'] ?? $data['client_email'] ?? null, 255);
            $question = $normalizeText($data['question'] ?? $data['message'] ?? $data['description'] ?? null, 5000);
            $topic = $normalizeText($data['topic'] ?? 'collaboration', 100) ?? 'collaboration';
            $scenario = $normalizeText($data['scenario'] ?? 'request', 100) ?? 'request';
            $pageUrl = $normalizeText($data['page_url'] ?? null, 1000);
            $pageTitle = $normalizeText($data['page_title'] ?? null, 255);
            $subject = $normalizeText($data['subject'] ?? null, 255);

            if ($clientName === null || $question === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите имя и текст обращения'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($clientPhone === null && $clientEmail === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите телефон или email для обратной связи'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($question !== null) {
                $questionLength = function_exists('mb_strlen') ? mb_strlen($question) : strlen($question);
                if ($questionLength < 10) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Опишите запрос чуть подробнее'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            if ($clientName !== null) {
                $nameLength = function_exists('mb_strlen') ? mb_strlen($clientName) : strlen($clientName);
                if ($nameLength < 2) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Укажите корректное имя'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            if ($clientEmail !== null && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите корректный email'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($clientPhone !== null) {
                $digits = preg_replace('/\D+/', '', $clientPhone);
                if (strlen($digits) < 6) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Укажите корректный телефон'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            if ($containsSuspiciousContent($clientName) || $containsSuspiciousContent($clientCompany) || $containsSuspiciousContent($question)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Заявка отклонена проверкой содержимого'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($subject === null) {
                $topicLabels = [
                    'collaboration' => 'Сотрудничество',
                    'problem' => 'Проблема',
                    'service' => 'Вопрос по услуге',
                    'other' => 'Другое'
                ];
                $subject = ($scenario === 'chat' ? 'Обращение из виджета чата' : 'Заявка с сайта') . ': ' . ($topicLabels[$topic] ?? $topic);
            }

            $descriptionParts = [$question];

            if ($clientCompany !== null) {
                $descriptionParts[] = 'Компания: ' . $clientCompany;
            }

            if ($clientPhone !== null) {
                $descriptionParts[] = 'Телефон: ' . $clientPhone;
            }

            if ($clientEmail !== null) {
                $descriptionParts[] = 'Email: ' . $clientEmail;
            }

            if ($pageTitle !== null) {
                $descriptionParts[] = 'Страница: ' . $pageTitle;
            }

            if ($pageUrl !== null) {
                $descriptionParts[] = 'URL: ' . $pageUrl;
            }

            $descriptionParts[] = 'Сценарий: ' . $scenario;
            $descriptionParts[] = 'Тема виджета: ' . $topic;

            $stage = 'ensure-helpdesk-support';
            $ticketNumber = $generateTicketNumber();
            $defaultStatusId = $ensureHelpdeskWidgetSupport();

            $stage = 'resolve-category';
            $categoryId = $resolveWidgetCategoryId($topic);
            $source = $scenario === 'chat' ? 'widget-chat' : 'widget-form';

            $stage = 'insert-ticket';
            $stmt = $pdo->prepare(" 
                INSERT INTO helpdesk_tickets
                (ticket_number, client_name, client_email, client_phone, client_company, category_id,
                 status_id, subject, description, priority, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $ticketNumber,
                $clientName,
                $clientEmail,
                $clientPhone,
                $clientCompany,
                $categoryId,
                $defaultStatusId,
                $subject,
                implode("\n", $descriptionParts),
                'medium',
                $source
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            $stage = 'write-history';
            $logHistory($ticketId, 'created', null, null, null, null, ['source' => $source, 'topic' => $topic, 'ip' => $ip]);

            $stage = 'notify-admins';
            // Notify full admins (admin.full) + root users.
            $widgetRecipients = getUserIdsByRoles($pdo, ['root']);
            // Prefer permission-based recipients when RBAC tables are available.
            try {
                $stmt = $pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN roles r ON r.name = u.role JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'admin.full'");
                $stmt->execute();
                $byPerm = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if (!empty($byPerm)) {
                    $widgetRecipients = array_merge($widgetRecipients, $byPerm);
                }
            } catch (Throwable $e) {
                // best-effort
            }

            $widgetRecipients = array_values(array_unique(array_map('intval', $widgetRecipients)));
            if ($widgetRecipients) {
                createNotifications($pdo, $widgetRecipients, [
                    'sender_id' => null,
                    'message' => 'Новая заявка HelpDesk с сайта',
                    'type' => 'helpdesk',
                    'related_id' => $ticketId,
                    'allow_self' => true,
                ]);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'message' => 'Заявка создана. Номер: ' . $ticketNumber
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            error_log('helpdesk.php widget-ticket failed at ' . ($stage ?? 'unknown') . ': ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка сервера на этапе ' . ($stage ?? 'unknown') . ': ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // POST /api/helpdesk/tickets - Создать заявку (публично + авторизованный)
    if ($method === 'POST' && $action === 'tickets' && $id === null) {
        try {
            $data = $readJsonBody();

            if (empty($data['client_name']) || empty($data['subject']) || empty($data['description'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите имя, тему и описание']);
                exit;
            }

            $ticketNumber = $generateTicketNumber();

            $stmt = $pdo->prepare("
                INSERT INTO helpdesk_tickets
                (ticket_number, client_name, client_email, client_phone, client_company, category_id,
                 subject, description, priority, budget, deadline, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'web')
            ");

            $stmt->execute([
                $ticketNumber,
                $data['client_name'],
                $data['client_email'] ?? null,
                $data['client_phone'] ?? null,
                $data['client_company'] ?? null,
                $data['category_id'] ?? null,
                $data['subject'],
                $data['description'],
                $data['priority'] ?? 'medium',
                $data['budget'] ?? null,
                $data['deadline'] ?? null
            ]);

        $ticketId = $pdo->lastInsertId();

        // Логируем создание
        $logHistory($ticketId, 'created', $currentUser ? $currentUser['id'] : null, null, null, null, ['source' => 'web']);

        if (!empty($data['assigned_to']) && (int)$data['assigned_to'] !== (int)($currentUser['id'] ?? 0)) {
            createNotification($pdo, [
                'user_id' => (int)$data['assigned_to'],
                'sender_id' => $currentUser ? (int)$currentUser['id'] : null,
                'message' => 'На вас назначена новая заявка HelpDesk',
                'type' => 'helpdesk',
                'related_id' => (int)$ticketId,
            ]);
        }

            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'message' => 'Заявка создана. Номер: ' . $ticketNumber
                ]
            ]);
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка сервера: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    // ============================================
    // ПУБЛИЧНЫЕ ENDPOINTS (чтение комментариев и истории)
    // ============================================

    // GET /api/helpdesk/tickets/:id/comments - Комментарии (публичный)
    if ($method === 'GET' && $action === 'tickets' && is_numeric($id) && $subaction === 'comments') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    c.*,
                    u.full_name as user_name,
                    u.avatar as user_avatar
                FROM helpdesk_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.ticket_id = ?
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([(int)$id]);
            $comments = $stmt->fetchAll();
    
            echo json_encode(['success' => true, 'data' => $comments]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // GET /api/helpdesk/tickets/:id/history - История (публичная)
    if ($method === 'GET' && $action === 'tickets' && is_numeric($id) && $subaction === 'history') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    h.*,
                    u.full_name as user_name
                FROM helpdesk_history h
                LEFT JOIN users u ON h.user_id = u.id
                WHERE h.ticket_id = ?
                ORDER BY h.created_at ASC
            ");
            $stmt->execute([(int)$id]);
            $history = $stmt->fetchAll();
    
            echo json_encode(['success' => true, 'data' => $history]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================
    // АВТОРИЗОВАННЫЕ ENDPOINTS
    // ============================================

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/helpdesk/tickets - Список заявок
    if ($method === 'GET' && $action === 'tickets' && $id === null) {
        $filters = [];
        $params = [];

        if (isset($_GET['status_id']) && is_numeric($_GET['status_id'])) {
            $filters[] = "t.status_id = ?";
            $params[] = (int)$_GET['status_id'];
        }

        if (isset($_GET['category_id']) && is_numeric($_GET['category_id'])) {
            $filters[] = "t.category_id = ?";
            $params[] = (int)$_GET['category_id'];
        }

        if (isset($_GET['assigned_to']) && is_numeric($_GET['assigned_to'])) {
            $filters[] = "t.assigned_to = ?";
            $params[] = (int)$_GET['assigned_to'];
        }

        if (isset($_GET['priority']) && in_array($_GET['priority'], ['low', 'medium', 'high', 'urgent'])) {
            $filters[] = "t.priority = ?";
            $params[] = $_GET['priority'];
        }

        if (isset($_GET['search'])) {
            $filters[] = "(t.subject LIKE ? OR t.description LIKE ? OR t.client_name LIKE ? OR t.ticket_number LIKE ?)";
            $searchTerm = '%' . $_GET['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = empty($filters) ? '' : 'WHERE ' . implode(' AND ', $filters);

        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                s.name as status_name,
                s.color as status_color,
                c.name as category_name,
                c.icon as category_icon,
                c.color as category_color,
                u.full_name as assigned_to_name,
                d.name as department_name,
                created.full_name as created_by_name
            FROM helpdesk_tickets t
            LEFT JOIN helpdesk_statuses s ON t.status_id = s.id
            LEFT JOIN helpdesk_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN departments d ON t.assigned_department_id = d.id
            LEFT JOIN users created ON t.created_by = created.id
            $whereClause
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $tickets]);
        exit;
    }

    // GET /api/helpdesk/tickets/:id - Заявка по ID
    if ($method === 'GET' && $action === 'tickets' && is_numeric($id)) {
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                s.name as status_name,
                s.color as status_color,
                c.name as category_name,
                c.icon as category_icon,
                c.color as category_color,
                u.full_name as assigned_to_name,
                d.name as department_name,
                created.full_name as created_by_name,
                resolved.full_name as resolved_by_name
            FROM helpdesk_tickets t
            LEFT JOIN helpdesk_statuses s ON t.status_id = s.id
            LEFT JOIN helpdesk_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN departments d ON t.assigned_department_id = d.id
            LEFT JOIN users created ON t.created_by = created.id
            LEFT JOIN users resolved ON t.resolved_by = resolved.id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $ticket]);
        exit;
    }

    // POST /api/helpdesk/tickets - Создать заявку (авторизованный)
    if ($method === 'POST' && $action === 'tickets' && $id === null && $currentUser) {
        $data = $readJsonBody();

        if (empty($data['client_name']) || empty($data['subject']) || empty($data['description'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите имя клиента, тему и описание']);
            exit;
        }

        $ticketNumber = $generateTicketNumber();

        $stmt = $pdo->prepare("
            INSERT INTO helpdesk_tickets 
            (ticket_number, client_name, client_email, client_phone, client_company, category_id, 
             subject, description, priority, budget, deadline, source, assigned_to, assigned_department_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'internal', ?, ?, ?)
        ");

        $stmt->execute([
            $ticketNumber,
            $data['client_name'],
            $data['client_email'] ?? null,
            $data['client_phone'] ?? null,
            $data['client_company'] ?? null,
            $data['category_id'] ?? null,
            $data['subject'],
            $data['description'],
            $data['priority'] ?? 'medium',
            $data['budget'] ?? null,
            $data['deadline'] ?? null,
            $data['assigned_to'] ?? null,
            $data['assigned_department_id'] ?? null,
            $currentUser['id']
        ]);

        $ticketId = $pdo->lastInsertId();

        $logHistory($ticketId, 'created', $currentUser['id'], null, null, null, ['source' => 'internal']);

        if (!empty($data['assigned_to']) && (int)$data['assigned_to'] !== (int)$currentUser['id']) {
            createNotification($pdo, [
                'user_id' => (int)$data['assigned_to'],
                'sender_id' => (int)$currentUser['id'],
                'message' => 'На вас назначена новая заявка HelpDesk',
                'type' => 'helpdesk',
                'related_id' => (int)$ticketId,
            ]);
        }

        echo json_encode([
            'success' => true,
            'data' => ['id' => $ticketId, 'ticket_number' => $ticketNumber]
        ]);
        exit;
    }

    // PUT /api/helpdesk/tickets/:id - Обновить заявку
    if ($method === 'PUT' && $action === 'tickets' && is_numeric($id)) {
        $data = $readJsonBody();

        $stmt = $pdo->prepare("SELECT * FROM helpdesk_tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        $updates = [];
        $params = [];
        $history = [];

        $fields = ['client_name', 'client_email', 'client_phone', 'client_company', 'subject', 'description', 'priority', 'budget', 'deadline'];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                $history[] = ['field' => $field, 'old' => $ticket[$field], 'new' => $data[$field]];
            }
        }

        if (!empty($updates)) {
            $params[] = $id;
            $sql = "UPDATE helpdesk_tickets SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Логируем изменения
            foreach ($history as $h) {
                if ($h['old'] !== $h['new']) {
                    $logHistory($id, 'updated', $currentUser['id'], $h['field'], $h['old'], $h['new']);
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Заявка обновлена']);
        exit;
    }

    // DELETE /api/helpdesk/tickets/:id - Удалить заявку
    if ($method === 'DELETE' && $action === 'tickets' && is_numeric($id)) {
        $stmt = $pdo->prepare("DELETE FROM helpdesk_tickets WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Заявка удалена']);
        exit;
    }

    // POST /api/helpdesk/tickets/:id/assign - Назначить ответственного
    if ($method === 'POST' && $action === 'tickets' && is_numeric($id) && $subaction === 'assign') {
        $data = $readJsonBody();

        $stmt = $pdo->prepare("SELECT assigned_to, assigned_department_id FROM helpdesk_tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        $assignedTo = $data['assigned_to'] ?? null;
        $assignedDepartment = $data['assigned_department_id'] ?? null;

        $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET assigned_to = ?, assigned_department_id = ? WHERE id = ?");
        $stmt->execute([$assignedTo, $assignedDepartment, $id]);

        $logHistory($id, 'assigned', $currentUser['id'], 'assigned_to', $ticket['assigned_to'], $assignedTo);
        if ($assignedDepartment !== null) {
            $logHistory($id, 'assigned', $currentUser['id'], 'assigned_department_id', $ticket['assigned_department_id'], $assignedDepartment);
        }

        if ($assignedTo !== null && (int)$assignedTo !== (int)$currentUser['id']) {
            createNotification($pdo, [
                'user_id' => (int)$assignedTo,
                'sender_id' => (int)$currentUser['id'],
                'message' => 'На вас назначена заявка HelpDesk',
                'type' => 'helpdesk',
                'related_id' => (int)$id,
            ]);
        }

        if ($assignedDepartment !== null) {
            createNotifications($pdo, getDepartmentUserIds($pdo, (int)$assignedDepartment), [
                'sender_id' => (int)$currentUser['id'],
                'message' => 'Новая заявка назначена вашему отделу',
                'type' => 'helpdesk',
                'related_id' => (int)$id,
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Ответственный назначен']);
        exit;
    }

    // POST /api/helpdesk/tickets/:id/status - Изменить статус
    if ($method === 'POST' && $action === 'tickets' && is_numeric($id) && $subaction === 'status') {
        $data = $readJsonBody();

        if (empty($data['status_id']) || !is_numeric($data['status_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите статус']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT status_id FROM helpdesk_tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET status_id = ? WHERE id = ?");
        $stmt->execute([$data['status_id'], $id]);

        $logHistory($id, 'status_changed', $currentUser['id'], 'status_id', $ticket['status_id'], $data['status_id']);

        echo json_encode(['success' => true, 'message' => 'Статус изменён']);
        exit;
    }

    // POST /api/helpdesk/tickets/:id/resolve - Завершить заявку
    if ($method === 'POST' && $action === 'tickets' && is_numeric($id) && $subaction === 'resolve') {
        $data = $readJsonBody();

        $resolution = $data['resolution'] ?? '';
        $statusId = $data['status_id'] ?? null; // Обычно ID статуса "Решена" или "Закрыта"

        $stmt = $pdo->prepare("
            UPDATE helpdesk_tickets 
            SET resolution = ?, resolved_at = NOW(), resolved_by = ?" . ($statusId ? ", status_id = ?" : "") . "
            WHERE id = ?
        ");
        
        if ($statusId) {
            $stmt->execute([$resolution, $currentUser['id'], $statusId, $id]);
        } else {
            $stmt->execute([$resolution, $currentUser['id'], $id]);
        }

        $logHistory($id, 'resolved', $currentUser['id'], 'resolution', null, $resolution);

        echo json_encode(['success' => true, 'message' => 'Заявка завершена']);
        exit;
    }

    // POST /api/helpdesk/tickets/:id/comments - Добавить комментарий (требуется авторизация)
    if ($method === 'POST' && $action === 'tickets' && is_numeric($id) && $subaction === 'comments') {
        $data = $readJsonBody();

        if (empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Введите текст комментария']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO helpdesk_comments (ticket_id, user_id, is_internal, message, attachments)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id,
            $currentUser['id'],
            $data['is_internal'] ?? 0,
            $data['message'],
            !empty($data['attachments']) ? json_encode($data['attachments']) : null
        ]);

        $commentId = $pdo->lastInsertId();

        $logHistory($id, 'comment', $currentUser['id'], null, null, $commentId, ['is_internal' => $data['is_internal'] ?? 0]);

        // Omnichannel outbound: if ticket is bound to Telegram/MAX thread, send operator reply back.
        try {
            $isInternal = (int)($data['is_internal'] ?? 0) === 1;
            if (!$isInternal) {
                $thread = omniFindHelpdeskThread($pdo, (int)$id);
                if ($thread && !empty($thread['channel']) && !empty($thread['external_chat_id'])) {
                    $channel = (string)$thread['channel'];
                    $text = trim((string)$data['message']);

                    if ($text !== '') {
                        if ($channel === 'telegram') {
                            $enabled = trim((string)(omniLoadSetting($pdo, 'omni_tg_enabled') ?? '0')) === '1';
                            if ($enabled) {
                                $token = omniLoadSecretSetting($pdo, 'omni_tg_bot_token');
                                $sendRes = omniSendTelegramMessage($pdo, $token, (string)$thread['external_chat_id'], $text);
                                $logHistory($id, 'omni.outgoing', $currentUser['id'], null, null, $commentId, [
                                    'channel' => 'telegram',
                                    'status' => $sendRes['status'] ?? 0,
                                    'ok' => (bool)($sendRes['ok'] ?? false)
                                ]);
                            }
                        }

                        if ($channel === 'max') {
                            $enabled = trim((string)(omniLoadSetting($pdo, 'omni_max_enabled') ?? '0')) === '1';
                            if ($enabled) {
                                $token = omniLoadSecretSetting($pdo, 'omni_max_bot_token');
                                $sendRes = omniSendMaxMessage($pdo, $token, (string)$thread['external_chat_id'], $text);
                                $logHistory($id, 'omni.outgoing', $currentUser['id'], null, null, $commentId, [
                                    'channel' => 'max',
                                    'status' => $sendRes['status'] ?? 0,
                                    'ok' => (bool)($sendRes['ok'] ?? false)
                                ]);
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // never break helpdesk comment flow
            error_log('helpdesk omni outbound error: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'data' => ['id' => $commentId]]);
        exit;
    }

    // GET /api/helpdesk/stats - Статистика
    if ($method === 'GET' && $action === 'stats' && $id === null) {
        $stats = [];

        // Общее количество
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM helpdesk_tickets");
        $stats['total'] = $stmt->fetch()['count'];

        // По статусам
        $stmt = $pdo->query("
            SELECT s.name, s.color, COUNT(t.id) as count 
            FROM helpdesk_statuses s
            LEFT JOIN helpdesk_tickets t ON s.id = t.status_id
            GROUP BY s.id, s.name, s.color
        ");
        $stats['by_status'] = $stmt->fetchAll();

        // По категориям
        $stmt = $pdo->query("
            SELECT c.name, c.color, COUNT(t.id) as count 
            FROM helpdesk_categories c
            LEFT JOIN helpdesk_tickets t ON c.id = t.category_id
            GROUP BY c.id, c.name, c.color
        ");
        $stats['by_category'] = $stmt->fetchAll();

        // Новые за сегодня
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM helpdesk_tickets WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = $stmt->fetch()['count'];

        // Просроченные
        $stmt = $pdo->query("
            SELECT COUNT(*) as count FROM helpdesk_tickets 
            WHERE deadline < CURDATE() AND status_id NOT IN (SELECT id FROM helpdesk_statuses WHERE name IN ('Решена', 'Закрыта', 'Отклонена'))
        ");
        $stats['overdue'] = $stmt->fetch()['count'];

        echo json_encode(['success' => true, 'data' => $stats]);
        exit;
    }

    // POST /api/helpdesk/tickets/:id/convert - Конвертировать в задачу/клиента/сделку
    if ($method === 'POST' && $action === 'tickets' && is_numeric($id) && $subaction === 'convert') {
        $data = $readJsonBody();

        $stmt = $pdo->prepare("SELECT * FROM helpdesk_tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        $convertType = $data['type'] ?? ''; // 'task', 'client', 'deal'
        $result = [];

        if ($convertType === 'task') {
            // Создаём задачу
            $stmt = $pdo->prepare("
                INSERT INTO tasks (title, description, priority, deadline, created_by, assigned_to)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ticket['subject'],
                $ticket['description'] . "\n\n[Из заявки #" . $ticket['ticket_number'] . "]",
                $ticket['priority'],
                $ticket['deadline'],
                $currentUser['id'],
                $ticket['assigned_to']
            ]);
            $taskId = $pdo->lastInsertId();

            // Связываем заявку с задачей
            $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET task_id = ? WHERE id = ?");
            $stmt->execute([$taskId, $id]);

            $result = ['type' => 'task', 'id' => $taskId];

        } elseif ($convertType === 'client') {
            // Создаём клиента в CRM
            $stmt = $pdo->prepare("
                INSERT INTO crm_clients (name, email, phone, notes, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ticket['client_name'] . ($ticket['client_company'] ? ' (' . $ticket['client_company'] . ')' : ''),
                $ticket['client_email'],
                $ticket['client_phone'],
                "Заявка #" . $ticket['ticket_number'] . ": " . $ticket['subject'],
                $currentUser['id']
            ]);
            $clientId = $pdo->lastInsertId();

            // Связываем заявку с клиентом
            $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET crm_client_id = ? WHERE id = ?");
            $stmt->execute([$clientId, $id]);

            $result = ['type' => 'client', 'id' => $clientId];

        } elseif ($convertType === 'deal') {
            // Создаём сделку (нужен pipeline)
            $stmt = $pdo->query("SELECT id FROM crm_pipelines WHERE is_default = 1 LIMIT 1");
            $pipeline = $stmt->fetch();
            
            if (!$pipeline) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Нет воронки по умолчанию']);
                exit;
            }

            $stmt = $pdo->query("SELECT id FROM crm_pipeline_stages WHERE pipeline_id = ? ORDER BY `order` LIMIT 1");
            $stmt->execute([$pipeline['id']]);
            $stage = $stmt->fetch();

            $stmt = $pdo->prepare("
                INSERT INTO crm_deals (title, client_id, pipeline_id, stage_id, description, created_by, owner_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ticket['subject'],
                null, // client_id можно связать позже
                $pipeline['id'],
                $stage['id'],
                $ticket['description'] . "\n\n[Из заявки #" . $ticket['ticket_number'] . "]",
                $currentUser['id'],
                $ticket['assigned_to'] ?? $currentUser['id']
            ]);
            $dealId = $pdo->lastInsertId();

            // Связываем заявку со сделкой
            $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET crm_deal_id = ? WHERE id = ?");
            $stmt->execute([$dealId, $id]);

            $result = ['type' => 'deal', 'id' => $dealId];

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите тип конвертации: task, client или deal']);
            exit;
        }

        $logHistory($id, 'converted', $currentUser['id'], null, null, $convertType, $result);

        echo json_encode(['success' => true, 'data' => $result, 'message' => 'Заявка конвертирована в ' . $convertType]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

// ============================================
// CALL HANDLER
// ============================================

// Проверяем, вызван ли этот файл из api/index.php (переменные уже определены)
if (isset($index_resource)) {
    // Вызов из api/index.php - параметры уже переданы
    handleHelpdesk($method, $action, $id, $subaction);
    exit;
}

// Прямой вызов - разбираем параметры и вызываем handleHelpdesk()
$method = $_SERVER['REQUEST_METHOD'];

$endpoint = $_GET['endpoint'] ?? '';
if (!empty($endpoint) && strpos($endpoint, 'helpdesk/') === 0) {
    // Разбираем путь вида helpdesk/tickets/123/comments
    $path = substr($endpoint, 9); // убираем 'helpdesk/'
    $parts = explode('/', $path);
    $parts = array_filter($parts, function($v) { return $v !== ''; });
    $parts = array_values($parts);
    
    $action = $parts[0] ?? null;
    $id = $parts[1] ?? null;
    $subaction = $parts[2] ?? null;
} else {
    // Прямой вызов - берем из $_GET
    $action = $_GET['action'] ?? null;
    $id = $_GET['id'] ?? null;
    $subaction = $_GET['subaction'] ?? null;
}

handleHelpdesk($method, $action, $id, $subaction);
