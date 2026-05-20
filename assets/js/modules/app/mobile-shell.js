window.TaskFlowMobileShell = (function () {
    const CRM_VIEWS = ['crm-dashboard', 'crm-clients', 'crm-funnels', 'crm-sales', 'crm-store'];
    const WORK_VIEWS = ['tasks', 'my-tasks', 'projects', 'departments', 'leader-dashboard'];
    const RESOURCE_VIEWS = ['mail', 'files', 'knowledge', 'documents'];
    const COMM_VIEWS = ['chat', 'conferences', 'helpdesk', 'booking', 'my-shift'];
    const ADMIN_VIEWS = ['users', 'roles', 'stages-manager', 'settings'];

    return {
        isMoreViewActive(ctx, viewId) {
            if (viewId === 'crm-dashboard') return CRM_VIEWS.includes(ctx.currentView);
            return ctx.currentView === viewId;
        },

        getContextTag(ctx) {
            return window.TaskFlowShellNavigationMeta?.getMobileContextTag(ctx) || 'Workspace';
        },

        getMoreGroups(ctx) {
            return window.TaskFlowShellNavigationMeta?.getMobileMoreGroups(ctx) || [];
        },

        openMore(ctx) {
            ctx.closeAllPanels();
            ctx.sidebarOpen = false;
            ctx.mobileMoreOpen = true;
        },

        closeMore(ctx) {
            ctx.mobileMoreOpen = false;
        },

        openProfile(ctx) {
            ctx.closeAllPanels();
            ctx.sidebarOpen = false;
            ctx.mobileProfileOpen = true;
            ctx.loadSettings();
        },

        closeProfile(ctx) {
            ctx.mobileProfileOpen = false;
        },

        async handleProfileAction(ctx, action) {
            if (action === 'profile') {
                this.closeProfile(ctx);
                await ctx.openSettingsModal();
                return;
            }

            if (action === 'theme') {
                ctx.toggleTheme();
                return;
            }

            if (action === 'notifications') {
                this.closeProfile(ctx);
                ctx.toggleNotificationsPanel();
                return;
            }

            if (action === 'widgets') {
                this.closeProfile(ctx);
                ctx.toggleWidgetsPanel();
                return;
            }

            if (action === 'search') {
                this.closeProfile(ctx);
                ctx.openTopbarSearch();
                return;
            }

            if (action === 'chat') {
                ctx.closeAllPanels();
                ctx.sidebarOpen = false;
                ctx.currentView = 'chat';
                return;
            }

            if (action === 'booking') {
                ctx.closeAllPanels();
                ctx.sidebarOpen = false;
                ctx.currentView = 'booking';
                return;
            }

            if (action === 'logout') {
                this.closeProfile(ctx);
                ctx.logout();
            }
        },

        handleHeaderAction(ctx, action) {
            if (action === 'search') {
                if (ctx.topbarSearchOpen) {
                    ctx.closeTopbarSearch();
                } else {
                    ctx.openTopbarSearch();
                }
                return;
            }

            if (action === 'notifications') {
                ctx.toggleNotificationsPanel();
                return;
            }

            if (action === 'quick-actions') {
                this.openMore(ctx);
                return;
            }

            if (action === 'profile') {
                if (ctx.mobileProfileOpen) {
                    this.closeProfile(ctx);
                } else {
                    this.openProfile(ctx);
                }
                return;
            }

            if (action === 'more') {
                this.openMore(ctx);
            }
        },

        handleQuickAction(ctx, action) {
            ctx.closeAllPanels();
            ctx.sidebarOpen = false;
            ctx.mobileMoreOpen = false;

            if (action === 'task') {
                ctx.openTaskModal();
                return;
            }

            if (action === 'client') {
                ctx.currentView = 'crm-clients';
                ctx.ensureCrmLoaded();
                ctx.crmOpenClientModal();
                return;
            }

            if (action === 'search') {
                ctx.openTopbarSearch();
                return;
            }

            if (action === 'notifications') {
                ctx.toggleNotificationsPanel();
                return;
            }

            if (action === 'chat') {
                ctx.currentView = 'chat';
                return;
            }

            if (action === 'widgets') {
                ctx.toggleWidgetsPanel();
            }
        },

        navigateMore(ctx, viewId) {
            ctx.closeAllPanels();
            ctx.sidebarOpen = false;

            if (viewId === 'crm-dashboard') {
                ctx.currentView = 'crm-dashboard';
                ctx.ensureCrmLoaded();
                return;
            }

            ctx.currentView = viewId;
        },

        getPrimaryNavItems(ctx) {
            const items = [
                { id: 'tasks', label: 'Главная', icon: 'M3 12l9-8 9 8M5 10v10h14V10M9 20v-6h6v6' },
                { id: 'my-tasks', label: 'Задачи', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4' },
        { id: 'crm-dashboard', label: 'CRM', icon: 'M3 3h18v6H3V3zm0 8h10v10H3V11zm12 0h6v10h-6V11z', visible: () => !!ctx.canCrm },
        { id: 'more', label: 'Меню', icon: 'M4 6h16M4 12h16M4 18h16' }
            ];

            return items
                .filter(item => item.id !== 'chat')
                .filter(item => !item.visible || item.visible());
        },

        isNavActive(ctx, viewId) {
            if (viewId === 'more') return ctx.mobileMoreOpen;
            if (viewId === 'crm-dashboard') return CRM_VIEWS.includes(ctx.currentView);
            if (viewId === 'tasks') return ['tasks', 'projects', 'departments', 'leader-dashboard'].includes(ctx.currentView);
            if (viewId === 'my-tasks') return ctx.currentView === 'my-tasks';
            return ctx.currentView === viewId;
        },

        navigatePrimary(ctx, viewId) {
            ctx.closeAllPanels();

            if (viewId === 'more') {
                ctx.mobileMoreOpen = !ctx.mobileMoreOpen;
                ctx.sidebarOpen = false;
                return;
            }

            ctx.mobileMoreOpen = false;
            ctx.sidebarOpen = false;

            if (viewId === 'crm-dashboard') {
                ctx.currentView = 'crm-dashboard';
                ctx.ensureCrmLoaded();
                return;
            }

            if (viewId === 'my-tasks') {
                ctx.currentView = 'my-tasks';
                return;
            }

            if (viewId === 'booking') {
                ctx.currentView = 'booking';
                return;
            }

            ctx.currentView = viewId;
        }
    };
})();
