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
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import subprocess
from typing import Any

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
CUSTOMER_PATH = "help/audit-regressions/isolated/ms4-customer-http-normal.php"
CUSTOMER_BEFORE = {"path": CUSTOMER_PATH, "bytes": 121457,
                   "sha256": "1fae540f4f145ace9ed94d17648d785f1663c4e8d6dbd0b2de54b75ffcbdea75"}
FULFILLMENT_PATH = "help/audit-regressions/isolated/ms4-fulfillment-normal.php"
LEGACY_PATH = "help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php"
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
    source_sha = authority.get("source_sha") if type(authority) is dict else None
    require(type(source_sha) is str and re.fullmatch(r"[a-f0-9]{40}", source_sha) is not None,
            "EXPLICIT_REVIEWED_SOURCE_COMMIT")
    require(type(authority) is dict and set(authority) == {
        "schema", "scope", "status", "source_sha", "candidate_root", "validation_root",
        "capture", "independent_review", "baseline_profile"}, "EXACT_TECHNICAL_AUTHORITY_SCHEMA")
    require(type(authority["schema"]) is int and authority["schema"] == 1
            and authority["scope"] == "R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY"
            and authority["status"] == "INDEPENDENTLY_REVIEWED_SOURCE_ONLY"
            and authority["source_sha"] == source_sha
            and authority["baseline_profile"] == BASELINE_PINS, "FIXED_CURRENT_SOURCE_AND_BASELINE_AUTHORITY")
    require(type(authority["candidate_root"]) is str and type(authority["validation_root"]) is str
            and authority["candidate_root"] != authority["validation_root"], "TWO_DISTINCT_REVIEWED_TREES")
    for name in ("candidate_root", "validation_root"):
        root = Path(authority[name])
        ordinary(root)
        require(root.is_dir() and root.resolve(strict=True).as_posix() == authority[name], "CANONICAL_REVIEWED_TREE")
    capture = decode(read_pointer(authority["capture"]))
    require(capture["schema"] == 1 and capture["status"] == "CAPTURED_NOT_APPROVED"
            and capture["source_approval"] == "NOT_GRANTED" and capture["source_sha"] == source_sha
            and capture["candidate"] == authority["candidate_root"]
            and capture["validation"] == authority["validation_root"], "CAPTURE_MATCHES_REVIEWED_TWO_TREES")
    review = decode(read_pointer(authority["independent_review"]))
    require(type(review) is dict and review.get("schema") == 1
            and review.get("status") == "PASS_CURRENT_SOURCE_TECHNICAL_REVIEW"
            and review.get("source_sha") == source_sha
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
    require(delta["source_sha"] == source_sha and delta["status"] == "CAPTURED_NOT_APPROVED"
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


def validate_isolation_subnet(value: str) -> str:
    """Validate the explicit choice only; do not inspect or reserve networks."""
    require(type(value) is str and "/" in value, "EXPLICIT_ISOLATION_IPV4_CIDR")
    try:
        network = ipaddress.IPv4Network(value, strict=True)
    except (ipaddress.AddressValueError, ipaddress.NetmaskValueError, ValueError) as error:
        raise ValueError("INVALID_ISOLATION_IPV4_CIDR") from error
    private_ranges = (ipaddress.IPv4Network(block) for block in
                      ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))
    require(str(network) == value and any(network.subnet_of(block) for block in private_ranges),
            "CANONICAL_RFC1918_ISOLATION_IPV4_CIDR")
    return str(network)


_SCHEMA_INITIALIZER = r'''
/** Explicit maintenance metadata, after original prerequisites and before any App. */
function m4i_schema_bootstrap(array $binding, array $history): array
{
    audit_guard_assert();
    $sourceFiles = m4c_entry_schema_sources();
    $privatePath = '/run/audit-bootstrap/migrator.json';
    $privateBefore = m2f_file($privatePath);
    $migrator = m4i_read($privatePath);
    $config = audit_config();
    m2f_assert(($migrator['username'] ?? null) === 'audit_migrator'
        && ($config['database']['username'] ?? null) === 'audit_app', 'MS6_SEPARATE_NATIVE_MAINTENANCE_ACCOUNT');
    $db = new PDO('mysql:host=audit-mysql;port=3306;dbname=audit_auth;charset=utf8mb4',
        $migrator['username'], $migrator['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3]);
    unset($migrator);
    m2f_assert(!$db->inTransaction() && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_migrator@%'
        && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth', 'MS6_NATIVE_MAINTENANCE_IDENTITY');
    $schemaGrant = false;
    foreach ($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) === 1) continue;
        m2f_assert(preg_match('/^GRANT ALL PRIVILEGES ON \x60audit_auth\x60\.\* TO /', $grant) === 1
            && !str_contains($grant, 'GRANT OPTION'), 'MS6_EXISTING_SCHEMA_ONLY_MAINTENANCE_GRANT');
        $schemaGrant = true;
    }
    m2f_assert($schemaGrant, 'MS6_EXISTING_MAINTENANCE_GRANT_PRESENT');
    require_once '/workspace/help/migrations/marketplace-ms6-schema.php';
    $manifest = \crmeb\services\marketplace\SchemaVersionGate::manifest('/workspace/crmeb/marketplace-release.json');
    m2f_assert($manifest === ['product' => 'generic-multi-merchant', 'app_version' => '0.1.0',
        'schema_min' => 1, 'schema_max' => 1]
        && \crmeb\services\marketplace\SchemaVersionGate::inspect($db, 'eb_') === null,
        'MS6_FIXED_MANIFEST_AND_ABSENT_METADATA_AFTER_ORIGINAL_PREREQUISITES');
    $started = time();
    $migration = new \Audit\Migration\MarketplaceMs6SchemaMigration($db, 'eb_', $manifest);
    $applied = $migration->apply();
    $checked = $migration->check();
    $schema = \crmeb\services\marketplace\SchemaVersionGate::assertCompatible(audit_db(), 'eb_', $manifest);
    m2f_assert(array_keys($schema) === ['id', 'schema_version', 'min_app_version', 'updated_at']
        && $schema['id'] === 1 && $schema['schema_version'] === 1 && $schema['min_app_version'] === '0.1.0'
        && is_int($schema['updated_at']) && $schema['updated_at'] >= $started && $schema['updated_at'] <= time()
        && $applied === ['mode' => 'apply', 'state' => 'created', 'changed' => true, 'code' => null, 'schema' => $schema]
        && $checked === ['mode' => 'check', 'state' => 'compatible', 'changed' => false, 'code' => null, 'schema' => $schema],
        'MS6_ORIGINAL_MIGRATION_AND_DML_GATE_SAME_INDEPENDENT_INITIAL_ROW');
    $receipt = ['schema' => 1, 'scope' => 'MS6_SCHEMA_METADATA_BEFORE_FIRST_APP_ONLY',
        'status' => 'VERIFIED_MS6_SCHEMA_METADATA_BEFORE_FIRST_APP_ONLY',
        'binding' => ['run_id' => getenv('AUDIT_RUN_ID'), 'source_sha' => $binding['source_sha'],
            'container_id' => $binding['container_id'], 'entry_namespace_pid' => $binding['entry_namespace_pid'],
            'created_sha256' => $binding['created_sha256'], 'freeze_sha256' => $binding['freeze_sha256'],
            'early_result_sha256' => $history['hashes']['ms4-aftersale-early-migration-normal.json']],
        'source_files' => $sourceFiles, 'manifest' => $manifest, 'apply' => $applied, 'check' => $checked,
        'application_schema' => $schema, 'App_business' => 'NOT RUN'];
    m2f_assert(m4c_entry_schema_receipt($receipt, $history) === $schema
        && m4c_entry_schema_sources() === $sourceFiles && m2f_file($privatePath) === $privateBefore,
        'MS6_SOURCE_PRIVATE_INPUT_AND_EXACT_CONSUMER_CONTRACT_RETAINED');
    audit_guard_assert();
    return $receipt;
}

'''

_SCHEMA_HOST = r'''
def verify_schema_bootstrap(context, result):
    receipt = result['schema_bootstrap']; row = receipt['application_schema']
    expect(type(receipt) is dict and set(receipt) == {'schema', 'scope', 'status', 'binding', 'source_files',
        'manifest', 'apply', 'check', 'application_schema', 'App_business'}
        and type(receipt['schema']) is int and receipt['schema'] == 1
        and receipt['scope'] == 'MS6_SCHEMA_METADATA_BEFORE_FIRST_APP_ONLY'
        and receipt['status'] == 'VERIFIED_MS6_SCHEMA_METADATA_BEFORE_FIRST_APP_ONLY'
        and receipt['App_business'] == 'NOT RUN', 'EXACT_MS6_BEFORE_APP_RECEIPT')
    binding = result['binding']
    expect(receipt['binding'] == {'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': binding['container_id'], 'entry_namespace_pid': binding['entry_namespace_pid'],
        'created_sha256': binding['created_sha256'], 'freeze_sha256': context['freeze_sha256'],
        'early_result_sha256': digest((Path(context['run_dir']) / 'evidence/ms4-aftersale-early-migration-normal.json').read_bytes())}
        and receipt['source_files'] == list(SCHEMA_SOURCE_FILES)
        and receipt['manifest'] == {'product': 'generic-multi-merchant', 'app_version': '0.1.0', 'schema_min': 1, 'schema_max': 1},
        'MS6_SAME_ORIGINAL_BOOTSTRAP_AND_FIXED_SOURCE')
    expect(type(row) is dict and set(row) == {'id', 'schema_version', 'min_app_version', 'updated_at'}
        and type(row['id']) is int and row['id'] == 1 and type(row['schema_version']) is int and row['schema_version'] == 1
        and row['min_app_version'] == '0.1.0' and type(row['updated_at']) is int and 0 < row['updated_at'] <= 4294967295
        and receipt['apply'] == {'mode': 'apply', 'state': 'created', 'changed': True, 'code': None, 'schema': row}
        and receipt['check'] == {'mode': 'check', 'state': 'compatible', 'changed': False, 'code': None, 'schema': row},
        'MS6_EXACT_NATIVE_MAINTENANCE_APPLY_CHECK_AND_DML_GATE')


'''



MERCHANT_EXIT_SOURCE_PATHS = (
    "help/migrations/marketplace-merchant-exit-menus.php",
    "help/migrations/marketplace-ms1.php",
    "help/migrations/marketplace-ms2-task7-submit-menu.php",
)

_MERCHANT_EXIT_INITIALIZER = r'''/** Add the two leaves before any early snapshot; never bootstrap App here. */
function m4i_exit_sources(array $binding): array
{
    $context = m4i_read('/run/audit/actual-run-context.json');
    $freeze = m4i_read('/run/audit/preparation-source-binding.json');
    m2f_assert(getenv('AUDIT_RUN_ID') === $context['run_id'] && $binding['source_sha'] === $context['source_sha']
        && $binding['freeze_sha256'] === hash_file('sha256', '/run/audit/preparation-source-binding.json'),
        'EXIT_SAME_INITIALIZER_AND_COMPLETE_SOURCE_AUTHORITY');
    $tree = m4i_source_tree_plan($context, $freeze); $sources = M4I_MERCHANT_EXIT_SOURCES;
    foreach ($sources as $row) {
        $matches = array_values(array_filter($tree['files'], static fn(array $item): bool => $item['path'] === $row['path']));
        m2f_assert($matches === [$row], 'EXIT_EXACT_CURRENT_COMPLETE_TREE_SOURCE');
        foreach (['/workspace', '/source'] as $root) {
            m2f_assert(realpath($root . '/' . $row['path']) === $root . '/' . $row['path'], 'EXIT_CANONICAL_SOURCE');
            m4i_sourceFile($root, $row);
        }
    }
    return $sources;
}
function m4i_exit_snapshot(PDO $db, PDO $maintenance): array
{
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    m2f_assert(count($names) === 166 && in_array('eb_system_menus', $names, true)
        && in_array('eb_system_role', $names, true) && in_array('eb_marketplace_role', $names, true),
        'EXIT_ORIGINAL_POST_NINE_166_TABLES');
    $spec = ['tables' => [], 'redis' => ['*']]; $ddl = [];
    foreach ($names as $name) {
        m2f_assert(preg_match('/\A(eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1, 'EXIT_FIXED_SCHEMA_TABLE_NAME');
        if ($name !== 'eb_system_menus') $spec['tables'][$name] = null;
        $sql = $db->query('SHOW CREATE TABLE ' . m2f_quote($name))->fetch(PDO::FETCH_NUM)[1];
        if ($name === 'eb_system_menus') {
            $sql = preg_replace('/ AUTO_INCREMENT=[0-9]+\b/', '', $sql, -1, $count);
            m2f_assert(is_string($sql) && $count <= 1, 'EXIT_ONLY_MENU_AUTO_INCREMENT_OPTION_NORMALIZED');
        }
        $ddl[$name] = hash('sha256', $sql);
    }
    $snapshot = audit_snapshot($spec); $cache = [];
    foreach ($snapshot['redis']['*'] as $key => $hash) {
        $keyHash = hash('sha256', (string)$key);
        m2f_assert(!isset($cache[$keyHash]), 'EXIT_DISTINCT_ORIGINAL_SNAPSHOT_CACHE_KEYS');
        $cache[$keyHash] = $hash;
    }
    ksort($cache, SORT_STRING);
    return ['names' => $names, 'tables' => $snapshot['tables'], 'ddl' => $ddl, 'redis' => $cache,
        'application_modes' => m2f_modes($db), 'maintenance_modes' => m2f_modes($maintenance),
        'application_grants_sha256' => hash('sha256', m2f_json($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))),
        'maintenance_grants_sha256' => hash('sha256', m2f_json($maintenance->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)))];
}
function m4i_exit_menus(array $binding): array
{
    audit_guard_assert(); $sourceFiles = m4i_exit_sources($binding);
    $privatePath = '/run/audit-bootstrap/migrator.json'; $privateBefore = m2f_file($privatePath);
    $appPath = '/run/audit-app/config.json'; $appBefore = m2f_file($appPath);
    $migrator = m4i_read($privatePath); $config = audit_config();
    m2f_assert(($migrator['username'] ?? null) === 'audit_migrator'
        && ($config['database']['username'] ?? null) === 'audit_app', 'EXIT_ORIGINAL_SEPARATE_ACCOUNTS');
    $maintenance = new PDO('mysql:host=audit-mysql;port=3306;dbname=audit_auth;charset=utf8mb4',
        $migrator['username'], $migrator['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 3]);
    unset($migrator); $db = audit_db();
    m2f_assert(!$maintenance->inTransaction() && !$db->inTransaction()
        && $maintenance->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_migrator@%'
        && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
        && $maintenance->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
        && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth', 'EXIT_REAL_DEDICATED_SCHEMA_IDENTITIES');
    require_once '/workspace/help/migrations/marketplace-merchant-exit-menus.php';
    $permissions = ['marketplace-merchants-exit-precheck', 'marketplace-merchants-close'];
    $before = m4i_exit_snapshot($db, $maintenance);
    $menusBefore = $db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $migration = new MarketplaceMerchantExitMenus($maintenance, 'eb_');
    $precheck = $migration->check();
    $expectedPre = ['mode' => 'check', 'state' => 'applicable', 'changed' => false, 'tables' => [],
        'menu_ids' => [], 'roles_modified' => false, 'atomic_scope' => 'one_permission_leaf', 'leaves' => [], 'issues' => []];
    foreach ($permissions as $permission) $expectedPre['leaves'][$permission] = ['mode' => 'check',
        'state' => 'applicable', 'changed' => false, 'tables' => [], 'menu_ids' => [], 'roles_modified' => false, 'issues' => []];
    m2f_assert($precheck === $expectedPre, 'EXIT_FRESH_EXACT_TWO_ABSENT_LEAVES');
    $applied = $migration->apply(); $checked = $migration->check(); $ids = $applied['menu_ids'] ?? [];
    m2f_assert(is_array($ids) && array_keys($ids) === $permissions && count(array_unique(array_values($ids))) === 2,
        'EXIT_TWO_DISTINCT_CREATED_IDENTITIES');
    $expectedApply = ['mode' => 'apply', 'state' => 'completed', 'changed' => true, 'tables' => [],
        'menu_ids' => $ids, 'roles_modified' => false, 'atomic_scope' => 'one_permission_leaf', 'leaves' => [], 'issues' => []];
    $expectedCheck = $expectedApply; $expectedCheck['mode'] = 'check'; $expectedCheck['changed'] = false;
    foreach ($ids as $permission => $id) {
        m2f_assert(is_int($id) && $id > 0 && $id <= 65535, 'EXIT_NATIVE_SMALLINT_MENU_ID');
        $leaf = ['mode' => 'check', 'state' => 'completed', 'changed' => false, 'tables' => [],
            'menu_ids' => [$permission => $id], 'roles_modified' => false, 'issues' => []];
        $expectedCheck['leaves'][$permission] = $leaf;
        $leaf['mode'] = 'apply'; $leaf['changed'] = true; $leaf['write_attempted'] = true;
        $leaf['commit_observed'] = true; $leaf['rollback_observed'] = null; $leaf['created_menu_ids'] = [$permission => $id];
        $expectedApply['leaves'][$permission] = $leaf;
    }
    m2f_assert($applied === $expectedApply && $checked === $expectedCheck && !$maintenance->inTransaction(),
        'EXIT_ORIGINAL_TWO_LEAF_NATIVE_APPLY_CHECK_COMPLETED');
    $menusAfter = $db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $retained = []; $added = [];
    foreach ($menusAfter as $row) {
        $permission = array_search((int)$row['id'], $ids, true);
        if ($permission === false) $retained[] = $row;
        else { m2f_assert($row['unique_auth'] === $permission, 'EXIT_CREATED_ROW_MATCHES_EXACT_PERMISSION'); $added[] = $row; }
    }
    $after = m4i_exit_snapshot($db, $maintenance);
    m2f_assert($before === $after && $retained === $menusBefore && count($added) === 2
        && count($menusAfter) === count($menusBefore) + 2, 'EXIT_ONLY_TWO_INSERTS_ALL_OLD_MENUS_ROLES_SCHEMA_CACHE_MODES_RETAINED');
    m2f_assert(m4i_exit_sources($binding) === $sourceFiles && m2f_file($privatePath) === $privateBefore
        && m2f_file($appPath) === $appBefore, 'EXIT_SOURCE_AND_ORIGINAL_PRIVATE_INPUTS_RETAINED');
    audit_guard_assert();
    return ['schema' => 1, 'scope' => 'MERCHANT_EXIT_MENUS_BEFORE_FIRST_APP_ONLY',
        'status' => 'VERIFIED_MERCHANT_EXIT_MENUS_BEFORE_FIRST_APP_ONLY',
        'stage' => 'AFTER_ORIGINAL_NINE_BEFORE_EARLY_SEAL',
        'binding' => ['run_id' => getenv('AUDIT_RUN_ID')] + array_intersect_key($binding, array_flip([
            'source_sha', 'container_id', 'entry_namespace_pid', 'created_sha256', 'freeze_sha256'])),
        'source_files' => $sourceFiles, 'maintenance_account' => 'audit_migrator', 'application_account' => 'audit_app',
        'precheck' => $precheck, 'apply' => $applied, 'check' => $checked, 'menu_ids' => $ids,
        'preservation_before' => $before, 'preservation_after' => $after,
        'menus_before' => ['count' => count($menusBefore), 'sha256' => hash('sha256', m2f_json($menusBefore))],
        'retained_menus_after' => ['count' => count($retained), 'sha256' => hash('sha256', m2f_json($retained))],
        'menus_after' => ['count' => count($menusAfter), 'sha256' => hash('sha256', m2f_json($menusAfter))],
        'added_menu_rows_sha256' => hash('sha256', m2f_json($added)), 'App_business' => 'NOT RUN'];
}

'''

_MERCHANT_EXIT_HOST = r'''def verify_merchant_exit_bootstrap(context, result):
    receipt = result['merchant_exit_menus']; binding = result['binding']
    expect(type(receipt) is dict and set(receipt) == {'schema', 'scope', 'status', 'stage', 'binding',
        'source_files', 'maintenance_account', 'application_account', 'precheck', 'apply', 'check', 'menu_ids',
        'preservation_before', 'preservation_after', 'menus_before', 'retained_menus_after', 'menus_after',
        'added_menu_rows_sha256', 'App_business'} and type(receipt['schema']) is int and receipt['schema'] == 1
        and receipt['scope'] == 'MERCHANT_EXIT_MENUS_BEFORE_FIRST_APP_ONLY'
        and receipt['status'] == 'VERIFIED_MERCHANT_EXIT_MENUS_BEFORE_FIRST_APP_ONLY'
        and receipt['stage'] == 'AFTER_ORIGINAL_NINE_BEFORE_EARLY_SEAL'
        and receipt['maintenance_account'] == 'audit_migrator' and receipt['application_account'] == 'audit_app'
        and receipt['App_business'] == 'NOT RUN', 'EXIT_EXACT_BEFORE_APP_NATIVE_RECEIPT')
    expect(receipt['binding'] == {'run_id':context['run_id'], **{key: binding[key] for key in ['source_sha', 'container_id',
        'entry_namespace_pid', 'created_sha256', 'freeze_sha256']}}
        and binding['source_sha'] == context['source_sha']
        and binding['freeze_sha256'] == context['freeze_sha256'] and binding['entry_namespace_pid'] == 1,
        'EXIT_SAME_ACTUAL_INITIALIZER_RUN_CID_AND_FREEZE')
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    complete = {row['path']: row for row in frozen['source_tree']['files']}
    expect(receipt['source_files'] == list(MERCHANT_EXIT_SOURCE_FILES)
        and receipt['source_files'] == [complete[row['path']] for row in MERCHANT_EXIT_SOURCE_FILES],
        'EXIT_EXACT_THREE_CURRENT_FROZEN_MIGRATION_INPUTS')
    permissions = ['marketplace-merchants-exit-precheck', 'marketplace-merchants-close']; ids = receipt['menu_ids']
    expect(type(ids) is dict and list(ids) == permissions and len(set(ids.values())) == 2
        and all(type(value) is int and 0 < value <= 65535 for value in ids.values()), 'EXIT_EXACT_TWO_NEW_MENU_IDENTITIES')
    pre = {'mode':'check','state':'applicable','changed':False,'tables':[], 'menu_ids':{},
        'roles_modified':False,'atomic_scope':'one_permission_leaf','leaves':{},'issues':[]}
    applied = {'mode':'apply','state':'completed','changed':True,'tables':[],'menu_ids':ids,
        'roles_modified':False,'atomic_scope':'one_permission_leaf','leaves':{},'issues':[]}
    checked = {**applied,'mode':'check','changed':False,'leaves':{}}
    for permission in permissions:
        pre['leaves'][permission] = {'mode':'check','state':'applicable','changed':False,'tables':[],
            'menu_ids':[],'roles_modified':False,'issues':[]}
        leaf = {'mode':'check','state':'completed','changed':False,'tables':[],
            'menu_ids':{permission:ids[permission]},'roles_modified':False,'issues':[]}
        checked['leaves'][permission] = leaf
        applied['leaves'][permission] = {**leaf,'mode':'apply','changed':True,'write_attempted':True,
            'commit_observed':True,'rollback_observed':None,'created_menu_ids':{permission:ids[permission]}}
    # PHP's empty associative menu_ids is encoded as []; all nonempty identity maps stay objects.
    pre['menu_ids'] = []
    expect(receipt['precheck'] == pre and receipt['apply'] == applied and receipt['check'] == checked,
        'EXIT_ORIGINAL_NATIVE_TWO_LEAF_APPLY_CHECK_AND_NO_ROLE_GRANTS')
    before = receipt['preservation_before']; after = receipt['preservation_after']
    expect(type(before) is dict and set(before) == {'names','tables','ddl','redis','application_modes',
        'maintenance_modes','application_grants_sha256','maintenance_grants_sha256'} and before == after
        and type(before['names']) is list and before['names'] == sorted(set(before['names']))
        and len(before['names']) == 166 and set(before['tables']) == set(before['names']) - {'eb_system_menus'}
        and set(before['ddl']) == set(before['names']) and {'eb_system_role','eb_marketplace_role'} <= set(before['tables']),
        'EXIT_ALL_OLD_TABLES_ROLES_SCHEMA_REDIS_MODES_GRANTS_RETAINED')
    for value in before['tables'].values():
        expect(type(value) is dict and set(value) == {'count','sha256'} and type(value['count']) is int
            and value['count'] >= 0 and re.fullmatch('[a-f0-9]{64}',value['sha256']), 'EXIT_COMPLETE_PROTECTED_TABLE_DIGESTS')
    for value in list(before['ddl'].values()) + [before['application_grants_sha256'], before['maintenance_grants_sha256'],
            receipt['added_menu_rows_sha256']]:
        expect(type(value) is str and re.fullmatch('[a-f0-9]{64}',value), 'EXIT_TYPED_NATIVE_SCHEMA_AND_GRANT_DIGESTS')
    for key in ['application_modes','maintenance_modes']:
        expect(type(before[key]) is dict and set(before[key]) == {'session_mode','global_mode'}, 'EXIT_ORIGINAL_NATIVE_MODE_PAIR')
    expect(type(before['redis']) is dict and bool(before['redis']), 'EXIT_ORIGINAL_SENTINEL_CACHE_SNAPSHOT')
    for key,value in before['redis'].items():
        expect(type(key) is str and re.fullmatch('[a-f0-9]{64}',key) and type(value) is str
            and re.fullmatch('[a-f0-9]{64}',value), 'EXIT_ORIGINAL_FULL_CACHE_SNAPSHOT_VALUES')
    for key in ['menus_before','retained_menus_after','menus_after']:
        value = receipt[key]
        expect(type(value) is dict and set(value) == {'count','sha256'} and type(value['count']) is int
            and value['count'] >= 0 and re.fullmatch('[a-f0-9]{64}',value['sha256']), 'EXIT_COMPLETE_MENU_ROW_DIGEST')
    expect(receipt['menus_before'] == receipt['retained_menus_after']
        and receipt['menus_after']['count'] == receipt['menus_before']['count'] + 2,
        'EXIT_ONLY_TWO_NEW_ROWS_AND_ALL_ORIGINAL_MENU_ROWS_EXACT')


'''


def merchant_exit_profile_changes(initializer: bytes, host: bytes, inventory: dict[str, dict[str, Any]]):
    """Append the original two-leaf DML after the nine children, before early sealing."""
    rows = tuple(inventory[name] for name in MERCHANT_EXIT_SOURCE_PATHS)
    php_rows = ("const M4I_MERCHANT_EXIT_SOURCES = [" + ", ".join(
        "['path' => '" + row["path"] + "', 'bytes' => " + str(row["bytes"])
        + ", 'sha256' => '" + row["sha256"] + "']" for row in rows) + "];\n\n").encode()
    helper = php_rows + _MERCHANT_EXIT_INITIALIZER.encode('utf-8')
    anchor = b"function m4i_initialize(array &$evidence, ?array &$primary, ?array &$cleanup): void\n"
    updated = once(initializer, anchor, helper + anchor)
    nine = b"        m2f_assert(count($evidence['children']) === 9 && count(array_unique(array_column($evidence['children'], 'pid'))) === 9, 'NINE_REAL_PREREQUISITE_CHILDREN');\n"
    call = b"        $evidence['merchant_exit_menus'] = m4i_exit_menus($evidence['binding']);\n"
    updated = once(updated, nine, nine + call)
    require(once(once(updated, nine + call, nine), helper + anchor, anchor) == initializer,
            'EXACT_INITIALIZER_EXIT_ONLY_WHOLE_FILE_INVERSE')
    host_helper = ("MERCHANT_EXIT_SOURCE_FILES = " + repr(rows) + "\n" + _MERCHANT_EXIT_HOST).encode('utf-8')
    host_anchor = b"def prerequisite_result(context, created_facts, terminal, result):\n"
    updated_host = once(host, host_anchor, host_helper + host_anchor)
    original = host_anchor + b"    owned(context, terminal, 'audit-ms4-initialize'); same_created(created_facts, terminal)\n    source_tree_copy_result(context, result)\n"
    consumer = b"    verify_merchant_exit_bootstrap(context, result)\n"
    updated_host = once(updated_host, original, original + consumer)
    require(once(once(updated_host, original + consumer, original), host_helper + host_anchor, host_anchor) == host,
            'EXACT_HOST_EXIT_ONLY_WHOLE_FILE_INVERSE')
    compile(updated_host, 'derived-ms4-host-exit.py', 'exec')
    return updated, updated_host, rows


ORDER_MENU_SOURCE_PATHS = (
    "help/audit-regressions/isolated/lib/ms4-order-menu-fixture.php",
    "help/audit-regressions/orchestration/order_menu_receipt.py",
    "help/preview/storefront.php",
    "crmeb/public/install/crmeb.sql",
    "help/migrations/marketplace-ms4-order-read-menus.php",
    "help/migrations/marketplace-ms1.php",
)
ORDER_MENU_PROFILE_MODES = {"normal": "NORMAL_A_WITH_READ_LEAVES", "ui": "UI_B_ANCHORS_ONLY"}

_ORDER_MENU_INITIALIZER = r'''function m4i_order_menu_sources(array $binding): array
{
    $context = m4i_read('/run/audit/actual-run-context.json');
    $freeze = m4i_read('/run/audit/preparation-source-binding.json');
    m2f_assert(getenv('AUDIT_RUN_ID') === $context['run_id'] && $binding['source_sha'] === $context['source_sha']
        && $binding['freeze_sha256'] === hash_file('sha256', '/run/audit/preparation-source-binding.json'),
        'ORDER_MENU_SAME_INITIALIZER_AND_COMPLETE_SOURCE_AUTHORITY');
    $tree = m4i_source_tree_plan($context, $freeze); $sources = M4I_ORDER_MENU_SOURCES;
    foreach ($sources as $row) {
        $matches = array_values(array_filter($tree['files'], static fn(array $item): bool => $item['path'] === $row['path']));
        m2f_assert($matches === [$row], 'ORDER_MENU_EXACT_CURRENT_COMPLETE_TREE_SOURCE');
        foreach (['/workspace', '/source'] as $root) {
            m2f_assert(realpath($root . '/' . $row['path']) === $root . '/' . $row['path'], 'ORDER_MENU_CANONICAL_SOURCE');
            m4i_sourceFile($root, $row);
        }
    }
    return $sources;
}
function m4i_order_menu_bootstrap(array $binding): array
{
    $sources = m4i_order_menu_sources($binding);
    require_once '/workspace/help/audit-regressions/isolated/lib/ms4-order-menu-fixture.php';
    $receipt = m4iom_prepare($binding, $sources, M4I_ORDER_MENU_WITH_READ_LEAVES);
    m2f_assert(m4i_order_menu_sources($binding) === $sources, 'ORDER_MENU_COMPLETE_SOURCE_RETAINED');
    return $receipt;
}

'''


def order_menu_profile_changes(initializer: bytes, host: bytes, inventory: dict[str, dict[str, Any]],
                               source_root: Path, *, with_read_leaves: bool):
    """Declare four original menu fixtures; only normal-A preinstalls the two read leaves."""
    require(type(with_read_leaves) is bool, "FIXED_AUTHOR_ORDER_MENU_PROFILE_MODE")
    rows = tuple(inventory[name] for name in ORDER_MENU_SOURCE_PATHS)
    source = read_pointer({"path": (source_root / ORDER_MENU_SOURCE_PATHS[0]).as_posix(),
                           **{key: rows[0][key] for key in ("bytes", "sha256")}})
    matches = re.findall(rb"^const M4I_ORDER_MENU_SOURCE_META = <<<'M4I_ORDER_MENU_SOURCE_JSON'\n(.*?)\nM4I_ORDER_MENU_SOURCE_JSON;",
                         source, flags=re.MULTILINE | re.DOTALL)
    require(len(matches) == 1, "ONE_FIXED_ORDER_MENU_SOURCE_META")
    meta = decode(matches[0])
    names = ["order_root", "order_page", "order_info_group", "order_info"]
    require(type(meta) is dict and set(meta) == {"columns", "rows"}
            and type(meta["columns"]) is list and len(meta["columns"]) == len(set(meta["columns"])) == 22
            and {"id", "pid", "path", "unique_auth", "auth_type", "api_url", "methods"} <= set(meta["columns"])
            and type(meta["rows"]) is dict and list(meta["rows"]) == names
            and all(type(row) is list and len(row) == 22
                    and all(value is None or type(value) in (str, int) for value in row)
                    for row in meta["rows"].values()), "EXACT_FOUR_DECLARED_NATIVE_MENU_SOURCE_ROWS")
    unique_index = meta["columns"].index("unique_auth")
    require([meta["rows"][name][unique_index] for name in names] == [
        "admin-order", "admin-order-storeOrder-index", "order-info", "order-info-6465a369a2c3b"],
        "ONLY_FOUR_ORIGINAL_ORDER_MENU_IDENTITIES")
    reader = read_pointer({"path": (source_root / ORDER_MENU_SOURCE_PATHS[1]).as_posix(),
                           **{key: rows[1][key] for key in ("bytes", "sha256")}})
    parsed_reader = ast.parse(reader)
    require(set(functions(reader)) == {"verify_order_menu_bootstrap"}
            and all(isinstance(node, ast.FunctionDef) or isinstance(node, ast.Expr)
                    and isinstance(node.value, ast.Constant) and isinstance(node.value.value, str)
                    for node in parsed_reader.body), "ONE_READONLY_ORDER_MENU_HOST_CONSUMER_NO_IMPORT_OR_TOPLEVEL_IO")
    mode = ORDER_MENU_PROFILE_MODES["normal" if with_read_leaves else "ui"]
    php_rows = ("const M4I_ORDER_MENU_SOURCES = [" + ", ".join(
        "['path' => '" + row["path"] + "', 'bytes' => " + str(row["bytes"])
        + ", 'sha256' => '" + row["sha256"] + "']" for row in rows) + "];\n"
        + "const M4I_ORDER_MENU_WITH_READ_LEAVES = " + ("true" if with_read_leaves else "false") + ";\n\n").encode()
    helper = php_rows + _ORDER_MENU_INITIALIZER.encode("utf-8")
    anchor = b"function m4i_initialize(array &$evidence, ?array &$primary, ?array &$cleanup): void\n"
    updated = once(initializer, anchor, helper + anchor)
    old_call = b"        $evidence['merchant_exit_menus'] = m4i_exit_menus($evidence['binding']);\n"
    call = b"        $evidence['order_menu_fixture'] = m4i_order_menu_bootstrap($evidence['binding']);\n"
    updated = once(updated, old_call, call + old_call)
    require(once(once(updated, call + old_call, old_call), helper + anchor, anchor) == initializer,
            "EXACT_INITIALIZER_DECLARED_ORDER_MENU_ONLY_WHOLE_FILE_INVERSE")
    host_helper = ("ORDER_MENU_SOURCE_FILES = " + repr(rows) + "\nORDER_MENU_SOURCE_META = " + repr(meta)
                   + "\nORDER_MENU_PROFILE_MODE = " + repr(mode) + "\n").encode() + reader + b"\n\n"
    host_anchor = b"def prerequisite_result(context, created_facts, terminal, result):\n"
    updated_host = once(host, host_anchor, host_helper + host_anchor)
    original = b"    verify_merchant_exit_bootstrap(context, result)\n"
    consumer = b"    verify_order_menu_bootstrap(context, result)\n"
    updated_host = once(updated_host, original, consumer + original)
    require(once(once(updated_host, consumer + original, original), host_helper + host_anchor, host_anchor) == host,
            "EXACT_HOST_DECLARED_ORDER_MENU_ONLY_WHOLE_FILE_INVERSE")
    compile(updated_host, "derived-ms4-host-order-menu.py", "exec")
    return updated, updated_host, rows, mode


def schema_profile_changes(initializer: bytes, host: bytes, inventory: dict[str, dict[str, Any]]):
    """Extend the existing bootstrap only; inverse-check both complete old files."""
    paths = (
        "crmeb/crmeb/exceptions/MarketplaceSchemaException.php",
        "crmeb/crmeb/services/marketplace/SchemaVersionGate.php",
        "crmeb/marketplace-release.json",
        "help/migrations/marketplace-ms6-schema.php",
    )
    source_files = tuple(inventory[name] for name in paths)
    helper = _SCHEMA_INITIALIZER.encode("utf-8")
    anchor = b"function m6i_migrator(): array\n"
    updated = once(initializer, anchor, helper + anchor)
    old_call = b"    $evidence['installation'] = m6i_migrator();"
    new_call = (b"    $evidence['schema_bootstrap'] = m4i_schema_bootstrap($evidence['binding'], $prerequisites['early_history']);\n"
                b"    $evidence['schema_bootstrap_preservation'] = m4c_entry_early_preserved(\n"
                b"        $prerequisites['early_history'], 'schema-bootstrap-completed', $evidence['schema_bootstrap']);\n"
                + old_call)
    updated = once(updated, old_call, new_call)
    old_completed = b"    m4c_entry_early_preserved($currentEarly,'files-bootstrap-completed');"
    new_completed = b"    m4c_entry_early_preserved($currentEarly,'files-bootstrap-completed',$evidence['schema_bootstrap']);"
    updated = once(updated, old_completed, new_completed)
    restored = once(once(once(updated, new_completed, old_completed), new_call, old_call), helper + anchor, anchor)
    require(restored == initializer, "EXACT_INITIALIZER_MS6_ONLY_WHOLE_FILE_INVERSE")
    host_helper = ("SCHEMA_SOURCE_FILES = " + repr(source_files) + "\n" + _SCHEMA_HOST).encode("utf-8")
    host_anchor = b"def verify_files_bootstrap(context, native_required=True):\n"
    updated_host = once(host, host_anchor, host_helper + host_anchor)
    before = b"           and installation['application_dml_only'] is True, 'ACTUAL_DEDICATED_FILES_BOOTSTRAP')\n"
    after = before + b"    verify_schema_bootstrap(context, result)\n"
    updated_host = once(updated_host, before, after)
    require(once(once(updated_host, after, before), host_helper + host_anchor, host_anchor) == host,
            "EXACT_HOST_MS6_ONLY_WHOLE_FILE_INVERSE")
    compile(updated_host, "derived-ms4-host.py", "exec")
    return updated, updated_host, source_files


def build_profile(output: str | Path, authority_pointer: dict[str, Any],
                  isolation_subnet: str | None = None) -> dict[str, Any]:
    if isolation_subnet is not None:
        isolation_subnet = validate_isolation_subnet(isolation_subnet)
    authority, inventory = reviewed_authority(authority_pointer)
    source_sha = authority["source_sha"]
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
    if isolation_subnet is not None:
        original_network = b"networks:\n  isolation:\n    internal: true\n    labels: *labels\nvolumes:"
        selected_network = (b"networks:\n  isolation:\n    internal: true\n    labels: *labels\n"
                            b"    ipam:\n      config:\n        - subnet: "
                            + isolation_subnet.encode("ascii") + b"\nvolumes:")
        after["compose.yml"] = once(before["compose.yml"], original_network, selected_network)
        require(once(after["compose.yml"], selected_network, original_network) == before["compose.yml"],
                "ONLY_EXPLICIT_ISOLATION_IPAM_BLOCK_CHANGED")
    interface = decode(before["interface-v1.json"])
    require(encode(interface) == before["interface-v1.json"], "ORIGINAL_INTERFACE_SERIALIZATION_RETAINED")
    seed = decode(before["seed-source-inverse.json"])
    require(inventory[seed["source_path"]]["sha256"] == seed["source_sha256"],
            "ORIGINAL_FINITE_SEED_CURRENT_SOURCE_IDENTITY")
    original_interface = copy.deepcopy(interface)
    source_review = decode(read_pointer(authority["independent_review"]))
    reviewed_updates = source_review.get("active_profile_updates")
    require(type(reviewed_updates) is list and len(reviewed_updates) in (2, 4),
            "EXACT_TWO_OR_FOUR_INDEPENDENT_PROFILE_UPDATES")
    extended_updates = len(reviewed_updates) == 4
    updates = [
        {"before": AR_BEFORE, "after": AR_CURRENT},
        {"before": CUSTOMER_BEFORE, "after": inventory[CUSTOMER_PATH]},
    ]
    if extended_updates:
        # These before rows belong to different fixed baseline groups. Only
        # the independent review may join them to this same capture's after rows.
        for path, source_rows in (
            (FULFILLMENT_PATH, interface["customer_form_current_profile"]["php_sources"]),
            (LEGACY_PATH, interface["fulfillment_normal_successor"]["three_read_internal"]["source_helper_files"]),
        ):
            matches = [row for row in source_rows if row["path"] == path]
            require(len(matches) == 1 and set(matches[0]) == {"path", "bytes", "sha256"},
                    "ONE_EXACT_FIXED_BASELINE_PROFILE_SOURCE_ROW")
            previous = copy.deepcopy(matches[0])
            require(inventory[path] != previous, "NEW_REVIEWED_FULFILLMENT_AND_LEGACY_SOURCE_BYTES")
            updates.append({"before": previous, "after": inventory[path]})
    require(reviewed_updates == updates and inventory[CUSTOMER_PATH] != CUSTOMER_BEFORE,
            "INDEPENDENT_EXACT_AFTERSALE_AND_MS6_CUSTOMER_UPDATES")
    common_updates = updates[:3] if extended_updates else updates
    old_active = {entry["before"]["path"]: entry["before"] for entry in common_updates}
    new_active = {entry["after"]["path"]: entry["after"] for entry in common_updates}
    legacy_update = updates[3] if extended_updates else None
    rows = interface["customer_form_current_profile"]["php_sources"]
    form_old_active, form_new_active = dict(old_active), dict(new_active)
    # The form oracle has its own reviewed update; the original four retain their meanings.
    form_helper_path = "help/audit-regressions/isolated/lib/ms4-customer-form-normal.php"
    helper_rows = [row for row in rows if row["path"] == form_helper_path]
    require(len(helper_rows) == 1 and set(helper_rows[0]) == {"path", "bytes", "sha256"},
            "ONE_EXACT_BASELINE_FORM_HELPER")
    helper_update = None
    if inventory[form_helper_path] != helper_rows[0]:
        helper_update = {"before": copy.deepcopy(helper_rows[0]), "after": inventory[form_helper_path]}
    require(source_review.get("customer_form_helper_update") == helper_update,
            "INDEPENDENT_EXACT_FORM_HELPER_UPDATE")
    if helper_update is not None:
        form_old_active[form_helper_path] = helper_update["before"]
        form_new_active[form_helper_path] = helper_update["after"]
    interface_changed_rows = len(form_old_active) + len(old_active) + (1 if legacy_update is not None else 0)
    require(all(sum(row == original for row in rows) == 1 for original in form_old_active.values()),
            "ONE_EACH_DECLARED_ACTIVE_FORM_ROW")
    for row in rows:
        require(inventory[row["path"]] == form_new_active.get(row["path"], row),
                "REVIEWED_SEVEN_ACTIVE_NORMAL_SOURCES")
        if row["path"] in form_new_active:
            row.update(form_new_active[row["path"]])
    runtime_rows = interface["source_tree_runtime_profile"]["actual_candidate_tracked_sources"]
    require(all(sum(row == original for row in runtime_rows) == 1 for original in old_active.values()),
            "ONE_EACH_DECLARED_ACTIVE_SOURCE_TREE_ROW")
    for row in runtime_rows:
        if row["path"] in new_active:
            row.update(new_active[row["path"]])
    if legacy_update is not None:
        legacy_rows = interface["fulfillment_normal_successor"]["three_read_internal"]["source_helper_files"]
        require(sum(row == legacy_update["before"] for row in legacy_rows) == 1,
                "ONE_DECLARED_THREE_READ_INTERFACE_SOURCE_ROW")
        for row in legacy_rows:
            wanted = legacy_update["after"] if row["path"] == LEGACY_PATH else row
            require(inventory[row["path"]] == wanted, "REVIEWED_THREE_READ_INTERFACE_SOURCES")
            if row["path"] == LEGACY_PATH:
                row.update(wanted)
    after["interface-v1.json"] = encode(interface)
    restored_interface = copy.deepcopy(interface)
    for rows, originals in ((restored_interface["customer_form_current_profile"]["php_sources"], form_old_active),
                            (restored_interface["source_tree_runtime_profile"]["actual_candidate_tracked_sources"], old_active)):
        for row in rows:
            if row["path"] in originals:
                row.update(originals[row["path"]])
    if legacy_update is not None:
        for row in restored_interface["fulfillment_normal_successor"]["three_read_internal"]["source_helper_files"]:
            if row["path"] == LEGACY_PATH:
                row.update(legacy_update["before"])
    require(restored_interface == original_interface,
            "ONLY_DECLARED_FORM_HELPER_AND_INTERFACE_ACTIVE_ROWS_CHANGED" if helper_update is not None
            else ("ONLY_SEVEN_DECLARED_INTERFACE_ACTIVE_ROWS_CHANGED" if extended_updates
                  else "ONLY_FOUR_DECLARED_INTERFACE_ACTIVE_ROWS_CHANGED"))
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        raw = after[name]
        original_raw = raw
        active = constant(raw, "CUSTOMER_FORM_SOURCE_FILES")
        require(type(active) is tuple
                and all(sum(row == original for row in active) == 1 for original in form_old_active.values()),
                "ORIGINAL_FROZEN_SOURCE_TUPLE")
        updated = tuple(form_new_active.get(row["path"], row) for row in active)
        for row in updated:
            require(inventory[row["path"]] == row, "ALL_CURRENT_ACTIVE_SOURCE_TUPLE_MEMBERS")
        three_read = constant(raw, "THREE_READ_SOURCE_FILES")
        if legacy_update is not None:
            require(type(three_read) is list
                    and sum(row == legacy_update["before"] for row in three_read) == 1,
                    "ONE_DECLARED_ORIGINAL_THREE_READ_SOURCE_TUPLE")
            three_read = [legacy_update["after"] if row["path"] == LEGACY_PATH else row
                          for row in three_read]
            raw = replace_constant(raw, "THREE_READ_SOURCE_FILES", three_read)
        for row in three_read:
            require(inventory[row["path"]] == row, "THREE_LEGACY_SOURCE_BYTES_RETAINED")
        for entry in ("PAID_ENTRY", "FULFILLMENT_ENTRY", "WORKER_ENTRY"):
            path = constant(raw, entry)
            original_entry = {"path": path, "bytes": constant(raw, entry + "_BYTES"),
                              "sha256": constant(raw, entry + "_SHA256")}
            if extended_updates and entry == "FULFILLMENT_ENTRY":
                require(original_entry == updates[2]["before"], "ORIGINAL_FULFILLMENT_ENTRY_PIN")
                raw = replace_constant(raw, entry + "_BYTES", updates[2]["after"]["bytes"])
                raw = replace_constant(raw, entry + "_SHA256", updates[2]["after"]["sha256"])
            else:
                require(inventory[path] == original_entry, "OTHER_ENTRY_PINS_RETAINED")
        raw = replace_constant(raw, "CUSTOMER_FORM_SOURCE_FILES", updated)
        require(constant(raw, "AFTERSALE_REVIEW_ENTRY_BYTES") == AR_BEFORE["bytes"]
                and constant(raw, "AFTERSALE_REVIEW_ENTRY_SHA256") == AR_BEFORE["sha256"], "OLD_ACTIVE_AFTERSALE_PIN")
        raw = replace_constant(raw, "AFTERSALE_REVIEW_ENTRY_BYTES", AR_CURRENT["bytes"])
        after[name] = replace_constant(raw, "AFTERSALE_REVIEW_ENTRY_SHA256", AR_CURRENT["sha256"])
        if extended_updates:
            recovered_raw = after[name]
            for symbol in ("CUSTOMER_FORM_SOURCE_FILES", "THREE_READ_SOURCE_FILES", "FULFILLMENT_ENTRY_BYTES",
                           "FULFILLMENT_ENTRY_SHA256", "AFTERSALE_REVIEW_ENTRY_BYTES", "AFTERSALE_REVIEW_ENTRY_SHA256"):
                original_nodes = [node for node in ast.parse(original_raw).body
                                  if isinstance(node, ast.Assign) and len(node.targets) == 1
                                  and isinstance(node.targets[0], ast.Name) and node.targets[0].id == symbol]
                require(len(original_nodes) == 1, "ONE_ORIGINAL_SOURCE_CONSTANT_ASSIGNMENT")
                original_assignment = ast.get_source_segment(original_raw.decode("utf-8"), original_nodes[0])
                require(type(original_assignment) is str, "COMPLETE_ORIGINAL_SOURCE_CONSTANT_BYTES")
                replacement = (symbol + " = " + repr(constant(recovered_raw, symbol))).encode("utf-8")
                recovered_raw = once(recovered_raw, replacement, original_assignment.encode("utf-8"))
            require(recovered_raw == original_raw, "FINITE_HOST_ZERO_SOURCE_CONSTANTS_WHOLE_FILE_INVERSE")
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
    def php_row(row):
        return ("['path' => '" + row["path"] + "', 'bytes' => " + str(row["bytes"])
                + ", 'sha256' => '" + row["sha256"] + "']").encode("utf-8")
    previous_initializer = after["ms4-initialize.php"]
    after["ms4-initialize.php"] = once(previous_initializer, php_row(CUSTOMER_BEFORE), php_row(inventory[CUSTOMER_PATH]))
    require(once(after["ms4-initialize.php"], php_row(inventory[CUSTOMER_PATH]), php_row(CUSTOMER_BEFORE))
            == previous_initializer, "ONE_CUSTOMER_LITERAL_WHOLE_FILE_INVERSE")
    if extended_updates:
        previous_initializer = after["ms4-initialize.php"]
        after["ms4-initialize.php"] = once(previous_initializer,
                                             php_row(updates[2]["before"]), php_row(updates[2]["after"]))
        require(once(after["ms4-initialize.php"], php_row(updates[2]["after"]), php_row(updates[2]["before"]))
                == previous_initializer, "ONE_FULFILLMENT_LITERAL_WHOLE_FILE_INVERSE")
    after["ms4-initialize.php"], after["ms4-host.py"], schema_sources = schema_profile_changes(
        after["ms4-initialize.php"], after["ms4-host.py"], inventory)
    after["ms4-initialize.php"], after["ms4-host.py"], exit_sources = merchant_exit_profile_changes(
        after["ms4-initialize.php"], after["ms4-host.py"], inventory)
    after["ms4-initialize.php"], after["ms4-host.py"], order_sources, order_mode = order_menu_profile_changes(
        after["ms4-initialize.php"], after["ms4-host.py"], inventory, Path(authority["validation_root"]),
        with_read_leaves=True)
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
        "scope": "REVIEWED_CURRENT_SOURCE_PROFILE_ONLY_NO_RUNTIME_ACCEPTANCE", "source_sha": source_sha,
        "baseline_profile": BASELINE_PINS, "technical_authority": authority_pointer,
        "capture": authority["capture"], "independent_review": authority["independent_review"],
        "active_aftersale_before": AR_BEFORE, "active_aftersale_current": AR_CURRENT,
        "active_profile_updates": updates,
        "all_original_python_function_bodies_exact": False,
        "python_function_changes": {"ms4-host.py": ["verify_files_bootstrap", "verify_schema_bootstrap",
            "prerequisite_result", "verify_merchant_exit_bootstrap", "verify_order_menu_bootstrap"]},
        "initializer_two_literal_inverse_exact": True, "initializer_customer_literal_inverse_exact": True,
        "initializer_schema_bootstrap_inverse_exact": True, "schema_bootstrap_sources": list(schema_sources),
        "initializer_merchant_exit_inverse_exact": True, "merchant_exit_bootstrap_sources": list(exit_sources),
        "initializer_order_menu_inverse_exact": True, "order_menu_bootstrap_sources": list(order_sources),
        "order_menu_profile_mode": order_mode,
        "original_nine_volume_source_tree_topology_retained": True,
        "initializer_syntax": "NOT RUN", "M_A_B_Runtime": "NOT RUN",
    }
    if extended_updates:
        derivation[named].update(interface_declared_active_rows_changed=7,
                                 initializer_fulfillment_literal_inverse_exact=True,
                                 legacy_source_binding_inverse_exact=True)
    if helper_update is not None:
        derivation[named].update(customer_form_helper_update=helper_update,
                                 customer_form_helper_interface_rows_changed=1,
                                 interface_declared_active_rows_changed=interface_changed_rows)
    if isolation_subnet is not None:
        derivation[named]["isolation_subnet"] = isolation_subnet
        derivation[named]["isolation_subnet_availability"] = "NOT_CHECKED_BY_AUTHOR"
    after["source-derivation.json"] = encode(derivation)
    recovered = copy.deepcopy(derivation)
    recovered.pop(named)
    recovered["current_nonself_artifacts"] = old_derivation["current_nonself_artifacts"]
    require(recovered == old_derivation, "ALL_DERIVATION_HISTORY_RETAINED")
    counts = {}
    for name in ("ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py"):
        observed = functions(after[name])
        if name == "ms4-host.py":
            require("verify_schema_bootstrap" not in functions(before[name]), "ONE_NEW_SCHEMA_HOST_CONSUMER")
            observed.pop("verify_schema_bootstrap")
            require("verify_merchant_exit_bootstrap" not in functions(before[name]), "ONE_NEW_EXIT_HOST_CONSUMER")
            observed.pop("verify_merchant_exit_bootstrap")
            require("verify_order_menu_bootstrap" not in functions(before[name]), "ONE_NEW_ORDER_MENU_HOST_CONSUMER")
            observed.pop("verify_order_menu_bootstrap")
            observed["prerequisite_result"] = once(observed["prerequisite_result"],
                b"    verify_order_menu_bootstrap(context, result)\n", b"")
            observed["prerequisite_result"] = once(observed["prerequisite_result"],
                b"    verify_merchant_exit_bootstrap(context, result)\n", b"")
            observed["verify_files_bootstrap"] = once(observed["verify_files_bootstrap"],
                                                       b"    verify_schema_bootstrap(context, result)\n", b"")
        require(functions(before[name]) == observed, "ALL_OTHER_ORIGINAL_PYTHON_FUNCTION_BODIES_EXACT")
        compile(after[name], str(out / name), "exec")
        counts[name] = len(functions(after[name]))
    forward = make_patch(before, after)
    reverse = make_patch(after, before)
    require(apply_patch(before, forward) == after and apply_patch(after, reverse) == before,
            "WHOLE_NINE_FORWARD_AND_REVERSE_REPLAY")
    manifest = {
        "schema": 1, "status": "REVIEWED_PROFILE_SOURCE_DERIVED_NOT_RUNTIME_QUALIFIED", "source_sha": source_sha,
        "technical_authority": authority_pointer, "baseline_profile": BASELINE_PINS,
        "ordered_members9": list(NAMES), "before": {name: pin(before[name]) for name in NAMES},
        "candidate": {name: pin(after[name]) for name in NAMES},
        "changed": [name for name in NAMES if before[name] != after[name]],
        "all_original_python_function_bodies_exact": False, "python_named_function_counts": counts,
        "python_function_changes": {"ms4-host.py": ["verify_files_bootstrap", "verify_schema_bootstrap",
            "prerequisite_result", "verify_merchant_exit_bootstrap", "verify_order_menu_bootstrap"]},
        "all_other_original_python_function_bodies_exact": True,
        "active_profile_updates": updates, "interface_only_four_declared_active_rows": True,
        "initializer_two_literal_inverse_exact": True, "initializer_customer_literal_inverse_exact": True,
        "initializer_schema_bootstrap_inverse_exact": True, "schema_bootstrap_sources": list(schema_sources),
        "initializer_merchant_exit_inverse_exact": True, "merchant_exit_bootstrap_sources": list(exit_sources),
        "initializer_order_menu_inverse_exact": True, "order_menu_bootstrap_sources": list(order_sources),
        "order_menu_profile_mode": order_mode,
        "all_five_original_hash_edges_rebound": True, "current_nonself_eight_exact": True,
        "all_derivation_history_retained": True, "whole_forward_reverse_replay": True,
        "actual_initializer_syntax": "NOT RUN", "M_S_Host_Docker_App_business": "NOT RUN",
    }
    if extended_updates:
        manifest.pop("interface_only_four_declared_active_rows")
        manifest.update(interface_only_seven_declared_active_rows=True,
                        interface_declared_active_rows_changed=7,
                        initializer_fulfillment_literal_inverse_exact=True,
                        legacy_source_binding_inverse_exact=True)
    if helper_update is not None:
        manifest.pop("interface_only_four_declared_active_rows", None)
        manifest.pop("interface_only_seven_declared_active_rows", None)
        manifest.update(interface_only_declared_active_rows=True,
                        interface_declared_active_rows_changed=interface_changed_rows,
                        customer_form_helper_update=helper_update,
                        customer_form_helper_interface_rows_changed=1)
    if isolation_subnet is not None:
        manifest["isolation_subnet"] = isolation_subnet
        manifest["isolation_subnet_availability"] = "NOT_CHECKED_BY_AUTHOR"
    files = {"before/" + name: before[name] for name in NAMES}
    files.update({"FORWARD.patch": forward, "REVERSE.patch": reverse, "MANIFEST.json": encode(manifest)})
    profile_pins = {"schema": 1, "scope": "R61_REVIEWED_CORE9_SOURCE_ONLY_NOT_RUNTIME",
                    "source_sha": source_sha, "technical_authority": authority_pointer,
                    "candidate_root": out.as_posix(), "candidate": manifest["candidate"],
                    "manifest": {"path": (proof / "MANIFEST.json").as_posix(), **pin(files["MANIFEST.json"])},
                    "runtime": "NOT RUN"}
    files["PINS.json"] = encode(profile_pins)
    write_set(proof, files)
    write_set(out, after)
    return {"status": manifest["status"], "source_sha": source_sha,
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
            "unique_source_files": len({row["path"] for rows in groups.values() for row in rows}),
            "merchant_exit_bootstrap_sources": [inventory[name] for name in MERCHANT_EXIT_SOURCE_PATHS],
            "order_menu_bootstrap_sources": [inventory[name] for name in ORDER_MENU_SOURCE_PATHS],
            "order_menu_profile_modes": ORDER_MENU_PROFILE_MODES}


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
    check(data['merchant_exit_bootstrap_sources']==[complete[name] for name in ('help/migrations/marketplace-merchant-exit-menus.php', 'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2-task7-submit-menu.php')],'EXACT_CURRENT_MERCHANT_EXIT_MIGRATION_GROUP')
    check(data['order_menu_bootstrap_sources']==[complete[name] for name in ('help/audit-regressions/isolated/lib/ms4-order-menu-fixture.php', 'help/audit-regressions/orchestration/order_menu_receipt.py', 'help/preview/storefront.php', 'crmeb/public/install/crmeb.sql', 'help/migrations/marketplace-ms4-order-read-menus.php', 'help/migrations/marketplace-ms1.php')]
        and data['order_menu_profile_modes']=={'normal':'NORMAL_A_WITH_READ_LEAVES','ui':'UI_B_ANCHORS_ONLY'},'EXACT_CURRENT_NATIVE_ORDER_MENU_FIXTURE_GROUP_AND_FIXED_MODES')
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
    source_sha = authority["source_sha"]
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
            and current_profile["source_sha"] == source_sha
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
    history = _git(repository, "rev-list", "--first-parent", "--reverse", "--parents", HISTORICAL_HEAD + ".." + source_sha)
    for line in history.decode("ascii").splitlines():
        fields = line.split(" ")
        current_edges.append({"source_sha": fields[0], "parent": fields[1], "parents": fields[1:]})
    require(current_edges and current_edges[0]["parent"] == HISTORICAL_HEAD
            and current_edges[-1]["source_sha"] == source_sha, "COMPLETE_CURRENT_FIRST_PARENT_SUCCESSOR")
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
    cumulative = _raw_delta(repository, PATCH_BASE, source_sha)
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
    requirement_paths = sorted({row["path"] for row in historical_pub["repository_Source_requirements"]}
        | {row["path"] for row in committed} | set(MERCHANT_EXIT_SOURCE_PATHS) | set(ORDER_MENU_SOURCE_PATHS))
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
    data = {"schema": 1, "status": "CAPTURED_SOURCE_GRAPH_NOT_APPROVED", "source_sha": source_sha, "parent": parent,
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
    binding = {"schema": 1, "status": "DERIVED_CURRENT_NORMAL_BINDING_NOT_APPROVED", "source_sha": source_sha,
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
    common = ("\nimport os,stat\n" + "CURRENT_SOURCE_SHA = " + repr(source_sha) + "\nSOURCE_PATCH_BASE = " + repr(PATCH_BASE)
              + "\nCURRENT_PARENT = " + repr(parent) + "\nNORMAL_ROOT = Path(" + repr(normal.as_posix()) + ")"
              + "\nCURRENT_SOURCE_BINDING = " + repr(binding_pointer) + "\nNORMAL_MEMBERS18 = " + repr(list(members))
              + "\nPROFILE_NAMES9 = " + repr(list(NAMES)) + "\nCURRENT_M_RESULT_KEYS = " + repr(list(M_RESULT_KEYS)) + "\n" + _COMMON_RUNTIME)
    selected = [{"path": name, **pin(current9[name])} for name in NAMES]
    publication = {"schema": 2, "scope": "CURRENT_COMPLETE_NORMAL9_SOURCE_PUBLICATION", "source_sha": source_sha,
                   "parent": parent, "current_source_binding": binding_pointer, "selected9": selected,
                   "repository_Source_requirements": data["requirements"], "target_execution": "NOT RUN"}
    publication_raw = encode(publication)
    pp = {"schema": 1, "scope": "FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS",
          "members": {name: pin(current9[name]) for name in NAMES}}
    pp["members"]["PROFILE-PUBLICATION.json"] = pin(publication_raw)
    pp_raw = encode(pp)
    s_prefix = "".join(old_s.decode("utf-8").splitlines(keepends=True)[:165]).encode("utf-8")
    s_prefix = replace_constant(s_prefix, "SOURCE", source_sha)
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
    m_prefix = replace_constant(m_prefix.encode(), "head", source_sha)
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
    checks = {"schema": 1, "status": "CURRENT_NORMAL18_DERIVED_NOT_APPROVED_NO_RUNTIME", "source_sha": source_sha,
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
    proofs["PINS.json"] = encode({"schema": 1, "status": checks["status"], "source_sha": source_sha,
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
    return {"status": checks["status"], "source_sha": source_sha, "normal_root": normal.as_posix(),
            "binding": binding_pointer, "source_data": data_pointer, "PINS": _pointer(normal / "source-proof-r61/PINS.json"),
            "current_edges": len(current_edges), "total_edges": len(edges), "merges": data["merge_count"],
            "cumulative_paths": len(committed), "source_members": 18, "runtime": "NOT RUN"}


def _b_replace_function(raw: bytes, name: str, replacement: str) -> bytes:
    previous = _source_function(raw, name).encode("utf-8")
    changed = once(raw, previous, replacement.rstrip().encode("utf-8"))
    require(once(changed, replacement.rstrip().encode("utf-8"), previous) == raw,
            "B_FINITE_COMPLETE_FUNCTION_INVERSE")
    return changed


def _b_constant(raw: bytes, name: str, value: Any) -> bytes:
    if not isinstance(value, Path):
        return replace_constant(raw, name, value)
    text = raw.decode("utf-8")
    nodes = [node for node in ast.parse(text).body if isinstance(node, ast.Assign)
             and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    require(len(nodes) == 1, "B_ONE_FIXED_PATH_ASSIGNMENT")
    previous = ast.get_source_segment(text, nodes[0]).encode("utf-8")
    replacement = (name + " = Path(" + repr(value.as_posix()) + ")").encode("utf-8")
    updated = once(raw, previous, replacement)
    require(once(updated, replacement, previous) == raw, "B_FIXED_PATH_WHOLE_FILE_INVERSE")
    return updated


def _b_baseline() -> dict[str, bytes]:
    pins = decode(read_pointer(BASELINE_PINS))["members"]
    prefix = "b/"
    names = [name for name in pins if name.startswith(prefix) and (
        name.startswith("b/candidate/") or name.startswith("b/browser-v1/")
        or name.startswith("b/root-source/") or name == "b/current-core-aux/CORE-AUX-AUTHORITY.json")]
    require(names, "FIXED_COMPLETE_B_FUNCTIONAL_SOURCE")
    return {name[len(prefix):]: read_pointer({"path": (BASELINE / name).as_posix(), **pins[name]})
            for name in names}


def _b_networks(after: dict[str, bytes], subnets: dict[str, str]) -> dict[str, str]:
    require(type(subnets) is dict and set(subnets) == {"isolation", "ui_web"}, "B_TWO_EXPLICIT_NETWORK_SUBNETS")
    selected = {name: validate_isolation_subnet(value) for name,value in subnets.items()}
    require(not ipaddress.IPv4Network(selected["isolation"]).overlaps(ipaddress.IPv4Network(selected["ui_web"])),
            "B_EXPLICIT_NETWORK_SUBNETS_DISJOINT")
    original = b"networks:\n  isolation:\n    internal: true\n    labels: *labels\nvolumes:"
    replacement = (b"networks:\n  isolation:\n    internal: true\n    labels: *labels\n    ipam:\n      config:\n        - subnet: "
                   + selected["isolation"].encode() + b"\nvolumes:")
    after["candidate/compose.yml"] = once(after["candidate/compose.yml"], original, replacement)
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        key = "candidate/" + name
        format_value = constant(after[key], "UI_WEB_NETWORK_FORMAT")
        require(format_value.endswith('}}}'), "B_ORIGINAL_NETWORK_PROJECTION_END")
        format_value = format_value[:-1] + ',"IPAM":{{json .IPAM}}}'
        after[key] = replace_constant(after[key], "UI_WEB_NETWORK_FORMAT", format_value)
    host = after["candidate/ms4-host.py"]
    old = b"argv=['docker','network','create','--driver','bridge',"
    new = ("argv=['docker','network','create','--driver','bridge','--subnet'," + repr(selected["ui_web"]) + ",").encode()
    host = once(host, old, new)
    source = _source_function(host, "ui_web_networks")
    source = once(source.encode(), b"'Labels','Containers'}", b"'Labels','Containers','IPAM'}").decode()
    checks = ("    expect(type(original['IPAM']) is dict and type(original['IPAM']['Config']) is list and len(original['IPAM']['Config'])==1\n"
        "        and original['IPAM']['Config'][0]['Subnet']==" + repr(selected["isolation"]) + ", 'WEB_SELECTED_ORIGINAL_ISOLATION_IPAM')\n"
        "    if frontend is not None:\n"
        "        expect(type(frontend['IPAM']) is dict and type(frontend['IPAM']['Config']) is list and len(frontend['IPAM']['Config'])==1\n"
        "            and frontend['IPAM']['Config'][0]['Subnet']==" + repr(selected["ui_web"]) + ", 'WEB_SELECTED_FRONTEND_IPAM')\n")
    source = once(source.encode(), b"    return {'internal':original,'frontend':frontend}",
                  checks.encode() + b"    return {'internal':original,'frontend':frontend}").decode()
    after["candidate/ms4-host.py"] = _b_replace_function(host, "ui_web_networks", source)
    zero = after["candidate/verify-zero-ms4-fulfillment.py"]
    for symbol in ("ui_zero_network_identity", "ui_zero_resource_networks"):
        source = _source_function(zero, symbol)
        source = once(source.encode(), b"'Labels','Containers'}", b"'Labels','Containers','IPAM'}").decode()
        if symbol == "ui_zero_network_identity":
            checks = ("    require(type(value['IPAM']) is dict and type(value['IPAM']['Config']) is list and len(value['IPAM']['Config'])==1\n"
                "        and value['IPAM']['Config'][0]['Subnet']==" + repr(selected["ui_web"]) + ", 'UI_ZERO_SELECTED_FRONTEND_IPAM')\n")
            source = once(source.encode(), b"    return value", checks.encode() + b"    return value").decode()
        else:
            checks = ("        require(type(row['IPAM']) is dict and type(row['IPAM']['Config']) is list and len(row['IPAM']['Config'])==1\n"
                "            and row['IPAM']['Config'][0]['Subnet']==(" + repr(selected["isolation"]) + " if name=='internal' else "
                + repr(selected["ui_web"]) + "), 'UI_ZERO_BOTH_SELECTED_NETWORK_IPAM')\n")
            source = once(source.encode(), b"        if empty:\n", checks.encode() + b"        if empty:\n").decode()
        zero = _b_replace_function(zero, symbol, source)
    after["candidate/verify-zero-ms4-fulfillment.py"] = zero
    return selected


def _b_browser_compatibility(after: dict[str, bytes]) -> dict[str, Any]:
    """Fixed B-only CUA transport changes; preserve the original business gates."""
    network_key = "browser-v1/ms4-ui-network-capture.mjs"
    dom_key = "browser-v1/ms4-ui-dom-producer.mjs"
    contract_key = "browser-v1/ui-business-consumer-contract.json"
    original = {name: after[name] for name in (network_key, dom_key, contract_key)}
    old_capture = b'''export async function createTask2OwnedNetworkCapture({tab,companion,role,origin}) {
  requireValue(Object.getPrototypeOf(companion)===Task2OwnedBrowser.prototype
    &&['customer','merchant','platform'].includes(role)&&tab&&typeof tab.id==='string'
    &&typeof tab.url==='function'&&tab.capabilities&&typeof tab.capabilities.get==='function',
    'TASK2_SAME_GENUINE_OWNED_TAB_CAPABILITY');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.role===role&&owner.tab_id_sha256===task2Sha(tab.id),'TASK2_REGISTERED_OWNED_TAB');
  const cssURL=await tab.url();let parsed;
  try{parsed=new URL(cssURL);}catch{throw new Error('TASK2_EXISTING_HTTP_CSS_BOOTSTRAP_REQUIRED');}
  requireValue(sameOrigin(cssURL,origin)&&parsed.pathname.endsWith('.css')&&parsed.hash===''
    &&parsed.username===''&&parsed.password==='','TASK2_EXISTING_HTTP_CSS_BOOTSTRAP_REQUIRED');
  const capability=await tab.capabilities.get('cdp');
  requireValue(capability&&typeof capability.send==='function'&&typeof capability.readEvents==='function',
    'TASK2_REAL_TAB_CDP_CAPABILITY');
  const observation=jsonCopy(await capability.send('Target.getTargetInfo',{}));
  requireValue(object(observation)&&object(observation.targetInfo),'TASK2_ACTUAL_TARGET_INFO_RESULT');
  const target=observation.targetInfo;
  requireValue(typeof target.targetId==='string'&&target.targetId.length>0&&target.targetId.length<=1024
    &&target.type==='page'&&target.url===cssURL&&await tab.url()===cssURL,
    'TASK2_CSS_OWNED_TARGET_INFO_JOIN');
  const capture=new UiNormalNetworkCapture(capability,{origin});
  task2OwnedCaptures.set(capture,{tab,companion,role,origin,target:jsonCopy(target),
    owner:jsonCopy(owner),cssURL,sourceKey:null,mapping:null});
  return capture;
}'''
    new_capture = b'''export async function createTask2OwnedNetworkCapture({tab,companion,role,origin}) {
  requireValue(Object.getPrototypeOf(companion)===Task2OwnedBrowser.prototype
    &&['customer','merchant','platform'].includes(role)&&tab&&typeof tab.id==='string'
    &&typeof tab.url==='function'&&tab.capabilities&&typeof tab.capabilities.get==='function'
    &&tab.playwright&&typeof tab.playwright.evaluate==='function'
    &&typeof tab.playwright.waitForLoadState==='function',
    'TASK2_SAME_GENUINE_OWNED_TAB_CAPABILITY');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.role===role&&owner.tab_id_sha256===task2Sha(tab.id),'TASK2_REGISTERED_OWNED_TAB');
  await tab.playwright.waitForLoadState({state:'networkidle'});
  const readDocument=()=>({url:document.URL,readyState:document.readyState,
    contentType:document.contentType,rootTag:document.documentElement?document.documentElement.tagName:null});
  const documentURL=await tab.url(),before=jsonCopy(await tab.playwright.evaluate(readDocument));let parsed;
  try{parsed=new URL(documentURL);}catch{throw new Error('TASK2_EXISTING_SAME_ORIGIN_HTML_DOCUMENT_REQUIRED');}
  requireValue(sameOrigin(documentURL,origin)&&parsed.username===''&&parsed.password===''
    &&object(before)&&before.url===documentURL&&before.readyState==='complete'
    &&before.contentType==='text/html'&&before.rootTag==='HTML',
    'TASK2_EXISTING_SAME_ORIGIN_HTML_DOCUMENT_REQUIRED');
  const capability=await tab.capabilities.get('cdp');
  requireValue(capability&&typeof capability.send==='function'&&typeof capability.readEvents==='function',
    'TASK2_REAL_TAB_CDP_CAPABILITY');
  const observed=jsonCopy(await tab.playwright.evaluate(readDocument));
  const stillOwned=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(JSON.stringify(canonical(observed))===JSON.stringify(canonical(before))
    &&await tab.url()===documentURL&&stillOwned.role===role
    &&stillOwned.tab_id_sha256===owner.tab_id_sha256&&owner.tab_id_sha256===task2Sha(tab.id),
    'TASK2_SAME_OWNED_TAB_STABLE_NATIVE_HTML_DOCUMENT');
  const capture=new UiNormalNetworkCapture(capability,{origin});
  task2OwnedCaptures.set(capture,{tab,companion,role,origin,owner:jsonCopy(owner),
    documentURL,sourceKey:null,mapping:null});
  return capture;
}'''
    old_mapping = b'''export function task2OwnedCaptureSource(capture,{tab,companion,role,origin,source}) {
  const original=task2OwnedCaptures.get(capture);
  requireValue(original&&original.tab===tab&&original.companion===companion&&original.role===role
    &&original.origin===origin&&object(source),'TASK2_ORIGINAL_OWNED_CAPTURE_REQUIRED');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.tab_id_sha256===original.owner.tab_id_sha256&&owner.tab_id_sha256===task2Sha(tab.id),
    'TASK2_OWNED_CAPTURE_STILL_SAME_TAB');
  const raw=jsonCopy(source),sourceKey=JSON.stringify(canonical(raw));
  let kind;
  if(typeof raw.targetId==='string'){
    requireValue(raw.targetId===original.target.targetId,'TASK2_REAL_EVENT_TARGET_ID_JOIN');kind='targetId';
  }else{
    requireValue(Number.isSafeInteger(raw.tabId)&&raw.tabId>=0,'TASK2_REAL_EVENT_TAB_MAPPING_REQUIRED');
    const value=String(raw.tabId);
    requireValue(tab.id===value,'TASK2_REAL_PUBLIC_TAB_ID_JOIN');kind='tabId';
  }
  if(original.sourceKey===null){original.sourceKey=sourceKey;original.mapping={kind,
    tab_id_sha256:owner.tab_id_sha256,target_id_sha256:task2Sha(original.target.targetId),
    source_sha256:task2Sha(sourceKey),css_url_sha256:task2Sha(original.cssURL)};}
  else requireValue(original.sourceKey===sourceKey,'TASK2_ONE_REAL_OWNED_CAPTURE_SOURCE_SCOPE');
  return jsonCopy(original.mapping);
}'''
    new_mapping = b'''export function task2OwnedCaptureSource(capture,{tab,companion,role,origin,source}) {
  const original=task2OwnedCaptures.get(capture);
  requireValue(original&&original.tab===tab&&original.companion===companion&&original.role===role
    &&original.origin===origin&&object(source),'TASK2_ORIGINAL_OWNED_CAPTURE_REQUIRED');
  const owner=Task2OwnedBrowser.prototype.ownedTabIdentity.call(companion,role);
  requireValue(owner.tab_id_sha256===original.owner.tab_id_sha256&&owner.tab_id_sha256===task2Sha(tab.id),
    'TASK2_OWNED_CAPTURE_STILL_SAME_TAB');
  const raw=jsonCopy(source),sourceKey=JSON.stringify(canonical(raw));
  requireValue(Object.keys(raw).length===1&&Object.hasOwn(raw,'tabId')
    &&Number.isSafeInteger(raw.tabId)&&raw.tabId>=0&&tab.id===String(raw.tabId),
    'TASK2_EXACT_NATIVE_EVENT_SOURCE_SAME_OWNED_TAB_ID');
  if(original.sourceKey===null){original.sourceKey=sourceKey;original.mapping={kind:'tabId',
    tab_id_sha256:owner.tab_id_sha256,source_sha256:task2Sha(sourceKey),
    document_url_sha256:task2Sha(original.documentURL)};}
  else requireValue(original.sourceKey===sourceKey,'TASK2_ONE_REAL_OWNED_CAPTURE_SOURCE_SCOPE');
  return jsonCopy(original.mapping);
}'''
    network = once(once(original[network_key], old_capture, new_capture), old_mapping, new_mapping)
    require(once(once(network, new_mapping, old_mapping), new_capture, old_capture) == original[network_key],
            "B_CUA_TWO_EXPORTS_EXACT_WHOLE_FILE_INVERSE_GENERIC_NETWORK_CORE_UNCHANGED")
    old_guard = b"&&typeof tab.screenshot==='function');"
    new_guard = b"&&tab.capabilities&&typeof tab.capabilities.get==='function');"
    old_png = b'''      const callStart=Date.now(),returned=await tab.screenshot({fullPage:false}),callEnd=Date.now();need(ArrayBuffer.isView(returned)&&Object.prototype.toString.call(returned)==='[object Uint8Array]');const raw=Buffer.from(returned);
      try{const pixels=png(raw);last=copy(await tab.playwright.evaluate(measureVisible,arg));'''
    new_png = b'''      const capability=await tab.capabilities.get('cdp');need(capability&&typeof capability.send==='function');
      const callStart=Date.now(),returned=copy(await capability.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false,fromSurface:true})),callEnd=Date.now();
      keys(returned,['data']);need(typeof returned.data==='string'&&returned.data.length>0&&returned.data.length<=44739244);
      const raw=Buffer.from(returned.data,'base64');
      try{need(raw.toString('base64')===returned.data);const pixels=png(raw);last=copy(await tab.playwright.evaluate(measureVisible,arg));'''
    dom = once(once(original[dom_key], old_guard, new_guard), old_png, new_png)
    require(once(once(dom, new_png, old_png), new_guard, old_guard) == original[dom_key],
            "B_CUA_NATIVE_PNG_ACQUISITION_EXACT_WHOLE_FILE_INVERSE_ALL_DOM_PNG_GATES_UNCHANGED")
    contract = decode(original[contract_key]); previous_contract = copy.deepcopy(contract)
    old_text = {
        "capture_mapping": "GENUINE_SAME_OWNED_TAB_CAPABILITY_AFTER_EXISTING_SAME_ORIGIN_CSS_TARGET_GET_TARGET_INFO_AND_ACTUAL_EVENT_SOURCE_JOIN",
        "screenshot_operand": "original SDK screenshot",
        "pixels_vs_CSS": "Actual PNG physical width/height from original SDK bytes separate from CSS viewport/DPR/bounds; do not assume equal.",
    }
    require(contract["task2"]["capture_mapping"] == old_text["capture_mapping"]
            and contract["wire_rules"]["relations"]["SCREENSHOT_ORIGINAL_PNG"]["operands"][0] == old_text["screenshot_operand"]
            and contract["dom_rules"]["screenshot_mapping"]["pixels_vs_CSS"] == old_text["pixels_vs_CSS"],
            "B_THREE_EXACT_ORIGINAL_BROWSER_TOOL_PROVENANCE_VALUES")
    contract["task2"]["capture_mapping"] = "GENUINE_SAME_OWNED_TAB_CDP_CAPABILITY_STABLE_SAME_ORIGIN_HTML_DOM_URL_AND_EXACT_ORIGINAL_EVENT_SOURCE_TAB_ID_JOIN"
    contract["wire_rules"]["relations"]["SCREENSHOT_ORIGINAL_PNG"]["operands"][0] = "same-owned-tab native CDP Page.captureScreenshot original PNG bytes; canonical base64 decoding only"
    contract["dom_rules"]["screenshot_mapping"]["pixels_vs_CSS"] = "Actual PNG physical width/height from the same owned tab native CDP Page.captureScreenshot original bytes, decoded from canonical base64 only, separate from CSS viewport/DPR/bounds; do not assume equal."
    restored = copy.deepcopy(contract)
    restored["task2"]["capture_mapping"] = old_text["capture_mapping"]
    restored["wire_rules"]["relations"]["SCREENSHOT_ORIGINAL_PNG"]["operands"][0] = old_text["screenshot_operand"]
    restored["dom_rules"]["screenshot_mapping"]["pixels_vs_CSS"] = old_text["pixels_vs_CSS"]
    require(restored == previous_contract and encode(restored) == original[contract_key],
            "B_THREE_TOOL_PROVENANCE_ONLY_EXACT_CONTRACT_INVERSE_NO_SCHEMA_OR_BUSINESS_CHANGE")
    after.update({network_key: network, dom_key: dom, contract_key: encode(contract)})
    return {"schema": 1, "scope": "B_ONLY_NATIVE_CUA_BROWSER_TRANSPORT_SOURCE_CHANGE",
            "tool_author": _pointer(Path(__file__).resolve()),
            "changed_exports": {network_key: ["createTask2OwnedNetworkCapture", "task2OwnedCaptureSource"]},
            "changed_method_seams": {dom_key: ["UiNormalDomProducer.observe: screenshot capability guard and original native PNG acquisition only"]},
            "changed_contract_paths": ["task2.capture_mapping", "wire_rules.relations.SCREENSHOT_ORIGINAL_PNG.operands[0]",
                                       "dom_rules.screenshot_mapping.pixels_vs_CSS"],
            "files": [{"path": name, "before": pin(original[name]), "after": pin(after[name])}
                      for name in original],
            "whole_file_inverse": True, "generic_network_and_original_dom_png_business_gates": "UNCHANGED",
            "native_event_source": "EXACT_ORIGINAL_TAB_ID_OBJECT_NO_SYNTHETIC_TARGET_ID",
            "screenshot": "SAME_OWNED_TAB_NATIVE_CDP_PNG_CANONICAL_BASE64_DECODE_ONLY_NO_TRANSCODING",
            "application_source_qualification": "UNCHANGED_CURRENT_CAPTURE_NOT_NEW_TOOL_GIT_HEAD_A_APPROVAL",
            "runtime_GUI": "NOT RUN", "approval": "NOT_GRANTED_REQUIRES_INDEPENDENT_SOURCE_REVIEW"}


def derive_b_profile(authority_pointer: dict[str, Any],
                     network_subnets: dict[str, str] | None = None) -> dict[str, Any]:
    """Pure finite B Source transformation; no A claim, output, import or runtime."""
    authority, inventory = reviewed_authority(authority_pointer)
    before = _b_baseline()
    after = dict(before)
    active_paths = {AR_PATH, CUSTOMER_PATH, FULFILLMENT_PATH, LEGACY_PATH,
                    "help/audit-regressions/isolated/ms4-ui-prepare.php",
                    "help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php"}
    def current_row(row):
        wanted = inventory[row["path"]]
        require(wanted == row or row["path"] in active_paths, "B_ONLY_DECLARED_REVIEWED_SOURCE_PIN_UPDATES")
        return wanted
    interface = decode(before["candidate/interface-v1.json"])
    require(encode(interface) == before["candidate/interface-v1.json"], "B_ORIGINAL_JSON_BYTES")
    # These are current B vectors. Earlier customer-form and historical profile
    # records intentionally retain the identities of their own generations.
    current = interface["fresh_B_current_UI_profile_source"]
    for rows in (current["ui_source_vector8"].values(), current["customer_form_sources7"],
                 interface["integrated_B_source_tree_contract"]
                 ["fixed_producer_source_qualification"]["producers10"],
                 interface["fulfillment_normal_successor"]["three_read_internal"]["source_helper_files"]):
        for row in rows:
            require(set(row) == {"path", "bytes", "sha256"} and row["path"] in inventory,
                    "B_EXACT_REVIEWED_ACTIVE_INTERFACE_ROW")
            row.update(current_row(row))
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        key = "candidate/" + name
        raw = after[key]
        for symbol in ("CUSTOMER_FORM_SOURCE_FILES", "THREE_READ_SOURCE_FILES"):
            rows = constant(raw, symbol)
            updated = [current_row(row) for row in rows]
            raw = replace_constant(raw, symbol, tuple(updated) if isinstance(rows, tuple) else updated)
        rows = constant(raw, "UI_CURRENT_SOURCE_PINS")
        require(len(rows) == 8, "B_ORIGINAL_RUNTIME_EIGHT")
        raw = replace_constant(raw, "UI_CURRENT_SOURCE_PINS", {path: current_row(row) for path,row in rows.items()})
        for symbol in ("PAID_ENTRY", "FULFILLMENT_ENTRY", "AFTERSALE_REVIEW_ENTRY", "WORKER_ENTRY"):
            row = inventory[constant(raw, symbol)]
            current_row({"path":row["path"],"bytes":constant(raw,symbol+"_BYTES"),
                         "sha256":constant(raw,symbol+"_SHA256")})
            raw = replace_constant(raw, symbol + "_BYTES", row["bytes"])
            raw = replace_constant(raw, symbol + "_SHA256", row["sha256"])
        after[key] = raw
    initializer = after["candidate/ms4-initialize.php"]
    old_rows = constant(before["candidate/ms4-host.py"], "CUSTOMER_FORM_SOURCE_FILES")
    old_ui = constant(before["candidate/ms4-host.py"], "UI_CURRENT_SOURCE_PINS")
    def php_row(row):
        return ("['path' => '" + row["path"] + "', 'bytes' => " + str(row["bytes"])
                + ", 'sha256' => '" + row["sha256"] + "']").encode("utf-8")
    for row in old_rows:
        if inventory[row["path"]] != row:
            initializer = once(initializer, php_row(row), php_row(inventory[row["path"]]))
    for path, row in old_ui.items():
        if inventory[path] != row:
            old = ("'" + path + "' => [" + str(row["bytes"]) + ", '" + row["sha256"] + "']").encode()
            new = ("'" + path + "' => [" + str(inventory[path]["bytes"]) + ", '"
                   + inventory[path]["sha256"] + "']").encode()
            initializer = once(initializer, old, new)
    initializer, host, schema_rows = schema_profile_changes(initializer, after["candidate/ms4-host.py"], inventory)
    initializer, host, exit_rows = merchant_exit_profile_changes(initializer, host, inventory)
    initializer, host, order_rows, order_mode = order_menu_profile_changes(
        initializer, host, inventory, Path(authority["validation_root"]), with_read_leaves=False)
    after["candidate/ms4-initialize.php"] = initializer
    after["candidate/ms4-host.py"] = host
    # The public installer remains in both complete source reads. Only deployment
    # descriptors use this exact root-only projection; nested install stays in.
    projection = "    public = {**public, 'files':[r for r in public['files'] if r['path'] != 'install' and not r['path'].startswith('install/')], 'directories':[p for p in public['directories'] if p != 'install' and not p.startswith('install/')]}\n"
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        key = "candidate/" + name
        anchor = b"    public = ui_copy_tree(Path(repo) / 'crmeb/public')\n"
        after[key] = once(after[key], anchor, anchor + projection.encode())
    library = after["candidate/ms4-ui-public-copy.php"]
    anchor = b"    $source = m4uic_tree('/source/crmeb/public');\n"
    require(anchor in library, "B_ORIGINAL_COMPLETE_PUBLIC_READER")
    php_projection = (b"    $deployable=$source;\n"
        b"    $deployable['files']=array_filter($source['files'],static fn($row)=>$row['path']!=='install'&&!str_starts_with($row['path'],'install/'));\n"
        b"    $deployable['directories']=array_values(array_filter($source['directories'],static fn($path)=>$path!=='install'&&!str_starts_with($path,'install/')));\n")
    library = once(library, anchor, anchor + php_projection)
    for field, target in (("files", "$sourceFiles"), ("directories", "$sourceDirs")):
        old = ("$source['" + field + "'] === " + target).encode()
        new = ("$deployable['" + field + "'] === " + target).encode()
        library = once(library, old, new)
    after["candidate/ms4-ui-public-copy.php"] = library
    # The browser has two independently fixed vectors plus the exact contract.
    core = decode(before["current-core-aux/CORE-AUX-AUTHORITY.json"])
    old_core = copy.deepcopy(core["core_sources"])
    core["core_sources"] = [current_row(row) for row in old_core]
    for row in old_core:
        changed = inventory[row["path"]]
        if changed != row:
            for name in ("ms4-ui-business-producer.mjs", "ms4-ui-window-native-bridge.mjs"):
                key = "browser-v1/" + name
                after[key] = once(after[key], row["sha256"].encode(), changed["sha256"].encode())
                require(row["bytes"] == changed["bytes"], "B_BROWSER_ONLY_FIXED_SAME_SIZE_BACKEND_LITERAL")
    contract = decode(after["browser-v1/ui-business-consumer-contract.json"])
    contract["php_sources"] = {row["path"]: {k:row[k] for k in ("bytes", "sha256")} for row in core["core_sources"]}
    after["browser-v1/ui-business-consumer-contract.json"] = encode(contract)
    browser_compatibility = _b_browser_compatibility(after)
    browser = decode(before["browser-v1/PINS.json"])
    browser["members"] = {name: pin(after["browser-v1/" + name]) for name in browser["members"]}
    after["browser-v1/PINS.json"] = encode(browser)
    after["candidate/verify-zero-ms4-fulfillment.py"] = replace_constant(
        after["candidate/verify-zero-ms4-fulfillment.py"], "UI_AUX_SOURCE_PIN", pin(after["browser-v1/PINS.json"]))
    after["current-core-aux/CORE-AUX-AUTHORITY.json"] = encode(core)
    after["candidate/interface-v1.json"] = encode(interface)
    # These source constants precede the original interface -> Host -> close ->
    # zero seals. No resource, role, ordinary close or business code is replaced.
    for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
        key = "candidate/" + name
        raw = after[key]
        for leaf in ("ms4-ui-copy.php", "ms4-ui-public-copy.php"):
            old, new = pin(before["candidate/" + leaf]), pin(after["candidate/" + leaf])
            old_literal = ("'bytes': " + str(old["bytes"]) + ",\n        'sha256': '" + old["sha256"] + "'").encode()
            new_literal = ("'bytes': " + str(new["bytes"]) + ",\n        'sha256': '" + new["sha256"] + "'").encode()
            if old != new:
                raw = once(raw, old_literal, new_literal)
        after[key] = raw
    if network_subnets is not None:
        network_subnets = _b_networks(after, network_subnets)
    for name, symbol, target in (("ms4-host.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("normal-close-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("normal-close-ms4-fulfillment.py", "UI_HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "UI_HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "CLOSE_SHA256", "normal-close-ms4-fulfillment.py"),
        ("verify-zero-ms4-fulfillment.py", "UI_CLOSE_SHA256", "normal-close-ms4-fulfillment.py"),
        ("verify-zero-ms4-fulfillment.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("verify-zero-ms4-fulfillment.py", "UI_INTERFACE_SHA256", "interface-v1.json")):
        after["candidate/" + name] = replace_constant(after["candidate/" + name], symbol, pin(after["candidate/" + target])["sha256"])
    for symbol in ("INTERFACE_BYTES", "UI_INTERFACE_BYTES"):
        after["candidate/verify-zero-ms4-fulfillment.py"] = replace_constant(
            after["candidate/verify-zero-ms4-fulfillment.py"], symbol, len(after["candidate/interface-v1.json"]))
    members = tuple(name for name in before if before[name] != after[name])
    left, right = ({name: before[name] for name in members}, {name: after[name] for name in members})
    forward, reverse = make_patch(left, right, members), make_patch(right, left, members)
    require(apply_patch(left, forward, members) == right and apply_patch(right, reverse, members) == left,
            "B_COMPLETE_FUNCTIONAL_SOURCE_FORWARD_REVERSE")
    for name, raw in after.items():
        if name.endswith(".py"): compile(raw, name, "exec")
        elif name.endswith(".json"): decode(raw)
    for name, changed, added in (("ms4-host.py", {"verify_files_bootstrap", "ui_copy_freeze_values", "prerequisite_result"},
                                  {"verify_schema_bootstrap", "verify_merchant_exit_bootstrap", "verify_order_menu_bootstrap"}),
                                 ("verify-zero-ms4-fulfillment.py", {"ui_copy_freeze_values"}, set()),
                                 ("normal-close-ms4-fulfillment.py", set(), set())):
        original_functions = functions(before["candidate/" + name])
        current_functions = functions(after["candidate/" + name])
        if network_subnets is not None:
            changed |= ({"ui_web_connect", "ui_web_networks"} if name == "ms4-host.py"
                        else {"ui_zero_network_identity", "ui_zero_resource_networks"} if name == "verify-zero-ms4-fulfillment.py" else set())
        require(set(current_functions) == set(original_functions) | added
                and all(current_functions[symbol] == body for symbol, body in original_functions.items() if symbol not in changed),
                "B_ALL_NON_TARGET_PYTHON_BUSINESS_AND_RESOURCE_FUNCTIONS_RAW_EXACT")
    return {"before": before, "after": after, "authority": authority, "inventory": inventory,
            "schema_sources": schema_rows, "merchant_exit_sources": exit_rows,
            "order_menu_sources": order_rows, "order_menu_profile_mode": order_mode,
            "browser_compatibility": browser_compatibility,
            "forward": forward, "reverse": reverse,
            "network_subnets": network_subnets,
            "status": "PURE_B_PROFILE_DERIVED_NOT_APPROVED_NO_A_OR_RUNTIME"}


def _b_outer(outcome: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
    require(type(outcome) is dict and set(outcome) == {"created", "terminal", "stdout", "stderr"},
            "B_EXPLICIT_ACTUAL_OUTER_FOUR_FILES")
    created, terminal = (decode(read_pointer(outcome[key])) for key in ("created", "terminal"))
    require(type(created) is dict and {"pid", "argv", "started_ns"} <= set(created)
            and type(created["pid"]) is int and created["pid"] > 0
            and type(created["argv"]) is list and created["argv"]
            and type(created["started_ns"]) is int, "B_ACTUAL_OUTER_CREATED")
    require(set(terminal) == set(created) | {"native_exit", "wait_returncode", "finished_ns", "stdout", "stderr"}
            and all(terminal[key] == value for key, value in created.items())
            and type(terminal["native_exit"]) is int and type(terminal["wait_returncode"]) is int
            and terminal["native_exit"] == terminal["wait_returncode"] == 0
            and type(terminal["finished_ns"]) is int
            and terminal["finished_ns"] > created["started_ns"], "B_ACTUAL_SAME_PID_OUTER_NATURAL_ZERO")
    for kind in ("stdout", "stderr"):
        require(type(terminal[kind]) is dict and set(terminal[kind]) == {"bytes", "sha256"}
                and terminal[kind] == {key:outcome[kind][key] for key in ("bytes", "sha256")},
                "B_ORIGINAL_FULL_OUTER_STREAM_POINTER")
        raw = read_pointer(outcome[kind])
        require(raw == b"" if kind == "stderr" else bool(raw), "B_COMPLETE_OUTER_RAW_STREAM")
    return created, terminal


_B_NATIVE_REPLAY = r'''
    # Preserve the original B consumer's semantic native replay, upgraded only
    # for schema2 complete parents, explicit NUL delta/index and two extra rows.
    expected=[]
    def record(label, argv, result): expected.append((label,argv,result))
    def git_record(label, root, args, result):
        record(label,['git','-c','safe.directory='+root.as_posix(),'-C',root,*args],result)
    main=Path(binding['candidate']);repo=REPO;parents=qualified['commit_chain'][-1]['parents']
    for label,root in [('main',main),('validation',repo)]:
        git_record(label+'-head-before',root,['rev-parse','HEAD'],(SOURCE+'\n').encode())
        git_record(label+'-status-before',root,['status','--porcelain=v1','--untracked-files=all'],b'')
        git_record(label+'-parent',root,['rev-list','--parents','-n','1',SOURCE],(' '.join([SOURCE,*parents])+'\n').encode())
    for index,step in enumerate(qualified['commit_chain'],1):
        label='source-step-'+str(index)
        git_record(label+'-parent',repo,['rev-list','--parents','-n','1',step['source_sha']],(' '.join([step['source_sha'],*step['parents']])+'\n').encode())
        delta=b''.join((':{} {} {} {} {}\0{}\0'.format(row['before_git_mode'],row['after_git_mode'],row['before_git_blob'] or '0'*40,row['after_git_blob'],'A' if row['before'] is None else 'M',row['path'])).encode('utf-8') for row in step['files'])
        git_record(label+'-paths',repo,['diff-tree','--no-commit-id','--raw','--no-abbrev','--no-renames','-r','-z',step['parent'],step['source_sha']],delta)
        for number,row in enumerate(step['files'],1):
            mark=label+'-member-'+str(number)
            git_record(mark+'-after',repo,['show',step['source_sha']+':'+row['path']],row['after'])
            if row['before'] is None:
                git_record(mark+'-absent-before',repo,['ls-tree','--name-only',step['parent'],'--',row['path']],b'')
            else:git_record(mark+'-before',repo,['show',step['parent']+':'+row['path']],row['before'])
    git_record('cumulative-changed-paths',repo,['diff','--name-only','--no-renames','-z',SOURCE_PATCH_BASE,SOURCE],
        b''.join((row['path']+'\0').encode('utf-8') for row in qualified['committed']))
    for number,row in enumerate(qualified['committed'],1):
        git_record('committed-blob-'+str(number),repo,['show',SOURCE+':'+row['path']],{key:row[key] for key in ['bytes','sha256']})
    inventory=b''.join((name+'\0').encode('utf-8') for name in qualified['all'])
    for label,root in [('main',main),('validation',repo)]:
        git_record(label+'-complete-tracked-inventory',root,['ls-files','-z'],inventory)
    index=b''.join((row['git_mode']+' '+row['git_blob']+' 0\t'+row['path']+'\0').encode('utf-8') for row in qualified['git_index'])
    for label,root in [('main',main),('validation',repo)]:
        git_record(label+'-complete-index',root,['ls-files','--stage','-z'],index)
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        record('help-'+name.replace('.','-'),[Path(sys.executable),'-B','-X','utf8',PROFILE/name,'--help'],None)
    record('freeze',[Path(sys.executable),'-B','-X','utf8',HOST,'freeze','--repo',repo,'--source',SOURCE,'--output',Path(actual_setup['freeze']),'--proof-ini-source',Path(frozen['proof_php_ini']['source_root'])],
        {'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':SOURCE,'freeze_sha256':actual_setup['freeze_sha256'],'runtime_SQL_App':'NOT RUN'})
    for label,root in [('main',main),('validation',repo)]:
        git_record(label+'-head-after',root,['rev-parse','HEAD'],(SOURCE+'\n').encode())
        git_record(label+'-status-after',root,['status','--porcelain=v1','--untracked-files=all'],b'')
    require(len(expected)==len(native),'B_EXACT_COMPLETE_SCHEMA2_NATIVE_GRAMMAR')
    for operation,(label,argv,wanted) in zip(native,expected):
        require(operation['label']==label and len(operation['argv'])==len(argv)
            and all(Path(value)==part if isinstance(part,Path) else value==part for value,part in zip(operation['argv'],argv)),
            'B_EVERY_ORIGINAL_NATIVE_LABEL_ARGV_AND_ORDER')
        raw=current_stream({'path':(folder/(operation['stem']+'.stdout')).as_posix(),'bytes':operation['stdout_bytes'],'sha256':operation['stdout_sha256']},True)
        if type(wanted) is bytes:require(raw==wanted,'B_EXACT_PARENT_DELTA_INDEX_STATUS_AND_NAMESPACE_STREAM')
        elif wanted is None:require(bool(raw),'B_ORIGINAL_NONEMPTY_PROFILE_HELP')
        elif set(wanted)=={'bytes','sha256'}:require({'bytes':len(raw),'sha256':sha(raw)}==wanted,'B_EVERY_REAL_BEFORE_AND_AFTER_GIT_BLOB')
        else:require(json.loads(raw)==wanted,'B_ONE_ORIGINAL_FREEZE_JSON_STREAM')
'''


def _b_normal_consumer(normal_root: Path, binding: dict[str, Any], normal_review: dict[str, Any],
                       setup_pointer: dict[str, Any]) -> str:
    """Reuse the exact current normal source and M preflight, without its CLI/business."""
    preflight = _S_PREFLIGHT
    old = preflight[:preflight.index("    verify_normal_review(args.source_review_file,args.source_review_sha256)\n")]
    header = ("\ndef current_b_normal_materialization():\n"
        "    BASE=NORMAL_ROOT/'validation'; PROFILE=BASE/'actual-profile'; HOST=PROFILE/'ms4-host.py'\n"
        "    SOURCE=CURRENT_SOURCE_SHA; CURRENT_PARENT=CURRENT_B_PARENT; SETUP=Path(CURRENT_B_M_RESULT['path'])\n"
        "    args=argparse.Namespace(source_review_file=CURRENT_B_NORMAL_REVIEW['path'],"
        "source_review_sha256=CURRENT_B_NORMAL_REVIEW['sha256'])\n")
    preflight = once(preflight.encode(), old.encode(), header.encode()).decode()
    preflight = once(preflight.encode(),
        b"    root_pins={'path':(BASE/'PINS.json').as_posix(),'bytes':(BASE/'PINS.json').stat().st_size,'sha256':args.root_source_pins_sha256}\n",
        b"    root_pins=CURRENT_B_NORMAL_ROOT_PINS\n").decode()
    preflight = once(preflight.encode(),
        b"    actual_setup=current_json({'path':SETUP.as_posix(),'bytes':SETUP.stat().st_size,'sha256':args.setup_sha256})\n",
        b"    actual_setup=current_json(CURRENT_B_M_RESULT)\n").decode()
    preflight += _B_NATIVE_REPLAY + "    return actual_setup\n"
    review = decode(read_pointer(normal_review))
    normal_s = read_pointer({"path": (normal_root / "validation/run.py").as_posix(),
                             **review["members"]["validation/run.py"]})
    tree_join = _source_function(normal_s, "validate_complete_source_tree")
    constants = {
        "NORMAL_ROOT": normal_root, "CURRENT_SOURCE_SHA": binding["source_sha"],
        "CURRENT_SOURCE_BINDING": _pointer(normal_root / "qualification/CURRENT-BINDING.json"),
        "PROFILE_NAMES9": list(NAMES), "NORMAL_MEMBERS18": list(review["members"]),
        "SOURCE_PATCH_BASE": PATCH_BASE, "CURRENT_M_RESULT_KEYS": M_RESULT_KEYS,
        "CURRENT_B_PARENT": binding["parent"], "CURRENT_B_NORMAL_REVIEW": normal_review,
        "CURRENT_B_M_RESULT": setup_pointer, "CURRENT_B_NORMAL_ROOT_PINS": _pointer(normal_root / "validation/PINS.json"),
        "PROFILE_PUBLICATION": pin(stable_read(normal_root / "validation/PROFILE-PINS.json")),
    }
    prefix = "\n".join(name + " = " + ("Path(" + repr(value.as_posix()) + ")" if isinstance(value, Path) else repr(value))
                       for name, value in constants.items()) + "\n"
    return prefix + _COMMON_RUNTIME + "\nrequire=current_assert\n" + tree_join + "\n" + preflight


def _b_materials(materials: dict[str, Any], authority_pointer: dict[str, Any],
                 inventory: dict[str, Any], proofs_pointer: dict[str, Any]) -> tuple[dict[str, bytes], dict[str, Any]]:
    require(set(materials) == {"root", "producer_result", "independent_review"}, "B_EXPLICIT_STATIC_MATERIAL_INPUTS")
    result = decode(read_pointer(materials["producer_result"]))
    require(result["schema"] == 1 and result["scope"] == "UI_FIVE_STATIC_MATERIAL_BYTES_ONLY"
            and result["status"] == "STATIC_MATERIAL_BYTES_CREATED_ONLY"
            and result["current_frontend_bytes_match_original_builds"] is True
            and result["original_build_revisions_preserved"] is True and result["compiler_rerun"] is False
            and result["full_acceptance"] is False, "B_REAL_STATIC_MATERIALS_NOT_RUNTIME_ACCEPTANCE")
    review = decode(read_pointer(materials["independent_review"]))
    require(review["status"] == "PASS_CURRENT_UI_STATIC_MATERIALS_REVIEW"
            and review["producer_result"] == materials["producer_result"], "B_INDEPENDENT_ACTUAL_FIVE_MATERIAL_REVIEW")
    proofs = decode(read_pointer(proofs_pointer))
    require(proofs["schema"] == 1 and proofs["scope"] == "CURRENT_THREE_FRONTEND_BUILD_PROOFS"
            and set(proofs["builds"]) == {"h5", "merchant", "admin"}, "B_EXPLICIT_THREE_TRUE_BUILD_PROOFS")
    scope = result["public_source_scope"]
    authority = decode(read_pointer(authority_pointer))
    require(scope["technical_authority"] == authority_pointer and scope["source_sha"] == authority["source_sha"]
            == proofs["source_sha"] and scope["capture"] == authority["capture"]
            and scope["excluded_root_directories"] == ["install"]
            and scope["nested_same_name_directories_excluded"] is False
            and scope["excluded_source_fully_read_and_rechecked"] is True,
            "B_MATERIALS_CURRENT_COMPLETE_CAPTURE_AND_EXACT_ROOT_ONLY_PROJECTION")
    names = ["ms4-order.png", "manifests/ui-h5-dist.json", "manifests/ui-merchant-dist.json",
             "manifests/ui-admin-dist.json", "manifests/ui-public-copy-profile.json"]
    require(list(result["materials"]) == names, "B_EXACT_FIVE_STATIC_MATERIALS")
    root = Path(materials["root"])
    emitted = {name: read_pointer({"path": (root / name).as_posix(), **result["materials"][name]}) for name in names}
    children = list(root.rglob('*'))
    for path in children: ordinary(path)
    require(sorted(p.relative_to(root).as_posix() for p in children if p.is_file()) == sorted(names)
            and sorted(p.relative_to(root).as_posix() for p in children if p.is_dir()) == ["manifests"],
            "B_MATERIALS_EXACT_CLOSED_FIVE_FILE_NAMESPACE")
    profile = decode(emitted[names[-1]])
    expected = [{"path": path[len("crmeb/public/"):], "bytes": row["bytes"], "sha256": row["sha256"]}
                for path, row in inventory.items() if path.startswith("crmeb/public/")
                and not path.startswith("crmeb/public/install/")]
    require(profile["public_source_files"] == expected, "B_PROFILE_EXACT_CURRENT_DEPLOYABLE_PUBLIC_BYTES")
    for name in ("h5", "merchant", "admin"):
        build = result["builds"][name]
        require(build["current_source_revision"] == authority["source_sha"]
                and build["reuse_basis"] == "COMPLETE_BUILD_INPUT_EQUIVALENCE_ORIGINAL_HEAD_RETAINED",
                "B_BUILD_ORIGINAL_PROVENANCE_RETAINED")
        row = proofs["builds"][name]
        require(type(row) is dict and set(row) == {"packet", "pins", "run", "outer"}, "B_BUILD_EXACT_SOURCE_RUN_POINTERS")
        read_pointer({"path": (Path(row["packet"]) / "PINS.json").as_posix(), **row["pins"]})
        tree = decode(emitted["manifests/ui-" + name + "-dist.json"])
        require(profile[name + "_dist_files"] == tree["files"]
                and profile[name + "_dist_directories"] == tree["directories"], "B_REAL_THREE_DIST_MANIFEST_JOIN")
    return emitted, result


def build_b_source(output: str | Path, authority_pointer: dict[str, Any],
                   fixed_inputs_pointer: dict[str, Any]) -> dict[str, Any]:
    """Publish a Source candidate only after every original actual B prerequisite."""
    fixed = decode(read_pointer(fixed_inputs_pointer))
    require(type(fixed) is dict and set(fixed) == {"schema", "scope", "source_authority", "normal",
        "A", "materials", "build_proofs", "base_source", "history", "runtime_root", "network_subnets"}
        and fixed["schema"] == 1 and fixed["scope"] == "CURRENT_B_FIXED_ACTUAL_PREREQUISITES"
        and fixed["source_authority"] == authority_pointer, "B_EXPLICIT_FIXED_ACTUAL_PREREQUISITES")
    derived = derive_b_profile(authority_pointer, fixed["network_subnets"])
    authority, inventory = derived["authority"], derived["inventory"]
    out = Path(output)
    ordinary(out, missing_leaf=True)
    require(out.is_absolute() and not out.exists(), "ONE_CREATE_ONLY_EXTERNAL_B_SOURCE")
    for protected in (Path(__file__).resolve().parents[3], BASELINE, HISTORICAL_NORMAL,
                      Path(authority["candidate_root"]), Path(authority["validation_root"])):
        require(not out.is_relative_to(protected) and not protected.is_relative_to(out), "B_OUTPUT_DISJOINT_FROM_ALL_FIXED_SOURCES")
    for protected in (Path(fixed["normal"]["root"]), Path(fixed["materials"]["root"]), Path(fixed["base_source"]["root"])):
        require(not out.is_relative_to(protected) and not protected.is_relative_to(out), "B_OUTPUT_DISJOINT_FROM_ACTUAL_INPUT_PACKETS")
    require(not Path(fixed_inputs_pointer["path"]).is_relative_to(out), "B_FIXED_INPUT_DESCRIPTOR_OUTSIDE_NEW_OUTPUT")
    normal = fixed["normal"]
    require(set(normal) == {"root", "review", "result", "outer"}, "B_EXACT_CURRENT_NORMAL_M_INPUTS")
    normal_root = Path(normal["root"])
    binding = decode(read_pointer(_pointer(normal_root / "qualification/CURRENT-BINDING.json")))
    require(binding["technical_authority"] == authority_pointer
            and binding["source_sha"] == authority["source_sha"], "B_CURRENT_NORMAL_SAME_REVIEWED_SOURCE")
    m_created, _ = _b_outer(normal["outer"])
    def actual_normal_argv(created, mode):
        argv = created["argv"]
        require(len(argv) == 11 and argv[1:5] == ["-I", "-B", "-X", "utf8"]
                and Path(argv[5]) == normal_root / "launcher/launch.py" and argv[6] == mode
                and argv[7] == "--source-review-file" and Path(argv[8]) == Path(normal["review"]["path"])
                and argv[9:] == ["--source-review-sha256", normal["review"]["sha256"]],
                "B_ORIGINAL_CURRENT_NORMAL_LAUNCHER_MODE_AND_REVIEW_ARGV")
    actual_normal_argv(m_created, "materialize")
    consumer = _b_normal_consumer(normal_root, binding, normal["review"], normal["result"])
    namespace = {"Path": Path, "os": os, "stat": stat, "re": re, "json": json,
                 "hashlib": hashlib, "argparse": argparse, "sys": __import__("sys"),
                 "sha": lambda raw: pin(raw)["sha256"],
                 "REPO": Path(authority["validation_root"])}
    exec(compile(consumer, "reviewed-current-normal-readonly-preflight", "exec"), namespace)
    setup = namespace["current_b_normal_materialization"]()
    a = fixed["A"]
    require(type(a) is dict and set(a) == {"session", "outer", "normal_root_result", "worker_close",
        "independent_zero", "post_a_history", "context", "post_a_contract"}, "B_ALL_FIVE_ACTUAL_A_PROOFS_REQUIRED")
    require(type(a["session"]) is int and a["session"] > 0, "B_REAL_SUCCESSFUL_A_OUTER_SESSION")
    a_created, _ = _b_outer(a["outer"])
    actual_normal_argv(a_created, "normal-start")
    context = decode(read_pointer(a["context"]))
    final = decode(read_pointer(a["normal_root_result"]))
    close = decode(read_pointer(a["worker_close"])); zero = decode(read_pointer(a["independent_zero"]))
    post = decode(read_pointer(a["post_a_history"]))
    require(final["status"] == "ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO"
            and close["status"] == "VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL"
            and zero["status"] == "VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO",
            "B_REAL_SUCCESSFUL_A_RESULT_CLOSE_AND_INDEPENDENT_ZERO")
    for value in (final, close, zero, context):
        require(value["source_sha"] == authority["source_sha"] and value["run_id"] == context["run_id"],
                "B_SAME_ACTUAL_A_RUN_AND_CURRENT_SOURCE")
    require(context["repo_dir"] == authority["validation_root"] and context["freeze_sha256"] == setup["freeze_sha256"]
            and context["prep_dir"] == (normal_root / "validation/actual-profile").as_posix(), "B_A_CONTEXT_JOINS_ACTUAL_M")
    require(close["exact_nonforce_removed"] == {"containers": 4, "networks": 1, "volumes": 9}
            and close["ordinary_stop_and_exact_nonforce_remove"] is True
            and [(row["kind"], row["label"]) for row in zero["queries"]]
                == [(kind, label) for kind in ("container", "network", "volume") for label in ("project", "run")]
            and all(type(row["native_exit"]) is int and row["native_exit"] == 0
                    and row["stdout_bytes"] == row["stderr_bytes"] == 0 for row in zero["queries"]),
            "B_ORIGINAL_ORDINARY_A_RETIREMENT_AND_SIX_NATIVE_RAW_EMPTY_QUERIES")
    post_contract = a["post_a_contract"]
    require(set(post_contract) == {"header", "files_key", "retained_public_files", "prior_public_inventory",
                                   "public_roots", "public_root_files"}
            and set(post) == set(post_contract["header"]) | {post_contract["files_key"]}
            and {key: post[key] for key in post_contract["header"]} == post_contract["header"],
            "B_FIXED_REAL_POST_A_INVENTORY_SCHEMA")
    for row in post[post_contract["files_key"]]: read_pointer(row)
    materials, material_result = _b_materials(fixed["materials"], authority_pointer, inventory, fixed["build_proofs"])
    # Construction below has no runtime side effects; all emitted scripts retain
    # the original guards and require a separate fixed independent Source review.
    return _b_publish(out, fixed_inputs_pointer, fixed, derived, consumer, setup, context,
                      materials, material_result)


_B_SOURCE_REVIEW = r'''
def current_b_outer(outcome):
    current_assert(set(outcome)=={'created','terminal','stdout','stderr'},'B_FIXED_ACTUAL_OUTER_FOUR')
    created=current_json(outcome['created']);terminal=current_json(outcome['terminal'])
    current_assert(type(created['pid']) is int and created['pid']>0 and type(created['argv']) is list
        and type(created['started_ns']) is int and set(terminal)==set(created)|{'native_exit','wait_returncode','finished_ns','stdout','stderr'}
        and all(terminal[key]==value for key,value in created.items())
        and type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
        and terminal['native_exit']==terminal['wait_returncode']==0
        and type(terminal['finished_ns']) is int and terminal['finished_ns']>created['started_ns'],'B_REAL_SAME_PID_NATURAL_OUTER_ZERO')
    for kind in ['stdout','stderr']:
        current_assert(type(terminal[kind]) is dict and set(terminal[kind])=={'bytes','sha256'}
            and terminal[kind]=={key:outcome[kind][key] for key in ['bytes','sha256']},'B_EXACT_FULL_OUTER_STREAM_POINTER')
        raw=current_stream(outcome[kind],True)
        current_assert(raw==b'' if kind=='stderr' else bool(raw),'B_COMPLETE_ACTUAL_OUTER_STREAM')

def current_b_source_review(path, expected_sha):
    current_assert(re.fullmatch('[a-f0-9]{64}',expected_sha),'EXPLICIT_B_SOURCE_REVIEW_SHA')
    review=current_json({'path':Path(path).as_posix(),'bytes':Path(path).stat().st_size,'sha256':expected_sha})
    current_assert(review['schema']==1 and review['status']=='PASS_CURRENT_B_SOURCE_REVIEW'
        and review['source_sha']==CURRENT_SOURCE_SHA and review['fixed_inputs']==CURRENT_B_FIXED_INPUT
        and review['original_business_and_resource_contracts_retained'] is True
        and review['complete_source_and_materials_reviewed'] is True,'INDEPENDENT_COMPLETE_B_SOURCE_REVIEW_REQUIRED')
    root=ROOT_SOURCE.parent
    current_assert(review['source_pins']['path']==(root/'SOURCE-PINS.json').as_posix(),'B_FIXED_SOURCE_PUBLICATION_PATH')
    publication=current_json({'path':(root/'SOURCE-PINS.json').as_posix(),**CURRENT_B_SOURCE_PINS})
    current_assert(publication['schema']==1 and publication['scope']=='CURRENT_B_COMPLETE_SOURCE_CANDIDATE_NOT_RUNTIME'
        and publication['source_sha']==CURRENT_SOURCE_SHA and publication['fixed_inputs']==CURRENT_B_FIXED_INPUT
        and review['members']==publication['members'],
        'B_REVIEW_EXACT_SOURCE_CANDIDATE_MEMBERS')
    for name,wanted in review['members'].items():
        current_assert(not Path(name).is_absolute() and '..' not in Path(name).parts,'B_REVIEW_SAFE_SOURCE_MEMBER')
        current_stream({'path':(root/name).as_posix(),**wanted})
    fixed=current_json(CURRENT_B_FIXED_INPUT)
    current_assert(fixed['source_authority']==CURRENT_B_TECHNICAL_AUTHORITY,'B_FIXED_SOURCE_AUTHORITY_INPUT')
    current_b_outer(fixed['normal']['outer']);current_b_outer(fixed['A']['outer'])
    current_assert(fixed['A']['session']==A_OUTER_SESSION
        and all(fixed['A'][key]==A_QUALIFICATION[key] for key in ['normal_root_result','worker_close','independent_zero','post_a_history']),
        'B_REVIEW_REAL_A_FIVE_PROOFS_STILL_EXACT')
    for name in ['normal_root_result','worker_close','independent_zero','post_a_history','context']:current_stream(fixed['A'][name])
    current_stream(fixed['materials']['producer_result']);current_stream(fixed['materials']['independent_review'])
    current_stream(fixed['build_proofs'])
'''


def _b_proof(name: str, before: bytes, after: bytes, previous: dict[str, Any],
             current: dict[str, Any]) -> dict[str, Any]:
    require(before == after or before.endswith(b"\n") and after.endswith(b"\n"), "B_COMPLETE_LINE_SEAMS")
    old_lines, new_lines = before.splitlines(keepends=True), after.splitlines(keepends=True)
    old_offsets, new_offsets = [0], [0]
    for line in old_lines: old_offsets.append(old_offsets[-1] + len(line))
    for line in new_lines: new_offsets.append(new_offsets[-1] + len(line))
    value = before; stages = []; chunks = []
    for kind, a, b, c, d in difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False).get_opcodes():
        if kind == "equal":
            raw = before[old_offsets[a]:old_offsets[b]]
            chunks.append({"before_utf8_offset": old_offsets[a], "after_utf8_offset": new_offsets[c], **pin(raw)})
        else:
            old, new = b"".join(old_lines[a:b]), b"".join(new_lines[c:d])
            offset = new_offsets[c]
            require(value[offset:offset + len(old)] == old, "B_FINITE_STAGE_EXACT_OFFSET")
            changed = value[:offset] + new + value[offset + len(old):]
            stages.append({"before_utf8": old.decode("utf-8"), "after_utf8": new.decode("utf-8"),
                           "stage_utf8_offset": offset, "whole_before": pin(value), "whole_after": pin(changed)})
            value = changed
    require(value == after, "B_ALL_STAGES_REPLAY_TO_EXACT_SOURCE")
    for stage in reversed(stages):
        old, new = stage["before_utf8"].encode(), stage["after_utf8"].encode(); offset = stage["stage_utf8_offset"]
        require(value[offset:offset + len(new)] == new, "B_INDEPENDENT_REVERSE_STAGE_OFFSET")
        value = value[:offset] + old + value[offset + len(new):]
    require(value == before, "B_ALL_STAGES_EXACT_WHOLE_SOURCE_INVERSE")
    return {"name": name, "before": previous, "after": current, "stages": stages, "unchanged_chunks": chunks}


def _b_publish(out: Path, fixed_pointer: dict[str, Any], fixed: dict[str, Any], derived: dict[str, Any],
               normal_consumer: str, setup: dict[str, Any], context: dict[str, Any],
               materials: dict[str, bytes], material_result: dict[str, Any]) -> dict[str, Any]:
    before, after = derived["before"], dict(derived["after"])
    authority, inventory = derived["authority"], derived["inventory"]
    head = authority["source_sha"]; normal_root = Path(fixed["normal"]["root"])
    binding = decode(read_pointer(_pointer(normal_root / "qualification/CURRENT-BINDING.json")))
    data = decode(read_pointer(binding["source_data"]))
    parent = binding["parent"]; a = fixed["A"]
    selected_subnets = derived["network_subnets"]
    require(("        - subnet: " + selected_subnets["isolation"] + "\n").encode()
            in stable_read(normal_root / "validation/actual-profile/compose.yml"),
            "B_ISOLATION_REUSES_ONLY_THE_ACTUAL_SUCCESSFUL_A_SELECTED_SUBNET")
    runtime = Path(fixed["runtime_root"])
    require(runtime.is_absolute() and runtime.parent == BASELINE.parent and not runtime.exists()
            and not runtime.is_relative_to(out) and not out.is_relative_to(runtime), "B_ORIGINAL_EXCLUSIVE_RUNTIME_BOUNDARY")
    artifacts = constant(before["root-source/root-ui-materialize-freeze.py"], "ARTIFACTS")
    require(len(artifacts) == 17, "B_ORIGINAL_COMPLETE_SEVENTEEN")
    for name, raw in materials.items(): after["candidate/" + name] = raw
    base = fixed["base_source"]
    require(set(base) == {"root", "PINS", "FINAL"}, "B_ORIGINAL_OBSERVER59_SOURCE_AUTHORITY")
    base_root = Path(base["root"])
    base_pins = decode(read_pointer({"path": (base_root / "PINS.json").as_posix(), **base["PINS"]}))
    base_members = base_pins.get("members", base_pins)
    require(len(base_members) == 59, "B_ORIGINAL_COMPLETE_OBSERVER_SOURCE59")
    for name, row in base_members.items():read_pointer({"path": (base_root / name).as_posix(), **row})
    original_final = decode(read_pointer({"path": (base_root / "FINAL-CANDIDATES.json").as_posix(), **base["FINAL"]}))
    tools = original_final["task2_observer_tools"]
    require(len(tools) == 3, "B_ORIGINAL_OBSERVER_TOOLS_THREE")
    for row in tools:read_pointer({"path": row["source"], **{key:row[key] for key in ("bytes", "sha256")}})
    core = decode(after["current-core-aux/CORE-AUX-AUTHORITY.json"])
    browser_pin = {"path": (out / "browser-v1/PINS.json").as_posix(), **pin(after["browser-v1/PINS.json"])}
    core.update(source_sha=head, browser_manifest=browser_pin, base_source=base)
    after["current-core-aux/CORE-AUX-AUTHORITY.json"] = encode(core)
    core_pointer = {"path": (out / "current-core-aux/CORE-AUX-AUTHORITY.json").as_posix(),
                    **pin(after["current-core-aux/CORE-AUX-AUTHORITY.json"])}
    rows = {row["path"]: row for row in data["requirements"]}
    for row in core["core_sources"]:
        require(row["path"] not in rows or rows[row["path"]] == row, "B_CONSISTENT_CORE_AND_NORMAL_SOURCE_OVERLAP")
        rows[row["path"]] = row
    latest = [inventory[row["path"]] for row in data["commit_chain"][-1]["files"]]
    require(latest and data["commit_chain"][-1]["source_sha"] == head, "B_ACTUAL_LAST_PARENT_COMMIT_FILES")
    members = {"files": list(inventory.values())}
    directories = set()
    for path in inventory:
        parts = path.split('/')
        directories.update('/'.join(parts[:i]) for i in range(1, len(parts)))
    members.update(directories=sorted(directories), file_count=len(inventory), directory_count=len(directories))
    members["manifest_sha256"] = pin(json.dumps({"files": members["files"], "directories": members["directories"]},
        ensure_ascii=False, separators=(',', ':')).encode())["sha256"]
    proofs = decode(read_pointer(fixed["build_proofs"]))
    qualification = {"schema":1, "scope":"B_UI_SOURCE_TREE_ACTUAL_QUALIFICATION", "source_sha":head,
        "source_tree_members":members, "source_authority":fixed["source_authority"],
        "builds":{name:{"actual_proof":proofs["builds"][name], **material_result["builds"][name]}
                  for name in ("h5", "merchant", "admin")}, "runtime_root":runtime.as_posix()}
    a_qualification = {"outer_native_exit":0, **{key:a[key] for key in (
        "normal_root_result", "worker_close", "independent_zero", "post_a_history")}}
    interface = decode(after["candidate/interface-v1.json"])
    current = interface["fresh_B_current_UI_profile_source"]
    current.update(source_sha=head, parent=parent, actual_adoption_authority=fixed["source_authority"],
        A_outer_exit=0, A_worker_close=a["worker_close"], A_independent_zero=a["independent_zero"],
        A_post_history=a["post_a_history"], materials_equivalence_authority=fixed["materials"]["independent_review"])
    contract = interface["integrated_B_source_tree_contract"]
    contract.update(source_sha=head, parent=parent, actual_adoption_authority=fixed["source_authority"],
        qualified_source_head=head, validation_worktree=authority["validation_root"],
        build_source=fixed["build_proofs"], actual_A=a_qualification, actual_A_post_history=a["post_a_history"])
    contract["build_and_materials"].update({name:material_result["builds"][name] for name in ("h5", "merchant", "admin")})
    contract["build_and_materials"]["public_copy"] = fixed["materials"]["producer_result"]
    after["candidate/interface-v1.json"] = encode(interface)
    for name in ("ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py"):
        key = "candidate/" + name
        after[key] = replace_constant(after[key], "B_SOURCE_TREE_SOURCE_SEAL", "SEALED_BY_ROOT_AFTER_B_SOURCE_TREE_QUALIFICATION")
        if name != "normal-close-ms4-fulfillment.py":
            after[key] = replace_constant(after[key], "SOURCE_TREE_ENTRY_PINS_STATUS", "BOUND_FROZEN_PHP_SOURCE")
    for name, symbol, target in (("ms4-host.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("normal-close-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("normal-close-ms4-fulfillment.py", "UI_HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "UI_HOST_SHA256", "ms4-host.py"),
        ("verify-zero-ms4-fulfillment.py", "CLOSE_SHA256", "normal-close-ms4-fulfillment.py"),
        ("verify-zero-ms4-fulfillment.py", "UI_CLOSE_SHA256", "normal-close-ms4-fulfillment.py"),
        ("verify-zero-ms4-fulfillment.py", "INTERFACE_SHA256", "interface-v1.json"),
        ("verify-zero-ms4-fulfillment.py", "UI_INTERFACE_SHA256", "interface-v1.json")):
        after["candidate/" + name] = replace_constant(after["candidate/" + name], symbol, pin(after["candidate/" + target])["sha256"])
    for symbol in ("INTERFACE_BYTES", "UI_INTERFACE_BYTES"):
        after["candidate/verify-zero-ms4-fulfillment.py"] = replace_constant(
            after["candidate/verify-zero-ms4-fulfillment.py"], symbol, len(after["candidate/interface-v1.json"]))
    derivation = decode(after["candidate/source-derivation.json"])
    derivation["current_nonself_artifacts"] = [{"path": name, **pin(after["candidate/" + name])}
                                               for name in artifacts if name != "source-derivation.json"]
    derivation["runtime_full17_sealed"] = True
    derivation["merchant_exit_bootstrap_sources"] = list(derived["merchant_exit_sources"])
    derivation["merchant_exit_before_original_early_seal"] = True
    derivation["order_menu_bootstrap_sources"] = list(derived["order_menu_sources"])
    derivation["order_menu_profile_mode"] = derived["order_menu_profile_mode"]
    derivation["declared_native_order_anchors_before_original_early_seal"] = True
    derivation["browser_compatibility"] = derived["browser_compatibility"]
    derivation["actual_clean_Source_Val_M_A_build_B_Run"] = {"source_sha":head,
        "normal_M":fixed["normal"]["result"], "A":a_qualification, "materials":fixed["materials"]["producer_result"], "B_Run":None}
    after["candidate/source-derivation.json"] = encode(derivation)
    selected = [{"path": name, **pin(after["candidate/" + name])} for name in artifacts]
    browser = decode(after["browser-v1/PINS.json"])
    proof_names = artifacts + ["browser-v1/" + name for name in browser["members"]] + ["browser-v1/PINS.json"]
    inverse = []
    for name in proof_names:
        key = name if name.startswith("browser-v1/") else "candidate/" + name
        if key in before:
            old = before[key]; previous = {"path": (BASELINE / "b" / key).as_posix(), **pin(old)}
        else:
            old = materials[name]; previous = {"path": (Path(fixed["materials"]["root"]) / name).as_posix(), **pin(old)}
        inverse.append(_b_proof(name, old, after[key], previous, {"path": (out / key).as_posix(), **pin(after[key])}))
    normal_publication = {"root":(normal_root / "validation").as_posix(),
        "profile_root":(normal_root / "validation/actual-profile").as_posix(),
        "PINS":_pointer(normal_root / "validation/PROFILE-PINS.json"),
        "PUBLICATION":_pointer(normal_root / "validation/PROFILE-PUBLICATION.json")}
    publication = {"schema":1,"scope":"INTEGRATED_B_UI_SOURCE_TREE_KNOWN_SOURCE_METADATA_ONLY",
        "source_sha":head,"parent":parent,"base_source":base,"base_profile":(BASELINE / "b/candidate").as_posix(),
        "selected17":selected,"repository_Source_requirements":data["requirements"],
        "finite_contexts":{"repository_committed_files":latest,"normal9_authority":{
            "root":normal_publication["root"],"profile_root":normal_publication["profile_root"],
            "PROFILE_PINS":normal_publication["PINS"],"PROFILE_PUBLICATION":normal_publication["PUBLICATION"]},
            "current_core_aux_authority":core_pointer,"current_Core3":core["core_sources"],
            "ordered_auxiliary_members":[{"path":name,**row} for name,row in browser["members"].items()],
            "whole_forward_inverse_files":inverse,"SourceRows":list(rows.values()),"unchanged_tools3":tools,
            "browser_compatibility":derived["browser_compatibility"],
            "original_build_provenance_and_current_input_equivalence":fixed["materials"]["producer_result"]},
        "target_execution":"NOT RUN"}
    after["candidate/PUBLICATION.json"] = encode(publication)
    after["candidate/PINS.json"] = encode({"schema":1,"scope":"INTEGRATED_B_UI_SOURCE_TREE_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL",
        "members":{name:pin(after["candidate/" + name]) for name in artifacts + ["DESIGN.md", "PUBLICATION.json"]}})
    checks = {"schema":1,"status":"CURRENT_B_SOURCE_CANDIDATE_NOT_APPROVED_NO_B_RUNTIME", "source_sha":head,
        "fixed_inputs":fixed_pointer,"original_A_five_proofs":a_qualification,"actual_materials":fixed["materials"]["producer_result"],
        "original_builds_retained":True,"complete17":selected,"all_finite_inverse_proofs":True,
        "network_subnets":selected_subnets,
        "network_availability":"NOT_PROVED_BY_AUTHOR_ROOT_FRESH_RECHECK_REQUIRED_BEFORE_START_ISOLATION_REUSE_ONLY_AFTER_ACTUAL_A_CLOSE_AND_ZERO",
        "changed_python_bodies":{"ms4-host.py":["verify_files_bootstrap","ui_copy_freeze_values","ui_web_connect","ui_web_networks","prerequisite_result"],
            "verify-zero-ms4-fulfillment.py":["ui_copy_freeze_values","ui_zero_network_identity","ui_zero_resource_networks"],
            "normal-close-ms4-fulfillment.py":[],
            "root-ui-materialize-freeze.py":["main","current_known_normal_root","current_consume_materialization","current_verify_sources","frozen_materials","frozen_resources"],
            "root-ui-start-to-ready.py":["main","current_core_aux_authority"]},
        "added_schema_consumer":"ms4-host.py:verify_schema_bootstrap",
        "added_merchant_exit_consumer":"ms4-host.py:verify_merchant_exit_bootstrap",
        "added_order_menu_consumer":"ms4-host.py:verify_order_menu_bootstrap",
        "browser_compatibility":derived["browser_compatibility"],
        "runtime":"NOT RUN","approval":"NOT_GRANTED_REQUIRES_INDEPENDENT_SOURCE_REVIEW"}
    after["SOURCE-CHECKS.json"] = encode(checks)
    inputs = decode(before["root-source/INPUTS.json"])
    inputs.update(sealed=True, head=head, parent=parent, root_baseline=BASELINE_PINS,
        normal_root={"root":(normal_root / "validation").as_posix(),"PINS":_pointer(normal_root / "validation/PINS.json")},
        normal_profile_publication=normal_publication, ui_profile_publication={"root":(out / "candidate").as_posix(),
            "PINS":{"path":(out / "candidate/PINS.json").as_posix(),**pin(after["candidate/PINS.json"])},
            "PUBLICATION":{"path":(out / "candidate/PUBLICATION.json").as_posix(),**pin(after["candidate/PUBLICATION.json"])}},
        committed_files=latest,history=fixed["history"],A_QUALIFICATION=a_qualification,A_OUTER_SESSION=a["session"],
        source_review_authority=fixed["source_authority"],post_a_history_contract=a["post_a_contract"],
        body_preservation={"path":(out / "SOURCE-CHECKS.json").as_posix(),**pin(after["SOURCE-CHECKS.json"])},
        current_core_aux_authority=core_pointer)
    after["root-source/INPUTS.json"] = encode(inputs)
    constants = {"PREPARED":out,"RUNTIME":runtime,"MAIN":Path(authority["candidate_root"]),
        "REPO":Path(authority["validation_root"]),"PROFILE_SOURCE":out / "candidate",
        "NORMAL_SOURCE":normal_root / "validation","HEAD":head,"PREVIOUS":head,"COMMIT_PARENT":parent,
        "A_OUTER_SESSION":a["session"],"A_QUALIFICATION":a_qualification,"OLD_RUN":context["run_id"],
        "OLD_FREEZE":setup["freeze_sha256"],"OLD_FREEZE_BYTES":setup["freeze_bytes"],
        "OLD_CONTEXT_PIN":{key:a["context"][key] for key in ("bytes","sha256")},
        "NORMAL_SETUP_PIN":{key:fixed["normal"]["result"][key] for key in ("bytes","sha256")},
        "OLD_COORDINATOR_SHA":pin(stable_read(normal_root / "validation/run.py"))["sha256"],
        "OLD_CLI_PINS":{name:pin(stable_read(normal_root / "validation/actual-profile" / name))["sha256"]
                        for name in ("ms4-host.py","normal-close-ms4-fulfillment.py","verify-zero-ms4-fulfillment.py")},
        "DIST_SOURCES":{name:material_result["builds"][name]["dist_root"] for name in ("h5","merchant","admin")},
        "B_SOURCE_TREE_QUALIFICATION":qualification,"ROOT_INPUTS_PIN":pin(after["root-source/INPUTS.json"]),
        "SOURCE_REVIEW_AUTHORITY":fixed["source_authority"],"POST_A_HISTORY_CONTRACT":a["post_a_contract"],
        "TOOL_SOURCE":base_root / "observer-source","SOURCE_ROWS":rows,"COMMITTED_FILES":latest,
        "COMMITTED_PATHS":set(row["path"] for row in latest),"NORMAL_SOURCE_PINS":decode(stable_read(normal_root / "validation/PINS.json"))["members"],
        "CURRENT_M_SOURCE_CONTRACT":{"source_sha":head,"SourceRows":list(rows.values()),
            "latest_commit_paths":sorted(row["path"] for row in latest),"source_requirements":data["requirements"]}}
    root_m = after["root-source/root-ui-materialize-freeze.py"]
    for name,value in constants.items():root_m = _b_constant(root_m,name,value)
    # PREPARED now names the new B package; the original proof receipt remains
    # at the exact independently pinned path already used by both Hosts.
    proof_reference = constant(after["candidate/ms4-host.py"], "PROOF_INI_REFERENCE")
    require(proof_reference == constant(before["candidate/ms4-host.py"], "PROOF_INI_REFERENCE"),
            "B_ORIGINAL_PROOF_INI_REFERENCE_RETAINED")
    read_pointer(proof_reference)
    frozen_resources = _source_function(root_m, "frozen_resources")
    frozen_resources = once(frozen_resources.encode(),
        b"(PREPARED / 'r20-proof-memory-budget-public-readback-r1/RESULT.json').as_posix()",
        repr(proof_reference["path"]).encode()).decode()
    root_m = _b_replace_function(root_m, "frozen_resources", frozen_resources)
    root_m = _b_replace_function(root_m, "current_known_normal_root", '''def current_known_normal_root(inputs):
    current_b_outer(CURRENT_B_M_OUTER)
    setup=current_b_normal_materialization()
    authority=inputs['normal_root']
    assert authority['root']==NORMAL_SOURCE.as_posix() and setup['root_source_pins']==authority['PINS']
    return authority,setup''')
    root_m = _b_replace_function(root_m, "current_consume_materialization", '''def current_consume_materialization(inputs):
    setup=current_known_normal_root(inputs)[1]
    for name,row in CURRENT_READS.items():
        OLD_NATIVE_PINS.append({key:row[key] for key in ['path','bytes','sha256']})
    return setup''')
    old = _source_function(root_m,"current_verify_sources")
    first = old.index("    normal_root,normal_manifest,normal_pub,normal_state=publication_source(")
    last = old.index("    assert normal_root==OLD_PROFILE", first)
    prefix = '''    normal_root=OLD_PROFILE
    normal_manifest=current_json(inputs['normal_profile_publication']['PINS'])
    normal_pub=current_json(inputs['normal_profile_publication']['PUBLICATION'])
    source_authority(normal_pub,lambda pointer:current_stream(pointer,True),current_assert)
    normal_state=tree(normal_root)
'''
    root_m = _b_replace_function(root_m,"current_verify_sources",old[:first]+prefix+old[last:])
    anchor = b"    public = tree(REPO / 'crmeb/public')\n"
    projection = b"    public = {'files':{name:row for name,row in public['files'].items() if name!='install' and not name.startswith('install/')},'directories':[name for name in public['directories'] if name!='install' and not name.startswith('install/')]}\n"
    root_m = once(root_m,anchor,anchor+projection)
    root_s = after["root-source/root-ui-start-to-ready.py"]
    s_constants = {"RUNTIME":runtime,"SOURCE":head,"SOURCE_PARENT":parent,"A_OUTER_SESSION":a["session"],
        "SOURCE_REVIEW_AUTHORITY":fixed["source_authority"],"POST_A_HISTORY_CONTRACT":a["post_a_contract"],
        "B_SOURCE_TREE_QUALIFICATION":qualification,"ROOT_INPUTS_PIN":pin(after["root-source/INPUTS.json"]),
        "A_QUALIFICATION":a_qualification,"CURRENT_CORE_AUX_AUTHORITY_PIN":core_pointer,
        "REPO":Path(authority["validation_root"]),"RUN_ROOT":runtime / "runs",
        "PINS":{name:pin(after["candidate/"+name])["sha256"] for name in constants["OLD_CLI_PINS"]},
        "SOURCE_BYTES":{name:len(after["candidate/"+name]) for name in constants["OLD_CLI_PINS"]},
        "PROFILE_ARTIFACTS":[{"artifact":row["path"],**row} for row in selected]}
    for name,value in s_constants.items():root_s=_b_constant(root_s,name,value)
    old_browser_pin = pin(before["browser-v1/PINS.json"])
    root_s = once(root_s,("'bytes':1222,'sha256':'"+old_browser_pin["sha256"]+"'").encode(),
        ("'bytes':"+str(browser_pin["bytes"])+",'sha256':'"+browser_pin["sha256"]+"'").encode())
    shared_constants = "\nCURRENT_B_FIXED_INPUT = "+repr(fixed_pointer)+"\nCURRENT_B_TECHNICAL_AUTHORITY = "+repr(fixed["source_authority"])+"\n"
    # SOURCE-PINS omits itself; its fixed digest is installed only after the two
    # callers are complete through an external reviewed publication descriptor.
    review_runtime = _B_SOURCE_REVIEW.replace("**CURRENT_B_SOURCE_PINS", "**review['source_pins']")
    for name,raw,shared in (("root-ui-materialize-freeze.py",root_m,normal_consumer),
                            ("root-ui-start-to-ready.py",root_s,normal_consumer)):
        raw = once(raw,b"import sys\n",b"import sys\nimport os\nimport stat\n") if name.startswith("root-ui-materialize") else raw
        raw = once(raw,b"    args = parser.parse_args()\n",b"    parser.add_argument('--source-review-file',required=True)\n    parser.add_argument('--source-review-sha256',required=True)\n    args = parser.parse_args()\n    current_b_source_review(args.source_review_file,args.source_review_sha256)\n")
        if name == "root-ui-start-to-ready.py":
            anchor = b"    current_b_source_review(args.source_review_file,args.source_review_sha256)\n"
            raw = once(raw,anchor,anchor+b"    current_b_normal_materialization()\n")
        extra = shared_constants + "CURRENT_B_M_OUTER = "+repr(fixed["normal"]["outer"])+"\n"+shared+review_runtime
        # Insert before the original main dispatch, leaving all original helpers
        # and business-stage bodies intact.
        marker = b"if __name__ == '__main__':\n"
        after["root-source/"+name] = once(raw,marker,extra.encode()+"\n".encode()+marker)
    for name, changed in (("root-ui-materialize-freeze.py", {"main", "current_known_normal_root",
            "current_consume_materialization", "current_verify_sources", "frozen_materials", "frozen_resources"}),
            ("root-ui-start-to-ready.py", {"main", "current_core_aux_authority"})):
        original = functions(before["root-source/" + name]); candidate = functions(after["root-source/" + name])
        require(all(symbol in candidate and candidate[symbol] == raw
                    for symbol, raw in original.items() if symbol not in changed),
                "B_ALL_ORIGINAL_NON_TARGET_ROOT_AND_BUSINESS_FUNCTIONS_RAW_EXACT")
    root_names = ["DESIGN-TASKS.md","INPUTS.json","root-ui-materialize-freeze.py","root-ui-start-to-ready.py"]
    after["root-source/PINS.json"] = encode({"schema":1,"scope":"INTEGRATED_B_UI_SOURCE_TREE_ROOT_SOURCE_PINS",
        "members":{name:pin(after["root-source/"+name]) for name in root_names}})
    for name,raw in after.items():
        if name.endswith('.py'):compile(raw,name,'exec')
        elif name.endswith('.json'):decode(raw)
    original_text_names = tuple(name for name, raw in before.items() if raw.endswith(b'\n'))
    original_text = {name:before[name] for name in original_text_names}
    current_text = {name:after[name] for name in original_text_names}
    forward, reverse = make_patch(original_text,current_text,original_text_names), make_patch(current_text,original_text,original_text_names)
    require(apply_patch(original_text,forward,original_text_names)==current_text
            and apply_patch(current_text,reverse,original_text_names)==original_text,
            "B_ALL_ORIGINAL_TEXT_MEMBERS_COMPLETE_FORWARD_AND_REVERSE_REPLAY")
    require(all(after[name] == raw for name,raw in before.items() if name not in original_text),
            "B_ORIGINAL_BINARY_MEMBERS_RAW_EXACT")
    after['SOURCE-FORWARD.patch']=forward; after['SOURCE-REVERSE.patch']=reverse
    # No generated package is executable until a nonauthor reviews these exact
    # bytes. The publication pointer is read from that explicit review at runtime.
    source_pins = encode({"schema":1,"scope":"CURRENT_B_COMPLETE_SOURCE_CANDIDATE_NOT_RUNTIME",
        "source_sha":head,"fixed_inputs":fixed_pointer,"members":{name:pin(raw) for name,raw in after.items()}})
    after["SOURCE-PINS.json"] = source_pins
    # Every original file not intentionally changed is still present and raw;
    # new four manifests come only from the actual qualified producer above.
    require(set(before) <= set(after) and all(after["candidate/"+row["path"]] == materials[row["path"]]
        for row in selected if row["path"] in materials), "B_COMPLETE_ORIGINAL_AND_TRUE_MATERIAL_SOURCE")
    write_set(out,after)
    return {**checks,"source_root":out.as_posix(),"SOURCE_PINS":_pointer(out / "SOURCE-PINS.json")}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    destination = parser.add_mutually_exclusive_group(required=True)
    destination.add_argument("--output", help="Derive the exact Core9 profile directory.")
    destination.add_argument("--normal-source", help="Author the complete normal18 around an existing reviewed Core9.")
    destination.add_argument("--b-source", help="Derive original B Source after fixed actual M, A and five-material proofs.")
    parser.add_argument("--b-inputs-file", help="Explicit completed current B prerequisite descriptor; only with --b-source.")
    parser.add_argument("--b-inputs-sha256", help="Fixed SHA256 of the complete B prerequisite descriptor.")
    parser.add_argument("--isolation-subnet", help="Optional canonical RFC1918 IPv4 CIDR; only with --output.")
    parser.add_argument("--initializer-syntax-result", help="Actual successful current initializer syntax receipt, for normal18.")
    parser.add_argument("--authority-file", required=True)
    parser.add_argument("--authority-sha256", required=True)
    args = parser.parse_args()
    require(args.output is not None or args.isolation_subnet is None, "ISOLATION_SUBNET_ONLY_FOR_PROFILE_OUTPUT")
    require(re.fullmatch(r"[a-f0-9]{64}", args.authority_sha256) is not None, "FIXED_AUTHORITY_SHA256")
    path = Path(args.authority_file)
    raw = stable_read(path)
    pointer = {"path": path.as_posix(), "bytes": len(raw), "sha256": args.authority_sha256}
    require(pin(raw)["sha256"] == args.authority_sha256, "EXPLICIT_AUTHORITY_SHA256_MATCH")
    if args.b_source:
        require(args.initializer_syntax_result is None and args.b_inputs_file is not None
                and type(args.b_inputs_sha256) is str and re.fullmatch(r"[a-f0-9]{64}", args.b_inputs_sha256),
                "B_FIXED_COMPLETE_PREREQUISITE_FILE_AND_SHA_REQUIRED")
        fixed_path = Path(args.b_inputs_file)
        fixed_raw = stable_read(fixed_path)
        fixed_pointer = {"path": fixed_path.as_posix(), "bytes": len(fixed_raw), "sha256": args.b_inputs_sha256}
        require(pin(fixed_raw)["sha256"] == args.b_inputs_sha256, "B_EXPLICIT_INPUT_DESCRIPTOR_SHA")
        result = build_b_source(args.b_source, pointer, fixed_pointer)
    elif args.normal_source:
        require(args.b_inputs_file is None and args.b_inputs_sha256 is None, "B_INPUTS_ONLY_FOR_B_SOURCE")
        require(args.initializer_syntax_result is not None, "ACTUAL_INITIALIZER_SYNTAX_RESULT_REQUIRED")
        result = build_normal(args.normal_source, pointer, args.initializer_syntax_result)
    else:
        require(args.b_inputs_file is None and args.b_inputs_sha256 is None, "B_INPUTS_ONLY_FOR_B_SOURCE")
        require(args.initializer_syntax_result is None, "SYNTAX_RECEIPT_ONLY_FOR_NORMAL18")
        result = build_profile(args.output, pointer, isolation_subnet=args.isolation_subnet)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
