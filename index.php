<?php
/**
 * TaskFlow Pro - Входная точка (резервный вариант)
 * Работает если .htaccess отключён
 */

// Проверяем, существует ли config.php
if (!file_exists(__DIR__ . '/api/config.php')) {
    // Перенаправляем на установщик
    header('Location: install.php');
    exit;
}

// Отдаём index.html
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
?>
