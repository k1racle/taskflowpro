window.TaskFlowMenuOrder = (function () {
    const SETTINGS_KEY = 'menu_order_v1';
    const REQUIRED_IDS = ['crm-dashboard', 'crm-clients', 'crm-funnels', 'crm-sales', 'crm-store', 'documents'];

    function getBaseNavItems(ctx) {
        return ctx.defaultNavItems || ctx.navItems || [];
    }

    function buildOrderedNav(ctx, ids) {
        const base = getBaseNavItems(ctx);
        const map = new Map(base.map((item) => [item.id, item]));
        const out = [];

        if (Array.isArray(ids)) {
            for (const id of ids) {
                const item = map.get(id);
                if (!item) continue;
                out.push(item);
                map.delete(id);
            }
        }

        for (const id of REQUIRED_IDS) {
            if (!map.has(id)) continue;
            const alreadyIncluded = out.some((item) => item && item.id === id);
            if (alreadyIncluded) continue;
            out.push(map.get(id));
            map.delete(id);
        }

        for (const item of map.values()) out.push(item);
        return out;
    }

    function moveDraftList(list, from, to) {
        if (from === null || typeof from === 'undefined') return list;
        if (from === to) return list;

        const nextList = [...(list || [])];
        const [moved] = nextList.splice(from, 1);
        nextList.splice(to, 0, moved);
        return nextList;
    }

    return {
        SETTINGS_KEY,

        async apply(ctx) {
            if (!ctx.currentUser?.id) return;
            try {
                const res = await apiGet(`user-settings?key=${encodeURIComponent(SETTINGS_KEY)}`);
                const raw = res?.data?.value;
                if (!raw) return;

                let ids = null;
                try {
                    ids = JSON.parse(raw);
                } catch (_) {
                    ids = null;
                }
                if (!Array.isArray(ids)) return;

                ctx.navItems = buildOrderedNav(ctx, ids);
            } catch (e) {
                console.warn('applyUserMenuOrder error', e);
            }
        },

        initDraft(ctx) {
            const base = ctx.navItems || [];
            ctx.menuOrderDraft = base.map((item) => ({ id: item.id, label: item.label }));
        },

        dragStart(ctx, idx) {
            ctx.menuDragIndex = idx;
        },

        moveDraft(ctx, from, to) {
            ctx.menuOrderDraft = moveDraftList(ctx.menuOrderDraft, from, to);
        },

        dropDraft(ctx, idx) {
            const from = ctx.menuDragIndex;
            ctx.menuDragIndex = null;
            ctx.menuOrderDraft = moveDraftList(ctx.menuOrderDraft, from, idx);
        },

        moveByDelta(ctx, idx, delta) {
            const next = idx + delta;
            const list = [...(ctx.menuOrderDraft || [])];
            if (next < 0 || next >= list.length) return;
            ctx.menuOrderDraft = moveDraftList(list, idx, next);
        },

        async save(ctx) {
            const ids = (ctx.menuOrderDraft || []).map((item) => item.id);
            await apiPut('user-settings', { key: SETTINGS_KEY, value: JSON.stringify(ids) });
            ctx.navItems = buildOrderedNav(ctx, ids);
        },

        async saveWithFeedback(ctx) {
            try {
                await this.save(ctx);
                ctx.showToast('Порядок меню сохранён', 'success');
            } catch (e) {
                console.error(e);
                ctx.showToast('Не удалось сохранить порядок меню', 'error');
            }
        },

        async reset(ctx) {
            ctx.navItems = JSON.parse(JSON.stringify(getBaseNavItems(ctx)));
            this.initDraft(ctx);
            try {
                await apiPut('user-settings', { key: SETTINGS_KEY, value: '' });
            } catch (_) {}
        },

        async resetWithConfirm(ctx) {
            ctx.openConfirm(
                'Сбросить порядок меню?',
                'Сбросить порядок меню к стандартному?',
                async () => {
                    await this.reset(ctx);
                },
                { confirmText: 'Сбросить', danger: true }
            );
        }
    };
})();
