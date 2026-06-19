window.TaskFlowChat = (function () {
    const CHAT_ACTIVE_ROOM_STORAGE_KEY = 'chatActiveRoomId';
    const CHAT_PAGE_SIZE = 50;

    function formatGetUserMediaErrorRu(error) {
        const name = String(error?.name || '').trim();
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
            return 'Доступ к микрофону/камере запрещён в браузере. Разрешите доступ и повторите.';
        }
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
            return 'Не найден микрофон/камера. Проверьте, что устройство подключено и доступно.';
        }
        if (name === 'NotReadableError' || name === 'TrackStartError') {
            return 'Не удалось включить микрофон/камеру (устройство занято другой программой).';
        }
        if (name === 'OverconstrainedError' || name === 'ConstraintNotSatisfiedError') {
            return 'Параметры камеры/микрофона не поддерживаются. Попробуйте другое устройство.';
        }
        if (name === 'SecurityError') {
            return 'WebRTC заблокирован политикой безопасности. Убедитесь, что сайт открыт по HTTPS.';
        }
        return error?.message ? String(error.message) : 'Не удалось получить доступ к микрофону/камере.';
    }

    function getRoomId(room) {
        return room?.room_id || room?.id || null;
    }

    function loadStoredActiveRoomId() {
        try {
            return String(localStorage.getItem(CHAT_ACTIVE_ROOM_STORAGE_KEY) || '').trim();
        } catch (_e) {
            return '';
        }
    }

    function storeActiveRoomId(roomId) {
        try {
            const id = String(roomId || '').trim();
            if (!id) {
                localStorage.removeItem(CHAT_ACTIVE_ROOM_STORAGE_KEY);
            } else {
                localStorage.setItem(CHAT_ACTIVE_ROOM_STORAGE_KEY, id);
            }
        } catch (_e) {}
    }

    function syncActiveChatRoom(ctx) {
        if (!ctx.activeChatRoom) return;

        const activeId = String(getRoomId(ctx.activeChatRoom) || '');
        const nextActive = (ctx.chatRooms || []).find((room) => String(getRoomId(room)) === activeId);
        if (nextActive) {
            ctx.activeChatRoom = nextActive;
            return;
        }

        ctx.activeChatRoom = null;
        ctx.chatMessages = [];
        ctx.lastMessageId = 0;
        ctx.lastRoomId = null;
    }

    function positionMenu(event, menuWidth, menuHeight) {
        const padding = 10;
        let x = event.clientX;
        let y = event.clientY;

        if (x + menuWidth > window.innerWidth - padding) {
            x = Math.max(padding, x - menuWidth);
        }

        if (y + menuHeight > window.innerHeight - padding) {
            y = Math.max(padding, y - menuHeight);
        }

        return { x, y };
    }

    function formatRoomId(room) {
        return String(getRoomId(room) || '');
    }

    function mergeRoomsById(prevRooms, nextRooms) {
        const prevList = Array.isArray(prevRooms) ? prevRooms : [];
        const nextList = Array.isArray(nextRooms) ? nextRooms : [];
        const prevById = new Map(prevList.map((room) => [formatRoomId(room), room]));

        return nextList.map((room) => {
            const roomId = formatRoomId(room);
            const prevRoom = prevById.get(roomId);
            if (!prevRoom) return room;

            return {
                ...prevRoom,
                ...room
            };
        });
    }

    function mergeMessages(prevMessages, nextMessages) {
        const prev = Array.isArray(prevMessages) ? prevMessages : [];
        const next = Array.isArray(nextMessages) ? nextMessages : [];
        const map = new Map(prev.map((message) => [String(message.id), message]));
        for (const message of next) {
            map.set(String(message.id), message);
        }
        return Array.from(map.values()).sort((a, b) => (a.id || 0) - (b.id || 0));
    }

    function getScrollContainer() {
        return document.getElementById('chat-messages');
    }

    function getViewportAnchor(container) {
        if (!container) return null;

        // Find the first message bubble currently visible and record its position
        // relative to the container scrollTop, so we can restore it after prepending.
        const nodes = container.querySelectorAll('[data-msg-swipe]');
        if (!nodes || !nodes.length) return null;

        const top = container.scrollTop;
        for (const el of nodes) {
            const offsetTop = el.offsetTop;
            if (offsetTop >= top - 8) {
                const id = el.getAttribute('data-msg-swipe') || '';
                if (!id) return null;
                return { id: String(id), delta: offsetTop - top };
            }
        }

        const last = nodes[nodes.length - 1];
        const lastId = last?.getAttribute?.('data-msg-swipe') || '';
        if (!lastId) return null;
        return { id: String(lastId), delta: last.offsetTop - top };
    }

    function buildPresenceMap(rows) {
        const map = {};
        for (const row of (rows || [])) {
            map[String(row.user_id)] = {
                online: !!row.is_online,
                typing: !!row.is_typing
            };
        }
        return map;
    }

    function formatLastSeenRu(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        if (Number.isNaN(d.getTime())) return '';

        const now = new Date();
        const diffMs = now - d;
        const diffMin = Math.floor(diffMs / 60000);

        if (diffMin < 1) return 'был(а) только что';
        if (diffMin < 60) return `был(а) ${diffMin} мин назад`;

        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
        const startOfThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
        if (startOfThat === startOfToday) {
            return 'был(а) сегодня в ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        }

        const startOfYesterday = startOfToday - 24 * 60 * 60 * 1000;
        if (startOfThat === startOfYesterday) {
            return 'был(а) вчера в ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        }

        return 'был(а) ' + d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' в ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }

    function stopVoiceStream(stream) {
        if (!stream) return;
        stream.getTracks().forEach((track) => track.stop());
    }

    return {
        async openCreateChatModal(ctx) {
            await ctx.loadChatUsers();
            ctx.chatUserSearch = '';
            ctx.showCreateChatModal = true;
        },

        async loadRooms(ctx) {
            const shouldShowLoader = !ctx.chatRoomsLoaded && !(Array.isArray(ctx.chatRooms) && ctx.chatRooms.length > 0);
            ctx.chatRoomsLoading = shouldShowLoader;
            ctx.chatRoomsError = '';
            try {
                const data = await apiGet('chat/rooms');
                if (data.success) {
                    const nextRooms = Array.isArray(data.data) ? data.data : [];
                    const deletedIds = new Set((ctx.deletedChatRoomIds || []).map((id) => String(id)));
                    const visibleRooms = deletedIds.size > 0
                        ? nextRooms.filter((room) => !deletedIds.has(formatRoomId(room)))
                        : nextRooms;

                    ctx.chatRooms = mergeRoomsById(ctx.chatRooms, visibleRooms);
                    ctx.chatRoomsLoaded = true;

                    if (deletedIds.size > 0) {
                        const stillPresent = ctx.chatRooms.some((room) => deletedIds.has(formatRoomId(room)));
                        if (!stillPresent) {
                            ctx.deletedChatRoomIds = [];
                        }
                    }

                    syncActiveChatRoom(ctx);

                    // Auto-select a room so chat doesn't open as an empty shell.
                    if (!ctx.activeChatRoom && ctx.chatRooms.length > 0) {
                        const storedId = loadStoredActiveRoomId();
                        const preferred = storedId
                            ? ctx.chatRooms.find((r) => String(getRoomId(r)) === storedId)
                            : null;
                        const nextRoom = preferred || ctx.chatRooms[0];
                        if (nextRoom) {
                            // fire and forget to avoid blocking rooms list render
                            this.selectRoom(ctx, nextRoom).catch(() => {});
                        }
                    }
                }
            } catch (error) {
                if (Number(error?.status) === 403) {
                    ctx.disableChatPolling();
                    return;
                }
                console.warn('Ошибка загрузки чатов:', error);
                ctx.chatRoomsError = error?.userMessage || error?.message || 'Не удалось загрузить список чатов';
            } finally {
                ctx.chatRoomsLoading = false;
            }
        },

        disablePolling(ctx) {
            this.stopPolling(ctx);
            ctx.stopPoller('chat_messages');
            ctx.stopPoller('chat_rooms');
            ctx.stopPoller('incoming_calls_visible');
            ctx._callsLongPollRunning = false;
            ctx.stopPoller('chat_presence');
            ctx.chatAccessDenied = true;
        },

        startPolling(ctx) {
            if (ctx.chatPollingInterval) return;
            if (ctx.chatAccessDenied) return;

            ctx.isChatVisible = true;
            console.log('🔄 Запуск Long Polling для чата');

            ctx.chatPollingInterval = true;
            ctx._chatLongPollRunning = true;
            this.startLongPoll(ctx);
            this.startPresencePolling(ctx);

            ctx.startPoller('chat_rooms', async () => {
                if (!ctx.isChatVisible) return;
                await ctx.loadChatRooms();
            }, 5000, { immediate: true, minGapMs: 2000 });

            // Read receipts / status sync (for Telegram-like double checks).
            ctx.startPoller('chat_room_sync', async () => {
                if (!ctx.isChatVisible) return;
                if (!ctx.activeChatRoom) return;
                if (document.hidden) return;

                // Only sync when it matters: we have recent own messages not yet marked as read.
                const recent = (ctx.chatMessages || []).slice(-30);
                const myId = String(ctx.currentUser?.id ?? '');
                const hasPending = recent.some((m) =>
                    String(m.sender_id ?? '') === myId && !m.deleted_at && String(m.status || '') !== 'read'
                );
                if (!hasPending) return;

                await this.checkNewMessages(ctx);
            }, 6000, { immediate: false, minGapMs: 2500 });

            ctx.startPoller('incoming_calls_visible', async () => {
                if (!ctx.isChatVisible) return;
                if (ctx.incomingCallModal || ctx.showCallModal) return;
                await ctx.checkIncomingCalls();
            }, 3000, { immediate: true, minGapMs: 1500 });
        },

        async startLongPoll(ctx) {
            if (ctx._chatLongPollLoopRunning) return;
            ctx._chatLongPollLoopRunning = true;

            const loop = async () => {
                let backoffMs = 0;
                while (ctx._chatLongPollRunning && ctx.isAuthenticated) {
                    try {
                        if (!ctx.isChatVisible || !ctx.activeChatRoom || document.hidden) {
                            await new Promise((resolve) => setTimeout(resolve, 800));
                            continue;
                        }

                        const roomId = ctx.activeChatRoom.room_id || ctx.activeChatRoom.id;
                        const sinceId = Number(ctx.lastMessageId || 0);
                        const timeout = 20;
                        const res = await apiGet(`chat/rooms/${roomId}/messages?since_id=${encodeURIComponent(sinceId)}&timeout=${timeout}&limit=200&offset=0`);
                        if (res?.success && Array.isArray(res.data) && res.data.length) {
                            const hadNew = res.data.some((message) => (message.id || 0) > sinceId);
                            ctx.chatMessages = mergeMessages(ctx.chatMessages, res.data);
                            ctx.lastMessageId = Math.max(ctx.lastMessageId || 0, ...res.data.map((message) => message.id || 0));

                            if (hadNew) {
                                ctx.forceChatScrollBottom();
                                setTimeout(() => ctx.forceChatScrollBottom(), 80);
                                await ctx.markChatNotificationsByRoomAsRead(roomId);
                                this.markRoomMessagesAsRead(ctx, res.data).catch(() => {});
                                if (document.hidden && res.data.some((message) => String(message.sender_id) !== String(ctx.currentUser?.id))) {
                                    ctx.showBrowserNotification(
                                        res.data[0]?.sender_name || 'Новое сообщение',
                                        res.data[0]?.message || ''
                                    );
                                }
                            }
                        }

                        // Successful request: reset backoff.
                        backoffMs = 0;
                    } catch (_error) {
                        // Exponential backoff with cap to avoid hammering the server on outages.
                        backoffMs = backoffMs ? Math.min(Math.round(backoffMs * 1.6), 15000) : 900;
                        const jitter = Math.round(Math.random() * 250);
                        await new Promise((resolve) => setTimeout(resolve, backoffMs + jitter));
                    }
                }
            };

            loop().finally(() => {
                ctx._chatLongPollLoopRunning = false;
            });
        },

        stopPolling(ctx) {
            if (ctx.chatPollingInterval) {
                ctx.chatPollingInterval = null;
                ctx._chatLongPollRunning = false;
                ctx._callsLongPollRunning = false;
                ctx.stopPoller('chat_rooms');
                ctx.stopPoller('incoming_calls_visible');
                ctx.stopPoller('chat_room_sync');
                ctx.isChatVisible = false;
                console.log('⏹️ Остановка Long Polling');
            }

            this.stopPresencePolling(ctx);
        },

        openOverlay(ctx) {
            ctx.chatOverlayOpen = false;
            ctx.isChatVisible = true;
            ctx.currentView = 'chat';
            ctx.sidebarOpen = false;
            ctx.mobileMoreOpen = false;

            this.startPolling(ctx);
            if (!ctx.chatRooms?.length) ctx.loadChatRooms();

            ctx.ensureChatAutoScroll();
            ctx.forceChatScrollBottom();
            if (ctx.activeChatRoom) {
                ctx.markChatNotificationsByRoomAsRead(ctx.activeChatRoom.room_id || ctx.activeChatRoom.id);
            }
        },

        closeOverlay(ctx) {
            ctx.chatOverlayOpen = false;
            ctx.isChatVisible = false;
            this.stopPolling(ctx);

            if (ctx.currentView === 'chat') {
                ctx.currentView = 'tasks';
            }
        },

        async checkNewMessages(ctx) {
            if (!ctx.activeChatRoom) return;
            const shouldStickToBottom = ctx.isChatNearBottom();

            try {
                const roomId = ctx.activeChatRoom.room_id || ctx.activeChatRoom.id;
                const data = await apiGet(`chat/rooms/${roomId}/messages`);

                if (data.success && data.data) {
                    const prevMessages = ctx.chatMessages || [];
                    const prevStatusById = {};
                    prevMessages.forEach((message) => {
                        prevStatusById[String(message.id)] = `${message.status}|${message.is_read}`;
                    });

                    const newMessages = data.data.filter((message) => message.id > ctx.lastMessageId);
                    const hasStatusChanges = data.data.some((message) => {
                        const idKey = String(message.id);
                        const next = `${message.status}|${message.is_read}`;
                        return prevStatusById[idKey] !== undefined && prevStatusById[idKey] !== next;
                    });
                    const hasLengthDiff = data.data.length !== prevMessages.length;

                    if (newMessages.length > 0 || hasStatusChanges || hasLengthDiff) {
                        ctx.chatMessages = data.data;
                        if (data.data.length > 0) {
                            ctx.lastMessageId = Math.max(...data.data.map((message) => message.id));
                        }

                        if (newMessages.length > 0 && (shouldStickToBottom || ctx.isChatVisible)) {
                            ctx.forceChatScrollBottom();
                            setTimeout(() => ctx.forceChatScrollBottom(), 80);
                        }

                        const container = document.getElementById('chat-messages');
                        const stuck = container?.dataset?.autoscrollStuckToBottom === '1';
                        if (stuck) {
                            ctx.forceChatScrollBottom();
                        }

                        if (ctx.chatOverlayOpen) {
                            ctx.forceChatScrollBottom();
                        }

                        await ctx.markChatNotificationsByRoomAsRead(roomId);

                        if (document.hidden && newMessages.some((message) => String(message.sender_id) !== String(ctx.currentUser?.id))) {
                            ctx.showBrowserNotification(
                                newMessages[0].sender_name || 'Новое сообщение',
                                newMessages[0].message || ''
                            );
                        }
                    }
                }
            } catch (_error) {
                // quiet (chat polling can be flaky on some hosts)
            }
        },

        startPresencePolling(ctx) {
            if (ctx._presenceInterval) return;
            ctx._presenceInterval = true;

            ctx.startPoller('chat_presence', async () => {
                if (!ctx.isChatVisible) return;

                try {
                    const roomId = ctx.activeChatRoom?.room_id || ctx.activeChatRoom?.id || null;
                    await apiPost('chat/presence', { room_id: roomId, ttl: 25 });
                } catch (error) {
                    if (Number(error?.status) === 403) {
                        this.disablePolling(ctx);
                        return;
                    }
                }

                if (!ctx.activeChatRoom) return;
                try {
                    const roomId = ctx.activeChatRoom.room_id || ctx.activeChatRoom.id;
                    const res = await apiGet(`chat/presence?room_id=${encodeURIComponent(roomId)}`);
                    if (res.success) {
                        ctx.chatPresenceByUserId = buildPresenceMap(res.data);

                        const otherOnline = (res.data || []).some((row) => String(row.user_id) !== String(ctx.currentUser?.id) && row.is_online);
                        const otherTyping = (res.data || []).some((row) => String(row.user_id) !== String(ctx.currentUser?.id) && row.is_typing);
                        ctx.activeChatRoomPresence = { otherOnline, otherTyping };
                    }
                } catch (error) {
                    if (Number(error?.status) === 403) {
                        this.disablePolling(ctx);
                    }
                }
            }, 5000, { immediate: true, minGapMs: 1500 });
        },

        stopPresencePolling(ctx) {
            if (ctx._presenceInterval) {
                ctx.stopPoller('chat_presence');
                ctx._presenceInterval = null;
            }
        },

        async searchMessages(ctx, query) {
            if (query.length < 2) {
                ctx.messageSearchResults = [];
                ctx.showSearchResults = false;
                return;
            }

            try {
                const roomId = getRoomId(ctx.activeChatRoom);
                const data = await apiGet(`chat/search?q=${encodeURIComponent(query)}&room_id=${roomId || ''}`);
                if (data.success) {
                    ctx.messageSearchResults = data.data;
                    ctx.showSearchResults = true;
                }
            } catch (error) {
                console.warn('Ошибка поиска:', error);
            }
        },

        searchRooms() {
            return true;
        },

        getFilteredRooms(ctx) {
            const rooms = Array.isArray(ctx.chatRooms) ? ctx.chatRooms : [];
            const query = String(ctx.chatSearch || '').trim().toLowerCase();
            if (!query) return rooms;

            return rooms.filter((room) => {
                const haystack = [
                    room?.interlocutor_name,
                    room?.room_name,
                    room?.last_message,
                    room?.type === 'project' ? 'проект' : '',
                    room?.type === 'group' ? 'группа' : '',
                    room?.type === 'private' ? 'личный чат' : ''
                ].filter(Boolean).join(' ').toLowerCase();

                return haystack.includes(query);
            });
        },

        formatRoomTimestamp(_ctx, dateStr) {
            if (!dateStr) return 'Нет активности';

            const parsed = new Date(dateStr);
            if (Number.isNaN(parsed.getTime())) return 'Нет активности';

            const now = new Date();
            if (parsed.toDateString() === now.toDateString()) {
                return parsed.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            }

            const diffMs = now - parsed;
            if (diffMs < 7 * 24 * 60 * 60 * 1000) {
                return parsed.toLocaleDateString('ru-RU', { weekday: 'short' });
            }

            return parsed.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
        },

        getRoomContextLabel(ctx, room) {
            if (!room) return '';
            if (room.type === 'project') return 'Проектный чат';
            if (room.type === 'group') {
                const members = Number(room.member_count || room.members_count || ctx.chatMembers?.length || 0);
                return members > 0 ? `Группа - ${members} участников` : 'Групповой чат';
            }

            // Private: Telegram-like status line.
            if (room.type === 'private') {
                const typing = !!ctx.activeChatRoomPresence?.otherTyping;
                if (typing) return 'печатает…';
                const online = !!ctx.activeChatRoomPresence?.otherOnline;
                if (online) return 'онлайн';
                return formatLastSeenRu(room.interlocutor_last_activity) || 'не в сети';
            }

            return room.interlocutor_name ? 'Личный чат' : '';
        },

        async selectRoom(ctx, room) {
            ctx.activeChatRoom = room;
            storeActiveRoomId(getRoomId(room));
            ctx.chatRoomMessagesLoading = true;
            ctx.chatRoomMessagesError = '';
            ctx.chatHistoryLoading = false;
            ctx.chatHasMoreHistory = true;
            ctx.chatMessagesOffset = 0;
            ctx.replyToMessage = null;
            ctx.editingMessage = null;
            ctx.forwardingMessage = null;
            ctx.messageSearchQuery = '';
            ctx.showSearchResults = false;
            ctx.showEmojiPicker = false;
            ctx.composerMediaTab = 'emoji';
            ctx.$nextTick(() => ctx.resetChatTextareaHeight());

            try {
                const roomId = getRoomId(room);
                const data = await apiGet(`chat/rooms/${roomId}/messages?limit=${CHAT_PAGE_SIZE}&offset=0`);
                if (data.success) {
                    ctx.chatMessages = data.data || [];
                    ctx.chatMessagesOffset = Array.isArray(ctx.chatMessages) ? ctx.chatMessages.length : 0;
                    ctx.chatHasMoreHistory = Array.isArray(data.data) ? (data.data.length >= CHAT_PAGE_SIZE) : false;
                    if (data.data && data.data.length > 0) {
                        ctx.lastMessageId = Math.max(...data.data.map((message) => message.id));
                        ctx.lastRoomId = roomId;
                    }
                    if (room.type === 'group' || room.type === 'project') {
                        await this.loadMembers(ctx, roomId);
                    }

                    ctx.startPresencePolling();
                    ctx.$nextTick(() => {
                        const container = document.getElementById('chat-messages');
                        if (container) {
                            container.scrollTop = container.scrollHeight;
                        }
                    });

                    ctx.ensureChatAutoScroll();
                    ctx.forceChatScrollBottom();
                    await ctx.markChatNotificationsByRoomAsRead(roomId);
                    // Mark incoming messages as read on server (read receipts/cleanup for recipient rows).
                    this.markRoomMessagesAsRead(ctx, ctx.chatMessages).catch(() => {});

                    ctx._chatLongPollRunning = true;
                    ctx.startChatLongPoll();
                }
            } catch (error) {
                console.error('Ошибка загрузки сообщений:', error);
                ctx.chatMessages = [];
                ctx.chatMembers = [];
                ctx.chatRoomMessagesError = error?.userMessage || error?.message || 'Не удалось загрузить сообщения';
                ctx.showToast('Ошибка загрузки сообщений', 'error');
            } finally {
                ctx.chatRoomMessagesLoading = false;
            }
        },

        async loadOlderMessages(ctx) {
            if (ctx.chatRoomMessagesLoading || ctx.chatHistoryLoading) return false;
            if (!ctx.activeChatRoom) return false;
            if (!ctx.chatHasMoreHistory) return false;

            const roomId = getRoomId(ctx.activeChatRoom);
            if (!roomId) return false;

            const container = getScrollContainer();
            const prevScrollHeight = container ? container.scrollHeight : 0;
            const prevScrollTop = container ? container.scrollTop : 0;
            const anchor = getViewportAnchor(container);

            ctx.chatHistoryLoading = true;
            try {
                const offset = Number(ctx.chatMessagesOffset || 0);
                const res = await apiGet(`chat/rooms/${roomId}/messages?limit=${CHAT_PAGE_SIZE}&offset=${encodeURIComponent(offset)}`);
                if (!res?.success) {
                    return false;
                }

                const older = Array.isArray(res.data) ? res.data : [];
                if (!older.length) {
                    ctx.chatHasMoreHistory = false;
                    return true;
                }

                ctx.chatMessages = mergeMessages(older, ctx.chatMessages);
                ctx.chatMessagesOffset = Number(ctx.chatMessagesOffset || 0) + older.length;
                if (older.length < CHAT_PAGE_SIZE) {
                    ctx.chatHasMoreHistory = false;
                }

                // keep viewport anchored
                ctx.$nextTick(() => {
                    const el = getScrollContainer();
                    if (!el) return;

                    if (anchor?.id) {
                        const target = el.querySelector(`[data-msg-swipe="${anchor.id}"]`) || document.getElementById(`chat-msg-${anchor.id}`);
                        if (target) {
                            el.scrollTop = target.offsetTop - Number(anchor.delta || 0);
                            return;
                        }
                    }

                    // Fallback: keep by scrollHeight delta.
                    const nextScrollHeight = el.scrollHeight;
                    el.scrollTop = prevScrollTop + (nextScrollHeight - prevScrollHeight);
                });

                return true;
            } catch (_e) {
                return false;
            } finally {
                ctx.chatHistoryLoading = false;
            }
        },

        async deleteRoom(ctx, room) {
            const targetRoom = room || ctx.chatRoomMenuRoom || ctx.activeChatRoom;
            const roomId = getRoomId(targetRoom);
            if (!roomId) return;

            const roomTitle = targetRoom?.interlocutor_name || targetRoom?.room_name || 'этот чат';
            ctx.openConfirm(
                'Удалить чат?',
                `Чат «${roomTitle}» будет удалён вместе с историей сообщений без возможности восстановления.`,
                async () => {
                    const previousActiveRoomId = String(getRoomId(ctx.activeChatRoom) || '');
                    const result = await apiDelete(`chat/rooms/${roomId}`);
                    if (!result?.success) {
                        throw new Error(result?.error || 'Не удалось удалить чат');
                    }

                    const deletedId = String(roomId);
                    this.closeRoomMenu(ctx);
                    ctx.deletedChatRoomIds = Array.from(new Set([...(ctx.deletedChatRoomIds || []), deletedId]));
                    ctx.chatRooms = (ctx.chatRooms || []).filter((item) => String(getRoomId(item)) !== deletedId);
                    if (String(getRoomId(ctx.activeChatRoom) || '') === deletedId) {
                        ctx.activeChatRoom = null;
                        ctx.chatMessages = [];
                        ctx.lastMessageId = 0;
                        ctx.lastRoomId = null;
                        ctx.replyToMessage = null;
                        ctx.editingMessage = null;
                        ctx.forwardingMessage = null;
                    }

                    await this.loadRooms(ctx);
                    if (previousActiveRoomId && previousActiveRoomId !== deletedId) {
                        syncActiveChatRoom(ctx);
                    }
                    ctx.showToast('Чат удалён', 'success');
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        scrollToBottom(ctx, force = false) {
            ctx.$nextTick(() => {
                const container = document.getElementById('chat-messages');
                if (!container) return;

                const stuck = container?.dataset?.autoscrollStuckToBottom === '1';
                const distance = container.scrollHeight - container.scrollTop - container.clientHeight;
                const isNearBottom = distance < 300;
                if (!force && !stuck && !isNearBottom) return;

                const anchor = document.getElementById('chat-bottom-anchor');
                if (anchor) {
                    requestAnimationFrame(() => {
                        try {
                            anchor.scrollIntoView({ block: 'end', behavior: 'smooth' });
                        } catch (_) {}
                    });
                }

                setTimeout(() => {
                    const targetScroll = container.scrollHeight;
                    const startScroll = container.scrollTop;
                    const delta = targetScroll - startScroll;
                    const duration = 250;
                    const startTime = performance.now();

                    const animate = (currentTime) => {
                        const elapsed = currentTime - startTime;
                        const progress = Math.min(elapsed / duration, 1);
                        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
                        container.scrollTop = startScroll + delta * easeOutQuart;
                        if (progress < 1) {
                            requestAnimationFrame(animate);
                        }
                    };

                    requestAnimationFrame(animate);
                }, 30);
            });
        },

        isNearBottom(_ctx, threshold = 300) {
            const container = document.getElementById('chat-messages');
            if (!container) return true;
            const distance = container.scrollHeight - container.scrollTop - container.clientHeight;
            return distance < threshold;
        },

        forceScrollBottom(ctx) {
            this.scrollToBottom(ctx, true);
        },

        ensureAutoScroll(ctx) {
            ctx.$nextTick(() => {
                const container = document.getElementById('chat-messages');
                if (!container) return;

                const anchor = document.getElementById('chat-bottom-anchor');
                if (anchor) {
                    try {
                        container.scrollTop = container.scrollHeight;
                        anchor.scrollIntoView({ block: 'end' });
                    } catch (_) {}
                }

                const stickThreshold = 260;
                container.dataset.autoscrollStickThreshold = String(stickThreshold);

                if (ctx._chatAutoScrollObserver) {
                    try {
                        ctx._chatAutoScrollObserver.disconnect();
                    } catch (_) {}
                }

                const observer = new MutationObserver(() => {
                    const threshold = Number(container.dataset.autoscrollStickThreshold || stickThreshold);
                    const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;

                    if (distanceFromBottom < threshold) {
                        this.scrollToBottom(ctx, true);
                    }
                });

                observer.observe(container, { childList: true, subtree: true });
                ctx._chatAutoScrollObserver = observer;

                if (!ctx._chatMediaLoadHandler) {
                    ctx._chatMediaLoadHandler = (event) => {
                        const target = event?.target;
                        if (!target || target.tagName !== 'IMG') return;
                        const threshold = Number(container.dataset.autoscrollStickThreshold || stickThreshold);
                        const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                        if (distanceFromBottom < threshold) {
                            this.scrollToBottom(ctx, true);
                        }
                    };
                }

                container.removeEventListener('load', ctx._chatMediaLoadHandler, true);
                container.addEventListener('load', ctx._chatMediaLoadHandler, true);
                container.onscroll = () => {
                    const threshold = Number(container.dataset.autoscrollStickThreshold || stickThreshold);
                    const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                    container.dataset.autoscrollStuckToBottom = distanceFromBottom < threshold ? '1' : '0';
                };

                if (!container.dataset.autoscrollStuckToBottom) {
                    container.dataset.autoscrollStuckToBottom = this.isNearBottom(ctx, stickThreshold) ? '1' : '0';
                }

                if (ctx._chatAutoScrollStuckInterval) {
                    clearInterval(ctx._chatAutoScrollStuckInterval);
                }
                ctx._chatAutoScrollStuckInterval = setInterval(() => {
                    try {
                        const threshold = Number(container.dataset.autoscrollStickThreshold || stickThreshold);
                        const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                        container.dataset.autoscrollStuckToBottom = distanceFromBottom < threshold ? '1' : '0';
                    } catch (_) {}
                }, 400);

                if (container.dataset.autoscrollStuckToBottom === '1') {
                    this.forceScrollBottom(ctx);
                    setTimeout(() => this.forceScrollBottom(ctx), 600);
                    setTimeout(() => this.forceScrollBottom(ctx), 1400);
                }
            });
        },

        async loadMembers(ctx, roomId) {
            try {
                const data = await apiGet(`chat/members/${roomId}`);
                if (data.success) {
                    ctx.chatMembers = data.data;
                }
            } catch (error) {
                console.warn('Ошибка загрузки участников:', error);
            }
        },

        formatChatTime(_ctx, dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diff = now - date;

            if (diff < 60000) return 'только что';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'м назад';
            if (diff < 86400000) return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
        },

        formatMessageTime(_ctx, dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        },

        formatTime(ctx, dateStr) {
            return this.formatMessageTime(ctx, dateStr);
        },

        formatChatDateChip(_ctx, dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const startOfYesterday = new Date(startOfToday);
            startOfYesterday.setDate(startOfYesterday.getDate() - 1);
            const startOfDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

            if (startOfDate.getTime() === startOfToday.getTime()) return 'Сегодня';
            if (startOfDate.getTime() === startOfYesterday.getTime()) return 'Вчера';
            return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
        },

        buildTimeline(ctx, messages) {
            const out = [];
            let lastKey = null;
            let prevMsg = null;
            for (const msg of (messages || [])) {
                const d = msg?.created_at ? new Date(msg.created_at) : null;
                const key = d
                    ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
                    : 'unknown';

                if (key !== lastKey) {
                    out.push({ type: 'date', key, label: this.formatChatDateChip(ctx, msg.created_at) });
                    lastKey = key;
                    prevMsg = null;
                }

                const isOwn = this.isMessageOwn(ctx, msg);
                let showAuthor = !isOwn;
                let groupedWithPrev = false;
                if (!isOwn && prevMsg) {
                    const sameSender = String(prevMsg.sender_id ?? '') === String(msg.sender_id ?? '');
                    const prevTime = prevMsg.created_at ? new Date(prevMsg.created_at).getTime() : 0;
                    const curTime = msg.created_at ? new Date(msg.created_at).getTime() : 0;
                    const closeInTime = prevTime && curTime ? (curTime - prevTime) <= 5 * 60 * 1000 : false;

                    groupedWithPrev = sameSender && closeInTime;
                    if (groupedWithPrev) {
                        showAuthor = false;
                    }
                }

                out.push({ type: 'msg', key: `m_${msg.id}`, msg, showAuthor, groupedWithPrev });
                prevMsg = msg;
            }
            return out;
        },

        getTimeline(ctx) {
            return this.buildTimeline(ctx, ctx.chatMessages);
        },

        isMessageOwn(ctx, msg) {
            if (!msg) return false;

            if (msg.is_own === true || msg.is_own === 1 || msg.is_own === '1') return true;
            if (msg.is_own === false || msg.is_own === 0 || msg.is_own === '0') return false;

            return String(msg.sender_id ?? '') === String(ctx.currentUser?.id ?? '');
        },

        formatVoiceDuration(_ctx, seconds) {
            if (!seconds) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        },

        async startVoiceRecording(ctx) {
            if (!ctx.activeChatRoom) return;
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                ctx.voiceMediaRecorder = new MediaRecorder(stream);
                ctx.voiceChunks = [];
                ctx.voiceRecordTime = 0;
                ctx.recordingVoice = true;

                ctx.voiceMediaRecorder.ondataavailable = (event) => {
                    if (event.data.size > 0) {
                        ctx.voiceChunks.push(event.data);
                    }
                };

                ctx.voiceMediaRecorder.onstop = async () => {
                    const blob = new Blob(ctx.voiceChunks, { type: 'audio/webm' });
                    const duration = ctx.voiceRecordTime;
                    const waveform = Array.from({ length: 20 }, () => Math.random() * 100);
                    const reader = new FileReader();

                    reader.onloadend = async () => {
                        const formData = new FormData();
                        formData.append('audio', blob, 'voice.webm');
                        formData.append('room_id', getRoomId(ctx.activeChatRoom));
                        formData.append('duration', Math.max(1, Number(duration) || 0));
                        formData.append('waveform', JSON.stringify(waveform));

                        const token = getToken();
                        const url = `api/chat/voice?_t=${Date.now()}&token=${encodeURIComponent(token)}`;
                        const requestOptions = {
                            method: 'POST',
                            headers: { 'Authorization': `Bearer ${token}` },
                            body: formData
                        };

                        const data = typeof window.fetchJsonOrThrow === 'function'
                            ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось отправить голосовое сообщение')
                            : await (await fetch(url, requestOptions)).json();

                        if (data.success) {
                            ctx.voiceChunks = [];
                            ctx.voiceRecordTime = 0;
                            await ctx.selectChatRoom(ctx.activeChatRoom);
                            await ctx.loadChatRooms();
                        }
                    };
                    reader.readAsDataURL(blob);

                    stopVoiceStream(stream);
                };

                ctx.voiceMediaRecorder.start();
                ctx.voiceRecordInterval = setInterval(() => {
                    ctx.voiceRecordTime++;
                }, 1000);
            } catch (error) {
                console.error('Ошибка записи голоса:', error);
                ctx.recordingVoice = false;
                ctx.showToast('Нет доступа к микрофону', 'error');
            }
        },

        stopVoiceRecording(ctx) {
            if (ctx.voiceMediaRecorder && ctx.recordingVoice) {
                ctx.voiceMediaRecorder.stop();
                ctx.recordingVoice = false;
                clearInterval(ctx.voiceRecordInterval);
            }
        },

        cancelVoiceRecording(ctx) {
            if (ctx.voiceMediaRecorder) {
                ctx.voiceMediaRecorder.stop();
                ctx.recordingVoice = false;
                clearInterval(ctx.voiceRecordInterval);
                ctx.voiceChunks = [];
                ctx.voiceRecordTime = 0;
            }
        },

        toggleVoicePlayback(ctx, msg) {
            if (ctx.playingVoiceId === msg.id) {
                this.stopVoicePlayback(ctx);
                return;
            }
            this.playVoiceMessage(ctx, msg);
        },

        playVoiceMessage(ctx, msg) {
            try {
                this.stopVoicePlayback(ctx);

                const fileUrl = msg.file_url || msg.voice_file_url || `uploads/voice/voice_${msg.id}.webm`;
                const fullUrl = fileUrl.startsWith('http') || fileUrl.startsWith('/') ? fileUrl : fileUrl;
                ctx.voiceAudioPlayer = new Audio(fullUrl);

                ctx.voiceAudioPlayer.onended = () => {
                    ctx.playingVoiceId = null;
                    ctx.voiceAudioPlayer = null;
                };

                ctx.voiceAudioPlayer.onerror = (event) => {
                    console.error('Ошибка воспроизведения голоса:', event);
                    ctx.showToast('Ошибка воспроизведения голоса', 'error');
                    ctx.playingVoiceId = null;
                };

                ctx.playingVoiceId = msg.id;
                ctx.voiceAudioPlayer.play();
            } catch (error) {
                console.error('Ошибка воспроизведения:', error);
                ctx.showToast('Ошибка воспроизведения голоса', 'error');
                ctx.playingVoiceId = null;
            }
        },

        stopVoicePlayback(ctx) {
            if (ctx.voiceAudioPlayer) {
                ctx.voiceAudioPlayer.pause();
                ctx.voiceAudioPlayer = null;
            }
            ctx.playingVoiceId = null;
        },

        getMessageStatusIcon(ctx, msg) {
            if (!this.isMessageOwn(ctx, msg)) return '';

            if (msg.status === 'read' || msg.is_read) {
                return '<svg class="w-4 h-4 crm-text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13l4 4L23 7"/></svg>';
            } else if (msg.status === 'delivered') {
                return '<svg class="w-4 h-4 crm-text-success" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
            }

            return '<svg class="w-4 h-4 crm-text-tertiary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
        },

        async loadUsers(ctx) {
            try {
                const data = await apiGet('chat/users');
                if (data.success) {
                    ctx.chatUsers = data.data.filter((u) => u.id !== ctx.currentUser?.id);
                }
            } catch (error) {
                console.warn('Ошибка загрузки пользователей:', error);
            }
        },

        async startPrivateChatWith(ctx, userId) {
            const uid = parseInt(userId, 10);
            if (!uid) return;

            const roomId = await this.createRoom(ctx, uid, 'private');
            if (!roomId) return;

            ctx.showCreateChatModal = false;
            ctx.chatUserSearch = '';
            const room = (ctx.chatRooms || []).find((item) => formatRoomId(item) === String(roomId));
            if (room) {
                await this.selectRoom(ctx, room);
            }
        },

        async createRoom(ctx, userId, type = 'private') {
            try {
                const result = await apiPost('chat/rooms', { user_id: userId, type });
                if (result.success) {
                    await this.loadRooms(ctx);
                    return result.data.room_id;
                }
            } catch (error) {
                console.error('Ошибка создания чата:', error);
            }
            return null;
        },

        async createGroupChat(ctx, name, memberIds) {
            try {
                const result = await apiPost('chat/rooms', {
                    type: 'group',
                    name,
                    members: memberIds
                });
                if (result.success) {
                    await this.loadRooms(ctx);
                    return result.data.room_id;
                }
            } catch (error) {
                console.error('Ошибка создания группы:', error);
            }
            return null;
        },

        async sendMessage(ctx, messageType = 'text', extraData = {}) {
            if (!ctx.activeChatRoom) return;

            const message = messageType === 'text'
                ? this.normalizeTextForStorage(ctx, ctx.chatMessage.trim())
                : (extraData.message || '');

            if (!message && messageType === 'text') return;
            if (ctx.chatSending) return;

            try {
                ctx.chatSending = true;
                const payload = {
                    room_id: getRoomId(ctx.activeChatRoom),
                    message,
                    message_type: messageType,
                    ...extraData
                };

                if (ctx.replyToMessage) {
                    payload.reply_to_id = ctx.replyToMessage.id;
                }

                if (ctx.editingMessage) {
                    await ctx.editMessage(ctx.editingMessage.id, message);
                    return;
                }

                const result = await apiPost('chat/messages', payload);
                if (result.success) {
                    const sentId = Number(result?.data?.id || 0);
                    ctx.chatMessage = '';
                    ctx.replyToMessage = null;
                    ctx.editingMessage = null;
                    ctx.showEmojiPicker = false;
                    ctx.showAttachmentMenu = false;

                    // Optimistic local append to avoid extra refetches.
                    try {
                        const nowIso = new Date().toISOString();
                        const localMsg = {
                            id: sentId || (Number(ctx.lastMessageId || 0) + 1),
                            room_id: getRoomId(ctx.activeChatRoom),
                            sender_id: ctx.currentUser?.id,
                            sender_name: ctx.currentUser?.full_name || ctx.currentUser?.login || 'Вы',
                            message,
                            message_type: messageType,
                            created_at: nowIso,
                            status: 'delivered',
                            is_read: 0,
                            is_own: true,
                            ...extraData
                        };
                        ctx.chatMessages = mergeMessages(ctx.chatMessages, [localMsg]);
                        ctx.lastMessageId = Math.max(Number(ctx.lastMessageId || 0), Number(localMsg.id || 0));

                        // Update rooms list preview without full reload.
                        const roomId = String(getRoomId(ctx.activeChatRoom) || '');
                        if (roomId) {
                            ctx.chatRooms = (ctx.chatRooms || []).map((r) => {
                                if (String(getRoomId(r) || '') !== roomId) return r;
                                return {
                                    ...r,
                                    last_message: messageType === 'text' ? message : (extraData?.message || r.last_message || ''),
                                    last_message_time: nowIso,
                                    unread_count: 0
                                };
                            });
                        }
                    } catch (_e) {}

                    ctx.$nextTick(() => {
                        ctx.resetChatTextareaHeight();
                        ctx.forceChatScrollBottom();
                    });
                } else {
                    ctx.showToast(result.error || 'Ошибка отправки', 'error');
                }
            } catch (error) {
                console.error('Ошибка отправки сообщения:', error);
                ctx.showToast('Ошибка отправки сообщения', 'error');
            } finally {
                ctx.chatSending = false;
            }
        },

        async markRoomAsRead(ctx, room) {
            const targetRoom = room || ctx.chatRoomMenuRoom || ctx.activeChatRoom;
            const roomId = getRoomId(targetRoom);
            if (!roomId) return;

            try {
                await ctx.markChatNotificationsByRoomAsRead(roomId);

                ctx.chatRooms = (ctx.chatRooms || []).map((item) => {
                    if (String(getRoomId(item)) !== String(roomId)) return item;
                    return { ...item, unread_count: 0 };
                });

                if (ctx.activeChatRoom && String(getRoomId(ctx.activeChatRoom)) === String(roomId)) {
                    ctx.activeChatRoom = { ...ctx.activeChatRoom, unread_count: 0 };
                }
            } catch (error) {
                console.warn('Ошибка пометки чата прочитанным:', error);
            }
        },

        notifyTyping(ctx) {
            if (!ctx.activeChatRoom || ctx.typingTimeout) return;

            apiPost('chat/typing', {
                room_id: getRoomId(ctx.activeChatRoom)
            }).catch(() => {});

            ctx.typingTimeout = setTimeout(() => {
                ctx.typingTimeout = null;
            }, 3000);
        },

        handleComposerKeydown(ctx, event) {
            const e = event;
            if (!e || e.key !== 'Enter') return;

            // Default behavior we want:
            // - Enter: send
            // - Shift+Enter: newline
            // - Ctrl/Cmd+Enter: send (common pattern)
            const wantsNewline = e.shiftKey && !e.ctrlKey && !e.metaKey;
            const wantsSend = !e.shiftKey || e.ctrlKey || e.metaKey;

            if (wantsNewline) {
                e.preventDefault();
                // Shift+Enter: keep newline and resize.
                try {
                    ctx.chatMessage = String(ctx.chatMessage || '') + '\n';
                    ctx.$nextTick(() => {
                        const textarea = document.querySelector('textarea[x-model="chatMessage"]');
                        if (textarea) {
                            ctx.autoResize(textarea);
                            try {
                                const len = textarea.value.length;
                                textarea.selectionStart = textarea.selectionEnd = len;
                            } catch (_) {}
                        }
                    });
                } catch (_err) {}
                return;
            }

            if (!wantsSend) return;

            e.preventDefault();

            ctx.sendChatMessage();
        },

        showIncomingCall(ctx, callerName, callType = 'audio', roomId = null) {
            try {
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission();
                }
            } catch (_) {}

            ctx.incomingCaller = {
                name: callerName,
                type: callType,
                roomId: roomId,
                call_id: null
            };

            const shouldShowModal = ctx.isChatOverlayActiveForCalls();
            ctx.incomingCallModal = shouldShowModal;
            if (!shouldShowModal) {
                ctx.showCallBanner({ caller_name: callerName, call_type: callType, room_id: roomId });
            }

            this.playCallSound(ctx);
        },

        playCallSound(ctx) {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2teleQQAKZXZ8NOmdgYALJXa8NOmeAYAK5Xa8NOmeAYAK5Xa8NOmeAYAK5Xa8NOmeAYAK5Xa8NOmeAYAK5Xa8NOmeA==');
            audio.loop = true;
            audio.play().catch(() => {});
            ctx.callSound = audio;
        },

        stopCallSound(ctx) {
            if (ctx.callSound) {
                ctx.callSound.pause();
                ctx.callSound = null;
            }
            if (navigator.vibrate) {
                navigator.vibrate(0);
            }
        },

        formatCallTime(_ctx, seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        },

        startBackgroundCallPolling(ctx) {
            if (ctx._callsLongPollRunning) return;
            ctx._callsLongPollRunning = true;
            this.startCallsLongPoll(ctx);
        },

        async startCallsLongPoll(ctx) {
            if (ctx._callsLongPollLoopRunning) return;
            ctx._callsLongPollLoopRunning = true;

            const loop = async () => {
                while (ctx._callsLongPollRunning && ctx.isAuthenticated) {
                    try {
                        if (ctx.incomingCallModal || ctx.showCallModal) {
                            await new Promise((resolve) => setTimeout(resolve, 600));
                            continue;
                        }

                        const timeout = 20;
                        const res = await apiGet(`chat/calls?timeout=${timeout}`);
                        if (res?.success && res.data) {
                            ctx.incomingCaller = {
                                name: res.data.caller_name,
                                type: res.data.call_type,
                                roomId: res.data.room_id,
                                call_id: res.data.call_id
                            };
                            const shouldShowModal = ctx.isChatOverlayActiveForCalls();
                            ctx.incomingCallModal = shouldShowModal;

                            if (shouldShowModal) {
                                ctx.clearCallToasts();
                            } else {
                                ctx.showCallBanner(res.data);
                            }

                            if (!shouldShowModal && document.hidden && Notification.permission === 'granted') {
                                try {
                                    new Notification(res.data.caller_name || 'Входящий звонок', {
                                        body: res.data.call_type === 'video' ? 'Видеозвонок' : 'Аудиозвонок',
                                        icon: '/favicon.ico',
                                        tag: 'workhub-call',
                                        requireInteraction: true
                                    });
                                } catch (_) {}
                            }

                            ctx.playCallSound();
                        }
                    } catch (error) {
                        if (Number(error?.status) === 403) {
                            ctx.disableChatPolling();
                            break;
                        }
                        await new Promise((resolve) => setTimeout(resolve, 1200));
                    }
                }
            };

            loop().finally(() => {
                ctx._callsLongPollLoopRunning = false;
            });
        },

        async checkIncomingCalls(ctx) {
            try {
                const data = await apiGet('chat/calls');
                if (data.success && data.data) {
                    ctx.incomingCaller = {
                        name: data.data.caller_name,
                        type: data.data.call_type,
                        roomId: data.data.room_id,
                        call_id: data.data.call_id
                    };
                    const shouldShowModal = ctx.isChatOverlayActiveForCalls();
                    ctx.incomingCallModal = shouldShowModal;

                    if (shouldShowModal) {
                        ctx.clearCallToasts();
                    } else {
                        ctx.showCallBanner(data.data);
                    }

                    if (!shouldShowModal && document.hidden && Notification.permission === 'granted') {
                        try {
                            new Notification(data.data.caller_name || 'Входящий звонок', {
                                body: data.data.call_type === 'video' ? 'Видеозвонок' : 'Аудиозвонок',
                                icon: '/favicon.ico',
                                tag: 'workhub-call',
                                requireInteraction: true
                            });
                        } catch (_) {}
                    }

                    ctx.playCallSound();
                }
            } catch (error) {
                if (Number(error?.status) === 403) {
                    ctx.disableChatPolling();
                }
            }
        },

        async acceptIncomingCall(ctx) {
            if (!ctx.incomingCaller) return;

            try {
                const callId = ctx.incomingCaller.call_id;
                if (!callId) {
                    ctx.showToast('Не найден ID звонка', 'error');
                    return;
                }

                const result = await apiPut(`chat/calls/${callId}`, { status: 'accepted' });
                if (result.success) {
                    ctx.incomingCallModal = false;
                    ctx.clearCallToasts();
                    ctx.showCallModal = true;
                    ctx.callType = ctx.incomingCaller?.type || 'audio';
                    ctx.callStatus = 'calling';
                    ctx.callTimer = 0;
                    ctx.callPendingIce = [];

                    let stream;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            audio: true,
                            video: ctx.callType === 'video'
                        });
                    } catch (e) {
                        const msg = formatGetUserMediaErrorRu(e);
                        try {
                            ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'getUserMedia.failed', lastError: msg };
                        } catch (_) {}
                        ctx.showToast(msg, 'error');
                        throw e;
                    }
                    ctx.callLocalStream = stream;

                    try {
                        stream.getAudioTracks().forEach((track) => {
                            track.enabled = true;
                        });
                    } catch (_) {}

                    const pc = new RTCPeerConnection({
                        iceServers: Array.isArray(ctx.webrtcIceServers) && ctx.webrtcIceServers.length
                            ? ctx.webrtcIceServers
                            : [{ urls: 'stun:stun.l.google.com:19302' }]
                    });
                    ctx.callPeerConnection = pc;

                    const syncDebug = (label, error = null) => {
                        try {
                            ctx.callDebug = {
                                iceConnectionState: pc.iceConnectionState,
                                connectionState: pc.connectionState,
                                signalingState: pc.signalingState,
                                iceGatheringState: pc.iceGatheringState,
                                lastEvent: String(label || ''),
                                lastError: error ? String(error?.message || error) : (ctx.callDebug?.lastError || '')
                            };
                        } catch (_) {}
                    };

                    syncDebug('pc.created');
                    pc.onconnectionstatechange = () => syncDebug('connectionState');
                    pc.onsignalingstatechange = () => syncDebug('signalingState');
                    pc.onicegatheringstatechange = () => syncDebug('iceGatheringState');

                    pc.oniceconnectionstatechange = () => {
                        syncDebug('iceConnectionState');
                        try {
                            if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                                ctx.showToast('Проблема соединения (ICE). Нажмите «Повторить подключение».', 'info');
                            }
                        } catch (_) {}
                    };

                    try {
                        pc.addTransceiver('audio', { direction: 'sendrecv' });
                        if (ctx.callType === 'video') {
                            pc.addTransceiver('video', { direction: 'sendrecv' });
                        }
                    } catch (_) {}

                    stream.getTracks().forEach((track) => pc.addTrack(track, stream));

                    pc.ontrack = (event) => {
                        if (!ctx.callRemoteStream) {
                            ctx.callRemoteStream = new MediaStream();
                        }
                        ctx.callRemoteStream.addTrack(event.track);
                        ctx.$nextTick(() => {
                            const remoteAudio = document.getElementById('remote-audio');
                            if (remoteAudio) {
                                remoteAudio.srcObject = ctx.callRemoteStream;
                                remoteAudio.play?.().catch(() => {});
                            }
                            const remoteVideo = document.getElementById('remote-video');
                            if (remoteVideo && ctx.callType === 'video') {
                                remoteVideo.srcObject = ctx.callRemoteStream;
                                remoteVideo.play?.().catch(() => {});
                            }
                        });
                    };

                    pc.onicecandidate = async (event) => {
                        if (!event.candidate || !callId) return;
                        try {
                            await apiPost('chat/webrtc', {
                                call_id: callId,
                                type: 'ice',
                                payload: event.candidate
                            });
                        } catch (_) {}
                    };

                    ctx.$nextTick(() => {
                        const localVideo = document.getElementById('local-video');
                        if (localVideo && ctx.callType === 'video') {
                            localVideo.srcObject = stream;
                        }
                    });

                    ctx.currentCallId = callId;
                    this.startWebrtcPolling(ctx);

                    ctx.$nextTick(() => {
                        const remoteAudio = document.getElementById('remote-audio');
                        if (remoteAudio) {
                            remoteAudio.muted = false;
                            remoteAudio.play?.().catch(() => {});
                        }
                    });

                    ctx.incomingCaller = null;
                    ctx.stopCallSound();

                    ctx.callTimerInterval = setInterval(() => {
                        if (ctx.callStatus === 'connected') ctx.callTimer++;
                    }, 1000);
                }
            } catch (error) {
                console.error('Ошибка принятия звонка:', error);
                ctx.showToast('Ошибка принятия звонка', 'error');
            }
        },

        async declineIncomingCall(ctx) {
            if (ctx.incomingCaller?.call_id) {
                try {
                    await apiPut(`chat/calls/${ctx.incomingCaller.call_id}`, { status: 'declined' });
                } catch (error) {
                    console.error('Ошибка отклонения звонка:', error);
                }
            }

            ctx.incomingCallModal = false;
            ctx.clearCallToasts();
            ctx.incomingCaller = null;
            ctx.stopCallSound();
            ctx.showToast('Звонок отклонён', 'error');
        },

        async applyPendingIceCandidates(ctx) {
            const pc = ctx.callPeerConnection;
            if (!pc || !pc.remoteDescription || !ctx.callPendingIce?.length) return;
            const pending = [...ctx.callPendingIce];
            ctx.callPendingIce = [];
            for (const candidate of pending) {
                try {
                    await pc.addIceCandidate(candidate);
                } catch (_) {}
            }
        },

        startAudioCall(ctx) {
            if (!ctx.activeChatRoom) return;
            ctx.callType = 'audio';
            this.initCall(ctx);
        },

        startVideoCall(ctx) {
            if (!ctx.activeChatRoom) return;
            ctx.callType = 'video';
            this.initCall(ctx);
        },

        async initCall(ctx) {
            ctx.callStatus = 'calling';
            ctx.callTimer = 0;
            ctx.isMuted = false;
            ctx.isCameraOff = false;
            ctx.callPendingIce = [];

            if (ctx.callPeerConnection) {
                try { ctx.callPeerConnection.close(); } catch (_) {}
                ctx.callPeerConnection = null;
            }

            const recipientId = ctx.activeChatRoom.interlocutor_id;
            if (!recipientId) {
                ctx.showToast('Невозможно определить собеседника', 'error');
                return;
            }

            try {
                const callData = await apiPost('chat/calls', {
                    recipient_id: recipientId,
                    call_type: ctx.callType,
                    room_id: ctx.activeChatRoom.room_id || ctx.activeChatRoom.id
                });

                if (callData.success) {
                    ctx.currentCallId = callData.data.call_id;
                    ctx.showCallModal = true;

                    let stream;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            audio: true,
                            video: ctx.callType === 'video'
                        });
                    } catch (e) {
                        const msg = formatGetUserMediaErrorRu(e);
                        try {
                            ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'getUserMedia.failed', lastError: msg };
                        } catch (_) {}
                        ctx.showToast(msg, 'error');
                        throw e;
                    }
                    ctx.callLocalStream = stream;

                    const pc = new RTCPeerConnection({
                        iceServers: Array.isArray(ctx.webrtcIceServers) && ctx.webrtcIceServers.length
                            ? ctx.webrtcIceServers
                            : [{ urls: 'stun:stun.l.google.com:19302' }]
                    });
                    ctx.callPeerConnection = pc;

                    const syncDebug = (label, error = null) => {
                        try {
                            ctx.callDebug = {
                                iceConnectionState: pc.iceConnectionState,
                                connectionState: pc.connectionState,
                                signalingState: pc.signalingState,
                                iceGatheringState: pc.iceGatheringState,
                                lastEvent: String(label || ''),
                                lastError: error ? String(error?.message || error) : (ctx.callDebug?.lastError || '')
                            };
                        } catch (_) {}
                    };

                    syncDebug('pc.created');
                    pc.onconnectionstatechange = () => syncDebug('connectionState');
                    pc.onsignalingstatechange = () => syncDebug('signalingState');
                    pc.onicegatheringstatechange = () => syncDebug('iceGatheringState');

                    pc.oniceconnectionstatechange = () => {
                        syncDebug('iceConnectionState');
                        try {
                            if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                                ctx.showToast('Проблема соединения (ICE). Нажмите «Повторить подключение».', 'info');
                            }
                        } catch (_) {}
                    };

                    try {
                        pc.addTransceiver('audio', { direction: 'sendrecv' });
                        if (ctx.callType === 'video') {
                            pc.addTransceiver('video', { direction: 'sendrecv' });
                        }
                    } catch (_) {}

                    stream.getTracks().forEach((track) => pc.addTrack(track, stream));

                    pc.ontrack = (event) => {
                        if (!ctx.callRemoteStream) {
                            ctx.callRemoteStream = new MediaStream();
                        }
                        ctx.callRemoteStream.addTrack(event.track);
                        ctx.$nextTick(() => {
                            const remoteAudio = document.getElementById('remote-audio');
                            if (remoteAudio) {
                                remoteAudio.srcObject = ctx.callRemoteStream;
                                remoteAudio.play?.().catch(() => {});
                            }
                            const remoteVideo = document.getElementById('remote-video');
                            if (remoteVideo && ctx.callType === 'video') {
                                remoteVideo.srcObject = ctx.callRemoteStream;
                                remoteVideo.play?.().catch(() => {});
                            }
                        });
                    };

                    pc.onicecandidate = async (event) => {
                        if (!event.candidate || !ctx.currentCallId) return;
                        try {
                            await apiPost('chat/webrtc', {
                                call_id: ctx.currentCallId,
                                type: 'ice',
                                payload: event.candidate
                            });
                        } catch (_) {}
                    };

                    ctx.$nextTick(() => {
                        const localVideo = document.getElementById('local-video');
                        if (localVideo && ctx.callType === 'video') {
                            localVideo.srcObject = stream;
                        }
                    });

                    const offer = await pc.createOffer({
                        offerToReceiveAudio: true,
                        offerToReceiveVideo: ctx.callType === 'video'
                    });
                    await pc.setLocalDescription(offer);
                    syncDebug('localDescription.set');
                    await apiPost('chat/webrtc', {
                        call_id: ctx.currentCallId,
                        type: 'offer',
                        payload: offer
                    });

                    this.startWebrtcPolling(ctx);

                    ctx.callTimeout = setTimeout(() => {
                        if (ctx.callStatus === 'calling') {
                            ctx.endCall();
                            ctx.showToast('Собеседник не ответил', 'error');
                        }
                    }, 60000);

                    if (!ctx.callTimerInterval) {
                        ctx.callTimerInterval = setInterval(() => {
                            if (ctx.callStatus === 'connected') ctx.callTimer++;
                        }, 1000);
                    }
                }
            } catch (error) {
                console.error('Ошибка начала звонка:', error);
                ctx.showToast('Нет доступа к микрофону/камере или ошибка API', 'error');
                ctx.callStatus = 'ended';
                setTimeout(() => {
                    ctx.showCallModal = false;
                }, 1500);
            }
        },

        startWebrtcPolling(ctx) {
            if (ctx._webrtcPollRunning) return;
            ctx._webrtcPollRunning = true;
            let lastId = 0;

            const loop = async () => {
                while (ctx._webrtcPollRunning && ctx.currentCallId) {
                    try {
                        const res = await apiGet(`chat/webrtc?call_id=${encodeURIComponent(ctx.currentCallId)}&since_id=${encodeURIComponent(lastId)}&timeout=20`);
                        const events = res?.data?.events || [];
                        if (res?.data?.last_id) lastId = res.data.last_id;

                        for (const ev of events) {
                            await this.handleWebrtcEvent(ctx, ev);
                        }
                    } catch (_) {
                        await new Promise((resolve) => setTimeout(resolve, 800));
                    }
                }
            };

            loop();
        },

        stopWebrtcPolling(ctx) {
            ctx._webrtcPollRunning = false;
        },

        async handleWebrtcEvent(ctx, ev) {
            const pc = ctx.callPeerConnection;
            if (!pc || !ev) return;

            if (ev.type === 'offer') {
                if (!ctx.callLocalStream) {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({
                            audio: true,
                            video: ctx.callType === 'video'
                        });
                        ctx.callLocalStream = stream;

                        try {
                            stream.getTracks().forEach((track) => pc.addTrack(track, stream));
                        } catch (_) {}

                        ctx.$nextTick(() => {
                            const localVideo = document.getElementById('local-video');
                            if (localVideo && ctx.callType === 'video') {
                                localVideo.srcObject = stream;
                                localVideo.play?.().catch(() => {});
                            }
                        });
                    } catch (e) {
                        const msg = formatGetUserMediaErrorRu(e);
                        try {
                            ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'getUserMedia.failed', lastError: msg };
                        } catch (_) {}
                        ctx.showToast(msg, 'error');
                    }
                } else {
                    try {
                        const hasAudioSender = (pc.getSenders() || []).some((sender) => sender?.track && sender.track.kind === 'audio');
                        if (!hasAudioSender) {
                            const audioTrack = ctx.callLocalStream.getAudioTracks()?.[0];
                            if (audioTrack) pc.addTrack(audioTrack, ctx.callLocalStream);
                        }
                    } catch (_) {}
                }

                try {
                    pc.addTransceiver('audio', { direction: 'sendrecv' });
                    if (ctx.callType === 'video') {
                        pc.addTransceiver('video', { direction: 'sendrecv' });
                    }
                } catch (_) {}

                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'remoteOffer.set' };
                } catch (_) {}
                await pc.setRemoteDescription(ev.payload);
                await this.applyPendingIceCandidates(ctx);
                const answer = await pc.createAnswer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: ctx.callType === 'video'
                });
                await pc.setLocalDescription(answer);
                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'localAnswer.set' };
                } catch (_) {}
                await apiPost('chat/webrtc', {
                    call_id: ctx.currentCallId,
                    type: 'answer',
                    payload: answer
                });
                ctx.callStatus = 'connected';

                try {
                    const sdp = String(answer?.sdp || '');
                    if (sdp.includes('m=audio') && sdp.includes('a=recvonly')) {
                        const offer2 = await pc.createOffer({
                            offerToReceiveAudio: true,
                            offerToReceiveVideo: ctx.callType === 'video'
                        });
                        await pc.setLocalDescription(offer2);
                        await apiPost('chat/webrtc', {
                            call_id: ctx.currentCallId,
                            type: 'offer',
                            payload: offer2
                        });
                    }
                } catch (_) {}
            }

            if (ev.type === 'answer') {
                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'remoteAnswer.set' };
                } catch (_) {}
                await pc.setRemoteDescription(ev.payload);
                await this.applyPendingIceCandidates(ctx);
                ctx.callStatus = 'connected';
            }

            if (ev.type === 'ice') {
                if (pc.remoteDescription) {
                    try {
                        await pc.addIceCandidate(ev.payload);
                    } catch (_) {}
                } else {
                    ctx.callPendingIce.push(ev.payload);
                }

                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'ice.received' };
                } catch (_) {}
            }

            if (ctx.callRemoteStream) {
                ctx.$nextTick(() => {
                    const remoteAudio = document.getElementById('remote-audio');
                    if (remoteAudio && remoteAudio.srcObject !== ctx.callRemoteStream) {
                        remoteAudio.srcObject = ctx.callRemoteStream;
                        remoteAudio.muted = false;
                        remoteAudio.play?.().catch(() => {});
                    }
                    const remoteVideo = document.getElementById('remote-video');
                    if (remoteVideo && ctx.callType === 'video' && remoteVideo.srcObject !== ctx.callRemoteStream) {
                        remoteVideo.srcObject = ctx.callRemoteStream;
                        remoteVideo.play?.().catch(() => {});
                    }
                });
            }

            try {
                if (ctx.callLocalStream) {
                    ctx.callLocalStream.getAudioTracks().forEach((track) => {
                        track.enabled = true;
                    });
                }
            } catch (_) {}
        },

        endCall(ctx) {
            if (ctx.callTimerInterval) {
                clearInterval(ctx.callTimerInterval);
                ctx.callTimerInterval = null;
            }

            if (ctx.callTimeout) {
                clearTimeout(ctx.callTimeout);
                ctx.callTimeout = null;
            }

            if (ctx.callLocalStream) {
                ctx.callLocalStream.getTracks().forEach((track) => track.stop());
                ctx.callLocalStream = null;
            }

            if (ctx.callRemoteStream) {
                ctx.callRemoteStream.getTracks().forEach((track) => track.stop());
                ctx.callRemoteStream = null;
            }

            if (ctx.callPeerConnection) {
                ctx.callPeerConnection.close();
                ctx.callPeerConnection = null;
            }

            ctx.callPendingIce = [];
            this.stopWebrtcPolling(ctx);
            ctx.currentCallId = null;

            const remoteAudio = document.getElementById('remote-audio');
            if (remoteAudio) {
                remoteAudio.srcObject = null;
            }

            ctx.callStatus = 'ended';

            setTimeout(() => {
                ctx.showCallModal = false;
                ctx.callTimer = 0;
            }, 1000);
        },

        async retryCall(ctx) {
            try {
                if (!ctx.currentCallId || !ctx.showCallModal) {
                    ctx.showToast('Нет активного звонка для переподключения', 'error');
                    return;
                }

                ctx.callStatus = 'connecting';
                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'retry.start', lastError: '' };
                } catch (_) {}

                // Recreate RTCPeerConnection, keep current local stream if available.
                if (ctx.callPeerConnection) {
                    try { ctx.callPeerConnection.close(); } catch (_) {}
                    ctx.callPeerConnection = null;
                }
                ctx.callPendingIce = [];

                const stream = ctx.callLocalStream;
                if (!stream) {
                    ctx.showToast('Нет локального аудио/видео потока. Разрешите доступ к устройствам.', 'error');
                    ctx.callStatus = 'calling';
                    return;
                }

                const pc = new RTCPeerConnection({
                    iceServers: Array.isArray(ctx.webrtcIceServers) && ctx.webrtcIceServers.length
                        ? ctx.webrtcIceServers
                        : [{ urls: 'stun:stun.l.google.com:19302' }]
                });
                ctx.callPeerConnection = pc;

                const syncDebug = (label, error = null) => {
                    try {
                        ctx.callDebug = {
                            iceConnectionState: pc.iceConnectionState,
                            connectionState: pc.connectionState,
                            signalingState: pc.signalingState,
                            iceGatheringState: pc.iceGatheringState,
                            lastEvent: String(label || ''),
                            lastError: error ? String(error?.message || error) : (ctx.callDebug?.lastError || '')
                        };
                    } catch (_) {}
                };
                syncDebug('retry.pc.created');
                pc.oniceconnectionstatechange = () => {
                    syncDebug('iceConnectionState');
                    try {
                        if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                            ctx.showToast('Проблема соединения (ICE). Нажмите «Повторить подключение».', 'info');
                        }
                    } catch (_) {}
                };
                pc.onconnectionstatechange = () => syncDebug('connectionState');
                pc.onsignalingstatechange = () => syncDebug('signalingState');
                pc.onicegatheringstatechange = () => syncDebug('iceGatheringState');

                pc.ontrack = (event) => {
                    if (!ctx.callRemoteStream) {
                        ctx.callRemoteStream = new MediaStream();
                    }
                    ctx.callRemoteStream.addTrack(event.track);
                    ctx.$nextTick(() => {
                        const remoteAudio = document.getElementById('remote-audio');
                        if (remoteAudio) {
                            remoteAudio.srcObject = ctx.callRemoteStream;
                            remoteAudio.play?.().catch(() => {});
                        }
                        const remoteVideo = document.getElementById('remote-video');
                        if (remoteVideo && ctx.callType === 'video') {
                            remoteVideo.srcObject = ctx.callRemoteStream;
                            remoteVideo.play?.().catch(() => {});
                        }
                    });
                };

                pc.onicecandidate = async (event) => {
                    if (!event.candidate || !ctx.currentCallId) return;
                    try {
                        await apiPost('chat/webrtc', {
                            call_id: ctx.currentCallId,
                            type: 'ice',
                            payload: event.candidate
                        });
                    } catch (_) {}
                };

                try {
                    pc.addTransceiver('audio', { direction: 'sendrecv' });
                    if (ctx.callType === 'video') {
                        pc.addTransceiver('video', { direction: 'sendrecv' });
                    }
                } catch (_) {}

                stream.getTracks().forEach((track) => pc.addTrack(track, stream));

                const offer = await pc.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: ctx.callType === 'video'
                });
                await pc.setLocalDescription(offer);
                syncDebug('retry.localDescription.set');
                await apiPost('chat/webrtc', {
                    call_id: ctx.currentCallId,
                    type: 'offer',
                    payload: offer
                });

                // Ensure polling is running.
                this.startWebrtcPolling(ctx);

                ctx.callStatus = 'calling';
                ctx.showToast('Переподключаемся…', 'info');
            } catch (error) {
                const msg = error?.message ? String(error.message) : 'Не удалось переподключиться';
                try {
                    ctx.callDebug = { ...(ctx.callDebug || {}), lastEvent: 'retry.failed', lastError: msg };
                } catch (_) {}
                ctx.callStatus = 'calling';
                ctx.showToast(msg, 'error');
            }
        },

        toggleMute(ctx) {
            if (ctx.callLocalStream) {
                ctx.callLocalStream.getAudioTracks().forEach((track) => {
                    track.enabled = !track.enabled;
                });
                ctx.isMuted = !ctx.isMuted;
            }
        },

        toggleCamera(ctx) {
            if (ctx.callLocalStream) {
                ctx.callLocalStream.getVideoTracks().forEach((track) => {
                    track.enabled = !track.enabled;
                });
                ctx.isCameraOff = !ctx.isCameraOff;
            }
        },

        async editMessage(ctx, messageId, newText) {
            try {
                const result = await apiFetch(`chat/messages/${messageId}`, {
                    method: 'PUT',
                    body: JSON.stringify({ message: newText })
                });
                if (result.success) {
                    ctx.editingMessage = null;

                    // Update locally to avoid full refetch.
                    const msgId = String(messageId);
                    ctx.chatMessages = (ctx.chatMessages || []).map((m) => {
                        if (String(m.id) !== msgId) return m;
                        return {
                            ...m,
                            message: String(newText || ''),
                            edited_at: new Date().toISOString()
                        };
                    });

                    ctx.showToast('Сообщение отредактировано', 'success');
                }
            } catch (error) {
                console.error('Ошибка редактирования:', error);
            }
        },

        async deleteMessage(ctx, messageId) {
            try {
                const result = await apiDelete(`chat/messages/${messageId}`);
                if (result.success) {
                    // Soft-delete locally to avoid full refetch.
                    const msgId = String(messageId);
                    const before = Array.isArray(ctx.chatMessages) ? ctx.chatMessages : [];
                    ctx.chatMessages = before.map((m) => {
                        if (String(m.id) !== msgId) return m;
                        return {
                            ...m,
                            deleted_at: new Date().toISOString(),
                            message: ''
                        };
                    });
                    ctx.showToast('Сообщение удалено', 'success');
                }
            } catch (error) {
                console.error('Ошибка удаления:', error);
                ctx.showToast('Ошибка удаления сообщения', 'error');
            }
        },

        async forwardMessage(ctx, messageId, toRoomId) {
            try {
                const result = await apiPost(`chat/messages/${messageId}/forward`, {
                    to_room_id: toRoomId
                });
                if (result.success) {
                    ctx.forwardingMessage = null;
                    ctx.showForwardModal = false;

                    // Best-effort: refresh rooms list in background to reflect the forwarded message in target room.
                    try {
                        this.loadRooms(ctx).catch(() => {});
                    } catch (_e) {}

                    ctx.showToast('Сообщение переслано', 'success');
                }
            } catch (error) {
                console.error('Ошибка пересылки:', error);
                ctx.showToast('Ошибка пересылки сообщения', 'error');
            }
        },

        async markAsRead(_ctx, messageId) {
            try {
                await apiFetch(`chat/messages/${messageId}/read`, {
                    method: 'PUT'
                });
            } catch (error) {
                console.warn('Ошибка отметки прочтения:', error);
            }
        },

        async markRoomMessagesAsRead(ctx, messages) {
            if (!ctx?.activeChatRoom) return;

            const list = Array.isArray(messages) ? messages : (ctx.chatMessages || []);
            const myId = String(ctx.currentUser?.id ?? '');
            if (!myId) return;

            const unread = list
                .filter((m) => !m?.deleted_at)
                .filter((m) => String(m.recipient_id ?? '') === myId)
                .filter((m) => !(m.is_read === 1 || m.is_read === true || String(m.status || '') === 'read'))
                .slice(0, 25);

            if (!unread.length) return;

            await Promise.allSettled(
                unread.map(async (m) => {
                    await this.markAsRead(ctx, m.id);
                })
            );

            // Update local state so UI doesn't lag behind.
            const ids = new Set(unread.map((m) => String(m.id)));
            ctx.chatMessages = (ctx.chatMessages || []).map((m) => {
                if (!ids.has(String(m.id))) return m;
                return { ...m, is_read: 1, status: 'read' };
            });
        },

        openMessageMenu(ctx, event, msg) {
            if (!event || !msg) return;

            // Don't steal clicks from interactive content inside the bubble.
            try {
                const t = event.target;
                if (t && typeof t.closest === 'function') {
                    if (t.closest('button, a, input, textarea, select, label')) {
                        return;
                    }
                }
            } catch (_e) {}

            try {
                event.preventDefault();
            } catch (_e) {}
            ctx.chatMsgMenuOpen = true;
            ctx.chatMsgMenuMsg = msg;

            const position = positionMenu(event, 220, 200);
            ctx.chatMsgMenuX = position.x;
            ctx.chatMsgMenuY = position.y;
        },

        closeMessageMenu(ctx) {
            ctx.chatMsgMenuOpen = false;
            ctx.chatMsgMenuMsg = null;
        },

        previewImage(ctx, msg) {
            if (!msg?.file_url) return;
            ctx.imagePreviewSrc = msg.file_url;
            ctx.imagePreviewName = msg.file_name || 'image';
            ctx.imagePreviewOpen = true;
        },

        closeCreateChatModal(ctx) {
            ctx.showCreateChatModal = false;
            ctx.chatUserSearch = '';
        },

        closeGroupModal(ctx) {
            ctx.showGroupModal = false;
            ctx.groupChatUserSearch = '';
        },

        onMessagePointerDown(ctx, event, msg) {
            if (!event || !msg) return;
            const point = event.touches?.[0] || event;
            ctx._swipeMsgId = msg.id;
            ctx._swipeStartX = point.clientX;
            ctx._swipeStartY = point.clientY;
            ctx._swipeActive = true;

            // Long-press (mobile): open message menu like in Telegram.
            if (event.touches && event.touches.length === 1) {
                try {
                    ctx._msgLongPressFired = false;
                    if (ctx._msgLongPressTimer) clearTimeout(ctx._msgLongPressTimer);
                    const fakeEvent = {
                        clientX: point.clientX,
                        clientY: point.clientY,
                        target: event.target,
                        preventDefault() {}
                    };
                    ctx._msgLongPressTimer = setTimeout(() => {
                        ctx._msgLongPressFired = true;
                        try {
                            this.openMessageMenu(ctx, fakeEvent, msg);
                        } catch (_e) {}
                    }, 450);
                } catch (_e) {}
            }
        },

        onMessagePointerMove(ctx, event) {
            if (!ctx._swipeActive) return;
            const point = event.touches?.[0] || event;
            const dx = point.clientX - ctx._swipeStartX;
            const dy = point.clientY - ctx._swipeStartY;

            if (ctx._msgLongPressTimer && (Math.abs(dx) > 10 || Math.abs(dy) > 10)) {
                clearTimeout(ctx._msgLongPressTimer);
                ctx._msgLongPressTimer = null;
            }

            if (Math.abs(dy) > 18) return;
            if (dx < 0) return;

            const el = document.querySelector(`[data-msg-swipe="${ctx._swipeMsgId}"]`);
            if (!el) return;
            const clamped = Math.min(dx, 72);
            el.style.transform = `translateX(${clamped}px)`;
        },

        onMessagePointerUp(ctx) {
            if (!ctx._swipeActive) return;

            if (ctx._msgLongPressTimer) {
                clearTimeout(ctx._msgLongPressTimer);
                ctx._msgLongPressTimer = null;
            }

            const el = document.querySelector(`[data-msg-swipe="${ctx._swipeMsgId}"]`);
            let offset = 0;
            if (el) {
                const m = /translateX\(([-\d.]+)px\)/.exec(el.style.transform || '');
                offset = m ? parseFloat(m[1]) : 0;
                el.style.transform = '';
            }

            // If a long-press already opened the menu, do not also trigger swipe-reply.
            if (ctx._msgLongPressFired) {
                ctx._swipeMsgId = null;
                ctx._swipeActive = false;
                ctx._msgLongPressFired = false;
                return;
            }

            const msg = (ctx.chatMessages || []).find((message) => String(message.id) === String(ctx._swipeMsgId));
            if (msg && offset >= 56) {
                this.replyTo(ctx, msg);
            }

            ctx._swipeMsgId = null;
            ctx._swipeActive = false;
        },

        replyTo(ctx, msg) {
            ctx.replyToMessage = msg;
            ctx.editingMessage = null;
            ctx.$nextTick(() => {
                const input = document.querySelector('textarea[x-model="chatMessage"]');
                if (input) input.focus();
            });
        },

        autoResize(_ctx, textarea) {
            if (!textarea) return;
            textarea.style.height = 'auto';
            const lineHeight = parseFloat(window.getComputedStyle(textarea).lineHeight) || 20;
            const minHeight = Math.ceil(lineHeight + 16);
            const maxHeight = 192;
            const nextHeight = Math.min(Math.max(textarea.scrollHeight, minHeight), maxHeight);
            textarea.style.height = `${nextHeight}px`;
        },

        startEditMessage(ctx, msg) {
            ctx.editingMessage = msg;
            ctx.replyToMessage = null;
            ctx.chatMessage = msg.message;
            ctx.$nextTick(() => {
                const input = document.querySelector('textarea[x-model="chatMessage"]');
                if (input) input.focus();
            });
        },

        cancelEditOrReply(ctx) {
            ctx.editingMessage = null;
            ctx.replyToMessage = null;
            ctx.chatMessage = '';
            ctx.$nextTick(() => ctx.resetChatTextareaHeight());
        },

        prepareForward(ctx, msg) {
            ctx.forwardingMessage = msg;
            ctx.showForwardModal = true;
        },

        toggleMessageSelection(ctx, msg) {
            const idx = ctx.selectedMessages.findIndex((message) => message.id === msg.id);
            if (idx >= 0) {
                ctx.selectedMessages.splice(idx, 1);
                return;
            }
            ctx.selectedMessages.push(msg);
        },

        async deleteSelectedMessages(ctx) {
            for (const msg of ctx.selectedMessages) {
                await this.deleteMessage(ctx, msg.id);
            }
            ctx.selectedMessages = [];
            ctx.showDeleteConfirm = false;
        },

        addEmoji(ctx, emoji) {
            ctx.chatMessage += emoji;
            ctx.$nextTick(() => {
                const textarea = document.querySelector('textarea[x-model="chatMessage"]');
                ctx.autoResize(textarea);
            });
        },

        async sendSticker(ctx, sticker) {
            if (!ctx.activeChatRoom) return;

            try {
                await ctx.sendChatMessage('sticker', {
                    message: '',
                    sticker_id: sticker.id,
                    sticker_url: sticker.emoji,
                    sticker_type: 'emoji'
                });
                ctx.showEmojiPicker = false;
            } catch (error) {
                console.error('Ошибка отправки стикера:', error);
            }
        },

        async attachFile(ctx, type = 'file') {
            const input = document.createElement('input');
            input.type = 'file';

            if (type === 'image') {
                input.accept = 'image/*';
            }

            input.onchange = async (e) => {
                const file = e.target.files[0];
                if (file && ctx.activeChatRoom) {
                    try {
                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('room_id', getRoomId(ctx.activeChatRoom));
                        formData.append('file_type', type);

                        const token = getToken();
                        const url = `api/chat/files?_t=${Date.now()}&token=${encodeURIComponent(token)}`;

                        const requestOptions = {
                            method: 'POST',
                            headers: { 'Authorization': `Bearer ${token}` },
                            body: formData
                        };

                        const data = typeof window.fetchJsonOrThrow === 'function'
                            ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось отправить файл')
                            : await (await fetch(url, requestOptions)).json();
                        if (data.success) {
                            await ctx.selectChatRoom(ctx.activeChatRoom);
                            await ctx.loadChatRooms();
                            ctx.showToast('Файл отправлен', 'success');
                        } else {
                            ctx.showToast(data.error || 'Ошибка отправки файла', 'error');
                        }
                    } catch (error) {
                        console.error('Ошибка отправки файла:', error);
                        ctx.showToast('Ошибка отправки файла', 'error');
                    }
                }
            };
            input.click();
        },

        sendTask(ctx) {
            ctx.showTaskSelector = true;
            ctx.showAttachmentMenu = false;
        },

        sendProject(ctx) {
            ctx.showProjectSelector = true;
            ctx.showAttachmentMenu = false;
        },

        async attachTaskToMessage(ctx, taskId) {
            const task = ctx.tasks.find((item) => item.id === taskId);
            if (!task || !ctx.activeChatRoom) return;

            await ctx.sendChatMessage('task', {
                message: `Задача: ${task.title}`,
                task_id: taskId,
                task_title: task.title,
                task_status: task.status,
                task_priority: task.priority
            });
            ctx.showTaskSelector = false;
            ctx.showToast('Задача прикреплена', 'success');
        },

        async attachProjectToMessage(ctx, projectId) {
            const project = ctx.projects.find((item) => item.id === projectId);
            if (!project || !ctx.activeChatRoom) return;

            await ctx.sendChatMessage('project', {
                message: `Проект: ${project.name}`,
                project_id: projectId,
                project_name: project.name,
                project_priority: project.priority
            });
            ctx.showProjectSelector = false;
            ctx.showToast('Проект прикреплён', 'success');
        },

        async attachSystemFile(ctx, fileId) {
            const file = ctx.files.find((item) => item.id === fileId);
            if (!file || !ctx.activeChatRoom) return;

            await ctx.sendChatMessage('file', {
                message: file.original_name || 'Файл',
                file_id: fileId,
                file_url: `uploads/files/${file.name}`
            });
            ctx.showFilePicker = false;
            ctx.showToast('Файл отправлен', 'success');
        },

        async openTaskFromMessage(ctx, taskId) {
            if (!taskId) {
                ctx.showToast('ID задачи не указан', 'error');
                return;
            }

            let task = ctx.tasks.find((item) => item.id === taskId);

            if (!task) {
                try {
                    const data = await apiGet(`tasks/${taskId}`);
                    if (data.success && data.data) {
                        task = data.data;
                    }
                } catch (error) {
                    console.error('Ошибка загрузки задачи:', error);
                }
            }

            if (!task) {
                ctx.showToast('Задача не найдена', 'error');
                return;
            }

            ctx.openTaskModal(task);
        },

        async openProjectFromMessage(ctx, projectId) {
            if (!projectId) {
                ctx.showToast('ID проекта не указан', 'error');
                return;
            }

            let project = ctx.projects.find((item) => item.id === projectId);

            if (!project) {
                try {
                    const data = await apiGet(`projects/${projectId}`);
                    if (data.success && data.data) {
                        project = data.data;
                    }
                } catch (error) {
                    console.error('Ошибка загрузки проекта:', error);
                }
            }

            if (!project) {
                ctx.showToast('Проект не найден', 'error');
                return;
            }

            ctx.openProjectModal(project);
        },

        openFileInNewTab(_ctx, fileUrl) {
            if (!fileUrl) return;
            window.open(fileUrl, '_blank');
        },

        resetTextareaHeight(ctx) {
            const textarea = document.querySelector('textarea[x-model="chatMessage"]');
            if (!textarea) return;
            textarea.style.height = 'auto';
            ctx.autoResize(textarea);
        },

        normalizeTextForStorage(_ctx, text) {
            if (!text) return '';
            const map = {
                '😀': ':)', '😁': ':D', '😂': ':))', '😉': ';)', '😊': ':)', '😍': '<3', '😘': ':*',
                '😎': '8)', '👍': '+1', '👎': '-1', '🔥': '*', '❤️': '<3', '❤': '<3', '🚀': '>>',
                '⚡': '!', '✅': '[ok]', '❌': '[x]'
            };
            let out = '';
            for (const ch of String(text)) {
                out += map[ch] || ch;
            }
            return out.replace(/[\u{10000}-\u{10FFFF}]/gu, '');
        },

        openRoomMenu(ctx, event, room) {
            if (!event || !room) return;
            event.preventDefault();
            ctx.closeChatMessageMenu();
            ctx.chatRoomMenuOpen = true;
            ctx.chatRoomMenuRoom = room;

            const position = positionMenu(event, 220, 156);
            ctx.chatRoomMenuX = position.x;
            ctx.chatRoomMenuY = position.y;
        },

        closeRoomMenu(ctx) {
            ctx.chatRoomMenuOpen = false;
            ctx.chatRoomMenuRoom = null;
        },

        async openGroupModal(ctx) {
            ctx.groupForm = { name: '', members: [] };
            await this.loadUsers(ctx);
            ctx.showGroupModal = true;
        },

        async createGroup(ctx) {
            if (!ctx.groupForm.name.trim()) {
                ctx.showToast('Введите название группы', 'error');
                return;
            }

            const roomId = await this.createGroupChat(ctx, ctx.groupForm.name, ctx.groupForm.members);
            if (!roomId) return;

            ctx.showGroupModal = false;
            await this.loadRooms(ctx);
            ctx.showToast('Группа создана', 'success');
        },

        toggleGroupMember(ctx, memberId) {
            const idx = ctx.groupForm.members.indexOf(memberId);
            if (idx >= 0) {
                ctx.groupForm.members.splice(idx, 1);
                return;
            }
            ctx.groupForm.members.push(memberId);
        }
    };
})();
