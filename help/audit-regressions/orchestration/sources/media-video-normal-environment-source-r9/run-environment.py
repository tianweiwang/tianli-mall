"""Dedicated normal media environment. Source preparation is not execution.

Root invokes one phase at a time after prior ordinary environment retirement.
No automatic cleanup, pull, force removal, raw App logs, or old suite runner.
"""
from pathlib import Path
import argparse, datetime, hashlib, json, os, re, secrets, subprocess, sys, time

ROOT = Path(__file__).resolve().parent
ENVIRONMENT = ROOT / 'media-environment'
SOURCE = json.loads((ENVIRONMENT / 'source-binding.json').read_text(encoding='utf-8'))['source_sha']
if not isinstance(SOURCE, str) or re.fullmatch('[0-9a-f]{40}', SOURCE) is None:
    raise RuntimeError('Root actual committed Source binding is UNBOUND')
PHP_IMAGE = 'sha256:297b37d4b7b3ad44e7194b765e785f4a1734d697caa4d555fefef4186784b780'
MYSQL_IMAGE = 'sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4'
REDIS_IMAGE = 'sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e'
ROLES = {'audit-bootstrap', 'audit-mysql', 'audit-redis', 'audit-app', 'audit-runner', 'audit-media-timer'}
VOLUME_KEYS = {'audit-vendor', 'mysql-data', 'redis-data', 'app-runtime', 'runner-runtime', 'worker-runtime', 'chat-runtime', 'backup-output', 'bootstrap-secrets', 'redis-secrets', 'app-secrets', 'fixture-secrets', 'source-tree', 'media-uploads', 'app-private-logs', 'media-video-private', 'media-scan-private', 'media-timer-runtime'}
INSPECT = ('{"id":{{json .Id}},"name":{{json .Name}},"labels":{{json .Config.Labels}},'
           '"image_id":{{json .Image}},"mounts":{{json .Mounts}},'
           '"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},'
           '"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},'
           '"network_mode":{{json .HostConfig.NetworkMode}},"restart_count":{{json .RestartCount}},"state":{{json .State}}}')

def require(value, message):
    if not value:
        raise RuntimeError(message)

def fingerprint(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink(), 'regular Source required')
    before = path.stat(); digest = hashlib.sha256(); size = 0
    own = lambda value: tuple(getattr(value, key, None) for key in
        ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns', 'st_birthtime_ns', 'st_file_attributes'])
    cross = lambda value: (value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns)
    with path.open('rb') as stream:
        opened = os.fstat(stream.fileno())
        require(cross(before) == cross(opened), 'Source named/fd identity')
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk); size += len(chunk)
        after_fd = os.fstat(stream.fileno())
    after = path.stat()
    require(own(before) == own(after) and own(opened) == own(after_fd) and cross(after) == cross(after_fd), 'Source full read changed')
    require(size == before.st_size, 'Source EOF bytes')
    return {'bytes': size, 'sha256': digest.hexdigest()}

def read_json(path):
    return json.loads(Path(path).read_text(encoding='utf-8'))

def put(path, value):
    path = Path(path)
    require(not path.exists(), 'refuse receipt replacement')
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')

def source_pack(path, expected):
    path = Path(path).resolve()
    require(fingerprint(path)['sha256'] == expected, 'Source PINS CLI mismatch')
    value = read_json(path)
    require(isinstance(value.get('files'), dict) and value['files'], 'Source PINS.files map required')
    for name, row in value['files'].items():
        member = path.parent / name
        require(member.resolve().is_relative_to(path.parent) and '..' not in Path(name).parts, 'Source member namespace')
        require(fingerprint(member) == {'bytes': row['bytes'], 'sha256': row['sha256']}, 'whole Source member changed')
    return {'path': str(path), **fingerprint(path)}

class Native:
    def __init__(self, run, env):
        self.directory = run / 'native'; self.directory.mkdir(exist_ok=True)
        self.env = env

    def call(self, argv, *, success=True):
        index = len(list(self.directory.glob('*.created.json'))) + 1
        prefix = self.directory / f'{index:04d}'
        stdout = prefix.with_suffix('.stdout'); stderr = prefix.with_suffix('.stderr')
        require(not stdout.exists() and not stderr.exists(), 'native namespace collision')
        with stdout.open('xb') as out, stderr.open('xb') as err:
            started = time.time_ns()
            process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=out, stderr=err, env=self.env)
            put(prefix.with_suffix('.created.json'), {'argv': argv, 'pid': process.pid, 'started_ns': started})
            code = process.wait()
        terminal = {'argv': argv, 'pid': process.pid, 'started_ns': started, 'finished_ns': time.time_ns(),
                    'native_exit': code, 'wait_exit': code,
                    'stdout': {'path': str(stdout), **fingerprint(stdout)},
                    'stderr': {'path': str(stderr), **fingerprint(stderr)}}
        put(prefix.with_suffix('.terminal.json'), terminal)
        require(not success or code == 0, 'native nonzero; preserve evidence and stop')
        return stdout.read_bytes(), terminal

def docker(native, *args):
    return native.call(['docker', *map(str, args)])[0]

def lines(data):
    return [line for line in data.decode('utf-8').splitlines() if line]

def selectors(native, project, run):
    label = ['--filter', 'label=tianli.audit=1', '--filter', 'label=tianli.audit.run=' + run,
             '--filter', 'label=com.docker.compose.project=' + project]
    return {'containers': lines(docker(native, 'ps', '-aq', '--no-trunc', *label)),
            'networks': lines(docker(native, 'network', 'ls', '-q', *label)),
            'volumes': lines(docker(native, 'volume', 'ls', '-q', *label))}

def inspected(native, ids):
    return [json.loads(docker(native, 'inspect', '--format', INSPECT, cid)) for cid in ids]

def foreign(rows, project):
    result = {}
    for row in rows:
        if (row['labels'] or {}).get('com.docker.compose.project') == project:
            continue
        result[row['id']] = {'name': row['name'], 'image_id': row['image_id'],
            'mounts': sorted(row['mounts'], key=lambda x: json.dumps(x, sort_keys=True)),
            'networks': sorted([name, value.get('NetworkID')] for name, value in row['networks'].items()),
            'read_only': row['read_only'], 'privileged': row['privileged'], 'network_mode': row['network_mode']}
    return result

def collect(native, context, run):
    found = selectors(native, context['project'], context['run_id'])
    rows = inspected(native, found['containers'])
    require(all(re.fullmatch('[0-9a-f]{64}', cid) for cid in found['containers']) and len(set(found['containers'])) == len(found['containers']), 'complete unique actual owned CIDs required')
    require(all((row['labels'] or {}).get('com.docker.compose.service') in ROLES for row in rows), 'unexpected owned role')
    require(len(found['networks']) == 1, 'one dedicated network required')
    network = json.loads(docker(native, 'network', 'inspect', '--format', '{{json .}}', found['networks'][0]))
    require(network['Internal'] is True and not (network.get('Options') or {}).get('com.docker.network.bridge.host_binding_ipv4'), 'dedicated internal network required')
    for row in rows:
        labels = row['labels'] or {}; role = labels['com.docker.compose.service']
        require(labels.get('tianli.audit') == '1' and labels.get('tianli.audit.run') == context['run_id']
                and labels.get('com.docker.compose.project') == context['project'], 'owned container labels')
        expected_image = MYSQL_IMAGE if role == 'audit-mysql' else REDIS_IMAGE if role == 'audit-redis' else PHP_IMAGE
        require(row['image_id'] == expected_image and row['privileged'] is False and row['restart_count'] == 0,
                'actual fixed image/nonprivileged/no-restart identity')
        require(set(row['networks']) == {network['Name']} and all(value is None for value in (row['ports'] or {}).values()), 'one internal network/no published port')
        if role in {'audit-bootstrap', 'audit-app', 'audit-runner', 'audit-media-timer'}:
            require(row['read_only'] is True, 'actual readonly PHP root')
            for destination in ['/source', '/workspace']:
                mounts = [m for m in row['mounts'] if m['Destination'] == destination]
                require(len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['Name'] == context['project'] + '_source-tree'
                        and mounts[0]['RW'] is False, 'actual complete same SourceTree readonly mounts')
        if role in {'audit-app', 'audit-runner', 'audit-media-timer'}:
            require(not any(m['Destination'] in {'/run/audit-bootstrap', '/run/audit-redis'} for m in row['mounts']), 'root/migrator secrets absent from App and runner')
            destination = '/workspace/crmeb/public/uploads' if role in {'audit-app', 'audit-media-timer'} else '/run/media-uploads-observe'
            mounts = [m for m in row['mounts'] if m['Destination'] == destination]
            require(len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['Name'] == context['project'] + '_media-uploads'
                    and mounts[0]['RW'] is (role in {'audit-app', 'audit-media-timer'}), 'actual upload writer/readonly observer mount')
        if role in {'audit-app', 'audit-media-timer'}:
            require(not any(m['Destination'] == '/run/audit-fixtures' for m in row['mounts']), 'actor fixture secrets absent from App')
        if role in {'audit-bootstrap', 'audit-app', 'audit-runner', 'audit-media-timer'}:
            for key, destination in [('media-video-private', '/run/audit-media-video'), ('media-scan-private', '/run/audit-media-scan')]:
                mounts = [m for m in row['mounts'] if m['Destination'] == destination]
                require(len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['Name'] == context['project'] + '_' + key
                        and mounts[0]['RW'] is (role != 'audit-runner'), 'actual dedicated private writer/readonly observer root')
        if role == 'audit-media-timer':
            mounts = [m for m in row['mounts'] if m['Destination'] == '/workspace/crmeb/runtime']
            require(len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['Name'] == context['project'] + '_media-timer-runtime'
                    and mounts[0]['RW'] is True, 'actual dedicated private Timer runtime')
    require(set(found['volumes']) <= {context['project'] + '_' + key for key in VOLUME_KEYS}, 'unexpected dedicated volume')
    volumes = []
    for name in found['volumes']:
        row = json.loads(docker(native, 'volume', 'inspect', '--format', '{{json .}}', name))
        volumes.append({'name': row['Name'], 'created_at': row['CreatedAt'], 'labels': row['Labels']})
    # Existing original register command consumes these complete safe metadata.
    (run / 'network.json').write_text(json.dumps(network), encoding='utf-8')
    (run / 'containers.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in rows), encoding='utf-8')
    (run / 'volumes.txt').write_text(''.join(name + '\n' for name in found['volumes']), encoding='utf-8')
    (run / 'volumes.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in volumes), encoding='utf-8')
    snapshots = run / 'resources'; snapshots.mkdir(exist_ok=True)
    put(snapshots / f'{len(list(snapshots.glob("*.json"))) + 1:04d}.json',
        {'schema': 1, 'run_id': context['run_id'], 'project': context['project'],
         'actual_roles': sorted({row['labels']['com.docker.compose.service'] for row in rows}),
         'all_current_container_ids': found['containers'], 'containers': rows,
         'network': network, 'volumes': volumes,
         'original_manifest_services_is_one_selected_CID_per_role_not_the_full_container_registry': True})
    return found, rows, network

def compose(native, context, *args):
    return docker(native, 'compose', '-p', context['project'], '-f', ENVIRONMENT / 'compose.base.yml',
                  '-f', ENVIRONMENT / 'compose.normal.yml', *args)

def job(native, context, name, role, script, *args):
    require(role in {'audit-bootstrap', 'audit-runner'}, 'finite job role')
    command = [script, *args]
    if role == 'audit-runner':
        configuration = read_json(Path(native.env['AUDIT_RUN_DIR']) / 'media-upload-configuration.json')
        memory = configuration['memory_limit']
        require(configuration['run_id'] == context['run_id'] and configuration['source_sha'] == SOURCE
                and isinstance(memory, str) and memory.isdecimal() and int(memory) > 0, 'actual finite runner memory configuration')
        # Direct CLI: these flags apply to this actual process; no Think child.
        command = ['-d', 'memory_limit=' + memory, *command]
    return compose(native, context, 'run', '--no-deps', '-T', '--name', context['project'] + '-' + name,
                   role, *command)

def register(native, context, run, name):
    collect(native, context, run)
    job(native, context, name, 'audit-bootstrap', '/media-environment/lib/harness.php', '__register')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('phase', choices=['prepare', 'prepare-media', 'start', 'verify', 'start-timer', 'observe-gc', 'observe', 'close', 'zero'])
    parser.add_argument('--run-dir', required=True)
    parser.add_argument('--source-pins-sha256', required=True)
    parser.add_argument('--checks-pins', required=True)
    parser.add_argument('--checks-pins-sha256', required=True)
    parser.add_argument('--fixtures-root', required=True)
    parser.add_argument('--large-fixtures-root', required=True)
    parser.add_argument('--fixtures-manifest', required=True)
    parser.add_argument('--fixtures-manifest-sha256', required=True)
    parser.add_argument('--authorize-normal-media', action='store_true', required=True)
    args = parser.parse_args()
    own = source_pack(ROOT / 'PINS.json', args.source_pins_sha256)
    checks = source_pack(args.checks_pins, args.checks_pins_sha256)
    checks_root = Path(args.checks_pins).resolve().parent
    require(read_json(ROOT / 'PINS.json')['source_sha'] == SOURCE and read_json(args.checks_pins)['source_sha'] == SOURCE, 'actual committed environment and HTTP Source required')
    fixtures = Path(args.fixtures_root).resolve()
    large_fixtures = Path(args.large_fixtures_root).resolve()
    large = read_json(checks_root / 'LARGE-VIDEO.json')
    large_file = large_fixtures / large['file']['name']
    require(large_file.resolve() == Path(large['file']['host_path']).resolve() and large_file.parent == large_fixtures
            and large['file']['container_path'] == '/media-large-fixture/' + large_file.name
            and fingerprint(large_file) == {key: large['file'][key] for key in ['bytes', 'sha256']}, 'actual independent real encoded large MP4 binding')
    fixtures_manifest = Path(args.fixtures_manifest).resolve()
    require(fingerprint(fixtures_manifest)['sha256'] == args.fixtures_manifest_sha256, 'fixture manifest CLI binding')
    require(all((checks_root / name).is_file() for name in ['common.php', 'prepare.php', 'verify.php']), 'complete normal HTTP driver Source')
    tracked = {row['path']: row for row in read_json(ROOT / 'source-input' / 'SOURCE-TREE.json')['files']}
    for name, row in read_json(args.checks_pins).get('dependencies', {}).items():
        require(name in tracked and all(row[key] == tracked[name][key] for key in ['bytes', 'sha256']), 'driver dependency is not this committed Source')
    run = Path(args.run_dir).resolve()
    require(not run.is_relative_to(ROOT) and not ROOT.is_relative_to(run), 'Run outside immutable Source packet')
    if args.phase == 'prepare':
        require(not run.exists(), 'new Run required')
        run.mkdir(parents=True)
        run_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S') + secrets.token_hex(3)
        context = {'schema': 1, 'run_id': run_id, 'project': 'tianli-audit-' + run_id, 'source_sha': SOURCE,
                   'Source_PINS': own, 'checks_PINS': checks, 'fixtures_manifest': {'path': str(fixtures_manifest), **fingerprint(fixtures_manifest)},
                   'large_fixture': {'path': str(large_file), **fingerprint(large_file)},
                   'images': {'php': PHP_IMAGE, 'mysql': MYSQL_IMAGE, 'redis': REDIS_IMAGE},
                   'logical_roles': sorted(ROLES), 'source_copy_network': 'none', 'actual_result': 'UNBOUND'}
        put(run / 'environment-context.json', context)
    else:
        context = read_json(run / 'environment-context.json')
        require(context['source_sha'] == SOURCE and context['Source_PINS'] == own and context['checks_PINS'] == checks
                and context['fixtures_manifest'] == {'path': str(fixtures_manifest), **fingerprint(fixtures_manifest)}
                and context['large_fixture'] == {'path': str(large_file), **fingerprint(large_file)}, 'current Source/runtime binding changed')
    env = os.environ.copy()
    env.update(AUDIT_RUN_ID=context['run_id'], AUDIT_PROJECT=context['project'], AUDIT_SOURCE_SHA=SOURCE,
               AUDIT_GUARD_MODE='read-only', AUDIT_RUN_DIR=run.as_posix(), AUDIT_REPO_DIR=str(ROOT),
               MEDIA_ENVIRONMENT_DIR=ENVIRONMENT.as_posix(), MEDIA_CHECKS_DIR=checks_root.as_posix(), MEDIA_FIXTURES_DIR=fixtures.as_posix(), MEDIA_LARGE_FIXTURES_DIR=large_fixtures.as_posix())
    native = Native(run, env)
    receipt = run / ('phase-' + args.phase + '.json')
    require(not receipt.exists(), 'phase already has a terminal receipt; do not rerun')
    put(run / ('phase-' + args.phase + '.started.json'), {'phase': args.phase, 'pid': os.getpid(), 'started_ns': time.time_ns()})
    project = context['project']; run_id = context['run_id']
    if args.phase == 'prepare':
        require(selectors(native, project, run_id) == {'containers': [], 'networks': [], 'volumes': []}, 'new label selectors must be empty')
        all_rows = inspected(native, lines(docker(native, 'ps', '-aq', '--no-trunc')))
        put(run / 'foreign-before.json', foreign(all_rows, project))
        for image in [PHP_IMAGE, MYSQL_IMAGE, REDIS_IMAGE]:
            require(json.loads(docker(native, 'image', 'inspect', '--format', '{{json .Id}}', image)) == image, 'fixed local image required; no pull')
        for key in ['source-tree', 'media-uploads', 'app-private-logs', 'media-video-private', 'media-scan-private', 'media-timer-runtime']:
            docker(native, 'volume', 'create', '--label', 'tianli.audit=1', '--label', 'tianli.audit.run=' + run_id,
                   '--label', 'com.docker.compose.project=' + project, '--label', 'com.docker.compose.volume=' + key, project + '_' + key)
        mounts = [('volume', project + '_source-tree', '/run/media-source-rw', False),
                  ('volume', project + '_media-uploads', '/run/media-uploads', False),
                  ('volume', project + '_app-private-logs', '/run/media-app-logs', False),
                  ('bind', str(ROOT / 'source-input'), '/media-input', True),
                  ('bind', str(ENVIRONMENT), '/media-environment', True), ('bind', str(run), '/run/audit', False)]
        argv = ['create', '--name', project + '-source-copy', '--network', 'none', '--read-only', '--tmpfs', '/tmp',
                '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--user', '0:0',
                '--label', 'tianli.audit=1', '--label', 'tianli.audit.run=' + run_id,
                '--label', 'com.docker.compose.project=' + project, '--label', 'com.docker.compose.service=audit-bootstrap']
        for kind, source, target, readonly in mounts:
            argv += ['--mount', f'type={kind},source={source},target={target}' + (',readonly' if readonly else '')]
        argv += ['--entrypoint', 'php', PHP_IMAGE, '/media-environment/install-source.php']
        cid = docker(native, *argv).decode().strip(); require(re.fullmatch('[0-9a-f]{64}', cid), 'real source writer CID')
        docker(native, 'start', '-a', cid)
        require(docker(native, 'wait', cid).strip() == b'0', 'source writer natural wait0')
        state = inspected(native, [cid])[0]
        require(state['state']['Status'] == 'exited' and state['state']['ExitCode'] == 0 and state['state']['Pid'] == 0, 'source writer real exit0')
        docker(native, 'rm', cid)
        put(run / 'source-writer-closed.json', {'schema': 1, 'container_id': cid, 'native_exit': 0, 'ordinary_removed': True,
            'source_tree_receipt': {'path': str(run / 'source-tree.json'), **fingerprint(run / 'source-tree.json')}})
        job(native, context, 'prepare', 'audit-bootstrap', '/media-environment/lib/harness.php', '__prepare')
        compose(native, context, 'up', '-d', '--wait', 'audit-mysql', 'audit-redis')
        register(native, context, run, 'register-seed')
        job(native, context, 'seed', 'audit-bootstrap', '/media-environment/lib/harness.php', '__seed')
        job(native, context, 'configure-upload', 'audit-bootstrap', '/media-environment/configure-upload.php')
    elif args.phase == 'prepare-media':
        require(read_json(run / 'phase-prepare.json')['native_exit'] == 0, 'actual environment prepare0 required')
        register(native, context, run, 'register-media')
        job(native, context, 'media-prepare', 'audit-bootstrap', '/media-checks/prepare.php')
        profile = '/workspace/help/audit-regressions/isolated/lib/media-normal-profile.php'
        for name in ['configure', 'schema', 'install', 'preflight']:
            register(native, context, run, 'register-media-' + name)
            job(native, context, 'media-' + name, 'audit-bootstrap', profile, name)
    elif args.phase == 'start':
        require(read_json(run / 'phase-prepare-media.json')['native_exit'] == 0, 'actual media prepare process0 required')
        require(read_json(run / 'source-writer-closed.json')['ordinary_removed'] is True, 'source writer ordinary removal required')
        compose(native, context, 'up', '-d', 'audit-app')
        register(native, context, run, 'register-app')
        job(native, context, 'start', 'audit-runner', '/media-environment/control.php', 'start')
        # The first real runner now exists: record/register the actual five
        # roles, retaining all previous transient job CIDs in native/snapshots.
        register(native, context, run, 'register-started')
        _, actual_rows, _ = collect(native, context, run)
        require({row['labels']['com.docker.compose.service'] for row in actual_rows} == ROLES - {'audit-media-timer'}, 'actual original five roles required before Timer')
    elif args.phase in {'verify', 'observe'}:
        require(read_json(run / 'phase-start.json')['native_exit'] == 0, 'actual App start0 required')
        register(native, context, run, 'register-' + args.phase)
        if args.phase == 'verify':
            job(native, context, 'verify', 'audit-runner', '/media-checks/verify.php')
            job(native, context, 'gc-before', 'audit-runner', '/media-environment/gc-observe.php', 'before')
        else:
            job(native, context, 'observe', 'audit-runner', '/media-environment/control.php', 'observe')
    elif args.phase == 'start-timer':
        require(read_json(run / 'phase-verify.json')['native_exit'] == 0 and (run / 'media-gc-before.json').is_file(), 'actual complete HTTP and private baseline required')
        register(native, context, run, 'register-timer-preflight')
        profile = '/workspace/help/audit-regressions/isolated/lib/media-normal-profile.php'
        job(native, context, 'timer-preflight', 'audit-runner', profile, 'preflight-timer')
        compose(native, context, 'up', '-d', 'audit-media-timer')
        register(native, context, run, 'register-timer')
        job(native, context, 'timer-start', 'audit-runner', profile, 'timer-start')
        register(native, context, run, 'register-timer-started')
        found, actual_rows, _ = collect(native, context, run)
        require({row['labels']['com.docker.compose.service'] for row in actual_rows} == ROLES
                and set(found['volumes']) == {project + '_' + key for key in VOLUME_KEYS}, 'actual complete six roles and all declared dedicated volumes required')
    elif args.phase == 'observe-gc':
        require(read_json(run / 'phase-start-timer.json')['native_exit'] == 0, 'actual original Timer start required')
        register(native, context, run, 'register-gc-observe')
        job(native, context, 'gc-observe', 'audit-runner', '/media-environment/gc-observe.php', 'observe')
    elif args.phase == 'close':
        found, rows, network = collect(native, context, run)
        require(foreign(inspected(native, lines(docker(native, 'ps', '-aq', '--no-trunc'))), project) == read_json(run / 'foreign-before.json'), 'foreign full mounts/networks changed')
        if any((r['labels'] or {}).get('com.docker.compose.service') == 'audit-media-timer' and r['state']['Running'] for r in rows):
            require((run / 'phase-start-timer.json').is_file(), 'Timer control prerequisite missing; diagnose before cleanup')
            job(native, context, 'timer-stop', 'audit-runner', '/workspace/help/audit-regressions/isolated/lib/media-normal-profile.php', 'timer-stop')
        if any((r['labels'] or {}).get('com.docker.compose.service') == 'audit-app' and r['state']['Running'] for r in rows):
            require((run / 'phase-start.json').is_file(), 'App control prerequisite missing; diagnose before cleanup')
            job(native, context, 'stop', 'audit-runner', '/media-environment/control.php', 'stop')
        found, rows, network = collect(native, context, run)
        aliases = {m['Source'] for r in rows for m in r['mounts'] if m.get('Name') in found['volumes']}
        global_rows = inspected(native, lines(docker(native, 'ps', '-aq', '--no-trunc')))
        for row in global_rows:
            if row['id'] in found['containers']: continue
            require(not any(m.get('Name') in found['volumes'] or (isinstance(m.get('Source'), str) and
                        any(m['Source'] == root or m['Source'].startswith(root.rstrip('/') + '/') for root in aliases)) for m in row['mounts'])
                    and not any(v.get('NetworkID') == network['Id'] for v in row['networks'].values()), 'foreign owned resource consumer')
        for row in rows:
            require(not row['state'].get('Restarting') and not row['state'].get('OOMKilled'), 'unexpected resource state requires diagnosis')
            role = row['labels']['com.docker.compose.service']
            if row['state']['Running']:
                require(role in {'audit-app', 'audit-media-timer', 'audit-mysql', 'audit-redis'}, 'unfinished bootstrap/runner; do not kill')
                docker(native, 'stop', '--time=-1', row['id'])
            ended = inspected(native, [row['id']])[0]
            require(not ended['state']['Running'] and ended['state']['Pid'] == 0 and ended['state']['Status'] in {'exited', 'created'}, 'ordinary stopped/created state required')
            if role in {'audit-mysql', 'audit-redis'}:
                require(ended['state']['Status'] == 'exited' and ended['state']['ExitCode'] == 0, 'actual ordinary database natural exited0 required')
            if role in {'audit-app', 'audit-media-timer'} and ended['state']['Status'] == 'exited':
                require(ended['state']['ExitCode'] == 0, 'actual SIGTERM-handled App supervisor natural0 required')
            docker(native, 'rm', row['id'])
        fresh_network = json.loads(docker(native, 'network', 'inspect', '--format', '{{json .}}', network['Id']))
        require(fresh_network['Containers'] == {}, 'dedicated network must be empty')
        docker(native, 'network', 'rm', network['Id'])
        for volume in found['volumes']: docker(native, 'volume', 'rm', volume)
        require(foreign(inspected(native, lines(docker(native, 'ps', '-aq', '--no-trunc'))), project) == read_json(run / 'foreign-before.json'), 'foreign full mounts/networks changed after close')
    else:
        require(read_json(run / 'phase-close.json')['native_exit'] == 0, 'actual normal close0 required')
        # Six independent scopes: each uses one label, never an intersected
        # audit/project/run filter that can hide a partially-labelled remnant.
        actual = {}
        for label_name, label_value in [('project', 'com.docker.compose.project=' + project),
                                        ('run', 'tianli.audit.run=' + run_id)]:
            for kind, argv in [('containers', ['ps', '-aq', '--no-trunc']),
                               ('networks', ['network', 'ls', '-q']),
                               ('volumes', ['volume', 'ls', '-q'])]:
                name = label_name + '-' + kind
                output = docker(native, *argv, '--filter', 'label=' + label_value)
                require(output == b'', 'independent fresh single-label selector not empty')
                actual[name] = []
        require(foreign(inspected(native, lines(docker(native, 'ps', '-aq', '--no-trunc'))), project) == read_json(run / 'foreign-before.json'), 'foreign complete mount/net continuity')
        put(run / 'independent-zero.json', {'schema': 1, 'run_id': run_id, 'source_sha': SOURCE, 'fresh_queries': actual,
                                          'status': 'NORMAL_MEDIA_SIX_FRESH_SELECTORS_EMPTY'})
    # No success file is emitted on any failed native command or assertion.
    put(receipt, {'schema': 1, 'phase': args.phase, 'run_id': run_id, 'source_sha': SOURCE, 'native_exit': 0,
                  'completed_ns': time.time_ns(), 'business_acceptance': 'NOT_SIGNED_BY_ENVIRONMENT_ORCHESTRATION'})
    print(json.dumps({'status': 'NORMAL_MEDIA_PHASE_COMPLETE', 'phase': args.phase, 'run_id': run_id}))

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'NORMAL_MEDIA_PHASE_STOPPED', 'class': type(error).__name__, 'code': str(error) if isinstance(error, RuntimeError) else 'CONTROL_ERROR'}))
        sys.exit(1)
