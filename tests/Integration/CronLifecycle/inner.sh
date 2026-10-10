#!/bin/sh
set -eu
rootfs=$1
mount -t proc proc "$rootfs/proc"
chroot "$rootfs" /usr/local/bin/php /app/namespace-proof.php
exec chroot "$rootfs" /usr/sbin/cron -f -L 15
