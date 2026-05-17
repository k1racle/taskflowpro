<?php
/**
 * api/permissions.php - Упрощённая система проверки прав доступа
 *
 * Принцип работы:
 * - root имеет ПОЛНЫЕ права на всё
 * - administrator имеет ПОЛНЫЕ права на всё
 * - manager может создавать/редактировать в своих отделах
 * - employee может только просматривать
 *
 * Если роль не указана явно - доступа нет
 */

/**
 * Получить все отделы пользователя
 */
function getUserDepartments($userId): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT d.*
        FROM departments d
        JOIN user_departments ud ON d.id = ud.department_id
        WHERE ud.user_id = ?
        ORDER BY d.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll() ?: [];
}

require_once __DIR__ . '/roles.php';

/**
 * Проверить принадлежит ли пользователь к отделу
 */
function isUserInDepartment($userId, $deptId): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM user_departments
        WHERE user_id = ? AND department_id = ?
    ");
    $stmt->execute([$userId, $deptId]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

/**
 * Проверка прав на создание отдела
 * root и administrator - всегда true
 */
function canCreateDepartment($user): bool {
    return hasPermission($user, 'departments.create');
}

/**
 * Проверка прав на редактирование отдела
 * root и administrator - всегда true
 */
function canEditDepartment($user, $deptId): bool {
    return hasPermission($user, 'departments.edit');
}

/**
 * Проверка прав на удаление отдела
 * root и administrator - всегда true
 */
function canDeleteDepartment($user, $deptId): bool {
    return hasPermission($user, 'departments.delete');
}

/**
 * Проверка прав на создание проекта
 * root и administrator - всегда true
 */
function canCreateProject($user, $deptId): bool {
    return hasPermission($user, 'projects.create');
}

/**
 * Проверка прав на редактирование проекта
 * root и administrator - всегда true
 */
function canEditProject($user, $project): bool {
    return hasPermission($user, 'projects.edit');
}

/**
 * Проверка прав на удаление проекта
 * root и administrator - всегда true
 */
function canDeleteProject($user, $project): bool {
    return hasPermission($user, 'projects.delete');
}

/**
 * Проверка прав на просмотр проекта
 * root и administrator - всегда true
 */
function canViewProject($user, $project): bool {
    return hasPermission($user, 'projects.view');
}

/**
 * Проверка прав на создание задачи
 * root и administrator - всегда true
 */
function canCreateTask($user, $deptId = null): bool {
    return hasPermission($user, 'tasks.create');
}

/**
 * Проверка прав на редактирование задачи
 * root и administrator - всегда true
 */
function canEditTask($user, $task): bool {
    return hasPermission($user, 'tasks.edit');
}

/**
 * Проверка прав на удаление задачи
 * root и administrator - всегда true
 */
function canDeleteTask($user, $task): bool {
    return hasPermission($user, 'tasks.delete');
}

/**
 * Проверка прав на просмотр задачи
 * root и administrator - всегда true
 */
function canViewTask($user, $task): bool {
    return hasPermission($user, 'tasks.view');
}

/**
 * Проверка прав на просмотр отдела
 * root и administrator - всегда true
 */
function canViewDepartment($user, $deptId): bool {
    return hasPermission($user, 'departments.view');
}

/**
 * Получить ID всех отделов пользователя
 */
function getUserDepartmentIds($userId): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT department_id
        FROM user_departments
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $result ?: [];
}
