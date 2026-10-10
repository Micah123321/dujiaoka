# Isolated queue and scheduler process acceptance

Install the independent legacy dependencies with `composer install --working-dir=tests/Compatibility/Laravel6 --no-scripts --no-plugins` (use PHP 8.5 with its complete fixture INI for Composer 2.10; the manifest fixes PHP 7.4.33). Run both dependency trees from the workspace root:

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/Integration/QueueLifecycle/run.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/QueueLifecycle/run.php --candidate
```

Each invocation creates an owned marker directory under ignored tests/.runtime/queue-lifecycle, with a shared SQLite file and isolated environment/cache/storage. Every PHP child inherits the selected binary and full ini. The historical root label requires PHP7.4 and selects `tests/Compatibility/Laravel6/vendor`, including package discovery; candidate requires PHP8.4+ and selects `tests/Compatibility/Laravel12/vendor`. `MANUAL_STATUS_MAIL_OUTBOX` is forced false in both profiles. E_ALL is retained. The system PHP8.2 is excluded from the formal root acceptance.

[run.php](run.php) publishes serialized production jobs and starts actual database queue workers via [console.php](console.php). [bootstrap.php](bootstrap.php) boots the real application, executes both actual outbox migrations, substitutes only provider responses and uses the real array mail transport. The fixture keeps the application scheduler events and overlap mutexes while replacing their CLI prefix with the isolated entry. Original artisan and shop environment files are never invoked.

Five scenarios cover expiry/coupon idempotency, publication versus delivery, duplicate tokens/503 recovery and encrypted mail cleanup, two real scheduler ticks, cache restart draining and termination/lease recovery. The lost reserved job intentionally leaves one MaxAttemptsExceeded failed_jobs record; all nine outbox rows still complete and the queue empties. Only fixture timestamps simulate long deadlines. --through=C selects the first three scenarios.

Round35 formal PHP7.4.33/Laravel6.20.42 and PHP8.5.11/Laravel13.35.0 each passed five scenarios,24 child PIDs and2452 raw checks (including repeated per-process isolation checks). Four native event children prove actual mutex ownership before execution and release afterward. [Detailed evidence, exact reports and limits](../../../docs/queue-lifecycle.md) also record the independently fixed webhook30-second backoff and complete shared suites.

Round39 replayed the same five scenarios on Linux8.5.11 and both Windows trees (2452 checks/24 child PIDs each). Linux abrupt loss uses SIGKILL and records signaled/termsig9, while cleanup uses bounded TERM then KILL; Windows retains its termination behavior. Separate [Linux PCNTL/Supervisor acceptance](../LinuxLifecycle/README.md) covers native hosting. Container/system cron, real provider/SMTP delivery and deployment schemas remain outside this fixture. Reports and child output are preserved; only owned process handles are stopped, and this harness never resets an existing shop database.
