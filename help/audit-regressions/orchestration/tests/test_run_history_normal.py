"""Normal source/record checks only; no Docker, fault stack, or business run."""
import ast
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


ENTRY = Path(__file__).resolve().parents[1] / "run_history_normal.py"
SPEC = importlib.util.spec_from_file_location("history_normal_runner", ENTRY)
RUNNER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNNER)
ARTIFACTS = Path("H:/天俪商城/artifacts/multi-merchant-final-normal-20261104-r101")
PACKET = ARTIFACTS / "history-source-5b6-close-time-diagnostic-r1"
FREEZE = ARTIFACTS / "history-freeze-5b6-close-time-diagnostic-r1.json"
OLD_LAUNCHER = ARTIFACTS / "history-run-blank-phone-r1-capture-r2/launcher.py"
SAVED_RUN = ARTIFACTS / "history-runs-ae13-reader-blank-phone-r1/202611060631298587f3"


def args(root):
    return RUNNER.argument_parser().parse_args([
        "--history-source", str(PACKET), "--freeze", str(FREEZE),
        "--freeze-sha256", hashlib.sha256(FREEZE.read_bytes()).hexdigest(),
        "--run-root", str(root / "run"), "--capture", str(root / "capture"),
        "--preview-project", "mall-preview-2026110401075567179b",
        "--preview-origin", "http://127.0.0.1:18212",
        "--reserved-subnet", "10.252.246.176/28", "--reserved-subnet", "10.252.246.192/28"])


@contextlib.contextmanager
def external_temporary_directory():
    # The only recursively removed path is the new tempfile under this artifact
    # root, checked before entering its automatic cleanup context.
    with tempfile.TemporaryDirectory(prefix="history-runner-pure-test-", dir=ARTIFACTS) as name:
        root = Path(name).resolve()
        assert root.parent == ARTIFACTS.resolve() and root.name.startswith("history-runner-pure-test-")
        yield root


class HistoryRunnerNormalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not all(path.exists() for path in (PACKET, FREEZE, OLD_LAUNCHER, SAVED_RUN)):
            raise unittest.SkipTest("Existing external public source/normal records are required; no replacement is generated.")

    def test_import_has_no_launcher_or_native_side_effect(self):
        with patch.object(subprocess, "Popen") as native:
            spec = importlib.util.spec_from_file_location("history_import_only", ENTRY)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
        native.assert_not_called()
        self.assertIsNone(module.context_path)
        self.assertIsNone(module.active_child)

    def test_current_packet_preflight_and_tail_sources_without_native_or_output(self):
        with external_temporary_directory() as root, patch.object(subprocess, "Popen") as native:
            inputs = RUNNER.configure(args(root))
            native.assert_not_called()
            self.assertEqual(inputs["source_sha"], "5b6e244a9198ef3316caee56f6ff7681fa575538")
            self.assertEqual(inputs["subnet"], "10.252.246.224/28")
            self.assertEqual(inputs["mysql_command"], json.loads((ARTIFACTS / "history-ae13-refund-columns-normal-invocations/11-mysql-options/stdout").read_bytes()))
            self.assertEqual(list(root.iterdir()), [])
            self.assertGreaterEqual(len(inputs["public_input_pins"]), 44)
            self.assertIn(str(RUNNER.OWNER.as_posix()), inputs["public_input_pins"])
            self.assertIn(str(RUNNER.ZERO.as_posix()), inputs["public_input_pins"])
            RUNNER.held_inputs()

    def test_actual_harmless_child_records_pid_wait_exit_and_complete_streams(self):
        with external_temporary_directory() as root:
            RUNNER.sequence = 0
            with contextlib.redirect_stdout(io.StringIO()):
                output = RUNNER.native("local-normal-child", [sys.executable, "-I", "-B", "-c", "print('LOCAL_NORMAL_CHILD')"], capture=root)
            stage = root / "01-local-normal-child"
            made = RUNNER.load(stage / "created.json")
            terminal = RUNNER.load(stage / "terminal.json")
            self.assertGreater(made["pid"], 0)
            self.assertEqual(terminal["pid"], made["pid"])
            self.assertEqual((terminal["native_exit"], terminal["wait_returncode"]), (0, 0))
            self.assertEqual(output.strip(), b"LOCAL_NORMAL_CHILD")
            self.assertEqual(terminal["stdout"], RUNNER.digest(stage / "stdout"))
            self.assertEqual(terminal["stderr"], {"bytes": 0, "sha256": hashlib.sha256(b"").hexdigest()})
            self.assertIsNone(RUNNER.active_child)

    def test_original_launcher_functions_retained_with_only_explicit_adaptations(self):
        raw = OLD_LAUNCHER.read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(), "637fa62ab55aeb98fa273a1345333880eb77ea282343ef0aaff61c243ad64186")
        before = raw.decode().replace("\r\n", "\n")
        # This review fixes the small accepted migration diff; all original
        # expressions, business predicates and call order remain identical.
        changes = [
            ('def native(label, argv):', 'def native(label, argv, capture=None):'),
            ('global sequence, current_step', 'global sequence, current_step, active_child, last_stage'),
            ('stage = ARCHIVE / f"{sequence:02d}-{label}"', 'stage = (capture or ARCHIVE) / f"{sequence:02d}-{label}"'),
            ('    stage.mkdir(exist_ok=False)', '    stage.mkdir(exist_ok=False)\n    last_stage = stage'),
            ('creationflags=subprocess.CREATE_NO_WINDOW)', 'creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))\n        active_child = child'),
            ('        code = child.wait()', '        code = child.wait()\n        active_child = None'),
            ('    return json.loads(native(label or stage, [*PY,HOST,stage,*args]))', '    held_inputs()\n    return json.loads(native(label or stage, [*PY,HOST,stage,*args]))'),
            ('urlopen("http://127.0.0.1:18212"+route', 'urlopen(PREVIEW_ORIGIN+route'),
            ('for s in ["10.252.246.176/28","10.252.246.192/28"]', 'for s in RESERVED_SUBNETS'),
            ('options==load(ROOT/"history-ae13-refund-columns-normal-invocations/11-mysql-options/stdout")', 'options==MYSQL_COMMAND'),
            ('    ownership=json.loads(native("post-reader-ownership",[*PY,OWNER,"--history-source",PACKET',
             '    held_inputs()\n    ownership=json.loads(native("post-reader-ownership",[*PY,OWNER,"--source",SOURCE,"--history-source",PACKET'),
            ('    independent=json.loads(native("independent-history-zero",', '    held_inputs()\n    independent=json.loads(native("independent-history-zero",'),
        ]
        for old, new in changes:
            self.assertEqual(before.count(old), 1)
            before = before.replace(old, new)
        old_functions = {node.name: node for node in ast.parse(before).body if isinstance(node, ast.FunctionDef)}
        new_functions = {node.name: node for node in ast.parse(ENTRY.read_bytes()).body if isinstance(node, ast.FunctionDef)}
        for name, node in old_functions.items():
            with self.subTest(function=name):
                self.assertEqual(ast.dump(node), ast.dump(new_functions[name]))

    def test_existing_created_records_plan_base_actor_and_prepare_subsets(self):
        with external_temporary_directory() as root:
            RUNNER.configure(args(root))
            context = RUNNER.load(SAVED_RUN / "actual-run-context.json")
            originals = RUNNER.original_resources(context)
            saved_compose = Path(context["prep_dir"]) / "compose.yml"
            RUNNER.SUBNET = RUNNER.compose_inputs(saved_compose.read_bytes())[0]
            base = list(context["initial_service_ids"].values())
            producer = RUNNER.load(SAVED_RUN / "evidence/legacy-history-producer-created.json")["id"]
            preparation = RUNNER.load(SAVED_RUN / "evidence/prepare-created.json")["id"]
            for included in (base, base + [producer], base + [preparation]):
                # These are projections of saved original created observations,
                # not a claim that any fixture resource is presently running.
                snapshot = {"containers": {key: copy.deepcopy(originals["containers"][key]) for key in included},
                            "volumes": copy.deepcopy(originals["volumes"]), "networks": copy.deepcopy(originals["networks"])}
                for row in snapshot["networks"].values():
                    row["IPAM"] = {"Config": [{"Subnet": str(RUNNER.SUBNET)}]}
                plan = RUNNER.retirement_plan(context, originals, snapshot)
                self.assertEqual(set(plan["containers"]), set(included))
                self.assertEqual(len(plan["volumes"]), 6)
                self.assertEqual(len(plan["networks"]), 1)
                self.assertEqual(plan["retained"], [])

    def test_failure_and_retirement_status_are_separate_in_actual_entry(self):
        functions = {node.name: node for node in ast.parse(ENTRY.read_bytes()).body if isinstance(node, ast.FunctionDef)}
        handler = next(node for node in functions["main"].body if isinstance(node, ast.Try)).handlers[0]
        statements = [ast.unparse(node) for node in handler.body]
        failure = next(index for index, value in enumerate(statements) if 'write(ARCHIVE / \'FAILURE.json\'' in value)
        retirement = next(index for index, value in enumerate(statements) if 'retire_failed_run(result)' in value)
        self.assertLess(failure, retirement)
        codes = [node.value.value for node in ast.walk(handler) if isinstance(node, ast.Assign)
                 and any(isinstance(t, ast.Name) and t.id == "code" for t in node.targets) and isinstance(node.value, ast.Constant)]
        self.assertEqual(codes, [1])
        self.assertIn('LIVE_HOST_CHILD_NO_RESOURCE_MUTATION', ast.unparse(functions["retire_failed_run"]))
        self.assertNotIn('history-final-close', ast.unparse(functions["retire_failed_run"]))

    def test_saved_public_identities_preserve_all_non_targets_and_mount_order(self):
        # Use saved public identities only. The projection is a pure fixture,
        # not an assertion about present Docker resources.
        context = RUNNER.load(SAVED_RUN / "actual-run-context.json")
        rows = context["initial_service_created"]
        containers = {row["id"]: RUNNER.stable_container(copy.deepcopy(row)) for row in rows.values()}
        snapshot = {"targets": {"containers": [], "volumes": [], "networks": []}, "global": containers,
                    "global_volumes": {row["name"]: row for row in context["initial_volumes"]},
                    "global_networks": {context["network_id"]: context["initial_network"]}}
        expected = RUNNER.non_target_resources(snapshot)
        reordered = copy.deepcopy(snapshot)
        for key, row in reordered["global"].items():
            row["mounts"].reverse()
            reordered["global"][key] = RUNNER.stable_container(row)
        self.assertEqual(RUNNER.non_target_resources(reordered), expected)
        self.assertEqual({kind: len(values) for kind, values in expected.items()},
                         {"containers": 2, "volumes": 6, "networks": 1})

    def test_actual_volume_name_sources_use_inspected_mountpoints(self):
        record = ARTIFACTS / "history-5b6-normal-capture-r1/retirement/before.json"
        if not record.is_file():
            self.skipTest("Saved public Docker observations are required; no replacement is generated.")
        snapshot = RUNNER.load(record)
        unchanged = copy.deepcopy(snapshot)
        named = [mount for row in snapshot["global"].values() for mount in row["mounts"]
                 if mount["Type"] == "volume" and mount["Source"] == mount.get("Name")]
        self.assertTrue(named)
        for mount in named:
            self.assertTrue(snapshot["global_volumes"][mount["Name"]]["mountpoint"].startswith("/"))
        RUNNER.no_foreign_consumers(snapshot, set(snapshot["targets"]["containers"]))
        self.assertEqual(snapshot, unchanged)


READER_SPEC = importlib.util.spec_from_file_location("history_reader_source", ENTRY.with_name("history_reader_source.py"))
READER = importlib.util.module_from_spec(READER_SPEC)
READER_SPEC.loader.exec_module(READER)
ARCHIVED_READER = ENTRY.parent / "sources/nonempty-history-current-reader-private-context-components-source-r2/candidate/legacy-current-reader.php"


def reader_closing_positive_component():
    """PHP component for an existing PHP 8.2 runtime; no sockets or application."""
    return b"<?php\n" + READER._CLOSING_AFTER + rb'''
function nhep_child_native(array &$child): array { $child['observations']++; return ['running' => true]; }
function nhecr_stream_capture(array $children): array {
    return ['http' => ['stderr' => ['raw' => "prior\n[normal] 127.0.0.1:12345 Closing\n"]]];
}
function nhecr_expect(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
$children = ['http' => ['observations' => 0]];
$response = ['local_port' => 12345, 'http' => 200, 'transport_errno' => 0];
$before = ['native_streams' => ['http' => ['stderr' => ['raw' => "prior\n"]]]];
$closingReason = null;
$closing = nhecr_wait_closing($children, $response, $before, $closingReason);
nhecr_expect($closing === true && $closingReason === 'matched_closing_line'
    && $children['http']['observations'] === 1, 'NORMAL_EXACT_PORT_CLOSING');
nhecr_expect(nhecr_wait_closing($children, $response, $before) === true
    && $children['http']['observations'] === 2, 'ORIGINAL_THREE_ARGUMENT_CALL');
$intent = ['sequence' => 1, 'step' => 'customer_login'];
''' + READER._TRANSPORT_EXPECT_AFTER + b'echo "NORMAL_CLOSING_AND_SUCCESS_ASSERTION_PASS\\n";\n'


class HistoryReaderDiagnosticPositiveTests(unittest.TestCase):
    """Finite source and normal Closing checks only; no failure samples or run."""

    def test_complete_archived_reader_transform_and_reverse_all_existing_modes(self):
        raw = ARCHIVED_READER.read_bytes()
        for readiness, anchors in ((False, False), (True, False), (True, True)):
            with self.subTest(refund_readiness=readiness, legacy_refund_anchors=anchors):
                candidate = READER.transform_reader(raw, refund_readiness=readiness, legacy_refund_anchors=anchors)
                self.assertEqual(READER.reverse_reader(candidate, refund_readiness=readiness,
                                                       legacy_refund_anchors=anchors), raw)
                self.assertEqual(candidate.count(READER._CLOSING_AFTER), 1)
                self.assertEqual(candidate.count(READER._TRANSPORT_EXPECT_AFTER), 1)
                self.assertEqual(candidate.count(b"nhecr_transport($intent, $request, $extra)"), 1)
                self.assertEqual(candidate.count(b"$actual = nhecr_capture($state, $db, $redis, $children);"), 1)
                self.assertIn(b"CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 30", candidate)
                start = candidate.index(b"            $response = null; $primary = null;")
                after = candidate.index(b"$actual = nhecr_capture($state, $db, $redis, $children);", start)
                diagnostic = candidate.index(READER._TRANSPORT_EXPECT_AFTER, start)
                self.assertLess(after, diagnostic)

    def test_closing_only_adds_reason_assignments_and_original_assertion_survives(self):
        original = READER._CLOSING_AFTER.replace(
            b", ?string &$closingReason = null", b"", 1)
        original = re.sub(rb"\{ \$closingReason = '[a-z_]+'; return (false|true); \}",
                          rb"return \1;", original)
        original = original.replace(b"$closingReason = 'deadline_exhausted'; ", b"", 1)
        self.assertEqual(original, READER._CLOSING_BEFORE)
        self.assertEqual(READER._TRANSPORT_EXPECT_AFTER.count(READER._TRANSPORT_EXPECT_BEFORE), 1)
        self.assertIn(b"throw $error;", READER._TRANSPORT_EXPECT_AFTER)
        fields = re.findall(rb"'([a-z_]+)' =>", READER._TRANSPORT_EXPECT_AFTER)
        self.assertEqual(fields, [b"sequence", b"step", b"closing", b"closing_reason", b"http", b"transport_errno"])
        self.assertIn(b"catch (Throwable $ignored) {}", READER._TRANSPORT_EXPECT_AFTER)

    def test_normal_component_contains_actual_closing_and_unchanged_success_predicate(self):
        component = reader_closing_positive_component()
        self.assertEqual(component.count(READER._CLOSING_AFTER), 1)
        self.assertEqual(component.count(READER._TRANSPORT_EXPECT_AFTER), 1)
        self.assertIn(b"NORMAL_EXACT_PORT_CLOSING", component)
        self.assertIn(b"ORIGINAL_THREE_ARGUMENT_CALL", component)
        self.assertNotIn(b"curl_exec", component)


if __name__ == "__main__":
    unittest.main()
