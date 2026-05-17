<?php
/**
 * api/tasks-save.php - Сохранение задачи (обход блокировки PUT)
 * Принимает POST вместо PUT
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tasks-direct.php';

// tasks-direct.php обрабатывает PUT, но мы вызываем его через POST
$_SERVER['REQUEST_METHOD'] = 'PUT';
$_GET['id'] = $_POST['id'] ?? $_GET['id'] ?? null;

// Читаем JSON из POST
if (isset($_POST['json'])) {
    $jsonData = json_decode($_POST['json'], true);
    if ($jsonData) {
        $_POST = array_merge($_POST, $jsonData);
    }
}

// Вызываем обработку из tasks-direct.php
// (код будет выполнен внутри require выше)
?>
