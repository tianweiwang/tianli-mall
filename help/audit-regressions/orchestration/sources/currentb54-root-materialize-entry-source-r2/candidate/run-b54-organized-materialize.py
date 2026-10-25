from pathlib import Path
import hashlib, json, os, subprocess, sys, time

ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
OUT = ORG / 'currentb54-organized-materialize-actual-r2'
ADM = ORG / 'current41c0-normal-root-admission-r1'
SOURCE = ORG / 'currentb54-next-normal-preparation-r3/normal-source'
PY = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'

def fp(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}

def put(name, value):
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()
    with (OUT / name).open('xb') as handle:
        assert handle.write(raw) == len(raw)

assert not sys.flags.optimize
# This new b54 entry preserves the earlier organized native protocol and never retries old M.
# Existing original M or any outer receipt is a refusal, never an invitation to retry.
assert not OUT.exists(), 'New organized materialize OUT must be absent'
assert not (SOURCE / 'validation/materialize-freeze-assets-r1').exists()
assert not (SOURCE / 'launcher/materialize.created.json').exists()
assert not (SOURCE / 'launcher/materialize.terminal.json').exists()
reports = {}
bafb_finite = None
for name, path, size, digest in [
    ('Root_Source', ADM / 'R106-B54-SOURCE-CALLBACK-ADMISSION.json', 50803, '01f8d3b604e4297ab0ea159691379c80566b10e2664ed2417cbebe889bb6077e'),
    ('independent_Source', ORG / 'currentb54-complete-normal-independent-source-r3/REPORT.json', 4518, 'd4ab6f169f11a4eb6b4119e201256d42b99a450e3030ee7b21398bfca048b556'),
    ('Root_actual_callback_readback', ADM / 'R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json', 4131, '2426a231bff2d7655b8dede264be984629a1a28ef1e5edbf3f502bede4445d63'),
    ('d0_failed_finite_closure', ADM / 'R98-D0A5-FAILED-FINITE-HISTORY-INDEX.json', 3972, 'c43abf997074d602d56b839791412e281dfb334ad826579b0a9fae3b7e31a35f'),
    ('bafb_failed_finite_closure', ADM / 'R102-BAFB-FAILED-FINITE-HISTORY-INDEX.json', 4373, 'ccb12a606de63db21ec621f90f913fed306fa7e4783346070533adcc2c6bb445'),
    ('bafb_close_Root', ADM / 'R101-CLOSE-ACTUAL-ROOT-READBACK.json', 1055, '42255895bef979be21035689b734710c591215d35f2dd7cae50e5266c4e2be79'),
    ('bafb_zero_Root', ADM / 'R101-ZERO-ACTUAL-ROOT-READBACK.json', 1051, '990ad026c1f085c80efdbcbbcf652ecbffcfd645ae330c59bd9b2f796b5eeafc'),
    ('bafb_finite_own_outputs', ADM / 'R102-BAFB-FAILED-FINITE-OWN-OUTPUTS.json', 402508, '28fa139a35022386d5e3fcea8a79bf4e3b72e8fa5969307253c4b8af3b40094d')]:
    raw = path.read_bytes()
    assert fp(raw) == {'bytes': size, 'sha256': digest}
    value = json.loads(raw)
    if name == 'independent_Source':
        assert value['finding_count'] == 0 and value['findings'] == [] and value['status'] == 'INDEPENDENT_R3_NARROW_SOURCE_REVIEW0_ACTIVE_ROOT_AND_NECESSARY_PIN_DAG_ONLY'
        assert value['Core_PINS'] == {'path': (SOURCE / 'PINS.json').as_posix(), 'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}
    elif name == 'Root_actual_callback_readback':
        assert value['status'] == 'ROOT_ACTUAL_COMPLETE_B54_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY'
        assert value['actual_PID'] == 44748 and value['native_exit'] == value['wait_returncode'] == 0
        assert value['M_and_S_each_full_checks'] == 11940693 and value['complete_return_objects_equal'] is True
        assert value['actual_source_paths'] == 33091 and value['callback_native_calls'] == 0
        assert value['canonical_return_pin'] == {'bytes': 198655490, 'sha256': 'ddfd325dfeb7041446364edbffcfb346dad8984994d357a9d1af5f38bcc8ffda'}
        assert value['complete_streams_join'] is value['Root_all_windows_ended'] is True and value['M_body_S_main_App_business'] == 'NOT RUN' and value['A0'] is False
        assert value['all_original_first_EOF_native9_cross4_closed_windows_verified'] is value['original_cache_semantics_preserved_no_second_disk_read_claim'] is True
        assert value['Root14_M3_independent17_files_held_after_actualcallbacks'] is True and value['original_bafb_Run_body_paths_absent'] is True and value['mutable_original_C_D_not_consumed'] is True
        assert value['new_finite_own_output_registry_counts'] == {'close': 367, 'zero': 53}
        assert value['normal_Source_PINS'] == {'path': (SOURCE / 'PINS.json').as_posix(), 'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}
    elif name == 'Root_Source':
        assert value['scope'] == 'TWO_ORIGINAL_FULL_SOURCE_AUTHORITY_CALLBACKS_ONLY_AFTER_ROOT_AND_INDEPENDENT_SOURCE_REVIEW'
        assert value['status'] == 'ROOT_AND_FRESH_R3_CALLBACK_R2_SOURCE0_AFTER_IDENTICAL_PHP8_CURRENT_PYTHON_AND_ROOT17'
        assert value['full_current_runtime_and_entry_PINS_full_bytes_and_names_checked'] is True
        assert value['Root17_EOF_native9_qualified_and_held'] is True
        assert value['prior_normal8_Root40_readback0_for_identical_PHP8'] is value['same_eight_PHP_current_bodies_equal_prior_actual_PHP82_lint_bodies'] is value['current_M_S_launcher_Python_compile0_separate_from_Docker_native8'] is True
        assert value['new_Docker8_and_PHP8_lint_rerun'] is False
        assert value['M_body_S_main_App_business_admitted'] is False
        for row in value['Root_fixed_refs'] + value['independent_reports']:
            assert fp(Path(row['path']).read_bytes()) == {key: row[key] for key in ('bytes', 'sha256')}
    elif name == 'd0_failed_finite_closure':
        assert value['run_id'] == '20261024114233c6e65e' and value['source_sha'] == 'd0a5d5398546e223122b5c2a2f115e1908f78ced'
        assert value['original_S_native_exit_wait'] == 1 and value['Task6_exit0_retired'] is True
        assert {key: value['counts'][key] for key in ('close_native', 'independent_zero_native', 'current_residual')} == {'close_native': 90, 'independent_zero_native': 12, 'current_residual': 0}
    elif name == 'bafb_failed_finite_closure':
        bafb_finite = value
        assert value['scope'] == 'ROOT_QUALIFIED_CURRENT_BAFB_FAILED_FINITE_HISTORY_ONLY_NOT_A_NEW_WHOLE_HISTORY_OR_SUCCESS'
        assert value['run_id'] == '20261024174817c66be6' and value['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
        assert value['original_S_pid'] == 20556 and value['original_S_native_exit_wait'] == value['aftersale_review_exit'] == 1 and value['Task6_exit0_retired'] is True and value['worker'] == 'NOT_RUN'
        assert value['counts'] == {'close_native': 90, 'independent_zero_native': 12, 'mutations': 5, 'containers_removed': 4, 'network_removed': 1, 'volumes_removed': 9, 'current_residual': 0, 'original_root_controls': 137, 'separate_prepare_controls': 1, 'total_original_controls': 138, 'existing_public_full_body_reads': 316, 'other_Run_files_metadata_only': 11057, 'foreign_complete_safe_containers': 53, 'close_complete_outputs': 367, 'zero_complete_outputs': 53}
    elif name in ('bafb_close_Root', 'bafb_zero_Root'):
        mode = 'close' if name == 'bafb_close_Root' else 'zero'
        assert value['status'] == ('ROOT_COMPLETE_CLOSE_NATIVE_OUTPUTS_READBACK0' if mode == 'close' else 'ROOT_COMPLETE_ZERO_NATIVE_OUTPUTS_READBACK0')
        assert value['result'] == bafb_finite['qualified_public'][mode + '_result']
        assert value['native_operations'] == (90 if mode == 'close' else 12) and value['ordinary_mutations'] == (5 if mode == 'close' else 0)
        assert value['complete_output_files'] == (367 if mode == 'close' else 53) and value['fresh_six_native_empty'] is True and value['foreign_preserved'] == 53
        assert value['original_Run_and_Source_metadata_preserved'] is value['failed_S1_AR1_and_Task6_0_retained'] is True
        assert value['original_private_and_M_S_raw_bodies_read'] is False and value['business_acceptance'] == 'INCOMPLETE'
    else:
        assert name == 'bafb_finite_own_outputs' and value['scope'] == 'ROOT_PRECISE_EXISTING_BAFB_FAILED_RETIREMENT_OWN_OUTPUT_CLOSED_LIST_NOT_RUN_OUTPUTS'
        assert value['failed_finite_index'] == reports['bafb_failed_finite_closure'] and value['source_sha'] == bafb_finite['source_sha']
        assert value['full_closed_namespace_files'] == 420 and value['full_closed_namespace_bytes'] == 12661425
        assert value['all420_full_body_EOF_and_named_FD_held_windows'] is value['only_two_fixed_prequalified_own_output_directories'] is value['original_Result_and_Source_body_pins_joined_to_prior_Root_proofs'] is True
        assert value['original_Run_private_ENV_actor_M_S_stream_reads'] is value['Docker_or_any_mutation_to_old_materials'] is False
        for mode, count, native_count in [('close', 367, 90), ('zero', 53, 12)]:
            own = value[mode]
            root = ORG / 'currentbafb-aftersale-failed-retirement-source-r1' / ('actual-' + mode + '-r1')
            assert own['root'] == root.as_posix() and own['all_own_output_files_EOF'] == len(own['output_files']) == count and own['original_native_ledger_count'] == native_count
            assert own['original_qualified_result'] == bafb_finite['qualified_public'][mode + '_result'] and own['original_Root_complete_readback'] == reports['bafb_' + mode + '_Root']
            assert len({row['path'] for row in own['output_files']}) == count
            assert all(set(row) == {'path', 'bytes', 'sha256'} and Path(row['path']).is_relative_to(root) and Path(row['path']) != root and '..' not in Path(row['path']).parts for row in own['output_files'])
    reports[name] = {'path': path.as_posix(), **fp(raw)}
pins = (SOURCE / 'PINS.json').read_bytes()
assert fp(pins) == {'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}
packet = json.loads(pins)
assert packet['source_sha'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53' and packet['parent'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
assert len(packet['files']) == packet['member_count'] == 33 and len(packet['source_members18']) == 18
for name, row in packet['files'].items():
    assert set(row) == {'path', 'bytes', 'sha256'} and Path(row['path']) == SOURCE / name
    assert fp((SOURCE / name).read_bytes()) == {key: row[key] for key in ('bytes', 'sha256')}
launch = SOURCE / 'launcher/launch.py'
assert fp(launch.read_bytes()) == {'bytes': 19274, 'sha256': '60c89ce65be5fb860de9fff612011bb8989f1e50a92bf3260db4c521ff5f3e53'}
OUT.mkdir(exist_ok=False)
put('B54-MATERIALIZATION-ADMISSION.json', {
    'scope': 'ONE_ORGANIZED_CURRENTB54_R3_SOURCE_MATERIALIZATION_ENTRY_ONLY',
    'historical_original_bafb_M_launched_by_this_new_entry': False,
    'source_PINS': {'path': (SOURCE / 'PINS.json').as_posix(), **fp(pins)},
    'reviews': reports, 'source_callbacks_actual_native_wait': 0,
    'source_callbacks_each_checks': 11940693, 'source_callbacks_paths': 33091,
    'Root14_M3_after_callbacks_held': True,
    'expected_fields_from_Source': 37, 'expected_chain_from_Source': 25,
    'native_budget_derived_not_actual': 1160,
    'complete_tracked_expected': 15752, 'required_per_checkout': 318,
    'actual_M_PID_RESULT_freeze_native': 'UNBOUND_BEFORE_THIS_LAUNCH',
    'App_DB_HTTP_business_or_Run_creation_admitted': False,
    'next_normal_S_admission': False})
argv = [PY, '-I', '-B', '-X', 'utf8', str(launch), 'materialize']
env = dict(os.environ)
env.pop('PYTHONOPTIMIZE', None)
started = time.time_ns()
p = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
made = {'pid': p.pid, 'argv': argv, 'started_ns': started}
put('r3-materialize-launcher.created.json', made)
stdout, stderr = p.communicate()
waited = p.wait()
for name, raw in [('r3-materialize-launcher.stdout', stdout), ('r3-materialize-launcher.stderr', stderr)]:
    with (OUT / name).open('xb') as handle:
        assert handle.write(raw) == len(raw)
put('r3-materialize-launcher.terminal.json', {
    **made, 'native_exit': p.returncode, 'wait_returncode': waited,
    'finished_ns': time.time_ns(), 'stdout': fp(stdout), 'stderr': fp(stderr)})
print(json.dumps({'pid': p.pid, 'native_exit': p.returncode, 'wait_returncode': waited,
                  'stdout': stdout.decode('utf8', 'replace'), 'stderr': stderr.decode('utf8', 'replace')}, ensure_ascii=False))
assert p.returncode == waited == 0 and stderr == b''
