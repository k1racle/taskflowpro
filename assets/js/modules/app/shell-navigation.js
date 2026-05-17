window.TaskFlowShellNavigation = (function () {
    return {
        getAllNavigationItems(ctx) {
            return window.TaskFlowShellNavigationMeta?.getAllNavigationItems(ctx) || [];
        },

        getCurrentNavigationItem(ctx) {
            return this.getAllNavigationItems(ctx).find((item) => item?.id === ctx?.currentView) || null;
        },

        getCurrentViewTitle(ctx) {
            return window.TaskFlowViewMeta?.getTitle(ctx)
                || this.getCurrentNavigationItem(ctx)?.label
                || 'TaskFlow Pro';
        },

        getCurrentViewSubtitle(ctx) {
            return window.TaskFlowViewMeta?.getSubtitle(ctx) || '';
        },

        toggleWidgetsPanel(ctx) {
            ctx.widgetsPanelOpen = !ctx.widgetsPanelOpen;
            ctx.notificationsPanelOpen = false;
            ctx.mobileMoreOpen = false;
            ctx.mobileProfileOpen = false;
        }
    };
})();
