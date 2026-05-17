<?php
/**
 * api/knowledge.php - База знаний (статьи, документы)
 * 
 * Эндпоинты:
 * - GET /api/knowledge - список статей
 * - GET /api/knowledge/:id - статья по ID
 * - POST /api/knowledge - создание статьи
 * - PUT /api/knowledge/:id - обновление статьи
 * - DELETE /api/knowledge/:id - удаление статьи
 */

/**
 * Обработка запросов к /api/knowledge/*
 */
function handleKnowledge(string $method, ?string $action, mixed $id): void {
    $pdo = getPDO();
    $currentUser = getCurrentUser();
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    
    // GET /api/knowledge - список статей
    if ($method === 'GET' && $action === null) {
        $deptFilter = $_GET['department_id'] ?? null;
        $typeFilter = $_GET['type'] ?? null;
        
        $sql = "
            SELECT k.*, 
                   d.name as department_name,
                   u.full_name as author_name
            FROM knowledge_base k
            LEFT JOIN departments d ON k.department_id = d.id
            LEFT JOIN users u ON k.created_by = u.id
        ";
        
        $params = [];
        $where = [];
        if ($deptFilter) {
            $where[] = "k.department_id = ?";
            $params[] = (int)$deptFilter;
        }
        if ($typeFilter) {
            $where[] = "k.type = ?";
            $params[] = $typeFilter;
        }
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $sql .= " ORDER BY k.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $articles = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $articles]);
        exit;
    }
    
    // GET /api/knowledge/:id - статья по ID
    if ($method === 'GET' && $action !== null && is_numeric($action)) {
        $articleId = (int)$action;
        
        $stmt = $pdo->prepare("
            SELECT k.*, 
                   d.name as department_name,
                   u.full_name as author_name
            FROM knowledge_base k
            LEFT JOIN departments d ON k.department_id = d.id
            LEFT JOIN users u ON k.created_by = u.id
            WHERE k.id = ?
        ");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();
        
        if (!$article) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Статья не найдена']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $article]);
        exit;
    }
    
    // POST /api/knowledge - создание статьи
    if ($method === 'POST' && $action === null) {
        $data = json_decode(file_get_contents('php://input'), true);

        $type = $data['type'] ?? 'article';
        $allowedTypes = ['article', 'video', 'slides', 'faq'];
        if (!in_array($type, $allowedTypes, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный тип материала']);
            exit;
        }

        if ($type === 'faq') {
            if (empty($data['question'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите вопрос']);
                exit;
            }
        } else {
            if (empty($data['title'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Укажите заголовок']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO knowledge_base (type, title, content, url, question, answer, department_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $type,
            $data['title'] ?? '',
            $data['content'] ?? '',
            $data['url'] ?? null,
            $data['question'] ?? null,
            $data['answer'] ?? null,
            $data['department_id'] ?? null,
            $currentUser['id']
        ]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode(['success' => true, 'data' => ['id' => $newId, 'message' => 'Статья создана']]);
        exit;
    }
    
    // PUT /api/knowledge/:id - обновление статьи
    if ($method === 'PUT' && $action !== null && is_numeric($action)) {
        $articleId = (int)$action;
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Проверка прав (автор или администратор)
        $stmt = $pdo->prepare("SELECT created_by FROM knowledge_base WHERE id = ?");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();
        
        if (!$article) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Статья не найдена']);
            exit;
        }
        
        if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view') && $article['created_by'] !== $currentUser['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
            exit;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = $data['title'];
        }

        if (isset($data['type'])) {
            $allowedTypes = ['article', 'video', 'slides', 'faq'];
            if (!in_array($data['type'], $allowedTypes, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Некорректный тип материала']);
                exit;
            }
            $updates[] = "type = ?";
            $params[] = $data['type'];
        }
        
        if (isset($data['content'])) {
            $updates[] = "content = ?";
            $params[] = $data['content'];
        }

        if (array_key_exists('url', $data)) {
            $updates[] = "url = ?";
            $params[] = $data['url'];
        }

        if (array_key_exists('question', $data)) {
            $updates[] = "question = ?";
            $params[] = $data['question'];
        }

        if (array_key_exists('answer', $data)) {
            $updates[] = "answer = ?";
            $params[] = $data['answer'];
        }
        
        if (isset($data['department_id'])) {
            $updates[] = "department_id = ?";
            $params[] = $data['department_id'];
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нет данных для обновления']);
            exit;
        }
        
        $params[] = $articleId;
        $sql = "UPDATE knowledge_base SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Статья обновлена']);
        exit;
    }
    
    // DELETE /api/knowledge/:id - удаление статьи
    if ($method === 'DELETE' && $action !== null && is_numeric($action)) {
        if (!hasPermission($currentUser, 'admin.full') && !hasPermission($currentUser, 'leader.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления статьи']);
            exit;
        }
        
        $articleId = (int)$action;
        
        $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ?");
        $stmt->execute([$articleId]);
        
        echo json_encode(['success' => true, 'message' => 'Статья удалена']);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
