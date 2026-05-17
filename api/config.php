<?php
/**
 * config.php - Конфигурация базы данных и JWT
 *
 * Отредактируйте данные подключения ниже
 */

require_once __DIR__ . '/app_version.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/audit.php';

// Значения ниже используются только как значения по умолчанию.
// При установке через install.php файл перезаписывается реальными данными.
define('DB_HOST', 'localhost');           // Хост MySQL (обычно localhost)
define('DB_NAME', 'taskflow');            // Имя базы данных
define('DB_USER', 'taskflow');            // Пользователь MySQL
define('DB_PASS', '');                    // Пароль MySQL

// DSN для подключения к БД
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4');

// JWT_SECRET из переменной окружения или значение по умолчанию.
// ВАЖНО: секрет должен быть стабильным, иначе после перезапуска PHP все токены станут невалидными.
// При установке через install.php он генерируется и записывается в этот файл.
define('JWT_SECRET', getenv('JWT_SECRET') ?: '');
define('JWT_EXPIRY', 86400); // 24 часа

// Минимальный login abuse protection foundation.
// После AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS неуспешных попыток за окно
// AUTH_LOGIN_THROTTLE_WINDOW_SECONDS включается временная блокировка
// на AUTH_LOGIN_THROTTLE_LOCK_SECONDS.
define('AUTH_LOGIN_THROTTLE_MAX_ATTEMPTS', 5);
define('AUTH_LOGIN_THROTTLE_WINDOW_SECONDS', 600);
define('AUTH_LOGIN_THROTTLE_LOCK_SECONDS', 900);

// Minimum viable audit retention/export foundation.
// По умолчанию audit-хвост храним 180 дней, после чего записи можно
// выгрузить через CLI и безопасно удалить через prune.
define('AUDIT_RETENTION_DAYS', max(1, (int)(getenv('AUDIT_RETENTION_DAYS') ?: 180)));

// License: allowed domain (hostname) for this installation.
// If empty - license checks are disabled (useful for local/dev).
define('LICENSE_DOMAIN', getenv('LICENSE_DOMAIN') ?: '');

// MVP referral integration settings.
// REFERRAL_SHARED_SECRET должен совпадать с настройкой WooCommerce plugin.
if (!defined('REFERRAL_SHARED_SECRET')) {
    define('REFERRAL_SHARED_SECRET', getenv('REFERRAL_SHARED_SECRET') ?: '');
}
// Базовый URL внешнего WooCommerce-сайта, на который ведёт реферальная ссылка.
if (!defined('REFERRAL_WOOCOMMERCE_BASE_URL')) {
    define('REFERRAL_WOOCOMMERCE_BASE_URL', rtrim((string)(getenv('REFERRAL_WOOCOMMERCE_BASE_URL') ?: ''), '/'));
}
if (!defined('REFERRAL_QUERY_PARAM')) {
    define('REFERRAL_QUERY_PARAM', getenv('REFERRAL_QUERY_PARAM') ?: 'ref');
}

// Секрет для шифрования чувствительных данных (например, паролей почты).
// По умолчанию используем JWT_SECRET, чтобы не плодить ещё один ключ.
// ВАЖНО: если сменить секрет, расшифровка старых данных станет невозможной.
define('APP_ENC_KEY', getenv('APP_ENC_KEY') ?: (JWT_SECRET ?: ''));

function appEncrypt(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    if (!APP_ENC_KEY) {
        throw new RuntimeException('APP_ENC_KEY/JWT_SECRET не задан — невозможно шифровать');
    }

    $key = hash('sha256', APP_ENC_KEY, true); // 32 bytes
    $iv = random_bytes(12); // GCM nonce
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Ошибка шифрования');
    }

    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function appDecrypt(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') return null;
    if (!APP_ENC_KEY) {
        throw new RuntimeException('APP_ENC_KEY/JWT_SECRET не задан — невозможно расшифровать');
    }

    if (!str_starts_with($encoded, 'v1:')) {
        // На всякий случай: если в БД лежит plaintext (старый режим)
        return $encoded;
    }

    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) < (12 + 16 + 1)) {
        throw new RuntimeException('Некорректные данные шифра');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $key = hash('sha256', APP_ENC_KEY, true);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Ошибка расшифровки');
    }
    return $plaintext;
}

/**
 * Получить PDO подключение к базе данных
 * @return PDO
 */
function getPDO(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

function getAppSchemaVersionStatus(?PDO $pdo = null): array {
    $pdo = $pdo ?? getPDO();
    return appGetSchemaStatus($pdo);
}

/**
 * Lazy-ensure для старых инсталляций: колонка users.last_activity
 * может отсутствовать, хотя chat/helpdesk пути уже на нее рассчитывают.
 */
function ensureUsersLastActivityColumn(?PDO $pdo = null): void {
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;
    $pdo = $pdo ?? getPDO();

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
        if ($stmt && $stmt->fetch()) {
            return;
        }
    } catch (Exception $e) {
        // На старых/нестандартных инсталляциях пробуем best-effort ALTER ниже.
    }

    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN `last_activity` TIMESTAMP NULL AFTER `last_login`");
    } catch (Exception $e) {
        $message = function_exists('mb_strtolower')
            ? mb_strtolower($e->getMessage(), 'UTF-8')
            : strtolower($e->getMessage());

        if (strpos($message, 'duplicate column') !== false || strpos($message, 'already exists') !== false) {
            return;
        }

        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN `last_activity` TIMESTAMP NULL");
        } catch (Exception $fallbackException) {
            $fallbackMessage = function_exists('mb_strtolower')
                ? mb_strtolower($fallbackException->getMessage(), 'UTF-8')
                : strtolower($fallbackException->getMessage());

            if (strpos($fallbackMessage, 'duplicate column') !== false || strpos($fallbackMessage, 'already exists') !== false) {
                return;
            }

            throw $fallbackException;
        }
    }
}

/**
 * Единый helper внутренних уведомлений.
 * Хранит совместимость со старой таблицей notifications без миграций.
 */
function createNotification(PDO $pdo, array $payload): bool {
    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
    $senderId = isset($payload['sender_id']) && $payload['sender_id'] !== null ? (int)$payload['sender_id'] : null;
    $message = trim((string)($payload['message'] ?? ''));
    $type = trim((string)($payload['type'] ?? 'info')) ?: 'info';
    $relatedId = isset($payload['related_id']) && $payload['related_id'] !== '' ? (int)$payload['related_id'] : null;
    $allowSelf = !empty($payload['allow_self']);

    if ($userId <= 0 || $message === '') {
        return false;
    }

    if (!$allowSelf && $senderId !== null && $senderId === $userId) {
        return false;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, sender_id, message, type, related_id)
         VALUES (?, ?, ?, ?, ?)"
    );

    return $stmt->execute([$userId, $senderId, $message, $type, $relatedId]);
}

function createNotifications(PDO $pdo, array $userIds, array $payload): int {
    $created = 0;
    $uniqueUserIds = array_values(array_unique(array_map('intval', $userIds)));
    foreach ($uniqueUserIds as $userId) {
        if (createNotification($pdo, $payload + ['user_id' => $userId])) {
            $created++;
        }
    }
    return $created;
}

function getDepartmentUserIds(PDO $pdo, int $departmentId): array {
    if ($departmentId <= 0) {
        return [];
    }

    $userIds = [];

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ?");
        $stmt->execute([$departmentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $userIds[(int)$userId] = true;
        }
    } catch (Exception $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ?");
        $stmt->execute([$departmentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $userIds[(int)$userId] = true;
        }
    } catch (Exception $e) {
        // ignore
    }

    $result = array_map('intval', array_keys($userIds));
    sort($result);
    return $result;
}

function getUserIdsByRoles(PDO $pdo, array $roles): array {
    $roles = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $roles), static fn($value) => $value !== ''));
    if (!$roles) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders)");
    $stmt->execute($roles);
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $userIds = array_values(array_unique($userIds));
    sort($userIds);
    return $userIds;
}
