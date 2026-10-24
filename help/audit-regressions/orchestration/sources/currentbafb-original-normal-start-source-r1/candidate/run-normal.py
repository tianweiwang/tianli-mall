"""Admit the existing full S once, after real media retirement and M dual readback."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import time

ROOT = Path('H:/天俪商城')
ORG = ROOT / 'artifacts/project-organization-20261023'
ADM = ORG / 'current41c0-normal-root-admission-r1'
NS = ORG / 'currentbafb-next-normal-preparation-r3/normal-source'
PY = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
HEAD = 'bafb6305062aeb6b6e945c21da572863062ca480'


def pin(path):
    before = path.stat()
    raw = path.read_bytes()
    after = path.stat()
    assert (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) == (
        after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
    return {'path': path.as_posix(), 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def put(path, value):
    with path.open('xb') as handle:
        handle.write((json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode())


def metadata(path):
    info = path.stat()
    return {'path': path.as_posix(), 'bytes': info.st_size,
            'mtime_ns': info.st_mtime_ns, 'body_read': False}


args = argparse.ArgumentParser()
args.add_argument('--retirement-root-sha256', required=True)
options = args.parse_args()
assert re.fullmatch('[0-9a-f]{64}', options.retirement_root_sha256)
retirement_path = ADM / 'R92-MEDIA-FAILED-PREPARE-CLOSE-ZERO-ACTUAL-ROOT-READBACK.json'
retirement_pin = pin(retirement_path)
assert retirement_pin['sha256'] == options.retirement_root_sha256
retirement = json.loads(retirement_path.read_bytes())
assert retirement['status'] == 'ROOT_CURRENT_ENV6_FAILED_PREPARATION_RETIREMENT_AND_INDEPENDENT_ZERO_VERIFIED'
assert retirement['remaining_resources'] == {'containers': 0, 'networks': 0, 'volumes': 0}
assert retirement['foreign_unchanged'] is True and retirement['independent_zero_mutations'] == 0
assert retirement['business_acceptance'] == 'INCOMPLETE'

expected = [
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/FINAL-PINS.json'), '3bbdebc0e55c10c1079a81d5c1a88932d5d8b166a09962dece56c617508a4661'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/PINS.json'), '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/PINS.json'), 'e40e95ba18acbdd3d42843129535be813f471b5bb1b45e018ba3f453466cd920'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/launcher/launch.py'), '7aa97994a1b765e14e46dbd7265fe270db328f6ca1261f65e1848792d5da253c'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-SOURCE-CALLBACK-ADMISSION.json'), '9d54b7e5c6ccc47ddb580fc9b05cd4dca625e19cf9e98750761914581510d8e6'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-normal-independent-source-r3/REPORT.json'), 'aa72c3a7dfcd8a01c5e9736265facb93bf9783b4f1a3b8fc047b98e23c4937af'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-CALLBACK-ACTUAL-ROOT-READBACK.json'), '626e8fcee1ff71a8d09974e0b5df16bd3c5c3f1502a8be00d9b7114d4bca75f3'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-EIGHT-PHP-ACTUAL-ROOT-READBACK.json'), '1fc551dbc152bc197c2f2c83ee420f863fd8bd717e6b98191cf39dc87bd531a7'),
    (Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentbafb-complete-materialization-root-readback-r1/REPORT.json'), 'c885b90870a49500cce43527e2f690c4b3e418b9ced7d5e7c97ff7cf3936ee93'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/REPORT.json'), 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/PINS.json'), '5cdfe6bada88124644a8b8b5724f7fffefd45996d1592088e40eba7505373db0'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-BOTH-M-READERS-ACTUAL-ROOT-READBACK.json'), '7aed3fc03af85053d6efc83dd9380f533b21eed870494371215301b23c72087e'),
    (Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R98-D0A5-FAILED-FINITE-HISTORY-INDEX.json'), 'c43abf997074d602d56b839791412e281dfb334ad826579b0a9fae3b7e31a35f'),
]

bindings = []
for path, digest in expected:
    descriptor = pin(path)
    assert descriptor['sha256'] == digest
    bindings.append(descriptor)

# All current source bytes are qualified, not only a stale PINS filename.
source_pins = json.loads((NS / 'PINS.json').read_bytes())
assert source_pins['source_sha'] == HEAD and source_pins['member_count'] == 29
for relative, row in source_pins['files'].items():
    assert pin(NS / relative) == row
qualification = json.loads((ADM / 'R100-BOTH-M-READERS-ACTUAL-ROOT-READBACK.json').read_bytes())
assert qualification['status'] == 'PASS_BOTH_COMPLETED_CURRENTBAFB_M_READERS_ACTUAL_ROOT_JOIN_ONLY'
assert qualification['source_sha'] == HEAD and qualification['actual_M_PID'] == 22536
assert qualification['both_native_and_wait_exit'] == 0
assert qualification['original_reader_PIDs'] == {'root': 1660, 'peer': 34708}
assert qualification['complete_saved_input_windows_validated'] == {'root': 5991, 'peer': 6037}
assert qualification['S_A0_full_business_UI_or_new83daa_qualification'] == 'NOT ASSERTED'
for mode, pid in [('root', 1660), ('peer', 34708)]:
    native = ORG / 'currentbafb-m-readers-actual-r1'
    made = json.loads((native / (mode + '-created.json')).read_bytes())
    ended = json.loads((native / (mode + '-terminal.json')).read_bytes())
    assert made['pid'] == ended['pid'] == pid
    assert ended['native_exit'] == ended['wait_returncode'] == 0
    for stream in ['stdout', 'stderr']:
        assert pin(native / (mode + '-' + stream)) == ended[stream]
finite = json.loads((ADM / 'R98-D0A5-FAILED-FINITE-HISTORY-INDEX.json').read_bytes())
assert finite['run_id'] == '20261024114233c6e65e' and finite['original_S_native_exit_wait'] == 1
assert finite['Task6_exit0_retired'] is True and finite['counts']['current_residual'] == 0
assert finite['counts']['containers_removed'] == 4 and finite['counts']['network_removed'] == 1 and finite['counts']['volumes_removed'] == 9
assert not (NS / 'launcher/normal-start.created.json').exists()
assert not (NS / 'launcher/normal-start.terminal.json').exists()
assert not (NS / 'validation/native-normal-start-assets-r1').exists()

stem = 'bafb-original-normal-start'
created = ADM / (stem + '.created.json')
terminal = ADM / (stem + '.terminal.json')
stdout = ADM / (stem + '.stdout')
stderr = ADM / (stem + '.stderr')
assert not any(path.exists() for path in [created, terminal, stdout, stderr])
git_facts = []
for label, worktree in [('W', ROOT / 'worktrees/ms4-asset-consumers'),
                        ('Val', ROOT / 'worktrees/ms4-aftersale-normal-check')]:
    for kind, command in [('head', ['rev-parse', 'HEAD']),
                          ('status', ['status', '--porcelain=v1', '--untracked-files=all'])]:
        argv = ['git', '-c', 'safe.directory=' + worktree.as_posix(), '-C', str(worktree), *command]
        out = ADM / (stem + '-' + label + '-' + kind + '.stdout')
        err = ADM / (stem + '-' + label + '-' + kind + '.stderr')
        made = ADM / (stem + '-' + label + '-' + kind + '.created.json')
        ended = ADM / (stem + '-' + label + '-' + kind + '.terminal.json')
        assert not any(path.exists() for path in [out, err, made, ended])
        with out.open('xb') as out_handle, err.open('xb') as err_handle:
            proc = subprocess.Popen(argv, stdout=out_handle, stderr=err_handle)
            put(made, {'pid': proc.pid, 'argv': argv, 'started_ns': time.time_ns()})
            result = proc.wait()
        assert result == proc.returncode == 0 and err.read_bytes() == b''
        actual = out.read_bytes()
        assert actual.strip() == HEAD.encode() if kind == 'head' else actual == b''
        fact = {'pid': proc.pid, 'argv': argv, 'native_exit': result, 'wait_returncode': proc.returncode,
                'stdout': pin(out), 'stderr': pin(err)}
        put(ended, fact)
        git_facts.append(fact)

argv = [PY, '-I', '-B', '-X', 'utf8', str(NS / 'launcher/launch.py'), 'normal-start']
put(ADM / (stem + '-ADMISSION.json'), {'schema': 1, 'scope': 'EXISTING_ORIGINAL_FULL_S_ONLY',
    'head': HEAD, 'argv': argv, 'bindings': bindings, 'Git': git_facts, 'retirement_root': retirement_pin,
    'M_result_sha256': '06291afb846c2043a4ab425a5184c962e3e2fc5e180f5c369bfbd075ca2e9e0a',
    'M_frozen_binding_sha256': '7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1',
    'wrapper': pin(Path(__file__)), 'M_callback_export_rerun': False,
    'no_skip_partial_or_scope_reduction': True, 'stopped_samples': 'NOT_RUN', 'business_acceptance': 'INCOMPLETE'})
with stdout.open('xb') as out_handle, stderr.open('xb') as err_handle:
    process = subprocess.Popen(argv, cwd=str(ROOT), stdout=out_handle, stderr=err_handle)
    put(created, {'pid': process.pid, 'argv': argv, 'started_ns': time.time_ns()})
    code = process.wait()
result = {'pid': process.pid, 'argv': argv, 'native_exit': code, 'wait_returncode': process.returncode,
          'finished_ns': time.time_ns(), 'stdout': metadata(stdout), 'stderr': metadata(stderr)}
put(terminal, result)
print(json.dumps({'native_exit': code, 'wait_returncode': process.returncode, 'pid': process.pid,
                  'full_project_acceptance': 'INCOMPLETE'}))
raise SystemExit(code)
