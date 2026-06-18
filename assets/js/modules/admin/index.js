window.TaskFlowAdmin = (function () {
    const EMPTY_ROLE_PERMS = {
        tasks: { view: false, create: false, edit: false, delete: false },
        projects: { view: false, create: false, edit: false, delete: false },
        departments: { view: false, create: false, edit: false, delete: false },
        files: { view: false, upload: false, edit: false, delete: false },
        knowledge: { view: false, create: false, edit: false, delete: false },
        chat: { view: false, send: false, edit: false, delete: false, forward: false, create_group: false },
        mail: { view: false, send: false, edit: false, delete: false },
        crm: { view: false, create: false, edit: false, delete: false, export: false, stages_manage: false },
        leader: { view: false, shifts_manage: false, export: false },
        users: { view: false, create: false, edit: false, delete: false },
        admin: { full: false }
    };

    function cloneRolePerms(base = EMPTY_ROLE_PERMS) {
        return {
            tasks: { ...base.tasks },
            projects: { ...base.projects },
            departments: { ...base.departments },
            files: { ...base.files },
            knowledge: { ...base.knowledge },
            chat: { ...base.chat },
            mail: { ...base.mail },
            crm: { ...base.crm },
            leader: { ...base.leader },
            users: { ...base.users },
            admin: { ...base.admin }
        };
    }

    function getDefaultRolePermissions(perms) {
        const source = perms && typeof perms === 'object' ? perms : {};
        const base = cloneRolePerms();

        for (const section of Object.keys(base)) {
            if (!source[section] || typeof source[section] !== 'object') continue;
            for (const action of Object.keys(base[section])) {
                if (typeof source[section][action] !== 'undefined') {
                    base[section][action] = !!source[section][action];
                }
            }
        }

        return base;
    }

    function normalizeRolePermissions(role) {
        let perms = {};
        if (role?.permissions) {
            try {
                perms = typeof role.permissions === 'string' ? JSON.parse(role.permissions) : role.permissions;
            } catch (e) {
                console.error('Ошибка парсинга прав:', e);
                perms = {};
            }
        }

        if (Array.isArray(perms)) {
            return makeRolePermissionsFromCodes(perms);
        }

        return getDefaultRolePermissions(perms || {});
    }

    function ensureRootUserInUi(ctx) {
        if (ctx.currentUser?.role !== 'root') return;

        const hasRoot = (ctx.users || []).some(u => u.role === 'root' || u.login === 'root');
        if (hasRoot || !ctx.currentUser) return;

        ctx.users = [
            {
                id: ctx.currentUser.id || 'root',
                login: ctx.currentUser.login || 'root',
                full_name: ctx.currentUser.full_name || 'Root User',
                role: 'root',
                department_id: ctx.currentUser.department_id || null,
                department_name: ctx.currentUser.department_name || '-',
                created_at: null,
                last_login: null
            },
            ...(ctx.users || [])
        ];
    }

    function canManageRoles(ctx) {
        return ctx.currentUser?.role === 'root' || !!ctx.can?.('admin.full');
    }

    function isRootRole(role) {
        return String(role?.name || role?.role || '').toLowerCase() === 'root';
    }

    function parseRolePermissions(role) {
        let permissions = role?.permissions || {};

        if (typeof permissions === 'string') {
            try {
                permissions = JSON.parse(permissions);
            } catch (_) {
                permissions = {}; 
            }
        }

        if (Array.isArray(permissions)) {
            return makeRolePermissionsFromCodes(permissions);
        }

        return permissions || {};
    }

    function mapPermissionCodeToUiKey(permissionCode) {
        const code = String(permissionCode || '').trim();
        if (!code) return null;

        // Backend permission codes may contain an extra segment, while UI toggles use snake_case keys.
        if (code === 'crm.stages.manage') return { section: 'crm', action: 'stages_manage' };
        if (code === 'leader.shifts.manage') return { section: 'leader', action: 'shifts_manage' };

        const parts = code.split('.');
        if (parts.length !== 2) return null;
        return { section: parts[0], action: parts[1] };
    }

    function mapUiKeyToPermissionCode(section, action) {
        const s = String(section || '').trim();
        const a = String(action || '').trim();
        if (!s || !a) return null;

        if (s === 'crm' && a === 'stages_manage') return 'crm.stages.manage';
        if (s === 'leader' && a === 'shifts_manage') return 'leader.shifts.manage';

        return `${s}.${a}`;
    }

    function makeRolePermissionsFromCodes(permissionCodes) {
        const codes = Array.isArray(permissionCodes) ? permissionCodes : [];

        const res = cloneRolePerms();

        for (const codeRaw of codes) {
            const key = mapPermissionCodeToUiKey(codeRaw);
            if (!key) continue;
            if (!res[key.section] || typeof res[key.section][key.action] === 'undefined') continue;
            res[key.section][key.action] = true;
        }

        return res;
    }

    function getScopeForPermissions(permissionCodes) {
        const codes = new Set((permissionCodes || []).map(code => String(code || '').trim()).filter(Boolean));
        if (codes.has('admin.full')) return 'all';
        if (codes.has('users.view') || codes.has('users.edit') || codes.has('users.create') || codes.has('users.delete')) return 'department';
        if (codes.has('crm.export') || codes.has('crm.delete') || codes.has('crm.stages.manage')) return 'department';
        if (codes.has('tasks.view') || codes.has('tasks.create') || codes.has('tasks.edit') || codes.has('tasks.delete')) return 'department';
        return 'view';
    }

    function getDefaultRolePermissionScopes() {
        return {
            tasks: 'department',
            projects: 'department',
            departments: 'department',
            files: 'department',
            knowledge: 'department',
            chat: 'department',
            mail: 'department',
            crm: 'department',
            leader: 'department',
            users: 'department'
        };
    }

    function getRolePermissionPreset(role) {
        const name = String(role?.name || '').toLowerCase();
        if (name === 'root') return 'full';
        if (name === 'leader') return 'leader';
        if (name === 'admin' || name === 'administrator') return 'admin';
        if (name === 'manager') return 'manager';
        if (name === 'employee') return 'employee';
        return 'custom';
    }

    function buildPresetPermissions(preset) {
        const base = cloneRolePerms();

        const scopes = getDefaultRolePermissionScopes();

        switch (preset) {
            case 'full':
                for (const section of Object.keys(base)) {
                    for (const action of Object.keys(base[section] || {})) {
                        base[section][action] = true;
                    }
                    if (section !== 'admin') scopes[section] = 'all';
                }
                scopes.admin = 'all';
                break;
            case 'admin':
                base.tasks.view = base.tasks.create = base.tasks.edit = base.tasks.delete = true;
                base.projects.view = base.projects.create = base.projects.edit = base.projects.delete = true;
                base.departments.view = base.departments.create = base.departments.edit = base.departments.delete = true;
                base.files.view = base.files.upload = base.files.edit = base.files.delete = true;
                base.knowledge.view = base.knowledge.create = base.knowledge.edit = base.knowledge.delete = true;
                base.chat.view = base.chat.send = base.chat.edit = base.chat.delete = base.chat.forward = base.chat.create_group = true;
                base.mail.view = base.mail.send = base.mail.edit = base.mail.delete = true;
                base.crm.view = base.crm.create = base.crm.edit = base.crm.delete = base.crm.export = base.crm.stages_manage = true;
                base.leader.view = base.leader.shifts_manage = base.leader.export = true;
                base.users.view = base.users.create = base.users.edit = base.users.delete = true;
                base.admin.full = true;
                break;
            case 'leader':
                base.tasks.view = base.tasks.create = base.tasks.edit = true;
                base.projects.view = base.projects.create = base.projects.edit = true;
                base.departments.view = true;
                base.files.view = base.files.upload = true;
                base.knowledge.view = base.knowledge.create = base.knowledge.edit = true;
                base.chat.view = base.chat.send = true;
                base.crm.view = base.crm.create = base.crm.edit = base.crm.export = true;
                base.leader.view = base.leader.shifts_manage = true;
                base.users.view = true;
                scopes.tasks = scopes.projects = scopes.departments = scopes.files = scopes.knowledge = scopes.chat = scopes.crm = scopes.leader = scopes.users = 'department';
                break;
            case 'manager':
                base.tasks.view = base.tasks.create = base.tasks.edit = true;
                base.projects.view = base.projects.create = base.projects.edit = true;
                base.files.view = base.files.upload = true;
                base.knowledge.view = base.knowledge.create = true;
                base.chat.view = base.chat.send = true;
                base.crm.view = base.crm.create = base.crm.edit = true;
                base.users.view = true;
                break;
            case 'employee':
                base.tasks.view = base.tasks.create = true;
                base.projects.view = true;
                base.files.view = base.files.upload = true;
                base.knowledge.view = true;
                base.chat.view = base.chat.send = true;
                base.crm.view = true;
                break;
        }

        return { permissions: base, scopes };
    }

    function normalizePermissionScopes(scopes) {
        return { ...getDefaultRolePermissionScopes(), ...(scopes || {}) };
    }

    function applySettingsForm(ctx) {
        if (!ctx.currentUser?.id) return;

        const referralSecretConfigured = String(ctx.settings?.referral_shared_secret_configured || '') === '1';
        const referralSecretSource = String(ctx.settings?.referral_shared_secret_source || 'none');

        ctx.settingsForm = {
            full_name: ctx.currentUser.full_name || '',
            phone: ctx.currentUser.phone || '',
            department_id: ctx.currentUser.department_id || '',
            bio: ctx.currentUser.bio || '',
            avatar: ctx.currentUser.avatar || '',
            birthday: ctx.currentUser.birthday || '',
            weather_city: ctx.currentUser.weather_city || '',
            company_name: ctx.settings?.company_name || 'TaskFlow Pro',
            app_name: ctx.settings?.app_name || 'TaskFlow',
            logo: ctx.settings?.logo || '',
            referral_woocommerce_base_url: ctx.settings?.referral_woocommerce_base_url || '',
            referral_shared_secret: '',
            woocommerce_api_consumer_key: ctx.settings?.woocommerce_api_consumer_key || '',
            woocommerce_api_consumer_secret: '',

            prostiezvonki_user: '',
            
            prostiezvonki_enabled: String(ctx.settings?.prostiezvonki_enabled || '') === '1',
            prostiezvonki_api_key: '',
            prostiezvonki_webhook_secret: ''
        };

        ctx.omniForm = {
            app_public_base_url: String(ctx.settings?.omni_app_public_base_url || ''),
            tg_enabled: String(ctx.settings?.omni_tg_enabled || '') === '1',
            tg_bot_token: '',
            tg_webhook_secret: '',
            max_enabled: String(ctx.settings?.omni_max_enabled || '') === '1',
            max_bot_token: '',
            max_webhook_secret: ''
        };

        ctx.bookingBotForm = {
            enabled: String(ctx.settings?.booking_bot_telegram_enabled || '') === '1',
            token: '',
            welcome_text: String(ctx.settings?.booking_bot_welcome_text || 'Здравствуйте! Я бот для записи на услуги. Напишите /book чтобы начать.')
        };

        ctx.referralIntegration = {
            orderWebhookUrl: buildReferralEndpointUrl('referrals/webhook/woocommerce'),
            visitEndpointUrl: buildReferralEndpointUrl('referrals/visit'),
            sharedSecretConfigured: referralSecretConfigured,
            sharedSecretSource: referralSecretSource
        };

        ctx.omniIntegration = {
            tgTokenConfigured: String(ctx.settings?.omni_tg_bot_token_configured || '') === '1',
            tgSecretConfigured: String(ctx.settings?.omni_tg_webhook_secret_configured || '') === '1',
            maxTokenConfigured: String(ctx.settings?.omni_max_bot_token_configured || '') === '1',
            maxSecretConfigured: String(ctx.settings?.omni_max_webhook_secret_configured || '') === '1',
            bookingBotTokenConfigured: String(ctx.settings?.booking_bot_telegram_token_configured || '') === '1'
        };

        const defaultIceServersJson = '[{"urls":"stun:stun.l.google.com:19302"}]';
        ctx.webrtcForm = {
            ice_servers_json: String(ctx.settings?.webrtc_ice_servers_json || defaultIceServersJson)
        };

        // Parsed iceServers cache for chat calls.
        try {
            const parsed = JSON.parse(ctx.webrtcForm.ice_servers_json);
            ctx.webrtcIceServers = Array.isArray(parsed) ? parsed : [{ urls: 'stun:stun.l.google.com:19302' }];
        } catch (_e) {
            ctx.webrtcIceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
        }
    }

    function buildReferralEndpointUrl(endpoint) {
        try {
            return new URL(`api/index.php?endpoint=${endpoint}`, window.location.href).href;
        } catch (_error) {
            return `api/index.php?endpoint=${endpoint}`;
        }
    }

    function getReferralSecretSourceLabel(source) {
        if (source === 'settings') return 'CRM settings';
        if (source === 'legacy') return 'legacy config/env';
        return 'не настроен';
    }

    return {
        async loadUsers(ctx) {
            ctx.usersLoading = true;
            ctx.usersError = '';
            try {
                const data = await apiGetUsers();
                if (data.success) {
                    ctx.users = data.data || [];
                } else {
                    ctx.users = [];
                    ctx.usersError = data.error || 'Не удалось загрузить сотрудников';
                }
            } catch (error) {
                console.error('Ошибка загрузки пользователей:', error);
                ctx.users = [];
                ctx.usersError = error?.message || 'Не удалось загрузить сотрудников';
            } finally {
                ctx.usersLoading = false;
            }

            ensureRootUserInUi(ctx);
        },

        getFilteredUsers(ctx) {
            const query = String(ctx.usersSearch || '').trim().toLowerCase();
            const users = Array.isArray(ctx.users) ? ctx.users : [];

            if (!query) {
                return users;
            }

            return users.filter(user => {
                const haystack = [
                    user?.full_name,
                    user?.login,
                    user?.department_name,
                    user?.role
                ]
                    .map(value => String(value || '').toLowerCase())
                    .join(' ');

                return haystack.includes(query);
            });
        },

        async refreshUsersList(ctx) {
            await this.loadUsers(ctx);
        },

        getFilteredRoles(ctx) {
            const query = String(ctx.rolesSearch || '').trim().toLowerCase();
            const roles = Array.isArray(ctx.roles) ? ctx.roles : [];

            if (!query) {
                return roles;
            }

            return roles.filter(role => {
                const name = String(role?.name || '').toLowerCase();
                const description = String(role?.description || '').toLowerCase();
                return name.includes(query) || description.includes(query);
            });
        },

        getRoleEnabledPermissionsCount(_ctx, role) {
            // RBAC source of truth: role.permission_codes from role_permissions table.
            if (Array.isArray(role?.permission_codes)) {
                const uniq = new Set(
                    (role.permission_codes || [])
                        .map(x => String(x || '').trim())
                        .filter(Boolean)
                );
                return uniq.size;
            }

            // Fallback to legacy JSON blob (roles.permissions).
            const permissions = parseRolePermissions(role);
            let count = 0;

            for (const actions of Object.values(permissions || {})) {
                if (!actions || typeof actions !== 'object') continue;
                for (const enabled of Object.values(actions)) {
                    if (enabled === true) count += 1;
                }
            }

            return count;
        },

        async loadRoles(ctx, force = false) {
            if (ctx._rolesLoading) return;

            const now = Date.now();
            if (!force && ctx._rolesLoadedAt && (now - ctx._rolesLoadedAt) < 15000) return;

            ctx._rolesLoading = true;
            ctx.rolesLoading = true;
            ctx.rolesError = '';
            try {
                const canManageRoles = ctx.currentUser?.role === 'root'
                    || ctx.can?.('admin.full');

                const data = canManageRoles && typeof apiGetManageRoles === 'function'
                    ? await apiGetManageRoles()
                    : await apiGetRoles();

                if (data.success) {
                    ctx.roles = Array.isArray(data.data) ? data.data : [];
                } else {
                    ctx.roles = [];
                    ctx.rolesError = data.error || 'Не удалось загрузить роли';
                }
            } catch (error) {
                console.warn('Роли недоступны');
                ctx.roles = [];
                ctx.rolesError = error?.message || 'Не удалось загрузить роли';
            } finally {
                ctx._rolesLoadedAt = Date.now();
                ctx._rolesLoading = false;
                ctx.rolesLoading = false;
            }

            if (!ctx.roles.some(r => (r.name || r.role) === 'root')) {
                ctx.roles.unshift({
                    id: 'root',
                    name: 'root',
                    description: 'Системная роль (полный доступ)',
                    icon: 'shield',
                    is_system: 1,
                    permissions: {},
                    users_count: 1
                });
            }
        },

        async saveUser(ctx) {
            const login = String(ctx.userForm.login || '').trim();
            const password = String(ctx.userForm.password || '');
            const fullName = String(ctx.userForm.full_name || '').trim();
            const role = String(ctx.userForm.role || '').trim();
            const departmentIds = Array.isArray(ctx.userForm.department_ids)
                ? ctx.userForm.department_ids.map(id => String(id || '').trim()).filter(Boolean)
                : [];
            const departmentId = departmentIds.length > 0
                ? departmentIds[0]
                : (ctx.userForm.department_id === '' ? null : ctx.userForm.department_id);
            const selectedRole = (ctx.roles || []).find(r => String(r?.name || '') === role);
            const isPrivilegedRole = Array.isArray(selectedRole?.permission_codes)
                && selectedRole.permission_codes.includes('admin.full');
            const canAssignPrivilegedRoles = ctx.currentUser?.role === 'root' || !!ctx.can?.('admin.full');

            if (!login) {
                ctx.showToast('Укажите логин или email', 'error');
                return;
            }

            if (!role) {
                ctx.showToast('Выберите роль', 'error');
                return;
            }

            if (!ctx.editingUser?.id && password.length < 6) {
                ctx.showToast('Пароль должен быть не менее 6 символов', 'error');
                return;
            }

            ctx.usersSaving = true;
            try {
                const payload = {
                    login,
                    full_name: fullName,
                    department_id: departmentId,
                    department_ids: departmentIds
                };

                if (!ctx.editingUser?.id || canAssignPrivilegedRoles || !isPrivilegedRole) {
                    payload.role = role;
                }

                if (ctx.editingUser?.id) {
                    await apiUpdateUser(ctx.editingUser.id, payload);
                    if (password) {
                        await apiChangeUserPassword(ctx.editingUser.id, password);
                    }
                    ctx.showToast('Пользователь обновлён', 'success');
                } else {
                    await apiCreateUser({
                        ...payload,
                        password
                    });
                    ctx.showToast('Пользователь создан', 'success');
                }

                await ctx.refreshUsersList();
                ctx.closeUserModal();
            } catch (error) {
                console.error('Ошибка сохранения пользователя:', error);
                ctx.showToast(error?.message || 'Ошибка сохранения пользователя', 'error');
            } finally {
                ctx.usersSaving = false;
            }
        },

        canDeleteUser(ctx, user) {
            if (!user || !ctx.currentUser) return false;
            if (String(user.id) === String(ctx.currentUser.id)) return false;
            if ((user.role || '') === 'root' || (user.login || '') === 'root') return false;
            if (String(user.login || '').startsWith('system_')) return false;
            return ctx.currentUser.role === 'root' || ctx.can?.('admin.full');
        },

        async deleteUser(ctx, user = null) {
            const targetUser = user || ctx.editingUser;
            if (!targetUser?.id || !this.canDeleteUser(ctx, targetUser)) {
                ctx.showToast('Удаление этого сотрудника недоступно', 'error');
                return;
            }

            const name = targetUser.full_name || targetUser.login || 'сотрудника';
            const confirmed = window.confirm(`Удалить сотрудника "${name}"? Действие необратимо.`);
            if (!confirmed) return;

            try {
                await apiDeleteUser(targetUser.id);
                ctx.showToast('Сотрудник удалён', 'success');
                await ctx.loadUsers();
                if (ctx.editingUser && String(ctx.editingUser.id) === String(targetUser.id)) {
                    ctx.closeUserModal();
                }
            } catch (error) {
                console.error('Ошибка удаления пользователя:', error);
                ctx.showToast(error?.message || 'Не удалось удалить сотрудника', 'error');
            }
        },

        async openUserModal(ctx, user = null) {
            ctx.editingUser = user;
            if (!ctx._rolesLoadedAt || (Date.now() - ctx._rolesLoadedAt) > 15000) {
                await ctx.loadRoles();
            }

            if (user) {
                const departmentIds = Array.isArray(user.department_ids)
                    ? user.department_ids
                    : String(user.department_ids || '')
                        .split(',')
                        .map(x => String(x || '').trim())
                        .filter(Boolean);
                ctx.userForm = {
                    login: user.login,
                    password: '',
                    full_name: user.full_name || '',
                    role: user.role,
                    department_id: user.department_id || '',
                    department_ids: departmentIds
                };
            } else {
                const canAssignPrivilegedRoles = ctx.currentUser?.role === 'root' || !!ctx.can?.('admin.full');
                const firstRole = (ctx.roles || []).find(r => {
                    if (!r || r.name === 'root') return false;
                    const isPrivileged = Array.isArray(r.permission_codes) && r.permission_codes.includes('admin.full');
                    return canAssignPrivilegedRoles || !isPrivileged;
                });
                ctx.userForm = {
                    login: '',
                    password: '',
                    full_name: '',
                    role: firstRole ? firstRole.name : 'employee',
                    department_id: '',
                    department_ids: []
                };
            }

            ctx.userModalOpen = true;
        },

        closeUserModal(ctx) {
            ctx.userModalOpen = false;
            ctx.editingUser = null;
            ctx.userForm = { login: '', password: '', full_name: '', role: 'employee', department_id: '', department_ids: [] };
        },

        async saveRole(ctx) {
            try {
                if (!canManageRoles(ctx)) {
                    ctx.showToast('Управление ролями недоступно', 'error');
                    return;
                }

                const roleName = String(ctx.roleForm.name || '').trim();
                if (!roleName) {
                    ctx.showToast('Укажите название роли', 'error');
                    return;
                }

                const roleData = {
                    name: roleName,
                    description: String(ctx.roleForm.description || '').trim(),
                    icon: ctx.roleForm.icon || 'shield'
                };

                if (ctx.editingRole?.id) {
                    await apiUpdateRole(ctx.editingRole.id, roleData);
                    ctx.showToast('Роль обновлена', 'success');
                } else {
                    await apiCreateRole(roleData);
                    ctx.showToast('Роль создана', 'success');
                }

                ctx._rolesLoadedAt = 0;
                await ctx.loadRoles(true);
                ctx.closeRoleModal();
            } catch (error) {
                console.error('Ошибка сохранения роли:', error);
                ctx.showToast(error?.message || 'Ошибка сохранения роли', 'error');
            }
        },

        openRoleModal(ctx, role = null) {
            if (!canManageRoles(ctx)) {
                ctx.showToast('Управление ролями недоступно', 'error');
                return;
            }

            ctx.editingRole = role;
            if (role?.is_system && ctx.currentUser?.role !== 'root') {
                ctx.showToast('Системные роли нельзя редактировать', 'error');
                ctx.editingRole = null;
                return;
            }

            if (role) {
                ctx.roleForm = {
                    name: role.name,
                    description: role.description || '',
                    icon: role.icon || 'shield'
                };
            } else {
                ctx.roleForm = { name: '', description: '', icon: 'shield' };
            }

            ctx.roleModalOpen = true;
        },

        closeRoleModal(ctx) {
            ctx.roleModalOpen = false;
            ctx.editingRole = null;
        },

        canEditRole(ctx, role) {
            const isSystem = (role?.is_system == 1 || role?.is_system === '1' || role?.is_system === true);
            if (ctx.currentUser?.role === 'root') return true;
            return canManageRoles(ctx) && !isSystem;
        },

        canDeleteRole(ctx, role) {
            if (!role) return false;
            const isSystem = (role?.is_system == 1 || role?.is_system === '1' || role?.is_system === true);
            if (isSystem && ctx.currentUser?.role !== 'root') return false;
            return canManageRoles(ctx) && !!role.id;
        },

        async refreshRolesList(ctx) {
            ctx._rolesLoadedAt = 0;
            await ctx.loadRoles(true);
        },

        async deleteRole(ctx, role = null) {
            const targetRole = role || ctx.editingRole;
            if (!targetRole?.id || !this.canDeleteRole(ctx, targetRole)) {
                ctx.showToast('Удаление этой роли недоступно', 'error');
                return;
            }

            const roleName = targetRole.name || 'роль';
            const usersCount = Number(targetRole.users_count || 0);
            const confirmText = usersCount > 0
                ? `Удалить роль "${roleName}"? Она назначена ${usersCount} пользователям.`
                : `Удалить роль "${roleName}"? Действие необратимо.`;

            if (!window.confirm(confirmText)) {
                return;
            }

            try {
                await apiDeleteRole(targetRole.id);
                ctx.showToast('Роль удалена', 'success');
                await ctx.refreshRolesList();
                if (ctx.editingRole && String(ctx.editingRole.id) === String(targetRole.id)) {
                    ctx.closeRoleModal();
                    ctx.closeRolePermissionsModal();
                }
            } catch (error) {
                console.error('Ошибка удаления роли:', error);
                ctx.showToast(error?.message || 'Не удалось удалить роль', 'error');
            }
        },

        openRolePermissions(ctx, role) {
            if (!canManageRoles(ctx)) {
                ctx.showToast('Управление правами ролей недоступно', 'error');
                return;
            }

            ctx.editingRole = role;
            if (Array.isArray(role?.permission_codes)) {
                ctx.rolePermissions = makeRolePermissionsFromCodes(role.permission_codes);
            } else {
                ctx.rolePermissions = normalizeRolePermissions(role);
            }
            const baseScopes = normalizePermissionScopes(role?.permission_scopes || {});
            for (const section of Object.keys(ctx.rolePermissions || {})) {
                if (!baseScopes[section]) {
                    baseScopes[section] = getScopeForPermissions(
                        Object.entries(ctx.rolePermissions[section] || {})
                            .filter(([, enabled]) => !!enabled)
                            .map(([action]) => `${section}.${action}`)
                    );
                }
            }
            ctx.rolePermissionScopes = baseScopes;
            ctx.rolePermissionPreset = getRolePermissionPreset(role);
            ctx.rolePermissionsModalOpen = true;
        },

        closeRolePermissionsModal(ctx) {
            ctx.rolePermissionsModalOpen = false;
            ctx.editingRole = null;
        },

        async saveRolePermissions(ctx) {
            try {
                if (!canManageRoles(ctx)) {
                    ctx.showToast('Управление правами ролей недоступно', 'error');
                    return;
                }

                if (ctx.editingRole?.id) {
                    if (isRootRole(ctx.editingRole)) {
                        ctx.showToast('Права root управляются автоматически', 'error');
                        return;
                    }

                    const permissionCodes = [];
                    for (const [section, actions] of Object.entries(ctx.rolePermissions || {})) {
                        for (const [action, enabled] of Object.entries(actions || {})) {
                            if (enabled) {
                                const code = mapUiKeyToPermissionCode(section, action);
                                if (code) permissionCodes.push(code);
                            }
                        }
                    }

                    const activeSections = Object.entries(ctx.rolePermissions || {})
                        .map(([section, actions]) => [section, Object.values(actions || {}).some(Boolean)])
                        .filter(([, enabled]) => enabled)
                        .map(([section]) => section);
                    const permissionScopes = {};
                    for (const section of activeSections) {
                        permissionScopes[section] = ctx.rolePermissionScopes?.[section] || getScopeForPermissions(
                            Object.entries(ctx.rolePermissions?.[section] || {})
                                .filter(([, enabled]) => !!enabled)
                                .map(([action]) => `${section}.${action}`)
                        );
                    }

                    await apiPut(`roles/${ctx.editingRole.id}/permissions`, {
                        permissions: permissionCodes,
                        permission_scopes: permissionScopes
                    });

                    ctx.showToast('Права роли обновлены', 'success');
                    await ctx.refreshRolesList();
                }
                ctx.closeRolePermissionsModal();
            } catch (error) {
                console.error('Ошибка сохранения прав роли:', error);
                ctx.showToast(error?.message || 'Ошибка сохранения прав роли', 'error');
            }
        },

        applyRolePermissionPreset(ctx, preset) {
            const data = buildPresetPermissions(preset);
            ctx.rolePermissions = data.permissions;
            ctx.rolePermissionScopes = data.scopes;
            ctx.rolePermissionPreset = preset;
        },

        async saveSettings(ctx) {
            try {
                await apiUpdateSettings(ctx.settingsForm);
                ctx.showToast('Настройки сохранены', 'success');
            } catch (_error) {
                ctx.showToast('Ошибка сохранения настроек', 'error');
            }
        },

        async saveOmnichannel(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }

                const payload = {
                    omni_app_public_base_url: String(ctx.omniForm?.app_public_base_url || '').trim(),
                    omni_tg_enabled: ctx.omniForm?.tg_enabled ? '1' : '0',
                    omni_max_enabled: ctx.omniForm?.max_enabled ? '1' : '0'
                };

                const tgToken = String(ctx.omniForm?.tg_bot_token || '').trim();
                const tgSecret = String(ctx.omniForm?.tg_webhook_secret || '').trim();
                const maxToken = String(ctx.omniForm?.max_bot_token || '').trim();
                const maxSecret = String(ctx.omniForm?.max_webhook_secret || '').trim();
                if (tgToken) payload.omni_tg_bot_token = tgToken;
                if (tgSecret) payload.omni_tg_webhook_secret = tgSecret;
                if (maxToken) payload.omni_max_bot_token = maxToken;
                if (maxSecret) payload.omni_max_webhook_secret = maxSecret;

                await apiUpdateSettings(payload);

                // Clear secret inputs after save.
                ctx.omniForm.tg_bot_token = '';
                ctx.omniForm.tg_webhook_secret = '';
                ctx.omniForm.max_bot_token = '';
                ctx.omniForm.max_webhook_secret = '';

                await this.loadSettings(ctx);
                ctx.showToast('Омниканал сохранён', 'success');
            } catch (error) {
                console.error('Ошибка сохранения омниканала:', error);
                ctx.showToast(error?.userMessage || error?.message || 'Ошибка сохранения омниканала', 'error');
            }
        },

        async saveWebrtcSettings(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }

                const jsonStr = String(ctx.webrtcForm?.ice_servers_json || '').trim();
                if (jsonStr === '') {
                    ctx.showToast('Укажите JSON ICE servers или оставьте дефолт', 'error');
                    return;
                }

                // Validate JSON early.
                const parsed = JSON.parse(jsonStr);
                if (!Array.isArray(parsed)) {
                    ctx.showToast('ICE servers должны быть JSON-массивом', 'error');
                    return;
                }

                await apiUpdateSettings({
                    webrtc_ice_servers_json: jsonStr
                });

                await this.loadSettings(ctx);
                ctx.showToast('WebRTC настройки сохранены', 'success');
            } catch (error) {
                ctx.showToast(error?.userMessage || error?.message || 'Ошибка сохранения WebRTC настроек', 'error');
            }
        },

        async loadSettings(ctx) {
            try {
                const data = await apiGetSettings();
                if (data.success && data.data) {
                    ctx.settings = data.data;
                    ctx.telegram = data.data.telegram || { enabled: false, bot_token: '', chat_id: '' };
                    ctx.weatherApiKey = data.data.weather_api_key || '';
                    if (data.data.weather_city) ctx.weatherCity = data.data.weather_city;
                }
            } catch (error) {
                console.error('Ошибка загрузки настроек:', error);
            }

            applySettingsForm(ctx);
        },

        async loadAdminHealth(ctx, force = false) {
            if (ctx.adminHealthLoading) return;
            if (!force && ctx.adminHealth && (Date.now() - (ctx.adminHealthLoadedAt || 0)) < 30000) return;

            ctx.adminHealthLoading = true;
            ctx.adminHealthError = '';
            try {
                const res = await apiGetAdminHealth();
                if (res?.success) {
                    ctx.adminHealth = res.data || {};
                    ctx.adminHealthLoadedAt = Date.now();
                } else {
                    ctx.adminHealthError = res?.error || 'Не удалось загрузить health-status';
                }
            } catch (error) {
                ctx.adminHealthError = error?.message || 'Не удалось загрузить health-status';
            } finally {
                ctx.adminHealthLoading = false;
            }
        },

        async runReleaseGate(ctx) {
            try {
                await this.loadAdminHealth(ctx, true);
                await this.loadDiagnosticsBaseline(ctx, true);
                ctx.showToast('Проверка готовности обновлена', 'success');
            } catch (error) {
                ctx.showToast(error?.message || 'Не удалось обновить проверку готовности', 'error');
            }
        },

        async loadUserDepartments(ctx, userId) {
            if (!userId) return;
            ctx.userDepartmentsLoading = true;
            ctx.userDepartmentsError = '';
            try {
                const data = await apiGetUserDepartments(userId);
                if (data.success) {
                    ctx.userDepartments = Array.isArray(data.data) ? data.data : [];
                } else {
                    ctx.userDepartments = [];
                    ctx.userDepartmentsError = data.error || 'Не удалось загрузить отделы пользователя';
                }
            } catch (error) {
                ctx.userDepartments = [];
                ctx.userDepartmentsError = error?.message || 'Не удалось загрузить отделы пользователя';
            } finally {
                ctx.userDepartmentsLoading = false;
            }
        },

        async addUserDepartment(ctx, userId, departmentId) {
            if (!userId || !departmentId) return;
            try {
                const res = await apiAddUserDepartment(userId, departmentId);
                if (res?.success) {
                    await this.loadUserDepartments(ctx, userId);
                    ctx.showToast('Отдел добавлен пользователю', 'success');
                    return;
                }
                ctx.showToast(res?.error || 'Не удалось добавить отдел', 'error');
            } catch (error) {
                ctx.showToast(error?.message || 'Не удалось добавить отдел', 'error');
            }
        },

        async removeUserDepartment(ctx, userId, departmentId) {
            if (!userId || !departmentId) return;
            try {
                const res = await apiDeleteUserDepartment(userId, departmentId);
                if (res?.success) {
                    await this.loadUserDepartments(ctx, userId);
                    ctx.showToast('Отдел удалён у пользователя', 'success');
                    return;
                }
                ctx.showToast(res?.error || 'Не удалось удалить отдел', 'error');
            } catch (error) {
                ctx.showToast(error?.message || 'Не удалось удалить отдел', 'error');
            }
        },

        closeSettingsModal(ctx) {
            ctx.settingsModalOpen = false;
            ctx.loadSettings();
        },

        async openSettingsModal(ctx) {
            ctx.settingsModalOpen = true;
            await ctx.loadSettings();
            await this.loadAdminHealth(ctx);

            // User-scoped settings
            try {
                const res = await apiGet('user-settings?key=prostiezvonki_user');
                if (res?.success) {
                    ctx.settingsForm.prostiezvonki_user = String(res?.data?.value || '');
                }
            } catch (_) {}

            try {
                const res = await apiGet('telegram');
                if (res.success && res.data) {
                    ctx.telegramForm = {
                        bot_token: res.data.bot_token || '',
                        chat_id: res.data.chat_id || '',
                        enabled: !!res.data.enabled
                    };
                }
            } catch (_) {}
        },

        async loadUserSettings(ctx) {
            try {
                const data = await apiGetUserProfile(ctx.currentUser.id);
                if (data.success && data.data) {
                    const profile = data.data;
                    if (profile.email_settings) {
                        ctx.emailSettings = { ...ctx.emailSettings, ...profile.email_settings };
                    }
                    if (profile.notification_settings) {
                        ctx.notificationSettings = { ...ctx.notificationSettings, ...profile.notification_settings };
                    }
                }
            } catch (error) {
                console.error('Ошибка загрузки настроек:', error);
            }
        },

        async saveTelegram(ctx) {
            try {
                await apiUpdateTelegram(ctx.telegramForm);
                ctx.showToast('Настройки Telegram сохранены', 'success');
            } catch (_error) {
                ctx.showToast('Ошибка сохранения настроек Telegram', 'error');
            }
        },

        async testTelegram(ctx) {
            try {
                const result = await apiTestTelegram();
                if (result.success) {
                    ctx.showToast('Тестовое сообщение отправлено', 'success');
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось отправить'), 'error');
                }
            } catch (_error) {
                ctx.showToast('Ошибка подключения к Telegram', 'error');
            }
        },

        async pingOmniTelegram(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }

                const res = await apiGet('integrations/telegram/ping');
                if (res.success) {
                    const username = res?.data?.response?.result?.username;
                    ctx.showToast(username ? `Telegram подключен: @${username}` : 'Telegram подключен', 'success');
                } else {
                    ctx.showToast(res.error || 'Не удалось подключиться к Telegram', 'error');
                }
            } catch (error) {
                ctx.showToast(error?.userMessage || error?.message || 'Не удалось подключиться к Telegram', 'error');
            }
        },

        async pingOmniMax(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }

                const res = await apiGet('integrations/max/ping');
                if (res.success) {
                    const botName = res?.data?.response?.name || res?.data?.response?.username;
                    ctx.showToast(botName ? `MAX подключен: ${botName}` : 'MAX подключен', 'success');
                } else {
                    ctx.showToast(res.error || 'Не удалось подключиться к MAX', 'error');
                }
            } catch (error) {
                ctx.showToast(error?.userMessage || error?.message || 'Не удалось подключиться к MAX', 'error');
            }
        },

        async saveBookingBotSettings(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }
                const payload = {
                    booking_bot_telegram_enabled: ctx.bookingBotForm?.enabled ? '1' : '0',
                    booking_bot_welcome_text: String(ctx.bookingBotForm?.welcome_text || '').trim()
                };
                const token = String(ctx.bookingBotForm?.token || '').trim();
                if (token) payload.booking_bot_telegram_token = token;
                await apiUpdateSettings(payload);
                ctx.bookingBotForm.token = '';
                await this.loadSettings(ctx);
                ctx.showToast('Настройки бота сохранены', 'success');
            } catch (error) {
                ctx.showToast(error?.userMessage || error?.message || 'Ошибка сохранения настроек бота', 'error');
            }
        },

        async pingBookingBot(ctx) {
            try {
                if (!ctx?.can?.('admin.full') && ctx.currentUser?.role !== 'root') {
                    ctx.showToast('Недостаточно прав', 'error');
                    return;
                }
                const res = await apiGet('integrations/telegram/ping');
                if (res.success) {
                    const username = res?.data?.response?.result?.username;
                    ctx.showToast(username ? `Бот подключен: @${username}` : 'Бот подключен', 'success');
                } else {
                    ctx.showToast(res.error || 'Не удалось подключиться к боту', 'error');
                }
            } catch (error) {
                ctx.showToast(error?.userMessage || error?.message || 'Не удалось подключиться к боту', 'error');
            }
        },

        async testEmailSettings(ctx) {
            ctx.showToast('Отправка тестового письма...', 'info');
            try {
                const result = await apiTestEmail(ctx.emailSettings);
                if (result.success) {
                    ctx.showToast('Тестовое письмо отправлено!', 'success');
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось отправить'), 'error');
                }
            } catch (error) {
                ctx.showToast('Ошибка: ' + (error.message || 'Не удалось отправить'), 'error');
            }
        },

        async saveEmailSettings(ctx) {
            try {
                const result = await apiPut(`profile/${ctx.currentUser.id}/email-settings`, ctx.emailSettings);
                if (result.success) {
                    ctx.showToast('Настройки почты сохранены', 'success');
                    ctx.settingsModalOpen = false;
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить настройки'), 'error');
                }
            } catch (error) {
                ctx.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async saveProfileSettings(ctx) {
            try {
                const profilePayload = { ...ctx.settingsForm };
                if (!profilePayload.avatar && ctx.currentUser?.avatar) {
                    profilePayload.avatar = null;
                }

                const profileResult = await apiUpdateUserProfile(ctx.currentUser.id, profilePayload);
                if (profileResult.success) {
                    ctx.currentUser = { ...ctx.currentUser, ...profilePayload };
                    ctx.showToast('Профиль сохранён', 'success');
                }

                const newPassword = String(ctx.settingsForm.new_password || '');
                const confirmPassword = String(ctx.settingsForm.confirm_password || '');
                if (newPassword) {
                    if (newPassword.length < 6) {
                        ctx.showToast('Пароль должен быть не менее 6 символов', 'error');
                        return;
                    }
                    if (newPassword !== confirmPassword) {
                        ctx.showToast('Пароли не совпадают', 'error');
                        return;
                    }
                    const passwordResult = await apiChangeUserPassword(ctx.currentUser.id, newPassword);
                    if (passwordResult.success) {
                        ctx.showToast('Пароль изменён', 'success');
                        ctx.settingsForm.new_password = '';
                        ctx.settingsForm.confirm_password = '';
                    } else {
                        ctx.showToast('Ошибка смены пароля: ' + (passwordResult.error || 'Неизвестная ошибка'), 'error');
                        return;
                    }
                }

                if (ctx.settingsForm.weather_city) {
                    ctx.weatherCity = ctx.settingsForm.weather_city;
                    localStorage.setItem('taskflow_weather_city', ctx.settingsForm.weather_city);
                    ctx.loadWeather();
                }

                const mailPayload = {
                    email: ctx.mailForm.email,
                    host: ctx.mailForm.host,
                    port: ctx.mailForm.port,
                    username: ctx.mailForm.smtp_username || ctx.mailForm.email,
                    password: ctx.mailForm.password,
                    encryption: ctx.mailForm.encryption,
                    imap_host: ctx.mailForm.imap_host,
                    imap_port: ctx.mailForm.imap_port,
                    imap_encryption: ctx.mailForm.imap_encryption,
                    display_name: ctx.mailForm.display_name,
                    signature: ctx.mailForm.signature
                };

                const mailResult = await apiPutProfileMailSettings(ctx.currentUser.id, mailPayload);

                if (mailResult.success) {
                    ctx.showToast('Настройки почты сохранены', 'success');
                    ctx.mailForm.password = '';
                }

                // ProstieZvonki: save current user's internal number (user-scoped)
                try {
                    const ext = String(ctx.settingsForm?.prostiezvonki_user || '').trim();
                    await apiPut('user-settings', { key: 'prostiezvonki_user', value: ext });
                } catch (_) {}

                ctx.closeSettingsModal();
            } catch (error) {
                console.error('Ошибка сохранения:', error);
                ctx.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        async uploadAvatar(ctx, event) {
            const file = event.target.files[0];
            if (!file) return;
            const result = await apiUploadAvatar(ctx.currentUser.id, file);
            if (result.success) {
                ctx.settingsForm.avatar = result.data.avatar;
                ctx.currentUser.avatar = result.data.avatar;
                ctx.showToast('Аватар обновлён', 'success');
            } else {
                ctx.showToast('Ошибка: ' + (result.error || 'Не удалось загрузить аватар'), 'error');
            }
        },

        async uploadLogo(ctx, event) {
            const file = event.target.files[0];
            if (!file) return;
            const result = await apiUploadLogo(file);
            if (result.success) {
                ctx.settingsForm.logo = result.data.logo;
                ctx.showToast('Логотип обновлён', 'success');
            } else {
                ctx.showToast('Ошибка: ' + (result.error || 'Не удалось загрузить логотип'), 'error');
            }
        },

        async saveAppSettings(ctx) {
            try {
                const companyName = (ctx.settingsForm.company_name || '').trim() || 'TaskFlow Pro';
                const appName = (ctx.settingsForm.app_name || '').trim() || 'TaskFlow';
                const referralWooCommerceBaseUrl = (ctx.settingsForm.referral_woocommerce_base_url || '').trim();
                const referralSharedSecret = (ctx.settingsForm.referral_shared_secret || '').trim();
                const wooConsumerKey = (ctx.settingsForm.woocommerce_api_consumer_key || '').trim();
                const wooConsumerSecret = (ctx.settingsForm.woocommerce_api_consumer_secret || '').trim();
                const payload = {
                    company_name: companyName,
                    app_name: appName,
                    logo: ctx.settingsForm.logo,
                    referral_woocommerce_base_url: referralWooCommerceBaseUrl,
                    woocommerce_api_consumer_key: wooConsumerKey
                };

                if (referralSharedSecret !== '') {
                    payload.referral_shared_secret = referralSharedSecret;
                }
                if (wooConsumerSecret !== '') {
                    payload.woocommerce_api_consumer_secret = wooConsumerSecret;
                }

                const result = await apiUpdateSettings(payload);
                if (result.success) {
                    ctx.settingsForm.company_name = companyName;
                    ctx.settingsForm.app_name = appName;
                    ctx.settingsForm.referral_woocommerce_base_url = referralWooCommerceBaseUrl;
                    ctx.settingsForm.woocommerce_api_consumer_key = wooConsumerKey;
                    ctx.settingsForm.referral_shared_secret = '';
                    ctx.settingsForm.woocommerce_api_consumer_secret = '';
                    ctx.settings = {
                        ...ctx.settings,
                        company_name: companyName,
                        app_name: appName,
                        logo: ctx.settingsForm.logo,
                        referral_woocommerce_base_url: referralWooCommerceBaseUrl,
                        woocommerce_api_consumer_key: wooConsumerKey,
                        referral_shared_secret_configured: referralSharedSecret !== '' || String(ctx.settings?.referral_shared_secret_configured || '') === '1' ? '1' : '0',
                        referral_shared_secret_source: referralSharedSecret !== '' ? 'settings' : (ctx.settings?.referral_shared_secret_source || 'none')
                    };
                    applySettingsForm(ctx);
                    ctx.showToast('Настройки приложения сохранены', 'success');
                } else {
                    ctx.showToast('Ошибка: ' + (result.error || 'Не удалось сохранить настройки приложения'), 'error');
                    return;
                }

                await ctx.saveWeatherSettings();
                ctx.closeSettingsModal();
            } catch (error) {
                ctx.showToast('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        resetAppSettings(ctx) {
            ctx.settingsForm.company_name = 'TaskFlow Pro';
            ctx.settingsForm.app_name = 'TaskFlow';
            ctx.settingsForm.logo = '';
            ctx.settingsForm.referral_woocommerce_base_url = '';
            ctx.settingsForm.referral_shared_secret = '';
            ctx.settingsForm.woocommerce_api_consumer_key = '';
            ctx.settingsForm.woocommerce_api_consumer_secret = '';
            ctx.showToast('Настройки сброшены', 'info');
        },

        getReferralSecretSourceLabel(_ctx, source) {
            return getReferralSecretSourceLabel(String(source || 'none'));
        },

        async copyReferralText(ctx, text, successMessage = 'Скопировано') {
            try {
                await navigator.clipboard.writeText(String(text || ''));
                ctx.showToast(successMessage, 'success');
            } catch (_error) {
                ctx.showToast('Не удалось скопировать', 'error');
            }
        }
    };
})();
