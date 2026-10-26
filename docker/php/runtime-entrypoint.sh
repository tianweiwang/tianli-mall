#!/bin/sh
set -eu

MARKETPLACE_QUEUE_AUTOSTART="${MARKETPLACE_QUEUE_AUTOSTART:-false}"
case "$MARKETPLACE_QUEUE_AUTOSTART" in
    true|false) export MARKETPLACE_QUEUE_AUTOSTART ;;
    *) echo "Invalid MARKETPLACE_QUEUE_AUTOSTART; expected true or false" >&2; exit 2 ;;
esac

/usr/local/bin/php -d open_basedir= -d display_errors=0 -d display_startup_errors=0 -d log_errors=0 /usr/local/bin/marketplace-startup-check.php

mkdir -p /var/www/runtime /var/www/public/uploads
chown -R www-data:www-data /var/www/runtime /var/www/public/uploads
rm -f /var/www/runtime/workerman.pid /var/www/runtime/timer.pid

exec docker-php-entrypoint "$@"
