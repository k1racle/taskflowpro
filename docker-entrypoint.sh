#!/bin/sh
set -eu

for dir in /var/www/html/runtime /var/www/html/uploads /var/www/html/backups /var/www/html/api/logs; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done

exec /usr/local/bin/docker-php-entrypoint "$@"
