"""Root starts one reviewed UI Run and stops at actual backend ready17 only.

Gateway remains unstarted. No private reader, observer, browser or normal stop runs.
Every original Host CLI native handle and complete streams are retained create-only.
"""
from pathlib import Path
import hashlib
import json
import subprocess
import sys
import argparse
import os
import stat

RUNTIME = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/fresh-b-customer-form-external-source-r3/runtime')
PROFILE = RUNTIME / 'actual-profile'
HOST = PROFILE / 'ms4-host.py'
SOURCE = '53e6d521b387b19a4cf370e0e381670b762d325e'
A_OUTER_SESSION = None
SOURCE_REVIEW_AUTHORITY = None
POST_A_HISTORY_CONTRACT = None
ROOT_SOURCE = Path(__file__).parent
ROOT_INPUTS_PIN = {'bytes': 59221, 'sha256': 'a317e210c9d82d454dc9e68f3568c4f2258885494ac55430b2e03f8d0243342f'}
A_QUALIFICATION = None  # WAIT: actual current A outer0/success/close/zero/post-A history.
CURRENT_CORE_AUX_AUTHORITY_PIN = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/fresh-b-customer-form-external-source-r3/current-core-aux/CORE-AUX-AUTHORITY.json', 'bytes': 1400, 'sha256': '1485088a780027013d8cb72e4d41f20bf59d6a01769840cfdc9e5cb242772f0a'}
REPO = Path('H:/天俪商城/worktrees/ms4-aftersale-normal-check')
RUN_ROOT = Path('H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs')
SETUP = RUNTIME / 'materialize' / 'RESULT.json'
SEQUENCE = 0
START_ARCHIVE = RUNTIME / 'native-start-to-ready'
ARCHIVE = START_ARCHIVE
CONTEXT = None
PINS = {'ms4-host.py': '9c2090c7313076418ef348829de4b91ac1a2f42c9e377b2625429e119b31b266', 'normal-close-ms4-fulfillment.py': '0a35ff07b38afc010e230e009a148fd65f7931daff0ec7e8ff09f6596cbb75e4', 'verify-zero-ms4-fulfillment.py': '67ecfb0244300d52b665651155734234b9f9cbe9f583c5b01dd86968c96a2580'}
SOURCE_BYTES = {'ms4-host.py': 551411, 'normal-close-ms4-fulfillment.py': 262645, 'verify-zero-ms4-fulfillment.py': 649577}
PROFILE_ARTIFACTS = [{'artifact': 'compose.yml', 'bytes': 19121, 'sha256': '2ce6b212e2a09f947ff3ac0e5f78f3420dbfeaab8d8af56e7ffca128e9057cc5'}, {'artifact': 'interface-v1.json', 'bytes': 118487, 'sha256': 'd0780d80bcd1f317d7ef13706fa90c0dccbeafa75cfb5e19163e68d48728e9d3'}, {'artifact': 'ms4-host.py', 'bytes': 551411, 'sha256': '9c2090c7313076418ef348829de4b91ac1a2f42c9e377b2625429e119b31b266'}, {'artifact': 'ms4-initialize.php', 'bytes': 118698, 'sha256': '0d11d3764bbadb0e1fb603e1fd3e06518b3f5c6510ad3204379c8c0b5c0cb45e'}, {'artifact': 'normal-close-ms4-fulfillment.py', 'bytes': 262645, 'sha256': '0a35ff07b38afc010e230e009a148fd65f7931daff0ec7e8ff09f6596cbb75e4'}, {'artifact': 'README.md', 'bytes': 60188, 'sha256': 'cfca3784a7c0aed7191943863717c21acefebd1333f5508b9fbca5e26e9e81b8'}, {'artifact': 'seed-source-inverse.json', 'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, {'artifact': 'source-derivation.json', 'bytes': 190569, 'sha256': 'd6c67b4bbe2e4861d272e2b520e09108e8224f50cded4b56c9bf1df9e7a735e2'}, {'artifact': 'verify-zero-ms4-fulfillment.py', 'bytes': 649577, 'sha256': '67ecfb0244300d52b665651155734234b9f9cbe9f583c5b01dd86968c96a2580'}, {'artifact': 'ms4-ui-nginx.conf', 'bytes': 4105, 'sha256': '033ae09499501ec9ef9a2ab87d5da53687491be06f20615781f2d5b01c4cbca5'}, {'artifact': 'ms4-ui-public-copy.php', 'bytes': 25847, 'sha256': '0f2a7944b25db42cbf935816b4b9366a599049a28ac77e32bab167b08fec47d0'}, {'artifact': 'ms4-ui-copy.php', 'bytes': 49037, 'sha256': '110e2b7e89aff72513238f4615fd220b2e0b3c6b4931ed37ebb58004ff959f54'}, {'artifact': 'ms4-order.png', 'bytes': 234, 'sha256': '1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8'}, {'artifact': 'manifests/ui-h5-dist.json', 'bytes': 49501, 'sha256': 'df48f2e9160e8fd3d9f0bde5b9cdf633f268cf8f43d24da8fd9701b933a0e876'}, {'artifact': 'manifests/ui-merchant-dist.json', 'bytes': 4174, 'sha256': '0f916caf675fde4ef5460030f21769b1a2357e98b8acbee625b56e2b444be8f3'}, {'artifact': 'manifests/ui-admin-dist.json', 'bytes': 87054, 'sha256': '0fa82aebab70161d16d48e9a7f6bed45d7e45232ef19dd153c46ecc0ced810e7'}, {'artifact': 'manifests/ui-public-copy-profile.json', 'bytes': 534684, 'sha256': 'bdade907675b73a5be1fccceb4449306f33f2acbc3242bf449ce4dddd87970a2'}]
HOST_INSPECT = '{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"user":{{json .Config.User}},"stop_signal":{{json (index .Config "StopSignal")}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}}}'

def require(value, code):
    if not value:
        raise RuntimeError(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def save(path, raw):
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'FULL_CREATE_ONLY_WRITE')
    require(path.read_bytes() == raw, 'FULL_CREATE_ONLY_READBACK')


def emit(value):
    print(json.dumps(value, ensure_ascii=False), flush=True)


def command(script, arguments, label, result_path=None):
    global SEQUENCE
    SEQUENCE += 1
    require(script.is_file() and not script.is_symlink() and sha(script.read_bytes()) == PINS[script.name],
            'EXACT_REVIEWED_CLI_SOURCE')
    argv = [sys.executable, '-B', '-X', 'utf8', str(script), *arguments]
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stem = ARCHIVE / ('%03d-' % SEQUENCE + label)
    save(stem.with_suffix('.created.json'), (json.dumps({'argv': argv, 'pid': process.pid}) + '\n').encode())
    emit({'stage': label, 'state': 'SAME_NATIVE_PROCESS_LIVE', 'native_pid': process.pid})
    stdout, stderr = process.communicate()
    waited = process.wait()
    save(stem.with_suffix('.stdout'), stdout)
    save(stem.with_suffix('.stderr'), stderr)
    receipt = {'pid': process.pid, 'native_exit': process.returncode, 'wait_returncode': waited,
               'stdout_bytes': len(stdout), 'stdout_sha256': sha(stdout),
               'stderr_bytes': len(stderr), 'stderr_sha256': sha(stderr)}
    save(stem.with_suffix('.terminal.json'), (json.dumps(receipt) + '\n').encode())
    require(type(process.returncode) is int and process.returncode == waited == 0 and stderr == b'',
            'ACTUAL_CLI_NATIVE_ZERO_EMPTY_STDERR_' + label)
    if result_path is None:
        value = json.loads(stdout)
    else:
        require(result_path.is_file() and not result_path.is_symlink(), 'ACTUAL_NEW_RESULT_FILE')
        value = json.loads(result_path.read_bytes())
    require(type(value) is dict, 'CLI_ACTUAL_JSON_OBJECT')
    emit({'stage': label, 'native_exit': process.returncode, 'status': value.get('status'),
          'container_exit': value.get('exit_code')})
    return value


def stage(name, proof=None):
    arguments = [name, '--context', str(CONTEXT)]
    if proof is not None:
        arguments += ['--proof', proof, '--seconds', '30']
    return command(HOST, arguments, name + ('-' + proof if proof else ''))


def observe(proof, expected_cid=None):
    cid = expected_cid
    while True:
        value = stage('observe', proof)
        require(type(value.get('id')) is str, 'ACTUAL_SAME_CONTAINER_ID')
        if cid is None:
            cid = value['id']
        require(value['id'] == cid, 'NEVER_RESTART_OR_REPLACE_OBSERVED_CID')
        if value['status'] == 'exited':
            require(type(value['exit_code']) is int and value['exit_code'] == 0
                    and value['pid'] == 0 and value['restart_count'] == 0,
                    'ACTUAL_NORMAL_CONTAINER_TERMINAL_' + proof)
            return value
        require(value['status'] in ['created', 'running']
                and value.get('observation') == 'LIVE_SAME_HANDLE_PRESERVED', 'ONLY_SAME_LIVE_NATIVE_HANDLE')


def finish_helper(proof):
    require(stage('finish-helper', proof)['status'] == 'SAME_TERMINAL_HELPER_REMOVED_ONLY',
            'SAME_REAL_NATIVE_ZERO_HELPER_REMOVED_' + proof)


def registered(name, proof, resume, expected_status):
    value = stage(name)
    live_states = ['LIVE_REGISTRATION_SAME_HANDLE_PRESERVED', 'LIVE_PAID_REGISTRATION_SAME_HANDLE_PRESERVED',
                   'LIVE_EARLY_REGISTRATION_SAME_HANDLE_PRESERVED', 'LIVE_FULFILLMENT_REGISTRATION_SAME_HANDLE_PRESERVED',
                   'LIVE_NEW_NORMAL_REGISTRATION_SAME_HANDLE_PRESERVED', 'LIVE_WORKER_REGISTRATION_SAME_HANDLE_PRESERVED']
    if value.get('status') in live_states:
        observe('register-' + proof)
        finish_helper('register-' + proof)
        if name == 'early-paid-create':
            # This stage has genuinely created the sole canonical paid CID.
            # Registration completion needs no second create and no premature paid binding.
            require(resume is None, 'EARLY_PAID_NEVER_RECREATED_OR_PREMATURELY_BOUND')
            return
        value = stage(resume)
        if name == 'initializer-start':
            expected_status = 'SAME_INITIALIZER_STARTED_ONLY'
    require(value.get('status') == expected_status, 'EXACT_CREATED_STAGE_STATUS_' + name)


def authorize(name, proof):
    value = stage(name)
    if value.get('status') in ['created', 'running']:
        observe(proof)
        finish_helper(proof)
    else:
        require(value.get('status') == 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY', 'ACTUAL_NORMAL_CLOSE_AUTHORIZATION_' + proof)


def gateway_unstarted_from_ready(context, before_names):
    # Consume only the already-recorded inspect produced during the successful ready CLI.
    evidence = Path(context['run_dir']) / 'evidence'
    original = json.loads((evidence / 'ui-gateway-created.json').read_bytes())
    argv = ['docker', 'inspect', '--type', 'container', '--format', HOST_INSPECT, original['id']]
    new_names = set(evidence.glob('copy-native-*.terminal.json')) - before_names
    matches = []
    for path in sorted(new_names):
        value = json.loads(path.read_bytes())
        if value.get('label') == 'web-current-collector' and value.get('argv') == argv:
            matches.append((path, value))
    require(len(matches) == 1, 'ONE_FRESH_GATEWAY_INSPECT_IN_SAME_READY_CLI')
    terminal_path, receipt = matches[0]
    stem = terminal_path.name[:-len('.terminal.json')]
    created_path = evidence / (stem + '.created.json')
    stdout_path = evidence / (stem + '.stdout')
    stderr_path = evidence / (stem + '.stderr')
    created = json.loads(created_path.read_bytes())
    stdout = stdout_path.read_bytes()
    stderr = stderr_path.read_bytes()
    require(set(receipt) == {'label', 'argv', 'pid', 'native_exit', 'wait_returncode', 'stdout_bytes',
            'stdout_sha256', 'stderr_bytes', 'stderr_sha256'}
            and type(receipt['pid']) is int and receipt['pid'] > 0
            and type(receipt['native_exit']) is int and type(receipt['wait_returncode']) is int
            and receipt['native_exit'] == receipt['wait_returncode'] == 0
            and created == {'label': 'web-current-collector', 'argv': argv, 'pid': receipt['pid']}
            and receipt['stdout_bytes'] == len(stdout) and receipt['stdout_sha256'] == sha(stdout)
            and stderr == b'' and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == sha(stderr),
            'ACTUAL_FRESH_GATEWAY_CREATED_TERMINAL_FULL_STREAMS_AND_WAIT')
    facts = json.loads(stdout)
    require(facts['id'] == original['id'] and facts['created_at'] == original['created_at']
            and facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0
            and type(facts['restart_count']) is int and facts['restart_count'] == 0
            and facts['labels'] == original['labels'] and facts['image'] == original['image']
            and facts['command'] == original['command'] and facts['entrypoint'] == original['entrypoint']
            and all(row is None for row in (facts['ports'] or {}).values()),
            'SAME_ACTUAL_GATEWAY_STILL_UNSTARTED_AT_READY_BOUNDARY')
    files = []
    for path in [created_path, terminal_path, stdout_path, stderr_path]:
        require(path.is_file() and not path.is_symlink(), 'ACTUAL_ORIGINAL_GATEWAY_PUBLIC_NATIVE_FILE')
        raw = path.read_bytes()
        files.append({'path': path.as_posix(), 'bytes': len(raw), 'sha256': sha(raw)})
    return {'container_id': facts['id'], 'status': facts['status'], 'pid': facts['pid'],
            'restart_count': facts['restart_count'], 'native_files': files}


def current_core_aux_authority(inputs, frozen):
    # Independent Source-only authority; no M import, App, private material or native action.
    def stable(path, row):
        require(type(row) is dict and set(row) in [{'bytes','sha256'},{'path','bytes','sha256'}]
            and type(row['bytes']) is int and row['bytes'] > 0 and type(row['sha256']) is str
            and len(row['sha256']) == 64 and all(c in '0123456789abcdef' for c in row['sha256']),
            'CURRENT_AUTHORITY_TYPED_FILE_PIN')
        require(path.is_absolute() and path.resolve(strict=True) == path and not path.is_symlink(),
            'CURRENT_AUTHORITY_CANONICAL_FILE')
        before = path.lstat()
        require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
            and not (getattr(before,'st_file_attributes',0) & 1024), 'CURRENT_AUTHORITY_REGULAR_SINGLE_LINK_FILE')
        fields = ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
        initial = tuple(getattr(before,k) for k in fields)
        raw = path.read_bytes()
        require(len(raw) == row['bytes'] and sha(raw) == row['sha256'] and path.read_bytes() == raw
            and tuple(getattr(path.lstat(),k) for k in fields) == initial, 'CURRENT_AUTHORITY_FULL_STABLE_SOURCE_BYTES')
        return raw
    descriptor = inputs['current_core_aux_authority']
    root = ROOT_SOURCE.parent / 'current-core-aux'
    require(descriptor == CURRENT_CORE_AUX_AUTHORITY_PIN and set(descriptor) == {'path','bytes','sha256'}
        and descriptor['path'] == (root / 'CORE-AUX-AUTHORITY.json').as_posix(),
        'PINNED_CURRENT_AUTHORITY_FROM_EXACT_ROOT_INPUTS')
    raw = stable(root / 'CORE-AUX-AUTHORITY.json', descriptor)
    authority = json.loads(raw)
    require(type(authority) is dict and set(authority) == {'schema','scope','source_sha','core_sources','browser_manifest','base_source'}
        and type(authority['schema']) is int and authority['schema'] == 1
        and authority['scope'] == 'R40_UI_CURRENT_CORE_AUX_SOURCE_ONLY' and authority['source_sha'] == SOURCE,
        'CURRENT_AUTHORITY_EXACT6_CURRENT_SOURCE')
    core_names = ['help/audit-regressions/isolated/ms4-ui-backend.php',
        'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php',
        'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php']
    core = authority['core_sources']
    require(type(core) is list and len(core) == 3 and [r['path'] for r in core] == core_names
        and all(type(r) is dict and set(r) == {'path','bytes','sha256'} for r in core), 'CURRENT_CORE3_EXACT_ORDERED_IDENTITIES')
    manifest_row = authority['browser_manifest']
    require(type(manifest_row) is dict and set(manifest_row) == {'path','bytes','sha256'}
        and manifest_row == {'path':(ROOT_SOURCE.parent / 'r29-ui-current-core-aux-source-r1/browser-v1/PINS.json').as_posix(),
            'bytes':1222,'sha256':'83b9267b2759b9bf8b120e734f2c4f595ce01f08743c96ed93ada98e403bda7d'},
        'CURRENT_EXACT_EXTERNAL_INNER_MANIFEST_PATH')
    browser_root = Path(manifest_row['path']).parent
    manifest_raw = stable(Path(manifest_row['path']), manifest_row)
    manifest = json.loads(manifest_raw)
    names = ['reader/ms4-ui-private-reader.mjs','ms4-ui-private-caller.mjs','ms4-ui-browser-close.mjs',
        'ms4-ui-business-producer.mjs','ms4-ui-window-native-bridge.mjs','ms4-ui-network-capture.mjs',
        'ms4-ui-dom-producer.mjs','ui-business-consumer-contract.json']
    require(set(manifest) == {'members'} and type(manifest['members']) is dict
        and list(manifest['members']) == names
        and all(type(row) is dict and set(row) == {'bytes','sha256'} for row in manifest['members'].values()),
        'CURRENT_INNER_EXACT_MEMBERS_ORDERED8')
    expected = {'CORE-AUX-AUTHORITY.json':descriptor, 'browser-v1/PINS.json':manifest_row}
    expected.update({'browser-v1/'+name:row for name,row in manifest['members'].items()})
    require(len(expected) == 10, 'CURRENT_AUTHORITY_COMPLETE_TEN_FILES')
    def source_namespace(base):
        files, directories = [], []
        def scan_error(error):
            raise error
        for current, children, leaves in os.walk(base, followlinks=False, onerror=scan_error):
            current_path = Path(current)
            facts = current_path.lstat()
            require(stat.S_ISDIR(facts.st_mode) and not current_path.is_symlink()
                and current_path.resolve(strict=True) == current_path and not (getattr(facts,'st_file_attributes',0) & 1024),
                'CURRENT_AUTHORITY_ORDINARY_SOURCE_DIRECTORY')
            for name in children:
                child = current_path / name
                facts = child.lstat()
                require(stat.S_ISDIR(facts.st_mode) and not child.is_symlink()
                    and not (getattr(facts,'st_file_attributes',0) & 1024), 'CURRENT_AUTHORITY_NO_LINK_SOURCE_DIRECTORY')
                directories.append(child.relative_to(base).as_posix())
            for name in leaves:
                files.append((current_path / name).relative_to(base).as_posix())
        return {'files':sorted(files),'directories':sorted(directories)}
    namespace_before = {'authority':source_namespace(root),'browser':source_namespace(browser_root)}
    require(namespace_before == {'authority':{'files':['CORE-AUX-AUTHORITY.json'],'directories':[]},
        'browser':{'files':sorted(['PINS.json',*names]),'directories':['reader']}},
        'CURRENT_EXACT_ONE_AUTHORITY_PLUS_EXTERNAL_NINE_FILE_CLOSURE')
    source_paths = {'CORE-AUX-AUTHORITY.json':root / 'CORE-AUX-AUTHORITY.json',
        'browser-v1/PINS.json':browser_root / 'PINS.json'}
    source_paths.update({'browser-v1/'+name:browser_root / name for name in names})
    source_bytes = {name:stable(source_paths[name],row) for name,row in expected.items()}
    ui = inputs['ui_profile_publication']; ui_root = ROOT_SOURCE.parent / 'candidate'
    require(set(ui) == {'root','PINS','PUBLICATION'} and ui['root'] == ui_root.as_posix()
        and ui['PINS']['path'] == (ui_root / 'PINS.json').as_posix()
        and ui['PUBLICATION']['path'] == (ui_root / 'PUBLICATION.json').as_posix(), 'FIXED_CURRENT_UI_PUBLICATION_SOURCE')
    ui_pins = json.loads(stable(ui_root / 'PINS.json',ui['PINS']))
    pub = json.loads(stable(ui_root / 'PUBLICATION.json',ui['PUBLICATION']))
    selected = [{'path':row['artifact'],'bytes':row['bytes'],'sha256':row['sha256']} for row in PROFILE_ARTIFACTS]
    require(set(ui_pins) == {'schema','scope','members'} and type(ui_pins['schema']) is int and ui_pins['schema'] == 1
        and ui_pins['scope'] == 'R40_UI17_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL'
        and set(ui_pins['members']) == {row['path'] for row in selected} | {'DESIGN.md','PUBLICATION.json'}
        and ui_pins['members']['PUBLICATION.json'] == {key:ui['PUBLICATION'][key] for key in ['bytes','sha256']},
        'CURRENT_UI_SOURCE_PINS_AND_PUBLICATION_JOIN')
    require(type(pub['schema']) is int and pub['schema'] == 1 and pub['scope'] == 'R40_UI17_KNOWN_SOURCE_METADATA_ONLY'
        and pub['source_sha'] == SOURCE and pub['parent'] == '2fa872e6fda7a8bf46c55f88dd946b972ec79102'
        and pub['target_execution'] == 'NOT RUN' and pub['selected17'] == selected, 'CURRENT_UI_SOURCE_SEVENTEEN_IDENTITIES')
    finite = pub['finite_contexts']
    ordered = [{'path':name,**manifest['members'][name]} for name in names]
    require(finite['repository_committed_files'] == inputs['committed_files']
        and len(inputs['committed_files']) == 3 and 'repository_committed_five' not in finite,
        'ACTUAL_THREE_COMMITTED_FILES_EXACT_CURRENT_PUBLICATION')
    require(finite['current_core_aux_authority'] == descriptor and finite['current_Core3'] == core
        and finite['browser_manifest'] == manifest_row and finite['ordered_auxiliary_members'] == ordered
        and pub['base_source'] == authority['base_source'], 'INDEPENDENT_UI_PUBLICATION_CURRENT_AUTHORITY_JOIN')
    normal = inputs['normal_profile_publication']; normal_root = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-raw-env-normal-next-source-r1/validation')
    require(set(normal) == {'root','profile_root','PINS','PUBLICATION'} and normal['root'] == normal_root.as_posix()
        and normal['profile_root'] == (normal_root / 'actual-profile').as_posix()
        and normal['PINS']['path'] == (normal_root / 'PROFILE-PINS.json').as_posix()
        and normal['PUBLICATION']['path'] == (normal_root / 'PROFILE-PUBLICATION.json').as_posix(), 'FIXED_CURRENT_ROOT5_SEPARATED_NORMAL_PUBLICATION')
    normal_pub = json.loads(stable(normal_root / 'PROFILE-PUBLICATION.json',normal['PUBLICATION']))
    normal_pins = json.loads(stable(normal_root / 'PROFILE-PINS.json',normal['PINS']))
    root_authority = inputs['normal_root']; root_pins = json.loads(stable(normal_root / 'PINS.json',root_authority['PINS']))
    require(root_pins == {'schema':1,'scope':'FULL_A_ASSET_NORMAL_DRIVER_SOURCE_PINS','members':root_authority['source_members']}
        and set(root_pins['members']) == {'DESIGN-TASKS.md','run.py'}, 'NORMAL_ROOT_TWO_REAL_SOURCE_MEMBERS')
    for name,row in root_pins['members'].items(): stable(normal_root / name,row)
    require(type(normal_pub['schema']) is int and normal_pub['schema'] == 1
        and normal_pub['scope'] == 'FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_ONLY' and normal_pub['source_sha'] == SOURCE
        and normal_pub['parent'] == '2fa872e6fda7a8bf46c55f88dd946b972ec79102' and normal_pub['target_execution'] == 'NOT RUN'
        and type(normal_pub['repository_Source_requirements']) is list and len(normal_pub['repository_Source_requirements']) == 68
        and normal_pub['repository_Source_requirements'] == pub['repository_Source_requirements'], 'SAME_CURRENT_NORMAL_SOURCE_TEN')
    require(set(normal_pins) == {'schema','scope','members'} and normal_pins['schema'] == 1
        and normal_pins['scope'] == 'FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS' and len(normal_pins['members']) == 10
        and set(normal_pins['members']) == {row['path'] for row in normal_pub['selected9']} | {'PROFILE-PUBLICATION.json'},
        'NORMAL_PROFILE_NINE_PLUS_SEPARATE_PUBLICATION_SOURCE')
    for name,row in normal_pins['members'].items():
        stable(normal_root / name if name == 'PROFILE-PUBLICATION.json' else normal_root / 'actual-profile' / name,row)
    require(finite['normal9_authority'] == {'root':normal['root'],'profile_root':normal['profile_root'],
        'PROFILE_PINS':normal['PINS'],'PROFILE_PUBLICATION':normal['PUBLICATION']}, 'EXACT_NORMAL_ROOT5_AUTHORITY_JOIN')
    joined = {}
    for row in normal_pub['repository_Source_requirements'] + core:
        require(type(row) is dict and set(row) == {'path','bytes','sha256'} and type(row['path']) is str
            and not Path(row['path']).is_absolute() and '..' not in Path(row['path']).parts
            and '\\' not in row['path'] and ':' not in row['path'], 'EXACT_CURRENT_RELATIVE_SOURCE_IDENTITY')
        require(row['path'] not in joined or joined[row['path']] == row, 'CURRENT_SOURCE_DUPLICATE_PATH_CONFLICT_REJECTED')
        joined[row['path']] = row
    require(len(joined) == 69 and list(joined.values()) == finite['SourceRows69'], 'CURRENT_SOURCE_TEN_PLUS_CORE3_UNIQUE_THIRTEEN')
    for prefix in ['driver','customer','paid','fulfillment','aftersale_review','worker','ui_prepare','ui_backend']:
        rows = frozen[prefix + '_source_files']
        require(type(rows) is list and len(rows) == len({row['path'] for row in rows}), 'ORIGINAL_EIGHT_SOURCE_GROUPS_UNIQUE')
        for row in rows:
            if row['path'] in joined:
                require(row == joined[row['path']], 'FROZEN_CURRENT_SOURCE_METADATA_EQUALS_AUTHORITY')
    for prefix, required in [('ui_prepare',[core[-1]]),('ui_backend',core)]:
        for row in required:
            require(frozen[prefix + '_source_files'].count(row) == 1, 'FROZEN_UI_PREPARE_ORACLE_AND_BACKEND_CORE3_MEMBERSHIP')
    for row in core:
        stable(REPO / row['path'], row)
    data = json.loads(source_bytes['browser-v1/ui-business-consumer-contract.json'])
    require(data['php_sources'] == {r['path']:{k:r[k] for k in ['bytes','sha256']} for r in core},
        'CURRENT_BROWSER_DATA_CORE3_IDENTITIES')
    base = authority['base_source']; base_root = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/ui-task2-r26-helper-metadata-source-r1')
    require(base == {'root':base_root.as_posix(),
        'PINS':{'bytes':9196,'sha256':'24ae8579e74699145490fb4c3ab71134c3410bd27855281926ed2d73840045e6'},
        'FINAL':{'bytes':11797,'sha256':'4338d16b65b5ad64be7ec0d1866fa6838dbd8c4098e04aded04628aa3d3e3fb3'}},
        'UNCHANGED_R26_HISTORY_AND_TOOLS_AUTHORITY_ONLY')
    old_pins = json.loads(stable(base_root / 'PINS.json',base['PINS']))
    final = json.loads(stable(base_root / 'FINAL-CANDIDATES.json',base['FINAL']))
    require(set(old_pins) == {'members'} and len(old_pins['members']) == 59, 'ORIGINAL_R26_FIFTY_NINE_MEMBER_PINS')
    tools = final['task2_observer_tools']; tool_names = ['entry.php','expected.php','gate.php']
    require(type(tools) is list and len(tools) == 3 and [r['path'] for r in tools] == tool_names
        and tools == finite['unchanged_tools3'], 'UNCHANGED_OLD_OBSERVER_TOOLS_THREE')
    require(frozen['task2_observer_source']['members'] == [
        {'path':row['path'], **{key:row[key] for key in ['bytes','sha256']}} for row in tools]
        and {p.name for p in (base_root / 'observer-source').iterdir()} == set(tool_names),
        'FROZEN_OBSERVER_MEMBERS_EQUAL_ORIGINAL_TOOLS3')
    for row in tools:
        require(set(row) == {'path','source','bytes','sha256'}
            and row['source'] == (base_root / 'observer-source' / row['path']).as_posix()
            and old_pins['members']['observer-source/'+row['path']] == {k:row[k] for k in ['bytes','sha256']},
            'OLD_TOOLS_SOURCE_AND_FULL_PIN_JOIN')
        stable(Path(row['source']), {k:row[k] for k in ['bytes','sha256']})
    require({'authority':source_namespace(root),'browser':source_namespace(browser_root)} == namespace_before
        and stable(root / 'CORE-AUX-AUTHORITY.json',descriptor) == raw,
        'CURRENT_AUTHORITY_COMPLETE_NAMESPACE_AND_BYTES_STILL_IDENTICAL')
    return authority


def initial_resource_groups(frozen, setup, authority):
    # Only public Source bytes; no App, private handoff or observer is started.
    input_path = ROOT_SOURCE / 'INPUTS.json'; input_raw = input_path.read_bytes()
    require(input_path.is_file() and not input_path.is_symlink()
        and {'bytes':len(input_raw),'sha256':sha(input_raw)} == ROOT_INPUTS_PIN,
        'SAME_PINNED_ROOT_INPUTS_FOR_RESOURCE_RECHECK')
    require(current_core_aux_authority(json.loads(input_raw), frozen) == authority,
        'SAME_COMPLETE_CURRENT_CORE_AUX_AUTHORITY_FOR_EVERY_RESOURCE_WINDOW')
    groups = {key:frozen[key] for key in ['proof_php_ini', 'task2_observer_source', 'task2_browser_source']}
    canonical = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    require(canonical(groups) == canonical(setup['initial_resources']), 'EXACT_COMPLETE_INITIAL_RESOURCE_GROUPS')
    ini, observer, browser = [groups[key] for key in ['proof_php_ini', 'task2_observer_source', 'task2_browser_source']]
    require(set(ini) == {'schema','scope','source_root','files','roles','scan_dir','config_reference'}
            and type(ini['schema']) is int and ini['schema'] == 1
            and ini['scope'] == 'MS4_PROOF_PHP_INI_RESOURCE_SOURCE'
            and ini['files'] == [{'path':'99-ms4-proof-memory.ini','bytes':18,
                'sha256':'986c7e4b2587348a8d5ba59312a2106d38bbefec35acda4e7e5c14761cfe9ff1'}]
            and ini['roles'] == ['audit-ms5-paid-producer','audit-ms4-fulfillment-normal',
                'audit-ms4-aftersale-review-normal','audit-ms4-worker-normal','audit-ms4-ui-prepare','audit-ms4-ui-backend']
            and ini['scan_dir'] == '/usr/local/etc/php/conf.d:/run/audit/proof-php-ini', 'INITIAL_PROOF_INI_EXACT7')
    require(set(observer) == {'schema','scope','core_source_sha','host_source_root','container_root','members'}
            and type(observer['schema']) is int and observer['schema'] == 1
            and observer['scope'] == 'MS5_TASK2_COMPOSITE_OBSERVER_SOURCE_ONLY'
            and observer['core_source_sha'] == SOURCE and observer['container_root'] == '/run/audit/task2-observer'
            and [row['path'] for row in observer['members']] == ['entry.php','expected.php','gate.php'], 'INITIAL_OBSERVER_EXACT6')
    names = ['reader/ms4-ui-private-reader.mjs','ms4-ui-private-caller.mjs','ms4-ui-browser-close.mjs',
        'ms4-ui-business-producer.mjs','ms4-ui-window-native-bridge.mjs','ms4-ui-network-capture.mjs',
        'ms4-ui-dom-producer.mjs','ui-business-consumer-contract.json']
    require(set(browser) == {'schema','scope','members'} and type(browser['schema']) is int and browser['schema'] == 1
            and browser['scope'] == 'MS5_TASK2_BROWSER_SOURCE_ONLY'
            and [row['path'] for row in browser['members']] == names, 'INITIAL_BROWSER_EXACT3_ORDERED8')
    def original(root, rows, directories):
        require(root.is_absolute() and root.resolve(strict=True) == root and root.is_dir()
                and not root.is_symlink() and not (getattr(root.lstat(),'st_file_attributes',0) & 1024), 'RESOURCE_REAL_CANONICAL_ROOT')
        expected = {row['path']:row for row in rows}; members = list(root.rglob('*'))
        require(len(expected) == len(rows)
                and sorted(p.relative_to(root).as_posix() for p in members if p.is_dir()) == directories
                and {p.relative_to(root).as_posix() for p in members if p.is_file()} == set(expected), 'RESOURCE_COMPLETE_DECLARED_NAMESPACE')
        for path in [root, *members]:
            require(not path.is_symlink() and not (getattr(path.lstat(),'st_file_attributes',0) & 1024)
                    and (path.is_dir() or path.is_file()), 'RESOURCE_NO_LINK_REPARSE_OR_SPECIAL')
        for name, row in expected.items():
            require(set(row) == {'path','bytes','sha256'} and type(row['bytes']) is int and row['bytes'] > 0, 'RESOURCE_SOURCE_DESCRIPTOR')
            path = root / name
            stat9 = lambda value: tuple(getattr(value,'st_' + key) for key in ['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'])
            before = stat9(path.stat()); raw = path.read_bytes()
            require(len(raw) == row['bytes'] and sha(raw) == row['sha256']
                    and stat9(path.stat()) == before and path.read_bytes() == raw, 'RESOURCE_WHOLE_SOURCE_BYTES_STABLE')
        require(sorted(p.relative_to(root).as_posix() for p in root.rglob('*'))
                == sorted(p.relative_to(root).as_posix() for p in members), 'RESOURCE_NAMESPACE_AFTER_READ')
    ini_root, observer_root = Path(ini['source_root']), Path(observer['host_source_root'])
    require(not ini_root.is_relative_to(REPO) and not observer_root.is_relative_to(REPO), 'RESOURCE_EXTERNAL_TO_CORE')
    original(ini_root, ini['files'], [])
    require((ini_root / '99-ms4-proof-memory.ini').read_bytes() == b'memory_limit=512M\n', 'ONLY_FIXED_PROOF_INI_DIRECTIVE')
    reference = ini['config_reference']; reference_path = Path(reference['path']); reference_raw = reference_path.read_bytes()
    require(set(reference) == {'path','bytes','sha256'} and type(reference['bytes']) is int
            and reference_path.is_file() and not reference_path.is_symlink()
            and len(reference_raw) == reference['bytes'] and sha(reference_raw) == reference['sha256'], 'REAL_FIXED_CONFIGURATION_REFERENCE')
    original(observer_root, observer['members'], [])
    aux = PROFILE.parent / 'browser-v1'
    require(setup['browser_auxiliary'] == aux.as_posix() and setup['browser_auxiliary_members'] == 8, 'REAL_SIBLING_AUX_NOT_INSIDE_PREP')
    manifest_raw = (aux / 'PINS.json').read_bytes(); manifest = json.loads(manifest_raw)
    manifest_row = authority['browser_manifest']
    source_aux = Path(manifest_row['path']).parent
    require({'bytes':len(manifest_raw),'sha256':sha(manifest_raw)}
        == {key:manifest_row[key] for key in ['bytes','sha256']}
        and manifest_raw == (source_aux / 'PINS.json').read_bytes(), 'CURRENT_SOURCE_AND_RUNTIME_INNER_MANIFEST_IDENTICAL')
    original(source_aux, browser['members'] + [{'path':'PINS.json', **setup['browser_auxiliary_manifest']}], ['reader'])
    require({'bytes':len(manifest_raw),'sha256':sha(manifest_raw)} == setup['browser_auxiliary_manifest']
            and set(manifest) == {'members'} and list(manifest['members']) == names
            and canonical(browser['members']) == canonical([{'path':name, **manifest['members'][name]} for name in names]), 'ACTUAL_AUX_WHOLE_PINS_ORDERED8')
    original(aux, browser['members'] + [{'path':'PINS.json', **setup['browser_auxiliary_manifest']}], ['reader'])
    return groups


def prepared_resource_copies(context, frozen, groups, setup, authority):
    # All copies are Host-produced after fresh mkdir and before its first native operation.
    canonical = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    run = Path(context['run_dir']); ini = groups['proof_php_ini']; observer = groups['task2_observer_source']
    def receipt(key, leaf, expected):
        descriptor = context[key]; path = run / leaf
        require(set(descriptor) == {'path','bytes','sha256'} and descriptor['path'] == leaf
                and type(descriptor['bytes']) is int and descriptor['bytes'] > 0
                and path.is_file() and not path.is_symlink() and path.resolve(strict=True) == path, 'ACTUAL_RESOURCE_COPY_CONTEXT_DESCRIPTOR')
        raw = path.read_bytes()
        require({'path':leaf,'bytes':len(raw),'sha256':sha(raw)} == descriptor
                and canonical(json.loads(raw)) == canonical(expected)
                and path.read_bytes() == raw, 'ACTUAL_WHOLE_INITIAL_RESOURCE_COPY_RECEIPT')
    ini_leaf = '99-ms4-proof-memory.ini'; ini_row = ini['files'][0]
    receipt('proof_php_ini_copy', 'evidence/proof-php-ini-copy.json', {
        'schema':1,'scope':'MS4_PROOF_PHP_INI_COPY','run_id':context['run_id'], 'source_sha':SOURCE,
        'freeze_sha256':context['freeze_sha256'],'resource_sha256':sha(canonical(ini)),
        'original':{'path':(Path(ini['source_root']) / ini_leaf).as_posix(),'bytes':ini_row['bytes'],'sha256':ini_row['sha256']},
        'copy':{'path':(run / 'proof-php-ini' / ini_leaf).as_posix(),'bytes':ini_row['bytes'],'sha256':ini_row['sha256']}})
    receipt('task2_observer_copy', 'evidence/task2-observer-copy.json', {
        'schema':1,'scope':'MS5_TASK2_OBSERVER_SOURCE_COPY','run_id':context['run_id'],'source_sha':SOURCE,
        'freeze_sha256':context['freeze_sha256'],'observer_source_sha256':sha(canonical(observer)),
        'originals':[{'path':(Path(observer['host_source_root']) / row['path']).as_posix(),'bytes':row['bytes'],'sha256':row['sha256']} for row in observer['members']],
        'copies':[{'path':'task2-observer/' + row['path'],'bytes':row['bytes'],'sha256':row['sha256']} for row in observer['members']]})
    for directory, rows in [('proof-php-ini',ini['files']),('task2-observer',observer['members'])]:
        root = run / directory
        require(root.is_dir() and root.resolve(strict=True) == root and not root.is_symlink()
                and sorted(p.name for p in root.iterdir()) == sorted(row['path'] for row in rows), 'ACTUAL_COPY_EXACT_NAMESPACE')
        for row in rows:
            path = root / row['path']; raw = path.read_bytes()
            require(path.is_file() and not path.is_symlink() and not (getattr(path.lstat(),'st_file_attributes',0) & 1024)
                    and len(raw) == row['bytes'] and sha(raw) == row['sha256'] and path.read_bytes() == raw, 'ACTUAL_COMPLETE_RESOURCE_COPY_BYTES')
    overlay = run / 'evidence/task2-browser-compose.json'
    expected = {'services':{'audit-ms4-ui-backend':{'volumes':[{'type':'bind',
        'source':(PROFILE.parent / 'browser-v1').as_posix(),'target':'/run/ms4-browser-source',
        'read_only':True,'bind':{'create_host_path':False}}]}}}
    require(overlay.is_file() and not overlay.is_symlink() and overlay.resolve(strict=True) == overlay
            and overlay.read_bytes() == canonical(expected) + b'\n', 'ONLY_BACKEND_CANONICAL_SIBLING_READONLY_OVERLAY')
    require(initial_resource_groups(frozen, setup, authority) == groups, 'ALL_ORIGINAL_RESOURCE_GROUPS_STILL_CURRENT')


def main():
    global ARCHIVE, CONTEXT
    require(type(A_QUALIFICATION) is dict and set(A_QUALIFICATION) == {'outer_native_exit','normal_root_result','worker_close','independent_zero','post_a_history'}, 'INCOMPLETE_R40_A_QUALIFICATION_REQUIRED')
    require(type(A_QUALIFICATION['outer_native_exit']) is int and A_QUALIFICATION['outer_native_exit'] == 0, 'ACTUAL_R40_NORMAL_OUTER_ZERO_REQUIRED')
    require(type(A_OUTER_SESSION) is int and A_OUTER_SESSION > 0 and type(SOURCE_REVIEW_AUTHORITY) is dict
        and set(SOURCE_REVIEW_AUTHORITY) == {'path','bytes','sha256'} and type(POST_A_HISTORY_CONTRACT) is dict,
        'WAIT_REAL_A_OUTER_REVIEW_AUTHORITY_AND_POST_HISTORY_SCHEMA')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root-source-pins-sha256',required=True)
    parser.add_argument('--setup-sha256',required=True)
    args = parser.parse_args()
    source_raw = (ROOT_SOURCE / 'PINS.json').read_bytes()
    require(sha(source_raw) == args.root_source_pins_sha256, 'ACTUAL_FIRST_B_ROOT_SOURCE_PINS')
    root_pins = json.loads(source_raw); names = {'DESIGN-TASKS.md','INPUTS.json','root-ui-materialize-freeze.py','root-ui-start-to-ready.py'}
    require(set(root_pins) == {'schema','scope','members'} and type(root_pins['schema']) is int and root_pins['schema'] == 1
        and root_pins['scope'] == 'R40_FIRST_B_ROOT_SOURCE_PINS' and set(root_pins['members']) == names
        and {p.name for p in ROOT_SOURCE.iterdir()} == names | {'PINS.json'}, 'EXACT_FOUR_ROOT_SOURCES_NOT_RUNTIME_TREE')
    for name,row in root_pins['members'].items():
        p = ROOT_SOURCE / name; content = p.read_bytes()
        require(p.is_file() and not p.is_symlink() and len(content) == row['bytes'] and sha(content) == row['sha256'], 'ALL_ACTUAL_CURRENT_ROOT_SOURCES')
    input_raw = (ROOT_SOURCE / 'INPUTS.json').read_bytes(); current_inputs = json.loads(input_raw)
    require(set(current_inputs) == {'schema','scope','sealed','head','parent','root_baseline','normal_root',
        'normal_profile_publication','ui_profile_publication','committed_files','history','A_QUALIFICATION',
        'A_OUTER_SESSION','source_review_authority','post_a_history_contract','body_preservation','current_core_aux_authority'}
        and type(current_inputs['schema']) is int and current_inputs['schema'] == 1
        and current_inputs['scope'] == 'R40_FIRST_B_ROOT_KNOWN_INPUTS_DRAFT'
        and current_inputs['parent'] == '2fa872e6fda7a8bf46c55f88dd946b972ec79102'
        and {'bytes':len(input_raw),'sha256':sha(input_raw)} == ROOT_INPUTS_PIN and current_inputs['sealed'] is True
        and current_inputs['head'] == SOURCE and current_inputs['A_QUALIFICATION'] == A_QUALIFICATION
        and current_inputs['A_OUTER_SESSION'] == A_OUTER_SESSION and current_inputs['source_review_authority'] == SOURCE_REVIEW_AUTHORITY
        and current_inputs['post_a_history_contract'] == POST_A_HISTORY_CONTRACT, 'SAME_SEALED_FIRST_B_ACTUAL_INPUTS')
    review = Path(SOURCE_REVIEW_AUTHORITY['path']); review_raw = review.read_bytes()
    require(review.is_file() and not review.is_symlink() and {'bytes':len(review_raw),'sha256':sha(review_raw)}
        == {k:SOURCE_REVIEW_AUTHORITY[k] for k in ['bytes','sha256']}, 'ACTUAL_CURRENT_SOURCE_REVIEW_AUTHORITY')
    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')
    require(SETUP.is_file() and not SETUP.is_symlink(), 'ACTUAL_MATERIALIZATION_AND_FREEZE_RECEIPT')
    setup_raw = SETUP.read_bytes()
    require(sha(setup_raw) == args.setup_sha256, 'ROOT_OBSERVED_ACTUAL_FIRST_B_M_RESULT')
    setup = json.loads(setup_raw)
    require(setup['root_source_pins'] == {'path':(ROOT_SOURCE / 'PINS.json').as_posix(),'bytes':len(source_raw),'sha256':sha(source_raw)}
        and setup['root_inputs'] == {'path':(ROOT_SOURCE / 'INPUTS.json').as_posix(),**ROOT_INPUTS_PIN}
        and setup['source_review_authority'] == SOURCE_REVIEW_AUTHORITY
        and setup['outer_coordinator_precondition']['original_session'] == A_OUTER_SESSION, 'SAME_ROOT_SOURCE_INPUTS_REVIEW_AND_REAL_A_SESSION')
    require(setup['prior_normal_root_result'] == A_QUALIFICATION['normal_root_result'] and setup['prior_public_history_inventory'] == {key:A_QUALIFICATION['post_a_history'][key] for key in ['path','bytes','sha256']}, 'SAME_QUALIFIED_R40_A_PUBLIC_INPUTS')
    require(setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_SEVENTEEN_FILE_UI_PROFILE_FROZEN_ONLY'
            and setup['source_sha'] == SOURCE and setup['repo'] == REPO.as_posix()
            and setup['profile'] == PROFILE.as_posix(), 'ACTUAL_REVIEWED_UI_SOURCE_AND_PROFILE')
    binding = Path(setup['freeze'])
    require(binding.is_file() and not binding.is_symlink() and binding.parent.resolve() == SETUP.parent.resolve(),
            'ACTUAL_FREEZE_IN_FIXED_MATERIALIZE_DIRECTORY')
    binding_raw = binding.read_bytes()
    frozen = json.loads(binding_raw)
    require(sha(binding_raw) == setup['freeze_sha256'] and frozen['status'] == 'CLEAN_SOURCE_FROZEN_ONLY'
            and frozen['source_sha'] == SOURCE and frozen['requested_repo_source'] == REPO.as_posix()
            and frozen['artifact_files'] == [{'path': row['artifact'], 'bytes': row['bytes'], 'sha256': row['sha256']}
                 for row in PROFILE_ARTIFACTS]
            and all(key in frozen for key in ['ui_prepare_source_files', 'ui_backend_source_files',
                'ui_copy_dist_sources', 'ui_web_port', 'ui_web_origin']), 'ACTUAL_COMPLETE_INITIAL_UI_FREEZE')
    authority = current_core_aux_authority(current_inputs, frozen)
    resources = initial_resource_groups(frozen, setup, authority)
    for row in PROFILE_ARTIFACTS:
        path = PROFILE / row['artifact']
        require(path.is_file() and not path.is_symlink(), 'ACTUAL_SELECTED_UI_PROFILE_FILE')
        raw = path.read_bytes()
        require(len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'EXACT_COMPLETE_SELECTED_UI17_COMPONENT')
    for name, digest in PINS.items():
        raw = (PROFILE / name).read_bytes()
        require(len(raw) == SOURCE_BYTES[name] and sha(raw) == digest, 'EXACT_COMPLETE_HOST_CLOSE_ZERO_SOURCE')
    ARCHIVE.mkdir()
    save(ARCHIVE / 'root-driver-source.py', Path(__file__).read_bytes())
    if not RUN_ROOT.exists():
        RUN_ROOT.mkdir()
    require(RUN_ROOT.is_dir() and not RUN_ROOT.is_symlink(), 'EXPLICIT_EXTERNAL_RUN_ROOT')
    prepared = command(HOST, ['prepare', '--binding', str(binding), '--run-root', str(RUN_ROOT)], 'prepare')
    CONTEXT = Path(prepared['context'])
    context = json.loads(CONTEXT.read_bytes())
    require(context['source_sha'] == SOURCE and context['repo_dir'] == REPO.as_posix()
            and context['prep_dir'] == PROFILE.as_posix() and context['freeze_sha256'] == setup['freeze_sha256']
            and CONTEXT == Path(context['run_dir']) / 'actual-run-context.json', 'ACTUAL_NEW_NATIVE_CONTEXT')
    prepared_resource_copies(context, frozen, resources, setup, authority)
    save(ARCHIVE / 'ACTUAL-CONTEXT.json', (json.dumps({'context': CONTEXT.as_posix(),
        'run_id': context['run_id'], 'source_sha': SOURCE, 'freeze_sha256': setup['freeze_sha256']}, indent=2) + '\n').encode())
    ARCHIVE = Path(context['run_dir']) / 'root-orchestration'
    require(not ARCHIVE.exists(), 'ONE_NEW_ACTUAL_RUN_ORCHESTRATION')
    ARCHIVE.mkdir()
    save(ARCHIVE / 'root-driver-source.py', Path(__file__).read_bytes())
    emit({'stage': 'actual_context', 'run_id': context['run_id'], 'source_sha': SOURCE, 'context': CONTEXT.as_posix()})
    if prepared['status'] == 'LIVE_SAME_HANDLE_PRESERVED':
        observe('prepare', prepared['container_id'])
        finish_helper('prepare')
    else:
        require(prepared['status'] == 'PRIVATE_PREPARE_TERMINAL_ONLY', 'ACTUAL_PREPARE_STATUS')
    require(stage('db-up')['status'] == 'NATIVE_DB_REDIS_READY_ONLY', 'ACTUAL_DB_REDIS_READY')
    registered('initializer-start', 'initializer', 'initializer-resume', 'INITIALIZER_STARTED_ONLY')
    observe('initializer')
    registered('driver-create', 'driver', 'driver-bind', 'UNSTARTED_MS5_PAYMENT_DRIVER_SOURCE_BOUND_ONLY')
    require(stage('driver-start')['status'] == 'MS5_PAYMENT_DRIVER_STARTED_ONLY', 'ACTUAL_MS5_DRIVER_START')
    observe('driver')
    registered('early-paid-create', 'paid-producer', None, 'CANONICAL_PAID_CREATED_UNSTARTED_ONLY')
    registered('fulfillment-migration-create', 'fulfillment-migration', 'fulfillment-migration-bind',
               'EARLY_MIGRATION_UNSTARTED_SOURCE_BOUND_ONLY')
    require(stage('fulfillment-migration-start')['status'] == 'EARLY_SIXTEEN_COMMAND_DRIVER_STARTED_ONLY', 'ACTUAL_EARLY16_START')
    observe('fulfillment-migration')
    require(stage('fulfillment-migration-retire')['status'] == 'EARLY_DRIVER_NATIVE_ZERO_EXACTLY_REMOVED_ONLY', 'ACTUAL_EARLY_NORMAL_RETIRE')
    require(stage('files-bootstrap-create')['status'] == 'FILES_BOOTSTRAP_CREATED_ONLY', 'ACTUAL_FILES_CREATE')
    require(stage('files-bootstrap-start')['status'] == 'FILES_BOOTSTRAP_STARTED_ONLY', 'ACTUAL_FILES_START')
    observe('files-bootstrap')
    registered('customer-prepare-create', 'customer-prepare', 'customer-prepare-bind', 'CUSTOMER_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY')
    require(stage('customer-prepare-start')['status'] == 'CUSTOMER_MODE_STARTED_ONLY', 'ACTUAL_CUSTOMER_PREPARE_START')
    observe('customer-prepare')
    registered('paid-create', 'paid-after-c3', 'paid-bind', 'PAID_PRODUCER_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY')
    require(stage('paid-start')['status'] == 'PAID_PRODUCER_STARTED_ONLY', 'ACTUAL_SAME_PAID_START')
    observe('paid-producer')
    ui_prepared = stage('ui-prepare-create')
    if ui_prepared.get('status') == 'LIVE_UI_PREPARE_REGISTRATION_SAME_HANDLE_PRESERVED':
        observe('register-ui-prepare')
        finish_helper('register-ui-prepare')
        ui_prepared = stage('ui-prepare-bind')
    require(ui_prepared.get('status') == 'UI_PREPARE_UNSTARTED_COMPLETE_SOURCE8ENV39_BOUND_ONLY',
            'ACTUAL_UI_PREPARE_SAME_UNSTARTED_SOURCE_BOUND')
    require(stage('ui-prepare-start')['status'] == 'UI_PREPARE_SAME_CID_STARTED_ONLY', 'ACTUAL_UI_PREPARE_START')
    observe('ui-prepare', ui_prepared['container_id'])
    require(stage('ui-prepare-seal')['status'] == 'UI_PREPARE_NATIVE0_ORIGINAL_HISTORY_SEALED_ONLY',
            'ACTUAL_UI_PREPARE_HISTORY_ONLY_AFTER_NATIVE_ZERO')
    require(stage('ui-prepare-retire')['status'] == 'UI_PREPARE_NATIVE0_EXACT_CID_NORMALLY_RETIRED_ONLY',
            'ACTUAL_UI_PREPARE_EXACT_NORMAL_RETIRE')
    copied = stage('ui-public-copy-create')
    while copied.get('status') == 'LIVE_COPY_REGISTRATION_SAME_CID_PRESERVED':
        copied = stage('ui-public-copy-bind')
    require(copied.get('status') == 'COPY_SAME_UNSTARTED_CID_SOURCE49_AUDIT_ENV_BOUND_ONLY',
            'ACTUAL_COPY_SAME_CID_OWN_REGISTER_HELPER_COMPLETED')
    require(stage('ui-public-copy-start')['status'] == 'COPY_ORIGINAL_CID_STARTED_CURRENT_NATIVE_PUBLISHED',
            'ACTUAL_COPY_ORIGINAL_START_AND_CURRENT')
    while True:
        observed = stage('ui-public-copy-observe')
        require(observed.get('id') == copied['container_id'], 'ACTUAL_COPY_NEVER_RECREATED')
        if observed['status'] == 'exited':
            require(type(observed['exit_code']) is int and observed['exit_code'] == 0
                    and observed['pid'] == 0 and observed['restart_count'] == 0, 'ACTUAL_COPY_NATURAL_ZERO')
            break
        require(observed['status'] in ['created', 'running']
                and observed.get('observation') == 'LIVE_SAME_CID_PRESERVED_NOT_TERMINAL',
                'ACTUAL_COPY_SAME_LIVE_NATIVE_CID')
    require(stage('ui-public-copy-publish')['status'] == 'COPY_NATURAL_ZERO_EXACT_MATERIALS_PUBLISHED',
            'ACTUAL_MATERIALS_ONLY_AFTER_COPY_ZERO')
    require(stage('ui-public-copy-retire')['status'] == 'COPY_ORIGINAL_NATURAL_ZERO_CID_NORMALLY_RETIRED',
            'ACTUAL_COPY_SAME_CID_NORMAL_RETIRE')
    web = stage('ui-web-create')
    require(web['status'] == 'WEB_BACKEND_GATEWAY_REAL_SINGLE_INTERNAL_UNSTARTED_ONLY',
            'ACTUAL_WEB_BACKEND_GATEWAY_CREATED_ONLY')
    registration = stage('ui-web-register')
    while registration.get('status') == 'LIVE_WEB_REGISTER_SAME_CID_PRESERVED':
        registration = stage('ui-web-register')
    require(registration.get('status') == 'REGISTERED_NATIVE_RESOURCES_ONLY', 'ACTUAL_WEB_ORIGINAL_FINAL_FIVE_TEN')
    connected = stage('ui-web-connect')
    require(connected['status'] == 'WEB_ORIGINAL_GATEWAY_CREATED_BOTH_NETWORKS_REAL_FORMAL_UNCHANGED'
            and connected['container_id'] == web['gateway'], 'ACTUAL_SAME_UNSTARTED_GATEWAY_LATE_FRONTEND_NETWORK')
    backend = stage('ui-backend-bind')
    require(backend['status'] == 'WEB_BACKEND_REAL_UNSTARTED_SOURCE37_HISTORY49_ENV9_BOUND_ONLY'
            and backend['container_id'] == web['backend'], 'ACTUAL_BACKEND_SAME_CID_BOUND')
    started = stage('ui-backend-start')
    require(started['status'] == 'WEB_ORIGINAL_BACKEND_STARTED_CURRENT_NATIVE10_ONLY'
            and started['container_id'] == web['backend'], 'ACTUAL_BACKEND_FIRST_START_CURRENT_ONLY')
    evidence = Path(context['run_dir']) / 'evidence'
    while True:
        before_ready_receipts = set(evidence.glob('copy-native-*.terminal.json'))
        ready = stage('ui-backend-ready')
        if ready['status'] == 'LIVE_SAME_BACKEND_NOT_READY_YET':
            require(ready['ready'] is None, 'LIVE_BACKEND_NOT_FALSE_READY')
            continue
        require(ready['status'] == 'BACKEND_REAL_READY17_LIFECYCLE_ONLY', 'ACTUAL_BACKEND_READY_ONLY')
        descriptor = ready['ready']
        ready_path = evidence / 'ui-web-control-ui-backend-ready.json'
        ready_raw = ready_path.read_bytes()
        ready_value = json.loads(ready_raw)
        require(descriptor == {'path': '/run/audit/evidence/ui-web-control-ui-backend-ready.json',
                    'bytes': len(ready_raw), 'sha256': sha(ready_raw)}
                and len(ready_value) == 17 and ready_value['scope'] == 'MS4_UI_BACKEND_READY'
                and ready_value['container_id'] == web['backend']
                and all(ready_value[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
                and ready_value['listener_open'] is True and ready_value['full_source_verified'] is True,
                'ACTUAL_READY17_ORIGINAL_DESCRIPTOR_AND_SAME_BACKEND')
        gateway = gateway_unstarted_from_ready(context, before_ready_receipts)
        require(gateway['container_id'] == web['gateway'], 'ACTUAL_GATEWAY_STILL_SAME_UNSTARTED_CID')
        break
    prepared_resource_copies(context, frozen, resources, setup, authority)
    final = {'schema': 1, 'scope': 'MS4_UI_ROOT_STARTUP_READY_ONLY', 'status': 'READY_NOT_GUI',
             'run_id': context['run_id'], 'source_sha': SOURCE, 'freeze_sha256': context['freeze_sha256'],
             'context': CONTEXT.as_posix(), 'backend_container_id': web['backend'],
             'ready': descriptor, 'gateway_unstarted': gateway, 'gateway_start_issued': False,
             'private_reader_and_dual_Host_gates': 'NOT RUN', 'observer_BEGIN': 'NOT RUN',
             'AppJS_GUI_business': 'NOT RUN', 'backend_stop_close_zero': 'NOT RUN',
             'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    raw = (json.dumps(final, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    save(ARCHIVE / 'RESULT.json', raw)
    save(START_ARCHIVE / 'RESULT.json', raw)
    emit(final)



if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        emit({'status': 'ROOT_UI_STARTUP_STOPPED_STATE_PRESERVED', 'class': type(error).__name__,
              'code': str(error), 'context': CONTEXT.as_posix() if CONTEXT is not None else None,
              'no_restart_retry_force_or_automatic_failure_cleanup': True})
        raise SystemExit(1)
