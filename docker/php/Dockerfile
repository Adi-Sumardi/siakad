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

# Remove any stale bootstrap caches and optimize autoloader for production.
# mkdir -p storage/framework/{cache,sessions,testing,views} (brace expansion)
# does NOT work under this base image's default shell (/bin/sh, busybox ash,
# not bash) - it silently creates one literally-named directory instead of
# four real ones, which only surfaces once something touches an EMPTY
# storage volume (realpath() on the missing framework/views dir returns
# false, config('view.compiled') caches as empty, every request 500s with
# "Please provide a valid cache path"). Invisible on a volume that already
# has the right folders from before this line existed - exactly the bug PMB
# hit deploying to a genuinely fresh volume (see infra/memory.md,
# 2026-09-17). Each directory spelled out explicitly here instead.
RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# This base image's own defaults (upload_max_filesize=2M, post_max_size=8M)
# are smaller than what Laravel's own validation already allows (achievement
# uploads can total ~10MB across two files) - PHP would silently drop the
# whole request before the validator ever runs. Same gap PMB found and fixed
# 2026-09-18, confirmed present here too - see docker/php/uploads.ini for
# the exact worst-case sizing.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Default pm.max_children=5 (this base image) queues nearly everything past a
# handful of concurrent requests - PMB hit this exact gap the day before its
# own launch (infra/memory.md, 2026-09-17). sed edits the base image's
# www.conf in place rather than COPY-replacing the whole file - a replace
# drops every directive the new file doesn't repeat (user, group, listen,
# ...), which crash-looped PHP-FPM in production on PMB's first attempt at
# this same fix (infra/memory.md, same date). 25 rather than higher: PDF
# generation here still runs synchronously in the request path (no
# QueuedPdfRenderer/RenderPdfJob yet, unlike PMB - see that gap if this ever
# needs raising), and each such worker can use 150-250MB against this node's
# 4GB budget.
RUN sed -i 's/^pm.max_children = .*/pm.max_children = 25/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.start_servers = .*/pm.start_servers = 5/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 3/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 10/' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
