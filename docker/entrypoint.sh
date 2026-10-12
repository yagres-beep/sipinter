#!/bin/sh
set -e

export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/sites-available/default.template > /etc/nginx/sites-available/default

# Folder storage bisa kosong bila suatu saat dipasang disk/volume baru — pastikan
# struktur yang dibutuhkan Laravel selalu ada dan bisa ditulis oleh PHP-FPM.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Migrasi dijalankan SEBELUM cache dibangun. Bila migrasi gagal, `set -e` menghentikan
# container sehingga Render menandai deploy gagal dan tetap melayani versi sebelumnya.
php artisan migrate --force

# config, route, view & event cache sekaligus (pengganti config:cache/route:cache/view:cache).
php artisan optimize:clear >/dev/null
php artisan optimize

# Cache dibangun oleh root — serahkan kepemilikannya ke www-data supaya PHP-FPM
# masih bisa menulis ulang (mis. view yang dikompilasi ulang).
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
