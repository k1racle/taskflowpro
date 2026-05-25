<?php
/**
 * api/permissions.php - Упрощённая система проверки прав доступа
 *
 * Принцип работы:
 * - root имеет ПОЛНЫЕ права на всё
 * - administrator получает права только через RBAC (admin.full), не как break-glass
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
 * Проверка прав через permission codes
 */
function canCreateDepartment($user): bool {
    return hasPermission($user, 'departments.create');
}

/**
 * Проверка прав на редактирование отдела
 * Проверка через permission code departments.edit
 */
function canEditDepartment($user, $deptId): bool {
    return hasPermission($user, 'departments.edit');
}

/**
 * Проверка прав на удаление отдела
 * Проверка через permission code departments.delete
 */
function canDeleteDepartment($user, $deptId): bool {
    return hasPermission($user, 'departments.delete');
}

/**
 * Проверка прав на создание проекта
 * Проверка через permission code projects.create
 */
function canCreateProject($user, $deptId): bool {
    return hasPermission($user, 'projects.create');
}

/**
 * Проверка прав на редактирование проекта
 * Проверка через permission code projects.edit
 */
function canEditProject($user, $project): bool {
    return hasPermission($user, 'projects.edit');
}

/**
 * Проверка прав на удаление проекта
 * Проверка через permission code projects.delete
 */
function canDeleteProject($user, $project): bool {
    return hasPermission($user, 'projects.delete');
}

/**
 * Проверка прав на просмотр проекта
 * Проверка через permission code projects.view
 */
function canViewProject($user, $project): bool {
    return hasPermission($user, 'projects.view');
}

/**
 * Проверка прав на создание задачи
 * Проверка через permission code tasks.create
 */
function canCreateTask($user, $deptId = null): bool {
    return hasPermission($user, 'tasks.create');
}

/**
 * Проверка прав на редактирование задачи
 * Проверка через permission code tasks.edit
 */
function canEditTask($user, $task): bool {
    return hasPermission($user, 'tasks.edit');
}

/**
 * Проверка прав на удаление задачи
 * Проверка через permission code tasks.delete
 */
function canDeleteTask($user, $task): bool {
    return hasPermission($user, 'tasks.delete');
}

/**
 * Проверка прав на просмотр задачи
 * Проверка через permission code tasks.view
 */
function canViewTask($user, $task): bool {
    return hasPermission($user, 'tasks.view');
}

/**
 * Проверка прав на просмотр отдела
 * Проверка через permission code departments.view
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
