#!/bin/sh
set -e

# Папка для фото должна быть доступна на запись пользователю Apache (www-data).
# chown без -R: демо-картинки seed_* остаются как есть.
mkdir -p /var/www/html/public/uploads
chown www-data:www-data /var/www/html/public/uploads 2>/dev/null || true
chmod 775 /var/www/html/public/uploads 2>/dev/null || true

runuser -u www-data -- php /var/www/html/bin/migrate.php

exec docker-php-entrypoint "$@"
