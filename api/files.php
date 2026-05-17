<?php
/**
 * api/files.php - Файловый менеджер
 *
 * Эндпоинты:
 * - GET /api/files - список файлов
 * - GET /api/files/:id/download - скачать файл
 * - GET /api/files/:id/preview - предпросмотр файла
 * - POST /api/files - загрузка файла
 * - PUT /api/files/:id - переименовать файл
 * - DELETE /api/files/:id - удаление файла
 * - PATCH /api/files/move - перемещение файлов
 * - GET /api/files/folders - список папок
 * - POST /api/files/folders - создать папку
 * - PUT /api/files/folders/:id - переименовать папку
 * - DELETE /api/files/folders/:id - удалить папку
 * - GET /api/files/tree - дерево папок
 */

/**
 * Обработка запросов к /api/files/*
 */
function handleFiles(string $method, ?string $action, mixed $id, ?string $subaction = null): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    require_once __DIR__ . '/disk.php';

    $uploadDir = __DIR__ . '/../uploads/disk/';

    // Создаём папку для загрузок если не существует
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // ============================================
    // ПАПКИ
    // ============================================

    // GET /api/files/tree - получить дерево всех папок
    if ($method === 'GET' && $action === 'tree') {
        // Получаем ТОЛЬКО корневые папки пользователя (без project_id и task_id)
        // Это "общие" папки верхнего уровня
        $stmt = $pdo->prepare("
            SELECT f.*,
                   (SELECT COUNT(*) FROM file_folders c WHERE c.parent_id = f.id) as children_count,
                   (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id) as files_count
            FROM file_folders f
            WHERE f.parent_id IS NULL
            ORDER BY f.name ASC
        ");
        $stmt->execute();
        $rootFolders = $stmt->fetchAll();

        // Рекурсивно получаем дочерние папки
        function getChildren($pdo, $parentId) {
            $stmt = $pdo->prepare("
                SELECT f.*,
                       (SELECT COUNT(*) FROM file_folders c WHERE c.parent_id = f.id) as children_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id) as files_count
                FROM file_folders f
                WHERE f.parent_id = ?
                ORDER BY f.name ASC
            ");
            $stmt->execute([$parentId]);
            $children = $stmt->fetchAll();
            foreach ($children as &$child) {
                $child['children'] = getChildren($pdo, $child['id']);
            }
            return $children;
        }

        foreach ($rootFolders as &$folder) {
            $folder['children'] = getChildren($pdo, $folder['id']);
        }

        echo json_encode(['success' => true, 'data' => $rootFolders]);
        exit;
    }

    // GET /api/files/folders - список папок в текущей папке или по parent_id
    if ($method === 'GET' && $action === 'folders') {
        $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
        $all = isset($_GET['all']) && $_GET['all'] === '1';

        if ($all) {
            $stmt = $pdo->prepare("
                SELECT f.*, 
                       (SELECT COUNT(*) FROM file_folders c WHERE c.parent_id = f.id) as children_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id) as files_count
                FROM file_folders f 
                ORDER BY f.name ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT f.*, 
                       (SELECT COUNT(*) FROM file_folders c WHERE c.parent_id = f.id) as children_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id) as files_count
                FROM file_folders f 
                WHERE f.parent_id " . ($parentId === null ? "IS NULL" : "= ?") . " 
                ORDER BY f.name ASC
            ");
            $stmt->execute($parentId === null ? [] : [$parentId]);
        }

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // POST /api/files/folders - создать папку
    if ($method === 'POST' && $action === 'folders') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim((string)($data['name'] ?? ''));
        $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : null;

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите имя папки']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO file_folders (parent_id, name, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$parentId, $name, $currentUser['id']]);
        echo json_encode(['success' => true, 'data' => ['id' => $pdo->lastInsertId()]]);
        exit;
    }

    // PUT /api/files/folders/:id - переименовать папку
    if ($method === 'PUT' && $action === 'folders' && $id !== null && is_numeric($id)) {
        $folderId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите имя папки']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE file_folders SET name = ? WHERE id = ?");
        $stmt->execute([$name, $folderId]);
        echo json_encode(['success' => true, 'message' => 'Папка переименована']);
        exit;
    }

    // DELETE /api/files/folders/:id - удалить папку (каскад по дочерним папкам)
    if ($method === 'DELETE' && $action === 'folders' && $id !== null && is_numeric($id)) {
        $folderId = (int)$id;

        // не даём удалить, если в папке есть файлы или дочерние папки
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM files WHERE folder_id = ?");
        $stmt->execute([$folderId]);
        $filesCount = (int)($stmt->fetch()['cnt'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM file_folders WHERE parent_id = ?");
        $stmt->execute([$folderId]);
        $foldersCount = (int)($stmt->fetch()['cnt'] ?? 0);
        
        if ($filesCount > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Папка не пуста - сначала удалите файлы']);
            exit;
        }
        
        if ($foldersCount > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Папка содержит другие папки - сначала удалите их']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM file_folders WHERE id = ?");
        $stmt->execute([$folderId]);
        echo json_encode(['success' => true, 'message' => 'Папка удалена']);
        exit;
    }

    // ============================================
    // ФАЙЛЫ - СКАЧИВАНИЕ И ПРЕДПРОСМОТР
    // ============================================

    // GET /api/files/:id/preview - предпросмотр файла (inline)
    if ($method === 'GET' && $subaction === 'preview' && $id !== null) {
        $fileId = (int)$id;
        error_log('Files preview: id=' . $fileId);

        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            error_log('Files preview: file not found in DB, id=' . $fileId);
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            exit;
        }

        error_log('Files preview: file found, name=' . $file['name'] . ', folder_id=' . ($file['folder_id'] ?? 'null'));

        $filePath = $uploadDir . $file['name'];
        if (!empty($file['folder_id'])) {
            $filePath = $uploadDir . 'folder_' . $file['folder_id'] . '/' . $file['name'];
        }

        error_log('Files preview: checking path=' . $filePath);

        if (!file_exists($filePath)) {
            error_log('Files preview: file not found on disk, path=' . $filePath);
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден на сервере']);
            exit;
        }

        // Определяем MIME тип
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // Всегда отдаём inline для предпросмотра
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . basename($file['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    // GET /api/files/:id/download - скачать файл
    if ($method === 'GET' && $subaction === 'download' && $id !== null) {
        $fileId = (int)$id;

        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            exit;
        }

        $filePath = $uploadDir . $file['name'];
        if (!empty($file['folder_id'])) {
            $filePath = $uploadDir . 'folder_' . $file['folder_id'] . '/' . $file['name'];
        }

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден на сервере']);
            exit;
        }

        if (!is_file($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Это не файл, а директория']);
            exit;
        }

        // Определяем MIME тип
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // Отправляем файл
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    // ============================================
    // ФАЙЛЫ - СПИСОК, ЗАГРУЗКА, УДАЛЕНИЕ
    // ============================================

    // GET /api/files - список файлов
    if ($method === 'GET' && $action === null) {
        $taskFilter = $_GET['task_id'] ?? null;
        $projectFilter = $_GET['project_id'] ?? null;
        $folderFilter = $_GET['folder_id'] ?? null;

        $sql = "
            SELECT f.*,
                   u.full_name as uploader_name
            FROM files f
            LEFT JOIN users u ON f.uploaded_by = u.id
        ";

        $params = [];
        $where = [];

        if ($taskFilter) {
            $where[] = "f.task_id = ?";
            $params[] = (int)$taskFilter;
        }

        if ($projectFilter) {
            $where[] = "f.project_id = ?";
            $params[] = (int)$projectFilter;
        }

        if ($folderFilter !== null && $folderFilter !== '') {
            $where[] = "f.folder_id = ?";
            $params[] = (int)$folderFilter;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY f.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $files]);
        exit;
    }

    // POST /api/files - загрузка файла
    if ($method === 'POST' && $action === null) {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }

        $uploadedFile = $_FILES['file'];

        // Проверка на ошибки
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла']);
            exit;
        }

        // Проверка размера (макс 10MB)
        if ($uploadedFile['size'] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл слишком большой (макс 10MB)']);
            exit;
        }

        // Вычисляем папку назначения по иерархии (folder_id или project_id/task_id)
        $targetFolderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
        $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
        $taskId = isset($_POST['task_id']) && $_POST['task_id'] !== '' ? (int)$_POST['task_id'] : null;

        if ($taskId) {
            $stmt = $pdo->prepare("SELECT t.title, t.project_id, p.name as project_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.id = ? LIMIT 1");
            $stmt->execute([$taskId]);
            $t = $stmt->fetch();
            if ($t && !empty($t['project_id'])) {
                $projectFolderId = ensureProjectDiskFolder($pdo, (int)$t['project_id'], (string)($t['project_name'] ?? ('Проект #' . $t['project_id'])), (int)$currentUser['id']);
                $targetFolderId = ensureTaskDiskFolder($pdo, $projectFolderId, $taskId, (string)($t['title'] ?? ('Задача #' . $taskId)), (int)$currentUser['id']);
            }
        } elseif ($projectId) {
            $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? LIMIT 1");
            $stmt->execute([$projectId]);
            $p = $stmt->fetch();
            if ($p) {
                $targetFolderId = ensureProjectDiskFolder($pdo, $projectId, (string)$p['name'], (int)$currentUser['id']);
            }
        }

        // Директория на диске по folder_id
        $diskFolderPath = $uploadDir;
        if ($targetFolderId) {
            $diskFolderPath = $uploadDir . 'folder_' . $targetFolderId . '/';
            if (!is_dir($diskFolderPath)) {
                mkdir($diskFolderPath, 0755, true);
            }
        }

        // Генерируем уникальное имя
        $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $newName = uniqid('file_') . '.' . $extension;
        $targetPath = $diskFolderPath . $newName;

        // Перемещаем файл
        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
            exit;
        }

        // Сохраняем в БД
        $stmt = $pdo->prepare("
            INSERT INTO files (name, original_name, mime_type, size, folder_id, task_id, project_id, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $newName,
            $uploadedFile['name'],
            $uploadedFile['type'] ?: 'application/octet-stream',
            $uploadedFile['size'],
            $targetFolderId,
            $taskId,
            $projectId,
            $currentUser['id']
        ]);

        $fileId = $pdo->lastInsertId();

        // Сохраняем в историю задачи если файл прикреплён к задаче
        if (!empty($_POST['task_id'])) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO task_history (task_id, user_id, action, field_name, new_value)
                    VALUES (?, ?, 'file_added', 'file', ?)
                ");
                $stmt->execute([$_POST['task_id'], $currentUser['id'], 'Прикреплён файл: ' . $uploadedFile['name']]);
            } catch (Exception $e) {
                error_log('File history error: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $fileId,
                'name' => $uploadedFile['name'],
                'size' => $uploadedFile['size'],
                'message' => 'Файл загружен'
            ]
        ]);
        exit;
    }

    // DELETE /api/files/:id - удаление файла
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        $fileId = (int)$action;

        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            exit;
        }

        // Удаляем файл с диска
        $filePath = $uploadDir . $file['name'];
        if (!empty($file['folder_id'])) {
            $filePath = $uploadDir . 'folder_' . $file['folder_id'] . '/' . $file['name'];
        }
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Удаляем из БД
        $stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
        $stmt->execute([$fileId]);

        // Сохраняем в историю задачи если файл был прикреплён к задаче
        if ($file['task_id']) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO task_history (task_id, user_id, action, field_name, old_value)
                    VALUES (?, ?, 'file_removed', 'file', ?)
                ");
                $stmt->execute([$file['task_id'], $currentUser['id'], 'Удалён файл: ' . $file['original_name']]);
            } catch (Exception $e) {
                error_log('File delete history error: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'message' => 'Файл удалён']);
        exit;
    }

    // PATCH /api/files/move - перемещение файлов в папку
    if ($method === 'PATCH' && $action === 'move') {
        $data = json_decode(file_get_contents('php://input'), true);
        $fileIds = $data['file_ids'] ?? [];
        $folderId = array_key_exists('folder_id', $data) ? ($data['folder_id'] === null ? null : (int)$data['folder_id']) : null;

        if (!is_array($fileIds) || empty($fileIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'file_ids required']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE files SET folder_id = ? WHERE id = ?");
        foreach ($fileIds as $fid) {
            if (is_numeric($fid)) {
                $stmt->execute([$folderId, (int)$fid]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Файлы перемещены']);
        exit;
    }

    error_log('Files endpoint not found: method=' . $method . ', action=' . ($action ?? 'null') . ', id=' . ($id ?? 'null') . ', subaction=' . ($subaction ?? 'null'));
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
