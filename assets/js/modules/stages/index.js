window.TaskFlowStagesManager = (function () {
    function getTaskStageSummary(count) {
        return `${count} ${count === 1 ? 'этап' : (count >= 2 && count <= 4 ? 'этапа' : 'этапов')} задач`;
    }

    function getSubstageSummary(count) {
        return `${count} ${count === 1 ? 'подэтап' : (count >= 2 && count <= 4 ? 'подэтапа' : 'подэтапов')} в справочнике`;
    }

    function getDealStageSummary(count) {
        return `${count} ${count === 1 ? 'этап' : (count >= 2 && count <= 4 ? 'этапа' : 'этапов')} сделки`;
    }

    return {
        async loadStages(ctx) {
            try {
                const data = await apiGetStages();
                if (data.success) ctx.stages = data.data;
            } catch (error) {
                console.error('Ошибка загрузки этапов:', error);
                throw error;
            }
        },

        async addStage(ctx) {
            if (!String(ctx.newStageName || '').trim()) {
                ctx.showToast('Введите название этапа', 'error');
                return;
            }

            try {
                const result = await apiCreateStage({
                    name: ctx.newStageName,
                    color: ctx.newStageColor
                });
                if (result.success) {
                    ctx.showToast('Этап создан', 'success');
                    ctx.newStageName = '';
                    ctx.newStageColor = '#3B82F6';
                    await ctx.loadStages();
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось создать этап'), 'error');
                }
            } catch (error) {
                console.error('Ошибка создания этапа:', error);
                ctx.showToast('Ошибка создания этапа', 'error');
            }
        },

        async updateStage(ctx, stageId) {
            try {
                const stage = (ctx.stages || []).find(s => s.id === stageId);
                if (!stage) return;

                await apiUpdateStage(stageId, {
                    name: stage.name,
                    color: stage.color
                });
                ctx.showToast('Этап обновлён', 'success');
            } catch (error) {
                console.error('Ошибка обновления этапа:', error);
                ctx.showToast('Ошибка обновления этапа', 'error');
            }
        },

        deleteStage(ctx, stageId) {
            ctx.openConfirm(
                'Удалить этап?',
                'Вы уверены что хотите удалить этот этап? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteStage(stageId);
                        ctx.showToast('Этап удалён', 'success');
                        await ctx.loadStages();
                    } catch (error) {
                        console.error('Ошибка удаления этапа:', error);
                        ctx.showToast('Ошибка удаления этапа', 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        getItems(ctx) {
            if (ctx.stagesManagerType === 'tasks') return Array.isArray(ctx.stages) ? ctx.stages : [];
            if (ctx.stagesManagerType === 'substages') return Array.isArray(ctx.taskSubstages) ? ctx.taskSubstages : [];
            return Array.isArray(ctx.dealStages) ? ctx.dealStages : [];
        },

        getSummary(ctx) {
            const count = this.getItems(ctx).length;
            if (ctx.stagesManagerType === 'tasks') return getTaskStageSummary(count);
            if (ctx.stagesManagerType === 'substages') return getSubstageSummary(count);
            return getDealStageSummary(count);
        },

        getHelpText(ctx) {
            if (ctx.stagesManagerType === 'tasks') {
                return 'Эти этапы используются как колонки канбана задач.';
            }
            if (ctx.stagesManagerType === 'substages') {
                return 'Подэтапы доступны внутри карточек задач как единый справочник.';
            }
            return 'Этапы сделок настраиваются отдельно по выбранной воронке. Ниже — отдельный справочник CRM-подэтапов.';
        },

        markLoaded(ctx) {
            ctx.stagesManagerLastLoadedAt = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        },

        async refresh(ctx, type = ctx.stagesManagerType, opts = {}) {
            const { silent = false } = opts;
            ctx.stagesManagerType = type;
            if (!silent) {
                ctx.stagesManagerLoading = true;
                ctx.stagesManagerError = '';
            }

            try {
                if (type === 'tasks') {
                    await ctx.loadStages();
                } else if (type === 'substages') {
                    await ctx.loadTaskSubstagesDict();
                } else {
                    await this.crmLoad(ctx);
                    await ctx.loadCrmDealSubstagesDict();
                }
                this.markLoaded(ctx);
            } catch (e) {
                ctx.stagesManagerError = e?.message || 'Не удалось загрузить данные экрана этапов';
                throw e;
            } finally {
                if (!silent) {
                    ctx.stagesManagerLoading = false;
                }
            }
        },

        async crmLoad(ctx) {
            try {
                const p = await apiGet('crm/pipelines');
                if (p.success) {
                    ctx.crmPipelines = p.data || [];
                    const availablePipelineIds = new Set((ctx.crmPipelines || []).map((item) => String(item?.id || '')));
                    if (!availablePipelineIds.has(String(ctx.stagesManagerPipelineId || ''))) {
                        ctx.stagesManagerPipelineId = ctx.crmPipelines?.find(x => Number(x.is_default) === 1)?.id || ctx.crmPipelines?.[0]?.id || 1;
                    }
                }
                const pid = ctx.stagesManagerPipelineId || 1;
                const s = await apiGet(`crm/pipelines/${pid}/stages`);
                ctx.dealStages = s.success ? (s.data || []) : [];
            } catch (e) {
                console.warn('CRM stage manager error', e);
                throw e;
            }
        },

        openModal(ctx, stage) {
            ctx.stageManagerEditing = stage;
            const base = stage || {};
            ctx.stageManagerForm = {
                id: base.id || null,
                name: base.name || '',
                color: base.color || '#3B82F6',
                order: Number(base.order || 0),
                is_won: !!Number(base.is_won || 0),
                is_lost: !!Number(base.is_lost || 0)
            };
            ctx.stageManagerModalOpen = true;
        },

        async save(ctx) {
            if (ctx.stagesManagerSaving) return;
            const form = { ...ctx.stageManagerForm };
            if (!String(form.name || '').trim()) {
                ctx.showToast('Введите название этапа', 'error');
                return;
            }

            try {
                ctx.stagesManagerSaving = true;
                if (ctx.stagesManagerType === 'tasks') {
                    if (form.id) {
                        await apiUpdateStage(form.id, { name: form.name, color: form.color, order: form.order });
                        ctx.showToast('Этап задач обновлён', 'success');
                    } else {
                        await apiCreateStage({ name: form.name, color: form.color, order: form.order });
                        ctx.showToast('Этап задач создан', 'success');
                    }
                    await this.refresh(ctx, 'tasks', { silent: true });
                } else {
                    const pid = ctx.stagesManagerPipelineId || 1;
                    if (form.id) {
                        await apiPut(`crm/stages/${form.id}`, {
                            name: form.name,
                            color: form.color,
                            order: Number(form.order || 0),
                            is_won: !!form.is_won,
                            is_lost: !!form.is_lost
                        });
                        ctx.showToast('Этап сделок обновлён', 'success');
                    } else {
                        await apiPost(`crm/pipelines/${pid}/stages`, {
                            name: form.name,
                            color: form.color,
                            order: Number(form.order || 0),
                            is_won: !!form.is_won,
                            is_lost: !!form.is_lost
                        });
                        ctx.showToast('Этап сделок создан', 'success');
                    }
                    await this.refresh(ctx, 'deals', { silent: true });
                    await ctx.crmLoadFunnels();
                }
                ctx.stageManagerModalOpen = false;
            } catch (e) {
                console.error(e);
                ctx.showToast('Ошибка: ' + (e.message || 'не удалось сохранить'), 'error');
            } finally {
                ctx.stagesManagerSaving = false;
            }
        },

        deleteDealStage(ctx, stageId) {
            if (!stageId) return;
            ctx.openConfirm(
                'Удалить этап сделки?',
                'Вы уверены что хотите удалить этап сделки? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDelete(`crm/stages/${stageId}`);
                        ctx.showToast('Этап удалён', 'success');
                        await this.refresh(ctx, 'deals', { silent: true });
                        await ctx.crmLoadFunnels();
                    } catch (e) {
                        ctx.showToast('Ошибка: ' + (e.message || 'не удалось удалить'), 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        async reorderDealStages(ctx) {
            const pid = ctx.stagesManagerPipelineId || 1;
            const items = (ctx.dealStages || []).map((s, idx) => ({ id: s.id, order: idx + 1 }));
            try {
                await apiPatch(`crm/pipelines/${pid}/stages`, { action: 'reorder', items });
                ctx.showToast('Порядок сохранён', 'success');
                await this.refresh(ctx, 'deals', { silent: true });
                await ctx.crmLoadFunnels();
            } catch (e) {
                ctx.showToast('Ошибка: ' + (e.message || ''), 'error');
            }
        },

        openSubstageModal(ctx, substage) {
            ctx.editingSubstage = substage;
            ctx.substageForm = substage ? { ...substage } : { name: '', color: '#6B7280', order: 0 };
            ctx.substageModalOpen = true;
        },

        async saveSubstage(ctx) {
            if (ctx.stagesManagerSaving) return;
            try {
                ctx.stagesManagerSaving = true;
                if (ctx.editingSubstage?.id) {
                    await apiPut(`task-substages/${ctx.editingSubstage.id}`, ctx.substageForm);
                    ctx.showToast('Подэтап обновлён', 'success');
                } else {
                    await apiPost('task-substages', ctx.substageForm);
                    ctx.showToast('Подэтап создан', 'success');
                }
                await this.refresh(ctx, 'substages', { silent: true });
                ctx.substageModalOpen = false;
            } catch (error) {
                ctx.showToast('Ошибка: ' + (error.message || 'Не удалось сохранить подэтап'), 'error');
            } finally {
                ctx.stagesManagerSaving = false;
            }
        },

        deleteTaskSubstage(ctx, id) {
            ctx.openConfirm(
                'Удалить подэтап?',
                'Это действие нельзя отменить',
                async () => {
                    try {
                        await apiDelete(`task-substages/${id}`);
                        await this.refresh(ctx, 'substages', { silent: true });
                        ctx.showToast('Подэтап удалён', 'success');
                    } catch (error) {
                        ctx.showToast('Ошибка: ' + (error.message || 'Не удалось удалить подэтап'), 'error');
                    }
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
        },

        openCrmDealSubstageModal(ctx, substage) {
            ctx.editingCrmDealSubstage = substage;
            ctx.crmDealSubstageForm = substage ? { ...substage } : { name: '', color: '#6B7280', order: 0 };
            ctx.crmDealSubstageModalOpen = true;
        },

        async saveCrmDealSubstage(ctx) {
            if (ctx.stagesManagerSaving) return;
            try {
                ctx.stagesManagerSaving = true;
                if (ctx.editingCrmDealSubstage?.id) {
                    await apiPut(`crm-deal-substages/${ctx.editingCrmDealSubstage.id}`, ctx.crmDealSubstageForm);
                    ctx.showToast('Подэтап CRM обновлён', 'success');
                } else {
                    await apiPost('crm-deal-substages', ctx.crmDealSubstageForm);
                    ctx.showToast('Подэтап CRM создан', 'success');
                }
                await this.refresh(ctx, 'deals', { silent: true });
                ctx.crmDealSubstageModalOpen = false;
            } catch (error) {
                ctx.showToast('Ошибка: ' + (error.message || 'Не удалось сохранить подэтап CRM'), 'error');
            } finally {
                ctx.stagesManagerSaving = false;
            }
        },

        deleteCrmDealSubstage(ctx, id) {
            ctx.openConfirm(
                'Удалить подэтап CRM?',
                'Это действие нельзя отменить',
                async () => {
                    try {
                        await apiDelete(`crm-deal-substages/${id}`);
                        await this.refresh(ctx, 'deals', { silent: true });
                        ctx.showToast('Подэтап CRM удалён', 'success');
                    } catch (error) {
                        ctx.showToast('Ошибка: ' + (error.message || 'Не удалось удалить подэтап CRM'), 'error');
                    }
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
        }
    };
})();
