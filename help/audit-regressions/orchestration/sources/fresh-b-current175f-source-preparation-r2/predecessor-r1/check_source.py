"""Static byte/AST reviewer only; never import/invoke any prepared author."""
import ast
import copy
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).parent
B = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
OLD = B / 'fresh-b-customer-form-external-source-r3'
N = B / 'full-a-quota-wire-current175f-normal-next-source-r1'
R2 = ROOT.parent / 'fresh-b-current28dc-source-preparation-r1'
reads = {}


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def read(p):
    raw = p.read_bytes()
    reads[p.as_posix()] = pin(raw)
    return raw


def enc(v):
    return (json.dumps(v, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def defs(raw):
    t = raw.decode('utf-8')
    return {n.name: ast.get_source_segment(t, n) for n in ast.parse(t).body if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef))}


def constants(raw):
    d = {}
    for n in ast.parse(raw.decode('utf-8')).body:
        if isinstance(n, ast.Assign):
            try:
                v = ast.literal_eval(n.value)
            except (ValueError, TypeError, SyntaxError):
                continue
            for target in n.targets:
                if isinstance(target, ast.Name):
                    d[target.id] = v
    return d


def patch(raw, diff):
    """Replay a full ordinary single-file unified patch, all context checked."""
    old = raw.decode('utf-8').splitlines(keepends=True)
    lines = diff.decode('utf-8').splitlines(keepends=True)
    assert lines[0].startswith('--- ') and lines[1].startswith('+++ ')
    out, at, i = [], 0, 2
    while i < len(lines):
        m = re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@\n', lines[i])
        assert m, (i, lines[i])
        start, count = int(m[1]), int(m[2] or 1)
        j = start - 1 if start else 0
        assert j >= at
        out.extend(old[at:j]); at = j; i += 1; used = 0
        while i < len(lines) and not lines[i].startswith('@@ '):
            tag, body = lines[i][0], lines[i][1:]
            assert tag in ' +-'
            if tag in ' -':
                assert old[at] == body, (at, old[at], body)
                at += 1; used += 1
            if tag in ' +':
                out.append(body)
            i += 1
        assert used == count
    out.extend(old[at:])
    return ''.join(out).encode('utf-8')


before = read(ROOT / 'before/author_draft.py')
after = read(ROOT / 'impl/author_draft.py')
oldleaf = read(ROOT / 'before/ms4-initialize.php')
leaf = read(ROOT / 'leaf/ms4-initialize.php')
assert before == read(R2 / 'impl/author_draft.py') and oldleaf == read(R2 / 'leaf/ms4-initialize.php')
transform = json.loads(read(ROOT / 'TRANSFORM.json'))
replay = before.decode('utf-8')
seam_stages = []
for seam in transform['ordered_exact_source_seams']:
    assert replay.count(seam['before']) == seam['occurrences']
    positions = [m.start() for m in re.finditer(re.escape(seam['before']), replay)]
    previous = replay
    replay = replay.replace(seam['before'], seam['after'])
    seam_stages.append((seam, positions, previous, replay))
assert replay.encode('utf-8') == after
for seam, positions, previous, expected_after in reversed(seam_stages):
    assert replay == expected_after
    for i in reversed(range(len(positions))):
        pos = positions[i] + i * (len(seam['after']) - len(seam['before']))
        assert replay[pos:pos + len(seam['after'])] == seam['after']
        replay = replay[:pos] + seam['before'] + replay[pos + len(seam['after']):]
    assert replay == previous
assert replay.encode('utf-8') == before
leaf_replay = oldleaf.decode('utf-8')
for seam in transform['leaf_exact_tuples']:
    assert leaf_replay.count(seam['before']) == 1
    leaf_replay = leaf_replay.replace(seam['before'], seam['after'])
assert leaf_replay.encode('utf-8') == leaf
for seam in reversed(transform['leaf_exact_tuples']):
    assert leaf_replay.count(seam['after']) == 1
    leaf_replay = leaf_replay.replace(seam['after'], seam['before'])
assert leaf_replay.encode('utf-8') == oldleaf
for name, a, z in [('AUTHOR', before, after), ('INITIALIZER', oldleaf, leaf)]:
    assert patch(a, read(ROOT / (name + '-FORWARD.diff'))) == z
    assert patch(z, read(ROOT / (name + '-REVERSE.diff'))) == a

bd, ad = defs(before), defs(after)
changed = [name for name in bd if bd[name] != ad[name]]
added = [name for name in ad if name not in bd]
assert changed == ['validate_success', 'new_adapter', 'build']
assert added == []
assert not set(bd) - set(ad)
bt, at = ast.parse(before.decode('utf-8')), ast.parse(after.decode('utf-8'))
imports = lambda t: [ast.dump(n, include_attributes=False) for n in t.body if isinstance(n, (ast.Import, ast.ImportFrom))]
assert imports(bt) == imports(at)
bc, ac = constants(before), constants(after)
assert ac['HEAD'] == '175f3cd6183894185656a2974579e1be39eed7a7'
assert ac['PARENT'] == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
assert ac['ROOT_INITIALIZER_LEAF'] == {'path': (ROOT / 'leaf/ms4-initialize.php').as_posix(), **pin(leaf)}
assert ac['CURRENT_A_SOURCE_PINS'] == pin(read(N / 'PINS.json'))
assert ac['CURRENT_M_RESULT'] == {'path': (N / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **pin(read(N / 'validation/materialize-freeze-assets-r1/RESULT.json'))}
m = json.loads(read(Path(ac['CURRENT_M_RESULT']['path'])))
assert ac['CURRENT_M_FREEZE'] == {'path': m['freeze'], 'bytes': m['freeze_bytes'], 'sha256': m['freeze_sha256']}
assert pin(read(Path(m['freeze']))) == {k: ac['CURRENT_M_FREEZE'][k] for k in ['bytes', 'sha256']}
assert m['source_sha'] == ac['HEAD'] and m['parent'] == ac['PARENT']
mv = constants(read(N / 'materializer/prepare.py'))
assert set(m) == set(mv['CURRENT_M_RESULT_KEYS35']) and len(m) == 35
assert len(m['commit_chain']) == 19 and len(m['committed_files']) == 307
assert m['commit_chain'] == mv['COMMIT_CHAIN'] and len(mv['COMMITTED_PATHS']) == 307
assert len(m['native_operations']) == 6 + sum(2 + 2 * len(s['files']) for s in mv['COMMIT_CHAIN']) + 1 + len(mv['COMMITTED_PATHS']) + 2 + 3 + 1 + 4 == 1088
latest = [{'path': r['path'], **r['after']} for r in m['commit_chain'][-1]['files']]
assert len(latest) == 4
apins = json.loads(read(N / 'PINS.json'))
assert len(apins['source_members18']) == 18
for name in apins['source_members18']:
    row = apins['files'][name]
    assert set(row) == {'path', 'bytes', 'sha256', 'native9'}
    assert row['path'] == (N / name).as_posix()
    assert pin(read(N / name)) == {k: row[k] for k in ['bytes', 'sha256']}
    st = (N / name).stat()
    assert row['native9'] == [st.st_dev, st.st_ino, st.st_mode, st.st_nlink, st.st_uid, st.st_gid, st.st_size, st.st_mtime_ns, st.st_ctime_ns]
publication = json.loads(read(N / 'validation/PROFILE-PUBLICATION.json'))
req = {r['path']: r for r in publication['repository_Source_requirements']}
assert len(req) == 313
interface = json.loads(read(N / 'validation/actual-profile/interface-v1.json'))
oldsets = json.loads(read(OLD / 'CURRENT-SETS.json'))
newcu = interface['customer_form_current_profile']['php_sources']
assert len(newcu) == 7 and sum(a != b for a, b in zip(oldsets['CU7'], newcu)) == 4
oracle_path = ac['ORACLE_PATH']
oracle_before, oracle_after = oldsets['source_vector8'][oracle_path], req[oracle_path]
assert set(oldsets['source_vector8']) - set(req) == {'help/audit-regressions/isolated/lib/ms4-ui-credentials.php', 'help/audit-regressions/isolated/ms4-ui-backend.php', 'help/migrations/marketplace-ms4-order-read-menus.php', 'help/audit-regressions/isolated/ms4-ui-credential-meta.php'}
vector = {p: req[p] if p in req else oldsets['source_vector8'][p] for p in oldsets['source_vector8']}
assert len(vector) == 8 and [p for p in vector if vector[p] != oldsets['source_vector8'][p]] == [oracle_path]
snapshot_pointer = m['complete_source_snapshot']
snapshot_raw = read(Path(snapshot_pointer['path']))
assert pin(snapshot_raw) == {k: snapshot_pointer[k] for k in ['bytes', 'sha256']}
snapshot = json.loads(snapshot_raw)
assert len(snapshot) == 31496
for root in [mv['MAIN_SOURCE'], mv['VALIDATION_SOURCE']]:
    for path, row in vector.items():
        actual = snapshot[(Path(root) / path).as_posix()]
        assert actual['path'] == (Path(root) / path).as_posix() and {k: actual[k] for k in ['bytes', 'sha256']} == {k: row[k] for k in ['bytes', 'sha256']}
core = json.loads(read(OLD / 'current-core-aux/CORE-AUX-AUTHORITY.json'))
core3 = [vector[r['path']] for r in core['core_sources']]
joined = dict(req)
for row in core3:
    assert row['path'] not in joined or joined[row['path']] == row
    joined[row['path']] = row
assert len(joined) == 314 and set(joined) - set(req) == {'help/audit-regressions/isolated/ms4-ui-backend.php'}
browserpins = json.loads(read(OLD / 'browser-v1/PINS.json'))
browser = {name: read(OLD / 'browser-v1' / name) for name in browserpins['members']}
assert {name: pin(raw) for name, raw in browser.items()} == browserpins['members']
assert list(browser) == oldsets['browser8'] and len(browser) == 8

# Independent finite literal transformations, not execution of author helpers.
browser_after = dict(browser)
a = "bytes:%d,sha256:%r" % (oracle_before['bytes'], oracle_before['sha256'])
z = "bytes:%d,sha256:%r" % (oracle_after['bytes'], oracle_after['sha256'])
for name in ['ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs']:
    assert browser[name].decode('utf-8').count(a) == 1
    browser_after[name] = browser[name].decode('utf-8').replace(a, z).encode('utf-8')
    assert browser_after[name].decode('utf-8').replace(z, a).encode('utf-8') == browser[name]
name = 'ui-business-consumer-contract.json'
data = json.loads(browser[name]); saved = copy.deepcopy(data)
assert data['php_sources'][oracle_path] == {k: oracle_before[k] for k in ['bytes', 'sha256']}
data['php_sources'][oracle_path] = {k: oracle_after[k] for k in ['bytes', 'sha256']}
browser_after[name] = enc(data)
data['php_sources'][oracle_path] = saved['php_sources'][oracle_path]
assert enc(data) == browser[name]
assert [n for n in browser if browser[n] != browser_after[n]] == ['ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs', 'ui-business-consumer-contract.json']
browser_manifest = enc({'members': {n: pin(raw) for n, raw in browser_after.items()}})
assert json.loads(browser_after[name])['php_sources'] == {r['path']: {k: r[k] for k in ['bytes', 'sha256']} for r in core3}

retirement = mv['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']
assert set(retirement) == {'path', 'bytes', 'sha256'}
rawret = read(Path(retirement['path']))
assert pin(rawret) == {k: retirement[k] for k in ['bytes', 'sha256']}
assert json.loads(rawret) == m['current_28dc_paid_producer_failure_retirement']
adoption = mv['CURRENT_QUOTA_WIRE_SOURCE_HISTORY']
assert pin(read(Path(adoption['path']))) == {k: adoption[k] for k in ['bytes', 'sha256']}
assert len(mv['SEVENTEENTH_SOURCE_HISTORY']) == 3 and adoption != mv['SEVENTEENTH_SOURCE_HISTORY']

oldpub = json.loads(read(OLD / 'candidate/PUBLICATION.json'))
selected = [r['path'] for r in oldpub['selected17']]
assert len(selected) == 17
for row in oldpub['selected17']:
    assert pin(read(OLD / 'candidate' / row['path'])) == {k: row[k] for k in ['bytes', 'sha256']}
oldtransforms = json.loads(read(OLD / 'SOURCE-TRANSFORMS.json'))
assert len(oldtransforms) == 31 and len(oldpub['finite_contexts']['whole_forward_inverse_files']) == 26
assert oldpub['base_source'] == core['base_source']
assert len(oldpub['finite_contexts']['unchanged_tools3']) == 3
for row in oldtransforms:
    p = OLD / row['path'] if row['path'].startswith(('browser-v1/', 'current-core-aux/', 'root-source/')) else OLD / 'candidate' / row['path']
    assert pin(read(p)) == row['candidate']

# Mandatory literal/control-flow seams remain present in the whole author.
s = after.decode('utf-8')
for required in [
    "len(COMMITTED_FILES)==4", "len(committed) == len(COMMITTED_PATHS) == 4", "len(inputs['committed_files']) == 4",
    "len(latest) == 4", "len(m['commit_chain']) == 19", "len(m) == 35", "'result_fields_exact35'",
    "== 1088", "== 31496", "== 15748", "== 313", "== 314", "== 26", "== 31",
    "'UI_AUX_SOURCE_PIN', pin(browserraw)", "'UIZB_DATA_PIN', pin(browser['ui-business-consumer-contract.json'])",
    "finite['ordered_auxiliary_members'] =", "('UI_CURRENT_SOURCE_PINS', vector)",
    "oldbrowser[name], browser[name]", "oldbrowserraw, browserraw", "mv['CURRENT_QUOTA_WIRE_SOURCE_HISTORY']",
    "owned_upload_root_current_source_successor", "normal_http_diagnostic_successor", "assertion_prefix_successor",
    "'current_0c13_paid_producer_failure_retirement'", "'NINE_DISTINCT_COMPLETE_RETIREMENT_AUTHORITIES'",
    "normal_root'].update", "normal_profile_publication'] =", "ui_profile_publication'] =",
    "EVERY_ORIGINAL_M_DEFINITION_EXACT", "EVERY_ORIGINAL_S_DEFINITION_EXACT", "FULL_ORIGINAL_DBUP_TO_READY_SUFFIX",
    "FULL_ORIGINAL26_PROOF_COVERAGE", "KEEP_ALL31_ORIGINAL_TRANSFORM_MEMBERS", "NO_AUTOMATIC_ROOT_ADMISSION",
    "WAIT_REAL_SAME_SOURCE_A_INPUTS_NO_PLACEHOLDERS", "output.mkdir()", "with path.open('xb')",
]:
    assert required in s, required
assert "len(COMMITTED_FILES)==5\"" not in s and "len(latest) == 5" not in s
assert "mv['SEVENTEENTH_SOURCE_HISTORY']" not in s
assert 'subprocess' not in s and not any(isinstance(n, ast.Call) and isinstance(n.func, ast.Name) and n.func.id in ['exec', 'eval', '__import__'] for n in ast.walk(at))
assert s.index('m, context = validate_success') < s.index('output.mkdir()')
assert ad['receipt_outcome'] == bd['receipt_outcome'] and ad['proof_successor'] == bd['proof_successor']

rmraw = read(OLD / 'root-source/root-ui-materialize-freeze.py')
rsraw = read(OLD / 'root-source/root-ui-start-to-ready.py')
rmd, rsd = defs(rmraw), defs(rsraw)
assert 'post_a_history' in rmd
post = rmd['post_a_history']
for k in ['pre_a_history()', "'retained_public_files'", "'evidence/ms4-worker-normal-independent-zero.json'", 'PUBLIC_ROOT_FILES', "'actual-profile'", "'materialize-freeze-assets-r1'", "'native-normal-start-assets-r1'"]:
    assert k in post
assert rsd['main'].count("    require(stage('db-up')") == 1
marker = "    require(stage('db-up')"
suffix = rsd['main'][rsd['main'].index(marker):]
# Renaming and parent/path data changes are above this byte-identical suffix.
assert "olddefs['main'][olddefs['main'].index(marker):]" in ad['build']
assert core['base_source'] == oldpub['base_source']
assert 'base_source' not in ad['refresh_ui_record'] and "core['base_source'] =" not in ad['build']
current_s = read(N / 'validation/run.py').decode('utf-8')
current_zero = read(N / 'validation/actual-profile/verify-zero-ms4-fulfillment.py').decode('utf-8')
assert "'worker-independent-zero'" in current_s
assert "new(evidence / 'ms4-worker-normal-independent-zero.json', output)" in current_zero

report = {
    'schema': 1, 'status': 'COMPLETE_AUTHOR_AND_LEAF_STATIC_SOURCE_CHECKS_ONLY_NOT_INVOKED_NOT_B_ADMISSION',
    'before_author': pin(before), 'candidate_author': pin(after), 'before_leaf': pin(oldleaf), 'candidate_leaf': pin(leaf),
    'ordinary_full_diff_forward_reverse': {'author': True, 'initializer': True},
    'ordered_exact_seam_forward_reverse': {'author_seams': len(transform['ordered_exact_source_seams']), 'initializer_tuples': 1, 'whole_original_bytes_recovered': True},
    'AST_scope': {'original_author_methods': len(bd), 'unchanged_methods': [n for n in bd if n not in changed], 'changed_methods': changed, 'added_methods': added, 'imports_unchanged': True, 'no_target_or_author_import_or_execution': True},
    'current_actual_A_Source_only': {'source_sha': ac['HEAD'], 'parent': ac['PARENT'], 'Source_PINS': ac['CURRENT_A_SOURCE_PINS'], 'M_result': ac['CURRENT_M_RESULT'], 'freeze': ac['CURRENT_M_FREEZE'], 'M_fields': 35, 'source_history_steps': 19, 'A_M_native_operations': 1088, 'normal_requirements': 313, 'UI_joined': 314, 'cumulative_source_paths': 307, 'tracked_each': 15748, 'dual_metadata': 31496, 'latest_four': latest, 'B_native_budget': 'NOT_CLAIMED_FROM_A_M'},
    'full_UI_data_closure_static': {'CU7_old53e_changed_rows': 4, 'previous28dc_leaf_change': transform['leaf_exact_tuples'], 'separate_paid_tuple_preserved': True, 'UI8_changed_paths': [oracle_path], 'Core3': core3, 'browser_changed_exact_three': [n for n in browser if browser[n] != browser_after[n]], 'other_browser_five_whole_bytes_preserved': True, 'derived_new_browser_source_manifest_pin': pin(browser_manifest), 'base_source_r26_unchanged': core['base_source'], 'all_original_selected17_transform31_proof26_tools3': True, 'original_S_dbup_READY_suffix': pin(suffix.encode('utf-8')), 'all_original_Root_M_methods_copied_and_checked_by_author': len(rmd), 'all_original_Root_S_methods_copied_and_checked_by_author': len(rsd)},
    'authority_and_interface': {'nineteenth_adoption': adoption, 'old_seventeenth_remains_historical': mv['SEVENTEENTH_SOURCE_HISTORY'], 'ninth_failed_28dc_retirement': retirement, 'prior_eight_retirements_retained': True, 'four_normal_lineage_nodes': ['assertion_prefix_successor', 'normal_http_diagnostic_successor', 'owned_upload_root_current_source_successor', 'quota_wire_current_source_successor']},
    'postA': {'original_complete_consumer': {'path': (OLD/'root-source/root-ui-materialize-freeze.py').as_posix(), 'function': 'post_a_history', 'start_line': 685, 'end_line': 733, 'whole_function_pin': pin(post.encode('utf-8'))}, 'current_zero_producer_source': {'path': (N/'validation/actual-profile/verify-zero-ms4-fulfillment.py').as_posix(), 'line': 3484, 'output_relative': 'evidence/ms4-worker-normal-independent-zero.json'}, 'current_normal_S_caller': {'path': (N/'validation/run.py').as_posix(), 'lines': [3776, 3778]}, 'separate_postA_inventory_producer_found': False, 'future_input_schema': 'EXACT_THREE_FIELD_CONTRACT_FROM_ACTUAL_ROOT_PUBLIC_INPUTS_ONLY', 'A0_success_close_zero_postA': 'UNBOUND'},
    'retained_original_scope': ['UI17/CoreAux8/UI20 all20steps and20realobserverACK/42nativeframes/320,390,1440 DOM/N1N2', 'Task2 unpaid-paid-cancel each9steps/20frames/18DOM', 'independent H5 nine realclick/catalogDTO/JPEG driver26262 separate complete coordinator/profile admission pending', 'official Kernel/four HTTP multipart/multiple goods/34columns only3delta/five original readers/savedreads/device', 'Task9 merchant CS off and platform CS retained', 'all existing historical/finance/release/manual requirements pending'],
    'pending': ['new full initializer independent review and actual fixedPHP8.2 syntax', 'real same175f successful A outer terminal/Root/context/workerclose/independentzero/postA and distinct Source review inputs', 'independent full author/leaf Source admission', 'author invocation and complete B generation/seal/materialization/start not run', 'actual complete UI/browser/Task2 and independent H5 coordinator/nine-scene evidence not run', 'stopped negative/security/fault/concurrency/disconnect/recovery/manualexpiry/T4 stay NOTRUN; real funds/deployment unauthorized'],
    'boundary': {'author_invoked': False, 'leaf_PHP_invoked': False, 'B_generated': False, 'Root_UI_Host_Docker_business': 'NOT RUN', 'Run_W_Val_N175f_Git_canonical_checkpoint_modified': False, 'future_success_hashes_PIDs_or_receipts_created': False},
    'complete_static_source_input_window': reads,
}
with (ROOT / 'STATIC-CHECKS.json').open('xb') as f:
    f.write(enc(report))
print(json.dumps({'status': report['status'], 'report': pin(enc(report)), 'source_read_count': len(reads), 'original_author_methods': len(bd), 'changed_methods': changed, 'added_methods': added}))
