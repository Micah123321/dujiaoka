#!/bin/sh
set -eu

mkdir -p /data /app/storage/logs /app/storage/framework/cache/data \
    /app/storage/framework/sessions /app/storage/framework/views /app/storage/app \
    /app/bootstrap/cache /app/public/uploads

# Keep the installer-written key, configuration and lock across image upgrades.
if [ ! -f /data/.env ]; then
    umask 077
    php -r '$template = file_get_contents("/app/deploy/runtime.env.example"); file_put_contents("/data/.env", str_replace("__APP_KEY__", "base64:" . base64_encode(random_bytes(32)), $template));'
fi
ln -sfn /data/.env /app/.env
ln -sfn /data/install.lock /app/install.lock
chown -R application:application /data /app/storage /app/bootstrap/cache /app/public/uploads
chmod 0750 /data
chmod 0600 /data/.env
# Nginx serves public uploads under a different user from PHP-FPM.
chmod -R u=rwX,go=rX /app/public/uploads
su -s /bin/sh application -c 'cd /app && php artisan package:discover --ansi'
