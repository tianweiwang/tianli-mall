"""Only create current175f author/leaf Source artifacts; never invoke them."""
from pathlib import Path
import ast, difflib, hashlib, json, os, time

ROOT = Path(__file__).resolve().parent
ORG = ROOT.parent
BASE = ORG.parent / 'ms4-worker-normal-source-preparation-r1'
PREV = ORG / 'fresh-b-current28dc-source-preparation-r1'
N = BASE / 'full-a-quota-wire-current175f-normal-next-source-r1'
READS = {}
HEAD = '175f3cd6183894185656a2974579e1be39eed7a7'
PARENT = '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def read(path, expected=None):
    path = Path(path)
    keys = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    a = [getattr(path.stat(), key) for key in keys]
    with path.open('rb') as stream:
        b = [getattr(os.fstat(stream.fileno()), key) for key in keys]
        raw = stream.read()
        assert stream.read(1) == b''
        c = [getattr(os.fstat(stream.fileno()), key) for key in keys]
    d = [getattr(path.stat(), key) for key in keys]
    assert a == d and b == c and len(raw) == a[6] and [a[i] for i in [0, 1, 6, 7]] == [b[i] for i in [0, 1, 6, 7]]
    if expected is not None:
        assert pin(raw) == {key: expected[key] for key in ['bytes', 'sha256']}
    READS[path.as_posix()] = {'path': path.as_posix(), **pin(raw), 'native9': a}
    return raw


def obj(path, expected=None):
    return json.loads(read(path, expected))


def definitions(raw):
    text = raw.decode()
    return {node.name: ast.get_source_segment(text, node) for node in ast.parse(raw).body
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef))}


def literals(raw):
    result = {}
    for node in ast.parse(raw).body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            try:
                result[node.targets[0].id] = ast.literal_eval(node.value)
            except (TypeError, ValueError):
                pass
    return result


def enc(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()


def put(name, raw):
    path = ROOT / name
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('xb') as stream:
        stream.write(raw)


def main():
    assert not (ROOT / 'impl/author_draft.py').exists()
    read(__file__)
    read(ROOT / 'DESIGN.md')
    previous_pins = obj(PREV / 'PINS.json', {'bytes': 7937, 'sha256': '037649164a6c72971d318b0c4b4a93e721c495d50f9a224e2cd28f9a0fadca5e'})
    previous_final = obj(PREV / 'FINAL-PINS.json')
    assert previous_final['packet_PINS'] == {'path': (PREV / 'PINS.json').as_posix(), **pin(read(PREV / 'PINS.json'))}
    assert set(p.relative_to(PREV).as_posix() for p in PREV.rglob('*') if p.is_file()) == set(previous_pins['files']) | {'PINS.json', 'FINAL-PINS.json'}
    for name, wanted in previous_pins['files'].items():
        read(PREV / name, wanted)
    independent = obj(ORG / 'fresh-b-current28dc-author-independent-r1/REPORT.json', {'bytes': 226089, 'sha256': '7b88b018a0d059e7797a6b055d15f632d61c21f8ece49531954170366b6dcb53'})
    apins = obj(N / 'PINS.json', {'bytes': 147196, 'sha256': '2653282ad09cdf30e030c6c1cf5976b73a1a06bd0be52070085e3a3f33bb270b'})
    for name in apins['source_members18']:
        read(N / name, apins['files'][name])
    actual = obj(N / 'validation/materialize-freeze-assets-r1/RESULT.json', {'bytes': 46750425, 'sha256': '6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa'})
    freeze = read(Path(actual['freeze']), {'bytes': 10985209, 'sha256': '4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed'})
    mvars = literals(read(N / 'materializer/prepare.py'))
    assert len(actual) == 35 and list(actual) == mvars['CURRENT_M_RESULT_KEYS35'] and actual['source_sha'] == HEAD and actual['parent'] == PARENT
    assert len(actual['commit_chain']) == 19 and len(actual['native_operations']) == 1088 and len(actual['committed_files']) == 307
    assert len(actual['commit_chain'][-1]['files']) == 4
    ninth = mvars['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']
    assert obj(Path(ninth['path']), ninth) == actual['current_28dc_paid_producer_failure_retirement']
    read(BASE / 'current175f-complete-materialization-root-readback-r1/REPORT.json', {'bytes': 1814, 'sha256': '6901f56b93e54ae6493a02e3851084e56be995d316294314e0bf03a0b710af05'})
    read(ORG / 'current175f-actual-materialization-independent-source-r2/candidate/REPORT.json', {'bytes': 2402, 'sha256': '9be51921dab142e51df7aec9a1d95717c0e9629b0ba5f8c84bb692d863c5c4a6'})
    old = read(PREV / 'impl/author_draft.py', previous_pins['files']['impl/author_draft.py'])
    oldleaf = read(PREV / 'leaf/ms4-initialize.php', previous_pins['files']['leaf/ms4-initialize.php'])
    oldvars = literals(old)
    before_interface = obj(BASE / 'full-a-upload-root-current28dc-normal-next-source-r1/validation/actual-profile/interface-v1.json')
    current_interface = obj(N / 'validation/actual-profile/interface-v1.json')
    oldcu = before_interface['customer_form_current_profile']['php_sources']
    newcu = current_interface['customer_form_current_profile']['php_sources']
    changed = [(a, z) for a, z in zip(oldcu, newcu) if a != z]
    assert len(changed) == 1 and changed[0][0]['path'] == 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'
    def tuple_text(row):
        return "['path' => %r, 'bytes' => %d, 'sha256' => %r]" % (row['path'], row['bytes'], row['sha256'])
    before_tuple, after_tuple = map(tuple_text, changed[0])
    assert oldleaf.decode().count(before_tuple) == 1
    leaf = oldleaf.decode().replace(before_tuple, after_tuple).encode()
    assert leaf.decode().replace(after_tuple, before_tuple).encode() == oldleaf and len(leaf) == len(oldleaf) == 118698
    leaf_pointer = {'path': (ROOT / 'leaf/ms4-initialize.php').as_posix(), **pin(leaf)}
    text = old.decode()
    seams = []
    def edit(before, after, scope, count=1):
        nonlocal text
        assert text.count(before) == count, (scope, before, text.count(before), count)
        text = text.replace(before, after)
        seams.append({'scope': scope, 'before': before, 'after': after, 'occurrences': count})
    edit("A = BASE / 'full-a-upload-root-current28dc-normal-next-source-r1'", "A = BASE / 'full-a-quota-wire-current175f-normal-next-source-r1'", 'Real current Source root')
    for name, value in [('HEAD', HEAD), ('PARENT', PARENT), ('CURRENT_A_SOURCE_PINS', pin(read(N / 'PINS.json'))),
                        ('CURRENT_M_RESULT', {'path': (N / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **pin(read(N / 'validation/materialize-freeze-assets-r1/RESULT.json'))}),
                        ('CURRENT_M_FREEZE', {'path': actual['freeze'], 'bytes': actual['freeze_bytes'], 'sha256': actual['freeze_sha256']}),
                        ('ROOT_INITIALIZER_LEAF', leaf_pointer)]:
        edit(name + ' = ' + repr(oldvars[name]), name + ' = ' + repr(value), 'Exact current literal: ' + name)
    # Rename current generated wrappers/data labels only; all historical Source text is still read from immutable OLD.
    for before, after in [('same28dc', 'same175f'), ('CURRENT28DC', 'CURRENT175F'), ('CURRENT_28DC_M_SOURCE_CONTRACT', 'CURRENT_175F_M_SOURCE_CONTRACT'),
                          ('current28dc_', 'current175f_'), ('Current28dc', 'Current175f'), ('optimization-before-current28dc', 'optimization-before-current175f')]:
        count = text.count(before)
        assert count > 0
        edit(before, after, 'Active generated name/label only', count)
    edit("set(mvars['CURRENT_M_RESULT_KEYS34']) and len(m) == 34", "set(mvars['CURRENT_M_RESULT_KEYS35']) and len(m) == 35", 'Strict completed current M35')
    edit("'EXACT_CURRENT_M34'", "'EXACT_CURRENT_M35'", 'Strict schema label')
    edit("len(m['commit_chain']) == 18, 'FULL_EIGHTEEN_SOURCE_CHAIN'", "len(m['commit_chain']) == 19, 'FULL_NINETEEN_SOURCE_CHAIN'", 'Real19 Source stages')
    edit("derived == len(m['native_operations']) == 1078", "derived == len(m['native_operations']) == 1088", 'Actual completed A-M budget, never B budget')
    edit("\"'result_fields_exact34'\"", "\"'result_fields_exact35'\"", 'Adapter exact active field list')
    edit("'len(setup) == 34'", "'len(setup) == 35'", 'Adapter strict active M35')
    edit('"len(setup[\'commit_chain\']) == 18"', '"len(setup[\'commit_chain\']) == 19"', 'Adapter full19 history')
    edit('"contract[\'derived_native_count\'] == 1078"', '"contract[\'derived_native_count\'] == 1088"', 'Adapter real A-M native ledger')
    edit('"assert finite[\'repository_committed_files\']==COMMITTED_FILES and len(COMMITTED_FILES)==8"', '"assert finite[\'repository_committed_files\']==COMMITTED_FILES and len(COMMITTED_FILES)==4"', 'First strict last4 consumer')
    edit("result_fields_exact34=mv['CURRENT_M_RESULT_KEYS34']", "result_fields_exact35=mv['CURRENT_M_RESULT_KEYS35']", 'Producer strict M35 keys')
    edit("mv['CURRENT_UPLOAD_ROOT_SOURCE_HISTORY']", "mv['CURRENT_QUOTA_WIRE_SOURCE_HISTORY']", 'Both exact19th adoption consumers', 2)
    edit("['assertion_prefix_successor', 'normal_http_diagnostic_successor', 'owned_upload_root_current_source_successor']", "['assertion_prefix_successor', 'normal_http_diagnostic_successor', 'owned_upload_root_current_source_successor', 'quota_wire_current_source_successor']", 'Fourth actual lineage node')
    edit("need(len(latest) == 8, 'ACTUAL_LAST_COMMIT_EIGHT_NOT_CUMULATIVE307')", "need(len(latest) == 4, 'ACTUAL_LAST_COMMIT_FOUR_NOT_CUMULATIVE307')", 'Real latest4 producer')
    edit("'len(committed) == len(COMMITTED_PATHS) == 8'", "'len(committed) == len(COMMITTED_PATHS) == 4'", 'Second strict last4 consumer and exact inverse', 2)
    edit("'ONLY_CURRENT_M_MAIN_COMMITTED_EIGHT_GUARD_CHANGED'", "'ONLY_CURRENT_M_MAIN_COMMITTED_FOUR_GUARD_CHANGED'", 'Wrapper sole guard label')
    edit('"len(inputs[\'committed_files\']) == 8"', '"len(inputs[\'committed_files\']) == 4"', 'Third strict last4 consumer')
    edit("current175f_main_with_exact_last_commit8_guard", "current175f_main_with_exact_last_commit4_guard", 'Actual current wrapper proof label')
    edit("strict Source-only wrapper, M34/UI314", "strict Source-only wrapper, M35/UI314", 'Generated requirement note')
    edit("actual_M34_Aledger1078_UI314_Source_generated", "actual_M35_Aledger1088_UI314_Source_generated", 'Generated Source-only qualification label')
    # Preserve eight original authorities and append the ninth complete genuine failure pointer/object.
    old_row = "('current_0c13_paid_producer_failure_retirement', 'paid_0c13_failure_retirement_expected', 'paid_0c13_failure_retirement_source', 'CURRENT_0C13_PAID_PRODUCER_FAILURE_RETIREMENT')]"
    new_row = old_row[:-1] + ",\n        ('current_28dc_paid_producer_failure_retirement', 'paid_28dc_failure_retirement_expected', 'paid_28dc_failure_retirement_source', 'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT')]"
    edit(old_row, new_row, 'Ninth whole authority producer')
    old_names = "'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement']"
    edit(old_names, old_names[:-1] + ", 'current_28dc_paid_producer_failure_retirement']", 'Nine distinct historical names')
    edit("len(contract['historical_retirement_names_remain_distinct']) == 8 and json.loads(deref(contract['paid_0c13_failure_retirement_source'])) == contract['paid_0c13_failure_retirement_expected'], 'EIGHT_DISTINCT_COMPLETE_RETIREMENT_AUTHORITIES'", "len(contract['historical_retirement_names_remain_distinct']) == 9 and json.loads(deref(contract['paid_28dc_failure_retirement_source'])) == contract['paid_28dc_failure_retirement_expected'], 'NINE_DISTINCT_COMPLETE_RETIREMENT_AUTHORITIES'", 'Complete actual ninth and preserve8')
    old_extra_end = "    assert json.loads(file_pin(Path(contract['paid_0c13_failure_retirement_source']['path']),contract['paid_0c13_failure_retirement_source'])) == contract['paid_0c13_failure_retirement_expected']"
    # This is a string literal in the author, so keep the actual backslash-n encoding.
    new_extra_end = old_extra_end + "\\n    assert setup['current_28dc_paid_producer_failure_retirement'] == contract['paid_28dc_failure_retirement_expected']\\n    assert json.loads(file_pin(Path(contract['paid_28dc_failure_retirement_source']['path']),contract['paid_28dc_failure_retirement_source'])) == contract['paid_28dc_failure_retirement_expected']"
    edit(old_extra_end, new_extra_end, 'Ninth full consumer in current materialization adapter')
    candidate = text.encode()
    ast.parse(candidate)
    reverse = text
    stages = old.decode()
    snapshots = []
    for row in seams:
        assert stages.count(row['before']) == row['occurrences']
        positions = []
        at = 0
        while True:
            at = stages.find(row['before'], at)
            if at < 0:
                break
            positions.append(at)
            at += len(row['before'])
        prior = stages
        stages = stages.replace(row['before'], row['after'])
        snapshots.append((row, positions, prior, stages))
    assert stages == text
    for row, positions, prior, after in reversed(snapshots):
        assert reverse == after
        for index in reversed(range(len(positions))):
            at = positions[index] + index * (len(row['after']) - len(row['before']))
            assert reverse[at:at + len(row['after'])] == row['after']
            reverse = reverse[:at] + row['before'] + reverse[at + len(row['after']):]
        assert reverse == prior
    assert reverse.encode() == old
    bd, ad = definitions(old), definitions(candidate)
    assert set(bd) == set(ad) and [name for name in bd if bd[name] != ad[name]] == ['validate_success', 'new_adapter', 'build']
    put('before/author_draft.py', old)
    put('before/ms4-initialize.php', oldleaf)
    put('impl/author_draft.py', candidate)
    put('leaf/ms4-initialize.php', leaf)
    notes = b'# Current175f Source successor (UNEXECUTED)\n\nCurrent values are real Source175f, M35/19/1088, last4, ninth actual failed28dc retirement and the helper tuple. A0/postA/success close-zero remain UNBOUND, B is unsealed. Original complete acceptance below remains mandatory; older as-of Source identities are historical.\n\n' + read(PREV / 'impl/DESIGN-NOTES.md')
    put('impl/DESIGN-NOTES.md', notes)
    for prefix, a, z in [('AUTHOR', old, candidate), ('INITIALIZER', oldleaf, leaf)]:
        put(prefix + '-FORWARD.diff', ''.join(difflib.unified_diff(a.decode().splitlines(True), z.decode().splitlines(True), 'a/' + prefix.lower(), 'b/' + prefix.lower())).encode())
        put(prefix + '-REVERSE.diff', ''.join(difflib.unified_diff(z.decode().splitlines(True), a.decode().splitlines(True), 'a/' + prefix.lower(), 'b/' + prefix.lower())).encode())
    put('TRANSFORM.json', enc({'schema': 1, 'ordered_exact_source_seams': seams,
        'leaf_exact_tuples': [{'before': before_tuple, 'after': after_tuple}], 'whole_both_reverse_original_bytes': True,
        'original_methods_unchanged': [name for name in bd if bd[name] == ad[name]], 'changed_methods': ['validate_success', 'new_adapter', 'build'],
        'author_invoked': False, 'leaf_PHP_executed': False}))
    for path, row in READS.items():
        assert pin(Path(path).read_bytes()) == {key: row[key] for key in ['bytes', 'sha256']}
    put('SOURCE-INPUTS.json', enc({'schema': 1, 'complete_source_inputs': READS,
        'original_preparation_files': len(previous_pins['files']), 'original_preparation_outer_descriptors': ['PINS.json', 'FINAL-PINS.json'],
        'current_A_M_only': {'M_fields': 35, 'steps': 19, 'operations': 1088}, 'current_A0_success_close_zero_postA': 'UNBOUND',
        'B_budget': 'NOT DERIVED OR CLAIMED', 'all_source_windows_ended': True}))
    print(json.dumps({'status': 'COMPLETE_CURRENT175F_AUTHOR_LEAF_SOURCE_PREPARED_UNEXECUTED', 'author': pin(candidate), 'leaf': pin(leaf), 'seams': len(seams), 'methods_changed': ['validate_success', 'new_adapter', 'build'], 'A0': 'UNBOUND'}))


if __name__ == '__main__':
    main()
