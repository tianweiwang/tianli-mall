"""Standalone independent reader for the actual completed original current175f S.

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


parser = argparse.ArgumentParser(description='Read only the completed successful current175f normal S receipts')
parser.add_argument('--run-id', required=True)
parser.add_argument('--outer-pid', required=True, type=positive_pid)
parser.add_argument('--prepare-pid', required=True, type=positive_pid)
parser.add_argument('--authorize-completed-s0', action='store_true', help='Root has authorized readback after original S native0/wait0')
args = parser.parse_args()
if re.fullmatch(r'[0-9]{14}[a-f0-9]{6}', args.run_id) is None or args.run_id in ('20261023124755f45a9e', '202610231609185fbd23'):
    parser.error('Supply the actual new completed successful Run id; both retired failed0c13 and failed28dc Runs are forbidden')
if args.outer_pid == args.prepare_pid:
    parser.error('Supply the distinct original outer and initial-prepare PIDs')
if not args.authorize_completed_s0:
    parser.error('Root must authorize completed original S native0/wait0 before this reader runs')
if (args.run_id, args.outer_pid, args.prepare_pid) != ('20261023182634096f57', 35944, 49064):
    parser.error('This independent reader is bound to the genuine current175f Run and original PIDs')

org = Path('H:/天俪商城/artifacts/project-organization-20261023')
workspace = org.parents[1]
base = workspace / 'artifacts/ms4-worker-normal-source-preparation-r1'
source = base / 'full-a-quota-wire-current175f-normal-next-source-r1'
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
r.read(Path(__file__).resolve().parent / 'readback-current175f-complete-normal-S-root.py', {'bytes': 14136, 'sha256': '9e0475c305cc61c9524291da9f1e27ac160de1f3bd66021c677d8e5556677784'})

SOURCE_PINS = {'bytes': 147196, 'sha256': '2653282ad09cdf30e030c6c1cf5976b73a1a06bd0be52070085e3a3f33bb270b'}
ROOT_SOURCE_PINS = {'bytes': 357, 'sha256': '0516d5f9eff69c4a36ab8b2a012333fbbcd9c2751614330ee9459cb391fe32a4'}
M_RESULT = {'bytes': 46750425, 'sha256': '6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa'}
FREEZE = {'bytes': 10985209, 'sha256': '4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed'}
M_ROOT_READBACK = {'bytes': 1814, 'sha256': '6901f56b93e54ae6493a02e3851084e56be995d316294314e0bf03a0b710af05'}
PROFILE_SOURCE_PINS9 = {'README.md': {'bytes': 89381, 'sha256': 'b886791202529032c9f85a88f80af456f1e916ad2ad1031eb417685edcae6493'}, 'compose.yml': {'bytes': 11860, 'sha256': 'f6c4b4f50fbd34fda66afcf2d0819d38992a2fe467172fe587cefbeb2d39a198'}, 'ms4-host.py': {'bytes': 345436, 'sha256': '0d379c9a33dccfc0ece92fe8129842ac27105d66a50320b37e893bfd23dc295e'}, 'ms4-initialize.php': {'bytes': 78955, 'sha256': 'a5add955d43e4fd8cfdc50dd0ad06d5f6408e5cf743a5c2564b6e51af7db067b'}, 'seed-source-inverse.json': {'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, 'interface-v1.json': {'bytes': 102529, 'sha256': '5f0bdb9bd952419f879d86bb8941b06147a9bea3b4f4eeb2c814dcafef392495'}, 'source-derivation.json': {'bytes': 604017, 'sha256': 'e0bda907571363b1e1c7f58920af91b6a75a66072db0fc081ff7041e05b6db46'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'cba367e05932836c7667e5fd10faa6f5c7c06a85dceceb27eb7b228b754bd361'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': 'ee5a9f65d87d063100f834c8456364cb8024fb3414467827e6c1ec92aa03b54f'}}
actual_source_pins = r.json(source / 'PINS.json', SOURCE_PINS)
assert actual_source_pins['source_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7'
actual_root_pins = r.json(source / 'validation/PINS.json', ROOT_SOURCE_PINS)
actual_M = r.json(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)
assert actual_M['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert actual_M['source_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7'
assert actual_M['repo'] == (workspace / 'worktrees/ms4-aftersale-normal-check').as_posix()
assert actual_M['profile'] == (source / 'validation/actual-profile').as_posix()
assert actual_M['freeze'] == (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix()
assert actual_M['freeze_sha256'] == FREEZE['sha256']
m_readback = r.json(base / 'current175f-complete-materialization-root-readback-r1/REPORT.json', M_ROOT_READBACK)
assert m_readback['status'] == 'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY' and m_readback['source_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7'
assert m_readback['actual_RESULT'] == {'path': (source / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **M_RESULT}
assert m_readback['actual_freeze'] == {'path': (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix(), **FREEZE}
CURRENT_M_RESULT_KEYS35 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
assert set(actual_M) == set(CURRENT_M_RESULT_KEYS35) and len(actual_M) == 35
assert len(actual_M['commit_chain']) == 19 and len(actual_M['native_operations']) == 1088
assert m_readback['M_RESULT_fields'] == 35 and m_readback['steps'] == 19 and m_readback['native_operations'] == 1088

for name, expected in PROFILE_SOURCE_PINS9.items():
    r.read(source / 'validation/actual-profile' / name, expected)


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
driver_pin = {'bytes': 6500754, 'sha256': 'e8ef902c0e33066259cf178d04ef782089e99b918e83f3284c60c879d25c7c5b'}
r.read(source / 'validation/run.py', driver_pin)
r.read(archive / 'root-driver-source.py', driver_pin)
assert actual_root_pins['members']['run.py'] == driver_pin
assert {key: actual_source_pins['files']['validation/run.py'][key] for key in ('bytes', 'sha256')} == driver_pin
context = r.json(run / 'actual-run-context.json', {'bytes': 12301, 'sha256': 'c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959'})
manifest = r.json(run / 'manifest.json')
assert context['run_id'] == manifest['run_id'] == args.run_id
assert context['source_sha'] == manifest['commit_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7'
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
scripts = {'ms4-host.py': {'bytes': 345436, 'sha256': '0d379c9a33dccfc0ece92fe8129842ac27105d66a50320b37e893bfd23dc295e'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'cba367e05932836c7667e5fd10faa6f5c7c06a85dceceb27eb7b228b754bd361'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': 'ee5a9f65d87d063100f834c8456364cb8024fb3414467827e6c1ec92aa03b54f'}}
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
report = {'status': 'PASS_INDEPENDENT_ACTUAL_CURRENT175F_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY',
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
