#!/usr/bin/env bash
# Обновление боевого сервера, где сайт стоит за nginx на хосте (docker-compose.nginx.yml):
#   bash bin/server-update.sh
# Ключи входа через Яндекс/Google задаются отдельным скриптом: bash bin/setup-oauth.sh
# Что делает: git pull → дописывает недостающие ключи в .env (генерирует APP_SECRET) →
# пересобирает и перезапускает app/ws/db/coturn (миграции БД применяются сами; coturn — TURN-сервер для звонков) →
# добавляет в nginx проксирование WebSocket (/ws), лимит загрузок 64m (видео) и X-Forwarded-For (реальный IP),
# проверяет конфиг и перезагружает nginx.
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
# Звонки: общий секрет с TURN-сервером и его публичный IP
if [ -z "$(get_env TURN_SECRET)" ]; then
    set_env TURN_SECRET "$(openssl rand -hex 24)"
    echo "TURN_SECRET сгенерирован"
fi
grep -q "^TURN_EXTERNAL_IP=" .env || set_env TURN_EXTERNAL_IP ""
if [ -z "$(get_env TURN_EXTERNAL_IP)" ]; then
    for url in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
        ip="$(curl -4 -fsS --max-time 6 "$url" 2>/dev/null | tr -d '[:space:]' || true)"
        if printf '%s' "$ip" | grep -Eq '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'; then
            set_env TURN_EXTERNAL_IP "$ip"
            echo "Публичный IP сервера для звонков: $ip"
            break
        fi
    done
fi
# Старые настройки входа через VK больше не нужны
sed -i '/^VK_CLIENT_ID=/d;/^VK_SCOPE=/d' .env
DOMAIN="$(get_env DOMAIN)"

say "Собираю и перезапускаю контейнеры (app, ws, db, coturn)"
"${COMPOSE[@]}" up -d --build app ws db coturn
"${COMPOSE[@]}" rm -sf caddy >/dev/null 2>&1 || true

say "Брандмауэр: порты для звонков (TURN)"
TURN_MIN="$(get_env TURN_MIN_PORT)"; TURN_MIN="${TURN_MIN:-49160}"
TURN_MAX="$(get_env TURN_MAX_PORT)"; TURN_MAX="${TURN_MAX:-49200}"
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 3478/udp >/dev/null && ufw allow 3478/tcp >/dev/null && ufw allow "${TURN_MIN}:${TURN_MAX}/udp" >/dev/null
    echo "ufw: открыты 3478/udp, 3478/tcp и ${TURN_MIN}:${TURN_MAX}/udp"
else
    echo "ufw не активен — ничего не меняю."
fi
echo "ВАЖНО: если у хостинга есть свой брандмауэр (группы безопасности, «Firewall» в панели), откройте там:"
echo "       3478 UDP и TCP, а также ${TURN_MIN}-${TURN_MAX} UDP — иначе звонки через сервер не заработают."

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

say "Настраиваю nginx: размер загрузок (видео до 50 МБ) и реальный IP посетителей"
if [ -f "$NGINX_CONF" ]; then
    changed=0
    cp "$NGINX_CONF" "$NGINX_CONF.bak.$(date +%s)"
    # 1) client_max_body_size не меньше 64m (во всех серверных блоках файла)
    if grep -Eq 'client_max_body_size' "$NGINX_CONF"; then
        if grep -Eq 'client_max_body_size[[:space:]]+([0-9]|[1-5][0-9]|6[0-3])m;' "$NGINX_CONF"; then
            sed -i -E 's/(client_max_body_size[[:space:]]+)([0-9]|[1-5][0-9]|6[0-3])m;/\164m;/' "$NGINX_CONF"; changed=1
            echo "client_max_body_size увеличен до 64m"
        fi
    else
        awk '{ print } !done && $0 ~ /^[[:space:]]*server_name[[:space:]]/ { print "    client_max_body_size 64m;"; done = 1 }' "$NGINX_CONF" > "$NGINX_CONF.new" \
            && cp "$NGINX_CONF.new" "$NGINX_CONF" && rm -f "$NGINX_CONF.new"; changed=1
        echo "client_max_body_size 64m добавлен"
    fi
    # 2) location / должен передавать X-Forwarded-For (иначе все посетители будут с одним IP)
    if ! awk '/location[[:space:]]+\/[[:space:]]*\{/ {inloc=1} inloc && /X-Forwarded-For/ {found=1} inloc && /^[[:space:]]*\}/ {inloc=0} END {exit !found}' "$NGINX_CONF"; then
        proto=1; grep -q 'X-Forwarded-Proto' "$NGINX_CONF" && proto=0
        awk -v proto="$proto" '{ print } !done && $0 ~ /^[[:space:]]*location[[:space:]]+\/[[:space:]]*\{/ { print "        proxy_set_header X-Forwarded-For $remote_addr;"; if (proto == 1) print "        proxy_set_header X-Forwarded-Proto $scheme;"; done = 1 }' "$NGINX_CONF" > "$NGINX_CONF.new" \
            && cp "$NGINX_CONF.new" "$NGINX_CONF" && rm -f "$NGINX_CONF.new"; changed=1
        echo "в location / добавлена передача X-Forwarded-For"
    fi
    if [ "$changed" = 1 ]; then
        if nginx -t; then
            systemctl reload nginx && echo "nginx перезагружен"
        else
            latest="$(ls -t "$NGINX_CONF".bak.* | head -n1)"
            cp "$latest" "$NGINX_CONF"
            echo "nginx -t не прошёл — конфиг возвращён из $latest. Поправьте client_max_body_size и X-Forwarded-For вручную (DEPLOY.md)" >&2
        fi
    else
        echo "Всё уже настроено"
    fi
fi

say "Проверка"
"${COMPOSE[@]}" ps
if command -v ss >/dev/null 2>&1; then
    ss -lun 2>/dev/null | grep -q ':3478 ' && echo "TURN-сервер слушает UDP 3478" || echo "ВНИМАНИЕ: coturn не слушает UDP 3478 — посмотрите логи: docker compose -f docker-compose.prod.yml -f docker-compose.nginx.yml logs coturn" >&2
fi
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
