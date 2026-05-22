window.TaskFlowCrmClientCard = (function () {
    function getEmptyClientSales() {
        return {
            summary: {
                total_sales: 0,
                months_count: 0,
                last_sale_month: null,
                first_sale_month: null,
                average_monthly_sales: 0,
            },
            history: [],
        };
    }

    function getEmptyClientReferrals() {
        return {
            code: null,
            link: null,
            link_ready: false,
            stats: {
                orders_count: 0,
                orders_total: 0,
                visits_count: 0,
                last_order_at: null,
            },
            orders: [],
            recent_orders: [],
        };
    }

    return {
        getEmptyClientSales,
        getEmptyClientReferrals,

        async loadClientSales(ctx, clientId = null) {
            const targetClientId = clientId || ctx.crmClientId;
            if (!targetClientId) return;
            try {
                const res = await apiCrmClientSales(targetClientId);
                if (res.success) ctx.crmClientSales = res.data || getEmptyClientSales();
            } catch (e) {
                console.warn('CRM client sales error', e);
            }
        },

        monthLabel(value) {
            if (!value) return '—';
            const date = new Date(`${value}T00:00:00`);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' });
        },

        async ensureReferralCode(ctx, clientId = null, forceRegenerate = false) {
            const targetClientId = clientId || ctx.crmClientId;
            if (!targetClientId) return null;
            try {
                const res = await apiCrmEnsureReferralCode(targetClientId, forceRegenerate);
                if (res.success) {
                    ctx.crmClientReferrals = {
                        ...(ctx.crmClientReferrals || getEmptyClientReferrals()),
                        code: res.data?.code || null,
                        link: res.data?.link || null,
                        link_ready: !!res.data?.link_ready,
                    };
                    if (ctx.crmClient?.client) {
                        ctx.crmClient.client.referral_code = res.data?.code || null;
                    }
                    await this.loadClientReferrals(ctx, targetClientId);
                    try { await ctx.crmLoadClients?.(); } catch (_) {}
                }
                return res;
            } catch (e) {
                console.warn('CRM referral code error', e);
                throw e;
            }
        },

        async loadClientReferrals(ctx, clientId = null) {
            const targetClientId = clientId || ctx.crmClientId;
            if (!targetClientId) return;
            try {
                const res = await apiCrmClientReferrals(targetClientId);
                if (res.success) {
                    ctx.crmClientReferrals = {
                        ...getEmptyClientReferrals(),
                        ...(res.data || {}),
                        orders: Array.isArray(res.data?.orders)
                            ? res.data.orders
                            : (Array.isArray(res.data?.recent_orders) ? res.data.recent_orders : []),
                        recent_orders: Array.isArray(res.data?.recent_orders)
                            ? res.data.recent_orders
                            : (Array.isArray(res.data?.orders) ? res.data.orders.slice(0, 5) : []),
                    };
                }
            } catch (e) {
                console.warn('CRM client referrals error', e);
            }
        },

        async copyReferralText(ctx, text) {
            if (!text) {
                ctx.showToast('Нечего копировать', 'error');
                return;
            }
            try {
                await navigator.clipboard.writeText(String(text));
                ctx.showToast('Скопировано', 'success');
            } catch (e) {
                console.warn('Clipboard error', e);
                ctx.showToast('Не удалось скопировать', 'error');
            }
        },

        async openClient(ctx, clientId, client = null) {
            ctx.crmClientId = clientId;
            ctx.crmClientTab = 'info';
            ctx.crmClient = null;
            ctx.crmClientTasks = [];
            ctx.crmClientActivity = [];
            ctx.crmClientSales = getEmptyClientSales();
            ctx.crmClientReferrals = getEmptyClientReferrals();
            try {
                const res = await apiGet(`crm/clients/${clientId}`);
                if (res.success) {
                    ctx.crmClient = res.data;
                    ctx.crmClientSales = res.data?.sales || getEmptyClientSales();
                    ctx.crmClientReferrals = {
                        ...getEmptyClientReferrals(),
                        ...(res.data?.referrals || {}),
                        orders: Array.isArray(res.data?.referrals?.orders)
                            ? res.data.referrals.orders
                            : (Array.isArray(res.data?.referrals?.recent_orders) ? res.data.referrals.recent_orders : []),
                        recent_orders: Array.isArray(res.data?.referrals?.recent_orders)
                            ? res.data.referrals.recent_orders
                            : (Array.isArray(res.data?.referrals?.orders) ? res.data.referrals.orders.slice(0, 5) : []),
                    };
                }
            } catch (e) {
                console.warn('CRM client load error', e);
            }
        },

        async openClientDrawer(ctx, clientId, client = null) {
            await this.openClient(ctx, clientId, client);
            const targetClient = ctx.crmClient?.client || client || null;
            if (targetClient) {
                ctx.crmClientDetailOpen = true;
                ctx.crmClientModalOpen = false;
                ctx.crmClientModalTab = 'main';
            }
        },

        async openClientDetail(ctx, clientId, client = null) {
            await this.openClientDrawer(ctx, clientId, client);
        },

        closeClient(ctx) {
            ctx.crmClientId = null;
            ctx.crmClient = null;
            ctx.crmClientTab = 'info';
            ctx.crmClientTasks = [];
            ctx.crmClientActivity = [];
            ctx.crmClientSales = getEmptyClientSales();
            ctx.crmClientReferrals = getEmptyClientReferrals();
            ctx.crmClientDetailOpen = false;
        },

        async loadClientTasks(ctx) {
            if (!ctx.crmClientId) return;
            try {
                const res = await apiGet(`crm/clients/${ctx.crmClientId}/tasks`);
                if (res.success) ctx.crmClientTasks = res.data || [];
            } catch (e) {
                console.warn('CRM client tasks error', e);
            }
        },

        async loadClientActivity(ctx) {
            if (!ctx.crmClientId) return;
            try {
                const res = await apiGet(`crm/clients/${ctx.crmClientId}/activity`);
                if (res.success) ctx.crmClientActivity = res.data || [];
            } catch (e) {
                console.warn('CRM client activity error', e);
            }
        },

        async setClientTab(ctx, tab) {
            ctx.crmClientTab = tab || 'info';
            if (ctx.crmClientTab === 'referrals') {
                await this.loadClientReferrals(ctx);
            }
            if (ctx.crmClientTab === 'sales') {
                await this.loadClientSales(ctx);
            }
            if (ctx.crmClientTab === 'tasks') {
                await this.loadClientTasks(ctx);
            }
            if (ctx.crmClientTab === 'history') {
                await this.loadClientActivity(ctx);
            }
            if (ctx.crmClientTab === 'calls') {
                await ctx.crmLoadClientCalls?.();
            }
        },
    };
})();
