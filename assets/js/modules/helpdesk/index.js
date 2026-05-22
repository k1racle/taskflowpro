window.TaskFlowHelpdesk = (function () {
    function getDefaultTicketForm() {
        return {
            client_name: '',
            client_email: '',
            subject: '',
            description: '',
            category_id: '',
            priority: 'medium'
        };
    }

    function syncSelectedTicket(ctx) {
        if (!ctx.selectedTicket?.id) return;
        const freshTicket = (ctx.helpdeskTickets || []).find(ticket => String(ticket.id) === String(ctx.selectedTicket.id));
        if (freshTicket) {
            ctx.selectedTicket = {
                ...ctx.selectedTicket,
                ...freshTicket
            };
        }
    }

    return {
        async ensureLoaded(ctx) {
            if (ctx._helpdeskLoaded) return;
            ctx._helpdeskLoaded = true;
            await Promise.all([
                this.loadTickets(ctx),
                this.loadStatuses(ctx),
                this.loadCategories(ctx),
                this.loadStats(ctx)
            ]);
        },

        async loadTickets(ctx) {
            ctx.helpdeskLoading = true;
            ctx.helpdeskError = '';
            try {
                const params = new URLSearchParams();
                if (ctx.helpdeskFilters.status_id) params.append('status_id', ctx.helpdeskFilters.status_id);
                if (ctx.helpdeskFilters.category_id) params.append('category_id', ctx.helpdeskFilters.category_id);
                if (ctx.helpdeskFilters.priority) params.append('priority', ctx.helpdeskFilters.priority);
                if (ctx.helpdeskSearch) params.append('search', ctx.helpdeskSearch);

                const res = await apiGet(`helpdesk/tickets?${params.toString()}`);
                if (res.success) {
                    ctx.helpdeskTickets = res.data || [];
                    ctx.helpdeskLastLoadedAt = new Date().toISOString();
                    syncSelectedTicket(ctx);
                } else {
                    ctx.helpdeskError = res.error || 'Не удалось загрузить заявки';
                }
            } catch (e) {
                console.warn('loadHelpdeskTickets error', e);
                ctx.helpdeskError = 'Не удалось загрузить заявки';
            } finally {
                ctx.helpdeskLoading = false;
            }
        },

        async loadStatuses(ctx) {
            try {
                const res = await apiGet('helpdesk/statuses');
                if (res.success) {
                    ctx.helpdeskStatuses = res.data || [];
                }
            } catch (e) {
                console.warn('loadHelpdeskStatuses error', e);
            }
        },

        async loadCategories(ctx) {
            try {
                const res = await apiGet('helpdesk/categories');
                if (res.success) {
                    ctx.helpdeskCategories = res.data || [];
                }
            } catch (e) {
                console.warn('loadHelpdeskCategories error', e);
            }
        },

        async loadStats(ctx) {
            try {
                const res = await apiGet('helpdesk/stats');
                if (res.success) {
                    ctx.helpdeskStats = res.data;
                }
            } catch (e) {
                console.warn('loadHelpdeskStats error', e);
            }
        },

        getStatByStatus(ctx, statusName) {
            if (!ctx.helpdeskStats?.by_status) return 0;
            const stat = ctx.helpdeskStats.by_status.find(s => s.name === statusName);
            return stat ? stat.count : 0;
        },

        syncSelectedTicket(ctx) {
            syncSelectedTicket(ctx);
        },

        getLastUpdateLabel(ctx) {
            if (!ctx.helpdeskLastLoadedAt) return 'Ещё не обновлялось';
            return 'Обновлено ' + ctx.formatDateTime(ctx.helpdeskLastLoadedAt);
        },

        openCreateTicketModal(ctx) {
            ctx.newTicketForm = getDefaultTicketForm();
            ctx.createTicketModalOpen = true;
        },

        async createTicket(ctx) {
            if (ctx.helpdeskSubmitting) return;
            try {
                if (!ctx.newTicketForm.client_name || !ctx.newTicketForm.subject || !ctx.newTicketForm.description) {
                    ctx.showToast('Заполните обязательные поля', 'error');
                    return;
                }

                ctx.helpdeskSubmitting = true;
                const res = await apiPost('helpdesk/tickets', ctx.newTicketForm);
                if (res.success) {
                    const ticketNumber = res.data?.ticket_number ? ` ${res.data.ticket_number}` : '';
                    ctx.showToast(`Заявка${ticketNumber} создана`, 'success');
                    ctx.createTicketModalOpen = false;
                    ctx.newTicketForm = getDefaultTicketForm();
                    await this.loadTickets(ctx);
                    await this.loadStats(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка создания', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка создания заявки', 'error');
            } finally {
                ctx.helpdeskSubmitting = false;
            }
        },

        async openTicketDetail(ctx, ticket) {
            if (!ticket?.id) return;
            ctx.selectedTicket = { ...ticket };
            ctx.ticketDetailModalOpen = true;
            ctx.showCommentForm = false;
            ctx.newComment = '';
            ctx.commentIsInternal = false;
            ctx.helpdeskTicketCalls = [];

            await Promise.all([
                this.loadTicketComments(ctx, ticket.id),
                this.loadTicketHistory(ctx, ticket.id),
                ctx.helpdeskLoadTicketCalls?.(ticket.id)
            ]);
        },

        async loadTicketComments(ctx, ticketId) {
            try {
                const res = await apiGet(`helpdesk/tickets/${ticketId}/comments`);
                if (res.success) {
                    ctx.ticketComments = res.data || [];
                }
            } catch (e) {
                console.warn('loadTicketComments error', e);
            }
        },

        async loadTicketHistory(ctx, ticketId) {
            try {
                const res = await apiGet(`helpdesk/tickets/${ticketId}/history`);
                if (res.success) {
                    ctx.ticketHistory = res.data || [];
                }
            } catch (e) {
                console.warn('loadTicketHistory error', e);
            }
        },

        formatHistoryAction(_ctx, h) {
            const actions = {
                created: 'Заявка создана',
                updated: 'Обновлено: ' + (h.field_name || ''),
                status_changed: 'Статус изменён',
                assigned: 'Назначен ответственный',
                comment: 'Добавлен комментарий',
                resolved: 'Заявка завершена',
                converted: 'Конвертирована в ' + (h.meta ? JSON.parse(h.meta).type : '')
            };
            return actions[h.action] || h.action;
        },

        async addComment(ctx) {
            if (!ctx.newComment.trim()) {
                ctx.showToast('Введите текст комментария', 'error');
                return;
            }

            try {
                const res = await apiPost(`helpdesk/tickets/${ctx.selectedTicket.id}/comments`, {
                    message: ctx.newComment,
                    is_internal: ctx.commentIsInternal ? 1 : 0
                });

                if (res.success) {
                    ctx.showToast('Комментарий добавлен', 'success');
                    ctx.newComment = '';
                    ctx.commentIsInternal = false;
                    ctx.showCommentForm = false;
                    await this.loadTicketComments(ctx, ctx.selectedTicket.id);
                    await this.loadTicketHistory(ctx, ctx.selectedTicket.id);
                    await this.loadTickets(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка добавления комментария', 'error');
            }
        },

        async changeTicketStatus(ctx) {
            try {
                const res = await apiPost(`helpdesk/tickets/${ctx.selectedTicket.id}/status`, {
                    status_id: ctx.selectedTicket.status_id
                });

                if (res.success) {
                    ctx.showToast('Статус изменён', 'success');
                    await this.loadTickets(ctx);
                    await this.loadTicketHistory(ctx, ctx.selectedTicket.id);
                    await this.loadStats(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка изменения статуса', 'error');
            }
        },

        async assignTicket(ctx) {
            try {
                const res = await apiPost(`helpdesk/tickets/${ctx.selectedTicket.id}/assign`, {
                    assigned_to: ctx.selectedTicket.assigned_to || null,
                    assigned_department_id: ctx.selectedTicket.assigned_department_id || null
                });

                if (res.success) {
                    ctx.showToast('Ответственный назначен', 'success');
                    await this.loadTickets(ctx);
                    await this.loadTicketHistory(ctx, ctx.selectedTicket.id);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка назначения ответственного', 'error');
            }
        },

        openResolveModal(ctx) {
            ctx.resolveStatus = ctx.helpdeskStatuses.find(s => s.name === 'Решена')?.id || '';
            ctx.resolutionText = '';
            ctx.resolveModalOpen = true;
        },

        async resolveTicket(ctx) {
            try {
                const res = await apiPost(`helpdesk/tickets/${ctx.selectedTicket.id}/resolve`, {
                    resolution: ctx.resolutionText,
                    status_id: ctx.resolveStatus
                });

                if (res.success) {
                    ctx.showToast('Заявка завершена', 'success');
                    ctx.resolveModalOpen = false;
                    ctx.ticketDetailModalOpen = false;
                    await this.loadTickets(ctx);
                    await this.loadStats(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка завершения заявки', 'error');
            }
        },

        openConvertModal(ctx) {
            ctx.convertModalOpen = true;
        },

        async convertTicket(ctx, type) {
            try {
                const res = await apiPost(`helpdesk/tickets/${ctx.selectedTicket.id}/convert`, { type });

                if (res.success) {
                    ctx.showToast('Заявка конвертирована в ' + type, 'success');
                    ctx.convertModalOpen = false;
                    ctx.ticketDetailModalOpen = false;
                    await this.loadTickets(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка конвертации', 'error');
            }
        },

        isOverdue(_ctx, dateStr) {
            if (!dateStr) return false;
            return new Date(dateStr) < new Date();
        }
    };
})();
