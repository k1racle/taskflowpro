window.TaskFlowTopbarSearch = (function () {
    const SECTION_TITLES = {
        tasks: 'Задачи',
        projects: 'Проекты',
        files: 'Файлы',
        users: 'Сотрудники',
        departments: 'Отделы',
        knowledge: 'База знаний',
        clients: 'Клиенты',
        deals: 'Сделки'
    };

    const SECTION_ITEM_TITLES = {
        tasks: 'Задача',
        projects: 'Проект',
        files: 'Файл',
        users: 'Сотрудник',
        departments: 'Отдел',
        knowledge: 'Статья',
        clients: 'Клиент',
        deals: 'Сделка'
    };

    return {
        async perform(ctx) {
            if (!ctx.globalSearch || ctx.globalSearch.length < 2) {
                ctx.lastSearchResults = null;
                return;
            }

            try {
                const data = await apiGlobalSearch(ctx.globalSearch);
                if (data.success) {
                    ctx.lastSearchResults = data.data;
                }
            } catch (error) {
                console.error('Ошибка поиска:', error);
            }
        },

        open(ctx) {
            if (typeof ctx.closeAllPanels === 'function') ctx.closeAllPanels();
            ctx.sidebarOpen = false;
            ctx.topbarSearchOpen = true;
            try {
                if (typeof window !== 'undefined' && window.innerWidth < 1024) {
                    document.body.style.overflow = 'hidden';
                }
            } catch (_) {}
            ctx.$nextTick(() => {
                try {
                    const focusSearchInput = () => {
                        const isMobile = typeof window !== 'undefined' && window.innerWidth < 1024;
                        const el = document.getElementById(isMobile ? 'topbar-search-input-mobile' : 'topbar-search-input')
                            || document.getElementById('topbar-search-input-mobile')
                            || document.getElementById('topbar-search-input');
                        if (!el) return false;
                        el.focus({ preventScroll: true });
                        if (typeof el.select === 'function' && el.value) el.select();
                        return true;
                    };

                    if (!focusSearchInput()) {
                        requestAnimationFrame(() => {
                            focusSearchInput();
                        });
                    }
                } catch (_) {}
            });
        },

        close(ctx) {
            ctx.topbarSearchOpen = false;
            ctx.globalSearch = '';
            ctx.lastSearchResults = null;
            try {
                document.body.style.overflow = '';
                if (document.activeElement && typeof document.activeElement.blur === 'function') {
                    document.activeElement.blur();
                }
            } catch (_) {}
        },

        getSections(ctx) {
            return Object.keys(ctx.lastSearchResults || {});
        },

        hasResults(ctx) {
            return this.getSections(ctx).length > 0;
        },

        getSectionTitle(section) {
            return SECTION_TITLES[section] || section;
        },

        getSectionItemTitle(section) {
            return SECTION_ITEM_TITLES[section] || section;
        },

        getItemKey(section, item) {
            return section + '-' + (item?.id || item?.file_id || item?.task_id || item?.project_id || JSON.stringify(item));
        },

        getItemTitle(item) {
            return item?.title || item?.name || item?.original_name || item?.full_name || item?.login || ('ID: ' + (item?.id || ''));
        },

        getItemDescription(item) {
            return item?.description || item?.content || item?.project_name || item?.department_name || item?.task_title || item?.filename || item?.email || '';
        },

        async navigate(ctx, section, item) {
            if (!item) return;

            if (section === 'tasks') {
                ctx.currentView = 'tasks';
                const id = item.id || item.task_id;
                const task = (ctx.tasks || []).find((t) => String(t.id) === String(id));
                if (task) ctx.openTaskModal(task);
                this.close(ctx);
                return;
            }

            if (section === 'projects') {
                ctx.currentView = 'projects';
                const id = item.id || item.project_id;
                const project = (ctx.projects || []).find((p) => String(p.id) === String(id));
                if (project) ctx.openProjectModal(project);
                this.close(ctx);
                return;
            }

            if (section === 'files') {
                ctx.currentView = 'files';
                this.close(ctx);
                return;
            }

            if (section === 'departments') {
                ctx.currentView = 'departments';
                const id = item.id || item.department_id;
                const dept = (ctx.departments || []).find((d) => String(d.id) === String(id));
                if (dept) ctx.openDepartmentModal(dept);
                this.close(ctx);
                return;
            }

            if (section === 'knowledge') {
                ctx.currentView = 'knowledge';
                const id = item.id;
                const article = (ctx.knowledgeArticles || []).find((a) => String(a.id) === String(id));
                if (article) ctx.openKnowledgeModal(article);
                this.close(ctx);
                return;
            }

            if (section === 'users') {
                ctx.currentView = 'users';
                const id = item.id;
                const user = (ctx.users || []).find((u) => String(u.id) === String(id));
                if (user) await ctx.openUserModal(user);
                this.close(ctx);
                return;
            }

            if (section === 'clients') {
                ctx.currentView = 'crm-clients';
                ctx.$nextTick(async () => {
                    try {
                        ctx.ensureCrmLoaded();
                        await ctx.crmLoadClients();
                        await ctx.crmOpenClientDrawer(item.id, item);
                    } catch (_) {}
                });
                this.close(ctx);
                return;
            }

            if (section === 'deals') {
                ctx.currentView = 'crm-funnels';
                ctx.$nextTick(async () => {
                    try {
                        ctx.ensureCrmLoaded();
                        await ctx.crmLoadFunnels();
                        const deal = (ctx.crmDeals || []).find((d) => String(d.id) === String(item.id));
                        if (deal) ctx.crmOpenDealModal(deal);
                    } catch (_) {}
                });
                this.close(ctx);
            }
        }
    };
})();
