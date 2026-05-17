/**
 * core/store.js - Централизованное хранилище состояния TaskFlow Pro
 */

const AppStore = (function() {
    // Состояние приложения
    const state = {
        // Навигация
        currentView: 'tasks',
        previousView: null,
        sidebarOpen: false,
        
        // Пользователь и настройки
        currentUser: null,
        settings: {},
        enabledWidgets: [],
        
        // Данные (кэш)
        users: [],
        departments: [],
        projects: [],
        tasks: [],
        stages: [],
        chatRooms: [],
        notifications: [],
        files: [],
        knowledge: [],
        
        // Фильтры
        filters: {
            tasks: {
                project_id: '',
                status: '',
                department_id: '',
                assigned_to: ''
            },
            projects: {
                status: '',
                department_id: ''
            },
            files: {
                type: '',
                folder_id: ''
            }
        },
        
        // Формы (черновики)
        formDrafts: {
            task: null,
            project: null,
            knowledge: null
        },
        
        // UI состояние
        modals: {
            taskModalOpen: false,
            projectModalOpen: false,
            userModalOpen: false,
            departmentModalOpen: false
        },
        
        // Тема
        isDark: false,
        
        // Загрузка
        loading: false,
        loadingViews: {}
    };

    // Слушатели изменений
    const listeners = new Map();

    // ============================================
    // STATE MANAGEMENT
    // ============================================

    /**
     * Получить значение по пути (например 'filters.tasks.project_id')
     */
    function get(path) {
        if (!path) return state;
        
        return path.split('.').reduce((obj, key) => {
            return (obj && obj[key] !== undefined) ? obj[key] : undefined;
        }, state);
    }

    /**
     * Установить значение по пути
     */
    function set(path, value) {
        const keys = path.split('.');
        const lastKey = keys.pop();
        
        const target = keys.reduce((obj, key) => {
            if (!(key in obj)) {
                obj[key] = {};
            }
            return obj[key];
        }, state);
        
        const oldValue = target[lastKey];
        target[lastKey] = value;
        
        // Уведомляем слушателей
        notifyListeners(path, value, oldValue);
    }

    /**
     * Получить всё состояние
     */
    function getState() {
        return { ...state };
    }

    /**
     * Массовое обновление состояния
     */
    function setState(newState) {
        Object.assign(state, newState);
        notifyListeners('*', newState, null);
    }

    // ============================================
    // DATA CACHE MANAGEMENT
    // ============================================

    /**
     * Кэшировать данные
     */
    function cacheData(type, data) {
        if (['users', 'departments', 'projects', 'tasks', 'stages', 'chatRooms', 'notifications', 'files', 'knowledge'].includes(type)) {
            state[type] = data;
            notifyListeners(type, data, null);
        }
    }

    /**
     * Получить кэшированные данные
     */
    function getCachedData(type) {
        return state[type] || [];
    }

    /**
     * Очистить кэш
     */
    function clearCache(types = null) {
        if (types) {
            types.forEach(type => {
                if (state[type]) {
                    state[type] = [];
                    notifyListeners(type, [], null);
                }
            });
        } else {
            state.users = [];
            state.departments = [];
            state.projects = [];
            state.tasks = [];
            state.chatRooms = [];
            state.notifications = [];
            state.files = [];
            state.knowledge = [];
            notifyListeners('*', {}, null);
        }
    }

    // ============================================
    // DRAFT MANAGEMENT (автосохранение форм)
    // ============================================

    /**
     * Сохранить черновик формы
     */
    function saveDraft(formType, data) {
        state.formDrafts[formType] = {
            data,
            timestamp: Date.now()
        };
        
        // Сохраняем в localStorage для восстановления после перезагрузки
        try {
            localStorage.setItem('form_drafts', JSON.stringify(state.formDrafts));
        } catch (e) {
            console.warn('Failed to save drafts to localStorage');
        }
        
        notifyListeners(`formDrafts.${formType}`, data, null);
    }

    /**
     * Получить черновик
     */
    function getDraft(formType) {
        return state.formDrafts[formType];
    }

    /**
     * Очистить черновик
     */
    function clearDraft(formType) {
        state.formDrafts[formType] = null;
        
        try {
            localStorage.setItem('form_drafts', JSON.stringify(state.formDrafts));
        } catch (e) {}
        
        notifyListeners(`formDrafts.${formType}`, null, null);
    }

    /**
     * Загрузить черновики из localStorage
     */
    function loadDrafts() {
        try {
            const saved = localStorage.getItem('form_drafts');
            if (saved) {
                state.formDrafts = JSON.parse(saved);
            }
        } catch (e) {
            console.error('Failed to load drafts:', e);
        }
    }

    // ============================================
    // VIEW STATE PERSISTENCE
    // ============================================

    /**
     * Сохранить текущее представление
     */
    function saveViewState() {
        const viewState = {
            currentView: state.currentView,
            filters: state.filters,
            timestamp: Date.now()
        };
        
        try {
            localStorage.setItem('view_state', JSON.stringify(viewState));
        } catch (e) {
            console.warn('Failed to save view state');
        }
    }

    /**
     * Восстановить состояние представления
     */
    function restoreViewState() {
        try {
            const saved = localStorage.getItem('view_state');
            if (saved) {
                const viewState = JSON.parse(saved);
                
                // Проверяем, не устарело ли (больше 24 часов)
                const age = Date.now() - viewState.timestamp;
                if (age < 86400000) { // 24 часа
                    state.currentView = viewState.currentView || 'tasks';
                    state.filters = viewState.filters || state.filters;
                    return true;
                }
            }
        } catch (e) {
            console.error('Failed to restore view state:', e);
        }
        
        return false;
    }

    /**
     * Очистить сохранённое состояние
     */
    function clearViewState() {
        try {
            localStorage.removeItem('view_state');
        } catch (e) {}
    }

    // ============================================
    // LISTENERS (реактивность)
    // ============================================

    /**
     * Подписаться на изменения
     */
    function subscribe(path, callback) {
        if (!listeners.has(path)) {
            listeners.set(path, new Set());
        }
        listeners.get(path).add(callback);
        
        // Возвращаем функцию отписки
        return () => {
            const set = listeners.get(path);
            if (set) {
                set.delete(callback);
                if (set.size === 0) {
                    listeners.delete(path);
                }
            }
        };
    }

    /**
     * Уведомить слушателей об изменении
     */
    function notifyListeners(path, newValue, oldValue) {
        // Слушатели конкретного пути
        const specificListeners = listeners.get(path);
        if (specificListeners) {
            specificListeners.forEach(cb => {
                try {
                    cb(newValue, oldValue, path);
                } catch (e) {
                    console.error('Listener error:', e);
                }
            });
        }
        
        // Слушатели всех изменений
        const globalListeners = listeners.get('*');
        if (globalListeners) {
            globalListeners.forEach(cb => {
                try {
                    cb({ path, newValue, oldValue });
                } catch (e) {
                    console.error('Global listener error:', e);
                }
            });
        }
        
        // Слушатели родительских путей (например, 'filters' при изменении 'filters.tasks')
        const parts = path.split('.');
        for (let i = 1; i < parts.length; i++) {
            const parentPath = parts.slice(0, i).join('.');
            const parentListeners = listeners.get(parentPath);
            if (parentListeners) {
                parentListeners.forEach(cb => {
                    try {
                        cb(get(parentPath), oldValue, parentPath);
                    } catch (e) {
                        console.error('Parent listener error:', e);
                    }
                });
            }
        }
    }

    // ============================================
    // THEME MANAGEMENT
    // ============================================

    function loadTheme() {
        const saved = localStorage.getItem('theme');
        state.isDark = saved === 'dark' || 
                      (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
        return state.isDark;
    }

    function toggleTheme() {
        state.isDark = !state.isDark;
        localStorage.setItem('theme', state.isDark ? 'dark' : 'light');
        notifyListeners('isDark', state.isDark, !state.isDark);
        return state.isDark;
    }

    function applyTheme() {
        if (state.isDark) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================

    function init() {
        // Загружаем тему
        loadTheme();
        applyTheme();
        
        // Загружаем черновики
        loadDrafts();
        
        // Восстанавливаем состояние представления
        restoreViewState();
        
        console.log('AppStore initialized');
    }

    // ============================================
    // PUBLIC API
    // ============================================

    return {
        // State
        get,
        set,
        getState,
        setState,
        
        // Data cache
        cacheData,
        getCachedData,
        clearCache,
        
        // Drafts
        saveDraft,
        getDraft,
        clearDraft,
        loadDrafts,
        
        // View state
        saveViewState,
        restoreViewState,
        clearViewState,
        
        // Theme
        loadTheme,
        toggleTheme,
        applyTheme,
        
        // Listeners
        subscribe,
        
        // Init
        init
    };
})();

// Экспорт для модульной системы
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AppStore;
}
