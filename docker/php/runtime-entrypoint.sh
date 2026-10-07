#!/bin/sh
set -eu

mkdir -p /var/www/runtime /var/www/public/uploads
chown -R www-data:www-data /var/www/runtime /var/www/public/uploads
rm -f /var/www/runtime/workerman.pid /var/www/runtime/timer.pid

exec docker-php-entrypoint "$@"
