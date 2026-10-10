#!/bin/sh
set -eu
umask 077
stage=$1
run=$2
marker=$3
source=$4
shift 4
case "$run" in */tests/.runtime/queue-lifecycle/run-ns-*) ;; *) exit 64 ;; esac
[ "$(cat "$run/marker")" = "$marker" ] || exit 65
if [ "$stage" = outer ]; then
    mount --make-rprivate /
    mount --bind "$run/passwd" /etc/passwd
    mount --bind "$run/group" /etc/group
    mount -o remount,bind,ro /etc/passwd
    mount -o remount,bind,ro /etc/group
    mount -t tmpfs -o mode=0700,nosuid,nodev tmpfs /tmp
    exec unshare --user --map-user=1000 --map-group=1000 --mount --pid --fork --kill-child=KILL --mount-proc /bin/sh "$0" inner "$run" "$marker" "$source"
fi
[ "$stage" = inner ] || exit 66
export PYTHONPATH="$source"
export PYTHONDONTWRITEBYTECODE=1
exec python3 -c '
import hashlib, json, os, pwd, grp, stat, sys, supervisor, supervisor.options
run, marker, source = sys.argv[1:]
record = {"marker": marker, "pid": os.getpid(), "uid": os.geteuid(), "gid": os.getegid(),
    "user": pwd.getpwuid(os.geteuid()).pw_name, "group": grp.getgrgid(os.getegid()).gr_name,
    "namespaces": {name: os.readlink("/proc/self/ns/" + name) for name in ("user", "mnt", "pid")},
    "uid_map": open("/proc/self/uid_map").read(), "gid_map": open("/proc/self/gid_map").read(),
    "status": open("/proc/self/status").read(), "socket_directory_mode": stat.S_IMODE(os.stat("/tmp").st_mode),
    "python": sys.version, "version": supervisor.options.VERSION, "source": os.path.realpath(supervisor.__file__),
    "source_hashes": {name: hashlib.sha256(open(source + "/supervisor/" + name, "rb").read()).hexdigest()
        for name in ("supervisord.py", "options.py", "process.py")}}
if record["pid"] != 1 or record["uid"] != 1000 or record["gid"] != 1000 or record["user"] != "application" or record["group"] != "application" or record["socket_directory_mode"] != 448:
    raise RuntimeError("Unexpected namespace identity")
with open(run + "/namespace-supervisor.json", "w") as handle:
    json.dump(record, handle, indent=2)
os.execvpe(sys.executable, [sys.executable, "-m", "supervisor.supervisord", "-n", "-c", run + "/supervisord.conf"], os.environ)
' "$run" "$marker" "$source"
