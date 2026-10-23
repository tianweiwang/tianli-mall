"""Construct and statically verify the complete fixed H5 Source successor.

This file reads/writes public Source bytes only. No prepared module is imported
or executed and no child process, Git, Docker, browser or runtime is called.
"""
import ast
import difflib
import hashlib
import json
from pathlib import Path
import re
import traceback

ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
BASE = ORG.parent / 'ms4-worker-normal-source-preparation-r1'
OLD = ORG / 'h5-current28dc-complete-source-r1'
OUT = ORG / 'h5-current175f-complete-source-r1'
NORMAL = BASE / 'full-a-quota-wire-current175f-normal-next-source-r1'
HEAD = '175f3cd6183894185656a2974579e1be39eed7a7'
PARENT = '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
READS = {}
SEAMS = []


def need(value, code):
    if not value:
        raise AssertionError(code)


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def read(path):
    path = Path(path)
    before = path.stat()
    raw = path.read_bytes()
    after = path.stat()
    need((before.st_size, before.st_mtime_ns, before.st_ino) ==
         (after.st_size, after.st_mtime_ns, after.st_ino), 'FIXED_SOURCE_WINDOW:' + path.name)
    READS[path.as_posix()] = pin(raw)
    return raw


def obj(path):
    return json.loads(read(path))


def pointer(path):
    path = Path(path)
    return {'path': path.as_posix(), **pin(read(path))}


def encode(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def write(path, raw):
    path = Path(path)
    need(path.is_relative_to(OUT), 'ONLY_OWN_NEW_DIRECTORY_WRITES')
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        need(path.read_bytes() == raw, 'PRESERVE_EXISTING_WHOLE_SOURCE_BYTES:' + path.name)
        return {'path': path.as_posix(), **pin(raw)}
    with path.open('xb') as stream:
        need(stream.write(raw) == len(raw), 'COMPLETE_NEW_FILE_WRITE')
    return {'path': path.as_posix(), **pin(raw)}


def save(name, value):
    return write(OUT / name, encode(value))


def assignments(raw):
    text = raw.decode('utf-8')
    lines = raw.splitlines(keepends=True)
    offsets = [0]
    for line in lines:
        offsets.append(offsets[-1] + len(line))
    result = {}
    for node in ast.parse(text).body:
        if isinstance(node, ast.Assign):
            for target in node.targets:
                if isinstance(target, ast.Name):
                    start = offsets[node.lineno - 1] + node.col_offset
                    end = offsets[node.end_lineno - 1] + node.end_col_offset
                    try:
                        value = ast.literal_eval(node.value)
                    except (ValueError, TypeError):
                        value = None
                    result[target.id] = {'start': start, 'end': end, 'value': value,
                                         'raw': raw[start:end]}
    return result


def swap(raw, before, after, name):
    before = before.encode('utf-8') if isinstance(before, str) else before
    after = after.encode('utf-8') if isinstance(after, str) else after
    need(raw.count(before) == 1, 'EXACT_SINGLE_SEAM:' + name)
    SEAMS.append({'name': name, 'before': before.decode('utf-8'), 'after': after.decode('utf-8')})
    return raw.replace(before, after, 1)


def set_global(raw, name, value, label):
    current = assignments(raw)[name]['raw']
    return swap(raw, current, name + ' = ' + repr(value), label)


def definition_rows(raw):
    lines = raw.splitlines(keepends=True)
    result = {}
    def visit(nodes, prefix=''):
        for node in nodes:
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
                key = prefix + node.name
                start = min([node.lineno] + [d.lineno for d in node.decorator_list])
                body = b''.join(lines[start - 1:node.end_lineno])
                need(key not in result, 'UNIQUE_DEFINITION_PATH')
                result[key] = {'first': start, 'last': node.end_lineno, 'raw': body}
                visit(node.body, key + '.')
            elif hasattr(node, 'body') and isinstance(node.body, list):
                visit(node.body, prefix)
    visit(ast.parse(raw).body)
    return result


def outside_definitions(raw, names):
    definitions = definition_rows(raw)
    lines = raw.splitlines(keepends=True)
    spans = [(definitions[name]['first'], definitions[name]['last'], name) for name in names]
    for first, last, name in sorted(spans, reverse=True):
        lines[first - 1:last] = [('# UNCHANGED-COMPLEMENT:' + name + '\n').encode('utf-8')]
    return b''.join(lines)


def outside_globals(raw, names):
    found = assignments(raw)
    for name in sorted(names, key=lambda x: found[x]['start'], reverse=True):
        row = found[name]
        raw = raw[:row['start']] + ('<GLOBAL:' + name + '>').encode() + raw[row['end']:]
    return raw


def ordinary_diff(before, after, reverse=False):
    pieces = []
    for relative in sorted(before):
        a, b = (after[relative], before[relative]) if reverse else (before[relative], after[relative])
        if a == b:
            continue
        alines, blines = a.decode('utf-8').splitlines(keepends=True), b.decode('utf-8').splitlines(keepends=True)
        for line in difflib.unified_diff(alines, blines, fromfile='a/' + relative, tofile='b/' + relative, n=3):
            if line.endswith('\n'):
                pieces.append(line)
            else:
                pieces.extend([line + '\n', '\\ No newline at end of file\n'])
    return ''.join(pieces).encode('utf-8')


def apply_ordinary(before, patch):
    lines = patch.decode('utf-8').splitlines(keepends=True)
    normalized = []
    for line in lines:
        if line == '\\ No newline at end of file\n':
            need(bool(normalized) and normalized[-1].endswith('\n'), 'ORDINARY_NO_NEWLINE_MARKER')
            normalized[-1] = normalized[-1][:-1]
        else:
            normalized.append(line)
    lines = normalized
    output = dict(before)
    cursor = 0
    changed = []
    while cursor < len(lines):
        need(lines[cursor].startswith('--- a/'), 'ORDINARY_FROM_HEADER')
        relative = lines[cursor][6:].rstrip('\n')
        cursor += 1
        need(lines[cursor] == '+++ b/' + relative + '\n', 'ORDINARY_TO_HEADER')
        cursor += 1
        need(relative in before and relative not in changed, 'ORDINARY_EXISTING_UNIQUE_PATH')
        changed.append(relative)
        original = before[relative].decode('utf-8').splitlines(keepends=True)
        position = 0
        result = []
        while cursor < len(lines) and not lines[cursor].startswith('--- a/'):
            match = re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(?:.*)\n', lines[cursor])
            need(match is not None, 'ORDINARY_HUNK_HEADER')
            old_start, old_count, new_start, new_count = [int(v) if v is not None else 1 for v in match.groups()]
            target = old_start - 1 if old_count else old_start
            need(position <= target <= len(original), 'ORDINARY_MONOTONIC_HUNK')
            result.extend(original[position:target])
            position = target
            need(len(result) == (new_start - 1 if new_count else new_start), 'ORDINARY_NEW_HUNK_OFFSET')
            cursor += 1
            old_used = new_used = 0
            while cursor < len(lines) and not lines[cursor].startswith(('@@ ', '--- a/')):
                line = lines[cursor]
                need(line and line[0] in ' +-','ORDINARY_HUNK_OPERATION')
                kind, body = line[0], line[1:]
                if kind in ' -':
                    need(position < len(original) and original[position] == body, 'ORDINARY_EXACT_OLD_LINE')
                    position += 1
                    old_used += 1
                if kind in ' +':
                    result.append(body)
                    new_used += 1
                cursor += 1
            need((old_used, new_used) == (old_count, new_count), 'ORDINARY_EXACT_HUNK_COUNTS')
        result.extend(original[position:])
        output[relative] = ''.join(result).encode('utf-8')
    return output


def update_success_contract(raw):
    for old, new, label in [
        ('EXACT_CURRENT28DC_ORIGINAL_SUCCESS_INPUT_SCHEMA', 'EXACT_CURRENT175F_ORIGINAL_SUCCESS_INPUT_SCHEMA', 'current-input-schema-label'),
        ("mvars['CURRENT_M_RESULT_KEYS34']", "mvars['CURRENT_M_RESULT_KEYS35']", 'current-result-keys35'),
        ('len(m) == 34', 'len(m) == 35', 'current-result-field-count35'),
        ('EXACT_CURRENT_M34', 'EXACT_CURRENT_M35', 'current-result-schema-label35'),
        ("len(m['commit_chain']) == 18", "len(m['commit_chain']) == 19", 'complete-source-chain19'),
        ('FULL_EIGHTEEN_SOURCE_CHAIN', 'FULL_NINETEEN_SOURCE_CHAIN', 'complete-source-chain-label19'),
        ("len(m['native_operations']) == 1078", "len(m['native_operations']) == 1088", 'derived-native-budget1088'),
    ]:
        raw = swap(raw, old, new, label)
    return raw


def main():
    old_pins = obj(OLD / 'SOURCE-PINS.json')
    inventory_path = ORG / 'h5-current175f-descriptor-inventory-r1/SOURCE-EVIDENCE.json'
    inventory = obj(inventory_path)
    need(inventory['source_sha'] == HEAD, 'FIXED175F_INVENTORY')
    old = {}
    for relative, expected in old_pins['files'].items():
        raw = read(OLD / relative)
        need(pin(raw) == expected, 'EXACT_WHOLE_OLD31:' + relative)
        old[relative] = raw
        write(OUT / 'before' / relative, raw)
    need(len(old) == 31, 'COMPLETE_SOURCE31')
    after = dict(old)
    n_pins = obj(NORMAL / 'PINS.json')
    n_manifest = obj(NORMAL / 'profile-source/MANIFEST.json')
    need(n_pins['source_sha'] == HEAD and n_pins['parent'] == PARENT, 'EXACT_CURRENT175F_A_SOURCE')
    normal9 = n_manifest['selected9']
    for row in normal9:
        relative = 'candidate/' + row['path']
        raw = read(NORMAL / 'validation/actual-profile' / row['path'])
        need(pin(raw) == {k: row[k] for k in ('bytes', 'sha256')}, 'COMPLETE_PINNED_NORMAL9')
        need(pin(raw) == {k: n_pins['files']['validation/actual-profile/' + row['path']][k] for k in ('bytes', 'sha256')}, 'NORMAL9_OUTER_PIN')
        after[relative] = raw
    normal_map = {r['path']: r for r in normal9}

    after['candidate/ms4-h5-host.py'] = set_global(after['candidate/ms4-h5-host.py'], 'CURRENT28DC_SOURCE', HEAD, 'H5-Host-current-HEAD')
    after['candidate/ms4-h5-host.py'] = set_global(after['candidate/ms4-h5-host.py'], 'CURRENT28DC_NORMAL9', normal9, 'H5-Host-complete-current-normal9')
    after['candidate/verify-zero-customer-h5.py'] = set_global(after['candidate/verify-zero-customer-h5.py'], 'CURRENT28DC_NORMAL_READER', normal_map['verify-zero-ms4-fulfillment.py'], 'H5-zero-complete-current-normal-reader')
    after['candidate/verify-zero-customer-h5.py'] = swap(after['candidate/verify-zero-customer-h5.py'],
        "context['source_sha'] == '" + PARENT + "'", "context['source_sha'] == '" + HEAD + "'", 'H5-zero-exact-current-HEAD')

    initializer = {'path': (OUT / 'candidate/ms4-initialize.php').as_posix(), **pin(after['candidate/ms4-initialize.php'])}
    known = json.loads(old['coordinator/CURRENT-A-SOURCE.json'])
    original_known = json.loads(old['coordinator/CURRENT-A-SOURCE.json'])
    m_raw = read(NORMAL / 'materializer/prepare.py')
    need(pin(m_raw) == {k: n_pins['files']['materializer/prepare.py'][k] for k in ('bytes', 'sha256')}, 'EXACT_CURRENT_M_SOURCE')
    m_assign = assignments(m_raw)
    m_values = {k: m_assign[k]['value'] for k in ['COMMITTED_PATHS','COMMIT_CHAIN','CURRENT_M_RESULT_KEYS34','CURRENT_M_RESULT_KEYS35']}
    need(m_values['COMMITTED_PATHS'] == known['M_Source_statics']['COMMITTED_PATHS'], 'COMPLETE307_PATHS_UNCHANGED')
    need(m_values['COMMIT_CHAIN'][:18] == known['M_Source_statics']['COMMIT_CHAIN'], 'COMPLETE18_HISTORY_UNCHANGED')
    need(m_values['CURRENT_M_RESULT_KEYS34'] == known['M_Source_statics']['CURRENT_M_RESULT_KEYS34'], 'COMPLETE34_RESULT_KEYS_UNCHANGED')
    need(len(m_values['COMMIT_CHAIN']) == 19 and len(m_values['CURRENT_M_RESULT_KEYS35']) == 35, 'EXACT19_STEPS35_KEYS')
    derived = 6 + sum(2 + 2 * len(s['files']) for s in m_values['COMMIT_CHAIN']) + 1 + len(m_values['COMMITTED_PATHS']) + 2 + 3 + 1 + 4
    need(derived == 1088, 'NATIVE_BUDGET_DERIVED_FROM_REAL_CHAIN')
    root_path = BASE / 'current175f-complete-materialization-root-readback-r1/REPORT.json'
    root = obj(root_path)
    need(root['status'] == 'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY' and root['source_sha'] == HEAD,
         'REAL_CURRENT_ROOT_M_REPORT')
    need((root['M_RESULT_fields'],root['native_operations'],root['steps'],root['outer_pid']) == (35,1088,19,11268), 'REAL_CURRENT_ROOT_M_METADATA')
    created_path = NORMAL / 'launcher/materialize.created.json'
    terminal_path = NORMAL / 'launcher/materialize.terminal.json'
    created, terminal = obj(created_path), obj(terminal_path)
    need(created['pid'] == terminal['pid'] == root['outer_pid'] and terminal['native_exit'] == terminal['wait_returncode'] == 0, 'REAL_SAME_M_PID_ZERO')
    for stream in ['stdout','stderr']:
        need(pin(read(terminal[stream]['path'])) == {k: terminal[stream][k] for k in ('bytes','sha256')}, 'REAL_M_STREAM_PIN')
    need(terminal['stderr']['bytes'] == 0, 'REAL_M_STDERR_EMPTY')
    known.update({'scope': 'KNOWN_CURRENT175F_SOURCE_AND_COMPLETED_M_ONLY_FUTURE_A0_UNBOUND',
        'source_sha': HEAD, 'parent': PARENT, 'A_Source_PINS': pointer(NORMAL / 'PINS.json'),
        'M_Source': pointer(NORMAL / 'materializer/prepare.py'), 'M_Source_statics': m_values,
        'M_result': root['actual_RESULT'], 'M_root_readback': pointer(root_path), 'M_outer_pid': root['outer_pid'],
        'base_initializer_leaf': initializer, 'M_freeze': root['actual_freeze'],
        'M_original_outcome_files': {'created': pointer(created_path), 'terminal': pointer(terminal_path),
                                   'stdout': terminal['stdout'], 'stderr': terminal['stderr']},
        'current_ninth_retirement': n_pins['ninth_retirement'],
        'initializer_syntax_authority': n_pins['initializer_syntax'], 'future_material_outputs4': None})
    need(known['current_ninth_retirement'] == m_assign['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']['value'], 'FULL_REAL_NINTH_RETIREMENT_POINTER')
    # The complete authority is fixed by N175f. Read bytes opaquely to verify its
    # whole pin; do not traverse the historical Run or the archived body.
    ninth = known['current_ninth_retirement']
    need(pin(read(ninth['path'])) == {k: ninth[k] for k in ('bytes','sha256')}, 'WHOLE_NINTH_FIXED_AUTHORITY')
    after['coordinator/CURRENT-A-SOURCE.json'] = encode(known)
    inputs = json.loads(old['coordinator/INPUTS-UNBOUND.json'])
    inputs.update({'source_sha': HEAD, 'M_result': root['actual_RESULT'],
                   'M_root_readback': known['M_root_readback'], 'initializer_leaf': initializer})
    after['coordinator/INPUTS-UNBOUND.json'] = encode(inputs)
    run = after['coordinator/run.py']
    run = set_global(run, 'HEAD', HEAD, 'coordinator-current-HEAD')
    run = set_global(run, 'PARENT', PARENT, 'coordinator-current-PARENT')
    run = set_global(run, 'ROOT_INITIALIZER_LEAF', initializer, 'coordinator-complete-current-initializer')
    run = update_success_contract(run)
    run = swap(run, 'PASS_ACTUAL_FULL_CURRENT28DC_MATERIALIZATION_ONLY', 'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY', 'coordinator-current-real-Root-M-status')
    run = swap(run, "report['native_operations'] == 1078 and report['steps'] == 18", "report['native_operations'] == 1088 and report['steps'] == 19", 'coordinator-Root-M1088-steps19')
    after['coordinator/run.py'] = run
    after['coordinator/success-contract.fragment.py'] = update_success_contract(after['coordinator/success-contract.fragment.py'])

    after['candidate/H5-README.md'] += (
        '\nCurrent175f independent nine-scene Source successor (not runtime admission).\n'
        'The complete original27 Source plus four coordinator Source members are retained.\n'
        'The exact normal9 profile binds actual175f; the committed Core2 bytes remain unchanged.\n'
        'Known materialization uses M35, the complete19 Source steps and derived1088 native operations;\n'
        'the original34 result keys, original18 Source steps and all307 committed path names remain.\n'
        'The full26262-byte driver, nine real scenes at320/390/1440, all27 scene-width cells,\n'
        'JPEG/catalog/private checks, original H5 close and complete independent zero remain intact.\n'
        'Base8 plus H5 four stays12; the H5 twenty-artifact freeze and UI20 remain separate.\n'
        'All future A0/A outcome/context/success close-zero/postA/material4/browser evidence is UNBOUND.\n'
        'The four original M files are known metadata, never an A business success receipt.\n'
        'This complete Source has not been imported or executed.\n').encode('utf-8')
    derivation = json.loads(old['candidate/h5-derivation.json'])
    nonself = []
    for relative in sorted(x for x in after if x.startswith('candidate/') and x != 'candidate/h5-derivation.json'):
        nonself.append({'path': relative.removeprefix('candidate/'), **pin(after[relative])})
    need(len(nonself) == 26, 'COMPLETE26_NONSELF_SOURCE')
    derivation['current175f_independent_nine_source_successor'] = {
        'source_sha': HEAD, 'parent': PARENT,
        'predecessor_Source_PINS': pointer(OLD / 'SOURCE-PINS.json'),
        'normal_A_Source_PINS': known['A_Source_PINS'], 'historical_derivation_retained': True,
        'nonself_source_members26': nonself, 'normal9': normal9,
        'M_result': known['M_result'], 'M_freeze': known['M_freeze'],
        'M_root_readback': known['M_root_readback'], 'current_ninth_retirement': known['current_ninth_retirement'],
        'resource_base8': derivation['current28dc_independent_nine_source_successor']['resource_base8'],
        'resource_H5_four': derivation['current28dc_independent_nine_source_successor']['resource_H5_four'],
        'complete_total12': True, 'original_nine_scenes_and_three_widths': True,
        'runtime_ready': False, 'A0': None, 'postA': None, 'generated_material_outputs4': None,
        'normal_current_independent_prefix_reader': normal_map['verify-zero-ms4-fulfillment.py'],
        'target_execution': 'NOT RUN', 'H5_is_not_UI20': True}
    after['candidate/h5-derivation.json'] = encode(derivation)

    for relative, raw in after.items():
        write(OUT / relative, raw)
    expected31 = set(old)
    actual31 = {p.relative_to(OUT).as_posix() for top in ['candidate','coordinator'] for p in (OUT/top).rglob('*') if p.is_file()}
    before31 = {p.relative_to(OUT/'before').as_posix() for p in (OUT/'before').rglob('*') if p.is_file()}
    need(actual31 == before31 == expected31, 'EXACT_BOTH_COMPLETE31_NAMESPACES')
    for relative in old:
        need(read(OUT/'before'/relative) == old[relative] and read(OUT/relative) == after[relative], 'WHOLE_BEFORE_AND_CANDIDATE31')
    changed = sorted(r for r in old if old[r] != after[r])
    reused = sorted(r for r in old if old[r] == after[r])
    need(changed == sorted(inventory['14_members_requiring_source_or_descriptor_update']) and len(reused) == 17, 'EXACT14_UPDATED17_WHOLE_REUSED')

    # Every original function/class survives. Whole definitions are compared,
    # and every allowed changed definition is exactly reversible by its named
    # Source descriptor literal seams. All remaining module bytes are checked.
    function_checks = {}
    allowed_functions = {'candidate/verify-zero-customer-h5.py': ['current28dc_normal_reader'],
                         'coordinator/run.py': ['validate_success','actual_A_inputs'],
                         'coordinator/success-contract.fragment.py': ['validate_success']}
    allowed_globals = {'candidate/ms4-h5-host.py': ['CURRENT28DC_SOURCE','CURRENT28DC_NORMAL9'],
                       'candidate/verify-zero-customer-h5.py': ['CURRENT28DC_NORMAL_READER'],
                       'coordinator/run.py': ['HEAD','PARENT','ROOT_INITIALIZER_LEAF'],
                       'candidate/ms4-host.py': ['INTERFACE_SHA256','CUSTOMER_FORM_SOURCE_FILES'],
                       'candidate/normal-close-ms4-fulfillment.py': ['HOST_SHA256'],
                       'candidate/verify-zero-ms4-fulfillment.py': ['HOST_SHA256','CLOSE_SHA256','INTERFACE_BYTES','INTERFACE_SHA256','CUSTOMER_FORM_SOURCE_FILES']}
    for relative in sorted(r for r in old if r.endswith('.py')):
        before_defs, after_defs = definition_rows(old[relative]), definition_rows(after[relative])
        need(set(before_defs) == set(after_defs), 'ALL_ORIGINAL_DEFINITIONS_AND_NO_NEW_DEFINITIONS:' + relative)
        changed_defs = sorted(name for name in before_defs if before_defs[name]['raw'] != after_defs[name]['raw'])
        need(changed_defs == sorted(allowed_functions.get(relative, [])), 'EXACT_DESCRIPTOR_FUNCTION_SEAMS_ONLY:' + relative)
        masked_before = outside_definitions(old[relative], changed_defs)
        masked_after = outside_definitions(after[relative], changed_defs)
        names = allowed_globals.get(relative, [])
        need(outside_globals(masked_before,names) == outside_globals(masked_after,names), 'WHOLE_NONALLOWED_MODULE_COMPLEMENT:' + relative)
        function_checks[relative] = {'original_count': len(before_defs), 'candidate_count': len(after_defs),
            'whole_unchanged': len(before_defs)-len(changed_defs), 'descriptor_changed': changed_defs,
            'module_complement_exact': True,
            'definitions': {name: {'before': pin(before_defs[name]['raw']), 'candidate': pin(after_defs[name]['raw'])} for name in before_defs}}
    zero_original = definition_rows(old['candidate/verify-zero-customer-h5.py'])['current28dc_normal_reader']['raw']
    zero_updated = definition_rows(after['candidate/verify-zero-customer-h5.py'])['current28dc_normal_reader']['raw']
    need(zero_updated.replace(HEAD.encode(), PARENT.encode()) == zero_original, 'ZERO_FUNCTION_WHOLE_HEAD_LITERAL_INVERSE')
    for relative in ['coordinator/run.py','coordinator/success-contract.fragment.py']:
        before_func = definition_rows(old[relative])['validate_success']['raw']
        after_func = definition_rows(after[relative])['validate_success']['raw']
        for seam in reversed(SEAMS):
            if seam['name'] in ['current-input-schema-label','current-result-keys35','current-result-field-count35','current-result-schema-label35','complete-source-chain19','complete-source-chain-label19','derived-native-budget1088']:
                # The same seven seams are recorded once for each full copy.
                a,b=seam['after'].encode(),seam['before'].encode()
                if a in after_func:
                    need(after_func.count(a)==1,'EXACT_COMPLETE_SUCCESS_FUNCTION_INVERSE_LITERAL')
                    after_func=after_func.replace(a,b,1)
        need(after_func == before_func, 'FULL_SUCCESS_FUNCTION_DESCRIPTOR_INVERSE')
    a_fn = definition_rows(after['coordinator/run.py'])['actual_A_inputs']['raw']
    a_fn = a_fn.replace(b'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY', b'PASS_ACTUAL_FULL_CURRENT28DC_MATERIALIZATION_ONLY')
    a_fn = a_fn.replace(b"report['native_operations'] == 1088 and report['steps'] == 19", b"report['native_operations'] == 1078 and report['steps'] == 18")
    need(a_fn == definition_rows(old['coordinator/run.py'])['actual_A_inputs']['raw'], 'WHOLE_ACTUAL_A_INPUTS_ONLY_ROOT_M_METADATA_INVERSE')
    new_validate = definition_rows(after['coordinator/run.py'])['validate_success']['raw']
    fragment_validate = definition_rows(after['coordinator/success-contract.fragment.py'])['validate_success']['raw']
    need(new_validate == fragment_validate, 'COMPLETE_SUCCESS_CONTRACT_FUNCTION_SAME_BOTH_COPIES')

    # Exact late-bound schema and data: a known M supplies no A success.
    nulls = ['M_outcome','A_outcome','A_outer_session','A_qualification','A_context','post_A_history_contract','Source_review_authority']
    need(set(inputs) == set(json.loads(old['coordinator/INPUTS-UNBOUND.json'])) and all(inputs[k] is None for k in nulls), 'FULL_ORIGINAL_INPUT_SCHEMA_AND_UNBOUND_A_FIELDS')
    need(all(known[k] is None for k in ['future_A0','future_postA','future_browser_evidence','future_material_outputs4']), 'ALL_FUTURE_SUCCESS_UNBOUND')
    need(assignments(after['coordinator/run.py'])['ROOT_INITIALIZER_LEAF']['value'] == inputs['initializer_leaf'] == known['base_initializer_leaf'] == initializer, 'COMPLETE_CURRENT_INITIALIZER_DAG')
    need(pin(after['candidate/ms4-initialize.php']) == {k: initializer[k] for k in ('bytes','sha256')}, 'REAL_INITIALIZER_BODY_PIN')
    need(inputs['M_result'] == known['M_result'] == root['actual_RESULT'] and known['M_freeze'] == root['actual_freeze'], 'REAL_M_RESULT_FREEZE_METADATA_DAG')
    need(inputs['M_root_readback'] == known['M_root_readback'] == pointer(root_path), 'REAL_ROOT_M_READBACK_DAG')

    # Complete normal9 and H5 hash DAG, with complete interface/CU7 rows.
    host_values = assignments(after['candidate/ms4-host.py'])
    close_values = assignments(after['candidate/normal-close-ms4-fulfillment.py'])
    zero_values = assignments(after['candidate/verify-zero-ms4-fulfillment.py'])
    interface = json.loads(after['candidate/interface-v1.json'])
    cu7 = interface['customer_form_current_profile']['php_sources']
    host_cu7 = host_values['CUSTOMER_FORM_SOURCE_FILES']['value']
    zero_cu7 = zero_values['CUSTOMER_FORM_SOURCE_FILES']['value']
    need(type(host_cu7) is tuple and type(zero_cu7) is tuple and type(cu7) is list,
         'ORIGINAL_PYTHON_TUPLES_AND_JSON_ARRAY_TYPES')
    need(host_cu7 == zero_cu7 == tuple(cu7) and len(cu7)==7, 'CU7_COMPLETE_INTERFACE_HOST_ZERO_DAG')
    need(cu7[0] == {'path':'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php','bytes':68224,'sha256':'fb729bd1a554441186938c22a4323a78537b3614c9561f7e9acf4dd00de2406d'}, 'ACTUAL175F_HELPER_TUPLE')
    initializer_text=after['candidate/ms4-initialize.php'].decode('utf-8')
    for row in cu7:
        literal="['path' => '" + row['path'] + "', 'bytes' => " + str(row['bytes']) + ", 'sha256' => '" + row['sha256'] + "']"
        need(initializer_text.count(literal)==1,'EACH_COMPLETE_CU7_INITIALIZER_TUPLE')
    edges = [('normal.Host.interface',host_values['INTERFACE_SHA256']['value'],pin(after['candidate/interface-v1.json'])['sha256']),
             ('normal.close.Host',close_values['HOST_SHA256']['value'],pin(after['candidate/ms4-host.py'])['sha256']),
             ('normal.zero.interface',zero_values['INTERFACE_SHA256']['value'],pin(after['candidate/interface-v1.json'])['sha256']),
             ('normal.zero.Host',zero_values['HOST_SHA256']['value'],pin(after['candidate/ms4-host.py'])['sha256']),
             ('normal.zero.close',zero_values['CLOSE_SHA256']['value'],pin(after['candidate/normal-close-ms4-fulfillment.py'])['sha256'])]
    need(all(a==b for _,a,b in edges), 'NORMAL_COMPLETE_HASH_DAG')
    need(zero_values['INTERFACE_BYTES']['value']==len(after['candidate/interface-v1.json']),'NORMAL_ZERO_INTERFACE_BYTES')
    normal_derivation=json.loads(after['candidate/source-derivation.json'])
    need(normal_derivation['current_nonself_artifacts']==[r for r in normal9 if r['path']!='source-derivation.json'], 'NORMAL_EIGHT_NONSELF_DAG')
    hv,zv=assignments(after['candidate/ms4-h5-host.py']),assignments(after['candidate/verify-zero-customer-h5.py'])
    need(hv['CURRENT28DC_SOURCE']['value']==HEAD and hv['CURRENT28DC_NORMAL9']['value']==normal9,'H5_HOST_HEAD_AND_COMPLETE_NORMAL9_DAG')
    need(zv['CURRENT28DC_NORMAL_READER']['value']==normal_map['verify-zero-ms4-fulfillment.py'],'H5_ZERO_COMPLETE_READER_DAG')
    need(hv['CURRENT28DC_H5_CORE2']['value']==zv['CURRENT28DC_H5_CORE2']['value'],'H5_CORE2_DESCRIPTOR_JOIN')
    core_map={'help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php':'candidate/Core/lib/ms4-customer-h5-normal.php',
              'help/audit-regressions/isolated/ms4-customer-h5.php':'candidate/Core/ms4-customer-h5.php'}
    for row in hv['CURRENT28DC_H5_CORE2']['value']:
        need(pin(after[core_map[row['path']]])=={k:row[k] for k in ('bytes','sha256')},'H5_CORE2_COMPLETE_BYTES')
    last=m_values['COMMIT_CHAIN'][-1]
    need(last['source_sha']==HEAD and last['parent']==PARENT and not set(core_map)&{r['path'] for r in last['files']},'CURRENT_COMMIT_NO_CORE2_CHANGE')
    new_subtree=derivation.pop('current175f_independent_nine_source_successor')
    need(derivation==json.loads(old['candidate/h5-derivation.json']),'WHOLE_ALL_HISTORICAL_DERIVATION_FIELDS')
    derivation['current175f_independent_nine_source_successor']=new_subtree
    for row in new_subtree['nonself_source_members26']:
        need(pin(after['candidate/'+row['path']])=={k:row[k] for k in ('bytes','sha256')},'H5_COMPLETE26_NONSELF_DAG')
    need(after['candidate/H5-README.md'].startswith(old['candidate/H5-README.md']),'WHOLE_HISTORICAL_README_RETAINED')
    need(pin(after['candidate/browser-v1/ms4-customer-browser.mjs'])=={'bytes':26262,'sha256':'f0a584263a9c997cce080b8aa0d45a8876842d458c0b1b64d9c24b975d17be7a'},'REAL_ORIGINAL26262_NINE_SCENE_DRIVER')
    cvars=assignments(after['candidate/normal-close-customer-h5.py'])
    need(len(cvars['SCENES']['value'])==9 and len(cvars['VOLUMES']['value'])==12,'REAL_NINE_SCENES_AND_TOTAL12')
    need(len(hv['H5_ARTIFACTS']['value'])==len(zv['H5_ARTIFACTS']['value'])==20,'COMPLETE_ORIGINAL20_H5_ARTIFACTS')

    forward=ordinary_diff(old,after)
    reverse=ordinary_diff(old,after,True)
    need(apply_ordinary(old,forward)==after and apply_ordinary(after,reverse)==old,'ORDINARY_COMPLETE_FORWARD_REVERSE_WHOLE31_REPLAY')
    fpin=write(OUT/'CHANGES.diff',forward)
    rpin=write(OUT/'REVERSE.diff',reverse)
    inverse=[]
    for relative in sorted(old):
        inverse.append({'path':relative,'before':pin(old[relative]),'candidate':pin(after[relative]),
                        'whole_forward_exact':True,'whole_inverse_exact':True,'reused':old[relative]==after[relative]})
    ip=save('WHOLE-INVERSE.json',{'schema':1,'members31':inverse,'ordinary_forward':fpin,'ordinary_reverse':rpin})
    sp=dict(old_pins)
    sp.update({'status':'COMPLETE_CURRENT175F_INDEPENDENT_NINE_H5_SOURCE_ONLY_NOT_RUNTIME_ADMISSION',
               'source_sha':HEAD,'parent':PARENT,'files':{r:pin(after[r]) for r in old_pins['files']}})
    need(sp['runtime_ready'] is False and sp['A0'] is None and sp['post_A'] is None and sp['generated_material_outputs4'] is None and sp['target_execution']=='NOT RUN','NO_RUNTIME_ACCEPTANCE_IN_SOURCE_PINS')
    source_pin=save('SOURCE-PINS.json',sp)
    check=save('STATIC-CHECKS.json',{'schema':1,'status':'PASS_COMPLETE_SOURCE_STATIC_ONLY',
       'normal9_whole_N175f':normal9,'changed14':changed,'whole_reused17':reused,
       'complete_both_namespaces31':True,'all_original_functions_and_module_complements':function_checks,
       'exact_literal_seams':SEAMS,'normal_hash_edges':[{'edge':k,'expected':a,'actual':b} for k,a,b in edges],
       'all_current_interface_CU7_initializer_and_H5_normal9_reader_Core2_nonself26_coordinator_DAG':True,
       'whole_historical_derivation_and_Readme':True,'original34_keys_and18_steps_and307_paths':True,
       'current35_keys19_steps_derived1088':True,'whole_ninth_authority':known['current_ninth_retirement'],
       'real_M_only_metadata':{'result':known['M_result'],'freeze':known['M_freeze'],'root':known['M_root_readback'],
            'original_outcome_files':known['M_original_outcome_files'],'pid':known['M_outer_pid'],'native_exit_wait':[0,0]},
       'A_success_late_bound_null_fields':nulls,'runtime_ready':False,'prepared_programs_imported_or_executed':False,
       'author_leaf_H5_Host_Docker_Node_browser_Git_calls':0,'live_S_Run_or_private_reads':False,
       'original_nine_scenes':cvars['SCENES']['value'],'widths':[320,390,1440],'grid':27,
       'driver':pin(after['candidate/browser-v1/ms4-customer-browser.mjs']),
       'H5_close_whole_reused':pin(after['candidate/normal-close-customer-h5.py']),
       'ordinary_forward_reverse_whole31_replayed':True,'whole_inverse':ip,
       'finite_Source_and_fixed_public_metadata_reads':READS})
    report=save('REPORT.json',{'schema':1,'status':'COMPLETE_CURRENT175F_H5_SOURCE_REBIND_STATIC_ONLY_NOT_RUNTIME_ADMISSION',
        'source_sha':HEAD,'parent':PARENT,'scope':'Complete original candidate27 plus coordinator4 Source only.',
        'original_Source_PINS':pointer(OLD/'SOURCE-PINS.json'),'inventory':pointer(inventory_path),
        'final_Source_PINS':source_pin,'static_checks':check,'whole_inverse':ip,
        'members31':31,'changed14':changed,'whole_reused17':reused,
        'all_original_functions_retained':True,'only_necessary_current_descriptors_changed':True,
        'full_normal9_whole_N175f':True,'original34_keys18_steps307_paths_preserved':True,
        'currentM35_steps19_native1088_derived':True,'actualM_PID':11268,
        'future_A0_A_outcome_context_success_close_zero_postA_material4':'UNBOUND',
        'runtime_H5_Source_Host_browser':'NOT RUN','live_S_not_read_or_modified':True,
        'original_nine_scenes_27_width_cells_JPEG26262_driver_and_close_zero_business':'PRESERVED',
        'H5_is_not_UI20':True,'ordinary_patches_full_whole31_forward_reverse':'PASS',
        'W_Val_Git_N175f_oldpackets_canonical_CP_writes':0,
        'limitations':'Static Source preparation only. Real same-source successful A and postA, actual four new material outputs, browser evidence and original H5 close/independent zero remain required and unbound.'})
    own_files={p.relative_to(OUT).as_posix():pin(read(p)) for p in OUT.rglob('*') if p.is_file()}
    final=save('PINS.json',{'schema':1,'status':'FROZEN_COMPLETE_CURRENT175F_H5_SOURCE_STATIC_ONLY',
       'source_sha':HEAD,'parent':PARENT,'Source_PINS':source_pin,'REPORT':report,'files':own_files,
       'prepared_programs_imported_or_executed':False,'runtime_admission':False})
    print(json.dumps({'REPORT':report,'SOURCE_PINS':source_pin,'PINS':final,'changed':len(changed),'reused':len(reused)},ensure_ascii=True))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        error=OUT/'construction-errors'
        error.mkdir(exist_ok=True)
        index=1
        while (error/('attempt-%02d.json'%index)).exists():
            index+=1
        write(error/('attempt-%02d.json'%index),encode({'status':'PRESERVED_SOURCE_CONSTRUCTION_OR_STATIC_CHECK_FAILURE',
              'traceback':traceback.format_exc(),'target_execution':'NOT RUN'}))
        raise
