<?php
/**
 * api/disk.php - helpers for Disk folders hierarchy
 */

function ensureProjectDiskFolder(PDO $pdo, int $projectId, string $projectName, ?int $userId = null): int {
    $stmt = $pdo->prepare("SELECT id FROM file_folders WHERE project_id = ? AND task_id IS NULL LIMIT 1");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    if ($row && !empty($row['id'])) return (int)$row['id'];

    $stmt = $pdo->prepare("INSERT INTO file_folders (parent_id, name, project_id, task_id, created_by) VALUES (NULL, ?, ?, NULL, ?)");
    $stmt->execute([$projectName, $projectId, $userId]);
    return (int)$pdo->lastInsertId();
}

function ensureTaskDiskFolder(PDO $pdo, int $projectFolderId, int $taskId, string $taskTitle, ?int $userId = null): int {
    $stmt = $pdo->prepare("SELECT id FROM file_folders WHERE task_id = ? LIMIT 1");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if ($row && !empty($row['id'])) return (int)$row['id'];

    $stmt = $pdo->prepare("INSERT INTO file_folders (parent_id, name, project_id, task_id, created_by) VALUES (?, ?, NULL, ?, ?)");
    $stmt->execute([$projectFolderId, $taskTitle, $taskId, $userId]);
    return (int)$pdo->lastInsertId();
}

function ensureTelegramDiskFolders(PDO $pdo, int $userId, ?string $userDisplayName = null): array {
    $rootName = 'telegram';
    $userFolderName = $userDisplayName ? trim($userDisplayName) : ('user_' . (int)$userId);

    $stmt = $pdo->prepare("SELECT id FROM file_folders WHERE parent_id IS NULL AND project_id IS NULL AND task_id IS NULL AND name = ? LIMIT 1");
    $stmt->execute([$rootName]);
    $root = $stmt->fetch();
    if ($root && !empty($root['id'])) {
        $rootId = (int)$root['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO file_folders (parent_id, name, project_id, task_id, created_by) VALUES (NULL, ?, NULL, NULL, ?)");
        $stmt->execute([$rootName, $userId]);
        $rootId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT id FROM file_folders WHERE parent_id = ? AND project_id IS NULL AND task_id IS NULL AND name = ? LIMIT 1");
    $stmt->execute([$rootId, $userFolderName]);
    $userRow = $stmt->fetch();
    if ($userRow && !empty($userRow['id'])) {
        $userFolderId = (int)$userRow['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO file_folders (parent_id, name, project_id, task_id, created_by) VALUES (?, ?, NULL, NULL, ?)");
        $stmt->execute([$rootId, $userFolderName, $userId]);
        $userFolderId = (int)$pdo->lastInsertId();
    }

    return ['root_id' => $rootId, 'user_id' => $userFolderId];
}
