window.TaskFlowShellNavigationMeta = (function () {
    const NAV_ITEMS = [
        { id: 'tasks', label: 'Задачи', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', badge: '' },
        { id: 'my-tasks', label: 'Мои задачи', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', badge: '' },
        { id: 'crm-dashboard', label: 'CRM', icon: 'M3 3h18v6H3V3zm0 8h10v10H3V11zm12 0h6v10h-6V11z', badge: '', visible() { return !!this.canCrm; } },
        { id: 'crm-clients', label: 'Клиенты', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-8 8a8 8 0 0116 0H4z', badge: '', visible() { return !!this.canCrm; } },
        { id: 'crm-funnels', label: 'Воронка', icon: 'M3 4h18l-7 8v6l-4 2v-8L3 4z', badge: '', visible() { return !!this.canCrm; } },
        { id: 'crm-sales', label: 'Продажи', icon: 'M5 3v18m0 0h16m-4-5l-4-4-3 3-4-6', badge: '', visible() { return !!this.canCrm; } },
        { id: 'crm-store', label: 'Интернет-магазин', icon: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 9h14l-2-9M10 21a1 1 0 11-2 0 1 1 0 012 0zm8 0a1 1 0 11-2 0 1 1 0 012 0z', badge: '', visible() { return !!this.canCrm; } },
        { id: 'leader-dashboard', label: 'Руководитель', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm6 8a6 6 0 00-12 0h12z', badge: '', visible() { return this.can('leader.view'); } },
        { id: 'projects', label: 'Проекты', icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z', badge: '' },
        { id: 'departments', label: 'Отделы', icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', badge: '' },
        { id: 'files', label: 'Файлы', icon: 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z', badge: '' },
        { id: 'knowledge', label: 'База знаний', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', badge: '' },
        { id: 'documents', label: 'Документы', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', badge: '' },
        { id: 'booking', label: 'Запись', icon: 'M8 2v4m8-4v4M3 10h18M5 6h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z', badge: '', visible() { return true; } },
        { id: 'widgets', label: 'Виджеты сайта', icon: 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4', badge: '' },
        { id: 'mail', label: 'Почта', icon: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', badge: '' },
        { id: 'conferences', label: 'Конференции', icon: 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z', badge: '' }
    ];

    const ADMIN_NAV_ITEMS = [
        { id: 'users', label: 'Пользователи', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656.126-1.283.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z' },
        { id: 'roles', label: 'Роли и права', icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
        { id: 'stages-manager', label: 'Этапы', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' },
        { id: 'settings', label: 'Настройки', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' }
    ];

    const GROUP_VIEW_IDS = {
        workspace: ['projects', 'departments', 'leader-dashboard', 'helpdesk', 'booking', 'my-shift'],
        resources: ['files', 'knowledge', 'documents', 'mail', 'widgets', 'conferences']
    };

    function cloneItems(items) {
        return items.map((item) => ({ ...item }));
    }

    function getAllNavigationItems(ctx) {
        const navItems = Array.isArray(ctx?.navItems) ? ctx.navItems : [];
        const adminNavItems = Array.isArray(ctx?.adminNavItems) ? ctx.adminNavItems : [];
        return [...navItems, ...adminNavItems];
    }

    function isItemVisible(ctx, item) {
        return !item?.visible || item.visible.call(ctx);
    }

    return {
        createNavItems() {
            return cloneItems(NAV_ITEMS);
        },

        createAdminNavItems() {
            return cloneItems(ADMIN_NAV_ITEMS);
        },

        getAllNavigationItems,

        getMobileContextTag(ctx) {
            const currentView = String(ctx?.currentView || '');
            if (['crm-dashboard', 'crm-clients', 'crm-funnels', 'crm-sales', 'crm-store'].includes(currentView)) return 'CRM';
            if (['tasks', 'my-tasks', 'projects', 'departments', 'leader-dashboard'].includes(currentView)) return 'Работа';
            if (['booking'].includes(currentView)) return 'Запись';
            if (['mail', 'files', 'knowledge', 'documents'].includes(currentView)) return 'Ресурсы';
            if (['chat', 'conferences', 'helpdesk', 'booking', 'my-shift'].includes(currentView)) return 'Коммуникации';
            if (['users', 'roles', 'stages-manager', 'settings'].includes(currentView)) return 'Администрирование';
            return 'Workspace';
        },

        getMobileMoreGroups(ctx) {
            const nav = Array.isArray(ctx?.navItems)
                ? ctx.navItems.filter((item) => isItemVisible(ctx, item))
                : [];
            const admin = ctx?.can && ctx.can('admin.full')
                ? (Array.isArray(ctx?.adminNavItems) ? ctx.adminNavItems : [])
                : [];

            return [
                {
                    id: 'workspace',
                    label: 'Рабочие разделы',
                    items: nav.filter((item) => GROUP_VIEW_IDS.workspace.includes(item.id) || item.id === 'booking')
                },
                {
                    id: 'resources',
                    label: 'Ресурсы и документы',
                    items: nav.filter((item) => GROUP_VIEW_IDS.resources.includes(item.id))
                },
                {
                    id: 'admin',
                    label: 'Администрирование',
                    items: admin
                }
            ].filter((group) => Array.isArray(group.items) && group.items.length);
        }
    };
})();
