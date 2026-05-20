window.TaskFlowViewRuntime = (function () {
    return {
        initCurrentViewWatcher(ctx) {
            let prevView = String(ctx.currentView || '');

            ctx.$watch('currentView', (newView) => {
                const nextView = String(newView || '');

                // Stop chat polling when leaving chat view
                if (prevView === 'chat' && nextView !== 'chat') {
                    try {
                        window.TaskFlowChat?.stopPolling?.(ctx);
                    } catch (_e) {}
                }

                if (newView === 'mail') {
                    ctx.loadMailFolders();
                    ctx.loadMailFromFolder('inbox');
                }

                if (newView === 'stages-manager') {
                    ctx.refreshStagesManager(ctx.stagesManagerType).catch(() => {});
                }

                if (newView === 'documents') {
                    ctx.ensureCrmLoaded();
                    ctx.documentsReload();
                }

                if (newView === 'crm-sales') {
                    ctx.ensureCrmLoaded();
                    ctx.crmLoadSalesAnalytics();
                }

                if (newView === 'chat') {
                    // Chat was previously only started from overlay. Ensure it works from sidebar too.
                    try {
                        window.TaskFlowChat?.startPolling?.(ctx);
                    } catch (_e) {}
                    ctx.loadChatRooms?.();
                }

                if (newView === 'booking') {
                    ctx.ensureBookingLoaded?.();
                }

                prevView = nextView;
            });
        }
    };
})();
