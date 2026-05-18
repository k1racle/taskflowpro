<?php

function ensureBookingModuleSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_key VARCHAR(64) NOT NULL,
        type_name VARCHAR(190) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT 'calendar',
        description TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_service_type_key (type_key),
        KEY idx_booking_service_type_active (is_active),
        KEY idx_booking_service_type_order (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_extra_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_id INT NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        description TEXT NULL,
        base_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        unit VARCHAR(24) NOT NULL DEFAULT 'piece',
        min_quantity INT NOT NULL DEFAULT 1,
        max_quantity INT NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_extra_service (type_id, service_name),
        KEY idx_booking_extra_service_type (type_id),
        KEY idx_booking_extra_service_active (is_active),
        KEY idx_booking_extra_service_order (sort_order),
        CONSTRAINT fk_booking_extra_services_type FOREIGN KEY (type_id) REFERENCES booking_service_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_number VARCHAR(32) NOT NULL,
        service_type_id INT NOT NULL,
        crm_client_id INT NULL,
        client_name VARCHAR(255) NOT NULL,
        client_email VARCHAR(255) NULL,
        client_phone VARCHAR(80) NULL,
        client_company VARCHAR(255) NULL,
        preferred_datetime DATETIME NULL,
        notes TEXT NULL,
        admin_comment TEXT NULL,
        status ENUM('new', 'approved', 'rejected') NOT NULL DEFAULT 'new',
        created_by INT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_request_number (request_number),
        KEY idx_booking_requests_service (service_type_id),
        KEY idx_booking_requests_status (status),
        KEY idx_booking_requests_created_at (created_at),
        KEY idx_booking_requests_preferred_datetime (preferred_datetime),
        KEY idx_booking_requests_crm_client (crm_client_id),
        KEY idx_booking_requests_created_by (created_by),
        KEY idx_booking_requests_reviewed_by (reviewed_by),
        CONSTRAINT fk_booking_requests_service FOREIGN KEY (service_type_id) REFERENCES booking_service_types(id) ON DELETE RESTRICT,
        CONSTRAINT fk_booking_requests_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_booking_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    bookingSeedDefaultServiceTypes($pdo);
}

function bookingSeedDefaultServiceTypes(PDO $pdo): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }

    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM booking_service_types")->fetchColumn();
        if ($count > 0) {
            $seeded = true;
            return;
        }
    } catch (Throwable $e) {
        error_log('bookingSeedDefaultServiceTypes count failed: ' . $e->getMessage());
        return;
    }

    $defaults = [
        ['snowmobile', 'Снегоходы', 'snowflake', 'Прокат и экскурсии на снегоходах', 1, 1],
        ['bbq', 'Мангалы и барбекю', 'flame', 'Аренда мангалов и наборы для барбекю', 2, 1],
        ['bike', 'Велосипеды', 'bicycle', 'Прокат велосипедов и велопрогулки', 3, 1],
        ['tour', 'Экскурсии', 'map', 'Организованные экскурсии и туры', 4, 1],
        ['equipment', 'Спортивный инвентарь', 'tool', 'Прокат лыж, тюбингов и другого инвентаря', 5, 1],
        ['other', 'Другие услуги', 'star', 'Дополнительные услуги', 6, 1],
    ];

    $stmt = $pdo->prepare("INSERT INTO booking_service_types (
        type_key, type_name, icon, description, sort_order, is_active
    ) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($defaults as $row) {
        $stmt->execute($row);
    }

    $seeded = true;
}
