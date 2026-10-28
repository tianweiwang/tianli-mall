"""Rebind the existing three-frontend builder to independently reviewed Source.

This finite author does not run a builder, dependency tool, application or Docker.
"""
from __future__ import annotations

import argparse
import copy
import importlib.util
from pathlib import Path, PurePosixPath
import re
from typing import Any

BASELINE = Path("H:/天俪商城/artifacts/project-organization-20261023/integrated-three-frontend-build-source-r5")
BASELINE_PINS = {"path": (BASELINE / "PINS.json").as_posix(), "bytes": 9207,
                 "sha256": "c2add24a609ff5185a9f4f971d0455a5a783cf9b55b4eedb16e599a6efec462d"}
def helpers():
    path = Path(__file__).resolve().with_name("current_normal_source.py")
    spec = importlib.util.spec_from_file_location("current_frontend_source_helpers", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("CURRENT_SOURCE_HELPERS_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def safe_relative(name: str, require) -> None:
    require(type(name) is str and name and not re.search(r"[\x00-\x1f\x7f\\:]", name)
            and not name.startswith("/") and PurePosixPath(name).as_posix() == name
            and all(part not in ("", ".", "..", ".git") for part in name.split("/")),
            "CLOSED_FRONTEND_SOURCE_RELATIVE_PATH")


def derive_source(output: str | Path, authority_pointer: dict[str, Any]):
    """Read fixed evidence and transform bytes in memory; create nothing."""
    h = helpers()
    # The caller supplies a fixed path/size/hash. A new commit still needs its
    # own independently reviewed complete capture; the old review cannot sign it.
    authority, compact = h.reviewed_authority(authority_pointer)
    capture = h.decode(h.read_pointer(authority["capture"]))
    h.require(capture["source_sha"] == authority["source_sha"] and len(capture["source_parents"]) == 1,
              "REVIEWED_SOURCE_AND_ACTUAL_SINGLE_PARENT")
    out = Path(output)
    h.require(out.is_absolute(), "ABSOLUTE_NEW_FRONTEND_SOURCE_OUTPUT")
    h.ordinary(out, missing_leaf=True)
    out = out.resolve()
    for protected in (Path(__file__).resolve().parents[3], BASELINE,
                      Path(authority["candidate_root"]), Path(authority["validation_root"])):
        h.require(not out.is_relative_to(protected), "FRONTEND_SOURCE_OUTPUT_OUTSIDE_PROTECTED_TREES")
    h.require(not out.exists() and not out.is_symlink(), "ONE_NEW_FRONTEND_SOURCE_PACKET_ONLY")
    baseline = h.decode(h.read_pointer(BASELINE_PINS))
    h.require(baseline["schema"] == 1 and baseline["scope"] == "SOURCE_ONLY_NOT_BUILD_OR_RUNTIME_SEAL"
              and len(baseline["files"]) == 53 and len(baseline["directories"]) == 11,
              "FIXED_COMPLETE_R5_SOURCE_PACKET")
    actual_files, actual_directories = set(), set()
    for path in BASELINE.rglob("*"):
        h.ordinary(path)
        relative = path.relative_to(BASELINE).as_posix()
        if path.is_dir():
            actual_directories.add(relative)
        else:
            h.require(path.is_file(), "ORDINARY_BASELINE_SOURCE_MEMBER")
            actual_files.add(relative)
    h.require(actual_files == set(baseline["files"]) | {"PINS.json"}
              and actual_directories == set(baseline["directories"]), "EXACT_PHYSICAL_R5_PACKET_NAMESPACE")
    before = {}
    for name, wanted in baseline["files"].items():
        safe_relative(name, h.require)
        before[name] = h.read_pointer({"path": (BASELINE / name).as_posix(), **wanted})
    qualification = h.decode(before["QUALIFICATION.json"])
    original_qualification = copy.deepcopy(qualification)
    h.require(set(qualification) == {"schema", "scope", "sealed", "head", "parent", "source_repo", "validation_repo",
                                    "full_tree", "source_authority", "dependency_authority", "npm_runtime_authority", "output_parent"}
              and qualification["schema"] == 1
              and qualification["scope"] == "ROOT_SAME_CLEAN_HEAD_THREE_BUILD_QUALIFICATION"
              and qualification["sealed"] is True, "ORIGINAL_BUILDER_INPUT_QUALIFICATION_CONTRACT")
    # Preserve the fixed authorities. Actual builds retain the existing complete
    # dependency-body/namespace checks; this Source author does not run them.
    for pointer in [*qualification["dependency_authority"].values(), qualification["npm_runtime_authority"]]:
        h.read_pointer(pointer)
    captured_rows = [h.decode(line) for line in h.read_pointer(capture["files"]["SOURCE-INVENTORY.jsonl"]).splitlines()]
    h.require(len(captured_rows) == capture["tracked_file_count"] == len(compact)
              and [row["path"] for row in captured_rows] == sorted(compact), "COMPLETE_REVIEWED_GIT_SOURCE_INVENTORY")
    files, directories = [], set()
    for row in captured_rows:
        name = row["path"]
        safe_relative(name, h.require)
        h.require({key: row[key] for key in ("path", "bytes", "sha256")} == compact[name]
                  and row["git_mode"] in ("100644", "100755")
                  and type(row["git_blob"]) is str and re.fullmatch(r"[a-f0-9]{40}", row["git_blob"])
                  and name != "crmeb/.env" and not name.endswith("/install.lock"), "ACTUAL_REVIEWED_FULL_GIT_BLOB_ROW")
        for label, root in (("candidate", authority["candidate_root"]), ("validation", authority["validation_root"])):
            observed = row["checkouts"][label]
            h.require(observed["path"] == (Path(root) / name).as_posix()
                      and {key: observed[key] for key in ("bytes", "sha256")}
                      == {key: row[key] for key in ("bytes", "sha256")}, "BOTH_CAPTURED_TREES_JOIN_SAME_GIT_SOURCE")
        files.append({"path": name, "bytes": row["bytes"], "sha256": row["sha256"],
                      "git_blob1": row["git_blob"], "git_mode": row["git_mode"]})
        directories.update(parent.as_posix() for parent in PurePosixPath(name).parents if parent.as_posix() != ".")
    h.require(len({row["path"].casefold() for row in files} | {name.casefold() for name in directories})
              == len(files) + len(directories), "NO_CURRENT_SOURCE_CASE_OR_FILE_DIRECTORY_COLLISION")
    full_tree = {"head": authority["source_sha"], "files": files, "directories": sorted(directories)}
    after = dict(before)
    after["FULL-TREE.json"] = h.encode(full_tree)
    qualification.update(head=authority["source_sha"], parent=capture["source_parents"][0],
                         source_repo=authority["candidate_root"], validation_repo=authority["validation_root"],
                         full_tree={"path": (out / "FULL-TREE.json").as_posix(), **h.pin(after["FULL-TREE.json"])},
                         source_authority=authority_pointer)
    h.require(all(qualification[key] == original_qualification[key] for key in
                  ("schema", "scope", "sealed", "dependency_authority", "npm_runtime_authority", "output_parent")),
              "ORIGINAL_DEPENDENCY_AND_OUTPUT_CONTRACTS_RETAINED")
    after["QUALIFICATION.json"] = h.encode(qualification)
    qualification_pointer = {"path": (out / "QUALIFICATION.json").as_posix(), **h.pin(after["QUALIFICATION.json"])}
    after["build_common.py"] = h.replace_constant(before["build_common.py"], "QUALIFIED_INPUTS_PIN", qualification_pointer)
    h.require(h.replace_constant(after["build_common.py"], "QUALIFIED_INPUTS_PIN",
                                 h.constant(before["build_common.py"], "QUALIFIED_INPUTS_PIN")) == before["build_common.py"],
              "ONLY_COMMON_QUALIFIED_INPUT_POINTER_CHANGED")
    for name, raw in before.items():
        if name.endswith(".py"):
            h.require(h.functions(raw) == h.functions(after[name]), "ALL_ORIGINAL_PYTHON_FUNCTION_BODIES_EXACT")
            compile(after[name], str(out / name), "exec")
    intro = (
        "# R61 当前三端构建来源候选\n\n"
        f"本代仅将原 r5 完整构建器绑定到独立核过的 `{qualification['head']}`，"
        f"父提交 `{qualification['parent']}`；完整 Git 来源为 {len(files)} 文件、{len(directories)} 目录。"
        "资格、双树与每文件 Git blob 均来自固定 R61 技术依据及其完整捕获；捕获本身不授予批准。\n\n"
        "原离线依赖依据、工具、构建命令、输出父守卫和所有校验函数保留。新包仍须独立 Source 复核，"
        "随后由 Root 按各端实际运行；作者没有执行构建、Docker 或应用。三端构建、dist、四份最终 manifest、"
        "完整 A/post-A、B/UI、设备及发布均未由此取得成功证据。\n\n"
        "当前绑定见 QUALIFICATION.json 与 R61-SOURCE-DERIVATION.json；以下 r5 及更早文字完整保留为历史。\n\n---\n\n"
    ).encode("utf-8")
    after["README.md"] = intro + before["README.md"]
    changed = [name for name in before if before[name] != after[name]]
    h.require(set(changed) == {"FULL-TREE.json", "QUALIFICATION.json", "build_common.py", "README.md"},
              "ONLY_FOUR_CURRENT_SOURCE_BINDING_MEMBERS_CHANGED")
    derivation = {
        "schema": 1, "scope": "R61_CURRENT_THREE_FRONTEND_BUILD_SOURCE_ONLY",
        "status": "SOURCE_CANDIDATE_PENDING_INDEPENDENT_PACKET_REVIEW",
        "baseline_packet": BASELINE_PINS, "technical_authority": authority_pointer,
        "independent_source_review": authority["independent_review"], "capture": authority["capture"],
        "actual_git_inventory": capture["files"]["SOURCE-INVENTORY.jsonl"],
        "source_sha": qualification["head"], "parent": qualification["parent"],
        "source_repo": qualification["source_repo"], "validation_repo": qualification["validation_repo"],
        "full_tree": qualification["full_tree"], "file_count": len(files), "directory_count": len(directories),
        "qualification": qualification_pointer, "changed_existing_members": changed,
        "preserved_existing_members": [name for name in before if name not in changed],
        "common_pointer_whole_file_inverse_exact": True, "all_original_python_function_bodies_exact": True,
        "dependency_authority_preserved": qualification["dependency_authority"],
        "npm_runtime_authority_preserved": qualification["npm_runtime_authority"],
        "output_parent_preserved": qualification["output_parent"],
        "build_Node_npm_Docker_App": "NOT RUN", "A_B_UI_release_acceptance": "NOT_GRANTED",
    }
    after["R61-SOURCE-DERIVATION.json"] = h.encode(derivation)
    pins = {"schema": 1, "scope": baseline["scope"],
            "files": {name: h.pin(raw) for name, raw in sorted(after.items())},
            "directories": baseline["directories"]}
    after["PINS.json"] = h.encode(pins)
    result = {"status": derivation["status"], "source_sha": qualification["head"], "parent": qualification["parent"],
              "source_packet": out.as_posix(), "PINS": {"path": (out / "PINS.json").as_posix(), **h.pin(after["PINS.json"])},
              "tracked_files": len(files), "tracked_directories": len(directories),
              "changed_existing_members": changed, "builds": "NOT RUN"}
    return h, out, after, baseline["directories"], result


def build_source(output: str | Path, authority_pointer: dict[str, Any]) -> dict[str, Any]:
    h, out, after, directories, result = derive_source(output, authority_pointer)
    out.parent.mkdir(parents=True, exist_ok=True)
    h.ordinary(out.parent)
    out.mkdir()  # Exclusive packet root; never adopt an existing output.
    h.write_set(out, after)
    for name in directories:
        (out / name).mkdir(parents=True, exist_ok=True)
    h.require({path.relative_to(out).as_posix() for path in out.rglob("*") if path.is_dir()}
              == set(directories), "ORIGINAL_ELEVEN_PACKET_DIRECTORIES_RETAINED")
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True)
    parser.add_argument("--authority-file", required=True)
    parser.add_argument("--authority-sha256", required=True)
    args = parser.parse_args()
    h = helpers()
    h.require(re.fullmatch(r"[a-f0-9]{64}", args.authority_sha256) is not None, "EXPLICIT_TECHNICAL_AUTHORITY_SHA256")
    path = Path(args.authority_file)
    raw = h.stable_read(path)
    pointer = {"path": path.as_posix(), "bytes": len(raw), "sha256": args.authority_sha256}
    h.require(h.pin(raw)["sha256"] == args.authority_sha256, "FIXED_TECHNICAL_AUTHORITY_BYTES")
    result = build_source(args.output, pointer)
    print(h.encode(result).decode("utf-8"), end="")


if __name__ == "__main__":
    main()
