#!/usr/bin/env bash
# Обновление боевого сервера, где сайт стоит за nginx на хосте (docker-compose.nginx.yml):
#   bash bin/server-update.sh
# Ключи входа через Яндекс/Google задаются отдельным скриптом: bash bin/setup-oauth.sh
# Что делает: git pull → дописывает недостающие ключи в .env (генерирует APP_SECRET) →
# пересобирает и перезапускает app/ws/db (миграции БД применяются сами) →
# добавляет в nginx проксирование WebSocket (/ws), проверяет конфиг и перезагружает nginx.
# Можно запускать повторно — все шаги идемпотентны.
set -euo pipefail

cd "$(dirname "$0")/.."
NGINX_CONF="${NGINX_CONF:-/etc/nginx/sites-available/board}"
COMPOSE=(docker compose -f docker-compose.prod.yml -f docker-compose.nginx.yml)

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

[ -f .env ] || { echo "Нет файла .env — сначала создайте его: cp .env.prod.example .env" >&2; exit 1; }

# Записывает KEY=VALUE в .env (заменяет существующую строку или добавляет новую)
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}
get_env() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- || true; }

say "Получаю свежий код"
git pull --ff-only

say "Проверяю .env"
if [ -z "$(get_env APP_SECRET)" ]; then
    set_env APP_SECRET "$(openssl rand -hex 32)"
    echo "APP_SECRET сгенерирован"
fi
for key in YANDEX_CLIENT_ID YANDEX_CLIENT_SECRET GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET; do
    grep -q "^${key}=" .env || set_env "$key" ""
done
# Старые настройки входа через VK больше не нужны
sed -i '/^VK_CLIENT_ID=/d;/^VK_SCOPE=/d' .env
DOMAIN="$(get_env DOMAIN)"

say "Собираю и перезапускаю контейнеры (app, ws, db)"
"${COMPOSE[@]}" up -d --build app ws db
"${COMPOSE[@]}" rm -sf caddy >/dev/null 2>&1 || true

say "Настраиваю nginx: WebSocket /ws"
if [ ! -f "$NGINX_CONF" ]; then
    echo "Не найден $NGINX_CONF — пропускаю. Добавьте location /ws вручную (см. DEPLOY.md), путь можно передать так: NGINX_CONF=/путь bash bin/server-update.sh" >&2
elif grep -Eq 'location[[:space:]]+(=[[:space:]]+)?/ws[[:space:]]*\{' "$NGINX_CONF"; then
    echo "location /ws уже есть — ничего менять не нужно"
else
    cp "$NGINX_CONF" "$NGINX_CONF.bak.$(date +%s)"
    # Вставляем блок перед первым «location / {» (это серверный блок с проксированием сайта)
    awk '
        !done && $0 ~ /^[[:space:]]*location[[:space:]]+\/[[:space:]]*\{/ {
            print "    # WebSocket чата"
            print "    location /ws {"
            print "        proxy_pass http://127.0.0.1:8081;"
            print "        proxy_http_version 1.1;"
            print "        proxy_set_header Upgrade $http_upgrade;"
            print "        proxy_set_header Connection \"upgrade\";"
            print "        proxy_set_header Host $host;"
            print "        proxy_set_header X-Forwarded-For $remote_addr;"
            print "        proxy_read_timeout 3600s;"
            print "        proxy_send_timeout 3600s;"
            print "    }"
            print ""
            done = 1
        }
        { print }
    ' "$NGINX_CONF" > "$NGINX_CONF.new"
    if ! grep -q 'location /ws' "$NGINX_CONF.new"; then
        rm -f "$NGINX_CONF.new"
        echo "В $NGINX_CONF не нашёлся «location / {» — добавьте блок /ws вручную (см. DEPLOY.md)" >&2
    else
        cp "$NGINX_CONF.new" "$NGINX_CONF" && rm -f "$NGINX_CONF.new"
        if nginx -t; then
            systemctl reload nginx
            echo "nginx перезагружен"
        else
            latest="$(ls -t "$NGINX_CONF".bak.* | head -n1)"
            cp "$latest" "$NGINX_CONF"
            echo "nginx -t не прошёл — конфиг возвращён из $latest" >&2
            exit 1
        fi
    fi
fi

say "Проверка"
"${COMPOSE[@]}" ps
if [ -n "$DOMAIN" ]; then
    printf 'Сайт:      '; curl -s -o /dev/null -w '%{http_code}\n' "https://$DOMAIN/" || true
    printf 'WebSocket: '; curl -s -o /dev/null -w '%{http_code} (400/426 — это нормально без Upgrade, главное — не 502/404)\n' "https://$DOMAIN/ws" || true
fi
echo
yandex=$([ -n "$(get_env YANDEX_CLIENT_ID)" ] && echo включён || echo 'не настроен')
google=$([ -n "$(get_env GOOGLE_CLIENT_ID)" ] && echo включён || echo 'не настроен')
echo "Готово. Вход через Яндекс: $yandex; через Google: $google."
if [ "$yandex" != "включён" ] || [ "$google" != "включён" ]; then
    echo "Настроить: bash bin/setup-oauth.sh (инструкция — docs/OAUTH_LOGIN.md)"
fi
