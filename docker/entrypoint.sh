#!/bin/sh
set -e

export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/sites-available/default.template > /etc/nginx/sites-available/default

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
