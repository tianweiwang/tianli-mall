"""Read original completed S receipts without executing source, Git or Docker."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import re

def positive_pid(value):
    parsed = int(value)
    if parsed <= 0:
        raise argparse.ArgumentTypeError('PID must be positive')
    return parsed


parser = argparse.ArgumentParser(description='Read only the completed successful current28dc normal S receipts')
parser.add_argument('--run-id', required=True)
parser.add_argument('--outer-pid', required=True, type=positive_pid)
parser.add_argument('--prepare-pid', required=True, type=positive_pid)
args = parser.parse_args()
if re.fullmatch(r'[0-9]{14}[a-f0-9]{6}', args.run_id) is None or args.run_id == '20261023124755f45a9e':
    parser.error('Supply the actual new completed successful Run id; the retired failed0c13 Run is forbidden')
if args.outer_pid == args.prepare_pid:
    parser.error('Supply the distinct original outer and initial-prepare PIDs')

org = Path('H:/天俪商城/artifacts/project-organization-20261023')
workspace = org.parents[1]
base = workspace / 'artifacts/ms4-worker-normal-source-preparation-r1'
source = base / 'full-a-upload-root-current28dc-normal-next-source-r1'
run = workspace / 'artifacts/ms4-aftersale-review-normal-runs' / args.run_id
archive = run / 'root-orchestration'
assert (source / 'launcher/normal-start.terminal.json').is_file(), 'Original S is not terminal; do not restart or run this reader yet'
helper = base / 'customer-form-current-three-front-material-producer-independent-r1/review.py'
raw = helper.read_bytes()
assert len(raw) == 33902 and hashlib.sha256(raw).hexdigest() == '4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'
spec = importlib.util.spec_from_file_location('completed_S_finite_reader', helper)
h = importlib.util.module_from_spec(spec)
spec.loader.exec_module(h)
r = h.Finite()
r.read(helper, h.fp(raw))

SOURCE_PINS = {'bytes': 189060, 'sha256': '0c2d0c6e68199e0c2b291fdaa764babb986b8d5ebe512903fbf06b6f7fea3650'}
ROOT_SOURCE_PINS = {'bytes': 357, 'sha256': '23a4c019bff4a20a13dd612d542596f5f7f77f1c7d8dc87ff07b3febe540f921'}
M_RESULT = {'bytes': 12504207, 'sha256': '7ea9b84dbd9304e7f6a87d8bc06cece649363894c317d416b8ee77d07500f12e'}
FREEZE = {'bytes': 10985209, 'sha256': 'fea1ea38344095ce5dfe84ad85ad3041fb3b10271fd2c99b396212886e927a1a'}
M_ROOT_READBACK = {'bytes': 1735, 'sha256': '39f5190ae8863990bcf7a6dbddb25dd55aa5d935fdfb11fd4cc97f6506e66554'}
PROFILE_SOURCE_PINS9 = {'README.md': {'bytes': 89381, 'sha256': 'b886791202529032c9f85a88f80af456f1e916ad2ad1031eb417685edcae6493'}, 'compose.yml': {'bytes': 11860, 'sha256': 'f6c4b4f50fbd34fda66afcf2d0819d38992a2fe467172fe587cefbeb2d39a198'}, 'ms4-host.py': {'bytes': 345436, 'sha256': '9843cc96ab5b88d998c104493c6f786a687c903e6e9604ffac925cb39edbbb6d'}, 'ms4-initialize.php': {'bytes': 78955, 'sha256': '9784c617424a430db06bde00c6f4b78530a30218c8c77e0e125b3421d31e9f31'}, 'seed-source-inverse.json': {'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, 'interface-v1.json': {'bytes': 101852, 'sha256': '46b388a5b0e454207246f0b5571e7a2a135f08206e42c59bcb09c3164ec265d6'}, 'source-derivation.json': {'bytes': 601053, 'sha256': '346b833a3a29d265caeee9d8e672b3dd0e512bf9b501f4777336ff2218a64451'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'b8e235225d0d4c09e7c9e06b96fee8104a99bc3937f2a5898861c53707653e02'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': 'bdd183fc90da85519821c43999dd71d43fe3cf63487315afb71ee8482d3e4c6f'}}
r.read(source / 'PINS.json', SOURCE_PINS)
r.read(source / 'validation/PINS.json', ROOT_SOURCE_PINS)
r.read(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)
m_readback = r.json(base / 'current28dc-complete-materialization-root-readback-r2/REPORT.json', M_ROOT_READBACK)
assert m_readback['status'] == 'PASS_ACTUAL_FULL_CURRENT28DC_MATERIALIZATION_ONLY' and m_readback['source_sha'] == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
assert m_readback['actual_RESULT'] == {'path': (source / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **M_RESULT}
assert m_readback['actual_freeze'] == {'path': (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix(), **FREEZE}
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
driver_pin = {'bytes': 4388758, 'sha256': '4c6f0c00644b02334d39ecde20dc0d605af38eca211e3f36d03a81991d76a760'}
r.read(source / 'validation/run.py', driver_pin)
r.read(archive / 'root-driver-source.py', driver_pin)
context = r.json(run / 'actual-run-context.json')
manifest = r.json(run / 'manifest.json')
assert context['run_id'] == manifest['run_id'] == args.run_id
assert context['source_sha'] == manifest['commit_sha'] == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
assert context['freeze_sha256'] == FREEZE['sha256']
r.read(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
result = r.json(archive / 'RESULT.json')
assert result == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'context': (run / 'actual-run-context.json').as_posix(),
    'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN'}
original(source / 'validation/native-normal-start-assets-r1/001-prepare', args.prepare_pid)
scripts = {'ms4-host.py': {'bytes': 345436, 'sha256': '9843cc96ab5b88d998c104493c6f786a687c903e6e9604ffac925cb39edbbb6d'}, 'normal-close-ms4-fulfillment.py': {'bytes': 47220, 'sha256': 'b8e235225d0d4c09e7c9e06b96fee8104a99bc3937f2a5898861c53707653e02'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 296098, 'sha256': 'bdd183fc90da85519821c43999dd71d43fe3cf63487315afb71ee8482d3e4c6f'}}
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
    assert ended['status'] == 'exited' and ended['exit_code'] == 0 and ended['pid'] == 0 and ended['restart_count'] == 0
    proofs[proof] = {'native_exit': ended['exit_code'], 'same_original_id': True, 'no_restart': True}
close_path = run / 'evidence/ms4-worker-normal-close-result.json'
closed = r.json(close_path)
assert closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL'
zero = json.loads(r.read(archive / (f"{commands[-1]['sequence']:03d}-worker-independent-zero.stdout")))
assert commands[-1]['label'] == 'worker-independent-zero'
assert zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO'
assert commands[-2]['label'] == 'worker-normal-close'
for value in (closed, zero):
    assert value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
    assert value['freeze_sha256'] == context['freeze_sha256'] and value['native_exit'] == 0
close_pin = h.fp(r.read(close_path))
assert zero['close_result_sha256'] == close_pin['sha256']
assert [(q['kind'], q['label']) for q in zero['queries']] == [(k, label) for k in ('container','network','volume') for label in ('project','run')]
assert all(q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0 and q['stdout_sha256'] == q['stderr_sha256'] == hashlib.sha256(b'').hexdigest() for q in zero['queries'])
ended_ns = r.end()
report = {'status': 'PASS_ORIGINAL_CURRENT28DC_COMPLETE_NORMAL_A_RECEIPT_READBACK_ONLY',
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
    'original_outer_pid': args.outer_pid, 'original_prepare_pid': args.prepare_pid, 'original_outer_native_exit_wait': 0, 'Root_native_wrappers': len(commands),
    'initial_prepare_native_zero': True, 'all_original_stream_pins_match': True,
    'original_root_driver_matches_frozen_source': driver_pin, 'all9_original_container_proofs': proofs,
    'full_original_normal_stage_order': labels, 'normal_close_result': close_pin,
    'independent_zero_from_original_S': True, 'extra_close_or_restart_performed': False,
    'worker_scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN', 'finite_input_count': len(r.rows),
    'all_windows_closed': True, 'ended_ns': ended_ns}
out = base / 'current28dc-complete-normal-A-root-readback-r1'
out.mkdir(exist_ok=False)
(out / 'REPORT.json').write_bytes(h.enc(report))
(out / 'INPUTS.json').write_bytes(h.enc({'files': list(r.rows.values()), 'all_windows_closed': True}))
print(json.dumps({'report': h.fp(h.enc(report)), 'Root_native_wrappers': len(commands), 'proofs': list(proofs),
    'scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE'}))
