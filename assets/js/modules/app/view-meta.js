window.TaskFlowViewMeta = (function () {
    const SUBTITLES = {
        'tasks': 'Управление задачами',
        'my-tasks': 'Задачи, назначенные вам',
        'projects': 'Проекты и прогресс',
        'departments': 'Команды и отделы',
        'files': 'Файлы и документы',
        'knowledge': 'Статьи и инструкции',
        'documents': 'Шаблоны и генерация клиентских документов',
        'widgets': 'Виджеты сайта, embed-коды и базовая конфигурация',
        'mail': 'Почтовый интерфейс',
        'conferences': 'Видеоконференции',
        'my-shift': 'Смена и чек-лист',
        'chat': 'Сообщения и звонки',
        'users': 'Пользователи системы',
        'roles': 'Роли и права доступа',
        'stages-manager': 'Этапы и колонки',
        'settings': 'Настройки системы'
    };

    return {
        getTitle(ctx) {
            const item = window.TaskFlowShellNavigationMeta?.getAllNavigationItems(ctx)
                ?.find((entry) => entry.id === ctx.currentView);
            return item ? item.label : 'TaskFlow Pro';
        },

        getSubtitle(ctx) {
            return SUBTITLES[ctx.currentView] || '';
        }
    };
})();
