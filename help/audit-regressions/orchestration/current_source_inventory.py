"""Capture current Git source evidence; capture never grants source approval.

This module reads Git objects and two clean worktrees.  It does not import the
archived orchestrators, execute application code, or operate runtime resources.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
from typing import Any

BASELINE_SHA = "584532ba22b1824164aa11c06b163715be8c224c"
PROTECTED_NAMESPACES = (
    "crmeb/app", "crmeb/crmeb", "crmeb/config", "crmeb/vendor",
    "help/migrations", "help/audit-regressions/isolated",
    "template/admin/src", "template/merchant", "template/uni-app",
)
STAT_FIELDS = (
    "st_dev", "st_ino", "st_mode", "st_nlink", "st_uid", "st_gid",
    "st_size", "st_mtime_ns", "st_ctime_ns",
)


def require(condition: bool, label: str) -> None:
    if not condition:
        raise ValueError(label)


def pin(raw: bytes) -> dict[str, Any]:
    return {"bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def encode(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def ordinary(path: Path) -> None:
    require(path.is_absolute() and ".." not in path.parts, "ABSOLUTE_ORDINARY_PATH")
    for node in (path, *path.parents):
        info = node.lstat()
        require(not stat.S_ISLNK(info.st_mode)
                and not (getattr(info, "st_file_attributes", 0) & 1024),
                "NO_SYMLINK_OR_REPARSE_POINT: " + str(node))


def stamp(path: Path) -> list[int]:
    return [getattr(path.lstat(), field) for field in STAT_FIELDS]


def safe_relative(name: str) -> None:
    parts = PurePosixPath(name).parts
    require(bool(parts) and not name.startswith("/") and "\\" not in name
            and ":" not in name and all(part not in (".", "..", "") for part in parts)
            and PurePosixPath(name).as_posix() == name,
            "ORDINARY_CANONICAL_TRACKED_PATH")


class Git:
    def __init__(self, repo: Path):
        self.repo = repo
        self.operations: list[dict[str, Any]] = []

    def run(self, *args: str, data: bytes | None = None) -> bytes:
        argv = ["git", "--no-optional-locks", "-c", "safe.directory=" + self.repo.as_posix(),
                "-c", "core.fsmonitor=false", "-c", "core.untrackedCache=false", *args]
        proc = subprocess.Popen(argv, cwd=self.repo, stdin=subprocess.PIPE,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        stdout, stderr = proc.communicate(data)
        self.operations.append({"argv": argv, "pid": proc.pid,
                                "native_exit": proc.returncode,
                                "wait_returncode": proc.wait(),
                                "stdin": None if data is None else pin(data),
                                "stdout": pin(stdout), "stderr": pin(stderr)})
        require(proc.returncode == 0, "GIT_QUERY_FAILED: " + " ".join(args[:3]))
        require(stderr == b"", "GIT_QUERY_STDERR: " + " ".join(args[:3]))
        return stdout

    def text(self, *args: str) -> str:
        return self.run(*args).decode("utf-8").rstrip("\n")


def git_tree(git: Git, source: str) -> dict[str, dict[str, Any]]:
    raw = git.run("ls-tree", "-r", "-z", "--full-tree", source)
    require(raw.endswith(b"\0"), "COMPLETE_NONEMPTY_GIT_TREE")
    result: dict[str, dict[str, Any]] = {}
    for record in raw[:-1].split(b"\0"):
        meta, raw_name = record.split(b"\t", 1)
        mode, kind, oid = meta.decode("ascii").split(" ")
        name = raw_name.decode("utf-8")
        safe_relative(name)
        require(name not in result and mode in ("100644", "100755") and kind == "blob"
                and re.fullmatch(r"[0-9a-f]{40}", oid) is not None,
                "UNIQUE_ORDINARY_TRACKED_BLOB")
        result[name] = {"path": name, "git_mode": mode, "git_blob": oid}
    return dict(sorted(result.items()))


def blobs(git: Git, trees: list[dict[str, dict[str, Any]]]) -> dict[str, dict[str, Any]]:
    """Read each unique object once, in bounded binary-safe cat-file batches."""
    ids = sorted({row["git_blob"] for tree in trees for row in tree.values()})
    result: dict[str, dict[str, Any]] = {}
    for offset in range(0, len(ids), 64):
        batch = ids[offset:offset + 64]
        raw = git.run("cat-file", "--batch", data=("\n".join(batch) + "\n").encode("ascii"))
        cursor = 0
        for requested in batch:
            end = raw.index(b"\n", cursor)
            oid, kind, size_text = raw[cursor:end].decode("ascii").split(" ")
            size = int(size_text)
            require(oid == requested and kind == "blob" and size >= 0,
                    "ACTUAL_REQUESTED_GIT_BLOB")
            cursor = end + 1
            body = raw[cursor:cursor + size]
            require(len(body) == size and raw[cursor + size:cursor + size + 1] == b"\n",
                    "COMPLETE_BINARY_GIT_BLOB")
            require(hashlib.sha1(b"blob " + str(size).encode("ascii") + b"\0" + body).hexdigest() == oid,
                    "GIT_OBJECT_ID_MATCHES_COMPLETE_BLOB")
            result[oid] = pin(body)
            cursor += size + 1
        require(cursor == len(raw), "NO_UNCONSUMED_BATCH_OUTPUT")
    return result


def index_tree(git: Git) -> dict[str, dict[str, Any]]:
    raw = git.run("ls-files", "--stage", "-z")
    require(raw.endswith(b"\0"), "COMPLETE_NONEMPTY_INDEX")
    result: dict[str, dict[str, Any]] = {}
    for record in raw[:-1].split(b"\0"):
        meta, raw_name = record.split(b"\t", 1)
        mode, oid, stage = meta.decode("ascii").split(" ")
        name = raw_name.decode("utf-8")
        safe_relative(name)
        require(stage == "0" and name not in result, "NO_UNMERGED_OR_DUPLICATE_INDEX_MEMBER")
        result[name] = {"path": name, "git_mode": mode, "git_blob": oid}
    return dict(sorted(result.items()))


def clean_source(git: Git, source: str, expected: dict[str, dict[str, Any]]) -> None:
    require(git.text("rev-parse", "HEAD") == source, "EXACT_WORKTREE_HEAD")
    require(git.run("status", "--porcelain=v1", "--untracked-files=all") == b"",
            "CLEAN_WORKTREE_WITH_NO_UNTRACKED_MEMBERS")
    require(index_tree(git) == expected, "COMPLETE_INDEX_EQUALS_COMMIT_TREE")


def file_row(root: Path, row: dict[str, Any]) -> dict[str, Any]:
    path = root / row["path"]
    ordinary(path)
    require(stat.S_ISREG(path.lstat().st_mode), "REGULAR_TRACKED_FILE")
    before = stamp(path)
    digest = hashlib.sha256()
    size = 0
    with path.open("rb") as stream:
        while block := stream.read(1024 * 1024):
            digest.update(block)
            size += len(block)
    require(stamp(path) == before, "STABLE_FULL_TRACKED_FILE_READ")
    require({"bytes": size, "sha256": digest.hexdigest()} == {
        key: row[key] for key in ("bytes", "sha256")}, "CHECKOUT_EQUALS_ACTUAL_GIT_BLOB: " + row["path"])
    return {"path": path.as_posix(), "bytes": size, "sha256": digest.hexdigest(), "stat9": before}


def namespace_receipt(tree: dict[str, dict[str, Any]], namespace: str) -> dict[str, Any]:
    names = [name for name in tree if name.startswith(namespace + "/")]
    return {"count": len(names), "paths_sha256": hashlib.sha256(
        ("\n".join(names) + "\n").encode("utf-8")).hexdigest()}


def inspect_namespace(root: Path, tree: dict[str, dict[str, Any]], namespace: str) -> dict[str, Any]:
    base = root / namespace
    actual = []
    ordinary(base)
    require(base.is_dir(), "PROTECTED_NAMESPACE_DIRECTORY")
    for current, dirs, files in os.walk(base, followlinks=False):
        ordinary(Path(current))
        for name in (*dirs, *files):
            ordinary(Path(current) / name)
        for name in files:
            path = Path(current) / name
            require(stat.S_ISREG(path.lstat().st_mode), "PROTECTED_REGULAR_FILE")
            actual.append(path.relative_to(root).as_posix())
    actual.sort()
    expected = [name for name in tree if name.startswith(namespace + "/")]
    require(actual == expected, "PROTECTED_NAMESPACE_HAS_NO_EXTRA_OR_MISSING_FILES: " + namespace)
    return namespace_receipt(tree, namespace)


def write_exact(path: Path, raw: bytes) -> None:
    if path.exists():
        ordinary(path)
        require(path.is_file() and path.read_bytes() == raw, "REFUSE_DIFFERENT_EXISTING_OUTPUT: " + str(path))
    else:
        with path.open("xb") as stream:
            stream.write(raw)


def capture(repo: str | Path, candidate: str | Path, validation: str | Path,
            source_sha: str, output: str | Path) -> dict[str, Any]:
    """Return evidence bound to exact Git objects; status is never approval."""
    require(re.fullmatch(r"[0-9a-f]{40}", source_sha) is not None, "EXACT_SOURCE_SHA")
    roots = [Path(value).absolute() for value in (repo, candidate, validation)]
    for root in roots:
        ordinary(root)
        require(root.is_dir(), "EXISTING_SOURCE_ROOT")
    repo_path, candidate_path, validation_path = roots
    require(candidate_path != validation_path, "TWO_DISTINCT_SOURCE_WORKTREES")
    out = Path(output).absolute()
    require(".." not in out.parts and all(out != root and root not in out.parents for root in roots),
            "REPOSITORY_EXTERNAL_OUTPUT")
    require(all(out not in root.parents for root in roots), "OUTPUT_NOT_SOURCE_ANCESTOR")
    # Inspect the existing parent before creating the one requested output directory.
    ordinary(out.parent)
    require(out.parent.is_dir(), "EXISTING_OUTPUT_PARENT")
    git = Git(repo_path)
    require(Path(git.text("rev-parse", "--show-toplevel")) == repo_path, "ACTUAL_REPOSITORY_ROOT")
    require(git.text("rev-parse", "--show-object-format") == "sha1", "EXPECTED_GIT_OBJECT_FORMAT")
    require(git.text("rev-parse", "--verify", source_sha + "^{commit}") == source_sha,
            "ACTUAL_CURRENT_COMMIT")
    require(git.text("rev-parse", "--verify", BASELINE_SHA + "^{commit}") == BASELINE_SHA,
            "ACTUAL_HISTORICAL_COMMIT")
    git.run("merge-base", "--is-ancestor", BASELINE_SHA, source_sha)
    common = Path(git.text("rev-parse", "--path-format=absolute", "--git-common-dir"))
    current = git_tree(git, source_sha)
    baseline = git_tree(git, BASELINE_SHA)
    object_pins = blobs(git, [current, baseline])
    current = {name: {**row, **object_pins[row["git_blob"]]} for name, row in current.items()}
    baseline = {name: {**row, **object_pins[row["git_blob"]]} for name, row in baseline.items()}
    delta = [{"path": name, "kind": "ADD" if name not in baseline else "DELETE" if name not in current else "MODIFY",
              "before": baseline.get(name), "after": current.get(name)}
             for name in sorted(set(current) | set(baseline)) if current.get(name) != baseline.get(name)]
    actual_delta = git.run("diff", "--name-only", "--no-renames", "-z", BASELINE_SHA, source_sha)
    names = [] if actual_delta == b"" else actual_delta[:-1].decode("utf-8").split("\0")
    require(actual_delta == b"" or actual_delta.endswith(b"\0"), "COMPLETE_GIT_DELTA")
    require(sorted(names) == [row["path"] for row in delta], "COMPLETE_ACTUAL_GIT_DELTA")
    simple_tree = {name: {key: row[key] for key in ("path", "git_mode", "git_blob")}
                   for name, row in current.items()}
    checkout_gits = {}
    checkout_rows = {}
    namespaces = {}
    for label, root in (("candidate", candidate_path), ("validation", validation_path)):
        checkout_git = Git(root)
        require(Path(checkout_git.text("rev-parse", "--show-toplevel")) == root, "ACTUAL_CHECKOUT_ROOT")
        require(Path(checkout_git.text("rev-parse", "--path-format=absolute", "--git-common-dir")) == common,
                "SAME_GIT_OBJECT_REPOSITORY")
        clean_source(checkout_git, source_sha, simple_tree)
        checkout_rows[label] = {name: file_row(root, row) for name, row in current.items()}
        namespaces[label] = {namespace: inspect_namespace(root, current, namespace)
                             for namespace in PROTECTED_NAMESPACES}
        checkout_gits[label] = checkout_git
    require(namespaces["candidate"] == namespaces["validation"], "TWO_PROTECTED_NAMESPACE_INVENTORIES_EQUAL")
    for label, root in (("candidate", candidate_path), ("validation", validation_path)):
        clean_source(checkout_gits[label], source_sha, simple_tree)
        for name, observed in checkout_rows[label].items():
            ordinary(root / name)
            require(stamp(root / name) == observed["stat9"], "FULL_SOURCE_NATIVE_NINE_HELD")
        require({namespace: inspect_namespace(root, current, namespace) for namespace in PROTECTED_NAMESPACES}
                == namespaces[label], "PROTECTED_NAMESPACE_MEMBERSHIP_HELD")
    source_rows = [{**row, "checkouts": {label: checkout_rows[label][name] for label in checkout_rows}}
                   for name, row in current.items()]
    inventory_raw = b"".join((json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")
                             for row in source_rows)
    baseline_raw = b"".join((json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")
                            for row in baseline.values())
    delta_raw = encode({"schema": 1, "baseline_sha": BASELINE_SHA, "source_sha": source_sha,
                        "status": "CAPTURED_NOT_APPROVED", "files": delta})
    protected = {namespace: {"before": namespace_receipt(baseline, namespace),
                             "after": namespace_receipt(current, namespace),
                             "changed_paths": [row["path"] for row in delta if row["path"].startswith(namespace + "/")]}
                 for namespace in PROTECTED_NAMESPACES}
    files = {"SOURCE-INVENTORY.jsonl": inventory_raw, "BASELINE-INVENTORY.jsonl": baseline_raw,
             "SOURCE-DELTA.json": delta_raw}
    manifest = {"schema": 1, "status": "CAPTURED_NOT_APPROVED", "source_sha": source_sha,
                "source_parents": git.text("rev-list", "--parents", "-n", "1", source_sha).split()[1:],
                "baseline_sha": BASELINE_SHA, "repo": repo_path.as_posix(),
                "candidate": candidate_path.as_posix(), "validation": validation_path.as_posix(),
                "tracked_file_count": len(current), "baseline_file_count": len(baseline),
                "delta_file_count": len(delta), "protected_namespaces": protected,
                "files": {name: {"path": (out / name).as_posix(), **pin(raw)} for name, raw in files.items()},
                "git_operations": {"objects": git.operations,
                                   **{label: item.operations for label, item in checkout_gits.items()}},
                "verification_scope": "COMPLETE_GIT_BLOBS_TWO_CLEAN_TRACKED_TREES_AND_PROTECTED_NAMESPACES",
                "runtime_SQL_App": "NOT RUN", "source_approval": "NOT_GRANTED"}
    files["CAPTURE.json"] = encode(manifest)
    if out.exists():
        ordinary(out)
        require(out.is_dir() and {item.name for item in out.iterdir()} <= set(files),
                "NO_UNREGISTERED_CAPTURE_OUTPUT_MEMBERS")
        # Check all old bytes before attempting any addition.
        for name, raw in files.items():
            if (out / name).exists():
                ordinary(out / name)
                require((out / name).is_file() and (out / name).read_bytes() == raw,
                        "REFUSE_DIFFERENT_EXISTING_CAPTURE")
    else:
        out.mkdir()
        ordinary(out)
    for name, raw in files.items():
        write_exact(out / name, raw)
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--validation", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    result = capture(args.repo, args.candidate, args.validation, args.source_sha, args.output)
    print(json.dumps({"status": result["status"], "source_sha": result["source_sha"],
                      "tracked_file_count": result["tracked_file_count"],
                      "delta_file_count": result["delta_file_count"],
                      "capture": (Path(args.output).absolute() / "CAPTURE.json").as_posix(),
                      "source_approval": result["source_approval"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
