"""Standalone independent reader for the actual completed original current6e3850 S.

SOURCE ONLY until Root authorizes completed native0/wait0. Imports stdlib only.
Retains the reviewed full normal-chain receipt contract; no target execution.
"""
import argparse
import hashlib
import json
from pathlib import Path
import sys
import os
import stat
import time
import re

if sys.flags.optimize:
    raise RuntimeError('OPTIMIZED_PYTHON_FORBIDDEN_BEFORE_ANY_INPUT_READ')

KEYS = ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']

def need(ok, code):
    if not ok:
        raise AssertionError(code)

def fp(raw):
    return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}

def pairs(rows):
    out = {}
    for k,v in rows:
        need(k not in out,'DUPLICATE_KEY:'+k)
        out[k] = v
    return out

def loads(raw):
    return json.loads(raw.decode('utf-8'),object_pairs_hook=pairs)

def enc(value):
    return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode('utf-8')

def pinpart(value):
    return {k:value[k] for k in ['bytes','sha256']}

def n9(info):
    out = [getattr(info,k) for k in KEYS]
    need(all(type(v) is int for v in out),'EXACT_PYTHON_INT_NATIVE9')
    return out

class Finite:
    def __init__(self):
        self.rows = {}
        self.raws = {}

    def observe(self,path):
        path = Path(path)
        a = path.lstat()
        need(stat.S_ISREG(a.st_mode) and not a.st_file_attributes & 0x400,'ORDINARY_FILE:'+str(path))
        pn = n9(a)
        total = 0
        digest = hashlib.sha256()
        chunks = []
        with path.open('rb') as f:
            x = os.fstat(f.fileno())
            fn = n9(x)
            # Windows path ctime and descriptor ctime have different API meanings.
            need(all(pn[i] == fn[i] for i,k in enumerate(KEYS) if k in {'st_dev', 'st_ino', 'st_size', 'st_mtime_ns'}),'NAMED_FD_COMMON_IDENTITY:'+str(path))
            need(a.st_birthtime_ns == x.st_birthtime_ns,'NAMED_FD_BIRTHTIME:'+str(path))
            while True:
                raw = f.read(262144)
                if not raw:
                    break
                chunks.append(raw);digest.update(raw);total += len(raw)
            need(f.read(1) == b'' and total == a.st_size,'FULL_EOF:'+str(path))
            y = os.fstat(f.fileno())
            need(n9(y) == fn and y.st_birthtime_ns == x.st_birthtime_ns,'FD_FIRST_FINAL:'+str(path))
        z = path.lstat()
        need(n9(z) == pn and z.st_birthtime_ns == a.st_birthtime_ns,'NAMED_FIRST_FINAL:'+str(path))
        return {'path':path.as_posix(),'pin':{'bytes':total,'sha256':digest.hexdigest()},
                'named_before':pn,'named_after':n9(z),'fd_open':fn,'fd_after':n9(y),
                'named_birthtime_ns':a.st_birthtime_ns,'fd_birthtime_ns':x.st_birthtime_ns},b''.join(chunks)

    def read(self,path,expected=None):
        path = Path(path);key = str(path)
        if key not in self.rows:
            row,raw = self.observe(path)
            self.rows[key] = row;self.raws[key] = raw
        raw = self.raws[key]
        if expected is not None:
            need(fp(raw) == pinpart(expected),'EXPECTED_FULL_PIN:'+path.as_posix())
        return raw

    def json(self,path,expected=None):
        return loads(self.read(path,expected))

    def end(self):
        for key,row in self.rows.items():
            final,raw = self.observe(Path(key))
            need(final == row and raw == self.raws[key],'WHOLE_REVIEW_FIRST_FINAL:'+key)
        return time.time_ns()


def positive_pid(value):
    parsed = int(value)
    if parsed <= 0:
        raise argparse.ArgumentTypeError('PID must be positive')
    return parsed


parser = argparse.ArgumentParser(description='Read only the completed successful current6e3850 normal S receipts')
parser.add_argument('--run-id', required=True)
parser.add_argument('--outer-pid', required=True, type=positive_pid)
parser.add_argument('--prepare-pid', required=True, type=positive_pid)
parser.add_argument('--authorize-completed-s0', action='store_true', help='Root has authorized readback after original S native0/wait0')
args = parser.parse_args()
if re.fullmatch(r'[0-9]{14}[a-f0-9]{6}', args.run_id) is None or args.run_id in ('20261023124755f45a9e', '202610231609185fbd23', '20261023182634096f57'):
    parser.error('Supply the actual new completed successful Run id; retired failed0c13, failed28dc and failed175f Runs are forbidden')
if args.outer_pid == args.prepare_pid:
    parser.error('Supply the distinct original outer and initial-prepare PIDs')
if not args.authorize_completed_s0:
    parser.error('Root must authorize completed original S native0/wait0 before this reader runs')
if (args.run_id, args.outer_pid, args.prepare_pid) != ('2026102321491304a643', 2388, 13036):
    parser.error('This independent reader is bound to the genuine current6e3850 Run and original PIDs')

org = Path('H:/天俪商城/artifacts/project-organization-20261023')
workspace = org.parents[1]
base = workspace / 'artifacts/ms4-worker-normal-source-preparation-r1'
source = base / 'full-a-preflight-diagnostic-current6e3850-normal-next-source-r2'
run = workspace / 'artifacts/ms4-aftersale-review-normal-runs' / args.run_id
archive = run / 'root-orchestration'
assert (source / 'launcher/normal-start.terminal.json').is_file(), 'Original S is not terminal; do not restart or run this reader yet'
r = Finite()
reader_source_pin = fp(r.read(Path(__file__)))
reviewed_reader = org / 'current28dc-complete-normal-S-readback-source-r1/candidate/readback-current28dc-complete-normal-S.py'
r.read(reviewed_reader, {'bytes': 12417, 'sha256': 'f76984c14e1bf004edf73d885093b6bfbecd2ff302316facfbed416f1e76a02e'})
reviewed_static = r.json(org / 'current28dc-normal-S-reader-source-independent-r1/REPORT.json',
    {'bytes': 2868, 'sha256': '577dd6c704da17e9b2af45d075c3edb7f6a436f2acd2af983b34ca27442a57cc'})
assert reviewed_static['status'] == 'PASS_INDEPENDENT_STATIC_CURRENT28DC_COMPLETE_NORMAL_S_READER_SOURCE_ONLY'
# Preserve fixed ancestor qualification; bind the full current Root Source separately.
r.read(Path(__file__).resolve().parent / 'readback-current6e3850-complete-normal-S-root.py', {'bytes': 25872, 'sha256': 'ee34b8415dc13d0db01c0b9282b90a7a3ae67f7973ae22d310f7be11ada9b232'})

SOURCE_PINS = {'bytes': 68413, 'sha256': 'd3e61099b79c564658d3c2a2d56407501b47583790c44c34bbdd3617da1b4f54'}
ROOT_SOURCE_PINS = {'bytes': 357, 'sha256': '384be3ed685ea338e377f49285fc15eeca1b2d7b1a5d981591e76055413fae00'}
M_RESULT = {'bytes': 117797645, 'sha256': 'a017b8c5722d0556659f9d03b264e91340b96a42b36a03f20f0c327525255e02'}
FREEZE = {'bytes': 10985209, 'sha256': 'fc567b79882a2ca6ac80fb7f7d1eafacf1281fe71e88c8ce152e590544611608'}
M_ROOT_READBACK = {'bytes': 3565, 'sha256': '93ae4947412f5972906f70d27f0d3affedceeb49716e6cc8274c13b2c4bddbae'}
PROFILE_SOURCE_PINS9 = {'README.md': {'bytes': 89381, 'sha256': 'b886791202529032c9f85a88f80af456f1e916ad2ad1031eb417685edcae6493'}, 'compose.yml': {'bytes': 11860, 'sha256': 'f6c4b4f50fbd34fda66afcf2d0819d38992a2fe467172fe587cefbeb2d39a198'}, 'ms4-host.py': {'bytes': 345436, 'sha256': 'b02c52e7641400a80e13c7148c3f1e923131526ccbcedea8a7f59071a04ed7e9'}, 'ms4-initialize.php': {'bytes': 78955, 'sha256': '136e5ddf48989c6bbf2746579fabed1b61b32f371026005a48ef98c585e56373'}, 'seed-source-inverse.json': {'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, 'interface-v1.json': {'bytes': 103220, 'sha256': '760e4e839025d8c768444aa451bea4ecd6debb06c6439f6e59f80554647af027'}, 'source-derivation.json': {'bytes': 607523, 'sha256': 'ee3a2a7a2728de0578fc7014d6881348ad617493cebf21ef2ca8ae0444a2dd26'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'd121e8c8379bdd4aaf2c291d7695f78aba541dbe7d1641979a4adca88befaf98'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': '475b62d5255669c543e67a067cbc489757c20da2d1de01d03183733bf94ce9c3'}}
actual_source_pins = r.json(source / 'PINS.json', SOURCE_PINS)
assert actual_source_pins['source_sha'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
actual_root_pins = r.json(source / 'validation/PINS.json', ROOT_SOURCE_PINS)
actual_M = r.json(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)
assert actual_M['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert actual_M['source_sha'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
assert actual_M['repo'] == (workspace / 'worktrees/ms4-aftersale-normal-check').as_posix()
assert actual_M['profile'] == (source / 'validation/actual-profile').as_posix()
assert actual_M['freeze'] == (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix()
assert actual_M['freeze_sha256'] == FREEZE['sha256']
m_readback = r.json(base / 'current6e3850-complete-materialization-root-readback-r1/REPORT.json', M_ROOT_READBACK)
assert m_readback['status'] == 'PASS_ACTUAL_FULL_CURRENT6E3850_MATERIALIZATION_ONLY' and m_readback['source_sha'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
assert m_readback['actual_RESULT'] == {'path': (source / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **M_RESULT}
assert m_readback['actual_freeze'] == {'path': (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix(), **FREEZE}
CURRENT_M_RESULT_KEYS36 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'current_175f_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
assert set(actual_M) == set(CURRENT_M_RESULT_KEYS36) and len(actual_M) == 36
assert len(actual_M['commit_chain']) == 20 and len(actual_M['native_operations']) == 1104
assert m_readback['M_RESULT_fields'] == 36 and m_readback['steps'] == 20 and m_readback['native_operations'] == 1104

for name, expected in PROFILE_SOURCE_PINS9.items():
    r.read(source / 'validation/actual-profile' / name, expected)

# Full actual M/source/metadata qualification; no path in its metadata maps is dereferenced.
source_pins = actual_source_pins
SEALED_SOURCE_PINS = SOURCE_PINS
result = actual_M
actual = source / 'validation/materialize-freeze-assets-r1'
ninth = result['current_28dc_paid_producer_failure_retirement']
source_constants = {'CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current175f-tenth-retirement-authority-preparation-r2/CURRENT-175F-RETIREMENT-COMPLETE.json', 'bytes': 68600317, 'sha256': '032410e9de4a3ad9ddacb914ddc49b7624c0442d2df791a5ad919472cfdc2628'}}
publication = r.json(source / 'validation/PROFILE-PUBLICATION.json', {'bytes': 1426325, 'sha256': 'dd3aa7af66f93cdefa7ff9db24e9c0f19c12bc5a6994c0e8be736ab2cd7bd803'})
profile_pins = r.json(source / 'validation/PROFILE-PINS.json', {'bytes': 1518, 'sha256': '13e06b0e98f0292be782d262d528ea6e0a44c1e8875c0ba262545c14b110540a'})
assert set(actual_root_pins['members']) == {'run.py', 'DESIGN-TASKS.md'} and len(profile_pins['members']) == 10
for name, wanted in actual_root_pins['members'].items():
    r.read(source / 'validation' / name, wanted)
for name, wanted in profile_pins['members'].items():
    r.read(source / 'validation' / name if name == 'PROFILE-PUBLICATION.json' else source / 'validation/actual-profile' / name, wanted)
assert m_readback['root_source_members'] == 14 and m_readback['complete_source_snapshot_rows'] == 31496 and m_readback['tracked_per_checkout'] == 15748 and m_readback['cumulative'] == 307 and m_readback['source_group_union'] == 10886
assert m_readback['all_prior_nine_whole_encoded_objects_preserved'] is True and m_readback['tenth_actual_failed_A_whole_pinned_authority_and_result_join_preserved'] is True
peer_M = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/current6e3850-actual-materialization-independent-source-r2/candidate/REPORT.json'), {'bytes': 3373, 'sha256': 'c68ae107e57cac0bc2c845122962f0c76f0cbea1eec3792679bdb427669f7d30'})
peer_M_PINS = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/current6e3850-actual-materialization-independent-source-r2/candidate/PINS.json'), {'bytes': 901, 'sha256': '7a07842a3f61b496e890557dc619f054da60486537794fb301f88a8e4751b839'})
assert peer_M_PINS['files']['REPORT.json'] == {'bytes': 3373, 'sha256': 'c68ae107e57cac0bc2c845122962f0c76f0cbea1eec3792679bdb427669f7d30'}
assert peer_M['status'] == 'PASS_COMPLETE_ACTUAL_CURRENT6E3850_M36_SOURCE_MATERIALIZATION_READBACK_ONLY' and peer_M['source_sha'] == result['source_sha']
assert peer_M['actual_RESULT'] == m_readback['actual_RESULT'] and peer_M['actual_freeze'] == m_readback['actual_freeze']
assert peer_M['actual_M_native_operations'] == 1104 and peer_M['M_RESULT_current_fields'] == 36 and peer_M['source_program_members'] == 18 and peer_M['complete_dual_source_metadata_rows'] == 31496
assert peer_M['complete_normal_source_union'] == 10886 and peer_M['ten_retirement_facts_whole_joined_without_overwriting_old_nine'] is True and peer_M['all_finite_windows_closed'] is True
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
    assert fp(enc(result[field])) == tenth['historical_nine_whole_encoded_pins'][field]
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
authority_snapshot = loads(authority_raw)
assert set(authority_snapshot) == authority_names
for path, row in authority_snapshot.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == path
    assert {key: row[key] for key in ('bytes', 'sha256')} == {key: callback_files[path][key] for key in ('bytes', 'sha256')}
    assert row['stat9'] == callback_files[path]['native9'] and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
for row in forbidden_metadata:
    assert row['path'] not in callback_files and row['path'] not in authority_snapshot
assert len(source_pins['files']) == 115 and len(source_pins['source_members18']) == 18
assert callback_report['group_counts'] == result['source_counts']

assert m_readback['M_complete_authority_inputs'] == peer_M['exact_M_authority_projection_rows'] == len(authority_snapshot) == 25933
assert m_readback['callback_complete_inputs'] == peer_M['complete_callback_graph_rows'] == len(callback_files) == 25939
assert m_readback['authority_snapshot'] == {'path': authority_path.as_posix(), **fp(authority_raw)}
assert m_readback['forbidden_ENV_body_reads'] == 0 and peer_M['complete_callback_REPORT'] == callback_root['actual_REPORT'] and peer_M['complete_callback_INPUTS'] == callback_root['actual_INPUTS']


def original(stem, expected_pid=None):
    created = r.json(stem.with_suffix('.created.json'))
    terminal = r.json(stem.with_suffix('.terminal.json'))
    assert set(created) == {'argv', 'pid'}
    assert created['pid'] == terminal['pid']
    if expected_pid is not None:
        assert created['pid'] == expected_pid
    assert terminal['native_exit'] == terminal['wait_returncode'] == 0
    for stream in ('stdout', 'stderr'):
        payload = r.read(stem.with_suffix('.' + stream), {'bytes': terminal[stream + '_bytes'], 'sha256': terminal[stream + '_sha256']})
        if stream == 'stderr':
            assert payload == b''
    return created, terminal


outer = source / 'launcher/normal-start'
created = r.json(outer.with_suffix('.created.json'))
terminal = r.json(outer.with_suffix('.terminal.json'))
assert set(created) == {'pid', 'argv', 'started_ns'} and created['pid'] == terminal['pid'] == args.outer_pid
assert terminal['native_exit'] == terminal['wait_returncode'] == 0
assert set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}
assert 0 < created['started_ns'] < terminal['finished_ns']
assert len(created['argv']) == 9 and Path(created['argv'][4]).resolve() == (source/'validation/run.py').resolve()
assert created['argv'][5:] == ['--root-source-pins-sha256', ROOT_SOURCE_PINS['sha256'], '--setup-sha256', M_RESULT['sha256']]
for stream in ('stdout', 'stderr'):
    payload = r.read(outer.with_suffix('.' + stream), terminal[stream])
    if stream == 'stderr':
        assert payload == b''
driver_pin = {'bytes': 9063290, 'sha256': '00464dd6a91b90f83210a96686321d7fac28cdc934656b78618c4fc29b53d90f'}
r.read(source / 'validation/run.py', driver_pin)
r.read(archive / 'root-driver-source.py', driver_pin)
assert actual_root_pins['members']['run.py'] == driver_pin
assert {key: actual_source_pins['files']['validation/run.py'][key] for key in ('bytes', 'sha256')} == driver_pin
context = r.json(run / 'actual-run-context.json', {'bytes': 12361, 'sha256': '0ae1ef4834aac7856fbda99c6d0d209149012daf068bb4e146af9e26382ae0f9'})
manifest = r.json(run / 'manifest.json')
assert context['run_id'] == manifest['run_id'] == args.run_id
assert context['source_sha'] == manifest['commit_sha'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
assert context['freeze_sha256'] == FREEZE['sha256']
assert Path(context['run_dir']).resolve() == run.resolve()
assert Path(context['repo_dir']).resolve() == Path(actual_M['repo']).resolve()
assert Path(context['prep_dir']).resolve() == Path(actual_M['profile']).resolve()
assert r.read(run / 'preparation-source-binding.json', FREEZE) == r.read(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
r.read(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
result = r.json(archive / 'RESULT.json')
assert result == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'context': (run / 'actual-run-context.json').as_posix(),
    'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN'}
original(source / 'validation/native-normal-start-assets-r1/001-prepare', args.prepare_pid)
scripts = {'ms4-host.py': {'bytes': 345436, 'sha256': 'b02c52e7641400a80e13c7148c3f1e923131526ccbcedea8a7f59071a04ed7e9'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'd121e8c8379bdd4aaf2c291d7695f78aba541dbe7d1641979a4adca88befaf98'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': '475b62d5255669c543e67a067cbc489757c20da2d1de01d03183733bf94ce9c3'}}
commands = []
labels = []
for path in sorted(archive.glob('*.created.json'), key=lambda item: int(item.name.split('-', 1)[0])):
    stem = path.with_name(path.name.removesuffix('.created.json'))
    c, t = original(stem)
    argv = c['argv']
    assert Path(argv[0]).resolve() == Path(sys.executable).resolve()
    assert argv[1:4] == ['-B', '-X', 'utf8']
    script = Path(argv[4])
    assert script.parent.resolve() == (source / 'validation/actual-profile').resolve() and script.name in scripts
    r.read(script, scripts[script.name])
    sequence_text, label = stem.name.split('-', 1)
    sequence = int(sequence_text)
    assert stem.name == f'{sequence:03d}-{label}'
    labels.append(label)
    if script.name == 'ms4-host.py':
        expected = argv[5] + ('-' + argv[argv.index('--proof') + 1] if '--proof' in argv else '')
        assert label == expected
    else:
        assert label in ('worker-normal-close', 'worker-independent-zero')
        assert '--worker-normal' in argv and argv[argv.index('--context') + 1] == str(run / 'actual-run-context.json')
    commands.append({'sequence': sequence, 'label': label, 'pid': c['pid'], 'native_exit': t['native_exit']})
assert [item['sequence'] for item in commands] == list(range(2, len(commands) + 2))
assert set(path.name for path in archive.iterdir()) == {'root-driver-source.py', 'RESULT.json'} | {
    f"{item['sequence']:03d}-{item['label']}.{suffix}" for item in commands
    for suffix in ('created.json', 'terminal.json', 'stdout', 'stderr')}
for required in ('paid-start', 'fulfillment-normal-start', 'fulfillment-normal-retire', 'aftersale-review-start',
                 'aftersale-review-retire', 'worker-start', 'worker-normal-close', 'worker-independent-zero'):
    assert labels.count(required) == 1
proofs = {}
for proof in ('initializer', 'driver', 'fulfillment-migration', 'files-bootstrap', 'customer-prepare', 'paid-producer',
              'fulfillment-normal', 'aftersale-review-normal', 'worker-normal'):
    made = r.json(run / 'evidence' / (proof + '-created.json'))
    ended = r.json(run / 'evidence' / (proof + '-terminal.json'))
    assert made['id'] == ended['id']
    assert made['run_id'] == context['run_id'] and made['source_sha'] == context['source_sha']
    assert made['proof_name'] == proof
    assert ended['status'] == 'exited' and ended['exit_code'] == 0 and ended['pid'] == 0 and ended['restart_count'] == 0
    proofs[proof] = {'native_exit': ended['exit_code'], 'same_original_id': True, 'no_restart': True}
close_path = run / 'evidence/ms4-worker-normal-close-result.json'
closed = r.json(close_path)
assert closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL'
zero = loads(r.read(archive / (f"{commands[-1]['sequence']:03d}-worker-independent-zero.stdout")))
assert commands[-1]['label'] == 'worker-independent-zero'
assert zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO'
assert commands[-2]['label'] == 'worker-normal-close'
for value in (closed, zero):
    assert value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
    assert value['freeze_sha256'] == context['freeze_sha256'] and value['native_exit'] == 0
close_pin = fp(r.read(close_path))
assert zero['close_result_sha256'] == close_pin['sha256']
assert [(q['kind'], q['label']) for q in zero['queries']] == [(k, label) for k in ('container','network','volume') for label in ('project','run')]
assert all(q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0 and q['stdout_sha256'] == q['stderr_sha256'] == hashlib.sha256(b'').hexdigest() for q in zero['queries'])
ended_ns = r.end()
report = {'status': 'PASS_INDEPENDENT_ACTUAL_CURRENT6E3850_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY',
    'reader_source': {'path': Path(__file__).as_posix(), **reader_source_pin},
    'independently_reads_originals': True, 'target_or_source_module_imported': False,
    'actual_Run_freeze_equals_complete_frozen_Source': True,
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
    'original_outer_pid': args.outer_pid, 'original_prepare_pid': args.prepare_pid, 'original_outer_native_exit_wait': 0, 'Root_native_wrappers': len(commands),
    'initial_prepare_native_zero': True, 'all_original_stream_pins_match': True,
    'original_root_driver_matches_frozen_source': driver_pin, 'all9_original_container_proofs': proofs,
    'full_original_normal_stage_order': labels, 'normal_close_result': close_pin,
    'independent_zero_from_original_S': True, 'extra_close_or_restart_performed': False,
    'worker_scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN', 'finite_input_count': len(r.rows),
    'all_windows_closed': True, 'ended_ns': ended_ns}
out = Path(__file__).resolve().parents[1] / 'actual-peer-readback-r1'
out.mkdir(exist_ok=False)
(out / 'REPORT.json').write_bytes(enc(report))
(out / 'INPUTS.json').write_bytes(enc({'files': list(r.rows.values()), 'all_windows_closed': True}))
print(json.dumps({'report': fp(enc(report)), 'Root_native_wrappers': len(commands), 'proofs': list(proofs),
    'scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE'}))
