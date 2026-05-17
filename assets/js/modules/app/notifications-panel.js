window.TaskFlowNotificationsPanel = (function () {
    const OPENABLE_NOTIFICATION_TYPES = ['chat', 'task', 'comment', 'project', 'department', 'mail', 'files', 'knowledge', 'crm', 'helpdesk', 'conference'];
    const NOTIFICATION_TITLE_MAP = {
        chat: 'Новое сообщение',
        task: 'Обновление по задаче',
        comment: 'Новый комментарий',
        crm: 'Изменение в CRM',
        helpdesk: 'Новое обращение',
        project: 'Обновление проекта',
        mail: 'Почтовое уведомление',
        conference: 'Событие конференции',
        knowledge: 'Обновление базы знаний',
        files: 'Событие по файлам',
        department: 'Изменение отдела',
        info: 'Системное уведомление',
        system: 'Системное уведомление'
    };
    const NOTIFICATION_SECTION_MAP = {
        chat: 'Чат и сообщения',
        task: 'Задачи',
        comment: 'Комментарии',
        crm: 'CRM',
        helpdesk: 'HelpDesk',
        project: 'Проекты',
        mail: 'Почта',
        conference: 'Конференции',
        knowledge: 'База знаний',
        files: 'Файлы',
        department: 'Отделы',
        info: 'Система',
        system: 'Система'
    };

    function buildToastText(notification, fallbackMessage) {
        return [notification.title || 'Новое уведомление', fallbackMessage]
            .filter(Boolean)
            .join(': ');
    }

    return {
        togglePanel(ctx) {
            ctx.notificationsPanelOpen = !ctx.notificationsPanelOpen;
            ctx.widgetsPanelOpen = false;
            ctx.mobileMoreOpen = false;
            ctx.mobileProfileOpen = false;
        },

        closeAllPanels(ctx) {
            ctx.notificationsPanelOpen = false;
            ctx.widgetsPanelOpen = false;
            ctx.mobileMoreOpen = false;
            ctx.mobileProfileOpen = false;
            ctx.topbarSearchOpen = false;
        },

        refreshCounters(ctx) {
            const unread = (ctx.notifications || []).filter(n => !(n.read ?? n.is_read));
            ctx.notificationCount = unread.filter(n => n.type !== 'chat').length;
            ctx.chatUnreadCount = unread.filter(n => {
                if (n.type !== 'chat') return false;
                const text = String(n.message || '').toLowerCase();
                return !text.includes('звонок');
            }).length;
        },

        getTypeLabel(ctx, notification) {
            const type = ctx.normalizeNotificationType(notification?.type || 'info');
            const labels = {
                chat: 'Чат',
                task: 'Задача',
                comment: 'Комментарий',
                crm: 'CRM',
                helpdesk: 'HelpDesk',
                project: 'Проект',
                mail: 'Почта',
                conference: 'Конференция',
                knowledge: 'База знаний',
                files: 'Файл',
                department: 'Отдел',
                info: 'Система',
                system: 'Система'
            };
            return labels[type] || 'Уведомление';
        },

        getTypeIcon(ctx, notification) {
            const type = ctx.normalizeNotificationType(notification?.type || 'info');
            const icons = {
                chat: '💬',
                task: '✓',
                comment: '🗨',
                crm: '◈',
                helpdesk: '⚑',
                project: '▣',
                mail: '✉',
                conference: '◉',
                knowledge: '≣',
                files: '▤',
                department: '⌘',
                info: 'i',
                system: 'i'
            };
            return icons[type] || '•';
        },

        getMessage(notification) {
            return String(notification?.message || notification?.text || '').trim() || 'Без дополнительного описания.';
        },

        getSubtitle(ctx, notification) {
            return String(notification?.subtitle || this.getTypeLabel(ctx, notification)).trim();
        },

        getEntityLabel(notification) {
            return String(notification?.target?.label || '').trim();
        },

        getRelativeTime(dateStr) {
            if (!dateStr) return 'Без даты';
            const date = new Date(dateStr);
            if (Number.isNaN(date.getTime())) return 'Без даты';

            const diffMs = Date.now() - date.getTime();
            const future = diffMs < 0;
            const absMs = Math.abs(diffMs);
            const minute = 60000;
            const hour = 3600000;
            const day = 86400000;

            let value;
            let unit;

            if (absMs < minute) {
                return future ? 'Через несколько секунд' : 'Только что';
            }
            if (absMs < hour) {
                value = Math.round(absMs / minute);
                unit = 'minute';
            } else if (absMs < day) {
                value = Math.round(absMs / hour);
                unit = 'hour';
            } else {
                value = Math.round(absMs / day);
                unit = 'day';
            }

            return new Intl.RelativeTimeFormat('ru', { numeric: 'auto' }).format(future ? value : -value, unit);
        },

        getAbsoluteTime(ctx, dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            if (Number.isNaN(date.getTime())) return '';
            return ctx.formatDateTime(dateStr);
        },

        canOpenTarget(ctx, notification) {
            const type = ctx.normalizeNotificationType(notification?.target?.type || notification?.type || '');
            return OPENABLE_NOTIFICATION_TYPES.includes(type);
        },

        getActionLabel(ctx, notification) {
            const type = ctx.normalizeNotificationType(notification?.target?.type || notification?.type || '');
            const labels = {
                chat: 'Открыть чат',
                task: 'Открыть задачу',
                comment: 'Открыть обсуждение',
                project: 'Открыть проект',
                department: 'Открыть отдел',
                mail: 'Перейти в почту',
                files: 'Открыть файлы',
                knowledge: 'Открыть базу знаний',
                crm: 'Открыть CRM',
                helpdesk: 'Открыть HelpDesk',
                conference: 'Открыть конференции'
            };
            return labels[type] || 'Открыть';
        },

        normalizeType(type) {
            const value = String(type || 'info').toLowerCase();
            const map = {
                tasks: 'task',
                projects: 'project',
                file: 'files',
                document: 'files',
                documents: 'files',
                comments: 'comment',
                messages: 'chat'
            };
            return map[value] || value;
        },

        parsePayload(payload) {
            if (!payload) return {};
            if (typeof payload === 'object') return payload;
            if (typeof payload !== 'string') return {};
            try {
                const parsed = JSON.parse(payload);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (_) {
                return {};
            }
        },

        pickText(notification, payload) {
            const value = notification.message
                ?? notification.text
                ?? notification.body
                ?? payload.message
                ?? payload.text
                ?? payload.body
                ?? payload.description
                ?? '';
            return String(value || '').trim() || 'Откройте уведомление, чтобы посмотреть детали.';
        },

        pickSubtitle(notification, payload, type, target) {
            const explicit = notification.subtitle
                ?? notification.subject
                ?? payload.subtitle
                ?? payload.subject
                ?? payload.entity_name
                ?? payload.name
                ?? '';
            if (String(explicit || '').trim()) return String(explicit).trim();

            if (target.label) return `${NOTIFICATION_SECTION_MAP[type] || 'Уведомления'} · ${target.label}`;
            return NOTIFICATION_SECTION_MAP[type] || 'Уведомления';
        },

        extractTarget(ctx, notification, payload, type) {
            const targetType = ctx.normalizeNotificationType(
                payload.entity_type
                || payload.target_type
                || notification.entity_type
                || notification.resource_type
                || type
            );

            const id = payload.entity_id
                ?? payload.target_id
                ?? payload.task_id
                ?? payload.project_id
                ?? payload.department_id
                ?? payload.room_id
                ?? payload.chat_room_id
                ?? payload.file_id
                ?? notification.related_id
                ?? notification.entity_id
                ?? notification.target_id
                ?? notification.task_id
                ?? notification.project_id
                ?? notification.department_id
                ?? notification.room_id
                ?? notification.chat_room_id
                ?? notification.file_id
                ?? null;

            const label = String(
                payload.entity_name
                || payload.target_name
                || payload.room_name
                || payload.task_title
                || payload.project_name
                || payload.department_name
                || payload.file_name
                || notification.entity_name
                || notification.target_name
                || notification.related_name
                || ''
            ).trim();

            return { type: targetType, id, label };
        },

        normalize(ctx, notification) {
            const n = notification || {};
            const readValue = (n.read ?? n.is_read ?? 0);
            const isRead = readValue === true || readValue === 1 || readValue === '1';
            const type = ctx.normalizeNotificationType(n.type || n.category || n.entity_type || 'info');
            const payload = ctx.parseNotificationPayload(n.payload ?? n.data ?? n.meta ?? n.metadata ?? null);
            const target = ctx.extractNotificationTarget(n, payload, type);

            return {
                ...n,
                payload,
                target,
                type,
                title: String(n.title || payload.title || NOTIFICATION_TITLE_MAP[type] || 'Уведомление').trim(),
                subtitle: ctx.pickNotificationSubtitle(n, payload, type, target),
                message: ctx.pickNotificationText(n, payload),
                read: isRead,
                is_read: isRead ? 1 : 0,
                created_at: n.created_at || n.createdAt || payload.created_at || payload.createdAt || null
            };
        },

        getStorageKey(ctx) {
            const userId = ctx.currentUser?.id || 'guest';
            return `workhub:lastNotifiedNotificationId:${userId}`;
        },

        restoreLastNotifiedId(ctx) {
            try {
                const value = localStorage.getItem(this.getStorageKey(ctx));
                ctx.lastNotifiedNotificationId = value ? String(value) : null;
            } catch (_) {
                ctx.lastNotifiedNotificationId = null;
            }
        },

        persistLastNotifiedId(ctx, id) {
            if (id === null || typeof id === 'undefined' || id === '') return;
            const value = String(id);
            ctx.lastNotifiedNotificationId = value;
            try {
                localStorage.setItem(this.getStorageKey(ctx), value);
            } catch (_) {}
        },

        updateBrowserPromptVisibility(ctx) {
            ctx.browserNotificationPermission = ctx.browserNotificationsSupported ? Notification.permission : 'unsupported';
            ctx.browserNotificationPromptVisible = Boolean(
                ctx.isAuthenticated
                && ctx.browserNotificationsSupported
                && ctx.browserNotificationPermission === 'default'
            );
        },

        isNewForClient(ctx, notification) {
            const id = String(notification?.id ?? '');
            if (!id) return false;
            if (!ctx.lastNotificationSyncAt) return false;

            const lastId = ctx.lastNotifiedNotificationId;
            if (!lastId) return true;

            const currentNum = Number(id);
            const lastNum = Number(lastId);
            if (!Number.isNaN(currentNum) && !Number.isNaN(lastNum)) {
                return currentNum > lastNum;
            }

            return id !== lastId;
        },

        handleIncoming(ctx, notifications) {
            const items = Array.isArray(notifications) ? notifications : [];
            if (!items.length) {
                ctx.lastNotificationSyncAt = Date.now();
                return;
            }

            const sorted = [...items].sort((a, b) => Number(a.id || 0) - Number(b.id || 0));
            const latest = sorted[sorted.length - 1];

            if (!ctx.lastNotificationSyncAt) {
                if (latest?.id != null) this.persistLastNotifiedId(ctx, latest.id);
                ctx.lastNotificationSyncAt = Date.now();
                return;
            }

            const fresh = sorted.filter(n => this.isNewForClient(ctx, n));
            if (!fresh.length) {
                if (latest?.id != null) this.persistLastNotifiedId(ctx, latest.id);
                ctx.lastNotificationSyncAt = Date.now();
                return;
            }

            const shouldUseBrowserNotification = ctx.browserNotificationsSupported
                && ctx.browserNotificationPermission === 'granted'
                && document.hidden;

            fresh.forEach(notification => {
                const toastKey = String(notification.id || '');
                if (!toastKey || ctx._notificationToastShownIds[toastKey]) return;
                ctx._notificationToastShownIds[toastKey] = true;

                const message = ctx.getNotificationMessage(notification);
                if (shouldUseBrowserNotification) {
                    ctx.showBrowserNotification(
                        notification.title || 'Новое уведомление',
                        message,
                        {
                            tag: `workhub-notification-${toastKey}`,
                            notificationId: toastKey,
                            data: { notificationId: toastKey }
                        }
                    );
                } else {
                    ctx.showToast(buildToastText(notification, message), notification.type === 'system' ? 'info' : (notification.type || 'info'));
                }
            });

            ctx.playNotificationSound();
            if (latest?.id != null) this.persistLastNotifiedId(ctx, latest.id);
            ctx.lastNotificationSyncAt = Date.now();
        },

        async load(ctx) {
            try {
                const data = await apiGetNotifications();
                if (data.success) {
                    const normalized = (data.data || [])
                        .map(n => ctx.normalizeNotification(n))
                        .filter(n => !(n.read ?? n.is_read));
                    ctx.handleIncomingNotifications(normalized);
                    ctx.notifications = normalized;
                    ctx.refreshNotificationCounters();
                }
            } catch (_) {
                // Тихая ошибка — уведомления не критичны
            }
        },

        async openTarget(ctx, notification) {
            const target = notification?.target || {};
            const payload = notification?.payload || {};
            const type = ctx.normalizeNotificationType(target.type || notification?.type || 'info');
            const id = target.id;

            if (type === 'chat') {
                ctx.currentView = 'chat';
                ctx.sidebarOpen = false;
                ctx.mobileMoreOpen = false;
                try {
                    if (!ctx.chatRooms?.length) await ctx.loadChatRooms();
                    const roomId = payload.room_id || payload.chat_room_id || id || notification.related_id;
                    const room = (ctx.chatRooms || []).find(r => String(r.room_id || r.id) === String(roomId));
                    if (room) await ctx.selectChatRoom(room);
                } catch (_) {}
            } else if (type === 'task' || type === 'comment') {
                ctx.currentView = 'tasks';
                const taskId = payload.task_id || id || notification.related_id;
                const task = (ctx.tasks || []).find(t => String(t.id) === String(taskId));
                if (task) ctx.openTaskModal(task);
            } else if (type === 'project') {
                ctx.currentView = 'projects';
                const projectId = payload.project_id || id || notification.related_id;
                const project = (ctx.projects || []).find(p => String(p.id) === String(projectId));
                if (project) ctx.openProjectModal(project);
            } else if (type === 'department') {
                ctx.currentView = 'departments';
                const deptId = payload.department_id || id || notification.related_id;
                const dept = (ctx.departments || []).find(d => String(d.id) === String(deptId));
                if (dept) ctx.openDepartmentModal(dept);
            } else if (type === 'mail') {
                ctx.currentView = 'mail';
                try {
                    await ctx.openMailView();
                    const folder = String(payload.folder || payload.mail_folder || 'inbox');
                    if (folder && folder !== ctx.currentMailFolder) {
                        await ctx.loadMailFromFolder(folder);
                    }
                } catch (_) {}
            } else if (type === 'files') {
                ctx.currentView = 'files';
                try {
                    await ctx.loadFiles?.();
                } catch (_) {}
            } else if (type === 'knowledge') {
                ctx.currentView = 'knowledge';
                const articleId = payload.article_id || payload.knowledge_id || id || notification.related_id;
                const article = (ctx.knowledgeArticles || []).find(a => String(a.id) === String(articleId));
                if (article) ctx.openKnowledgeModal(article);
            } else if (type === 'crm') {
                const clientId = payload.client_id || payload.contact_id || null;
                const dealId = payload.deal_id || id || null;
                if (clientId) {
                    ctx.currentView = 'crm-clients';
                    ctx.ensureCrmLoaded();
                    try {
                        await ctx.crmLoadClients();
                        await ctx.crmOpenClientDrawer(clientId);
                    } catch (_) {}
                } else if (dealId) {
                    ctx.currentView = 'crm-funnels';
                    ctx.ensureCrmLoaded();
                    try {
                        await ctx.crmLoadFunnels();
                        const deal = (ctx.crmDeals || []).find(d => String(d.id) === String(dealId));
                        if (deal) ctx.crmOpenDealModal(deal);
                    } catch (_) {}
                } else {
                    ctx.currentView = 'crm-dashboard';
                    ctx.ensureCrmLoaded();
                }
            } else if (type === 'helpdesk') {
                ctx.currentView = 'helpdesk';
                try {
                    await ctx.ensureHelpdeskLoaded();
                    const ticketId = payload.ticket_id || id || notification.related_id;
                    const ticket = (ctx.helpdeskTickets || []).find(t => String(t.id) === String(ticketId));
                    if (ticket) await ctx.openTicketDetail(ticket);
                } catch (_) {}
            } else if (type === 'conference') {
                ctx.currentView = 'conferences';
                try {
                    await ctx.loadConferences?.();
                } catch (_) {}
            }

            await ctx.markNotificationRead(notification.id);
            ctx.closeAllPanels();
        },

        async markRead(ctx, notificationId) {
            try {
                await apiMarkNotificationRead(notificationId);
                ctx.notifications = (ctx.notifications || []).filter(n => String(n.id) !== String(notificationId));
                ctx.refreshNotificationCounters();
            } catch (error) {
                console.error('Ошибка отметки уведомления:', error);
            }
        },

        async markAllRead(ctx) {
            try {
                await apiMarkAllNotificationsRead();
                ctx.notifications = [];
                ctx.refreshNotificationCounters();
            } catch (error) {
                console.error('Ошибка отметки всех уведомлений:', error);
            }
        },

        async markChatRoomRead(ctx, roomId) {
            const pending = (ctx.notifications || []).filter(n => {
                const isUnread = !(n.read ?? n.is_read);
                return isUnread && n.type === 'chat' && String(n.related_id ?? '') === String(roomId);
            });

            if (!pending.length) return;

            const requests = pending.map(n => apiMarkNotificationRead(n.id).catch(() => null));
            await Promise.all(requests);

            const pendingIds = new Set(pending.map(n => String(n.id)));
            ctx.notifications = (ctx.notifications || []).filter(n => !pendingIds.has(String(n.id)));
            ctx.refreshNotificationCounters();
        },

        startPolling(ctx) {
            ctx.startPoller('notifications', async () => {
                await ctx.loadNotifications();
            }, 15000, { immediate: true, minGapMs: 5000 });
        },

        initEnhancements(ctx) {
            ctx.browserNotificationsSupported = typeof window !== 'undefined' && 'Notification' in window;
            ctx.browserNotificationPermission = ctx.browserNotificationsSupported ? Notification.permission : 'unsupported';
            ctx.restoreLastNotifiedNotificationId();
            ctx.updateBrowserNotificationPromptVisibility();

            if (!ctx._notificationUnlockBound) {
                ctx._notificationUnlockBound = true;
                const unlock = () => ctx.unlockNotificationSound();
                window.addEventListener('pointerdown', unlock, { passive: true });
                window.addEventListener('keydown', unlock, { passive: true });
            }
        },

        showBrowser(ctx, title, body, options = {}) {
            if (!('Notification' in window)) return null;
            ctx.updateBrowserNotificationPromptVisibility();
            if (Notification.permission !== 'granted') return null;

            const notification = new Notification(title, {
                body,
                icon: 'favicon.png',
                badge: 'favicon.png',
                requireInteraction: false,
                tag: options.tag || 'workhub-notification',
                data: options.data || null
            });

            notification.onclick = () => {
                try { window.focus(); } catch (_) {}
                const notificationId = options.notificationId || options.data?.notificationId || null;
                const item = notificationId
                    ? (ctx.notifications || []).find(n => String(n.id) === String(notificationId))
                    : null;
                if (item) {
                    ctx.openNotificationTarget(item);
                } else {
                    ctx.toggleNotificationsPanel();
                }
                notification.close();
            };

            return notification;
        },

        unlockSound(ctx) {
            if (ctx._notificationSoundUnlocked) return;
            try {
                const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextCtor) return;
                const hadGesture = !!(window.__taskflowUserGestureUnlocked || ctx._notificationUnlockInProgress);
                if (!ctx._notificationAudioCtx && !hadGesture) {
                    return;
                }
                if (!ctx._notificationAudioCtx) {
                    ctx._notificationAudioCtx = new AudioContextCtor();
                }
                if (ctx._notificationAudioCtx.state === 'running') {
                    ctx._notificationSoundUnlocked = true;
                    return;
                }
                if (ctx._notificationAudioCtx.state === 'suspended') {
                    ctx._notificationAudioCtx.resume()
                        .then(() => {
                            ctx._notificationSoundUnlocked = ctx._notificationAudioCtx?.state === 'running';
                        })
                        .catch(() => {});
                }
            } catch (_) {}
        },

        playSound(ctx) {
            try {
                const audioCtx = ctx._notificationAudioCtx;
                if (!audioCtx || audioCtx.state !== 'running') return;

                const now = audioCtx.currentTime;
                const oscillator = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(880, now);
                oscillator.frequency.exponentialRampToValueAtTime(660, now + 0.12);
                gain.gain.setValueAtTime(0.0001, now);
                gain.gain.exponentialRampToValueAtTime(0.035, now + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
                oscillator.connect(gain);
                gain.connect(audioCtx.destination);
                oscillator.start(now);
                oscillator.stop(now + 0.2);
            } catch (_) {}
        },

        requestPermission(ctx, force = false) {
            if (!('Notification' in window)) {
                ctx.updateBrowserNotificationPromptVisibility();
                return Promise.resolve('unsupported');
            }

            ctx.updateBrowserNotificationPromptVisibility();
            if (Notification.permission !== 'default' && !force) {
                return Promise.resolve(Notification.permission);
            }

            return Notification.requestPermission().then((permission) => {
                ctx.browserNotificationPermission = permission;
                ctx.updateBrowserNotificationPromptVisibility();
                if (permission === 'granted') {
                    ctx.showToast('Браузерные уведомления включены', 'success');
                }
                return permission;
            }).catch(() => {
                ctx.updateBrowserNotificationPromptVisibility();
                return Notification.permission;
            });
        }
    };
})();
