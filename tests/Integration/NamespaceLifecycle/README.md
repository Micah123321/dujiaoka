# Namespace PID1 SIGTERM acceptance

Run from this checkout in the existing unprivileged Debian WSL session. The existing extracted PHP8.5 runtime, full INI and Supervisor4.3.0 source are reused; no installation is required.

```sh
namespace_runtime="$PWD/tests/.runtime/linux-runtime-39"
export LD_LIBRARY_PATH="$namespace_runtime/rootfs/usr/lib/x86_64-linux-gnu"
export PHP_INI_SCAN_DIR=
"$namespace_runtime/rootfs/usr/bin/php8.5" -c "$namespace_runtime/php.ini" tests/Integration/NamespaceLifecycle/run.php --candidate --supervisor-source="$namespace_runtime/supervisor-4.3.0"
```

The parent PHP remains in HostNS. The outer user/mount namespace maps the invoking user to namespace root and binds this run's synthetic passwd/group read-only over its private account files. A private tmpfs supplies /tmp mode0700. The inner user/mount/PID namespace maps uid/gid1000 to application and remounts /proc. Its launcher uses --kill-child=KILL and execs real Supervisor as PID1.

The original program configuration retains user=application, startsecs5, autorestart, stopasgroup/killasgroup, stopwaitsecs90 and worker timeout60. Only worker binary/INI/entrypoint/queue, working directory and log paths are isolated; TERM is made explicit. The console wrapper records identity and then includes the original QueueLifecycle console without replacing PCNTL, Worker or DeliverNotification behavior.

After RUNNING, the original dispatcher publishes two original DeliverNotification jobs. The first pauses in the existing 15-second provider gate. Verified owned descendants and /proc/NSpid map namespace PIDs to HostNS PIDs; exact start_ticks/argv checks precede HostNS SIGTERM to Supervisor PID1. At 10.5 seconds Supervisor and worker must still live, the first task must remain incomplete, and the next must remain unreserved with attempts0. The gate is then released before 15 seconds. The first task must deliver once with HTTP204/transaction0, worker and Supervisor must exit0, and the next job must remain pending.

Each fresh tests/.runtime/queue-lifecycle/run-ns-* retains summary.json, namespace-runtime.json, pid-mapping.json, namespace identity records, events.jsonl, SQL snapshots, SQLite, Supervisor/worker logs and before/after source/config/HostNS account hashes. Cleanup reuses bounded owned-process TERM/KILL waits and verifies no owned process remains. The mount namespace and private socket disappear with its owned processes.

This acceptance does not start a Docker image, invoke Docker's own stop timer, exercise system cron ticks, test the native 60-second timeout, or replay pending-job recovery. It demonstrates a real Linux namespace PID1 drain exceeding the 10-second comparison threshold.
