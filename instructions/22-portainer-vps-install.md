# Установка TaskFlow Pro на VPS через Portainer из Git

Ниже рабочая схема для Portainer CE, когда stack берётся из Git, image собирается GitHub Actions и NGINX настраивается вручную как reverse proxy.

## Что нужно заранее

1. VPS с установленными Docker и Portainer CE.
2. GitHub-репозиторий этого проекта.
3. Включенный GitHub Actions.
4. Домен и доступ к TLS-сертификату для NGINX.
5. Один раз проверить видимость образа в GHCR и сделать его public, если он почему-то не стал public автоматически.

## Шаг 1. Убедиться, что image собирается из Git

В репозитории уже есть workflow `.github/workflows/publish-ghcr.yml`.

Он собирает Docker image из `Dockerfile` и публикует его в GitHub Container Registry как:

`ghcr.io/k1racle/taskflowpro:latest`

Portainer потом только pull-ит этот image. Никаких build step внутри Portainer и никаких host bind mount здесь не нужно.

Если image в GHCR оказался private, открой в GitHub страницу Packages и переведи package в `Public`. Для public package anonymous pull в GHCR работает.

## Шаг 2. Развернуть stack из Git в Portainer

В Portainer выбери `Stacks -> Add stack -> Repository` и укажи:

- Repository URL: `https://github.com/k1racle/taskflowpro.git`
- Repository reference: `refs/heads/main`
- Compose path: `portainer-stack.yml`

Перед запуском задай переменные окружения:

- `MARIADB_ROOT_PASSWORD=<strong-root-password>`
- `MARIADB_DATABASE=taskflow`
- `MARIADB_USER=taskflow`
- `MARIADB_PASSWORD=<strong-db-password>`

Что важно:

- `TASKFLOW_APP_PATH` больше не нужен;
- код приложения не монтируется с хоста;
- image берётся из GHCR;
- данные MySQL/MariaDB хранятся в named volume `taskflow_db_data`;
- runtime-данные хранятся в named volumes внутри Docker, а не в bind mount на VPS.

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
- передавай `X-Forwarded-Proto: https`, иначе secure-cookie и HSTS могут работать неправильно;
- `client_max_body_size` должен быть не меньше размера реальных загрузок, иначе NGINX отрежет запрос раньше PHP.

## Шаг 4. Запустить первичную установку

После запуска stack открой:

`https://taskflow.example.com/install.php`

В форме установки укажи:

- `Хост MySQL` - `db`
- `Пользователь MySQL` - значение `MARIADB_USER`
- `Пароль базы данных` - значение `MARIADB_PASSWORD`
- `Имя базы данных` - `MARIADB_DATABASE`
- `Пароль для root` - пароль администратора системы, не пароль БД

После установки installer создаст `runtime/install.lock`. Он заменяет старую схему с ручной записью `api/config.php`.

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

1. При необходимости удалить `install.php` из production-поставки или оставить его только как заблокированный fallback.
2. Не публиковать `backups/`, `docs/`, `tools/`, `migrations/`, `vendor/`, `instructions/`, `old/`, `plans/`, `integrations/` через web.
3. Проверить загрузку аватара, файла, документа и авторизацию под root.

## Если нужен апдейт

При обновлении кода достаточно:

1. сделать push в GitHub;
2. дождаться, пока workflow соберёт новый image и отправит его в GHCR;
3. в Portainer пересоздать stack или обновить контейнер, чтобы подтянулся новый `latest`.

Если меняются PHP-расширения или версия PHP, меняешь `Dockerfile`, после чего image автоматически пересоберётся через workflow.
