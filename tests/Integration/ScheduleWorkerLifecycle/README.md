# ScheduleWorkerLifecycle

This integration entry exercises the original Laravel13.35.0 schedule:work minute loop under the existing Linux PHP8.5.11 runtime and regular Host UID/GID1000. It uses a fresh owned SQLite/database queue, private file cache/storage and the original AppKernel orders:expire and notifications:dispatch events. It is separate from the system cron and full image acceptance recorded in [CronLifecycle](../CronLifecycle/README.md).

Run from the existing Linux checkout:

```sh
env LD_LIBRARY_PATH=/mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/rootfs/usr/lib/x86_64-linux-gnu \
  PHP_INI_SCAN_DIR= \
  /mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/rootfs/usr/bin/php8.5 \
  -c /mnt/e/code/php/dujiaoka/tests/.runtime/linux-runtime-39/php.ini \
  /mnt/e/code/php/dujiaoka/tests/Integration/ScheduleWorkerLifecycle/run.php --candidate
```

Add --signal-probe to verify the original command signal registrar without waiting for two minutes. [cleanup-probe.php](cleanup-probe.php), with the same binary/INI, injects a parent failure while an owned descendant ignores TERM; the normal cleanup must reach KILL after five seconds and leave no owned live descendant or open parent handle.

The minute run seeds one overdue order with a coupon, one future order and two committed but unpublished notification intents. The first actual minute expires only the overdue order, returns the coupon once and publishes two jobs with attempts0, reserved_at=null and 300-second outbox leases. No worker runs between the two minutes. The next actual minute must preserve the exact SQL snapshots, including job IDs/payloads and lease tokens. Then the original queue worker delivers one synthetic204 callback and one array mail, each at transaction level0 and attempt1, clears payload/leases, and leaves zero jobs or failed jobs.

The private ARTISAN_BINARY is defined before application boot; PHPRC and the empty scan directory are synchronized in getenv, $_ENV and $_SERVER because Symfony child environment merges those sources. Only the existing ql_schedule command paths are rewritten, preserving original foreground events and actual mutex acquisition/removal. Carbon is never frozen; actual minute entries must lie in two consecutive wall-clock minutes about60 seconds apart.

Laravel ArtisanServiceProvider disables command signal traps in the testing environment. This fixture retains its isolated testing environment and explicitly reenables only the framework Signals availability resolver for Linux PCNTL. It does not replace ScheduleWorkCommand, its loop, its handlers or its signal registrar. The summary/log records this fixture adjustment. A successful TERM check proves the original native trap path with that adjustment, rather than default testing behavior or a full deployment.

The parent handles TERM/INT/ALRM by recording interruption and throwing at the next wait boundary; a270-second alarm bounds the run. The first complete tick has110 seconds to include daemon boot, the next minute boundary and both PHP event child startups; the second has90 seconds. The queue worker has30 seconds to finish. Each owned child starts in a dedicated setsid session. The open process handle verifies its original PID/start_ticks with SID/PGID equal to that PID; only stat metadata is scanned until it matches one of these sessions. Then UID1000 and the same PID namespace are verified before recording members, including children whose parent has already exited. Exit waits and both cleanup windows rescan continuously. Signals recheck PID/start_ticks/SID/PGID/namespace, and two empty scans confirm shutdown. Cleanup uses five-second TERM/KILL windows and never calls proc_close on a live process. Shared log reads lock against partial records. All retained processes, descendants, remaining live identities and handles are reported in summary.json under tests/.runtime/queue-lifecycle/run-minute-*.

[Final minute run](../../.runtime/queue-lifecycle/run-minute-20261010-050452-9dabb639023706d5182b3407/summary.json) passed854 checks, with59.748 seconds between entries and155.609 seconds elapsed. Native daemon and worker exit0; remaining owned processes and handles are0. The [independent parent proof](../../.runtime/queue-lifecycle/run-minute-20261010-050452-9dabb639023706d5182b3407/parent-proof-41.json) passed155 checks against raw events, a separately opened SQLite connection,14 source classes and15 tracked process identities.

No production settings, dependencies, database, host accounts, merchant or SMTP transport are changed. The Docker/cron daemon, root-to-application identity switch, actual bind permissions and deployment of schedule:work are outside this entry. The production deployment remains the existing crontab with schedule:run.
