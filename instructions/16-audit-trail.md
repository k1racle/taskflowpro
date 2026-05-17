# Audit trail minimum viable

Этот слой добавляет минимально рискованный системный аудит для коробочной эксплуатации: без SIEM, без тяжёлой платформенной обвязки, но уже с реальной пользой для расследования инцидентов и базовой ops-поддержки.

## Что логируется на текущем шаге

- успешный логин (`auth.login.success`);
- неуспешный логин (`auth.login.failed`);
- срабатывание login throttling / временной блокировки (`auth.login.throttled`);
- смена роли пользователя (`rbac.user_role.changed`);
- изменение прав роли (`rbac.role_permissions.changed`);
- изменение системных настроек:
  - погода (`settings.weather.updated`),
  - одиночная настройка (`settings.value.updated`),
  - массовое обновление (`settings.bulk.updated`),
  - профиль виджета сайта (`settings.site_widgets.updated`, `settings.site_widgets.activated`);
- удаление ключевых сущностей:
  - пользователь (`entity.user.deleted`),
  - задача (`entity.task.deleted`),
  - проект (`entity.project.deleted`).

## Где хранятся записи

После применения миграций создаётся таблица `audit_log`.

Каждая запись содержит минимум:

- тип события (`event_type`);
- кто выполнил действие (`actor_user_id`, `actor_login`, `actor_role`);
- над чем выполнено действие (`target_type`, `target_id`);
- краткое описание (`summary`);
- структурированные детали (`details_json`);
- контекст HTTP-запроса (`request_method`, `request_path`, `ip_address`, `user_agent`);
- время (`created_at`).

## Retention policy первого слоя

Для коробочного minimum viable retention foundation введено дефолтное окно хранения:

- `AUDIT_RETENTION_DAYS` в `api/config.php`;
- значение по умолчанию: `180` дней;
- при необходимости может быть переопределено через environment variable `AUDIT_RETENTION_DAYS`.

Это не означает автоматическое удаление по cron внутри продукта. На текущем шаге policy фиксируется как операционный контракт:

1. записи старше retention window при необходимости сначала выгружаются через CLI;
2. после проверки выгрузки старые записи удаляются отдельной CLI-командой prune;
3. автоматическую планировочную ротацию команда может добавить на уровне инфраструктуры позже, когда появится agreed cron/ops policy.

## Как писать новые audit-события

Используйте единый helper:

```php
auditLog($pdo, 'some.event.type', [
    'actor' => $currentUser,
    'target_type' => 'user',
    'target_id' => '123',
    'summary' => 'Короткое описание события',
    'details' => [
        'before' => '...',
        'after' => '...'
    ],
]);
```

Практические правила для первого шага:

- вызывать helper только в точках действительно значимых действий;
- не логировать пароли, токены, секреты и лишние payload целиком;
- по возможности писать `before/after` только для важных полей;
- не строить сложную абстракцию поверх `auditLog(...)`, пока coverage ещё маленький.

## Как смотреть журнал

Есть простой административный endpoint:

```bash
GET /api/audit/recent?limit=50
```

На текущем шаге у него появились минимальные фильтры для ops/support:

- `limit` - сколько последних записей вернуть, от `1` до `200`;
- `event_type` - точный тип события, например `auth.login.failed` или `auth.login.throttled`;
- `actor` - поиск по логину актёра, а также по `summary` и `details_json`; подходит и для логина пользователя при разборе жалоб на вход;
- `target_type` - точный тип сущности;
- `target_id` - точный идентификатор сущности;
- `date_from` - нижняя граница периода по `created_at`;
- `date_to` - верхняя граница периода по `created_at`.

Поддерживаемые форматы даты:

- `YYYY-MM-DD`
- `YYYY-MM-DD HH:MM:SS`

Примеры:

```bash
GET /api/audit/recent?event_type=auth.login.throttled&limit=20
GET /api/audit/recent?actor=ivanov&limit=50
GET /api/audit/recent?event_type=auth.login.failed&date_from=2026-05-07&date_to=2026-05-07
GET /api/audit/recent?target_type=user&target_id=15
```

Доступ:

- только авторизованный пользователь;
- только `root` или пользователь с `admin.full`.

Ответ возвращает последние записи в обратном хронологическом порядке и поле `filters` с применёнными параметрами.

Дополнительно в `meta.retention` API теперь возвращает:

- `retention_days` - активное retention window;
- `cutoff_before` - дата-время, старше которой записи уже считаются кандидатами на export/prune по текущему policy.

## Как смотреть активные login lockout / throttling state

Для minimum viable operational visibility добавлен отдельный read-only endpoint:

```bash
GET /api/audit/lockouts?limit=50
```

Он возвращает только активные блокировки из `auth_login_throttle`, то есть записи, у которых `lock_expires_at > NOW()`.

Параметры:

- `limit` - сколько активных записей вернуть, от `1` до `200`;
- `login` - фильтр по логину.

Примеры:

```bash
GET /api/audit/lockouts
GET /api/audit/lockouts?login=ivanov
```

В ответе доступны:

- `login`;
- `ip_address`;
- `failed_attempts`;
- `first_failed_at`;
- `last_failed_at`;
- `lock_expires_at`;
- `remaining_seconds`.

## Как использовать при расследовании инцидентов

На первом этапе журнал отвечает хотя бы на базовые вопросы:

- кто заходил в систему;
- по какому логину и с какого IP шли неуспешные попытки входа;
- когда сработала временная блокировка логина;
- кто менял роли и права;
- кто менял системные настройки;
- кто удалял ключевые сущности.

Типовой порядок разбора:

1. открыть последние записи `/api/audit/recent`;
2. при необходимости отфильтровать по `event_type`, `actor`, `target_type`, `target_id`, периоду;
3. для жалоб на блокировку сначала проверить `/api/audit/lockouts?login=<логин>`, затем `auth.login.failed` и `auth.login.throttled`, поля `details.login`, `details.ip_address`, `details.failed_attempts`, `details.remaining_seconds`;
4. сопоставить время события с жалобой пользователя, ошибками API и release/change window;
5. при необходимости дополнить картину данными из `api/logs/` и backup/restore runbook.

## CLI: выгрузка audit log

Для надёжной ops-работы без UI добавлен отдельный CLI helper:

```bash
php tools/audit.php export
```

Поведение по умолчанию:

- формат: `jsonl`;
- вывод: файл в `backups/audit_exports/`;
- сортировка: по возрастанию `id`, чтобы выгрузка была предсказуемой для архива/последующей обработки;
- если нужен вывод в stdout, это делается явно через `--stdout`.

Поддерживаемые фильтры:

- `--event-type=...`
- `--actor=...`
- `--login=...`
- `--target-type=...`
- `--target-id=...`
- `--ip-address=...`
- `--date-from=YYYY-MM-DD`
- `--date-to=YYYY-MM-DD`
- `--limit=1000`

Примеры:

```bash
php tools/audit.php export
php tools/audit.php export --event-type=auth.login.failed --date-from=2026-05-01 --date-to=2026-05-07
php tools/audit.php export --actor=ivanov --output=backups/audit_exports/ivanov-audit.jsonl
php tools/audit.php export --target-type=user --target-id=15 --stdout
```

Файл `jsonl` содержит по одной JSON-записи на строку. Это проще и надёжнее для больших хвостов, чем пытаться строить UI или держать весь export в памяти.

## CLI: retention / prune / maintenance

Посмотреть текущий retention policy:

```bash
php tools/audit.php retention
```

Получить безопасный dry-run/report по регулярному обслуживанию audit:

```bash
php tools/audit.php maintenance
```

Этот режим нужен как минимальный ops-layer поверх уже существующего foundation:

- по умолчанию ничего не экспортирует и ничего не удаляет;
- показывает `cutoff_before`, число кандидатов, временной диапазон и planned export path;
- подходит как для ручной проверки, так и для cron/scheduler usage, где нужен понятный summary;
- с `--force` выполняет связку `export -> prune` для одного и того же retention cutoff.

Сделать безопасный dry-run cleanup:

```bash
php tools/audit.php prune
```

По умолчанию prune:

- использует `AUDIT_RETENTION_DAYS`;
- работает в `dry-run`, пока явно не передан `--force`;
- показывает количество кандидатов, а также `oldest_created_at` и `newest_created_at` для диапазона удаления.

Примеры:

```bash
php tools/audit.php prune
php tools/audit.php prune --retention-days=365
php tools/audit.php prune --before=2025-11-01
php tools/audit.php prune --force
php tools/audit.php maintenance
php tools/audit.php maintenance --retention-days=365
php tools/audit.php maintenance --before=2025-11-01
php tools/audit.php maintenance --force
php tools/audit.php maintenance --force --output=backups/audit_exports/monthly-audit-archive.jsonl
```

Рекомендуемая последовательность эксплуатации:

1. `php tools/audit.php maintenance`;
2. проверить summary и planned export path;
3. выполнить либо `php tools/audit.php maintenance --force`, либо ручную связку `export -> prune --dry-run -> prune --force`;
4. сохранить export-файл в штатное архивное/backup-хранилище инсталляции.

### Как запускать регулярное обслуживание audit

Pragmatic minimum viable routine:

1. оставить `AUDIT_RETENTION_DAYS` как базовый retention contract, если нет отдельного требования комплаенса;
2. запускать `php tools/audit.php maintenance` регулярно как безопасный dry-run/report;
3. перед фактическим удалением убедиться, что export path корректный и не указывает на публично доступный каталог;
4. затем запускать `php tools/audit.php maintenance --force` или ручную последовательность, если нужен больший контроль.

Если нужен предсказуемый путь к архиву, лучше явно указывать output:

```bash
php tools/audit.php maintenance --force --output=backups/audit_exports/audit-monthly-2026-05.jsonl
```

### Как встроить в cron / планировщик

Встроенного scheduler внутри продукта по-прежнему нет, поэтому routine подключается на уровне ОС, панели хостинга или внешнего orchestrator.

Минимально полезная схема:

- регулярный dry-run report:

```bash
php tools/audit.php maintenance >> logs/audit-maintenance.log 2>&1
```

- отдельный подтверждённый job на реальный cleanup:

```bash
php tools/audit.php maintenance --force --output=backups/audit_exports/audit-maintenance-YYYYMMDD.jsonl >> logs/audit-maintenance.log 2>&1
```

Практические замечания:

- `maintenance` без `--force` безопасен и не меняет данные;
- вывод идёт в компактном `key: value` формате, пригодном для логов и grep;
- каталог `backups/` на production не должен быть публично доступен из веба;
- если retention policy отличается от дефолта, его лучше задавать явно через `AUDIT_RETENTION_DAYS` или `--retention-days`.

### Параметры и допущения retention policy

- дефолтное окно хранения: `180` дней;
- источник значения: `AUDIT_RETENTION_DAYS` в `api/config.php` или environment variable `AUDIT_RETENTION_DAYS`;
- `--retention-days=N` позволяет временно переопределить retention window для конкретного запуска;
- `--before=...` задаёт явную границу export/prune и имеет приоритет над retention window;
- `maintenance --force` сначала пишет export-файл, потом делает prune;
- внешняя долговременная архивация export-файла остаётся зоной ответственности ops/infrastructure, а не продукта.

## Ограничения текущего foundation

- coverage намеренно неполный: логируются не все CRUD-операции;
- UI-экрана поиска и фильтрации по-прежнему нет; visibility сейчас остаётся на API/ops-уровне;
- нет встроенного scheduler/cron orchestration для автоматического export/prune; есть только CLI routine, которую нужно подключать инфраструктурно;
- archive strategy остаётся файловой и внешней: продукт умеет выгрузить данные, но не управляет внешним object storage/SIEM;
- нет tamper-proof механизма, внешней отправки, webhook/SIEM и alerting;
- нет обязательного correlation id между audit, app errors и бизнес-историей;
- часть legacy/старых потоков может менять данные без audit, пока точечно не доинструментируем их.

## Что логично делать следующим

Следующий практичный шаг после этого foundation:

1. расширить coverage на другие критичные удаления и admin-операции;
2. связать retention/export routine с формальным cron/regламентом конкретной инсталляции;
3. связать audit с security hardening checklist и incident response runbook;
4. при необходимости добавить очень маленький internal ops screen поверх уже существующих endpoint'ов, без отдельного большого админ-проекта.
