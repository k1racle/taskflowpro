window.TaskFlowMail = (function () {
    function buildMailSettingsForm(currentMailForm, settings) {
        return {
            ...currentMailForm,
            email: settings.mail_email || currentMailForm.email || '',
            host: settings.mail_host || currentMailForm.host || '',
            port: settings.mail_port || currentMailForm.port || '587',
            smtp_username: settings.mail_smtp_username || settings.mail_username || currentMailForm.smtp_username || '',
            password: '',
            display_name: settings.mail_from_name || currentMailForm.display_name || 'TaskFlow Pro',
            encryption: settings.mail_encryption || currentMailForm.encryption || 'tls',
            signature: settings.mail_signature || currentMailForm.signature || '',
            imap_host: settings.mail_imap_host || currentMailForm.imap_host || '',
            imap_port: settings.mail_imap_port || currentMailForm.imap_port || '993',
            imap_encryption: settings.mail_imap_encryption || currentMailForm.imap_encryption || 'ssl'
        };
    }

    function buildSmtpTestPayload(mailForm) {
        return {
            email: mailForm.email,
            smtp_host: mailForm.host,
            smtp_port: mailForm.port,
            smtp_username: mailForm.smtp_username || mailForm.email,
            smtp_password: mailForm.password,
            smtp_encryption: mailForm.encryption
        };
    }

    function buildImapTestPayload(mailForm) {
        return {
            email: mailForm.email,
            imap_host: mailForm.imap_host,
            imap_port: mailForm.imap_port,
            imap_encryption: mailForm.imap_encryption,
            imap_username: mailForm.smtp_username || mailForm.email,
            imap_password: mailForm.password
        };
    }

    function getEmptyComposeMailForm() {
        return { to: '', subject: '', body: '', is_html: true, use_smtp: false, attachments: [] };
    }

    function getLegacyComposeForm(ctx) {
        if (!ctx.composeForm) {
            ctx.composeForm = { to: '', subject: '', body: '', attachments: [] };
        }

        if (!Array.isArray(ctx.composeForm.attachments)) {
            ctx.composeForm.attachments = [];
        }

        return ctx.composeForm;
    }

    function syncComposeBodyFromEditor(ctx) {
        if (ctx.$refs.mailEditor) {
            ctx.composeMailForm.body = ctx.$refs.mailEditor.innerHTML;
        }
    }

    function resetComposeMailForm(ctx) {
        ctx.composeMailForm = getEmptyComposeMailForm();
    }

    return {
        getEmptyComposeMailForm,

        async loadSettings(ctx) {
            if (!ctx.currentUser || !ctx.currentUser.id) return;

            try {
                const data = await apiGetProfileMailSettings(ctx.currentUser.id);
                if (data.success && data.data) {
                    ctx.mailForm = buildMailSettingsForm(ctx.mailForm, data.data);
                }
            } catch (_error) {
                console.warn('Настройки почты недоступны');
            }
        },

        async saveSettings(ctx) {
            try {
                await apiPut('mail/settings', {
                    ...ctx.mailForm
                });
                ctx.showToast('Настройки почты сохранены', 'success');
            } catch (_error) {
                ctx.showToast('Ошибка сохранения настроек почты', 'error');
            }
        },

        async testConnection(ctx) {
            ctx.showToast('Проверка соединения...', 'info');
            try {
                const result = await apiPost('mail/test', buildSmtpTestPayload(ctx.mailForm));
                if (result.success) {
                    ctx.showToast('Соединение успешно', 'success');
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось подключиться'), 'error');
                }
            } catch (error) {
                ctx.showToast('Ошибка подключения: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async testImapConnection(ctx) {
            ctx.showToast('Проверка IMAP...', 'info');
            try {
                const result = await apiPost('mail/imap-test', buildImapTestPayload(ctx.mailForm));
                if (result.success) {
                    ctx.showToast('IMAP подключение успешно', 'success');
                } else {
                    ctx.showToast('IMAP ошибка: ' + (result.error || 'Не удалось подключиться'), 'error');
                }
            } catch (error) {
                ctx.showToast('IMAP ошибка: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async loadFolders(ctx) {
            try {
                const [localRes, imapRes] = await Promise.all([
                    apiGet('mail/folders'),
                    apiGet('mail/imap-folders').catch(() => ({ success: false }))
                ]);

                if (localRes.success) {
                    const localFolders = localRes.data || [];
                    const imapFolders = imapRes && imapRes.success && Array.isArray(imapRes.data) ? imapRes.data : [];
                    const mapped = window.TaskFlowMailFoldersUi?.mapImapFoldersToUiFolders?.(imapFolders) || [];

                    const byId = new Map();
                    for (const folder of localFolders) byId.set(String(folder.id), folder);
                    for (const folder of mapped) {
                        if (!byId.has(String(folder.id))) byId.set(String(folder.id), folder);
                    }

                    ctx.mailFolders = Array.from(byId.values());
                    if (!ctx.currentMailFolder) {
                        ctx.currentMailFolder = 'inbox';
                    }
                }
            } catch (error) {
                console.error('Ошибка загрузки папок:', error);
            }
        },

        async loadFromFolder(ctx, folder) {
            ctx.currentMailFolder = folder;
            try {
                const hasImap = !!(ctx.mailForm && ctx.mailForm.imap_host);
                const q = (ctx.mailSearch || '').trim();
                const query = q ? `&q=${encodeURIComponent(q)}` : '';
                const star = ctx.mailStarFilter ? '&starred=1' : '';
                const unread = ctx.mailUnreadOnly ? '&unread=1' : '';
                const isImapFolder = String(folder || '').startsWith('imap:');
                const imapFolder = isImapFolder ? String(folder).slice(5) : null;

                if (isImapFolder && hasImap) {
                    await apiPost('mail/sync', { folder: imapFolder, limit: 50 });
                    const data = await apiGet(`mail/emails?folder=${encodeURIComponent(folder)}${query}${star}${unread}`);
                    if (data.success) {
                        ctx.mailList = data.data;
                    }
                    return;
                }

                const data = await apiGet(`mail/emails?folder=${folder}${hasImap ? '&sync=1' : ''}${query}${star}${unread}`);
                if (data.success) {
                    ctx.mailList = data.data;
                }
            } catch (error) {
                console.error('Ошибка загрузки писем:', error);
            }
        },

        async openView(ctx) {
            await ctx.loadMailSettings();
            await this.loadFolders(ctx);
            await this.loadFromFolder(ctx, ctx.currentMailFolder || 'inbox');
        },

        async refresh(ctx) {
            try {
                await this.loadFromFolder(ctx, ctx.currentMailFolder || 'inbox');
                await this.loadFolders(ctx);
                ctx.showToast('Почта обновлена', 'success');
            } catch (error) {
                console.error('Ошибка синхронизации:', error);
                ctx.showToast('Ошибка синхронизации почты', 'error');
            }
        },

        createImapFolderPrompt(ctx) {
            ctx.mailFolderCreateName = '';
            ctx.mailFolderCreateModalOpen = true;
        },

        async createImapFolder(ctx) {
            try {
                const name = String(ctx.mailFolderCreateName || '').trim();
                if (!name) {
                    ctx.showToast('Введите название папки', 'error');
                    return;
                }
                const res = await apiPost('mail/imap-folders', { name });
                if (res.success) {
                    ctx.mailFolderCreateModalOpen = false;
                    await this.loadFolders(ctx);
                    ctx.showToast('Папка создана', 'success');
                } else {
                    ctx.showToast(res.error || 'Не удалось создать папку', 'error');
                }
            } catch (error) {
                console.error('Ошибка создания папки:', error);
                ctx.showToast('Ошибка создания папки', 'error');
            }
        },

        openSettings(ctx) {
            ctx.settingsTab = 'email';
            ctx.loadMailSettings();
            ctx.settingsModalOpen = true;
        },

        async openEmail(ctx, emailId) {
            try {
                let data = await apiGet(`mail/emails/${emailId}`);
                if (data && data.success && Array.isArray(data.data)) {
                    data = await apiGet(`mail/emails?id=${encodeURIComponent(emailId)}`);
                }
                if (data.success) {
                    ctx.viewingEmail = data.data;
                    ctx.emailViewModalOpen = true;

                    const idx = (ctx.mailList || []).findIndex((email) => String(email.id) === String(emailId));
                    const wasUnread = idx >= 0 ? !Number(ctx.mailList[idx].is_read) : false;
                    if (idx >= 0) {
                        ctx.mailList[idx].is_read = 1;
                    }
                    const folderIdx = (ctx.mailFolders || []).findIndex((folder) => String(folder.id) === String(ctx.currentMailFolder));
                    if (wasUnread && folderIdx >= 0 && ctx.currentMailFolder === 'inbox') {
                        if (Number(ctx.mailFolders[folderIdx].count) > 0) {
                            ctx.mailFolders[folderIdx].count = Math.max(0, Number(ctx.mailFolders[folderIdx].count) - 1);
                        }
                    }
                    await this.loadFolders(ctx);
                } else {
                    ctx.showToast(data.error || 'Не удалось открыть письмо', 'error');
                }
            } catch (error) {
                console.error('Ошибка открытия письма:', error);
                ctx.showToast('Ошибка открытия письма', 'error');
            }
        },

        async loadAccounts(ctx) {
            try {
                const data = await apiGet('mail/accounts');
                if (data.success) ctx.mailAccounts = data.data || [];
            } catch (_error) {
                ctx.mailAccounts = [];
            }
        },

        async deleteAccount(ctx, accountId) {
            ctx.openConfirm(
                'Удалить аккаунт?',
                'Удалить этот почтовый аккаунт?',
                async () => {
                    try {
                        await apiDelete(`mail/accounts/${accountId}`);
                        ctx.showToast('Аккаунт удалён', 'success');
                        await this.loadAccounts(ctx);
                    } catch (error) {
                        console.error('Ошибка удаления:', error);
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        toggleEmailSelection(ctx, emailId) {
            ctx.selectedEmails = window.TaskFlowMailFoldersUi?.toggleEmailSelection?.(ctx.selectedEmails, emailId) || ctx.selectedEmails;
        },

        async moveSelectedEmails(ctx, folder) {
            if (ctx.selectedEmails.length === 0) return;
            try {
                const promises = ctx.selectedEmails.map((id) => apiPost('mail/move', { email_id: id, folder }));
                await Promise.all(promises);
                ctx.showToast(`Письма перемещены в ${ctx.getFolderName(folder)}`, 'success');
                ctx.selectedEmails = [];
                await this.loadFromFolder(ctx, ctx.currentMailFolder);
                await this.loadFolders(ctx);
            } catch (error) {
                console.error('Ошибка перемещения:', error);
                ctx.showToast('Ошибка перемещения писем', 'error');
            }
        },

        async toggleStarSelectedEmails(ctx) {
            if (ctx.selectedEmails.length === 0) return;
            try {
                const promises = ctx.selectedEmails.map((id) => {
                    const email = ctx.mailList.find((item) => item.id === id);
                    const newStarred = Number(email?.is_starred) ? 0 : 1;
                    return apiPost('mail/star', { email_id: id, is_starred: newStarred });
                });
                await Promise.all(promises);
                ctx.showToast('Важность изменена', 'success');
                ctx.selectedEmails = [];
                await this.loadFromFolder(ctx, ctx.currentMailFolder);
            } catch (error) {
                console.error('Ошибка изменения важности:', error);
                ctx.showToast('Ошибка', 'error');
            }
        },

        purgeFolderPrompt(ctx, folder) {
            ctx.purgeFolderName = ctx.getFolderName(folder);
            ctx.purgeFolderModalOpen = true;
            ctx.purgeFolderTarget = folder;
        },

        async purgeFolder(ctx) {
            if (!ctx.purgeFolderTarget) return;
            try {
                const result = await apiPost('mail/purge', { folder: ctx.purgeFolderTarget });
                if (result.success) {
                    ctx.showToast('Папка очищена', 'success');
                    ctx.purgeFolderModalOpen = false;
                    ctx.purgeFolderTarget = null;
                    await this.loadFromFolder(ctx, ctx.currentMailFolder);
                    await this.loadFolders(ctx);
                } else {
                    ctx.showToast(result.error || 'Ошибка очистки', 'error');
                }
            } catch (error) {
                console.error('Ошибка очистки:', error);
                ctx.showToast('Ошибка очистки папки', 'error');
            }
        },

        openContextMenu(ctx, event, email) {
            ctx.contextMenuEmail = email;
            ctx.contextMenuX = Math.min(event.clientX, window.innerWidth - 220);
            ctx.contextMenuY = Math.min(event.clientY, window.innerHeight - 300);
            ctx.contextMenuOpen = true;
            ctx.mailContextMenuOpen = true;
            ctx.contextMenuItems = [
                { action: 'reply', label: 'Ответить', iconColor: 'color: var(--lg-primary)' },
                { action: 'forward', label: 'Переслать', iconColor: 'color: var(--lg-primary)' },
                { action: 'star', label: Number(email.is_starred) ? 'Убрать из важного' : 'Важное', iconColor: 'color: #fbbf24' },
                { action: 'markAsRead', label: email.is_read ? 'Пометить непрочитанным' : 'Пометить прочитанным', iconColor: 'color: var(--lg-text-primary)' },
                { action: 'moveToSpam', label: 'В спам', iconColor: 'color: #f97316' },
                { action: 'delete', label: 'Удалить', iconColor: 'color: #ef4444' }
            ];
        },

        async executeContextMenuAction(ctx, action) {
            const email = ctx.contextMenuEmail;
            if (!email) return;
            ctx.contextMenuOpen = false;
            ctx.mailContextMenuOpen = false;

            switch (action) {
                case 'reply':
                    ctx.viewingEmail = email;
                    this.replyToEmail(ctx);
                    break;
                case 'forward':
                    ctx.viewingEmail = email;
                    this.forwardEmail(ctx);
                    break;
                case 'star':
                    await this.toggleEmailStar(ctx, email);
                    break;
                case 'delete':
                    await this.deleteEmail(ctx, email.id);
                    break;
                case 'moveToSpam':
                    await this.moveEmailToFolder(ctx, email.id, 'spam');
                    break;
            }
        },

        async moveEmailToFolder(ctx, emailId, folder) {
            try {
                const result = await apiPost('mail/move', { email_id: emailId, folder });
                if (result.success) {
                    ctx.showToast(`Письмо перемещено в ${ctx.getFolderName(folder)}`, 'success');
                    await this.loadFromFolder(ctx, ctx.currentMailFolder);
                    await this.loadFolders(ctx);
                }
            } catch (error) {
                console.error('Ошибка перемещения:', error);
            }
        },

        async toggleEmailStar(ctx, email) {
            if (!email) return;
            try {
                const newStarred = Number(email.is_starred) ? 0 : 1;
                const result = await apiPost('mail/star', { email_id: email.id, is_starred: newStarred });
                if (result.success) {
                    email.is_starred = newStarred;
                    if (ctx.viewingEmail && ctx.viewingEmail.id === email.id) {
                        ctx.viewingEmail.is_starred = newStarred;
                    }
                }
            } catch (error) {
                console.error('Ошибка изменения важности:', error);
            }
        },

        async deleteEmail(ctx, emailId) {
            if (!confirm('Удалить это письмо?')) return;
            try {
                const result = await apiDelete(`mail/emails/${emailId}`);
                if (result.success) {
                    ctx.showToast('Письмо удалено', 'success');
                    if (ctx.emailViewModalOpen) {
                        ctx.emailViewModalOpen = false;
                        ctx.viewingEmail = null;
                    }
                    await this.loadFromFolder(ctx, ctx.currentMailFolder);
                    await this.loadFolders(ctx);
                } else {
                    ctx.showToast(result.error || 'Ошибка удаления', 'error');
                }
            } catch (error) {
                console.error('Ошибка удаления:', error);
            }
        },

        openFromMenu(ctx) {
            if (ctx.mailContextMenuEmail?.id) {
                ctx.openEmail(ctx.mailContextMenuEmail.id);
            }
            ctx.closeContextMenu();
        },

        replyFromMenu(ctx) {
            if (ctx.mailContextMenuEmail?.id) {
                ctx.openEmail(ctx.mailContextMenuEmail.id).then(() => ctx.replyToEmail());
            }
            ctx.closeContextMenu();
        },

        async toggleStarFromMenu(ctx) {
            const emailId = ctx.mailContextMenuEmail?.id;
            if (!emailId) return;
            const isStarred = !!Number(ctx.mailContextMenuEmail?.is_starred);
            try {
                const res = await apiPost('mail/star', { email_id: emailId, is_starred: isStarred ? 0 : 1 });
                if (res.success) {
                    const idx = (ctx.mailList || []).findIndex((email) => String(email.id) === String(emailId));
                    if (idx >= 0) ctx.mailList[idx].is_starred = isStarred ? 0 : 1;
                    ctx.showToast(isStarred ? 'Убрано из важного' : 'Добавлено в важное', 'success');
                } else {
                    ctx.showToast(res.error || 'Не удалось обновить', 'error');
                }
            } catch (error) {
                console.error('toggle star error', error);
                ctx.showToast('Ошибка', 'error');
            }
            ctx.closeContextMenu();
        },

        async toggleStarQuick(ctx, email) {
            const emailId = email?.id;
            if (!emailId) return;
            const isStarred = !!Number(email?.is_starred);
            try {
                const res = await apiPost('mail/star', { email_id: emailId, is_starred: isStarred ? 0 : 1 });
                if (res.success) {
                    email.is_starred = isStarred ? 0 : 1;
                    if (ctx.mailStarFilter && isStarred) {
                        ctx.mailList = (ctx.mailList || []).filter((item) => String(item.id) !== String(emailId));
                    }
                } else {
                    ctx.showToast(res.error || 'Не удалось обновить', 'error');
                }
            } catch (error) {
                console.error('toggle star error', error);
                ctx.showToast('Ошибка', 'error');
            }
        },

        deleteFromMenu(ctx) {
            const emailId = ctx.mailContextMenuEmail?.id;
            if (emailId) ctx.deleteEmail(emailId);
            ctx.closeContextMenu();
        },

        openComposeModal(ctx) {
            ctx.mailComposeModalOpen = true;
            ctx.composeMailForm = getEmptyComposeMailForm();
        },

        attachLegacyFiles(ctx, event) {
            const files = event?.target?.files;
            if (!files || files.length === 0) return;

            const composeForm = getLegacyComposeForm(ctx);
            for (const file of files) {
                composeForm.attachments.push({
                    name: file.name,
                    file
                });
            }

            event.target.value = '';
        },

        async sendLegacyEmail(ctx) {
            const composeForm = getLegacyComposeForm(ctx);
            if (!composeForm.to || !composeForm.subject) {
                ctx.showToast('Заполните получателя и тему', 'error');
                return;
            }

            try {
                let body = composeForm.body;
                if (ctx.mailForm?.signature) {
                    body += '<br><br>--<br>' + ctx.mailForm.signature;
                }

                const formData = new FormData();
                formData.append('to', composeForm.to);
                formData.append('subject', composeForm.subject);
                formData.append('body', body);
                formData.append('from_name', ctx.mailForm?.display_name || 'TaskFlow Pro');

                for (const attachment of composeForm.attachments) {
                    formData.append('attachments[]', attachment.file);
                }

                const token = getToken();
                const url = `api/index.php?endpoint=mail/send&_t=${Date.now()}&token=${token}`;
                const requestOptions = {
                    method: 'POST',
                    headers: {
                        Authorization: `Bearer ${token}`
                    },
                    body: formData
                };

                const data = typeof window.fetchJsonOrThrow === 'function'
                    ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось отправить письмо')
                    : await (await fetch(url, requestOptions)).json();

                if (data.success) {
                    ctx.showToast('Письмо отправлено', 'success');
                    ctx.composeForm = { to: '', subject: '', body: '', attachments: [] };
                    ctx.mailView = 'sent';
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось отправить'), 'error');
                }
            } catch (error) {
                console.error('Ошибка отправки письма:', error);
                ctx.showToast('Ошибка отправки: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        saveLegacyDraft(ctx) {
            const composeForm = getLegacyComposeForm(ctx);
            if (!composeForm.subject && !composeForm.body) {
                ctx.showToast('Черновик пуст', 'error');
                return;
            }

            ctx.mailDrafts.push({
                id: Date.now(),
                subject: composeForm.subject || 'Без темы',
                body: composeForm.body,
                date: new Date().toLocaleDateString('ru-RU')
            });

            ctx.showToast('Сохранено в черновики', 'success');
            ctx.composeForm = { to: '', subject: '', body: '', attachments: [] };
            ctx.mailView = 'drafts';
        },

        replyToEmail(ctx) {
            if (!ctx.viewingEmail) return;
            ctx.mailComposeModalOpen = true;
            ctx.composeMailForm = {
                to: ctx.viewingEmail.sender_email || '',
                subject: 'Re: ' + (ctx.viewingEmail.subject || ''),
                body: this.prepareReplyBody(ctx),
                is_html: ctx.viewingEmail.is_html,
                use_smtp: true,
                attachments: []
            };
            ctx.emailViewModalOpen = false;
        },

        prepareReplyBody(ctx) {
            const email = ctx.viewingEmail;
            return window.TaskFlowMailFoldersUi?.formatReplyBody?.(email, ctx.formatEmailDate(email?.sent_at)) || '';
        },

        forwardEmail(ctx) {
            if (!ctx.viewingEmail) return;
            ctx.mailComposeModalOpen = true;
            ctx.composeMailForm = {
                to: '',
                subject: 'Fwd: ' + (ctx.viewingEmail.subject || ''),
                body: this.prepareForwardBody(ctx),
                is_html: ctx.viewingEmail.is_html,
                use_smtp: true,
                attachments: []
            };
            ctx.emailViewModalOpen = false;
        },

        prepareForwardBody(ctx) {
            const email = ctx.viewingEmail;
            return window.TaskFlowMailFoldersUi?.formatForwardBody?.(email, ctx.formatEmailDate(email?.sent_at)) || '';
        },

        quickReply(ctx, text) {
            if (!ctx.viewingEmail) return;
            ctx.mailComposeModalOpen = true;
            ctx.composeMailForm = {
                to: ctx.viewingEmail.sender_email || '',
                subject: 'Re: ' + (ctx.viewingEmail.subject || ''),
                body: text,
                is_html: false,
                use_smtp: true,
                attachments: []
            };
            ctx.emailViewModalOpen = false;
        },

        applyMailFormat(ctx, command) {
            document.execCommand(command, false, null);
            syncComposeBodyFromEditor(ctx);
        },

        insertMailLink(ctx) {
            const url = prompt('Введите URL ссылки:', 'https://');
            if (!url) return;

            document.execCommand('createLink', false, url);
            syncComposeBodyFromEditor(ctx);
        },

        async insertInlineImage(ctx, event) {
            const file = event.target.files[0];
            if (!file || !file.type.startsWith('image/')) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                const imgHtml = `<img src="${e.target.result}" alt="image" style="max-width: 100%; height: auto;">`;
                if (ctx.$refs.mailEditor) {
                    document.execCommand('insertHTML', false, imgHtml);
                    syncComposeBodyFromEditor(ctx);
                } else {
                    ctx.composeMailForm.body += imgHtml;
                }
            };
            reader.readAsDataURL(file);
            event.target.value = '';
        },

        handleAttachments(ctx, event) {
            const files = Array.from(event.target.files);
            if (!ctx.composeMailForm.attachments) {
                ctx.composeMailForm.attachments = [];
            }

            ctx.composeMailForm.attachments.push(...files);
            event.target.value = '';
        },

        removeAttachment(ctx, index) {
            if (ctx.composeMailForm.attachments) {
                ctx.composeMailForm.attachments.splice(index, 1);
            }
        },

        async sendMail(ctx) {
            if (!ctx.composeMailForm.to || !ctx.composeMailForm.subject || !ctx.composeMailForm.body) {
                ctx.showToast('Заполните все обязательные поля', 'error');
                return;
            }
            ctx.mailSending = true;
            try {
                const formData = new FormData();
                formData.append('recipient_email', ctx.composeMailForm.to);
                formData.append('subject', ctx.composeMailForm.subject);
                formData.append('body', ctx.composeMailForm.body);
                formData.append('is_html', ctx.composeMailForm.is_html ? '1' : '0');
                formData.append('use_smtp', ctx.composeMailForm.use_smtp ? '1' : '0');
                if (ctx.composeMailForm.attachments && ctx.composeMailForm.attachments.length > 0) {
                    for (let i = 0; i < ctx.composeMailForm.attachments.length; i++) {
                        formData.append('attachments[]', ctx.composeMailForm.attachments[i]);
                    }
                }
                const url = 'api/index.php?endpoint=mail/send';
                const requestOptions = {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${getToken()}` },
                    body: formData
                };
                const data = typeof window.fetchJsonOrThrow === 'function'
                    ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось отправить письмо')
                    : await (await fetch(url, requestOptions)).json();
                if (data.success) {
                    ctx.showToast('Письмо успешно отправлено!', 'success');
                    ctx.mailComposeModalOpen = false;
                    resetComposeMailForm(ctx);
                    await this.loadFromFolder(ctx, 'sent');
                    await this.loadFolders(ctx);
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось отправить'), 'error');
                }
            } catch (error) {
                console.error('Ошибка отправки:', error);
                ctx.showToast('Ошибка отправки письма', 'error');
            } finally {
                ctx.mailSending = false;
            }
        },

        async saveDraft(ctx) {
            if (!ctx.composeMailForm.subject && !ctx.composeMailForm.body) {
                ctx.showToast('Черновик пуст', 'error');
                return;
            }
            try {
                const result = await apiPost('mail/send', {
                    recipient_email: ctx.composeMailForm.to || '',
                    subject: ctx.composeMailForm.subject || '',
                    body: ctx.composeMailForm.body || '',
                    is_html: ctx.composeMailForm.is_html ? 1 : 0,
                    save_as_draft: true
                });
                if (result.success) {
                    ctx.showToast('Сохранено в черновики', 'success');
                    ctx.mailComposeModalOpen = false;
                    await this.loadFromFolder(ctx, 'drafts');
                } else {
                    ctx.showToast(result.error || 'Ошибка сохранения', 'error');
                }
            } catch (error) {
                console.error('Ошибка сохранения:', error);
                ctx.showToast('Ошибка сохранения черновика', 'error');
            }
        },

        async syncFolder(ctx, folder = 'INBOX') {
            if (ctx.mailSyncing) return;
            ctx.mailSyncing = true;
            try {
                const result = await apiPost('mail/sync', { folder, limit: 50 });
                if (result.success) {
                    ctx.showToast(`Синхронизировано: ${result.inserted || 0} новых писем`, 'success');
                    ctx.lastSyncTime = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
                    await this.loadFromFolder(ctx, ctx.currentMailFolder);
                    await this.loadFolders(ctx);
                } else {
                    ctx.showToast(result.error || 'Ошибка синхронизации', 'error');
                }
            } catch (error) {
                console.error('Ошибка синхронизации:', error);
                ctx.showToast('Ошибка синхронизации с сервером', 'error');
            } finally {
                ctx.mailSyncing = false;
            }
        }
    };
})();
