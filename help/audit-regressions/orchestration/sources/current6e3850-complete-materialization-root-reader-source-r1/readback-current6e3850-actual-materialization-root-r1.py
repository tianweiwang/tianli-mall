import argparse
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys

sys.dont_write_bytecode = True
parser = argparse.ArgumentParser()
for name in ('result-bytes', 'freeze-bytes', 'outer-pid', 'source-pins-bytes'):
    parser.add_argument('--' + name, type=int, required=True)
for name in ('result-sha256', 'freeze-sha256', 'source-pins-sha256'):
    parser.add_argument('--' + name, required=True)
args = parser.parse_args()
assert args.result_bytes > 0 and args.freeze_bytes > 0 and args.outer_pid > 0
assert all(re.fullmatch('[a-f0-9]{64}', value) for value in (args.result_sha256, args.freeze_sha256))
B = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
SEALED_SOURCE_PINS = {'bytes': 68413, 'sha256': 'd3e61099b79c564658d3c2a2d56407501b47583790c44c34bbdd3617da1b4f54'}
assert args.source_pins_bytes == SEALED_SOURCE_PINS['bytes'] and args.source_pins_sha256 == SEALED_SOURCE_PINS['sha256']
helper = B / 'customer-form-current-three-front-material-producer-independent-r1/review.py'
helper_raw = helper.read_bytes()
assert len(helper_raw) == 33902 and hashlib.sha256(helper_raw).hexdigest() == '4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'
sp = importlib.util.spec_from_file_location('current6e3850_actual_M_readonly', helper)
h = importlib.util.module_from_spec(sp)
sp.loader.exec_module(h)
r = h.Finite()
r.read(helper, h.fp(helper_raw))
source = B / 'full-a-preflight-diagnostic-current6e3850-normal-next-source-r2'
actual = source / 'validation/materialize-freeze-assets-r1'
source_pins = r.json(source / 'PINS.json', {'bytes': args.source_pins_bytes, 'sha256': args.source_pins_sha256})
raw = r.read(actual / 'RESULT.json', {'bytes': args.result_bytes, 'sha256': args.result_sha256})
result = h.loads(raw)
assert result['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert result['source_sha'] == source_pins['source_sha'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
assert result['parent'] == source_pins['parent'] == '175f3cd6183894185656a2974579e1be39eed7a7'
program = r.read(source / 'materializer/prepare.py', source_pins['files']['materializer/prepare.py'])
source_constants = {node.targets[0].id: ast.literal_eval(node.value) for node in ast.parse(program).body if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id in {'COMMITTED_PATHS', 'COMMIT_CHAIN', 'CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT', 'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT', 'CURRENT_M_RESULT_KEYS35', 'CURRENT_M_RESULT_KEYS36'}}
keys = source_constants['CURRENT_M_RESULT_KEYS36']
previous_keys35 = source_constants['CURRENT_M_RESULT_KEYS35']
previous_keys34 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
assert set(result) == set(keys) and len(result) == len(keys) == 36
assert [key for key in keys if key != 'current_175f_paid_producer_failure_retirement'] == previous_keys35
assert [key for key in previous_keys35 if key != 'current_28dc_paid_producer_failure_retirement'] == previous_keys34
assert result['commit_chain'] == source_constants['COMMIT_CHAIN'] and len(source_constants['COMMIT_CHAIN']) == 20
assert [row['path'] for row in result['committed_files']] == source_constants['COMMITTED_PATHS']
ops = result['native_operations']
budget = 6 + sum(2 + 2 * len(step['files']) for step in result['commit_chain']) + 1 + len(result['committed_files']) + 2 + 3 + 1 + 4
source_budget = 6 + sum(2 + 2 * len(step['files']) for step in source_constants['COMMIT_CHAIN']) + 1 + len(source_constants['COMMITTED_PATHS']) + 2 + 3 + 1 + 4
assert len(ops) == budget == source_budget == 1104
for operation in ops:
    stem = operation['stem']
    assert r.json(actual / (stem + '.terminal.json')) == operation
    made = r.json(actual / (stem + '.created.json'))
    assert set(made) == {'pid', 'argv'}
    assert made['pid'] == operation['pid'] and made['argv'] == operation['argv']
    assert operation['native_exit'] == operation['wait_returncode'] == 0
    for stream in ('stdout', 'stderr'):
        value = r.read(actual / (stem + '.' + stream), {'bytes': operation[stream + '_bytes'], 'sha256': operation[stream + '_sha256']})
        if stream == 'stderr':
            assert value == b''
assert len({op['stem'] for op in ops}) == len(ops)
outer = r.json(source / 'launcher/materialize.terminal.json')
made = r.json(source / 'launcher/materialize.created.json')
assert set(made) == {'pid', 'argv', 'started_ns'}
assert outer['pid'] == made['pid'] == args.outer_pid and outer['native_exit'] == outer['wait_returncode'] == 0
for stream in ('stdout', 'stderr'):
    value = r.read(source / ('launcher/materialize.' + stream), outer[stream])
    if stream == 'stderr':
        assert value == b''
assert r.read(actual / 'root-materialize-source.py', result['materialize_source']) == program
freeze_raw = r.read(Path(result['freeze']), {'bytes': args.freeze_bytes, 'sha256': args.freeze_sha256})
assert result['freeze_bytes'] == args.freeze_bytes and result['freeze_sha256'] == args.freeze_sha256
freeze = h.loads(freeze_raw)
assert freeze['status'] == 'CLEAN_SOURCE_FROZEN_ONLY' and freeze['source_sha'] == result['source_sha']
groups = {'driver': 'driver_source_files', 'customer': 'customer_source_files', 'paid': 'paid_source_files', 'fulfillment': 'fulfillment_source_files', 'aftersale_review': 'aftersale_review_source_files', 'worker': 'worker_source_files'}
assert result['source_counts'] == {'driver': 10, 'customer': 10865, 'paid': 10866, 'fulfillment': 10881, 'aftersale_review': 10885, 'worker': 10886}
combined = {}
for key, field in groups.items():
    members = freeze[field]
    assert len(members) == result['source_counts'][key]
    for row in members:
        assert row['path'] not in combined or combined[row['path']] == row
        combined[row['path']] = row
assert len(combined) == result['unique_source_files_actual_read'] == 10886
r.read(source / 'validation/PINS.json', result['root_source_pins'])
root_snapshot = r.json(Path(result['root_source_snapshot']['path']), result['root_source_snapshot'])
snapshot = r.json(Path(result['complete_source_snapshot']['path']), result['complete_source_snapshot'])
assert len(root_snapshot) == 14 and len(snapshot) == 31496
qualification = r.json(source / 'CURRENT-SOURCE-QUALIFICATION.json', source_pins['files']['CURRENT-SOURCE-QUALIFICATION.json'])
inventory = next(row['stdout'] for row in qualification['operations'] if row['stem'] == 'main-tracked')
inventory_raw = r.read(Path(inventory['path']), inventory)
assert inventory_raw.endswith(b'\0')
names = sorted(x.decode('utf-8') for x in inventory_raw[:-1].split(b'\0'))
assert len(names) == len(set(names)) == 15748
publication = r.json(source / 'validation/PROFILE-PUBLICATION.json', source_pins['files']['validation/PROFILE-PUBLICATION.json'])
assert publication['source_sha'] == result['source_sha'] and publication['parent'] == result['parent']
assert publication['finite_contexts']['commit_chain'] == result['commit_chain']
code = {row['path']: {key: row[key] for key in ('bytes', 'sha256')} for row in publication['repository_Source_requirements']}
assert len(code) == 313
current_source_authority = r.json(source / 'CURRENT-SOURCE-AUTHORITY.json', source_pins['files']['CURRENT-SOURCE-AUTHORITY.json'])
assert current_source_authority['source_sha'] == result['source_sha'] and current_source_authority['parent'] == result['parent']
assert result['commit_chain'][-1]['files'] == current_source_authority['files'] and len(current_source_authority['files']) == 7
assert result['commit_chain'][-1]['commit'] == {'path': (source / 'CURRENT-SOURCE-AUTHORITY.json').as_posix(), **h.fp(r.read(source / 'CURRENT-SOURCE-AUTHORITY.json', source_pins['files']['CURRENT-SOURCE-AUTHORITY.json']))}
assert all(row['before'] is not None for row in result['commit_chain'][-1]['files'])

for root in ('H:/天俪商城/worktrees/ms4-asset-consumers', 'H:/天俪商城/worktrees/ms4-aftersale-normal-check'):
    prefix = root + '/'
    actual_names = sorted(path[len(prefix):] for path in snapshot if path.startswith(prefix))
    assert actual_names == names
    for path, expected in code.items():
        assert {key: snapshot[prefix + path][key] for key in ('bytes', 'sha256')} == expected
for mapping in (root_snapshot, snapshot):
    for name, row in mapping.items():
        assert row['path'] == name and type(row['bytes']) is int and row['bytes'] >= 0
        assert type(row['stat9']) is list and len(row['stat9']) == 9 and all(type(value) is int for value in row['stat9'])
        assert row['stat9'][6] == row['bytes']
for name, row in root_snapshot.items():
    path = Path(name)
    r.read(path, row)
    assert r.rows[str(path)]['named_before'] == row['stat9']
for row in result['selected9']:
    assert row in freeze['artifact_files']
assert len(result['selected9']) == len(freeze['artifact_files']) == 9
assert len(result['commit_chain']) == 20 and len(result['committed_files']) == 307
assert result['commit_chain'][-2]['source_sha'] == result['parent'] and len(result['commit_chain'][-2]['files']) == 4
assert result['Run_created'] is False and result['App_DB_HTTP_business'] == 'NOT RUN'
eighth_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-upload-root-current28dc-normal-next-source-r1/CURRENT-0C13-RETIREMENT-COMPLETE.json', 'bytes': 7629389, 'sha256': '0c912bb891d4cf0c2f45817aa9241cf0dfc453fd36ed7258c80f051276dddf5b'}
eighth = r.json(Path(eighth_pointer['path']), eighth_pointer)
assert publication['finite_contexts']['current_0c13_paid_producer_failure_retirement'] == eighth_pointer
assert result['current_0c13_paid_producer_failure_retirement'] == eighth
assert eighth['original_S_native_exit'] == eighth['paid_producer_native_exit'] == 1 and eighth['business_acceptance'] == 'INCOMPLETE'
seventh_pointer = publication['finite_contexts']['current_881_paid_producer_failure_retirement']
seventh = r.json(Path(seventh_pointer['path']), seventh_pointer)
assert result['current_881_paid_producer_failure_retirement'] == seventh
for field in ('current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement'):
    previous = result[field]
    assert previous['original_S_native_exit'] == previous['paid_producer_native_exit'] == 1 and previous['business_acceptance'] == 'INCOMPLETE'
ninth_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/CURRENT-28DC-RETIREMENT-COMPLETE.json', 'bytes': 33024728, 'sha256': '65cdd89dcd9d02247b99705af76322ecffedb5cd18b47c85776ac4b7fb7c01c8'}
ninth = r.json(Path(ninth_pointer['path']), ninth_pointer)
assert ninth_pointer == source_constants['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']
assert ninth_pointer == {'path': Path(ninth_pointer['path']).as_posix(), **h.fp(r.read(Path(ninth_pointer['path']), ninth_pointer))}
assert publication['finite_contexts']['current_28dc_paid_producer_failure_retirement'] == ninth_pointer
assert result['current_28dc_paid_producer_failure_retirement'] == ninth
assert ninth['scope'] == 'NINTH_CURRENT28DC_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert ninth['run_id'] == '202610231609185fbd23' and ninth['source_sha'] == result['commit_chain'][-2]['parent'] == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
assert ninth['original_S_native_exit'] == ninth['paid_producer_native_exit'] == 1 and ninth['business_acceptance'] == 'INCOMPLETE'
assert ninth['M34_preserved'] is True and ninth['M_native_count'] == 1078 and ninth['M_commit_count'] == 18 and ninth['M_complete_source_metadata_rows'] == 31496
assert ninth['historical_eight_retirements']['current_0c13_paid_producer_failure_retirement'] == eighth

tenth_pointer = source_constants['CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT']
assert publication['finite_contexts']['current_175f_paid_producer_failure_retirement'] == tenth_pointer
tenth = r.json(Path(tenth_pointer['path']), tenth_pointer)
assert result['current_175f_paid_producer_failure_retirement'] == tenth
assert tenth['scope'] == 'TENTH_CURRENT175F_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert tenth['run_id'] == '20261023182634096f57' and tenth['source_sha'] == result['parent']
assert tenth['original_S_native_exit'] == tenth['paid_producer_native_exit'] == 1 and tenth['business_acceptance'] == 'INCOMPLETE'
assert tenth['M35_preserved'] is True and tenth['M_native_count'] == 1088 and tenth['M_commit_count'] == 19 and tenth['M_complete_source_metadata_rows'] == 31496
historical_nine_fields = ('prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement')
assert set(tenth['historical_nine_retirements']) == set(tenth['historical_nine_whole_encoded_pins']) == set(historical_nine_fields)
for field in historical_nine_fields:
    assert result[field] == tenth['historical_nine_retirements'][field]
    assert h.fp(h.enc(result[field])) == tenth['historical_nine_whole_encoded_pins'][field]
assert tenth['historical_nine_retirements']['current_28dc_paid_producer_failure_retirement'] == ninth
assert set(tenth['forbidden_environment_metadata']) == {'rows', 'body_reads', 'hashes_claimed'}
forbidden_metadata = tenth['forbidden_environment_metadata']['rows']
assert len(forbidden_metadata) == 2 and tenth['forbidden_environment_metadata']['body_reads'] == 0 and tenth['forbidden_environment_metadata']['hashes_claimed'] is False
for row in forbidden_metadata:
    assert set(row) == {'path', 'stat9', 'birthtime_ns', 'file_attributes'} and len(row['stat9']) == 9

assert result['selected9'] == publication['selected9']


CALLBACK_ROOT_POINTER = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-source-callback-root-admission-r2/ACTUAL-READBACK.json', 'bytes': 1534, 'sha256': '1267c9428dcec57a7a11fe79cdc88372cfa917d23dc2e0f4350de4224b9a0477'}
CALLBACK_ROOT_EXPECTED = {'schema': 1, 'status': 'PASS_ROOT_ACTUAL_COMPLETE_ORIGINAL_R2_SOURCE_CALLBACKS0_PUBLIC_RECORDS', 'actual_REPORT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-original-source-callback-actual-r2/REPORT.json', 'bytes': 5621, 'sha256': 'f2c5910fb4ba2dd3137133c5b51adccee5e98e1dbe8692b6a75288a4d4fdb1cf'}, 'actual_INPUTS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-original-source-callback-actual-r2/INPUTS.json', 'bytes': 42084153, 'sha256': '7234db16fdd27ec298d059cfcfbddc9be1d1389ee308dd9155b1d3a9dde43954'}, 'native_pid': 52348, 'native_exit': 0, 'wait_returncode': 0, 'whole_created_terminal_stdout_stderr_join': True, 'complete_actual_M_and_S_calls_each_checks': 7904982, 'complete_return_objects_equal': True, 'complete_canonical_return_pin': {'bytes': 96760186, 'sha256': 'a6c3c7ad75a16ea2493794b2bfc9181ddb518ce1e69d217f2e888a9731650ed3'}, 'genuine_fixed_r2_source_entries_and_declared_namespaces': True, 'all25939_original_first_EOF_own9_cross4_closed_windows_validated': True, 'original_cache_semantics_preserved_no_two_independent_disk_reads_claim': True, 'Root_did_not_reopen_all25939_bodies': True, 'current175f_original_outer_M_S_stream_bodies_absent': True, 'current175f_named_ENV_bodies_absent': True, 'Root_own_public_file_windows': 7, 'Root_own_first_final_windows_ended': True, 'callback_native_calls': 0, 'new_M_S_App_business': 'NOT RUN', 'A0': False}

callback_root = r.json(Path(CALLBACK_ROOT_POINTER['path']), CALLBACK_ROOT_POINTER)
assert callback_root == CALLBACK_ROOT_EXPECTED
callback_report = r.json(Path(callback_root['actual_REPORT']['path']), callback_root['actual_REPORT'])
callback_inputs = r.json(Path(callback_root['actual_INPUTS']['path']), callback_root['actual_INPUTS'])
assert callback_report['status'] == 'PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY'
assert callback_report['source_PINS'] == {'path': (source / 'PINS.json').as_posix(), **SEALED_SOURCE_PINS}
assert callback_report['stages'] == 20 and callback_report['retirements'] == 10 and callback_report['tracked'] == 15748 and callback_report['committed'] == 307
assert callback_report['complete_return_objects_equal'] is True and callback_report['callback_native_calls'] == 0 and callback_report['M_body_or_S_main_or_App_business'] == 'NOT RUN' and callback_report['A0'] is False
assert callback_root['complete_canonical_return_pin'] == callback_report['complete_canonical_return_pin']
assert callback_root['complete_actual_M_and_S_calls_each_checks'] == 7904982
assert set(callback_report['qualification']) == {'M', 'S'}
for label, item in callback_report['qualification'].items():
    assert item['module'] == label and item['manual_dependency_injection'] is False and item['unbound_globals'] == [] and item['pure_source_checks'] == 7904982
assert set(callback_inputs) == {'files', 'cache_semantics', 'callback_invocations'} and callback_inputs['callback_invocations'] == 2
assert callback_inputs['cache_semantics'] == 'ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
callback_files = callback_inputs['files']
assert len(callback_files) == callback_report['actual_input_paths'] == 25939
entry_names = {'source_reader.py', 'author_support.py', 'materializer/prepare.py', 'validation/run.py', 'validation/PROFILE-PUBLICATION.json'}
assert set(callback_report['entries']) == entry_names
bootstrap = {(source / 'PINS.json').as_posix(): SEALED_SOURCE_PINS, **{(source / name).as_posix(): wanted for name, wanted in callback_report['entries'].items()}}
assert len(bootstrap) == 6 and set(bootstrap) <= set(callback_files)
for name in entry_names:
    assert callback_report['entries'][name] == {key: source_pins['files'][name][key] for key in ('bytes', 'sha256')}
for path, wanted in bootstrap.items():
    assert {key: callback_files[path][key] for key in ('bytes', 'sha256')} == wanted
for path, row in callback_files.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'native9', 'handle_before', 'handle_after', 'path_before', 'path_after', 'EOF', 'closed', 'started_ns', 'ended_ns'}
    assert row['path'] == path and row['native9'] == row['path_before'] == row['path_after'] and row['handle_before'] == row['handle_after']
    assert len(row['native9']) == len(row['handle_before']) == 9 and row['native9'][6] == row['bytes']
    assert [row['native9'][i] for i in (0, 1, 6, 7)] == [row['handle_before'][i] for i in (0, 1, 6, 7)]
    assert row['EOF'] is row['closed'] is True and callback_report['started_ns'] <= row['started_ns'] <= row['ended_ns'] <= callback_report['ended_ns']
authority_names = set(callback_files) - set(bootstrap)
assert len(authority_names) == 25933
authority_path = actual / 'AUTHORITY-INPUT-SNAPSHOT.json'
authority_raw = r.read(authority_path)
authority_snapshot = h.loads(authority_raw)
assert set(authority_snapshot) == authority_names
for path, row in authority_snapshot.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == path
    assert {key: row[key] for key in ('bytes', 'sha256')} == {key: callback_files[path][key] for key in ('bytes', 'sha256')}
    assert row['stat9'] == callback_files[path]['native9'] and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
for row in forbidden_metadata:
    assert row['path'] not in callback_files and row['path'] not in authority_snapshot
assert len(source_pins['files']) == 115 and len(source_pins['source_members18']) == 18
assert callback_report['group_counts'] == result['source_counts']

ended = r.end()
out = B / 'current6e3850-complete-materialization-root-readback-r1'
out.mkdir(exist_ok=False)
(out / 'INPUTS.json').write_bytes(h.enc({'files': list(r.rows.values()), 'all_windows_ended': True, 'ended_ns': ended}))
report = {
    'status': 'PASS_ACTUAL_FULL_CURRENT6E3850_MATERIALIZATION_ONLY', 'source_sha': result['source_sha'],
    'actual_RESULT': {'path': (actual / 'RESULT.json').as_posix(), **h.fp(raw)},
    'actual_freeze': {'path': result['freeze'], **h.fp(freeze_raw)}, 'native_operations': budget,
    'all_original_native_created_terminal_and_streams_join': True, 'outer_pid': args.outer_pid, 'outer_native_exit_wait': 0,
    'M_RESULT_fields': 36, 'historical_M_RESULT_fields': 35, 'historical_before_ninth_fields': 34, 'complete_source_snapshot_rows': 31496, 'root_source_members': 14,
    'steps': len(result['commit_chain']), 'tracked_per_checkout': 15748, 'cumulative': 307, 'source_group_union': 10886,
    'eighth_actual_failed_A_retirement_preserved': True, 'old_seven_retirements_preserved': True,
    'previous_seventh_whole_pinned_authority_and_result_join_preserved': True, 'reader_revision': 1,
    'ninth_actual_failed_A_whole_pinned_authority_and_result_join_preserved': True,
    'tenth_actual_failed_A_whole_pinned_authority_and_result_join_preserved': True, 'all_prior_nine_whole_encoded_objects_preserved': True,
    'original_complete_callback_Root_qualification': CALLBACK_ROOT_POINTER,
    'original_complete_callback_REPORT': callback_root['actual_REPORT'], 'original_complete_callback_INPUTS': callback_root['actual_INPUTS'],
    'callback_bootstrap_controls': 6, 'callback_complete_inputs': 25939, 'M_complete_authority_inputs': len(authority_snapshot),
    'authority_snapshot': {'path': authority_path.as_posix(), **h.fp(authority_raw)},
    'full_authority_namespace_bytes_hash_stat9_joined_to_real_complete_callback': True,
    'forbidden_ENV_metadata_files': 2, 'forbidden_ENV_body_reads': 0,
    'both_checkouts_all313_required_file_metadata_bytes_joined': True, 'last_commit_exact_seven_modified_paths': True, 'historical_nineteenth_commit_exact_four_modified_paths': True,
    'finite_reads': len(r.rows), 'all_input_windows_ended': True,
    'old_source_bodies': 'NOT REREAD',
    'new31496_snapshot': 'FULL_METADATA_READ; ORIGINAL_M_FULL_SOURCE_READ_METHOD_AND_NATIVE_RECEIPTS_VERIFIED',
    'Run_App_DB_HTTP_business': 'NOT RUN', 'A0': 'NOT CREATED',
}
(out / 'REPORT.json').write_bytes(h.enc(report))
print(json.dumps({'REPORT': h.fp(h.enc(report)), 'finite_reads': len(r.rows), 'M': h.fp(raw), 'freeze': h.fp(freeze_raw)}))
