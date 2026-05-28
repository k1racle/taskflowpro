/**
 * core/module-loader.js - Модульный загрузчик TaskFlow Pro
 * Ленивая загрузка модулей, выгрузка неактивных
 */

const ModuleLoader = (function() {
    // Кэш загруженных модулей
    const loadedModules = new Map();
    const loadedComponents = new Map();
    
    // Активный модуль
    let activeModule = null;
    let activeComponent = null;
    
    // Загрузка в процессе
    const loadingModules = new Map();
    
    // Конфигурация модулей
    const moduleConfig = {
        'tasks': {
            js: 'assets/js/modules/tasks/index.js',
            html: 'assets/components/tasks-view.html',
            css: null
        },
        'my-tasks': {
            js: 'assets/js/modules/tasks/index.js',
            html: 'assets/components/my-tasks-view.html',
            css: null
        },
        'projects': {
            js: 'assets/js/modules/projects/index.js',
            html: 'assets/components/projects-view.html',
            css: null
        },
        'departments': {
            js: 'assets/js/modules/departments/index.js',
            html: 'assets/components/departments-view.html',
            css: null
        },
        'files': {
            js: 'assets/js/modules/files/index.js',
            html: 'assets/components/files-view.html',
            css: null
        },
        'knowledge': {
            js: 'assets/js/modules/knowledge/index.js',
            html: 'assets/components/knowledge-view.html',
            css: null
        },
        'documents': {
            js: 'assets/js/modules/documents/index.js',
            html: 'assets/components/documents-view.html',
            css: null
        },
        'widgets': {
            js: 'assets/js/modules/widgets/site-widgets.js',
            html: 'assets/components/widgets-view.html',
            css: null
        },
        'mail': {
            js: 'assets/js/modules/mail/index.js',
            html: 'assets/components/mail-interface.html',
            css: null
        },
        'conferences': {
            js: 'assets/js/modules/conferences/index.js',
            html: 'assets/components/conferences-view.html',
            css: null
        },
        'my-shift': {
            js: 'assets/js/modules/shifts/my-shift.js',
            html: 'assets/components/my-shift-view.html',
            css: null
        },
        'chat': {
            js: 'assets/js/modules/chat/index.js',
            html: 'assets/components/chat-view.html',
            css: null
        },
        'crm-dashboard': {
            js: 'assets/js/modules/crm/dashboard-sales.js',
            html: 'assets/components/crm-dashboard-view.html',
            css: null
        },
        'crm-clients': {
            js: 'assets/js/modules/crm/client-card.js',
            html: 'assets/components/crm-clients-view.html',
            css: null
        },
        'crm-funnels': {
            js: 'assets/js/modules/crm/funnels.js',
            html: 'assets/components/crm-funnels-view.html',
            css: null
        },
        'crm-sales': {
            js: null,
            html: 'assets/components/crm-sales-view.html',
            css: null
        },
        'crm-store': {
            js: 'assets/js/modules/crm/store.js',
            html: 'assets/components/crm-store-view.html',
            css: null
        },
        'helpdesk': {
            js: 'assets/js/modules/helpdesk/index.js',
            html: 'assets/components/helpdesk-view.html',
            css: null
        },
        'booking': {
            js: 'assets/js/modules/booking/index.js',
            html: 'assets/components/booking-view.html',
            css: null
        },
        'users': {
            js: 'assets/js/modules/admin/index.js',
            html: 'assets/components/users-view.html',
            css: null
        },
        'roles': {
            js: 'assets/js/modules/admin/index.js',
            html: 'assets/components/roles-view.html',
            css: null
        },
        'stages-manager': {
            js: 'assets/js/modules/stages/index.js',
            html: 'assets/components/stages-view.html',
            css: null
        },
        'settings': {
            // Settings logic lives in the admin module bundle.
            js: 'assets/js/modules/admin/index.js',
            html: 'assets/components/settings-view.html',
            css: null
        },
        'leader-dashboard': {
            js: 'assets/js/modules/leader/index.js',
            html: 'assets/components/leader-dashboard-view.html',
            css: null
        }
    };

    function stripCdataWrapper(source) {
        if (!source) return '';
        let out = String(source);
        out = out.replace(/^\s*<!\[CDATA\[/, '');
        out = out.replace(/\]\]>\s*$/, '');
        return out;
    }

    async function loadLegacyScript(url) {
        // The project JS modules are not ESM: they register themselves on window.*
        // They are wrapped into CDATA markers, so we fetch+eval after stripping.
        const response = await fetch(`${url}?v=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status} while loading ${url}`);
        }
        const text = stripCdataWrapper(await response.text());
        // eslint-disable-next-line no-new-func
        (new Function(text))();
        return true;
    }

    // ============================================
    // MODULE LOADING
    // ============================================

    /**
     * Загрузить модуль
     */
    async function loadModule(moduleName) {
        // Проверяем, загружен ли уже
        if (loadedModules.has(moduleName)) {
            console.log(`Module '${moduleName}' already loaded`);
            return loadedModules.get(moduleName);
        }
        
        // Проверяем, не загружается ли уже
        if (loadingModules.has(moduleName)) {
            console.log(`Module '${moduleName}' is loading...`);
            return loadingModules.get(moduleName);
        }
        
        const config = moduleConfig[moduleName];
        if (!config) {
            console.warn(`Module '${moduleName}' not configured`);
            return null;
        }
        
        // Создаём promise загрузки
        const loadPromise = (async () => {
            try {
                console.log(`Loading module: ${moduleName}`);
                
                // Загружаем JS модуль
                let moduleInstance = null;
                if (config.js) {
                    try {
                        await loadLegacyScript(config.js);
                        console.log(`JS loaded (legacy): ${config.js}`);
                    } catch (e) {
                        console.warn(`Failed to load JS for ${moduleName}:`, e);
                        // Не критично, продолжаем без JS
                    }
                }
                
                // Загружаем HTML компонент
                let htmlContent = null;
                if (config.html) {
                    try {
                        const response = await fetch(`${config.html}?v=${Date.now()}`, {
                            cache: 'no-store'
                        });
                        if (response.ok) {
                            htmlContent = await response.text();
                            console.log(`HTML loaded: ${config.html}`);
                        }
                    } catch (e) {
                        console.error(`Failed to load HTML for ${moduleName}:`, e);
                    }
                }
                
                // Загружаем CSS если есть
                if (config.css) {
                    try {
                        await loadCSS(config.css);
                    } catch (e) {
                        console.warn(`Failed to load CSS for ${moduleName}:`, e);
                    }
                }
                
                const moduleData = {
                    name: moduleName,
                    instance: moduleInstance,
                    html: htmlContent,
                    loaded: true,
                    timestamp: Date.now()
                };
                
                loadedModules.set(moduleName, moduleData);
                return moduleData;
                
            } catch (error) {
                console.error(`Failed to load module '${moduleName}':`, error);
                throw error;
            } finally {
                loadingModules.delete(moduleName);
            }
        })();
        
        loadingModules.set(moduleName, loadPromise);
        return loadPromise;
    }

    /**
     * Выгрузить модуль
     */
    async function unloadModule(moduleName) {
        if (!loadedModules.has(moduleName)) {
            return;
        }
        
        const moduleData = loadedModules.get(moduleName);
        
        // Вызываем cleanup если есть
        if (moduleData.instance?.default?.cleanup) {
            try {
                await moduleData.instance.default.cleanup();
                console.log(`Cleanup called for ${moduleName}`);
            } catch (e) {
                console.error(`Cleanup error for ${moduleName}:`, e);
            }
        }
        
        // Также вызываем глобальный cleanup если экспортирован
        if (moduleData.instance?.cleanup) {
            try {
                await moduleData.instance.cleanup();
            } catch (e) {
                console.error(`Global cleanup error for ${moduleName}:`, e);
            }
        }
        
        // Удаляем из кэша
        loadedModules.delete(moduleName);
        
        // Очищаем память
        if (moduleData.html) {
            moduleData.html = null;
        }
        
        console.log(`Module unloaded: ${moduleName}`);
    }

    /**
     * Выгрузить все модули кроме указанного
     */
    async function unloadAllModules(except = null) {
        const moduleNames = Array.from(loadedModules.keys());
        
        for (const name of moduleNames) {
            if (name !== except) {
                await unloadModule(name);
            }
        }
    }

    // ============================================
    // COMPONENT LOADING
    // ============================================

    /**
     * Загрузить HTML компонент в элемент
     */
    async function loadComponent(container, componentName, props = {}) {
        const config = moduleConfig[componentName];
        if (!config || !config.html) {
            console.error(`Component '${componentName}' not found`);
            return;
        }
        
        try {
            // Показываем индикатор загрузки
            container.setAttribute('data-loading', 'true');
            
            // Загружаем HTML
            const response = await fetch(`${config.html}?v=${Date.now()}`, {
                cache: 'no-store'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const html = await response.text();
            
            // Вставляем HTML
            container.innerHTML = html;
            
            // Сохраняем в кэш
            loadedComponents.set(componentName, {
                html,
                props,
                timestamp: Date.now()
            });
            
            // Вызываем инициализацию если есть
            if (props.onLoad) {
                await props.onLoad(container);
            }
            
        } catch (error) {
            console.error(`Failed to load component '${componentName}':`, error);
            container.innerHTML = `
                <div class="p-8 text-center">
                    <div class="text-red-500 font-semibold">Ошибка загрузки компонента</div>
                    <div class="text-sm text-gray-500 mt-2">${error.message}</div>
                </div>
            `;
        } finally {
            container.removeAttribute('data-loading');
        }
    }

    // ============================================
    // CSS LOADING
    // ============================================

    /**
     * Загрузить CSS файл
     */
    function loadCSS(url) {
        return new Promise((resolve, reject) => {
            // Проверяем, не загружен ли уже
            const existing = document.querySelector(`link[href="${url}"]`);
            if (existing) {
                resolve();
                return;
            }
            
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = `${url}?v=${Date.now()}`;
            
            link.onload = () => {
                console.log(`CSS loaded: ${url}`);
                resolve();
            };
            
            link.onerror = (e) => {
                console.error(`CSS load error: ${url}`, e);
                reject(e);
            };
            
            document.head.appendChild(link);
        });
    }

    // ============================================
    // PREFETCHING
    // ============================================

    /**
     * Предзагрузка модуля (в фоне)
     */
    async function prefetchModule(moduleName) {
        if (loadedModules.has(moduleName) || loadingModules.has(moduleName)) {
            return;
        }
        
        const config = moduleConfig[moduleName];
        if (!config) return;
        
        // Загружаем только JS в фоне
        if (config.js) {
            try {
                await fetch(`${config.js}?v=${Date.now()}`, { cache: 'no-store' });
                console.log(`Prefetched: ${moduleName}`);
            } catch (e) {
                // Игнорируем ошибки предзагрузки
            }
        }
    }

    /**
     * Предзагрузка списка модулей
     */
    function prefetchModules(names) {
        names.forEach(name => prefetchModule(name));
    }

    // ============================================
    // UTILITIES
    // ============================================

    /**
     * Проверить, загружен ли модуль
     */
    function isLoaded(moduleName) {
        return loadedModules.has(moduleName);
    }

    /**
     * Получить загруженный модуль
     */
    function getModule(moduleName) {
        return loadedModules.get(moduleName);
    }

    /**
     * Получить список загруженных модулей
     */
    function getLoadedModules() {
        return Array.from(loadedModules.keys());
    }

    /**
     * Очистить весь кэш
     */
    async function clearCache() {
        await unloadAllModules();
        loadedComponents.clear();
        loadingModules.clear();
    }

    // ============================================
    // INITIALIZATION
    // ============================================

    function init() {
        console.log('ModuleLoader initialized');
        console.log(`Configured modules: ${Object.keys(moduleConfig).length}`);
    }

    // ============================================
    // PUBLIC API
    // ============================================

    return {
        // Module loading
        loadModule,
        unloadModule,
        unloadAllModules,
        
        // Component loading
        loadComponent,
        
        // Utilities
        isLoaded,
        getModule,
        getLoadedModules,
        clearCache,
        
        // Prefetching
        prefetchModule,
        prefetchModules,
        
        // Init
        init
    };
})();

// Экспорт для модульной системы
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ModuleLoader;
}
