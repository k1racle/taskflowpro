window.TaskFlowSmartTopbar = (function () {
    return {
        init(ctx) {
            const topbar = document.querySelector('.smart-topbar');
            if (!topbar) return;

            const triggerZone = document.querySelector('.topbar-trigger-zone');
            if (triggerZone) {
                triggerZone.addEventListener('mouseenter', () => {
                    topbar.classList.add('visible');
                    if (topbar._hideTimeout) clearTimeout(topbar._hideTimeout);
                });
            }

            topbar.addEventListener('mouseleave', () => {
                topbar._hideTimeout = setTimeout(() => {
                    if (!triggerZone || !triggerZone.matches(':hover')) {
                        topbar.classList.remove('visible');
                    }
                }, 5000);
            });

            topbar.addEventListener('mouseenter', () => {
                if (topbar._hideTimeout) clearTimeout(topbar._hideTimeout);
            });
        },

        toggleTheme(ctx) {
            ctx.isDark = !ctx.isDark;
            this.applyTheme(ctx);
        },

        applyTheme(ctx) {
            if (ctx.isDark) {
                document.documentElement.classList.add('dark');
                document.body.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                return;
            }

            document.documentElement.classList.remove('dark');
            document.body.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        }
    };
})();
