"""Read saved positive evidence only; never run an archived actor or Docker."""
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("current_normal_source", ROOT / "current_normal_source.py")
AUTHOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUTHOR)
R101 = Path("H:/天俪商城/artifacts/multi-merchant-final-normal-20261104-r101")
R100 = Path("H:/天俪商城/artifacts/multi-merchant-final-normal-20261103-r100")
CONTINUATION = R101 / "normal-a-5b6-continuation-20261106-r1"
RUN = Path("H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261106091945df41c7")


def pointer(path):
    raw = path.read_bytes()
    return {"path": path.as_posix(), "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}


def actual_segment_inputs():
    """Expose the actual public inputs without writing a new source/input packet."""
    return {
        "scope": "INTERRUPTED_A_WITH_INDEPENDENT_COMPLETION", "original_session": 20540,
        "context": pointer(RUN / "actual-run-context.json"),
        "worker_close": pointer(RUN / "evidence/ms4-worker-normal-close-result.json"),
        "completion": {
            "kind": "ORIGINAL_NORMAL_CONTINUATION",
            "source": pointer(CONTINUATION / "continue.py"),
            "source_review": pointer(R101 / "normal-a-5b6-continuation-source-review-20261106-r1.json"),
            "result": pointer(CONTINUATION / "RESULT.json"),
            "admission": pointer(CONTINUATION / "ADMISSION.json"),
            "supervisor": pointer(CONTINUATION / "supervisor.json"),
            "independent_zero": pointer(RUN / "evidence/ms4-worker-normal-independent-zero.json"),
            "outer": {key: pointer(CONTINUATION / name) for key, name in (
                ("created", "created.json"), ("terminal", "terminal.json"), ("stdout", "stdout"), ("stderr", "stderr"))},
        },
    }


class InterruptedSegmentsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.a = actual_segment_inputs()
        cls.authority = pointer(R101 / "current-source-5b6/CURRENT-SOURCE-TECHNICAL-AUTHORITY.json")
        cls.context = json.loads(Path(cls.a["context"]["path"]).read_bytes())
        cls.namespace = AUTHOR._interruption_namespace()
        with patch("subprocess.Popen", side_effect=AssertionError("No process execution")) as spawn, \
                patch("pathlib.Path.write_bytes", side_effect=AssertionError("No writes")), \
                patch("pathlib.Path.mkdir", side_effect=AssertionError("No directories")):
            cls.proof = AUTHOR._interrupted_completion(
                cls.a, cls.authority, R101 / "normal-source-5b6", cls.context["freeze_sha256"])
            spawn.assert_not_called()

    def test_actual_complete_prefix_and_same_run_continuation(self):
        self.assertEqual(self.proof["coordinator_complete"]["count"], 136)
        self.assertEqual(self.proof["fresh_native_count"], 70)
        self.assertEqual(self.proof["incomplete_sequence"], 137)
        zero_script = Path(self.context["prep_dir"]) / "verify-zero-ms4-fulfillment.py"
        self.assertEqual(self.proof["zero_source_sha256"], pointer(zero_script)["sha256"])
        self.assertNotEqual(self.proof["zero_source_sha256"], self.a["completion"]["source"]["sha256"])
        self.assertEqual(self.proof["result"]["original_outer_state"], "INTERRUPTED_EXIT_UNKNOWN")
        self.assertIs(self.proof["result"]["original_full_a_acceptance"], False)
        archive = RUN / "root-orchestration"
        self.assertFalse((archive / "RESULT.json").exists())
        for suffix in ("terminal.json", "stdout", "stderr"):
            self.assertFalse((archive / ("137-observe-register-aftersale-review-normal." + suffix)).exists())

    def test_public_closure_contains_original_prepare_and_every_continuation_file(self):
        public = {row["path"]: row for row in self.proof["public_rows"]}
        first = R101 / "normal-source-5b6/validation/native-normal-start-assets-r1"
        for suffix in ("created.json", "terminal.json", "stdout", "stderr"):
            row = pointer(first / ("001-prepare." + suffix))
            self.assertEqual(public[row["path"]], row)
        admission = json.loads((CONTINUATION / "ADMISSION.json").read_bytes())
        self.assertEqual(len(admission["source_original_files"]), 542)
        for path, expected in admission["source_original_files"].items():
            self.assertEqual(public[Path(path).as_posix()]["sha256"], expected)
        for path in CONTINUATION.rglob("*"):
            if path.is_file():
                row = pointer(path)
                self.assertEqual(public[row["path"]], row)
        self.assertEqual(self.proof["public_closure_counts"]["new_roots_unique_files"], 288)
        self.assertEqual(len(public), 864)

    def test_legacy_outer_and_shared_capture_reader_accept_actual_old_records(self):
        fixed = json.loads((R100 / "post-a-inputs-r1.json").read_bytes())
        old = fixed["A"]
        context = json.loads(Path(old["context"]["path"]).read_bytes())
        close_raw = Path(old["worker_close"]["path"]).read_bytes()
        normal = Path(fixed["normal"]["root"])
        read = self.namespace["current_completion_file"]
        cli_pins = {name: pointer(Path(context["prep_dir"]) / name)["sha256"] for name in (
            "ms4-host.py", "normal-close-ms4-fulfillment.py", "verify-zero-ms4-fulfillment.py")}
        with patch("subprocess.Popen", side_effect=AssertionError("No process execution")) as spawn:
            self.namespace["current_completion_outer"](old)
            commands = []
            for archive in (normal / "validation/native-normal-start-assets-r1", Path(context["run_dir"]) / "root-orchestration"):
                for path in sorted(archive.glob("*.created.json")):
                    stem = archive / path.name[:-len(".created.json")]
                    if not Path(str(stem) + ".terminal.json").exists():
                        continue
                    row = self.namespace["current_normal_capture"](stem, read)
                    self.namespace["current_normal_capture_context"](row, context, normal, cli_pins, close_raw, b"{}")
                    commands.append(row)
            spawn.assert_not_called()
        self.assertEqual(len(commands), 205)
        self.assertEqual([row["sequence"] for row in commands], list(range(1, 206)))

    def test_ordinary_gate_and_legacy_validation_body_are_unchanged(self):
        source = (ROOT / "current_normal_source.py").read_text(encoding="utf-8")
        definitions = {node.name: node for node in ast.parse(source).body if isinstance(node, ast.FunctionDef)}
        expected = {
            "_completed_normal_a": "a89b338491b38e1839f8373bf9b19d8ef3a81e59f53606ff3bef548e22d29ab7",
            "_b_normal_consumer": "5a852aa798cd9f587ca45bca7e7f998ab7bdbbae22bfa918a3d9400f7274e42c",
            "_interrupted_core": "cebdae946f3f07d101246ede9a4e096053169f32a171e110ee1c2c3b4d3e7de2",
            "_interrupted_qualification": "c51123e96f75d79819c08e777eb6ab6f6af2178f326baf8ef713b6a1a422118b",
        }
        for name, digest in expected.items():
            raw = ast.get_source_segment(source, definitions[name]).encode("utf-8")
            self.assertEqual(hashlib.sha256(raw).hexdigest(), digest)
        legacy = {node.name: node for node in ast.parse(AUTHOR._INTERRUPTED_A_CONSUMER).body if isinstance(node, ast.FunctionDef)}
        for name, digest in (
                ("current_completion_outer", "1c927dc50be470dd763b6741fecc3354f1a05b155adcee1e56da011ef12b5df7"),
                ("current_interrupted_a", "738fd2ed3ec35b4c1ab797d13e9b592d8a5e77181a6213ee727161f3f02e81ac")):
            node = legacy[name.replace("current_", "current_legacy_", 1)]
            node.name = name
            self.assertEqual(hashlib.sha256(ast.dump(node, include_attributes=False).encode()).hexdigest(), digest)

    def test_both_future_consumers_use_the_same_proof_and_roundtrip(self):
        baseline = ROOT / "sources/integrated-normal-and-b-source-tree-source-r3/b/root-source"
        before = {name: (baseline / name).read_bytes() for name in (
            "root-ui-materialize-freeze.py", "root-ui-start-to-ready.py")}
        after = {name: AUTHOR._b_interrupted_root(raw, name) for name, raw in before.items()}
        retirement = AUTHOR._b_interrupted_retirement(before["root-ui-materialize-freeze.py"])
        review = AUTHOR._b_interrupted_review_runtime(AUTHOR._B_SOURCE_REVIEW)
        for body in (*after.values(), retirement, review):
            compile(body, "derived-interrupted-consumer", "exec")
        self.assertIn("proof['zero_source_sha256']", retirement)
        self.assertIn("proof['incomplete_sequence']", retirement)
        self.assertIn("CURRENT_ORIGINAL_COMPLETED_COUNT", review)
        self.assertIn("CURRENT_ORIGINAL_INCOMPLETE_SEQUENCE", review)
        self.assertIn(b"==CURRENT_ORIGINAL_COMPLETED_COUNT", after["root-ui-start-to-ready.py"])
        self.assertIn(b"==CURRENT_ORIGINAL_INCOMPLETE_SEQUENCE", after["root-ui-start-to-ready.py"])
        self.assertIn("current_interrupted_a(CURRENT_INTERRUPTED_A", retirement)
        self.assertIn("current_interrupted_a(CURRENT_INTERRUPTED_A", review)
        names = tuple(before)
        self.assertEqual(AUTHOR.apply_patch(before, AUTHOR.make_patch(before, after, names), names), after)
        self.assertEqual(AUTHOR.apply_patch(after, AUTHOR.make_patch(after, before, names), names), before)

    def test_original_six_key_public_union_prefix_remains_exact(self):
        source = (ROOT / "sources/integrated-normal-and-b-source-tree-source-r3/b/root-source/root-ui-materialize-freeze.py").read_bytes()
        original = AUTHOR._source_function(source, "current_post_a_history")
        marker = "    for item,data in [(OLD_RESULT,final_raw),(OLD_ROOT/'evidence/ms4-worker-normal-close-result.json',close_raw),"
        prefix = original[:original.index(marker)].replace(
            "def current_post_a_history(args,context,final_raw,close_raw,zero_raw):",
            "def current_interrupted_post_a_history(args,context):", 1)
        current = AUTHOR._interrupted_post_a_consumer(source)
        self.assertTrue(current.startswith(prefix))
        self.assertIn("CURRENT_COMPLETION_PUBLIC_ROWS", current)
        self.assertIn("'original_A_status':'UNKNOWN/INTERRUPTED'", current)


if __name__ == "__main__":
    unittest.main()
