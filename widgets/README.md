# Виджеты для внешнего сайта

В папке [widgets/](c:/Proj/workhub/backup/widgets) находится основной `snippet-first` вариант встраивания виджета на внешний сайт без `iframe`.

Файлы:

- `site-widgets.js` - основной публичный script для вставки одним snippet
- `widget-mini.html` - legacy/fallback форма в iframe
- `widget-standard.html` - legacy/fallback чат в iframe
- `widget-premium.html` - демо-страница

## Что теперь делает виджет

После подключения `site-widgets.js` на внешний сайт скрипт:

- сам создает плавающую кнопку и DOM-панель поверх страницы,
- подтягивает публичные настройки активного профиля или профиля по `slug`,
- открывает форму и чат прямо на странице сайта,
- отправляет заявки в `HelpDesk`,
- создает живой диалог, который попадает во внутренний раздел `Чат`,
- при старте чата регистрирует связанную запись в `HelpDesk`.

Сценарий `Написать нам` больше не обязан вести во внешние каналы вроде Telegram или WhatsApp. Основной путь теперь - внутренний CRM-чат проекта.

## Быстрое подключение на внешний сайт

Вариант для `footer` или перед `</body>`:

```html
<script
  src="https://YOUR-DOMAIN/widgets/site-widgets.js"
  data-api-base="https://YOUR-DOMAIN/api"
  data-profile="default"
  data-position="right">
</script>
```

Вариант для `head` тоже поддерживается. Скрипт сам дождется появления `document.body`:

```html
<head>
  <script
    src="https://YOUR-DOMAIN/widgets/site-widgets.js"
    data-api-base="https://YOUR-DOMAIN/api"
    data-profile="default"
    data-position="right"></script>
</head>
```

Опциональные параметры:

- `data-profile` - slug профиля виджета
- `data-position="left|right"` - сторона экрана
- `data-title` - заголовок виджета
- `data-subtitle` - подзаголовок виджета
- `data-brand-color` - основной цвет
- `data-brand-button-text` - символ/текст кнопки запуска
- `data-brand-form-title` - заголовок сценария заявки
- `data-brand-form-description` - описание сценария заявки
- `data-contact-label` - текст сценария чата
- `data-contact-description` - описание сценария чата

Рекомендуемый минимум для обычного хостинга - это один `script` со значениями `src`, `data-api-base` и `data-profile`.

## Как работает заявка

Форма отправляет данные в:

- `POST /api/helpdesk.php?action=widget-ticket`

В результате:

- создается тикет в `HelpDesk`,
- `source` сохраняется как `widget-form` или `widget-chat`,
- возвращается номер обращения.

## Как работает чат

Живой чат работает через публичные endpoints:

- `POST /api/helpdesk.php?action=widget-chat&id=session` - старт сессии
- `GET /api/helpdesk.php?action=widget-chat&id=messages&token=...` - чтение сообщений
- `POST /api/helpdesk.php?action=widget-chat&id=messages` - отправка сообщения посетителем

При запуске чата:

- создается приватная комната во внутреннем модуле `Чат`,
- оператор видит ее в обычном списке диалогов,
- создается связанный тикет в `HelpDesk`,
- сообщения посетителя и оператора зеркалятся в `HelpDesk` как комментарии.

То есть обращения не уходят во внешние мессенджеры: основной сценарий полностью замкнут внутри CRM/helpdesk проекта.

## Пример только формы

```html
<script
  src="https://YOUR-DOMAIN/widgets/site-widgets.js"
  data-mode="form"
  data-api-base="https://YOUR-DOMAIN/api"
  data-profile="default">
</script>
```

В этом режиме скрипт тоже встраивает форму напрямую в DOM страницы, без `iframe`.

## Legacy варианты

Старые `widget-mini.html` и `widget-standard.html` сохранены как fallback и для совместимости. Но основной рекомендуемый способ - `site-widgets.js`.

## Что проверить вручную

1. Подключить `site-widgets.js` на тестовую страницу внешнего сайта.
2. Проверить вставку через `script` и в `head`, и перед `</body>`.
3. Проверить открытие плавающей кнопки и панели без `iframe`.
4. Отправить заявку и убедиться, что она появилась в `HelpDesk`.
5. Начать чат и убедиться, что в `HelpDesk` создался связанный тикет.
6. Проверить, что внутри раздела `Чат` появился новый диалог.
7. Проверить, что ответ оператора в разделе `Чат` отображается во внешнем виджете после обновления.
