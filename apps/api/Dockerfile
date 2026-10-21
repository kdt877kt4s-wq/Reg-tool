# RuleRadar API image. One image, four roles (see docker/entrypoint.sh):
# web (HTTP on :8080), worker (queue), scheduler, migrate.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# Resolve for the PHP version the runtime image has (8.3), not the
# composer image's own; PHP extensions are installed in the runtime stage.
RUN composer config platform.php 8.3.30 \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-req='ext-*'
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM dunglas/frankenphp:1-php8.3-bookworm
RUN install-php-extensions pdo_pgsql pgsql redis intl zip opcache bcmath pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { echo 'expose_php=Off'; echo 'upload_max_filesize=26M'; echo 'post_max_size=28M'; echo 'memory_limit=256M'; \
         echo 'opcache.enable=1'; echo 'opcache.validate_timestamps=0'; } > "$PHP_INI_DIR/conf.d/ruleradar.ini"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=":8080"

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/ruleradar
RUN chmod +x /usr/local/bin/ruleradar \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/views storage/logs storage/app/private \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

USER www-data
EXPOSE 8080
ENTRYPOINT ["ruleradar"]
CMD ["web"]
