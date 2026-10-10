#!/bin/sh
set -eu
project=$1
run=$2
rootfs=$3
case "$run" in "$project"/tests/.runtime/cron-lifecycle/run-*) ;; *) exit 64 ;; esac
case "$rootfs" in /tmp/dujiaoka-cron41.*) ;; *) exit 64 ;; esac
mount --make-rprivate /
cat /proc/self/uid_map > "$run/outer.uid-map"
cat /proc/self/gid_map > "$run/outer.gid-map"
cat /proc/self/setgroups > "$run/outer.setgroups"
readlink /proc/self/ns/user > "$run/outer.user-ns"
# A private tmpfs disappears with this owned mount namespace.
mount -t tmpfs -o mode=0700 tmpfs /tmp
mkdir -p "$rootfs/app" "$rootfs/usr/bin" "$rootfs/usr/sbin" "$rootfs/usr/lib/x86_64-linux-gnu" "$rootfs/usr/lib64" "$rootfs/usr/share" "$rootfs/usr/local/bin" "$rootfs/etc/cron.d" "$rootfs/etc/pam.d" "$rootfs/var/spool/cron/crontabs" "$rootfs/var/log" "$rootfs/run" "$rootfs/tmp" "$rootfs/dev" "$rootfs/proc" "$rootfs$project"
ln -s usr/bin "$rootfs/bin"
ln -s usr/sbin "$rootfs/sbin"
ln -s usr/lib "$rootfs/lib"
ln -s usr/lib64 "$rootfs/lib64"
ln -s /run "$rootfs/var/run"
for subtree in bin sbin lib64 share; do
    mount --bind "/usr/$subtree" "$rootfs/usr/$subtree"
    mount -o remount,bind,ro "$rootfs/usr/$subtree"
done
# /usr/lib has locked WSL submounts: bind only the required library subtree.
mount --bind /usr/lib/x86_64-linux-gnu "$rootfs/usr/lib/x86_64-linux-gnu"
mount -o remount,bind,ro "$rootfs/usr/lib/x86_64-linux-gnu"
mount --bind "$project" "$rootfs$project"
mount -o remount,bind,ro "$rootfs$project"
mount --bind "$run" "$rootfs$run"
mount -o remount,bind,rw "$rootfs$run"
cp "$run/passwd" "$run/group" "$run/nsswitch.conf" "$rootfs/etc/"
cp "$run/pam-cron" "$rootfs/etc/pam.d/cron"
cp "$run/php-wrapper" "$rootfs/usr/local/bin/php"
cp "$run/artisan" "$rootfs/app/artisan"
cp "$run/namespace-proof.php" "$rootfs/app/namespace-proof.php"
cp "$run/production-cron.copy" "$rootfs/etc/cron.d/dujiaoka"
chmod 0644 "$rootfs/etc/cron.d/dujiaoka" "$rootfs/etc/passwd" "$rootfs/etc/group" "$rootfs/etc/nsswitch.conf" "$rootfs/etc/pam.d/cron"
chmod 0755 "$rootfs/usr/local/bin/php"
ln -s "$run/storage" "$rootfs/app/storage"
touch "$rootfs/dev/null"
mount --bind /dev/null "$rootfs/dev/null"
# Bulk mappings avoid util-linux's map-root-user/setgroups=allow argument conflict.
# The kernel still must allow setgroups; inherited deny is a hard precondition failure.
exec unshare --user --map-users=0:0:1 --map-groups=0:0:1 --setgroups=allow --mount --pid --fork --kill-child=KILL /bin/sh "$project/tests/Integration/CronLifecycle/inner.sh" "$rootfs"
