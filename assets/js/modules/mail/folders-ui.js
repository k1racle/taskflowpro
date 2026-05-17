window.TaskFlowMailFoldersUi = (function () {
    function mapImapFoldersToUiFolders(folders) {
        const list = Array.isArray(folders) ? folders : [];
        const system = {
            INBOX: { id: 'inbox', name: 'Входящие', icon: 'inbox' }
        };

        const result = [];
        const used = new Set();

        if (list.includes('INBOX')) {
            result.push({ ...system.INBOX, count: 0, source: 'imap', imap_folder: 'INBOX' });
            used.add('INBOX');
        }

        for (const folder of list) {
            if (!folder || used.has(folder)) continue;

            const lower = String(folder).toLowerCase();
            let name = folder;

            if (lower === 'inbox') name = 'Входящие';
            else if (lower.includes('sent') || lower.includes('отправ')) name = 'Отправленные';
            else if (lower.includes('trash') || lower.includes('deleted') || lower.includes('корз')) name = 'Корзина';
            else if (lower.includes('spam') || lower.includes('junk') || lower.includes('спам')) name = 'Спам';
            else if (lower.includes('draft')) name = 'Черновики';

            result.push({
                id: `imap:${folder}`,
                name,
                icon: 'folder',
                count: 0,
                source: 'imap',
                imap_folder: folder
            });
            used.add(folder);
        }

        return result;
    }

    function getFolderName(mailFolders, folderId) {
        const folders = Array.isArray(mailFolders) ? mailFolders : [];
        const folder = folders.find((item) => item.id === folderId);
        return folder ? folder.name : folderId;
    }

    function buildMailAttachmentDownloadUrl(attachmentId, token) {
        return `/api/index.php/mail/attachments/${attachmentId}/download?token=${encodeURIComponent(token || '')}`;
    }

    function toggleEmailSelection(selectedEmails, emailId) {
        const nextSelectedEmails = Array.isArray(selectedEmails) ? [...selectedEmails] : [];
        const index = nextSelectedEmails.indexOf(emailId);

        if (index > -1) {
            nextSelectedEmails.splice(index, 1);
        } else {
            nextSelectedEmails.push(emailId);
        }

        return nextSelectedEmails;
    }

    function formatReplyBody(email, formattedSentAt) {
        if (!email) return '';
        const sender = email.sender_name || email.sender_email;
        const subject = email.subject || '';
        const body = email.body || '';
        const replyHeader = `\n\n--- Исходное письмо ---\nОт: ${sender}\nДата: ${formattedSentAt}\nТема: ${subject}\n\n`;
        return replyHeader + body;
    }

    function formatForwardBody(email, formattedSentAt) {
        if (!email) return '';
        const sender = email.sender_name || email.sender_email;
        const subject = email.subject || '';
        const body = email.body || '';
        const forwardHeader = `\n\n--- Пересылаемое письмо ---\nОт: ${sender}\nДата: ${formattedSentAt}\nТема: ${subject}\n\n`;
        return forwardHeader + body;
    }

    function closeEmailView(state) {
        return {
            ...state,
            emailViewModalOpen: false,
            viewingEmail: null
        };
    }

    return {
        mapImapFoldersToUiFolders,
        getFolderName,
        buildMailAttachmentDownloadUrl,
        toggleEmailSelection,
        formatReplyBody,
        formatForwardBody,
        closeEmailView
    };
})();
