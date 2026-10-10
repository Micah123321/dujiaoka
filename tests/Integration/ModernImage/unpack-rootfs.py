#!/usr/bin/env python3
"""Verify and materialize the frozen base image, without running image code.

Usage (Linux, real and effective uid 1000): python3 unpack-rootfs.py OWNED_RUN
Absolute image symlinks are retained for a future chroot. Never use host tools
that follow those symlinks on this tree. Failed attempts are retained, not reused.
"""

import collections
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import tarfile

WINDOWS_ROOT = "E:/code/php/dujiaoka"
PROJECT_ROOT = Path("/mnt/e/code/php/dujiaoka")
RUN_PARENT = PROJECT_ROOT / "tests/.runtime/modern-image"
MANIFEST_DIGEST = "sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6"
CONFIG_DIGEST = "sha256:b80ffe888ac6dddfabfb22e74d98aa5e9fef111b09065cea0a2a9d8aedac6521"
LAYER_MEDIA = "application/vnd.docker.image.rootfs.diff.tar.gzip"
TOTAL_COMPRESSED = 431655842
MAX_UNPACKED = 8 * 1024 ** 3
MAX_FILE_BYTES = 4 * 1024 ** 3
MAX_LAYER_MEMBERS = 100000
MAX_MEMBERS = 500000
CHUNK = 1024 * 1024
DIGEST_RE = re.compile(r"sha256:[0-9a-f]{64}\Z")
DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
READ_FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC


class UnpackError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise UnpackError(message)


def sha256(data):
    return "sha256:" + hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"),
                      ensure_ascii=True, allow_nan=False).encode("ascii")


def no_duplicates(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "Duplicate JSON key: " + key)
        result[key] = value
    return result


def regular_fd(path):
    fd = os.open(str(path), READ_FLAGS)
    try:
        require(stat.S_ISREG(os.fstat(fd).st_mode), "Non-regular input: " + str(path))
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_bytes(path, maximum=2 * CHUNK):
    fd = regular_fd(path)
    with os.fdopen(fd, "rb") as stream:
        require(os.fstat(stream.fileno()).st_size <= maximum, "Oversized input: " + str(path))
        data = stream.read(maximum + 1)
        require(len(data) <= maximum, "Oversized input: " + str(path))
        return data


def parse_json(data):
    return json.loads(data.decode("utf-8"), object_pairs_hook=no_duplicates,
                      parse_constant=lambda value: (_ for _ in ()).throw(
                          UnpackError("Non-finite JSON number: " + value)))


def read_json(path):
    return parse_json(read_bytes(path))


def write_json(path, value):
    payload = json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True,
                         allow_nan=False).encode("ascii") + b"\n"
    fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                 os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(payload)
        stream.flush()
        os.fsync(stream.fileno())
    return sha256(payload)


def windows_to_linux(value):
    require(isinstance(value, str) and "\x00" not in value and "\\" not in value,
            "Invalid configured path")
    require(value == WINDOWS_ROOT or value.startswith(WINDOWS_ROOT + "/"),
            "Configured path outside the owned Windows project")
    require(all(part not in ("", ".", "..") for part in value.split("/")),
            "Non-canonical configured path")
    return Path("/mnt/e" + value[2:])


def owned_directory(path):
    require(path.is_absolute(), "Owned path must be absolute")
    require(all(part not in (".", "..") for part in path.parts), "Non-canonical owned path")
    for ancestor in (path,) + tuple(path.parents):
        info = os.lstat(str(ancestor))
        require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode),
                "Linked or non-directory owned ancestor: " + str(ancestor))
    require(os.path.realpath(str(path)) == str(path), "Owned realpath mismatch")


def stat_signature(fd):
    info = os.fstat(fd)
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def hash_fd(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    digest = hashlib.sha256()
    count = 0
    while True:
        chunk = os.read(fd, CHUNK)
        if not chunk:
            break
        count += len(chunk)
        digest.update(chunk)
    return "sha256:" + digest.hexdigest(), count


def validate_run(argument):
    require(sys.platform.startswith("linux") and os.getuid() == 1000 and
            os.geteuid() == 1000, "Run only as Linux real/effective UID 1000")
    require(hasattr(os, "O_NOFOLLOW"), "Linux no-follow file operations required")
    require("\x00" not in argument and "\\" not in argument, "Invalid OWNED_RUN path")
    run = windows_to_linux(argument) if argument.startswith("E:/") else Path(argument)
    require(run.is_absolute() and ".." not in run.parts, "Use an absolute owned run path")
    require(run.parent == RUN_PARENT, "OWNED_RUN must be a direct child of the frozen run parent")
    owned_directory(PROJECT_ROOT)
    owned_directory(run)
    cfg = read_json(run / "run.json")
    require(isinstance(cfg, dict) and set(cfg) == {
        "root", "run", "marker", "manifestDigest", "configDigest", "linuxRoot", "rootfs"
    }, "Unexpected run.json fields")
    marker = cfg["marker"]
    require(isinstance(marker, str) and re.fullmatch(r"[0-9a-f]{24}", marker), "Invalid marker")
    match = re.fullmatch(r"run-layers-(\d{8}-\d{6})-" + marker, run.name)
    require(match is not None, "Owned run basename/marker mismatch")
    datetime.datetime.strptime(match.group(1), "%Y%m%d-%H%M%S")
    require(cfg["root"] == WINDOWS_ROOT and windows_to_linux(cfg["root"]) == PROJECT_ROOT,
            "run.json root mismatch")
    require(windows_to_linux(cfg["run"]) == run and
            cfg["run"] == WINDOWS_ROOT + "/tests/.runtime/modern-image/" + run.name,
            "run.json run/realpath mismatch")
    require(read_bytes(run / "marker", 24) == marker.encode("ascii"), "Marker file mismatch")
    require(cfg["manifestDigest"] == MANIFEST_DIGEST and cfg["configDigest"] == CONFIG_DIGEST,
            "Configured frozen digests changed")
    linux_root = Path("/var/tmp/dujiaoka-image50-" + marker)
    require(cfg["linuxRoot"] == str(linux_root) and cfg["rootfs"] == str(linux_root / "rootfs"),
            "Linux root/rootfs mismatch")
    owned_directory(linux_root.parent)
    for path in (linux_root, run / "unpack-summary.json", run / "unpack-started.json"):
        require(not os.path.lexists(str(path)), "Unpack destination/attempt already exists: " + str(path))
    pull = read_json(run / "pull-summary.json")
    require(pull.get("status") == "PASS" and pull.get("run") == cfg["run"] and
            pull.get("manifestDigest") == MANIFEST_DIGEST and
            pull.get("configDigest") == CONFIG_DIGEST and
            pull.get("platform") == "linux/amd64" and
            pull.get("totalBytes") == TOTAL_COMPRESSED, "Pull summary is not the matching PASS")
    manifest_bytes = read_bytes(run / "manifest.json")
    config_bytes = read_bytes(run / "config.json")
    require(sha256(manifest_bytes) == MANIFEST_DIGEST and sha256(config_bytes) == CONFIG_DIGEST,
            "Raw frozen manifest/config digest mismatch")
    manifest, config = parse_json(manifest_bytes), parse_json(config_bytes)
    require(manifest.get("schemaVersion") == 2 and
            manifest.get("config", {}).get("digest") == CONFIG_DIGEST and
            manifest["config"].get("size") == len(config_bytes) and
            config.get("architecture") == "amd64" and config.get("os") == "linux" and
            config.get("rootfs", {}).get("type") == "layers", "Frozen manifest/config shape mismatch")
    layers = manifest.get("layers")
    diff_ids = config["rootfs"].get("diff_ids")
    require(isinstance(layers, list) and len(layers) == 20 and
            isinstance(diff_ids, list) and len(diff_ids) == 20, "Exactly twenty layers/diff IDs required")
    pulled = pull.get("layers")
    require(isinstance(pulled, list) and len(pulled) == 20, "Incomplete pull layer descriptors")
    for index, (layer, diff_id, saved) in enumerate(zip(layers, diff_ids, pulled)):
        require(isinstance(layer, dict) and DIGEST_RE.fullmatch(layer.get("digest", "")) and
                layer.get("mediaType") == LAYER_MEDIA and type(layer.get("size")) is int and
                layer["size"] > 0 and isinstance(diff_id, str) and DIGEST_RE.fullmatch(diff_id),
                "Invalid frozen layer descriptor")
        require(saved == {"position": index, "digest": layer["digest"], "bytes": layer["size"],
                          "file": "blobs/" + layer["digest"][7:] + ".tar.gz"},
                "Pull descriptor mismatch at layer " + str(index))
    require(sum(layer["size"] for layer in layers) == TOTAL_COMPRESSED, "Compressed total mismatch")
    owned_directory(run / "blobs")
    return run, cfg, config, layers, diff_ids


def entry_parts(name, root_allowed=False):
    require(isinstance(name, str) and name and "\x00" not in name and "\\" not in name and
            not name.startswith("/"), "Invalid or absolute archive entry: " + repr(name))
    raw = name.split("/")
    require(".." not in raw, "Archive traversal: " + repr(name))
    parts = tuple(part for part in raw if part not in ("", "."))
    require(parts or root_allowed, "Empty normalized archive entry: " + repr(name))
    require(len(parts) <= 256 and len(os.fsencode(name)) <= 4096 and
            all(len(os.fsencode(part)) <= 255 for part in parts), "Archive path exceeds limits")
    return parts


def validate_target(target):
    require(isinstance(target, str) and target and "\x00" not in target and
            len(os.fsencode(target)) <= 4096, "Invalid symlink target")
    require(all(len(os.fsencode(part)) <= 255 for part in target.split("/")),
            "Symlink component exceeds limits")


def member_kind(member):
    if member.type in (tarfile.REGTYPE, tarfile.AREGTYPE):
        return "file"
    if member.isdir():
        return "directory"
    if member.issym():
        return "symlink"
    if member.islnk():
        return "hardlink"
    if member.ischr():
        return "character-device"
    if member.isblk():
        return "block-device"
    if member.isfifo():
        return "fifo"
    raise UnpackError("Unsupported tar member type: " + repr(member.name))


def validate_member(member):
    kind = member_kind(member)
    require(member.sparse is None, "Sparse tar entries are outside materialization scope")
    parts = entry_parts(member.name, kind == "directory")
    require(type(member.size) is int and 0 <= member.size <= MAX_FILE_BYTES and
            type(member.uid) is int and member.uid >= 0 and
            type(member.gid) is int and member.gid >= 0 and
            type(member.mode) is int and member.mode >= 0, "Invalid tar metadata")
    require(kind == "file" or member.size == 0, "Non-file member carries unexpected payload")
    require(not any(part.startswith(".wh.") for part in parts[:-1]), "Whiteout in path ancestry")
    if parts and parts[-1].startswith(".wh."):
        require(kind == "file" and member.size == 0, "Whiteout must be an empty regular entry")
        basename = parts[-1]
        require(basename == ".wh..wh..opq" or
                (not basename.startswith(".wh..wh.") and basename[4:] not in ("", ".", "..")),
                "Invalid whiteout name")
        return "opaque-whiteout" if basename == ".wh..wh..opq" else "whiteout"
    if kind == "symlink":
        validate_target(member.linkname)
    if kind == "hardlink":
        entry_parts(member.linkname)
    return kind


def fingerprint(member):
    return (member.name, member.type, member.size, member.mode, member.uid, member.gid,
            member.linkname, member.mtime, member.offset, member.offset_data,
            member.devmajor, member.devminor, member.pax_headers, member.sparse)


class VerifiedStream:
    """Hash every uncompressed byte, including bytes after tar's end markers."""
    def __init__(self, fd, limit):
        os.lseek(fd, 0, os.SEEK_SET)
        self.raw = os.fdopen(os.dup(fd), "rb")
        self.gz = gzip.GzipFile(fileobj=self.raw, mode="rb")
        self.limit = limit
        self.count = 0
        self.digest = hashlib.sha256()

    def read(self, size=-1):
        require(0 <= size <= 16 * CHUNK, "Oversized tar metadata read")
        chunk = self.gz.read(size)
        self.count += len(chunk)
        require(self.count <= self.limit, "Total uncompressed tar stream exceeds 8 GiB")
        self.digest.update(chunk)
        return chunk

    def finish(self):
        while self.read(CHUNK):
            pass
        return "sha256:" + self.digest.hexdigest(), self.count

    def close(self):
        self.gz.close()
        self.raw.close()


def inspect_layers(fds, layers, diff_ids):
    plans = []
    unpacked = members_count = file_bytes = 0
    chain = None
    for index, (fd, layer, expected_diff) in enumerate(zip(fds, layers, diff_ids)):
        signature = stat_signature(fd)
        require(signature[2] == layer["size"], "Blob length changed before inspection")
        stream = VerifiedStream(fd, MAX_UNPACKED - unpacked)
        members = []
        try:
            with tarfile.open(fileobj=stream, mode="r|", bufsize=64 * 1024) as archive:
                for member in archive:
                    validate_member(member)
                    members.append(member)
                    members_count += 1
                    require(len(members) <= MAX_LAYER_MEMBERS and members_count <= MAX_MEMBERS,
                            "Tar member count limit exceeded")
                    if member_kind(member) == "file":
                        file_bytes += member.size
                        require(file_bytes <= MAX_FILE_BYTES, "Aggregate file payload exceeds 4 GiB")
            actual_diff, count = stream.finish()
        finally:
            stream.close()
        require(actual_diff == expected_diff, "Full gzip/tar diff ID mismatch at layer " + str(index))
        require(stat_signature(fd) == signature, "Blob changed during inspection")
        unpacked += count
        chain = actual_diff if chain is None else sha256((chain + " " + actual_diff).encode("ascii"))
        plans.append({"members": members, "unpackedBytes": count, "diffId": actual_diff,
                      "chainId": chain, "signature": signature})
        print(json.dumps({"event": "DiffIdVerified", "position": index,
                          "diffId": actual_diff, "chainId": chain, "unpackedBytes": count}), flush=True)
    return plans, unpacked, members_count, file_bytes


class ImageTree:
    """All filesystem operations use anchored directory FDs; never host-follow links."""
    def __init__(self, rootfs):
        self.fd = os.open(str(rootfs), DIR_FLAGS)
        self.metadata = {}
        self.inodes = {}

    def close(self):
        os.close(self.fd)

    def directory_fd(self, parts):
        fd = os.dup(self.fd)
        try:
            for part in parts:
                next_fd = os.open(part, DIR_FLAGS, dir_fd=fd)
                os.close(fd)
                fd = next_fd
            return fd
        except BaseException:
            os.close(fd)
            raise

    def lstat(self, parts):
        if not parts:
            return os.fstat(self.fd)
        try:
            parent = self.directory_fd(parts[:-1])
        except FileNotFoundError:
            return None
        try:
            try:
                return os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                return None
        finally:
            os.close(parent)

    def resolve(self, parts, follow_final=False, create=False):
        pending = collections.deque(parts)
        resolved = []
        links = steps = 0
        while pending:
            part = pending.popleft()
            steps += 1
            require(steps <= 4096, "Image path expansion limit exceeded")
            if part in ("", "."):
                continue
            if part == "..":
                if resolved:
                    resolved.pop()
                continue  # chroot semantics: /.. is still image root.
            require("\x00" not in part and len(os.fsencode(part)) <= 255,
                    "Invalid expanded image component")
            candidate = tuple(resolved + [part])
            info = self.lstat(candidate)
            final = not pending
            if info is not None and stat.S_ISLNK(info.st_mode) and (follow_final or not final):
                links += 1
                require(links <= 40, "Image symlink loop/expansion limit exceeded")
                parent = self.directory_fd(candidate[:-1])
                try:
                    target = os.readlink(part, dir_fd=parent)
                finally:
                    os.close(parent)
                validate_target(target)
                if target.startswith("/"):
                    resolved = []
                pending.extendleft(reversed(target.split("/")))
                continue
            if not final:
                if info is None:
                    if create:
                        parent = self.directory_fd(tuple(resolved))
                        try:
                            os.mkdir(part, 0o700, dir_fd=parent)
                        finally:
                            os.close(parent)
                        self.metadata[candidate] = {"type": "directory", "mode": 0o755,
                                                    "implicit": True, "uid": None, "gid": None}
                    else:
                        # Remaining absent components have no lower-layer target to delete.
                        resolved.append(part)
                        for rest in pending:
                            if rest == "..":
                                if resolved:
                                    resolved.pop()
                            elif rest not in ("", "."):
                                resolved.append(rest)
                        return tuple(resolved)
                else:
                    require(stat.S_ISDIR(info.st_mode), "Non-directory image ancestor: /" + "/".join(candidate))
            resolved.append(part)
            require(len(resolved) <= 256, "Expanded image path depth exceeded")
        return tuple(resolved)

    def remove(self, parts):
        require(parts, "Removing the rootfs anchor is prohibited")
        info = self.lstat(parts)
        if info is None:
            return False
        parent = self.directory_fd(parts[:-1])
        try:
            self._remove_at(parent, parts[-1])
        finally:
            os.close(parent)
        if stat.S_ISDIR(info.st_mode):
            for key in list(self.metadata):
                if key[:len(parts)] == parts:
                    del self.metadata[key]
        else:
            self.metadata.pop(parts, None)
        return True

    def _remove_at(self, parent, name):
        info = os.stat(name, dir_fd=parent, follow_symlinks=False)
        require(info.st_uid == 1000, "Unexpected physical owner during removal")
        if stat.S_ISDIR(info.st_mode):
            child = os.open(name, DIR_FLAGS, dir_fd=parent)
            try:
                for entry in os.listdir(child):
                    self._remove_at(child, entry)
            finally:
                os.close(child)
            os.rmdir(name, dir_fd=parent)
        else:
            os.unlink(name, dir_fd=parent)

    def whiteouts(self, members):
        records, deletions = [], []
        for member in members:
            kind = validate_member(member)
            if kind not in ("whiteout", "opaque-whiteout"):
                continue
            parts = entry_parts(member.name)
            if kind == "whiteout":
                target = self.resolve(parts[:-1] + (parts[-1][4:],))
                targets = [target] if self.lstat(target) is not None else []
            else:
                target = self.resolve(parts[:-1], follow_final=True)
                info = self.lstat(target)
                require(info is None or stat.S_ISDIR(info.st_mode), "Opaque whiteout parent is not a directory")
                targets = []
                if info is not None:
                    parent = self.directory_fd(target)
                    try:
                        targets = [target + (name,) for name in sorted(os.listdir(parent))]
                    finally:
                        os.close(parent)
            record = member_record(member, kind, target)
            record["lowerTargets"] = ["/" + "/".join(value) for value in targets]
            records.append(record)
            deletions.extend(targets)
        # Resolve every deletion against the unchanged lower tree before deleting anything.
        for target in sorted(set(deletions), key=lambda value: (len(value), value)):
            self.remove(target)
        return records

    def apply(self, member, archive):
        kind = validate_member(member)
        parts = entry_parts(member.name, kind == "directory")
        target = self.resolve(parts, create=True)
        require(target or kind == "directory", "Non-directory resolves to image root")
        record = member_record(member, kind, target)
        if kind == "directory":
            existing = self.lstat(target)
            if existing is not None and not stat.S_ISDIR(existing.st_mode):
                self.remove(target)
                existing = None
            if target and existing is None:
                parent = self.directory_fd(target[:-1])
                try:
                    os.mkdir(target[-1], 0o700, dir_fd=parent)
                finally:
                    os.close(parent)
            self.metadata[target] = record
            return record
        source = None
        if kind == "hardlink":
            source = self.resolve(entry_parts(member.linkname), follow_final=True)
            source_info = self.lstat(source)
            require(source and source != target and source_info is not None and
                    stat.S_ISREG(source_info.st_mode) and source_info.st_uid == 1000,
                    "Hardlink must reference an existing regular image file")
            require(source[:len(target)] != target, "Hardlink source is inside its replacement ancestry")
        self.remove(target)  # A final symlink is unlinked, never followed for replacement.
        if kind in ("character-device", "block-device", "fifo"):
            record["materialized"] = False
            record["limitation"] = "Special member recorded only; any replaced physical entry was removed."
            return record
        parent = self.directory_fd(target[:-1])
        try:
            if kind == "symlink":
                os.symlink(member.linkname, target[-1], dir_fd=parent)
                record["materialized"] = True
            elif kind == "hardlink":
                source_parent = self.directory_fd(source[:-1])
                try:
                    os.link(source[-1], target[-1], src_dir_fd=source_parent,
                            dst_dir_fd=parent, follow_symlinks=False)
                finally:
                    os.close(source_parent)
                info = os.stat(target[-1], dir_fd=parent, follow_symlinks=False)
                require(stat.S_ISREG(info.st_mode) and (info.st_dev, info.st_ino) ==
                        (source_info.st_dev, source_info.st_ino), "Hardlink inode mismatch")
                inode = (info.st_dev, info.st_ino)
                require(inode in self.inodes, "Hardlink source has no verified content record")
                record["fileSHA256"] = self.inodes[inode]["fileSHA256"]
                record["resolvedTarget"] = "/" + "/".join(source)
                record["inodeMetadataChanged"] = any(
                    record[key] != self.inodes[inode][key] for key in ("mode", "uid", "gid"))
                self.inodes[inode] = dict(record)
            else:
                fd = os.open(target[-1], os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                             os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
                with os.fdopen(fd, "wb") as output:
                    data = archive.extractfile(member)
                    require(data is not None, "Missing regular member payload")
                    digest = hashlib.sha256()
                    remaining = member.size
                    with data:
                        while remaining:
                            chunk = data.read(min(CHUNK, remaining))
                            require(chunk, "Truncated regular member payload")
                            output.write(chunk)
                            digest.update(chunk)
                            remaining -= len(chunk)
                    output.flush()
                    info = os.fstat(output.fileno())
                    require(info.st_size == member.size and info.st_uid == 1000,
                            "Written file size/physical owner mismatch")
                    record["fileSHA256"] = "sha256:" + digest.hexdigest()
                    self.inodes[(info.st_dev, info.st_ino)] = dict(record)
        finally:
            os.close(parent)
        record["materialized"] = True
        self.metadata[target] = record
        return record

    def file_proof(self, image_path):
        parts = entry_parts(image_path.lstrip("/"))
        target = self.resolve(parts, follow_final=True)
        info = self.lstat(target)
        if info is None:
            return {"path": image_path, "status": "MISSING"}
        require(stat.S_ISREG(info.st_mode), "Proof target is not regular: " + image_path)
        parent = self.directory_fd(target[:-1])
        try:
            fd = os.open(target[-1], READ_FLAGS, dir_fd=parent)
            try:
                digest, size = hash_fd(fd)
            finally:
                os.close(fd)
        finally:
            os.close(parent)
        return {"path": image_path, "resolvedPath": "/" + "/".join(target),
                "status": "PRESENT", "fileSHA256": digest, "bytes": size}

    def finish_permissions(self):
        inventory, seen = [], set()

        def walk(fd, parts):
            require(os.fstat(fd).st_uid == 1000, "Unexpected physical directory owner")
            for name in sorted(os.listdir(fd)):
                path = parts + (name,)
                info = os.stat(name, dir_fd=fd, follow_symlinks=False)
                require(info.st_uid == 1000, "Unexpected physical rootfs owner")
                metadata = self.metadata.get(path)
                require(metadata is not None, "Unrecorded physical rootfs member")
                item = {"path": "/" + "/".join(path), "physicalUid": info.st_uid,
                        "physicalGid": info.st_gid, "logicalUid": metadata["uid"],
                        "logicalGid": metadata["gid"], "logicalMode": metadata["mode"],
                        "type": metadata["type"]}
                if stat.S_ISDIR(info.st_mode):
                    child = os.open(name, DIR_FLAGS, dir_fd=fd)
                    try:
                        walk(child, path)
                        os.fchmod(child, metadata["mode"] & 0o1777)
                        item["physicalMode"] = os.fstat(child).st_mode & 0o7777
                    finally:
                        os.close(child)
                elif stat.S_ISREG(info.st_mode):
                    inode = (info.st_dev, info.st_ino)
                    effective = self.inodes[inode]
                    if inode not in seen:
                        child = os.open(name, READ_FLAGS, dir_fd=fd)
                        try:
                            os.fchmod(child, effective["mode"] & 0o1777)
                        finally:
                            os.close(child)
                        seen.add(inode)
                    actual = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    item.update({"physicalMode": actual.st_mode & 0o7777,
                                 "logicalUid": effective["uid"], "logicalGid": effective["gid"],
                                 "logicalMode": effective["mode"], "fileSHA256": effective["fileSHA256"],
                                 "bytes": actual.st_size})
                elif stat.S_ISLNK(info.st_mode):
                    item.update({"target": os.readlink(name, dir_fd=fd),
                                 "physicalMode": info.st_mode & 0o7777})
                else:
                    raise UnpackError("Unexpected physical special member")
                require(not item["physicalMode"] & 0o6000, "Physical setuid/setgid bit present")
                inventory.append(item)
            metadata = self.metadata.get(parts, {"mode": 0o755})
            # Children are finalized first; restrictive directories never obstruct unpacking.
            os.fchmod(fd, metadata["mode"] & 0o1777)

        walk(self.fd, ())
        inventory.append({"path": "/", "type": "directory", "physicalUid": os.fstat(self.fd).st_uid,
                          "physicalGid": os.fstat(self.fd).st_gid,
                          "physicalMode": os.fstat(self.fd).st_mode & 0o7777,
                          "logicalUid": self.metadata.get((), {}).get("uid"),
                          "logicalGid": self.metadata.get((), {}).get("gid")})
        inventory.sort(key=lambda item: item["path"])
        return inventory


def member_record(member, kind, target):
    return {"path": member.name, "resolvedPath": "/" + "/".join(target), "type": kind,
            "uid": member.uid, "gid": member.gid, "uname": member.uname, "gname": member.gname,
            "mode": member.mode, "sanitizedMode": member.mode & 0o1777,
            "size": member.size, "mtime": member.mtime, "target": member.linkname or None,
            "fileSHA256": None, "paxHeaders": dict(member.pax_headers),
            "devmajor": member.devmajor, "devminor": member.devminor}


def apply_layer(tree, fd, plan):
    require(stat_signature(fd) == plan["signature"], "Blob changed before application")
    records = tree.whiteouts(plan["members"])
    stream = VerifiedStream(fd, plan["unpackedBytes"])
    count = 0
    try:
        with tarfile.open(fileobj=stream, mode="r|", bufsize=64 * 1024) as archive:
            for member in archive:
                require(count < len(plan["members"]) and
                        fingerprint(member) == fingerprint(plan["members"][count]),
                        "Tar member changed after preflight")
                count += 1
                if validate_member(member) not in ("whiteout", "opaque-whiteout"):
                    records.append(tree.apply(member, archive))
        digest, unpacked = stream.finish()
    finally:
        stream.close()
    require(count == len(plan["members"]) and digest == plan["diffId"] and
            unpacked == plan["unpackedBytes"] and stat_signature(fd) == plan["signature"],
            "Applied full stream differs from verified preflight")
    return records


def key_proofs(tree, config):
    entrypoint = config.get("config", {}).get("Entrypoint", [])
    require(isinstance(entrypoint, list) and entrypoint and
            isinstance(entrypoint[0], str) and entrypoint[0].startswith("/"),
            "Expected absolute native Entrypoint")
    groups = {"entrypoint": [entrypoint[0]], "php": ["/usr/local/bin/php", "/usr/bin/php"],
              "phpfpm": ["/usr/local/sbin/php-fpm", "/usr/sbin/php-fpm"],
              "nginx": ["/usr/sbin/nginx", "/usr/local/sbin/nginx"],
              "composer": ["/usr/local/bin/composer", "/usr/bin/composer", "/opt/docker/bin/composer"]}
    result = {}
    for group, candidates in groups.items():
        for candidate in candidates:
            proof = tree.file_proof(candidate)
            if proof["status"] == "PRESENT":
                result[group] = proof
                break
        require(group in result, "Missing native critical file: " + group)
    configuration = []
    for parts, metadata in sorted(tree.metadata.items()):
        path = "/" + "/".join(parts)
        if metadata["type"] in ("file", "hardlink", "symlink") and (
            path.startswith(("/etc/", "/usr/local/etc/", "/opt/docker/etc/")) and
            path.endswith((".conf", ".ini")) or path in ("/etc/passwd", "/etc/group")):
            configuration.append(tree.file_proof(path))
    fields = {}
    native_config = config.get("config", {})
    for name in ("Entrypoint", "Cmd", "Env", "WorkingDir", "User", "StopSignal", "ExposedPorts", "Labels"):
        value = native_config.get(name)
        fields[name] = {"present": name in native_config, "value": value, "sha256": sha256(canonical(value))}
    return result, configuration, fields


def main():
    require(len(sys.argv) == 2, "Usage: python3 unpack-rootfs.py OWNED_RUN")
    os.umask(0o077)
    run, cfg, config, layers, diff_ids = validate_run(sys.argv[1])
    fds = []
    tree = None
    linux_root = Path(cfg["linuxRoot"])
    started = False
    try:
        # Pin no-follow input file descriptors and verify ALL compressed blobs first.
        for index, layer in enumerate(layers):
            fd = regular_fd(run / "blobs" / (layer["digest"][7:] + ".tar.gz"))
            fds.append(fd)
            before = stat_signature(fd)
            digest, size = hash_fd(fd)
            require(digest == layer["digest"] and size == layer["size"] and
                    stat_signature(fd) == before, "Compressed blob digest/size mismatch at layer " + str(index))
        plans, unpacked, members_count, file_bytes = inspect_layers(fds, layers, diff_ids)
        # Nothing under linuxRoot exists until every compressed/uncompressed layer is verified.
        write_json(run / "unpack-started.json", {"marker": cfg["marker"], "run": cfg["run"],
                   "linuxRoot": cfg["linuxRoot"], "status": "STARTED", "reuseAllowed": False})
        started = True
        os.mkdir(str(linux_root), 0o700)
        owned_directory(linux_root)
        require(os.lstat(str(linux_root)).st_uid == 1000, "Linux destination owner must be UID 1000")
        fd = os.open(str(linux_root / ".marker"), os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                     os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        with os.fdopen(fd, "wb") as marker:
            marker.write(cfg["marker"].encode("ascii"))
        os.mkdir(cfg["rootfs"], 0o700)
        os.mkdir(str(linux_root / "metadata"), 0o700)
        tree = ImageTree(Path(cfg["rootfs"]))
        limitations = [
            "Only frozen base-image OCI layer content is materialized; no project COPY/build or image code was executed.",
            "Logical uid/gid/mode are recorded; all physical rootfs entries are owned by Linux UID 1000. No chown.",
            "Physical file/directory modes preserve ordinary permissions and sticky bit, with setuid/setgid cleared.",
            "Symlink text is unchanged (including absolute targets); resolve only inside image scope or a future chroot.",
            "Symlink chmod, timestamps, ACLs, xattrs/capabilities and special devices/FIFOs are not materialized.",
            "Directories/files use temporary owner-access modes during merge; final modes are applied after all layers.",
            "Hardlinks require an already existing regular image target; the last hardlink header controls shared inode metadata.",
            "Implicit parent directories use logical mode 0755 with unspecified logical uid/gid.",
            "Sparse/unknown tar types, forward hardlinks, backslash entry names and paths deeper than 256 components are rejected."
            "This is an unprivileged materialization, not a stock-root or application-user runtime/permission verification."
        ]
        layer_results = []
        for index, (fd, layer, plan) in enumerate(zip(fds, layers, plans)):
            records = apply_layer(tree, fd, plan)
            impacts = []
            for record in records:
                affected = []
                if record["mode"] & 0o6000:
                    affected.append("setuid/setgid cleared")
                if record["paxHeaders"]:
                    affected.append("PAX metadata retained in audit only beyond interpreted tar fields")
                if record["type"] in ("character-device", "block-device", "fifo"):
                    affected.append(record["limitation"])
                if record.get("inodeMetadataChanged"):
                    affected.append("Hardlink header changes shared logical inode metadata")
                if affected:
                    impacts.append({"path": record["path"], "effects": affected})
            metadata_path = linux_root / "metadata" / ("layer-%02d.json" % index)
            metadata_digest = write_json(metadata_path, {"position": index, "compressedDigest": layer["digest"],
                "diffId": plan["diffId"], "chainId": plan["chainId"], "unpackedBytes": plan["unpackedBytes"],
                "members": records, "limitations": impacts})
            layer_results.append({"position": index, "digest": layer["digest"], "compressedBytes": layer["size"],
                "diffId": plan["diffId"], "chainId": plan["chainId"], "unpackedBytes": plan["unpackedBytes"],
                "members": len(records), "metadata": str(metadata_path), "metadataSHA256": metadata_digest,
                "limitations": impacts})
            print(json.dumps({"event": "LayerApplied", "position": index, "members": len(records)}), flush=True)
        critical, configuration, fields = key_proofs(tree, config)
        # Detect compressed input changes again before committing a successful materialization.
        for fd, layer, plan in zip(fds, layers, plans):
            require(stat_signature(fd) == plan["signature"] and
                    hash_fd(fd) == (layer["digest"], layer["size"]), "Saved compressed layer changed during merge")
        inventory = tree.finish_permissions()
        inventory_path = linux_root / "metadata/final-inventory.json"
        inventory_digest = write_json(inventory_path, inventory)
        summary = {"status": "PASS", "pullrun": cfg["run"], "linuxPullrun": str(run),
            "rootfs": cfg["rootfs"], "linuxRoot": cfg["linuxRoot"], "marker": cfg["marker"],
            "manifestDigest": MANIFEST_DIGEST, "configDigest": CONFIG_DIGEST, "layers": 20,
            "totalCompressedBytes": TOTAL_COMPRESSED, "totalUnpackedBytes": unpacked,
            "totalMembers": members_count, "totalFilePayloadBytes": file_bytes,
            "diffIdsChain": [{"position": index, "diffId": plan["diffId"], "chainId": plan["chainId"]}
                             for index, plan in enumerate(plans)],
            "finalChainId": plans[-1]["chainId"], "layerResults": layer_results,
            "nativeKeyFiles": critical, "configurationFiles": configuration,
            "nativeConfigFields": fields, "finalInventory": str(inventory_path),
            "finalInventorySHA256": inventory_digest, "physicalOwnerUID": 1000,
            "materializedEntries": len(inventory), "limitations": limitations,
            "scope": "Original frozen twenty-layer base-image content verified and merged with OCI whiteouts in image path scope. "
                     "No host root, chown, package installation, namespace, services, Docker build/run, application changes "
                     "or stock-root privilege/drop validation. Special entries and non-permission metadata are audit-only."}
        summary_digest = write_json(run / "unpack-summary.json", summary)
        write_json(linux_root / "completed.json", {"status": "PASS", "marker": cfg["marker"],
                   "summary": str(run / "unpack-summary.json"), "summarySHA256": summary_digest})
        print(json.dumps({"status": "PASS", "pullrun": cfg["run"], "rootfs": cfg["rootfs"],
                          "layers": 20, "finalChainId": plans[-1]["chainId"],
                          "nativeKeyFiles": critical, "nativeConfigFields": fields,
                          "summary": str(run / "unpack-summary.json")}, sort_keys=True), flush=True)
    except BaseException as error:
        if started:
            failure = run / "unpack-failure.json"
            if not os.path.lexists(str(failure)):
                write_json(failure, {"status": "FAIL", "error": str(error), "marker": cfg["marker"],
                                    "linuxRoot": str(linux_root), "reuseAllowed": False})
        raise
    finally:
        if tree is not None:
            tree.close()
        for fd in fds:
            os.close(fd)


if __name__ == "__main__":
    try:
        main()
    except (UnpackError, OSError, ValueError, TypeError, KeyError, tarfile.TarError,
            EOFError, RecursionError) as error:
        print(json.dumps({"status": "FAIL", "error": str(error)}, ensure_ascii=True), file=sys.stderr)
        sys.exit(1)
