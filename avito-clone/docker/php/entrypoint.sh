#!/bin/sh
set -e

# Папка для фото должна быть доступна на запись пользователю приложения.
# На Docker Desktop (Windows/macOS) chown по bind-mount может не поддерживаться — это не страшно.
mkdir -p /var/www/html/public/uploads
chown app:app /var/www/html/public/uploads 2>/dev/null || true
chmod 777 /var/www/html/public/uploads 2>/dev/null || true

# Дальше — без прав root
exec su-exec app "$@"
