<?php
/**
 * api/auth.php - Авторизация, регистрация и JWT токены
 * 
 * Функции:
 * - generateJWT($user) - создание JWT токена
 * - verifyJWT($token) - проверка JWT токена
 * - getCurrentUser() - получение текущего пользователя из токена
 * - handleAuth() - обработка запросов к /api/auth/*
 */

require_once __DIR__ . '/audit.php';

// Функция getallheaders() может отсутствовать на некоторых серверах
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Простая реализация JWT без внешних библиотек
function base64UrlEncode($data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Генерация JWT токена
 * @param array $user Данные пользователя
 * @return string JWT токен
 */
function generateJWT(array $user): string {
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    
    $payload = [
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY,
        'user_id' => $user['id'],
        'login' => $user['login'],
        'role' => $user['role'],
        'full_name' => $user['full_name'] ?? ''
    ];
    
    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);
    
    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * Проверка JWT токена
 * @param string $token JWT токен
 * @return array|false Данные пользователя или false при ошибке
 */
function verifyJWT(string $token): array|false {
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
    
    // Проверка подписи
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureExpected = base64UrlEncode($signature);
    
    if (!hash_equals($signatureExpected, $signatureEncoded)) {
        return false;
    }
    
    // Декодирование payload
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    
    if (!$payload) {
        return false;
    }
    
    // Проверка времени жизни
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
}

/**
 * Получение текущего пользователя из JWT токена
 * @return array|null Данные пользователя или null
 */
function getCurrentUser(): ?array {
    // Debug: проверяем JWT_SECRET
    if (empty(JWT_SECRET)) {
        error_log('getCurrentUser: JWT_SECRET is empty!');
    }
    
    // Пробуем разные варианты получения токена
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ??
                  $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ??
                  $_SERVER['HTTP_X_AUTHORIZATION'] ??
                  $_SERVER['REDIRECT_X_AUTHORIZATION'] ??
                  '';

    // Если заголовок не найден, пробуем получить из cookie (приоритет)
    if (empty($authHeader) && !empty($_COOKIE['jwt_token'])) {
        $authHeader = 'Bearer ' . $_COOKIE['jwt_token'];
    }

    // Если заголовок не найден, пробуем получить из query-параметра (для отладки)
    if (empty($authHeader) && !empty($_GET['token'])) {
        $authHeader = 'Bearer ' . $_GET['token'];
        error_log('getCurrentUser: token from $_GET[token], length=' . strlen($_GET['token']));
    }

    // Если заголовок не найден, пробуем получить из POST (для обратной совместимости с localStorage)
    // ВАЖНО: php://input можно прочитать только один раз! Проверяем, есть ли уже прочитанные данные
    if (empty($authHeader)) {
        // Проверяем, есть ли уже прочитанные данные в глобальной переменной
        global $POST_DATA_CACHE;
        if ($POST_DATA_CACHE !== null) {
            $postData = $POST_DATA_CACHE;
        } else {
            // Читаем только если ещё не было прочитано
            $postData = json_decode(file_get_contents('php://input'), true);
            $POST_DATA_CACHE = $postData;  // Кэшируем для повторного использования
        }
        if ($postData && !empty($postData['token'])) {
            $authHeader = 'Bearer ' . $postData['token'];
        }
    }

    // Если заголовок не найден, пробуем получить из заголовка запроса
    if (empty($authHeader)) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ??
                      $headers['authorization'] ??
                      $headers['X-Authorization'] ??
                      '';
    }

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }

    $token = substr($authHeader, 7);
    $payload = verifyJWT($token);

    if (!$payload) {
        return null;
    }

    try {
        $pdo = getPDO();
        ensureUsersLastActivityColumn($pdo);
        $stmt = $pdo->prepare(" 
            SELECT u.id, u.login, u.full_name, u.role, u.department_id, u.avatar, u.created_at, u.last_login,
                   d.name as department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = ?
        ");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            // Обновляем last_login только если прошло больше 5 минут
            $lastLogin = strtotime($user['last_login'] ?? 0);
            if (time() - $lastLogin > 300) {
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                    ->execute([$payload['user_id']]);
            }
        }

        return $user ?: null;
    } catch (PDOException $e) {
        error_log('getCurrentUser error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Проверка прав доступа
 * @param string|array $roles Разрешённые роли
 * @return bool
 */
function checkRole(string|array $roles): bool {
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($user['role'], $roles);
}

/**
 * Требовать авторизацию
 * @return array Данные текущего пользователя
 */
function requireAuth(): array {
    $user = getCurrentUser();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    return $user;
}

function appAuthThrottleTableName(): string {
    return 'auth_login_throttle';
}

function appAuthNormalizeLogin(string $login): string {
    $login = trim($login);
    if ($login === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($login, 'UTF-8')
        : strtolower($login);
}

function appAuthClientIp(): string {
    return appAuditIpAddress() ?? 'unknown';
}

function appAuthEnsureThrottleTable(PDO $pdo): void {
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . appAuthThrottleTableName() . " (
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
}

function appAuthThrottleNow(): int {
    return time();
}

function appAuthFormatSqlDateTime(int $timestamp): string {
    return date('Y-m-d H:i:s', $timestamp);
}

function appAuthRemainingLockSeconds(?string $lockExpiresAt, ?int $now = null): int {
    if (!$lockExpiresAt) {
        return 0;
    }

    $now = $now ?? appAuthThrottleNow();
    $expiresAt = strtotime($lockExpiresAt);
    if ($expiresAt === false) {
        return 0;
    }

    return max(0, $expiresAt - $now);
}

function appAuthLoadThrottleState(PDO $pdo, string $login): ?array {
    appAuthEnsureThrottleTable($pdo);

    $loginKey = appAuthNormalizeLogin($login);
    $ipAddress = appAuthClientIp();

    $stmt = $pdo->prepare(
        'SELECT id, login_key, login_value, ip_address, failed_attempts, first_failed_at, last_failed_at, lock_expires_at
         FROM ' . appAuthThrottleTableName() . '
         WHERE login_key = ? AND ip_address = ?
         LIMIT 1'
    );
    $stmt->execute([$loginKey, $ipAddress]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $row['failed_attempts'] = isset($row['failed_attempts']) ? (int)$row['failed_attempts'] : 0;
    return $row;
}

function appAuthGetThrottleStatus(PDO $pdo, string $login): array {
    $state = appAuthLoadThrottleState($pdo, $login);
    $remainingSeconds = appAuthRemainingLockSeconds($state['lock_expires_at'] ?? null);

    return [
        'locked' => $remainingSeconds > 0,
        'remaining_seconds' => $remainingSeconds,
        'state' => $state,
    ];
}

function appAuthClearThrottle(PDO $pdo, string $login): void {
    appAuthEnsureThrottleTable($pdo);

    $stmt = $pdo->prepare(
        'DELETE FROM ' . appAuthThrottleTableName() . ' WHERE login_key = ? AND ip_address = ?'
    );
    $stmt->execute([appAuthNormalizeLogin($login), appAuthClientIp()]);
}

function appAuthRegisterFailedAttempt(PDO $pdo, string $login): array {
    appAuthEnsureThrottleTable($pdo);

    $loginKey = appAuthNormalizeLogin($login);
    $loginValue = trim($login);
    $ipAddress = appAuthClientIp();
    $now = appAuthThrottleNow();
    $windowStartedAt = $now - AUTH_LOGIN_THROTTLE_WINDOW_SECONDS;
    $nowSql = appAuthFormatSqlDateTime($now);
    $lockExpiresAt = null;
    $lockTriggered = false;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, failed_attempts, first_failed_at, last_failed_at, lock_expires_at
             FROM ' . appAuthThrottleTableName() . '
             WHERE login_key = ? AND ip_address = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$loginKey, $ipAddress]);
        $row = $stmt->fetch();

        $failedAttempts = 1;
        $firstFailedAt = $nowSql;

        if ($row) {
            $lastFailedAtTs = !empty($row['last_failed_at']) ? strtotime((string)$row['last_failed_at']) : false;
            $windowExpired = $lastFailedAtTs === false || $lastFailedAtTs < $windowStartedAt;

            if (!$windowExpired) {
                $failedAttempts = ((int)($row['failed_attempts'] ?? 0)) + 1;
                $firstFailedAt = !empty($row['first_failed_at']) ? (string)$row['first_failed_at'] : $nowSql;
            }

            if ($failedAttempts >= AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS) {
                $lockTriggered = true;
                $lockExpiresAt = appAuthFormatSqlDateTime($now + AUTH_LOGIN_THROTTLE_LOCK_SECONDS);
            }

            $update = $pdo->prepare(
                'UPDATE ' . appAuthThrottleTableName() . '
                 SET login_value = ?, failed_attempts = ?, first_failed_at = ?, last_failed_at = ?, lock_expires_at = ?
                 WHERE id = ?'
            );
            $update->execute([
                $loginValue !== '' ? $loginValue : null,
                $failedAttempts,
                $firstFailedAt,
                $nowSql,
                $lockExpiresAt,
                $row['id'],
            ]);
        } else {
            if ($failedAttempts >= AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS) {
                $lockTriggered = true;
                $lockExpiresAt = appAuthFormatSqlDateTime($now + AUTH_LOGIN_THROTTLE_LOCK_SECONDS);
            }

            $insert = $pdo->prepare(
                'INSERT INTO ' . appAuthThrottleTableName() . ' (
                    login_key, login_value, ip_address, failed_attempts, first_failed_at, last_failed_at, lock_expires_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $loginKey,
                $loginValue !== '' ? $loginValue : null,
                $ipAddress,
                $failedAttempts,
                $firstFailedAt,
                $nowSql,
                $lockExpiresAt,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'failed_attempts' => $failedAttempts,
        'lock_triggered' => $lockTriggered,
        'remaining_seconds' => $lockExpiresAt ? AUTH_LOGIN_THROTTLE_LOCK_SECONDS : 0,
    ];
}

function appAuthAuditLoginEvent(PDO $pdo, string $eventType, array $payload = []): void {
    $login = trim((string)($payload['login'] ?? ''));
    $user = $payload['user'] ?? null;
    $details = [
        'login' => $login !== '' ? $login : null,
        'auth_flow' => $payload['auth_flow'] ?? null,
        'ip_address' => appAuthClientIp(),
    ];

    if (array_key_exists('reason', $payload)) {
        $details['reason'] = $payload['reason'];
    }
    if (array_key_exists('failed_attempts', $payload)) {
        $details['failed_attempts'] = (int)$payload['failed_attempts'];
    }
    if (array_key_exists('remaining_seconds', $payload)) {
        $details['remaining_seconds'] = (int)$payload['remaining_seconds'];
    }

    auditLog($pdo, $eventType, [
        'actor' => $user,
        'target_type' => 'user',
        'target_id' => isset($user['id']) ? (string)$user['id'] : null,
        'summary' => $payload['summary'] ?? $eventType,
        'details' => $details,
    ]);
}

function appAuthFindUserByLogin(PDO $pdo, string $login): ?array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.login, u.password_hash, u.full_name, u.role, u.department_id,
                u.avatar, u.created_at, u.last_login,
                COALESCE(d.name, 'Без отдела') as department_name
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE u.login = ?
         LIMIT 1"
    );
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function appAuthProcessLogin(PDO $pdo, array $data, string $authFlow): array {
    $loginValue = trim((string)($data['login'] ?? ''));
    $password = (string)($data['password'] ?? '');

    error_log(sprintf(
        '[AUTH] login attempt login=%s uri=%s origin=%s remote_addr=%s auth_header_present=%s cookie_present=%s flow=%s',
        $loginValue !== '' ? $loginValue : '<empty>',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['HTTP_ORIGIN'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        !empty($_SERVER['HTTP_AUTHORIZATION']) ? 'true' : 'false',
        !empty($_COOKIE['jwt_token']) ? 'true' : 'false',
        $authFlow
    ));

    if ($loginValue === '' || $password === '') {
        error_log('[AUTH] login validation failed: missing login or password');
        return [
            'http_status' => 400,
            'body' => ['success' => false, 'error' => 'Укажите логин и пароль'],
        ];
    }

    $throttle = appAuthGetThrottleStatus($pdo, $loginValue);
    if (!empty($throttle['locked'])) {
        appAuthAuditLoginEvent($pdo, 'auth.login.throttled', [
            'login' => $loginValue,
            'auth_flow' => $authFlow,
            'summary' => 'Временная блокировка логина из-за частых неуспешных попыток',
            'reason' => 'temporary_lock_active',
            'remaining_seconds' => $throttle['remaining_seconds'],
            'failed_attempts' => isset($throttle['state']['failed_attempts']) ? (int)$throttle['state']['failed_attempts'] : AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS,
        ]);

        return [
            'http_status' => 429,
            'body' => [
                'success' => false,
                'error' => 'Слишком много попыток входа. Повторите позже.',
                'retry_after_seconds' => $throttle['remaining_seconds'],
            ],
        ];
    }

    $user = appAuthFindUserByLogin($pdo, $loginValue);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $failedState = appAuthRegisterFailedAttempt($pdo, $loginValue);

        appAuthAuditLoginEvent($pdo, 'auth.login.failed', [
            'login' => $loginValue,
            'user' => $user,
            'auth_flow' => $authFlow,
            'summary' => 'Неуспешная попытка входа в систему',
            'reason' => 'invalid_credentials',
            'failed_attempts' => $failedState['failed_attempts'],
        ]);

        if (!empty($failedState['lock_triggered'])) {
            appAuthAuditLoginEvent($pdo, 'auth.login.throttled', [
                'login' => $loginValue,
                'user' => $user,
                'auth_flow' => $authFlow,
                'summary' => 'Логин временно заблокирован после серии неуспешных попыток',
                'reason' => 'temporary_lock_started',
                'failed_attempts' => $failedState['failed_attempts'],
                'remaining_seconds' => $failedState['remaining_seconds'],
            ]);

            return [
                'http_status' => 429,
                'body' => [
                    'success' => false,
                    'error' => 'Слишком много попыток входа. Повторите позже.',
                    'retry_after_seconds' => $failedState['remaining_seconds'],
                ],
            ];
        }

        error_log('[AUTH] login rejected: invalid credentials for login=' . $loginValue);
        return [
            'http_status' => 401,
            'body' => ['success' => false, 'error' => 'Неверный логин или пароль'],
        ];
    }

    appAuthClearThrottle($pdo, $loginValue);

    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    $token = generateJWT($user);

    auditLog($pdo, 'auth.login.success', [
        'actor' => $user,
        'target_type' => 'user',
        'target_id' => (string)$user['id'],
        'summary' => 'Успешный вход в систему',
        'details' => [
            'login' => $user['login'] ?? null,
            'auth_flow' => $authFlow,
        ],
    ]);

    error_log('[AUTH] login success user_id=' . $user['id'] . ' flow=' . $authFlow);

    return [
        'http_status' => 200,
        'token' => $token,
        'user' => $user,
    ];
}

/**
 * Обработка запросов к /api/auth/*
 * @param string $method HTTP метод
 * @param string|null $action Действие
 * @param mixed $id ID ресурса
 */
function handleAuth(string $method, ?string $action, mixed $id): void {
    try {
        $pdo = getPDO();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка подключения к БД: ' . $e->getMessage()
        ]);
        return;
    }

    require_once __DIR__ . '/roles.php';

    // Кто я (получение текущего пользователя)
    if ($action === 'whoami' && $method === 'GET') {
        $user = getCurrentUser();
        
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        }
        exit;
    }
    
    // Логин
    if ($action === 'login' && $method === 'POST') {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $result = appAuthProcessLogin($pdo, is_array($data) ? $data : [], 'api/auth/login');

            http_response_code($result['http_status']);

            if ($result['http_status'] !== 200) {
                echo json_encode($result['body']);
                exit;
            }

            $token = $result['token'];
            $user = $result['user'];

            // Устанавливаем cookie с токеном (ModSecurity-safe)
            setcookie('jwt_token', $token, appSecurityGetCookieOptions(time() + JWT_EXPIRY));

            // Удаляем хеш пароля из ответа
            unset($user['password_hash']);

            echo json_encode([
                'success' => true,
                'data' => [
                    'token' => $token,  // Оставляем для обратной совместимости
                    'user' => $user
                ],
                'message' => 'Вход выполнен успешно'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            error_log('[AUTH] login exception: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Ошибка входа: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    // Регистрация (пользователь должен иметь право users.create)
    if ($action === 'register' && $method === 'POST') {
        $currentUser = getCurrentUser();
        
        if (!$currentUser || ($currentUser['role'] !== 'root' && !hasPermission($currentUser, 'users.create'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для регистрации пользователей']);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['login']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите логин и пароль']);
            exit;
        }

        // Валидация email
        if (!filter_var($data['login'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный формат email']);
            exit;
        }

        // Валидация сложности пароля
        if (strlen($data['password']) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов']);
            exit;
        }

        $requestedRole = trim((string)($data['role'] ?? 'employee'));
        if ($requestedRole === '') {
            $requestedRole = 'employee';
        }
        if ($requestedRole === 'root' && ($currentUser['role'] ?? null) !== 'root') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только root может регистрировать root-пользователей']);
            exit;
        }
        if ($requestedRole !== 'root' && hasPermission(['role' => $requestedRole], 'admin.full') && !hasPermission($currentUser, 'admin.full') && ($currentUser['role'] ?? null) !== 'root') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут регистрировать привилегированные роли']);
            exit;
        }
        
        // Проверка существования пользователя
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->execute([$data['login']]);

        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Пользователь с таким логином уже существует']);
            exit;
        }
        
        // Создание пользователя
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (login, password_hash, full_name, role, department_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['login'],
            $passwordHash,
            $data['full_name'] ?? '',
            $requestedRole,
            $data['department_id'] ?? null
        ]);
        
        $newUserId = $pdo->lastInsertId();

        auditLog($pdo, 'entity.user.created', [
            'actor' => $currentUser,
            'target_type' => 'user',
            'target_id' => (string)$newUserId,
            'summary' => 'Зарегистрирован пользователь',
            'details' => [
                'source' => 'auth.register',
                'login' => $data['login'],
                'full_name' => $data['full_name'] ?? '',
                'role' => $requestedRole,
                'department_id' => $data['department_id'] ?? null,
            ],
        ]);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $newUserId,
                'login' => $data['login'],
                'message' => 'Пользователь успешно создан'
            ]
        ]);
        exit;
    }
    
    // Logout
    if ($action === 'logout' && $method === 'POST') {
        // Очищаем cookie
        setcookie('jwt_token', '', appSecurityGetCookieOptions(time() - 3600));

        echo json_encode(['success' => true, 'message' => 'Выход выполнен']);
        exit;
    }

    // Refresh token (продление сессии)
    if ($action === 'refresh' && $method === 'POST') {
        $user = getCurrentUser();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }

        // Генерируем новый токен
        $newToken = generateJWT($user);

        // Устанавливаем cookie с новым токеном
        setcookie('jwt_token', $newToken, appSecurityGetCookieOptions(time() + JWT_EXPIRY));

        echo json_encode(['success' => true, 'message' => 'Токен обновлён']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
