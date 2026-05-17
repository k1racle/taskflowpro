window.TaskFlowProjects = (function () {
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    const ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z', 'tar', 'gz'];

    function getFileExtension(file) {
        const originalName = file?.original_name || '';
        const parts = originalName.split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function getProjectFormDefaults() {
        return {
            name: '',
            description: '',
            department_ids: [],
            deadline: '',
            priority: 'medium'
        };
    }

    function getProjectFormFromProject(project) {
        return {
            name: project?.name || '',
            description: project?.description || '',
            department_ids: (project?.departments || []).map(d => d.id),
            deadline: project?.deadline || '',
            priority: project?.priority || 'medium'
        };
    }

    function resetProjectDetailState(ctx) {
        ctx.projectTasks = [];
        ctx.projectFiles = [];
        ctx.projectComments = [];
        ctx.projectHistory = [];
    }

    function resetProjectModalUiState(ctx) {
        ctx.projectModalTab = 'project';
        ctx.projectFilesTab = 'all';
        ctx.newProjectComment = '';
        ctx.projectReplyId = null;
        ctx.projectReplyText = '';
    }

    function getProjectsViewStorageKey() {
        return 'projectsView';
    }

    async function loadProjectCollection(ctx, loader, stateKey, errorMessage) {
        try {
            const data = await loader();
            if (data.success && data.data) {
                ctx[stateKey] = Array.isArray(data.data) ? data.data : [];
            } else {
                ctx[stateKey] = [];
            }
        } catch (error) {
            console.error(errorMessage, error);
            ctx[stateKey] = [];
        }
    }

    function getProjectCommentInput(ctx, parentId) {
        return parentId ? ctx.projectReplyText : ctx.newProjectComment;
    }

    function resetProjectCommentInput(ctx, parentId) {
        if (parentId) {
            ctx.projectReplyId = null;
            ctx.projectReplyText = '';
            return;
        }

        ctx.newProjectComment = '';
    }

    function buildProjectFileUploadRequest(file, projectId) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('project_id', projectId);

        const token = getToken();
        const url = `api/index.php?endpoint=files&_t=${Date.now()}&token=${encodeURIComponent(token || '')}`;

        return {
            url,
            requestOptions: {
                method: 'POST',
                headers: token ? { 'Authorization': `Bearer ${token}` } : {},
                body: formData
            }
        };
    }

    return {
        async loadProjects(ctx) {
            try {
                const data = await apiGetProjects();
                if (data.success) ctx.projects = data.data;
            } catch (error) {
                console.error('Ошибка загрузки проектов:', error);
            }
        },

        getFilteredProjects(ctx) {
            let filtered = ctx.projects || [];

            if (ctx.projectFilters?.department_id) {
                filtered = filtered.filter(project => project.department_id == ctx.projectFilters.department_id);
            }

            return filtered;
        },

        initProjectsViewPersistence(ctx) {
            ctx.$watch('projectsView', (newView) => {
                localStorage.setItem(getProjectsViewStorageKey(), newView);
            });

            const savedProjectsView = localStorage.getItem(getProjectsViewStorageKey());
            if (savedProjectsView && ['cards', 'list'].includes(savedProjectsView)) {
                ctx.projectsView = savedProjectsView;
            }
        },

        openProjectModal(ctx, project = null) {
            ctx.editingProject = project;
            resetProjectModalUiState(ctx);

            if (project) {
                ctx.projectForm = getProjectFormFromProject(project);

                ctx.loadProjectTasks(project.id);
                ctx.loadProjectFiles(project.id);
                ctx.loadProjectComments(project.id);
                ctx.loadProjectHistory(project.id);
            } else {
                ctx.projectForm = getProjectFormDefaults();
                resetProjectDetailState(ctx);
            }

            ctx.projectModalOpen = true;
        },

        closeProjectModal(ctx) {
            ctx.projectModalOpen = false;
            ctx.editingProject = null;
            ctx.projectForm = getProjectFormDefaults();
            resetProjectDetailState(ctx);
            resetProjectModalUiState(ctx);
        },

        validateProjectForm(ctx) {
            if (!ctx.projectForm?.name?.trim()) {
                ctx.showToast?.('Укажите название проекта', 'error');
                return false;
            }

            return true;
        },

        buildProjectPayload(ctx) {
            const projectData = {
                ...ctx.projectForm,
                name: ctx.projectForm?.name?.trim() || '',
                description: ctx.projectForm?.description || '',
                department_id: ctx.projectForm?.department_ids?.[0] || null
            };

            delete projectData.department_ids;
            return projectData;
        },

        async loadProjectTasks(ctx, projectId) {
            return loadProjectCollection(ctx, () => apiGetProjectTasks(projectId), 'projectTasks', 'Ошибка загрузки задач проекта:');
        },

        async loadProjectFiles(ctx, projectId) {
            return loadProjectCollection(ctx, () => apiGetProjectFiles(projectId), 'projectFiles', 'Ошибка загрузки файлов проекта:');
        },

        async loadProjectHistory(ctx, projectId) {
            return loadProjectCollection(ctx, () => apiGetProjectHistory(projectId), 'projectHistory', 'Ошибка загрузки истории проекта:');
        },

        async loadProjectComments(ctx, projectId) {
            return loadProjectCollection(ctx, () => apiGet(`project-comments?project_id=${projectId}`), 'projectComments', 'Ошибка загрузки комментариев проекта:');
        },

        async addProjectComment(ctx, parentId = null) {
            if (!ctx.editingProject?.id) return;

            const text = getProjectCommentInput(ctx, parentId);
            if (!text?.trim()) return;

            try {
                const data = await apiPost('project-comments', {
                    project_id: ctx.editingProject.id,
                    message: text.trim(),
                    parent_id: parentId || null
                });

                if (data.success) {
                    ctx.showToast('Комментарий добавлен', 'success');
                    resetProjectCommentInput(ctx, parentId);
                    await ctx.loadProjectComments(ctx.editingProject.id);
                    await ctx.loadProjectHistory(ctx.editingProject.id);
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось добавить комментарий'), 'error');
                }
            } catch (error) {
                console.error('Ошибка добавления комментария:', error);
                ctx.showToast('Ошибка: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        projectReplyTo(ctx, comment) {
            if (!comment?.id) return;
            ctx.projectReplyId = comment.id;
            ctx.projectReplyText = '';
        },

        async deleteProjectComment(ctx, commentId) {
            if (!commentId) return;

            try {
                const data = await apiDelete(`project-comments/${commentId}`);
                if (data.success) {
                    ctx.showToast('Комментарий удалён', 'success');
                    if (ctx.editingProject?.id) {
                        await ctx.loadProjectComments(ctx.editingProject.id);
                    }
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось удалить комментарий'), 'error');
                }
            } catch (error) {
                console.error('Ошибка удаления комментария:', error);
                ctx.showToast('Ошибка: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async uploadFileToProject(ctx, file) {
            if (!file || !ctx.editingProject?.id) return;

            try {
                const { url, requestOptions } = buildProjectFileUploadRequest(file, ctx.editingProject.id);
                const data = typeof window.fetchJsonOrThrow === 'function'
                    ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось загрузить файл')
                    : await (await fetch(url, requestOptions)).json();

                if (data.success) {
                    ctx.showToast('Файл загружен', 'success');
                    await ctx.loadProjectFiles(ctx.editingProject.id);
                    await ctx.loadProjectHistory(ctx.editingProject.id);
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось загрузить файл'), 'error');
                }
            } catch (error) {
                console.error('Ошибка загрузки файла:', error);
                ctx.showToast('Ошибка: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async deleteProjectFile(ctx, fileId) {
            if (!fileId) return;

            try {
                const data = await apiDelete(`files/${fileId}`);
                if (data.success) {
                    ctx.showToast('Файл удалён', 'success');
                    if (ctx.editingProject?.id) {
                        await ctx.loadProjectFiles(ctx.editingProject.id);
                    }
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось удалить файл'), 'error');
                }
            } catch (error) {
                console.error('Ошибка удаления файла:', error);
                ctx.showToast('Ошибка: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        showProjectContextMenu(ctx, event) {
            event.preventDefault();
            event.stopPropagation();
            ctx.selectedProject = null;
            ctx.projectContextMenuX = event.clientX;
            ctx.projectContextMenuY = event.clientY;
            ctx.projectContextMenuOpen = true;
        },

        showProjectCardContextMenu(ctx, event, project) {
            event.preventDefault();
            event.stopPropagation();
            ctx.selectedProject = project;
            ctx.projectContextMenuX = event.clientX;
            ctx.projectContextMenuY = event.clientY;
            ctx.projectContextMenuOpen = true;
        },

        closeProjectContextMenu(ctx) {
            ctx.projectContextMenuOpen = false;
            ctx.selectedProject = null;
        },

        deleteProjectFromMenu(ctx) {
            if (ctx.selectedProject?.id) {
                ctx.editingProject = ctx.selectedProject;
                ctx.deleteProject();
            }

            this.closeProjectContextMenu(ctx);
        },

        getFilteredProjectFiles(ctx) {
            if (!ctx.projectFiles) return [];
            if (ctx.projectFilesTab === 'all') return ctx.projectFiles;
            if (ctx.projectFilesTab === 'project') {
                return ctx.projectFiles.filter(f => !f.task_id);
            }
            if (ctx.projectFilesTab === 'tasks') {
                return ctx.projectFiles.filter(f => f.task_id);
            }
            if (ctx.projectFilesTab === 'images') {
                return ctx.projectFiles.filter(f => IMAGE_EXTENSIONS.includes(getFileExtension(f)));
            }
            if (ctx.projectFilesTab === 'documents') {
                return ctx.projectFiles.filter(f => DOCUMENT_EXTENSIONS.includes(getFileExtension(f)));
            }
            if (ctx.projectFilesTab === 'archives') {
                return ctx.projectFiles.filter(f => ARCHIVE_EXTENSIONS.includes(getFileExtension(f)));
            }
            return ctx.projectFiles;
        }
    };
})();
