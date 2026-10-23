"""Source-only eighth retirement authority. Never execute or import a target.

Read fixed public receipts and safe native streams. Historical opaque observations
are metadata only: no dereference of their file paths, including advanced W/Val.
The sole write root is this new Source directory through source_reader.write.
"""
import ast
import collections
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import traceback
import source_reader as r

ORG = r.B.parent / 'project-organization-20261023'
RUN_ID = '20261023124755f45a9e'
SOURCE = '0c13c57ba1ec4bf15ea9240601a5d6361e986ce8'
FREEZE = 'ed107cddd68676c945983a5a33cc3faf88dc7a92475ed2112f293cdfb82f1e47'
RUN = ORG.parent / 'ms4-aftersale-review-normal-runs' / RUN_ID
CLOSE = r.B / 'current0c13-paid-producer-failed-normal-close-r1'
ZERO = r.B / 'current0c13-paid-producer-failed-independent-zero-r1'
SRC = r.B / 'current0c13-paid-producer-failed-close-source-r2'
PEER = ORG / 'current0c13-failed-retirement-actual-independent-full-r5'
PEER_SRC = ORG / 'current0c13-failed-retirement-actual-reader-source-r5'
ROOT_FULL = ORG / 'current0c13-failure-full-root-actual-readback-r2'
FAILURE = ORG / 'current0c13-paid-failure-retirement-preparation-r1'
COLLECTOR = ORG / 'current0c13-paid-failure-diagnostic-actual-r1'
PY = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
DOCKER = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
EMPTY = hashlib.sha256(b'').hexdigest()
CLASSIFICATIONS = {}
CHECKS = {}


def ordinary(path):
    path = Path(path)
    assert path.is_absolute() and '..' not in path.parts
    assert not path.is_relative_to(ORG.parent.parent / 'worktrees'), 'NO_ADVANCED_W_VAL_BODY_READ'
    for item in [*reversed(path.parents), path]:
        info = item.lstat()
        assert not stat.S_ISLNK(info.st_mode) and not getattr(info, 'st_file_attributes', 0) & 0x400
        assert stat.S_ISREG(info.st_mode) if item == path else stat.S_ISDIR(info.st_mode)
    return path


def safe(path, category, expected=None):
    path = ordinary(path)
    assert category in {
        'REVIEWED_SOURCE', 'PUBLIC_RECEIPT_JSON', 'OBSERVATION_METADATA_ONLY',
        'NATIVE_CREATED_TERMINAL', 'DECLARED_EMPTY_STDERR', 'PUBLIC_NATIVE_STDOUT',
        'FIXED_HOST_JSON_STDOUT', 'PUBLIC_IMMUTABLE_PROFILE_SOURCE', 'PUBLIC_GIT_METADATA_STDOUT'}
    assert path.name not in {'php.env', '.env', 'environment.json', 'php-environment.json'}
    assert 'php-env' not in path.parts and not any(x.startswith('private-') for x in path.parts)
    key = path.as_posix()
    CLASSIFICATIONS.setdefault(key, set()).add(category)
    return r.fixed(path, expected) if expected is not None else r.read(path)


def doc(path, category='PUBLIC_RECEIPT_JSON', expected=None):
    def pairs(items):
        result = {}
        for key, value in items:
            assert key not in result, ('DUPLICATE_JSON_KEY', key)
            result[key] = value
        return result
    return json.loads(safe(path, category, expected), object_pairs_hook=pairs)


def ptr(path):
    assert Path(path).as_posix() in CLASSIFICATIONS
    return r.pointer(Path(path), True)


def four(folder, stem=None, expected=None, stdout_category='PUBLIC_NATIVE_STDOUT', code=0):
    paths = ({name: folder / name for name in ['created.json', 'terminal.json', 'stdout', 'stderr']}
             if stem is None else {name: Path(str(folder / stem) + '.' + name)
                                   for name in ['created.json', 'terminal.json', 'stdout', 'stderr']})
    made = doc(paths['created.json'], 'NATIVE_CREATED_TERMINAL')
    ended = doc(paths['terminal.json'], 'NATIVE_CREATED_TERMINAL')
    assert type(made['pid']) is int and made['pid'] > 0 and made['pid'] == ended['pid']
    assert type(ended['native_exit']) is int and type(ended['wait_returncode']) is int
    assert ended['native_exit'] == ended['wait_returncode'] == code
    if expected is not None:
        assert made == {'argv': expected['argv'], 'pid': expected['pid']} and ended == expected
    streams = {}
    for name in ['stdout', 'stderr']:
        pin = ended.get(name)
        if type(pin) is not dict:
            pin = {'bytes': ended[name + '_bytes'], 'sha256': ended[name + '_sha256']}
        streams[name] = safe(paths[name], 'DECLARED_EMPTY_STDERR' if name == 'stderr' else stdout_category, pin)
        assert name != 'stderr' or streams[name] == b''
    return {name: ptr(paths[key]) for name, key in [('created', 'created.json'), ('terminal', 'terminal.json'),
                                                   ('stdout', 'stdout'), ('stderr', 'stderr')]}, made, ended, streams


def literal_assignments(path, names):
    text = safe(path, 'REVIEWED_SOURCE').decode('utf-8')
    found = {}
    for item in ast.parse(text).body:
        if isinstance(item, ast.Assign):
            for target in item.targets:
                if isinstance(target, ast.Name) and target.id in names:
                    found[target.id] = ast.literal_eval(item.value)
    assert set(found) == set(names)
    return found


def inventory_equal(left, right):
    assert set(left) == set(right)
    assert {k: v for k, v in left.items() if k != 'mounts'} == {k: v for k, v in right.items() if k != 'mounts'}
    encode = lambda rows: sorted(json.dumps(x, sort_keys=True, separators=(',', ':')) for x in rows)
    assert encode(left['mounts']) == encode(right['mounts'])


def metadata_pointer(row):
    assert type(row) is dict and type(row['path']) is str
    pin = row.get('pin', row)
    assert type(pin['bytes']) is int and pin['bytes'] >= 0 and re.fullmatch('[a-f0-9]{64}', pin['sha256'])
    return {'path': row['path'], 'bytes': pin['bytes'], 'sha256': pin['sha256']}


def main():
    assert r.HEAD == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3' and r.PARENT == SOURCE
    assert r.OLD == r.B / 'full-a-normal-http-diagnostic-next-source-r1'
    safe(Path(__file__), 'REVIEWED_SOURCE')
    safe(r.OUT / 'source_reader.py', 'REVIEWED_SOURCE')
    safe(r.OUT / 'DESIGN.md', 'REVIEWED_SOURCE')
    safe(r.OLD / 'retirement-author.py', 'REVIEWED_SOURCE')
    specs = {
        'Root_final_admission': (ORG / 'current0c13-retirement-final-root-admission-r1/REPORT.json', 1948,
                                 'd874511b24e94b73180b70401e96eba9ca03df2665b9a8ad8ade4655655e5c6c'),
        'Root_full': (ROOT_FULL / 'REPORT.json', 1248, '69b7db168819cf72116a287923d285a5f5cf7d28b578f7d85c7874349cbafc2d'),
        'peer_full': (PEER / 'REPORT.json', 5199, '920ad49125fd654728f47bf496886252a95a7c92db7a1bfa07ad8ecbde707f09'),
        'peer_PINS': (PEER / 'FINAL-PINS.json', 584, '8121a57d4fbb9dd545a09a46bb18b66f3349a5661f7a5888cbedeefcaa4bb484'),
        'close': (CLOSE / 'RESULT.json', 106556, '3a74708b6b89312c0a4f41f9094aeafb22c6c034cddd96d6211d99f6477959b7'),
        'zero': (ZERO / 'RESULT.json', 27029, '630e5324960a37586555213deede3218a4ebf4dfcc04fce1516aadeea1c1e03f')}
    documents = {}
    inputs = {}
    for key, (path, size, digest) in specs.items():
        documents[key] = doc(path, expected={'bytes': size, 'sha256': digest})
        inputs[key] = ptr(path)
    admission, root, peer, close, zero = [documents[k] for k in ['Root_final_admission', 'Root_full', 'peer_full', 'close', 'zero']]
    assert admission['all_required_retirement_gate_satisfied'] is True
    assert admission['original_business_result'] == 'FAILED_S1_PAID1_A0_ABSENT'
    assert root['status'] == 'PASS_ROOT_COMPLETE_ACTUAL_FULL_READBACK' and root['read_count'] == 45038
    assert peer['status'] == 'VERIFIED_FAILED_RUN_CLOSE_AND_INDEPENDENT_ZERO' and peer['findings'] == []
    assert peer['zero_admitted'] is True and peer['finite_safe_files'] == 751 and peer['opaque_fingerprint_files'] == 44568
    assert peer['finite_directories'] == 6860 and peer['foreign_inventories_verified'] == 9
    for result in [root, peer, close, zero]:
        assert result['run_id'] == RUN_ID and result['source_sha'] == SOURCE and result['freeze_sha256'] == FREEZE
    for key in ['Root_report', 'peer_report', 'peer_FINAL_PINS']:
        target = {'Root_report': 'Root_full', 'peer_report': 'peer_full', 'peer_FINAL_PINS': 'peer_PINS'}[key]
        assert admission[key] == {k: inputs[target][k] for k in ['path', 'bytes', 'sha256']}
    extra = {
        'Root_full_inputs': ROOT_FULL / 'INPUTS.json', 'peer_execution': PEER / 'EXECUTION-PROVENANCE.json',
        'peer_reader_inputs': PEER_SRC / 'INPUTS.json', 'retirement_Source_PINS': SRC / 'PINS.json',
        'actual_failure': FAILURE / 'REPORT.json', 'actual_failure_public_facts': FAILURE / 'PUBLIC-EVIDENCE-FACTS.json',
        'M_result': r.OLD / 'validation/materialize-freeze-assets-r1/RESULT.json',
        'collector': COLLECTOR / 'REPORT.json', 'collector_created': COLLECTOR / 'created.json',
        'collector_terminal': COLLECTOR / 'terminal.json'}
    for key, path in extra.items():
        documents[key] = doc(path, 'OBSERVATION_METADATA_ONLY' if key == 'Root_full_inputs' else 'PUBLIC_RECEIPT_JSON')
        inputs[key] = ptr(path)
    for key in ['source_R2_review']:
        pointer = admission[key]; doc(pointer['path'], expected=pointer); inputs[key] = ptr(pointer['path'])
    observation_pointer = peer['observations']
    observed = doc(observation_pointer['path'], 'OBSERVATION_METADATA_ONLY', observation_pointer)
    inputs['peer_observations'] = ptr(observation_pointer['path'])
    assert set(observed) == {'safe_files', 'opaque_files', 'directories', 'inventories', 'native_operations'}
    assert [len(observed[k]) for k in ['safe_files', 'opaque_files', 'directories', 'inventories', 'native_operations']] == [751, 44568, 6860, 9, 113]
    opaque_map = {row['path']: row for row in observed['opaque_files']}
    assert len(opaque_map) == 44568
    root_observed = documents['Root_full_inputs']
    assert type(root_observed) is dict and len(root_observed) == 45038
    for name, row in root_observed.items():
        assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == name
        assert type(row['stat9']) is list and len(row['stat9']) == 9 and all(type(x) is int for x in row['stat9'])
    # The peer safe list contains only these finite, previously reviewed classes.
    safe_prefixes = [SRC, CLOSE, ZERO, PEER_SRC, RUN / 'root-orchestration', RUN / 'evidence',
                     FAILURE, ORG / 'current0c13-failure-close-outer-actual-r1',
                     ORG / 'current0c13-failure-zero-outer-actual-r1',
                     ORG / 'current0c13-failed-close-source-independent-r1', r.OLD / 'launcher']
    safe_exact = {RUN / name for name in ['actual-run-context.json', 'preparation-source-binding.json', 'manifest.json']}
    safe_exact |= {r.OLD / 'validation' / name for name in [
        'actual-profile/ms4-host.py', 'native-normal-start-assets-r1/001-prepare.created.json',
        'native-normal-start-assets-r1/001-prepare.terminal.json', 'materialize-freeze-assets-r1/RESULT.json',
        'materialize-freeze-assets-r1/ROOT-SOURCE-SNAPSHOT.json', 'materialize-freeze-assets-r1/COMPLETE-SOURCE-SNAPSHOT.json']}
    safe_exact |= {r.B / 'customer-form-current-three-front-material-producer-independent-r1/review.py',
                   ORG / 'launch-current0c13-failure-retirement-r1.py'}
    peer_safe = []
    for row in observed['safe_files']:
        path = Path(row['path'])
        assert path in safe_exact or any(path.is_relative_to(prefix) for prefix in safe_prefixes)
        assert row['named_before'] == row['named_after'] and row['fd_open'] == row['fd_after']
        category = ('REVIEWED_SOURCE' if path.suffix in ['.py', '.md', '.patch'] else
                    'DECLARED_EMPTY_STDERR' if path.name.endswith('.stderr') or path.name == 'stderr' else
                    'PUBLIC_NATIVE_STDOUT' if path.name.endswith('.stdout') or path.name == 'stdout' else 'PUBLIC_RECEIPT_JSON')
        raw = safe(path, category, row['pin'])
        assert category != 'DECLARED_EMPTY_STDERR' or raw == b''
        assert r.READS[path.as_posix()]['path_before'] == row['named_before']
        peer_safe.append(ptr(path))
    assert len({x['path'] for x in peer_safe}) == 751
    data = documents['peer_reader_inputs']
    literals = data['cleanup_literals']
    assert literals['RUN_ID'] == RUN_ID and literals['SOURCE'] == SOURCE and literals['FREEZE'] == FREEZE
    assert literal_assignments(SRC / 'normal-close.py', literals) == literals
    assert literal_assignments(SRC / 'independent-zero.py', literals) == literals
    for name, expected in documents['retirement_Source_PINS']['files'].items():
        safe(SRC / name, 'REVIEWED_SOURCE', expected)
    assert len(literals['AUTHOR_PINS']) == 532
    failure = documents['actual_failure']; materialized = documents['M_result']
    assert failure['run_id'] == RUN_ID and failure['source_sha'] == SOURCE and failure['freeze_sha256'] == FREEZE
    assert failure['A0'] is False and failure['business_acceptance'] == 'INCOMPLETE'
    assert failure['normal_http_response_diagnostic'] == {'kind': 'upload', 'method': 'POST', 'http': 503,
        'json_error': 0, 'response_bytes': 216, 'response_sha256': '318ae4db8bedc98bc218ade87cd653fd885bb3f91b4e23968c88c62dbbed4670'}
    assert len(materialized) == 33 and list(materialized) == failure['M33_keys']
    assert materialized['source_sha'] == SOURCE and materialized['freeze_sha256'] == FREEZE
    assert len(materialized['commit_chain']) == 17 and len(materialized['committed_files']) == 307
    assert len(materialized['selected9']) == 9 and len(materialized['native_operations']) == 1060
    assert materialized['source_counts'] == failure['source_counts'] and materialized['unique_source_files_actual_read'] == 10886
    baseline = doc(CLOSE / 'PUBLIC-INPUT-PINS.json', expected=close['public_input_pins'])
    assert set(baseline) == {'files', 'directories'} and [len(baseline[k]) for k in ['files', 'directories']] == [44567, 6860]
    for row in baseline['files']:
        name = row['path']; old = opaque_map[name]
        assert metadata_pointer(row) == metadata_pointer(old)
        assert row['stat9'] == old['named_before'] == old['named_after']
        assert root_observed[name] == row
    dir_map = {row['path']: row for row in observed['directories']}
    assert len(dir_map) == 6860 and all(dir_map[x['path']] == x for x in baseline['directories'])
    snapshots = {}
    for key, count in [('root_source_snapshot', 14), ('complete_source_snapshot', 31496)]:
        pointer = materialized[key]; snapshot = doc(pointer['path'], expected=pointer)
        assert type(snapshot) is dict and len(snapshot) == count
        assert all(row == root_observed[name] for name, row in snapshot.items())
        snapshots[key] = {'pointer': ptr(pointer['path']), 'records': count, 'schema': ['path', 'bytes', 'sha256', 'stat9']}
    profile = r.OLD / 'validation/actual-profile'
    for row in materialized['selected9']:
        safe(profile / row['path'], 'PUBLIC_IMMUTABLE_PROFILE_SOURCE', row)
    # 1060 complete M native ledgers: content-producing git-show stdout stays opaque.
    m_native = []; m_opaque_stdout = []; m_safe_stdout = 0
    m_folder = r.OLD / 'validation/materialize-freeze-assets-r1'
    for index, row in enumerate(materialized['native_operations'], 1):
        assert row['stem'].startswith('%03d-' % index) and row['native_exit'] == row['wait_returncode'] == 0
        made_path = m_folder / (row['stem'] + '.created.json'); ended_path = m_folder / (row['stem'] + '.terminal.json')
        made = doc(made_path, 'NATIVE_CREATED_TERMINAL'); ended = doc(ended_path, 'NATIVE_CREATED_TERMINAL')
        assert made == {'argv': row['argv'], 'pid': row['pid']} and ended == row
        stdout_path = m_folder / (row['stem'] + '.stdout'); stderr_path = m_folder / (row['stem'] + '.stderr')
        stderr = safe(stderr_path, 'DECLARED_EMPTY_STDERR', {'bytes': row['stderr_bytes'], 'sha256': row['stderr_sha256']})
        assert stderr == b'' and row['stderr_sha256'] == EMPTY
        argv = row['argv']; opaque_stdout = argv[0] == 'git' and argv[5:6] == ['show']
        expected = {'bytes': row['stdout_bytes'], 'sha256': row['stdout_sha256']}
        if opaque_stdout:
            old = opaque_map[stdout_path.as_posix()]
            assert old['pin'] == expected and old['named_before'] == old['named_after'] and old['fd_open'] == old['fd_after']
            stdout_pointer = metadata_pointer(old)
            m_opaque_stdout.append(stdout_pointer)
        else:
            safe(stdout_path, 'PUBLIC_GIT_METADATA_STDOUT' if argv[0] == 'git' else 'PUBLIC_NATIVE_STDOUT', expected)
            stdout_pointer = ptr(stdout_path); m_safe_stdout += 1
        m_native.append({'index': index, 'operation': row, 'created': ptr(made_path), 'terminal': ptr(ended_path),
                         'stdout': stdout_pointer, 'stderr': ptr(stderr_path),
                         'stdout_policy': 'OPAQUE_HISTORICAL_METADATA_NO_BODY_READ' if opaque_stdout else 'PUBLIC_FULL_BYTES'})
    assert len(m_opaque_stdout) == 747 and m_safe_stdout == 313
    original_root = []; root_payloads = []; stdout_schemas = {}
    root_rows = [failure['initial_prepare'], *failure['root_native_commands']]
    assert len(root_rows) == 112 and len(failure['root_native_commands']) == 111
    for index, row in enumerate(root_rows, 1):
        created_path = Path(row['created']['path'])
        stem = created_path.name.removesuffix('.created.json')
        native, made, ended, streams = four(created_path.parent, stem, stdout_category='FIXED_HOST_JSON_STDOUT')
        assert made['pid'] == row['pid'] and Path(made['argv'][0]) == Path(PY) and made['argv'][1:4] == ['-B', '-X', 'utf8']
        assert Path(made['argv'][4]) == profile / 'ms4-host.py'
        for key in ['created', 'terminal', 'stdout', 'stderr']:
            assert {k: native[key][k] for k in ['path', 'bytes', 'sha256']} == row[key]
        payload = json.loads(streams['stdout'])
        assert type(payload) is dict
        command = made['argv'][5]
        assert command not in ['fulfillment-normal-start', 'aftersale-review-start', 'worker-start']
        if index == 1:
            assert command == 'prepare' and made['argv'][6::2] == ['--binding', '--run-root']
            assert Path(made['argv'][7]) == r.OLD / 'validation/materialize-freeze-assets-r1/freeze-binding.json'
            assert Path(made['argv'][9]) == RUN.parent and '--context' not in made['argv']
        else:
            assert made['argv'].count('--context') == 1 and Path(made['argv'][made['argv'].index('--context')+1]) == RUN / 'actual-run-context.json'
        schema = list(payload)
        stdout_schemas.setdefault(command, [])
        if schema not in stdout_schemas[command]: stdout_schemas[command].append(schema)
        original_root.append(native)
        root_payloads.append({'index': index, 'command': command, 'pid': made['pid'], 'argv': made['argv'],
                              'created_schema': list(made), 'terminal_schema': list(ended), 'stdout_schema': schema,
                              'stdout_json': payload, 'native_exit': ended['native_exit'], 'wait_returncode': ended['wait_returncode']})
    native_receipts = {}; cleanup_rows = {}; inventories = []; foreign_previous = None; mutation_commands = {}
    roles = literals['ROLES']; project = 'tianli-audit-' + RUN_ID
    for label, result, folder, count in [('close', close, CLOSE, 93), ('zero', zero, ZERO, 20)]:
        assert result['failed_outer_native_exit'] == result['paid_producer_native_exit'] == 1
        assert result['original_completed_native_zero'] == 111 and result['original_total_native_zero'] == 112
        assert result['later_Task6_AR_Worker'] == 'NOT STARTED' and result['full_acceptance'] == 'INCOMPLETE'
        rows = result['operations']; assert len(rows) == count
        native_receipts[label] = []; cleanup_rows[label] = []; mutations = []; pending = None; round_facts = []
        for index, row in enumerate(rows, 1):
            assert row['index'] == index and row['stem'] == '%03d-' % index + re.sub('[^a-z0-9-]', '-', row['label'].lower())
            native, made, ended, streams = four(folder, row['stem'], expected=row)
            native_receipts[label].append({'index': index, 'stem': row['stem'], **native})
            cleanup_rows[label].append({'operation': row, 'created_schema': list(made), 'terminal_schema': list(ended)})
            args = row['argv'][1:]
            if row['argv'][0] == DOCKER:
                if args[:1] in [['stop'], ['rm']] or args[:2] in [['network', 'rm'], ['volume', 'rm']]: mutations.append(args)
            else:
                assert row['argv'][0] == 'git'
                assert args[-2:] == ['rev-parse', 'HEAD'] or args[-3:] == ['status', '--porcelain=v1', '--untracked-files=all']
                assert streams['stdout'] == ((SOURCE+'\n').encode() if args[-1] == 'HEAD' else b'')
            if row['label'] == 'global-cids-before':
                pending = streams['stdout'].decode().splitlines(); assert len(set(pending)) == len(pending)
            elif row['label'] == 'global-safe-consumer-facts':
                facts = [json.loads(line) for line in streams['stdout'].splitlines()]
                assert pending is not None and len(facts) == len(pending) and {x['id'] for x in facts} == set(pending)
                assert all(set(x) == {'id', 'mounts', 'network_mode', 'networks'} for x in facts)
                foreign = {x['id']: x for x in facts if x['id'] not in roles.values()}
                if foreign_previous is None: foreign_previous = foreign
                assert set(foreign) == set(foreign_previous)
                for cid in foreign: inventory_equal(foreign_previous[cid], foreign[cid])
                inventories.append({'scope': label, 'operation_index': index, 'facts': facts})
                round_facts.append(facts)
            elif row['label'] == 'global-cids-after':
                assert pending is not None and set(streams['stdout'].decode().splitlines()) == set(pending); pending = None
        assert pending is None
        if label == 'close':
            assert mutations == [['stop', '--timeout', '-1', roles['audit-mysql']], ['stop', '--timeout', '-1', roles['audit-redis']],
                                 ['rm', *sorted(roles.values())], ['network', 'rm', literals['NETWORK_ID']],
                                 ['volume', 'rm', *sorted(project+'_'+x for x in literals['VOLUME_SUFFIXES'])]]
        else: assert mutations == [] and result['resource_mutations'] == 0
        mutation_commands[label] = mutations
        assert [(x['kind'], x['label']) for x in result['fresh_queries']] == [(kind, key) for kind in ['container', 'network', 'volume'] for key in ['project', 'run']]
        prefixes = {'container': ['ps', '-aq', '--no-trunc'], 'network': ['network', 'ls', '-q'], 'volume': ['volume', 'ls', '-q']}
        for query in result['fresh_queries']:
            row = rows[query['operation_index']-1]
            value = 'com.docker.compose.project='+project if query['label'] == 'project' else 'tianli.audit.run='+RUN_ID
            assert row['argv'] == [DOCKER, *prefixes[query['kind']], '--filter', 'label='+value]
            assert row['stdout_bytes'] == row['stderr_bytes'] == 0
        for filename, facts in [('GLOBAL-BEFORE.json', round_facts[0]), ('GLOBAL-AFTER.json', round_facts[-1])]:
            archive = doc(folder / filename)
            assert archive['container_count'] == len(archive['facts']) == len(facts)
            actual = {x['id']: x for x in facts}; retained = {x['id']: x for x in archive['facts']}
            assert set(actual) == set(retained)
            for cid in actual: inventory_equal(actual[cid], retained[cid])
    assert len(inventories) == 9 and [x['operation'] for x in cleanup_rows['close']+cleanup_rows['zero']] == [
        {k: v for k, v in x.items() if k != 'scope'} for x in observed['native_operations']]
    outer = {}; outer_joins = {}
    for label, folder, stem, code, expected_pid in [
        ('M', r.OLD / 'launcher', 'materialize', 0, 18580), ('S', r.OLD / 'launcher', 'normal-start', 1, 2676),
        ('close', ORG / 'current0c13-failure-close-outer-actual-r1', None, 0, 41152),
        ('zero', ORG / 'current0c13-failure-zero-outer-actual-r1', None, 0, 22076)]:
        files, made, ended, streams = four(folder, stem, code=code)
        assert made['pid'] == expected_pid and Path(made['argv'][0]) == Path(PY)
        outer[label] = files
        outer_joins[label] = {'created': made, 'terminal': ended,
                             'stdout_json_lines': [json.loads(line) for line in streams['stdout'].splitlines()]}
    collector = documents['collector']; collector_made = documents['collector_created']; collector_end = documents['collector_terminal']
    assert collector_made['pid'] == collector_end['pid'] and collector_end['native_exit'] == collector_end['wait_returncode'] == 0
    assert collector_made['argv'] == [DOCKER, 'logs', literals['ROLES']['audit-ms5-paid-producer']]
    assert collector['container_id'] == literals['ROLES']['audit-ms5-paid-producer']
    assert collector['raw_streams_retained'] is False and collector['unprojected_line_count'] == 0
    assert collector['normal_http_response_diagnostic'] == failure['normal_http_response_diagnostic']
    for key, path in [('actual_native_created', COLLECTOR / 'created.json'), ('actual_native_terminal', COLLECTOR / 'terminal.json')]:
        assert r.pin(safe(path, 'NATIVE_CREATED_TERMINAL')) == {k: collector[key][k] for k in ['bytes', 'sha256']}
    collector_source = r.OLD / 'git-blobs/after/help/audit-regressions/orchestration/collect_paid_failure.py'
    safe(collector_source, 'REVIEWED_SOURCE', collector['public_input_pins']['collector_source'])
    inputs['collector_original_frozen_Source'] = ptr(collector_source)
    assert r.pin(safe(CLOSE / 'root-driver-source.py', 'REVIEWED_SOURCE')) == r.pin(safe(SRC / 'normal-close.py', 'REVIEWED_SOURCE'))
    assert r.pin(safe(ZERO / 'root-driver-source.py', 'REVIEWED_SOURCE')) == r.pin(safe(SRC / 'independent-zero.py', 'REVIEWED_SOURCE'))
    CHECKS.update({'M33_fields': list(materialized), 'M_commit_count': 17, 'M_committed_paths': 307,
        'M_native_zero': 1060, 'M_safe_stdout_full': m_safe_stdout, 'M_opaque_stdout_metadata_only': len(m_opaque_stdout),
        'original_Root_Host_native_zero': 112, 'native_close': 93, 'native_zero': 20,
        'exact_close_mutations': mutation_commands['close'], 'zero_mutations': [], 'fresh_empty_queries_each': 6,
        'foreign_inventories': 9, 'peer_safe_complete': 751, 'original_public_files_metadata_only': 44567,
        'original_public_directories_metadata_only': 6860, 'all_mapping_records_metadata_only': 31496,
        'W_Val_old_body_reads': 0, 'opaque_payload_reads': 0, 'target_native_calls': 0,
        'initial_prepare_outside_archive_included_first': True, 'collector_reexecuted': False})
    safe_public = [dict(r.READS[name]) | {'classification': sorted(CLASSIFICATIONS[name])} for name in sorted(CLASSIFICATIONS)]
    safe_pointers = [{k: row[k] for k in ['path', 'bytes', 'sha256', 'native9']} for row in safe_public]
    value = {'schema': 1, 'scope': 'EIGHTH_CURRENT0C13_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY',
        'inputs': inputs, 'native_receipts': native_receipts, 'original_root_native_receipts': original_root,
        'outer_native_receipts': outer, 'Root_actual_complete_public_inputs': safe_pointers,
        'peer_original_safe_public_inputs': peer_safe,
        'original_root_native_documents': root_payloads, 'original_root_stdout_schemas_by_command': stdout_schemas,
        'cleanup_native_documents': cleanup_rows, 'outer_native_documents': outer_joins,
        'M_complete_native_receipts': m_native, 'M_result_schema': list(materialized),
        'M_complete_source_snapshots': snapshots, 'foreign_inventory_full_safe_facts': inventories,
        'ordinary_close_mutations': mutation_commands['close'], 'fresh_queries': {'close': close['fresh_queries'], 'zero': zero['fresh_queries']},
        'opaque_historical_observations': {'policy': 'FINGERPRINT_AND_NATIVE_METADATA_ONLY_NEVER_DEREFERENCE_HISTORICAL_PATHS',
            'peer_observations': inputs['peer_observations'], 'Root_observations': inputs['Root_full_inputs'],
            'original_baseline': ptr(CLOSE / 'PUBLIC-INPUT-PINS.json'), 'original_files': 44567, 'directories': 6860,
            'peer_opaque_rows': 44568, 'body_reads': 0, 'mapping_rows': 31496,
            'opaque_row_schema': list(observed['opaque_files'][0]), 'directory_row_schema': ['path', 'stat9']},
        'safe_read_policy': {'peer_finite_safe_inputs': 751, 'full_safe_reads': len(safe_pointers),
            'categories': dict(collections.Counter(category for row in safe_public for category in row['classification'])),
            'M_git_show_stdout_body_reads': 0, 'M_git_show_stdout_metadata_count': 747,
            'environment_private_application_child_body_reads': 0, 'old_W_Val_body_reads': 0},
        'historical_seven_retirements': 'UNCHANGED_COMPLETE_PREDECESSOR_SEVENTEEN_AUTHORITY',
        'M33_preserved': True, 'M_native_count': 1060, 'M_complete_source_metadata_rows': 31496,
        'M_commit_count': 17, 'M_source_files_committed': 307, 'original_S_native_exit': 1,
        'paid_producer_native_exit': 1, 'original_paid_restart_count': 0, 'A0': False,
        'later_Task6_AR_Worker': 'NOT STARTED', 'business_acceptance': 'INCOMPLETE',
        'HTTP_root_cause': 'UNKNOWN_FIRST_THROWABLE_NOT_PROVED_BY_216_HASH',
        'collector_original_once_only': True, 'private_or_old_source_bodies_read': False,
        'new_source_M_S_business': 'NOT RUN', 'checks': CHECKS}
    r.put('CURRENT-0C13-RETIREMENT-COMPLETE.json', value)
    authority = r.pointer(r.OUT / 'CURRENT-0C13-RETIREMENT-COMPLETE.json')
    report = {'schema': 1, 'status': 'PASS_SOURCE_ONLY_COMPLETE_EIGHTH_RETIREMENT_BINDING',
        'authority': authority, 'authority_fields': list(value), 'inputs_fields': list(inputs),
        'native_receipt_members': ['index', 'stem', 'created', 'terminal', 'stdout', 'stderr'],
        'original_Root_receipt_members': ['created', 'terminal', 'stdout', 'stderr'],
        'safe_public_input_members': ['path', 'bytes', 'sha256', 'native9'], 'checks': CHECKS,
        'safety_classification': value['safe_read_policy'], 'original_business_state': 'S1_PAID1_NO_A0_LATER_NOT_STARTED',
        'historical_baseline': 'SAVED_ORIGINAL_METADATA_NOT_CURRENT_W_VAL',
        'original_collector_reexecution': False, 'original_close_zero_reexecution': False,
        'original_author_or_reader_import_or_execution': False, 'new_business_or_native_execution': False,
        'open_business_acceptance': True, 'reviewer_notes': [
            'A read-only lookup for SRC/DESIGN.md found no file; actual preserved design is DESIGN-TASKS.md.',
            'No historical opaque path was opened. Public source/receipt paths are individually classified before reads.',
            'M33 fields and seventeen commits are neither profile file count nor executed business completion.']}
    r.put('retirement-source/REPORT.json', report)
    r.put('retirement-source/INPUTS.json', {'schema': 1, 'scope': 'FULL_SAFE_FILE_READ_WINDOWS_NO_OPAQUE_BODY',
        'files': safe_public, 'opaque_metadata_files_read': [inputs['peer_observations'], inputs['Root_full_inputs']],
        'all_windows_ended': True})
    names = ['retirement-author.py', 'CURRENT-0C13-RETIREMENT-COMPLETE.json', 'retirement-source/REPORT.json', 'retirement-source/INPUTS.json']
    r.put('retirement-source/PINS.json', {'schema': 1, 'scope': 'SOURCE_ONLY_EIGHTH_RETIREMENT_COMPLETE_PUBLIC_BINDING',
        'files': {name: r.pin(r.read(r.OUT / name)) for name in names}, 'authority': authority,
        'authority_fields': list(value), 'safe_public_inputs': len(safe_pointers), 'native_close_zero': [93, 20],
        'Root_Host_native': 112, 'M_native': 1060, 'business': 'INCOMPLETE'})
    print(json.dumps({'authority': authority, 'pins': r.pointer(r.OUT / 'retirement-source/PINS.json'),
                      'safe_public_inputs': len(safe_pointers), 'Root_Host_native': 112, 'native_close_zero': [93, 20]}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        failure = traceback.format_exc()
        root = r.OUT / 'retirement-source'
        root.mkdir(exist_ok=True)
        index = 1
        while (root / ('ERROR-%02d.json' % index)).exists(): index += 1
        r.put('retirement-source/ERROR-%02d.json' % index, {'schema': 1, 'status': 'OWN_SOURCE_AUTHOR_ERROR_PRESERVED',
              'traceback': failure, 'source': r.pointer(Path(__file__)), 'target_calls': 0})
        r.write('retirement-source/ERROR-%02d-retirement-author.py' % index, Path(__file__).read_bytes())
        raise
