window.TaskFlowBooking = (function () {
    const DEFAULT_FORM = {
        service_type_id: '',
        client_name: '',
        client_email: '',
        client_phone: '',
        client_company: '',
        preferred_datetime: '',
        notes: ''
    };

    const DEFAULT_STATS = {
        total: 0,
        new: 0,
        approved: 0,
        rejected: 0
    };

    function createDefaultForm() {
        return { ...DEFAULT_FORM };
    }

    function normalizeStats(stats) {
        const source = stats && typeof stats === 'object' ? stats : {};
        return {
            total: Number(source.total || 0),
            new: Number(source.new || 0),
            approved: Number(source.approved || 0),
            rejected: Number(source.rejected || 0)
        };
    }

    function notify(ctx, message, type = 'info') {
        if (typeof ctx?.showToast === 'function') {
            ctx.showToast(message, type);
            return;
        }

        if (typeof ctx?.showNotification === 'function') {
            ctx.showNotification(type, message);
            return;
        }

        if (typeof console !== 'undefined') {
            console.log(`[${type}] ${message}`);
        }
    }

    function ensureDefaultService(ctx) {
        if (!ctx?.bookingForm) return;
        if (!ctx.bookingForm.service_type_id && Array.isArray(ctx.bookingServiceTypes) && ctx.bookingServiceTypes.length > 0) {
            ctx.bookingForm.service_type_id = String(ctx.bookingServiceTypes[0].id);
        }
    }

    function selectDefaultRequest(ctx) {
        if (ctx.bookingSelectedRequestId) return;
        const requests = Array.isArray(ctx.bookingRequests) ? ctx.bookingRequests : [];
        if (!requests.length) return;

        const preferred = requests.find((item) => String(item.status || '') === 'new') || requests[0];
        if (preferred?.id != null) {
            ctx.bookingSelectedRequestId = preferred.id;
        }
    }

    function isBookingRequest(item) {
        return item && typeof item === 'object' && item.id != null;
    }

    function getStatusLabel(status) {
        const map = {
            new: 'Новая',
            approved: 'Одобрена',
            rejected: 'Отклонена'
        };

        return map[String(status || '').toLowerCase()] || 'Новая';
    }

    function getStatusTone(status) {
        const map = {
            new: 'warning',
            approved: 'success',
            rejected: 'danger'
        };

        return map[String(status || '').toLowerCase()] || 'info';
    }

    function getServiceIcon(icon) {
        const map = {
            snowflake: '❄',
            flame: '🔥',
            bicycle: '🚲',
            map: '🗺',
            tool: '🛠',
            star: '⭐',
            calendar: '📅'
        };

        return map[String(icon || '').toLowerCase()] || '📅';
    }

    return {
        getDefaultForm() {
            return createDefaultForm();
        },

        getServiceIcon(icon) {
            return getServiceIcon(icon);
        },

        getStatusLabel(status) {
            return getStatusLabel(status);
        },

        getStatusTone(status) {
            return getStatusTone(status);
        },

        createView() {
            return {
                bookingLoading: false,
                bookingSubmitting: false,
                bookingError: '',
                bookingNotice: {
                    show: false,
                    type: 'info',
                    message: ''
                },
                bookingServiceTypes: [],
                bookingRequests: [],
                bookingStats: { ...DEFAULT_STATS },
                bookingCanManage: false,
                bookingLastLoadedAt: '',
                bookingForm: createDefaultForm(),
                bookingSelectedRequestId: null,
                _bookingLoaded: false,
                _bookingNoticeTimer: null,

                async init() {
                    await window.TaskFlowBooking.ensureLoaded(this);
                },

                showToast(message, type = 'info') {
                    this.bookingNotice = {
                        show: true,
                        type,
                        message
                    };

                    if (this._bookingNoticeTimer) {
                        clearTimeout(this._bookingNoticeTimer);
                    }

                    this._bookingNoticeTimer = setTimeout(() => {
                        this.bookingNotice.show = false;
                    }, 4000);
                },

                refresh() {
                    return window.TaskFlowBooking.refresh(this);
                },

                refreshBookingData() {
                    return window.TaskFlowBooking.refresh(this);
                },

                submitBooking() {
                    return window.TaskFlowBooking.submitBookingRequest(this);
                },

                submitBookingRequest() {
                    return window.TaskFlowBooking.submitBookingRequest(this);
                },

                approveRequest(request) {
                    return window.TaskFlowBooking.approveRequest(this, request);
                },

                approveBookingRequest(request) {
                    return window.TaskFlowBooking.approveRequest(this, request);
                },

                getBookingServiceIcon(icon) {
                    return window.TaskFlowBooking.getServiceIcon(icon);
                },

                getBookingStatusLabel(status) {
                    return window.TaskFlowBooking.getStatusLabel(status);
                },

                getBookingStatusTone(status) {
                    return window.TaskFlowBooking.getStatusTone(status);
                },

                rejectRequest(request) {
                    return window.TaskFlowBooking.rejectRequest(this, request);
                },

                rejectBookingRequest(request) {
                    return window.TaskFlowBooking.rejectRequest(this, request);
                },

                selectRequest(request) {
                    if (!request?.id) return;
                    this.bookingSelectedRequestId = request.id;
                },

                selectBookingRequest(request) {
                    if (!request?.id) return;
                    this.bookingSelectedRequestId = request.id;
                },

                get selectedBookingRequest() {
                    return (this.bookingRequests || []).find((item) => String(item.id) === String(this.bookingSelectedRequestId || '')) || null;
                },

                getSelectedBookingRequest() {
                    return (this.bookingRequests || []).find((item) => String(item.id) === String(this.bookingSelectedRequestId || '')) || null;
                },

                get selectedServiceType() {
                    const id = String(this.bookingForm.service_type_id || '');
                    return (this.bookingServiceTypes || []).find((item) => String(item.id) === id) || null;
                },

                formatDateTime(dateStr) {
                    return window.TaskFlowSharedFormatters?.formatDateTime?.(dateStr) || '';
                },

                formatDate(dateStr) {
                    return window.TaskFlowSharedFormatters?.formatRelativeDate?.(dateStr) || '';
                },

                formatRelativeDate(dateStr) {
                    return window.TaskFlowSharedFormatters?.formatRelativeDate?.(dateStr) || '';
                }
            };
        },

        async loadData(ctx, force = false) {
            if (ctx.bookingLoading) {
                return {
                    success: true,
                    data: {
                        service_types: ctx.bookingServiceTypes || [],
                        requests: ctx.bookingRequests || [],
                        stats: ctx.bookingStats || { ...DEFAULT_STATS },
                        can_manage: !!ctx.bookingCanManage
                    }
                };
            }

            if (ctx._bookingLoaded && !force) {
                ensureDefaultService(ctx);
                return {
                    success: true,
                    data: {
                        service_types: ctx.bookingServiceTypes || [],
                        requests: ctx.bookingRequests || [],
                        stats: ctx.bookingStats || { ...DEFAULT_STATS },
                        can_manage: !!ctx.bookingCanManage
                    }
                };
            }

            ctx.bookingLoading = true;
            ctx.bookingError = '';

            try {
                const res = await apiGet('booking.php');
                if (res.success) {
                    const data = res.data || {};
                    ctx.bookingServiceTypes = Array.isArray(data.service_types) ? data.service_types : [];
                    ctx.bookingRequests = Array.isArray(data.requests) ? data.requests : [];
                    ctx.bookingStats = normalizeStats(data.stats);
                    ctx.bookingCanManage = !!data.can_manage;
                    ctx.bookingLastLoadedAt = new Date().toISOString();
                    ctx._bookingLoaded = true;
                    ensureDefaultService(ctx);
                    selectDefaultRequest(ctx);
                } else {
                    ctx.bookingError = res.error || 'Не удалось загрузить данные записи';
                }

                return res;
            } catch (error) {
                console.warn('booking.loadData error', error);
                ctx.bookingError = error?.userMessage || error?.message || 'Не удалось загрузить данные записи';
                return {
                    success: false,
                    error: ctx.bookingError
                };
            } finally {
                ctx.bookingLoading = false;
            }
        },

        async ensureLoaded(ctx) {
            return this.loadData(ctx, false);
        },

        async refresh(ctx) {
            return this.loadData(ctx, true);
        },

        resetForm(ctx) {
            ctx.bookingForm = createDefaultForm();
            ensureDefaultService(ctx);
        },

        async submitBookingRequest(ctx) {
            if (ctx.bookingSubmitting) {
                return false;
            }

            const form = ctx.bookingForm || {};
            const serviceTypeId = Number(form.service_type_id || 0);
            const clientName = String(form.client_name || '').trim();
            const clientEmail = String(form.client_email || '').trim();
            const clientPhone = String(form.client_phone || '').trim();

            if (!serviceTypeId || !clientName) {
                notify(ctx, 'Укажите услугу и имя клиента', 'error');
                return false;
            }

            if (!clientEmail && !clientPhone) {
                notify(ctx, 'Укажите телефон или email для связи', 'error');
                return false;
            }

            ctx.bookingSubmitting = true;

            try {
                const res = await apiPost('booking.php', {
                    service_type_id: serviceTypeId,
                    client_name: clientName,
                    client_email: clientEmail,
                    client_phone: clientPhone,
                    client_company: String(form.client_company || '').trim(),
                    preferred_datetime: String(form.preferred_datetime || '').trim(),
                    notes: String(form.notes || '').trim()
                });

                if (res.success) {
                    notify(ctx, res.message || 'Заявка на запись создана', 'success');
                    this.resetForm(ctx);
                    await this.loadData(ctx, true);
                    return true;
                }

                notify(ctx, res.error || 'Не удалось создать заявку', 'error');
                return false;
            } catch (error) {
                console.error('booking.submitBookingRequest error', error);
                notify(ctx, error?.userMessage || error?.message || 'Не удалось создать заявку', 'error');
                return false;
            } finally {
                ctx.bookingSubmitting = false;
            }
        },

        async respondToRequest(ctx, requestOrId, decision) {
            const requestId = isBookingRequest(requestOrId) ? requestOrId.id : requestOrId;
            if (!requestId) {
                notify(ctx, 'Укажите заявку', 'error');
                return false;
            }

            if (ctx.bookingSubmitting) {
                return false;
            }

            ctx.bookingSubmitting = true;

            try {
                const res = await apiPost('booking.php', {
                    action: decision,
                    request_id: requestId
                });

                if (res.success) {
                    notify(ctx, res.message || (decision === 'approve' ? 'Заявка одобрена' : 'Заявка отклонена'), 'success');
                    await this.loadData(ctx, true);
                    return true;
                }

                notify(ctx, res.error || 'Не удалось обработать заявку', 'error');
                return false;
            } catch (error) {
                console.error('booking.respondToRequest error', error);
                notify(ctx, error?.userMessage || error?.message || 'Не удалось обработать заявку', 'error');
                return false;
            } finally {
                ctx.bookingSubmitting = false;
            }
        },

        async approveRequest(ctx, request) {
            return this.respondToRequest(ctx, request, 'approve');
        },

        async rejectRequest(ctx, request) {
            return this.respondToRequest(ctx, request, 'reject');
        }
    };
})();
