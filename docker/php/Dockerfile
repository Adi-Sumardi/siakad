FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        mbstring \
        bcmath \
        gd \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

# --no-scripts: composer's post-autoload-dump hook runs `artisan package:discover`,
# which reads composer.json's require-dev list directly (not just what's actually
# installed) and errors on dev-only packages like laravel/pail that --no-dev never
# installed. Runtime provider loading (config:cache in entrypoint.sh) introspects
# vendor/composer/installed.json instead, which only lists what's really there, so
# skipping the hook here doesn't lose anything.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
