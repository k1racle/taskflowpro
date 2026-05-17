window.TaskFlowShifts = (function () {
    const SCHEDULE_TEMPLATES = {
        '5/2': { shift_start: '09:00', shift_end: '18:00', is_day_off: false, workDays: [1, 2, 3, 4, 5] },
        '2/2': { shift_start: '09:00', shift_end: '21:00', is_day_off: false, pattern: [1, 1, 0, 0] },
        '3/3': { shift_start: '09:00', shift_end: '21:00', is_day_off: false, pattern: [1, 1, 1, 0, 0, 0] },
        '1/3': { shift_start: '09:00', shift_end: '09:00', is_day_off: false, pattern: [1, 0, 0, 0] }
    };

    const SCHEDULE_TEMPLATE_LABELS = {
        '5/2': 'Пн-Пт (5/2)',
        '2/2': '2 через 2',
        '3/3': '3 через 3',
        '1/3': '1 через 3'
    };

    function resetShiftSnapshot(ctx) {
        ctx.shiftStatus = 'offline';
        ctx.shiftStart = null;
        ctx.shiftNote = '';
        ctx.shiftTimer = '00:00:00';
        ctx.breakTime = '0 мин';
        ctx.workedTime = '0 ч';
        ctx._shiftOpenSession = null;
    }

    function getScheduleFormDefaults(ctx) {
        return {
            user_id: '',
            schedule_date: ctx.scheduleForm?.schedule_date || new Date().toISOString().split('T')[0],
            period_start: '',
            period_end: '',
            shift_start: '09:00',
            shift_end: '18:00',
            break_minutes: 60,
            is_day_off: false,
            note: '',
            selected_template: ''
        };
    }

    function buildSingleSchedulePayload(ctx) {
        return {
            user_id: ctx.scheduleForm.user_id,
            schedule_date: ctx.scheduleForm.schedule_date,
            shift_start: ctx.scheduleForm.is_day_off ? null : ctx.scheduleForm.shift_start,
            shift_end: ctx.scheduleForm.is_day_off ? null : ctx.scheduleForm.shift_end,
            is_day_off: ctx.scheduleForm.is_day_off ? 1 : 0,
            note: ctx.scheduleForm.note
        };
    }

    function buildCalendarMonthRange(month) {
        const [year, currentMonth] = String(month || '').split('-');
        const firstDay = `${year}-${currentMonth}-01`;
        const lastDay = new Date(year, currentMonth, 0).toISOString().split('T')[0];
        return { firstDay, lastDay };
    }

    function buildBulkSchedules(ctx) {
        const start = new Date(ctx.scheduleForm.period_start);
        const end = new Date(ctx.scheduleForm.period_end);
        const template = ctx.scheduleForm.selected_template;
        const schedules = [];
        const patterns = {
            '2/2': [1, 1, 0, 0],
            '1/3': [1, 0, 0, 0],
            '3/3': [1, 1, 1, 0, 0, 0]
        };

        const pattern = patterns[template];
        const patternDayOffset = pattern
            ? Math.floor((start - new Date(start.getFullYear(), 0, 1)) / 86400000) % pattern.length
            : 0;

        for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            const dateStr = d.toISOString().split('T')[0];
            const dayOfWeek = d.getDay();
            let isWorkDay = false;

            if (template === '5/2') {
                isWorkDay = dayOfWeek >= 1 && dayOfWeek <= 5;
            } else if (pattern) {
                const dayIndex = Math.floor((d - new Date(d.getFullYear(), 0, 1)) / 86400000);
                const pIdx = (dayIndex + patternDayOffset) % pattern.length;
                isWorkDay = pattern[pIdx] === 1;
            } else {
                isWorkDay = true;
            }

            schedules.push({
                user_id: ctx.scheduleForm.user_id,
                schedule_date: dateStr,
                shift_start: isWorkDay ? ctx.scheduleForm.shift_start : null,
                shift_end: isWorkDay ? ctx.scheduleForm.shift_end : null,
                is_day_off: !isWorkDay ? 1 : 0,
                note: ctx.scheduleForm.note
            });
        }

        return schedules;
    }

    return {
        async loadMyShift(ctx) {
            try {
                const res = await apiGet('shifts/me/today');
                if (!res.success) return;

                const open = res.data?.open || null;
                ctx._shiftOpenSession = open;

                if (!open) {
                    resetShiftSnapshot(ctx);
                } else {
                    ctx.shiftStatus = open.status || 'working';
                    ctx.shiftStart = open.started_at;
                    ctx.shiftNote = open.note || '';
                }

                const h = await apiGet('shifts/me/history');
                if (h.success) {
                    ctx.shiftHistory = (h.data || []).map(s => this.mapShiftForUi(ctx, s));
                }

                this.generateWeekSchedule(ctx);
                this.startShiftTicker(ctx);
            } catch (e) {
                console.warn('loadMyShift error', e);
            }
        },

        mapShiftForUi(ctx, shift) {
            const started = shift.started_at ? new Date(shift.started_at) : null;
            const ended = shift.ended_at ? new Date(shift.ended_at) : null;
            const date = started ? started.toLocaleDateString('ru-RU') : '';
            const start = started ? started.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '—';
            const end = ended ? ended.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '—';
            const br = this.formatDuration(ctx, shift.break_seconds || 0);
            const wk = this.formatDuration(ctx, shift.worked_seconds || 0);
            return { ...shift, date, start, end, break: br, worked: wk, status: shift.ended_at ? 'completed' : 'in_progress' };
        },

        startShiftTicker(ctx) {
            if (ctx._shiftTick) clearInterval(ctx._shiftTick);

            const tick = () => {
                const open = ctx._shiftOpenSession;
                if (!open?.started_at) {
                    ctx.shiftTimer = '00:00:00';
                    ctx.breakTime = '0 мин';
                    ctx.workedTime = '0 ч';
                    return;
                }

                const start = new Date(open.started_at);
                const now = new Date();
                const total = Math.max(0, Math.floor((now - start) / 1000));
                let breakSeconds = Number(open.break_seconds || 0);
                if (open.status === 'break' && open.current_break_started_at) {
                    breakSeconds += Math.max(0, Math.floor((now - new Date(open.current_break_started_at)) / 1000));
                }
                const worked = Math.max(0, total - breakSeconds);

                ctx.shiftTimer = this.formatHms(ctx, total);
                ctx.breakTime = this.formatDuration(ctx, breakSeconds);
                ctx.workedTime = this.formatDuration(ctx, worked);
            };

            tick();
            ctx._shiftTick = setInterval(tick, 1000);
        },

        formatHms(_ctx, totalSeconds) {
            const s = Math.max(0, Number(totalSeconds || 0));
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const ss = Math.floor(s % 60);
            return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(ss).padStart(2, '0')}`;
        },

        formatDuration(_ctx, totalSeconds) {
            const s = Math.max(0, Number(totalSeconds || 0));
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            if (h <= 0) return `${m} мин`;
            const mm = String(m).padStart(2, '0');
            return `${h}:${mm} ч`;
        },

        async startShift(ctx) {
            try {
                const res = await apiPost('shifts/me/start', {});
                if (res.success) {
                    ctx.showToast('Смена началась', 'success');
                    await this.loadMyShift(ctx);
                }
            } catch (e) {
                ctx.showToast(e.userMessage || e.message || 'Не удалось начать смену', 'error');
            }
        },

        async startBreak(ctx) {
            try {
                const res = await apiPost('shifts/me/break-start', {});
                if (res.success) {
                    ctx.showToast('Перерыв', 'info');
                    await this.loadMyShift(ctx);
                }
            } catch (e) {
                ctx.showToast(e.userMessage || e.message || 'Не удалось начать перерыв', 'error');
            }
        },

        async endBreak(ctx) {
            try {
                const res = await apiPost('shifts/me/break-end', {});
                if (res.success) {
                    ctx.showToast('С возвращением!', 'success');
                    await this.loadMyShift(ctx);
                }
            } catch (e) {
                ctx.showToast(e.userMessage || e.message || 'Не удалось завершить перерыв', 'error');
            }
        },

        async endShift(ctx) {
            ctx.openConfirm(
                'Завершить смену?',
                'Смена будет закрыта. Это действие можно будет увидеть в истории.',
                async () => {
                    try {
                        const res = await apiPost('shifts/me/end', {});
                        if (res.success) {
                            ctx.showToast('Смена завершена', 'success');
                            await this.loadMyShift(ctx);
                            return;
                        }
                        ctx.showToast(res.error || 'Не удалось завершить смену', 'error');
                    } catch (e) {
                        ctx.showToast(e.userMessage || e.message || 'Не удалось завершить смену', 'error');
                    }
                },
                { confirmText: 'Завершить', cancelText: 'Отмена', danger: true }
            );
        },

        async saveShiftNote(ctx) {
            try {
                await apiPost('shifts/me/note', { note: ctx.shiftNote });
                ctx.showToast('Заметка сохранена', 'success');
                await this.loadMyShift(ctx);
            } catch (e) {
                ctx.showToast('Не удалось сохранить заметку', 'error');
            }
        },

        async loadWorkSchedules(ctx) {
            if (!ctx.can('leader.view')) return;
            try {
                const params = new URLSearchParams();
                if (ctx.scheduleFilters.user_id) params.append('user_id', ctx.scheduleFilters.user_id);

                if (ctx.scheduleFilters.month) {
                    const { firstDay, lastDay } = buildCalendarMonthRange(ctx.scheduleFilters.month);
                    params.append('date_from', firstDay);
                    params.append('date_to', lastDay);
                } else {
                    if (ctx.scheduleFilters.date_from) params.append('date_from', ctx.scheduleFilters.date_from);
                    if (ctx.scheduleFilters.date_to) params.append('date_to', ctx.scheduleFilters.date_to);
                }

                const res = await apiGet(`work-schedules?${params.toString()}`);
                if (res.success) {
                    ctx.workSchedules = res.data || [];
                    if (ctx.scheduleViewMode === 'calendar') {
                        this.generateCalendarDays(ctx);
                    }
                }
            } catch (e) {
                console.warn('loadWorkSchedules error', e);
            }
        },

        openScheduleModal(ctx, schedule = null) {
            ctx.editingSchedule = schedule;
            if (schedule) {
                ctx.scheduleForm = {
                    user_id: schedule.user_id,
                    schedule_date: schedule.schedule_date,
                    period_start: '',
                    period_end: '',
                    shift_start: schedule.shift_start || '09:00',
                    shift_end: schedule.shift_end || '18:00',
                    break_minutes: 60,
                    is_day_off: !!schedule.is_day_off,
                    note: schedule.note || '',
                    selected_template: ''
                };
            } else {
                ctx.scheduleForm = getScheduleFormDefaults(ctx);
            }
            ctx.scheduleModalOpen = true;
        },

        applyScheduleTemplate(ctx, template) {
            const current = SCHEDULE_TEMPLATES[template];
            if (!current) return;

            ctx.scheduleForm.shift_start = current.shift_start;
            ctx.scheduleForm.shift_end = current.shift_end;
            ctx.scheduleForm.is_day_off = false;
            ctx.scheduleForm.selected_template = template;

            const now = new Date();
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            ctx.scheduleForm.period_start = firstDay.toISOString().split('T')[0];
            ctx.scheduleForm.period_end = lastDay.toISOString().split('T')[0];

            ctx.showToast(`Шаблон "${SCHEDULE_TEMPLATE_LABELS[template] || template}" применён. Проверьте период и нажмите "Сохранить".`, 'info');
        },

        generateBulkSchedule(ctx) {
            if (!ctx.scheduleForm.user_id || !ctx.scheduleForm.period_start || !ctx.scheduleForm.period_end) {
                ctx.showToast('Укажите сотрудника и период', 'error');
                return;
            }

            const schedules = buildBulkSchedules(ctx);
            const workDays = schedules.filter((s) => !s.is_day_off).length;
            const offDays = schedules.filter((s) => s.is_day_off).length;
            const tmplLabel = {
                '5/2': '5/2 (Пн-Пт)',
                '2/2': '2/2',
                '1/3': '1/3',
                '3/3': '3/3'
            }[ctx.scheduleForm.selected_template] || 'Без шаблона';

            ctx.openConfirm(
                `Заполнить график: ${schedules.length} дней (${workDays} раб. + ${offDays} вых.)?`,
                `Шаблон: ${tmplLabel} • Период: ${ctx.scheduleForm.period_start} — ${ctx.scheduleForm.period_end}`,
                async () => {
                    try {
                        const res = await apiPost('work-schedules/bulk', { schedules });
                        if (res.success) {
                            ctx.showToast(`График создан: ${res.data.created} новых, ${res.data.updated} обновлено`, 'success');
                            ctx.scheduleModalOpen = false;
                            await this.loadWorkSchedules(ctx);
                        } else {
                            ctx.showToast(res.error || 'Ошибка', 'error');
                        }
                    } catch (e) {
                        ctx.showToast('Ошибка: ' + (e.message || ''), 'error');
                    }
                },
                { confirmText: 'Заполнить', cancelText: 'Отмена' }
            );
        },

        editSchedule(ctx, schedule) {
            this.openScheduleModal(ctx, schedule);
        },

        async saveSchedule(ctx) {
            try {
                if (!ctx.scheduleForm.user_id) {
                    ctx.showToast('Укажите сотрудника', 'error');
                    return;
                }

                if (ctx.scheduleForm.period_start && ctx.scheduleForm.period_end) {
                    this.generateBulkSchedule(ctx);
                    return;
                }

                if (!ctx.scheduleForm.schedule_date) {
                    ctx.showToast('Укажите дату', 'error');
                    return;
                }

                const payload = buildSingleSchedulePayload(ctx);
                if (ctx.editingSchedule?.id) {
                    payload.id = ctx.editingSchedule.id;
                }

                const res = await apiPost('work-schedules', payload);
                if (res.success) {
                    ctx.showToast('График сохранён', 'success');
                    ctx.scheduleModalOpen = false;
                    await this.loadWorkSchedules(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка сохранения', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка сохранения графика', 'error');
            }
        },

        getMonthName(_ctx, monthStr) {
            if (!monthStr) return '';
            const [year, month] = monthStr.split('-');
            const date = new Date(parseInt(year, 10), parseInt(month, 10) - 1);
            return date.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
        },

        previousMonth(ctx) {
            const [year, month] = ctx.calendarCurrentMonth.split('-').map(Number);
            const date = new Date(year, month - 2, 1);
            ctx.calendarCurrentMonth = date.toISOString().slice(0, 7);
            ctx.scheduleFilters.month = ctx.calendarCurrentMonth;
            this.generateCalendarDays(ctx);
            this.loadWorkSchedules(ctx);
        },

        nextMonth(ctx) {
            const [year, month] = ctx.calendarCurrentMonth.split('-').map(Number);
            const date = new Date(year, month, 1);
            ctx.calendarCurrentMonth = date.toISOString().slice(0, 7);
            ctx.scheduleFilters.month = ctx.calendarCurrentMonth;
            this.generateCalendarDays(ctx);
            this.loadWorkSchedules(ctx);
        },

        generateCalendarDays(ctx) {
            const [year, month] = ctx.calendarCurrentMonth.split('-').map(Number);
            const firstDay = new Date(year, month - 1, 1);
            const lastDay = new Date(year, month, 0);
            let firstDayOfWeek = firstDay.getDay();
            firstDayOfWeek = firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1;

            ctx.calendarPaddingDays = firstDayOfWeek;

            const today = new Date();
            const days = [];

            for (let d = 1; d <= lastDay.getDate(); d++) {
                const date = new Date(year, month - 1, d);
                const dateStr = date.toISOString().split('T')[0];
                const dayOfWeek = date.getDay();
                const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
                const isToday = date.toDateString() === today.toDateString();
                const schedules = (ctx.workSchedules || []).filter((s) => s.schedule_date === dateStr);

                days.push({ date: dateStr, day: d, isToday, isWeekend, schedules });
            }

            ctx.calendarDays = days;
        },

        onCalendarDayClick(ctx, day) {
            if (day.schedules.length > 0) {
                console.log('Смены за день:', day.schedules);
            } else {
                this.openScheduleModalForDay(ctx, day);
            }
        },

        openScheduleModalForDay(ctx, day) {
            ctx.scheduleForm.schedule_date = day.date;
            this.openScheduleModal(ctx);
        },

        openScheduleContextMenu(ctx, event, schedule) {
            event.preventDefault();
            ctx.contextMenuItem = schedule;
            ctx.calendarDayForContext = null;
            ctx.contextMenuX = Math.min(event.clientX, window.innerWidth - 220);
            ctx.contextMenuY = Math.min(event.clientY, window.innerHeight - 150);
            ctx.contextMenuOpen = true;
        },

        onCalendarContextMenu(ctx, event, day) {
            event.preventDefault();
            ctx.contextMenuItem = null;
            ctx.calendarDayForContext = day;
            ctx.contextMenuX = Math.min(event.clientX, window.innerWidth - 220);
            ctx.contextMenuY = Math.min(event.clientY, window.innerHeight - 150);
            ctx.contextMenuOpen = true;
        },

        closeScheduleContextMenu(ctx) {
            ctx.contextMenuOpen = false;
            ctx.contextMenuItem = null;
            ctx.calendarDayForContext = null;
        },

        confirmDeleteSchedule(ctx, schedule) {
            ctx.scheduleToDelete = schedule;
            ctx.deleteConfirmModalOpen = true;
        },

        async deleteSchedule(ctx) {
            if (!ctx.scheduleToDelete?.id) return;

            try {
                const res = await apiDelete(`work-schedules/${ctx.scheduleToDelete.id}`);
                if (res.success) {
                    ctx.showToast('График удалён', 'success');
                    ctx.deleteConfirmModalOpen = false;
                    ctx.scheduleToDelete = null;
                    await this.loadWorkSchedules(ctx);
                    this.generateCalendarDays(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка удаления', 'error');
                }
            } catch (_e) {
                ctx.showToast('Ошибка удаления графика', 'error');
            }
        },

        generateWeekSchedule(ctx) {
            const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
            const today = new Date();
            const start = new Date(today);
            const day = today.getDay();
            const mondayOffset = day === 0 ? -6 : 1 - day;
            start.setDate(today.getDate() + mondayOffset);

            ctx.weekSchedule = days.map((name, i) => {
                const d = new Date(start);
                d.setDate(start.getDate() + i);
                const dateStr = d.toLocaleDateString('ru-RU');
                const isToday = d.toDateString() === today.toDateString();
                const status = isToday ? (ctx.shiftStatus === 'offline' ? 'Сегодня' : 'Смена') : (d < today ? 'Прошло' : 'План');
                return { date: dateStr, name, isToday, status };
            });
        }
    };
})();
