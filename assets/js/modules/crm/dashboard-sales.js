window.TaskFlowCrmDashboardSales = (function () {
    function destroySalesChart(ctx) {
        try {
            if (ctx.crmSalesChart && typeof ctx.crmSalesChart.destroy === 'function') {
                ctx.crmSalesChart.destroy();
            }
        } catch (_) {
        } finally {
            ctx.crmSalesChart = null;
        }
    }

    function formatCompactNumber(value) {
        const n = Number(value || 0);
        try {
            return new Intl.NumberFormat('ru-RU', {
                notation: 'compact',
                maximumFractionDigits: n >= 100 ? 0 : 1,
            }).format(n);
        } catch (_) {
            return String(Math.round(n));
        }
    }

    function getSalesChartMetricOptions(ctx) {
        const metric = ctx.crmSalesChartMetric === 'clients' ? 'clients' : 'amount';
        const isAmount = metric === 'amount';
        return {
            metric,
            datasetLabel: isAmount ? 'Сумма продаж' : 'Количество клиентов',
            values: (ctx.crmSalesAnalytics?.monthly_totals || []).map(item => isAmount
                ? Number(item.amount || 0)
                : Number(item.clients_count || 0)),
            formatTick: (value) => isAmount
                ? ctx.crmFormatMoney(value)
                : formatCompactNumber(value),
            formatValue: (value) => isAmount
                ? ctx.crmFormatMoney(value)
                : `${Math.round(Number(value || 0))}`,
            tooltipTitle: isAmount ? 'Сумма продаж' : 'Количество клиентов',
        };
    }

    return {
        async loadDashboard(ctx) {
            try {
                const res = await apiGet('crm/dashboard');
                if (res.success) ctx.crmDashboard = res.data;
            } catch (e) {
                console.warn('CRM dashboard error', e);
            }
        },

        async loadSalesAnalytics(ctx) {
            try {
                const params = {};
                if (ctx.crmSalesFilters.client_id) params.client_id = String(ctx.crmSalesFilters.client_id);
                if (ctx.crmSalesFilters.q) params.q = ctx.crmSalesFilters.q;
                if (ctx.crmSalesFilters.from_month) params.from_month = ctx.crmSalesFilters.from_month;
                if (ctx.crmSalesFilters.to_month) params.to_month = ctx.crmSalesFilters.to_month;
                const res = await apiCrmSales(params);
                if (res.success) {
                    ctx.crmSalesAnalytics = res.data || ctx.crmSalesAnalytics;
                    if (!ctx.crmSalesFilters.from_month && res.data?.period?.from_month) ctx.crmSalesFilters.from_month = res.data.period.from_month;
                    if (!ctx.crmSalesFilters.to_month && res.data?.period?.to_month) ctx.crmSalesFilters.to_month = res.data.period.to_month;
                    ctx.$nextTick(() => this.renderSalesChart(ctx));
                }
            } catch (e) {
                destroySalesChart(ctx);
                console.warn('CRM sales analytics error', e);
            }
        },

        destroySalesChart,

        formatCompactNumber,

        getSalesChartMetricOptions,

        setSalesChartMetric(ctx, metric) {
            const normalized = metric === 'clients' ? 'clients' : 'amount';
            if (ctx.crmSalesChartMetric === normalized) return;
            ctx.crmSalesChartMetric = normalized;
            ctx.$nextTick(() => this.renderSalesChart(ctx));
        },

        renderSalesChart(ctx) {
            const items = ctx.crmSalesAnalytics?.monthly_totals || [];
            const canvas = ctx.$refs?.crmSalesChartCanvas;
            if (!canvas || typeof window.Chart === 'undefined') return;

            if (!items.length) {
                destroySalesChart(ctx);
                return;
            }

            const style = getComputedStyle(document.documentElement);
            const primaryText = (style.getPropertyValue('--lg-text-primary') || '#E5EEF8').trim();
            const secondaryText = (style.getPropertyValue('--lg-text-secondary') || '#94A3B8').trim();
            const borderColor = (style.getPropertyValue('--lg-border') || 'rgba(148, 163, 184, 0.22)').trim();
            const primaryColor = (style.getPropertyValue('--lg-primary') || '#60A5FA').trim();
            const metricOptions = getSalesChartMetricOptions(ctx);
            const dataLabelPlugin = {
                id: 'crmSalesValueLabels',
                afterDatasetsDraw: (chart) => {
                    const { ctx: chartContext } = chart;
                    const dataset = chart.data?.datasets?.[0];
                    const meta = chart.getDatasetMeta(0);
                    if (!dataset || !meta || meta.hidden) return;

                    chartContext.save();
                    chartContext.fillStyle = primaryText;
                    chartContext.font = '600 11px Inter, system-ui, sans-serif';
                    chartContext.textAlign = 'center';
                    chartContext.textBaseline = 'bottom';

                    meta.data.forEach((bar, index) => {
                        const rawValue = dataset.data?.[index];
                        const numericValue = Number(rawValue || 0);
                        if (!Number.isFinite(numericValue)) return;

                        const label = metricOptions.formatValue(numericValue);
                        const x = bar.x;
                        const y = Math.max(bar.y - 8, chart.chartArea.top + 14);
                        chartContext.fillText(label, x, y);
                    });

                    chartContext.restore();
                },
            };

            destroySalesChart(ctx);

            ctx.crmSalesChart = new window.Chart(canvas, {
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
                        categoryPercentage: 0.9,
                        barPercentage: 0.82,
                        maxBarThickness: 64,
                    }],
                },
                plugins: [dataLabelPlugin],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    layout: {
                        padding: {
                            top: 28,
                            right: 4,
                            left: 4,
                            bottom: 0,
                        },
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
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
                                        `Клиентов: ${item.clients_count || 0}`,
                                    ];
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false,
                            },
                            ticks: {
                                color: secondaryText,
                                maxRotation: 0,
                                autoSkip: false,
                                padding: 8,
                                font: {
                                    size: 11,
                                },
                            },
                            border: {
                                display: false,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: borderColor,
                                drawBorder: false,
                            },
                            ticks: {
                                color: secondaryText,
                                padding: 8,
                                maxTicksLimit: 6,
                                callback: (value) => metricOptions.formatTick(value),
                                font: {
                                    size: 11,
                                },
                            },
                            border: {
                                display: false,
                            },
                        },
                    },
                },
            });
        },
    };
})();
