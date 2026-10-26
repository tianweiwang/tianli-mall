FROM composer:2.10.2 AS composer-bin

FROM php:8.2-fpm-bookworm AS php-base

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        ftp \
        gd \
        mysqli \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear /var/lib/apt/lists/*

FROM php-base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www

COPY crmeb/composer.json crmeb/composer.lock ./
COPY crmeb/crmeb/compat/ crmeb/compat/
COPY crmeb/crmeb/services/image/Image.php crmeb/services/image/Image.php
COPY crmeb/crmeb/services/image/gif/ crmeb/services/image/gif/
COPY crmeb/crmeb/services/wechat/Collection.php crmeb/services/wechat/Collection.php

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --no-plugins --no-scripts

COPY crmeb/ /var/www/

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
        --no-plugins --no-scripts \
    && php think service:discover \
    && php think vendor:publish

FROM php-base AS runtime

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-runtime.ini
COPY docker/php/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/php/runtime-entrypoint.sh /usr/local/bin/runtime-entrypoint
COPY docker/php/runtime-healthcheck.php /usr/local/bin/runtime-healthcheck.php
COPY --chown=root:root docker/php/marketplace-startup-check.php /usr/local/bin/marketplace-startup-check.php
COPY --from=vendor --chown=root:root /var/www/ /var/www/

RUN mkdir -p /var/www/runtime /var/www/public/uploads /run/application \
    && ln -s /run/application/.env /var/www/.env \
    && touch /var/www/public/install.lock \
    && chown -R www-data:www-data /var/www/runtime /var/www/public/uploads \
    && chmod 0755 /usr/local/bin/runtime-entrypoint \
    && chmod 0644 /usr/local/bin/marketplace-startup-check.php

# Docker initializes a new empty private volume from these image-owned directories.
# Existing volumes and their files are never chowned or rewritten at startup.
RUN install -d -o root -g root -m 0755 /private \
    && for name in customer-form aftersale refund licence withdrawal chat product-video product-media-scan product-video-cloud; do \
        install -d -o www-data -g www-data -m 0700 "/private/$name"; \
    done

STOPSIGNAL SIGTERM

ENTRYPOINT ["runtime-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
