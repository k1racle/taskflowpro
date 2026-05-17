<?php
/**
 * api/profile.php - Личная карточка сотрудника
 * 
 * Эндпоинты:
 * - GET /api/profile - получить профиль текущего пользователя
 * - PUT /api/profile - обновить профиль
 * - POST /api/profile/avatar - загрузить аватар
 * - GET /api/profile/:id - профиль другого пользователя
 */

/**
 * Обработка запросов к /api/profile/*
 */
function handleProfile(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    
    // Создаём папку для аватарок если не существует
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // ============================================
    // GET/PUT /api/profile/mail-settings - настройки почты текущего пользователя
    // (без :id, чтобы не зависеть от особенностей роутера)
    // ============================================
    if ($action === 'mail-settings') {
        $userId = (int)$currentUser['id'];

        if ($method === 'GET') {
            $stmt = $pdo->prepare("SELECT `key`, value FROM user_settings WHERE user_id = ? AND `key` LIKE 'mail_%'");
            $stmt->execute([$userId]);
            $settings = $stmt->fetchAll();

            $mailSettings = [];
            foreach ($settings as $s) {
                $mailSettings[$s['key']] = $s['value'];
            }

            unset($mailSettings['mail_password']);
            echo json_encode(['success' => true, 'data' => $mailSettings]);
            exit;
        }

        if ($method === 'PUT') {
            $data = json_decode(file_get_contents('php://input'), true);

            $settingsToSave = [
                'mail_email' => $data['email'] ?? '',
                'mail_host' => $data['host'] ?? '',
                'mail_port' => $data['port'] ?? '587',
                'mail_username' => $data['username'] ?? '',
                'mail_smtp_username' => $data['username'] ?? '',
                'mail_imap_host' => $data['imap_host'] ?? '',
                'mail_imap_port' => $data['imap_port'] ?? '993',
                'mail_imap_encryption' => $data['imap_encryption'] ?? 'ssl',
                'mail_from_name' => $data['display_name'] ?? 'TaskFlow Pro',
                'mail_encryption' => $data['encryption'] ?? 'tls',
                'mail_signature' => $data['signature'] ?? '',
            ];

            if (!empty($data['password'])) {
                $settingsToSave['mail_password'] = appEncrypt($data['password']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO user_settings (user_id, `key`, value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            ");

            foreach ($settingsToSave as $key => $value) {
                $stmt->execute([$userId, $key, $value]);
            }

            echo json_encode(['success' => true, 'message' => 'Настройки почты сохранены']);
            exit;
        }
    }

    // GET /api/profile - профиль текущего пользователя
    if ($method === 'GET' && $action === null) {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   d.name as department_name,
                   d.description as department_description
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = ?
        ");
        $stmt->execute([$currentUser['id']]);
        $profile = $stmt->fetch();
        
        // Получаем статистику
        $statsStmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM tasks WHERE assigned_to = ?) as total_tasks,
                (SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'Готово') as completed_tasks,
                (SELECT COUNT(*) FROM projects WHERE created_by = ?) as total_projects
        ");
        $statsStmt->execute([$currentUser['id'], $currentUser['id'], $currentUser['id']]);
        $profile['stats'] = $statsStmt->fetch();
        
        echo json_encode(['success' => true, 'data' => $profile]);
        exit;
    }
    
    // GET /api/profile/:id - профиль другого пользователя
    if ($method === 'GET' && $action !== null && is_numeric($action)) {
        $userId = (int)$action;
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.login, u.full_name, u.role, u.department_id, u.created_at, u.last_login,
                   u.phone, u.avatar, u.bio, u.birthday, u.weather_city,
                   d.name as department_name,
                   d.description as department_description
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        
        if (!$profile) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        
        // Получаем статистику
        $statsStmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM tasks WHERE assigned_to = ?) as total_tasks,
                (SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'Готово') as completed_tasks,
                (SELECT COUNT(*) FROM projects WHERE created_by = ?) as total_projects
        ");
        $statsStmt->execute([$userId, $userId, $userId]);
        $profile['stats'] = $statsStmt->fetch();
        
        echo json_encode(['success' => true, 'data' => $profile]);
        exit;
    }
    
    // PUT /api/profile - обновить профиль
    if ($method === 'PUT' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        $updates = [];
        $params = [];

        if (isset($data['full_name'])) {
            $updates[] = "full_name = ?";
            $params[] = $data['full_name'];
        }

        if (isset($data['phone'])) {
            $updates[] = "phone = ?";
            $params[] = $data['phone'];
        }

        if (isset($data['bio'])) {
            $updates[] = "bio = ?";
            $params[] = $data['bio'];
        }

        if (isset($data['birthday'])) {
            $updates[] = "birthday = ?";
            $params[] = $data['birthday'];
        }

        if (isset($data['weather_city'])) {
            $updates[] = "weather_city = ?";
            $params[] = $data['weather_city'];
        }

        if (array_key_exists('avatar', $data)) {
            $updates[] = "avatar = ?";
            $params[] = $data['avatar'] ?: null;
        }

        if (isset($data['department_id'])) {
            $updates[] = "department_id = ?";
            $params[] = $data['department_id'];
        }

        if (!empty($updates)) {
            $params[] = $currentUser['id'];
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        echo json_encode(['success' => true, 'message' => 'Профиль обновлён']);
        exit;
    }

    // GET /api/profile/:id/mail-settings - получить настройки почты
    if ($method === 'GET' && $action !== null && is_numeric($action) && $id === 'mail-settings') {
        $userId = (int)$action;
        
        // Проверка что пользователь просматривает свои настройки
        if ($userId !== $currentUser['id'] && !hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'users.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        // Настройки почты храним на пользователя
        $stmt = $pdo->prepare("SELECT `key`, value FROM user_settings WHERE user_id = ? AND `key` LIKE 'mail_%'");
        $stmt->execute([$userId]);
        $settings = $stmt->fetchAll();

        $mailSettings = [];
        foreach ($settings as $s) {
            $mailSettings[$s['key']] = $s['value'];
        }

        // Не возвращаем пароль
        unset($mailSettings['mail_password']);

        echo json_encode(['success' => true, 'data' => $mailSettings]);
        exit;
    }

    // PUT /api/profile/:id/mail-settings - обновить настройки почты
    if ($method === 'PUT' && $action !== null && is_numeric($action) && $id === 'mail-settings') {
        $userId = (int)$action;
        
        // Проверка что пользователь редактирует свои настройки
        if ($userId !== $currentUser['id'] && !hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'users.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $settingsToSave = [
            'mail_email' => $data['email'] ?? '',
            'mail_host' => $data['host'] ?? '',
            'mail_port' => $data['port'] ?? '587',
            'mail_username' => $data['username'] ?? '',
            'mail_smtp_username' => $data['username'] ?? '',
            'mail_imap_host' => $data['imap_host'] ?? '',
            'mail_imap_port' => $data['imap_port'] ?? '993',
            'mail_imap_encryption' => $data['imap_encryption'] ?? 'ssl',
            'mail_from_name' => $data['display_name'] ?? 'TaskFlow Pro',
            'mail_encryption' => $data['encryption'] ?? 'tls',
            'mail_signature' => $data['signature'] ?? '',
        ];

        // Сохраняем пароль только если он указан (в зашифрованном виде)
        if (!empty($data['password'])) {
            $settingsToSave['mail_password'] = appEncrypt($data['password']);
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, `key`, value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");

        foreach ($settingsToSave as $key => $value) {
            $stmt->execute([$userId, $key, $value]);
        }

        // Дублируем настройки в mail_accounts (как каноничное хранилище для почтового клиента)
        // Пароль сохраняем только если он передан (в зашифрованном виде)
        $email = $data['email'] ?? '';
        if ($email) {
            $stmt = $pdo->prepare("SELECT id FROM mail_accounts WHERE user_id = ? AND email = ?");
            $stmt->execute([$userId, $email]);
            $existing = $stmt->fetch();

            if ($existing) {
                $fields = [
                    'smtp_host' => $data['host'] ?? '',
                    'smtp_port' => (int)($data['port'] ?? 587),
                    'smtp_username' => $data['username'] ?? $email,
                    'smtp_encryption' => $data['encryption'] ?? 'tls',
                    'imap_host' => $data['imap_host'] ?? '',
                    'imap_port' => (int)($data['imap_port'] ?? 993),
                    'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
                    'display_name' => $data['display_name'] ?? 'TaskFlow Pro',
                    'mail_signature' => $data['signature'] ?? '',
                ];

                $sql = "UPDATE mail_accounts SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_encryption = ?, imap_host = ?, imap_port = ?, imap_encryption = ?, display_name = ?, mail_signature = ?";
                $params = [
                    $fields['smtp_host'],
                    $fields['smtp_port'],
                    $fields['smtp_username'],
                    $fields['smtp_encryption'],
                    $fields['imap_host'],
                    $fields['imap_port'],
                    $fields['imap_encryption'],
                    $fields['display_name'],
                    $fields['mail_signature'],
                ];

                if (!empty($data['password'])) {
                    $sql .= ", smtp_password = ?";
                    $params[] = appEncrypt($data['password']);
                }

                $sql .= " WHERE id = ? AND user_id = ?";
                $params[] = (int)$existing['id'];
                $params[] = $userId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO mail_accounts (user_id, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, imap_host, imap_port, imap_encryption, display_name, mail_signature, is_default)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $userId,
                    $email,
                    $data['host'] ?? '',
                    (int)($data['port'] ?? 587),
                    $data['username'] ?? $email,
                    appEncrypt($data['password'] ?? ''),
                    $data['encryption'] ?? 'tls',
                    $data['imap_host'] ?? '',
                    (int)($data['imap_port'] ?? 993),
                    $data['imap_encryption'] ?? 'ssl',
                    $data['display_name'] ?? 'TaskFlow Pro',
                    $data['signature'] ?? '',
                ]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Настройки почты сохранены']);
        exit;
    }

    // POST /api/profile/avatar - загрузить аватар
    if ($method === 'POST' && $action === 'avatar') {
        if (!isset($_FILES['avatar'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }
        
        $uploadedFile = $_FILES['avatar'];
        
        // Проверка на ошибки
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла']);
            exit;
        }
        
        // Проверка типа файла
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($uploadedFile['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недопустимый формат файла']);
            exit;
        }
        
        // Проверка размера (макс 5MB)
        if ($uploadedFile['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл слишком большой (макс 5MB)']);
            exit;
        }
        
        // Генерируем уникальное имя
        $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $newName = 'avatar_' . $currentUser['id'] . '_' . uniqid() . '.' . $extension;
        $targetPath = $uploadDir . $newName;
        
        // Удаляем старый аватар
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $oldAvatar = $stmt->fetchColumn();
        
        if ($oldAvatar && file_exists($uploadDir . $oldAvatar)) {
            unlink($uploadDir . $oldAvatar);
        }
        
        // Перемещаем файл
        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
            exit;
        }
        
        // Создаём миниатюру
        createAvatarThumbnail($targetPath, $uploadDir . 'thumb_' . $newName, 100);
        
        // Сохраняем в БД
        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->execute([$newName, $currentUser['id']]);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'avatar' => $newName,
                'avatar_url' => 'uploads/avatars/' . $newName
            ]
        ]);
        exit;
    }
    
    // DELETE /api/profile/avatar - удалить аватар
    if ($method === 'DELETE' && $action === 'avatar') {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $avatar = $stmt->fetchColumn();
        
        if ($avatar) {
            // Удаляем файлы
            if (file_exists($uploadDir . $avatar)) {
                unlink($uploadDir . $avatar);
            }
            if (file_exists($uploadDir . 'thumb_' . $avatar)) {
                unlink($uploadDir . 'thumb_' . $avatar);
            }
            
            // Очищаем в БД
            $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
            $stmt->execute([$currentUser['id']]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Аватар удалён']);
        exit;
    }

    // ============================================
    // PUT /api/profile/:id/email-settings - Сохранить настройки почты
    // ============================================
    if ($method === 'PUT' && $action !== null && is_numeric($action) && $id === 'email-settings') {
        $userId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);

        // Проверка что пользователь редактирует свои настройки
        if ($userId != $currentUser['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет доступа']);
            exit;
        }

        if (empty($data['email']) || empty($data['smtp_host']) || empty($data['smtp_password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля']);
            exit;
        }

        // Проверяем существует ли аккаунт
        $stmt = $pdo->prepare("SELECT id FROM mail_accounts WHERE user_id = ? AND email = ?");
        $stmt->execute([$userId, $data['email']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Обновляем существующий
            $stmt = $pdo->prepare("
                UPDATE mail_accounts 
                SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, smtp_encryption = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $data['smtp_host'],
                $data['smtp_port'] ?? 587,
                $data['smtp_username'] ?? $data['email'],
                password_hash($data['smtp_password'], PASSWORD_DEFAULT),
                $data['smtp_encryption'] ?? 'tls',
                $existing['id'],
                $userId
            ]);
        } else {
            // Создаём новый
            $stmt = $pdo->prepare("
                INSERT INTO mail_accounts (user_id, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $data['email'],
                $data['smtp_host'],
                $data['smtp_port'] ?? 587,
                $data['smtp_username'] ?? $data['email'],
                password_hash($data['smtp_password'], PASSWORD_DEFAULT),
                $data['smtp_encryption'] ?? 'tls'
            ]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}

/**
 * Создание миниатюры аватара
 */
function createAvatarThumbnail($sourcePath, $targetPath, $size = 100) {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) return false;
    
    $imageType = $imageInfo[2];
    $sourceWidth = $imageInfo[0];
    $sourceHeight = $imageInfo[1];
    
    // Создаём изображение
    $sourceImage = null;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
    }
    
    if (!$sourceImage) return false;
    
    // Вычисляем размеры для кропа (square crop)
    $minSize = min($sourceWidth, $sourceHeight);
    $centerX = intval($sourceWidth / 2);
    $centerY = intval($sourceHeight / 2);
    
    // Создаём миниатюру
    $thumbImage = imagecreatetruecolor($size, $size);
    
    // Копируем с кропом по центру
    imagecopyresampled(
        $thumbImage, $sourceImage,
        0, 0,
        $centerX - intval($minSize / 2),
        $centerY - intval($minSize / 2),
        $size, $size,
        $minSize, $minSize
    );
    
    // Сохраняем
    imagejpeg($thumbImage, $targetPath, 90);
    
    imagedestroy($sourceImage);
    imagedestroy($thumbImage);

    return true;
}
