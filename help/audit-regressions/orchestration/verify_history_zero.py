"""Read-only zero for the existing current-history reader close protocol.

Derived from the frozen legacy independent-zero r2 primitives. The old r2 is
unchanged. This explicit profile consumes the Host's actual records, never an
invented legacy RESULT/PUBLIC-INPUTS or poll status. Business interpretation of
the phase originals remains a separate non-author readback.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys


PROFILE = "current-history-reader-v1"
HELPERS_PIN = {"bytes": 32130, "sha256": "6bb62892cdab1947dce13ab0f2567cab52e1fa079ebb6ebd7c7edb1dbeea4591"}
HOST_PINS = (
    {"bytes": 212260, "sha256": "bd7801d51d1121de7bcca983110051c3936e432da12d79519e995f7330b04fdf"},
    {"bytes": 219230, "sha256": "764f9357013fe0c8372d58433310ac33de002f6cad30b47b223f14cf20202bb4"},
)
DOCKER = "C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe"
LEGACY_SHA = "477732e3e03ed4ba1dbb6b44a94dbba75f7e1978"
VOLUMES = ("app-secrets", "bootstrap-secrets", "fixture-secrets", "mysql-data", "redis-data", "redis-secrets")
MUTABLE = {"manifest.json", "network.json", "containers.jsonl", "volumes.txt", "volumes.jsonl"}
PHASES = ("before-legacy-files", "legacy-files-complete", "legacy-producer-complete", "before-current-reader")
MADE_KEYS = {"schema", "run_id", "source_sha", "freeze_sha256", "argv", "pid", "started_at"}
TERM_KEYS = MADE_KEYS | {"finished_at", "native_exit", "wait_exit", "stdout", "stderr"}
CLOSE_KEYS = {"schema", "scope", "run_id", "source_sha", "freeze_sha256", "reader_retired", "normal_base_stop", "original_containers", "original_volumes", "original_networks", "fresh_zero_queries", "full_acceptance"}
BEFORE_KEYS = {"schema", "run_id", "source_sha", "freeze_sha256", "base", "volumes", "physical_volumes", "network", "historical_cids"}
RETIRE_KEYS = {"schema", "scope", "run_id", "source_sha", "freeze_sha256", "role", "proof_name", "container_id", "created", "terminal", "binding", "result", "normal_remove", "resource_after", "prerequisites"}
GLOBAL_FORMAT = '{"id":{{json .Id}},"name":{{json .Name}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}},"network_mode":{{json .HostConfig.NetworkMode}}'
PROOFS = ("prepare", "initializer", "register-initializer", "legacy-files-bootstrap", "register-legacy-files-bootstrap", "legacy-history-producer", "register-legacy-history-producer", "driver", "register-driver", "current-structure-upgrade", "legacy-current-reader", "register-legacy-current-reader")


def require(value, code):
    if not value:
        raise RuntimeError(code)


def pin(raw):
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def decode(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, "UNIQUE_JSON_KEYS")
            result[key] = value
        return result
    def invalid(value):
        raise RuntimeError("FINITE_INTEGER_JSON_ONLY")
    return json.loads(raw, object_pairs_hook=pairs, parse_float=invalid, parse_constant=invalid)


def keys(value, expected, code):
    require(type(value) is dict and set(value) == set(expected), code)


def sha(value):
    require(type(value) is str and re.fullmatch("[a-f0-9]{64}", value) is not None, "EXPLICIT_SHA256")
    return value


def absolute(value):
    require(type(value) is str and value and "\x00" not in value, "ABSOLUTE_PATH_TEXT")
    path = Path(value)
    require(path.is_absolute() and ".." not in path.parts and value in (str(path), path.as_posix()), "CANONICAL_PATH_TEXT")
    return path


def relative(value):
    require(type(value) is str and value and "\\" not in value and "\x00" not in value, "RELATIVE_PUBLIC_PATH_TEXT")
    path = Path(value)
    require(not path.is_absolute() and all(p not in ("", ".", "..") for p in value.split("/")), "OWNED_RELATIVE_PATH")
    return value


def private_name(value):
    if value == "credential-check.json":
        return True
    parts = Path(value).parts
    return any(p in (".git", "runtime", "install.lock", ".env", "private.env", "legacy.env")
               or re.search(r"(?:^|[-_.])(?:private|secrets?|credentials|passwords?|tokens?)(?:$|[-_.])", p, re.I)
               for p in parts)


def load_helpers():
    path = Path(__file__).with_name("retire_failed_history.py")
    raw = path.read_bytes()
    require(pin(raw) == HELPERS_PIN, "FIXED_SIBLING_PURE_HELPERS")
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location("current_history_zero_helpers", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Inputs:
    def __init__(self, helpers, root):
        self.h = helpers
        self.root = root
        self.files = {}

    def seal(self, path):
        path = absolute(path.as_posix())
        row = self.h.filepin(path)
        name = path.as_posix()
        if name in self.files:
            require(self.files[name] == row, "PUBLIC_INPUT_FULL_WINDOW_HELD")
        self.files[name] = row
        return {"path": name, **{k: row[k] for k in ("bytes", "sha256")}}

    def read(self, path):
        descriptor = self.seal(path)
        require(descriptor["bytes"] <= 67108864, "FINITE_COMPLETE_PUBLIC_INPUT")
        raw = path.read_bytes()
        require(pin(raw) == {k: descriptor[k] for k in ("bytes", "sha256")}, "FULL_PUBLIC_BYTES")
        self.seal(path)
        return raw

    def run(self, name):
        relative(name)
        require(not private_name(name), "NEVER_OPEN_PRIVATE_RUN_CONTENT")
        return self.read(self.root / name)

    def obj(self, name):
        return decode(self.run(name))

    def pointer(self, row):
        keys(row, ("path", "bytes", "sha256"), "EXACT_THREE_KEY_POINTER")
        require(type(row["bytes"]) is int and row["bytes"] >= 0, "POINTER_NATIVE_SIZE")
        sha(row["sha256"])
        name = row["path"]
        require(type(name) is str and name.startswith("/run/audit/"), "ORIGINAL_RUN_POINTER_ONLY")
        name = relative(name[len("/run/audit/"):])
        raw = self.run(name)
        require(pin(raw) == {k: row[k] for k in ("bytes", "sha256")}, "FULL_ORIGINAL_POINTER_BYTES")
        return name, raw

    def held(self):
        for path, row in self.files.items():
            require(self.h.filepin(Path(path)) == row, "EVERY_PUBLIC_INPUT_BYTES_NATIVE9_HELD")


def history_native(inputs, stem, context, zero=False):
    """Parse the actual Host 7/12 protocol; no legacy poll field exists here."""
    require(re.fullmatch(r"process-(?:native-[0-9]{4,}|compose-[a-z0-9-]+)", stem) is not None, "FIXED_HOST_NATIVE_STEM")
    base = "evidence/" + stem
    made = inputs.obj(base + ".created.json")
    terminal = inputs.obj(base + ".terminal.json")
    keys(made, MADE_KEYS, "HOST_NATIVE_CREATED_EXACT7")
    keys(terminal, TERM_KEYS, "HOST_NATIVE_TERMINAL_EXACT12")
    require(type(made["schema"]) is int and made["schema"] == 1 and type(made["pid"]) is int and made["pid"] > 0, "HOST_REAL_NATIVE_PID")
    require(all(made[k] == context[k] for k in ("run_id", "source_sha", "freeze_sha256")), "HOST_NATIVE_SAME_RUN_SOURCE_FREEZE")
    require(all(type(terminal[k]) is type(v) and terminal[k] == v for k, v in made.items()), "SAME_ORIGINAL_PROCESS_TERMINAL")
    require(type(made["argv"]) is list and len(made["argv"]) >= 2 and all(type(v) is str for v in made["argv"])
            and made["argv"][0] == "docker", "ORIGINAL_HOST_SHORT_DOCKER_ARGV")
    require(type(made["started_at"]) is str and type(terminal["finished_at"]) is str
            and terminal["finished_at"] >= made["started_at"], "NATIVE_REAL_TIME_ORDER")
    started = datetime.fromisoformat(made["started_at"])
    finished = datetime.fromisoformat(terminal["finished_at"])
    require(started.utcoffset() == finished.utcoffset() == timedelta(0) and finished >= started, "NATIVE_REAL_UTC_TIMESTAMPS")
    require(type(terminal["native_exit"]) is int and type(terminal["wait_exit"]) is int
            and terminal["native_exit"] == terminal["wait_exit"], "REAL_NATIVE_WAIT_EXIT_NOT_SYNTHETIC_POLL")
    streams = {}
    for stream in ("stdout", "stderr"):
        require(terminal[stream]["path"] == "/run/audit/" + base + "." + stream, "EXACT_ORIGINAL_STREAM_PATH")
        _, streams[stream] = inputs.pointer(terminal[stream])
    if zero:
        require(terminal["native_exit"] == 0 and streams["stderr"] == b"", "ORIGINAL_CLOSE_NATIVE_ZERO_EMPTY_STDERR")
    return {"made": made, "terminal": terminal, **streams}


def identities(context, frozen):
    require(type(context.get("schema")) is int and context["schema"] == 1
            and re.fullmatch(r"[0-9]{14}[a-f0-9]{6}", context.get("run_id", "")) is not None
            and context["project"] == "tianli-audit-" + context["run_id"], "ORIGINAL_CONTEXT_IDENTITY")
    require(re.fullmatch("[a-f0-9]{40}", context["source_sha"]) is not None, "APPLICATION_SOURCE_COMMIT")
    ids = context["initial_service_ids"]
    require(set(ids) == {"audit-mysql", "audit-redis"} and len(set(ids.values())) == 2
            and all(re.fullmatch("[a-f0-9]{64}", cid) for cid in ids.values()), "TWO_ORIGINAL_BASE_CIDS")
    network = context["initial_network"]
    keys(network, ("Id", "Name", "Internal", "Labels"), "ORIGINAL_NETWORK_KEYS")
    require(network["Id"] == context["network_id"] and re.fullmatch("[a-f0-9]{64}", network["Id"])
            and network["Name"] == context["project"] + "_isolation" and network["Internal"] is True, "ORIGINAL_INTERNAL_NETWORK")
    volumes = context["initial_volumes"]
    require(type(volumes) is list and [v["name"] for v in volumes] == sorted(context["project"] + "_" + s for s in VOLUMES), "EXACT_SIX_ORIGINAL_VOLUMES")
    for volume in volumes:
        keys(volume, ("name", "created_at", "labels"), "ORIGINAL_VOLUME_NATIVE_KEYS")
        require(type(volume["created_at"]) is str and volume["created_at"], "ORIGINAL_VOLUME_CREATION_TIME")
    for value in (network, *volumes):
        labels = value["Labels"] if value is network else value["labels"]
        require(labels.get("tianli.audit") == "1" and labels.get("tianli.audit.run") == context["run_id"]
                and labels.get("com.docker.compose.project") == context["project"], "ORIGINAL_RESOURCE_LABELS")
    old = frozen["nonempty_history"]
    keys(old, ("schema", "legacy_source_sha", "requested_legacy_repo_source", "legacy_source_files", "legacy_source_directories", "control_files"), "PRODUCER_SOURCE_AUTHORITY_EXACT6")
    current = frozen["current_reader"]
    keys(current, ("schema", "source_sha", "source_files", "source_directories", "control_files"), "CURRENT_READER_AUTHORITY_EXACT5")
    require(old["legacy_source_sha"] == LEGACY_SHA and old["requested_legacy_repo_source"] == context["legacy_repo_dir"]
            and old["schema"] == current["schema"] == 1 and current["source_sha"] == context["source_sha"], "DUAL_ORIGINAL_SOURCE")
    require(len(frozen["artifact_files"]) == 14 and len(old["control_files"]) == 11 and len(current["control_files"]) == 12, "EXACT_ARTIFACT14_PRODUCER11_READER12")
    reader = "/run/nonempty/legacy-current-reader.php"
    require([v for v in current["control_files"] if v["path"] != reader] == old["control_files"]
            and len({v["path"] for v in current["control_files"]}) == 12, "ORIGINAL11_PLUS_ONE_READER_CONTROL")
    require(frozen["status"] == "CLEAN_SOURCE_FROZEN_ONLY" and frozen["source_sha"] == context["source_sha"]
            and frozen["requested_repo_source"] == context["repo_dir"], "ORIGINAL_FREEZE_CONTEXT")


def phase_archives(inputs, context):
    archived = set()
    for phase in PHASES:
        folder = "evidence/nonempty-history-phases/" + phase
        record = inputs.obj(folder + "/INDEX.json")
        keys(record, ("schema", "phase", "run_id", "source_sha", "legacy_source_sha", "files", "business_acceptance"), "PHASE_INDEX_EXACT7")
        require(record["schema"] == 1 and record["phase"] == phase and record["legacy_source_sha"] == LEGACY_SHA
                and record["business_acceptance"] == "NOT RUN"
                and all(record[k] == context[k] for k in ("run_id", "source_sha")), "ORIGINAL_PHASE_INDEX_IDENTITY")
        names = []
        for row in record["files"]:
            keys(row, ("path", "bytes", "sha256", "original_stat"), "PHASE_ORIGINAL_DESCRIPTOR")
            name = relative(row["path"])
            require(not private_name(name), "PHASE_PUBLIC_LEAVES_ONLY")
            keys(row["original_stat"], inputs.h.STAT, "PHASE_ORIGINAL_NATIVE9")
            require(all(type(v) is int for v in row["original_stat"].values()) and row["original_stat"]["st_size"] == row["bytes"], "PHASE_REAL_NATIVE_SIZE")
            raw = inputs.run(folder + "/" + name)
            require(pin(raw) == {k: row[k] for k in ("bytes", "sha256")}, "EVERY_ARCHIVED_FILE_FULL_BYTES")
            if name not in MUTABLE:
                require(inputs.run(name) == raw, "IMMUTABLE_CONTEXT_FREEZE_EVIDENCE_EQUALS_ARCHIVE")
            names.append(name)
            archived.add(folder + "/" + name)
        require(len(names) == len(set(names)), "UNIQUE_PHASE_FILES")
        actual_files, _ = inputs.h.namespace(inputs.root / folder)
        require(actual_files == sorted(["INDEX.json", *names]), "PHASE_COMPLETE_FILE_NAMESPACE")
        archived.add(folder + "/INDEX.json")
    return archived


def retired_chain(inputs, context, closed):
    specs = {
        "legacy-history-producer": ("audit-legacy-history-producer", "audit-legacy-history-producer-source-binding.json", "legacy-history-producer-result.json", {}),
        "driver": ("audit-ms4-migration", "ms4-migration-source-binding.json", "ms4-migration-normal.json", {"producer_retired": "legacy-history-producer"}),
        "current-structure-upgrade": ("audit-bootstrap", "../preparation-source-binding.json", "current-structure-upgrade-result.json", {"producer_retired": "legacy-history-producer", "driver_retired": "driver"}),
        "legacy-current-reader": ("audit-legacy-current-reader", "audit-legacy-current-reader-source-binding.json", "legacy-current-reader-result.json", {"driver_retired": "driver", "upgrade_retired": "current-structure-upgrade"}),
    }
    for proof, (role, binding, result, prerequisites) in specs.items():
        name = "evidence/" + proof + "-retired.json"
        record = inputs.obj(name)
        keys(record, RETIRE_KEYS, "ORIGINAL_RETIRE_RECEIPT_EXACT15")
        require(type(record["schema"]) is int and record["schema"] == 1
                and record["scope"] == "NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED"
                and record["proof_name"] == proof and record["role"] == role
                and all(record[k] == context[k] for k in ("run_id", "source_sha", "freeze_sha256")), "ORIGINAL_NORMAL_RETIRE_CHAIN")
        expected = {"created": "evidence/" + proof + "-created.json", "terminal": "evidence/" + proof + "-terminal.json",
                    "binding": "preparation-source-binding.json" if binding.startswith("../") else "evidence/" + binding,
                    "result": "evidence/" + result, "resource_after": "evidence/" + proof + "-retired-resources.json"}
        values = {}
        for key, leaf in expected.items():
            require(record[key] == {"path": "/run/audit/" + leaf, **pin(inputs.run(leaf))}, "RETIRE_EXACT_ORIGINAL_PUBLIC_POINTER")
            values[key] = decode(inputs.pointer(record[key])[1])
        made, terminal = values["created"], values["terminal"]
        require(record["container_id"] == made["id"] == terminal["id"]
                and terminal["status"] == "exited" and type(terminal["exit_code"]) is int and terminal["exit_code"] == 0
                and type(terminal["pid"]) is int and terminal["pid"] == 0 and terminal["restart_count"] == 0
                and made["labels"].get("tianli.audit.run") == context["run_id"], "RETIRED_ORIGINAL_ACTOR_NATIVE_ZERO")
        require(set(record["prerequisites"]) == set(prerequisites), "EXACT_RETIRE_PREREQUISITES")
        for key, prior in prerequisites.items():
            leaf = "evidence/" + prior + "-retired.json"
            require(record["prerequisites"][key] == {"path": "/run/audit/" + leaf, **pin(inputs.run(leaf))}, "RETIRE_COMPLETE_PRIOR_PIN")
            inputs.pointer(record["prerequisites"][key])
        removal_name, removal_raw = inputs.pointer(record["normal_remove"])
        require(re.fullmatch(r"evidence/native-[0-9]{4,}\.json", removal_name), "RETIRE_NATIVE_SUMMARY_PATH")
        removal = decode(removal_raw)
        require(removal["stage"] == proof + "-exact-nonforce-remove" and removal["native_exit"] == 0
                and removal["stdout_bytes"] == 65 and removal["stdout_sha256"] == pin((made["id"] + "\n").encode())["sha256"]
                and removal["stderr_bytes"] == 0 and removal["stderr_sha256"] == pin(b"")["sha256"], "ACTUAL_ORIGINAL_SINGLE_NORMAL_REMOVE")
        if proof == "legacy-current-reader":
            require(closed["reader_retired"] == {"path": "/run/audit/" + name, **pin(inputs.run(name))}, "FINAL_CLOSE_ORIGINAL_READER_RECEIPT")


def expected_mutations(context):
    cids = list(context["initial_service_ids"].values())
    return ([["docker", "stop", "--timeout=-1", cid] for cid in cids]
            + [["docker", "rm", cid] for cid in cids]
            + [["docker", "volume", "rm", v["name"]] for v in context["initial_volumes"]]
            + [["docker", "network", "rm", context["network_id"]]])


def close_records(inputs, context, before):
    summaries = sorted((inputs.root / "evidence").glob("native-*.json"), key=lambda p: int(p.stem[7:]))
    groups = []
    compose_count = 0
    for path in sorted((inputs.root / "evidence").glob("process-compose-*.created.json")):
        history_native(inputs, path.name[:-len(".created.json")], context)
        compose_count += 1
    historical = set(context["initial_service_ids"].values())
    for path in sorted((inputs.root / "evidence").glob("*-created.json")):
        obj = inputs.obj("evidence/" + path.name)
        if "id" in obj:
            require(type(obj["id"]) is str and re.fullmatch("[a-f0-9]{64}", obj["id"])
                    and obj["labels"].get("tianli.audit.run") == context["run_id"]
                    and obj["labels"].get("com.docker.compose.project") == context["project"], "EVERY_HISTORICAL_CREATED_CID")
            historical.add(obj["id"])
    require(before["historical_cids"] == sorted(historical), "COMPLETE_ORIGINAL_HISTORICAL_CID_SET")
    aliases = set(historical)
    for number, path in enumerate(summaries, 1):
        require(path.name == "native-%04d.json" % number, "COMPLETE_NATIVE_SUMMARY_SEQUENCE")
        summary = inputs.obj("evidence/" + path.name)
        keys(summary, ("stage", "native_exit", "stdout_bytes", "stdout_sha256", "stderr_bytes", "stderr_sha256", "private_environment_logs_omitted"), "HOST_NATIVE_SUMMARY_EXACT7")
        group = history_native(inputs, "process-native-%04d" % number, context)
        require(summary["native_exit"] == group["terminal"]["native_exit"] and summary["private_environment_logs_omitted"] is True, "ACTUAL_NATIVE_SUMMARY_JOIN")
        for stream in ("stdout", "stderr"):
            require({"bytes": summary[stream + "_bytes"], "sha256": summary[stream + "_sha256"]} == pin(group[stream]), "SUMMARY_COMPLETE_STREAMS")
        group["stage"] = summary["stage"]
        groups.append(group)
        if summary["stage"] == "history-global-alias-facts":
            for line in group["stdout"].splitlines():
                row = decode(line)
                if row["id"] in historical:
                    aliases.add(row["name"].lstrip("/"))
    starts = [i for i, g in enumerate(groups) if g["stage"] == "history-final-global-before"]
    require(len(starts) == 1, "ONE_ACTUAL_ORIGINAL_FINAL_CLOSE")
    close = groups[starts[0]:]
    mutations = []
    for group in close:
        require(group["terminal"]["native_exit"] == 0 and group["stderr"] == b"", "ALL_ORIGINAL_CLOSE_NATIVE_ZERO")
        argv = group["made"]["argv"]
        if argv[1] in ("stop", "rm") or argv[1:3] in (["volume", "rm"], ["network", "rm"]):
            mutations.append(argv)
            require(mutations == expected_mutations(context)[:len(mutations)], "EXACT_ELEVEN_ORDINARY_MUTATIONS_IN_ORDER")
            require(group["stdout"] == (argv[-1] + "\n").encode(), "EXACT_SINGLE_ORIGINAL_STOP_REMOVE_STDOUT")
        else:
            require(argv[1] in ("ps", "inspect") or argv[1:3] in (["network", "ls"], ["network", "inspect"], ["volume", "ls"], ["volume", "inspect"]), "OTHER_CLOSE_COMMANDS_READ_ONLY")
    require(mutations == expected_mutations(context) and len(mutations) == 11, "ALL_ELEVEN_ACTUAL_MUTATIONS_PRESENT")
    for stage, status in (("history-base-before-stop", "running"), ("history-base-after-stop", "exited")):
        rows = [decode(g["stdout"]) for g in close if g["stage"] == stage]
        require(len(rows) == 2 and {v["id"] for v in rows} == set(context["initial_service_ids"].values()), "BOTH_BASE_ACTUAL_STATE_OBSERVATIONS")
        for row in rows:
            require(row["status"] == status and row["restart_count"] == 0, "SAME_BASE_NORMAL_STATE")
            require((type(row["pid"]) is int and row["pid"] > 0) if status == "running" else
                    (type(row["pid"]) is int and row["pid"] == 0 and type(row["exit_code"]) is int and row["exit_code"] == 0), "BASE_REAL_NORMAL_STOP_NO_KILL")
    final = [g for g in close if g["stage"] == "history-final-global-after"]
    require(len(final) == 1 and historical.isdisjoint(final[0]["stdout"].decode().splitlines()), "ORIGINAL_GLOBAL_HISTORICAL_ZERO")
    physical = {}
    for group in close:
        if group["stage"] == "history-physical-volume":
            row = decode(group["stdout"])
            name = row["name"]
            original = next((v for v in context["initial_volumes"] if v["name"] == name), None)
            require(original is not None and {k: row[k] for k in original} == original, "ORIGINAL_VOLUME_PHYSICAL_OBSERVATION")
            physical[name] = row["mountpoint"].rstrip("/")
    require(physical == before["physical_volumes"] and len(set(physical.values())) == 6
            and all(type(v) is str and v.startswith("/") and v != "/" for v in physical.values()), "SIX_RECORDED_PHYSICAL_ROOTS_NOT_REINSPECTED")
    return historical, aliases, len(groups) + compose_count


def public_classification(root, helpers, archived):
    files, _ = helpers.namespace(root)
    fixed = MUTABLE | {"actual-run-context.json", "preparation-source-binding.json", "preparation-attempt.json", "history-source-review.json", "history-source-publication.json"}
    result = {}
    for name in files:
        if private_name(name):
            result[name] = "private_metadata_only"
            continue
        public = name in fixed or name in archived
        if name.startswith("evidence/") and len(Path(name).parts) == 2:
            leaf = Path(name).name
            public = public or bool(re.fullmatch(r"(?:native-[0-9]{4,}\.json|process-(?:native-[0-9]{4,}|compose-[a-z0-9-]+)\.(?:created\.json|terminal\.json|stdout|stderr)|current-structure-upgrade-cli-[0-9]{2}\.(?:created\.json|terminal\.json|stdout|stderr)|previous-[0-9]{4,}-(?:network\.json|containers\.jsonl|volumes\.txt|volumes\.jsonl)|[a-z0-9-]+-compose-native\.json)", leaf))
            public = public or any(leaf.startswith(prefix + "-") and leaf.endswith(".json") for prefix in (*PROOFS, *("audit-" + p for p in PROOFS), "ms4-migration", "history-final-close"))
            public = public or leaf == "history-final-close.json"
        require(public, "EXPLICIT_CURRENT_PUBLIC_FILE_CLASSIFICATION")
        result[name] = "public"
    return result


def source_window(inputs, context, frozen):
    snapshots = {}
    for root, files, dirs in ((Path(context["repo_dir"]), frozen["current_reader"]["source_files"], frozen["current_reader"]["source_directories"]),
                              (Path(context["legacy_repo_dir"]), frozen["nonempty_history"]["legacy_source_files"], frozen["nonempty_history"]["legacy_source_directories"])):
        actual_files, actual_dirs = [], []
        for current, names, leaves in os.walk(root, followlinks=False, onerror=lambda error: (_ for _ in ()).throw(error)):
            parent = Path(current)
            inputs.h.ordinary(parent)
            for name in names[:]:
                path = parent / name
                inputs.h.ordinary(path)
                rel = path.relative_to(root).as_posix()
                if rel in (".git", "crmeb/runtime"):
                    names.remove(name)
                else:
                    actual_dirs.append(rel)
            actual_files.extend((parent / name).relative_to(root).as_posix() for name in leaves if (parent / name).relative_to(root).as_posix() != ".git")
        require(sorted(actual_files) == [v["path"] for v in files] and sorted(actual_dirs) == dirs, "ENTIRE_FROZEN_APPLICATION_NAMESPACE")
        for row in files:
            rel = relative(row["path"])
            require(Path(rel).name not in (".env", "private.env", "legacy.env", "install.lock")
                    or rel in ("crmeb/public/install/.env", "template/admin/.env"), "ONLY_FROZEN_PUBLIC_ENV_TEMPLATES")
            require(inputs.seal(root / rel) == {**row, "path": (root / rel).as_posix()}, "ALL_FROZEN_APPLICATION_FILE_BYTES")
        snapshots[root.as_posix()] = {"directories": {v: inputs.h.stamp(root / v) for v in ("", *dirs)}}
        for leaf in (".git", "crmeb/runtime"):
            path = root / leaf
            if not path.exists():
                snapshots[root.as_posix()][leaf] = None
            elif path.is_file():
                snapshots[root.as_posix()][leaf] = inputs.h.filepin(path, True)
            else:
                names, directories = inputs.h.namespace(path)
                snapshots[root.as_posix()][leaf] = {"files": {n: inputs.h.filepin(path / n, True) for n in names}, "directories": {n: inputs.h.stamp(path / n) for n in ("", *directories)}}
    prep = Path(context["prep_dir"])
    for row in frozen["artifact_files"]:
        require(inputs.seal(prep / relative(row["path"])) == {**row, "path": (prep / row["path"]).as_posix()}, "ALL_FOURTEEN_PREPARATION_BYTES")
    require(inputs.h.namespace(prep) == (sorted(row["path"] for row in frozen["artifact_files"]), ["."]), "EXACT_PREPARATION_NAMESPACE")
    for field in ("driver_source_files", "preparation_source_references"):
        for row in frozen[field]:
            path = Path(context["repo_dir"]) / relative(row["path"])
            require(inputs.seal(path) == {**row, "path": path.as_posix()}, "EXACT_DRIVER_AND_PREPARATION_REFERENCES")
    for row in frozen["current_reader"]["control_files"]:
        destination = row["path"]
        if destination.startswith("/workspace/"):
            path = Path(context["repo_dir"]) / relative(destination[len("/workspace/"):])
        else:
            require(destination.startswith("/run/nonempty/"), "FIXED_CONTROL_DESTINATION")
            path = prep / relative(destination[len("/run/nonempty/"):])
        require(inputs.seal(path) == {**row, "path": path.as_posix()}, "FULL_PRODUCER11_READER12_CONTROL_BYTES")
    snapshots[prep.as_posix()] = {"root": inputs.h.stamp(prep), "namespace": inputs.h.namespace(prep)}
    return snapshots


def query_argv(kind, selector, context):
    require(kind in ("container", "network", "volume") and selector in ("project", "run"), "SIX_FIXED_QUERY_SELECTORS")
    value = "com.docker.compose.project=" + context["project"] if selector == "project" else "tianli.audit.run=" + context["run_id"]
    command = {"container": ["ps", "-aq", "--no-trunc"], "network": ["network", "ls", "-q", "--no-trunc"], "volume": ["volume", "ls", "-q"]}[kind]
    return [DOCKER, *command, "--filter", "label=" + value]


class Fresh:
    def __init__(self, output):
        self.output = output
        self.operations = []
        self.waiting = None

    def save(self, name, raw):
        path = self.output / name
        with path.open("xb") as stream:
            require(stream.write(raw) == len(raw), "FULL_CREATE_ONLY_ZERO_EVIDENCE")
            stream.flush()
            os.fsync(stream.fileno())
        require(path.read_bytes() == raw, "FULL_ZERO_EVIDENCE_READBACK")

    def obj(self, name, value):
        self.save(name, (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode())

    def run(self, argv, label):
        require(argv[0] == DOCKER and (argv[1] in ("ps", "inspect") or argv[1:3] in (["network", "ls"], ["volume", "ls"])), "ONLY_READ_ONLY_DOCKER_QUERIES")
        stem = "%03d-%s" % (len(self.operations) + 1, label)
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        require(type(process.pid) is int and process.pid > 0, "FRESH_REAL_POPEN_PID")
        self.waiting = {"pid": process.pid, "argv": argv}
        self.obj(stem + ".created.json", {"argv": argv, "pid": process.pid})
        stdout, stderr = process.communicate()
        waited, polled = process.wait(), process.poll()
        self.save(stem + ".stdout", stdout)
        self.save(stem + ".stderr", stderr)
        row = {"index": len(self.operations) + 1, "stem": stem, "argv": argv, "pid": process.pid, "native_exit": process.returncode, "wait_returncode": waited, "poll_returncode": polled, "stdout_bytes": len(stdout), "stdout_sha256": pin(stdout)["sha256"], "stderr_bytes": len(stderr), "stderr_sha256": pin(stderr)["sha256"]}
        self.obj(stem + ".terminal.json", row)
        self.operations.append(row)
        self.waiting = None
        require(all(type(v) is int and v == 0 for v in (process.returncode, waited, polled)) and stderr == b"", "FRESH_NATIVE_WAIT_POLL_ZERO")
        return stdout


def fresh_zero(fresh, context, historical, aliases, physical):
    for kind in ("container", "network", "volume"):
        for selector in ("project", "run"):
            require(fresh.run(query_argv(kind, selector, context), "zero-" + kind + "-" + selector) == b"", "FRESH_SIX_EMPTY_LABEL_QUERIES")
    argv = [DOCKER, "ps", "-aq", "--no-trunc"]
    before = fresh.run(argv, "global-cids-before")
    ids = before.decode().splitlines()
    require(len(ids) == len(set(ids)) and all(re.fullmatch("[a-f0-9]{64}", cid) for cid in ids)
            and historical.isdisjoint(ids), "FRESH_HISTORICAL_CIDS_ABSENT")
    if ids:
        raw = fresh.run([DOCKER, "inspect", "--format", GLOBAL_FORMAT, *ids], "global-alias-facts")
        rows = [decode(line) for line in raw.splitlines()]
        require(len(rows) == len(ids) and {v["id"] for v in rows} == set(ids), "COMPLETE_FRESH_GLOBAL_PROJECTION")
        for row in rows:
            keys(row, ("id", "name", "mounts", "networks", "network_mode"), "SAFE_GLOBAL_PROJECTION_ONLY")
            require(row["name"].lstrip("/") not in aliases, "NO_ORIGINAL_CONTAINER_NAME_ALIAS")
            require(context["project"] + "_isolation" not in row["networks"]
                    and all(v.get("NetworkID") != context["network_id"] for v in row["networks"].values()), "NO_ORIGINAL_NETWORK_ALIAS")
            mode = row["network_mode"]
            if mode.startswith("container:"):
                target = mode.split(":", 1)[1]
                require(target not in aliases and not any(len(target) >= 12 and cid.startswith(target) for cid in historical), "NO_ORIGINAL_CONTAINER_NAMESPACE_ALIAS")
            for mount in row["mounts"]:
                source = mount.get("Source", "").rstrip("/")
                require(mount.get("Name") not in physical and not any(source == p or source.startswith(p + "/") for p in physical.values()), "NO_ORIGINAL_PHYSICAL_VOLUME_ALIAS")
    require(fresh.run(argv, "global-cids-after") == before, "FRESH_GLOBAL_CID_SET_HELD")


def parser():
    value = argparse.ArgumentParser(description=__doc__)
    value.add_argument("--profile", required=True, choices=[PROFILE])
    for name in ("context", "close-result-sha256", "close-source-sha256", "source-sha256", "output"):
        value.add_argument("--" + name, required=True)
    return value


def execute(args):
    require(args.profile == PROFILE, "EXPLICIT_CURRENT_HISTORY_PROFILE")
    helpers = load_helpers()
    context_path = absolute(args.context)
    root = context_path.parent
    inputs = Inputs(helpers, root)
    require(context_path.name == "actual-run-context.json", "ORIGINAL_CONTEXT_FILENAME")
    context = decode(inputs.read(context_path))
    require(absolute(context["run_dir"]) == root, "SAME_ORIGINAL_RUN_ROOT")
    frozen_raw = inputs.run("preparation-source-binding.json")
    require(pin(frozen_raw) == {"bytes": context["freeze_bytes"], "sha256": context["freeze_sha256"]}, "ENTIRE_ORIGINAL_FREEZE")
    frozen = decode(frozen_raw)
    identities(context, frozen)
    host = Path(context["prep_dir"]) / "ms4-host.py"
    host_pin = inputs.seal(host)
    require(any(host_pin == {"path": host.as_posix(), **allowed} for allowed in HOST_PINS)
            and sha(args.close_source_sha256) == host_pin["sha256"], "EXACT_REVIEWED_FROZEN_HOST_SOURCE")
    own = Path(__file__).resolve()
    own_pin = inputs.seal(own)
    require(own_pin["sha256"] == sha(args.source_sha256), "EXPLICIT_REVIEWED_ZERO_SOURCE")
    inputs.seal(own.with_name("retire_failed_history.py"))
    closed_raw = inputs.run("evidence/history-final-close.json")
    require(pin(closed_raw)["sha256"] == sha(args.close_result_sha256), "ROOT_OBSERVED_ACTUAL_CLOSE_SHA")
    closed = decode(closed_raw)
    before = inputs.obj("evidence/history-final-close-before.json")
    keys(closed, CLOSE_KEYS, "ACTUAL_CURRENT_CLOSE_EXACT12")
    keys(before, BEFORE_KEYS, "ACTUAL_CURRENT_CLOSE_BEFORE_EXACT9")
    require(type(closed["schema"]) is type(before["schema"]) is int and closed["schema"] == before["schema"] == 1
            and all(type(closed[k]) is int for k in ("original_containers", "original_volumes", "original_networks"))
            and closed["scope"] == "NONEMPTY_HISTORY_NORMAL_READER_CHAIN_CLOSED"
            and all(closed[k] == before[k] == context[k] for k in ("run_id", "source_sha", "freeze_sha256"))
            and closed["normal_base_stop"] is True and closed["original_containers"] == 2
            and closed["original_volumes"] == 6 and closed["original_networks"] == 1
            and closed["full_acceptance"] == "INCOMPLETE", "CURRENT_NORMAL_CLOSE_NOT_FULL_ACCEPTANCE")
    expected_zero = {kind + ":" + label: [] for kind in ("container", "network", "volume") for label in ("com.docker.compose.project", "tianli.audit.run")}
    require(closed["fresh_zero_queries"] == expected_zero, "ORIGINAL_AUTHOR_SIX_ZERO_RECORD")
    require(before["base"] == context["initial_service_created"] and before["volumes"] == context["initial_volumes"]
            and before["network"] == context["initial_network"], "ORIGINAL_BEFORE_RESOURCE_IDENTITIES")
    retired_chain(inputs, context, closed)
    archived = phase_archives(inputs, context)
    historical, aliases, native_count = close_records(inputs, context, before)
    # Read the real public source-review/publication aliases without executing them.
    for field, leaf in (("history_source_review", "history-source-review.json"), ("history_source_publication", "history-source-publication.json")):
        row = frozen[field]
        require(inputs.seal(absolute(row["path"])) == row and inputs.run(leaf) == inputs.read(absolute(row["path"])), "EXACT_EXTERNAL_SOURCE_ALIAS")
    classification = public_classification(root, helpers, archived)
    run_before = helpers.run_snapshot(root, classification)
    source_before = source_window(inputs, context, frozen)
    for executable in (Path(DOCKER), Path(sys.executable).resolve()):
        inputs.seal(executable)
    inputs.held()
    output = absolute(args.output)
    for source in (root, Path(context["repo_dir"]), Path(context["legacy_repo_dir"]), Path(context["prep_dir"]), own.parent, own.parents[3]):
        require(not output.is_relative_to(source) and not source.is_relative_to(output), "ZERO_OUTPUT_OUTSIDE_ALL_INPUT_TREES")
    helpers.ordinary(output, missing=True)
    require(output.parent.is_dir() and not output.exists() and not output.is_symlink(), "ONE_NEW_EXTERNAL_ZERO_ATTEMPT")
    output.mkdir(mode=0o700)
    fresh = Fresh(output)
    try:
        fresh.save("zero-source.py", inputs.read(own))
        fresh_zero(fresh, context, historical, aliases, before["physical_volumes"])
        require(helpers.run_snapshot(root, classification) == run_before, "ALL_RUN_PUBLIC_BYTES_PRIVATE_METADATA_NAMESPACES_HELD")
        require(source_window(inputs, context, frozen) == source_before, "BOTH_FROZEN_SOURCE_WINDOWS_HELD")
        inputs.held()
        fresh.obj("INPUT-WINDOW.json", {"scope": "THIS_INDEPENDENT_ZERO_WINDOW_ONLY", "public_inputs": inputs.files, "run": run_before, "sources": source_before})
        result = {"schema": 1, "profile": PROFILE, "scope": "CURRENT_HISTORY_INDEPENDENT_READ_ONLY_ZERO_ONLY", "status": "VERIFIED_CURRENT_HISTORY_INDEPENDENT_ZERO", "run_id": context["run_id"], "source_sha": context["source_sha"], "freeze_sha256": context["freeze_sha256"], "context": inputs.seal(context_path), "close_result": {"path": (root / "evidence/history-final-close.json").as_posix(), **pin(closed_raw)}, "source": own_pin, "original_native_groups_read": native_count, "original_close_mutations_verified": 11, "fresh_label_queries": 6, "operations": fresh.operations, "historical_cids_absent": sorted(historical), "physical_volume_aliases_absent": True, "all_current_input_windows_held": True, "closed_period_PUBLIC_INPUTS_proof": "NOT_PRESENT_NOT_CLAIMED", "business_phase_INDEX_and_full_pins": "READ_AND_HELD_COMPLETE_BUSINESS_INTERPRETATION_REQUIRES_NONAUTHOR_READBACK", "close_outer_terminal": "REQUIRED_FROM_ROOT_ORIGINAL_NATIVE_HANDLE", "mutations": "NONE", "business_acceptance": "NOT SIGNED", "full_acceptance": "INCOMPLETE"}
        fresh.obj("RESULT.json", result)
        expected = {"zero-source.py", "INPUT-WINDOW.json", "RESULT.json"}
        for operation in fresh.operations:
            expected.update(operation["stem"] + "." + s for s in ("created.json", "terminal.json", "stdout", "stderr"))
        require({p.name for p in output.iterdir()} == expected, "EXACT_NEW_ZERO_EVIDENCE_NAMESPACE")
        return {k: result[k] for k in ("status", "run_id", "source_sha", "mutations", "full_acceptance")}
    except Exception as error:
        fresh.obj("FAILURE.json", {"status": "INDEPENDENT_ZERO_INCOMPLETE_ORIGINAL_STATE_PRESERVED", "class": type(error).__name__, "waiting_same_native": fresh.waiting, "operations": fresh.operations, "no_mutation_or_retry": True})
        raise


def main():
    args = parser().parse_args()
    print(json.dumps(execute(args), ensure_ascii=False), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        code = error.args[0] if type(error) is RuntimeError and len(error.args) == 1 and type(error.args[0]) is str and re.fullmatch("[A-Z0-9_]+", error.args[0]) else None
        print(json.dumps({"status": "INDEPENDENT_ZERO_INCOMPLETE_ORIGINAL_STATE_PRESERVED", "class": type(error).__name__, "code": code, "mutations": "NONE"}), flush=True)
        raise SystemExit(1)
