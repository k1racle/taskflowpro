<?php
/**
 * api/index.php - Центральный роутер API
 *
 * Обрабатывает все API запросы и перенаправляет их
 * к соответствующим контроллерам
 */

// Загружаем конфигурацию
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/license.php';

// Centralized error logging to file: api/logs/api-error-YYYY-MM-DD.log
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}
$logFile = $logsDir . '/api-error-' . date('Y-m-d') . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    error_log("[PHP] {$message} in {$file}:{$line}");
    return false;
});

set_exception_handler(function (Throwable $e) {
    error_log('[EXCEPTION] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log($e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
    exit;
});

// Базовые API/security headers
appSecurityApplyApiHeaders();

// Обработка preflight запросов
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Функция для отправки JSON ответа
function jsonResponse($data, $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Функция для отправки ошибки
function jsonError($message, $statusCode = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

// Получаем endpoint из URL разными способами
function getEndpoint(): array {
    // Способ 1: PATH_INFO (если доступен) - для прямых путей
    if (!empty($_SERVER['PATH_INFO'])) {
        $path = trim($_SERVER['PATH_INFO'], '/');
    }
    // Способ 2: QUERY STRING с параметром endpoint
    elseif (!empty($_GET['endpoint'])) {
        // Some clients pass endpoint URL-encoded (e.g. "license%2Fstatus").
        // Decode to keep routing stable.
        $path = rawurldecode(trim((string)$_GET['endpoint'], '/'));
    }
    // Способ 3: Из REQUEST_URI
    else {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        // Удаляем путь до api/index.php
        $path = preg_replace('#.*/api/index\.php#', '', $requestUri);
        $path = trim($path, '/');
    }

    // Support endpoints like "conferences/1/start" or "chat/rooms/1/messages"
    // We keep all segments, but also map action/id/subaction in a predictable way.
    $parts = explode('/', $path);
    $parts = array_filter($parts, function($v) { return $v !== ''; });
    $parts = array_values($parts);

    // Логирование для отладки (можно включить при необходимости)
    error_log('API endpoint: ' . $path . ' => resource=' . ($parts[0] ?? '') . ', action=' . ($parts[1] ?? '') . ', id=' . ($parts[2] ?? '') . ', subaction=' . ($parts[3] ?? ''));

    $resource = $parts[0] ?? '';
    $action = $parts[1] ?? null;
    $id = $parts[2] ?? null;
    $subaction = $parts[3] ?? null;

    // Специальная обработка для files/:id/download и files/:id/preview - ПРОВЕРЯЕМ ПЕРЕД ОСТАЛЬНЫМ!
    if ($resource === 'files' && is_numeric($action)) {
        // files/1/download => id=1, subaction=download
        if ($id === 'download') {
            $resource = 'files-download';
            $id = $action;
            $action = null;
            $subaction = null;
            error_log('Files download detected: id=' . $id);
        }
        // files/1/preview => id=1, subaction=preview
        elseif ($id === 'preview') {
            $resource = 'files';
            $id = $action;
            $action = null;
            $subaction = 'preview';
            error_log('Files preview detected: id=' . $id);
        }
    }

    // If action is numeric, usually treat it as id and shift.
    // Example: /conferences/1/start => id=1, subaction=start
    // But for classic CRUD resources we keep action=id (users/2, projects/10, tasks/5).
    $keepCrudActionNumeric = in_array($resource, ['users', 'departments', 'projects', 'tasks', 'files', 'knowledge', 'mail', 'notifications', 'profile', 'roles', 'permissions'], true);
    
    // Special handling for CRM pipelines: /crm/pipelines/1/stages
    if ($resource === 'crm' && $action === 'pipelines' && is_numeric($id)) {
        // Keep as action=pipelines, id=1, subaction=stages
    } elseif ($action !== null && is_numeric($action) && !$keepCrudActionNumeric) {
        $subaction = $id;
        $id = $action;
        $action = null;
    }

    // Special handling for conferences: /conferences/1/join-requests
    if ($resource === 'conferences' && is_numeric($action) && $id === 'join-requests') {
        $id = $action;
        $action = null;
        $subaction = 'join-requests';
    }

    return [
        'resource' => $resource,
        'action' => $action,
        'id' => $id,
        'subaction' => $subaction,
        'full_path' => $path
    ];
}

// Получаем метод запроса
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Получаем endpoint из query-параметра или PATH_INFO
$endpoint = getEndpoint();
$resource = $endpoint['resource'];
$action = $endpoint['action'];
$id = $endpoint['id'];
$subaction = $endpoint['subaction'];

// Поддержка токена из query-параметра
if (!empty($_GET['token']) && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_GET['token'];
}

// Operational health/readiness endpoints must stay reachable even when
// database or license checks are currently failing.
if (in_array($resource, ['health', 'ready'], true)) {
    handleHealthEndpoint($resource, $method);
}

// Проверка подключения к БД
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    jsonError('Ошибка подключения к базе данных. Проверьте config.php', 500);
}

// License gate (domain-only). Allow auth + license status endpoints always.
if (!in_array($resource, ['auth', 'license', 'health', 'ready', 'referrals'], true)) {
    requireValidLicense();
}

// Логирование для отладки (можно включить)
// file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - $method /$resource/$action/$id\n", FILE_APPEND);

// Маршрутизация
try {
    switch ($resource) {
        case 'auth':
            require __DIR__ . '/auth.php';
            handleAuth($method, $action, $id);
            break;

        case 'license':
            handleLicense($method, $action, $id);
            break;

        case 'health':
        case 'ready':
            handleHealthEndpoint($resource, $method);
            break;

        case 'users':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/users.php';
            handleUsers($method, $action, $id);
            break;

        case 'departments':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/departments.php';
            handleDepartments($method, $action, $id);
            break;

        case 'projects':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/projects.php';
            handleProjects($method, $action, $id);
            break;

        case 'tasks':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/tasks.php';

            // Операции с подэтапом справочника: /task-substages/:id
            if ($action === 'task-substages' && is_numeric($id)) {
                if ($method === 'PUT') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    updateTaskSubstageDict((int)$id, $data);
                } elseif ($method === 'DELETE') {
                    deleteTaskSubstageDict((int)$id);
                }
                exit;
            }

            // Обработка справочника подэтапов: /task-substages
            if ($action === 'task-substages') {
                if ($method === 'GET') {
                    getTaskSubstagesDict();
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    createTaskSubstageDict($data);
                }
                exit;
            }

            handleTasks($method, $action, $id);
            break;

        case 'tasks-direct.php':
        case 'tasks-direct':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/tasks-direct.php';
            break;

        case 'comments-direct.php':
        case 'comments-direct':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/comments-direct.php';
            break;

        // Backward-compat: older frontend builds call task substages dictionary as a top-level resource
        // (endpoint=task-substages). Internally it lives under tasks handler.
        case 'task-substages':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/tasks.php';

            if ($method === 'GET') {
                getTaskSubstagesDict();
            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                createTaskSubstageDict($data);
            } elseif ($method === 'PUT' && is_numeric($action)) {
                $data = json_decode(file_get_contents('php://input'), true);
                updateTaskSubstageDict((int)$action, $data);
            } elseif ($method === 'DELETE' && is_numeric($action)) {
                deleteTaskSubstageDict((int)$action);
            } else {
                jsonError('Endpoint не найден', 404);
            }
            break;

        case 'settings':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/settings.php';
            handleSettings($method, $action, $id);
            break;

        case 'audit':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/audit.php';
            handleAudit($method, $action, $id);
            break;

        case 'telegram':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/telegram.php';
            handleTelegram($method, $action, $id);
            break;

        case 'knowledge':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/knowledge.php';
            handleKnowledge($method, $action, $id);
            break;

        case 'files':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/files.php';
            handleFiles($method, $action, $id, $subaction);
            break;

        case 'files-download':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/files.php';
            // Вызываем handleFiles с subaction='download'
            handleFiles('GET', null, $id, 'download');
            break;

        case 'notifications':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/notifications.php';
            handleNotifications($method, $action, $id);
            break;

        case 'profile':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/profile.php';
            handleProfile($method, $action, $id);
            break;

        case 'chat':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/chat.php';
            handleChat($method, $action, $id, $subaction);
            break;

        case 'conferences':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/conferences.php';
            handleConferences($method, $action, $id, $subaction);
            break;

        case 'webrtc':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/webrtc.php';
            handleWebRTC($method, $action, $id, $subaction);
            break;

        case 'mail':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/mail.php';
            handleMail($method, $action, $id, $subaction);
            break;

        case 'comments':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/comments.php';
            handleComments($method, $action, $id);
            break;

        case 'user-departments':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/user-departments.php';
            handleUserDepartments($method, $action, $id);
            break;

        case 'project-comments':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/project-comments.php';
            handleProjectComments($method, $action, $id);
            break;

        case 'project-history':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/project-history.php';
            handleProjectHistory($method, $action, $id);
            break;

        case 'roles':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/roles.php';
            handleRoles($method, $action, $id);
            break;

        case 'permissions':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/roles.php';
            handlePermissions($method, $action, $id);
            break;

        case 'user-permissions':
            require __DIR__ . '/auth.php';
            require __DIR__ . '/roles.php';
            handleUserPermissions($method, $action, $id);
            break;

        case 'stages':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/tasks.php';
            handleStages($method, $action, $id);
            break;

        case 'search':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/search.php';
            handleSearch($method, $action, $id);
            break;

        case 'user-settings':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/user-settings.php';
            handleUserSettings($method, $action, $id);
            break;

        case 'shifts':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/shifts.php';
            handleShifts($method, $action, $id, $subaction);
            break;

        case 'work-schedules':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/work-schedules.php';
            handleWorkSchedules($method, $action, $id, $subaction);
            break;

        case 'analytics':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/analytics.php';
            handleAnalytics($method, $action, $id, $subaction);
            break;

        case 'helpdesk':
            // Для публичных endpoint'ов (categories, statuses, stats) не требуем авторизацию
            // Также разрешаем доступ к комментариям и истории без авторизации (для просмотра)
            
            // Всегда подключаем auth.php для getCurrentUser(), но не требуем авторизацию для публичных
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/config.php';
            
            // Передаем флаг, что вызов из api/index.php
            $index_resource = $resource;
            
            // Подключаем helpdesk.php - он сам разберется с вызовом
            require_once __DIR__ . '/helpdesk.php';
            break;

        case 'crm':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/crm.php';
            
            // Операции с подэтапом справочника: /crm-deal-substages/:id
            if ($action === 'crm-deal-substages' && is_numeric($id)) {
                if ($method === 'PUT') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    updateCrmDealSubstageDict((int)$id, $data);
                } elseif ($method === 'DELETE') {
                    deleteCrmDealSubstageDict((int)$id);
                }
                exit;
            }
            
            // Обработка справочника подэтапов сделок: /crm-deal-substages
            if ($action === 'crm-deal-substages') {
                if ($method === 'GET') {
                    getCrmDealSubstagesDict();
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    createCrmDealSubstageDict($data);
                }
                exit;
            }
            
            handleCrm($method, $action, $id, $subaction);
            break;

        case 'referrals':
            require_once __DIR__ . '/referrals.php';
            handleReferrals($method, $action, $id, $subaction);
            break;

        case 'documents':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/documents.php';
            handleDocuments($method, $action, $id, $subaction);
            break;

        // Backward-compat: some frontend builds call this as a top-level resource
        // (endpoint=crm-deal-substages). Route it to CRM dictionary handlers.
        case 'crm-deal-substages':
            require_once __DIR__ . '/auth.php';
            require_once __DIR__ . '/crm.php';

            if ($method === 'GET') {
                getCrmDealSubstagesDict();
            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                createCrmDealSubstageDict($data);
            } elseif ($method === 'PUT' && is_numeric($action)) {
                $data = json_decode(file_get_contents('php://input'), true);
                updateCrmDealSubstageDict((int)$action, $data);
            } elseif ($method === 'DELETE' && is_numeric($action)) {
                deleteCrmDealSubstageDict((int)$action);
            } else {
                jsonError('Endpoint не найден', 404);
            }
            break;

        case '':
            jsonResponse(['success' => true, 'message' => 'TaskFlow Pro API v1.0', 'endpoints' => [
                'health', 'ready',
                'auth/login', 'auth/register', 'auth/whoami',
                'users', 'departments', 'projects', 'tasks', 'settings', 'telegram', 'stages',
                'knowledge', 'files', 'mail', 'notifications', 'profile', 'chat', 'roles', 'permissions'
            ]]);
            break;

        default:
            jsonError('Endpoint не найден: ' . $resource, 404);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()]);
}
