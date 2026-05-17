window.TaskFlowLeader = (function () {
    function buildAnalyticsParams(ctx) {
        const params = new URLSearchParams();
        params.append('period', ctx.analyticsPeriod);
        params.append('compare', ctx.analyticsCompare);

        if (ctx.analyticsPeriod === 'custom') {
            if (ctx.analyticsCustomFrom) params.append('from', ctx.analyticsCustomFrom);
            if (ctx.analyticsCustomTo) params.append('to', ctx.analyticsCustomTo);
        }

        return params;
    }

    function getChartTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        return {
            gridColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.08)',
            textColor: isDark ? '#e5e7eb' : '#374151'
        };
    }

    function destroyChart(ctx, key) {
        if (ctx.analyticsCharts?.[key]) ctx.analyticsCharts[key].destroy();
    }

    function getCanvas(id) {
        return document.getElementById(id);
    }

    return {
        formatMoney(_ctx, amount) {
            if (!amount) return '—';
            return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(amount);
        },

        async loadLeaderDashboard(ctx) {
            if (!ctx.can('leader.view') && !ctx.isAdmin) return;
            try {
                const res = await apiGet('shifts/overview');
                if (res.success) ctx.leaderOverview = res.data;

                ctx.scheduleFilters.date_from = new Date().toISOString().split('T')[0];
                ctx.scheduleFilters.date_to = new Date().toISOString().split('T')[0];
                await ctx.loadWorkSchedules();
            } catch (e) {
                console.warn('loadLeaderDashboard error', e);
            }
        },

        exportData(_ctx, type) {
            const token = getToken();
            const t = token ? encodeURIComponent(token) : '';
            const url = `api/index.php?endpoint=${encodeURIComponent('shifts/export')}&type=${encodeURIComponent(type)}&_t=${Date.now()}${token ? `&token=${t}` : ''}`;
            window.open(url, '_blank');
        },

        getShiftViolations(ctx) {
            const violations = [];
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const todaySchedules = (ctx.workSchedules || []).filter(s => {
                const scheduleDate = new Date(s.schedule_date);
                scheduleDate.setHours(0, 0, 0, 0);
                return scheduleDate.getTime() === today.getTime() && !s.is_day_off;
            });

            for (const schedule of todaySchedules) {
                const onShift = (ctx.leaderOverview?.on_shift || []).find(s => s.user_id === schedule.user_id);

                if (!onShift) {
                    violations.push({
                        id: schedule.id,
                        user_id: schedule.user_id,
                        user_name: schedule.user_name,
                        schedule_date: schedule.schedule_date,
                        shift_start: schedule.shift_start,
                        shift_end: schedule.shift_end,
                        reason: 'Не вышел на смену'
                    });
                }
            }

            return violations;
        },

        async loadAnalytics(ctx) {
            if (!ctx.can('leader.view') && !ctx.isAdmin) return;
            try {
                const params = buildAnalyticsParams(ctx);
                const query = params.toString();

                const res = await apiGet(`analytics/overview?${query}`);
                if (res.success) ctx.analyticsData = res.data;

                const tasksRes = await apiGet(`analytics/tasks?${query}`);
                if (tasksRes.success) ctx.analyticsTasksData = tasksRes.data;

                const shiftsRes = await apiGet(`analytics/shifts?${query}`);
                if (shiftsRes.success) ctx.analyticsShiftsData = shiftsRes.data;

                const crmRes = await apiGet(`analytics/crm?${query}`);
                if (crmRes.success) ctx.analyticsCRMData = crmRes.data;

                const empRes = await apiGet(`analytics/employees?${query}`);
                if (empRes.success) ctx.analyticsEmployees = empRes.data;

                const projRes = await apiGet(`analytics/tasks-by-project?${query}`);
                if (projRes.success) ctx.analyticsTasksByProject = projRes.data;

                ctx.$nextTick(() => {
                    setTimeout(() => {
                        this.renderAnalyticsCharts(ctx);
                    }, 100);
                });
            } catch (e) {
                console.warn('loadAnalytics error', e);
            }
        },

        renderAnalyticsCharts(ctx) {
            this.renderTasksDailyChart(ctx);
            this.renderTasksStatusChart(ctx);
            this.renderTasksPriorityChart(ctx);
            this.renderShiftsDailyChart(ctx);
            this.renderCRMFunnelChart(ctx);
            this.renderEmployeeEfficiencyChart(ctx);
            this.renderTasksByProjectChart(ctx);
        },

        renderTasksDailyChart(ctx) {
            const canvas = getCanvas('chartTasksDaily');
            if (!canvas) return;
            destroyChart(ctx, 'tasksDaily');

            const daily = ctx.analyticsTasksData?.daily || [];
            const dailyCompare = ctx.analyticsTasksData?.daily_compare || [];
            const labels = [...daily.map(d => d.day), ...dailyCompare.map(d => d.day)];
            const uniqueLabels = [...new Set(labels)].sort();

            const createdData = uniqueLabels.map(day => {
                const found = daily.find(d => d.day === day);
                return found ? parseInt(found.created) : 0;
            });
            const completedData = uniqueLabels.map(day => {
                const found = daily.find(d => d.day === day);
                return found ? parseInt(found.completed) : 0;
            });
            const compareCreatedData = uniqueLabels.map(day => {
                const found = dailyCompare.find(d => d.day === day);
                return found ? parseInt(found.created) : 0;
            });
            const compareCompletedData = uniqueLabels.map(day => {
                const found = dailyCompare.find(d => d.day === day);
                return found ? parseInt(found.completed) : 0;
            });

            const theme = getChartTheme();
            ctx.analyticsCharts.tasksDaily = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: uniqueLabels,
                    datasets: [
                        { label: 'Создано (тек.)', data: createdData, borderColor: '#3B82F6', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.3 },
                        { label: 'Завершено (тек.)', data: completedData, borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3 },
                        { label: 'Создано (пред.)', data: compareCreatedData, borderColor: '#3B82F6', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.3 },
                        { label: 'Завершено (пред.)', data: compareCompletedData, borderColor: '#10B981', borderDash: [5, 5], backgroundColor: 'transparent', fill: false, tension: 0.3 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: theme.textColor } } },
                    scales: {
                        x: { ticks: { color: theme.textColor }, grid: { color: theme.gridColor } },
                        y: { beginAtZero: true, ticks: { color: theme.textColor }, grid: { color: theme.gridColor } }
                    }
                }
            });
        },

        renderTasksStatusChart(ctx) {
            const canvas = getCanvas('chartTasksStatus');
            if (!canvas) return;
            destroyChart(ctx, 'tasksStatus');

            const data = ctx.analyticsTasksData?.by_status || [];
            const labels = data.map(d => d.status);
            const values = data.map(d => parseInt(d.cnt));
            const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#6B7280'];
            const theme = getChartTheme();

            ctx.analyticsCharts.tasksStatus = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 0 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: theme.textColor } } }
                }
            });
        },

        renderTasksPriorityChart(ctx) {
            const canvas = getCanvas('chartTasksPriority');
            if (!canvas) return;
            destroyChart(ctx, 'tasksPriority');

            const data = ctx.analyticsTasksData?.by_priority || [];
            const labels = data.map(d => d.priority || 'Не указан');
            const values = data.map(d => parseInt(d.cnt));
            const colors = ['#EF4444', '#F59E0B', '#10B981', '#6B7280'];
            const theme = getChartTheme();

            ctx.analyticsCharts.tasksPriority = new Chart(canvas, {
                type: 'pie',
                data: {
                    labels,
                    datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 0 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: theme.textColor } } }
                }
            });
        },

        renderShiftsDailyChart(ctx) {
            const canvas = getCanvas('chartShiftsDaily');
            if (!canvas) return;
            destroyChart(ctx, 'shiftsDaily');

            const daily = ctx.analyticsShiftsData?.daily_hours || [];
            const dailyCompare = ctx.analyticsShiftsData?.daily_hours_compare || [];
            const labels = [...daily.map(d => d.day), ...dailyCompare.map(d => d.day)];
            const uniqueLabels = [...new Set(labels)].sort();

            const hoursData = uniqueLabels.map(day => {
                const found = daily.find(d => d.day === day);
                return found ? parseFloat(found.total_hours) : 0;
            });
            const compareHoursData = uniqueLabels.map(day => {
                const found = dailyCompare.find(d => d.day === day);
                return found ? parseFloat(found.total_hours) : 0;
            });

            const theme = getChartTheme();
            ctx.analyticsCharts.shiftsDaily = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: uniqueLabels,
                    datasets: [
                        { label: 'Часы (тек.)', data: hoursData, backgroundColor: 'rgba(59,130,246,0.7)', borderRadius: 4 },
                        { label: 'Часы (пред.)', data: compareHoursData, backgroundColor: 'rgba(139,92,246,0.5)', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: theme.textColor } } },
                    scales: {
                        x: { ticks: { color: theme.textColor }, grid: { color: theme.gridColor } },
                        y: { beginAtZero: true, ticks: { color: theme.textColor }, grid: { color: theme.gridColor } }
                    }
                }
            });
        },

        renderCRMFunnelChart(ctx) {
            const canvas = getCanvas('chartCRMFunnel');
            if (!canvas) return;
            destroyChart(ctx, 'crmFunnel');

            const funnel = ctx.analyticsCRMData?.funnel || [];
            const labels = funnel.map(f => f.name);
            const values = funnel.map(f => parseInt(f.cnt));
            const colors = ['#3B82F6', '#8B5CF6', '#EC4899', '#F59E0B', '#10B981', '#EF4444', '#6B7280'];
            const theme = getChartTheme();

            ctx.analyticsCharts.crmFunnel = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ label: 'Сделки', data: values, backgroundColor: colors.slice(0, labels.length), borderRadius: 4 }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: theme.textColor }, grid: { color: theme.gridColor } },
                        y: { ticks: { color: theme.textColor }, grid: { display: false } }
                    }
                }
            });
        },

        renderEmployeeEfficiencyChart(ctx) {
            const canvas = getCanvas('chartEmployeeEfficiency');
            if (!canvas) return;
            destroyChart(ctx, 'employeeEfficiency');

            const employees = ctx.analyticsEmployees || [];
            const top = employees.slice(0, 10);
            const labels = top.map(e => e.full_name);
            const completedData = top.map(e => e.completed_tasks);
            const activeData = top.map(e => e.active_tasks);
            const theme = getChartTheme();

            ctx.analyticsCharts.employeeEfficiency = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Завершено', data: completedData, backgroundColor: 'rgba(16,185,129,0.7)', borderRadius: 4 },
                        { label: 'В работе', data: activeData, backgroundColor: 'rgba(59,130,246,0.7)', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: theme.textColor } } },
                    scales: {
                        x: { ticks: { color: theme.textColor, maxRotation: 45 }, grid: { color: theme.gridColor } },
                        y: { beginAtZero: true, ticks: { color: theme.textColor }, grid: { color: theme.gridColor } }
                    }
                }
            });
        },

        renderTasksByProjectChart(ctx) {
            const canvas = getCanvas('chartTasksByProject');
            if (!canvas) return;
            destroyChart(ctx, 'tasksByProject');

            const projects = ctx.analyticsTasksByProject || [];
            const top = projects.slice(0, 10);
            const labels = top.map(p => p.project_name || 'Без проекта');
            const totalData = top.map(p => parseInt(p.total));
            const completedData = top.map(p => parseInt(p.completed));
            const activeData = top.map(p => parseInt(p.active));
            const theme = getChartTheme();

            ctx.analyticsCharts.tasksByProject = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Всего', data: totalData, backgroundColor: 'rgba(139,92,246,0.7)', borderRadius: 4 },
                        { label: 'Завершено', data: completedData, backgroundColor: 'rgba(16,185,129,0.7)', borderRadius: 4 },
                        { label: 'В работе', data: activeData, backgroundColor: 'rgba(245,158,11,0.7)', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: theme.textColor } } },
                    scales: {
                        x: { ticks: { color: theme.textColor, maxRotation: 45 }, grid: { color: theme.gridColor } },
                        y: { beginAtZero: true, ticks: { color: theme.textColor }, grid: { color: theme.gridColor } }
                    }
                }
            });
        }
    };
})();
