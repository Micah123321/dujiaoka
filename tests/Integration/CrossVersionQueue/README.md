# Native cross-version queue acceptance

[Run](run.php) starts a fresh marker-owned SQLite fixture with shipped-schema business tables and original integer-ID failed_jobs fields. Every publisher and consumer is a separate original application Console Kernel process, using installed Windows PHP7.4.33/Laravel6.20.42 or PHP8.5.11/Laravel13.35.0. The historical `root` label selects `tests/Compatibility/Laravel6/vendor`; `candidate` selects `tests/Compatibility/Laravel12/vendor`. Install legacy dependencies with `composer install --working-dir=tests/Compatibility/Laravel6 --no-scripts --no-plugins` before replay (use PHP 8.5 with its complete fixture INI for Composer 2.10; the manifest fixes PHP 7.4.33). Package discovery and the independent verifier use the same selected vendor. Each tree has private storage, file cache, logs and bootstrap caches; only the fixture database is shared. The real database queue, Dispatcher, OrderExpired, Worker and queue:retry are used.

~~~powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/CrossVersionQueue/run.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/CrossVersionQueue/verify.php RUN_PATH
~~~

Use the exact new run printed by the first command. Completed summaries stop worker mutations before application bootstrap. Tasks must be canonical direct children of tasks, with explicit operation filenames. [Bootstrap](bootstrap.php) proves binary/INI, actual SQLite, real queue and integer failed provider, sole vendor loader, original class origins and per-tree cache paths. Strict PHP diagnostics are observed immediately after original HandleExceptions bootstrap and through worker shutdown.

The native old producer has no payload UUID. Its real serialized payload is consumed by the new worker before a real near-future deadline, acknowledged without returning the coupon, replayed byte-for-byte by pushRaw, then consumed after the actual 15-second deadline. A duplicate old worker acknowledges it without changing any business column. This does not claim a five-minute wallclock wait or a historically missing serialized member.

Both producer directions hit a real coupon AFTER UPDATE abort trigger. The original worker runs three times, with payload maxTries=3 overriding CLI tries=1. Each attempt has complete business-table rollback and zero transaction nesting. The first two release; the third logs exactly one original numeric failed record with unchanged payload. Native candidate queue:retry uses each actual numeric ID, removes the failed record, resets attempts and preserves bytes; recovery returns the coupon once. Duplicate consumption leaves every business column unchanged.

The candidate UUID failed provider is separately tested with the actual old payload. It raises the real missing-uuid diagnostic and leaves old failed rows unchanged. This is a provider control, not an exhausted worker under the wrong driver. Keep database while migrating old integer-ID failures; switching database-uuids is a separate coordinated migration.

[Independent verification](verify.php) reopens raw tasks, original payloads, events, include origins and complete SQL snapshots. It opens final SQLite with PRAGMA query_only=ON and checks preserved seed records and other tables. Process closure is additionally checked by tracked PID and unique owned command marker. See [current evidence and application startup fix](../../../docs/cross-version-queue-compatibility.md).

This is a development-tree queue fixture with synthetic orders, coupons and SQLite. It does not replace independent no-dev Linux release, OCI/Engine, real MySQL deployment, system cron or payment/mail acceptance.
