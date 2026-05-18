# Установка TaskFlow Pro на VPS через Portainer

Ниже рабочая схема для установки через Portainer, когда NGINX настраивается вручную как reverse proxy.

## Что нужно заранее

1. VPS с установленными Docker и Portainer.
2. Домен и доступ к TLS-сертификату для NGINX.
3. Копия проекта на VPS, например в `/opt/taskflow/taskflowpro`.
4. Полный набор файлов проекта, включая `vendor/`, `uploads/`, `backups/`, `docs/`, `api/`, `assets/`, `index.html`, `install.php`.

## Подготовка каталога на VPS

Скопируй проект на сервер и проверь права на runtime-папки:

```bash
mkdir -p /opt/taskflow/taskflowpro
# сюда нужно развернуть весь проект

chown -R 33:33 /opt/taskflow/taskflowpro/uploads \
  /opt/taskflow/taskflowpro/backups \
  /opt/taskflow/taskflowpro/api/logs \
  /opt/taskflow/taskflowpro/api/config.php

chmod -R 775 /opt/taskflow/taskflowpro/uploads \
  /opt/taskflow/taskflowpro/backups \
  /opt/taskflow/taskflowpro/api/logs

chmod 664 /opt/taskflow/taskflowpro/api/config.php
```

`33:33` - это `www-data` в official PHP Apache image.

## Шаг 1. Собрать image в Portainer

В Portainer открой `Images -> Build a new image` и собери образ из Dockerfile в корне проекта.

- image name: `taskflowpro-php:8.2`
- base image: `php:8.2-apache-bookworm`

Этот image ставит нужные PHP-расширения и включает Apache modules. Сам код приложения берётся из bind mount на VPS.

## Шаг 2. Развернуть stack

Создай stack из файла `portainer-stack.yml` или вставь его в web editor Portainer.

Перед запуском задай переменные окружения:

- `TASKFLOW_APP_PATH=/opt/taskflow/taskflowpro`
- `MARIADB_ROOT_PASSWORD=<strong-root-password>`
- `MARIADB_DATABASE=taskflow`
- `MARIADB_USER=taskflow`
- `MARIADB_PASSWORD=<strong-db-password>`

Что важно:

- приложение публикуется только на `127.0.0.1:8085`;
- MySQL/MariaDB хранится в named volume `taskflow_db_data`;
- код и `api/config.php` остаются на VPS, а не внутри image.

## Шаг 3. Настроить NGINX вручную

NGINX должен работать только как reverse proxy к локальному Apache-контейнеру.

Минимальный пример:

```nginx
server {
    listen 80;
    server_name taskflow.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name taskflow.example.com;

    client_max_body_size 160m;

    ssl_certificate     /etc/letsencrypt/live/taskflow.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/taskflow.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8085;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Ssl on;
        proxy_set_header X-Forwarded-Host $host;
    }
}
```

Что обязательно:

- не открывай `8085` наружу, он нужен только для локального proxy_pass;
- передавай `X-Forwarded-Proto: https`, иначе приложение не включит secure-cookie и HSTS корректно;
- `client_max_body_size` должен быть не меньше размера реальных загрузок, иначе NGINX отрежет запрос раньше PHP.

## Шаг 4. Запустить установку

После запуска stack открой:

`https://taskflow.example.com/install.php`

В форме установки укажи:

- `Хост MySQL` - `db`
- `Пользователь MySQL` - значение `MARIADB_USER`
- `Пароль базы данных` - значение `MARIADB_PASSWORD`
- `Имя базы данных` - `MARIADB_DATABASE`
- `Пароль для root` - пароль администратора системы, не пароль БД

После установки будет создан `api/config.php` и root-пользователь системы.

## Шаг 5. Проверить инстанс

Проверь:

```bash
curl -sS https://taskflow.example.com/api/health
curl -sS https://taskflow.example.com/api/ready
```

Норма:

- `/api/health` отвечает `200`;
- `/api/ready` отвечает `200` и не показывает критических ошибок;
- вход в систему работает;
- загрузка файлов и генерация документов проходят без ошибок.

## Шаг 6. Что сделать сразу после установки

1. Убедиться, что `api/config.php` не попадает в публичные артефакты и бэкапы выкладки.
2. При необходимости удалить `install.php` из production-поставки или оставить его только на время первичной установки.
3. Не публиковать `backups/`, `docs/`, `tools/`, `migrations/`, `vendor/`, `instructions/`, `old/`, `plans/`, `integrations/` через web.
4. Проверить загрузку аватара, файла, документа и авторизацию под root.

## Если нужен апдейт

При обновлении кода на VPS обычно достаточно:

1. заменить файлы в `/opt/taskflow/taskflowpro`;
2. перезапустить контейнер `app` в Portainer;
3. проверить `/api/health` и `/api/ready`.

Если меняются PHP-расширения или версия PHP, пересобери image `taskflowpro-php:8.2`.

