window.TaskFlowBooking = (function () {
    const DEFAULT_FORM = {
        service_type_ids: [],
        client_name: '',
        client_email: '',
        client_phone: '',
        client_company: '',
        preferred_datetime: '',
        notes: ''
    };

    const DEFAULT_SERVICE_FORM = {
        id: null,
        key: '',
        name: '',
        description: '',
        icon: 'calendar',
        duration_minutes: 60,
        price: 0,
        currency: 'RUB',
        discount_type: 'none',
        discount_value: 0,
        is_active: true
    };

    const DEFAULT_STATS = {
        total: 0,
        pending: 0,
        confirmed: 0,
        rejected: 0,
        expired: 0,

        // legacy aliases used in some UI blocks
        new: 0,
        approved: 0
    };

    function createDefaultForm() {
        return { ...DEFAULT_FORM };
    }

    function createDefaultServiceForm() {
        return { ...DEFAULT_SERVICE_FORM };
    }

    function normalizeStats(stats) {
        const source = stats && typeof stats === 'object' ? stats : {};
        const pending = Number(source.pending || source.pending_count || source.new || 0);
        const confirmed = Number(source.confirmed || source.confirmed_count || source.approved || 0);
        const rejected = Number(source.rejected || source.rejected_count || 0);
        const expired = Number(source.expired || source.expired_count || 0);
        return {
            total: Number(source.total || 0),
            pending,
            confirmed,
            rejected,
            expired,

            new: pending,
            approved: confirmed
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
        const current = Array.isArray(ctx.bookingForm.service_type_ids) ? ctx.bookingForm.service_type_ids : [];
        if (current.length === 0 && Array.isArray(ctx.bookingServiceTypes) && ctx.bookingServiceTypes.length > 0) {
            ctx.bookingForm.service_type_ids = [String(ctx.bookingServiceTypes[0].id)];
        }
    }

    function selectDefaultRequest(ctx) {
        if (ctx.bookingSelectedRequestId) return;
        const requests = Array.isArray(ctx.bookingRequests) ? ctx.bookingRequests : [];
        if (!requests.length) return;

        const preferred = requests.find((item) => String(item.status || '') === 'pending') || requests[0];
        if (preferred?.id != null) {
            ctx.bookingSelectedRequestId = preferred.id;
        }
    }

    function isBookingRequest(item) {
        return item && typeof item === 'object' && item.id != null;
    }

    function getStatusLabel(status) {
        const map = {
            pending: 'Ожидает подтверждения',
            confirmed: 'Подтверждена',
            rejected: 'Отклонена',
            expired: 'Истекла'
        };

        return map[String(status || '').toLowerCase()] || 'Ожидает подтверждения';
    }

    function getStatusTone(status) {
        const map = {
            pending: 'warning',
            confirmed: 'success',
            rejected: 'danger',
            expired: 'muted'
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

    function bookingServiceToForm(service) {
        const source = service && typeof service === 'object' ? service : {};
        return {
            ...createDefaultServiceForm(),
            id: source.id != null ? Number(source.id) : null,
            key: String(source.key || ''),
            name: String(source.name || ''),
            description: String(source.description || ''),
            icon: String(source.icon || 'calendar'),
            duration_minutes: Number(source.duration_minutes || 0) || 60,
            price: Number(source.price || 0) || 0,
            currency: String(source.currency || 'RUB') || 'RUB',
            discount_type: String(source.discount_type || 'none') || 'none',
            discount_value: Number(source.discount_value || 0) || 0,
            is_active: source.is_active == null ? true : !!source.is_active
        };
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
                bookingServiceModalOpen: false,
                bookingServiceForm: createDefaultServiceForm(),
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

                openBookingServiceModal() {
                    this.bookingServiceForm = createDefaultServiceForm();
                    this.bookingServiceModalOpen = true;
                },

                editBookingService(service) {
                    this.bookingServiceForm = bookingServiceToForm(service);
                    this.bookingServiceModalOpen = true;
                },

                async saveBookingService() {
                    return window.TaskFlowBooking.saveBookingService(this);
                },

                async deleteBookingService(service) {
                    return window.TaskFlowBooking.deleteBookingService(this, service);
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
                    const ids = Array.isArray(this.bookingForm?.service_type_ids) ? this.bookingForm.service_type_ids : [];
                    const id = String(ids[0] || '');
                    return (this.bookingServiceTypes || []).find((item) => String(item.id) === id) || null;
                },

                toggleServiceType(serviceId) {
                    const id = String(serviceId || '');
                    if (!id) return;
                    if (!Array.isArray(this.bookingForm.service_type_ids)) {
                        this.bookingForm.service_type_ids = [];
                    }

                    const current = this.bookingForm.service_type_ids.map(String);
                    const idx = current.indexOf(id);
                    if (idx >= 0) {
                        current.splice(idx, 1);
                    } else {
                        current.push(id);
                    }

                    this.bookingForm.service_type_ids = current;
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
            const serviceTypeIds = Array.isArray(form.service_type_ids) ? form.service_type_ids.map((x) => Number(x || 0)).filter((x) => x > 0) : [];
            const clientName = String(form.client_name || '').trim();
            const clientEmail = String(form.client_email || '').trim();
            const clientPhone = String(form.client_phone || '').trim();
            const preferredDatetime = String(form.preferred_datetime || '').trim();

            if (!serviceTypeIds.length || !clientName) {
                notify(ctx, 'Укажите услуги и имя клиента', 'error');
                return false;
            }

            if (!clientPhone) {
                notify(ctx, 'Укажите телефон для связи', 'error');
                return false;
            }

            if (!preferredDatetime) {
                notify(ctx, 'Укажите желаемое время', 'error');
                return false;
            }

            ctx.bookingSubmitting = true;

            try {
                const res = await apiPost('booking.php', {
                    service_type_ids: serviceTypeIds,
                    client_name: clientName,
                    client_email: clientEmail,
                    client_phone: clientPhone,
                    client_company: String(form.client_company || '').trim(),
                    preferred_datetime: preferredDatetime,
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
                    notify(ctx, res.message || (decision === 'confirm' ? 'Заявка подтверждена' : 'Заявка отклонена'), 'success');
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
            // backend supports legacy 'approve' alias, but keep new contract here
            return this.respondToRequest(ctx, request, 'confirm');
        },

        async rejectRequest(ctx, request) {
            return this.respondToRequest(ctx, request, 'reject');
        },

        async saveBookingService(ctx) {
            if (!ctx.bookingCanManage) {
                notify(ctx, 'Недостаточно прав для управления услугами', 'error');
                return false;
            }

            if (ctx.bookingSubmitting) return false;

            const form = ctx.bookingServiceForm || {};
            const payload = {
                action: 'service_upsert',
                id: form.id != null ? Number(form.id) : null,
                key: String(form.key || '').trim(),
                name: String(form.name || '').trim(),
                description: String(form.description || '').trim(),
                icon: String(form.icon || 'calendar').trim(),
                duration_minutes: Number(form.duration_minutes || 0),
                price: Number(form.price || 0),
                currency: String(form.currency || 'RUB').trim() || 'RUB',
                discount_type: String(form.discount_type || 'none').trim() || 'none',
                discount_value: Number(form.discount_value || 0),
                is_active: !!form.is_active
            };

            if (!payload.key) {
                notify(ctx, 'Укажите ключ услуги', 'error');
                return false;
            }

            if (!payload.name) {
                notify(ctx, 'Укажите название услуги', 'error');
                return false;
            }

            if (!(payload.duration_minutes > 0)) {
                notify(ctx, 'Укажите длительность (минуты)', 'error');
                return false;
            }

            ctx.bookingSubmitting = true;
            try {
                const res = await apiPost('booking.php', payload);
                if (res.success) {
                    notify(ctx, res.message || 'Услуга сохранена', 'success');
                    ctx.bookingServiceModalOpen = false;
                    ctx.bookingServiceForm = createDefaultServiceForm();
                    await this.loadData(ctx, true);
                    return true;
                }

                notify(ctx, res.error || 'Не удалось сохранить услугу', 'error');
                return false;
            } catch (error) {
                console.error('booking.saveBookingService error', error);
                notify(ctx, error?.userMessage || error?.message || 'Не удалось сохранить услугу', 'error');
                return false;
            } finally {
                ctx.bookingSubmitting = false;
            }
        },

        async deleteBookingService(ctx, service) {
            if (!ctx.bookingCanManage) {
                notify(ctx, 'Недостаточно прав для управления услугами', 'error');
                return false;
            }

            const id = service?.id != null ? Number(service.id) : null;
            if (!id) {
                notify(ctx, 'Не удалось определить услугу', 'error');
                return false;
            }

            if (ctx.bookingSubmitting) return false;

            const name = String(service?.name || '').trim();
            if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
                const ok = window.confirm(`Удалить услугу${name ? ` «${name}»` : ''}?`);
                if (!ok) return false;
            }

            ctx.bookingSubmitting = true;
            try {
                const res = await apiPost('booking.php', {
                    action: 'service_delete',
                    id
                });

                if (res.success) {
                    notify(ctx, res.message || 'Услуга удалена', 'success');
                    await this.loadData(ctx, true);
                    return true;
                }

                notify(ctx, res.error || 'Не удалось удалить услугу', 'error');
                return false;
            } catch (error) {
                console.error('booking.deleteBookingService error', error);
                notify(ctx, error?.userMessage || error?.message || 'Не удалось удалить услугу', 'error');
                return false;
            } finally {
                ctx.bookingSubmitting = false;
            }
        }
    };
})();
