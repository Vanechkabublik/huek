#!/usr/bin/env bash
# Интерактивная настройка входа через Яндекс и Google на боевом сервере:
#   bash bin/setup-oauth.sh            # сайт за nginx на хосте (docker-compose.nginx.yml)
#   bash bin/setup-oauth.sh --caddy    # сайт за Caddy (только docker-compose.prod.yml)
# Скрипт спрашивает ключи (секреты вводятся скрыто и не попадают в историю команд), записывает их в .env
# и перезапускает app и ws. Пустой ответ — оставить текущее значение. Где взять ключи — docs/OAUTH_LOGIN.md.
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f docker-compose.prod.yml)
[ "${1:-}" = "--caddy" ] || COMPOSE+=(-f docker-compose.nginx.yml)

[ -f .env ] || { echo "Нет файла .env — сначала создайте его: cp .env.prod.example .env" >&2; exit 1; }

get_env() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- || true; }

# Записывает KEY=VALUE в .env без sed (в значении могут быть любые символы из белого списка)
set_env() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp .env.XXXXXX)"
    grep -v "^${key}=" .env > "$tmp" || true
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    chmod --reference=.env "$tmp" 2>/dev/null || chmod 600 "$tmp"
    mv "$tmp" .env
}

# ask KEY "Подсказка" [secret]
ask() {
    local key="$1" label="$2" secret="${3:-}" cur value hint=""
    cur="$(get_env "$key")"
    [ -n "$cur" ] && hint=" (Enter — оставить текущее)"
    if [ -n "$secret" ]; then
        read -rsp "$label$hint: " value; echo
    else
        read -rp "$label$hint: " value
    fi
    value="$(printf '%s' "$value" | tr -d '[:space:]')"
    [ -n "$value" ] || return 0
    if ! printf '%s' "$value" | grep -Eq '^[A-Za-z0-9._~+/=:@-]+$'; then
        echo "В значении есть недопустимые символы — проверьте, что скопировали только ключ." >&2
        exit 1
    fi
    set_env "$key" "$value"
}

DOMAIN="$(get_env DOMAIN)"
echo "Адрес для кабинетов Яндекса и Google (Redirect URI): https://${DOMAIN:-ваш-домен}/oauth-callback.php"
echo

echo "== Яндекс (https://oauth.yandex.ru/ → ваше приложение)"
ask YANDEX_CLIENT_ID "ClientID"
ask YANDEX_CLIENT_SECRET "Client secret (необязателен; ввод скрыт)" secret
echo
echo "== Google (https://console.cloud.google.com/auth/clients)"
ask GOOGLE_CLIENT_ID "Client ID"
ask GOOGLE_CLIENT_SECRET "Client secret (ввод скрыт)" secret

echo
echo "Перезапускаю сайт…"
"${COMPOSE[@]}" up -d app ws

echo
echo "Яндекс: $([ -n "$(get_env YANDEX_CLIENT_ID)" ] && echo включён || echo 'не настроен')"
if [ -n "$(get_env GOOGLE_CLIENT_ID)" ] && [ -z "$(get_env GOOGLE_CLIENT_SECRET)" ]; then
    echo "Google: ВНИМАНИЕ — задан Client ID, но нет Client secret; кнопка Google останется скрытой"
else
    echo "Google: $([ -n "$(get_env GOOGLE_CLIENT_ID)" ] && echo включён || echo 'не настроен')"
fi
echo "Проверьте: https://${DOMAIN:-ваш-домен}/login.php — под формой должны быть кнопки входа."
