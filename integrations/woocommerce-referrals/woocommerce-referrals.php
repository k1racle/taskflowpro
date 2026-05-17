<?php
/**
 * Plugin Name: WorkHub CRM Woo Referrals
 * Description: MVP referral attribution for WooCommerce with CRM webhook sync.
 * Version: 0.1.0
 * Author: WorkHub
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WorkHub_Woo_Referrals {
    private const OPTION_KEY = 'workhub_woo_referrals_options';
    private const COOKIE_NAME = 'workhub_referral_code';
    private const SETTINGS_PAGE = 'workhub-woo-referrals';
    private const TEST_ACTION = 'workhub_test_crm_connection';
    private const SYNC_ORDERS_ACTION = 'workhub_sync_existing_orders';
    private const TEST_RESULT_TRANSIENT_PREFIX = 'workhub_woo_referrals_test_result_';

    public static function init(): void {
        $instance = new self();
        add_action('admin_menu', [$instance, 'register_admin_page']);
        add_action('admin_init', [$instance, 'register_settings']);
        add_action('admin_post_' . self::TEST_ACTION, [$instance, 'handle_test_connection']);
        add_action('admin_post_' . self::SYNC_ORDERS_ACTION, [$instance, 'handle_sync_existing_orders']);
        add_action('init', [$instance, 'capture_referral_param']);
        add_action('woocommerce_checkout_create_order', [$instance, 'attach_referral_meta'], 10, 2);
        add_action('woocommerce_order_status_processing', [$instance, 'send_order_webhook']);
        add_action('woocommerce_order_status_completed', [$instance, 'send_order_webhook']);
        add_action('woocommerce_order_status_on-hold', [$instance, 'send_order_webhook']);
    }

    public function register_admin_page(): void {
        add_options_page(
            'WorkHub Woo Referrals',
            'WorkHub Woo Referrals',
            'manage_options',
            self::SETTINGS_PAGE,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('workhub_woo_referrals', self::OPTION_KEY, [$this, 'sanitize_settings']);

        add_settings_section(
            'workhub_woo_referrals_main',
            'Настройки интеграции',
            [$this, 'render_main_section'],
            self::SETTINGS_PAGE
        );

        $fields = [
            'crm_webhook_url' => 'CRM webhook URL',
            'shared_secret' => 'Shared secret',
            'cookie_ttl_days' => 'Cookie TTL (дней)',
            'track_visits' => 'Отправлять visit event',
        ];

        foreach ($fields as $field => $label) {
            add_settings_field($field, $label, [$this, 'render_field'], self::SETTINGS_PAGE, 'workhub_woo_referrals_main', ['field' => $field]);
        }
    }

    public function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];

        $rawWebhookUrl = isset($input['crm_webhook_url']) ? trim((string)$input['crm_webhook_url']) : '';
        $webhookUrl = $rawWebhookUrl !== '' ? esc_url_raw($rawWebhookUrl) : '';

        if ($rawWebhookUrl !== '' && $webhookUrl === '') {
            add_settings_error(
                self::OPTION_KEY,
                'invalid_crm_webhook_url',
                'CRM webhook URL сохранён не был: укажите корректный абсолютный URL.',
                'error'
            );
        }

        $cookieTtlDays = max(1, (int)($input['cookie_ttl_days'] ?? 30));

        if ($cookieTtlDays !== (int)($input['cookie_ttl_days'] ?? 30)) {
            add_settings_error(
                self::OPTION_KEY,
                'invalid_cookie_ttl_days',
                'Cookie TTL должен быть целым числом не меньше 1 дня. Значение было скорректировано автоматически.',
                'warning'
            );
        }

        return [
            'crm_webhook_url' => $webhookUrl,
            'shared_secret' => isset($input['shared_secret']) ? trim((string)$input['shared_secret']) : '',
            'cookie_ttl_days' => $cookieTtlDays,
            'track_visits' => !empty($input['track_visits']) ? 1 : 0,
        ];
    }

    public function render_main_section(): void {
        echo '<p>Укажите параметры CRM webhook и поведение трекинга переходов по реферальным ссылкам.</p>';
    }

    public function render_field(array $args): void {
        $field = (string)($args['field'] ?? '');
        $options = $this->get_options();

        if ($field === 'track_visits') {
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($field); ?>]" value="1" <?php checked(!empty($options[$field])); ?>>
                Отправлять visit webhook при заходе по ref-ссылке
            </label>
            <p class="description">Полезно для CRM, если нужно видеть сам факт перехода до оформления заказа.</p>
            <?php
            return;
        }

        $type = $field === 'shared_secret' ? 'password' : ($field === 'cookie_ttl_days' ? 'number' : 'url');

        $value = (string)($options[$field] ?? '');
        $inputClass = $field === 'cookie_ttl_days' ? 'small-text' : 'regular-text code';
        $attrs = '';

        if ($field === 'cookie_ttl_days') {
            $attrs = ' min="1" step="1"';
        } elseif ($field === 'crm_webhook_url') {
            $attrs = ' placeholder="https://crm.example.com/api/referrals/webhook/woocommerce"';
        } elseif ($field === 'shared_secret') {
            $attrs = ' autocomplete="new-password" placeholder="shared-secret-from-crm"';
        }

        printf(
            '<input type="%1$s" class="%2$s" name="%3$s[%4$s]" value="%5$s"%6$s />',
            esc_attr($type),
            esc_attr($inputClass),
            esc_attr(self::OPTION_KEY),
            esc_attr($field),
            esc_attr($value),
            $attrs
        );

        if ($field === 'crm_webhook_url') {
            echo '<p class="description">URL, куда WooCommerce отправляет данные по заказам с реферальной атрибуцией.</p>';
        } elseif ($field === 'shared_secret') {
            echo '<p class="description">Должен совпадать с секретом, настроенным на стороне CRM для проверки заголовка X-Referral-Secret.</p>';
        } elseif ($field === 'cookie_ttl_days') {
            echo '<p class="description">Сколько дней хранить referral code в cookie после перехода по ref-ссылке.</p>';
        }
    }

    public function render_settings_page(): void {
        $options = $this->get_options();
        $webhookUrl = trim((string)($options['crm_webhook_url'] ?? ''));
        $visitUrl = $this->get_visit_webhook_url($webhookUrl);
        $sharedSecret = trim((string)($options['shared_secret'] ?? ''));
        $cookieTtlDays = max(1, (int)($options['cookie_ttl_days'] ?? 30));
        $trackingEnabled = !empty($options['track_visits']);
        $exampleLandingUrl = home_url('/shop/?ref=PARTNER123');
        $settingsSaved = $webhookUrl !== '' && $sharedSecret !== '';
        $testResult = $this->consume_test_connection_result();

        ?>
        <div class="wrap">
            <h1>WorkHub Woo Referrals</h1>

            <?php settings_errors(self::OPTION_KEY); ?>
            <?php $this->render_test_connection_notice($testResult); ?>

            <div style="max-width: 1100px; display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 20px; align-items: start;">
                <div>
                    <div class="postbox" style="padding: 20px; margin: 0 0 20px;">
                        <h2 style="margin-top: 0;">Настройки плагина</h2>
                        <form method="post" action="options.php">
                            <?php
                            settings_fields('workhub_woo_referrals');
                            do_settings_sections(self::SETTINGS_PAGE);
                            submit_button('Сохранить настройки');
                            ?>
                        </form>
                    </div>

                    <div class="postbox" style="padding: 20px; margin: 0 0 20px;">
                        <h2 style="margin-top: 0;">Тест соединения с CRM</h2>
                        <p style="margin-top: 0;">Проверка отправляет в текущий <strong>CRM webhook URL</strong> специальный тестовый POST-запрос с заголовком <code>X-Referral-Secret</code> и заведомо тестовым referral code, которого не должно быть в CRM. Это позволяет безопасно проверить ответ endpoint и реакцию на текущий shared secret без создания реального referral order.</p>
                        <p><strong>Что проверяется:</strong> доступность endpoint, HTTP-ответ CRM, принят ли текущий shared secret, доходит ли запрос до бизнес-валидации CRM.</p>
                        <p><strong>Что не проверяется:</strong> реальная атрибуция заказа, существование рабочего referral code, создание корректного заказа в CRM и полный сценарий оформления покупки.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>">
                            <?php wp_nonce_field('workhub_test_crm_connection'); ?>
                            <?php submit_button('Тест соединения с CRM', 'secondary', 'submit', false); ?>
                        </form>
                    </div>

                    <div class="postbox" style="padding: 20px; margin: 0 0 20px;">
                        <h2 style="margin-top: 0;">Синхронизация существующих заказов</h2>
                        <p style="margin-top: 0;">Если плагин был установлен позже, старые WooCommerce-заказы сами не попадут в CRM. Эта кнопка отправляет уже существующие заказы повторно тем же webhook, не ломая текущую реферальную логику.</p>
                        <p><strong>Что попадёт в CRM:</strong> заказы в статусах <code>processing</code>, <code>completed</code> и <code>on-hold</code>. CRM сама защитится от дублей по external order id.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::SYNC_ORDERS_ACTION); ?>">
                            <?php wp_nonce_field('workhub_sync_existing_orders'); ?>
                            <?php submit_button('Синхронизировать существующие заказы', 'secondary', 'submit', false); ?>
                        </form>
                    </div>

                    <div class="postbox" style="padding: 20px; margin: 0;">
                        <h2 style="margin-top: 0;">Инструкция по подключению</h2>

                        <h3>1. Что указать в CRM</h3>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li>Создайте или проверьте endpoint для webhook заказов WooCommerce.</li>
                            <li>Сохраните shared secret, который CRM будет ожидать в заголовке <code>X-Referral-Secret</code>.</li>
                            <li>Если CRM принимает события визитов, убедитесь, что visit endpoint тоже доступен.</li>
                        </ul>

                        <h3>2. Что указать в WooCommerce</h3>
                        <ul style="list-style: disc; padding-left: 20px;">
                            <li>Вставьте CRM webhook URL в поле <strong>CRM webhook URL</strong>.</li>
                            <li>Вставьте тот же shared secret, который настроен в CRM.</li>
                            <li>Задайте срок хранения cookie и при необходимости включите отправку visit event.</li>
                        </ul>

                        <h3>3. Пример реферальной ссылки</h3>
                        <p>Плагин отслеживает параметр <code>ref</code>. Пример ссылки:</p>
                        <p><code><?php echo esc_html($exampleLandingUrl); ?></code></p>

                        <h3>4. Как проверить тестовый заказ</h3>
                        <ol style="padding-left: 20px;">
                            <li>Откройте сайт по ref-ссылке из примера выше.</li>
                            <li>Добавьте товар в корзину и оформите тестовый заказ.</li>
                            <li>Убедитесь, что заказ перешёл в статус <code>processing</code>, <code>completed</code> или <code>on-hold</code>.</li>
                            <li>Проверьте, что CRM получила webhook с <code>referral_code</code> и данными заказа.</li>
                        </ol>

                        <h3>5. Если ссылка не работает</h3>
                        <ul style="list-style: disc; padding-left: 20px; margin-bottom: 0;">
                            <li>Проверьте, что в URL действительно есть параметр <code>ref</code>.</li>
                            <li>Проверьте, что cookie не блокируются браузером или кэширующими/безопасностными плагинами.</li>
                            <li>Убедитесь, что <strong>CRM webhook URL</strong> и <strong>Shared secret</strong> заполнены без ошибок.</li>
                            <li>Если visit tracking включён, проверьте доступность visit endpoint на стороне CRM.</li>
                            <li>Если заказ создан, но не отправился, проверьте, достиг ли он одного из поддерживаемых статусов.</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <div class="postbox" style="padding: 20px; margin: 0 0 20px;">
                        <h2 style="margin-top: 0;">Статус интеграции</h2>
                        <table class="widefat striped" style="border: 0; box-shadow: none;">
                            <tbody>
                                <tr>
                                    <td style="width: 42%;"><strong>Настройки</strong></td>
                                    <td><?php echo $settingsSaved ? '<span style="color: #008a20;">Заполнены</span>' : '<span style="color: #b32d2e;">Заполнены не полностью</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Order webhook</strong></td>
                                    <td><code><?php echo esc_html($webhookUrl !== '' ? $webhookUrl : 'не задан'); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Visit webhook</strong></td>
                                    <td><code><?php echo esc_html($visitUrl !== '' ? $visitUrl : 'не вычислен'); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Track visits</strong></td>
                                    <td><?php echo $trackingEnabled ? 'Включён' : 'Выключен'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cookie TTL</strong></td>
                                    <td><?php echo esc_html((string)$cookieTtlDays); ?> дн.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="postbox" style="padding: 20px; margin: 0;">
                        <h2 style="margin-top: 0;">Краткая памятка</h2>
                        <p style="margin-top: 0;">Плагин делает две вещи:</p>
                        <ol style="padding-left: 20px; margin-bottom: 0;">
                            <li>Сохраняет referral code из параметра <code>ref</code> в cookie.</li>
                            <li>При заказе отправляет в CRM webhook с кодом и данными заказа.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_test_connection(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав для выполнения этого действия.');
        }

        check_admin_referer('workhub_test_crm_connection');

        $options = $this->get_options();
        $url = trim((string)($options['crm_webhook_url'] ?? ''));
        $secret = trim((string)($options['shared_secret'] ?? ''));

        if ($url === '' || $secret === '') {
            $this->store_test_connection_result([
                'type' => 'error',
                'title' => 'Тест не выполнен.',
                'message' => 'Сначала заполните и сохраните CRM webhook URL и Shared secret.',
            ]);
            $this->redirect_to_settings_page();
        }

        $this->store_test_connection_result($this->test_crm_connection($url, $secret));
        $this->redirect_to_settings_page();
    }

    public function handle_sync_existing_orders(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав для выполнения этого действия.');
        }

        check_admin_referer('workhub_sync_existing_orders');

        $options = $this->get_options();
        $url = trim((string)($options['crm_webhook_url'] ?? ''));
        $secret = trim((string)($options['shared_secret'] ?? ''));

        if ($url === '' || $secret === '') {
            $this->store_test_connection_result([
                'type' => 'error',
                'title' => 'Синхронизация не выполнена.',
                'message' => 'Сначала заполните и сохраните CRM webhook URL и Shared secret.',
            ]);
            $this->redirect_to_settings_page();
        }

        if (!function_exists('wc_get_orders')) {
            $this->store_test_connection_result([
                'type' => 'error',
                'title' => 'Синхронизация не выполнена.',
                'message' => 'WooCommerce API для выборки заказов недоступен.',
            ]);
            $this->redirect_to_settings_page();
        }

        $orderIds = wc_get_orders([
            'limit' => 200,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['wc-processing', 'wc-completed', 'wc-on-hold'],
        ]);

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            $result = $this->send_order_webhook((int)$orderId, true);
            if ($result === 'sent') {
                $sent++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        $type = $failed > 0 ? 'warning' : 'success';
        $title = $failed > 0 ? 'Синхронизация завершена с предупреждениями.' : 'Синхронизация заказов завершена.';
        $message = sprintf(
            'Обработано заказов: %d. Отправлено: %d. Пропущено: %d. Ошибок отправки: %d.',
            count($orderIds),
            $sent,
            $skipped,
            $failed
        );

        if (!$orderIds) {
            $type = 'warning';
            $title = 'Нет заказов для синхронизации.';
            $message = 'WooCommerce не вернул ни одного заказа в статусах processing, completed или on-hold.';
        }

        $this->store_test_connection_result([
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ]);
        $this->redirect_to_settings_page();
    }

    public function capture_referral_param(): void {
        $ref = isset($_GET['ref']) ? strtoupper(sanitize_text_field(wp_unslash((string)$_GET['ref']))) : '';
        if ($ref === '') {
            return;
        }

        $options = $this->get_options();
        $ttlDays = max(1, (int)($options['cookie_ttl_days'] ?? 30));
        $expires = time() + ($ttlDays * DAY_IN_SECONDS);

        wc_setcookie(self::COOKIE_NAME, $ref, $expires, true, true);

        if (!empty($options['track_visits'])) {
            $this->send_visit_webhook($ref);
        }
    }

    public function attach_referral_meta(WC_Order $order): void {
        $ref = isset($_COOKIE[self::COOKIE_NAME]) ? strtoupper(sanitize_text_field(wp_unslash((string)$_COOKIE[self::COOKIE_NAME]))) : '';
        if ($ref === '') {
            return;
        }

        $order->update_meta_data('_workhub_referral_code', $ref);
    }

    public function send_order_webhook(int $orderId, bool $force = false): string {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return 'failed';
        }

        $ref = strtoupper(trim((string)$order->get_meta('_workhub_referral_code')));

        $options = $this->get_options();
        $url = trim((string)($options['crm_webhook_url'] ?? ''));
        $secret = trim((string)($options['shared_secret'] ?? ''));
        if ($url === '' || $secret === '') {
            return 'skipped';
        }

        if (!$force && !in_array($order->get_status(), ['processing', 'completed', 'on-hold'], true)) {
            return 'skipped';
        }

        $payload = [
            'source' => 'woocommerce',
            'referral_code' => $ref !== '' ? $ref : null,
            'external_order_id' => (string)$order->get_id(),
            'order_number' => (string)$order->get_order_number(),
            'order_status' => (string)$order->get_status(),
            'currency' => (string)$order->get_currency(),
            'total_amount' => (float)$order->get_total(),
            'subtotal_amount' => (float)$order->get_subtotal(),
            'shipping_amount' => (float)$order->get_shipping_total(),
            'discount_amount' => (float)$order->get_discount_total(),
            'customer_email' => (string)$order->get_billing_email(),
            'customer_phone' => (string)$order->get_billing_phone(),
            'customer_name' => trim((string)$order->get_formatted_billing_full_name()),
            'order_created_at' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
            'attributed_at' => current_time('mysql'),
            'order_items' => $this->build_order_items_payload($order),
        ];

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Referral-Secret' => $secret,
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($response)) {
            return 'failed';
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode >= 200 && $statusCode < 300) {
            return 'sent';
        }

        return 'failed';
    }

    private function build_order_items_payload(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $quantity = (float)$item->get_quantity();
            $lineTotal = (float)$item->get_total();
            $unitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.0;

            $items[] = [
                'id' => (string)$itemId,
                'product_id' => (string)$item->get_product_id(),
                'variation_id' => (string)$item->get_variation_id(),
                'sku' => $product ? (string)$product->get_sku() : '',
                'name' => (string)$item->get_name(),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'currency' => (string)$order->get_currency(),
            ];
        }

        return $items;
    }

    private function send_visit_webhook(string $ref): void {
        $options = $this->get_options();
        $baseUrl = trim((string)($options['crm_webhook_url'] ?? ''));
        $secret = trim((string)($options['shared_secret'] ?? ''));
        if ($baseUrl === '' || $secret === '') {
            return;
        }

        $visitUrl = $this->get_visit_webhook_url($baseUrl);
        if ($visitUrl === '') {
            return;
        }

        $payload = [
            'source' => 'woocommerce',
            'referral_code' => $ref,
            'landing_url' => home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')),
            'referrer_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash((string)$_SERVER['HTTP_REFERER'])) : null,
            'visitor_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string)$_SERVER['REMOTE_ADDR'])) : null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash((string)$_SERVER['HTTP_USER_AGENT'])), 0, 255) : null,
            'visit_token' => wp_generate_uuid4(),
        ];

        wp_remote_post($visitUrl, [
            'timeout' => 5,
            'blocking' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Referral-Secret' => $secret,
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function test_crm_connection(string $url, string $secret): array {
        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Referral-Secret' => $secret,
            ],
            'body' => wp_json_encode($this->build_test_connection_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($response)) {
            return [
                'type' => 'error',
                'title' => 'Соединение с CRM не установлено.',
                'message' => sprintf('WordPress не смог отправить запрос: %s', $response->get_error_message()),
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $statusMessage = trim((string) wp_remote_retrieve_response_message($response));
        $responseBody = $this->get_response_excerpt(wp_remote_retrieve_body($response));
        $httpLabel = 'HTTP ' . $statusCode . ($statusMessage !== '' ? ' ' . $statusMessage : '');

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'type' => 'success',
                'title' => 'CRM ответила на тестовый запрос.',
                'message' => $httpLabel . '. Endpoint доступен, shared secret принят, а CRM обработала тестовый payload без транспортной ошибки.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
            ];
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return [
                'type' => 'error',
                'title' => 'CRM отклонила тестовый запрос.',
                'message' => $httpLabel . '. Похоже, shared secret не принят или доступ к endpoint ограничен.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
            ];
        }

        if ($statusCode === 404 && stripos($responseBody, 'Referral code') !== false) {
            return [
                'type' => 'success',
                'title' => 'CRM ответила и корректно отклонила тестовый referral code.',
                'message' => $httpLabel . '. Это ожидаемый безопасный результат: endpoint доступен, shared secret принят, запрос дошёл до бизнес-валидации CRM, но тестовый referral code не найден и реальный referral order не был создан.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
            ];
        }

        if ($statusCode === 404 || $statusCode === 405) {
            return [
                'type' => 'error',
                'title' => 'CRM endpoint ответил ошибкой маршрута.',
                'message' => $httpLabel . '. Похоже, CRM webhook URL указан неверно либо endpoint не принимает POST.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
            ];
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            return [
                'type' => 'warning',
                'title' => 'CRM ответила, но тестовый запрос не был принят полностью.',
                'message' => $httpLabel . '. Это значит, что endpoint доступен, но CRM отвергла или не приняла тестовый payload. URL и shared secret могут быть корректны, но бизнес-валидация не пройдена.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
            ];
        }

        return [
            'type' => 'error',
            'title' => 'CRM ответила серверной ошибкой.',
            'message' => $httpLabel . '. Endpoint доступен, но на стороне CRM произошла ошибка обработки.' . ($responseBody !== '' ? ' Ответ: ' . $responseBody : ''),
        ];
    }

    private function build_test_connection_payload(): array {
        return [
            'source' => 'woocommerce',
            'referral_code' => 'WH-CONNECTION-TEST',
            'external_order_id' => 'workhub-connection-test-' . gmdate('YmdHis'),
            'order_number' => 'connection-test',
            'order_status' => 'test',
            'currency' => get_woocommerce_currency(),
            'total_amount' => 0,
            'customer_email' => 'connection-test@example.invalid',
            'customer_phone' => '',
            'order_created_at' => current_time('mysql'),
            'attributed_at' => current_time('mysql'),
            'is_connection_test' => true,
            'test_mode' => true,
        ];
    }

    private function get_response_excerpt(string $body): string {
        $body = trim(wp_strip_all_tags($body));
        if ($body === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            $excerpt = mb_substr($body, 0, 240);
            return $excerpt !== $body ? $excerpt . '...' : $excerpt;
        }

        $excerpt = substr($body, 0, 240);
        return $excerpt !== $body ? $excerpt . '...' : $excerpt;
    }

    private function get_options(): array {
        $options = get_option(self::OPTION_KEY, []);
        return is_array($options) ? $options : [];
    }

    private function store_test_connection_result(array $result): void {
        set_transient(
            self::TEST_RESULT_TRANSIENT_PREFIX . get_current_user_id(),
            $result,
            5 * MINUTE_IN_SECONDS
        );
    }

    private function consume_test_connection_result(): ?array {
        if (!isset($_GET['workhub_crm_test_result'])) {
            return null;
        }

        $transientKey = self::TEST_RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $result = get_transient($transientKey);
        delete_transient($transientKey);

        return is_array($result) ? $result : null;
    }

    private function render_test_connection_notice(?array $result): void {
        if (!is_array($result)) {
            return;
        }

        $type = (string)($result['type'] ?? 'info');
        $noticeClass = 'notice-info';

        if ($type === 'success') {
            $noticeClass = 'notice-success';
        } elseif ($type === 'warning') {
            $noticeClass = 'notice-warning';
        } elseif ($type === 'error') {
            $noticeClass = 'notice-error';
        }

        $title = trim((string)($result['title'] ?? ''));
        $message = trim((string)($result['message'] ?? ''));
        ?>
        <div class="notice <?php echo esc_attr($noticeClass); ?> is-dismissible">
            <?php if ($title !== '') : ?>
                <p><strong><?php echo esc_html($title); ?></strong></p>
            <?php endif; ?>
            <?php if ($message !== '') : ?>
                <p><?php echo esc_html($message); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function redirect_to_settings_page(): void {
        wp_safe_redirect(add_query_arg([
            'page' => self::SETTINGS_PAGE,
            'workhub_crm_test_result' => 1,
        ], admin_url('options-general.php')));
        exit;
    }

    private function get_visit_webhook_url(string $baseUrl): string {
        $visitUrl = preg_replace('#/webhook/woocommerce/?$#', '/visit', $baseUrl);

        if (!is_string($visitUrl) || $visitUrl === '') {
            return '';
        }

        return $visitUrl;
    }
}

WorkHub_Woo_Referrals::init();
