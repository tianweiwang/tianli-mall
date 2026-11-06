#!/usr/bin/env python3
"""One explicit post-reader synthetic ownership run; no retry or failed cleanup.

This source is an unexecuted candidate until independently reviewed.  It never
changes the original history context, manifest, source packet, or old results.
Only a successful new actor may be normally removed.  Original final-close is
deliberately not invoked here.
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import stat
import subprocess
import sys


SCOPE = "POST_READER_SYNTHETIC_OWNERSHIP_NORMAL_V1"
IMAGE = "sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a"
SERVICE = "audit-post-reader-ownership"
PROOF = "post-reader-ownership"
ROLE = {"service": SERVICE, "proof": PROOF, "image": IMAGE}
DEPENDENCIES = (
    "help/audit-regressions/isolated/lib/guard.php",
    "help/audit-regressions/isolated/lib/harness.php",
    "help/audit-regressions/isolated/ms2-migration.php",
    "help/audit-regressions/isolated/ms4-migration.php",
    "help/migrations/marketplace-ms1.php",
    "help/migrations/marketplace-ms2.php",
)
VOLUMES = {"app-secrets", "bootstrap-secrets", "fixture-secrets", "mysql-data", "redis-data", "redis-secrets"}
STEPS = ["customer_login", "current_products", "current_product_detail", "current_reply_list", "current_reply_config",
         "current_refunded_root_detail", "current_completed_root_detail", "current_refund_detail", "current_original_public_image"]
STAT9 = ("st_dev", "st_ino", "st_mode", "st_nlink", "st_uid", "st_gid", "st_size", "st_mtime_ns", "st_ctime_ns")
MAX_PUBLIC = 128 * 1024 * 1024
EMPTY_SHA = hashlib.sha256(b"").hexdigest()
GLOBAL_FORMAT = ('{"id":{{json .Id}},"name":{{json .Name}},"mounts":{{json .Mounts}},'
                 '"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}')


class Stopped(RuntimeError):
    pass


def require(condition: bool, code: str) -> None:
    if not condition:
        raise Stopped(code)


def pin(raw: bytes) -> dict:
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def encode(value) -> bytes:
    return (json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")


def object_(raw: bytes) -> dict:
    value = json.loads(raw)
    require(type(value) is dict, "PUBLIC_OBJECT_REQUIRED")
    return value


def ordinary(path: Path, directory: bool = False) -> Path:
    require(path.is_absolute(), "ABSOLUTE_PATH_REQUIRED")
    path = Path(os.path.abspath(path))
    for node in (path, *path.parents):
        value = node.lstat()
        require(not stat.S_ISLNK(value.st_mode) and not (getattr(value, "st_file_attributes", 0) & 1024),
                "NO_SOURCE_LINK_OR_REPARSE")
    value = path.lstat()
    require(stat.S_ISDIR(value.st_mode) if directory else stat.S_ISREG(value.st_mode), "ORDINARY_SOURCE_REQUIRED")
    if not directory:
        require(value.st_nlink == 1, "SINGLE_SOURCE_LINK_REQUIRED")
    return path.resolve(strict=True)


def read(path: Path, maximum: int = MAX_PUBLIC) -> bytes:
    path = ordinary(path)
    first = path.lstat()
    before = tuple(getattr(first, key) for key in STAT9)
    require(all(type(item) is int for item in before) and 0 <= first.st_size <= maximum, "FINITE_PUBLIC_SOURCE")
    with path.open("rb") as stream:
        opened_stat = os.fstat(stream.fileno())
        opened = tuple(getattr(opened_stat, key) for key in STAT9)
        require((opened[:-1] == before[:-1] if os.name == "nt" else opened == before), "SAME_PUBLIC_OPEN_IDENTITY")
        raw = stream.read(maximum + 1)
        require(len(raw) == first.st_size and stream.read(1) == b""
                and tuple(getattr(os.fstat(stream.fileno()), key) for key in STAT9) == opened, "PUBLIC_COMPLETE_HANDLE_WINDOW")
    require(tuple(getattr(path.lstat(), key) for key in STAT9) == before, "PUBLIC_CLOSED_PATH_WINDOW")
    return raw


def exact(path: Path, expected: dict) -> bytes:
    require(type(expected) is dict and set(expected) >= {"bytes", "sha256"}
            and type(expected["bytes"]) is int and expected["bytes"] >= 0
            and type(expected["sha256"]) is str and re.fullmatch("[a-f0-9]{64}", expected["sha256"]), "EXACT_PUBLIC_PIN")
    raw = read(path)
    require(pin(raw) == {key: expected[key] for key in ("bytes", "sha256")}, "PUBLIC_PIN_MISMATCH")
    return raw


def pointer(path: Path, container_path: str, raw: bytes | None = None) -> dict:
    require(container_path.startswith("/") and ".." not in container_path.split("/"), "CANONICAL_CONTAINER_POINTER")
    return {"host_path": ordinary(path).as_posix(), "path": container_path, **pin(read(path) if raw is None else raw)}


def new(path: Path, raw: bytes) -> None:
    with path.open("xb") as stream:
        require(stream.write(raw) == len(raw), "COMPLETE_CREATE_ONLY_WRITE")
    require(read(path) == raw, "CREATE_ONLY_READBACK")


def same_json(left, right) -> bool:
    return json.dumps(left, sort_keys=True, ensure_ascii=False, separators=(",", ":")) == json.dumps(right, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def run_relative(root: Path, item: dict) -> tuple[Path, bytes]:
    require(type(item) is dict and set(item) == {"path", "bytes", "sha256"}
            and type(item["path"]) is str and item["path"].startswith("/run/audit/"), "ORIGINAL_RUN_DESCRIPTOR")
    relative = item["path"][len("/run/audit/"):]
    require(relative and not re.search(r"(^/|\\|(^|/)\.\.?(/|$)|:)", relative), "ORIGINAL_RUN_RELATIVE_PATH")
    path = root / relative
    return path, exact(path, item)


def qualify(args) -> dict:
    expected_source_sha = args.source
    require(type(expected_source_sha) is str and re.fullmatch("[a-f0-9]{40}", expected_source_sha),
            "EXPLICIT_EXPECTED_APPLICATION_SOURCE_SHA")
    source = ordinary(Path(args.history_source), True)
    profile = ordinary(source / "candidate", True)
    source_pins_raw = read(source / "SOURCE-PINS.json")
    source_pins = object_(source_pins_raw)
    require(set(source_pins) == {"schema", "scope", "source_sha", "members"}
            and type(source_pins["schema"]) is int and source_pins["schema"] == 1
            and source_pins["scope"] == "CURRENT_HISTORY_SOURCE_CANDIDATE_NOT_RUNTIME"
            and source_pins["source_sha"] == expected_source_sha and type(source_pins["members"]) is dict
            and len(source_pins["members"]) == 37,
            "REVIEWED_CURRENT_HISTORY_SOURCE_ONLY")
    actual_files = {path.relative_to(source).as_posix() for path in source.rglob("*") if path.is_file()}
    require(actual_files == set(source_pins["members"]) | {"SOURCE-PINS.json"}
            and all(not path.is_symlink() and not path.is_junction() for path in source.rglob("*")),
            "COMPLETE_SOURCE3_PACKET_NAMESPACE")
    for name, expected in source_pins["members"].items():
        require(type(name) is str and not re.search(r"(^/|\\|(^|/)\.\.?(/|$)|:)", name), "SOURCE_MEMBER_NAMESPACE")
        exact(source / name, expected)
    host_path = profile / "ms4-host.py"
    # The frozen, reviewed Host is loaded under a non-main name.  current(False)
    # checks original review/publication aliases; no Host native/collect is used.
    module = runpy.run_path(str(host_path), run_name="post_reader_original_history_public_contract")
    context_path = ordinary(Path(args.context))
    context_raw = read(context_path)
    context = module["current"](context_path, False)
    require(context == object_(context_raw) and context["source_sha"] == expected_source_sha
            and context["prep_dir"] == profile.as_posix()
            and re.fullmatch(r"\d{14}[a-f0-9]{6}", context["run_id"])
            and context["project"] == "tianli-audit-" + context["run_id"], "ORIGINAL_FIXED_HISTORY_CONTEXT")
    root = ordinary(Path(context["run_dir"]), True)
    repo = ordinary(Path(context["repo_dir"]), True)
    require(context_path == root / "actual-run-context.json", "ORIGINAL_CONTEXT_LOCATION")
    freeze_raw = read(root / "preparation-source-binding.json")
    require(pin(freeze_raw)["sha256"] == context["freeze_sha256"], "ORIGINAL_FREEZE_SHA")
    freeze = object_(freeze_raw)
    require(freeze["source_sha"] == expected_source_sha and freeze["requested_repo_source"] == repo.as_posix()
            and freeze["status"] == "CLEAN_SOURCE_FROZEN_ONLY", "ORIGINAL_CLEAN_FREEZE")
    review_raw = read(root / "history-source-review.json")
    publication_raw = read(root / "history-source-publication.json")
    review, publication = object_(review_raw), object_(publication_raw)
    require(review["status"] == "PASS_CURRENT_HISTORY_SOURCE_REVIEW" and review["source_sha"] == expected_source_sha
            and publication["scope"] == "CURRENT_HISTORY_SOURCE_PUBLICATION" and publication["source_sha"] == expected_source_sha
            and same_json(publication["artifact_files"], freeze["artifact_files"]), "ORIGINAL_REVIEWED_SOURCE_PUBLICATION")
    artifacts = []
    require(len(freeze["artifact_files"]) == 14 and len({row["path"] for row in freeze["artifact_files"]}) == 14,
            "COMPLETE_HISTORY_ARTIFACT14")
    for row in freeze["artifact_files"]:
        require(type(row["path"]) is str and not re.search(r"(^/|\\|(^|/)\.\.?(/|$)|:)", row["path"]), "HISTORY_ARTIFACT_PATH")
        raw = exact(profile / row["path"], row)
        require(source_pins["members"]["candidate/" + row["path"]] == pin(raw), "PACKET_AND_FREEZE_SAME_ARTIFACT")
        artifacts.append(pointer(profile / row["path"], "/run/nonempty/" + row["path"], raw))
    evidence = root / "evidence"
    made_raw, terminal_raw, result_raw, retired_raw = [read(evidence / leaf) for leaf in
        ("legacy-current-reader-created.json", "legacy-current-reader-terminal.json", "legacy-current-reader-result.json", "legacy-current-reader-retired.json")]
    made, terminal, result, retired = map(object_, (made_raw, terminal_raw, result_raw, retired_raw))
    cid = made["id"]
    require(re.fullmatch("[a-f0-9]{64}", cid) and made["proof_name"] == "legacy-current-reader"
            and made["run_id"] == context["run_id"] and made["source_sha"] == expected_source_sha, "ORIGINAL_READER_CREATED")
    module["same_created"](made, terminal)
    module["owned"](context, terminal, "audit-legacy-current-reader")
    require(terminal["status"] == "exited" and terminal["pid"] == terminal["exit_code"] == terminal["restart_count"] == 0,
            "ORIGINAL_READER_NATIVE_ZERO")
    result_keys = {"schema", "scope", "status", "run_id", "source_sha", "freeze_sha256", "container_id", "entry_namespace_pid",
                   "legacy_parent", "identities", "requests", "normal_http_close", "feature_profile", "files", "reader_terminal", "full_acceptance"}
    require(set(result) == result_keys and type(result["schema"]) is int and result["schema"] == 1
            and result["scope"] == "CURRENT_SOURCE_SINGLE_SELF_LEGACY_READS_ONLY"
            and result["status"] == "VERIFIED_CURRENT_LEGACY_HISTORY_READS_NORMAL_CLOSED"
            and result["run_id"] == context["run_id"] and result["source_sha"] == expected_source_sha
            and result["freeze_sha256"] == context["freeze_sha256"] and result["container_id"] == cid
            and type(result["entry_namespace_pid"]) is int and result["entry_namespace_pid"] > 0
            and result["files"] == "VERIFIED_CURRENT_SOURCE_NORMAL_CLOSE"
            and result["reader_terminal"] == "REQUIRED_FROM_ORIGINAL_NATIVE_TERMINAL"
            and result["full_acceptance"] == "INCOMPLETE", "ORIGINAL_READER_COMPLETE_NORMAL_RESULT")
    feature = result["feature_profile"]
    require(type(feature) is dict and set(feature) == {"enabled", "self_operated_mer_id", "scope"}
            and feature["enabled"] is False and feature["scope"] == "STRUCTURE_ONLY_NOT_BACKFILLED"
            and type(feature["self_operated_mer_id"]) is int and feature["self_operated_mer_id"] > 0, "ORIGINAL_SINGLE_SELF_PROFILE")
    require(result["requests"] == [{"sequence": number, "step": step, "http": 200, "seven_parts_equal": True}
                                    for number, step in enumerate(STEPS, 1)], "ORIGINAL_COMPLETE_NINE_NORMAL_REQUESTS")
    http = result["normal_http_close"]
    require(http["stopped"] is True and http["remaining_members"] == [] and http["listener_open"] is False
            and http["term_attempted"] is True and http["stop_signal"] == 15
            and type(http["proc_close_exit"]) is int and http["first_terminal"]["running"] is False
            and http["first_terminal"]["signaled"] is True and http["first_terminal"]["termsig"] == 15,
            "ORIGINAL_READER_HTTP_NORMAL_CLOSED")
    producer_raw = read(evidence / "legacy-history-producer-result.json")
    producer = object_(producer_raw)
    require(same_json(result["legacy_parent"], producer["producer"]["native_parent"])
            and same_json(result["identities"], producer["identities"]), "ORIGINAL_READER_PRODUCER_IDENTITIES_JOIN")
    retired_keys = {"schema", "scope", "run_id", "source_sha", "freeze_sha256", "role", "proof_name", "container_id",
                    "created", "terminal", "binding", "result", "normal_remove", "resource_after", "prerequisites"}
    require(set(retired) == retired_keys and type(retired["schema"]) is int and retired["schema"] == 1
            and retired["scope"] == "NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED"
            and retired["run_id"] == context["run_id"] and retired["source_sha"] == expected_source_sha
            and retired["freeze_sha256"] == context["freeze_sha256"] and retired["role"] == "audit-legacy-current-reader"
            and retired["proof_name"] == "legacy-current-reader" and retired["container_id"] == cid, "ORIGINAL_READER_RETIRE_RECEIPT")
    for key, leaf in [("created", "legacy-current-reader-created.json"), ("terminal", "legacy-current-reader-terminal.json"),
                      ("binding", "audit-legacy-current-reader-source-binding.json"), ("result", "legacy-current-reader-result.json"),
                      ("resource_after", "legacy-current-reader-retired-resources.json")]:
        path, raw = run_relative(root, retired[key])
        require(path == evidence / leaf, "ORIGINAL_READER_RETIRE_EXACT_PUBLIC_POINTERS")
    remove_path, remove_raw = run_relative(root, retired["normal_remove"])
    require(re.fullmatch(r"native-[0-9]{4,}[.]json", remove_path.name), "ORIGINAL_NORMAL_REMOVE_NATIVE_PATH")
    removal = object_(remove_raw)
    require(set(removal) == {"stage", "native_exit", "stdout_bytes", "stdout_sha256", "stderr_bytes", "stderr_sha256", "private_environment_logs_omitted"}
            and removal["stage"] == "legacy-current-reader-exact-nonforce-remove" and type(removal["native_exit"]) is int
            and removal["native_exit"] == 0 and removal["stdout_bytes"] == 65
            and removal["stdout_sha256"] == pin((cid + "\n").encode())["sha256"]
            and removal["stderr_bytes"] == 0 and removal["stderr_sha256"] == EMPTY_SHA
            and removal["private_environment_logs_omitted"] is True, "ORIGINAL_READER_ACTUAL_NORMAL_REMOVE")
    require(set(retired["prerequisites"]) == {"driver_retired", "upgrade_retired"}, "ORIGINAL_READER_RETIRE_PREREQUISITES")
    for key, leaf in [("driver_retired", "driver-retired.json"), ("upgrade_retired", "current-structure-upgrade-retired.json")]:
        path, _ = run_relative(root, retired["prerequisites"][key]); require(path == evidence / leaf, "ORIGINAL_PREDECESSOR_RETIRE_POINTER")
    resource_after = object_(run_relative(root, retired["resource_after"])[1])
    raws = resource_after["public_raw"]
    require(set(raws) == {"network.json", "containers.jsonl", "volumes.txt", "volumes.jsonl"}
            and same_json(json.loads(raws["network.json"]), context["initial_network"])
            and same_json([json.loads(line) for line in raws["volumes.jsonl"].splitlines()], context["initial_volumes"]),
            "ORIGINAL_READER_RETIRED_SIX_VOLUME_NETWORK")
    base_ids = set(context["initial_service_ids"].values())
    require(set(context["initial_service_ids"]) == {"audit-mysql", "audit-redis"} and len(base_ids) == 2
            and {row["id"] for row in map(json.loads, raws["containers.jsonl"].splitlines())} == base_ids,
            "ORIGINAL_READER_RETIRED_TWO_BASES")
    require(len(context["initial_volumes"]) == 6 and {row["name"] for row in context["initial_volumes"]}
            == {context["project"] + "_" + name for name in VOLUMES}, "ORIGINAL_EXACT_SIX_VOLUMES")
    manifest_raw = read(root / "manifest.json")
    manifest = object_(manifest_raw)
    require(manifest["guard_mode"] == "read-only" and manifest["run_id"] == context["run_id"]
            and manifest["commit_sha"] == expected_source_sha and manifest["project"] == context["project"]
            and manifest["network"]["id"] == context["network_id"] and manifest["network_internal"] is True
            and all(manifest["services"][role]["id"] == context["initial_service_ids"][role]
                    for role in ("audit-mysql", "audit-redis")), "ORIGINAL_MANIFEST_BASE_IDENTITIES")
    indexed = {row["path"]: row for row in freeze["current_reader"]["source_files"]}
    dependencies = []
    for name in DEPENDENCIES:
        require(name in indexed, "DEPENDENCY_IN_ORIGINAL_CURRENT_SOURCE")
        raw = exact(repo / name, indexed[name])
        dependencies.append(pointer(repo / name, "/workspace/" + name, raw))
    actor_path = ordinary(Path(args.actor))
    actor_raw = read(actor_path)
    require(actor_raw.startswith(b"<?php") and b"POST_READER_SYNTHETIC_OWNERSHIP_NORMAL_V1" in actor_raw, "SPECIFIC_ACTOR_SOURCE")
    predecessors = {
        "context": pointer(context_path, "/run/audit/actual-run-context.json", context_raw),
        "freeze": pointer(root / "preparation-source-binding.json", "/run/audit/preparation-source-binding.json", freeze_raw),
        "reader_created": pointer(evidence / "legacy-current-reader-created.json", "/run/audit/evidence/legacy-current-reader-created.json", made_raw),
        "reader_result": pointer(evidence / "legacy-current-reader-result.json", "/run/audit/evidence/legacy-current-reader-result.json", result_raw),
        "reader_terminal": pointer(evidence / "legacy-current-reader-terminal.json", "/run/audit/evidence/legacy-current-reader-terminal.json", terminal_raw),
        "reader_retired": pointer(evidence / "legacy-current-reader-retired.json", "/run/audit/evidence/legacy-current-reader-retired.json", retired_raw),
        "source_pins": pointer(source / "SOURCE-PINS.json", "/run/post-reader/history-source-pins.json", source_pins_raw),
        "source_publication": pointer(root / "history-source-publication.json", "/run/audit/history-source-publication.json", publication_raw),
        "source_review": pointer(root / "history-source-review.json", "/run/audit/history-source-review.json", review_raw),
        "manifest": pointer(root / "manifest.json", "/run/audit/manifest.json", manifest_raw),
        "producer_result": pointer(evidence / "legacy-history-producer-result.json", "/run/audit/evidence/legacy-history-producer-result.json", producer_raw),
    }
    handoff = {"schema": 1, "scope": SCOPE, "run_id": context["run_id"], "app_source_sha": expected_source_sha,
               "context": context, "predecessors": predecessors,
               "resources": {"services": context["initial_service_created"], "volumes": context["initial_volumes"], "network": context["initial_network"]},
               "self_mer_id": feature["self_operated_mer_id"],
               "actor": pointer(actor_path, "/run/post-reader/ms4-history-ownership-normal.php", actor_raw),
               "dependencies": dependencies, "history_artifacts": artifacts, "role": ROLE}
    return {"expected_source_sha": expected_source_sha,
            "source": source, "profile": profile, "root": root, "repo": repo, "module": module,
            "context": context, "handoff": handoff, "actor_raw": actor_raw, "source_pins_raw": source_pins_raw}


class Native:
    def __init__(self, root: Path):
        self.root = root
        self.sequence = 0

    def run(self, label: str, argv: list[str]) -> bytes:
        require(re.fullmatch(r"[a-z0-9-]+", label) is not None, "FINITE_NATIVE_LABEL")
        self.sequence += 1
        stem = self.root / ("%03d-" % self.sequence + label)
        stdout, stderr = Path(str(stem) + ".stdout"), Path(str(stem) + ".stderr")
        created, terminal = Path(str(stem) + ".created.json"), Path(str(stem) + ".terminal.json")
        with stdout.open("xb") as out, stderr.open("xb") as err:
            process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                                       creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            new(created, encode({"schema": 1, "label": label, "argv": argv, "pid": process.pid,
                                 "parent_pid": os.getpid(), "observed_at": datetime.datetime.now(datetime.timezone.utc).isoformat()}))
            waited = process.wait()
        out_raw, err_raw = read(stdout), read(stderr)
        new(terminal, encode({"schema": 1, "label": label, "argv": argv, "pid": process.pid,
                              "native_exit": process.returncode, "wait_returncode": waited,
                              "stdout_bytes": len(out_raw), "stdout_sha256": pin(out_raw)["sha256"],
                              "stderr_bytes": len(err_raw), "stderr_sha256": pin(err_raw)["sha256"]}))
        require(process.returncode == waited == 0 and err_raw == b"", "NATIVE_ZERO_REQUIRED")
        return out_raw


def ids(raw: bytes) -> list[str]:
    values = raw.decode("ascii").splitlines()
    require(len(values) == len(set(values)) and all(re.fullmatch("[a-f0-9]{64}", value) for value in values), "FULL_UNIQUE_NATIVE_CIDS")
    return values


def resource_gate(q: dict, native: Native, actor_id: str | None = None) -> dict:
    context, module = q["context"], q["module"]
    expected = set(context["initial_service_ids"].values()) | ({actor_id} if actor_id else set())
    all_ids = ids(native.run("global-before", ["docker", "ps", "-aq", "--no-trunc"]))
    require(expected <= set(all_ids), "ORIGINAL_BASES_AND_ONLY_KNOWN_ACTOR_PRESENT")
    rows = [json.loads(line) for line in native.run("global-projections", ["docker", "inspect", "--format", GLOBAL_FORMAT, *all_ids]).decode().splitlines()] if all_ids else []
    require(len(rows) == len(all_ids) and {row["id"] for row in rows} == set(all_ids), "COMPLETE_GLOBAL_PROJECTIONS")
    physical = {}
    for original in context["initial_volumes"]:
        raw = native.run("volume-identity", ["docker", "volume", "inspect", "--format",
            '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},"mountpoint":{{json .Mountpoint}}}', original["name"]])
        value = object_(raw)
        require({key: value[key] for key in original} == original and type(value["mountpoint"]) is str
                and value["mountpoint"].startswith("/"), "ORIGINAL_FULL_VOLUME_IDENTITY")
        physical[original["name"]] = value["mountpoint"].rstrip("/")
    network = context["initial_network"]
    current = object_(native.run("network-identity", ["docker", "network", "inspect", "--format",
        '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}', network["Id"]]))
    require({key: current[key] for key in network} == network and current["Internal"] is True
            and set(context["initial_service_ids"].values()) <= set(current["Containers"]) <= expected,
            "EXACT_ORIGINAL_INTERNAL_NETWORK_ENDPOINTS")
    names = {network["Id"], network["Name"]}
    aliases = expected | {row["name"].lstrip("/") for row in rows if row["id"] in expected}
    for row in rows:
        mode = row["network_mode"]
        touched = bool(names & set(row["networks"])) or any(item.get("NetworkID") == network["Id"] for item in row["networks"].values())
        touched = touched or (mode.startswith("container:") and any(mode[10:] == alias or (len(mode[10:]) >= 12 and alias.startswith(mode[10:])) for alias in aliases))
        for mount in row["mounts"]:
            source = mount.get("Source", "").rstrip("/")
            touched = touched or mount.get("Name") in physical or any(source == root or source.startswith(root + "/") for root in physical.values())
        require(not touched or row["id"] in expected, "NO_FOREIGN_HISTORY_RESOURCE_CONSUMER")
    for role, cid in context["initial_service_ids"].items():
        facts = object_(native.run("base-identity", ["docker", "inspect", "--format", module["INSPECT"], cid]))
        module["owned"](context, facts, role)
        module["same_created"](context["initial_service_created"][role], facts)
        require(facts["status"] == "running" and facts["pid"] > 0, "ORIGINAL_BASE_RUNNING")
    for path in sorted((q["root"] / "evidence").glob("*-created.json")):
        value = object_(read(path))
        if "id" in value:
            require(value["id"] in context["initial_service_ids"].values() or value["id"] not in all_ids, "ORIGINAL_RETIRED_HISTORY_CID_ABSENT")
    after = ids(native.run("global-after", ["docker", "ps", "-aq", "--no-trunc"]))
    require(set(after) == set(all_ids), "SAME_COMPLETE_GLOBAL_RESOURCE_WINDOW")
    return {"schema": 1, "scope": SCOPE, "global_ids": sorted(all_ids), "allowed_history_consumers": sorted(expected),
            "original_network": network, "original_volumes": context["initial_volumes"], "unexpected_consumers": []}


def preserved(q: dict) -> None:
    for row in q["handoff"]["predecessors"].values():
        exact(Path(row["host_path"]), row)
    for row in (*q["handoff"]["dependencies"], *q["handoff"]["history_artifacts"]):
        exact(Path(row["host_path"]), row)
    require(read(Path(q["handoff"]["actor"]["host_path"])) == q["actor_raw"], "NEW_ACTOR_SOURCE_PRESERVED")


def actor_mounts(q: dict, source_dir: Path, evidence: Path, facts: dict) -> None:
    def canonical(value: str) -> str:
        value = value.replace("\\", "/").rstrip("/")
        match = re.fullmatch(r"([A-Za-z]):(/.*)", value)
        return "/run/desktop/mnt/host/" + match[1].lower() + match[2] if match else value
    expected = {"/workspace": q["repo"], "/source": q["repo"], "/run/nonempty": q["profile"],
                "/run/audit": q["root"], "/run/post-reader": source_dir, "/run/post-reader-output": evidence}
    secret = {"/run/audit-app": "app-secrets", "/run/audit-fixtures": "fixture-secrets", "/run/audit-bootstrap": "bootstrap-secrets"}
    actual = {}
    for mount in facts["mounts"]:
        destination = mount["Destination"]
        require(destination not in actual, "ONE_ACTOR_MOUNT_PER_DESTINATION")
        if mount["Type"] == "tmpfs":
            require(destination in {"/tmp", "/current-runtime"} and mount["RW"] is True, "ONLY_OWN_TMPFS_MOUNTS")
            continue
        actual[destination] = mount
    require(set(actual) == set(expected) | set(secret), "EXACT_NINE_ACTOR_SOURCE_AND_SECRET_MOUNTS")
    for destination, host in expected.items():
        mount = actual[destination]
        require(mount["Type"] == "bind" and mount["RW"] is (destination == "/run/post-reader-output")
                and canonical(mount["Source"]) == canonical(host.as_posix()), "ACTUAL_READONLY_SOURCE_AND_OWN_OUTPUT_BIND")
    for destination, name in secret.items():
        mount = actual[destination]
        require(mount["Type"] == "volume" and mount["RW"] is False
                and mount["Name"] == q["context"]["project"] + "_" + name, "ORIGINAL_SECRETS_ONLY_READONLY")
    require(type(facts["tmpfs"]) is dict and set(facts["tmpfs"]) == {"/tmp", "/current-runtime"}, "EXACT_OWN_TMPFS_CONFIGURATION")
    for destination, mode in [("/tmp", "1777"), ("/current-runtime", "0700")]:
        require(set(facts["tmpfs"][destination].split(",")) == {"rw", "nosuid", "nodev", "mode=" + mode}, "FIXED_OWN_TMPFS_MODE")


def actor_identity(context: dict, facts: dict) -> None:
    labels = facts["labels"]
    require(labels.get("tianli.audit") == "1" and labels.get("tianli.audit.run") == context["run_id"]
            and labels.get("tianli.audit.proof") == PROOF
            and labels.get("com.docker.compose.project") == context["project"]
            and labels.get("com.docker.compose.service") == SERVICE, "OWN_ACTOR_EXACT_RUN_ROLE_LABELS")
    require(facts["image"] == IMAGE and facts["user"] == "0:0" and facts["read_only"] is True and facts["privileged"] is False
            and facts["entrypoint"] == ["php"] and facts["workdir"] == "/workspace/crmeb"
            and type(facts["restart_count"]) is int and facts["restart_count"] == 0
            and facts["cap_drop"] == ["ALL"]
            and facts["security_options"] in [["no-new-privileges"], ["no-new-privileges:true"]],
            "OWN_ACTOR_FIXED_IMAGE_AND_PRIVILEGE_BOUNDARY")
    network = context["initial_network"]
    require(type(facts["networks"]) is dict and set(facts["networks"]) == {network["Name"]}
            and ((facts["status"] == "created" and type(facts["pid"]) is int and facts["pid"] == 0
                  and facts["networks"][network["Name"]].get("NetworkID") in ["", context["network_id"]])
                 or (facts["status"] != "created"
                     and facts["networks"][network["Name"]].get("NetworkID") == context["network_id"]))
            and facts["network_mode"] == network["Name"]
            and type(facts["ports"]) is dict and all(value is None for value in facts["ports"].values())
            and facts["port_bindings"] in [None, {}], "OWN_ACTOR_ONE_ORIGINAL_NETWORK_NO_PUBLISHED_PORTS")


def normal_domains(q: dict, cid: str, handoff_raw: bytes, evidence: Path, result: dict) -> None:
    expected_source_sha = q["expected_source_sha"]
    def complete_pin(value: dict, maximum: int = MAX_PUBLIC) -> None:
        require(type(value) is dict and set(value) == {"bytes", "sha256"}
                and type(value["bytes"]) is int and 0 < value["bytes"] <= maximum
                and type(value["sha256"]) is str and re.fullmatch("[a-f0-9]{64}", value["sha256"]), "NORMAL_DOMAIN_COMPLETE_PIN")

    self_id = q["handoff"]["self_mer_id"]
    ms2 = result["ms2"]
    require(type(ms2) is dict and set(ms2) == {"attachment", "children", "steps", "mapping_pin",
            "complete_nonowner_and_legacy_transactions_preserved", "five_roots_enforced", "maintenance_feature_enabled"}
            and ms2["complete_nonowner_and_legacy_transactions_preserved"] is True
            and ms2["five_roots_enforced"] is True and ms2["maintenance_feature_enabled"] is False,
            "ACTUAL_MS2_MAINTENANCE_DOMAIN")
    complete_pin(ms2["mapping_pin"])
    attachment = ms2["attachment"]
    image_pin = {"bytes": 53659, "sha256": "24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d"}
    require(type(attachment) is dict and set(attachment) == {"scope", "attachment_id", "pid", "self_mer_id", "image", "mime",
            "width", "height", "before", "after", "only_declared_row_and_counter_changed", "original_product_sku_transactions_and_image_bytes_unchanged"}
            and attachment["scope"] == "ONE_DECLARED_SYNTHETIC_ORIGINAL_PNG_ATTACHMENT_ONLY"
            and type(attachment["attachment_id"]) is int and attachment["attachment_id"] > 0
            and type(attachment["pid"]) is int and attachment["pid"] == 0
            and type(attachment["self_mer_id"]) is int and attachment["self_mer_id"] == self_id
            and same_json(attachment["image"], image_pin) and attachment["mime"] == "image/png"
            and type(attachment["width"]) is int and attachment["width"] == 690
            and type(attachment["height"]) is int and attachment["height"] == 290
            and attachment["only_declared_row_and_counter_changed"] is True
            and attachment["original_product_sku_transactions_and_image_bytes_unchanged"] is True,
            "ONE_ACTUAL_DECLARED_ORIGINAL_PNG_ATTACHMENT")
    complete_pin(attachment["before"]); complete_pin(attachment["after"])
    require(attachment["before"] != attachment["after"], "DECLARED_ATTACHMENT_ACTUAL_STATE_CHANGE")
    script = next(row for row in q["handoff"]["dependencies"] if row["path"] == "/workspace/help/migrations/marketplace-ms2.php")
    script_pin = {key: script[key] for key in ("bytes", "sha256")}
    modes = ["check", "check", "backfill", "backfill", "enforce", "enforce", "check"]
    children = ms2["children"]
    require(type(children) is list and len(children) == 7, "SEVEN_REAL_MAINTENANCE_CHILDREN")
    pids, cli_results = set(), []
    for number, (child, mode) in enumerate(zip(children, modes), 1):
        require(type(child) is dict and set(child) == {"sequence", "mode", "pid", "first_native", "proc_close_exit", "native_exit",
                "native_exit_origin", "script", "stdout", "stderr", "private_arguments_and_values_omitted"}
                and type(child["sequence"]) is int and child["sequence"] == number and child["mode"] == mode
                and type(child["pid"]) is int and child["pid"] > 0 and child["pid"] not in pids
                and type(child["native_exit"]) is int and child["native_exit"] == 0
                and type(child["proc_close_exit"]) is int and child["private_arguments_and_values_omitted"] is True
                and same_json(child["script"], script_pin) and same_json(child["stderr"], {"bytes": 0, "sha256": EMPTY_SHA}),
                "MAINTENANCE_DISTINCT_NATIVE_CHILD_AND_SCRIPT")
        pids.add(child["pid"])
        first = child["first_native"]
        require(type(first) is dict and set(first) == {"pid", "running", "signaled", "stopped", "exitcode", "termsig", "stopsig"}
                and type(first["pid"]) is int and first["pid"] == child["pid"]
                and type(first["running"]) is bool and first["signaled"] is False and first["stopped"] is False
                and all(type(first[key]) is int for key in ("exitcode", "termsig", "stopsig"))
                and first["termsig"] == first["stopsig"] == 0, "MAINTENANCE_REAL_FIRST_NATIVE_SEVEN")
        require((child["native_exit_origin"] == "proc_close" and child["proc_close_exit"] == 0)
                or (child["native_exit_origin"] == "first_actual_terminal" and child["proc_close_exit"] == -1
                    and first["running"] is False and first["exitcode"] == 0), "MAINTENANCE_ACTUAL_EXIT_ORIGIN")
        complete_pin(child["stdout"], 33554432)
        require(same_json(object_(read(evidence / ("ms2-%d-native.json" % number))), child), "MAINTENANCE_NATIVE_PUBLIC_RECEIPT_JOIN")
        raw = exact(evidence / ("ms2-%d-result.json" % number), child["stdout"])
        value = object_(raw)
        require(value["mode"] == mode and type(value["native_exit"]) is int and value["native_exit"] == 0
                and value["state"] == "completed" and value["enabled"] is False
                and type(value["self_mer_id"]) is int and value["self_mer_id"] == self_id, "MAINTENANCE_REAL_CLI_PUBLIC_RESULT")
        cli_results.append(value)
    steps = ms2["steps"]
    changed = [False, True, False, True, False, False]
    require(type(steps) is list and len(steps) == 6, "COMPLETE_SIX_DECLARED_MAINTENANCE_STEPS")
    for index, (step, mode, actual_changed) in enumerate(zip(steps, modes[1:], changed)):
        require(type(step) is dict and set(step) == {"mode", "changed", "view"} and step["mode"] == mode
                and step["changed"] is actual_changed and cli_results[index + 1]["changed"] is actual_changed,
                "FIRST_ONLY_BACKFILL_AND_ENFORCE_CHANGE")
        complete_pin(step["view"])
    require(steps[0]["view"] == attachment["after"] and steps[2]["view"] == steps[1]["view"]
            and steps[4]["view"] == steps[3]["view"] == steps[5]["view"], "MAINTENANCE_COMPLETE_STATE_REPEAT_PINS")
    require(type(cli_results[2]["updated_rows"]) is int and cli_results[2]["updated_rows"] == 1
            and type(cli_results[3]["updated_rows"]) is int and cli_results[3]["updated_rows"] == 0, "ONLY_FIRST_TEMPLATE_BACKFILL_ONE_THEN_ZERO")
    ownership = result["ownership"]
    require(type(ownership) is dict and set(ownership) == {"five_roots", "all_rows_at_actual_self", "no_unknown_orphans_cross_store",
            "full_nonowner_preserved", "old_saved_versions_preserved"}
            and ownership["five_roots"] == ["store_product", "system_attachment", "system_attachment_category", "shipping_templates", "store_product_rule"]
            and all(ownership[key] is True for key in ownership if key != "five_roots"), "ALL_FIVE_ROOTS_AND_OLD_VERSION_RESPONSIBILITIES")
    require(same_json(result["app"], {"enabled": True, "self_operated_mer_id": self_id, "app_source_sha": expected_source_sha,
            "container_id": cid, "handoff_sha256": pin(handoff_raw)["sha256"]}), "ACTUAL_ENABLED_CURRENT_APPLICATION_PROFILE")
    require(same_json(result["requests"], [{"sequence": number, "step": step, "http": 200, "full_independent_oracle": True}
            for number, step in enumerate(STEPS, 1)]), "NINE_CURRENT_APPLICATION_FULL_INDEPENDENT_NORMAL_READS")
    preservation = result["preservation"]
    fields = {"complete174_nonowner_and_old_transactions", "only_declared_attachment_then_owner_constraints",
              "original_public_private_source_files", "original_context_manifest_results", "redis_only_approved_auth_and_passive_expiry",
              "old_v0_null_rows_balance_points_coupons_unchanged", "private_inputs_cleaned", "complete_seven_parts_verified"}
    require(type(preservation) is dict and set(preservation) == fields | {"original_image"}
            and all(preservation[key] is True for key in fields), "COMPLETE174_SEVEN_PARTS_AND_ORIGINAL_RESPONSIBILITIES")
    require(same_json(preservation["original_image"], {"schema": 1, "scope": "CURRENT_ORIGINAL_PUBLIC_IMAGE_BYTES_ONLY",
            **image_pin, "content_type": "image/png", "width": 690, "height": 290, "full_reader_oracle_complete": False}),
            "ORIGINAL_DECODED_IMAGE_PIN_WITH_OWN_LIMITED_SCOPE")


def execute(args) -> dict:
    q = qualify(args)
    expected_source_sha = q["expected_source_sha"]
    output = Path(os.path.abspath(args.output))
    ordinary(output.parent, True)
    require(not output.exists(), "NEW_OUTPUT_ONLY_NO_RETRY")
    require(not any(output == root or output.is_relative_to(root) or root.is_relative_to(output)
                    for root in (q["source"], q["root"], q["repo"])), "EXTERNAL_NEW_OUTPUT_ONLY")
    output.mkdir()
    source_dir, evidence = output / "source", output / "evidence"
    source_dir.mkdir(); evidence.mkdir()
    q["handoff"]["role"] = {**ROLE, "source_directory": ordinary(source_dir, True).as_posix(),
                             "output_directory": ordinary(evidence, True).as_posix()}
    capture = evidence / "native"; capture.mkdir()
    native = Native(capture)
    cid = None
    try:
        image = native.run("fixed-image", ["docker", "image", "inspect", "--format", "{{.Id}}", IMAGE])
        require(image == (IMAGE + "\n").encode(), "FIXED_EXISTING_PHP_IMAGE")
        before = resource_gate(q, native)
        new(evidence / "resource-admission.json", encode(before))
        new(source_dir / "ms4-history-ownership-normal.php", q["actor_raw"])
        new(source_dir / "history-source-pins.json", q["source_pins_raw"])
        handoff_raw = encode(q["handoff"])
        new(source_dir / "HANDOFF.json", handoff_raw)
        context = q["context"]
        actor_format = '{"user":{{json .Config.User}},' + q["module"]["INSPECT"][1:]
        argv = ["docker", "create", "--name", context["project"] + "-" + PROOF, "--pull", "never", "--network", context["initial_network"]["Name"],
                "--user", "0:0", "--read-only", "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
                "--tmpfs", "/tmp:rw,nosuid,nodev,mode=1777", "--tmpfs", "/current-runtime:rw,nosuid,nodev,mode=0700",
                "--workdir", "/workspace/crmeb", "--entrypoint", "php"]
        for key, value in {"tianli.audit": "1", "tianli.audit.run": context["run_id"], "tianli.audit.proof": PROOF,
                           "com.docker.compose.project": context["project"], "com.docker.compose.service": SERVICE}.items():
            argv += ["--label", key + "=" + value]
        for key, value in {"AUDIT_ISOLATED": "1", "AUDIT_RUN_ID": context["run_id"], "AUDIT_PROJECT": context["project"],
                           "AUDIT_SOURCE_SHA": expected_source_sha, "AUDIT_REPO_DIR": "/workspace", "AUDIT_RUN_DIR": "/run/audit",
                           "AUDIT_GUARD_MODE": "read-only", "MS4_PROOF_NAME": PROOF}.items():
            argv += ["--env", key + "=" + value]
        for host, destination, readonly in [(q["repo"], "/workspace", True), (q["repo"], "/source", True),
                (q["profile"], "/run/nonempty", True), (q["root"], "/run/audit", True),
                (source_dir, "/run/post-reader", True), (evidence, "/run/post-reader-output", False)]:
            require("," not in host.as_posix(), "UNAMBIGUOUS_BIND_PATH")
            argv += ["--mount", "type=bind,source=" + host.as_posix() + ",target=" + destination + (",readonly" if readonly else "")]
        for name, destination in [("app-secrets", "/run/audit-app"), ("fixture-secrets", "/run/audit-fixtures"), ("bootstrap-secrets", "/run/audit-bootstrap")]:
            argv += ["--mount", "type=volume,source=" + context["project"] + "_" + name + ",target=" + destination + ",readonly"]
        command = ["/run/post-reader/ms4-history-ownership-normal.php", "normal", "--handoff-bytes=" + str(len(handoff_raw)),
                   "--handoff-sha256=" + pin(handoff_raw)["sha256"]]
        raw_cid = native.run("actor-create", [*argv, IMAGE, *command])
        require(re.fullmatch(b"[a-f0-9]{64}\n", raw_cid), "ACTUAL_NEW_CREATED_CID")
        cid = raw_cid.decode().strip()
        made = object_(native.run("actor-created-inspect", ["docker", "inspect", "--format", actor_format, cid]))
        actor_identity(context, made)
        require(made["status"] == "created" and made["pid"] == 0 and made["command"] == command, "ACTUAL_UNSTARTED_NEW_ACTOR")
        actor_mounts(q, source_dir, evidence, made)
        made.update({"run_id": context["run_id"], "source_sha": expected_source_sha, "proof_name": PROOF})
        made_raw = encode(made)
        new(source_dir / "created.json", made_raw)
        binding = {"schema": 1, "scope": "POST_READER_SYNTHETIC_OWNERSHIP_SOURCE_BOUND_V1", "run_id": context["run_id"],
                   "app_source_sha": expected_source_sha, "container_id": cid, "handoff": pin(handoff_raw), "created": pin(made_raw), "actor": pin(q["actor_raw"])}
        new(source_dir / "source-binding.json", encode(binding))
        preserved(q)
        resource_gate(q, native, cid)
        native.run("actor-start-attached", ["docker", "start", "-a", cid])
        require(native.run("actor-wait", ["docker", "wait", cid]) == b"0\n", "ACTOR_REAL_WAIT_ZERO")
        terminal = object_(native.run("actor-terminal-inspect", ["docker", "inspect", "--format", actor_format, cid]))
        actor_identity(context, terminal)
        q["module"]["same_created"](made, terminal)
        require(terminal["status"] == "exited" and terminal["pid"] == terminal["exit_code"] == terminal["restart_count"] == 0,
                "NEW_ACTOR_ACTUAL_NATIVE_ZERO_TERMINAL")
        new(evidence / "actor-terminal.json", encode(terminal))
        result_raw = read(evidence / "RESULT.json")
        result = object_(result_raw)
        require(set(result) == {"schema", "scope", "status", "run_id", "app_source_sha", "actor_pin", "handoff_pin", "container_id",
                                "ms2", "ownership", "app", "requests", "normal_http_close", "preservation", "old_writes_tested", "full_acceptance"}
                and type(result["schema"]) is int and result["schema"] == 1 and result["scope"] == SCOPE
                and result["status"] == "VERIFIED_POST_READER_SYNTHETIC_OWNERSHIP_ENABLED_NORMAL_ONLY"
                and result["run_id"] == context["run_id"] and result["app_source_sha"] == expected_source_sha
                and result["container_id"] == cid and result["actor_pin"] == pin(q["actor_raw"])
                and result["handoff_pin"] == pin(handoff_raw) and result["old_writes_tested"] == "NOT RUN"
                and result["full_acceptance"] == "INCOMPLETE", "ACTUAL_OWN_NORMAL_RESULT_NOT_FULL_ACCEPTANCE")
        normal_domains(q, cid, handoff_raw, evidence, result)
        http = result["normal_http_close"]
        require(http["stopped"] is True and http["remaining_members"] == [] and http["listener_open"] is False
                and http["term_attempted"] is True and http["stop_signal"] == 15, "OWN_HTTP_GROUP_AND_LISTENER_NORMAL_CLOSED")
        preserved(q)
        resource_gate(q, native, cid)
        require(native.run("actor-normal-remove", ["docker", "rm", cid]) == (cid + "\n").encode(), "EXACT_NONFORCE_NORMAL_REMOVE")
        after = resource_gate(q, native)
        require(cid not in after["global_ids"], "NEW_ACTOR_CID_ACTUALLY_ABSENT")
        preserved(q)
        receipt = {"schema": 1, "scope": SCOPE, "status": "VERIFIED_POST_READER_ACTOR_NORMAL_RETIRED_ONLY",
                   "run_id": context["run_id"], "app_source_sha": expected_source_sha, "container_id": cid,
                   "actor_result": {"path": (evidence / "RESULT.json").as_posix(), **pin(result_raw)},
                   "handoff": {"path": (source_dir / "HANDOFF.json").as_posix(), **pin(handoff_raw)},
                   "resource_after": after, "native_commands": native.sequence, "original_history_final_close": "NOT RUN",
                   "full_acceptance": "INCOMPLETE"}
        new(output / "RUNNER-RESULT.json", encode(receipt))
        return receipt
    except Exception as error:
        code = str(error) if isinstance(error, Stopped) and re.fullmatch("[A-Z0-9_]+", str(error)) else "POST_READER_RUNNER_STOPPED"
        new(output / "RUNNER-STOPPED.json", encode({"schema": 1, "scope": SCOPE, "status": "STOPPED_STATE_PRESERVED",
            "code": code, "container_id": cid, "automatic_retry": False, "failed_cleanup": "NOT PERFORMED",
            "original_history_final_close": "NOT RUN", "full_acceptance": "INCOMPLETE"}))
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, help="Explicit expected application commit (40 lowercase hex characters).")
    parser.add_argument("--history-source", required=True)
    parser.add_argument("--context", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--actor", default=str(Path(__file__).resolve().parent.parent / "isolated/ms4-history-ownership-normal.php"))
    args = parser.parse_args()
    try:
        result = execute(args)
        print(json.dumps({"scope": SCOPE, "status": result["status"], "container_id": result["container_id"],
                          "original_history_final_close": "NOT RUN"}, separators=(",", ":")))
        return 0
    except Exception as error:
        code = str(error) if isinstance(error, Stopped) and re.fullmatch("[A-Z0-9_]+", str(error)) else "POST_READER_RUNNER_STOPPED"
        print(json.dumps({"scope": SCOPE, "status": "STOPPED_STATE_PRESERVED", "code": code,
                          "automatic_retry": False, "failed_cleanup": "NOT PERFORMED"}, separators=(",", ":")))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
