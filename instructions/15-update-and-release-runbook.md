# Update/release runbook foundation

Этот документ собирает уже добавленные foundation pieces в один практический порядок обновления коробки.

Основа runbook опирается на уже существующие команды:

- `php tools/migrate.php preflight`
- `php tools/migrate.php plan`
- `php tools/backup.php create`
- `php tools/restore.php inspect`
- `php tools/restore.php restore-files`
- `php tools/restore.php restore-db`
- `php tools/restore.php restore-all`
- `php tools/audit.php retention`
- `php tools/audit.php export`
- `php tools/audit.php prune`
- `php tools/smoke.php --base-url=http://<host>`
- `GET /api/health`
- `GET /api/ready`

Документ специально остаётся минимальным и безопасным: без сложной orchestration-автоматики, но с понятным ручным порядком действий для команды.

## Когда использовать этот runbook

Используйте его для любого обновления коробки, которое затрагивает хотя бы одно из следующего:

- PHP-код в `api/` или другие серверные части приложения;
- миграции из `migrations/`;
- данные или runtime-файлы, где возможен rollback через backup/restore.

Если обновление совсем маленькое и не затрагивает БД, backup и post-check всё равно желательны, но обязательными остаются хотя бы проверка состава релиза и базовый smoke-test.

## Audit housekeeping в эксплуатационном контуре

После появления minimum viable audit retention/export foundation у ops-команды есть ещё четыре штатные CLI-точки:

```bash
php tools/audit.php retention
php tools/audit.php export
php tools/audit.php prune
php tools/audit.php maintenance
```

Практический смысл:

- `retention` показывает активное окно хранения и текущий cutoff;
- `export` позволяет безопасно выгрузить audit log в `jsonl` перед архивированием или cleanup;
- `prune` по умолчанию работает в `dry-run` и помогает безопасно проверить, какие записи попадут под удаление.
- `maintenance` даёт единый cron-friendly сценарий: в dry-run режиме печатает summary, а с `--force` делает `export -> prune` для одного и того же cutoff.

Это не обязательный шаг каждого релиза, но уже штатная часть эксплуатационного контура коробки — особенно если на инстансе активный audit trail и нужно контролировать рост таблицы `audit_log`.

Если во время release window нужно быстро и безопасно обслужить audit log, practical порядок такой:

1. `php tools/audit.php maintenance`
2. проверить candidate set и planned export path;
3. `php tools/audit.php maintenance --force --output=...`

Это не заменяет backup перед релизом, а дополняет эксплуатационный контур как отдельный housekeeping routine.

## Minimum viable smoke helper

Для коробочного post-update/pre-release verification теперь есть минимальный CLI helper:

```bash
php tools/smoke.php --base-url=http://<host>
```

Что именно он проверяет:

1. `GET /api/health` -> HTTP `200`, `status: "ok"`, `kind: "liveness"`.
2. `GET /api/ready` -> HTTP `200`, `status: "ok"`, `kind: "readiness"`.
3. `GET /` -> отдаётся базовый HTML shell приложения.
4. `GET /manifest.json` -> доступен публичный manifest базового UI shell.
5. `GET /api/license/status` -> анонимный license check для текущего host, чтобы быстро поймать ситуацию, когда код уже развернули, но домен не проходит license gate.

Интерпретация результатов:

- `result: PASS` — все обязательные анонимные release-foundation checks прошли.
- `result: FAIL` — есть хотя бы один hard failure, релиз нельзя считать верифицированным.
- `pending_migrations` в ответе `/api/ready` helper показывает как warning, а не как hard fail. Это полезно для pre-release контекста, где миграции ещё могут быть не применены. После post-update стадии для релиза с миграциями это поле уже должно быть `0`.

Как запускать practically:

```bash
php tools/smoke.php --base-url=http://localhost/workhub/backup
php tools/smoke.php --base-url=https://crm.example.com --timeout=10
```

Ограничение этого helper'а сознательное: он не выполняет логин, не трогает auth-required UI flows и не проверяет бизнес-сценарии. Это minimum viable release verification, а не полноценный тестовый фреймворк.

## Короткая схема процесса

Базовый безопасный порядок такой:

```bash
php tools/migrate.php preflight
php tools/migrate.php plan
php tools/backup.php create --label=before-release

# далее: развернуть код релиза

php tools/migrate.php migrate

# далее: post-update verification
```

Если любой шаг до `migrate` завершился ошибкой, обновление нужно остановить.

## Pre-update checklist

Перед началом обновления проверьте:

1. Понятно, какой именно релиз ставится и на какой инстанс.
2. Есть окно работ и ответственный за выполнение/наблюдение.
3. Есть доступ к CLI на сервере и рабочие PHP-команды запускаются.
4. Известно, затрагивает ли релиз БД, `uploads/`, `docs/`, `manifest.json`, `api/config.php` или другие runtime-части.
5. Код релиза уже подготовлен и есть понятный способ его развернуть.
6. На диске есть место под backup.
7. Есть путь rollback: понятно, какой backup будет точкой возврата и кто принимает решение об откате.

### Обязательные pre-check команды

Проверить, что приложение вообще отвечает и готово до начала выкладки:

```bash
php tools/smoke.php --base-url=http://<host>
curl -sS http://<host>/api/health
curl -sS http://<host>/api/ready
```

Что считать нормой на этом шаге:

- `/api/health` должен вернуть HTTP `200` и JSON со `status: "ok"`;
- `/api/ready` должен вернуть HTTP `200` и JSON со `status: "ok"`, если инстанс уже находится в рабочем состоянии;
- если `/api/ready` возвращает HTTP `503` или `status: "fail"`, релиз останавливается до разбора причины;
- поле `checks[].details.pending_migrations` можно использовать как справочную информацию, но само по себе наличие pending migrations до релиза не считается аварией.

Проверить миграционный preflight:

```bash
php tools/migrate.php preflight
```

Посмотреть, есть ли pending migrations:

```bash
php tools/migrate.php plan
```

При необходимости заранее посмотреть состав backup:

```bash
php tools/backup.php plan
```

Если `/api/health` или `/api/ready` не проходят, `preflight` не проходит, есть неожиданные pending migrations или backup plan выглядит неверно, релиз останавливается до разбора причины.

## Сценарий 1. Обычное обновление

### Шаг 1. Зафиксировать состояние до релиза

Создайте backup непосредственно перед обновлением:

```bash
php tools/backup.php create --label=before-release
```

Что важно записать в рабочий лог/тикет:

- точный путь к созданному backup-каталогу;
- время начала обновления;
- кто выполняет релиз;
- вывод `migrate plan`, если были pending migrations.

### Шаг 2. Перевести систему в режим минимальной активности

Если возможно организационно:

- предупредите пользователей об окне работ;
- временно остановите активные массовые операции;
- по возможности включите maintenance mode или ограничьте доступ на время выкладки.

Этот шаг пока не автоматизирован в foundation и выполняется способом, принятым на вашем сервере.

### Шаг 3. Развернуть код релиза

Разверните файлы нового релиза своим текущим способом.

Важно:

- не удаляйте только что созданный backup;
- не перетирайте backup-каталог при выкладке;
- если релиз включает новые миграции, код миграций должен быть уже на сервере до запуска `migrate`.

### Шаг 4. Применить миграции

После выкладки кода запустите:

```bash
php tools/migrate.php migrate
```

Если pending migrations не было, runner сообщит `No pending migrations.` — это нормальный результат для релиза без новых схемных изменений.

### Шаг 5. Выполнить post-update verification

Сначала проверьте общее состояние:

```bash
php tools/smoke.php --base-url=http://<host>
curl -sS http://<host>/api/health
curl -sS http://<host>/api/ready
php tools/migrate.php status
```

Затем выполните чеклист из раздела `Post-update verification checklist` ниже.

## Сценарий 2. Обновление с rollback

Rollback нужен, если после выкладки или миграций произошло хотя бы одно из следующего:

- приложение не открывается или ломается критический пользовательский поток;
- миграции завершились ошибкой;
- данные выглядят повреждёнными или несовместимыми с релизом;
- smoke-test показывает, что релиз нельзя оставлять в проде.

### Порядок rollback

1. Остановить дальнейшие изменения в системе и по возможности снова ограничить доступ пользователей.
2. Найти backup, созданный на шаге `before-release`.
3. Проверить его состав:

```bash
php tools/restore.php inspect backups/taskflow_backup_YYYYMMDD_HHMMSS
```

4. Сначала прогнать dry-run полного восстановления:

```bash
php tools/restore.php restore-all backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
```

5. Если dry-run выглядит корректно, выполнить восстановление:

```bash
php tools/restore.php restore-all backups/taskflow_backup_YYYYMMDD_HHMMSS --force
```

6. Если код релиза тоже нужно откатить, верните совместимую предыдущую версию кода вашим штатным способом.
7. После восстановления проверьте состояние:

```bash
php tools/migrate.php status
```

8. Выполните тот же smoke-test, что и после обычного обновления.

### Когда использовать restore-files и restore-db отдельно

- `restore-files` — если проблема только в runtime-файлах (`uploads/`, `docs/`, `config/manifest`), а БД трогать не нужно.
- `restore-db` — если нужно вернуть состояние данных без отдельного восстановления файлов.
- `restore-all` — основной и самый понятный путь для полного rollback в рамках текущего foundation.

Примеры:

```bash
php tools/restore.php restore-files backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
php tools/restore.php restore-files backups/taskflow_backup_YYYYMMDD_HHMMSS --force

php tools/restore.php restore-db backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
php tools/restore.php restore-db backups/taskflow_backup_YYYYMMDD_HHMMSS --force
```

## Сценарий 3. Проверка после обновления

## Post-update verification checklist

Минимальный набор проверок после релиза:

1. `/api/health` возвращает HTTP `200` и `status: "ok"`.
2. `/api/ready` возвращает HTTP `200` и `status: "ok"`.
3. `php tools/smoke.php --base-url=http://<host>` возвращает `result: PASS`.
4. `php tools/migrate.php status` отрабатывает без ошибок.
5. `pending_migrations` равно `0` для релиза, где все миграции уже должны быть применены.
6. Открывается страница входа и выполняется вход под администратором.
7. Загружается основная рабочая страница без фатальных ошибок PHP/JS.
8. Открывается хотя бы один критичный для бизнеса раздел, например CRM, задачи или продажи.
9. Если релиз затрагивал документы или файлы, проверяется чтение/скачивание одного реального файла.
10. Если релиз затрагивал CRM-схему, открывается карточка клиента/сделки и выполняется базовое сохранение тестового изменения, если это допустимо на стенде.
11. Если на сервере есть журналы ошибок, проверяется отсутствие новых критических записей сразу после релиза.
12. Проверяется security baseline: `/api/ready` не показывает неожиданных security failures, а `install.php` и `api/debug.php` либо удалены из production, либо осознанно оставлены только как временное исключение.

### Практический порядок проверки

1. CLI-проверка:

```bash
php tools/smoke.php --base-url=http://<host>
curl -sS http://<host>/api/health
curl -sS http://<host>/api/ready
php tools/migrate.php status
```

2. Browser smoke-test:

- вход;
- главная страница;
- один критичный бизнес-сценарий;
- один сценарий, затронутый релизом напрямую.

Дополнительно для deploy/admin:

- проверить HTTPS-выдачу;
- проверить secure attributes у `jwt_token` cookie на HTTPS;
- посмотреть security-related checks в `/api/ready`.

3. Если что-то сломано, не затягивать с решением: либо быстро исправлять, либо переходить к rollback, пока состояние изменения ещё хорошо контролируется.

## Что этот foundation покрывает

Теперь foundation закрывает:

- единый порядок pre-check -> backup -> deploy -> migrate -> verify;
- обязательную связку `preflight` и `plan` до обновления;
- обязательный rollback path через `restore inspect` и `restore-*`;
- базовые liveness/readiness endpoint'ы для release и эксплуатации;
- минимальный post-update smoke helper для анонимной release verification;
- понятную точку фиксации backup перед выкладкой.

## Что пока не покрыто

В этот этап сознательно не входят:

- автоматическое переключение maintenance mode;
- автоматический deploy кода;
- auth-required UI e2e и полноценные бизнесовые smoke-tests;
- глубокие dependency checks (SMTP, внешние API, очереди, cron workers, WebSocket/media узлы);
- политики retention/offsite/encryption для backup;
- пооперационный audit trail релиза.

Это следующий слой зрелости, но не часть текущего foundation.

## Ограничения текущих health/ready проверок

- `/api/health` проверяет только то, что PHP-router и bootstrap отвечают; он специально не зависит от БД.
- `/api/ready` сейчас покрывает только БД и базовые файловые зависимости: `uploads/`, `backups/`, `api/logs/`, `migrations/`, `manifest.json`.
- `tools/smoke.php` специально проверяет только анонимно доступные и безопасные точки: health, ready, базовый UI shell, `manifest.json`, `license/status`.
- `tools/smoke.php` не валидирует JS-исполнение в браузере, не делает login и не проходит бизнес-сценарии под ролью пользователя.
- `/api/ready` не проверяет бизнес-функции, права доступа пользователей, SMTP, интеграции, фоновые процессы и качество данных.
- `pending_migrations` в ответе `/api/ready` сейчас информационное поле, а не жёсткий fail-criterion: до релиза это может быть нормой.
