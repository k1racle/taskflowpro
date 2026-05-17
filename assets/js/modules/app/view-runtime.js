window.TaskFlowViewRuntime = (function () {
    return {
        initCurrentViewWatcher(ctx) {
            ctx.$watch('currentView', (newView) => {
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

                if (newView === 'crm-store') {
                    ctx.ensureCrmLoaded();
                    ctx.crmLoadStoreAnalytics();
                }
            });
        }
    };
})();
