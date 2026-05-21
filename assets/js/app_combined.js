/**
 * app.js - Основная логика TaskFlow Pro
 * Alpine.js компонент app()
 */

window.app = function() {
    return {
        // ============================================
        // СОСТОЯНИЕ ПРИЛОЖЕНИЯ
        // ============================================

        isLoading: true,
        preAuthLoading: true,
        loginTransitionOpen: false,
        loginTransitionName: '',
        loginTransitionWish: '',
        isAuthenticated: false,
        permissionsReady: false,
        loading: false,
        adminMenuOpen: true,
        sidebarOpen: false,
        mobileMoreOpen: false,
        mobileProfileOpen: false,
        currentTime: { hours: '00:00' },
        currentDate: { full: '', dayName: '' },
        isDark: false,
        isDesktop: false,
        currentView: 'tasks',

        // License status (domain-based)
        license: {
            checked: false,
            enabled: false,
            valid: true,
            licensed_domain: null,
            request_domain: null,
            error: null,
        },

        // ============================================
        // POLLING / AUTO-REFRESH MANAGER
        // ============================================
        _pollers: {},
        _pollerSeq: 0,
        _pollingEnabled: true,
        _pollingPausedUntil: 0,

        initVisibilityListener() {
            return window.TaskFlowInitLifecycle?.initVisibilityListener?.(this);
        },

        _shouldPoll() {
            return window.TaskFlowInitLifecycle?.shouldPoll?.(this);
        },

        _pausePolling(ms = 15000) {
            return window.TaskFlowInitLifecycle?.pausePolling?.(this, ms);
        },

        startPoller(key, fn, intervalMs, opts = {}) {
            return window.TaskFlowInitLifecycle?.startPoller?.(this, key, fn, intervalMs, opts);
        },

        stopPoller(key) {
            return window.TaskFlowInitLifecycle?.stopPoller?.(this, key);
        },

        // Авторизация
        loginForm: { login: '', password: '' },
        loginError: '',
        currentUser: null,
        token: null,

        // Данные
        users: [],
        departments: [],
        projects: [],
        tasks: [],
        stages: [],
        settings: {},
        telegram: { enabled: false, bot_token: '', chat_id: '' },

        // Формы
        taskForm: {
            title: '',
            description: '',
            project_id: '',
            status: 'Новая',
            priority: 'medium',
            deadline: '',
            assigned_to: '',
            checklist: [],
            department_id: '',
            responsible_ids: []
        },
        projectForm: { name: '', description: '', department_ids: [], deadline: '', priority: 'medium' },
        hasUnsavedChanges: false,
        projectModalTab: 'project',
        projectFilesTab: 'all',
        projectTasks: [],
        projectFiles: [],
        projectComments: [],
        projectHistory: [],
        newProjectComment: '',
        projectReplyId: null,
        projectReplyText: '',
        departmentForm: { name: '', description: '', icon: 'building' },
        departmentModalTab: 'department',
        departmentEmployees: [],
        departmentProjects: [],
        departmentTasks: [],
        departmentContextMenuOpen: false,
        departmentContextMenuX: 0,
        departmentContextMenuY: 0,
        selectedDepartment: null,
        userForm: { login: '', password: '', full_name: '', role: 'employee', department_id: '' },
        settingsForm: {
            full_name: '',
            phone: '',
            department_id: '',
            bio: '',
            avatar: '',
            birthday: '',
            weather_city: 'Москва',
            company_name: '',
            app_name: '',
            logo: '',
            referral_woocommerce_base_url: '',
            referral_shared_secret: '',
            woocommerce_api_consumer_key: '',
            woocommerce_api_consumer_secret: '',
            mango_office_enabled: false,
            mango_office_remote_id: '',
            mango_office_security_token: ''
        },

        // WebRTC / ICE servers (STUN/TURN)
        webrtcIceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
        telegramForm: { bot_token: '', chat_id: '', enabled: false },
        profileForm: { full_name: '', phone: '', bio: '', department_id: '', birthday: '', weather_city: 'Москва' },
        knowledgeForm: { title: '', content: '', department_id: '' },
        knowledgeModalTab: 'article',
        knowledgeMediaForm: {
            video_title: '',
            video_url: '',
            slides_title: '',
            slides_url: '',
            faq_question: '',
            faq_answer: ''
        },
        // Настройки почты (SMTP/IMAP)
        mailForm: {
            email: '',
            host: '',
            port: '587',
            smtp_username: '',
            password: '',
            encryption: 'tls',
            imap_host: '',
            imap_port: '993',
            imap_encryption: 'ssl',
            display_name: 'TaskFlow Pro',
            signature: ''
        },
        // Форма для написания письма
        composeMailForm: { to: '', subject: '', body: '', is_html: true, use_smtp: false, attachments: [] },
        mailView: 'inbox',
        mailInbox: [],
        mailSent: [],
        mailDrafts: [],

        // Роли и права
        roles: [],
        permissions: [],
        usersLoading: false,
        usersSaving: false,
        usersError: '',
        rolesLoading: false,
        rolesError: '',
        roleModalOpen: false,
        rolePermissionsModalOpen: false,
        editingRole: null,
        roleForm: { name: '', description: '', icon: 'shield' },
        rolePermissions: {
            tasks: { view: false, create: false, edit: false, delete: false },
            projects: { view: false, create: false, edit: false, delete: false },
            departments: { view: false, create: false, edit: false, delete: false },
            files: { view: false, upload: false, edit: false, delete: false },
            knowledge: { view: false, create: false, edit: false, delete: false },
            chat: { view: false, send: false, edit: false, delete: false, forward: false, create_group: false },
            mail: { view: false, send: false, edit: false, delete: false },
            crm: { view: false, create: false, edit: false, delete: false, export: false, stages_manage: false },
            leader: { view: false, shifts_manage: false, export: false }
        },
        roleIcons: ['shield', 'users', 'user-check', 'lock', 'key', 'briefcase', 'folder', 'document', 'clipboard', 'chart-bar', 'cog', 'star'],
        userPermissions: [],

        can(permissionCode) {
            if (!this.currentUser) return false;
            if (this.currentUser.role === 'root') return true;
            return (this.userPermissions || []).some(p => p && p.code === permissionCode);
        },

        async loadCurrentUserPermissions() {
            return window.TaskFlowAppResidualCore?.loadCurrentUserPermissions?.(this);
        },

        get canAdmin() {
            return this.can('admin.full');
        },

        get canCrm() {
            return this.can('crm.view');
        },

        ensureCrmLoaded() {
            return window.TaskFlowAppResidualCore?.ensureCrmLoaded?.(this);
        },

        ensureLeaderLoaded() {
            return window.TaskFlowAppResidualCore?.ensureLeaderLoaded?.(this);
        },

        // Глобальный поиск
        globalSearch: '',
        lastSearchResults: null,
        topbarSearchOpen: false,

        // Поиск в админских списках
        usersSearch: '',
        rolesSearch: '',

        // Для модального окна задачи
        taskTab: 'task',
        taskComments: [],
        taskFiles: [],
        taskHistory: [],
        taskSubstages: [],  // Справочник подэтапов
        newSubstageName: '',
        newCommentText: '',
        commentReplyId: null,
        commentReplyText: '',

        // Предпросмотр изображений
        imagePreviewOpen: false,
        imagePreviewSrc: '',
        imagePreviewName: '',
        filePreviewOpen: false,
        filePreviewSrc: '',
        filePreviewName: '',
        filePreviewType: '', // 'image', 'video', 'audio', 'pdf', 'document'

        // Chat message context menu
        chatMsgMenuOpen: false,
        chatMsgMenuX: 0,
        chatMsgMenuY: 0,
        chatMsgMenuMsg: null,

        // Chat room context menu
        chatRoomMenuOpen: false,
        chatRoomMenuX: 0,
        chatRoomMenuY: 0,
        chatRoomMenuRoom: null,

        // Chat runtime marker for live diagnostics
        chatRuntimeVersion: 'chat-live-2026-04-25-v1',

        // Swipe-to-reply
        _swipeMsgId: null,
        _swipeStartX: 0,
        _swipeStartY: 0,
        _swipeActive: false,

        // Drag-and-drop для задач
        draggingTask: null,

        // Контекстное меню
        contextMenuOpen: false,
        contextMenuX: 0,
        contextMenuY: 0,
        contextMenuTask: null,
        contextMenuStage: null,
        contextMenuStageName: null,

        // CRM: context menu (ПКМ по сделке)
        crmContextMenuOpen: false,
        crmContextMenuX: 0,
        crmContextMenuY: 0,
        crmContextMenuDeal: null,
        crmContextMenuStage: null,

        // Mail context menu (ПКМ по письму)
        mailContextMenuEmail: null,

        // Управление этапами
        stagesModalOpen: false,
        newStageName: '',
        newStageColor: '#3B82F6',
        substageModalOpen: false,
        editingSubstage: null,
        substageForm: { name: '', color: '#6B7280', order: 0 },

        // Редактируемые объекты
        editingTask: null,
        editingProject: null,
        editingDepartment: null,
        editingUser: null,
        editingKnowledge: null,

        // Модальные окна
        taskModalOpen: false,
        projectModalOpen: false,
        departmentModalOpen: false,
        userModalOpen: false,
        settingsModalOpen: false,
        chatModalOpen: false,
        knowledgeModalOpen: false,
        profileModalOpen: false,
        mailModalOpen: false,
        rolesModalOpen: false,
        permissionsModalOpen: false,

        // Настройки
        settingsTab: 'profile',
        emailSettings: {
            email: '',
            smtp_host: '',
            smtp_port: 587,
            smtp_username: '',
            smtp_password: '',
            smtp_encryption: 'tls',
            signature: ''
        },
        notificationSettings: {
            email: {
                new_tasks: true,
                status_changed: true,
                comments: true,
                mentions: true,
                weekly_digest: false
            },
            telegram: {
                new_tasks: true,
                status_changed: true,
                comments: true,
                mentions: true
            }
        },

        // Почта
        mailFolders: [],
        mailList: [],
        currentMailFolder: 'inbox',
        mailSearch: '',
        mailStarFilter: false,
        mailUnreadOnly: false,
        mailLoading: false,
        mailSyncing: false,
        mailSending: false,
        lastSyncTime: null,
        selectedEmails: [],
        mailComposeModalOpen: false,
        mailFolderCreateModalOpen: false,
        mailFolderCreateName: '',
        mailConfirmModalOpen: false,
        mailConfirmTitle: '',
        mailConfirmMessage: '',
        mailConfirmAction: null,
        mailSearchOpen: false,
        mailAccounts: [],
        viewingEmail: null,
        // Контекстное меню
        contextMenuOpen: false,
        mailContextMenuOpen: false,
        contextMenuEmail: null,
        contextMenuX: 0,
        contextMenuY: 0,
        contextMenuItems: [],
        // Очистка папки
        purgeFolderModalOpen: false,
        purgeFolderTarget: null,
        purgeFolderName: '',
        // Generic confirm modal (app-wide)
        confirmModalOpen: false,
        pendingAction: null,

        // ============================================
        // КОНФЕРЕНЦИИ
        // ============================================

        conferences: [],
        lastCreatedConference: null,
        revealedPins: {},

        createConferenceModalOpen: false,
        createConferenceSubmitting: false,
        createConferenceForm: { title: '', description: '' },

        inviteModalOpen: false,
        inviteSearch: '',
        inviteSelected: [],
        inviteUsers: [],
        currentConference: null,

        conferenceModalOpen: false,
        conferenceLocalStream: null,
        conferenceAudioEnabled: true,
        conferenceVideoEnabled: true,
        conferenceParticipants: [],
        conferenceJoinRequests: [],
        conferenceSidebarTab: 'participants',
        _conferencePollInterval: null,
        emailViewModalOpen: false,

        // Таймер задачи
        taskTimerRunning: false,
        taskTimerSeconds: 0,
        taskTimerInterval: null,
        taskTimerTaskId: null,
        activeTimers: {},

        // Toast уведомления
        toasts: [],

        // Уведомления
        notificationsOpen: false,
        browserNotificationsSupported: false,
        browserNotificationPermission: 'default',
        browserNotificationPromptVisible: false,
        lastNotifiedNotificationId: null,
        lastNotificationSyncAt: 0,
        _notificationAudioCtx: null,
        _notificationSoundUnlocked: false,
        _notificationToastShownIds: {},

        // Профиль
        profile: null,

        // Чат
        chatMessage: '',
        chatSearch: '',
        chatUserSearch: '',
        groupChatUserSearch: '',
        deletedChatRoomIds: [],
        chatRoomsLoaded: false,
        chatRoomsLoading: false,
        chatRoomsError: '',
        chatRoomMessagesLoading: false,
        chatRoomMessagesError: '',
        chatHistoryLoading: false,
        chatHasMoreHistory: true,
        chatMessagesOffset: 0,
        chatSending: false,
        showAttachmentMenu: false,
        showEmojiPicker: false,
        composerMediaTab: 'emoji',
        chatOptionsOpen: false,
        
        // Расширенные функции чата
        replyToMessage: null,           // Сообщение для ответа
        editingMessage: null,           // Сообщение для редактирования
        forwardingMessage: null,        // Сообщение для пересылки
        recordingVoice: false,          // Идёт запись голоса
        voiceRecordTime: 0,             // Длительность записи в секундах
        voiceRecordInterval: null,      // Таймер записи
        voiceMediaRecorder: null,       // MediaRecorder для записи
        voiceChunks: [],                // Чанки аудио
        typingTimeout: null,            // Таймер индикатора набора
        chatMembers: [],                // Участники текущего чата
        showForwardModal: false,        // Модальное окно пересылки
        showGroupModal: false,          // Модальное окно создания группы
        showCreateChatModal: false,     // Модалка создания личного чата
        groupForm: { name: '', members: [] },  // Форма группы
        messageSearchQuery: '',         // Поиск по сообщениям
        messageSearchResults: [],       // Результаты поиска
        showSearchResults: false,       // Показать результаты поиска
        selectedMessages: [],           // Выбранные сообщения (для bulk действий)
        showDeleteConfirm: false,       // Подтверждение удаления
        messageToDelete: null,          // Сообщение на удаление
        showTaskSelector: false,        // Выбор задачи для прикрепления
        showProjectSelector: false,     // Выбор проекта для прикрепления
        showFilePicker: false,          // Выбор файла из системы
        selectedTaskForAttach: null,    // Выбранная задача
        selectedProjectForAttach: null, // Выбранный проект
        playingVoiceId: null,           // ID воспроизводимого голосового сообщения
        voiceAudioPlayer: null,         // Audio элемент для воспроизведения
        showStickers: false,            // Legacy flag (оставлен для совместимости)
        stickers: [                     // Коллекция стикеров (SVG emoji - работают без внешних URL)
            // Приветствия
            { id: 1, name: 'Привет', emoji: '👋', type: 'emoji' },
            { id: 2, name: 'Здравствуй', emoji: '🙋', type: 'emoji' },
            { id: 3, name: 'Пока', emoji: '👋', type: 'emoji' },
            { id: 31, name: 'Приветствую', emoji: '🤝', type: 'emoji' },
            { id: 32, name: 'Хай', emoji: '🖐️', type: 'emoji' },
            // Эмоции
            { id: 4, name: 'Смех', emoji: '😂', type: 'emoji' },
            { id: 5, name: 'Любовь', emoji: '😍', type: 'emoji' },
            { id: 6, name: 'Грусть', emoji: '😢', type: 'emoji' },
            { id: 7, name: 'Злость', emoji: '😠', type: 'emoji' },
            { id: 8, name: 'Удивление', emoji: '😮', type: 'emoji' },
            { id: 33, name: 'Восторг', emoji: '🤩', type: 'emoji' },
            { id: 34, name: 'Задумчивость', emoji: '🤔', type: 'emoji' },
            { id: 35, name: 'Смущение', emoji: '😅', type: 'emoji' },
            { id: 36, name: 'Радость', emoji: '😊', type: 'emoji' },
            // Реакции
            { id: 9, name: 'Аплодисменты', emoji: '👏', type: 'emoji' },
            { id: 10, name: 'Класс', emoji: '👍', type: 'emoji' },
            { id: 11, name: 'ОК', emoji: '👌', type: 'emoji' },
            { id: 12, name: 'Палец вверх', emoji: '👍', type: 'emoji' },
            { id: 13, name: 'Палец вниз', emoji: '👎', type: 'emoji' },
            { id: 14, name: 'Огонь', emoji: '🔥', type: 'emoji' },
            { id: 37, name: 'Сердце', emoji: '❤️', type: 'emoji' },
            { id: 38, name: 'Слеза', emoji: '💯', type: 'emoji' },
            { id: 39, name: 'Взрыв', emoji: '💥', type: 'emoji' },
            // Работа
            { id: 15, name: 'Работаем', emoji: '💼', type: 'emoji' },
            { id: 16, name: 'Дедлайн', emoji: '⏰', type: 'emoji' },
            { id: 17, name: 'Успех', emoji: '✅', type: 'emoji' },
            { id: 18, name: 'Идея', emoji: '💡', type: 'emoji' },
            { id: 19, name: 'Вопрос', emoji: '❓', type: 'emoji' },
            { id: 20, name: 'Ответ', emoji: '💬', type: 'emoji' },
            { id: 40, name: 'Компьютер', emoji: '💻', type: 'emoji' },
            { id: 41, name: 'Документ', emoji: '📄', type: 'emoji' },
            { id: 42, name: 'График', emoji: '📊', type: 'emoji' },
            { id: 43, name: 'Цель', emoji: '🎯', type: 'emoji' },
            // Время
            { id: 21, name: 'Кофе', emoji: '☕', type: 'emoji' },
            { id: 22, name: 'Обед', emoji: '🍽️', type: 'emoji' },
            { id: 23, name: 'Пауза', emoji: '⏸️', type: 'emoji' },
            { id: 24, name: 'Сон', emoji: '😴', type: 'emoji' },
            { id: 44, name: 'Часы', emoji: '⏰', type: 'emoji' },
            { id: 45, name: 'Календарь', emoji: '📅', type: 'emoji' },
            // Праздники
            { id: 25, name: 'День рождения', emoji: '🎂', type: 'emoji' },
            { id: 26, name: 'Праздник', emoji: '🎉', type: 'emoji' },
            { id: 27, name: 'С Новым годом', emoji: '🎄', type: 'emoji' },
            { id: 46, name: 'Подарок', emoji: '🎁', type: 'emoji' },
            { id: 47, name: 'Салют', emoji: '🎆', type: 'emoji' },
            // Животные
            { id: 28, name: 'Кот', emoji: '🐱', type: 'emoji' },
            { id: 29, name: 'Собака', emoji: '🐶', type: 'emoji' },
            { id: 30, name: 'Медведь', emoji: '🐻', type: 'emoji' },
            { id: 48, name: 'Лиса', emoji: '🦊', type: 'emoji' },
            { id: 49, name: 'Заяц', emoji: '🐰', type: 'emoji' },
            { id: 50, name: 'Панда', emoji: '🐼', type: 'emoji' },
        ],

        stickerPacks: [
            { id: 'greetings', name: 'Приветствия', icon: '👋', items: [1, 2, 3, 31, 32] },
            { id: 'emotions', name: 'Эмоции', icon: '😊', items: [4, 5, 6, 7, 8, 33, 34, 35, 36] },
            { id: 'reactions', name: 'Реакции', icon: '👍', items: [9, 10, 11, 12, 13, 14, 37, 38, 39] },
            { id: 'work', name: 'Работа', icon: '💼', items: [15, 16, 17, 18, 19, 20, 40, 41, 42, 43] },
            { id: 'time', name: 'Время', icon: '☕', items: [21, 22, 23, 24, 44, 45] },
            { id: 'holidays', name: 'Праздники', icon: '🎉', items: [25, 26, 27, 46, 47] },
            { id: 'animals', name: 'Животные', icon: '🐱', items: [28, 29, 30, 48, 49, 50] },
        ],
        activeStickerPackId: 'greetings',
        
        // Звонки
        showCallModal: false,           // Модальное окно звонка
        callType: 'audio',              // 'audio' или 'video'
        callStatus: 'ended',            // 'calling', 'connected', 'ended'
        callTimer: 0,                   // Таймер разговора
        callTimerInterval: null,        // Интервал таймера
        callLocalStream: null,          // Локальный MediaStream
        callRemoteStream: null,         // Удаленный MediaStream
        callPeerConnection: null,       // RTCPeerConnection
        callPendingIce: [],
        callDebug: { iceConnectionState: '', connectionState: '', signalingState: '', iceGatheringState: '', lastEvent: '', lastError: '' },
        _webrtcPollRunning: false,
        isMuted: false,                 // Микрофон выключен
        isCameraOff: false,             // Камера выключена
        
        // Long Polling для чата
        chatPollingInterval: null,      // Интервал опроса чата
        lastMessageId: 0,               // ID последнего сообщения
        lastRoomId: null,               // ID последнего чата
        isChatVisible: false,           // Видима ли вкладка с чатом

        _presenceInterval: null,
        chatPresenceByUserId: {},
        activeChatRoomPresence: { otherOnline: false, otherTyping: false },
        
        // Входящие звонки
        incomingCallModal: false,       // Модальное окно входящего звонка
        incomingCaller: null,           // Данные звонящего {name, type, roomId}
        incomingCallInterval: null,     // Интервал проверки входящих
        
        // Топбар и боковые панели
        topbarVisible: true,            // Видимость топбара
        lastScrollY: 0,                 // Последняя позиция скролла
        notificationsPanelOpen: false,  // Левая панель уведомлений
        widgetsPanelOpen: false,        // Правая панель виджетова
        widgetStoreOpen: false,         // Магазин виджетов
        enabledWidgets: ['time'],       // Включенные виджеты
        widgetOrder: ['time'],          // Порядок виджетов
        externalResources: [],          // Внешние ресурсы (настраиваются пользователем)
        hotTasks: [],                   // Горящие задачи для быстрого просмотра
        clickerScore: 0,                // Счёт в игре кликер
        clickerMultiplier: 1,           // Множитель кликера
        weather: { temp: 0, desc: '', icon: '' },  // Погода
        weatherApiKey: '427fb0d97beab5341712d7cdca451f68',
        weatherCity: 'Москва',  // Город для погоды

        // Floating chat overlay
        chatOverlayOpen: false,

        // Site widgets
        siteWidgetsConfig: {
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
        },
        siteWidgetProfiles: [],
        siteWidgetsCanManage: false,
        siteWidgetsSelectedProfileId: null,
        siteWidgetsActiveProfileId: null,
        siteWidgetsNewProfileName: '',
        siteWidgetsNewProfileSlug: '',
        siteWidgetsCopyState: {
            form: false,
            chat: false,
            script: false,
            scriptHead: false,
            scriptFooter: false
        },

        // Навигация
        navItems: window.TaskFlowShellNavigationMeta?.createNavItems?.() || [],

        defaultNavItems: null,
        menuOrderDraft: [],
        menuDragIndex: null,

        // ============================================
        // CRM STATE
        // ============================================

        crmDashboard: null,
        crmClients: [],
        crmClientsQuery: '',
        crmClientsStatus: '',
        crmClientsTag: '',
        crmClientId: null,
        crmClient: null,
        crmClientTab: 'info',
        crmClientTasks: [],
        crmClientActivity: [],
        crmClientSales: { summary: { total_sales: 0, months_count: 0, last_sale_month: null, first_sale_month: null, average_monthly_sales: 0 }, history: [] },
        crmClientReferrals: { code: null, link: null, link_ready: false, stats: { orders_count: 0, orders_total: 0, visits_count: 0, last_order_at: null }, orders: [], recent_orders: [] },

        crmPipelines: [],
        crmActivePipelineId: 1,
        crmStages: [],
        crmDeals: [],
        crmDealsQuery: '',
        crmFunnelsClientFilter: '',
        crmColumnDisplayMode: 'both', // 'both', 'count', 'sum'
        crmDraggingDeal: null,

        crmSalesAnalytics: { summary: { total_amount: 0, clients_count: 0, records_count: 0, last_sale_month: null }, period: { from_month: '', to_month: '' }, monthly_totals: [], top_clients: [], clients: [], available_months: [] },
        crmSalesFilters: { client_id: '', q: '', from_month: '', to_month: '' },
        crmSalesChart: null,
        crmSalesChartMetric: 'amount',
        crmStoreAnalytics: { summary: { total_amount: 0, orders_count: 0, average_order_amount: 0, total_items_qty: 0 }, comparison: { amount_delta: 0, amount_delta_percent: null, orders_delta: 0, orders_delta_percent: null }, period: { from_month: '', to_month: '' }, monthly_totals: [], orders: [], available_months: [], available_statuses: [] },
        crmStoreFilters: { status: '', q: '', from_month: '', to_month: '' },
        crmStoreChart: null,
        crmStoreChartMetric: 'amount',
        crmStoreSelectedOrderId: null,
        crmStoreImportState: { loading: false, lastResult: null },
        crmStoreSyncStatus: { state: 'idle', label: 'Синхронизация WooCommerce еще не запускалась', latest: null, recent: [], configured: false, last_error: null, last_success_at: null, last_failure_at: null },
        crmAdminToolsForm: { file: 'old/База B2B-1 (2).xlsx', sheet: 'База клиентов', clients_sheet: 'Работа с АКБ', client_id: '', primary_id: '', group_index: '', all: false },
        crmAdminToolsState: { loading: false, lastResult: null },
        diagnosticsBaseline: { loading: false, loaded: false, error: '', data: null },

        // CRM modals
        crmClientDetailOpen: false,
        crmClientModalOpen: false,
        crmClientModalTab: 'main',
        crmClientForm: { id: null, name: '', type: 'person', email: '', phone: '', site: '', address: '', legal_name_full: '', legal_name_short: '', inn: '', kpp: '', ogrn: '', legal_address: '', postal_address: '', signer_name: '', signer_position: '', signer_authority: '', bank_name: '', bik: '', checking_account: '', correspondent_account: '', tagsText: '', status: 'active', notes: '', custom_fields: {} },

        crmContactModalOpen: false,
        crmContactForm: { name: '', position: '', email: '', phone: '', is_primary: false },

        crmDealModalOpen: false,
        crmDealForm: { id: null, client_id: '', pipeline_id: 1, stage_id: '', title: '', amount: 0, currency: 'RUB', probability: 0, expected_close_date: '', owner_id: '', description: '' },
        crmDealSubstages: [],
        newDealSubstageName: '',

        crmPipelineModalOpen: false,
        crmPipelineForm: { name: '' },

        crmStageModalOpen: false,
        crmStageForm: { id: null, name: '', color: '#3B82F6', order: 0, is_won: false, is_lost: false },
        crmDealSubstageModalOpen: false,
        editingCrmDealSubstage: null,
        crmDealSubstageForm: { name: '', color: '#6B7280', order: 0 },

        crmDealTab: 'info',

        // Documents
        documentTemplates: [],
        documentClients: [],
        documentFieldGroups: [],
        documentsSelectedTemplateId: null,
        documentsSelectedTemplate: null,
        documentsSelectedClientId: '',
        documentsSelectedClientName: '',
        documentsBatchTemplateIds: [],
        documentGeneratedItems: [],
        documentGenerationHistory: [],
        documentsBatchArchive: null,
        documentsBatchWarning: '',
        documentsIsGenerating: false,
        documentsIsLoading: false,
        documentsIsHistoryLoading: false,
        documentsLoadError: '',
        documentsHistoryError: '',
        documentsPreviewContext: null,
        documentsPreviewJson: 'Выберите клиента, чтобы увидеть доступные данные.',
        documentsDocxSupport: null,
        documentsSourceContext: null,
        documentsLastGenerationMeta: null,
        documentTemplateForm: { id: null, name: '', slug: '', description: '', category: 'CRM', content: '', output_format: 'html', source_origin: 'inline', source_path: '' },

        // Leader
        leaderOverview: null,
        leaderTab: 'dashboard',

        // Analytics
        analyticsData: null,
        analyticsTasksData: null,
        analyticsShiftsData: null,
        analyticsCRMData: null,
        analyticsPeriod: 'month',
        analyticsCompare: 'previous',
        analyticsCustomFrom: '',
        analyticsCustomTo: '',
        analyticsEmployees: [],
        analyticsTasksByProject: [],
        analyticsCharts: {},

        // Новые данные для функций
        knowledgeArticles: [],
        chatRooms: [],
        chatMessages: [],
        activeChatRoom: null,
        chatUsers: [],
        notifications: [],
        notificationCount: 0,
        chatUnreadCount: 0,
        files: [],
        filteredFiles: [],
        folders: [],
        fileTree: [],
        fileView: 'all',
        filesViewMode: 'grid',
        fileSearch: '',
        currentFolder: null,
        breadcrumb: [],
        selectedFileIds: [],
        selectedFolderId: null,
        filesLoading: false,
        filesError: '',
        fileActionBusy: false,
        fileActionLabel: '',
        filesContextMenuOpen: false,
        filesContextMenuX: 0,
        filesContextMenuY: 0,
        contextFile: null,
        contextFolder: null,
        newFolderName: '',
        createFolderModalOpen: false,
        moveToFolderModalOpen: false,

        // Наблюдатель для фильтрации файлов
        get filteredFilesList() {
            if (this.fileView === 'images') {
                return this.files.filter(f => {
                    const ext = f.original_name.split('.').pop().toLowerCase();
                    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext);
                });
            } else if (this.fileView === 'documents') {
                return this.files.filter(f => {
                    const ext = f.original_name.split('.').pop().toLowerCase();
                    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(ext);
                });
            } else if (this.fileView === 'archives') {
                return this.files.filter(f => {
                    const ext = f.original_name.split('.').pop().toLowerCase();
                    return ['zip', 'rar', '7z', 'tar', 'gz'].includes(ext);
                });
            }
            return this.files;
        },

        get filteredFilesUi() {
            const q = (this.fileSearch || '').trim().toLowerCase();
            const base = this.filteredFilesList || [];
            if (!q) return base;
            return base.filter(f => String(f.original_name || f.name || '').toLowerCase().includes(q));
        },

        getFileExt(name) {
            const n = String(name || '');
            const parts = n.split('.');
            return parts.length > 1 ? parts.pop().toLowerCase() : '';
        },

        isPdf(name) { return this.getFileExt(name) === 'pdf'; },
        isDoc(name) { return ['doc', 'docx', 'rtf', 'txt'].includes(this.getFileExt(name)); },
        isXls(name) { return ['xls', 'xlsx', 'csv'].includes(this.getFileExt(name)); },
        isPpt(name) { return ['ppt', 'pptx', 'key'].includes(this.getFileExt(name)); },
        isZip(name) { return ['zip', 'rar', '7z', 'tar', 'gz'].includes(this.getFileExt(name)); },
        isAudio(name) { return ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(this.getFileExt(name)); },
        isVideo(name) { return ['mp4', 'webm', 'mov', 'mkv', 'avi'].includes(this.getFileExt(name)); },

        // Фильтры задач
        taskFilters: window.TaskFlowTasks?.getTaskFilterDefaults?.() || { project_id: '', status: '', department_id: '' },
        myTaskFilters: window.TaskFlowTasks?.getTaskFilterDefaults?.() || { project_id: '', status: '', department_id: '' },
        projectFilters: { department_id: '' },

        // Фильтры задач (computed)
        get filteredTasks() {
            return window.TaskFlowTasks?.getFilteredTasks?.(this) || [];
        },

        get filteredMyTasks() {
            return window.TaskFlowTasks?.getFilteredMyTasks?.(this) || [];
        },

        get mobileHomeSnapshot() {
            const tasks = Array.isArray(this.filteredTasks) ? this.filteredTasks : [];
            const myTasks = Array.isArray(this.filteredMyTasks) ? this.filteredMyTasks : [];
            const urgentTasks = tasks.filter(task => ['high', 'urgent'].includes(task.priority)).length;
            const overdueTasks = tasks.filter(task => task.deadline && this.isOverdue(task.deadline)).length;
            const upcomingTasks = myTasks
                .filter(task => task.deadline)
                .slice()
                .sort((a, b) => new Date(a.deadline) - new Date(b.deadline))
                .slice(0, 3);

            return {
                tasksTotal: tasks.length,
                myTasksTotal: myTasks.length,
                urgentTasks,
                overdueTasks,
                upcomingTasks,
                notifications: Number(this.notificationCount || 0),
                chatUnread: Number(this.chatUnreadCount || 0),
                crmClients: Number(this.crmDashboard?.clients || 0),
                crmDeals: Number(this.crmDashboard?.active_deals || 0),
                crmPipelineSum: this.crmDashboard?.pipeline_sum || 0,
                leaderBurning: Number(this.leaderOverview?.tasks_burning?.length || 0),
                leaderOnShift: Number(this.leaderOverview?.on_shift?.length || 0)
            };
        },

        // Фильтры проектов (computed)
        get filteredProjects() {
            return window.TaskFlowProjects?.getFilteredProjects?.(this) || [];
        },

        // Проверка на администратора
        get isAdmin() {
            return this.can('admin.full');
        },

        get canManageSiteWidgets() {
            return this.can('admin.full');
        },

        // Представления задач
        tasksView: 'kanban',
        myTasksView: 'kanban',
        projectsView: 'cards',
        projectContextMenuOpen: false,
        projectContextMenuX: 0,
        projectContextMenuY: 0,
        selectedProject: null,

        // Иконки отделов
        departmentIcons: [
            'building', 'users', 'code', 'palette', 'megaphone', 'dollar-sign',
            'briefcase', 'chart-bar', 'shopping-cart', 'heart', 'globe', 'cpu',
            'database', 'cloud', 'folder', 'file-text', 'calendar', 'settings'
        ],

        adminNavItems: window.TaskFlowShellNavigationMeta?.createAdminNavItems?.() || [],

        // ============================================
        // Stages manager: tasks vs deals
        // ============================================

        stagesManagerType: 'tasks',
        stagesManagerPipelineId: 1,
        dealStages: [],
        stagesManagerLoading: false,
        stagesManagerSaving: false,
        stagesManagerError: '',
        stagesManagerLastLoadedAt: '',
        stageManagerModalOpen: false,
        stageManagerEditing: null,
        stageManagerForm: { id: null, name: '', color: '#3B82F6', order: 0, is_won: false, is_lost: false },

        // Календари
        calendar: null,
        myTasksCalendar: null,

        // Gantt
        ganttCache: {},

        // Утилиты теперь в assets/js/utils.js

        // ============================================
        // ИНИЦИАЛИЗАЦИЯ
        // ============================================

        async init() {
            this.isDesktop = window.matchMedia('(min-width: 1024px)').matches;
            window.addEventListener('resize', () => {
                this.isDesktop = window.matchMedia('(min-width: 1024px)').matches;
            });

            try {
                window.__CHAT_RUNTIME__ = {
                    version: this.chatRuntimeVersion,
                    bundle: 'assets/js/app_combined.js?v=20',
                    template: 'assets/components/chat-view.html',
                    apiBase: 'api/index.php?endpoint=chat/...',
                    ts: new Date().toISOString()
                };
            } catch (_) {}

            // License check (non-blocking, no console spam)
            this.checkLicenseStatus();

            this.initGanttBridge();

            // Проверка темы
            this.isDark = localStorage.getItem('theme') === 'dark' ||
                          (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
            this.applyTheme();

            await window.TaskFlowAuthSession?.bootstrapAuthenticatedSession?.(this);

            // Инициализация иконок
            this.$nextTick(() => {
                if (window.lucide) {
                    lucide.createIcons();
                }
            });

            // Watch для переключения видов
            window.TaskFlowViewRuntime?.initCurrentViewWatcher?.(this);

            // Watch для сохранения представления задач в localStorage
            window.TaskFlowTasks?.initViewPersistence?.(this);
            window.TaskFlowProjects?.initProjectsViewPersistence?.(this);

        // Инициализация умного топбара
        this.initSmartTopbar();

            // Heavy modules: lazy-load on demand (faster start on weak machines)
            // CRM/Leader/Shift will load when user opens related views.
            await window.TaskFlowInitLifecycle?.bootstrapRuntime?.(this);
        },

        initGanttBridge() {
            return window.TaskFlowAppResidualCore?.initGanttBridge?.(this);
        },

        // ============================================
        // CRM METHODS
        // ============================================

        crmFormatMoney(amount, currency = 'RUB') {
            const n = Number(amount || 0);
            const cur = String(currency || 'RUB');
            try {
                return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(n);
            } catch (_) {
                return `${Math.round(n)} ${cur}`;
            }
        },

        async crmLoadDashboard() {
            return window.TaskFlowCrmDashboardSales?.loadDashboard?.(this);
        },

        async crmLoadClients() {
            return window.TaskFlowCrmListFilters?.loadClients?.(this);
        },

        async crmLoadSalesAnalytics() {
            return window.TaskFlowCrmDashboardSales?.loadSalesAnalytics?.(this);
        },

        async crmLoadStoreAnalytics() {
            try {
                if (!window.TaskFlowCrmStore?.loadStoreAnalytics) {
                    await ModuleLoader.loadModule('crm-store');
                }
            } catch (e) {
                console.warn('Failed to load crm-store module', e);
            }

            if (!window.TaskFlowCrmStore?.loadStoreAnalytics) {
                this.showToast('Модуль "Интернет-магазин" не загрузился. Обновите страницу.', 'error');
                return false;
            }

            return window.TaskFlowCrmStore.loadStoreAnalytics(this);
        },

        async crmImportStoreOrders() {
            // If module isn't loaded yet, silently failing here looks like "nothing happens".
            // Make sure crm-store module is loaded, then call.
            try {
                if (!window.TaskFlowCrmStore?.importOrders) {
                    await ModuleLoader.loadModule('crm-store');
                }
            } catch (e) {
                console.warn('Failed to load crm-store module', e);
            }

            if (!window.TaskFlowCrmStore?.importOrders) {
                this.showToast('Модуль "Интернет-магазин" не загрузился. Обновите страницу.', 'error');
                return false;
            }

            return window.TaskFlowCrmStore.importOrders(this);
        },

        async crmRetryStoreOrders() {
            try {
                if (!window.TaskFlowCrmStore?.importOrders) {
                    await ModuleLoader.loadModule('crm-store');
                }
            } catch (e) {
                console.warn('Failed to load crm-store module', e);
            }

            if (!window.TaskFlowCrmStore?.importOrders) {
                this.showToast('Модуль "Интернет-магазин" не загрузился. Обновите страницу.', 'error');
                return false;
            }

            return window.TaskFlowCrmStore.importOrders(this, { trigger: 'retry' });
        },

        crmDestroySalesChart() {
            return window.TaskFlowCrmDashboardSales?.destroySalesChart?.(this);
        },

        crmFormatCompactNumber(value) {
            return window.TaskFlowCrmDashboardSales?.formatCompactNumber?.(value);
        },

        crmSalesChartMetricOptions() {
            return window.TaskFlowCrmDashboardSales?.getSalesChartMetricOptions?.(this);
        },

        crmSetSalesChartMetric(metric) {
            return window.TaskFlowCrmDashboardSales?.setSalesChartMetric?.(this, metric);
        },

        crmRenderSalesChart() {
            return window.TaskFlowCrmDashboardSales?.renderSalesChart?.(this);
        },

        crmDestroyStoreChart() {
            return window.TaskFlowCrmStore?.destroyStoreChart?.(this);
        },

        crmSetStoreChartMetric(metric) {
            return window.TaskFlowCrmStore?.setStoreChartMetric?.(this, metric);
        },

        crmRenderStoreChart() {
            return window.TaskFlowCrmStore?.renderStoreChart?.(this);
        },

        crmSelectStoreOrder(orderId) {
            return window.TaskFlowCrmStore?.selectOrder?.(this, orderId);
        },

        crmStoreComparisonLabel(delta, percent) {
            return window.TaskFlowCrmStore?.comparisonLabel?.(delta, percent) || '—';
        },

        crmStoreComparisonTone(delta) {
            return window.TaskFlowCrmStore?.comparisonTone?.(delta) || '';
        },

        crmStoreSyncStatusTone(state) {
            return window.TaskFlowCrmStore?.syncStatusTone?.(state) || '';
        },

        crmStoreSyncTimestamp(value) {
            return window.TaskFlowCrmStore?.formatSyncTimestamp?.(value) || '—';
        },

        crmStoreSyncAttemptLabel(trigger) {
            return window.TaskFlowCrmStore?.syncAttemptLabel?.(trigger) || 'Синхронизация';
        },

        crmClientEmptySales() {
            return window.TaskFlowCrmClientCard?.getEmptyClientSales?.() || {
                summary: {
                    total_sales: 0,
                    months_count: 0,
                    last_sale_month: null,
                    first_sale_month: null,
                    average_monthly_sales: 0,
                },
                history: [],
            };
        },

        async crmSetClientTab(tab) {
            return window.TaskFlowCrmClientCard?.setClientTab?.(this, tab);
        },

        crmAdminToolsCanManage() {
            return window.TaskFlowCrmMutations?.canManageAdminTools?.(this);
        },

        async crmRunAdminTool(operation, mode = 'dry-run') {
            return window.TaskFlowCrmMutations?.runAdminTool?.(this, operation, mode);
        },

        async loadDiagnosticsBaseline(force = false) {
            return window.TaskFlowCrmMutations?.loadDiagnosticsBaseline?.(this, force);
        },

        async crmLoadClientSales(clientId = null) {
            return window.TaskFlowCrmClientCard?.loadClientSales?.(this, clientId);
        },

        async crmEnsureReferralCode(clientId = null, forceRegenerate = false) {
            return window.TaskFlowCrmClientCard?.ensureReferralCode?.(this, clientId, forceRegenerate);
        },

        async crmLoadClientReferrals(clientId = null) {
            return window.TaskFlowCrmClientCard?.loadClientReferrals?.(this, clientId);
        },

        async crmCopyReferralText(text) {
            return window.TaskFlowCrmClientCard?.copyReferralText?.(this, text);
        },

        crmMonthLabel(value) {
            return window.TaskFlowCrmClientCard?.monthLabel?.(value) || '—';
        },

        async crmOpenClient(clientId, client = null) {
            return window.TaskFlowCrmClientCard?.openClientDrawer?.(this, clientId, client);
        },

        async crmOpenClientDrawer(clientId, client = null) {
            return window.TaskFlowCrmClientCard?.openClientDrawer?.(this, clientId, client);
        },

        async crmOpenClientDetail(clientId, client = null) {
            return window.TaskFlowCrmClientCard?.openClientDetail?.(this, clientId, client);
        },

        crmCloseClientDetailView() {
            return window.TaskFlowCrmClientCard?.closeClient?.(this);
        },

        crmCloseClientDetail() {
            return window.TaskFlowCrmClientCard?.closeClient?.(this);
        },

        async crmLoadClientTasks() {
            return window.TaskFlowCrmClientCard?.loadClientTasks?.(this);
        },

        async crmLoadClientActivity() {
            return window.TaskFlowCrmClientCard?.loadClientActivity?.(this);
        },

        crmOpenClientModal(client = null) {
            return window.TaskFlowCrmFormsModals?.openClientModal?.(this, client);
        },

        crmCloseClientModal() {
            return window.TaskFlowCrmFormsModals?.closeClientModal?.(this);
        },

        crmSetClientModalTab(tab = 'main') {
            return window.TaskFlowCrmFormsModals?.setClientModalTab?.(this, tab);
        },

        async crmSaveClient() {
            return window.TaskFlowCrmMutations?.saveClient?.(this);
        },

        async crmDeleteClient(clientId) {
            return window.TaskFlowCrmMutations?.deleteClient?.(this, clientId);
        },

        crmOpenContactModal() {
            return window.TaskFlowCrmFormsModals?.openContactModal?.(this);
        },

        crmCloseContactModal() {
            return window.TaskFlowCrmFormsModals?.closeContactModal?.(this);
        },

        async crmSaveContact() {
            return window.TaskFlowCrmMutations?.saveContact?.(this);
        },

        async crmLoadFunnels() {
            return window.TaskFlowCrmListFilters?.loadFunnels?.(this);
        },

        crmDealsByStage(stageId) {
            return window.TaskFlowCrmFunnels?.dealsByStage?.(this, stageId) || [];
        },

        crmStageSum(stageId) {
            return window.TaskFlowCrmFunnels?.stageSum?.(this, stageId) || 0;
        },

        crmGetColumnDisplayModeLabel() {
            return window.TaskFlowCrmFunnels?.getColumnDisplayModeLabel?.(this) || 'Суммы и сделки';
        },

        crmToggleColumnDisplayMode() {
            return window.TaskFlowCrmFunnels?.toggleColumnDisplayMode?.(this);
        },

        crmGetStageDisplayName(stage) {
            return window.TaskFlowCrmFunnels?.getStageDisplayName?.(stage) || String(stage?.name || '');
        },

        crmOnDealDragStart(deal) {
            return window.TaskFlowCrmFunnels?.onDealDragStart?.(this, deal);
        },

        async crmOnDealDrop(stageId) {
            return window.TaskFlowCrmMutations?.onDealDrop?.(this, stageId);
        },

        crmOpenDealModal(deal = null) {
            return window.TaskFlowCrmFormsModals?.openDealModal?.(this, deal);
        },

        crmCloseDealModal() {
            return window.TaskFlowCrmFormsModals?.closeDealModal?.(this);
        },

        async crmSetDealModalTab(tab = 'info') {
            return window.TaskFlowCrmFormsModals?.setDealModalTab?.(this, tab);
        },

        async crmSaveDeal() {
            return window.TaskFlowCrmMutations?.saveDeal?.(this);
        },

        crmOpenPipelineModal() {
            return window.TaskFlowCrmFormsModals?.openPipelineModal?.(this);
        },

        crmClosePipelineModal() {
            return window.TaskFlowCrmFormsModals?.closePipelineModal?.(this);
        },

        async crmSavePipeline() {
            return window.TaskFlowCrmMutations?.savePipeline?.(this);
        },

        async crmDeletePipeline(pipelineId) {
            return window.TaskFlowCrmMutations?.deletePipeline?.(this, pipelineId);
        },

        crmOpenStageModal(stage) {
            return window.TaskFlowCrmFormsModals?.openStageModal?.(this, stage);
        },

        crmCloseStageModal() {
            return window.TaskFlowCrmFormsModals?.closeStageModal?.(this);
        },

        async crmSaveStage() {
            return window.TaskFlowCrmMutations?.saveStage?.(this);
        },

        crmCreateLinkedTask({ client_id = '', deal_id = '' } = {}) {
            return window.TaskFlowCrmMutations?.createLinkedTask?.(this, { client_id, deal_id });
        },

        crmExport(type) {
            return window.TaskFlowCrmMutations?.exportData?.(this, type);
        },

        // ============================================
        // DOCUMENTS
        // ============================================

        async documentsReload() {
            return window.TaskFlowDocuments.reload(this);
        },

        async ensureHelpdeskLoaded() {
            return window.TaskFlowHelpdesk.ensureLoaded(this);
        },

        async ensureBookingLoaded() {
            return window.TaskFlowBooking?.ensureLoaded?.(this);
        },

        async loadHelpdeskTickets() {
            return window.TaskFlowHelpdesk.loadTickets(this);
        },

        async loadHelpdeskStatuses() {
            return window.TaskFlowHelpdesk.loadStatuses(this);
        },

        async loadHelpdeskCategories() {
            return window.TaskFlowHelpdesk.loadCategories(this);
        },

        async loadHelpdeskStats() {
            return window.TaskFlowHelpdesk.loadStats(this);
        },

        async loadBookingData(force = false) {
            return window.TaskFlowBooking?.loadData?.(this, force);
        },

        async refreshBookingData() {
            return window.TaskFlowBooking?.refresh?.(this);
        },

        async submitBookingRequest() {
            return window.TaskFlowBooking?.submitBookingRequest?.(this);
        },

        toggleServiceType(serviceId) {
            // booking-view.html expects this for multi-service selection
            if (!this.bookingForm) {
                this.bookingForm = window.TaskFlowBooking?.getDefaultForm?.() || { service_type_ids: [] };
            }
            if (!Array.isArray(this.bookingForm.service_type_ids)) {
                this.bookingForm.service_type_ids = [];
            }

            const id = String(serviceId || '');
            if (!id) return;
            const current = this.bookingForm.service_type_ids.map(String);
            const idx = current.indexOf(id);
            if (idx >= 0) current.splice(idx, 1);
            else current.push(id);
            this.bookingForm.service_type_ids = current;
        },

        formatDateTime(dateStr) {
            return window.TaskFlowSharedFormatters?.formatDateTime?.(dateStr) || '';
        },

        formatRelativeDate(dateStr) {
            return window.TaskFlowSharedFormatters?.formatRelativeDate?.(dateStr) || '';
        },

        async approveBookingRequest(request) {
            return window.TaskFlowBooking?.approveRequest?.(this, request);
        },

        async rejectBookingRequest(request) {
            return window.TaskFlowBooking?.rejectRequest?.(this, request);
        },

        selectBookingRequest(request) {
            if (!request?.id) return;
            this.bookingSelectedRequestId = request.id;
        },

        getSelectedBookingRequest() {
            return (this.bookingRequests || []).find((item) => String(item.id) === String(this.bookingSelectedRequestId || '')) || null;
        },

        getBookingStatusLabel(status) {
            return window.TaskFlowBooking?.getStatusLabel?.(status) || 'Новая';
        },

        getBookingStatusTone(status) {
            return window.TaskFlowBooking?.getStatusTone?.(status) || 'info';
        },

        getBookingServiceIcon(icon) {
            return window.TaskFlowBooking?.getServiceIcon?.(icon) || '📅';
        },

        openBookingServiceModal() {
            this.bookingServiceForm = window.TaskFlowBooking?.normalizeServiceFormForUi?.(null) || this.bookingServiceForm;
            this.bookingServiceModalOpen = true;
        },

        editBookingService(service) {
            this.bookingServiceForm = window.TaskFlowBooking?.normalizeServiceFormForUi?.(service) || this.bookingServiceForm;
            this.bookingServiceModalOpen = true;
        },

        async saveBookingService() {
            return window.TaskFlowBooking?.saveBookingService?.(this);
        },

        async deleteBookingService(service) {
            return window.TaskFlowBooking?.deleteBookingService?.(this, service);
        },

        setBookingScheduleToday() {
            if (typeof window.TaskFlowBooking?.setScheduleToday === 'function') {
                return window.TaskFlowBooking.setScheduleToday(this);
            }
            const d = new Date();
            const pad2 = (v) => String(v).padStart(2, '0');
            this.bookingScheduleDate = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
        },

        getBookingScheduleCell(serviceId, slotLabel) {
            return window.TaskFlowBooking?.getScheduleCell?.(this, serviceId, slotLabel) || [];
        },

        getStatByStatus(statusName) {
            return window.TaskFlowHelpdesk.getStatByStatus(this, statusName);
        },

        syncSelectedTicket() {
            return window.TaskFlowHelpdesk.syncSelectedTicket(this);
        },

        getHelpdeskLastUpdateLabel() {
            return window.TaskFlowHelpdesk.getLastUpdateLabel(this);
        },

        openCreateTicketModal() {
            return window.TaskFlowHelpdesk.openCreateTicketModal(this);
        },

        async createTicket() {
            return window.TaskFlowHelpdesk.createTicket(this);
        },

        async openTicketDetail(ticket) {
            return window.TaskFlowHelpdesk.openTicketDetail(this, ticket);
        },

        async loadTicketComments(ticketId) {
            return window.TaskFlowHelpdesk.loadTicketComments(this, ticketId);
        },

        async loadTicketHistory(ticketId) {
            return window.TaskFlowHelpdesk.loadTicketHistory(this, ticketId);
        },

        formatHistoryAction(h) {
            return window.TaskFlowHelpdesk.formatHistoryAction(this, h);
        },

        async addComment() {
            return window.TaskFlowHelpdesk.addComment(this);
        },

        async changeTicketStatus() {
            return window.TaskFlowHelpdesk.changeTicketStatus(this);
        },

        async assignTicket() {
            return window.TaskFlowHelpdesk.assignTicket(this);
        },

        openResolveModal() {
            return window.TaskFlowHelpdesk.openResolveModal(this);
        },

        async resolveTicket() {
            return window.TaskFlowHelpdesk.resolveTicket(this);
        },

        openConvertModal() {
            return window.TaskFlowHelpdesk.openConvertModal(this);
        },

        async convertTicket(type) {
            return window.TaskFlowHelpdesk.convertTicket(this, type);
        },

        isOverdue(dateStr) {
            return window.TaskFlowHelpdesk.isOverdue(this, dateStr);
        },

        async openCreateChatModal() {
            return window.TaskFlowChat.openCreateChatModal(this);
        },

        closeCreateChatModal() {
            return window.TaskFlowChat?.closeCreateChatModal?.(this);
        },

        closeGroupModal() {
            return window.TaskFlowChat?.closeGroupModal?.(this);
        },

        async loadChatRooms() {
            return window.TaskFlowChat.loadRooms(this);
        },

        searchChats() {
            return window.TaskFlowChat.searchRooms(this);
        },

        getFilteredChatRooms() {
            return window.TaskFlowChat.getFilteredRooms(this);
        },

        formatChatRoomTimestamp(dateStr) {
            return window.TaskFlowChat.formatRoomTimestamp(this, dateStr);
        },

        getChatRoomContextLabel(room) {
            return window.TaskFlowChat.getRoomContextLabel(this, room);
        },

        async selectChatRoom(room) {
            return window.TaskFlowChat.selectRoom(this, room);
        },

        async loadOlderChatMessages() {
            return window.TaskFlowChat.loadOlderMessages?.(this);
        },

        scrollChatToBottom(force = false) {
            return window.TaskFlowChat.scrollToBottom(this, force);
        },

        isChatNearBottom(threshold = 300) {
            return window.TaskFlowChat.isNearBottom(this, threshold);
        },

        forceChatScrollBottom() {
            return window.TaskFlowChat.forceScrollBottom(this);
        },

        ensureChatAutoScroll() {
            return window.TaskFlowChat.ensureAutoScroll(this);
        },

        async loadChatMembers(roomId) {
            return window.TaskFlowChat.loadMembers(this, roomId);
        },

        async sendChatMessage(messageType = 'text', extraData = {}) {
            return window.TaskFlowChat.sendMessage(this, messageType, extraData);
        },

        async markChatRoomAsRead(room) {
            return window.TaskFlowChat.markRoomAsRead(this, room);
        },

        notifyTyping() {
            return window.TaskFlowChat.notifyTyping(this);
        },

        showIncomingCall(callerName, callType = 'audio', roomId = null) {
            return window.TaskFlowChat.showIncomingCall(this, callerName, callType, roomId);
        },

        playCallSound() {
            return window.TaskFlowChat.playCallSound(this);
        },

        stopCallSound() {
            return window.TaskFlowChat.stopCallSound(this);
        },

        formatCallTime(seconds) {
            return window.TaskFlowChat.formatCallTime(this, seconds);
        },

        resetChatTextareaHeight() {
            return window.TaskFlowChat.resetTextareaHeight(this);
        },

        normalizeChatTextForStorage(text) {
            return window.TaskFlowChat.normalizeTextForStorage(this, text);
        },

        openChatRoomMenu(event, room) {
            return window.TaskFlowChat.openRoomMenu(this, event, room);
        },

        closeChatRoomMenu() {
            return window.TaskFlowChat.closeRoomMenu(this);
        },

        async loadFileTree() {
            return window.TaskFlowFiles.loadFileTree(this);
        },

        async refreshFilesView(options = {}) {
            return window.TaskFlowFiles.refreshView(this, options);
        },

        renderFolderTree(folder, level = 0) {
            return window.TaskFlowFiles.renderFolderTree(this, folder, level);
        },

        async loadFiles() {
            return window.TaskFlowFiles.loadFiles(this);
        },

        async uploadFile(file) {
            return window.TaskFlowFiles.uploadFile(this, file);
        },

        async navigateToFolder(folderId) {
            return window.TaskFlowFiles.navigateToFolder(this, folderId);
        },

        async buildFilesBreadcrumb() {
            return window.TaskFlowFiles.buildBreadcrumb(this);
        },

        createFolder() {
            return window.TaskFlowFiles.createFolder(this);
        },

        async confirmCreateFolder() {
            return window.TaskFlowFiles.confirmCreateFolder(this);
        },

        openFileInNewTab(file) {
            return window.TaskFlowFiles.openFileInNewTab(this, file);
        },

        isImage(filename) {
            return window.TaskFlowFiles.isImage(this, filename);
        },

        previewFile(file) {
            return window.TaskFlowFiles.previewFile(this, file);
        },

        downloadFile(file) {
            return window.TaskFlowFiles.downloadFile(this, file);
        },

        openFilesContextMenu(event, payload) {
            return window.TaskFlowFiles.openContextMenu(this, event, payload);
        },

        toggleFileSelection(file, event = null) {
            return window.TaskFlowFiles.toggleFileSelection(this, file, event);
        },

        onFileDragStart(event, file) {
            return window.TaskFlowFiles.onFileDragStart(this, event, file);
        },

        clearFileSelection() {
            return window.TaskFlowFiles.clearFileSelection(this);
        },

        async moveSelectedFilesToFolder(folderId) {
            return window.TaskFlowFiles.moveSelectedFilesToFolder(this, folderId);
        },

        onFolderDragOver(event) {
            return window.TaskFlowFiles.onFolderDragOver(this, event);
        },

        onFolderDragLeave(event) {
            return window.TaskFlowFiles.onFolderDragLeave(this, event);
        },

        async onFolderDrop(event, folderId) {
            return window.TaskFlowFiles.onFolderDrop(this, event, folderId);
        },

        showMoveToFolderModal() {
            return window.TaskFlowFiles.showMoveToFolderModal(this);
        },

        renderMoveToFolderTree(folder, level = 0) {
            return window.TaskFlowFiles.renderMoveToFolderTree(this, folder, level);
        },

        async moveFilesToFolder(folderId) {
            return window.TaskFlowFiles.moveFilesToFolder(this, folderId);
        },

        closeFilesContextMenu() {
            return window.TaskFlowFiles.closeContextMenu(this);
        },

        async renameContextFolder() {
            return window.TaskFlowFiles.renameContextFolder(this);
        },

        async deleteContextFolder() {
            return window.TaskFlowFiles.deleteContextFolder(this);
        },

        async deleteContextFile() {
            return window.TaskFlowFiles.deleteContextFile(this);
        },

        documentsSelectTemplate(templateId) {
            return window.TaskFlowDocuments.selectTemplate(this, templateId);
        },

        async documentsLoadTemplate(templateId) {
            return window.TaskFlowDocuments.loadTemplate(this, templateId);
        },

        documentsOpenTemplateEditor(template = null) {
            return window.TaskFlowDocuments.openTemplateEditor(this, template);
        },

        documentsResetTemplateEditor() {
            return window.TaskFlowDocuments.resetTemplateEditor(this);
        },

        async documentsSaveTemplate() {
            return window.TaskFlowDocuments.saveTemplate(this);
        },

        documentsToggleBatchTemplate(templateId) {
            return window.TaskFlowDocuments.toggleBatchTemplate(this, templateId);
        },

        async documentsOnClientChange() {
            return window.TaskFlowDocuments.onClientChange(this);
        },

        documentsBuildSourcePayload() {
            return window.TaskFlowDocuments.buildSourcePayload(this);
        },

        documentsResetSourceContext() {
            return window.TaskFlowDocuments.resetSourceContext(this);
        },

        documentsHistorySourceLabel(item) {
            return window.TaskFlowDocuments.historySourceLabel(this, item);
        },

        documentsOpenClientsDirectory() {
            return window.TaskFlowDocuments.openClientsDirectory(this);
        },

        async documentsRefreshHistory() {
            return window.TaskFlowDocuments.refreshHistory(this);
        },

        documentsGenerationFeedback(item = null) {
            return window.TaskFlowDocuments.generationFeedback(this, item);
        },

        documentsCopyField(fieldKey) {
            return window.TaskFlowDocuments.copyField(this, fieldKey);
        },

        documentsFieldBestForLabel(field) {
            return window.TaskFlowDocuments.fieldBestForLabel(this, field);
        },

        documentsFieldBadgeClass(field) {
            return window.TaskFlowDocuments.fieldBadgeClass(this, field);
        },

        documentsTemplateReadinessBadgeStyle(template) {
            return window.TaskFlowDocuments.templateReadinessBadgeStyle(this, template);
        },

        documentsTemplateLimitations(template) {
            return window.TaskFlowDocuments.templateLimitations(this, template);
        },

        documentsTemplateTokenMap(template = null) {
            return window.TaskFlowDocuments.templateTokenMap(this, template);
        },

        documentsTemplateTokenNotes(template = null) {
            return window.TaskFlowDocuments.templateTokenNotes(this, template);
        },

        documentsDocxRecommendedTokens() {
            return window.TaskFlowDocuments.docxRecommendedTokens(this);
        },

        async documentsImportTemplate(event) {
            return window.TaskFlowDocuments.importTemplate(this, event);
        },

        async documentsGenerateSingle() {
            return window.TaskFlowDocuments.generateSingle(this);
        },

        documentsIsDocxTemplate(template = null) {
            return window.TaskFlowDocuments.isDocxTemplate(this, template);
        },

        async documentsGenerateBatch() {
            return window.TaskFlowDocuments.generateBatch(this);
        },

        documentsDownloadBatch() {
            return window.TaskFlowDocuments.downloadBatch(this);
        },

        documentsOpenForClient(client = null) {
            return window.TaskFlowDocuments.openForClient(this, client);
        },

        documentsOpenForTask(task = null) {
            return window.TaskFlowDocuments.openForTask(this, task);
        },

        taskCanOpenDocuments(task = null) {
            return window.TaskFlowDocuments.taskCanOpenDocuments(this, task);
        },

        // ============================================
        // MY SHIFT (DB-backed)
        // ============================================

        shiftStatus: 'offline',
        shiftTimer: '00:00:00',
        shiftStart: null,
        breakTime: '0 мин',
        workedTime: '0 ч',
        shiftHistory: [],
        weekSchedule: [],
        shiftNote: '',
        _shiftTick: null,
        _shiftOpenSession: null,

        // Work schedules
        workSchedules: [],
        scheduleFilters: { user_id: '', date_from: '', date_to: '', month: '' },
        scheduleModalOpen: false,
        editingSchedule: null,
        scheduleForm: {
            user_id: '',
            schedule_date: '',
            period_start: '',
            period_end: '',
            shift_start: '09:00',
            shift_end: '18:00',
            break_minutes: 60,
            is_day_off: false,
            note: '',
            selected_template: ''
        },
        // Calendar view state
        scheduleViewMode: 'list',
        calendarCurrentMonth: new Date().toISOString().slice(0, 7),
        calendarPaddingDays: 0,
        calendarDays: [],
        // Context menu
        contextMenuOpen: false,
        contextMenuX: 0,
        contextMenuY: 0,
        contextMenuItem: null,
        calendarDayForContext: null,
        deleteConfirmModalOpen: false,
        scheduleToDelete: null,
        // HelpDesk
        helpdeskTickets: [],
        helpdeskStats: null,
        helpdeskStatuses: [],
        helpdeskCategories: [],
        helpdeskLoading: false,
        helpdeskSubmitting: false,
        helpdeskError: '',
        helpdeskLastLoadedAt: '',
        helpdeskSearch: '',
        helpdeskFilters: { status_id: '', category_id: '', priority: '' },
        selectedTicket: null,
        ticketComments: [],
        ticketHistory: [],
        newComment: '',
        commentIsInternal: false,
        showCommentForm: false,
        convertModalOpen: false,
        resolveModalOpen: false,
        resolveStatus: '',
        resolutionText: '',
        createTicketModalOpen: false,
        newTicketForm: { client_name: '', client_email: '', subject: '', description: '', category_id: '', priority: 'medium' },
        ticketDetailModalOpen: false,
        // Booking
        bookingLoading: false,
        bookingSubmitting: false,
        bookingError: '',
        bookingServiceTypes: [],
        bookingRequests: [],
        bookingStats: { total: 0, new: 0, approved: 0, rejected: 0 },
        bookingCanManage: false,
        bookingLastLoadedAt: '',
        bookingForm: window.TaskFlowBooking?.getDefaultForm?.() || { service_type_ids: [], client_name: '', client_email: '', client_phone: '', client_company: '', preferred_datetime: '', notes: '' },
        bookingSelectedRequestId: null,
        bookingServiceModalOpen: false,
        bookingServiceForm: window.TaskFlowBooking?.getDefaultServiceForm?.() || {
            id: null,
            type_key: '',
            type_name: '',
            description: '',
            icon: 'calendar',
            duration_minutes: 60,
            price_rub: 0,
            discount_type: 'none',
            discount_value: 0,
            promo_label: '',
            sort_order: 0,
            is_active: true
        },
        bookingScheduleDate: '',
        bookingScheduleHint: '',
        bookingScheduleSlots: [],
        bookingWorkingHours: [],
        birthdays: [],  // Дни рождения сотрудников

        async loadMyShift() {
            return window.TaskFlowShifts?.loadMyShift?.(this);
        },

        mapShiftForUi(s) {
            return window.TaskFlowShifts?.mapShiftForUi?.(this, s);
        },

        startShiftTicker() {
            return window.TaskFlowShifts?.startShiftTicker?.(this);
        },

        formatHms(totalSeconds) {
            return window.TaskFlowShifts?.formatHms?.(this, totalSeconds);
        },

        formatDuration(totalSeconds) {
            return window.TaskFlowShifts?.formatDuration?.(this, totalSeconds);
        },

        async startShift() {
            return window.TaskFlowShifts?.startShift?.(this);
        },

        async startBreak() {
            return window.TaskFlowShifts?.startBreak?.(this);
        },

        async endBreak() {
            return window.TaskFlowShifts?.endBreak?.(this);
        },

        async endShift() {
            return window.TaskFlowShifts?.endShift?.(this);
        },

        async saveShiftNote() {
            return window.TaskFlowShifts?.saveShiftNote?.(this);
        },

        // Work schedules methods
        async loadWorkSchedules() {
            return window.TaskFlowShifts?.loadWorkSchedules?.(this);
        },

        openScheduleModal(schedule = null) {
            return window.TaskFlowShifts?.openScheduleModal?.(this, schedule);
        },

        applyScheduleTemplate(template) {
            return window.TaskFlowShifts?.applyScheduleTemplate?.(this, template);
        },

        generateBulkSchedule() {
            return window.TaskFlowShifts?.generateBulkSchedule?.(this);
        },

        editSchedule(schedule) {
            return window.TaskFlowShifts?.editSchedule?.(this, schedule);
        },

        async saveSchedule() {
            return window.TaskFlowShifts?.saveSchedule?.(this);
        },

        generateWeekSchedule() {
            return window.TaskFlowShifts?.generateWeekSchedule?.(this);
        },

        // ============================================
        // CALENDAR VIEW METHODS
        // ============================================

        getMonthName(monthStr) {
            return window.TaskFlowShifts?.getMonthName?.(this, monthStr) || '';
        },

        previousMonth() {
            return window.TaskFlowShifts?.previousMonth?.(this);
        },

        nextMonth() {
            return window.TaskFlowShifts?.nextMonth?.(this);
        },

        generateCalendarDays() {
            return window.TaskFlowShifts?.generateCalendarDays?.(this);
        },

        onCalendarDayClick(day) {
            return window.TaskFlowShifts?.onCalendarDayClick?.(this, day);
        },

        openScheduleModalForDay(day) {
            return window.TaskFlowShifts?.openScheduleModalForDay?.(this, day);
        },

        // ============================================
        // CONTEXT MENU METHODS
        // ============================================

        openScheduleContextMenu(event, schedule) {
            return window.TaskFlowShifts?.openScheduleContextMenu?.(this, event, schedule);
        },

        onCalendarContextMenu(event, day) {
            return window.TaskFlowShifts?.onCalendarContextMenu?.(this, event, day);
        },

        confirmDeleteSchedule(schedule) {
            return window.TaskFlowShifts?.confirmDeleteSchedule?.(this, schedule);
        },

        async deleteSchedule() {
            return window.TaskFlowShifts?.deleteSchedule?.(this);
        },

        formatMoney(amount) {
            return window.TaskFlowLeader?.formatMoney?.(this, amount) || '—';
        },

        // ============================================
        // LEADER DASHBOARD
        // ============================================

        async loadLeaderDashboard() {
            return window.TaskFlowLeader?.loadLeaderDashboard?.(this);
        },

        leaderExport(type) {
            return window.TaskFlowLeader?.exportData?.(this, type);
        },

        getShiftViolations() {
            return window.TaskFlowLeader?.getShiftViolations?.(this) || [];
        },

        // ============================================
        // ANALYTICS
        // ============================================

        async loadAnalytics() {
            return window.TaskFlowLeader?.loadAnalytics?.(this);
        },

        renderAnalyticsCharts() {
            return window.TaskFlowLeader?.renderAnalyticsCharts?.(this);
        },

        renderTasksDailyChart() {
            return window.TaskFlowLeader?.renderTasksDailyChart?.(this);
        },

        renderTasksStatusChart() {
            return window.TaskFlowLeader?.renderTasksStatusChart?.(this);
        },

        renderTasksPriorityChart() {
            return window.TaskFlowLeader?.renderTasksPriorityChart?.(this);
        },

        renderShiftsDailyChart() {
            return window.TaskFlowLeader?.renderShiftsDailyChart?.(this);
        },

        renderCRMFunnelChart() {
            return window.TaskFlowLeader?.renderCRMFunnelChart?.(this);
        },

        renderEmployeeEfficiencyChart() {
            return window.TaskFlowLeader?.renderEmployeeEfficiencyChart?.(this);
        },

        renderTasksByProjectChart() {
            return window.TaskFlowLeader?.renderTasksByProjectChart?.(this);
        },

        guestJoinUrl(roomId) {
            return window.TaskFlowConferences?.guestJoinUrl?.(this, roomId);
        },

        async copyText(text) {
            return window.TaskFlowConferences?.copyText?.(this, text);
        },

        async copyGuestLink(roomId) {
            return window.TaskFlowConferences?.copyGuestLink?.(this, roomId);
        },

        openCreateConferenceModal() {
            return window.TaskFlowConferences?.openCreateConferenceModal?.(this);
        },

        closeCreateConferenceModal() {
            return window.TaskFlowConferences?.closeCreateConferenceModal?.(this);
        },

        async submitCreateConference() {
            return window.TaskFlowConferences?.submitCreateConference?.(this);
        },

        async loadConferences() {
            return window.TaskFlowConferences?.loadConferences?.(this);
        },

        async startConference(conferenceId) {
            return window.TaskFlowConferences?.startConference?.(this, conferenceId);
        },

        async endConference(conferenceId) {
            return window.TaskFlowConferences?.endConference?.(this, conferenceId);
        },

        async deleteConference(conferenceId) {
            return window.TaskFlowConferences?.deleteConference?.(this, conferenceId);
        },

        async revealPin(conferenceId) {
            return window.TaskFlowConferences?.revealPin?.(this, conferenceId);
        },

        async togglePin(conf) {
            return window.TaskFlowConferences?.togglePin?.(this, conf);
        },

        async rotatePin(conf) {
            return window.TaskFlowConferences?.rotatePin?.(this, conf);
        },

        async openInviteModal(conf) {
            return window.TaskFlowConferences?.openInviteModal?.(this, conf);
        },

        closeInviteModal() {
            return window.TaskFlowConferences?.closeInviteModal?.(this);
        },

        filteredInviteUsers() {
            return window.TaskFlowConferences?.filteredInviteUsers?.(this) || [];
        },

        toggleInviteUser(u) {
            return window.TaskFlowConferences?.toggleInviteUser?.(this, u);
        },

        async sendInvites() {
            return window.TaskFlowConferences?.sendInvites?.(this);
        },

        async joinConference(conf) {
            return window.TaskFlowConferences?.joinConference?.(this, conf);
        },

        async refreshConferenceSidebar() {
            return window.TaskFlowConferences?.refreshConferenceSidebar?.(this);
        },

        startConferencePolling() {
            return window.TaskFlowConferences?.startConferencePolling?.(this);
        },

        stopConferencePolling() {
            return window.TaskFlowConferences?.stopConferencePolling?.(this);
        },

        async reviewJoinRequest(requestId, status) {
            return window.TaskFlowConferences?.reviewJoinRequest?.(this, requestId, status);
        },

        toggleAudio() {
            return window.TaskFlowConferences?.toggleAudio?.(this);
        },

        toggleVideo() {
            return window.TaskFlowConferences?.toggleVideo?.(this);
        },

        leaveConference() {
            return window.TaskFlowConferences?.leaveConference?.(this);
        },

        // ============================================
        // SMART TOPBAR & SIDE PANELS
        // ============================================

        // Инициализация умного топбара
        initSmartTopbar() {
            return window.TaskFlowSmartTopbar?.init?.(this);
        },

        // Переключение панели уведомлений
        toggleNotificationsPanel() {
            return window.TaskFlowNotificationsPanel?.togglePanel(this);
        },

        // Переключение панели виджетов
        toggleWidgetsPanel() {
            return window.TaskFlowShellNavigation?.toggleWidgetsPanel(this);
        },

        // Закрытие всех панелей
        closeAllPanels() {
            return window.TaskFlowNotificationsPanel?.closeAllPanels(this);
        },

        // ============================================
        // WIDGET STORE METHODS
        // ============================================

        toggleWidget(widgetId) {
            return window.TaskFlowSiteWidgets?.toggleWidget(this, widgetId);
        },

        async saveWidgets() {
            return window.TaskFlowSiteWidgets?.saveWidgets(this);
        },

        async loadWidgets() {
            return window.TaskFlowSiteWidgets?.loadWidgets(this);
        },

        resetWidgets() {
            return window.TaskFlowSiteWidgets?.resetWidgets(this);
        },

        addExternalResource() {
            return window.TaskFlowSiteWidgets?.addExternalResource(this);
        },

        removeExternalResource(idx) {
            return window.TaskFlowSiteWidgets?.removeExternalResource(this, idx);
        },

        openExternalResource(url) {
            return window.TaskFlowSiteWidgets?.openExternalResource(this, url);
        },

        // Кликер методы
        clickerClick() {
            this.clickerScore += this.clickerMultiplier;
        },

        clickerBuyUpgrade() {
            if (this.clickerScore >= 10) {
                this.clickerScore -= 10;
                this.clickerMultiplier += 1;
                this.showToast('Улучшение куплено! +1 к клику', 'success');
            } else {
                this.showToast('Нужно 10 очков для улучшения', 'error');
            }
        },

        resetClicker() {
            this.clickerScore = 0;
            this.clickerMultiplier = 1;
        },

        async saveWeatherSettings() {
            try {
                await apiPut('settings/weather', {
                    weather_api_key: this.weatherApiKey,
                    weather_city: this.weatherCity
                });
                this.showToast('Настройки погоды сохранены', 'success');
            } catch (e) {
                console.error('Save weather settings error:', e);
                this.showToast('Ошибка сохранения настроек погоды', 'error');
            }
        },

        async loadBirthdays() {
            try {
                const res = await apiGet('users');
                if (res.success) {
                    const now = new Date();
                    const currentMonth = now.getMonth() + 1;
                    this.birthdays = (res.data || [])
                        .filter(u => u.birthday)
                        .map(u => ({
                            user_id: u.id,
                            full_name: u.full_name,
                            birthday: u.birthday,
                            month: new Date(u.birthday).getMonth() + 1
                        }))
                        .filter(b => b.month === currentMonth)
                        .sort((a, b) => new Date(a.birthday) - new Date(b.birthday));
                }
            } catch (e) {
                console.error('Load birthdays error:', e);
            }
        },

        async loadDealSubstages(dealId) {
            return window.TaskFlowCrmMutations?.loadDealSubstages?.(this, dealId);
        },

        async addDealSubstage(dealId, name) {
            return window.TaskFlowCrmMutations?.addDealSubstage?.(this, dealId, name);
        },

        async toggleDealSubstage(dealId, substageId, isCompleted, name) {
            return window.TaskFlowCrmMutations?.toggleDealSubstage?.(this, dealId, substageId, isCompleted, name);
        },

        async deleteDealSubstage(dealId, substageId) {
            return window.TaskFlowCrmMutations?.deleteDealSubstage?.(this, dealId, substageId);
        },

        async loadWeather() {
            try {
                const url = `https://api.openweathermap.org/data/2.5/weather?q=${encodeURIComponent(this.weatherCity)}&appid=${this.weatherApiKey}&units=metric&lang=ru`;
                const res = await fetch(url);
                const data = await res.json();
                if (data.cod === 200) {
                    this.weather = {
                        temp: Math.round(data.main.temp),
                        desc: data.weather[0].description,
                        icon: data.weather[0].icon,
                        humidity: data.main.humidity,
                        wind: data.wind.speed
                    };
                }
            } catch (e) {
                // Тихая ошибка — погода не критична
            }
        },

        async loadMailSettings() {
            return window.TaskFlowMail?.loadSettings?.(this);
        },

        updateDateTime() {
            return window.TaskFlowAppResidualCore?.updateDateTime?.(this);
        },

        // ============================================
        // АВТОРИЗАЦИЯ
        // ============================================

        async login() {
            return window.TaskFlowAuthSession?.login?.(this);
        },

        getRandomLoginWish() {
            return window.TaskFlowAuthSession?.getRandomLoginWish?.() || 'Пусть день сложится легко и продуктивно.';
        },

        async checkAuth() {
            return window.TaskFlowAuthSession?.checkAuth?.(this);
        },

        async checkLicenseStatus() {
            return window.TaskFlowAuthSession?.checkLicenseStatus?.(this);
        },

        logout() {
            return window.TaskFlowAuthSession?.logout?.(this);
        },

        // ============================================
        // ЗАГРУЗКА ДАННЫХ
        // ============================================

        async loadAllData() {
            return window.TaskFlowAuthSession?.loadAllData?.(this);
        },

        async loadUsers() {
            return window.TaskFlowAdmin?.loadUsers(this);
        },

        getFilteredUsers() {
            return window.TaskFlowAdmin?.getFilteredUsers(this) || [];
        },

        async refreshUsersList() {
            return window.TaskFlowAdmin?.refreshUsersList(this);
        },

        async loadDepartments() {
            return window.TaskFlowDepartments?.loadDepartments?.(this);
        },

        async loadProjects() {
            return window.TaskFlowProjects?.loadProjects?.(this);
        },

        async loadTasks() {
            if (this._tasksLoading) return;
            this._tasksLoading = true;
            try {
                const data = await apiGetTasks();
                if (data.success) {
                    this.tasks = data.data;

                // Прокидываем локально активные таймеры в задачи (для карточек)
                if (this.activeTimers && Object.keys(this.activeTimers).length) {
                    for (const t of this.tasks) {
                        const tId = parseInt(t.id, 10);
                        const localSeconds = this.activeTimers[tId];
                        if (localSeconds != null) {
                            t.active_timer_seconds = localSeconds;
                            t.active_timer_running = true;
                        }
                    }
                }

                    // Обогащаем задачи названиями проектов/отделов для карточек
                    for (const t of this.tasks) {
                        if (!t.project_name && t.project_id) {
                            const p = this.projects?.find(p => String(p.id) === String(t.project_id));
                            if (p) t.project_name = p.name;
                        }
                        if (!t.department_name && t.department_id) {
                            const d = this.departments?.find(d => String(d.id) === String(t.department_id));
                            if (d) t.department_name = d.name;
                        }
                        // Загружаем подэтапы для каждой задачи (кэшируем)
                        if (!t.substages) {
                            this.loadTaskSubstages(t.id).then(() => {
                                t.substages = this.taskSubstages;
                            });
                        }
                    }
                }
            } catch (error) {
                console.error('Ошибка загрузки задач:', error);
            } finally {
                this._tasksLoading = false;
            }
        },

        async loadStages() {
            return window.TaskFlowStagesManager?.loadStages?.(this);
        },

        async loadKnowledge() {
            return window.TaskFlowKnowledge?.loadKnowledge?.(this);
        },

        async loadNotifications() {
            return window.TaskFlowNotificationsPanel?.load(this);
        },

        normalizeNotification(notification) {
            return window.TaskFlowNotificationsPanel?.normalize(this, notification) || notification || {};
        },

        normalizeNotificationType(type) {
            return window.TaskFlowNotificationsPanel?.normalizeType(type) || String(type || 'info').toLowerCase();
        },

        parseNotificationPayload(payload) {
            return window.TaskFlowNotificationsPanel?.parsePayload(payload) || {};
        },

        pickNotificationText(notification, payload) {
            return window.TaskFlowNotificationsPanel?.pickText(notification, payload)
                || 'Откройте уведомление, чтобы посмотреть детали.';
        },

        pickNotificationSubtitle(notification, payload, type, target) {
            return window.TaskFlowNotificationsPanel?.pickSubtitle(notification, payload, type, target)
                || 'Уведомления';
        },

        extractNotificationTarget(notification, payload, type) {
            return window.TaskFlowNotificationsPanel?.extractTarget(this, notification, payload, type)
                || { type: this.normalizeNotificationType(type), id: null, label: '' };
        },

        refreshNotificationCounters() {
            return window.TaskFlowNotificationsPanel?.refreshCounters(this);
        },

        initNotificationEnhancements() {
            return window.TaskFlowNotificationsPanel?.initEnhancements(this);
        },

        getNotificationStorageKey() {
            return window.TaskFlowNotificationsPanel?.getStorageKey(this)
                || `workhub:lastNotifiedNotificationId:${this.currentUser?.id || 'guest'}`;
        },

        restoreLastNotifiedNotificationId() {
            return window.TaskFlowNotificationsPanel?.restoreLastNotifiedId(this);
        },

        persistLastNotifiedNotificationId(id) {
            return window.TaskFlowNotificationsPanel?.persistLastNotifiedId(this, id);
        },

        updateBrowserNotificationPromptVisibility() {
            return window.TaskFlowNotificationsPanel?.updateBrowserPromptVisibility(this);
        },

        isNotificationNewForClient(notification) {
            return !!window.TaskFlowNotificationsPanel?.isNewForClient(this, notification);
        },

        handleIncomingNotifications(notifications) {
            return window.TaskFlowNotificationsPanel?.handleIncoming(this, notifications);
        },

        getNotificationTypeLabel(notification) {
            return window.TaskFlowNotificationsPanel?.getTypeLabel(this, notification) || 'Уведомление';
        },

        getNotificationTypeIcon(notification) {
            return window.TaskFlowNotificationsPanel?.getTypeIcon(this, notification) || '•';
        },

        getNotificationMessage(notification) {
            return window.TaskFlowNotificationsPanel?.getMessage(notification) || 'Без дополнительного описания.';
        },

        getNotificationSubtitle(notification) {
            return window.TaskFlowNotificationsPanel?.getSubtitle(this, notification) || '';
        },

        getNotificationEntityLabel(notification) {
            return window.TaskFlowNotificationsPanel?.getEntityLabel(notification) || '';
        },

        getNotificationRelativeTime(dateStr) {
            return window.TaskFlowNotificationsPanel?.getRelativeTime(dateStr) || 'Без даты';
        },

        getNotificationAbsoluteTime(dateStr) {
            return window.TaskFlowNotificationsPanel?.getAbsoluteTime(this, dateStr) || '';
        },

        canOpenNotificationTarget(notification) {
            return !!window.TaskFlowNotificationsPanel?.canOpenTarget(this, notification);
        },

        getNotificationActionLabel(notification) {
            return window.TaskFlowNotificationsPanel?.getActionLabel(this, notification) || 'Открыть';
        },

        async openNotificationTarget(notification) {
            return window.TaskFlowNotificationsPanel?.openTarget(this, notification);
        },

        async markChatNotificationsByRoomAsRead(roomId) {
            return window.TaskFlowNotificationsPanel?.markChatRoomRead(this, roomId);
        },

        isChatOverlayActiveForCalls() {
            return Boolean(this.chatOverlayOpen);
        },

        clearCallToasts() {
            this.toasts = (this.toasts || []).filter(t => t.type !== 'call');
        },

        startNotificationsPolling() {
            return window.TaskFlowNotificationsPanel?.startPolling(this);
        },

        getFilteredRoles() {
            return window.TaskFlowAdmin?.getFilteredRoles(this) || [];
        },

        getRoleEnabledPermissionsCount(role) {
            return window.TaskFlowAdmin?.getRoleEnabledPermissionsCount(this, role) || 0;
        },

        async loadRoles(force = false) {
            return window.TaskFlowAdmin?.loadRoles(this, force);
        },

        async loadPermissions() {
            try {
                const data = await apiGetPermissions();
                if (data.success) this.permissions = data.data;
            } catch (error) {
                console.warn('Права недоступны, таблица permissions не существует');
                this.permissions = [];
            }
        },

        // ============================================
        // УТИЛИТЫ
        // ============================================

        getCurrentViewTitle() {
            return window.TaskFlowShellNavigation?.getCurrentViewTitle(this)
                || 'TaskFlow Pro';
        },

        getCurrentViewSubtitle() {
            return window.TaskFlowShellNavigation?.getCurrentViewSubtitle(this) || '';
        },

        isMobileMoreViewActive(viewId) {
            return window.TaskFlowMobileShell?.isMoreViewActive(this, viewId);
        },

        getMobileContextTag() {
            return window.TaskFlowMobileShell?.getContextTag(this);
        },

        getMobileMoreGroups() {
            return window.TaskFlowMobileShell?.getMoreGroups(this);
        },

        openMobileMore() {
            return window.TaskFlowMobileShell?.openMore(this);
        },

        closeMobileMore() {
            return window.TaskFlowMobileShell?.closeMore(this);
        },

        openMobileProfile() {
            return window.TaskFlowMobileShell?.openProfile(this);
        },

        closeMobileProfile() {
            return window.TaskFlowMobileShell?.closeProfile(this);
        },

        async handleMobileProfileAction(action) {
            return window.TaskFlowMobileShell?.handleProfileAction(this, action);
        },

        handleMobileHeaderAction(action) {
            return window.TaskFlowMobileShell?.handleHeaderAction(this, action);
        },

        handleMobileQuickAction(action) {
            return window.TaskFlowMobileShell?.handleQuickAction(this, action);
        },

        navigateMobileMore(viewId) {
            return window.TaskFlowMobileShell?.navigateMore(this, viewId);
        },

        getMobilePrimaryNavItems() {
            return window.TaskFlowMobileShell?.getPrimaryNavItems(this);
        },

        isMobileNavActive(viewId) {
            return window.TaskFlowMobileShell?.isNavActive(this, viewId);
        },

        navigateMobilePrimary(viewId) {
            return window.TaskFlowMobileShell?.navigatePrimary(this, viewId);
        },

        normalizeSiteWidgetApiBase(value) {
            return window.TaskFlowSiteWidgets?.normalizeApiBase(value);
        },

        normalizeSiteWidgetProfile(profile) {
            return window.TaskFlowSiteWidgets?.normalizeProfile(this, profile);
        },

        selectSiteWidgetProfile(profileId) {
            return window.TaskFlowSiteWidgets?.selectProfile(this, profileId);
        },

        getSelectedSiteWidgetProfile() {
            return window.TaskFlowSiteWidgets?.getSelectedProfile(this);
        },

        getSelectedSiteWidgetProfileSlug() {
            return window.TaskFlowSiteWidgets?.getSelectedProfileSlug(this);
        },

        getSelectedSiteWidgetProfileName() {
            return window.TaskFlowSiteWidgets?.getSelectedProfileName(this);
        },

        getSelectedSiteWidgetProfileSnippetLabel() {
            return window.TaskFlowSiteWidgets?.getSelectedProfileSnippetLabel(this);
        },

        getDefaultSiteWidgetsConfig() {
            return window.TaskFlowSiteWidgets?.getDefaultConfig();
        },

        async loadSiteWidgetsSettings() {
            return window.TaskFlowSiteWidgets?.loadSettings(this);
        },

        async saveSiteWidgetsSettings() {
            return window.TaskFlowSiteWidgets?.saveSettings(this);
        },

        async createSiteWidgetProfile() {
            return window.TaskFlowSiteWidgets?.createProfile(this);
        },

        async activateSiteWidgetProfile(profileId = null) {
            return window.TaskFlowSiteWidgets?.activateProfile(this, profileId);
        },

        resetSiteWidgetsSettings() {
            return window.TaskFlowSiteWidgets?.resetSettings(this);
        },

        getSiteWidgetFrameBase() {
            return window.TaskFlowSiteWidgets?.getFrameBase(this);
        },

        getSiteWidgetFormPreviewUrl() {
            return window.TaskFlowSiteWidgets?.getFormPreviewUrl(this);
        },

        getSiteWidgetChatPreviewUrl() {
            return window.TaskFlowSiteWidgets?.getChatPreviewUrl(this);
        },

        getSiteWidgetEmbedCode(type) {
            return window.TaskFlowSiteWidgets?.getEmbedCode(this, type);
        },

        getSiteWidgetScriptCode() {
            return window.TaskFlowSiteWidgets?.getScriptCode(this);
        },

        async copySiteWidgetCode(type) {
            return window.TaskFlowSiteWidgets?.copyCode(this, type);
        },

        // Утилиты теперь в assets/js/utils.js

        // ============================================
        // МЕТОДЫ ДЛЯ ЗАДАЧ
        // ============================================

        async saveTask() {
            return window.TaskFlowTasks?.saveTask?.(this);
        },

        closeTaskModal() {
            return window.TaskFlowTasks?.closeTaskModal?.(this);
        },

        openTaskModal(task = null) {
            return window.TaskFlowTasks?.openTaskModal?.(this, task);
        },

        async deleteTask() {
            return window.TaskFlowTasks?.deleteTask?.(this);
        },

        async deleteTaskById(taskId) {
            return window.TaskFlowTasks?.deleteTaskById?.(this, taskId);
        },

        async moveTask(event, newStatus) {
            return window.TaskFlowTasks?.moveTask?.(this, event, newStatus);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ПРОЕКТОВ
        // ============================================

        async saveProject() {
            try {
                if (!window.TaskFlowProjects?.validateProjectForm?.(this)) return;

                const projectData = window.TaskFlowProjects?.buildProjectPayload?.(this);
                if (!projectData) {
                    this.showToast('Ошибка подготовки данных проекта', 'error');
                    return;
                }

                if (this.editingProject?.id) {
                    await apiUpdateProject(this.editingProject.id, projectData);
                    this.showToast('Проект обновлён', 'success');
                } else {
                    const result = await apiCreateProject(projectData);
                    this.showToast('Проект создан', 'success');
                    
                    // Автосоздание чата проекта
                    if (result.success && result.data?.id) {
                        await this.createProjectChat(result.data.id);
                    }
                }
                await this.loadProjects();
                this.closeProjectModal();
            } catch (error) {
                console.error('Ошибка сохранения проекта:', error);
                this.showToast('Ошибка сохранения проекта', 'error');
            }
        },

        async deleteProject() {
            if (!this.editingProject?.id) return;
            this.openConfirm(
                'Удалить проект?',
                'Вы уверены что хотите удалить этот проект? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteProject(this.editingProject.id);
                        this.showToast('Проект удалён', 'success');
                        await this.loadProjects();
                        this.closeProjectModal();
                    } catch (error) {
                        console.error('Ошибка удаления проекта:', error);
                        this.showToast('Ошибка: ' + error.message, 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        openProjectModal(project = null) {
            return window.TaskFlowProjects?.openProjectModal?.(this, project);
        },

        closeProjectModal() {
            return window.TaskFlowProjects?.closeProjectModal?.(this);
        },

        async loadProjectTasks(projectId) {
            return window.TaskFlowProjects?.loadProjectTasks?.(this, projectId);
        },

        async loadProjectFiles(projectId) {
            return window.TaskFlowProjects?.loadProjectFiles?.(this, projectId);
        },

        async loadProjectHistory(projectId) {
            return window.TaskFlowProjects?.loadProjectHistory?.(this, projectId);
        },

        async loadProjectComments(projectId) {
            return window.TaskFlowProjects?.loadProjectComments?.(this, projectId);
        },

        async addProjectComment() {
            const parentId = arguments.length ? arguments[0] : null;
            return window.TaskFlowProjects?.addProjectComment?.(this, parentId);
        },

        projectReplyTo(comment) {
            return window.TaskFlowProjects?.projectReplyTo?.(this, comment);
        },

        async deleteProjectComment(commentId) {
            return window.TaskFlowProjects?.deleteProjectComment?.(this, commentId);
        },

        async uploadFileToProject(file) {
            return window.TaskFlowProjects?.uploadFileToProject?.(this, file);
        },

        async deleteProjectFile(fileId) {
            return window.TaskFlowProjects?.deleteProjectFile?.(this, fileId);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ПОЛЬЗОВАТЕЛЕЙ
        // ============================================

        async saveUser() {
            return window.TaskFlowAdmin?.saveUser(this);
        },

        canDeleteUser(user) {
            return window.TaskFlowAdmin?.canDeleteUser(this, user) || false;
        },

        async deleteUser(user = null) {
            return window.TaskFlowAdmin?.deleteUser(this, user);
        },

        async openUserModal(user = null) {
            return window.TaskFlowAdmin?.openUserModal(this, user);
        },

        closeUserModal() {
            return window.TaskFlowAdmin?.closeUserModal(this);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ РОЛЕЙ
        // ============================================

        async saveRole() {
            return window.TaskFlowAdmin?.saveRole(this);
        },

        openRoleModal(role = null) {
            return window.TaskFlowAdmin?.openRoleModal(this, role);
        },

        closeRoleModal() {
            return window.TaskFlowAdmin?.closeRoleModal(this);
        },

        canEditRole(role) {
            return window.TaskFlowAdmin?.canEditRole(this, role) || false;
        },

        canDeleteRole(role) {
            return window.TaskFlowAdmin?.canDeleteRole(this, role) || false;
        },

        async refreshRolesList() {
            return window.TaskFlowAdmin?.refreshRolesList(this);
        },

        async deleteRole(role = null) {
            return window.TaskFlowAdmin?.deleteRole(this, role);
        },

        openRolePermissions(role) {
            return window.TaskFlowAdmin?.openRolePermissions(this, role);
        },

        closeRolePermissionsModal() {
            return window.TaskFlowAdmin?.closeRolePermissionsModal(this);
        },

        async saveRolePermissions() {
            return window.TaskFlowAdmin?.saveRolePermissions(this);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ОТДЕЛОВ
        // ============================================

        async saveDepartment() {
            return window.TaskFlowDepartments?.saveDepartment?.(this);
        },

        openDepartmentModal(dept = null) {
            return window.TaskFlowDepartments?.openDepartmentModal?.(this, dept);
        },

        async loadDepartmentEmployees(deptId) {
            return window.TaskFlowDepartments?.loadDepartmentEmployees?.(this, deptId);
        },

        async loadDepartmentProjects(deptId) {
            return window.TaskFlowDepartments?.loadDepartmentProjects?.(this, deptId);
        },

        async loadDepartmentTasks(deptId) {
            return window.TaskFlowDepartments?.loadDepartmentTasks?.(this, deptId);
        },

        closeDepartmentModal() {
            return window.TaskFlowDepartments?.closeDepartmentModal?.(this);
        },

        async deleteDepartment() {
            return window.TaskFlowDepartments?.deleteDepartment?.(this);
        },

        showDepartmentContextMenu(event, dept) {
            return window.TaskFlowDepartments?.showDepartmentContextMenu?.(this, event, dept);
        },

        openDepartmentContextMenu(event, dept) {
            return window.TaskFlowDepartments?.openDepartmentContextMenu?.(this, event, dept);
        },

        showProjectContextMenu(event) {
            return window.TaskFlowProjects?.showProjectContextMenu?.(this, event);
        },

        showProjectCardContextMenu(event, project) {
            return window.TaskFlowProjects?.showProjectCardContextMenu?.(this, event, project);
        },

        closeProjectContextMenu() {
            return window.TaskFlowProjects?.closeProjectContextMenu?.(this);
        },

        deleteProjectFromMenu() {
            return window.TaskFlowProjects?.deleteProjectFromMenu?.(this);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ БАЗЫ ЗНАНИЙ
        // ============================================

        async saveKnowledge() {
            return window.TaskFlowKnowledge?.saveKnowledge?.(this);
        },

        openKnowledgeModal(article = null) {
            return window.TaskFlowKnowledge?.openKnowledgeModal?.(this, article);
        },

        closeKnowledgeModal() {
            return window.TaskFlowKnowledge?.closeKnowledgeModal?.(this);
        },

        async deleteKnowledge() {
            return window.TaskFlowKnowledge?.deleteKnowledge?.(this);
        },

        // ============================================
        // МЕТОДЫ КОНТЕКСТНОГО МЕНЮ (ПКМ)
        // ============================================

        openContextMenu(event, task = null, stageName = null) {
            return window.TaskFlowSharedUi?.openContextMenu?.(this, event, task, stageName);
        },

        closeContextMenu() {
            return window.TaskFlowSharedUi?.closeContextMenu?.(this);
        },

        openCrmContextMenu(event, deal = null, stage = null) {
            return window.TaskFlowCrmFunnels?.openContextMenu?.(this, event, deal, stage);
        },

        closeCrmContextMenu() {
            return window.TaskFlowCrmFunnels?.closeContextMenu?.(this);
        },

        async crmDeleteDeal(dealId) {
            if (!dealId) return false;

            this.openConfirm(
                'Убрать сделку из списка?',
                'Сделка будет перемещена в архив (без удаления информации).',
                async () => {
                    const res = await apiDelete(`crm/deals/${dealId}`);
                    if (res.success) {
                        this.showToast('Сделка перемещена в архив', 'success');
                        await this.crmLoadFunnels();
                        this.closeCrmContextMenu();
                        return;
                    }
                    this.showToast(res.error || 'Не удалось удалить сделку', 'error');
                },
                { confirmText: 'Убрать', cancelText: 'Отмена', danger: true }
            );
            return false;
        },

        openMailContextMenu(event, email) {
            return window.TaskFlowSharedUi?.openMailContextMenu?.(this, event, email);
        },

        openMailFromMenu() {
            return window.TaskFlowMail?.openFromMenu?.(this);
        },

        replyMailFromMenu() {
            return window.TaskFlowMail?.replyFromMenu?.(this);
        },

        async toggleMailStarFromMenu() {
            return window.TaskFlowMail?.toggleStarFromMenu?.(this);
        },

        async toggleMailStarQuick(email) {
            return window.TaskFlowMail?.toggleStarQuick?.(this, email);
        },

        deleteMailFromMenu() {
            return window.TaskFlowMail?.deleteFromMenu?.(this);
        },

        handleContextMenu(event, task = null, stageName = null) {
            this.openContextMenu(event, task, stageName);
        },

        createTaskFromMenu() {
            return window.TaskFlowTasks?.createTaskFromMenu?.(this);
        },

        deleteTaskFromMenu() {
            return window.TaskFlowTasks?.deleteTaskFromMenu?.(this);
        },

        createStageFromMenu() {
            this.stageManagerForm = { id: null, name: '', color: '#3B82F6', order: 0, is_won: false, is_lost: false };
            this.stageManagerEditing = null;
            this.stagesManagerType = 'tasks';
            this.stageManagerModalOpen = true;
            this.closeContextMenu();
        },

        editStageFromMenu() {
            // Находим этап по имени
            const stageName = this.contextMenuStageName;
            const stage = this.stages.find(s => s.name === stageName);
            
            if (stage) {
                this.stageManagerForm = { ...stage };
                this.stageManagerEditing = stage;
                this.stagesManagerType = 'tasks';
                this.stageManagerModalOpen = true;
            }
            this.closeContextMenu();
        },

        deleteStageFromMenu() {
            if (this.contextMenuStageName) {
                const stage = this.stages.find(s => s.name === this.contextMenuStageName);
                if (stage) {
                    this.deleteStage(stage.id);
                }
            }
            this.closeContextMenu();
        },

        // ============================================
        // УПРАВЛЕНИЕ УВЕДОМЛЕНИЯМИ
        // ============================================

        async markNotificationRead(notificationId) {
            return window.TaskFlowNotificationsPanel?.markRead(this, notificationId);
        },

        async markAllNotificationsRead() {
            return window.TaskFlowNotificationsPanel?.markAllRead(this);
        },

        // ============================================
        // УПРАВЛЕНИЕ ТАЙМЕРОМ ЗАДАЧ
        // ============================================

        startTaskTimer() {
            return window.TaskFlowTasks?.startTaskTimer?.(this);
        },

        stopTaskTimer() {
            return window.TaskFlowTasks?.stopTaskTimer?.(this);
        },

        toggleTaskTimer() {
            return window.TaskFlowTasks?.toggleTaskTimer?.(this);
        },

        async saveTaskTimerToServer(taskId, seconds) {
            return window.TaskFlowTasks?.saveTaskTimerToServer?.(this, taskId, seconds);
        },

        // ============================================
        // НАСТРОЙКИ И ПРОФИЛЬ
        // ============================================

        async saveSettings() {
            return window.TaskFlowAdmin?.saveSettings(this);
        },

        async saveTelegram() {
            return window.TaskFlowAdmin?.saveTelegram(this);
        },

        async testTelegram() {
            return window.TaskFlowAdmin?.testTelegram(this);
        },

        async testMangoOffice() {
            return window.TaskFlowAdmin?.testMangoOffice(this);
        },

        async saveOmnichannel() {
            return window.TaskFlowAdmin?.saveOmnichannel(this);
        },

        async saveWebrtcSettings() {
            return window.TaskFlowAdmin?.saveWebrtcSettings(this);
        },

        async pingOmniTelegram() {
            return window.TaskFlowAdmin?.pingOmniTelegram(this);
        },

        async pingOmniMax() {
            return window.TaskFlowAdmin?.pingOmniMax(this);
        },

        async retryCall() {
            return window.TaskFlowChat?.retryCall?.(this);
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ПОЧТЫ
        // ============================================

        attachFile(event) {
            return window.TaskFlowMail?.attachLegacyFiles?.(this, event);
        },

        async sendEmail() {
            return window.TaskFlowMail?.sendLegacyEmail?.(this);
        },

        saveAsDraft() {
            return window.TaskFlowMail?.saveLegacyDraft?.(this);
        },

        async saveMailSettings() {
            return window.TaskFlowMail?.saveSettings?.(this);
        },

        async testMailConnection() {
            return window.TaskFlowMail?.testConnection?.(this);
        },

        async testImapConnection() {
            return window.TaskFlowMail?.testImapConnection?.(this);
        },

        async saveProfileSettings() {
            return window.TaskFlowAdmin?.saveProfileSettings(this);
        },

        closeSettingsModal() {
            return window.TaskFlowAdmin?.closeSettingsModal(this);
        },

        async openSettingsModal() {
            return window.TaskFlowAdmin?.openSettingsModal(this);
        },

        // ============================================
        // USER MENU ORDER (per-user)
        // ============================================

        async applyUserMenuOrder() {
            return window.TaskFlowMenuOrder?.apply(this);
        },

        initMenuSettings() {
            window.TaskFlowMenuOrder?.initDraft(this);
        },

        menuDragStart(idx) {
            return window.TaskFlowMenuOrder?.dragStart(this, idx);
        },

        menuDrop(idx) {
            return window.TaskFlowMenuOrder?.dropDraft(this, idx);
        },

        menuMove(idx, delta) {
            return window.TaskFlowMenuOrder?.moveByDelta(this, idx, delta);
        },

        async saveMenuOrder() {
            return window.TaskFlowMenuOrder?.saveWithFeedback(this);
        },

        async resetMenuOrder() {
            return window.TaskFlowMenuOrder?.resetWithConfirm(this);
        },

        async loadSettings() {
            return window.TaskFlowAdmin?.loadSettings(this);
        },

        async loadUserSettings() {
            return window.TaskFlowAdmin?.loadUserSettings(this);
        },

        async testEmailSettings() {
            return window.TaskFlowAdmin?.testEmailSettings(this);
        },

        async saveEmailSettings() {
            return window.TaskFlowAdmin?.saveEmailSettings(this);
        },

        async uploadAvatar(event) {
            return window.TaskFlowAdmin?.uploadAvatar(this, event);
        },

        async uploadLogo(event) {
            return window.TaskFlowAdmin?.uploadLogo(this, event);
        },

        async saveAppSettings() {
            return window.TaskFlowAdmin?.saveAppSettings(this);
        },

        resetAppSettings() {
            return window.TaskFlowAdmin?.resetAppSettings(this);
        },

        getReferralSecretSourceLabel(source) {
            return window.TaskFlowAdmin?.getReferralSecretSourceLabel(this, source) || 'не настроен';
        },

        async copyReferralText(text, successMessage = 'Скопировано') {
            return window.TaskFlowAdmin?.copyReferralText(this, text, successMessage);
        },

        async performGlobalSearch() {
            return window.TaskFlowTopbarSearch?.perform(this);
        },

        openTopbarSearch() {
            return window.TaskFlowTopbarSearch?.open(this);
        },

        closeTopbarSearch() {
            return window.TaskFlowTopbarSearch?.close(this);
        },

        getTopbarSearchSections() {
            return window.TaskFlowTopbarSearch?.getSections(this) || [];
        },

        hasTopbarSearchResults() {
            return !!window.TaskFlowTopbarSearch?.hasResults(this);
        },

        getTopbarSearchSectionTitle(section) {
            return window.TaskFlowTopbarSearch?.getSectionTitle(section) || section;
        },

        getTopbarSearchSectionItemTitle(section) {
            return window.TaskFlowTopbarSearch?.getSectionItemTitle(section) || section;
        },

        getTopbarSearchItemKey(section, item) {
            return window.TaskFlowTopbarSearch?.getItemKey(section, item) || `${section}-${item?.id || ''}`;
        },

        getTopbarSearchItemTitle(item) {
            return window.TaskFlowTopbarSearch?.getItemTitle(item) || '';
        },

        getTopbarSearchItemDescription(item) {
            return window.TaskFlowTopbarSearch?.getItemDescription(item) || '';
        },

        async navigateFromSearch(section, item) {
            return window.TaskFlowTopbarSearch?.navigate(this, section, item);
        },

        // ============================================
        // УПРАВЛЕНИЕ ЭТАПАМИ
        // ============================================

        async addStage() {
            return window.TaskFlowStagesManager?.addStage(this);
        },

        async updateStage(stageId) {
            return window.TaskFlowStagesManager?.updateStage(this, stageId);
        },

        async deleteStage(stageId) {
            return window.TaskFlowStagesManager?.deleteStage(this, stageId);
        },

        // ============================================
        // STAGES MANAGER (tasks vs deals)
        // ============================================

        getStagesManagerItems() {
            return window.TaskFlowStagesManager?.getItems(this) || [];
        },

        getStagesManagerSummary() {
            return window.TaskFlowStagesManager?.getSummary(this) || '';
        },

        getStagesManagerHelpText() {
            return window.TaskFlowStagesManager?.getHelpText(this) || '';
        },

        markStagesManagerLoaded() {
            return window.TaskFlowStagesManager?.markLoaded(this);
        },

        async refreshStagesManager(type = this.stagesManagerType, opts = {}) {
            return window.TaskFlowStagesManager?.refresh(this, type, opts);
        },

        async crmLoadStageManager() {
            return window.TaskFlowStagesManager?.crmLoad(this);
        },

        openStageManagerModal(stage = null) {
            return window.TaskFlowStagesManager?.openModal(this, stage);
        },

        async saveStageManager() {
            return window.TaskFlowStagesManager?.save(this);
        },

        async crmDeleteDealStage(stageId) {
            return window.TaskFlowStagesManager?.deleteDealStage(this, stageId);
        },

        async crmReorderDealStages() {
            return window.TaskFlowStagesManager?.reorderDealStages(this);
        },

        // ============================================
        // УТИЛИТЫ
        // ============================================

        getTasksByStage(stageName) {
            return window.TaskFlowTasks?.getTasksByStage?.(this, stageName) || [];
        },

        getMyTasksByStage(stageName) {
            return window.TaskFlowTasks?.getMyTasksByStage?.(this, stageName) || [];
        },

        translatePriority(priority) {
            return window.TaskFlowSharedFormatters?.translatePriority?.(priority) || priority;
        },

        formatDateTime(dateStr) {
            return window.TaskFlowSharedFormatters?.formatDateTime?.(dateStr) || '';
        },

        formatTimer(seconds) {
            return window.TaskFlowSharedFormatters?.formatTimer?.(seconds) || '0:00';
        },

        isOverdue(dateStr) {
            return window.TaskFlowSharedFormatters?.isOverdue?.(dateStr) || false;
        },

        getDeadlineClass(dateStr) {
            return window.TaskFlowSharedFormatters?.getDeadlineClass?.(dateStr) || 'text-gray-500';
        },

        getDaysUntilDeadline(dateStr) {
            return window.TaskFlowTasks?.getDaysUntilDeadline?.(this, dateStr) || '';
        },

        getInitials(name) {
            return window.TaskFlowSharedFormatters?.getInitials?.(name) || '?';
        },

        formatEmailDate(dateString) {
            return window.TaskFlowSharedFormatters?.formatEmailDate?.(dateString) || '';
        },

        formatDate(dateString) {
            return window.TaskFlowSharedFormatters?.formatRelativeDate?.(dateString) || '';
        },

        formatFileSize(bytes) {
            return window.TaskFlowSharedFormatters?.formatFileSize?.(bytes) || '0 B';
        },

        stripHtml(html) {
            return window.TaskFlowSharedFormatters?.stripHtml?.(html) || '';
        },

        sanitizeEmailHtml(html) {
            return window.TaskFlowSharedFormatters?.sanitizeEmailHtml?.(html) || '';
        },

        getAvatarGradient(name) {
            return window.TaskFlowSharedFormatters?.getAvatarGradient?.(name)
                || 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        },

        getHistoryActionText(item) {
            if (!item) return 'История недоступна';
            const userName = item.user_name || 'Пользователь #' + (item.user_id || '?');
            const actions = {
                'created': userName + ' создал задачу',
                'updated': userName + ' изменил ' + (item.field_name || 'задачу'),
                'status_changed': userName + ' изменил статус на "' + (item.new_value || 'неизвестно') + '"',
                'priority_changed': userName + ' изменил приоритет на "' + (item.new_value || 'неизвестно') + '"',
                'assigned': userName + ' назначил исполнителем',
                'comment_added': userName + ' добавил комментарий',
                'file_added': userName + ' прикрепил файл',
                'file_removed': userName + ' удалил файл'
            };
            return actions[item.action] || (userName + ' выполнил действие: ' + (item.action || 'неизвестно'));
        },

        async loadTaskComments(taskId) {
            if (!taskId) return;
            try {
                // Используем правильный endpoint /api/comments?task_id=X
                const data = await apiGet(`comments?task_id=${encodeURIComponent(taskId)}`);

                if (data.success) {
                    // API может вернуть объект с data.data или просто массив
                    const comments = Array.isArray(data.data) ? data.data :
                                     (data.data && Array.isArray(data.data.data) ? data.data.data : []);
                    this.taskComments = comments.filter(c => c !== null);
                }
            } catch (error) {
                console.error('Ошибка загрузки комментариев:', error);
                this.taskComments = [];
            }
        },

        async loadTaskFilesData(taskId) {
            if (!taskId) return;
            try {
                const data = await apiGet('files?task_id=' + encodeURIComponent(taskId));

                if (data.success) {
                    const files = Array.isArray(data.data) ? data.data :
                                  (data.data && Array.isArray(data.data.data) ? data.data.data : []);
                    this.taskFiles = files.filter(f => f !== null);
                }
            } catch (error) {
                console.error('Ошибка загрузки файлов:', error);
                this.taskFiles = [];
            }
        },

        async loadTaskHistory(taskId) {
            if (!taskId) return;
            try {
                const data = await apiGet(`tasks/${taskId}/history`);
                if (data.success) {
                    this.taskHistory = Array.isArray(data.data) ? data.data : [];
                } else {
                    this.taskHistory = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки истории:', error);
                this.taskHistory = [];
            }
        },

        async loadTaskSubstagesDict() {
            try {
                const data = await apiGet('task-substages');
                if (data.success) {
                    this.taskSubstages = Array.isArray(data.data) ? data.data : [];
                }
            } catch (error) {
                console.error('Ошибка загрузки справочника подэтапов:', error);
                throw error;
            }
        },

        // Backward-compat: some UI paths call loadTaskSubstages(taskId)
        // expecting it to exist and load the dictionary.
        async loadTaskSubstages(taskId) {
            await this.loadTaskSubstagesDict();
            return this.taskSubstages;
        },

        async openSubstageModal(substage = null) {
            return window.TaskFlowStagesManager?.openSubstageModal(this, substage);
        },

        async saveSubstage() {
            return window.TaskFlowStagesManager?.saveSubstage(this);
        },

        async deleteTaskSubstageDict(id) {
            return window.TaskFlowStagesManager?.deleteTaskSubstage(this, id);
        },

        async updateTaskSubstage(taskId, substageId) {
            try {
                const data = await apiPut(`tasks-direct.php?id=${taskId}`, { current_substage_id: substageId });
                if (data.success) {
                    this.showToast('Подэтап обновлён', 'success');
                    await this.loadTasks();
                }
            } catch (error) {
                this.showToast('Ошибка: ' + (error.message || 'Не удалось обновить подэтап'), 'error');
            }
        },

        async loadCrmDealSubstagesDict() {
            try {
                const data = await apiGet('crm-deal-substages');
                if (data.success) {
                    this.crmDealSubstages = Array.isArray(data.data) ? data.data : [];
                }
            } catch (error) {
                console.error('Ошибка загрузки справочника подэтапов CRM:', error);
                throw error;
            }
        },

        openCrmDealSubstageModal(substage = null) {
            return window.TaskFlowStagesManager?.openCrmDealSubstageModal(this, substage);
        },

        async saveCrmDealSubstage() {
            return window.TaskFlowStagesManager?.saveCrmDealSubstage(this);
        },

        async deleteCrmDealSubstageDict(id) {
            return window.TaskFlowStagesManager?.deleteCrmDealSubstage(this, id);
        },

        async loadCrmData() {
            try {
                // Загружаем сделки
                const dealsPromise = apiGet('crm/deals');
                // Загружаем клиентов
                const clientsPromise = apiGet(`crm/clients${window.TaskFlowCrmListFilters?.buildClientsQueryString?.(this) || ''}`);
                
                const [dealsRes, clientsRes] = await Promise.all([dealsPromise, clientsPromise]);
                
                if (dealsRes.success) {
                    this.crmDeals = dealsRes.data || [];
                }
                if (clientsRes.success) {
                    this.crmClients = clientsRes.data || [];
                }
            } catch (error) {
                console.error('Ошибка загрузки CRM данных:', error);
                this.crmDeals = [];
                this.crmClients = [];
            }
        },

        async updateDealSubstage(dealId, substageId) {
            try {
                const data = await apiPut(`crm/deals/${dealId}`, { current_substage_id: substageId });
                if (data.success) {
                    this.showToast('Подэтап обновлён', 'success');
                }
            } catch (error) {
                this.showToast('Ошибка: ' + (error.message || 'Не удалось обновить подэтап'), 'error');
            }
        },

        async addCommentToTask(parentId = null) {
            const text = parentId !== null ? this.commentReplyText : this.newCommentText;
            if (!text.trim() || !this.editingTask?.id) return;

            try {
                const result = await apiAddTaskComment(this.editingTask.id, text, parentId);

                if (result.success) {
                    this.showToast('Комментарий добавлен', 'success');
                    this.newCommentText = '';
                    this.commentReplyText = '';
                    this.commentReplyId = null;
                    await this.loadTaskComments(this.editingTask.id);
                } else {
                    this.showToast('Ошибка: ' + (result.error || 'Не удалось добавить комментарий'), 'error');
                }
            } catch (error) {
                console.error('Ошибка добавления комментария:', error);
                this.showToast('Ошибка добавления комментария: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        replyToTaskComment(comment) {
            this.commentReplyId = comment.id;
            this.commentReplyText = '';
            // Прокрутка к форме ответа
            this.$nextTick(() => {
                const textarea = document.querySelector('textarea[x-model="commentReplyText"]');
                if (textarea) textarea.focus();
            });
        },

        cancelReply() {
            this.commentReplyId = null;
            this.commentReplyText = '';
        },

        async deleteCommentFromTask(commentId) {
            if (!commentId) return;
            try {
                const result = await apiDeleteTaskComment(commentId);
                if (result.success) {
                    this.showToast('Комментарий удалён', 'success');
                    await this.loadTaskComments(this.editingTask.id);
                } else {
                    this.showToast('Ошибка: ' + (result.error || 'Не удалось удалить комментарий'), 'error');
                }
            } catch (error) {
                console.error('Ошибка удаления комментария:', error);
                this.showToast('Ошибка удаления комментария', 'error');
            }
        },

        async attachFileToCurrentTask(file) {
            if (!file || !this.editingTask?.id) return;

            // Создаём FormData с файлом и task_id
            const formData = new FormData();
            formData.append('file', file);
            formData.append('task_id', this.editingTask.id);

            try {
                // Используем apiUpload напрямую с FormData
                const token = getToken();
                const url = `${API_BASE_URL}?endpoint=files&_t=${Date.now()}${token ? `&token=${token}` : ''}`;

                const requestOptions = {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${token}`,
                    },
                    body: formData,
                };

                const result = typeof window.fetchJsonOrThrow === 'function'
                    ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось прикрепить файл')
                    : await (await fetch(url, requestOptions)).json();

                if (result.success) {
                    this.showToast('Файл прикреплён', 'success');
                    await this.loadTaskFilesData(this.editingTask.id);
                } else {
                    this.showToast('Ошибка: ' + (result.error || 'Не удалось прикрепить файл'), 'error');
                }
            } catch (error) {
                console.error('Ошибка прикрепления файла:', error);
                this.showToast('Ошибка прикрепления файла: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async deleteFileFromTask(fileId) {
            if (!fileId || !this.editingTask?.id) return;
            this.openConfirm(
                'Удалить файл?',
                'Вы уверены что хотите удалить этот файл? Это действие нельзя отменить.',
                async () => {
                    try {
                        const result = await apiDelete(`files/${fileId}`);
                        if (result.success) {
                            this.showToast('Файл удалён', 'success');
                            await this.loadTaskFilesData(this.editingTask.id);
                        } else {
                            this.showToast('Ошибка: ' + (result.error || 'Не удалось удалить файл'), 'error');
                        }
                    } catch (error) {
                        console.error('Ошибка удаления файла:', error);
                        this.showToast('Ошибка удаления файла: ' + (error.message || 'Неизвестная ошибка'), 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ЧЕК-ЛИСТА
        // ============================================

        async saveTaskChecklist() {
            if (this.editingTask?.id) {
                await apiPatch(`tasks/${this.editingTask.id}/checklist`, {
                    checklist: this.taskForm.checklist
                });
            }
        },

        addChecklistItem() {
            const newItem = {
                id: Date.now(),
                text: '',
                done: false
            };
            this.taskForm.checklist.push(newItem);
        },

        removeChecklistItem(index) {
            this.taskForm.checklist.splice(index, 1);
            this.saveTaskChecklist();
        },

        // ============================================
        // МЕТОДЫ ДЛЯ ФАЙЛОВ ПРОЕКТА
        // ============================================

        getFilteredProjectFiles() {
            return window.TaskFlowProjects?.getFilteredProjectFiles?.(this) || [];
        },

        // ============================================
        // КАЛЕНДАРЬ
        // ============================================

        initCalendar(calendarId = 'calendar', tasks = null) {
            return window.TaskFlowTasks?.initCalendar?.(this, calendarId, tasks);
        },

        initGantt(containerId, tasks = null) {
            return window.TaskFlowTasks?.initGantt?.(this, containerId, tasks);
        },

        buildGanttRows(tasks) {
            return window.TaskFlowTasks?.buildGanttRows?.(this, tasks) || [];
        },

        renderGantt(container, rows) {
            return window.TaskFlowTasks?.renderGantt?.(this, container, rows);
        },

        escapeHtml(s) {
            return window.TaskFlowSharedFormatters?.escapeHtml?.(s) || '';
        },

        // ============================================
        // ДОПОЛНИТЕЛЬНЫЕ МЕТОДЫ
        // ============================================

        showToast(message, type = 'info') {
            return window.TaskFlowSharedUi?.showToast?.(this, message, type);
        },

        closeToast(id) {
            return window.TaskFlowSharedUi?.closeToast?.(this, id);
        },

        showCallBanner(callData) {
            return window.TaskFlowSharedUi?.showCallBanner?.(this, callData);
        },

        // ============================================
        // ТЕМЫ
        // ============================================

        toggleTheme() {
            return window.TaskFlowSmartTopbar?.toggleTheme?.(this);
        },

        applyTheme() {
            return window.TaskFlowSmartTopbar?.applyTheme?.(this);
        },

        // ============================================
        // ЧАТ (Telegram-like) - Расширенные функции
        // ============================================

        formatChatTime(dateStr) {
            return window.TaskFlowChat.formatChatTime(this, dateStr);
        },

        formatMessageTime(dateStr) {
            return window.TaskFlowChat.formatMessageTime(this, dateStr);
        },

        formatTime(dateStr) {
            return window.TaskFlowChat.formatTime(this, dateStr);
        },

        formatChatDateChip(dateStr) {
            return window.TaskFlowChat.formatChatDateChip(this, dateStr);
        },

        buildChatTimeline(messages) {
            return window.TaskFlowChat.buildTimeline(this, messages);
        },

        get chatTimeline() {
            return window.TaskFlowChat.getTimeline(this);
        },

        isChatMessageOwn(msg) {
            return window.TaskFlowChat.isMessageOwn(this, msg);
        },

        formatVoiceDuration(seconds) {
            return window.TaskFlowChat.formatVoiceDuration(this, seconds);
        },

        // Статус сообщения (иконка)
        getMessageStatusIcon(msg) {
            return window.TaskFlowChat.getMessageStatusIcon(this, msg);
        },

        async loadChatUsers() {
            return window.TaskFlowChat.loadUsers(this);
        },

        async startPrivateChatWith(userId) {
            return window.TaskFlowChat.startPrivateChatWith(this, userId);
        },

        async createChatRoom(userId, type = 'private') {
            return window.TaskFlowChat.createRoom(this, userId, type);
        },

        async createGroupChat(name, memberIds) {
            return window.TaskFlowChat.createGroupChat(this, name, memberIds);
        },

        async createProjectChat(projectId) {
            try {
                const result = await apiPost('chat/rooms', {
                    type: 'project',
                    project_id: projectId
                });
                if (result.success) {
                    await this.loadChatRooms();
                    return result.data.room_id;
                }
            } catch (error) {
                console.error('Ошибка создания чата проекта:', error);
            }
            return null;
        },

        disableChatPolling() {
            return window.TaskFlowChat?.disablePolling?.(this);
        },

        // Запуск Long Polling для чата
        startChatPolling() {
            return window.TaskFlowChat?.startPolling?.(this);
        },

        async startChatLongPoll() {
            return window.TaskFlowChat?.startLongPoll?.(this);
        },

        // Фоновая проверка входящих звонков (даже когда чат закрыт)
        startBackgroundCallPolling() {
            return window.TaskFlowChat.startBackgroundCallPolling(this);
        },

        async startCallsLongPoll() {
            return window.TaskFlowChat.startCallsLongPoll(this);
        },

        // Остановка Long Polling
        stopChatPolling() {
            return window.TaskFlowChat?.stopPolling?.(this);
        },

        openChatOverlay() {
            return window.TaskFlowChat?.openOverlay?.(this);
        },

        closeChatOverlay() {
            return window.TaskFlowChat?.closeOverlay?.(this);
        },

        // Проверка новых сообщений
        async checkNewMessages() {
            return window.TaskFlowChat?.checkNewMessages?.(this);
        },

        // Проверка входящих звонков
        async checkIncomingCalls() {
            return window.TaskFlowChat.checkIncomingCalls(this);
        },

        // Тест входящего звонка (для демонстрации)
        testIncomingCall() {
            // Показываем тестовый звонок
            const callers = [
                { name: 'Иван Иванов', type: 'audio' },
                { name: 'Анна Петрова', type: 'video' },
                { name: 'Дмитрий Сидоров', type: 'audio' }
            ];
            const randomCaller = callers[Math.floor(Math.random() * callers.length)];
            
            this.showIncomingCall(randomCaller.name, randomCaller.type);
        },

        // Показ браузерного уведомления
        showBrowserNotification(title, body, options = {}) {
            return window.TaskFlowNotificationsPanel?.showBrowser(this, title, body, options) || null;
        },

        unlockNotificationSound() {
            return window.TaskFlowNotificationsPanel?.unlockSound(this);
        },

        playNotificationSound() {
            return window.TaskFlowNotificationsPanel?.playSound(this);
        },

        // Запрос разрешения на уведомления
        requestNotificationPermission(force = false) {
            return window.TaskFlowNotificationsPanel?.requestPermission(this, force)
                || Promise.resolve('unsupported');
        },

        // Принятие входящего звонка
        async acceptIncomingCall() {
            return window.TaskFlowChat.acceptIncomingCall(this);
        },

        // Отклонение входящего звонка
        async declineIncomingCall() {
            return window.TaskFlowChat.declineIncomingCall(this);
        },

        // ============================================
        // ПОЧТА
        // ============================================

        async loadMailFolders() {
            return window.TaskFlowMail?.loadFolders?.(this);
        },

        mapImapFoldersToUiFolders(folders) {
            return window.TaskFlowMailFoldersUi?.mapImapFoldersToUiFolders?.(folders) || [];
        },

        async loadMailFromFolder(folder) {
            return window.TaskFlowMail?.loadFromFolder?.(this, folder);
        },

        async openMailView() {
            return window.TaskFlowMail?.openView?.(this);
        },

        async refreshMail() {
            return window.TaskFlowMail?.refresh?.(this);
        },

        async createImapFolderPrompt() {
            return window.TaskFlowMail?.createImapFolderPrompt?.(this);
        },

        async createImapFolder() {
            return window.TaskFlowMail?.createImapFolder?.(this);
        },

        openMailConfirm(title, message, action) {
            return window.TaskFlowSharedUi?.openMailConfirm?.(this, title, message, action);
        },

        async runMailConfirm() {
            return window.TaskFlowSharedUi?.runMailConfirm?.(this);
        },

        openConfirm(title, message, action, opts = {}) {
            return window.TaskFlowSharedUi?.openConfirm?.(this, title, message, action, opts);
        },

        async runConfirm() {
            return window.TaskFlowSharedUi?.runConfirm?.(this);
        },

        openMailSettings() {
            return window.TaskFlowMail?.openSettings?.(this);
        },

        async openEmail(emailId) {
            return window.TaskFlowMail?.openEmail?.(this, emailId);
        },

        getMailAttachmentDownloadUrl(attachmentId) {
            return window.TaskFlowMailFoldersUi?.buildMailAttachmentDownloadUrl?.(attachmentId, getToken()) || '';
        },

        async loadMailAccounts() {
            return window.TaskFlowMail?.loadAccounts?.(this);
        },

        async saveMailAccount() {
            // Legacy modal removed. Сохраняем канонично: в профиль пользователя.
            await this.saveProfileSettings();
        },

        async deleteMailAccount(accountId) {
            return window.TaskFlowMail?.deleteAccount?.(this, accountId);
        },

        openMailComposeModal() {
            return window.TaskFlowMail?.openComposeModal?.(this);
        },

        getFolderName(folderId) {
            return window.TaskFlowMailFoldersUi?.getFolderName?.(this.mailFolders, folderId) || folderId;
        },

        toggleEmailSelection(emailId) {
            return window.TaskFlowMail?.toggleEmailSelection?.(this, emailId);
        },

        async moveSelectedEmails(folder) {
            return window.TaskFlowMail?.moveSelectedEmails?.(this, folder);
        },

        async toggleStarSelectedEmails() {
            return window.TaskFlowMail?.toggleStarSelectedEmails?.(this);
        },

        mailConfirmPurgeFolder(folder) {
            return window.TaskFlowMail?.purgeFolderPrompt?.(this, folder);
        },

        async purgeFolder() {
            return window.TaskFlowMail?.purgeFolder?.(this);
        },

        openMailContextMenu(event, email) {
            return window.TaskFlowMail?.openContextMenu?.(this, event, email);
        },

        async executeContextMenuAction(action) {
            return window.TaskFlowMail?.executeContextMenuAction?.(this, action);
        },

        async moveEmailToFolder(emailId, folder) {
            return window.TaskFlowMail?.moveEmailToFolder?.(this, emailId, folder);
        },

        async toggleEmailStar(email) {
            return window.TaskFlowMail?.toggleEmailStar?.(this, email);
        },

        async deleteEmail(emailId) {
            return window.TaskFlowMail?.deleteEmail?.(this, emailId);
        },

        replyToEmail() {
            return window.TaskFlowMail?.replyToEmail?.(this);
        },

        prepareReplyBody() {
            return window.TaskFlowMail?.prepareReplyBody?.(this) || '';
        },

        forwardEmail() {
            return window.TaskFlowMail?.forwardEmail?.(this);
        },

        prepareForwardBody() {
            return window.TaskFlowMail?.prepareForwardBody?.(this) || '';
        },

        quickReply(text) {
            return window.TaskFlowMail?.quickReply?.(this, text);
        },

        async sendMail() {
            return window.TaskFlowMail?.sendMail?.(this);
        },

        async saveMailDraft() {
            return window.TaskFlowMail?.saveDraft?.(this);
        },

        async syncImapFolder(folder = 'INBOX') {
            return window.TaskFlowMail?.syncFolder?.(this, folder);
        },

        closeEmailView() {
            const nextState = window.TaskFlowMailFoldersUi?.closeEmailView?.({
                emailViewModalOpen: this.emailViewModalOpen,
                viewingEmail: this.viewingEmail
            });
            this.emailViewModalOpen = nextState?.emailViewModalOpen ?? false;
            this.viewingEmail = nextState?.viewingEmail ?? null;
        },

        applyMailFormat(command) {
            return window.TaskFlowMail?.applyMailFormat?.(this, command);
        },

        insertMailLink() {
            return window.TaskFlowMail?.insertMailLink?.(this);
        },

        async insertInlineImage(event) {
            return window.TaskFlowMail?.insertInlineImage?.(this, event);
        },

        handleMailAttachments(event) {
            return window.TaskFlowMail?.handleAttachments?.(this, event);
        },

        removeMailAttachment(index) {
            return window.TaskFlowMail?.removeAttachment?.(this, index);
        },

        async editMessage(messageId, newText) {
            return window.TaskFlowChat?.editMessage?.(this, messageId, newText);
        },

        async deleteMessage(messageId) {
            return window.TaskFlowChat?.deleteMessage?.(this, messageId);
        },

        async deleteChatRoom(room) {
            return window.TaskFlowChat?.deleteRoom?.(this, room);
        },

        async forwardMessage(messageId, toRoomId) {
            return window.TaskFlowChat?.forwardMessage?.(this, messageId, toRoomId);
        },

        async markAsRead(messageId) {
            return window.TaskFlowChat?.markAsRead?.(this, messageId);
        },

        startPresencePolling() {
            return window.TaskFlowChat?.startPresencePolling?.(this);
        },

        stopPresencePolling() {
            return window.TaskFlowChat?.stopPresencePolling?.(this);
        },

        // Голосовые сообщения
        async startVoiceRecording() {
            return window.TaskFlowChat?.startVoiceRecording?.(this);
        },

        stopVoiceRecording() {
            return window.TaskFlowChat?.stopVoiceRecording?.(this);
        },

        cancelVoiceRecording() {
            return window.TaskFlowChat?.cancelVoiceRecording?.(this);
        },

        async applyPendingIceCandidates() {
            return window.TaskFlowChat.applyPendingIceCandidates(this);
        },

        // Воспроизведение голосового сообщения
        toggleVoicePlayback(msg) {
            return window.TaskFlowChat?.toggleVoicePlayback?.(this, msg);
        },

        playVoiceMessage(msg) {
            return window.TaskFlowChat?.playVoiceMessage?.(this, msg);
        },

        stopVoicePlayback() {
            return window.TaskFlowChat?.stopVoicePlayback?.(this);
        },

        // Контекстное меню сообщения
        openChatMessageMenu(event, msg) {
            return window.TaskFlowChat?.openMessageMenu?.(this, event, msg);
        },

        closeChatMessageMenu() {
            return window.TaskFlowChat?.closeMessageMenu?.(this);
        },

        previewChatImage(msg) {
            return window.TaskFlowChat?.previewImage?.(this, msg);
        },

        onMsgPointerDown(event, msg) {
            return window.TaskFlowChat?.onMessagePointerDown?.(this, event, msg);
        },

        onMsgPointerMove(event) {
            return window.TaskFlowChat?.onMessagePointerMove?.(this, event);
        },

        onMsgPointerUp() {
            return window.TaskFlowChat?.onMessagePointerUp?.(this);
        },

        // Поиск по сообщениям
        async searchMessages(query) {
            return window.TaskFlowChat?.searchMessages?.(this, query);
        },

        // Ответ на сообщение
        replyTo(msg) {
            return window.TaskFlowChat?.replyTo?.(this, msg);
        },

        // Авто-ресайз textarea (Telegram-like)
        autoResize(textarea) {
            return window.TaskFlowChat?.autoResize?.(this, textarea);
        },

        // Редактирование сообщения
        startEditMessage(msg) {
            return window.TaskFlowChat?.startEditMessage?.(this, msg);
        },

        // Отмена редактирования/ответа
        cancelEditOrReply() {
            return window.TaskFlowChat?.cancelEditOrReply?.(this);
        },

        // Подготовка к пересылке
        prepareForward(msg) {
            return window.TaskFlowChat?.prepareForward?.(this, msg);
        },

        // Выбор сообщения (для bulk действий)
        toggleMessageSelection(msg) {
            return window.TaskFlowChat?.toggleMessageSelection?.(this, msg);
        },

        // Удаление нескольких сообщений
        async deleteSelectedMessages() {
            return window.TaskFlowChat?.deleteSelectedMessages?.(this);
        },

        addEmoji(emoji) {
            return window.TaskFlowChat?.addEmoji?.(this, emoji);
        },

        // Отправка стикера
        async sendSticker(sticker) {
            return window.TaskFlowChat?.sendSticker?.(this, sticker);
        },

        // Прикрепление файлов (2 типа: файл и изображение)
        async attachFile(type = 'file') {
            return window.TaskFlowChat?.attachFile?.(this, type);
        },

        sendTask() {
            return window.TaskFlowChat?.sendTask?.(this);
        },

        sendProject() {
            return window.TaskFlowChat?.sendProject?.(this);
        },

        // openGroupModal is defined near the bottom (chat group creation)

        async attachTaskToMessage(taskId) {
            return window.TaskFlowChat?.attachTaskToMessage?.(this, taskId);
        },

        async attachProjectToMessage(projectId) {
            return window.TaskFlowChat?.attachProjectToMessage?.(this, projectId);
        },

        async attachSystemFile(fileId) {
            return window.TaskFlowChat?.attachSystemFile?.(this, fileId);
        },

        // Открытие задачи из сообщения
        async openTaskFromMessage(taskId) {
            return window.TaskFlowChat?.openTaskFromMessage?.(this, taskId);
        },

        // Открытие проекта из сообщения
        async openProjectFromMessage(projectId) {
            return window.TaskFlowChat?.openProjectFromMessage?.(this, projectId);
        },

        // Открытие файла в новой вкладке
        openFileInNewTab(fileUrl) {
            return window.TaskFlowChat?.openFileInNewTab?.(this, fileUrl);
        },

        startAudioCall() {
            return window.TaskFlowChat.startAudioCall(this);
        },

        startVideoCall() {
            return window.TaskFlowChat.startVideoCall(this);
        },

        async initCall() {
            return window.TaskFlowChat.initCall(this);
        },

        startWebrtcPolling() {
            return window.TaskFlowChat.startWebrtcPolling(this);
        },

        stopWebrtcPolling() {
            return window.TaskFlowChat.stopWebrtcPolling(this);
        },

        async handleWebrtcEvent(ev) {
            return window.TaskFlowChat.handleWebrtcEvent(this, ev);
        },

        endCall() {
            return window.TaskFlowChat.endCall(this);
        },

        toggleMute() {
            return window.TaskFlowChat.toggleMute(this);
        },

        toggleCamera() {
            return window.TaskFlowChat.toggleCamera(this);
        },

        // Открытие модального окна создания группы
        async openGroupModal() {
            return window.TaskFlowChat.openGroupModal(this);
        },

        // Создание группы
        async createGroup() {
            return window.TaskFlowChat.createGroup(this);
        },

        toggleGroupMember(memberId) {
            return window.TaskFlowChat.toggleGroupMember(this, memberId);
        },

        // ============================================
        // ДОПОЛНИТЕЛЬНЫЕ МЕТОДЫ
        // ============================================
        // (продолжение следует в index.html или app.methods.js)
    };
}

// Экспортируем для использования
if (typeof window !== 'undefined') {
    window.app = app;
}
