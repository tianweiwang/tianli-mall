"""UNEXECUTED author draft, never a target launcher.

Only stdlib data/AST/byte operations. It refuses absent same175f A success inputs,
creates a NEW unsealed Source directory, never imports reviewed programs and
does not create final outer Source PINS/REPORT or make an admission decision.
Root must review this author and provide actual public input before invoking it.
"""
import argparse
import ast
import copy
import difflib
import hashlib
import json
from pathlib import Path

BASE = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
OLD = BASE / 'fresh-b-customer-form-external-source-r3'
A = BASE / 'full-a-quota-wire-current175f-normal-next-source-r1'
HEAD = '175f3cd6183894185656a2974579e1be39eed7a7'
PARENT = '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
EMPTY = hashlib.sha256(b'').hexdigest()
UNSEALED = 'UNSEALED_PENDING_JOINT_FULL17_SOURCE_QUALIFICATION'
CLI = ['ms4-host.py', 'normal-close-ms4-fulfillment.py', 'verify-zero-ms4-fulfillment.py']
CURRENT_A_SOURCE_PINS = {'bytes': 147196, 'sha256': '2653282ad09cdf30e030c6c1cf5976b73a1a06bd0be52070085e3a3f33bb270b'}
CURRENT_M_RESULT = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/validation/materialize-freeze-assets-r1/RESULT.json', 'bytes': 46750425, 'sha256': '6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa'}
CURRENT_M_FREEZE = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/validation/materialize-freeze-assets-r1/freeze-binding.json', 'bytes': 10985209, 'sha256': '4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed'}
ORACLE_PATH = 'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php'
ROOT_INITIALIZER_LEAF = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/fresh-b-current175f-source-preparation-r1/leaf/ms4-initialize.php', 'bytes': 118698, 'sha256': '29376b4266010285ec4cebd92f0197d849142988442ead06cbcc7d26ff840db8'}


def need(value, code):
    if not value:
        raise RuntimeError(code)


def enc(v):
    return (json.dumps(v, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def fixed(p, row):
    p = Path(p)
    need(p.is_absolute() and p.is_file() and not p.is_symlink(), 'EXPLICIT_REGULAR_SOURCE_OR_PUBLIC_INPUT')
    need(type(row) is dict and type(row.get('bytes')) is int and row['bytes'] >= 0 and type(row.get('sha256')) is str, 'TYPED_PIN')
    raw = p.read_bytes()
    need(pin(raw) == {k: row[k] for k in ('bytes', 'sha256')}, 'EXACT_INPUT_BYTES')
    return raw


def deref(row):
    need(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}, 'COMPLETE_NONEMPTY_ARTIFACT_POINTER')
    return fixed(row['path'], row)


def ptr(p, raw):
    return {'path': Path(p).as_posix(), **pin(raw)}


def constants(raw):
    result = {}
    for node in ast.parse(raw.decode('utf-8')).body:
        if isinstance(node, ast.Assign):
            try:
                value = ast.literal_eval(node.value)
            except (ValueError, TypeError, SyntaxError):
                continue
            for target in node.targets:
                if isinstance(target, ast.Name):
                    result[target.id] = value
    return result


def replace_assign(raw, name, value=None, expression=None):
    tree = ast.parse(raw.decode('utf-8'))
    matches = [n for n in tree.body if isinstance(n, ast.Assign) and len(n.targets) == 1
               and isinstance(n.targets[0], ast.Name) and n.targets[0].id == name]
    need(len(matches) == 1, 'UNIQUE_TOP_LEVEL_ASSIGNMENT_' + name)
    n = matches[0]
    lines = raw.splitlines(keepends=True)
    lo = sum(map(len, lines[:n.lineno - 1])) + n.col_offset
    hi = sum(map(len, lines[:n.end_lineno - 1])) + n.end_col_offset
    replacement = (name + ' = ' + (expression if expression is not None else repr(value))).encode('utf-8')
    return raw[:lo] + replacement + raw[hi:]


def one(text, old, new, count=1):
    need(text.count(old) == count, 'EXACT_LITERAL_OR_BLOCK_SEAM')
    return text.replace(old, new)


def definitions(raw):
    text = raw.decode('utf-8')
    return {n.name: ast.get_source_segment(text, n) for n in ast.parse(text).body
            if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef))}


def append_before_entry(raw, extra):
    marker = b"if __name__ == '__main__':"
    need(raw.count(marker) == 1, 'ONE_REAL_ENTRY_GUARD')
    i = raw.index(marker)
    return raw[:i] + b'\n\n' + extra + b'\n\n' + raw[i:]


def receipt_outcome(value):
    need(type(value) is dict and set(value) == {'created', 'terminal', 'stdout', 'stderr', 'created_object', 'terminal_object'}, 'EXACT_OUTCOME_SIX')
    made = json.loads(deref(value['created']))
    ended = json.loads(deref(value['terminal']))
    need(made == value['created_object'] and ended == value['terminal_object'], 'REAL_OUTCOME_OBJECTS')
    need(set(made) == {'pid', 'argv', 'started_ns'}, 'ACTUAL_CREATED_THREE')
    need(set(ended) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}, 'ACTUAL_TERMINAL_SIX')
    need(type(made['pid']) is int and made['pid'] > 0 and made['pid'] == ended['pid'], 'ACTUAL_SAME_PID')
    need(type(ended['native_exit']) is int and type(ended['wait_returncode']) is int and ended['native_exit'] == ended['wait_returncode'] == 0, 'ACTUAL_OUTER_ZERO')
    need(0 < made['started_ns'] < ended['finished_ns'] and type(made['argv']) is list, 'ACTUAL_NATIVE_TIME_ARGV')
    for stream in ('stdout', 'stderr'):
        need(ended[stream] == value[stream], 'ACTUAL_NESTED_STREAM_POINTER')
        raw = deref(value[stream])
        if stream == 'stderr':
            need(raw == b'', 'ACTUAL_EMPTY_STDERR')
    return made, ended


def validate_success(inputs, mvars, apins):
    required = {'schema', 'source_sha', 'M_result', 'M_outcome', 'M_root_readback', 'A_outcome', 'A_outer_session',
                'A_qualification', 'A_context', 'post_A_history_contract', 'Source_review_authority', 'initializer_leaf'}
    need(type(inputs) is dict and set(inputs) == required and inputs['schema'] == 1 and inputs['source_sha'] == HEAD, 'EXACT_CURRENT175F_AUTHOR_INPUT_SCHEMA')
    need(all(inputs[k] is not None for k in required), 'WAIT_REAL_SAME_SOURCE_A_INPUTS_NO_PLACEHOLDERS')
    need(inputs['initializer_leaf'] == ROOT_INITIALIZER_LEAF, 'EXACT_ROOT_ALREADY_QUALIFIED_INITIALIZER_LEAF')
    need(inputs['M_result'] == CURRENT_M_RESULT, 'EXACT_COMPLETED_CURRENT175F_M_PUBLIC_POINTER')
    m = json.loads(deref(inputs['M_result']))
    need({'path': m['freeze'], 'bytes': m['freeze_bytes'], 'sha256': m['freeze_sha256']} == CURRENT_M_FREEZE, 'EXACT_COMPLETED_CURRENT175F_FREEZE')
    need(set(m) == set(mvars['CURRENT_M_RESULT_KEYS35']) and len(m) == 35, 'EXACT_CURRENT_M35')
    need(m['source_sha'] == HEAD and m['parent'] == PARENT and m['Run_created'] is False and m['App_DB_HTTP_business'] == 'NOT RUN', 'SAME_HEAD_M_ONLY')
    need(m['commit_chain'] == mvars['COMMIT_CHAIN'] and len(m['commit_chain']) == 19, 'FULL_NINETEEN_SOURCE_CHAIN')
    need([r['path'] for r in m['committed_files']] == mvars['COMMITTED_PATHS'], 'ALL307_COMMITTED_SOURCE_ROWS')
    derived = 6 + sum(2 + 2 * len(s['files']) for s in mvars['COMMIT_CHAIN']) + 1 + len(mvars['COMMITTED_PATHS']) + 2 + 3 + 1 + 4
    need(derived == len(m['native_operations']) == 1088, 'DERIVED_FULL_A_M_LEDGER_NOT_B_BUDGET')
    receipt_outcome(inputs['M_outcome'])
    receipt_outcome(inputs['A_outcome'])
    deref(inputs['M_root_readback'])
    deref(inputs['Source_review_authority'])
    aq = inputs['A_qualification']
    need(type(aq) is dict and set(aq) == {'outer_native_exit', 'normal_root_result', 'worker_close', 'independent_zero', 'post_a_history'}, 'REAL_A_SUCCESS_FIVE')
    need(type(aq['outer_native_exit']) is int and aq['outer_native_exit'] == 0, 'A_OUTER_SUCCESS_REQUIRED')
    need(type(inputs['A_outer_session']) is int and inputs['A_outer_session'] > 0, 'REAL_A_ORIGINAL_HANDLE_REQUIRED')
    result = json.loads(deref(aq['normal_root_result']))
    context = json.loads(deref(inputs['A_context']))
    need(result == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
        'run_id': context['run_id'], 'source_sha': HEAD, 'context': inputs['A_context']['path'],
        'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE',
        'DOM_nonempty_history_due_finance_release': 'NOT RUN'}, 'ORIGINAL_FULL_NORMAL_A_RESULT_NOT_PARTIAL_SUCCESS')
    need(context['source_sha'] == HEAD and context['freeze_sha256'] == m['freeze_sha256'], 'ACTUAL_SAME_A_CONTEXT_FREEZE')
    closed = json.loads(deref(aq['worker_close']))
    zero = json.loads(deref(aq['independent_zero']))
    need(closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL' and zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO', 'ORIGINAL_SUCCESSFUL_SUFFIX_ALREADY_CLOSED')
    for value in (closed, zero):
        need(value['run_id'] == context['run_id'] and value['source_sha'] == HEAD and value['freeze_sha256'] == m['freeze_sha256'] and value['native_exit'] == 0, 'SAME_A_ACTUAL_CLOSE_ZERO_IDENTITY')
    need(zero['close_result_sha256'] == aq['worker_close']['sha256'], 'EXACT_ORIGINAL_CLOSE_ZERO_JOIN')
    need([(r['kind'], r['label']) for r in zero['queries']] == [(k, l) for k in ['container', 'network', 'volume'] for l in ['project', 'run']], 'SIX_ORIGINAL_FRESH_ZERO_QUERIES')
    need(all(r['native_exit'] == 0 and r['stdout_bytes'] == r['stderr_bytes'] == 0 and r['stdout_sha256'] == r['stderr_sha256'] == EMPTY for r in zero['queries']), 'SIX_ORIGINAL_FULL_EMPTY_STREAMS')
    history = json.loads(deref(aq['post_a_history']))
    hc = inputs['post_A_history_contract']
    need(type(hc) is dict and set(hc) == {'header', 'files_key', 'retained_public_files'}, 'ACTUAL_POST_A_CLOSED_SCHEMA')
    need(set(history) == set(hc['header']) | {hc['files_key']} and {k: history[k] for k in hc['header']} == hc['header'], 'ACTUAL_POST_A_HEADER')
    rows = history[hc['files_key']]
    need(type(rows) is list and [r['path'] for r in rows] == sorted({r['path'] for r in rows}), 'COMPLETE_POST_A_PUBLIC_NAMESPACE')
    # Actual target consumers retain their full native/Source/history proof; this
    # author does not execute them, replay them or assert a future B result.
    need(apins['source_sha'] == HEAD, 'EXACT_FINAL_ELIGIBLE_A_SOURCE')
    return m, context


def new_adapter(old, contract):
    """Full old four-function text retained separately; derive exact new wrapper."""
    text = old.decode('utf-8')
    funcs = definitions(old)
    names = ['current_known_normal_root', 'current_tracked_source_trees', 'current_consume_materialization', 'current_verify_sources']
    need(all(name in funcs for name in names), 'ALL_ORIGINAL_FOUR_ADAPTER_METHODS')
    # Change only explicitly active field names/counts/source dispatch, not old
    # history bodies or historical contracts embedded in the original Root.
    for before, after in [
        ('CURRENT_M_SOURCE_CONTRACT', 'CURRENT_175F_M_SOURCE_CONTRACT'),
        ("'result_fields_exact30'", "'result_fields_exact35'"), ('len(setup) == 30', 'len(setup) == 35'),
        ("len(setup['commit_chain']) == 11", "len(setup['commit_chain']) == 19"),
        ("'committed_paths62'", "'committed_paths307'"), ("len(setup['committed_files']) == 62", "len(setup['committed_files']) == 307"),
        ("'source_requirements68'", "'source_requirements313'"),
        ("len(contract['source_requirements313'])==68", "len(contract['source_requirements313'])==313"),
        ("len({r['path'] for r in contract['source_requirements313']})==68", "len({r['path'] for r in contract['source_requirements313']})==313"),
        ("len(normal_pub['repository_Source_requirements'])==68", "len(normal_pub['repository_Source_requirements'])==313"),
        ("'complete_tracked_paths15504'", "'complete_tracked_paths15748'"), ('15504', '15748'), ('31008', '31496'),
        ("contract['derived_native_count'] == 277", "contract['derived_native_count'] == 1088"),
        ('len(joined)==69', 'len(joined)==314'), ("finite['SourceRows69']", "finite['SourceRows314']"),
        ("'SOURCE_CONTRACT_ACTUAL_CURRENT_M0_BOUND_A_POSTA_PENDING'", "'SOURCE_CONTRACT_CURRENT175F_M0_AND_REAL_A_SUCCESS_SOURCE_ONLY'"),
    ]:
        need(before in text, 'EXPECTED_ACTIVE_ADAPTER_SEAM')
        text = text.replace(before, after)
    text = one(text, "assert finite['repository_committed_files']==COMMITTED_FILES and len(COMMITTED_FILES)==3",
        "assert finite['repository_committed_files']==COMMITTED_FILES and len(COMMITTED_FILES)==4")
    marker = "    assert json.loads(file_pin(Path(contract['customer_prepare_failure_retirement_source']['path']),contract['customer_prepare_failure_retirement_source'])) == contract['customer_prepare_failure_retirement_expected']"
    extra = "\n    assert setup['current_paid_producer_failure_retirement'] == contract['paid_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['paid_failure_retirement_source']['path']),contract['paid_failure_retirement_source'])) == contract['paid_failure_retirement_expected']\n    assert setup['current_2e4_paid_producer_failure_retirement'] == contract['paid_2e4_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['paid_2e4_failure_retirement_source']['path']),contract['paid_2e4_failure_retirement_source'])) == contract['paid_2e4_failure_retirement_expected']\n    assert setup['current_881_paid_producer_failure_retirement'] == contract['paid_881_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['paid_881_failure_retirement_source']['path']),contract['paid_881_failure_retirement_source'])) == contract['paid_881_failure_retirement_expected']\n    assert setup['current_0c13_paid_producer_failure_retirement'] == contract['paid_0c13_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['paid_0c13_failure_retirement_source']['path']),contract['paid_0c13_failure_retirement_source'])) == contract['paid_0c13_failure_retirement_expected']\n    assert setup['current_28dc_paid_producer_failure_retirement'] == contract['paid_28dc_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['paid_28dc_failure_retirement_source']['path']),contract['paid_28dc_failure_retirement_source'])) == contract['paid_28dc_failure_retirement_expected']"
    text = one(text, marker, marker + extra)
    for name in sorted(names, key=len, reverse=True):
        text = text.replace(name, name.replace('current_', 'current175f_', 1))
    return ("CURRENT_175F_M_SOURCE_CONTRACT = " + repr(contract) + '\n\n' + text).encode('utf-8')


def php_leaf(raw, oldcu, newcu, oldoracle, neworacle):
    text = raw.decode('utf-8')
    for before, after in zip(oldcu, newcu):
        if before == after:
            continue
        left = "['path' => %r, 'bytes' => %d, 'sha256' => %r]" % (before['path'], before['bytes'], before['sha256'])
        right = "['path' => %r, 'bytes' => %d, 'sha256' => %r]" % (after['path'], after['bytes'], after['sha256'])
        text = one(text, left, right)
    before = next(r for r in oldcu if r['path'].endswith('/ms5-paid-consumer-normal.php'))
    after = next(r for r in newcu if r['path'] == before['path'])
    text = one(text, "[%d, %r]" % (before['bytes'], before['sha256']), "[%d, %r]" % (after['bytes'], after['sha256']))
    text = one(text, "%r => [%d, %r]" % (oldoracle['path'], oldoracle['bytes'], oldoracle['sha256']),
        "%r => [%d, %r]" % (neworacle['path'], neworacle['bytes'], neworacle['sha256']))
    need("const M4I_UI17_SOURCE_SEAL = %r;" % UNSEALED in text, 'PRESERVE_UNSEALED_INITIALIZER')
    return text.encode('utf-8')


def proof_successor(proof, current_raw, after, path):
    before = fixed(proof['before']['path'], proof['before'])
    replay = before
    for seam in proof['stages']:
        old, new, i = seam['before_utf8'].encode(), seam['after_utf8'].encode(), seam['stage_utf8_offset']
        need(pin(replay) == seam['whole_before'] and replay[i:i + len(old)] == old, 'ORIGINAL_COMPLETE_FORWARD_SEAM')
        replay = replay[:i] + new + replay[i + len(old):]
        need(pin(replay) == seam['whole_after'], 'ORIGINAL_COMPLETE_FORWARD_HASH')
    need(replay == current_raw, 'ORIGINAL_FULL_FORWARD_END')
    result = copy.deepcopy(proof)
    if current_raw != after:
        # A complete whole-file byte seam preserves/reverses all original stages;
        # it is Source proof, never a replacement business implementation.
        result['stages'].append({'stage_utf8_offset': 0, 'before_utf8': current_raw.decode('utf-8'),
            'after_utf8': after.decode('utf-8'), 'whole_before': pin(current_raw), 'whole_after': pin(after)})
    result['after'] = ptr(path, after)
    result['unchanged_chunks'] = []
    oldlines, newlines = before.splitlines(keepends=True), after.splitlines(keepends=True)
    for block in difflib.SequenceMatcher(None, oldlines, newlines, autojunk=False).get_matching_blocks():
        raw = b''.join(oldlines[block.a:block.a + block.size])
        if raw:
            result['unchanged_chunks'].append({'before_utf8_offset': sum(map(len, oldlines[:block.a])),
                'after_utf8_offset': sum(map(len, newlines[:block.b])), **pin(raw)})
    replay = after
    for seam in reversed(result['stages']):
        old, new, i = seam['before_utf8'].encode(), seam['after_utf8'].encode(), seam['stage_utf8_offset']
        need(replay[i:i + len(new)] == new, 'FULL_REVERSE_SEAM')
        replay = replay[:i] + old + replay[i + len(new):]
    need(replay == before, 'FULL_REVERSE_ORIGINAL_BYTES')
    return result


def refresh_browser(browser, oldoracle, neworacle):
    """Exactly two literal descriptors plus the complete data-contract field."""
    original = dict(browser)
    old = "bytes:%d,sha256:%r" % (oldoracle['bytes'], oldoracle['sha256'])
    new = "bytes:%d,sha256:%r" % (neworacle['bytes'], neworacle['sha256'])
    for name in ['ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs']:
        browser[name] = one(browser[name].decode('utf-8'), old, new).encode('utf-8')
    name = 'ui-business-consumer-contract.json'
    data = json.loads(browser[name])
    need(data['php_sources'][ORACLE_PATH] == {k: oldoracle[k] for k in ['bytes', 'sha256']}, 'ORIGINAL_BROWSER_ORACLE_DESCRIPTOR')
    data['php_sources'][ORACLE_PATH] = {k: neworacle[k] for k in ['bytes', 'sha256']}
    browser[name] = enc(data)
    need([n for n in browser if browser[n] != original[n]] == ['ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs', 'ui-business-consumer-contract.json'], 'EXACT_BROWSER_THREE_CHANGED_AND_OTHER_FIVE_WHOLE_BYTES')
    return browser


def refresh_ui_record(record, vector, oldoracle, neworacle, newcu, coreptr, browserptr, adoption):
    need(set(record['ui_source_vector8']) == set(vector) and len(vector) == 8, 'ORIGINAL_COMPLETE_UI_VECTOR_EIGHT')
    observer = record['UI_r4_contract']['CU_independent_private_window']['observer']
    need({k: observer[k] for k in ['bytes', 'sha256']} == {k: oldoracle[k] for k in ['bytes', 'sha256']}, 'ORIGINAL_UI_R4_OBSERVER_WHOLE_FILE_PIN')
    observer.update(bytes=neworacle['bytes'], sha256=neworacle['sha256'])
    record.update(source_sha=HEAD, parent=PARENT, ui_source_vector8=copy.deepcopy(vector),
        customer_form_sources7=copy.deepcopy(newcu), browser8_manifest=browserptr,
        CoreAux_authority=coreptr, actual_adoption_authority=adoption)


def build(args):
    output = Path(args.output)
    need(output.is_absolute() and not output.exists(), 'NEW_EXTERNAL_OUTPUT_ONLY')
    need(output.parent.resolve() == BASE.resolve(), 'OUTPUT_MUST_BE_NEW_DIRECT_EXTERNAL_SOURCE_PACKAGE')
    inputs = json.loads(Path(args.actual_public_inputs).read_bytes())
    apinsraw = fixed(A / 'PINS.json', CURRENT_A_SOURCE_PINS)
    apins = json.loads(apinsraw)
    need(apins['source_sha'] == HEAD and len(apins['source_members18']) == 18, 'CURRENT_COMPLETE_A_SOURCE')
    for name in apins['source_members18']:
        fixed(A / name, apins['files'][name])
    mraw = fixed(A / 'materializer/prepare.py', apins['files']['materializer/prepare.py'])
    mv = constants(mraw)
    m, context = validate_success(inputs, mv, apins)
    normalpubraw = fixed(A / 'validation/PROFILE-PUBLICATION.json', apins['files']['validation/PROFILE-PUBLICATION.json'])
    normalpub = json.loads(normalpubraw)
    normalpins = (A / 'validation/PROFILE-PINS.json').read_bytes()
    normalsourcepins = (A / 'validation/PINS.json').read_bytes()
    normalroot = json.loads(normalsourcepins)
    ainterface = json.loads(fixed(A / 'validation/actual-profile/interface-v1.json', apins['files']['validation/actual-profile/interface-v1.json']))
    oldpub = json.loads((OLD / 'candidate/PUBLICATION.json').read_bytes())
    selected = [r['path'] for r in oldpub['selected17']]
    need(len(selected) == len(set(selected)) == 17, 'FULL_ORIGINAL_SELECTED17')
    original = {name: (OLD / 'candidate' / name).read_bytes() for name in selected}
    out = dict(original)
    browserpins = json.loads((OLD / 'browser-v1/PINS.json').read_bytes())
    browser = {name: fixed(OLD / 'browser-v1' / name, row) for name, row in browserpins['members'].items()}
    need(len(browser) == 8, 'COMPLETE_ORIGINAL_BROWSER8')
    oldbrowser = dict(browser)
    oldbrowserraw = (OLD / 'browser-v1/PINS.json').read_bytes()
    oldsets = json.loads((OLD / 'CURRENT-SETS.json').read_bytes())
    oldcu = oldsets['CU7']
    newcu = ainterface['customer_form_current_profile']['php_sources']
    need(len(newcu) == len(oldcu) == 7 and sum(a != b for a, b in zip(oldcu, newcu)) == 4, 'EXACT_FOUR_CHANGED_CU_ROWS')
    core = json.loads((OLD / 'current-core-aux/CORE-AUX-AUTHORITY.json').read_bytes())
    oldoracle = oldsets['source_vector8'][ORACLE_PATH]
    requirement_map = {r['path']: r for r in normalpub['repository_Source_requirements']}
    need(len(requirement_map) == 313, 'ALL_CURRENT_NORMAL313')
    neworacle = requirement_map[ORACLE_PATH]
    need(set(oldsets['source_vector8']) - set(requirement_map) == {'help/audit-regressions/isolated/lib/ms4-ui-credentials.php', 'help/audit-regressions/isolated/ms4-ui-backend.php', 'help/migrations/marketplace-ms4-order-read-menus.php', 'help/audit-regressions/isolated/ms4-ui-credential-meta.php'}, 'EXACT_ORIGINAL_FOUR_UI_VECTOR_ONLY_SOURCE_ROWS')
    vector = {p: requirement_map[p] if p in requirement_map else oldsets['source_vector8'][p] for p in oldsets['source_vector8']}
    need([p for p in vector if vector[p] != oldsets['source_vector8'][p]] == [ORACLE_PATH], 'EXACT_ONE_CURRENT_UI_ORACLE_DESCRIPTOR_CHANGE')
    browser = refresh_browser(browser, oldoracle, neworacle)
    browserraw = enc({'members': {name: pin(raw) for name, raw in browser.items()}})
    core['core_sources'] = [vector[r['path']] for r in core['core_sources']]
    need(len(core['core_sources']) == 3 and {r['path']: {k: r[k] for k in ['bytes', 'sha256']} for r in core['core_sources']} == json.loads(browser['ui-business-consumer-contract.json'])['php_sources'], 'CLOSED_CURRENT_CORE3_BROWSER')
    core['source_sha'] = HEAD
    core['browser_manifest'] = ptr(output / 'browser-v1/PINS.json', browserraw)
    coreraw = enc(core)
    coreptr = ptr(output / 'current-core-aux/CORE-AUX-AUTHORITY.json', coreraw)
    joined = dict(requirement_map)
    for row in core['core_sources']:
        need(row['path'] not in joined or joined[row['path']] == row, 'CORE_EXACT_DESCRIPTOR_JOIN')
        joined[row['path']] = row
    need(len(joined) == 314 and set(joined) - set(requirement_map) == {'help/audit-regressions/isolated/ms4-ui-backend.php'}, 'EXACT_UI314')
    oldcontract = constants((OLD / 'root-source/root-ui-materialize-freeze.py').read_bytes())['CURRENT_M_SOURCE_CONTRACT']
    contract = copy.deepcopy(oldcontract)
    for key in ['committed_paths62', 'source_requirements68', 'complete_tracked_paths15504', 'result_fields_exact30']:
        contract.pop(key)
    snapshot = json.loads(deref(m['complete_source_snapshot']))
    mainroot = Path(mv['MAIN_SOURCE'])
    tracked = sorted(Path(path).relative_to(mainroot).as_posix() for path in snapshot if Path(path).is_relative_to(mainroot))
    need(len(snapshot) == 31496 and len(tracked) == len(set(tracked)) == 15748, 'FULL_CURRENT_DUAL_METADATA')
    for root in [mainroot, Path(mv['VALIDATION_SOURCE'])]:
        for path, row in vector.items():
            actual = snapshot[(root / path).as_posix()]
            need(actual['path'] == (root / path).as_posix() and {k: actual[k] for k in ['bytes', 'sha256']} == {k: row[k] for k in ['bytes', 'sha256']}, 'ALL_EIGHT_CURRENT_UI_SOURCE_ROWS_JOIN_ACTUAL_DUAL_METADATA')
    contract.update(status='SOURCE_CONTRACT_CURRENT175F_M0_AND_REAL_A_SUCCESS_SOURCE_ONLY', source_sha=HEAD, parent=PARENT,
        commit_chain=m['commit_chain'], committed_paths307=mv['COMMITTED_PATHS'], source_requirements313=normalpub['repository_Source_requirements'],
        complete_tracked_paths15748=tracked, result_fields_exact35=mv['CURRENT_M_RESULT_KEYS35'], source_patch_base=m['source_patch_base'],
        native_commands=[{'label': row['label'], 'argv': row['argv']} for row in m['native_operations']], derived_native_count=len(m['native_operations']),
        selected9=m['selected9'], source_counts=m['source_counts'], tracked_files=15748, dual_tracked_snapshot=31496,
        A_source_PINS=ptr(A / 'PINS.json', apinsraw), final_eligible_A_Source=ptr(A / 'PINS.json', apinsraw),
        M_Source=ptr(A / 'materializer/prepare.py', mraw), M_result=inputs['M_result'], M_outcome=inputs['M_outcome'],
        M_materialize_source_copy=m['materialize_source'], M_native_operations=m['native_operations'],
        M_root_snapshot=m['root_source_snapshot'], M_complete_snapshot=m['complete_source_snapshot'],
        M_authority_input_snapshot=ptr(A / 'validation/materialize-freeze-assets-r1/AUTHORITY-INPUT-SNAPSHOT.json', (A / 'validation/materialize-freeze-assets-r1/AUTHORITY-INPUT-SNAPSHOT.json').read_bytes()),
        M_freeze={'path': m['freeze'], 'bytes': m['freeze_bytes'], 'sha256': m['freeze_sha256']},
        M_launcher_argv=inputs['M_outcome']['created_object']['argv'], M_Root_raw_readback=inputs['M_root_readback'],
        A_outcome=inputs['A_outcome'], A_run_context=inputs['A_context'], A_outer_session=inputs['A_outer_session'],
        A_worker_close=inputs['A_qualification']['worker_close'], A_independent_zero=inputs['A_qualification']['independent_zero'],
        post_A_history=inputs['A_qualification']['post_a_history'],
        pinned_candidate_A_Source_status='CURRENT175F_REAL_A_SUCCESS_INPUTS_BOUND_B_JOINT_SOURCE_REVIEW_PENDING',
        A_source_review_authority=inputs['Source_review_authority'],
        historical53e_contract=ptr(OLD / 'CURRENT-M-SOURCE-CONTRACT.json', (OLD / 'CURRENT-M-SOURCE-CONTRACT.json').read_bytes()))
    for field, expected, sourcekey, sourceconstant in [
        ('prior_retirement', 'prior_retirement_expected', None, None),
        ('current_driver_failure_retirement', 'driver_failure_retirement_expected', None, None),
        ('current_early_failure_retirement', 'early_failure_retirement_expected', 'early_failure_retirement_source', 'CURRENT_EARLY_FAILURE_RETIREMENT'),
        ('current_customer_prepare_failure_retirement', 'customer_prepare_failure_retirement_expected', 'customer_prepare_failure_retirement_source', 'CURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT'),
        ('current_paid_producer_failure_retirement', 'paid_failure_retirement_expected', 'paid_failure_retirement_source', 'CURRENT_PAID_PRODUCER_FAILURE_RETIREMENT'),
        ('current_2e4_paid_producer_failure_retirement', 'paid_2e4_failure_retirement_expected', 'paid_2e4_failure_retirement_source', 'CURRENT_2E4_PAID_PRODUCER_FAILURE_RETIREMENT'),
        ('current_881_paid_producer_failure_retirement', 'paid_881_failure_retirement_expected', 'paid_881_failure_retirement_source', 'CURRENT_881_PAID_PRODUCER_FAILURE_RETIREMENT'),
        ('current_0c13_paid_producer_failure_retirement', 'paid_0c13_failure_retirement_expected', 'paid_0c13_failure_retirement_source', 'CURRENT_0C13_PAID_PRODUCER_FAILURE_RETIREMENT'),
        ('current_28dc_paid_producer_failure_retirement', 'paid_28dc_failure_retirement_expected', 'paid_28dc_failure_retirement_source', 'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT')]:
        contract[expected] = m[field]
        if sourcekey:
            contract[sourcekey] = mv[sourceconstant]
    contract['historical_retirement_names_remain_distinct'] = ['prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement']
    need(len(contract['historical_retirement_names_remain_distinct']) == 9 and json.loads(deref(contract['paid_28dc_failure_retirement_source'])) == contract['paid_28dc_failure_retirement_expected'], 'NINE_DISTINCT_COMPLETE_RETIREMENT_AUTHORITIES')
    contractraw = enc(contract)
    contractptr = ptr(output / 'CURRENT-M-SOURCE-CONTRACT.json', contractraw)
    interface = json.loads(original['interface-v1.json'])
    for name, row in [('paid_producer_successor', next(r for r in newcu if r['path'].endswith('/ms5-paid-consumer-normal.php'))), ('fulfillment_normal_successor', next(r for r in newcu if r['path'].endswith('/ms4-fulfillment-normal.php')))]:
        interface[name]['entry_bytes'], interface[name]['entry_sha256'] = row['bytes'], row['sha256']
    interface['customer_form_current_profile']['php_sources'] = newcu
    interface['customer_form_current_profile']['current_composite_php_source_authority'] = copy.deepcopy(ainterface['customer_form_current_profile']['current_composite_php_source_authority'])
    for key in ['assertion_prefix_successor', 'normal_http_diagnostic_successor', 'owned_upload_root_current_source_successor', 'quota_wire_current_source_successor']:
        interface['customer_form_current_profile'][key] = copy.deepcopy(ainterface['customer_form_current_profile'][key])
    record = interface['fresh_B_current_UI_profile_source']
    refresh_ui_record(record, vector, oldoracle, neworacle, newcu, coreptr, core['browser_manifest'], mv['CURRENT_QUOTA_WIRE_SOURCE_HISTORY'])
    record['historical_previous53e_UI_projection'] = ptr(OLD / 'candidate/interface-v1.json', original['interface-v1.json'])
    # Only an invocation after actual A0 can reach this point. This author is
    # currently unexecuted; the template remains null. Real A0 still does not
    # qualify or seal this B Source.
    need(all(record[k] is None for k in ['A_outer_exit', 'A_worker_close', 'A_independent_zero', 'A_post_history']), 'ORIGINAL_PROFILE_FUTURE_FIELDS_ABSENT')
    record.update(A_outer_exit=0, A_worker_close=inputs['A_qualification']['worker_close'],
        A_independent_zero=inputs['A_qualification']['independent_zero'], A_post_history=inputs['A_qualification']['post_a_history'])
    out['interface-v1.json'] = enc(interface)
    paid = next(r for r in newcu if r['path'].endswith('/ms5-paid-consumer-normal.php'))
    fulfill = next(r for r in newcu if r['path'].endswith('/ms4-fulfillment-normal.php'))
    for name in ['ms4-host.py', 'verify-zero-ms4-fulfillment.py']:
        for key, value in [('CUSTOMER_FORM_SOURCE_FILES', newcu), ('PAID_ENTRY_BYTES', paid['bytes']), ('PAID_ENTRY_SHA256', paid['sha256']),
                           ('FULFILLMENT_ENTRY_BYTES', fulfill['bytes']), ('FULFILLMENT_ENTRY_SHA256', fulfill['sha256']), ('UI_CURRENT_SOURCE_PINS', vector)]:
            out[name] = replace_assign(out[name], key, value)
    out['ms4-initialize.php'] = php_leaf(out['ms4-initialize.php'], oldcu, newcu, oldoracle, neworacle)
    need(pin(out['ms4-initialize.php']) == {k: inputs['initializer_leaf'][k] for k in ['bytes', 'sha256']}, 'EXACT_ROOT_REVIEWED_CURRENT_CU4_PLUS_ORACLE_INITIALIZER_LEAF')
    need(deref(inputs['initializer_leaf']) == out['ms4-initialize.php'], 'SAME_ROOT_LEAF_FULL_BYTES')
    out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], 'UI_AUX_SOURCE_PIN', pin(browserraw))
    out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], 'UIZB_DATA_PIN', pin(browser['ui-business-consumer-contract.json']))
    out['ms4-host.py'] = replace_assign(out['ms4-host.py'], 'INTERFACE_SHA256', pin(out['interface-v1.json'])['sha256'])
    for key in ['HOST_SHA256', 'UI_HOST_SHA256']:
        out['normal-close-ms4-fulfillment.py'] = replace_assign(out['normal-close-ms4-fulfillment.py'], key, pin(out['ms4-host.py'])['sha256'])
        out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], key, pin(out['ms4-host.py'])['sha256'])
    for key in ['CLOSE_SHA256', 'UI_CLOSE_SHA256']:
        out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], key, pin(out['normal-close-ms4-fulfillment.py'])['sha256'])
    for key in ['INTERFACE_SHA256', 'UI_INTERFACE_SHA256']:
        out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], key, pin(out['interface-v1.json'])['sha256'])
    for key in ['INTERFACE_BYTES', 'UI_INTERFACE_BYTES']:
        out['verify-zero-ms4-fulfillment.py'] = replace_assign(out['verify-zero-ms4-fulfillment.py'], key, len(out['interface-v1.json']))
    for name in CLI:
        need(constants(out[name])['UI17_SOURCE_SEAL'] == UNSEALED, 'KEEP_UNSEALED_SOURCE_ENTRY')
        need(definitions(out[name]) == definitions(original[name]), 'ALL_ORIGINAL_PROFILE_FUNCTIONS_EXACT')
    out['README.md'] += b'\n\nCurrent175f Source successor draft. Real successful A inputs supplied to author do not seal B entries. Independent H5 nine-scenes remains separate; all original UI20/CU/DOM/media scope retained. NOT EXECUTED.\n'
    derivation = json.loads(out['source-derivation.json'])
    projection = derivation['fresh_B_customer_form_external_current_source']
    refresh_ui_record(projection, vector, oldoracle, neworacle, newcu, coreptr, core['browser_manifest'], mv['CURRENT_QUOTA_WIRE_SOURCE_HISTORY'])
    projection['normal_source_profile'] = {'root': (A / 'validation/actual-profile').as_posix(), 'PROFILE_PUBLICATION': ptr(A / 'validation/PROFILE-PUBLICATION.json', normalpubraw)}
    projection.update(A_outer_exit=0, A_worker_close=inputs['A_qualification']['worker_close'], A_independent_zero=inputs['A_qualification']['independent_zero'], A_post_history=inputs['A_qualification']['post_a_history'])
    derivation['current_nonself_artifacts'] = [{'path': name, **pin(out[name])} for name in selected if name != 'source-derivation.json']
    derivation['fresh_B_current175f_source_successor'] = {'source_sha': HEAD, 'parent': PARENT, 'current_A_Source': ptr(A / 'PINS.json', apinsraw), 'current_M_contract': contractptr,
        'predecessor': ptr(OLD / 'candidate/source-derivation.json', original['source-derivation.json']), 'all_original_history_unchanged': True, 'admission': 'UNSEALED_NOT_EXECUTED'}
    out['source-derivation.json'] = enc(derivation)
    publication = copy.deepcopy(oldpub)
    publication.update(source_sha=HEAD, parent=PARENT, selected17=[{'path': n, **pin(out[n])} for n in selected], repository_Source_requirements=normalpub['repository_Source_requirements'])
    finite = publication['finite_contexts']
    latest = [{'path': r['path'], **r['after']} for r in mv['COMMIT_CHAIN'][-1]['files']]
    need(len(latest) == 4, 'ACTUAL_LAST_COMMIT_FOUR_NOT_CUMULATIVE307')
    finite.update(repository_committed_files=latest, current_core_aux_authority=coreptr, current_Core3=core['core_sources'], browser_manifest=core['browser_manifest'], SourceRows314=list(joined.values()),
        normal9_authority={'root': (A / 'validation').as_posix(), 'profile_root': (A / 'validation/actual-profile').as_posix(), 'PROFILE_PINS': ptr(A / 'validation/PROFILE-PINS.json', normalpins), 'PROFILE_PUBLICATION': ptr(A / 'validation/PROFILE-PUBLICATION.json', normalpubraw)}, current_M_source_contract=contractptr)
    finite['ordered_auxiliary_members'] = [{'path': name, **pin(raw)} for name, raw in browser.items()]
    finite.pop('SourceRows69')
    proof_files = {}
    for name in selected:
        proof_files[name] = (original[name], out[name], output / 'candidate' / name)
    for name in browser:
        proof_files['browser-v1/' + name] = (oldbrowser[name], browser[name], output / 'browser-v1' / name)
    proof_files['browser-v1/PINS.json'] = (oldbrowserraw, browserraw, output / 'browser-v1/PINS.json')
    need(len(proof_files) == len(finite['whole_forward_inverse_files']) == 26, 'FULL_ORIGINAL26_PROOF_COVERAGE')
    finite['whole_forward_inverse_files'] = [proof_successor(p, *proof_files[p['name']]) for p in finite['whole_forward_inverse_files']]
    pubraw = enc(publication)
    design = (Path(__file__).parent / 'DESIGN-NOTES.md').read_bytes()
    profilepins = {'schema': 1, 'scope': 'R40_UI17_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL', 'members': {n: pin(raw) for n, raw in out.items()}}
    profilepins['members'].update({'DESIGN.md': pin(design), 'PUBLICATION.json': pin(pubraw)})
    profilepinsraw = enc(profilepins)
    ri = json.loads((OLD / 'root-source/INPUTS.json').read_bytes())
    ri.update(sealed=False, head=HEAD, parent=PARENT, committed_files=latest, current_core_aux_authority=coreptr,
        A_QUALIFICATION=inputs['A_qualification'], A_OUTER_SESSION=inputs['A_outer_session'],
        post_a_history_contract=inputs['post_A_history_contract'])
    ri['normal_root'].update(root=(A / 'validation').as_posix(), PINS=ptr(A / 'validation/PINS.json', normalsourcepins), source_members=normalroot['members'],
        known_A_identity={'scope': 'IDENTITY_ONLY_NOT_SUCCESS', 'run_id': context['run_id'], 'source_sha': HEAD, 'freeze_sha256': m['freeze_sha256'], 'freeze_bytes': m['freeze_bytes'], 'context': inputs['A_context']}, materialization_result=inputs['M_result'])
    ri['normal_profile_publication'] = {'root': (A / 'validation').as_posix(), 'profile_root': (A / 'validation/actual-profile').as_posix(),
        'PINS': ptr(A / 'validation/PROFILE-PINS.json', normalpins), 'PUBLICATION': ptr(A / 'validation/PROFILE-PUBLICATION.json', normalpubraw)}
    ri['ui_profile_publication'] = {'root': (output / 'candidate').as_posix(),
        'PINS': ptr(output / 'candidate/PINS.json', profilepinsraw), 'PUBLICATION': ptr(output / 'candidate/PUBLICATION.json', pubraw)}
    # This distinct B review is not A's review. Never impersonate the final
    # joint Source reviewer or seal Root merely because A0 inputs exist.
    need(ri['source_review_authority'] is None and ri['sealed'] is False, 'NO_AUTOMATIC_ROOT_ADMISSION')
    riraw = enc(ri)
    rmold = (OLD / 'root-source/root-ui-materialize-freeze.py').read_bytes()
    rsold = (OLD / 'root-source/root-ui-start-to-ready.py').read_bytes()
    rm, rs = rmold, rsold
    assignments = [('HEAD', HEAD), ('PREVIOUS', HEAD), ('COMMIT_PARENT', PARENT), ('COMMITTED_FILES', latest), ('COMMITTED_PATHS', {r['path'] for r in latest}),
        ('SOURCE_ROWS', joined), ('NORMAL_SOURCE_PINS', normalroot['members']), ('ROOT_INPUTS_PIN', pin(riraw)),
        ('OLD_CLI_PINS', {r['path']: r['sha256'] for r in m['selected9'] if r['path'] in CLI}),
        ('OLD_COORDINATOR_SHA', apins['files']['validation/run.py']['sha256']),
        ('A_QUALIFICATION', inputs['A_qualification']), ('A_OUTER_SESSION', inputs['A_outer_session']),
        ('POST_A_HISTORY_CONTRACT', inputs['post_A_history_contract']), ('OLD_RUN', context['run_id']),
        ('OLD_FREEZE', m['freeze_sha256']), ('OLD_FREEZE_BYTES', m['freeze_bytes']),
        ('OLD_CONTEXT_PIN', {k: inputs['A_context'][k] for k in ['bytes', 'sha256']}), ('NORMAL_SETUP_PIN', inputs['M_result'])]
    for key, value in assignments:
        rm = replace_assign(rm, key, value)
    for key, path in [('NORMAL_SOURCE', A / 'validation'), ('PROFILE_SOURCE', output / 'candidate'), ('RUNTIME', output / 'runtime')]:
        rm = replace_assign(rm, key, expression='Path(' + repr(path.as_posix()) + ')')
    fragment = new_adapter((OLD / 'root-current-adapter.fragment.py').read_bytes(), contract)
    rm = append_before_entry(rm, fragment)
    old_m_main = definitions(rmold)['main']
    current_m_main = one(old_m_main, 'def main(', 'def current175f_main(')
    current_m_main = one(current_m_main,
        'len(committed) == len(COMMITTED_PATHS) == 3',
        'len(committed) == len(COMMITTED_PATHS) == 4')
    need(current_m_main.replace('def current175f_main(', 'def main(', 1).replace(
        'len(committed) == len(COMMITTED_PATHS) == 4',
        'len(committed) == len(COMMITTED_PATHS) == 3', 1) == old_m_main,
        'ONLY_CURRENT_M_MAIN_COMMITTED_FOUR_GUARD_CHANGED')
    rm = append_before_entry(rm, (current_m_main + '\n\nmain=current175f_main\n').encode('utf-8'))
    for key, value in [('SOURCE', HEAD), ('ROOT_INPUTS_PIN', pin(riraw)), ('CURRENT_CORE_AUX_AUTHORITY_PIN', coreptr),
        ('PROFILE_ARTIFACTS', [{'artifact': n, **pin(out[n])} for n in selected]), ('PINS', {n: pin(out[n])['sha256'] for n in CLI}), ('SOURCE_BYTES', {n: len(out[n]) for n in CLI}),
        ('A_QUALIFICATION', inputs['A_qualification']), ('A_OUTER_SESSION', inputs['A_outer_session']), ('POST_A_HISTORY_CONTRACT', inputs['post_A_history_contract'])]:
        rs = replace_assign(rs, key, value)
    rs = replace_assign(rs, 'RUNTIME', expression='Path(' + repr((output / 'runtime').as_posix()) + ')')
    olddefs = definitions(rsold)
    newmethods = []
    for name in ['current_core_aux_authority', 'main']:
        body = olddefs[name]
        body = one(body, 'def ' + name + '(', 'def current175f_' + name + '(')
        body = body.replace("'2fa872e6fda7a8bf46c55f88dd946b972ec79102'", repr(PARENT))
        body = body.replace((BASE / 'full-a-raw-env-normal-next-source-r1/validation').as_posix(), (A / 'validation').as_posix())
        if name == 'current_core_aux_authority':
            body = one(body, "len(inputs['committed_files']) == 3", "len(inputs['committed_files']) == 4")
            body = one(body,
                "ROOT_SOURCE.parent / 'r29-ui-current-core-aux-source-r1/browser-v1/PINS.json'",
                "ROOT_SOURCE.parent / 'browser-v1/PINS.json'")
            body = one(body, "'bytes':1222,'sha256':'83b9267b2759b9bf8b120e734f2c4f595ce01f08743c96ed93ada98e403bda7d'",
                "'bytes':%d,'sha256':%r" % (len(browserraw), pin(browserraw)['sha256']))
            body = one(body, "len(normal_pub['repository_Source_requirements']) == 68",
                "len(normal_pub['repository_Source_requirements']) == 313")
            body = one(body, "len(joined) == 69 and list(joined.values()) == finite['SourceRows69']",
                "len(joined) == 314 and list(joined.values()) == finite['SourceRows314']")
        newmethods.append(body)
    s_extra = '\n\n'.join(newmethods) + '\n\ncurrent_core_aux_authority=current175f_current_core_aux_authority\nmain=current175f_main\n'
    rs = append_before_entry(rs, s_extra.encode('utf-8'))
    need(all(definitions(rm).get(n) == s for n, s in definitions(rmold).items()), 'EVERY_ORIGINAL_M_DEFINITION_EXACT')
    need(all(definitions(rs).get(n) == s for n, s in definitions(rsold).items()), 'EVERY_ORIGINAL_S_DEFINITION_EXACT')
    marker = "    require(stage('db-up')"
    need(marker in olddefs['main'] and newmethods[1][newmethods[1].index(marker):] == olddefs['main'][olddefs['main'].index(marker):], 'FULL_ORIGINAL_DBUP_TO_READY_SUFFIX')
    ast.parse(rm.decode('utf-8')); ast.parse(rs.decode('utf-8'))
    root = {'DESIGN-TASKS.md': (OLD / 'root-source/DESIGN-TASKS.md').read_bytes() + b'\n\nCurrent175f strict Source-only wrapper, M35/UI314; all admission guards remain unsealed. Full original functions/suffix retained.\n', 'INPUTS.json': riraw, 'root-ui-materialize-freeze.py': rm, 'root-ui-start-to-ready.py': rs}
    rootpins = enc({'schema': 1, 'scope': 'R40_FIRST_B_ROOT_SOURCE_PINS', 'members': {n: pin(r) for n, r in root.items()}})
    payload = {'candidate/' + n: raw for n, raw in out.items()}
    payload.update({'candidate/DESIGN.md': design, 'candidate/PINS.json': profilepinsraw, 'candidate/PUBLICATION.json': pubraw,
        'browser-v1/PINS.json': browserraw, 'current-core-aux/CORE-AUX-AUTHORITY.json': coreraw, 'CURRENT-M-SOURCE-CONTRACT.json': contractraw,
        'root-current-adapter.fragment.py': fragment, 'root-source/PINS.json': rootpins, 'ACTUAL-A-INPUTS-SOURCE-ONLY.json': enc(inputs),
        'DRAFT-STATE.json': enc({'status': 'UNSEALED_AUTHOR_DRAFT_NOT_QUALIFIED_OR_EXECUTED', 'source_sha': HEAD, 'A_input_is_real_but_joint_Source_review_pending': True, 'Root_inputs_sealed': False, 'all_four_UI_entry_guards': 'UNSEALED', 'target_execution': 'NOT RUN', 'final_outer_PINS_REPORT': 'NOT CREATED'})})
    payload.update({'browser-v1/' + n: raw for n, raw in browser.items()})
    payload.update({'root-source/' + n: raw for n, raw in root.items()})
    # Preserve/rebind both original membership Source seams. Root must separately
    # qualify this exact new leaf for syntax after live A close/zero, before invocation.
    membership = json.loads((OLD / 'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json').read_bytes())
    before_leaf = php_leaf(fixed(membership['checkpoint'], membership['before']), oldcu, newcu, oldoracle, neworacle)
    rollback = out['ms4-initialize.php'].decode('utf-8')
    for seam in reversed(membership['seams']):
        rollback = one(rollback, seam['replacement'], seam['original'])
    need(rollback.encode('utf-8') == before_leaf, 'EXACT_BOTH_MEMBERSHIP_SEAMS_ONLY')
    membership.update(before=pin(before_leaf), candidate=pin(out['ms4-initialize.php']),
        checkpoint=(output / 'optimization-before-current175f/ms4-initialize.php').as_posix(),
        historical_previous53e_proof=ptr(OLD / 'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json', (OLD / 'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json').read_bytes()),
        initializer_exact_Root_source_leaf=ROOT_INITIALIZER_LEAF,
        exact_new_leaf_syntax_requires_prior_Root_qualification=True,
        syntax_not_manifest_or_performance=True, B_joint_Source_seal='UNSEALED')
    freeze = json.loads(fixed(m['freeze'], {'bytes': m['freeze_bytes'], 'sha256': m['freeze_sha256']}))
    membership['models'] = []
    for leftkey, rightkey in [('customer_source_files', 'fulfillment_source_files'), ('fulfillment_source_files', 'aftersale_review_source_files')]:
        left, right = freeze[leftkey], freeze[rightkey]
        need(all(type(r['path']) is str for r in left + right), 'COMPLETE_STRING_PATHS')
        keys = {'path:' + r['path']: True for r in left}
        filtered = [r for r in right if type(r['path']) is str and 'path:' + r['path'] in keys]
        need(filtered == left and len(keys) == len(left), 'SAME_FULL_ORDER_DESCRIPTORS_MEMBERSHIP_MODEL')
        membership['models'].append({'left_group': leftkey, 'right_group': rightkey,
            'left_count': len(left), 'right_count': len(right), 'original_result': pin(enc(left)),
            'lookup_model_result': pin(enc(filtered)), 'complete_order_and_all_descriptors_equal': True,
            'proof_kind': 'PURE_PUBLIC_M_METADATA_ONLY_NOT_PHP_MANIFEST_OR_PERFORMANCE'})
    payload.update({'optimization-before-current175f/ms4-initialize.php': before_leaf,
        'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json': enc(membership),
        'INITIALIZER-MEMBERSHIP-DIFF.patch': ''.join(difflib.unified_diff(before_leaf.decode('utf-8').splitlines(keepends=True), out['ms4-initialize.php'].decode('utf-8').splitlines(keepends=True), fromfile='a/ms4-initialize.php', tofile='b/ms4-initialize.php')).encode('utf-8'),
        'INITIALIZER-MEMBERSHIP-REVERSE.patch': ''.join(difflib.unified_diff(out['ms4-initialize.php'].decode('utf-8').splitlines(keepends=True), before_leaf.decode('utf-8').splitlines(keepends=True), fromfile='b/ms4-initialize.php', tofile='a/ms4-initialize.php')).encode('utf-8')})
    sets = copy.deepcopy(oldsets)
    sets.update(source_sha=HEAD, CU7=newcu, source_vector8=copy.deepcopy(vector))
    need(sets['browser8'] == list(browser), 'KEEP_ALL_ORIGINAL_EIGHT_BROWSER_NAMES')
    payload['CURRENT-SETS.json'] = enc(sets)
    payload['CURRENT-A-SOURCE-BINDING.json'] = enc({'schema': 1, 'scope': 'REAL_SAME28DC_A_SUCCESS_INPUTS_BOUND_SOURCE_ONLY_B_NOT_SEALED',
        'root': A.as_posix(), 'normal_profile_candidate': (A / 'validation/actual-profile').as_posix(),
        'Source_PINS': pin(apinsraw), 'publication': pin(normalpubraw), 'M_Source': pin(mraw),
        'S_Source': {k: apins['files']['validation/run.py'][k] for k in ['bytes', 'sha256']}, 'Root_PINS': pin(normalsourcepins),
        'M0': inputs['M_result'], 'A_qualification': inputs['A_qualification'], 'predecessor53e': ptr(OLD / 'CURRENT-A-SOURCE-BINDING.json', (OLD / 'CURRENT-A-SOURCE-BINDING.json').read_bytes())})
    payload['FUNCTION-PRESERVATION.json'] = enc({'previous53e': ptr(OLD / 'FUNCTION-PRESERVATION.json', (OLD / 'FUNCTION-PRESERVATION.json').read_bytes()),
        'profile_all_original_definitions_exact': {name: True for name in CLI},
        'M_all_original_definitions_exact': True, 'S_all_original_definitions_exact': True,
        'new_wrappers_only': ['current175f_known_normal_root', 'current175f_tracked_source_trees', 'current175f_consume_materialization', 'current175f_verify_sources', 'current175f_current_core_aux_authority', 'current175f_main'],
        'new_M_main_wrapper_only': 'current175f_main_with_exact_last_commit4_guard',
        'S_original_dbup_to_READY_suffix_exact': True})
    transforms, forward, reverse = [], [], []
    oldtransforms = json.loads((OLD / 'SOURCE-TRANSFORMS.json').read_bytes())
    for row in oldtransforms:
        name = row['path']
        oldpath = OLD / name if name.startswith(('browser-v1/', 'current-core-aux/', 'root-source/')) else OLD / 'candidate' / name
        newname = name if name.startswith(('browser-v1/', 'current-core-aux/', 'root-source/')) else 'candidate/' + name
        before, after = fixed(oldpath, row['candidate']), payload[newname]
        transforms.append({'path': name, 'before': pin(before), 'candidate': pin(after), 'changed': before != after,
            'whole_forward_reverse': 'PENDING_INDEPENDENT_PATCH_REPLAY_NOT_CLAIMED_BY_AUTHOR_DRAFT'})
        if before != after:
            forward.append(''.join(difflib.unified_diff(before.decode('utf-8').splitlines(keepends=True), after.decode('utf-8').splitlines(keepends=True), fromfile='a/' + name, tofile='b/' + name)))
            reverse.append(''.join(difflib.unified_diff(after.decode('utf-8').splitlines(keepends=True), before.decode('utf-8').splitlines(keepends=True), fromfile='b/' + name, tofile='a/' + name)))
    need(len(transforms) == 31, 'KEEP_ALL31_ORIGINAL_TRANSFORM_MEMBERS')
    payload.update({'SOURCE-TRANSFORMS.json': enc(transforms), 'DIFF.patch': ''.join(forward).encode('utf-8'), 'REVERSE.patch': ''.join(reverse).encode('utf-8'),
        'SOURCE-QUALIFICATION.json': enc({'schema': 1, 'status': 'UNSEALED_AUTHOR_DRAFT_PENDING_INDEPENDENT_PATCH_NAMESPACE_DAG_QUALIFICATION',
            'source_sha': HEAD, 'final_outer_PINS_REPORT': 'NOT CREATED', 'all_original_scope_preserved_required': True,
            'actual_M35_Aledger1088_UI314_Source_generated': True, 'no_source_function_body_or_target_imported': True,
            'recursive_declared_import_namespace_and_complete_patch_replay': 'PENDING_INDEPENDENT_REVIEW', 'runtime_eligible': False,
            'B_H5_GUI_DOM_finance_device_manual_acceptance': 'NOT RUN'})})
    need(len(set(payload)) == len(payload), 'UNIQUE_OUTPUT_NAMES')
    # Mutation occurs only after every input/generation check above. No current
    # Source, checkout, canonical, resource or original Run is changed.
    output.mkdir()
    for name, raw in payload.items():
        path = output / name
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open('xb') as f:
            f.write(raw)
    print(json.dumps({'status': 'UNSEALED_SOURCE_DRAFT_ONLY', 'files': len(payload), 'output': output.as_posix(),
        'final_outer_PINS_REPORT': 'NOT CREATED', 'target_execution': 'NOT RUN'}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--actual-public-inputs', required=True, help='Root reviewed real same175f A success and exact public artifact pointers; null is rejected')
    parser.add_argument('--output', required=True, help='new direct external Source package under BASE; existing paths rejected')
    build(parser.parse_args())
