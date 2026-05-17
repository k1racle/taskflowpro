window.TaskFlowCrmMutations = (function () {
    function buildClientPayload(ctx) {
        const tags = String(ctx.crmClientForm.tagsText || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);

        return {
            name: ctx.crmClientForm.name,
            type: ctx.crmClientForm.type,
            email: ctx.crmClientForm.email,
            phone: ctx.crmClientForm.phone,
            site: ctx.crmClientForm.site,
            address: ctx.crmClientForm.address,
            legal_name_full: ctx.crmClientForm.legal_name_full,
            legal_name_short: ctx.crmClientForm.legal_name_short,
            inn: ctx.crmClientForm.inn,
            kpp: ctx.crmClientForm.kpp,
            ogrn: ctx.crmClientForm.ogrn,
            legal_address: ctx.crmClientForm.legal_address,
            postal_address: ctx.crmClientForm.postal_address,
            signer_name: ctx.crmClientForm.signer_name,
            signer_position: ctx.crmClientForm.signer_position,
            signer_authority: ctx.crmClientForm.signer_authority,
            bank_name: ctx.crmClientForm.bank_name,
            bik: ctx.crmClientForm.bik,
            checking_account: ctx.crmClientForm.checking_account,
            correspondent_account: ctx.crmClientForm.correspondent_account,
            tags,
            status: ctx.crmClientForm.status,
            notes: ctx.crmClientForm.notes,
            custom_fields: ctx.crmClientForm.custom_fields || {},
        };
    }

    function buildDealPayload(ctx) {
        const payload = { ...ctx.crmDealForm };
        payload.amount = Number(payload.amount || 0);
        payload.probability = Number(payload.probability || 0);
        payload.client_id = payload.client_id ? Number(payload.client_id) : '';
        payload.pipeline_id = payload.pipeline_id ? Number(payload.pipeline_id) : (ctx.crmActivePipelineId || 1);
        payload.stage_id = payload.stage_id ? Number(payload.stage_id) : '';
        return payload;
    }

    function buildStagePayload(ctx) {
        return {
            name: ctx.crmStageForm.name,
            color: ctx.crmStageForm.color,
            order: ctx.crmStageForm.order,
            is_won: ctx.crmStageForm.is_won,
            is_lost: ctx.crmStageForm.is_lost,
        };
    }

    function canManageAdminTools(ctx) {
        try {
            return !!ctx.can?.('admin.full');
        } catch (_) {
            return false;
        }
    }

    return {
        async saveClient(ctx) {
            const payload = buildClientPayload(ctx);
            try {
                if (ctx.crmClientForm.id) {
                    await apiPut(`crm/clients/${ctx.crmClientForm.id}`, payload);
                    ctx.showToast('Клиент обновлён', 'success');
                    await ctx.crmLoadClients();
                    await ctx.crmOpenClient(ctx.crmClientForm.id);
                } else {
                    const res = await apiPost('crm/clients', payload);
                    ctx.showToast('Клиент создан', 'success');
                    await ctx.crmLoadClients();
                    if (res?.data?.id) await ctx.crmOpenClient(res.data.id);
                }
                ctx.crmClientModalOpen = false;
            } catch (e) {
                console.error(e);
                ctx.showToast('Ошибка CRM: ' + (e.message || 'не удалось сохранить'), 'error');
            }
        },

        async deleteClient(ctx, clientId) {
            if (!clientId) return;

            ctx.openConfirm(
                'Удалить клиента?',
                'Удалятся контакты и сделки. Это действие необратимо.',
                async () => {
                    await apiDelete(`crm/clients/${clientId}`);
                    ctx.showToast('Клиент удалён', 'success');
                    if (typeof ctx.crmCloseClientDetail === 'function') {
                        ctx.crmCloseClientDetail();
                    } else {
                        ctx.crmClientId = null;
                        ctx.crmClient = null;
                    }
                    await ctx.crmLoadClients();
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
        },

        async saveContact(ctx) {
            if (!ctx.crmClientId) return;
            try {
                const payload = { ...ctx.crmContactForm };
                await apiPost(`crm/clients/${ctx.crmClientId}/contacts`, payload);
                ctx.showToast('Контакт добавлен', 'success');
                const res = await apiGet(`crm/clients/${ctx.crmClientId}`);
                if (res.success) ctx.crmClient = res.data;
                ctx.crmContactModalOpen = false;
            } catch (e) {
                ctx.showToast('Ошибка: ' + (e.message || 'не удалось добавить контакт'), 'error');
            }
        },

        async onDealDrop(ctx, stageId) {
            const deal = ctx.crmDraggingDeal;
            ctx.crmDraggingDeal = null;
            if (!deal || !deal.id) return;
            if (String(deal.stage_id) === String(stageId)) return;
            try {
                await apiPatch(`crm/deals/${deal.id}/move`, { stage_id: stageId });
                deal.stage_id = stageId;
                ctx.showToast('Сделка перемещена', 'success');
            } catch (e) {
                ctx.showToast('Ошибка перемещения: ' + (e.message || ''), 'error');
            }
        },

        async saveDeal(ctx) {
            const payload = buildDealPayload(ctx);
            try {
                if (payload.id) {
                    await apiPut(`crm/deals/${payload.id}`, payload);
                    ctx.showToast('Сделка обновлена', 'success');
                } else {
                    await apiPost('crm/deals', payload);
                    ctx.showToast('Сделка создана', 'success');
                }
                ctx.crmDealModalOpen = false;
                await ctx.crmLoadFunnels();
                if (ctx.crmClientId) await ctx.crmOpenClient(ctx.crmClientId);
            } catch (e) {
                ctx.showToast('Ошибка: ' + (e.message || 'не удалось сохранить'), 'error');
            }
        },

        async savePipeline(ctx) {
            try {
                await apiPost('crm/pipelines', { name: ctx.crmPipelineForm.name });
                ctx.showToast('Воронка создана', 'success');
                ctx.crmPipelineModalOpen = false;
                await ctx.crmLoadFunnels();
            } catch (e) {
                ctx.showToast('Ошибка: ' + (e.message || ''), 'error');
            }
        },

        async deletePipeline(ctx, pipelineId) {
            if (!pipelineId) return;
            ctx.openConfirm(
                'Удалить воронку?',
                'Все этапы этой воронки будут удалены. Сделки останутся.',
                async () => {
                    try {
                        const res = await apiDelete(`crm/pipelines/${pipelineId}`);
                        if (res.success) {
                            ctx.showToast('Воронка удалена', 'success');
                            ctx.crmActivePipelineId = null;
                            await ctx.crmLoadFunnels();
                        } else {
                            ctx.showToast(res.error || 'Ошибка удаления', 'error');
                        }
                    } catch (e) {
                        ctx.showToast('Ошибка: ' + (e.message || ''), 'error');
                    }
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
        },

        async saveStage(ctx) {
            const pipelineId = ctx.crmActivePipelineId || 1;
            const payload = buildStagePayload(ctx);
            try {
                if (ctx.crmStageForm.id) {
                    await apiPut(`crm/stages/${ctx.crmStageForm.id}`, payload);
                    ctx.showToast('Этап обновлён', 'success');
                } else {
                    await apiPost(`crm/pipelines/${pipelineId}/stages`, payload);
                    ctx.showToast('Этап добавлен', 'success');
                }
                ctx.crmStageModalOpen = false;
                await ctx.crmLoadFunnels();
                await ctx.crmLoadStageManager();
            } catch (e) {
                ctx.showToast('Ошибка: ' + (e.message || ''), 'error');
            }
        },

        canManageAdminTools,

        async runAdminTool(ctx, operation, mode = 'dry-run') {
            if (!canManageAdminTools(ctx)) {
                ctx.showToast('Недостаточно прав для запуска админ-инструментов CRM', 'error');
                return;
            }

            const payload = {
                operation,
                mode,
            };

            if (operation === 'import_sales') {
                payload.file = (ctx.crmAdminToolsForm.file || '').trim();
                payload.sheet = (ctx.crmAdminToolsForm.sheet || '').trim() || 'База клиентов';
                payload.clients_sheet = (ctx.crmAdminToolsForm.clients_sheet || '').trim() || 'Работа с АКБ';
            }

            if (operation === 'diagnose_duplicates' || operation === 'merge_duplicates') {
                if (ctx.crmAdminToolsForm.client_id) payload.client_id = Number(ctx.crmAdminToolsForm.client_id);
            }

            if (operation === 'merge_duplicates') {
                if (ctx.crmAdminToolsForm.primary_id) payload.primary_id = Number(ctx.crmAdminToolsForm.primary_id);
                if (ctx.crmAdminToolsForm.group_index !== '') payload.group_index = Number(ctx.crmAdminToolsForm.group_index);
                payload.all = !!ctx.crmAdminToolsForm.all;
            }

            ctx.crmAdminToolsState.loading = true;
            try {
                const res = await apiCrmAdminTools(payload);
                ctx.crmAdminToolsState.lastResult = res;
                if (res?.success) {
                    ctx.showToast(`CRM tool: ${operation} (${mode}) выполнен`, 'success');
                    if (operation === 'import_sales' && mode === 'apply') {
                        await ctx.crmLoadSalesAnalytics();
                        try { await ctx.crmLoadClients?.(); } catch (_) {}
                    }
                    if (operation === 'merge_duplicates' && mode === 'apply') {
                        try { await ctx.crmLoadClients?.(); } catch (_) {}
                        await ctx.crmLoadSalesAnalytics();
                    }
                } else {
                    ctx.showToast(res?.error || 'Операция завершилась с ошибкой', 'error');
                }
            } catch (e) {
                console.error('CRM admin tool error', e);
                ctx.crmAdminToolsState.lastResult = {
                    success: false,
                    error: e?.message || 'Ошибка выполнения',
                };
                ctx.showToast('Ошибка CRM инструмента: ' + (e?.message || 'неизвестная ошибка'), 'error');
            } finally {
                ctx.crmAdminToolsState.loading = false;
            }
        },

        async loadDiagnosticsBaseline(ctx, force = false) {
            if (!ctx.can?.('admin.full')) {
                return;
            }

            if (ctx.diagnosticsBaseline.loading) {
                return;
            }

            if (ctx.diagnosticsBaseline.loaded && !force) {
                return;
            }

            ctx.diagnosticsBaseline.loading = true;
            ctx.diagnosticsBaseline.error = '';

            try {
                const res = await apiGetSettingsDiagnostics();
                if (res?.success) {
                    ctx.diagnosticsBaseline.data = res.data || null;
                    ctx.diagnosticsBaseline.loaded = true;
                } else {
                    ctx.diagnosticsBaseline.error = res?.error || 'Не удалось загрузить диагностику';
                }
            } catch (e) {
                console.error('Diagnostics baseline error', e);
                ctx.diagnosticsBaseline.error = e?.message || 'Не удалось загрузить диагностику';
            } finally {
                ctx.diagnosticsBaseline.loading = false;
            }
        },

        async loadDealSubstages(ctx, dealId) {
            if (!dealId) return;
            try {
                const data = await apiGet(`crm/deals/${dealId}/substages`);
                if (data.success) {
                    ctx.crmDealSubstages = Array.isArray(data.data) ? data.data : [];
                } else {
                    ctx.crmDealSubstages = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки подэтапов сделки:', error);
                ctx.crmDealSubstages = [];
            }
        },

        async addDealSubstage(ctx, dealId, name) {
            if (!dealId || !name?.trim()) return;
            try {
                const data = await apiPost(`crm/deals/${dealId}/substages`, { name: name.trim() });
                if (data.success) {
                    await this.loadDealSubstages(ctx, dealId);
                    ctx.newDealSubstageName = '';
                    ctx.showToast('Подэтап добавлен', 'success');
                }
            } catch (error) {
                ctx.showToast('Ошибка: ' + (error.message || 'Не удалось добавить подэтап'), 'error');
            }
        },

        async toggleDealSubstage(ctx, dealId, substageId, isCompleted, name) {
            try {
                await apiPut(`crm/deals/${dealId}/substages/${substageId}`, {
                    is_completed: !isCompleted,
                    name: name
                });
                await this.loadDealSubstages(ctx, dealId);
            } catch (error) {
                ctx.showToast('Ошибка: ' + (error.message || 'Не удалось обновить подэтап'), 'error');
            }
        },

        async deleteDealSubstage(ctx, dealId, substageId) {
            ctx.openConfirm(
                'Удалить подэтап?',
                'Это действие нельзя отменить',
                async () => {
                    try {
                        const data = await apiDelete(`crm/deals/${dealId}/substages/${substageId}`);
                        if (data.success) {
                            await this.loadDealSubstages(ctx, dealId);
                            ctx.showToast('Подэтап удалён', 'success');
                        }
                    } catch (error) {
                        ctx.showToast('Ошибка: ' + (error.message || 'Не удалось удалить подэтап'), 'error');
                    }
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
        },

        createLinkedTask(ctx, { client_id = '', deal_id = '' } = {}) {
            ctx.openTaskModal({ client_id, deal_id });
        },

        exportData(_ctx, type) {
            const token = getToken();
            const encodedToken = token ? encodeURIComponent(token) : '';
            const url = `api/index.php?endpoint=${encodeURIComponent('crm/export')}&type=${encodeURIComponent(type)}&_t=${Date.now()}${token ? `&token=${encodedToken}` : ''}`;
            window.open(url, '_blank');
        },
    };
})();
