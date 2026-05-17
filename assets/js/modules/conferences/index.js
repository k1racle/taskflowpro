window.TaskFlowConferences = (function () {
    function resetInviteState(ctx) {
        ctx.inviteSearch = '';
        ctx.inviteSelected = [];
    }

    function resetConferenceSessionState(ctx) {
        ctx.currentConference = null;
        ctx.conferenceParticipants = [];
        ctx.conferenceJoinRequests = [];
    }

    return {
        guestJoinUrl(_ctx, roomId) {
            return `${window.location.origin}/conference-join.html?room=${encodeURIComponent(roomId)}`;
        },

        async submitCreateConference(ctx) {
            const title = (ctx.createConferenceForm.title || '').trim();
            const description = (ctx.createConferenceForm.description || '').trim();
            if (!title) {
                ctx.showToast('Укажите название встречи', 'error');
                return;
            }

            ctx.createConferenceSubmitting = true;
            try {
                const data = await apiPost('conferences', { title, description });
                if (data.success) {
                    ctx.lastCreatedConference = data.data;
                    ctx.showToast('Встреча создана', 'success');
                    await this.loadConferences(ctx);
                } else {
                    ctx.showToast(data.error || 'Ошибка создания', 'error');
                }
            } catch (e) {
                ctx.showToast(e.message || 'Ошибка создания', 'error');
            } finally {
                ctx.createConferenceSubmitting = false;
            }
        },

        async copyText(ctx, text) {
            try {
                await navigator.clipboard.writeText(String(text || ''));
                ctx.showToast('Скопировано', 'success');
            } catch {
                ctx.showToast('Не удалось скопировать', 'error');
            }
        },

        async copyGuestLink(ctx, roomId) {
            return this.copyText(ctx, this.guestJoinUrl(ctx, roomId));
        },

        openCreateConferenceModal(ctx) {
            ctx.createConferenceModalOpen = true;
            ctx.createConferenceSubmitting = false;
            ctx.createConferenceForm = { title: '', description: '' };
        },

        closeCreateConferenceModal(ctx) {
            ctx.createConferenceModalOpen = false;
            ctx.createConferenceSubmitting = false;
        },

        async loadConferences(ctx) {
            try {
                const data = await apiGet('conferences');
                if (data.success) ctx.conferences = data.data || [];
            } catch (_e) {
                // Тихая ошибка — конференции не критичны
            }
        },

        async startConference(ctx, conferenceId) {
            try {
                const data = await apiPost(`conferences/${conferenceId}/start`, {});
                if (data.success) {
                    ctx.showToast('Конференция началась', 'success');
                    await this.loadConferences(ctx);
                    return true;
                }
                ctx.showToast(data.error || 'Не удалось начать', 'error');
            } catch (e) {
                const msg = String(e?.message || '');
                if (msg.includes('Укажите название конференции')) {
                    try {
                        const res = await apiPost(`conferences/start/${conferenceId}`, {});
                        if (res.success) {
                            ctx.showToast('Конференция началась', 'success');
                            await this.loadConferences(ctx);
                            return true;
                        }
                        ctx.showToast(res.error || 'Не удалось начать', 'error');
                        return false;
                    } catch (e2) {
                        ctx.showToast(e2.message || 'Не удалось начать', 'error');
                        return false;
                    }
                }
                ctx.showToast(e.message || 'Не удалось начать', 'error');
            }
            return false;
        },

        async endConference(ctx, conferenceId) {
            try {
                const data = await apiPost(`conferences/${conferenceId}/end`, {});
                if (data.success) {
                    ctx.showToast('Конференция завершена', 'success');
                    await this.loadConferences(ctx);
                    return true;
                }
                ctx.showToast(data.error || 'Не удалось завершить', 'error');
            } catch (e) {
                const msg = String(e?.message || '');
                if (msg.includes('Укажите название конференции')) {
                    try {
                        const res = await apiPost(`conferences/end/${conferenceId}`, {});
                        if (res.success) {
                            ctx.showToast('Конференция завершена', 'success');
                            await this.loadConferences(ctx);
                            return true;
                        }
                        ctx.showToast(res.error || 'Не удалось завершить', 'error');
                        return false;
                    } catch (e2) {
                        ctx.showToast(e2.message || 'Не удалось завершить', 'error');
                        return false;
                    }
                }
                ctx.showToast(e.message || 'Не удалось завершить', 'error');
            }
            return false;
        },

        async deleteConference(ctx, conferenceId) {
            if (!conferenceId) return false;
            ctx.openConfirm(
                'Удалить конференцию?',
                'Это действие необратимо. Конференция и связанные данные будут удалены.',
                async () => {
                    try {
                        const res = await apiDelete(`conferences/${conferenceId}`);
                        if (res.success) {
                            ctx.showToast('Конференция удалена', 'success');
                            await this.loadConferences(ctx);
                            return;
                        }
                        ctx.showToast(res.error || 'Не удалось удалить', 'error');
                    } catch (e) {
                        const msg = String(e?.message || '');
                        if (msg.includes('Укажите название конференции')) {
                            const res2 = await apiFetch(`conferences/delete/${conferenceId}`, { method: 'DELETE' });
                            if (res2.success) {
                                ctx.showToast('Конференция удалена', 'success');
                                await this.loadConferences(ctx);
                                return;
                            }
                            ctx.showToast(res2.error || 'Не удалось удалить', 'error');
                            return;
                        }
                        ctx.showToast(e.message || 'Не удалось удалить', 'error');
                    }
                },
                { confirmText: 'Удалить', cancelText: 'Отмена', danger: true }
            );
            return false;
        },

        async revealPin(_ctx, conferenceId) {
            const data = await apiGet(`conferences/${conferenceId}/guest-pin`);
            if (data.success) return data.data?.guest_pin || null;
            throw new Error(data.error || 'PIN недоступен');
        },

        async togglePin(ctx, conf) {
            const id = conf?.id;
            if (!id) return;
            if (ctx.revealedPins[id]) {
                const copy = { ...ctx.revealedPins };
                delete copy[id];
                ctx.revealedPins = copy;
                return;
            }
            if (conf.status !== 'active') {
                ctx.showToast('PIN доступен только для активных конференций', 'info');
                return;
            }
            try {
                const pin = await this.revealPin(ctx, id);
                if (pin) {
                    ctx.revealedPins = { ...ctx.revealedPins, [id]: pin };
                } else {
                    ctx.showToast('PIN не найден', 'error');
                }
            } catch (e) {
                ctx.showToast(e.message || 'PIN недоступен', 'error');
            }
        },

        async rotatePin(ctx, conf) {
            const id = conf?.id;
            if (!id) return;
            try {
                const data = await apiPost(`conferences/${id}/guest-pin?action=rotate`, {});
                if (data.success) {
                    const pin = data.data?.guest_pin;
                    ctx.revealedPins = { ...ctx.revealedPins, [id]: pin };
                    ctx.showToast('PIN обновлён', 'success');
                } else {
                    ctx.showToast(data.error || 'Не удалось сменить PIN', 'error');
                }
            } catch (e) {
                ctx.showToast(e.message || 'Не удалось сменить PIN', 'error');
            }
        },

        async openInviteModal(ctx, conf) {
            ctx.currentConference = conf;
            ctx.inviteModalOpen = true;
            resetInviteState(ctx);

            if (!ctx.inviteUsers.length) {
                try {
                    const data = await apiGet('users');
                    if (data.success) ctx.inviteUsers = data.data || [];
                } catch (e) {
                    console.error('Ошибка загрузки пользователей:', e);
                }
            }
        },

        closeInviteModal(ctx) {
            ctx.inviteModalOpen = false;
            resetInviteState(ctx);
        },

        filteredInviteUsers(ctx) {
            const q = String(ctx.inviteSearch || '').trim().toLowerCase();
            if (!q) return ctx.inviteUsers;
            return (ctx.inviteUsers || []).filter(u => {
                const text = `${u.full_name || ''} ${u.login || ''}`.toLowerCase();
                return text.includes(q);
            });
        },

        toggleInviteUser(ctx, u) {
            const id = String(u?.id);
            const idx = (ctx.inviteSelected || []).findIndex(x => String(x.id) === id);
            if (idx >= 0) ctx.inviteSelected.splice(idx, 1);
            else ctx.inviteSelected.push({ id: u?.id, full_name: u?.full_name, avatar: u?.avatar || '' });
        },

        async sendInvites(ctx) {
            if (!ctx.currentConference?.id) return;
            if (!ctx.inviteSelected.length) {
                ctx.showToast('Выберите сотрудников', 'info');
                return;
            }
            try {
                const requests = ctx.inviteSelected.map(u => apiPost(`conferences/${ctx.currentConference.id}/participants`, { user_id: u.id, role: 'participant' }));
                const results = await Promise.all(requests);
                const okCount = results.filter(r => r && r.success).length;
                ctx.showToast(`Приглашено: ${okCount}`, okCount ? 'success' : 'error');
                this.closeInviteModal(ctx);
            } catch (e) {
                console.error('Ошибка приглашения:', e);
                ctx.showToast('Ошибка приглашения', 'error');
            }
        },

        async joinConference(ctx, conf) {
            ctx.currentConference = conf;
            ctx.conferenceModalOpen = true;

            try {
                ctx.conferenceLocalStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
                ctx.conferenceAudioEnabled = true;
                ctx.conferenceVideoEnabled = true;

                await this.refreshConferenceSidebar(ctx);
                this.startConferencePolling(ctx);

                ctx.$nextTick(() => {
                    const videoEl = ctx.$refs?.localVideo;
                    if (videoEl) videoEl.srcObject = ctx.conferenceLocalStream;
                });
            } catch (e) {
                console.error('Ошибка доступа к медиа:', e);
                ctx.showToast('Нет доступа к камере/микрофону', 'error');
            }
        },

        async refreshConferenceSidebar(ctx) {
            const id = ctx.currentConference?.id;
            if (!id) return;

            try {
                const [participants, requests] = await Promise.all([
                    apiGet(`conferences/${id}/participants`),
                    apiGet(`conferences/${id}/join-requests`)
                ]);
                if (participants.success) ctx.conferenceParticipants = participants.data || [];
                if (requests.success) ctx.conferenceJoinRequests = requests.data || [];
            } catch (e) {
                console.warn('Не удалось обновить участников/запросы:', e);
            }
        },

        startConferencePolling(ctx) {
            this.stopConferencePolling(ctx);
            ctx._conferencePollInterval = setInterval(() => {
                if (!ctx.conferenceModalOpen) return;
                this.refreshConferenceSidebar(ctx);
            }, 3000);
        },

        stopConferencePolling(ctx) {
            if (ctx._conferencePollInterval) clearInterval(ctx._conferencePollInterval);
            ctx._conferencePollInterval = null;
        },

        async reviewJoinRequest(ctx, requestId, status) {
            const confId = ctx.currentConference?.id;
            if (!confId || !requestId) return;
            try {
                const res = await apiFetch(`conferences/${confId}/join-requests?request_id=${encodeURIComponent(requestId)}`, {
                    method: 'PUT',
                    body: JSON.stringify({ status })
                });
                if (res.success) {
                    ctx.showToast(status === 'approved' ? 'Запрос одобрен' : 'Запрос отклонён', 'success');
                    await this.refreshConferenceSidebar(ctx);
                } else {
                    ctx.showToast(res.error || 'Ошибка', 'error');
                }
            } catch (e) {
                ctx.showToast(e.message || 'Ошибка', 'error');
            }
        },

        toggleAudio(ctx) {
            if (!ctx.conferenceLocalStream) return;
            const track = ctx.conferenceLocalStream.getAudioTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            ctx.conferenceAudioEnabled = track.enabled;
        },

        toggleVideo(ctx) {
            if (!ctx.conferenceLocalStream) return;
            const track = ctx.conferenceLocalStream.getVideoTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            ctx.conferenceVideoEnabled = track.enabled;
        },

        leaveConference(ctx) {
            if (ctx.conferenceLocalStream) {
                ctx.conferenceLocalStream.getTracks().forEach(track => track.stop());
            }

            ctx.conferenceLocalStream = null;
            this.stopConferencePolling(ctx);
            ctx.conferenceModalOpen = false;
            resetConferenceSessionState(ctx);
        }
    };
})();
