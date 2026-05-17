/**
 * app.js - TaskFlow Pro (МОДУЛЬНАЯ ВЕРСИЯ)
 * Только состояние и базовые функции
 */

function app() {
    return {
        // ===== СОСТОЯНИЕ =====
        isLoading: true,
        isAuthenticated: false,
        currentUser: null,
        currentView: 'tasks',
        sidebarOpen: false,
        isDark: false,
        settings: {},
        
        // Формы
        loginForm: { login: '', password: '' },
        loginError: '',
        loading: false,

        // Навигация
        navItems: [
            { id: 'tasks', label: 'Задачи', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' },
            { id: 'my-tasks', label: 'Мои задачи', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' },
            { id: 'projects', label: 'Проекты', icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z' },
            { id: 'departments', label: 'Отделы', icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
            { id: 'files', label: 'Файлы', icon: 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z' },
            { id: 'knowledge', label: 'База знаний', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
            { id: 'mail', label: 'Почта', icon: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
            { id: 'conferences', label: 'Конференции', icon: 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z' },
            { id: 'my-shift', label: 'Моя смена', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
            { id: 'chat', label: 'Чат', icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.96 9.96 0 01-4.243-.947L4 20l1.06-3.747A8.968 8.968 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' }
        ],

        // ===== ИНИЦИАЛИЗАЦИЯ =====
        async init() {
            this.loadTheme();
            await this.checkAuth();
            this.isLoading = false;
        },

        // ===== АВТОРИЗАЦИЯ =====
        async checkAuth() {
            const token = localStorage.getItem('token');
            if (!token) {
                this.isAuthenticated = false;
                return;
            }

            try {
                const response = await fetch('api/index.php?endpoint=auth/whoami', {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await response.json();
                
                if (data.success) {
                    this.isAuthenticated = true;
                    this.currentUser = data.data;
                    this.settings = data.data.settings || {};
                }
            } catch (error) {
                console.error('Auth error:', error);
            }
        },

        async login() {
            this.loading = true;
            this.loginError = '';

            try {
                const response = await fetch('api/index.php?endpoint=auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.loginForm)
                });
                const data = await response.json();

                if (data.success) {
                    localStorage.setItem('token', data.data.token);
                    await this.checkAuth();
                } else {
                    this.loginError = data.error || 'Ошибка входа';
                }
            } catch (error) {
                this.loginError = 'Ошибка подключения';
            } finally {
                this.loading = false;
            }
        },

        // ===== ТЕМЫ =====
        loadTheme() {
            const saved = localStorage.getItem('theme');
            this.isDark = saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
            this.applyTheme();
        },

        toggleTheme() {
            this.isDark = !this.isDark;
            this.applyTheme();
        },

        applyTheme() {
            if (this.isDark) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            }
        },

        // ===== УТИЛИТЫ =====
        getCurrentViewTitle() {
            const item = this.navItems.find(i => i.id === this.currentView);
            return item?.label || 'TaskFlow Pro';
        },

        getPageSubtitle() {
            const subtitles = {
                'tasks': 'Управление задачами',
                'my-tasks': 'Личный список задач',
                'projects': 'Управление проектами',
                'departments': 'Структура компании',
                'files': 'Файловое хранилище',
                'knowledge': 'База знаний',
                'mail': 'Почтовый клиент',
                'conferences': 'Видеовстречи',
                'my-shift': 'Учет рабочего времени',
                'chat': 'Мессенджер'
            };
            return subtitles[this.currentView] || '';
        },

        getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
        },

        formatDate(dateStr) {
            if (!dateStr) return '—';
            return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
        },

        translatePriority(priority) {
            const translations = {
                'low': 'Низкий',
                'medium': 'Средний',
                'high': 'Высокий',
                'urgent': 'Срочный'
            };
            return translations[priority] || priority;
        },

        isOverdue(dateStr) {
            if (!dateStr) return false;
            return new Date(dateStr) < new Date();
        }
    };
}
