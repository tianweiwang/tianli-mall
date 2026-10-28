"""Derive the existing Core9 profile from a fixed independent Source review.

This author does not grant approval to captured hashes, start an orchestrator,
import archived candidates, or operate an application/runtime environment.
"""
from __future__ import annotations

import argparse
import ast
import copy
import difflib
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
from typing import Any

SOURCE_SHA = "7dfcc37459043959f648aa25921325ae677ed039"
BASELINE = Path("H:/天俪商城/artifacts/project-organization-20261023/integrated-normal-and-b-source-tree-source-r3")
BASELINE_PINS = {"path": (BASELINE / "PINS.json").as_posix(), "bytes": 93140,
                 "sha256": "67c9baf29f98230cd7a1e0bdd70157f2b3d1ac30b48adab6e241a84f51995b62"}
NAMES = (
    "README.md", "compose.yml", "ms4-host.py", "ms4-initialize.php",
    "seed-source-inverse.json", "interface-v1.json", "source-derivation.json",
    "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py",
)
AR_PATH = "help/audit-regressions/isolated/ms4-aftersale-review-normal.php"
AR_BEFORE = {"path": AR_PATH, "bytes": 245730,
             "sha256": "898eb47ab921a0572bcf04a4680e11d0eb4899465ea9598c60130d92bbc073fa"}
AR_CURRENT = {"path": AR_PATH, "bytes": 247958,
              "sha256": "3b914f6b6f995d7a3a2cb9b8f6460bb7eb16519864f1dceb7f6cd5df595a01d1"}
STAT_FIELDS = ("st_dev", "st_ino", "st_mode", "st_nlink", "st_uid", "st_gid",
               "st_size", "st_mtime_ns", "st_ctime_ns")


def require(condition: bool, label: str) -> None:
    if not condition:
        raise ValueError(label)


def pin(raw: bytes) -> dict[str, Any]:
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def encode(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def decode(raw: bytes) -> Any:
    def pairs(items: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in items:
            require(key not in result, "NO_DUPLICATE_JSON_KEYS")
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def ordinary(path: Path, *, missing_leaf: bool = False) -> None:
    require(path.is_absolute() and ".." not in path.parts, "ABSOLUTE_ORDINARY_PATH")
    for item in (path, *path.parents):
        if missing_leaf and not item.exists():
            require(not item.is_symlink(), "NO_DANGLING_OUTPUT_LINK")
            continue
        info = item.lstat()
        require(not stat.S_ISLNK(info.st_mode)
                and not (getattr(info, "st_file_attributes", 0) & 1024), "NO_SOURCE_LINK_OR_REPARSE")


def stable_read(path: Path) -> bytes:
    ordinary(path)
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, "REGULAR_SINGLE_LINK_SOURCE")
    initial = tuple(getattr(before, key) for key in STAT_FIELDS)
    with path.open("rb") as handle:
        opened = tuple(getattr(os.fstat(handle.fileno()), key) for key in STAT_FIELDS)
        # On Windows lstat's ctime is creation time while fstat's ctime can be
        # change time. Keep each complete nine-field observation stable, and
        # join the path/handle on their eight fields with matching semantics.
        comparable = list(opened[:-1])
        # CPython's Windows update_st_mode_from_path adds execute bits for
        # these exact extensions; fstat has no filename for that adjustment.
        if os.name == "nt" and path.suffix.lower() in (".exe", ".bat", ".cmd", ".com"):
            comparable[2] |= 0o111
        require(tuple(comparable) == initial[:-1], "OPEN_SOURCE_IDENTITY")
        raw = handle.read()
        require(handle.read(1) == b"", "COMPLETE_SOURCE_EOF")
        require(tuple(getattr(os.fstat(handle.fileno()), key) for key in STAT_FIELDS) == opened,
                "OPEN_SOURCE_IDENTITY_HELD")
    require(tuple(getattr(path.lstat(), key) for key in STAT_FIELDS) == initial
            and len(raw) == before.st_size, "COMPLETE_STABLE_SOURCE_BYTES")
    return raw


def read_pointer(pointer: dict[str, Any]) -> bytes:
    require(type(pointer) is dict and set(pointer) == {"path", "bytes", "sha256"}, "EXACT_FIXED_POINTER")
    require(type(pointer["path"]) is str and type(pointer["bytes"]) is int and pointer["bytes"] >= 0
            and type(pointer["sha256"]) is str and re.fullmatch(r"[a-f0-9]{64}", pointer["sha256"]) is not None,
            "TYPED_FIXED_POINTER")
    raw = stable_read(Path(pointer["path"]))
    require(pin(raw) == {key: pointer[key] for key in ("bytes", "sha256")}, "FIXED_POINTER_COMPLETE_BYTES")
    return raw


def reviewed_authority(pointer: dict[str, Any]) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    authority = decode(read_pointer(pointer))
    require(type(authority) is dict and set(authority) == {
        "schema", "scope", "status", "source_sha", "candidate_root", "validation_root",
        "capture", "independent_review", "baseline_profile"}, "EXACT_TECHNICAL_AUTHORITY_SCHEMA")
    require(type(authority["schema"]) is int and authority["schema"] == 1
            and authority["scope"] == "R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY"
            and authority["status"] == "INDEPENDENTLY_REVIEWED_SOURCE_ONLY"
            and authority["source_sha"] == SOURCE_SHA
            and authority["baseline_profile"] == BASELINE_PINS, "FIXED_CURRENT_SOURCE_AND_BASELINE_AUTHORITY")
    require(type(authority["candidate_root"]) is str and type(authority["validation_root"]) is str
            and authority["candidate_root"] != authority["validation_root"], "TWO_DISTINCT_REVIEWED_TREES")
    for name in ("candidate_root", "validation_root"):
        root = Path(authority[name])
        ordinary(root)
        require(root.is_dir() and root.resolve(strict=True).as_posix() == authority[name], "CANONICAL_REVIEWED_TREE")
    capture = decode(read_pointer(authority["capture"]))
    require(capture["schema"] == 1 and capture["status"] == "CAPTURED_NOT_APPROVED"
            and capture["source_approval"] == "NOT_GRANTED" and capture["source_sha"] == SOURCE_SHA
            and capture["candidate"] == authority["candidate_root"]
            and capture["validation"] == authority["validation_root"], "CAPTURE_MATCHES_REVIEWED_TWO_TREES")
    review = decode(read_pointer(authority["independent_review"]))
    require(type(review) is dict and review.get("schema") == 1
            and review.get("status") == "PASS_CURRENT_SOURCE_TECHNICAL_REVIEW"
            and review.get("source_sha") == SOURCE_SHA
            and review.get("capture") == authority["capture"]
            and review.get("candidate_root") == authority["candidate_root"]
            and review.get("validation_root") == authority["validation_root"]
            and review.get("baseline_profile") == BASELINE_PINS
            and review.get("all_tracked_and_delta_reviewed") is True
            and review.get("active_normal_sources_reviewed") is True,
            "INDEPENDENT_COMPLETE_CAPTURE_AND_ACTIVE_SOURCE_REVIEW_REQUIRED")
    # Capture is evidence only. Approval comes from the independently authored,
    # separately pinned review above, whose scope must include this exact capture.
    rows = [decode(line) for line in read_pointer(capture["files"]["SOURCE-INVENTORY.jsonl"]).splitlines()]
    require(type(capture["tracked_file_count"]) is int and len(rows) == capture["tracked_file_count"]
            and rows and [row["path"] for row in rows] == sorted({row["path"] for row in rows}),
            "REVIEWED_COMPLETE_ORDERED_INVENTORY")
    inventory = {row["path"]: {key: row[key] for key in ("path", "bytes", "sha256")} for row in rows}
    require(inventory.get(AR_PATH) == AR_CURRENT, "FIXED_REVIEWED_CURRENT_AFTERSALE_SOURCE")
    delta = decode(read_pointer(capture["files"]["SOURCE-DELTA.json"]))
    require(delta["source_sha"] == SOURCE_SHA and delta["status"] == "CAPTURED_NOT_APPROVED"
            and len(delta["files"]) == capture["delta_file_count"], "REVIEWED_COMPLETE_DELTA_POINTER")
    read_pointer(capture["files"]["BASELINE-INVENTORY.jsonl"])
    read_pointer(BASELINE_PINS)
    return authority, inventory


def constant(raw: bytes, name: str) -> Any:
    matches = [node for node in ast.parse(raw).body if isinstance(node, ast.Assign)
               and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    require(len(matches) == 1, "UNIQUE_CONSTANT_" + name)
    if name == "EARLY_SCRIPTS":
        # This one original Host dictionary refers to its fixed ASSET_CLI
        # constant. Resolve only that exact, audited reference; no eval/import
        # or arbitrary-name constant fallback is used.
        value = matches[0].value
        require(isinstance(value, ast.Dict)
                and [ast.literal_eval(key) for key in value.keys]
                == ["aftersale", "aftersale_menus", "review_menus", "assets"], "EXACT_ORIGINAL_EARLY_SCRIPTS_KEYS")
        expected = {
            "aftersale": "help/migrations/marketplace-ms4-aftersale.php",
            "aftersale_menus": "help/migrations/marketplace-ms4-aftersale-menus.php",
            "review_menus": "help/migrations/marketplace-ms4-review-menus.php",
        }
        result = {}
        for key_node, item in zip(value.keys, value.values):
            key = ast.literal_eval(key_node)
            if key == "assets":
                require(isinstance(item, ast.Name) and item.id == "ASSET_CLI", "ONE_FIXED_EARLY_ASSET_CLI_REFERENCE")
                result[key] = constant(raw, "ASSET_CLI")
                require(result[key] == "help/migrations/marketplace-ms4-assets.php", "EXACT_ORIGINAL_ASSET_CLI")
            else:
                result[key] = ast.literal_eval(item)
                require(result[key] == expected[key], "EXACT_ORIGINAL_EARLY_MIGRATION_PATH")
        return result
    return ast.literal_eval(matches[0].value)


def once(raw: bytes, before: bytes, after: bytes) -> bytes:
    require(raw.count(before) == 1, "EXACT_SINGLE_LITERAL_CHANGE")
    return raw.replace(before, after, 1)


def replace_constant(raw: bytes, name: str, value: Any) -> bytes:
    tree = ast.parse(raw)
    nodes = [node for node in tree.body if isinstance(node, ast.Assign)
             and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    require(len(nodes) == 1, "UNIQUE_CONSTANT_" + name)
    node = nodes[0]
    lines = raw.splitlines(keepends=True)
    start = sum(map(len, lines[:node.lineno - 1])) + node.col_offset
    end = sum(map(len, lines[:node.end_lineno - 1])) + node.end_col_offset
    result = raw[:start] + (name + " = " + repr(value)).encode("utf-8") + raw[end:]
    require(constant(result, name) == value, "EXACT_CONSTANT_REPLACEMENT")
    return result


def functions(raw: bytes) -> dict[str, bytes]:
    lines = raw.splitlines(keepends=True)
    return {node.name: b"".join(lines[node.lineno - 1:node.end_lineno])
            for node in ast.parse(raw).body if isinstance(node, (ast.FunctionDef, ast.ClassDef))}


def make_patch(before: dict[str, bytes], after: dict[str, bytes], names: tuple[str, ...] = NAMES) -> bytes:
    require(all(raw.endswith(b"\n") for raw in (*before.values(), *after.values())), "COMPLETE_PATCH_LINES")
    return "".join("".join(difflib.unified_diff(
        before[name].decode("utf-8").splitlines(keepends=True),
        after[name].decode("utf-8").splitlines(keepends=True),
        fromfile="a/" + name, tofile="b/" + name)) for name in names).encode("utf-8")


def apply_patch(before: dict[str, bytes], patch: bytes, names: tuple[str, ...] = NAMES) -> dict[str, bytes]:
    lines = patch.decode("utf-8").splitlines(keepends=True)
    result = dict(before)
    index = 0
    seen: set[str] = set()
    while index < len(lines):
        require(lines[index].startswith("--- a/"), "UNIFIED_PATCH_OLD_HEADER")
        name = lines[index][6:].rstrip("\r\n")
        require(name in names and name not in seen, "UNIQUE_PATCH_MEMBER")
        seen.add(name)
        index += 1
        require(lines[index].rstrip("\r\n") == "+++ b/" + name, "UNIFIED_PATCH_NEW_HEADER")
        index += 1
        old = before[name].decode("utf-8").splitlines(keepends=True)
        output: list[str] = []
        cursor = 0
        while index < len(lines) and lines[index].startswith("@@ "):
            match = re.fullmatch(r"@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?", lines[index])
            require(match is not None, "COMPLETE_UNIFIED_HUNK_HEADER")
            old_count = int(match[2] or 1)
            new_count = int(match[4] or 1)
            start = int(match[1]) - 1 if old_count else int(match[1])
            require(start >= cursor, "ORDERED_NONOVERLAPPING_HUNKS")
            output += old[cursor:start]
            cursor = start
            index += 1
            used = made = 0
            while index < len(lines) and not lines[index].startswith(("@@ ", "--- a/")):
                line = lines[index]
                require(line[0] in (" ", "+", "-"), "COMPLETE_UNIFIED_HUNK_BODY")
                if line[0] in (" ", "-"):
                    require(cursor < len(old) and old[cursor] == line[1:], "EXACT_PATCH_OLD_BYTES")
                    cursor += 1
                    used += 1
                if line[0] in (" ", "+"):
                    output.append(line[1:])
                    made += 1
                index += 1
            require((used, made) == (old_count, new_count), "COMPLETE_UNIFIED_HUNK_COUNTS")
        output += old[cursor:]
        result[name] = "".join(output).encode("utf-8")
    return result


def write_set(output: Path, files: dict[str, bytes]) -> None:
    ordinary(output, missing_leaf=True)
    if output.exists():
        require(output.is_dir(), "PROFILE_OUTPUT_DIRECTORY")
        paths = list(output.rglob("*"))
        for path in paths:
            ordinary(path)
        existing = {path.relative_to(output).as_posix() for path in paths if path.is_file()}
        require(existing <= set(files), "NO_UNKNOWN_PROFILE_OUTPUT_MEMBERS")
        directories = {path.relative_to(output).as_posix() for path in paths if path.is_dir()}
        expected_directories = {parent.as_posix() for name in files for parent in Path(name).parents
                                if parent.as_posix() != "."}
        require(directories <= expected_directories, "NO_UNKNOWN_PROFILE_OUTPUT_DIRECTORIES")
    for name, raw in files.items():
        target = output / name
        ordinary(target, missing_leaf=True)
        if target.exists():
            require(stable_read(target) == raw, "EXISTING_OUTPUT_MUST_BE_IDENTICAL")
    for name, raw in files.items():
        target = output / name
        target.parent.mkdir(parents=True, exist_ok=True)
        ordinary(target.parent)
        if not target.exists():
            with target.open("xb") as handle:
                handle.write(raw)
        require(stable_read(target) == raw, "WRITTEN_OUTPUT_COMPLETE_BYTES")


def build_profile(output: str | Path, authority_pointer: dict[str, Any]) -> dict[str, Any]:
    authority, inventory = reviewed_authority(authority_pointer)
    out = Path(output)
    require(out.is_absolute(), "ABSOLUTE_EXTERNAL_PROFILE_OUTPUT")
    ordinary(out, missing_leaf=True)
    out = out.resolve()
    proof = out.parent / "profile-source-proof-r61"
    for protected in (Path(__file__).resolve().parents[3], BASELINE,
                      Path(authority["candidate_root"]), Path(authority["validation_root"])):
        require(not out.is_relative_to(protected), "PROFILE_OUTPUT_OUTSIDE_SOURCE_TREES")
    baseline = decode(read_pointer(BASELINE_PINS))
    before: dict[str, bytes] = {}
    for name in NAMES:
        key = "core/actual-profile/" + name
        require(key in baseline["members"], "FIXED_BASELINE_CORE9_MEMBER")
        before[name] = read_pointer({"path": (BASELINE / key).as_posix(), **baseline["members"][key]})
    after = dict(before)
    interface = decode(before["interface-v1.json"])
    require(encode(interface) == before["interface-v1.json"], "ORIGINAL_INTERFACE_SERIALIZATION_RETAINED")
    seed = decode(before["seed-source-inverse.json"])
    require(inventory[seed["source_path"]]["sha256"] == seed["source_sha256"],
            "ORIGINAL_FINITE_SEED_CURRENT_SOURCE_IDENTITY")
    original_interface = copy.deepcopy(interface)
    rows = interface["customer_form_current_profile"]["php_sources"]
    require(sum(row == AR_BEFORE for row in rows) == 1, "ONE_ACTIVE_AFTERSALE_FORM_ROW")
    for row in rows:
        require(inventory[row["path"]] == (AR_CURRENT if row["path"] == AR_PATH else row),
                "REVIEWED_SEVEN_ACTIVE_NORMAL_SOURCES")
        if row["path"] == AR_PATH:
            row.update(AR_CURRENT)
    runtime_rows = interface["source_tree_runtime_profile"]["actual_candidate_tracked_sources"]
    require(sum(row == AR_BEFORE for row in runtime_rows) == 1, "ONE_ACTIVE_AFTERSALE_SOURCE_TREE_ROW")
    for row in runtime_rows:
        if row["path"] == AR_PATH:
            row.update(AR_CURRENT)
    after["interface-v1.json"] = encode(interface)
    restored_interface = copy.deepcopy(interface)
    for rows in (restored_interface["customer_form_current_profile"]["php_sources"],
                 restored_interface["source_tree_runtime_profile"]["actual_candidate_tracked_sources"]):
        for row in rows:
            if row["path"] == AR_PATH:
                row.update(AR_BEFORE)
    require(restored_interface == original_interface, "ONLY_TWO_INTERFACE_ACTIVE_ROWS_CHANGED")
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        raw = after[name]
        active = constant(raw, "CUSTOMER_FORM_SOURCE_FILES")
        require(type(active) is tuple and sum(row == AR_BEFORE for row in active) == 1, "ORIGINAL_FROZEN_SOURCE_TUPLE")
        updated = tuple(AR_CURRENT if row["path"] == AR_PATH else row for row in active)
        for row in updated:
            require(inventory[row["path"]] == row, "ALL_CURRENT_ACTIVE_SOURCE_TUPLE_MEMBERS")
        for row in constant(raw, "THREE_READ_SOURCE_FILES"):
            require(inventory[row["path"]] == row, "THREE_LEGACY_SOURCE_BYTES_RETAINED")
        for entry in ("PAID_ENTRY", "FULFILLMENT_ENTRY", "WORKER_ENTRY"):
            path = constant(raw, entry)
            require(inventory[path] == {"path": path, "bytes": constant(raw, entry + "_BYTES"),
                                       "sha256": constant(raw, entry + "_SHA256")}, "OTHER_ENTRY_PINS_RETAINED")
        raw = replace_constant(raw, "CUSTOMER_FORM_SOURCE_FILES", updated)
        require(constant(raw, "AFTERSALE_REVIEW_ENTRY_BYTES") == AR_BEFORE["bytes"]
                and constant(raw, "AFTERSALE_REVIEW_ENTRY_SHA256") == AR_BEFORE["sha256"], "OLD_ACTIVE_AFTERSALE_PIN")
        raw = replace_constant(raw, "AFTERSALE_REVIEW_ENTRY_BYTES", AR_CURRENT["bytes"])
        after[name] = replace_constant(raw, "AFTERSALE_REVIEW_ENTRY_SHA256", AR_CURRENT["sha256"])
    for name in ("ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py"):
        require(constant(after[name], "CURRENT_SOURCE_AUTHORITY") is None, "ORIGINAL_UNBOUND_SOURCE_GUARD")
        after[name] = replace_constant(after[name], "CURRENT_SOURCE_AUTHORITY", authority_pointer)
        before_comment = {
            "ms4-host.py": b"# UNBOUND: Root actual final HEAD/M/Val/A qualification required.",
            "normal-close-ms4-fulfillment.py": b"# UNBOUND: no original-context cleanup before Root qualification.",
            "verify-zero-ms4-fulfillment.py": b"# UNBOUND: no new zero query before actual Root final qualification.",
        }[name]
        after[name] = once(after[name], before_comment,
                           b"# Fixed independent Source review; runtime acceptance remains pending.")
    old_row = ("['path' => '" + AR_PATH + "', 'bytes' => " + str(AR_BEFORE["bytes"])
               + ", 'sha256' => '" + AR_BEFORE["sha256"] + "']").encode("utf-8")
    new_row = ("['path' => '" + AR_PATH + "', 'bytes' => " + str(AR_CURRENT["bytes"])
               + ", 'sha256' => '" + AR_CURRENT["sha256"] + "']").encode("utf-8")
    old_tuple = ("[" + str(AR_BEFORE["bytes"]) + ", '" + AR_BEFORE["sha256"] + "']").encode("utf-8")
    new_tuple = ("[" + str(AR_CURRENT["bytes"]) + ", '" + AR_CURRENT["sha256"] + "']").encode("utf-8")
    after["ms4-initialize.php"] = once(once(before["ms4-initialize.php"], old_row, new_row), old_tuple, new_tuple)
    require(once(once(after["ms4-initialize.php"], new_tuple, old_tuple), new_row, old_row)
            == before["ms4-initialize.php"], "TWO_INITIALIZER_LITERALS_WHOLE_FILE_INVERSE")
    for name, symbol, target in (
        ("ms4-host.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("normal-close-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("verify-zero-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "CLOSE_SHA256", "normal-close-ms4-fulfillment.py"),
    ):
        after[name] = replace_constant(after[name], symbol, pin(after[target])["sha256"])
    derivation = decode(before["source-derivation.json"])
    require(encode(derivation) == before["source-derivation.json"], "ORIGINAL_DERIVATION_SERIALIZATION_RETAINED")
    old_derivation = copy.deepcopy(derivation)
    derivation["current_nonself_artifacts"] = [{"path": name, **pin(after[name])} for name in NAMES
                                               if name != "source-derivation.json"]
    named = "r61_current_source_profile_binding"
    require(named not in derivation, "ONE_NEW_NAMED_SOURCE_DERIVATION")
    derivation[named] = {
        "scope": "REVIEWED_CURRENT_SOURCE_PROFILE_ONLY_NO_RUNTIME_ACCEPTANCE", "source_sha": SOURCE_SHA,
        "baseline_profile": BASELINE_PINS, "technical_authority": authority_pointer,
        "capture": authority["capture"], "independent_review": authority["independent_review"],
        "active_aftersale_before": AR_BEFORE, "active_aftersale_current": AR_CURRENT,
        "all_original_python_function_bodies_exact": True, "initializer_two_literal_inverse_exact": True,
        "original_nine_volume_source_tree_topology_retained": True,
        "initializer_syntax": "NOT RUN", "M_A_B_Runtime": "NOT RUN",
    }
    after["source-derivation.json"] = encode(derivation)
    recovered = copy.deepcopy(derivation)
    recovered.pop(named)
    recovered["current_nonself_artifacts"] = old_derivation["current_nonself_artifacts"]
    require(recovered == old_derivation, "ALL_DERIVATION_HISTORY_RETAINED")
    counts = {}
    for name in ("ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py"):
        require(functions(before[name]) == functions(after[name]), "ALL_ORIGINAL_PYTHON_FUNCTION_BODIES_EXACT")
        compile(after[name], str(out / name), "exec")
        counts[name] = len(functions(after[name]))
    forward = make_patch(before, after)
    reverse = make_patch(after, before)
    require(apply_patch(before, forward) == after and apply_patch(after, reverse) == before,
            "WHOLE_NINE_FORWARD_AND_REVERSE_REPLAY")
    manifest = {
        "schema": 1, "status": "REVIEWED_PROFILE_SOURCE_DERIVED_NOT_RUNTIME_QUALIFIED", "source_sha": SOURCE_SHA,
        "technical_authority": authority_pointer, "baseline_profile": BASELINE_PINS,
        "ordered_members9": list(NAMES), "before": {name: pin(before[name]) for name in NAMES},
        "candidate": {name: pin(after[name]) for name in NAMES},
        "changed": [name for name in NAMES if before[name] != after[name]],
        "all_original_python_function_bodies_exact": True, "python_named_function_counts": counts,
        "interface_only_two_active_rows": True, "initializer_two_literal_inverse_exact": True,
        "all_five_original_hash_edges_rebound": True, "current_nonself_eight_exact": True,
        "all_derivation_history_retained": True, "whole_forward_reverse_replay": True,
        "actual_initializer_syntax": "NOT RUN", "M_S_Host_Docker_App_business": "NOT RUN",
    }
    files = {"before/" + name: before[name] for name in NAMES}
    files.update({"FORWARD.patch": forward, "REVERSE.patch": reverse, "MANIFEST.json": encode(manifest)})
    profile_pins = {"schema": 1, "scope": "R61_REVIEWED_CORE9_SOURCE_ONLY_NOT_RUNTIME",
                    "source_sha": SOURCE_SHA, "technical_authority": authority_pointer,
                    "candidate_root": out.as_posix(), "candidate": manifest["candidate"],
                    "manifest": {"path": (proof / "MANIFEST.json").as_posix(), **pin(files["MANIFEST.json"])},
                    "runtime": "NOT RUN"}
    files["PINS.json"] = encode(profile_pins)
    write_set(proof, files)
    write_set(out, after)
    return {"status": manifest["status"], "source_sha": SOURCE_SHA,
            "PINS": {"path": (proof / "PINS.json").as_posix(), **pin(files["PINS.json"])},
            "profile_root": out.as_posix(), "changed": manifest["changed"]}


HISTORICAL_NORMAL = Path("H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source")
HISTORICAL_NORMAL_PINS = {"path": (HISTORICAL_NORMAL / "PINS.json").as_posix(), "bytes": 14595,
                          "sha256": "36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c"}
PATCH_BASE = "b7cabe0619789b308203f86b799102539d9ef803"
HISTORICAL_HEAD = "b54ad9c39ca95a1b8b15ed6724082b98b1370c53"
BUSINESS_MARKER = "    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
M_RESULT_KEYS = (
    "schema", "status", "source_sha", "parent", "source_patch_base", "commit_chain", "repo", "profile",
    "freeze", "freeze_bytes", "freeze_sha256", "root_source_pins", "profile_publication", "selected9",
    "committed_files", "source_counts", "unique_source_files_actual_read", "proof_resource_required", "proof_php_ini",
    "historical_retirements", "current_source_binding", "source_graph", "source_review", "native_operations",
    "Run_created", "App_DB_HTTP_business", "root_source_snapshot", "authority_input_snapshot",
    "complete_source_snapshot", "materialize_source", "materialize_source_inputs",
)


def _pointer(path: Path) -> dict[str, Any]:
    return {"path": path.as_posix(), **pin(stable_read(path))}


def _source_function(raw: bytes, name: str) -> str:
    lines = raw.decode("utf-8").splitlines(keepends=True)
    matches = [node for node in ast.parse(raw).body if isinstance(node, ast.FunctionDef) and node.name == name]
    require(len(matches) == 1, "ONE_ORIGINAL_FUNCTION_" + name)
    node = matches[0]
    return "".join(lines[node.lineno - 1:node.end_lineno])


def _literal_line(raw: bytes, name: str) -> Any:
    # The historical module contains very large single-line dictionaries. Parse
    # only the named assignment, never execute/import its nested authority code.
    rows = [line for line in raw.splitlines() if re.match(name.encode() + rb"\s*=", line)]
    require(len(rows) == 1, "ONE_HISTORICAL_LITERAL_" + name)
    node = ast.parse(rows[0]).body[0]
    require(isinstance(node, ast.Assign), "HISTORICAL_LITERAL_ASSIGNMENT")
    return ast.literal_eval(node.value)


def _git(repo: Path, *args: str, data: bytes | None = None) -> bytes:
    argv = ["git", "--no-optional-locks", "-c", "safe.directory=" + repo.as_posix(), "-C", str(repo), *args]
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate(data)
    require(process.returncode == process.wait() == 0 and stderr == b"", "AUTHOR_READONLY_GIT_ZERO")
    return stdout


def _raw_delta(repo: Path, parent: str, source: str) -> list[dict[str, Any]]:
    raw = _git(repo, "diff-tree", "--no-commit-id", "--raw", "--no-abbrev", "--no-renames", "-r", "-z", parent, source)
    if not raw:
        return []
    fields = raw.split(b"\0")
    require(fields[-1] == b"" and len(fields[:-1]) % 2 == 0, "COMPLETE_EXPLICIT_PARENT_GIT_DIFF")
    rows = []
    for index in range(0, len(fields) - 1, 2):
        before_mode, after_mode, before_blob, after_blob, state = fields[index].decode("ascii")[1:].split(" ")
        name = fields[index + 1].decode("utf-8")
        require(state in ("A", "M") and after_mode in ("100644", "100755")
                and before_mode in ("000000", "100644", "100755")
                and name and not re.search(r"[\x00-\x1f\x7f\\:]", name)
                and all(part not in ("", ".", "..", ".git") for part in name.split("/")), "FINITE_ORDINARY_NO_DELETE_GIT_DELTA")
        rows.append({"path": name, "before_git_mode": before_mode, "after_git_mode": after_mode,
                     "before_git_blob": None if state == "A" else before_blob, "after_git_blob": after_blob})
    require(rows == sorted(rows, key=lambda row: row["path"])
            and len(rows) == len({row["path"] for row in rows}), "ORDERED_UNIQUE_GIT_DELTA")
    return rows


def _object_pins(repo: Path, objects: set[str]) -> dict[str, dict[str, Any]]:
    result = {}
    ordered = sorted(objects)
    for index in range(0, len(ordered), 64):
        requested = ordered[index:index + 64]
        raw = _git(repo, "cat-file", "--batch", data=("\n".join(requested) + "\n").encode("ascii"))
        offset = 0
        for oid in requested:
            end = raw.index(b"\n", offset)
            actual, kind, amount = raw[offset:end].decode("ascii").split(" ")
            length = int(amount)
            require(actual == oid and kind == "blob" and length >= 0, "ACTUAL_SOURCE_GRAPH_GIT_OBJECT")
            offset = end + 1
            body = raw[offset:offset + length]
            require(len(body) == length and raw[offset + length:offset + length + 1] == b"\n"
                    and hashlib.sha1(b"blob " + str(length).encode("ascii") + b"\0" + body).hexdigest() == oid,
                    "COMPLETE_SOURCE_GRAPH_GIT_BLOB_SHA1")
            result[oid] = pin(body)
            offset += length + 1
        require(offset == len(raw), "COMPLETE_SOURCE_GRAPH_BINARY_BATCH")
    return result


def _profile_groups(host: bytes, inventory: dict[str, dict[str, Any]]) -> dict[str, Any]:
    names = ("CUSTOMER_ROOTS", "CUSTOMER_EXTRA", "DRIVER", "REFERENCES", "PAID_ENTRY", "FULFILLMENT_ENTRY",
             "FULFILLMENT_MIGRATION_ENTRY", "FULFILLMENT_CLI", "ASSET_CLI", "THREE_READ_SOURCE_FILES",
             "EARLY_SCRIPTS", "AFTERSALE_REVIEW_ENTRY", "WORKER_ENTRY")
    values = {name: constant(host, name) for name in names}
    fulfillment = sorted(set(values["CUSTOMER_EXTRA"] + values["DRIVER"] + [values[name] for name in (
        "PAID_ENTRY", "FULFILLMENT_ENTRY", "FULFILLMENT_MIGRATION_ENTRY", "FULFILLMENT_CLI", "ASSET_CLI")]
        + [row["path"] for row in values["THREE_READ_SOURCE_FILES"]]))
    aftersale = sorted(set(fulfillment + list(values["EARLY_SCRIPTS"].values()) + [values["AFTERSALE_REVIEW_ENTRY"]]))
    worker = sorted(aftersale + [values["WORKER_ENTRY"]])
    require((len(fulfillment), len(aftersale), len(worker)) == (26, 30, 31), "ORIGINAL_EXACT_ROLE_EXTRAS")
    extras = {"customer": sorted(values["CUSTOMER_EXTRA"]),
              "paid": sorted(values["CUSTOMER_EXTRA"] + [values["PAID_ENTRY"]]),
              "fulfillment": fulfillment, "aftersale_review": aftersale, "worker": worker}
    roots = {name: values["CUSTOMER_ROOTS"] for name in extras}
    groups = {"driver": [inventory[name] for name in values["DRIVER"]]}
    for name, paths in extras.items():
        selected = sorted(set(paths) | {path for path in inventory
                                      if any(path.startswith(root + "/") for root in roots[name])})
        groups[name] = [inventory[path] for path in selected]
    return {"roots": roots, "extras": extras, "driver": values["DRIVER"],
            "references": [inventory[name] for name in values["REFERENCES"]],
            "source_counts": {name: len(rows) for name, rows in groups.items()},
            "unique_source_files": len({row["path"] for rows in groups.values() for row in rows})}


_COMMON_RUNTIME = r'''
CURRENT_READS = {}
SOURCE_REVIEW_POINTER = None

def current_assert(value, code):
    if not value: raise RuntimeError(code)

def current_stream(pointer, keep=False):
    current_assert(type(pointer) is dict and set(pointer)=={'path','bytes','sha256'}
        and type(pointer['path']) is str and type(pointer['bytes']) is int and pointer['bytes']>=0
        and type(pointer['sha256']) is str and re.fullmatch('[a-f0-9]{64}',pointer['sha256']), 'FIXED_COMPLETE_CURRENT_POINTER')
    path=Path(pointer['path']);current_assert(path.is_absolute() and '..' not in path.parts,'ABSOLUTE_CURRENT_SOURCE')
    for node in [path,*path.parents]:
        facts=node.lstat();current_assert(not stat.S_ISLNK(facts.st_mode) and not(getattr(facts,'st_file_attributes',0)&1024),'NO_CURRENT_SOURCE_LINK_OR_REPARSE')
    keys=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
    facts=path.lstat();before=[getattr(facts,key) for key in keys]
    current_assert(stat.S_ISREG(facts.st_mode) and facts.st_nlink==1,'REGULAR_SINGLE_LINK_CURRENT_SOURCE')
    digest=hashlib.sha256();amount=0;parts=[]
    with path.open('rb') as handle:
        opened=[getattr(os.fstat(handle.fileno()),key) for key in keys]
        comparable=list(opened[:-1])
        # Match only CPython Windows' four filename-based execute-bit additions.
        if os.name=='nt' and path.suffix.lower() in ('.exe','.bat','.cmd','.com'):
            comparable[2]|=0o111
        current_assert(comparable==before[:-1],'SAME_CURRENT_PATH_AND_HANDLE_IDENTITY')
        while True:
            block=handle.read(1024*1024)
            if not block:break
            digest.update(block);amount+=len(block)
            if keep:parts.append(block)
        current_assert(handle.read(1)==b'' and [getattr(os.fstat(handle.fileno()),key) for key in keys]==opened,'COMPLETE_CURRENT_HANDLE_EOF_AND_IDENTITY')
    current_assert([getattr(path.lstat(),key) for key in keys]==before and amount==facts.st_size==pointer['bytes']
        and digest.hexdigest()==pointer['sha256'],'COMPLETE_CURRENT_SOURCE_HASH_AND_NATIVE_NINE_HELD')
    record={'path':path.as_posix(),'bytes':amount,'sha256':digest.hexdigest(),'stat9':before}
    CURRENT_READS[path.as_posix()]=record
    return b''.join(parts) if keep else record

def current_json(pointer):
    def pairs(items):
        value={}
        for key,item in items:
            current_assert(key not in value,'NO_DUPLICATE_CURRENT_SOURCE_JSON_KEYS');value[key]=item
        return value
    return json.loads(current_stream(pointer,True),object_pairs_hook=pairs)

def verify_current_technical():
    binding=current_json(CURRENT_SOURCE_BINDING)
    current_assert(binding['schema']==1 and binding['status']=='DERIVED_CURRENT_NORMAL_BINDING_NOT_APPROVED'
        and binding['source_sha']==CURRENT_SOURCE_SHA and binding['normal_root']==NORMAL_ROOT.as_posix(),'EXACT_CURRENT_NORMAL_BINDING')
    authority=current_json(binding['technical_authority'])
    current_assert(authority['schema']==1 and authority['scope']=='R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY'
        and authority['status']=='INDEPENDENTLY_REVIEWED_SOURCE_ONLY' and authority['source_sha']==CURRENT_SOURCE_SHA
        and authority['capture']==binding['capture'] and authority['candidate_root']==binding['candidate']
        and authority['validation_root']==binding['validation'] and authority['baseline_profile']==binding['baseline_profile'],'FIXED_CURRENT_TECHNICAL_AUTHORITY')
    review=current_json(authority['independent_review'])
    current_assert(review['status']=='PASS_CURRENT_SOURCE_TECHNICAL_REVIEW' and review['source_sha']==CURRENT_SOURCE_SHA
        and review['capture']==binding['capture'] and review['candidate_root']==binding['candidate']
        and review['validation_root']==binding['validation'] and review['baseline_profile']==binding['baseline_profile']
        and review['all_tracked_and_delta_reviewed'] is True and review['active_normal_sources_reviewed'] is True,'ACTUAL_INDEPENDENT_COMPLETE_SOURCE_REVIEW')
    capture=current_json(binding['capture'])
    current_assert(capture['status']=='CAPTURED_NOT_APPROVED' and capture['source_approval']=='NOT_GRANTED'
        and capture['source_sha']==CURRENT_SOURCE_SHA and capture['candidate']==binding['candidate']
        and capture['validation']==binding['validation'],'CAPTURE_IS_EVIDENCE_NOT_ITS_OWN_APPROVAL')
    profile=current_json(binding['profile_pins'])
    current_assert(profile['technical_authority']==binding['technical_authority'] and profile['source_sha']==CURRENT_SOURCE_SHA
        and profile['candidate_root']==(NORMAL_ROOT/'validation/actual-profile').as_posix(),'EXACT_CURRENT_PROFILE_SOURCE_JOIN')
    for name,wanted in profile['candidate'].items():
        current_stream({'path':(NORMAL_ROOT/'validation/actual-profile'/name).as_posix(),**wanted})
    syntax=current_json(binding['initializer_syntax']['result'])
    current_assert(syntax['native_exit']==syntax['wait_returncode']==0 and type(syntax['pid']) is int and syntax['pid']>0
        and syntax['source']=={'path':(NORMAL_ROOT/'validation/actual-profile/ms4-initialize.php').as_posix(),**profile['candidate']['ms4-initialize.php']}
        and syntax['scope']=='PHP82_SYNTAX_ONLY_NO_INCLUDE_APP_SQL_OR_BUSINESS','ACTUAL_CURRENT_INITIALIZER_SYNTAX_ZERO')
    current_assert(current_stream(binding['initializer_syntax']['stderr'],True)==b''
        and current_stream(binding['initializer_syntax']['stdout'],True)==b'No syntax errors detected in /source/ms4-initialize.php\n','ACTUAL_INITIALIZER_FULL_SYNTAX_STREAMS')
    return binding,capture,profile

def verify_normal_review(path, expected_sha):
    global SOURCE_REVIEW_POINTER
    path=Path(path);current_assert(re.fullmatch('[a-f0-9]{64}',expected_sha),'EXPLICIT_NORMAL_SOURCE_REVIEW_SHA')
    SOURCE_REVIEW_POINTER={'path':path.as_posix(),'bytes':path.stat().st_size,'sha256':expected_sha}
    review=current_json(SOURCE_REVIEW_POINTER);binding,capture,profile=verify_current_technical()
    current_assert(review['schema']==1 and review['status']=='PASS_CURRENT_NORMAL18_SOURCE_REVIEW'
        and review['source_sha']==CURRENT_SOURCE_SHA and review['binding']==CURRENT_SOURCE_BINDING
        and review['source_data']==binding['source_data'] and review['original_business_suffix_exact'] is True
        and review['source_graph_and_stream_contract_reviewed'] is True
        and type(review['members']) is dict and set(review['members'])==set(NORMAL_MEMBERS18),'INDEPENDENT_NORMAL18_SOURCE_REVIEW_REQUIRED')
    for name,wanted in review['members'].items():
        current_stream({'path':(NORMAL_ROOT/name).as_posix(),**wanted})
    return review

def source_authority(pub, reader, check):
    check(SOURCE_REVIEW_POINTER is not None,'CURRENT_SOURCE_GRAPH_NOT_APPROVED_WITHOUT_INDEPENDENT_NORMAL18_REVIEW')
    binding,capture,profile=verify_current_technical()
    data=json.loads(reader(binding['source_data']))
    check(data['schema']==1 and data['status']=='CAPTURED_SOURCE_GRAPH_NOT_APPROVED'
        and data['source_sha']==CURRENT_SOURCE_SHA and data['parent']==binding['parent']
        and data['capture']==binding['capture'] and data['profile_pins']==binding['profile_pins'],'FIXED_CURRENT_SOURCE_GRAPH_JOIN')
    check(pub['schema']==2 and pub['scope']=='CURRENT_COMPLETE_NORMAL9_SOURCE_PUBLICATION'
        and pub['source_sha']==CURRENT_SOURCE_SHA and pub['parent']==data['parent']
        and pub['current_source_binding']==CURRENT_SOURCE_BINDING
        and pub['selected9']==[{'path':name,**profile['candidate'][name]} for name in PROFILE_NAMES9]
        and pub['repository_Source_requirements']==data['requirements'],'CURRENT_PROFILE_PUBLICATION_COMPLETE_SOURCE_GRAPH_JOIN')
    rows=[json.loads(line) for line in reader(capture['files']['SOURCE-INVENTORY.jsonl']).splitlines()]
    check(len(rows)==capture['tracked_file_count'] and [row['path'] for row in rows]==sorted({row['path'] for row in rows}),'EXACT_ALL_CURRENT_TRACKED_MEMBERS')
    complete={row['path']:{key:row[key] for key in ['path','bytes','sha256']} for row in rows}
    check(all(type(row['bytes']) is int and row['bytes']>=0 and re.fullmatch('[a-f0-9]{64}',row['sha256']) for row in complete.values()),'TYPED_FULL_CURRENT_TRACKED_RECORDS')
    for pointer in binding['historical_sources'].values():current_stream(pointer)
    for pointer in data['historical_retirements'].values():current_stream(pointer)
    check(len(data['historical_retirements'])==11 and data['source_patch_base']==SOURCE_PATCH_BASE
        and data['chain_historical_prefix_count']==25 and len(data['commit_chain'])==data['chain_total_count']
        and data['chain_total_count']==25+data['chain_current_count'],'EXACT_HISTORICAL_PREFIX_AND_ACTUAL_CURRENT_PARENT_CHAIN')
    previous=SOURCE_PATCH_BASE;added=set()
    for step in data['commit_chain']:
        check(step['parent']==previous and step['parents'] and step['parents'][0]==step['parent']
            and re.fullmatch('[a-f0-9]{40}',step['source_sha']) and len(step['parents'])==len(set(step['parents'])),'COMPLETE_ACTUAL_FIRST_PARENT_AND_ALL_MERGE_PARENTS')
        for row in step['files']:
            if row['before'] is None:added.add(row['path'])
        previous=step['source_sha']
    check(previous==CURRENT_SOURCE_SHA and data['new_tracked_paths']==sorted(added),'EXACT_CURRENT_SOURCE_GRAPH_TERMINAL_AND_ADDITIONS')
    check(all(complete.get(row['path'])==row for row in data['committed']) and data['requirements']==[complete[name] for name in data['requirement_paths']],'CURRENT_COMMITTED_AND_REQUIREMENT_ROWS_COMPLETE')
    groups={'driver':[complete[name] for name in data['driver']]}
    for name,roots in data['roots'].items():
        paths=sorted(set(data['extras'][name])|{path for path in complete if any(path.startswith(root+'/') for root in roots)})
        groups[name]=[complete[path] for path in paths]
    check(data['source_counts']=={name:len(group) for name,group in groups.items()}
        and data['unique_source_files']==len({row['path'] for group in groups.values() for row in group}),'EXACT_CURRENT_COMPLETE_ROLE_SOURCE_GROUPS')
    protected={name:proof['after'] for name,proof in capture['protected_namespaces'].items()}
    for name,proof in protected.items():
        paths=[path for path in complete if path.startswith(name+'/')]
        check(proof=={'count':len(paths),'paths_sha256':hashlib.sha256(('\n'.join(paths)+'\n').encode()).hexdigest()},'CURRENT_COMPLETE_PROTECTED_NAMESPACE')
    return {'all':complete,'groups':groups,'roots':data['roots'],'extras':data['extras'],'references':data['references'],
        'proof_php_ini':data['proof_php_ini'],'committed':data['committed'],'commit_chain':data['commit_chain'],
        'new_tracked_paths':data['new_tracked_paths'],'protected_namespaces':protected,
        'historical_retirements':data['historical_retirements'],'git_index':[{key:row[key] for key in ['path','git_mode','git_blob']} for row in rows]}
'''


_S_PREFLIGHT = r'''
def main():
    global ARCHIVE, CONTEXT
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root-source-pins-sha256',required=True)
    parser.add_argument('--setup-sha256',required=True)
    parser.add_argument('--source-review-file',required=True)
    parser.add_argument('--source-review-sha256',required=True)
    args=parser.parse_args()
    verify_normal_review(args.source_review_file,args.source_review_sha256)
    root_pins={'path':(BASE/'PINS.json').as_posix(),'bytes':(BASE/'PINS.json').stat().st_size,'sha256':args.root_source_pins_sha256}
    pins=current_json(root_pins)
    require(pins['schema']==1 and pins['scope']=='FULL_A_ASSET_NORMAL_DRIVER_SOURCE_PINS'
        and set(pins['members'])=={'DESIGN-TASKS.md','run.py'},'EXACT_TWO_CURRENT_ROOT_SOURCE_MEMBERS')
    for name,wanted in pins['members'].items():current_stream({'path':(BASE/name).as_posix(),**wanted})
    actual_setup=current_json({'path':SETUP.as_posix(),'bytes':SETUP.stat().st_size,'sha256':args.setup_sha256})
    require(set(actual_setup)==set(CURRENT_M_RESULT_KEYS) and len(actual_setup)==len(CURRENT_M_RESULT_KEYS)
        and actual_setup['schema']==2 and actual_setup['status']=='ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
        and actual_setup['source_sha']==SOURCE and actual_setup['parent']==CURRENT_PARENT
        and actual_setup['source_patch_base']==SOURCE_PATCH_BASE and actual_setup['repo']==REPO.as_posix()
        and actual_setup['profile']==PROFILE.as_posix() and actual_setup['root_source_pins']==root_pins
        and actual_setup['current_source_binding']==CURRENT_SOURCE_BINDING
        and actual_setup['source_review']==SOURCE_REVIEW_POINTER
        and actual_setup['Run_created'] is False and actual_setup['App_DB_HTTP_business']=='NOT RUN','EXACT_SCHEMA2_ACTUAL_SOURCE_FREEZE_RESULT')
    publication=actual_setup['profile_publication']
    require(set(publication)=={'root','pins','publication'} and publication['root']==BASE.as_posix()
        and publication['pins']['path']==(BASE/'PROFILE-PINS.json').as_posix()
        and publication['publication']['path']==(BASE/'PROFILE-PUBLICATION.json').as_posix()
        and {key:publication['pins'][key] for key in ['bytes','sha256']}==PROFILE_PUBLICATION,'EXACT_CURRENT_PROFILE_PUBLICATION_POINTERS')
    pp=current_json(publication['pins']);pub=current_json(publication['publication'])
    require(pp['schema']==1 and pp['scope']=='FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS'
        and set(pp['members'])==set(PROFILE_NAMES9)|{'PROFILE-PUBLICATION.json'}
        and {path.name for path in PROFILE.iterdir()}==set(PROFILE_NAMES9),'EXACT_PHYSICAL_CURRENT_PROFILE9')
    for name,wanted in pp['members'].items():current_stream({'path':((BASE if name=='PROFILE-PUBLICATION.json' else PROFILE)/name).as_posix(),**wanted})
    for name,symbol,target in [('ms4-host.py','INTERFACE_SHA256','interface-v1.json'),('normal-close-ms4-fulfillment.py','HOST_SHA256','ms4-host.py'),
        ('verify-zero-ms4-fulfillment.py','INTERFACE_SHA256','interface-v1.json'),('verify-zero-ms4-fulfillment.py','HOST_SHA256','ms4-host.py'),('verify-zero-ms4-fulfillment.py','CLOSE_SHA256','normal-close-ms4-fulfillment.py')]:
        require(re.findall(r'^'+symbol+r" = '([a-f0-9]{64})'$",(PROFILE/name).read_text(encoding='utf-8'),re.M)==[pp['members'][target]['sha256']],'ALL_ORIGINAL_FIVE_PROFILE_HASH_EDGES')
    derived=json.loads((PROFILE/'source-derivation.json').read_bytes())
    require(derived['current_nonself_artifacts']==[row for row in pub['selected9'] if row['path']!='source-derivation.json'],'EXACT_CURRENT_NONSELF_EIGHT')
    qualified=source_authority(pub,lambda pointer:current_stream(pointer,True),require)
    binding=current_json(CURRENT_SOURCE_BINDING)
    require(actual_setup['committed_files']==qualified['committed'] and actual_setup['commit_chain']==qualified['commit_chain']
        and actual_setup['historical_retirements']==qualified['historical_retirements']
        and actual_setup['source_graph']==binding['source_data'] and actual_setup['selected9']==pub['selected9'],'CURRENT_SOURCE_GRAPH_AND_FIXED_HISTORICAL_DESCRIPTORS')
    for item in pub['repository_Source_requirements']:current_stream({'path':(REPO/item['path']).as_posix(),**{key:item[key] for key in ['bytes','sha256']}})
    frozen_raw=current_stream({'path':actual_setup['freeze'],'bytes':actual_setup['freeze_bytes'],'sha256':actual_setup['freeze_sha256']},True)
    frozen=json.loads(frozen_raw)
    require(frozen['schema']==1 and frozen['status']=='CLEAN_SOURCE_FROZEN_ONLY' and frozen['source_sha']==SOURCE
        and frozen['requested_repo_source']==REPO.as_posix() and frozen['artifact_files']==pub['selected9']
        and frozen['runtime_SQL_App']=='NOT RUN','ACTUAL_COMPLETE_FREEZE_AND_PROFILE_SOURCE_JOIN')
    validate_complete_source_tree(frozen,qualified,SOURCE,REPO.as_posix(),require)
    combined={}
    for prefix,expected in qualified['groups'].items():
        require(frozen[prefix+'_source_files']==expected and len(expected)==len({row['path'] for row in expected}),'EXACT_COMPLETE_ROLE_SOURCE_GROUP')
        if prefix!='driver':require(frozen[prefix+'_source_roots']==qualified['roots'][prefix] and frozen[prefix+'_source_extra']==qualified['extras'][prefix],'ORIGINAL_ROLE_ROOTS_AND_EXTRAS')
        for item in expected:
            require(item['path'] not in combined or combined[item['path']]==item,'EXACT_OVERLAPPING_SOURCE_RECORDS');combined[item['path']]=item
    require(actual_setup['source_counts']=={name:len(rows) for name,rows in qualified['groups'].items()}
        and actual_setup['unique_source_files_actual_read']==len(combined)
        and frozen['preparation_source_references']==qualified['references'] and frozen['proof_php_ini']==qualified['proof_php_ini']
        and actual_setup['proof_php_ini']==qualified['proof_php_ini'] and actual_setup['proof_resource_required'] is True,'COMPLETE_SOURCE_UNION_REFERENCES_AND_ORIGINAL_INI')
    snapshot=current_json(actual_setup['complete_source_snapshot'])
    require(len(snapshot)==2*len(qualified['all']),'EXACT_BOTH_COMPLETE_TRACKED_SOURCE_SNAPSHOTS')
    for root in [Path(binding['candidate']),REPO]:
        for relative,wanted in qualified['all'].items():
            path=root/relative;record=current_stream({'path':path.as_posix(),**{key:wanted[key] for key in ['bytes','sha256']}})
            require(record==snapshot[path.as_posix()],'ALL_CURRENT_SOURCE_BYTES_AND_NATIVE_NINE_MATCH_REAL_M')
    for key in ['root_source_snapshot','authority_input_snapshot']:
        records=current_json(actual_setup[key])
        for name,wanted in records.items():
            require(current_stream({field:wanted[field] for field in ['path','bytes','sha256']})==wanted,'ALL_M_SOURCE_AND_AUTHORITY_INPUTS_HELD')
    for name,wanted in actual_setup['materialize_source_inputs'].items():require(current_stream({field:wanted[field] for field in ['path','bytes','sha256']})==wanted,'ALL_THREE_MATERIALIZER_INPUTS_HELD')
    current_stream(actual_setup['materialize_source'])
    native=actual_setup['native_operations'];expected_count=6+sum(2+2*len(step['files']) for step in qualified['commit_chain'])+1+len(qualified['committed'])+4+3+1+4
    require(type(native) is list and len(native)==expected_count,'EXACT_DERIVED_COMPLETE_NATIVE_FREEZE_LEDGER')
    freezes=[];folder=SETUP.parent
    for operation in native:
        require(type(operation['pid']) is int and operation['pid']>0 and operation['native_exit']==operation['wait_returncode']==0
            and operation['stderr_bytes']==0 and operation['stderr_sha256']==sha(b''),'ACTUAL_SAME_PID_WAIT_ZERO_NATIVE_RESULT')
        stem=operation['stem'];require(re.fullmatch(r'[0-9]{3,}-[a-zA-Z0-9.-]+',stem),'EXACT_NATIVE_LEDGER_STEM')
        created=json.loads((folder/(stem+'.created.json')).read_bytes());terminal=json.loads((folder/(stem+'.terminal.json')).read_bytes())
        require(created=={'argv':operation['argv'],'pid':operation['pid']} and terminal==operation,'ACTUAL_NATIVE_CREATED_TERMINAL_JOIN')
        for kind in ['stdout','stderr']:
            current_stream({'path':(folder/(stem+'.'+kind)).as_posix(),'bytes':operation[kind+'_bytes'],'sha256':operation[kind+'_sha256']})
        if operation['label']=='freeze':freezes.append(operation)
    require(len(freezes)==1,'ONE_ACTUAL_GENERIC_FREEZE_ONLY')
    argv=freezes[0]['argv']
    require(len(argv)==14 and Path(argv[0])==Path(sys.executable) and argv[1:4]==['-B','-X','utf8']
        and Path(argv[4])==HOST and argv[5:7]==['freeze','--repo'] and Path(argv[7])==REPO
        and argv[8:11]==['--source',SOURCE,'--output'] and Path(argv[11])==Path(actual_setup['freeze'])
        and argv[12]=='--proof-ini-source' and Path(argv[13])==Path(frozen['proof_php_ini']['source_root']),'EXACT_REAL_CURRENT_HOST_FREEZE_ARGV')
'''


_LAUNCHER_RUNTIME = r'''
def main():
    parser=argparse.ArgumentParser(description='Run one independently reviewed normal stage; no retry.')
    parser.add_argument('stage',choices=['materialize','normal-start'])
    parser.add_argument('--source-review-file',required=True)
    parser.add_argument('--source-review-sha256',required=True)
    args=parser.parse_args()
    verify_normal_review(args.source_review_file,args.source_review_sha256)
    root=NORMAL_ROOT/'validation';folder=NORMAL_ROOT/'launcher';stamp=args.stage
    paths={suffix:folder/(stamp+'.'+suffix) for suffix in ['created.json','terminal.json','stdout','stderr']}
    current_assert(not any(path.exists() or path.is_symlink() for path in paths.values()),'ONE_ACTUAL_STAGE_LAUNCH_ONLY_NO_RETRY')
    common=['--source-review-file',args.source_review_file,'--source-review-sha256',args.source_review_sha256]
    if args.stage=='materialize':
        argv=[sys.executable,'-B','-X','utf8',str(NORMAL_ROOT/'materializer/prepare.py'),*common]
    else:
        setup=root/'materialize-freeze-assets-r1/RESULT.json'
        current_assert(setup.is_file() and not setup.is_symlink(),'ACTUAL_CURRENT_M_RESULT_BEFORE_NORMAL_START')
        value=json.loads(setup.read_bytes());current_assert(value['schema']==2 and value['source_sha']==CURRENT_SOURCE_SHA
            and value['source_review']==SOURCE_REVIEW_POINTER,'SAME_CURRENT_NORMAL18_REVIEWED_M_BEFORE_BUSINESS')
        argv=[sys.executable,'-B','-X','utf8',str(root/'run.py'),'--root-source-pins-sha256',hashlib.sha256((root/'PINS.json').read_bytes()).hexdigest(),
            '--setup-sha256',hashlib.sha256(setup.read_bytes()).hexdigest(),*common]
    started=time.time_ns()
    process=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    def put(path,raw):
        with path.open('xb') as handle:current_assert(handle.write(raw)==len(raw),'FULL_CREATE_ONLY_LAUNCHER_WRITE');handle.flush();os.fsync(handle.fileno())
        current_assert(path.read_bytes()==raw,'FULL_LAUNCHER_WRITE_READBACK')
    put(paths['created.json'],(json.dumps({'pid':process.pid,'argv':argv,'started_ns':started},ensure_ascii=False)+'\n').encode())
    print(json.dumps({'state':'SAME_NATIVE_PROCESS_LIVE','stage':args.stage,'pid':process.pid}),flush=True)
    stdout,stderr=process.communicate();waited=process.wait()
    put(paths['stdout'],stdout);put(paths['stderr'],stderr)
    terminal={'pid':process.pid,'native_exit':process.returncode,'wait_returncode':waited,'finished_ns':time.time_ns(),
        **{kind:{'path':paths[kind].as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()} for kind,raw in [('stdout',stdout),('stderr',stderr)]}}
    put(paths['terminal.json'],(json.dumps(terminal,ensure_ascii=False)+'\n').encode())
    print(json.dumps({'state':'ACTUAL_STAGE_TERMINAL','stage':args.stage,'pid':process.pid,'native_exit':process.returncode,'wait_returncode':waited}),flush=True)
    current_assert(process.returncode==waited==0 and stderr==b'','ACTUAL_STAGE_SAME_PID_WAIT_ZERO_FULL_STREAMS')

if __name__=='__main__':main()
'''


def build_normal(output: str | Path, authority_pointer: dict[str, Any], syntax_result: str | Path) -> dict[str, Any]:
    """Author the complete18; only an independent fixed review can run it later."""
    authority, inventory = reviewed_authority(authority_pointer)
    normal = Path(output)
    require(normal.is_absolute(), "ABSOLUTE_NEW_NORMAL_SOURCE")
    ordinary(normal, missing_leaf=True)
    normal = normal.resolve()
    require(all(not normal.is_relative_to(protected) for protected in (
        Path(__file__).resolve().parents[3], BASELINE, HISTORICAL_NORMAL,
        Path(authority["candidate_root"]), Path(authority["validation_root"]))),
        "NEW_NORMAL_SOURCE_OUTSIDE_EXISTING_AND_FROZEN_SOURCES")
    profile = normal / "validation/actual-profile"
    profile_pin = _pointer(normal / "validation/profile-source-proof-r61/PINS.json")
    current_profile = decode(read_pointer(profile_pin))
    require(current_profile["candidate_root"] == profile.as_posix()
            and current_profile["technical_authority"] == authority_pointer
            and current_profile["source_sha"] == SOURCE_SHA
            and set(current_profile["candidate"]) == set(NAMES)
            and {path.name for path in profile.iterdir()} == set(NAMES), "EXACT_ALREADY_DERIVED_CURRENT_CORE9")
    current9 = {name: read_pointer({"path": (profile / name).as_posix(), **current_profile["candidate"][name]}) for name in NAMES}
    baseline_pins = decode(read_pointer(HISTORICAL_NORMAL_PINS))
    members = tuple(baseline_pins["source_members18"])
    require(len(members) == len(set(members)) == 18, "COMPLETE_ORIGINAL_NORMAL18_SET")
    before = {name: read_pointer({"path": (HISTORICAL_NORMAL / name).as_posix(),
                                 **{key: baseline_pins["files"][name][key] for key in ("bytes", "sha256")}})
              for name in members}
    old_m = before["materializer/prepare.py"]
    old_s = before["validation/run.py"]
    historical_pub = decode(before["validation/PROFILE-PUBLICATION.json"])
    old_chain = _literal_line(old_m, "COMMIT_CHAIN")
    require(len(old_chain) == 25 and old_chain[-1]["source_sha"] == HISTORICAL_HEAD
            and historical_pub["finite_contexts"]["commit_chain"] == old_chain, "EXACT_ORIGINAL_25_SOURCE_EDGES")
    capture = decode(read_pointer(authority["capture"]))
    require(len(capture["source_parents"]) == 1, "ACTUAL_CURRENT_SINGLE_PARENT_HEAD")
    parent = capture["source_parents"][0]
    repository = Path(authority["validation_root"])
    current_edges = []
    history = _git(repository, "rev-list", "--first-parent", "--reverse", "--parents", HISTORICAL_HEAD + ".." + SOURCE_SHA)
    for line in history.decode("ascii").splitlines():
        fields = line.split(" ")
        current_edges.append({"source_sha": fields[0], "parent": fields[1], "parents": fields[1:]})
    require(current_edges and current_edges[0]["parent"] == HISTORICAL_HEAD
            and current_edges[-1]["source_sha"] == SOURCE_SHA, "COMPLETE_CURRENT_FIRST_PARENT_SUCCESSOR")
    edges = []
    objects: set[str] = set()
    previous = PATCH_BASE
    for index, original in enumerate([*old_chain, *current_edges]):
        source = original["source_sha"]
        fields = _git(repository, "rev-list", "--parents", "-n", "1", source).decode("ascii").strip().split(" ")
        require(fields[0] == source and fields[1] == original["parent"] == previous, "ACTUAL_SOURCE_GRAPH_COMPLETE_PARENT_EDGE")
        rows = _raw_delta(repository, previous, source)
        if index < 25:
            require([row["path"] for row in rows] == [row["path"] for row in original["files"]], "ORIGINAL_25_EXACT_CHANGED_MEMBER_SETS")
        edges.append({**original, "parents": fields[1:], "files": rows})
        objects.update(row[key] for row in rows for key in ("before_git_blob", "after_git_blob") if row[key] is not None)
        previous = source
    cumulative = _raw_delta(repository, PATCH_BASE, SOURCE_SHA)
    objects.update(row[key] for row in cumulative for key in ("before_git_blob", "after_git_blob") if row[key] is not None)
    object_pins = _object_pins(repository, objects)
    added = set()
    for index, edge in enumerate(edges):
        for row in edge["files"]:
            row["before"] = None if row["before_git_blob"] is None else object_pins[row["before_git_blob"]]
            row["after"] = object_pins[row["after_git_blob"]]
            if row["before"] is None:
                added.add(row["path"])
            if index < 25:
                old = next(item for item in old_chain[index]["files"] if item["path"] == row["path"])
                require((row["before"], row["after"]) == (old["before"], old["after"]), "ALL_ORIGINAL_25_EDGE_BEFORE_AFTER_BLOBS_RETAINED")
    committed = [{"path": row["path"], **object_pins[row["after_git_blob"]]} for row in cumulative]
    require(all(inventory[row["path"]] == row for row in committed), "CUMULATIVE_B7_CURRENT_BLOBS_EQUAL_REVIEWED_COMPLETE_INVENTORY")
    groups = _profile_groups(current9["ms4-host.py"], inventory)
    requirement_paths = sorted({row["path"] for row in historical_pub["repository_Source_requirements"]} | {row["path"] for row in committed})
    retirements = {key: historical_pub["finite_contexts"]["current_retirement" if key == "prior_retirement" else key]
                   for key in ("prior_retirement", "current_driver_failure_retirement", "current_early_failure_retirement",
                               "current_customer_prepare_failure_retirement", "current_paid_producer_failure_retirement",
                               "current_2e4_paid_producer_failure_retirement", "current_881_paid_producer_failure_retirement",
                               "current_0c13_paid_producer_failure_retirement", "current_28dc_paid_producer_failure_retirement",
                               "current_175f_paid_producer_failure_retirement", "current_6e3850_paid_producer_failure_retirement")}
    host = current9["ms4-host.py"]
    proof_ini = {"schema": 1, "scope": "MS4_PROOF_PHP_INI_RESOURCE_SOURCE",
                 "source_root": "H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/r20-proof-memory-budget-source-r1/candidate/proof-php-ini",
                 "files": [{"path": constant(host, "PROOF_INI_LEAF"), **constant(host, "PROOF_INI_PIN")}],
                 "roles": constant(host, "PROOF_INI_ROLES"), "scan_dir": constant(host, "PROOF_INI_SCAN"),
                 "config_reference": constant(host, "PROOF_INI_REFERENCE")}
    syntax = Path(syntax_result)
    syntax_value = decode(stable_read(syntax))
    require(syntax_value["native_exit"] == syntax_value["wait_returncode"] == 0
            and syntax_value["source"] == {"path": (profile / "ms4-initialize.php").as_posix(), **pin(current9["ms4-initialize.php"])},
            "ACTUAL_CURRENT_INITIALIZER_SYNTAX_RECEIPT")
    syntax_group = {"result": _pointer(syntax), "stdout": _pointer(syntax.with_suffix(".stdout")), "stderr": _pointer(syntax.with_suffix(".stderr"))}
    require(read_pointer(syntax_group["stdout"]) == b"No syntax errors detected in /source/ms4-initialize.php\n"
            and read_pointer(syntax_group["stderr"]) == b"", "ACTUAL_COMPLETE_CURRENT_SYNTAX_STREAMS")
    qualification = normal / "qualification"
    data = {"schema": 1, "status": "CAPTURED_SOURCE_GRAPH_NOT_APPROVED", "source_sha": SOURCE_SHA, "parent": parent,
            "capture": authority["capture"], "capture_delta_base": capture["baseline_sha"], "capture_delta_count": capture["delta_file_count"],
            "source_patch_base": PATCH_BASE, "profile_pins": profile_pin, "chain_historical_prefix_count": 25,
            "chain_current_count": len(current_edges), "chain_total_count": len(edges),
            "merge_count": sum(len(edge["parents"]) > 1 for edge in edges), "commit_chain": edges,
            "committed_count": len(committed), "committed": committed, "new_tracked_paths": sorted(added),
            "requirement_paths": requirement_paths, "requirements": [inventory[name] for name in requirement_paths],
            **groups, "proof_php_ini": proof_ini, "historical_retirements": retirements,
            "target_runtime": "NOT RUN"}
    data_raw = encode(data)
    data_pointer = {"path": (qualification / "SOURCE-DATA.json").as_posix(), **pin(data_raw)}
    business_suffix = old_s.decode("utf-8").split(BUSINESS_MARKER, 1)
    require(len(business_suffix) == 2, "ONE_COMPLETE_ORIGINAL_BUSINESS_SUFFIX")
    suffix = BUSINESS_MARKER + business_suffix[1]
    binding = {"schema": 1, "status": "DERIVED_CURRENT_NORMAL_BINDING_NOT_APPROVED", "source_sha": SOURCE_SHA,
               "parent": parent, "normal_root": normal.as_posix(), "candidate": authority["candidate_root"],
               "validation": authority["validation_root"], "technical_authority": authority_pointer,
               "capture": authority["capture"], "baseline_profile": BASELINE_PINS, "profile_pins": profile_pin,
               "source_data": data_pointer, "initializer_syntax": syntax_group,
               "original_business_suffix": pin(suffix.encode("utf-8")),
               "historical_sources": {"normal_PINS": HISTORICAL_NORMAL_PINS,
                                      **{name: {"path": (HISTORICAL_NORMAL / name).as_posix(), **pin(before[name])}
                                         for name in ("materializer/prepare.py", "validation/run.py", "validation/PROFILE-PUBLICATION.json")}},
               "runtime_authorization": "REQUIRES_SEPARATE_FIXED_INDEPENDENT_NORMAL18_SOURCE_REVIEW"}
    binding_raw = encode(binding)
    binding_pointer = {"path": (qualification / "CURRENT-BINDING.json").as_posix(), **pin(binding_raw)}
    common = ("\nimport os,stat\n" + "CURRENT_SOURCE_SHA = " + repr(SOURCE_SHA) + "\nSOURCE_PATCH_BASE = " + repr(PATCH_BASE)
              + "\nCURRENT_PARENT = " + repr(parent) + "\nNORMAL_ROOT = Path(" + repr(normal.as_posix()) + ")"
              + "\nCURRENT_SOURCE_BINDING = " + repr(binding_pointer) + "\nNORMAL_MEMBERS18 = " + repr(list(members))
              + "\nPROFILE_NAMES9 = " + repr(list(NAMES)) + "\nCURRENT_M_RESULT_KEYS = " + repr(list(M_RESULT_KEYS)) + "\n" + _COMMON_RUNTIME)
    selected = [{"path": name, **pin(current9[name])} for name in NAMES]
    publication = {"schema": 2, "scope": "CURRENT_COMPLETE_NORMAL9_SOURCE_PUBLICATION", "source_sha": SOURCE_SHA,
                   "parent": parent, "current_source_binding": binding_pointer, "selected9": selected,
                   "repository_Source_requirements": data["requirements"], "target_execution": "NOT RUN"}
    publication_raw = encode(publication)
    pp = {"schema": 1, "scope": "FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS",
          "members": {name: pin(current9[name]) for name in NAMES}}
    pp["members"]["PROFILE-PUBLICATION.json"] = pin(publication_raw)
    pp_raw = encode(pp)
    s_prefix = "".join(old_s.decode("utf-8").splitlines(keepends=True)[:165]).encode("utf-8")
    s_prefix = replace_constant(s_prefix, "SOURCE", SOURCE_SHA)
    s_prefix = once(s_prefix, ("Path('H:/天俪商城/worktrees/ms4-language-cache-normal-check')").encode(), ("Path(" + repr(authority["validation_root"]) + ")").encode())
    s_prefix = replace_constant(s_prefix, "PINS", {name: pin(current9[name])["sha256"] for name in (
        "ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py")})
    s_prefix = replace_constant(s_prefix, "PROFILE_PUBLICATION", pin(pp_raw))
    tree_join = _source_function(old_m, "validate_complete_source_tree")
    s = s_prefix.decode("utf-8") + common + "\n" + tree_join + "\n" + _S_PREFLIGHT + suffix
    design = before["validation/DESIGN-TASKS.md"] + (
        "\n\nR61 current normal18 Source candidate: fixed technical Source review, independent normal18 review required before native execution. "
        "Historical25/full authority/11 retirements remain immutable descriptors, not repeated JSON objects or new Runtime evidence. "
        "Schema2 current M preserves real full Git/dual-tree/namespace/freeze/source-tree checks. Original normal business suffix/helpers/close/zero remain complete. "
        "All M/A/B/runtime outcomes remain NOT RUN at author time.\n").encode()
    root_pins = {"schema": 1, "scope": "FULL_A_ASSET_NORMAL_DRIVER_SOURCE_PINS",
                 "members": {"DESIGN-TASKS.md": pin(design), "run.py": pin(s.encode("utf-8"))}}
    root_pins_raw = encode(root_pins)
    m_prefix = "".join(old_m.decode("utf-8").splitlines(keepends=True)[:40])
    m_prefix = m_prefix.replace((HISTORICAL_NORMAL / "validation").as_posix(), (normal / "validation").as_posix())
    m_prefix = m_prefix.replace("H:/天俪商城/worktrees/ms4-language-cache-normal-check", authority["validation_root"])
    m_prefix = m_prefix.replace("H:/天俪商城/worktrees/ms4-language-cache-normal", authority["candidate_root"])
    m_prefix = replace_constant(m_prefix.encode(), "head", SOURCE_SHA)
    m_prefix = replace_constant(m_prefix, "parent", parent).decode()
    m_body = old_m.decode("utf-8").split("\ntry:\n", 1)[1]
    m_body = m_body.replace((HISTORICAL_NORMAL / "materializer").as_posix(), (normal / "materializer").as_posix())
    old_pin_literal = repr(pin(before["validation/PINS.json"]))
    m_body = once(m_body.encode(), old_pin_literal.encode(), repr(pin(root_pins_raw)).encode()).decode()
    m_body = m_body.replace("qualified=source_authority(pub,checked,need);committed=qualified['committed'];retirement=qualified['retirement']",
                            "qualified=source_authority(pub,checked,need);committed=qualified['committed'];authority_before.update(CURRENT_READS)")
    start = m_body.index(" for step_index,step in enumerate(qualified['commit_chain'],1):")
    end = m_body.index(" need(sorted(git(repo,'cumulative-changed-paths'", start)
    m_body = m_body[:start] + ''' for step_index,step in enumerate(qualified['commit_chain'],1):
  label='source-step-'+str(step_index)
  need(git(repo,label+'-parent','rev-list','--parents','-n','1',step['source_sha'])==(' '.join([step['source_sha'],*step['parents']])+'\\n').encode(),'ACTUAL_EACH_SOURCE_COMPLETE_PARENTS')
  expected_raw=b''.join((':{} {} {} {} {}\\0{}\\0'.format(r['before_git_mode'],r['after_git_mode'],r['before_git_blob'] or '0'*40,r['after_git_blob'],'A' if r['before'] is None else 'M',r['path'])).encode('utf-8') for r in step['files'])
  need(git(repo,label+'-paths','diff-tree','--no-commit-id','--raw','--no-abbrev','--no-renames','-r','-z',step['parent'],step['source_sha'])==expected_raw,'EXACT_EXPLICIT_PARENT_SOURCE_DELTA_MODES_BLOBS_AND_PATHS')
  for member_index,r in enumerate(step['files'],1):
   mark=label+'-member-'+str(member_index)
   after=git(repo,mark+'-after','show',step['source_sha']+':'+r['path'])
   need(pin(after)==r['after'],'EACH_REAL_COMMITTED_AFTER_BLOB')
   if r['before'] is None:
    need(r['path'] in qualified['new_tracked_paths'] and git(repo,mark+'-absent-before','ls-tree','--name-only',step['parent'],'--',r['path'])==b'','EXACT_REAL_PARENT_ADDITION_ABSENCE')
   else:
    need(pin(git(repo,mark+'-before','show',step['parent']+':'+r['path']))==r['before'],'EACH_REAL_COMMITTED_BEFORE_BLOB')
''' + m_body[end:]
    m_body = m_body.replace("BASELINE_AUTHORITY['source_sha']", "SOURCE_PATCH_BASE")
    m_body = m_body.replace("git(repo,'cumulative-changed-paths','diff','--name-only',SOURCE_PATCH_BASE,head).decode().splitlines()",
                            "git(repo,'cumulative-changed-paths','diff','--name-only','--no-renames','-z',SOURCE_PATCH_BASE,head).decode().split('\\0')[:-1]")
    marker = " for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:"
    extra_index = ''' for label,path in [('main',main),('validation',repo)]:
  raw_index=git(path,label+'-complete-index','ls-files','--stage','-z');need(raw_index.endswith(b'\\0'),'COMPLETE_NUL_GIT_INDEX')
  actual_index=[]
  for raw_row in raw_index[:-1].split(b'\\0'):
   meta,name=raw_row.split(b'\\t',1);mode,blob,stage=meta.decode('ascii').split(' ');need(stage=='0','NO_UNMERGED_SOURCE_INDEX')
   actual_index.append({'path':name.decode('utf-8'),'git_mode':mode,'git_blob':blob})
  need(actual_index==qualified['git_index'],'COMPLETE_GIT_INDEX_MODES_AND_BLOBS_EQUAL_REVIEWED_CAPTURE')
'''
    m_body = once(m_body.encode(), marker.encode(), (extra_index + marker).encode()).decode()
    m_body = m_body.replace("+1+len(committed)+2+3+1+4", "+1+len(committed)+4+3+1+4")
    m_body = m_body.replace("need(all(row(Path(name))==rec for name,rec in authority_before.items()),'COMPLETE_FIXED_AUTHORITY_INPUT_BYTES_NATIVE_HELD')",
                            "need(all(current_stream({k:rec[k] for k in ['path','bytes','sha256']})==rec for name,rec in authority_before.items()),'COMPLETE_FIXED_AUTHORITY_INPUT_BYTES_NATIVE_HELD')")
    result_start = m_body.index(" result={'schema':1,")
    result_end = m_body.index(" save('RESULT.json',result)", result_start)
    m_body = m_body[:result_start] + ''' result={'schema':2,'status':'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY','source_sha':head,'parent':parent,
 'source_patch_base':SOURCE_PATCH_BASE,'commit_chain':qualified['commit_chain'],'repo':repo.as_posix(),'profile':profile.as_posix(),
 'freeze':frozen_path.as_posix(),'freeze_bytes':len(frozen_raw),'freeze_sha256':pin(frozen_raw)['sha256'],
 'root_source_pins':descriptor(root/'PINS.json'),'profile_publication':{'root':root.as_posix(),'pins':descriptor(root/'PROFILE-PINS.json'),'publication':descriptor(root/'PROFILE-PUBLICATION.json')},
 'selected9':pub['selected9'],'committed_files':committed,'source_counts':counts,'unique_source_files_actual_read':len(combined),
 'proof_resource_required':True,'proof_php_ini':frozen['proof_php_ini'],'historical_retirements':qualified['historical_retirements'],
 'current_source_binding':CURRENT_SOURCE_BINDING,'source_graph':current_json(CURRENT_SOURCE_BINDING)['source_data'],'source_review':SOURCE_REVIEW_POINTER,
 'native_operations':ops,'Run_created':False,'App_DB_HTTP_business':'NOT RUN','root_source_snapshot':descriptor(out/'ROOT-SOURCE-SNAPSHOT.json'),
 'authority_input_snapshot':descriptor(out/'AUTHORITY-INPUT-SNAPSHOT.json'),'complete_source_snapshot':descriptor(out/'COMPLETE-SOURCE-SNAPSHOT.json'),
 'materialize_source':descriptor(out/'root-materialize-source.py'),'materialize_source_inputs':tool_before}
 need(set(result)==set(CURRENT_M_RESULT_KEYS),'EXACT_SCHEMA2_CURRENT_M_RESULT_FIELDS')
''' + m_body[result_end:]
    m = (m_prefix + "\nimport argparse\n" + common + "\n" + tree_join + '''
parser=argparse.ArgumentParser(description='Materialize a fixed independently reviewed current normal18 source.')
parser.add_argument('--source-review-file',required=True)
parser.add_argument('--source-review-sha256',required=True)
arguments=parser.parse_args()
verify_normal_review(arguments.source_review_file,arguments.source_review_sha256)
try:
''' + m_body)
    launcher = ("from pathlib import Path\nimport argparse,hashlib,json,os,re,stat,subprocess,sys,time\n"
                + common + "\n" + _LAUNCHER_RUNTIME)
    after = {"validation/actual-profile/" + name: current9[name] for name in NAMES}
    after.update({"materializer/prepare.py": m.encode(), "validation/run.py": s.encode(), "validation/DESIGN-TASKS.md": design,
                  "validation/PINS.json": root_pins_raw, "validation/PROFILE-PINS.json": pp_raw,
                  "validation/PROFILE-PUBLICATION.json": publication_raw, "launcher/launch.py": launcher.encode(),
                  "materializer/PLAN.md": before["materializer/PLAN.md"] + b"\nR61 schema2: same native/full-tree/freeze invariants; fixed independent normal18 review is required before any native stage. Historical bodies remain immutable external references.\n",
                  "materializer/INVERSE.json": encode({"schema": 2, "scope": "CURRENT_SCHEMA2_SOURCE_ONLY_NOT_APPROVED",
                                                       "historical_normal_PINS": HISTORICAL_NORMAL_PINS,
                                                       "current_binding": binding_pointer, "original_business_suffix": binding["original_business_suffix"],
                                                       "history_objects_not_replicated": True, "runtime": "NOT RUN"})})
    require(set(after) == set(members), "EXACT_COMPLETE_NEW_NORMAL18_MEMBERS")
    for name in ("materializer/prepare.py", "validation/run.py", "launcher/launch.py"):
        compile(after[name], str(normal / name), "exec")
    require(s[s.index(BUSINESS_MARKER):].encode() == suffix.encode(), "ENTIRE_ORIGINAL_S_BUSINESS_SUFFIX_EXACT")
    old_helpers = functions(b"".join(old_s.splitlines(keepends=True)[:165]))
    new_s_functions = functions(after["validation/run.py"])
    require(all(new_s_functions[name] == body for name, body in old_helpers.items()), "ALL_ORIGINAL_S_HELPER_FUNCTIONS_EXACT")
    require(_source_function(after["materializer/prepare.py"], "source_authority") == _source_function(after["validation/run.py"], "source_authority"), "IDENTICAL_M_S_CURRENT_SOURCE_AUTHORITY")
    require(_source_function(after["materializer/prepare.py"], "validate_complete_source_tree") == tree_join
            and _source_function(after["validation/run.py"], "validate_complete_source_tree") == tree_join, "WHOLE_ORIGINAL_SOURCE_TREE_JOIN_EXACT")
    forward = make_patch(before, after, members)
    reverse = make_patch(after, before, members)
    require(apply_patch(before, forward, members) == after and apply_patch(after, reverse, members) == before,
            "COMPLETE_NORMAL18_FORWARD_REVERSE_REPLAY")
    checks = {"schema": 1, "status": "CURRENT_NORMAL18_DERIVED_NOT_APPROVED_NO_RUNTIME", "source_sha": SOURCE_SHA,
              "binding": binding_pointer, "source_data": data_pointer, "source_members18": list(members),
              "before": {name: pin(before[name]) for name in members}, "candidate": {name: pin(after[name]) for name in members},
              "historical_prefix_edges": 25, "current_edges": len(current_edges), "total_edges": len(edges),
              "merges": data["merge_count"], "cumulative_patch_base": PATCH_BASE, "cumulative_paths": len(committed),
              "capture_patch_base": capture["baseline_sha"], "capture_paths": capture["delta_file_count"],
              "original_business_suffix_exact": True, "all_original_S_helpers_exact": True,
              "whole_original_source_tree_join_exact": True, "identical_M_S_current_source_authority": True,
              "complete_forward_reverse_replay": True, "history_fixed_descriptors_not_current_runtime": True,
              "runtime": "NOT RUN", "approval": "NOT_GRANTED_REQUIRES_INDEPENDENT_NORMAL18_SOURCE_REVIEW"}
    proofs = {"FORWARD.patch": forward, "REVERSE.patch": reverse, "AUTHOR-CHECKS.json": encode(checks)}
    proofs["PINS.json"] = encode({"schema": 1, "status": checks["status"], "source_sha": SOURCE_SHA,
                                 "binding": binding_pointer, "source_data": data_pointer, "source_members18": list(members),
                                 "members": checks["candidate"], "author_checks": {"path": (normal / "source-proof-r61/AUTHOR-CHECKS.json").as_posix(), **pin(proofs["AUTHOR-CHECKS.json"])}})
    # All candidates and inverse proofs are complete before the first new write.
    for name, raw in after.items():
        target = normal / name
        ordinary(target, missing_leaf=True)
        if target.exists():require(stable_read(target) == raw, "DO_NOT_MUTATE_EXISTING_NORMAL_SOURCE_MEMBER")
    write_set(qualification, {"SOURCE-DATA.json": data_raw, "CURRENT-BINDING.json": binding_raw})
    write_set(normal / "source-proof-r61", proofs)
    for name, raw in after.items():
        target = normal / name
        target.parent.mkdir(parents=True, exist_ok=True)
        if not target.exists():
            with target.open("xb") as handle:require(handle.write(raw) == len(raw), "COMPLETE_NEW_NORMAL_SOURCE_WRITE")
        require(stable_read(target) == raw, "COMPLETE_NORMAL18_SOURCE_READBACK")
    return {"status": checks["status"], "source_sha": SOURCE_SHA, "normal_root": normal.as_posix(),
            "binding": binding_pointer, "source_data": data_pointer, "PINS": _pointer(normal / "source-proof-r61/PINS.json"),
            "current_edges": len(current_edges), "total_edges": len(edges), "merges": data["merge_count"],
            "cumulative_paths": len(committed), "source_members": 18, "runtime": "NOT RUN"}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    destination = parser.add_mutually_exclusive_group(required=True)
    destination.add_argument("--output", help="Derive the exact Core9 profile directory.")
    destination.add_argument("--normal-source", help="Author the complete normal18 around an existing reviewed Core9.")
    parser.add_argument("--initializer-syntax-result", help="Actual successful current initializer syntax receipt, for normal18.")
    parser.add_argument("--authority-file", required=True)
    parser.add_argument("--authority-sha256", required=True)
    args = parser.parse_args()
    require(re.fullmatch(r"[a-f0-9]{64}", args.authority_sha256) is not None, "FIXED_AUTHORITY_SHA256")
    path = Path(args.authority_file)
    raw = stable_read(path)
    pointer = {"path": path.as_posix(), "bytes": len(raw), "sha256": args.authority_sha256}
    require(pin(raw)["sha256"] == args.authority_sha256, "EXPLICIT_AUTHORITY_SHA256_MATCH")
    if args.normal_source:
        require(args.initializer_syntax_result is not None, "ACTUAL_INITIALIZER_SYNTAX_RESULT_REQUIRED")
        result = build_normal(args.normal_source, pointer, args.initializer_syntax_result)
    else:
        require(args.initializer_syntax_result is None, "SYNTAX_RECEIPT_ONLY_FOR_NORMAL18")
        result = build_profile(args.output, pointer)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
