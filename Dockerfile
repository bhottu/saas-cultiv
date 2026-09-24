FROM php:8.2-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev libzip-dev zip oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl zip bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Production PHP defaults
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.jit=tracing"; \
    echo "opcache.jit_buffer_size=64M"; \
    echo "opcache.validate_timestamps=0"; \
    echo "realpath_cache_size=4096K"; \
    echo "memory_limit=256M"; \
  } > /usr/local/etc/php/conf.d/saas.ini

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan config:cache --no-ansi || true \
    && php artisan route:cache --no-ansi || true \
    && php artisan view:cache --no-ansi || true \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
