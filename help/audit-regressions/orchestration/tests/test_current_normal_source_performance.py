"""Pure source/projection checks; no archived entry point or Docker is executed."""
import ast
import hashlib
import importlib.util
import itertools
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("current_normal_source", ROOT / "current_normal_source.py")
AUTHOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUTHOR)
HOST = ROOT / "sources/integrated-normal-and-b-source-tree-source-r3/core/actual-profile/ms4-host.py"


def receipt(raw):
    return {"stdout_bytes": len(raw), "stdout_sha256": hashlib.sha256(raw).hexdigest()}


class EnvironmentProjectionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original_host = HOST.read_bytes()
        cls.candidate_host, cls.before, cls.after, cls.proof = (
            AUTHOR.normal_environment_projection_profile_changes(cls.original_host))
        # Read the original names as data. Never import or execute the old Host.
        cls.names = AUTHOR.constant(cls.original_host, "ENV_NAMES")

    def projection(self, body):
        state = {"calls": 0, "form_calls": 0,
                 "values": {name: "synthetic-value-" + str(index) for index, name in enumerate(self.names)}}

        def public_environment(context):
            self.assertEqual(context, {"synthetic": True})
            state["calls"] += 1
            return dict(state["values"])

        def customer_form_environment_bytes(context):
            self.assertEqual(context, {"synthetic": True})
            state["form_calls"] += 1
            return b"synthetic-customer-form\n\n"

        namespace = {"json": json, "itertools": itertools, "ENV_NAMES": self.names,
                     "digest": lambda raw: hashlib.sha256(raw).hexdigest(),
                     "public_environment": public_environment,
                     "customer_form_environment_bytes": customer_form_environment_bytes}
        exec(compile(body, "extracted-environment-projection", "exec"), namespace)
        return namespace["environment_projection_matches"], state

    def test_all_six_field_permutations_and_both_endings_match(self):
        old, old_state = self.projection(self.before)
        new, new_state = self.projection(self.after)
        self.assertEqual(len(self.names), 6)
        lines = [json.dumps(name + "=" + old_state["values"][name]).encode() for name in self.names]
        count = 0
        for permutation in itertools.permutations(lines):
            for ending in (b"\n", b"\n\n"):
                value = receipt(b"\n".join(permutation) + ending)
                self.assertTrue(old({"synthetic": True}, value))
                self.assertTrue(new({"synthetic": True}, value))
                count += 1
        self.assertEqual(count, 1440)
        self.assertEqual(old_state["calls"], count * 6)
        self.assertEqual(new_state["calls"], count)

    def test_each_call_gets_a_fresh_environment(self):
        new, state = self.projection(self.after)
        for index in range(2):
            state["values"][self.names[0]] = "fresh-" + str(index)
            raw = b"\n".join(json.dumps(name + "=" + state["values"][name]).encode()
                              for name in self.names) + b"\n"
            self.assertTrue(new({"synthetic": True}, receipt(raw)))
            self.assertEqual(state["calls"], index + 1)

    def test_customer_form_branch_stays_separate(self):
        for body in (self.before, self.after):
            projection, state = self.projection(body)
            self.assertTrue(projection({"synthetic": True}, receipt(b"synthetic-customer-form\n\n"), True))
            self.assertEqual(state["calls"], 0)
            self.assertEqual(state["form_calls"], 1)
        old_branch = ast.parse(self.before).body[0].body[0]
        new_branch = ast.parse(self.after).body[0].body[0]
        self.assertEqual(ast.dump(old_branch), ast.dump(new_branch))

    def test_only_projection_changes_and_whole_source_reverses(self):
        old = AUTHOR.functions(self.original_host)
        new = AUTHOR.functions(self.candidate_host)
        self.assertEqual(new, {**old, "environment_projection_matches": self.after})
        self.assertEqual(AUTHOR.once(self.candidate_host, self.after, self.before), self.original_host)
        before, after = {"ms4-host.py": self.original_host}, {"ms4-host.py": self.candidate_host}
        names = ("ms4-host.py",)
        forward = AUTHOR.make_patch(before, after, names)
        reverse = AUTHOR.make_patch(after, before, names)
        self.assertEqual(AUTHOR.apply_patch(before, forward, names), after)
        self.assertEqual(AUTHOR.apply_patch(after, reverse, names), before)
        self.assertEqual(self.proof["before"], AUTHOR.pin(self.before))
        self.assertEqual(self.proof["after"], AUTHOR.pin(self.after))
        self.assertTrue(self.proof["whole_host_inverse_exact"])
        self.assertFalse(self.proof["cross_call_or_cli_cache"])


if __name__ == "__main__":
    unittest.main()
