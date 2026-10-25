"""Source only: fixed CU_LICENCE caller; no import-time native operations."""
from __future__ import annotations
import argparse
import csv
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

ORG = Path("H:/天俪商城/artifacts/project-organization-20261023")
PACKET = ORG / "licence-normal-host-source-r6"
SOURCE = Path("H:/天俪商城/worktrees/ms4-agent-licence-private-normal")
VAL = Path("H:/天俪商城/worktrees/ms4-agent-licence-normal-check-r3")
RUN_ROOT = ORG / "licence-normal-runtime-r6"
COMMIT = "864dda32fba62ab12944a01369d816a20be5aec2"
PHP_IMAGE = "sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a"
GIT = "C:/Program Files/Git/cmd/git.exe"
DOCKER = "C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe"
HARNESS = "/workspace/help/audit-regressions/isolated/lib/harness.php"
ENTRY = "/workspace/help/audit-regressions/isolated/ms4-agent-licence-normal.php"
PRIVATE = "/run/audit-customer-licence"
VOLUMES = ["audit-vendor", "mysql-data", "redis-data", "app-runtime", "runner-runtime",
           "worker-runtime", "chat-runtime", "backup-output", "bootstrap-secrets",
           "redis-secrets", "app-secrets", "fixture-secrets", "customer-licence-private"]
SERVICES = {"audit-bootstrap", "audit-mysql", "audit-redis", "audit-app", "audit-runner"}
# Utility residence only. No autoload, business request, retry, restart or forced stop.
KEEPER = "pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{exit(0);});while(true){sleep(60);}"
NET_FORMAT = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}}}'
VOL_FORMAT = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
# Original collect fields plus narrowly needed actual user/state; never Config.Env.
CID_FORMAT = '{"id":{{json .Id}},"image_id":{{json .Image}},"mounts":{{json .Mounts}},"labels":{{json .Config.Labels}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"user":{{json .Config.User}},"entrypoint":{{json .Config.Entrypoint}},"command":{{json .Config.Cmd}},"working_dir":{{json .Config.WorkingDir}},"restart_policy":{{json .HostConfig.RestartPolicy.Name}},"state":{"Running":{{json .State.Running}},"Status":{{json .State.Status}},"ExitCode":{{json .State.ExitCode}},"Pid":{{json .State.Pid}},"StartedAt":{{json .State.StartedAt}}}}'

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
    temporary = path.with_name(path.name + ".licence-host-temporary")
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

class Caller:
    def __init__(self, run_id):
        self.run_id = run_id
        self.project = "tianli-audit-" + run_id
        self.run_dir = RUN_ROOT / run_id
        self.context = None
        self.engine = None
        self.images = {}
        self.sequence = 0
        self.owned = False
        self.env = {k: v for k, v in os.environ.items() if k.upper() in {
            "PATH", "PATHEXT", "SYSTEMROOT", "WINDIR", "COMSPEC", "USERPROFILE",
            "HOMEDRIVE", "HOMEPATH", "TEMP", "TMP", "APPDATA", "LOCALAPPDATA", "PROGRAMDATA",
            "PROGRAMFILES", "PROGRAMFILES(X86)"}}
        self.env.update(AUDIT_RUN_ID=run_id, AUDIT_PROJECT=self.project,
                        AUDIT_RUN_DIR=str(self.run_dir), AUDIT_REPO_DIR=str(VAL),
                        AUDIT_SOURCE_SHA=COMMIT, AUDIT_GUARD_MODE="read-only",
                        AUDIT_ISOLATED="1", AUDIT_LICENCE_NORMAL="1",
                        MSYS_NO_PATHCONV="1", MSYS2_ARG_CONV_EXCL="*")

    def native(self, label, argv):
        """Direct streams avoid pipe deadlocks. The one original handle is waited naturally."""
        self.sequence += 1
        stem = f"{self.sequence:04d}-{label}"
        out = self.run_dir / (stem + ".stdout.bin")
        err = self.run_dir / (stem + ".stderr.bin")
        receipt_path = self.run_dir / (stem + ".json")
        with out.open("xb") as stdout, err.open("xb") as stderr:
            process = subprocess.Popen(argv, cwd=self.run_dir, env=self.env,
                                       stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                                       shell=False, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            new(self.run_dir / (stem + ".running.json"), encoded({
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
        with (self.run_dir / "receipts.jsonl").open("ab") as ledger:
            ledger.write(json.dumps(receipt, ensure_ascii=False, sort_keys=True).encode("utf-8") + b"\n")
            ledger.flush(); os.fsync(ledger.fileno())
        require(code == 0 and not interrupted, "NATIVE_FAILED_SCENE_RETAINED")
        return out.read_bytes()

    def git(self, repository, *args):
        return self.native("git", [GIT, "-C", str(repository), "-c", "safe.directory=" + str(repository), *args])

    def docker(self, label, *args):
        require(self.context is not None, "LOCAL_DOCKER_CONTEXT_NOT_BOUND")
        return self.native(label, [DOCKER, "--context", self.context, *args])

    def compose(self, label, *args):
        return self.docker(label, "compose", "--project-name", self.project,
                           "--file", str(VAL / "help/audit-regressions/isolated/compose.yml"),
                           "--file", str(VAL / "help/audit-regressions/isolated/compose.ms4-agent-licence.yml"), *args)

    def execute(self, cid, *args):
        require(re.fullmatch(r"[a-f0-9]{64}", cid) is not None, "EXACT_CONTAINER_ID_REQUIRED")
        return self.docker("exec", "exec", cid, "php", *args)

    def local_engine(self, expected=None):
        if self.context is None:
            self.context = self.native("context", [DOCKER, "context", "show"]).decode().strip()
            require(re.fullmatch(r"[A-Za-z0-9_.-]+", self.context) is not None, "CONTEXT_NAME_INVALID")
        host = json.loads(self.docker("context-endpoint", "context", "inspect", self.context,
                                    "--format", "{{json .Endpoints.docker.Host}}"))
        require(isinstance(host, str) and host.startswith("npipe://"), "LOCAL_WINDOWS_DOCKER_ONLY")
        self.engine = self.docker("engine", "info", "--format", "{{.ID}}").decode().strip()
        require(bool(self.engine) and (expected is None or self.engine == expected), "ENGINE_IDENTITY_CHANGED")

    def source_binding(self, pins, own_lock=False):
        trees = []
        gitdirs = []
        for repository in (SOURCE, VAL):
            require(repository.is_dir() and not repository.is_symlink()
                    and not getattr(repository.lstat(), "st_file_attributes", 0) & 0x400, "FIXED_WORKTREE_MISSING")
            head = self.git(repository, "rev-parse", "HEAD").decode().strip()
            require(head == COMMIT, "CHECKPOINT_HEAD_CHANGED")
            gitdirs.append(self.git(repository, "rev-parse", "--absolute-git-dir").decode().strip())
            status = self.git(repository, "status", "--porcelain=v1", "-z", "--untracked-files=all")
            require(status in (b"", b"?? crmeb/public/install.lock\0") if repository == VAL and own_lock else status == b"",
                    "WORKTREE_NOT_CLEAN")
            rows = self.git(repository, "ls-tree", "-r", "-z", "--full-tree", "HEAD").split(b"\0")
            entries = []
            aggregate = hashlib.sha256()
            for row in filter(None, rows):
                meta, raw_name = row.split(b"\t", 1); mode, kind, blob = meta.split()
                name = raw_name.decode("utf-8")
                require(mode in (b"100644", b"100755") and kind == b"blob" and ".." not in Path(name).parts,
                        "FIXED_REGULAR_GIT_TREE_REQUIRED")
                path = repository / name; before = regular(path)
                sha1 = hashlib.sha1(b"blob " + str(before.st_size).encode() + b"\0")
                sha256 = hashlib.sha256()
                with path.open("rb") as stream:
                    opened = os.fstat(stream.fileno())
                    require(cross_api_identity(opened) == cross_api_identity(before), "OPEN_FILE_CHANGED")
                    for part in iter(lambda: stream.read(1024 * 1024), b""):
                        sha1.update(part); sha256.update(part)
                    finished = os.fstat(stream.fileno()); after = regular(path)
                    require(file_identity(finished) == file_identity(opened)
                            and file_identity(after) == file_identity(before), "TRACKED_BYTES_CHANGED")
                    require(cross_api_identity(finished) == cross_api_identity(after)
                            == cross_api_identity(opened) == cross_api_identity(before), "PATH_HANDLE_IDENTITY_CHANGED")
                require(sha1.hexdigest().encode() == blob, "RAW_BYTES_DO_NOT_MATCH_COMMITTED_BLOB")
                item = [name, blob.decode(), sha256.hexdigest(), before.st_size]
                entries.append(item); aggregate.update(json.dumps(item, ensure_ascii=False).encode() + b"\n")
            require(len({e[0].casefold() for e in entries}) == len(entries), "CASE_COLLIDING_TREE")
            selected = {e[0]: {"blob": e[1], "sha256": e[2], "bytes": e[3]} for e in entries if e[0] in pins["changed23"]}
            require(selected == pins["changed23"], "FIXED23_BYTES_CHANGED")
            trees.append({"tracked_files": len(entries), "aggregate_sha256": aggregate.hexdigest(), "selected23": selected})
            if repository == VAL:
                actual_names = set()
                for directory, directories, names in os.walk(VAL, followlinks=False):
                    for name in directories:
                        info = (Path(directory) / name).lstat()
                        require(not stat.S_ISLNK(info.st_mode) and not getattr(info, "st_file_attributes", 0) & 0x400,
                                "VAL_REPARSE_DIRECTORY_REFUSED")
                    for name in names:
                        path = Path(directory) / name
                        relative = path.relative_to(VAL).as_posix()
                        if relative != ".git":
                            regular(path); actual_names.add(relative)
                expected_names = {e[0] for e in entries} | ({"crmeb/public/install.lock"} if own_lock else set())
                require(actual_names == expected_names, "VAL_EXACT_COMMITTED_NAMESPACE_REQUIRED")
            for forbidden in ("crmeb/.env",):
                require(not (repository / forbidden).exists() and not (repository / forbidden).is_symlink(), "SHARED_ENV_REFUSED")
        require(gitdirs[0] != gitdirs[1] and trees[0] == trees[1], "INDEPENDENT_VAL_OR_TREE_MISMATCH")
        lock = VAL / "crmeb/public/install.lock"
        if own_lock:
            regular(lock); require(lock.read_bytes() == self.lock_bytes(), "OWN_LOCK_CHANGED")
        else:
            require(not lock.exists() and not (SOURCE / "crmeb/public/install.lock").exists(), "EXISTING_LOCK_REFUSED")
        return {"commit": COMMIT, "source": str(SOURCE), "val": str(VAL), "gitdirs": gitdirs, **trees[0]}

    def lock_bytes(self):
        return ("# AUDIT-RUN " + self.run_id + "\n").encode("ascii")

    def collect(self, expected_services, created_app=False):
        network_name = self.project + "_isolation"
        network = json.loads(self.docker("network-inspect", "network", "inspect", "--format", NET_FORMAT, network_name))
        labels = {"com.docker.compose.project": self.project, "tianli.audit": "1", "tianli.audit.run": self.run_id}
        require(network["Name"] == network_name and network["Internal"] is True
                and all(network["Labels"].get(k) == v for k, v in labels.items()), "DEDICATED_INTERNAL_NETWORK_REQUIRED")
        ids = self.docker("container-list", "ps", "-aq", "--no-trunc", "--filter",
                          "label=com.docker.compose.project=" + self.project).decode().split()
        containers = [json.loads(self.docker("container-inspect", "inspect", "--format", CID_FORMAT, cid)) for cid in sorted(ids)]
        services = {}
        for item in containers:
            service = item["labels"].get("com.docker.compose.service")
            require(service in expected_services and service not in services
                    and all(item["labels"].get(k) == v for k, v in labels.items()), "ONE_REAL_CID_PER_SERVICE_REQUIRED")
            require(list(item["networks"]) == [network_name] and all(v is None for v in (item["ports"] or {}).values()),
                    "FOREIGN_NETWORK_OR_HOST_PORT")
            require(item["restart_policy"] == "no", "AUTOMATIC_RESTART_FORBIDDEN")
            require(item["state"]["Running"] is True or created_app and service == "audit-app"
                    and item["state"]["Status"] == "created", "UNEXPECTED_CONTAINER_STATE")
            if service in ("audit-app", "audit-bootstrap", "audit-runner"):
                require(item["image_id"] == PHP_IMAGE and item["read_only"] is True and item["privileged"] is False,
                        "ORIGINAL_PHP_CONTAINER_IDENTITY")
                command = ["help/audit-regressions/isolated/lib/harness.php", "__serve"] if service == "audit-app" else ["-r", KEEPER]
                require(item["entrypoint"] == ["php"] and item["command"] == command and item["working_dir"] == "/workspace",
                        "REAL_SUPERVISOR_OR_FIXED_UTILITY_COMMAND")
                self.mounts(item, service)
            else:
                require(item["image_id"] == self.images[service], "DATABASE_CACHE_IMAGE_CHANGED")
                self.data_mounts(item, service)
            services[service] = item
        require(set(services) == set(expected_services), "EXACT_SERVICE_SET_REQUIRED")
        names = sorted(self.docker("volume-list", "volume", "ls", "-q", "--filter",
                                  "label=com.docker.compose.project=" + self.project).decode().split())
        require(names == sorted(self.project + "_" + n for n in VOLUMES), "EXACT13_ACTUAL_NAMED_VOLUMES_REQUIRED")
        volumes = [json.loads(self.docker("volume-inspect", "volume", "inspect", "--format", VOL_FORMAT, n)) for n in names]
        require(all(v["created_at"] and all(v["labels"].get(k) == value for k, value in labels.items()) for v in volumes),
                "NATIVE_VOLUME_IDENTITIES_REQUIRED")
        replace_owned(self.run_dir / "network.json", encoded(network))
        replace_owned(self.run_dir / "containers.jsonl", b"".join(encoded(c).replace(b"\n", b"") + b"\n" for c in containers))
        replace_owned(self.run_dir / "volumes.txt", ("\n".join(names) + "\n").encode())
        replace_owned(self.run_dir / "volumes.jsonl", b"".join(encoded(v).replace(b"\n", b"") + b"\n" for v in volumes))
        return {"network": network, "services": services, "volumes": volumes}

    def data_mounts(self, item, service):
        expected = ({"/var/lib/mysql": ("mysql-data", True), "/run/audit-bootstrap": ("bootstrap-secrets", False)}
                    if service == "audit-mysql" else {"/data": ("redis-data", True), "/run/audit-redis": ("redis-secrets", False)})
        actual = {m["Destination"]: m for m in item["mounts"]}
        require(len(actual) == len(item["mounts"]) and set(actual) == set(expected) and item["privileged"] is False,
                "EXACT_DATABASE_CACHE_MOUNT_SET")
        for destination, (name, rw) in expected.items():
            mount = actual[destination]
            require(mount["Type"] == "volume" and mount["Name"] == self.project + "_" + name and mount["RW"] is rw,
                    "ACTUAL_DATABASE_CACHE_NAMED_VOLUME")

    def mounts(self, item, service):
        expected = {"/workspace": ("bind", str(VAL), False), "/run/audit": ("bind", str(self.run_dir), True),
                    "/workspace/crmeb/vendor": ("volume", "audit-vendor", service == "audit-bootstrap"),
                    PRIVATE: ("volume", "customer-licence-private", service != "audit-runner"),
                    "/run/audit-app": ("volume", "app-secrets", service == "audit-bootstrap")}
        if service == "audit-bootstrap":
            expected.update({"/source": ("bind", str(VAL), False), "/run/audit-bootstrap": ("volume", "bootstrap-secrets", True),
                             "/run/audit-redis": ("volume", "redis-secrets", True), "/run/audit-fixtures": ("volume", "fixture-secrets", True)})
        elif service == "audit-app":
            expected.update({"/workspace/crmeb/runtime": ("volume", "app-runtime", True),
                             "/workspace/crmeb/backup": ("volume", "backup-output", True)})
        else:
            expected.update({"/run/audit-fixtures": ("volume", "fixture-secrets", False),
                             "/workspace/crmeb/runtime": ("volume", "runner-runtime", True),
                             "/run/audit-app-runtime": ("volume", "app-runtime", False),
                             "/run/audit-worker-runtime": ("volume", "worker-runtime", False),
                             "/run/audit-chat-runtime": ("volume", "chat-runtime", False),
                             "/workspace/crmeb/backup": ("volume", "backup-output", True)})
        actual = {m["Destination"]: m for m in item["mounts"]}
        require(len(actual) == len(item["mounts"]) and set(actual) == set(expected), "EXACT_ACTUAL_MOUNT_SET")
        for destination, (kind, source, rw) in expected.items():
            mount = actual[destination]
            require(mount["Type"] == kind and mount["RW"] is rw, "ACTUAL_MOUNT_TYPE_MODE")
            require(canonical_host(mount["Source"]) == canonical_host(source) if kind == "bind"
                    else mount["Name"] == self.project + "_" + source, "ACTUAL_MOUNT_SOURCE")

    def register(self, inventory):
        self.execute(inventory["services"]["audit-bootstrap"]["id"], HARNESS, "__register")
        manifest = json_read(self.run_dir / "manifest.json")
        require(manifest["commit_sha"] == COMMIT and manifest["guard_mode"] == "read-only"
                and manifest["isolation"] == "ISO-FULL" and manifest["network_internal"] is True, "ORIGINAL_MANIFEST_REQUIRED")
        require({s: d["id"] for s, d in manifest["services"].items()} ==
                {s: d["id"] for s, d in inventory["services"].items()}, "NATIVE_MANIFEST_CID_EQUALITY")
        require(manifest["volume_identities"] == {v["name"]: v["created_at"] for v in inventory["volumes"]},
                "NATIVE_MANIFEST13_VOLUME_EQUALITY")
        return manifest

    def strict_after_seed(self, bootstrap):
        # The unchanged legacy seed must complete first. Only this registered own bootstrap has root secrets.
        code = "try { require " + json.dumps(HARNESS) + ";" + r'''
            audit_guard_assert();
            $runId=getenv('AUDIT_RUN_ID');
            audit_expect(is_string($runId)&&$runId!=='','LICENCE_STRICT_RUN_REQUIRED');
            $modeFlags=static function(mixed $raw):array{
                audit_expect(is_string($raw)&&strlen($raw)<=2048,'LICENCE_SQL_MODE_VALUE');
                $flags=$raw===''?[]:explode(',',$raw);
                foreach($flags as $flag)audit_expect(preg_match('/\A[A-Z][A-Z0-9_]*\z/D',$flag)===1,'LICENCE_SQL_MODE_FLAG');
                audit_expect(count($flags)===count(array_unique($flags)),'LICENCE_SQL_MODE_DUPLICATE');
                return $flags;
            };
            $password=file_get_contents('/run/audit-bootstrap/root-password');
            audit_expect(is_string($password)&&$password!=='','LICENCE_EXISTING_ROOT_SECRET_REQUIRED');
            $root=new PDO('mysql:host=audit-mysql;port=3306;dbname=audit_auth;charset=utf8mb4','root',$password,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3,PDO::ATTR_EMULATE_PREPARES=>false]);
            audit_expect($root->query('SELECT DATABASE()')->fetchColumn()==='audit_auth'
                &&$root->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN)===[$runId],
                'LICENCE_OWN_MYSQL_SENTINEL_REQUIRED');
            $before=$root->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();$wanted=$modeFlags($before);
            if(!in_array('STRICT_TRANS_TABLES',$wanted,true))$wanted[]='STRICT_TRANS_TABLES';
            $literal=$root->quote(implode(',',$wanted));
            audit_expect(is_string($literal)&&$root->exec('SET GLOBAL sql_mode = '.$literal)!==false,'LICENCE_GLOBAL_STRICT_SET');
            unset($root,$password);
            // audit_db() constructs a genuinely new App DML PDO; it is not the old root/seed session.
            $probe=audit_db();
            audit_expect($probe->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)===true
                &&in_array($probe->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'LICENCE_STRICT_PROBE_NATIVE');
            audit_expect($probe->query('SELECT DATABASE()')->fetchColumn()==='audit_auth'
                &&$probe->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN)===[$runId],
                'LICENCE_FRESH_DML_SENTINEL_REQUIRED');
            $session=$probe->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $global=$probe->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
            $sessionFlags=$modeFlags($session);$globalFlags=$modeFlags($global);sort($wanted,SORT_STRING);
            sort($sessionFlags,SORT_STRING);sort($globalFlags,SORT_STRING);
            audit_expect($sessionFlags===$wanted&&$globalFlags===$wanted
                &&in_array('STRICT_TRANS_TABLES',$sessionFlags,true),'LICENCE_FRESH_GLOBAL_SESSION_STRICT');
            audit_guard_assert();
            echo json_encode(['schema'=>1,'status'=>'LICENCE_MYSQL_STRICT_AFTER_LEGACY_SEED','run_id'=>$runId,
                'global_before'=>$before,'global_after'=>$global,'fresh_session'=>$session,
                'preserved_flags_plus_strict'=>true,'fresh_dml_connection'=>true,'native_probe'=>true,
                'original_guard_and_sentinel'=>true,'credentials_omitted'=>true],JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            fwrite(STDERR,json_encode(['status'=>'LICENCE_MYSQL_STRICT_UNAVAILABLE','error_class'=>get_class($error)],JSON_THROW_ON_ERROR)."\n");
            exit(1);
        }'''
        result = json.loads(self.execute(bootstrap, "-r", code))
        require(result["schema"] == 1 and result["status"] == "LICENCE_MYSQL_STRICT_AFTER_LEGACY_SEED"
                and result["run_id"] == self.run_id and result["preserved_flags_plus_strict"] is True
                and result["fresh_dml_connection"] is True and result["native_probe"] is True
                and result["original_guard_and_sentinel"] is True and result["credentials_omitted"] is True,
                "ACTUAL_MYSQL_STRICT_BASELINE_REQUIRED")
        original = result["global_before"].split(",") if result["global_before"] else []
        for observed in (result["global_after"], result["fresh_session"]):
            flags = observed.split(",") if observed else []
            require(len(flags) == len(set(flags)) and set(flags) == set(original) | {"STRICT_TRANS_TABLES"},
                    "MYSQL_ORIGINAL_FLAGS_PLUS_STRICT_REQUIRED")
        return result

    def identities(self, inventory):
        code = ("require " + json.dumps(HARNESS) + ";audit_guard_assert();$s=lstat(" + json.dumps(PRIVATE) + ");"
                "echo json_encode(['euid'=>posix_geteuid(),'root_uid'=>$s['uid'],'root_link'=>is_link(" + json.dumps(PRIVATE) + "),"
                "'migrator'=>is_file('/run/audit-bootstrap/migrator.json'),'root_password'=>is_file('/run/audit-bootstrap/root-password')]);")
        results = {}
        for service in ("audit-bootstrap", "audit-app", "audit-runner"):
            result = json.loads(self.execute(inventory["services"][service]["id"], "-r", code))
            require(isinstance(result["euid"], int) and result["root_uid"] == result["euid"] and result["root_link"] is False
                    and result["migrator"] is (service == "audit-bootstrap")
                    and result["root_password"] is (service == "audit-bootstrap"), "ACTUAL_EUID_ROOT_OR_SECRET_MOUNT")
            results[service] = result
        require(len({v["euid"] for v in results.values()}) == 1, "BOOTSTRAP_APP_RUNNER_EUID_MISMATCH")
        return results

def packet(pins_sha):
    require(Path(__file__).resolve() == PACKET / "licence-normal-host.py", "REVIEWED_SCRIPT_PATH_REQUIRED")
    require(re.fullmatch(r"[a-f0-9]{64}", pins_sha) is not None, "REVIEWED_PINS_REQUIRED")
    require(digest(PACKET / "PINS.json")["sha256"] == pins_sha, "REVIEWED_PINS_CHANGED")
    pins = json_read(PACKET / "PINS.json")
    require(pins["commit"] == COMMIT and pins["scope"] == "CU_LICENCE_NORMAL_HOST_SOURCE_ONLY", "SOURCE_PINS_SCOPE")
    for name, expected in pins["packet_files"].items():
        require(Path(name).name == name and digest(PACKET / name) == expected, "REVIEWED_CALLER_BYTES_CHANGED")
    require(len(pins["changed23"]) == 23, "FIXED23_PINS_REQUIRED")
    return pins

def setup(caller, pins, pins_sha):
    require(not caller.run_dir.exists(), "FRESH_RUN_ONLY_NO_RETRY")
    RUN_ROOT.mkdir(mode=0o700, exist_ok=True)
    require(RUN_ROOT.is_dir() and not RUN_ROOT.is_symlink()
            and not getattr(RUN_ROOT.lstat(), "st_file_attributes", 0) & 0x400, "FIXED_PRIVATE_RUN_ROOT")
    caller.run_dir.mkdir(mode=0o700)
    caller.owned = True
    raw = caller.native("current-sid", ["C:/Windows/System32/whoami.exe", "/user", "/fo", "csv", "/nh"]).decode("utf-8", "replace")
    sid = list(csv.reader(io.StringIO(raw)))[0][-1]
    require(re.fullmatch(r"S-[0-9-]+", sid) is not None, "CURRENT_SID_REQUIRED")
    caller.native("private-acl", ["C:/Windows/System32/icacls.exe", str(caller.run_dir), "/inheritance:r",
                                 "/grant:r", "*" + sid + ":(OI)(CI)F", "*S-1-5-18:(OI)(CI)F"])
    binding = caller.source_binding(pins)
    caller.local_engine()
    for resource in ("ps", "network", "volume"):
        args = ["ps", "-aq"] if resource == "ps" else [resource, "ls", "-q"]
        require(not caller.docker("fresh-project", *args, "--filter",
                                  "label=com.docker.compose.project=" + caller.project).strip(), "PROJECT_ALREADY_EXISTS")
    image_specs = {"audit-bootstrap": "tianli/crmeb-php:acceptance",
                   "audit-mysql": "ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45",
                   "audit-redis": "ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1"}
    for service, spec in image_specs.items():
        image = caller.docker("local-image", "image", "inspect", "--format", "{{.Id}}", spec).decode().strip()
        require(re.fullmatch(r"sha256:[a-f0-9]{64}", image) is not None
                and (service != "audit-bootstrap" or image == PHP_IMAGE), "LOCAL_PINNED_IMAGE_REQUIRED")
        caller.images[service] = image
    caller.compose("compose-check", "config", "--quiet")
    bootstrap = caller.compose("bootstrap-resident", "run", "--no-deps", "--detach", "--name",
                               caller.project + "-licence-bootstrap", "audit-bootstrap", "-r", KEEPER).decode().strip()
    require(re.fullmatch(r"[a-f0-9]{64}", bootstrap) is not None, "BOOTSTRAP_ACTUAL_CID")
    caller.execute(bootstrap, HARNESS, "__prepare")
    # This real runner residence also creates all original runner/worker/chat volumes before collection.
    runner = caller.compose("runner-resident", "run", "--no-deps", "--detach", "--name",
                            caller.project + "-licence-runner", "audit-runner", "-r", KEEPER).decode().strip()
    require(re.fullmatch(r"[a-f0-9]{64}", runner) is not None, "RUNNER_ACTUAL_CID")
    caller.compose("database-up", "up", "-d", "--wait", "--wait-timeout", "90", "audit-mysql", "audit-redis")
    first = caller.collect({"audit-bootstrap", "audit-runner", "audit-mysql", "audit-redis"})
    caller.register(first); caller.execute(bootstrap, HARNESS, "__seed")
    strict_mode = caller.strict_after_seed(bootstrap)
    new(VAL / "crmeb/public/install.lock", caller.lock_bytes())
    caller.compose("app-create", "create", "audit-app")
    created = caller.collect(SERVICES, created_app=True); caller.register(created)
    app = created["services"]["audit-app"]["id"]
    caller.docker("app-start-same-cid", "start", app)
    final = caller.collect(SERVICES); manifest = caller.register(final)
    identities = caller.identities(final)
    require(caller.source_binding(pins, own_lock=True) == binding, "SOURCE_BINDING_DRIFT")
    result = {"status": "SETUP_COMPLETE_NOT_BUSINESS_VERIFIED", "commit": COMMIT, "run_id": caller.run_id,
              "context": caller.context, "engine": caller.engine, "images": caller.images, "binding": binding,
              "identities": identities, "mysql_sql_mode": strict_mode, "native": final, "manifest": digest(caller.run_dir / "manifest.json"),
              "pins_sha256": pins_sha, "receipts": digest(caller.run_dir / "receipts.jsonl"),
              "normal": "NOT RUN", "retirement": "PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_ZERO"}
    new(caller.run_dir / "SETUP.json", encoded(result))
    return caller.run_dir / "SETUP.json"

def normal(caller, pins, pins_sha, setup_sha):
    require(caller.run_dir.is_dir() and not (caller.run_dir / "FAILURE.json").exists(), "SETUP_OR_FAILED_RUN_REFUSED")
    require(digest(caller.run_dir / "SETUP.json")["sha256"] == setup_sha, "EXPLICIT_ACTUAL_SETUP_PIN_REQUIRED")
    previous = json_read(caller.run_dir / "SETUP.json")
    require(previous["status"] == "SETUP_COMPLETE_NOT_BUSINESS_VERIFIED" and previous["commit"] == COMMIT
            and previous["run_id"] == caller.run_id and previous["pins_sha256"] == pins_sha, "SETUP_SCOPE_CHANGED")
    caller.owned = True
    require(digest(caller.run_dir / "receipts.jsonl") == previous["receipts"]
            and digest(caller.run_dir / "manifest.json") == previous["manifest"], "SETUP_RECEIPTS_OR_MANIFEST_CHANGED")
    receipts = [json.loads(line) for line in (caller.run_dir / "receipts.jsonl").read_bytes().splitlines()]
    require([r["sequence"] for r in receipts] == list(range(1, len(receipts) + 1)), "ORDERED_RECEIPTS_REQUIRED")
    for receipt in receipts:
        require(receipt["same_handle_wait"] and receipt["natural_exit"] == 0, "NATURAL_SETUP_WAIT_REQUIRED")
        for stream in ("stdout", "stderr"):
            item = receipt[stream]
            require(Path(item["file"]).name == item["file"] and digest(caller.run_dir / item["file"]) ==
                    {"bytes": item["bytes"], "sha256": item["sha256"]}, "COMPLETE_SETUP_STREAM_CHANGED")
    caller.sequence = len(receipts); caller.context = previous["context"]; caller.images = previous["images"]
    new(caller.run_dir / "NORMAL_STARTED.json", encoded({"pins_sha256": pins_sha, "setup_sha256": setup_sha}))
    caller.local_engine(previous["engine"])
    require(caller.source_binding(pins, own_lock=True) == previous["binding"], "NORMAL_SOURCE_BINDING_CHANGED")
    actual = caller.collect(SERVICES)
    require(scene_projection(actual) == scene_projection(previous["native"]), "SETUP_NATIVE_IDENTITIES_CHANGED")
    require(caller.identities(actual) == previous["identities"], "NORMAL_ACTUAL_EUID_CHANGED")
    # No full-tree scan, extra request or host fixture operation between these calls.
    caller.execute(actual["services"]["audit-bootstrap"]["id"], ENTRY, "prepare")
    caller.execute(actual["services"]["audit-runner"]["id"], ENTRY, "verify")
    status = json_read(caller.run_dir / "app-status.json")
    require(status.get("status") == "stop" and status.get("stop_mode") == "term-only"
            and status.get("force_signal_sent") is False and status.get("members") == []
            and status.get("listener_open") is False, "TERM_ONLY_NORMAL_APP_CLOSE_REQUIRED")
    report_path = caller.run_dir / "evidence/ms4-agent-licence-verify.json"
    report = json_read(report_path)
    require(report["source_sha"] == COMMIT and report["normal_app_group_closed"] is True
            and report["acceptance"] == "INCOMPLETE", "REAL_NORMAL_ENTRY_RECEIPT_REQUIRED")
    final = caller.collect(SERVICES)
    require(scene_projection(final) == scene_projection(previous["native"]) and caller.source_binding(pins, own_lock=True) == previous["binding"],
            "FINAL_NATIVE_OR_SOURCE_BINDING_CHANGED")
    new(caller.run_dir / "NORMAL.json", encoded({
        "status": "NORMAL_ENTRY_COMPLETE_RETIREMENT_PENDING", "commit": COMMIT, "run_id": caller.run_id,
        "setup_sha256": setup_sha, "normal_entry": digest(report_path), "app_stop": digest(caller.run_dir / "app-status.json"),
        "receipts": digest(caller.run_dir / "receipts.jsonl"), "acceptance": "INCOMPLETE",
        "retirement": "PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_ZERO",
        "browser_device_external_release": "NOT RUN", "delivery_groups": "19 STILL OPEN"}))
    return caller.run_dir / "NORMAL.json"

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("setup", "normal"))
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--pins-sha256", required=True)
    parser.add_argument("--setup-sha256")
    args = parser.parse_args()
    require(os.name == "nt" and re.fullmatch(r"[0-9]{14}[a-f0-9]{6}", args.run_id) is not None, "FIXED_WINDOWS_RUN_ID_REQUIRED")
    require((args.setup_sha256 is None) == (args.phase == "setup"), "EXPLICIT_TWO_PHASE_ARGUMENTS")
    pins = packet(args.pins_sha256); caller = Caller(args.run_id)
    try:
        result = setup(caller, pins, args.pins_sha256) if args.phase == "setup" else normal(caller, pins, args.pins_sha256, args.setup_sha256)
        require(packet(args.pins_sha256) == pins, "CALLER_SOURCE_CHANGED_DURING_PHASE")
        print(json.dumps({"status": json_read(result)["status"], "receipt": str(result), **digest(result)}, ensure_ascii=False))
    except BaseException as error:
        if caller.owned and caller.run_dir.is_dir():
            failure = caller.run_dir / "FAILURE.json"
            if not failure.exists():
                new(failure, encoded({"status": "BLOCKED_SCENE_RETAINED", "phase": args.phase,
                                     "error_class": type(error).__name__, "sequence": caller.sequence,
                                     "reason_code": str(error) if isinstance(error, Blocked) and re.fullmatch(r"[A-Z0-9_]+", str(error)) else "NATIVE_OR_HOST_EXCEPTION",
                                     "cleanup": "NOT ATTEMPTED", "retry": "NOT AUTHORIZED"}))
        print(json.dumps({"status": "BLOCKED_SCENE_RETAINED", "receipt": str(caller.run_dir / "FAILURE.json")}, ensure_ascii=False))
        return 2
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except Blocked:
        print('{"status":"BLOCKED_BEFORE_RUNTIME"}')
        sys.exit(2)
