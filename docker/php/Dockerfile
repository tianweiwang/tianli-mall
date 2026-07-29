# syntax=docker/dockerfile:1.7

FROM ccr.ccs.tencentyun.com/crmebky_php/php:v7.4

RUN docker-php-ext-install -j"$(nproc)" opcache

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-runtime.ini
COPY docker/php/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/php/runtime-entrypoint.sh /usr/local/bin/runtime-entrypoint
COPY docker/php/runtime-healthcheck.php /usr/local/bin/runtime-healthcheck.php
COPY --chown=root:root crmeb/ /var/www/

RUN mkdir -p /var/www/runtime /var/www/public/uploads \
    && touch /var/www/public/install.lock \
    && chown -R www-data:www-data /var/www/runtime /var/www/public/uploads \
    && chmod 0755 /usr/local/bin/runtime-entrypoint

ENTRYPOINT ["runtime-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
