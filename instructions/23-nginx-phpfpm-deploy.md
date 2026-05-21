# Деплой TaskFlow Pro на VPS: nginx + php-fpm (Ubuntu)

Этот проект исторически содержит rewrite/CORS в `api/.htaccess` (под Apache). На nginx **.htaccess не работает**, поэтому на VPS нужно явно настроить маршрутизацию API.

Ниже минимальный «рабочий» чеклист, который закрывает 80% проблем при деплое.

## 1) Размещение файлов

Пример:

- код проекта: `/var/www/taskflowpro`
- веб-домен: `https://crm.example.ru`

Убедитесь, что папка доступна пользователю веб-сервера (`www-data`).

## 2) Nginx конфиг

В репозитории есть пример конфига:

- `instructions/nginx/taskflowpro.example.conf`

Для HTTPS (80 -> 443 redirect + http2 + HSTS) есть отдельный пример:

- `instructions/nginx/taskflowpro.example.ssl.conf`

Скопируйте его в nginx, поправьте:

- `server_name`
- `root`
- `fastcgi_pass` (сокет/порт php-fpm)

Ключевое: блок `/api/` должен **rewrite** делать в `api/index.php?endpoint=...`.

## 3) PHP-FPM

Проект использует PDO/MySQL, шифрование и работу с сетью. Обычно нужны расширения:

- `pdo_mysql`
- `openssl`
- `mbstring`
- `curl` (желательно)

## 4) Секреты и переменные окружения

Для продакшна обязательно задайте корректные значения:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `JWT_SECRET` (должен быть стабильным и сильным)
- `APP_ENC_KEY` (для шифрования секретов в settings)

Если `JWT_SECRET` будет меняться между рестартами/деплоями, пользователей будет «выкидывать» из сессий.

## 5) Права на каталоги

Проверьте, что writable:

- `uploads/`
- `api/logs/`

И что эти каталоги **не отдаются напрямую** из web (в nginx примере они запрещены внутри `/api/`).

## 6) Проверка готовности

В проекте есть endpoint'ы:

- `/api/health`
- `/api/ready`

Их удобно дергать после деплоя как smoke-check.

Также полезно проверить, что long-poll эндпоинты не обрываются proxy таймаутами:

- чат: `/api/chat/rooms/:id/messages?since_id=...&timeout=20`
- звонки: `/api/chat/calls?timeout=20`

## 7) Installer/debug

На проде рекомендуется удалить или закрыть доступ к:

- `install.php`
- `api/debug.php`

В коде есть защитные гард-условия, но физическое удаление/deny в nginx уменьшает поверхность атаки.
