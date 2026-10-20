# Выкладка в интернет: свой поддомен + HTTPS

Схема:

```
Интернет ──443/80──▶ Caddy (SSL, Let's Encrypt) ──┬─ /      ─▶ app (PHP: сайт и API) ──▶ db (PostgreSQL)
                      единственный открытый порт   └─ /ws    ─▶ ws  (WebSocket чата)  ──▶ db
                                                   app, ws и db снаружи недоступны
```

Caddy сам получает сертификат, продлевает его и перенаправляет `http → https`. Ничего настраивать вручную (certbot, cron) не нужно.

## Что понадобится

- Linux-сервер (Ubuntu/Debian) с публичным IP и установленными Docker + Docker Compose v2 (`docker compose version`).
- Поддомен, например `board.example.com`.
- Свободные порты **80 и 443** на сервере (если там уже стоит nginx/Apache — см. раздел «Уже есть nginx» ниже).

## 1. DNS

У регистратора/DNS-хостинга создайте **A-запись**: `board` → IP сервера. Проверка (должен вернуться IP сервера):

```bash
nslookup board.example.com
```

- Если есть AAAA-запись (IPv6), она должна вести на этот же сервер — иначе удалите её.
- Если домен за Cloudflare, на время выпуска сертификата выключите «оранжевое облако» (режим **DNS only**), потом можно включить обратно с SSL-режимом *Full (strict)*.
- DNS-записи обновляются от пары минут до нескольких часов.

## 2. Порты

Откройте 80 и 443 в файрволе сервера и (если есть) в панели хостинга:

```bash
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw allow 443/udp
```

Порт 80 нужен Let's Encrypt для проверки домена и для редиректа на https.

## 3. Код на сервер

```bash
git clone --branch claude/relaxed-davinci-a4exs9 https://github.com/Vanechkabublik/huek.git
cd huek/avito-clone
```

## 4. Настройки

```bash
cp .env.prod.example .env
nano .env
```

Заполните `DOMAIN`, `ACME_EMAIL`, `DB_PASS` (пароль БД — длинный и случайный: `openssl rand -base64 24`) и `APP_SECRET`
(`openssl rand -hex 32` — подпись тикетов WebSocket-чата).
**Пароль БД задайте до первого запуска** — он записывается в том `pg_data` один раз.

Вход через Яндекс и Google подключается отдельно и необязателен — см. [docs/OAUTH_LOGIN.md](docs/OAUTH_LOGIN.md) (там же, где взять ключи).

## 5. Запуск

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f caddy      # ждём «certificate obtained successfully», выход — Ctrl+C
```

Откройте **https://board.example.com**.

## 6. Первый администратор

Демо-данные (пользователи Иван и Мария с известными паролями, тестовые объявления и чаты) заливаются **только** при `APP_DEMO=1`;
на боевом сервере в `.env` оставьте `APP_DEMO=0` — тогда после первого запуска создаются лишь категории с характеристиками.
Создайте администратора:

```bash
docker compose -f docker-compose.prod.yml exec app php bin/create-admin.php you@example.com 'ВашНадёжныйПароль' 'Ваше имя'
```

(пароль — минимум 8 символов; он останется в истории команд shell — после создания можно очистить её командой `history -c`).
Эта же команда делает администратором уже существующего пользователя и сбрасывает ему пароль. Сменить пароль админа позже:
`docker compose -f docker-compose.prod.yml exec app php bin/set-password.php you@example.com 'НовыйПароль'`.

Затем зайдите в `/admin.php`. Раздел **Интеграции** показывает состояние входа через Яндекс/Google и WebSocket-чата, подсказывает, какие значения
вписать в кабинетах провайдеров, и предупреждает, если на боевом сервере остались включены демо-режим или отладка.

**Если на сервере уже есть демо-данные** (база создана прежней версией проекта или с `APP_DEMO=1`): в админке → **Пользователи** удалите
Ивана и Марию (их объявления, чаты и отзывы удалятся вместе с ними) и смените пароль демо-админа `admin@example.com`
(`bin/set-password.php`, как выше) — либо создайте своего администратора и удалите демо-админа.

## Обновление сайта

**Сайт за nginx на хосте** (вариант Б ниже) — одна команда: тянет код, дописывает недостающие настройки в `.env`
(в том числе генерирует `APP_SECRET`), пересобирает контейнеры, добавляет в nginx блок `/ws`, проверяет конфиг и перезагружает nginx:

```bash
cd ~/huek/avito-clone && bash bin/server-update.sh
```

**Сайт за Caddy:**

```bash
cd ~/huek/avito-clone
git pull
docker compose -f docker-compose.prod.yml up -d --build
```

Данные (БД, фото, сертификаты) лежат в томах `pg_data`, `caddy_data` и в папке `public/uploads` — при обновлении они сохраняются.
**Схема БД обновляется сама:** при каждом старте контейнер `app` выполняет `bin/migrate.php`, который применяет новые файлы из
`database/migrations/` (уже применённые пропускает; журнал — таблица `schema_migrations`). Старая база, созданная прежней версией
проекта, распознаётся и дообновляется без потери объявлений и сообщений (прежняя переписка переносится в новый чат).

## Резервные копии

```bash
# база
docker compose -f docker-compose.prod.yml exec -T db pg_dump -U ads_user ads > backup-$(date +%F).sql
# фото
tar czf uploads-$(date +%F).tar.gz public/uploads
```

Восстановление базы: `cat backup.sql | docker compose -f docker-compose.prod.yml exec -T db psql -U ads_user ads`
(на пустой базе — после `docker compose -f docker-compose.prod.yml down -v`; **внимание:** `-v` удаляет и тома, в том числе сертификаты Caddy).

## Если что-то не работает

| Симптом | Причина / что делать |
|---|---|
| Браузер пишет «небезопасно» / нет сертификата | Смотрите `logs caddy`. Чаще всего: DNS ещё не указывает на сервер, закрыты 80/443, домен за «оранжевым облаком» Cloudflare, либо исчерпан лимит Let's Encrypt (5 неудачных попыток в час — подождите). |
| `address already in use` на 80/443 | Порты заняты nginx/Apache — см. раздел «Порты 80/443 заняты» ниже. |
| Чат без мгновенной доставки, внизу баннер «Нет соединения в реальном времени» | Не проксируется `/ws` (в nginx нет блока `location /ws`, см. ниже) или не запущен контейнер `ws`: `docker compose -f docker-compose.prod.yml ps`, `logs --tail=50 ws`. Сообщения при этом всё равно доходят — опросом раз в 5 секунд. |
| Сайт открывается, но вход «сбрасывается» | Проверьте, что заходите по `https://` (cookie помечается `Secure`, когда Caddy передаёт `X-Forwarded-Proto: https`). |
| Фото не загружаются | Лимит 5 МБ на файл; папка `public/uploads` должна быть доступна на запись (`ls -ld public/uploads`). |
| Ошибка 500 | `docker compose -f docker-compose.prod.yml logs --tail=100 app`. Для подробностей временно добавьте `APP_DEBUG: "1"` в `docker-compose.prod.yml` (не оставляйте включённым). |

## Порты 80/443 заняты (уже есть nginx/Apache)

Узнайте, кто занял порт: `sudo ss -ltnp | grep -E ':(80|443)\b'`.

**Вариант А — освободить порты** (если этот nginx не нужен): `sudo systemctl disable --now nginx apache2`, затем
`docker compose -f docker-compose.prod.yml up -d`.

**Вариант Б — оставить nginx** и пустить сайт через него (Caddy не нужен):

1. Запустите `app`, `ws` и `db`; сайт будет слушать лишь `127.0.0.1:8080`, WebSocket — `127.0.0.1:8081`:
   ```bash
   docker compose -f docker-compose.prod.yml -f docker-compose.nginx.yml up -d --build app ws db
   docker compose -f docker-compose.prod.yml rm -sf caddy      # убрать неудавшийся контейнер Caddy
   ```
2. Конфиг nginx (`/etc/nginx/sites-available/board`, затем `sudo ln -s /etc/nginx/sites-available/board /etc/nginx/sites-enabled/`):
   ```nginx
   server {
       listen 80;
       server_name board.example.com;
       client_max_body_size 64m;          # фото до 5 МБ, видеообзор до 50 МБ

       # WebSocket чата — обязательно ДО location /
       location /ws {
           proxy_pass http://127.0.0.1:8081;
           proxy_http_version 1.1;
           proxy_set_header Upgrade $http_upgrade;
           proxy_set_header Connection "upgrade";
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-For $remote_addr;
           proxy_read_timeout 3600s;
           proxy_send_timeout 3600s;
       }

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-For $remote_addr;   # по нему сайт узнаёт реальный IP посетителя (баны, аналитика)
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_read_timeout 120s;                         # загрузка видео с медленного телефона
           proxy_send_timeout 120s;
       }
   }
   ```
   Если конфиг уже настроен (certbot добавил https), блок `/ws` можно вставить автоматически: `bash bin/server-update.sh`.
3. Проверить и применить: `sudo nginx -t && sudo systemctl reload nginx`.
4. Сертификат: `sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d board.example.com`
   (certbot сам допишет https и редирект и настроит автопродление).

## Реальный IP, геолокация, видео и политика данных

- **IP и User-Agent посетителя.** Сайт берёт IP из заголовка `X-Forwarded-For`, но только если запрос пришёл от доверенного прокси
  (по умолчанию localhost и частные сети — этого хватает для Caddy и для nginx на том же сервере). Строка
  `proxy_set_header X-Forwarded-For $remote_addr;` в nginx обязательна — без неё все посетители будут «на одном IP»
  и бан по IP заденет всех. Свой список доверенных прокси — переменная `TRUSTED_PROXIES` в `.env` (CIDR через запятую).
  Если сайт стоит за Cloudflare, добавьте его диапазоны в `TRUSTED_PROXIES` — иначе IP посетителей будут адресами Cloudflare.
  Проверить: войдите на сайт и откройте «Админка → Безопасность → Журнал входов» — там должен быть ваш реальный IP, а не `172.x`/`127.0.0.1`.
- **Геолокация города.** Браузер спрашивает разрешение сам; работает только по `https://` (в браузере это требование «безопасного контекста»).
  Координаты округляются до ~1 км и превращаются в город через OpenStreetMap Nominatim; результат кэшируется в БД.
  Публичный Nominatim бесплатен, но с лимитом ~1 запрос/сек — для большого трафика укажите свой сервер в `GEOCODER_URL`.
  Если геолокация недоступна, пользователь выбирает город вручную.
- **Видеообзор.** MP4/MOV/WebM до 50 МБ и до 3 минут. Лимиты загрузки уже подняты: `php.ini` (52M), Caddy (`max_size 64MB`),
  nginx (`client_max_body_size 64m`) — `bash bin/server-update.sh` исправит это в конфиге nginx сам. Ролики отдаются с поддержкой
  перемотки (HTTP Range); при большой нагрузке лучше отдавать `/uploads/` напрямую средствами nginx/Caddy.
- **Политика обработки данных и соглашение** — шаблоны, которые редактируются в «Админка → Документы». Обязательно впишите
  реквизиты оператора («Название и контакты») и проверьте тексты с юристом: сервис не заменяет юридическую консультацию.
  При каждом изменении текста версия согласия растёт, и пользователям снова предлагается принять документ.

## Звонки (голос и видео)

Звонки идут напрямую между браузерами, а когда это невозможно, — через TURN-сервер **coturn**, который `docker-compose.prod.yml` запускает
рядом с сайтом (в сети хоста). `bash bin/server-update.sh` сам: генерирует `TURN_SECRET`, определяет публичный IP сервера (`TURN_EXTERNAL_IP`),
поднимает coturn и открывает порты в `ufw`. **Если у хостинга есть свой брандмауэр** (группы безопасности, «Firewall» в панели управления),
откройте в нём вручную: **3478 UDP и TCP** и **49160–49200 UDP**. Без этого звонки между людьми в мобильных сетях могут не соединяться.

Проверка: позвоните самому себе из двух браузеров, откройте значок пинга — «Соединение: напрямую»; в консоли браузера `localStorage.setItem('callRelay','1')`
и повторите звонок — должно быть «через сервер (TURN)». Подробности и отладка — [docs/CALLS.md](docs/CALLS.md).
Микрофон и камера в браузерах работают только по `https://`. Чтобы собеседники не видели IP друг друга, добавьте в `.env` `CALLS_RELAY_ONLY=1`.

## Замечание о производительности

По умолчанию контейнер `app` использует лёгкий образ со встроенным сервером PHP (8 процессов) — этого хватает для небольшого
сайта. Для заметной нагрузки переключитесь на Apache: в `docker-compose.prod.yml` замените
`dockerfile: docker/php/Dockerfile` на `docker/php/apache/Dockerfile` (образ `php:8.2-apache`, на сервере с нормальным каналом
скачивается быстро) и пересоберите: `docker compose -f docker-compose.prod.yml up -d --build`.
