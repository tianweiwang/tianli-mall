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
PUBLIC_ORIGIN = BASELINE.parent.as_posix()
PUBLIC_AUTHORITIES = {
    "admin": {"path": PUBLIC_ORIGIN + "/integrated-continuation-20261026-r1/dependency-preflight-r2/admin.json",
              "bytes": 11566525, "sha256": "7535000a19b35dc4683a3ec5c6da40ccf857c89198d3b0e074d0ab727145eb7d"},
    "merchant": {"path": PUBLIC_ORIGIN + "/integrated-continuation-20261026-r1/dependency-preflight-r2/merchant.json",
                 "bytes": 4271550, "sha256": "faea7f5e40b5c0f4e172419675b35b7d85f5779a57cc86ab4c414940f946889b"},
    "h5": {"path": PUBLIC_ORIGIN + "/integrated-continuation-20261026-r1/CURRENT-H5-DEPENDENCY-AUTHORITY.json",
           "bytes": 5704609, "sha256": "dacec90c49dadcdd760e0f81facc1cf1e5668595219e33b7af1592d750de140c"},
    "npm": {"path": PUBLIC_ORIGIN + "/integrated-continuation-20261026-r1/dependency-preflight-r2/npm.json",
            "bytes": 337444, "sha256": "559ee2b39970215ac32b56550b4f96b080d6e714e64b0f745652f021f2b9d675"},
}
EMPTY_BASELINE_DIRECTORY = "h5-tools/compiler-overlays/vue-cli-plugin-uni-optimize/.tmp"


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


def public_build_sources(h):
    """Resolve only the fixed 54 source leaves and four public metadata inputs."""
    tool_root = Path(__file__).resolve().parent
    manifest = h.decode(h.stable_read(tool_root / "source-manifest.json"))
    h.require(manifest["schema"] == 2 and manifest["status"] == "SOURCE_ARCHIVE_RUNTIME_NOT_READY"
              and manifest["file_count"] == len(manifest["files"]), "FRONTEND_PUBLIC_ARCHIVE_INDEX")
    indexed = {row["path"]: row for row in manifest["files"]}
    h.require(len(indexed) == len(manifest["files"]), "FRONTEND_PUBLIC_UNIQUE_ARCHIVE_PATHS")

    def archived(origin):
        h.require(type(origin) is dict and set(origin) == {"path", "bytes", "sha256"},
                  "FRONTEND_FIXED_PUBLIC_DESCRIPTOR")
        logical = Path(origin["path"])
        h.require(logical.is_absolute() and logical.as_posix() == origin["path"]
                  and logical.is_relative_to(Path(PUBLIC_ORIGIN)), "FRONTEND_FIXED_PUBLIC_ORIGIN")
        relative = logical.relative_to(Path(PUBLIC_ORIGIN)).as_posix()
        safe_relative(relative, h.require)
        name = "sources/" + relative
        row = indexed[name]
        h.require(row["origin_root"] == PUBLIC_ORIGIN and row["origin_relative_path"] == relative
                  and row["package"] == PurePosixPath(relative).parts[0]
                  and {key: row[key] for key in ("bytes", "sha256")}
                  == {key: origin[key] for key in ("bytes", "sha256")},
                  "FRONTEND_PUBLIC_ORIGINAL_AND_ARCHIVED_PIN_JOIN")
        actual = tool_root / name
        h.ordinary(actual)
        h.require(actual.resolve(strict=True).is_relative_to(tool_root / "sources"),
                  "FRONTEND_PUBLIC_INSIDE_ARCHIVE")
        return {"path": actual.as_posix(), **{key: origin[key] for key in ("bytes", "sha256")}}

    baseline_pointer = archived(BASELINE_PINS)
    baseline = h.decode(h.read_pointer(baseline_pointer))
    h.require(baseline["schema"] == 1 and baseline["scope"] == "SOURCE_ONLY_NOT_BUILD_OR_RUNTIME_SEAL"
              and len(baseline["files"]) == 53 and len(baseline["directories"]) == 11,
              "FIXED_COMPLETE_R5_SOURCE_PACKET")
    required = ["sources/" + BASELINE.name + "/PINS.json"]
    for name, wanted in baseline["files"].items():
        safe_relative(name, h.require)
        archived({"path": (BASELINE / name).as_posix(), **wanted})
        required.append("sources/" + BASELINE.name + "/" + name)
    authority_pointers = {name: archived(origin) for name, origin in PUBLIC_AUTHORITIES.items()}
    authority_rows = {name: {**row, "path": "sources/" + Path(row["path"]).relative_to(Path(PUBLIC_ORIGIN)).as_posix()}
                      for name, row in PUBLIC_AUTHORITIES.items()}
    required += [row["path"] for row in authority_rows.values()]
    expected = {"schema": 1, "scope": "FIXED_THREE_FRONTEND_PUBLIC_SOURCE_INPUTS", "origin_root": PUBLIC_ORIGIN,
                "baseline_pin": {**BASELINE_PINS, "path": required[0]}, "authority_inputs": authority_rows,
                "required": required, "declared_empty_directories": [EMPTY_BASELINE_DIRECTORY]}
    h.require(len(required) == len(set(required)) == 58
              and manifest.get("frontend_build_public_inputs") == expected,
              "EXACT_FRONTEND_PUBLIC_FIFTY_EIGHT_ALLOWLIST")
    return Path(baseline_pointer["path"]).parent, baseline, baseline_pointer, authority_pointers


def windows_copy_four(raw: bytes, h) -> bytes:
    """Replace only the existing copy loop; retain every per-file check and its inverse."""
    original = (
        b"    for name, expected in before['files'].items():\n"
        b"        raw = read(cache / 'node_modules' / name)\n"
        b"        require(pin(raw) == expected, 'EVERY_ORIGINAL_MODULE_BEFORE_COPY')\n"
        b"        create(destination / name, raw)\n"
        b"        require((destination / name).stat().st_nlink == 1, 'NO_LINKED_MODULE_REUSE')\n"
    )
    replacement = (
        b"    def copy_one(item):\n"
        b"        name, expected = item\n"
        + original.split(b"\n", 1)[1]
        + b"    from concurrent.futures import ThreadPoolExecutor\n"
        b"    executor = ThreadPoolExecutor(max_workers=4)\n"
        b"    try:\n"
        b"        for _ in executor.map(copy_one, before['files'].items(), buffersize=4):\n"
        b"            pass\n"
        b"    finally:\n"
        b"        executor.shutdown(wait=True, cancel_futures=True)\n"
    )
    result = h.once(raw, original, replacement)
    h.require(h.once(result, replacement, original) == raw, "WINDOWS_COPY_WHOLE_FILE_INVERSE_EXACT")
    before_functions, after_functions = h.functions(raw), h.functions(result)
    h.require(set(before_functions) == set(after_functions)
              and {name for name in before_functions if before_functions[name] != after_functions[name]} == {"run"},
              "ONLY_WINDOWS_RUN_COPY_SCHEDULING_CHANGED")
    return result


def windows_lx_alias_attributes(raw: bytes, h) -> bytes:
    """Retain raw observations; compare only the stable attributes of four fixed LX aliases."""
    changes = [
        (b"    from build_common import native9, regular, relative\n",
         b"    from build_common import native9, regular, relative, put\n"),
        (b"    files, directories, links, alias_states, directory_states = {}, [], {}, {}, {}\n",
         b"    files, directories, links, alias_states, directory_states = {}, [], {}, {}, {}\n"
         b"    require(os.name == 'nt', 'ONLY_WINDOWS_FIXED_LX_ALIAS_ATTRIBUTES')\n"
         b"    raw_alias_attributes = {}\n"),
        (b"                    and getattr(facts, 'st_file_attributes', 0) & 1024\n",
         b"                    and getattr(facts, 'st_file_attributes', None) in (1056, 263200)\n"),
        (b"                    and getattr(after, 'st_file_attributes', None) == getattr(facts, 'st_file_attributes', None),\n",
         b"                    and getattr(after, 'st_file_attributes', None) in (1056, 263200),\n"),
        (b"            links[name] = expected_links[name]\n",
         b"            raw_alias_attributes[name] = {'before': facts.st_file_attributes, 'after': after.st_file_attributes,\n"
         b"                'native9': before, 'birthtime_ns': birth, 'reparse_tag': facts.st_reparse_tag, 'reparse_query': pin(query)}\n"
         b"            links[name] = expected_links[name]\n"),
        (b"                'reparse_tag': facts.st_reparse_tag, 'file_attributes': facts.st_file_attributes,\n",
         b"                'reparse_tag': facts.st_reparse_tag, 'file_attributes': 1056,\n"),
        (b"    return {'tree': value, 'native9': identities(STYLUS, value['files']),\n",
         b"    put(context['evidence'] / ('h5-stylus-' + phase + '-alias-attributes.json'),\n"
         b"        {'schema': 1, 'scope': 'FOUR_FIXED_WINDOWS_LX_ALIAS_RAW_ATTRIBUTES', 'root': STYLUS.as_posix(), 'phase': phase, 'aliases': raw_alias_attributes})\n"
         b"    return {'tree': value, 'native9': identities(STYLUS, value['files']),\n"),
    ]
    result = raw
    for before, after in changes:
        result = h.once(result, before, after)
    inverse = result
    for before, after in reversed(changes):
        inverse = h.once(inverse, after, before)
    h.require(inverse == raw, "LX_ALIAS_WHOLE_FILE_INVERSE_EXACT")
    original, updated = h.functions(raw), h.functions(result)
    h.require(set(original) == set(updated)
              and {name for name in original if original[name] != updated[name]} == {"stylus_tree"},
              "ONLY_FOUR_LX_ALIAS_ATTRIBUTE_OBSERVATION_CHANGED")
    return result


def derive_source(output: str | Path, authority_pointer: dict[str, Any], windows_copy_workers: int = 1,
                  windows_lx_alias_attributes_enabled: bool = False):
    """Read fixed evidence and transform bytes in memory; create nothing."""
    h = helpers()
    h.require(type(windows_copy_workers) is int and windows_copy_workers in (1, 4), "WINDOWS_COPY_WORKERS_ONE_OR_FOUR")
    h.require(type(windows_lx_alias_attributes_enabled) is bool, "EXPLICIT_WINDOWS_LX_ALIAS_ATTRIBUTE_OPTION")
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
    baseline_root, baseline, baseline_pointer, authority_pointers = public_build_sources(h)
    actual_files, actual_directories = set(), set()
    for path in baseline_root.rglob("*"):
        h.ordinary(path)
        relative = path.relative_to(baseline_root).as_posix()
        if path.is_dir():
            actual_directories.add(relative)
        else:
            h.require(path.is_file(), "ORDINARY_BASELINE_SOURCE_MEMBER")
            actual_files.add(relative)
    file_parents = {parent.as_posix() for name in baseline["files"] for parent in PurePosixPath(name).parents
                    if parent.as_posix() != "."}
    h.require(set(baseline["directories"]) - file_parents == {EMPTY_BASELINE_DIRECTORY}
              and actual_files == set(baseline["files"]) | {"PINS.json"}
              and actual_directories == file_parents, "EXACT_ARCHIVED_R5_FILES_AND_DECLARED_EMPTY_DIRECTORY")
    before = {}
    for name, wanted in baseline["files"].items():
        safe_relative(name, h.require)
        before[name] = h.read_pointer({"path": (baseline_root / name).as_posix(), **wanted})
    qualification = h.decode(before["QUALIFICATION.json"])
    original_qualification = copy.deepcopy(qualification)
    h.require(set(qualification) == {"schema", "scope", "sealed", "head", "parent", "source_repo", "validation_repo",
                                    "full_tree", "source_authority", "dependency_authority", "npm_runtime_authority", "output_parent"}
              and qualification["schema"] == 1
              and qualification["scope"] == "ROOT_SAME_CLEAN_HEAD_THREE_BUILD_QUALIFICATION"
              and qualification["sealed"] is True, "ORIGINAL_BUILDER_INPUT_QUALIFICATION_CONTRACT")
    # Preserve the fixed authorities. Actual builds retain the existing complete
    # dependency-body/namespace checks; this Source author does not run them.
    h.require(qualification["dependency_authority"] == {name: PUBLIC_AUTHORITIES[name] for name in ("admin", "merchant", "h5")}
              and qualification["npm_runtime_authority"] == PUBLIC_AUTHORITIES["npm"],
              "EXACT_ORIGINAL_FOUR_PUBLIC_METADATA_AUTHORITIES")
    for pointer in authority_pointers.values():
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
    before_pointer_mapping = copy.deepcopy(qualification)
    qualification["dependency_authority"] = {name: authority_pointers[name] for name in ("admin", "merchant", "h5")}
    qualification["npm_runtime_authority"] = authority_pointers["npm"]
    inverse_pointer_mapping = copy.deepcopy(qualification)
    inverse_pointer_mapping["dependency_authority"] = original_qualification["dependency_authority"]
    inverse_pointer_mapping["npm_runtime_authority"] = original_qualification["npm_runtime_authority"]
    h.require(inverse_pointer_mapping == before_pointer_mapping
              and all({key: PUBLIC_AUTHORITIES[name][key] for key in ("bytes", "sha256")}
                      == {key: authority_pointers[name][key] for key in ("bytes", "sha256")}
                      and PUBLIC_AUTHORITIES[name]["path"] != authority_pointers[name]["path"]
                      for name in PUBLIC_AUTHORITIES), "ONLY_FOUR_PUBLIC_METADATA_PATHS_REBOUND_WITH_COMPLETE_INVERSE")
    h.require(all(qualification[key] == original_qualification[key] for key in
                  ("schema", "scope", "sealed", "output_parent")), "ORIGINAL_QUALIFICATION_AND_OUTPUT_CONTRACTS_RETAINED")
    after["QUALIFICATION.json"] = h.encode(qualification)
    qualification_pointer = {"path": (out / "QUALIFICATION.json").as_posix(), **h.pin(after["QUALIFICATION.json"])}
    after["build_common.py"] = h.replace_constant(before["build_common.py"], "QUALIFIED_INPUTS_PIN", qualification_pointer)
    h.require(h.replace_constant(after["build_common.py"], "QUALIFIED_INPUTS_PIN",
                                 h.constant(before["build_common.py"], "QUALIFIED_INPUTS_PIN")) == before["build_common.py"],
              "ONLY_COMMON_QUALIFIED_INPUT_POINTER_CHANGED")
    if windows_copy_workers == 4:
        after["windows_build.py"] = windows_copy_four(before["windows_build.py"], h)
    if windows_lx_alias_attributes_enabled:
        after["h5_build.py"] = windows_lx_alias_attributes(before["h5_build.py"], h)
    for name, raw in before.items():
        if name.endswith(".py"):
            if name == "windows_build.py" and windows_copy_workers == 4:
                original_functions, updated_functions = h.functions(raw), h.functions(after[name])
                h.require(set(original_functions) == set(updated_functions)
                          and all(original_functions[key] == updated_functions[key] for key in original_functions if key != "run"),
                          "ALL_OTHER_WINDOWS_FUNCTION_BODIES_EXACT")
            elif name == "h5_build.py" and windows_lx_alias_attributes_enabled:
                original_functions, updated_functions = h.functions(raw), h.functions(after[name])
                h.require(set(original_functions) == set(updated_functions)
                          and all(original_functions[key] == updated_functions[key] for key in original_functions if key != "stylus_tree"),
                          "ALL_OTHER_H5_FUNCTION_BODIES_EXACT")
            else:
                h.require(h.functions(raw) == h.functions(after[name]), "ALL_ORIGINAL_PYTHON_FUNCTION_BODIES_EXACT")
            compile(after[name], str(out / name), "exec")
    intro = (
        "# R61 当前三端构建来源候选\n\n"
        f"本代仅将原 r5 完整构建器绑定到独立核过的 `{qualification['head']}`，"
        f"父提交 `{qualification['parent']}`；完整 Git 来源为 {len(files)} 文件、{len(directories)} 目录。"
        "资格、双树与每文件 Git blob 均来自固定 R61 技术依据及其完整捕获；捕获本身不授予批准。\n\n"
        "原离线依赖依据字节、工具、构建命令、输出父守卫和所有校验函数保留；四份公开清单指针仅重绑定归档位置。新包仍须独立 Source 复核，"
        "随后由 Root 按各端实际运行；作者没有执行构建、Docker 或应用。三端构建、dist、四份最终 manifest、"
        "完整 A/post-A、B/UI、设备及发布均未由此取得成功证据。\n\n"
        "当前绑定见 QUALIFICATION.json 与 R61-SOURCE-DERIVATION.json；以下 r5 及更早文字完整保留为历史。\n\n---\n\n"
    ).encode("utf-8")
    if windows_copy_workers == 4:
        intro = h.once(intro, "本代仅将原 r5 完整构建器绑定到".encode("utf-8"),
                       "本代将原 r5 完整构建器绑定到".encode("utf-8"))
        intro = h.once(intro, "当前绑定见 QUALIFICATION.json".encode("utf-8"), (
            "本代另显式选择 Windows 依赖复制 workers=4：只调整原 run 内的复制调度并增加局部 copy_one，"
            "由标准库线程池有界提交。每文件原读取、哈希、独占物理写入、完整读回和单链接检查，以及"
            "复制前后整树、文件身份、EOF 与全部 Node/npm 命令保持。异常时取消未启动任务并等待在途线程退出，"
            "不再保证失败残留是严格串行前缀；不重试、不删除现场。其余原函数保持，H5 未改变，提速尚未实测。\n\n"
            "当前绑定见 QUALIFICATION.json"
        ).encode("utf-8"))
    after["README.md"] = intro + before["README.md"]
    if windows_lx_alias_attributes_enabled:
        after["README.md"] = (
            "本代显式启用四固定Windows LX链接属性适配：完整身份、链接全文、工具与编译命令保持；"
            "仅允许实测属性1056/263200，稳定属性按1056保全，前后raw观察均保存于evidence。"
            "此段取代下文历史候选的H5未改变描述；实际构建仍待运行。\n\n"
        ).encode("utf-8") + after["README.md"]
    changed = [name for name in before if before[name] != after[name]]
    expected_changed = {"FULL-TREE.json", "QUALIFICATION.json", "build_common.py", "README.md"}
    if windows_copy_workers == 4:
        expected_changed.add("windows_build.py")
    if windows_lx_alias_attributes_enabled:
        expected_changed.add("h5_build.py")
    h.require(set(changed) == expected_changed,
              "EXACT_CURRENT_SOURCE_AND_EXPLICIT_BUILDER_ADAPTATIONS")
    derivation = {
        "schema": 1, "scope": "R61_CURRENT_THREE_FRONTEND_BUILD_SOURCE_ONLY",
        "status": "SOURCE_CANDIDATE_PENDING_INDEPENDENT_PACKET_REVIEW",
        "baseline_packet": BASELINE_PINS, "technical_authority": authority_pointer,
        "baseline_packet_source": baseline_pointer,
        "independent_source_review": authority["independent_review"], "capture": authority["capture"],
        "actual_git_inventory": capture["files"]["SOURCE-INVENTORY.jsonl"],
        "source_sha": qualification["head"], "parent": qualification["parent"],
        "source_repo": qualification["source_repo"], "validation_repo": qualification["validation_repo"],
        "full_tree": qualification["full_tree"], "file_count": len(files), "directory_count": len(directories),
        "qualification": qualification_pointer, "changed_existing_members": changed,
        "preserved_existing_members": [name for name in before if name not in changed],
        "common_pointer_whole_file_inverse_exact": True,
        "all_original_python_function_bodies_exact": windows_copy_workers == 1 and not windows_lx_alias_attributes_enabled,
        # Existing copy consumers join these fields to the actual qualification.
        # Preserved means authority bytes/SHA and logical origin, not path identity.
        "dependency_authority_preserved": qualification["dependency_authority"],
        "npm_runtime_authority_preserved": qualification["npm_runtime_authority"],
        "public_input_pointer_rebinding": {"schema": 1, "scope": "FOUR_FIXED_PUBLIC_METADATA_PATHS_ONLY",
            "original_logical_inputs": copy.deepcopy(PUBLIC_AUTHORITIES), "actual_archive_inputs": authority_pointers,
            "paths_changed": True, "authority_bytes_sha256_preserved": True,
            "qualification_four_path_inverse_equal": True,
            "legacy_preserved_fields_mean": "actual qualification pointers; original authority bytes/SHA and logical origins preserved, paths explicitly rebound"},
        "output_parent_preserved": qualification["output_parent"],
        "build_Node_npm_Docker_App": "NOT RUN", "A_B_UI_release_acceptance": "NOT_GRANTED",
    }
    if windows_copy_workers == 4:
        derivation.update(windows_copy_workers=4, windows_copy_buffersize=4,
                          windows_copy_whole_file_inverse_exact=True,
                          python_function_changes={"windows_build.py": ["run"]},
                          added_local_functions={"windows_build.py": ["run.copy_one"]},
                          all_other_original_python_function_bodies_exact=True,
                          copy_failure_order="NOT_SERIAL_PREFIX; queued work cancelled and running workers joined before failure",
                          measured_copy_speedup="NOT RUN")
    if windows_lx_alias_attributes_enabled:
        derivation.update(windows_lx_alias_attributes=True, lx_alias_whole_file_inverse_exact=True,
                          raw_attribute_values=[1056, 263200], stable_alias_attributes=1056,
                          raw_attribute_receipts=["h5-stylus-before-alias-attributes.json", "h5-stylus-after-alias-attributes.json"])
        derivation.setdefault("python_function_changes", {})["h5_build.py"] = ["stylus_tree"]
        derivation["all_other_original_python_function_bodies_exact"] = True
    after["R61-SOURCE-DERIVATION.json"] = h.encode(derivation)
    pins = {"schema": 1, "scope": baseline["scope"],
            "files": {name: h.pin(raw) for name, raw in sorted(after.items())},
            "directories": baseline["directories"]}
    after["PINS.json"] = h.encode(pins)
    result = {"status": derivation["status"], "source_sha": qualification["head"], "parent": qualification["parent"],
              "source_packet": out.as_posix(), "PINS": {"path": (out / "PINS.json").as_posix(), **h.pin(after["PINS.json"])},
              "tracked_files": len(files), "tracked_directories": len(directories),
              "changed_existing_members": changed, "builds": "NOT RUN"}
    if windows_copy_workers == 4:
        result["windows_copy_workers"] = 4
    return h, out, after, baseline["directories"], result


def build_source(output: str | Path, authority_pointer: dict[str, Any], windows_copy_workers: int = 1,
                 windows_lx_alias_attributes_enabled: bool = False) -> dict[str, Any]:
    h, out, after, directories, result = derive_source(output, authority_pointer, windows_copy_workers, windows_lx_alias_attributes_enabled)
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
    parser.add_argument("--windows-copy-workers", type=int, choices=(1, 4), default=1,
                        help="1 retains the original bytes; 4 derives bounded Windows dependency copy only")
    parser.add_argument("--windows-lx-alias-attributes", action="store_true",
                        help="Record both raw values of the four fixed Windows LX aliases; retain all stable checks")
    args = parser.parse_args()
    h = helpers()
    h.require(re.fullmatch(r"[a-f0-9]{64}", args.authority_sha256) is not None, "EXPLICIT_TECHNICAL_AUTHORITY_SHA256")
    path = Path(args.authority_file)
    raw = h.stable_read(path)
    pointer = {"path": path.as_posix(), "bytes": len(raw), "sha256": args.authority_sha256}
    h.require(h.pin(raw)["sha256"] == args.authority_sha256, "FIXED_TECHNICAL_AUTHORITY_BYTES")
    result = build_source(args.output, pointer, args.windows_copy_workers, args.windows_lx_alias_attributes)
    print(h.encode(result).decode("utf-8"), end="")


if __name__ == "__main__":
    main()
