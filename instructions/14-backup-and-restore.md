# Backup и restore foundation

Это minimum viable foundation для коробочного резервирования перед обновлениями и миграциями.

Цель простая: перед изменениями схемы БД и другими рискованными обновлениями иметь реальный способ сохранить:

- базу данных;
- `uploads/` с пользовательскими и прикладными файлами;
- `docs/` с файловыми шаблонами документов;
- install-specific bootstrap lock `runtime/install.lock` и `manifest.json`.

## Что входит в резервную копию

Команда `php tools/backup.php create` создаёт каталог вида:

```text
backups/
  taskflow_backup_YYYYMMDD_HHMMSS/
    manifest.json
    db/
      database.sql
    files/
      uploads/
      docs/
    config/
      runtime/
        install.lock
      manifest.json
```

### Содержимое

- `db/database.sql` — SQL dump всей текущей MySQL БД;
- `files/uploads/` — все загруженные и сгенерированные файлы приложения;
- `files/docs/` — шаблоны документов с диска;
- `config/runtime/install.lock` — параметры подключения к БД, JWT/license-related настройки текущей установки;
- `config/manifest.json` — текущий PWA/delivery manifest, если он есть.

## Что сознательно не входит

В этот foundation **не** входит полный снимок проекта. Сознательно не копируются:

- `assets/`, `api/` целиком, `widgets/`, `instructions/`, `tools/`, `migrations/`;
- git-история и любые developer artifacts;
- временные runtime-файлы вне описанных директорий;
- внешние зависимости и системная конфигурация веб-сервера/MySQL.

Причина: это P0 backup для безопасного обновления данных, а не full disaster recovery image сервера.

## Как сделать backup

Сначала посмотреть план:

```bash
php tools/backup.php plan
```

Создать backup:

```bash
php tools/backup.php create
```

Создать backup в другой каталог или с меткой:

```bash
php tools/backup.php create --output=D:/taskflow-backups --label=before-migrate
```

## Рекомендуемый порядок перед миграциями

```bash
php tools/migrate.php preflight
php tools/backup.php create --label=before-migrate
php tools/migrate.php plan
php tools/migrate.php migrate
```

Если `preflight` не проходит или backup не создался, миграции запускать не нужно.

## Как восстановить

Сначала посмотреть содержимое backup:

```bash
php tools/restore.php inspect backups/taskflow_backup_YYYYMMDD_HHMMSS
```

### Восстановление файлов

Сначала dry-run:

```bash
php tools/restore.php restore-files backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
```

Потом реальное применение:

```bash
php tools/restore.php restore-files backups/taskflow_backup_YYYYMMDD_HHMMSS --force
```

### Восстановление БД

Сначала dry-run:

```bash
php tools/restore.php restore-db backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
```

Потом реальное применение:

```bash
php tools/restore.php restore-db backups/taskflow_backup_YYYYMMDD_HHMMSS --force
```

### Полуавтоматический полный путь

```bash
php tools/restore.php restore-all backups/taskflow_backup_YYYYMMDD_HHMMSS --dry-run
php tools/restore.php restore-all backups/taskflow_backup_YYYYMMDD_HHMMSS --force
```

После восстановления:

```bash
php tools/migrate.php status
```

И затем нужен ручной smoke-test приложения.

Полный сценарий обновления и отката с привязкой к релизному процессу описан в `instructions/15-update-and-release-runbook.md`.

## Ограничения и допущения

- Restore рассчитан на **совместимый код приложения**, уже развернутый на сервере.
- Восстановление БД выполняется в **текущую БД из `runtime/install.lock`** или из environment variables, если они заданы.
- SQL restore перезаписывает таблицы из dump (`DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT`).
- Restore файлов делает копирование поверх существующих файлов, но **не удаляет лишние файлы**, которых нет в backup.
- Если нужно восстановить на другой сервер/инстанс, сначала надо развернуть совместимую версию кода, затем при необходимости подменить `runtime/install.lock` из backup и только потом выполнять restore.

## Когда этого уже мало

Если появятся требования по регулярному расписанию, шифрованию backup, offsite storage, retention policy, инкрементальным backup или атомарным snapshot-сценариям, это уже следующий этап, а не часть текущего P0 foundation.

Для полного порядка действий во время релиза и rollback используйте также `instructions/15-update-and-release-runbook.md`.
