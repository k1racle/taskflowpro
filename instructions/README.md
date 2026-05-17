# Инструкции по работе с системой WorkHub

В этой папке собраны пользовательские инструкции по основным разделам системы. Материалы подготовлены в формате Markdown, чтобы их было удобно дорабатывать, переносить в базу знаний и дополнять скриншотами.

## Список инструкций

1. [01-login-navigation.md](instructions/01-login-navigation.md) - вход в систему, структура интерфейса, главная навигация.
2. [02-crm-clients-contacts-deals.md](instructions/02-crm-clients-contacts-deals.md) - работа с CRM: клиенты, контакты, сделки и карточка клиента.
3. [03-tasks.md](instructions/03-tasks.md) - постановка задач, фильтры, режимы просмотра и контроль исполнения.
4. [04-documents.md](instructions/04-documents.md) - шаблоны документов, генерация по клиенту и пакетная выгрузка.
5. [05-sales-and-history.md](instructions/05-sales-and-history.md) - раздел продаж, аналитика по месяцам и история продаж по клиенту.
6. [06-notifications.md](instructions/06-notifications.md) - системные уведомления, браузерные уведомления и организационные рекомендации.
7. [07-chat-and-site-requests.md](instructions/07-chat-and-site-requests.md) - внутренний чат и работа с заявками с сайта.
8. [08-employees-roles-permissions.md](instructions/08-employees-roles-permissions.md) - сотрудники, роли, права доступа и администрирование.
9. [09-files.md](instructions/09-files.md) - файловое хранилище, папки, загрузка и организация документов.
10. [10-knowledge-base.md](instructions/10-knowledge-base.md) - база знаний, типы материалов и поддержка актуальности статей.
11. [11-site-widgets.md](instructions/11-site-widgets.md) - виджеты сайта, профили, сценарии вставки и публикация кода.
12. [12-after-install-and-client-import.md](instructions/12-after-install-and-client-import.md) - что делать после установки, первичная настройка и загрузка клиентов.
13. [13-versioning-and-migrations.md](instructions/13-versioning-and-migrations.md) - версия приложения, preflight и применение миграций.
14. [14-backup-and-restore.md](instructions/14-backup-and-restore.md) - минимальный backup/restore foundation перед обновлениями и миграциями.
15. [15-update-and-release-runbook.md](instructions/15-update-and-release-runbook.md) - единый пошаговый runbook обновления коробки: pre-check, update flow, rollback и post-update verification.
16. `health/ready foundation` встроен в API (`/api/health`, `/api/ready`), а минимальный release smoke helper доступен через `php tools/smoke.php --base-url=http://<host>`; оба описаны в `instructions/15-update-and-release-runbook.md`.
17. Minimum viable audit trail foundation описан в `instructions/16-audit-trail.md`; для admin/root доступны `GET /api/audit/recent` с базовыми фильтрами и `GET /api/audit/lockouts` для просмотра активных login lockout-состояний, а для ops/CLI доступны `php tools/audit.php retention`, `php tools/audit.php export` и `php tools/audit.php prune`.
18. [17-security-baseline.md](instructions/17-security-baseline.md) - минимальный security hardening baseline: что включается автоматически, что остаётся проверить администратору и как интерпретировать security-related checks в `/api/ready`.
19. Minimum viable auth hardening на логине использует мягкий throttling для сочетания `login + IP`, а связанные audit-события и администраторские подсказки описаны в `instructions/16-audit-trail.md` и `instructions/17-security-baseline.md`.
20. [18-design-foundation.md](instructions/18-design-foundation.md) - design foundation v1: базовые токены, стандартные UI-паттерны и правила расширения интерфейса без расползания дизайна.
21. [19-deployment-and-admin-guide.md](instructions/19-deployment-and-admin-guide.md) - единый deployment/admin guide для коробочного запуска: развёртывание, первичная настройка, health/security checks и передача в эксплуатацию.
22. [20-integrations-and-support-runbook.md](instructions/20-integrations-and-support-runbook.md) - integration guide и runbook первой линии: что фиксировать при подключении интеграций и как support диагностирует типовые жалобы.
23. [21-no-code-customization-boundaries.md](instructions/21-no-code-customization-boundaries.md) - текущие границы кастомизации без кода: что администратор уже может менять сам, а что пока требует разработки.

## Как использовать эту папку

- Откройте нужный файл по теме и используйте его как готовую статью или основу для статьи в базе знаний.
- Внутри инструкций есть пометки вида `Скриншот: ...` - это места, куда позже можно вставить реальные изображения интерфейса.
- При необходимости тексты можно адаптировать под конкретную роль: администратора, руководителя, менеджера или исполнителя.

## Рекомендации по переносу в базу знаний

- Сначала перенесите базовые инструкции по входу, CRM, задачам и документам - они чаще всего нужны новым сотрудникам.
- Затем добавьте статьи по ролям, уведомлениям, заявкам и виджетам сайта.
- После переноса проверьте, чтобы названия разделов в статьях совпадали с названиями пунктов меню в вашей рабочей системе.
