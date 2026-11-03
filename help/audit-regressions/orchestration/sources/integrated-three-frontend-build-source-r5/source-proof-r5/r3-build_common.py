"""Finite three-build Source helpers. Import performs no process or write."""
from pathlib import Path
import hashlib
import json
import os
import re
import stat
import subprocess
import time

ROOT = Path(__file__).resolve().parent
ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
RUN_PARENT = ORG / 'integrated-three-frontend-build-runs'
# Root must derive and review a new qualified Source generation. This draft cannot run.
QUALIFIED_INPUTS_PIN = None
NODE_ROOT = Path('H:/天俪商城/artifacts/ms3-build-tools/source477732-node22/node-v22.23.3-win-x64')
NODE = NODE_ROOT / 'node.exe'
NPM = NODE_ROOT / 'node_modules/npm/bin/npm-cli.js'
NODE_PIN = {'bytes': 86973768, 'sha256': '9c9245166b4a8e182e0b797da9c20136117ff24368eaff1fec8343a123c8db0e'}
NPM_PIN = {'bytes': 56, 'sha256': '3ce7cba6f5128dd5f54c98b6a5036b0f850496878cc2e21044b675fe3c594e3e'}
PYTHON = 'C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'


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
    return roots


def frontend_source(context, frontend):
    q, out = context['qualification'], context['out']
    prefix = 'template/uni-app/' if frontend == 'h5' else 'template/' + frontend + '/'
    names = [x for x in q['_rows'] if x.startswith(prefix)]
    if frontend == 'admin':
        names += ['help/audit-regressions/frontend-source-check.cjs']
    require(names and len(names) == len(set(names)), 'COMPLETE_FRONTEND_AND_PREBUILD_SOURCE')
    for name in q['_tree']['directories']:
        if name == prefix.rstrip('/') or name.startswith(prefix):
            (out / relative(name)).mkdir(parents=True, exist_ok=True)
    rows = {}
    for name in sorted(names):
        raw = read(Path(q['validation_repo']) / name)
        rows[name] = pin(raw)
        create(out / name, raw)
    put(out / 'SOURCE-INPUTS.json', rows)
    return rows


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
