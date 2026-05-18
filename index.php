<?php
/**
 * TaskFlow Pro - Входная точка (резервный вариант)
 * Работает если .htaccess отключён
 */

require_once __DIR__ . '/api/security.php';

// Если инстанс ещё не настроен, отправляем на установщик.
if (!appSecurityIsConfigured()) {
    header('Location: install.php');
    exit;
}

// Отдаём index.html
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
?>
