window.TaskFlowSiteWidgets = (function () {
    function getDefaultEnabledWidgets() {
        return ['time'];
    }

    function getWidgetStorageConfig(ctx) {
        return {
            enabledWidgets: Array.isArray(ctx.enabledWidgets) ? ctx.enabledWidgets : getDefaultEnabledWidgets(),
            widgetOrder: Array.isArray(ctx.widgetOrder) ? ctx.widgetOrder : getDefaultEnabledWidgets(),
            externalResources: Array.isArray(ctx.externalResources) ? ctx.externalResources : []
        };
    }

    function getDefaultConfig() {
        return {
            apiBase: window.location.origin + '/api',
            position: 'right',
            contactUrl: '',
            contactLabel: 'Написать в TaskFlow',
            contactDescription: 'Ответим в чате и при необходимости оформим обращение.',
            formWidth: 480,
            formHeight: 760,
            chatWidth: 420,
            chatHeight: 760,
            chatTitle: 'Чат с командой',
            chatDescription: 'Обычно отвечаем в рабочее время',
            brandColor: '#2563eb',
            brandButtonText: '💬',
            brandFormTitle: 'Оставить обращение',
            brandFormDescription: 'Коротко опишите вопрос, и мы зарегистрируем обращение.'
        };
    }

    function resetState(ctx) {
        ctx.siteWidgetsCanManage = ctx.canManageSiteWidgets;
        ctx.siteWidgetProfiles = [];
        ctx.siteWidgetsActiveProfileId = null;
        ctx.siteWidgetsSelectedProfileId = null;
        ctx.siteWidgetsConfig = getDefaultConfig();
    }

    function buildSettingsPayload(ctx) {
        return {
            profile_id: Number(ctx.siteWidgetsSelectedProfileId) || Number(ctx.siteWidgetsActiveProfileId) || null,
            api_base: window.TaskFlowSiteWidgets.normalizeApiBase(ctx.siteWidgetsConfig.apiBase),
            position: ctx.siteWidgetsConfig.position === 'left' ? 'left' : 'right',
            contact_url: String(ctx.siteWidgetsConfig.contactUrl || '').trim(),
            contact_label: String(ctx.siteWidgetsConfig.contactLabel || '').trim(),
            contact_description: String(ctx.siteWidgetsConfig.contactDescription || '').trim(),
            form_width: Number(ctx.siteWidgetsConfig.formWidth) || 480,
            form_height: Number(ctx.siteWidgetsConfig.formHeight) || 760,
            chat_width: Number(ctx.siteWidgetsConfig.chatWidth) || 420,
            chat_height: Number(ctx.siteWidgetsConfig.chatHeight) || 760,
            chat_title: String(ctx.siteWidgetsConfig.chatTitle || '').trim(),
            chat_description: String(ctx.siteWidgetsConfig.chatDescription || '').trim(),
            brand_color: String(ctx.siteWidgetsConfig.brandColor || '').trim(),
            brand_button_text: String(ctx.siteWidgetsConfig.brandButtonText || '').trim(),
            brand_form_title: String(ctx.siteWidgetsConfig.brandFormTitle || '').trim(),
            brand_form_description: String(ctx.siteWidgetsConfig.brandFormDescription || '').trim()
        };
    }

    return {
        normalizeApiBase(value) {
            const raw = String(value || '').trim();
            if (!raw) return window.location.origin + '/api';
            const withProtocol = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
            return withProtocol.replace(/\/$/, '').replace(/\/api\/api$/i, '/api');
        },

        getDefaultConfig,

        normalizeProfile(ctx, profile) {
            const defaults = getDefaultConfig();
            const config = profile && profile.config ? profile.config : {};
            return {
                id: Number(profile?.id) || null,
                name: String(profile?.name || 'Профиль'),
                slug: String(profile?.slug || 'default'),
                is_active: !!profile?.is_active,
                created_at: profile?.created_at || null,
                updated_at: profile?.updated_at || null,
                config: {
                    ...defaults,
                    apiBase: config.api_base || defaults.apiBase,
                    position: config.position === 'left' ? 'left' : 'right',
                    contactUrl: config.contact_url || defaults.contactUrl,
                    contactLabel: config.contact_label || defaults.contactLabel,
                    contactDescription: config.contact_description || defaults.contactDescription,
                    formWidth: Number(config.form_width) || defaults.formWidth,
                    formHeight: Number(config.form_height) || defaults.formHeight,
                    chatWidth: Number(config.chat_width) || defaults.chatWidth,
                    chatHeight: Number(config.chat_height) || defaults.chatHeight,
                    chatTitle: config.chat_title || defaults.chatTitle,
                    chatDescription: config.chat_description || defaults.chatDescription,
                    brandColor: config.brand_color || defaults.brandColor,
                    brandButtonText: config.brand_button_text || defaults.brandButtonText,
                    brandFormTitle: config.brand_form_title || defaults.brandFormTitle,
                    brandFormDescription: config.brand_form_description || defaults.brandFormDescription
                }
            };
        },

        selectProfile(ctx, profileId) {
            const numericId = Number(profileId) || null;
            const profile = ctx.siteWidgetProfiles.find((item) => Number(item.id) === numericId)
                || ctx.siteWidgetProfiles.find((item) => item.is_active)
                || ctx.siteWidgetProfiles[0]
                || null;

            ctx.siteWidgetsSelectedProfileId = profile ? Number(profile.id) : null;
            ctx.siteWidgetsConfig = profile ? { ...profile.config } : getDefaultConfig();
        },

        getSelectedProfile(ctx) {
            return ctx.siteWidgetProfiles.find((item) => Number(item.id) === Number(ctx.siteWidgetsSelectedProfileId)) || null;
        },

        getSelectedProfileSlug(ctx) {
            return this.getSelectedProfile(ctx)?.slug || 'default';
        },

        getSelectedProfileName(ctx) {
            return this.getSelectedProfile(ctx)?.name || 'Основной профиль';
        },

        getSelectedProfileSnippetLabel(ctx) {
            const profile = this.getSelectedProfile(ctx);
            if (!profile) {
                return 'Профиль не выбран';
            }

            return profile.is_active ? `${profile.name} · активный` : `${profile.name} · черновой`;
        },

        async loadSettings(ctx) {
            try {
                const response = await apiGet('settings/site-widgets');
                if (response.success && response.data) {
                    ctx.siteWidgetsCanManage = Boolean(response.data.can_manage ?? ctx.canManageSiteWidgets);
                    ctx.siteWidgetProfiles = Array.isArray(response.data.profiles)
                        ? response.data.profiles.map((profile) => this.normalizeProfile(ctx, profile))
                        : [];
                    ctx.siteWidgetsActiveProfileId = Number(response.data.active_profile_id) || null;

                    const targetProfileId = Number(response.data.current_profile_id)
                        || Number(ctx.siteWidgetsSelectedProfileId)
                        || Number(ctx.siteWidgetsActiveProfileId)
                        || null;

                    this.selectProfile(ctx, targetProfileId);
                    return;
                }

                resetState(ctx);
            } catch (error) {
                console.error('Load site widgets settings error:', error);
                resetState(ctx);
            }
        },

        async saveSettings(ctx) {
            if (!ctx.siteWidgetsCanManage) {
                ctx.showToast('Недостаточно прав для изменения настроек виджетов', 'error');
                return;
            }

            try {
                const payload = buildSettingsPayload(ctx);
                const response = await apiPut('settings/site-widgets', payload);
                if (!response.success) {
                    throw new Error(response.error || 'Не удалось сохранить настройки виджетов');
                }

                await this.loadSettings(ctx);
                this.selectProfile(ctx, payload.profile_id);
                ctx.showToast(`Профиль «${this.getSelectedProfileName(ctx)}» сохранен`, 'success');
            } catch (error) {
                console.error('Save site widgets settings error:', error);
                ctx.showToast(error.message || 'Ошибка сохранения настроек виджетов', 'error');
            }
        },

        async createProfile(ctx) {
            if (!ctx.siteWidgetsCanManage) {
                ctx.showToast('Недостаточно прав для создания профилей виджетов', 'error');
                return;
            }

            const name = String(ctx.siteWidgetsNewProfileName || '').trim();
            if (!name) {
                ctx.showToast('Укажите название нового профиля', 'info');
                return;
            }

            try {
                const response = await apiPost('settings/site-widgets', {
                    name,
                    slug: String(ctx.siteWidgetsNewProfileSlug || '').trim(),
                    clone_from_profile_id: Number(ctx.siteWidgetsSelectedProfileId) || Number(ctx.siteWidgetsActiveProfileId) || null
                });

                if (!response.success) {
                    throw new Error(response.error || 'Не удалось создать профиль');
                }

                ctx.siteWidgetsNewProfileName = '';
                ctx.siteWidgetsNewProfileSlug = '';
                await this.loadSettings(ctx);

                const createdId = Number(response.data?.current_profile_id) || null;
                if (createdId) {
                    this.selectProfile(ctx, createdId);
                }

                ctx.showToast('Профиль виджета создан', 'success');
            } catch (error) {
                console.error('Create site widget profile error:', error);
                ctx.showToast(error.message || 'Ошибка создания профиля', 'error');
            }
        },

        async activateProfile(ctx, profileId = null) {
            if (!ctx.siteWidgetsCanManage) {
                ctx.showToast('Недостаточно прав для активации профиля виджета', 'error');
                return;
            }

            const targetId = Number(profileId) || Number(ctx.siteWidgetsSelectedProfileId) || null;
            if (!targetId) {
                ctx.showToast('Сначала выберите профиль', 'info');
                return;
            }

            try {
                const response = await apiPut('settings/site-widgets/activate', { profile_id: targetId });
                if (!response.success) {
                    throw new Error(response.error || 'Не удалось активировать профиль');
                }

                await this.loadSettings(ctx);
                this.selectProfile(ctx, targetId);
                ctx.showToast('Активный профиль обновлен', 'success');
            } catch (error) {
                console.error('Activate site widget profile error:', error);
                ctx.showToast(error.message || 'Ошибка активации профиля', 'error');
            }
        },

        resetSettings(ctx) {
            ctx.siteWidgetsConfig = getDefaultConfig();
            ctx.showToast('Применены значения по умолчанию. Сохраните их, если хотите записать в систему.', 'info');
        },

        getFrameBase(ctx) {
            const apiBase = this.normalizeApiBase(ctx.siteWidgetsConfig.apiBase);
            return apiBase.replace(/\/api$/i, '');
        },

        getFormPreviewUrl(ctx) {
            const params = new URLSearchParams({
                api_base: this.normalizeApiBase(ctx.siteWidgetsConfig.apiBase),
                profile: this.getSelectedProfileSlug(ctx),
                page_title: 'Форма сотрудничества',
                page_url: window.location.href,
                brand_color: ctx.siteWidgetsConfig.brandColor || '#2563eb',
                brand_button_text: ctx.siteWidgetsConfig.brandButtonText || '💬',
                brand_form_title: ctx.siteWidgetsConfig.brandFormTitle || 'Оставить обращение',
                brand_form_description: ctx.siteWidgetsConfig.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.'
            });

            return `${this.getFrameBase(ctx)}/widgets/widget-mini.html?${params.toString()}`;
        },

        getChatPreviewUrl(ctx) {
            const params = new URLSearchParams({
                api_base: this.normalizeApiBase(ctx.siteWidgetsConfig.apiBase),
                profile: this.getSelectedProfileSlug(ctx),
                position: ctx.siteWidgetsConfig.position === 'left' ? 'left' : 'right',
                contact_url: ctx.siteWidgetsConfig.contactUrl || '',
                contact_label: ctx.siteWidgetsConfig.contactLabel || 'Написать в TaskFlow',
                contact_description: ctx.siteWidgetsConfig.contactDescription || 'Ответим в чате и при необходимости оформим обращение.',
                title: ctx.siteWidgetsConfig.chatTitle || 'Чат с командой',
                subtitle: ctx.siteWidgetsConfig.chatDescription || 'Обычно отвечаем в рабочее время',
                brand_color: ctx.siteWidgetsConfig.brandColor || '#2563eb',
                brand_button_text: ctx.siteWidgetsConfig.brandButtonText || '💬',
                brand_form_title: ctx.siteWidgetsConfig.brandFormTitle || 'Оставить обращение',
                brand_form_description: ctx.siteWidgetsConfig.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.',
                page_title: 'Чат-виджет',
                page_url: window.location.href
            });

            return `${this.getFrameBase(ctx)}/widgets/widget-standard.html?${params.toString()}`;
        },

        getEmbedCode(ctx, type) {
            const frameBase = this.getFrameBase(ctx);
            const apiBase = this.normalizeApiBase(ctx.siteWidgetsConfig.apiBase);

            if (type === 'form') {
                const params = new URLSearchParams({
                    api_base: apiBase,
                    profile: this.getSelectedProfileSlug(ctx),
                    page_title: 'Форма сотрудничества',
                    brand_color: ctx.siteWidgetsConfig.brandColor || '#2563eb',
                    brand_button_text: ctx.siteWidgetsConfig.brandButtonText || '💬',
                    brand_form_title: ctx.siteWidgetsConfig.brandFormTitle || 'Оставить обращение',
                    brand_form_description: ctx.siteWidgetsConfig.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.'
                });

                return `<iframe src="${frameBase}/widgets/widget-mini.html?${params.toString()}" width="${Number(ctx.siteWidgetsConfig.formWidth) || 480}" height="${Number(ctx.siteWidgetsConfig.formHeight) || 760}" frameborder="0" style="border:0;width:100%;max-width:${Number(ctx.siteWidgetsConfig.formWidth) || 480}px;height:${Number(ctx.siteWidgetsConfig.formHeight) || 760}px;border-radius:24px;overflow:hidden;" title="Форма заявки на сотрудничество"></iframe>`;
            }

            if (type === 'chat') {
                const params = new URLSearchParams({
                    api_base: apiBase,
                    profile: this.getSelectedProfileSlug(ctx),
                    position: ctx.siteWidgetsConfig.position === 'left' ? 'left' : 'right',
                    contact_url: ctx.siteWidgetsConfig.contactUrl || '',
                    contact_label: ctx.siteWidgetsConfig.contactLabel || 'Написать в TaskFlow',
                    contact_description: ctx.siteWidgetsConfig.contactDescription || 'Ответим в чате и при необходимости оформим обращение.',
                    title: ctx.siteWidgetsConfig.chatTitle || 'Чат с командой',
                    subtitle: ctx.siteWidgetsConfig.chatDescription || 'Обычно отвечаем в рабочее время',
                    brand_color: ctx.siteWidgetsConfig.brandColor || '#2563eb',
                    brand_button_text: ctx.siteWidgetsConfig.brandButtonText || '💬',
                    brand_form_title: ctx.siteWidgetsConfig.brandFormTitle || 'Оставить обращение',
                    brand_form_description: ctx.siteWidgetsConfig.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.'
                });

                return `<iframe src="${frameBase}/widgets/widget-standard.html?${params.toString()}" width="${Number(ctx.siteWidgetsConfig.chatWidth) || 420}" height="${Number(ctx.siteWidgetsConfig.chatHeight) || 760}" frameborder="0" style="border:0;width:${Number(ctx.siteWidgetsConfig.chatWidth) || 420}px;max-width:100%;height:${Number(ctx.siteWidgetsConfig.chatHeight) || 760}px;overflow:hidden;" title="Чат-виджет"></iframe>`;
            }

            if (type === 'booking') {
                const dataset = [
                    `data-mode="booking"`,
                    `data-profile="${this.getSelectedProfileSlug(ctx)}"`,
                    `data-api-base="${apiBase}"`,
                    `data-position="${ctx.siteWidgetsConfig.position === 'left' ? 'left' : 'right'}"`,
                    `data-brand-color="${ctx.siteWidgetsConfig.brandColor || '#2563eb'}"`,
                    `data-brand-button-text="${ctx.siteWidgetsConfig.brandButtonText || '💬'}"`,
                ];
                return `<script src="${frameBase}/widgets/site-widgets.js" ${dataset.join(' ')}><\/script>`;
            }

            return '';
        },

        getScriptCode(ctx) {
            const frameBase = this.getFrameBase(ctx);
            const apiBase = this.normalizeApiBase(ctx.siteWidgetsConfig.apiBase);
            const dataset = [
                `data-profile="${this.getSelectedProfileSlug(ctx)}"`,
                `data-api-base="${apiBase}"`,
                `data-position="${ctx.siteWidgetsConfig.position === 'left' ? 'left' : 'right'}"`,
                `data-contact-label="${ctx.siteWidgetsConfig.contactLabel || 'Написать в TaskFlow'}"`,
                `data-title="${ctx.siteWidgetsConfig.chatTitle || 'Чат с командой'}"`,
                `data-subtitle="${ctx.siteWidgetsConfig.chatDescription || 'Обычно отвечаем в рабочее время'}"`,
                `data-brand-color="${ctx.siteWidgetsConfig.brandColor || '#2563eb'}"`,
                `data-brand-button-text="${ctx.siteWidgetsConfig.brandButtonText || '💬'}"`,
                `data-brand-form-title="${ctx.siteWidgetsConfig.brandFormTitle || 'Оставить обращение'}"`,
                `data-brand-form-description="${ctx.siteWidgetsConfig.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.'}"`,
                `data-contact-description="${ctx.siteWidgetsConfig.contactDescription || 'Ответим в чате и при необходимости оформим обращение.'}"`
            ];

            return `<script src="${frameBase}/widgets/site-widgets.js" ${dataset.join(' ')}><\/script>`;
        },

        async copyCode(ctx, type) {
            const code = type === 'script' || type === 'scriptHead' || type === 'scriptFooter'
                ? this.getScriptCode(ctx)
                : this.getEmbedCode(ctx, type);
            if (!code) return;

            try {
                await navigator.clipboard.writeText(code);
            } catch (_) {
                const textarea = document.createElement('textarea');
                textarea.value = code;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }

            ctx.siteWidgetsCopyState[type] = true;
            setTimeout(() => {
                ctx.siteWidgetsCopyState[type] = false;
            }, 1800);
        },

        toggleWidget(ctx, widgetId) {
            const idx = ctx.enabledWidgets.indexOf(widgetId);
            if (idx === -1) {
                ctx.enabledWidgets.push(widgetId);
                ctx.widgetOrder.push(widgetId);
            } else {
                ctx.enabledWidgets = ctx.enabledWidgets.filter(w => w !== widgetId);
                ctx.widgetOrder = ctx.widgetOrder.filter(w => w !== widgetId);
            }
        },

        async saveWidgets(ctx) {
            try {
                const config = getWidgetStorageConfig(ctx);

                localStorage.setItem('taskflow_enabled_widgets', JSON.stringify(config.enabledWidgets));
                localStorage.setItem('taskflow_widget_order', JSON.stringify(config.widgetOrder));
                localStorage.setItem('taskflow_external_resources', JSON.stringify(config.externalResources));

                try {
                    await apiPut('user-settings', {
                        key: 'widgets_config',
                        value: JSON.stringify(config)
                    });
                } catch (e) {
                    console.warn('Failed to save widgets to DB, using localStorage:', e);
                }

                try {
                    await apiPut('settings/weather', {
                        weather_api_key: ctx.weatherApiKey,
                        weather_city: ctx.weatherCity
                    });
                } catch (e) {
                    localStorage.setItem('taskflow_weather_api_key', ctx.weatherApiKey);
                    localStorage.setItem('taskflow_weather_city', ctx.weatherCity);
                }

                ctx.showToast('Виджеты сохранены', 'success');
            } catch (e) {
                console.error('Save widgets error:', e);
            }
        },

        async loadWidgets(ctx) {
            try {
                try {
                    const widgetsRes = await apiGet(`user-settings?key=${encodeURIComponent('widgets_config')}`);
                    if (widgetsRes.success && widgetsRes.data && widgetsRes.data.value) {
                        const config = JSON.parse(widgetsRes.data.value);
                        if (config.enabledWidgets) ctx.enabledWidgets = config.enabledWidgets;
                        if (config.widgetOrder) ctx.widgetOrder = config.widgetOrder;
                        if (config.externalResources) ctx.externalResources = config.externalResources;
                    }
                } catch (e) {
                    console.warn('Failed to load widgets from DB, using localStorage:', e);
                }

                if (ctx.enabledWidgets.length === 0) {
                    const enabled = localStorage.getItem('taskflow_enabled_widgets');
                    const order = localStorage.getItem('taskflow_widget_order');
                    const resources = localStorage.getItem('taskflow_external_resources');
                    if (enabled) ctx.enabledWidgets = JSON.parse(enabled);
                    if (order) ctx.widgetOrder = JSON.parse(order);
                    if (resources) ctx.externalResources = JSON.parse(resources);
                }

                try {
                    const settingsRes = await apiGet('settings/weather');
                    if (settingsRes.success && settingsRes.data) {
                        ctx.weatherApiKey = settingsRes.data.weather_api_key || '';
                        if (settingsRes.data.weather_city) {
                            ctx.weatherCity = settingsRes.data.weather_city;
                        }
                    }
                } catch (e) {
                    const apiKey = localStorage.getItem('taskflow_weather_api_key');
                    const city = localStorage.getItem('taskflow_weather_city');
                    if (apiKey) ctx.weatherApiKey = apiKey;
                    if (city) ctx.weatherCity = city;
                }
            } catch (e) {
                console.error('Load widgets error:', e);
            }
        },

        resetWidgets(ctx) {
            ctx.enabledWidgets = getDefaultEnabledWidgets();
            ctx.widgetOrder = getDefaultEnabledWidgets();
            ctx.externalResources = [];
            ctx.showToast('Виджеты сброшены', 'info');
        },

        addExternalResource(ctx) {
            ctx.externalResources.push({ name: '', url: '' });
        },

        removeExternalResource(ctx, idx) {
            ctx.externalResources.splice(idx, 1);
        },

        openExternalResource(_ctx, url) {
            if (url) window.open(url, '_blank');
        }
    };
})();
