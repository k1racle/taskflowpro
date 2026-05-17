window.TaskFlowAuthSession = (function () {
    function getLoginWishes() {
        return [
            'Пусть день сложится легко и продуктивно.',
            'Хорошего фокуса и спокойного темпа.',
            'Пусть задачи закрываются одна за другой.',
            'Побольше ясных решений и поменьше суеты.',
            'Пусть всё важное сегодня получится.',
            'Хорошей командной работы и добрых новостей.',
            'Пусть встречи будут короткими, а результаты - большими.',
            'Пусть будет время и на работу, и на себя.',
            'Пусть всё пойдёт по плану, даже если плана нет.',
            'Пусть день принесёт что-то приятное.',
        ];
    }

    function applyLicenseState(ctx, data, error) {
        ctx.license = {
            checked: true,
            enabled: !!data?.enabled,
            valid: !!data?.valid,
            licensed_domain: data?.licensed_domain ?? null,
            request_domain: data?.request_domain ?? null,
            error: error || null,
        };
    }

    async function loadAllDataPayload(ctx) {
        await Promise.all([
            ctx.loadUsers(),
            ctx.loadDepartments(),
            ctx.loadProjects(),
            ctx.loadTasks(),
            ctx.loadStages(),
            ctx.loadSettings(),
            ctx.loadKnowledge(),
            ctx.loadFiles(),
            ctx.loadFileTree(),
            ctx.loadNotifications(),
            ctx.loadRoles(),
            ctx.loadPermissions(),
            ctx.loadCrmData(),
        ]);

        ctx.startNotificationsPolling();
    }

    return {
        getRandomLoginWish() {
            const wishes = getLoginWishes();
            return wishes[Math.floor(Math.random() * wishes.length)];
        },

        async login(ctx) {
            ctx.loading = true;
            ctx.loginError = '';

            const loginName = (ctx.loginForm.login || '').trim();
            ctx.loginTransitionName = loginName;
            ctx.loginTransitionWish = this.getRandomLoginWish();

            try {
                let result;
                if (window.AuthModule) {
                    result = await AuthModule.login({
                        login: ctx.loginForm.login,
                        password: ctx.loginForm.password
                    });
                } else {
                    result = await apiLogin(ctx.loginForm.login, ctx.loginForm.password);
                }

                if (result.success) {
                    ctx.loginTransitionOpen = true;

                    const apiFullName = result.user?.full_name;
                    if (apiFullName) ctx.loginTransitionName = apiFullName;

                    await new Promise(resolve => setTimeout(resolve, 3000));

                    ctx.token = result.token || getToken();
                    ctx.currentUser = result.user;
                    ctx.isAuthenticated = true;

                    saveToken(ctx.token);
                    await ctx.loadCurrentUserPermissions();
                    await this.loadAllData(ctx);
                    ctx.showToast('Вход выполнен успешно', 'success');

                    ctx.loginTransitionOpen = false;
                } else {
                    ctx.loginError = result.error || 'Ошибка входа';
                }
            } catch (error) {
                ctx.loginError = error.message || 'Ошибка подключения к серверу';
            } finally {
                ctx.loginTransitionOpen = false;
                ctx.loading = false;
            }
        },

        async checkAuth(ctx) {
            try {
                if (window.AuthModule) {
                    const isAuth = await AuthModule.checkAuth();
                    if (isAuth) {
                        ctx.currentUser = AuthModule.getCurrentUser();
                        ctx.token = AuthModule.getToken();
                        ctx.isAuthenticated = true;
                        await ctx.loadCurrentUserPermissions();
                        await this.loadAllData(ctx);
                        return;
                    }
                }

                const data = await apiWhoami();
                if (data.success) {
                    ctx.currentUser = data.data;
                    ctx.isAuthenticated = true;
                    await ctx.loadCurrentUserPermissions();
                    await this.loadAllData(ctx);
                } else {
                    this.logout(ctx);
                }
            } catch (_error) {
                this.logout(ctx);
            }
        },

        async checkLicenseStatus(ctx) {
            try {
                const res = await apiGet('license/status');
                if (res?.success) {
                    applyLicenseState(ctx, res.data, null);

                    if (ctx.license.enabled && !ctx.license.valid) {
                        try { ctx.disableChatPolling?.(); } catch (_) {}
                        try { ctx.stopPoller('notifications'); } catch (_) {}
                        try { ctx.stopPoller('tasks'); } catch (_) {}
                        try { ctx.stopPoller('weather'); } catch (_) {}
                    }
                    return;
                }

                applyLicenseState(ctx, ctx.license, res?.error || 'Статус лицензии недоступен');
            } catch (_) {
                applyLicenseState(ctx, ctx.license, 'Статус лицензии недоступен');
            }
        },

        logout(ctx) {
            if (window.AuthModule) {
                AuthModule.logout(false);
            }

            ctx.isAuthenticated = false;
            ctx.currentUser = null;
            ctx.token = null;
            ctx.userPermissions = [];
            ctx.permissionsReady = false;
            saveToken(null);
            ctx.stopPoller('notifications');
            ctx.stopPoller('tasks');
            ctx.stopPoller('weather');
            location.reload();
        },

        async loadAllData(ctx) {
            return loadAllDataPayload(ctx);
        },

        async bootstrapAuthenticatedSession(ctx) {
            ctx.token = getToken();

            if (ctx.token) {
                await this.checkAuth(ctx);

                if (!ctx.defaultNavItems) {
                    ctx.defaultNavItems = JSON.parse(JSON.stringify(ctx.navItems || []));
                }

                try {
                    await ctx.applyUserMenuOrder();
                } catch (_) {}

                if (ctx.currentUser) {
                    await ctx.loadCurrentUserPermissions();
                }
            } else {
                ctx.isAuthenticated = false;
            }

            if (ctx.isAuthenticated) {
                ctx.loadChatRooms();
                ctx.loadConferences();
                ctx.initVisibilityListener();
                ctx.startChatPolling();
                ctx.startBackgroundCallPolling();
                ctx.initNotificationEnhancements();
            }

            if (ctx.isAuthenticated && ctx.can('leader.view')) {
                ctx.loadWorkSchedules();
            }

            if (ctx.isAuthenticated) {
                ctx.loadWidgets();
                ctx.loadSiteWidgetsSettings();
                ctx.loadBirthdays();
                ctx.loadWeather();
                ctx.loadTaskSubstagesDict();
                ctx.loadCrmDealSubstagesDict();
                console.log('✅ Приложение инициализировано, Long Polling запущен');
            }
        }
    };
})();
