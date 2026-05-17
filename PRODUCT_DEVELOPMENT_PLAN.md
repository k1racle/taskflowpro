# PRODUCT_DEVELOPMENT_PLAN

Этот документ - рабочий backlog развития WorkHub до продаваемого и поддерживаемого состояния.

Опорные документы:

- [plans/rbac-audit-backlog.md](plans/rbac-audit-backlog.md)
- [instructions/14-backup-and-restore.md](instructions/14-backup-and-restore.md)
- [docs/woocommerce-referrals-mvp.md](docs/woocommerce-referrals-mvp.md)
- [instructions/07-chat-and-site-requests.md](instructions/07-chat-and-site-requests.md)
- [instructions/08-employees-roles-permissions.md](instructions/08-employees-roles-permissions.md)
- [instructions/11-site-widgets.md](instructions/11-site-widgets.md)
- [instructions/15-update-and-release-runbook.md](instructions/15-update-and-release-runbook.md)
- [instructions/16-audit-trail.md](instructions/16-audit-trail.md)
- [instructions/17-security-baseline.md](instructions/17-security-baseline.md)
- [instructions/19-deployment-and-admin-guide.md](instructions/19-deployment-and-admin-guide.md)
- [instructions/20-integrations-and-support-runbook.md](instructions/20-integrations-and-support-runbook.md)
- [instructions/21-no-code-customization-boundaries.md](instructions/21-no-code-customization-boundaries.md)
- [widgets/README.md](widgets/README.md)

## 1. Текущее состояние

### Факты

- В продукте уже есть рабочее ядро: CRM, задачи, документы, файлы, знания, helpdesk, виджеты сайта, уведомления, почта, конференции, аудит и лицензирование.
- Есть базовые foundation-слои для security, audit trail, backup/restore, migrate preflight/plan и release smoke.
- RBAC уже описан как permission-code модель, но в коде и UI еще остались legacy role-name проверки и расхождения между `root`, `administrator` и `admin.full`.
- WooCommerce referral integration уже существует как MVP: referral code, referral link, webhook, plugin, защита от дублей и отображение в карточке клиента.
- Mango Office / IP-телефония в репозитории как подтвержденный модуль не найдены.
- Чат существует, но он еще не соответствует целевому real-time и UX уровню, который нужен для продаваемого продукта.
- Границы no-code кастомизации уже частично описаны, но их нужно довести до честной sales-ready формулировки.

### Вывод

- Продукт уже имеет рабочую основу, но до продаваемого состояния ему нужны закрытые P0-блокеры, поддерживаемые интеграции, полноценный чат и повторяемый релизный процесс.

## 2. Правила приоритизации

| Приоритет | Что означает | Что сюда попадает в этом плане |
| --- | --- | --- |
| P0 | Без этого нельзя безопасно запускать или продавать продукт | RBAC/security/audit, secret masking, release path, production hygiene, mandatory release gate |
| P1 | Без этого нет первой продаваемой версии или поддерживаемого канала | WooCommerce, Mango Office / IP-телефония, чат, staging coverage, install/update docs, support runbooks |
| P2 | Улучшения, расширения, полировка | advanced sync, richer chat UX, analytics, demo pack, extra tooling |

### Приоритеты по ключевым направлениям

- RBAC / audit / security - P0.
- WooCommerce - P1.
- Mango Office / IP-телефония - P1.
- Чат - P1.
- Тестирование и release gate - P0.
- Документация и runbooks - P1.
- Расширения и полировка - P2.

## 3. Спринтовый план

### Спринт 0 - Подготовительный этап: безопасность и границы продукта

Цель: закрыть блокеры безопасного запуска и зафиксировать честные границы продаж.

Зависимости: нет.

Состав задач:

- [P0] Довести permission-code модель до конца: убрать legacy role-name checks, оставить `root` как break-glass, а `administrator` - только через `admin.full`.
- [P0] Маскировать секреты и чувствительные поля в API и UI, а также закрыть audit trail для критичных изменений в правах, настройках и интеграциях.
- [P0] Зафиксировать безопасный release path: preflight, backup, deploy, migrate, smoke, verify, rollback, production hygiene.
- [P1] Описать supported / not supported сценарии и sales-ready границы no-code кастомизации.
- [P1] Подготовить стабильный демо-сценарий и базовые тестовые данные для пресейла и support.

Ожидаемый результат: систему можно безопасно администрировать, обновлять и показывать без скрытых исключений и утечек секретов.

### Спринт 1 - Общий интеграционный контракт

Цель: сделать все внешние интеграции одинаково поддерживаемыми.

Зависимости: Спринт 0.

Состав задач:

- [P1] Определить единый шаблон интеграции: settings block, secret, test connection, status, last sync, last error, manual resync, audit trail.
- [P1] Зафиксировать source-of-truth матрицу и единый event model для внешних систем.
- [P1] Разделить transport/adapter слой и core domain handlers, чтобы интеграции не встраивались хаотично в бизнес-логику.
- [P2] Свести support diagnostics к одному формату и добавить удобные инструменты для bulk resync/backfill.

Ожидаемый результат: новые и существующие интеграции собираются по одному шаблону и поддерживаются одинаково.

### Спринт 2 - WooCommerce как поддерживаемая интеграция

Цель: перевести WooCommerce из MVP в поддерживаемый канал продаж.

Зависимости: Спринт 1 и базовый security/RBAC слой из Спринта 0.

Состав задач:

- [P1] Довести referral MVP до поддерживаемой интеграции WooCommerce.
- [P1] Синхронизировать products, variations, prices, stock, customers, orders и statuses по согласованным правилам.
- [P1] Устойчиво связать заказы и клиентов по email, phone и referral_code, сохранив idempotency для webhook'ов и повторных импортов.
- [P2] Довести custom fields, bulk resync/backfill и более гибкое mapping-правило.

Ожидаемый результат: WooCommerce работает как поддерживаемая интеграция, а не только как MVP.

### Спринт 3 - Mango Office / IP-телефония

Цель: добавить поддерживаемую телефонию с базовыми сценариями продаж и поддержки.

Зависимости: Спринт 1 и базовый security/RBAC слой из Спринта 0.

Состав задач:

- [P1] Определить MVP-сценарии: входящий звонок, исходящий звонок, click-to-call, missed call, повторный контакт.
- [P1] Реализовать settings block, secret, test connection и понятный status конфигурации.
- [P1] Зафиксировать модель событий телефонии и привязку звонков к клиенту, контакту, сделке, заявке или задаче; добавить историю звонков и записи.
- [P2] Довести диагностику, нормализацию номеров и дополнительные сценарии маршрутизации.

Ожидаемый результат: телефония становится рабочим продуктовым каналом, а не заготовкой.

### Спринт 4 - Чат и site widget/helpdesk

Цель: довести чат до real-time уровня и свести сайт, helpdesk и внутренний чат к одной модели событий.

Зависимости: Спринт 1 и security/RBAC слой из Спринта 0.

Состав задач:

- [P1] Перевести transport на WebSocket-first модель с HTTP fallback только как аварийный режим.
- [P1] Ввести единый event contract для сообщений, edit/delete, delivery/read receipts, typing, presence, room updates и reconnect sync.
- [P1] Довести UX списка диалогов и карточки чата до mobile-friendly состояния и связать внутренний чат, site widget и helpdesk с одной backend-моделью.
- [P2] Добавить search, draft persistence, reply/forward/edit/delete, richer attachments.

Ожидаемый результат: чат становится real-time каналом с предсказуемой доставкой, понятными состояниями и единым backend-потоком.

### Спринт 5 - Тестирование, релиз, документация и поддержка

Цель: сделать релиз повторяемым, а поддержку - операционно понятной.

Зависимости: Спринты 0-4.

Состав задач:

- [P0] Сделать staging/QA gate обязательным условием production release: smoke, integration checks, негативные сценарии и rollback verification.
- [P1] Подготовить тестовые данные и роли, manual QA matrix для desktop/mobile и проверки auth, RBAC, audit, settings, chat, helpdesk, CRM, WooCommerce и Mango Office.
- [P1] Обновить install guide, admin guide, update/release runbook, support runbook, release notes и versioning.
- [P2] Собрать compact demo pack и checklist для внедрения.

Ожидаемый результат: релизы проходят по повторяемой схеме, а support может диагностировать типовые проблемы без разработчика.

## 4. Критерии готовности к продаже

- можно установить и обновить по документации;
- можно безопасно администрировать по permission model;
- RBAC не содержит скрытых role-name исключений;
- WooCommerce работает как supported integration;
- Mango Office / IP-телефония работают как supported module;
- чат работает как real-time канал с delivery/read receipts, typing, presence;
- site widgets и helpdesk не теряют обращения и используют согласованную backend-модель;
- staging и QA блокируют плохой релиз;
- support может диагностировать типовые проблемы без разработчика;
- есть честно описанные границы no-code и supported integrations.
