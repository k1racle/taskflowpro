# Design foundation v1 / v1.1 / consolidation-pass

Этот документ фиксирует minimum viable baseline для интерфейса WorkHub после серии точечных проходов по экранам и отдельного consolidation-pass. Цель по-прежнему не в полном редизайне, а в том, чтобы новые и обновляемые экраны собирались из одного визуального языка, а не из локальных одноразовых решений.

## 1. Что считать базовыми токенами

Основной источник токенов: `assets/css/crm-theme.css`.

### Цвета и поверхности

- `--crm-accent`, `--crm-accent-strong`, `--crm-accent-soft` - основной брендовый акцент и его состояния.
- `--crm-danger-soft`, `--crm-danger-border`, `--crm-danger-text` - базовая danger-семантика.
- `--crm-bg`, `--crm-surface`, `--crm-surface-alt` - фон приложения, основная поверхность, вторичная поверхность.
- `--crm-border`, `--crm-border-strong` - обычные и усиленные границы.
- `--crm-text`, `--crm-text-muted`, `--crm-text-faint` - основной, вторичный и слабый текст.

### Типографика

- `--crm-title-size`, `--crm-title-line` - заголовки экранов.
- Для внутренних секций используйте `crm-section-head__title`, а не локальные inline-стили, если блок уже укладывается в foundation-header паттерн.

### Spacing

- `--crm-space-2: 8px`
- `--crm-space-3: 12px`
- `--crm-space-4: 16px`
- `--crm-space-5: 20px`
- `--crm-space-6: 24px`

Это не полная шкала дизайн-системы, а рабочий baseline для общих экранов, карточек и секций.

### Radius

- `--crm-radius-sm: 14px`
- `--crm-radius-md: 18px`
- `--crm-radius-lg: 24px`

### Shadows и слои

- `--crm-shadow-sm`, `--crm-shadow-md`, `--crm-shadow-lg`
- `--crm-layer-base`, `--crm-layer-sticky`, `--crm-layer-overlay`, `--crm-layer-popover`

Если нужен новый z-index, сначала проверьте, нельзя ли использовать один из этих слоёв.

## 2. Какие классы считать стандартными

### Экранные контейнеры

- `crm-screen-section` - базовая экранная секция.
- `crm-table-shell` - стандартная оболочка таблиц/списков.
- `crm-panel-block` - вложенный нейтральный блок внутри секции.
- `crm-ui-subcard` - лёгкая вторичная карточка.
- `crm-panel-stack`, `crm-panel-stack--compact` - вертикальные стеки карточек и панелей.
- `crm-card-compact`, `crm-card-equal` - компактные и выровненные карточки.

### Toolbar и header-паттерны

- `crm-toolbar` - стандартный верхний ряд секции.
- `crm-toolbar--dense` - уплотнённый вариант toolbar без нового локального CSS.
- `crm-toolbar-main`, `crm-toolbar-actions` - основной контент и зона действий в screen-header сценариях.
- `crm-section-head`, `crm-section-head__content`, `crm-section-head__title`, `crm-section-head__text`, `crm-section-head__meta` - основной паттерн заголовка внутренней секции.
- `crm-table-toolbar` - стандартная шапка таблицы/списка/панели с нижней границей.
- `crm-table-toolbar--compact` - уплотнённая шапка таблицы/панели без отдельного локального CSS.

Короткое правило:

- если это верхний ряд экрана с действиями - начинаем с `crm-toolbar`;
- если это шапка таблицы/списка/мастер-панели - начинаем с `crm-table-toolbar`;
- если внутри нужны `title/text/meta`, собираем шапку через `crm-section-head*`;
- в типовом сценарии `crm-table-toolbar` и `crm-section-head` можно ставить на один контейнер, а не вкладывать одно в другое.

### Секции, карточки, списки

- `crm-action-row`, `crm-queue-row` - повторяемые строки для action/info list сценариев.

### Filters и controls

- `crm-filterbar`, `crm-filterbar-wide` - типовая раскладка фильтров.
- `crm-filter-field`, `crm-filter-field--compact` - контейнер поля фильтра.
- `crm-control-input`, `crm-control-select`, `crm-control-textarea` - общие form controls.

Короткое правило:

- `--compact` используем для toolbar/filter/panel сценариев с плотной компоновкой;
- если поле уникально и не формирует новый повторяемый паттерн, не создаём под него новый foundation-класс.

### Состояния

- `crm-empty-state` - базовый пустой/нейтральный контейнер состояния.
- `crm-empty-state-title`, `crm-empty-state-text`, `crm-empty-state-actions` - внутренние элементы empty state.
- `crm-empty-state[data-state="loading"]` - loading-состояние без локальной ручной вёрстки.
- `crm-empty-state[data-state="error"]` - error-состояние без локальной ручной вёрстки.

Короткое правило:

- если состояние повторяемое и смысловое, используем `title/text/actions`, а не просто локальный `font-semibold` + `text-sm`;
- если есть реальная загрузка или ошибка, используем `data-state`, а не локальные границы и цвета;
- если действий нет, допустимо оставить только `crm-empty-state-title` и `crm-empty-state-text`.

### Modal / drawer baseline

- `crm-modal-body--compact` - компактный baseline для частых коротких модалок.
- `crm-modal-footer--compact` - компактный footer для таких модалок.
- `crm-modal-form`, `crm-modal-form--compact` - стандартная форма внутри modal body.
- `crm-form-stack--compact`, `crm-form-grid--compact` - компактная вертикальная и grid-раскладка полей.

Короткое правило:

- если модалка короткая и повторяемая, сначала используем этот baseline;
- не дублируем альтернативный layout для `crm-modal-form` в theme layer, если существующий baseline уже решает задачу.

## 3. Как добавлять новые UI-паттерны без расползания дизайна

Перед добавлением нового класса проверьте три вопроса:

1. Можно ли решить задачу уже существующим паттерном (`crm-screen-section`, `crm-panel-block`, `crm-filter-field`, `crm-btn-*`)?
2. Если нужен новый класс, это действительно повторяемый сценарий хотя бы для 2 экранов?
3. Новый класс опирается на токены и существующую семантику, а не вносит ещё одну частную визуальную систему?

### Правило расширения

- если меняется только плотность/вариант существующего блока - добавляем modifier-класс, например `--dense`, `--compact`;
- если сценарий новый, но явно повторяемый - добавляем новый semantic-class в `crm-theme.css` и сразу применяем минимум в одном живом экране;
- если паттерн нужен только один раз - лучше остаться на локальной tailwind/layout-разметке без создания псевдо-компонента.

### Чего избегать

- inline-цветов и inline-границ для типовых состояний;
- новых локальных radii/padding без привязки к токенам;
- копирования пустых состояний, header-полос и stat-карточек с ручной сборкой в каждом модуле;
- создания абстракций без хотя бы одного реального применения;
- повторного определения того же foundation-класса в `crm-theme.css`, если можно укрепить существующий baseline.

## 4. Что уже внедрено в v1

На текущем шаге foundation уже применён в существующем UI:

- `assets/components/documents-view.html` - внутренние секции, empty states и hint-карточки;
- `assets/components/users-view.html` - заголовок таблицы, error/empty состояния и поисковый toolbar.
- `assets/components/tasks-view.html` - dense toolbar в верхнем блоке, `section-head` в панели фильтров и стандартизированное empty state списка задач;
- `assets/components/projects-view.html` - `section-head` в панели фильтров и табличном списке, `crm-card-compact` / `crm-card-equal` / `crm-panel-stack--compact` в карточном режиме, стандартизированное empty state.
- `assets/components/files-view.html` - dense toolbar верхнего экрана, `section-head` в каталоге и боковой навигации, `crm-panel-stack--compact` в левой колонке, `crm-card-compact` / `crm-card-equal` в плитках файлов и папок, стандартизированные empty states.
- `assets/components/knowledge-view.html` - dense toolbar верхнего блока и каталога, `section-head` в списке материалов и панели чтения, `crm-panel-stack--compact` и `crm-card-compact` в правой колонке, стандартизированные empty states.
- `assets/components/helpdesk-view.html` - dense toolbar верхнего блока и списка заявок, `section-head` в панели фильтров, списке и правой колонке, `crm-panel-stack--compact` / `crm-card-compact` / `crm-card-equal` в боковых карточках, стандартизированные empty states для пустой очереди и невыбранной заявки.

Это не завершённая дизайн-система, а стартовый общий контракт. Следующие экраны стоит переводить на него инкрементально, без массового редизайна.

## 5. Что добавлено в v1.1

На шаге v1.1 фокус смещён не на новый экран, а на повторяющиеся системные паттерны UI.

Добавлены и нормализованы:

- baseline для компактных modal body/footer: `crm-modal-body--compact`, `crm-modal-footer--compact`;
- baseline для повторяемых modal forms: `crm-modal-form`, `crm-modal-form--compact`, `crm-form-stack--compact`, `crm-form-grid--compact`;
- baseline для компактных filter/panel/table header сценариев: `crm-filter-field--compact`, `crm-table-toolbar--compact`;
- семантические варианты состояний: `crm-empty-state[data-state="loading"]` и `crm-empty-state[data-state="error"]`.

### Где уже применено

- `assets/components/leader-dashboard-view.html` - модалка смен переведена на compact modal/form baseline без изменения JS-логики;
- `assets/components/chat-view.html` - модалки создания личного чата и группы переведены на compact modal body/footer baseline;
- `assets/components/users-view.html` - уплотнены поле поиска и табличная шапка, loading/error состояния переведены на semantic `crm-empty-state`.
- `assets/components/modals/crm-modals-block.html` - общий CRM contact drawer переведён на `crm-modal-body--compact`, `crm-modal-footer--compact`, `crm-modal-form`, `crm-modal-form--compact` и `crm-form-grid--compact` без изменения поведения drawer;
- `assets/components/modals/knowledge-modal.html` - shared knowledge modal переведена на `crm-modal-body--compact`, `crm-modal-footer--compact`, `crm-modal-form`, `crm-modal-form--compact` и `crm-form-stack--compact` без изменения логики табов и полей;
- `assets/components/stages-view.html` - экран этапов переведён на `crm-table-toolbar--compact`, `crm-section-head*`, `crm-filter-field--compact` и semantic `crm-empty-state` для loading/error/empty состояний; короткие modal forms этапов и подэтапов уплотнены через compact modal/form baseline;
- `assets/components/roles-view.html` - экран ролей переведён на compact search field и table-toolbar baseline, а `loading` / `error` / `empty` состояния нормализованы через semantic `crm-empty-state`.
- `assets/components/my-tasks-view.html` - экран персонального списка задач переведён на `crm-toolbar--dense`, `crm-section-head*`, `crm-filter-field--compact`, `crm-table-toolbar--compact` и базовый `crm-empty-state` для пустого результата без изменения JS-логики.
- `assets/components/crm-clients-view.html` - общий экран списка клиентов переведён на `crm-toolbar--dense`, `crm-filter-field--compact`, `crm-table-toolbar--compact`, `crm-section-head*` и стандартизированные `crm-empty-state-title/text` для пустого списка и невыбранной карточки без изменения JS-логики.
- `assets/components/departments-view.html` - спокойный экран карточек отделов переведён на `crm-toolbar--dense`, `crm-table-toolbar--compact`, `crm-section-head*`, `crm-card-compact` / `crm-card-equal` и базовый `crm-empty-state` для пустого каталога без изменения JS-логики.
- `assets/components/chat-view.html` - экран чатов точечно доведён до foundation без JS-изменений: sidebar header собран через `crm-section-head*`, поиск уплотнён через `crm-filter-field--compact`, а loading/error/empty состояния списка чатов и области сообщений нормализованы через semantic `crm-empty-state`, `crm-empty-state-title/text/actions` и `data-state="loading"|"error"`.
- `assets/components/mail-interface.html` - почтовый интерфейс точечно выровнен по foundation в безопасных зонах без изменения JS: верхний toolbar уплотнён через `crm-toolbar--dense`, поиск переведён на `crm-filter-field--compact`, шапка списка писем собрана через `crm-table-toolbar--compact` и `crm-section-head*`, а loading/empty состояния списка и пустой reader нормализованы через `crm-empty-state`, `crm-empty-state-title/text` и `data-state="loading"`; на втором безопасном проходе дополнительно нормализованы reader header через `crm-section-head*` и компактный action-row выбранного письма через `crm-toolbar--dense`. Отдельного готового `error-state` в этом шаблоне не было, поэтому semantic `crm-empty-state[data-state="error"]` здесь сознательно не добавлялся искусственно.
- `assets/components/my-shift-view.html` - безопасный экран учёта времени точечно доведён до foundation без JS-изменений: верхний header уплотнён через `crm-toolbar--dense`, правая колонка собрана через `crm-panel-stack--compact`, а блоки заметки, недельного обзора и истории смен нормализованы через `crm-section-head*` и `crm-table-toolbar--compact`.
- `assets/components/conferences-view.html` - экран конференций точечно доведён до foundation без JS-изменений: верхний header уплотнён через `crm-toolbar--dense` и `crm-section-head*`, карточки встреч и их stat-блоки нормализованы через `crm-card-compact` / `crm-card-equal`, нижняя action-плашка карточки уплотнена через `crm-table-toolbar--compact`, а пустой список переведён на `crm-empty-state`, `crm-empty-state-title/text/actions`.
- `assets/components/settings-view.html` - спокойный административный экран настроек точечно доведён до foundation без JS-изменений: верхний header уплотнён через `crm-toolbar--dense`, read-only блок безопасной диагностики собран через `crm-table-toolbar--compact` и `crm-section-head*`, loading/error состояния переведены на semantic `crm-empty-state` с `data-state="loading"|"error"`, а summary/environment/check карточки нормализованы через `crm-panel-stack--compact` и `crm-card-compact` / `crm-card-equal`.
- `assets/components/widgets-view.html` - актуальный экран виджетов точечно доведён до foundation без JS-изменений: верхний header уплотнён через `crm-toolbar--dense`, секции настроек и кодов собраны через `crm-section-head*` и `crm-table-toolbar--compact`, правая колонка и панель профилей уплотнены через `crm-panel-stack--compact`, сценарные и summary-карточки нормализованы через `crm-card-compact` / `crm-card-equal`, а для пустого списка профилей добавлен базовый `crm-empty-state` с `crm-empty-state-title/text`.

## 6. Что стабилизировано на consolidation-pass

Этот проход не вводит новый большой слой абстракций. Он только фиксирует уже сложившийся стандарт.

### Что считать текущим baseline

- `crm-table-toolbar` и `crm-section-head` считаются совместимым парным паттерном для табличных/панельных header-блоков;
- `crm-empty-state` считается завершённым foundation-паттерном, когда текстовая структура собрана через `crm-empty-state-title` и `crm-empty-state-text`, а действия - через `crm-empty-state-actions`;
- `crm-modal-form` / `crm-modal-form--compact` остаются единственным baseline для коротких modal forms в theme layer.

### Где это уже видно

- `assets/components/roles-view.html`, `assets/components/users-view.html`, `assets/components/mail-interface.html`, `assets/components/settings-view.html` - semantic loading/error/empty states;
- `assets/components/crm-clients-view.html`, `assets/components/my-tasks-view.html`, `assets/components/stages-view.html`, `assets/components/mail-interface.html` - header-паттерн `crm-table-toolbar` + `crm-section-head*`;
- `assets/components/chat-view.html`, `assets/components/stages-view.html`, `assets/components/leader-dashboard-view.html`, `assets/components/modals/crm-modals-block.html`, `assets/components/modals/knowledge-modal.html` - compact modal/form baseline.

### Допустимые отклонения

- допустимо оставить локальную layout-разметку, если блок уникален и не претендует на reusable foundation-pattern;
- допустимо не добавлять `data-state="error"`, если в шаблоне реально нет самостоятельного error-сценария;
- допустимы локальные иконки, badges и summary-узлы внутри foundation-контейнера, если его базовая семантика не ломается.

### Что считать недопустимым отклонением

- новый локальный header-паттерн для таблицы/списка вместо `crm-table-toolbar` + `crm-section-head*` там, где уже нужен `title/text/meta`;
- empty-state, собранный вручную типографикой, если он уже повторяет существующий semantic-сценарий;
- повторное определение того же foundation-класса в `crm-theme.css` с конфликтующим layout/result, если можно использовать один baseline.

### Что сознательно не делали

- не выносили новые универсальные form-компоненты глубже, чем реально нужно для уже существующих модалок;
- не переписывали старые inline-стили массово по всем экранам;
- не меняли JS и не делали полный редизайн table/list экранов.

## 7. Very-light audit pass по remaining templates

На коротком audit pass после consolidation были добраны только самые безопасные локальные выбросы, без широкой миграции:

- `assets/components/helpdesk-view.html` - локальные loading/error-сообщения очереди заявок переведены на semantic `crm-empty-state` с `data-state="loading"|"error"`;
- `assets/components/crm-sales-view.html` - header графика продаж собран через `crm-table-toolbar` + `crm-section-head*`, а локальная пустая заглушка графика переведена на `crm-empty-state-title/text`.
- `assets/components/tasks-view.html` - локальный header блока Ганта собран через `crm-table-toolbar` + `crm-section-head*` без изменения поведения кнопки обновления.
- `assets/components/leader-dashboard-view.html` - во вторичных блоках дашборда локальные пустые заглушки `Кто на смене`, `Горящие задачи`, `Нарушения графика` и CRM funnel переведены на `crm-empty-state-title/text` без изменения аналитики и JS-логики.
- `assets/components/crm-dashboard-view.html` - вторичные header-блоки `Воронка` и `Очередь внимания` собраны через `crm-table-toolbar` + `crm-section-head*` без изменения навигационных action-кнопок.
- follow-up very-light pass: `assets/components/helpdesk-view.html` - ручная заглушка ленты комментариев переведена на `crm-empty-state-title/text`; `assets/components/projects-view.html` - header табличного списка проектов доведён до явного `crm-table-toolbar` + `crm-section-head*`; `assets/components/leader-dashboard-view.html` - вторичные карточки `Кто на смене` и `Горящие задачи` доведены до header baseline `crm-table-toolbar` + `crm-section-head*` без изменения JS.
- ещё один very-light pass: `assets/components/leader-dashboard-view.html` - вторичный header аналитического блока `CRM воронка` собран через `crm-table-toolbar` + `crm-section-head*`; `assets/components/helpdesk-view.html` - вторичные header-блоки `Комментарии` и `История изменений` в карточке заявки переведены на тот же foundation baseline без изменения JS и модальной логики.
- micro audit pass: `assets/components/leader-dashboard-view.html` - ещё три локальных chart-header выброса (`Создание и завершение задач по дням`, `Задачи по статусам`, `Задачи по приоритетам`) переведены на `crm-table-toolbar--compact` + `crm-section-head*` без изменения аналитики, canvas-узлов и JS.
