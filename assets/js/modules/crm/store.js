window.TaskFlowCrmStore = (function () {
    function destroyStoreChart(ctx) {
        try {
            if (ctx.crmStoreChart && typeof ctx.crmStoreChart.destroy === 'function') {
                ctx.crmStoreChart.destroy();
            }
        } catch (_) {
        } finally {
            ctx.crmStoreChart = null;
        }
    }

    function comparisonTone(delta) {
        const value = Number(delta || 0);
        if (value > 0) return 'color:#22c55e';
        if (value < 0) return 'color:#ef4444';
        return 'color:var(--lg-text-tertiary)';
    }

    function comparisonLabel(delta, percent) {
        const value = Number(delta || 0);
        const sign = value > 0 ? '+' : '';
        if (percent === null || typeof percent === 'undefined') {
            return `${sign}${Math.round(value)}`;
        }
        return `${sign}${Math.round(value)} (${sign}${percent}%)`;
    }

    function formatSyncTimestamp(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('ru-RU');
    }

    function syncStatusTone(state) {
        const normalized = String(state || 'idle');
        if (normalized === 'healthy') return 'color:#22c55e';
        if (normalized === 'failed' || normalized === 'not_configured') return 'color:#ef4444';
        return 'color:var(--lg-text-tertiary)';
    }

    function syncAttemptLabel(trigger) {
        const normalized = String(trigger || 'manual');
        if (normalized === 'retry') return 'Повтор';
        if (normalized === 'resync') return 'Ресинхронизация';
        return 'Синхронизация';
    }

    function normalizeImportTrigger(trigger) {
        const normalized = String(trigger || '').trim().toLowerCase();
        return normalized === 'retry' || normalized === 'resync' ? normalized : 'manual';
    }

    function getMetricOptions(ctx) {
        const metric = ctx.crmStoreChartMetric === 'orders' ? 'orders' : 'amount';
        const isAmount = metric === 'amount';
        return {
            metric,
            values: (ctx.crmStoreAnalytics?.monthly_totals || []).map((item) => isAmount
                ? Number(item.amount || 0)
                : Number(item.orders_count || 0)),
            datasetLabel: isAmount ? 'Оборот магазина' : 'Заказы',
            formatTick: (value) => isAmount ? ctx.crmFormatMoney(value) : `${Math.round(Number(value || 0))}`,
            formatValue: (value) => isAmount ? ctx.crmFormatMoney(value) : `${Math.round(Number(value || 0))}`,
            tooltipTitle: isAmount ? 'Оборот' : 'Заказы',
        };
    }

    return {
        async loadStoreAnalytics(ctx) {
            try {
                const params = {};
                if (ctx.crmStoreFilters.status) params.status = ctx.crmStoreFilters.status;
                if (ctx.crmStoreFilters.q) params.q = ctx.crmStoreFilters.q;
                if (ctx.crmStoreFilters.from_month) params.from_month = ctx.crmStoreFilters.from_month;
                if (ctx.crmStoreFilters.to_month) params.to_month = ctx.crmStoreFilters.to_month;
                const res = await apiCrmStore(params);
                if (res.success) {
                    ctx.crmStoreAnalytics = res.data || ctx.crmStoreAnalytics;
                    if (res.data?.sync_status) {
                        ctx.crmStoreSyncStatus = res.data.sync_status;
                    }
                    if (!ctx.crmStoreFilters.from_month && res.data?.period?.from_month) ctx.crmStoreFilters.from_month = res.data.period.from_month;
                    if (!ctx.crmStoreFilters.to_month && res.data?.period?.to_month) ctx.crmStoreFilters.to_month = res.data.period.to_month;

                    const currentSelection = Number(ctx.crmStoreSelectedOrderId || 0);
                    const orders = Array.isArray(res.data?.orders) ? res.data.orders : [];
                    const stillExists = orders.some((order) => Number(order.id || 0) === currentSelection);
                    if (!stillExists) {
                        ctx.crmStoreSelectedOrderId = orders.length ? Number(orders[0].id || 0) : null;
                    }

                    ctx.$nextTick(() => this.renderStoreChart(ctx));
                }
            } catch (e) {
                destroyStoreChart(ctx);
                console.warn('CRM store analytics error', e);
            }
        },

        async importOrders(ctx, options = {}) {
            const trigger = normalizeImportTrigger(options?.trigger);
            if (ctx.crmStoreImportState?.loading) return;
            ctx.crmStoreImportState = { ...(ctx.crmStoreImportState || {}), loading: true };
            try {
                const res = await apiCrmStoreImport({ per_page: 50, max_pages: 6, trigger });
                if (res.success) {
                    const imported = Number(res.data?.imported || 0);
                    const updated = Number(res.data?.updated || 0);
                    const authFallbackUsed = !!res.data?.auth_fallback_used;
                    const authMode = String(res.data?.auth_mode || 'basic_auth');
                    const syncStatus = res.data?.sync_status || ctx.crmStoreSyncStatus || null;
                    if (syncStatus) {
                        ctx.crmStoreSyncStatus = syncStatus;
                    }
                    ctx.crmStoreImportState = { loading: false, lastResult: res.data || null, syncStatus };
                    if (typeof ctx.showToast === 'function') {
                        const actionLabel = trigger === 'retry' ? 'Повторная синхронизация WooCommerce' : 'WooCommerce синхронизирован';
                        const message = `${actionLabel}: новых ${imported}, обновлено ${updated}` + (authFallbackUsed ? `, auth fallback: ${authMode}` : '');
                        ctx.showToast(message, authFallbackUsed ? 'warning' : 'success');
                    }
                    await this.loadStoreAnalytics(ctx);
                    return;
                }

                const syncStatus = res.data?.sync_status || ctx.crmStoreSyncStatus || null;
                if (syncStatus) {
                    ctx.crmStoreSyncStatus = syncStatus;
                }
                ctx.crmStoreImportState = { ...(ctx.crmStoreImportState || {}), loading: false, lastResult: res.data || null, syncStatus };
                if (typeof ctx.showToast === 'function') {
                    ctx.showToast(res.error || 'Не удалось импортировать заказы WooCommerce', 'error');
                }
                return false;
            } catch (e) {
                console.warn('CRM store import error', e);
                const syncStatus = e?.data?.data?.sync_status || e?.data?.sync_status || ctx.crmStoreSyncStatus || null;
                if (syncStatus) {
                    ctx.crmStoreSyncStatus = syncStatus;
                }
                ctx.crmStoreImportState = { ...(ctx.crmStoreImportState || {}), lastResult: { success: false, error: e?.message || 'Ошибка импорта', trigger }, syncStatus };
                if (typeof ctx.showToast === 'function') {
                    ctx.showToast((trigger === 'retry' ? 'Повторная синхронизация' : 'Импорт') + ' заказов WooCommerce: ' + (e?.message || 'ошибка'), 'error');
                }
                return false;
            } finally {
                ctx.crmStoreImportState = { ...(ctx.crmStoreImportState || {}), loading: false };
            }
        },

        destroyStoreChart,

        setStoreChartMetric(ctx, metric) {
            const normalized = metric === 'orders' ? 'orders' : 'amount';
            if (ctx.crmStoreChartMetric === normalized) return;
            ctx.crmStoreChartMetric = normalized;
            ctx.$nextTick(() => this.renderStoreChart(ctx));
        },

        selectOrder(ctx, orderId) {
            ctx.crmStoreSelectedOrderId = Number(orderId || 0) || null;
        },

        formatSyncTimestamp,

        syncStatusTone,

        syncAttemptLabel,

        comparisonTone,

        comparisonLabel,

        renderStoreChart(ctx) {
            const items = ctx.crmStoreAnalytics?.monthly_totals || [];
            const canvas = ctx.$refs?.crmStoreChartCanvas;
            if (!canvas || typeof window.Chart === 'undefined') return;

            if (!items.length) {
                destroyStoreChart(ctx);
                return;
            }

            const style = getComputedStyle(document.documentElement);
            const secondaryText = (style.getPropertyValue('--lg-text-secondary') || '#94A3B8').trim();
            const borderColor = (style.getPropertyValue('--lg-border') || 'rgba(148, 163, 184, 0.22)').trim();
            const primaryColor = (style.getPropertyValue('--lg-primary') || '#60A5FA').trim();
            const metricOptions = getMetricOptions(ctx);

            destroyStoreChart(ctx);
            ctx.crmStoreChart = new window.Chart(canvas, {
                type: 'bar',
                data: {
                    labels: items.map(item => ctx.crmMonthLabel(item.sale_month)),
                    datasets: [{
                        label: metricOptions.datasetLabel,
                        data: metricOptions.values,
                        backgroundColor: 'rgba(96, 165, 250, 0.75)',
                        borderColor: primaryColor,
                        borderWidth: 1,
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 64,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.94)',
                            borderColor,
                            borderWidth: 1,
                            padding: 12,
                            titleColor: '#F8FAFC',
                            bodyColor: '#E2E8F0',
                            callbacks: {
                                label: (context) => {
                                    const item = items[context.dataIndex] || {};
                                    return [
                                        `${metricOptions.tooltipTitle}: ${metricOptions.formatValue(context.raw || 0)}`,
                                        `Заказов: ${item.orders_count || 0}`,
                                    ];
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { color: secondaryText, maxRotation: 0, autoSkip: false, padding: 8, font: { size: 11 } },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: borderColor, drawBorder: false },
                            ticks: { color: secondaryText, padding: 8, maxTicksLimit: 6, callback: (value) => metricOptions.formatTick(value), font: { size: 11 } },
                            border: { display: false },
                        },
                    },
                },
            });
        },
    };
})();
