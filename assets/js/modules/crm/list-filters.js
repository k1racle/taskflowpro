window.TaskFlowCrmListFilters = (function () {
    function buildClientsQueryString(ctx) {
        const params = new URLSearchParams();
        if (ctx.crmClientsQuery) params.set('q', ctx.crmClientsQuery);
        if (ctx.crmClientsStatus) params.set('status', ctx.crmClientsStatus);
        if (ctx.crmClientsTag) params.set('tag', ctx.crmClientsTag);
        const query = params.toString();
        return query ? `?${query}` : '';
    }

    function buildFunnelsQueryString(ctx, pipelineId) {
        const params = new URLSearchParams();
        params.set('pipeline_id', String(pipelineId));
        if (ctx.crmDealsQuery) params.set('q', ctx.crmDealsQuery);
        if (ctx.crmFunnelsClientFilter) params.set('client_id', String(ctx.crmFunnelsClientFilter));
        return params.toString();
    }

    return {
        buildClientsQueryString,

        async loadClients(ctx) {
            try {
                const res = await apiGet(`crm/clients${buildClientsQueryString(ctx)}`);
                if (res.success) ctx.crmClients = res.data || [];
            } catch (e) {
                console.warn('CRM clients error', e);
            }
        },

        buildFunnelsQueryString,

        async loadFunnels(ctx) {
            try {
                const p = await apiGet('crm/pipelines');
                if (p.success) {
                    ctx.crmPipelines = p.data || [];
                    const defaultPipelineId = ctx.crmPipelines.find((item) => Number(item.is_default) === 1)?.id || null;
                    const hasActivePipeline = ctx.crmPipelines.some((item) => String(item.id) === String(ctx.crmActivePipelineId));
                    if (!hasActivePipeline) {
                        ctx.crmActivePipelineId = defaultPipelineId || ctx.crmPipelines[0]?.id || null;
                    }
                }

                const pipelineId = ctx.crmActivePipelineId || (ctx.crmPipelines.find((item) => Number(item.is_default) === 1)?.id ?? ctx.crmPipelines[0]?.id ?? 1);
                ctx.crmActivePipelineId = pipelineId;

                const s = await apiGet(`crm/pipelines/${pipelineId}/stages`);
                ctx.crmStages = s.success ? (s.data || []) : [];

                const d = await apiGet(`crm/deals?${buildFunnelsQueryString(ctx, pipelineId)}`);
                ctx.crmDeals = d.success ? (d.data || []) : [];
            } catch (e) {
                console.warn('CRM funnels error', e);
            }
        },

    };
})();
