"""SOURCE candidate: real early structure before App, then serial Task6 and positive aftersale/review HTTP. No runtime acceptance implied."""
import argparse
import base64
import itertools
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
VOLUMES = ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets']
ARTIFACTS = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php', 'seed-source-inverse.json', 'interface-v1.json', 'source-derivation.json', 'normal-close-ms4-fulfillment.py', 'verify-zero-ms4-fulfillment.py']
DRIVER = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
          'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms5-payment-migration.php',
          'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
          'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php',
          'help/migrations/marketplace-ms5-payment.md', 'help/migrations/marketplace-ms5-payment.php']
REFERENCES = ['help/audit-regressions/isolated/bootstrap.sh', 'help/audit-regressions/isolated/compose.yml',
              'help/audit-regressions/isolated/compose.ms2-migration.yml', 'help/audit-regressions/isolated/ms2-migration.php']
CUSTOMER_ENTRY = 'help/audit-regressions/isolated/ms4-customer-http-normal.php'
CUSTOMER_ROOTS = ['crmeb/app', 'crmeb/crmeb', 'crmeb/vendor', 'crmeb/config']
CUSTOMER_EXTRA = ['crmeb/composer.json', 'crmeb/composer.lock', 'crmeb/public/index.php', 'crmeb/public/router.php', 'crmeb/think', 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php', 'help/audit-regressions/isolated/ms4-customer-http-normal.php', 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php']
INTERFACE_SHA256 = '46b388a5b0e454207246f0b5571e7a2a135f08206e42c59bcb09c3164ec265d6'
PAID_ENTRY = 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php'
PAID_ENTRY_BYTES = 109849
PAID_ENTRY_SHA256 = '956f4dbcf96afa7155a478cdb7e74b960891d120148e74ff8735b6da53d37e5d'
PAID = {'proof': 'paid-producer', 'role': 'audit-ms5-paid-producer',
    'binding': 'paid-producer-source-binding.json', 'scope': 'MS5_PAID_PRODUCER_SOURCE',
    'result': 'paid-producer-result.json', 'result_scope': 'MS5_PAID_PRODUCER_INTERNAL_ONLY',
    'status': 'VERIFIED_MS5_PAID_PRODUCER_INTERNAL_ONLY', 'environment_scope': 'MS5_PAID_PRODUCER_REAL_ENVIRONMENT'}

INSPECT = '{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"user":{{json .Config.User}},"stop_signal":{{json (index .Config "StopSignal")}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}}}'
IMMUTABLE_FIELDS = ('id', 'image', 'hostname', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
                    'network_mode', 'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop',
                    'security_options', 'created_at', 'user', 'stop_signal')


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


def ids(context, kind, label):
    argv = {'container': ['ps', '-aq', '--no-trunc'], 'network': ['network', 'ls', '-q'], 'volume': ['volume', 'ls', '-q']}[kind]
    return native(context, [*argv, '--filter', 'label=' + label], 'list-' + kind).splitlines()


def inspect(context, cid, label):
    result = json.loads(native(context, ['inspect', '--format', INSPECT, cid], label))
    expect(result['id'] == cid, 'EXACT_NATIVE_CID')
    return result


def role_id(context, role, proof='initializer', phase='prepare'):
    values = compose(context, ['ps', '-aq', role], 'lookup-' + proof + '-' + role, proof, phase).splitlines()
    expect(len(values) == 1 and re.fullmatch('[a-f0-9]{64}', values[0]), 'ONE_ACTUAL_ROLE_CID')
    return values[0]


def global_consumers(context, allowed):
    all_ids = native(context, ['ps', '-aq', '--no-trunc'], 'global-consumers').splitlines()
    expect(len(all_ids) == len(set(all_ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in all_ids), 'GLOBAL_COMPLETE_UNIQUE_CIDS')
    network_name = context['project'] + '_isolation'
    network_id = context.get('network_id') or context.get('initial_network', {}).get('Id')
    if network_id is None:
        project = ids(context, 'network', 'com.docker.compose.project=' + context['project'])
        run = ids(context, 'network', 'tianli.audit.run=' + context['run_id'])
        expect(len(project) == 1 and project == run, 'GLOBAL_ACTUAL_BOUND_NETWORK_REQUIRED')
        current = json.loads(native(context, ['network', 'inspect', '--format', '{{json .}}', project[0]], 'global-bound-network'))
        expect(type(current['Id']) is str and re.fullmatch('[a-f0-9]{64}', current['Id'])
               and re.fullmatch('[a-f0-9]{12,64}', project[0]) and current['Id'].startswith(project[0])
               and current['Name'] == network_name and current['Internal'] is True
               and current['Labels']['tianli.audit.run'] == context['run_id']
               and current['Labels']['com.docker.compose.project'] == context['project'], 'GLOBAL_ACTUAL_NETWORK_IDENTITY')
        network_id = current['Id']
    expect(type(network_id) is str and re.fullmatch('[a-f0-9]{64}', network_id), 'GLOBAL_REAL_BOUND_NETWORK_ID')
    targets = set(allowed) | set(context.get('initial_service_ids', {}).values())
    paid_created = Path(context['run_dir']) / 'evidence/paid-producer-created.json'
    if paid_created.exists():
        original = load(paid_created)
        expect(original['run_id'] == context['run_id'] and original['source_sha'] == context['source_sha']
               and original['proof_name'] == PAID['proof'] and re.fullmatch('[a-f0-9]{64}', original['id']), 'GLOBAL_ORIGINAL_PAID_REFERENCE')
        targets.add(original['id'])
    historical_roles = [('prepare', 'audit-bootstrap'), ('initializer', 'audit-ms4-initialize'),
        ('register-initializer', 'audit-bootstrap'), ('driver', 'audit-ms5-payment-migration'), ('register-driver', 'audit-bootstrap'),
        ('files-bootstrap', 'audit-bootstrap'), ('customer-prepare', 'audit-ms4-customer-prepare'),
        ('register-customer-prepare', 'audit-bootstrap'), ('customer', 'audit-ms4-customer-http'),
        ('register-customer', 'audit-bootstrap'), ('authorize-customer-cleanup', 'audit-bootstrap'),
        ('register-paid-producer', 'audit-bootstrap'), ('register-paid-after-c3', 'audit-bootstrap'),
        ('authorize-paid-cleanup', 'audit-bootstrap'),
        ('fulfillment-migration', 'audit-ms4-fulfillment-migration'), ('register-fulfillment-migration', 'audit-bootstrap'),
        ('fulfillment-normal', 'audit-ms4-fulfillment-normal'), ('register-fulfillment-normal', 'audit-bootstrap'),
        ('authorize-fulfillment-cleanup', 'audit-bootstrap'), ('aftersale-review-normal', 'audit-ms4-aftersale-review-normal'),
        ('register-aftersale-review-normal', 'audit-bootstrap'), ('authorize-aftersale-review-cleanup', 'audit-bootstrap'),
        ('worker-normal', 'audit-ms4-worker-normal'), ('register-worker-normal', 'audit-bootstrap'), ('authorize-worker-cleanup', 'audit-bootstrap')]
    for proof, role in historical_roles:
        path = Path(context['run_dir']) / 'evidence' / (proof + '-created.json')
        if path.exists():
            original = bound_created(context, proof, role)
            targets.add(original['id'])
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    lines = native(context, ['inspect', '--format', template, *all_ids], 'global-consumer-facts').splitlines() if all_ids else []
    private = {context['project'] + '_' + name for name in VOLUMES}
    observed = set()
    for line in lines:
        row = json.loads(line)
        expect(type(row) is dict and set(row) == {'id', 'mounts', 'network_mode', 'networks'}
               and row['id'] in all_ids and row['id'] not in observed and type(row['mounts']) is list
               and type(row['networks']) is dict and type(row['network_mode']) is str, 'GLOBAL_COMPLETE_NATIVE_FIELDS')
        observed.add(row['id'])
        volumes = {m.get('Name') for m in row['mounts'] if m.get('Type') == 'volume'}
        mode = row['network_mode']
        joined = (network_name in row['networks'] or network_id in row['networks'] or mode in {network_name, network_id}
                  or any(endpoint.get('NetworkID') == network_id for endpoint in row['networks'].values())
                  or (mode.startswith('container:') and mode[len('container:'):] in targets))
        if volumes & private or joined:
            expect(row['id'] in allowed, 'FOREIGN_RESOURCE_CONSUMER')
    final = native(context, ['ps', '-aq', '--no-trunc'], 'global-consumers-final').splitlines()
    expect(observed == set(all_ids) and len(final) == len(set(final)) and set(final) == set(all_ids), 'SAME_COMPLETE_GLOBAL_NATIVE_CID_SET')

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
        proof_ini_environment(context, cid, role)
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
    expect(set(volumes) == set(run_volumes) == {context['project'] + '_' + name for name in VOLUMES}, 'EXACT_EIGHT_NEW_VOLUMES')
    volume_rows = []
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    for volume in sorted(volumes):
        row = json.loads(native(context, ['volume', 'inspect', '--format', template, volume], 'native-volume'))
        expect(row['name'] == volume and isinstance(row['created_at'], str) and row['created_at']
               and isinstance(row['labels'], dict), 'COMPLETE_ACTUAL_VOLUME_IDENTITY')
        expect(row['labels'].get('tianli.audit.run') == context['run_id'] and row['labels'].get('com.docker.compose.project') == context['project'], 'NATIVE_VOLUME_LABELS')
        volume_rows.append(row)
    if initial_services is None:
        expect(volume_rows == context['initial_volumes'], 'SAME_INITIAL_EIGHT_VOLUME_IDENTITIES')
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


def prepare(binding_file, run_root):
    global ATTEMPT_CONTEXT
    binding = load(binding_file); verify_freeze(binding)
    root = Path(run_root).resolve(strict=True)
    expect(not root.is_relative_to(Path(binding['requested_repo_source']).resolve()), 'RUN_OUTSIDE_SOURCE')
    run = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S') + secrets.token_hex(3)
    directory = root / run
    expect(not directory.exists() and not directory.is_symlink(), 'NEW_RUN_DIRECTORY')
    directory.mkdir(mode=0o700); (directory / 'evidence').mkdir(mode=0o700)
    proof_copy = proof_ini_prepare(binding, directory, run, digest(Path(binding_file).read_bytes()))
    context = {'schema': 1, 'run_id': run, 'project': 'tianli-audit-' + run, 'source_sha': binding['source_sha'],
               'repo_dir': binding['requested_repo_source'], 'prep_dir': PREP.as_posix(), 'run_dir': directory.as_posix(),
               'freeze_sha256': digest(Path(binding_file).read_bytes())}
    if proof_copy is not None:
        context['proof_php_ini_copy'] = proof_copy
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
    private_volume = context['project'] + '_aftersale-evidence'
    all_volume_names = native(context, ['volume', 'ls', '--format', '{{.Name}}'], 'prepare-global-volume-names-before-private-create').splitlines()
    expect(private_volume not in all_volume_names, 'NEW_UNIQUE_PRIVATE_VOLUME_NAME_NEVER_REUSE')
    actual = native(context, ['volume', 'create', '--label', 'com.docker.compose.project=' + context['project'],
        '--label', 'com.docker.compose.volume=aftersale-evidence', '--label', 'tianli.audit=1',
        '--label', 'tianli.audit.run=' + context['run_id'], private_volume], 'prepare-create-only-aftersale-evidence-volume')
    expect(actual == private_volume, 'ACTUAL_ONE_NEW_PRIVATE_VOLUME_CREATED')
    form_volume = context['project'] + '_customer-form-evidence'
    expect(form_volume not in all_volume_names, 'CUSTOMER_FORM_NEW_UNIQUE_PRIVATE_VOLUME_NEVER_REUSE')
    actual_form = native(context, ['volume', 'create', '--label', 'com.docker.compose.project=' + context['project'],
        '--label', 'com.docker.compose.volume=customer-form-evidence', '--label', 'tianli.audit=1',
        '--label', 'tianli.audit.run=' + context['run_id'], form_volume], 'prepare-create-only-customer-form-evidence-volume')
    expect(actual_form == form_volume, 'CUSTOMER_FORM_ACTUAL_ONE_ADDITIONAL_NEW_PRIVATE_VOLUME_CREATED')
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
    proof_ini_current(context, binding)
    return context


def persist_terminal(context, proof, facts):
    expect(type(facts) is dict and facts.get('status') == 'exited'
        and type(facts.get('pid')) is int and facts['pid'] == 0
        and type(facts.get('restart_count')) is int and facts['restart_count'] == 0
        and type(facts.get('exit_code')) is int, 'ACTUAL_TERMINAL_INTEGER_STATE')
    expect(type(facts.get('mounts')) is list and all(type(entry) is dict for entry in facts['mounts']),
        'ACTUAL_TERMINAL_COMPLETE_MOUNTS')
    path = Path(context['run_dir']) / 'evidence' / (proof + '-terminal.json')
    if path.exists() or path.is_symlink():
        previous = load(path)
        expect(type(previous) is dict and set(previous) == set(facts), 'SAME_COMPLETE_TERMINAL_FIELD_SET')
        expect(type(previous['mounts']) is list and all(type(entry) is dict for entry in previous['mounts']),
            'STORED_TERMINAL_COMPLETE_MOUNTS')
        canonical = lambda value: json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)
        expect(sorted(canonical(entry) for entry in previous['mounts'])
            == sorted(canonical(entry) for entry in facts['mounts']), 'SAME_COMPLETE_TERMINAL_MOUNT_MULTISET')
        expect(canonical({key: value for key, value in previous.items() if key != 'mounts'})
            == canonical({key: value for key, value in facts.items() if key != 'mounts'}),
            'SAME_ALL_OTHER_TERMINAL_FIELDS')
    else:
        new(path, facts)


def observe(context, cid, proof, seconds):
    end = time.monotonic() + seconds
    while True:
        facts = inspect(context, cid, proof + '-observe')
        expect(facts['restart_count'] == 0 and facts['status'] in ['created', 'running', 'exited'], 'SAME_FINITE_HANDLE')
        if facts['status'] == 'exited':
            persist_terminal(context, proof, facts)
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
    persist_terminal(context, proof, facts)
    native(context, ['rm', cid], proof + '-exact-nonforce-remove')
    collect(context, {known: service for known, service in expected.items() if known != cid})


def freeze(repo, source, output, proof_ini_source=None):
    expect(CUSTOMER_FORM_SOURCE_BINDING_STATUS == 'BOUND_FROZEN_PHP_SOURCE', 'CUSTOMER_FORM_FROZEN_PHP_SOURCE_REQUIRED')
    repo = Path(repo).resolve(strict=True)
    expect(not Path(output).resolve().is_relative_to(repo), 'PROOF_OUTSIDE_REPO')
    clean(repo, source)
    expect(source_row(PREP, 'interface-v1.json')['sha256'] == INTERFACE_SHA256, 'EXACT_REVIEWED_INTERFACE_SOURCE')
    entry = (repo / DRIVER[3]).read_text(encoding='utf-8')
    match = re.search(r'function m5pm_source_paths\(\): array\s*\{\s*\$paths = \[(.*?)\];', entry, re.S)
    entry_path = re.search(r"const M5PM_ENTRY = '([^']+)';", entry)
    cli_path = re.search(r"const M5PM_CLI = '([^']+)';", entry)
    expect(match is not None and entry_path is not None and cli_path is not None
           and sorted(re.findall(r"'([^']+)'", match.group(1)) + [entry_path[1], cli_path[1]]) == DRIVER,
           'ACTUAL_TEN_DRIVER_FILES')
    for relative in DRIVER + REFERENCES:
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_SOURCE_REFERENCE')
    for row in CUSTOMER_FORM_SOURCE_FILES:
        expect(git(repo, 'ls-files', '--', row['path']) == row['path'] and source_row(repo, row['path']) == row,
            'CUSTOMER_FORM_SEVEN_FROZEN_PHP_SOURCES_EXACT_TRACKED_BYTES')
    seed_inverse(repo)
    expect(git(repo, 'ls-files', '--', PAID_ENTRY) == PAID_ENTRY, 'TRACKED_REAL_PAID_ENTRY')
    paid_entry = source_row(repo, PAID_ENTRY)
    expect(paid_entry == {'path': PAID_ENTRY, 'bytes': PAID_ENTRY_BYTES, 'sha256': PAID_ENTRY_SHA256}, 'EXACT_REVIEWED_REAL_PAID_ENTRY')
    for relative in [FULFILLMENT_ENTRY, FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI] + [row['path'] for row in THREE_READ_SOURCE_FILES]:
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_REAL_FULFILLMENT_SOURCE')
    expect(source_row(repo, FULFILLMENT_ENTRY) == {'path': FULFILLMENT_ENTRY, 'bytes': FULFILLMENT_ENTRY_BYTES, 'sha256': FULFILLMENT_ENTRY_SHA256}, 'EXACT_REVIEWED_FULFILLMENT_ENTRY_SOURCE')
    for row in THREE_READ_SOURCE_FILES:
        expect(source_row(repo, row['path']) == row, 'EXACT_REVIEWED_THREE_READ_HELPER_SOURCE')
    value = {'schema': 1, 'status': 'CLEAN_SOURCE_FROZEN_ONLY', 'source_sha': source, 'requested_repo_source': repo.as_posix(),
             'driver_source_files': [source_row(repo, p) for p in DRIVER],
             'customer_source_roots': CUSTOMER_ROOTS, 'customer_source_extra': sorted(CUSTOMER_EXTRA),
             'customer_source_files': [source_row(repo, p) for p in customer_paths(repo)],
             'paid_source_roots': CUSTOMER_ROOTS, 'paid_source_extra': sorted(CUSTOMER_EXTRA + [PAID_ENTRY]),
             'paid_source_files': [source_row(repo, p) for p in paid_paths(repo)],
             'fulfillment_source_roots': CUSTOMER_ROOTS, 'fulfillment_source_extra': fulfillment_extra(),
             'fulfillment_source_files': [source_row(repo, p) for p in fulfillment_paths(repo)],
             'aftersale_review_source_roots': CUSTOMER_ROOTS, 'aftersale_review_source_extra': aftersale_review_extra(),
             'aftersale_review_source_files': [source_row(repo, p) for p in aftersale_review_paths(repo)],
             'worker_source_roots': CUSTOMER_ROOTS, 'worker_source_extra': worker_extra(),
             'worker_source_files': [source_row(repo, p) for p in worker_paths(repo)],
             'preparation_source_references': [source_row(repo, p) for p in REFERENCES],
             'artifact_files': [source_row(PREP, p) for p in ARTIFACTS], 'runtime_SQL_App': 'NOT RUN'}
    expect(source_row(repo, AFTERSALE_REVIEW_ENTRY) == {'path': AFTERSALE_REVIEW_ENTRY, 'bytes': AFTERSALE_REVIEW_ENTRY_BYTES, 'sha256': AFTERSALE_REVIEW_ENTRY_SHA256}, 'EXACT_STOPPED_AFTERSALE_REVIEW_NORMAL_ENTRY')
    for relative in [AFTERSALE_REVIEW_ENTRY] + list(EARLY_SCRIPTS.values()):
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_EXACT_NEW_NORMAL_AND_FOUR_MIGRATION_SOURCES')
    expect(git(repo, 'ls-files', '--', WORKER_ENTRY) == WORKER_ENTRY, 'TRACKED_REAL_WORKER_ENTRY')
    expect(source_row(repo, WORKER_ENTRY) == {'path': WORKER_ENTRY, 'bytes': WORKER_ENTRY_BYTES,
        'sha256': WORKER_ENTRY_SHA256}, 'EXACT_REVIEWED_WORKER_ENTRY')
    if proof_ini_source is not None:
        value['proof_php_ini'] = proof_ini_freeze(proof_ini_source)
        expect(not Path(value['proof_php_ini']['source_root']).is_relative_to(repo), 'PROOF_RESOURCE_OUTSIDE_CORE_SOURCE')
    clean(repo, source)
    sha = new(output, value)
    return {'status': value['status'], 'source_sha': source, 'freeze_sha256': sha, 'runtime_SQL_App': 'NOT RUN'}


def verify_freeze(binding):
    expect(CUSTOMER_FORM_SOURCE_BINDING_STATUS == 'BOUND_FROZEN_PHP_SOURCE', 'CUSTOMER_FORM_FROZEN_PHP_SOURCE_REQUIRED')
    proof_ini_group(binding)
    expect(source_row(PREP, 'interface-v1.json')['sha256'] == INTERFACE_SHA256, 'EXACT_REVIEWED_FROZEN_INTERFACE_SOURCE')
    repo = Path(binding['requested_repo_source'])
    clean(repo, binding['source_sha'])
    expect([r['path'] for r in binding['driver_source_files']] == DRIVER, 'FROZEN_DRIVER_SET')
    expect([r['path'] for r in binding['preparation_source_references']] == REFERENCES, 'FROZEN_PREPARATION_SET')
    expect([r['path'] for r in binding['artifact_files']] == ARTIFACTS, 'FROZEN_ARTIFACT_SET')
    for rows, root in [(binding['driver_source_files'], repo), (binding['preparation_source_references'], repo), (binding['artifact_files'], PREP)]:
        for row in rows:
            expect(source_row(root, row['path']) == row, 'FROZEN_BYTE_IDENTITY')
    expect(binding['customer_source_roots'] == CUSTOMER_ROOTS and binding['customer_source_extra'] == sorted(CUSTOMER_EXTRA)
           and [r['path'] for r in binding['customer_source_files']] == customer_paths(repo), 'FROZEN_COMPLETE_ORDER_SOURCE_SET')
    for row in binding['customer_source_files']:
        expect(source_row(repo, row['path']) == row, 'FROZEN_ORDER_SOURCE_BYTES')
    expect(binding['aftersale_review_source_roots'] == CUSTOMER_ROOTS and binding['aftersale_review_source_extra'] == aftersale_review_extra()
        and [row['path'] for row in binding['aftersale_review_source_files']] == aftersale_review_paths(repo), 'FROZEN_COMPLETE_AFTERSALE_REVIEW_FOUR_ROOTS_TWENTY_NINE_EXTRAS')
    for row in binding['aftersale_review_source_files']:
        expect(source_row(repo, row['path']) == row, 'FROZEN_AFTERSALE_REVIEW_FULL_SOURCE_BYTES')
    expect(source_row(repo, AFTERSALE_REVIEW_ENTRY) == {'path': AFTERSALE_REVIEW_ENTRY, 'bytes': AFTERSALE_REVIEW_ENTRY_BYTES, 'sha256': AFTERSALE_REVIEW_ENTRY_SHA256}, 'FROZEN_EXACT_STOPPED_AFTERSALE_REVIEW_NORMAL_ENTRY')
    for relative in [AFTERSALE_REVIEW_ENTRY] + list(EARLY_SCRIPTS.values()):
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_REAL_NEW_NORMAL_AND_FOUR_MIGRATION_SOURCES')
    for row in CUSTOMER_FORM_SOURCE_FILES:
        expect(git(repo, 'ls-files', '--', row['path']) == row['path'] and source_row(repo, row['path']) == row,
            'CUSTOMER_FORM_SEVEN_FROZEN_PHP_SOURCES_EXACT_TRACKED_BYTES')
    seed_inverse(repo)
    expect(binding['paid_source_roots'] == CUSTOMER_ROOTS and binding['paid_source_extra'] == sorted(CUSTOMER_EXTRA + [PAID_ENTRY])
           and [r['path'] for r in binding['paid_source_files']] == paid_paths(repo), 'FROZEN_COMPLETE_PAID_SOURCE_SET')
    for row in binding['paid_source_files']:
        expect(source_row(repo, row['path']) == row, 'FROZEN_PAID_FULL_SOURCE_BYTES')
    expect([row for row in binding['paid_source_files'] if row['path'] != PAID_ENTRY] == binding['customer_source_files']
           and next(row for row in binding['paid_source_files'] if row['path'] == PAID_ENTRY)
               == {'path': PAID_ENTRY, 'bytes': PAID_ENTRY_BYTES, 'sha256': PAID_ENTRY_SHA256}, 'EXACT_PAID_TENTH_SOURCE_AND_CUSTOMER_NINE')
    expect(git(repo, 'ls-files', '--', PAID_ENTRY) == PAID_ENTRY, 'TRACKED_REVIEWED_PAID_SOURCE')
    expect(binding['fulfillment_source_roots'] == CUSTOMER_ROOTS and binding['fulfillment_source_extra'] == fulfillment_extra()
        and [row['path'] for row in binding['fulfillment_source_files']] == fulfillment_paths(repo), 'FROZEN_COMPLETE_FULFILLMENT_SOURCE_SET')
    for row in binding['fulfillment_source_files']:
        expect(source_row(repo, row['path']) == row, 'FROZEN_FULFILLMENT_COMPLETE_BYTES')
    expect(next(row for row in binding['fulfillment_source_files'] if row['path'] == FULFILLMENT_ENTRY)
        == {'path': FULFILLMENT_ENTRY, 'bytes': FULFILLMENT_ENTRY_BYTES, 'sha256': FULFILLMENT_ENTRY_SHA256}, 'EXACT_REVIEWED_FULFILLMENT_ENTRY')
    for row in THREE_READ_SOURCE_FILES:
        expect(next(item for item in binding['fulfillment_source_files'] if item['path'] == row['path']) == row,
               'EXACT_REVIEWED_FROZEN_THREE_READ_HELPER')
    for relative in [FULFILLMENT_ENTRY, FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI] + [row['path'] for row in THREE_READ_SOURCE_FILES]:
        expect(git(repo, 'ls-files', '--', relative) == relative, 'TRACKED_REVIEWED_FULFILLMENT_SOURCE')


    expect(binding['worker_source_roots'] == CUSTOMER_ROOTS and binding['worker_source_extra'] == worker_extra()
        and [row['path'] for row in binding['worker_source_files']] == worker_paths(repo), 'FROZEN_COMPLETE_WORKER_FOUR_ROOTS_THIRTY_EXTRAS')
    expect([row for row in binding['worker_source_files'] if row['path'] != WORKER_ENTRY] == binding['aftersale_review_source_files'],
        'WORKER_ORIGINAL_COMPLETE_AFTERSALE_SOURCE_RETAINED')
    for row in binding['worker_source_files']:
        expect(source_row(repo, row['path']) == row, 'FROZEN_WORKER_COMPLETE_NATIVE_SOURCE_BYTES')
    expect(source_row(repo, WORKER_ENTRY) == {'path': WORKER_ENTRY, 'bytes': WORKER_ENTRY_BYTES,
        'sha256': WORKER_ENTRY_SHA256}, 'FROZEN_EXACT_REAL_WORKER_ENTRY')
    expect(git(repo, 'ls-files', '--', WORKER_ENTRY) == WORKER_ENTRY, 'TRACKED_FROZEN_WORKER_ENTRY')


# This section is new customer-only wiring; prerequisite functions above are exact source copies.
PROOF_ROLES = {
    'prepare': [('prepare', 'audit-bootstrap')],
    'initializer': [('initializer', 'audit-ms4-initialize')],
    'driver': [('driver', 'audit-ms5-payment-migration')],
    'register-initializer': [('initializer', 'audit-ms4-initialize'), ('register-initializer', 'audit-bootstrap')],
    'register-driver': [('driver', 'audit-ms5-payment-migration'), ('register-driver', 'audit-bootstrap')],
    'files-bootstrap': [('paid-producer', 'audit-ms5-paid-producer'), ('files-bootstrap', 'audit-bootstrap')],
    'customer-prepare': [('paid-producer', 'audit-ms5-paid-producer'), ('customer-prepare', 'audit-ms4-customer-prepare')],
    'register-customer-prepare': [('paid-producer', 'audit-ms5-paid-producer'), ('customer-prepare', 'audit-ms4-customer-prepare'), ('register-customer-prepare', 'audit-bootstrap')],
    'customer': [('customer', 'audit-ms4-customer-http')],
    'register-customer': [('customer', 'audit-ms4-customer-http'), ('register-customer', 'audit-bootstrap')],
    'authorize-customer-cleanup': [('customer', 'audit-ms4-customer-http'), ('authorize-customer-cleanup', 'audit-bootstrap')],
    'paid-producer': [('paid-producer', 'audit-ms5-paid-producer')],
    'register-paid-producer': [('paid-producer', 'audit-ms5-paid-producer'), ('register-paid-producer', 'audit-bootstrap')],
    'register-paid-after-c3': [('paid-producer', 'audit-ms5-paid-producer'), ('register-paid-after-c3', 'audit-bootstrap')],
    'authorize-paid-cleanup': [('paid-producer', 'audit-ms5-paid-producer'), ('authorize-paid-cleanup', 'audit-bootstrap')],
    'fulfillment-migration': [('paid-producer', 'audit-ms5-paid-producer'), ('fulfillment-migration', 'audit-ms4-fulfillment-migration')],
    'register-fulfillment-migration': [('paid-producer', 'audit-ms5-paid-producer'), ('fulfillment-migration', 'audit-ms4-fulfillment-migration'), ('register-fulfillment-migration', 'audit-bootstrap')],
    'fulfillment-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('fulfillment-normal', 'audit-ms4-fulfillment-normal')],
    'register-fulfillment-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('fulfillment-normal', 'audit-ms4-fulfillment-normal'), ('register-fulfillment-normal', 'audit-bootstrap')],
    'authorize-fulfillment-cleanup': [('paid-producer', 'audit-ms5-paid-producer'), ('fulfillment-normal', 'audit-ms4-fulfillment-normal'), ('authorize-fulfillment-cleanup', 'audit-bootstrap')],
    'aftersale-review-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('aftersale-review-normal', 'audit-ms4-aftersale-review-normal')],
    'register-aftersale-review-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('aftersale-review-normal', 'audit-ms4-aftersale-review-normal'), ('register-aftersale-review-normal', 'audit-bootstrap')],
    'authorize-aftersale-review-cleanup': [('paid-producer', 'audit-ms5-paid-producer'), ('aftersale-review-normal', 'audit-ms4-aftersale-review-normal'), ('authorize-aftersale-review-cleanup', 'audit-bootstrap')],
    'worker-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('worker-normal', 'audit-ms4-worker-normal')],
    'register-worker-normal': [('paid-producer', 'audit-ms5-paid-producer'), ('worker-normal', 'audit-ms4-worker-normal'), ('register-worker-normal', 'audit-bootstrap')],
    'authorize-worker-cleanup': [('paid-producer', 'audit-ms5-paid-producer'), ('worker-normal', 'audit-ms4-worker-normal'), ('authorize-worker-cleanup', 'audit-bootstrap')],
}
HELPER_RESULTS = {'prepare': 'PREPARED_PRIVATE_CONFIG_ONLY',
    **{name: 'REGISTERED_NATIVE_RESOURCES_ONLY' for name in ['register-initializer', 'register-driver', 'register-customer-prepare', 'register-customer']},
    'authorize-customer-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY',
    'register-paid-producer': 'REGISTERED_NATIVE_RESOURCES_ONLY', 'register-paid-after-c3': 'REGISTERED_NATIVE_RESOURCES_ONLY',
    'authorize-paid-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY',
    'register-fulfillment-migration': 'REGISTERED_NATIVE_RESOURCES_ONLY', 'register-fulfillment-normal': 'REGISTERED_NATIVE_RESOURCES_ONLY',
    'authorize-fulfillment-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY',
    'register-aftersale-review-normal': 'REGISTERED_NATIVE_RESOURCES_ONLY',
    'authorize-aftersale-review-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY',
    'register-worker-normal': 'REGISTERED_NATIVE_RESOURCES_ONLY', 'authorize-worker-cleanup': 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY'}
MODES = {'prepare': {'proof': 'customer-prepare', 'role': 'audit-ms4-customer-prepare',
    'binding': 'ms4-customer-prepare-source-binding.json', 'scope': 'MS4_CUSTOMER_PREPARE_MS5_SOURCE',
    'result': 'ms4-customer-prepare.json', 'result_scope': 'CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
    'status': 'VERIFIED_CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARED_ONLY'},
    'verify': {'proof': 'customer', 'role': 'audit-ms4-customer-http',
    'binding': 'ms4-customer-http-source-binding.json', 'scope': 'MS4_CUSTOMER_HTTP_MS5_SOURCE',
    'result': 'ms4-customer-http.json', 'result_scope': 'NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY',
    'status': 'VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY'}}
CUSTOMER_FORM_SOURCE_BINDING_STATUS = 'BOUND_FROZEN_PHP_SOURCE'
CUSTOMER_FORM_SOURCE_FILES = ({'path': 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php', 'bytes': 68191, 'sha256': 'd47bafd900c50d1e99e8359b46f0c6d6f67e1033d02050bba1890611ea1fc988'}, {'path': 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'bytes': 65119, 'sha256': '925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d'}, {'path': 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php', 'bytes': 239863, 'sha256': '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'}, {'path': 'help/audit-regressions/isolated/ms4-customer-http-normal.php', 'bytes': 117172, 'sha256': '80a394ec165679e5404efef0751676f902674ea36caddc07d4249cf87e0f438c'}, {'path': 'help/audit-regressions/isolated/ms4-fulfillment-normal.php', 'bytes': 128359, 'sha256': '4d38c7ac0d8afbc72a0000319765e155fbd8a116656c43652e54cd824dc992b7'}, {'path': 'help/audit-regressions/isolated/ms4-worker-normal.php', 'bytes': 127354, 'sha256': '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8'}, {'path': 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php', 'bytes': 109849, 'sha256': '956f4dbcf96afa7155a478cdb7e74b960891d120148e74ff8735b6da53d37e5d'})
CUSTOMER_FORM_PRIVATE_ROOT = '/run/audit-customer-form'
CUSTOMER_FORM_ENV = 'PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT'

ENV_FIXED = {'APP_ENV': 'testing', 'PHP_MARKETPLACE_ENABLED': 'true',
    'PHP_MARKETPLACE_TRADE_ENABLED': 'true', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED': 'true', 'PHP_CACHE_DRIVER': 'redis'}
ENV_NAMES = ['APP_ENV', 'PHP_MARKETPLACE_ENABLED', 'PHP_MARKETPLACE_TRADE_ENABLED', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED',
    'PHP_MARKETPLACE_SELF_OPERATED_MER_ID', 'PHP_CACHE_DRIVER']
CUSTOMER_FORM_ENV_NAMES = sorted(ENV_NAMES + [CUSTOMER_FORM_ENV])
# The template matches by name, so an unexpected or duplicate value is observed and rejected.
ENV_INSPECT = '{{range .Config.Env}}{{if or ' + ' '.join(
    '(and (ge (len .) %d) (eq (slice . 0 %d) %s))' % (len(name) + 1, len(name) + 1, json.dumps(name + '='))
    for name in ENV_NAMES) + '}}{{json .}}{{println}}{{end}}{{end}}'


AFTERSALE_ENV_NAMES = sorted(ENV_NAMES + ['PHP_MARKETPLACE_AFTERSALE_ENABLED', 'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT', CUSTOMER_FORM_ENV])
# One fixed range per exact name preserves sorted order and exposes duplicate/missing keys.
# Literal separators plus docker inspect's final LF produce exactly eight complete JSON string lines.
AFTERSALE_ENV_INSPECT = '\n'.join(
    '{{range .Config.Env}}{{if and (ge (len .) %d) (eq (slice . 0 %d) %s)}}{{json .}}{{end}}{{end}}'
    % (len(name) + 1, len(name) + 1, json.dumps(name + '=')) for name in AFTERSALE_ENV_NAMES)


CUSTOMER_FORM_ENV_INSPECT = ''.join(
    '{{range .Config.Env}}{{if and (ge (len .) %d) (eq (slice . 0 %d) %s)}}{{json .}}{{println}}{{end}}{{end}}'
    % (len(name) + 1, len(name) + 1, json.dumps(name + '=')) for name in CUSTOMER_FORM_ENV_NAMES)


def aftersale_environment(context):
    return public_environment(context) | {'PHP_MARKETPLACE_AFTERSALE_ENABLED': 'true',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT': AFTERSALE_PRIVATE_ROOT, CUSTOMER_FORM_ENV: CUSTOMER_FORM_PRIVATE_ROOT}


def aftersale_environment_bytes(context):
    value = aftersale_environment(context)
    return ('\n'.join(json.dumps(name + '=' + value[name], ensure_ascii=False, separators=(',', ':'))
        for name in AFTERSALE_ENV_NAMES) + '\n').encode('utf-8')


def aftersale_environment_observation(context, cid):
    result = subprocess.run(['docker', 'inspect', '--format', AFTERSALE_ENV_INSPECT, cid], capture_output=True)
    receipt = {'stage': 'aftersale-review-normal-public-environment-inspect', 'native_exit': result.returncode,
        'stdout_bytes': len(result.stdout), 'stdout_sha256': digest(result.stdout),
        'stderr_bytes': len(result.stderr), 'stderr_sha256': digest(result.stderr), 'private_environment_logs_omitted': True}
    expect(type(result.returncode) is int and result.returncode == 0 and result.stderr == b''
        and result.stdout == aftersale_environment_bytes(context), 'ACTUAL_NINE_ORDERED_COMPLETE_PUBLIC_ENV_BYTES')
    lines = result.stdout.decode('utf-8').splitlines()
    expect(len(lines) == 9 and [json.loads(line).split('=', 1)[0] for line in lines] == AFTERSALE_ENV_NAMES,
        'EXACT_NINE_NATIVE_ENV_NAMES_NO_DUPLICATE_OR_MISSING')
    value = {name: json.loads(line)[len(name) + 1:] for name, line in zip(AFTERSALE_ENV_NAMES, lines)}
    expect(value == aftersale_environment(context), 'ACTUAL_NINE_NATIVE_ENV_VALUES')
    return value, receipt


def record_aftersale_environment(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    value, receipt = aftersale_environment_observation(context, cid)
    new(evidence / 'aftersale-review-normal-environment-native.json', receipt)
    new(evidence / 'aftersale-review-normal-environment.json', {'schema': 1, 'scope': AFTERSALE_REVIEW['environment_scope'],
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': cid, 'freeze_sha256': context['freeze_sha256'],
        'environment': value, 'native_projection_sha256': receipt['stdout_sha256']})


def verify_aftersale_environment(context, cid, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    value = load(evidence / 'aftersale-review-normal-environment.json')
    receipt = load(evidence / 'aftersale-review-normal-environment-native.json')
    raw = aftersale_environment_bytes(context)
    expect(type(receipt) is dict and set(receipt) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256',
        'private_environment_logs_omitted'} and receipt['stage'] == 'aftersale-review-normal-public-environment-inspect'
        and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == digest(raw)
        and type(receipt['stderr_bytes']) is int and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
        and receipt['private_environment_logs_omitted'] is True, 'EIGHT_ENV_REAL_NATIVE_RECEIPT_EXACT_CLOSED_FIELDS')
    expect(paid_equal(value, {'schema': 1, 'scope': AFTERSALE_REVIEW['environment_scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'freeze_sha256': context['freeze_sha256'], 'environment': aftersale_environment(context),
        'native_projection_sha256': receipt['stdout_sha256']}), 'EIGHT_ENV_COMPLETE_PUBLIC_WRAPPER_AND_NATIVE_BINDING')
    if native_required:
        actual, fresh = aftersale_environment_observation(context, cid)
        expect(paid_equal(actual, value['environment']) and paid_equal(fresh, receipt), 'SAME_EIGHT_ACTUAL_ENVIRONMENT_BYTES_BEFORE_START')
    return value

def customer_paths(repo):
    paths = set(CUSTOMER_EXTRA)
    for root in CUSTOMER_ROOTS:
        folder = Path(repo) / root
        expect(folder.is_dir() and not folder.is_symlink(), 'CUSTOMER_REAL_SOURCE_ROOT')
        for path in folder.rglob('*'):
            expect(not path.is_symlink(), 'CUSTOMER_NO_SOURCE_SYMLINK')
            if path.is_file():
                paths.add(path.relative_to(repo).as_posix())
    return sorted(paths)


def paid_paths(repo):
    paths = customer_paths(repo) + [PAID_ENTRY]
    expect(len(paths) == len(set(paths)), 'EXPLICIT_UNIQUE_PAID_TENTH_SOURCE')
    return sorted(paths)


def canonical_host(value):
    value = str(value).replace('\\', '/').rstrip('/')
    match = re.fullmatch(r'(?:/run/desktop/mnt/host|/host_mnt)/([a-zA-Z])/(.*)', value)
    if match:
        value = match[1] + ':/' + match[2]
    return value.casefold()


def owned(context, facts, service):
    labels = facts['labels']
    expect(labels.get('tianli.audit') == '1' and labels.get('tianli.audit.run') == context['run_id']
           and labels.get('com.docker.compose.project') == context['project'] and labels.get('com.docker.compose.service') == service, 'OWNED_NATIVE_LABELS')
    expect(facts['image'] == ({'audit-mysql': MYSQL, 'audit-redis': REDIS}.get(service, PHP)) and facts['restart_count'] == 0, 'PINNED_NATIVE_IMAGE_RESTART')
    expect(set(facts['networks']) == {context['project'] + '_isolation'} and all(v is None for v in (facts['ports'] or {}).values()), 'ONE_INTERNAL_NETWORK_NO_PORTS')
    expect(not facts['port_bindings'], 'NO_CREATED_HOST_PORT_BINDINGS')
    if service not in ['audit-mysql', 'audit-redis']:
        expect(facts['read_only'] is True and facts['privileged'] is False and facts['entrypoint'] == ['php'] and facts['workdir'] == '/workspace', 'NATIVE_HELPER_BOUNDARY')
        expect(facts['cap_drop'] == ['ALL'] and facts['security_options'] in [['no-new-privileges'], ['no-new-privileges:true']], 'NATIVE_CAPABILITIES_PRIVILEGE_BOUNDARY')
    if service == 'audit-ms4-initialize':
        expect(facts['command'] == ['/run/ms4-prep/ms4-initialize.php'] and facts['user'] == '0:0'
            and set(facts['tmpfs']) == {'/tmp'}, 'CUSTOMER_FORM_INITIALIZER_EXACT_ENTRY_USER_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/run/audit-bootstrap': ('volume', context['project'] + '_bootstrap-secrets', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            CUSTOMER_FORM_PRIVATE_ROOT: ('volume', context['project'] + '_customer-form-evidence', True)}
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == 7 and {row['Destination'] for row in mounts} == set(expected)
            and all(row['Destination'] == '/tmp' and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'),
            'CUSTOMER_FORM_INITIALIZER_CLOSED_SEVEN_NON_TMPFS_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source),
                'CUSTOMER_FORM_INITIALIZER_EXACT_MOUNT_IDENTITY_ACCESS')
        expect('audit-app' not in (facts['networks'][context['project'] + '_isolation'].get('Aliases') or []),
            'CUSTOMER_FORM_INITIALIZER_NO_HTTP_ALIAS')

    customer_mode = next((mode for mode, spec in MODES.items() if spec['role'] == service), None)
    if customer_mode is not None:
        expect(facts['command'] == [CUSTOMER_ENTRY, customer_mode] and facts['user'] in ['', '0', '0:0', 'root'], 'EXACT_CUSTOMER_ENTRY_MODE_USER')
        expect(set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}, 'CUSTOMER_EXACT_THREE_TMPFS')
        expect(all(type(options) is str and 'rw' in options.split(',') for options in facts['tmpfs'].values()), 'CUSTOMER_REAL_RW_TMPFS_OPTIONS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', customer_mode == 'prepare')}
        if customer_mode == 'verify':
            expected[CUSTOMER_FORM_PRIVATE_ROOT] = ('volume', context['project'] + '_customer-form-evidence', True)
        # Tmpfs may be reported in Mounts on some engines; their complete native
        # rows still remain bound by same_created and persist_terminal.
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(all(row['Destination'] in facts['tmpfs'] for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'ONLY_DECLARED_CUSTOMER_TMPFS_MOUNT_ROWS')
        expect(len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected), 'EXACT_CUSTOMER_SOURCE_AND_PRIVATE_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                   and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'EXACT_CUSTOMER_MOUNT_IDENTITY_MODE')
        aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
        expect(aliases.count('audit-app') == (1 if customer_mode == 'verify' else 0), 'ONLY_VERIFY_REAL_AUDIT_APP_ALIAS')
        if customer_mode == 'verify':
            expect(facts['stop_signal'] == 'SIGTERM', 'REAL_CUSTOMER_TERM_STOP_SIGNAL')

    if service == PAID['role']:
        native_keys = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
            'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid', 'exit_code', 'networks', 'ports',
            'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at'}
        expect(set(facts) in [native_keys, native_keys | {'run_id', 'source_sha', 'proof_name'}]
               and type(facts['exit_code']) is int, 'PAID_COMPLETE_NATIVE_TWENTY_SIX_OR_CREATED_TWENTY_NINE_KEYS')
        if 'proof_name' in facts:
            expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
                   and facts['proof_name'] == PAID['proof'], 'PAID_REAL_CREATED_THREE_IDENTITY_KEYS')
        expect(facts['command'] == [PAID_ENTRY, 'produce'] and facts['user'] == '0:0'
               and facts['stop_signal'] == 'SIGTERM' and type(facts['pid']) is int
               and type(facts['restart_count']) is int and facts['restart_count'] == 0, 'EXACT_PAID_ENTRY_USER_SIGNAL')
        expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}
               and all(type(value) is str and 'rw' in value.split(',') for value in facts['tmpfs'].values()), 'PAID_EXACT_RW_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', True)}
        expected[CUSTOMER_FORM_PRIVATE_ROOT] = ('volume', context['project'] + '_customer-form-evidence', True)
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == 8 and {row['Destination'] for row in mounts} == set(expected)
               and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'PAID_EXACT_NATIVE_EIGHT_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                   and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'PAID_EXACT_MOUNT_SOURCE_ACCESS')
        aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
        expect(aliases.count('audit-app') == 1, 'PAID_ONLY_REAL_SELF_HTTP_ALIAS')

    if service in [FULFILLMENT['role'], AFTERSALE_REVIEW['role']]:
        spec = FULFILLMENT if service == FULFILLMENT['role'] else AFTERSALE_REVIEW
        native_keys = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
            'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid', 'exit_code', 'networks', 'ports',
            'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at'}
        expect(set(facts) in [native_keys, native_keys | {'run_id', 'source_sha', 'proof_name'}]
               and type(facts['exit_code']) is int, 'PAID_COMPLETE_NATIVE_TWENTY_SIX_OR_CREATED_TWENTY_NINE_KEYS')
        if 'proof_name' in facts:
            expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
                   and facts['proof_name'] == spec['proof'], 'PAID_REAL_CREATED_THREE_IDENTITY_KEYS')
        expect(facts['command'] == [FULFILLMENT_ENTRY if service == FULFILLMENT['role'] else AFTERSALE_REVIEW_ENTRY, 'normal'] and facts['user'] == '0:0'
               and facts['stop_signal'] == 'SIGTERM' and type(facts['pid']) is int
               and type(facts['restart_count']) is int and facts['restart_count'] == 0, 'EXACT_PAID_ENTRY_USER_SIGNAL')
        expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}
               and all(type(value) is str and 'rw' in value.split(',') for value in facts['tmpfs'].values()), 'PAID_EXACT_RW_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', True)}
        expected[CUSTOMER_FORM_PRIVATE_ROOT] = ('volume', context['project'] + '_customer-form-evidence', service == AFTERSALE_REVIEW['role'])
        if service == AFTERSALE_REVIEW['role']:
            expected[AFTERSALE_PRIVATE_ROOT] = ('volume', context['project'] + '_aftersale-evidence', True)
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected)
               and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'PAID_EXACT_NATIVE_SEVEN_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                   and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'PAID_EXACT_MOUNT_SOURCE_ACCESS')
        aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
        expect(aliases.count('audit-app') == (0 if service == FULFILLMENT['role'] else 1), 'PAID_ONLY_REAL_SELF_HTTP_ALIAS')

    if service == FULFILLMENT_MIGRATION['role']:
        expect(facts['command'] == [FULFILLMENT_MIGRATION_ENTRY] and facts['user'] == '0:0', 'EXACT_FULFILLMENT_MIGRATION_ENTRY_AND_USER')
        expect(set(facts['tmpfs']) == {'/tmp'}, 'MIGRATION_ONLY_PRIVATE_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/audit': ('bind', context['run_dir'], True), '/run/audit-bootstrap': ('volume', context['project'] + '_bootstrap-secrets', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False)}
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected)
            and all(row['Destination'] == '/tmp' for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'MIGRATION_EXACT_FIVE_NON_TMPFS_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'MIGRATION_EXACT_SOURCE_AND_PRIVATE_MODE')
        expect('audit-app' not in (facts['networks'][context['project'] + '_isolation'].get('Aliases') or []), 'MIGRATION_NO_HTTP_ALIAS')


    if service == WORKER['role']:
        native_keys = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
            'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid', 'exit_code', 'networks', 'ports',
            'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at'}
        expect(set(facts) in [native_keys, native_keys | {'run_id', 'source_sha', 'proof_name'}]
            and type(facts['exit_code']) is int, 'WORKER_COMPLETE_NATIVE_TWENTY_SIX_OR_CREATED_TWENTY_NINE')
        if 'proof_name' in facts:
            expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
                and facts['proof_name'] == WORKER['proof'], 'WORKER_REAL_CREATED_IDENTITY')
        expect(facts['command'] == [WORKER_ENTRY, 'normal'] and facts['user'] == '0:0' and facts['stop_signal'] == 'SIGTERM'
            and type(facts['pid']) is int and type(facts['restart_count']) is int and facts['restart_count'] == 0,
            'WORKER_EXACT_SOURCE_ENTRY_USER_SIGNAL')
        expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}
            and all(type(options) is str and 'rw' in options.split(',') for options in facts['tmpfs'].values()), 'WORKER_EXACT_THREE_PRIVATE_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', True),
            AFTERSALE_PRIVATE_ROOT: ('volume', context['project'] + '_aftersale-evidence', False),
            CUSTOMER_FORM_PRIVATE_ROOT: ('volume', context['project'] + '_customer-form-evidence', False)}
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == 9 and {row['Destination'] for row in mounts} == set(expected)
            and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True
                for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'WORKER_COMPLETE_NINE_NON_TMPFS_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source),
                'WORKER_EXACT_SOURCE_PRIVATE_READ_ONLY_AFTERSALE_MOUNT')
        expect('audit-app' not in (facts['networks'][context['project'] + '_isolation'].get('Aliases') or []),
            'WORKER_NO_HTTP_ALIAS')


def proof_expected(context, proof):
    expect(proof in PROOF_ROLES, 'KNOWN_PROOF_EXPECTED_ROLES')
    expected = base_roles(context)
    for name, role in PROOF_ROLES[proof]:
        cid = bound_created(context, name, role)['id']
        expect(cid not in expected, 'DISTINCT_KNOWN_CREATED_CID')
        expected[cid] = role
    return expected


def normal_child(row):
    expect(type(row.get('pid')) is int and row['pid'] > 0 and row.get('native_exit') == 0
           and type(row.get('native_exit')) is int and row.get('stderr_bytes') == 0
           and row.get('capture_error_class') is None and type(row.get('stdout_bytes')) is int
           and 0 < row['stdout_bytes'] <= 1048576, 'REAL_BOUNDED_NORMAL_CHILD')
    close, terminal = row.get('proc_close_exit'), row.get('same_handle_terminal')
    expect(type(close) is int and close in [0, -1], 'REAL_CHILD_PROC_CLOSE')
    if terminal is not None:
        expect(terminal == {'running': False, 'signaled': False, 'stopped': False, 'exitcode': 0},
               'SAME_CHILD_HANDLE_NORMAL_TERMINAL')
    expect(close == 0 or terminal is not None, 'NEGATIVE_CLOSE_REQUIRES_ACTUAL_SAME_HANDLE_TERMINAL')
    for key in ['stdout_sha256', 'stderr_sha256']:
        expect(isinstance(row.get(key), str) and re.fullmatch('[a-f0-9]{64}', row[key]), 'CHILD_FULL_HASH')
    expect(row['stderr_sha256'] == digest(b''), 'EMPTY_CHILD_STDERR_HASH')

def verify_customer_form_initialization(context, value):
    keys = {'schema', 'scope', 'volume', 'created_at', 'actual_euid', 'actual_egid', 'dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'empty', 'App_started'}
    expect(type(value) is dict and set(value) == keys and type(value['schema']) is int and value['schema'] == 1
        and value['scope'] == 'CU_FORM_EMPTY_PRIVATE_ROOT_INITIALIZATION_BEFORE_APP'
        and value['volume'] == context['project'] + '_customer-form-evidence'
        and type(value['actual_euid']) is int and value['actual_euid'] == 0
        and type(value['actual_egid']) is int and value['actual_egid'] == 0
        and all(type(value[key]) is int for key in ['dev', 'ino', 'mode', 'uid', 'gid', 'nlink'])
        and value['dev'] >= 0 and value['ino'] > 0 and value['mode'] == 16832
        and value['uid'] == value['actual_euid'] and value['gid'] == value['actual_egid'] and value['nlink'] >= 1
        and value['empty'] is True and value['App_started'] is False,
        'CUSTOMER_FORM_ACTUAL_INITIALIZER_EMPTY_ROOT_OWNER_0700_BEFORE_APP')
    rows = [row for row in context['initial_volumes'] if row['name'] == value['volume']]
    expect(len(rows) == 1 and rows[0]['created_at'] == value['created_at']
        and rows[0]['labels']['tianli.audit.run'] == context['run_id']
        and rows[0]['labels']['com.docker.compose.project'] == context['project'],
        'CUSTOMER_FORM_INITIALIZER_SAME_ACTUAL_VOLUME_IDENTITY')


def verify_customer_form_upload_child(child):
    keys = {'stopped', 'group', 'remaining_members', 'listener_open', 'stop_signal', 'term_attempted',
        'native_child', 'native_proc_close', 'normal_only', 'streams'}
    expect(type(child) is dict and set(child) == keys, 'CUSTOMER_FORM_EXACT_TEN_UPLOAD_CHILD_FIELDS')
    verify_child_stopped(child)
    group, native = child['group'], child['native_child']
    expect(set(group) == {'leader_pid', 'pgid', 'sid', 'leader_starttime'}
        and all(type(group[key]) is int and 1 <= group[key] <= 2147483647 for key in ['leader_pid', 'pgid', 'sid'])
        and group['leader_pid'] == group['pgid'] == group['sid'] == native['pid'],
        'CUSTOMER_FORM_SAME_ACTUAL_REGISTERED_LEADER_GROUP_SESSION_AND_NATIVE_PID')
    start = group['leader_starttime']
    expect((type(start) is int and 0 < start <= 18446744073709551615)
        or (paid_decimal(start, 18446744073709551615) and start != '0'), 'CUSTOMER_FORM_REAL_REGISTERED_LEADER_STARTTIME')
    expect(all(type(native[key]) is bool for key in ['running', 'signaled', 'stopped'])
        and all(type(native[key]) is int for key in ['pid', 'exitcode', 'termsig', 'stopsig'])
        and native['running'] is False and native['stopped'] is False and native['stopsig'] == 0
        and native['exitcode'] in [-1, 0] and native['termsig'] in [0, 15]
        and child['native_proc_close'] in [-1, 0], 'CUSTOMER_FORM_REAL_NORMAL_TERM_OR_RETURN_NO_SIGKILL')
    streams = child['streams']
    expect(type(streams) is dict and set(streams) == {'stdout', 'stderr'}, 'CUSTOMER_FORM_BOTH_COMPLETE_NATIVE_STREAMS')
    stat_names = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks']
    stat_keys = set(stat_names) | {str(index) for index in range(13)}
    stems, identities = [], []
    for name in ['stdout', 'stderr']:
        stream = streams[name]
        expect(type(stream) is dict and set(stream) == {'path', 'bytes', 'sha256', 'stat'}
            and type(stream['path']) is str and type(stream['bytes']) is int and 0 <= stream['bytes'] <= 16777216
            and paid_hash(stream['sha256']), 'CUSTOMER_FORM_COMPLETE_OWN_STREAM_DESCRIPTOR')
        match = re.fullmatch(r'/workspace/crmeb/runtime/customer-form-upload-([a-f0-9]{32})\.' + name, stream['path'])
        expect(match is not None, 'CUSTOMER_FORM_EXACT_CREATE_ONLY_STREAM_NAMESPACE_AND_SUFFIX')
        stems.append(match[1])
        stat = stream['stat']
        expect(type(stat) is dict and set(stat) == stat_keys and all(type(value) is int for value in stat.values())
            and all(stat[str(index)] == stat[key] for index, key in enumerate(stat_names))
            and stat['dev'] >= 0 and stat['ino'] > 0 and stat['mode'] == 33152 and stat['nlink'] == 1
            and stat['uid'] == 0 and stat['gid'] >= 0 and stat['size'] == stream['bytes']
            and all(stat[key] >= 0 for key in ['rdev', 'atime', 'mtime', 'ctime'])
            and stat['blksize'] >= -1 and stat['blocks'] >= -1,
            'CUSTOMER_FORM_COMPLETE_UNCHANGED_PHP_LSTAT_NATIVE_FIELDS_OWNER_MODE_SIZE')
        identities.append((stat['dev'], stat['ino']))
        expect(stream['bytes'] != 0 or stream['sha256'] == digest(b''), 'CUSTOMER_FORM_REAL_EMPTY_STREAM_HASH_IF_EMPTY')
    expect(stems[0] == stems[1] and identities[0] != identities[1], 'CUSTOMER_FORM_SAME_CHILD_TWO_DISTINCT_OWN_NATIVE_STREAMS')


def verify_customer_form_matrix(value, paid):
    counts = {'uploads': 4, 'selected_distinct': 3, 'required_image_entries': 8}
    flags = {'optional_empty', 'multi_product', 'complete34_independent_before', 'three_bind_columns_only', 'original_full_bytes'}
    extra = {'upload_child', 'first_create_official_kernel', 'same_body_original_early_return'} if paid else set()
    expect(type(value) is dict and set(value) == {'schema', 'scope'} | set(counts) | flags | extra
        and type(value['schema']) is int and value['schema'] == 1
        and value['scope'] == ('CU_FORM_NORMAL_PAID' if paid else 'CU_FORM_NORMAL_CUSTOMER_HTTP')
        and all(type(value[key]) is int and value[key] == count for key, count in counts.items())
        and all(value[key] is True for key in flags), 'CUSTOMER_FORM_FINITE_REAL_NORMAL_MATRIX')
    if paid:
        expect(value['first_create_official_kernel'] is True and value['same_body_original_early_return'] is True,
            'CUSTOMER_FORM_SAME_APP_OFFICIAL_KERNEL_AND_ORIGINAL_REPLAY')
        verify_customer_form_upload_child(value['upload_child'])


def verify_customer_form_saved_readers(value):
    flags = {'staged_reader', 'package_root_locators', 'historical_bound_after_actual_confirmation_deadline', 'original_full_bytes'}
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'roots_read', 'saved_reader_kinds'} | flags
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'CU_FORM_NORMAL_SAVED_READERS'
        and type(value['roots_read']) is int and value['roots_read'] == 2
        and value['saved_reader_kinds'] == ['customer_order', 'merchant_order', 'platform_order', 'mobile_order']
        and all(value[key] is True for key in flags), 'CUSTOMER_FORM_SAVED_READERS_REAL_ROOTS_AND_NORMAL_DEADLINE')


def prerequisite_result(context, created_facts, terminal, result):
    owned(context, terminal, 'audit-ms4-initialize'); same_created(created_facts, terminal)
    expect(terminal['status'] == 'exited' and terminal['pid'] == 0 and terminal['exit_code'] == 0
           and terminal['restart_count'] == 0 and result['schema'] == 1
           and result['scope'] == 'MS5_PAYMENT_PREREQUISITES_ONLY'
           and result['status'] == 'VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY'
           and result['native_exit'] == 0 and result['private_cleanup_verified'] is True
           and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
           and result['binding']['container_id'] == created_facts['id']
           and result['binding']['source_sha'] == context['source_sha']
           and result['binding']['freeze_sha256'] == context['freeze_sha256']
           and result['binding']['created_sha256'] == digest((Path(context['run_dir']) / 'evidence/initializer-created.json').read_bytes()),
           'ACTUAL_NINE_CHILD_INITIALIZER_IDENTITY')
    children = result['children']
    sequence = [('marketplace-ms1.php', 'check'), ('marketplace-ms1.php', 'apply'), ('marketplace-ms1.php', 'check'),
                ('marketplace-ms2.php', 'check'), ('marketplace-ms2.php', 'apply-structure'), ('marketplace-ms2.php', 'check'),
                ('marketplace-ms4.php', 'check'), ('marketplace-ms4.php', 'apply-structure'), ('marketplace-ms4.php', 'check')]
    expect(isinstance(children, list) and [(r['script'], r['mode']) for r in children] == sequence
           and len({r['pid'] for r in children}) == 9, 'NINE_EXACT_DISTINCT_NATIVE_CHILDREN')
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    hashes = {r['path']: r['sha256'] for r in frozen['driver_source_files']}
    for index, child in enumerate(children):
        normal_child(child); value = child['result']
        expect(child['script_sha256'] == hashes['help/migrations/' + child['script']]
               and value['mode'] == sequence[index][1]
               and value['state'] == ('applicable' if index in [0, 3, 6] else 'completed')
               and value['changed'] is (index in [1, 4, 7]) and value['issues'] == [], 'EXACT_PREREQUISITE_RESULT')
        if index >= 6:
            expect(value['native_exit'] == 0 and value['source_sha256'] == child['script_sha256']
                   and value['marker'] == 'marketplace-ms4-structure-v1' and value['pending_steps_known'] is True
                   and value['failure_phase'] is None and value['trade_enabled'] is False, 'MS4_REAL_COMPLETED_SOURCE')
    expect(children[6]['result']['applied_steps'] == [] and children[6]['result']['pending_steps']
           and children[7]['result']['applied_steps'] == children[6]['result']['pending_steps']
           and children[7]['result']['pending_steps'] == []
           and children[8]['result']['applied_steps'] == children[8]['result']['pending_steps'] == [],
           'MS4_ACTUAL_STEPS_WITHOUT_OLD_FIXED_COUNT')
    structure = result['prerequisite_structure']
    expect(structure['pre_ms4_count'] == 164 and structure['post_ms4_count'] == 166
           and structure['added_tables'] == ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement']
           and structure['ms4_completed'] is True and structure['ms5_tables_absent'] is True
           and structure['ms4_source_sha256'] == hashes['help/migrations/marketplace-ms4.php'],
           'STAGED_ACTUAL_STRUCTURE_BEFORE_MS5')
    expect(type(result['actual_self_mer_id']) is int and result['actual_self_mer_id'] > 0
           and children[4]['result']['self_mer_id'] == children[5]['result']['self_mer_id'] == result['actual_self_mer_id'],
           'ACTUAL_MS2_SELF_PK_NOT_FIXED')

    verify_customer_form_initialization(context, result['customer_form_private_root'])
def payment_result(context, created_facts, terminal, result):
    owned(context, terminal, 'audit-ms5-payment-migration'); same_created(created_facts, terminal)
    root = Path(context['run_dir']); evidence = root / 'evidence'
    frozen = load(root / 'preparation-source-binding.json')
    binding_path = evidence / 'ms5-payment-migration-source-binding.json'
    created_path = evidence / 'ms5-payment-migration-container-created.json'
    binding = load(binding_path)
    expect(set(binding) == {'schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source',
                           'entry_sha256', 'cli_sha256', 'source_files'}
           and binding['schema'] == 1 and binding['run_id'] == context['run_id']
           and binding['source_sha'] == context['source_sha'] and binding['requested_repo_source'] == context['repo_dir']
           and binding['requested_run_source'] == context['run_dir']
           and binding['source_files'] == frozen['driver_source_files'] and len(binding['source_files']) == 10
           and load(created_path) == created_facts, 'REAL_EXACT_EIGHT_KEY_TEN_SOURCE_BINDING')
    hashes = {r['path']: r['sha256'] for r in binding['source_files']}
    expect(binding['entry_sha256'] == hashes['help/audit-regressions/isolated/ms5-payment-migration.php']
           and binding['cli_sha256'] == hashes['help/migrations/marketplace-ms5-payment.php'], 'EXACT_ENTRY_AND_CLI_HASHES')
    actual = result['binding']
    expect(terminal['status'] == 'exited' and terminal['pid'] == 0 and terminal['exit_code'] == 0
           and terminal['restart_count'] == 0 and result['schema'] == 1
           and result['status'] == 'VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
           and result['scope'] == 'MS5_PAYMENT_NORMAL_STRUCTURE_ONLY' and result['native_exit'] is None
           and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
           and type(result['entry_return_code']) is int and result['entry_return_code'] == 0
           and result['termination'] == 'NORMAL_RETURN' and result['private_cleanup_verified'] is True
           and result['private_cleanup_state'] == 'OWN_FILE_REMOVED'
           and actual['run_id'] == context['run_id'] and actual['container_id'] == created_facts['id']
           and actual['source_sha'] == context['source_sha'] and actual['source_files'] == 10
           and actual['entry_sha256'] == binding['entry_sha256'] and actual['cli_sha256'] == binding['cli_sha256']
           and actual['binding_sha256'] == digest(binding_path.read_bytes())
           and actual['native_created_sha256'] == digest(created_path.read_bytes()),
           'REAL_CONTAINER_TERMINAL_PLUS_UNCHANGED_NULL_ENTRY_NATIVE')
    children = result['children']; modes = ['check', 'apply-structure', 'check', 'apply-structure']
    expect(result['actual_command_counts'] == {'check': 2, 'apply-structure': 2}
           and isinstance(children, list) and [r['mode'] for r in children] == modes
           and len({r['pid'] for r in children}) == 4, 'FOUR_REAL_NATIVE_PAYMENT_CHILDREN')
    steps = ['marketplace_payment_registry', 'marketplace_payment_attempt']
    for index, child in enumerate(children):
        normal_child(child); value = child['result']
        expect(set(value) == {'mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps',
                             'pending_steps_known', 'issues', 'failure_phase', 'marker', 'source_sha256',
                             'history_mapping', 'payment_producer', 'acceptance'}
               and value['mode'] == modes[index] and value['native_exit'] == 0
               and value['state'] == ('applicable' if index == 0 else 'completed')
               and value['changed'] is (index == 1)
               and value['applied_steps'] == (steps if index == 1 else [])
               and value['pending_steps'] == (steps if index == 0 else [])
               and value['pending_steps_known'] is True and value['issues'] == [] and value['failure_phase'] is None
               and value['marker'] == 'marketplace-ms5-payment-structure-v1'
               and value['source_sha256'] == binding['cli_sha256'] and value['history_mapping'] == 'NOT RUN'
               and value['payment_producer'] == 'NOT IMPLEMENTED' and value['acceptance'] == 'NOT RUN',
               'FOUR_EXACT_TWO_STEP_AND_REPEAT_RESULTS')
    expect(result['independent_new_metadata_verified'] is True and result['history_mapping'] == 'NOT RUN'
           and result['payment_producer'] == 'NOT IMPLEMENTED' and result['business_acceptance'] == 'NOT RUN',
           'STRUCTURE_ONLY_NOT_PAYMENT_OR_BUSINESS_ACCEPTANCE')
    snapshots = result['snapshots']; before, after = snapshots['before'], snapshots['after']
    names = ['eb_marketplace_payment_attempt', 'eb_marketplace_payment_registry']
    expect(set(snapshots) == {'before', 'after'} and len(before['names']) == result['original_table_count']
           and set(before['names']) == set(before['tables']) and set(after['names']) == set(after['tables'])
           and set(after['names']) - set(before['names']) == set(names)
           and len(after['names']) == len(before['names']) + 2
           and before['modes'] == after['modes'] and before['redis'] == after['redis'],
           'FULL_ORIGINAL_METADATA_ROWS_MODES_REDIS_RECEIPT')
    expect(set(result['original_rows']) == set(before['names']), 'ALL_ORIGINAL_ROW_COUNTS_PRESENT')
    for name, table in before['tables'].items():
        expect(after['tables'][name] == table and result['original_rows'][name] == table['projection']['rows'],
               'ALL_ORIGINAL_TABLE_PUBLIC_FACTS_IDENTICAL')
    for name in names:
        expect(after['tables'][name]['projection']['rows'] == 0
               and len(after['tables'][name]['projected_columns']) == (11 if name.endswith('_registry') else 18),
               'ONLY_NEW_EMPTY_ELEVEN_EIGHTEEN_COLUMN_TABLES')
    for snapshot in [before, after]:
        for table in snapshot['tables'].values():
            raw = base64.b64decode(table['metadata_serialized_base64'], validate=True)
            expect(digest(raw) == table['metadata_sha256'], 'FULL_BINARY_SAFE_METADATA_BYTES')
    expect([(r['phase'], r['equals'], r['snapshot_sha256']) for r in result['comparisons']] ==
           [('check_first', 'before', before['native_snapshot_sha256']),
            ('check_repeat', 'after', after['native_snapshot_sha256']),
            ('apply-structure_repeat', 'after', after['native_snapshot_sha256'])], 'ALL_THREE_FULL_SNAPSHOT_COMPARISONS')

def actual_self(context):
    evidence = Path(context['run_dir']) / 'evidence'
    result = load(evidence / 'initializer-result.json')
    created = bound_created(context, 'initializer', 'audit-ms4-initialize')
    prerequisite_result(context, created, load(evidence / 'initializer-completed-terminal.json'), result)
    value = result['actual_self_mer_id']
    expect(type(value) is int and 1 <= value <= 2147483647
           and all(row['result']['self_mer_id'] == value and row['result']['ownership_phase'] == 'structure'
                   and row['result']['enabled'] is False for row in result['children'][4:6]), 'ACTUAL_MS2_SELF_PRIMARY_KEY')
    return value


def compose(context, args, label, proof='initializer', phase='prepare'):
    env = os.environ.copy()
    env.pop('MS4_CUSTOMER_SELF_MER_ID', None)
    env.pop('MS5_PROOF_NAME', None)
    env.pop('MS4_FULFILLMENT_PROOF_NAME', None)
    env.pop('MS4_AFTERSALE_REVIEW_PROOF_NAME', None)
    env.pop('MS4_WORKER_NORMAL_PROOF_NAME', None)
    env.update({'AUDIT_RUN_ID': context['run_id'], 'AUDIT_PROJECT': context['project'], 'AUDIT_SOURCE_SHA': context['source_sha'],
                'AUDIT_REPO_DIR': context['repo_dir'], 'AUDIT_RUN_DIR': context['run_dir'], 'MS4_PREP_DIR': context['prep_dir'],
                'MS4_PROOF_NAME': proof, 'MS4_BOOTSTRAP_PHASE': phase, 'MS4_BOOTSTRAP_PRIVATE_MODE': 'rw' if phase == 'prepare' else 'ro'})
    customer_roles = {spec['role'] for spec in MODES.values()}
    if proof in ['customer', 'customer-prepare', PAID['proof']] or customer_roles.intersection(args) or PAID['role'] in args or proof == FULFILLMENT['proof'] or FULFILLMENT['role'] in args or proof == AFTERSALE_REVIEW['proof'] or AFTERSALE_REVIEW['role'] in args or proof == WORKER['proof'] or WORKER['role'] in args:
        env['MS4_CUSTOMER_SELF_MER_ID'] = str(actual_self(context))
    result = subprocess.run(['docker', 'compose', '--project-name', context['project'], '--file', str(PREP / 'compose.yml'), *args], capture_output=True, env=env)
    new(Path(context['run_dir']) / 'evidence' / (label + '-compose-native.json'), {'stage': label, 'native_exit': result.returncode,
        'stdout_sha256': digest(result.stdout), 'stderr_sha256': digest(result.stderr), 'stdout_bytes': len(result.stdout), 'stderr_bytes': len(result.stderr)})
    expect(result.returncode == 0, 'COMPOSE_' + label)
    return result.stdout.decode('utf-8').strip()


def customer_form_environment(context):
    return public_environment(context) | {CUSTOMER_FORM_ENV: CUSTOMER_FORM_PRIVATE_ROOT}


def customer_form_environment_bytes(context):
    values = customer_form_environment(context)
    return ('\n'.join(json.dumps(name + '=' + values[name], ensure_ascii=False, separators=(',', ':'))
        for name in CUSTOMER_FORM_ENV_NAMES) + '\n\n').encode('utf-8')


def customer_form_environment_observation(context, cid):
    expect(re.fullmatch('[a-f0-9]{64}', cid), 'CUSTOMER_FORM_ENV_ACTUAL_CID')
    process = subprocess.run(['docker', 'inspect', '--format', CUSTOMER_FORM_ENV_INSPECT, cid], capture_output=True)
    raw = customer_form_environment_bytes(context)
    expect(type(process.returncode) is int and process.returncode == 0 and process.stderr == b''
        and process.stdout == raw, 'CUSTOMER_FORM_EXACT_SORTED_SEVEN_ENV_BYTES_DOUBLE_FINAL_LF')
    lines = process.stdout.decode('utf-8').splitlines()
    expect(len(lines) == 8 and lines[-1] == '' and
        [json.loads(line).split('=', 1)[0] for line in lines[:-1]] == CUSTOMER_FORM_ENV_NAMES,
        'CUSTOMER_FORM_SEVEN_ENV_NAMES_NO_DUPLICATE_MISSING_OR_EXTRA')
    values = {name: json.loads(line)[len(name) + 1:] for name, line in zip(CUSTOMER_FORM_ENV_NAMES, lines[:-1])}
    expect(values == customer_form_environment(context), 'CUSTOMER_FORM_EXACT_SEVEN_NATIVE_ENV_VALUES')
    return values, {'stage': 'customer-public-environment-inspect', 'native_exit': process.returncode,
        'stdout_bytes': len(process.stdout), 'stdout_sha256': digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': digest(process.stderr), 'private_environment_logs_omitted': True}


def public_environment(context):
    return ENV_FIXED | {'PHP_MARKETPLACE_SELF_OPERATED_MER_ID': str(actual_self(context))}


def environment_observation(context, cid, customer_form=False):
    if customer_form:
        return customer_form_environment_observation(context, cid)
    expect(re.fullmatch('[a-f0-9]{64}', cid), 'CUSTOMER_ENV_ACTUAL_CID')
    process = subprocess.run(['docker', 'inspect', '--format', ENV_INSPECT, cid], capture_output=True)
    # Only six public name prefixes are selected by the native Go template.
    # No Config.Env, private child environment, or native stderr is published.
    expect(process.returncode == 0 and process.stderr == b'', 'ACTUAL_CUSTOMER_ENV_NATIVE_ZERO')
    values = [json.loads(line) for line in process.stdout.decode('utf-8').splitlines() if line.strip()]
    expect(len(values) == 6 and all(type(line) is str and '=' in line for line in values), 'SIX_ACTUAL_PUBLIC_ENV_ROWS')
    pairs = [line.split('=', 1) for line in values]
    expect(len({pair[0] for pair in pairs}) == 6 and {pair[0] for pair in pairs} == set(ENV_NAMES)
           and dict(pairs) == public_environment(context), 'EXACT_REAL_ENABLED_ENV_NO_DUPLICATES')
    return dict(pairs), {'stage': 'customer-public-environment-inspect', 'native_exit': process.returncode,
        'stdout_bytes': len(process.stdout), 'stdout_sha256': digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': digest(process.stderr), 'private_environment_logs_omitted': True}


PREREQUISITES = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json',
    'initializer-result.json', 'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
    'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']
FILES_PREREQUISITES = ['files-bootstrap-created.json', 'files-bootstrap-terminal.json',
    'files-bootstrap-result.json', 'files-bootstrap-shutdown.json']
PREPARE_PREREQUISITES = ['customer-prepare-created.json', 'customer-prepare-terminal.json', 'ms4-customer-prepare.json']


def migration_prerequisites(context):
    evidence = Path(context['run_dir']) / 'evidence'
    initializer = bound_created(context, 'initializer', 'audit-ms4-initialize')
    prerequisite_result(context, initializer, load(evidence / 'initializer-completed-terminal.json'), load(evidence / 'initializer-result.json'))
    driver = bound_created(context, 'driver', 'audit-ms5-payment-migration')
    payment_result(context, driver, load(evidence / 'driver-terminal.json'), load(evidence / 'ms5-payment-migration-normal.json'))
    formal = load(evidence / 'ms5-payment-migration-formal-manifest.json')
    expected = {'audit-ms5-payment-migration': driver['id'], **context['initial_service_ids']}
    expect(formal['run_id'] == context['run_id'] and formal['project'] == context['project']
           and formal['commit_sha'] == context['source_sha'] and formal['isolation'] == 'ISO-FULL'
           and formal['guard_mode'] == 'read-only' and formal['network_internal'] is True
           and formal['network'] == {'id': context['network_id'], 'name': context['initial_network']['Name']}
           and set(formal['services']) == set(expected)
           and all(formal['services'][role]['id'] == cid for role, cid in expected.items())
           and sorted(formal['volumes']) == sorted(row['name'] for row in context['initial_volumes'])
           and formal['volume_identities'] == {row['name']: row['created_at'] for row in context['initial_volumes']},
           'ORIGINAL_MS5_FORMAL_THREE_ROLES_NETWORK_SIX_VOLUMES')


def prerequisite_hashes(context, mode):
    expect(mode in MODES, 'KNOWN_CUSTOMER_PREREQUISITE_MODE')
    evidence = Path(context['run_dir']) / 'evidence'
    migration_prerequisites(context)
    verify_files_bootstrap(context, False)
    names = PREREQUISITES + FILES_PREREQUISITES
    if mode == 'verify':
        verify_mode_result(context, 'prepare', False)
        names += PREPARE_PREREQUISITES
    return {name: digest((evidence / name).read_bytes()) for name in names}


def environment_projection_matches(context, receipt, customer_form=False):
    if customer_form:
        raw = customer_form_environment_bytes(context)
        return receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == digest(raw)
    lines = [json.dumps(name + '=' + public_environment(context)[name]).encode() for name in ENV_NAMES]
    return any(receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == digest(raw)
               for permutation in itertools.permutations(lines)
               for raw in [b'\n'.join(permutation) + b'\n', b'\n'.join(permutation) + b'\n\n'])


def mode_binding(context, mode, cid):
    spec = MODES[mode]; root = Path(context['run_dir']); evidence = root / 'evidence'
    frozen = load(root / 'preparation-source-binding.json')
    return {'schema': 1, 'scope': spec['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / (spec['proof'] + '-created.json')).read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': next(row['sha256'] for row in frozen['customer_source_files'] if row['path'] == CUSTOMER_ENTRY),
        'source_files': frozen['customer_source_files'], 'prerequisites_sha256': prerequisite_hashes(context, mode)}


def record_mode_binding(context, mode, cid):
    spec = MODES[mode]; evidence = Path(context['run_dir']) / 'evidence'
    actual, native_receipt = environment_observation(context, cid, mode == 'verify')
    new(evidence / (spec['proof'] + '-environment-native.json'), native_receipt)
    new(evidence / (spec['proof'] + '-environment.json'), {'schema': 1, 'scope': 'MS4_CUSTOMER_REAL_ENVIRONMENT',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': cid,
        'freeze_sha256': context['freeze_sha256'], 'environment': actual, 'native_projection_sha256': native_receipt['stdout_sha256']})
    new(evidence / spec['binding'], mode_binding(context, mode, cid))


def verify_mode_binding(context, mode, cid, native_required=True):
    spec = MODES[mode]; evidence = Path(context['run_dir']) / 'evidence'
    actual_binding = load(evidence / spec['binding']); wanted = mode_binding(context, mode, cid)
    expect(actual_binding == wanted and type(actual_binding['schema']) is int, 'COMPLETE_EXACT_MODE_SOURCE_BINDING')
    actual = load(evidence / (spec['proof'] + '-environment.json'))
    expect(set(actual) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'environment', 'native_projection_sha256'}
           and type(actual['schema']) is int and actual['schema'] == 1 and actual['scope'] == 'MS4_CUSTOMER_REAL_ENVIRONMENT'
           and all(actual[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
           and actual['container_id'] == cid and actual['environment'] == (customer_form_environment(context) if mode == 'verify' else public_environment(context)), 'EXACT_MODE_ENVIRONMENT_RECEIPT')
    receipt = load(evidence / (spec['proof'] + '-environment-native.json'))
    expect(receipt['stage'] == 'customer-public-environment-inspect' and type(receipt['native_exit']) is int
           and receipt['native_exit'] == 0 and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
           and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] > 0
           and receipt['stdout_sha256'] == actual['native_projection_sha256'] and receipt['private_environment_logs_omitted'] is True
           and environment_projection_matches(context, receipt, mode == 'verify'),
           'ACTUAL_NATIVE_ENV_PROJECTION_BINDING')
    if native_required:
        projected, now = environment_observation(context, cid, mode == 'verify')
        expect(projected == actual['environment'] and now == receipt, 'SAME_NATIVE_ENV_PROJECTION_BYTES')
    return actual_binding


def normal_terminal(context, proof, role, native_required=True):
    before = bound_created(context, proof, role)
    if native_required:
        facts = inspect(context, before['id'], proof + '-normal-terminal-required')
    else:
        facts = load(Path(context['run_dir']) / 'evidence' / (proof + '-terminal.json'))
    owned(context, facts, role); same_created(before, facts)
    expect(facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
           and type(facts['restart_count']) is int and facts['restart_count'] == 0
           and type(facts['exit_code']) is int and facts['exit_code'] == 0, 'ACTUAL_CUSTOMER_NORMAL_TERMINAL')
    persist_terminal(context, proof, facts)
    return facts


def verify_mode_result(context, mode, native_required=True):
    spec = MODES[mode]; evidence = Path(context['run_dir']) / 'evidence'
    facts = normal_terminal(context, spec['proof'], spec['role'], native_required)
    verify_mode_binding(context, mode, facts['id'], native_required)
    result = load(evidence / spec['result'])
    expect(type(result['schema']) is int and result['schema'] == 1
           and result['scope'] == spec['result_scope'] and result['status'] == spec['status']
           and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
           and result['container_id'] == facts['id'] and result['phase'] == 'final_source'
           and type(result['native_exit']) is int and result['native_exit'] == 0
           and result['full_source_unchanged'] is True and type(result['assertions']) is int and result['assertions'] > 0
           and result['native_created_sha256'] == digest((evidence / (spec['proof'] + '-created.json')).read_bytes())
           and result['source_binding_sha256'] == digest((evidence / spec['binding']).read_bytes())
           and result['environment_sha256'] == digest((evidence / (spec['proof'] + '-environment.json')).read_bytes())
           and type(result['business']) is dict and bool(result['business']), 'ACTUAL_CUSTOMER_MODE_COMMON_RESULT')
    verify_business_result(context, mode, result['business'])
    if mode == 'prepare':
        expect(result['child'] is None, 'PREPARE_NO_HTTP_CHILD')
    else:
        verify_child_stopped(result['child'])
    return result


def verify_business_result(context, mode, business):
    # These are the finite fields returned by the actual source-bound helper,
    # not future conjectures or replacement business assertions.
    expect(re.fullmatch('[a-f0-9]{12}', business['fixture_sha256_prefix']) is not None, 'ACTUAL_PRIVATE_FIXTURE_PUBLIC_PREFIX_ONLY')
    events = business['event_preconditions']
    expect(events['custom_event_count'] == 0 and events['external_channels_enabled'] == 0 and events['queue_open'] == 0
           and type(events['login_notice_rows']) is int and events['login_notice_rows'] >= 0, 'ACTUAL_NORMAL_EVENT_PRECONDITIONS')
    expect(business['real_enabled_configuration'] is True, 'REAL_ENABLED_BUSINESS_CONFIGURATION')
    if mode == 'prepare':
        expect(type(business['fixture_bytes']) is int and business['fixture_bytes'] > 0
               and business['fixture_counts'] == {'customers': 1, 'merchants': 1, 'products': 2, 'skus': 3, 'cart_rows': 0, 'orders': 0}
               and business['production_password_reset'] is True and business['self_operated_identity_verified'] is True
               and business['HTTP_executed'] is False and business['order_creation_executed'] is False, 'ACTUAL_BOUNDED_DML_FIXTURE_PREPARATION')
        return
    evidence = Path(context['run_dir']) / 'evidence'
    prepare = load(evidence / 'ms4-customer-prepare.json')['business']
    expect(type(business['http_call_count']) is int and business['http_call_count'] == 12
           and type(business['fact_table_count']) is int and business['fact_table_count'] == 27
           and business['saved_counts'] == {'orders': 1, 'items': 2, 'reserves': 2, 'invoices': 1, 'ledger': 1, 'unselected_cart': 1,
               'cart_stat_rows': 3, 'order_stat_rows': 2, 'creation_status_rows': 1}
           and business['fixture_sha256_prefix'] == prepare['fixture_sha256_prefix']
           and business['prepare_receipt_sha256'] == digest((evidence / 'ms4-customer-prepare.json').read_bytes())
           and events == prepare['event_preconditions'], 'ACTUAL_TWELVE_HTTP_CALLS_27_TABLES_AND_SAVED_COUNTS')
    expect(all(business[name] is True for name in ['real_credential_login', 'real_request_uid_and_production_handlers',
        'independent_committed_sql_verified', 'same_request_replay_unchanged', 'owner_lookup_unchanged',
        'saved_computed_unchanged', 'nonempty_forms_and_invoice_verified', 'whole_line_postage_verified',
        'only_owner_auth_fields_changed', 'order_still_unpaid'])
        and re.fullmatch('[a-f0-9]{12}', business['private_facts_sha256_prefix']) is not None
        and business['payment_or_shipping_executed'] is False and business['customer_DOM_acceptance'] == 'NOT RUN'
        and business['full_acceptance'] == 'INCOMPLETE', 'ACTUAL_CUSTOMER_HTTP_FINITE_SOURCE_HELPER_PROOF')

    verify_customer_form_matrix(business['customer_form'], False)

def verify_child_stopped(child):
    # Exact finalized field names are shared with the entry source producer.
    expect(type(child) is dict and child['stopped'] is True and child['remaining_members'] == []
           and child['listener_open'] is False and type(child['stop_signal']) is int and child['stop_signal'] == 15
           and child['term_attempted'] is True and child['normal_only'] is True
           and type(child['group']) is dict and type(child['native_child']) is dict
           and set(child['native_child']) == {'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'}
           and type(child['native_child']['pid']) is int and child['native_child']['pid'] > 0
           and child['native_child']['running'] is False and type(child['native_proc_close']) is int,
           'ACTUAL_REAL_CHILD_SINGLE_TERM_GROUP_AND_LISTENER_CLOSED')


def paid_equal(left, right):
    if type(left) is not type(right):
        return False
    if type(left) is dict:
        return set(left) == set(right) and all(paid_equal(left[key], right[key]) for key in left)
    if type(left) is list:
        return len(left) == len(right) and all(paid_equal(a, b) for a, b in zip(left, right))
    return left == right


def paid_hash(value):
    return type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None


def paid_decimal(value, maximum=None):
    valid = type(value) is str and re.fullmatch('0|[1-9][0-9]*', value) is not None
    return valid and (maximum is None or int(value) <= maximum)


def paid_prerequisite_hashes(context, current_formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    migration_prerequisites(context)
    verify_files_bootstrap(context, False)
    verify_mode_result(context, 'prepare', False)
    verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    verify_paid_helper(context, 'register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    created_facts = bound_created(context, PAID['proof'], PAID['role'])
    historical = [bound_created(context, proof, role)['id'] for proof, role in
        [('initializer', 'audit-ms4-initialize'), ('driver', 'audit-ms5-payment-migration'),
         ('files-bootstrap', 'audit-bootstrap'), ('customer-prepare', MODES['prepare']['role'])]]
    expect(len(set(historical + [created_facts['id']])) == 5, 'PAID_FIVE_DISTINCT_ORIGINAL_CIDS')
    formal = load(evidence / 'ms5-payment-migration-formal-manifest.json')
    current_formal = load(Path(context['run_dir']) / 'manifest.json') if current_formal is None else current_formal
    for key in ['run_id', 'commit_sha', 'project', 'guard_mode', 'isolation', 'network_internal', 'network', 'volumes', 'volume_identities']:
        expect(paid_equal(formal[key], current_formal[key]), 'PAID_HISTORICAL_CURRENT_FORMAL_IDENTITY')
    expect(set(current_formal['services']) == {PAID['role'], 'audit-mysql', 'audit-redis'}
           and current_formal['services'][PAID['role']]['id'] == created_facts['id'], 'PAID_FORMAL_EXACT_THREE_ROLES')
    for role in ['audit-mysql', 'audit-redis']:
        for key in ['id', 'image_id', 'address']:
            expect(paid_equal(formal['services'][role][key], current_formal['services'][role][key]), 'PAID_ORIGINAL_DB_ENDPOINT')
        address = current_formal['services'][role]['address']
        expect(type(address) is str and re.fullmatch('[0-9]+(?:[.][0-9]+){3}', address)
               and all(0 <= int(part) <= 255 and part == str(int(part)) for part in address.split('.')), 'PAID_REAL_FORMAL_IPV4')
    names = PREREQUISITES + FILES_PREREQUISITES + PREPARE_PREREQUISITES
    expect(len(names) == len(set(names)) == 15, 'EXACT_PAID_FIFTEEN_PREREQUISITES')
    return {name: digest((evidence / name).read_bytes()) for name in names}


def paid_binding(context, cid, formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    return {'schema': 1, 'scope': PAID['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / 'paid-producer-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': next(row['sha256'] for row in frozen['paid_source_files'] if row['path'] == PAID_ENTRY),
        'source_files': frozen['paid_source_files'], 'prerequisites_sha256': paid_prerequisite_hashes(context, formal)}


def record_paid_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    actual, receipt = environment_observation(context, cid, True)
    new(evidence / 'paid-producer-environment-native.json', receipt)
    new(evidence / 'paid-producer-environment.json', {'schema': 1, 'scope': PAID['environment_scope'],
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': cid,
        'freeze_sha256': context['freeze_sha256'], 'environment': actual, 'native_projection_sha256': receipt['stdout_sha256']})
    new(evidence / PAID['binding'], paid_binding(context, cid))


def verify_paid_binding(context, cid, native_required=True, formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    actual = load(evidence / PAID['binding'])
    expect(paid_equal(actual, paid_binding(context, cid, formal)) and len(actual) == 13, 'PAID_EXACT_THIRTEEN_KEY_BINDING')
    environment = load(evidence / 'paid-producer-environment.json')
    receipt = load(evidence / 'paid-producer-environment-native.json')
    expect(paid_equal(environment, {'schema': 1, 'scope': PAID['environment_scope'], 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'container_id': cid, 'freeze_sha256': context['freeze_sha256'],
        'environment': customer_form_environment(context), 'native_projection_sha256': receipt['stdout_sha256']}), 'PAID_EXACT_EIGHT_KEY_REAL_ENVIRONMENT')
    expect(type(receipt) is dict and set(receipt) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'}
        and receipt['stage'] == 'customer-public-environment-inspect' and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] > 0 and paid_hash(receipt['stdout_sha256'])
        and type(receipt['stderr_bytes']) is int and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
        and receipt['private_environment_logs_omitted'] is True and environment_projection_matches(context, receipt, True), 'PAID_REAL_NATIVE_SORTED_SEVEN_ENV_DOUBLE_LF')
    if native_required:
        projected, fresh = environment_observation(context, cid, True)
        expect(paid_equal(projected, environment['environment']) and paid_equal(fresh, receipt), 'PAID_SAME_NATIVE_ENV_BYTES')
    return actual


def verify_paid_helper(context, proof, wanted_status):
    expect((proof, wanted_status) in [('register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('authorize-paid-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')], 'ONLY_PAID_REAL_HELPER_PROOFS')
    evidence = Path(context['run_dir']) / 'evidence'
    before = bound_created(context, proof, 'audit-bootstrap')
    if proof == 'register-paid-after-c3':
        early = bound_created(context, 'register-paid-producer', 'audit-bootstrap')
        paid = bound_created(context, PAID['proof'], PAID['role'])
        c3 = bound_created(context, 'customer-prepare', MODES['prepare']['role'])
        expect(len({before['id'], early['id'], paid['id'], c3['id']}) == 4
            and before['command'] == ['/run/ms4-prep/ms4-initialize.php', 'register'],
            'POST_C3_DISTINCT_ACTUAL_ORIGINAL_REGISTER_HELPER')
    terminal = load(evidence / (proof + '-terminal.json'))
    owned(context, terminal, 'audit-bootstrap'); same_created(before, terminal)
    expect(terminal['status'] == 'exited' and type(terminal['pid']) is int and terminal['pid'] == 0
        and type(terminal['exit_code']) is int and terminal['exit_code'] == 0
        and type(terminal['restart_count']) is int and terminal['restart_count'] == 0, 'PAID_HELPER_REAL_NORMAL_TERMINAL')
    result = load(evidence / (proof + '-result.json'))
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'children', 'private_cleanup',
        'private_cleanup_verified', 'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS5_PAYMENT_PREREQUISITES_ONLY'
        and result['status'] == wanted_status and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
        and type(result['native_exit']) is int and result['native_exit'] == 0 and result['children'] == [] and result['private_cleanup'] == []
        and result['private_cleanup_verified'] is False and all(result[key] == 'NOT RUN' for key in ['App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance']), 'PAID_HELPER_ACTUAL_THIRTEEN_KEY_RESULT')
    binding = result['binding']
    expect(type(binding) is dict and set(binding) == {'container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'}
        and binding['container_id'] == before['id'] and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
        and binding['source_sha'] == context['source_sha'] and binding['freeze_sha256'] == context['freeze_sha256']
        and binding['created_sha256'] == digest((evidence / (proof + '-created.json')).read_bytes()), 'PAID_HELPER_REAL_FIVE_KEY_BINDING')
    shutdown = load(evidence / (proof + '-shutdown.json'))
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'phase', 'fatal', 'type', 'file', 'line', 'primary_failure', 'cleanup_failure', 'memory_limit', 'peak_bytes'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1
        and shutdown['phase'] == ('register' if proof in ['register-paid-producer', 'register-paid-after-c3'] else 'authorize-cleanup')
        and shutdown['fatal'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and type(shutdown['type']) is int and type(shutdown['line']) is int and type(shutdown['file']) is str
        and type(shutdown['memory_limit']) is str and type(shutdown['peak_bytes']) is int and shutdown['peak_bytes'] > 0, 'PAID_HELPER_REAL_NONFATAL_SHUTDOWN')
    return result


def verify_paid_sequence(sequence):
    expect(type(sequence) is dict and set(sequence) == {'scope', 'status', 'epoch_ms', 'source_id_count', 'key_count', 'targets', 'server_samples',
        'monotonic_elapsed_ns', 'complete_database_before_sha256', 'complete_database_after_sha256', 'non_target_redis_sha256',
        'all_non_target_redis_values_and_absolute_expiries_unchanged', 'complete_database_strictly_unchanged', 'original_account_payment_assertions_retained'}, 'PAID_SEQUENCE_EXACT_PUBLIC_SHAPE')
    expect(sequence['scope'] == 'READONLY_REAL_PRODUCER_SEQUENCE_EXPIRY_BARRIER_ONLY'
        and sequence['status'] == 'VERIFIED_SEQUENCE_KEYS_ABSENT_BEFORE_ACCOUNT' and sequence['epoch_ms'] == '1640966400000'
        and type(sequence['source_id_count']) is int and sequence['source_id_count'] == 1
        and type(sequence['key_count']) is int and sequence['key_count'] == 1
        and type(sequence['monotonic_elapsed_ns']) is int and 0 <= sequence['monotonic_elapsed_ns'] <= 10000000000
        and all(sequence[key] is True for key in ['all_non_target_redis_values_and_absolute_expiries_unchanged', 'complete_database_strictly_unchanged', 'original_account_payment_assertions_retained'])
        and all(paid_hash(sequence[key]) for key in ['complete_database_before_sha256', 'complete_database_after_sha256', 'non_target_redis_sha256'])
        and sequence['complete_database_before_sha256'] == sequence['complete_database_after_sha256'], 'PAID_SINGLE_REAL_SEQUENCE_BARRIER')
    samples = sequence['server_samples']
    expect(type(samples) is list and len(samples) >= 3, 'PAID_REAL_SEQUENCE_SAMPLES')
    last_time = -1
    for sample in samples:
        expect(type(sample) is dict and set(sample) == {'time', 'time_ms', 'key_count', 'complete_keys_sha256'}
            and type(sample['time']) is list and len(sample['time']) == 2 and paid_decimal(sample['time'][0])
            and type(sample['time'][1]) is str and re.fullmatch('[0-9]{1,6}', sample['time'][1])
            and int(sample['time'][1]) <= 999999 and paid_decimal(sample['time_ms'])
            and int(sample['time_ms']) == int(sample['time'][0]) * 1000 + int(sample['time'][1]) // 1000
            and int(sample['time_ms']) >= last_time and type(sample['key_count']) is int and sample['key_count'] >= 0
            and paid_hash(sample['complete_keys_sha256']), 'PAID_SEQUENCE_NATIVE_TIME_AND_FULL_KEYS_HASH')
        last_time = int(sample['time_ms'])
    expect(type(sequence['targets']) is list and len(sequence['targets']) == 1, 'PAID_ONE_PRODUCER_DERIVED_SEQUENCE_TARGET')
    target = sequence['targets'][0]
    expect(type(target) is dict and set(target) == {'key_sha256', 'sources', 'initial_observation', 'initial_expires_at_ms', 'initial_value_sha256', 'first_absence_time_ms', 'natural_expiry_observed', 'final_absent'}
        and paid_hash(target['key_sha256']) and type(target['sources']) is list and len(target['sources']) == 1
        and type(target['sources'][0]) is dict and set(target['sources'][0]) == {'source_kind', 'identifier_sha256'}
        and target['sources'][0]['source_kind'] == 'persistent_order' and paid_hash(target['sources'][0]['identifier_sha256'])
        and target['final_absent'] is True and paid_decimal(target['first_absence_time_ms'])
        and int(samples[0]['time_ms']) <= int(target['first_absence_time_ms']) <= last_time, 'PAID_REAL_SINGLE_PERSISTENT_TARGET')
    if target['initial_observation'] == 'ALREADY_ABSENT':
        expect(target['initial_expires_at_ms'] is None and target['initial_value_sha256'] is None and target['natural_expiry_observed'] is False
            and target['first_absence_time_ms'] == samples[0]['time_ms'], 'PAID_SEQUENCE_ACTUALLY_ALREADY_ABSENT')
    else:
        expiry = target['initial_expires_at_ms']
        expect(target['initial_observation'] == 'PRESENT_WITH_OBSERVED_EXPIRY' and type(expiry) is int
            and 0 < expiry - int(samples[0]['time_ms']) <= 1000 and paid_hash(target['initial_value_sha256'])
            and target['natural_expiry_observed'] is True and int(target['first_absence_time_ms']) >= expiry, 'PAID_SEQUENCE_REAL_OBSERVED_NATURAL_EXPIRY')


def verify_paid_result(context, native_required=True, formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    facts = normal_terminal(context, PAID['proof'], PAID['role'], native_required)
    binding = verify_paid_binding(context, facts['id'], native_required, formal)
    verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    verify_paid_helper(context, 'register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    result = load(evidence / PAID['result'])
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'phase', 'entry_return_code',
        'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'child', 'metadata_observer', 'business',
        'customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'full_acceptance', 'stopped_samples', 'assertions', 'customer_form'}, 'PAID_EXACT_TWENTY_SEVEN_RESULT_KEYS')
    expect(type(result['schema']) is int and result['schema'] == 1 and result['scope'] == PAID['result_scope'] and result['status'] == PAID['status']
        and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']) and result['container_id'] == facts['id']
        and result['native_created_sha256'] == digest((evidence / 'paid-producer-created.json').read_bytes())
        and result['source_binding_sha256'] == digest((evidence / PAID['binding']).read_bytes())
        and result['environment_sha256'] == digest((evidence / 'paid-producer-environment.json').read_bytes())
        and result['environment_native_sha256'] == digest((evidence / 'paid-producer-environment-native.json').read_bytes())
        and result['phase'] == 'final_source' and type(result['entry_return_code']) is int and result['entry_return_code'] == 0
        and result['termination'] == 'NORMAL_RETURN' and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and type(result['assertions']) is int and result['assertions'] > 0
        and all(result[key] == 'NOT RUN' for key in ['customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'stopped_samples'])
        and result['full_acceptance'] == 'INCOMPLETE', 'PAID_REAL_ENTRY_AND_DISTINCT_NATIVE_TERMINAL')
    child = result['child']; verify_child_stopped(child)
    expect(set(child) == {'stopped', 'group', 'remaining_members', 'listener_open', 'stop_signal', 'term_attempted', 'native_child', 'native_proc_close', 'normal_only'}
           and all(type(child['native_child'][key]) is bool for key in ['running', 'signaled', 'stopped'])
           and all(type(child['native_child'][key]) is int for key in ['pid', 'exitcode', 'termsig', 'stopsig'])
           and child['native_child']['termsig'] != 9, 'PAID_ACTUAL_IDENTITY_CHILD_NO_SIGKILL')
    observer = result['metadata_observer']
    expect(type(observer) is dict and set(observer) == {'scope', 'before', 'configured'} and observer['scope'] == 'independent_pdo_session_only'
        and type(observer['before']) is list and len(observer['before']) == 1
        and type(observer['configured']) is list and len(observer['configured']) == 1, 'PAID_INDEPENDENT_OBSERVER_REAL_MODES')
    before, configured = observer['before'][0], observer['configured'][0]
    mode_keys = {'session_mode', 'global_mode', 'session_innodb', 'global_innodb', 'session_stats_expiry', 'global_stats_expiry'}
    expect(type(before) is dict and type(configured) is dict and set(before) == set(configured) == mode_keys
        and ((type(configured['session_stats_expiry']) is int and configured['session_stats_expiry'] == 0)
             or (type(configured['session_stats_expiry']) is str and configured['session_stats_expiry'] == '0'))
        and all(paid_equal(before[key], configured[key]) for key in mode_keys - {'session_stats_expiry'}), 'PAID_ONLY_OBSERVER_SESSION_STATS_CHANGED')
    for modes in [before, configured]:
        for key in ['session_stats_expiry', 'global_stats_expiry']:
            value = modes[key]
            expect((type(value) is int and 0 <= value <= 4294967295) or paid_decimal(value, 4294967295), 'PAID_NATIVE_STATS_NOT_NULL_NORMALIZED')
    business = result['business']
    counts = {'orders': 1, 'items': 2, 'reserves': 2, 'invoices': 1, 'payment_attempts': 1, 'http_identity_call_count': 2}
    flags = ['real_credential_login_and_user_get', 'real_persistent_cart_confirm_create', 'actual_saved_g0_before_payment',
        'same_app_factory_driver_connection', 'original_create_descriptor_retained', 'winner_application_root_four_hold_zero_verified',
        'later_second_replay_unchanged', 'full_database_non_targets_and_redis_preserved']
    expect(type(business) is dict and set(business) == set(counts) | set(flags) | {'generation', 'baseline', 'sequence_expiry'}
        and all(type(business[key]) is int and business[key] == value for key, value in counts.items())
        and all(business[key] is True for key in flags) and business['generation'] == '1', 'PAID_ACTUAL_SEVENTEEN_BUSINESS_FIELDS')
    baseline = business['baseline']
    expect(type(baseline) is dict and set(baseline) == {'bytes', 'sha256', 'fact_table_count', 'auxiliary_table_count', 'native_archive_bytes',
        'native_archive_sha256', 'full_database_table_count', 'full_database_redis_sha256', 'original_fixture_unchanged', 'private_native_readback_verified'}
        and type(baseline['bytes']) is int and 0 < baseline['bytes'] <= 2097152 and paid_hash(baseline['sha256'])
        and type(baseline['native_archive_bytes']) is int and 0 < baseline['native_archive_bytes'] <= 1572864
        and paid_hash(baseline['native_archive_sha256']) and paid_hash(baseline['full_database_redis_sha256'])
        and type(baseline['fact_table_count']) is int and baseline['fact_table_count'] == 27
        and type(baseline['auxiliary_table_count']) is int and baseline['auxiliary_table_count'] == 7
        and type(baseline['full_database_table_count']) is int and baseline['full_database_table_count'] >= 34
        and baseline['original_fixture_unchanged'] is True and baseline['private_native_readback_verified'] is True, 'PAID_NATIVE_PRIVATE_BASELINE_SAFE_TEN_FIELDS')
    verify_paid_sequence(business['sequence_expiry'])
    expect(paid_equal(paid_prerequisite_hashes(context, formal), binding['prerequisites_sha256']), 'PAID_FULL_ORIGINAL_FIFTEEN_HASHES_UNCHANGED')
    verify_customer_form_matrix(result['customer_form'], True)
    expect(result['customer_form']['upload_child']['group']['leader_pid'] != result['child']['group']['leader_pid'],
        'CUSTOMER_FORM_DISTINCT_NEW_UPLOAD_CHILD_FROM_ORIGINAL_IDENTITY_CHILD')
    return result


def verify_files_bootstrap(context, native_required=True):
    evidence = Path(context['run_dir']) / 'evidence'
    facts = normal_terminal(context, 'files-bootstrap', 'audit-bootstrap', native_required)
    migration_prerequisites(context)
    result = load(evidence / 'files-bootstrap-result.json'); installation = result['installation']
    expect(result['scope'] == 'MS5_PAYMENT_PREREQUISITES_ONLY' and result['status'] == 'VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY'
           and result['native_exit'] == 0 and result['binding']['container_id'] == facts['id']
           and result['binding']['created_sha256'] == digest((evidence / 'files-bootstrap-created.json').read_bytes())
           and result['binding']['freeze_sha256'] == context['freeze_sha256']
           and result['actual_self_mer_id'] == actual_self(context)
           and result['initializer_result_sha256'] == digest((evidence / 'initializer-result.json').read_bytes())
           and result['migration_result_sha256'] == digest((evidence / 'ms5-payment-migration-normal.json').read_bytes())
           and installation['account'] == 'audit_migrator' and type(installation['checksum_rows']) is int
           and installation['checksum_rows'] > 0 and installation['source_matches'] is True
           and installation['version_marker_matches'] is True and installation['application_account'] == 'audit_app'
           and installation['application_dml_only'] is True, 'ACTUAL_DEDICATED_FILES_BOOTSTRAP')
    shutdown = load(evidence / 'files-bootstrap-shutdown.json')
    expect(shutdown['phase'] == 'files-bootstrap' and shutdown['fatal'] is False
           and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None, 'FILES_BOOTSTRAP_NORMAL_SHUTDOWN')
    return result


# Finite successor constants. Original customer9/paid10/driver10 closures remain intact.
FULFILLMENT_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-normal.php'
FULFILLMENT_ENTRY_BYTES = 128359
FULFILLMENT_ENTRY_SHA256 = '4d38c7ac0d8afbc72a0000319765e155fbd8a116656c43652e54cd824dc992b7'
THREE_READ_RESULT = 'ms4-three-read-normal.json'
THREE_READ_SOURCE_FILES = [
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php', 'bytes': 53328, 'sha256': '9da29b5edf6bc50c8bb483eaf4a67adaaacc6cddc4660e6cac0d2dabcb81a584'},
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php', 'bytes': 51479, 'sha256': '7ad23450261f2771a46e8c04e7c2f8da0941d476d887f30064150188886ea941'},
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-read-normal.php', 'bytes': 160668, 'sha256': '508c84e75b1b07bc6e574b461a069def27cedfb51378850923213391aa3bb01d'}
]
FULFILLMENT_MIGRATION_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-migration.php'
FULFILLMENT_CLI = 'help/migrations/marketplace-ms4-fulfillment.php'
FULFILLMENT = {'proof': 'fulfillment-normal', 'role': 'audit-ms4-fulfillment-normal',
    'binding': 'ms4-fulfillment-normal-source-binding.json', 'scope': 'MS4_FULFILLMENT_NORMAL_SOURCE',
    'result': 'ms4-fulfillment-normal.json', 'result_scope': 'NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY',
    'status': 'VERIFIED_NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY', 'environment_scope': 'MS4_FULFILLMENT_NORMAL_REAL_ENVIRONMENT'}
FULFILLMENT_MIGRATION = {'proof': 'fulfillment-migration', 'role': 'audit-ms4-fulfillment-migration'}
PAID_PUBLIC_SIX = ['paid-producer-created.json', 'paid-producer-terminal.json', 'paid-producer-result.json',
    'paid-producer-source-binding.json', 'paid-producer-environment.json', 'paid-producer-environment-native.json']
FULFILLMENT_MIGRATION_FOUR = ['ms4-fulfillment-migration-container-created.json',
    'ms4-fulfillment-migration-source-binding.json', 'ms4-aftersale-early-migration-normal.json', 'ms4-fulfillment-migration-terminal.json']


AFTERSALE_REVIEW_ENTRY = 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php'
AFTERSALE_REVIEW_ENTRY_BYTES = 239863
AFTERSALE_REVIEW_ENTRY_SHA256 = '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'
AFTERSALE_REVIEW = {'proof': 'aftersale-review-normal', 'role': 'audit-ms4-aftersale-review-normal',
    'binding': 'ms4-aftersale-review-normal-source-binding.json', 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_SOURCE',
    'result': 'ms4-aftersale-review-normal.json', 'result_scope': 'NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY',
    'status': 'VERIFIED_NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY', 'environment_scope': 'MS4_AFTERSALE_REVIEW_NORMAL_REAL_ENVIRONMENT'}
AFTERSALE_PRIVATE_ROOT = '/var/lib/crmeb-aftersale-evidence'

ASSET_CLI = 'help/migrations/marketplace-ms4-assets.php'
ASSET_SCHEMA = 'crmeb/app/services/marketplace/assets/AssetSchema.php'
EARLY_ASSET_COLUMNS = {'eb_marketplace_customer_asset': ['id', 'uid', 'purpose', 'state', 'storage_type', 'object_key', 'thumbnail_key', 'name', 'real_name', 'media_type', 'byte_size', 'width', 'height', 'content_sha256', 'created_at', 'expires_at', 'bound_at', 'retired_at', 'root_order_id', 'order_item_id', 'refund_id', 'review_id', 'confirmation_ref', 'form_product_id', 'form_definition_sha256', 'form_field_index', 'extract_id', 'agent_apply_id', 'source_product_id', 'source_qrcode_id', 'created_by_uid', 'created_by_admin_id', 'created_by_job', 'legacy_att_id'], 'eb_marketplace_platform_asset_category': ['id', 'pid', 'name', 'enname', 'type', 'created_by_admin_id', 'updated_by_admin_id', 'created_at', 'updated_at', 'legacy_category_id'], 'eb_marketplace_platform_asset': ['id', 'purpose', 'category_id', 'state', 'storage_type', 'object_key', 'thumbnail_key', 'name', 'real_name', 'media_type', 'byte_size', 'width', 'height', 'content_sha256', 'created_at', 'expires_at', 'bound_at', 'retired_at', 'created_by_admin_id', 'created_by_service_id', 'created_by_uid', 'created_by_job', 'chat_customer_uid', 'chat_is_tourist', 'chat_service_id', 'chat_service_uid', 'channel_qrcode_id', 'routine_version', 'legacy_att_id']}
EARLY_SCRIPTS = {'aftersale': 'help/migrations/marketplace-ms4-aftersale.php',
    'aftersale_menus': 'help/migrations/marketplace-ms4-aftersale-menus.php',
    'review_menus': 'help/migrations/marketplace-ms4-review-menus.php',
    'assets': ASSET_CLI}
EARLY_REFUND_COLUMNS = ['marketplace_root_order_id', 'marketplace_payment_attempt_id', 'marketplace_kind', 'marketplace_state',
    'marketplace_physical_state', 'marketplace_finance_state', 'marketplace_apply_action_id', 'marketplace_last_action_id']
EARLY_AFTERSALE_STEPS = ['create.marketplace_aftersale_action', 'refund.column.marketplace_root_order_id',
    'refund.column.marketplace_payment_attempt_id', 'refund.column.marketplace_kind', 'refund.column.marketplace_state',
    'refund.column.marketplace_physical_state', 'refund.column.marketplace_finance_state', 'refund.column.marketplace_apply_action_id',
    'refund.column.marketplace_last_action_id', 'refund.index.idx_ms4a_refund_root', 'refund.index.uq_ms4a_refund_apply',
    'refund.index.idx_ms4a_refund_last', 'refund.foreign.mm4a_refund_apply_fk', 'refund.foreign.mm4a_refund_last_fk',
    'refund.check.mm4a_refund_v1', 'operation.column.aftersale_cut_id', 'operation.index.idx_fop_aftersale_cut',
    'operation.foreign.mm4a_fop_cut_fk', 'operation.checks.replace_known_ids_and_add_cut', 'create.marketplace_aftersale_file']
EARLY_MENU_AUTHS = {'aftersale_menus': ['marketplace-aftersales-list', 'marketplace-aftersales-read',
    'marketplace-aftersales-approve', 'marketplace-aftersales-reject', 'marketplace-aftersales-return-accept',
    'marketplace-aftersale-files-ticket', 'marketplace-aftersale-files-read'],
    'review_menus': ['marketplace-reviews-list', 'marketplace-reviews-read', 'marketplace-reviews-moderate']}


WORKER_ENTRY = 'help/audit-regressions/isolated/ms4-worker-normal.php'
WORKER_ENTRY_BYTES = 127354
WORKER_ENTRY_SHA256 = '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8'
WORKER = {'proof': 'worker-normal', 'role': 'audit-ms4-worker-normal',
    'binding': 'ms4-worker-normal-source-binding.json', 'scope': 'MS4_WORKER_NORMAL_SOURCE',
    'result': 'ms4-worker-normal.json', 'result_scope': 'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
    'status': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'environment_scope': 'MS4_WORKER_NORMAL_REAL_ENVIRONMENT'}


def worker_extra():
    values = sorted(aftersale_review_extra() + [WORKER_ENTRY])
    expect(len(values) == len(set(values)) == 31, 'WORKER_ORIGINAL_THIRTY_PLUS_ONE_ENTRY')
    return values


def worker_paths(repo):
    values = sorted(aftersale_review_paths(repo) + [WORKER_ENTRY])
    expect(len(values) == len(set(values)) and all((Path(repo) / path).is_file()
        and not (Path(repo) / path).is_symlink() for path in values), 'WORKER_COMPLETE_REAL_FOUR_ROOTS_THIRTY_EXTRAS')
    return values


def aftersale_review_extra():
    paths = sorted(set(fulfillment_extra() + list(EARLY_SCRIPTS.values()) + [AFTERSALE_REVIEW_ENTRY]))
    expect(len(paths) == 30, 'ONLY_ORIGINAL_FULFILLMENT_WITH_ASSETS_PLUS_THREE_CLI_AND_NEW_ENTRY')
    return paths


def aftersale_review_paths(repo):
    paths = sorted(set(customer_paths(repo) + aftersale_review_extra()))
    expect(all((Path(repo) / path).is_file() and not (Path(repo) / path).is_symlink() for path in paths),
        'COMPLETE_FOUR_SOURCE_ROOTS_AND_TWENTY_NINE_EXTRAS')
    return paths

def fulfillment_extra():
    values = sorted(set(CUSTOMER_EXTRA + DRIVER + [PAID_ENTRY, FULFILLMENT_ENTRY, FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI, ASSET_CLI]
                        + [row['path'] for row in THREE_READ_SOURCE_FILES]))
    expect(len(values) == 26, 'EXACT_FULFILLMENT_TWENTY_SIX_EXTRAS')
    return values


def fulfillment_paths(repo):
    values = sorted(set(customer_paths(repo) + fulfillment_extra()))
    expect(all(not (Path(repo) / path).is_symlink() and (Path(repo) / path).is_file() for path in values), 'FULFILLMENT_REAL_COMPLETE_SOURCE_FILES')
    return values


def fulfillment_migration_paths():
    return sorted(set(DRIVER) | {FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI, ASSET_SCHEMA, *EARLY_SCRIPTS.values()})


def exact_copy(source, destination):
    source, destination = Path(source), Path(destination)
    expect(source.is_file() and not source.is_symlink() and destination.parent.is_dir()
           and not destination.exists() and not destination.is_symlink(), 'CREATE_ONLY_PUBLIC_ORIGINAL_COPY')
    raw = source.read_bytes()
    with destination.open('xb') as stream:
        expect(stream.write(raw) == len(raw), 'COMPLETE_ORIGINAL_PUBLIC_COPY')
        stream.flush()
    expect(destination.read_bytes() == raw and source.read_bytes() == raw, 'COMPLETE_PUBLIC_COPY_AND_SOURCE_READBACK')
    return digest(raw)


def paid_history_names():
    values = PREREQUISITES + FILES_PREREQUISITES + PREPARE_PREREQUISITES + [
        'ms4-customer-prepare-source-binding.json', 'customer-prepare-environment.json', 'customer-prepare-environment-native.json'] + PAID_PUBLIC_SIX
    values += ['register-paid-producer' + suffix for suffix in ['-created.json', '-terminal.json', '-result.json', '-shutdown.json']]
    values += ['register-paid-after-c3' + suffix for suffix in ['-created.json', '-terminal.json', '-result.json', '-shutdown.json']]
    values += ['driver-created.json']
    expect(len(values) == len(set(values)) == 33, 'EXACT_THIRTY_THREE_PAID_HISTORY_PUBLIC_ORIGINALS')
    return values


def paid_history_hashes(context):
    evidence = Path(context['run_dir']) / 'evidence'
    result = {}
    for name in paid_history_names():
        path = evidence / name
        expect(path.is_file() and not path.is_symlink(), 'ORIGINAL_PAID_HISTORY_PUBLIC_REGULAR')
        result[name] = digest(path.read_bytes())
    return result


def seal_paid_predecessor(context):
    evidence = Path(context['run_dir']) / 'evidence'
    collect(context, proof_expected(context, PAID['proof']), publish=False)
    verify_paid_result(context, True)
    original = Path(context['run_dir']) / 'manifest.json'
    before = original.read_bytes()
    hashes = paid_history_hashes(context)
    exact_copy(original, evidence / 'paid-producer-formal-manifest.json')
    value = {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'freeze_sha256': context['freeze_sha256'], 'context_sha256': digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes()),
        'manifest_sha256': digest(before), 'public_sha256': hashes}
    new(evidence / 'paid-producer-predecessor-history.json', value)
    expect(original.read_bytes() == before and paid_history_hashes(context) == hashes, 'ORIGINAL_THREE_ROLE_PAID_SEAL_UNCHANGED')
    verify_paid_history(context, True)
    return value


def verify_paid_history(context, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    history = load(evidence / 'paid-producer-predecessor-history.json')
    formal = load(evidence / 'paid-producer-formal-manifest.json')
    expect(type(history) is dict and set(history) == {'schema', 'run_id', 'source_sha', 'freeze_sha256', 'context_sha256', 'manifest_sha256', 'public_sha256'}
        and type(history['schema']) is int and history['schema'] == 1
        and all(history[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and history['context_sha256'] == digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes())
        and history['manifest_sha256'] == digest((evidence / 'paid-producer-formal-manifest.json').read_bytes())
        and paid_equal(history['public_sha256'], paid_history_hashes(context)), 'FULL_ORIGINAL_PAID_HISTORY_SEAL')
    # The explicit real saved formal is the sole seam; load/current manifest are never replaced.
    result = verify_paid_result(context, native_required, formal)
    expect(paid_equal(history['public_sha256'], paid_history_hashes(context)), 'PAID_HISTORY_FULL_ORIGINALS_UNCHANGED_AFTER_SEMANTIC_VERIFICATION')
    return result


def fulfillment_expected_formal(context, formal, role, proof):
    evidence = Path(context['run_dir']) / 'evidence'
    original = load(evidence / 'paid-producer-formal-manifest.json')
    expected = {**{service: row['id'] for service, row in original['services'].items()},
                role: bound_created(context, proof, role)['id']}
    expect(type(formal) is dict and set(formal['services']) == set(expected)
        and all(paid_equal(formal[key], original[key]) for key in ['run_id', 'project', 'commit_sha', 'isolation', 'guard_mode', 'network_internal', 'network', 'volumes', 'volume_identities'])
        and all(paid_equal(formal['services'][service], row) for service, row in original['services'].items())
        and all(type(row) is dict and set(row) == {'id', 'address', 'image_id'} and row['id'] == expected[service]
                for service, row in formal['services'].items())
        and formal['services'][role]['image_id'] == PHP, 'REAL_SAME_PAID_AND_DATABASE_FOUR_ROLE_FORMAL')
    return formal


def verify_fulfillment_helper(context, proof, wanted_status):
    phase = 'authorize-cleanup' if proof == 'authorize-fulfillment-cleanup' else 'register'
    expect((proof, wanted_status) in [('register-fulfillment-migration', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('register-fulfillment-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('authorize-fulfillment-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')], 'ONLY_REAL_FULFILLMENT_HELPERS')
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, proof, 'audit-bootstrap')
    normal_terminal(context, proof, 'audit-bootstrap', False)
    result = load(evidence / (proof + '-result.json'))
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'children', 'private_cleanup',
        'private_cleanup_verified', 'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_FULFILLMENT_LIFECYCLE_ONLY'
        and result['status'] == wanted_status and type(result['native_exit']) is int and result['native_exit'] == 0
        and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
        and result['children'] == [] and result['private_cleanup'] == [] and result['private_cleanup_verified'] is False
        and all(result[key] == 'NOT RUN' for key in ['App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance']), 'REAL_FULFILLMENT_HELPER_COMPLETE_RESULT')
    expect(created_facts['command'] == ['/run/ms4-prep/ms4-initialize.php', phase], 'REAL_FULFILLMENT_HELPER_COMMAND')
    binding = result['binding']
    expect(type(binding) is dict and set(binding) == {'container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'}
        and binding['container_id'] == created_facts['id'] and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
        and binding['source_sha'] == context['source_sha'] and binding['freeze_sha256'] == context['freeze_sha256']
        and binding['created_sha256'] == digest((evidence / (proof + '-created.json')).read_bytes()), 'FULFILLMENT_HELPER_FULL_FIVE_KEY_SOURCE_BINDING')
    shutdown = load(evidence / (proof + '-shutdown.json'))
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'phase', 'fatal', 'type', 'file', 'line', 'primary_failure', 'cleanup_failure', 'memory_limit', 'peak_bytes'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['phase'] == phase and shutdown['fatal'] is False
        and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None and type(shutdown['type']) is int
        and type(shutdown['line']) is int and type(shutdown['file']) is str and type(shutdown['memory_limit']) is str
        and type(shutdown['peak_bytes']) is int and shutdown['peak_bytes'] > 0, 'FULFILLMENT_HELPER_NONFATAL_SHUTDOWN')
    return result


def early_initialization_hashes(context):
    evidence = Path(context['run_dir']) / 'evidence'
    return {name: digest((evidence / name).read_bytes()) for name in PREREQUISITES}


def early_child_receipt(child, mode, index, script, script_sha, prerequisites, kind):
    keys = {'mode', 'pid', 'native_exit', 'proc_close_exit', 'same_handle_terminal', 'capture_error_class',
            'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'result'}
    if kind != 'fulfillment':
        keys |= {'script', 'script_sha256'}
        expect(child.get('script') == script and child.get('script_sha256') == script_sha, 'EARLY_ACTUAL_SUCCESSOR_SCRIPT_SOURCE')
    expect(type(child) is dict and set(child) == keys and child['mode'] == mode, 'EARLY_COMPLETE_NATIVE_CHILD_RECEIPT')
    normal_child(child)
    value = child['result']
    base_keys = {'mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known',
                 'issues', 'failure_phase', 'marker', 'source_sha256', 'scope', 'prerequisite_source_sha256', 'writer', 'acceptance'}
    if kind in ['fulfillment', 'aftersale']:
        steps = (['marketplace_fulfillment_operation', 'marketplace_fulfillment_range'] if kind == 'fulfillment' else EARLY_AFTERSALE_STEPS)
        expect(type(value) is dict and set(value) == (base_keys if kind == 'fulfillment' else base_keys | {'structure_stage', 'structure_step_count'})
            and value['mode'] == mode and type(value['native_exit']) is int and value['native_exit'] == 0
            and value['state'] == ('applicable' if index == 0 else 'completed') and value['changed'] is (index == 1)
            and value['applied_steps'] == (steps if index == 1 else []) and value['pending_steps'] == (steps if index == 0 else [])
            and value['pending_steps_known'] is True and value['issues'] == [] and value['failure_phase'] is None
            and value['source_sha256'] == script_sha and paid_equal(value['prerequisite_source_sha256'], prerequisites)
            and value['marker'] == ('marketplace-ms4-fulfillment-v1' if kind == 'fulfillment' else 'marketplace-ms4-aftersale-v1')
            and value['scope'] == ('MS4_FULFILLMENT_STRUCTURE_ONLY' if kind == 'fulfillment' else 'MS4_AFTERSALE_STRUCTURE_ONLY')
            and value['writer'] == value['acceptance'] == 'NOT RUN', 'EARLY_EXACT_ORIGINAL_FOUR_OR_SUCCESSOR_FOUR_STRUCTURE_RESULTS')
        if kind == 'aftersale':
            expect(type(value['structure_stage']) is int and value['structure_stage'] == (0 if index == 0 else 20)
                and type(value['structure_step_count']) is int and value['structure_step_count'] == 20, 'EARLY_ACTUAL_STAGE_ZERO_TO_TWENTY')
    elif kind == 'assets':
        asset_keys = {'mode', 'state', 'native_exit', 'changed', 'change_observation', 'attempted_steps', 'applied_steps',
            'ddl_outcome_uncertain', 'pending_steps', 'pending_steps_known', 'observed_tables', 'structure_step_count', 'issues',
            'failure_phase', 'lock_release_failed', 'lock_scope', 'source_sha256', 'schema_source_sha256', 'sources_held',
            'schema_contract', 'scope', 'history_mapping', 'writer', 'activation', 'acceptance'}
        suffixes = [name[3:] for name in EARLY_ASSET_COLUMNS]
        steps = ['create.' + suffix for suffix in suffixes]
        lock = {'request_attempted': False, 'execute_attempted': False, 'preexisting_same_session': None,
            'acquisition': 'NOT_ATTEMPTED', 'request_cursor_closed': None, 'confirmation_failed': False,
            'release_attempted': False, 'release_execute_attempted': False, 'release_result': 'NOT_ATTEMPTED',
            'release_cursor_closed': None, 'ownership': 'NOT_CHECKED', 'resolved': None}
        if mode == 'apply-structure':
            lock.update(request_attempted=True, execute_attempted=True, preexisting_same_session=False, acquisition='ONE',
                request_cursor_closed=True, release_attempted=True, release_execute_attempted=True, release_result='ONE',
                release_cursor_closed=True, ownership='NOT_OWNED', resolved=True)
        expect(type(value) is dict and set(value) == asset_keys and value['mode'] == mode
            and type(value['native_exit']) is int and value['native_exit'] == 0
            and value['state'] == ('applicable' if index == 0 else 'completed') and value['changed'] is (index == 1)
            and value['change_observation'] == ('DDL_MAY_HAVE_COMMITTED' if index == 1 else 'DDL_NOT_ATTEMPTED')
            and value['attempted_steps'] == (steps if index == 1 else []) and value['applied_steps'] == (steps if index == 1 else [])
            and value['ddl_outcome_uncertain'] is False and value['pending_steps'] == (steps if index == 0 else [])
            and value['pending_steps_known'] is True and value['observed_tables'] == {suffix: 'absent' if index == 0 else 'compatible' for suffix in suffixes}
            and type(value['structure_step_count']) is int and value['structure_step_count'] == 3
            and value['issues'] == [] and value['failure_phase'] is None and value['lock_release_failed'] is False
            and paid_equal(value['lock_scope'], lock) and value['source_sha256'] == script_sha
            and value['schema_source_sha256'] == prerequisites[ASSET_SCHEMA] and value['sources_held'] is True
            and value['schema_contract'] == 'marketplace-asset-domains-v1' and value['scope'] == 'MS4_ASSET_DOMAINS_STRUCTURE_ONLY'
            and all(value[key] == 'NOT RUN' for key in ['history_mapping', 'writer', 'activation', 'acceptance']),
            'EARLY_COMPLETE_TWENTY_FIVE_KEY_ASSET_RESULT_AND_REAL_NORMAL_LOCK_LIFECYCLE')
    else:
        menu_keys = {'mode', 'state', 'changed', 'tables', 'menu_ids', 'issues', 'source_binding', 'authorization_cache_producer', 'roles_modified'}
        expect(type(value) is dict and set(value) == (menu_keys if mode == 'check' else menu_keys | {'created_menu_ids'})
            and value['mode'] == mode and value['state'] == ('applicable' if index == 0 else 'completed')
            and value['changed'] is (index == 1) and value['tables'] == [] and value['issues'] == []
            and value['roles_modified'] is False
            and value['authorization_cache_producer'] == 'fresh_scope_for_request_exact_all_auth_and_current_roles'
            and value['source_binding'] == {'entry_sha256': script_sha, 'ms1_sha256': prerequisites['help/migrations/marketplace-ms1.php']},
            'EARLY_COMPLETE_MENU_RESULT_NO_ROLE_GRANT')
        if index == 0:
            expect(value['menu_ids'] == [], 'EARLY_INITIAL_ALL_MENU_LEAVES_ABSENT')
    raw = (json.dumps(value, ensure_ascii=False, separators=(',', ':')) + '\n').encode('utf-8')
    expect(child['stdout_bytes'] == len(raw) and child['stdout_sha256'] == digest(raw), 'EARLY_COMPLETE_BOUNDED_CLI_STDOUT_RECONSTRUCTION')
    return value


def fulfillment_migration_result(context, created_facts, terminal, result):
    owned(context, terminal, FULFILLMENT_MIGRATION['role']); same_created(created_facts, terminal)
    root = Path(context['run_dir']); evidence = root / 'evidence'
    binding_path = evidence / FULFILLMENT_MIGRATION_FOUR[1]
    created_path = evidence / FULFILLMENT_MIGRATION_FOUR[0]
    binding = load(binding_path); rows = [source_row(context['repo_dir'], path) for path in fulfillment_migration_paths()]
    hashes = {row['path']: row['sha256'] for row in rows}
    expected_binding = {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'requested_repo_source': context['repo_dir'], 'requested_run_source': context['run_dir'],
        'entry_sha256': hashes[FULFILLMENT_MIGRATION_ENTRY], 'cli_sha256': hashes[FULFILLMENT_CLI], 'source_files': rows,
        'initialization_predecessor_sha256': early_initialization_hashes(context),
        'successor_cli_sha256': {path: hashes[path] for path in EARLY_SCRIPTS.values()}}
    expect(len(rows) == 17 and paid_equal(binding, expected_binding) and load(created_path) == created_facts,
        'EARLY_EXACT_TEN_KEY_SEVENTEEN_SOURCE_BINDING')
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'binding', 'children', 'successor_children',
        'snapshots', 'comparisons', 'phase', 'termination', 'entry_return_code', 'native_exit', 'native_exit_observation',
        'private_cleanup_verified', 'private_cleanup_state', 'actual_command_counts', 'original_table_count', 'original_rows',
        'independent_new_metadata_verified', 'menu_leaves', 'full_source_unchanged', 'prerequisites_verified', 'stopped_cases',
        'business_acceptance', 'diagnostic', 'cleanup_failure', 'publication_failure'}, 'EARLY_EXACT_TWENTY_SEVEN_PARENT_KEYS')
    expect(terminal['status'] == 'exited' and terminal['pid'] == 0 and terminal['exit_code'] == 0 and terminal['restart_count'] == 0
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY'
        and result['status'] == 'VERIFIED_MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY' and result['phase'] == 'final_binding'
        and type(result['entry_return_code']) is int and result['entry_return_code'] == 0 and result['termination'] == 'NORMAL_RETURN'
        and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['private_cleanup_verified'] is True and result['private_cleanup_state'] == 'OWN_FILE_REMOVED'
        and result['independent_new_metadata_verified'] is True and result['full_source_unchanged'] is True
        and result['prerequisites_verified'] is True and result['stopped_cases'] == {'denial_conflict_concurrency_fault_restore': 'NOT RUN'}
        and result['business_acceptance'] == 'NOT RUN'
        and all(result[key] is None for key in ['diagnostic', 'cleanup_failure', 'publication_failure']),
        'EARLY_SAME_NATIVE_OUTER_ZERO_HONEST_NULL_PARENT_AND_STRUCTURE_SCOPE')
    prerequisites = {path: hashes[path] for path in ['help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms5-payment.php']}
    actual = result['binding']
    expect(type(actual) is dict and set(actual) == {'run_id', 'container_id', 'entry_namespace_pid', 'source_sha', 'entry_sha256',
        'cli_sha256', 'source_files', 'binding_sha256', 'native_created_sha256', 'freeze_sha256', 'initialization_predecessor_sha256',
        'prerequisite_source_sha256', 'successor_cli_sha256'} and type(actual['entry_namespace_pid']) is int and actual['entry_namespace_pid'] > 0
        and actual['run_id'] == context['run_id'] and actual['container_id'] == created_facts['id'] and actual['source_sha'] == context['source_sha']
        and type(actual['source_files']) is int and actual['source_files'] == 17
        and actual['binding_sha256'] == digest(binding_path.read_bytes()) and actual['native_created_sha256'] == digest(created_path.read_bytes())
        and actual['entry_sha256'] == binding['entry_sha256'] and actual['cli_sha256'] == binding['cli_sha256']
        and actual['freeze_sha256'] == context['freeze_sha256']
        and paid_equal(actual['initialization_predecessor_sha256'], binding['initialization_predecessor_sha256'])
        and paid_equal(actual['prerequisite_source_sha256'], prerequisites)
        and paid_equal(actual['successor_cli_sha256'], binding['successor_cli_sha256']), 'EARLY_COMPLETE_THIRTEEN_KEY_RESULT_BINDING')
    counts = {'fulfillment': {'check': 2, 'apply-structure': 2}, 'aftersale': {'check': 2, 'apply-structure': 2},
              'aftersale_menus': {'check': 2, 'apply': 2}, 'review_menus': {'check': 2, 'apply': 2}, 'assets': {'check': 2, 'apply-structure': 2}}
    expect(paid_equal(result['actual_command_counts'], counts) and type(result['children']) is list and len(result['children']) == 4
        and type(result['successor_children']) is list and len(result['successor_children']) == 16, 'EARLY_REAL_FOUR_PLUS_SIXTEEN_COMMANDS')
    for index, mode in enumerate(['check', 'apply-structure', 'check', 'apply-structure']):
        early_child_receipt(result['children'][index], mode, index, FULFILLMENT_CLI, binding['cli_sha256'], prerequisites, 'fulfillment')
    offset = 0; menu_maps = {}
    aftersale_prerequisites = {path: hashes[path] for path in ['help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms5-payment.php', FULFILLMENT_CLI, 'crmeb/public/install/crmeb.sql']}
    for kind, script in EARLY_SCRIPTS.items():
        modes = ['check', 'apply-structure', 'check', 'apply-structure'] if kind in ['aftersale', 'assets'] else ['check', 'apply', 'check', 'apply']
        values = [early_child_receipt(result['successor_children'][offset + index], mode, index, script, hashes[script], {ASSET_SCHEMA: hashes[ASSET_SCHEMA]} if kind == 'assets' else aftersale_prerequisites, kind)
                  for index, mode in enumerate(modes)]
        offset += 4
        if kind in EARLY_MENU_AUTHS:
            actual_ids = values[1]['menu_ids']; expected_auth = EARLY_MENU_AUTHS[kind]
            expect(type(actual_ids) is dict and list(actual_ids) == expected_auth and len(actual_ids) == (7 if kind == 'aftersale_menus' else 3)
                and all(type(value) is int and 1 <= value <= 65535 for value in actual_ids.values())
                and len(set(actual_ids.values())) == len(actual_ids) and values[1]['created_menu_ids'] == actual_ids
                and values[2]['menu_ids'] == actual_ids and values[3]['menu_ids'] == actual_ids and values[3]['created_menu_ids'] == [],
                'EARLY_ACTUAL_SEVEN_AND_THREE_NATURAL_MENU_PK_MAPS')
            menu_maps[kind] = actual_ids
    expect(paid_equal(result['menu_leaves'], menu_maps), 'EARLY_PARENT_EXACT_OBSERVED_TEN_MENU_LEAVES')
    snapshots = result['snapshots']; stages = ['before', 'fulfillment', 'aftersale', 'aftersale_menus', 'review_menus', 'assets']
    expect(type(snapshots) is dict and list(snapshots) == stages, 'EARLY_SIX_ACTUAL_SNAPSHOT_STAGES')
    for snapshot in snapshots.values():
        expect(type(snapshot) is dict and set(snapshot) == {'names', 'tables', 'redis', 'modes', 'metadata_observer_modes', 'native_snapshot_sha256'}
            and type(snapshot['names']) is list and snapshot['names'] == sorted(set(snapshot['names']))
            and list(snapshot['tables']) == snapshot['names'] and paid_hash(snapshot['native_snapshot_sha256']), 'EARLY_COMPLETE_SNAPSHOT_NAMES_AND_NATIVE_DIGEST')
        for table in snapshot['tables'].values():
            expect(type(table) is dict and set(table) == {'ddl_sha256', 'projected_columns', 'projection', 'metadata_sha256', 'metadata_serialized_base64'}
                and paid_hash(table['ddl_sha256']) and paid_hash(table['metadata_sha256']), 'EARLY_COMPLETE_PUBLIC_TABLE_NATIVE_METADATA')
            raw = base64.b64decode(table['metadata_serialized_base64'], validate=True)
            expect(digest(raw) == table['metadata_sha256'] and base64.b64encode(raw).decode() == table['metadata_serialized_base64'], 'EARLY_ORIGINAL_BINARY_SAFE_METADATA_BYTES')
            projection = table['projection']
            expect(type(projection) is dict and set(projection) == {'encoding', 'rows', 'sha256'}
                and projection['encoding'] == 'php-serialize-row-sha256-sorted-multiset-v1'
                and type(projection['rows']) is int and projection['rows'] >= 0 and paid_hash(projection['sha256']), 'EARLY_COMPLETE_ORIGINAL_ROW_MULTISET')
    before, fulfillment, aftersale, menu1, menu2, final = (snapshots[name] for name in stages)
    expect(type(result['original_table_count']) is int and result['original_table_count'] == len(before['names'])
        and set(result['original_rows']) == set(before['names'])
        and all(result['original_rows'][name] == table['projection']['rows'] for name, table in before['tables'].items()), 'EARLY_ALL_ORIGINAL_TABLE_COUNTS')
    added = {'eb_marketplace_fulfillment_operation', 'eb_marketplace_fulfillment_range'}
    expect(set(fulfillment['names']) == set(before['names']) | added and not added.intersection(before['names'])
        and all(fulfillment['tables'][name] == table for name, table in before['tables'].items()), 'EARLY_ORIGINAL_FOUR_FULL_PRESERVATION')
    new_aftersale = {'eb_marketplace_aftersale_action', 'eb_marketplace_aftersale_file'}
    expect(set(aftersale['names']) == set(fulfillment['names']) | new_aftersale and not new_aftersale.intersection(fulfillment['names']), 'EARLY_ONLY_TWO_NEW_AFTERSALE_TABLES')
    for name, table in fulfillment['tables'].items():
        if name not in ['eb_store_order_refund', 'eb_marketplace_fulfillment_operation']:
            expect(aftersale['tables'][name] == table, 'EARLY_ALL_NON_TARGET_METADATA_DDL_ROWS_UNCHANGED')
        else:
            columns = table['projected_columns']; actual_table = aftersale['tables'][name]
            additions = EARLY_REFUND_COLUMNS if name == 'eb_store_order_refund' else ['aftersale_cut_id']
            expect(actual_table['projected_columns'] == columns + additions and actual_table['projection']['rows'] == table['projection']['rows'],
                'EARLY_ONLY_DECLARED_NULLABLE_COLUMN_APPEND')
    for name, width in {'eb_marketplace_fulfillment_operation': 24, 'eb_marketplace_fulfillment_range': 30}.items():
        expect(len(fulfillment['tables'][name]['projected_columns']) == width and fulfillment['tables'][name]['projection']['rows'] == 0, 'EARLY_ORIGINAL_TWENTY_FOUR_COLUMN_TIMEPOINT')
    for snapshot in [aftersale, menu1, menu2, final]:
        for name, width in {'eb_marketplace_aftersale_action': 21, 'eb_marketplace_aftersale_file': 18,
                            'eb_store_order_refund': 34, 'eb_marketplace_fulfillment_operation': 25, 'eb_marketplace_fulfillment_range': 30}.items():
            expect(len(snapshot['tables'][name]['projected_columns']) == width, 'EARLY_FINAL_STAGE20_COMPLETE_COLUMN_WIDTHS')
            if name != 'eb_store_order_refund':
                expect(snapshot['tables'][name]['projection']['rows'] == 0 and snapshot['tables'][name]['projection']['sha256'] == digest(b''), 'EARLY_FOUR_REAL_EMPTY_TABLES')
    for old, new_snapshot, count in [(aftersale, menu1, 7), (menu1, menu2, 3)]:
        expect(new_snapshot['names'] == old['names'] and all(new_snapshot['tables'][name] == table
            for name, table in old['tables'].items() if name != 'eb_system_menus')
            and new_snapshot['tables']['eb_system_menus']['projected_columns'] == old['tables']['eb_system_menus']['projected_columns']
            and len(new_snapshot['tables']['eb_system_menus']['projected_columns']) == 22
            and new_snapshot['tables']['eb_system_menus']['projection']['rows'] == old['tables']['eb_system_menus']['projection']['rows'] + count,
            'EARLY_ONLY_ACTUAL_SEVEN_THREE_MENU_INSERTS')
    asset_names = set(EARLY_ASSET_COLUMNS)
    expect(not asset_names.intersection(menu2['names']) and set(final['names']) == set(menu2['names']) | asset_names
        and all(final['tables'][name] == table for name, table in menu2['tables'].items()),
        'EARLY_ONLY_THREE_ASSET_TABLES_AND_ALL_OLD_NATIVE_TABLES_PRESERVED')
    for name, columns in EARLY_ASSET_COLUMNS.items():
        table = final['tables'][name]
        expect(table['projected_columns'] == columns and table['projection']['rows'] == 0
            and table['projection']['sha256'] == digest(b''), 'EARLY_THREE_COMPLETE_ASSET_COLUMNS_AND_GENUINELY_EMPTY_ROWS')
    expect(all(snapshot['modes'] == before['modes'] and snapshot['metadata_observer_modes'] == before['metadata_observer_modes']
        and snapshot['redis'] == before['redis'] for snapshot in snapshots.values()), 'EARLY_ALL_SIX_MODES_AND_ABSOLUTE_REDIS_UNCHANGED')
    comparisons = [('check_first', 'before'), ('check_repeat', 'fulfillment'), ('apply-structure_repeat', 'fulfillment'),
        ('aftersale_check_first', 'fulfillment'), ('aftersale_check_repeat', 'aftersale'), ('aftersale_apply-structure_repeat', 'aftersale'),
        ('aftersale_menus_check_first', 'aftersale'), ('aftersale_menus_check_repeat', 'aftersale_menus'), ('aftersale_menus_apply_repeat', 'aftersale_menus'),
        ('review_menus_check_first', 'aftersale_menus'), ('review_menus_check_repeat', 'review_menus'), ('review_menus_apply_repeat', 'review_menus'),
        ('assets_check_first', 'review_menus'), ('assets_check_repeat', 'assets'), ('assets_apply-structure_repeat', 'assets')]
    expect(result['comparisons'] == [{'phase': phase, 'equals': stage, 'snapshot_sha256': snapshots[stage]['native_snapshot_sha256']}
        for phase, stage in comparisons], 'EARLY_ALL_FIFTEEN_ACTUAL_COMPLETE_REPEAT_COMPARISONS')

def same_terminal_facts(left, right):
    expect(type(left) is dict and type(right) is dict and set(left) == set(right), 'SAME_ALL_TERMINAL_FIELDS')
    canonical = lambda value: json.dumps(value, sort_keys=True, separators=(',', ':'))
    expect(type(left['mounts']) is list and type(right['mounts']) is list
        and sorted(canonical(row) for row in left['mounts']) == sorted(canonical(row) for row in right['mounts'])
        and canonical({key: value for key, value in left.items() if key != 'mounts'})
        == canonical({key: value for key, value in right.items() if key != 'mounts'}), 'ALL_TERMINAL_FACTS_EXACT_MOUNT_MULTISET')


def verify_fulfillment_migration(context, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'])
    terminal = normal_terminal(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'], native_required)
    expect(paid_equal(load(evidence / FULFILLMENT_MIGRATION_FOUR[0]), created_facts), 'EXACT_MIGRATION_PUBLIC_CREATED_COPY')
    same_terminal_facts(load(evidence / FULFILLMENT_MIGRATION_FOUR[3]), terminal)
    fulfillment_migration_result(context, created_facts, terminal, load(evidence / FULFILLMENT_MIGRATION_FOUR[2]))
    verify_fulfillment_helper(context, 'register-fulfillment-migration', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    early_expected_formal(context, load(evidence / 'ms4-fulfillment-migration-formal-manifest.json'))
    return terminal


def fulfillment_prerequisite_hashes(context):
    verify_paid_history(context, False)
    verify_fulfillment_migration(context, False)
    names = PREREQUISITES + FILES_PREREQUISITES + PREPARE_PREREQUISITES + PAID_PUBLIC_SIX + FULFILLMENT_MIGRATION_FOUR
    expect(len(names) == len(set(names)) == 25, 'FULFILLMENT_EXACT_TWENTY_FIVE_REAL_PREREQUISITES')
    evidence = Path(context['run_dir']) / 'evidence'
    return {name: digest((evidence / name).read_bytes()) for name in names}


def fulfillment_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    return {'schema': 1, 'scope': FULFILLMENT['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / 'fulfillment-normal-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': FULFILLMENT_ENTRY_SHA256, 'source_files': frozen['fulfillment_source_files'],
        'prerequisites_sha256': fulfillment_prerequisite_hashes(context)}


def record_fulfillment_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    actual, receipt = environment_observation(context, cid, True)
    new(evidence / 'fulfillment-normal-environment-native.json', receipt)
    new(evidence / 'fulfillment-normal-environment.json', {'schema': 1, 'scope': FULFILLMENT['environment_scope'],
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': cid,
        'freeze_sha256': context['freeze_sha256'], 'environment': actual, 'native_projection_sha256': receipt['stdout_sha256']})
    new(evidence / FULFILLMENT['binding'], fulfillment_binding(context, cid))


def verify_fulfillment_binding(context, cid, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    binding = load(evidence / FULFILLMENT['binding'])
    expect(paid_equal(binding, fulfillment_binding(context, cid)), 'EXACT_FULFILLMENT_THIRTEEN_KEY_BINDING')
    environment = load(evidence / 'fulfillment-normal-environment.json')
    receipt = load(evidence / 'fulfillment-normal-environment-native.json')
    expect(paid_equal(environment, {'schema': 1, 'scope': FULFILLMENT['environment_scope'], 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'container_id': cid, 'freeze_sha256': context['freeze_sha256'],
        'environment': customer_form_environment(context), 'native_projection_sha256': receipt['stdout_sha256']}), 'FULFILLMENT_EXACT_EIGHT_ENV_KEYS')
    expect(type(receipt) is dict and set(receipt) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'}
        and receipt['stage'] == 'customer-public-environment-inspect' and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] > 0 and paid_hash(receipt['stdout_sha256'])
        and type(receipt['stderr_bytes']) is int and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
        and receipt['private_environment_logs_omitted'] is True and environment_projection_matches(context, receipt, True), 'FULFILLMENT_REAL_SORTED_SEVEN_ENV_DOUBLE_LF')
    if native_required:
        projected, fresh = environment_observation(context, cid, True)
        expect(paid_equal(projected, environment['environment']) and paid_equal(fresh, receipt), 'FULFILLMENT_SAME_NATIVE_ENV_BYTES')
    return binding


def verify_fulfillment_sequence(sequence, source_count, children):
    expect(type(sequence) is dict and set(sequence) == {'scope', 'status', 'epoch_ms', 'source_id_count', 'key_count', 'targets', 'server_samples',
        'monotonic_elapsed_ns', 'complete_database_before_sha256', 'complete_database_after_sha256', 'non_target_redis_sha256',
        'all_non_target_redis_values_and_absolute_expiries_unchanged', 'complete_database_strictly_unchanged', 'original_paid_account_payment_facts_retained'}, 'FULFILLMENT_SEQUENCE_FOURTEEN_KEYS')
    expect(sequence['scope'] == 'READONLY_REAL_PRODUCER_SEQUENCE_EXPIRY_BARRIER_ONLY'
        and sequence['status'] == 'VERIFIED_CF_SEQUENCE_KEYS_NATURALLY_ABSENT' and sequence['epoch_ms'] == '1640966400000'
        and type(sequence['source_id_count']) is int and sequence['source_id_count'] == source_count
        and type(sequence['key_count']) is int and 1 <= sequence['key_count'] <= source_count
        and type(sequence['monotonic_elapsed_ns']) is int and 0 <= sequence['monotonic_elapsed_ns'] <= 10000000000
        and all(sequence[key] is True for key in ['all_non_target_redis_values_and_absolute_expiries_unchanged', 'complete_database_strictly_unchanged', 'original_paid_account_payment_facts_retained'])
        and all(paid_hash(sequence[key]) for key in ['complete_database_before_sha256', 'complete_database_after_sha256', 'non_target_redis_sha256'])
        and sequence['complete_database_before_sha256'] == sequence['complete_database_after_sha256'], 'FULFILLMENT_REAL_CF_SEQUENCE_BARRIER')
    samples = sequence['server_samples']
    expect(type(samples) is list and len(samples) >= 3, 'FULFILLMENT_REAL_SEQUENCE_SAMPLES')
    last_time = -1
    for sample in samples:
        expect(type(sample) is dict and set(sample) == {'time', 'time_ms', 'key_count', 'complete_keys_sha256'}
            and type(sample['time']) is list and len(sample['time']) == 2 and paid_decimal(sample['time'][0])
            and type(sample['time'][1]) is str and re.fullmatch('[0-9]{1,6}', sample['time'][1])
            and int(sample['time'][1]) <= 999999 and paid_decimal(sample['time_ms'])
            and int(sample['time_ms']) == int(sample['time'][0]) * 1000 + int(sample['time'][1]) // 1000
            and int(sample['time_ms']) >= last_time and type(sample['key_count']) is int and sample['key_count'] >= 0
            and paid_hash(sample['complete_keys_sha256']), 'FULFILLMENT_SEQUENCE_NATIVE_TIME_AND_FULL_KEYS_HASH')
        last_time = int(sample['time_ms'])
    expect(type(sequence['targets']) is list and len(sequence['targets']) == sequence['key_count'], 'FULFILLMENT_REAL_CF_TARGET_COUNT')
    seen_keys, seen_sources = set(), set()
    for target in sequence['targets']:
        expect(type(target) is dict and set(target) == {'key_sha256', 'sources', 'initial_observation', 'initial_expires_at_ms', 'initial_value_sha256', 'first_absence_time_ms', 'natural_expiry_observed', 'final_absent'}
            and paid_hash(target['key_sha256']) and target['key_sha256'] not in seen_keys
            and type(target['sources']) is list and 1 <= len(target['sources']) <= source_count
            and target['final_absent'] is True and paid_decimal(target['first_absence_time_ms'])
            and int(samples[0]['time_ms']) <= int(target['first_absence_time_ms']) <= last_time, 'FULFILLMENT_REAL_CF_TARGET')
        seen_keys.add(target['key_sha256'])
        for index, source in enumerate(target['sources']):
            expect(type(source) is dict and set(source) == {'source_kind', 'identifier_sha256', 'child_order_id', 'sequence'}
                and source['source_kind'] == 'fulfillment_child' and paid_hash(source['identifier_sha256'])
                and type(source['child_order_id']) is int and source['child_order_id'] in children and source['child_order_id'] not in seen_sources
                and source['sequence'] == ('0' if index == 0 else '2'), 'FULFILLMENT_REAL_CF_SOURCES_AND_COUNTER_SEQUENCE')
            seen_sources.add(source['child_order_id'])
        if target['initial_observation'] == 'ALREADY_ABSENT':
            expect(target['initial_expires_at_ms'] is None and target['initial_value_sha256'] is None and target['natural_expiry_observed'] is False
                and target['first_absence_time_ms'] == samples[0]['time_ms'], 'FULFILLMENT_SEQUENCE_ACTUALLY_ALREADY_ABSENT')
        else:
            expiry = target['initial_expires_at_ms']
            expect(target['initial_observation'] == 'PRESENT_WITH_OBSERVED_EXPIRY' and type(expiry) is int
                and 0 < expiry - int(samples[0]['time_ms']) <= 1000 and paid_hash(target['initial_value_sha256'])
                and target['natural_expiry_observed'] is True and int(target['first_absence_time_ms']) >= expiry, 'FULFILLMENT_REAL_NATURAL_SEQUENCE_EXPIRY')
    expect(len(seen_sources) == source_count, 'FULFILLMENT_ALL_REAL_CF_SOURCES_COVERED')


# Independent finite consumer of the frozen three-read PHP projection only.
# The private legacy archive/body and vanished namespace PID are never reopened.
def three_read_ids_sha256(ids):
    expect(type(ids) is list and all(type(value) is int and 1 <= value <= 2147483647 for value in ids),
           'THREE_READ_PUBLIC_NATIVE_INTEGER_IDS_ONLY')
    raw = ('a:' + str(len(ids)) + ':{' + ''.join('i:' + str(index) + ';i:' + str(value) + ';'
           for index, value in enumerate(ids)) + '}').encode('ascii')
    return digest(raw)


def verify_three_read_native(native):
    keys = {'mode', 'argv', 'cwd', 'environment_inherited', 'pid', 'supervisor', 'registered_group',
        'first_native_status', 'last_native_status', 'same_handle_first_terminal', 'proc_close_exit', 'native_exit',
        'process_closed', 'remaining_members', 'registered_group_gone', 'listener_open', 'term_attempted', 'term_sent',
        'normal_only', 'capture_complete', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256',
        'failure_close_budget_ns', 'failure_close_started_ns', 'failure_close_deadline_ns',
        'failure_close_budget_exhausted', 'observed_elapsed_ns', 'failure_code', 'native_completed_normally'}
    expect(type(native) is dict and set(native) == keys and native['mode'] == 'legacy'
        and native['argv'] == ['setsid', 'php', 'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php', 'legacy']
        and native['cwd'] == '/workspace' and native['environment_inherited'] is True
        and type(native['pid']) is int and native['pid'] > 1, 'THREE_READ_EXACT_THIRTY_ONE_NATIVE_FIELDS_AND_ARGV')
    pid = native['pid']; supervisor = native['supervisor']; group = native['registered_group']
    expect(type(supervisor) is dict and set(supervisor) == {'pid', 'pgid', 'sid', 'starttime'}
        and all(type(supervisor[key]) is int and supervisor[key] > 0 for key in ['pid', 'pgid', 'sid'])
        and supervisor['pid'] == 1 and type(supervisor['starttime']) is str
        and re.fullmatch('[0-9]+', supervisor['starttime']) is not None,
        'THREE_READ_ORIGINAL_PID_ONE_NATIVE_SUPERVISOR')
    expect(type(group) is dict and set(group) == {'leader_pid', 'pgid', 'sid', 'leader_starttime'}
        and pid not in [supervisor['pid'], supervisor['pgid'], supervisor['sid']]
        and all(type(group[key]) is int and group[key] == pid for key in ['leader_pid', 'pgid', 'sid'])
        and type(group['leader_starttime']) is str and re.fullmatch('[0-9]+', group['leader_starttime']) is not None
        and int(group['leader_starttime']) >= int(supervisor['starttime']), 'THREE_READ_EXACT_ORIGINAL_REGISTERED_NATIVE_GROUP')
    status_keys = {'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'}
    for key in ['first_native_status', 'last_native_status', 'same_handle_first_terminal']:
        status = native[key]
        expect(type(status) is dict and set(status) == status_keys
            and type(status['pid']) is int and status['pid'] == pid
            and all(type(status[field]) is bool for field in ['running', 'signaled', 'stopped'])
            and all(type(status[field]) is int for field in ['exitcode', 'termsig', 'stopsig']),
            'THREE_READ_EXACT_SEVEN_NATIVE_STATUS_FIELDS_SAME_PID')
    terminal = native['same_handle_first_terminal']; first = native['first_native_status']
    expect(paid_equal(native['last_native_status'], terminal)
        and terminal['running'] is False and terminal['signaled'] is False and terminal['stopped'] is False
        and terminal['exitcode'] == 0 and terminal['termsig'] == 0 and terminal['stopsig'] == 0
        and first['signaled'] is False and first['stopped'] is False and first['termsig'] == 0 and first['stopsig'] == 0
        and ((first['running'] is True and first['exitcode'] == -1) or paid_equal(first, terminal)),
        'THREE_READ_SAVED_SAME_HANDLE_NORMAL_TERMINAL_FIRST_MAY_ALREADY_BE_TERMINAL')
    expect(type(native['proc_close_exit']) is int and native['proc_close_exit'] in [0, -1]
        and type(native['native_exit']) is int and native['native_exit'] == 0
        and all(native[key] is True for key in ['process_closed', 'registered_group_gone', 'normal_only',
            'capture_complete', 'native_completed_normally'])
        and type(native['remaining_members']) is list and native['remaining_members'] == []
        and all(native[key] is False for key in ['listener_open', 'term_attempted', 'term_sent', 'failure_close_budget_exhausted'])
        and type(native['stdout_bytes']) is int and 0 < native['stdout_bytes'] <= 16384 and paid_hash(native['stdout_sha256'])
        and type(native['stderr_bytes']) is int and native['stderr_bytes'] == 0 and native['stderr_sha256'] == digest(b'')
        and type(native['failure_close_budget_ns']) is int and native['failure_close_budget_ns'] == 9000000000
        and all(native[key] is None for key in ['failure_close_started_ns', 'failure_close_deadline_ns', 'failure_code'])
        and type(native['observed_elapsed_ns']) is int and native['observed_elapsed_ns'] > 0,
        'THREE_READ_NORMAL_SAME_HANDLE_CLOSED_DUAL_EOF_GROUP_GONE_NATIVE_ZERO_NO_FAILURE_TERM')


def three_read_window_count(population, page, limit):
    expect(type(population) is int and population in [1, 101] and type(page) is int and page >= 0
        and type(limit) is int and limit >= 0, 'THREE_READ_REAL_NONNEGATIVE_NATIVE_WINDOW')
    if page == 0 or limit == 0:
        return population
    limit = min(limit, 100)
    return max(0, min(limit, population - (page - 1) * limit))


def three_read_expected_cases(stage, windows, children):
    # Exact frozen m4tr_read_stage loop. Duplicate child pages remain duplicate cases.
    cases = []
    for scope in ['customer', 'platform', 'mobile']:
        window = windows['mobile' if scope == 'mobile' else 'customer_platform']
        def add(kind, wrapper, page, limit, count, label):
            cases.append({'view': scope + '_' + kind,
                'surface': 'REAL_BUSINESS_WRAPPER' if wrapper else 'OWNED_PUBLIC_SERVICE',
                'page': page, 'limit': limit, 'expected_native_count': count,
                'returned_count': three_read_window_count(count, page, limit) if kind == 'list' else 1,
                'label': label})
        for wrapper in [False, True]:
            add('list', wrapper, window[0] if wrapper else 0, window[1] if wrapper else 0, 1, 'paid')
        for wrapper in [False, True]:
            add('detail', wrapper, 1, 20, 1, 'paid')
        if stage in [0, 5]:
            for page, limit in [[0, 0], [1, 0], [1, 1], [100, 1], [101, 1], [102, 1], [1, 100], [2, 100], [3, 100]]:
                add('list', False, page, limit, 101, 'whole')
            add('list', True, window[0], window[1], 101, 'whole')
            for label in ['member', 'legacy']:
                for wrapper in [False, True]:
                    add('detail', wrapper, 1, 20, 1, label)
        if children:
            for page, limit in [[1, 1], [2, 1], [len(children) + 1, 1]]:
                for wrapper in [False, True]:
                    add('detail', wrapper, page, limit, 1, 'paid')
            for child in children:
                for wrapper in [False, True]:
                    add('detail', wrapper, 1, 20, 1, 'child:' + str(child))
    return cases


def verify_three_read_result(context, original_result, binding, business):
    evidence = Path(context['run_dir']) / 'evidence'
    result = load(evidence / THREE_READ_RESULT)
    root_keys = {'schema', 'scope', 'status', 'run_id', 'source_sha', 'freeze_sha256', 'container_id',
        'source_binding_sha256', 'phase', 'entry_return_code', 'native_exit', 'native_exit_observation', 'legacy_native',
        'fixture', 'baseline', 'stage_count', 'owned_call_count', 'wrapper_call_count', 'total_call_count', 'stages',
        'final_source_and_original_inputs_verified', 'diagnostic', 'cleanup_failure', 'HTTP_API_DOM', 'worker', 'task7',
        'full_acceptance', 'stopped_samples'}
    expect(type(result) is dict and set(result) == root_keys and type(result['schema']) is int and result['schema'] == 1
        and result['scope'] == 'MS4_THREE_READ_NORMAL_INTERNAL_ONLY' and result['status'] == 'VERIFIED_THREE_READ_NORMAL_INTERNAL_ONLY'
        and all(result[key] == context[key] == original_result[key] == binding[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and result['container_id'] == original_result['container_id'] == binding['container_id']
        and result['source_binding_sha256'] == original_result['source_binding_sha256']
        == digest((evidence / FULFILLMENT['binding']).read_bytes())
        and result['phase'] == original_result['phase'] == 'final_source'
        and type(result['entry_return_code']) is int and result['entry_return_code'] == 0
        and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['final_source_and_original_inputs_verified'] is True
        and result['diagnostic'] is None and result['cleanup_failure'] is None
        and all(result[key] == 'NOT RUN' for key in ['HTTP_API_DOM', 'worker', 'task7', 'stopped_samples'])
        and result['full_acceptance'] == 'INCOMPLETE', 'THREE_READ_EXACT_TWENTY_EIGHT_SUCCESS_FIELDS_SAME_ORIGINAL_RESULT_BINDING')
    verify_three_read_native(result['legacy_native'])
    fixture = result['fixture']
    fixture_values = {'original_paid_commercial_roots': 1, 'new_true_legacy_commercial_roots': 1,
        'new_true_v1_unpaid_roots': 99, 'whole_commercial_roots': 101, 'new_ordinary_v1_roots': 98,
        'new_member_v1_roots': 1, 'member_saved_unit_price': '16.00', 'member_saved_base_price': '20.15',
        'independent_new_v1_goods_total': '1990.70', 'saved_deadline_zero_roots': 99, 'current_cancel_hours': 1,
        'platform_ordinary_role_rows': 1, 'platform_ordinary_admin_rows': 1,
        'internal_identity_is_not_HTTP_authentication': True, 'full_native_preparation_delta_verified': True}
    fixture_hashes = {'legacy_native_archive_sha256', 'private_preparation_facts_sha256'}
    expect(type(fixture) is dict and set(fixture) == set(fixture_values) | fixture_hashes
        and all(paid_equal(fixture[key], value) for key, value in fixture_values.items())
        and all(paid_hash(fixture[key]) for key in fixture_hashes), 'THREE_READ_EXACT_SEVENTEEN_FINITE_FIXTURE_FIELDS')
    baseline = result['baseline']
    expect(type(baseline) is dict and set(baseline) == {'database_sha256', 'redis_keys_values_absolute_expiries_sha256',
        'complete_native_baseline_sha256'} and all(paid_hash(value) for value in baseline.values()),
        'THREE_READ_EXACT_THREE_PREPARATION_BASELINE_HASHES_NOT_LATER_STAGE_BASELINE')
    stages = result['stages']
    expect(type(result['stage_count']) is int and result['stage_count'] == 6
        and type(stages) is list and len(stages) == 6, 'THREE_READ_ALL_SIX_ACTUAL_STAGES')
    stage_keys = {'stage', 'whole_population', 'paid_roots', 'unpaid_v1_roots', 'legacy_roots',
        'member_saved_price_differs_from_base', 'actual_wrapper_windows', 'calls', 'call_count', 'complete_stage_zero'}
    call_keys = {'view', 'surface', 'authentication', 'page', 'limit', 'expected_native_count', 'returned_count',
        'selected_native_ids_sha256', 'comparison', 'actual_interval', 'database_before_sha256', 'database_after_sha256',
        'redis_before_sha256', 'redis_after_sha256',
        'complete_database_rows_metadata_counters_and_redis_values_absolute_expiries_zero',
        'same_default_connection_pdo_depth_zero', 'runtime_files_and_original_paid_inputs_unchanged'}
    paid_id = business['original_root_id']; operations = business['operations']
    all_children = [operations[0]['child_order_id'], operations[3]['child_order_id']]
    known_ids = {'paid': paid_id, **{'child:' + str(value): value for value in all_children}}
    selected_hashes = {}; scalar_hashes = {}; whole_hashes = {}; page_slots = {}
    owned_count = wrapper_count = total_count = 0
    first_windows = None
    for stage_index, stage in enumerate(stages):
        stage_values = {'stage': stage_index, 'whole_population': 101, 'paid_roots': 1, 'unpaid_v1_roots': 99, 'legacy_roots': 1}
        expect(type(stage) is dict and set(stage) == stage_keys
            and all(type(stage[key]) is int and stage[key] == value for key, value in stage_values.items())
            and stage['member_saved_price_differs_from_base'] is True and stage['complete_stage_zero'] is True,
            'THREE_READ_EXACT_TEN_STAGE_FIELDS_AND_ORDERED_ORIGINAL_STATES')
        windows = stage['actual_wrapper_windows']
        expect(type(windows) is dict and set(windows) == {'customer_platform', 'mobile'}
            and all(type(window) is list and len(window) == 3 and all(type(value) is int for value in window)
                and window[0] >= 0 and window[1] >= 0 for window in windows.values()),
            'THREE_READ_ACTUAL_THREE_INT_WRAPPER_WINDOWS_NO_ASSUMED_DEFAULT_LIMIT')
        if first_windows is None:
            first_windows = windows
        else:
            expect(paid_equal(windows, first_windows), 'THREE_READ_SAME_UNMODIFIED_REQUEST_AND_CONFIG_WINDOWS_THROUGH_STAGES')
        stage_children = []
        stage_ships = sorted((operation for operation in operations[:stage_index] if operation['action'] == 'ship'),
            key=lambda operation: int(operation['operation_id']), reverse=True)
        for operation in stage_ships:
            if operation['child_order_id'] not in stage_children:
                stage_children.append(operation['child_order_id'])
        cases = three_read_expected_cases(stage_index, windows, stage_children)
        calls = stage['calls']
        expect(type(calls) is list and type(stage['call_count']) is int
            and stage['call_count'] == len(calls) == len(cases), 'THREE_READ_COMPLETE_FROZEN_LOOP_CALL_MULTISET_WITH_DUPLICATES')
        stage_database = stage_redis = None
        for call, case in zip(calls, cases):
            expect(type(call) is dict and set(call) == call_keys
                and all(paid_equal(call[key], value) for key, value in case.items() if key != 'label')
                and call['authentication'] == 'INTERNAL_ONLY_HTTP_NOT_RUN'
                and all(paid_hash(call[key]) for key in ['selected_native_ids_sha256', 'database_before_sha256',
                    'database_after_sha256', 'redis_before_sha256', 'redis_after_sha256'])
                and call['database_before_sha256'] == call['database_after_sha256']
                and call['redis_before_sha256'] == call['redis_after_sha256']
                and all(call[key] is True for key in ['complete_database_rows_metadata_counters_and_redis_values_absolute_expiries_zero',
                    'same_default_connection_pdo_depth_zero', 'runtime_files_and_original_paid_inputs_unchanged'])
                and type(call['actual_interval']) is list and len(call['actual_interval']) == 2
                and all(type(value) is int and value > 0 for value in call['actual_interval'])
                and call['actual_interval'][0] <= call['actual_interval'][1],
                'THREE_READ_EXACT_SEVENTEEN_CALL_FIELDS_REAL_WINDOW_COUNTS_INTERVAL_AND_COMPLETE_ZERO')
            if stage_database is None:
                stage_database = call['database_before_sha256']; stage_redis = call['redis_before_sha256']
            expect(call['database_before_sha256'] == stage_database and call['redis_before_sha256'] == stage_redis,
                'THREE_READ_WHOLE_STAGE_COMPLETE_DATABASE_AND_REDIS_ZERO')
            label = case['label']; selected = call['selected_native_ids_sha256']; count = case['returned_count']
            if count == 0:
                expect(selected == three_read_ids_sha256([]), 'THREE_READ_ACTUAL_EMPTY_NATIVE_SELECTION_HASH')
            elif label in known_ids:
                expect(selected == three_read_ids_sha256([known_ids[label]]), 'THREE_READ_ORIGINAL_PUBLIC_PAID_OR_CHILD_NATIVE_LIST_HASH')
            if label == 'whole':
                page, limit = case['page'], case['limit']
                window_key = (0, 0) if page == 0 or limit == 0 else (page, min(limit, 100))
                if window_key in whole_hashes:
                    expect(selected == whole_hashes[window_key], 'THREE_READ_WHOLE_SELECTION_IDENTICAL_ACROSS_SCOPES_INITIAL_FINAL')
                else:
                    whole_hashes[window_key] = selected
            elif count:
                if label in selected_hashes:
                    expect(selected == selected_hashes[label], 'THREE_READ_SELECTED_REAL_LOCATOR_IDENTICAL_ACROSS_SCOPES_SURFACES_STATES')
                else:
                    selected_hashes[label] = selected
            comparison = call['comparison']; scope, kind = call['view'].rsplit('_', 1)
            wrapper = call['surface'] == 'REAL_BUSINESS_WRAPPER'
            if wrapper:
                expect(type(comparison) is dict and set(comparison) == {'kind', 'returned_count', 'native_count',
                    'complete_original_wrapper_envelope_values_and_types_verified'}
                    and comparison['kind'] == call['view'] and type(comparison['returned_count']) is int
                    and comparison['returned_count'] == count and type(comparison['native_count']) is int
                    and comparison['native_count'] == case['expected_native_count']
                    and comparison['complete_original_wrapper_envelope_values_and_types_verified'] is True,
                    'THREE_READ_EXACT_FOUR_ORIGINAL_WRAPPER_COMPARISON_FIELDS')
                wrapper_count += 1
            elif kind == 'list':
                expect(type(comparison) is dict and set(comparison) == {'kind', 'native_count', 'returned_count', 'legacy_slots',
                    'v1_slots', 'selected_native_ids_sha256', 'complete_expected_values_and_types_verified'}
                    and comparison['kind'] == scope + '_page'
                    and all(type(comparison[key]) is int for key in ['native_count', 'returned_count', 'legacy_slots', 'v1_slots'])
                    and comparison['native_count'] == case['expected_native_count'] and comparison['returned_count'] == count
                    and 0 <= comparison['legacy_slots'] <= min(1, count) and comparison['v1_slots'] >= 0
                    and comparison['legacy_slots'] + comparison['v1_slots'] == count
                    and (label != 'paid' or comparison['legacy_slots'] == 0)
                    and (count != 101 or comparison['legacy_slots'] == 1)
                    and comparison['selected_native_ids_sha256'] == selected
                    and comparison['complete_expected_values_and_types_verified'] is True,
                    'THREE_READ_EXACT_SEVEN_OWNED_PAGE_COMPARISON_FIELDS_NATIVE_SLOTS')
                slots = [comparison['legacy_slots'], comparison['v1_slots']]
                if selected in page_slots:
                    expect(paid_equal(slots, page_slots[selected]), 'THREE_READ_SAME_NATIVE_SELECTION_SAME_LEGACY_V1_SLOTS')
                else:
                    page_slots[selected] = slots
                owned_count += 1
            else:
                locator_class = 'real_child' if label.startswith('child:') else 'real_legacy' if label == 'legacy' else 'real_root'
                expect(type(comparison) is dict and set(comparison) == {'kind', 'locator_class', 'selected_native_id_sha256',
                    'complete_expected_values_and_types_verified'} and comparison['kind'] == scope + '_root'
                    and comparison['locator_class'] == locator_class and paid_hash(comparison['selected_native_id_sha256'])
                    and comparison['complete_expected_values_and_types_verified'] is True,
                    'THREE_READ_EXACT_FOUR_OWNED_ROOT_COMPARISON_FIELDS_REAL_LOCATOR_CLASS')
                scalar = comparison['selected_native_id_sha256']
                if label in known_ids:
                    expect(scalar == digest(str(known_ids[label]).encode('ascii')), 'THREE_READ_ORIGINAL_PUBLIC_NATIVE_SCALAR_LOCATOR_HASH')
                if label in scalar_hashes:
                    expect(scalar == scalar_hashes[label], 'THREE_READ_SAME_REAL_SCALAR_LOCATOR_ACROSS_SCOPES_INITIAL_FINAL')
                else:
                    scalar_hashes[label] = scalar
                owned_count += 1
            total_count += 1
    expect(set(selected_hashes) == set(known_ids) | {'member', 'legacy'}
        and len(set(selected_hashes.values())) == len(selected_hashes)
        and set(scalar_hashes) == set(selected_hashes) and len(set(scalar_hashes.values())) == len(scalar_hashes),
        'THREE_READ_DISTINCT_REAL_PAID_MEMBER_LEGACY_AND_ORIGINAL_CHILD_LOCATORS')
    expect(all(type(result[key]) is int and result[key] == value for key, value in
        [('owned_call_count', owned_count), ('wrapper_call_count', wrapper_count), ('total_call_count', total_count)])
        and total_count == owned_count + wrapper_count,
        'THREE_READ_ALL_COUNTS_RECOMPUTED_FROM_COMPLETE_FROZEN_CASE_MULTISET')
    return result


def verify_fulfillment_result(context, native_required=False, saved_formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    verify_paid_history(context, native_required)
    migration = verify_fulfillment_migration(context, False)
    facts = normal_terminal(context, FULFILLMENT['proof'], FULFILLMENT['role'], native_required)
    binding = verify_fulfillment_binding(context, facts['id'], native_required)
    verify_fulfillment_helper(context, 'register-fulfillment-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    formal = load(evidence / 'fulfillment-normal-formal-manifest.json')
    fulfillment_expected_formal(context, formal, FULFILLMENT['role'], FULFILLMENT['proof'])
    if saved_formal is None:
        expect(paid_equal(formal, load(Path(context['run_dir']) / 'manifest.json')), 'REAL_CURRENT_TASK6_FULL_FORMAL')
    else:
        expect(native_required is False and paid_equal(saved_formal, formal), 'ONLY_ACTUAL_SAVED_TASK6_FORMAL_FOR_REMOVED_HISTORY')
    expect(migration['id'] not in {row['id'] for row in formal['services'].values()}, 'REAL_FULFILLMENT_FOUR_ROLE_MANIFEST_MIGRATION_REMOVED')
    result = load(evidence / FULFILLMENT['result'])
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'prerequisites_sha256',
        'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'metadata_observer',
        'business', 'diagnostic', 'customer_fulfillment_API_DOM', 'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM',
        'worker', 'task7', 'full_acceptance', 'stopped_samples', 'assertions'}, 'FULFILLMENT_EXACT_TWENTY_NINE_RESULT_KEYS')
    expect(type(result['schema']) is int and result['schema'] == 1 and result['scope'] == FULFILLMENT['result_scope'] and result['status'] == FULFILLMENT['status']
        and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']) and result['container_id'] == facts['id']
        and result['native_created_sha256'] == digest((evidence / 'fulfillment-normal-created.json').read_bytes())
        and result['source_binding_sha256'] == digest((evidence / FULFILLMENT['binding']).read_bytes())
        and result['environment_sha256'] == digest((evidence / 'fulfillment-normal-environment.json').read_bytes())
        and result['environment_native_sha256'] == digest((evidence / 'fulfillment-normal-environment-native.json').read_bytes())
        and paid_equal(result['prerequisites_sha256'], binding['prerequisites_sha256'])
        and result['phase'] == 'final_source' and type(result['entry_return_code']) is int and result['entry_return_code'] == 0
        and result['termination'] == 'NORMAL_RETURN' and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and result['diagnostic'] is None and type(result['assertions']) is int and result['assertions'] > 0
        and all(result[key] == 'NOT RUN' for key in ['customer_fulfillment_API_DOM', 'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM', 'worker', 'task7', 'stopped_samples'])
        and result['full_acceptance'] == 'INCOMPLETE', 'FULFILLMENT_REAL_SUCCESS_AND_DISTINCT_HOST_NATIVE_ZERO')
    verify_fulfillment_observer(result)
    business = result['business']
    counts = {'original_items': 2, 'original_quantity': 3, 'new_roles': 1, 'new_employees': 1, 'new_password_setups': 1, 'new_configuration_rows': 1,
        'ships': 2, 'receives': 3, 'committed_operations': 5, 'persisted_ranges': 5, 'children': 2, 'child_items': 2,
        'same_key_zero_delta_replays': 5, 'merchant_saved_read_pairs': 6}
    flags = ['same_run_original_authenticated_owner_verified', 'original_paid_result_native_null_terminal_zero_verified', 'real_employee_setup_login_authenticate',
        'same_default_connection_pdo_depth_zero', 'all_saved_history_three_digests_native_types_verified', 'original_Q_integer_cent_allocations_verified',
        'all_previous_and_non_target_rows_metadata_redis_preserved', 'original_payment_stock_rewards_unchanged', 'independent_merchant_saved_DTO_verified']
    expect(type(business) is dict and set(business) == set(counts) | set(flags) | {'original_root_id', 'authenticated_owner_uid',
        'authenticated_owner_producer_container_id', 'root_status_sequence', 'shipped_received_sequence', 'final_summary', 'source_snapshot_sha256', 'operations', 'sequence_expiry', 'baseline'}
        and all(type(business[key]) is int and business[key] == value for key, value in counts.items())
        and all(business[key] is True for key in flags) and all(type(business[key]) is int and 1 <= business[key] <= 2147483647 for key in ['original_root_id', 'authenticated_owner_uid'])
        and business['authenticated_owner_producer_container_id'] == bound_created(context, PAID['proof'], PAID['role'])['id']
        and paid_equal(business['root_status_sequence'], [0, 0, 0, 1, 2]) and paid_equal(business['shipped_received_sequence'], [[2, 0], [2, 1], [2, 2], [3, 2], [3, 3]])
        and paid_hash(business['source_snapshot_sha256']), 'FULFILLMENT_EXACT_THIRTY_THREE_BUSINESS_FIELDS')
    summary = {'shipping_state': 'shipped', 'receipt_state': 'received', 'closed': True, 'original': 3, 'shipped': 3, 'received': 3,
        'cancelled_unshipped': 0, 'returned_accepted': 0, 'to_ship': 0, 'to_receive': 0, 'child_count': 2}
    expect(paid_equal(business['final_summary'], summary), 'FULFILLMENT_FINAL_ELEVEN_FIELD_ROOT_SUMMARY')
    operations = business['operations']
    expect(type(operations) is list and len(operations) == 5, 'FULFILLMENT_FIVE_ACTUAL_OPERATIONS')
    operation_ids, range_ids = set(), set()
    for index, operation in enumerate(operations):
        expect(type(operation) is dict and set(operation) == {'operation_id', 'range_id', 'action', 'child_order_id', 'source_item_id', 'source_ship_range_id',
            'unit_start', 'unit_end', 'quantity', 'request_digest_sha256', 'source_snapshot_sha256', 'allocation_digest_sha256', 'saved_result_sha256'}
            and all(paid_decimal(operation[key], 18446744073709551615) and operation[key] != '0' for key in ['operation_id', 'range_id'])
            and operation['operation_id'] not in operation_ids and operation['range_id'] not in range_ids
            and operation['action'] == ['ship', 'receive', 'receive', 'ship', 'receive'][index]
            and all(type(operation[key]) is int and 1 <= operation[key] <= 2147483647 for key in ['child_order_id', 'source_item_id'])
            and all(type(operation[key]) is int for key in ['unit_start', 'unit_end', 'quantity'])
            and [operation['unit_start'], operation['unit_end'], operation['quantity']] == [[0, 2, 2], [0, 1, 1], [1, 2, 1], [0, 1, 1], [0, 1, 1]][index]
            and all(paid_hash(operation[key]) for key in ['request_digest_sha256', 'source_snapshot_sha256', 'allocation_digest_sha256', 'saved_result_sha256']), 'FULFILLMENT_OPERATION_CANONICAL_IDS_RANGES_AND_DIGESTS')
        operation_ids.add(operation['operation_id']); range_ids.add(operation['range_id'])
        parent = 0 if index in [1, 2] else 3
        expect(operation['source_ship_range_id'] is None if operation['action'] == 'ship'
            else operation['source_ship_range_id'] == operations[parent]['range_id'], 'FULFILLMENT_ACTUAL_RECEIVE_SHIP_RANGE_PARENT')
    expect(operations[0]['child_order_id'] == operations[1]['child_order_id'] == operations[2]['child_order_id']
        and operations[3]['child_order_id'] == operations[4]['child_order_id'] and operations[0]['child_order_id'] != operations[3]['child_order_id']
        and operations[0]['source_item_id'] == operations[1]['source_item_id'] == operations[2]['source_item_id']
        and operations[3]['source_item_id'] == operations[4]['source_item_id'] and operations[0]['source_item_id'] != operations[3]['source_item_id'], 'FULFILLMENT_TWO_DISTINCT_CHILDREN_AND_SOURCE_ITEMS')
    sequence = business['sequence_expiry']
    expect(type(sequence) is list and len(sequence) == 2, 'FULFILLMENT_TWO_REAL_CF_EXPIRY_BARRIERS')
    verify_fulfillment_sequence(sequence[0], 1, {operations[0]['child_order_id']})
    verify_fulfillment_sequence(sequence[1], 2, {operations[0]['child_order_id'], operations[3]['child_order_id']})
    baseline = business['baseline']
    expect(type(baseline) is dict and set(baseline) == {'path_basename', 'bytes', 'sha256', 'native_archive_bytes', 'native_archive_sha256',
        'original_fixture_unchanged', 'original_paid_baseline_unchanged', 'private_native_readback_verified'}
        and baseline['path_basename'] == 'ms4-fulfillment-stage6.json' and type(baseline['bytes']) is int and baseline['bytes'] > 0
        and type(baseline['native_archive_bytes']) is int and baseline['native_archive_bytes'] > 0 and paid_hash(baseline['sha256']) and paid_hash(baseline['native_archive_sha256'])
        and all(baseline[key] is True for key in ['original_fixture_unchanged', 'original_paid_baseline_unchanged', 'private_native_readback_verified']), 'FULFILLMENT_SAFE_NEW_PRIVATE_BASELINE_DESCRIPTOR')
    shutdown = load(evidence / 'fulfillment-normal-shutdown.json')
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase',
        'completed', 'result_published', 'fatal', 'termination_requested', 'primary_failure', 'cleanup_failure', 'native_exit', 'native_exit_observation'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['scope'] == 'MS4_FULFILLMENT_NORMAL_SHUTDOWN'
        and all(shutdown[key] == result[key] for key in ['run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase'])
        and shutdown['completed'] is True and shutdown['result_published'] is True and shutdown['fatal'] is False and shutdown['termination_requested'] is False
        and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None and shutdown['native_exit'] is None
        and shutdown['native_exit_observation'] == 'HOST_NATIVE_REQUIRED', 'FULFILLMENT_TRUE_FIFTEEN_KEY_NORMAL_SHUTDOWN')
    expect(paid_equal(fulfillment_prerequisite_hashes(context), binding['prerequisites_sha256']), 'FULFILLMENT_ALL_TWENTY_FIVE_FULL_ORIGINALS_UNCHANGED')
    verify_three_read_result(context, result, binding, business)
    return result


def verify_fulfillment_observer(result):
    observer = result['metadata_observer']
    expect(type(observer) is dict and set(observer) == {'scope', 'before', 'configured'} and observer['scope'] == 'independent_pdo_session_only'
        and type(observer['before']) is list and len(observer['before']) == 1
        and type(observer['configured']) is list and len(observer['configured']) == 1, 'PAID_INDEPENDENT_OBSERVER_REAL_MODES')
    before, configured = observer['before'][0], observer['configured'][0]
    mode_keys = {'session_mode', 'global_mode', 'session_innodb', 'global_innodb', 'session_stats_expiry', 'global_stats_expiry'}
    expect(type(before) is dict and type(configured) is dict and set(before) == set(configured) == mode_keys
        and ((type(configured['session_stats_expiry']) is int and configured['session_stats_expiry'] == 0)
             or (type(configured['session_stats_expiry']) is str and configured['session_stats_expiry'] == '0'))
        and all(paid_equal(before[key], configured[key]) for key in mode_keys - {'session_stats_expiry'}), 'PAID_ONLY_OBSERVER_SESSION_STATS_CHANGED')
    for modes in [before, configured]:
        for key in ['session_stats_expiry', 'global_stats_expiry']:
            value = modes[key]
            expect((type(value) is int and 0 <= value <= 4294967295) or paid_decimal(value, 4294967295), 'PAID_NATIVE_STATS_NOT_NULL_NORMALIZED')




def fulfillment_public_names():
    names = paid_history_names() + ['paid-producer-formal-manifest.json', 'paid-producer-predecessor-history.json']
    names += FULFILLMENT_MIGRATION_FOUR + ['fulfillment-migration-created.json', 'fulfillment-migration-terminal.json',
        'ms4-fulfillment-migration-formal-manifest.json', 'fulfillment-normal-formal-manifest.json']
    names += ['fulfillment-normal-created.json', 'fulfillment-normal-terminal.json', 'ms4-fulfillment-normal-source-binding.json',
        'fulfillment-normal-environment.json', 'fulfillment-normal-environment-native.json', 'ms4-fulfillment-normal.json',
        'fulfillment-normal-shutdown.json', 'ms4-three-read-normal.json']
    for proof in ['register-fulfillment-migration', 'register-fulfillment-normal', 'authorize-fulfillment-cleanup']:
        names += [proof + suffix for suffix in ['-created.json', '-terminal.json', '-result.json', '-shutdown.json']]
    expect(len(names) == len(set(names)) == 63, 'ORIGINAL_SIXTY_THREE_TASK6_PUBLIC_ORIGINALS')
    return sorted(names)


def fulfillment_history_names():
    names = fulfillment_public_names() + ['files-bootstrap-paid-unstarted.json', 'customer-prepare-paid-unstarted.json']
    expect(len(names) == len(set(names)) == 65, 'TASK6_ORIGINALS_POST_C3_HELPER_AND_TWO_REAL_PRE_APP_PAID_OBSERVATIONS')
    return sorted(names)


def public_hashes(context, names):
    expect(type(names) is list and len(names) == len(set(names))
        and all(type(name) is str and re.fullmatch(r'[a-z0-9][a-z0-9.-]*\.json', name) for name in names), 'FINITE_PUBLIC_FILE_NAMES_ONLY')
    evidence = Path(context['run_dir']) / 'evidence'
    result = {}
    for name in names:
        path = evidence / name
        expect(path.is_file() and not path.is_symlink(), 'PUBLIC_REGULAR_ORIGINAL_REQUIRED')
        result[name] = digest(path.read_bytes())
    return result


def seal_fulfillment_predecessor(context):
    evidence = Path(context['run_dir']) / 'evidence'
    collect(context, proof_expected(context, FULFILLMENT['proof']), publish=False)
    result = verify_fulfillment_result(context, True)
    verify_fulfillment_helper(context, 'authorize-fulfillment-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    formal = (evidence / 'fulfillment-normal-formal-manifest.json').read_bytes()
    expect(formal == (Path(context['run_dir']) / 'manifest.json').read_bytes(), 'ACTUAL_TASK6_FORMAL_BEFORE_EXACT_REMOVAL')
    hashes = public_hashes(context, fulfillment_history_names())
    value = {'schema': 1, 'scope': 'MS4_FULFILLMENT_NORMAL_REAL_PREDECESSOR_HISTORY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes()),
        'manifest_sha256': digest(formal), 'public_sha256': hashes}
    new(evidence / 'fulfillment-normal-predecessor-history.json', value)
    expect(public_hashes(context, fulfillment_history_names()) == hashes, 'ALL_TASK6_ORIGINALS_UNCHANGED_DURING_SEAL')
    return result


def verify_fulfillment_history(context):
    evidence = Path(context['run_dir']) / 'evidence'
    history = load(evidence / 'fulfillment-normal-predecessor-history.json')
    expect(type(history) is dict and set(history) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256',
        'context_sha256', 'manifest_sha256', 'public_sha256'} and type(history['schema']) is int and history['schema'] == 1
        and history['scope'] == 'MS4_FULFILLMENT_NORMAL_REAL_PREDECESSOR_HISTORY'
        and all(history[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and history['context_sha256'] == digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes())
        and history['manifest_sha256'] == digest((evidence / 'fulfillment-normal-formal-manifest.json').read_bytes())
        and paid_equal(history['public_sha256'], public_hashes(context, fulfillment_history_names())), 'COMPLETE_TASK6_PUBLIC_NATIVE_HISTORY_SEAL')
    formal = load(evidence / 'fulfillment-normal-formal-manifest.json')
    result = verify_fulfillment_result(context, False, formal)
    verify_fulfillment_helper(context, 'authorize-fulfillment-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    expect(paid_equal(history['public_sha256'], public_hashes(context, fulfillment_history_names())), 'ALL_TASK6_ORIGINALS_UNCHANGED_AFTER_COMPLETE_CONSUMER')
    return result


def verify_fulfillment_removed(context):
    evidence = Path(context['run_dir']) / 'evidence'
    original = bound_created(context, FULFILLMENT['proof'], FULFILLMENT['role'])
    normal_terminal(context, FULFILLMENT['proof'], FULFILLMENT['role'], False)
    value = load(evidence / 'fulfillment-normal-removed.json')
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id',
        'created_sha256', 'terminal_sha256', 'native_remove', 'absent_after', 'target_role'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_FULFILLMENT_NORMAL_EXACT_REMOVAL'
        and all(value[key] == context[key] for key in ['run_id', 'source_sha']) and value['container_id'] == original['id']
        and value['target_role'] == FULFILLMENT['role'] and value['absent_after'] is True
        and value['created_sha256'] == digest((evidence / 'fulfillment-normal-created.json').read_bytes())
        and value['terminal_sha256'] == digest((evidence / 'fulfillment-normal-terminal.json').read_bytes()), 'TASK6_EXACT_CREATED_TERMINAL_REMOVAL_TEN_FIELDS')
    receipt = value['native_remove']
    expect(type(receipt) is dict and set(receipt) == {'argv', 'native_exit', 'stdout_sha256', 'stderr_sha256', 'same_handle_terminal'}
        and receipt['argv'] == ['docker', 'rm', original['id']] and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and receipt['stdout_sha256'] == digest((original['id'] + '\n').encode('ascii'))
        and receipt['stderr_sha256'] == digest(b''), 'TASK6_NONFORCE_EXACT_RM_AND_COMPLETE_STREAM_HASHES')
    terminal = receipt['same_handle_terminal']
    expect(type(terminal) is dict and set(terminal) == {'pid', 'returncode', 'wait_returncode'}
        and type(terminal['pid']) is int and terminal['pid'] > 0
        and type(terminal['returncode']) is int and terminal['returncode'] == 0
        and type(terminal['wait_returncode']) is int and terminal['wait_returncode'] == 0, 'TASK6_REAL_PYTHON_SAME_POPEN_NATIVE_TERMINAL')
    native = load(evidence / 'fulfillment-normal-remove-native.json')
    expect(type(native) is dict and set(native) == {'schema', 'native', 'stdout_bytes', 'stderr_bytes'}
        and type(native['schema']) is int and native['schema'] == 1 and paid_equal(native['native'], receipt)
        and type(native['stdout_bytes']) is int and native['stdout_bytes'] == len(original['id']) + 1
        and type(native['stderr_bytes']) is int and native['stderr_bytes'] == 0, 'TASK6_COMPLETE_NATIVE_RM_RECEIPT_PRESERVED')
    return value


def retire_fulfillment(context):
    evidence = Path(context['run_dir']) / 'evidence'
    expect(not (evidence / 'fulfillment-normal-removed.json').exists(), 'TASK6_REMOVE_ONCE_NO_RETRY')
    original = bound_created(context, FULFILLMENT['proof'], FULFILLMENT['role'])
    expected = proof_expected(context, FULFILLMENT['proof'])
    collect(context, expected)
    seal_fulfillment_predecessor(context)
    terminal = normal_terminal(context, FULFILLMENT['proof'], FULFILLMENT['role'], True)
    argv = ['docker', 'rm', original['id']]
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate()
    returncode = process.returncode
    waited = process.wait()
    receipt = {'argv': argv, 'native_exit': returncode, 'stdout_sha256': digest(stdout), 'stderr_sha256': digest(stderr),
        'same_handle_terminal': {'pid': process.pid, 'returncode': returncode, 'wait_returncode': waited}}
    new(evidence / 'fulfillment-normal-remove-native.json', {'schema': 1, 'native': receipt,
        'stdout_bytes': len(stdout), 'stderr_bytes': len(stderr)})
    expect(type(returncode) is int and returncode == 0 and type(waited) is int and waited == 0
        and stdout == (original['id'] + '\n').encode('ascii') and stderr == b'', 'TASK6_ACTUAL_SAME_HANDLE_EXACT_NONFORCE_REMOVE_NATIVE_ZERO')
    remaining = {cid: role for cid, role in expected.items() if cid != original['id']}
    collect(context, remaining)
    expect(original['id'] not in active(context), 'TASK6_FRESH_NORMAL_LISTING_ABSENT_AFTER_REMOVE')
    global_consumers(context, set(remaining))
    new(evidence / 'fulfillment-normal-removed.json', {'schema': 1, 'scope': 'MS4_FULFILLMENT_NORMAL_EXACT_REMOVAL',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': original['id'],
        'created_sha256': digest((evidence / 'fulfillment-normal-created.json').read_bytes()),
        'terminal_sha256': digest((evidence / 'fulfillment-normal-terminal.json').read_bytes()),
        'native_remove': receipt, 'absent_after': True, 'target_role': FULFILLMENT['role']})
    verify_fulfillment_removed(context)
    verify_fulfillment_history(context)
    return {'status': 'TASK6_NATIVE_ZERO_EXACT_NONFORCE_REMOVED_ONLY', 'container_id': original['id']}


def unstarted_paid_native_equal(left, right):
    """Compare only complete never-started native facts; mounts are a full multiset."""
    fields = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs',
        'read_only', 'privileged', 'network_mode', 'status', 'command', 'entrypoint', 'workdir',
        'restart_count', 'pid', 'exit_code', 'networks', 'ports', 'port_bindings', 'cap_drop',
        'security_options', 'started_at', 'finished_at', 'created_at'}
    if type(left) is not dict or type(right) is not dict or set(left) != fields or set(right) != fields:
        return False
    if not all(paid_equal(left[key], right[key]) for key in fields - {'mounts'}):
        return False
    if type(left['mounts']) is not list or type(right['mounts']) is not list:
        return False
    if not all(type(row) is dict for row in left['mounts'] + right['mounts']):
        return False
    canonical = lambda rows: sorted(json.dumps(row, sort_keys=True, separators=(',', ':'),
        ensure_ascii=True, allow_nan=False) for row in rows)
    return canonical(left['mounts']) == canonical(right['mounts'])


def current_unstarted_paid(context):
    original = bound_created(context, PAID['proof'], PAID['role'])
    fresh = inspect(context, original['id'], 'pre-App-paid-must-still-be-unstarted')
    owned(context, fresh, PAID['role']); same_created(original, fresh)
    expected = {key: value for key, value in original.items() if key not in ['run_id', 'source_sha', 'proof_name']}
    expect(len(expected) == 26 and unstarted_paid_native_equal(fresh, expected) and fresh['status'] == 'created'
        and type(fresh['pid']) is int and fresh['pid'] == 0 and type(fresh['restart_count']) is int and fresh['restart_count'] == 0,
        'SAME_CANONICAL_PAID_NEVER_STARTED_BEFORE_FILES_AND_C3')
    expect(all(type(fresh[key]) is str and re.fullmatch(r'0001-01-01T00:00:00(?:\.0{1,9})?Z', fresh[key])
        for key in ['started_at', 'finished_at']), 'PAID_ACTUAL_NATIVE_ZERO_TIMES_NEVER_STARTED')
    return fresh


def pre_app_paid_state(context, stage, create=False):
    expect(stage in ['files-bootstrap', 'customer-prepare'], 'ONLY_TWO_PRE_APP_PAID_OBSERVATION_STAGES')
    fresh = current_unstarted_paid(context)
    path = Path(context['run_dir']) / 'evidence' / (stage + '-paid-unstarted.json')
    if create:
        new(path, fresh)
    expect(unstarted_paid_native_equal(load(path), fresh), 'SAME_FULL_TWENTY_SIX_NATIVE_UNSTARTED_PAID_FIELDS')
    return fresh


def early_expected_formal(context, formal):
    evidence = Path(context['run_dir']) / 'evidence'
    original = load(evidence / 'ms5-payment-migration-formal-manifest.json')
    paid = bound_created(context, PAID['proof'], PAID['role'])
    migration = bound_created(context, FULFILLMENT_MIGRATION['proof'], FULFILLMENT_MIGRATION['role'])
    expected = {**context['initial_service_ids'], PAID['role']: paid['id'], FULFILLMENT_MIGRATION['role']: migration['id']}
    expect(type(formal) is dict and set(formal['services']) == set(expected)
        and all(paid_equal(formal[key], original[key]) for key in ['run_id', 'project', 'commit_sha', 'isolation', 'guard_mode',
            'network_internal', 'network', 'volumes', 'volume_identities'])
        and all(paid_equal(formal['services'][role], original['services'][role]) for role in ['audit-mysql', 'audit-redis'])
        and all(type(row) is dict and set(row) == {'id', 'address', 'image_id'} and row['id'] == expected[role]
            and row['image_id'] == ({'audit-mysql': MYSQL, 'audit-redis': REDIS}.get(role, PHP)) for role, row in formal['services'].items()),
        'REAL_PRE_APP_FOUR_ROLES_WITH_CANONICAL_UNSTARTED_PAID_NO_FUTURE_RECEIPTS')
    return formal


def early_binding(context):
    rows = [source_row(context['repo_dir'], path) for path in fulfillment_migration_paths()]
    hashes = {row['path']: row['sha256'] for row in rows}
    return {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'requested_repo_source': context['repo_dir'], 'requested_run_source': context['run_dir'],
        'entry_sha256': hashes[FULFILLMENT_MIGRATION_ENTRY], 'cli_sha256': hashes[FULFILLMENT_CLI], 'source_files': rows,
        'initialization_predecessor_sha256': early_initialization_hashes(context),
        'successor_cli_sha256': {path: hashes[path] for path in EARLY_SCRIPTS.values()}}


def early_stage(name, context_file):
    context = current(context_file); root = Path(context['run_dir']); evidence = root / 'evidence'; base = base_roles(context)
    if name == 'early-paid-create':
        expect(not (evidence / 'paid-producer-created.json').exists(), 'PAID_CANONICAL_CREATED_ONCE_ONLY')
        old = bound_created(context, 'driver', 'audit-ms5-payment-migration')
        terminal = normal_terminal(context, 'driver', 'audit-ms5-payment-migration', True)
        payment_result(context, old, terminal, load(evidence / 'ms5-payment-migration-normal.json'))
        formal = load(root / 'manifest.json')
        expect(set(formal['services']) == {'audit-ms5-payment-migration', 'audit-mysql', 'audit-redis'}
            and formal['services']['audit-ms5-payment-migration']['id'] == old['id'], 'REAL_MS5_FORMAL_BEFORE_FIRST_APP')
        exact_copy(root / 'manifest.json', evidence / 'ms5-payment-migration-formal-manifest.json')
        migration_prerequisites(context); actual_self(context)
        remove_finished(context, old['id'], 'audit-ms5-payment-migration', 'driver', base | {old['id']: 'audit-ms5-payment-migration'})
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', PAID['role']], 'early-paid-create', PAID['proof'])
        cid = created(context, PAID['role'], PAID['proof'])
        registered = helper(context, 'register-paid-producer', 'register', base | {cid: PAID['role']})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_PAID_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
        current_unstarted_paid(context)
        return {'status': 'CANONICAL_PAID_CREATED_UNSTARTED_ONLY', 'container_id': cid, 'App_paid_business': 'NOT RUN'}
    paid = current_unstarted_paid(context); paid_base = base | {paid['id']: PAID['role']}
    migration_prerequisites(context)
    if name == 'fulfillment-migration-create':
        expect(not (evidence / 'fulfillment-migration-created.json').exists(), 'EARLY_MIGRATION_CANONICAL_CREATED_ONCE')
        verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        collect(context, paid_base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', FULFILLMENT_MIGRATION['role']], name, 'fulfillment-migration')
        cid = created(context, FULFILLMENT_MIGRATION['role'], 'fulfillment-migration')
        exact_copy(evidence / 'fulfillment-migration-created.json', evidence / FULFILLMENT_MIGRATION_FOUR[0])
        registered = helper(context, 'register-fulfillment-migration', 'register', paid_base | {cid: FULFILLMENT_MIGRATION['role']})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_EARLY_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
        return early_stage('fulfillment-migration-bind', context_file)
    if name in ['fulfillment-migration-bind', 'fulfillment-migration-start']:
        verify_fulfillment_helper(context, 'register-fulfillment-migration', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        original = bound_created(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'])
        cid = original['id']; facts = inspect(context, cid, name + '-precheck')
        owned(context, facts, FULFILLMENT_MIGRATION['role']); same_created(original, facts)
        expect(facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0, 'ONLY_SAME_UNSTARTED_EARLY_MIGRATION')
        collect(context, paid_base | {cid: FULFILLMENT_MIGRATION['role']})
        early_expected_formal(context, load(root / 'manifest.json'))
        if name == 'fulfillment-migration-bind':
            exact_copy(root / 'manifest.json', evidence / 'ms4-fulfillment-migration-formal-manifest.json')
            new(evidence / FULFILLMENT_MIGRATION_FOUR[1], early_binding(context))
            return {'status': 'EARLY_MIGRATION_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': cid}
        expect(paid_equal(load(root / 'manifest.json'), load(evidence / 'ms4-fulfillment-migration-formal-manifest.json'))
            and paid_equal(load(evidence / FULFILLMENT_MIGRATION_FOUR[1]), early_binding(context)), 'EARLY_SAME_FULL_FORMAL_AND_REAL_BINDING_BEFORE_START')
        native(context, ['start', cid], name)
        return {'status': 'EARLY_SIXTEEN_COMMAND_DRIVER_STARTED_ONLY', 'container_id': cid}
    if name == 'fulfillment-migration-retire':
        terminal = normal_terminal(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'], True)
        exact_copy(evidence / 'fulfillment-migration-terminal.json', evidence / FULFILLMENT_MIGRATION_FOUR[3])
        verify_fulfillment_migration(context, True)
        remove_finished(context, terminal['id'], FULFILLMENT_MIGRATION['role'], 'fulfillment-migration', paid_base | {terminal['id']: FULFILLMENT_MIGRATION['role']})
        expect(terminal['id'] not in active(context), 'EARLY_NATIVE_ZERO_DRIVER_EXACTLY_REMOVED_BEFORE_FILES')
        collect(context, paid_base)
        return {'status': 'EARLY_DRIVER_NATIVE_ZERO_EXACTLY_REMOVED_ONLY', 'container_id': terminal['id'], 'App_paid_business': 'NOT RUN'}
    raise RuntimeError('UNKNOWN_FIXED_EARLY_STAGE')

def fulfillment_stage(name, context_file, proof=None, seconds=30):
    context = current(context_file)
    root = Path(context['run_dir']); evidence = root / 'evidence'
    base = base_roles(context)
    paid = bound_created(context, PAID['proof'], PAID['role'])
    paid_base = base | {paid['id']: PAID['role']}
    if name == 'fulfillment-normal-create':
        expect(not (evidence / 'fulfillment-normal-created.json').exists(), 'FULFILLMENT_ALREADY_CREATED_USE_SAME_HANDLE')
        seal_paid_predecessor(context)
        verify_paid_history(context, True)
        terminal = verify_fulfillment_migration(context, False)
        expect(terminal['id'] not in active(context), 'EARLY_MIGRATION_ALREADY_NATIVE_ZERO_AND_REMOVED_BEFORE_APP')
        collect(context, paid_base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', FULFILLMENT['role']], name, FULFILLMENT['proof'])
        cid = created(context, FULFILLMENT['role'], FULFILLMENT['proof'])
        registered = helper(context, 'register-fulfillment-normal', 'register', paid_base | {cid: FULFILLMENT['role']})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_FULFILLMENT_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
        return fulfillment_stage('fulfillment-normal-bind', context_file)
    if name == 'fulfillment-normal-bind':
        verify_paid_history(context, True); verify_fulfillment_migration(context, False)
        verify_fulfillment_helper(context, 'register-fulfillment-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        original = bound_created(context, FULFILLMENT['proof'], FULFILLMENT['role'])
        cid = original['id']; facts = inspect(context, cid, name + '-precheck')
        owned(context, facts, FULFILLMENT['role']); same_created(original, facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0, 'ONLY_SAME_UNSTARTED_FULFILLMENT_BIND')
        collect(context, paid_base | {cid: FULFILLMENT['role']})
        fulfillment_expected_formal(context, load(root / 'manifest.json'), FULFILLMENT['role'], FULFILLMENT['proof'])
        exact_copy(root / 'manifest.json', evidence / 'fulfillment-normal-formal-manifest.json')
        record_fulfillment_binding(context, cid)
        return {'status': 'FULFILLMENT_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY', 'container_id': cid}
    if name == 'fulfillment-normal-start':
        verify_paid_history(context, True); verify_fulfillment_migration(context, False)
        verify_fulfillment_helper(context, 'register-fulfillment-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        original = bound_created(context, FULFILLMENT['proof'], FULFILLMENT['role'])
        cid = original['id']; facts = inspect(context, cid, name + '-precheck')
        owned(context, facts, FULFILLMENT['role']); same_created(original, facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0, 'ONLY_SAME_UNSTARTED_FULFILLMENT_START')
        collect(context, paid_base | {cid: FULFILLMENT['role']})
        verify_fulfillment_binding(context, cid, True)
        expect(paid_equal(load(root / 'manifest.json'), load(evidence / 'fulfillment-normal-formal-manifest.json')), 'FULFILLMENT_SAME_REGISTERED_FORMAL_AT_START')
        native(context, ['start', cid], name)
        return {'status': 'FULFILLMENT_NORMAL_STARTED_ONLY', 'container_id': cid}
    if name == 'authorize-fulfillment-close':
        verify_fulfillment_result(context, True)
        return helper(context, 'authorize-fulfillment-cleanup', 'authorize-cleanup', proof_expected(context, FULFILLMENT['proof']))
    if name == 'fulfillment-normal-retire':
        return retire_fulfillment(context)
    raise RuntimeError('UNKNOWN_EXPLICIT_FULFILLMENT_STAGE')


def aftersale_review_prerequisite_hashes(context):
    verify_fulfillment_history(context)
    verify_fulfillment_removed(context)
    names = PREREQUISITES + FILES_PREREQUISITES + PREPARE_PREREQUISITES + PAID_PUBLIC_SIX + FULFILLMENT_MIGRATION_FOUR
    names += ['fulfillment-normal-created.json', 'ms4-fulfillment-normal-source-binding.json',
        'fulfillment-normal-environment.json', 'fulfillment-normal-environment-native.json', 'ms4-fulfillment-normal.json',
        'fulfillment-normal-shutdown.json', 'fulfillment-normal-terminal.json', 'fulfillment-normal-removed.json', THREE_READ_RESULT]
    expect(len(names) == len(set(names)) == 34, 'AFTERSALE_REVIEW_EXACT_THIRTY_FOUR_REAL_PREDECESSORS')
    return public_hashes(context, names)


def aftersale_review_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    rows = frozen['aftersale_review_source_files']
    entries = [row for row in rows if row['path'] == AFTERSALE_REVIEW_ENTRY]
    expect(len(entries) == 1 and paid_equal(entries[0], source_row(context['repo_dir'], AFTERSALE_REVIEW_ENTRY)), 'NEW_ENTRY_ACTUAL_FROZEN_COMPLETE_BYTES')
    return {'schema': 1, 'scope': AFTERSALE_REVIEW['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / 'aftersale-review-normal-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': entries[0]['sha256'], 'source_files': rows, 'prerequisites_sha256': aftersale_review_prerequisite_hashes(context)}


def record_aftersale_review_binding(context, cid):
    record_aftersale_environment(context, cid)
    new(Path(context['run_dir']) / 'evidence' / AFTERSALE_REVIEW['binding'], aftersale_review_binding(context, cid))


def verify_aftersale_review_binding(context, cid, native_required=False):
    actual = load(Path(context['run_dir']) / 'evidence' / AFTERSALE_REVIEW['binding'])
    expect(paid_equal(actual, aftersale_review_binding(context, cid)) and len(actual) == 13, 'NEW_ENTRY_EXACT_THIRTEEN_KEY_REAL_SOURCE_BINDING')
    verify_aftersale_environment(context, cid, native_required)
    return actual


def verify_aftersale_review_helper(context, proof, wanted_status):
    expect((proof, wanted_status) in [('register-aftersale-review-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')], 'ONLY_TWO_FIXED_AFTERSALE_REVIEW_HELPERS')
    phase = 'register' if proof == 'register-aftersale-review-normal' else 'authorize-cleanup'
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, proof, 'audit-bootstrap')
    normal_terminal(context, proof, 'audit-bootstrap', False)
    expect(created_facts['command'] == ['/run/ms4-prep/ms4-initialize.php', phase], 'NEW_LIFECYCLE_ACTUAL_ORIGINAL_INITIALIZER_COMMAND')
    result = load(evidence / (proof + '-result.json'))
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'children', 'private_cleanup',
        'private_cleanup_verified', 'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_AFTERSALE_REVIEW_LIFECYCLE_ONLY'
        and result['status'] == wanted_status and type(result['native_exit']) is int and result['native_exit'] == 0
        and all(result[key] == context[key] for key in ['run_id', 'source_sha']) and result['children'] == []
        and result['private_cleanup'] == [] and result['private_cleanup_verified'] is False
        and all(result[key] == 'NOT RUN' for key in ['App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance']), 'NEW_LIFECYCLE_COMPLETE_THIRTEEN_FIELD_PUBLIC_RESULT')
    binding = result['binding']
    expect(type(binding) is dict and set(binding) == {'container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'}
        and binding['container_id'] == created_facts['id'] and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
        and all(binding[key] == context[key] for key in ['source_sha', 'freeze_sha256'])
        and binding['created_sha256'] == digest((evidence / (proof + '-created.json')).read_bytes()), 'NEW_LIFECYCLE_FULL_FIVE_KEY_SOURCE_BINDING')
    shutdown = load(evidence / (proof + '-shutdown.json'))
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'phase', 'fatal', 'type', 'file', 'line', 'primary_failure',
        'cleanup_failure', 'memory_limit', 'peak_bytes'} and type(shutdown['schema']) is int and shutdown['schema'] == 1
        and shutdown['phase'] == phase and shutdown['fatal'] is False and shutdown['primary_failure'] is None
        and shutdown['cleanup_failure'] is None and type(shutdown['type']) is int and type(shutdown['file']) is str
        and type(shutdown['line']) is int and type(shutdown['memory_limit']) is str
        and type(shutdown['peak_bytes']) is int and shutdown['peak_bytes'] > 0, 'NEW_LIFECYCLE_REAL_NONFATAL_SHUTDOWN')
    return result


def verify_aftersale_review_public_upload_root(context, cid, binding, required=True):
    expect(type(required) is bool and type(cid) is str and re.fullmatch(r'[a-f0-9]{64}', cid) is not None,
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_FIXED_REQUIRED_FLAG_AND_REAL_CID')
    path = Path(context['run_dir']) / 'evidence' / 'ms4-aftersale-review-public-upload-root-prepared.json'
    if not path.exists() and not path.is_symlink():
        expect(required is False, 'AFTERSALE_PUBLIC_UPLOAD_ROOT_PREPARATION_REQUIRED_BEFORE_NORMAL_BUSINESS')
        return None
    expect(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= 16384,
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_PRESENT_PROOF_REGULAR_BOUNDED')
    value = load(path)
    keys = {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'source_binding_sha256',
        'configuration', 'before', 'after', 'created', 'existing_root_native_unchanged'}
    expect(type(value) is dict and set(value) == keys and type(value['schema']) is int and value['schema'] == 1
        and value['scope'] == 'MS4_AFTERSALE_REVIEW_LOCAL_PUBLIC_ROOT_PREPARATION'
        and all(value[key] == context[key] == binding[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and value['container_id'] == cid == binding['container_id']
        and value['source_binding_sha256'] == digest((path.parent / AFTERSALE_REVIEW['binding']).read_bytes()),
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_EXACT_TWELVE_FIELDS_REAL_IDENTITY_SOURCE_BINDING')
    configuration = value['configuration']
    configuration_keys = {'configuration_preloaded', 'configuration_loaded', 'type_is_local', 'url_is_uploads',
        'root_is_expected', 'parent_is_directory', 'parent_is_nonlink', 'parent_is_canonical', 'parent_writable'}
    expect(type(configuration) is dict and set(configuration) == configuration_keys
        and all(type(item) is bool for item in configuration.values())
        and all(configuration[key] is True for key in configuration_keys - {'configuration_preloaded'}),
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_ACTUAL_CONFIGURATION_LOADED_AND_EXACT_OWN_LOCAL_PARENT')
    before = value['before']; after = value['after']
    before_keys = {'exists', 'is_directory', 'is_link', 'canonical_expected', 'writable'}
    expect(type(before) is dict and set(before) == before_keys and all(type(item) is bool for item in before.values())
        and type(after) is dict and set(after) == before_keys | {'mode_is_0700'} and all(type(item) is bool for item in after.values())
        and type(value['created']) is bool and type(value['existing_root_native_unchanged']) is bool,
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_EXACT_NATIVE_OBSERVATION_BOOLEAN_SHAPES')
    ready = {'exists': True, 'is_directory': True, 'is_link': False, 'canonical_expected': True, 'writable': True}
    expect({key: after[key] for key in before_keys} == ready,
        'AFTERSALE_PUBLIC_UPLOAD_ROOT_ACTUAL_FINAL_READY_CANONICAL_NONLINK_WRITABLE_DIRECTORY')
    if value['created']:
        expect(before == {key: False for key in before_keys} and after['mode_is_0700'] is True
            and value['existing_root_native_unchanged'] is False,
            'AFTERSALE_PUBLIC_UPLOAD_ROOT_ONLY_ACTUAL_ABSENT_OWN_DIRECTORY_CREATED_ONCE_MODE0700')
    else:
        expect(before == ready and value['existing_root_native_unchanged'] is True,
            'AFTERSALE_PUBLIC_UPLOAD_ROOT_EXISTING_DIRECTORY_NATIVE_FIELDS_UNCHANGED_NO_MODE_REWRITE')
    return value

def aftersale_review_public_names():
    names = fulfillment_history_names() + ['fulfillment-normal-predecessor-history.json',
        'fulfillment-normal-remove-native.json', 'fulfillment-normal-removed.json', 'aftersale-review-normal-formal-manifest.json',
        'aftersale-review-normal-created.json', 'aftersale-review-normal-terminal.json', 'ms4-aftersale-review-normal-source-binding.json',
        'aftersale-review-normal-environment.json', 'aftersale-review-normal-environment-native.json',
        'ms4-aftersale-review-normal.json', 'aftersale-review-normal-shutdown.json',
        'ms4-aftersale-review-public-upload-root-prepared.json']
    for proof in ['register-aftersale-review-normal', 'authorize-aftersale-review-cleanup']:
        names += [proof + suffix for suffix in ['-created.json', '-terminal.json', '-result.json', '-shutdown.json']]
    expect(len(names) == len(set(names)) == 85, 'AFTERSALE_REVIEW_EIGHTY_FIVE_COMPLETE_PUBLIC_ORIGINALS')
    return sorted(names)


def aftersale_review_public_hashes(context):
    return public_hashes(context, aftersale_review_public_names())


def verify_aftersale_review_archive(value, basename, scope):
    expect(type(value) is dict and set(value) == {'path_basename', 'scope', 'bytes', 'sha256', 'native_archive_bytes', 'native_archive_sha256'}
        and value['path_basename'] == basename and value['scope'] == scope
        and type(value['bytes']) is int and 0 < value['bytes'] <= 100663296
        and type(value['native_archive_bytes']) is int and 0 < value['native_archive_bytes'] <= 67108864
        and paid_hash(value['sha256']) and paid_hash(value['native_archive_sha256']), 'NEW_NORMAL_REAL_PRIVATE_DESCRIPTOR_ONLY_NO_ARCHIVE_BODY')


def verify_aftersale_review_child(child):
    expect(type(child) is dict and set(child) == {'stopped', 'group', 'remaining_members', 'listener_open', 'first_terminal',
        'term_attempted', 'stop_signal', 'proc_close_exit', 'streams'} and child['stopped'] is True
        and child['remaining_members'] == [] and child['listener_open'] is False and type(child['term_attempted']) is bool
        and (child['stop_signal'] is None or type(child['stop_signal']) is int and child['stop_signal'] == 15)
        and (child['stop_signal'] is None or child['term_attempted'] is True)
        and type(child['proc_close_exit']) is int, 'NEW_NORMAL_REAL_REGISTERED_HTTP_GROUP_AND_SAME_HANDLE_CLOSED')
    group = child['group']; terminal = child['first_terminal']
    expect(type(group) is dict and set(group) == {'leader_pid', 'pgid', 'sid', 'leader_starttime'}
        and all(type(group[key]) is int and group[key] > 0 for key in ['leader_pid', 'pgid', 'sid'])
        and group['leader_pid'] == group['pgid'] == group['sid'] and paid_decimal(group['leader_starttime'])
        and group['leader_starttime'] != '0', 'NEW_NORMAL_ACTUAL_OWN_PGID_SID_AND_REGISTERED_STARTTIME')
    expect(type(terminal) is dict and set(terminal) == {'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'}
        and terminal['pid'] == group['leader_pid'] and type(terminal['pid']) is int and terminal['running'] is False
        and type(terminal['signaled']) is bool and terminal['stopped'] is False
        and all(type(terminal[key]) is int for key in ['exitcode', 'termsig', 'stopsig']) and terminal['termsig'] != 9,
        'NEW_NORMAL_ORIGINAL_FIRST_NATIVE_TERMINAL_NEVER_FORCE_OR_FAKE_ZERO')
    streams = child['streams']
    expect(type(streams) is list and len(streams) == 2 and all(type(row) is dict and set(row) == {'bytes', 'sha256'}
        and type(row['bytes']) is int and 0 <= row['bytes'] <= 16777216 and paid_hash(row['sha256'])
        and (row['bytes'] != 0 or row['sha256'] == digest(b'')) for row in streams), 'NEW_NORMAL_COMPLETE_BOUNDED_HTTP_STDOUT_AND_STDERR_HASHES')


def verify_aftersale_review_business(business, prior):
    keys = {'authenticated_owner_uid', 'merchant_id', 'employee_id', 'limited_admin_id', 'original_root_id', 'new_root_id',
        'new_items', 'new_quantity', 'normal_action_counts', 's2_operations', 'same_body_replays', 'new_refunds', 'private_evidence',
        'review', 'http', 'paid_baseline', 'final_baseline', 'independent_database_oracle', 'independent_redis_oracle',
        'independent_files_oracle', 'prior_task6_preserved', 'original_private_inputs_preserved', 'normal_business_decisions', 'excluded_acceptance', 'customer_form'}
    expect(type(business) is dict and set(business) == keys and all(type(business[key]) is int and 1 <= business[key] <= 2147483647
        for key in ['authenticated_owner_uid', 'merchant_id', 'employee_id', 'limited_admin_id', 'original_root_id', 'new_root_id'])
        and business['authenticated_owner_uid'] == prior['business']['authenticated_owner_uid']
        and business['original_root_id'] == prior['business']['original_root_id'] and business['new_root_id'] != business['original_root_id'],
        'NEW_NORMAL_EXACT_TWENTY_FOUR_ACTUAL_BUSINESS_FIELDS_AND_REAL_PRIOR_OWNER')
    counts = {'new_items': 2, 'new_quantity': 3, 's2_operations': 2, 'same_body_replays': 15, 'new_refunds': 5}
    expect(all(type(business[key]) is int and business[key] == value for key, value in counts.items())
        and paid_equal(business['normal_action_counts'], {'apply': 5, 'withdraw': 1, 'reject': 2, 'approve': 2, 'express': 1, 'accept_return': 2})
        and all(business[key] is True for key in ['independent_database_oracle', 'independent_redis_oracle', 'independent_files_oracle',
            'prior_task6_preserved', 'original_private_inputs_preserved'])
        and business['normal_business_decisions'] == {'merchant_reject': 'HTTP200_SUCCESS', 'platform_reject': 'HTTP200_SUCCESS'}
        and business['excluded_acceptance'] == {'mobile_workbench': 'NOT RUN', 'three_end_DOM': 'NOT RUN', 'worker': 'NOT RUN',
            'finance_real_channel': 'NOT RUN', 'nonempty_history': 'NOT RUN', 'stopped_samples': 'NOT RUN',
            'push_deploy': 'NOT RUN', 'full_acceptance': 'INCOMPLETE'}, 'NEW_NORMAL_ACTUAL_FINITE_ACTIONS_REPLAYS_ORACLES_AND_EXCLUSIONS')
    evidence = business['private_evidence']
    expect(type(evidence) is dict and set(evidence) == {'files', 'bytes', 'sha256', 'private_preparation', 'tickets'}
        and type(evidence['files']) is int and evidence['files'] == 1 and type(evidence['bytes']) is int
        and 0 < evidence['bytes'] <= 5242880 and paid_hash(evidence['sha256']), 'NEW_NORMAL_REAL_ONE_NONEMPTY_PRIVATE_IMAGE')
    prepared = evidence['private_preparation']
    expect(type(prepared) is dict and set(prepared) == {'dev', 'ino', 'mode', 'uid', 'gid', 'nlink'}
        and all(type(value) is int for value in prepared.values()) and prepared['dev'] >= 0 and prepared['ino'] > 0
        and prepared['mode'] == 16832 and prepared['uid'] == 0 and prepared['gid'] >= 0 and prepared['nlink'] >= 1,
        'NEW_NORMAL_ACTUAL_EMPTY_VOLUME_OWNER_AND_DIRECTORY_0700_PREPARATION')
    tickets = evidence['tickets']
    expect(type(tickets) is dict and set(tickets) == {'customer', 'merchant', 'platform'}, 'NEW_NORMAL_THREE_REAL_SESSION_TICKET_READERS')
    for value in tickets.values():
        expect(type(value) is dict and set(value) == {'ticket_created', 'content_http', 'bytes', 'sha256', 'consumed'}
            and value['ticket_created'] is True and type(value['content_http']) is int and value['content_http'] == 200
            and type(value['bytes']) is int and value['bytes'] == evidence['bytes'] and value['sha256'] == evidence['sha256']
            and value['consumed'] is True, 'NEW_NORMAL_SESSION_BOUND_TICKET_CONTENT_AND_ATOMIC_CONSUMPTION')
    review = business['review']
    expect(type(review) is dict and set(review) == {'root_order_id', 'source_item_id', 'review_id', 'image_source', 'customer_asset_id', 'image_bytes',
        'image_sha256', 'create_same_body_replay', 'status_sequence', 'merchant_reply_changed_columns', 'public', 'pending_public'}
        and type(review['root_order_id']) is int and review['root_order_id'] == business['original_root_id']
        and all(type(review[key]) is int and 1 <= review[key] <= 2147483647 for key in ['source_item_id', 'review_id'])
        and review['image_source'] == 'customer_review' and paid_decimal(review['customer_asset_id'], 18446744073709551615)
        and review['customer_asset_id'] != '0'
        and type(review['image_bytes']) is int and review['image_bytes'] == evidence['bytes'] and review['image_sha256'] == evidence['sha256']
        and review['create_same_body_replay'] is True and paid_equal(review['status_sequence'], [0, 1])
        and type(review['merchant_reply_changed_columns']) is int and review['merchant_reply_changed_columns'] == 3,
        'NEW_NORMAL_REAL_ORIGINAL_SOURCE_ITEM_REVIEW_UPLOAD_MODERATION_AND_REPLY')
    pending = review['pending_public']
    expect(paid_equal(pending, {'review_id': review['review_id'], 'status': 0,
        'native_predicate_count': 0, 'list_requests': 1, 'count_requests': 1,
        'anonymous_requests': 2, 'full_native_zero_diff': True}),
        'NEW_NORMAL_PENDING_REVIEW_EXACT_ANONYMOUS_READS_AND_FULL_NATIVE_ZERO')
    public = review['public']
    values = {'projection_fields': 12, 'native_predicate_count': 1, 'list_requests': 5, 'count_requests': 1, 'anonymous_image_requests': 1}
    expect(type(public) is dict and set(public) == set(values) | {'image_bytes', 'image_sha256', 'same_native_predicate', 'full_raw_image_bytes'}
        and all(type(public[key]) is int and public[key] == value for key, value in values.items())
        and type(public['image_bytes']) is int and public['image_bytes'] == evidence['bytes'] and public['image_sha256'] == evidence['sha256']
        and public['same_native_predicate'] is True and public['full_raw_image_bytes'] is True, 'NEW_NORMAL_PUBLIC_TWELVE_FIELD_PREDICATE_AND_ACTUAL_ANONYMOUS_PNG')
    verify_aftersale_review_archive(business['paid_baseline'], 'ms4-aftersale-review-paid-baseline.json', 'MS4_AFTERSALE_REVIEW_NEW_PAID_NATIVE_FACTS')
    verify_aftersale_review_archive(business['final_baseline'], 'ms4-aftersale-review-stage-final.json', 'MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS')
    expect(business['paid_baseline']['sha256'] != business['final_baseline']['sha256'], 'NEW_NORMAL_TWO_DISTINCT_REAL_CREATE_ONLY_NATIVE_ARCHIVES')
    http = business['http']
    expect(type(http) is dict and set(http) == {'calls', 'requests'} and type(http['calls']) is int
        and type(http['requests']) is list and http['calls'] == len(http['requests']) and http['calls'] > 0,
        'NEW_NORMAL_ALL_ACTUAL_HTTP_CALLS_PRESERVED')
    requests = http['requests']; commands = {}; ticket_reads = 0; public_images = 0; review_writes = []; lists = 0; configs = 0
    for request in requests:
        expect(type(request) is dict and set(request) == {'method', 'path', 'http', 'bytes', 'sha256', 'request_bytes', 'request_sha256', 'actual_interval'}
            and request['method'] in ['GET', 'POST', 'PUT'] and type(request['path']) is str and len(request['path']) < 2048
            and request['path'].startswith('/') and all(value not in request['path'] for value in ['..', '\\', '#', '\n', '\r'])
            and type(request['http']) is int and request['http'] == 200 and type(request['bytes']) is int and 0 <= request['bytes'] <= 6291456
            and paid_hash(request['sha256']) and type(request['request_bytes']) is int and 0 <= request['request_bytes'] <= 6291456
            and paid_hash(request['request_sha256']) and (request['request_bytes'] != 0 or request['request_sha256'] == digest(b''))
            and type(request['actual_interval']) is list and len(request['actual_interval']) == 2
            and all(type(value) is int and 0 < value <= 4294967295 for value in request['actual_interval'])
            and request['actual_interval'][0] <= request['actual_interval'][1], 'NEW_NORMAL_FINITE_PUBLIC_HTTP_RECEIPT_FULL_BODY_HASH_NO_TOKEN')
        leaf = request['path'].split('?', 1)[0]
        is_command = request['method'] == 'POST' and (re.fullmatch(r'/api/order/refund/(?:apply/[1-9][0-9]*|cancel/[A-Za-z0-9]+|express)', leaf)
            or re.fullmatch(r'/(?:merchantapi|adminapi/marketplace)/aftersales/[1-9][0-9]*/(?:approve|reject|return-accept)', leaf)
            or re.fullmatch(r'/merchantapi/orders/[1-9][0-9]*/shipments', leaf)
            or re.fullmatch(r'/api/order/fulfillment/packages/[A-Za-z0-9]+/receipt', leaf))
        if is_command:
            expect(request['request_bytes'] > 0, 'NEW_NORMAL_COMMAND_FROZEN_NONEMPTY_REAL_JSON_BYTES')
            identity = (leaf, request['request_bytes'], request['request_sha256'])
            commands[identity] = commands.get(identity, 0) + 1
            if leaf.startswith('/api/order/refund/apply/'):
                expect(leaf == '/api/order/refund/apply/' + str(business['new_root_id']), 'NEW_NORMAL_APPLY_REAL_NEW_ROOT_LOCATOR')
        if request['method'] == 'GET' and re.fullmatch(r'/(?:api/order/refund/files|merchantapi/aftersale-files|adminapi/marketplace/aftersale-files)/[1-9][0-9]*/content', leaf):
            expect(request['bytes'] == evidence['bytes'] and request['sha256'] == evidence['sha256'], 'NEW_NORMAL_THREE_HTTP_RAW_PRIVATE_IMAGE_DIGESTS')
            ticket_reads += 1
        if request['method'] == 'GET' and leaf.startswith('/uploads/store/comment/'):
            expect(request['bytes'] == evidence['bytes'] and request['sha256'] == evidence['sha256'], 'NEW_NORMAL_ANONYMOUS_RAW_PUBLIC_IMAGE_DIGEST')
            public_images += 1
        if request['method'] == 'POST' and re.fullmatch(r'/api/order/reviews/[1-9][0-9]*/[1-9][0-9]*', leaf):
            expect(leaf == '/api/order/reviews/%d/%d' % (review['root_order_id'], review['source_item_id']), 'NEW_NORMAL_REVIEW_REAL_ORIGINAL_ROOT_SOURCE_PK')
            review_writes.append((request['request_bytes'], request['request_sha256']))
        if request['method'] == 'GET' and leaf.startswith('/api/reply/list/'):
            lists += 1
        if request['method'] == 'GET' and leaf.startswith('/api/reply/config/'):
            configs += 1
    expect(len(commands) == 15 and sum(commands.values()) == 30 and all(count == 2 for count in commands.values())
        and ticket_reads == 3 and public_images == 1 and len(review_writes) == 2 and review_writes[0] == review_writes[1]
        and lists == public['list_requests'] + pending['list_requests']
        and configs == public['count_requests'] + pending['count_requests'], 'NEW_NORMAL_ACTUAL_FIFTEEN_IDENTICAL_BODY_REPLAYS_THREE_PRIVATE_READS_AND_PUBLIC_REVIEW_MULTIPLICITY')

    verify_customer_form_saved_readers(business['customer_form'])

def verify_aftersale_review_result(context, native_required=False, saved_formal=None):
    evidence = Path(context['run_dir']) / 'evidence'
    prior = verify_fulfillment_history(context)
    verify_fulfillment_removed(context)
    verify_paid_history(context, native_required)
    facts = normal_terminal(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'], native_required)
    binding = verify_aftersale_review_binding(context, facts['id'], native_required)
    verify_aftersale_review_public_upload_root(context, facts['id'], binding)
    verify_aftersale_review_helper(context, 'register-aftersale-review-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    formal = load(evidence / 'aftersale-review-normal-formal-manifest.json')
    fulfillment_expected_formal(context, formal, AFTERSALE_REVIEW['role'], AFTERSALE_REVIEW['proof'])
    if saved_formal is None:
        expect(paid_equal(formal, load(Path(context['run_dir']) / 'manifest.json')), 'NEW_NORMAL_REAL_SAME_FOUR_ROLE_FORMAL')
    else:
        expect(native_required is False and paid_equal(saved_formal, formal), 'ONLY_ACTUAL_SAVED_ORIGINAL_NORMAL_FORMAL_FOR_REMOVED_HISTORY')
    result = load(evidence / AFTERSALE_REVIEW['result'])
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'prerequisites_sha256',
        'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'metadata_observer',
        'business', 'child', 'diagnostic', 'mobile_workbench_HTTP_DOM', 'three_end_DOM', 'worker', 'finance', 'full_acceptance', 'stopped_samples', 'assertions'},
        'NEW_NORMAL_EXACT_TWENTY_NINE_REAL_RESULT_KEYS')
    expect(type(result['schema']) is int and result['schema'] == 1 and result['scope'] == AFTERSALE_REVIEW['result_scope']
        and result['status'] == AFTERSALE_REVIEW['status'] and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and result['container_id'] == facts['id'] and result['native_created_sha256'] == digest((evidence / 'aftersale-review-normal-created.json').read_bytes())
        and result['source_binding_sha256'] == digest((evidence / AFTERSALE_REVIEW['binding']).read_bytes())
        and result['environment_sha256'] == digest((evidence / 'aftersale-review-normal-environment.json').read_bytes())
        and result['environment_native_sha256'] == digest((evidence / 'aftersale-review-normal-environment-native.json').read_bytes())
        and paid_equal(result['prerequisites_sha256'], binding['prerequisites_sha256']) and result['phase'] == 'final_source'
        and type(result['entry_return_code']) is int and result['entry_return_code'] == 0 and result['termination'] == 'NORMAL_RETURN'
        and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and result['diagnostic'] is None and type(result['assertions']) is int and result['assertions'] > 0
        and all(result[key] == 'NOT RUN' for key in ['mobile_workbench_HTTP_DOM', 'three_end_DOM', 'worker', 'finance', 'stopped_samples'])
        and result['full_acceptance'] == 'INCOMPLETE', 'NEW_NORMAL_SOURCE_SUCCESS_REMAINS_DISTINCT_FROM_ACTUAL_OUTER_NATIVE_ZERO_AND_ACCEPTANCE')
    verify_fulfillment_observer(result)
    verify_aftersale_review_business(result['business'], prior)
    verify_aftersale_review_child(result['child'])
    shutdown = load(evidence / 'aftersale-review-normal-shutdown.json')
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase',
        'completed', 'result_published', 'fatal', 'termination_requested', 'primary_failure', 'cleanup_failure', 'native_exit', 'native_exit_observation'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['scope'] == 'MS4_AFTERSALE_REVIEW_NORMAL_SHUTDOWN'
        and all(shutdown[key] == result[key] for key in ['run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase'])
        and shutdown['completed'] is True and shutdown['result_published'] is True and shutdown['fatal'] is False
        and shutdown['termination_requested'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and shutdown['native_exit'] is None and shutdown['native_exit_observation'] == 'HOST_NATIVE_REQUIRED', 'NEW_NORMAL_EXACT_REAL_FIFTEEN_FIELD_SHUTDOWN')
    expect(paid_equal(binding['prerequisites_sha256'], aftersale_review_prerequisite_hashes(context)), 'NEW_NORMAL_ALL_THIRTY_FOUR_ORIGINAL_INPUTS_RECHECKED')
    return result

def aftersale_review_history_names():
    names = aftersale_review_public_names() + ['aftersale-review-public-file-keep.json']
    expect(len(names) == len(set(names)) == 86, 'AFTERSALE_REVIEW_COMPLETE_POST_C3_EIGHTY_FIVE_PLUS_KEEP')
    return sorted(set(names))


def verify_aftersale_review_keep(context, result):
    evidence = Path(context['run_dir']) / 'evidence'
    value = load(evidence / 'aftersale-review-public-file-keep.json')
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256',
        'container_id', 'source_binding_sha256', 'final_baseline_sha256', 'archive', 'file_map_sha256'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP'
        and all(value[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and value['container_id'] == result['container_id']
        and value['source_binding_sha256'] == result['source_binding_sha256']
        and value['source_binding_sha256'] == digest((evidence / AFTERSALE_REVIEW['binding']).read_bytes())
        and value['final_baseline_sha256'] == result['business']['final_baseline']['sha256']
        and paid_hash(value['file_map_sha256']), 'ORIGINAL_NORMAL_KEEP_COMPLETE_IDENTITY_SOURCE_AND_FINAL_SEAL_FILE_HASH')
    verify_aftersale_review_archive(value['archive'], 'ms4-aftersale-review-public-file-keep.json',
        'MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP_NATIVE_FACTS')
    return value


def verify_aftersale_review_history(context):
    evidence = Path(context['run_dir']) / 'evidence'
    history = load(evidence / 'aftersale-review-normal-predecessor-history.json')
    expect(type(history) is dict and set(history) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256',
        'context_sha256', 'manifest_sha256', 'public_sha256'} and type(history['schema']) is int and history['schema'] == 1
        and history['scope'] == 'MS4_AFTERSALE_REVIEW_NORMAL_REAL_PREDECESSOR_HISTORY'
        and all(history[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and history['context_sha256'] == digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes())
        and history['manifest_sha256'] == digest((evidence / 'aftersale-review-normal-formal-manifest.json').read_bytes())
        and paid_equal(history['public_sha256'], public_hashes(context, aftersale_review_history_names())),
        'COMPLETE_ORIGINAL_NORMAL_EIGHT_FIELD_EIGHTY_FIVE_FILE_HISTORY_SEAL')
    formal = load(evidence / 'aftersale-review-normal-formal-manifest.json')
    result = verify_aftersale_review_result(context, False, formal)
    verify_aftersale_review_helper(context, 'authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    verify_aftersale_review_keep(context, result)
    expect(paid_equal(history['public_sha256'], public_hashes(context, aftersale_review_history_names())),
        'ALL_ORIGINAL_NORMAL_AND_KEEP_BYTES_UNCHANGED_AFTER_COMPLETE_BUSINESS_CONSUMER')
    return result


def verify_aftersale_review_removed(context):
    evidence = Path(context['run_dir']) / 'evidence'
    original = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
    normal_terminal(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'], False)
    value = load(evidence / 'aftersale-review-normal-removed.json')
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id',
        'created_sha256', 'terminal_sha256', 'native_remove', 'absent_after', 'target_role'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_AFTERSALE_REVIEW_NORMAL_EXACT_REMOVAL'
        and all(value[key] == context[key] for key in ['run_id', 'source_sha']) and value['container_id'] == original['id']
        and value['target_role'] == AFTERSALE_REVIEW['role'] and value['absent_after'] is True
        and value['created_sha256'] == digest((evidence / 'aftersale-review-normal-created.json').read_bytes())
        and value['terminal_sha256'] == digest((evidence / 'aftersale-review-normal-terminal.json').read_bytes()),
        'ORIGINAL_NORMAL_EXACT_CREATED_TERMINAL_REMOVAL_TEN_FIELDS')
    receipt = value['native_remove']
    expect(type(receipt) is dict and set(receipt) == {'argv', 'native_exit', 'stdout_sha256', 'stderr_sha256', 'same_handle_terminal'}
        and receipt['argv'] == ['docker', 'rm', original['id']] and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and receipt['stdout_sha256'] == digest((original['id'] + '\n').encode('ascii'))
        and receipt['stderr_sha256'] == digest(b''), 'ORIGINAL_NORMAL_NONFORCE_EXACT_RM_AND_COMPLETE_STREAM_HASHES')
    terminal = receipt['same_handle_terminal']
    expect(type(terminal) is dict and set(terminal) == {'pid', 'returncode', 'wait_returncode'}
        and type(terminal['pid']) is int and terminal['pid'] > 0
        and type(terminal['returncode']) is int and terminal['returncode'] == 0
        and type(terminal['wait_returncode']) is int and terminal['wait_returncode'] == 0,
        'ORIGINAL_NORMAL_REAL_PYTHON_SAME_POPEN_NATIVE_TERMINAL')
    native = load(evidence / 'aftersale-review-normal-remove-native.json')
    expect(type(native) is dict and set(native) == {'schema', 'native', 'stdout_bytes', 'stderr_bytes'}
        and type(native['schema']) is int and native['schema'] == 1 and paid_equal(native['native'], receipt)
        and type(native['stdout_bytes']) is int and native['stdout_bytes'] == len(original['id']) + 1
        and type(native['stderr_bytes']) is int and native['stderr_bytes'] == 0,
        'ORIGINAL_NORMAL_COMPLETE_NATIVE_RM_RECEIPT_PRESERVED')
    return value


def seal_aftersale_review_predecessor(context):
    evidence = Path(context['run_dir']) / 'evidence'
    collect(context, proof_expected(context, AFTERSALE_REVIEW['proof']), publish=False)
    result = verify_aftersale_review_result(context, True)
    verify_aftersale_review_helper(context, 'authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    verify_aftersale_review_keep(context, result)
    formal = (evidence / 'aftersale-review-normal-formal-manifest.json').read_bytes()
    expect(formal == (Path(context['run_dir']) / 'manifest.json').read_bytes(), 'ACTUAL_ORIGINAL_NORMAL_FORMAL_BEFORE_EXACT_REMOVAL')
    hashes = public_hashes(context, aftersale_review_history_names())
    value = {'schema': 1, 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_REAL_PREDECESSOR_HISTORY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes()),
        'manifest_sha256': digest(formal), 'public_sha256': hashes}
    new(evidence / 'aftersale-review-normal-predecessor-history.json', value)
    expect(paid_equal(public_hashes(context, aftersale_review_history_names()), hashes),
        'ALL_ORIGINAL_NORMAL_AND_KEEP_BYTES_UNCHANGED_DURING_SEAL')
    return result


def retire_aftersale_review(context):
    evidence = Path(context['run_dir']) / 'evidence'
    names = ['aftersale-review-normal-predecessor-history.json', 'aftersale-review-normal-remove-native.json',
        'aftersale-review-normal-removed.json']
    expect(all(not (evidence / name).exists() and not (evidence / name).is_symlink() for name in names),
        'ORIGINAL_NORMAL_SEAL_AND_EXACT_REMOVE_ONCE_NO_RETRY')
    original = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
    expected = proof_expected(context, AFTERSALE_REVIEW['proof'])
    collect(context, expected)
    seal_aftersale_review_predecessor(context)
    terminal = normal_terminal(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'], True)
    argv = ['docker', 'rm', original['id']]
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate()
    returncode = process.returncode
    waited = process.wait()
    receipt = {'argv': argv, 'native_exit': returncode, 'stdout_sha256': digest(stdout), 'stderr_sha256': digest(stderr),
        'same_handle_terminal': {'pid': process.pid, 'returncode': returncode, 'wait_returncode': waited}}
    new(evidence / 'aftersale-review-normal-remove-native.json', {'schema': 1, 'native': receipt,
        'stdout_bytes': len(stdout), 'stderr_bytes': len(stderr)})
    expect(type(returncode) is int and returncode == 0 and type(waited) is int and waited == 0
        and stdout == (original['id'] + '\n').encode('ascii') and stderr == b'',
        'ORIGINAL_NORMAL_ACTUAL_SAME_HANDLE_EXACT_NONFORCE_REMOVE_NATIVE_ZERO')
    remaining = {cid: role for cid, role in expected.items() if cid != original['id']}
    collect(context, remaining)
    expect(original['id'] not in active(context), 'ORIGINAL_NORMAL_FRESH_NORMAL_LISTING_ABSENT_AFTER_REMOVE')
    global_consumers(context, set(remaining))
    new(evidence / 'aftersale-review-normal-removed.json', {'schema': 1, 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_EXACT_REMOVAL',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': original['id'],
        'created_sha256': digest((evidence / 'aftersale-review-normal-created.json').read_bytes()),
        'terminal_sha256': digest((evidence / 'aftersale-review-normal-terminal.json').read_bytes()),
        'native_remove': receipt, 'absent_after': True, 'target_role': AFTERSALE_REVIEW['role']})
    verify_aftersale_review_removed(context)
    verify_aftersale_review_history(context)
    return {'status': 'AFTERSALE_REVIEW_NATIVE_ZERO_EXACT_NONFORCE_REMOVED_ONLY', 'container_id': original['id']}


def aftersale_review_stage(name, context_file):
    context = current(context_file); root = Path(context['run_dir']); evidence = root / 'evidence'
    base = base_roles(context)
    paid = bound_created(context, PAID['proof'], PAID['role'])
    paid_base = base | {paid['id']: PAID['role']}
    verify_fulfillment_history(context)
    removed = verify_fulfillment_removed(context)
    expect(removed['container_id'] not in active(context), 'TASK6_ABSENT_BEFORE_SERIAL_NEW_ROLE')
    verify_paid_history(context, True)
    if name == 'aftersale-review-create':
        expect(not (evidence / 'aftersale-review-normal-created.json').exists(), 'NEW_NORMAL_CANONICAL_CREATED_ONCE')
        collect(context, paid_base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', AFTERSALE_REVIEW['role']], name, AFTERSALE_REVIEW['proof'])
        cid = created(context, AFTERSALE_REVIEW['role'], AFTERSALE_REVIEW['proof'])
        registered = helper(context, 'register-aftersale-review-normal', 'register', paid_base | {cid: AFTERSALE_REVIEW['role']})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_NEW_NORMAL_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
        return aftersale_review_stage('aftersale-review-bind', context_file)
    if name in ['aftersale-review-bind', 'aftersale-review-start']:
        verify_aftersale_review_helper(context, 'register-aftersale-review-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        original = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
        cid = original['id']; fresh = inspect(context, cid, name + '-precheck')
        owned(context, fresh, AFTERSALE_REVIEW['role']); same_created(original, fresh)
        expect(fresh['status'] == 'created' and type(fresh['pid']) is int and fresh['pid'] == 0, 'SAME_NEW_NORMAL_UNSTARTED_NATIVE_INSTANCE')
        collect(context, paid_base | {cid: AFTERSALE_REVIEW['role']})
        formal = load(root / 'manifest.json')
        fulfillment_expected_formal(context, formal, AFTERSALE_REVIEW['role'], AFTERSALE_REVIEW['proof'])
        if name == 'aftersale-review-bind':
            exact_copy(root / 'manifest.json', evidence / 'aftersale-review-normal-formal-manifest.json')
            record_aftersale_review_binding(context, cid)
            return {'status': 'NEW_NORMAL_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY', 'container_id': cid}
        expect(paid_equal(formal, load(evidence / 'aftersale-review-normal-formal-manifest.json')), 'NEW_NORMAL_SAME_ORIGINAL_FULL_FORMAL_BEFORE_START')
        verify_aftersale_review_binding(context, cid, True)
        native(context, ['start', cid], name)
        return {'status': 'AFTERSALE_REVIEW_NORMAL_STARTED_ONLY', 'container_id': cid}
    if name == 'authorize-aftersale-review-close':
        verify_aftersale_review_result(context, True)
        return helper(context, 'authorize-aftersale-review-cleanup', 'authorize-cleanup', proof_expected(context, AFTERSALE_REVIEW['proof']))
    if name == 'aftersale-review-retire':
        return retire_aftersale_review(context)
    raise RuntimeError('UNKNOWN_FIXED_AFTERSALE_REVIEW_STAGE')

def worker_prerequisite_names():
    names = aftersale_review_history_names() + ['aftersale-review-normal-predecessor-history.json',
        'aftersale-review-normal-remove-native.json', 'aftersale-review-normal-removed.json']
    expect(len(names) == len(set(names)) == 89, 'WORKER_EXACT_EIGHTY_NINE_ORIGINAL_PREDECESSORS')
    return sorted(names)


def worker_prerequisite_hashes(context):
    verify_aftersale_review_history(context)
    verify_aftersale_review_removed(context)
    return public_hashes(context, worker_prerequisite_names())


def worker_public_names():
    names = worker_prerequisite_names() + ['worker-normal-created.json', 'worker-normal-terminal.json',
        WORKER['binding'], 'worker-normal-environment.json', 'worker-normal-environment-native.json',
        'worker-normal-formal-manifest.json', WORKER['result'], 'worker-normal-shutdown.json']
    for proof in ['register-worker-normal', 'authorize-worker-cleanup']:
        names += [proof + suffix for suffix in ['-created.json', '-terminal.json', '-result.json', '-shutdown.json']]
    expect(len(names) == len(set(names)) == 105, 'WORKER_EXACT_ONE_HUNDRED_FIVE_PUBLIC_MEMBERS')
    return sorted(names)


def worker_public_hashes(context):
    return public_hashes(context, worker_public_names())


def worker_environment(context):
    return aftersale_environment(context)


def worker_environment_bytes(context):
    values = worker_environment(context)
    return (''.join(json.dumps(name + '=' + values[name], ensure_ascii=False, separators=(',', ':')) + '\n'
        for name in sorted(values))).encode('utf-8')


def worker_environment_observation(context, cid):
    process = subprocess.run(['docker', 'inspect', '--format', AFTERSALE_ENV_INSPECT, cid], capture_output=True)
    receipt = {'stage': 'worker-normal-public-environment-inspect', 'native_exit': process.returncode,
        'stdout_bytes': len(process.stdout), 'stdout_sha256': digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': digest(process.stderr), 'private_environment_logs_omitted': True}
    expect(type(process.returncode) is int and process.returncode == 0 and process.stderr == b''
        and process.stdout == worker_environment_bytes(context), 'WORKER_ACTUAL_SORTED_NINE_PUBLIC_ENVIRONMENT_LINES')
    lines = process.stdout.decode('utf-8').splitlines()
    expect(len(lines) == 9 and [json.loads(line).split('=', 1)[0] for line in lines] == AFTERSALE_ENV_NAMES,
        'WORKER_EXACT_EIGHT_NATIVE_NAMES_NO_DUPLICATES_OR_MISSING')
    values = {name: json.loads(line)[len(name) + 1:] for name, line in zip(AFTERSALE_ENV_NAMES, lines)}
    expect(paid_equal(values, worker_environment(context)), 'WORKER_ACTUAL_NINE_PUBLIC_ENVIRONMENT_VALUES')
    return values, receipt


def record_worker_environment(context, cid):
    values, receipt = worker_environment_observation(context, cid)
    evidence = Path(context['run_dir']) / 'evidence'
    new(evidence / 'worker-normal-environment-native.json', receipt)
    new(evidence / 'worker-normal-environment.json', {'schema': 1, 'scope': WORKER['environment_scope'],
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': cid,
        'freeze_sha256': context['freeze_sha256'], 'environment': values,
        'native_projection_sha256': receipt['stdout_sha256']})


def verify_worker_environment(context, cid, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    value = load(evidence / 'worker-normal-environment.json')
    receipt = load(evidence / 'worker-normal-environment-native.json')
    expected = worker_environment_bytes(context)
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id',
        'freeze_sha256', 'environment', 'native_projection_sha256'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == WORKER['environment_scope']
        and all(value[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and value['container_id'] == cid and paid_equal(value['environment'], worker_environment(context)),
        'WORKER_COMPLETE_EIGHT_FIELD_PUBLIC_ENVIRONMENT')
    expect(paid_equal(receipt, {'stage': 'worker-normal-public-environment-inspect', 'native_exit': 0,
        'stdout_bytes': len(expected), 'stdout_sha256': digest(expected), 'stderr_bytes': 0,
        'stderr_sha256': digest(b''), 'private_environment_logs_omitted': True})
        and value['native_projection_sha256'] == receipt['stdout_sha256'], 'WORKER_COMPLETE_SEVEN_FIELD_NATIVE_ENVIRONMENT')
    if native_required:
        values, fresh = worker_environment_observation(context, cid)
        expect(paid_equal(values, value['environment']) and paid_equal(fresh, receipt), 'WORKER_SAME_NATIVE_ENVIRONMENT_BEFORE_START')
    return value


def worker_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    rows = frozen['worker_source_files']
    entries = [row for row in rows if row['path'] == WORKER_ENTRY]
    expect(len(entries) == 1 and paid_equal(entries[0], source_row(context['repo_dir'], WORKER_ENTRY))
        and entries[0]['bytes'] == WORKER_ENTRY_BYTES and entries[0]['sha256'] == WORKER_ENTRY_SHA256,
        'WORKER_ACTUAL_COMPLETE_ENTRY_SOURCE')
    return {'schema': 1, 'scope': WORKER['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / 'worker-normal-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': entries[0]['sha256'], 'source_files': rows,
        'prerequisites_sha256': worker_prerequisite_hashes(context)}


def record_worker_binding(context, cid):
    record_worker_environment(context, cid)
    new(Path(context['run_dir']) / 'evidence' / WORKER['binding'], worker_binding(context, cid))


def verify_worker_binding(context, cid, native_required=False):
    actual = load(Path(context['run_dir']) / 'evidence' / WORKER['binding'])
    expect(type(actual) is dict and len(actual) == 13 and paid_equal(actual, worker_binding(context, cid)),
        'WORKER_COMPLETE_THIRTEEN_FIELD_SOURCE_BINDING')
    verify_worker_environment(context, cid, native_required)
    return actual


def verify_worker_helper(context, proof, wanted_status):
    expect((proof, wanted_status) in [('register-worker-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY'),
        ('authorize-worker-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')], 'WORKER_ONLY_TWO_FIXED_HELPERS')
    phase = 'register' if proof == 'register-worker-normal' else 'authorize-cleanup'
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, proof, 'audit-bootstrap')
    normal_terminal(context, proof, 'audit-bootstrap', False)
    expect(created_facts['command'] == ['/run/ms4-prep/ms4-initialize.php', phase], 'WORKER_REAL_INITIALIZER_HELPER_COMMAND')
    result = load(evidence / (proof + '-result.json'))
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'children',
        'private_cleanup', 'private_cleanup_verified', 'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_WORKER_LIFECYCLE_ONLY'
        and result['status'] == wanted_status and type(result['native_exit']) is int and result['native_exit'] == 0
        and all(result[key] == context[key] for key in ['run_id', 'source_sha']) and result['children'] == []
        and result['private_cleanup'] == [] and result['private_cleanup_verified'] is False
        and all(result[key] == 'NOT RUN' for key in ['App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance']),
        'WORKER_COMPLETE_THIRTEEN_FIELD_HELPER_RESULT')
    binding = result['binding']
    expect(type(binding) is dict and set(binding) == {'container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'}
        and binding['container_id'] == created_facts['id'] and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
        and all(binding[key] == context[key] for key in ['source_sha', 'freeze_sha256'])
        and binding['created_sha256'] == digest((evidence / (proof + '-created.json')).read_bytes()), 'WORKER_HELPER_EXACT_FIVE_KEY_BINDING')
    shutdown = load(evidence / (proof + '-shutdown.json'))
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'phase', 'fatal', 'type', 'file', 'line',
        'primary_failure', 'cleanup_failure', 'memory_limit', 'peak_bytes'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['phase'] == phase
        and shutdown['fatal'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and type(shutdown['type']) is int and type(shutdown['file']) is str and type(shutdown['line']) is int
        and type(shutdown['memory_limit']) is str and type(shutdown['peak_bytes']) is int and shutdown['peak_bytes'] > 0,
        'WORKER_HELPER_COMPLETE_NONFATAL_TEN_FIELD_SHUTDOWN')
    return result


def worker_keys(value, keys, code):
    expect(type(value) is dict and set(value) == set(keys), code)


def worker_int(value, minimum=0, maximum=9223372036854775807):
    expect(type(value) is int and minimum <= value <= maximum, 'WORKER_NATIVE_INTEGER')
    return value


def worker_path(value):
    expect(type(value) is str and 0 < len(value) <= 4096 and '\0' not in value and '\\' not in value
        and ':' not in value and not value.startswith('/') and all(part not in ['', '.', '..'] for part in value.split('/')),
        'WORKER_CANONICAL_RELATIVE_PUBLIC_PATH')
    return value


def worker_absolute(value):
    expect(type(value) is str and value.startswith('/') and '\0' not in value and '\\' not in value
        and 0 < len(value) <= 4096 and all(part not in ['.', '..', ''] for part in value.split('/')[1:]),
        'WORKER_COMPLETE_ABSOLUTE_NATIVE_PATH')
    return value


def worker_file(value):
    worker_keys(value, ['bytes', 'sha256', 'mode', 'uid', 'nlink'], 'WORKER_COMPLETE_FILE_METADATA')
    worker_int(value['bytes'], 0, 67108864)
    expect(paid_hash(value['sha256']) and (value['bytes'] != 0 or value['sha256'] == digest(b'')), 'WORKER_FULL_FILE_HASH')
    worker_int(value['mode'], 0, 511)
    expect(type(value['uid']) is int and value['uid'] == 0 and type(value['nlink']) is int and value['nlink'] == 1,
        'WORKER_REAL_OWNER_SINGLE_LINK_METADATA')


def worker_runtime(value):
    expect(type(value) is dict and list(value) == sorted(value), 'WORKER_SORTED_COMPLETE_RUNTIME_MAP')
    total = 0
    for path, metadata in value.items():
        worker_path(path)
        worker_file(metadata)
        total += metadata['bytes']
    expect(total <= 67108864 and 'timer.pid' not in value and '.timer' in value and 'timer.log' in value
        and value['timer.log']['bytes'] > 0 and value['timer.log']['mode'] == 0o622
        and value['.timer']['mode'] == 0o600, 'WORKER_COMPLETE_BOUNDED_FINAL_RUNTIME_NO_PIDFILE')


def worker_process(value, role, command, ready):
    worker_keys(value, ['pid', 'ppid', 'pgid', 'sid', 'starttime', 'state', 'exe', 'cwd', 'cmdline'], 'WORKER_COMPLETE_NATIVE_PROCESS_PROJECTION')
    worker_int(value['pid'], 1)
    worker_int(value['ppid'])
    worker_int(value['pgid'])
    worker_int(value['sid'])
    expect(type(value['starttime']) is str and re.fullmatch('[1-9][0-9]*', value['starttime'])
        and type(value['state']) is str and re.fullmatch('[A-Za-z]', value['state']) and value['state'] not in ['Z', 'X', 'x', 'T', 't'],
        'WORKER_LIVE_NATIVE_KERNEL_STARTTIME_STATE')
    worker_absolute(value['exe'])
    expect(value['cwd'] == ('/workspace' if role == 'supervisor' else '/workspace/crmeb'), 'WORKER_REAL_NATIVE_CWD')
    raw = value['cmdline']
    expect(type(raw) is str and 0 < len(raw.encode('utf-8')) <= 8192 and raw.endswith('\0'), 'WORKER_COMPLETE_NATIVE_NUL_ARGV')
    body = raw.rstrip('\0')
    launch = '\0'.join(command)
    master_title = 'WorkerMan: master process  start_file=/workspace/crmeb/think'
    child_title = 'WorkerMan: worker process  none none'
    allowed = ['\0'.join(['php', WORKER_ENTRY, 'normal'])] if role == 'supervisor' else (
        [launch, master_title] if role == 'master' else [launch, child_title] + ([] if ready else [master_title]))
    expect(body in allowed, 'WORKER_EXACT_SOURCE_BOUND_ARGV_OR_ORIGINAL_TITLE')


def worker_same_process(before, after):
    expect(all(paid_equal(before[key], after[key]) for key in ['pid', 'ppid', 'pgid', 'sid', 'starttime', 'exe', 'cwd']),
        'WORKER_SAME_UNREPLACED_NATIVE_PROCESS')


def worker_json_object(pairs):
    value = {}
    for key, member in pairs:
        expect(key not in value, 'WORKER_ORIGINAL_JSON_NO_DUPLICATE_KEYS')
        value[key] = member
    return value


def worker_events(value, runtime, child_pid, signal_ns, absence_ns):
    expect(type(value) is list and len(value) >= 3, 'WORKER_ALL_ORIGINAL_EVENT_LOCATORS')
    started = None
    scans = []
    removed = []
    last_ns = 0
    positions = {}
    marker = 'MARKETPLACE_FULFILLMENT_WORKER_EVENT '
    for locator in value:
        worker_keys(locator, ['file', 'file_sha256', 'offset', 'bytes', 'sha256', 'event_json'], 'WORKER_COMPLETE_SIX_FIELD_EVENT_LOCATOR')
        path = worker_path(locator['file'])
        expect(re.fullmatch(r'log/(?:[0-9]+-)?[0-9]{8}\.log', path) and path in runtime
            and locator['file_sha256'] == runtime[path]['sha256'] and paid_hash(locator['sha256']), 'WORKER_EVENT_ACTUAL_RUNTIME_FILE_BINDING')
        offset = worker_int(locator['offset'])
        length = worker_int(locator['bytes'], 1, 16384)
        raw = locator['event_json']
        expect(type(raw) is str and 0 < len(raw.encode('utf-8')) <= 8192 and '\r' not in raw and '\n' not in raw
            and length == len(('2000-01-01 00:00:00|info|' + marker + raw + '\n').encode('utf-8'))
            and offset + length <= runtime[path]['bytes'], 'WORKER_ORIGINAL_LINE_LENGTH_OFFSET_AND_JSON_BOUNDS')
        for previous_offset, previous_length in positions.setdefault(path, []):
            expect(offset + length <= previous_offset or previous_offset + previous_length <= offset, 'WORKER_DISTINCT_NONOVERLAPPING_ORIGINAL_LINES')
        positions[path].append((offset, length))
        event = json.loads(raw, object_pairs_hook=worker_json_object)
        expect(json.dumps(event, ensure_ascii=False, separators=(',', ':')) == raw, 'WORKER_ORIGINAL_CANONICAL_EVENT_JSON')
        worker_keys(event, ['schema', 'phase', 'pid', 'worker_id', 'loop_class', 'loop_object_id', 'timer_id', 'monotonic_ns',
            'active', 'busy', 'closed', 'owned', 'worker_count', 'slot', 'count', 'socketless', 'details'], 'WORKER_ORIGINAL_EXACT_SEVENTEEN_EVENT_FIELDS')
        expect(type(event['schema']) is int and event['schema'] == 1 and type(event['pid']) is int and event['pid'] == child_pid
            and type(event['worker_id']) is str and 0 < len(event['worker_id'].encode('utf-8')) <= 128
            and type(event['loop_class']) is str and len(event['loop_class']) <= 256 and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_\\]*', event['loop_class']),
            'WORKER_REAL_EVENT_CHILD_WORKER_LOOP_IDENTITY')
        worker_int(event['loop_object_id'], 1)
        worker_int(event['timer_id'], 1)
        stamp = worker_int(event['monotonic_ns'], 1)
        expect(last_ns < stamp <= absence_ns, 'WORKER_STRICT_ORIGINAL_EVENT_TIME_ORDER')
        last_ns = stamp
        expect(event['owned'] is True and type(event['worker_count']) is int and event['worker_count'] == 1
            and type(event['slot']) is int and event['slot'] == 0 and type(event['count']) is int and event['count'] == 1
            and event['socketless'] is True, 'WORKER_REAL_COUNT_ONE_SOCKETLESS_OWNED_TIMER')
        phase = event['phase']
        expect(phase in ['timer_started', 'scan_returned', 'timer_removed'], 'WORKER_ONLY_ORIGINAL_THREE_PHASES')
        expect(paid_equal([event['active'], event['busy'], event['closed']],
            {'timer_started': [True, False, False], 'scan_returned': [True, True, False], 'timer_removed': [False, False, True]}[phase]),
            'WORKER_PHASE_ACTIVE_BUSY_CLOSED')
        details = event['details']
        if phase == 'timer_started':
            expect(started is None and not scans and not removed, 'WORKER_ONE_FIRST_REAL_START')
            worker_keys(details, ['period_seconds', 'batch', 'add_started_ns'], 'WORKER_ORIGINAL_START_DETAILS')
            expect(type(details['period_seconds']) is int and details['period_seconds'] == 60
                and type(details['batch']) is int and details['batch'] == 100, 'WORKER_ORIGINAL_SIXTY_SECONDS_BATCH_HUNDRED')
            expect(worker_int(details['add_started_ns'], 1) <= stamp, 'WORKER_REAL_PRE_ADD_MONOTONIC_TIME')
            started = event
        else:
            expect(started is not None and all(paid_equal(event[key], started[key])
                for key in ['pid', 'worker_id', 'loop_class', 'loop_object_id', 'timer_id']), 'WORKER_SAME_ORIGINAL_LOOP_TIMER_LIFECYCLE')
            if phase == 'scan_returned':
                expect(not removed and stamp - started['details']['add_started_ns'] >= 60000000000,
                    'WORKER_NATURAL_SCAN_AFTER_ORIGINAL_SIXTY_SECONDS')
                worker_keys(details, ['scan', 'cursor_saved'], 'WORKER_ORIGINAL_SCAN_RETURN_DETAILS')
                expect(details['cursor_saved'] is True, 'WORKER_ORIGINAL_CURSOR_SAVED')
                scan = details['scan']
                worker_keys(scan, ['processed', 'received', 'already_received', 'not_due', 'pending', 'next_due_at',
                    'next_ship_id', 'wrapped', 'aftersale_paused', 'fulfillment_closed'], 'WORKER_ORIGINAL_TEN_SCAN_FIELDS')
                expect(all(type(scan[key]) is int and scan[key] == 0 for key in ['processed', 'received', 'already_received',
                    'not_due', 'pending', 'next_due_at', 'aftersale_paused', 'fulfillment_closed'])
                    and scan['next_ship_id'] == '0' and type(scan['next_ship_id']) is str and scan['wrapped'] is True,
                    'WORKER_ACTUAL_EMPTY_NON_DUE_SCAN_AND_CURSOR')
                scans.append(event)
            else:
                worker_keys(details, [], 'WORKER_REMOVAL_DETAILS_ORIGINAL_EMPTY_OBJECT')
                expect(not removed and scans and stamp >= signal_ns, 'WORKER_ONE_REMOVAL_AFTER_OWNED_TERM')
                removed.append(event)
    expect(started is not None and scans and scans[0]['monotonic_ns'] <= signal_ns and len(removed) == 1 and value[-1]['event_json'] == json.dumps(removed[0],
        ensure_ascii=False, separators=(',', ':')), 'WORKER_COMPLETE_FIRST_START_ALL_SCANS_FINAL_REMOVAL')
    return started, scans, removed[0]


def worker_lifecycle(value):
    worker_keys(value, ['command', 'cwd', 'supervisor', 'master_initial', 'child_initial', 'master_before_signal',
        'child_before_signal', 'pid_file', 'signal', 'proc_close_exit', 'streams', 'event_records', 'runtime', 'child_exit_observation'],
        'WORKER_COMPLETE_FOURTEEN_FIELD_LIFECYCLE')
    command = value['command']
    expect(type(command) is list and len(command) == 4 and all(type(part) is str for part in command)
        and command[1:] == ['/workspace/crmeb/think', 'timer', 'start'] and value['cwd'] == '/workspace/crmeb',
        'WORKER_EXACT_ORIGINAL_FOREGROUND_COMMAND')
    worker_absolute(command[0])
    for field, role, ready in [('supervisor', 'supervisor', True), ('master_initial', 'master', False),
        ('child_initial', 'child', False), ('master_before_signal', 'master', True), ('child_before_signal', 'child', True)]:
        worker_process(value[field], role, command, ready)
    supervisor, master, child = value['supervisor'], value['master_initial'], value['child_initial']
    expect(supervisor['pid'] == 1 and master['pid'] > 1 and child['pid'] > 1
        and len({supervisor['pid'], master['pid'], child['pid']}) == 3
        and master['ppid'] == supervisor['pid'] and child['ppid'] == master['pid']
        and int(supervisor['starttime']) <= int(master['starttime']) <= int(child['starttime'])
        and all(value[field]['exe'] == supervisor['exe'] for field in ['master_initial', 'child_initial', 'master_before_signal', 'child_before_signal']),
        'WORKER_REAL_PID_ONE_DIRECT_MASTER_CHILD_SAME_EXECUTABLE')
    worker_same_process(master, value['master_before_signal'])
    worker_same_process(child, value['child_before_signal'])
    signal = value['signal']
    worker_keys(signal, ['resource_id', 'number', 'attempted', 'returned', 'monotonic_ns'], 'WORKER_COMPLETE_SAME_RESOURCE_SIGNAL')
    worker_int(signal['resource_id'], 1)
    expect(type(signal['number']) is int and signal['number'] == 15 and signal['attempted'] is True and signal['returned'] is True,
        'WORKER_REAL_ONCE_NORMAL_TERM')
    signal_ns = worker_int(signal['monotonic_ns'], 1)
    expect(type(value['proc_close_exit']) is int and value['proc_close_exit'] == 0, 'WORKER_ACTUAL_INNER_PROC_CLOSE_ZERO')
    pidfile = value['pid_file']
    worker_keys(pidfile, ['path', 'before_signal', 'master_pid', 'exact_master_pid_bytes', 'absent_after_close', 'absence_monotonic_ns'],
        'WORKER_COMPLETE_REAL_PIDFILE_LIFECYCLE')
    worker_file(pidfile['before_signal'])
    pid_raw = str(master['pid']).encode('ascii')
    expect(pidfile['path'] == '/workspace/crmeb/runtime/timer.pid' and type(pidfile['master_pid']) is int
        and pidfile['master_pid'] == master['pid'] and pidfile['exact_master_pid_bytes'] is True
        and pidfile['absent_after_close'] is True and pidfile['before_signal']['bytes'] == len(pid_raw)
        and pidfile['before_signal']['sha256'] == digest(pid_raw) and pidfile['before_signal']['mode'] == 0o600,
        'WORKER_EXACT_ORIGINAL_MASTER_PID_BYTES_THEN_ABSENT')
    absence_ns = worker_int(pidfile['absence_monotonic_ns'], signal_ns)
    worker_keys(value['streams'], ['stdout', 'stderr'], 'WORKER_BOTH_ORIGINAL_STREAMS')
    for stream in value['streams'].values():
        worker_keys(stream, ['bytes', 'sha256', 'eof', 'closed'], 'WORKER_COMPLETE_STREAM_DESCRIPTOR')
        worker_int(stream['bytes'], 0, 67108864)
        expect(paid_hash(stream['sha256']) and stream['eof'] is True and stream['closed'] is True
            and (stream['bytes'] != 0 or stream['sha256'] == digest(b'')), 'WORKER_FULL_ORIGINAL_STREAM_EOF_CLOSE_HASH')
    expect(sum(row['bytes'] for row in value['streams'].values()) <= 67108864, 'WORKER_COMPLETE_ORIGINAL_COMBINED_STREAM_BOUND')
    worker_runtime(value['runtime'])
    started, scans, removed = worker_events(value['event_records'], value['runtime'], child['pid'], signal_ns, absence_ns)
    expect(absence_ns - started['details']['add_started_ns'] <= 180000000000, 'WORKER_VISIBLE_COMPLETE_MONOTONIC_WINDOW')
    observation = value['child_exit_observation']
    worker_keys(observation, ['observation', 'raw_child_wait_status', 'child_absent_after_close', 'removed_event_count',
        'master_proc_close_exit'], 'WORKER_ORIGINAL_CHILD_WAITSTATUS_EVIDENCE_BOUNDARY')
    expect(observation['observation'] == 'MASTER_NORMAL_EXIT_OWNED_STOP_TRACE_NO_RAW_CHILD_WAIT_STATUS'
        and observation['raw_child_wait_status'] is None and observation['child_absent_after_close'] is True
        and type(observation['removed_event_count']) is int and observation['removed_event_count'] == 1
        and type(observation['master_proc_close_exit']) is int and observation['master_proc_close_exit'] == 0,
        'WORKER_NO_INVENTED_RAW_CHILD_EXIT_CODE')
    return value


def worker_cache_summary(value):
    keys = ['c:crontabCache', 'c:marketplace_fulfillment_receive_cursor', 'c:CRMEB:827bf7a6b0b8d160bd116898762da4c5']
    worker_keys(value, ['time_ms', 'keys', 'reply_sha256'], 'WORKER_NATIVE_CACHE_SUMMARY')
    expect(type(value['time_ms']) is str and re.fullmatch('(?:0|[1-9][0-9]*)', value['time_ms']) and paid_hash(value['reply_sha256']),
        'WORKER_REAL_CACHE_TIME_REPLY_HASH')
    worker_keys(value['keys'], keys, 'WORKER_ONLY_THREE_FIXED_CACHE_KEYS')
    for key, row in value['keys'].items():
        worker_keys(row, ['type', 'expires', 'bytes', 'sha256'], 'WORKER_COMPLETE_NATIVE_CACHE_KEY')
        expect(row['type'] in ['none', 'string', 'set'] and type(row['expires']) is int and row['expires'] >= -2
            and paid_hash(row['sha256']), 'WORKER_REAL_CACHE_TYPE_EXPIRY_VALUE_HASH')
        worker_int(row['bytes'], 0, 67108864)
        if row['type'] == 'none':
            expect(row['expires'] == -2 and row['bytes'] == 0 and row['sha256'] == digest(b''), 'WORKER_ACTUAL_ABSENT_CACHE')
        else:
            expect(row['expires'] >= -1 and row['bytes'] > 0, 'WORKER_EXISTING_CACHE_NATIVE_EXPIRY_AND_BYTES')
    return keys


def worker_preservation(value, lifecycle, prerequisites, original_normal):
    worker_keys(value, ['before', 'after', 'predecessor_hashes', 'original_inputs', 'all_database_unchanged',
        'non_target_redis_unchanged', 'timer_redis_changes', 'private_files_unchanged', 'public_files_unchanged',
        'runtime', 'full_source_unchanged', 'window'], 'WORKER_COMPLETE_TWELVE_FIELD_PRESERVATION')
    verify_aftersale_review_archive(value['before'], 'ms4-worker-normal-before.json', 'MS4_WORKER_NORMAL_BEFORE_NATIVE_FACTS')
    verify_aftersale_review_archive(value['after'], 'ms4-worker-normal-after.json', 'MS4_WORKER_NORMAL_AFTER_NATIVE_FACTS')
    expect(value['before']['sha256'] != value['after']['sha256'] and paid_equal(value['predecessor_hashes'], prerequisites)
        and paid_equal(value['runtime'], lifecycle['runtime']), 'WORKER_NATIVE_SEALS_PREDECESSORS_RUNTIME_LINKED')
    for name in ['all_database_unchanged', 'non_target_redis_unchanged', 'private_files_unchanged', 'public_files_unchanged', 'full_source_unchanged']:
        expect(value[name] is True, 'WORKER_FULL_ORIGINAL_PRESERVATION_' + name)
    inputs = value['original_inputs']
    names = ['ms4-customer-fixture.json', 'ms5-paid-baseline.json', 'ms4-fulfillment-stage6.json',
        'ms4-aftersale-review-paid-baseline.json', 'ms4-aftersale-review-stage-final.json', 'ms4-aftersale-review-public-file-keep.json']
    limits = [1048576, 2097152, 67108864, 100663296, 100663296, 100663296]
    expect(type(inputs) is list and len(inputs) == 6, 'WORKER_ALL_SIX_ORIGINAL_PRIVATE_INPUT_DESCRIPTORS')
    for row, name, limit in zip(inputs, names, limits):
        worker_keys(row, ['path_basename', 'bytes', 'sha256'], 'WORKER_PUBLIC_PRIVATE_INPUT_DESCRIPTOR_ONLY')
        expect(row['path_basename'] == name and paid_hash(row['sha256']), 'WORKER_FIXED_ORIGINAL_PRIVATE_INPUT')
        worker_int(row['bytes'], 1, limit)
    evidence = Path(original_normal['context']['run_dir']) / 'evidence'
    prepare = load(evidence / 'ms4-customer-prepare.json')['business']
    expect(type(prepare['fixture_bytes']) is int and inputs[0]['bytes'] == prepare['fixture_bytes']
        and type(prepare['fixture_sha256_prefix']) is str and re.fullmatch('[a-f0-9]{12}', prepare['fixture_sha256_prefix'])
        and inputs[0]['sha256'][:12] == prepare['fixture_sha256_prefix'], 'WORKER_ORIGINAL_FIXTURE_PUBLIC_BYTES_AND_PREFIX_ONLY')
    paid_baseline = load(evidence / 'paid-producer-result.json')['business']['baseline']
    task6_baseline = load(evidence / 'ms4-fulfillment-normal.json')['business']['baseline']
    for row, descriptor in [(inputs[1], paid_baseline), (inputs[2], task6_baseline)]:
        expect(type(descriptor['bytes']) is int and row['bytes'] == descriptor['bytes']
            and row['sha256'] == descriptor['sha256'], 'WORKER_ORIGINAL_PAID_TASK6_BASELINE_PUBLIC_BYTES_HASH')
    keep = load(evidence / 'aftersale-review-public-file-keep.json')
    for row, descriptor in [(inputs[3], original_normal['result']['business']['paid_baseline']),
        (inputs[4], original_normal['result']['business']['final_baseline']), (inputs[5], keep['archive'])]:
        expect(row['path_basename'] == descriptor['path_basename'] and row['bytes'] == descriptor['bytes']
            and row['sha256'] == descriptor['sha256'], 'WORKER_OLD_INPUTS_BIND_REAL_ORIGINAL_NORMAL_SEALS')
    window = value['window']
    worker_keys(window, ['wall_started', 'wall_through', 'redis_started_ms', 'redis_through_ms', 'seconds',
        'tasks_count', 'saved_ship_rows_count', 'preflight_sha256'], 'WORKER_COMPLETE_REAL_NATURAL_WINDOW')
    start = worker_int(window['wall_started'], 1)
    end = worker_int(window['wall_through'], start)
    expect(type(window['seconds']) is int and window['seconds'] == 180 and end - start == 180
        and type(window['tasks_count']) is int and window['tasks_count'] == 0 and paid_hash(window['preflight_sha256']),
        'WORKER_NATURALLY_EMPTY_TASKS_FULL_WINDOW_HASH')
    worker_int(window['saved_ship_rows_count'])
    for key in ['redis_started_ms', 'redis_through_ms']:
        expect(type(window[key]) is str and re.fullmatch('(?:0|[1-9][0-9]*)', window[key]), 'WORKER_ACTUAL_REDIS_WINDOW_MILLISECONDS')
    expect(int(window['redis_through_ms']) - int(window['redis_started_ms']) == 180000, 'WORKER_FULL_REDIS_NONEXPIRY_WINDOW')
    timer = lifecycle['runtime']['.timer']
    expect(any(timer['bytes'] == len(str(stamp)) and timer['sha256'] == digest(str(stamp).encode('ascii'))
        for stamp in range(start, end + 1)), 'WORKER_ACTUAL_ASCII_TIMER_HEARTBEAT_WITHIN_WINDOW')
    changes = value['timer_redis_changes']
    worker_keys(changes, ['before', 'after', 'changes'], 'WORKER_COMPLETE_THREE_KEY_REDIS_DELTA')
    keys = worker_cache_summary(changes['before'])
    worker_cache_summary(changes['after'])
    expect(int(window['redis_started_ms']) <= int(changes['before']['time_ms']) <= int(changes['after']['time_ms'])
        <= int(window['redis_through_ms']), 'WORKER_ACTUAL_CACHE_OBSERVATIONS_WITHIN_WINDOW')
    before, after = changes['before']['keys'], changes['after']['keys']
    expect(before[keys[0]]['type'] in ['none', 'string'] and before[keys[1]]['type'] in ['none', 'string']
        and before[keys[2]]['type'] in ['none', 'set'], 'WORKER_REAL_NATURAL_INITIAL_CACHE_TYPES')
    empty = b'a:0:{}'
    cursor = b'a:2:{s:6:"due_at";i:0;s:7:"ship_id";s:1:"0";}'
    if before[keys[0]]['type'] == 'string':
        expect(before[keys[0]]['bytes'] == len(empty) and before[keys[0]]['sha256'] == digest(empty), 'WORKER_NATURAL_EXISTING_STRICT_EMPTY_TASK_CACHE')
    for key, raw in [(keys[0], empty), (keys[1], cursor)]:
        expect(paid_equal(after[key], {'type': 'string', 'expires': -1, 'bytes': len(raw), 'sha256': digest(raw)}),
            'WORKER_REAL_EMPTY_SCAN_NATIVE_SERIALIZED_CACHE_CURSOR')
    expect(after[keys[2]]['type'] == 'set' and after[keys[2]]['bytes'] > 0
        and after[keys[2]]['expires'] == (-1 if before[keys[2]]['type'] == 'none' else before[keys[2]]['expires']),
        'WORKER_ORIGINAL_TAG_TYPE_AND_ABSOLUTE_EXPIRY')
    for row in before.values():
        expect(row['expires'] <= 0 or row['expires'] > int(window['redis_through_ms']), 'WORKER_EXISTING_TARGET_EXPIRY_OUTLASTS_WINDOW')
    worker_keys(changes['changes'], keys, 'WORKER_THREE_EXACT_FULL_SNAPSHOT_DELTA_KEYS')
    for key, row in changes['changes'].items():
        worker_keys(row, ['type', 'sha256', 'expires'], 'WORKER_NATIVE_DUMP_OR_SET_SNAPSHOT_DESCRIPTOR')
        expect(row['type'] == after[key]['type'] and type(row['expires']) is int and row['expires'] == after[key]['expires']
            and paid_hash(row['sha256']), 'WORKER_CACHE_DELTA_FULL_NATIVE_SNAPSHOT_ASSOCIATION')
    # Original line bodies, tag members and full snapshot bodies remain private;
    # the complete entry validates them and seals their bytes before this public projection.


def verify_worker_result(context, native_required=False):
    evidence = Path(context['run_dir']) / 'evidence'
    checked_names = [name for name in worker_public_names() if not name.startswith('authorize-worker-cleanup-')]
    before = public_hashes(context, checked_names)
    original_normal = verify_aftersale_review_history(context)
    verify_aftersale_review_removed(context)
    verify_worker_helper(context, 'register-worker-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    facts = normal_terminal(context, WORKER['proof'], WORKER['role'], native_required)
    binding = verify_worker_binding(context, facts['id'], False)
    formal = load(evidence / 'worker-normal-formal-manifest.json')
    expect(paid_equal(formal, load(Path(context['run_dir']) / 'manifest.json'))
        and fulfillment_expected_formal(context, formal, WORKER['role'], WORKER['proof']), 'WORKER_CURRENT_REAL_FOUR_ROLE_FORMAL')
    result = load(evidence / WORKER['result'])
    worker_keys(result, ['schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'prerequisites_sha256',
        'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged',
        'metadata_observer', 'lifecycle', 'preservation', 'diagnostic', 'full_acceptance', 'stopped_samples', 'assertions'],
        'WORKER_COMPLETE_TWENTY_FIVE_FIELD_RESULT')
    expect(type(result['schema']) is int and result['schema'] == 1 and result['scope'] == WORKER['result_scope']
        and result['status'] == WORKER['status'] and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and result['container_id'] == facts['id'] and result['native_created_sha256'] == before['worker-normal-created.json']
        and result['source_binding_sha256'] == before[WORKER['binding']]
        and result['environment_sha256'] == before['worker-normal-environment.json']
        and result['environment_native_sha256'] == before['worker-normal-environment-native.json']
        and paid_equal(result['prerequisites_sha256'], binding['prerequisites_sha256'])
        and result['phase'] == 'final_source' and type(result['entry_return_code']) is int and result['entry_return_code'] == 0
        and result['termination'] == 'NORMAL_RETURN' and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and result['diagnostic'] is None and result['full_acceptance'] == 'INCOMPLETE'
        and result['stopped_samples'] == 'NOT RUN' and type(result['assertions']) is int and result['assertions'] > 0,
        'WORKER_SOURCE_RESULT_DISTINCT_INNER_OUTER_ZERO_AND_LIMITED_ACCEPTANCE')
    verify_fulfillment_observer(result)
    lifecycle = worker_lifecycle(result['lifecycle'])
    worker_preservation(result['preservation'], lifecycle, binding['prerequisites_sha256'],
        {'context': context, 'result': original_normal})
    shutdown = load(evidence / 'worker-normal-shutdown.json')
    worker_keys(shutdown, ['schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase', 'completed',
        'result_published', 'fatal', 'termination_requested', 'primary_failure', 'cleanup_failure', 'native_exit', 'native_exit_observation'],
        'WORKER_COMPLETE_FIFTEEN_FIELD_SHUTDOWN')
    expect(type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['scope'] == 'MS4_WORKER_NORMAL_SHUTDOWN'
        and all(paid_equal(shutdown[key], result[key]) for key in ['run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase'])
        and shutdown['completed'] is True and shutdown['result_published'] is True and shutdown['fatal'] is False
        and shutdown['termination_requested'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and shutdown['native_exit'] is None and shutdown['native_exit_observation'] == 'HOST_NATIVE_REQUIRED',
        'WORKER_REAL_NORMAL_PARENT_SHUTDOWN')
    expect(paid_equal(binding['prerequisites_sha256'], worker_prerequisite_hashes(context))
        and paid_equal(before, public_hashes(context, checked_names)), 'WORKER_ALL_HUNDRED_PREAUTHORIZE_PUBLIC_ORIGINALS_UNCHANGED')
    return result


def worker_stage(name, context_file):
    context = current(context_file)
    root = Path(context['run_dir'])
    evidence = root / 'evidence'
    paid = bound_created(context, PAID['proof'], PAID['role'])
    expected = base_roles(context) | {paid['id']: PAID['role']}
    verify_paid_history(context, True)
    verify_aftersale_review_history(context)
    removed = verify_aftersale_review_removed(context)
    expect(removed['container_id'] not in active(context), 'OLD_NORMAL_ABSENT_BEFORE_WORKER_STAGE')
    if name == 'worker-create':
        expect(not (evidence / 'worker-normal-created.json').exists(), 'WORKER_ONE_CREATED_NATIVE_INSTANCE')
        collect(context, expected)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', WORKER['role']], name, WORKER['proof'])
        cid = created(context, WORKER['role'], WORKER['proof'])
        registered = helper(context, 'register-worker-normal', 'register', expected | {cid: WORKER['role']})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_WORKER_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
        return worker_stage('worker-bind', context_file)
    if name in ['worker-bind', 'worker-start']:
        verify_worker_helper(context, 'register-worker-normal', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        original = bound_created(context, WORKER['proof'], WORKER['role'])
        cid = original['id']
        fresh = inspect(context, cid, name + '-precheck')
        owned(context, fresh, WORKER['role'])
        same_created(original, fresh)
        expect(fresh['status'] == 'created' and type(fresh['pid']) is int and fresh['pid'] == 0, 'WORKER_SAME_UNSTARTED_NATIVE_INSTANCE')
        collect(context, expected | {cid: WORKER['role']})
        formal = load(root / 'manifest.json')
        fulfillment_expected_formal(context, formal, WORKER['role'], WORKER['proof'])
        if name == 'worker-bind':
            exact_copy(root / 'manifest.json', evidence / 'worker-normal-formal-manifest.json')
            record_worker_binding(context, cid)
            return {'status': 'WORKER_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY', 'container_id': cid}
        expect(paid_equal(formal, load(evidence / 'worker-normal-formal-manifest.json')), 'WORKER_SAME_ORIGINAL_FORMAL_BEFORE_START')
        verify_worker_binding(context, cid, True)
        native(context, ['start', cid], name)
        return {'status': 'WORKER_NORMAL_STARTED_ONLY', 'container_id': cid}
    if name == 'authorize-worker-close':
        verify_worker_result(context, True)
        return helper(context, 'authorize-worker-cleanup', 'authorize-cleanup', proof_expected(context, WORKER['proof']))
    raise RuntimeError('UNKNOWN_FIXED_WORKER_STAGE')


def stage(name, context_file, proof=None, seconds=30):
    if name in ['worker-create', 'worker-bind', 'worker-start', 'authorize-worker-close']:
        return worker_stage(name, context_file)
    if name == 'early-paid-create' or name in ['fulfillment-migration-create', 'fulfillment-migration-bind', 'fulfillment-migration-start', 'fulfillment-migration-retire']:
        return early_stage(name, context_file)
    if name.startswith('aftersale-review-') or name == 'authorize-aftersale-review-close':
        return aftersale_review_stage(name, context_file)
    if name.startswith('fulfillment-') or name == 'authorize-fulfillment-close':
        return fulfillment_stage(name, context_file, proof, seconds)
    context = current(context_file, name not in ['observe', 'finish-helper']); root = Path(context['run_dir']); evidence = root / 'evidence'
    base = base_roles(context)
    if name == 'observe':
        expect(proof in PROOF_ROLES, 'KNOWN_OBSERVATION_PROOF')
        collect(context, proof_expected(context, proof), publish=False)
        facts = load(evidence / (proof + '-created.json'))
        return observe(context, facts['id'], proof, seconds)
    if name == 'finish-helper':
        wanted = HELPER_RESULTS
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
        return {'status': 'INITIALIZER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id'], 'MS5_DDL': 'NOT RUN'}
    if name == 'initializer-resume':
        cid = load(evidence / 'initializer-created.json')['id']
        result = load(evidence / 'register-initializer-result.json')
        expect(result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and result['native_exit'] == 0, 'REGISTERED_INITIALIZER_ACTUAL_RECEIPT')
        facts = inspect(context, cid, 'initializer-resume-precheck'); owned(context, facts, 'audit-ms4-initialize')
        expect(facts['status'] == 'created', 'SAME_UNSTARTED_INITIALIZER_ONLY')
        collect(context, base | {cid: 'audit-ms4-initialize'})
        native(context, ['start', cid], 'initializer-resume-start')
        return {'status': 'SAME_INITIALIZER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id']}
    if name == 'driver-create':
        expect(not (evidence / 'driver-created.json').exists(), 'DRIVER_ALREADY_CREATED')
        old = load(evidence / 'initializer-created.json'); terminal = inspect(context, old['id'], 'initializer-terminal-required')
        result = load(evidence / 'initializer-result.json')
        prerequisite_result(context, old, terminal, result)
        new(evidence / 'initializer-completed-terminal.json', terminal)
        remove_finished(context, old['id'], 'audit-ms4-initialize', 'initializer', base | {old['id']: 'audit-ms4-initialize'})
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-ms5-payment-migration'], 'driver-create')
        cid = created(context, 'audit-ms5-payment-migration', 'driver')
        registered = helper(context, 'register-driver', 'register', base | {cid: 'audit-ms5-payment-migration'})
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_REGISTRATION_SAME_HANDLE_PRESERVED', 'driver_id': cid, 'registration': registered}
        return stage('driver-bind', context_file)
    if name == 'driver-bind':
        cid = load(evidence / 'driver-created.json')['id']
        result = load(evidence / 'register-driver-result.json')
        expect(result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and result['native_exit'] == 0, 'REGISTERED_DRIVER_ACTUAL_RECEIPT')
        facts = inspect(context, cid, 'driver-bind-precheck'); owned(context, facts, 'audit-ms5-payment-migration')
        expect(facts['status'] == 'created', 'SAME_UNSTARTED_DRIVER_ONLY')
        collect(context, base | {cid: 'audit-ms5-payment-migration'})
        binding = load(root / 'preparation-source-binding.json')
        facts = load(evidence / 'driver-created.json')
        new(evidence / 'ms5-payment-migration-container-created.json', facts)
        new(evidence / 'ms5-payment-migration-source-binding.json', {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
            'requested_repo_source': context['repo_dir'], 'requested_run_source': context['run_dir'], 'source_files': binding['driver_source_files'],
            'entry_sha256': next(row['sha256'] for row in binding['driver_source_files'] if row['path'] == DRIVER[3]),
            'cli_sha256': next(row['sha256'] for row in binding['driver_source_files'] if row['path'] == DRIVER[-1])})
        return {'status': 'UNSTARTED_MS5_PAYMENT_DRIVER_SOURCE_BOUND_ONLY', 'container_id': cid, 'run_id': context['run_id'], 'MS5_DDL': 'NOT RUN'}
    if name == 'driver-start':
        cid = load(evidence / 'driver-created.json')['id']; facts = inspect(context, cid, 'driver-start-precheck')
        expect(facts['status'] == 'created' and facts['restart_count'] == 0, 'ONLY_UNSTARTED_SAME_DRIVER')
        collect(context, base | {cid: 'audit-ms5-payment-migration'})
        native(context, ['start', cid], 'driver-start')
        return {'status': 'MS5_PAYMENT_DRIVER_STARTED_ONLY', 'container_id': cid, 'run_id': context['run_id']}

    if name == 'files-bootstrap-create':
        expect(not (evidence / 'files-bootstrap-created.json').exists(), 'FILES_BOOTSTRAP_ALREADY_CREATED')
        terminal = verify_fulfillment_migration(context, False)
        expect(terminal['id'] not in active(context), 'EARLY_DRIVER_REMOVED_BEFORE_FIRST_APP')
        paid = pre_app_paid_state(context, 'files-bootstrap', True)
        paid_base = base | {paid['id']: PAID['role']}
        collect(context, paid_base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-bootstrap'], 'files-bootstrap-create', 'files-bootstrap', 'files-bootstrap')
        cid = created(context, 'audit-bootstrap', 'files-bootstrap', 'files-bootstrap')
        collect(context, paid_base | {cid: 'audit-bootstrap'})
        return {'status': 'FILES_BOOTSTRAP_CREATED_ONLY', 'container_id': cid}
    if name == 'files-bootstrap-start':
        cid = bound_created(context, 'files-bootstrap', 'audit-bootstrap')['id']
        facts = inspect(context, cid, 'files-bootstrap-start-precheck'); owned(context, facts, 'audit-bootstrap')
        same_created(bound_created(context, 'files-bootstrap', 'audit-bootstrap'), facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0, 'FILES_ONLY_UNSTARTED_HANDLE')
        paid = pre_app_paid_state(context, 'files-bootstrap')
        verify_fulfillment_migration(context, False)
        collect(context, base | {paid['id']: PAID['role'], cid: 'audit-bootstrap'})
        native(context, ['start', cid], 'files-bootstrap-start')
        return {'status': 'FILES_BOOTSTRAP_STARTED_ONLY', 'container_id': cid}
    for mode in ['prepare', 'verify']:
        spec = MODES[mode]; prefix = 'customer-prepare' if mode == 'prepare' else 'customer'
        mode_base = base | {bound_created(context, PAID['proof'], PAID['role'])['id']: PAID['role']} if mode == 'prepare' else base
        if name == prefix + '-create':
            expect(not (evidence / (spec['proof'] + '-created.json')).exists(), 'CUSTOMER_ALREADY_CREATED_USE_SAME_HANDLE')
            if mode == 'prepare':
                pre_app_paid_state(context, 'customer-prepare', True)
                verify_fulfillment_migration(context, False)
                verify_files_bootstrap(context)
                before = bound_created(context, 'files-bootstrap', 'audit-bootstrap')
                remove_finished(context, before['id'], 'audit-bootstrap', 'files-bootstrap', mode_base | {before['id']: 'audit-bootstrap'})
            else:
                verify_mode_result(context, 'prepare')
                before = bound_created(context, 'customer-prepare', MODES['prepare']['role'])
                remove_finished(context, before['id'], MODES['prepare']['role'], 'customer-prepare', mode_base | {before['id']: MODES['prepare']['role']})
            collect(context, mode_base)
            compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', spec['role']], prefix + '-create', spec['proof'])
            cid = created(context, spec['role'], spec['proof'])
            registered = helper(context, 'register-' + spec['proof'], 'register', mode_base | {cid: spec['role']})
            if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
                return {'status': 'LIVE_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid, 'registration': registered}
            return stage(prefix + '-bind', context_file)
        if name == prefix + '-bind':
            cid = bound_created(context, spec['proof'], spec['role'])['id']
            registered = load(evidence / ('register-' + spec['proof'] + '-result.json'))
            expect(registered['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and registered['native_exit'] == 0, 'CUSTOMER_REAL_REGISTRATION')
            facts = inspect(context, cid, prefix + '-bind-precheck'); owned(context, facts, spec['role'])
            same_created(bound_created(context, spec['proof'], spec['role']), facts)
            expect(facts['status'] == 'created' and facts['pid'] == 0, 'ONLY_UNSTARTED_CUSTOMER_BIND')
            collect(context, mode_base | {cid: spec['role']})
            record_mode_binding(context, mode, cid)
            return {'status': 'CUSTOMER_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY', 'mode': mode, 'container_id': cid}
        if name == prefix + '-start':
            cid = bound_created(context, spec['proof'], spec['role'])['id']
            facts = inspect(context, cid, prefix + '-start-precheck'); owned(context, facts, spec['role'])
            same_created(bound_created(context, spec['proof'], spec['role']), facts)
            expect(facts['status'] == 'created' and facts['pid'] == 0, 'ONLY_UNSTARTED_CUSTOMER_START')
            collect(context, mode_base | {cid: spec['role']})
            if mode == 'prepare':
                pre_app_paid_state(context, 'customer-prepare')
                verify_fulfillment_migration(context, False)
            verify_mode_binding(context, mode, cid)
            native(context, ['start', cid], prefix + '-start')
            return {'status': 'CUSTOMER_MODE_STARTED_ONLY', 'mode': mode, 'container_id': cid}
    if name == 'paid-create':
        # Canonical paid CID was genuinely created before early DDL and never started.
        verify_files_bootstrap(context, False)
        verify_mode_result(context, 'prepare')
        verify_fulfillment_migration(context, False)
        paid = current_unstarted_paid(context)
        before = bound_created(context, 'customer-prepare', MODES['prepare']['role'])
        paid_base = base | {paid['id']: PAID['role']}
        remove_finished(context, before['id'], MODES['prepare']['role'], 'customer-prepare', paid_base | {before['id']: MODES['prepare']['role']})
        collect(context, paid_base)
        verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        registered = helper(context, 'register-paid-after-c3', 'register', paid_base)
        if registered.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_PAID_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': paid['id'], 'registration': registered}
        verify_paid_helper(context, 'register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        current_unstarted_paid(context)
        return stage('paid-bind', context_file)
    if name == 'paid-bind':
        cid = bound_created(context, PAID['proof'], PAID['role'])['id']
        verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        verify_paid_helper(context, 'register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        current_unstarted_paid(context)
        facts = inspect(context, cid, 'paid-bind-precheck'); owned(context, facts, PAID['role'])
        same_created(bound_created(context, PAID['proof'], PAID['role']), facts)
        expect(facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0, 'ONLY_UNSTARTED_SAME_PAID_BIND')
        collect(context, base | {cid: PAID['role']})
        record_paid_binding(context, cid)
        return {'status': 'PAID_PRODUCER_UNSTARTED_SOURCE_ENVIRONMENT_BOUND_ONLY', 'container_id': cid}
    if name == 'paid-start':
        cid = bound_created(context, PAID['proof'], PAID['role'])['id']
        facts = inspect(context, cid, 'paid-start-precheck'); owned(context, facts, PAID['role'])
        same_created(bound_created(context, PAID['proof'], PAID['role']), facts)
        expect(facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0, 'ONLY_UNSTARTED_SAME_PAID_START')
        collect(context, base | {cid: PAID['role']})
        verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        verify_paid_helper(context, 'register-paid-after-c3', 'REGISTERED_NATIVE_RESOURCES_ONLY')
        verify_paid_binding(context, cid)
        native(context, ['start', cid], 'paid-start')
        return {'status': 'PAID_PRODUCER_STARTED_ONLY', 'container_id': cid}
    if name == 'authorize-paid-close':
        verify_paid_result(context)
        return helper(context, 'authorize-paid-cleanup', 'authorize-cleanup', proof_expected(context, PAID['proof']))
    if name == 'authorize-close':
        verify_mode_result(context, 'prepare', False)
        verify_files_bootstrap(context, False)
        verify_mode_result(context, 'verify')
        return helper(context, 'authorize-customer-cleanup', 'authorize-cleanup', proof_expected(context, 'customer'))
    raise RuntimeError('Only explicit reviewed customer stages; close and independent zero are separate')


# Finite profile-only PHP INI resource; no Core/env6/env8 or native entry changes.
PROOF_INI_LEAF = '99-ms4-proof-memory.ini'
PROOF_INI_PIN = {'bytes': 18, 'sha256': '986c7e4b2587348a8d5ba59312a2106d38bbefec35acda4e7e5c14761cfe9ff1'}
PROOF_INI_ROLES = ['audit-ms5-paid-producer', 'audit-ms4-fulfillment-normal', 'audit-ms4-aftersale-review-normal',
    'audit-ms4-worker-normal', 'audit-ms4-ui-prepare', 'audit-ms4-ui-backend']
PROOF_INI_SCAN = '/usr/local/etc/php/conf.d:/run/audit/proof-php-ini'
PROOF_INI_REFERENCE = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/r20-proof-memory-budget-public-readback-r1/RESULT.json',
    'bytes': 60911, 'sha256': 'e4ff0cd932806efb206b46dfdc5f5ef0230e0f2ad84679dbcb843adbdbec67f5'}
PROOF_INI_COPY = 'evidence/proof-php-ini-copy.json'
PROOF_INI_ENV_FOLDER = 'evidence/proof-php-ini-env'

def proof_ini_raw(path):
    path = Path(path)
    expect(path.is_file() and not path.is_symlink() and not (getattr(path.lstat(), 'st_file_attributes', 0) & 1024), 'PROOF_INI_REGULAR_NO_REPARSE')
    raw = path.read_bytes()
    expect(path.read_bytes() == raw, 'PROOF_INI_COMPLETE_READBACK')
    return raw

def proof_ini_directory(path):
    path = Path(path)
    expect(path.is_dir() and not path.is_symlink() and not (getattr(path.lstat(), 'st_file_attributes', 0) & 1024), 'PROOF_INI_SOURCE_DIRECTORY')
    expect(sorted(item.name for item in path.iterdir()) == [PROOF_INI_LEAF], 'PROOF_INI_EXACT_ONE_MEMBER')
    raw = proof_ini_raw(path / PROOF_INI_LEAF)
    expect(raw == b'memory_limit=512M\n' and {'bytes': len(raw), 'sha256': digest(raw)} == PROOF_INI_PIN, 'PROOF_INI_ONLY_PINNED_DIRECTIVE')
    return raw

def proof_ini_reference():
    raw = proof_ini_raw(PROOF_INI_REFERENCE['path'])
    expect({'path': PROOF_INI_REFERENCE['path'], 'bytes': len(raw), 'sha256': digest(raw)} == PROOF_INI_REFERENCE, 'ACTUAL_FIXED_IMAGE_CONFIG_REFERENCE')
    value = json.loads(raw); config = value['configurations']
    expect(value['schema'] == 1 and value['scope'] == 'FIXED_IMAGE_PROOF_INI_AND_FRESH_CHILD_CONFIGURATION_ONLY'
        and value['status'] == 'VERIFIED_DEFAULT_128M_PROOF_512M_FRESH_CHILD_128M_NO_APP_NO_NETWORK' and value['image'] == PHP
        and config['default']['memory_limit'] == config['fresh_child']['memory_limit'] == '128M'
        and config['proof']['memory_limit'] == '512M' and config['proof']['scan_dir_env'] == PROOF_INI_SCAN
        and config['default']['scan_dir_env'] is None and config['fresh_child']['scan_dir_env'] is None
        and len(value['native_operations']) == 10, 'FIXED_IMAGE_ONLY_NOT_BUSINESS_PROCESS_CONFIG')
    for receipt in value['native_operations']:
        expect(type(receipt['pid']) is int and receipt['pid'] > 0 and type(receipt['native_exit']) is int
            and receipt['native_exit'] == receipt['wait_returncode'] == 0 and receipt['stderr'] == {'bytes': 0, 'sha256': digest(b'')}, 'ACTUAL_REFERENCE_NATIVE_ZERO')
    return value

def proof_ini_group(binding):
    if 'proof_php_ini' not in binding:
        return None
    group = binding['proof_php_ini']
    expect(type(group) is dict and set(group) == {'schema', 'scope', 'source_root', 'files', 'roles', 'scan_dir', 'config_reference'}
        and type(group['schema']) is int and group['schema'] == 1 and group['scope'] == 'MS4_PROOF_PHP_INI_RESOURCE_SOURCE'
        and type(group['source_root']) is str and Path(group['source_root']).is_absolute()
        and group['files'] == [{'path': PROOF_INI_LEAF, **PROOF_INI_PIN}]
        and group['roles'] == PROOF_INI_ROLES and group['scan_dir'] == PROOF_INI_SCAN
        and group['config_reference'] == PROOF_INI_REFERENCE, 'PROOF_INI_EXACT_FROZEN_RESOURCE_GROUP')
    proof_ini_directory(group['source_root']); proof_ini_reference()
    return group

def proof_ini_freeze(source_root):
    root = Path(source_root)
    proof_ini_directory(root)
    root = root.resolve(strict=True)
    value = {'schema': 1, 'scope': 'MS4_PROOF_PHP_INI_RESOURCE_SOURCE', 'source_root': root.as_posix(),
        'files': [{'path': PROOF_INI_LEAF, **PROOF_INI_PIN}], 'roles': PROOF_INI_ROLES,
        'scan_dir': PROOF_INI_SCAN, 'config_reference': PROOF_INI_REFERENCE}
    proof_ini_group({'proof_php_ini': value})
    return value

def proof_ini_group_sha(group):
    return digest(json.dumps(group, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode('utf-8'))

def proof_ini_copy_value(group, directory, run, source, freeze_sha):
    return {'schema': 1, 'scope': 'MS4_PROOF_PHP_INI_COPY', 'run_id': run, 'source_sha': source,
        'freeze_sha256': freeze_sha, 'resource_sha256': proof_ini_group_sha(group),
        'original': {'path': (Path(group['source_root']) / PROOF_INI_LEAF).as_posix(), **PROOF_INI_PIN},
        'copy': {'path': (Path(directory) / 'proof-php-ini' / PROOF_INI_LEAF).as_posix(), **PROOF_INI_PIN}}

def proof_ini_prepare(binding, directory, run, freeze_sha):
    group = proof_ini_group(binding)
    if group is None:
        return None
    raw = proof_ini_directory(group['source_root']); target = Path(directory) / 'proof-php-ini'
    expect(not target.exists() and not target.is_symlink(), 'NEW_PROOF_INI_RUN_COPY_DIRECTORY')
    target.mkdir(mode=0o700)
    with (target / PROOF_INI_LEAF).open('xb') as stream:
        expect(stream.write(raw) == len(raw), 'COMPLETE_CREATE_ONLY_PROOF_INI_COPY')
    expect(proof_ini_directory(target) == raw and proof_ini_directory(group['source_root']) == raw, 'PROOF_INI_ORIGINAL_COPY_FULL_BYTES')
    path = Path(directory) / PROOF_INI_COPY
    new(path, proof_ini_copy_value(group, directory, run, binding['source_sha'], freeze_sha))
    saved = proof_ini_raw(path)
    return {'path': PROOF_INI_COPY, 'bytes': len(saved), 'sha256': digest(saved)}

def proof_ini_current(context, binding=None):
    if binding is None:
        binding = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    group = proof_ini_group(binding)
    if group is None:
        expect('proof_php_ini_copy' not in context, 'NO_UNFROZEN_PROOF_INI_COPY')
        return None
    row = context.get('proof_php_ini_copy')
    expect(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and row['path'] == PROOF_INI_COPY
        and type(row['bytes']) is int and row['bytes'] > 0 and type(row['sha256']) is str
        and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None, 'ACTUAL_PROOF_INI_CONTEXT_COPY_DESCRIPTOR')
    raw = proof_ini_raw(Path(context['run_dir']) / PROOF_INI_COPY)
    expect({'path': PROOF_INI_COPY, 'bytes': len(raw), 'sha256': digest(raw)} == row
        and paid_equal(json.loads(raw), proof_ini_copy_value(group, context['run_dir'], context['run_id'], context['source_sha'], context['freeze_sha256'])), 'EXACT_COMPLETE_PROOF_INI_COPY_BINDING')
    expect(proof_ini_directory(Path(context['run_dir']) / 'proof-php-ini') == proof_ini_directory(group['source_root']), 'PROOF_INI_RUN_ORIGINAL_COMPLETE_NAMESPACE_BYTES')
    return group

def proof_ini_env_template():
    scan = 'PHP_INI_SCAN_DIR='; phprc = 'PHPRC='
    def prefix(value):
        return '(and (ge (len .) %d) (eq (slice . 0 %d) %s))' % (len(value), len(value), json.dumps(value))
    return ('[{{range .Config.Env}}{{if ' + prefix(scan) + '}}{{if eq . ' + json.dumps(scan + PROOF_INI_SCAN)
        + '}}{{json "CANONICAL_PROOF_SCAN"}}{{else}}{{json "UNEXPECTED_PROOF_SCAN"}}{{end}}{{else if '
        + prefix(phprc) + '}}{{json "UNEXPECTED_PHPRC"}}{{end}}{{end}}]')

def proof_ini_environment(context, cid, role):
    group = proof_ini_current(context)
    if group is None:
        return
    expect(re.fullmatch('[a-f0-9]{64}', cid) is not None, 'PROOF_INI_REAL_OWNED_CID')
    folder = Path(context['run_dir']) / PROOF_INI_ENV_FOLDER
    if not folder.exists():
        folder.mkdir(mode=0o700)
    expect(folder.is_dir() and not folder.is_symlink() and not (getattr(folder.lstat(), 'st_file_attributes', 0) & 1024), 'PROOF_INI_PUBLIC_ENV_NAMESPACE')
    records = sorted(folder.glob('*.record.json'))
    wanted_files = {path.stem[:-7] + suffix for path in records for suffix in ['.created.json', '.native.json', '.stdout', '.stderr', '.record.json']}
    expect({path.name for path in folder.iterdir()} == wanted_files, 'NO_PARTIAL_OR_UNKNOWN_PROOF_INI_NATIVE_RECORD')
    stem = '%06d' % (len(records) + 1)
    argv = ['docker', 'inspect', '--type', 'container', '--format', proof_ini_env_template(), cid]
    process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    new(folder / (stem + '.created.json'), {'argv': argv, 'pid': process.pid})
    stdout, stderr = process.communicate(); waited = process.wait()
    for name, raw in [('.stdout', stdout), ('.stderr', stderr)]:
        with (folder / (stem + name)).open('xb') as stream:
            expect(stream.write(raw) == len(raw), 'PROOF_INI_COMPLETE_NATIVE_RAW_WRITE')
        expect(proof_ini_raw(folder / (stem + name)) == raw, 'PROOF_INI_COMPLETE_NATIVE_RAW_READBACK')
    receipt = {'argv': argv, 'pid': process.pid, 'native_exit': process.returncode, 'wait_returncode': waited,
        'stdout_bytes': len(stdout), 'stdout_sha256': digest(stdout), 'stderr_bytes': len(stderr), 'stderr_sha256': digest(stderr)}
    new(folder / (stem + '.native.json'), receipt)
    expected = b'["CANONICAL_PROOF_SCAN"]\n' if role in PROOF_INI_ROLES else b'[]\n'
    expect(type(process.returncode) is int and process.returncode == waited == 0 and stderr == b'' and stdout == expected, 'ACTUAL_ROLE_ONLY_SCAN_AND_ALL_OTHER_ROLE_ABSENCE_NO_PHPRC')
    record = {'schema': 1, 'scope': 'MS4_PROOF_PHP_INI_ENVIRONMENT_CLASSIFICATION', 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'container_id': cid, 'role': role,
        'copy_sha256': context['proof_php_ini_copy']['sha256'], 'classification': 'CANONICAL_PROOF_SCAN' if role in PROOF_INI_ROLES else 'NO_SCAN_OR_PHPRC'}
    for key, suffix in [('native_created', '.created.json'), ('native_terminal', '.native.json'), ('stdout', '.stdout'), ('stderr', '.stderr')]:
        leaf = PROOF_INI_ENV_FOLDER + '/' + stem + suffix; raw = proof_ini_raw(Path(context['run_dir']) / leaf)
        record[key] = {'path': leaf, 'bytes': len(raw), 'sha256': digest(raw)}
    new(folder / (stem + '.record.json'), record)

def proof_ini_audit(context, coverage=False):
    group = proof_ini_current(context)
    if group is None:
        return
    folder = Path(context['run_dir']) / PROOF_INI_ENV_FOLDER
    expect(folder.is_dir() and not folder.is_symlink() and not (getattr(folder.lstat(), 'st_file_attributes', 0) & 1024), 'PROOF_INI_NATIVE_EVIDENCE_DIRECTORY')
    known = {cid: role for role, cid in context['initial_service_ids'].items()}
    for path in (Path(context['run_dir']) / 'evidence').glob('*-created.json'):
        value = load(path)
        if type(value) is dict and 'id' in value and 'labels' in value:
            role = value['labels']['com.docker.compose.service']; cid = value['id']
            expect(cid not in known or known[cid] == role, 'PROOF_INI_SAME_HISTORICAL_ROLE')
            known[cid] = role
    seen = set(); names = set()
    for index, path in enumerate(sorted(folder.glob('*.record.json')), 1):
        stem = '%06d' % index; expect(path.name == stem + '.record.json', 'ORDERED_COMPLETE_PROOF_INI_RECORDS')
        record = load(path)
        expect(type(record) is dict and set(record) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'role', 'copy_sha256',
            'classification', 'native_created', 'native_terminal', 'stdout', 'stderr'} and type(record['schema']) is int and record['schema'] == 1
            and record['scope'] == 'MS4_PROOF_PHP_INI_ENVIRONMENT_CLASSIFICATION'
            and all(record[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and record['copy_sha256'] == context['proof_php_ini_copy']['sha256'] and known.get(record['container_id']) == record['role'], 'OWN_EXACT_PROOF_INI_ENV_RECORD')
        raw = {}
        for key, suffix in [('native_created', '.created.json'), ('native_terminal', '.native.json'), ('stdout', '.stdout'), ('stderr', '.stderr')]:
            relative = PROOF_INI_ENV_FOLDER + '/' + stem + suffix; descriptor = record[key]
            data = proof_ini_raw(Path(context['run_dir']) / relative)
            expect(type(descriptor) is dict and set(descriptor) == {'path', 'bytes', 'sha256'} and type(descriptor['bytes']) is int
                and descriptor == {'path': relative, 'bytes': len(data), 'sha256': digest(data)}, 'WHOLE_PROOF_INI_NATIVE_DESCRIPTOR')
            raw[key] = data; names.add(stem + suffix)
        names.add(path.name); native = json.loads(raw['native_terminal']); created = json.loads(raw['native_created'])
        argv = ['docker', 'inspect', '--type', 'container', '--format', proof_ini_env_template(), record['container_id']]
        expected = b'["CANONICAL_PROOF_SCAN"]\n' if record['role'] in PROOF_INI_ROLES else b'[]\n'
        expect(type(native) is dict and set(native) == {'argv', 'pid', 'native_exit', 'wait_returncode', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256'}
            and native['argv'] == argv and type(native['pid']) is int and native['pid'] > 0
            and type(created) is dict and set(created) == {'argv', 'pid'} and type(created['pid']) is int
            and created == {'argv': argv, 'pid': native['pid']} and type(native['native_exit']) is int and type(native['wait_returncode']) is int
            and native['native_exit'] == native['wait_returncode'] == 0 and raw['stdout'] == expected and raw['stderr'] == b''
            and type(native['stdout_bytes']) is int and type(native['stderr_bytes']) is int
            and native['stdout_bytes'] == len(expected) and native['stdout_sha256'] == digest(expected)
            and native['stderr_bytes'] == 0 and native['stderr_sha256'] == digest(b'')
            and record['classification'] == ('CANONICAL_PROOF_SCAN' if record['role'] in PROOF_INI_ROLES else 'NO_SCAN_OR_PHPRC'), 'ACTUAL_PROOF_INI_COMPLETE_SAFE_NATIVE_RECEIPT')
        seen.add(record['container_id'])
    expect({item.name for item in folder.iterdir()} == names, 'PROOF_INI_NATIVE_NAMESPACE_EXACT_COMPLETE')
    if coverage:
        expect(set(known) == seen, 'PROOF_INI_CLASSIFIED_ALL_CREATED_ROLES_NO_OMISSION')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=['freeze', 'prepare', 'db-up', 'initializer-start', 'initializer-resume',
        'driver-create', 'driver-bind', 'driver-start', 'early-paid-create', 'files-bootstrap-create', 'files-bootstrap-start',
        'customer-prepare-create', 'customer-prepare-bind', 'customer-prepare-start',
        'customer-create', 'customer-bind', 'customer-start', 'observe', 'finish-helper', 'authorize-close',
        'paid-create', 'paid-bind', 'paid-start', 'authorize-paid-close',
        'fulfillment-migration-create', 'fulfillment-migration-bind', 'fulfillment-migration-start', 'fulfillment-migration-retire',
        'fulfillment-normal-create', 'fulfillment-normal-bind', 'fulfillment-normal-start', 'authorize-fulfillment-close',
        'fulfillment-normal-retire', 'aftersale-review-create', 'aftersale-review-bind', 'aftersale-review-start', 'authorize-aftersale-review-close',
        'authorize-aftersale-review-close', 'aftersale-review-retire',
        'worker-create', 'worker-bind', 'worker-start', 'authorize-worker-close'])
    parser.add_argument('--repo'); parser.add_argument('--source'); parser.add_argument('--output')
    parser.add_argument('--proof-ini-source')
    parser.add_argument('--binding'); parser.add_argument('--run-root'); parser.add_argument('--context'); parser.add_argument('--proof')
    parser.add_argument('--seconds', type=int, default=30)
    args = parser.parse_args(); expect(0 <= args.seconds <= 30, 'BOUNDED_OBSERVATION')
    if args.stage == 'freeze':
        expect(args.repo and args.source and args.output, 'EXPLICIT_CLEAN_SOURCE_FREEZE')
        result = freeze(args.repo, args.source, args.output, args.proof_ini_source)
    elif args.stage == 'prepare':
        expect(args.binding and args.run_root, 'EXPLICIT_NEW_RUN_PREPARATION')
        result = prepare(args.binding, args.run_root)
    else:
        expect(args.context, 'EXPLICIT_ACTUAL_RUN_CONTEXT')
        result = stage(args.stage, args.context, args.proof, args.seconds)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'PRESERVED_FAILURE_OR_OBSERVATION_REQUIRED', 'class': type(error).__name__,
            'attempt_context': ATTEMPT_CONTEXT, 'resources_not_automatically_deleted': True, 'customer_HTTP_acceptance': 'NOT CLAIMED',
            'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
            'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}))
        raise SystemExit(1)
