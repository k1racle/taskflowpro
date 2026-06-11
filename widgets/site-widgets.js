(function () {
    var widgetBuild = '2026-04-25-unified-badge';
    var script = document.currentScript;
    if (!script) {
        return;
    }

    var dataset = script.dataset || {};
    var mode = dataset.mode === 'form' ? 'form' : (dataset.mode === 'booking' ? 'booking' : 'chat');
    var baseUrl = (dataset.baseUrl || script.src.replace(/\/site-widgets\.js(?:\?.*)?$/, '')).replace(/\/$/, '');
    var apiBase = (dataset.apiBase || baseUrl.replace(/\/widgets$/, '') + '/api').replace(/\/$/, '');
    var profile = dataset.profile || '';
    var position = dataset.position === 'left' ? 'left' : 'right';
    var width = parseInt(dataset.width || '', 10);
    var height = parseInt(dataset.height || '', 10);
    var pageUrl = window.location.href;
    var pageTitle = document.title || '';
    var zIndex = dataset.zIndex || '2147483000';
    var storageKey = 'workhub_widget_chat_token:' + (profile || 'default') + ':' + apiBase;

    var config = {
        title: dataset.title || 'Чат с командой',
        subtitle: dataset.subtitle || 'Обычно отвечаем в рабочее время',
        brandColor: dataset.brandColor || '#FF4433',
        brandButtonText: dataset.brandButtonText || '💬',
        brandFormTitle: dataset.brandFormTitle || 'Оставить обращение',
        brandFormDescription: dataset.brandFormDescription || 'Коротко опишите вопрос, и мы зарегистрируем обращение.',
        bookingTitle: dataset.bookingTitle || 'Запись',
        bookingDescription: dataset.bookingDescription || 'Выберите услуги и удобное время. Заявка уйдет на подтверждение.',
        bookingSuccessText: dataset.bookingSuccessText || 'Готово. Заявка создана и ожидает подтверждения.',
        contactLabel: dataset.contactLabel || 'Написать в TaskFlow',
        contactDescription: dataset.contactDescription || 'Ответим в чате и при необходимости оформим обращение.',
        chatWidth: width || 420,
        chatHeight: height || 760,
        formWidth: width || 480,
        formHeight: height || 760
    };

    function request(url, options) {
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                if (!response.ok || !payload || payload.success === false) {
                    var message = payload && payload.error ? payload.error : 'Ошибка запроса';
                    throw new Error(message);
                }
                return payload;
            });
        });
    }

    function loadPublicConfig() {
        var url = apiBase + '/index.php?endpoint=settings/site-widgets-public' + (profile ? '&profile=' + encodeURIComponent(profile) : '');
        return request(url, { method: 'GET', credentials: 'omit' }).then(function (result) {
            if (!result || !result.data) {
                return;
            }
            var data = result.data;
            config.title = data.chat_title || config.title;
            config.subtitle = data.chat_description || config.subtitle;
            config.brandColor = data.brand_color || config.brandColor;
            config.brandButtonText = data.brand_button_text || config.brandButtonText;
            config.brandFormTitle = data.brand_form_title || config.brandFormTitle;
            config.brandFormDescription = data.brand_form_description || config.brandFormDescription;
            config.contactLabel = data.contact_label || config.contactLabel;
            config.contactDescription = data.contact_description || config.contactDescription;
            config.chatWidth = Number(data.chat_width) || config.chatWidth;
            config.chatHeight = Number(data.chat_height) || config.chatHeight;
            config.formWidth = Number(data.form_width) || config.formWidth;
            config.formHeight = Number(data.form_height) || config.formHeight;
        }).catch(function () {
            return null;
        });
    }

    function injectStyles() {
        if (document.getElementById('workhub-site-widget-styles')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'workhub-site-widget-styles';
        style.textContent = '' +
            '.wh-widget-root{position:fixed;bottom:18px;' + position + ':18px;z-index:' + zIndex + ';font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#FAFAFA}' +
            '.wh-widget-root *{box-sizing:border-box}' +
            '.wh-widget-launcher{display:flex;align-items:center;justify-content:center;width:60px;height:60px;border:1px solid #FF4433;border-radius:2px;background:' + config.brandColor + ';color:#fff;cursor:pointer;box-shadow:none;font-size:22px;font-weight:700;transition:transform .18s ease,box-shadow .18s ease,filter .18s ease}' +
            '.wh-widget-launcher:hover{transform:translateY(-2px) scale(1.01);box-shadow:none;filter:saturate(1.04)}' +
            '.wh-widget-launcher:focus-visible,.wh-widget-close:focus-visible,.wh-widget-card:focus-visible,.wh-widget-back:focus-visible,.wh-widget-primary:focus-visible,.wh-widget-secondary:focus-visible,.wh-widget-input:focus-visible,.wh-widget-textarea:focus-visible{outline:2px solid rgba(255,68,51,0.35);outline-offset:2px}' +
            '.wh-widget-panel{position:absolute;bottom:74px;' + position + ':0;width:min(' + config.chatWidth + 'px,calc(100vw - 28px));height:min(' + config.chatHeight + 'px,calc(100vh - 104px));display:none;flex-direction:column;background:#0D0D0D;border:1px solid #2A2A2A;border-radius:2px;overflow:hidden;box-shadow:none}' +
            '.wh-widget-panel.open{display:flex}' +
            '.wh-widget-header{padding:16px 16px 12px;background:#0D0D0D;color:#FAFAFA;border-bottom:1px solid #2A2A2A}' +
            '.wh-widget-header-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}' +
            '.wh-widget-brand{display:flex;align-items:flex-start;gap:12px;min-width:0}' +
            '.wh-widget-icon-badge{display:flex;align-items:center;justify-content:center;flex:0 0 auto;padding:0;line-height:1;background:rgba(255,68,51,0.08);border:1px solid rgba(255,68,51,0.2);color:#FF4433;box-shadow:none}' +
            '.wh-widget-icon-badge-sm{width:42px;height:42px;border-radius:2px;font-size:18px}' +
            '.wh-widget-icon-badge-lg{width:72px;height:72px;border-radius:2px;font-size:30px;box-shadow:none}' +
            '.wh-widget-icon-glyph{display:flex;align-items:center;justify-content:center;width:100%;height:100%;line-height:1;flex:0 0 auto}' +
            '.wh-widget-icon-glyph svg{display:block;flex:0 0 auto;width:100%;height:100%;stroke:currentColor;fill:none;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round;overflow:hidden}' +
            '.wh-widget-avatar{flex-shrink:0}' +
            '.wh-widget-title{margin:0 0 3px;font-size:17px;line-height:1.2;font-weight:700;letter-spacing:-.02em;color:#FAFAFA}' +
            '.wh-widget-subtitle{margin:0;font-size:12px;line-height:1.45;color:#666666;max-width:250px}' +
            '.wh-widget-presence{display:inline-flex;align-items:center;gap:7px;margin-top:10px;padding:7px 11px;border-radius:2px;background:#0D0D0D;border:1px solid #2A2A2A;font-size:11px;font-weight:600;line-height:1.2;color:#999999}' +
            '.wh-widget-presence:before{content:"";width:7px;height:7px;border-radius:2px;background:#22c55e;box-shadow:0 0 0 4px rgba(74,222,128,0.12)}' +
            '.wh-widget-close{width:34px;height:34px;border:1px solid #2A2A2A;border-radius:2px;background:#0D0D0D;color:#999999;cursor:pointer;font-size:18px;flex-shrink:0;box-shadow:none}' +
            '.wh-widget-body{padding:14px;overflow:auto;background:#000000;flex:1;display:flex;flex-direction:column}' +
            '.wh-widget-section{display:none}.wh-widget-section.active{display:block}' +
            '.wh-widget-section.active{height:100%}' +
            '.wh-widget-grid{display:grid;gap:12px}' +
            '.wh-widget-card,.wh-widget-topic{position:relative;width:100%;text-align:left;border:1px solid #2A2A2A;border-radius:2px;background:#0D0D0D;padding:16px;cursor:pointer;box-shadow:none;transition:border-color .18s ease,transform .18s ease,box-shadow .18s ease}' +
            '.wh-widget-card:hover,.wh-widget-topic:hover{transform:translateY(-2px);border-color:rgba(99,102,241,.22);box-shadow:none}' +
            '.wh-widget-card strong,.wh-widget-topic strong{display:block;margin-bottom:6px;font-size:15px;line-height:1.35;color:#FAFAFA;letter-spacing:-.015em}' +
            '.wh-widget-card span,.wh-widget-topic span,.wh-widget-note,.wh-widget-helper{display:block;font-size:12px;color:#666666;line-height:1.45}' +
            '.wh-widget-card:after{content:"";position:absolute;top:20px;right:18px;width:8px;height:8px;border-top:2px solid rgba(99,102,241,.7);border-right:2px solid rgba(99,102,241,.7);transform:rotate(45deg)}' +
            '.wh-widget-card{display:flex;align-items:center;gap:16px;padding:18px 46px 18px 18px;min-height:124px}' +
            '.wh-widget-card-header{display:flex;align-items:center;justify-content:center;flex:0 0 72px;width:72px;min-height:72px;margin-bottom:0;padding:0;align-self:center}' +
            '.wh-widget-card-eyebrow{display:inline-block;margin-bottom:0;padding:5px 8px;border-radius:2px;background:rgba(255,68,51,0.08);color:#FF4433;font-size:10px;font-weight:700;line-height:1.1;letter-spacing:.04em;text-transform:uppercase}' +
            '.wh-widget-card-copy{flex:1;min-width:0;padding-right:4px}' +
            '.wh-widget-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}' +
            '.wh-widget-head h2{margin:0 0 4px;font-size:16px;line-height:1.25;color:#FAFAFA;letter-spacing:-.02em}' +
            '.wh-widget-back{border:0;background:transparent;color:' + config.brandColor + ';cursor:pointer;padding:7px 0;font-weight:700;white-space:nowrap}' +
            '.wh-widget-field{margin-bottom:12px}' +
            '.wh-widget-label{display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#999999}' +
            '.wh-widget-input,.wh-widget-textarea{width:100%;border:1px solid #2A2A2A;border-radius:2px;padding:12px 14px;font:inherit;color:#FAFAFA;background:#0D0D0D;transition:border-color .18s ease,box-shadow .18s ease}' +
            '.wh-widget-input::placeholder,.wh-widget-textarea::placeholder{color:#94a3b8}' +
            '.wh-widget-input:focus,.wh-widget-textarea:focus{border-color:#FF4433;box-shadow:0 0 0 4px rgba(255,68,51,0.12)}' +
            '.wh-widget-textarea{min-height:110px;resize:vertical}' +
            '.wh-widget-status{display:none;border-radius:2px;padding:12px 13px;font-size:13px;line-height:1.5;margin-bottom:12px;border:1px solid transparent}' +
            '.wh-widget-status.success{display:block;background:rgba(74,222,128,0.08);color:#4ADE80}' +
            '.wh-widget-status.error{display:block;background:rgba(255,68,51,0.08);color:#FF4433}' +
            '.wh-widget-actions{display:flex;gap:10px;margin-top:14px}' +
            '.wh-widget-primary,.wh-widget-secondary{border:0;border-radius:2px;padding:13px 15px;font:inherit;font-weight:700;cursor:pointer;transition:transform .18s ease,opacity .18s ease,box-shadow .18s ease}' +
            '.wh-widget-primary:hover,.wh-widget-secondary:hover{transform:translateY(-1px)}' +
            '.wh-widget-primary{flex:1;background:' + config.brandColor + ';color:#fff;box-shadow:none}' +
            '.wh-widget-secondary{background:#0D0D0D;color:#CCCCCC;border:1px solid #2A2A2A}' +
            '.wh-widget-primary[disabled]{opacity:.7;cursor:wait}' +
            '.wh-widget-chat-shell{display:flex;flex-direction:column;height:100%;min-height:0}' +
            '.wh-widget-chat-log{display:flex;flex-direction:column;gap:10px;flex:1;min-height:0;overflow:auto;margin-bottom:12px;padding:2px 2px 6px}' +
            '.wh-widget-bubble-row{display:flex;flex-direction:column;gap:4px;max-width:88%}' +
            '.wh-widget-bubble-row.operator{align-self:flex-start;align-items:flex-start}' +
            '.wh-widget-bubble-row.visitor{align-self:flex-end;align-items:flex-end}' +
            '.wh-widget-bubble{padding:10px 12px;border-radius:2px;font-size:13px;line-height:1.5;white-space:pre-wrap;box-shadow:none}' +
            '.wh-widget-bubble.operator{background:#0D0D0D;color:#FAFAFA;border:1px solid #2A2A2A;border-bottom-left-radius:8px}' +
            '.wh-widget-bubble.visitor{background:#1A1A1A;color:#FAFAFA;border-bottom-right-radius:8px}' +
            '.wh-widget-meta{font-size:11px;opacity:.62;padding:0 4px}' +
            '.wh-widget-hero{padding:2px 2px 14px}' +
            '.wh-widget-kicker{display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;padding:6px 10px;border-radius:2px;background:#0D0D0D;border:1px solid #2A2A2A;color:#999999;font-size:11px;font-weight:600;box-shadow:none}' +
            '.wh-widget-kicker:before{content:"";width:6px;height:6px;border-radius:2px;background:' + config.brandColor + ';box-shadow:0 0 0 4px rgba(255,68,51,0.12)}' +
            '.wh-widget-hero-title{margin:0 0 6px;font-size:19px;line-height:1.2;color:#FAFAFA;letter-spacing:-.03em}' +
            '.wh-widget-hero-copy{margin:0;font-size:13px;line-height:1.55;color:#666666;max-width:320px}' +
            '.wh-widget-footnote{margin-top:14px;padding:11px 12px;border-radius:2px;background:#0D0D0D;border:1px solid #2A2A2A;color:#666666;font-size:11px;line-height:1.45;display:flex;align-items:center;justify-content:center;gap:6px}' +
            '.wh-widget-footnote strong{color:#CCCCCC}' +
            '.wh-widget-chat-composer{padding:10px;border-radius:2px;background:#0D0D0D;border:1px solid #2A2A2A;box-shadow:none}' +
            '.wh-widget-chat-composer .wh-widget-field{margin-bottom:10px}' +
            '.wh-widget-chat-composer .wh-widget-textarea{min-height:44px;max-height:120px;line-height:1.45;resize:none;padding-top:11px;padding-bottom:11px}' +
            '.wh-widget-chat-composer .wh-widget-actions{margin-top:0}' +
            '.wh-widget-chat-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}' +
            '.wh-widget-chat-caption{font-size:11px;color:#666666}' +
            '.wh-widget-brandline{margin-top:12px;padding:10px 12px;border-radius:2px;background:#0D0D0D;border:1px solid #2A2A2A;text-align:center;font-size:11px;color:#666666;line-height:1.4}' +
            '.wh-widget-brandline strong{font-weight:700;color:#CCCCCC}' +
            '.wh-widget-brandline .wh-widget-brandline-mark{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;margin-right:6px;border-radius:2px;background:rgba(255,68,51,0.12);color:#FF4433;font-size:10px;font-weight:700;vertical-align:middle}' +
            '.wh-widget-hidden{display:none!important}' +
            '.wh-widget-inline{display:flex;gap:10px;align-items:center;justify-content:space-between}' +
            '@media (max-width:480px){.wh-widget-root{bottom:12px;' + position + ':12px}.wh-widget-launcher{width:56px;height:56px}.wh-widget-panel{width:calc(100vw - 24px);height:calc(100vh - 88px);bottom:68px;border-radius:2px}.wh-widget-header{padding:14px 14px 12px}.wh-widget-body{padding:12px}.wh-widget-subtitle{max-width:none}.wh-widget-hero-title{font-size:18px}.wh-widget-actions{flex-direction:column}.wh-widget-primary,.wh-widget-secondary{width:100%}.wh-widget-chat-log{padding-right:0}.wh-widget-chat-composer .wh-widget-actions{flex-direction:row}.wh-widget-chat-composer .wh-widget-primary,.wh-widget-chat-composer .wh-widget-secondary{width:auto}}';
        document.head.appendChild(style);
    }

    function whenBodyReady(callback) {
        if (document.body) {
            callback();
            return;
        }

        var done = false;
        function finish() {
            if (done || !document.body) {
                return;
            }
            done = true;
            callback();
        }

        document.addEventListener('DOMContentLoaded', finish, { once: true });

        var tries = 0;
        var timer = window.setInterval(function () {
            tries += 1;
            if (document.body || tries > 200) {
                window.clearInterval(timer);
                finish();
            }
        }, 25);
    }

    function mountRoot(node, mountId) {
        whenBodyReady(function () {
            if (!node) {
                return;
            }

            if (mountId) {
                var existing = document.getElementById(mountId);
                if (existing && existing !== node && existing.parentNode) {
                    existing.parentNode.removeChild(existing);
                }
                node.id = mountId;
            }

            document.body.appendChild(node);
        });
    }

    function createNode(tag, className, html) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (typeof html === 'string') {
            node.innerHTML = html;
        }
        return node;
    }

    function renderIconBadge(content, sizeClass, extraClass) {
        var className = 'wh-widget-icon-badge ' + (sizeClass || '');
        if (extraClass) {
            className += ' ' + extraClass;
        }
        return '<span class="' + className.replace(/\s+/g, ' ').trim() + '" aria-hidden="true"><span class="wh-widget-icon-glyph">' + content + '</span></span>';
    }

    function renderCardBadge(glyph) {
        return renderIconBadge(glyph, 'wh-widget-icon-badge-lg');
    }

    function publishDebugInfo(stage, details) {
        var payload = {
            build: widgetBuild,
            stage: stage,
            scriptSrc: script.src,
            apiBase: apiBase,
            profile: profile || 'default',
            mode: mode
        };

        if (details) {
            Object.keys(details).forEach(function (key) {
                payload[key] = details[key];
            });
        }

        try {
            window.__workhubSiteWidgetDebug = payload;
        } catch (e) {
            // ignore assignment errors
        }

        if (typeof console !== 'undefined' && console.info) {
            console.info('[WorkHub widget]', payload);
        }
    }

    function formatTime(value) {
        try {
            return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function rub(value) {
        var amount = Number(value || 0);
        if (!isFinite(amount)) amount = 0;
        try {
            return amount.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 });
        } catch (e) {
            return Math.round(amount) + ' ₽';
        }
    }

    function renderBookingMode() {
        injectStyles();

        var widgetConfig = {};
        var hideBranding = false;
        var displayMode = dataset.displayMode === 'inline' ? 'inline' : 'floating';
        var requireEmail = dataset.requireEmail === 'true';
        var widgetSessionId = 'wh_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
        var pageReferrer = document.referrer || '';

        function trackWidgetEvent(eventName, extra) {
            if (!widgetConfig.profile_id) return;
            try {
                fetch(apiBase + '/booking.php?action=widget-analytics', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({
                        widget_profile_id: widgetConfig.profile_id,
                        event: eventName,
                        page_url: pageUrl,
                        page_title: pageTitle,
                        referrer: pageReferrer,
                        session_id: widgetSessionId
                    }, extra || {}))
                }).catch(function(){});
            } catch (e) {}
        }

        function initBookingWidget(configData) {
            widgetConfig = configData || {};
            hideBranding = !!widgetConfig.hide_branding;

            var companyName = widgetConfig.company_name || config.bookingTitle;
            var brandText = widgetConfig.brand_button_text || config.brandButtonText;

            var root = createNode('div', displayMode === 'inline' ? 'wh-widget-form-inline' : 'wh-widget-root');
            var panel = createNode('div', 'wh-widget-panel');
            var launcher = null;

            if (displayMode === 'floating') {
                launcher = createNode('button', 'wh-widget-launcher', brandText);
                launcher.type = 'button';
                launcher.setAttribute('aria-label', 'Открыть запись');
                root.appendChild(panel);
                root.appendChild(launcher);
            } else {
                panel.classList.add('open');
                panel.style.position = 'relative';
                panel.style.bottom = 'auto';
                panel.style.left = 'auto';
                panel.style.right = 'auto';
                panel.style.width = '100%';
                panel.style.maxHeight = 'none';
                root.appendChild(panel);
            }

            root.setAttribute('data-wh-widget-build', widgetBuild);
            panel.setAttribute('data-wh-widget-build', widgetBuild);

            var brandHeaderIcon = renderIconBadge(brandText, 'wh-widget-icon-badge-sm', 'wh-widget-avatar');
            var slotHtml = '<div class="wh-widget-field">' +
                '<label class="wh-widget-label">Дата</label>' +
                '<input class="wh-widget-input" name="preferred_date" type="date" required>' +
                '</div>' +
                '<div class="wh-widget-field">' +
                '<label class="wh-widget-label">Время</label>' +
                '<div data-role="slot-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">Сначала выберите дату</div>' +
                '</div>';

            panel.innerHTML = '' +
                '<div class="wh-widget-header">' +
                    '<div class="wh-widget-header-top">' +
                        '<div class="wh-widget-brand">' +
                            brandHeaderIcon +
                            '<div><h1 class="wh-widget-title">' + companyName + '</h1><p class="wh-widget-subtitle">' + (widgetConfig.booking_description || config.bookingDescription) + '</p></div>' +
                        '</div>' +
                        (displayMode === 'floating' ? '<button type="button" class="wh-widget-close">×</button>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="wh-widget-body">' +
                    '<form class="wh-widget-request-form" data-role="booking-form">' +
                        '<div class="wh-widget-status" data-role="booking-status"></div>' +
                        '<div class="wh-widget-field">' +
                            '<label class="wh-widget-label">Услуги</label>' +
                            '<div class="wh-widget-panel-block" style="border-radius:2px;border:1px solid #2A2A2A;background:#0D0D0D;padding:10px" data-role="service-list">Загружаем…</div>' +
                        '</div>' +
                        '<div class="wh-widget-field wh-widget-inline">' +
                            '<div style="flex:1">' + slotHtml + '</div>' +
                            '<div style="min-width:120px;text-align:right">' +
                                '<div class="wh-widget-label">Итого</div>' +
                                '<div style="font-weight:800;color:#FAFAFA" data-role="booking-total">0 ₽</div>' +
                                '<div style="font-size:11px;color:#666666" data-role="booking-duration"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Имя</label><input class="wh-widget-input" name="client_name" maxlength="255" required></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Телефон</label><input class="wh-widget-input" name="client_phone" maxlength="80" required placeholder="+7 (___) ___-__-__"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Почта ' + (requireEmail ? '' : '(необязательно)') + '</label><input class="wh-widget-input" name="client_email" type="email" maxlength="255" ' + (requireEmail ? 'required' : '') + '></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Комментарий (необязательно)</label><textarea class="wh-widget-textarea" name="notes" maxlength="5000"></textarea></div>' +
                        '<button class="wh-widget-primary" type="submit">Отправить заявку</button>' +
                    '</form>' +
                    (hideBranding ? '' : '<div class="wh-widget-brandline"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>') +
                '</div>';

            var closeBtn = panel.querySelector('.wh-widget-close');
            function closePanel() {
                panel.classList.remove('open');
            }
            function openPanel() {
                panel.classList.add('open');
                trackWidgetEvent('open');
            }
            if (launcher) {
                launcher.addEventListener('click', function () {
                    if (panel.classList.contains('open')) {
                        closePanel();
                    } else {
                        openPanel();
                    }
                });
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', closePanel);
            }

            var form = panel.querySelector('[data-role="booking-form"]');
            var status = panel.querySelector('[data-role="booking-status"]');
            var serviceList = panel.querySelector('[data-role="service-list"]');
            var slotList = panel.querySelector('[data-role="slot-list"]');
            var totalNode = panel.querySelector('[data-role="booking-total"]');
            var durationNode = panel.querySelector('[data-role="booking-duration"]');
            var dateInput = panel.querySelector('[name="preferred_date"]');
            var servicesById = {};
            var selectedSlot = null;

            function setStatus(type, message) {
                if (!message) {
                    status.className = 'wh-widget-status';
                    status.textContent = '';
                    return;
                }
                status.className = 'wh-widget-status ' + type;
                status.textContent = message;
            }

            function recalc() {
                var ids = [];
                var checkboxes = serviceList.querySelectorAll('input[type="checkbox"][name="service_type_id"]');
                for (var i = 0; i < checkboxes.length; i++) {
                    if (checkboxes[i].checked) {
                        ids.push(parseInt(checkboxes[i].value, 10));
                    }
                }
                var total = 0;
                var minutes = 0;
                for (var j = 0; j < ids.length; j++) {
                    var svc = servicesById[ids[j]];
                    if (!svc) continue;
                    total += Number(svc.effective_price_rub || svc.price_rub || 0);
                    minutes += Number(svc.duration_minutes || 0);
                }
                totalNode.textContent = rub(total);
                durationNode.textContent = minutes > 0 ? (minutes + ' мин') : '';
                // Сбрасываем слот при изменении услуг
                selectedSlot = null;
                if (dateInput.value) {
                    loadSlots(dateInput.value, ids);
                }
            }

            function renderServiceList(list) {
                if (!list || !list.length) {
                    serviceList.textContent = 'Услуги не настроены';
                    return;
                }
                var html = '';
                for (var i = 0; i < list.length; i++) {
                    var svc = list[i];
                    servicesById[svc.id] = svc;
                    var line = '<label style="display:flex;align-items:flex-start;gap:10px;padding:8px 6px;border-radius:2px;cursor:pointer">' +
                        '<input type="checkbox" name="service_type_id" value="' + svc.id + '" style="margin-top:3px">' +
                        '<span style="flex:1;min-width:0">' +
                            '<span style="display:flex;align-items:center;justify-content:space-between;gap:10px">' +
                                '<strong style="font-size:13px;color:#FAFAFA">' + (svc.service_name || svc.name || 'Услуга') + '</strong>' +
                                '<span style="font-weight:800;color:#FAFAFA;white-space:nowrap">' + rub(svc.effective_price_rub || svc.price_rub || 0) + '</span>' +
                            '</span>' +
                            '<span style="display:block;font-size:11px;color:#666666;margin-top:2px">' + (svc.duration_minutes ? (svc.duration_minutes + ' мин') : '') + (svc.promo_label ? (' • ' + svc.promo_label) : '') + '</span>' +
                        '</span>' +
                    '</label>';
                    html += line;
                }
                serviceList.innerHTML = html;
                serviceList.addEventListener('change', recalc);
                recalc();
            }

            function loadSlots(date, serviceIds) {
                if (!date || !serviceIds.length) {
                    slotList.textContent = 'Выберите услуги и дату';
                    return;
                }
                slotList.innerHTML = '<span style="font-size:12px;color:#666666">Загружаем слоты…</span>';
                var url = apiBase + '/booking.php?action=slots&date=' + encodeURIComponent(date) + '&service_ids=' + encodeURIComponent(serviceIds.join(','));
                request(url, { method: 'GET' }).then(function (result) {
                    var slots = (result.data && result.data.slots) || [];
                    if (!slots.length) {
                        slotList.innerHTML = '<span style="font-size:12px;color:#666666">Нет доступных слотов на эту дату</span>';
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < slots.length; i++) {
                        var s = slots[i];
                        html += '<button type="button" data-slot="' + s.datetime + '" style="padding:8px 12px;border:1px solid #2A2A2A;border-radius:2px;background:#0D0D0D;cursor:pointer;font-size:13px;font-weight:600;color:#FAFAFA;transition:all .15s">' + s.time + '</button>';
                    }
                    slotList.innerHTML = html;
                    slotList.querySelectorAll('button[data-slot]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            slotList.querySelectorAll('button[data-slot]').forEach(function (b) {
                                b.style.borderColor = '#e2e8f0';
                                b.style.background = '#fff';
                                b.style.color = '#0f172a';
                            });
                            btn.style.borderColor = 'rgba(99,102,241,.7)';
                            btn.style.background = '#eef2ff';
                            btn.style.color = '#4338ca';
                            selectedSlot = btn.getAttribute('data-slot');
                        });
                    });
                }).catch(function () {
                    slotList.innerHTML = '<span style="font-size:12px;color:#FF4433">Не удалось загрузить слоты</span>';
                });
            }

            if (dateInput) {
                var tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                dateInput.min = tomorrow.toISOString().split('T')[0];
                dateInput.addEventListener('change', function () {
                    var ids = [];
                    var checkboxes = serviceList.querySelectorAll('input[type="checkbox"][name="service_type_id"]:checked');
                    for (var i = 0; i < checkboxes.length; i++) ids.push(parseInt(checkboxes[i].value, 10));
                    loadSlots(dateInput.value, ids);
                });
            }

            // Маска телефона
            var phoneInput = panel.querySelector('[name="client_phone"]');
            if (phoneInput) {
                phoneInput.addEventListener('input', function () {
                    var val = phoneInput.value.replace(/[^\d+\-\(\)\s]/g, '');
                    if (val.length > 80) val = val.slice(0, 80);
                    phoneInput.value = val;
                });
            }

            // Загрузка услуг
            var widgetProfileSlug = profile || '';
            var configUrl = apiBase + '/booking.php?action=widget-config' + (widgetProfileSlug ? '&profile=' + encodeURIComponent(widgetProfileSlug) : '');
            request(configUrl, { method: 'GET' }).then(function (result) {
                var data = result.data || {};
                var services = data.service_types || [];
                renderServiceList(services);
                publishDebugInfo('booking-catalog-loaded', { count: services.length });

                // Логируем просмотр
                if (data.config && data.config.profile_id) {
                    widgetConfig.profile_id = data.config.profile_id;
                    trackWidgetEvent('view');
                }
            }).catch(function (error) {
                serviceList.textContent = 'Не удалось загрузить услуги';
                setStatus('error', error.message || 'Ошибка загрузки');
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                setStatus(null, null);

                if (!selectedSlot) {
                    setStatus('error', 'Выберите дату и время');
                    return;
                }

                var submit = form.querySelector('button[type="submit"]');
                submit.disabled = true;
                submit.textContent = 'Отправляем…';

                var selected = [];
                var checkboxes = serviceList.querySelectorAll('input[type="checkbox"][name="service_type_id"]:checked');
                for (var i = 0; i < checkboxes.length; i++) {
                    selected.push(parseInt(checkboxes[i].value, 10));
                }

                request(apiBase + '/booking.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        service_type_ids: selected,
                        client_name: (form.client_name.value || '').trim(),
                        client_phone: (form.client_phone.value || '').trim(),
                        client_email: (form.client_email.value || '').trim(),
                        preferred_datetime: selectedSlot,
                        notes: (form.notes.value || '').trim(),
                        source: 'widget',
                        page_url: pageUrl,
                        page_title: pageTitle,
                        profile: profile
                    })
                }).then(function () {
                    setStatus('success', config.bookingSuccessText);
                    trackWidgetEvent('submit');
                    form.reset();
                    selectedSlot = null;
                    if (slotList) slotList.innerHTML = '<span style="font-size:12px;color:#666666">Сначала выберите дату</span>';
                    var cb = serviceList.querySelectorAll('input[type="checkbox"]');
                    for (var i = 0; i < cb.length; i++) cb[i].checked = false;
                    recalc();
                }).catch(function (error) {
                    setStatus('error', error.message || 'Не удалось создать заявку');
                }).finally(function () {
                    submit.disabled = false;
                    submit.textContent = 'Отправить заявку';
                });
            });

            if (displayMode === 'inline') {
                var mountTarget = document.getElementById(dataset.target || '');
                if (mountTarget) {
                    mountTarget.appendChild(root);
                } else {
                    mountRoot(root, 'workhub-site-widget-booking-inline');
                }
            } else {
                mountRoot(root, 'workhub-site-widget-booking-root');
            }
        }

        // Загружаем конфигурацию виджета
        var widgetProfileSlug = profile || '';
        var configUrl = apiBase + '/booking.php?action=widget-config' + (widgetProfileSlug ? '&profile=' + encodeURIComponent(widgetProfileSlug) : '');
        request(configUrl, { method: 'GET' }).then(function (result) {
            initBookingWidget(result.data);
        }).catch(function () {
            initBookingWidget({});
        });
    }

    function renderFormMode() {
        injectStyles();
        var root = createNode('div', 'wh-widget-form-inline');
        root.style.maxWidth = (config.formWidth || 480) + 'px';
        var brandHeaderIcon = renderIconBadge(config.brandButtonText, 'wh-widget-icon-badge-sm', 'wh-widget-avatar');
        root.innerHTML = '' +
            '<div class="wh-widget-panel open" style="position:relative;display:flex;bottom:auto;' + position + ':auto;width:100%;max-height:none">' +
                '<div class="wh-widget-header">' +
                    '<div class="wh-widget-header-top">' +
                        '<div class="wh-widget-brand">' +
                            brandHeaderIcon +
                            '<div><div class="wh-widget-title">' + config.brandFormTitle + '</div><p class="wh-widget-subtitle">' + config.brandFormDescription + '</p></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="wh-widget-presence">Бережно принимаем обращения</div>' +
                '</div>' +
                '<div class="wh-widget-body">' +
                    '<form class="wh-widget-request-form">' +
                        '<div class="wh-widget-status" data-role="status"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Имя</label><input class="wh-widget-input" name="name" maxlength="255" required></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Компания</label><input class="wh-widget-input" name="company" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Телефон</label><input class="wh-widget-input" name="phone" maxlength="50"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Почта</label><input class="wh-widget-input" name="email" type="email" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Сообщение</label><textarea class="wh-widget-textarea" name="question" maxlength="5000" required></textarea></div>' +
                        '<div class="wh-widget-field wh-widget-hidden"><input class="wh-widget-input" name="website" tabindex="-1" autocomplete="off"></div>' +
                        '<button class="wh-widget-primary" type="submit">Отправить обращение</button>' +
                    '</form>' +
                    '<div class="wh-widget-brandline"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>' +
                '</div>' +
            '</div>';

        var form = root.querySelector('form');
        var status = root.querySelector('[data-role="status"]');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            submit.textContent = 'Отправляем...';

            request(apiBase + '/helpdesk.php?action=widget-ticket', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: form.name.value.trim(),
                    company: form.company.value.trim(),
                    phone: form.phone.value.trim(),
                    email: form.email.value.trim(),
                    question: form.question.value.trim(),
                    topic: 'collaboration',
                    scenario: 'request',
                    page_url: pageUrl,
                    page_title: pageTitle,
                    profile: profile,
                    website: form.website.value.trim()
                })
            }).then(function (result) {
                status.className = 'wh-widget-status success';
                status.textContent = 'Готово. Обращение зарегистрировано, номер: ' + result.data.ticket_number + '.';
                form.reset();
            }).catch(function (error) {
                status.className = 'wh-widget-status error';
                status.textContent = error.message || 'Не удалось отправить обращение';
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = 'Отправить обращение';
            });
        });

        mountRoot(root, 'workhub-site-widget-form-root');
    }

    function renderChatMode() {
        injectStyles();

        var root = createNode('div', 'wh-widget-root');
        var panel = createNode('div', 'wh-widget-panel');
        var launcher = createNode('button', 'wh-widget-launcher', config.brandButtonText);
        launcher.type = 'button';
        launcher.setAttribute('aria-label', 'Открыть виджет поддержки');
        root.setAttribute('data-wh-widget-build', widgetBuild);
        panel.setAttribute('data-wh-widget-build', widgetBuild);

        var brandHeaderIcon = renderIconBadge(config.brandButtonText, 'wh-widget-icon-badge-sm', 'wh-widget-avatar');
        var chatCardIcon = renderCardBadge('<svg viewBox="0 0 32 32" focusable="false" aria-hidden="true"><path d="M8.5 9.5h15a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H17l-5 4v-4H8.5a3 3 0 0 1-3-3v-8a3 3 0 0 1 3-3Z"></path><path d="M12 15h8"></path><path d="M12 19h5"></path></svg>');
        var requestCardIcon = renderCardBadge('<svg viewBox="0 0 32 32" focusable="false" aria-hidden="true"><rect x="6" y="9" width="20" height="14" rx="3"></rect><path d="m8.5 11 6.6 5.15a1.5 1.5 0 0 0 1.84 0L23.5 11"></path></svg>');

        panel.innerHTML = '' +
            '<div class="wh-widget-header">' +
                '<div class="wh-widget-header-top">' +
                    '<div class="wh-widget-brand">' +
                            brandHeaderIcon +
                            '<div><h1 class="wh-widget-title">' + config.title + '</h1><p class="wh-widget-subtitle">' + config.subtitle + '</p></div>' +
                    '</div>' +
                    '<button type="button" class="wh-widget-close">×</button>' +
                '</div>' +
            '</div>' +
            '<div class="wh-widget-body">' +
                '<section class="wh-widget-section active" data-section="home">' +
                    '<div class="wh-widget-hero">' +
                        '<h2 class="wh-widget-hero-title">Выберите удобный способ связаться</h2>' +
                        '<p class="wh-widget-hero-copy">Короткий чат для быстрого вопроса или обращение с деталями, если нужен более формальный запрос.</p>' +
                    '</div>' +
                    '<div class="wh-widget-grid">' +
                        '<button type="button" class="wh-widget-card" data-open="chat"><div class="wh-widget-card-header">' + chatCardIcon + '</div><div class="wh-widget-card-copy"><strong>' + config.contactLabel + '</strong><span>' + config.contactDescription + '</span></div></button>' +
                        '<button type="button" class="wh-widget-card" data-open="request"><div class="wh-widget-card-header">' + requestCardIcon + '</div><div class="wh-widget-card-copy"><strong>' + config.brandFormTitle + '</strong><span>' + config.brandFormDescription + '</span></div></button>' +
                    '</div>' +
                    '<div class="wh-widget-footnote"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>' +
                '</section>' +
                '<section class="wh-widget-section" data-section="request">' +
                    '<div class="wh-widget-head"><div><h2>' + config.brandFormTitle + '</h2><span class="wh-widget-helper">Контакты и адрес страницы добавим автоматически.</span></div><button class="wh-widget-back" type="button">Назад</button></div>' +
                    '<form class="wh-widget-request-form">' +
                        '<div class="wh-widget-status" data-role="request-status"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Имя</label><input class="wh-widget-input" name="name" maxlength="255" required></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Компания</label><input class="wh-widget-input" name="company" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Телефон</label><input class="wh-widget-input" name="phone" maxlength="50"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Почта</label><input class="wh-widget-input" name="email" type="email" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Сообщение</label><textarea class="wh-widget-textarea" name="question" maxlength="5000" required placeholder="Коротко опишите ваш вопрос или задачу"></textarea></div>' +
                        '<div class="wh-widget-field wh-widget-hidden"><input class="wh-widget-input" name="website" tabindex="-1" autocomplete="off"></div>' +
                        '<button class="wh-widget-primary" type="submit">Отправить обращение</button>' +
                    '</form>' +
                    '<div class="wh-widget-brandline"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>' +
                '</section>' +
                '<section class="wh-widget-section" data-section="chat-intro">' +
                    '<div class="wh-widget-head"><div><h2>' + config.contactLabel + '</h2><span class="wh-widget-helper">Оставьте контакт и начните короткий диалог.</span></div><button class="wh-widget-back" type="button">Назад</button></div>' +
                    '<form class="wh-widget-chat-start-form">' +
                        '<div class="wh-widget-status" data-role="chat-start-status"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Имя</label><input class="wh-widget-input" name="name" maxlength="255" required></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Компания</label><input class="wh-widget-input" name="company" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Телефон</label><input class="wh-widget-input" name="phone" maxlength="50"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Почта</label><input class="wh-widget-input" name="email" type="email" maxlength="255"></div>' +
                        '<div class="wh-widget-field"><label class="wh-widget-label">Первое сообщение</label><textarea class="wh-widget-textarea" name="message" maxlength="5000" required placeholder="Напишите, чем можем помочь"></textarea></div>' +
                        '<div class="wh-widget-field wh-widget-hidden"><input class="wh-widget-input" name="website" tabindex="-1" autocomplete="off"></div>' +
                        '<button class="wh-widget-primary" type="submit">Начать чат</button>' +
                    '</form>' +
                    '<div class="wh-widget-brandline"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>' +
                '</section>' +
                '<section class="wh-widget-section" data-section="chat-live">' +
                    '<div class="wh-widget-chat-shell">' +
                        '<div class="wh-widget-head"><div><h2>Диалог</h2><span class="wh-widget-helper" data-role="chat-ticket-copy"></span></div><button class="wh-widget-back" type="button">Свернуть</button></div>' +
                        '<div class="wh-widget-chat-log" data-role="chat-log"></div>' +
                        '<div class="wh-widget-status" data-role="chat-live-status"></div>' +
                        '<form class="wh-widget-chat-live-form wh-widget-chat-composer">' +
                            '<div class="wh-widget-chat-toolbar"><div class="wh-widget-chat-caption">Сообщения обновляются автоматически</div><button class="wh-widget-secondary" type="button" data-role="chat-refresh">Обновить</button></div>' +
                            '<div class="wh-widget-field"><textarea class="wh-widget-textarea" name="message" maxlength="5000" placeholder="Сообщение"></textarea></div>' +
                            '<div class="wh-widget-actions"><button class="wh-widget-primary" type="submit">Отправить</button></div>' +
                        '</form>' +
                        '<div class="wh-widget-brandline"><span class="wh-widget-brandline-mark">TF</span>Работает на базе <strong>TaskFlow</strong></div>' +
                    '</div>' +
                '</section>' +
            '</div>';

        root.appendChild(panel);
        root.appendChild(launcher);
        mountRoot(root, 'workhub-site-widget-chat-root');
        publishDebugInfo('render-chat', {
            mountId: 'workhub-site-widget-chat-root',
            title: config.title,
            contactLabel: config.contactLabel,
            requestLabel: config.brandFormTitle
        });

        var sections = {
            home: panel.querySelector('[data-section="home"]'),
            request: panel.querySelector('[data-section="request"]'),
            chatIntro: panel.querySelector('[data-section="chat-intro"]'),
            chatLive: panel.querySelector('[data-section="chat-live"]')
        };
        var requestForm = panel.querySelector('.wh-widget-request-form');
        var chatStartForm = panel.querySelector('.wh-widget-chat-start-form');
        var chatLiveForm = panel.querySelector('.wh-widget-chat-live-form');
        var requestStatus = panel.querySelector('[data-role="request-status"]');
        var chatStartStatus = panel.querySelector('[data-role="chat-start-status"]');
        var chatLiveStatus = panel.querySelector('[data-role="chat-live-status"]');
        var chatLog = panel.querySelector('[data-role="chat-log"]');
        var chatTicketCopy = panel.querySelector('[data-role="chat-ticket-copy"]');
        var chatRefresh = panel.querySelector('[data-role="chat-refresh"]');
        var token = '';
        var pollTimer = null;

        function openPanel() {
            panel.classList.add('open');
        }

        function closePanel() {
            panel.classList.remove('open');
        }

        function showSection(name) {
            Object.keys(sections).forEach(function (key) {
                sections[key].classList.toggle('active', key === name);
            });
        }

        function setStatus(node, type, message) {
            node.className = 'wh-widget-status ' + type;
            node.textContent = message;
        }

        function loadStoredToken() {
            try {
                return localStorage.getItem(storageKey) || '';
            } catch (e) {
                return '';
            }
        }

        function storeToken(value) {
            token = value || '';
            try {
                if (token) {
                    localStorage.setItem(storageKey, token);
                } else {
                    localStorage.removeItem(storageKey);
                }
            } catch (e) {
                // ignore storage errors
            }
        }

        function renderMessages(messages) {
            chatLog.innerHTML = '';
            if (!messages.length) {
                chatLog.innerHTML = '<div class="wh-widget-helper">Сообщений пока нет.</div>';
                return;
            }

            messages.forEach(function (item) {
                var side = item.is_operator ? 'operator' : 'visitor';
                var row = createNode('div', 'wh-widget-bubble-row ' + side);
                var bubble = createNode('div', 'wh-widget-bubble ' + side);
                bubble.textContent = item.message || '';
                var meta = createNode('div', 'wh-widget-meta');
                meta.textContent = formatTime(item.created_at);
                row.appendChild(bubble);
                row.appendChild(meta);
                chatLog.appendChild(row);
            });
            chatLog.scrollTop = chatLog.scrollHeight;
        }

        function loadChatMessages(silent) {
            if (!token) {
                return Promise.resolve();
            }

            return request(apiBase + '/helpdesk.php?action=widget-chat&id=messages&token=' + encodeURIComponent(token), {
                method: 'GET',
                credentials: 'omit'
            }).then(function (result) {
                var data = result.data || {};
                renderMessages(data.messages || []);
                if (data.ticket_id) {
                    chatTicketCopy.textContent = 'Обращение #' + data.ticket_id;
                }
                if (!silent) {
                    chatLiveStatus.className = 'wh-widget-status';
                    chatLiveStatus.textContent = '';
                }
                showSection('chatLive');
            }).catch(function (error) {
                if (!silent) {
                    setStatus(chatLiveStatus, 'error', error.message || 'Не удалось загрузить переписку');
                }
            });
        }

        function startPolling() {
            stopPolling();
            pollTimer = window.setInterval(function () {
                loadChatMessages(true);
            }, 5000);
        }

        function stopPolling() {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        launcher.addEventListener('click', function () {
            if (panel.classList.contains('open')) {
                closePanel();
                return;
            }
            openPanel();
            if (token) {
                loadChatMessages(true);
            }
        });

        panel.querySelector('.wh-widget-close').addEventListener('click', closePanel);

        panel.querySelectorAll('[data-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = button.getAttribute('data-open');
                showSection(target === 'chat' ? 'chatIntro' : 'request');
            });
        });

        panel.querySelectorAll('.wh-widget-back').forEach(function (button) {
            button.addEventListener('click', function () {
                if (token && sections.chatLive.classList.contains('active')) {
                    closePanel();
                    return;
                }
                showSection('home');
            });
        });

        requestForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = requestForm.querySelector('button[type="submit"]');
            submit.disabled = true;
            submit.textContent = 'Отправляем...';

            request(apiBase + '/helpdesk.php?action=widget-ticket', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: requestForm.name.value.trim(),
                    company: requestForm.company.value.trim(),
                    phone: requestForm.phone.value.trim(),
                    email: requestForm.email.value.trim(),
                    question: requestForm.question.value.trim(),
                    topic: 'collaboration',
                    scenario: 'request',
                    page_url: pageUrl,
                    page_title: pageTitle,
                    profile: profile,
                    website: requestForm.website.value.trim()
                })
            }).then(function (result) {
                setStatus(requestStatus, 'success', 'Готово. Обращение зарегистрировано, номер: ' + result.data.ticket_number + '.');
                requestForm.reset();
            }).catch(function (error) {
                setStatus(requestStatus, 'error', error.message || 'Не удалось отправить обращение');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = 'Отправить обращение';
            });
        });

        chatStartForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = chatStartForm.querySelector('button[type="submit"]');
            submit.disabled = true;
            submit.textContent = 'Запускаем чат...';

            request(apiBase + '/helpdesk.php?action=widget-chat&id=session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: chatStartForm.name.value.trim(),
                    company: chatStartForm.company.value.trim(),
                    phone: chatStartForm.phone.value.trim(),
                    email: chatStartForm.email.value.trim(),
                    message: chatStartForm.message.value.trim(),
                    topic: 'other',
                    page_url: pageUrl,
                    page_title: pageTitle,
                    profile: profile,
                    website: chatStartForm.website.value.trim()
                })
            }).then(function (result) {
                storeToken(result.data.token || '');
                chatStartForm.reset();
                if (result.data.ticket_number) {
                    chatTicketCopy.textContent = 'Обращение ' + result.data.ticket_number;
                }
                showSection('chatLive');
                return loadChatMessages(true);
            }).then(function () {
                startPolling();
            }).catch(function (error) {
                setStatus(chatStartStatus, 'error', error.message || 'Не удалось запустить чат');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = 'Начать чат';
            });
        });

        chatLiveForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = chatLiveForm.querySelector('button[type="submit"]');
            var message = chatLiveForm.message.value.trim();
            if (!token || !message) {
                return;
            }

            submit.disabled = true;
            submit.textContent = 'Отправляем...';

            request(apiBase + '/helpdesk.php?action=widget-chat&id=messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token, message: message })
            }).then(function () {
                chatLiveForm.reset();
                return loadChatMessages(true);
            }).catch(function (error) {
                setStatus(chatLiveStatus, 'error', error.message || 'Не удалось отправить сообщение');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = 'Отправить';
            });
        });

        chatRefresh.addEventListener('click', function () {
            loadChatMessages(false);
        });

        token = loadStoredToken();
        if (token) {
            loadChatMessages(true);
            startPolling();
        }
    }

    whenBodyReady(function () {
        loadPublicConfig().finally(function () {
            publishDebugInfo('config-loaded', {
                title: config.title,
                subtitle: config.subtitle,
                contactLabel: config.contactLabel,
                requestLabel: config.brandFormTitle
            });
            if (mode === 'form') {
                renderFormMode();
            } else if (mode === 'booking') {
                renderBookingMode();
            } else {
                renderChatMode();
            }
        });
    });
})();
