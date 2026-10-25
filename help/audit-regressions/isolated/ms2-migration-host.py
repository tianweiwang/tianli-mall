"""Windows host for existing MS2 normal-only verification; explicit phases only.
Design/acceptance: docs/multi-merchant/07-plan-ms2.md. Requires an actual clean
committed checkout and new private Run. The original PHP entry owns the four CLI
business steps and all assertions/private cleanup. Binding/verify PHP leaves are
extracted from its committed original shell. No import-time native operations,
automatic retry/restart, teardown/down, forced stop or failed cleanup bypass.
"""
from __future__ import annotations
import argparse
import csv
import hashlib
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import subprocess
import sys
import time

PHP_IMAGE = "sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a"
MYSQL_IMAGE = "sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4"
REDIS_IMAGE = "sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e"
HARNESS = "/workspace/help/audit-regressions/isolated/lib/harness.php"
ENTRY = "/workspace/help/audit-regressions/isolated/ms2-migration.php"
SERVICES = {"audit-ms2-migration", "audit-mysql", "audit-redis"}
DECLARED_VOLUMES = {"audit-vendor", "mysql-data", "redis-data", "app-runtime", "runner-runtime", "worker-runtime", "chat-runtime", "backup-output", "bootstrap-secrets", "redis-secrets", "app-secrets", "fixture-secrets"}
MOUNT_VOLUMES = DECLARED_VOLUMES - {"backup-output"}
NET_FORMAT = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'
VOL_FORMAT = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
CID_FORMAT = '{"id":{{json .Id}},"image_id":{{json .Image}},"hostname":{{json .Config.Hostname}},"mounts":{{json .Mounts}},"labels":{{json .Config.Labels}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"user":{{json .Config.User}},"entrypoint":{{json .Config.Entrypoint}},"command":{{json .Config.Cmd}},"working_dir":{{json .Config.WorkingDir}},"tmpfs":{{json .HostConfig.Tmpfs}},"restart_policy":{{json .HostConfig.RestartPolicy.Name}},"restart_count":{{json .RestartCount}},"created_at":{{json .Created}},"state":{"Running":{{json .State.Running}},"Status":{{json .State.Status}},"ExitCode":{{json .State.ExitCode}},"Pid":{{json .State.Pid}},"StartedAt":{{json .State.StartedAt}},"FinishedAt":{{json .State.FinishedAt}}}}'
SOURCE_PATHS = ['crmeb/app/model/product/product/StoreProductRelation.php', 'crmeb/app/services/activity/advance/StoreAdvanceServices.php', 'crmeb/app/services/activity/bargain/StoreBargainServices.php', 'crmeb/app/services/activity/combination/StoreCombinationServices.php', 'crmeb/app/services/activity/integral/StoreIntegralOrderServices.php', 'crmeb/app/services/activity/integral/StoreIntegralServices.php', 'crmeb/app/services/activity/seckill/StoreSeckillServices.php', 'crmeb/app/services/marketplace/PublicCatalogServices.php', 'crmeb/app/services/marketplace/freight/MerchantFreightPolicy.php', 'crmeb/app/services/order/StoreCartServices.php', 'crmeb/app/services/order/StoreOrderComputedServices.php', 'crmeb/app/services/product/product/StoreDescriptionServices.php', 'crmeb/app/services/product/product/StoreProductServices.php', 'crmeb/app/services/system/attachment/SystemAttachmentServices.php', 'crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/bootstrap.sh', 'help/audit-regressions/isolated/compose.ms2-migration.yml', 'help/audit-regressions/isolated/compose.yml', 'help/audit-regressions/isolated/lib/guard.php', 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms2-migration-host.py', 'help/audit-regressions/isolated/ms2-migration.php', 'help/audit-regressions/isolated/ms2-migration.sh', 'help/audit-regressions/isolated/teardown.sh', 'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php']

class Blocked(Exception):
    pass

def require(ok, code):
    if not ok:
        raise Blocked(code)

def encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")

def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for part in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(part)
    return {"bytes": path.stat().st_size, "sha256": h.hexdigest()}

def new(path, data):
    with path.open("xb") as stream:
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    os.chmod(path, 0o600)

def replace_owned(path, data):
    temporary = path.with_name(path.name + ".ms2-host-temporary")
    new(temporary, data)
    os.replace(temporary, path)

def json_read(path):
    return json.loads(path.read_bytes())

def regular(path):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and not path.is_symlink()
            and not getattr(info, "st_file_attributes", 0) & 0x400 and info.st_nlink == 1,
            "REGULAR_SINGLE_LINK_REQUIRED")
    return info

def file_identity(info):
    return tuple(getattr(info, key) for key in ("st_dev", "st_ino", "st_mode", "st_nlink",
                                                "st_uid", "st_gid", "st_size", "st_mtime_ns", "st_ctime_ns"))

def cross_api_identity(info):
    birthtime = getattr(info, "st_birthtime_ns", None)
    require(type(birthtime) is int and birthtime > 0, "NATIVE_BIRTHTIME_REQUIRED")
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, birthtime)

def canonical_host(value):
    value = value.replace("\\", "/").rstrip("/")
    match = re.fullmatch(r"(?i)/run/desktop/mnt/host/([a-z])(/.*)", value)
    if match:
        value = match[1] + ":" + match[2]
    return value.casefold()

def scene_projection(inventory):
    # Comparison only: retain every complete Mounts object, duplicate and count.
    # collect() and all raw snapshots remain untouched; no other native field is normalized.
    return {**inventory, "services": {
        service: {**item, "mounts": sorted(item["mounts"], key=encoded)}
        for service, item in inventory["services"].items()}}


def sha(path):
    return digest(path)["sha256"]


def same_container(left, right):
    return scene_projection({"services": {"one": left}}) == scene_projection({"services": {"one": right}})


def invariant_container(left, right):
    keys = ["id", "image_id", "hostname", "mounts", "labels", "read_only", "privileged", "network_mode", "user", "entrypoint", "command", "working_dir", "tmpfs", "restart_policy", "created_at"]
    require(same_container({k: left[k] for k in keys}, {k: right[k] for k in keys}), "SAME_COMPLETE_ORIGINAL_CONFIGURATION")


def terminal_same(before, after):
    invariant_container(before, after)
    require(after["restart_count"] == 0 and after["state"]["Running"] is False
            and after["state"]["Status"] == "exited" and after["state"]["Pid"] == 0
            and after["state"]["ExitCode"] == 0 and bool(after["state"]["StartedAt"])
            and bool(after["state"]["FinishedAt"]), "SAME_ORIGINAL_EXITED_ZERO_PID0")
    if before["state"]["Status"] != "created":
        require(after["state"]["StartedAt"] == before["state"]["StartedAt"], "SAME_ORIGINAL_GENERATION")


def pinned(path, expected):
    require(re.fullmatch(r"[a-f0-9]{64}", expected or ""), "ACTUAL_RECEIPT_SHA_REQUIRED")
    regular(path); require(sha(path) == expected, "ACTUAL_RECEIPT_CHANGED")
    return json_read(path)


def streams(directory, expected):
    require(digest(directory / "receipts.jsonl") == expected, "COMPLETE_NATIVE_LEDGER_PIN")
    rows = [json.loads(line) for line in (directory / "receipts.jsonl").read_bytes().splitlines()]
    require(rows and [r["sequence"] for r in rows] == list(range(1, len(rows) + 1)), "ORDERED_NATIVE_SEQUENCE")
    for row in rows:
        stem = f"{row['sequence']:04d}-{row['label']}"
        require(Path(stem).name == stem and row["same_handle_wait"] is True and row["natural_exit"] == 0
                and row["interrupted"] is False and type(row["pid"]) is int and row["pid"] > 0
                and (directory / (stem + ".json")).read_bytes() == encoded(row), "ALL_REAL_NATIVES_NATURAL_ZERO")
        running = json_read(directory / (stem + ".running.json"))
        require(running["pid"] == row["pid"] and running["argv"] == row["argv"]
                and running["sequence"] == row["sequence"] and running["status"] == "RUNNING_SAME_HANDLE", "SAME_NATIVE_HANDLE")
        for name in ("stdout", "stderr"):
            item = row[name]; require(Path(item["file"]).name == item["file"], "RAW_STREAM_BASENAME")
            regular(directory / item["file"])
            require(digest(directory / item["file"]) == {"bytes": item["bytes"], "sha256": item["sha256"]}, "COMPLETE_RAW_DUAL_STREAMS")
    return rows


class Caller:
    def __init__(self, args, run_id):
        self.repo = Path(args.repo).resolve(strict=True); self.commit = args.source
        self.run_dir = Path(args.run_dir).absolute(); self.output = self.run_dir / ("host-" + args.phase)
        self.git_path = args.git or shutil.which("git"); self.docker_path = args.docker or shutil.which("docker")
        require(self.git_path and self.docker_path, "LOCAL_GIT_AND_DOCKER_REQUIRED")
        self.run_id = run_id; self.project = "tianli-audit-" + run_id
        self.context = None; self.engine = None; self.sequence = 0; self.images = {}
        self.env = {k: v for k, v in os.environ.items() if k.upper() in {
            "PATH", "PATHEXT", "SYSTEMROOT", "WINDIR", "COMSPEC", "USERPROFILE", "HOMEDRIVE", "HOMEPATH",
            "TEMP", "TMP", "APPDATA", "LOCALAPPDATA", "PROGRAMDATA", "PROGRAMFILES", "PROGRAMFILES(X86)"}}
        self.env.update(AUDIT_RUN_ID=run_id, AUDIT_PROJECT=self.project, AUDIT_RUN_DIR=self.run_dir.as_posix(),
                        AUDIT_REPO_DIR=self.repo.as_posix(), AUDIT_SOURCE_SHA=self.commit, AUDIT_GUARD_MODE="read-only",
                        AUDIT_ISOLATED="1", AUDIT_MILESTONE="MS2", AUDIT_PHASE="normal-only",
                        MSYS_NO_PATHCONV="1", MSYS2_ARG_CONV_EXCL="*")

    def native(self, label, argv):
        """Direct streams avoid pipe deadlocks. The one original handle is waited naturally."""
        self.sequence += 1
        stem = f"{self.sequence:04d}-{label}"
        out = self.output / (stem + ".stdout.bin")
        err = self.output / (stem + ".stderr.bin")
        receipt_path = self.output / (stem + ".json")
        with out.open("xb") as stdout, err.open("xb") as stderr:
            process = subprocess.Popen(argv, cwd=self.output, env=self.env,
                                       stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                                       shell=False, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            new(self.output / (stem + ".running.json"), encoded({
                "sequence": self.sequence, "label": label, "pid": process.pid,
                "status": "RUNNING_SAME_HANDLE", "argv": argv, "started_ns": time.time_ns()}))
            interrupted = False
            while True:
                try:
                    code = process.wait()
                    break
                except KeyboardInterrupt:
                    # Retain the same live handle and streams; never terminate a Docker client.
                    interrupted = True
                    print(json.dumps({"status": "INTERRUPTED_HANDLE_RETAINED", "sequence": self.sequence}), flush=True)
            stdout.flush(); stderr.flush()
            os.fsync(stdout.fileno()); os.fsync(stderr.fileno())
        os.chmod(out, 0o600); os.chmod(err, 0o600)
        receipt = {"sequence": self.sequence, "label": label, "pid": process.pid,
                   "same_handle_wait": True, "natural_exit": code, "interrupted": interrupted,
                   "stdout": {"file": out.name, **digest(out)}, "stderr": {"file": err.name, **digest(err)},
                   "completed_ns": time.time_ns(), "argv": argv}
        new(receipt_path, encoded(receipt))
        with (self.output / "receipts.jsonl").open("ab") as ledger:
            ledger.write(json.dumps(receipt, ensure_ascii=False, sort_keys=True).encode("utf-8") + b"\n")
            ledger.flush(); os.fsync(ledger.fileno())
        require(code == 0 and not interrupted, "NATIVE_FAILED_SCENE_RETAINED")
        return out.read_bytes()


    def git(self, *args):
        return self.native("git", [self.git_path, "-c", "safe.directory=" + str(self.repo), "-C", str(self.repo), *args])

    def docker(self, label, *args):
        require(self.context is not None, "LOCAL_DOCKER_CONTEXT_NOT_BOUND")
        return self.native(label, [self.docker_path, "--context", self.context, *args])

    def compose(self, label, *args):
        directory = self.repo / "help/audit-regressions/isolated"
        return self.docker(label, "compose", "--project-name", self.project,
                           "--file", str(directory / "compose.yml"), "--file", str(directory / "compose.ms2-migration.yml"),
                           "--profile", "ms2-migration", *args)

    def local_engine(self, expected=None):
        require(os.name == "nt", "WINDOWS_HOST_REQUIRED")
        if self.context is None:
            self.context = self.native("context", [self.docker_path, "context", "show"]).decode().strip()
            require(re.fullmatch(r"[A-Za-z0-9_.-]+", self.context), "CONTEXT_NAME_INVALID")
        endpoint = json.loads(self.docker("context-endpoint", "context", "inspect", self.context, "--format", "{{json .Endpoints.docker.Host}}"))
        require(isinstance(endpoint, str) and endpoint.startswith("npipe://"), "LOCAL_WINDOWS_DOCKER_ONLY")
        self.engine = self.docker("engine", "info", "--format", "{{.ID}}").decode().strip()
        require(self.engine and (expected is None or self.engine == expected), "ENGINE_IDENTITY_CHANGED")

    def private_acl(self):
        system = Path(os.environ["SYSTEMROOT"]) / "System32"
        raw = self.native("current-sid", [str(system / "whoami.exe"), "/user", "/fo", "csv", "/nh"]).decode("utf-8", "replace")
        sid = list(csv.reader(io.StringIO(raw)))[0][-1]
        require(re.fullmatch(r"S-[0-9-]+", sid), "CURRENT_PRIVATE_SID")
        self.native("private-acl", [str(system / "icacls.exe"), str(self.run_dir), "/inheritance:r", "/grant:r",
                                  "*" + sid + ":(OI)(CI)F", "*S-1-5-18:(OI)(CI)F"])

    def source_binding(self):
        require(not self.repo.is_symlink() and not getattr(self.repo.lstat(), "st_file_attributes", 0) & 0x400, "REPARSE_REPO_REFUSED")
        require(self.git("rev-parse", "HEAD").decode().strip() == self.commit, "ACTUAL_COMMIT_CHANGED")
        require(self.git("status", "--porcelain=v1", "-z", "--untracked-files=all") == b"", "COMPLETE_CLEAN_COMMIT_REQUIRED")
        for name in ("crmeb/.env", "crmeb/public/install.lock"):
            require(not os.path.lexists(self.repo / name), "HOST_ENV_OR_INSTALL_LOCK_REFUSED")
        rows = self.git("ls-tree", "-r", "-z", "--full-tree", "HEAD").split(b"\0")
        entries = {}; aggregate = hashlib.sha256()
        for row in filter(None, rows):
            meta, raw_name = row.split(b"\t", 1); mode, kind, blob = meta.split(); name = raw_name.decode("utf-8")
            require(mode in (b"100644", b"100755") and kind == b"blob" and not Path(name).is_absolute()
                    and ".." not in Path(name).parts, "REGULAR_COMMITTED_TREE_REQUIRED")
            path = self.repo / name; before = regular(path)
            h1 = hashlib.sha1(b"blob " + str(before.st_size).encode() + b"\0"); h256 = hashlib.sha256()
            with path.open("rb") as handle:
                opened = os.fstat(handle.fileno()); require(cross_api_identity(opened) == cross_api_identity(before), "OPEN_FILE_CHANGED")
                for part in iter(lambda: handle.read(1024 * 1024), b""):
                    h1.update(part); h256.update(part)
                finished = os.fstat(handle.fileno()); after = regular(path)
                require(file_identity(finished) == file_identity(opened) and file_identity(after) == file_identity(before)
                        and cross_api_identity(finished) == cross_api_identity(after) == cross_api_identity(opened) == cross_api_identity(before), "SOURCE_PATH_HANDLE_CHANGED")
            require(h1.hexdigest().encode() == blob, "RAW_BYTES_DO_NOT_MATCH_COMMITTED_BLOB")
            entries[name] = {"blob": blob.decode(), "bytes": before.st_size, "sha256": h256.hexdigest()}
            aggregate.update(json.dumps([name, blob.decode(), h256.hexdigest(), before.st_size], ensure_ascii=False).encode() + b"\n")
        require(len({name.casefold() for name in entries}) == len(entries), "CASE_COLLIDING_TREE")
        actual = set()
        for directory, directories, names in os.walk(self.repo, followlinks=False):
            if Path(directory) == self.repo and ".git" in directories: directories.remove(".git")
            for name in directories:
                info = (Path(directory) / name).lstat()
                require(not stat.S_ISLNK(info.st_mode) and not getattr(info, "st_file_attributes", 0) & 0x400, "REPARSE_DIRECTORY_REFUSED")
            for name in names:
                path = Path(directory) / name; relative = path.relative_to(self.repo).as_posix()
                if relative != ".git": regular(path); actual.add(relative)
        require(actual == set(entries), "EXACT_COMMITTED_NAMESPACE_REQUIRED")
        selected = {path: entries[path] for path in SOURCE_PATHS}
        php = (self.repo / "help/audit-regressions/isolated/ms2-migration.php").read_text(encoding="utf-8")
        body = re.findall(r"function m2m_sourcePaths\(\):array \{\n(.*?)\n\}", php, re.S)
        require(len(body) == 1 and sorted(re.findall(r"'([^']+)'", body[0])) == SOURCE_PATHS, "EXACT_SOURCE26_CLOSURE")
        require(Path(__file__).resolve() == self.repo / "help/audit-regressions/isolated/ms2-migration-host.py", "COMMITTED_HOST_REQUIRED")
        return {"commit": self.commit, "tracked_files": len(entries), "aggregate_sha256": aggregate.hexdigest(), "source26": selected}

    def leaf(self, name):
        require(name in ("bind", "verify"), "FIXED_ORIGINAL_SHELL_LEAF")
        shell = (self.repo / "help/audit-regressions/isolated/ms2-migration.sh").read_text(encoding="utf-8")
        result = re.findall(r"(?m)^m2_" + name + r"_php=\"\$\(cat <<'PHP'\n(.*?)\nPHP\n\)\"$", shell, re.S)
        require(len(result) == 1 and result[0].startswith("ini_set("), "ORIGINAL_SHELL_LEAF_BOUNDARY")
        return result[0]

    def configuration(self):
        config = json.loads(self.compose("compose-config", "config", "--format", "json"))
        require(set(config["volumes"]) == DECLARED_VOLUMES and config["networks"]["isolation"]["internal"] is True, "ORIGINAL_DECLARED_VOLUME_AND_NETWORK_CONTRACT")
        used = set()
        for service in SERVICES | {"audit-bootstrap"}:
            item = config["services"][service]
            require(item.get("ports", []) == [] and set(item["networks"]) == {"isolation"}, "CONFIG_NO_PORTS_INTERNAL_ONLY")
            require(item.get("restart", "no") == "no", "CONFIG_NO_RESTART")
            expected_image = PHP_IMAGE if service in ("audit-bootstrap", "audit-ms2-migration") else {
                "audit-mysql": "ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45", "audit-redis": "ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1"}[service]
            require(item["image"] == expected_image, "ORIGINAL_CONFIG_IMAGE")
            if service in SERVICES: used.update(m["source"] for m in item["volumes"] if m["type"] == "volume")
        require(used == MOUNT_VOLUMES, "ORIGINAL_SELECTED_ELEVEN_MOUNT_VOLUME_CLOSURE")
        return {"declared_volumes": sorted(config["volumes"]), "selected_volume_closure": sorted(used), "resolved_sha256": hashlib.sha256(encoded(config)).hexdigest()}

    def image_qualification(self):
        specs = {"audit-ms2-migration": (PHP_IMAGE, PHP_IMAGE), "audit-mysql": ("ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45", MYSQL_IMAGE),
                 "audit-redis": ("ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1", REDIS_IMAGE)}
        for service, (spec, wanted) in specs.items():
            actual = self.docker("local-image", "image", "inspect", "--format", "{{.Id}}", spec).decode().strip()
            require(actual == wanted, "FIXED_LOCAL_IMAGE_REQUIRED"); self.images[service] = actual

    def labels(self, item):
        expected = {"com.docker.compose.project": self.project, "tianli.audit": "1", "tianli.audit.run": self.run_id}
        return all((item.get("labels") or {}).get(key) == value for key, value in expected.items())

    def inspect(self, cid):
        require(re.fullmatch(r"[a-f0-9]{64}", cid), "EXACT_FULL_CID_REQUIRED")
        return json.loads(self.docker("container-inspect", "inspect", "--format", CID_FORMAT, cid))

    def mounts(self, item, service):
        if service == "audit-mysql":
            expected = {"/var/lib/mysql": ("volume", "mysql-data", True), "/run/audit-bootstrap": ("volume", "bootstrap-secrets", False)}
        elif service == "audit-redis":
            expected = {"/data": ("volume", "redis-data", True), "/run/audit-redis": ("volume", "redis-secrets", False)}
        else:
            helper = service == "audit-bootstrap"
            expected = {"/workspace": ("bind", str(self.repo), False), "/source": ("bind", str(self.repo), False),
                        "/run/audit": ("bind", str(self.run_dir), True), "/workspace/crmeb/vendor": ("volume", "audit-vendor", helper),
                        "/run/audit-bootstrap": ("volume", "bootstrap-secrets", helper), "/run/audit-redis": ("volume", "redis-secrets", helper),
                        "/run/audit-app": ("volume", "app-secrets", helper), "/run/audit-fixtures": ("volume", "fixture-secrets", True)}
            if not helper:
                expected["/workspace/crmeb/public/uploads"] = ("volume", "fixture-secrets", False)
                expected.update({"/run/audit-" + role + "-runtime": ("volume", role + "-runtime", False) for role in ("app", "runner", "worker", "chat")})
            require(item["tmpfs"] is not None and set(item["tmpfs"]) == {"/tmp", "/workspace/crmeb/runtime"}, "EXACT_TWO_PHP_TMPFS")
        actual = {m["Destination"]: m for m in item["mounts"]}
        require(len(actual) == len(item["mounts"]) and set(actual) == set(expected), "EXACT_COMPLETE_NATIVE_MOUNT_SET")
        for destination, (kind, source, rw) in expected.items():
            mount = actual[destination]
            require(mount["Type"] == kind and mount["RW"] is rw and (canonical_host(mount["Source"]) == canonical_host(source)
                    if kind == "bind" else mount["Name"] == self.project + "_" + source), "ACTUAL_MOUNT_SOURCE_TYPE_MODE")

    def container_contract(self, item, service, command=None):
        require(self.labels(item) and item["labels"]["com.docker.compose.service"] == service
                and item["restart_policy"] == "no" and item["restart_count"] == 0 and item["privileged"] is False
                and item["network_mode"] == self.project + "_isolation"
                and list(item["networks"]) == [self.project + "_isolation"]
                and all(value is None for value in (item["ports"] or {}).values()), "OWN_ONLY_INTERNAL_NO_RESTART_NO_PORTS")
        image = PHP_IMAGE if service == "audit-bootstrap" else self.images[service]
        require(item["image_id"] == image, "ORIGINAL_PINNED_CONTAINER_IMAGE")
        if service in ("audit-bootstrap", "audit-ms2-migration"):
            require(item["read_only"] is True and item["entrypoint"] == ["php"] and item["working_dir"] == "/workspace"
                    and item["user"] in ("", "0", "0:0", "root"), "ORIGINAL_PHP_UID_AND_READONLY")
            require(item["command"] == (command if service == "audit-bootstrap" else [ENTRY.removeprefix("/workspace/")]), "ORIGINAL_FINITE_COMMAND")
        self.mounts(item, service)

    def queries(self, expected):
        result = []
        for kind, command in [("container", ["ps", "-aq", "--no-trunc"]), ("network", ["network", "ls", "--no-trunc", "-q"]), ("volume", ["volume", "ls", "-q"])]:
            for label, value in [("project", "com.docker.compose.project=" + self.project), ("run", "tianli.audit.run=" + self.run_id)]:
                actual = self.docker("resource-" + kind + "-" + label, *command, "--filter", "label=" + value).decode().split()
                require(len(actual) == len(set(actual)) and sorted(actual) == sorted(expected[kind]), "EXACT_ACTUAL_RESOURCE_SET")
                if not expected[kind]:
                    row = json_read(self.output / f"{self.sequence:04d}-resource-{kind}-{label}.json")
                    require(row["stdout"]["bytes"] == row["stderr"]["bytes"] == 0, "EMPTY_RAW_DUAL_ZERO_STREAMS")
                result.append({"kind": kind, "label": label, "values": actual})
        return result

    def volume_consumers(self, names, inventory):
        for name in names:
            expected = {row["id"] for row in inventory.values() if any(m["Type"] == "volume" and m.get("Name") == name for m in row["mounts"])}
            actual = self.docker("only-own-volume", "ps", "-aq", "--no-trunc", "--filter", "volume=" + name).decode().split()
            require(len(actual) == len(set(actual)) and set(actual) == expected, "ONLY_ACTUAL_OWN_VOLUME_CONSUMERS")

    def collect(self, states):
        network = json.loads(self.docker("network-inspect", "network", "inspect", "--format", NET_FORMAT, self.project + "_isolation"))
        require(network["Name"] == self.project + "_isolation" and network["Internal"] is True
                and self.labels({"labels": network["Labels"]}), "DEDICATED_INTERNAL_NETWORK_REQUIRED")
        ids = self.docker("container-list", "ps", "-aq", "--no-trunc", "--filter", "label=com.docker.compose.project=" + self.project).decode().split()
        services = {}
        for cid in sorted(ids):
            item = self.inspect(cid); service = item["labels"].get("com.docker.compose.service")
            require(service in states and service not in services, "EXACT_ORIGINAL_SERVICE_SET")
            self.container_contract(item, service); require(item["state"]["Status"] == states[service], "EXACT_ORIGINAL_SERVICE_STATE")
            if states[service] == "exited": terminal_same(item, item)
            elif states[service] == "created": require(item["state"]["Pid"] == 0 and item["state"]["Running"] is False, "FINITE_CREATED_NOT_STARTED")
            else: require(item["state"]["Running"] is True and item["state"]["Pid"] > 0, "ACTUAL_SERVICE_RUNNING")
            services[service] = item
        require(set(services) == set(states), "EXACT_THREE_ROLES_REQUIRED")
        names = sorted(self.docker("volume-list", "volume", "ls", "-q", "--filter", "label=com.docker.compose.project=" + self.project).decode().split())
        required = {self.project + "_" + name for name in MOUNT_VOLUMES}; declared = {self.project + "_" + name for name in DECLARED_VOLUMES}
        require(required <= set(names) <= declared and len(names) == len(set(names)), "ACTUAL_DECLARED_VOLUME_CLOSURE")
        volumes = [json.loads(self.docker("volume-inspect", "volume", "inspect", "--format", VOL_FORMAT, name)) for name in names]
        require(all(self.labels(item) and item["created_at"] for item in volumes), "ACTUAL_ALL_VOLUME_IDENTITIES")
        connected = {item["id"] for item in services.values() if item["state"]["Status"] == "running"}
        require(set(network["Containers"]) == connected, "ONLY_OWN_NETWORK_CONSUMERS")
        self.queries({"container": ids, "network": [network["Id"]], "volume": names}); self.volume_consumers(names, services)
        return {"network": network, "services": services, "volumes": volumes}

    def register(self, scene, tag):
        network = scene["network"]
        replace_owned(self.run_dir / "network.json", encoded({key: network[key] for key in ("Id", "Name", "Internal", "Labels")}))
        replace_owned(self.run_dir / "containers.jsonl", b"".join(encoded(item).replace(b"\n", b"") + b"\n" for item in scene["services"].values()))
        names = [item["name"] for item in scene["volumes"]]
        replace_owned(self.run_dir / "volumes.txt", ("\n".join(names) + "\n").encode())
        replace_owned(self.run_dir / "volumes.jsonl", b"".join(encoded(item).replace(b"\n", b"") + b"\n" for item in scene["volumes"]))
        self.helper(tag, "require " + json.dumps(HARNESS) + ";audit_register_resources();")
        manifest = json_read(self.run_dir / "manifest.json")
        require(manifest["run_id"] == self.run_id and manifest["commit_sha"] == self.commit and manifest["project"] == self.project
                and manifest["guard_mode"] == "read-only" and manifest["isolation"] == "ISO-FULL" and manifest["network_internal"] is True
                and manifest["network"] == {"id": network["Id"], "name": network["Name"]}
                and {name: item["id"] for name, item in manifest["services"].items()} == {name: item["id"] for name, item in scene["services"].items()}
                and manifest["volume_identities"] == {item["name"]: item["created_at"] for item in scene["volumes"]}, "ORIGINAL_THREE_ROLE_MANIFEST")
        return manifest

    def helper(self, tag, code, *args):
        require(re.fullmatch(r"[a-z][a-z0-9-]{0,40}", tag), "FINITE_HELPER_TAG")
        relative = "evidence/ms2-host-" + tag + "-uid.json"
        prelude = ("ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');"
                   "if(!is_dir('/run/audit/evidence')&&!mkdir('/run/audit/evidence',0700))exit(1);"
                   "$u=json_encode(['namespace_pid'=>getmypid(),'euid'=>posix_geteuid(),'egid'=>posix_getegid()],JSON_THROW_ON_ERROR).\"\\n\";"
                   "$h=fopen(" + json.dumps("/run/audit/" + relative) + ",'xb');if(!is_resource($h))exit(1);"
                   "if(fwrite($h,$u)!==strlen($u)||!fflush($h))exit(1);fclose($h);")
        command = ["-r", prelude + code, *args]
        cid = self.compose("helper-create-" + tag, "run", "--no-deps", "--detach", "--pull", "never", "--name", self.project + "-" + tag, "audit-bootstrap", *command).decode().strip()
        require(re.fullmatch(r"[a-f0-9]{64}", cid), "FINITE_HELPER_REAL_CID")
        before = self.inspect(cid); self.container_contract(before, "audit-bootstrap", command)
        new(self.output / ("helper-" + tag + "-created.json"), encoded(before))
        waited = self.docker("helper-wait-" + tag, "wait", cid)
        self.docker("helper-logs-" + tag, "logs", cid)
        after = self.inspect(cid)
        new(self.output / ("helper-" + tag + "-terminal.json"), encoded({"container": after, "actual_wait_stdout": waited.decode("ascii")}))
        self.container_contract(after, "audit-bootstrap", command)
        require(waited == b"0\n", "FINITE_HELPER_ACTUAL_NATURAL_ZERO")
        terminal_same(before, after)
        uid = json_read(self.run_dir / relative)
        require(uid == {"namespace_pid": 1, "euid": 0, "egid": 0}, "REAL_FINITE_HELPER_ROOT_UID_PID1")
        immediate = self.inspect(cid); require(same_container(immediate, after), "FINITE_HELPER_TERMINAL_HELD")
        require(self.docker("helper-nonforce-rm-" + tag, "rm", cid) == (cid + "\n").encode(), "FINITE_HELPER_EXACT_NONFORCE_RM")
        require(self.docker("helper-removed-" + tag, "ps", "-aq", "--no-trunc", "--filter", "id=" + cid) == b"", "FINITE_HELPER_REMOVED")
        return {"id": cid, "created": before, "terminal": after, "uid": uid}

    def receipt(self, name, value):
        value.update(schema=1, run_id=self.run_id, project=self.project, commit=self.commit, repo=str(self.repo), host_sha256=sha(Path(__file__)),
                     context=self.context, engine=self.engine, receipts=digest(self.output / "receipts.jsonl"), Task2_complete=False,
                     MS2_complete=False, historical_acceptance=False, original_G01_G07="NOT RUN")
        path = self.run_dir / (name + ".json"); new(path, encoded(value)); return path


def setup(caller):
    binding = caller.source_binding(); caller.local_engine()
    caller.queries({"container": [], "network": [], "volume": []})
    caller.image_qualification(); config = caller.configuration()
    prepare = caller.helper("prepare", "require " + json.dumps(ENTRY) + ";exit(m2f_prepare());")
    caller.compose("database-up", "up", "-d", "--wait", "--wait-timeout", "90", "--no-build", "--pull", "never", "audit-mysql", "audit-redis")
    caller.compose("finite-create", "create", "--no-recreate", "--no-build", "--pull", "never", "audit-ms2-migration")
    scene = caller.collect({"audit-ms2-migration": "created", "audit-mysql": "running", "audit-redis": "running"})
    caller.register(scene, "register-created")
    finite = scene["services"]["audit-ms2-migration"]
    facts = {"run_id": caller.run_id, "source_sha": caller.commit, "id": finite["id"], "image": finite["image_id"],
             "hostname": finite["hostname"], "labels": finite["labels"], "mounts": finite["mounts"], "tmpfs": finite["tmpfs"],
             "read_only": finite["read_only"], "privileged": finite["privileged"], "network_mode": finite["network_mode"],
             "status": finite["state"]["Status"], "command": finite["command"], "entrypoint": finite["entrypoint"],
             "workdir": finite["working_dir"], "restart_count": finite["restart_count"], "created_at": finite["created_at"]}
    new(caller.run_dir / "evidence/ms2-migration-container-created.json", encoded(facts))
    bound = caller.helper("bind-source", caller.leaf("bind"), caller.run_id, caller.commit, caller.repo.as_posix(), caller.run_dir.as_posix())
    source = json_read(caller.run_dir / "evidence/ms2-migration-source-binding.json")
    expected = [{"path": name, "bytes": item["bytes"], "sha256": item["sha256"]} for name, item in binding["source26"].items()]
    require(source["source_files"] == expected and source["source_sha"] == caller.commit and source["run_id"] == caller.run_id, "ORIGINAL_PHP_SOURCE26_EQUALS_NATIVE_GIT")
    held = caller.collect({"audit-ms2-migration": "created", "audit-mysql": "running", "audit-redis": "running"})
    require(scene_projection(held) == scene_projection(scene) and caller.source_binding() == binding, "SETUP_SOURCE_AND_FULL_SCENE_HELD")
    return caller.receipt("SETUP", {"status": "MS2_SETUP_COMPLETE_NOT_BUSINESS_VERIFIED", "binding": binding, "images": caller.images,
                                   "configuration": config, "scene": held, "prepare_helper": prepare, "binding_helper": bound,
                                   "manifest": digest(caller.run_dir / "manifest.json"), "manifest_value": json_read(caller.run_dir / "manifest.json"), "source_binding": digest(caller.run_dir / "evidence/ms2-migration-source-binding.json"),
                                   "created": digest(caller.run_dir / "evidence/ms2-migration-container-created.json"), "normal": "NOT RUN"})


def inputs(caller, args, normal=False):
    require(not any(caller.run_dir.glob("*-FAILURE.json")), "FAILED_SCENE_NO_AUTOMATIC_REPLAY_OR_CLEANUP")
    setup_receipt = pinned(caller.run_dir / "SETUP.json", args.setup_sha256)
    require(setup_receipt["status"] == "MS2_SETUP_COMPLETE_NOT_BUSINESS_VERIFIED" and setup_receipt["commit"] == caller.commit
            and setup_receipt["run_id"] == caller.run_id and setup_receipt["repo"] == str(caller.repo)
            and setup_receipt["host_sha256"] == sha(Path(__file__)), "ACTUAL_SETUP_SAME_SOURCE_RUN_HOST")
    streams(caller.run_dir / "host-setup", setup_receipt["receipts"])
    caller.context = setup_receipt["context"]; caller.images = setup_receipt["images"]
    require(caller.images == {"audit-ms2-migration": PHP_IMAGE, "audit-mysql": MYSQL_IMAGE, "audit-redis": REDIS_IMAGE}, "SETUP_FIXED_IMAGES")
    caller.local_engine(setup_receipt["engine"])
    require(caller.source_binding() == setup_receipt["binding"], "CURRENT_COMPLETE_SOURCE_BINDING")
    require(digest(caller.run_dir / "evidence/ms2-migration-source-binding.json") == setup_receipt["source_binding"]
            and digest(caller.run_dir / "evidence/ms2-migration-container-created.json") == setup_receipt["created"], "ORIGINAL_PUBLIC_BINDING_UNCHANGED")
    if not normal:
        require(digest(caller.run_dir / "manifest.json") == setup_receipt["manifest"]
                and json_read(caller.run_dir / "manifest.json") == setup_receipt["manifest_value"], "ORIGINAL_SETUP_MANIFEST_HELD")
        return setup_receipt, None
    result = pinned(caller.run_dir / "NORMAL.json", args.normal_sha256)
    require(result["status"] == "MS2_NORMAL_COMPLETE_ORDINARY_CLOSE_PENDING" and result["setup_sha256"] == args.setup_sha256
            and result["run_id"] == caller.run_id and result["commit"] == caller.commit and result["host_sha256"] == sha(Path(__file__)), "ACTUAL_SUCCESS_NORMAL_ONLY")
    streams(caller.run_dir / "host-normal", result["receipts"])
    for name, expected in result["reports"].items():
        require(Path(name).name == name and digest(caller.run_dir / "evidence" / name) == expected, "ORIGINAL_COMPLETE_NORMAL_REPORTS_HELD")
    require(digest(caller.run_dir / "manifest.json") == result["manifest"]
            and json_read(caller.run_dir / "manifest.json") == result["manifest_value"], "ORIGINAL_NORMAL_MANIFEST_HELD")
    return setup_receipt, result


def normal(caller, args):
    setup_receipt, _ = inputs(caller, args)
    before = caller.collect({"audit-ms2-migration": "created", "audit-mysql": "running", "audit-redis": "running"})
    require(scene_projection(before) == scene_projection(setup_receipt["scene"]), "SAME_CREATED_FINITE_BEFORE_START")
    cid = before["services"]["audit-ms2-migration"]["id"]
    caller.docker("finite-start-attached", "start", "--attach", cid)
    require(caller.docker("finite-wait", "wait", cid) == b"0\n", "FINITE_ACTUAL_NATURAL_ZERO")
    terminal = caller.inspect(cid); terminal_same(before["services"]["audit-ms2-migration"], terminal)
    receipt = {"run_id": caller.run_id, "source_sha": caller.commit, "id": cid, "image": terminal["image_id"],
               "status": terminal["state"]["Status"], "exit_code": terminal["state"]["ExitCode"], "pid": terminal["state"]["Pid"],
               "started_at": terminal["state"]["StartedAt"], "finished_at": terminal["state"]["FinishedAt"], "restart_count": terminal["restart_count"]}
    new(caller.run_dir / "evidence/ms2-migration-terminal.json", encoded(receipt))
    verify = caller.helper("verify", caller.leaf("verify"), caller.run_id, caller.commit, cid, "0")
    scene = caller.collect({"audit-ms2-migration": "exited", "audit-mysql": "running", "audit-redis": "running"})
    for service, original in before["services"].items():
        invariant_container(original, scene["services"][service])
        if service != "audit-ms2-migration": require(same_container(original, scene["services"][service]), "ORIGINAL_DATA_SERVICES_UNCHANGED")
    require(scene["volumes"] == before["volumes"] and caller.source_binding() == setup_receipt["binding"], "NORMAL_ALL_VOLUMES_AND_SOURCE_HELD")
    reports = {path.name: digest(path) for path in sorted((caller.run_dir / "evidence").iterdir())
               if path.is_file() and not path.is_symlink() and (path.name.startswith("ms2-migration-") or path.name == "ms2-host-verify-uid.json")}
    required = {"ms2-migration-normal.json", "ms2-migration-terminal.json", "ms2-migration-host-receipt.json", "ms2-migration-shutdown.json"}
    require(required <= set(reports), "ORIGINAL_NORMAL_AND_SHUTDOWN_REPORTS_REQUIRED")
    initializer = json_read(caller.run_dir / "evidence/ms2-migration-normal.json")["initializer"]
    wanted = {**setup_receipt["manifest_value"], "fixture_table_count": 157,
              "create_only_initializer": {"source_sha256": initializer["installation_source_sha256"], "create_blocks": 157,
                                          "create_inventory_sha256": initializer["create_inventory_sha256"],
                                          "installation_inserts_executed": 0, "only_declared_new_fixture_may_be_inserted": True}}
    require(json_read(caller.run_dir / "manifest.json") == wanted, "ONLY_ORIGINAL_CREATE_INITIALIZER_MANIFEST_ADDITIONS")
    return caller.receipt("NORMAL", {"status": "MS2_NORMAL_COMPLETE_ORDINARY_CLOSE_PENDING", "setup_sha256": args.setup_sha256,
                                    "scene": scene, "verify_helper": verify, "reports": reports,
                                    "manifest": digest(caller.run_dir / "manifest.json"), "manifest_value": wanted,
                                    "normal_only": True, "retirement": "NOT RUN"})


def close(caller, args):
    setup_receipt, result = inputs(caller, args, normal=True)
    scene = caller.collect({"audit-ms2-migration": "exited", "audit-mysql": "running", "audit-redis": "running"})
    require(scene_projection(scene) == scene_projection(result["scene"]), "ORIGINAL_SUCCESS_NORMAL_SCENE_REQUIRED")
    # Public-only original authorization reads exact three-role manifest/resource texts.
    caller.helper("authorize-close", "require " + json.dumps(HARNESS) + ";audit_authorize_cleanup();")
    inventory = scene["services"]; cids = sorted(item["id"] for item in inventory.values()); names = [item["name"] for item in scene["volumes"]]
    network_id = scene["network"]["Id"]; caller.volume_consumers(names, inventory)
    help_bytes = caller.docker("ordinary-stop-options", "container", "stop", "--help")
    require(b"--signal" in help_bytes and b"--timeout" in help_bytes, "EXPLICIT_TERM_INFINITE_WAIT_SUPPORT")
    stopped = {"audit-ms2-migration": caller.inspect(inventory["audit-ms2-migration"]["id"])}
    terminal_same(inventory["audit-ms2-migration"], stopped["audit-ms2-migration"])
    for service in ("audit-mysql", "audit-redis"):
        before = inventory[service]; cid = before["id"]; immediate = caller.inspect(cid)
        require(same_container(immediate, before), "IMMEDIATE_SAME_DATA_SERVICE_BEFORE_TERM")
        require(caller.docker("ordinary-term-" + service, "stop", "--signal", "SIGTERM", "--timeout", "-1", cid) == (cid + "\n").encode(), "EXACT_ORDINARY_TERM_ACK")
        after = caller.inspect(cid); terminal_same(before, after); stopped[service] = after
    for service, before in inventory.items():
        actual = caller.inspect(before["id"]); terminal_same(before, actual)
        require(same_container(actual, stopped[service]), "ALL_ORIGINAL_TERMINALS_HELD_BEFORE_RM")
    caller.queries({"container": cids, "network": [network_id], "volume": names}); caller.volume_consumers(names, stopped)
    require(caller.docker("nonforce-three-rm", "rm", *cids) == ("\n".join(cids) + "\n").encode(), "EXACT_NONFORCE_THREE_RM")
    network = json.loads(caller.docker("empty-original-network", "network", "inspect", "--format", NET_FORMAT, network_id))
    require({key: network[key] for key in scene["network"] if key != "Containers"} == {key: value for key, value in scene["network"].items() if key != "Containers"}
            and network["Containers"] == {}, "SAME_EMPTY_ORIGINAL_NETWORK")
    caller.docker("nonforce-network-rm", "network", "rm", network_id)
    caller.queries({"container": [], "network": [], "volume": names})
    volumes = [json.loads(caller.docker("same-volume-created", "volume", "inspect", "--format", VOL_FORMAT, name)) for name in names]
    require(volumes == scene["volumes"] and all(caller.labels(item) for item in volumes), "SAME_ACTUAL_COMPLETE_VOLUME_IDENTITIES")
    caller.volume_consumers(names, {})
    require(caller.docker("nonforce-actual-volumes-rm", "volume", "rm", *names) == ("\n".join(names) + "\n").encode(), "EXACT_NONFORCE_ACTUAL_VOLUMES_RM")
    require(caller.source_binding() == setup_receipt["binding"], "SOURCE_HELD_AFTER_ORDINARY_CLOSE")
    return caller.receipt("CLOSE", {"status": "MS2_ORDINARY_CLOSE_COMPLETE_INDEPENDENT_ZERO_PENDING", "setup_sha256": args.setup_sha256,
                                   "normal_sha256": args.normal_sha256, "same_original_terminal_zero": stopped,
                                   "exact_removed": {"containers": cids, "network": network_id, "volumes": names}, "zero": "NOT RUN"})


def zero(caller, args):
    _, _ = inputs(caller, args, normal=True)
    previous = pinned(caller.run_dir / "CLOSE.json", args.close_sha256)
    require(previous["status"] == "MS2_ORDINARY_CLOSE_COMPLETE_INDEPENDENT_ZERO_PENDING" and previous["run_id"] == caller.run_id
            and previous["commit"] == caller.commit and previous["host_sha256"] == sha(Path(__file__))
            and previous["setup_sha256"] == args.setup_sha256 and previous["normal_sha256"] == args.normal_sha256, "ACTUAL_COMPLETED_ORDINARY_CLOSE_ONLY")
    streams(caller.run_dir / "host-close", previous["receipts"])
    queries = caller.queries({"container": [], "network": [], "volume": []})
    require(len(queries) == 6, "SIX_FRESH_SINGLE_LABEL_ZERO_QUERIES")
    return caller.receipt("ZERO", {"status": "MS2_INDEPENDENT_SIX_QUERY_ZERO", "setup_sha256": args.setup_sha256,
                                  "normal_sha256": args.normal_sha256, "close_sha256": args.close_sha256, "queries": queries,
                                  "retirement_only": True, "business_browser_release": "NOT ACCEPTED BY RESOURCE_ZERO"})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("setup", "normal", "close", "zero"))
    parser.add_argument("--repo", required=True); parser.add_argument("--source", required=True)
    parser.add_argument("--run-dir", required=True); parser.add_argument("--git"); parser.add_argument("--docker")
    parser.add_argument("--setup-sha256"); parser.add_argument("--normal-sha256"); parser.add_argument("--close-sha256")
    args = parser.parse_args()
    require(sys.version_info >= (3, 12), "PYTHON_312_REQUIRED_FOR_NATIVE_FILE_IDENTITY")
    require(re.fullmatch(r"[a-f0-9]{40}", args.source) and Path(args.repo).is_absolute() and Path(args.run_dir).is_absolute(), "ABSOLUTE_REPO_RUN_AND_ACTUAL_COMMIT_REQUIRED")
    repo_input = Path(args.repo); run_input = Path(args.run_dir)
    for directory in (repo_input, run_input.parent):
        for ancestor in (directory, *directory.parents):
            require(ancestor.is_dir() and not ancestor.is_symlink() and not getattr(ancestor.lstat(), "st_file_attributes", 0) & 0x400, "REGULAR_NONREPARSE_PATH_ANCESTORS_REQUIRED")
    if os.path.lexists(run_input):
        require(run_input.is_dir() and not run_input.is_symlink() and not getattr(run_input.lstat(), "st_file_attributes", 0) & 0x400, "ORIGINAL_NONREPARSE_RUN_PATH")
    repo = repo_input.resolve(strict=True); run = run_input.resolve(strict=False)
    args.repo = str(repo); args.run_dir = str(run)
    require(not run.is_relative_to(repo) and not repo.is_relative_to(run), "RUN_MUST_BE_OUTSIDE_SOURCE")
    require((args.setup_sha256 is not None) == (args.phase != "setup")
            and (args.normal_sha256 is not None) == (args.phase in ("close", "zero"))
            and (args.close_sha256 is not None) == (args.phase == "zero"), "EXPLICIT_PHASE_RECEIPT_INPUTS")
    owned = False; caller = None
    try:
        if args.phase == "setup":
            require(not os.path.lexists(run), "NEW_RUN_ONLY_NO_REPLAY")
            parent = run.parent; require(parent.is_dir() and not parent.is_symlink() and not getattr(parent.lstat(), "st_file_attributes", 0) & 0x400, "EXISTING_REGULAR_RUN_PARENT")
            run.mkdir(mode=0o700); run_id = time.strftime("%Y%m%d%H%M%S", time.gmtime()) + secrets.token_hex(3)
            context = {"schema": 1, "run_id": run_id, "repo": str(repo), "source": args.source}
        else:
            require(run.is_dir() and not run.is_symlink() and not getattr(run.lstat(), "st_file_attributes", 0) & 0x400, "ORIGINAL_REGULAR_RUN_REQUIRED")
            context = json_read(run / "HOST-INPUT.json"); run_id = context["run_id"]
            require(context["repo"] == str(repo) and context["source"] == args.source, "ORIGINAL_RUN_INPUTS")
        require(re.fullmatch(r"[0-9]{14}[a-f0-9]{6}", run_id), "ACTUAL_RUN_ID_REQUIRED")
        caller = Caller(args, run_id); require(not os.path.lexists(caller.output), "PHASE_ALREADY_STARTED_NO_REPLAY")
        caller.output.mkdir(mode=0o700); owned = True
        if args.phase == "setup": caller.private_acl(); new(run / "HOST-INPUT.json", encoded(context))
        result = {"setup": setup, "normal": normal, "close": close, "zero": zero}[args.phase]
        path = result(caller) if args.phase == "setup" else result(caller, args)
        print(json.dumps({"status": json_read(path)["status"], "receipt": str(path), **digest(path)}, ensure_ascii=False)); return 0
    except BaseException as error:
        if owned:
            failure = run / (args.phase.upper() + "-FAILURE.json")
            if not failure.exists():
                new(failure, encoded({"status": "MS2_INCOMPLETE_SCENE_RETAINED", "phase": args.phase, "run_id": caller.run_id,
                                      "source": args.source, "error_class": type(error).__name__, "reason": str(error) if isinstance(error, Blocked) else "HOST_FAILURE",
                                      "sequence": caller.sequence, "retry_restart_force_or_cleanup": "NOT ATTEMPTED", "Task2_complete": False}))
        print('{"status":"MS2_INCOMPLETE_SCENE_RETAINED"}'); return 2


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        print('{"status":"MS2_BLOCKED_BEFORE_PHASE"}'); sys.exit(2)
