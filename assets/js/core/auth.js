/**
 * core/auth.js - Модуль авторизации TaskFlow Pro
 * JWT в httpOnly cookie + резервное хранилище в localStorage
 * ModSecurity-совместимая авторизация с поддержкой обратной совместимости
 */

const AuthModule = (function() {
    // Состояние
    let isAuthenticated = false;
    let currentUser = null;
    let token = null;
    let refreshTimer = null;
    let isRefreshing = false;
    let refreshSubscribers = [];

    async function parseAuthResponse(response) {
        const rawText = await response.text();
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        const isJson = contentType.includes('application/json');

        let data = {};
        if (rawText) {
            if (isJson) {
                try {
                    data = JSON.parse(rawText);
                } catch (_) {
                    data = { success: false, error: 'Сервер вернул поврежденный JSON' };
                }
            } else {
                data = { success: false, error: 'Сервер вернул не-JSON ответ' };
            }
        }

        if (!response.ok) {
            const error = new Error(data.error || `Ошибка запроса (${response.status})`);
            error.status = response.status;
            error.data = data;
            throw error;
        }

        return data;
    }

    // ============================================
    // JWT STORAGE MANAGEMENT (Cookie + localStorage fallback)
    // ============================================

    /**
     * Получить JWT из cookie (приоритет)
     */
    function getJwtFromCookie() {
        const name = 'jwt_token=';
        const decodedCookie = decodeURIComponent(document.cookie);
        const ca = decodedCookie.split(';');

        for (let c of ca) {
            c = c.trim();
            if (c.indexOf(name) === 0) {
                return c.substring(name.length);
            }
        }
        return null;
    }

    /**
     * Получить токен из localStorage (fallback для обратной совместимости)
     */
    function getJwtFromLocalStorage() {
        try {
            return localStorage.getItem('token');
        } catch (e) {
            return null;
        }
    }

    /**
     * Сохранить токен в localStorage (для обратной совместимости)
     * Cookie устанавливается сервером
     */
    function saveTokenToLocalStorage(tokenStr) {
        try {
            localStorage.setItem('token', tokenStr);
        } catch (e) {
            console.warn('Не удалось сохранить токен в localStorage:', e);
        }
    }

    /**
     * Удалить токен из localStorage
     */
    function removeTokenFromLocalStorage() {
        try {
            localStorage.removeItem('token');
        } catch (e) {
            // Игнорируем ошибки
        }
    }

    /**
     * Получить токен из любого доступного источника
     * Приоритет: cookie > localStorage
     */
    function getToken() {
        // Сначала пробуем cookie (основной способ)
        const cookieToken = getJwtFromCookie();
        if (cookieToken) {
            // Синхронизируем с localStorage для обратной совместимости
            saveTokenToLocalStorage(cookieToken);
            return cookieToken;
        }

        // Fallback: localStorage (для обратной совместимости)
        const localToken = getJwtFromLocalStorage();
        if (localToken) {
            return localToken;
        }

        return null;
    }

    /**
     * Проверка валидности токена (без расшифровки)
     */
    function isTokenValid(tokenStr) {
        if (!tokenStr) return false;

        try {
            const parts = tokenStr.split('.');
            if (parts.length !== 3) return false;

            // Декодируем payload
            const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));

            // Проверяем срок действия
            if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
                return false;
            }

            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * Получить время истечения токена (в секундах)
     */
    function getTokenExpiry(tokenStr) {
        if (!tokenStr) return 0;

        try {
            const parts = tokenStr.split('.');
            if (parts.length !== 3) return 0;

            const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
            return payload.exp || 0;
        } catch (e) {
            return 0;
        }
    }

    /**
     * Получить оставшееся время жизни токена (в секундах)
     */
    function getTokenTimeLeft(tokenStr) {
        const expiry = getTokenExpiry(tokenStr);
        if (!expiry) return 0;
        return Math.max(0, expiry - Math.floor(Date.now() / 1000));
    }

    /**
     * Получить данные пользователя из токена (без расшифровки подписи)
     */
    function getUserFromToken(tokenStr) {
        if (!tokenStr) return null;

        try {
            const parts = tokenStr.split('.');
            if (parts.length !== 3) return null;

            const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
            return {
                user_id: payload.user_id,
                login: payload.login,
                role: payload.role,
                full_name: payload.full_name,
                exp: payload.exp
            };
        } catch (e) {
            return null;
        }
    }

    // ============================================
    // REFRESH TOKEN SUBSCRIBER PATTERN
    // ============================================

    /**
     * Подписаться на обновление токена
     */
    function subscribeToTokenRefresh(callback) {
        refreshSubscribers.push(callback);
    }

    /**
     * Уведомить подписчиков об обновлении токена
     */
    function notifyTokenRefresh(newToken) {
        refreshSubscribers.forEach(callback => {
            try {
                callback(newToken);
            } catch (e) {
                console.error('Token refresh subscriber error:', e);
            }
        });
        refreshSubscribers = [];
    }

    // ============================================
    // AUTH API CALLS (ModSecurity-safe)
    // ============================================

    /**
     * Логин - отправляем данные в POST body (не в query!)
     */
    async function login(loginData) {
        try {
            const response = await fetch('api/index.php?endpoint=auth/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    login: loginData.login,
                    password: loginData.password
                }),
                credentials: 'include' // Важно для cookie
            });

            const data = await parseAuthResponse(response);

            if (data.success) {
                // Токен теперь в cookie, сохраняем также в localStorage для обратной совместимости
                const serverToken = data.data?.token;
                if (serverToken) {
                    saveTokenToLocalStorage(serverToken);
                    token = serverToken;
                } else {
                    // Токен только в cookie
                    token = getJwtFromCookie();
                }

                isAuthenticated = true;
                currentUser = data.data?.user || null;
                startRefreshTimer();
                return { success: true, user: currentUser, token: serverToken };
            } else {
                return { success: false, error: data.error || 'Ошибка входа' };
            }
        } catch (error) {
            console.error('Login error:', error);
            return {
                success: false,
                error: error?.data?.error || error?.message || 'Ошибка подключения к серверу'
            };
        }
    }

    /**
     * Загрузка данных текущего пользователя
     */
    async function loadCurrentUser() {
        try {
            const response = await fetch('api/index.php?endpoint=auth/whoami', {
                method: 'GET',
                credentials: 'include' // Отправляем cookie с токеном
            });

            const data = await parseAuthResponse(response);

            if (data.success && data.data) {
                currentUser = data.data;
                isAuthenticated = true;
                return currentUser;
            } else {
                isAuthenticated = false;
                currentUser = null;
                return null;
            }
        } catch (error) {
            console.error('Auth check error:', error);
            isAuthenticated = false;
            currentUser = null;
            return null;
        }
    }

    /**
     * Проверка авторизации при загрузке
     */
    async function checkAuth() {
        const tokenFromStorage = getToken();

        if (tokenFromStorage && isTokenValid(tokenFromStorage)) {
            token = tokenFromStorage;
            const user = await loadCurrentUser();
            if (user) {
                startRefreshTimer();
                return true;
            }
        }

        // Токен невалиден или пользователь не найден - очищаем
        await logout(false); // false = не отправлять запрос на сервер
        isAuthenticated = false;
        currentUser = null;
        return false;
    }

    /**
     * Выход из системы
     * @param {boolean} sendRequest - отправлять ли запрос на сервер
     */
    async function logout(sendRequest = true) {
        if (sendRequest) {
            try {
                await fetch('api/index.php?endpoint=auth/logout', {
                    method: 'POST',
                    credentials: 'include'
                });
            } catch (e) {
                // Игнорируем ошибки выхода
            }
        }

        isAuthenticated = false;
        currentUser = null;
        token = null;
        stopRefreshTimer();

        // Очищаем cookie
        document.cookie = 'jwt_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        // Очищаем localStorage
        removeTokenFromLocalStorage();
    }

    // ============================================
    // SESSION REFRESH (автопродление)
    // ============================================

    /**
     * Запуск таймера автопродления сессии
     */
    function startRefreshTimer() {
        stopRefreshTimer();

        // Проверяем токен каждые 2 минуты
        refreshTimer = setInterval(async () => {
            if (isAuthenticated) {
                const currentToken = getToken();
                if (currentToken && isTokenValid(currentToken)) {
                    // Проверяем, не истекает ли токен скоро (меньше 10 минут)
                    const timeLeft = getTokenTimeLeft(currentToken);

                    if (timeLeft < 600) {
                        // Токен скоро истечёт - продлеваем
                        await refreshToken();
                    }
                } else {
                    // Токен невалиден - выходим
                    await logout(false);
                }
            }
        }, 120000); // 2 минуты
    }

    function stopRefreshTimer() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    /**
     * Продление токена (refresh token flow)
     */
    async function refreshToken() {
        if (isRefreshing) {
            // Уже идёт обновление - ждём
            return new Promise((resolve, reject) => {
                const checkInterval = setInterval(() => {
                    if (!isRefreshing) {
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

        isRefreshing = true;

        try {
            const response = await fetch('api/index.php?endpoint=auth/refresh', {
                method: 'POST',
                credentials: 'include'
            });

            const data = await parseAuthResponse(response);

            if (data.success) {
                // Новый токен в cookie, получаем его
                const newToken = getJwtFromCookie() || getToken();
                if (newToken) {
                    token = newToken;
                    saveTokenToLocalStorage(newToken);
                    console.log('Token refreshed successfully, time left:', getTokenTimeLeft(newToken), 'seconds');
                }
                notifyTokenRefresh(newToken);
                return newToken;
            } else {
                // Не удалось обновить токен - выходим
                await logout(false);
                throw new Error('Token refresh failed');
            }
        } catch (error) {
            console.error('Token refresh error:', error);
            await logout(false);
            throw error;
        } finally {
            isRefreshing = false;
        }
    }

    // ============================================
    // PUBLIC API
    // ============================================

    return {
        // Основные методы авторизации
        checkAuth,
        login,
        logout,
        loadCurrentUser,

        // Получение состояния
        isAuthenticated: () => isAuthenticated,
        getCurrentUser: () => currentUser,
        getToken,

        // Утилиты токена
        isTokenValid,
        getTokenTimeLeft,
        getUserFromToken,

        // Refresh token
        refreshToken,
        subscribeToTokenRefresh,

        // Для отладки
        _debug: {
            getJwtFromCookie,
            getJwtFromLocalStorage
        }
    };
})();

// Экспорт для модульной системы
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AuthModule;
}

// Экспорт в глобальную область для обратной совместимости
if (typeof window !== 'undefined') {
    window.AuthModule = AuthModule;
}
