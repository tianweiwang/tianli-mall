"""Positive source and native-facts components; no Docker or archived launcher runs."""
import copy
import importlib.util
from pathlib import Path
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


if __name__ == "__main__":
    unittest.main()
