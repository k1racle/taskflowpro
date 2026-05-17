window.TaskFlowCrmFormsModals = (function () {
    function getClientTagsText(client) {
        if (!client?.tags) return '';
        return Array.isArray(client.tags) ? client.tags.join(', ') : String(client.tags);
    }

    return {
        openClientModal(ctx, client = null) {
            ctx.crmClientForm = {
                id: client?.id || null,
                name: client?.name || '',
                type: client?.type || 'person',
                email: client?.email || '',
                phone: client?.phone || '',
                site: client?.site || '',
                address: client?.address || '',
                legal_name_full: client?.legal_name_full || '',
                legal_name_short: client?.legal_name_short || '',
                inn: client?.inn || '',
                kpp: client?.kpp || '',
                ogrn: client?.ogrn || '',
                legal_address: client?.legal_address || '',
                postal_address: client?.postal_address || '',
                signer_name: client?.signer_name || '',
                signer_position: client?.signer_position || '',
                signer_authority: client?.signer_authority || '',
                bank_name: client?.bank_name || '',
                bik: client?.bik || '',
                checking_account: client?.checking_account || '',
                correspondent_account: client?.correspondent_account || '',
                tagsText: getClientTagsText(client),
                status: client?.status || 'active',
                notes: client?.notes || '',
                custom_fields: client?.custom_fields || {},
            };
            ctx.crmClientModalTab = 'main';
            ctx.crmClientModalOpen = true;
        },

        closeClientModal(ctx) {
            ctx.crmClientModalOpen = false;
        },

        setClientModalTab(ctx, tab = 'main') {
            ctx.crmClientModalTab = tab || 'main';
        },

        openContactModal(ctx) {
            ctx.crmContactForm = { name: '', position: '', email: '', phone: '', is_primary: false };
            ctx.crmContactModalOpen = true;
        },

        closeContactModal(ctx) {
            ctx.crmContactModalOpen = false;
        },

        openDealModal(ctx, deal = null) {
            ctx.crmDealForm = {
                id: deal?.id || null,
                client_id: deal?.client_id || (ctx.crmClientId || ''),
                pipeline_id: deal?.pipeline_id || ctx.crmActivePipelineId || 1,
                stage_id: deal?.stage_id || '',
                title: deal?.title || '',
                amount: Number(deal?.amount || 0),
                currency: deal?.currency || 'RUB',
                probability: Number(deal?.probability || 0),
                expected_close_date: deal?.expected_close_date || '',
                owner_id: deal?.owner_id || '',
                description: deal?.description || '',
            };
            ctx.crmDealTab = 'info';
            ctx.crmDealModalOpen = true;
        },

        closeDealModal(ctx) {
            ctx.crmDealModalOpen = false;
        },

        async setDealModalTab(ctx, tab = 'info') {
            ctx.crmDealTab = tab || 'info';
            if (ctx.crmDealTab === 'substages' && ctx.crmDealForm?.id) {
                await ctx.loadDealSubstages(ctx.crmDealForm.id);
            }
        },

        openPipelineModal(ctx) {
            ctx.crmPipelineForm = { name: '' };
            ctx.crmPipelineModalOpen = true;
        },

        closePipelineModal(ctx) {
            ctx.crmPipelineModalOpen = false;
        },

        openStageModal(ctx, stage = null) {
            ctx.crmStageForm = {
                id: stage?.id || null,
                name: stage?.name || '',
                color: stage?.color || '#3B82F6',
                order: Number(stage?.order || 0),
                is_won: !!Number(stage?.is_won || 0),
                is_lost: !!Number(stage?.is_lost || 0),
            };
            ctx.crmStageModalOpen = true;
        },

        closeStageModal(ctx) {
            ctx.crmStageModalOpen = false;
        },
    };
})();
