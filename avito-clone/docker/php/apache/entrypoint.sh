#!/bin/sh
set -e

# Папка для фото должна быть доступна на запись пользователю Apache (www-data).
# chown без -R: демо-картинки seed_* остаются как есть.
mkdir -p /var/www/html/public/uploads
chown www-data:www-data /var/www/html/public/uploads 2>/dev/null || true
chmod 775 /var/www/html/public/uploads 2>/dev/null || true

# Миграции БД выполняет только веб-контейнер; сервис ws (php bin/ws-server.php) просто запускается без root
if [ "$1" = "apache2-foreground" ]; then
    runuser -u www-data -- php /var/www/html/bin/migrate.php
    exec docker-php-entrypoint "$@"
fi
exec runuser -u www-data -- "$@"
