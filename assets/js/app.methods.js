/**
 * app.methods.js - Дополнительные методы для TaskFlow Pro
 * Методы которые не вошли в основную логику
 */

/**
 * Получить задачи по этапу
 */
function getTaskFlowApp() {
    return window.TaskFlowApp || null;
}

function ensureAppStateDefaults(app) {
    if (!app) return;

    if (typeof app.adminHealthLoading === 'undefined') app.adminHealthLoading = false;
    if (typeof app.adminHealthError === 'undefined') app.adminHealthError = '';
    if (typeof app.adminHealth === 'undefined') app.adminHealth = null;
    if (typeof app.adminHealthLoadedAt === 'undefined') app.adminHealthLoadedAt = 0;
    if (typeof app.adminHealthReady === 'undefined') app.adminHealthReady = false;

    if (typeof app.rolePermissionPreset === 'undefined') app.rolePermissionPreset = 'custom';
    if (typeof app.rolePermissionPresetOptions === 'undefined') {
        app.rolePermissionPresetOptions = ['custom', 'employee', 'manager', 'leader', 'admin', 'full'];
    }
}

if (typeof window !== 'undefined') {
    window.ensureAppStateDefaults = ensureAppStateDefaults;
}

function getTasksByStage(stageName) {
    const app = getTaskFlowApp();
    ensureAppStateDefaults(app);
    if (!app?.tasks) return [];
    return app.tasks.filter(t => t.status === stageName);
}

/**
 * Получить мои задачи по этапу
 */
function getMyTasksByStage(stageName) {
    const app = getTaskFlowApp();
    ensureAppStateDefaults(app);
    if (!app?.currentUser || !app?.tasks) return [];
    return app.tasks.filter(t => 
        t.status === stageName && 
        t.assigned_to === app.currentUser.id
    );
}

/**
 * Перевести приоритет
 */
function translatePriority(priority) {
    const priorities = {
        'low': 'Низкий',
        'medium': 'Средний',
        'high': 'Высокий',
        'urgent': 'Срочный'
    };
    return priorities[priority] || priority;
}

/**
 * Перевести статус CRM клиента
 */
function translateCrmStatus(status) {
    const statuses = {
        'active': 'Активен',
        'lead': 'Лид',
        'inactive': 'Не активен'
    };
    return statuses[status] || status;
}

/**
 * Перевести этап CRM сделки
 */
function translateDealStage(name) {
    const stages = {
        'Lead': 'Лид',
        'Qualification': 'Квалификация',
        'Proposal': 'Предложение',
        'Negotiation': 'Переговоры',
        'Contract': 'Договор',
        'Working': 'В работе',
        'Closed Won': 'Успех',
        'Closed Lost': 'Провал'
    };
    return stages[name] || name;
}

/**
 * Форматировать дату
 */
function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

/**
 * Форматировать дату и время
 */
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

/**
 * Форматировать таймер
 */
function formatTimer(seconds) {
    if (!seconds || seconds === 0) return '0:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h > 0) {
        return `${h}ч ${m}м`;
    }
    return `${m}м`;
}

/**
 * Проверить просрочен ли срок
 */
function isOverdue(dateStr) {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
}

/**
 * Получить дней до дедлайна
 */
function getDaysUntilDeadline(dateStr) {
    if (!dateStr) return '';
    const deadline = new Date(dateStr);
    const today = new Date();
    const diffTime = deadline - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        return 'Просрочено';
    } else if (diffDays === 0) {
        return 'Сегодня';
    } else if (diffDays === 1) {
        return '1 день';
    } else if (diffDays < 5) {
        return diffDays + ' дн.';
    } else {
        return diffDays + ' дней';
    }
}

/**
 * Получить класс для дедлайна
 */
function getDeadlineClass(dateStr) {
    if (!dateStr) return 'crm-text-secondary';
    const deadline = new Date(dateStr);
    const today = new Date();
    const diffTime = deadline - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        return 'crm-text-error font-semibold';
    } else if (diffDays === 0) {
        return 'crm-text-error font-semibold';
    } else if (diffDays < 3) {
        return 'crm-text-warning font-semibold';
    } else if (diffDays < 7) {
        return 'crm-text-warning';
    } else {
        return 'crm-text-secondary';
    }
}

/**
 * Получить текст действия из истории
 */
function getHistoryActionText(item) {
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
}

/**
 * Получить текст действия из истории проекта
 */
function getProjectHistoryActionText(item) {
    if (!item) return 'История недоступна';

    const userName = item.user_name || 'Пользователь #' + (item.user_id || '?');
    const actions = {
        'created': userName + ' создал проект',
        'updated': userName + ' обновил проект',
        'comment_added': userName + ' добавил комментарий',
        'file_added': userName + ' прикрепил файл',
        'file_removed': userName + ' удалил файл',
        'task_added': userName + ' добавил задачу',
        'task_removed': userName + ' удалил задачу'
    };
    return actions[item.action] || (userName + ' выполнил действие: ' + (item.action || 'неизвестно'));
}

/**
 * Получить путь для иконки роли
 */
function getIconPath(iconName) {
    const icons = {
        'shield': 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        'users': 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656.126-1.283.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        'user-check': 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z M9 16l2 2 4-4',
        'lock': 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
        'key': 'M15 7a2 2 0 012 2m4 0a2 2 0 01-2 2M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19v4h4M9 19v4m2-8v4m2-4v4',
        'briefcase': 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'folder': 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
        'document': 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'clipboard': 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        'chart-bar': 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'cog': 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'star': 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'
    };
    return icons[iconName] || icons['shield'];
}

/**
 * Сохранить чек-лист задачи
 */
async function saveTaskChecklist() {
    if (this.editingTask?.id) {
        await apiPatch(`tasks/${this.editingTask.id}/checklist`, {
            checklist: this.taskForm.checklist
        });
    }
}

/**
 * Добавить пункт в чек-лист
 */
function addChecklistItem() {
    const newItem = { 
        id: Date.now(),
        text: '', 
        done: false 
    };
    this.taskForm.checklist.push(newItem);
}

/**
 * Удалить пункт из чек-листа
 */
function removeChecklistItem(index) {
    this.taskForm.checklist.splice(index, 1);
    this.saveTaskChecklist();
}

/**
 * Загрузить комментарии задачи
 */
async function loadTaskComments(taskId) {
    const data = await apiGet(`tasks/${taskId}/comments`);
    this.taskComments = data?.data || [];
}

/**
 * Добавить комментарий к задаче
 */
async function addCommentToTask(parentId = null) {
    const text = parentId !== null ? this.commentReplyText : this.newCommentText;
    if (!text.trim() || !this.editingTask?.id) return;

    const result = await apiPost(`tasks/${this.editingTask.id}/comments`, {
        message: text,
        parent_id: parentId
    });
    
    if (result.success) {
        this.showToast('Комментарий добавлен', 'success');
        this.newCommentText = '';
        this.commentReplyText = '';
        this.commentReplyId = null;
        await loadTaskComments(this.editingTask.id);
    } else {
        this.showToast('Ошибка: ' + (result.error || 'Не удалось добавить комментарий'), 'error');
    }
}

/**
 * Удалить комментарий задачи
 */
async function deleteCommentFromTask(commentId) {
    const result = await apiDelete(`comments/${commentId}`);
    if (result.success) {
        this.showToast('Комментарий удалён', 'success');
        await loadTaskComments(this.editingTask.id);
    } else {
        this.showToast('Ошибка: ' + (result.error || 'Не удалось удалить комментарий'), 'error');
    }
}

/**
 * Прикрепить файл к задаче
 */
async function attachFileToCurrentTask(file) {
    if (!file || !this.editingTask?.id) return;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('task_id', this.editingTask.id);

    const result = await apiUpload('files', formData);
    if (result.success) {
        this.showToast('Файл прикреплён', 'success');
        await this.loadTaskFilesData(this.editingTask.id);
    } else {
        this.showToast('Ошибка: ' + (result.error || 'Не удалось прикрепить файл'), 'error');
    }
}

/**
 * Загрузить файлы задачи
 */
async function loadTaskFilesData(taskId) {
    const data = await apiGet(`tasks/${taskId}/files`);
    this.taskFiles = data?.data || [];
}

/**
 * Загрузить историю задачи
 */
async function loadTaskHistory(taskId) {
    this.taskHistory = [];
    if (!taskId) return;

    try {
        const data = await apiGet(`tasks/${taskId}/history`);
        if (data.success && Array.isArray(data.data)) {
            this.taskHistory = data.data;
        } else {
            this.taskHistory = [];
        }
    } catch (error) {
        console.error('Ошибка загрузки истории:', error);
        this.taskHistory = [];
    }
}

/**
 * Предпросмотр файла
 */
function previewFile(file) {
    if (!isImage(file.original_name)) return;
    this.imagePreviewName = file.original_name;
    this.imagePreviewSrc = apiGetFileDownload(file.id);
    this.imagePreviewOpen = true;
}

/**
 * Проверить является ли файл изображением
 */
function isImage(filename) {
    if (!filename) return false;
    const ext = filename.split('.').pop().toLowerCase();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext);
}

/**
 * Форматировать размер файла
 */
function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

/**
 * Открыть модальное окно задачи
 */
function openTaskModal(task = null) {
    this.editingTask = task;
    if (task) {
        // Обрабатываем checklist - может быть строкой JSON или массивом
        let checklist = task.checklist || [];
        if (typeof checklist === 'string') {
            try {
                checklist = JSON.parse(checklist);
            } catch (e) {
                checklist = [];
            }
        }
        // Добавляем ID к элементам checklist если их нет
        checklist = checklist.map((item, idx) => ({
            id: item.id || Date.now() + idx,
            text: item.text || '',
            done: item.done || false
        }));

        this.taskForm = {
            title: task.title,
            description: task.description || '',
            project_id: task.project_id || '',
            status: task.status,
            priority: task.priority,
            deadline: task.deadline || '',
            assigned_to: task.assigned_to || '',
            checklist: checklist,
        };
        
        // Восстанавливаем таймер если он в activeTimers
        if (this.activeTimers[task.id]) {
            this.taskTimerSeconds = this.activeTimers[task.id];
            this.taskTimerTaskId = task.id;
            this.taskTimerRunning = true;
        } else {
            this.taskTimerSeconds = task.timer_seconds || 0;
            this.taskTimerTaskId = task.id;
            this.taskTimerRunning = false;
        }
        
        // Загружаем комментарии и файлы
        loadTaskComments(task.id);
        loadTaskFilesData(task.id);
        // Загружаем историю
        loadTaskHistory(task.id);
    } else {
        this.taskForm = { 
            title: '', 
            description: '', 
            project_id: '', 
            status: 'Новая', 
            priority: 'medium', 
            deadline: '', 
            assigned_to: '', 
            checklist: [] 
        };
        this.taskTimerSeconds = 0;
        this.taskTimerTaskId = null;
        this.taskTimerRunning = false;
        this.taskComments = [];
        this.taskFiles = [];
        this.taskHistory = [];
    }
    
    // Открываем на вкладке "Задача"
    this.taskTab = 'task';
    this.newCommentText = '';
    this.taskModalOpen = true;
}

/**
 * Закрыть модальное окно задачи
 */
function closeTaskModal() {
    // Проверяем есть ли несохранённые изменения
    if (this.editingTask && this.taskForm.title !== this.editingTask.title) {
        if (confirm('Есть несохранённые изменения. Сохранить перед закрытием?')) {
            this.saveTask();
            return;
        }
    }
    this.taskModalOpen = false;
    this.editingTask = null;
}

/**
 * Сохранить задачу
 */
async function saveTask() {
    console.log('Сохранение задачи:', this.taskForm);
    try {
        if (this.editingTask?.id) {
            console.log('Обновление задачи:', this.editingTask.id);
            await apiUpdateTask(this.editingTask.id, this.taskForm);
            this.showToast('Задача обновлена', 'success');
        } else {
            console.log('Создание новой задачи');
            const result = await apiCreateTask(this.taskForm);
            console.log('Результат создания:', result);
            this.showToast('Задача создана', 'success');
        }
        
        console.log('Перезагрузка списка задач...');
        await this.loadTasks();
        console.log('Задач в списке:', this.tasks.length);

        // Если задача открыта из проекта, обновляем проект тоже
        if (this.editingProject?.id) {
            console.log('Обновление проекта после изменения задачи...');
            await apiGetProjectTasks(this.editingProject.id);
            await apiGetProjectFiles(this.editingProject.id);
        }

        closeTaskModal();
    } catch (error) {
        console.error('Ошибка сохранения задачи:', error);
        this.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
    }
}

/**
 * Удалить задачу
 */
async function deleteTask() {
    if (!this.editingTask?.id) return;
    
    if (!confirm('Вы уверены что хотите удалить эту задачу?')) return;

    try {
        await apiDeleteTask(this.editingTask.id);
        this.showToast('Задача удалена', 'success');
        
        // Обновляем список задач
        await this.loadTasks();
        
        closeTaskModal();
    } catch (error) {
        console.error('Ошибка удаления задачи:', error);
        this.showToast('Ошибка: ' + error.message, 'error');
    }
}

/**
 * Перемещение задачи (drag-and-drop)
 */
async function moveTask(event, newStatus) {
    event.preventDefault();
    const taskId = this.draggingTask;
    console.log('DROP event:', event);
    console.log('Перемещение задачи:', taskId, 'в статус:', newStatus);

    if (!taskId) {
        console.error('Нет taskId для перемещения');
        return;
    }

    try {
        const result = await apiMoveTask(taskId, newStatus);
        console.log('Результат:', result);

        if (result.success) {
            const task = this.tasks.find(t => t.id === taskId);
            if (task) {
                task.status = newStatus;
            }
            this.showToast('Статус обновлён', 'success');
        } else {
            this.showToast('Ошибка: ' + (result.error || 'Неизвестная'), 'error');
        }
    } catch (error) {
        console.error('Ошибка перемещения:', error);
        this.showToast('Ошибка перемещения: ' + error.message, 'error');
    }

    this.draggingTask = null;
}

/**
 * Удалить задачу по ID
 */
async function deleteTaskById(taskId) {
    if (!taskId) return;
    
    if (!confirm('Вы уверены что хотите удалить эту задачу?')) return;

    try {
        await apiDeleteTask(taskId);
        this.showToast('Задача удалена', 'success');
        await this.loadTasks();
    } catch (error) {
        console.error('Ошибка удаления задачи:', error);
        this.showToast('Ошибка: ' + error.message, 'error');
    }
}

/**
 * Сохранить проект
 */
async function saveProject() {
    console.log('saveProject called, hasUnsavedChanges:', this.hasUnsavedChanges);
    try {
        if (this.editingProject?.id) {
            await apiUpdateProject(this.editingProject.id, this.projectForm);
            this.showToast('Проект обновлён', 'success');
        } else {
            await apiCreateProject(this.projectForm);
            this.showToast('Проект создан', 'success');
        }
        
        await this.loadProjects();
        this.closeProjectModal();
    } catch (error) {
        console.error('Ошибка сохранения проекта:', error);
        this.showToast('Ошибка сохранения проекта', 'error');
    }
}

/**
 * Открыть модальное окно проекта
 */
function openProjectModal(project = null) {
    this.editingProject = project;
    if (project) {
        this.projectForm = {
            name: project.name,
            description: project.description || '',
            department_id: project.department_id || '',
            deadline: project.deadline || '',
            priority: project.priority || 'medium'
        };
    } else {
        this.projectForm = {
            name: '',
            description: '',
            department_id: '',
            deadline: '',
            priority: 'medium'
        };
    }
    this.projectModalOpen = true;
}

/**
 * Закрыть модальное окно проекта
 */
function closeProjectModal() {
    this.projectModalOpen = false;
    this.editingProject = null;
}

/**
 * Открыть модальное окно отдела
 */
function openDepartmentModal(dept = null) {
    this.editingDepartment = dept;
    if (dept) {
        this.departmentForm = {
            name: dept.name,
            description: dept.description || '',
            icon: dept.icon || 'building'
        };
    } else {
        this.departmentForm = {
            name: '',
            description: '',
            icon: 'building'
        };
    }
    this.departmentModalOpen = true;
}

/**
 * Закрыть модальное окно отдела
 */
function closeDepartmentModal() {
    this.departmentModalOpen = false;
    this.editingDepartment = null;
}

/**
 * Сохранить отдел
 */
async function saveDepartment() {
    try {
        if (this.editingDepartment?.id) {
            await apiUpdateDepartment(this.editingDepartment.id, this.departmentForm);
            this.showToast('Отдел обновлён', 'success');
        } else {
            await apiCreateDepartment(this.departmentForm);
            this.showToast('Отдел создан', 'success');
        }
        
        await this.loadDepartments();
        closeDepartmentModal();
    } catch (error) {
        console.error('Ошибка сохранения отдела:', error);
        this.showToast('Ошибка сохранения отдела', 'error');
    }
}

/**
 * Удалить отдел
 */
async function deleteDepartment() {
    if (!this.editingDepartment?.id) return;
    
    if (!confirm('Вы уверены что хотите удалить этот отдел?')) return;

    try {
        await apiDeleteDepartment(this.editingDepartment.id);
        this.showToast('Отдел удалён', 'success');
        await this.loadDepartments();
        closeDepartmentModal();
    } catch (error) {
        console.error('Ошибка удаления отдела:', error);
        this.showToast('Ошибка: ' + error.message, 'error');
    }
}

/**
 * Показать контекстное меню отдела
 */
function showDepartmentContextMenu(event, dept) {
    event.preventDefault();
    this.selectedDepartment = dept;
    this.departmentContextMenuX = event.clientX;
    this.departmentContextMenuY = event.clientY;
    this.departmentContextMenuOpen = true;
}

/**
 * Сохранить настройки
 */
async function saveSettings() {
    try {
        await apiUpdateSettings(this.settingsForm);
        this.showToast('Настройки сохранены', 'success');
    } catch (error) {
        this.showToast('Ошибка сохранения настроек', 'error');
    }
}

/**
 * Сохранить настройки Telegram
 */
async function saveTelegram() {
    try {
        await apiUpdateTelegram(this.telegramForm);
        this.showToast('Настройки Telegram сохранены', 'success');
    } catch (error) {
        this.showToast('Ошибка сохранения настроек Telegram', 'error');
    }
}

/**
 * Тестировать Telegram
 */
async function testTelegram() {
    try {
        const result = await apiTestTelegram();
        if (result.success) {
            this.showToast('Тестовое сообщение отправлено', 'success');
        } else {
            this.showToast('Ошибка: ' + (result.error || 'Не удалось отправить'), 'error');
        }
    } catch (error) {
        this.showToast('Ошибка подключения к Telegram', 'error');
    }
}

/**
 * Сохранить настройки профиля
 */
async function saveProfileSettings() {
    try {
        const payload = { ...this.settingsForm };
        if (!payload.avatar && this.currentUser?.avatar) {
            payload.avatar = null;
        }
        const result = await apiUpdateUserProfile(this.currentUser.id, payload);
        if (result.success) {
            this.currentUser = { ...this.currentUser, ...payload };
            this.showToast('Профиль сохранён', 'success');
            this.closeSettingsModal();
        } else {
            this.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить профиль'), 'error');
        }
    } catch (error) {
        this.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
    }
}

/**
 * Загрузить аватар
 */
async function uploadAvatar(event) {
    const file = event.target.files[0];
    if (!file) return;

    const result = await apiUploadAvatar(this.currentUser.id, file);
    if (result.success) {
        this.settingsForm.avatar = result.data.avatar;
        this.currentUser.avatar = result.data.avatar;
        this.showToast('Аватар обновлён', 'success');
    } else {
        this.showToast('Ошибка: ' + (result.error || 'Не удалось загрузить аватар'), 'error');
    }
}

/**
 * Загрузить логотип
 */
async function uploadLogo(event) {
    const file = event.target.files[0];
    if (!file) return;

    const result = await apiUploadLogo(file);
    if (result.success) {
        this.settingsForm.logo = result.data.logo;
        this.showToast('Логотип обновлён', 'success');
    } else {
        this.showToast('Ошибка: ' + (result.error || 'Не удалось загрузить логотип'), 'error');
    }
}

/**
 * Сохранить настройки приложения
 */
async function saveAppSettings() {
    try {
        const result = await apiUpdateSettings({
            company_name: this.settingsForm.company_name,
            app_name: this.settingsForm.app_name,
            logo: this.settingsForm.logo
        });
        if (result.success) {
            this.settings = {
                ...this.settings,
                company_name: this.settingsForm.company_name,
                app_name: this.settingsForm.app_name,
                logo: this.settingsForm.logo
            };
            this.showToast('Настройки приложения сохранены', 'success');
            this.closeSettingsModal();
        } else {
            this.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить настройки'), 'error');
        }
    } catch (error) {
        this.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
    }
}

/**
 * Сбросить настройки приложения
 */
function resetAppSettings() {
    this.settingsForm.company_name = 'TaskFlow Pro';
    this.settingsForm.app_name = 'TaskFlow';
    this.settingsForm.logo = '';
    this.showToast('Настройки сброшены', 'info');
}

/**
 * Закрыть модальное окно настроек
 */
function closeSettingsModal() {
    this.settingsModalOpen = false;
    this.loadSettings();
}

/**
 * Загрузить настройки
 */
function loadSettings() {
    if (this.currentUser?.id) {
        const referralSecretConfigured = String(this.settings?.referral_shared_secret_configured || '') === '1';
        const referralSecretSource = String(this.settings?.referral_shared_secret_source || 'none');
        
        this.settingsForm = {
            full_name: this.currentUser.full_name || '',
            phone: this.currentUser.phone || '',
            department_id: this.currentUser.department_id || '',
            bio: this.currentUser.bio || '',
            avatar: this.currentUser.avatar || '',
            birthday: this.currentUser.birthday || '',
            weather_city: this.currentUser.weather_city || '',
            company_name: this.settings?.company_name || 'TaskFlow Pro',
            app_name: this.settings?.app_name || 'TaskFlow',
            logo: this.settings?.logo || '',
            referral_woocommerce_base_url: this.settings?.referral_woocommerce_base_url || '',
            referral_shared_secret: '',
            woocommerce_api_consumer_key: this.settings?.woocommerce_api_consumer_key || '',
            woocommerce_api_consumer_secret: '',
            prostiezvonki_user: '',
            prostiezvonki_enabled: String(this.settings?.prostiezvonki_enabled || '') === '1',
            prostiezvonki_api_key: '',
            prostiezvonki_webhook_secret: ''
        };
        
        this.omniForm = {
            app_public_base_url: String(this.settings?.omni_app_public_base_url || ''),
            tg_enabled: String(this.settings?.omni_tg_enabled || '') === '1',
            tg_bot_token: '',
            tg_webhook_secret: '',
            max_enabled: String(this.settings?.omni_max_enabled || '') === '1',
            max_bot_token: '',
            max_webhook_secret: ''
        };
        
        this.bookingBotForm = {
            enabled: String(this.settings?.booking_bot_telegram_enabled || '') === '1',
            token: '',
            welcome_text: String(this.settings?.booking_bot_welcome_text || 'Здравствуйте! Я бот для записи на услуги. Напишите /book чтобы начать.')
        };
        
        this.webrtcForm = {
            ice_servers_json: String(this.settings?.webrtc_ice_servers_json || '[{"urls":"stun:stun.l.google.com:19302"}]')
        };
        
        this.referralIntegration = {
            orderWebhookUrl: buildReferralEndpointUrl('referrals/webhook/woocommerce'),
            visitEndpointUrl: buildReferralEndpointUrl('referrals/visit'),
            sharedSecretConfigured: referralSecretConfigured,
            sharedSecretSource: referralSecretSource
        };
        
        this.omniIntegration = {
            tgTokenConfigured: String(this.settings?.omni_tg_bot_token_configured || '') === '1',
            tgSecretConfigured: String(this.settings?.omni_tg_webhook_secret_configured || '') === '1',
            maxTokenConfigured: String(this.settings?.omni_max_bot_token_configured || '') === '1',
            maxSecretConfigured: String(this.settings?.omni_max_webhook_secret_configured || '') === '1'
        };
        
        this.loadUserSettings();
    }
}

function buildReferralEndpointUrl(endpoint) {
    try {
        return new URL(`api/index.php?endpoint=${endpoint}`, window.location.href).href;
    } catch (_error) {
        return `api/index.php?endpoint=${endpoint}`;
    }
}

/**
 * Загрузить настройки пользователя
 */
async function loadUserSettings() {
    try {
        const data = await apiGetUserProfile(this.currentUser.id);
        if (data.success && data.data) {
            const profile = data.data;
            if (profile.email_settings) {
                this.emailSettings = { ...this.emailSettings, ...profile.email_settings };
            }
            if (profile.notification_settings) {
                this.notificationSettings = { ...this.notificationSettings, ...profile.notification_settings };
            }
        }
    } catch (error) {
        console.error('Ошибка загрузки настроек:', error);
    }
}

/**
 * Сохранить настройки почты
 */
async function saveEmailSettings() {
    try {
        const result = await apiPut(`profile/${this.currentUser.id}/email-settings`, this.emailSettings);
        if (result.success) {
            if (this.showToast) this.showToast('Настройки почты сохранены', 'success');
            this.closeSettingsModal();
        } else {
            if (this.showToast) this.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить настройки'), 'error');
        }
    } catch (error) {
        if (this.showToast) this.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
    }
}

/**
 * Тестировать почту
 */
async function testEmailSettings() {
    if (this.showToast) this.showToast('Отправка тестового письма...', 'info');
    try {
        const result = await apiTestEmail(this.emailSettings);
        if (result.success) {
            if (this.showToast) this.showToast('Тестовое письмо отправлено!', 'success');
        } else {
            if (this.showToast) this.showToast('Ошибка: ' + (result.error || 'Не удалось отправить'), 'error');
        }
    } catch (error) {
        this.showToast('Ошибка: ' + (error.message || 'Не удалось отправить'), 'error');
    }
}

/**
 * Сохранить настройки уведомлений
 */
async function saveNotificationSettings() {
    try {
        const result = await apiPut(`profile/${this.currentUser.id}/notifications`, this.notificationSettings);
        if (result.success) {
            this.showToast('Настройки уведомлений сохранены', 'success');
            this.closeSettingsModal();
        } else {
            this.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить настройки'), 'error');
        }
    } catch (error) {
        this.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
    }
}

/**
 * Выполнить глобальный поиск
 */
async function performGlobalSearch() {
    if (!this.globalSearch || this.globalSearch.length < 2) {
        this.lastSearchResults = null;
        return;
    }

    try {
        const data = await apiGlobalSearch(this.globalSearch);
        if (data.success) {
            this.lastSearchResults = data.data;
        }
    } catch (error) {
        console.error('Ошибка поиска:', error);
    }
}

/**
 * Открыть контекстное меню
 */
function openContextMenu(event, task = null, stageName = null) {
    event.preventDefault();
    this.contextMenuX = event.clientX;
    this.contextMenuY = event.clientY;
    this.contextMenuTask = task;
    this.contextMenuStage = stageName ? { name: stageName } : null;
    this.contextMenuStageName = stageName;
    this.contextMenuOpen = true;
}

/**
 * Запустить таймер задачи
 */
function startTaskTimer() {
    this.taskTimerRunning = true;
    this.taskTimerTaskId = this.editingTask?.id || this.taskTimerTaskId;

    if (this.taskTimerTaskId) {
        this.activeTimers[this.taskTimerTaskId] = this.taskTimerSeconds;
    }
}

/**
 * Остановить таймер задачи
 */
function stopTaskTimer() {
    this.taskTimerRunning = false;
    if (this.taskTimerTaskId) {
        delete this.activeTimers[this.taskTimerTaskId];
    }
}

/**
 * Переключить таймер задачи
 */
function toggleTaskTimer() {
    if (this.taskTimerRunning) {
        this.stopTaskTimer();
    } else {
        this.startTaskTimer();
    }
}

/**
 * Сохранить таймер на сервер
 */
async function saveTaskTimerToServer(taskId, seconds) {
    try {
        await apiPatch(`tasks/${taskId}/timer`, { timer_seconds: seconds });
    } catch (error) {
        console.error('Ошибка сохранения таймера:', error);
    }
}

/**
 * Отметить уведомление прочитанным
 */
async function markNotificationRead(id) {
    try {
        await apiMarkNotificationRead(id);
        const notification = this.notifications.find(n => n.id === id);
        if (notification) {
            notification.read = true;
        }
        this.notificationCount = this.notifications.filter(n => !n.read).length;
    } catch (error) {
        console.error('Ошибка отметки уведомления:', error);
    }
}

/**
 * Отметить все уведомления прочитанными
 */
async function markAllNotificationsRead() {
    try {
        await apiMarkAllNotificationsRead();
        this.notifications.forEach(n => n.read = true);
        this.notificationCount = 0;
    } catch (error) {
        console.error('Ошибка отметки всех уведомлений:', error);
    }
}

// Экспортируем функции для использования в app()
if (typeof window !== 'undefined') {
    window.getTasksByStage = getTasksByStage;
    window.getMyTasksByStage = getMyTasksByStage;
    window.translatePriority = translatePriority;
    window.translateCrmStatus = translateCrmStatus;
    window.translateDealStage = translateDealStage;
    window.formatDate = formatDate;
    window.formatDateTime = formatDateTime;
    window.formatTimer = formatTimer;
    window.isOverdue = isOverdue;
    window.getDaysUntilDeadline = getDaysUntilDeadline;
    window.getDeadlineClass = getDeadlineClass;
    window.getHistoryActionText = getHistoryActionText;
    window.getProjectHistoryActionText = getProjectHistoryActionText;
    window.getIconPath = getIconPath;
    window.saveTaskChecklist = saveTaskChecklist;
    window.addChecklistItem = addChecklistItem;
    window.removeChecklistItem = removeChecklistItem;
    window.loadTaskComments = loadTaskComments;
    window.addCommentToTask = addCommentToTask;
    window.deleteCommentFromTask = deleteCommentFromTask;
    window.attachFileToCurrentTask = attachFileToCurrentTask;
    window.loadTaskFilesData = loadTaskFilesData;
    window.loadTaskHistory = loadTaskHistory;
    window.previewFile = previewFile;
    window.isImage = isImage;
    window.formatFileSize = formatFileSize;
    window.openTaskModal = openTaskModal;
    window.closeTaskModal = closeTaskModal;
    window.saveTask = saveTask;
    window.deleteTask = deleteTask;
    window.moveTask = moveTask;
    window.deleteTaskById = deleteTaskById;
    window.saveProject = saveProject;
    window.openProjectModal = openProjectModal;
    window.closeProjectModal = closeProjectModal;
    window.openDepartmentModal = openDepartmentModal;
    window.closeDepartmentModal = closeDepartmentModal;
    window.saveDepartment = saveDepartment;
    window.deleteDepartment = deleteDepartment;
    window.showDepartmentContextMenu = showDepartmentContextMenu;
    window.saveSettings = saveSettings;
    window.saveTelegram = saveTelegram;
    window.testTelegram = testTelegram;
    window.saveProfileSettings = saveProfileSettings;
    window.uploadAvatar = uploadAvatar;
    window.uploadLogo = uploadLogo;
    window.saveAppSettings = saveAppSettings;
    window.resetAppSettings = resetAppSettings;
    window.closeSettingsModal = closeSettingsModal;
    window.loadSettings = loadSettings;
    window.loadUserSettings = loadUserSettings;
    window.saveEmailSettings = saveEmailSettings;
    window.testEmailSettings = testEmailSettings;
    window.saveNotificationSettings = saveNotificationSettings;
    window.performGlobalSearch = performGlobalSearch;
    window.openContextMenu = openContextMenu;
    window.startTaskTimer = startTaskTimer;
    window.stopTaskTimer = stopTaskTimer;
    window.toggleTaskTimer = toggleTaskTimer;
    window.saveTaskTimerToServer = saveTaskTimerToServer;
    window.markNotificationRead = markNotificationRead;
    window.markAllNotificationsRead = markAllNotificationsRead;

    window.notificationTemplatesTab = notificationTemplatesTab;
    window.bookingWidgetsTab = bookingWidgetsTab;
}

/**
 * Alpine data для вкладки "Лицензия" в настройках
 */
function licenseTab() {
    return {
        licenseStatus: null,
        licenseRequestForm: { name: '', email: '', company: '', tier_requested: 'pro', message: '' },
        licenseRequestSending: false,

        async loadLicenseStatus() {
            try {
                const res = await fetch('/api/index.php?endpoint=license/status');
                const data = await res.json();
                if (data.success) this.licenseStatus = data.data;
            } catch (e) { console.error(e); }
        },

        async sendLicenseRequest() {
            this.licenseRequestSending = true;
            try {
                const res = await fetch('/api/index.php?endpoint=license/request', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.licenseRequestForm)
                });
                const data = await res.json();
                if (data.success) {
                    alert('Заявка отправлена');
                    this.licenseRequestForm = { name: '', email: '', company: '', tier_requested: 'pro', message: '' };
                } else {
                    alert(data.error || 'Ошибка отправки');
                }
            } catch (e) { console.error(e); alert('Ошибка отправки'); }
            this.licenseRequestSending = false;
        }
    };
}

/**
 * Alpine data для вкладки "Уведомления" в настройках
 */
function notificationTemplatesTab() {
    return {
        templates: [],
        logs: [],
        editingTemplate: null,
        editForm: { subject: '', body_html: '', body_text: '', is_active: true },
        testEmail: '',
        saving: false,

        async loadNotificationTemplates() {
            try {
                const res = await fetch('/api/index.php?endpoint=notifications/templates');
                const data = await res.json();
                if (data.success) this.templates = data.data || [];
            } catch (e) { console.error(e); }
            this.loadLogs();
        },

        async loadLogs() {
            try {
                const res = await fetch('/api/index.php?endpoint=notifications/logs?limit=20');
                const data = await res.json();
                if (data.success) this.logs = data.data || [];
            } catch (e) { console.error(e); }
        },

        editTemplate(t) {
            this.editingTemplate = t;
            this.editForm = {
                subject: t.subject || '',
                body_html: t.body_html || '',
                body_text: t.body_text || '',
                is_active: !!t.is_active,
            };
        },

        async saveTemplate() {
            if (!this.editingTemplate) return;
            this.saving = true;
            try {
                const res = await fetch('/api/index.php?endpoint=notifications/templates/' + this.editingTemplate.id, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.editForm)
                });
                const data = await res.json();
                if (data.success) {
                    this.editingTemplate = null;
                    await this.loadNotificationTemplates();
                }
            } catch (e) { console.error(e); }
            this.saving = false;
        },

        async sendTest() {
            if (!this.editingTemplate || !this.testEmail) return;
            this.saving = true;
            try {
                const res = await fetch('/api/index.php?endpoint=notifications/templates/' + this.editingTemplate.id, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: this.testEmail })
                });
                const data = await res.json();
                alert(data.message || (data.success ? 'Отправлено' : 'Ошибка'));
            } catch (e) { console.error(e); }
            this.saving = false;
        }
    };
}

/**
 * Alpine data для вкладки "Виджеты записи" в настройках
 */
function bookingWidgetsTab() {
    return {
        profiles: [],
        editingProfile: null,
        profileForm: { name: '', slug: '', display_mode: 'floating', brand_color: '#2563eb', hide_branding: false, require_email: false },
        generatedCode: '',
        saving: false,

        async loadBookingWidgetProfiles() {
            try {
                const res = await fetch('/api/index.php?endpoint=settings/site-widgets');
                const data = await res.json();
                if (data.success && data.data && data.data.profiles) {
                    this.profiles = data.data.profiles.map(p => ({...p, config: p.config || {}}));
                }
            } catch (e) { console.error(e); }
        },

        createNewProfile() {
            this.editingProfile = { id: null, name: '', slug: '', config: {} };
            this.profileForm = { name: '', slug: '', display_mode: 'floating', brand_color: '#2563eb', hide_branding: false, require_email: false };
        },

        editProfile(p) {
            this.editingProfile = p;
            const cfg = p.config || {};
            this.profileForm = {
                name: p.name || '',
                slug: p.slug || '',
                display_mode: cfg.display_mode || 'floating',
                brand_color: cfg.brand_color || '#2563eb',
                hide_branding: !!cfg.hide_branding,
                require_email: !!cfg.require_email,
            };
        },

        async saveProfile() {
            this.saving = true;
            try {
                const payload = {
                    name: this.profileForm.name,
                    slug: this.profileForm.slug,
                    ...this.profileForm,
                };
                const res = await fetch('/api/index.php?endpoint=settings/site-widgets', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    this.editingProfile = null;
                    await this.loadBookingWidgetProfiles();
                }
            } catch (e) { console.error(e); }
            this.saving = false;
        },

        generateCode(p) {
            const baseUrl = window.location.origin;
            const code = '<script src="' + baseUrl + '/widgets/site-widgets.js" data-mode="booking" data-profile="' + (p.slug || 'default') + '" data-base-url="' + baseUrl + '"><\/script>';
            this.generatedCode = code;
        },

        copyCode() {
            navigator.clipboard.writeText(this.generatedCode).then(() => alert('Код скопирован'));
        },

        widgetAnalyticsDays: 7,
        widgetAnalytics: null,
        widgetAnalyticsLoading: false,

        async loadWidgetAnalytics() {
            this.widgetAnalyticsLoading = true;
            try {
                const res = await fetch('/api/booking.php?action=widget-analytics-report&days=' + this.widgetAnalyticsDays);
                const data = await res.json();
                if (data.success) this.widgetAnalytics = data.data;
            } catch (e) { console.error(e); }
            this.widgetAnalyticsLoading = false;
        }
    };
}
