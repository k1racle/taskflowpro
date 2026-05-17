window.TaskFlowDepartments = (function () {
    function getDepartmentFormDefaults() {
        return {
            name: '',
            description: '',
            icon: 'building'
        };
    }

    function resetDepartmentDetailState(ctx) {
        ctx.departmentEmployees = [];
        ctx.departmentProjects = [];
        ctx.departmentTasks = [];
    }

    function resetDepartmentModalUiState(ctx) {
        ctx.departmentModalTab = 'department';
    }

    return {
        async loadDepartments(ctx) {
            try {
                const data = await apiGetDepartments();
                if (data.success) {
                    ctx.departments = Array.isArray(data.data) ? data.data : [];
                }
            } catch (error) {
                console.error('Ошибка загрузки отделов:', error);
            }
        },

        async saveDepartment(ctx) {
            try {
                if (ctx.editingDepartment?.id) {
                    await apiUpdateDepartment(ctx.editingDepartment.id, ctx.departmentForm);
                    ctx.showToast('Отдел обновлён', 'success');
                } else {
                    await apiCreateDepartment(ctx.departmentForm);
                    ctx.showToast('Отдел создан', 'success');
                }

                await ctx.loadDepartments();
                ctx.closeDepartmentModal();
            } catch (error) {
                console.error('Ошибка сохранения отдела:', error);
                ctx.showToast('Ошибка сохранения отдела', 'error');
            }
        },

        openDepartmentModal(ctx, dept = null) {
            ctx.editingDepartment = dept;
            resetDepartmentModalUiState(ctx);

            if (dept) {
                ctx.departmentForm = {
                    name: dept.name,
                    description: dept.description || '',
                    icon: dept.icon || 'building'
                };

                ctx.loadDepartmentEmployees(dept.id);
                ctx.loadDepartmentProjects(dept.id);
                ctx.loadDepartmentTasks(dept.id);
            } else {
                ctx.departmentForm = getDepartmentFormDefaults();
                resetDepartmentDetailState(ctx);
            }

            ctx.departmentModalOpen = true;
        },

        closeDepartmentModal(ctx) {
            ctx.departmentModalOpen = false;
            ctx.editingDepartment = null;
            ctx.departmentForm = getDepartmentFormDefaults();
            resetDepartmentDetailState(ctx);
            resetDepartmentModalUiState(ctx);
        },

        async loadDepartmentEmployees(ctx, deptId) {
            try {
                const data = await apiGet(`departments/${deptId}/employees`);
                if (data.success) {
                    ctx.departmentEmployees = data.data || [];
                } else {
                    ctx.departmentEmployees = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки сотрудников:', error);
                ctx.departmentEmployees = [];
            }
        },

        async loadDepartmentProjects(ctx, deptId) {
            try {
                const data = await apiGet(`departments/${deptId}/projects`);
                if (data.success) {
                    ctx.departmentProjects = data.data || [];
                } else {
                    ctx.departmentProjects = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки проектов:', error);
                ctx.departmentProjects = [];
            }
        },

        async loadDepartmentTasks(ctx, deptId) {
            try {
                const data = await apiGet(`departments/${deptId}/tasks`);
                if (data.success) {
                    ctx.departmentTasks = data.data || [];
                } else {
                    ctx.departmentTasks = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки задач:', error);
                ctx.departmentTasks = [];
            }
        },

        showDepartmentContextMenu(ctx, event, dept) {
            event.preventDefault();
            ctx.selectedDepartment = dept;
            ctx.departmentContextMenuX = event.clientX;
            ctx.departmentContextMenuY = event.clientY;
            ctx.departmentContextMenuOpen = true;
        },

        openDepartmentContextMenu(ctx, event, dept) {
            return this.showDepartmentContextMenu(ctx, event, dept);
        },

        deleteDepartment(ctx) {
            if (!ctx.editingDepartment?.id) return;

            ctx.openConfirm(
                'Удалить отдел?',
                'Вы уверены что хотите удалить этот отдел? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteDepartment(ctx.editingDepartment.id);
                        ctx.showToast('Отдел удалён', 'success');
                        await ctx.loadDepartments();
                        ctx.closeDepartmentModal();
                    } catch (error) {
                        console.error('Ошибка удаления отдела:', error);
                        ctx.showToast('Ошибка: ' + error.message, 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        }
    };
})();
