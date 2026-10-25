"""SOURCE DESIGN BEFORE IMPLEMENTATION; Root+nonauthor review before runtime.

Ordinary retirement only for exact failed normal verify Run20261025095534ceb090,
Source92c6eaf55947ffc175335e025ff91457e28989ad, frozen independent Val-r3,
Host-r3 38985/90e4cfeb and Source PINS19961/a95bf9e4. No normal retry,
App restart, failure reclassification, production Host bypass or force is added.

Root actual SETUP completed0. Original146-entry ledger/292 complete streams has
only146 verify exit255: stdout empty, exact547-byte PHP/ThinkPHP fatal memory
exhaustion at native128MiB during complete-table hashing in normal.php:50.
Original145 prepare completed0. NORMAL and verify-success are absent. OOM did
not execute the normal finally; its App group was still running, not closed.

Root subsequently used the original harness audit_app_stop through the same
runner, original App supervisor/registered child handle, actual5 same-PID native
commands all0. Fixed BEFORE/AFTER group/control and6125-byte Root RESULT prove
the same registered group was stopped by ordinary TERM-only, members[] and closed
listener, no force. Root's first attempt was only3 readonly preflight operations:
raw Mounts order differed, complete multiset/count was identical; no stop had
been dispatched, so it is neither business failure nor a repeated signal.

Requirements and design before implementation: keep this failure/SETUP/146
ledger/whole streams/public preparation, and Root stop public proof immutable.
Use explicit actual SETUP and FAILURE SHA CLI inputs fixed to this exact Run;
zero still requires actual completed close and all0 full cleanup streams. Bind
the same Host/Source/Val/context/engine/five roles/thirteen volume generations,
full mount-object multisets with duplicate counts, UID/private root and only-own
consumers. Import only the reviewed fixed Host, no private config or fixture.

Sole resource baseline is the complete r2 failed-verify retire Source25386/
3096486cd15c2d45f13c687489543ac3a781271d7ad9128b1fd1f9c0d0fb2dd1.
All14 mature resource functions raw-inverse to that Source; only fixed r3 names
and result labels differ. Add a strict memory-failure reader and strict Root
ordinary-stop proof reader; no relaxed resource gates or generic failed bypass.
All original5 resident CID generations receive explicit SIGTERM timeout -1 only
after immediate same-generation/complete mount/UID/only-own checks; require
natural Exited0/PID0/no restart before nonforce exact5CID/13 original volumes/
original empty internal network removal. Remove only exact Val-r3 own install
lock by stable path/handle. Independent zero makes6 fresh project/run single-
label queries and proves both raw streams empty, with full native evidence.

Only this new external Source is authored. Frozen W/Val/Host/Run, failure/native
streams and Root stop artifacts are never patched/replayed or used as normal
PASS. Future cleanup output/PIDs/receipts stay UNBOUND. Author/reviewers execute
no candidate, Docker/PHP/SQL/HTTP/target, secret, commit/push or canonical write.
Successful resource retirement cannot accept normal business, external channel,
browser/device, fullA/B/V1, original37 history, originalG cases or wholeTask2.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys

ORG = Path("H:/天俪商城/artifacts/project-organization-20261023")
HOST = ORG / "legacy-refund-normal-host-source-r3/legacy-refund-normal-host.py"
HOST_SHA = "90e4cfeb7296001f28d9bd789d06e41b8a37c0c456603b8a0d6e86ac63aeb7d3"
HOST_PINS = "a95bf9e4729bd5f034026eb4b6a12d73cd6d4324d44fb9b56eeac2a28b601053"
RUN = "20261025095534ceb090"
COMMIT = "92c6eaf55947ffc175335e025ff91457e28989ad"
CLOSE_OUTPUT = ORG / "legacy-refund-normal-r3-memory-failed-close-r1"
ZERO_OUTPUT = ORG / "legacy-refund-normal-r3-memory-failed-zero-r1"
ORDER = ["audit-app", "audit-runner", "audit-bootstrap", "audit-mysql", "audit-redis"]
NET = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'

FAILURE_PINS = {
    "FAILURE.json": {
        "bytes": 217,
        "sha256": "73a6ca489018b48d074433e5d0ba00b21157d8040cf404bbaf8863961063b67d"
    },
    "SETUP.json": {
        "bytes": 44214,
        "sha256": "74c5d1044602c665bd48e310e9fc348b9c538c29d2b1c5ad1f381dc99a623782"
    },
    "receipts.jsonl": {
        "bytes": 124136,
        "sha256": "063696f888527975c2e0540d0843376f8b4b55ed7aab68c65c8146d720026788"
    },
    "0146-exec.json": {
        "bytes": 811,
        "sha256": "51e840c42f55a60f96f650020a1387656a29fd60e10edbe1fd646d458bc8e9cc"
    },
    "0146-exec.running.json": {
        "bytes": 463,
        "sha256": "f910fa281db84b64b89fb0eb105c4b09f60bf544d6cc142a852d4b6a04442515"
    },
    "0146-exec.stdout.bin": {
        "bytes": 0,
        "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    },
    "0146-exec.stderr.bin": {
        "bytes": 547,
        "sha256": "6ee505a5f7e6585f6632a5274c0680cdcf8bf1e0f313f1f766a565ab6922460d"
    },
    "app-status.json": {
        "bytes": 454,
        "sha256": "3a04e84cef6a3062df372a26a4de96be900288d6dab410224cf9ef064940eaa3"
    },
    "app-control.json": {
        "bytes": 120,
        "sha256": "bf60af108f3af419870d8478e38c493b4f7e73ec6730ce327a99c9c175f74150"
    },
    "evidence/ms4-legacy-refund-private-prepare.json": {
        "bytes": 355,
        "sha256": "2560886911c34676796100f5b8f6f5e80f2fbf5b18b77827fcb87692dd3149fd"
    }
}

STOP_DIR = ORG / "legacy-refund-normal-r3-app-ordinary-stop-r2"
STOP_PINS = {
    "RESULT.json": {
        "bytes": 6125,
        "sha256": "2bc0fa008b829206c98014393a72ec80d14c8f6077c99c6940b155705ca596f3"
    },
    "BEFORE-app-status.json": {
        "bytes": 1066,
        "sha256": "21b0d7f58870a1e89f2ed654335cffa006d3a6f15e798b170c66743459ad54ad"
    },
    "BEFORE-app-control.json": {
        "bytes": 265,
        "sha256": "69fbc77c35c536d1aeff0e9a5353a5ef64d65e9d4087b6dde59c8ac188ba28b3"
    },
    "AFTER-app-status.json": {
        "bytes": 454,
        "sha256": "3a04e84cef6a3062df372a26a4de96be900288d6dab410224cf9ef064940eaa3"
    },
    "AFTER-app-control.json": {
        "bytes": 120,
        "sha256": "bf60af108f3af419870d8478e38c493b4f7e73ec6730ce327a99c9c175f74150"
    }
}
ROOT_READBACK = ORG / "legacy-refund-normal-r3-launcher-r1/ROOT-NORMAL-MEMORY-FAILURE-READBACK.json"
ROOT_READBACK_PIN = {"bytes": 1256, "sha256": "63afc44484b4118efebc1be2ebf41d01d5e3b9d528e8f0f972725bf74bb1dbb5"}
STOP_CODE_SHA = "5320b1217af8258a5aee442b7a35e7a0a404c96fdc2b1e178bd2ff4c5e7523c5"
MEMORY_STDERR = b'PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 4198400 bytes) in /workspace/help/audit-regressions/isolated/ms4-legacy-refund-private-normal.php on line 50\n\n\n                                                                                      \n  [think\\exception\\ErrorException]                                                    \n  Allowed memory size of 134217728 bytes exhausted (tried to allocate 4198400 bytes)  \n                                                                                      \n\n\n'


def require(ok, code):
    if not ok:
        raise RuntimeError(code)

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def host_source():
    require(HOST.is_file() and not HOST.is_symlink() and sha(HOST) == HOST_SHA, "REVIEWED_FRESH_HOST6")
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location("legacy_refund_normal_r3_memory_failed_retire_host", HOST)
    host = importlib.util.module_from_spec(spec); spec.loader.exec_module(host)
    host.packet(HOST_PINS)
    require(host.COMMIT == COMMIT and host.VAL == Path("H:/天俪商城/worktrees/ms4-legacy-refund-normal-check-r3"),
            "FIXED_FRESH_COMMIT_VAL")
    return host

def pinned(host, path, expected):
    require(re.fullmatch(r"[a-f0-9]{64}", expected or "") is not None, "ACTUAL_RECEIPT_SHA_REQUIRED")
    host.regular(path); require(sha(path) == expected, "ACTUAL_RECEIPT_CHANGED")
    return host.json_read(path)

def streams(host, directory, expected):
    require(host.digest(directory / "receipts.jsonl") == expected, "COMPLETE_NATIVE_LEDGER_PIN")
    rows = [json.loads(line) for line in (directory / "receipts.jsonl").read_bytes().splitlines()]
    require(rows and [r["sequence"] for r in rows] == list(range(1, len(rows) + 1)), "ORDERED_NATIVE_SEQUENCE")
    for row in rows:
        stem = f"{row['sequence']:04d}-{row['label']}"
        require(Path(stem).name == stem and row["same_handle_wait"] is True and row["natural_exit"] == 0
                and row["interrupted"] is False and type(row["pid"]) is int and row["pid"] > 0
                and (directory / (stem + ".json")).read_bytes() == host.encoded(row), "ALL_REAL_NATIVES_NATURAL_ZERO")
        running = host.json_read(directory / (stem + ".running.json"))
        require(running["pid"] == row["pid"] and running["argv"] == row["argv"]
                and running["sequence"] == row["sequence"] and running["status"] == "RUNNING_SAME_HANDLE", "SAME_NATIVE_HANDLE")
        for name in ("stdout", "stderr"):
            item = row[name]
            require(Path(item["file"]).name == item["file"] and host.digest(directory / item["file"]) ==
                    {"bytes": item["bytes"], "sha256": item["sha256"]}, "COMPLETE_RAW_DUAL_STREAMS")


def failed_verify_streams(host, directory, setup):
    require(host.digest(directory / "receipts.jsonl") == FAILURE_PINS["receipts.jsonl"], "COMPLETE_NATIVE_LEDGER_PIN")
    rows = [json.loads(line) for line in (directory / "receipts.jsonl").read_bytes().splitlines()]
    require(len(rows) == 146 and [r["sequence"] for r in rows] == list(range(1, 147)), "EXACT_ORIGINAL_146_NATIVE_SEQUENCE")
    for row in rows:
        stem = f"{row['sequence']:04d}-{row['label']}"
        require(Path(stem).name == stem and row["same_handle_wait"] is True and row["natural_exit"] == (255 if row["sequence"] == 146 else 0)
                and row["interrupted"] is False and type(row["pid"]) is int and row["pid"] > 0
                and (directory / (stem + ".json")).read_bytes() == host.encoded(row), "ONLY_REAL_NATIVE_146_VERIFY_MEMORY_EXIT_255")
        running = host.json_read(directory / (stem + ".running.json"))
        require(running["pid"] == row["pid"] and running["argv"] == row["argv"]
                and running["sequence"] == row["sequence"] and running["status"] == "RUNNING_SAME_HANDLE", "SAME_NATIVE_HANDLE")
        for name in ("stdout", "stderr"):
            item = row[name]
            require(Path(item["file"]).name == item["file"] and host.digest(directory / item["file"]) ==
                    {"bytes": item["bytes"], "sha256": item["sha256"]}, "COMPLETE_RAW_DUAL_STREAMS")
    raw = (directory / "receipts.jsonl").read_bytes()
    setup_ledger = b"".join(raw.splitlines(keepends=True)[:108])
    require(setup["receipts"] == {"bytes": len(setup_ledger), "sha256": hashlib.sha256(setup_ledger).hexdigest()},
            "ORIGINAL_COMPLETE_108_SETUP_NATIVE_PREFIX")
    for sequence, service, phase, pid in [(145, "audit-bootstrap", "prepare", 13164),
                                         (146, "audit-runner", "verify", 10020)]:
        row = rows[sequence - 1]
        require(row["label"] == "exec" and row["pid"] == pid and row["argv"] ==
                [host.DOCKER, "--context", setup["context"], "exec", setup["native"]["services"][service]["id"],
                 "php", host.ENTRY, phase], "EXACT_ORIGINAL_PREPARE_VERIFY_NATIVE_GENERATIONS")
    require((directory / "0146-exec.stdout.bin").read_bytes() == b""
            and (directory / "0146-exec.stderr.bin").read_bytes() == MEMORY_STDERR,
            "EXACT_ACTUAL_VERIFY_MEMORY_FATAL_NOT_BUSINESS_PASS")

def root_stop_streams(host, setup):
    for name, expected in STOP_PINS.items():
        path = STOP_DIR / name; host.regular(path)
        require(host.digest(path) == expected, "EXACT_ROOT_ORDINARY_STOP_PUBLIC_PIN")
    result = host.json_read(STOP_DIR / "RESULT.json")
    require(result["status"] == "ROOT_ORDINARY_APP_GROUP_CLOSED_AFTER_RUNNER_MEMORY_FAILURE"
            and result["run_id"] == RUN and result["original_application_supervisor_and_child_handle_used"] is True
            and result["force_signal_sent"] is False and result["remaining_members"] == []
            and result["listener_closed"] is True and result["business_normal"] == "FAILED_NO_REPLAY",
            "ACTUAL_ROOT_APP_STOP_IS_RETIREMENT_NOT_BUSINESS_PASS")
    stems = ["001-context-endpoint", "002-same-engine", "003-same-audit-app",
             "004-same-audit-runner", "005-original-harness-ordinary-stop"]
    rows = result["operations"]; require(len(rows) == 5, "EXACT_FIVE_ROOT_ORDINARY_STOP_NATIVES")
    for stem, row in zip(stems, rows):
        require(host.json_read(STOP_DIR / (stem + ".terminal.json")) == row
                and row["native_exit"] == row["wait_returncode"] == 0 and row["stderr"]["bytes"] == 0
                and type(row["pid"]) is int and row["pid"] > 0, "ROOT_STOP_ALL_NATURAL_ZERO")
        created = host.json_read(STOP_DIR / (stem + ".created.json"))
        require(created["argv"] == row["argv"] and created["pid"] == row["pid"]
                and row["argv"][:3] == [host.DOCKER, "--context", setup["context"]], "ROOT_STOP_SAME_NATIVE_HANDLE")
        for side in ("stdout", "stderr"):
            path = STOP_DIR / (stem + "." + side); host.regular(path)
            require(host.digest(path) == row[side], "ROOT_STOP_COMPLETE_RAW_DUAL_STREAMS")
    require(json.loads((STOP_DIR / "001-context-endpoint.stdout").read_bytes()).startswith("npipe://")
            and (STOP_DIR / "002-same-engine.stdout").read_text().strip() == setup["engine"], "ROOT_STOP_SAME_LOCAL_ENGINE")
    for offset, service in [(2, "audit-app"), (3, "audit-runner")]:
        row = rows[offset]
        require(row["argv"] == [host.DOCKER, "--context", setup["context"], "inspect", "--format", host.CID_FORMAT,
                                setup["native"]["services"][service]["id"]], "ROOT_STOP_ORIGINAL_ROLE_GENERATION")
        current = host.json_read(STOP_DIR / (stems[offset] + ".stdout"))
        require(same_container(host, current, setup["native"]["services"][service])
                and current["state"]["Running"] is True, "ROOT_STOP_COMPLETE_NATIVE_MOUNT_MULTISET_AND_STATE")
    argv = rows[-1]["argv"]
    require(argv[:6] == [host.DOCKER, "--context", setup["context"], "exec",
                         setup["native"]["services"]["audit-runner"]["id"], "php"]
            and len(argv) == 8 and argv[6] == "-r" and hashlib.sha256(argv[7].encode()).hexdigest() == STOP_CODE_SHA,
            "ORIGINAL_HARNESS_SINGLE_STOP_REQUEST_ONLY")
    require((STOP_DIR / "005-original-harness-ordinary-stop.stdout").read_bytes() ==
            b'{"status":"ROOT_ORIGINAL_APP_GROUP_STOPPED","members":[],"listener_closed":true,"stop_mode":"term-only","force_signal_sent":false}\n',
            "EXACT_ROOT_ORIGINAL_STOP_ACK")
    before = host.json_read(STOP_DIR / "BEFORE-app-status.json"); after = host.json_read(STOP_DIR / "AFTER-app-status.json")
    before_control = host.json_read(STOP_DIR / "BEFORE-app-control.json"); after_control = host.json_read(STOP_DIR / "AFTER-app-control.json")
    require(before["run_id"] == before_control["run_id"] == after["run_id"] == after_control["run_id"] == RUN
            and before["status"] == before_control["action"] == "start" and len(before["members"]) == 3
            and before["group"] == after["stopped_group"] and after["generation_revision"] == ""
            and after["revision"] == after_control["revision"] and after_control["action"] == "stop"
            and after_control["overrides"] == [] and after["status"] == "stop" and after["stop_mode"] == "term-only"
            and after["term_sent"] is True and after["force_signal_sent"] is False
            and after["members"] == after["remaining_members"] == [] and after["listener_closed"] is True
            and after["listener_open"] is False, "SAME_REGISTERED_APP_GROUP_ACTUAL_TERM_ONLY_CLOSED")
    require(host.digest(STOP_DIR / "AFTER-app-status.json") == result["app_status"] == FAILURE_PINS["app-status.json"]
            and host.digest(STOP_DIR / "AFTER-app-control.json") == result["app_control"] == FAILURE_PINS["app-control.json"],
            "ROOT_AFTER_AND_ORIGINAL_CURRENT_PUBLIC_STOP_IDENTICAL")

def inputs(host, args):
    original = host.Caller(RUN).run_dir
    host.regular(ROOT_READBACK)
    require(host.digest(ROOT_READBACK) == ROOT_READBACK_PIN, "EXACT_ROOT_COMPLETE_FAILED_VERIFY_AND_STOP_READBACK")
    root = host.json_read(ROOT_READBACK)
    require(root["status"] == "ROOT_REFUND_R3_FAILED_VERIFY_MEMORY_FULL_READBACK_APP_ORDINARY_STOPPED"
            and root["run_id"] == RUN and root["source"] == COMMIT and root["native"] == 146
            and root["full_dualstream_bytes"] == 11364329 and root["only_native_failure"] == 146
            and root["native146_exit"] == 255 and root["native146_stdout_bytes"] == 0 and root["native146_stderr_bytes"] == 547
            and root["app_group_stop_result"] == STOP_PINS["RESULT.json"] and root["stop_native"] == 5
            and root["stop_full_dualstream_bytes"] == 9035 and root["group_members_empty_listener_closed_TERM_only_no_force"] is True
            and root["original_not_replayed"] is True and root["normal_business"] == "FAILED_INCOMPLETE",
            "ROOT_INDEPENDENT_COMPLETE_MEMORY_FAILURE_NOT_BUSINESS_PASS")
    setup = pinned(host, original / "SETUP.json", args.setup_sha256)
    failure = pinned(host, original / "FAILURE.json", args.failure_sha256)
    for name, expected in FAILURE_PINS.items():
        path = original / name; host.regular(path)
        require(host.digest(path) == expected, "EXACT_PUBLIC_FAILED_VERIFY_SOURCE_PIN")
    require(not (original / "NORMAL.json").exists()
            and not (original / "evidence/ms4-legacy-refund-private-verify.json").exists()
            and setup["status"] == "SETUP_COMPLETE_NOT_BUSINESS_VERIFIED" and setup["run_id"] == RUN
            and setup["commit"] == COMMIT and setup["pins_sha256"] == HOST_PINS
            and failure == {"cleanup": "NOT ATTEMPTED", "error_class": "Blocked", "phase": "normal",
                "reason_code": "NATIVE_FAILED_SCENE_RETAINED", "retry": "NOT AUTHORIZED", "sequence": 146,
                "status": "BLOCKED_SCENE_RETAINED"}, "EXACT_ACTUAL_FAILED_VERIFY_ONLY")
    require(set(setup["native"]["services"]) == set(ORDER) and len(setup["native"]["volumes"]) == 13
            and host.digest(original / "manifest.json") == setup["manifest"], "ORIGINAL_FIVE_THIRTEEN_MANIFEST")
    require(setup["mysql_sql_mode"]["status"] == "LEGACY_REFUND_MYSQL_STRICT_AFTER_LEGACY_SEED"
            and setup["mysql_sql_mode"]["preserved_flags_plus_strict"] is True
            and setup["mysql_sql_mode"]["fresh_dml_connection"] is True, "SOURCE_QUALIFIED_FRESH_SETUP")
    failed_verify_streams(host, original, setup)
    root_stop_streams(host, setup)
    status = host.json_read(original / "app-status.json")
    control = host.json_read(original / "app-control.json")
    prepare = host.json_read(original / "evidence/ms4-legacy-refund-private-prepare.json")
    require(status["run_id"] == control["run_id"] == RUN and status["revision"] == control["revision"]
            and control["action"] == "stop" and control["overrides"] == []
            and status["status"] == "stop" and status["stop_mode"] == "term-only"
            and status["term_sent"] is True and status["force_signal_sent"] is False
            and status["remaining_members"] == [] and status["members"] == []
            and status["listener_closed"] is True and status["listener_open"] is False,
            "REAL_FAILED_VERIFY_APP_ALREADY_TERM_CLOSED")
    require(prepare["scope"] == "CU_LEGACY_REFUND_PREPARATION_ONLY"
            and prepare["status"] == "PREPARED_NOT_HTTP_VERIFIED" and prepare["source_sha"] == COMMIT
            and prepare["synthetic_legacy_families"] == 3 and prepare["actual_equalSplit"] is True
            and prepare["business_HTTP"] == "NOT RUN" and prepare["acceptance"] == "INCOMPLETE",
            "ACTUAL_PREPARE_SUCCESS_IS_NOT_VERIFY_SUCCESS")
    return setup, failure

def evidence_caller(host, output, setup):
    class EvidenceCaller(host.Caller):
        def native(self, label, argv):
            original = self.run_dir; self.run_dir = output
            try:
                return super().native(label, argv)
            finally:
                self.run_dir = original
    caller = EvidenceCaller(RUN); caller.context = setup["context"]; caller.images = setup["images"]
    return caller

def same_container(host, left, right):
    return host.scene_projection({"services": {"one": left}}) == host.scene_projection({"services": {"one": right}})

def native_container(host, caller, cid):
    template = host.CID_FORMAT[:-1] + ',"restart_count":{{json .RestartCount}}}'
    return json.loads(caller.docker("original-container", "inspect", "--format", template, cid))

def terminal_same(host, before, after):
    keys = ["id", "image_id", "mounts", "labels", "read_only", "privileged", "network_mode",
            "user", "entrypoint", "command", "working_dir", "restart_policy"]
    require(same_container(host, {key: after[key] for key in keys}, {key: before[key] for key in keys})
            and after["state"]["StartedAt"] == before["state"]["StartedAt"] and after["restart_count"] == 0
            and after["state"]["Status"] == "exited" and after["state"]["Running"] is False
            and after["state"]["Pid"] == 0 and after["state"]["ExitCode"] == 0, "SAME_ORIGINAL_EXITED_ZERO_PID0")

def labels(caller, rows):
    expected = {"com.docker.compose.project": caller.project, "tianli.audit.run": RUN, "tianli.audit": "1"}
    return all(all(row["labels"].get(k) == value for k, value in expected.items()) for row in rows)

def queries(caller, expected):
    result = []
    for kind, argv in [("container", ["ps", "-aq", "--no-trunc"]),
                       ("network", ["network", "ls", "--no-trunc", "-q"]), ("volume", ["volume", "ls", "-q"])]:
        for label, value in [("project", "com.docker.compose.project=" + caller.project), ("run", "tianli.audit.run=" + RUN)]:
            actual = caller.docker("resource-" + kind + "-" + label, *argv, "--filter", "label=" + value).decode().split()
            require(sorted(actual) == sorted(expected[kind]), "EXACT_ACTUAL_RESOURCE_SET")
            result.append({"kind": kind, "label": label, "values": actual})
    return result

def volume_consumers(caller, names, inventory=None):
    for name in names:
        expected = set() if inventory is None else {row["id"] for row in inventory.values()
                    if any(m["Type"] == "volume" and m.get("Name") == name for m in row["mounts"])}
        actual = caller.docker("only-own-volume", "ps", "-aq", "--no-trunc", "--filter", "volume=" + name).decode().split()
        require(len(actual) == len(set(actual)) and set(actual) == expected, "ONLY_ACTUAL_OWN_VOLUME_CONSUMERS")

def fresh_scene(host, caller, setup):
    expected = setup["native"]; services = {}
    for service, before in expected["services"].items():
        actual = native_container(host, caller, before["id"]); current = {key: actual[key] for key in before}
        require(same_container(host, current, before) and actual["restart_count"] == 0
                and current["state"]["Running"] is True, "SAME_ORIGINAL_RUNNING_CONTAINER")
        if service in ("audit-app", "audit-bootstrap", "audit-runner"):
            require(current["image_id"] == host.PHP_IMAGE and current["read_only"] is True
                    and current["privileged"] is False, "ORIGINAL_PHP_CONFIGURATION")
            caller.mounts(current, service)
        else:
            require(current["image_id"] == caller.images[service], "ORIGINAL_DATA_IMAGE"); caller.data_mounts(current, service)
        services[service] = current
    network = json.loads(caller.docker("original-network", "network", "inspect", "--format", NET, expected["network"]["Id"]))
    require(set(network["Containers"]) == {row["id"] for row in services.values()}, "EXACT_FIVE_NETWORK_CONSUMERS")
    scene = {"network": {key: network[key] for key in expected["network"]}, "services": services,
             "volumes": [json.loads(caller.docker("original-volume", "volume", "inspect", "--format", host.VOL_FORMAT, v["name"])) for v in expected["volumes"]]}
    require(host.scene_projection(scene) == host.scene_projection(expected), "SAME_FULL_SCENE_COMPLETE_MOUNTS")
    host.new(CLOSE_OUTPUT / "FRESH-SCENE.json", host.encoded(scene)); return scene

def close(host, caller, setup):
    caller.local_engine(setup["engine"]); scene = fresh_scene(host, caller, setup)
    inventory = scene["services"]; cids = sorted(row["id"] for row in inventory.values())
    network_id = scene["network"]["Id"]; names = sorted(v["name"] for v in scene["volumes"])
    require(set(inventory) == set(ORDER) and len(set(cids)) == 5 and len(set(names)) == 13
            and names == sorted(caller.project + "_" + name for name in host.VOLUMES)
            and labels(caller, list(inventory.values()) + scene["volumes"]) and scene["network"]["Internal"] is True, "EXACT_OWN_FIVE_THIRTEEN_INTERNAL")
    queries(caller, {"container": cids, "network": [network_id], "volume": names}); volume_consumers(caller, names, inventory)
    require(caller.identities(scene) == setup["identities"], "ORIGINAL_ACTUAL_UID_AND_PRIVATE_ROOT")
    caller.execute(inventory["audit-bootstrap"]["id"], host.HARNESS, "__authorize_cleanup")
    help_bytes = caller.docker("ordinary-stop-options", "container", "stop", "--help")
    require(b"--signal" in help_bytes and b"--timeout" in help_bytes, "EXPLICIT_TERM_INFINITE_WAIT_SUPPORT")
    stopped = {}
    for service in ORDER:
        before = inventory[service]; cid = before["id"]; immediate = native_container(host, caller, cid)
        require(same_container(host, {key: immediate[key] for key in before}, before)
                and immediate["restart_count"] == 0, "IMMEDIATE_ORIGINAL_BEFORE_TERM")
        require(caller.docker("ordinary-term-" + service, "stop", "--signal", "SIGTERM", "--timeout", "-1", cid)
                == (cid + "\n").encode(), "EXACT_ORDINARY_TERM_NATIVE_ACK")
        after = native_container(host, caller, cid); terminal_same(host, before, after); stopped[service] = after
    for service in ORDER:
        actual = native_container(host, caller, inventory[service]["id"])
        terminal_same(host, inventory[service], actual); require(same_container(host, actual, stopped[service]), "TERMINALS_HELD_BEFORE_RM")
    queries(caller, {"container": cids, "network": [network_id], "volume": names})
    require(caller.docker("nonforce-five-rm", "rm", *cids) == ("\n".join(cids) + "\n").encode(), "EXACT_NONFORCE_FIVE_RM")
    network = json.loads(caller.docker("empty-original-network", "network", "inspect", "--format", NET, network_id))
    require({key: network[key] for key in scene["network"]} == scene["network"]
            and network["Containers"] == {}, "SAME_EMPTY_ORIGINAL_NETWORK")
    caller.docker("nonforce-network-rm", "network", "rm", network_id)
    queries(caller, {"container": [], "network": [], "volume": names})
    volumes = [json.loads(caller.docker("same-volume-created", "volume", "inspect", "--format", host.VOL_FORMAT, name)) for name in names]
    require(volumes == scene["volumes"] and labels(caller, volumes), "SAME_THIRTEEN_VOLUME_IDENTITIES")
    volume_consumers(caller, names)
    require(caller.docker("nonforce-thirteen-rm", "volume", "rm", *names)
            == ("\n".join(names) + "\n").encode(), "EXACT_NONFORCE_THIRTEEN_RM")
    lock = host.VAL / "crmeb/public/install.lock"; before = host.regular(lock)
    with lock.open("rb") as stream:
        opened = os.fstat(stream.fileno())
        require(host.cross_api_identity(opened) == host.cross_api_identity(before)
                and stream.read() == caller.lock_bytes(), "ONLY_EXACT_COMPLETE_NEW_OWN_LOCK")
        finished = os.fstat(stream.fileno()); after = host.regular(lock)
        require(host.file_identity(opened) == host.file_identity(finished) and host.file_identity(before) == host.file_identity(after)
                and host.cross_api_identity(finished) == host.cross_api_identity(after), "OWN_LOCK_PATH_HANDLE_HELD")
    require(host.file_identity(host.regular(lock)) == host.file_identity(after), "OWN_LOCK_IMMEDIATE_IDENTITY"); lock.unlink()
    return {"status": "LEGACY_REFUND_R3_MEMORY_FAILED_ORDINARY_CLOSE_COMPLETE_ZERO_PENDING", "same_original_terminal_zero": stopped,
            "exact_removed": {"containers": cids, "network": network_id, "volumes": names}, "own_lock_removed": str(lock)}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("close", "zero"))
    parser.add_argument("--setup-sha256", required=True); parser.add_argument("--failure-sha256", required=True)
    parser.add_argument("--close-sha256")
    args = parser.parse_args(); require((args.close_sha256 is None) == (args.phase == "close"), "EXPLICIT_CLOSE_ZERO_PHASE")
    host = host_source(); setup, failure = inputs(host, args)
    previous = None
    if args.phase == "zero":
        previous = pinned(host, CLOSE_OUTPUT / "CLOSE.json", args.close_sha256)
        require(previous["status"] == "LEGACY_REFUND_R3_MEMORY_FAILED_ORDINARY_CLOSE_COMPLETE_ZERO_PENDING" and previous["run_id"] == RUN
                and previous["commit"] == COMMIT and previous["source_sha256"] == sha(Path(__file__))
                and previous["setup_sha256"] == args.setup_sha256 and previous["failure_sha256"] == args.failure_sha256
                and not (CLOSE_OUTPUT / "FAILURE.json").exists(), "ACTUAL_COMPLETED_FRESH_CLOSE_ONLY")
        streams(host, CLOSE_OUTPUT, previous["receipts"])
    output = CLOSE_OUTPUT if args.phase == "close" else ZERO_OUTPUT; caller = evidence_caller(host, output, setup); owned = False
    try:
        require(not output.exists() and not output.is_symlink(), "FRESH_SEPARATE_OUTPUT"); output.mkdir(mode=0o700); owned = True
        sid_raw = caller.native("current-sid", ["C:/Windows/System32/whoami.exe", "/user", "/fo", "csv", "/nh"]).decode("utf-8", "replace")
        sid = list(host.csv.reader(host.io.StringIO(sid_raw)))[0][-1]; require(re.fullmatch(r"S-[0-9-]+", sid), "CURRENT_PRIVATE_SID")
        caller.native("private-acl", ["C:/Windows/System32/icacls.exe", str(output), "/inheritance:r", "/grant:r", "*" + sid + ":(OI)(CI)F", "*S-1-5-18:(OI)(CI)F"])
        if args.phase == "close":
            result = close(host, caller, setup)
        else:
            caller.local_engine(setup["engine"]); result_queries = queries(caller, {"container": [], "network": [], "volume": []})
            require(len(result_queries) == 6 and caller.sequence >= 6, "EXACT_SIX_FRESH_ZERO_NATIVE_RECORDS")
            first = caller.sequence - 5
            for offset, query in enumerate(result_queries):
                sequence = first + offset; label = "resource-" + query["kind"] + "-" + query["label"]
                stem = f"{sequence:04d}-{label}"; row = host.json_read(output / (stem + ".json"))
                require(row["sequence"] == sequence and row["label"] == label and row["natural_exit"] == 0
                        and row["same_handle_wait"] is True, "FRESH_ZERO_ORIGINAL_NATIVE_TERMINAL")
                for side in ("stdout", "stderr"):
                    item = row[side]; require(Path(item["file"]).name == item["file"], "FRESH_ZERO_STREAM_BASENAME")
                    path = output / item["file"]; host.regular(path)
                    require(item["bytes"] == 0 and item["sha256"] == hashlib.sha256(b"").hexdigest()
                            and path.read_bytes() == b"", "SIX_FRESH_ZERO_RAW_BOTH_STREAMS_EMPTY")
            lock = host.VAL / "crmeb/public/install.lock"
            require(len(result_queries) == 6 and not lock.exists() and not lock.is_symlink(), "INDEPENDENT_SIX_ZERO_OWN_LOCK_ABSENT")
            result = {"status": "LEGACY_REFUND_R3_MEMORY_FAILED_INDEPENDENT_SIX_QUERY_ZERO", "queries": result_queries, "close_sha256": args.close_sha256}
        inputs(host, args)
        result.update(run_id=RUN, commit=COMMIT, setup_sha256=args.setup_sha256, failure_sha256=args.failure_sha256,
                      source_sha256=sha(Path(__file__)), host_pins_sha256=HOST_PINS, receipts=host.digest(output / "receipts.jsonl"),
                      retirement_only=True, business_browser_device_release="NOT ACCEPTED BY RETIREMENT", full_acceptance="INCOMPLETE")
        path = output / ("CLOSE.json" if args.phase == "close" else "ZERO.json"); host.new(path, host.encoded(result))
        print(json.dumps({"status": result["status"], "receipt": str(path), **host.digest(path)}, ensure_ascii=False)); return 0
    except BaseException as error:
        if owned and not (output / "FAILURE.json").exists():
            host.new(output / "FAILURE.json", host.encoded({"status": "INCOMPLETE_LEGACY_REFUND_R3_MEMORY_FAILED_RETIREMENT_SCENE_RETAINED", "phase": args.phase,
                     "error_class": type(error).__name__, "sequence": caller.sequence, "force_retry_or_cleanup": "NOT ATTEMPTED"}))
        print('{"status":"INCOMPLETE_LEGACY_REFUND_R3_MEMORY_FAILED_RETIREMENT_SCENE_RETAINED"}'); return 2
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        print('{"status":"BLOCKED_BEFORE_LEGACY_REFUND_R3_MEMORY_FAILED_RETIREMENT"}'); sys.exit(2)
