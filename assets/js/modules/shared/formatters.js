window.TaskFlowSharedFormatters = (function () {
    const AVATAR_GRADIENTS = [
        'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'linear-gradient(135deg, #30cfd0 0%, #330867 100%)',
        'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
        'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)'
    ];

    function formatShortDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('ru-RU', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatTimer(seconds) {
        const total = Number(seconds) || 0;
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = Math.floor(total % 60);
        const mm = String(m).padStart(2, '0');
        const ss = String(s).padStart(2, '0');
        if (h > 0) return `${h}:${mm}:${ss}`;
        return `${m}:${ss}`;
    }

    function isOverdue(dateStr) {
        if (!dateStr) return false;
        return new Date(dateStr) < new Date();
    }

    function getDeadlineClass(dateStr) {
        if (!dateStr) return 'crm-text-secondary';
        const deadline = new Date(dateStr);
        const today = new Date();
        const diffTime = deadline - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        if (diffDays < 0) return 'crm-text-error font-semibold';
        if (diffDays === 0) return 'crm-text-error font-semibold';
        if (diffDays < 3) return 'crm-text-warning font-semibold';
        if (diffDays < 7) return 'crm-text-warning';
        return 'crm-text-secondary';
    }

    function getInitials(name) {
        const s = String(name ?? '').trim();
        if (!s || s === 'null' || s === 'undefined') return '?';
        const parts = s.split(/\s+/).filter(Boolean);
        const a = parts[0]?.[0] || '';
        const b = parts[1]?.[0] || '';
        const out = (a + b) || a || '?';
        return out.toUpperCase();
    }

    function formatEmailDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        if (days === 0) return 'Сегодня, ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        if (days === 1) return 'Вчера, ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        if (days < 7) return date.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'short' });
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function formatRelativeDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        if (days === 0) return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        if (days === 1) return 'Вчера';
        if (days < 7) return date.toLocaleDateString('ru-RU', { weekday: 'long' });
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    }

    function formatFileSize(bytes) {
        const b = Number(bytes || 0);
        if (!b) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), units.length - 1);
        const v = b / Math.pow(1024, i);
        return (v >= 10 || i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
    }

    function stripHtml(html) {
        if (!html) return '';
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    function sanitizeEmailHtml(html) {
        const s = String(html || '');
        return s
            .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
            .replace(/on\w+\s*=\s*"[^"]*"/gi, '')
            .replace(/on\w+\s*=\s*'[^']*'/gi, '')
            .replace(/javascript:/gi, '');
    }

    function getAvatarGradient(name) {
        if (!name) return AVATAR_GRADIENTS[0];
        const index = String(name || '').length % AVATAR_GRADIENTS.length;
        return AVATAR_GRADIENTS[index];
    }

    function translatePriority(priority) {
        const priorities = {
            low: 'Низкий',
            medium: 'Средний',
            high: 'Высокий',
            urgent: 'Срочный'
        };
        return priorities[priority] || priority;
    }

    function escapeHtml(text) {
        const str = String(text ?? '');
        return str
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    return {
        formatShortDate,
        formatDateTime,
        formatTimer,
        isOverdue,
        getDeadlineClass,
        getInitials,
        formatEmailDate,
        formatRelativeDate,
        formatFileSize,
        stripHtml,
        sanitizeEmailHtml,
        getAvatarGradient,
        translatePriority,
        escapeHtml
    };
})();
