window.TaskFlowCrmFunnels = (function () {
    function getColumnDisplayMode(ctx) {
        return ctx.crmColumnDisplayMode === 'count' || ctx.crmColumnDisplayMode === 'sum'
            ? ctx.crmColumnDisplayMode
            : 'both';
    }

    function dealsByStage(ctx, stageId) {
        return (ctx.crmDeals || []).filter(d => String(d.stage_id) === String(stageId));
    }

    return {
        dealsByStage,

        stageSum(ctx, stageId) {
            return dealsByStage(ctx, stageId).reduce((acc, d) => acc + Number(d.amount || 0), 0);
        },

        getColumnDisplayMode,

        getColumnDisplayModeLabel(ctx) {
            return {
                both: 'Суммы и сделки',
                count: 'Только сделки',
                sum: 'Только суммы',
            }[getColumnDisplayMode(ctx)];
        },

        toggleColumnDisplayMode(ctx) {
            const currentMode = getColumnDisplayMode(ctx);
            ctx.crmColumnDisplayMode = currentMode === 'both'
                ? 'count'
                : (currentMode === 'count' ? 'sum' : 'both');
            return ctx.crmColumnDisplayMode;
        },

        onDealDragStart(ctx, deal) {
            ctx.crmDraggingDeal = deal || null;
        },

        getStageDisplayName(stage) {
            return String(stage?.name || '');
        },

        openContextMenu(ctx, event, deal = null, stage = null) {
            if (event?.preventDefault) event.preventDefault();
            if (event?.stopPropagation) event.stopPropagation();
            ctx.crmContextMenuX = Number(event?.clientX || 0);
            ctx.crmContextMenuY = Number(event?.clientY || 0);
            ctx.crmContextMenuDeal = deal;
            ctx.crmContextMenuStage = stage;
            ctx.crmContextMenuOpen = true;
        },

        closeContextMenu(ctx) {
            ctx.crmContextMenuOpen = false;
            ctx.crmContextMenuDeal = null;
            ctx.crmContextMenuStage = null;
        },
    };
})();
