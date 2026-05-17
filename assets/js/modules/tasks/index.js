window.TaskFlowTasks = (function () {
    const TASK_VIEW_STORAGE_KEY = 'tasksView';
    const MY_TASK_VIEW_STORAGE_KEY = 'myTasksView';
    const ALLOWED_TASK_VIEWS = ['kanban', 'list', 'gantt', 'calendar'];

    function getTaskFilterDefaults() {
        return { project_id: '', status: '', department_id: '' };
    }

    function applyTaskFilters(list, filters) {
        let filtered = Array.isArray(list) ? list : [];
        if (filters?.project_id) {
            filtered = filtered.filter(task => task.project_id == filters.project_id);
        }
        if (filters?.status) {
            filtered = filtered.filter(task => task.status === filters.status);
        }
        if (filters?.department_id) {
            filtered = filtered.filter(task => task.department_id == filters.department_id);
        }
        return filtered;
    }

    function normalizeDateOnly(value) {
        if (!value) return null;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return null;
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function formatCalendarDeadline(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function buildTaskPayload(ctx) {
        return {
            title: ctx.taskForm.title,
            description: ctx.taskForm.description || '',
            project_id: ctx.taskForm.project_id || null,
            status: ctx.taskForm.status,
            priority: ctx.taskForm.priority,
            deadline: ctx.taskForm.deadline || null,
            assigned_to: ctx.taskForm.assigned_to || null,
            client_id: ctx.taskForm.client_id || null,
            deal_id: ctx.taskForm.deal_id || null,
            department_ids: ctx.taskForm.department_id ? [ctx.taskForm.department_id] : [],
            checklist: ctx.taskForm.checklist || [],
            responsible_ids: ctx.taskForm.responsible_ids || []
        };
    }

    function normalizeChecklist(checklist) {
        let items = checklist || [];
        if (typeof items === 'string') {
            try {
                items = JSON.parse(items);
            } catch (_e) {
                items = [];
            }
        }

        return items.map((item, idx) => ({
            id: item.id || Date.now() + idx,
            text: item.text || '',
            done: item.done || false
        }));
    }

    function getNewTaskForm(task, isNewTaskWithProject, status = 'Новая') {
        return {
            title: '',
            description: '',
            project_id: isNewTaskWithProject ? task.project_id : '',
            client_id: task?.client_id || '',
            deal_id: task?.deal_id || '',
            status,
            priority: 'medium',
            deadline: '',
            assigned_to: '',
            checklist: [],
            department_id: '',
            responsible_ids: []
        };
    }

    return {
        getTaskFilterDefaults,

        getFilteredTasks(ctx) {
            return applyTaskFilters(ctx.tasks || [], ctx.taskFilters);
        },

        getFilteredMyTasks(ctx) {
            const mine = (ctx.tasks || []).filter(task => task.assigned_to == ctx.currentUser?.id);
            return applyTaskFilters(mine, ctx.myTaskFilters);
        },

        async saveTask(ctx) {
            try {
                const taskData = buildTaskPayload(ctx);

                if (ctx.editingTask?.id) {
                    await apiUpdateTask(ctx.editingTask.id, taskData);
                    ctx.showToast('Задача обновлена', 'success');
                } else {
                    await apiCreateTask(taskData);
                    ctx.showToast('Задача создана', 'success');
                }

                await ctx.loadTasks();

                if (ctx.editingProject?.id) {
                    await ctx.loadProjectTasks(ctx.editingProject.id);
                    await ctx.loadProjectFiles(ctx.editingProject.id);
                }

                this.closeTaskModal(ctx);
            } catch (error) {
                console.error('Ошибка сохранения задачи:', error);
                ctx.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        closeTaskModal(ctx) {
            ctx.taskModalOpen = false;
            ctx.editingTask = null;
        },

        openTaskModal(ctx, task = null) {
            const isNewTaskWithProject = task && !task.id && task.project_id;

            ctx.editingTask = task && task.id ? task : null;

            if (!ctx.users || ctx.users.length === 0) {
                ctx.loadUsers();
            }

            if (isNewTaskWithProject || (task && task.project_id)) {
                if (!ctx.editingProject?.id) {
                    ctx.editingProject = ctx.projects.find((p) => p.id === task.project_id) || { id: task.project_id };
                }
            }

            if (task && task.id) {
                const checklist = normalizeChecklist(task.checklist);

                ctx.taskForm = {
                    title: task.title,
                    description: task.description || '',
                    project_id: task.project_id || '',
                    client_id: task.client_id || '',
                    deal_id: task.deal_id || '',
                    status: task.status,
                    priority: task.priority,
                    deadline: task.deadline || '',
                    assigned_to: task.assigned_to || '',
                    checklist,
                    department_id: task.department_id || (task.departments && task.departments.length > 0 ? task.departments[0].id : ''),
                    responsible_ids: (task.responsibles || []).map((r) => r.id)
                };

                const hasActiveTimer = ctx.activeTimers?.[task.id] !== undefined;
                ctx.taskTimerSeconds = hasActiveTimer ? ctx.activeTimers[task.id] : (task.timer_seconds || 0);
                ctx.taskTimerTaskId = task.id;
                ctx.taskTimerRunning = hasActiveTimer;

                ctx.loadTaskComments(task.id);
                ctx.loadTaskFilesData(task.id);
                ctx.loadTaskHistory(task.id);
            } else {
                ctx.taskForm = getNewTaskForm(task, isNewTaskWithProject);
                ctx.taskTimerSeconds = 0;
                ctx.taskTimerTaskId = null;
                ctx.taskTimerRunning = false;
            }

            ctx.taskTab = 'task';
            ctx.newCommentText = '';
            ctx.taskModalOpen = true;
        },

        async deleteTask(ctx) {
            if (!ctx.editingTask?.id) return;
            ctx.openConfirm(
                'Удалить задачу?',
                'Вы уверены что хотите удалить эту задачу? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteTask(ctx.editingTask.id);
                        ctx.showToast('Задача удалена', 'success');
                        await ctx.loadTasks();
                        this.closeTaskModal(ctx);
                    } catch (error) {
                        console.error('Ошибка удаления задачи:', error);
                        ctx.showToast('Ошибка: ' + error.message, 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        async deleteTaskById(ctx, taskId) {
            if (!taskId) return;
            ctx.openConfirm(
                'Удалить задачу?',
                'Вы уверены что хотите удалить эту задачу? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteTask(taskId);
                        ctx.showToast('Задача удалена', 'success');
                        await ctx.loadTasks();
                    } catch (error) {
                        console.error('Ошибка удаления задачи:', error);
                        ctx.showToast('Ошибка: ' + error.message, 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        async moveTask(ctx, event, newStatus) {
            event.preventDefault();
            const taskId = ctx.draggingTask || event?.dataTransfer?.getData('text/plain');
            if (!taskId) return;
            try {
                const result = await apiMoveTask(parseInt(taskId, 10), newStatus);
                if (result.success) {
                    const numericTaskId = parseInt(taskId, 10);
                    const task = ctx.tasks.find((t) => parseInt(t.id, 10) === numericTaskId);
                    if (task) task.status = newStatus;
                    ctx.showToast('Статус обновлён', 'success');
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Неизвестная'), 'error');
                }
            } catch (error) {
                console.error('Ошибка перемещения:', error);
                ctx.showToast('Ошибка перемещения: ' + error.message, 'error');
            }
            ctx.draggingTask = null;
        },

        createTaskFromMenu(ctx) {
            const isCalendarContext = ctx.contextMenuStage === '__calendar__';
            const deadline = isCalendarContext ? (ctx._calendarContextDate || '') : '';

            ctx.taskForm = {
                ...getNewTaskForm(null, false, isCalendarContext ? 'Новая' : (ctx.contextMenuStageName || 'Новая')),
                deadline,
                assigned_to: ''
            };
            ctx.editingTask = null;
            ctx.taskModalOpen = true;
            ctx.closeContextMenu();
        },

        deleteTaskFromMenu(ctx) {
            if (ctx.contextMenuTask) {
                ctx.editingTask = ctx.contextMenuTask;
                this.deleteTask(ctx);
            }
            ctx.closeContextMenu();
        },

        startTaskTimer(ctx) {
            if (ctx.taskTimerTaskId) {
                ctx.taskTimerRunning = true;
                ctx.activeTimers[ctx.taskTimerTaskId] = ctx.taskTimerSeconds;
                ctx.taskTimerInterval = setInterval(() => {
                    ctx.taskTimerSeconds++;
                    ctx.activeTimers[ctx.taskTimerTaskId] = ctx.taskTimerSeconds;
                }, 1000);
            }
        },

        stopTaskTimer(ctx) {
            ctx.taskTimerRunning = false;
            if (ctx.taskTimerInterval) {
                clearInterval(ctx.taskTimerInterval);
                ctx.taskTimerInterval = null;
            }
            if (ctx.taskTimerTaskId) {
                const totalSeconds = ctx.taskTimerSeconds;
                ctx.activeTimers[ctx.taskTimerTaskId] = totalSeconds;
                this.saveTaskTimerToServer(ctx, ctx.taskTimerTaskId, totalSeconds);
                delete ctx.activeTimers[ctx.taskTimerTaskId];
            }
        },

        toggleTaskTimer(ctx) {
            if (ctx.taskTimerRunning) {
                this.stopTaskTimer(ctx);
            } else {
                this.startTaskTimer(ctx);
            }
        },

        async saveTaskTimerToServer(_ctx, taskId, seconds) {
            try {
                await apiPut(`tasks/${taskId}`, { timer_seconds: seconds });
            } catch (error) {
                console.error('Ошибка сохранения таймера:', error);
            }
        },

        initViewPersistence(ctx) {
            ctx.$watch('tasksView', (newView) => {
                localStorage.setItem(TASK_VIEW_STORAGE_KEY, newView);
            });
            ctx.$watch('myTasksView', (newView) => {
                localStorage.setItem(MY_TASK_VIEW_STORAGE_KEY, newView);
            });

            const savedTasksView = localStorage.getItem(TASK_VIEW_STORAGE_KEY);
            if (savedTasksView && ALLOWED_TASK_VIEWS.includes(savedTasksView)) {
                ctx.tasksView = savedTasksView;
            }

            const savedMyTasksView = localStorage.getItem(MY_TASK_VIEW_STORAGE_KEY);
            if (savedMyTasksView && ALLOWED_TASK_VIEWS.includes(savedMyTasksView)) {
                ctx.myTasksView = savedMyTasksView;
            }
        },

        getTasksByStage(ctx, stageName) {
            const filtered = ctx.filteredTasks;
            if (!filtered) return [];
            return filtered.filter(task => task.status === stageName);
        },

        getMyTasksByStage(ctx, stageName) {
            const filtered = ctx.filteredTasks;
            if (!ctx.currentUser || !filtered) return [];
            return filtered.filter(task => task.status === stageName && task.assigned_to === ctx.currentUser.id);
        },

        getDaysUntilDeadline(_ctx, dateStr) {
            if (!dateStr) return '';
            const deadline = new Date(dateStr);
            const today = new Date();
            const diffTime = deadline - today;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays < 0) return 'Просрочено';
            if (diffDays === 0) return 'Сегодня';
            if (diffDays === 1) return '1 день';
            if (diffDays < 5) return diffDays + ' дн.';
            return diffDays + ' дней';
        },

        initCalendar(ctx, calendarId = 'calendar', tasks = null) {
            const calendarKey = calendarId === 'my-tasks-calendar' ? 'myTasksCalendar' : 'calendar';
            if (ctx[calendarKey]) {
                ctx[calendarKey].destroy();
                ctx[calendarKey] = null;
            }

            const calendarEl = document.getElementById(calendarId);
            if (!calendarEl || !window.FullCalendar?.Calendar) return;

            const useTasks = Array.isArray(tasks) ? tasks : (ctx.tasks || []);
            ctx._calendarContextDate = null;

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'ru',
                firstDay: 1,
                timeZone: 'local',
                height: 'auto',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                buttonText: {
                    today: 'Сегодня',
                    month: 'Месяц',
                    week: 'Неделя',
                    day: 'День',
                    list: 'Список'
                },
                weekText: 'Нед',
                allDayText: 'Весь день',
                moreLinkText(n) {
                    return '+ ещё ' + n;
                },
                noEventsText: 'Нет событий для отображения',
                events: useTasks.map(task => ({
                    id: task.id,
                    title: task.title,
                    start: task.deadline,
                    backgroundColor: task.status_color,
                    borderColor: task.status_color,
                    extendedProps: { task }
                })),
                eventClick: (info) => {
                    const task = info.event.extendedProps.task;
                    if (task) ctx.openTaskModal(task);
                },
                dateClick: (info) => {
                    const deadline = formatCalendarDeadline(info.date);
                    ctx.taskForm = {
                        title: '',
                        description: '',
                        project_id: '',
                        status: 'Новая',
                        priority: 'medium',
                        deadline,
                        assigned_to: '',
                        checklist: []
                    };
                    ctx.editingTask = null;
                    ctx.taskModalOpen = true;
                },
                eventDidMount: (info) => {
                    info.el.addEventListener('contextmenu', (ev) => {
                        ev.preventDefault();
                        ev.stopPropagation();
                        const task = info.event.extendedProps.task;
                        if (task) ctx.openContextMenu(ev, task);
                    });
                },
                dayCellDidMount: (arg) => {
                    const dateStr = formatCalendarDeadline(arg.date);
                    arg.el.addEventListener('contextmenu', (ev) => {
                        ev.preventDefault();
                        ev.stopPropagation();
                        ctx._calendarContextDate = dateStr;
                        ctx.openContextMenu(ev, null, '__calendar__');
                    });
                    arg.el.addEventListener('click', () => {
                        ctx._calendarContextDate = dateStr;
                    });
                },
                editable: false,
                droppable: false
            });

            ctx[calendarKey] = calendar;
            calendar.render();
        },

        initGantt(ctx, containerId, tasks = null) {
            try {
                const container = document.getElementById(containerId);
                if (!container) return;
                const list = Array.isArray(tasks) ? tasks : [];
                const rows = this.buildGanttRows(ctx, list);
                this.renderGantt(ctx, container, rows);
            } catch (e) {
                console.warn('initGantt error', e);
            }
        },

        buildGanttRows(ctx, tasks) {
            const rows = [];
            for (const task of (tasks || [])) {
                const deadline = normalizeDateOnly(task.deadline);
                if (!deadline) continue;

                const createdAt = normalizeDateOnly(task.created_at);
                const start = createdAt && createdAt <= deadline
                    ? createdAt
                    : new Date(deadline.getFullYear(), deadline.getMonth(), deadline.getDate() - 1);

                if (deadline.getTime() < start.getTime()) continue;

                const isMine = ctx.currentUser?.id && (String(task.assigned_to) === String(ctx.currentUser.id));
                rows.push({
                    id: task.id,
                    title: task.title || `Задача #${task.id}`,
                    project: task.project_name || '—',
                    start,
                    end: deadline,
                    status: task.status || '',
                    priority: task.priority || '',
                    isMine
                });
            }

            rows.sort((a, b) => a.start.getTime() - b.start.getTime());
            return rows;
        },

        renderGantt(ctx, container, rows) {
            const dayMs = 24 * 60 * 60 * 1000;
            const today = new Date();
            const today0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());

            let min = null;
            let max = null;
            for (const row of (rows || [])) {
                const startMs = row.start.getTime();
                const endMs = row.end.getTime();
                min = (min == null) ? startMs : Math.min(min, startMs);
                max = (max == null) ? endMs : Math.max(max, endMs);
            }

            if (min == null || max == null) {
                container.innerHTML = `
                    <div class="rounded-2xl border border-dashed p-8 text-center" style="border-color: var(--lg-border); color: var(--lg-text-tertiary);">
                        <div class="text-sm font-semibold" style="color: var(--lg-text-primary)">Нет задач для Ганта</div>
                        <div class="text-xs mt-1" style="color: var(--lg-text-tertiary)">Нужен заполненный дедлайн у задач.</div>
                    </div>
                `;
                return;
            }

            min -= dayMs * 2;
            max += dayMs * 3;
            const days = Math.max(1, Math.round((max - min) / dayMs) + 1);
            const containerWidth = container.parentElement?.clientWidth || 1200;
            const labelW = 320;
            const availableChartW = containerWidth - labelW - 40;
            const pxPerDay = Math.max(30, Math.floor(availableChartW / days));
            const rowH = 42;
            const headerH = 36;
            const chartW = days * pxPerDay;
            const totalW = labelW + chartW;

            const fmt = (d) => {
                const dd = String(d.getDate()).padStart(2, '0');
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const day = d.toLocaleDateString('ru-RU', { weekday: 'short' });
                return `${dd}.${mm} ${day}`;
            };

            const buildHeader = () => {
                let html = '';
                for (let i = 0; i < days; i++) {
                    const d = new Date(min + i * dayMs);
                    const isWeekend = [0, 6].includes(d.getDay());
                    const isToday = d.getTime() === today0.getTime();
                    html += `
                        <div class="flex items-center justify-center text-[10px] font-medium"
                             style="width:${pxPerDay}px; height:${headerH}px; border-left:1px solid var(--lg-border);
                             color: ${isToday ? 'var(--lg-primary)' : 'var(--lg-text-tertiary)'};
                             background: ${isToday ? 'color-mix(in oklab, var(--lg-primary) 15%, transparent)' : (isWeekend ? 'color-mix(in oklab, var(--lg-glass-bg) 40%, transparent)' : 'transparent')};
                             ${isWeekend && !isToday ? 'opacity:0.75;' : ''}">
                            ${fmt(d)}
                        </div>
                    `;
                }
                return html;
            };

            const todayX = labelW + Math.round((today0.getTime() - min) / dayMs) * pxPerDay;
            const gridBg = `background: linear-gradient(90deg, transparent 0, transparent ${labelW}px, color-mix(in oklab, var(--lg-glass-bg) 35%, transparent) ${labelW}px);`;

            let rowsHtml = '';
            for (const row of (rows || [])) {
                const x1 = Math.round((row.start.getTime() - min) / dayMs) * pxPerDay;
                const x2 = (Math.round((row.end.getTime() - min) / dayMs) + 1) * pxPerDay;
                const left = labelW + x1;
                const width = Math.max(8, x2 - x1);
                const barBg = row.isMine
                    ? 'var(--lg-primary-gradient)'
                    : 'linear-gradient(90deg, color-mix(in oklab, var(--lg-glass-bg) 10%, var(--lg-text-primary)), color-mix(in oklab, var(--lg-glass-bg) 25%, var(--lg-text-primary)))';

                let gridHtml = '';
                for (let i = 0; i < days; i++) {
                    const d = new Date(min + i * dayMs);
                    const isWeekend = [0, 6].includes(d.getDay());
                    gridHtml += `<div class="absolute top-0 bottom-0" style="left:${labelW + i * pxPerDay}px; width:1px; background: ${isWeekend ? 'color-mix(in oklab, var(--lg-border) 40%, transparent)' : 'color-mix(in oklab, var(--lg-border) 20%, transparent)'};"></div>`;
                }

                rowsHtml += `
                    <div class="relative" style="height:${rowH}px; border-top:1px solid var(--lg-border);">
                        ${gridHtml}
                        <button type="button" class="absolute left-0 top-0 h-full flex items-center gap-3 px-4 text-left" style="width:${labelW}px; color: var(--lg-text-primary); background: color-mix(in oklab, var(--lg-glass-bg) 60%, transparent);"
                            onclick="window.__tfOpenTask && window.__tfOpenTask(${Number(row.id)})">
                            <div class="min-w-0">
                                <div class="text-xs font-semibold truncate">${ctx.escapeHtml(row.title)}</div>
                                <div class="text-[10px] truncate" style="color: var(--lg-text-tertiary)">${ctx.escapeHtml(row.project)} • ${ctx.escapeHtml(row.status || '—')}</div>
                            </div>
                        </button>

                        <div class="absolute top-0 bottom-0" style="left:${left}px; width:${width}px; margin-top:8px; margin-bottom:8px; border-radius: 999px; background: ${barBg}; box-shadow: var(--lg-shadow-sm); border:1px solid color-mix(in oklab, var(--lg-border) 70%, transparent);"></div>
                    </div>
                `;
            }

            container.innerHTML = `
                <div class="liquid-glass-pro rounded-2xl overflow-x-auto" style="border:1px solid var(--lg-border); ${gridBg} max-height: calc(100vh - 200px);">
                    <div class="relative" style="width:${totalW}px; min-width: 100%;">
                        <div class="flex" style="width:${totalW}px">
                            <div class="flex items-center px-4 flex-shrink-0" style="width:${labelW}px; height:${headerH}px; border-right:1px solid var(--lg-border); background: color-mix(in oklab, var(--lg-glass-bg) 70%, transparent);">
                                <div class="text-[11px] font-semibold" style="color: var(--lg-text-tertiary)">Задачи</div>
                            </div>
                            <div class="flex" style="height:${headerH}px;">
                                ${buildHeader()}
                            </div>
                        </div>

                        <div class="relative" style="width:${totalW}px">
                            <div class="absolute top-0 bottom-0 pointer-events-none" style="left:${todayX}px; width:2px; background: color-mix(in oklab, var(--accent-color) 70%, transparent); box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent-color) 12%, transparent);"></div>
                            ${rowsHtml}
                        </div>
                    </div>
                </div>
            `;
        }
    };
})();
