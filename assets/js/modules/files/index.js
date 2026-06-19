window.TaskFlowFiles = (function () {
    function getAuthFileUrl(fileId, action) {
        const token = getToken();
        return `api/index.php?endpoint=files/${fileId}/${action}&_t=${Date.now()}${token ? `&token=${encodeURIComponent(token)}` : ''}`;
    }

    function getFileExtension(filename) {
        return String(filename || '').split('.').pop().toLowerCase();
    }

    function getSelectedFileIds(ctx) {
        return (ctx.selectedFileIds || []).map(x => parseInt(x, 10)).filter(Boolean);
    }

    async function runFileMove(ctx, ids, folderId, options = {}) {
        const {
            clearContextFile = false,
            closeMoveModal = false,
            requestLabel = 'Перемещение файлов'
        } = options;

        try {
            ctx.fileActionBusy = true;
            ctx.fileActionLabel = requestLabel;
            await apiMoveFiles(ids, folderId);
            ctx.clearFileSelection();
            if (clearContextFile) ctx.contextFile = null;
            if (closeMoveModal) ctx.moveToFolderModalOpen = false;
            await ctx.refreshFilesView({ silent: true });
            ctx.showToast('Файлы перемещены', 'success');
        } catch (e) {
            console.error('Ошибка перемещения файлов:', e);
            ctx.showToast('Не удалось переместить файлы', 'error');
        } finally {
            ctx.fileActionBusy = false;
            ctx.fileActionLabel = '';
        }
    }

    return {
        async loadFileTree(ctx) {
            try {
                const data = await apiGetFileTree();
                if (data.success) {
                    ctx.fileTree = data.data || [];
                } else {
                    ctx.filesError = data.error || 'Не удалось загрузить дерево папок';
                }
            } catch (error) {
                console.error('Ошибка загрузки дерева папок:', error);
                ctx.filesError = error?.message || 'Не удалось загрузить дерево папок';
            }
        },

        async refreshView(ctx, options = {}) {
            const { preserveSelection = false, silent = false } = options;
            if (!silent) ctx.filesLoading = true;
            ctx.filesError = '';

            try {
                await Promise.all([
                    ctx.loadFiles(),
                    ctx.loadFileTree(),
                    ctx.buildFilesBreadcrumb()
                ]);

                if (!preserveSelection) {
                    ctx.clearFileSelection();
                }
            } catch (error) {
                console.error('Ошибка обновления файлового раздела:', error);
                ctx.filesError = error?.message || 'Не удалось обновить файловый раздел';
            } finally {
                if (!silent) ctx.filesLoading = false;
            }
        },

        renderFolderTree(ctx, folder, level = 0) {
            const indent = level * 20;
            const hasChildren = folder.children_count > 0 || (folder.children && folder.children.length > 0);
            const isSelected = ctx.currentFolder === folder.id;

            return `
                <div class="folder-tree-item" style="padding-left: ${indent}px;"
                     @dragover.prevent="onFolderDragOver($event)"
                     @drop="onFolderDrop($event, ${folder.id})"
                     @dragleave="onFolderDragLeave($event)">
                    <div @click="navigateToFolder(${folder.id})" 
                         @contextmenu.prevent.stop="openFilesContextMenu($event, {type:'folder', item: folder})"
                         class="flex items-center gap-2 px-2 py-2 rounded-lg cursor-pointer crm-hover-surface transition-all ${isSelected ? 'liquid-glass-pro' : ''}"
                         style="font-size: 13px;">
                        <svg class="w-4 h-4 crm-text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                        </svg>
                        <span class="flex-1 truncate" style="color: var(--lg-text-primary)">${ctx.escapeHtml(folder.name)}</span>
                        <span class="text-xs crm-text-tertiary">${folder.files_count || 0}</span>
                    </div>
                    ${hasChildren && folder.children ? `
                        <div class="folder-children">
                            ${folder.children.map(child => this.renderFolderTree(ctx, child, level + 1)).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        },

        escapeHtml(_ctx, text) {
            return window.TaskFlowSharedFormatters?.escapeHtml?.(text) || '';
        },

        async loadFiles(ctx) {
            try {
                const [filesData, foldersData] = await Promise.all([
                    apiGetFiles({ folder_id: ctx.currentFolder ?? '' }),
                    apiGetFileFolders(ctx.currentFolder)
                ]);
                if (filesData.success) {
                    ctx.files = filesData.data || [];
                } else {
                    ctx.files = [];
                    ctx.filesError = filesData.error || 'Не удалось загрузить список файлов';
                }
                if (foldersData.success) {
                    ctx.folders = foldersData.data || [];
                } else {
                    ctx.folders = [];
                    ctx.filesError = foldersData.error || 'Не удалось загрузить список папок';
                }
            } catch (error) {
                console.error('Ошибка загрузки файлов:', error);
                ctx.files = [];
                ctx.folders = [];
                ctx.filesError = error?.message || 'Не удалось загрузить файлы';
            }
        },

        async uploadFile(ctx, file) {
            if (!file) return;

            try {
                ctx.fileActionBusy = true;
                ctx.fileActionLabel = `Загрузка: ${file.name || 'файл'}`;
                const formData = new FormData();
                formData.append('file', file);
                if (ctx.currentFolder != null) {
                    formData.append('folder_id', ctx.currentFolder);
                }

                const token = getToken();
                const url = `api/index.php?endpoint=files&_t=${Date.now()}${token ? `&token=${encodeURIComponent(token)}` : ''}`;

                const requestOptions = {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${token}`
                    },
                    body: formData
                };

                const data = typeof window.fetchJsonOrThrow === 'function'
                    ? await window.fetchJsonOrThrow(url, requestOptions, 'Не удалось загрузить файл')
                    : await (await fetch(url, requestOptions)).json();

                if (data.success) {
                    ctx.showToast('Файл загружен', 'success');
                    await ctx.refreshFilesView({ silent: true });
                } else {
                    ctx.showToast('Ошибка: ' + (data.error || 'Не удалось загрузить файл'), 'error');
                }
            } catch (error) {
                console.error('Ошибка загрузки файла:', error);
                ctx.showToast('Ошибка загрузки: ' + (error.message || 'Неизвестная ошибка'), 'error');
            } finally {
                ctx.fileActionBusy = false;
                ctx.fileActionLabel = '';
            }
        },

        async navigateToFolder(ctx, folderId) {
            ctx.currentFolder = folderId;
            await ctx.refreshFilesView({ silent: true });
        },

        async buildBreadcrumb(ctx) {
            const crumbs = [];
            let currentId = ctx.currentFolder;
            if (currentId == null) {
                ctx.breadcrumb = [];
                return;
            }

            const all = await apiGetFileFolders(null);
            if (!all.success) {
                ctx.breadcrumb = [];
                return;
            }

            const map = new Map((all.data || []).map(f => [String(f.id), f]));
            while (currentId != null) {
                const folder = map.get(String(currentId));
                if (!folder) break;
                crumbs.unshift({ id: folder.id, name: folder.name });
                currentId = folder.parent_id;
            }
            ctx.breadcrumb = crumbs;
        },

        createFolder(ctx) {
            ctx.newFolderName = '';
            ctx.createFolderModalOpen = true;
            ctx.$nextTick(() => {
                ctx.$refs?.createFolderNameInput?.focus();
            });
        },

        async confirmCreateFolder(ctx) {
            if (!ctx.newFolderName?.trim()) {
                ctx.showToast('Введите название папки', 'error');
                return;
            }
            const res = await apiCreateFileFolder(ctx.newFolderName.trim(), ctx.currentFolder);
            if (res.success) {
                await ctx.refreshFilesView({ silent: true });
                ctx.createFolderModalOpen = false;
                ctx.showToast('Папка создана', 'success');
            } else {
                ctx.showToast('Ошибка: ' + (res.error || 'Не удалось создать папку'), 'error');
            }
        },

        openFileInNewTab(_ctx, file) {
            if (!file?.id) return;
            window.open(getAuthFileUrl(file.id, 'preview'), '_blank');
        },

        isImage(_ctx, filename) {
            if (!filename) return false;
            const ext = getFileExtension(filename);
            return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext);
        },

        previewFile(ctx, file) {
            if (!file || (!file.id && !file.file_id)) {
                console.warn('previewFile: invalid file object', file);
                return;
            }

            const fileId = file.id || file.file_id;
            const filename = file.original_name || file.name || file.file_name || 'file';
            const fileUrl = getAuthFileUrl(fileId, 'preview');
            const ext = getFileExtension(filename);

            let type = 'document';
            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext)) {
                type = 'image';
            } else if (['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv'].includes(ext)) {
                type = 'video';
            } else if (['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac'].includes(ext)) {
                type = 'audio';
            } else if (ext === 'pdf') {
                type = 'pdf';
            }

            ctx.filePreviewSrc = fileUrl;
            ctx.filePreviewName = filename;
            ctx.filePreviewType = type;
            ctx.filePreviewOpen = true;
        },

        downloadFile(_ctx, file) {
            if (!file?.id) return;
            const a = document.createElement('a');
            a.href = getAuthFileUrl(file.id, 'download');
            a.download = file.original_name || 'file';
            a.target = '_blank';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        },

        openContextMenu(ctx, event, payload) {
            event.preventDefault();
            event.stopPropagation();
            ctx.filesContextMenuX = event.clientX;
            ctx.filesContextMenuY = event.clientY;
            ctx.contextFile = payload?.type === 'file' ? payload.item : null;
            ctx.contextFolder = payload?.type === 'folder' ? payload.item : null;
            ctx.filesContextMenuOpen = true;
        },

        toggleFileSelection(ctx, file, event = null) {
            if (!file?.id) return;
            const id = String(file.id);
            const isSelected = ctx.selectedFileIds.includes(id);

            if (event && (event.ctrlKey || event.metaKey)) {
                if (isSelected) {
                    ctx.selectedFileIds = ctx.selectedFileIds.filter(x => x !== id);
                } else {
                    ctx.selectedFileIds = [...ctx.selectedFileIds, id];
                }
                return;
            }

            if (event && event.shiftKey && ctx.selectedFileIds.length) {
                const list = ctx.filteredFilesUi || [];
                const ids = list.map(x => String(x.id));
                const last = ctx.selectedFileIds[ctx.selectedFileIds.length - 1];
                const a = ids.indexOf(String(last));
                const b = ids.indexOf(id);
                if (a !== -1 && b !== -1) {
                    const [start, end] = a < b ? [a, b] : [b, a];
                    const slice = ids.slice(start, end + 1);
                    const set = new Set([...ctx.selectedFileIds, ...slice]);
                    ctx.selectedFileIds = Array.from(set);
                    return;
                }
            }

            ctx.selectedFileIds = [id];
        },

        onFileDragStart(ctx, event, file) {
            if (!file?.id) return;
            ctx.toggleFileSelection(file, event);
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', file.id);
        },

        clearFileSelection(ctx) {
            ctx.selectedFileIds = [];
        },

        async moveSelectedFilesToFolder(ctx, folderId) {
            const ids = getSelectedFileIds(ctx);
            if (!ids.length) return;

            try {
                ctx.fileActionBusy = true;
                ctx.fileActionLabel = 'Перемещение файлов';
                await apiPatch('files/move', { file_ids: ids, folder_id: folderId });
                ctx.clearFileSelection();
                await ctx.refreshFilesView({ silent: true });
                ctx.showToast('Файлы перемещены', 'success');
            } catch (e) {
                ctx.showToast('Не удалось переместить файлы', 'error');
            } finally {
                ctx.fileActionBusy = false;
                ctx.fileActionLabel = '';
            }
        },

        onFolderDragOver(_ctx, event) {
            event.preventDefault();
            const el = event.currentTarget;
            if (el) el.classList.add('drag-over');
        },

        onFolderDragLeave(_ctx, event) {
            const el = event.currentTarget;
            if (el) el.classList.remove('drag-over');
        },

        async onFolderDrop(ctx, event, folderId) {
            event.preventDefault();
            const el = event.currentTarget;
            if (el) el.classList.remove('drag-over');

            const ids = getSelectedFileIds(ctx);
            if (!ids.length) return;
            await runFileMove(ctx, ids, folderId);
        },

        showMoveToFolderModal(ctx) {
            ctx.moveToFolderModalOpen = true;
        },

        renderMoveToFolderTree(ctx, folder, level = 0) {
            const indent = level * 20;
            const hasChildren = folder.children_count > 0 || (folder.children && folder.children.length > 0);

            return `
                <div style="padding-left: ${indent}px;">
                    <button @click="moveFilesToFolder(${folder.id})"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:liquid-glass-pro transition-all"
                            style="font-size: 13px;">
                        <svg class="w-4 h-4 crm-text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                        </svg>
                        <span class="flex-1 truncate" style="color: var(--lg-text-primary)">${ctx.escapeHtml(folder.name)}</span>
                        <span class="text-xs crm-text-tertiary">${folder.files_count || 0}</span>
                    </button>
                    ${hasChildren && folder.children ? `
                        <div class="folder-children">
                            ${folder.children.map(child => this.renderMoveToFolderTree(ctx, child, level + 1)).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        },

        async moveFilesToFolder(ctx, folderId) {
            const ids = getSelectedFileIds(ctx);
            if (!ids.length && ctx.contextFile) {
                ids.push(ctx.contextFile.id);
            }
            if (!ids.length) {
                ctx.showToast('Нет файлов для перемещения', 'error');
                return;
            }

            await runFileMove(ctx, ids, folderId, {
                clearContextFile: true,
                closeMoveModal: true
            });
        },

        closeContextMenu(ctx) {
            ctx.filesContextMenuOpen = false;
            ctx.contextFile = null;
            ctx.contextFolder = null;
        },

        async renameContextFolder(ctx) {
            const folder = ctx.contextFolder;
            if (!folder?.id) return;
            const name = prompt('Новое имя папки', folder.name || '');
            if (!name) {
                ctx.closeFilesContextMenu();
                return;
            }

            const res = await apiRenameFileFolder(folder.id, name);
            if (res?.success) {
                await ctx.refreshFilesView({ silent: true });
                ctx.showToast('Папка переименована', 'success');
            } else {
                ctx.showToast('Ошибка: ' + (res?.error || 'Не удалось переименовать папку'), 'error');
            }
            ctx.closeFilesContextMenu();
        },

        async deleteContextFolder(ctx) {
            const folder = ctx.contextFolder;
            if (!folder?.id) return;
            if (!confirm('Удалить папку?')) {
                ctx.closeFilesContextMenu();
                return;
            }

            const res = await apiDeleteFileFolder(folder.id);
            if (!res.success) {
                ctx.showToast('Ошибка: ' + (res.error || 'Не удалось удалить папку'), 'error');
            } else {
                await ctx.refreshFilesView({ silent: true });
                ctx.showToast('Папка удалена', 'success');
            }
            ctx.closeFilesContextMenu();
        },

        async deleteContextFile(ctx) {
            const file = ctx.contextFile;
            if (!file?.id) return;
            if (!confirm('Удалить файл?')) {
                ctx.closeFilesContextMenu();
                return;
            }

            const res = await apiDeleteFile(file.id);
            if (!res.success) {
                ctx.showToast('Ошибка: ' + (res.error || 'Не удалось удалить файл'), 'error');
            } else {
                await ctx.refreshFilesView({ silent: true });
                ctx.showToast('Файл удалён', 'success');
            }
            ctx.closeFilesContextMenu();
        }
    };
})();
