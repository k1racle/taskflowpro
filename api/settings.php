<?php
/**
 * api/settings.php - Глобальные настройки приложения
 *
 * Эндпоинты:
 * - GET /api/settings - все настройки
 * - GET /api/settings/:key - настройка по ключу
 * - PUT /api/settings/:key - обновление настройки
 * - POST /api/settings - массовое обновление настроек
 */

/**
 * Обработка запросов к /api/settings/*
 * @param string $method HTTP метод
 * @param string|null $action Действие
 * @param mixed $id ID ресурса
 */
function handleSettings(string $method, ?string $action, mixed $id): void {
    require_once __DIR__ . '/roles.php';

    $pdo = getPDO();
    $currentUser = getCurrentUser();
    $canManageSettings = $currentUser && hasAdminAccess($currentUser);
    $loadSettingValue = function(string $key) use ($pdo) {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE BINARY `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    $loadReferralSharedSecretFromSettings = function() use ($loadSettingValue): string {
        $storedValue = trim((string)($loadSettingValue('referral_shared_secret') ?? ''));
        if ($storedValue === '') {
            return '';
        }

        try {
            return trim((string)(appDecrypt($storedValue) ?? ''));
        } catch (Throwable $e) {
            return $storedValue;
        }
    };

    $loadEncryptedSettingValue = function(string $key) use ($loadSettingValue): string {
        $storedValue = trim((string)($loadSettingValue($key) ?? ''));
        if ($storedValue === '') {
            return '';
        }

        try {
            return trim((string)(appDecrypt($storedValue) ?? ''));
        } catch (Throwable $e) {
            return $storedValue;
        }
    };

    $omnichannelDefaults = [
        'omni_app_public_base_url' => '',
        'omni_tg_enabled' => '0',
        'omni_tg_bot_token' => '',
        'omni_tg_webhook_secret' => '',
        'omni_max_enabled' => '0',
        'omni_max_bot_token' => '',
        'omni_max_webhook_secret' => ''
    ];

    $webrtcDefaults = [
        // JSON string of RTCIceServer[] (see RTCPeerConnection config)
        // Default: public Google STUN.
        'webrtc_ice_servers_json' => '[{"urls":"stun:stun.l.google.com:19302"}]'
    ];

    $loadOmnichannelSettingsSnapshot = function() use ($loadSettingValue, $loadEncryptedSettingValue, $omnichannelDefaults): array {
        $raw = $omnichannelDefaults;
        foreach (array_keys($omnichannelDefaults) as $key) {
            $raw[$key] = $loadSettingValue($key) ?? $omnichannelDefaults[$key];
        }

        $tgToken = $loadEncryptedSettingValue('omni_tg_bot_token');
        $maxToken = $loadEncryptedSettingValue('omni_max_bot_token');

        return [
            'omni_app_public_base_url' => (string)($raw['omni_app_public_base_url'] ?? ''),
            'omni_tg_enabled' => (string)($raw['omni_tg_enabled'] ?? '0'),
            'omni_tg_bot_token' => '',
            'omni_tg_bot_token_configured' => $tgToken !== '' ? '1' : '0',
            'omni_tg_webhook_secret' => '',
            'omni_tg_webhook_secret_configured' => trim((string)($raw['omni_tg_webhook_secret'] ?? '')) !== '' ? '1' : '0',
            'omni_max_enabled' => (string)($raw['omni_max_enabled'] ?? '0'),
            'omni_max_bot_token' => '',
            'omni_max_bot_token_configured' => $maxToken !== '' ? '1' : '0',
            'omni_max_webhook_secret' => '',
            'omni_max_webhook_secret_configured' => trim((string)($raw['omni_max_webhook_secret'] ?? '')) !== '' ? '1' : '0',
        ];
    };

    $loadWebrtcSettingsSnapshot = function() use ($loadSettingValue, $webrtcDefaults): array {
        $raw = $webrtcDefaults;
        foreach (array_keys($webrtcDefaults) as $key) {
            $raw[$key] = $loadSettingValue($key) ?? $webrtcDefaults[$key];
        }

        return [
            'webrtc_ice_servers_json' => (string)($raw['webrtc_ice_servers_json'] ?? $webrtcDefaults['webrtc_ice_servers_json'])
        ];
    };

    $getReferralSharedSecretSource = function() use ($loadReferralSharedSecretFromSettings): string {
        if ($loadReferralSharedSecretFromSettings() !== '') {
            return 'settings';
        }

        if (defined('REFERRAL_SHARED_SECRET') && trim((string)REFERRAL_SHARED_SECRET) !== '') {
            return 'legacy';
        }

        if (trim((string)(getenv('REFERRAL_SHARED_SECRET') ?: '')) !== '') {
            return 'legacy';
        }

        return 'none';
    };

    $loadMangoOfficeSecurityTokenFromSettings = function() use ($loadEncryptedSettingValue): string {
        return $loadEncryptedSettingValue('mango_office_security_token');
    };

    $ensureUtf8mb4Connection = function() use ($pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
        } catch (Throwable $e) {
            error_log('settings.php: failed to align connection collation: ' . $e->getMessage());
        }
    };

    $ensureUtf8mb4Connection();

    $siteWidgetDefaults = [
        'site_widgets_api_base' => '',
        'site_widgets_position' => 'right',
        'site_widgets_contact_url' => '',
        'site_widgets_contact_label' => 'Написать в чат',
        'site_widgets_contact_description' => 'Диалог откроется прямо на сайте и сразу попадет в CRM-чат команды.',
        'site_widgets_form_width' => '480',
        'site_widgets_form_height' => '760',
        'site_widgets_chat_width' => '420',
        'site_widgets_chat_height' => '760',
        'site_widgets_chat_title' => 'Команда на связи',
        'site_widgets_chat_description' => 'Ответим в CRM-чате или оформим обращение в HelpDesk без перехода на другие каналы.',
        'site_widgets_brand_color' => '#2563eb',
        'site_widgets_brand_button_text' => '💬',
        'site_widgets_brand_form_title' => 'Оставить обращение',
        'site_widgets_brand_form_description' => 'Опишите задачу в форме, и мы сразу зарегистрируем обращение в HelpDesk.'
    ];

    $mangoOfficeDefaults = [
        'mango_office_enabled' => '0',
        'mango_office_remote_id' => ''
    ];

    $ensureSettingsTableUtf8mb4 = function() use ($pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $stmt = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' LIMIT 1");
            $stmt->execute();
            $collation = (string)($stmt->fetchColumn() ?: '');

            if ($collation !== '' && stripos($collation, 'utf8mb4_') !== 0) {
                $pdo->exec("ALTER TABLE settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {
            // Ignore best-effort charset alignment on hosts with restricted metadata access.
        }
    };

    $quoteSqlString = function(string $value) use ($pdo): string {
        $quoted = $pdo->quote($value);
        if ($quoted === false) {
            throw new RuntimeException('Не удалось экранировать SQL строку');
        }
        return $quoted;
    };

    $buildBinarySettingsKeyCondition = function(array $keys) use ($quoteSqlString): string {
        if ($keys === []) {
            return '0 = 1';
        }

        $quotedKeys = array_map(static fn(string $key): string => $key, $keys);
        $quotedKeys = array_map($quoteSqlString, $quotedKeys);
        return 'BINARY `key` IN (' . implode(',', $quotedKeys) . ')';
    };

    $loadSiteWidgetSettings = function() use ($pdo, $siteWidgetDefaults, $buildBinarySettingsKeyCondition, $ensureSettingsTableUtf8mb4): array {
        $ensureSettingsTableUtf8mb4();
        $keys = array_keys($siteWidgetDefaults);
        $condition = $buildBinarySettingsKeyCondition($keys);
        $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE $condition");

        $raw = $siteWidgetDefaults;
        foreach ($stmt->fetchAll() as $row) {
            $raw[$row['key']] = $row['value'];
        }

        return [
            'api_base' => trim((string)($raw['site_widgets_api_base'] ?? '')),
            'position' => ($raw['site_widgets_position'] ?? 'right') === 'left' ? 'left' : 'right',
            'contact_url' => (string)($raw['site_widgets_contact_url'] ?? $siteWidgetDefaults['site_widgets_contact_url']),
            'contact_label' => (string)($raw['site_widgets_contact_label'] ?? $siteWidgetDefaults['site_widgets_contact_label']),
            'contact_description' => (string)($raw['site_widgets_contact_description'] ?? $siteWidgetDefaults['site_widgets_contact_description']),
            'form_width' => (int)($raw['site_widgets_form_width'] ?? 480),
            'form_height' => (int)($raw['site_widgets_form_height'] ?? 760),
            'chat_width' => (int)($raw['site_widgets_chat_width'] ?? 420),
            'chat_height' => (int)($raw['site_widgets_chat_height'] ?? 760),
            'chat_title' => (string)($raw['site_widgets_chat_title'] ?? $siteWidgetDefaults['site_widgets_chat_title']),
            'chat_description' => (string)($raw['site_widgets_chat_description'] ?? $siteWidgetDefaults['site_widgets_chat_description']),
            'brand_color' => (string)($raw['site_widgets_brand_color'] ?? '#2563eb'),
            'brand_button_text' => (string)($raw['site_widgets_brand_button_text'] ?? '💬'),
            'brand_form_title' => (string)($raw['site_widgets_brand_form_title'] ?? $siteWidgetDefaults['site_widgets_brand_form_title']),
            'brand_form_description' => (string)($raw['site_widgets_brand_form_description'] ?? $siteWidgetDefaults['site_widgets_brand_form_description'])
        ];
    };

    $upsertSetting = function(string $key, string $value) use ($pdo, $ensureSettingsTableUtf8mb4): void {
        $ensureSettingsTableUtf8mb4();
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $stmt->execute([$key, $value]);
    };

    $normalizeSiteWidgetConfig = function(array $raw) use ($siteWidgetDefaults): array {
        return [
            'api_base' => trim((string)($raw['api_base'] ?? $raw['site_widgets_api_base'] ?? $siteWidgetDefaults['site_widgets_api_base'])),
            'position' => ($raw['position'] ?? $raw['site_widgets_position'] ?? 'right') === 'left' ? 'left' : 'right',
            'contact_url' => (string)($raw['contact_url'] ?? $raw['site_widgets_contact_url'] ?? $siteWidgetDefaults['site_widgets_contact_url']),
            'contact_label' => (string)($raw['contact_label'] ?? $raw['site_widgets_contact_label'] ?? $siteWidgetDefaults['site_widgets_contact_label']),
            'contact_description' => (string)($raw['contact_description'] ?? $raw['site_widgets_contact_description'] ?? $siteWidgetDefaults['site_widgets_contact_description']),
            'form_width' => (int)($raw['form_width'] ?? $raw['site_widgets_form_width'] ?? 480),
            'form_height' => (int)($raw['form_height'] ?? $raw['site_widgets_form_height'] ?? 760),
            'chat_width' => (int)($raw['chat_width'] ?? $raw['site_widgets_chat_width'] ?? 420),
            'chat_height' => (int)($raw['chat_height'] ?? $raw['site_widgets_chat_height'] ?? 760),
            'chat_title' => (string)($raw['chat_title'] ?? $raw['site_widgets_chat_title'] ?? $siteWidgetDefaults['site_widgets_chat_title']),
            'chat_description' => (string)($raw['chat_description'] ?? $raw['site_widgets_chat_description'] ?? $siteWidgetDefaults['site_widgets_chat_description']),
            'brand_color' => (string)($raw['brand_color'] ?? $raw['site_widgets_brand_color'] ?? '#2563eb'),
            'brand_button_text' => (string)($raw['brand_button_text'] ?? $raw['site_widgets_brand_button_text'] ?? '💬'),
            'brand_form_title' => (string)($raw['brand_form_title'] ?? $raw['site_widgets_brand_form_title'] ?? $siteWidgetDefaults['site_widgets_brand_form_title']),
            'brand_form_description' => (string)($raw['brand_form_description'] ?? $raw['site_widgets_brand_form_description'] ?? $siteWidgetDefaults['site_widgets_brand_form_description'])
        ];
    };

    $sanitizeText = function($value, int $maxLength, string $fallback = ''): string {
        if (!is_string($value)) {
            return $fallback;
        }
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value ?? '');
        if ($value === '') {
            return $fallback;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    };

    $sanitizeSize = function($value, int $fallback, int $min, int $max): int {
        $numeric = (int)$value;
        if ($numeric <= 0) {
            $numeric = $fallback;
        }
        return max($min, min($max, $numeric));
    };

    $getMangoOfficeConfig = function(?array $raw = null) use ($loadSettingValue, $loadMangoOfficeSecurityTokenFromSettings): array {
        $raw = is_array($raw) ? $raw : [];

        $enabledSource = array_key_exists('mango_office_enabled', $raw)
            ? $raw['mango_office_enabled']
            : ($loadSettingValue('mango_office_enabled') ?? '0');
        $enabled = filter_var($enabledSource, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $enabled = trim((string)$enabledSource) === '1';
        }

        $remoteId = array_key_exists('mango_office_remote_id', $raw)
            ? trim((string)$raw['mango_office_remote_id'])
            : trim((string)($loadSettingValue('mango_office_remote_id') ?? ''));

        $securityTokenConfiguredSource = $raw['mango_office_security_token_configured'] ?? null;
        $securityTokenConfigured = filter_var($securityTokenConfiguredSource, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($securityTokenConfigured === null) {
            $securityTokenConfigured = false;
        }

        if (array_key_exists('mango_office_security_token', $raw)) {
            $securityToken = trim((string)$raw['mango_office_security_token']);
            if ($securityToken === '' && $securityTokenConfigured) {
                $securityToken = $loadMangoOfficeSecurityTokenFromSettings();
            }
        } else {
            $securityToken = $loadMangoOfficeSecurityTokenFromSettings();
        }

        return [
            'enabled' => (bool)$enabled,
            'remote_id' => $remoteId,
            'security_token' => $securityToken,
        ];
    };

    $validateMangoOfficeConfig = function(array $config): array {
        $enabled = !empty($config['enabled']);
        $remoteId = trim((string)($config['remote_id'] ?? ''));
        $securityToken = trim((string)($config['security_token'] ?? ''));

        $errors = [];
        $warnings = [];

        if ($remoteId !== '' && preg_match('/[\x00-\x1F\x7F]/', $remoteId)) {
            $errors[] = 'Remote ID содержит недопустимые символы';
        }
        if ($securityToken !== '' && preg_match('/[\x00-\x1F\x7F]/', $securityToken)) {
            $errors[] = 'Security token содержит недопустимые символы';
        }
        if ($remoteId !== '' && strlen($remoteId) > 128) {
            $errors[] = 'Remote ID слишком длинный';
        }
        if ($securityToken !== '' && strlen($securityToken) > 256) {
            $errors[] = 'Security token слишком длинный';
        }

        if ($enabled) {
            if ($remoteId === '') {
                $errors[] = 'Укажите Remote ID Mango Office';
            }
            if ($securityToken === '') {
                $errors[] = 'Укажите security token Mango Office';
            }
        }
        $ready = $enabled && $errors === [];
        $status = $ready ? 'ok' : ($errors !== [] ? 'error' : 'disabled');
        $statusLabel = $ready ? 'Готово' : ($errors !== [] ? 'Требует внимания' : 'Отключено');
        $validationMessage = $ready
            ? 'Конфигурация Mango Office готова'
            : ($errors !== [] ? implode('; ', $errors) : 'Интеграция отключена');

        return [
            'enabled' => $enabled,
            'remote_id' => $remoteId,
            'security_token_configured' => $securityToken !== '',
            'ready' => $ready,
            'status' => $status,
            'status_label' => $statusLabel,
            'validation_message' => $validationMessage,
            'validation_errors' => $errors,
            'validation_warnings' => $warnings,
        ];
    };

    $buildMangoOfficeSettingsSnapshot = function(?array $raw = null) use ($getMangoOfficeConfig, $validateMangoOfficeConfig): array {
        $validation = $validateMangoOfficeConfig($getMangoOfficeConfig($raw));

        return [
            'mango_office_enabled' => $validation['enabled'] ? '1' : '0',
            'mango_office_remote_id' => $validation['remote_id'],
            'mango_office_security_token' => '',
            'mango_office_security_token_configured' => $validation['security_token_configured'] ? '1' : '0',
            'mango_office_ready' => $validation['ready'] ? '1' : '0',
            'mango_office_status' => $validation['status'],
            'mango_office_status_label' => $validation['status_label'],
            'mango_office_validation_message' => $validation['validation_message'],
            'mango_office_validation_errors' => $validation['validation_errors'],
            'mango_office_validation_warnings' => $validation['validation_warnings'],
        ];
    };

    $sanitizeSiteWidgetPayload = function(array $data) use ($siteWidgetDefaults, $sanitizeText, $sanitizeSize): array {
        $brandColor = strtoupper(trim((string)($data['brand_color'] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $brandColor)) {
            $brandColor = '#2563EB';
        }

        return [
            'api_base' => $sanitizeText($data['api_base'] ?? '', 255, ''),
            'position' => ($data['position'] ?? 'right') === 'left' ? 'left' : 'right',
            'contact_url' => $sanitizeText($data['contact_url'] ?? '', 500, $siteWidgetDefaults['site_widgets_contact_url']),
            'contact_label' => $sanitizeText($data['contact_label'] ?? '', 120, $siteWidgetDefaults['site_widgets_contact_label']),
            'contact_description' => $sanitizeText($data['contact_description'] ?? '', 255, $siteWidgetDefaults['site_widgets_contact_description']),
            'form_width' => $sanitizeSize($data['form_width'] ?? 480, 480, 320, 960),
            'form_height' => $sanitizeSize($data['form_height'] ?? 760, 760, 420, 1200),
            'chat_width' => $sanitizeSize($data['chat_width'] ?? 420, 420, 320, 640),
            'chat_height' => $sanitizeSize($data['chat_height'] ?? 760, 760, 420, 1200),
            'chat_title' => $sanitizeText($data['chat_title'] ?? '', 120, $siteWidgetDefaults['site_widgets_chat_title']),
            'chat_description' => $sanitizeText($data['chat_description'] ?? '', 255, $siteWidgetDefaults['site_widgets_chat_description']),
            'brand_color' => $brandColor,
            'brand_button_text' => $sanitizeText($data['brand_button_text'] ?? '', 24, '💬'),
            'brand_form_title' => $sanitizeText($data['brand_form_title'] ?? '', 120, $siteWidgetDefaults['site_widgets_brand_form_title']),
            'brand_form_description' => $sanitizeText($data['brand_form_description'] ?? '', 255, $siteWidgetDefaults['site_widgets_brand_form_description'])
        ];
    };

    $slugifyProfileName = function(string $value) use ($sanitizeText): string {
        $value = strtolower($sanitizeText($value, 120, 'profile'));
        $value = str_replace([' ', '_'], '-', $value);
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim((string)$value, '-');
        return $value !== '' ? $value : 'profile';
    };

    $ensureSiteWidgetProfilesTable = function() use ($pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_widget_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            config_json LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_site_widget_profile_slug (slug),
            INDEX idx_site_widget_profiles_active (is_active),
            INDEX idx_site_widget_profiles_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $stmt = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_widget_profiles' LIMIT 1");
            $stmt->execute();
            $collation = (string)($stmt->fetchColumn() ?: '');

            if ($collation !== '' && stripos($collation, 'utf8mb4_') !== 0) {
                $pdo->exec("ALTER TABLE site_widget_profiles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {
            error_log('settings.php: failed to align site_widget_profiles collation: ' . $e->getMessage());
        }
    };

    $decodeSiteWidgetProfileConfig = function($json) use ($normalizeSiteWidgetConfig): array {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return $normalizeSiteWidgetConfig($decoded);
    };

    $syncLegacySettingsFromProfile = function(array $config) use ($upsertSetting): void {
        $mapping = [
            'site_widgets_api_base' => 'api_base',
            'site_widgets_position' => 'position',
            'site_widgets_contact_url' => 'contact_url',
            'site_widgets_contact_label' => 'contact_label',
            'site_widgets_contact_description' => 'contact_description',
            'site_widgets_form_width' => 'form_width',
            'site_widgets_form_height' => 'form_height',
            'site_widgets_chat_width' => 'chat_width',
            'site_widgets_chat_height' => 'chat_height',
            'site_widgets_chat_title' => 'chat_title',
            'site_widgets_chat_description' => 'chat_description',
            'site_widgets_brand_color' => 'brand_color',
            'site_widgets_brand_button_text' => 'brand_button_text',
            'site_widgets_brand_form_title' => 'brand_form_title',
            'site_widgets_brand_form_description' => 'brand_form_description'
        ];

        foreach ($mapping as $legacyKey => $configKey) {
            $upsertSetting($legacyKey, (string)($config[$configKey] ?? ''));
        }
    };

    $ensureSiteWidgetProfilesInitialized = function() use ($pdo, $ensureSiteWidgetProfilesTable, $loadSiteWidgetSettings, $sanitizeSiteWidgetPayload): void {
        $ensureSiteWidgetProfilesTable();

        $count = (int)$pdo->query("SELECT COUNT(*) FROM site_widget_profiles")->fetchColumn();
        if ($count === 0) {
            $legacyConfig = $sanitizeSiteWidgetPayload($loadSiteWidgetSettings());
            $stmt = $pdo->prepare("INSERT INTO site_widget_profiles (name, slug, is_active, config_json) VALUES (?, ?, 1, ?)");
            $stmt->execute([
                'Основной профиль',
                'default',
                json_encode($legacyConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
            return;
        }

        $activeIds = $pdo->query("SELECT id FROM site_widget_profiles WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (count($activeIds) !== 1) {
            $keepId = (int)($activeIds[0] ?? $pdo->query("SELECT id FROM site_widget_profiles ORDER BY id ASC LIMIT 1")->fetchColumn());
            $pdo->exec("UPDATE site_widget_profiles SET is_active = 0");
            if ($keepId > 0) {
                $stmt = $pdo->prepare("UPDATE site_widget_profiles SET is_active = 1 WHERE id = ?");
                $stmt->execute([$keepId]);
            }
        }
    };

    $getSiteWidgetProfiles = function() use ($pdo, $ensureSiteWidgetProfilesInitialized, $decodeSiteWidgetProfileConfig): array {
        $ensureSiteWidgetProfilesInitialized();
        $stmt = $pdo->query("SELECT id, name, slug, is_active, config_json, created_at, updated_at FROM site_widget_profiles ORDER BY is_active DESC, updated_at DESC, id ASC");
        $profiles = [];
        foreach ($stmt->fetchAll() as $row) {
            $profiles[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'slug' => (string)$row['slug'],
                'is_active' => (bool)$row['is_active'],
                'config' => $decodeSiteWidgetProfileConfig($row['config_json']),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        return $profiles;
    };

    $findSiteWidgetProfile = function($selector = null) use ($getSiteWidgetProfiles): ?array {
        $profiles = $getSiteWidgetProfiles();
        if ($selector !== null && $selector !== '') {
            $selector = (string)$selector;
            foreach ($profiles as $profile) {
                if ((string)$profile['id'] === $selector || strcmp((string)$profile['slug'], $selector) === 0) {
                    return $profile;
                }
            }
        }
        foreach ($profiles as $profile) {
            if (!empty($profile['is_active'])) {
                return $profile;
            }
        }
        return $profiles[0] ?? null;
    };

    $getSiteWidgetSettingsBundle = function($selector = null) use ($getSiteWidgetProfiles, $findSiteWidgetProfile): array {
        $profiles = $getSiteWidgetProfiles();
        $currentProfile = $findSiteWidgetProfile($selector);
        $activeProfile = $findSiteWidgetProfile(null);

        return [
            'profiles' => $profiles,
            'active_profile_id' => $activeProfile['id'] ?? null,
            'current_profile_id' => $currentProfile['id'] ?? ($activeProfile['id'] ?? null),
            'current_profile' => $currentProfile,
            'active_profile' => $activeProfile
        ];
    };

    if ($method === 'GET' && $action === null) {
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `key`, value FROM settings");
        $stmt->execute();
        $settings = $stmt->fetchAll();

        $settingsData = [];
        foreach ($settings as $setting) {
            $key = (string)$setting['key'];
            if ($key === 'referral_shared_secret' || $key === 'woocommerce_api_consumer_secret'
                || $key === 'omni_tg_bot_token' || $key === 'omni_max_bot_token'
                || $key === 'omni_tg_webhook_secret' || $key === 'omni_max_webhook_secret') {
                $settingsData[$key] = '';
                continue;
            }
            if (!$canManageSettings && ($key === 'weather_api_key' || $key === 'woocommerce_api_consumer_key')) {
                $settingsData[$key] = '';
                continue;
            }

            $settingsData[$key] = $setting['value'];
        }

        $referralSharedSecretSource = $getReferralSharedSecretSource();
        $settingsData['referral_shared_secret'] = '';
        $settingsData['referral_shared_secret_configured'] = $referralSharedSecretSource === 'none' ? '0' : '1';
        $settingsData['referral_shared_secret_source'] = $referralSharedSecretSource;
        $settingsData = array_merge($settingsData, $buildMangoOfficeSettingsSnapshot());
        $settingsData = array_merge($settingsData, $loadOmnichannelSettingsSnapshot());
        $settingsData = array_merge($settingsData, $loadWebrtcSettingsSnapshot());

        echo json_encode([
            'success' => true,
            'data' => $settingsData
        ]);
        exit;
    }

    if ($method === 'GET' && $action !== null) {
        if ($action === 'site-widgets-public') {
            $profile = $findSiteWidgetProfile($_GET['profile'] ?? $id ?? null);
            if (!$profile) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Профиль виджета не найден'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'success' => true,
                'data' => $profile['config'] + [
                    'profile_id' => $profile['id'],
                    'profile_slug' => $profile['slug'],
                    'profile_name' => $profile['name']
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }

        if ($action === 'weather') {
            $ensureSettingsTableUtf8mb4();
            $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE BINARY `key` IN ('weather_api_key', 'weather_city')");
            $settings = $stmt->fetchAll();

            $weatherSettings = [];
            foreach ($settings as $s) {
                $key = (string)$s['key'];
                if ($key === 'weather_api_key' && !$canManageSettings) {
                    $weatherSettings[$key] = '';
                    continue;
                }

                $weatherSettings[$key] = $s['value'];
            }

            echo json_encode(['success' => true, 'data' => $weatherSettings]);
            exit;
        }

        if ($action === 'mango-office') {
            if (!$canManageSettings) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут просматривать настройки'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $buildMangoOfficeSettingsSnapshot()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'diagnostics') {
            if (!$canManageSettings) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут просматривать диагностику'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $projectRoot = dirname(__DIR__);
            $logsDir = __DIR__ . '/logs';
            $apiIndexPath = __DIR__ . '/index.php';
            $authPath = __DIR__ . '/auth.php';
            $crmPath = __DIR__ . '/crm.php';
            $configPath = __DIR__ . '/config.php';
            $manifestPath = $projectRoot . '/manifest.json';
            $serviceWorkerPath = $projectRoot . '/sw.js';
            $boxReadinessDefinitions = [
                'entry_points' => [
                    ['key' => 'install_php', 'path' => $projectRoot . '/install.php', 'label' => 'Install entry point', 'severity' => 'warning'],
                    ['key' => 'index_php', 'path' => $projectRoot . '/index.php', 'label' => 'Main PHP entry', 'severity' => 'warning'],
                    ['key' => 'default_php', 'path' => $projectRoot . '/default.php', 'label' => 'Default PHP entry', 'severity' => 'warning'],
                    ['key' => 'index_html', 'path' => $projectRoot . '/index.html', 'label' => 'Static HTML entry', 'severity' => 'warning'],
                ],
                'delivery_artifacts' => [
                    ['key' => 'manifest_json', 'path' => $projectRoot . '/manifest.json', 'label' => 'manifest.json', 'severity' => 'warning'],
                    ['key' => 'sw_js', 'path' => $projectRoot . '/sw.js', 'label' => 'sw.js', 'severity' => 'warning'],
                    ['key' => 'favicon_png', 'path' => $projectRoot . '/favicon.png', 'label' => 'favicon.png', 'severity' => 'warning'],
                    ['key' => 'robots_txt', 'path' => $projectRoot . '/robots.txt', 'label' => 'robots.txt', 'severity' => 'warning'],
                    ['key' => 'app_png', 'path' => $projectRoot . '/app.png', 'label' => 'app.png', 'severity' => 'warning'],
                    ['key' => 'api_htaccess', 'path' => __DIR__ . '/.htaccess', 'label' => 'api/.htaccess', 'severity' => 'warning'],
                ],
                'project_directories' => [
                    ['key' => 'docs_dir_box', 'path' => $projectRoot . '/docs', 'label' => 'docs/', 'type' => 'dir', 'severity' => 'warning'],
                    ['key' => 'instructions_dir_box', 'path' => $projectRoot . '/instructions', 'label' => 'instructions/', 'type' => 'dir', 'severity' => 'warning'],
                    ['key' => 'widgets_dir_box', 'path' => $projectRoot . '/widgets', 'label' => 'widgets/', 'type' => 'dir', 'severity' => 'warning'],
                    ['key' => 'tools_dir_box', 'path' => $projectRoot . '/tools', 'label' => 'tools/', 'type' => 'dir', 'severity' => 'warning'],
                    ['key' => 'uploads_dir_box', 'path' => $projectRoot . '/uploads', 'label' => 'uploads/', 'type' => 'dir', 'severity' => 'warning'],
                ],
            ];

            $checkDefinitions = [
                'api_router' => ['path' => $apiIndexPath, 'label' => 'API router'],
                'auth_baseline' => ['path' => $authPath, 'label' => 'Auth baseline'],
                'crm_module' => ['path' => $crmPath, 'label' => 'CRM модуль'],
                'config' => ['path' => $configPath, 'label' => 'Конфигурация'],
                'manifest' => ['path' => $manifestPath, 'label' => 'PWA manifest'],
                'service_worker' => ['path' => $serviceWorkerPath, 'label' => 'Service worker'],
            ];

            $directoryDefinitions = [
                'assets_dir' => ['path' => $projectRoot . '/assets', 'label' => 'Assets'],
                'uploads_dir' => ['path' => $projectRoot . '/uploads', 'label' => 'Uploads'],
                'widgets_dir' => ['path' => $projectRoot . '/widgets', 'label' => 'Widgets'],
                'docs_dir' => ['path' => $projectRoot . '/docs', 'label' => 'Docs'],
                'instructions_dir' => ['path' => $projectRoot . '/instructions', 'label' => 'Instructions'],
                'tools_dir' => ['path' => $projectRoot . '/tools', 'label' => 'Tools'],
            ];

            $checks = [];
            $overallOk = true;
            $warningCount = 0;
            $errorCount = 0;

            $addCheck = static function(string $key, bool $ok, string $message, array $meta = []) use (&$checks, &$overallOk, &$warningCount, &$errorCount): void {
                $checks[$key] = array_merge([
                    'ok' => $ok,
                    'message' => $message,
                    'severity' => $ok ? 'ok' : 'warning',
                ], $meta);

                if (!$ok) {
                    $overallOk = false;
                    if (($checks[$key]['severity'] ?? 'warning') === 'error') {
                        $errorCount++;
                    } else {
                        $warningCount++;
                    }
                }
            };

            foreach ($checkDefinitions as $key => $definition) {
                $exists = is_file($definition['path']);
                $readable = $exists && is_readable($definition['path']);
                $relativePath = ltrim(str_replace('\\', '/', substr($definition['path'], strlen($projectRoot))), '/');

                $addCheck(
                    $key,
                    $readable,
                    $readable ? ($definition['label'] . ' доступен') : ($exists ? ($definition['label'] . ' не читается') : ($definition['label'] . ' не найден')),
                    [
                        'path' => $relativePath,
                        'exists' => $exists,
                        'readable' => $readable,
                        'group' => 'files',
                        'severity' => in_array($key, ['api_router', 'auth_baseline', 'crm_module', 'config'], true) ? 'error' : 'warning',
                    ]
                );
            }

            try {
                $pdo->query('SELECT 1');
                $addCheck('db_connection', true, 'Подключение к БД активно', ['group' => 'environment', 'severity' => 'error']);
            } catch (Throwable $e) {
                $addCheck('db_connection', false, 'Ошибка подключения к БД', ['group' => 'environment', 'severity' => 'error']);
            }

            $authLoaded = function_exists('getCurrentUser');
            $addCheck(
                'auth_runtime',
                $authLoaded,
                $authLoaded ? 'Auth runtime доступен в текущем процессе' : 'Auth runtime не инициализирован',
                ['path' => 'api/auth.php', 'group' => 'environment', 'severity' => 'error']
            );

            foreach ($directoryDefinitions as $key => $definition) {
                $exists = is_dir($definition['path']);
                $relativePath = ltrim(str_replace('\\', '/', substr($definition['path'], strlen($projectRoot))), '/');
                $itemsCount = null;

                if ($exists) {
                    $items = @scandir($definition['path']);
                    if (is_array($items)) {
                        $itemsCount = count(array_values(array_diff($items, ['.', '..'])));
                    }
                }

                $addCheck(
                    $key,
                    $exists,
                    $exists ? ($definition['label'] . ' доступна') : ($definition['label'] . ' отсутствует'),
                    [
                        'path' => $relativePath,
                        'exists' => $exists,
                        'items_count' => $itemsCount,
                        'group' => 'directories',
                        'severity' => in_array($key, ['assets_dir', 'uploads_dir', 'widgets_dir'], true) ? 'error' : 'warning',
                    ]
                );
            }

            $logsExists = is_dir($logsDir);
            $logsWritable = $logsExists && is_writable($logsDir);
            $addCheck(
                'logs_dir',
                $logsWritable,
                $logsWritable ? 'Директория логов доступна на запись' : ($logsExists ? 'Директория логов не доступна на запись' : 'Директория логов отсутствует'),
                ['path' => 'api/logs', 'exists' => $logsExists, 'writable' => $logsWritable, 'group' => 'writable_paths', 'severity' => 'warning']
            );

            $uploadsWritable = is_dir($projectRoot . '/uploads') && is_writable($projectRoot . '/uploads');
            $addCheck(
                'uploads_writable',
                $uploadsWritable,
                $uploadsWritable ? 'Uploads доступны на запись' : 'Uploads не доступны на запись',
                ['path' => 'uploads', 'exists' => is_dir($projectRoot . '/uploads'), 'writable' => $uploadsWritable, 'group' => 'writable_paths', 'severity' => 'warning']
            );

            $phpVersion = PHP_VERSION;
            $summaryStatus = $errorCount > 0 ? 'error' : ($warningCount > 0 ? 'warning' : 'ok');
            $boxReadinessSignals = [];
            $boxMissingItems = [];
            $boxWarningItems = [];
            $boxOkCount = 0;

            foreach ($boxReadinessDefinitions as $group => $items) {
                foreach ($items as $item) {
                    $type = $item['type'] ?? 'file';
                    $exists = $type === 'dir' ? is_dir($item['path']) : is_file($item['path']);
                    $readable = $exists && is_readable($item['path']);
                    $relativePath = ltrim(str_replace('\\', '/', substr($item['path'], strlen($projectRoot))), '/');
                    $itemsCount = null;

                    if ($type === 'dir' && $exists) {
                        $dirItems = @scandir($item['path']);
                        if (is_array($dirItems)) {
                            $itemsCount = count(array_values(array_diff($dirItems, ['.', '..'])));
                        }
                    }

                    $ok = $type === 'dir' ? $exists : $readable;
                    $message = $ok
                        ? ($item['label'] . ' подтвержден' . ($type === 'dir' ? 'а' : ''))
                        : ($exists ? ($item['label'] . ' найден, но не читается') : ($item['label'] . ' отсутствует'));

                    $signal = [
                        'key' => $item['key'],
                        'label' => $item['label'],
                        'path' => $relativePath,
                        'group' => $group,
                        'type' => $type,
                        'ok' => $ok,
                        'exists' => $exists,
                        'readable' => $readable,
                        'severity' => $item['severity'] ?? 'warning',
                        'message' => $message,
                    ];

                    if ($itemsCount !== null) {
                        $signal['items_count'] = $itemsCount;
                    }

                    if ($ok) {
                        $boxOkCount++;
                    } else {
                        $boxMissingItems[] = $item['label'];
                        if (($item['severity'] ?? 'warning') !== 'error') {
                            $boxWarningItems[] = $item['label'];
                        }
                    }

                    $boxReadinessSignals[] = $signal;
                }
            }

            $boxReadinessStatus = count($boxMissingItems) > 0 ? 'warning' : 'ok';

            echo json_encode([
                'success' => true,
                'data' => [
                    'overall_ok' => $overallOk,
                    'status' => $summaryStatus,
                    'generated_at' => gmdate('c'),
                    'summary' => [
                        'status' => $summaryStatus,
                        'ok_count' => count(array_filter($checks, static fn(array $check): bool => ($check['ok'] ?? false) === true)),
                        'warning_count' => $warningCount,
                        'error_count' => $errorCount,
                        'total_count' => count($checks),
                    ],
                    'environment' => [
                        'php_version' => $phpVersion,
                        'sapi' => PHP_SAPI,
                        'os_family' => PHP_OS_FAMILY,
                    ],
                    'box_readiness' => [
                        'status' => $boxReadinessStatus,
                        'summary' => [
                            'ok_count' => $boxOkCount,
                            'missing_count' => count($boxMissingItems),
                            'warning_count' => count($boxWarningItems),
                            'total_count' => count($boxReadinessSignals),
                        ],
                        'missing_items' => $boxMissingItems,
                        'warning_items' => $boxWarningItems,
                        'signals' => $boxReadinessSignals,
                    ],
                    'checks' => $checks,
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'site-widgets') {
            echo json_encode([
                'success' => true,
                'data' => $getSiteWidgetSettingsBundle($_GET['profile'] ?? $_GET['profile_id'] ?? $id ?? null) + [
                    'can_manage' => $canManageSettings
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $key = $action;
        $ensureSettingsTableUtf8mb4();
        $quotedKey = $quoteSqlString($key);
        $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE BINARY `key` = $quotedKey");
        $setting = $stmt->fetch();

        if (!$setting) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Настройка не найдена']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'key' => $setting['key'],
                'value' => $setting['value']
            ]
        ]);
        exit;
    }

    if ($method === 'PUT' && $action !== null) {
        if ($action === 'weather') {
            if (!$canManageSettings) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ожидается JSON объект'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ensureSettingsTableUtf8mb4();

            $beforeWeather = [
                'weather_api_key' => $loadSettingValue('weather_api_key'),
                'weather_city' => $loadSettingValue('weather_city'),
            ];

            if (array_key_exists('weather_api_key', $data)) {
                $stmt = $pdo->prepare("UPDATE settings SET value = ? WHERE BINARY `key` = 'weather_api_key'");
                $stmt->execute([$data['weather_api_key']]);
            }

            if (array_key_exists('weather_city', $data)) {
                $stmt = $pdo->prepare("UPDATE settings SET value = ? WHERE BINARY `key` = 'weather_city'");
                $stmt->execute([$data['weather_city']]);
            }

            auditLog($pdo, 'settings.weather.updated', [
                'actor' => $currentUser,
                'target_type' => 'settings',
                'target_id' => 'weather',
                'summary' => 'Обновлены настройки погоды',
                'details' => [
                    'before' => $beforeWeather,
                    'changed_keys' => array_values(array_keys($data)),
                ],
            ]);

            echo json_encode(['success' => true, 'message' => 'Настройки погоды обновлены']);
            exit;
        }

        if ($action === 'site-widgets') {
            if (!$canManageSettings) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ожидается JSON объект'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($id === 'activate') {
                $profileId = (int)($data['profile_id'] ?? 0);
                if ($profileId <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Укажите корректный профиль'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $ensureSiteWidgetProfilesInitialized();
                $stmt = $pdo->prepare("SELECT config_json FROM site_widget_profiles WHERE id = ? LIMIT 1");
                $stmt->execute([$profileId]);
                $configJson = $stmt->fetchColumn();
                if ($configJson === false) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Профиль виджета не найден'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $pdo->exec("UPDATE site_widget_profiles SET is_active = 0");
                $stmt = $pdo->prepare("UPDATE site_widget_profiles SET is_active = 1 WHERE id = ?");
                $stmt->execute([$profileId]);
                $syncLegacySettingsFromProfile($decodeSiteWidgetProfileConfig($configJson));

                auditLog($pdo, 'settings.site_widgets.activated', [
                    'actor' => $currentUser,
                    'target_type' => 'site_widget_profile',
                    'target_id' => (string)$profileId,
                    'summary' => 'Активирован профиль виджета сайта',
                    'details' => [
                        'profile_id' => $profileId,
                    ],
                ]);

                echo json_encode(['success' => true, 'data' => $getSiteWidgetSettingsBundle($profileId)], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ensureSiteWidgetProfilesInitialized();
            $profileId = (int)($data['profile_id'] ?? 0);
            if ($profileId <= 0) {
                $activeProfile = $findSiteWidgetProfile(null);
                $profileId = (int)($activeProfile['id'] ?? 0);
            }

            if ($profileId <= 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Профиль виджета не найден'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $payload = $sanitizeSiteWidgetPayload($data);
            $stmt = $pdo->prepare("UPDATE site_widget_profiles SET config_json = ? WHERE id = ?");
            $stmt->execute([
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $profileId
            ]);

            $profile = $findSiteWidgetProfile($profileId);
            if ($profile && !empty($profile['is_active'])) {
                $syncLegacySettingsFromProfile($payload);
            }

            auditLog($pdo, 'settings.site_widgets.updated', [
                'actor' => $currentUser,
                'target_type' => 'site_widget_profile',
                'target_id' => (string)$profileId,
                'summary' => 'Обновлена конфигурация виджета сайта',
                'details' => [
                    'profile_id' => $profileId,
                    'is_active_profile' => !empty($profile['is_active']),
                ],
            ]);

            echo json_encode(['success' => true, 'data' => $getSiteWidgetSettingsBundle($profileId)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'mango-office' && $id === 'test') {
            if (!$canManageSettings) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут проверять настройки'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = [];
            }

            $snapshot = $buildMangoOfficeSettingsSnapshot($data);

            auditLog($pdo, 'settings.mango_office.tested', [
                'actor' => $currentUser,
                'target_type' => 'settings',
                'target_id' => 'mango-office',
                'summary' => 'Проверена конфигурация Mango Office',
                'details' => [
                    'ready' => $snapshot['mango_office_ready'],
                    'status' => $snapshot['mango_office_status'],
                    'errors' => $snapshot['mango_office_validation_errors'],
                    'warnings' => $snapshot['mango_office_validation_warnings'],
                ],
            ]);

            echo json_encode(['success' => true, 'data' => $snapshot], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$canManageSettings) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки']);
            exit;
        }

        $key = $action;
        $data = json_decode(file_get_contents('php://input'), true);
        $ensureSettingsTableUtf8mb4();
        $previousValue = $loadSettingValue($key);

        if (!isset($data['value'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите значение']);
            exit;
        }

        $quotedKey = $quoteSqlString($key);
        $stmt = $pdo->query("SELECT `key` FROM settings WHERE BINARY `key` = $quotedKey");

        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Настройка не найдена']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE settings SET value = ? WHERE BINARY `key` = $quotedKey");
        $stmt->execute([$data['value']]);

        auditLog($pdo, 'settings.value.updated', [
            'actor' => $currentUser,
            'target_type' => 'setting',
            'target_id' => $key,
            'summary' => 'Изменена системная настройка',
            'details' => [
                'key' => $key,
                'old_value' => $previousValue,
                'new_value' => $data['value'],
            ],
        ]);

        echo json_encode([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $data['value']
            ]
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'site-widgets') {
        if (!$canManageSettings) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ожидается JSON объект'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ensureSiteWidgetProfilesInitialized();
        $name = $sanitizeText($data['name'] ?? '', 150, 'Новый профиль');
        $baseSlug = $slugifyProfileName((string)($data['slug'] ?? $name));
        $slug = $baseSlug;
        $suffix = 2;
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM site_widget_profiles WHERE slug COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci");
        do {
            $existsStmt->execute([$slug]);
            $exists = (int)$existsStmt->fetchColumn() > 0;
            if ($exists) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }
        } while ($exists);

        $sourceProfile = $findSiteWidgetProfile($data['clone_from_profile_id'] ?? null);
        $config = $sanitizeSiteWidgetPayload($sourceProfile['config'] ?? $loadSiteWidgetSettings());

        $stmt = $pdo->prepare("INSERT INTO site_widget_profiles (name, slug, is_active, config_json) VALUES (?, ?, 0, ?)");
        $stmt->execute([
            $name,
            $slug,
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['success' => true, 'data' => $getSiteWidgetSettingsBundle($newId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === null) {
        if (!$canManageSettings) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Только пользователи с админ-доступом могут изменять настройки']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $ensureSettingsTableUtf8mb4();

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ожидаются данные в формате JSON объект']);
            exit;
        }

        $updated = [];

        $mangoPayloadPresent = array_key_exists('mango_office_enabled', $data)
            || array_key_exists('mango_office_remote_id', $data)
            || array_key_exists('mango_office_security_token', $data);
        if ($mangoPayloadPresent) {
            $mangoSnapshot = $buildMangoOfficeSettingsSnapshot($data);
            if (!empty($mangoSnapshot['mango_office_validation_errors'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $mangoSnapshot['mango_office_validation_message'],
                    'data' => $mangoSnapshot,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // NOTE: use UPSERT so new keys (like company_name/app_name) are persisted even
        // when they were not pre-created in the settings table.
        $stmt = $pdo->prepare(
            "INSERT INTO settings (`key`, value) VALUES (?, ?)\n"
            . "ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );

        $normalizeSettingValue = function($value): string {
            if (is_bool($value)) return $value ? '1' : '0';
            if ($value === null) return '';
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return (string)$value;
        };

        foreach ($data as $key => $value) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }

            $normalizedValue = $normalizeSettingValue($value);
            $isSecretKey = ($key === 'referral_shared_secret'
                || $key === 'woocommerce_api_consumer_secret'
                || $key === 'mango_office_security_token'
                || $key === 'omni_tg_bot_token'
                || $key === 'omni_max_bot_token'
                || $key === 'omni_tg_webhook_secret'
                || $key === 'omni_max_webhook_secret');

            if ($isSecretKey) {
                $normalizedValue = trim($normalizedValue);

                // Empty secret fields mean "keep existing" (UI sends blanks by default).
                if ($normalizedValue === '') {
                    continue;
                }

                try {
                    $normalizedValue = (string)(appEncrypt($normalizedValue) ?? '');
                } catch (Throwable $e) {
                    error_log('settings.php: failed to encrypt secret setting, storing plaintext fallback: ' . $e->getMessage());
                }
            }

            $stmt->execute([$key, $normalizedValue]);
            $updated[] = $key;
        }

        auditLog($pdo, 'settings.bulk.updated', [
            'actor' => $currentUser,
            'target_type' => 'settings',
            'target_id' => 'bulk',
            'summary' => 'Массово обновлены системные настройки',
            'details' => [
                'updated_keys' => $updated,
            ],
        ]);

        echo json_encode(['success' => true, 'updated' => $updated]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Endpoint не найден']);
}
