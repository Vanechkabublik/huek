# Лёгкий образ: Alpine + PHP 8.3 из готовых пакетов (без компиляции) + встроенный сервер PHP.
# Скачивается ~30 МБ вместо ~500 МБ у php:8.2-apache. Вариант на Apache — docker/php/apache/Dockerfile.
FROM alpine:3.20

# pdo_pgsql — PostgreSQL, gd — ресайз/перекодирование фото, exif — поворот по EXIF,
# mbstring/fileinfo/session/ctype — используются приложением, su-exec — запуск без root.
RUN apk add --no-cache php83 php83-pdo_pgsql php83-gd php83-exif php83-mbstring php83-fileinfo php83-session php83-ctype su-exec \
    && ln -sf /usr/bin/php83 /usr/bin/php \
    && adduser -D -H -s /sbin/nologin app \
    && php -r 'foreach (["pdo_pgsql","gd","exif","mbstring","fileinfo","session","ctype","filter","json"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Не загружено расширение PHP: $e\n"); exit(1); } }' \
    && php -v

COPY docker/php/php.ini /etc/php83/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# Несколько процессов встроенного сервера: браузер грузит страницу и картинки параллельно
ENV PHP_CLI_SERVER_WORKERS=8
WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["php", "-S", "0.0.0.0:80", "-t", "public", "docker/php/router.php"]
