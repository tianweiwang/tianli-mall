"""Own Source-only immutable r2 assembly; never import or execute any candidate."""
import ast
import builtins
import difflib
import hashlib
import json
import os
import re
import stat
import symtable
from pathlib import Path
ROWS = []

def enc(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')

def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}

def pointer(path, raw):
    return {'path': Path(path).as_posix(), **pin(raw)}

def n9(info):
    return [info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_uid,
            info.st_gid, info.st_size, info.st_mtime_ns, info.st_ctime_ns]

def read(path, expected=None):
    path = Path(path)
    before = path.lstat()
    assert stat.S_ISREG(before.st_mode) and not getattr(before, 'st_file_attributes', 0) & 0x400
    with path.open('rb') as stream:
        opened = os.fstat(stream.fileno())
        assert all(getattr(before, key) == getattr(opened, key) for key in ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns'))
        assert before.st_birthtime_ns == opened.st_birthtime_ns
        raw = stream.read()
        assert len(raw) == before.st_size and stream.read(1) == b''
        ended = os.fstat(stream.fileno())
        assert n9(ended) == n9(opened) and ended.st_birthtime_ns == opened.st_birthtime_ns
    after = path.lstat()
    assert n9(after) == n9(before) and after.st_birthtime_ns == before.st_birthtime_ns
    if expected is not None:
        assert pin(raw) == {k: expected[k] for k in ('bytes', 'sha256')}
    row = {'path': path.as_posix(), 'pin': pin(raw), 'named_before': n9(before),
           'named_after': n9(after), 'fd_open': n9(opened), 'fd_after': n9(ended),
           'named_birthtime_ns': before.st_birthtime_ns, 'fd_birthtime_ns': opened.st_birthtime_ns}
    ROWS.append(row)
    return raw

def write(path, raw):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('xb') as stream:
        assert stream.write(raw) == len(raw)
    assert path.read_bytes() == raw

def definitions(raw):
    text = raw.decode('utf-8')
    return {node.name: ast.get_source_segment(text, node).encode('utf-8')
            for node in ast.parse(text).body if isinstance(node, (ast.FunctionDef, ast.ClassDef))}

def apply_diff(raw, patch):
    """Replay the complete ordinary unified file diff; no shell or target call."""
    original = raw.decode('utf-8').splitlines(keepends=True)
    lines = patch.decode('utf-8').splitlines(keepends=True)
    assert lines[0].startswith('--- ') and lines[1].startswith('+++ ')
    result, cursor, index = [], 0, 2
    while index < len(lines):
        match = re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@.*\n', lines[index])
        assert match is not None
        start = int(match[1]) - 1
        result.extend(original[cursor:start])
        cursor = start
        index += 1
        while index < len(lines) and not lines[index].startswith('@@ '):
            line = lines[index]
            assert line[0] in ' +-'
            if line[0] in ' -':
                assert cursor < len(original) and original[cursor] == line[1:]
                cursor += 1
            if line[0] in ' +':
                result.append(line[1:])
            index += 1
    result.extend(original[cursor:])
    return ''.join(result).encode('utf-8')

def globals_check(raw):
    table = symtable.symtable(raw.decode('utf-8'), 'producer-source', 'exec')
    declared = {s.get_name() for s in table.get_symbols() if s.is_assigned() or s.is_imported() or s.is_namespace()}
    missing = set()
    def walk(node):
        for symbol in node.get_symbols():
            if symbol.is_referenced() and symbol.is_global() and symbol.get_name() not in declared and not hasattr(builtins, symbol.get_name()):
                missing.add(symbol.get_name())
        for child in node.get_children():
            walk(child)
    walk(table)
    assert missing <= {'__file__', '__name__'}, missing
    return {'declared': sorted(declared), 'recursive_undeclared': sorted(missing - {'__file__', '__name__'})}


ROOT = Path('H:/天俪商城')
ORG = ROOT / 'artifacts/project-organization-20261023'
BASE = ROOT / 'artifacts/ms4-worker-normal-source-preparation-r1'
OUT = Path(__file__).resolve().parent
R1 = ORG / 'current175f-postA-public-producer-source-preparation-r1'
WRAPPERS = ORG / 'current175f-complete-normal-S-reader-outers-source-r1'
SYNTAX = ORG / 'current175f-B-initializer-syntax-source-r2'
EXPECTED_SYNTAX_FINAL = {'bytes': 5997, 'sha256': '7d001b989f7110750706a05ba08f7681ef8d2c60f603b682890e81590f0cb4f4'}


def replace_constant(raw, name, value):
    nodes = [node for node in ast.parse(raw.decode()).body if isinstance(node, ast.Assign)
             and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    assert len(nodes) == 1
    lines = raw.splitlines(keepends=True)
    node = nodes[0]
    lo = sum(map(len, lines[:node.lineno - 1]))
    hi = sum(map(len, lines[:node.end_lineno]))
    return raw[:lo] + (name + ' = ' + repr(value) + '\n').encode('utf-8') + raw[hi:]


def replace_definition(raw, name, value):
    nodes = [node for node in ast.parse(raw.decode()).body if isinstance(node, ast.FunctionDef) and node.name == name]
    assert len(nodes) == 1
    lines = raw.splitlines(keepends=True)
    node = nodes[0]
    lo = sum(map(len, lines[:node.lineno - 1]))
    hi = sum(map(len, lines[:node.end_lineno]))
    return raw[:lo] + value.rstrip(b'\r\n') + b'\n' + raw[hi:]


def literal_constants(raw):
    values = {}
    for node in ast.parse(raw.decode('utf-8')).body:
        if isinstance(node, ast.Assign):
            try:
                value = ast.literal_eval(node.value)
            except (ValueError, TypeError, SyntaxError):
                continue
            for target in node.targets:
                if isinstance(target, ast.Name):
                    values[target.id] = value
    return values


def assemble():
    assert OUT == ORG / 'current175f-postA-public-producer-source-preparation-r2'
    assert type(EXPECTED_SYNTAX_FINAL) is dict, 'Wait for fixed real syntax r2 Source pins; no guessed qualification.'
    r1_pins_raw = read(R1 / 'PINS.json', {'bytes': 3047, 'sha256': 'a10065a96eaa28e7a6f1e41bc212c6489bc733c885f51a1f277580039991a59a'})
    r1_pins = json.loads(r1_pins_raw)
    assert {p.relative_to(R1).as_posix() for p in R1.rglob('*') if p.is_file()} == set(r1_pins['files']) | {'PINS.json'}
    r1_bodies = {name: read(R1 / name, row) for name, row in r1_pins['files'].items()}
    before = r1_bodies['candidate/producer.py']
    m_row = literal_constants(before)['M_RESULT']
    completed_m = json.loads(read(Path(m_row['path']), m_row))
    retirement_fields = [key for key in completed_m if 'retirement' in key]
    assert len(retirement_fields) == 9 and len(completed_m) == 35
    wrappers_pins_raw = read(WRAPPERS / 'PINS.json', {'bytes': 2800, 'sha256': 'b85f4a44d90b8abee38e1b557a21abb9e75327829fe58c688a263cef3e3dc48b'})
    wrappers_pins = json.loads(wrappers_pins_raw)
    for name, row in wrappers_pins['files'].items():
        read(WRAPPERS / name, row)
    wrappers_review_path = ORG / 'current175f-complete-normal-S-reader-outers-root-review-r1/REPORT.json'
    wrappers_review_raw = read(wrappers_review_path, {'bytes': 1756, 'sha256': '27d01b8461670d305d41b336dd2030a82e80172051cca3643c4705a200b53e06'})
    wrappers_review = json.loads(wrappers_review_raw)
    assert wrappers_review['status'] == 'PASS_ROOT_COMPLETE_NORMAL_S_READER_NATIVE_OUTERS_SOURCE_ONLY_NOT_EXECUTED'
    syntax_final_raw = read(SYNTAX / 'FINAL-PINS.json', EXPECTED_SYNTAX_FINAL)
    syntax_final = json.loads(syntax_final_raw)
    for name, row in syntax_final['files'].items():
        read(SYNTAX / name, row)
    assert {p.relative_to(SYNTAX).as_posix() for p in SYNTAX.rglob('*') if p.is_file()} == set(syntax_final['files']) | {'FINAL-PINS.json'}
    declared_raw = read(SYNTAX / 'RESULT-INTERFACE.json')
    declared = json.loads(declared_raw)
    manifest_raw = read(SYNTAX / 'CANDIDATE-SOURCE.json')
    manifest = json.loads(manifest_raw)
    run_raw, launch_raw = read(SYNTAX / 'run.py'), read(SYNTAX / 'launch.py')
    code_raw = read(SYNTAX / 'CODE-PINS.json')
    constants = literal_constants(run_raw)
    expected_run_argv = ['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe', '-B', '-X', 'utf8', str(SYNTAX / 'run.py'), '--authorize-ended-S17155-root-admitted']
    assert declared['run_argv'] == expected_run_argv and declared['result_exact_key_count'] == 20
    assert declared['candidate'] == manifest['candidate'] and declared['candidate']['bytes'] == 118698
    args_nodes = [node for node in ast.walk(ast.parse(run_raw.decode('utf-8'))) if isinstance(node, ast.Assign)
        and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == 'args'
        and isinstance(node.value, ast.List) and isinstance(node.value.elts[0], ast.Constant) and node.value.elts[0].value == 'create']
    assert len(args_nodes) == 1 and ast.literal_eval(args_nodes[0].value.elts[3]) == '--name'
    container_name = ast.literal_eval(args_nodes[0].value.elts[4])
    original_launcher_path = BASE / 'full-a-quota-wire-current175f-normal-next-source-r1/launcher/launch.py'
    original_launcher_raw = read(original_launcher_path, {'bytes': 15839, 'sha256': '2e3bf0739a978420241f7c75128abfb4528c2d911d9e73230f4c0ef3dd3aad25'})
    syntax_interface = {'source_root': SYNTAX.as_posix(), 'source_member_count': len(syntax_final['files']),
        'final_PINS': pointer(SYNTAX / 'FINAL-PINS.json', syntax_final_raw),
        'RESULT_INTERFACE': pointer(SYNTAX / 'RESULT-INTERFACE.json', declared_raw), 'declared': declared,
        'manifest': pointer(SYNTAX / 'CANDIDATE-SOURCE.json', manifest_raw), 'manifest_expected': manifest,
        'run': pointer(SYNTAX / 'run.py', run_raw), 'launch': pointer(SYNTAX / 'launch.py', launch_raw),
        'CODE_PINS': pointer(SYNTAX / 'CODE-PINS.json', code_raw), 'true_run_argv': expected_run_argv,
        'DOCKER': constants['DOCKER'], 'IMAGE': constants['IMAGE'], 'LABEL': constants['LABEL'], 'CONTAINER_NAME': container_name}
    candidate = replace_constant(before, 'INITIALIZER_SYNTAX_INTERFACE', syntax_interface)
    extra_constants = {
        'ORIGINAL_LAUNCHER': pointer(original_launcher_path, original_launcher_raw),
        'READER_WRAPPER_SOURCE_PINS': pointer(WRAPPERS / 'PINS.json', wrappers_pins_raw),
        'READER_WRAPPER_SOURCE_REVIEW': pointer(wrappers_review_path, wrappers_review_raw),
        'READER_OUTCOME_PATHS': {role: (WRAPPERS / role / 'OUTCOME.json').as_posix() for role in ['root', 'peer']}}
    marker = b'\n\ndef need(value, code):'
    assert candidate.count(marker) == 1
    candidate = candidate.replace(marker, b'\n\n' + b'\n'.join((key + ' = ' + repr(value)).encode('utf-8') for key, value in extra_constants.items()) + marker)
    old_argv = b"(NORMAL_SOURCE/'run.py').as_posix()"
    assert candidate.count(old_argv) == 1
    candidate = candidate.replace(old_argv, b"str(NORMAL_SOURCE/'run.py')")
    old_before = b'        before = native9(path)\n'
    new_before = b'        before = [first.st_dev, first.st_ino, first.st_mode, first.st_nlink, first.st_uid, first.st_gid, first.st_size, first.st_mtime_ns, first.st_ctime_ns]\n'
    assert candidate.count(old_before) == 1
    candidate = candidate.replace(old_before, new_before)
    old_final = b"        need(native9(path) == before and final.st_birthtime_ns == first.st_birthtime_ns, 'OWN_NAMED_NINE_AND_BIRTHTIME_UNCHANGED')"
    new_final = b"        after = [final.st_dev, final.st_ino, final.st_mode, final.st_nlink, final.st_uid, final.st_gid, final.st_size, final.st_mtime_ns, final.st_ctime_ns]\n        need(after == before and final.st_birthtime_ns == first.st_birthtime_ns, 'OWN_NAMED_NINE_AND_BIRTHTIME_UNCHANGED')"
    assert candidate.count(old_final) == 1
    candidate = candidate.replace(old_final, new_final)
    old_observation = b"'named_before': before, 'named_after': native9(path), 'fd_open': fd_before"
    assert candidate.count(old_observation) == 1
    candidate = candidate.replace(old_observation, b"'named_before': before, 'named_after': after, 'fd_open': fd_before")
    old_fd_window = b"        self.fd_windows[path.as_posix()] = {'named_before': before, 'named_after': after, 'fd_open': fd_before, 'fd_after': fd_after,\n            'named_birthtime_ns': first.st_birthtime_ns, 'fd_birthtime_ns': opened.st_birthtime_ns, 'full_EOF': True}\n"
    new_fd_window = b"        window = {'named_before': before, 'named_after': after, 'fd_open': fd_before, 'fd_after': fd_after,\n            'named_birthtime_ns': first.st_birthtime_ns, 'fd_birthtime_ns': opened.st_birthtime_ns, 'full_EOF': True}\n        if path.as_posix() in self.fd_windows:\n            need(self.fd_windows[path.as_posix()] == window, 'WHOLE_FIRST_FINAL_NAMED_FD_NINE_BIRTHTIME_EOF_IDENTICAL')\n        else:\n            self.fd_windows[path.as_posix()] = window\n"
    assert candidate.count(old_fd_window) == 1
    candidate = candidate.replace(old_fd_window, new_fd_window)
    old_m_outcome = b"    outcome(m_outcome)\n    exact_pointer(M_ROOT_READBACK)\n"
    new_m_outcome = b"    m_root = json.loads(exact_pointer(M_ROOT_READBACK))\n    need(m_root['status'] == 'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY' and m_root['source_sha'] == HEAD\n        and m_root['actual_RESULT'] == M_RESULT and m_root['actual_freeze'] == FREEZE\n        and m_root['M_RESULT_fields'] == 35 and m_root['steps'] == 19 and m_root['native_operations'] == 1088\n        and m_root['all_original_native_created_terminal_and_streams_join'] is True\n        and m_root['all_input_windows_ended'] is True and type(m_root['outer_pid']) is int and m_root['outer_pid'] == 11268\n        and m_root['outer_native_exit_wait'] == 0, 'ACTUAL_FIXED_COMPLETE_M_ROOT_READBACK_AND_OUTER_IDENTITY')\n    outcome(m_outcome, expected_pid=m_root['outer_pid'], expected_argv=[PYTHON, '-B', '-X', 'utf8', str(NORMAL_SOURCE.parent/'materializer/prepare.py')])\n"
    assert candidate.count(old_m_outcome) == 1
    candidate = candidate.replace(old_m_outcome, new_m_outcome)
    wrapper_gate = b"    need(type(value) is dict and set(value) == {'root', 'peer'}, 'TWO_REAL_COMPLETE_ORIGINAL_SUCCESS_READER_ADMISSIONS')\n"
    assert candidate.count(wrapper_gate) == 1
    candidate = candidate.replace(wrapper_gate, wrapper_gate + b"    wrapper_source = json.loads(exact_pointer(READER_WRAPPER_SOURCE_PINS))\n    wrapper_review = json.loads(exact_pointer(READER_WRAPPER_SOURCE_REVIEW))\n    need(wrapper_review['Source_PINS'] == READER_WRAPPER_SOURCE_PINS and wrapper_review['status'] == 'PASS_ROOT_COMPLETE_NORMAL_S_READER_NATIVE_OUTERS_SOURCE_ONLY_NOT_EXECUTED', 'EXISTING_COMPLETE_NATIVE_WRAPPER_SOURCE_REVIEW_ONLY')\n    for name, row in wrapper_source['files'].items():\n        WINDOWS.file(Path(READER_WRAPPER_SOURCE_PINS['path']).parent / name, row)\n")
    before_outcome = b"        reader_native_outcome(row['outcome'], source)"
    assert candidate.count(before_outcome) == 1
    candidate = candidate.replace(before_outcome, b"        need(row['outcome']['path'] == READER_OUTCOME_PATHS[which], 'EXACT_REAL_SOURCE_DECLARED_READER_OUTCOME_PATH')\n" + before_outcome)
    outcome_gate = b"    made = json.loads(exact_pointer(value['created']))\n    ended = json.loads(exact_pointer(value['terminal']))\n    need(type(made) is dict"
    assert candidate.count(outcome_gate) == 1
    candidate = candidate.replace(outcome_gate, b"    output = Path(row['path']).parent\n    for key, name in [('created', 'reader.created.json'), ('terminal', 'reader.terminal.json'), ('stdout', 'reader.stdout'), ('stderr', 'reader.stderr')]:\n        need(value[key]['path'] == (output / name).as_posix(), 'ORIGINAL_READER_ALL_FOUR_REAL_TYPED_OUTPUT_PATHS')\n" + outcome_gate)
    syntax_adapter_raw = read(OUT / 'syntax-adapter.py')
    adapter = definitions(syntax_adapter_raw)['validate_initializer_syntax_admission']
    candidate = replace_definition(candidate, 'validate_initializer_syntax_admission', adapter)
    ast.parse(candidate.decode('utf-8'))
    old_defs, new_defs = definitions(before), definitions(candidate)
    r1_changed = [name for name in old_defs if old_defs[name] != new_defs[name]]
    assert set(r1_changed) == {'PublicWindows', 'produce', 'reader_native_outcome', 'validate_reader_native_admission', 'validate_initializer_syntax_admission'}
    original_defs = definitions(r1_bodies['before/producer-body.py'])
    cumulative_changed = [name for name in original_defs if original_defs[name] != new_defs[name]]
    assert set(cumulative_changed) == {'PublicWindows', 'require_reader_report', 'produce'}
    consumer_defs = definitions(r1_bodies['recovered/consumer-root-ui-materialize-freeze.py'])
    for name in ['sha', 'ordinary', 'file_pin', 'tree', 'pre_a_history', 'post_a_history']:
        assert new_defs[name] == consumer_defs[name]
    forward = ''.join(difflib.unified_diff(before.decode().splitlines(keepends=True), candidate.decode().splitlines(keepends=True), 'a/producer.py', 'b/producer.py')).encode()
    reverse = ''.join(difflib.unified_diff(candidate.decode().splitlines(keepends=True), before.decode().splitlines(keepends=True), 'a/producer.py', 'b/producer.py')).encode()
    assert apply_diff(before, forward) == candidate and apply_diff(candidate, reverse) == before
    for name, raw in r1_bodies.items():
        write(OUT / 'predecessor-r1' / name, raw)
    write(OUT / 'predecessor-r1/PINS.json', r1_pins_raw)
    write(OUT / 'candidate/producer.py', candidate)
    write(OUT / 'PRODUCER-FORWARD.diff', forward)
    write(OUT / 'PRODUCER-REVERSE.diff', reverse)
    write(OUT / 'INTERFACE-BINDINGS.json', enc({'syntax': syntax_interface, 'native_wrapper': extra_constants,
        'existing_retirement_metadata_authority': {'M_result': m_row, 'fields': retirement_fields,
            'retained_public_files_selection': 'ROOT_EXPLICIT_REAL_COMPLETE_SELECTION_STILL_UNBOUND',
            'opaque_metadata_is_not_payload_read_permission': True},
        'future_actual_outputs_created': False, 'schema_values_are_fixed_Source_not_runtime_receipts': True}))
    initial = list(ROWS)
    ROWS.clear()
    for row in initial:
        read(Path(row['path']), row['pin'])
        assert ROWS[-1] == row
    write(OUT / 'SOURCE-INPUTS.json', enc({'files': initial, 'all_windows_closed': True}))
    design_path = OUT / 'DESIGN.md'
    design = design_path.read_bytes()
    old_task = b'- [ ] Complete r2 Source and exact fixed syntax-interface binding; close all Source-only windows.'
    assert design.count(old_task) == 1
    design_path.write_bytes(design.replace(old_task, b'- [x] Complete r2 Source and exact fixed syntax-interface binding; close all Source-only windows.'))
    report = {'schema': 1, 'status': 'COMPLETE_CURRENT175F_POST_A_PRODUCER_SOURCE_ONLY_UNEXECUTED',
        'candidate': pointer(OUT / 'candidate/producer.py', candidate), 'predecessor_PINS': pointer(R1 / 'PINS.json', r1_pins_raw),
        'whole_predecessor_members': len(r1_bodies) + 1, 'fixed_syntax_Source_FINAL': pointer(SYNTAX / 'FINAL-PINS.json', syntax_final_raw),
        'exact_20_key_8_native_outer10_terminal9_38_file_UI_syntax_adapter': True,
        'actual_reader_OUTCOME6_created3_terminal8_and_fixed_paths': True,
        'original_S_argv_exact_Windows_str_Path': True, 'one_named_snapshot_each_first_final_own9_fd9_cross4_birthtime_EOF': True,
        'full_first_fd_window_preserved_and_every_repeated_end_read_compared': True,
        'actual_fixed_M_Root_outer_pid11268_and_original_materializer_argv_joined': True,
        'r1_changed_definitions': r1_changed, 'original_partial_definitions': len(original_defs),
        'original_unchanged_definitions': sorted(set(original_defs) - set(cumulative_changed)), 'original_changed_definitions': cumulative_changed,
        'whole_original_pre_post_A_and_four_helpers': True, 'whole_original_complete_history_rows': new_defs['complete_history_rows'] == original_defs['complete_history_rows'],
        'full_ordinary_forward_inverse_replayed': True, 'AST': True, 'globals': globals_check(candidate),
        'Source_input_windows': len(initial), 'all_windows_closed': True,
        'retained_public_selection': 'REAL_ROOT_SELECTION_UNBOUND_NOT_EXECUTED; DESIGN_lists_all_required_origins_and_existing_authorities',
        'producer_or_author_or_leaf_or_target_or_syntax_job_executed': False,
        'actual_reader_or_A0_postA_or_future_receipts_hashes_PIDs_created': False,
        'current_Run_private_ENV_actors_cookies_raw_child_stream_read': False,
        'old_Source_canonical_CP_W_Val_N_Run_modified': False, 'Root_admission_required': True,
        'runtime_ready': False, 'full_acceptance': 'INCOMPLETE'}
    write(OUT / 'REPORT.json', enc(report))
    files = {p.relative_to(OUT).as_posix(): pin(p.read_bytes()) for p in sorted(OUT.rglob('*')) if p.is_file()}
    write(OUT / 'PINS.json', enc({'schema': 1, 'status': report['status'], 'files': files, 'runtime_ready': False, 'producer_executed': False}))
    print(json.dumps({'status': report['status'], 'candidate': pin(candidate), 'PINS': pin((OUT / 'PINS.json').read_bytes()), 'members': len(files), 'actual_execution': 'NONE_SOURCE_ASSEMBLY_ONLY'}))


if __name__ == '__main__':
    assemble()

