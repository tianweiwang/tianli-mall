"""Nonempty-history source preparation. Runtime wiring remains incomplete."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time

PREP = Path(__file__).resolve().parent
ATTEMPT_CONTEXT = None
PHP = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
MYSQL = 'sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4'
REDIS = 'sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e'
VOLUMES = ['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets']
ARTIFACTS = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php', 'seed-source-inverse.json',
             'legacy-app.php', 'legacy-files-bootstrap.php', 'legacy-normal-config.php',
             'legacy-actors.php', 'legacy-history-http.php', 'legacy-history-channel.php',
             'legacy-history-producer.php', 'legacy-history-router.php']
LEGACY_SOURCE = '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
LEGACY_ENV_TEMPLATES = {
    'crmeb/public/install/.env': (518, 'cc2cd4add8cd6534d433101a2f0d095f3ea5b58d7703201ae779972b7463df91'),
    'template/admin/.env': (199, 'a17eca0b39f6e17d343221138a0ba59bd2a75c64e7ecc1f594b829e620921562'),
}
LEGACY_CONTROL = [
    ('current', 'help/audit-regressions/isolated/ms4-migration.php', '/workspace/help/audit-regressions/isolated/ms4-migration.php'),
    ('current', 'help/audit-regressions/isolated/lib/harness.php', '/workspace/help/audit-regressions/isolated/lib/harness.php'),
    ('current', 'help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/guard.php'),
    ('bundle', 'legacy-app.php', '/run/nonempty/legacy-app.php'),
    ('bundle', 'legacy-files-bootstrap.php', '/run/nonempty/legacy-files-bootstrap.php'),
    ('bundle', 'legacy-normal-config.php', '/run/nonempty/legacy-normal-config.php'),
    ('bundle', 'legacy-actors.php', '/run/nonempty/legacy-actors.php'),
    ('bundle', 'legacy-history-http.php', '/run/nonempty/legacy-history-http.php'),
    ('bundle', 'legacy-history-channel.php', '/run/nonempty/legacy-history-channel.php'),
    ('bundle', 'legacy-history-producer.php', '/run/nonempty/legacy-history-producer.php'),
    ('bundle', 'legacy-history-router.php', '/run/nonempty/legacy-history-router.php'),
]
DRIVER = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
          'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms4-migration.php',
          'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
          'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php']
REFERENCES = ['help/audit-regressions/isolated/bootstrap.sh', 'help/audit-regressions/isolated/compose.yml',
              'help/audit-regressions/isolated/compose.ms2-migration.yml', 'help/audit-regressions/isolated/ms2-migration.php']
INSPECT = '{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}}}'
IMMUTABLE_FIELDS = ('id', 'image', 'hostname', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
                    'network_mode', 'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop',
                    'security_options', 'created_at')


def expect(value, code):
    if not value:
        raise RuntimeError(code)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def load(path):
    path = Path(path)
    expect(path.is_file() and not path.is_symlink(), 'REGULAR_BOUND_INPUT')
    return json.loads(path.read_text(encoding='utf-8-sig'))


def new(path, value):
    path = Path(path)
    expect(path.parent.is_dir() and not path.exists() and not path.is_symlink(), 'CREATE_ONLY_OUTPUT')
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    with path.open('xb') as stream:
        expect(stream.write(raw) == len(raw), 'FULL_OUTPUT_WRITE')
        stream.flush()
    expect(path.read_bytes() == raw, 'FULL_OUTPUT_READBACK')
    return digest(raw)


def git(repo, *args):
    env = os.environ.copy()
    env['GIT_OPTIONAL_LOCKS'] = '0'
    result = subprocess.run(['git', '-c', 'safe.directory=' + Path(repo).as_posix(), '-C', str(repo), *args],
                            capture_output=True, encoding='utf-8', env=env)
    expect(result.returncode == 0, 'GIT_READ')
    return result.stdout.rstrip('\r\n')


def clean(repo, source):
    expect(re.fullmatch('[a-f0-9]{40}', source) and git(repo, 'rev-parse', 'HEAD') == source, 'EXACT_CURRENT_HEAD')
    expect(not git(repo, 'status', '--porcelain=v1', '--untracked-files=all'), 'COMPLETE_CLEAN_SOURCE')
    for relative in ['crmeb/.env', 'crmeb/public/install.lock']:
        path = Path(repo) / relative
        expect(not path.exists() and not path.is_symlink(), 'NO_HOST_CONFIGURATION')


def source_row(root, relative):
    path = Path(root) / relative
    expect(path.is_file() and not path.is_symlink(), 'BOUND_REGULAR_SOURCE')
    raw = path.read_bytes()
    return {'path': relative, 'bytes': len(raw), 'sha256': digest(raw)}


def seed_inverse(repo):
    source = (Path(repo) / REFERENCES[-1]).read_text(encoding='utf-8')
    before = re.search(r'^function m2f_seed\(array &\$evidence\):array \{\n.*?^\}\n(?=\nfunction)', source, re.M | re.S)
    code = (PREP / 'ms4-initialize.php').read_text(encoding='utf-8')
    after = re.search(r'^function m4i_seed\(array &\$evidence\):array \{\n.*?^\}\n(?=\nfunction)', code, re.M | re.S)
    expect(before is not None and after is not None, 'FINITE_SEED_SOURCE')
    inverse = after.group().replace('function m4i_seed(', 'function m2f_seed(', 1).replace('m4i_source();', 'm2f_source();')
    expect(inverse == before.group(), 'WHOLE_SEED_SOURCE_INVERSE')
    record = load(PREP / 'seed-source-inverse.json')
    expect(record['source_sha256'] == digest((Path(repo) / REFERENCES[-1]).read_bytes())
           and record['before_function_sha256'] == digest(before.group().encode())
           and record['after_function_sha256'] == digest(after.group().encode()), 'SEED_PROVENANCE_MATCH')


def legacy_namespace(repo):
    """Full old namespace, except Git metadata and the isolated runtime overlay."""
    root = Path(repo).resolve(strict=True)
    files, directories = [], []
    def unreadable(error):
        raise error
    for directory, names, leaves in os.walk(root, followlinks=False, onerror=unreadable):
        parent = Path(directory)
        for name in sorted(names[:]):
            path = parent / name
            relative = path.relative_to(root).as_posix()
            if relative in ['.git', 'crmeb/runtime']:
                expect(not path.is_symlink() and not path.is_junction(), 'LEGACY_NO_EXCLUDED_SOURCE_LINK')
                names.remove(name)
                continue
            expect(not path.is_symlink() and not path.is_junction()
                   and path.resolve(strict=True).is_relative_to(root), 'LEGACY_OWNED_REGULAR_DIRECTORY')
            directories.append(relative)
        for name in sorted(leaves):
            path = parent / name
            relative = path.relative_to(root).as_posix()
            if relative == '.git':
                expect(not path.is_symlink(), 'LEGACY_NO_EXCLUDED_SOURCE_LINK')
                continue
            expect(name not in ['.env', 'install.lock', 'private.env', 'legacy.env']
                   or relative in LEGACY_ENV_TEMPLATES, 'LEGACY_NO_PRIVATE_SOURCE_FILE')
            expect(path.is_file() and not path.is_symlink()
                   and path.resolve(strict=True).is_relative_to(root), 'LEGACY_OWNED_REGULAR_FILE')
            files.append(relative)
    expect(len(files) == len(set(files)) and len(directories) == len(set(directories)), 'LEGACY_UNIQUE_NAMESPACE')
    return sorted(files), sorted(directories)


def legacy_authority(repo, legacy_repo):
    root = Path(legacy_repo).resolve(strict=True)
    expect(root != Path(repo).resolve(strict=True) and not root.is_relative_to(PREP), 'SEPARATE_OLD_APPLICATION_SOURCE')
    clean(root, LEGACY_SOURCE)
    files, directories = legacy_namespace(root)
    required = ['crmeb/vendor/autoload.php', 'crmeb/vendor/composer/autoload_real.php',
                'crmeb/vendor/topthink/framework/src/think/App.php', 'crmeb/app/provider.php',
                'crmeb/app/common.php', 'crmeb/public/index.php', 'crmeb/public/install/crmeb.sql',
                'crmeb/app/services/pay/YuePayServices.php']
    expect(all(path in files for path in required), 'COMPLETE_REQUIRED_OLD_APPLICATION')
    control = []
    for kind, relative, destination in LEGACY_CONTROL:
        row = source_row(repo if kind == 'current' else PREP, relative)
        control.append(row | {'path': destination})
    value = {'schema': 1, 'legacy_source_sha': LEGACY_SOURCE,
             'requested_legacy_repo_source': root.as_posix(),
             'legacy_source_files': [source_row(root, path) for path in files],
             'legacy_source_directories': directories,
             'control_files': sorted(control, key=lambda row: row['path'])}
    for path, (size, sha) in LEGACY_ENV_TEMPLATES.items():
        expect(source_row(root, path) == {'path': path, 'bytes': size, 'sha256': sha}, 'EXACT_OLD_SOURCE_ENV_TEMPLATE')
    clean(root, LEGACY_SOURCE)
    return value


def verify_legacy_authority(binding):
    value = binding['nonempty_history']
    expect(type(value) is dict and set(value) == {'schema', 'legacy_source_sha', 'requested_legacy_repo_source',
        'legacy_source_files', 'legacy_source_directories', 'control_files'}
        and type(value['schema']) is int and value['schema'] == 1
        and value['legacy_source_sha'] == LEGACY_SOURCE, 'EXACT_OLD_SOURCE_AUTHORITY')
    expect(legacy_authority(binding['requested_repo_source'], value['requested_legacy_repo_source']) == value,
           'COMPLETE_OLD_NAMESPACE_AND_CONTROL_BYTES')


def freeze(repo, source, output, legacy_repo):
    repo = Path(repo).resolve(strict=True)
    expect(not Path(output).resolve().is_relative_to(repo), 'PROOF_OUTSIDE_REPO')
    clean(repo, source)
    entry = (repo / DRIVER[3]).read_text(encoding='utf-8')
    match = re.search(r'function m4m_sourcePaths\(\): array\s*\{\s*\$paths = \[(.*?)\];', entry, re.S)
    expect(match is not None and sorted(re.findall(r"'([^']+)'", match.group(1))) == DRIVER, 'ACTUAL_EIGHT_DRIVER_FILES')
    for relative in DRIVER + REFERENCES:
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_SOURCE_REFERENCE')
    seed_inverse(repo)
    value = {'schema': 1, 'status': 'CLEAN_SOURCE_FROZEN_ONLY', 'source_sha': source, 'requested_repo_source': repo.as_posix(),
             'driver_source_files': [source_row(repo, p) for p in DRIVER],
             'preparation_source_references': [source_row(repo, p) for p in REFERENCES],
             'artifact_files': [source_row(PREP, p) for p in ARTIFACTS],
             'nonempty_history': legacy_authority(repo, legacy_repo),
             'runtime_SQL_App': 'NOT RUN', 'nonempty_history_runtime': 'NOT_READY_FOR_RUNTIME'}
    expect(not Path(output).resolve().is_relative_to(Path(legacy_repo).resolve(strict=True)), 'PROOF_OUTSIDE_OLD_SOURCE')
    clean(repo, source)
    sha = new(output, value)
    return {'status': value['status'], 'source_sha': source, 'freeze_sha256': sha, 'runtime_SQL_App': 'NOT RUN'}


def verify_freeze(binding):
    repo = Path(binding['requested_repo_source'])
    clean(repo, binding['source_sha'])
    expect([r['path'] for r in binding['driver_source_files']] == DRIVER, 'FROZEN_DRIVER_SET')
    expect([r['path'] for r in binding['preparation_source_references']] == REFERENCES, 'FROZEN_PREPARATION_SET')
    expect([r['path'] for r in binding['artifact_files']] == ARTIFACTS, 'FROZEN_ARTIFACT_SET')
    for rows, root in [(binding['driver_source_files'], repo), (binding['preparation_source_references'], repo), (binding['artifact_files'], PREP)]:
        for row in rows:
            expect(source_row(root, row['path']) == row, 'FROZEN_BYTE_IDENTITY')
    seed_inverse(repo)
    verify_legacy_authority(binding)


def native(context, args, label):
    result = subprocess.run(['docker', *args], capture_output=True)
    evidence = Path(context['run_dir']) / 'evidence'
    sequence = 1
    while (evidence / ('native-%04d.json' % sequence)).exists():
        sequence += 1
    new(evidence / ('native-%04d.json' % sequence), {'stage': label, 'native_exit': result.returncode,
        'stdout_bytes': len(result.stdout), 'stdout_sha256': digest(result.stdout),
        'stderr_bytes': len(result.stderr), 'stderr_sha256': digest(result.stderr), 'private_environment_logs_omitted': True})
    expect(result.returncode == 0, 'NATIVE_' + label)
    return result.stdout.decode('utf-8').strip()


def compose(context, args, label, proof='initializer', phase='prepare'):
    env = os.environ.copy()
    env.update({'AUDIT_RUN_ID': context['run_id'], 'AUDIT_PROJECT': context['project'], 'AUDIT_SOURCE_SHA': context['source_sha'],
                'AUDIT_REPO_DIR': context['repo_dir'], 'AUDIT_RUN_DIR': context['run_dir'], 'MS4_PREP_DIR': context['prep_dir'],
                'MS4_PROOF_NAME': proof, 'MS4_BOOTSTRAP_PHASE': phase, 'MS4_BOOTSTRAP_PRIVATE_MODE': 'rw' if phase == 'prepare' else 'ro',
                'LEGACY_REPO_DIR': context['legacy_repo_dir'], 'NONEMPTY_FREEZE_BYTES': str(context['freeze_bytes']),
                'NONEMPTY_FREEZE_SHA256': context['freeze_sha256']})
    result = subprocess.run(['docker', 'compose', '--project-name', context['project'], '--file', str(PREP / 'compose.yml'), *args], capture_output=True, env=env)
    evidence = Path(context['run_dir']) / 'evidence'
    new(evidence / (label + '-compose-native.json'), {'stage': label, 'native_exit': result.returncode,
        'stdout_sha256': digest(result.stdout), 'stderr_sha256': digest(result.stderr), 'stdout_bytes': len(result.stdout), 'stderr_bytes': len(result.stderr)})
    expect(result.returncode == 0, 'COMPOSE_' + label)
    return result.stdout.decode('utf-8').strip()


def ids(context, kind, label):
    argv = {'container': ['ps', '-aq', '--no-trunc'], 'network': ['network', 'ls', '-q'], 'volume': ['volume', 'ls', '-q']}[kind]
    return native(context, [*argv, '--filter', 'label=' + label], 'list-' + kind).splitlines()


def inspect(context, cid, label):
    result = json.loads(native(context, ['inspect', '--format', INSPECT, cid], label))
    expect(result['id'] == cid, 'EXACT_NATIVE_CID')
    return result


def owned(context, facts, service):
    labels = facts['labels']
    expect(labels.get('tianli.audit') == '1' and labels.get('tianli.audit.run') == context['run_id']
           and labels.get('com.docker.compose.project') == context['project'] and labels.get('com.docker.compose.service') == service, 'OWNED_NATIVE_LABELS')
    expect(facts['image'] == ({'audit-mysql': MYSQL, 'audit-redis': REDIS}.get(service, PHP)) and facts['restart_count'] == 0, 'PINNED_NATIVE_IMAGE_RESTART')
    expect(set(facts['networks']) == {context['project'] + '_isolation'} and all(v is None for v in (facts['ports'] or {}).values()), 'ONE_INTERNAL_NETWORK_NO_PORTS')
    expect(not facts['port_bindings'], 'NO_CREATED_HOST_PORT_BINDINGS')
    if service not in ['audit-mysql', 'audit-redis']:
        workdir = '/legacy-workspace/crmeb' if service in ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'] else '/workspace'
        expect(facts['read_only'] is True and facts['privileged'] is False and facts['entrypoint'] == ['php'] and facts['workdir'] == workdir, 'NATIVE_HELPER_BOUNDARY')
        expect(facts['cap_drop'] == ['ALL'] and facts['security_options'] in [['no-new-privileges'], ['no-new-privileges:true']], 'NATIVE_CAPABILITIES_PRIVILEGE_BOUNDARY')


def role_id(context, role, proof='initializer', phase='prepare'):
    values = compose(context, ['ps', '-aq', role], 'lookup-' + proof + '-' + role, proof, phase).splitlines()
    expect(len(values) == 1 and re.fullmatch('[a-f0-9]{64}', values[0]), 'ONE_ACTUAL_ROLE_CID')
    return values[0]


def global_consumers(context, allowed):
    all_ids = native(context, ['ps', '-aq', '--no-trunc'], 'global-consumers').splitlines()
    if not all_ids:
        return
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'
    lines = native(context, ['inspect', '--format', template, *all_ids], 'global-consumer-facts').splitlines()
    private = {context['project'] + '_' + name for name in VOLUMES}
    for line in lines:
        row = json.loads(line)
        volumes = {m.get('Name') for m in row['mounts'] if m.get('Type') == 'volume'}
        joined = context['project'] + '_isolation' in row['networks']
        if volumes & private or joined:
            expect(row['id'] in allowed, 'FOREIGN_RESOURCE_CONSUMER')


def active(context):
    project = set(ids(context, 'container', 'com.docker.compose.project=' + context['project']))
    run = set(ids(context, 'container', 'tianli.audit.run=' + context['run_id']))
    expect(project == run, 'PROJECT_RUN_CONTAINER_SET')
    return project


def same_created(before, fresh):
    expect(all(key in before and key in fresh and before[key] == fresh[key] for key in IMMUTABLE_FIELDS if key != 'mounts'),
           'SAME_COMPLETE_CREATED_IMMUTABLE_FIELDS')
    expect(isinstance(before['mounts'], list) and isinstance(fresh['mounts'], list), 'NATIVE_COMPLETE_MOUNT_ARRAYS')
    expect(sorted(json.dumps(entry, sort_keys=True) for entry in before['mounts'])
           == sorted(json.dumps(entry, sort_keys=True) for entry in fresh['mounts']), 'SAME_COMPLETE_CREATED_MOUNTS')
    expect(before['restart_count'] == fresh['restart_count'] == 0 and set(before['networks']) == set(fresh['networks']),
           'SAME_CREATED_NETWORK_RESTART')


def base_roles(context):
    initial = context['initial_service_ids']
    expect(set(initial) == {'audit-mysql', 'audit-redis'} and len(set(initial.values())) == 2
           and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in initial.values()), 'EXACT_INITIAL_BASE_CIDS')
    expect(set(context['initial_service_created']) == set(initial), 'COMPLETE_INITIAL_BASE_CREATED')
    for role, cid in initial.items():
        facts = context['initial_service_created'][role]
        expect(facts['id'] == cid and facts['status'] == 'created' and facts['pid'] == 0, 'ACTUAL_INITIAL_UNSTARTED_BASE')
        owned(context, facts, role)
    return {cid: role for role, cid in initial.items()}


def bound_created(context, proof, role):
    facts = load(Path(context['run_dir']) / 'evidence' / (proof + '-created.json'))
    expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
           and facts['proof_name'] == proof and re.fullmatch('[a-f0-9]{64}', facts['id'])
           and facts['status'] == 'created' and facts['pid'] == 0, 'BOUND_ACTUAL_CREATED_RECEIPT')
    owned(context, facts, role)
    return facts


def proof_expected(context, proof):
    receipts = {
        'prepare': [('prepare', 'audit-bootstrap')],
        'initializer': [('initializer', 'audit-ms4-initialize')],
        'driver': [('driver', 'audit-ms4-migration')],
        'register-initializer': [('initializer', 'audit-ms4-initialize'), ('register-initializer', 'audit-bootstrap')],
        'register-driver': [('driver', 'audit-ms4-migration'), ('register-driver', 'audit-bootstrap')],
        'authorize-cleanup': [('driver', 'audit-ms4-migration'), ('authorize-cleanup', 'audit-bootstrap')],
        'legacy-files-bootstrap': [('legacy-files-bootstrap', 'audit-legacy-files-bootstrap')],
        'register-legacy-files-bootstrap': [('legacy-files-bootstrap', 'audit-legacy-files-bootstrap'), ('register-legacy-files-bootstrap', 'audit-bootstrap')],
        'legacy-history-producer': [('legacy-history-producer', 'audit-legacy-history-producer')],
        'register-legacy-history-producer': [('legacy-history-producer', 'audit-legacy-history-producer'), ('register-legacy-history-producer', 'audit-bootstrap')],
    }
    expect(proof in receipts, 'KNOWN_PROOF_EXPECTED_ROLES')
    expected = base_roles(context)
    for name, role in receipts[proof]:
        cid = bound_created(context, name, role)['id']
        expect(cid not in expected, 'DISTINCT_KNOWN_CREATED_CID')
        expected[cid] = role
    return expected


def collect(context, expected, initial_services=None, publish=True):
    expect(active(context) == set(expected), 'EXACT_ACTIVE_NATIVE_CONTAINERS')
    if initial_services is None:
        base = base_roles(context)
    else:
        expect(not any(key in context for key in ['initial_service_ids', 'initial_service_created', 'initial_volumes', 'initial_network']),
               'INITIAL_NATIVE_IDENTITY_CREATE_ONCE')
        expect(set(initial_services) == {'audit-mysql', 'audit-redis'} and len(set(initial_services.values())) == 2,
               'INITIAL_TWO_BASE_ROLES')
        base = {cid: role for role, cid in initial_services.items()}
    expect(all(expected.get(cid) == role for cid, role in base.items()), 'EXPECTED_INCLUDES_ORIGINAL_BASE')
    network_ids = ids(context, 'network', 'com.docker.compose.project=' + context['project'])
    run_network = ids(context, 'network', 'tianli.audit.run=' + context['run_id'])
    expect(len(network_ids) == 1 and network_ids == run_network, 'EXACT_NATIVE_NETWORK')
    template = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}}}'
    network = json.loads(native(context, ['network', 'inspect', '--format', template, network_ids[0]], 'native-network'))
    expect(network['Internal'] is True and network['Name'] == context['project'] + '_isolation'
           and network['Labels']['tianli.audit.run'] == context['run_id'] and network['Labels']['com.docker.compose.project'] == context['project'], 'NATIVE_INTERNAL_NETWORK')
    if initial_services is None:
        expect(context['network_id'] == network['Id'] and context['initial_network'] == network, 'SAME_INITIAL_NATIVE_NETWORK')
    rows = []
    collected = {}
    for cid, role in expected.items():
        facts = inspect(context, cid, 'collect-native'); owned(context, facts, role)
        collected[cid] = facts
        if cid in base:
            if initial_services is None:
                same_created(context['initial_service_created'][role], facts)
            else:
                expect(facts['status'] == 'created' and facts['pid'] == 0, 'FIRST_ACTUAL_UNSTARTED_BASE')
        rows.append({'id': facts['id'], 'image_id': facts['image'], 'mounts': facts['mounts'], 'labels': facts['labels'],
                     'networks': facts['networks'], 'ports': facts['ports'], 'read_only': facts['read_only'],
                     'privileged': facts['privileged'], 'network_mode': facts['network_mode']})
    volumes = ids(context, 'volume', 'com.docker.compose.project=' + context['project'])
    run_volumes = ids(context, 'volume', 'tianli.audit.run=' + context['run_id'])
    expect(set(volumes) == set(run_volumes) == {context['project'] + '_' + name for name in VOLUMES}, 'EXACT_SIX_NEW_VOLUMES')
    volume_rows = []
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    for volume in sorted(volumes):
        row = json.loads(native(context, ['volume', 'inspect', '--format', template, volume], 'native-volume'))
        expect(row['name'] == volume and isinstance(row['created_at'], str) and row['created_at']
               and isinstance(row['labels'], dict), 'COMPLETE_ACTUAL_VOLUME_IDENTITY')
        expect(row['labels'].get('tianli.audit.run') == context['run_id'] and row['labels'].get('com.docker.compose.project') == context['project'], 'NATIVE_VOLUME_LABELS')
        volume_rows.append(row)
    if initial_services is None:
        expect(volume_rows == context['initial_volumes'], 'SAME_INITIAL_SIX_VOLUME_IDENTITIES')
    else:
        context['initial_service_ids'] = dict(initial_services)
        context['initial_service_created'] = {role: collected[cid] for cid, role in base.items()}
        context['initial_volumes'] = volume_rows
        context['initial_network'] = network
        context['network_id'] = network['Id']
    global_consumers(context, set(expected))
    if not publish:
        return network['Id']
    # These four files are the original collector's current observation inputs.
    # Previous observation copies are retained as immutable numbered evidence.
    root = Path(context['run_dir'])
    for name, value in [('network.json', network), ('containers.jsonl', rows), ('volumes.txt', sorted(volumes)), ('volumes.jsonl', volume_rows)]:
        path = root / name
        if path.exists():
            sequence = 1
            while (root / 'evidence' / ('previous-%04d-%s' % (sequence, name))).exists():
                sequence += 1
            raw = path.read_bytes()
            backup = root / 'evidence' / ('previous-%04d-%s' % (sequence, name))
            with backup.open('xb') as stream:
                expect(stream.write(raw) == len(raw), 'OBSERVATION_BACKUP_BYTES')
        if name == 'network.json':
            raw = json.dumps(value).encode() + b'\n'
        elif name.endswith('jsonl'):
            raw = ''.join(json.dumps(row) + '\n' for row in value).encode()
        else:
            raw = ('\n'.join(value) + '\n').encode()
        path.write_bytes(raw)
    return network['Id']


def created(context, role, proof, phase='initialize'):
    cid = role_id(context, role, proof, phase)
    facts = inspect(context, cid, proof + '-native-created'); owned(context, facts, role)
    expect(facts['status'] == 'created' and facts['pid'] == 0, 'UNSTARTED_NATIVE_FINITE')
    facts.update({'run_id': context['run_id'], 'source_sha': context['source_sha'], 'proof_name': proof})
    new(Path(context['run_dir']) / 'evidence' / (proof + '-created.json'), facts)
    return cid


def observe(context, cid, proof, seconds):
    end = time.monotonic() + seconds
    while True:
        facts = inspect(context, cid, proof + '-observe')
        expect(facts['restart_count'] == 0 and facts['status'] in ['created', 'running', 'exited'], 'SAME_FINITE_HANDLE')
        if facts['status'] == 'exited':
            expect(facts['pid'] == 0, 'ACTUAL_TERMINAL_PID_ZERO')
            path = Path(context['run_dir']) / 'evidence' / (proof + '-terminal.json')
            if not path.exists():
                new(path, facts)
            return facts
        if time.monotonic() >= end:
            return {'id': cid, 'status': facts['status'], 'observation': 'LIVE_SAME_HANDLE_PRESERVED'}
        time.sleep(1)


def remove_finished(context, cid, role, proof, expected):
    expect(expected.get(cid) == role, 'REMOVE_EXPLICIT_KNOWN_ROLE_CID')
    collect(context, expected)
    before = bound_created(context, proof, role)
    expect(before['id'] == cid, 'REMOVE_SAME_BOUND_CREATED_CID')
    facts = inspect(context, cid, proof + '-pre-remove'); owned(context, facts, role)
    same_created(before, facts)
    expect(facts['status'] == 'exited' and facts['pid'] == 0 and facts['exit_code'] == 0, 'REMOVE_ONLY_NORMAL_TERMINAL_OWN_HELPER')
    native(context, ['rm', cid], proof + '-exact-nonforce-remove')
    collect(context, {known: service for known, service in expected.items() if known != cid})


def helper(context, proof, phase, expected):
    collect(context, expected)
    compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-bootstrap'], proof + '-create', proof, phase)
    cid = created(context, 'audit-bootstrap', proof, phase)
    expect(active(context) == set(expected) | {cid}, 'ONLY_REGISTERED_TRANSIENT_HELPER')
    global_consumers(context, set(expected) | {cid})
    native(context, ['start', cid], proof + '-start')
    facts = observe(context, cid, proof, 30)
    if facts.get('status') != 'exited':
        return facts
    result = load(Path(context['run_dir']) / 'evidence' / (proof + '-result.json'))
    expect(facts['exit_code'] == 0 and result['native_exit'] == 0 and result['binding']['container_id'] == cid, 'ACTUAL_HELPER_NORMAL_RECEIPT')
    remove_finished(context, cid, 'audit-bootstrap', proof, expected | {cid: 'audit-bootstrap'})
    return result


def history_archive(context, phase):
    """Save the inputs which registration/collection will replace, once per phase."""
    expect(phase in ['before-legacy-files', 'legacy-files-complete'], 'FINITE_HISTORY_ARCHIVE_PHASE')
    root = Path(context['run_dir'])
    leaves = ['manifest.json', 'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
              'actual-run-context.json', 'preparation-source-binding.json']
    if phase == 'before-legacy-files':
        leaves += ['evidence/initializer-created.json', 'evidence/initializer-terminal.json', 'evidence/initializer-result.json']
    else:
        leaves += ['evidence/legacy-files-bootstrap-created.json', 'evidence/legacy-files-bootstrap-terminal.json',
                   'evidence/audit-legacy-files-bootstrap-container-created.json',
                   'evidence/audit-legacy-files-bootstrap-source-binding.json',
                   'evidence/audit-legacy-files-bootstrap-result.json',
                   'evidence/register-legacy-files-bootstrap-created.json',
                   'evidence/register-legacy-files-bootstrap-terminal.json',
                   'evidence/register-legacy-files-bootstrap-result.json']
    parent = root / 'evidence/nonempty-history-phases'
    parent.mkdir(exist_ok=True)
    expect(parent.is_dir() and not parent.is_symlink(), 'HISTORY_ARCHIVE_REGULAR_PARENT')
    output = parent / phase
    expect(not output.exists() and not output.is_symlink(), 'HISTORY_PHASE_CREATE_ONLY')
    output.mkdir()
    rows = []
    for relative in leaves:
        path = root / relative
        expect(path.is_file() and not path.is_symlink(), 'HISTORY_PHASE_ACTUAL_INPUT')
        before = path.stat(); raw = path.read_bytes(); after = path.stat()
        fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
        expect(all(getattr(before, key) == getattr(after, key) for key in fields), 'HISTORY_ARCHIVE_INPUT_UNCHANGED')
        destination = output / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        with destination.open('xb') as stream:
            expect(stream.write(raw) == len(raw), 'HISTORY_PHASE_FULL_COPY')
            stream.flush()
        expect(destination.read_bytes() == raw and path.read_bytes() == raw, 'HISTORY_PHASE_EXACT_COPY_READBACK')
        rows.append({'path': relative, 'bytes': len(raw), 'sha256': digest(raw),
                     'original_stat': {key: getattr(before, key) for key in fields}})
    record = {'schema': 1, 'phase': phase, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
              'legacy_source_sha': LEGACY_SOURCE, 'files': rows, 'business_acceptance': 'NOT RUN'}
    sha = new(output / 'INDEX.json', record)
    return {'path': (output / 'INDEX.json').as_posix(), 'bytes': (output / 'INDEX.json').stat().st_size, 'sha256': sha}


def history_mount_rows(mounts):
    rows = []
    seen = set()
    for mount in mounts:
        destination = mount['Destination']
        expect(destination not in seen, 'HISTORY_ONE_NATIVE_MOUNT_PER_DESTINATION')
        seen.add(destination)
        if mount['Type'] == 'tmpfs':
            expect(destination in ['/tmp', '/legacy-runtime'] and mount['RW'] is True, 'HISTORY_ONLY_TWO_TMPFS')
            continue
        source = mount['Source']
        match = re.fullmatch(r'([A-Za-z]):(/.*)', source)
        if match:
            source = '/run/desktop/mnt/host/' + match[1].lower() + match[2]
        rows.append({'Type': mount['Type'], 'Source': source, 'Destination': destination,
                     'RW': mount['RW'], 'Name': mount.get('Name', '')})
    return sorted(rows, key=lambda row: json.dumps(row, ensure_ascii=False, separators=(',', ':')) + '\n')


def bind_legacy_files(context):
    root = Path(context['run_dir']); evidence = root / 'evidence'
    facts = bound_created(context, 'legacy-files-bootstrap', 'audit-legacy-files-bootstrap')
    cid = facts['id']; now = inspect(context, cid, 'legacy-files-bind-precheck')
    owned(context, now, 'audit-legacy-files-bootstrap'); same_created(facts, now)
    expect(now['status'] == 'created' and now['pid'] == 0, 'HISTORY_SAME_UNSTARTED_BOOTSTRAP')
    freeze = load(root / 'preparation-source-binding.json')
    authority = freeze['nonempty_history']
    new(evidence / 'audit-legacy-files-bootstrap-container-created.json', facts)
    new(evidence / 'audit-legacy-files-bootstrap-source-binding.json', {
        'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'legacy_source_sha': authority['legacy_source_sha'], 'role': 'audit-legacy-files-bootstrap',
        'container_id': cid, 'authority_sha256': digest((json.dumps(authority, ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': history_mount_rows(facts['mounts'])})
    return cid


def legacy_files_complete(context):
    """Read the original completed file stage; never recreate or retire it."""
    root = Path(context['run_dir']); archive = root / 'evidence/nonempty-history-phases/legacy-files-complete'
    leaves = ['manifest.json', 'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
              'actual-run-context.json', 'preparation-source-binding.json',
              'evidence/legacy-files-bootstrap-created.json', 'evidence/legacy-files-bootstrap-terminal.json',
              'evidence/audit-legacy-files-bootstrap-container-created.json',
              'evidence/audit-legacy-files-bootstrap-source-binding.json',
              'evidence/audit-legacy-files-bootstrap-result.json',
              'evidence/register-legacy-files-bootstrap-created.json',
              'evidence/register-legacy-files-bootstrap-terminal.json',
              'evidence/register-legacy-files-bootstrap-result.json']
    fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    snapshot = {}; raws = {}
    for directory in [archive.parent.parent, archive.parent, archive, archive / 'evidence']:
        expect(directory.is_dir() and not directory.is_symlink(), 'COMPLETED_FILES_REGULAR_ARCHIVE_DIRECTORIES')
    def read(path):
        expect(path.is_file() and not path.is_symlink(), 'COMPLETED_FILES_REGULAR_PUBLIC_ORIGINAL')
        before = path.stat(); raw = path.read_bytes(); after = path.stat()
        stat = tuple(getattr(before, key) for key in fields)
        expect(stat == tuple(getattr(after, key) for key in fields), 'COMPLETED_FILES_FULL_READ_STAT9')
        snapshot[path.as_posix()] = (len(raw), digest(raw), stat)
        return raw
    index = json.loads(read(archive / 'INDEX.json'))
    expect(set(index) == {'schema', 'phase', 'run_id', 'source_sha', 'legacy_source_sha', 'files', 'business_acceptance'}
           and type(index['schema']) is int and index['schema'] == 1 and index['phase'] == 'legacy-files-complete'
           and index['run_id'] == context['run_id'] and index['source_sha'] == context['source_sha']
           and index['legacy_source_sha'] == LEGACY_SOURCE and index['business_acceptance'] == 'NOT RUN'
           and isinstance(index['files'], list) and [row['path'] for row in index['files']] == leaves,
           'COMPLETED_FILES_ORIGINAL_EXACT_15_INDEX')
    actual_files = []; actual_dirs = []
    def walk_error(error):
        raise error
    for parent, dirs, files in os.walk(archive, onerror=walk_error, followlinks=False):
        for name in dirs:
            path = Path(parent) / name
            expect(path.is_dir() and not path.is_symlink(), 'COMPLETED_FILES_NO_LINK_DIRECTORY')
            actual_dirs.append(path.relative_to(archive).as_posix())
        for name in files:
            actual_files.append((Path(parent) / name).relative_to(archive).as_posix())
    expect(sorted(actual_files) == sorted(leaves + ['INDEX.json']) and actual_dirs == ['evidence'],
           'COMPLETED_FILES_EXACT_ARCHIVE_NAMESPACE')
    for row in index['files']:
        expect(set(row) == {'path', 'bytes', 'sha256', 'original_stat'} and type(row['bytes']) is int and row['bytes'] > 0
               and isinstance(row['sha256'], str) and re.fullmatch('[a-f0-9]{64}', row['sha256'])
               and isinstance(row['original_stat'], dict) and set(row['original_stat']) == set(fields)
               and all(type(value) is int for value in row['original_stat'].values())
               and row['original_stat']['st_size'] == row['bytes'], 'COMPLETED_FILES_COMPLETE_ORIGINAL_DESCRIPTOR')
        raw = read(archive / row['path'])
        expect(len(raw) == row['bytes'] and digest(raw) == row['sha256'], 'COMPLETED_FILES_ARCHIVE_FULL_BYTES')
        raws[row['path']] = raw
        if row['path'].startswith('evidence/'):
            expect(read(root / row['path']) == raw, 'COMPLETED_FILES_IMMUTABLE_EVIDENCE_NOT_REPLACED')
    expect(raws['actual-run-context.json'] == read(root / 'actual-run-context.json')
           and json.loads(raws['actual-run-context.json']) == context
           and raws['preparation-source-binding.json'] == read(root / 'preparation-source-binding.json')
           and len(raws['preparation-source-binding.json']) == context['freeze_bytes']
           and digest(raws['preparation-source-binding.json']) == context['freeze_sha256'], 'COMPLETED_FILES_CURRENT_CONTEXT_FREEZE')
    manifest = json.loads(raws['manifest.json']); role = 'audit-legacy-files-bootstrap'
    expect(set(manifest['services']) == {role, 'audit-mysql', 'audit-redis'}
           and manifest['run_id'] == context['run_id'] and manifest['commit_sha'] == context['source_sha']
           and manifest['network'] == {'id': context['network_id'], 'name': context['project'] + '_isolation'}
           and manifest['network_internal'] is True
           and json.loads(raws['network.json']) == context['initial_network'], 'COMPLETED_FILES_ARCHIVED_RESOURCE_IDENTITY')
    expect([json.loads(line) for line in raws['volumes.jsonl'].splitlines()] == context['initial_volumes']
           and raws['volumes.txt'].decode().splitlines() == sorted(row['name'] for row in context['initial_volumes']),
           'COMPLETED_FILES_ALL_ORIGINAL_VOLUMES')
    collected = [json.loads(line) for line in raws['containers.jsonl'].splitlines()]
    expect(len(collected) == 3 and {row['id'] for row in collected} == {service['id'] for service in manifest['services'].values()},
           'COMPLETED_FILES_ARCHIVED_THREE_CONTAINERS')
    for base_role, cid in context['initial_service_ids'].items():
        expect(manifest['services'][base_role]['id'] == cid, 'COMPLETED_FILES_ORIGINAL_BASE_CIDS')
    history = manifest['nonempty_history']
    expect(set(history) == {'schema', 'stages'} and type(history['schema']) is int and history['schema'] == 1
           and set(history['stages']) == {role} and set(history['stages'][role]) == {'created', 'binding'},
           'COMPLETED_FILES_ORIGINAL_STAGE_NOT_LIVE_REPLACEMENT')
    for key, suffix in [('created', '-container-created.json'), ('binding', '-source-binding.json')]:
        leaf = 'evidence/' + role + suffix; raw = raws[leaf]
        expect(history['stages'][role][key] == {'path': '/run/audit/' + leaf, 'bytes': len(raw), 'sha256': digest(raw)},
               'COMPLETED_FILES_ORIGINAL_TWO_STAGE_PINS')
    facts = json.loads(raws['evidence/legacy-files-bootstrap-created.json'])
    terminal = json.loads(raws['evidence/legacy-files-bootstrap-terminal.json'])
    expect(facts == json.loads(raws['evidence/' + role + '-container-created.json'])
           and facts == bound_created(context, 'legacy-files-bootstrap', role)
           and manifest['services'][role]['id'] == facts['id'] and terminal['id'] == facts['id']
           and terminal['status'] == 'exited' and terminal['pid'] == 0 and terminal['exit_code'] == 0,
           'COMPLETED_FILES_SAME_ORIGINAL_NORMAL_BOOTSTRAP')
    same_created(facts, terminal)
    binding = json.loads(raws['evidence/' + role + '-source-binding.json'])
    authority = json.loads(raws['preparation-source-binding.json'])['nonempty_history']
    expect(set(binding) == {'schema', 'run_id', 'source_sha', 'legacy_source_sha', 'role', 'container_id', 'authority_sha256', 'mounts'}
           and type(binding['schema']) is int and binding['schema'] == 1 and binding['run_id'] == context['run_id']
           and binding['source_sha'] == context['source_sha'] and binding['legacy_source_sha'] == LEGACY_SOURCE
           and binding['role'] == role and binding['container_id'] == facts['id']
           and binding['authority_sha256'] == digest((json.dumps(authority, ensure_ascii=False, separators=(',', ':')) + '\n').encode())
           and binding['mounts'] == history_mount_rows(facts['mounts'])
           and facts['command'] == ['/run/nonempty/legacy-files-bootstrap.php', '--freeze-bytes=' + str(context['freeze_bytes']),
                                    '--freeze-sha256=' + context['freeze_sha256']], 'COMPLETED_FILES_ORIGINAL_SOURCE_BINDING')
    result = json.loads(raws['evidence/' + role + '-result.json'])
    expect(result['status'] == 'LEGACY_FILE_INITIALIZATION_ONLY' and result['native_exit'] == 0
           and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
           and result['legacy_source_sha'] == LEGACY_SOURCE and result['freeze_sha256'] == context['freeze_sha256']
           and result['container_id'] == facts['id'] and type(result['checksum_rows']) is int and result['checksum_rows'] > 0
           and all(result[key] is True for key in ['target_native_id_full_validation',
               'complete_target_rows_and_projections_verified', 'old_version_marker_matches', 'non_target_database_and_redis_unchanged'])
           and result['business_acceptance'] == result['migration'] == 'NOT RUN' and result['producer'] == 'NOT IMPLEMENTED',
           'COMPLETED_FILES_FULL_NORMAL_RESULT')
    window = result['marker_add_time_native_window']
    expect(set(window) == {'before', 'after'} and all(type(value) is int for value in window.values())
           and 0 < window['before'] <= window['after'], 'COMPLETED_FILES_REAL_MARKER_WINDOW')
    helper_created = json.loads(raws['evidence/register-legacy-files-bootstrap-created.json'])
    helper_terminal = json.loads(raws['evidence/register-legacy-files-bootstrap-terminal.json'])
    helper_result = json.loads(raws['evidence/register-legacy-files-bootstrap-result.json'])
    expect(helper_created == bound_created(context, 'register-legacy-files-bootstrap', 'audit-bootstrap')
           and helper_terminal['id'] == helper_created['id'] and helper_terminal['status'] == 'exited'
           and helper_terminal['pid'] == 0 and helper_terminal['exit_code'] == 0
           and helper_result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and helper_result['native_exit'] == 0
           and helper_result['run_id'] == context['run_id'] and helper_result['source_sha'] == context['source_sha']
           and helper_result['binding']['container_id'] == helper_created['id']
           and helper_result['binding']['source_sha'] == context['source_sha']
           and helper_result['binding']['freeze_sha256'] == context['freeze_sha256']
           and helper_result['binding']['created_sha256'] == digest(raws['evidence/register-legacy-files-bootstrap-created.json']),
           'COMPLETED_FILES_ORIGINAL_REGISTER_HELPER')
    same_created(helper_created, helper_terminal)
    removals = []
    for path in sorted((root / 'evidence').glob('native-*.json')):
        receipt = load(path)
        if receipt.get('stage') == 'legacy-files-bootstrap-exact-nonforce-remove':
            removals.append(path)
            expect(receipt['native_exit'] == 0 and receipt['stdout_bytes'] == 65
                   and receipt['stdout_sha256'] == digest((facts['id'] + '\n').encode())
                   and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b''),
                   'COMPLETED_FILES_EXACT_NORMAL_REMOVE_RECEIPT')
    expect(len(removals) == 1, 'COMPLETED_FILES_ONE_ORIGINAL_REMOVE')
    read(removals[0])
    for path, expected in list(snapshot.items()):
        read(Path(path)); expect(snapshot[path] == expected, 'COMPLETED_FILES_ALL_INPUTS_SECOND_FULL_READ')
    return {'container_id': facts['id'], 'stage_pins': history['stages'][role], 'snapshot': snapshot}


def legacy_producer_mounts(context, facts):
    authority = load(Path(context['run_dir']) / 'preparation-source-binding.json')['nonempty_history']
    wanted = [{'Type': 'bind', 'Source': source, 'Destination': destination,
               'RW': destination == '/run/audit', 'Name': ''}
              for destination, source in {'/workspace': context['repo_dir'], '/source': context['repo_dir'],
                  '/legacy-source': authority['requested_legacy_repo_source'], '/legacy-workspace': authority['requested_legacy_repo_source'],
                  '/run/nonempty': context['prep_dir'], '/run/audit': context['run_dir']}.items()]
    private = [mount for mount in facts['mounts'] if mount['Destination'] == '/run/audit-app']
    expect(len(private) == 1 and private[0]['Type'] == 'volume' and private[0]['RW'] is False
           and private[0]['Name'] == context['project'] + '_app-secrets'
           and isinstance(private[0]['Source'], str) and private[0]['Source'], 'PRODUCER_ONLY_ORIGINAL_APP_PRIVATE_RO')
    wanted.append({'Type': 'volume', 'Source': private[0]['Source'], 'Destination': '/run/audit-app',
                   'RW': False, 'Name': private[0]['Name']})
    rows = history_mount_rows(facts['mounts'])
    expect(rows == history_mount_rows(wanted) and set(facts['tmpfs']) == {'/tmp', '/legacy-runtime'},
           'PRODUCER_EXACT_SIX_BINDS_ONE_PRIVATE_TWO_TMPFS')
    options = facts['tmpfs']['/legacy-runtime'].split(',')
    modes = [value[5:] for value in options if value.startswith('mode=')]
    expect(len(modes) == 1 and re.fullmatch('0?700', modes[0]) and int(modes[0], 8) == 0o700,
           'PRODUCER_RUNTIME_EXPLICIT_PRIVATE_MODE_0700')
    expect(facts['command'] == ['/run/nonempty/legacy-history-producer.php', 'normal',
           '--freeze-bytes=' + str(context['freeze_bytes']), '--freeze-sha256=' + context['freeze_sha256']],
           'PRODUCER_FIXED_NORMAL_FREEZE_COMMAND')
    return rows


def bind_legacy_producer(context):
    evidence = Path(context['run_dir']) / 'evidence'; role = 'audit-legacy-history-producer'
    facts = bound_created(context, 'legacy-history-producer', role)
    now = inspect(context, facts['id'], 'legacy-producer-bind-precheck'); owned(context, now, role); same_created(facts, now)
    expect(now['status'] == 'created' and now['pid'] == 0, 'PRODUCER_SAME_UNSTARTED_CREATED')
    mounts = legacy_producer_mounts(context, facts)
    authority = load(Path(context['run_dir']) / 'preparation-source-binding.json')['nonempty_history']
    new(evidence / (role + '-container-created.json'), facts)
    new(evidence / (role + '-source-binding.json'), {'schema': 1, 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'legacy_source_sha': LEGACY_SOURCE, 'role': role,
        'container_id': facts['id'], 'authority_sha256': digest((json.dumps(authority, ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': mounts})
    return facts['id']


def prepare(binding_file, run_root):
    # This candidate currently qualifies source components only. Do not start
    # the old empty-history runner before producer/handoff/close are wired.
    raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')
    global ATTEMPT_CONTEXT
    binding = load(binding_file); verify_freeze(binding)
    root = Path(run_root).resolve(strict=True)
    expect(not root.is_relative_to(Path(binding['requested_repo_source']).resolve()), 'RUN_OUTSIDE_SOURCE')
    run = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S') + secrets.token_hex(3)
    directory = root / run
    expect(not directory.exists() and not directory.is_symlink(), 'NEW_RUN_DIRECTORY')
    directory.mkdir(mode=0o700); (directory / 'evidence').mkdir(mode=0o700)
    context = {'schema': 1, 'run_id': run, 'project': 'tianli-audit-' + run, 'source_sha': binding['source_sha'],
               'repo_dir': binding['requested_repo_source'], 'prep_dir': PREP.as_posix(), 'run_dir': directory.as_posix(),
               'freeze_sha256': digest(Path(binding_file).read_bytes()), 'freeze_bytes': Path(binding_file).stat().st_size,
               'legacy_repo_dir': binding['nonempty_history']['requested_legacy_repo_source']}
    ATTEMPT_CONTEXT = (directory / 'preparation-attempt.json').as_posix()
    new(ATTEMPT_CONTEXT, context | {'scope': 'PRE_NATIVE_ATTEMPT_ONLY', 'resources_created_claimed': False, 'SQL_App': 'NOT RUN'})
    frozen = Path(binding_file).read_bytes()
    with (directory / 'preparation-source-binding.json').open('xb') as stream:
        expect(stream.write(frozen) == len(frozen), 'COMPLETE_FREEZE_COPY')
        stream.flush()
    expect((directory / 'preparation-source-binding.json').read_bytes() == frozen, 'FREEZE_COPY_BYTES')
    for kind in ['container', 'network', 'volume']:
        expect(not ids(context, kind, 'com.docker.compose.project=' + context['project'])
               and not ids(context, kind, 'tianli.audit.run=' + run), 'FRESH_PROJECT_RUN_RESOURCES')
    for image in [PHP, MYSQL, REDIS]:
        actual = native(context, ['image', 'inspect', '--format', '{{.Id}}', image], 'cached-image')
        expect(actual == image, 'EXACT_CACHED_IMAGE')
    compose(context, ['config', '--quiet'], 'prepare-config', 'prepare', 'prepare')
    compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-bootstrap', 'audit-mysql', 'audit-redis'], 'prepare-create', 'prepare', 'prepare')
    cid = created(context, 'audit-bootstrap', 'prepare', 'prepare')
    mysql = role_id(context, 'audit-mysql', 'prepare', 'prepare'); redis = role_id(context, 'audit-redis', 'prepare', 'prepare')
    expected = {cid: 'audit-bootstrap', mysql: 'audit-mysql', redis: 'audit-redis'}
    collect(context, expected, initial_services={'audit-mysql': mysql, 'audit-redis': redis})
    new(directory / 'actual-run-context.json', context)
    native(context, ['start', cid], 'prepare-start')
    facts = observe(context, cid, 'prepare', 30)
    if facts.get('status') == 'exited':
        result = load(directory / 'evidence/prepare-result.json')
        expect(facts['exit_code'] == 0 and result['status'] == 'PREPARED_PRIVATE_CONFIG_ONLY', 'ACTUAL_PRIVATE_PREPARE_RESULT')
        remove_finished(context, cid, 'audit-bootstrap', 'prepare', expected)
    return {'status': 'PRIVATE_PREPARE_TERMINAL_ONLY' if facts.get('status') == 'exited' else 'LIVE_SAME_HANDLE_PRESERVED',
            'context': (directory / 'actual-run-context.json').as_posix(), 'container_id': cid, 'SQL_App': 'NOT RUN'}


def current(context_file, source_required=True):
    context = load(context_file)
    expect(Path(context_file).resolve() == (Path(context['run_dir']) / 'actual-run-context.json').resolve(), 'EXACT_RUN_CONTEXT_PATH')
    binding_file = Path(context['run_dir']) / 'preparation-source-binding.json'
    expect(digest(binding_file.read_bytes()) == context['freeze_sha256'] and context['prep_dir'] == PREP.as_posix(), 'IMMUTABLE_CURRENT_BINDING')
    binding = load(binding_file)
    if source_required:
        verify_freeze(binding)
    expect(binding['source_sha'] == context['source_sha'] and binding['requested_repo_source'] == context['repo_dir'], 'SOURCE_CONTEXT_MATCH')
    return context


def stage(name, context_file, proof=None, seconds=30):
    context = current(context_file, name not in ['observe', 'finish-helper']); root = Path(context['run_dir']); evidence = root / 'evidence'
    base = base_roles(context)
    if name == 'observe':
        expect(proof in ['prepare', 'initializer', 'driver', 'register-initializer', 'register-driver', 'authorize-cleanup',
                         'legacy-files-bootstrap', 'register-legacy-files-bootstrap',
                         'legacy-history-producer', 'register-legacy-history-producer'], 'KNOWN_OBSERVATION_PROOF')
        collect(context, proof_expected(context, proof), publish=False)
        facts = load(evidence / (proof + '-created.json'))
        return observe(context, facts['id'], proof, seconds)
    if name == 'finish-helper':
        wanted = {'prepare': 'PREPARED_PRIVATE_CONFIG_ONLY', 'register-initializer': 'REGISTERED_NATIVE_RESOURCES_ONLY',
                  'register-driver': 'REGISTERED_NATIVE_RESOURCES_ONLY', 'authorize-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY',
                  'register-legacy-files-bootstrap': 'REGISTERED_NATIVE_RESOURCES_ONLY',
                  'register-legacy-history-producer': 'REGISTERED_NATIVE_RESOURCES_ONLY'}
        expect(proof in wanted, 'KNOWN_FINITE_HELPER_PROOF')
        cid = bound_created(context, proof, 'audit-bootstrap')['id']
        result = load(evidence / (proof + '-result.json'))
        expect(result['status'] == wanted[proof] and result['native_exit'] == 0 and result['binding']['container_id'] == cid, 'ACTUAL_SAME_HELPER_RESULT')
        remove_finished(context, cid, 'audit-bootstrap', proof, proof_expected(context, proof))
        return {'status': 'SAME_TERMINAL_HELPER_REMOVED_ONLY', 'container_id': cid, 'migration_acceptance': 'NOT RUN'}
    if name == 'db-up':
        expect(not (evidence / 'db-up-compose-native.json').exists(), 'STAGE_ALREADY_ATTEMPTED')
        collect(context, base)
        compose(context, ['up', '-d', '--no-recreate', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '90', 'audit-mysql', 'audit-redis'], 'db-up')
        collect(context, base)
        return {'status': 'NATIVE_DB_REDIS_READY_ONLY', 'run_id': context['run_id'], 'App': 'NOT RUN'}
    if name == 'initializer-start':
        expect(not (evidence / 'initializer-created.json').exists(), 'INITIALIZER_ALREADY_CREATED_USE_OBSERVE')
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-ms4-initialize'], 'initializer-create')
        cid = created(context, 'audit-ms4-initialize', 'initializer')
        registered = helper(context, 'register-initializer', 'register', base | {cid: 'audit-ms4-initialize'})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_REGISTRATION_SAME_HANDLE_PRESERVED', 'initializer_id': cid, 'registration': registered}
        collect(context, base | {cid: 'audit-ms4-initialize'})
        native(context, ['start', cid], 'initializer-start')
        return {'status': 'INITIALIZER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id'], 'MS4_DDL': 'NOT RUN'}
    if name == 'initializer-resume':
        cid = load(evidence / 'initializer-created.json')['id']
        result = load(evidence / 'register-initializer-result.json')
        expect(result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and result['native_exit'] == 0, 'REGISTERED_INITIALIZER_ACTUAL_RECEIPT')
        facts = inspect(context, cid, 'initializer-resume-precheck'); owned(context, facts, 'audit-ms4-initialize')
        expect(facts['status'] == 'created', 'SAME_UNSTARTED_INITIALIZER_ONLY')
        collect(context, base | {cid: 'audit-ms4-initialize'})
        native(context, ['start', cid], 'initializer-resume-start')
        return {'status': 'SAME_INITIALIZER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id']}
    if name == 'legacy-files-create':
        expect(not (evidence / 'legacy-files-bootstrap-created.json').exists(), 'HISTORY_BOOTSTRAP_ALREADY_CREATED')
        old = bound_created(context, 'initializer', 'audit-ms4-initialize')
        terminal = observe(context, old['id'], 'initializer', 0)
        result = load(evidence / 'initializer-result.json')
        expect(terminal['status'] == 'exited' and terminal['exit_code'] == 0
               and result['status'] == 'VERIFIED_MS4_PREREQUISITES_ONLY' and result['native_exit'] == 0
               and result['private_cleanup_verified'] is True and len(result['children']) == 6
               and result['binding']['container_id'] == old['id']
               and result['canonical_default_shipping_seed'] == {'rows': 1, 'original_id': 1,
                    'counter_unchanged': True, 'other_database_and_redis_unchanged': True},
               'ORIGINAL_INITIALIZER_COMPLETE_BEFORE_HISTORY')
        history_archive(context, 'before-legacy-files')
        remove_finished(context, old['id'], 'audit-ms4-initialize', 'initializer', base | {old['id']: 'audit-ms4-initialize'})
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-legacy-files-bootstrap'],
                'legacy-files-create', 'legacy-files-bootstrap', 'initialize')
        created(context, 'audit-legacy-files-bootstrap', 'legacy-files-bootstrap')
        cid = bind_legacy_files(context)
        registered = helper(context, 'register-legacy-files-bootstrap', 'register', base | {cid: 'audit-legacy-files-bootstrap'})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_HISTORY_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid}
        return {'status': 'LEGACY_FILES_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'legacy-files-bind':
        cid = bound_created(context, 'legacy-files-bootstrap', 'audit-legacy-files-bootstrap')['id']
        registered = load(evidence / 'register-legacy-files-bootstrap-result.json')
        expect(registered['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and registered['native_exit'] == 0,
               'HISTORY_ORIGINAL_REGISTRATION_COMPLETED')
        collect(context, base | {cid: 'audit-legacy-files-bootstrap'})
        facts = inspect(context, cid, 'legacy-files-bind-same-created')
        same_created(bound_created(context, 'legacy-files-bootstrap', 'audit-legacy-files-bootstrap'), facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0, 'HISTORY_BOOTSTRAP_SAME_UNSTARTED')
        manifest = load(root / 'manifest.json')
        pins = manifest['nonempty_history']['stages']['audit-legacy-files-bootstrap']
        for key, leaf in [('created', 'audit-legacy-files-bootstrap-container-created.json'),
                          ('binding', 'audit-legacy-files-bootstrap-source-binding.json')]:
            raw = (evidence / leaf).read_bytes()
            expect(pins[key] == {'path': '/run/audit/evidence/' + leaf, 'bytes': len(raw), 'sha256': digest(raw)},
                   'HISTORY_NATIVE_REGISTRATION_PROOF_READBACK')
        return {'status': 'LEGACY_FILES_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'legacy-files-start':
        ready = stage('legacy-files-bind', context_file)
        cid = ready['container_id']
        facts = inspect(context, cid, 'legacy-files-start-precheck')
        expect(facts['status'] == 'created' and facts['pid'] == 0 and facts['restart_count'] == 0,
               'HISTORY_ONLY_SAME_UNSTARTED_BOOTSTRAP')
        native(context, ['start', cid], 'legacy-files-bootstrap-start')
        return {'status': 'LEGACY_FILES_BOOTSTRAP_STARTED_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'legacy-files-retire':
        cid = bound_created(context, 'legacy-files-bootstrap', 'audit-legacy-files-bootstrap')['id']
        terminal = observe(context, cid, 'legacy-files-bootstrap', 0)
        result = load(evidence / 'audit-legacy-files-bootstrap-result.json')
        expect(terminal['status'] == 'exited' and terminal['exit_code'] == 0
               and result['native_exit'] == 0 and result['status'] == 'LEGACY_FILE_INITIALIZATION_ONLY'
               and result['container_id'] == cid and result['run_id'] == context['run_id']
               and result['source_sha'] == context['source_sha'] and result['legacy_source_sha'] == LEGACY_SOURCE
               and result['freeze_sha256'] == context['freeze_sha256']
               and result['target_native_id_full_validation'] is True
               and result['complete_target_rows_and_projections_verified'] is True
               and result['old_version_marker_matches'] is True
               and result['non_target_database_and_redis_unchanged'] is True
               and type(result['checksum_rows']) is int and result['checksum_rows'] > 0
               and result['business_acceptance'] == 'NOT RUN', 'HISTORY_BOOTSTRAP_ACTUAL_NORMAL_RECEIPT')
        marker_window = result['marker_add_time_native_window']
        expect(isinstance(marker_window, dict) and set(marker_window) == {'before', 'after'}
               and type(marker_window['before']) is int and type(marker_window['after']) is int
               and 0 < marker_window['before'] <= marker_window['after'], 'HISTORY_BOOTSTRAP_REAL_MARKER_TIME_WINDOW')
        archive = history_archive(context, 'legacy-files-complete')
        remove_finished(context, cid, 'audit-legacy-files-bootstrap', 'legacy-files-bootstrap', base | {cid: 'audit-legacy-files-bootstrap'})
        return {'status': 'LEGACY_FILES_ONLY_NORMAL_RETIRED', 'container_id': cid, 'phase_archive': archive,
                'business_acceptance': 'NOT RUN', 'history_producer': 'NOT IMPLEMENTED'}
    if name == 'legacy-producer-create':
        expect(not (evidence / 'legacy-history-producer-created.json').exists(), 'PRODUCER_ALREADY_CREATED_USE_SAME_HANDLE')
        completed = legacy_files_complete(context)
        collect(context, base)
        expect(completed['container_id'] not in base, 'PRODUCER_ORIGINAL_BOOTSTRAP_NORMALLY_REMOVED')
        for base_cid, base_role in base.items():
            facts = inspect(context, base_cid, 'legacy-producer-original-base-running'); owned(context, facts, base_role)
            same_created(context['initial_service_created'][base_role], facts)
            expect(facts['status'] == 'running' and type(facts['pid']) is int and facts['pid'] > 0, 'PRODUCER_ORIGINAL_DB_REDIS_RUNNING')
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-legacy-history-producer'],
                'legacy-producer-create', 'legacy-history-producer', 'normal')
        created(context, 'audit-legacy-history-producer', 'legacy-history-producer', 'normal')
        cid = bind_legacy_producer(context)
        registered = helper(context, 'register-legacy-history-producer', 'register', base | {cid: 'audit-legacy-history-producer'})
        expect(legacy_files_complete(context) == completed, 'PRODUCER_CREATE_PRESERVES_COMPLETED_FILES_PUBLIC_INPUTS')
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_PRODUCER_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid}
        return {'status': 'LEGACY_PRODUCER_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'legacy-producer-bind':
        completed = legacy_files_complete(context); role = 'audit-legacy-history-producer'
        facts = bound_created(context, 'legacy-history-producer', role); cid = facts['id']
        registration = load(evidence / 'register-legacy-history-producer-result.json')
        registered = bound_created(context, 'register-legacy-history-producer', 'audit-bootstrap')
        terminal = load(evidence / 'register-legacy-history-producer-terminal.json')
        same_created(registered, terminal)
        expect(registration['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and registration['native_exit'] == 0
               and registration['run_id'] == context['run_id'] and registration['source_sha'] == context['source_sha']
               and registration['binding']['container_id'] == registered['id'] and terminal['id'] == registered['id']
               and registration['binding']['source_sha'] == context['source_sha']
               and registration['binding']['freeze_sha256'] == context['freeze_sha256']
               and registration['binding']['created_sha256'] == digest((evidence / 'register-legacy-history-producer-created.json').read_bytes())
               and terminal['status'] == 'exited' and terminal['exit_code'] == 0 and terminal['pid'] == 0,
               'PRODUCER_ACTUAL_REGISTER_HELPER_TERMINAL_ZERO')
        collect(context, base | {cid: role})
        now = inspect(context, cid, 'legacy-producer-bind-same-created'); owned(context, now, role); same_created(facts, now)
        expect(now['status'] == 'created' and now['pid'] == 0, 'PRODUCER_ONLY_SAME_UNSTARTED')
        mounts = legacy_producer_mounts(context, facts)
        manifest = load(root / 'manifest.json'); stages = manifest['nonempty_history']['stages']
        expect(manifest['run_id'] == context['run_id'] and manifest['commit_sha'] == context['source_sha']
               and set(manifest['services']) == {role, 'audit-mysql', 'audit-redis'} and manifest['services'][role]['id'] == cid
               and all(manifest['services'][base_role]['id'] == base_cid for base_cid, base_role in base.items())
               and set(stages) == {'audit-legacy-files-bootstrap', role}
               and stages['audit-legacy-files-bootstrap'] == completed['stage_pins'], 'PRODUCER_CURRENT_ROLE_OLD_STAGE_PRESERVED')
        for key, suffix in [('created', '-container-created.json'), ('binding', '-source-binding.json')]:
            raw = (evidence / (role + suffix)).read_bytes()
            expect(stages[role][key] == {'path': '/run/audit/evidence/' + role + suffix, 'bytes': len(raw), 'sha256': digest(raw)},
                   'PRODUCER_REGISTERED_ACTUAL_TWO_PINS')
        binding = load(evidence / (role + '-source-binding.json'))
        authority = load(root / 'preparation-source-binding.json')['nonempty_history']
        expect(load(evidence / (role + '-container-created.json')) == facts
               and binding == {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
                   'legacy_source_sha': LEGACY_SOURCE, 'role': role, 'container_id': cid,
                   'authority_sha256': digest((json.dumps(authority, ensure_ascii=False, separators=(',', ':')) + '\n').encode()), 'mounts': mounts}
               and legacy_files_complete(context) == completed, 'PRODUCER_BIND_FULL_PUBLIC_READBACK')
        return {'status': 'LEGACY_PRODUCER_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'legacy-producer-start':
        ready = stage('legacy-producer-bind', context_file); cid = ready['container_id']
        facts = inspect(context, cid, 'legacy-producer-start-precheck')
        same_created(bound_created(context, 'legacy-history-producer', 'audit-legacy-history-producer'), facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0 and facts['restart_count'] == 0, 'PRODUCER_START_ORIGINAL_UNSTARTED_ONLY')
        native(context, ['start', cid], 'legacy-history-producer-start')
        return {'status': 'LEGACY_HISTORY_PRODUCER_STARTED_ONLY', 'container_id': cid, 'business_acceptance': 'NOT RUN'}
    if name == 'driver-create':
        raise RuntimeError('NONEMPTY_HISTORY_PRODUCER_HANDOFF_NOT_IMPLEMENTED')
        expect(not (evidence / 'driver-created.json').exists(), 'DRIVER_ALREADY_CREATED')
        old = load(evidence / 'initializer-created.json'); terminal = inspect(context, old['id'], 'initializer-terminal-required')
        result = load(evidence / 'initializer-result.json')
        expect(terminal['status'] == 'exited' and terminal['exit_code'] == 0 and result['status'] == 'VERIFIED_MS4_PREREQUISITES_ONLY'
               and result['native_exit'] == 0 and result['private_cleanup_verified'] is True and len(result['children']) == 6
               and result['binding']['container_id'] == old['id'], 'TRUE_INITIALIZER_COMPLETE')
        remove_finished(context, old['id'], 'audit-ms4-initialize', 'initializer', base | {old['id']: 'audit-ms4-initialize'})
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-ms4-migration'], 'driver-create')
        cid = created(context, 'audit-ms4-migration', 'driver')
        registered = helper(context, 'register-driver', 'register', base | {cid: 'audit-ms4-migration'})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_REGISTRATION_SAME_HANDLE_PRESERVED', 'driver_id': cid, 'registration': registered}
        return stage('driver-bind', context_file)
    if name == 'driver-bind':
        cid = load(evidence / 'driver-created.json')['id']
        result = load(evidence / 'register-driver-result.json')
        expect(result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and result['native_exit'] == 0, 'REGISTERED_DRIVER_ACTUAL_RECEIPT')
        facts = inspect(context, cid, 'driver-bind-precheck'); owned(context, facts, 'audit-ms4-migration')
        expect(facts['status'] == 'created', 'SAME_UNSTARTED_DRIVER_ONLY')
        collect(context, base | {cid: 'audit-ms4-migration'})
        binding = load(root / 'preparation-source-binding.json')
        facts = load(evidence / 'driver-created.json')
        new(evidence / 'ms4-migration-container-created.json', facts)
        new(evidence / 'ms4-migration-source-binding.json', {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
            'requested_repo_source': context['repo_dir'], 'requested_run_source': context['run_dir'], 'source_files': binding['driver_source_files'],
            'entry_sha256': next(row['sha256'] for row in binding['driver_source_files'] if row['path'] == DRIVER[3]),
            'cli_sha256': next(row['sha256'] for row in binding['driver_source_files'] if row['path'] == DRIVER[-1])})
        return {'status': 'UNSTARTED_MS4_DRIVER_SOURCE_BOUND_ONLY', 'container_id': cid, 'run_id': context['run_id'], 'MS4_DDL': 'NOT RUN'}
    if name == 'driver-start':
        cid = load(evidence / 'driver-created.json')['id']; facts = inspect(context, cid, 'driver-start-precheck')
        expect(facts['status'] == 'created' and facts['restart_count'] == 0, 'ONLY_UNSTARTED_SAME_DRIVER')
        collect(context, base | {cid: 'audit-ms4-migration'})
        native(context, ['start', cid], 'driver-start')
        return {'status': 'MS4_DRIVER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id']}
    if name == 'authorize-close':
        cid = load(evidence / 'driver-created.json')['id']
        facts = inspect(context, cid, 'driver-normal-close-precheck'); owned(context, facts, 'audit-ms4-migration')
        result = load(evidence / 'ms4-migration-normal.json')
        expect(facts['status'] == 'exited' and facts['pid'] == 0 and facts['exit_code'] == 0
               and result['status'] == 'VERIFIED_NORMAL_STRUCTURE_ONLY' and result['native_exit'] == 0
               and result['binding']['container_id'] == cid and result['private_cleanup_verified'] is True
               and result['actual_command_counts'] == {'check': 2, 'apply-structure': 2} and len(result['children']) == 4,
               'ACTUAL_FOUR_COMMAND_RECEIPT_FOR_CLOSE')
        expect([row['mode'] for row in result['children']] == ['check', 'apply-structure', 'check', 'apply-structure']
               and len({row['pid'] for row in result['children']}) == 4
               and all(isinstance(row['pid'], int) and row['pid'] > 0 and row['native_exit'] == 0 and row['stderr_bytes'] == 0
                       for row in result['children']), 'ACTUAL_FOUR_NORMAL_NATIVE_CHILDREN')
        return helper(context, 'authorize-cleanup', 'authorize-cleanup', base | {cid: 'audit-ms4-migration'})
    raise RuntimeError('Use only explicit reviewed stages; full closeout is performed separately')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=['freeze', 'prepare', 'db-up', 'initializer-start', 'initializer-resume', 'driver-create', 'driver-bind', 'driver-start', 'observe', 'finish-helper', 'authorize-close',
        'legacy-files-create', 'legacy-files-bind', 'legacy-files-start', 'legacy-files-retire',
        'legacy-producer-create', 'legacy-producer-bind', 'legacy-producer-start'])
    parser.add_argument('--repo'); parser.add_argument('--source'); parser.add_argument('--output')
    parser.add_argument('--legacy-repo')
    parser.add_argument('--binding'); parser.add_argument('--run-root'); parser.add_argument('--context'); parser.add_argument('--proof')
    parser.add_argument('--seconds', type=int, default=30)
    args = parser.parse_args()
    expect(0 <= args.seconds <= 30, 'BOUNDED_OBSERVATION')
    if args.stage == 'freeze':
        expect(args.repo and args.source and args.output and args.legacy_repo, 'EXPLICIT_CLEAN_DUAL_SOURCE_FREEZE')
        result = freeze(args.repo, args.source, args.output, args.legacy_repo)
    elif args.stage == 'prepare':
        expect(args.binding and args.run_root, 'EXPLICIT_NEW_RUN_PREPARATION')
        result = prepare(args.binding, args.run_root)
    else:
        raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # No driver error text, environment, native stderr or container logs are emitted.
        print(json.dumps({'status': 'PRESERVED_FAILURE_OR_OBSERVATION_REQUIRED', 'class': type(error).__name__,
                          'attempt_context': ATTEMPT_CONTEXT, 'resources_not_automatically_deleted': True, 'migration_acceptance': 'NOT RUN'}))
        raise SystemExit(1)
