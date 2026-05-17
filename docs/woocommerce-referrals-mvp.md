# MVP referral integration: CRM <-> WooCommerce

## Что делает MVP

- у клиента в CRM появляется `referral_code`;
- CRM строит referral link на WooCommerce-сайт через admin-настройку `referral_woocommerce_base_url` с fallback на `REFERRAL_WOOCOMMERCE_BASE_URL`;
- WooCommerce plugin ловит `?ref=CODE`, кладёт код в cookie;
- при оформлении заказа referral code пишется в order meta;
- на статусах `processing`, `completed`, `on-hold` плагин отправляет webhook в CRM;
- CRM сохраняет referral order и защищается от дублей по `(external_source, external_order_id)`;
- в карточке клиента видно код, ссылку, переходы, число заказов, сумму и последние заказы.

## Что нужно настроить в CRM

Через CRM settings / config задать:

- `referral_woocommerce_base_url` — базовый URL внешнего WooCommerce сайта в разделе «Администрирование -> Настройки», например `https://shop.example.com/`;
- `referral_shared_secret` — основной shared secret в CRM-настройках;
- `REFERRAL_SHARED_SECRET` — legacy fallback из config/env, если secret не сохранён через settings-layer;
- `REFERRAL_WOOCOMMERCE_BASE_URL` — fallback базового URL из config/env, если admin-настройка не задана.

Если не заданы ни `referral_woocommerce_base_url`, ни `REFERRAL_WOOCOMMERCE_BASE_URL`, CRM всё равно хранит код, но ссылка в UI не соберётся.

## Какие endpoints использовать

### Внутренние CRM endpoints

- `GET /api/index.php?endpoint=crm/clients/{id}/referrals` — referral-данные клиента;
- `POST /api/index.php?endpoint=crm/clients/{id}/referral-code` — сгенерировать referral code при отсутствии.

### Внешние webhook endpoints для WooCommerce

- `POST /api/index.php?endpoint=referrals/webhook/woocommerce`
- `POST /api/index.php?endpoint=referrals/visit`

Оба ожидают header:

- `X-Referral-Secret: <same secret as in CRM>`

Приоритет shared secret:

1. `referral_shared_secret` из CRM settings;
2. `REFERRAL_SHARED_SECRET` из legacy config/env.

Если secret не настроен вообще, CRM не падает, но публичные referral webhook endpoints будут возвращать ошибку конфигурации, пока общий secret не будет задан.

## Как настроить WooCommerce plugin

Файл плагина лежит в:

- `integrations/woocommerce-referrals/woocommerce-referrals.php`

Установка:

1. Скопировать папку в `wp-content/plugins/workhub-woo-referrals/`.
2. Активировать плагин в WordPress.
3. Открыть `Settings -> WorkHub Woo Referrals`.
4. Заполнить:
   - `CRM webhook URL`: `https://crm.example.com/api/index.php?endpoint=referrals/webhook/woocommerce`
   - `Shared secret`: тот же, что задан в CRM-настройках (`referral_shared_secret`) или, если settings-layer не использован, в legacy `REFERRAL_SHARED_SECRET`
   - `Cookie TTL`: например `30`
   - `Track visits`: включить, если нужен MVP click/visit tracking

## Как работает атрибуция

1. Клиент CRM получает код и ссылку.
2. Покупатель приходит на WooCommerce по `?ref=CODE`.
3. Плагин сохраняет `CODE` в cookie.
4. При checkout код попадает в order meta.
5. Когда заказ переходит в `processing` / `completed` / `on-hold`, CRM получает webhook.
6. CRM ищет клиента по `referral_code` и создаёт или обновляет referral order.

## Ограничения MVP

- атрибуция только по одному cookie-коду;
- нет multi-touch / first-touch vs last-touch;
- нет payout/commission logic;
- нет отдельного кабинета партнёра;
- visit tracking best-effort и не обязателен для заказа;
- если заказ создан без cookie/ref code, он не атрибутируется;
- дубли защищены только по `external_source + external_order_id`.

## Рекомендуемый smoke test

1. Применить миграции CRM.
2. Открыть клиента в CRM и нажать `Сгенерировать` у referral code.
3. Перейти по referral link на WooCommerce-сайт.
4. Проверить, что у заказа есть meta `_workhub_referral_code`.
5. Перевести заказ в `processing`.
6. Проверить, что в CRM у клиента появился referral order.
7. Повторно отправить тот же webhook и убедиться, что дубль не создаётся.
