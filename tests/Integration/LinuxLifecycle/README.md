# Linux PCNTL and Supervisor acceptance

Run from the workspace root in the existing unprivileged Debian13 WSL session. The ignored local runtime contains SHA256-verified Sury PHP8.5.11 Debian13 archives and required Debian libraries extracted to rootfs, plus PyPI Supervisor4.3.0 source; no system installation or production service is used. See [source, runtime proof and complete results](../../../docs/linux-worker-compatibility.md). Paths below use this existing checkout; a different checkout must supply its own extracted binary, matching full INI and libraries.

```sh
queue_linux_runtime="$PWD/tests/.runtime/linux-runtime-39"
export LD_LIBRARY_PATH="$queue_linux_runtime/rootfs/usr/lib/x86_64-linux-gnu"
export PHP_INI_SCAN_DIR=
"$queue_linux_runtime/rootfs/usr/bin/php8.5" -c "$queue_linux_runtime/php.ini" tests/Integration/QueueLifecycle/run.php --candidate
"$queue_linux_runtime/rootfs/usr/bin/php8.5" -c "$queue_linux_runtime/php.ini" tests/Integration/LinuxLifecycle/cleanup-probe.php
"$queue_linux_runtime/rootfs/usr/bin/php8.5" -c "$queue_linux_runtime/php.ini" tests/Integration/LinuxLifecycle/run.php --candidate --supervisor-source="$queue_linux_runtime/supervisor-4.3.0"
"$queue_linux_runtime/rootfs/usr/bin/php8.5" -c "$queue_linux_runtime/php.ini" tests/Integration/LinuxLifecycle/timeout.php --candidate
"$queue_linux_runtime/rootfs/usr/bin/php8.5" -c "$queue_linux_runtime/php.ini" tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml
```

[run.php](run.php) preserves the production worker lifecycle options but runs a private user-level Supervisor/socket0700 with owned PHP/INI/marker processes and isolated SQLite/cache/storage. Its two scenarios verify native SIGTERM drains the current provider without reserving the next job, followed by start, actual queue:restart filecache, autorestart with a distinct PID, new provider/mail consumption and shutdown. [process.php](process.php) closes only owned proc handles with bounded TERM then KILL waits. [cleanup-probe.php](cleanup-probe.php) tests an actual child that ignores TERM and has a deliberately stale pre-exec identity snapshot.

[timeout.php](timeout.php) starts [timeout-console.php](timeout-console.php) with the original DeliverNotification payload timeout60/tries1 and CLI timeout1, using a synthetic provider blocked for at most75 seconds. Actual JobTimedOut/WorkerStopping and native self-SIGKILL must occur after59–65 wall-clock seconds for PASS. The original failed job and uncompleted lease remain, then only the fixture lease expiry is simulated; the original dispatcher republishes a distinct job and recovery delivers once at attempt2 while preserving the original failed row. No job property, production timeout or Worker kill implementation is patched.

Each run creates a fresh marker directory under ignored tests/.runtime/queue-lifecycle, preserves SQL, events, source hashes, process states and logs, and reports actual cleanup errors. Round39 passed the Linux complete2321/18250 strict suite,2452 common queue checks/24 child PIDs,777 final Supervisor checks and472 actual timeout/recovery checks. The two Windows common queue replays also pass2452 checks each. Container, system cron, production application identity, deployment schema/resources and real SMTP/merchant receipts remain separate work.
