<?php
/**
 * api/search.php - Глобальный поиск
 *
 * Эндпоинты:
 * - GET /api/search?q=запрос - поиск по задачам, проектам, пользователям, CRM
 */

/**
 * Обработка запросов к /api/search/*
 */
function handleSearch(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    // GET /api/search?q=запрос
    if ($method === 'GET' && $action === null) {
        $query = $_GET['q'] ?? '';

        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'data' => ['tasks' => [], 'projects' => [], 'users' => [], 'clients' => [], 'deals' => []]]);
            exit;
        }

        $searchTerm = '%' . $query . '%';

        // Поиск задач
        $tasksStmt = $pdo->prepare("
            SELECT t.id, t.title, t.status, t.priority, p.name as project_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.title LIKE ? OR t.description LIKE ?
            LIMIT 5
        ");
        $tasksStmt->execute([$searchTerm, $searchTerm]);
        $tasks = $tasksStmt->fetchAll();

        // Поиск проектов
        $projectsStmt = $pdo->prepare("
            SELECT p.id, p.name, p.description, d.name as department_name
            FROM projects p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.name LIKE ? OR p.description LIKE ?
            LIMIT 5
        ");
        $projectsStmt->execute([$searchTerm, $searchTerm]);
        $projects = $projectsStmt->fetchAll();

        // Поиск пользователей
        $usersStmt = $pdo->prepare("
            SELECT u.id, u.login, u.full_name, d.name as department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.login LIKE ? OR u.full_name LIKE ?
            LIMIT 5
        ");
        $usersStmt->execute([$searchTerm, $searchTerm]);
        $users = $usersStmt->fetchAll();

        // CRM: Клиенты
        try {
            $clientsStmt = $pdo->prepare("
                SELECT id, name, type, status, email, phone
                FROM crm_clients
                WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
                LIMIT 5
            ");
            $clientsStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $clients = $clientsStmt->fetchAll();
        } catch (Throwable $e) {
            $clients = [];
        }

        // CRM: Сделки
        try {
            $dealsStmt = $pdo->prepare("
                SELECT d.id, d.title, d.amount, d.currency, c.name as client_name
                FROM crm_deals d
                JOIN crm_clients c ON c.id = d.client_id
                WHERE d.title LIKE ? OR c.name LIKE ?
                LIMIT 5
            ");
            $dealsStmt->execute([$searchTerm, $searchTerm]);
            $deals = $dealsStmt->fetchAll();
        } catch (Throwable $e) {
            $deals = [];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'tasks' => $tasks,
                'projects' => $projects,
                'users' => $users,
                'clients' => $clients,
                'deals' => $deals
            ]
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
