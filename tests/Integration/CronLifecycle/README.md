# CronLifecycle

This harness uses the existing Debian /usr/sbin/cron and Linux PHP8.5.11 / Laravel13.35.0 candidate. It writes only a fresh tests/.runtime/cron-lifecycle/run-* directory. The four harness files are [run.php](run.php), [namespace.sh](namespace.sh), [inner.sh](inner.sh) and this document. No Docker engine, host root service, shared fixture edit, dependency installation, external request or KB update is involved.

Replay from the existing Linux checkout:

~~~sh
timeout --signal=TERM --kill-after=12s 240s env \
  LD_LIBRARY_PATH=/mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/rootfs/usr/lib/x86_64-linux-gnu \
  PHP_INI_SCAN_DIR= \
  /mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/rootfs/usr/bin/php8.5 \
  -c /mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/php.ini \
  /mnt/e/code/php/dujiaoka/tests/Integration/CronLifecycle/run.php --candidate
~~~

The harness has a 225-second interruption handler and a 150-second wall-clock limit for both cron ticks. It reuses the original LinuxLifecycle ll_spawn / ll_wait / ll_cleanup_owned interfaces, with bounded TERM5s then KILL5s. Cron PID1 is killed separately only after its launcher ancestry, exact rootfs, PID namespace, argv and start ticks match. A live process is never passed directly to unbounded proc_close. Failures still enter cleanup and write summary.json with process state and handles_remaining.

The outer user/mount namespace maps only Host UID/GID1000 to root0. A private /tmp tmpfs holds the random rootfs; host /tmp is not populated with rootfs files. Required /usr subtrees and the project are read-only binds; only this run directory is overlaid read/write. /usr/lib itself is not bound because it contains locked WSL submounts. The synthetic /etc contains root and application passwd records, both UID/GID0, local group/nsswitch records and a pam_permit account/session stack. Host /etc and credentials are not mounted. This setup does not exercise stock root-to-application UID1000 switching, and must not be combined with an R40 UID1000 result to make that claim.

The original [production cron text](../../../deploy/cron/dujiaoka) is copied byte for byte as mode0644. The PHP wrapper selects the existing extracted binary/INI/libraries with an empty scan directory. The /app/artisan shim records raw microtime, UID/GID, LOGNAME, root, cwd, argv and namespace maps, then delegates to a byte-identical copy of [QueueLifecycle console.php](../QueueLifecycle/console.php). A runtime copy of its [bootstrap.php](../QueueLifecycle/bootstrap.php) changes exactly three path anchors: source root and the two runtime directory expressions. bootstrap-adaptation.json records every replacement and both SHA256 values. Original AppKernel, events, mutexes and foreground event children remain in ql_schedule; Carbon is not frozen.

The schema and app paths are isolated by the original QL bootstrap assertions. Seed orders use Carbon::now in the application timezone: expired10 minutes ago with a coupon, future10 minutes ahead, and C-cron payment intents for both channels recorded inside the real transaction. Jobs start at zero. Once namespace allow/mapping/initgroups is proved, real cron must create two consecutive minute entries about60 seconds apart. No worker runs between them: the first snapshot must contain two jobs and two unconsumed300-second leases at attempts0; the second must retain exact IDs, payloads, tokens, leases and order/coupon state. After cron is stopped, the original QL queue worker drains fixture35 and must deliver once per channel (204 synthetic provider and array mail, both transaction level0), clear lease/token/error/mail payload, and leave zero jobs/failed_jobs. Four event results and actual mutex acquisition/removal are required.

Evidence includes the original cron copy, seed/tick/final SQL snapshots, CronEntry records, QL events, original 14 class source hashes before/after, host passwd/group and protected-file hashes before/after, runtime SHA256, logs and bounded process cleanup. A BLOCKED result is not a cron acceptance result; downstream checks that did not run remain unverified.

## Observed run

[Actual summary](../../.runtime/cron-lifecycle/run-20261009-231130-f944909c97a0cb66b25fac7f/summary.json): BLOCKED, 94 passed checks, zero failed assertions, 7.454 seconds, PHP8.5.11 / Laravel13.35.0, zero observed cron ticks, zero remaining handles and no cleanup errors. The launcher exited1 and its proc handle is closed; the parent database connection was closed. All protected hashes and the14 original class hashes match before/after. Seed SQLite has zero jobs/failed_jobs and two committed notification intents; minute ticks, publication leases and worker delivery were not exercised. Main PHP, both shell files and all four generated PHP files pass syntax checks.

## Verified namespace blocker on this host

Debian util-linux2.41 rejects --map-root-user combined with --setgroups=allow. The implementation instead uses explicit single-range --map-users=0:0:1 / --map-groups=0:0:1 with allow. On the current unprivileged HostUID1000, outer --map-root-user sets setgroups to deny; an unconfigured inner user namespace inherits deny. The explicit inner allow attempt exits with "write failed /proc/self/setgroups: Operation not permitted". An outer UID-only --map-user=0 preserves allow but provides no GID map, so it does not satisfy the cron identity prerequisite. The harness fails before the long minute wait rather than weakening the required identity checks.

These read-only commands reproduce the decisive facts:

~~~sh
unshare --user --map-root-user --mount cat /proc/self/setgroups /proc/self/uid_map /proc/self/gid_map
unshare --user --map-root-user --mount unshare --user --mount --pid --fork cat /proc/self/setgroups /proc/self/uid_map /proc/self/gid_map
unshare --user --map-root-user --mount unshare --user --map-users=0:0:1 --map-groups=0:0:1 --setgroups=allow --mount --pid --fork id
unshare --user --map-user=0 --mount cat /proc/self/setgroups /proc/self/uid_map /proc/self/gid_map
~~~

A replay requires a launcher that can supply the required GID map while preserving allow. The current single-unprivileged-HostUID namespace chain has not supplied that prerequisite. No application/cron/libc patch or broader host identity mapping is applied by this harness. Real minute/worker acceptance is pending that prerequisite.
