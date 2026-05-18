# Security baseline foundation

Это минимальный practical security hardening foundation для коробки WorkHub.

Цель этого шага: закрыть самые дешёвые и полезные классы рисков без внедрения тяжёлой security-подсистемы.

## Что harden'ится автоматически

Текущий foundation уже делает следующее:

1. Для API-ответов добавляются базовые security headers:
   - `X-Content-Type-Options: nosniff`
   - `X-Frame-Options: DENY`
   - `Referrer-Policy: no-referrer`
   - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
   - `Cross-Origin-Resource-Policy: same-site`
   - `Strict-Transport-Security`, если текущий запрос идёт по HTTPS
2. Для API сохраняется текущий CORS baseline и no-cache policy.
3. JWT cookie выставляется с `HttpOnly`, `SameSite=Lax`, а `Secure` включается автоматически на HTTPS-запросах.
4. `install.php` автоматически блокируется после того, как инстанс уже настроен (`runtime/install.lock` существует и содержит рабочие DB/JWT значения).
5. `api/debug.php` больше не доступен извне и разрешён только с localhost.
6. В `/api/ready` добавлены runtime security checks, чтобы быстро увидеть очевидные проблемы после деплоя.
7. Для логина включён минимальный brute-force hardening: после `5` неуспешных попыток для сочетания `login + IP` за `10` минут включается временная блокировка на `15` минут.

## Что проверять администратору вручную

Этот foundation сознательно не пытается автоматически менять web-server/system configuration. После деплоя администратор должен проверить:

1. Публичный доступ к приложению идёт только по HTTPS.
2. На reverse proxy / веб-сервере корректно настроен TLS и редирект с HTTP на HTTPS.
3. `install.php` удалён или исключён из публичной выкладки после первичной установки, даже несмотря на автоматическую блокировку.
4. `api/debug.php` удалён с production, если он больше не нужен.
5. `api/config.php` не доступен на чтение через веб-сервер и не попадает в публичные артефакты/репозитории.
6. Права на `uploads/`, `backups/`, `api/logs/` минимально достаточные, без избыточной записи для всех пользователей системы.
7. Backup-каталоги не доступны из веба напрямую.
8. Если используется прокси, он передаёт `X-Forwarded-Proto: https`, чтобы приложение корректно активировало secure-cookie/HSTS на HTTPS-трафике.
9. На production отключены лишние debug endpoints, тестовые дампы и временные скрипты вне текущего foundation.

## Что теперь видно в `/api/ready`

В readiness-ответ добавлены security-related checks:

- `config_present`
- `bootstrap_configured`
- `jwt_secret_configured`
- `https_detection`
- `installer_exposed`
- `debug_endpoint_exposed`

Интерпретация простая:

- `bootstrap_configured.ok=false` означает, что не найден `runtime/install.lock` или он не содержит валидные bootstrap-значения.
- `installer_exposed` показывает, лежит ли `install.php` в проекте. Runtime уже блокирует его после настройки инстанса, но файл всё равно стоит удалить из production-поставки.
- `debug_endpoint_exposed.ok=false` означает, что `api/debug.php` всё ещё присутствует. Он ограничен localhost-only, но для production его лучше убрать совсем.
- `jwt_secret_configured.ok=false` означает критическую проблему конфигурации.

## Secure deployment checklist

Минимальный безопасный чеклист для релиза/развёртывания:

1. `php tools/smoke.php --base-url=https://<host>`
2. `GET /api/health`
3. `GET /api/ready`
4. Убедиться, что приложение доступно по HTTPS.
5. Проверить, что после логина cookie `jwt_token` выставляется как `HttpOnly`; на HTTPS также должен быть `Secure`.
6. Удалить или исключить из production `install.php`, если установка уже завершена.
7. Удалить или исключить из production `api/debug.php`, если он не нужен.
8. Проверить, что `backups/` и чувствительные runtime-файлы не раздаются веб-сервером напрямую.
9. Если пользователь жалуется на временную блокировку входа, сначала проверить `GET /api/audit/lockouts?login=<логин>`, затем `GET /api/audit/recent?event_type=auth.login.throttled&actor=<логин>&limit=50` и связанные события `auth.login.failed` / `auth.login.throttled`.
10. Если используется `php tools/audit.php maintenance --force`, export-файлы audit должны попадать только в защищённый каталог и не быть доступны из веба напрямую.

## Ограничения текущего шага

Сознательно не входят в этот foundation:

- distributed/global rate limiting между несколькими инстансами;
- 2FA;
- captcha;
- сложная session-management система;
- CSP с жёстким policy для всего UI;
- централизованный secrets manager;
- автоматический hardening веб-сервера/OS.

Это следующий этап, если проекту потребуется более глубокая security maturity.
