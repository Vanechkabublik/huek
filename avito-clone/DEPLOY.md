# Выкладка в интернет: свой поддомен + HTTPS

Схема:

```
Интернет ──443/80──▶ Caddy (SSL, Let's Encrypt) ──▶ app (PHP) ──▶ db (PostgreSQL)
                      единственный открытый порт      внутри docker-сети, снаружи недоступны
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

Заполните `DOMAIN`, `ACME_EMAIL` и `DB_PASS` (пароль БД — длинный и случайный: `openssl rand -base64 24`).
**Пароль БД задайте до первого запуска** — он записывается в том `pg_data` один раз.

## 5. Запуск

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f caddy      # ждём «certificate obtained successfully», выход — Ctrl+C
```

Откройте **https://board.example.com**.

## 6. Обязательно после первого запуска

В базе лежат демо-данные с известными паролями (`admin@example.com / admin123` и т. д.). На публичном сайте их надо убрать:

1. **Сменить пароль админа:**
   ```bash
   docker compose -f docker-compose.prod.yml exec app php bin/set-password.php admin@example.com 'ВашНадёжныйПароль'
   ```
   (минимум 8 символов; пароль останется в истории команд shell — после смены можно очистить её командой `history -c`).
2. Войти в `/admin.php` → **Пользователи** → удалить демо-пользователей Иван и Мария (их объявления удалятся вместе с ними).
3. При желании переименуйте/отредактируйте категории в разделе **Категории**.

Кнопки быстрого входа с демо-паролями на странице входа на боевом сервере не показываются (они включаются только переменной `APP_DEMO=1`).

## Обновление сайта

```bash
cd huek/avito-clone
git pull
docker compose -f docker-compose.prod.yml up -d --build
```

Данные (БД, фото, сертификаты) лежат в томах `pg_data`, `caddy_data` и в папке `public/uploads` — при обновлении они сохраняются.
Схема БД применяется только при первом создании тома; если в будущем схема изменится, понадобится миграция вручную.

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
| `address already in use` на 80/443 | Порты заняты nginx/Apache: остановите их (`sudo systemctl stop nginx apache2`) или используйте вариант ниже. |
| Сайт открывается, но вход «сбрасывается» | Проверьте, что заходите по `https://` (cookie помечается `Secure`, когда Caddy передаёт `X-Forwarded-Proto: https`). |
| Фото не загружаются | Лимит 5 МБ на файл; папка `public/uploads` должна быть доступна на запись (`ls -ld public/uploads`). |
| Ошибка 500 | `docker compose -f docker-compose.prod.yml logs --tail=100 app`. Для подробностей временно добавьте `APP_DEBUG: "1"` в `docker-compose.prod.yml` (не оставляйте включённым). |

## Уже есть nginx на сервере (вместо Caddy)

1. В `docker-compose.prod.yml` удалите сервис `caddy` (и тома `caddy_*`), а у `app` вместо `expose:` укажите
   `ports: ["127.0.0.1:8080:80"]` — приложение будет доступно только самому серверу.
2. Конфиг nginx (`/etc/nginx/sites-available/board`):
   ```nginx
   server {
       server_name board.example.com;
       client_max_body_size 12m;

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-For $remote_addr;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```
3. Сертификат: `sudo certbot --nginx -d board.example.com` (certbot сам допишет https и редирект).

## Замечание о производительности

По умолчанию контейнер `app` использует лёгкий образ со встроенным сервером PHP (8 процессов) — этого хватает для небольшого
сайта. Для заметной нагрузки переключитесь на Apache: в `docker-compose.prod.yml` замените
`dockerfile: docker/php/Dockerfile` на `docker/php/apache/Dockerfile` (образ `php:8.2-apache`, на сервере с нормальным каналом
скачивается быстро) и пересоберите: `docker compose -f docker-compose.prod.yml up -d --build`.
