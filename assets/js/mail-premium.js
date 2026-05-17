/**
 * MAIL MODULE - PREMIUM 2027 (Yandex/Gmail Style)
 * Полностью рабочий почтовый клиент с двухсторонней IMAP синхронизацией
 */

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================

function initMail() {
    console.log('📧 Инициализация почты...');
    loadMailFolders();
    loadMailFromFolder('inbox');
    loadMailAccounts();
}

// ============================================
// ЗАГРУЗКА ПАПОК
// ============================================

async function loadMailFolders() {
    try {
        const response = await fetch('api/index.php?endpoint=mail/folders', {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });
        const data = await response.json();

        if (data.success) {
            this.mailFolders = data.data;
            console.log('✅ Папки загружены:', this.mailFolders);
        }
    } catch (error) {
        console.error('❌ Ошибка загрузки папок:', error);
    }
}

// ============================================
// ЗАГРУЗКА ПИСЕМ
// ============================================

async function loadMailFromFolder(folder) {
    this.mailLoading = true;
    this.currentMailFolder = folder || 'inbox';
    
    try {
        let url = `api/index.php?endpoint=mail/emails&folder=${this.currentMailFolder}`;
        
        // Параметры фильтрации
        const params = [];
        if (this.mailSearch) params.push(`q=${encodeURIComponent(this.mailSearch)}`);
        if (this.mailStarFilter) params.push('starred=1');
        if (this.mailUnreadOnly) params.push('unread=1');
        
        if (params.length > 0) {
            url += '&' + params.join('&');
        }
        
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });
        const data = await response.json();

        if (data.success) {
            this.mailList = data.data || [];
            console.log('✅ Письма загружены:', this.mailList.length);
        }
    } catch (error) {
        console.error('❌ Ошибка загрузки писем:', error);
    } finally {
        this.mailLoading = false;
    }
}

// ============================================
// СИНХРОНИЗАЦИЯ С IMAP
// ============================================

async function syncImapFolder(folder = 'INBOX') {
    if (this.mailSyncing) return;
    
    this.mailSyncing = true;
    
    try {
        const response = await fetch('api/index.php?endpoint=mail/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                folder: folder,
                limit: 50
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            this.showToast(`Синхронизировано: ${data.inserted || 0} новых писем`, 'success');
            this.lastSyncTime = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            
            // Обновляем список писем
            await loadMailFromFolder(this.currentMailFolder);
            await loadMailFolders();
        } else {
            this.showToast(data.error || 'Ошибка синхронизации', 'error');
        }
    } catch (error) {
        console.error('❌ Ошибка синхронизации:', error);
        this.showToast('Ошибка синхронизации с сервером', 'error');
    } finally {
        this.mailSyncing = false;
    }
}

// ============================================
// ОТКРЫТИЕ ПИСЬМА
// ============================================

async function openEmail(emailId) {
    try {
        const response = await fetch(`api/index.php?endpoint=mail/emails/${emailId}`, {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });
        const data = await response.json();

        if (data.success) {
            this.viewingEmail = data.data;
            this.emailViewModalOpen = true;

            // Обновляем список (помеченное как прочитанное)
            await loadMailFromFolder(this.currentMailFolder);
            await loadMailFolders();
        }
    } catch (error) {
        console.error('❌ Ошибка открытия письма:', error);
    }
}

function closeEmailView() {
    this.emailViewModalOpen = false;
    this.viewingEmail = null;
}

// ============================================
// ОТПРАВКА ПИСЬМА
// ============================================

async function sendMail() {
    if (!this.composeMailForm.to || !this.composeMailForm.subject || !this.composeMailForm.body) {
        this.showToast('Заполните все обязательные поля', 'error');
        return;
    }

    this.mailSending = true;

    try {
        const formData = new FormData();
        formData.append('recipient_email', this.composeMailForm.to);
        formData.append('subject', this.composeMailForm.subject);
        formData.append('body', this.composeMailForm.body);
        formData.append('is_html', this.composeMailForm.is_html ? '1' : '0');
        formData.append('use_smtp', this.composeMailForm.use_smtp ? '1' : '0');
        
        // Вложения
        if (this.composeMailForm.attachments && this.composeMailForm.attachments.length > 0) {
            for (let i = 0; i < this.composeMailForm.attachments.length; i++) {
                formData.append('attachments[]', this.composeMailForm.attachments[i]);
            }
        }

        const response = await fetch('api/index.php?endpoint=mail/send', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${getToken()}`
            },
            body: formData
        });
        
        const data = await response.json();

        if (data.success) {
            this.showToast('Письмо успешно отправлено!', 'success');
            this.mailComposeModalOpen = false;
            this.composeMailForm = { to: '', subject: '', body: '', is_html: true, use_smtp: false, attachments: [] };
            
            // Обновляем список
            await loadMailFromFolder('sent');
            await loadMailFolders();
        } else {
            this.showToast('Ошибка: ' + (data.error || 'Не удалось отправить'), 'error');
        }
    } catch (error) {
        console.error('❌ Ошибка отправки:', error);
        this.showToast('Ошибка отправки письма', 'error');
    } finally {
        this.mailSending = false;
    }
}

// ============================================
// СОХРАНЕНИЕ В ЧЕРНОВИКИ
// ============================================

async function saveMailDraft() {
    if (!this.composeMailForm.subject && !this.composeMailForm.body) {
        this.showToast('Черновик пуст', 'error');
        return;
    }

    try {
        const response = await fetch('api/index.php?endpoint=mail/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                recipient_email: this.composeMailForm.to || '',
                subject: this.composeMailForm.subject || '',
                body: this.composeMailForm.body || '',
                is_html: this.composeMailForm.is_html ? 1 : 0,
                save_as_draft: true
            })
        });
        
        const data = await response.json();

        if (data.success) {
            this.showToast('Сохранено в черновики', 'success');
            this.mailComposeModalOpen = false;
            await loadMailFromFolder('drafts');
        } else {
            this.showToast(data.error || 'Ошибка сохранения', 'error');
        }
    } catch (error) {
        console.error('❌ Ошибка сохранения:', error);
        this.showToast('Ошибка сохранения черновика', 'error');
    }
}

// ============================================
// УДАЛЕНИЕ ПИСЬМА
// ============================================

async function deleteEmail(emailId) {
    if (!confirm('Удалить это письмо?')) return;

    try {
        const response = await fetch(`api/index.php?endpoint=mail/emails/${emailId}`, {
            method: 'DELETE',
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });
        const data = await response.json();

        if (data.success) {
            this.showToast('Письмо удалено', 'success');
            
            if (this.emailViewModalOpen) {
                this.emailViewModalOpen = false;
                this.viewingEmail = null;
            }
            
            await loadMailFromFolder(this.currentMailFolder);
            await loadMailFolders();
        } else {
            this.showToast(data.error || 'Ошибка удаления', 'error');
        }
    } catch (error) {
        console.error('❌ Ошибка удаления:', error);
    }
}

// ============================================
// ОТВЕТ НА ПИСЬМО
// ============================================

function replyToEmail() {
    if (!this.viewingEmail) return;

    this.mailComposeModalOpen = true;
    this.composeMailForm = {
        to: this.viewingEmail.sender_email || '',
        subject: 'Re: ' + (this.viewingEmail.subject || ''),
        body: this.prepareReplyBody(),
        is_html: this.viewingEmail.is_html,
        use_smtp: true,
        attachments: []
    };
    
    this.emailViewModalOpen = false;
}

function prepareReplyBody() {
    const email = this.viewingEmail;
    if (!email) return '';
    
    const replyHeader = `\n\n--- Исходное письмо ---\nОт: ${email.sender_name || email.sender_email}\nДата: ${this.formatEmailDate(email.sent_at)}\nТема: ${email.subject}\n\n`;
    
    return replyHeader + (email.body || '');
}

function forwardEmail() {
    if (!this.viewingEmail) return;

    this.mailComposeModalOpen = true;
    this.composeMailForm = {
        to: '',
        subject: 'Fwd: ' + (this.viewingEmail.subject || ''),
        body: this.prepareForwardBody(),
        is_html: this.viewingEmail.is_html,
        use_smtp: true,
        attachments: []
    };
    
    this.emailViewModalOpen = false;
}

function prepareForwardBody() {
    const email = this.viewingEmail;
    if (!email) return '';
    
    const forwardHeader = `\n\n--- Пересылаемое письмо ---\nОт: ${email.sender_name || email.sender_email}\nДата: ${this.formatEmailDate(email.sent_at)}\nТема: ${email.subject}\n\n`;
    
    return forwardHeader + (email.body || '');
}

function quickReply(text) {
    if (!this.viewingEmail) return;
    
    this.mailComposeModalOpen = true;
    this.composeMailForm = {
        to: this.viewingEmail.sender_email || '',
        subject: 'Re: ' + (this.viewingEmail.subject || ''),
        body: text,
        is_html: false,
        use_smtp: true,
        attachments: []
    };
    
    this.emailViewModalOpen = false;
}

// ============================================
// ВАЖНЫЕ ПИСЬМА
// ============================================

async function toggleEmailStar(email) {
    if (!email) return;
    
    try {
        const newStarred = Number(email.is_starred) ? 0 : 1;
        
        const response = await fetch('api/index.php?endpoint=mail/star', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                email_id: email.id,
                is_starred: newStarred
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            email.is_starred = newStarred;
            
            // Обновляем просмотр письма если открыто
            if (this.viewingEmail && this.viewingEmail.id === email.id) {
                this.viewingEmail.is_starred = newStarred;
            }
        }
    } catch (error) {
        console.error('❌ Ошибка изменения важности:', error);
    }
}

function toggleMailStarQuick(email) {
    toggleEmailStar(email);
}

// ============================================
// ВЫДЕЛЕНИЕ ПИСЕМ
// ============================================

function toggleEmailSelection(emailId) {
    const index = this.selectedEmails.indexOf(emailId);
    if (index > -1) {
        this.selectedEmails.splice(index, 1);
    } else {
        this.selectedEmails.push(emailId);
    }
}

async function moveSelectedEmails(folder) {
    if (this.selectedEmails.length === 0) return;
    
    try {
        const promises = this.selectedEmails.map(id => 
            fetch('api/index.php?endpoint=mail/move', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${getToken()}`
                },
                body: JSON.stringify({
                    email_id: id,
                    folder: folder
                })
            })
        );
        
        await Promise.all(promises);
        
        this.showToast(`Письма перемещены в ${getFolderName(folder)}`, 'success');
        this.selectedEmails = [];
        
        await loadMailFromFolder(this.currentMailFolder);
        await loadMailFolders();
    } catch (error) {
        console.error('❌ Ошибка перемещения:', error);
        this.showToast('Ошибка перемещения писем', 'error');
    }
}

async function toggleStarSelectedEmails() {
    if (this.selectedEmails.length === 0) return;
    
    try {
        const promises = this.selectedEmails.map(id => {
            const email = this.mailList.find(e => e.id === id);
            const newStarred = Number(email?.is_starred) ? 0 : 1;
            
            return fetch('api/index.php?endpoint=mail/star', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${getToken()}`
                },
                body: JSON.stringify({
                    email_id: id,
                    is_starred: newStarred
                })
            });
        });
        
        await Promise.all(promises);
        
        this.showToast('Важность изменена', 'success');
        this.selectedEmails = [];
        
        await loadMailFromFolder(this.currentMailFolder);
    } catch (error) {
        console.error('❌ Ошибка изменения важности:', error);
        this.showToast('Ошибка', 'error');
    }
}

// ============================================
// ОЧИСТКА ПАПКИ
// ============================================

function mailConfirmPurgeFolder(folder) {
    this.purgeFolderName = getFolderName(folder);
    this.purgeFolderModalOpen = true;
    this.purgeFolderTarget = folder;
}

async function purgeFolder() {
    if (!this.purgeFolderTarget) return;
    
    try {
        const response = await fetch('api/index.php?endpoint=mail/purge', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                folder: this.purgeFolderTarget
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            this.showToast('Папка очищена', 'success');
            this.purgeFolderModalOpen = false;
            this.purgeFolderTarget = null;
            
            await loadMailFromFolder(this.currentMailFolder);
            await loadMailFolders();
        } else {
            this.showToast(data.error || 'Ошибка очистки', 'error');
        }
    } catch (error) {
        console.error('❌ Ошибка очистки:', error);
        this.showToast('Ошибка очистки папки', 'error');
    }
}

// ============================================
// КОНТЕКСТНОЕ МЕНЮ
// ============================================

function openMailContextMenu(event, email) {
    this.contextMenuEmail = email;
    this.contextMenuX = Math.min(event.clientX, window.innerWidth - 220);
    this.contextMenuY = Math.min(event.clientY, window.innerHeight - 300);
    this.contextMenuOpen = true;
    
    this.contextMenuItems = [
        { action: 'reply', label: 'Ответить', iconColor: 'color: var(--lg-primary)' },
        { action: 'forward', label: 'Переслать', iconColor: 'color: var(--lg-primary)' },
        { action: 'star', label: Number(email.is_starred) ? 'Убрать из важного' : 'Важное', iconColor: 'color: #fbbf24' },
        { action: 'markAsRead', label: email.is_read ? 'Пометить непрочитанным' : 'Пометить прочитанным', iconColor: 'color: var(--lg-text-primary)' },
        { action: 'moveToSpam', label: 'В спам', iconColor: 'color: #f97316' },
        { action: 'delete', label: 'Удалить', iconColor: 'color: #ef4444' }
    ];
}

async function executeContextMenuAction(action) {
    const email = this.contextMenuEmail;
    if (!email) return;
    
    this.contextMenuOpen = false;
    
    switch (action) {
        case 'reply':
            this.viewingEmail = email;
            replyToEmail();
            break;
        case 'forward':
            this.viewingEmail = email;
            forwardEmail();
            break;
        case 'star':
            await toggleEmailStar(email);
            break;
        case 'markAsRead':
            // TODO: добавить endpoint для пометки прочитанным/непрочитанным
            break;
        case 'moveToSpam':
            await moveEmailToFolder(email.id, 'spam');
            break;
        case 'delete':
            await deleteEmail(email.id);
            break;
    }
}

async function moveEmailToFolder(emailId, folder) {
    try {
        const response = await fetch('api/index.php?endpoint=mail/move', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                email_id: emailId,
                folder: folder
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            this.showToast(`Письмо перемещено в ${getFolderName(folder)}`, 'success');
            await loadMailFromFolder(this.currentMailFolder);
            await loadMailFolders();
        }
    } catch (error) {
        console.error('❌ Ошибка перемещения:', error);
    }
}

// ============================================
// ФОРМАТИРОВАНИЕ ТЕКСТА
// ============================================

function applyMailFormat(command) {
    document.execCommand(command, false, null);
    if (this.$refs.mailEditor) {
        this.composeMailForm.body = this.$refs.mailEditor.innerHTML;
    }
}

function insertMailLink() {
    const url = prompt('Введите URL ссылки:', 'https://');
    if (url) {
        document.execCommand('createLink', false, url);
        if (this.$refs.mailEditor) {
            this.composeMailForm.body = this.$refs.mailEditor.innerHTML;
        }
    }
}

async function insertInlineImage(event) {
    const file = event.target.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    
    const reader = new FileReader();
    reader.onload = (e) => {
        const imgHtml = `<img src="${e.target.result}" alt="image" style="max-width: 100%; height: auto;">`;
        
        if (this.$refs.mailEditor) {
            document.execCommand('insertHTML', false, imgHtml);
            this.composeMailForm.body = this.$refs.mailEditor.innerHTML;
        } else {
            this.composeMailForm.body += imgHtml;
        }
    };
    reader.readAsDataURL(file);
    
    event.target.value = '';
}

// ============================================
// ОБРАБОТКА ВЛОЖЕНИЙ
// ============================================

function handleMailAttachments(event) {
    const files = Array.from(event.target.files);
    if (!this.composeMailForm.attachments) {
        this.composeMailForm.attachments = [];
    }
    this.composeMailForm.attachments.push(...files);
    event.target.value = '';
}

function removeMailAttachment(index) {
    if (this.composeMailForm.attachments) {
        this.composeMailForm.attachments.splice(index, 1);
    }
}

// ============================================
// ЗАГРУЗКА SMTP АККАУНТОВ
// ============================================

async function loadMailAccounts() {
    try {
        const response = await fetch('api/index.php?endpoint=mail/accounts', {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });
        const data = await response.json();

        if (data.success) {
            this.mailAccounts = data.data || [];
            console.log('✅ Аккаунты загружены:', this.mailAccounts);
        }
    } catch (error) {
        console.error('❌ Ошибка загрузки аккаунтов:', error);
    }
}

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================

function getFolderName(folderId) {
    const folder = this.mailFolders?.find(f => f.id === folderId);
    return folder ? folder.name : folderId;
}

function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}

function getAvatarGradient(name) {
    if (!name) return 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
    
    const gradients = [
        'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'linear-gradient(135deg, #30cfd0 0%, #330867 100%)',
        'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
        'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)'
    ];
    
    const index = name.length % gradients.length;
    return gradients[index];
}

function formatDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    if (days === 0) {
        return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    } else if (days === 1) {
        return 'Вчера';
    } else if (days < 7) {
        return date.toLocaleDateString('ru-RU', { weekday: 'long' });
    } else {
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    }
}

function formatEmailDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    if (days === 0) {
        return 'Сегодня, ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    } else if (days === 1) {
        return 'Вчера, ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    } else if (days < 7) {
        return date.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'short' });
    } else {
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
    }
}

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(1)) + ' ' + sizes[i];
}

function stripHtml(html) {
    if (!html) return '';
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

function sanitizeEmailHtml(html) {
    if (!html) return '';
    // Базовая санация - удаляем скрипты
    return html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
}

function openMailComposeModal() {
    this.mailComposeModalOpen = true;
    this.composeMailForm = {
        to: '',
        subject: '',
        body: '',
        is_html: true,
        use_smtp: true,
        attachments: []
    };
}

function openMailSettings() {
    this.currentView = 'settings';
    this.mailSettingsModalOpen = false;
}

// ============================================
// ЭКСПОРТ ФУНКЦИЙ
// ============================================

if (typeof window !== 'undefined') {
    window.initMail = initMail;
    window.loadMailFolders = loadMailFolders;
    window.loadMailFromFolder = loadMailFromFolder;
    window.syncImapFolder = syncImapFolder;
    window.openEmail = openEmail;
    window.closeEmailView = closeEmailView;
    window.sendMail = sendMail;
    window.saveMailDraft = saveMailDraft;
    window.loadMailAccounts = loadMailAccounts;
    window.deleteEmail = deleteEmail;
    window.replyToEmail = replyToEmail;
    window.forwardEmail = forwardEmail;
    window.quickReply = quickReply;
    window.toggleEmailStar = toggleEmailStar;
    window.toggleMailStarQuick = toggleMailStarQuick;
    window.toggleEmailSelection = toggleEmailSelection;
    window.moveSelectedEmails = moveSelectedEmails;
    window.toggleStarSelectedEmails = toggleStarSelectedEmails;
    window.mailConfirmPurgeFolder = mailConfirmPurgeFolder;
    window.purgeFolder = purgeFolder;
    window.openMailContextMenu = openMailContextMenu;
    window.executeContextMenuAction = executeContextMenuAction;
    window.applyMailFormat = applyMailFormat;
    window.insertMailLink = insertMailLink;
    window.insertInlineImage = insertInlineImage;
    window.handleMailAttachments = handleMailAttachments;
    window.removeMailAttachment = removeMailAttachment;
    window.getFolderName = getFolderName;
    window.getInitials = getInitials;
    window.getAvatarGradient = getAvatarGradient;
    window.formatDate = formatDate;
    window.formatEmailDate = formatEmailDate;
    window.formatFileSize = formatFileSize;
    window.stripHtml = stripHtml;
    window.sanitizeEmailHtml = sanitizeEmailHtml;
    window.openMailComposeModal = openMailComposeModal;
    window.openMailSettings = openMailSettings;
}
