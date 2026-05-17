window.TaskFlowInitLifecycle = (function () {
    function installVisibilityListener(ctx) {
        if (ctx._visibilityListenerInstalled) return;
        ctx._visibilityListenerInstalled = true;

        const onVisibility = () => {
            const hidden = document.hidden;
            ctx._pollingEnabled = !hidden;

            if (!hidden && ctx.isAuthenticated) {
                try { ctx.loadTasks?.(); } catch (_) {}
                try { ctx.loadNotifications?.(); } catch (_) {}
                try { ctx.loadChatRooms?.(); } catch (_) {}
            }
        };

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('focus', onVisibility);
    }

    function shouldPoll(ctx) {
        if (!ctx.isAuthenticated) return false;
        if (!ctx._pollingEnabled) return false;
        if (Date.now() < (ctx._pollingPausedUntil || 0)) return false;
        return true;
    }

    function pausePolling(ctx, ms = 15000) {
        ctx._pollingPausedUntil = Date.now() + Math.max(0, Number(ms) || 0);
    }

    function startPoller(ctx, key, fn, intervalMs, opts = {}) {
        const k = String(key || `poller_${++ctx._pollerSeq}`);
        stopPoller(ctx, k);

        const interval = Math.max(1000, Number(intervalMs) || 1000);
        const immediate = opts.immediate !== false;
        const minGap = Math.max(0, Number(opts.minGapMs) || 0);

        let inFlight = false;
        let lastRunAt = 0;

        const tick = async () => {
            if (!shouldPoll(ctx)) return;
            if (inFlight) return;

            const now = Date.now();
            if (minGap && (now - lastRunAt) < minGap) return;

            inFlight = true;
            lastRunAt = now;
            try {
                await fn();
            } catch (_) {
                pausePolling(ctx, 10000);
            } finally {
                inFlight = false;
            }
        };

        const id = setInterval(tick, interval);
        ctx._pollers[k] = { id, tick, interval };
        if (immediate) setTimeout(tick, 50);
        return k;
    }

    function stopPoller(ctx, key) {
        const k = String(key || '');
        const poller = ctx._pollers?.[k];
        if (poller?.id) clearInterval(poller.id);
        if (ctx._pollers) delete ctx._pollers[k];
    }

    async function bootstrapRuntime(ctx) {
        setTimeout(() => {
            ctx.preAuthLoading = false;
            ctx.isLoading = false;
        }, 1000);

        startPoller(ctx, 'tasks', async () => {
            await ctx.loadTasks();
        }, 30000, { immediate: true, minGapMs: 5000 });

        setInterval(() => {
            if (ctx.isAuthenticated && Object.keys(ctx.activeTimers).length > 0) {
                for (const taskId in ctx.activeTimers) {
                    ctx.activeTimers[taskId]++;
                    if (ctx.activeTimers[taskId] % 30 === 0) {
                        ctx.saveTaskTimerToServer(parseInt(taskId, 10), ctx.activeTimers[taskId]);
                    }
                }
                ctx.$nextTick(() => {});
            }
        }, 1000);

        ctx.updateDateTime();
        setInterval(() => ctx.updateDateTime(), 1000);

        if (ctx.isAuthenticated) {
            startPoller(ctx, 'weather', async () => {
                await ctx.loadWeather();
            }, 1800000, { immediate: false, minGapMs: 60000 });
        }

        if (ctx.isAuthenticated) {
            await ctx.loadRoles();
            await ctx.loadMailSettings();
        }
    }

    return {
        initVisibilityListener(ctx) {
            return installVisibilityListener(ctx);
        },

        shouldPoll(ctx) {
            return shouldPoll(ctx);
        },

        pausePolling(ctx, ms) {
            return pausePolling(ctx, ms);
        },

        startPoller(ctx, key, fn, intervalMs, opts = {}) {
            return startPoller(ctx, key, fn, intervalMs, opts);
        },

        stopPoller(ctx, key) {
            return stopPoller(ctx, key);
        },

        async bootstrapRuntime(ctx) {
            return bootstrapRuntime(ctx);
        }
    };
})();
