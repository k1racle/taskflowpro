/**
 * api.js - API функции для TaskFlow Pro
 * Все запросы к серверу через этот модуль
 * 
 * Интеграция с AuthModule для:
 * - Автоматического добавления токена в заголовки
 * - Обработки 401 ошибок с refresh token
 * - Повторной отправки запроса после обновления токена
 */

/**
 * Базовый URL API
 */
const API_BASE_URL = 'api/index.php';

function normalizeLegacyApiEndpoint(endpoint) {
    const endpointStr = String(endpoint || '');
    const queryIdx = endpointStr.indexOf('?');
    const endpointPath = queryIdx === -1 ? endpointStr : endpointStr.slice(0, queryIdx);
    const endpointQuery = queryIdx === -1 ? '' : endpointStr.slice(queryIdx + 1);

    const legacyMap = {
        'auth.php': 'auth',
        'license.php': 'license'
    };

    const normalizedPath = legacyMap[endpointPath] || endpointPath;
    return endpointQuery ? `${normalizedPath}?${endpointQuery}` : normalizedPath;
}

function buildApiUrl(endpoint) {
    const endpointStr = normalizeLegacyApiEndpoint(endpoint);
    const queryIdx = endpointStr.indexOf('?');
    const endpointPath = queryIdx === -1 ? endpointStr : endpointStr.slice(0, queryIdx);
    let endpointQuery = queryIdx === -1 ? '' : endpointStr.slice(queryIdx + 1);

    endpointQuery = endpointQuery.replace(/^[?&]+/, '');

    const isDirectFile = endpointPath.endsWith('.php');
    if (isDirectFile) {
        return `${API_BASE_URL.replace('index.php', '')}${endpointPath}?_t=${Date.now()}`;
    }

    const encodedEndpoint = encodeURIComponent(endpointPath);
    const querySuffix = endpointQuery ? `&${endpointQuery}` : '';
    return `${API_BASE_URL}?endpoint=${encodedEndpoint}${querySuffix}&_t=${Date.now()}`;
}

// In-flight request dedupe (single-flight) to avoid request storms from polling/UI.
// Keyed by method + url + body hash (best-effort).
const __apiInFlight = new Map();

// Refresh token lock to prevent concurrent refreshes
let __isRefreshingToken = false;
let __refreshSubscribers = [];

function __truncateApiText(text, maxLength = 300) {
    const normalized = String(text || '').trim();
    if (!normalized) return '';
    return normalized.length > maxLength
        ? `${normalized.slice(0, maxLength)}...`
        : normalized;
}

async function parseApiResponse(response) {
    const rawText = await response.text();
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    const isJson = contentType.includes('application/json');

    let data = {};
    if (rawText) {
        if (isJson) {
            try {
                data = JSON.parse(rawText);
            } catch (_) {
                data = {
                    success: false,
                    error: 'Сервер вернул поврежденный JSON',
                    raw: __truncateApiText(rawText)
                };
            }
        } else {
            data = {
                success: false,
                error: __truncateApiText(rawText) || `Сервер вернул ответ в формате ${contentType || 'unknown'}`,
                raw: __truncateApiText(rawText)
            };
        }
    }

    if (!response.ok) {
        const error = new Error(data.error || `Ошибка запроса (${response.status})`);
        error.status = response.status;
        error.statusText = response.statusText;
        error.data = data;
        error.raw = rawText;
        error.contentType = contentType;
        throw error;
    }

    return data;
}

async function fetchJsonOrThrow(url, options = {}, fallbackMessage = 'Ошибка запроса') {
    const response = await fetch(url, options);
    try {
        return await parseApiResponse(response);
    } catch (error) {
        error.userMessage = getApiErrorMessage(error, fallbackMessage);
        throw error;
    }
}

function getApiErrorMessage(error, fallbackMessage = 'Ошибка запроса') {
    if (!error) return fallbackMessage;

    const status = Number(error.status) || 0;
    const serverMessage = error.data?.error || error.message;

    if (status === 401) return serverMessage || 'Требуется авторизация';
    if (status === 403) return serverMessage || 'Доступ запрещен';
    if (status >= 500) return serverMessage || 'Внутренняя ошибка сервера';

    return serverMessage || fallbackMessage;
}

function __stableStringify(value) {
    try {
        if (value == null) return '';
        if (typeof value === 'string') return value;
        if (typeof value !== 'object') return String(value);
        const seen = new WeakSet();
        const normalize = (v) => {
            if (v === null || typeof v !== 'object') return v;
            if (seen.has(v)) return '[Circular]';
            seen.add(v);
            if (Array.isArray(v)) return v.map(normalize);
            const out = {};
            for (const k of Object.keys(v).sort()) out[k] = normalize(v[k]);
            return out;
        };
        return JSON.stringify(normalize(value));
    } catch (_) {
        return '';
    }
}

/**
 * Получить токен из AuthModule (приоритет) или localStorage (fallback)
 */
function getToken() {
    // Пробуем AuthModule если доступен
    if (typeof window !== 'undefined' && window.AuthModule) {
        const token = window.AuthModule.getToken();
        if (token) return token;
    }

    // Fallback: localStorage для обратной совместимости
    try {
        return localStorage.getItem('token');
    } catch (e) {
        return null;
    }
}

/**
 * Сохранить токен в localStorage (для обратной совместимости)
 */
function saveToken(tokenStr) {
    try {
        if (tokenStr) {
            localStorage.setItem('token', tokenStr);
        } else {
            localStorage.removeItem('token');
        }
    } catch (e) {
        console.warn('Failed to save token:', e);
    }
}

/**
 * Подписаться на обновление токена
 */
function subscribeToTokenRefresh(callback) {
    __refreshSubscribers.push(callback);
}

/**
 * Обработать обновление токена
 */
function onTokenRefreshed(token) {
    __refreshSubscribers.forEach(cb => cb(token));
    __refreshSubscribers = [];
}

/**
 * Основной API метод
 * @param {string} endpoint - Endpoint API (или прямой файл типа 'comments-direct.php')
 * @param {Object} options - Опции fetch
 * @param {boolean} _retry - Внутренний флаг повторной попытки (не передавать)
 * @returns {Promise<Object>} - Ответ сервера
 */
async function api(endpoint, options = {}, _retry = false) {
    const token = getToken();

    const endpointStr = normalizeLegacyApiEndpoint(endpoint);
    const url = buildApiUrl(endpointStr);

    const headers = {
        ...options.headers,
    };

    // Если body = FormData — Content-Type выставит браузер.
    const isFormData = typeof FormData !== 'undefined' && (options.body instanceof FormData);
    if (!isFormData) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/json';
    }

    const config = {
        ...options,
        headers,
        // Needed when API auth relies on PHP session cookies.
        // Safe even when using Bearer token auth.
        credentials: options.credentials || 'include',
    };

    // Добавляем токен в заголовок Authorization если есть
    if (token) {
        config.headers['Authorization'] = `Bearer ${token}`;
        // Для nginx/openresty добавляем также X-Authorization
        config.headers['X-Authorization'] = `Bearer ${token}`;
    }

    const method = String(config.method || 'GET').toUpperCase();
    const dedupe = options.dedupe !== false;
    const bodyKey = (config.body && !(config.body instanceof FormData)) ? __stableStringify(config.body) : '';
    const inflightKey = `${method} ${url} ${bodyKey}`;

    if (dedupe && __apiInFlight.has(inflightKey)) {
        return __apiInFlight.get(inflightKey);
    }

    const promise = (async () => {
        const response = await fetch(url, config);

        try {
            return await parseApiResponse(response);
        } catch (error) {
            if (response.status === 401) {
                if (_retry) {
                    error.status = 401;
                    error.endpoint = endpoint;
                    throw error;
                }

                try {
                    await __refreshToken();
                    return api(endpoint, options, true);
                } catch (refreshError) {
                    console.error('Token refresh failed, redirecting to login:', refreshError);
                    saveToken(null);

                    if (typeof window !== 'undefined') {
                        sessionStorage.setItem('redirect_after_login', window.location.href);
                        window.location.href = 'index.html';
                    }

                    const refreshFailError = new Error('Сессия истекла. Выполните вход заново.');
                    refreshFailError.status = 401;
                    refreshFailError.endpoint = endpoint;
                    throw refreshFailError;
                }
            }

            error.endpoint = endpoint;
            throw error;
        }
    })();

    if (dedupe) __apiInFlight.set(inflightKey, promise);

    try {
        return await promise;
    } catch (error) {
        const noisyPollingEndpoint = ['chat/calls', 'chat/presence', 'chat/rooms', 'notifications'].includes(String(endpoint || ''));
        if (!(noisyPollingEndpoint && error && Number(error.status) === 403)) {
            console.error(`API Error (${endpoint}):`, error);
        }
        throw error;
    } finally {
        if (dedupe) __apiInFlight.delete(inflightKey);
    }
}

/**
 * Внутренняя функция обновления токена
 */
async function __refreshToken() {
    if (__isRefreshingToken) {
        // Уже идёт обновление - ждём
        return new Promise((resolve, reject) => {
            const checkInterval = setInterval(() => {
                if (!__isRefreshingToken) {
                    clearInterval(checkInterval);
                    const newToken = getToken();
                    if (newToken) {
                        resolve(newToken);
                    } else {
                        reject(new Error('Token refresh failed'));
                    }
                }
            }, 100);
        });
    }

    __isRefreshingToken = true;

    try {
        const response = await fetch('api/index.php?endpoint=auth/refresh', {
            method: 'POST',
            credentials: 'include'
        });

        const data = await parseApiResponse(response);

        if (data.success) {
            // Получаем новый токен
            const newToken = getToken();
            if (newToken) {
                saveToken(newToken);
                onTokenRefreshed(newToken);
                console.log('Token refreshed successfully');
            }
            return newToken;
        } else {
            throw new Error('Token refresh failed');
        }
    } finally {
        __isRefreshingToken = false;
    }
}

/**
 * GET запрос
 */
async function apiGet(endpoint) {
    return api(endpoint, { method: 'GET' });
}

/**
 * POST запрос
 */
async function apiPost(endpoint, data) {
    return api(endpoint, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

/**
 * POST запрос с FormData (multipart/form-data)
 */
async function apiPostForm(endpoint, formData) {
    // Для FormData нельзя задавать Content-Type вручную — браузер сам поставит boundary.
    return api(endpoint, {
        method: 'POST',
        headers: {},
        body: formData,
    });
}

/**
 * PUT запрос
 */
async function apiPut(endpoint, data) {
    const hasBody = typeof data !== 'undefined';
    return api(endpoint, {
        method: 'PUT',
        ...(hasBody ? { body: JSON.stringify(data) } : {}),
    });
}

/**
 * PATCH запрос
 */
async function apiPatch(endpoint, data) {
    const hasBody = typeof data !== 'undefined';
    return api(endpoint, {
        method: 'PATCH',
        ...(hasBody ? { body: JSON.stringify(data) } : {}),
    });
}

/**
 * Универсальный fetch-совместимый API вызов
 */
async function apiFetch(endpoint, options = {}) {
    return api(endpoint, options);
}

/**
 * DELETE запрос
 */
async function apiDelete(endpoint) {
    return api(endpoint, { method: 'DELETE' });
}

/**
 * Загрузить файл
 */
async function apiUpload(endpoint, file, fieldName = 'file') {
    const token = getToken();

    const encodedEndpoint = encodeURIComponent(endpoint);
    const encodedToken = token ? encodeURIComponent(token) : '';
    const url = `${API_BASE_URL}?endpoint=${encodedEndpoint}&_t=${Date.now()}${token ? `&token=${encodedToken}` : ''}`;

    const formData = new FormData();
    // Проверяем что файл существует
    if (!file) {
        throw new Error('Файл не выбран');
    }
    formData.append(fieldName, file, file.name);

    // Не устанавливаем Content-Type явно - браузер сам установит multipart/form-data с boundary
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                // Не устанавливаем 'Content-Type': 'multipart/form-data' явно!
            },
            body: formData,
        });

        const data = await parseApiResponse(response);

        return data;
    } catch (error) {
        console.error(`Upload Error (${endpoint}):`, error);
        throw error;
    }
}

/* ============================================
   Profile Mail Settings API
   ============================================ */

async function apiGetProfileMailSettings(userId) {
    return apiGet('profile/mail-settings');
}

async function apiPutProfileMailSettings(userId, payload) {
    return apiPut('profile/mail-settings', payload);
}

/* ============================================
   Auth API
   ============================================ */

async function apiLogin(login, password) {
    return api('auth/login', {
        method: 'POST',
        body: JSON.stringify({ login, password })
    });
}

async function apiLogout() {
    return api('auth/logout', { method: 'POST' });
}

async function apiWhoami() {
    return api('auth/whoami', { method: 'GET' });
}

async function apiRegister(userData) {
    return apiPost('auth/register', userData);
}

/* ============================================
   Tasks API
   ============================================ */

async function apiGetTasks(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`tasks${query ? '?' + query : ''}`);
}

async function apiGetTask(id) {
    return apiGet(`tasks/${id}`);
}

async function apiCreateTask(taskData) {
    return apiPost('tasks', taskData);
}

async function apiUpdateTask(id, taskData) {
    return apiPut(`tasks-direct.php?id=${id}`, taskData);
}

async function apiDeleteTask(id) {
    return apiDelete(`tasks/${id}`);
}

async function apiMoveTask(id, status) {
    return apiPut(`tasks/${id}`, { status });
}

async function apiGetTaskHistory(id) {
    return apiGet(`tasks/${id}/history`);
}

/* ============================================
   CRM API
   ============================================ */

async function apiCrmDashboard() {
    return apiGet('crm/dashboard');
}

async function apiCrmClients(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`crm/clients${query ? '?' + query : ''}`);
}

async function apiCrmClient(id) {
    return apiGet(`crm/clients/${id}`);
}

async function apiCrmClientSales(id) {
    return apiGet(`crm/clients/${id}/sales`);
}

async function apiCrmClientReferrals(id) {
    return apiGet(`crm/clients/${id}/referrals`);
}

async function apiCrmEnsureReferralCode(id, forceRegenerate = false) {
    return apiPost(`crm/clients/${id}/referral-code`, { force_regenerate: !!forceRegenerate });
}

async function apiCrmSales(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`crm/sales${query ? '?' + query : ''}`);
}

async function apiCrmStore(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`crm/store${query ? '?' + query : ''}`);
}

async function apiCrmStoreImport(payload = {}) {
    return apiPost('crm/store/import', payload);
}

async function apiCrmAdminTools(payload) {
    return apiPost('crm/admin-tools', payload);
}

async function apiCrmCreateClient(payload) {
    return apiPost('crm/clients', payload);
}

async function apiCrmUpdateClient(id, payload) {
    return apiPut(`crm/clients/${id}`, payload);
}

async function apiCrmDeleteClient(id) {
    return apiDelete(`crm/clients/${id}`);
}

async function apiCrmPipelines() {
    return apiGet('crm/pipelines');
}

async function apiCrmPipelineStages(pipelineId) {
    return apiGet(`crm/pipelines/${pipelineId}/stages`);
}

async function apiDeletePipeline(pipelineId) {
    return apiDelete(`crm/pipelines/${pipelineId}`);
}

async function apiCrmDeals(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`crm/deals${query ? '?' + query : ''}`);
}

async function apiGetTaskComments(id) {
    return apiGet(`comments?task_id=${id}`);
}

async function apiAddTaskComment(id, message, parentId = null) {
    return apiPost('comments', {
        task_id: parseInt(id),
        message,
        parent_id: parentId
    });
}

async function apiDeleteTaskComment(commentId) {
    return apiDelete(`comments/${commentId}`);
}

/* ============================================
   Projects API
   ============================================ */

async function apiGetProjects(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`projects${query ? '?' + query : ''}`);
}

async function apiGetProjectHistory(projectId) {
    return apiGet(`projects/${projectId}/history`);
}

async function apiGetProject(id) {
    return apiGet(`projects/${id}`);
}

async function apiCreateProject(projectData) {
    return apiPost('projects', projectData);
}

async function apiUpdateProject(id, projectData) {
    return apiPut(`projects/${id}`, projectData);
}

async function apiDeleteProject(id) {
    return apiDelete(`projects/${id}`);
}

async function apiGetProjectTasks(id) {
    return apiGet(`projects/${id}/tasks`);
}

async function apiGetProjectFiles(id) {
    return apiGet(`projects/${id}/files`);
}

async function apiGetProjectComments(projectId) {
    return apiGet(`project-comments?project_id=${encodeURIComponent(projectId)}`);
}

async function apiAddProjectComment(projectId, message, parentId = null) {
    return apiPost('project-comments', { project_id: projectId, message, parent_id: parentId });
}

async function apiDeleteProjectComment(commentId) {
    return apiDelete(`project-comments/${commentId}`);
}

/* ============================================
   Departments API
   ============================================ */

async function apiGetDepartments() {
    return apiGet('departments');
}

async function apiGetDepartment(id) {
    return apiGet(`departments/${id}`);
}

async function apiCreateDepartment(deptData) {
    return apiPost('departments', deptData);
}

async function apiUpdateDepartment(id, deptData) {
    return apiPut(`departments/${id}`, deptData);
}

async function apiDeleteDepartment(id) {
    return apiDelete(`departments/${id}`);
}

async function apiGetDepartmentEmployees(id) {
    return apiGet(`departments/${id}/employees`);
}

async function apiGetDepartmentTasks(id) {
    return apiGet(`departments/${id}/tasks`);
}

/* ============================================
   Users API
   ============================================ */

async function apiGetUsers(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`users${query ? '?' + query : ''}`);
}

async function apiGetUserRoles() {
    return apiGet('users/roles');
}

async function apiGetUser(id) {
    return apiGet(`users/${id}`);
}

async function apiCreateUser(userData) {
    return apiPost('users', userData);
}

async function apiUpdateUser(id, userData) {
    return apiPut(`users/${id}`, userData);
}

async function apiDeleteUser(id) {
    return apiDelete(`users/${id}`);
}

async function apiChangeUserPassword(id, password) {
    return apiPatch(`users/${id}/password`, { password });
}

async function apiGetUserProfile(id) {
    return apiGet(`profile/${id}`);
}

async function apiUpdateUserProfile(id, userData) {
    // Backend supports updating only current user's profile via PUT /api/profile
    return apiPut('profile', userData);
}

async function apiUploadAvatar(id, file) {
    return apiUpload('profile/avatar', file, 'avatar');
}

/* ============================================
   Files API
   ============================================ */

async function apiGetFiles(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`files${query ? '?' + query : ''}`);
}

async function apiGetFileTree() {
    return apiGet('files/tree');
}

async function apiGetFileFolders(parentId = null) {
    const q = parentId === null ? '?all=1' : `?parent_id=${encodeURIComponent(parentId)}`;
    return apiGet(`files/folders${q}`);
}

async function apiCreateFileFolder(name, parentId = null) {
    return apiPost('files/folders', { name, parent_id: parentId });
}

async function apiRenameFileFolder(folderId, name) {
    return apiPut(`files/folders/${folderId}`, { name });
}

async function apiDeleteFileFolder(folderId) {
    return apiDelete(`files/folders/${folderId}`);
}

async function apiUploadFile(file, folderId = null) {
    const formData = new FormData();
    formData.append('file', file);
    if (folderId) formData.append('folder_id', folderId);
    return apiUpload('files', file, 'file');
}

async function apiDeleteFile(id) {
    return apiDelete(`files/${id}`);
}

async function apiMoveFiles(fileIds, folderId = null) {
    return apiPatch('files/move', { file_ids: fileIds, folder_id: folderId });
}

async function apiGetFileDownload(id) {
    const token = getToken();
    const url = `${API_BASE_URL}?endpoint=files/${id}/download&_t=${Date.now()}`;
    return token ? `${url}&token=${encodeURIComponent(token)}` : url;
}

async function apiGetFilePreview(id) {
    const token = getToken();
    const url = `${API_BASE_URL}?endpoint=files/${id}/preview&_t=${Date.now()}`;
    return token ? `${url}&token=${encodeURIComponent(token)}` : url;
}

/* ============================================
   Settings API
   ============================================ */

async function apiGetSettings() {
    return apiGet('settings');
}

async function apiGetSettingsDiagnostics() {
    return apiGet('settings/diagnostics');
}

async function apiUpdateSettings(settingsData) {
    return apiPost('settings', settingsData);
}

async function apiUploadLogo(file) {
    return apiUpload('settings/logo', file, 'logo');
}

async function apiTestTelegram() {
    return apiPost('telegram/test');
}

async function apiUpdateTelegram(telegramData) {
    return apiPut('telegram', telegramData);
}

/* ============================================
   Work Schedules API
   ============================================ */

async function apiGetWorkSchedules(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`work-schedules${query ? '?' + query : ''}`);
}

async function apiDocumentsTemplates() {
    return apiGet('documents/templates');
}

async function apiDocumentsClients() {
    return apiGet('documents/clients');
}

async function apiDocumentsFields(clientId = '') {
    return apiGet(`documents/fields${clientId ? `?client_id=${encodeURIComponent(clientId)}` : ''}`);
}

async function apiDocumentsHistory(params = {}) {
    const query = new URLSearchParams();
    if (params.client_id) query.set('client_id', params.client_id);
    if (params.limit) query.set('limit', params.limit);
    return apiGet(`documents/history${query.toString() ? '?' + query.toString() : ''}`);
}

async function apiDocumentsGenerate(payload) {
    return apiPost('documents/generate', payload);
}

async function apiDocumentsGenerateBatch(payload) {
    return apiPost('documents/generate-batch', payload);
}

async function apiCreateWorkSchedule(data) {
    return apiPost('work-schedules', data);
}

async function apiUpdateWorkSchedule(id, data) {
    return apiPut(`work-schedules/${id}`, data);
}

async function apiDeleteWorkSchedule(id) {
    return apiDelete(`work-schedules/${id}`);
}

/* ============================================
   Notifications API
   ============================================ */

async function apiGetNotifications() {
    return apiGet('notifications');
}

async function apiMarkNotificationRead(id) {
    return apiPut(`notifications/${id}/read`);
}

async function apiMarkAllNotificationsRead() {
    return apiPut('notifications/read-all');
}

/* ============================================
   Knowledge Base API
   ============================================ */

async function apiGetKnowledge(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`knowledge${query ? '?' + query : ''}`);
}

async function apiGetKnowledgeArticle(id) {
    return apiGet(`knowledge/${id}`);
}

async function apiCreateKnowledge(articleData) {
    return apiPost('knowledge', articleData);
}

async function apiUpdateKnowledge(id, articleData) {
    return apiPut(`knowledge/${id}`, articleData);
}

async function apiDeleteKnowledge(id) {
    return apiDelete(`knowledge/${id}`);
}

/* ============================================
   Mail API
   ============================================ */

async function apiGetMail(params = {}) {
    const query = new URLSearchParams(params).toString();
    return apiGet(`mail${query ? '?' + query : ''}`);
}

async function apiSendEmail(emailData) {
    return apiPost('mail/send', emailData);
}

async function apiTestEmail(emailSettings) {
    return apiPost('mail/test', emailSettings);
}

/* ============================================
   Stages API
   ============================================ */

async function apiGetStages() {
    return apiGet('stages');
}

async function apiCreateStage(stageData) {
    return apiPost('stages', stageData);
}

async function apiUpdateStage(id, stageData) {
    return apiPut(`stages/${id}`, stageData);
}

async function apiDeleteStage(id) {
    return apiDelete(`stages/${id}`);
}

/* ============================================
   Roles & Permissions API
   ============================================ */

async function apiGetRoles() {
    // Safer than top-level /roles on hosts with aggressive WAF rules.
    return apiGet('users/roles');
}

async function apiGetManageRoles() {
    return apiGet('roles');
}

async function apiCreateRole(roleData) {
    return apiPost('roles', roleData);
}

async function apiUpdateRole(id, roleData) {
    return apiPut(`roles/${id}`, roleData);
}

async function apiDeleteRole(id) {
    return apiDelete(`roles/${id}`);
}

async function apiGetPermissions() {
    return apiGet('permissions');
}

async function apiGetUserPermissions(userId) {
    const token = getToken();
    const url = `api/index.php?endpoint=user-permissions&user_id=${userId}&_t=${Date.now()}${token ? `&token=${token}` : ''}`;

    return apiGetWithBearerUrl(url, 'Ошибка загрузки прав', 'API Error (user-permissions)', token);
}

/* ============================================
   Search API
   ============================================ */

async function apiGlobalSearch(query) {
    const token = getToken();
    const url = `api/index.php?endpoint=search&_t=${Date.now()}&q=${encodeURIComponent(query)}${token ? `&token=${token}` : ''}`;

    return apiGetWithBearerUrl(url, 'Ошибка поиска', 'Search Error', token);
}

async function apiGetWithBearerUrl(url, fallbackMessage, logLabel, token = getToken()) {
    try {
        return await fetchJsonOrThrow(url, {
            method: 'GET',
            headers: token ? { 'Authorization': `Bearer ${token}` } : {}
        }, fallbackMessage);
    } catch (error) {
        console.error(`${logLabel}:`, error);
        throw error;
    }
}

/* ============================================
   Export для использования в app()
   ============================================ */

if (typeof window !== 'undefined') {
    window.api = api;
    window.apiGet = apiGet;
    window.apiPost = apiPost;
    window.apiPut = apiPut;
    window.apiPatch = apiPatch;
    window.apiDelete = apiDelete;
    window.apiUpload = apiUpload;
    
    // Auth
    window.apiLogin = apiLogin;
    window.apiLogout = apiLogout;
    window.apiWhoami = apiWhoami;
    
    // Tasks
    window.apiGetTasks = apiGetTasks;
    window.apiGetTask = apiGetTask;
    window.apiCreateTask = apiCreateTask;
    window.apiUpdateTask = apiUpdateTask;
    window.apiDeleteTask = apiDeleteTask;
    window.apiMoveTask = apiMoveTask;
    window.apiGetTaskHistory = apiGetTaskHistory;
    window.apiGetTaskComments = apiGetTaskComments;
    window.apiAddTaskComment = apiAddTaskComment;
    window.apiDeleteTaskComment = apiDeleteTaskComment;
    
    // Projects
    window.apiGetProjects = apiGetProjects;
    window.apiGetProjectHistory = apiGetProjectHistory;
    window.apiGetProject = apiGetProject;
    window.apiCreateProject = apiCreateProject;
    window.apiUpdateProject = apiUpdateProject;
    window.apiDeleteProject = apiDeleteProject;
    window.apiGetProjectComments = apiGetProjectComments;
    window.apiAddProjectComment = apiAddProjectComment;
    window.apiDeleteProjectComment = apiDeleteProjectComment;
    
    // Departments
    window.apiGetDepartments = apiGetDepartments;
    window.apiGetDepartment = apiGetDepartment;
    window.apiCreateDepartment = apiCreateDepartment;
    window.apiUpdateDepartment = apiUpdateDepartment;
    window.apiDeleteDepartment = apiDeleteDepartment;
    
    // Users
    window.apiGetUsers = apiGetUsers;
    window.apiGetUserRoles = apiGetUserRoles;
    window.apiGetUser = apiGetUser;
    window.apiCreateUser = apiCreateUser;
    window.apiUpdateUser = apiUpdateUser;
    window.apiDeleteUser = apiDeleteUser;
    window.apiChangeUserPassword = apiChangeUserPassword;
    
    // Files
    window.apiGetFiles = apiGetFiles;
    window.apiUploadFile = apiUploadFile;
    window.apiDeleteFile = apiDeleteFile;
    window.apiGetFileFolders = apiGetFileFolders;
    window.apiCreateFileFolder = apiCreateFileFolder;
    window.apiRenameFileFolder = apiRenameFileFolder;
    window.apiDeleteFileFolder = apiDeleteFileFolder;
    
    // Settings
    window.apiGetSettings = apiGetSettings;
    window.apiGetSettingsDiagnostics = apiGetSettingsDiagnostics;
    window.apiUpdateSettings = apiUpdateSettings;

    // Knowledge Base
    window.apiGetKnowledge = apiGetKnowledge;
    window.apiGetKnowledgeArticle = apiGetKnowledgeArticle;
    window.apiCreateKnowledge = apiCreateKnowledge;
    window.apiUpdateKnowledge = apiUpdateKnowledge;
    window.apiDeleteKnowledge = apiDeleteKnowledge;

    // Notifications
    window.apiGetNotifications = apiGetNotifications;
    window.apiMarkNotificationRead = apiMarkNotificationRead;
    window.apiMarkAllNotificationsRead = apiMarkAllNotificationsRead;

    // Stages
    window.apiGetStages = apiGetStages;
    window.apiCreateStage = apiCreateStage;
    window.apiUpdateStage = apiUpdateStage;
    window.apiDeleteStage = apiDeleteStage;

    // Roles & Permissions
    window.apiGetRoles = apiGetRoles;
    window.apiGetManageRoles = apiGetManageRoles;
    window.apiCreateRole = apiCreateRole;
    window.apiUpdateRole = apiUpdateRole;
    window.apiDeleteRole = apiDeleteRole;
    window.apiGetPermissions = apiGetPermissions;
    window.apiGetUserPermissions = apiGetUserPermissions;

    // CRM
    window.apiCrmClients = apiCrmClients;
    window.apiCrmClient = apiCrmClient;
    window.apiCrmClientSales = apiCrmClientSales;
    window.apiCrmClientReferrals = apiCrmClientReferrals;
    window.apiCrmEnsureReferralCode = apiCrmEnsureReferralCode;
    window.apiCrmSales = apiCrmSales;
    window.apiCrmStore = apiCrmStore;
    window.apiCrmStoreImport = apiCrmStoreImport;
    window.apiCrmAdminTools = apiCrmAdminTools;

    // Search
    window.apiGlobalSearch = apiGlobalSearch;

    // Profile
    window.apiGetUserProfile = apiGetUserProfile;
    window.apiUpdateUserProfile = apiUpdateUserProfile;
    window.apiUploadAvatar = apiUploadAvatar;
    window.apiUploadLogo = apiUploadLogo;

    // Telegram
    window.apiTestTelegram = apiTestTelegram;
    window.apiUpdateTelegram = apiUpdateTelegram;

    // Work schedules
    window.apiGetWorkSchedules = apiGetWorkSchedules;
    window.apiCreateWorkSchedule = apiCreateWorkSchedule;
    window.apiUpdateWorkSchedule = apiUpdateWorkSchedule;
    window.apiDeleteWorkSchedule = apiDeleteWorkSchedule;

    // Documents
    window.apiDocumentsTemplates = apiDocumentsTemplates;
    window.apiDocumentsClients = apiDocumentsClients;
    window.apiDocumentsFields = apiDocumentsFields;
    window.apiDocumentsHistory = apiDocumentsHistory;
    window.apiDocumentsGenerate = apiDocumentsGenerate;
    window.apiDocumentsGenerateBatch = apiDocumentsGenerateBatch;

    // Utils
    window.getToken = getToken;
    window.saveToken = saveToken;
    window.subscribeToTokenRefresh = subscribeToTokenRefresh;
    window.parseApiResponse = parseApiResponse;
    window.fetchJsonOrThrow = fetchJsonOrThrow;
    window.getApiErrorMessage = getApiErrorMessage;
}
