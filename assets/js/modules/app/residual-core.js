window.TaskFlowAppResidualCore = (function () {
    const DAY_NAMES = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const MONTH_NAMES = ['янв.', 'февр.', 'март', 'апр.', 'май', 'июнь', 'июль', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];

    return {
        async loadCurrentUserPermissions(ctx) {
            ctx.permissionsReady = false;

            if (!ctx.currentUser?.id) {
                ctx.userPermissions = [];
                ctx.permissionsReady = true;
                return;
            }

            try {
                const permsData = await apiGetUserPermissions(ctx.currentUser.id);
                ctx.userPermissions = permsData.success && Array.isArray(permsData.data) ? permsData.data : [];
            } catch (_error) {
                console.warn('Не удалось загрузить права пользователя, используем права по умолчанию');
                ctx.userPermissions = [];
            } finally {
                ctx.permissionsReady = true;
            }
        },

        ensureCrmLoaded(ctx) {
            if (ctx._crmLoaded) return;
            ctx._crmLoaded = true;
            setTimeout(() => {
                try { ctx.crmLoadDashboard?.(); } catch (_) {}
                try { ctx.crmLoadClients?.(); } catch (_) {}
                try { ctx.crmLoadFunnels?.(); } catch (_) {}
                try { ctx.crmLoadSalesAnalytics?.(); } catch (_) {}
                try { ctx.documentsReload?.(); } catch (_) {}
            }, 0);
        },

        ensureLeaderLoaded(ctx) {
            if (ctx._leaderLoaded) return;
            ctx._leaderLoaded = true;
            setTimeout(() => {
                try { ctx.loadMyShift?.(); } catch (_) {}
                try { ctx.loadLeaderDashboard?.(); } catch (_) {}
                if (ctx.currentView === 'work-schedules') {
                    try {
                        ctx.loadWorkSchedules();
                        ctx.generateCalendarDays();
                    } catch (_) {}
                }
            }, 0);
        },

        initGanttBridge(ctx) {
            try {
                window.__tfOpenTask = (taskId) => {
                    const task = (ctx.tasks || []).find(t => String(t.id) === String(taskId));
                    if (task) ctx.openTaskModal(task);
                };
            } catch (_) {}
        },

        updateDateTime(ctx) {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            ctx.currentTime = { hours: `${hours}:${minutes}` };

            const dayName = DAY_NAMES[now.getDay()];
            const day = String(now.getDate()).padStart(2, '0');
            const month = MONTH_NAMES[now.getMonth()];
            const year = now.getFullYear();
            ctx.currentDate = {
                full: `${day} ${month} ${year}`,
                dayName,
            };
        }
    };
})();
