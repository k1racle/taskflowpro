<?php
/**
 * tools/rbac_remove_user_roles.php
 *
 * Миграция/очистка для существующих установок: отключённая таблица `user_roles`.
 *
 * В актуальной модели RBAC источник роли — `users.role` (одна роль на пользователя),
 * а права вычисляются через `roles` + `role_permissions` + `permissions`.
 *
 * Что делает скрипт:
 * - Если таблицы `user_roles` нет — ничего не делает.
 * - Если таблица есть — создаёт бэкап `user_roles_backup_<timestamp>` и переносит туда данные.
 * - Затем удаляет таблицу `user_roles`.
 *
 * Запуск:
 *   php tools/rbac_remove_user_roles.php
 */

require_once __DIR__ . '/../api/config.php';

function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

try {
    $pdo = getPDO();

    if (!tableExists($pdo, 'user_roles')) {
        echo "OK: table `user_roles` not found, nothing to do.\n";
        exit(0);
    }

    // Бэкапим данные (на всякий случай).
    $ts = date('Ymd_His');
    $backupTable = 'user_roles_backup_' . $ts;

    // Структуру берём из оригинальной таблицы.
    $pdo->exec("CREATE TABLE `{$backupTable}` LIKE `user_roles`");
    $pdo->exec("INSERT INTO `{$backupTable}` SELECT * FROM `user_roles`");

    // Удаляем таблицу.
    $pdo->exec("DROP TABLE `user_roles`");

    echo "OK: table `user_roles` was backed up to `{$backupTable}` and dropped.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

