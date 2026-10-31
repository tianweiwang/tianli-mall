"""Ordinary retirement of one pinned, pre-seal failed history producer.

This is deliberately not a successful history close, a retry, or an acceptance
runner. The original Host owns all resource/Source predicates. Only its evidence
destination is redirected; every resource predicate receives the original context.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time


HOST_PIN = {"bytes": 201800, "sha256": "1ef0570cb3dde604ce2c5122ba30ba99fda1a4eb9ea597f764e57252acff37ea"}
LEGACY_SHA = "477732e3e03ed4ba1dbb6b44a94dbba75f7e1978"
STAT = ("st_dev", "st_ino", "st_mode", "st_nlink", "st_uid", "st_gid", "st_size", "st_mtime_ns", "st_ctime_ns")
FIELDS27 = {"id", "image", "hostname", "user", "stop_signal", "labels", "mounts", "tmpfs", "read_only", "privileged", "network_mode", "status", "command", "entrypoint", "workdir", "restart_count", "pid", "exit_code", "networks", "ports", "port_bindings", "cap_drop", "security_options", "started_at", "finished_at", "created_at", "health_status"}
# Exact adopted R91 preflight projection. Never inspect Config.Env or save Cmd.
PREVIEW_FORMAT = '{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"user":{{json .Config.User}},"stop_signal":{{json (index .Config "StopSignal")}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}},"health_status":{{if index .State "Health"}}{{json (index (index .State "Health") "Status")}}{{else}}null{{end}}}'


def require(value, code):
    if not value:
        raise RuntimeError(code)


def decode(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, "NO_DUPLICATE_JSON_KEYS")
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def pin(raw):
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def stamp(path):
    facts = path.lstat()
    require(not stat.S_ISLNK(facts.st_mode) and not (getattr(facts, "st_file_attributes", 0) & 1024), "NO_LINK_OR_REPARSE")
    return [getattr(facts, key) for key in STAT]


def ordinary(path, missing=False):
    require(path.is_absolute() and ".." not in path.parts, "ABSOLUTE_ORDINARY_PATH")
    for node in [path, *path.parents]:
        if missing and not node.exists():
            require(not node.is_symlink(), "NO_DANGLING_OUTPUT_LINK")
            continue
        stamp(node)


def filepin(path, private=False):
    ordinary(path)
    before = stamp(path)
    require(stat.S_ISREG(before[2]) and before[3] == 1, "REGULAR_SINGLE_LINK_FILE")
    row = {"bytes": before[6], "stat9": before}
    if private:
        # No open, hash, or handle read for a classified private file.
        row["private_metadata_only"] = True
    else:
        with path.open("rb") as stream:
            opened = [getattr(os.fstat(stream.fileno()), key) for key in STAT]
            comparable = list(opened[:-1])
            if os.name == "nt" and path.suffix.lower() in (".exe", ".bat", ".cmd", ".com"):
                comparable[2] |= 0o111
            require(comparable == before[:-1], "SAME_PUBLIC_PATH_HANDLE")
            row["sha256"] = hashlib.file_digest(stream, "sha256").hexdigest()
            require([getattr(os.fstat(stream.fileno()), key) for key in STAT] == opened, "PUBLIC_HANDLE_HELD")
    require(stamp(path) == before, "FILE_NATIVE_WINDOW_HELD")
    return row


def read_pointer(path, expected_sha):
    require(re.fullmatch(r"[a-f0-9]{64}", expected_sha) is not None, "EXPLICIT_INPUT_SHA256")
    before = filepin(path)
    require(before["sha256"] == expected_sha, "FIXED_INPUT_SHA256")
    raw = path.read_bytes()
    require(pin(raw) == {key: before[key] for key in ("bytes", "sha256")} and filepin(path) == before, "FIXED_INPUT_FULL_BYTES_HELD")
    return raw, {"path": path.as_posix(), **pin(raw)}, before


def namespace(root):
    ordinary(root)
    files, directories = [], []
    for current, names, leaves in os.walk(root, followlinks=False, onerror=lambda error: (_ for _ in ()).throw(error)):
        parent = Path(current)
        stamp(parent)
        directories.append(parent.relative_to(root).as_posix())
        for name in names + leaves:
            stamp(parent / name)
        files.extend((parent / name).relative_to(root).as_posix() for name in leaves)
    require(len(files) == len(set(files)), "UNIQUE_FILE_NAMESPACE")
    return sorted(files), sorted(directories)


def run_snapshot(root, classification):
    files, directories = namespace(root)
    require(files == sorted(classification), "EXACT_REVIEWED_RUN_FILE_CLASSIFICATION")
    return {"files": {name: filepin(root / name, classification[name] == "private_metadata_only") for name in files}, "directories": directories, "directory_stat9": {name: stamp(root / name) for name in directories}}


def source_snapshot(host, root, expected_files, expected_directories):
    # Use the original positive Source namespace before opening any Source leaf.
    files, directories = host.legacy_namespace(root)
    require(files == [row["path"] for row in expected_files] and directories == expected_directories, "EXACT_FROZEN_SOURCE_NAMESPACE")
    rows = {}
    for wanted in expected_files:
        row = filepin(root / wanted["path"])
        require({key: row[key] for key in ("bytes", "sha256")} == {key: wanted[key] for key in ("bytes", "sha256")}, "FULL_FROZEN_SOURCE_BYTES")
        rows[wanted["path"]] = row
    # The Host excludes Git metadata and runtime overlays from its Source. Hold
    # their actual namespace/stat metadata without reading possible private data.
    excluded = {}
    for leaf in (".git", "crmeb/runtime"):
        path = root / leaf
        if not path.exists():
            excluded[leaf] = None
        elif path.is_file():
            excluded[leaf] = {"stat9": stamp(path)}
        else:
            names, dirs = namespace(path)
            excluded[leaf] = {"files": {name: filepin(path / name, True) for name in names}, "directories": dirs}
    return {"files": rows, "directories": directories, "directory_stat9": {name: stamp(root / name) for name in ["", *directories]}, "original_Host_exclusions_metadata_only": excluded}


def preview_canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def preview_public(rows):
    result = {}
    for cid, row in rows.items():
        mounts = sorted(row["mounts"], key=lambda item: json.dumps(item, sort_keys=True))
        normalized = {**row, "mounts": mounts}
        original26 = {key: value for key, value in normalized.items() if key != "health_status"}
        result[cid] = {"project": row["labels"]["com.docker.compose.project"], "service": row["labels"]["com.docker.compose.service"], "image": row["image"], "status": row["status"], "health_status": row["health_status"], "restart_count": row["restart_count"], "field_count": len(row), "full_27_canonical_sha256": hashlib.sha256(preview_canonical(normalized)).hexdigest(), "full_26_canonical_sha256": hashlib.sha256(preview_canonical(original26)).hexdigest(), "command_canonical_sha256": hashlib.sha256(preview_canonical(row["command"])).hexdigest(), "mounts_count_preserving_duplicates": len(mounts), "complete_mount_objects_sorted_multiset_sha256": hashlib.sha256(preview_canonical(mounts)).hexdigest(), "raw_command_saved": False}
    return result


def parser():
    value = argparse.ArgumentParser(description=__doc__)
    for name in ("host", "context", "source-authority", "classification", "preview-baseline"):
        value.add_argument("--" + name, required=True, type=Path)
        value.add_argument("--" + name + "-sha256", required=True)
    value.add_argument("--freeze-sha256", required=True)
    value.add_argument("--review-sha256", required=True)
    value.add_argument("--output", required=True, type=Path)
    return value


def execute(args):
    started = time.time_ns()
    input_rows = {}
    def checked(name):
        path = getattr(args, name.replace("-", "_"))
        raw, pointer, facts = read_pointer(path, getattr(args, name.replace("-", "_") + "_sha256"))
        input_rows[path.as_posix()] = facts
        return decode(raw), pointer
    host_raw, host_pointer, host_facts = read_pointer(args.host, args.host_sha256)
    require(pin(host_raw) == HOST_PIN, "ONLY_FIXED_REVIEWED_HISTORY_HOST")
    input_rows[args.host.as_posix()] = host_facts
    original, context_pointer = checked("context")
    authority, authority_pointer = checked("source-authority")
    classification, classification_pointer = checked("classification")
    baseline, baseline_pointer = checked("preview-baseline")
    run = Path(original["run_dir"])
    ordinary(run)
    require(args.context == run / "actual-run-context.json" and original["prep_dir"] == args.host.parent.as_posix(), "EXACT_ORIGINAL_CONTEXT_AND_HOST")
    freeze_path = run / "preparation-source-binding.json"
    freeze_raw, freeze_pointer, freeze_facts = read_pointer(freeze_path, args.freeze_sha256)
    input_rows[freeze_path.as_posix()] = freeze_facts
    frozen = decode(freeze_raw)
    require(original["freeze_sha256"] == args.freeze_sha256 and original["freeze_bytes"] == len(freeze_raw), "EXACT_ORIGINAL_FREEZE")
    require(frozen["history_source_review"]["sha256"] == args.review_sha256, "EXPLICIT_ORIGINAL_HISTORY_REVIEW")
    require(authority["schema"] == 1 and authority["scope"] == "R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY" and authority["status"] == "INDEPENDENTLY_REVIEWED_SOURCE_ONLY" and authority["source_sha"] == original["source_sha"] and authority["candidate_root"] == original["repo_dir"], "EXACT_CURRENT_SOURCE_AUTHORITY")
    source, validation, legacy = Path(original["repo_dir"]), Path(authority["validation_root"]), Path(original["legacy_repo_dir"])
    require(len({source, validation, legacy}) == 3 and frozen["nonempty_history"]["legacy_source_sha"] == LEGACY_SHA and frozen["nonempty_history"]["requested_legacy_repo_source"] == legacy.as_posix(), "EXACT_DISTINCT_CURRENT_VALIDATION_LEGACY_ROOTS")
    roots = (run, source, validation, legacy, args.host.parent)
    require(not args.classification.is_relative_to(run), "EXTERNAL_CLASSIFICATION_DOES_NOT_APPEND_TO_ORIGINAL_RUN")
    ordinary(args.output, missing=True)
    require(not args.output.exists() and all(not args.output.is_relative_to(root) and not root.is_relative_to(args.output) for root in roots), "FRESH_OUTPUT_OUTSIDE_RUN_AND_SOURCE_ROOTS")
    require(classification == {"schema": 1, "run_id": original["run_id"], "files": classification.get("files")} and type(classification["files"]) is dict, "EXACT_EXPLICIT_FILE_CLASSIFICATION")
    classes = classification["files"]
    require(classes and all(type(name) is str and name and not re.search(r"[\x00-\x1f\x7f\\:]", name) and all(part not in ("", ".", "..") for part in name.split("/")) and value in ("public", "private_metadata_only") for name, value in classes.items()), "FINITE_ORDINARY_CLASSIFIED_RUN_FILES")
    require(all(value == "private_metadata_only" for name, value in classes.items() if Path(name).name in {".env", "private.env", "legacy.env"}), "KNOWN_PRIVATE_ENV_LEAVES_NEVER_OPENED")
    required_public = {"actual-run-context.json", "preparation-source-binding.json", "history-source-review.json", "history-source-publication.json", "evidence/legacy-history-producer-created.json", "evidence/legacy-history-producer-terminal.json"}
    require(all(classes.get(name) == "public" for name in required_public), "ORIGINAL_OWNERSHIP_FILES_PUBLIC")
    require(not (run / "evidence/legacy-history-producer-result.json").exists(), "ONLY_PRE_SEAL_FAILED_PRODUCER_SUPPORTED")
    require(not any((run / "evidence" / name).exists() for name in ("driver-created.json", "current-structure-upgrade-created.json", "legacy-current-reader-created.json", "history-final-close.json")), "NO_LATER_ROLE_OR_SUCCESS_CLOSE")
    projects = baseline["preview_projects"]
    previews = {cid for ids in projects.values() for cid in ids}
    require(len(projects) == 2 and all(len(ids) == len(set(ids)) == 4 for ids in projects.values()) and len(previews) == 8 and set(baseline["preview_projections_public_digests"]) == previews, "EXACT_ADOPTED_TWO_PREVIEW_EIGHT_CIDS")
    spec = importlib.util.spec_from_file_location("pinned_failed_history_host", args.host)
    host = importlib.util.module_from_spec(spec)
    sys.dont_write_bytecode = True
    spec.loader.exec_module(host)
    # Complete metadata classification precedes h.current()/any public body read.
    original_run = run_snapshot(run, classes)
    current_files = frozen["current_reader"]["source_files"]
    current_dirs = frozen["current_reader"]["source_directories"]
    legacy_files = frozen["nonempty_history"]["legacy_source_files"]
    legacy_dirs = frozen["nonempty_history"]["legacy_source_directories"]
    sources = {path.as_posix(): source_snapshot(host, path, files, dirs) for path, files, dirs in ((source, current_files, current_dirs), (validation, current_files, current_dirs), (legacy, legacy_files, legacy_dirs))}
    context = host.current(args.context)
    require(context == original, "ORIGINAL_CONTEXT_NOT_CHANGED")
    host.clean(validation, context["source_sha"])
    expected = host.proof_expected(context, "legacy-history-producer")
    require(len(expected) == 3 and set(expected.values()) == {"audit-mysql", "audit-redis", "audit-legacy-history-producer"} and len(context["initial_volumes"]) == 6, "ONLY_THREE_CIDS_SIX_VOLUMES_HISTORY_PROFILE")
    args.output.mkdir()
    (args.output / "evidence").mkdir()
    out = args.output
    def save(name, value):
        with (out / name).open("xb") as stream:
            raw = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
            require(stream.write(raw) == len(raw), "FULL_CREATE_ONLY_PUBLIC_RECEIPT")
    save("actual.created.json", {"pid": os.getpid(), "argv": sys.argv, "started_ns": started, "scope": "FAILED_HISTORY_PRODUCER_ORDINARY_RETIREMENT_NOT_SUCCESS_CLOSE"})
    save("inputs.json", {"host": host_pointer, "context": context_pointer, "freeze": freeze_pointer, "history_review": frozen["history_source_review"], "source_authority": authority_pointer, "classification": classification_pointer, "preview_baseline": baseline_pointer, "recording_context_only_difference": {"run_dir": out.as_posix()}})
    save("original-files.json", original_run)
    save("frozen-three-trees.json", sources)
    record_context = {**context, "run_dir": out.as_posix()}
    original_native, original_process = host.native, host.history_process
    operations, ram_processes, mutations = [], [], set()
    approved_mutations = {("docker", "stop", "--timeout=-1", cid) for cid, role in expected.items() if role in {"audit-mysql", "audit-redis"}}
    approved_mutations |= {("docker", "rm", cid) for cid in expected}
    approved_mutations |= {("docker", "volume", "rm", row["name"]) for row in context["initial_volumes"]}
    approved_mutations.add(("docker", "network", "rm", context["network_id"]))
    require(len(approved_mutations) == 12, "EXACT_TWELVE_OWNED_ORDINARY_RESOURCE_ACTIONS")
    def process(recording, argv, stem, env=None):
        require(recording == record_context and env is None, "ONLY_RECORDING_DESTINATION_REDIRECTED_NO_ENV")
        action = tuple(argv)
        readonly = (argv[:1] == ["docker"] and (argv[1:2] in (["ps"], ["inspect"], ["wait"]) or argv[1:3] in (["network", "ls"], ["network", "inspect"], ["volume", "ls"], ["volume", "inspect"])))
        require(readonly or action in approved_mutations, "NO_UNKNOWN_DOCKER_RESOURCE_ACTION")
        if not readonly:
            require(action not in mutations, "ORDINARY_MUTATION_ONCE_NO_RETRY")
        result = original_process(recording, argv, stem)
        made = decode((out / "evidence" / (stem + ".created.json")).read_bytes())
        terminal = decode((out / "evidence" / (stem + ".terminal.json")).read_bytes())
        require(made["argv"] == terminal["argv"] == argv and made["pid"] == terminal["pid"] and terminal["native_exit"] == terminal["wait_exit"] == result.returncode and result.returncode == 0 and result.stderr == b"", "ORIGINAL_SAME_PID_NATIVE_WAIT_ZERO")
        operations.append({"stem": stem, "argv": argv, "pid": made["pid"], "native_exit": result.returncode})
        if not readonly:
            mutations.add(action)
        return result
    def native(actual, argv, label):
        require(actual == context, "OWNERSHIP_USES_UNMODIFIED_ORIGINAL_CONTEXT")
        return original_native(record_context, argv, label)
    host.history_process, host.native = process, native
    def global_volume_aliases(removed=False):
        names = native(context, ["volume", "ls", "-q"], "failed-history-global-volume-names").splitlines()
        require(len(names) == len(set(names)), "GLOBAL_UNIQUE_VOLUME_NAMES")
        rows = []
        for offset in range(0, len(names), 40):
            raw = native(context, ["volume", "inspect", "--format", '{"name":{{json .Name}},"root":{{json .Mountpoint}}}', *names[offset:offset + 40]], "failed-history-global-volume-roots")
            rows.extend(decode(line) for line in raw.splitlines())
        require({row["name"] for row in rows} == set(names), "COMPLETE_GLOBAL_VOLUME_ROOTS")
        for row in rows:
            if removed or row["name"] not in physical:
                require(not any(row["root"].rstrip("/") == root or row["root"].startswith(root + "/") for root in physical.values()), "NO_FOREIGN_PHYSICAL_VOLUME_ALIAS")
        return rows
    def preview(label):
        ids = set()
        for project, wanted in projects.items():
            found = native(context, ["ps", "-aq", "--no-trunc", "--filter", "label=com.docker.compose.project=" + project], label + "-ids").splitlines()
            require(len(found) == len(set(found)) == 4 and set(found) == set(wanted), "SAME_FOUR_CIDS_EACH_PREVIEW")
            ids.update(found)
        require(ids == previews, "EXACT_EIGHT_PREVIEWS")
        argv = ["docker", "inspect", "--type", "container", "--format", PREVIEW_FORMAT, *sorted(ids)]
        before = time.time_ns()
        child = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        made = {"pid": child.pid, "argv": argv, "started_ns": before, "scope": "RAM_ONLY_COMPLETE27_NO_RAW_COMMAND_FILE"}
        save(label + "-inspect.created.json", made)
        raw, err = child.communicate()
        waited = child.wait()
        terminal = {**made, "native_exit": child.returncode, "wait_returncode": waited, "finished_ns": time.time_ns(), "stdout_bytes": len(raw), "stdout_sha256": hashlib.sha256(raw).hexdigest(), "stderr_bytes": len(err), "stderr_sha256": hashlib.sha256(err).hexdigest(), "raw_stdout_stderr_saved": False}
        save(label + "-inspect.terminal.json", terminal)
        ram_processes.append(terminal)
        require(child.returncode == waited == 0 and err == b"", "PREVIEW_RAM_INSPECT_ZERO")
        values = [decode(line) for line in raw.splitlines()]
        require(len(values) == 8 and {row["id"] for row in values} == previews and all(set(row) == FIELDS27 for row in values), "COMPLETE_EIGHT_PREVIEW27_FIELDS")
        rows = {row["id"]: row for row in values}
        require(preview_public(rows) == baseline["preview_projections_public_digests"], "ALL27_MATCH_FIXED_R91_PREVIEW_BASELINE")
        return rows
    try:
        network = host.collect(context, expected, publish=False)
        facts = {cid: host.inspect(context, cid, "failed-history-preflight") for cid in expected}
        for cid, role in expected.items():
            host.owned(context, facts[cid], role)
            if role == "audit-legacy-history-producer":
                host.same_created(host.bound_created(context, "legacy-history-producer", role), facts[cid])
                require(facts[cid]["status"] == "exited" and facts[cid]["pid"] == 0 and facts[cid]["exit_code"] == 1 and facts[cid]["restart_count"] == 0, "ORIGINAL_FAILED_PRODUCER_EXIT1")
            else:
                host.same_created(context["initial_service_created"][role], facts[cid])
                require(facts[cid]["status"] == "running" and facts[cid]["pid"] > 0, "ORIGINAL_BASE_RUNNING")
        physical = host.history_volume_roots(context)
        aliases = host.history_global_aliases(context, set(expected), physical)
        global_volume_aliases()
        before_preview = preview("preview-before")
        historical = set(expected)
        for name in classes:
            if name.startswith("evidence/") and name.endswith("-created.json"):
                require(classes[name] == "public", "CREATED_OWNERSHIP_RECEIPTS_NOT_PRIVATE")
                value = decode((run / name).read_bytes())
                if "id" in value:
                    require(re.fullmatch(r"[a-f0-9]{64}", value["id"]) is not None and value["labels"].get("tianli.audit.run") == context["run_id"] and value["labels"].get("com.docker.compose.project") == context["project"], "ALL_ORIGINAL_CREATED_IDENTITIES")
                    historical.add(value["id"])
        save("preflight.json", {"targets": facts, "network": network, "physical_roots": physical, "historical_ids": sorted(historical), "preserved_aliases": sorted(aliases), "preview": preview_public(before_preview)})
        require(run_snapshot(run, classes) == original_run, "ORIGINAL_RUN_ZERO_APPEND_BEFORE_MUTATION")
        for path, wanted in input_rows.items():
            require(filepin(Path(path)) == wanted, "FIXED_INPUT_WINDOW_BEFORE_MUTATION")
        for cid, role in expected.items():
            fresh = host.inspect(context, cid, "failed-history-before-stop-wait")
            host.owned(context, fresh, role)
            host.same_created(facts[cid], fresh)
            wanted = 1 if role == "audit-legacy-history-producer" else 0
            if wanted == 0:
                require(native(context, ["stop", "--timeout=-1", cid], "failed-history-ordinary-base-stop") == cid, "EXACT_NORMAL_BASE_STOP")
            require(native(context, ["wait", cid], "failed-history-original-wait") == str(wanted), "ORIGINAL_NATIVE_EXIT_PRESERVED")
            fresh = host.inspect(context, cid, "failed-history-after-wait")
            host.owned(context, fresh, role)
            host.same_created(facts[cid], fresh)
            require(fresh["status"] == "exited" and fresh["pid"] == 0 and fresh["exit_code"] == wanted, "SAME_ORIGINAL_TERMINAL")
        remaining = set(expected)
        for cid, role in expected.items():
            host.history_global_aliases(context, remaining, physical, aliases)
            global_volume_aliases()
            fresh = host.inspect(context, cid, "failed-history-before-remove")
            host.owned(context, fresh, role)
            host.same_created(facts[cid], fresh)
            require(fresh["status"] == "exited" and fresh["pid"] == 0 and fresh["exit_code"] == (1 if role == "audit-legacy-history-producer" else 0), "ORIGINAL_TERMINAL_HELD_BEFORE_REMOVE")
            require(native(context, ["rm", cid], "failed-history-exact-nonforce-remove") == cid, "EXACT_NONFORCE_CID_REMOVE")
            remaining.remove(cid)
        host.history_global_aliases(context, set(), physical, aliases)
        for original_volume in context["initial_volumes"]:
            name = original_volume["name"]
            template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},"mountpoint":{{json .Mountpoint}}}'
            row = decode(native(context, ["volume", "inspect", "--format", template, name], "failed-history-volume-before-remove"))
            require({key: row[key] for key in original_volume} == original_volume and row["mountpoint"].rstrip("/") == physical[name], "EXACT_ORIGINAL_VOLUME_IDENTITY")
            require(native(context, ["volume", "rm", name], "failed-history-volume-nonforce-remove") == name, "EXACT_NONFORCE_VOLUME_REMOVE")
        template = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'
        network_row = decode(native(context, ["network", "inspect", "--format", template, network], "failed-history-network-before-remove"))
        require({key: network_row[key] for key in context["initial_network"]} == context["initial_network"] and network_row["Containers"] == {}, "ORIGINAL_EMPTY_INTERNAL_NETWORK")
        require(native(context, ["network", "rm", network], "failed-history-network-nonforce-remove") == network, "EXACT_NONFORCE_NETWORK_REMOVE")
        zeros = []
        for kind, argv in (("container", ["ps", "-aq", "--no-trunc"]), ("network", ["network", "ls", "-q", "--no-trunc"]), ("volume", ["volume", "ls", "-q"])):
            for label in ("com.docker.compose.project=" + context["project"], "tianli.audit.run=" + context["run_id"]):
                require(native(context, [*argv, "--filter", "label=" + label], "failed-history-fresh-zero") == "", "ACTUAL_FRESH_SIX_ZERO")
                zeros.append({"kind": kind, "label": label})
        all_cids = set(native(context, ["ps", "-aq", "--no-trunc"], "failed-history-all-historical-cids-zero").splitlines())
        require(historical.isdisjoint(all_cids), "ALL_ORIGINAL_HISTORICAL_CIDS_ABSENT")
        host.history_global_aliases(context, set(), physical, aliases)
        # Also reject an alias volume exposing an original physical subtree.
        global_volume_aliases(removed=True)
        after_preview = preview("preview-after")
        before_complete = {cid: {**row, "mounts": sorted(row["mounts"], key=lambda item: json.dumps(item, sort_keys=True))} for cid, row in before_preview.items()}
        after_complete = {cid: {**row, "mounts": sorted(row["mounts"], key=lambda item: json.dumps(item, sort_keys=True))} for cid, row in after_preview.items()}
        require(after_complete == before_complete, "ALL27_ACTUAL_VALUES_IN_RAM_AND_MOUNTS_MULTISET_HELD")
        require(preview_public(after_preview) == preview_public(before_preview), "ALL27_PREVIEW_FIELDS_AND_MOUNTS_MULTISET_HELD")
        require(run_snapshot(run, classes) == original_run, "ORIGINAL_RUN_ZERO_APPEND_ALL_BYTES_STAT_HELD")
        for path, wanted in sources.items():
            files, dirs = (legacy_files, legacy_dirs) if path == legacy.as_posix() else (current_files, current_dirs)
            require(source_snapshot(host, Path(path), files, dirs) == wanted, "CURRENT_VALIDATION_LEGACY_COMPLETE_SOURCE_HELD")
        require(host.current(args.context) == context, "ORIGINAL_SOURCE_FREEZE_REVIEW_REVERIFIED")
        host.clean(validation, context["source_sha"])
        for path, wanted in input_rows.items():
            require(filepin(Path(path)) == wanted, "ALL_FIXED_INPUTS_HELD")
        # Enumerate exactly the original process four-files and native summaries.
        expected_evidence = set()
        for operation in operations:
            stem = operation["stem"]
            expected_evidence.update(stem + suffix for suffix in (".created.json", ".terminal.json", ".stdout", ".stderr"))
            require(re.fullmatch(r"process-native-[0-9]{4,}", stem) is not None, "ONLY_ORIGINAL_NATIVE_RECORDER_STEMS")
            summary = stem.removeprefix("process-") + ".json"
            expected_evidence.add(summary)
            row = decode((out / "evidence" / summary).read_bytes())
            terminal = decode((out / "evidence" / (stem + ".terminal.json")).read_bytes())
            for kind in ("stdout", "stderr"):
                raw = (out / "evidence" / (stem + "." + kind)).read_bytes()
                require(pin(raw) == {key: terminal[kind][key] for key in ("bytes", "sha256")} and row[kind + "_bytes"] == len(raw) and row[kind + "_sha256"] == pin(raw)["sha256"], "COMPLETE_NATIVE_FOUR_AND_SUMMARY_JOIN")
            require(row["native_exit"] == operation["native_exit"] == 0, "AUTOMATIC_NATIVE_SUMMARY_ZERO")
        require({path.name for path in (out / "evidence").iterdir()} == expected_evidence, "EXACT_DYNAMIC_NATIVE_FOUR_AND_SUMMARY_CLOSED_SET")
        require(mutations == approved_mutations, "ALL_AND_ONLY_TWELVE_OWNED_ORDINARY_ACTIONS")
        save("operations.json", operations)
        result = {"schema": 1, "scope": "FAILED_HISTORY_PRODUCER_EXIT1_ORDINARY_RETIREMENT_ONLY", "status": "EXACT_THREE_CONTAINERS_SIX_VOLUMES_ONE_NETWORK_NONFORCE_REMOVED", "run_id": context["run_id"], "source_sha": context["source_sha"], "original_producer_exit": 1, "original_base_normal_stop_exit": 0, "containers": expected, "network": network, "physical_roots": physical, "resource_mutations": len(mutations), "original_Run_files": len(original_run["files"]), "original_Run_append_count": 0, "original_Run_all_bytes_stat_held": True, "classified_private_metadata_only": [name for name, kind in classes.items() if kind == "private_metadata_only"], "current_validation_legacy_source_trees_held": True, "preview_before": preview_public(before_preview), "preview_after": preview_public(after_preview), "preview_inspect_processes": ram_processes, "original_native_groups": len(operations), "external_native_four_files": 4 * len(operations), "external_automatic_native_summaries": len(operations), "external_evidence_closed_set": True, "fresh_six_zero": zeros, "historical_CIDs_absent": sorted(historical), "network_and_physical_aliases_absent": True, "success_close": "NOT_CALLED", "seal": "NOT_CREATED", "independent_zero": "PENDING_SEPARATE_NONAUTHOR_FRESH_OBSERVATION", "history_acceptance": "INCOMPLETE", "stopped_samples": "NOT RUN"}
        save("RESULT.json", result)
        allowed = {"actual.created.json", "inputs.json", "original-files.json", "frozen-three-trees.json", "preflight.json", "preview-before-inspect.created.json", "preview-before-inspect.terminal.json", "preview-after-inspect.created.json", "preview-after-inspect.terminal.json", "operations.json", "RESULT.json", "actual.terminal.json", "evidence"}
        require({path.name for path in out.iterdir()} == allowed - {"actual.terminal.json"}, "EXACT_ROLE_OUTPUT_NAMESPACE")
        save("actual.terminal.json", {"pid": os.getpid(), "started_ns": started, "finished_ns": time.time_ns(), "native_exit": 0, "result": pin((out / "RESULT.json").read_bytes())})
        print(json.dumps({"status": result["status"], "result": out.as_posix() + "/RESULT.json", "original_native_groups": len(operations), "original_Run_append_count": 0, "independent_zero": "PENDING"}))
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
