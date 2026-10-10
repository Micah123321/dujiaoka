#!/bin/sh
set -eu
rootfs=$1
marker=$2
entry=${3:-native}
case "$entry" in
    native) set -- /bin/sh /probe/native-commands.sh ;;
    http) set -- /usr/local/bin/php /probe/http-contract.php ;;
    queue) set -- /usr/local/bin/php /probe/queue-contract.php ;;
    queue-application) set -- /usr/bin/setpriv --inh-caps=-all --ambient-caps=-all --bounding-set=-all --no-new-privs /usr/local/bin/php /probe/queue-contract.php ;;
    *) exit 2 ;;
esac
base=/var/tmp/dujiaoka-image50-$marker
[ "$rootfs" = "$base/rootfs" ]
[ "$(cat "$base/.marker")" = "$marker" ]
if [ "$entry" = queue-application ]; then
    expected_uid=1000
    expected_map=1000:1000:1
else
    expected_uid=0
    expected_map=0:1000:1
fi
[ "$(id -u)" = "$expected_uid" ]
[ "$(id -g)" = "$expected_uid" ]
[ "$(awk '{print $1 ":" $2 ":" $3}' /proc/self/uid_map)" = "$expected_map" ]
[ "$(awk '{print $1 ":" $2 ":" $3}' /proc/self/gid_map)" = "$expected_map" ]
for folder in dev proc app probe; do
    [ ! -L "$rootfs/$folder" ]
    [ -d "$rootfs/$folder" ] || mkdir "$rootfs/$folder"
done
mount --make-rprivate /
mount -t proc proc "$rootfs/proc"
for device in null zero random urandom; do
    [ ! -L "$rootfs/dev/$device" ]
    [ -e "$rootfs/dev/$device" ] || touch "$rootfs/dev/$device"
    mount --bind "/dev/$device" "$rootfs/dev/$device"
done
printf '%s\n' "$(id)" > "$rootfs/probe/namespace-id.txt"
cat /proc/self/uid_map > "$rootfs/probe/namespace-uid-map.txt"
cat /proc/self/gid_map > "$rootfs/probe/namespace-gid-map.txt"
exec chroot "$rootfs" /usr/bin/env -i \
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    HOME=/root LANG=C.UTF-8 LC_ALL=C.UTF-8 PHP_INI_DIR=/usr/local/etc/php PYTHONDONTWRITEBYTECODE=1 \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/probe/composer-home \
    COMPOSER_CACHE_DIR=/probe/composer-cache COMPOSER_DISABLE_NETWORK=1 \
    APP_BASE_PATH=/app APP_ENV=production APP_DEBUG=false \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=http://127.0.0.1:1 LARAVEL_STORAGE_PATH=/app/storage \
    APP_CONFIG_CACHE=/app/bootstrap/cache/config.php APP_ROUTES_CACHE=/app/bootstrap/cache/routes.php \
    APP_PACKAGES_CACHE=/app/bootstrap/cache/packages.php APP_SERVICES_CACHE=/app/bootstrap/cache/services.php \
    APP_EVENTS_CACHE=/app/bootstrap/cache/events.php \
    DB_CONNECTION=sqlite DB_DATABASE=/app/storage/image-fixture.sqlite DATABASE_URL= \
    CACHE_STORE=file CACHE_DRIVER=file CACHE_PREFIX=image50 SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync MAIL_DRIVER=array MAIL_MAILER=array LOG_CHANNEL=single \
    API_HOOK_OUTBOX=false PAYMENT_MAIL_OUTBOX=false MANUAL_STATUS_MAIL_OUTBOX=false PAYMENT_QUOTE_FREEZE=false \
    STRIPE_PAYMENT_INTENTS=false STRIPE_RECONCILIATION=false PAYPAL_ORDERS_V2=false PAYPAL_RECONCILIATION=false \
    DUJIAO_ADMIN_LANGUAGE=en ADMIN_HTTPS=false ADMIN_ROUTE_DOMAIN= ADMIN_ASSETS_SERVER= \
    "$@"
