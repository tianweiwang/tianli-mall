from pathlib import Path
import hashlib, json, os, subprocess, sys, time

ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
OUT = ORG / 'currentbafb-organized-materialize-actual-r1'
ADM = ORG / 'current41c0-normal-root-admission-r1'
SOURCE = ORG / 'currentbafb-next-normal-preparation-r3/normal-source'
PY = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'

def fp(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}

def put(name, value):
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()
    with (OUT / name).open('xb') as handle:
        assert handle.write(raw) == len(raw)

assert not sys.flags.optimize
# This new organized entry did not launch the already completed original bafb M.
# Existing original M or any outer receipt is a refusal, never an invitation to retry.
assert not OUT.exists(), 'New organized materialize OUT must be absent'
assert not (SOURCE / 'validation/materialize-freeze-assets-r1').exists()
assert not (SOURCE / 'launcher/materialize.created.json').exists()
assert not (SOURCE / 'launcher/materialize.terminal.json').exists()
reports = {}
for name, path, size, digest in [
    ('Root_Source', ADM / 'R100-SOURCE-CALLBACK-ADMISSION.json', 2067, '9d54b7e5c6ccc47ddb580fc9b05cd4dca625e19cf9e98750761914581510d8e6'),
    ('independent_Source', ORG / 'currentbafb-complete-normal-independent-source-r3/REPORT.json', 5803, 'aa72c3a7dfcd8a01c5e9736265facb93bf9783b4f1a3b8fc047b98e23c4937af'),
    ('Root_actual_callback_readback', ADM / 'R100-CALLBACK-ACTUAL-ROOT-READBACK.json', 1947, '626e8fcee1ff71a8d09974e0b5df16bd3c5c3f1502a8be00d9b7114d4bca75f3'),
    ('d0_failed_finite_closure', ADM / 'R98-D0A5-FAILED-FINITE-HISTORY-INDEX.json', 3972, 'c43abf997074d602d56b839791412e281dfb334ad826579b0a9fae3b7e31a35f')]:
    raw = path.read_bytes()
    assert fp(raw) == {'bytes': size, 'sha256': digest}
    value = json.loads(raw)
    if name == 'independent_Source':
        assert value['finding_count'] == 0 and value['status'] == 'PASS_INDEPENDENT_COMPLETE_R3_NARROW_SOURCE_REVIEW_FINDING0_F2_SOURCE_CLOSED'
    elif name == 'Root_actual_callback_readback':
        assert value['actual_PID'] == 27244 and value['native_exit'] == value['wait_returncode'] == 0
        assert value['M_and_S_each_full_checks'] == 11680434 and value['complete_return_objects_equal'] is True
        assert value['actual_source_paths'] == 32497 and value['callback_native_calls'] == 0
        assert value['Root_all_windows_ended'] is True and value['M_body_S_main_App_business'] == 'NOT RUN'
        assert value['normal_Source_PINS'] == {'path': (SOURCE / 'PINS.json').as_posix(), 'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
    elif name == 'Root_Source':
        assert value['scope'] == 'TWO_ORIGINAL_FULL_SOURCE_AUTHORITY_CALLBACKS_ONLY_AFTER_ROOT_AND_INDEPENDENT_SOURCE_REVIEW'
        assert value['full_current_runtime_and_entry_PINS_full_bytes_and_names_checked'] is True
        assert value['M_body_S_main_App_business_admitted'] is False
        for row in value['Root_fixed_refs'] + value['independent_reports']:
            assert fp(Path(row['path']).read_bytes()) == {key: row[key] for key in ('bytes', 'sha256')}
    else:
        assert value['run_id'] == '20261024114233c6e65e' and value['source_sha'] == 'd0a5d5398546e223122b5c2a2f115e1908f78ced'
        assert value['original_S_native_exit_wait'] == 1 and value['Task6_exit0_retired'] is True
        assert {key: value['counts'][key] for key in ('close_native', 'independent_zero_native', 'current_residual')} == {'close_native': 90, 'independent_zero_native': 12, 'current_residual': 0}
    reports[name] = {'path': path.as_posix(), **fp(raw)}
pins = (SOURCE / 'PINS.json').read_bytes()
assert fp(pins) == {'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
packet = json.loads(pins)
assert packet['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
assert len(packet['files']) == 29 and len(packet['source_members18']) == 18
for name, row in packet['files'].items():
    assert set(row) == {'path', 'bytes', 'sha256'} and Path(row['path']) == SOURCE / name
    assert fp((SOURCE / name).read_bytes()) == {key: row[key] for key in ('bytes', 'sha256')}
launch = SOURCE / 'launcher/launch.py'
assert fp(launch.read_bytes()) == {'bytes': 19276, 'sha256': '7aa97994a1b765e14e46dbd7265fe270db328f6ca1261f65e1848792d5da253c'}
OUT.mkdir(exist_ok=False)
put('R3-MATERIALIZATION-ADMISSION.json', {
    'scope': 'ONE_ORGANIZED_CURRENTBAFB_R3_SOURCE_MATERIALIZATION_ENTRY_ONLY',
    'historical_original_bafb_M_launched_by_this_new_entry': False,
    'source_PINS': {'path': (SOURCE / 'PINS.json').as_posix(), **fp(pins)},
    'reviews': reports, 'source_callbacks_actual_native_wait': 0,
    'source_callbacks_each_checks': 11680434,
    'expected_fields_from_Source': 37, 'expected_chain_from_Source': 24,
    'native_budget_derived_not_actual': 1150,
    'complete_tracked_expected': 15750, 'required_per_checkout': 316,
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
