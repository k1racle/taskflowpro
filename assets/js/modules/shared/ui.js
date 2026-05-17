window.TaskFlowSharedUi = (function () {
    function showToast(ctx, message, type = 'info') {
        const id = Date.now();
        ctx.toasts.push({ id, message, type, visible: true, title: null });

        setTimeout(() => {
            closeToast(ctx, id);
        }, 4000);
    }

    function closeToast(ctx, id) {
        const toast = (ctx.toasts || []).find((item) => item.id === id);
        if (!toast) return;

        toast.visible = false;
        setTimeout(() => {
            ctx.toasts = (ctx.toasts || []).filter((item) => item.id !== id);
        }, 300);
    }

    function showCallBanner(ctx, callData) {
        const id = Date.now();
        const callerName = callData?.caller_name || callData?.name || 'Входящий звонок';
        const callType = callData?.call_type || callData?.type || 'audio';
        const message = callType === 'video' ? 'Видеозвонок' : 'Аудиозвонок';

        ctx.toasts.push({
            id,
            type: 'call',
            title: callerName,
            message,
            visible: true
        });
    }

    function openMailConfirm(ctx, title, message, action) {
        ctx.mailConfirmTitle = title || 'Подтвердите действие';
        ctx.mailConfirmMessage = message || '';
        ctx.mailConfirmAction = typeof action === 'function' ? action : null;
        ctx.mailConfirmModalOpen = true;
    }

    async function runMailConfirm(ctx) {
        try {
            const action = ctx.mailConfirmAction;
            ctx.mailConfirmModalOpen = false;
            ctx.mailConfirmAction = null;
            if (action) await action();
        } catch (e) {
            console.error('Confirm action error:', e);
        }
    }

    function openConfirm(ctx, title, message, action, opts = {}) {
        ctx.pendingAction = {
            title: title || 'Подтвердите действие',
            message: message || '',
            action: typeof action === 'function' ? action : null,
            confirmText: opts.confirmText || 'Да',
            cancelText: opts.cancelText || 'Отмена',
            danger: !!opts.danger
        };
        ctx.confirmModalOpen = true;
    }

    async function runConfirm(ctx) {
        const pending = ctx.pendingAction;
        ctx.confirmModalOpen = false;
        ctx.pendingAction = null;
        try {
            if (pending?.action) await pending.action();
        } catch (e) {
            ctx.showToast(e.message || 'Ошибка', 'error');
        }
    }

    function openContextMenu(ctx, event, task = null, stageName = null) {
        event.preventDefault();
        event.stopPropagation();
        ctx.contextMenuX = event.clientX;
        ctx.contextMenuY = event.clientY;
        ctx.contextMenuTask = task;
        ctx.contextMenuStage = stageName;
        ctx.contextMenuStageName = stageName;
        ctx.contextMenuOpen = true;
    }

    function closeContextMenu(ctx) {
        ctx.contextMenuOpen = false;
        ctx.contextMenuTask = null;
        ctx.contextMenuStage = null;
        ctx.contextMenuStageName = null;
        ctx.mailContextMenuEmail = null;
    }

    function openMailContextMenu(ctx, event, email) {
        event.preventDefault();
        event.stopPropagation();
        ctx.contextMenuX = event.clientX;
        ctx.contextMenuY = event.clientY;
        ctx.mailContextMenuEmail = email;
        ctx.contextMenuOpen = true;
    }

    return {
        showToast,
        closeToast,
        showCallBanner,
        openMailConfirm,
        runMailConfirm,
        openConfirm,
        runConfirm,
        openContextMenu,
        closeContextMenu,
        openMailContextMenu
    };
})();
