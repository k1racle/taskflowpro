# Версионирование и миграции

Минимальный foundation для обновлений теперь состоит из четырёх частей:

- версия приложения хранится в `api/app_version.php`;
- applied migrations хранятся в таблице `schema_migrations`;
- CLI runner v1 находится в `tools/migrate.php`;
- обязательный backup step перед изменением схемы описан в `tools/backup.php` и `instructions/14-backup-and-restore.md`.

## Где хранится версия

- `APP_VERSION` — в `api/app_version.php`;
- текущая версия схемы БД — это последний `id` в `schema_migrations`;
- целевая версия схемы — это последний файл в каталоге `migrations/`.

## Команды

Перед любыми миграциями сначала нужен backup:

```bash
php tools/backup.php create --label=before-migrate
```

Показать состояние:

```bash
php tools/migrate.php status
```

Применить все pending migrations:

```bash
php tools/migrate.php migrate
```

Показать доступные migration ids:

```bash
php tools/migrate.php list
```

Показать только pending migrations без применения:

```bash
php tools/migrate.php plan
```

Прогнать preflight-проверку перед обновлением:

```bash
php tools/migrate.php preflight
```

Показать backup plan без записи на диск:

```bash
php tools/backup.php plan
```

## Формат миграции

Каждый файл в `migrations/` возвращает массив:

- `id` — монотонно растущий идентификатор миграции;
- `name` — короткое имя;
- `description` — краткое назначение;
- `up` — функция применения.

Текущий runner v1 поддерживает только применение `up`-миграций. Rollback пока не реализован.

## Что добавлено для legacy-safe пути

- `plan` даёт dry-run список pending migrations без изменения БД;
- `preflight` проверяет, что каталог миграций доступен, таблица `schema_migrations` может быть создана, applied migration ids известны текущему коду и файлы следуют принятому шаблону `<id>_<name>.php`;
- duplicate migration ids теперь останавливают runner до применения изменений;
- перед `migrate` теперь предполагается обязательный backup DB + критичных файлов.

## Рекомендуемый flow обновления

Минимальный безопасный порядок такой:

```bash
php tools/migrate.php preflight
php tools/backup.php create --label=before-migrate
php tools/migrate.php plan
php tools/migrate.php migrate
```

Если `preflight` не проходит или backup не создался, обновление нужно остановить до исправления проблемы.

Полный пошаговый operational flow для обычного обновления, rollback и post-update verification теперь собран отдельно в `instructions/15-update-and-release-runbook.md`.

## Совместимость с текущим проектом

- `install.php` после установки также прогоняет runner-логику и фиксирует applied migrations;
- существующие lazy ensure-ветки в `api/*` пока сохранены как переходный слой совместимости;
- новые изменения схемы дальше стоит оформлять через `migrations/`, а старые lazy ensure постепенно убирать после стабилизации.
