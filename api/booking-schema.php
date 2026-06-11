<?php

function bookingQuoteIdentifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function bookingTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function bookingColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . bookingQuoteIdentifier($table) . ' LIKE ' . $pdo->quote($column));
        return (bool)($stmt && $stmt->fetch());
    } catch (Throwable $e) {
        return false;
    }
}

function bookingIndexExists(PDO $pdo, string $table, string $indexName): bool {
    try {
        $stmt = $pdo->query('SHOW INDEX FROM ' . bookingQuoteIdentifier($table) . ' WHERE Key_name = ' . $pdo->quote($indexName));
        return (bool)($stmt && $stmt->fetch());
    } catch (Throwable $e) {
        return false;
    }
}

function bookingEnsureCharset(PDO $pdo, string $table): void {
    try {
        $stmt = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        $collation = (string)($stmt->fetchColumn() ?: '');
        if ($collation !== '' && stripos($collation, 'utf8mb4_') !== 0) {
            $pdo->exec('ALTER TABLE ' . bookingQuoteIdentifier($table) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    } catch (Throwable $e) {
        error_log('booking-schema: charset check failed for ' . $table . ': ' . $e->getMessage());
    }
}

function bookingAddColumn(PDO $pdo, string $table, string $column, string $definition, ?string $after = null): void {
    if (bookingColumnExists($pdo, $table, $column)) {
        return;
    }

    $sql = 'ALTER TABLE ' . bookingQuoteIdentifier($table) . ' ADD COLUMN ' . bookingQuoteIdentifier($column) . ' ' . $definition;
    if ($after) {
        $sql .= ' AFTER ' . bookingQuoteIdentifier($after);
    }
    $pdo->exec($sql);
}

function bookingModifyColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (!bookingColumnExists($pdo, $table, $column)) {
        return;
    }

    $pdo->exec('ALTER TABLE ' . bookingQuoteIdentifier($table) . ' MODIFY COLUMN ' . bookingQuoteIdentifier($column) . ' ' . $definition);
}

function bookingAddIndex(PDO $pdo, string $table, string $indexName, string $definition): void {
    if (bookingIndexExists($pdo, $table, $indexName)) {
        return;
    }

    $pdo->exec('ALTER TABLE ' . bookingQuoteIdentifier($table) . ' ADD ' . $definition);
}

function bookingNormalizeServiceKey(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    if ($value === '') {
        return 'service';
    }

    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = strtolower($transliterated);
        }
    }

    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', $value) ?? '';
    $value = trim((string)$value, '-');

    return $value !== '' ? $value : 'service';
}

function bookingNormalizeStatus(?string $status): string {
    $value = strtolower(trim((string)$status));
    return match ($value) {
        'new', 'pending' => 'pending',
        'approved', 'confirmed' => 'confirmed',
        'rejected' => 'rejected',
        'expired' => 'expired',
        default => 'pending',
    };
}

function bookingServiceEffectivePrice(array $service): float {
    $base = (float)($service['price_rub'] ?? 0);
    $discountType = strtolower(trim((string)($service['discount_type'] ?? 'none')));
    $discountValue = max(0, (float)($service['discount_value'] ?? 0));

    if ($discountType === 'percent') {
        $base -= $base * min(100, $discountValue) / 100;
    } elseif ($discountType === 'amount') {
        $base -= $discountValue;
    }

    return max(0, round($base, 2));
}

function bookingServiceDiscountAmount(array $service): float {
    $base = (float)($service['price_rub'] ?? 0);
    return max(0, round($base - bookingServiceEffectivePrice($service), 2));
}

function bookingDecorateServiceRow(array $service): array {
    $effectivePrice = bookingServiceEffectivePrice($service);
    $discountAmount = bookingServiceDiscountAmount($service);

    $service['id'] = isset($service['id']) ? (int)$service['id'] : null;
    $service['duration_minutes'] = max(0, (int)($service['duration_minutes'] ?? 0));
    $service['price_rub'] = round((float)($service['price_rub'] ?? 0), 2);
    $service['discount_value'] = round((float)($service['discount_value'] ?? 0), 2);
    $service['effective_price_rub'] = $effectivePrice;
    $service['discount_amount_rub'] = $discountAmount;
    $service['is_active'] = (int)($service['is_active'] ?? 0) === 1;
    $service['discount_type'] = strtolower(trim((string)($service['discount_type'] ?? 'none')));
    $service['promo_label'] = trim((string)($service['promo_label'] ?? ''));

    return $service;
}

function bookingWeekdayLabel(int $weekday): string {
    return match ($weekday) {
        1 => 'Пн',
        2 => 'Вт',
        3 => 'Ср',
        4 => 'Чт',
        5 => 'Пт',
        6 => 'Сб',
        7 => 'Вс',
        default => (string)$weekday,
    };
}

function bookingFormatTimeLabel(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    return substr($value, 0, 5);
}

function bookingMinutesLabel(int $minutes): string {
    $minutes = max(0, $minutes);
    if ($minutes === 0) {
        return '0 мин';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ч';
    }
    if ($rest > 0 || !$parts) {
        $parts[] = $rest . ' мин';
    }

    return implode(' ', $parts);
}

function bookingDecorateWorkingHourRow(array $row): array {
    $row['id'] = isset($row['id']) ? (int)$row['id'] : null;
    $row['weekday'] = max(1, min(7, (int)($row['weekday'] ?? 0)));
    $row['weekday_label'] = bookingWeekdayLabel($row['weekday']);
    $row['is_open'] = (int)($row['is_open'] ?? 0) === 1;
    $row['opens_at'] = bookingFormatTimeLabel($row['opens_at'] ?? null);
    $row['closes_at'] = bookingFormatTimeLabel($row['closes_at'] ?? null);
    $row['break_starts_at'] = bookingFormatTimeLabel($row['break_starts_at'] ?? null);
    $row['break_ends_at'] = bookingFormatTimeLabel($row['break_ends_at'] ?? null);
    $row['note'] = trim((string)($row['note'] ?? ''));
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);

    return $row;
}

function bookingSeedDefaultServiceTypes(PDO $pdo): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }

    if (!bookingTableExists($pdo, 'booking_service_types')) {
        return;
    }

    // Важно: не сеем демо-услуги. Услуги должны настраиваться из CRM.
    // Оставляем таблицу пустой по умолчанию.
    $count = 0;
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM booking_service_types')->fetchColumn();
    } catch (Throwable $e) {
        $count = 0;
    }

    $seeded = true;
    if ($count > 0) {
        return;
    }

    return;
}

function bookingPurgeLegacyDemoServiceTypes(PDO $pdo): void {
    // Старые демо-услуги из ранних версий ("Снегоходы" и т.п.) не должны висеть в проде.
    // Удаляем только НЕиспользуемые (на которые нет заявок), чтобы не ломать историю.
    if (!bookingTableExists($pdo, 'booking_service_types')) {
        return;
    }

    $demoKeys = ['snowmobile', 'bbq', 'bike', 'tour', 'equipment', 'other'];

    $placeholders = implode(',', array_fill(0, count($demoKeys), '?'));

    $stmt = $pdo->prepare("SELECT st.id, st.type_key
        FROM booking_service_types st
        WHERE st.type_key IN ($placeholders)");
    $stmt->execute($demoKeys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM booking_requests WHERE service_type_id = ?');
    $deleteReqSvcStmt = $pdo->prepare('DELETE FROM booking_request_services WHERE service_type_id = ?');
    $deleteSvcStmt = $pdo->prepare('DELETE FROM booking_service_types WHERE id = ?');

    foreach ($rows as $row) {
        $serviceId = (int)($row['id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        try {
            $countStmt->execute([$serviceId]);
            $requestCount = (int)$countStmt->fetchColumn();
            if ($requestCount > 0) {
                // Если услуга уже использовалась в заявках, не удаляем из-за FK и истории.
                continue;
            }

            // Удаляем связанные строки (на всякий случай), затем саму услугу.
            $deleteReqSvcStmt->execute([$serviceId]);
            $deleteSvcStmt->execute([$serviceId]);
        } catch (Throwable $e) {
            error_log('bookingPurgeLegacyDemoServiceTypes failed: ' . $e->getMessage());
        }
    }
}

function bookingSeedDefaultWorkingHours(PDO $pdo): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }

    if (!bookingTableExists($pdo, 'booking_working_hours')) {
        return;
    }

    $defaults = [
        [1, 1, '09:00:00', '18:00:00', null, null, 'Пн', 1],
        [2, 1, '09:00:00', '18:00:00', null, null, 'Вт', 2],
        [3, 1, '09:00:00', '18:00:00', null, null, 'Ср', 3],
        [4, 1, '09:00:00', '18:00:00', null, null, 'Чт', 4],
        [5, 1, '09:00:00', '18:00:00', null, null, 'Пт', 5],
        [6, 1, '10:00:00', '16:00:00', null, null, 'Сб', 6],
        [7, 0, null, null, null, null, 'Вс', 7],
    ];

    $selectStmt = $pdo->prepare("SELECT id, sort_order FROM booking_working_hours WHERE weekday = ? LIMIT 1");
    $insertStmt = $pdo->prepare("INSERT INTO booking_working_hours (
        weekday,
        is_open,
        opens_at,
        closes_at,
        break_starts_at,
        break_ends_at,
        note,
        sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $updateStmt = $pdo->prepare("UPDATE booking_working_hours
        SET is_open = ?,
            opens_at = ?,
            closes_at = ?,
            break_starts_at = ?,
            break_ends_at = ?,
            note = ?,
            sort_order = ?
        WHERE weekday = ?");

    foreach ($defaults as $row) {
        try {
            $selectStmt->execute([$row[0]]);
            $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $insertStmt->execute($row);
                continue;
            }

            if ((int)($existing['sort_order'] ?? 0) <= 0) {
                $updateStmt->execute([
                    $row[1],
                    $row[2],
                    $row[3],
                    $row[4],
                    $row[5],
                    $row[6],
                    $row[7],
                    $row[0],
                ]);
            }
        } catch (Throwable $e) {
            error_log('bookingSeedDefaultWorkingHours insert failed: ' . $e->getMessage());
        }
    }

    $seeded = true;
}

function bookingNormalizeLegacyBookingRequests(PDO $pdo): void {
    static $normalized = false;
    if ($normalized) {
        return;
    }

    if (!bookingTableExists($pdo, 'booking_requests') || !bookingTableExists($pdo, 'booking_request_services') || !bookingTableExists($pdo, 'booking_service_types')) {
        return;
    }

    try {
        $requests = $pdo->query('SELECT * FROM booking_requests ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('bookingNormalizeLegacyBookingRequests load failed: ' . $e->getMessage());
        return;
    }

    if (!$requests) {
        $normalized = true;
        return;
    }

    $serviceTypeStmt = $pdo->prepare('SELECT * FROM booking_service_types WHERE id = ? LIMIT 1');
    $requestServicesStmt = $pdo->prepare('SELECT * FROM booking_request_services WHERE booking_request_id = ? ORDER BY sort_order ASC, id ASC');
    $insertRequestServiceStmt = $pdo->prepare("INSERT INTO booking_request_services (
        booking_request_id,
        service_type_id,
        service_key,
        service_name,
        icon,
        duration_minutes,
        price_rub,
        discount_type,
        discount_value,
        effective_price_rub,
        sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updateRequestStmt = $pdo->prepare("UPDATE booking_requests SET
        service_type_id = ?,
        preferred_end_at = ?,
        total_duration_minutes = ?,
        total_price_rub = ?,
        hold_expires_at = ?,
        confirmed_at = ?,
        status = ?
        WHERE id = ?");

    $now = new DateTimeImmutable('now');

    foreach ($requests as $request) {
        $requestId = (int)($request['id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $rawStatus = strtolower(trim((string)($request['status'] ?? '')));
        $status = bookingNormalizeStatus($request['status'] ?? null);
        $createdAt = null;
        try {
            $createdAt = !empty($request['created_at']) ? new DateTimeImmutable((string)$request['created_at']) : null;
        } catch (Throwable $e) {
            $createdAt = null;
        }

        $holdExpiresAt = null;
        if (!empty($request['hold_expires_at'])) {
            try {
                $holdExpiresAt = new DateTimeImmutable((string)$request['hold_expires_at']);
            } catch (Throwable $e) {
                $holdExpiresAt = null;
            }
        }

        $preferredDatetime = null;
        if (!empty($request['preferred_datetime'])) {
            try {
                $preferredDatetime = new DateTimeImmutable((string)$request['preferred_datetime']);
            } catch (Throwable $e) {
                $preferredDatetime = null;
            }
        }

        $serviceRows = [];
        try {
            $requestServicesStmt->execute([$requestId]);
            $serviceRows = $requestServicesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('bookingNormalizeLegacyBookingRequests services load failed: ' . $e->getMessage());
        }

        if (!$serviceRows && (int)($request['service_type_id'] ?? 0) > 0) {
            $serviceTypeId = (int)$request['service_type_id'];
            try {
                $serviceTypeStmt->execute([$serviceTypeId]);
                $serviceType = $serviceTypeStmt->fetch(PDO::FETCH_ASSOC);
                if ($serviceType) {
                    $catalog = bookingDecorateServiceRow($serviceType);
                    $insertRequestServiceStmt->execute([
                        $requestId,
                        (int)$serviceType['id'],
                        (string)($catalog['type_key'] ?? $serviceType['type_key'] ?? ''),
                        (string)($catalog['type_name'] ?? $serviceType['type_name'] ?? ''),
                        (string)($catalog['icon'] ?? $serviceType['icon'] ?? 'calendar'),
                        (int)($catalog['duration_minutes'] ?? $serviceType['duration_minutes'] ?? 0),
                        (float)($catalog['price_rub'] ?? $serviceType['price_rub'] ?? 0),
                        (string)($catalog['discount_type'] ?? $serviceType['discount_type'] ?? 'none'),
                        (float)($catalog['discount_value'] ?? $serviceType['discount_value'] ?? 0),
                        (float)($catalog['effective_price_rub'] ?? bookingServiceEffectivePrice($serviceType)),
                        1,
                    ]);
                    $serviceRows[] = [
                        'booking_request_id' => $requestId,
                        'service_type_id' => (int)$serviceType['id'],
                        'service_key' => (string)($catalog['type_key'] ?? $serviceType['type_key'] ?? ''),
                        'service_name' => (string)($catalog['type_name'] ?? $serviceType['type_name'] ?? ''),
                        'icon' => (string)($catalog['icon'] ?? $serviceType['icon'] ?? 'calendar'),
                        'duration_minutes' => (int)($catalog['duration_minutes'] ?? $serviceType['duration_minutes'] ?? 0),
                        'price_rub' => (float)($catalog['price_rub'] ?? $serviceType['price_rub'] ?? 0),
                        'discount_type' => (string)($catalog['discount_type'] ?? $serviceType['discount_type'] ?? 'none'),
                        'discount_value' => (float)($catalog['discount_value'] ?? $serviceType['discount_value'] ?? 0),
                        'effective_price_rub' => (float)($catalog['effective_price_rub'] ?? bookingServiceEffectivePrice($serviceType)),
                        'sort_order' => 1,
                    ];
                }
            } catch (Throwable $e) {
                error_log('bookingNormalizeLegacyBookingRequests seed request service failed: ' . $e->getMessage());
            }
        }

        $totalDuration = 0;
        $totalPrice = 0.0;
        $primaryServiceTypeId = (int)($request['service_type_id'] ?? 0);

        foreach ($serviceRows as $idx => $serviceRow) {
            if ($idx === 0) {
                $primaryServiceTypeId = (int)($serviceRow['service_type_id'] ?? $primaryServiceTypeId);
            }

            $totalDuration += max(0, (int)($serviceRow['duration_minutes'] ?? 0));
            $totalPrice += max(0, (float)($serviceRow['effective_price_rub'] ?? 0));
        }

        if ($totalDuration <= 0) {
            $totalDuration = max(0, (int)($request['total_duration_minutes'] ?? 0));
        }

        if ($totalPrice <= 0) {
            $totalPrice = round((float)($request['total_price_rub'] ?? 0), 2);
        }

        $preferredEndAt = null;
        if ($preferredDatetime && $totalDuration > 0) {
            $preferredEndAt = $preferredDatetime->modify('+' . $totalDuration . ' minutes')->format('Y-m-d H:i:s');
        } elseif (!empty($request['preferred_end_at'])) {
            $preferredEndAt = date('Y-m-d H:i:s', strtotime((string)$request['preferred_end_at'])) ?: null;
        }

        $holdValue = null;
        if ($status === 'pending') {
            if ($holdExpiresAt === null && $createdAt instanceof DateTimeImmutable) {
                $holdExpiresAt = $createdAt->modify('+30 minutes');
            }

            if ($holdExpiresAt instanceof DateTimeImmutable && $holdExpiresAt <= $now) {
                $status = 'expired';
            }

            if ($holdExpiresAt instanceof DateTimeImmutable) {
                $holdValue = $holdExpiresAt->format('Y-m-d H:i:s');
            }
        } else {
            $holdValue = null;
        }

        $confirmedValue = null;
        if ($status === 'confirmed') {
            $confirmedSource = !empty($request['confirmed_at']) ? (string)$request['confirmed_at'] : (!empty($request['reviewed_at']) ? (string)$request['reviewed_at'] : (!empty($request['updated_at']) ? (string)$request['updated_at'] : null));
            if ($confirmedSource !== null) {
                try {
                    $confirmedValue = (new DateTimeImmutable($confirmedSource))->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    $confirmedValue = null;
                }
            }
        }

        $preferredEndValue = $preferredEndAt;
        if ($preferredEndValue === null && !empty($request['preferred_end_at'])) {
            try {
                $preferredEndValue = (new DateTimeImmutable((string)$request['preferred_end_at']))->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $preferredEndValue = null;
            }
        }

        $needsUpdate = false;
        if ($status !== $rawStatus) {
            $needsUpdate = true;
        }
        if ($primaryServiceTypeId !== (int)($request['service_type_id'] ?? 0)) {
            $needsUpdate = true;
        }
        if ($totalDuration !== (int)($request['total_duration_minutes'] ?? 0)) {
            $needsUpdate = true;
        }
        if (abs($totalPrice - (float)($request['total_price_rub'] ?? 0)) > 0.001) {
            $needsUpdate = true;
        }
        if (($preferredEndValue ?? null) !== ($request['preferred_end_at'] ?? null)) {
            $needsUpdate = true;
        }
        if (($holdValue ?? null) !== ($request['hold_expires_at'] ?? null)) {
            $needsUpdate = true;
        }
        if (($confirmedValue ?? null) !== ($request['confirmed_at'] ?? null)) {
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            try {
                $updateRequestStmt->execute([
                    $primaryServiceTypeId,
                    $preferredEndValue,
                    $totalDuration,
                    $totalPrice,
                    $holdValue,
                    $confirmedValue,
                    $status,
                    $requestId,
                ]);
            } catch (Throwable $e) {
                error_log('bookingNormalizeLegacyBookingRequests update failed for #' . $requestId . ': ' . $e->getMessage());
            }
        }
    }

    $normalized = true;
}

function ensureBookingModuleSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_key VARCHAR(64) NOT NULL,
        type_name VARCHAR(190) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT 'calendar',
        description TEXT NULL,
        duration_minutes INT NOT NULL DEFAULT 60,
        price_rub DECIMAL(14,2) NOT NULL DEFAULT 0,
        discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
        discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        promo_label VARCHAR(120) NULL,
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
        preferred_end_at DATETIME NULL,
        total_duration_minutes INT NOT NULL DEFAULT 0,
        total_price_rub DECIMAL(14,2) NOT NULL DEFAULT 0,
        hold_expires_at DATETIME NULL,
        confirmed_at DATETIME NULL,
        notes TEXT NULL,
        admin_comment TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
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
        KEY idx_booking_requests_hold_expires_at (hold_expires_at),
        KEY idx_booking_requests_crm_client (crm_client_id),
        KEY idx_booking_requests_created_by (created_by),
        KEY idx_booking_requests_reviewed_by (reviewed_by),
        CONSTRAINT fk_booking_requests_service FOREIGN KEY (service_type_id) REFERENCES booking_service_types(id) ON DELETE RESTRICT,
        CONSTRAINT fk_booking_requests_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_booking_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_request_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_request_id INT NOT NULL,
        service_type_id INT NOT NULL,
        service_key VARCHAR(64) NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT 'calendar',
        duration_minutes INT NOT NULL DEFAULT 0,
        price_rub DECIMAL(14,2) NOT NULL DEFAULT 0,
        discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
        discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
        effective_price_rub DECIMAL(14,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_request_service (booking_request_id, service_type_id),
        KEY idx_booking_request_services_request (booking_request_id),
        KEY idx_booking_request_services_service (service_type_id),
        CONSTRAINT fk_booking_request_services_request FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id) ON DELETE CASCADE,
        CONSTRAINT fk_booking_request_services_service FOREIGN KEY (service_type_id) REFERENCES booking_service_types(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_working_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        weekday TINYINT NOT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        opens_at TIME NULL,
        closes_at TIME NULL,
        break_starts_at TIME NULL,
        break_ends_at TIME NULL,
        note VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_working_hours_weekday (weekday),
        KEY idx_booking_working_hours_open (is_open),
        KEY idx_booking_working_hours_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_widget_analytics (
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
        KEY idx_widget_profile_event (widget_profile_id, event),
        KEY idx_widget_analytics_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['booking_service_types', 'booking_extra_services', 'booking_requests', 'booking_request_services', 'booking_working_hours', 'booking_widget_analytics'] as $table) {
        bookingEnsureCharset($pdo, $table);
    }

    bookingAddColumn($pdo, 'booking_service_types', 'duration_minutes', 'INT NOT NULL DEFAULT 60', 'description');
    bookingAddColumn($pdo, 'booking_service_types', 'price_rub', 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'duration_minutes');
    bookingAddColumn($pdo, 'booking_service_types', 'discount_type', "VARCHAR(20) NOT NULL DEFAULT 'none'", 'price_rub');
    bookingAddColumn($pdo, 'booking_service_types', 'discount_value', 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'discount_type');
    bookingAddColumn($pdo, 'booking_service_types', 'promo_label', 'VARCHAR(120) NULL', 'discount_value');

    bookingAddColumn($pdo, 'booking_requests', 'preferred_end_at', 'DATETIME NULL', 'preferred_datetime');
    bookingAddColumn($pdo, 'booking_requests', 'total_duration_minutes', 'INT NOT NULL DEFAULT 0', 'preferred_end_at');
    bookingAddColumn($pdo, 'booking_requests', 'total_price_rub', 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'total_duration_minutes');
    bookingAddColumn($pdo, 'booking_requests', 'hold_expires_at', 'DATETIME NULL', 'total_price_rub');
    bookingAddColumn($pdo, 'booking_requests', 'confirmed_at', 'DATETIME NULL', 'hold_expires_at');
    bookingModifyColumn($pdo, 'booking_requests', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");

    bookingAddIndex($pdo, 'booking_requests', 'idx_booking_requests_hold_expires_at', 'INDEX idx_booking_requests_hold_expires_at (hold_expires_at)');
    bookingAddIndex($pdo, 'booking_request_services', 'idx_booking_request_services_request', 'INDEX idx_booking_request_services_request (booking_request_id)');
    bookingAddIndex($pdo, 'booking_request_services', 'idx_booking_request_services_service', 'INDEX idx_booking_request_services_service (service_type_id)');
    bookingAddIndex($pdo, 'booking_working_hours', 'idx_booking_working_hours_open', 'INDEX idx_booking_working_hours_open (is_open)');

    bookingSeedDefaultServiceTypes($pdo);
    bookingSeedDefaultWorkingHours($pdo);
    bookingPurgeLegacyDemoServiceTypes($pdo);
    bookingNormalizeLegacyBookingRequests($pdo);
}
