"""Integrated B/UI SourceTree caller; actual Source/A/build qualification is UNBOUND.

Original historical validators remain complete. This author never executes or
compiles a candidate. Root must bind real new producers before any output or Run.
"""
from pathlib import Path
import ast
import argparse
import base64
import re
import hashlib
import json
import stat
import subprocess
import sys

PREPARED = Path('H:/天俪商城/artifacts/project-organization-20261023/integrated-normal-and-b-source-tree-source-r3/b')
RUNTIME = Path('H:/天俪商城/artifacts/project-organization-20261023/integrated-normal-and-b-source-tree-runtime-r3-UNBOUND')
BASE = RUNTIME
MAIN = Path('H:/天俪商城/worktrees/multi-merchant-integrated')
REPO = Path('H:/天俪商城/worktrees/integrated-normal-and-b-validation-r3-UNBOUND')
PROFILE_SOURCE = Path('H:/天俪商城/artifacts/project-organization-20261023/integrated-normal-and-b-source-tree-source-r3/b/candidate')
PROFILE_PEER = None
NORMAL_SOURCE = Path('H:/天俪商城/artifacts/project-organization-20261023/integrated-normal-and-b-source-tree-source-r3/qualified-core-source-UNBOUND')
PROFILE = RUNTIME / 'actual-profile'
AUXILIARY = RUNTIME / 'browser-v1'
OUT = RUNTIME / 'materialize'
HEAD = None
PREVIOUS = None
COMMIT_PARENT = None
A_OUTER_SESSION = None  # WAIT: Root must bind the actual current A native outer identity.
A_QUALIFICATION = None  # WAIT: actual current A outer0/success/close/zero/post-A history.
OLD_RUN = None
OLD_FREEZE = None
OLD_FREEZE_BYTES = None
OLD_CONTEXT_PIN = None
NORMAL_SETUP_PIN = None
OLD_ROOT = None if OLD_RUN is None else Path('H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs') / OLD_RUN
OLD_PROFILE = NORMAL_SOURCE / 'actual-profile'
OLD_ARCHIVE = None if OLD_ROOT is None else OLD_ROOT / 'root-orchestration'
OLD_RESULT = None if OLD_ARCHIVE is None else OLD_ARCHIVE / 'RESULT.json'
OLD_COORDINATOR_SHA = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.
OLD_ARTIFACTS = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php',
    'seed-source-inverse.json', 'interface-v1.json', 'source-derivation.json',
    'normal-close-ms4-fulfillment.py', 'verify-zero-ms4-fulfillment.py']
OLD_CLI_PINS = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.
SOURCE_GROUPS = ['driver','customer','paid','fulfillment','aftersale_review','worker','ui_prepare','ui_backend']
PUBLIC_ROOT_FILES = ['actual-run-context.json', 'preparation-source-binding.json', 'manifest.json',
    'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl']
DIST_SOURCES = {'h5':None,'merchant':None,'admin':None}
UI_PREPARE_FOUR = ['help/audit-regressions/isolated/ms4-ui-prepare.php',
    'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
    'help/audit-regressions/isolated/lib/ms4-ui-credentials.php',
    'help/migrations/marketplace-ms4-order-read-menus.php']
UI_BACKEND_THREE = ['help/audit-regressions/isolated/ms4-ui-backend.php',
    'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php',
    'help/audit-regressions/isolated/ms4-ui-credential-meta.php']
ROOT_SOURCE = Path(__file__).parent
B_SOURCE_TREE_QUALIFICATION = None  # Root must bind actual new clean Source/build/A; never old receipts.
ROOT_INPUTS_PIN = None
SOURCE_REVIEW_AUTHORITY = None
POST_A_HISTORY_CONTRACT = None
AUX_SOURCE = ROOT_SOURCE.parent / 'browser-v1'
TOOL_SOURCE = PREPARED / 'ui-task2-r26-helper-metadata-source-r1/observer-source'
OPS = []
DIRTY = {}
PROTOCOL_PINS = []
OLD_PROFILE_PINS = []
OLD_NATIVE_PINS = []
ARTIFACTS = ['compose.yml', 'interface-v1.json', 'ms4-host.py', 'ms4-initialize.php', 'normal-close-ms4-fulfillment.py', 'README.md', 'seed-source-inverse.json', 'source-derivation.json', 'verify-zero-ms4-fulfillment.py', 'ms4-ui-nginx.conf', 'ms4-ui-public-copy.php', 'ms4-ui-copy.php', 'ms4-order.png', 'manifests/ui-h5-dist.json', 'manifests/ui-merchant-dist.json', 'manifests/ui-admin-dist.json', 'manifests/ui-public-copy-profile.json']
SOURCE_ROWS = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.
COMMITTED_FILES = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.
COMMITTED_PATHS = None  # UNBOUND: exact actual latest-HEAD Git delta from Root's fixed new M contract.
ADOPTION_ROOT = PREPARED / 'r26-child-form-adoption-r1'
ADOPTION_PUBLIC_FILES = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.
NORMAL_SOURCE_PINS = None  # UNBOUND: Root must pin actual same-HEAD new M/A Source.


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def save(path, raw):
    with path.open('xb') as stream:
        assert stream.write(raw) == len(raw)
    assert path.read_bytes() == raw


def ordinary(path, directory=False):
    path = Path(path)
    for current in [path, *path.parents]:
        info = current.stat(follow_symlinks=False)
        assert not current.is_symlink() and not getattr(info, 'st_file_attributes', 0) & 1024, str(current)
    info = path.stat(follow_symlinks=False)
    assert stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode), str(path)
    return path


def file_pin(path, row):
    path = ordinary(path)
    raw = path.read_bytes()
    assert type(row['bytes']) is int and row['bytes'] >= 0
    assert re.fullmatch('[a-f0-9]{64}', row['sha256'])
    assert len(raw) == row['bytes'] and sha(raw) == row['sha256'], str(path)
    return raw


def tree(root):
    root = ordinary(root, True); files = {}; directories = []
    def walk(folder):
        for path in sorted(folder.iterdir(), key=lambda p:p.name):
            info = path.stat(follow_symlinks=False)
            assert not path.is_symlink() and not getattr(info, 'st_file_attributes', 0) & 1024
            name = path.relative_to(root).as_posix()
            assert not re.search(r'(^/|\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)', name)
            if stat.S_ISDIR(info.st_mode):
                directories.append(name); walk(path)
            else:
                assert stat.S_ISREG(info.st_mode)
                raw = path.read_bytes(); files[name] = {'bytes':len(raw),'sha256':sha(raw)}
    walk(root)
    return {'files':dict(sorted(files.items())), 'directories':sorted(directories)}


def package(root, manifest_row, count):
    raw = file_pin(root / 'PINS.json', manifest_row)
    pins = json.loads(raw); pins = pins.get('members', pins)
    assert type(pins) is dict and len(pins) == count
    state = tree(root)
    assert set(state['files']) == set(pins) | {'PINS.json'}
    expected_dirs = set()
    for name, row in pins.items():
        assert state['files'][name] == row
        for parent in Path(name).parents:
            if parent.as_posix() != '.': expected_dirs.add(parent.as_posix())
    assert set(state['directories']) == expected_dirs
    return state


def native(label, argv, allow_git_switch_stderr=False):
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate()
    waited = process.wait()
    save(OUT / (label + '.stdout'), stdout)
    save(OUT / (label + '.stderr'), stderr)
    row = {'label': label, 'argv': argv, 'pid': process.pid,
           'native_exit': process.returncode, 'wait_returncode': waited,
           'stdout_bytes': len(stdout), 'stdout_sha256': sha(stdout),
           'stderr_bytes': len(stderr), 'stderr_sha256': sha(stderr)}
    save(OUT / (label + '.native.json'), (json.dumps(row) + '\n').encode())
    OPS.append(row)
    assert process.returncode == waited == 0, label
    assert stderr == b'' or (allow_git_switch_stderr and len(stderr) < 4096), label
    return stdout


def main_state(prefix, git):
    assert native(prefix + '-head', [*git, 'rev-parse', 'HEAD']).decode().strip() == HEAD
    assert native(prefix + '-branch', [*git, 'branch', '--show-current']).decode().strip() == 'feature/ms4-single-store'
    assert native(prefix + '-index', [*git, 'diff', '--cached', '--name-only', '-z']) == b''
    raw = native(prefix + '-owned-dirty', [*git, 'status', '--porcelain=v1', '-z', '--untracked-files=all'])
    rows = raw.decode().split('\x00')
    assert rows[-1] == '' and set(rows[:-1]) == {state + path for path, (_, _, state) in DIRTY.items()}
    for path, (length, digest, _) in DIRTY.items():
        file_pin(MAIN / path, {'bytes': length, 'sha256': digest})
    return raw


def source_inputs():
    raw = file_pin(ROOT_SOURCE / 'INPUTS.json', ROOT_INPUTS_PIN)
    value = json.loads(raw)
    assert set(value) == {'schema','scope','sealed','head','parent','root_baseline','normal_root',
        'normal_profile_publication','ui_profile_publication','committed_files','history',
        'A_QUALIFICATION','A_OUTER_SESSION','source_review_authority','post_a_history_contract','body_preservation','current_core_aux_authority'}
    assert type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'INTEGRATED_B_UI_SOURCE_TREE_ROOT_INPUTS_DRAFT'
    assert type(value['sealed']) is bool and value['head'] == HEAD and value['parent'] == COMMIT_PARENT
    assert value['committed_files'] == COMMITTED_FILES
    assert value['A_QUALIFICATION'] == A_QUALIFICATION and value['A_OUTER_SESSION'] == A_OUTER_SESSION
    assert value['source_review_authority'] == SOURCE_REVIEW_AUTHORITY and value['post_a_history_contract'] == POST_A_HISTORY_CONTRACT
    return value


def self_source_pins(expected_sha):
    assert re.fullmatch('[a-f0-9]{64}', expected_sha)
    raw = ordinary(ROOT_SOURCE / 'PINS.json').read_bytes()
    assert sha(raw) == expected_sha
    value = json.loads(raw)
    assert set(value) == {'schema','scope','members'} and type(value['schema']) is int and value['schema'] == 1
    assert value['scope'] == 'INTEGRATED_B_UI_SOURCE_TREE_ROOT_SOURCE_PINS'
    names = {'DESIGN-TASKS.md','INPUTS.json','root-ui-materialize-freeze.py','root-ui-start-to-ready.py'}
    assert set(value['members']) == names and {p.name for p in ROOT_SOURCE.iterdir()} == names | {'PINS.json'}
    for name,row in value['members'].items(): file_pin(ROOT_SOURCE / name,row)
    return {'path':(ROOT_SOURCE / 'PINS.json').as_posix(),'bytes':len(raw),'sha256':sha(raw)}


def publication_source(authority, source_scope, publication_scope, count, publication_fields):
    metadata_root = ordinary(Path(authority['root']),True)
    separated = 'profile_root' in authority
    root = ordinary(Path(authority['profile_root']),True) if separated else metadata_root
    pins_path = metadata_root / ('PROFILE-PINS.json' if separated else 'PINS.json')
    pub_name = 'PROFILE-PUBLICATION.json' if separated else 'PUBLICATION.json'
    assert authority['PINS']['path'] == pins_path.as_posix()
    assert authority['PUBLICATION']['path'] == (metadata_root / pub_name).as_posix()
    pins_raw = file_pin(pins_path,authority['PINS'])
    pub_raw = file_pin(metadata_root / pub_name,authority['PUBLICATION'])
    manifest = json.loads(pins_raw); pub = json.loads(pub_raw)
    assert set(manifest) == {'schema','scope','members'} and type(manifest['schema']) is int and manifest['schema'] == 1
    assert manifest['scope'] == source_scope and len(manifest['members']) == count
    assert set(pub) == publication_fields and type(pub['schema']) is int and pub['schema'] == 1
    assert pub['scope'] == publication_scope and pub['source_sha'] == HEAD and pub['parent'] == COMMIT_PARENT and pub['target_execution'] == 'NOT RUN'
    state = tree(root)
    assert set(state['files']) == (set(manifest['members']) - {pub_name} if separated else set(manifest['members']) | {'PINS.json'})
    assert state['directories'] == ([] if separated else ['manifests'])
    for name,row in manifest['members'].items():
        assert not Path(name).is_absolute() and '..' not in Path(name).parts
        target = metadata_root / name if separated and name == pub_name else root / name
        file_pin(target,row)
        if not (separated and name == pub_name): assert state['files'][name] == row
    return root,manifest,pub,state


def top_values(raw):
    text = raw.decode('utf-8'); decoder = json.JSONDecoder(); at = text.index('{') + 1; values = {}
    while True:
        while text[at].isspace(): at += 1
        if text[at] == '}': break
        key,end = decoder.raw_decode(text,at); assert type(key) is str and key not in values
        at = end
        while text[at].isspace(): at += 1
        assert text[at] == ':'; at += 1
        while text[at].isspace(): at += 1
        start = at; value,at = decoder.raw_decode(text,at); values[key] = (text[start:at],value)
        while text[at].isspace(): at += 1
        if text[at] == ',': at += 1
        else: assert text[at] == '}'; break
    return values


def known_normal_root(inputs):
    authority = inputs['normal_root']; root = ordinary(Path(authority['root']),True)
    assert root == NORMAL_SOURCE
    raw = file_pin(root / 'PINS.json',authority['PINS']); manifest = json.loads(raw)
    assert set(manifest) == {'schema','scope','members'} and type(manifest['schema']) is int and manifest['schema'] == 1
    assert manifest['scope'] == 'R40_NORMAL_DRIVER_SOURCE_PINS'
    assert manifest['members'] == authority['source_members'] == NORMAL_SOURCE_PINS
    assert set(manifest['members']) == {'DESIGN-TASKS.md','run.py'}
    source_names = {'DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json'}
    assert {p.name for p in root.iterdir() if p.is_file()} == source_names
    assert {p.name for p in root.iterdir() if p.is_dir()} <= {'actual-profile','materialize-freeze-r40','native-normal-start-r40'}
    protocol = authority['materialization_protocol']
    for name,row in protocol['Root5'].items(): file_pin(root / name,row)
    for name,row in manifest['members'].items(): file_pin(root / name,row)
    setup = json.loads(file_pin(root / 'materialize-freeze-r40/RESULT.json',authority['materialization_result']))
    assert set(setup) == set(protocol['result_fields']) and len(setup) == 26
    assert setup['source_sha'] == HEAD and setup['parent'] == setup['source_patch_base'] == COMMIT_PARENT
    assert setup['committed_files'] == COMMITTED_FILES and len(COMMITTED_FILES) == 3
    assert setup['profile_publication'] == {'root':inputs['normal_profile_publication']['root'],
        'pins':inputs['normal_profile_publication']['PINS'],'publication':inputs['normal_profile_publication']['PUBLICATION']}
    assert setup['root_source_pins'] == authority['PINS']
    assert setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
    assert setup['profile'] == OLD_PROFILE.as_posix() and setup['repo'] == REPO.as_posix()
    assert setup['Run_created'] is False and setup['App_DB_HTTP_business'] == 'NOT RUN'
    assert setup['source_counts'] == {'driver':10,'customer':10837,'paid':10838,'fulfillment':10852,'aftersale_review':10856,'worker':10857}
    assert setup['unique_source_files_actual_read'] == 10857 and setup['proof_resource_required'] is True
    assert setup['prior_retirement'] == protocol['prior_retirement']
    for name in ['close','zero']:
        row = setup['prior_retirement'][name]; file_pin(Path(row['path']),row)
        assert type(setup['prior_retirement'][name + '_native_exit']) is int and setup['prior_retirement'][name + '_native_exit'] == 0
    # This real M has no INPUTS/prepare/pre-A-consumed fields. Historical preservation is independent below.
    assert all(name not in setup for name in ['prior_public_unchanged','prior_protocol_directory_files_unchanged','prior_failed_preparation_public_files_unchanged'])
    assert authority['known_A_identity'] == {'scope':'IDENTITY_ONLY_NOT_SUCCESS','run_id':OLD_RUN,
        'source_sha':HEAD,'freeze_sha256':OLD_FREEZE,'freeze_bytes':OLD_FREEZE_BYTES,
        'context':{'path':(OLD_ROOT / 'actual-run-context.json').as_posix(),**OLD_CONTEXT_PIN}}
    file_pin(OLD_ROOT / 'actual-run-context.json',OLD_CONTEXT_PIN)
    return authority,setup


def consume_materialization(inputs):
    authority,setup = known_normal_root(inputs)
    folder = NORMAL_SOURCE / 'materialize-freeze-r40'; protocol = authority['materialization_protocol']
    assert setup['materialize_source'] == protocol['materialize_source']
    copied = file_pin(Path(setup['materialize_source']['path']),setup['materialize_source'])
    assert setup['materialize_source_inputs'] == protocol['materialize_source_inputs']
    tool = PREPARED / 'r40-normal-materialize-source-r1'
    assert {p.name for p in tool.iterdir()} == {'PLAN.md','prepare.py','INVERSE.json'}
    for name,row in setup['materialize_source_inputs'].items():
        path = Path(name); assert path.parent == tool and set(row) == {'path','bytes','sha256','stat9'}
        raw = file_pin(path,row); facts = path.lstat()
        assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']] == row['stat9']
        if path.name == 'prepare.py': assert raw == copied
    text = copied.decode('utf-8')
    assert text.index("write(out/'root-materialize-source.py'") < text.index("need(git(path,label+'-head-before'")
    # The pinned pre-frozen Source plus original first native receipt preserve copy-before-native causality.
    rows = setup['native_operations']; expected = protocol['native_commands']
    assert len(rows) == len(expected) == 18 and expected[0]['label'] == 'main-head-before'
    seen = set()
    for index,(row,command) in enumerate(zip(rows,expected),1):
        assert set(row) == {'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}
        assert {'label':row['label'],'argv':row['argv']} == command
        assert row['stem'] == '%03d-' % index + row['label'] and row['label'] not in seen
        assert type(row['pid']) is int and row['pid'] > 0
        seen.add(row['label']); stem = folder / row['stem']
        assert json.loads(ordinary(stem.with_suffix('.created.json')).read_bytes()) == {'argv':row['argv'],'pid':row['pid']}
        assert json.loads(ordinary(stem.with_suffix('.terminal.json')).read_bytes()) == row
        assert type(row['native_exit']) is int and type(row['wait_returncode']) is int and row['native_exit'] == row['wait_returncode'] == 0
        streams = {}
        for suffix in ['stdout','stderr']:
            path = stem.with_suffix('.' + suffix); streams[suffix] = file_pin(path,{'bytes':row[suffix + '_bytes'],'sha256':row[suffix + '_sha256']})
            OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(streams[suffix]),'sha256':sha(streams[suffix])})
        assert streams['stderr'] == b''
        label = row['label']; stdout = streams['stdout']
        if label in {'main-head-before','validation-head-before','main-head-after','validation-head-after'}: assert stdout == (HEAD + '\n').encode()
        elif label in {'main-parent','validation-parent'}: assert stdout == (HEAD + ' ' + COMMIT_PARENT + '\n').encode()
        elif 'status' in label: assert stdout == b''
        elif label == 'three-changed-paths': assert stdout.decode().splitlines() == sorted(x['path'] for x in COMMITTED_FILES)
        elif label.startswith('committed-blob-'):
            value = COMMITTED_FILES[int(label.rsplit('-',1)[1])-1]
            assert len(stdout) == value['bytes'] and sha(stdout) == value['sha256']
            file_pin(MAIN / value['path'],value); file_pin(REPO / value['path'],value)
        elif label == 'freeze':
            assert json.loads(stdout) == {'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':HEAD,'freeze_sha256':setup['freeze_sha256'],'runtime_SQL_App':'NOT RUN'}
        else: assert label in {'help-ms4-host-py','help-normal-close-ms4-fulfillment-py','help-verify-zero-ms4-fulfillment-py'} and stdout
        for suffix in ['created.json','terminal.json']:
            path = stem.with_suffix('.' + suffix); raw = ordinary(path).read_bytes()
            OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    for key,count in [('root_source_snapshot',14),('complete_source_snapshot',10861)]:
        descriptor = setup[key]; assert descriptor == protocol[key]
        snapshot = json.loads(file_pin(Path(descriptor['path']),descriptor)); assert len(snapshot) == count
        for name,row in snapshot.items():
            assert row['path'] == name and set(row) == {'path','bytes','sha256','stat9'} and len(row['stat9']) == 9 and all(type(x) is int for x in row['stat9'])
            path = Path(name); file_pin(path,row); facts = path.lstat()
            assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']] == row['stat9']
    raw = file_pin(Path(setup['freeze']),{'bytes':setup['freeze_bytes'],'sha256':setup['freeze_sha256']}); frozen = json.loads(raw)
    assert Path(setup['freeze']) == folder / 'freeze-binding.json' and frozen['source_sha'] == HEAD and frozen['requested_repo_source'] == REPO.as_posix()
    assert frozen['artifact_files'] == setup['selected9'] and frozen['proof_php_ini'] == setup['proof_php_ini']
    for name in ['RESULT.json','root-materialize-source.py','freeze-binding.json','ROOT-SOURCE-SNAPSHOT.json','COMPLETE-SOURCE-SNAPSHOT.json']:
        path = folder / name; raw = ordinary(path).read_bytes()
        OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    for name,row in setup['materialize_source_inputs'].items(): OLD_NATIVE_PINS.append({key:row[key] for key in ['path','bytes','sha256']})
    return authority,setup


def verify_sources():
    inputs = source_inputs()
    normal_authority,setup = known_normal_root(inputs)
    normal_root,normal_manifest,normal_pub,normal_state = publication_source(inputs['normal_profile_publication'],
        'R40_NORMAL_NINE_METADATA_SOURCE_PINS','R40_NORMAL_NINE_METADATA_SOURCE_ONLY',10,
        {'schema','scope','source_sha','parent','base_profile','selected9','repository_Source_requirements','finite_contexts','target_execution'})
    assert normal_root == NORMAL_SOURCE / 'actual-profile'
    assert [r['path'] for r in normal_pub['selected9']] == OLD_ARTIFACTS
    assert setup['selected9'] == normal_pub['selected9']
    assert json.loads((normal_root / 'source-derivation.json').read_bytes())['current_nonself_artifacts'] == [r for r in normal_pub['selected9'] if r['path'] != 'source-derivation.json']
    ui_root,manifest,pub,state = publication_source(inputs['ui_profile_publication'],
        'R40_UI17_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL','R40_UI17_KNOWN_SOURCE_METADATA_ONLY',19,
        {'schema','scope','source_sha','parent','base_source','base_profile','selected17','repository_Source_requirements','finite_contexts','target_execution'})
    assert ui_root == PROFILE_SOURCE and [r['path'] for r in pub['selected17']] == ARTIFACTS
    assert pub['repository_Source_requirements'] == normal_pub['repository_Source_requirements'] and len(pub['repository_Source_requirements']) == 10
    original_root = ordinary(Path(pub['base_source']['root']),True)
    assert original_root == PREPARED / 'ui-task2-r26-helper-metadata-source-r1'
    base = package(original_root,pub['base_source']['PINS'],59)
    original_final_raw = file_pin(original_root / 'FINAL-CANDIDATES.json',pub['base_source']['FINAL'])
    original_final = json.loads(original_final_raw)
    predecessor = pub['finite_contexts']['predecessor_Source']
    assert set(predecessor) == {'root','PINS','PUBLICATION'}
    predecessor_root = ordinary(Path(predecessor['root']),True)
    assert predecessor_root == PREPARED / 'r34-ui17-source-r1'
    predecessor_state = package(predecessor_root,predecessor['PINS'],19)
    predecessor_pub = json.loads(file_pin(predecessor_root / 'PUBLICATION.json',predecessor['PUBLICATION']))
    assert set(predecessor_pub) == set(pub) and type(predecessor_pub['schema']) is int and predecessor_pub['schema'] == 1
    assert predecessor_pub['scope'] == 'R34_UI17_METADATA_SOURCE_ONLY' and predecessor_pub['source_sha'] == '0cb342f82e878756bd59e93bc6aac85a40b6c29b'
    assert predecessor_pub['parent'] == '803c1b420dca8894fa9409b38601a1c6dc3c8b30' and predecessor_pub['target_execution'] == 'NOT RUN'
    assert pub['base_profile'] == {'root':predecessor_root.as_posix(),'artifact_files':predecessor_pub['selected17']}
    original = {r['path']:file_pin(predecessor_root / r['path'],r) for r in pub['base_profile']['artifact_files']}
    current = {r['path']:file_pin(ui_root / r['path'],r) for r in pub['selected17']}
    finite = pub['finite_contexts']; code = finite['code']
    assert len(code) == finite['profile_code_contexts'] == 16 and finite['activity_contexts'] == 7 and finite['dependency_contexts'] == 9
    assert finite['repository_committed_files'] == COMMITTED_FILES and len(COMMITTED_FILES) == 3
    assert finite['normal9_authority'] == {'root':inputs['normal_profile_publication']['root'],
        'profile_root':inputs['normal_profile_publication']['profile_root'],
        'PROFILE_PINS':inputs['normal_profile_publication']['PINS'],'PROFILE_PUBLICATION':inputs['normal_profile_publication']['PUBLICATION']}
    expected = {'interface-v1.json':{'current_native_cart_AR'},
        'ms4-initialize.php':{'current_UI_AR','current_worker_AR'},
        'ms4-host.py':{'current_AR_constants','current_UI_pin_AR','dependency_INTERFACE_SHA256'},
        'normal-close-ms4-fulfillment.py':{'dependency_HOST_SHA256','dependency_UI_HOST_SHA256'},
        'verify-zero-ms4-fulfillment.py':{'current_AR_constants','current_UI_pin_AR','dependency_HOST_SHA256','dependency_CLOSE_SHA256',
            'dependency_INTERFACE_SHA256','dependency_UI_HOST_SHA256','dependency_UI_CLOSE_SHA256','dependency_UI_INTERFACE_SHA256'}}
    assert {name:{r['name'] for r in code if r['artifact'] == name} for name in expected} == expected
    rebuilt = dict(original)
    for row in code:
        assert set(row) == {'artifact','name','before_utf8_offset','stage_utf8_offset','before','after','whole_before','whole_after'}
        name = row['artifact']; raw = rebuilt[name]; before = row['before'].encode(); after = row['after'].encode(); offset = row['stage_utf8_offset']
        assert {'bytes':len(raw),'sha256':sha(raw)} == row['whole_before'] and raw.count(before) == 1 and raw[offset:offset+len(before)] == before
        rebuilt[name] = raw[:offset] + after + raw[offset+len(before):]
        assert {'bytes':len(rebuilt[name]),'sha256':sha(rebuilt[name])} == row['whole_after']
    for name in expected: assert rebuilt[name] == current[name]
    for row in reversed(code):
        name = row['artifact']; raw = rebuilt[name]; before = row['before'].encode(); after = row['after'].encode(); offset = row['stage_utf8_offset']
        assert raw[offset:offset+len(after)] == after; rebuilt[name] = raw[:offset] + before + raw[offset+len(after):]
    for name in expected: assert rebuilt[name] == original[name]
    proofs = finite['whole_forward_inverse_files']
    assert len(proofs) == 19 and [row['name'] for row in proofs] == ARTIFACTS + ['CORE-AUX-AUTHORITY.json','DESIGN.md']
    for proof in proofs:
        name = proof['name']; before_path = Path(proof['before']['path']); after_path = Path(proof['after']['path'])
        if name in ARTIFACTS or name == 'DESIGN.md': assert before_path == predecessor_root / name and after_path == ui_root / name
        else:
            assert before_path == PREPARED / 'r34-ui-current-core-aux-source-r1/CORE-AUX-AUTHORITY.json'
            assert after_path == Path(inputs['current_core_aux_authority']['path'])
        before = file_pin(before_path,proof['before']); after = file_pin(after_path,proof['after']); raw = before
        for seam in proof['stages']:
            a,b = seam['before_utf8'].encode(),seam['after_utf8'].encode(); offset = seam['stage_utf8_offset']
            assert {'bytes':len(raw),'sha256':sha(raw)} == seam['whole_before'] and raw[offset:offset+len(a)] == a
            raw = raw[:offset] + b + raw[offset+len(a):]
            assert {'bytes':len(raw),'sha256':sha(raw)} == seam['whole_after']
        assert raw == after
        for seam in reversed(proof['stages']):
            a,b = seam['before_utf8'].encode(),seam['after_utf8'].encode(); offset = seam['stage_utf8_offset']
            assert raw[offset:offset+len(b)] == b; raw = raw[:offset] + a + raw[offset+len(b):]
        assert raw == before
        for chunk in proof['unchanged_chunks']:
            a = before[chunk['before_utf8_offset']:chunk['before_utf8_offset']+chunk['bytes']]
            b = after[chunk['after_utf8_offset']:chunk['after_utf8_offset']+chunk['bytes']]
            assert a == b and {'bytes':len(a),'sha256':sha(a)} == {k:chunk[k] for k in ['bytes','sha256']}
    changed = ['interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','README.md','source-derivation.json','verify-zero-ms4-fulfillment.py']
    assert [n for n in ARTIFACTS if original[n] != current[n]] == changed
    assert len(finite['unchanged_artifacts']) == 10 and all(original[n] == current[n] for n in finite['unchanged_artifacts'])
    assert len(original['README.md']) == 56354 and current['README.md'].startswith(original['README.md'])
    old_values,new_values = top_values(original['source-derivation.json']),top_values(current['source-derivation.json'])
    revision = finite['derivation_appended_record']; untouched = set(old_values) - {'new_business_entry','current_nonself_artifacts'}
    assert revision == 'ui_task2_r40_real_source_Root5_AR_core_authority_metadata_source_refresh'
    assert len(old_values) == 60 and len(new_values) == 61 and set(new_values) == set(old_values) | {revision}
    assert len(untouched) == 58 and all(old_values[k] == new_values[k] for k in untouched)
    assert new_values['current_nonself_artifacts'][1] == [r for r in pub['selected17'] if r['path'] != 'source-derivation.json']
    assert new_values['new_business_entry'][1]['bytes'] == 222875 and new_values['new_business_entry'][1]['sha256'] == SOURCE_ROWS['help/audit-regressions/isolated/ms4-aftersale-review-normal.php']['sha256']
    assert finite['A_QUALIFICATION'] is None and new_values[revision][1]['A_QUALIFICATION'] is None
    assert new_values[revision][1]['Root_adopted_or_executed'] is False and new_values[revision][1]['runtime_A_B_business_API_DOM_acceptance'] == 'NOT RUN'
    historical_record = old_values[predecessor_pub['finite_contexts']['derivation_appended_record']][1]
    assert historical_record['A_QUALIFICATION'] is None and historical_record['Root_adopted_or_executed'] is False
    assert historical_record['runtime_A_B_business_API_DOM_acceptance'] == 'NOT RUN'
    assert new_values[revision][1]['active_AR'] == SOURCE_ROWS['help/audit-regressions/isolated/ms4-aftersale-review-normal.php']
    preparation = finite['active_UI_prepare_provenance']['parent_descriptor']
    assert preparation == predecessor_pub['finite_contexts']['active_UI_prepare_provenance']['parent_descriptor']
    assert preparation == {'path':'help/audit-regressions/isolated/ms4-ui-prepare.php','bytes':31146,
        'sha256':'7affcce7d809a8f75222def64915c5746046c3cdab48dcf47f2c9b424b78405a'}
    assert preparation['path'] not in COMMITTED_PATHS
    provenance = finite['active_UI_prepare_provenance']
    assert set(provenance) == {'current_source_sha','current_commit_parent','parent_source_sha','current_Main_file','parent_descriptor','parent_publication',
        'current_Main_bytes_equal_pinned_parent_descriptor','current_commit_member','source_requirement_group'}
    assert provenance['current_source_sha'] == HEAD and provenance['current_commit_parent'] == COMMIT_PARENT
    assert provenance['parent_source_sha'] == '2b396ed0499d1d32017347f1963d9297338c8be0'
    assert provenance['parent_descriptor'] == preparation
    assert provenance['current_Main_file'] == {'path':(MAIN / preparation['path']).as_posix(),**{key:preparation[key] for key in ['bytes','sha256']}}
    assert provenance['parent_publication'] == predecessor_pub['finite_contexts']['active_UI_prepare_provenance']['parent_publication']
    assert provenance['current_Main_bytes_equal_pinned_parent_descriptor'] is True and provenance['current_commit_member'] is False
    assert provenance['source_requirement_group'] == 'EXISTING_UI_PREPARE_PIN_VECTOR_NOT_ADDED_TO_NORMAL_SOURCE10'
    file_pin(MAIN / preparation['path'],preparation); file_pin(REPO / preparation['path'],preparation)
    for row in finite['dependency_edges']:
        assert current[row['consumer']].decode().splitlines().count(row['field'] + " = '" + sha(current[row['dependency']]) + "'") == 1
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        old_text,new_text = original[name].decode(),current[name].decode()
        blocks = lambda text:[(type(n).__name__,getattr(n,'name',None),ast.get_source_segment(text,n)) for n in ast.walk(ast.parse(text)) if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef,ast.ClassDef))]
        assert blocks(old_text) == blocks(new_text)
    assert len(current['ms4-initialize.php']) == len(original['ms4-initialize.php']) == 111060
    assert current['ms4-initialize.php'] != ordinary(normal_root / 'ms4-initialize.php').read_bytes()
    authority_row = inputs['current_core_aux_authority']
    assert set(authority_row) == {'path','bytes','sha256'} and authority_row == finite['current_core_aux_authority']
    authority_path = Path(authority_row['path'])
    assert authority_path == PREPARED / 'r40-ui-current-core-aux-source-r1/CORE-AUX-AUTHORITY.json'
    authority = json.loads(file_pin(authority_path,authority_row))
    assert set(authority) == {'schema','scope','source_sha','core_sources','browser_manifest','base_source'}
    assert type(authority['schema']) is int and authority['schema'] == 1
    assert authority['scope'] == 'R40_UI_CURRENT_CORE_AUX_SOURCE_ONLY' and authority['source_sha'] == HEAD
    assert authority['base_source'] == pub['base_source'] and authority['core_sources'] == finite['current_Core3']
    assert len(authority['core_sources']) == 3 and len({row['path'] for row in authority['core_sources']}) == 3
    combined_rows = {}
    for row in pub['repository_Source_requirements'] + authority['core_sources']:
        assert set(row) == {'path','bytes','sha256'} and not Path(row['path']).is_absolute() and '..' not in Path(row['path']).parts
        assert row['path'] not in combined_rows or combined_rows[row['path']] == row
        combined_rows[row['path']] = row
        file_pin(MAIN / row['path'],row); file_pin(REPO / row['path'],row)
    assert len(combined_rows) == 13 and combined_rows == SOURCE_ROWS
    assert finite['SourceRows13'] == list(combined_rows.values())
    assert new_values[revision][1]['current_Core3'] == authority['core_sources']
    assert new_values[revision][1]['current_core_aux_authority'] == authority_row
    assert new_values[revision][1]['current_Core3'] == authority['core_sources']
    assert AUX_SOURCE == PREPARED / 'r29-ui-current-core-aux-source-r1/browser-v1'
    assert TOOL_SOURCE == original_root / 'observer-source'
    assert authority['browser_manifest'] == finite['browser_manifest'] == new_values[revision][1]['browser_manifest_original']
    assert authority['browser_manifest'] == {'path':(AUX_SOURCE / 'PINS.json').as_posix(),
        'bytes':1258,'sha256':'0b69df3a61dc36002462c0abcf87197e4c7de11f1635af4065c560f1837db8ad'}
    auxiliary = package(AUX_SOURCE,authority['browser_manifest'],8)
    inner_raw = file_pin(AUX_SOURCE / 'PINS.json',authority['browser_manifest']); inner = json.loads(inner_raw)
    aux_names = [row['path'] for row in finite['ordered_auxiliary_members']]
    assert set(inner) == {'members'} and list(inner['members']) == aux_names and len(aux_names) == 8
    assert inner['members'] == {row['path']:{key:row[key] for key in ['bytes','sha256']} for row in finite['ordered_auxiliary_members']}
    assert predecessor_pub['finite_contexts']['ordered_auxiliary_members'] == finite['ordered_auxiliary_members']
    assert predecessor_pub['finite_contexts']['auxiliary_code'] == [] and predecessor_pub['finite_contexts']['auxiliary_changed_members'] == []
    assert predecessor_pub['finite_contexts']['auxiliary_unchanged_members'] == aux_names and predecessor_pub['finite_contexts']['inner_manifest_literal_replacements'] == 0
    authority_state = tree(authority_path.parent)
    assert authority_state == {'files':{'CORE-AUX-AUTHORITY.json':{key:authority_row[key] for key in ['bytes','sha256']}},
        'directories':[]}
    assert auxiliary == tree(AUX_SOURCE) and auxiliary['directories'] == ['reader']
    old_aux_root = original_root / 'browser-v1'
    old_auxiliary = package(old_aux_root,original_final['auxiliary_sibling'],8)
    # The direct R33 profile kept browser8 unchanged; its actual R29 authority owns the old three browser seams.
    historical_r33_source = predecessor_pub['finite_contexts']['predecessor_Source']
    historical_r33_root = ordinary(Path(historical_r33_source['root']),True)
    assert historical_r33_root == PREPARED / 'r33-ui17-source-r1'
    package(historical_r33_root,historical_r33_source['PINS'],19)
    historical_r33_pub = json.loads(file_pin(historical_r33_root / 'PUBLICATION.json',historical_r33_source['PUBLICATION']))
    assert historical_r33_pub['source_sha'] == '803c1b420dca8894fa9409b38601a1c6dc3c8b30' and historical_r33_pub['parent'] == 'db4a8bd6a9e4e538aee39df6b4077b93f1290141'
    historical_auxiliary_source = historical_r33_pub['finite_contexts']['predecessor_Source']
    assert set(historical_auxiliary_source) == {'root','PINS','PUBLICATION'}
    historical_auxiliary_root = ordinary(Path(historical_auxiliary_source['root']),True)
    assert historical_auxiliary_root == PREPARED / 'r29-ui17-source-r1'
    package(historical_auxiliary_root,historical_auxiliary_source['PINS'],19)
    historical_auxiliary_pub = json.loads(file_pin(historical_auxiliary_root / 'PUBLICATION.json',historical_auxiliary_source['PUBLICATION']))
    assert historical_auxiliary_pub['scope'] == 'R29_UI17_METADATA_SOURCE_ONLY'
    assert historical_auxiliary_pub['source_sha'] == '2b396ed0499d1d32017347f1963d9297338c8be0'
    assert historical_auxiliary_pub['parent'] == '2522a46fb9c4e70667609c1974332c7eb3902586'
    assert historical_auxiliary_pub['base_source'] == pub['base_source']
    historical_auxiliary = historical_auxiliary_pub['finite_contexts']
    auxiliary_code = historical_auxiliary['auxiliary_code']
    assert len(auxiliary_code) == 3 and all(set(row) == {'artifact','name','before','after'} and row['name'] == 'current_Core3_oracle' for row in auxiliary_code)
    assert {row['artifact'] for row in auxiliary_code} == {'browser-v1/ms4-ui-business-producer.mjs',
        'browser-v1/ms4-ui-window-native-bridge.mjs','browser-v1/ui-business-consumer-contract.json'}
    changed_auxiliary = []
    for name in aux_names:
        before = file_pin(old_aux_root / name,old_auxiliary['files'][name])
        after = file_pin(AUX_SOURCE / name,auxiliary['files'][name]); rebuilt = before
        seams = [row for row in auxiliary_code if row['artifact'] == 'browser-v1/' + name]
        for row in seams:
            assert rebuilt.count(row['before'].encode()) == 1
            rebuilt = rebuilt.replace(row['before'].encode(),row['after'].encode(),1)
        assert rebuilt == after
        for row in reversed(seams):
            assert rebuilt.count(row['after'].encode()) == 1
            rebuilt = rebuilt.replace(row['after'].encode(),row['before'].encode(),1)
        assert rebuilt == before
        if before != after: changed_auxiliary.append(name)
    assert set(changed_auxiliary) == set(historical_auxiliary['auxiliary_changed_members']) and len(changed_auxiliary) == 3
    assert set(historical_auxiliary['auxiliary_unchanged_members']) == set(aux_names) - set(changed_auxiliary)
    rebuilt_manifest = file_pin(old_aux_root / 'PINS.json',original_final['auxiliary_sibling'])
    manifest_before = rebuilt_manifest
    for row in historical_auxiliary['inner_manifest_literal_replacements']:
        assert old_auxiliary['files'][row['member']] == row['before'] and auxiliary['files'][row['member']] == row['after']
        assert row['before']['bytes'] == row['after']['bytes'] and rebuilt_manifest.count(row['before']['sha256'].encode()) == 1
        rebuilt_manifest = rebuilt_manifest.replace(row['before']['sha256'].encode(),row['after']['sha256'].encode(),1)
    assert len(historical_auxiliary['inner_manifest_literal_replacements']) == 3 and rebuilt_manifest == inner_raw
    for row in reversed(historical_auxiliary['inner_manifest_literal_replacements']):
        assert rebuilt_manifest.count(row['after']['sha256'].encode()) == 1
        rebuilt_manifest = rebuilt_manifest.replace(row['after']['sha256'].encode(),row['before']['sha256'].encode(),1)
    assert rebuilt_manifest == manifest_before
    for row in original_final['task2_observer_tools']: file_pin(Path(row['source']),row)
    assert tree(TOOL_SOURCE) == {'files':dict(sorted((r['path'],{k:r[k] for k in ['bytes','sha256']}) for r in original_final['task2_observer_tools'])),'directories':[]}
    if SOURCE_REVIEW_AUTHORITY is not None: file_pin(Path(SOURCE_REVIEW_AUTHORITY['path']),SOURCE_REVIEW_AUTHORITY)
    assert finite['unchanged_tools3'] == original_final['task2_observer_tools']
    final = {'files':[{'artifact':r['path'],**r} for r in pub['selected17']],
        'task2_observer_tools':original_final['task2_observer_tools'],'publication':pub,'current_core_aux_authority':authority}
    return {'normal_source_pins':inputs['normal_root']['PINS'],'normal_publication':normal_state,
        'ui_source':state,'profile_predecessor_source':predecessor_state,'base_source':base,'auxiliary':auxiliary,
        'current_core_aux_authority':authority_state,'historical_auxiliary':old_auxiliary,'review_authority':SOURCE_REVIEW_AUTHORITY},final


def verify_profile(final, materialize=False):
    expected = {r['artifact']:{'bytes':r['bytes'],'sha256':r['sha256']} for r in final['files']}
    auxiliary = tree(AUX_SOURCE)
    if materialize:
        assert not PROFILE.exists() and not PROFILE.is_symlink() and not AUXILIARY.exists() and not AUXILIARY.is_symlink()
        PROFILE.mkdir(); (PROFILE / 'manifests').mkdir()
        AUXILIARY.mkdir(); (AUXILIARY / 'reader').mkdir()
        for row in final['files']:
            save(PROFILE / row['artifact'], file_pin(PROFILE_SOURCE / row['path'], row))
        for name, row in auxiliary['files'].items():
            save(AUXILIARY / name, file_pin(AUX_SOURCE / name,row))
    assert tree(PROFILE) == {'files':dict(sorted(expected.items())), 'directories':['manifests']}
    assert tree(AUXILIARY) == auxiliary
    assert {p.name for p in RUNTIME.iterdir()} == {'actual-profile','browser-v1','materialize'}
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        ast.parse(file_pin(PROFILE / name, expected[name]), filename=name)
    return expected


def protocol_retirement():
    inputs = source_inputs(); history = inputs['history']; root = PREPARED / 'cache-wire-driver-native-r2'
    raw = file_pin(root / 'RESULT.json',{'bytes':44142,'sha256':'47589708d86426423033602ee7ce0132a03ab689bfe0762cb6e11821a3dd4201'})
    result = json.loads(raw); expected_names = {'ACTUAL-OWNED.json','cache-wire-protocol.php','RESULT.json','root-driver-source.py'}
    assert len(result['operations']) == 45 and result['status'] == 'VERIFIED_ORIGINAL_CACHE_DRIVER_POSITIVE_PROTOCOL_AND_NORMAL_CLOSE'
    assert result['removed_containers'] == 2 and result['removed_internal_networks'] == 1
    assert result['named_volumes_created'] == 0 and result['six_fresh_raw_zero'] is True
    assert len(result['result']['cases']) == 8 and result['result']['final_key_count'] == 11 and result['result']['full_tag_count'] == 9
    for index,row in enumerate(result['operations'],1):
        stem = root / ('%03d-' % index + row['label'])
        assert json.loads(ordinary(stem.with_suffix('.created.json')).read_bytes()) == {'argv':row['argv'],'pid':row['pid']}
        assert json.loads(ordinary(stem.with_suffix('.terminal.json')).read_bytes()) == row
        assert type(row['pid']) is int and row['pid'] > 0 and type(row['native_exit']) is int and row['native_exit'] == row['wait_returncode'] == 0
        for suffix in ['stdout','stderr']:
            data = file_pin(stem.with_suffix('.' + suffix),{'bytes':row[suffix + '_bytes'],'sha256':row[suffix + '_sha256']})
            if suffix == 'stderr': assert data == b''
        expected_names |= {stem.name + suffix for suffix in ['.created.json','.terminal.json','.stdout','.stderr']}
    assert len(expected_names) == 184 and {p.name for p in root.iterdir()} == expected_names
    for name in sorted(expected_names):
        path = root / name; data = ordinary(path).read_bytes()
        PROTOCOL_PINS.append({'path':path.as_posix(),'bytes':len(data),'sha256':sha(data)})
    legacy = history['legacy_protocol']; failed = history['preparation_failure']
    assert len(legacy) == 35 and len(failed['files']) == 30 and len({r['path'] for r in legacy}) == 35
    for row in legacy:
        file_pin(Path(row['path']),row); PROTOCOL_PINS.append(row)
    assert len(PROTOCOL_PINS) == 219 and len({r['path'] for r in PROTOCOL_PINS}) == 219
    failed_root = ordinary(Path(failed['root']),True)
    assert failed_root == PREPARED / 'r27-normal-preparation-source-r1'
    assert set(tree(failed_root)['files']) == {Path(r['path']).relative_to(failed_root).as_posix() for r in failed['files']}
    for row in failed['files']: file_pin(Path(row['path']),row); OLD_NATIVE_PINS.append(row)
    owned = json.loads(ordinary(root / 'ACTUAL-OWNED.json').read_bytes())
    assert len(owned['containers']) == 2 and owned['network']['Internal'] is True and len(owned['labels']) == 2
    for label in ['create-redis','create-php']:
        index = next(i for i,row in enumerate(result['operations'],1) if row['label'] == label)
        cid = ordinary(root / ('%03d-' % index + label + '.stdout')).read_text().strip()
        assert cid in owned['containers'] and owned['containers'][cid]['id'] == cid
    # Existing fresh residual reads remain; the retired historical business is never rerun.
    for kind,args in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for index,(key,value) in enumerate(owned['labels'].items()):
            assert native('protocol-zero-' + kind + '-' + str(index),['docker',*args,'--filter','label=' + key + '=' + value]) == b''
    return owned


def pre_a_history():
    authority = source_inputs()['history']; root = ordinary(Path(authority['root']),True)
    assert set(authority) == {'root','files','header','legacy_protocol','preparation_failure'}
    assert root == PREPARED / 'r33-failed-ar-history-source-r1'
    assert set(authority['files']) == {'DESIGN-TASKS.md','inventory.py','INVENTORY.json','RESULT.json'}
    assert tree(root)['directories'] == [] and {p.name for p in root.iterdir()} == set(authority['files'])
    for name,row in authority['files'].items(): file_pin(root / name,row)
    raw = file_pin(root / 'INVENTORY.json',authority['files']['INVENTORY.json']); value = json.loads(raw)
    assert set(value) == {'schema','scope','run_id','source_sha','freeze_sha256','prior_public_inventory','prior_public_count',
        'all_prior_full_bytes_unchanged','full_current_public_file_count','fixed_public_files','explicit_public_trees',
        'failed_AR_result','failure_close_result','independent_zero_result','outer_coordinator_observation','native_history',
        'Task6','AR','Worker_firstB','private_inputs','full_acceptance','files'}
    assert authority['header'] == {key:row for key,row in value.items() if key != 'files'}
    assert type(value['schema']) is int and value['schema'] == 1
    assert value['scope'] == 'R33_FAILED_AR_AFTER_ACTUAL_NORMAL_CLOSE_AND_INDEPENDENT_ZERO_PUBLIC_HISTORY'
    assert value['run_id'] == '20261021030301cc3b74' and value['source_sha'] == '803c1b420dca8894fa9409b38601a1c6dc3c8b30'
    assert value['freeze_sha256'] == 'a748d661b4087d0a5c5b96677b0f2f57fa9771b65d89b676d554d7ba7ec55b5a'
    assert value['prior_public_count'] == 224135 and value['all_prior_full_bytes_unchanged'] is True
    assert value['full_current_public_file_count'] == len(value['files']) == 238900
    assert value['Task6'] == 'NORMAL_SUCCESS_AND_ACTUAL_NORMAL_RETIRE' and value['AR'] == 'FAILED_NEW_ROOT_REAL_CART_CONFIRM_CREATE'
    assert value['Worker_firstB'] == 'NOT RUN' and value['private_inputs'] == 'NOT READ OR ENUMERATED' and value['full_acceptance'] == 'INCOMPLETE'
    names = [r['path'] for r in value['files']]; assert names == sorted(set(names))
    result = {r['path']:r for r in value['files']}
    for row in value['files']:
        assert set(row) == {'path','bytes','sha256'} and Path(row['path']).is_absolute()
        file_pin(Path(row['path']),row)
    legacy = authority['legacy_protocol']
    assert len(legacy) == 35 and len({row['path'] for row in legacy}) == 35
    for row in legacy: assert result[row['path']] == row
    native_history = value['native_history']
    assert native_history['native_commands'] == native_history['successful_native_commands'] == 184
    assert native_history['last_native_exit'] == 0 and native_history['last_container_exit'] == 1
    assert native_history['same_PID_native_wait_full_streams'] is True
    assert native_history['failure_close_native_commands'] == 84 and native_history['independent_zero_native_commands'] == 12
    assert value['outer_coordinator_observation'] == {'original_session':16051,'native_exit':1,
        'failure_close_native_exit':0,'independent_zero_native_exit':0,
        'authority':'ROOT_OBSERVED_ORIGINAL_FAILED_OUTER_AND_ACTUAL_FAILURE_CLOSE_ZERO'}
    closed = json.loads(file_pin(Path(value['failure_close_result']['path']),value['failure_close_result']))
    zero = json.loads(file_pin(Path(value['independent_zero_result']['path']),value['independent_zero_result']))
    assert closed['scope'] == 'R33_FAILED_AR_EXACT_NORMAL_RETIREMENT_ONLY' and closed['status'] == 'VERIFIED_R33_AR_FAILURE_NORMAL_CLOSE_ZERO_RESIDUAL'
    assert zero['scope'] == 'R33_FAILED_AR_INDEPENDENT_ZERO_ONLY' and zero['status'] == 'VERIFIED_R33_AR_FAILURE_INDEPENDENT_SIX_QUERY_ZERO'
    assert len(closed['operations']) == 84 and len(zero['operations']) == 12
    assert zero['close_result'] == value['failure_close_result'] and zero['close_source'] == closed['source']
    for receipt in [closed,zero]:
        assert all(receipt[key] == value[key] for key in ['run_id','source_sha','freeze_sha256'])
        assert receipt['Task6'] == 'NORMAL_SUCCESS_ALREADY_RETIRED' and receipt['AR'] == 'FAILED_NEW_ROOT_REAL_CART_CONFIRM_CREATE'
        assert receipt['Worker_firstB'] == 'NOT STARTED' and receipt['full_acceptance'] == 'INCOMPLETE'
        assert receipt['stopped_samples'] == 'NOT RUN'
    assert closed['ordinary_stop_and_exact_nonforce_remove'] is True and zero['mutations'] == 'NONE'
    assert closed['all_original_public_bytes_stat9_namespaces_unchanged'] is True
    assert zero['all_original_public_and_close_bytes_stat9_namespaces_unchanged'] is True
    assert closed['removed_containers'] == 4 and closed['removed_internal_networks'] == 1 and closed['removed_original_volumes'] == 7
    assert [row['operation_index'] for row in closed['fresh_queries']] == list(range(76,82))
    assert [row['operation_index'] for row in zero['queries']] == list(range(4,10))
    # Retain the publication four and original failed M1 explicitly; duplicates must keep their original complete descriptors.
    extras = [{'path':(root / name).as_posix(),**row} for name,row in authority['files'].items()]
    extras += authority['preparation_failure']['files']
    return result,extras


def post_a_history(args,context,final_raw,close_raw,zero_raw):
    # This contract is deliberately None until Root publishes and reviews the actual successful-A schema.
    contract = POST_A_HISTORY_CONTRACT
    assert type(contract) is dict and set(contract) == {'header','files_key','retained_public_files'}, 'WAIT_ACTUAL_POST_A_FULL_HEADER_AND_FILES_KEY_SOURCE_REVIEW'
    assert type(contract['header']) is dict and type(contract['files_key']) is str and contract['files_key'] not in contract['header']
    qualified = A_QUALIFICATION['post_a_history']; path = ordinary(Path(args.public_history_inventory))
    assert path.as_posix() == qualified['path'] and args.public_history_inventory_sha256 == qualified['sha256']
    raw = file_pin(path,qualified); value = json.loads(raw); key = contract['files_key']
    assert set(value) == set(contract['header']) | {key}
    assert {name:value[name] for name in contract['header']} == contract['header']
    rows = value[key]; assert type(rows) is list and [r['path'] for r in rows] == sorted({r['path'] for r in rows})
    assert not path.is_relative_to(OLD_ROOT) and not path.is_relative_to(RUNTIME)
    prior,extras = pre_a_history(); expected = dict(prior)
    for row in extras:
        assert row['path'] not in expected or expected[row['path']] == row; expected[row['path']] = row
    for name in ['DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json']:
        p = NORMAL_SOURCE / name; data = ordinary(p).read_bytes(); expected[p.as_posix()] = {'path':p.as_posix(),'bytes':len(data),'sha256':sha(data)}
    for root in [OLD_ROOT / 'evidence',OLD_ARCHIVE,NORMAL_SOURCE / 'actual-profile',
            NORMAL_SOURCE / 'materialize-freeze-assets-r1',NORMAL_SOURCE / 'native-normal-start-assets-r1']:
        for name,row in tree(root)['files'].items():
            p = root / name; descriptor = {'path':p.as_posix(),**row}
            assert p.as_posix() not in expected or expected[p.as_posix()] == descriptor; expected[p.as_posix()] = descriptor
    for name in PUBLIC_ROOT_FILES + ['proof-php-ini/99-ms4-proof-memory.ini']:
        p = OLD_ROOT / name; data = ordinary(p).read_bytes(); expected[p.as_posix()] = {'path':p.as_posix(),'bytes':len(data),'sha256':sha(data)}
    # R40 did not consume the historical R33 inventory. Require its retained baseline independently.
    # The separately pinned R39 ordinary retirement and pre-frozen R40 materializer remain public history.
    for root in [NORMAL_SOURCE.parent / 'materializer',PREPARED / 'r40-normal-materialize-source-r1',
            PREPARED / 'r39-ar-failed-normal-close-r1/close-output',
            PREPARED / 'r39-ar-failed-normal-close-r1/independent-zero-output']:
        for name,row in tree(root)['files'].items():
            p = root / name; descriptor = {'path':p.as_posix(),**row}
            assert p.as_posix() not in expected or expected[p.as_posix()] == descriptor; expected[p.as_posix()] = descriptor
    retained = contract['retained_public_files']
    assert type(retained) is list and retained and [r['path'] for r in retained] == sorted({r['path'] for r in retained})
    for descriptor in retained:
        assert set(descriptor) == {'path','bytes','sha256'} and Path(descriptor['path']).is_absolute()
        file_pin(Path(descriptor['path']),descriptor)
        assert descriptor['path'] not in expected or expected[descriptor['path']] == descriptor
        expected[descriptor['path']] = descriptor
    assert rows == sorted(expected.values(),key=lambda r:r['path'])
    for row in rows:
        assert set(row) == {'path','bytes','sha256'} and Path(row['path']).is_absolute()
        file_pin(Path(row['path']),row)
    for p,data in [(OLD_RESULT,final_raw),(OLD_ROOT / 'evidence/ms4-worker-normal-close-result.json',close_raw),
            (OLD_ROOT / 'evidence/ms4-worker-normal-independent-zero.json',zero_raw)]:
        assert expected[p.as_posix()] == {'path':p.as_posix(),'bytes':len(data),'sha256':sha(data)}
    return rows + [{'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}],{
        'normal_root_result':A_QUALIFICATION['normal_root_result'],
        'history_inventory':{'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}}


def prior_native_history(context, close_raw, zero_raw):
    """Read original coordinator-produced same-child receipts; never invoke an old CLI."""
    archives = [NORMAL_SOURCE / 'native-normal-start-assets-r1', OLD_ARCHIVE]
    for driver in [archives[0] / 'root-driver-source.py', archives[1] / 'root-driver-source.py']:
        raw = ordinary(driver).read_bytes()
        assert sha(raw) == OLD_COORDINATOR_SHA
        OLD_NATIVE_PINS.append({'path':driver.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    actual_context = json.loads(ordinary(archives[0] / 'ACTUAL-CONTEXT.json').read_bytes())
    assert actual_context == {'context':(OLD_ROOT / 'actual-run-context.json').as_posix(),
        'run_id':OLD_RUN,'source_sha':PREVIOUS,'freeze_sha256':OLD_FREEZE}
    commands = []
    for archive in archives:
        ordinary(archive, True)
        created_paths = sorted(archive.glob('*.created.json'))
        terminal_stems = {x.name[:-len('.terminal.json')] for x in archive.glob('*.terminal.json')}
        created_stems = {x.name[:-len('.created.json')] for x in created_paths}
        assert created_stems == terminal_stems and created_paths
        for path in created_paths:
            stem = path.name[:-len('.created.json')]
            match = re.fullmatch(r'([0-9]{3,})-(.+)', stem)
            assert match is not None
            current = {}
            for suffix in ['created.json','terminal.json','stdout','stderr']:
                item = archive / (stem + '.' + suffix)
                raw = ordinary(item).read_bytes(); current[suffix] = raw
                OLD_NATIVE_PINS.append({'path':item.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
            created = json.loads(current['created.json']); terminal = json.loads(current['terminal.json'])
            assert set(created) == {'argv','pid'}
            assert set(terminal) == {'pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'}
            assert type(created['pid']) is int and created['pid'] > 0 and type(terminal['pid']) is int and terminal['pid'] == created['pid']
            assert type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
            assert terminal['native_exit'] == terminal['wait_returncode'] == 0 and current['stderr'] == b''
            for suffix in ['stdout','stderr']:
                raw = current[suffix]
                assert type(terminal[suffix + '_bytes']) is int and terminal[suffix + '_bytes'] == len(raw)
                assert terminal[suffix + '_sha256'] == sha(raw)
            argv = created['argv']
            assert type(argv) is list and len(argv) >= 6 and all(type(x) is str for x in argv)
            assert Path(argv[0]).is_absolute() and argv[1:4] == ['-B','-X','utf8']
            script = Path(argv[4]); arguments = argv[5:]
            assert script.parent == OLD_PROFILE and script.name in OLD_CLI_PINS
            assert sha(ordinary(script).read_bytes()) == OLD_CLI_PINS[script.name]
            if script.name == 'ms4-host.py':
                assert script == OLD_PROFILE / 'ms4-host.py' and arguments[0] != '--help'
                if arguments[0] == 'prepare':
                    assert len(arguments) == 5 and arguments[1] == '--binding' and arguments[3] == '--run-root'
                    assert Path(arguments[2]) == NORMAL_SOURCE / 'materialize-freeze-assets-r1/freeze-binding.json'
                    assert Path(arguments[4]) == OLD_ROOT.parent
                else:
                    assert len(arguments) in [3,7] and arguments[1] == '--context'
                    assert Path(arguments[2]) == OLD_ROOT / 'actual-run-context.json'
                    if len(arguments) == 7:
                        assert arguments[0] in ['observe','finish-helper'] and arguments[3] == '--proof' and arguments[5:] == ['--seconds','30']
                value = json.loads(current['stdout']); assert type(value) is dict
            elif script.name == 'normal-close-ms4-fulfillment.py':
                assert arguments[0] == '--context' and Path(arguments[1]) == OLD_ROOT / 'actual-run-context.json'
                assert arguments[2:] == ['--worker-normal']
                value = json.loads(close_raw)
            else:
                assert arguments[0] == '--context' and Path(arguments[1]) == OLD_ROOT / 'actual-run-context.json'
                assert arguments[2:] == ['--close-result-sha256',sha(close_raw),'--worker-normal']
                assert json.loads(current['stdout']) == json.loads(zero_raw)
                value = json.loads(zero_raw)
            commands.append({'sequence':int(match[1]),'label':match[2],'argv':argv,'native':terminal,'value':value})
    commands.sort(key=lambda x:x['sequence'])
    assert [x['sequence'] for x in commands] == list(range(1,len(commands)+1))
    assert commands[0]['label'] == 'prepare' and commands[0]['argv'][5] == 'prepare'
    assert commands[-2]['label'] == 'worker-normal-close' and commands[-1]['label'] == 'worker-independent-zero'
    assert Path(commands[-2]['argv'][4]).name == 'normal-close-ms4-fulfillment.py'
    assert Path(commands[-1]['argv'][4]).name == 'verify-zero-ms4-fulfillment.py'
    assert sum(Path(x['argv'][4]).name == 'normal-close-ms4-fulfillment.py' for x in commands) == 1
    assert sum(Path(x['argv'][4]).name == 'verify-zero-ms4-fulfillment.py' for x in commands) == 1
    return commands


def original_retirement(args):
    inputs = source_inputs()
    consume_materialization(inputs)

    final_raw = file_pin(OLD_RESULT,A_QUALIFICATION['normal_root_result'])
    assert sha(final_raw) == args.normal_root_result_sha256 == A_QUALIFICATION['normal_root_result']['sha256']
    result = json.loads(final_raw)
    assert result == {'status':'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
        'run_id':OLD_RUN,'source_sha':PREVIOUS,'context':(OLD_ROOT / 'actual-run-context.json').as_posix(),
        'worker_scope':'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
        'full_acceptance':'INCOMPLETE','DOM_nonempty_history_due_finance_release':'NOT RUN'}
    context_raw = file_pin(OLD_ROOT / 'actual-run-context.json',OLD_CONTEXT_PIN); context = json.loads(context_raw)
    assert context['run_id'] == OLD_RUN and context['source_sha'] == PREVIOUS and context['freeze_sha256'] == OLD_FREEZE
    assert context['repo_dir'] == REPO.as_posix() and context['prep_dir'] == OLD_PROFILE.as_posix()
    assert context['run_dir'] == OLD_ROOT.as_posix() and context['project'] == 'tianli-audit-' + OLD_RUN
    binding_raw = ordinary(OLD_ROOT / 'preparation-source-binding.json').read_bytes(); old_binding = json.loads(binding_raw)
    assert len(binding_raw) == OLD_FREEZE_BYTES and sha(binding_raw) == OLD_FREEZE and old_binding['source_sha'] == PREVIOUS
    assert old_binding['requested_repo_source'] == REPO.as_posix()
    setup_path = NORMAL_SOURCE / 'materialize-freeze-assets-r1/RESULT.json'
    setup_raw = file_pin(setup_path,NORMAL_SETUP_PIN); setup = json.loads(setup_raw)
    assert setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
    assert setup['source_sha'] == PREVIOUS and setup['repo'] == REPO.as_posix() and setup['profile'] == OLD_PROFILE.as_posix()
    old_freeze_path = NORMAL_SOURCE / 'materialize-freeze-assets-r1/freeze-binding.json'
    assert Path(setup['freeze']) == old_freeze_path and setup['freeze_sha256'] == OLD_FREEZE
    assert ordinary(old_freeze_path).read_bytes() == binding_raw
    for path, raw in [(setup_path,setup_raw),(old_freeze_path,binding_raw)]:
        OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    assert [row['path'] for row in old_binding['artifact_files']] == OLD_ARTIFACTS
    for row in old_binding['artifact_files']:
        file_pin(OLD_PROFILE / row['path'], row)
        OLD_PROFILE_PINS.append({'path':(OLD_PROFILE / row['path']).as_posix(),'bytes':row['bytes'],'sha256':row['sha256']})
    assert tree(OLD_PROFILE) == {'files':dict(sorted((x['path'],{'bytes':x['bytes'],'sha256':x['sha256']}) for x in old_binding['artifact_files'])), 'directories':[]}
    for name, digest in OLD_CLI_PINS.items(): assert sha(ordinary(OLD_PROFILE / name).read_bytes()) == digest
    evidence = OLD_ROOT / 'evidence'
    close_path = evidence / 'ms4-worker-normal-close-result.json'; close_raw = file_pin(close_path,A_QUALIFICATION['worker_close']); closed = json.loads(close_raw)
    zero_path = evidence / 'ms4-worker-normal-independent-zero.json'; zero_raw = file_pin(zero_path,A_QUALIFICATION['independent_zero']); zero = json.loads(zero_raw)
    assert closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL' and closed['scope'] == 'MS4_WORKER_NORMAL_CLOSE_ONLY'
    assert zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO' and zero['scope'] == 'MS4_WORKER_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY'
    for value in [closed,zero]:
        assert value['run_id'] == OLD_RUN and value['source_sha'] == PREVIOUS and value['freeze_sha256'] == OLD_FREEZE
        assert value['context_sha256'] == sha(context_raw) and type(value['native_exit']) is int and value['native_exit'] == 0
        assert value['full_acceptance'] == 'INCOMPLETE' and value['stopped_samples'] == 'NOT RUN'
    assert closed['close_source_sha256'] == zero['close_source_sha256'] == OLD_CLI_PINS['normal-close-ms4-fulfillment.py']
    assert closed['reviewed_host_sha256'] == zero['host_source_sha256'] == OLD_CLI_PINS['ms4-host.py']
    assert zero['zero_source_sha256'] == OLD_CLI_PINS['verify-zero-ms4-fulfillment.py'] and zero['close_result_sha256'] == sha(close_raw)
    assert closed['exact_nonforce_removed'] == {'containers':4,'networks':1,'volumes':8} and closed['ordinary_stop_and_exact_nonforce_remove'] is True
    assert set(closed['final_inventory']) == {'container','network','volume'}
    assert all(set(labels) == {'project','run'} and all(x == [] for x in labels.values()) for labels in closed['final_inventory'].values())
    assert [(q['kind'],q['label']) for q in zero['queries']] == [(kind,label) for kind in ['container','network','volume'] for label in ['project','run']]
    assert all(type(q['native_exit']) is int and q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0
        and q['stdout_sha256'] == q['stderr_sha256'] == sha(b'') for q in zero['queries'])
    commands = prior_native_history(context, close_raw, zero_raw)
    assert set(context['initial_service_ids']) == {'audit-mysql','audit-redis'} and len(set(context['initial_service_ids'].values())) == 2
    targets = set(context['initial_service_ids'].values())
    for path in sorted(evidence.glob('*-created.json')):
        value = json.loads(ordinary(path).read_bytes())
        if 'id' in value:
            assert value['run_id'] == OLD_RUN and value['source_sha'] == PREVIOUS and re.fullmatch('[a-f0-9]{64}',value['id'])
            targets.add(value['id'])
    assert all(re.fullmatch('[a-f0-9]{64}',cid) for cid in targets)
    volumes = {x['name'] for x in context['initial_volumes']}
    assert len(context['initial_volumes']) == len(volumes) == 8
    assert volumes == {context['project'] + '_' + suffix for suffix in ['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets','aftersale-evidence','customer-form-evidence']}
    assert context['initial_network']['Internal'] is True and context['network_id'] == context['initial_network']['Id']
    assert re.fullmatch('[a-f0-9]{64}',context['network_id'])
    protocol = protocol_retirement(); targets |= set(protocol['containers'])
    # These fresh observations happen only after the original normal/close/zero native-zero proof.
    for kind, argv in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for label, value in [('project','com.docker.compose.project=' + context['project']),('run','tianli.audit.run=' + OLD_RUN)]:
            assert native('old-zero-' + kind + '-' + label,['docker',*argv,'--filter','label=' + value]) == b''
    ids = native('old-global-cids',['docker','ps','-aq','--no-trunc']).decode().splitlines()
    assert len(ids) == len(set(ids)) and not set(ids).intersection(targets)
    assert all(re.fullmatch('[a-f0-9]{64}',cid) for cid in ids)
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    facts = native('old-global-facts',['docker','inspect','--format',template,*ids]).decode().splitlines() if ids else []
    observed = set()
    for line in facts:
        value = json.loads(line)
        assert set(value) == {'id','mounts','mode','networks'} and value['id'] in ids and value['id'] not in observed
        observed.add(value['id'])
        assert not {x.get('Name') for x in value['mounts'] if x.get('Type') == 'volume'}.intersection(volumes)
        for net_name, net_id in [(context['initial_network']['Name'],context['network_id']),(protocol['network']['Name'],protocol['network']['Id'])]:
            assert net_name not in value['networks'] and net_id not in value['networks']
            assert all(x.get('NetworkID') != net_id for x in value['networks'].values())
            assert value['mode'] not in {net_id,net_name}
        assert not (value['mode'].startswith('container:') and value['mode'][10:] in targets)
    final_ids = native('old-global-cids-final',['docker','ps','-aq','--no-trunc']).decode().splitlines()
    assert observed == set(ids) and len(final_ids) == len(set(final_ids)) and set(final_ids) == set(ids)

    preserved,prior = post_a_history(args,context,final_raw,close_raw,zero_raw)
    actual_context_path = NORMAL_SOURCE / 'native-normal-start-assets-r1/ACTUAL-CONTEXT.json'
    data = ordinary(actual_context_path).read_bytes()
    OLD_NATIVE_PINS.append({'path':actual_context_path.as_posix(),'bytes':len(data),'sha256':sha(data)})
    prior['native_commands'] = len(commands)
    return preserved,old_binding,prior


def frozen_sources(binding, old_binding):
    combined = {}
    normal_groups = ['driver','customer','paid','fulfillment','aftersale_review','worker']
    for prefix in normal_groups:
        key = prefix + '_source_files'
        assert binding[key] == [SOURCE_ROWS.get(x['path'],x) for x in old_binding[key]]
        if prefix != 'driver':
            for suffix in ['_source_roots','_source_extra']:
                assert binding[prefix + suffix] == old_binding[prefix + suffix]
    roots = old_binding['worker_source_roots']
    assert roots == ['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config']
    actual_root_files = {}
    for root in roots:
        for name, row in tree(REPO / root)['files'].items(): actual_root_files[root + '/' + name] = row
    assert binding['ui_prepare_source_roots'] == binding['ui_backend_source_roots'] == roots
    prep_extra = sorted(set(binding['aftersale_review_source_extra'] + UI_PREPARE_FOUR))
    backend_extra = sorted(set(prep_extra + UI_BACKEND_THREE))
    assert len(prep_extra) == 34 and len(backend_extra) == 37
    assert binding['ui_prepare_source_extra'] == prep_extra and binding['ui_backend_source_extra'] == backend_extra
    for prefix in SOURCE_GROUPS:
        rows = binding[prefix + '_source_files']
        assert type(rows) is list and [r['path'] for r in rows] == sorted(set(r['path'] for r in rows))
        if prefix != 'driver':
            assert binding[prefix + '_source_roots'] == roots
            assert [x['path'] for x in rows] == sorted(set(actual_root_files) | set(binding[prefix + '_source_extra']))
        for row in rows:
            assert type(row) is dict and set(row) == {'path','bytes','sha256'}
            assert not Path(row['path']).is_absolute() and '..' not in Path(row['path']).parts
            assert row['path'] not in combined or combined[row['path']] == row
            combined[row['path']] = row
    for row in combined.values(): file_pin(REPO / row['path'],row)
    for path, row in SOURCE_ROWS.items():
        assert combined[path] == row
        file_pin(MAIN / path,row); file_pin(REPO / path,row)
    assert binding['preparation_source_references'] == old_binding['preparation_source_references']
    for row in binding['preparation_source_references']: file_pin(REPO / row['path'],row)
    return combined


def frozen_materials(binding, port):
    assert binding['ui_copy_dist_sources'] == DIST_SOURCES
    assert binding['ui_web_port'] == port and binding['ui_web_origin'] == 'http://127.0.0.1:' + str(port)
    observation = binding['ui_web_initial_port_observation']
    assert set(observation) == {'native','stdout_base64'}
    raw = base64.b64decode(observation['stdout_base64'],validate=True); receipt = observation['native']
    assert set(receipt) == {'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'}
    assert receipt['label'] == 'ui-web-initial-port' and type(receipt['pid']) is int and receipt['pid'] > 0
    assert type(receipt['native_exit']) is int and type(receipt['wait_returncode']) is int and receipt['native_exit'] == receipt['wait_returncode'] == 0
    assert receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == sha(raw)
    assert receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == sha(b'') and json.loads(raw.decode('utf-8-sig')) == []
    port_script = "$ErrorActionPreference='Stop'; $rows=@(Get-NetTCPConnection -State Listen | Where-Object { $_.LocalPort -eq " + str(port) + " } | Select-Object LocalAddress,LocalPort,OwningProcess); ConvertTo-Json -InputObject $rows -Compress"
    assert receipt['argv'] == ['powershell.exe','-NoProfile','-NonInteractive','-Command',port_script]
    profile = json.loads(ordinary(PROFILE / 'manifests/ui-public-copy-profile.json').read_bytes())
    for name, source in DIST_SOURCES.items():
        current = tree(Path(source))
        projection = {'files':[{'path':n,**r} for n,r in current['files'].items()],'directories':current['directories']}
        assert projection == binding['ui_copy_dist_inventory'][name]
        assert json.loads(ordinary(PROFILE / ('manifests/ui-' + name + '-dist.json')).read_bytes()) == projection
        assert profile[name + '_dist_files'] == projection['files'] and profile[name + '_dist_directories'] == projection['directories']
    public = tree(REPO / 'crmeb/public')
    assert profile['public_source_files'] == [{'path':n,**r} for n,r in public['files'].items()]
    assert profile['public_source_directories'] == public['directories']


def frozen_resources(binding, args, final):
    # Existing Host CLI produces these groups at the one initial freeze.
    ini_root = ordinary(Path(args.proof_ini_source), True).resolve(strict=True)
    observer_root = ordinary(Path(args.task2_observer_source), True).resolve(strict=True)
    for root in [ini_root, observer_root]:
        assert not root.is_relative_to(REPO) and not root.is_relative_to(MAIN)
    ini_pin = {'bytes':18, 'sha256':'986c7e4b2587348a8d5ba59312a2106d38bbefec35acda4e7e5c14761cfe9ff1'}
    ini_leaf = '99-ms4-proof-memory.ini'
    reference = {'path':(PREPARED / 'r20-proof-memory-budget-public-readback-r1/RESULT.json').as_posix(),
        'bytes':60911, 'sha256':'e4ff0cd932806efb206b46dfdc5f5ef0230e0f2ad84679dbcb843adbdbec67f5'}
    expected_ini = {'schema':1, 'scope':'MS4_PROOF_PHP_INI_RESOURCE_SOURCE',
        'source_root':ini_root.as_posix(), 'files':[{'path':ini_leaf, **ini_pin}],
        'roles':['audit-ms5-paid-producer', 'audit-ms4-fulfillment-normal',
            'audit-ms4-aftersale-review-normal', 'audit-ms4-worker-normal',
            'audit-ms4-ui-prepare', 'audit-ms4-ui-backend'],
        'scan_dir':'/usr/local/etc/php/conf.d:/run/audit/proof-php-ini', 'config_reference':reference}
    assert json.dumps(binding['proof_php_ini'],sort_keys=True,separators=(',',':')) == json.dumps(expected_ini,sort_keys=True,separators=(',',':'))
    assert tree(ini_root) == {'files':{ini_leaf:ini_pin}, 'directories':[]}
    assert file_pin(ini_root / ini_leaf, ini_pin) == b'memory_limit=512M\n'
    file_pin(Path(reference['path']), reference)
    tool_names = ['entry.php', 'expected.php', 'gate.php']
    authority_root = ordinary(TOOL_SOURCE,True)
    authority = final['task2_observer_tools']
    assert type(authority) is list and len(authority) == 3
    authority_files = {}
    for name, row in zip(tool_names,authority):
        assert set(row) == {'path','source','bytes','sha256'} and row['path'] == name
        assert row['source'] == (authority_root / name).as_posix()
        file_pin(authority_root / name,row)
        authority_files[name] = {key:row[key] for key in ['bytes','sha256']}
    authority_tree = {'files':dict(sorted(authority_files.items())),'directories':[]}
    assert tree(authority_root) == authority_tree
    tools = tree(observer_root)
    assert tools == authority_tree
    expected_observer = {'schema':1, 'scope':'MS5_TASK2_COMPOSITE_OBSERVER_SOURCE_ONLY',
        'core_source_sha':HEAD, 'host_source_root':observer_root.as_posix(),
        'container_root':'/run/audit/task2-observer',
        'members':[{'path':name, **tools['files'][name]} for name in tool_names]}
    assert json.dumps(binding['task2_observer_source'],sort_keys=True,separators=(',',':')) == json.dumps(expected_observer,sort_keys=True,separators=(',',':'))
    aux_names = ['reader/ms4-ui-private-reader.mjs', 'ms4-ui-private-caller.mjs',
        'ms4-ui-browser-close.mjs', 'ms4-ui-business-producer.mjs',
        'ms4-ui-window-native-bridge.mjs', 'ms4-ui-network-capture.mjs',
        'ms4-ui-dom-producer.mjs', 'ui-business-consumer-contract.json']
    aux = tree(AUXILIARY)
    assert AUXILIARY == PROFILE.parent / 'browser-v1' and AUXILIARY.resolve(strict=True) == AUXILIARY
    assert set(aux['files']) == set(aux_names) | {'PINS.json'} and aux['directories'] == ['reader']
    manifest = json.loads(file_pin(AUXILIARY / 'PINS.json', aux['files']['PINS.json']))
    assert set(manifest) == {'members'} and list(manifest['members']) == aux_names
    assert manifest['members'] == {name:aux['files'][name] for name in aux_names}
    current_authority = final['current_core_aux_authority']
    assert aux['files']['PINS.json'] == {key:current_authority['browser_manifest'][key] for key in ['bytes','sha256']}
    assert current_authority['core_sources'] == final['publication']['finite_contexts']['current_Core3']
    assert manifest['members'] == {row['path']:{key:row[key] for key in ['bytes','sha256']}
        for row in final['publication']['finite_contexts']['ordered_auxiliary_members']}
    expected_browser = {'schema':1, 'scope':'MS5_TASK2_BROWSER_SOURCE_ONLY',
        'members':[{'path':name, **aux['files'][name]} for name in aux_names]}
    assert json.dumps(binding['task2_browser_source'],sort_keys=True,separators=(',',':')) == json.dumps(expected_browser,sort_keys=True,separators=(',',':'))
    # Re-read every external original after the full group joins.
    assert tree(ini_root) == {'files':{ini_leaf:ini_pin}, 'directories':[]}
    assert tree(authority_root) == authority_tree and tree(observer_root) == tools and tree(AUXILIARY) == aux
    return {'proof_php_ini':expected_ini, 'task2_observer_source':expected_observer,
        'task2_browser_source':expected_browser}


def b_source_tree_binding(binding, source, repo, context=None):
    """Finite B join of the reviewed full tree to genuine freeze and native context."""
    q = B_SOURCE_TREE_QUALIFICATION
    members = q['source_tree_members']
    fields = ['schema','scope','source_sha','requested_repo_source','volume_suffix',
        'writer_destination','files','directories','file_count','directory_count','manifest_sha256']
    tree = binding['source_tree']
    assert type(tree) is dict and list(tree) == fields, 'B_EXACT_COMPLETE_TREE_FREEZE_SCHEMA'
    expected = {'schema':1,'scope':'COMPLETE_TRACKED_SOURCE_TREE','source_sha':source,
        'requested_repo_source':repo.as_posix(),'volume_suffix':'source-tree',
        'writer_destination':'/run/audit-source-tree','files':members['files'],
        'directories':members['directories'],'file_count':members['file_count'],
        'directory_count':members['directory_count'],'manifest_sha256':members['manifest_sha256']}
    assert tree == expected and binding['source_sha'] == source == q['source_sha'], 'B_FROZEN_FULL_TREE_EQUALS_FIXED_SOURCE_AUTHORITY'
    paths = []; parents = set()
    for row in tree['files']:
        assert type(row) is dict and list(row) == ['path','bytes','sha256'] and type(row['path']) is str
        assert row['path'] and not re.search(r'[\x00-\x1f\x7f\\:]',row['path'])
        assert all(part not in ['', '.', '..', '.git'] for part in row['path'].split('/'))
        assert row['path'] not in ['crmeb/.env','crmeb/public/install.lock']
        assert type(row['bytes']) is int and row['bytes'] >= 0 and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}',row['sha256'])
        paths.append(row['path']); parts = row['path'].split('/')
        parents.update('/'.join(parts[:count]) for count in range(1,len(parts)))
    assert paths == sorted(set(paths)) and tree['directories'] == sorted(parents), 'B_CLOSED_FULL_TREE_NAMESPACE'
    assert tree['manifest_sha256'] == sha(json.dumps({'files':tree['files'],'directories':tree['directories']},
        ensure_ascii=False,separators=(',',':')).encode('utf-8')), 'B_COMPLETE_TREE_CANONICAL_HASH'
    summary = {key:tree[key] for key in ['volume_suffix','writer_destination','file_count','directory_count','manifest_sha256']}
    if context is not None:
        expected_context = {'volume_name':context['project']+'_source-tree','manifest_sha256':tree['manifest_sha256'],
            'file_count':tree['file_count'],'directory_count':tree['directory_count']}
        assert context['source_sha'] == source and context['repo_dir'] == repo.as_posix()
        assert context['source_tree'] == expected_context, 'B_ACTUAL_CONTEXT_COMPLETE_TREE_JOIN'
        assert type(context['initial_volumes']) is list and len(context['initial_volumes']) == 9
        volumes = [row for row in context['initial_volumes'] if row['name'] == expected_context['volume_name']]
        assert len(volumes) == 1 and volumes[0]['created_at'] and volumes[0]['labels']['com.docker.compose.project'] == context['project']
        assert volumes[0]['labels']['tianli.audit.run'] == context['run_id'], 'B_ORIGINAL_NATIVE_TREE_VOLUME_GENERATION'
    return summary


def b_source_tree_qualification_guard():
    q = B_SOURCE_TREE_QUALIFICATION
    assert type(q) is dict and set(q) == {'schema','scope','source_sha','source_tree_members','source_authority','builds','runtime_root'}, 'B_SOURCE_TREE_QUALIFICATION_UNBOUND'
    assert q['schema'] == 1 and q['scope'] == 'B_UI_SOURCE_TREE_ACTUAL_QUALIFICATION', 'B_SOURCE_TREE_QUALIFICATION_SCOPE'
    assert type(q['source_sha']) is str and re.fullmatch('[a-f0-9]{40}',q['source_sha']), 'B_SOURCE_TREE_NEW_REAL_SOURCE_REQUIRED'
    assert q['runtime_root'] == RUNTIME.as_posix() and RUNTIME.parent == Path('H:/天俪商城/artifacts/project-organization-20261023'), 'B_SOURCE_TREE_NEW_EXCLUSIVE_OUTPUT_BOUNDARY'
    assert type(q['source_authority']) is dict and set(q['source_authority']) == {'path','bytes','sha256'} and q['source_authority'] == SOURCE_REVIEW_AUTHORITY, 'B_SOURCE_TREE_REAL_SOURCE_AUTHORITY_REQUIRED'
    assert type(q['builds']) is dict and set(q['builds']) == {'h5','merchant','admin'} and all(type(row) is dict for row in q['builds'].values()), 'B_SOURCE_TREE_REAL_THREE_BUILDS_REQUIRED'
    t = q['source_tree_members']
    assert type(t) is dict and set(t) == {'files','directories','file_count','directory_count','manifest_sha256'}, 'B_SOURCE_TREE_REVIEWED_COMPLETE_MEMBERS_REQUIRED'
    assert type(t['file_count']) is int and t['file_count'] > 0 and type(t['files']) is list and len(t['files']) == t['file_count'], 'B_SOURCE_TREE_REVIEWED_REAL_COUNT_REQUIRED'
    assert type(t['directories']) is list and len(t['directories']) == t['directory_count'], 'B_SOURCE_TREE_REVIEWED_REAL_DIRECTORIES_REQUIRED'
    assert t['manifest_sha256'] == sha(json.dumps({'files':t['files'],'directories':t['directories']},ensure_ascii=False,separators=(',',':')).encode('utf-8')), 'B_SOURCE_TREE_REVIEWED_COMPLETE_MANIFEST_HASH'
    assert type(HEAD) is str and HEAD == PREVIOUS == q['source_sha'] and re.fullmatch('[a-f0-9]{40}',HEAD), 'B_NEW_CLEAN_HEAD_NOT_BOUND'
    assert type(CURRENT_M_SOURCE_CONTRACT) is dict and CURRENT_M_SOURCE_CONTRACT['source_sha'] == HEAD, 'B_NEW_FIXED_M_SOURCE_CONTRACT_UNBOUND'
    assert type(SOURCE_ROWS) is dict and list(SOURCE_ROWS.values()) == CURRENT_M_SOURCE_CONTRACT['SourceRows'], 'B_NEW_ROLE_SOURCE_ROWS_UNBOUND'
    assert type(NORMAL_SOURCE_PINS) is dict and type(OLD_CLI_PINS) is dict and type(COMMITTED_FILES) is list, 'B_NEW_M_A_FIXED_SOURCE_PINS_UNBOUND'
    assert type(COMMITTED_PATHS) is set and COMMITTED_PATHS and sorted(COMMITTED_PATHS) == CURRENT_M_SOURCE_CONTRACT['latest_commit_paths'], 'B_FIXED_ACTUAL_LATEST_COMMIT_PATHS_UNBOUND'


def main():
    b_source_tree_qualification_guard()
    assert type(A_QUALIFICATION) is dict and set(A_QUALIFICATION) == {'outer_native_exit','normal_root_result','worker_close','independent_zero','post_a_history'}, 'INCOMPLETE_R40_A_QUALIFICATION_REQUIRED'
    assert type(A_QUALIFICATION['outer_native_exit']) is int and A_QUALIFICATION['outer_native_exit'] == 0
    assert type(A_OUTER_SESSION) is int and A_OUTER_SESSION > 0, 'WAIT_REAL_SUCCESSFUL_A_OUTER_SESSION'
    assert type(SOURCE_REVIEW_AUTHORITY) is dict and set(SOURCE_REVIEW_AUTHORITY) == {'path','bytes','sha256'}, 'WAIT_ACTUAL_CURRENT_SOURCE_REVIEW_AUTHORITY'
    assert type(POST_A_HISTORY_CONTRACT) is dict, 'WAIT_ACTUAL_POST_A_SCHEMA_SOURCE_CONTRACT'
    assert all(type(A_QUALIFICATION[key]) is dict for key in ['normal_root_result','worker_close','independent_zero','post_a_history'])
    assert type(OLD_CONTEXT_PIN) is dict and set(OLD_CONTEXT_PIN) == {'bytes','sha256'}, 'WAIT_R40_ACTUAL_CONTEXT_PUBLIC_DESCRIPTOR'
    parser = argparse.ArgumentParser(description='INCOMPLETE_NOT_EXECUTABLE: Root must first bind actual current R40 A outer0 and full normal/close/zero/post-A history; identity and internal receipts do not prove that terminal.')
    parser.add_argument('--root-source-pins-sha256',required=True)
    parser.add_argument('--normal-root-result-sha256',required=True)
    parser.add_argument('--public-history-inventory',required=True)
    parser.add_argument('--public-history-inventory-sha256',required=True)
    parser.add_argument('--ui-web-port',type=int,required=True)
    parser.add_argument('--proof-ini-source',required=True)
    parser.add_argument('--task2-observer-source',required=True)
    args = parser.parse_args()
    root_source_pins = self_source_pins(args.root_source_pins_sha256)
    draft_inputs = source_inputs()
    assert draft_inputs['sealed'] is True, 'UNSEALED_FIRST_B_INPUTS'
    file_pin(Path(SOURCE_REVIEW_AUTHORITY['path']),SOURCE_REVIEW_AUTHORITY)
    assert re.fullmatch('[a-f0-9]{64}',args.normal_root_result_sha256)
    assert re.fullmatch('[a-f0-9]{64}',args.public_history_inventory_sha256)
    assert type(args.ui_web_port) is int and 1024 <= args.ui_web_port <= 65535
    ordinary(PREPARED,True)
    assert not RUNTIME.exists() and not RUNTIME.is_symlink()
    source_before, final = verify_sources()
    RUNTIME.mkdir(); OUT.mkdir()
    save(OUT / 'root-driver-source.py',ordinary(Path(__file__)).read_bytes())
    preserved, old_binding, prior = original_retirement(args)
    main_git = ['git','-c','safe.directory=' + MAIN.as_posix(),'-C',str(MAIN)]
    git = ['git','-c','safe.directory=' + REPO.as_posix(),'-C',str(REPO)]
    dirty_before = main_state('main-before',main_git)
    for path, row in SOURCE_ROWS.items(): file_pin(MAIN / path,row)
    assert native('validation-head-before',[*git,'rev-parse','HEAD']).decode().strip() == PREVIOUS
    assert native('validation-clean-before',[*git,'status','--porcelain=v1','--untracked-files=all']) == b''
    assert native('validation-detached-before',[*git,'branch','--show-current']) == b''
    changed = native('committed-successor-paths',[*git,'diff','--name-only',PREVIOUS,HEAD]).decode().splitlines()
    assert changed == [] and HEAD == PREVIOUS
    committed = native('actual-parent-commit-paths',[*git,'diff','--name-only',COMMIT_PARENT,HEAD]).decode().splitlines()
    assert set(committed) == COMMITTED_PATHS and len(committed) == len(COMMITTED_PATHS)
    assert native('actual-committed-parent',[*git,'rev-parse',HEAD + '^']).decode().strip() == COMMIT_PARENT
    for path, row in SOURCE_ROWS.items():
        raw = native('committed-source-' + Path(path).name,[*git,'show',HEAD + ':' + path])
        assert len(raw) == row['bytes'] and sha(raw) == row['sha256']
    profile_rows = verify_profile(final,materialize=True)
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        native('profile-help-' + name,[sys.executable,'-B','-X','utf8',str(PROFILE / name),'--help'])
    assert verify_sources() == (source_before,final)
    for row in preserved + PROTOCOL_PINS + OLD_PROFILE_PINS + OLD_NATIVE_PINS: file_pin(Path(row['path']),row)
    native('advance-detached-validation-after-normal-zero',[*git,'switch','--detach',HEAD],True)
    assert native('validation-head-after',[*git,'rev-parse','HEAD']).decode().strip() == HEAD
    assert native('validation-clean-after',[*git,'status','--porcelain=v1','--untracked-files=all']) == b''
    assert native('validation-detached-after',[*git,'branch','--show-current']) == b''
    for path, row in SOURCE_ROWS.items(): file_pin(REPO / path,row)
    artifacts = [{'path':name,**profile_rows[name]} for name in ARTIFACTS]
    freeze = OUT / 'freeze-binding.json'
    frozen = json.loads(native('freeze-ui-web',[sys.executable,'-B','-X','utf8',str(PROFILE / 'ms4-host.py'),
        'freeze-ui-web','--repo',str(REPO),'--source',HEAD,'--output',str(freeze),
        '--ui-h5-dist',DIST_SOURCES['h5'],'--ui-merchant-dist',DIST_SOURCES['merchant'],
        '--ui-admin-dist',DIST_SOURCES['admin'],'--ui-web-port',str(args.ui_web_port),
        '--proof-ini-source',args.proof_ini_source,'--task2-observer-source',args.task2_observer_source]))
    raw = ordinary(freeze).read_bytes(); binding = json.loads(raw)
    assert frozen == {'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':HEAD,'freeze_sha256':sha(raw),'runtime_SQL_App':'NOT RUN'}
    assert binding['source_sha'] == HEAD and binding['requested_repo_source'] == REPO.as_posix() and binding['artifact_files'] == artifacts
    source_tree = b_source_tree_binding(binding,HEAD,REPO)
    combined = frozen_sources(binding,old_binding)
    frozen_materials(binding,args.ui_web_port)
    resources = frozen_resources(binding,args,final)
    save(OUT / 'actual-frozen-source-groups.json',(json.dumps({prefix:{
        'files':binding[prefix + '_source_files'],
        **({'roots':binding[prefix + '_source_roots'],'extra':binding[prefix + '_source_extra']} if prefix != 'driver' else {})
        } for prefix in SOURCE_GROUPS},ensure_ascii=False,indent=2) + '\n').encode('utf-8'))
    save(OUT / 'actual-profile-artifacts.json',(json.dumps(artifacts,ensure_ascii=False,indent=2) + '\n').encode('utf-8'))
    save(OUT / 'actual-browser-auxiliary.json',(json.dumps(tree(AUXILIARY),ensure_ascii=False,indent=2) + '\n').encode('utf-8'))
    assert main_state('main-final',main_git) == dirty_before
    for path, row in SOURCE_ROWS.items(): file_pin(MAIN / path,row)
    assert native('validation-clean-final',[*git,'status','--porcelain=v1','--untracked-files=all']) == b''
    assert verify_sources() == (source_before,final)
    assert verify_profile(final) == profile_rows
    assert frozen_resources(binding,args,final) == resources
    for row in preserved + PROTOCOL_PINS + OLD_PROFILE_PINS + OLD_NATIVE_PINS: file_pin(Path(row['path']),row)
    result = {'root_source_pins':root_source_pins,'root_inputs':{'path':(ROOT_SOURCE / 'INPUTS.json').as_posix(),**ROOT_INPUTS_PIN},
        'source_review_authority':SOURCE_REVIEW_AUTHORITY,
        'status':'ACTUAL_CLEAN_SOURCE_AND_SEVENTEEN_FILE_UI_PROFILE_FROZEN_ONLY',
        'source_sha':HEAD,'repo':REPO.as_posix(),'profile':PROFILE.as_posix(),
        'browser_auxiliary':AUXILIARY.as_posix(),'browser_auxiliary_members':8,
        'browser_auxiliary_manifest':tree(AUXILIARY)['files']['PINS.json'],
        'profile_mode':'REVIEWED_SOURCE_COPIED_TO_FRESH_SEPARATE_ACTUAL_UI_PROFILE',
        'freeze':freeze.as_posix(),'freeze_bytes':len(raw),'freeze_sha256':sha(raw),
        'source_tree':source_tree,
        'initial_resources':resources,
        'ui_web_port':args.ui_web_port,'ui_web_origin':binding['ui_web_origin'],
        'source_counts':{prefix:len(binding[prefix + '_source_files']) for prefix in SOURCE_GROUPS},
        'artifact_count':17,'artifacts':artifacts,'unique_source_files_actual_read':len(combined),
        'prior_normal_root_result':prior['normal_root_result'],'prior_public_history_inventory':prior['history_inventory'],
        'prior_native_commands_verified':prior['native_commands'],'prior_public_unchanged':len(preserved)-1,
        'outer_coordinator_precondition':{'original_session':A_OUTER_SESSION,
            'native_exit_zero':'MUST_HAVE_BEEN_VERIFIED_BY_ROOT_BEFORE_INVOCATION',
            'materializer_observation':'NOT_OBSERVED_OR_INFERRED_FROM_INTERNAL_CHILD_RECEIPTS'},
        'prior_protocol_files_unchanged':len(PROTOCOL_PINS),'prior_old_profile_files_unchanged':len(OLD_PROFILE_PINS),
        'Main_clean_commit_preserved':True,'main_index':'EMPTY','Run_created':False,
        'App_DB_HTTP_business_GUI':'NOT RUN','full_acceptance':'INCOMPLETE'}
    save(OUT / 'RESULT.json',(json.dumps(result,ensure_ascii=False,indent=2) + '\n').encode('utf-8'))
    print(json.dumps(result,ensure_ascii=False),flush=True)




CURRENT_M_SOURCE_CONTRACT = None
TASK2_BROWSER_NAMES_CURRENT = ['reader/ms4-ui-private-reader.mjs', 'ms4-ui-private-caller.mjs', 'ms4-ui-browser-close.mjs', 'ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs', 'ms4-ui-network-capture.mjs', 'ms4-ui-dom-producer.mjs', 'ui-business-consumer-contract.json']

# Embedded Source fragment only. The old R40 adapters remain complete historical
# definitions in the derived Root M. No function in this fragment is executed by
# the Source author. Missing future receipts are rejected before any mutation.
def current_known_normal_root(inputs):
    authority = inputs['normal_root']
    contract = CURRENT_M_SOURCE_CONTRACT
    assert type(contract) is dict and contract['source_sha'] == HEAD, 'NEW_FIXED_M_SOURCE_CONTRACT_REQUIRED'
    assert type(contract['final_eligible_A_Source']) is dict and contract['final_eligible_A_Source'] == contract['A_source_PINS'], 'WAIT_FINAL_A_SOURCE_AFTER_SOURCE_FINDINGS'
    assert contract['source_sha'] == HEAD and contract['parent'] == COMMIT_PARENT
    assert contract['status'] == 'SOURCE_CONTRACT_ACTUAL_CURRENT_M_BOUND_A_POSTA_PENDING'
    assert type(contract['complete_tracked_paths']) is list and len(contract['complete_tracked_paths']) == B_SOURCE_TREE_QUALIFICATION['source_tree_members']['file_count']
    assert contract['complete_tracked_paths'] == sorted(set(contract['complete_tracked_paths']))
    assert contract['complete_tracked_paths'] == [row['path'] for row in B_SOURCE_TREE_QUALIFICATION['source_tree_members']['files']], 'CURRENT_M_COMPLETE_TRACKED_NAMESPACE_EQUALS_REVIEWED_TREE'
    assert type(contract['M_result']) is dict and set(contract['M_result']) == {'path','bytes','sha256'}, 'WAIT_ACTUAL_SAME_HEAD_CURRENT_M_RESULT'
    assert type(contract['M_outcome']) is dict and set(contract['M_outcome']) == {'created','terminal','stdout','stderr','created_object','terminal_object'}, 'WAIT_ACTUAL_CURRENT_M_OUTER_NATIVE_ZERO'
    outcome = contract['M_outcome']
    created = json.loads(file_pin(Path(outcome['created']['path']),outcome['created']))
    terminal = json.loads(file_pin(Path(outcome['terminal']['path']),outcome['terminal']))
    assert created == outcome['created_object'] and terminal == outcome['terminal_object']
    assert set(created) == {'pid','argv','started_ns'}
    assert set(terminal) == {'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}
    assert type(created['pid']) is int and created['pid'] > 0 and terminal['pid'] == created['pid']
    assert created['argv'] == contract['M_launcher_argv'] and type(created['argv']) is list
    assert type(created['started_ns']) is int and type(terminal['finished_ns']) is int
    assert 0 < created['started_ns'] < terminal['finished_ns']
    assert type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
    assert terminal['native_exit'] == terminal['wait_returncode'] == 0
    for suffix in ['stdout','stderr']:
        descriptor = terminal[suffix]
        assert type(descriptor) is dict and set(descriptor) == {'path','bytes','sha256'}
        assert descriptor == outcome[suffix]
        assert descriptor['path'] == (NORMAL_SOURCE.parent/'launcher'/('materialize.'+suffix)).as_posix()
        assert type(descriptor['bytes']) is int and descriptor['bytes'] >= 0
        assert type(descriptor['sha256']) is str and re.fullmatch('[a-f0-9]{64}',descriptor['sha256'])
        raw = file_pin(Path(outcome[suffix]['path']),outcome[suffix])
        assert len(raw) == descriptor['bytes'] and sha(raw) == descriptor['sha256']
        if suffix == 'stderr': assert raw == b''
    root = ordinary(Path(authority['root']),True)
    assert root == NORMAL_SOURCE
    manifest = json.loads(file_pin(root/'PINS.json',authority['PINS']))
    assert manifest == {'schema':1,'scope':'FULL_A_ASSET_NORMAL_DRIVER_SOURCE_PINS','members':NORMAL_SOURCE_PINS}
    assert set(manifest['members']) == {'DESIGN-TASKS.md','run.py'}
    for name,row in manifest['members'].items(): file_pin(root/name,row)
    setup_path = root/'materialize-freeze-assets-r1/RESULT.json'
    assert contract['M_result']['path'] == setup_path.as_posix()
    setup = json.loads(file_pin(setup_path,contract['M_result']))
    assert set(setup) == set(contract['result_fields_exact']) and len(setup) == len(contract['result_fields_exact'])
    assert setup['schema'] == 1 and setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
    assert setup['source_sha'] == HEAD and setup['parent'] == COMMIT_PARENT
    assert setup['source_patch_base'] == contract['source_patch_base']
    assert setup['commit_chain'] == contract['commit_chain'] and len(setup['commit_chain']) == len(contract['commit_chain'])
    assert [r['path'] for r in setup['committed_files']] == contract['committed_paths']
    assert len(setup['committed_files']) == len(contract['committed_paths']) and setup['selected9'] == contract['selected9']
    assert setup['source_counts'] == contract['source_counts'] and setup['unique_source_files_actual_read'] == contract['unique_source_files_actual_read']
    assert setup['Run_created'] is False and setup['App_DB_HTTP_business'] == 'NOT RUN'
    assert setup['repo'] == REPO.as_posix() and setup['profile'] == OLD_PROFILE.as_posix()
    assert setup['root_source_pins'] == authority['PINS']
    assert setup['profile_publication'] == {'root':inputs['normal_profile_publication']['root'],
        'pins':inputs['normal_profile_publication']['PINS'],'publication':inputs['normal_profile_publication']['PUBLICATION']}
    # These remain four distinct actual historical failure retirements. None is
    # substituted for future successful current A close/zero.
    assert setup['prior_retirement'] == contract['prior_retirement_expected']
    assert setup['current_driver_failure_retirement'] == contract['driver_failure_retirement_expected']
    assert setup['current_early_failure_retirement'] == contract['early_failure_retirement_expected']
    assert json.loads(file_pin(Path(contract['early_failure_retirement_source']['path']),contract['early_failure_retirement_source'])) == contract['early_failure_retirement_expected']
    assert setup['current_customer_prepare_failure_retirement'] == contract['customer_prepare_failure_retirement_expected']
    assert json.loads(file_pin(Path(contract['customer_prepare_failure_retirement_source']['path']),contract['customer_prepare_failure_retirement_source'])) == contract['customer_prepare_failure_retirement_expected']
    assert setup['proof_resource_required'] is True and type(setup['proof_php_ini']) is dict and len(setup['proof_php_ini']) == 7
    assert authority['known_A_identity'] == {'scope':'IDENTITY_ONLY_NOT_SUCCESS','run_id':OLD_RUN,
        'source_sha':HEAD,'freeze_sha256':OLD_FREEZE,'freeze_bytes':OLD_FREEZE_BYTES,
        'context':{'path':(OLD_ROOT/'actual-run-context.json').as_posix(),**OLD_CONTEXT_PIN}}
    file_pin(OLD_ROOT/'actual-run-context.json',OLD_CONTEXT_PIN)
    return authority,setup


def current_tracked_source_trees(snapshot):
    """Join requirements to both full tracked trees, independently of role union."""
    members=B_SOURCE_TREE_QUALIFICATION['source_tree_members']; count=members['file_count']
    assert type(snapshot) is dict and len(snapshot) == 2*count
    trees = {'main':{},'validation':{}}
    for name,row in snapshot.items():
        assert type(row) is dict and set(row) == {'path','bytes','sha256','stat9'} and row['path'] == name
        assert type(row['bytes']) is int and row['bytes'] >= 0 and type(row['sha256']) is str
        assert re.fullmatch('[a-f0-9]{64}',row['sha256']) and len(row['stat9']) == 9 and all(type(v) is int for v in row['stat9'])
        path = Path(name); matches = [(label,base) for label,base in [('main',MAIN),('validation',REPO)] if path.is_relative_to(base)]
        assert len(matches) == 1 and path.is_absolute()
        label,base = matches[0]; relative = path.relative_to(base).as_posix()
        assert relative and not relative.startswith('/') and '\\' not in relative and ':' not in relative and '\0' not in relative
        assert all(part not in ['', '.', '..'] for part in relative.split('/')) and relative not in trees[label]
        trees[label][relative] = {'path':relative,'bytes':row['bytes'],'sha256':row['sha256']}
    expected = [row['path'] for row in B_SOURCE_TREE_QUALIFICATION['source_tree_members']['files']]
    assert type(expected) is list and len(expected) == count and expected == sorted(set(expected))
    assert all(len(rows) == count and sorted(rows) == expected for rows in trees.values())
    assert trees['main'] == trees['validation']
    return trees


def current_consume_materialization(inputs):
    authority,setup = current_known_normal_root(inputs)
    contract = CURRENT_M_SOURCE_CONTRACT
    assert type(contract) is dict and contract['source_sha'] == HEAD, 'NEW_FIXED_M_SOURCE_CONTRACT_REQUIRED'
    folder = NORMAL_SOURCE/'materialize-freeze-assets-r1'
    tool = NORMAL_SOURCE.parent/'materializer'
    assert {p.name for p in tool.iterdir()} == {'PLAN.md','prepare.py','INVERSE.json'}
    assert set(setup['materialize_source_inputs']) == {(tool/name).as_posix() for name in ['PLAN.md','prepare.py','INVERSE.json']}
    copied = file_pin(Path(setup['materialize_source']['path']),setup['materialize_source'])
    assert Path(setup['materialize_source']['path']) == folder/'root-materialize-source.py'
    assert copied == file_pin(Path(contract['M_Source']['path']),contract['M_Source'])
    for name,row in setup['materialize_source_inputs'].items():
        assert set(row) == {'path','bytes','sha256','stat9'} and row['path'] == name
        raw = file_pin(Path(name),row)
        assert len(row['stat9']) == 9 and all(type(v) is int for v in row['stat9'])
        facts = Path(name).lstat()
        assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']] == row['stat9']
        if Path(name).name == 'prepare.py': assert raw == copied
    text = copied.decode('utf-8')
    assert text.index("write(out/'root-materialize-source.py'") < text.index("phase='native_git_and_help'")
    rows = setup['native_operations']; commands = contract['native_commands']
    derived = 6+sum(2+2*len(step['files']) for step in contract['commit_chain'])+1+len(contract['committed_paths'])+2+3+1+4
    assert derived == len(rows) == len(commands) == contract['derived_native_count']
    seen = set()
    for index,(row,command) in enumerate(zip(rows,commands),1):
        assert set(row) == {'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}
        assert {'label':row['label'],'argv':row['argv']} == command
        assert row['stem'] == '%03d-'%index+row['label'] and row['label'] not in seen
        seen.add(row['label']); stem = folder/row['stem']
        assert json.loads(ordinary(stem.with_suffix('.created.json')).read_bytes()) == {'argv':row['argv'],'pid':row['pid']}
        assert json.loads(ordinary(stem.with_suffix('.terminal.json')).read_bytes()) == row
        assert type(row['pid']) is int and row['pid'] > 0
        assert type(row['native_exit']) is int and type(row['wait_returncode']) is int
        assert row['native_exit'] == row['wait_returncode'] == 0
        streams = {}
        for suffix in ['stdout','stderr']:
            path = stem.with_suffix('.'+suffix)
            streams[suffix] = file_pin(path,{'bytes':row[suffix+'_bytes'],'sha256':row[suffix+'_sha256']})
            OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(streams[suffix]),'sha256':sha(streams[suffix])})
        assert streams['stderr'] == b''
        label = row['label']; raw = streams['stdout']
        if label in {'main-head-before','validation-head-before','main-head-after','validation-head-after'}: assert raw == (HEAD+'\n').encode()
        elif label in {'main-parent','validation-parent'}: assert raw == (HEAD+' '+COMMIT_PARENT+'\n').encode()
        elif 'status' in label: assert raw == b''
        elif label.startswith('source-step-'):
            bits=label.split('-'); step=contract['commit_chain'][int(bits[2])-1]
            if bits[3]=='parent': assert raw == (step['source_sha']+' '+step['parent']+'\n').encode()
            elif bits[3]=='paths': assert sorted(raw.decode().splitlines()) == [r['path'] for r in step['files']]
            else:
                member=step['files'][int(bits[4])-1]
                if label.endswith('absent-before'): assert member['before'] is None and raw == b''
                else: assert {'bytes':len(raw),'sha256':sha(raw)} == member['after' if label.endswith('-after') else 'before']
        elif label=='cumulative-changed-paths': assert sorted(raw.decode().splitlines()) == contract['committed_paths']
        elif label.startswith('committed-blob-'):
            r=setup['committed_files'][int(label.rsplit('-',1)[1])-1]
            assert {'bytes':len(raw),'sha256':sha(raw)} == {k:r[k] for k in ['bytes','sha256']}
            file_pin(MAIN/r['path'],r);file_pin(REPO/r['path'],r)
        elif label.endswith('-complete-tracked-inventory'):
            names=raw.decode().split('\0');assert names[-1]=='' and len(names)-1==B_SOURCE_TREE_QUALIFICATION['source_tree_members']['file_count'] and sorted(names[:-1])==contract['complete_tracked_paths']
        elif label=='freeze': assert json.loads(raw)=={'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':HEAD,'freeze_sha256':setup['freeze_sha256'],'runtime_SQL_App':'NOT RUN'}
        else: assert label in {'help-ms4-host-py','help-normal-close-ms4-fulfillment-py','help-verify-zero-ms4-fulfillment-py'} and raw
        for suffix in ['created.json','terminal.json']:
            path=stem.with_suffix('.'+suffix);raw=ordinary(path).read_bytes();OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    snapshots={}
    for key,count in [('root_source_snapshot',14),('complete_source_snapshot',2*B_SOURCE_TREE_QUALIFICATION['source_tree_members']['file_count'])]:
        descriptor=setup[key];snapshot=json.loads(file_pin(Path(descriptor['path']),descriptor));assert len(snapshot)==count
        for name,row in snapshot.items():
            assert row['path']==name and set(row)=={'path','bytes','sha256','stat9'} and len(row['stat9'])==9 and all(type(x) is int for x in row['stat9'])
            file_pin(Path(name),row);facts=Path(name).lstat()
            assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]==row['stat9']
        snapshots[key]=snapshot
    expected_root14={(NORMAL_SOURCE/name).as_posix() for name in ['DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json']}
    expected_root14|={(OLD_PROFILE/r['path']).as_posix() for r in contract['selected9']}
    assert set(snapshots['root_source_snapshot'])==expected_root14 and len(expected_root14)==14
    authority_descriptor=contract['M_authority_input_snapshot']
    assert type(authority_descriptor) is dict and set(authority_descriptor)=={'path','bytes','sha256'}
    assert authority_descriptor['path']==(folder/'AUTHORITY-INPUT-SNAPSHOT.json').as_posix()
    authority_snapshot=json.loads(file_pin(Path(authority_descriptor['path']),authority_descriptor))
    assert type(authority_snapshot) is dict and authority_snapshot
    for name,row in authority_snapshot.items():
        assert row['path']==name and set(row)=={'path','bytes','sha256','stat9'}
        assert len(row['stat9'])==9 and all(type(x) is int for x in row['stat9'])
        file_pin(Path(name),row);facts=Path(name).lstat()
        assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]==row['stat9']
    for base in [MAIN,REPO]:
        actual=sorted(Path(name).relative_to(base).as_posix() for name in snapshots['complete_source_snapshot'] if Path(name).is_relative_to(base))
        assert actual==[row['path'] for row in B_SOURCE_TREE_QUALIFICATION['source_tree_members']['files']] and len(actual)==B_SOURCE_TREE_QUALIFICATION['source_tree_members']['file_count']
    tracked=current_tracked_source_trees(snapshots['complete_source_snapshot'])
    frozen_raw=file_pin(Path(setup['freeze']),{'bytes':setup['freeze_bytes'],'sha256':setup['freeze_sha256']});frozen=json.loads(frozen_raw)
    assert Path(setup['freeze'])==folder/'freeze-binding.json' and frozen['source_sha']==HEAD and frozen['requested_repo_source']==REPO.as_posix()
    assert frozen['artifact_files']==setup['selected9'] and frozen['proof_php_ini']==setup['proof_php_ini']
    combined={}
    for group,count in contract['source_counts'].items():
        rows=frozen[group+'_source_files'];assert len(rows)==len({r['path'] for r in rows})==count
        for r in rows:
            assert r['path'] not in combined or combined[r['path']]==r
            combined[r['path']]=r
            for base in [MAIN,REPO]:assert {k:snapshots['complete_source_snapshot'][(base/r['path']).as_posix()][k] for k in ['path','bytes','sha256']}=={'path':(base/r['path']).as_posix(),'bytes':r['bytes'],'sha256':r['sha256']}
    assert len(combined)==contract['unique_source_files_actual_read']
    assert type(contract['source_requirements']) is list and len(contract['source_requirements'])==len({r['path'] for r in contract['source_requirements']}) and contract['source_requirements']
    for r in contract['source_requirements']:
        assert set(r)=={'path','bytes','sha256'}
        assert tracked['main'][r['path']]==tracked['validation'][r['path']]==r
    for name in ['RESULT.json','root-materialize-source.py','freeze-binding.json','ROOT-SOURCE-SNAPSHOT.json','COMPLETE-SOURCE-SNAPSHOT.json','AUTHORITY-INPUT-SNAPSHOT.json']:
        path=folder/name;raw=ordinary(path).read_bytes();OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    # Final exact repeat after all cross-file/source/native joins.
    assert current_known_normal_root(inputs)[1]==setup
    return setup


def current_verify_sources():
    inputs=source_inputs(); _,setup=current_known_normal_root(inputs)
    normal_root,normal_manifest,normal_pub,normal_state=publication_source(inputs['normal_profile_publication'],
        'FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS','FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_ONLY',10,
        {'schema','scope','source_sha','parent','base_profile','selected9','repository_Source_requirements','finite_contexts','target_execution'})
    assert normal_root==OLD_PROFILE and setup['selected9']==normal_pub['selected9']
    assert normal_pub['repository_Source_requirements']==CURRENT_M_SOURCE_CONTRACT['source_requirements']
    ui_root,ui_manifest,pub,ui_state=publication_source(inputs['ui_profile_publication'],
        'INTEGRATED_B_UI_SOURCE_TREE_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL','INTEGRATED_B_UI_SOURCE_TREE_KNOWN_SOURCE_METADATA_ONLY',19,
        {'schema','scope','source_sha','parent','base_source','base_profile','selected17','repository_Source_requirements','finite_contexts','target_execution'})
    assert ui_root==PROFILE_SOURCE and [r['path'] for r in pub['selected17']]==ARTIFACTS
    assert pub['repository_Source_requirements']==normal_pub['repository_Source_requirements']
    finite=pub['finite_contexts']
    assert finite['repository_committed_files']==COMMITTED_FILES and {row['path'] for row in COMMITTED_FILES}==COMMITTED_PATHS and len(COMMITTED_FILES)==len(COMMITTED_PATHS)
    assert finite['normal9_authority']=={'root':inputs['normal_profile_publication']['root'],'profile_root':inputs['normal_profile_publication']['profile_root'],
        'PROFILE_PINS':inputs['normal_profile_publication']['PINS'],'PROFILE_PUBLICATION':inputs['normal_profile_publication']['PUBLICATION']}
    authority=json.loads(file_pin(Path(inputs['current_core_aux_authority']['path']),inputs['current_core_aux_authority']))
    assert set(authority)=={'schema','scope','source_sha','core_sources','browser_manifest','base_source'}
    assert authority['schema']==1 and authority['source_sha']==HEAD and authority['base_source']==pub['base_source']
    assert finite['current_core_aux_authority']==inputs['current_core_aux_authority'] and finite['current_Core3']==authority['core_sources']
    auxiliary=package(AUX_SOURCE,authority['browser_manifest'],8)
    manifest=json.loads(file_pin(AUX_SOURCE/'PINS.json',authority['browser_manifest']))
    assert list(manifest['members'])==TASK2_BROWSER_NAMES_CURRENT and len(manifest['members'])==8
    assert manifest['members']=={name:auxiliary['files'][name] for name in TASK2_BROWSER_NAMES_CURRENT}
    assert finite['ordered_auxiliary_members']==[{'path':name,**manifest['members'][name]} for name in TASK2_BROWSER_NAMES_CURRENT]
    assert json.loads(ordinary(AUX_SOURCE/'ui-business-consumer-contract.json').read_bytes())['php_sources']=={r['path']:{k:r[k] for k in ['bytes','sha256']} for r in authority['core_sources']}
    proof_names=ARTIFACTS+['browser-v1/'+name for name in TASK2_BROWSER_NAMES_CURRENT]+['browser-v1/PINS.json']
    proofs=finite['whole_forward_inverse_files'];assert [r['name'] for r in proofs]==proof_names and len(proofs)==26
    for proof in proofs:
        before=file_pin(Path(proof['before']['path']),proof['before']);after=file_pin(Path(proof['after']['path']),proof['after']);raw=before
        if proof['name'] in ARTIFACTS: assert Path(proof['after']['path'])==PROFILE_SOURCE/proof['name']
        else: assert Path(proof['after']['path'])==AUX_SOURCE/proof['name'][len('browser-v1/'):]
        for seam in proof['stages']:
            old,new=seam['before_utf8'].encode(),seam['after_utf8'].encode();offset=seam['stage_utf8_offset']
            assert {'bytes':len(raw),'sha256':sha(raw)}==seam['whole_before'] and raw[offset:offset+len(old)]==old
            raw=raw[:offset]+new+raw[offset+len(old):]
            assert {'bytes':len(raw),'sha256':sha(raw)}==seam['whole_after']
        assert raw==after
        for seam in reversed(proof['stages']):
            old,new=seam['before_utf8'].encode(),seam['after_utf8'].encode();offset=seam['stage_utf8_offset']
            assert raw[offset:offset+len(new)]==new;raw=raw[:offset]+old+raw[offset+len(new):]
        assert raw==before
        for chunk in proof['unchanged_chunks']:
            a=before[chunk['before_utf8_offset']:chunk['before_utf8_offset']+chunk['bytes']];b=after[chunk['after_utf8_offset']:chunk['after_utf8_offset']+chunk['bytes']]
            assert a==b and {'bytes':len(a),'sha256':sha(a)}=={k:chunk[k] for k in ['bytes','sha256']}
    assert json.loads(ordinary(PROFILE_SOURCE/'source-derivation.json').read_bytes())['current_nonself_artifacts']==[r for r in pub['selected17'] if r['path']!='source-derivation.json']
    joined={r['path']:r for r in pub['repository_Source_requirements']}
    for r in authority['core_sources']:
        assert r['path'] not in joined or joined[r['path']]==r
        joined[r['path']]=r
    assert joined and list(joined.values())==finite['SourceRows']==CURRENT_M_SOURCE_CONTRACT['SourceRows'] and joined==SOURCE_ROWS
    tracked=current_tracked_source_trees(json.loads(file_pin(Path(setup['complete_source_snapshot']['path']),setup['complete_source_snapshot'])))
    for path,row in joined.items():assert tracked['main'][path]==tracked['validation'][path]==row
    for path,row in joined.items():file_pin(MAIN/path,row);file_pin(REPO/path,row)
    base_root=Path(pub['base_source']['root']);base=package(base_root,pub['base_source']['PINS'],59)
    original_final=json.loads(file_pin(base_root/'FINAL-CANDIDATES.json',pub['base_source']['FINAL']))
    assert original_final['task2_observer_tools']==finite['unchanged_tools3']
    for row in finite['unchanged_tools3']:file_pin(Path(row['source']),row)
    assert tree(TOOL_SOURCE)=={'files':dict(sorted((r['path'],{k:r[k] for k in ['bytes','sha256']}) for r in finite['unchanged_tools3'])),'directories':[]}
    if SOURCE_REVIEW_AUTHORITY is not None:file_pin(Path(SOURCE_REVIEW_AUTHORITY['path']),SOURCE_REVIEW_AUTHORITY)
    final={'files':[{'artifact':r['path'],**r} for r in pub['selected17']],
        'task2_observer_tools':finite['unchanged_tools3'],'publication':pub,'current_core_aux_authority':authority}
    return {'normal_source_pins':inputs['normal_root']['PINS'],'normal_publication':normal_state,'ui_source':ui_state,
        'base_source':base,'auxiliary':auxiliary,'current_core_aux_authority':inputs['current_core_aux_authority'],
        'review_authority':SOURCE_REVIEW_AUTHORITY},final


def current_protocol_retirement():
    inputs = source_inputs(); history = inputs['history']; contract = history['protocol_retirement']
    assert type(contract) is dict and set(contract) == {'root','result'}, 'B_FIXED_PRIOR_PROTOCOL_SOURCE_REQUIRED'
    root = ordinary(Path(contract['root']),True)
    assert type(contract['result']) is dict and set(contract['result']) == {'path','bytes','sha256'}
    assert contract['result']['path'] == (root/'RESULT.json').as_posix()
    raw = file_pin(root / 'RESULT.json',contract['result'])
    result = json.loads(raw); expected_names = {'ACTUAL-OWNED.json','cache-wire-protocol.php','RESULT.json','root-driver-source.py'}
    assert len(result['operations']) == 45 and result['status'] == 'VERIFIED_ORIGINAL_CACHE_DRIVER_POSITIVE_PROTOCOL_AND_NORMAL_CLOSE'
    assert result['removed_containers'] == 2 and result['removed_internal_networks'] == 1
    assert result['named_volumes_created'] == 0 and result['six_fresh_raw_zero'] is True
    assert len(result['result']['cases']) == 8 and result['result']['final_key_count'] == 11 and result['result']['full_tag_count'] == 9
    for index,row in enumerate(result['operations'],1):
        stem = root / ('%03d-' % index + row['label'])
        assert json.loads(ordinary(stem.with_suffix('.created.json')).read_bytes()) == {'argv':row['argv'],'pid':row['pid']}
        assert json.loads(ordinary(stem.with_suffix('.terminal.json')).read_bytes()) == row
        assert type(row['pid']) is int and row['pid'] > 0 and type(row['native_exit']) is int and row['native_exit'] == row['wait_returncode'] == 0
        for suffix in ['stdout','stderr']:
            data = file_pin(stem.with_suffix('.' + suffix),{'bytes':row[suffix + '_bytes'],'sha256':row[suffix + '_sha256']})
            if suffix == 'stderr': assert data == b''
        expected_names |= {stem.name + suffix for suffix in ['.created.json','.terminal.json','.stdout','.stderr']}
    assert len(expected_names) == 184 and {p.name for p in root.iterdir()} == expected_names
    for name in sorted(expected_names):
        path = root / name; data = ordinary(path).read_bytes()
        PROTOCOL_PINS.append({'path':path.as_posix(),'bytes':len(data),'sha256':sha(data)})
    legacy = history['legacy_protocol']; failed = history['preparation_failure']
    assert len(legacy) == 35 and len(failed['files']) == 30 and len({r['path'] for r in legacy}) == 35
    for row in legacy:
        file_pin(Path(row['path']),row); PROTOCOL_PINS.append(row)
    assert len(PROTOCOL_PINS) == 219 and len({r['path'] for r in PROTOCOL_PINS}) == 219
    failed_root = ordinary(Path(failed['root']),True)
    assert failed_root.as_posix() == failed['root']
    assert set(tree(failed_root)['files']) == {Path(r['path']).relative_to(failed_root).as_posix() for r in failed['files']}
    for row in failed['files']: file_pin(Path(row['path']),row); OLD_NATIVE_PINS.append(row)
    owned = json.loads(ordinary(root / 'ACTUAL-OWNED.json').read_bytes())
    assert len(owned['containers']) == 2 and owned['network']['Internal'] is True and len(owned['labels']) == 2
    for label in ['create-redis','create-php']:
        index = next(i for i,row in enumerate(result['operations'],1) if row['label'] == label)
        cid = ordinary(root / ('%03d-' % index + label + '.stdout')).read_text().strip()
        assert cid in owned['containers'] and owned['containers'][cid]['id'] == cid
    # Existing fresh residual reads remain; the retired historical business is never rerun.
    for kind,args in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for index,(key,value) in enumerate(owned['labels'].items()):
            assert native('protocol-zero-' + kind + '-' + str(index),['docker',*args,'--filter','label=' + key + '=' + value]) == b''
    return owned


def current_post_a_history(args,context,final_raw,close_raw,zero_raw):
    """Exact future Root-reviewed public inventory; no inferred A or copied receipt."""
    contract = POST_A_HISTORY_CONTRACT
    assert type(contract) is dict and set(contract) == {'header','files_key','retained_public_files',
        'prior_public_inventory','public_roots','public_root_files'}, 'B_NEW_FIXED_POST_A_SOURCE_SCHEMA_UNBOUND'
    qualified = A_QUALIFICATION['post_a_history']; path = ordinary(Path(args.public_history_inventory))
    assert path.as_posix() == qualified['path'] and args.public_history_inventory_sha256 == qualified['sha256']
    raw = file_pin(path,qualified); value = json.loads(raw); key = contract['files_key']
    assert type(contract['header']) is dict and type(key) is str and key not in contract['header']
    assert set(value) == set(contract['header']) | {key}
    assert {name:value[name] for name in contract['header']} == contract['header']
    rows = value[key]; assert type(rows) is list and [r['path'] for r in rows] == sorted({r['path'] for r in rows})
    assert not path.is_relative_to(OLD_ROOT) and not path.is_relative_to(RUNTIME)
    prior = contract['prior_public_inventory']
    assert type(prior) is dict and set(prior) == {'descriptor','header','files_key'}
    previous_raw = file_pin(Path(prior['descriptor']['path']),prior['descriptor']); previous = json.loads(previous_raw)
    assert type(prior['header']) is dict and type(prior['files_key']) is str and prior['files_key'] not in prior['header']
    assert set(previous) == set(prior['header']) | {prior['files_key']}
    assert {name:previous[name] for name in prior['header']} == prior['header']
    old_rows = previous[prior['files_key']]
    assert type(old_rows) is list and old_rows and [r['path'] for r in old_rows] == sorted({r['path'] for r in old_rows})
    expected = {}
    for row in old_rows + [{'path':prior['descriptor']['path'],'bytes':len(previous_raw),'sha256':sha(previous_raw)}]:
        assert set(row) == {'path','bytes','sha256'} and Path(row['path']).is_absolute()
        file_pin(Path(row['path']),row); assert row['path'] not in expected or expected[row['path']] == row
        expected[row['path']] = row
    roots = [OLD_ROOT/'evidence',OLD_ARCHIVE,OLD_PROFILE,NORMAL_SOURCE/'materialize-freeze-assets-r1',
        NORMAL_SOURCE/'native-normal-start-assets-r1',NORMAL_SOURCE.parent/'materializer']
    assert contract['public_roots'] == [root.as_posix() for root in roots], 'B_EXACT_NEW_A_PUBLIC_SOURCE_ROOTS'
    files = [NORMAL_SOURCE/name for name in ['DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json']]
    files += [OLD_ROOT/name for name in PUBLIC_ROOT_FILES+['proof-php-ini/99-ms4-proof-memory.ini']]
    assert contract['public_root_files'] == [name.as_posix() for name in files], 'B_EXACT_NEW_A_PUBLIC_ROOT_FILES'
    for root in roots:
        for name,row in tree(root)['files'].items():
            item = root/name; descriptor = {'path':item.as_posix(),**row}
            assert descriptor['path'] not in expected or expected[descriptor['path']] == descriptor
            expected[descriptor['path']] = descriptor
    for item in files:
        data = ordinary(item).read_bytes(); descriptor = {'path':item.as_posix(),'bytes':len(data),'sha256':sha(data)}
        assert descriptor['path'] not in expected or expected[descriptor['path']] == descriptor
        expected[descriptor['path']] = descriptor
    retained = contract['retained_public_files']
    assert type(retained) is list and retained and [r['path'] for r in retained] == sorted({r['path'] for r in retained})
    for descriptor in retained:
        assert set(descriptor) == {'path','bytes','sha256'} and Path(descriptor['path']).is_absolute()
        file_pin(Path(descriptor['path']),descriptor)
        assert descriptor['path'] not in expected or expected[descriptor['path']] == descriptor
        expected[descriptor['path']] = descriptor
    assert rows == sorted(expected.values(),key=lambda row:row['path']), 'B_COMPLETE_PRIOR_AND_NEW_A_PUBLIC_INVENTORY'
    for row in rows:
        assert set(row) == {'path','bytes','sha256'} and Path(row['path']).is_absolute(); file_pin(Path(row['path']),row)
    for item,data in [(OLD_RESULT,final_raw),(OLD_ROOT/'evidence/ms4-worker-normal-close-result.json',close_raw),
            (OLD_ROOT/'evidence/ms4-worker-normal-independent-zero.json',zero_raw)]:
        assert expected[item.as_posix()] == {'path':item.as_posix(),'bytes':len(data),'sha256':sha(data)}
    return rows+[{'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}], {
        'normal_root_result':A_QUALIFICATION['normal_root_result'],
        'history_inventory':{'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}}


def current_original_retirement(args):
    inputs = source_inputs()
    consume_materialization(inputs)

    final_raw = file_pin(OLD_RESULT,A_QUALIFICATION['normal_root_result'])
    assert sha(final_raw) == args.normal_root_result_sha256 == A_QUALIFICATION['normal_root_result']['sha256']
    result = json.loads(final_raw)
    assert result == {'status':'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
        'run_id':OLD_RUN,'source_sha':PREVIOUS,'context':(OLD_ROOT / 'actual-run-context.json').as_posix(),
        'worker_scope':'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
        'full_acceptance':'INCOMPLETE','DOM_nonempty_history_due_finance_release':'NOT RUN'}
    context_raw = file_pin(OLD_ROOT / 'actual-run-context.json',OLD_CONTEXT_PIN); context = json.loads(context_raw)
    assert context['run_id'] == OLD_RUN and context['source_sha'] == PREVIOUS and context['freeze_sha256'] == OLD_FREEZE
    assert context['repo_dir'] == REPO.as_posix() and context['prep_dir'] == OLD_PROFILE.as_posix()
    assert context['run_dir'] == OLD_ROOT.as_posix() and context['project'] == 'tianli-audit-' + OLD_RUN
    binding_raw = ordinary(OLD_ROOT / 'preparation-source-binding.json').read_bytes(); old_binding = json.loads(binding_raw)
    assert len(binding_raw) == OLD_FREEZE_BYTES and sha(binding_raw) == OLD_FREEZE and old_binding['source_sha'] == PREVIOUS
    assert old_binding['requested_repo_source'] == REPO.as_posix()
    b_source_tree_binding(old_binding,PREVIOUS,REPO,context)
    setup_path = NORMAL_SOURCE / 'materialize-freeze-assets-r1/RESULT.json'
    setup_raw = file_pin(setup_path,NORMAL_SETUP_PIN); setup = json.loads(setup_raw)
    assert setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
    assert setup['source_sha'] == PREVIOUS and setup['repo'] == REPO.as_posix() and setup['profile'] == OLD_PROFILE.as_posix()
    old_freeze_path = NORMAL_SOURCE / 'materialize-freeze-assets-r1/freeze-binding.json'
    assert Path(setup['freeze']) == old_freeze_path and setup['freeze_sha256'] == OLD_FREEZE
    assert ordinary(old_freeze_path).read_bytes() == binding_raw
    for path, raw in [(setup_path,setup_raw),(old_freeze_path,binding_raw)]:
        OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    assert [row['path'] for row in old_binding['artifact_files']] == OLD_ARTIFACTS
    for row in old_binding['artifact_files']:
        file_pin(OLD_PROFILE / row['path'], row)
        OLD_PROFILE_PINS.append({'path':(OLD_PROFILE / row['path']).as_posix(),'bytes':row['bytes'],'sha256':row['sha256']})
    assert tree(OLD_PROFILE) == {'files':dict(sorted((x['path'],{'bytes':x['bytes'],'sha256':x['sha256']}) for x in old_binding['artifact_files'])), 'directories':[]}
    for name, digest in OLD_CLI_PINS.items(): assert sha(ordinary(OLD_PROFILE / name).read_bytes()) == digest
    evidence = OLD_ROOT / 'evidence'
    close_path = evidence / 'ms4-worker-normal-close-result.json'; close_raw = file_pin(close_path,A_QUALIFICATION['worker_close']); closed = json.loads(close_raw)
    zero_path = evidence / 'ms4-worker-normal-independent-zero.json'; zero_raw = file_pin(zero_path,A_QUALIFICATION['independent_zero']); zero = json.loads(zero_raw)
    assert closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL' and closed['scope'] == 'MS4_WORKER_NORMAL_CLOSE_ONLY'
    assert zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO' and zero['scope'] == 'MS4_WORKER_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY'
    for value in [closed,zero]:
        assert value['run_id'] == OLD_RUN and value['source_sha'] == PREVIOUS and value['freeze_sha256'] == OLD_FREEZE
        assert value['context_sha256'] == sha(context_raw) and type(value['native_exit']) is int and value['native_exit'] == 0
        assert value['full_acceptance'] == 'INCOMPLETE' and value['stopped_samples'] == 'NOT RUN'
    assert closed['close_source_sha256'] == zero['close_source_sha256'] == OLD_CLI_PINS['normal-close-ms4-fulfillment.py']
    assert closed['reviewed_host_sha256'] == zero['host_source_sha256'] == OLD_CLI_PINS['ms4-host.py']
    assert zero['zero_source_sha256'] == OLD_CLI_PINS['verify-zero-ms4-fulfillment.py'] and zero['close_result_sha256'] == sha(close_raw)
    assert closed['exact_nonforce_removed'] == {'containers':4,'networks':1,'volumes':9} and closed['ordinary_stop_and_exact_nonforce_remove'] is True
    assert set(closed['final_inventory']) == {'container','network','volume'}
    assert all(set(labels) == {'project','run'} and all(x == [] for x in labels.values()) for labels in closed['final_inventory'].values())
    assert [(q['kind'],q['label']) for q in zero['queries']] == [(kind,label) for kind in ['container','network','volume'] for label in ['project','run']]
    assert all(type(q['native_exit']) is int and q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0
        and q['stdout_sha256'] == q['stderr_sha256'] == sha(b'') for q in zero['queries'])
    commands = prior_native_history(context, close_raw, zero_raw)
    assert set(context['initial_service_ids']) == {'audit-mysql','audit-redis'} and len(set(context['initial_service_ids'].values())) == 2
    targets = set(context['initial_service_ids'].values())
    for path in sorted(evidence.glob('*-created.json')):
        value = json.loads(ordinary(path).read_bytes())
        if 'id' in value:
            assert value['run_id'] == OLD_RUN and value['source_sha'] == PREVIOUS and re.fullmatch('[a-f0-9]{64}',value['id'])
            targets.add(value['id'])
    assert all(re.fullmatch('[a-f0-9]{64}',cid) for cid in targets)
    volumes = {x['name'] for x in context['initial_volumes']}
    assert len(context['initial_volumes']) == len(volumes) == 9
    assert volumes == {context['project'] + '_' + suffix for suffix in ['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets','aftersale-evidence','customer-form-evidence','source-tree']}
    assert context['initial_network']['Internal'] is True and context['network_id'] == context['initial_network']['Id']
    assert re.fullmatch('[a-f0-9]{64}',context['network_id'])
    protocol = current_protocol_retirement(); targets |= set(protocol['containers'])
    # These fresh observations happen only after the original normal/close/zero native-zero proof.
    for kind, argv in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for label, value in [('project','com.docker.compose.project=' + context['project']),('run','tianli.audit.run=' + OLD_RUN)]:
            assert native('old-zero-' + kind + '-' + label,['docker',*argv,'--filter','label=' + value]) == b''
    ids = native('old-global-cids',['docker','ps','-aq','--no-trunc']).decode().splitlines()
    assert len(ids) == len(set(ids)) and not set(ids).intersection(targets)
    assert all(re.fullmatch('[a-f0-9]{64}',cid) for cid in ids)
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    facts = native('old-global-facts',['docker','inspect','--format',template,*ids]).decode().splitlines() if ids else []
    observed = set()
    for line in facts:
        value = json.loads(line)
        assert set(value) == {'id','mounts','mode','networks'} and value['id'] in ids and value['id'] not in observed
        observed.add(value['id'])
        assert not {x.get('Name') for x in value['mounts'] if x.get('Type') == 'volume'}.intersection(volumes)
        for net_name, net_id in [(context['initial_network']['Name'],context['network_id']),(protocol['network']['Name'],protocol['network']['Id'])]:
            assert net_name not in value['networks'] and net_id not in value['networks']
            assert all(x.get('NetworkID') != net_id for x in value['networks'].values())
            assert value['mode'] not in {net_id,net_name}
        assert not (value['mode'].startswith('container:') and value['mode'][10:] in targets)
    final_ids = native('old-global-cids-final',['docker','ps','-aq','--no-trunc']).decode().splitlines()
    assert observed == set(ids) and len(final_ids) == len(set(final_ids)) and set(final_ids) == set(ids)

    preserved,prior = current_post_a_history(args,context,final_raw,close_raw,zero_raw)
    actual_context_path = NORMAL_SOURCE / 'native-normal-start-assets-r1/ACTUAL-CONTEXT.json'
    data = ordinary(actual_context_path).read_bytes()
    OLD_NATIVE_PINS.append({'path':actual_context_path.as_posix(),'bytes':len(data),'sha256':sha(data)})
    prior['native_commands'] = len(commands)
    return preserved,old_binding,prior


# The original functions remain exact historical Source. Active calls throughout
# the original materializer now dispatch to the strict current adapters.
known_normal_root=current_known_normal_root
consume_materialization=current_consume_materialization
verify_sources=current_verify_sources
original_retirement=current_original_retirement


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        trace = error.__traceback__
        while trace.tb_next is not None: trace = trace.tb_next
        print(json.dumps({'status':'B_SOURCE_TREE_QUALIFICATION_INCOMPLETE_STATE_PRESERVED',
            'class':type(error).__name__,'code':str(error),
            'file':Path(trace.tb_frame.f_code.co_filename).name,'line':trace.tb_lineno,
            'Run_created':None,'App_DB_HTTP_GUI':'NOT RUN','no_retry_force_or_cleanup':True}),flush=True)
        raise SystemExit(1)
