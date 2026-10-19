#!/bin/sh
set -e

# Папка для фото должна быть доступна на запись пользователю приложения.
# На Docker Desktop (Windows/macOS) chown по bind-mount может не поддерживаться — это не страшно.
mkdir -p /var/www/html/public/uploads
chown app:app /var/www/html/public/uploads 2>/dev/null || true
chmod 777 /var/www/html/public/uploads 2>/dev/null || true

# Миграции БД (и демо-данные при APP_DEMO=1) — безопасно запускать при каждом старте.
# Если БД ещё не поднялась — контейнер перезапустится (restart policy) и попробует снова.
if [ "$1" = "php" ] && [ "$(echo "$2" | cut -c1-2)" = "-S" ]; then
    su-exec app php /var/www/html/bin/migrate.php
fi

# Дальше — без прав root
exec su-exec app "$@"
