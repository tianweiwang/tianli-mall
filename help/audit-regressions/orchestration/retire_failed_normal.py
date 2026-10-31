"""Retire one pinned failed normal Worker: four CIDs, nine volumes, one network.

This is not successful Worker close or acceptance. Resource steps derive from
R90 ordinary-failed-retirement.py (17945 bytes, SHA256
42138c373563cf6c3773fc46583ece9200a06330495061cc725459dae591e127),
actually completed by original PID22392/native-wait0. That artifact is provenance,
not a runtime dependency. All ownership and INI inputs retain the original context;
only observation recording goes to a fresh external output directory.
"""
from __future__ import annotations

import argparse
import ast
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time


HOST_PIN = {"bytes": 402025, "sha256": "9d3f34f28cdd944af53be54861b19d5eb9bc89c32d94a607efaa4857201a13e7"}
HELPERS_PIN = {"bytes": 32130, "sha256": "6bb62892cdab1947dce13ab0f2567cab52e1fa079ebb6ebd7c7edb1dbeea4591"}
INI_FUNCTION_PIN = {"bytes": 2862, "sha256": "8e50b5f364484f041ba53808b72e3e867363da41b7eef7491ee2b93f123a3817"}
ORIGINAL_RETIREMENT = {"bytes": 17945, "sha256": "42138c373563cf6c3773fc46583ece9200a06330495061cc725459dae591e127", "pid": 22392, "native_exit": 0, "wait_returncode": 0}
VOLUME_SUFFIXES = {"app-secrets", "bootstrap-secrets", "fixture-secrets", "mysql-data", "redis-data", "redis-secrets", "aftersale-evidence", "customer-form-evidence", "source-tree"}
INI_SUFFIXES = (".created.json", ".native.json", ".stdout", ".stderr", ".record.json")


def require(value, code):
    if not value:
        raise RuntimeError(code)


def pin(raw):
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def load_helpers():
    path = Path(__file__).with_name("retire_failed_history.py")
    raw = path.read_bytes()
    require(pin(raw) == HELPERS_PIN, "EXACT_UNCHANGED_PURE_SNAPSHOT_PREVIEW_HELPERS")
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location("failed_normal_pure_helpers", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def external_ini_observer(host, host_raw, output):
    """Exactly two output roots change; original input/context/INI checks do not."""
    text = host_raw.decode("utf-8")
    node = next(item for item in ast.parse(text).body if isinstance(item, ast.FunctionDef) and item.name == "proof_ini_environment")
    original = "".join(text.splitlines(keepends=True)[node.lineno - 1:node.end_lineno])
    require(pin(original.encode("utf-8")) == INI_FUNCTION_PIN, "EXACT_ORIGINAL_INI_OBSERVER_BODY")
    changes = (
        ("folder = Path(context['run_dir']) / PROOF_INI_ENV_FOLDER", "folder = __failed_normal_output_root / PROOF_INI_ENV_FOLDER"),
        ("raw = proof_ini_raw(Path(context['run_dir']) / leaf)", "raw = proof_ini_raw(folder / (stem + suffix))"),
    )
    derived = original
    for before, after in changes:
        require(derived.count(before) == 1 and after not in derived, "UNIQUE_EXACT_INI_OUTPUT_ROOT_SEAM")
        derived = derived.replace(before, after, 1)
    inverse = derived
    for before, after in reversed(changes):
        require(inverse.count(after) == 1, "UNIQUE_INI_OUTPUT_INVERSE")
        inverse = inverse.replace(after, before, 1)
    require(inverse == original, "WHOLE_INI_OBSERVER_EXACT_INVERSE_ALL_INPUT_ASSERTIONS_HELD")
    host.__dict__["__failed_normal_output_root"] = output
    exec(compile(derived, "<failed-normal-external-ini-observer>", "exec"), host.__dict__)
    return {"original": INI_FUNCTION_PIN, "derived": pin(derived.encode("utf-8")), "changed_output_roots": 2, "original_context_and_all_input_assertions_held": True}


def source_snapshot(helpers, root, manifest):
    """Read only the positively frozen tracked files; unknown non-overlay leaves fail."""
    files, directories = helpers.namespace(root)
    wanted = {row["path"]: row for row in manifest["files"]}
    require(len(wanted) == manifest["file_count"] and set(wanted) <= set(files), "COMPLETE_POSITIVE_FROZEN_SOURCE_FILES")
    excluded = [name for name in files if name not in wanted]
    require(all(name == ".git" or name.startswith(".git/") or name.startswith("crmeb/runtime/") for name in excluded), "NO_UNKNOWN_SOURCE_FILE_BEFORE_BODY_READ")
    rows = {}
    for name in files:
        row = helpers.filepin(root / name, name not in wanted)
        if name in wanted:
            require({key: row[key] for key in ("bytes", "sha256")} == {key: wanted[name][key] for key in ("bytes", "sha256")}, "COMPLETE_FROZEN_SOURCE_BODY")
        rows[name] = row
    return {"files": rows, "directories": directories, "directory_stat9": {name: helpers.stamp(root / name) for name in directories}}


def parser():
    value = argparse.ArgumentParser(description=__doc__)
    for name in ("host", "context", "source-authority", "classification", "preview-baseline"):
        value.add_argument("--" + name, required=True, type=Path)
        value.add_argument("--" + name + "-sha256", required=True)
    value.add_argument("--freeze-sha256", required=True)
    value.add_argument("--output", required=True, type=Path)
    return value


def execute(args):
    helpers = load_helpers()
    started = time.time_ns()
    fixed = {}
    def checked(name):
        path = getattr(args, name.replace("-", "_"))
        raw, pointer, facts = helpers.read_pointer(path, getattr(args, name.replace("-", "_") + "_sha256"))
        fixed[path.as_posix()] = facts
        return helpers.decode(raw), pointer
    host_raw, host_pointer, host_facts = helpers.read_pointer(args.host, args.host_sha256)
    require(pin(host_raw) == HOST_PIN, "ONLY_FIXED_REVIEWED_NORMAL_HOST")
    fixed[args.host.as_posix()] = host_facts
    original, context_pointer = checked("context")
    authority, authority_pointer = checked("source-authority")
    classification, classification_pointer = checked("classification")
    baseline, baseline_pointer = checked("preview-baseline")
    run = Path(original["run_dir"])
    require(args.context == run / "actual-run-context.json" and original["prep_dir"] == args.host.parent.as_posix(), "EXACT_ORIGINAL_CONTEXT_HOST")
    freeze_path = run / "preparation-source-binding.json"
    freeze_raw, freeze_pointer, freeze_facts = helpers.read_pointer(freeze_path, args.freeze_sha256)
    fixed[freeze_path.as_posix()] = freeze_facts
    frozen = helpers.decode(freeze_raw)
    require(original["freeze_sha256"] == args.freeze_sha256 and frozen["source_sha"] == original["source_sha"] and frozen["requested_repo_source"] == original["repo_dir"], "EXACT_ORIGINAL_FREEZE")
    require(set(authority) == {"schema", "scope", "status", "source_sha", "candidate_root", "validation_root", "capture", "independent_review", "baseline_profile"}
            and authority["schema"] == 1 and authority["scope"] == "R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY"
            and authority["status"] == "INDEPENDENTLY_REVIEWED_SOURCE_ONLY" and authority["source_sha"] == original["source_sha"]
            and authority["validation_root"] == original["repo_dir"], "ORIGINAL_NORMAL_SOURCE_ONLY_AUTHORITY")
    source, validation = Path(authority["candidate_root"]), Path(authority["validation_root"])
    require(source != validation, "DISTINCT_FROZEN_SOURCE_VALIDATION")
    roots = (run, source, validation, args.host.parent, Path(__file__).resolve().parents[3])
    helpers.ordinary(args.output, missing=True)
    require(not args.output.exists() and all(not args.output.is_relative_to(root) and not root.is_relative_to(args.output) for root in roots), "FRESH_EXTERNAL_OUTPUT_ONLY")
    require(not args.classification.is_relative_to(run), "EXTERNAL_CLASSIFICATION_ONLY")
    require(classification == {"schema": 1, "run_id": original["run_id"], "files": classification.get("files")} and type(classification["files"]) is dict, "EXACT_EXPLICIT_FILE_CLASSIFICATION")
    classes = classification["files"]
    require(classes and all(type(name) is str and name and not re.search(r"[\x00-\x1f\x7f\\:]", name)
            and all(part not in ("", ".", "..") for part in name.split("/")) and kind in ("public", "private_metadata_only") for name, kind in classes.items()), "POSITIVE_CLASSIFIED_NAMESPACE_NO_DEFAULT")
    require(all(kind == "private_metadata_only" for name, kind in classes.items() if Path(name).name in {".env", "private.env", "legacy.env"}), "KNOWN_PRIVATE_ENV_NEVER_OPENED")
    required_public = {"actual-run-context.json", "preparation-source-binding.json", "manifest.json", "evidence/worker-normal-created.json", "evidence/worker-normal-terminal.json", "evidence/paid-producer-created.json", "evidence/paid-producer-terminal.json"}
    require(all(classes.get(name) == "public" for name in required_public), "POSITIVE_ORIGINAL_OWNERSHIP_PUBLIC_INPUTS")
    require(not any((run / "evidence" / name).exists() for name in ("ms4-worker-normal.json", "worker-normal-result.json", "worker-normal-shutdown.json", "authorize-worker-cleanup-created.json", "ms4-worker-normal-close-result.json", "ms4-worker-normal-independent-zero.json")), "ONLY_FAILED_WORKER_BEFORE_SUCCESS_CLOSE")
    projects = baseline["preview_projects"]
    previews = {cid for ids in projects.values() for cid in ids}
    require(baseline["subnet"] == "10.252.240.96/28" and len(projects) == 2 and all(len(ids) == len(set(ids)) == 4 for ids in projects.values())
            and len(previews) == 8 and set(baseline["preview_projections_public_digests"]) == previews, "EXACT_ADOPTED_NORMAL_SUBNET_AND_EIGHT_PREVIEWS")
    # Classification is complete before original Host current()/any unknown Run body is opened.
    original_run = helpers.run_snapshot(run, classes)
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location("pinned_failed_normal_host", args.host)
    host = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(host)
    context = host.current(args.context)
    require(context == original, "ORIGINAL_CONTEXT_NOT_REPLACED")
    manifest = frozen["source_tree"]
    require(manifest["requested_repo_source"] == validation.as_posix() and manifest["source_sha"] == context["source_sha"], "ORIGINAL_COMPLETE_TRACKED_SOURCE_MANIFEST")
    sources = {root.as_posix(): source_snapshot(helpers, root, manifest) for root in (source, validation)}
    host.clean(source, context["source_sha"])
    host.clean(validation, context["source_sha"])
    expected = host.proof_expected(context, "worker-normal")
    require(len(expected) == 4 and set(expected.values()) == {"audit-ms4-worker-normal", "audit-ms5-paid-producer", "audit-mysql", "audit-redis"}, "EXACT_FOUR_ORIGINAL_NORMAL_ROLES")
    volumes = [row["name"] for row in context["initial_volumes"]]
    require(len(volumes) == len(set(volumes)) == 9 and set(volumes) == {context["project"] + "_" + suffix for suffix in VOLUME_SUFFIXES}, "EXACT_NINE_ORIGINAL_NORMAL_VOLUMES")
    # Cmd is immutable and all four original commands are fixed source literals, with no credential values.
    commands = {"audit-ms4-worker-normal": [host.WORKER_ENTRY, "normal"], "audit-ms5-paid-producer": [host.PAID_ENTRY, "produce"],
                "audit-mysql": ["mysqld", "--sql-mode=STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"], "audit-redis": ["/run/audit-redis/redis.conf"]}
    created = {}
    for cid, role in expected.items():
        row = context["initial_service_created"][role] if role in {"audit-mysql", "audit-redis"} else host.bound_created(context, "worker-normal" if role == "audit-ms4-worker-normal" else "paid-producer", role)
        require(row["id"] == cid and row["command"] == commands[role], "ONLY_FIXED_NONSECRET_ORIGINAL_COMMANDS")
        created[cid] = row
    args.output.mkdir()
    (args.output / "evidence").mkdir()
    out = args.output
    def save(name, value):
        with (out / name).open("xb") as stream:
            raw = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
            require(stream.write(raw) == len(raw), "FULL_CREATE_ONLY_PUBLIC_RECEIPT")
    save("actual.created.json", {"pid": os.getpid(), "argv": sys.argv, "started_ns": started, "scope": "FAILED_NORMAL_WORKER255_ORDINARY_RETIREMENT_NOT_SUCCESS_CLOSE"})
    save("inputs.json", {"host": host_pointer, "context": context_pointer, "freeze": freeze_pointer, "source_authority": authority_pointer,
         "classification": classification_pointer, "preview_baseline": baseline_pointer, "original_R90_retirement_provenance": ORIGINAL_RETIREMENT,
         "pure_helpers": {"path": Path(__file__).with_name("retire_failed_history.py").as_posix(), **HELPERS_PIN}, "recording_context_only_difference": {"run_dir": out.as_posix()}})
    save("original-files.json", original_run)
    save("frozen-two-trees.json", sources)
    ini_derivation = external_ini_observer(host, host_raw, out)
    record_context = {**context, "run_dir": out.as_posix()}
    original_process, original_native = host.public_native_process, host.native
    operations, ram_processes, mutations = [], [], set()
    approved = {("docker", "stop", "--timeout=-1", cid) for cid, role in expected.items() if role in {"audit-mysql", "audit-redis"}}
    approved |= {("docker", "rm", cid) for cid in expected}
    approved |= {("docker", "volume", "rm", name) for name in volumes}
    approved.add(("docker", "network", "rm", context["network_id"]))
    require(len(approved) == 16, "EXACT_SIXTEEN_ORDINARY_OWNED_ACTIONS")
    def process(recording, argv, label, env=None, legacy_receipt=None):
        require(recording == record_context and env is None, "ONLY_EXTERNAL_RECORDING_CONTEXT_NO_ENV_OVERRIDE")
        args = argv[1:]
        require(argv[:1] == ["docker"], "ONLY_ORIGINAL_DOCKER_RECORDER")
        action = tuple(argv)
        readonly = args[:1] in (["ps"], ["inspect"], ["wait"]) or args[:2] in (["network", "ls"], ["network", "inspect"], ["volume", "ls"], ["volume", "inspect"])
        require(readonly or action in approved, "NO_UNKNOWN_OR_FORCE_RESOURCE_ACTION")
        if not readonly:
            require(action not in mutations, "ORDINARY_MUTATION_ONCE_NO_RETRY")
        result = original_process(recording, argv, label, legacy_receipt=legacy_receipt)
        require(result.returncode == 0 and result.stderr == b"", "ORIGINAL_NATIVE_ZERO_EMPTY_STDERR")
        stems = sorted((out / "evidence" / "native-streams").glob("*.terminal.json"))
        terminal_path = stems[-1]
        stem = terminal_path.name.removesuffix(".terminal.json")
        made = helpers.decode(terminal_path.with_name(stem + ".created.json").read_bytes())
        terminal = helpers.decode(terminal_path.read_bytes())
        require(made["argv"] == terminal["argv"] == argv and made["pid"] == terminal["pid"]
                and terminal["native_exit"] == terminal["wait_returncode"] == result.returncode, "ORIGINAL_SAME_PID_NATIVE_WAIT_ZERO")
        operations.append({"stem": stem, "summary": legacy_receipt, "argv": argv, "pid": made["pid"], "native_exit": result.returncode})
        if not readonly:
            mutations.add(action)
        return result
    def native(actual, args, label):
        require(actual == context, "OWNERSHIP_ALWAYS_ORIGINAL_CONTEXT")
        return original_native(record_context, args, label)
    host.public_native_process, host.native = process, native
    def aliases(allowed, removed=False):
        ids = native(context, ["ps", "-aq", "--no-trunc"], "failed-normal-global-cids").splitlines()
        require(len(ids) == len(set(ids)), "COMPLETE_UNIQUE_GLOBAL_CIDS")
        template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"mode":{{json .HostConfig.NetworkMode}},"pid_mode":{{json .HostConfig.PidMode}},"ipc_mode":{{json .HostConfig.IpcMode}},"networks":{{json .NetworkSettings.Networks}}}'
        rows = [helpers.decode(line) for line in native(context, ["inspect", "--format", template, *ids], "failed-normal-global-aliases").splitlines()] if ids else []
        require({row["id"] for row in rows} == set(ids), "ALL_GLOBAL_ALIAS_ROWS")
        require(not (historical - set(allowed)).intersection(ids), "ALL_UNALLOWED_ORIGINAL_HISTORICAL_CIDS_ABSENT")
        for row in rows:
            joined = (context["project"] + "_isolation" in row["networks"] or row["mode"] in {network, context["project"] + "_isolation"}
                      or any(item.get("NetworkID") == network for item in row["networks"].values())
                      or any(row[key] in {"container:" + cid for cid in historical} for key in ("mode", "pid_mode", "ipc_mode")))
            physical = any(item.get("Name") in volumes or any(str(item.get("Source", "")).rstrip("/") == root or str(item.get("Source", "")).startswith(root + "/") for root in physical_roots.values()) for item in row["mounts"])
            require(not (joined or physical) or row["id"] in allowed, "NO_FOREIGN_NETWORK_OR_PHYSICAL_ALIAS")
        names = native(context, ["volume", "ls", "-q"], "failed-normal-all-volume-names").splitlines()
        require(len(names) == len(set(names)), "UNIQUE_GLOBAL_VOLUME_NAMES")
        roots = []
        for offset in range(0, len(names), 40):
            raw = native(context, ["volume", "inspect", "--format", '{"name":{{json .Name}},"root":{{json .Mountpoint}}}', *names[offset:offset + 40]], "failed-normal-global-volume-roots")
            roots.extend(helpers.decode(line) for line in raw.splitlines())
        require({row["name"] for row in roots} == set(names), "COMPLETE_GLOBAL_VOLUME_ROOTS")
        for row in roots:
            if removed or row["name"] not in volumes:
                require(not any(row["root"].rstrip("/") == root or row["root"].startswith(root + "/") for root in physical_roots.values()), "NO_FOREIGN_VOLUME_PHYSICAL_SUBTREE_ALIAS")
        return {"container_count": len(ids), "volume_count": len(names)}
    def preview(label):
        ids = set()
        for project, wanted in projects.items():
            values = native(context, ["ps", "-aq", "--no-trunc", "--filter", "label=com.docker.compose.project=" + project], label + "-ids").splitlines()
            require(len(values) == len(set(values)) == 4 and set(values) == set(wanted), "EXACT_PREVIEW_FOUR_CIDS_EACH")
            ids.update(values)
        argv = ["docker", "inspect", "--type", "container", "--format", helpers.PREVIEW_FORMAT, *sorted(ids)]
        when = time.time_ns()
        child = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        made = {"pid": child.pid, "argv": argv, "started_ns": when, "scope": "RAM_ONLY_COMPLETE27_NO_RAW_COMMAND_FILE"}
        save(label + "-inspect.created.json", made)
        raw, err = child.communicate(); waited = child.wait()
        terminal = {**made, "native_exit": child.returncode, "wait_returncode": waited, "finished_ns": time.time_ns(), "stdout_bytes": len(raw), "stdout_sha256": hashlib.sha256(raw).hexdigest(), "stderr_bytes": len(err), "stderr_sha256": hashlib.sha256(err).hexdigest(), "raw_stdout_stderr_saved": False}
        save(label + "-inspect.terminal.json", terminal); ram_processes.append(terminal)
        require(child.returncode == waited == 0 and err == b"", "PREVIEW_ACTUAL_NATIVE_WAIT_ZERO")
        values = [helpers.decode(line) for line in raw.splitlines()]
        require(len(values) == 8 and {row["id"] for row in values} == previews and all(set(row) == helpers.FIELDS27 for row in values), "ALL_EIGHT_COMPLETE27_PREVIEW_ROWS")
        rows = {row["id"]: row for row in values}
        require(helpers.preview_public(rows) == baseline["preview_projections_public_digests"], "ALL27_MATCH_ORIGINAL_PREVIEW_BASELINE")
        return rows
    try:
        # All resource predicates receive context; only native and INI output roots differ.
        network = host.collect(context, expected, publish=False)
        require(network == context["network_id"], "EXACT_ORIGINAL_NETWORK_ID")
        ipam = helpers.decode(native(context, ["network", "inspect", "--format", "{{json .IPAM.Config}}", network], "failed-normal-original-subnet"))
        require(type(ipam) is list and len(ipam) == 1 and ipam[0].get("Subnet") == baseline["subnet"], "ONLY_ORIGINAL_NORMAL_SUBNET_96")
        facts = {cid: host.inspect(context, cid, "failed-normal-target-preflight") for cid in expected}
        for cid, role in expected.items():
            host.owned(context, facts[cid], role); host.same_created(created[cid], facts[cid])
            if role in {"audit-mysql", "audit-redis"}:
                require(facts[cid]["status"] == "running" and facts[cid]["pid"] > 0, "ORIGINAL_BASE_RUNNING")
            else:
                require(facts[cid]["status"] == "exited" and facts[cid]["pid"] == 0 and facts[cid]["exit_code"] == (255 if role == "audit-ms4-worker-normal" else 0), "ORIGINAL_WORKER255_PAID0_TERMINALS")
        volume_template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},"root":{{json .Mountpoint}}}'
        volume_facts = {name: helpers.decode(native(context, ["volume", "inspect", "--format", volume_template, name], "failed-normal-volume-preflight")) for name in volumes}
        physical_roots = {name: row["root"].rstrip("/") for name, row in volume_facts.items()}
        require(len(set(physical_roots.values())) == 9 and all(root.startswith("/var/lib/docker/volumes/") for root in physical_roots.values()), "NINE_DISTINCT_ORIGINAL_PHYSICAL_ROOTS")
        for row in context["initial_volumes"]:
            require({key: value for key, value in volume_facts[row["name"]].items() if key != "root"} == row, "ORIGINAL_VOLUME_CREATED_IDENTITIES")
        historical = set(expected)
        for name, kind in classes.items():
            if name.startswith("evidence/") and name.endswith("-created.json"):
                require(kind == "public", "CREATED_IDENTITY_INPUT_POSITIVELY_PUBLIC")
                value = helpers.decode((run / name).read_bytes())
                if "id" in value:
                    require(re.fullmatch(r"[a-f0-9]{64}", value["id"]) is not None and value["run_id"] == context["run_id"] and value["source_sha"] == context["source_sha"], "ALL_ORIGINAL_HISTORICAL_CREATED_IDENTITIES")
                    historical.add(value["id"])
        aliases(set(expected))
        before_preview = preview("preview-before")
        save("preflight.json", {"targets": facts, "network": network, "volume_facts": volume_facts, "physical_roots": physical_roots,
             "historical_ids": sorted(historical), "preview": helpers.preview_public(before_preview), "ini_output_derivation": ini_derivation})
        require(helpers.run_snapshot(run, classes) == original_run, "ORIGINAL_RUN_ZERO_APPEND_BEFORE_MUTATION")
        for path, wanted in fixed.items():
            require(helpers.filepin(Path(path)) == wanted, "ALL_FIXED_INPUTS_HELD_BEFORE_MUTATION")
        for cid, role in expected.items():
            fresh = host.inspect(context, cid, "failed-normal-before-stop-wait"); host.owned(context, fresh, role); host.same_created(facts[cid], fresh)
            wanted = 255 if role == "audit-ms4-worker-normal" else 0
            if role in {"audit-mysql", "audit-redis"}:
                require(native(context, ["stop", "--timeout=-1", cid], "failed-normal-ordinary-base-stop") == cid, "EXACT_BASE_STOP_NO_TIMED_FORCE")
            require(native(context, ["wait", cid], "failed-normal-original-terminal-wait") == str(wanted), "ORIGINAL_WORKER255_PAID_BASE0_EXITS_HELD")
            fresh = host.inspect(context, cid, "failed-normal-after-wait"); host.owned(context, fresh, role); host.same_created(facts[cid], fresh)
            require(fresh["status"] == "exited" and fresh["pid"] == 0 and fresh["exit_code"] == wanted, "EXACT_ORIGINAL_TERMINALS")
        remaining = set(expected)
        for cid, role in expected.items():
            host.global_consumers(context, remaining); aliases(remaining)
            fresh = host.inspect(context, cid, "failed-normal-before-nonforce-remove"); host.owned(context, fresh, role); host.same_created(facts[cid], fresh)
            require(fresh["status"] == "exited" and fresh["pid"] == 0 and fresh["exit_code"] == (255 if role == "audit-ms4-worker-normal" else 0), "ORIGINAL_TERMINAL_HELD_BEFORE_REMOVE")
            require(native(context, ["rm", cid], "failed-normal-exact-nonforce-cid-remove") == cid, "EXACT_NONFORCE_CID_REMOVE"); remaining.remove(cid)
        aliases(set())
        require(helpers.decode(native(context, ["network", "inspect", "--format", "{{json .Containers}}", network], "failed-normal-original-network-empty")) == {}, "ORIGINAL_NETWORK_EMPTY")
        require(native(context, ["network", "rm", network], "failed-normal-exact-nonforce-network-remove") == network, "EXACT_NONFORCE_NETWORK_REMOVE")
        for name in volumes:
            require(helpers.decode(native(context, ["volume", "inspect", "--format", volume_template, name], "failed-normal-volume-immediately-before-remove")) == volume_facts[name], "ORIGINAL_VOLUME_IDENTITY_HELD_BEFORE_REMOVE")
            require(native(context, ["volume", "rm", name], "failed-normal-exact-nonforce-volume-remove") == name, "EXACT_NONFORCE_VOLUME_REMOVE")
        zeros = []
        for kind, argv in (("container", ["ps", "-aq", "--no-trunc"]), ("network", ["network", "ls", "-q", "--no-trunc"]), ("volume", ["volume", "ls", "-q"])):
            for label in ("com.docker.compose.project=" + context["project"], "tianli.audit.run=" + context["run_id"]):
                require(native(context, [*argv, "--filter", "label=" + label], "failed-normal-fresh-zero") == "", "ACTUAL_FRESH_SIX_ZERO")
                zeros.append({"kind": kind, "label": label})
        aliases(set(), removed=True)
        after_preview = preview("preview-after")
        normalize = lambda rows: {cid: {**row, "mounts": sorted(row["mounts"], key=lambda item: json.dumps(item, sort_keys=True))} for cid, row in rows.items()}
        require(normalize(after_preview) == normalize(before_preview), "ALL27_ACTUAL_RAM_VALUES_AND_MOUNTS_MULTISET_HELD")
        require(helpers.run_snapshot(run, classes) == original_run, "ORIGINAL_RUN_ZERO_APPEND_ALL_BYTES_STAT_HELD")
        for path, wanted in sources.items():
            require(source_snapshot(helpers, Path(path), manifest) == wanted, "BOTH_FULL_FROZEN_SOURCE_TREES_BYTES_STAT_HELD")
        require(host.current(args.context) == context, "ORIGINAL_SOURCE_AND_INI_INPUTS_REVERIFIED")
        host.clean(source, context["source_sha"]); host.clean(validation, context["source_sha"])
        for path, wanted in fixed.items():
            require(helpers.filepin(Path(path)) == wanted, "ALL_FIXED_INPUTS_HELD")
        # Original native groups are complete four-files; previews alone remain RAM-only.
        native_names, summary_names = set(), set()
        for operation in operations:
            stem = operation["stem"]
            require(re.fullmatch(r"process-[0-9]{6,}", stem) is not None, "ORIGINAL_NATIVE_RECORDER_STEM")
            native_names.update(stem + suffix for suffix in (".created.json", ".terminal.json", ".stdout", ".stderr"))
            summary = operation["summary"]
            require(type(summary) is str and re.fullmatch(r"native-[0-9]{4,}\.json", summary) is not None, "ORIGINAL_AUTOMATIC_NATIVE_SUMMARY")
            summary_names.add(summary)
            summary_row = helpers.decode((out / "evidence" / summary).read_bytes())
            terminal = helpers.decode((out / "evidence/native-streams" / (stem + ".terminal.json")).read_bytes())
            for kind in ("stdout", "stderr"):
                raw = (out / "evidence/native-streams" / (stem + "." + kind)).read_bytes()
                require(pin(raw) == {key: terminal[kind][key] for key in ("bytes", "sha256")}
                        and summary_row[kind + "_bytes"] == len(raw) and summary_row[kind + "_sha256"] == pin(raw)["sha256"], "FULL_ORIGINAL_NATIVE_STREAM_AND_SUMMARY_PIN_JOIN")
            require(summary_row["native_exit"] == operation["native_exit"] == 0, "ORIGINAL_AUTOMATIC_NATIVE_SUMMARY_ZERO")
        require({path.name for path in (out / "evidence/native-streams").iterdir()} == native_names, "EXACT_NATIVE_FOUR_FILE_CLOSED_SET")
        ini_folder = out / host.PROOF_INI_ENV_FOLDER
        ini_names = {"%06d%s" % (index, suffix) for index in range(1, 5) for suffix in INI_SUFFIXES}
        require({path.name for path in ini_folder.iterdir()} == ini_names, "EXACT_TWENTY_EXTERNAL_INI_OBSERVATION_LEAVES")
        observed = set()
        for index in range(1, 5):
            stem = "%06d" % index
            row = helpers.decode(host.proof_ini_raw(ini_folder / (stem + ".record.json")))
            made = helpers.decode(host.proof_ini_raw(ini_folder / (stem + ".created.json")))
            terminal = helpers.decode(host.proof_ini_raw(ini_folder / (stem + ".native.json")))
            require(row["container_id"] in expected and row["container_id"] not in observed and row["role"] == expected[row["container_id"]]
                    and made["argv"] == terminal["argv"] == ["docker", "inspect", "--type", "container", "--format", host.proof_ini_env_template(), row["container_id"]]
                    and made["pid"] == terminal["pid"] and terminal["native_exit"] == terminal["wait_returncode"] == 0, "EXACT_FOUR_ORIGINAL_INI_ROLE_NATIVE_IDENTITIES")
            for key, suffix in (("native_created", ".created.json"), ("native_terminal", ".native.json"), ("stdout", ".stdout"), ("stderr", ".stderr")):
                raw = host.proof_ini_raw(ini_folder / (stem + suffix))
                require(row[key] == {"path": host.PROOF_INI_ENV_FOLDER + "/" + stem + suffix, **pin(raw)}, "EXTERNAL_INI_FOUR_POINTERS_RESOLVE_ONLY_FROM_OUT")
            observed.add(row["container_id"])
        require(observed == set(expected) and mutations == approved, "ALL_FOUR_INI_ROLES_AND_ONLY_SIXTEEN_RESOURCE_ACTIONS")
        save("operations.json", operations)
        result = {"schema": 1, "scope": "FAILED_NORMAL_WORKER255_ORDINARY_RETIREMENT_ONLY", "status": "EXACT_FOUR_CONTAINERS_NINE_VOLUMES_ONE_NETWORK_NONFORCE_REMOVED",
                  "run_id": context["run_id"], "source_sha": context["source_sha"], "original_worker_exit": 255, "original_paid_exit": 0, "base_normal_stop_exit": 0,
                  "containers": expected, "network": network, "physical_roots": physical_roots, "resource_mutations": len(mutations), "original_Run_append_count": 0,
                  "original_Run_all_bytes_stat_held": True, "classified_private_metadata_only": [name for name, kind in classes.items() if kind == "private_metadata_only"],
                  "both_full_frozen_source_trees_held": True, "preview_before": helpers.preview_public(before_preview), "preview_after": helpers.preview_public(after_preview),
                  "preview_inspect_processes": ram_processes, "external_original_native_groups": len(operations), "external_native_four_files": 4 * len(operations), "external_native_summaries": len(summary_names),
                  "external_ini_groups": 4, "external_ini_leaves": 20, "ini_input_context_unchanged": True, "fresh_six_zero": zeros,
                  "historical_CIDs_absent": sorted(historical), "network_and_physical_aliases_absent": True, "success_close": "NOT_CALLED", "seal": "NOT_CREATED",
                  "worker_acceptance": "FAILED_EXIT_255_PRESERVED", "full_acceptance": "INCOMPLETE", "stopped_samples": "NOT RUN", "independent_zero": "PENDING_SEPARATE_NONAUTHOR_FRESH_OBSERVATION"}
        save("RESULT.json", result)
        require({path.name for path in (out / "evidence").iterdir()} == {"native-streams", "proof-php-ini-env"} | summary_names, "EXACT_EXTERNAL_EVIDENCE_FOUR_SUMMARIES_AND_INI_CLOSED_SET")
        allowed = {"actual.created.json", "inputs.json", "original-files.json", "frozen-two-trees.json", "preflight.json", "operations.json", "RESULT.json", "preview-before-inspect.created.json", "preview-before-inspect.terminal.json", "preview-after-inspect.created.json", "preview-after-inspect.terminal.json", "evidence"}
        require({path.name for path in out.iterdir()} == allowed, "EXACT_CREATE_ONLY_EXTERNAL_OUTPUT_NAMESPACE")
        save("actual.terminal.json", {"pid": os.getpid(), "started_ns": started, "finished_ns": time.time_ns(), "native_exit": 0, "result": pin((out / "RESULT.json").read_bytes())})
        print(json.dumps({"status": result["status"], "result": (out / "RESULT.json").as_posix(), "original_Run_append_count": 0, "independent_zero": "PENDING"}))
    except BaseException as error:
        text = str(error)
        code = text if re.fullmatch(r"[A-Z0-9_]+", text) else "UNEXPECTED_EXCEPTION"
        save("FAILURE.json", {"pid": os.getpid(), "class": type(error).__name__, "code": code, "finished_ns": time.time_ns(), "automatic_retry_restart_cleanup": False, "original_failure_and_partial_native_records_preserved": True})
        raise


def main():
    args = parser().parse_args()
    try:
        execute(args)
    except BaseException as error:
        text = str(error)
        code = text if re.fullmatch(r"[A-Z0-9_]+", text) else "UNEXPECTED_EXCEPTION"
        print(json.dumps({"status": "INCOMPLETE", "class": type(error).__name__, "code": code, "automatic_retry_restart_cleanup": False}), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
