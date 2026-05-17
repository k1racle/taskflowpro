<?php

return [
    'id' => '202605070001',
    'name' => 'users_last_activity',
    'description' => 'Add users.last_activity column for auth, chat and helpdesk activity tracking.',
    'up' => static function (PDO $pdo): void {
        if (!appTableExists($pdo, 'users')) {
            return;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN `last_activity` TIMESTAMP NULL AFTER `last_login`");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `last_activity` TIMESTAMP NULL");
        }
    },
];

