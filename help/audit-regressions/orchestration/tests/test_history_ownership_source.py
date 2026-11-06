"""Positive source selection checks; no history, application, or Docker run."""
import ast
import contextlib
import importlib.util
import io
from pathlib import Path
import re
from types import SimpleNamespace
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).resolve().parents[1] / "history_ownership_normal.py"
SPEC = importlib.util.spec_from_file_location("history_ownership_normal", SOURCE)
RUNNER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNNER)
SOURCES = (
    "ae13dc8fa1e4e3dd259e5d917649715eec9f1567",
    "5b6e244a9198ef3316caee56f6ff7681fa575538",
)


class ExplicitSourceTests(unittest.TestCase):
    def test_cli_passes_each_explicit_source_without_process_global_state(self):
        for expected in (*SOURCES, SOURCES[0]):
            with self.subTest(source=expected):
                argv = [str(SOURCE), "--source", expected,
                        "--history-source", "synthetic-history-source",
                        "--context", "synthetic-original-context",
                        "--output", "synthetic-new-output"]
                result = {"status": "SYNTHETIC_CALL_ONLY", "container_id": "0" * 64}
                with patch.object(RUNNER.sys, "argv", argv), \
                        patch.object(RUNNER, "execute", return_value=result) as execute, \
                        contextlib.redirect_stdout(io.StringIO()):
                    self.assertEqual(RUNNER.main(), 0)
                execute.assert_called_once()
                args = execute.call_args.args[0]
                self.assertEqual(args.source, expected)
                self.assertEqual(args.history_source, "synthetic-history-source")
                self.assertEqual(args.context, "synthetic-original-context")
                self.assertEqual(args.output, "synthetic-new-output")

    def test_actual_qualification_source_guards_accept_both_matching_packets(self):
        # Execute only the real source selection and source-pins guard, extracted
        # before any file read or original Host import. This is not full qualify.
        tree = ast.parse(SOURCE.read_bytes())
        qualify = next(node for node in tree.body
                       if isinstance(node, ast.FunctionDef) and node.name == "qualify")
        boundary = next(index for index, node in enumerate(qualify.body)
                        if isinstance(node, ast.Assign)
                        and isinstance(node.targets[0], ast.Name)
                        and node.targets[0].id == "source")
        guard = next(node for node in qualify.body
                     if isinstance(node, ast.Expr) and isinstance(node.value, ast.Call)
                     and any(isinstance(arg, ast.Constant)
                             and arg.value == "REVIEWED_CURRENT_HISTORY_SOURCE_ONLY"
                             for arg in node.value.args))
        body = qualify.body[:boundary] + [guard, ast.Return(ast.Name("expected_source_sha", ast.Load()))]
        function = ast.FunctionDef(
            name="source_selection", args=ast.arguments(
                posonlyargs=[], args=[ast.arg("args"), ast.arg("source_pins")],
                kwonlyargs=[], kw_defaults=[], defaults=[]),
            body=body, decorator_list=[])
        module = ast.fix_missing_locations(ast.Module(body=[function], type_ignores=[]))
        namespace = {"require": RUNNER.require, "re": re}
        exec(compile(module, "actual-qualification-source-guards", "exec"), namespace)
        for expected in (*SOURCES, SOURCES[0]):
            packet = {"schema": 1, "scope": "CURRENT_HISTORY_SOURCE_CANDIDATE_NOT_RUNTIME",
                      "source_sha": expected, "members": {str(index): {} for index in range(37)}}
            self.assertEqual(namespace["source_selection"](SimpleNamespace(source=expected), packet), expected)

    def test_help_describes_explicit_source_without_running_actor(self):
        output = io.StringIO()
        with patch.object(RUNNER.sys, "argv", [str(SOURCE), "--help"]), \
                patch.object(RUNNER, "execute") as execute, contextlib.redirect_stdout(output):
            with self.assertRaises(SystemExit) as exited:
                RUNNER.main()
        self.assertEqual(exited.exception.code, 0)
        self.assertIn("--source SOURCE", output.getvalue())
        execute.assert_not_called()


if __name__ == "__main__":
    unittest.main()
