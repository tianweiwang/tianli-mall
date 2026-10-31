"""One host-only formal public/context copier. No build, image, database or deployment.
Requires actual complete same-source three-build outputs; never imports builder code.
"""
from pathlib import Path
import argparse, ast, hashlib, json, os, re, stat, subprocess, sys, time
from urllib.parse import urlsplit, unquote
from html.parser import HTMLParser

ROOT = None
ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
RUN_PARENT = ORG / 'build-runs'
COPY_PARENT = ORG / 'formal-public-runs'
QUALIFIED_INPUTS_PIN = None
NODE_ROOT = Path('H:/天俪商城/artifacts/ms3-build-tools/source477732-node22/node-v22.23.3-win-x64')
NODE = NODE_ROOT / 'node.exe'
NPM = NODE_ROOT / 'node_modules/npm/bin/npm-cli.js'
PYTHON = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
REVIEWED_BUILDER_BODIES = {'build_common.py': {'bytes': 15280, 'sha256': '207823f1bdf059bcacfa8d6787d3a31b822235d4fb9866ee7143e609eab8cbce'}, 'root-build.py': {'bytes': 4006, 'sha256': '71b8ba80deceb1ab1d14d8dc91c86a07c3336a1e6360f7baded2da88be3333c4'}, 'launch.py': {'bytes': 2554, 'sha256': '370c3565af6c7de13a40a9a0998f28577b13b79f7661d2f6b327137fda0cf660'}, 'windows_build.py': {'bytes': 10945, 'sha256': '26539f48a225625030f53df8fda204773213c35ca30e0da98467d0443f3453c5'}, 'h5_build.py': {'bytes': 18797, 'sha256': 'e093b2248d9a4eb6b181775653dce1ed4b2476261dcfc27850918ec6818b515f'}}
# Actual 07192652 source was read: /admin + system_static, /merchant/ +
# static, H5 history with empty base. A later config change needs a reviewed
# contract and new builds; accepting arbitrary current config hashes is unsafe.
FORMAL_SOURCE_CONFIGS = {
    'template/admin/vue.config.js': {'bytes': 2932, 'sha256': '87e46dc80877462941897126cf00715ef8fe8b65583529ae56e2556f4f5c9164'},
    'template/merchant/vue.config.js': {'bytes': 321, 'sha256': '6566a3d16eea14c7a7e1a38910f462ebab0cd9901a9ab6754f2bd224bb440375'},
    'template/uni-app/manifest.json': {'bytes': 10156, 'sha256': 'f181c0044caafb4dca124d4038494159a4dcfe0129222a563de2debe3f94bdb1'},
}

def require(value, code):
    if not value:
        raise RuntimeError(code)



def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}



def native9(path):
    info = Path(path).lstat()
    return [info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_uid,
            info.st_gid, info.st_size, info.st_mtime_ns, info.st_ctime_ns]



def regular(path, directory=False):
    path = Path(path)
    info = path.lstat()
    require(not path.is_symlink() and not (getattr(info, 'st_file_attributes', 0) & 1024), 'NO_LINK_OR_REPARSE')
    require(stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode), 'EXACT_FILE_KIND')
    return info



def read(path):
    path = Path(path)
    fields = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid',
              'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    shared = ('st_dev', 'st_ino', 'st_nlink', 'st_uid', 'st_gid',
              'st_size', 'st_mtime_ns')
    before_info = regular(path)
    before = [getattr(before_info, key) for key in fields]
    before_birth = getattr(before_info, 'st_birthtime_ns', None)
    require(type(before_birth) is int, 'EXPLICIT_NAMED_FILE_BIRTHTIME')
    with path.open('rb') as handle:
        opened = os.fstat(handle.fileno())
        opened_native = [getattr(opened, key) for key in fields]
        opened_birth = getattr(opened, 'st_birthtime_ns', None)
        require([getattr(opened, key) for key in shared]
                == [getattr(before_info, key) for key in shared]
                and type(opened_birth) is int and opened_birth == before_birth
                and stat.S_IFMT(opened.st_mode) == stat.S_IFMT(before_info.st_mode) == stat.S_IFREG,
                'SAME_NAMED_OPEN_FILE')
        raw = handle.read()
        require(handle.read(1) == b'' and len(raw) == opened.st_size, 'COMPLETE_EOF_READ')
        final = os.fstat(handle.fileno())
        final_birth = getattr(final, 'st_birthtime_ns', None)
        require([getattr(final, key) for key in fields] == opened_native
                and type(final_birth) is int and final_birth == opened_birth,
                'SAME_OPEN_FILE_AFTER_READ')
    after_info = regular(path)
    after_birth = getattr(after_info, 'st_birthtime_ns', None)
    require([getattr(after_info, key) for key in fields] == before
            and type(after_birth) is int and after_birth == before_birth,
            'SAME_NAMED_FILE_AFTER_READ')
    return raw



def descriptor(path):
    return {'path': Path(path).as_posix(), **pin(read(path))}



def relative(name):
    require(type(name) is str and name and '\\' not in name and ':' not in name
            and not name.startswith('/') and all(x not in ('', '.', '..') for x in name.split('/')), 'CLOSED_RELATIVE_PATH')
    return name



def fixed(value):
    require(type(value) is dict and set(value) == {'path', 'bytes', 'sha256'}, 'FIXED_INPUT_DESCRIPTOR')
    raw = read(value['path'])
    require(pin(raw) == {key: value[key] for key in ('bytes', 'sha256')}, 'FIXED_WHOLE_INPUT')
    return raw



def create(path, raw):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('xb') as handle:
        require(handle.write(raw) == len(raw), 'COMPLETE_NEW_WRITE')
    require(read(path) == raw, 'NEW_WRITE_READBACK')



def put(path, value):
    create(path, (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + '\n').encode('utf-8'))



def tree(root, exclude_cache=False):
    root = Path(root)
    regular(root, True)
    rows, directories = {}, []
    for directory, children, files in os.walk(root, followlinks=False):
        regular(directory, True)
        for name in children:
            regular(Path(directory) / name, True)
        if exclude_cache:
            children[:] = [name for name in children if name not in ('.cache', '.tmp', '.git')]
        children.sort()
        for name in children:
            directories.append((Path(directory) / name).relative_to(root).as_posix())
        for name in sorted(files):
            path = Path(directory) / name
            rows[path.relative_to(root).as_posix()] = pin(read(path))
    require(len({x.casefold() for x in [*rows, *directories]}) == len(rows) + len(directories), 'NO_CASE_OR_FILE_DIRECTORY_COLLISION')
    return {'files': dict(sorted(rows.items())), 'directories': sorted(directories)}



def identities(root, names):
    return {name: native9(Path(root) / relative(name)) for name in names}



def native(context, label, argv, cwd=None, env=None, allow_failure=False, allow_stderr=False):
    require(re.fullmatch('[a-z0-9][a-z0-9-]*', label) is not None, 'FINITE_NATIVE_LABEL')
    require(type(argv) is list and argv and all(type(x) is str and '\0' not in x for x in argv), 'EXACT_NATIVE_ARGV')
    evidence = context['evidence']
    paths = {key: evidence / (label + suffix) for key, suffix in
             [('created', '.created.json'), ('stdout', '.stdout'), ('stderr', '.stderr'), ('terminal', '.terminal.json')]}
    require(not any(p.exists() or p.is_symlink() for p in paths.values()), 'NEW_NATIVE_RECORD_ONLY')
    started = time.time_ns()
    with paths['stdout'].open('xb') as out, paths['stderr'].open('xb') as err:
        process = subprocess.Popen(argv, cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                                   stdout=out, stderr=err, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        require(type(process.pid) is int and process.pid > 0, 'REAL_CREATED_CHILD_PID')
        put(paths['created'], {'pid': process.pid, 'argv': argv, 'cwd': None if cwd is None else str(cwd), 'started_ns': started})
        waited = process.wait()
        code = process.returncode
    require(type(code) is int and type(waited) is int and code == waited, 'SAME_HANDLE_NATIVE_WAIT')
    stdout, stderr = read(paths['stdout']), read(paths['stderr'])
    terminal = {'pid': process.pid, 'argv': argv, 'native_exit': code, 'wait_returncode': waited,
                'started_ns': started, 'finished_ns': time.time_ns(),
                'stdout': descriptor(paths['stdout']), 'stderr': descriptor(paths['stderr'])}
    put(paths['terminal'], terminal)
    context['native'].append(terminal)
    require(allow_failure or code == 0, 'NATIVE_' + label)
    require(allow_stderr or stderr == b'', 'NATIVE_STDERR_' + label)
    return stdout, terminal



def git(context, label, repo, args):
    return native(context, label, ['git', '--no-optional-locks', '-c', 'safe.directory=' + Path(repo).as_posix(),
                                  '-C', str(repo), *args], env=dict(os.environ, GIT_OPTIONAL_LOCKS='0'))[0]



def qualification(pins_sha256):
    # This first guard precedes all mkdir, source export and child creation.
    require(QUALIFIED_INPUTS_PIN is not None, 'FINAL_ROOT_SOURCE_VAL_FULLTREE_DEPENDENCIES_UNBOUND')
    raw = fixed(QUALIFIED_INPUTS_PIN)
    q = json.loads(raw)
    require(set(q) == {'schema', 'scope', 'sealed', 'head', 'parent', 'source_repo', 'validation_repo',
                       'full_tree', 'source_authority', 'dependency_authority', 'npm_runtime_authority', 'output_parent'}, 'ROOT_QUALIFICATION_CLOSED_FIELDS')
    require(type(q['schema']) is int and q['schema'] == 1 and q['scope'] == 'ROOT_SAME_CLEAN_HEAD_THREE_BUILD_QUALIFICATION'
            and q['sealed'] is True and re.fullmatch('[a-f0-9]{40}', q['head'] or '') is not None
            and re.fullmatch('[a-f0-9]{40}', q['parent'] or '') is not None, 'ROOT_FINAL_CLEAN_HEAD_AUTHORITY')
    source, val = Path(q['source_repo']), Path(q['validation_repo'])
    require(source.is_absolute() and val.is_absolute() and source.resolve() != val.resolve(), 'INDEPENDENT_SOURCE_VAL_ROOTS')
    require(Path(q['output_parent']).resolve() == RUN_PARENT.resolve(), 'ONLY_NEW_BUILD_RUN_PARENT')
    fixed(q['source_authority'])
    require(set(q['dependency_authority']) == {'admin', 'merchant', 'h5'}, 'EXACT_THREE_DEPENDENCY_AUTHORITIES')
    manifest = json.loads(fixed(q['full_tree']))
    require(set(manifest) == {'head', 'files', 'directories'} and manifest['head'] == q['head'], 'FIXED_FULL_TRACKED_TREE')
    require(type(manifest['files']) is list and manifest['files'] and type(manifest['directories']) is list, 'FULL_TREE_LISTS')
    rows = {}
    for row in manifest['files']:
        require(set(row) == {'path', 'bytes', 'sha256', 'git_blob1', 'git_mode'}, 'FULL_TRACKED_BLOB_ROW')
        name = relative(row['path'])
        require(name not in rows and row['git_mode'] in ('100644', '100755')
                and type(row['bytes']) is int and row['bytes'] >= 0
                and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None
                and re.fullmatch('[a-f0-9]{40}', row['git_blob1']) is not None, 'EXACT_REGULAR_GIT_BLOB')
        require(name != 'crmeb/.env' and not name.endswith('/install.lock'), 'NO_MUTABLE_RUNTIME_CONFIG')
        rows[name] = row
    require(list(rows) == sorted(rows) and len({x.casefold() for x in rows}) == len(rows), 'COMPLETE_SORTED_TRACKED_NAMESPACE')
    raw_pins = read(ROOT / 'PINS.json')
    require(hashlib.sha256(raw_pins).hexdigest() == pins_sha256, 'ROOT_REVIEWED_SOURCE_PACKET_PIN')
    pins = json.loads(raw_pins)
    require(pins['scope'] == 'SOURCE_ONLY_NOT_BUILD_OR_RUNTIME_SEAL', 'SOURCE_PACKET_SCOPE')
    packet = tree(ROOT)
    require(set(packet['files']) == set(pins['files']) | {'PINS.json'}
            and packet['directories'] == pins['directories'], 'COMPLETE_SOURCE_PACKET_NAMESPACE')
    for name, expected in pins['files'].items():
        require(packet['files'][name] == expected, 'COMPLETE_SOURCE_PACKET_BYTES')
    q['_rows'], q['_tree'], q['_packet'], q['_pins_raw'] = rows, manifest, packet, raw_pins
    q['_packet_native9'] = identities(ROOT, packet['files'])
    return q



def repo_snapshot(context, phase):
    q = context['qualification']
    roots, gitdirs = {}, []
    for role in ('source_repo', 'validation_repo'):
        tag = role.replace('_', '-')
        repo = Path(q[role])
        regular(repo, True)
        require(git(context, phase + '-' + tag + '-head', repo, ['rev-parse', 'HEAD']).decode().strip() == q['head'], 'SAME_FINAL_HEAD')
        require(git(context, phase + '-' + tag + '-parent', repo, ['rev-parse', 'HEAD^']).decode().strip() == q['parent'], 'SAME_FINAL_PARENT')
        require(git(context, phase + '-' + tag + '-status', repo, ['status', '--porcelain=v1', '--untracked-files=all']) == b'', 'BOTH_REPOSITORIES_CLEAN')
        gd = git(context, phase + '-' + tag + '-gitdir', repo, ['rev-parse', '--absolute-git-dir']).decode().strip()
        gitdirs.append(Path(gd).resolve())
        if role == 'validation_repo':
            require(git(context, phase + '-val-detached', repo, ['rev-parse', '--abbrev-ref', 'HEAD']).strip() == b'HEAD', 'DETACHED_VALIDATION_ROOT')
        listing = git(context, phase + '-' + tag + '-ls-tree', repo, ['ls-tree', '-rz', '--full-tree', q['head']])
        observed = {}
        for item in listing.rstrip(b'\0').split(b'\0'):
            info, name = item.split(b'\t', 1)
            mode, kind, blob = info.decode().split(' ')
            require(kind == 'blob', 'NO_SUBMODULE_OR_LINK_SOURCE')
            observed[name.decode('utf-8')] = (mode, blob)
        require(observed == {name: (row['git_mode'], row['git_blob1']) for name, row in q['_rows'].items()}, 'ACTUAL_COMPLETE_GIT_BLOB_NAMESPACE')
        files, dirs, states = {}, [], {}
        for directory, children, names in os.walk(repo, followlinks=False):
            regular(directory, True)
            if Path(directory) == repo:
                children[:] = [x for x in children if x != '.git']
                names = [x for x in names if x != '.git']
            for child in children:
                regular(Path(directory) / child, True)
                dirs.append((Path(directory) / child).relative_to(repo).as_posix())
            for leaf in names:
                name = (Path(directory) / leaf).relative_to(repo).as_posix()
                require(name in q['_rows'], 'NO_EXTRA_IGNORED_SOURCE_FILE')
                raw = read(repo / name)
                row = q['_rows'][name]
                require(pin(raw) == {key: row[key] for key in ('bytes', 'sha256')}
                        and hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == row['git_blob1'], 'EVERY_FULL_BODY_GIT_BLOB1_SHA256')
                files[name], states[name] = pin(raw), native9(repo / name)
        require(set(files) == set(q['_rows']) and sorted(dirs) == q['_tree']['directories'], 'FULL_PHYSICAL_TRACKED_NAMESPACE_AND_DIRECTORIES')
        roots[role] = {'files': files, 'native9': states,
                       'directories_native9': {'': native9(repo), **identities(repo, sorted(dirs))}}
    require(gitdirs[0] != gitdirs[1], 'SEPARATE_REAL_GIT_DIRECTORIES')
    if '_r61_gitdirs' in q:
        require(gitdirs == [Path(value) for value in q['_r61_gitdirs']], 'R61_CAPTURE_MARKERS_JOIN_ACTUAL_NATIVE_GITDIRS')
        require(all(roots[role]['native9'] == q['_r61_native9'][role] for role in ('source_repo', 'validation_repo')),
                'R61_COMPLETE_CAPTURE_NATIVE_NINE_JOINS_FRESH_SOURCE_READ')
    return roots



def preserved(context, initial):
    current = repo_snapshot(context, 'after')
    require(current == initial, 'BOTH_FULL_SOURCE_TREES_BYTES_NATIVE9_UNCHANGED')
    q = context['qualification']
    require(tree(ROOT) == q['_packet'] and read(ROOT / 'PINS.json') == q['_pins_raw']
            and identities(ROOT, q['_packet']['files']) == q['_packet_native9'], 'SOURCE_PACKET_UNCHANGED')



def dist_tree(path):
    value = tree(path)
    require('index.html' in value['files'] and value['files']['index.html']['bytes'] > 0, 'GENUINE_COMPLETE_DIST_INDEX')
    return {'files': [{'path': name, **row} for name, row in value['files'].items()],
            'directories': value['directories']}

def adapter_receipt(path, guards, manifest):
    receipt = json.loads(read(path))
    passes, emitted = receipt['passes'], receipt['after_emit_inventories']
    generated, callbacks = receipt['generated_modules'], receipt['callback_observations']
    require(receipt['schema'] == 1 and receipt['status'] == 'SECOND_PASS_OBSERVED'
            and receipt['factory_count'] == 1 and receipt['callback_count'] == 1
            and receipt['first_callback_success'] is True and receipt['explicit_invalidations'] == 1
            and receipt['purged_module_count'] == 8 and len(passes) == 2
            and [x['pass'] for x in passes] == [1, 2]
            and all(x['has_errors'] is False and x['asset_count'] > 0 for x in passes)
            and receipt['after_emit_passes'] == [2] and len(emitted) == 1 and emitted[0]['pass'] == 2
            and len(generated) == 8 and all(type(x['bytes']) is int and x['bytes'] > 0
                and re.fullmatch('[a-f0-9]{64}', x['sha256']) is not None for x in generated)
            and receipt['source_guards'] == guards and receipt['phase_counts']['failed'] == 0
            and receipt['error_observations'] == [] and len(callbacks) == 1
            and callbacks[0]['index'] == 1 and callbacks[0]['error_present'] is False
            and receipt['failure_close_requested'] is False and receipt['native_failure_exit_requested'] is False,
            'ORIGINAL_FULL_TWO_PASS_CALLBACK_AND_EIGHT_COMPILER_GUARDS')
    by_path = {row['path']: row for row in manifest['files']}
    names = sorted(by_path, key=lambda value: value.encode('utf-16-be'))
    signature = ''.join(name + '\0' + str(by_path[name]['bytes']) + '\0' + by_path[name]['sha256'] + '\n' for name in names)
    digest = hashlib.sha256(signature.encode('utf-8')).hexdigest()
    require(emitted[0]['file_count'] == len(by_path)
            and emitted[0]['bytes'] == sum(x['bytes'] for x in by_path.values())
            and emitted[0]['manifest_sha256'] == digest and 'index.html' in by_path,
            'EXACT_ORDINAL_PATH_NUL_BYTES_NUL_SHA_LF_AFTER_EMIT_INVENTORY')
    return {'receipt_sha256': pin(read(path))['sha256'], 'dist_inventory_sha256': digest,
            'passes': 2, 'after_emit_passes': [2]}


INPUT_WINDOWS = {}


def tracked(path, expected=None):
    path = Path(path)
    require(path.is_absolute() and '..' not in path.parts, 'ABSOLUTE_PUBLIC_INPUT')
    before = native9(path)
    raw = read(path)
    value = {'body': pin(raw), 'native9': native9(path)}
    require(value['native9'] == before, 'INPUT_WINDOW_STABLE')
    if expected is not None:
        require(set(expected) == {'path', 'bytes', 'sha256'} and Path(expected['path']) == path
                and type(expected['bytes']) is int and expected['bytes'] >= 0
                and type(expected['sha256']) is str and re.fullmatch('[a-f0-9]{64}', expected['sha256'])
                and value['body'] == {key: expected[key] for key in ['bytes', 'sha256']}, 'COMPLETE_ORIGINAL_DESCRIPTOR')
    key = path.as_posix()
    require(key not in INPUT_WINDOWS or INPUT_WINDOWS[key] == value, 'SAME_ORIGINAL_INPUT_WINDOW')
    INPUT_WINDOWS[key] = value
    return raw


def pairs(items):
    result = {}
    for key, value in items:
        require(key not in result, 'NO_DUPLICATE_JSON_KEYS')
        result[key] = value
    return result


def document(path):
    return json.loads(tracked(path), object_pairs_hook=pairs)


def replace_once(raw, before, after):
    require(raw.count(before) == 1, 'EXACT_REVIEWED_BUILDER_LITERAL')
    return raw.replace(before, after, 1)


def builder_profile():
    path = ROOT / 'R61-SOURCE-DERIVATION.json'
    if not path.exists():
        return {'copy4': False, 'lx': False, 'declaration': None}
    value = document(path)
    require(type(value) is dict and value.get('schema') == 1
            and value.get('scope') == 'R61_CURRENT_THREE_FRONTEND_BUILD_SOURCE_ONLY'
            and value.get('status') == 'SOURCE_CANDIDATE_PENDING_INDEPENDENT_PACKET_REVIEW',
            'EXPLICIT_R61_BUILDER_DERIVATION')
    workers, lx = value.get('windows_copy_workers', 1), value.get('windows_lx_alias_attributes', False)
    require(type(workers) is int and workers in (1, 4) and type(lx) is bool and (not lx or workers == 4),
            'ONLY_REVIEWED_LEGACY_COPY4_AND_COPY4_LX_PROFILES')
    if workers == 4:
        require(value.get('windows_copy_buffersize') == 4
                and value.get('windows_copy_whole_file_inverse_exact') is True, 'DECLARED_REVIEWED_COPY4_PROFILE')
    if lx:
        require(value.get('lx_alias_whole_file_inverse_exact') is True
                and value.get('raw_attribute_values') == [1056, 263200]
                and value.get('stable_alias_attributes') == 1056
                and value.get('raw_attribute_receipts') == ['h5-stylus-before-alias-attributes.json',
                                                          'h5-stylus-after-alias-attributes.json'],
                'DECLARED_FOUR_FIXED_LX_ALIAS_PROFILE')
    return {'copy4': workers == 4, 'lx': lx, 'declaration': value}


def reviewed_builder_body(name, raw, profile):
    original = raw
    changes = []
    if name == 'windows_build.py' and profile['copy4']:
        require(pin(raw) == {'bytes': 11228,
                'sha256': '0f9a5778a660cb89bf3d77b9234e20c8d5e31060586eb651f824502519e53c11'},
                'EXACT_REVIEWED_COPY4_WHOLE_BODY')
        changes = [(b"    for name, expected in before['files'].items():\n        raw = read(cache / 'node_modules' / name)\n        require(pin(raw) == expected, 'EVERY_ORIGINAL_MODULE_BEFORE_COPY')\n        create(destination / name, raw)\n        require((destination / name).stat().st_nlink == 1, 'NO_LINKED_MODULE_REUSE')\n", b"    def copy_one(item):\n        name, expected = item\n        raw = read(cache / 'node_modules' / name)\n        require(pin(raw) == expected, 'EVERY_ORIGINAL_MODULE_BEFORE_COPY')\n        create(destination / name, raw)\n        require((destination / name).stat().st_nlink == 1, 'NO_LINKED_MODULE_REUSE')\n    from concurrent.futures import ThreadPoolExecutor\n    executor = ThreadPoolExecutor(max_workers=4)\n    try:\n        for _ in executor.map(copy_one, before['files'].items(), buffersize=4):\n            pass\n    finally:\n        executor.shutdown(wait=True, cancel_futures=True)\n")]
    elif name == 'h5_build.py' and profile['lx']:
        require(pin(raw) == {'bytes': 19341,
                'sha256': 'ab6dafd5f1beccc70a512887a9a3449a250008be0045aac84b4476460e210350'},
                'EXACT_REVIEWED_LX_WHOLE_BODY')
        changes = [(b'    from build_common import native9, regular, relative\n', b'    from build_common import native9, regular, relative, put\n'), (b'    files, directories, links, alias_states, directory_states = {}, [], {}, {}, {}\n', b"    files, directories, links, alias_states, directory_states = {}, [], {}, {}, {}\n    require(os.name == 'nt', 'ONLY_WINDOWS_FIXED_LX_ALIAS_ATTRIBUTES')\n    raw_alias_attributes = {}\n"), (b"                    and getattr(facts, 'st_file_attributes', 0) & 1024\n", b"                    and getattr(facts, 'st_file_attributes', None) in (1056, 263200)\n"), (b"                    and getattr(after, 'st_file_attributes', None) == getattr(facts, 'st_file_attributes', None),\n", b"                    and getattr(after, 'st_file_attributes', None) in (1056, 263200),\n"), (b'            links[name] = expected_links[name]\n', b"            raw_alias_attributes[name] = {'before': facts.st_file_attributes, 'after': after.st_file_attributes,\n                'native9': before, 'birthtime_ns': birth, 'reparse_tag': facts.st_reparse_tag, 'reparse_query': pin(query)}\n            links[name] = expected_links[name]\n"), (b"                'reparse_tag': facts.st_reparse_tag, 'file_attributes': facts.st_file_attributes,\n", b"                'reparse_tag': facts.st_reparse_tag, 'file_attributes': 1056,\n"), (b"    return {'tree': value, 'native9': identities(STYLUS, value['files']),\n", b"    put(context['evidence'] / ('h5-stylus-' + phase + '-alias-attributes.json'),\n        {'schema': 1, 'scope': 'FOUR_FIXED_WINDOWS_LX_ALIAS_RAW_ATTRIBUTES', 'root': STYLUS.as_posix(), 'phase': phase, 'aliases': raw_alias_attributes})\n    return {'tree': value, 'native9': identities(STYLUS, value['files']),\n")]
    for before, after in reversed(changes):
        raw = replace_once(raw, after, before)
    require(pin(raw) == REVIEWED_BUILDER_BODIES[name],
            'ORIGINAL_REVIEWED_BUILDER_BODY_NOT_HASH_ONLY_SUBSTITUTE')
    forward = raw
    for before, after in changes:
        forward = replace_once(forward, before, after)
    require(forward == original, 'REVIEWED_BUILDER_WHOLE_FORWARD_INVERSE')


def fixed_document(value):
    return json.loads(tracked(value['path'], value), object_pairs_hook=pairs)


def source_update(value, inventory):
    require(type(value) is dict and set(value) == {'before', 'after'}, 'EXACT_REVIEWED_SOURCE_UPDATE')
    for side in ('before', 'after'):
        row = value[side]
        require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
                and type(row['bytes']) is int and row['bytes'] >= 0
                and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256'])
                and relative(row['path']) == row['path'], 'TYPED_REVIEWED_SOURCE_UPDATE')
    after = value['after']
    require(value['before']['path'] == after['path'] and after == {
        key: inventory[after['path']][key] for key in ['path', 'bytes', 'sha256']},
        'SOURCE_UPDATE_JOINS_ACTUAL_CAPTURED_BYTES')


def builder_derivation_join(q):
    value = q['_builder_profile']['declaration']
    if value is None:
        return
    require(value['source_sha'] == q['head'] and value['parent'] == q['parent']
            and value['source_repo'] == q['source_repo'] and value['validation_repo'] == q['validation_repo']
            and value['full_tree'] == q['full_tree'] and value['qualification'] == descriptor(ROOT / 'QUALIFICATION.json')
            and value['file_count'] == len(q['_rows']) and value['directory_count'] == len(q['_tree']['directories'])
            and value['dependency_authority_preserved'] == q['dependency_authority']
            and value['npm_runtime_authority_preserved'] == q['npm_runtime_authority']
            and value['output_parent_preserved'] == q['output_parent'], 'R61_DECLARATION_JOINS_COMPLETE_QUALIFICATION')


def r61_source_facts(q, authority):
    fields = {'schema', 'scope', 'status', 'source_sha', 'candidate_root', 'validation_root',
              'capture', 'independent_review', 'baseline_profile'}
    require(type(authority) is dict and set(authority) == fields and type(authority['schema']) is int
            and authority['schema'] == 1 and authority['scope'] == 'R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY'
            and authority['status'] == 'INDEPENDENTLY_REVIEWED_SOURCE_ONLY'
            and authority['source_sha'] == q['head']
            and authority['candidate_root'] == Path(q['source_repo']).as_posix()
            and authority['validation_root'] == Path(q['validation_repo']).as_posix(), 'CLOSED_R61_SOURCE_AUTHORITY')
    capture = fixed_document(authority['capture']); review = fixed_document(authority['independent_review'])
    require(capture['schema'] == 1 and capture['status'] == 'CAPTURED_NOT_APPROVED'
            and capture['source_approval'] == 'NOT_GRANTED' and capture['source_sha'] == q['head']
            and capture['candidate'] == authority['candidate_root'] and capture['validation'] == authority['validation_root']
            and capture['source_parents'] == [q['parent']], 'CAPTURE_JOINS_SAME_SOURCE_VAL_HEAD_PARENT')
    required = {'schema', 'status', 'source_sha', 'capture', 'candidate_root', 'validation_root', 'baseline_profile',
                'all_tracked_and_delta_reviewed', 'active_normal_sources_reviewed', 'paid_consumer_helper_update',
                'active_profile_updates', 'customer_form_helper_update'}
    require(type(review) is dict and required <= set(review) and review['schema'] == 1
            and review['status'] == 'PASS_CURRENT_SOURCE_TECHNICAL_REVIEW'
            and all(review[key] == authority[key] for key in
                    ['source_sha', 'capture', 'candidate_root', 'validation_root', 'baseline_profile'])
            and review['all_tracked_and_delta_reviewed'] is True and review['active_normal_sources_reviewed'] is True,
            'FIXED_INDEPENDENT_SOURCE_REVIEW_TWELVE_FIELDS')
    require(set(capture['files']) == {'SOURCE-INVENTORY.jsonl', 'BASELINE-INVENTORY.jsonl', 'SOURCE-DELTA.json'},
            'EXACT_THREE_CAPTURE_INPUTS')
    def rows(name):
        raw = tracked(capture['files'][name]['path'], capture['files'][name])
        values = [json.loads(line, object_pairs_hook=pairs) for line in raw.splitlines()]
        require(values and [row['path'] for row in values] == sorted({row['path'] for row in values}),
                'COMPLETE_SORTED_CAPTURED_ROWS')
        return {row['path']: row for row in values}
    current, baseline = rows('SOURCE-INVENTORY.jsonl'), rows('BASELINE-INVENTORY.jsonl')
    simple = {name: {key: row[key] for key in ['path', 'git_mode', 'git_blob', 'bytes', 'sha256']}
              for name, row in current.items()}
    expected = {name: {**{key: row[key] for key in ['path', 'bytes', 'sha256', 'git_mode']},
                       'git_blob1': row['git_blob']} for name, row in simple.items()}
    directories = sorted({parent.as_posix() for name in current for parent in Path(name).parents
                          if parent.as_posix() != '.'})
    require(expected == q['_rows'] and directories == q['_tree']['directories']
            and len(current) == capture['tracked_file_count'] and len(baseline) == capture['baseline_file_count'],
            'COMPLETE_CAPTURE_FULLTREE_MODE_BLOB_BODY_DIRECTORY_JOIN')
    delta = fixed_document(capture['files']['SOURCE-DELTA.json'])
    changed = [{'path': name, 'kind': 'ADD' if name not in baseline else 'DELETE' if name not in simple else 'MODIFY',
                'before': baseline.get(name), 'after': simple.get(name)}
               for name in sorted(set(simple) | set(baseline)) if simple.get(name) != baseline.get(name)]
    require(delta == {'schema': 1, 'baseline_sha': capture['baseline_sha'], 'source_sha': q['head'],
                      'status': 'CAPTURED_NOT_APPROVED', 'files': changed}
            and len(changed) == capture['delta_file_count'], 'COMPLETE_CAPTURE_BASELINE_DELTA_JOIN')
    profile_name = 'help/audit-regressions/orchestration/sources/integrated-normal-and-b-source-tree-source-r3/PINS.json'
    profile_pin = {'bytes': 93140, 'sha256': '67c9baf29f98230cd7a1e0bdd70157f2b3d1ac30b48adab6e241a84f51995b62'}
    require({key: authority['baseline_profile'][key] for key in ['bytes', 'sha256']} == profile_pin
            and {key: current[profile_name][key] for key in ['bytes', 'sha256']} == profile_pin
            and pin(tracked(Path(q['source_repo']) / profile_name)) == profile_pin,
            'FIXED_BASELINE_PROFILE_JOINS_QUALIFIED_SOURCE')
    source_update(review['paid_consumer_helper_update'], current)
    updates = review['active_profile_updates']
    require(type(updates) is list and len(updates) == 4 and [row['after']['path'] for row in updates] == [
        'help/audit-regressions/isolated/ms4-aftersale-review-normal.php',
        'help/audit-regressions/isolated/ms4-customer-http-normal.php',
        'help/audit-regressions/isolated/ms4-fulfillment-normal.php',
        'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php'], 'FIXED_FOUR_ACTIVE_SOURCE_UPDATES')
    for value in updates:
        source_update(value, current)
    if review['customer_form_helper_update'] is not None:
        source_update(review['customer_form_helper_update'], current)
    gitdirs, captured_native = [], {}
    for role, label in [('source_repo', 'candidate'), ('validation_repo', 'validation')]:
        repo = Path(q[role]); marker = tracked(repo / '.git').decode('utf-8')
        require(marker.startswith('gitdir: ') and len(marker.splitlines()) == 1 and '\0' not in marker,
                'EXACT_WORKTREE_GITDIR_MARKER')
        value = Path(marker[8:].strip()); gitdir = (value if value.is_absolute() else repo / value).resolve(strict=True)
        regular(gitdir, True); gitdirs.append(gitdir.as_posix()); captured_native[role] = {}
        for name, row in current.items():
            observed = row['checkouts'][label]
            require(observed['path'] == (repo / name).as_posix()
                    and all(observed[key] == row[key] for key in ['bytes', 'sha256'])
                    and type(observed['stat9']) is list and len(observed['stat9']) == 9
                    and all(type(number) is int for number in observed['stat9']), 'CAPTURED_SOURCE_NATIVE_NINE')
            captured_native[role][name] = observed['stat9']
    require(gitdirs[0] != gitdirs[1], 'DISTINCT_CAPTURED_SOURCE_VAL_GITDIRS')
    q['_r61_native9'], q['_r61_gitdirs'] = captured_native, gitdirs
    declaration = q['_builder_profile']['declaration']
    require(type(declaration) is dict and declaration['technical_authority'] == q['source_authority']
            and declaration['capture'] == authority['capture']
            and declaration['independent_source_review'] == authority['independent_review']
            and declaration['actual_git_inventory'] == capture['files']['SOURCE-INVENTORY.jsonl'],
            'R61_DERIVATION_FIXED_AUTHORITY_CAPTURE_REVIEW_JOIN')
    return {'source_sha': q['head'], 'Source': q['source_repo'], 'Val': q['validation_repo'],
            'file_count_each': len(current), 'complete_bytes_each': sum(row['bytes'] for row in current.values()),
            'directory_count': len(directories), 'Gitdirs': gitdirs}


def verify_lx_alias_inputs(q, build):
    if not q['_builder_profile']['lx']:
        return
    run = build['run']; preservation = document(run / 'NATIVE-INPUT-PRESERVATION.json')
    dependencies = fixed_document(q['dependency_authority']['h5']); stylus = dependencies['compile-stylus']
    root = Path(stylus['root']); names = {'node_modules/.bin/' + name for name in ['atob', 'mkdirp', 'semver', 'stylus']}
    sealed = preservation['compiler_dependencies']['compile-stylus']
    require(set(sealed['alias_identities']) == set(stylus['links']) == names, 'ONLY_FOUR_ORIGINAL_LX_ALIASES')
    operations = {Path(row['stdout']['path']).name.removesuffix('.stdout'): row for row in build['report']['native_operations']}
    phases = {}
    for phase in ['before', 'after']:
        value = document(run / 'evidence' / ('h5-stylus-' + phase + '-alias-attributes.json'))
        require(type(value) is dict and set(value) == {'schema', 'scope', 'root', 'phase', 'aliases'}
                and type(value['schema']) is int and value['schema'] == 1
                and value['scope'] == 'FOUR_FIXED_WINDOWS_LX_ALIAS_RAW_ATTRIBUTES'
                and value['root'] == root.as_posix() and value['phase'] == phase
                and type(value['aliases']) is dict and set(value['aliases']) == names, 'EXACT_LX_ALIAS_RAW_RECEIPT')
        stable = {}
        for name, row in value['aliases'].items():
            state = sealed['alias_identities'][name]
            require(type(row) is dict and set(row) == {'before', 'after', 'native9', 'birthtime_ns', 'reparse_tag', 'reparse_query'}
                    and type(row['before']) is int and type(row['after']) is int
                    and row['before'] in (1056, 263200) and row['after'] in (1056, 263200)
                    and type(row['native9']) is list and len(row['native9']) == 9
                    and all(type(number) is int for number in row['native9'])
                    and row['native9'] == state['native9'] and type(row['birthtime_ns']) is int
                    and row['birthtime_ns'] == state['birthtime_ns']
                    and row['reparse_tag'] == state['reparse_tag'] == 0xA000001D
                    and state['file_attributes'] == 1056
                    and row['reparse_query'] == state['reparse_query'] == stylus['links'][name]['reparse_query'],
                    'LX_RAW_VALUES_JOIN_COMPLETE_ORIGINAL_STABLE_IDENTITY')
            label = 'h5-stylus-' + phase + '-alias-' + Path(name).name
            observed_label, created, stdout, stderr = original_native(operations[label], run / 'evidence')
            require(observed_label == label and created['cwd'] is None
                    and created['argv'] == ['C:/Windows/System32/fsutil.exe', 'reparsepoint', 'query', str(root / name)]
                    and stderr == b'' and pin(stdout) == row['reparse_query'], 'LX_RAW_RECEIPT_JOINS_ORIGINAL_NATIVE_QUERY')
            stable[name] = {key: row[key] for key in ['native9', 'birthtime_ns', 'reparse_tag', 'reparse_query']}
        phases[phase] = stable
    require(phases['before'] == phases['after'], 'LX_BOTH_PHASES_COMPLETE_STABLE_IDENTITY_HELD')


def reviewed_qualification(folder, expected_pins, tool_review_file=None, tool_review_sha256=None):
    global ROOT, QUALIFIED_INPUTS_PIN
    ROOT = Path(folder)
    require(ROOT.is_absolute() and ROOT.resolve() == ROOT, 'REAL_REVIEWED_BUILD_SOURCE')
    require(re.fullmatch('[a-f0-9]{64}', expected_pins) is not None, 'EXPLICIT_ROOT_REVIEWED_PACKET_PIN')
    binding = None
    profile = builder_profile()
    for name, expected in REVIEWED_BUILDER_BODIES.items():
        raw = tracked(ROOT / name)
        if name == 'build_common.py':
            lines = re.findall(rb'^QUALIFIED_INPUTS_PIN = ([^\n]*)\n', raw, re.M)
            require(len(lines) == 1, 'ONE_FINITE_BUILD_BINDING')
            # Data only: never import/exec builder code or evaluate a Python expression.
            binding = ast.literal_eval(lines[0].decode('utf-8'))
            require(type(binding) is dict and set(binding) == {'path', 'bytes', 'sha256'}
                    and type(binding['path']) is str and type(binding['bytes']) is int and binding['bytes'] > 0
                    and type(binding['sha256']) is str and re.fullmatch('[a-f0-9]{64}', binding['sha256'])
                    and Path(binding['path']) == ROOT / 'QUALIFICATION.json', 'QUALIFIED_DATA_LITERAL_ONLY')
            raw = re.sub(rb'^QUALIFIED_INPUTS_PIN = [^\n]*\n', b'QUALIFIED_INPUTS_PIN = None\n', raw, flags=re.M)
        reviewed_builder_body(name, raw, profile)
    QUALIFIED_INPUTS_PIN = binding
    q = qualification(expected_pins)
    q['_builder_profile'] = profile
    builder_derivation_join(q)
    require(document(ROOT / 'QUALIFICATION.json') == {key: value for key, value in q.items() if not key.startswith('_')},
            'QUALIFICATION_FULL_DATA_MATCH')
    own_path = Path(__file__).resolve()
    own = tracked(own_path)
    own_row = q['_rows'].get('help/docker/prepare-marketplace-public.py')
    require(type(own_row) is dict, 'QUALIFIED_SOURCE_HAS_ORIGINAL_COPIER')
    require((tool_review_file is None) == (tool_review_sha256 is None), 'COPIER_TOOL_REVIEW_FILE_AND_SHA_TOGETHER')
    tool = {'body': {'path': own_path.as_posix(), **pin(own)}, 'review': None}
    if tool_review_file is None:
        require(pin(own) == {key: own_row[key] for key in ['bytes', 'sha256']},
                'COPIER_BELONGS_TO_SAME_QUALIFIED_SOURCE')
    else:
        review_path = Path(tool_review_file)
        require(review_path.is_absolute() and review_path.resolve() == review_path
                and type(tool_review_sha256) is str and re.fullmatch('[a-f0-9]{64}', tool_review_sha256),
                'EXPLICIT_ROOT_COPIER_TOOL_REVIEW_PATH_AND_SHA')
        review_raw = tracked(review_path)
        require(pin(review_raw)['sha256'] == tool_review_sha256, 'ROOT_COPIER_TOOL_REVIEW_FULL_SHA')
        review = json.loads(review_raw, object_pairs_hook=pairs)
        fields = {'schema', 'scope', 'status', 'source_head', 'packet', 'before', 'after'}
        require(type(review) is dict and set(review) in (fields, fields | {'tool_git_head'})
                and type(review['schema']) is int and review['schema'] == 1
                and review['scope'] == 'ROOT_REVIEWED_FORMAL_COPIER_TOOL_ONLY'
                and review['status'] == 'REVIEWED_FOR_EXPLICIT_QUALIFIED_SOURCE_AND_PACKET'
                and review['source_head'] == q['head'], 'ROOT_COPIER_TOOL_REVIEW_CLOSED_SOURCE_CONTRACT')
        require('tool_git_head' not in review or (type(review['tool_git_head']) is str
                and re.fullmatch('[a-f0-9]{40}', review['tool_git_head'])), 'ROOT_COPIER_TOOL_GIT_HEAD_AUDIT_FIELD')
        source_tool = Path(q['source_repo']) / 'help/docker/prepare-marketplace-public.py'
        require(review['packet'] == {'path': (ROOT / 'PINS.json').as_posix(), **pin(tracked(ROOT / 'PINS.json'))}
                and review['packet']['sha256'] == expected_pins
                and review['before'] == {'path': source_tool.as_posix(),
                                         **{key: own_row[key] for key in ['bytes', 'sha256']}}
                and review['after'] == tool['body'], 'ROOT_REVIEW_BINDS_EXACT_PACKET_OLD_AND_ACTUAL_TOOL_BODIES')
        tracked(source_tool, review['before'])
        tool['review'] = {'path': review_path.as_posix(), **pin(review_raw)}
    q['_copier_tool'] = tool
    for name, expected in FORMAL_SOURCE_CONFIGS.items():
        row = q['_rows'].get(name)
        require(type(row) is dict and {key: row[key] for key in ['bytes', 'sha256']} == expected
                and pin(tracked(Path(q['source_repo']) / name)) == expected,
                'REVIEWED_FORMAL_BASE_AND_ASSET_DIRECTORY_SOURCE_CONTRACT')
    for frontend, command in [('admin', 'vue-cli-service build --mode=production'),
                              ('merchant', 'vue-cli-service build')]:
        package = document(Path(q['source_repo']) / 'template' / frontend / 'package.json')
        require(package['scripts']['build'] == command, 'ORIGINAL_FORMAL_BUILD_SCRIPT_NO_BASE_OVERRIDE')
    authority = json.loads(tracked(q['source_authority']['path'], q['source_authority']), object_pairs_hook=pairs)
    if type(authority) is dict and (authority.get('scope') == 'R61_CURRENT_NORMAL_SOURCE_TECHNICAL_AUTHORITY'
            or set(authority) == {'schema', 'scope', 'status', 'source_sha', 'candidate_root', 'validation_root',
                                  'capture', 'independent_review', 'baseline_profile'}):
        authority = r61_source_facts(q, authority)
    require(authority['source_sha'] == q['head'] and Path(authority['Source']) == Path(q['source_repo'])
            and Path(authority['Val']) == Path(q['validation_repo'])
            and authority['file_count_each'] == len(q['_rows'])
            and authority['complete_bytes_each'] == sum(row['bytes'] for row in q['_rows'].values())
            and authority['directory_count'] == len(q['_tree']['directories'])
            and len(authority['Gitdirs']) == 2 and Path(authority['Gitdirs'][0]) != Path(authority['Gitdirs'][1]),
            'ACTUAL_SOURCE_AUTHORITY_JOIN_NOT_APPROVED_BOOLEAN')
    return q, authority


def original_native(row, evidence):
    require(type(row) is dict and set(row) == {'pid', 'argv', 'native_exit', 'wait_returncode', 'started_ns',
                                              'finished_ns', 'stdout', 'stderr'}, 'COMPLETE_NATIVE_TERMINAL_SHAPE')
    stdout_path = Path(row['stdout']['path'])
    require(stdout_path.parent == evidence and stdout_path.name.endswith('.stdout'), 'ORIGINAL_EVIDENCE_ROOT')
    label = stdout_path.name[:-7]
    require(re.fullmatch('[a-z0-9][a-z0-9-]*', label) is not None, 'ORIGINAL_FINITE_NATIVE_LABEL')
    require(Path(row['stderr']['path']) == evidence / (label + '.stderr'), 'SAME_ORIGINAL_NATIVE_PAIR')
    created = document(evidence / (label + '.created.json'))
    terminal = document(evidence / (label + '.terminal.json'))
    require(terminal == row and set(created) == {'pid', 'argv', 'cwd', 'started_ns'}
            and type(row['pid']) is int and row['pid'] > 0 and created['pid'] == row['pid']
            and type(row['argv']) is list and row['argv'] and all(type(x) is str and '\0' not in x for x in row['argv'])
            and created['argv'] == row['argv'] and type(row['native_exit']) is int and type(row['wait_returncode']) is int
            and row['native_exit'] == row['wait_returncode'] == 0
            and type(row['started_ns']) is int and row['started_ns'] == created['started_ns']
            and type(row['finished_ns']) is int and row['finished_ns'] >= row['started_ns'], 'ORIGINAL_PID_ARGV_WAIT_ZERO')
    stdout = tracked(stdout_path, row['stdout'])
    stderr = tracked(evidence / (label + '.stderr'), row['stderr'])
    return label, created, stdout, stderr


def original_git(label, item, q, authority):
    row, created, stdout, stderr = item
    require(stderr == b'', 'ORIGINAL_GIT_STDERR_EMPTY')
    role = 'source_repo' if '-source-repo-' in label else 'validation_repo'
    repo = Path(q[role])
    tail = label.split('-' + role.replace('_', '-') + '-', 1)[-1]
    if label.endswith('val-detached'):
        role, repo, tail = 'validation_repo', Path(q['validation_repo']), 'detached'
    commands = {'head': ['rev-parse', 'HEAD'], 'parent': ['rev-parse', 'HEAD^'],
                'status': ['status', '--porcelain=v1', '--untracked-files=all'],
                'gitdir': ['rev-parse', '--absolute-git-dir'], 'ls-tree': ['ls-tree', '-rz', '--full-tree', q['head']],
                'detached': ['rev-parse', '--abbrev-ref', 'HEAD']}
    require(tail in commands and row['argv'] == ['git', '--no-optional-locks', '-c', 'safe.directory=' + repo.as_posix(),
                                                 '-C', str(repo), *commands[tail]], 'ORIGINAL_EXACT_GIT_COMMAND')
    if tail in ('head', 'parent', 'detached'):
        expected = q['head'] if tail == 'head' else q['parent'] if tail == 'parent' else 'HEAD'
        require(stdout.strip() == expected.encode(), 'ORIGINAL_GIT_HEAD_PARENT_DETACHED')
    elif tail == 'status':
        require(stdout == b'', 'ORIGINAL_COMPLETE_CLEAN_STATUS')
    elif tail == 'gitdir':
        require(Path(stdout.decode().strip()) == Path(authority['Gitdirs'][0 if role == 'source_repo' else 1]),
                'ORIGINAL_REAL_SEPARATE_GITDIR')
    else:
        rows = {}
        for entry in stdout.rstrip(b'\0').split(b'\0'):
            info, name = entry.split(b'\t', 1)
            mode, kind, blob = info.decode().split(' ')
            require(kind == 'blob' and name.decode() not in rows, 'ORIGINAL_COMPLETE_GIT_BLOBS')
            rows[name.decode()] = (mode, blob)
        require(rows == {name: (v['git_mode'], v['git_blob1']) for name, v in q['_rows'].items()}, 'ORIGINAL_BUILD_FULL_SOURCE_TREE')


class Resources(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        self.urls += [value for key, value in attrs if (tag == 'script' and key == 'src') or (tag == 'link' and key == 'href')]


def actual_build(frontend, pointer, q, authority):
    require(type(pointer) is dict and set(pointer) == {'run', 'launch'}, 'RUN_PATH_INDEX_NOT_APPROVAL')
    run, launch = Path(pointer['run']), Path(pointer['launch'])
    run_id = run.name.removeprefix(frontend + '-')
    require(re.fullmatch('[0-9]{14}[a-f0-9]{8}', run_id) is not None
            and run == RUN_PARENT / (frontend + '-' + run_id) and launch == RUN_PARENT / (frontend + '-' + run_id + '-launch')
            and run.resolve() == run and launch.resolve() == launch,
            'GENUINE_BUILD_RUN_LOCATIONS')
    regular(run, True); regular(launch, True); regular(run / 'evidence', True)
    require((run / 'evidence').resolve() == run / 'evidence', 'ORIGINAL_EVIDENCE_WITHOUT_ANCESTOR_ALIAS')
    report = document(run / 'BUILD-REPORT.json')
    outer = document(launch / 'RESULT.json')
    require(outer['status'] == 'ACTUAL_ORDINARY_BUILD_VERIFIED' and outer['scope'] == 'ROOT_REAL_BUILD_OUTER_LIFETIME_NOT_BROWSER'
            and report['schema'] == 1 and report['status'] == 'VERIFIED_ORDINARY_OFFLINE_BUILD_NOT_UI_OR_DEPLOYMENT'
            and outer['frontend'] == report['frontend'] == frontend and outer['run_id'] == report['run_id'] == run_id
            and outer['source_head'] == report['source_head'] == q['head']
            and report['full_source_and_val_preserved'] is True and report['installation_or_network_fallback'] is False
            and report['dist_manifest'] == 'DIST-TREE.json' and report['commit_push_deploy'] is False
            and report['HTTP_DOM_devices'] == report['business_UI_devices'] == 'NOT_RUN'
            and report['final_public_copy_four_manifests'] == 'NOT_CREATED_BY_THIS_DRIVER'
            and outer['public_copy_four_manifests'] == 'NOT_CREATED'
            and Path(outer['build_report']) == run / 'BUILD-REPORT.json', 'ACTUAL_BUILD_OUTER_AND_INNER_JOIN')
    label, created, stdout, stderr = original_native(outer['wrapper'], launch)
    require(label == 'wrapper' and Path(created['cwd']) == ROOT
            and outer['wrapper']['argv'] == [PYTHON, '-I', '-B', '-X', 'utf8', str(ROOT / 'root-build.py'), '--frontend', frontend,
                                             '--pins-sha256', q['_copy_pins'], '--run-id', run_id], 'ORIGINAL_BUILD_OUTER_COMMAND')
    printed = json.loads(stdout)
    require(printed == {'status': report['status'], 'frontend': frontend, 'run_id': run_id, 'source_head': q['head']},
            'ORIGINAL_BUILD_STDOUT_FULL_RETURN')
    admission = document(run / 'ADMISSION.json')
    require(admission == {'schema': 1, 'scope': 'ROOT_ORDINARY_BUILD_ADMITTED_NOT_COMPLETE', 'frontend': frontend,
                         'run_id': run_id, 'head': q['head'], 'source_repo': q['source_repo'],
                         'validation_repo': q['validation_repo'], 'source_authority': q['source_authority'], 'full_tree': q['full_tree']},
            'ORIGINAL_BUILD_INPUT_ADMISSION')
    suffixes = ['source-repo-head', 'source-repo-parent', 'source-repo-status', 'source-repo-gitdir', 'source-repo-ls-tree',
                'validation-repo-head', 'validation-repo-parent', 'validation-repo-status', 'validation-repo-gitdir',
                'val-detached', 'validation-repo-ls-tree']
    prefix = ['before-' + x for x in suffixes]
    ending = ['after-' + x for x in suffixes]
    middle = (['node-version', 'npm-version', 'npm-build'] if frontend != 'h5' else
              ['h5-stylus-before-alias-' + x for x in ['atob', 'mkdirp', 'semver', 'stylus']]
              + ['h5-image-inspect', 'h5-container-create', 'h5-compiler-attach', 'h5-container-terminal']
              + ['h5-stylus-after-alias-' + x for x in ['atob', 'mkdirp', 'semver', 'stylus']]
              + ['h5-owned-id-before-remove', 'h5-owned-normal-remove'])
    observed = {}
    labels = []
    for row in report['native_operations']:
        name, c, o, e = original_native(row, run / 'evidence')
        require(name not in observed, 'NO_DUPLICATE_ORIGINAL_NATIVE')
        observed[name] = (row, c, o, e)
        labels.append(name)
    require(labels == prefix + middle + ending, 'ENTIRE_ORIGINAL_BUILD_NATIVE_SEQUENCE')
    for name in prefix + ending:
        original_git(name, observed[name], q, authority)
    source = document(run / 'SOURCE-INPUTS.json')
    source_prefix = 'template/uni-app/' if frontend == 'h5' else 'template/' + frontend + '/'
    expected_source = {name: {key: row[key] for key in ['bytes', 'sha256']} for name, row in q['_rows'].items()
                       if name.startswith(source_prefix) or (frontend == 'admin' and name == 'help/audit-regressions/frontend-source-check.cjs')}
    require(source == expected_source and len(source) == report['source_files'], 'ALL_EXPORTED_SOURCE_INPUTS_SAME_GIT_BODY')
    for name, expected in source.items():
        require(pin(tracked(run / name)) == expected, 'REAL_EXPORTED_BUILD_SOURCE')
    preservation = document(run / 'NATIVE-INPUT-PRESERVATION.json')
    saved_source = preservation['exported_source' if frontend == 'h5' else 'source']
    require(saved_source == identities(run, source), 'ORIGINAL_EXPORTED_SOURCE_NATIVE_IDENTITIES')
    dist_root = Path(report['dist_root'])
    require(dist_root == (run / 'output' if frontend == 'h5' else run / 'template' / frontend / 'dist'), 'ORIGINAL_DIST_LOCATION')
    manifest = document(run / 'DIST-TREE.json')
    require(set(manifest) == {'files', 'directories'} and dist_tree(dist_root) == manifest, 'FULL_PHYSICAL_DIST_AND_EMPTY_DIRECTORIES')
    identity = document(run / 'DIST-NATIVE-IDENTITIES.json')
    require(identity == {'root': native9(dist_root), 'files': identities(dist_root, [x['path'] for x in manifest['files']]),
                         'directories': identities(dist_root, manifest['directories'])}, 'ORIGINAL_DIST_NATIVE_IDENTITIES')
    if frontend != 'h5':
        package = document(run / 'template' / frontend / 'package.json')
        versions = ({'vue': '2.7.16', 'vue-template-compiler': '2.7.16', '@vue/cli-service': '3.12.1',
                     'webpack': '4.47.0', 'axios': '0.18.1', 'uglifyjs-webpack-plugin': '2.2.0'}
                    if frontend == 'admin' else {**package['dependencies'], **package['devDependencies']})
        require(report['versions'] == versions
                and report['source_assembly'] == 'ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_OVERLAY',
                'ORIGINAL_VERSION_AND_SOURCE_ASSEMBLY_CONTRACT')
        require(observed['node-version'][3] == observed['npm-version'][3] == b'', 'ORIGINAL_VERSION_STDERR_EMPTY')
        require(observed['node-version'][2].decode().strip() == 'v22.23.3'
                and observed['npm-version'][2].decode().strip() == '10.9.9'
                and observed['node-version'][0]['argv'] == [str(NODE), '--version']
                and observed['npm-version'][0]['argv'] == [str(NODE), str(NPM), '--version']
                and observed['npm-build'][0]['argv'] == [str(NODE), str(NPM), 'run', 'build']
                and Path(observed['npm-build'][1]['cwd']) == run / 'template' / frontend,
                'REAL_ORIGINAL_NODE_NPM_BUILD_NOT_NEW_BASE_OVERRIDE')
        log = observed['npm-build'][2]
        require(b'prebuild' in log and (b'frontend-source-check.cjs' if frontend == 'admin' else b'scripts/source-check.cjs') in log,
                'ORIGINAL_PREBUILD_FULL_STDOUT')
        require(frontend != 'merchant' or b'"source_checks":"PASS"' in log, 'MERCHANT_ORIGINAL_SOURCE_CHECK')
        parser = Resources(); parser.feed(read(dist_root / 'index.html').decode('utf-8'))
        resources = []
        rows = {x['path']: x for x in manifest['files']}
        for url in parser.urls:
            parsed = urlsplit(url)
            require(not parsed.scheme and not parsed.netloc and parsed.path.startswith('/' + frontend + '/'), 'FORMAL_ORIGINAL_RESOURCE_BASE')
            name = relative(unquote(parsed.path[len(frontend) + 2:]))
            require(name in rows, 'ALL_HTML_RESOURCES_PHYSICALLY_PRESENT')
            resources.append({'url': url, **rows[name]})
        require(resources and resources == report['resources'], 'ORIGINAL_RESOURCE_VALIDATION_JOIN')
        dependency = json.loads(tracked(q['dependency_authority'][frontend]['path'], q['dependency_authority'][frontend]), object_pairs_hook=pairs)
        require(document(run / 'DEPENDENCY-INPUTS.json') == {key: dependency[key] for key in ['files', 'directories']}
                and report['dependency_files'] == len(dependency['files']), 'FULL_RECORDED_DEPENDENCY_INPUTS')
        npm = json.loads(tracked(q['npm_runtime_authority']['path'], q['npm_runtime_authority']), object_pairs_hook=pairs)
        require(set(preservation) == {'source', 'original_modules', 'copied_modules', 'runtime', 'npm_runtime'}
                and set(preservation['original_modules']) == set(preservation['copied_modules']) == set(dependency['files'])
                and set(preservation['npm_runtime']) == set(npm['files'])
                and set(preservation['runtime']) == {str(NODE), str(NPM)}, 'COMPLETE_ORIGINAL_PRESERVATION_NAMESPACES')
        for group in preservation.values():
            require(type(group) is dict and all(type(value) is list and len(value) == 9
                    and all(type(number) is int for number in value) for value in group.values()),
                    'COMPLETE_ORIGINAL_NINE_FIELD_IDENTITIES')
    else:
        require(report['source_assembly'] == 'ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_SNAPSHOT', 'ORIGINAL_FULL_H5_SOURCE_ASSEMBLY')
        compiler = report['compiler']; cid = compiler['container_id']; image = compiler['image']
        docker = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
        require(re.fullmatch('[a-f0-9]{64}', cid)
                and image == 'sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293'
                and compiler['natural_exit'] == 0 and compiler['nonforce_removed'] is True,
                'REAL_COMPILER_NORMAL_LIFECYCLE')
        require(observed['h5-image-inspect'][0]['argv'] == [docker, 'image', 'inspect', '--format', '{{.Id}}', image]
                and observed['h5-image-inspect'][2].decode().strip() == image
                and observed['h5-container-create'][2].decode().strip() == cid
                and observed['h5-compiler-attach'][0]['argv'] == [docker, 'start', '--attach', cid]
                and observed['h5-owned-normal-remove'][0]['argv'] == [docker, 'rm', cid]
                and observed['h5-owned-normal-remove'][2] == (cid + '\n').encode(), 'ORIGINAL_COMPILER_NATIVE_BINDING')
        created_argv = observed['h5-container-create'][0]['argv']
        require(created_argv[:3] == [docker, 'create', '--pull=never'] and '--read-only' in created_argv
                and created_argv[created_argv.index('--network') + 1] == 'none'
                and created_argv[created_argv.index('--cap-drop') + 1] == 'ALL'
                and 'no-new-privileges' in created_argv and created_argv[-5:] ==
                [image, 'node', '--require', '/tool/explicit-pass-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js'],
                'ORIGINAL_FIXED_OFFLINE_COMPILER_ROUTE')
        h5_dependencies = json.loads(tracked(q['dependency_authority']['h5']['path'], q['dependency_authority']['h5']), object_pairs_hook=pairs)
        stylus = h5_dependencies['compile-stylus']
        expected_argv = [docker, 'create', '--pull=never', '--name', 'tianli-integrated-h5-build-' + run_id,
            '--label', 'tianli.audit.purecheck=integrated-three-frontend-build', '--label', 'tianli.audit.build=' + run_id,
            '--label', 'tianli.audit.source=' + q['head'], '--network', 'none', '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--tmpfs', '/tmp:rw,nosuid,nodev,size=1073741824',
            '--tmpfs', '/.automator:rw,nosuid,nodev,size=1048576',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/.cache:rw,nosuid,nodev,size=268435456',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize/.tmp:rw,nosuid,nodev,size=16777216',
            '--workdir', '/hbx/plugins/uniapp-cli']
        for key, value in [('NODE_ENV', 'production'), ('UNI_PLATFORM', 'h5'), ('UNI_MINIMIZE', 'true'),
                           ('CHOKIDAR_USEPOLLING', 'true'), ('CHOKIDAR_INTERVAL', '250'),
                           ('UNI_INPUT_DIR', '/input'), ('UNI_OUTPUT_DIR', '/output'),
                           ('VUE_CLI_CONTEXT', '/hbx/plugins/uniapp-cli'), ('UNI_HBUILDERX_PLUGINS', '/hbx/plugins'),
                           ('HOME', '/tmp'), ('TMPDIR', '/tmp')]:
            expected_argv += ['--env', key + '=' + value]
        for item, mount, readonly in [(run / 'input', '/input', False), (dist_root, '/output', False),
                                     (run / 'evidence', '/evidence', False),
                                     (ROOT / 'h5-tools/explicit-pass-preload.cjs', '/tool/explicit-pass-preload.cjs', True)]:
            expected_argv += ['--mount', 'type=bind,source=' + str(item) + ',target=' + mount + (',readonly' if readonly else '')]
        for name in ['uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers']:
            require(Path(h5_dependencies[name]['root']) == Path('E:/HBuilderX/plugins') / name,
                    'ORIGINAL_COMPILER_DEPENDENCY_ROOT')
            expected_argv += ['--mount', 'type=bind,source=' + str(Path(h5_dependencies[name]['root']))
                              + ',target=/hbx/plugins/' + name + ',readonly']
        expected_argv += ['--mount', 'type=bind,source=' + str(Path(stylus['root']))
                          + ',target=/hbx/plugins/compile-stylus,readonly',
                          '--mount', 'type=bind,source=' + str(ROOT / 'h5-tools/compiler-overlays/vue-cli-plugin-uni-optimize')
                          + ',target=/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize,readonly',
                          image, 'node', '--require', '/tool/explicit-pass-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js']
        require(created_argv == expected_argv, 'ORIGINAL_COMPLETE_COMPILER_COMMAND_INPUTS_AND_MOUNTS')
        runtime_files = {name.removeprefix('template/uni-app/'): row for name, row in source.items()}
        runtime_tree = tree(run / 'input')
        require(set(preservation) == {'exported_source', 'runtime_source', 'compiler_dependencies', 'adapter'}
                and runtime_tree['files'] == runtime_files
                and runtime_tree['directories'] == tree(run / 'template/uni-app')['directories']
                and preservation['runtime_source'] == identities(run / 'input', runtime_files)
                and preservation['adapter'] == pin(tracked(ROOT / 'h5-tools/explicit-pass-preload.cjs'))
                and set(preservation['compiler_dependencies']) == set(h5_dependencies), 'ALL_REAL_H5_RUNTIME_COPY_AND_ADAPTER_INPUTS')
        for name, value in h5_dependencies.items():
            recorded = preservation['compiler_dependencies'][name]
            keys = ['files', 'directories', 'links'] if name == 'compile-stylus' else ['files', 'directories']
            require(recorded['tree'] == {key: value[key] for key in keys}
                    and set(recorded['native9']) == set(value['files']), 'ALL_ORIGINAL_H5_DEPENDENCY_BODIES_AND_IDENTITIES')
        for phase in ['before', 'after']:
            for alias in ['atob', 'mkdirp', 'semver', 'stylus']:
                item = observed['h5-stylus-' + phase + '-alias-' + alias]
                leaf = 'node_modules/.bin/' + alias
                require(item[0]['argv'] == ['C:/Windows/System32/fsutil.exe', 'reparsepoint', 'query', str(Path(stylus['root']) / leaf)]
                        and pin(item[2]) == stylus['links'][leaf]['reparse_query'] and item[3] == b'',
                        'ALL_ORIGINAL_NATIVE_STYLUS_ALIAS_PROOFS')
        template = '{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart_count":{{json .RestartCount}}}'
        for label in ['h5-container-terminal', 'h5-owned-id-before-remove']:
            require(observed[label][0]['argv'] == [docker, 'inspect', '--type', 'container', '--format', template, cid],
                    'ORIGINAL_PUBLIC_ONLY_COMPILER_INSPECT')
        require(all(observed[name][3] == b'' for name in middle if name != 'h5-compiler-attach'), 'ORIGINAL_COMPILER_STDERR_RULES')
        state = json.loads(observed['h5-container-terminal'][2])
        require(state == json.loads(observed['h5-owned-id-before-remove'][2]) and state['id'] == cid and state['image'] == image
                and state['restart_count'] == 0 and state['state']['Status'] == 'exited' and state['state']['Running'] is False
                and state['state']['ExitCode'] == 0 and state['state']['Pid'] == 0, 'ORIGINAL_NATURAL_COMPILER_TERMINAL')
        guards = document(ROOT / 'h5-tools/compiler-source-guards.json')
        receipt = run / 'evidence/adapter-receipt.json'; tracked(receipt)
        info = adapter_receipt(receipt, guards, manifest)
        require(all(compiler[key] == value for key, value in info.items()), 'ORIGINAL_FULL_TWO_PASS_REPORT_JOIN')
    return {'run': run, 'report': report, 'dist_root': dist_root, 'manifest': manifest, 'identity': identity}


def frontend_owned(name):
    prefix = 'crmeb/public/'
    if not name.startswith(prefix):
        return False
    leaf = name[len(prefix):]
    return (leaf in ('admin', 'merchant', 'static', 'assets', 'pages', 'index.html')
            or leaf.startswith(('admin/', 'merchant/', 'static/', 'assets/', 'pages/')))


def excluded_runtime(name):
    parts = name.split('/')
    require('__ms4_assets' not in parts and '__ms4_fixture__' not in parts, 'NO_AUDIT_PUBLIC_CONTEXT')
    return ('.env' in parts or parts[-1] == 'install.lock'
            or name == 'crmeb/.constant'
            or any(name == prefix or name.startswith(prefix + '/') for prefix in
                   ['crmeb/runtime', 'crmeb/public/uploads']))


def copy_plan(q, builds):
    files, directories, origins, modes, excluded = {}, set(), {}, {}, []
    source = Path(q['source_repo'])
    installer_name = 'crmeb/public/install/.env'
    installer_pin = {'bytes': 518, 'sha256': 'cc2cd4add8cd6534d433101a2f0d095f3ea5b58d7703201ae779972b7463df91'}
    installer_row = q['_rows'].get(installer_name)
    require(type(installer_row) is dict and installer_row['path'] == installer_name
            and {key: installer_row[key] for key in ['bytes', 'sha256']} == installer_pin
            and pin(tracked(source / installer_name)) == installer_pin,
            'EXACT_QUALIFIED_SOURCE_INSTALLER_TEMPLATE')
    for name, row in q['_rows'].items():
        if (excluded_runtime(name) and name != installer_name) or frontend_owned(name):
            excluded.append(name)
            continue
        files[name] = {key: row[key] for key in ['bytes', 'sha256']}
        origins[name] = {'kind': 'source', 'path': (source / name).as_posix()}
        modes[name] = row['git_mode']
    for name in q['_tree']['directories']:
        if not excluded_runtime(name) and not frontend_owned(name):
            directories.add(name)
    for frontend in ['admin', 'merchant', 'h5']:
        data = builds[frontend]
        prefix = 'crmeb/public/' + (frontend + '/' if frontend != 'h5' else '')
        for row in data['manifest']['files']:
            name = relative(row['path'])
            require(not excluded_runtime('crmeb/public/' + name), 'NO_RUNTIME_DIST_INPUT')
            require(frontend != 'h5' or name == 'index.html' or name.startswith(('static/', 'assets/', 'pages/')), 'UNKNOWN_H5_ROOT_REQUIRES_NEW_DESIGN')
            target = prefix + name
            relative(target)
            require(target not in files and target not in directories, 'NO_BACKEND_OR_FRONTEND_FILE_COLLISION')
            files[target] = {key: row[key] for key in ['bytes', 'sha256']}
            origins[target] = {'kind': frontend, 'path': (data['dist_root'] / name).as_posix()}
            modes[target] = '100644'
        for name in data['manifest']['directories']:
            relative(name)
            require(frontend != 'h5' or name in ('static', 'assets', 'pages') or name.startswith(('static/', 'assets/', 'pages/')), 'UNKNOWN_H5_DIRECTORY_REQUIRES_NEW_DESIGN')
            require(not excluded_runtime('crmeb/public/' + name), 'NO_RUNTIME_DIST_DIRECTORY')
            target = (prefix + name).rstrip('/')
            require(target not in files, 'NO_DIRECTORY_FILE_COLLISION')
            directories.add(target)
    for name in files:
        for parent in Path(name).parents:
            if parent.as_posix() != '.':
                directories.add(parent.as_posix())
    names = list(files) + list(directories)
    require(len({name.casefold() for name in names}) == len(names), 'NO_CASE_OR_FILE_DIRECTORY_ALIAS')
    require('crmeb/public/index.php' in files and 'crmeb/public/index.html' in files
            and 'crmeb/public/admin/index.html' in files and 'crmeb/public/merchant/index.html' in files,
            'ALL_REAL_FORMAL_ENTRIES_AND_BACKEND')
    require(files.get(installer_name) == installer_pin
            and origins.get(installer_name) == {'kind': 'source', 'path': (source / installer_name).as_posix()},
            'PRESERVE_EXACT_INSTALLER_TEMPLATE_FROM_QUALIFIED_SOURCE')
    require('crmeb/composer.lock' in files, 'PRESERVE_DEPENDENCY_LOCK_NOT_RUNTIME_LOCK')
    return {'files': dict(sorted(files.items())), 'directories': sorted(directories),
            'origins': dict(sorted(origins.items())), 'logical_git_modes_not_windows_acl': dict(sorted(modes.items())),
            'excluded_declared_runtime_or_frontend': sorted(excluded)}


def fresh_directory(path):
    path = Path(path)
    regular(path.parent, True)
    require(path.parent.resolve() == path.parent and not path.exists() and not path.is_symlink(), 'CREATE_ONLY_DIRECTORY')
    path.mkdir()
    regular(path, True)
    require(path.resolve() == path, 'NEW_DIRECTORY_CANONICAL')


def transfer(source, target, expected, frozen):
    source, target = Path(source), Path(target)
    regular(target.parent, True)
    require(target.parent.resolve() == target.parent and not target.exists() and not target.is_symlink(), 'EXCLUSIVE_TARGET_ONLY')
    require(native9(source) == frozen and pin(read(source)) == expected, 'FROZEN_TRANSFER_SOURCE')
    named = regular(source)
    named_birth = getattr(named, 'st_birthtime_ns', None)
    named_fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    require([getattr(named, key) for key in named_fields] == frozen, 'SAME_NAMED_TRANSFER_INPUT')
    with source.open('rb') as input_stream:
        opened = os.fstat(input_stream.fileno())
        birth = getattr(opened, 'st_birthtime_ns', None)
        shared = ['st_dev', 'st_ino', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns']
        require(type(birth) is int and birth == named_birth and stat.S_ISREG(opened.st_mode)
                and all(getattr(opened, key) == getattr(named, key) for key in shared), 'REAL_NAMED_OPEN_INPUT_AND_BIRTH')
        with target.open('xb') as output:
            created = os.fstat(output.fileno())
            created_birth = getattr(created, 'st_birthtime_ns', None)
            require(stat.S_ISREG(created.st_mode) and created.st_nlink == 1 and type(created_birth) is int, 'NEW_SINGLE_LINK_FILE')
            digest = hashlib.sha256(); total = 0
            while True:
                chunk = input_stream.read(262144)
                if not chunk:
                    break
                digest.update(chunk); total += len(chunk)
                view = memoryview(chunk)
                while view:
                    count = output.write(view)
                    require(type(count) is int and count > 0, 'FULL_EXCLUSIVE_WRITE')
                    view = view[count:]
            output.flush(); os.fsync(output.fileno())
            final = os.fstat(output.fileno())
            require(final.st_dev == created.st_dev and final.st_ino == created.st_ino and final.st_nlink == 1
                    and final.st_size == total == expected['bytes'] and digest.hexdigest() == expected['sha256'],
                    'EXACT_TRANSFER_AND_SAME_NEW_HANDLE')
        done = os.fstat(input_stream.fileno())
        fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
        require(all(getattr(done, key) == getattr(opened, key) for key in fields)
                and getattr(done, 'st_birthtime_ns', None) == birth and input_stream.read(1) == b'', 'OPEN_INPUT_FULL_EOF_UNCHANGED')
    require(native9(source) == frozen and pin(read(source)) == expected
            and getattr(regular(source), 'st_birthtime_ns', None) == named_birth, 'INPUT_NATIVE_IDENTITY_AND_BYTES_UNCHANGED')
    require(pin(read(target)) == expected and native9(target)[0:2] == [created.st_dev, created.st_ino]
            and getattr(regular(target), 'st_birthtime_ns', None) == created_birth, 'WHOLE_NEW_FILE_READBACK')


def verify_windows():
    for name, before in INPUT_WINDOWS.items():
        require(pin(read(Path(name))) == before['body'] and native9(Path(name)) == before['native9'], 'ALL_ORIGINAL_INPUT_WINDOWS_HELD')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--build-source', required=True)
    parser.add_argument('--pins-sha256', required=True)
    parser.add_argument('--runs-file', required=True, help='JSON path index only: admin/merchant/h5 each has run and launch')
    parser.add_argument('--out', required=True, help='new exclusive directory under the fixed formal-public-runs parent')
    parser.add_argument('--tool-review-file', help='explicit Root-reviewed copier tool descriptor; omitted for the same-source tool')
    parser.add_argument('--tool-review-sha256', help='explicit complete SHA256 of the Root tool review file')
    args = parser.parse_args()
    require(os.name == 'nt' and sys.version_info[:2] == (3, 14), 'QUALIFIED_WINDOWS_PYTHON314_HOST_ONLY')
    q, authority = reviewed_qualification(args.build_source, args.pins_sha256,
                                           args.tool_review_file, args.tool_review_sha256)
    q['_copy_pins'] = args.pins_sha256
    runs = document(Path(args.runs_file))
    require(type(runs) is dict and set(runs) == {'admin', 'merchant', 'h5'}, 'THREE_ACTUAL_RUNS_REQUIRED_NO_STUB')
    builds = {name: actual_build(name, runs[name], q, authority) for name in ['admin', 'merchant', 'h5']}
    verify_lx_alias_inputs(q, builds['h5'])
    plan = copy_plan(q, builds)
    out = Path(args.out)
    require(out.is_absolute() and out.parent == COPY_PARENT and re.fullmatch('[0-9]{14}[a-f0-9]{8}', out.name), 'ONE_NEW_FIXED_CONTEXT_OUTPUT')
    for root in [Path(q['source_repo']), Path(q['validation_repo']), ROOT, RUN_PARENT]:
        require(not out.is_relative_to(root) and not root.is_relative_to(out), 'OUTPUT_SEPARATE_FROM_ALL_IMMUTABLE_INPUTS')
    require(not out.exists() and not out.is_symlink(), 'NEVER_RESUME_OR_OVERWRITE_FORMAL_COPY')
    regular(COPY_PARENT.parent, True)
    require(COPY_PARENT.parent.resolve() == COPY_PARENT.parent, 'CANONICAL_PUBLIC_OUTPUT_PARENT')
    COPY_PARENT.mkdir(exist_ok=True); regular(COPY_PARENT, True)
    fresh_directory(out); fresh_directory(out / 'evidence')
    context = {'qualification': q, 'out': out, 'evidence': out / 'evidence', 'native': []}
    initial = repo_snapshot(context, 'before')
    # No context object has been created: all source/Git/build/dist/expected inputs precede it.
    target = out / 'context'
    require(not target.exists() and not target.is_symlink(), 'CONTEXT_UNTOUCHED_BEFORE_COMPLETE_PLAN')
    input_states = {name: native9(Path(origin['path'])) for name, origin in plan['origins'].items()}
    for name, origin in plan['origins'].items():
        require(pin(read(Path(origin['path']))) == plan['files'][name], 'ALL_TRANSFER_INPUTS_FULLY_READ_BEFORE_WRITE')
    verify_windows()
    put(out / 'COPY-PLAN.json', {'schema': 1, 'source_head': q['head'], **plan})
    fresh_directory(target)
    for name in sorted(plan['directories'], key=lambda x: (len(Path(x).parts), x)):
        fresh_directory(target / name)
    for name, expected in plan['files'].items():
        transfer(plan['origins'][name]['path'], target / name, expected, input_states[name])
    observed = tree(target)
    require(observed == {key: plan[key] for key in ['files', 'directories']}, 'COMPLETE_PHYSICAL_CONTEXT_EQUALS_PRECONSTRUCTED_EXPECTED')
    after_identities = {'root': native9(target), 'files': identities(target, plan['files']),
                        'directories': identities(target, plan['directories'])}
    preserved(context, initial)
    verify_windows()
    require(all(native9(Path(origin['path'])) == input_states[name] for name, origin in plan['origins'].items()), 'ALL_TRANSFER_INPUT_IDENTITIES_HELD')
    require(tree(target) == observed and after_identities == {'root': native9(target), 'files': identities(target, plan['files']),
                                                            'directories': identities(target, plan['directories'])}, 'COMPLETE_NEW_CONTEXT_FINAL_READBACK')
    put(out / 'CONTEXT-TREE.json', {'source_head': q['head'], **observed})
    put(out / 'CONTEXT-NATIVE-IDENTITIES.json', after_identities)
    result = {'schema': 1, 'status': 'FORMAL_PUBLIC_CONTEXT_PHYSICAL_COPY_COMPLETE_NOT_IMAGE_OR_RELEASE',
              'source_head': q['head'], 'source_qualification': QUALIFIED_INPUTS_PIN, 'copier_tool': q['_copier_tool'],
              'full_tree': q['full_tree'], 'source_authority': q['source_authority'],
              'builds': {name: {'run_id': value['report']['run_id'], 'report': descriptor(value['run'] / 'BUILD-REPORT.json'),
                                'dist': descriptor(value['run'] / 'DIST-TREE.json')} for name, value in builds.items()},
              'context': target.as_posix(), 'context_tree': descriptor(out / 'CONTEXT-TREE.json'),
              'plan': descriptor(out / 'COPY-PLAN.json'), 'input_windows': INPUT_WINDOWS,
              'native_operations': context['native'], 'producer_pid': os.getpid(), 'producer_argv': sys.argv,
              'outer_created_terminal_samewait': 'REQUIRED_FROM_ACTUAL_ROOT_CALLER_NOT_SELF_SIGNED',
              'four_B_manifests': 'NOT_CREATED_OR_MODIFIED', 'M_A_B_UI_images_deploy_acceptance': 'NOT_SIGNED'}
    put(out / 'FORMAL-COPY.json', result)
    print(json.dumps({'status': result['status'], 'source_head': q['head'], 'context': target.as_posix()}, ensure_ascii=False), flush=True)
    return 0


if __name__ == '__main__':
    try:
        result_code = main()
    except BaseException as failure:
        if isinstance(failure, SystemExit):
            raise
        code = str(failure) if isinstance(failure, RuntimeError) else 'FORMAL_COPY_FAILED_OR_UNKNOWN'
        if re.fullmatch('[A-Z0-9_]{1,128}', code) is None:
            code = 'FORMAL_COPY_FAILED_OR_UNKNOWN'
        print(json.dumps({'status': 'FORMAL_COPY_FAILED_PRESERVE_NEW_OUTPUT_NO_RETRY', 'code': code}), file=sys.stderr, flush=True)
        sys.exit(2)
    else:
        sys.exit(result_code)
