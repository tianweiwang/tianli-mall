"""Derive one read-only completion of the interrupted R100 normal A.

Authoring and --check are filesystem reads plus in-memory construction only.
--emit writes candidate bytes to stdout, never a source package. The generated
actor needs a separate independent source review and a new original native
capture. It never completes, replaces, or invents an old A/206 terminal.
"""
from __future__ import annotations

import argparse
import ast
import hashlib
import json
from pathlib import Path
import sys
import types


R100 = Path('H:/天俪商城/artifacts/multi-merchant-final-normal-20261103-r100')
NORMAL = R100 / 'normal-source-r1'
PROFILE = NORMAL / 'validation/actual-profile'
RUN = Path('H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261103083539c03d06')
SOURCE_SHA = 'f1814e6c21f8294e2cab7c77013f3baaf5127d0b'
FREEZE_SHA = '512f03410fdbd425881fbf2c0c6aaa962840b548dc5d9ff88b66e42e0bb4a64a'
ZERO = {'path': (PROFILE / 'verify-zero-ms4-fulfillment.py').as_posix(), 'bytes': 316640,
        'sha256': '48510b413af408429d8796ea6e9ba700f140979abcc536c36163cded56811e45'}
FIXED = {
    'original_zero': ZERO,
    'authority': {'path': (R100 / 'CURRENT-SOURCE-TECHNICAL-AUTHORITY.json').as_posix(), 'bytes': 1095,
                  'sha256': '9b48282ee34d21b3369652c5fd85689db6dc368767ca81776eb974b54e4280e4'},
    'context': {'path': (RUN / 'actual-run-context.json').as_posix(), 'bytes': 12732,
                'sha256': '4e49c7129e4d054646a598f13e91a642f5400d4e1bd3c854318b537549f52465'},
    'close': {'path': (RUN / 'evidence/ms4-worker-normal-close-result.json').as_posix(), 'bytes': 153290,
              'sha256': '5875d84ffd22c0ce702b119f2fb4e60f355af1d7d8e9f81ee3acbd4dca9c86f4'},
    'capture_created': {'path': (R100 / 'normal-start-capture-r1/actual.created.json').as_posix(), 'bytes': 1034,
                        'sha256': '6ddecadeaa908bf3869392c510ff95ad9e8b3d997b3fa34e9785e5e54127e4e5'},
    'launcher_created': {'path': (NORMAL / 'launcher/normal-start.created.json').as_posix(), 'bytes': 682,
                         'sha256': '3dc9c6a189c9fdd4d4fd51c604d03be2c64cd10d184c17b8f13f207cc3eb4f3e'},
    'interrupted_created': {'path': (RUN / 'root-orchestration/206-worker-independent-zero.created.json').as_posix(),
                            'bytes': 534, 'sha256': 'cd1d6cdc938dee882e14c9c06ce6af4118585133b464d73120f35542bf9c9c6e'},
}
SCOPE = 'CURRENT_A_INTERRUPTION_READ_ONLY_INDEPENDENT_COMPLETION'
CHANGED_FUNCTIONS = {'public_native_process', 'public_native_sidecar', 'worker_main'}
PUBLIC_ROOT_FILES = ['actual-run-context.json', 'preparation-source-binding.json', 'manifest.json',
                     'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl']


def require(value, code):
    if not value:
        raise ValueError(code)


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def definitions(raw):
    text = raw.decode('utf-8')
    return {node.name: ast.get_source_segment(text, node)
            for node in ast.parse(text).body if isinstance(node, ast.FunctionDef)}


# These helpers are part of the new reviewed candidate. They do not import the
# Host/close, execute App/SQL/Redis, traverse private payloads, or modify inputs.
RUNTIME = r'''
_CI_STATE = {}
_CI_STAT = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')


def interruption_ordinary(path, missing=False):
    import stat
    path = Path(path)
    require(path.is_absolute() and '..' not in path.parts, 'CI_ABSOLUTE_ORDINARY_PATH')
    for item in (path, *path.parents):
        if missing and not item.exists():
            require(item == path and not item.is_symlink(), 'CI_ONLY_MISSING_OUTPUT_LEAF')
            continue
        info = item.lstat()
        require(not stat.S_ISLNK(info.st_mode) and not (getattr(info, 'st_file_attributes', 0) & 1024),
                'CI_NO_LINK_OR_REPARSE_COMPONENT')
    return path


def interruption_read(path):
    import stat
    path = interruption_ordinary(path)
    before = path.lstat(); initial = tuple(getattr(before, key) for key in _CI_STAT)
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, 'CI_REGULAR_SINGLE_LINK_PUBLIC_FILE')
    with path.open('rb') as stream:
        opened = tuple(getattr(os.fstat(stream.fileno()), key) for key in _CI_STAT)
        comparable = list(opened[:-1])
        if os.name == 'nt' and path.suffix.lower() in ('.exe', '.bat', '.cmd', '.com'):
            comparable[2] |= 0o111
        require(tuple(comparable) == initial[:-1], 'CI_PATH_FD_EIGHT_COMPARABLE_FIELDS')
        raw = stream.read()
        require(stream.read(1) == b'' and tuple(getattr(os.fstat(stream.fileno()), key) for key in _CI_STAT) == opened,
                'CI_FULL_EOF_AND_FD_STAT9_HELD')
    require(tuple(getattr(path.lstat(), key) for key in _CI_STAT) == initial and len(raw) == before.st_size,
            'CI_FULL_BYTES_AND_PATH_STAT9_HELD')
    return raw, {'bytes': len(raw), 'sha256': sha(raw), 'path_stat9': initial, 'fd_stat9': opened}


def interruption_fixed(pointer):
    require(type(pointer) is dict and set(pointer) == {'path', 'bytes', 'sha256'}, 'CI_EXACT_PUBLIC_POINTER')
    raw, _ = interruption_read(pointer['path'])
    require(type(pointer['bytes']) is int and {'bytes': len(raw), 'sha256': sha(raw)}
            == {key: pointer[key] for key in ('bytes', 'sha256')}, 'CI_FIXED_FULL_INPUT_BYTES')
    return raw


def interruption_inactive():
    # Read-only Windows process handles. No signal or process termination API.
    import ctypes
    from ctypes import wintypes
    require(os.name == 'nt', 'CI_CURRENT_WINDOWS_HOST_ONLY')
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
    kernel.OpenProcess.restype = wintypes.HANDLE
    kernel.GetExitCodeProcess.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD)]
    kernel.GetExitCodeProcess.restype = wintypes.BOOL
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]; kernel.CloseHandle.restype = wintypes.BOOL
    for pid in CI['original_pids']:
        handle = kernel.OpenProcess(0x1000, False, pid)
        if handle:
            try:
                code = wintypes.DWORD()
                require(kernel.GetExitCodeProcess(handle, ctypes.byref(code)) and code.value != 259,
                        'CI_NO_LIVE_OR_REUSED_ORIGINAL_PID')
            finally:
                require(kernel.CloseHandle(handle), 'CI_QUERY_HANDLE_CLOSED')
        else:
            require(ctypes.get_last_error() == 87, 'CI_ORIGINAL_PID_ABSENCE_MUST_BE_OBSERVABLE')
    # No observed old exit code is attributed to the interrupted old actor.
    return {'original_A': 'UNKNOWN/INTERRUPTED', 'live_original_pids': []}


def interruption_coordinators():
    normal = Path(CI['normal_root']); run = Path(CI['run_root']); profile = Path(CI['profile'])
    archives = [normal / 'validation/native-normal-start-assets-r1', run / 'root-orchestration']
    coordinator = interruption_fixed(CI['coordinator_source'])
    commands = []; all_members = {}
    for ordinal, archive in enumerate(archives):
        interruption_ordinary(archive)
        require((archive / 'root-driver-source.py').read_bytes() == coordinator, 'CI_ORIGINAL_COORDINATOR_TWO_COPIES')
        created_paths = sorted(archive.glob('*.created.json')); members = {'root-driver-source.py'}
        if ordinal == 0:
            actual = json.loads(interruption_read(archive / 'ACTUAL-CONTEXT.json')[0])
            require(actual == {'context': CI['fixed']['context']['path'], 'run_id': CI['run_id'],
                    'source_sha': CI['source_sha'], 'freeze_sha256': CI['freeze_sha256']}, 'CI_ORIGINAL_ACTUAL_CONTEXT')
            members.add('ACTUAL-CONTEXT.json')
        for path in created_paths:
            stem = path.name[:-len('.created.json')]; match = re.fullmatch(r'([0-9]{3})-(.+)', stem)
            require(match is not None, 'CI_ORIGINAL_COORDINATOR_NUMERIC_SEQUENCE')
            number = int(match[1]); label = match[2]
            if number == 206:
                require(ordinal == 1 and label == 'worker-independent-zero'
                    and path.as_posix() == CI['fixed']['interrupted_created']['path'], 'CI_ONE_EXACT_INTERRUPTED_CREATED')
                interruption_fixed(CI['fixed']['interrupted_created']); members.add(path.name)
                for suffix in ('.terminal.json', '.stdout', '.stderr'):
                    missing = archive / (stem + suffix)
                    require(not missing.exists() and not missing.is_symlink(), 'CI_206_TERMINAL_AND_STREAMS_REMAIN_ABSENT')
                continue
            require(1 <= number <= 205 and (number == 1 if ordinal == 0 else number >= 2), 'CI_ONLY_ORIGINAL_COMPLETE_1_TO_205')
            streams = {}; pointers = {}
            for suffix in ('created.json', 'terminal.json', 'stdout', 'stderr'):
                member = archive / (stem + '.' + suffix)
                raw = interruption_read(member)[0]; streams[suffix] = raw; members.add(member.name)
                pointers[suffix] = {'path': member.as_posix(), 'bytes': len(raw), 'sha256': sha(raw)}
            made = json.loads(streams['created.json']); terminal = json.loads(streams['terminal.json'])
            require(type(made) is dict and set(made) == {'argv', 'pid'} and type(made['pid']) is int and made['pid'] > 0
                and type(terminal) is dict and set(terminal) == {'pid', 'native_exit', 'wait_returncode',
                    'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256'}
                and type(terminal['pid']) is int and terminal['pid'] == made['pid']
                and type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
                and terminal['native_exit'] == terminal['wait_returncode'] == 0 and streams['stderr'] == b'',
                'CI_ALL_205_SAME_NATIVE_PID_WAIT_ZERO')
            for kind in ('stdout', 'stderr'):
                require(type(terminal[kind + '_bytes']) is int and terminal[kind + '_bytes'] == len(streams[kind])
                    and terminal[kind + '_sha256'] == sha(streams[kind]), 'CI_ALL_205_FULL_RAW_STREAM_PINS')
            argv = made['argv']
            require(type(argv) is list and len(argv) >= 6 and all(type(x) is str for x in argv)
                and Path(argv[0]).is_absolute() and argv[1:4] == ['-B', '-X', 'utf8'], 'CI_ORIGINAL_COORDINATOR_PYTHON_ARGV')
            script = Path(argv[4]); arguments = argv[5:]
            require(script.parent == profile and script.name in ('ms4-host.py', 'normal-close-ms4-fulfillment.py'),
                    'CI_ORIGINAL_COMPLETE_205_ONLY_REVIEWED_HOST_AND_CLOSE')
            if number == 1:
                require(label == 'prepare' and script.name == 'ms4-host.py' and len(arguments) == 5
                    and arguments[0:2] == ['prepare', '--binding'] and arguments[3] == '--run-root'
                    and Path(arguments[2]) == normal / 'validation/materialize-freeze-assets-r1/freeze-binding.json'
                    and Path(arguments[4]) == run.parent, 'CI_ORIGINAL_FIRST_PREPARE')
            elif number == 205:
                require(label == 'worker-normal-close' and script.name == 'normal-close-ms4-fulfillment.py'
                    and len(arguments) == 3 and arguments[0] == '--context' and Path(arguments[1]) == run / 'actual-run-context.json'
                    and arguments[2] == '--worker-normal', 'CI_EXACT_LAST_COMPLETE_NORMAL_CLOSE')
            else:
                require(script.name == 'ms4-host.py' and len(arguments) in (3, 7) and arguments[1] == '--context'
                    and Path(arguments[2]) == run / 'actual-run-context.json', 'CI_ORIGINAL_HOST_CONTEXT_ARGV')
                if len(arguments) == 7:
                    require(arguments[0] in ('observe', 'finish-helper') and arguments[3] == '--proof'
                        and arguments[5:] == ['--seconds', '30'], 'CI_ORIGINAL_HOST_OBSERVE_OR_FINISH')
                require(type(json.loads(streams['stdout'])) is dict, 'CI_ORIGINAL_HOST_JSON_RESULT')
            commands.append({'sequence': number, 'label': label, 'argv': argv, 'native': terminal, 'files': pointers})
        require({p.name for p in archive.iterdir()} == members, 'CI_EXACT_OLD_COORDINATOR_NAMESPACE_WITH_ONE_GAP')
        all_members[archive.as_posix()] = sorted(members)
    commands.sort(key=lambda value: value['sequence'])
    require([row['sequence'] for row in commands] == list(range(1, 206)), 'CI_CONTIGUOUS_ORIGINAL_205_COMPLETE_COMMANDS')
    raw = (json.dumps({'commands': commands, 'members': all_members}, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':')) + '\n').encode()
    return {'count': len(commands), 'bytes': len(raw), 'sha256': sha(raw)}


def interruption_window():
    # This stat9 window covers CI declarations only. External source dependencies
    # retain the original zero verifier's complete before/after content gates.
    import stat
    files = {}; directories = {}
    def item(path):
        raw, record = interruption_read(path); files[path.as_posix()] = record
    def tree(folder):
        interruption_ordinary(folder); before = tuple(getattr(folder.lstat(), k) for k in _CI_STAT)
        members = sorted(folder.iterdir(), key=lambda p: p.name)
        rows = []
        for path in members:
            interruption_ordinary(path); info = path.lstat()
            require(stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode), 'CI_PUBLIC_TREE_REGULAR_FILES_AND_DIRECTORIES')
            rows.append({'name': path.name, 'kind': 'directory' if stat.S_ISDIR(info.st_mode) else 'file',
                         'stat9': tuple(getattr(info, k) for k in _CI_STAT)})
            if stat.S_ISDIR(info.st_mode): tree(path)
            else: item(path)
        require(tuple(getattr(folder.lstat(), k) for k in _CI_STAT) == before
            and [p.name for p in sorted(folder.iterdir(), key=lambda p: p.name)] == [row['name'] for row in rows],
            'CI_PUBLIC_DIRECTORY_STAT9_AND_COMPLETE_MEMBERS_HELD')
        directories[folder.as_posix()] = {'stat9': before, 'members': rows}
    for path in CI['public_trees']: tree(Path(path))
    run = Path(CI['run_root']); interruption_ordinary(run)
    before = tuple(getattr(run.lstat(), k) for k in _CI_STAT)
    # Non-whitelisted root members are metadata only: no body/hash/recursion.
    root_members = [{'name': p.name, 'stat9': tuple(getattr(p.lstat(), k) for k in _CI_STAT)}
                    for p in sorted(run.iterdir(), key=lambda p: p.name)]
    require(tuple(getattr(run.lstat(), k) for k in _CI_STAT) == before, 'CI_RUN_ROOT_METADATA_STAT9_HELD')
    for name in CI['public_root_files']: item(run / name)
    for pointer in CI['fixed'].values(): item(Path(pointer['path']))
    for pointer in (CI['author_source'], CI['coordinator_source'], _CI_STATE['candidate'], _CI_STATE['review']):
        item(Path(pointer['path']))
    return {'scope': 'COMPLETE_CI_DECLARED_PUBLIC_SET_AND_RUN_ROOT_MEMBER_METADATA_ONLY',
            'files': files, 'directories': directories, 'run_root_stat9': before, 'run_root_members': root_members,
            'non_whitelisted_root_bodies_or_private_payloads': 'NOT READ OR HASHED OR RECURSED'}


def interruption_output_boundary(output, candidate, review):
    output = interruption_ordinary(output, missing=True)
    require(not output.exists() and output.parent.is_dir(), 'CI_ONE_NEW_CREATE_ONLY_OUTPUT_DIRECTORY')
    protected = [Path(CI['normal_root']), Path(CI['run_root']), Path(CI['capture_root']),
                 Path(CI['candidate_root']), Path(CI['validation_root']), Path(CI['author_worktree'])]
    for root in protected:
        interruption_ordinary(root)
        require(not output.is_relative_to(root) and not root.is_relative_to(output), 'CI_OUTPUT_DISJOINT_FROM_EVERY_OLD_SOURCE_AND_RUN')
    for path in (candidate, review, *(p['path'] for p in CI['fixed'].values())):
        require(not Path(path).is_relative_to(output) and not output.is_relative_to(Path(path)), 'CI_OUTPUT_DISJOINT_FROM_ALL_FIXED_INPUT_FILES')
    require(output.resolve(strict=False) == output, 'CI_CANONICAL_PHYSICALLY_SEPARATE_OUTPUT')
    return output


def interruption_review(review_file, review_sha):
    require(type(review_sha) is str and re.fullmatch('[a-f0-9]{64}', review_sha), 'CI_INDEPENDENT_SOURCE_REVIEW_HASH_REQUIRED')
    raw, facts = interruption_read(review_file); review = json.loads(raw)
    require(sha(raw) == review_sha and type(review) is dict
        and set(review) == {'schema', 'scope', 'status', 'reviewer', 'candidate', 'author_source', 'bindings', 'checks', 'findings'}
        and type(review['schema']) is int and review['schema'] == 1
        and review['scope'] == 'CURRENT_A_INTERRUPTION_COMPLETION_SOURCE_REVIEW'
        and review['status'] == 'PASS_SOURCE_REVIEW_ONLY' and type(review['reviewer']) is str and review['reviewer']
        and review['reviewer'] != 'a_interruption_options' and review['findings'] == [], 'CI_ACTUAL_INDEPENDENT_SOURCE_REVIEW')
    own = interruption_read(Path(__file__))[0]
    candidate = {'path': Path(__file__).resolve(strict=True).as_posix(), 'bytes': len(own), 'sha256': sha(own)}
    require(review['candidate'] == candidate and review['author_source'] == CI['author_source']
        and review['bindings'] == CI and review['checks'] == {'whole_source_inverse': True,
            'unchanged_reachable_functions': 135, 'only_read_only_native_commands': True,
            'original_A': 'UNKNOWN/INTERRUPTED'}, 'CI_COMPLETE_REVIEWED_SOURCE_AND_BINDINGS')
    interruption_fixed(CI['author_source'])
    _CI_STATE['candidate'] = candidate
    _CI_STATE['review'] = {'path': Path(review_file).as_posix(), 'bytes': len(raw), 'sha256': review_sha}


def interruption_admit(context_file, close_sha):
    require(not _CI_STATE.get('admitted'), 'CI_ONE_WORKER_ONLY_COMPLETION_ENTRY')
    require(Path(context_file).as_posix() == CI['fixed']['context']['path']
        and close_sha == CI['fixed']['close']['sha256'], 'CI_EXACT_ORIGINAL_CONTEXT_AND_CLOSE_HASH')
    values = {key: interruption_fixed(pointer) for key, pointer in CI['fixed'].items()}
    context = json.loads(values['context']); authority = json.loads(values['authority'])
    require(context['run_id'] == CI['run_id'] and context['source_sha'] == CI['source_sha']
        and context['freeze_sha256'] == CI['freeze_sha256'] and context['run_dir'] == CI['run_root']
        and context['repo_dir'] == CI['validation_root'] and context['prep_dir'] == CI['profile']
        and authority['source_sha'] == CI['source_sha'] and authority['candidate_root'] == CI['candidate_root']
        and authority['validation_root'] == CI['validation_root'] and CURRENT_SOURCE_AUTHORITY == CI['fixed']['authority'],
        'CI_ORIGINAL_SOURCE_AUTHORITY_CONTEXT_FREEZE_AND_PROFILE')
    interrupted = json.loads(values['interrupted_created']); argv = interrupted['argv']
    require(set(interrupted) == {'argv', 'pid'} and interrupted['pid'] == 5968 and len(argv) == 10
        and argv[1:4] == ['-B', '-X', 'utf8'] and Path(argv[4]).as_posix() == CI['fixed']['original_zero']['path']
        and argv[5] == '--context' and Path(argv[6]).as_posix() == CI['fixed']['context']['path']
        and argv[7:] == ['--close-result-sha256', close_sha, '--worker-normal'], 'CI_EXACT_UNFINISHED_ORIGINAL_206_ARGV')
    for directory, prefix in [(Path(CI['capture_root']), 'actual'), (Path(CI['normal_root']) / 'launcher', 'normal-start')]:
        for suffix in ('terminal.json', 'stdout', 'stderr'):
            path = directory / (prefix + '.' + suffix)
            require(not path.exists() and not path.is_symlink(), 'CI_OLD_OUTER_TERMINALS_AND_STREAMS_STILL_UNKNOWN')
    for name in ('root-orchestration/RESULT.json', 'evidence/ms4-worker-normal-independent-zero.json',
                 'evidence/core-native-worker-zero-before.json', 'evidence/core-native-worker-zero-after.json'):
        path = Path(CI['run_root']) / name
        require(not path.exists() and not path.is_symlink(), 'CI_ONLY_THIS_PREPUBLICATION_INTERRUPTION')
    _CI_STATE['context'] = context
    _CI_STATE['inactive'] = interruption_inactive()
    require(interruption_coordinators() == CI['coordinator_complete'], 'CI_FIXED_COMPLETE_205_NATIVE_PROOFS_AND_UNIQUE_206_GAP')
    # Original ledger/prefix validation runs before creating even the new OUT.
    _CI_STATE['old_records'] = public_native_records(context)
    interruption_old_prefix(context, _CI_STATE['old_records'])
    _CI_STATE['before'] = interruption_window()
    output = interruption_output_boundary(CI['output_root'], _CI_STATE['candidate']['path'], _CI_STATE['review']['path'])
    output.mkdir(); (output / 'evidence').mkdir()
    _CI_STATE['admitted'] = True


def interruption_native_context(context, argv, label, env, legacy_receipt):
    require(_CI_STATE.get('admitted') is True and paid_equal(context, _CI_STATE['context'])
        and env is None and legacy_receipt is None, 'CI_ONLY_CURRENT_ORIGINAL_CONTEXT_AND_READ_ONLY_NATIVE_ACCOUNTING')
    global_args = ['docker', 'ps', '-aq', '--no-trunc']
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    accepted = label == 'core-zero-worker-global-query' and (argv == global_args or
        (len(argv) == 5 and argv[:4] == ['docker', 'inspect', '--format', template]
         and type(argv[4]) is str and re.fullmatch('[a-f0-9]{64}', argv[4]) is not None))
    for kind, args in [('container', ['ps', '-aq', '--no-trunc']), ('network', ['network', 'ls', '-q']), ('volume', ['volume', 'ls', '-q'])]:
        for key, value in [('project', 'com.docker.compose.project=' + context['project']), ('run', 'tianli.audit.run=' + context['run_id'])]:
            accepted |= label == 'core-zero-worker-fresh-' + kind + '-' + key and argv == ['docker', *args, '--filter', 'label=' + value]
    require(accepted, 'CI_EXACT_EXISTING_READ_ONLY_PS_INSPECT_AND_SIX_QUERY_ARGV_ONLY')
    return {'run_dir': CI['output_root']}


def interruption_old_prefix(context, records):
    root = Path(context['run_dir']); linked = None; cap = len(records)
    for index, phase in [(1, 'CLOSE_AFTER'), (0, 'CLOSE_BEFORE')]:
        path = root / 'evidence' / ('core-native-worker-close-' + ('after' if index else 'before') + '.json')
        raw = interruption_read(path)[0]; old = json.loads(raw)
        current = {'path': path.relative_to(root).as_posix(), 'bytes': len(raw), 'sha256': sha(raw)}
        if linked is not None: require(linked == current, 'CI_OLD_COMPLETE_PRIOR_SIDECAR_PIN')
        require(type(old) is dict and set(old) == {'schema', 'scope', 'phase', 'run_id', 'source_sha', 'freeze_sha256', 'record_count', 'records', 'prefix', 'complete_namespace'}
            and type(old['schema']) is int and old['schema'] == 1 and old['scope'] == 'MS4_CORE_PUBLIC_NATIVE_RAW_PRESERVATION'
            and old['phase'] == phase and all(old[key] == context[key] for key in ('run_id', 'source_sha', 'freeze_sha256'))
            and old['complete_namespace'] is True and type(old['record_count']) is int and type(old['records']) is list
            and old['record_count'] == len(old['records']) and 0 < old['record_count'] <= cap
            and old['records'] == records[:old['record_count']], 'CI_ALL_ORIGINAL_CLOSE_PREFIX_ASSERTIONS_HELD')
        require(interruption_read(path)[0] == raw, 'CI_OLD_PREFIX_FULL_READBACK')
        linked = old['prefix']; cap = old['record_count']
        if index == 0: require(linked is None, 'CI_ORIGINAL_FIRST_PHASE_NO_PRIOR_PREFIX')
        else: require(type(linked) is dict and set(linked) == {'path', 'bytes', 'sha256'}, 'CI_ORIGINAL_TYPED_PRIOR_PREFIX')


def interruption_finish(context, zero):
    require(_CI_STATE.get('admitted') is True and paid_equal(context, _CI_STATE['context']), 'CI_FINISH_SAME_ORIGINAL_CONTEXT')
    require(zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO'
        and zero['scope'] == 'MS4_WORKER_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY' and zero['native_exit'] == 0,
        'CI_ALL_ORIGINAL_WORKER_ZERO_RETURN_ASSERTIONS_PASSED')
    require(interruption_coordinators() == CI['coordinator_complete'], 'CI_ORIGINAL_205_AND_UNFINISHED_206_HELD_AFTER_ZERO')
    interruption_inactive()
    after = interruption_window()
    require(after == _CI_STATE['before'], 'CI_COMPLETE_DECLARED_PUBLIC_SET_AND_ROOT_METADATA_UNCHANGED')
    output = Path(CI['output_root'])
    new(output / 'PUBLIC-INPUT-WINDOWS.json', {'scope': after['scope'], 'before': after,
        'after_equal_to_before': True, 'after_sha256': sha((json.dumps(after, sort_keys=True, ensure_ascii=False) + '\n').encode())})
    value = {'schema': 1, 'scope': CI['scope'], 'status': 'VERIFIED_INDEPENDENT_COMPLETION_OF_INTERRUPTED_A_ONLY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'original_A': {'status': 'UNKNOWN/INTERRUPTED', 'native_exit': None, 'wait_returncode': None,
                       'coordinator_commands_1_to_205': CI['coordinator_complete'], '206_terminal': 'ABSENT_NOT_REPLACED',
                       'old_Root_RESULT_and_outer_terminal': 'ABSENT_NOT_CREATED'},
        'independent_zero_verification': zero, 'source': _CI_STATE['candidate'], 'source_review': _CI_STATE['review'],
        'bindings': CI, 'verification_return_code': 0, 'actor_native_exit': None, 'actor_outer_terminal': 'REQUIRED_FROM_ROOT_ORIGINAL_NATIVE_HANDLE',
        'ci_declared_public_inputs_and_root_metadata_held': True, 'resource_mutations': 'NONE',
        'formal_B': 'NOT SIGNED', 'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    new(output / 'RESULT.json', value)
    expected = {'evidence', 'PUBLIC-INPUT-WINDOWS.json', 'RESULT.json'}
    require({p.name for p in output.iterdir()} == expected, 'CI_EXACT_NEW_COMPLETION_OUTPUT_NAMESPACE')
    require({p.name for p in (output / 'evidence').iterdir()} == {'native-streams',
        'completion-zero-before.json', 'completion-zero-after.json'}, 'CI_EXACT_NEW_COMPLETION_EVIDENCE_NAMESPACE')
    print(json.dumps({key: value[key] for key in ('status', 'run_id', 'source_sha', 'verification_return_code', 'actor_native_exit', 'full_acceptance')}, ensure_ascii=False))
'''

SIDECAR = r'''def public_native_sidecar(context, basename, phase, prefix=None):
    require(_CI_STATE.get('admitted') is True and paid_equal(context, _CI_STATE['context']), 'CI_SAME_ORIGINAL_CONTEXT_SIDECAR')
    require((basename, phase, prefix) in [
        ('core-native-worker-zero-before.json', 'ZERO_BEFORE', 'core-native-worker-close-after.json'),
        ('core-native-worker-zero-after.json', 'ZERO_AFTER', 'core-native-worker-zero-before.json')], 'CI_ONLY_ORIGINAL_TWO_ZERO_CALLS')
    old = public_native_records(context)
    require(old == _CI_STATE['old_records'], 'CI_ORIGINAL_COMPLETE_NATIVE_LEDGER_UNCHANGED')
    interruption_old_prefix(context, old)
    output = Path(CI['output_root']); native_context = {'run_dir': output.as_posix()}
    fresh_folder = output / 'evidence/native-streams'
    if phase == 'ZERO_BEFORE':
        require(not fresh_folder.exists(), 'CI_NEW_NATIVE_LEDGER_EMPTY_BEFORE_ACTUAL_QUERIES')
        records = []
    else:
        records = public_native_records(native_context)
        require(records, 'CI_ACTUAL_FRESH_NATIVE_QUERY_LEDGER')
    target = output / 'evidence' / ('completion-zero-before.json' if phase == 'ZERO_BEFORE' else 'completion-zero-after.json')
    previous = None
    if phase == 'ZERO_AFTER':
        p = output / 'evidence/completion-zero-before.json'; raw = interruption_read(p)[0]; value = json.loads(raw)
        require(type(value) is dict and set(value) == {'schema', 'scope', 'phase', 'original_A', 'run_id', 'source_sha',
            'freeze_sha256', 'old_records', 'fresh_records', 'prefix', 'complete_namespaces'}
            and type(value['schema']) is int and value['schema'] == 1 and value['complete_namespaces'] is True
            and value['scope'] == CI['scope'] and value['phase'] == 'ZERO_BEFORE' and value['fresh_records'] == []
            and value['old_records'] == old and value['original_A'] == 'UNKNOWN/INTERRUPTED'
            and all(value[key] == context[key] for key in ('run_id', 'source_sha', 'freeze_sha256'))
            and value['prefix'] is None, 'CI_NEW_ZERO_BEFORE_FULL_PREFIX_AND_OLD_RECORDS')
        previous = {'path': p.as_posix(), 'bytes': len(raw), 'sha256': sha(raw)}
    value = {'schema': 1, 'scope': CI['scope'], 'phase': phase, 'original_A': 'UNKNOWN/INTERRUPTED',
        **{key: context[key] for key in ('run_id', 'source_sha', 'freeze_sha256')},
        'old_records': old, 'fresh_records': records, 'prefix': previous, 'complete_namespaces': True}
    new(target, value)
    require(load(target) == value, 'CI_NEW_SIDECAR_FULL_READBACK')
    return value'''

ENTRY = r'''if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='One reviewed, read-only independent completion; original A remains interrupted.')
    parser.add_argument('--context', required=True)
    parser.add_argument('--close-result-sha256', required=True)
    parser.add_argument('--source-review-file', required=True)
    parser.add_argument('--source-review-sha256', required=True)
    args = parser.parse_args()
    try:
        interruption_review(args.source_review_file, args.source_review_sha256)
        worker_main(args.context, args.close_result_sha256)
    except Exception as error:
        print(json.dumps({'status': 'INTERRUPTION_COMPLETION_INCOMPLETE_OLD_STATE_PRESERVED',
            'original_A': 'UNKNOWN/INTERRUPTED', 'class': type(error).__name__,
            'no_mutation_retry_replay_or_old_terminal_creation': True}))
        raise SystemExit(1)
'''


def _source_parts(original):
    text = original.decode('utf-8'); tree = ast.parse(text)
    guard = tree.body[-1]
    require(isinstance(guard, ast.If) and ast.unparse(guard.test) == "__name__ == '__main__'", 'ONE_ORIGINAL_MAIN_GUARD')
    lines = text.splitlines(keepends=True)
    tail = ''.join(lines[guard.lineno - 1:]).encode()
    functions = definitions(original)
    artifact = b'ARTIFACTS = Path(__file__).resolve().parent\n'
    native = b"def public_native_process(context, argv, label, env=None, legacy_receipt=None):\n    reservation = public_native_reserve(context)\n"
    admitted = b"def worker_main(context_file, close_result_sha256):\n    require(CURRENT_SOURCE_AUTHORITY is not None, 'CORE_CURRENT_SOURCE_AUTHORITY_UNBOUND')\n"
    finish = b"    new(evidence / 'ms4-worker-normal-independent-zero.json', output)\n    print(json.dumps(output, ensure_ascii=False))\n"
    seams = [(artifact, ('ARTIFACTS = Path(' + repr(PROFILE.as_posix()) + ')\n').encode()),
             (native, native.replace(b'    reservation', b'    context = interruption_native_context(context, argv, label, env, legacy_receipt)\n    reservation')),
             (functions['public_native_sidecar'].encode(), SIDECAR.encode()),
             (admitted, admitted.replace(b'    require', b'    interruption_admit(context_file, close_result_sha256)\n    require', 1)),
             (finish, b'    interruption_finish(context, output)\n')]
    return seams, tail


def _namespace(source, name):
    module = types.ModuleType(name); module.__file__ = ZERO['path']
    exec(compile(source, name, 'exec'), module.__dict__)
    return module


def build_source(output_root):
    original = Path(ZERO['path']).read_bytes()
    require(pin(original) == {key: ZERO[key] for key in ('bytes', 'sha256')}, 'FIXED_ORIGINAL_ZERO_SOURCE')
    author_path = Path(__file__).resolve(strict=True); author = author_path.read_bytes()
    coordinator_path = NORMAL / 'validation/run.py'; coordinator = coordinator_path.read_bytes()
    authority = json.loads(Path(FIXED['authority']['path']).read_bytes())
    bindings = {'schema': 1, 'scope': SCOPE, 'original_A': 'UNKNOWN/INTERRUPTED', 'run_id': RUN.name,
        'source_sha': SOURCE_SHA, 'freeze_sha256': FREEZE_SHA, 'fixed': FIXED,
        'normal_root': NORMAL.as_posix(), 'run_root': RUN.as_posix(), 'profile': PROFILE.as_posix(),
        'capture_root': (R100 / 'normal-start-capture-r1').as_posix(),
        'candidate_root': authority['candidate_root'], 'validation_root': authority['validation_root'],
        'author_worktree': author_path.parents[3].as_posix(),
        'author_source': {'path': author_path.as_posix(), **pin(author)},
        'coordinator_source': {'path': coordinator_path.as_posix(), **pin(coordinator)},
        'original_pids': [34032, 41428, 36932, 5968], 'output_root': Path(output_root).as_posix(),
        'public_root_files': PUBLIC_ROOT_FILES,
        'public_trees': [NORMAL.as_posix(), (RUN / 'evidence').as_posix(), (RUN / 'root-orchestration').as_posix(),
                         (RUN / 'proof-php-ini').as_posix(), (R100 / 'normal-start-capture-r1').as_posix()]}
    helpers = _namespace(original + b'\n' + RUNTIME.encode(), 'normal_interruption_author_checks')
    helpers.CI = bindings
    for pointer in FIXED.values(): helpers.interruption_fixed(pointer)
    bindings['coordinator_complete'] = helpers.interruption_coordinators()
    require(bindings['coordinator_complete']['count'] == 205, 'ACTUAL_FIXED_205_COMPLETE_SOURCE_QUALIFICATION')
    seams, tail = _source_parts(original); candidate = original
    for before, after in seams:
        require(candidate.count(before) == 1, 'UNIQUE_LIMITED_INTERRUPTION_SEAM')
        candidate = candidate.replace(before, after, 1)
    require(candidate.endswith(tail), 'ORIGINAL_MAIN_GUARD_IS_COMPLETE_SUFFIX')
    insertion = ('\n\nCI = ' + repr(bindings) + '\n' + RUNTIME + '\n\n' + ENTRY).encode()
    candidate = candidate[:-len(tail)] + insertion
    ast.parse(candidate); compile(candidate, '<current-a-interruption-completion-candidate>', 'exec')
    recovered = candidate[:-len(insertion)] + tail
    for before, after in reversed(seams):
        require(recovered.count(after) == 1, 'UNIQUE_LIMITED_INTERRUPTION_INVERSE')
        recovered = recovered.replace(after, before, 1)
    require(recovered == original, 'WHOLE_SOURCE_EXACT_REVERSE_TO_FROZEN_ZERO')
    old_functions, new_functions = definitions(original), definitions(candidate)
    changed = {name for name, raw in old_functions.items() if new_functions.get(name) != raw}
    require(changed == CHANGED_FUNCTIONS, 'ONLY_THREE_EXPLICIT_IO_BOUNDARY_FUNCTIONS_CHANGED')
    reachable = set(); pending = ['worker_main']
    tree = ast.parse(original); nodes = {n.name: n for n in tree.body if isinstance(n, ast.FunctionDef)}
    while pending:
        name = pending.pop()
        if name in reachable: continue
        reachable.add(name)
        pending.extend(n.func.id for n in ast.walk(nodes[name]) if isinstance(n, ast.Call)
                       and isinstance(n.func, ast.Name) and n.func.id in nodes)
    require(len(reachable) == 138 and len(reachable - CHANGED_FUNCTIONS) == 135
        and all(old_functions[name] == new_functions[name] for name in reachable - CHANGED_FUNCTIONS),
        'ALL_135_OTHER_REACHABLE_FUNCTION_BODIES_PRESERVED')
    # All subprocess launches remain the original byte-for-byte Git readers or
    # the original native function with exactly one accounting-context seam.
    new_nodes = {n.name: n for n in ast.parse(candidate).body if isinstance(n, ast.FunctionDef)}
    for name in set(new_nodes) - set(nodes):
        require(all(not (isinstance(n, ast.Call) and isinstance(n.func, ast.Attribute)
                    and isinstance(n.func.value, ast.Name) and n.func.value.id in ('subprocess', 'os')
                    and n.func.attr in ('Popen', 'run', 'call', 'check_call', 'check_output', 'system', 'popen', 'execv', 'spawnv'))
                    for n in ast.walk(new_nodes[name])), 'NO_NEW_SUBPROCESS_OR_SHELL_LAUNCH_HELPER')
    return candidate, bindings


def check_source(output_root):
    candidate, bindings = build_source(output_root)
    module = _namespace(candidate, 'normal_interruption_positive_structure')
    context = json.loads(Path(FIXED['context']['path']).read_bytes())
    module._CI_STATE.update({'admitted': True, 'context': context})
    positives = [(['docker', 'ps', '-aq', '--no-trunc'], 'core-zero-worker-global-query')]
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    positives.append((['docker', 'inspect', '--format', template, '1' * 64], 'core-zero-worker-global-query'))
    for kind, argv in [('container', ['ps', '-aq', '--no-trunc']), ('network', ['network', 'ls', '-q']), ('volume', ['volume', 'ls', '-q'])]:
        for label, value in [('project', 'com.docker.compose.project=' + context['project']), ('run', 'tianli.audit.run=' + context['run_id'])]:
            positives.append((['docker', *argv, '--filter', 'label=' + value], 'core-zero-worker-fresh-' + kind + '-' + label))
    for argv, label in positives:
        require(module.interruption_native_context(context, argv, label, None, None) == {'run_dir': bindings['output_root']},
                'POSITIVE_ONLY_READ_ONLY_ACCOUNTING_PROJECTION')
    return {'schema': 1, 'scope': 'INTERRUPTION_AUTHOR_SOURCE_CHECKS_ONLY', 'status': 'PASS_SOURCE_ONLY',
        'candidate': pin(candidate), 'original_zero': ZERO, 'coordinator_complete': bindings['coordinator_complete'],
        'changed_functions': sorted(CHANGED_FUNCTIONS), 'unchanged_reachable_functions': 135,
        'whole_source_inverse': True, 'positive_read_only_argv_cases': len(positives),
        'runtime_Docker_App_SQL_Redis': 'NOT RUN', 'source_package': 'NOT WRITTEN', 'original_A': 'UNKNOWN/INTERRUPTED'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--emit', action='store_true')
    parser.add_argument('--output-root', required=True, help='New independent runtime output; authoring never creates it.')
    args = parser.parse_args()
    if args.check:
        print(json.dumps(check_source(args.output_root), ensure_ascii=False, indent=2))
    else:
        sys.stdout.buffer.write(build_source(args.output_root)[0])


if __name__ == '__main__':
    main()
