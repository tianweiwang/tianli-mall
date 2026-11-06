"""Positive source and native-facts components; no Docker or archived launcher runs."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("current_normal_source", ROOT / "current_normal_source.py")
AUTHOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUTHOR)
REPO = ROOT.parents[2]


class ImageFormSourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.baseline = AUTHOR._b_baseline()
        cls.inventory = {path: {"path": path, **AUTHOR.pin((REPO / path).read_bytes())}
                         for path in AUTHOR.B_IMAGE_FORM_QA_PINS}

    def test_reviewed_three_file_identity(self):
        self.assertTrue(AUTHOR._b_image_form_sources(self.inventory))
        self.assertEqual(len(self.inventory), 3)

    def test_original_profile_keeps_all_bytes(self):
        original_rows = AUTHOR.decode(self.baseline["current-core-aux/CORE-AUX-AUTHORITY.json"])["core_sources"]
        old_inventory = {row["path"]: row for row in original_rows}
        candidate = dict(self.baseline)
        self.assertIsNone(AUTHOR._b_image_form_mounts(candidate, old_inventory))
        self.assertEqual(candidate, self.baseline)

    def test_only_ui_role_and_its_two_native_consumers_change(self):
        candidate = dict(self.baseline)
        proof = AUTHOR._b_image_form_mounts(candidate, self.inventory)
        self.assertTrue(proof["whole_files_inverse_exact"])
        self.assertEqual({name for name in candidate if candidate[name] != self.baseline[name]},
                         {"candidate/compose.yml", "candidate/ms4-host.py",
                          "candidate/verify-zero-ms4-fulfillment.py"})
        for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
            old = AUTHOR.functions(self.baseline["candidate/" + name])
            new = AUTHOR.functions(candidate["candidate/" + name])
            self.assertEqual(set(old), set(new))
            self.assertEqual([key for key in old if old[key] != new[key]], ["ui_prepare_owned"])
            compile(candidate["candidate/" + name], name, "exec")
        old_compose = self.baseline["candidate/compose.yml"]
        new_compose = candidate["candidate/compose.yml"]
        self.assertEqual(len(new_compose), len(old_compose))
        self.assertEqual(sum(left != right for left, right in zip(old_compose, new_compose)), 1)

    def native_owner(self, raw, writable):
        names = ("UI_PREPARE_ENTRY", "UI_PREPARE", "AFTERSALE_PRIVATE_ROOT", "CUSTOMER_FORM_PRIVATE_ROOT")
        namespace = {name: AUTHOR.constant(raw, name) for name in names}
        namespace.update(expect=self.assertTrue, canonical_host=lambda value: value)
        body = AUTHOR.functions(raw)["ui_prepare_owned"]
        exec(compile(body, "ui-native-owner-component", "exec"), namespace)
        context = {"project": "synthetic-ui-run", "repo_dir": "/synthetic/source",
                   "prep_dir": "/synthetic/prep", "run_dir": "/synthetic/run",
                   "source_tree": {"volume_name": "synthetic-ui-run_source-tree"}}
        binds = [("/run/ms4-prep", context["prep_dir"], False), ("/run/audit", context["run_dir"], True),
                 ("/workspace/crmeb/public/install/crmeb.sql", context["repo_dir"] + "/crmeb/public/install/crmeb.sql", False)]
        volumes = [("/workspace", "source-tree", False), ("/source", "source-tree", False),
                   ("/run/audit-app", "app-secrets", False), ("/run/audit-fixtures", "fixture-secrets", True),
                   (namespace["AFTERSALE_PRIVATE_ROOT"], "aftersale-evidence", True),
                   (namespace["CUSTOMER_FORM_PRIVATE_ROOT"], "customer-form-evidence", writable)]
        keys = {"id", "image", "hostname", "user", "stop_signal", "labels", "mounts", "tmpfs", "read_only",
                "privileged", "network_mode", "status", "command", "entrypoint", "workdir", "restart_count", "pid",
                "exit_code", "networks", "ports", "port_bindings", "cap_drop", "security_options", "started_at", "finished_at", "created_at"}
        facts = dict.fromkeys(keys)
        facts.update(command=[namespace["UI_PREPARE_ENTRY"], "prepare"], user="0:0", stop_signal="SIGTERM",
                     pid=10, exit_code=0, restart_count=0,
                     tmpfs={path: "rw" for path in ("/tmp", "/workspace/crmeb/runtime", "/workspace/crmeb/public")},
                     networks={context["project"] + "_isolation": {"Aliases": ["audit-app"]}},
                     mounts=[{"Type": "bind", "Destination": path, "Source": source, "RW": rw} for path, source, rw in binds]
                            + [{"Type": "volume", "Destination": path, "Name": context["project"] + "_" + name, "RW": rw}
                               for path, name, rw in volumes])
        namespace["ui_prepare_owned"](context, copy.deepcopy(facts))

    def test_old_readonly_and_new_upload_native_facts(self):
        candidate = dict(self.baseline)
        AUTHOR._b_image_form_mounts(candidate, self.inventory)
        for name in ("ms4-host.py", "verify-zero-ms4-fulfillment.py"):
            self.native_owner(self.baseline["candidate/" + name], False)
            self.native_owner(candidate["candidate/" + name], True)

    def test_both_browser_consumers_hold_new_lengths_and_hashes(self):
        rows = AUTHOR.decode(self.baseline["current-core-aux/CORE-AUX-AUTHORITY.json"])["core_sources"]
        changed = [row for row in rows if row["path"] in self.inventory]
        self.assertEqual(len(rows), 3)
        self.assertEqual([row["path"] for row in changed], [AUTHOR.B_QA_ORACLE_PATH])
        for name in ("ms4-ui-business-producer.mjs", "ms4-ui-window-native-bridge.mjs"):
            original = self.baseline["browser-v1/" + name]
            raw = original
            for before in changed:
                after = self.inventory[before["path"]]
                raw, _ = AUTHOR._b_browser_source_literal(raw, before, after)
                self.assertIn(f"bytes:{after['bytes']},sha256:'{after['sha256']}'".encode(), raw)
            for before in reversed(changed):
                raw, _ = AUTHOR._b_browser_source_literal(raw, self.inventory[before["path"]], before)
            self.assertEqual(raw, original)

    def test_php_owner_requires_same_writable_private_root_and_readonly_sources(self):
        raw = (REPO / "help/audit-regressions/isolated/ms4-ui-prepare.php").read_text(encoding="utf-8")
        self.assertIn("M4CF_ROOT=>['customer-form-evidence',true]", raw)
        self.assertIn("count($cuMounts)===1&&in_array('rw',$cuMounts[0],true)&&!in_array('ro',$cuMounts[0],true)", raw)
        self.assertIn("'UI_SOURCE_TREE_ACTUAL_KERNEL_ROOT_READONLY'", raw)
        self.assertIn("$sourceRoots===['/workspace'=>1,'/source'=>1]", raw)

    def test_runtime_successor_uses_original_components_and_keeps_exact_inverses(self):
        candidate = dict(self.baseline)
        AUTHOR._b_image_form_mounts(candidate, self.inventory)
        prior = dict(candidate)
        proof = AUTHOR._b_runtime_successor(self.baseline, candidate)
        self.assertTrue(proof["task2_login_projection"])
        self.assertTrue(proof["whole_gateway_and_compose_inverses_exact"])
        self.assertEqual(candidate["candidate/compose.yml"].replace(b', "--innodb-use-native-aio=OFF"', b""),
                         prior["candidate/compose.yml"])
        self.assertEqual(candidate["candidate/ms4-ui-nginx.conf"].replace(b"|users/user_info/index|", b"|"),
                         prior["candidate/ms4-ui-nginx.conf"])
        # Only exercise the source binder; these are explicit synthetic counts,
        # not material-producer or actual-build acceptance records.
        materials = {"ms4-order.png": self.baseline["candidate/ms4-order.png"],
                     "manifests/ui-public-copy-profile.json": AUTHOR.encode({"public_source_files": list(range(2536))})}
        materials.update({"manifests/ui-" + role + "-dist.json": AUTHOR.encode({"files": list(range(count))})
                          for role, count in (("h5", 337), ("merchant", 30), ("admin", 603))})
        result = AUTHOR._b_bind_browser_materials(candidate, materials, task2_login_projection=proof["task2_login_projection"])
        self.assertTrue(result["whole_producer_and_zero_inverse_exact"])
        self.assertIn(b"TASK2_ACTUAL_LOGOUT_EMPTY_DATA_RESPONSE", candidate["browser-v1/ms4-ui-business-producer.mjs"])
        self.assertIn(b"path==='/adminapi/login/info'", candidate["browser-v1/ms4-ui-business-producer.mjs"])
        self.assertEqual(AUTHOR.functions(candidate["candidate/ms4-host.py"]).keys(),
                         AUTHOR.functions(prior["candidate/ms4-host.py"]).keys())


class UtcLauncherOuterTests(unittest.TestCase):
    """Synthetic successful component records; never a claim about the running A."""

    def successful_fixture(self, folder, materialize):
        root = folder / "normal"
        stage = "materialize" if materialize else "normal-start"

        def write(path, value):
            path.parent.mkdir(parents=True, exist_ok=True)
            raw = value if isinstance(value, bytes) else AUTHOR.encode(value)
            path.write_bytes(raw)
            return {"path": path.as_posix(), **AUTHOR.pin(raw)}

        source_sha = "a" * 40
        binding = write(root / "qualification/CURRENT-BINDING.json", {"normal_root": root.as_posix(), "source_sha": source_sha})
        names = ["launcher/launch.py", "materializer/prepare.py", "validation/run.py", "validation/PINS.json"]
        names += ["synthetic-%02d" % index for index in range(14)]
        members = {name: {key: value for key, value in write(root / name, b"SYNTHETIC_SOURCE_COMPONENT\n").items() if key != "path"}
                   for name in names}
        review = write(folder / "source-review.json", {"schema": 1, "status": "PASS_CURRENT_NORMAL18_SOURCE_REVIEW",
            "source_sha": source_sha, "binding": binding, "members": members,
            "original_business_suffix_exact": True, "source_graph_and_stream_contract_reviewed": True})
        setup = write(root / "validation/materialize-freeze-assets-r1/RESULT.json",
                      {"schema": 2, "source_sha": source_sha, "source_review": review, "freeze_sha256": "b" * 64})
        admission = write(folder / "admission.json", {"status": "CURRENT_NORMAL_RUNTIME_ADMISSION_PASS",
            "at_utc": "2026-11-06T22:00:00.000000+00:00", "source_sha": source_sha,
            "M_result": {key: setup[key] for key in ("path", "sha256")}, "freeze_sha256": "b" * 64, "runtime_started": False})
        common = ["--source-review-file", review["path"], "--source-review-sha256", review["sha256"]]
        executable = (folder / "python.exe").as_posix()
        created = {"pid": 100, "argv": [executable, "-I", "-B", "-X", "utf8",
            (root / "launcher/launch.py").as_posix(), stage, *common],
            "at_utc": "2026-11-06T22:00:01.000000+00:00", "process_local_git_longpaths": True}
        created.update({"scope": "SOURCE_MATERIALIZATION_ONLY_NO_DOCKER_OR_APP_OPERATIONS"} if materialize else {"admission": admission["path"]})
        child_args = common if materialize else ["--root-source-pins-sha256", members["validation/PINS.json"]["sha256"],
                                                "--setup-sha256", setup["sha256"], *common]
        entry = "materializer/prepare.py" if materialize else "validation/run.py"
        child_created = {"pid": 200, "argv": [executable, "-B", "-X", "utf8", (root / entry).as_posix(), *child_args],
                         "started_ns": 1794002402000000000}
        write(root / "launcher" / (stage + ".created.json"), child_created)
        child_streams = {key: write(root / "launcher" / (stage + "." + key), b"SYNTHETIC_SUCCESS\n" if key == "stdout" else b"")
                         for key in ("stdout", "stderr")}
        write(root / "launcher" / (stage + ".terminal.json"),
              {"pid": 200, "native_exit": 0, "wait_returncode": 0, "finished_ns": 1794002403000000000, **child_streams})
        markers = [{"state": "SAME_NATIVE_PROCESS_LIVE", "stage": stage, "pid": 200},
                   {"state": "ACTUAL_STAGE_TERMINAL", "stage": stage, "pid": 200, "native_exit": 0, "wait_returncode": 0}]
        raw_stdout = b"".join((json.dumps(marker) + "\n").encode() for marker in markers)
        outcome = {key: write(folder / "capture" / key, raw_stdout if key == "stdout" else b"") for key in ("stdout", "stderr")}
        terminal = {"pid": 100, "native_exit": 0, "wait_returncode": 0, "at_utc": "2026-11-06T22:00:04.000000+00:00",
                    "stdout_bytes": len(raw_stdout), "stderr_bytes": 0}
        outcome.update(created=write(folder / "capture/created.json", created), terminal=write(folder / "capture/terminal.json", terminal))
        return outcome, review if materialize else None

    def test_successful_m_and_a_use_same_author_and_generated_consumer_without_writes(self):
        with tempfile.TemporaryDirectory(prefix="positive-utc-component-", dir=ROOT) as temp:
            folder = Path(temp)
            for materialize in (True, False):
                scope = folder / ("m" if materialize else "a")
                outcome, review = self.successful_fixture(scope, materialize)
                before = {path: path.read_bytes() for path in scope.rglob("*") if path.is_file()}
                created, terminal = AUTHOR._b_outer(outcome, review)
                self.assertEqual(created["pid"], terminal["pid"])
                runtime = AUTHOR._b_utc_review_runtime(AUTHOR._B_SOURCE_REVIEW)
                namespace = {"Path": Path, "json": json, "stat": AUTHOR.stat, "hashlib": AUTHOR.hashlib, "re": AUTHOR.re,
                             "current_json": lambda pointer: AUTHOR.decode(AUTHOR.read_pointer(pointer)),
                             "current_stream": lambda pointer, *args: AUTHOR.read_pointer(pointer), "current_assert": AUTHOR.require}
                exec(compile(runtime, "synthetic-positive-generated-review", "exec"), namespace)
                namespace["current_b_outer"](outcome, review)
                self.assertEqual({path: path.read_bytes() for path in scope.rglob("*") if path.is_file()}, before)

    def test_old_powershell_dispatch_stays_before_utc_and_all_other_runtime_bytes_survive(self):
        decode = "    created=current_json(outcome['created']);terminal=current_json(outcome['terminal'])\n"
        dispatch = ("    if _b_current_powershell_outer(outcome, created, terminal, materialize_review,\n"
                    "                                  lambda pointer: current_stream(pointer, True), current_assert):\n"
                    "        return\n")
        original = AUTHOR._B_SOURCE_REVIEW.replace(decode, decode + dispatch, 1)
        result = AUTHOR._b_utc_review_runtime(original)
        functions = AUTHOR.functions(result.encode())
        current = functions["current_b_outer"].decode()
        self.assertLess(current.index("if _b_current_powershell_outer"), current.index("if _b_utc_launcher_outer"))
        utc_dispatch = ("    if _b_utc_launcher_outer(outcome, created, terminal, materialize_review,\n"
                        "                             lambda pointer: current_stream(pointer, True), current_assert):\n"
                        "        return\n")
        self.assertEqual(result.splitlines()[0],
                         "def _b_utc_launcher_outer(outcome, created, terminal, materialize_review, reader, check):")
        self.assertEqual(current.replace(utc_dispatch, ""), AUTHOR.functions(original.encode())["current_b_outer"].decode())
        self.assertEqual(functions["current_b_source_review"], AUTHOR.functions(original.encode())["current_b_source_review"])


if __name__ == "__main__":
    unittest.main()
