"""Finite source-bound customer H5 preparation and final service stages; no automatic Run orchestration."""
import argparse
import importlib.util
import io
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile

PREP = Path(__file__).resolve().parent
ATTEMPT_CONTEXT = None
H5_SOURCES = ['help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php',
              'help/audit-regressions/isolated/ms4-customer-h5.php']
H5_ARTIFACTS = ['H5-README.md', 'compose.h5.yml', 'h5-derivation.json', 'ms4-h5-host.py',
                'ms4-h5-materials.py', 'ms4-h5-bootstrap.php', 'ms4-h5-public-copy.php',
                'ms4-h5-nginx.conf', 'ms4-order.png', 'inputs/language.json', 'inputs/theme.json',
                'manifests/public-source.json', 'manifests/h5-dist.json', 'manifests/inputs.json',
                'h5-materials-result.json', 'browser-v1/customer-network-observer.mjs',
                'browser-v1/ms4-customer-private-bridge.mjs', 'browser-v1/ms4-customer-browser.mjs',
                'normal-close-customer-h5.py', 'verify-zero-customer-h5.py']
BUILD_REFERENCES = ['BUILD-REPORT.json', 'MAIN-H5-INPUTS.json', 'TOOL-INPUTS.json', 'SOURCE-CANDIDATE.json', 'COMPILER-NATIVE.json', 'NATIVE-TOOL-CONTRACT.json', 'DIST-MANIFEST.json', 'input-checks.py', 'verify-build.py', 'build-native.py', 'native-explicit-pass.cjs', 'logs/adapter-receipt.json', 'logs/compiler.log', 'bootstrap-head.native.json', 'bootstrap-head.stdout', 'bootstrap-head.stderr', 'bootstrap-status.native.json', 'bootstrap-status.stdout', 'bootstrap-status.stderr', 'bootstrap-source-files.native.json', 'bootstrap-source-files.stdout', 'bootstrap-source-files.stderr', 'bootstrap-source-stages.native.json', 'bootstrap-source-stages.stdout', 'bootstrap-source-stages.stderr', 'clone-tools.native.json', 'clone-tools.stdout', 'clone-tools.stderr', 'prepare-source.native.json', 'prepare-source.stdout', 'prepare-source.stderr', 'prepare-adapter.native.json', 'prepare-adapter.stdout', 'prepare-adapter.stderr', 'build-native.native.json', 'build-native.stdout', 'build-native.stderr', 'verify-build.native.json', 'verify-build.stdout', 'verify-build.stderr', 'root-run-build.py', 'prepare-source.py', 'clone-tools.ps1', 'prepare-native-adapter.py']


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    instance = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(instance)
    return instance


def freeze(repo, head, port, output, proof_ini_source=None):
    base = module('ms4_h5_original_prerequisites', PREP / 'ms4-host.py')
    materials = module('ms4_h5_local_materials', PREP / 'ms4-h5-materials.py')
    base.expect(re.fullmatch('[a-f0-9]{40}', head) is not None and 1024 <= port <= 65535,
                'ACTUAL_HEAD_AND_FINITE_LOOPBACK_PORT')
    base.clean(repo, head)
    current28dc_source_contract(base, repo, head)
    base.expect(not output.resolve().is_relative_to(repo)
                and output.parent.is_dir() and not output.exists() and not output.is_symlink(),
                'NEW_OUTSIDE_H5_FREEZE')
    # Freeze all twenty actual final-profile files before producing any prerequisite freeze or Run.
    # In particular, absent close/zero sources cannot be replaced by placeholders or a success marker.
    h5_artifacts = [base.source_row(PREP, path) for path in H5_ARTIFACTS]
    for path in H5_SOURCES:
        base.expect(base.git(repo, 'ls-files', '--', path) == path, 'TRACKED_H5_PREPARATION_SOURCE')
    h5_sources = [base.source_row(repo, path) for path in H5_SOURCES]
    manifest = base.load(PREP / 'h5-materials-result.json')
    base.expect(manifest['scope'] == 'CUSTOMER_H5_LOCAL_MATERIAL_BYTES_ONLY'
                and manifest['status'] == 'MATERIALS_CREATED_NOT_RUNTIME'
                and manifest['Run_App_SQL_HTTP_DOM'] == 'NOT RUN', 'ACTUAL_MATERIAL_PRODUCER_RECORD')
    for key, name in [('public_source', 'public-source.json'), ('h5_dist', 'h5-dist.json'),
                      ('inputs', 'inputs.json')]:
        row = manifest['manifests'][key]
        path = PREP / 'manifests' / name
        base.expect(row == {'path': '/run/ms4-prep/manifests/' + name,
                            **materials.fp(materials.read(path))}, 'EXACT_GENERATED_MANIFEST_BYTES')
    source = base.load(PREP / 'manifests/public-source.json')
    dist = base.load(PREP / 'manifests/h5-dist.json')
    inputs = base.load(PREP / 'manifests/inputs.json')
    base.expect(materials.tree(repo / 'crmeb/public') == source, 'CURRENT_FULL_PUBLIC_SOURCE')
    base.expect(inputs == {'schema': 1, **materials.INPUTS}, 'EXACT_TWO_APPROVED_INPUT_DESCRIPTORS')
    for key, row in materials.INPUTS.items():
        base.expect(materials.fp(materials.read(PREP / 'inputs' / (key + '.json')))
                    == {field: row[field] for field in ['bytes', 'sha256']}, 'CURRENT_FIXED_INPUT_BYTES')
    actual_dist, reuse = materials.build_reuse(repo, head)
    base.expect(actual_dist == dist, 'CURRENT_COMPLETE_H5_DIST_AND_EMPTY_DIRECTORIES')
    png = {'path': '/run/ms4-prep/ms4-order.png', **materials.fp(materials.read(PREP / 'ms4-order.png'))}
    base.expect(png == manifest['synthetic_png'], 'UNCHANGED_ORIGINAL_FIXTURE_PNG')
    provenance = [base.source_row(materials.BUILD, path) for path in BUILD_REFERENCES]
    prerequisite = output.with_name(output.name + '.prerequisites.json')
    base.expect(not prerequisite.exists() and not prerequisite.is_symlink(), 'NEW_ORIGINAL_PREREQUISITE_FREEZE')
    base.freeze(repo, head, prerequisite, proof_ini_source)
    value = base.load(prerequisite)
    base.verify_freeze(value)
    value.update({'h5_source_files': h5_sources, 'h5_artifact_files': h5_artifacts,
                  'h5_materials': {'runtime_origin': 'http://127.0.0.1:' + str(port),
                                   'public_source': manifest['manifests']['public_source'],
                                   'dist': manifest['manifests']['h5_dist'],
                                   'inputs': manifest['manifests']['inputs'], 'fixture_png': png},
                  'h5_dist_source': (materials.BUILD / 'dist').as_posix(),
                  'h5_build_provenance': {'build_directory': materials.BUILD.as_posix(),
                                          'files': provenance, 'current_reuse': reuse},
                  'h5_preparation_scope': 'SOURCE_FROZEN_NOT_RUNTIME',
                  'h5_runtime_source_status': 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED',
                  'h5_serve_browser_acceptance': 'NOT RUN'})
    base.expect(h5_artifacts == [base.source_row(PREP, path) for path in H5_ARTIFACTS]
                and h5_sources == [base.source_row(repo, path) for path in H5_SOURCES]
                and provenance == [base.source_row(materials.BUILD, path) for path in BUILD_REFERENCES]
                and materials.tree(repo / 'crmeb/public') == source
                and materials.tree(materials.BUILD / 'dist') == dist,
                'ALL_H5_SOURCE_AND_MATERIAL_BYTES_AFTER_UNCHANGED')
    base.clean(repo, head)
    digest = base.new(output, value)
    return {'status': 'CLEAN_H5_FINAL_PROFILE_SOURCE_FROZEN_ONLY', 'source_sha': head,
            'freeze_sha256': digest, 'h5_sources': len(h5_sources), 'h5_artifacts': len(h5_artifacts),
            'current_frontend_bytes_equal': reuse['all_frontend_paths_blobs_and_bytes_equal'],
            'original_build_head': reuse['original_build_head'], 'runtime': 'NOT RUN',
            'runtime_source_status': 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED',
            'serve_browser_acceptance': 'NOT RUN'}


H5_VOLUMES = ['http-runtime', 'http-control', 'http-browser-secrets', 'http-public']
H5_FINAL_ARTIFACTS = H5_ARTIFACTS
H5_FINAL_STAGES = ['final-create', 'final-register-create', 'final-register-start',
    'final-register-observe', 'final-register-finish', 'final-bind', 'final-serve-start', 'final-ui-start']
H5_FINAL_PROOFS = {
    'customer-h5-serve': {'role': 'audit-ms4-customer-h5', 'command': [
        'help/audit-regressions/isolated/ms4-customer-h5.php', 'serve']},
    'customer-h5-ui': {'role': 'audit-ui', 'command': ['-g', 'daemon off;', '-c', '/etc/nginx/nginx.conf']},
    'register-customer-h5-serve': {'role': 'audit-ms4-customer-h5-register',
        'command': ['/run/ms4-prep/ms4-h5-bootstrap.php', 'register-final']},
}
H5_NGINX = 'sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236'
H5_NETWORK_FULL = '{"Id":{{json .Id}},"Name":{{json .Name}},"Driver":{{json .Driver}},"Scope":{{json .Scope}},"Internal":{{json .Internal}},"EnableIPv6":{{json .EnableIPv6}},"Options":{{json .Options}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'
H5_ENTRY = 'help/audit-regressions/isolated/ms4-customer-h5.php'
H5_BOOTSTRAP = '/run/ms4-prep/ms4-h5-bootstrap.php'
H5_PROOFS = {
    'customer-h5-public-copy': {'role': 'audit-ms4-customer-h5-public-copy', 'phase': 'copy',
                              'command': ['/run/ms4-prep/ms4-h5-public-copy.php', 'copy']},
    'customer-h5-prepare': {'role': 'audit-ms4-customer-h5-prepare', 'phase': 'prepare',
                          'command': [H5_ENTRY, 'prepare']},
    'customer-h5-files': {'role': 'audit-ms4-customer-h5-files', 'phase': 'files-bootstrap',
                        'command': [H5_BOOTSTRAP, 'files-bootstrap']},
    'register-customer-h5-prepare': {'role': 'audit-ms4-customer-h5-register', 'phase': 'register',
                                   'command': [H5_BOOTSTRAP, 'register']},
    'register-customer-h5-files': {'role': 'audit-ms4-customer-h5-register', 'phase': 'register',
                                 'command': [H5_BOOTSTRAP, 'register']},
}
ORIGINAL_STAGES = ['db-up', 'initializer-start', 'initializer-resume', 'driver-create', 'driver-bind',
                   'driver-start', 'early-paid-create', 'fulfillment-migration-create', 'fulfillment-migration-bind',
                   'fulfillment-migration-start', 'fulfillment-migration-retire', 'files-bootstrap-create', 'files-bootstrap-start',
                   'customer-prepare-create', 'customer-prepare-bind', 'customer-prepare-start',
                   'observe', 'finish-helper']
VOLUME_TEMPLATE = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
NETWORK_TEMPLATE = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}}}'


def dependencies():
    return module('ms4_h5_original_runtime', PREP / 'ms4-host.py'), module(
        'ms4_h5_runtime_materials', PREP / 'ms4-h5-materials.py')


def verify_h5_freeze(base, materials, frozen, artifact_paths=H5_ARTIFACTS):
    base.verify_freeze(frozen)
    repo = Path(frozen['requested_repo_source'])
    current28dc_source_contract(base, repo, frozen['source_sha'])
    base.expect(frozen['h5_preparation_scope'] == 'SOURCE_FROZEN_NOT_RUNTIME'
                and frozen['h5_runtime_source_status'] == 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED'
                and frozen['h5_serve_browser_acceptance'] == 'NOT RUN'
                and artifact_paths == H5_ARTIFACTS and len(artifact_paths) == 20
                and set(frozen['h5_materials']) == {'runtime_origin', 'public_source', 'dist', 'inputs', 'fixture_png'}
                and frozen['h5_source_files'] == [base.source_row(repo, path) for path in H5_SOURCES]
                and frozen['h5_artifact_files'] == [base.source_row(PREP, path) for path in artifact_paths],
                'EXACT_CURRENT_H5_SOURCE_ARTIFACT_FREEZE')
    for name, filename in [('public_source', 'public-source.json'), ('dist', 'h5-dist.json'), ('inputs', 'inputs.json')]:
        path = PREP / 'manifests' / filename
        base.expect(frozen['h5_materials'][name] == {'path': '/run/ms4-prep/manifests/' + filename,
                                                  **materials.fp(materials.read(path))}, 'EXACT_CURRENT_H5_MANIFEST_BYTES')
    base.expect(base.load(PREP / 'manifests/public-source.json') == materials.tree(repo / 'crmeb/public')
                and base.load(PREP / 'manifests/inputs.json') == {'schema': 1, **materials.INPUTS},
                'COMPLETE_CURRENT_H5_PUBLIC_AND_APPROVED_INPUTS')
    for name, descriptor in materials.INPUTS.items():
        base.expect(materials.fp(materials.read(PREP / 'inputs' / (name + '.json')))
                    == {key: descriptor[key] for key in ['bytes', 'sha256']}, 'EXACT_REVIEWED_H5_INPUT_BYTES')
    actual_dist, reuse = materials.build_reuse(repo, frozen['source_sha'])
    provenance = frozen['h5_build_provenance']
    base.expect(actual_dist == base.load(PREP / 'manifests/h5-dist.json')
                and provenance == {'build_directory': materials.BUILD.as_posix(),
                                   'files': [base.source_row(materials.BUILD, path) for path in BUILD_REFERENCES],
                                   'current_reuse': reuse}
                and frozen['h5_dist_source'] == (materials.BUILD / 'dist').as_posix(), 'COMPLETE_CURRENT_BUILD_SOURCE_REUSE_BYTES')
    base.expect(frozen['h5_materials']['fixture_png'] == {'path': '/run/ms4-prep/ms4-order.png',
                                                       **materials.fp(materials.read(PREP / 'ms4-order.png'))}, 'CURRENT_H5_PNG_BYTES')
    origin = frozen['h5_materials']['runtime_origin']
    match = re.fullmatch(r'http://127\.0\.0\.1:([1-9][0-9]{0,4})', origin)
    base.expect(match is not None and 1024 <= int(match[1]) <= 65535, 'FROZEN_ONLY_H5_LOOPBACK_ORIGIN')


def runtime(context_file):
    base, materials = dependencies()
    context = base.current(context_file, False)
    frozen = base.load(Path(context['run_dir']) / 'preparation-source-binding.json')
    verify_h5_freeze(base, materials, frozen)
    return base, materials, context, frozen


def evidence(context, name):
    return Path(context['run_dir']) / 'evidence' / name


def descriptor(base, path, container_path):
    raw = path.read_bytes()
    base.expect(path.is_file() and not path.is_symlink() and raw, 'ACTUAL_NONEMPTY_PUBLIC_DESCRIPTOR_FILE')
    return {'path': container_path, 'bytes': len(raw), 'sha256': base.digest(raw)}


def compose_h5(base, context, frozen, args, label, proof):
    base.expect(proof in H5_PROOFS, 'FINITE_H5_COMPOSE_PROOF')
    spec = H5_PROOFS[proof]
    env = os.environ.copy()
    env.update({'AUDIT_RUN_ID': context['run_id'], 'AUDIT_PROJECT': context['project'], 'AUDIT_SOURCE_SHA': context['source_sha'],
                'AUDIT_REPO_DIR': context['repo_dir'], 'AUDIT_RUN_DIR': context['run_dir'], 'MS4_PREP_DIR': context['prep_dir'],
                'MS4_PROOF_NAME': proof, 'MS4_BOOTSTRAP_PHASE': spec['phase'], 'MS4_BOOTSTRAP_PRIVATE_MODE': 'ro',
                'MS4_H5_REGISTER_MODE': 'register', 'MS4_H5_PORT': frozen['h5_materials']['runtime_origin'].rsplit(':', 1)[1],
                'MS4_CUSTOMER_SELF_MER_ID': str(base.actual_self(context)), 'MS4_H5_DIST': frozen['h5_dist_source']})
    process = subprocess.run(['docker', 'compose', '--project-name', context['project'],
                              '--file', str(PREP / 'compose.yml'), '--file', str(PREP / 'compose.h5.yml'), *args],
                             capture_output=True, env=env)
    base.new(evidence(context, label + '-compose-native.json'), {'stage': label, 'native_exit': process.returncode,
        'stdout_bytes': len(process.stdout), 'stdout_sha256': base.digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': base.digest(process.stderr)})
    base.expect(process.returncode == 0, 'H5_COMPOSE_NATIVE_ZERO')
    return process.stdout.decode('utf-8').strip()


def h5_owned(base, context, frozen, facts, role, proof=None):
    base.owned(context, facts, role)
    if role == base.PAID['role']:
        canonical = base.current_unstarted_paid(context)
        native_facts = {key: value for key, value in facts.items() if key not in ['run_id', 'source_sha', 'proof_name']}
        base.expect(base.unstarted_paid_native_equal(native_facts, canonical), 'H5_SAME_FULL_NEVER_STARTED_CANONICAL_OWNER')
        return
    if role in ['audit-mysql', 'audit-redis']:
        base.same_created(context['initial_service_created'][role], facts)
        return
    base.expect(proof in H5_PROOFS and H5_PROOFS[proof]['role'] == role, 'FINITE_H5_NATIVE_ROLE_PROOF')
    spec = H5_PROOFS[proof]
    base.expect(facts['command'] == spec['command'] and facts['user'] in ['', '0', '0:0', 'root']
                and set(facts['tmpfs']) == {'/tmp'} and type(facts['tmpfs']['/tmp']) is str
                and 'rw' in facts['tmpfs']['/tmp'].split(','), 'H5_EXACT_COMMAND_ROOT_USER_AND_TMPFS')
    expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
        '/run/ms4-prep': ('bind', context['prep_dir'], False),
        '/run/audit': ('bind', context['run_dir'], spec['phase'] in ['copy', 'register']),
        '/run/audit-app': ('volume', context['project'] + '_app-secrets', False)}
    if spec['phase'] == 'copy':
        expected['/run/ms4-h5-dist'] = ('bind', frozen['h5_dist_source'], False)
    if spec['phase'] != 'register':
        expected.update({'/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', False),
            '/workspace/crmeb/public': ('volume', context['project'] + '_http-public', spec['phase'] == 'copy'),
            '/workspace/crmeb/runtime': ('volume', context['project'] + '_http-runtime', True),
            '/run/audit-http-control': ('volume', context['project'] + '_http-control', True)})
        if spec['phase'] == 'files-bootstrap':
            expected['/run/audit-bootstrap'] = ('volume', context['project'] + '_bootstrap-secrets', False)
        else:
            expected['/run/audit-http-browser'] = ('volume', context['project'] + '_http-browser-secrets', True)
    mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    tmp = [row for row in facts['mounts'] if row['Type'] == 'tmpfs']
    base.expect(len(tmp) <= 1 and all(row['Destination'] == '/tmp' and row['RW'] is True for row in tmp)
                and len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected),
                'H5_COMPLETE_NATIVE_MOUNT_MULTISET')
    for row in mounts:
        kind, source, rw = expected[row['Destination']]
        base.expect(row['Type'] == kind and row['RW'] is rw
                    and (base.canonical_host(row['Source']) == base.canonical_host(source)
                         if kind == 'bind' else row['Name'] == source), 'H5_EXACT_NATIVE_MOUNT_SOURCE_ACCESS')
    aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
    base.expect('audit-app' not in aliases, 'PRE_UI_HAS_NO_SERVE_ALIAS')


def base_expected(base, context):
    original = base.base_roles(context)
    paid = base.current_unstarted_paid(context)
    base.expect(paid['id'] not in original, 'H5_CANONICAL_DISTINCT_FROM_ORIGINAL_DATABASES')
    return original | {paid['id']: base.PAID['role']}


def global_consumers_h5(base, context, allowed):
    ids = base.native(context, ['ps', '-aq', '--no-trunc'], 'h5-global-consumers').splitlines()
    if not ids:
        return
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'
    lines = base.native(context, ['inspect', '--format', template, *ids], 'h5-global-consumer-facts').splitlines()
    private = {context['project'] + '_' + name for name in [*base.VOLUMES, *H5_VOLUMES]}
    for line in lines:
        row = json.loads(line)
        names = {mount.get('Name') for mount in row['mounts'] if mount.get('Type') == 'volume'}
        if names & private or context['project'] + '_isolation' in row['networks']:
            base.expect(row['id'] in allowed, 'H5_FOREIGN_CONSUMER_OF_ANY_TEN_VOLUMES_OR_NETWORK')


def h5_volumes(base, context, record_new=False):
    wanted = {context['project'] + '_' + name for name in [*base.VOLUMES, *H5_VOLUMES]}
    project = set(base.ids(context, 'volume', 'com.docker.compose.project=' + context['project']))
    run = set(base.ids(context, 'volume', 'tianli.audit.run=' + context['run_id']))
    base.expect(project == run == wanted, 'H5_EXACT_TEN_NATIVE_VOLUME_NAMES')
    rows = []
    for name in sorted(wanted):
        row = json.loads(base.native(context, ['volume', 'inspect', '--format', VOLUME_TEMPLATE, name], 'h5-native-volume'))
        base.expect(set(row) == {'name', 'created_at', 'labels'} and row['name'] == name
                    and type(row['created_at']) is str and row['created_at']
                    and row['labels'].get('tianli.audit') == '1'
                    and row['labels'].get('tianli.audit.run') == context['run_id']
                    and row['labels'].get('com.docker.compose.project') == context['project'], 'H5_NATIVE_VOLUME_COMPLETE_IDENTITY')
        rows.append(row)
    original_names = {context['project'] + '_' + name for name in base.VOLUMES}
    original = [row for row in rows if row['name'] in original_names]
    additional = [row for row in rows if row['name'] not in original_names]
    base.expect(original == context['initial_volumes'], 'H5_ORIGINAL_SIX_VOLUME_IDENTITIES_PRESERVED')
    value = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_ADDITIONAL_NATIVE_VOLUMES_ONLY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'original_context_sha256': base.digest((Path(context['run_dir']) / 'actual-run-context.json').read_bytes()),
        'network_id': context['network_id'], 'additional_volumes': additional, 'all_volume_names': sorted(wanted)}
    path = evidence(context, 'customer-h5-additional-volume-identities.json')
    if record_new:
        base.new(path, value)
    else:
        base.expect(base.load(path) == value, 'H5_SAME_FOUR_ADDITIONAL_NATIVE_VOLUME_IDENTITIES')
    return rows


def collect_h5(base, context, frozen, expected, proofs, publish=False):
    base.expect(base.active(context) == set(expected), 'H5_EXACT_ACTIVE_NATIVE_CID_SET')
    original = base_expected(base, context)
    base.expect(all(expected.get(cid) == role for cid, role in original.items()), 'H5_EXPECTED_ORIGINAL_DB_CIDS')
    names = base.ids(context, 'network', 'com.docker.compose.project=' + context['project'])
    run_names = base.ids(context, 'network', 'tianli.audit.run=' + context['run_id'])
    base.expect(len(names) == 1 and names == run_names, 'H5_ONLY_ONE_PROJECT_RUN_BACKEND_NETWORK')
    network = json.loads(base.native(context, ['network', 'inspect', '--format', NETWORK_TEMPLATE, names[0]], 'h5-native-network'))
    base.expect(network == context['initial_network'] and network['Id'] == context['network_id']
                and network['Internal'] is True, 'H5_SAME_ORIGINAL_INTERNAL_NETWORK')
    rows = []
    facts_by_id = {}
    for cid, role in expected.items():
        facts = base.inspect(context, cid, 'h5-collect-native')
        h5_owned(base, context, frozen, facts, role, proofs.get(cid))
        if cid not in original:
            base.same_created(bound_h5(base, context, frozen, proofs[cid]), facts)
        base.proof_ini_environment(context, cid, role)
        facts_by_id[cid] = facts
        rows.append({'id': cid, 'image_id': facts['image'], 'mounts': facts['mounts'], 'labels': facts['labels'],
            'networks': facts['networks'], 'ports': facts['ports'], 'read_only': facts['read_only'],
            'privileged': facts['privileged'], 'network_mode': facts['network_mode']})
    volumes = h5_volumes(base, context)
    global_consumers_h5(base, context, set(expected))
    if publish:
        root = Path(context['run_dir'])
        for name, value in [('network.json', network), ('containers.jsonl', rows),
                            ('volumes.txt', sorted(row['name'] for row in volumes)), ('volumes.jsonl', volumes)]:
            path = root / name
            if path.exists() or path.is_symlink():
                base.expect(path.is_file() and not path.is_symlink(), 'H5_REGISTRATION_INPUT_REGULAR_PREVIOUS_FILE')
                sequence = 1
                while evidence(context, 'h5-previous-%04d-%s' % (sequence, name)).exists():
                    sequence += 1
                previous = evidence(context, 'h5-previous-%04d-%s' % (sequence, name))
                with previous.open('xb') as stream:
                    raw = path.read_bytes()
                    base.expect(stream.write(raw) == len(raw), 'H5_PREVIOUS_OBSERVATION_ARCHIVE_BYTES')
            raw = ((json.dumps(value) + '\n') if name == 'network.json' else
                   ''.join(json.dumps(row) + '\n' for row in value) if name.endswith('jsonl') else '\n'.join(value) + '\n').encode()
            path.write_bytes(raw)
            base.expect(path.read_bytes() == raw, 'H5_ACTUAL_REGISTRATION_METADATA_WRITE_READBACK')
    return facts_by_id


def created_h5(base, context, frozen, proof):
    spec = H5_PROOFS[proof]
    ids = compose_h5(base, context, frozen, ['ps', '-aq', spec['role']], proof + '-lookup', proof).splitlines()
    base.expect(len(ids) == 1 and re.fullmatch('[a-f0-9]{64}', ids[0]), 'ONE_ACTUAL_H5_ROLE_CID')
    facts = base.inspect(context, ids[0], proof + '-created-inspect')
    h5_owned(base, context, frozen, facts, spec['role'], proof)
    base.expect(facts['status'] == 'created' and facts['pid'] == 0, 'H5_NATIVE_CREATED_NEVER_STARTED')
    base.new(evidence(context, proof + '-created.json'), facts | {
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'proof_name': proof})
    return facts['id']


def bound_h5(base, context, frozen, proof):
    base.expect(proof in H5_PROOFS, 'KNOWN_H5_BOUND_NATIVE_PROOF')
    row = base.load(evidence(context, proof + '-created.json'))
    base.expect(row['run_id'] == context['run_id'] and row['source_sha'] == context['source_sha']
                and row['proof_name'] == proof and re.fullmatch('[a-f0-9]{64}', row['id'])
                and row['status'] == 'created' and row['pid'] == 0, 'ORIGINAL_BOUND_H5_CREATED_RECEIPT')
    h5_owned(base, context, frozen, row, H5_PROOFS[proof]['role'], proof)
    return row


def normal_h5(base, context, frozen, proof, live=True):
    before = bound_h5(base, context, frozen, proof)
    facts = base.inspect(context, before['id'], proof + '-terminal-required') if live else base.load(evidence(context, proof + '-terminal.json'))
    base.expect(set(facts) == set(before) - {'run_id', 'source_sha', 'proof_name'}, 'H5_ORIGINAL_RAW_NATIVE_TERMINAL_FIELD_SET')
    h5_owned(base, context, frozen, facts, H5_PROOFS[proof]['role'], proof)
    base.same_created(before, facts)
    base.expect(facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
                and type(facts['exit_code']) is int and facts['exit_code'] == 0, 'H5_ACTUAL_SAME_NORMAL_TERMINAL_ZERO')
    base.persist_terminal(context, proof, facts)
    return facts


def expected_h5(base, context, frozen, proof):
    expected = base_expected(base, context)
    proofs = {}
    group = [proof.removeprefix('register-'), proof] if proof.startswith('register-') else [proof]
    for name in group:
        cid = bound_h5(base, context, frozen, name)['id']
        base.expect(cid not in expected, 'H5_DISTINCT_ORIGINAL_NATIVE_HANDLES')
        expected[cid] = H5_PROOFS[name]['role']
        proofs[cid] = name
    return expected, proofs


def h5_paid_observation(base, context, proof, create=False):
    base.expect(proof in H5_PROOFS or proof in H5_FINAL_PROOFS, 'H5_CANONICAL_FIXED_ACTUAL_PHASE')
    fresh = base.current_unstarted_paid(context)
    filename = proof + '-paid-unstarted.json'
    target = evidence(context, filename)
    if create:
        base.new(target, fresh)
    original = base.load(target)
    base.expect(base.unstarted_paid_native_equal(original, fresh), 'H5_PHASE_ORIGINAL_AND_FRESH_NATIVE26_CANONICAL_EQUAL')
    return descriptor(base, target, '/run/audit/evidence/' + filename)


def h5_prerequisite_names(base, actual_phase):
    """The Main H5 MS5 reader's exact public prefix; no future phase files."""
    counts = {'copy': 16, 'register-prepare': 19, 'prepare': 19, 'register-files': 22,
              'files-bootstrap': 22, 'register-final': 25, 'serve': 28}
    base.expect(actual_phase in counts, 'H5_MS5_FINITE_ACTUAL_PHASE')
    common = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json',
        'initializer-result.json', 'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
        'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']
    files = ['files-bootstrap-created.json', 'files-bootstrap-terminal.json',
             'files-bootstrap-result.json', 'files-bootstrap-shutdown.json']
    prepared = ['customer-prepare-created.json', 'customer-prepare-terminal.json', 'ms4-customer-prepare.json']
    base.expect(base.PREREQUISITES == common and base.FILES_PREREQUISITES == files
        and base.PREPARE_PREREQUISITES == prepared
        and base.VOLUMES == ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets',
                            'mysql-data', 'redis-data', 'redis-secrets'], 'H5_REAL_CURRENT_MS5_FOUNDATION_CONTRACT')
    names = common + files + prepared + ['customer-h5-additional-volume-identities.json']
    if actual_phase != 'copy':
        names += ['customer-h5-public-copy-created.json', 'customer-h5-public-copy-terminal.json', 'customer-h5-public-copy.json']
    if actual_phase in ['register-files', 'files-bootstrap', 'register-final', 'serve']:
        names += ['customer-h5-prepare-created.json', 'customer-h5-prepare-terminal.json', 'customer-h5-prepare-result.json']
    if actual_phase in ['register-final', 'serve']:
        names += ['customer-h5-files-created.json', 'customer-h5-files-terminal.json', 'customer-h5-files-result.json']
    if actual_phase == 'serve':
        names += ['register-customer-h5-serve-created.json', 'register-customer-h5-serve-terminal.json',
                  'register-customer-h5-serve-result.json']
    base.expect(len(names) == counts[actual_phase] and len(set(names)) == len(names), 'H5_MS5_EXACT_ORDERED_PUBLIC_PREFIX')
    return names


def h5_prerequisite_hashes(base, context, frozen, actual_phase):
    """Full genuine predecessors, then exact complete bytes; not a caller success flag."""
    names = h5_prerequisite_names(base, actual_phase)
    prefix = base.prerequisite_hashes(context, 'verify')
    base.expect(list(prefix) == names[:15], 'H5_MS5_REAL_COMPLETE_C3_PREFIX')
    base.verify_fulfillment_migration(context, False)
    base.current_unstarted_paid(context)
    h5_volumes(base, context)
    if actual_phase != 'copy':
        public_copy_result(base, context, frozen)
    if actual_phase in ['register-files', 'files-bootstrap', 'register-final', 'serve']:
        extracted_public_result(base, context, frozen, 'customer-h5-prepare')
    if actual_phase in ['register-final', 'serve']:
        extracted_public_result(base, context, frozen, 'customer-h5-files')
    if actual_phase == 'serve':
        proof = 'register-customer-h5-serve'
        before = bound_final_h5(base, context, frozen, proof)
        terminal = base.load(evidence(context, proof + '-terminal.json'))
        final_h5_owned(base, context, frozen, terminal, H5_FINAL_PROOFS[proof]['role'], proof)
        base.same_created(before, terminal)
        result = base.load(evidence(context, proof + '-result.json'))
        manifest = base.load(Path(context['run_dir']) / 'manifest.json')
        base.expect(terminal['status'] == 'exited' and type(terminal['pid']) is int and terminal['pid'] == 0
            and type(terminal['exit_code']) is int and terminal['exit_code'] == 0
            and type(result) is dict and set(result) == {'schema', 'scope', 'status', 'phase', 'run_id', 'source_sha',
                'container_id', 'freeze_sha256', 'native_created_sha256', 'source_binding_sha256', 'proof_name',
                'full_source_unchanged', 'native_exit', 'business', 'HTTP_DOM'}
            and type(result['schema']) is int and result['schema'] == 1
            and result['scope'] == 'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            and result['status'] == 'REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY'
            and result['phase'] == 'register-final' and result['proof_name'] == proof
            and result['container_id'] == before['id']
            and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and result['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
            and result['source_binding_sha256'] == base.digest(evidence(context, proof + '-source-binding.json').read_bytes())
            and result['full_source_unchanged'] is True and type(result['native_exit']) is int and result['native_exit'] == 0
            and result['HTTP_DOM'] == 'NOT RUN' and result['business'] == {'registered_roles': sorted(manifest['services']),
                'input_inventory_excludes_transient_helper': True,
                'manifest_sha256': base.digest((Path(context['run_dir']) / 'manifest.json').read_bytes())},
            'H5_MS5_REAL_FINAL_REGISTER_NATIVE_RESULT_AND_CURRENT_MANIFEST')
    values = {}
    for name in names:
        path = evidence(context, name)
        base.expect(path.is_file() and not path.is_symlink(), 'H5_MS5_REAL_ORIGINAL_PUBLIC_FILE')
        values[name] = base.digest(path.read_bytes())
    base.expect({name: values[name] for name in names[:15]} == prefix, 'H5_MS5_PREDECESSOR_BYTES_STILL_IDENTICAL')
    return values


def source_binding_h5(base, context, frozen, proof, registration_inputs=None):
    created = bound_h5(base, context, frozen, proof)
    canonical_paid = h5_paid_observation(base, context, proof)
    environment, native = base.environment_observation(context, created['id'])
    spec = H5_PROOFS[proof]
    if proof == 'customer-h5-prepare':
        binding = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_PREPARE_MS5_SOURCE',
            'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': created['id'],
            'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
            'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
            'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
            'entry_sha256': next(row['sha256'] for row in frozen['h5_source_files'] if row['path'] == H5_ENTRY),
            'source_files': frozen['customer_source_files'], 'h5_source_files': frozen['h5_source_files'],
            'h5_materials': frozen['h5_materials'], 'public_copy': descriptor(base,
                evidence(context, 'customer-h5-public-copy.json'), '/run/audit/evidence/customer-h5-public-copy.json'),
            'prerequisites_sha256': h5_prerequisite_hashes(base, context, frozen, 'prepare'), 'canonical_paid': canonical_paid}
        env = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT',
            'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': created['id'],
            'freeze_sha256': context['freeze_sha256'], 'environment': environment,
            'native_projection_sha256': native['stdout_sha256']}
        return binding, native, env
    public_copy = prepare_result = None
    if proof == 'customer-h5-files':
        public_copy = descriptor(base, evidence(context, 'customer-h5-public-copy.json'), '/run/audit/evidence/customer-h5-public-copy.json')
        prepare_result = descriptor(base, evidence(context, 'customer-h5-prepare-result.json'), '/run/audit-http-control/prepare-result.json')
    binding = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_BOOTSTRAP_MS5_SOURCE', 'phase': spec['phase'], 'proof_name': proof,
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': created['id'],
        'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': next(row['sha256'] for row in frozen['h5_artifact_files']
                            if row['path'] == Path(spec['command'][0]).name),
        'source_files': frozen['customer_source_files'], 'h5_source_files': frozen['h5_source_files'],
        'artifact_files': frozen['artifact_files'], 'h5_artifact_files': frozen['h5_artifact_files'],
        'environment': environment, 'public_copy': public_copy, 'prepare_result': prepare_result,
        'registration_inputs': registration_inputs,
        'prerequisites_sha256': h5_prerequisite_hashes(base, context, frozen, {
            'customer-h5-public-copy': 'copy', 'customer-h5-files': 'files-bootstrap',
            'register-customer-h5-prepare': 'register-prepare', 'register-customer-h5-files': 'register-files'}[proof]),
        'canonical_paid': canonical_paid}
    return binding, native, None


def binding_name(proof):
    return 'ms4-customer-h5-prepare-source-binding.json' if proof == 'customer-h5-prepare' else proof + '-source-binding.json'


def record_binding_h5(base, context, frozen, proof, registration_inputs=None):
    h5_paid_observation(base, context, proof, True)
    binding, native, env = source_binding_h5(base, context, frozen, proof, registration_inputs)
    base.new(evidence(context, proof + '-environment-native.json'), native)
    if env is not None:
        base.new(evidence(context, proof + '-environment.json'), env)
    base.new(evidence(context, binding_name(proof)), binding)


def verify_binding_h5(base, context, frozen, proof):
    old = base.load(evidence(context, binding_name(proof)))
    inputs = old.get('registration_inputs')
    if proof.startswith('register-'):
        wanted_paths = {'containers': '/run/audit/containers.jsonl', 'volumes': '/run/audit/volumes.txt',
                        'volume_identities': '/run/audit/volumes.jsonl'}
        base.expect(type(inputs) is dict and set(inputs) == set(wanted_paths), 'H5_EXACT_PRECOLLECTED_REGISTRATION_INPUTS')
        for key, path in wanted_paths.items():
            host_path = Path(context['run_dir']) / Path(path).name
            base.expect(inputs[key] == descriptor(base, host_path, path), 'H5_SAME_PRECOLLECTED_BYTES_EXCLUDING_REGISTER_HELPER')
    binding, native, env = source_binding_h5(base, context, frozen, proof, inputs)
    base.expect(old == binding and type(old['schema']) is int
                and base.load(evidence(context, proof + '-environment-native.json')) == native, 'H5_EXACT_SOURCE_AND_ACTUAL_NATIVE_ENV_BINDING')
    if env is not None:
        base.expect(base.load(evidence(context, proof + '-environment.json')) == env, 'H5_EXACT_EIGHT_FIELD_DML_ENVIRONMENT')
    return binding


def removed_h5(base, context, frozen, proof):
    created = bound_h5(base, context, frozen, proof)
    normal_h5(base, context, frozen, proof, False)
    value = base.load(evidence(context, proof + '-removed.json'))
    public_name = proof + '.json' if proof == 'customer-h5-public-copy' else proof + '-result.json'
    base.expect(value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
                and value['freeze_sha256'] == context['freeze_sha256'] and value['container_id'] == created['id']
                and value['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
                and value['native_terminal_sha256'] == base.digest(evidence(context, proof + '-terminal.json').read_bytes())
                and value['source_binding_sha256'] == base.digest(evidence(context, binding_name(proof)).read_bytes())
                and value['public_result_name'] == public_name
                and value['public_result_sha256'] == base.digest(evidence(context, public_name).read_bytes())
                and value['additional_volumes_sha256'] == base.digest(evidence(context, 'customer-h5-additional-volume-identities.json').read_bytes()),
                'H5_SAME_ORIGINAL_TERMINAL_ARCHIVED_AND_REMOVED_RECEIPT')
    return value


def remove_h5(base, context, frozen, proof):
    expected, proofs = expected_h5(base, context, frozen, proof)
    collect_h5(base, context, frozen, expected, proofs)
    facts = normal_h5(base, context, frozen, proof)
    cid = facts['id']
    base.native(context, ['rm', cid], proof + '-exact-normal-remove')
    remaining = {key: role for key, role in expected.items() if key != cid}
    collect_h5(base, context, frozen, remaining, {key: value for key, value in proofs.items() if key != cid})
    result = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_ORIGINAL_TERMINAL_REMOVAL_ONLY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'container_id': cid, 'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'native_terminal_sha256': base.digest(evidence(context, proof + '-terminal.json').read_bytes()),
        'source_binding_sha256': base.digest(evidence(context, binding_name(proof)).read_bytes()),
        'public_result_name': proof + '.json' if proof == 'customer-h5-public-copy' else proof + '-result.json',
        'public_result_sha256': base.digest(evidence(context, proof + '.json' if proof == 'customer-h5-public-copy' else proof + '-result.json').read_bytes()),
        'additional_volumes_sha256': base.digest(evidence(context, 'customer-h5-additional-volume-identities.json').read_bytes()),
        'remaining_container_ids': sorted(remaining)}
    base.new(evidence(context, proof + '-removed.json'), result)
    return result


def public_copy_result(base, context, frozen):
    proof = 'customer-h5-public-copy'
    before = bound_h5(base, context, frozen, proof)
    normal_h5(base, context, frozen, proof, False)
    result = base.load(evidence(context, proof + '.json'))
    keys = {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'source_identity', 'read_only_source',
            'source_count', 'h5_count', 'public_count', 'files', 'directories', 'inputs_unchanged', 'closed_set_verified',
            'marker_sha256', 'png_sha256', 'permissions'}
    base.expect(type(result) is dict and set(result) == keys and type(result['schema']) is int and result['schema'] == 1
                and result['scope'] == 'MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY'
                and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
                and result['source_identity'] == {'proof_name': proof, 'role': H5_PROOFS[proof]['role'],
                    'command': H5_PROOFS[proof]['command'], 'container_id': before['id'],
                    'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes())}
                and all(result[key] is True for key in ['read_only_source', 'inputs_unchanged', 'closed_set_verified']),
                'H5_REAL_SOURCE_BOUND_PUBLIC_COPY_RECEIPT')
    source = base.load(PREP / 'manifests/public-source.json')
    dist = base.load(PREP / 'manifests/h5-dist.json')
    files = {row['path']: row for row in source['files']}
    base.expect(not any(path == 'install.lock' or path.startswith(('__ms4_assets', '__ms4_fixture__'))
                        for path in [*files, *source['directories']]), 'H5_PUBLIC_SOURCE_RESERVED_NAMES_ABSENT')
    for row in dist['files']:
        path = '__ms4_assets/h5/' + row['path']
        base.expect(path not in files, 'H5_PUBLIC_DIST_NO_FILE_COLLISION')
        files[path] = row | {'path': path}
    marker = ('# AUDIT-RUN ' + context['run_id'] + '\n').encode()
    png = frozen['h5_materials']['fixture_png']
    files['install.lock'] = {'path': 'install.lock', 'bytes': len(marker), 'sha256': base.digest(marker)}
    files['__ms4_fixture__/product.png'] = {'path': '__ms4_fixture__/product.png', 'bytes': png['bytes'], 'sha256': png['sha256']}
    directories = set(source['directories']) | {'__ms4_assets/h5/' + path for path in dist['directories']}
    for path in files:
        parent = Path(path).parent
        while parent.as_posix() != '.':
            directories.add(parent.as_posix())
            parent = parent.parent
    base.expect(all(type(result[name]) is int and result[name] == count for name, count in [
        ('source_count', len(source['files'])), ('h5_count', len(dist['files'])), ('public_count', len(files))])
        and result['files'] == [files[path] for path in sorted(files)] and result['directories'] == sorted(directories)
        and result['marker_sha256'] == base.digest(marker) and result['png_sha256'] == png['sha256'],
        'H5_COMPLETE_PUBLIC_COPY_FILES_EMPTY_DIRECTORIES_AND_BYTES')
    permissions = result['permissions']
    base.expect(set(permissions) == {'publisher_uid', 'directory_mode', 'file_mode', 'verified'}
                and type(permissions['publisher_uid']) is int and permissions['publisher_uid'] >= 0
                and permissions['directory_mode'] == '0755' and permissions['file_mode'] == '0644'
                and permissions['verified'] is True, 'H5_ACTUAL_COPIER_PERMISSION_RECEIPT')
    return result


def bootstrap_public_result(base, context, frozen, proof, result):
    before = bound_h5(base, context, frozen, proof)
    keys = {'schema', 'scope', 'status', 'phase', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
            'native_created_sha256', 'source_binding_sha256', 'proof_name', 'full_source_unchanged', 'native_exit', 'business', 'HTTP_DOM'}
    wanted_status = 'REGISTERED_CUSTOMER_H5_WITH_MS5_TARGET_RESOURCES_ONLY' if proof.startswith('register-') else 'VERIFIED_CUSTOMER_H5_WITH_MS5_FILES_BOOTSTRAP_ONLY'
    base.expect(type(result) is dict and set(result) == keys and type(result['schema']) is int and result['schema'] == 1
                and result['scope'] == 'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY' and result['status'] == wanted_status
                and result['phase'] == H5_PROOFS[proof]['phase'] and result['proof_name'] == proof
                and result['container_id'] == before['id'] and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
                and result['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
                and result['source_binding_sha256'] == base.digest(evidence(context, binding_name(proof)).read_bytes())
                and result['full_source_unchanged'] is True and type(result['native_exit']) is int and result['native_exit'] == 0
                and result['HTTP_DOM'] == 'NOT RUN', 'H5_EXACT_REAL_BOOTSTRAP_RESULT_SOURCE_IDENTITY')
    business = result['business']
    if proof.startswith('register-'):
        target = proof.removeprefix('register-')
        role = H5_PROOFS[target]['role']
        base.expect(set(business) == {'target_proof', 'target_role', 'target_container_id', 'registered_roles', 'input_inventory_excludes_transient_helper'}
                    and business['target_proof'] == target and business['target_role'] == role
                    and business['target_container_id'] == bound_h5(base, context, frozen, target)['id']
                    and business['registered_roles'] == sorted([role, base.PAID['role'], 'audit-mysql', 'audit-redis'])
                    and business['input_inventory_excludes_transient_helper'] is True, 'H5_ACTUAL_REGISTER_TARGET_ONLY_RESULT')
        return
    base.expect(set(business) == {'installation', 'business_tables', 'auxiliary_tables', 'complete_native_rows_unchanged',
        'sealed_baseline_and_base_fixture_unchanged', 'DML_prepare_actual_terminal_zero_verified', 'public_materials_unchanged',
        'HTTP_executed', 'credential_login_authenticated', 'order_creation_executed', 'HTTP_DOM'}
        and type(business['business_tables']) is int and business['business_tables'] == 27
        and type(business['auxiliary_tables']) is int and business['auxiliary_tables'] == 7
        and all(business[key] is True for key in ['complete_native_rows_unchanged', 'sealed_baseline_and_base_fixture_unchanged',
                                                 'DML_prepare_actual_terminal_zero_verified', 'public_materials_unchanged'])
        and all(business[key] is False for key in ['HTTP_executed', 'credential_login_authenticated', 'order_creation_executed'])
        and business['HTTP_DOM'] == 'NOT RUN', 'H5_COMPLETE_FILES_PREPARATION_ONLY_RESULT')
    installation = business['installation']
    base.expect(set(installation) == {'account', 'checksum_rows', 'source_matches', 'version_marker_matches',
        'customer_cache_prefix', 'customer_cache_tag_prefix', 'application_account', 'application_dml_only', 'one_independent_migrator_App'}
        and installation['account'] == 'audit_migrator' and installation['application_account'] == 'audit_app'
        and type(installation['checksum_rows']) is int and installation['checksum_rows'] > 0
        and installation['customer_cache_prefix'] == 'c:' and installation['customer_cache_tag_prefix'] == 'CRMEB:'
        and all(installation[key] is True for key in ['source_matches', 'version_marker_matches', 'application_dml_only', 'one_independent_migrator_App']),
        'H5_REAL_FINAL_MIGRATOR_NO_PRIVATE_VALUES')


def dml_public_result(base, context, frozen, result):
    proof = 'customer-h5-prepare'
    before = bound_h5(base, context, frozen, proof)
    keys = {'schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'native_created_sha256',
            'source_binding_sha256', 'environment_sha256', 'public_copy_sha256', 'public_source_sha256', 'phase', 'native_exit',
            'full_source_unchanged', 'public_materials_unchanged', 'business', 'HTTP_executed', 'credential_login_authenticated',
            'order_creation_executed', 'HTTP_DOM', 'assertions', 'assertions_by_phase'}
    base.expect(type(result) is dict and set(result) == keys and type(result['schema']) is int and result['schema'] == 1
        and result['scope'] == 'NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY' and result['status'] == 'VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        and result['phase'] == 'final_source' and type(result['native_exit']) is int and result['native_exit'] == 0
        and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and result['container_id'] == before['id'] and result['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
        and result['source_binding_sha256'] == base.digest(evidence(context, binding_name(proof)).read_bytes())
        and result['environment_sha256'] == base.digest(evidence(context, proof + '-environment.json').read_bytes())
        and result['public_copy_sha256'] == base.digest(evidence(context, 'customer-h5-public-copy.json').read_bytes())
        and result['public_source_sha256'] == frozen['h5_materials']['public_source']['sha256']
        and result['full_source_unchanged'] is True and result['public_materials_unchanged'] is True
        and all(result[key] is False for key in ['HTTP_executed', 'credential_login_authenticated', 'order_creation_executed'])
        and result['HTTP_DOM'] == 'NOT RUN' and type(result['assertions']) is int and result['assertions'] > 0,
        'H5_EXACT_REAL_DML_PREPARATION_RESULT_IDENTITY')
    phases = result['assertions_by_phase']
    base.expect(type(phases) is dict and phases and all(type(key) is str and re.fullmatch('[a-z][a-z0-9_]{0,63}', key)
                and type(value) is int and value > 0 for key, value in phases.items())
                and sum(phases.values()) == result['assertions'], 'H5_PUBLIC_ASSERTION_COUNTS_ONLY')
    business = result['business']
    base.expect(set(business) == {'fixture_counts', 'configuration_keys', 'language_counts', 'theme_count', 'business_table_count',
        'auxiliary_table_count', 'sealed_files', 'base_fixture_preserved', 'native_rows_and_relationships_verified',
        'production_configuration_published_and_read_back', 'theme_service_saved_enabled_and_read_back',
        'language_native_ids_and_full_package_verified', 'event_preconditions', 'HTTP_executed', 'order_creation_executed',
        'credential_login_authenticated', 'HTTP_DOM'}
        and business['fixture_counts'] == {'customers': 1, 'merchants': 2, 'products': 3, 'skus': 4, 'cart_rows': 0, 'orders': 0}
        and business['configuration_keys'] == ['site_name', 'site_url', 'wap_login_logo', 'nncnL_crmeb_copyright',
            'nncnL_crmeb_copyright_image', 'statistic_script', 'routine_auth_type', 'balance_func_status', 'recharge_switch',
            'ali_pay_status', 'pay_weixin_open', 'yue_pay_status', 'friend_pay_status', 'brokerage_func_status',
            'brokerage_window_switch', 'brokerage_bindind']
        and business['language_counts'] == {'types': 1, 'countries': 1, 'user_codes': 1179}
        and type(business['theme_count']) is int and business['theme_count'] == 1
        and type(business['business_table_count']) is int and business['business_table_count'] == 27
        and type(business['auxiliary_table_count']) is int and business['auxiliary_table_count'] == 7
        and all(business[key] is True for key in ['base_fixture_preserved', 'native_rows_and_relationships_verified',
            'production_configuration_published_and_read_back', 'theme_service_saved_enabled_and_read_back', 'language_native_ids_and_full_package_verified'])
        and all(business[key] is False for key in ['HTTP_executed', 'order_creation_executed', 'credential_login_authenticated'])
        and business['HTTP_DOM'] == 'NOT RUN', 'H5_FINITE_DML_COUNTS_AND_NO_CLIENT_OR_PRIVATE_ROWS')
    base.expect(all(type(value) is int for row in [business['fixture_counts'], business['language_counts']]
                    for value in row.values()), 'H5_REAL_INTEGER_PREPARATION_COUNTS')
    sealed = business['sealed_files']
    base.expect(type(sealed) is dict and set(sealed) == {'sql-baseline', 'customer-oracle', 'customer-actors'}, 'H5_EXACT_THREE_PRIVATE_FILE_PUBLIC_SUMMARIES')
    for name, row in sealed.items():
        base.expect(set(row) == {'sha256_prefix', 'bytes'} and type(row['sha256_prefix']) is str
                    and re.fullmatch('[a-f0-9]{12}', row['sha256_prefix']) and type(row['bytes']) is int
                    and 0 < row['bytes'] <= (16384 if name == 'customer-actors' else 2097152), 'H5_ONLY_TWELVE_PREFIX_AND_PRIVATE_BYTE_COUNT')
    events = business['event_preconditions']
    base.expect(set(events) == {'custom_event_count', 'external_channels_enabled', 'queue_open', 'login_notice_rows', 'product_visit_custom_event_count'}
                and all(type(events[key]) is int and events[key] == 0 for key in ['custom_event_count', 'external_channels_enabled',
                    'queue_open', 'product_visit_custom_event_count']) and type(events['login_notice_rows']) is int and events['login_notice_rows'] >= 0,
                'H5_REAL_FINITE_EVENT_PRECONDITIONS')


def extract_public_result(base, context, frozen, proof):
    base.expect(proof in ['customer-h5-prepare', 'customer-h5-files'], 'ONLY_TWO_FIXED_NONSECRET_RESULT_FILES')
    facts = normal_h5(base, context, frozen, proof)
    filename = 'prepare-result.json' if proof == 'customer-h5-prepare' else 'files-result.json'
    source = '/run/audit-http-control/' + filename
    process = subprocess.run(['docker', 'cp', facts['id'] + ':' + source, '-'], capture_output=True)
    base.expect(process.returncode == 0 and process.stderr == b'' and 0 < len(process.stdout) <= 4194304,
                'H5_ACTUAL_SAME_CID_PUBLIC_FILE_TAR_NATIVE_ZERO')
    with tarfile.open(fileobj=io.BytesIO(process.stdout), mode='r:') as archive:
        members = archive.getmembers()
        base.expect(len(members) == 1, 'H5_ONLY_ONE_FIXED_PUBLIC_TAR_MEMBER')
        member = members[0]
        base.expect(member.isfile() and member.name == filename and member.uid == 0 and member.mode & 0o777 == 0o600
                    and 0 < member.size <= 2097152, 'H5_PUBLIC_RESULT_ACTUAL_REGULAR_MEMBER_OWNER_MODE_SIZE')
        stream = archive.extractfile(member)
        base.expect(stream is not None, 'H5_PUBLIC_RESULT_TAR_STREAM')
        raw = stream.read(2097153)
        base.expect(len(raw) == member.size, 'H5_FULL_FIXED_PUBLIC_FILE_BYTES')
    result = json.loads(raw.decode('utf-8'))
    if proof == 'customer-h5-prepare':
        dml_public_result(base, context, frozen, result)
    else:
        bootstrap_public_result(base, context, frozen, proof, result)
    after = normal_h5(base, context, frozen, proof)
    base.same_created(facts, after)
    output = evidence(context, proof + '-result.json')
    base.expect(not output.exists() and not output.is_symlink(), 'H5_CREATE_ONLY_EXTRACTED_PUBLIC_RESULT')
    with output.open('xb') as handle:
        base.expect(handle.write(raw) == len(raw), 'H5_PUBLIC_RESULT_EXACT_RAW_WRITE')
        handle.flush()
    base.expect(output.read_bytes() == raw, 'H5_PUBLIC_RESULT_EXACT_RAW_READBACK')
    copy = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_FIXED_PUBLIC_RESULT_EXTRACTION_ONLY',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'container_id': facts['id'], 'container_path': source, 'output_name': output.name,
        'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'native_terminal_sha256': base.digest(evidence(context, proof + '-terminal.json').read_bytes()),
        'native_exit': process.returncode, 'stderr_bytes': len(process.stderr), 'stderr_sha256': base.digest(process.stderr),
        'tar_bytes': len(process.stdout), 'tar_sha256': base.digest(process.stdout), 'bytes': len(raw), 'sha256': base.digest(raw)}
    base.new(evidence(context, proof + '-result-copy.json'), copy)
    return result


def extracted_public_result(base, context, frozen, proof):
    before = bound_h5(base, context, frozen, proof)
    normal_h5(base, context, frozen, proof, False)
    row = base.load(evidence(context, proof + '-result-copy.json'))
    filename = 'prepare-result.json' if proof == 'customer-h5-prepare' else 'files-result.json'
    path = evidence(context, proof + '-result.json')
    raw = path.read_bytes()
    base.expect(row['run_id'] == context['run_id'] and row['source_sha'] == context['source_sha']
                and row['freeze_sha256'] == context['freeze_sha256'] and row['container_id'] == before['id']
                and row['container_path'] == '/run/audit-http-control/' + filename and row['output_name'] == path.name
                and type(row['native_exit']) is int and row['native_exit'] == 0 and row['stderr_bytes'] == 0
                and row['stderr_sha256'] == base.digest(b'') and row['bytes'] == len(raw) and row['sha256'] == base.digest(raw)
                and row['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
                and row['native_terminal_sha256'] == base.digest(evidence(context, proof + '-terminal.json').read_bytes()),
                'H5_ONLY_ACTUAL_SAME_CID_EXTRACTED_PUBLIC_RESULT_BYTES')
    result = base.load(path)
    if proof == 'customer-h5-prepare':
        dml_public_result(base, context, frozen, result)
    else:
        bootstrap_public_result(base, context, frozen, proof, result)
    return result


def verify_registration_h5(base, context, frozen, target, live_helper=False):
    proof = 'register-' + target
    normal_h5(base, context, frozen, proof, live_helper)
    result = base.load(evidence(context, proof + '-result.json'))
    bootstrap_public_result(base, context, frozen, proof, result)
    target_id = bound_h5(base, context, frozen, target)['id']
    expected = base_expected(base, context) | {target_id: H5_PROOFS[target]['role']}
    if live_helper:
        helper_id = bound_h5(base, context, frozen, proof)['id']
        actual = collect_h5(base, context, frozen, expected | {helper_id: H5_PROOFS[proof]['role']},
                            {target_id: target, helper_id: proof})
    else:
        removed_h5(base, context, frozen, proof)
        actual = collect_h5(base, context, frozen, expected, {target_id: target})
    manifest = base.load(Path(context['run_dir']) / 'manifest.json')
    wanted_services = {role: {'id': cid, 'address': actual[cid]['networks'][context['project'] + '_isolation']['IPAddress'],
                              'image_id': actual[cid]['image']} for cid, role in expected.items()}
    volumes = h5_volumes(base, context)
    base.expect(manifest['run_id'] == context['run_id'] and manifest['commit_sha'] == context['source_sha']
                and manifest['project'] == context['project'] and manifest['network_internal'] is True
                and manifest['guard_mode'] == 'read-only' and manifest['services'] == wanted_services
                and manifest['network'] == {'id': context['network_id'], 'name': context['project'] + '_isolation'}
                and manifest['volumes'] == sorted(row['name'] for row in volumes)
                and manifest['volume_identities'] == {row['name']: row['created_at'] for row in volumes},
                'H5_REAL_TARGET_THREE_ROLE_MANIFEST_AND_ALL_TEN_VOLUMES')
    return result


def create_h5_phase(base, context, frozen, proof):
    base.expect(proof in ['customer-h5-public-copy', 'customer-h5-prepare', 'customer-h5-files']
                and not evidence(context, proof + '-created.json').exists(), 'H5_NEW_PHASE_CREATE_ONLY')
    original = base_expected(base, context)
    if proof == 'customer-h5-public-copy':
        base.expect(not evidence(context, 'customer-h5-additional-volume-identities.json').exists()
                    and not evidence(context, 'customer-created.json').exists(), 'H5_AFTER_ORIGINAL_PREPARE_BEFORE_ANY_HTTP_ORDER')
        base.verify_mode_result(context, 'prepare')
        old = base.bound_created(context, 'customer-prepare', base.MODES['prepare']['role'])
        # Preserve all current seven identities and the never-started canonical owner before the four new volumes.
        base.remove_finished(context, old['id'], base.MODES['prepare']['role'], 'customer-prepare',
                             original | {old['id']: base.MODES['prepare']['role']})
        base.collect(context, original, publish=False)
    else:
        collect_h5(base, context, frozen, original, {})
        removed_h5(base, context, frozen, 'customer-h5-public-copy')
        public_copy_result(base, context, frozen)
        if proof == 'customer-h5-files':
            removed_h5(base, context, frozen, 'customer-h5-prepare')
            extracted_public_result(base, context, frozen, 'customer-h5-prepare')
    compose_h5(base, context, frozen, ['create', '--no-recreate', '--no-build', '--pull', 'never', H5_PROOFS[proof]['role']],
                proof + '-create', proof)
    cid = created_h5(base, context, frozen, proof)
    if proof == 'customer-h5-public-copy':
        h5_volumes(base, context, True)
    collect_h5(base, context, frozen, original | {cid: H5_PROOFS[proof]['role']}, {cid: proof})
    if proof == 'customer-h5-public-copy':
        record_binding_h5(base, context, frozen, proof)
    return {'status': 'CREATED_SAME_UNSTARTED_H5_PHASE_ONLY', 'proof': proof, 'container_id': cid,
            'source_binding_ready': proof == 'customer-h5-public-copy', 'serve_browser_acceptance': 'NOT RUN'}


def create_registration_h5(base, context, frozen, target):
    base.expect(target in ['customer-h5-prepare', 'customer-h5-files'], 'ONLY_TWO_H5_REGISTRATION_TARGETS')
    proof = 'register-' + target
    base.expect(not evidence(context, proof + '-created.json').exists(), 'H5_REGISTER_HELPER_CREATE_ONLY')
    target_created = bound_h5(base, context, frozen, target)
    cid = target_created['id']
    target_expected = base_expected(base, context) | {cid: H5_PROOFS[target]['role']}
    actual = collect_h5(base, context, frozen, target_expected, {cid: target}, publish=True)
    base.expect(actual[cid]['status'] == 'created' and actual[cid]['pid'] == 0, 'H5_REGISTER_ONLY_UNSTARTED_TARGET')
    root = Path(context['run_dir'])
    inputs = {key: descriptor(base, root / name, '/run/audit/' + name) for key, name in [
        ('containers', 'containers.jsonl'), ('volumes', 'volumes.txt'), ('volume_identities', 'volumes.jsonl')]}
    # The target+DB snapshot is complete before the transient helper is created.
    compose_h5(base, context, frozen, ['create', '--no-recreate', '--no-build', '--pull', 'never', H5_PROOFS[proof]['role']], proof + '-create', proof)
    helper_id = created_h5(base, context, frozen, proof)
    collect_h5(base, context, frozen, target_expected | {helper_id: H5_PROOFS[proof]['role']}, {cid: target, helper_id: proof})
    record_binding_h5(base, context, frozen, proof, inputs)
    return {'status': 'CREATED_UNSTARTED_METADATA_REGISTRATION_HELPER_ONLY', 'proof': proof,
            'container_id': helper_id, 'target_container_id': cid, 'input_inventory_excludes_transient_helper': True}


def bind_target_h5(base, context, frozen, proof):
    base.expect(proof in ['customer-h5-prepare', 'customer-h5-files'], 'H5_ONLY_DML_OR_FILES_BIND')
    verify_registration_h5(base, context, frozen, proof)
    removed_h5(base, context, frozen, 'customer-h5-public-copy')
    public_copy_result(base, context, frozen)
    if proof == 'customer-h5-files':
        removed_h5(base, context, frozen, 'customer-h5-prepare')
        extracted_public_result(base, context, frozen, 'customer-h5-prepare')
    before = bound_h5(base, context, frozen, proof)
    actual = base.inspect(context, before['id'], proof + '-bind-unstarted')
    base.same_created(before, actual)
    base.expect(actual['status'] == 'created' and actual['pid'] == 0, 'H5_BIND_SAME_ORIGINAL_UNSTARTED_TARGET')
    record_binding_h5(base, context, frozen, proof)
    return {'status': 'BOUND_ORIGINAL_UNSTARTED_H5_SOURCE_AND_PUBLIC_ENV_ONLY', 'proof': proof,
            'container_id': before['id'], 'serve_browser_acceptance': 'NOT RUN'}


def start_h5_phase(base, context, frozen, proof):
    base.expect(proof in H5_PROOFS, 'H5_FINITE_START_PROOF')
    expected, proofs = expected_h5(base, context, frozen, proof)
    actual = collect_h5(base, context, frozen, expected, proofs)
    before = bound_h5(base, context, frozen, proof)
    base.expect(actual[before['id']]['status'] == 'created' and actual[before['id']]['pid'] == 0,
                'H5_START_ONLY_ORIGINAL_CREATED_HANDLE')
    if proof in ['customer-h5-prepare', 'customer-h5-files']:
        verify_registration_h5(base, context, frozen, proof)
        removed_h5(base, context, frozen, 'customer-h5-public-copy')
        public_copy_result(base, context, frozen)
        if proof == 'customer-h5-files':
            removed_h5(base, context, frozen, 'customer-h5-prepare')
            extracted_public_result(base, context, frozen, 'customer-h5-prepare')
    if proof.startswith('register-'):
        target = proof.removeprefix('register-')
        target_id = bound_h5(base, context, frozen, target)['id']
        base.expect(actual[target_id]['status'] == 'created' and actual[target_id]['pid'] == 0, 'H5_REGISTER_TARGET_STILL_UNSTARTED')
    verify_binding_h5(base, context, frozen, proof)
    # The create-only request persists before start. An ambiguous start never permits another start attempt.
    base.new(evidence(context, proof + '-start-request.json'), {'schema': 1,
        'scope': 'MS4_CUSTOMER_H5_SINGLE_NATIVE_START_ATTEMPT_ONLY', 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'container_id': before['id'],
        'proof_name': proof, 'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'source_binding_sha256': base.digest(evidence(context, binding_name(proof)).read_bytes())})
    base.native(context, ['start', before['id']], proof + '-single-start')
    return {'status': 'START_COMMAND_COMPLETED_ORIGINAL_H5_CID_ONLY', 'proof': proof, 'container_id': before['id'],
            'completion': 'OBSERVE_ORIGINAL_HANDLE_REQUIRED', 'serve_browser_acceptance': 'NOT RUN'}


def observe_h5_phase(base, context, frozen, proof, seconds):
    base.expect(proof in H5_PROOFS and type(seconds) is int and 0 <= seconds <= 30, 'H5_BOUNDED_ORIGINAL_HANDLE_OBSERVATION')
    expected, proofs = expected_h5(base, context, frozen, proof)
    collect_h5(base, context, frozen, expected, proofs)
    before = bound_h5(base, context, frozen, proof)
    # Same original implementation: raw exit code and original facts, no restart or substitute CID on timeout.
    result = base.observe(context, before['id'], proof, seconds)
    if result.get('status') == 'exited':
        h5_owned(base, context, frozen, result, H5_PROOFS[proof]['role'], proof)
        base.same_created(before, result)
    return result


def finish_h5_phase(base, context, frozen, proof):
    base.expect(proof in H5_PROOFS, 'H5_FINITE_FINISH_PROOF')
    expected, proofs = expected_h5(base, context, frozen, proof)
    collect_h5(base, context, frozen, expected, proofs)
    facts = normal_h5(base, context, frozen, proof)
    verify_binding_h5(base, context, frozen, proof)
    if proof == 'customer-h5-public-copy':
        public_copy_result(base, context, frozen)
    elif proof.startswith('register-'):
        verify_registration_h5(base, context, frozen, proof.removeprefix('register-'), True)
    else:
        if evidence(context, proof + '-result-copy.json').exists():
            extracted_public_result(base, context, frozen, proof)
        else:
            extract_public_result(base, context, frozen, proof)
    removal = remove_h5(base, context, frozen, proof)
    return {'status': 'REAL_H5_TERMINAL_ARCHIVED_AND_SAME_CID_REMOVED_ONLY', 'proof': proof,
            'container_id': facts['id'], 'native_terminal_sha256': removal['native_terminal_sha256'],
            'public_result_sha256': removal['public_result_sha256'], 'serve_browser_acceptance': 'NOT RUN'}


def final_h5_source(base, materials, context, frozen):
    # All R3 stages use the same complete source closure; R1/R2 files and freezes remain separate.
    base.expect(H5_FINAL_ARTIFACTS == H5_ARTIFACTS and len(H5_ARTIFACTS) == 20
                and context['prep_dir'] == PREP.as_posix(), 'H5_FINAL_COMPLETE_SUCCESSOR_PROFILE_REQUIRED')
    verify_h5_freeze(base, materials, frozen, H5_FINAL_ARTIFACTS)


def final_h5_port(base, frozen):
    match = re.fullmatch(r'http://127\.0\.0\.1:([1-9][0-9]{0,4})', frozen['h5_materials']['runtime_origin'])
    base.expect(match is not None and 1024 <= int(match[1]) <= 65535, 'H5_FINAL_FROZEN_LOOPBACK_PORT')
    return match[1]


def compose_final_h5(base, context, frozen, args, label, proof):
    base.expect(proof in H5_FINAL_PROOFS, 'H5_FINAL_EXACT_COMPOSE_PROOF')
    env = os.environ.copy()
    env.update({'AUDIT_RUN_ID': context['run_id'], 'AUDIT_PROJECT': context['project'],
        'AUDIT_SOURCE_SHA': context['source_sha'], 'AUDIT_REPO_DIR': context['repo_dir'],
        'AUDIT_RUN_DIR': context['run_dir'], 'MS4_PREP_DIR': context['prep_dir'], 'MS4_PROOF_NAME': proof,
        'MS4_BOOTSTRAP_PRIVATE_MODE': 'ro', 'MS4_H5_REGISTER_MODE': 'register-final',
        'MS4_CUSTOMER_SELF_MER_ID': str(base.actual_self(context)), 'MS4_H5_DIST': frozen['h5_dist_source'],
        'MS4_H5_PORT': final_h5_port(base, frozen)})
    process = subprocess.run(['docker', 'compose', '--project-name', context['project'], '--file',
        str(PREP / 'compose.yml'), '--file', str(PREP / 'compose.h5.yml'), *args], capture_output=True, env=env)
    base.new(evidence(context, label + '-compose-native.json'), {'stage': label, 'native_exit': process.returncode,
        'stdout_bytes': len(process.stdout), 'stdout_sha256': base.digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': base.digest(process.stderr)})
    base.expect(process.returncode == 0, 'H5_FINAL_COMPOSE_NATIVE_ZERO')
    return process.stdout.decode('utf-8').strip()


def final_h5_network_identity(base, value):
    base.expect(type(value) is dict and set(value) == {'Id', 'Name', 'Driver', 'Scope', 'Internal',
        'EnableIPv6', 'Options', 'Labels', 'Containers'} and re.fullmatch('[a-f0-9]{64}', value['Id'])
        and value['Driver'] == 'bridge' and value['Scope'] == 'local' and value['EnableIPv6'] is False
        and type(value['Containers']) is dict, 'H5_FINAL_COMPLETE_NATIVE_NETWORK')
    return {key: item for key, item in value.items() if key != 'Containers'}


def final_h5_networks(base, context, allowed):
    identities = {}
    for kind in ['backend', 'frontend']:
        row = base.load(evidence(context, 'http-' + kind + '-network-created.json'))
        base.expect(set(row) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'network'}
            and type(row['schema']) is int and row['schema'] == 1
            and row['scope'] == 'MS4_HTTP_' + kind.upper() + '_NATIVE_IDENTITY_ONLY'
            and all(row[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']),
            'H5_FINAL_SAME_BOUND_NETWORK_RECEIPT')
        value = row['network']; identity = final_h5_network_identity(base, value)
        if kind == 'backend':
            base.expect({key: value[key] for key in ['Id', 'Name', 'Internal', 'Labels']} == context['initial_network']
                        and value['Internal'] is True, 'H5_FINAL_ORIGINAL_BACKEND_IDENTITY')
        else:
            base.expect(value['Name'] == context['project'] + '_frontend' and value['Internal'] is False
                and value['Labels'] == {'tianli.audit': '1', 'tianli.audit.run': context['run_id'],
                    'com.docker.compose.project': context['project'], 'com.docker.compose.network': 'frontend'}
                and value['Options'] == {'com.docker.network.enable_ipv4': 'true',
                    'com.docker.network.bridge.enable_ip_masquerade': 'false'} and value['Containers'] == {},
                'H5_FINAL_ORIGINAL_NO_MASQUERADE_FRONTEND')
        identities[kind] = identity
    base.expect(identities['backend']['Id'] != identities['frontend']['Id'], 'H5_FINAL_DISTINCT_NETWORK_IDENTITIES')
    for label in ['com.docker.compose.project=' + context['project'], 'tianli.audit.run=' + context['run_id']]:
        actual = {base.native(context, ['network', 'inspect', '--format', '{{.Id}}', item], 'h5-final-network-id')
                  for item in base.ids(context, 'network', label)}
        base.expect(actual == {row['Id'] for row in identities.values()}, 'H5_FINAL_EXACT_TWO_LABELLED_NETWORKS')
    current = {}
    for kind, identity in identities.items():
        value = json.loads(base.native(context, ['network', 'inspect', '--format', H5_NETWORK_FULL, identity['Id']],
                                       'h5-final-current-' + kind))
        base.expect(final_h5_network_identity(base, value) == identity, 'H5_FINAL_SAME_NETWORK_COMPLETE_IDENTITY')
        permitted = {cid for cid, role in allowed.items() if kind == 'backend' or role == 'audit-ui'}
        base.expect(set(value['Containers']) <= permitted, 'H5_FINAL_ONLY_EXPLICIT_NETWORK_ENDPOINTS')
        current[kind] = value
    return current


def final_h5_owned(base, context, frozen, facts, role, proof=None):
    if role == base.PAID['role']:
        h5_owned(base, context, frozen, facts, role)
        return
    if role in ['audit-mysql', 'audit-redis']:
        base.owned(context, facts, role); base.same_created(context['initial_service_created'][role], facts)
        return
    base.expect(proof in H5_FINAL_PROOFS and H5_FINAL_PROOFS[proof]['role'] == role, 'H5_FINAL_FINITE_NATIVE_ROLE')
    if role != 'audit-ui':
        base.owned(context, facts, role)
    else:
        base.expect(facts['labels'].get('tianli.audit') == '1' and facts['labels'].get('tianli.audit.run') == context['run_id']
            and facts['labels'].get('com.docker.compose.project') == context['project']
            and facts['labels'].get('com.docker.compose.service') == role and facts['image'] == H5_NGINX
            and facts['user'] == '101:101' and facts['entrypoint'] == ['nginx'] and facts['workdir'] == '/'
            and facts['read_only'] is True and facts['privileged'] is False and facts['restart_count'] == 0
            and facts['cap_drop'] == ['ALL'] and facts['security_options'] in [['no-new-privileges'], ['no-new-privileges:true']],
            'H5_FINAL_UI_IMAGE_USER_PROCESS_AND_PRIVILEGES')
    base.expect(facts['command'] == H5_FINAL_PROOFS[proof]['command'] and set(facts['tmpfs']) == {'/tmp'}
        and type(facts['tmpfs']['/tmp']) is str and 'rw' in facts['tmpfs']['/tmp'].split(','), 'H5_FINAL_COMMAND_AND_TMPFS')
    if role == 'audit-ui':
        binding = {'8080/tcp': [{'HostIp': '127.0.0.1', 'HostPort': final_h5_port(base, frozen)}]}
        base.expect(facts['port_bindings'] == binding and (facts['ports'] is None or type(facts['ports']) is dict
            and set(facts['ports']) <= {'80/tcp', '8080/tcp'} and facts['ports'].get('80/tcp') is None
            and facts['ports'].get('8080/tcp') in [None, binding['8080/tcp']])
            and set(facts['networks']) == {context['project'] + '_isolation', context['project'] + '_frontend'}
            and facts['network_mode'] in facts['networks'], 'H5_FINAL_ONLY_UI_LOOPBACK_AND_TWO_NETWORKS')
        if facts['status'] == 'running':
            base.expect(facts['ports'].get('8080/tcp') == binding['8080/tcp'], 'H5_FINAL_RUNNING_UI_ACTUAL_PORT')
        wanted = {'/srv/public': ('volume', context['project'] + '_http-public', False),
            '/etc/nginx/nginx.conf': ('bind', (PREP / 'ms4-h5-nginx.conf').as_posix(), False)}
    else:
        base.expect(facts['user'] in ['', '0', '0:0', 'root'], 'H5_FINAL_PHP_REAL_ROOT_USER')
        wanted = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'],
                proof == 'register-customer-h5-serve'), '/run/audit-app': ('volume', context['project'] + '_app-secrets', False)}
        if proof == 'customer-h5-serve':
            base.expect(facts['stop_signal'] == 'SIGTERM', 'H5_FINAL_REAL_SERVE_SIGTERM')
            wanted.update({'/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', False),
                '/workspace/crmeb/public': ('volume', context['project'] + '_http-public', False),
                '/workspace/crmeb/runtime': ('volume', context['project'] + '_http-runtime', True),
                '/run/audit-http-control': ('volume', context['project'] + '_http-control', True),
                '/run/audit-http-browser': ('volume', context['project'] + '_http-browser-secrets', False)})
        base.expect('audit-app' not in (facts['networks'][context['project'] + '_isolation'].get('Aliases') or []),
                    'H5_FINAL_NO_CUSTOM_AUDIT_APP_ALIAS')
    mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    base.expect(len(mounts) == len(wanted) and {row['Destination'] for row in mounts} == set(wanted)
        and all(row['Destination'] == '/tmp' and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'),
        'H5_FINAL_COMPLETE_MOUNT_MULTISET')
    for row in mounts:
        kind, source, rw = wanted[row['Destination']]
        base.expect(row['Type'] == kind and row['RW'] is rw and (base.canonical_host(row['Source']) == base.canonical_host(source)
            if kind == 'bind' else row['Name'] == source), 'H5_FINAL_EXACT_MOUNT_SOURCE_ACCESS')


def final_h5_global_consumers(base, context, allowed):
    ids = base.native(context, ['ps', '-aq', '--no-trunc'], 'h5-final-global-consumers').splitlines()
    if not ids:
        return
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'
    private = {context['project'] + '_' + name for name in [*base.VOLUMES, *H5_VOLUMES]}
    networks = {context['project'] + '_isolation', context['project'] + '_frontend'}
    for line in base.native(context, ['inspect', '--format', template, *ids], 'h5-final-global-facts').splitlines():
        row = json.loads(line); used = {mount.get('Name') for mount in row['mounts'] if mount.get('Type') == 'volume'}
        if used & private or networks & set(row['networks']):
            base.expect(row['id'] in allowed, 'H5_FINAL_NO_FOREIGN_TEN_VOLUME_OR_TWO_NETWORK_CONSUMER')


def bound_final_h5(base, context, frozen, proof):
    base.expect(proof in H5_FINAL_PROOFS, 'H5_FINAL_KNOWN_CREATED_PROOF')
    value = base.load(evidence(context, proof + '-created.json'))
    base.expect(value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
        and value['proof_name'] == proof and re.fullmatch('[a-f0-9]{64}', value['id'])
        and value['status'] == 'created' and value['pid'] == 0, 'H5_FINAL_REAL_ORIGINAL_CREATED_RECEIPT')
    final_h5_owned(base, context, frozen, value, H5_FINAL_PROOFS[proof]['role'], proof)
    return value


def final_h5_expected(base, context, frozen, helper=False):
    expected = base_expected(base, context); proofs = {}
    for proof in ['customer-h5-serve', 'customer-h5-ui'] + (['register-customer-h5-serve'] if helper else []):
        value = bound_final_h5(base, context, frozen, proof)
        base.expect(value['id'] not in expected, 'H5_FINAL_DISTINCT_ORIGINAL_CIDS')
        expected[value['id']] = H5_FINAL_PROOFS[proof]['role']; proofs[value['id']] = proof
    base.expect(len(expected) == len(base_expected(base, context)) + 2 + int(helper),
        'H5_FINAL_EXACT_BASE_CANONICAL_AND_TWO_TARGETS_WITH_OPTIONAL_HELPER')
    return expected, proofs


def collect_final_h5(base, context, frozen, expected, proofs, publish=False):
    base.expect(base.active(context) == set(expected) and all(expected.get(cid) == role
        for cid, role in base_expected(base, context).items()), 'H5_FINAL_EXACT_ACTIVE_SAME_BASE_CIDS')
    topology = final_h5_networks(base, context, expected); facts_by_id = {}; rows = []
    for cid, role in expected.items():
        facts = base.inspect(context, cid, 'h5-final-collect-native')
        final_h5_owned(base, context, frozen, facts, role, proofs.get(cid))
        if cid in proofs:
            base.same_created(bound_final_h5(base, context, frozen, proofs[cid]), facts)
        for name, network in facts['networks'].items():
            identity = next(row for row in topology.values() if row['Name'] == name)
            base.expect(network.get('NetworkID') in ['', identity['Id']]
                and (facts['status'] != 'running' or network.get('NetworkID') == identity['Id']), 'H5_FINAL_CURRENT_NETWORK_ID')
            if facts['status'] == 'running':
                base.expect(isinstance(ipaddress.ip_address(network.get('IPAddress', '')), ipaddress.IPv4Address),
                            'H5_FINAL_RUNNING_NATIVE_IPV4_PRESENT')
        if role in ['audit-mysql', 'audit-redis']:
            base.expect(facts['networks'][context['initial_network']['Name']]['IPAddress']
                == base.load(Path(context['run_dir']) / 'manifest.json')['services'][role]['address'],
                'H5_FINAL_ORIGINAL_DATABASE_CACHE_ADDRESS_STRICT')
        base.proof_ini_environment(context, cid, role)
        facts_by_id[cid] = facts
        rows.append({key: facts[value] for key, value in [('id', 'id'), ('image_id', 'image'), ('mounts', 'mounts'),
            ('labels', 'labels'), ('networks', 'networks'), ('ports', 'ports'), ('read_only', 'read_only'),
            ('privileged', 'privileged'), ('network_mode', 'network_mode')]})
    volumes = h5_volumes(base, context); final_h5_global_consumers(base, context, set(expected))
    if publish:
        base.expect(set(expected.values()) == {'audit-ms4-customer-h5', 'audit-ui', base.PAID['role'], 'audit-mysql', 'audit-redis'}
            and all(facts_by_id[cid]['status'] == 'created' and facts_by_id[cid]['pid'] == 0 for cid in proofs),
            'H5_FINAL_PRECOLLECT_EXACT_FOUR_BEFORE_HELPER_AND_START')
        for name, raw in [('containers.jsonl', ''.join(json.dumps(row) + '\n' for row in rows).encode()),
            ('volumes.txt', ('\n'.join(sorted(row['name'] for row in volumes)) + '\n').encode()),
            ('volumes.jsonl', ''.join(json.dumps(row) + '\n' for row in volumes).encode())]:
            path = Path(context['run_dir']) / name
            previous = evidence(context, 'h5-final-before-' + name)
            base.expect(path.is_file() and not path.is_symlink() and not previous.exists(), 'H5_FINAL_PRECOLLECT_ARCHIVE_ONCE')
            with previous.open('xb') as stream:
                old = path.read_bytes(); base.expect(stream.write(old) == len(old), 'H5_FINAL_PREVIOUS_METADATA_FULL_BYTES')
            path.write_bytes(raw); base.expect(path.read_bytes() == raw, 'H5_FINAL_REAL_PRECOLLECT_READBACK')
    return facts_by_id


def final_h5_preconditions(base, context, frozen):
    base.verify_files_bootstrap(context, False); base.verify_mode_result(context, 'prepare', False)
    base.migration_prerequisites(context)
    base.verify_fulfillment_migration(context, False)
    base.current_unstarted_paid(context)
    for proof in ['customer-h5-public-copy', 'customer-h5-prepare', 'customer-h5-files']:
        removed_h5(base, context, frozen, proof)
    public_copy_result(base, context, frozen)
    extracted_public_result(base, context, frozen, 'customer-h5-prepare')
    extracted_public_result(base, context, frozen, 'customer-h5-files')


def create_final_h5(base, materials, context, frozen):
    final_h5_source(base, materials, context, frozen); final_h5_preconditions(base, context, frozen)
    collect_h5(base, context, frozen, base_expected(base, context), {})
    base.expect(not any(evidence(context, name).exists() for name in ['http-backend-network-created.json',
        'http-frontend-network-created.json', 'customer-h5-serve-created.json', 'customer-h5-ui-created.json']),
        'H5_FINAL_NEW_NETWORK_AND_TARGETS_CREATE_ONCE')
    backend = json.loads(base.native(context, ['network', 'inspect', '--format', H5_NETWORK_FULL, context['network_id']],
                                     'h5-final-backend-created-identity'))
    final_h5_network_identity(base, backend)
    base.expect({key: backend[key] for key in ['Id', 'Name', 'Internal', 'Labels']} == context['initial_network'],
                'H5_FINAL_ORIGINAL_BACKEND_BEFORE_FRONTEND')
    name = context['project'] + '_frontend'
    base.expect(base.native(context, ['network', 'ls', '--format', '{{.Name}}', '--filter', 'name=^' + name + '$'],
        'h5-final-frontend-name-create-only') == '', 'H5_FINAL_FRONTEND_NAME_ABSENT')
    args = ['network', 'create', '--driver', 'bridge', '--scope', 'local', '--ipv6=false',
            '--opt', 'com.docker.network.bridge.enable_ip_masquerade=false']
    for key, value in {'tianli.audit': '1', 'tianli.audit.run': context['run_id'],
        'com.docker.compose.project': context['project'], 'com.docker.compose.network': 'frontend'}.items():
        args += ['--label', key + '=' + value]
    network_id = base.native(context, args + [name], 'h5-final-create-frontend-once')
    base.expect(re.fullmatch('[a-f0-9]{64}', network_id), 'H5_FINAL_REAL_FRONTEND_NATIVE_ID')
    frontend = json.loads(base.native(context, ['network', 'inspect', '--format', H5_NETWORK_FULL, network_id],
                                      'h5-final-created-frontend'))
    for kind, value in [('backend', backend), ('frontend', frontend)]:
        base.new(evidence(context, 'http-' + kind + '-network-created.json'), {'schema': 1,
            'scope': 'MS4_HTTP_' + kind.upper() + '_NATIVE_IDENTITY_ONLY',
            **{key: context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']}, 'network': value})
    collect_final_h5(base, context, frozen, base_expected(base, context), {})
    base.expect(base.native(context, ['image', 'inspect', '--format', '{{.Id}}', H5_NGINX], 'h5-final-cached-ui-image')
                == H5_NGINX, 'H5_FINAL_EXACT_EXISTING_UI_IMAGE')
    compose_final_h5(base, context, frozen, ['create', '--no-recreate', '--no-build', '--pull', 'never',
        'audit-ms4-customer-h5', 'audit-ui'], 'h5-final-create', 'customer-h5-serve')
    for proof in ['customer-h5-serve', 'customer-h5-ui']:
        role = H5_FINAL_PROOFS[proof]['role']
        values = compose_final_h5(base, context, frozen, ['ps', '-aq', role], proof + '-lookup', proof).splitlines()
        base.expect(len(values) == 1 and re.fullmatch('[a-f0-9]{64}', values[0]), 'H5_FINAL_ONE_REAL_ROLE_CID')
        facts = base.inspect(context, values[0], proof + '-created-native'); final_h5_owned(base, context, frozen, facts, role, proof)
        base.expect(facts['status'] == 'created' and facts['pid'] == 0, 'H5_FINAL_UNSTARTED_ORIGINAL_TARGET')
        base.new(evidence(context, proof + '-created.json'), facts | {'run_id': context['run_id'],
            'source_sha': context['source_sha'], 'proof_name': proof})
    expected, proofs = final_h5_expected(base, context, frozen)
    collect_final_h5(base, context, frozen, expected, proofs)
    final_h5_stop_signal(base, context, frozen, True)
    final_h5_source(base, materials, context, frozen)
    return {'status': 'CREATED_ORIGINAL_H5_SERVE_UI_ONLY', 'container_ids': sorted(proofs), 'HTTP_DOM': 'NOT RUN'}


def final_h5_stop_signal(base, context, frozen, publish=False):
    before = bound_final_h5(base, context, frozen, 'customer-h5-serve')
    template = '{"id":{{json .Id}},"image":{{json .Image}},"stop_signal":{{json .Config.StopSignal}}}'
    process = subprocess.run(['docker', 'inspect', '--format', template, before['id']], capture_output=True)
    native = {'exit_code': process.returncode, 'stdout_bytes': len(process.stdout), 'stdout_sha256': base.digest(process.stdout),
        'stderr_bytes': len(process.stderr), 'stderr_sha256': base.digest(process.stderr)}
    base.expect(process.returncode == 0 and process.stderr == b'' and json.loads(process.stdout)
        == {'id': before['id'], 'image': base.PHP, 'stop_signal': 'SIGTERM'}, 'H5_FINAL_ACTUAL_NATIVE_STOP_SIGNAL')
    value = {'schema': 1, 'scope': 'MS4_HTTP_APP_STOP_SIGNAL_NATIVE_ONLY',
        **{key: context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']}, 'container_id': before['id'],
        'native_created_sha256': base.digest(evidence(context, 'customer-h5-serve-created.json').read_bytes()),
        'effective_stop_signal': 'SIGTERM', 'native_inspect': native}
    path = evidence(context, 'http-app-stop-signal.json')
    if publish:
        base.new(path, value)
    else:
        base.expect(base.load(path) == value, 'H5_FINAL_SAME_NATIVE_STOP_SIGNAL_RECEIPT')
    return value


def final_h5_inputs(base, context):
    return {key: descriptor(base, Path(context['run_dir']) / name, '/run/audit/' + name) for key, name in [
        ('containers', 'containers.jsonl'), ('volumes', 'volumes.txt'), ('volume_identities', 'volumes.jsonl')]}


def final_h5_binding(base, context, frozen, proof):
    before = bound_final_h5(base, context, frozen, proof)
    canonical_paid = h5_paid_observation(base, context, proof)
    environment, native = base.environment_observation(context, before['id'])
    networks = {kind: descriptor(base, evidence(context, 'http-' + kind + '-network-created.json'),
        '/run/audit/evidence/http-' + kind + '-network-created.json') for kind in ['backend', 'frontend']}
    binding = {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_FINAL_REGISTRATION_MS5_SOURCE' if proof.startswith('register-')
        else 'MS4_CUSTOMER_H5_HTTP_MS5_SOURCE'}
    if proof.startswith('register-'):
        binding.update({'phase': 'register-final', 'proof_name': proof})
    binding.update({'run_id': context['run_id'], 'source_sha': context['source_sha'], 'container_id': before['id'],
        'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': next(row['sha256'] for row in frozen['h5_artifact_files'] if row['path'] == 'ms4-h5-bootstrap.php')
            if proof.startswith('register-') else next(row['sha256'] for row in frozen['h5_source_files'] if row['path'] == H5_ENTRY),
        'source_files': frozen['customer_source_files'], 'h5_source_files': frozen['h5_source_files'],
        'artifact_files': frozen['artifact_files'], 'h5_artifact_files': frozen['h5_artifact_files']})
    public = descriptor(base, evidence(context, 'customer-h5-public-copy.json'), '/run/audit/evidence/customer-h5-public-copy.json')
    if proof.startswith('register-'):
        binding.update({'environment': environment, 'public_copy': public,
            'prepare_result': descriptor(base, evidence(context, 'customer-h5-prepare-result.json'),
                '/run/audit/evidence/customer-h5-prepare-result.json'),
            'files_result': descriptor(base, evidence(context, 'customer-h5-files-result.json'),
                '/run/audit/evidence/customer-h5-files-result.json'), 'registration_inputs': final_h5_inputs(base, context),
            'network_receipts': networks, 'stop_signal': descriptor(base, evidence(context, 'http-app-stop-signal.json'),
                '/run/audit/evidence/http-app-stop-signal.json'),
            'targets': {name: descriptor(base, evidence(context, item + '-created.json'),
                '/run/audit/evidence/' + item + '-created.json') for name, item in [
                    ('serve', 'customer-h5-serve'), ('ui', 'customer-h5-ui')]}})
    else:
        base.expect(proof == 'customer-h5-serve', 'H5_FINAL_BINDING_ONLY_REAL_SERVE_OR_FINAL_HELPER')
        binding.update({'h5_materials': frozen['h5_materials'], 'public_copy': public,
            'prepare_result': descriptor(base, evidence(context, 'customer-h5-prepare-result.json'), '/run/audit-http-control/prepare-result.json'),
            'files_result': descriptor(base, evidence(context, 'customer-h5-files-result.json'), '/run/audit-http-control/files-result.json'),
            'registration_result': descriptor(base, evidence(context, 'register-customer-h5-serve-result.json'),
                '/run/audit/evidence/register-customer-h5-serve-result.json'),
            'environment': descriptor(base, evidence(context, proof + '-environment.json'), '/run/audit/evidence/' + proof + '-environment.json'),
            'network_receipts': networks, 'stop_signal': descriptor(base, evidence(context, 'http-app-stop-signal.json'),
                '/run/audit/evidence/http-app-stop-signal.json'),
            'manifest': descriptor(base, Path(context['run_dir']) / 'manifest.json', '/run/audit/manifest.json'),
            'registration_inputs': final_h5_inputs(base, context)})
    binding['prerequisites_sha256'] = h5_prerequisite_hashes(base, context, frozen,
        'register-final' if proof.startswith('register-') else 'serve')
    binding['canonical_paid'] = canonical_paid
    base.expect(len(binding) == 27, 'H5_FINAL_EXACT_TWENTY_SEVEN_MS5_BINDING_FIELDS')
    return binding, native, environment


def final_h5_manifest(base, context, frozen):
    expected, proofs = final_h5_expected(base, context, frozen)
    inputs = final_h5_inputs(base, context); rows = [json.loads(line) for line in
        (Path(context['run_dir']) / 'containers.jsonl').read_text(encoding='utf-8').splitlines()]
    by_role = {row['labels']['com.docker.compose.service']: row for row in rows}
    base.expect(len(rows) == len(by_role) == len(expected) and set(by_role) == set(expected.values()), 'H5_FINAL_ORIGINAL_FOUR_PRECOLLECTED_ROLES')
    base.expect(all(by_role[role]['id'] == cid for cid, role in expected.items()), 'H5_FINAL_PRECOLLECTED_SAME_FOUR_ORIGINAL_CIDS')
    services = {role: {'id': cid, 'address': by_role[role]['networks'][context['initial_network']['Name']]['IPAddress'],
        'image_id': by_role[role]['image_id']} for cid, role in expected.items()}
    volumes = h5_volumes(base, context); value = base.load(Path(context['run_dir']) / 'manifest.json')
    topology = base.load(evidence(context, 'http-frontend-network-created.json'))['network']
    base.expect(value['run_id'] == context['run_id'] and value['commit_sha'] == context['source_sha']
        and value['services'] == services and value['network_internal'] is True
        and value['network'] == {'id': context['network_id'], 'name': context['initial_network']['Name']}
        and value['frontend_network'] == final_h5_network_identity(base, topology)
        and value['http_stop_signal_sha256'] == base.digest(evidence(context, 'http-app-stop-signal.json').read_bytes())
        and value['volumes'] == sorted(row['name'] for row in volumes)
        and value['volume_identities'] == {row['name']: row['created_at'] for row in volumes},
        'H5_FINAL_MANIFEST_USES_ORIGINAL_PRESTART_INPUTS_NOT_RUNNING_APP_IP')
    return value, inputs


def create_final_registration_h5(base, materials, context, frozen):
    final_h5_source(base, materials, context, frozen); final_h5_preconditions(base, context, frozen)
    expected, proofs = final_h5_expected(base, context, frozen)
    collect_final_h5(base, context, frozen, expected, proofs, True)
    proof = 'register-customer-h5-serve'; role = H5_FINAL_PROOFS[proof]['role']
    base.expect(not evidence(context, proof + '-created.json').exists(), 'H5_FINAL_ONE_TRANSIENT_REGISTER_HELPER')
    inputs = final_h5_inputs(base, context)
    compose_final_h5(base, context, frozen, ['create', '--no-recreate', '--no-build', '--pull', 'never', role], proof + '-create', proof)
    values = compose_final_h5(base, context, frozen, ['ps', '-aq', role], proof + '-lookup', proof).splitlines()
    base.expect(len(values) == 1 and re.fullmatch('[a-f0-9]{64}', values[0]), 'H5_FINAL_ONE_ACTUAL_REGISTER_CID')
    facts = base.inspect(context, values[0], proof + '-created-native'); final_h5_owned(base, context, frozen, facts, role, proof)
    base.expect(facts['status'] == 'created' and facts['pid'] == 0, 'H5_FINAL_REAL_UNSTARTED_REGISTER_HELPER')
    base.new(evidence(context, proof + '-created.json'), facts | {'run_id': context['run_id'], 'source_sha': context['source_sha'], 'proof_name': proof})
    wanted, mapping = final_h5_expected(base, context, frozen, True); collect_final_h5(base, context, frozen, wanted, mapping)
    base.expect(final_h5_inputs(base, context) == inputs, 'H5_FINAL_HELPER_EXCLUDED_FROM_IMMUTABLE_FOUR_INPUTS')
    h5_paid_observation(base, context, proof, True)
    binding, native, environment = final_h5_binding(base, context, frozen, proof)
    base.new(evidence(context, proof + '-environment-native.json'), native)
    base.new(evidence(context, proof + '-source-binding.json'), binding)
    final_h5_source(base, materials, context, frozen)
    return {'status': 'CREATED_SOURCE_BOUND_FINAL_REGISTER_ONLY', 'container_id': facts['id'], 'HTTP_DOM': 'NOT RUN'}


def observe_final_registration_h5(base, materials, context, frozen, seconds):
    final_h5_source(base, materials, context, frozen)
    base.expect(type(seconds) is int and 0 <= seconds <= 30, 'H5_FINAL_REGISTER_BOUNDED_SAME_HANDLE_OBSERVATION')
    expected, proofs = final_h5_expected(base, context, frozen, True)
    collect_final_h5(base, context, frozen, expected, proofs)
    proof = 'register-customer-h5-serve'; before = bound_final_h5(base, context, frozen, proof)
    result = base.observe(context, before['id'], proof, seconds)
    actual = collect_final_h5(base, context, frozen, expected, proofs)
    base.same_created(before, actual[before['id']])
    if result.get('status') == 'exited':
        final_h5_owned(base, context, frozen, result, H5_FINAL_PROOFS[proof]['role'], proof)
        base.same_created(before, result)
    final_h5_source(base, materials, context, frozen)
    return result


def finish_final_registration_h5(base, materials, context, frozen):
    final_h5_source(base, materials, context, frozen)
    proof = 'register-customer-h5-serve'; before = bound_final_h5(base, context, frozen, proof)
    expected, proofs = final_h5_expected(base, context, frozen, True); collect_final_h5(base, context, frozen, expected, proofs)
    facts = base.inspect(context, before['id'], proof + '-normal-terminal')
    final_h5_owned(base, context, frozen, facts, H5_FINAL_PROOFS[proof]['role'], proof); base.same_created(before, facts)
    base.expect(facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['exit_code']) is int and facts['exit_code'] == 0, 'H5_FINAL_REGISTER_ACTUAL_NORMAL_ZERO')
    base.persist_terminal(context, proof, facts)
    binding, native, environment = final_h5_binding(base, context, frozen, proof)
    base.expect(base.load(evidence(context, proof + '-source-binding.json')) == binding
        and base.load(evidence(context, proof + '-environment-native.json')) == native, 'H5_FINAL_REGISTER_SAME_BINDING_AND_ENV')
    value = base.load(evidence(context, proof + '-result.json')); manifest, inputs = final_h5_manifest(base, context, frozen)
    base.expect(set(value) == {'schema', 'scope', 'status', 'phase', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'proof_name', 'full_source_unchanged', 'native_exit', 'business', 'HTTP_DOM'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        and value['status'] == 'REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY' and value['phase'] == 'register-final'
        and value['proof_name'] == proof and value['container_id'] == before['id']
        and all(value[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and value['native_created_sha256'] == base.digest(evidence(context, proof + '-created.json').read_bytes())
        and value['source_binding_sha256'] == base.digest(evidence(context, proof + '-source-binding.json').read_bytes())
        and value['full_source_unchanged'] is True and type(value['native_exit']) is int and value['native_exit'] == 0
        and value['HTTP_DOM'] == 'NOT RUN' and value['business'] == {'registered_roles': sorted(manifest['services']),
            'input_inventory_excludes_transient_helper': True,
            'manifest_sha256': base.digest((Path(context['run_dir']) / 'manifest.json').read_bytes())}, 'H5_FINAL_ACTUAL_REGISTER_RESULT')
    base.native(context, ['rm', before['id']], proof + '-exact-nonforce-remove')
    remaining, mapping = final_h5_expected(base, context, frozen); collect_final_h5(base, context, frozen, remaining, mapping)
    base.expect(final_h5_inputs(base, context) == inputs, 'H5_FINAL_REGISTER_REMOVAL_PRESERVES_INPUT_BYTES')
    base.new(evidence(context, proof + '-removed.json'), {'schema': 1, 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'container_id': before['id'],
        'terminal_sha256': base.digest(evidence(context, proof + '-terminal.json').read_bytes()),
        'result_sha256': base.digest(evidence(context, proof + '-result.json').read_bytes())})
    final_h5_source(base, materials, context, frozen)
    return {'status': 'FINAL_REGISTER_REAL_TERMINAL_ARCHIVED_SAME_CID_REMOVED', 'container_id': before['id'], 'HTTP_DOM': 'NOT RUN'}


def bind_final_h5(base, materials, context, frozen):
    final_h5_source(base, materials, context, frozen); final_h5_preconditions(base, context, frozen)
    expected, proofs = final_h5_expected(base, context, frozen); actual = collect_final_h5(base, context, frozen, expected, proofs)
    proof = 'register-customer-h5-serve'; helper = bound_final_h5(base, context, frozen, proof)
    removed = base.load(evidence(context, proof + '-removed.json')); terminal = base.load(evidence(context, proof + '-terminal.json'))
    base.same_created(helper, terminal)
    base.expect(helper['id'] not in base.active(context) and terminal['status'] == 'exited' and terminal['pid'] == 0
        and terminal['exit_code'] == 0 and removed['container_id'] == helper['id']
        and removed['terminal_sha256'] == base.digest(evidence(context, proof + '-terminal.json').read_bytes())
        and removed['result_sha256'] == base.digest(evidence(context, proof + '-result.json').read_bytes()),
        'H5_FINAL_REGISTER_NATIVE_TERMINAL_AND_SAME_HELPER_REMOVAL')
    final_h5_manifest(base, context, frozen)
    base.expect(all(actual[cid]['status'] == 'created' and actual[cid]['pid'] == 0 for cid in proofs), 'H5_FINAL_BIND_ONLY_UNSTARTED_TARGETS')
    serve = bound_final_h5(base, context, frozen, 'customer-h5-serve'); environment, native = base.environment_observation(context, serve['id'])
    base.new(evidence(context, 'customer-h5-serve-environment-native.json'), native)
    base.new(evidence(context, 'customer-h5-serve-environment.json'), {'schema': 1, 'scope': 'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT',
        **{key: context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']}, 'container_id': serve['id'],
        'environment': environment, 'native_projection_sha256': native['stdout_sha256']})
    h5_paid_observation(base, context, 'customer-h5-serve', True)
    binding, current_native, current_environment = final_h5_binding(base, context, frozen, 'customer-h5-serve')
    base.expect(current_native == native and current_environment == environment, 'H5_FINAL_REAL_ENV_STABLE_BEFORE_BIND')
    base.new(evidence(context, 'ms4-customer-h5-serve-source-binding.json'), binding)
    final_h5_source(base, materials, context, frozen)
    return {'status': 'BOUND_ORIGINAL_UNSTARTED_SERVE_AND_UI_IDENTITIES', 'container_id': serve['id'], 'HTTP_DOM': 'NOT RUN'}


def start_final_h5(base, materials, context, frozen, proof):
    final_h5_source(base, materials, context, frozen)
    base.expect(proof in H5_FINAL_PROOFS, 'H5_FINAL_EXPLICIT_SINGLE_START_PROOF')
    helper = proof == 'register-customer-h5-serve'
    expected, proofs = final_h5_expected(base, context, frozen, helper); actual = collect_final_h5(base, context, frozen, expected, proofs)
    before = bound_final_h5(base, context, frozen, proof)
    base.expect(actual[before['id']]['status'] == 'created' and actual[before['id']]['pid'] == 0, 'H5_FINAL_START_SAME_UNSTARTED_HANDLE')
    binding_proof = proof if helper else 'customer-h5-serve'
    path = evidence(context, binding_proof + '-source-binding.json' if helper else 'ms4-customer-h5-serve-source-binding.json')
    binding, native, environment = final_h5_binding(base, context, frozen, binding_proof)
    base.expect(base.load(path) == binding and base.load(evidence(context, binding_proof + '-environment-native.json')) == native,
                'H5_FINAL_START_COMPLETE_SOURCE_AND_NATIVE_ENV_BINDING')
    if helper:
        base.expect(all(actual[cid]['status'] == 'created' and actual[cid]['pid'] == 0 for cid in proofs if cid != before['id']),
                    'H5_FINAL_REGISTER_BEFORE_TARGET_START')
    else:
        final_h5_manifest(base, context, frozen)
        if proof == 'customer-h5-ui':
            serve = bound_final_h5(base, context, frozen, 'customer-h5-serve')
            base.expect(actual[serve['id']]['status'] == 'running' and actual[serve['id']]['pid'] > 0, 'H5_FINAL_SERVE_RUNNING_BEFORE_UI_DNS')
            handoff = base.load(evidence(context, 'customer-h5-private-handoff.json'))
            base.expect(handoff['scope'] == 'MS4_CUSTOMER_H5_PRIVATE_HANDOFF' and handoff['status'] == 'VERIFIED_PRIVATE_ACTOR_COPY_ONLY'
                and all(handoff[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
                and handoff['container_id'] == serve['id'] and handoff['private_actor_count'] == 1
                and handoff['private_actor_file_retained_on_host'] is False and handoff['actor_values_emitted'] is False
                and all(type(handoff[key]) is int and handoff[key] == 0 for key in ['docker_cp_exit', 'tar_list_exit', 'tar_extract_exit']),
                'H5_FINAL_ACTUAL_PRIVATE_HANDOFF_BEFORE_UI_START')
    base.new(evidence(context, proof + '-start-request.json'), {'schema': 1,
        'scope': 'MS4_CUSTOMER_H5_SINGLE_NATIVE_START_ATTEMPT_ONLY', **{key: context[key] for key in ['run_id', 'source_sha', 'freeze_sha256']},
        'container_id': before['id'], 'proof_name': proof,
        'native_created_sha256': base.digest(evidence(context, proof + '-created.json').read_bytes()),
        'source_binding_sha256': base.digest(path.read_bytes())})
    base.native(context, ['start', before['id']], proof + '-single-start')
    collect_final_h5(base, context, frozen, expected, proofs)
    final_h5_source(base, materials, context, frozen)
    return {'status': 'STARTED_ORIGINAL_FINAL_CID_ONLY', 'container_id': before['id'], 'completion': 'ACTUAL_OBSERVATION_REQUIRED', 'HTTP_DOM': 'NOT RUN'}


def stage_h5(name, context_file, proof=None, seconds=30):
    base, materials, context, frozen = runtime(context_file)
    base.expect(type(seconds) is int and 0 <= seconds <= 30, 'H5_STAGE_OBSERVATION_AT_MOST_THIRTY_SECONDS')
    if name in H5_FINAL_STAGES:
        base.expect(proof is None, 'H5_FINAL_CLI_FIXED_PROOF_NO_OVERRIDE')
        if name == 'final-create':
            result = create_final_h5(base, materials, context, frozen)
        elif name == 'final-register-create':
            result = create_final_registration_h5(base, materials, context, frozen)
        elif name == 'final-register-start':
            result = start_final_h5(base, materials, context, frozen, 'register-customer-h5-serve')
        elif name == 'final-register-observe':
            result = observe_final_registration_h5(base, materials, context, frozen, seconds)
        elif name == 'final-register-finish':
            result = finish_final_registration_h5(base, materials, context, frozen)
        elif name == 'final-bind':
            result = bind_final_h5(base, materials, context, frozen)
        elif name == 'final-serve-start':
            result = start_final_h5(base, materials, context, frozen, 'customer-h5-serve')
        else:
            base.expect(name == 'final-ui-start', 'H5_FINAL_FINITE_EXACT_STAGE')
            result = start_final_h5(base, materials, context, frozen, 'customer-h5-ui')
        verify_h5_freeze(base, materials, frozen)
        return result
    if name in ORIGINAL_STAGES:
        base.expect(not evidence(context, 'customer-h5-additional-volume-identities.json').exists(),
                    'ORIGINAL_SIX_VOLUME_STAGES_END_BEFORE_H5_TRANSITION')
        if name in ['observe', 'finish-helper']:
            base.expect(proof in base.PROOF_ROLES and proof not in ['customer', 'register-customer', 'authorize-customer-cleanup'],
                        'ONLY_UNCHANGED_PRE_ORDER_ORIGINAL_PROOF')
        result = base.stage(name, context_file, proof, seconds)
        verify_h5_freeze(base, materials, frozen)
        return result
    creates = {'h5-copy-create': 'customer-h5-public-copy', 'h5-prepare-create': 'customer-h5-prepare',
               'h5-files-create': 'customer-h5-files'}
    if name in creates:
        result = create_h5_phase(base, context, frozen, creates[name])
    elif name == 'h5-register-create':
        base.expect(proof in ['register-customer-h5-prepare', 'register-customer-h5-files'], 'EXPLICIT_H5_REGISTER_PROOF')
        result = create_registration_h5(base, context, frozen, proof.removeprefix('register-'))
    elif name == 'h5-bind':
        result = bind_target_h5(base, context, frozen, proof)
    elif name == 'h5-start':
        result = start_h5_phase(base, context, frozen, proof)
    elif name == 'h5-observe':
        result = observe_h5_phase(base, context, frozen, proof, seconds)
    elif name == 'h5-finish':
        result = finish_h5_phase(base, context, frozen, proof)
    else:
        raise RuntimeError('Only explicit finite H5 preparation and final service stages')
    verify_h5_freeze(base, materials, frozen)
    return result


def main():
    global ATTEMPT_CONTEXT
    stages = ['freeze', 'prepare', *ORIGINAL_STAGES, 'h5-copy-create', 'h5-prepare-create', 'h5-files-create',
              'h5-register-create', 'h5-bind', 'h5-start', 'h5-observe', 'h5-finish', *H5_FINAL_STAGES]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=stages)
    parser.add_argument('--repo'); parser.add_argument('--source'); parser.add_argument('--port', type=int)
    parser.add_argument('--output'); parser.add_argument('--binding'); parser.add_argument('--run-root')
    parser.add_argument('--proof-ini-source')
    parser.add_argument('--context'); parser.add_argument('--proof'); parser.add_argument('--seconds', type=int, default=30)
    args = parser.parse_args()
    base, materials = dependencies()
    base.expect(0 <= args.seconds <= 30, 'EXPLICIT_BOUNDED_OBSERVATION')
    if args.stage == 'freeze':
        base.expect(args.repo and args.source and args.port is not None and args.output, 'EXPLICIT_NEW_H5_SOURCE_FREEZE')
        result = freeze(Path(args.repo).resolve(strict=True), args.source, args.port, Path(args.output), args.proof_ini_source)
    elif args.stage == 'prepare':
        base.expect(args.binding and args.run_root, 'EXPLICIT_NEW_H5_PREREQUISITE_RUN')
        verify_h5_freeze(base, materials, base.load(args.binding))
        try:
            result = base.prepare(args.binding, args.run_root)
        finally:
            ATTEMPT_CONTEXT = base.ATTEMPT_CONTEXT
        verify_h5_freeze(base, materials, base.load(args.binding))
    else:
        base.expect(args.context, 'EXPLICIT_ACTUAL_H5_CONTEXT')
        result = stage_h5(args.stage, args.context, args.proof, args.seconds)
    print(json.dumps(result, ensure_ascii=False))



# Current28dc exact Source binding; no historical source/schema union.
CURRENT28DC_SOURCE = '175f3cd6183894185656a2974579e1be39eed7a7'
CURRENT28DC_NORMAL9 = [{'path': 'README.md', 'bytes': 89381, 'sha256': 'b886791202529032c9f85a88f80af456f1e916ad2ad1031eb417685edcae6493'}, {'path': 'compose.yml', 'bytes': 11860, 'sha256': 'f6c4b4f50fbd34fda66afcf2d0819d38992a2fe467172fe587cefbeb2d39a198'}, {'path': 'ms4-host.py', 'bytes': 345436, 'sha256': '0d379c9a33dccfc0ece92fe8129842ac27105d66a50320b37e893bfd23dc295e'}, {'path': 'ms4-initialize.php', 'bytes': 78955, 'sha256': 'a5add955d43e4fd8cfdc50dd0ad06d5f6408e5cf743a5c2564b6e51af7db067b'}, {'path': 'seed-source-inverse.json', 'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, {'path': 'interface-v1.json', 'bytes': 102529, 'sha256': '5f0bdb9bd952419f879d86bb8941b06147a9bea3b4f4eeb2c814dcafef392495'}, {'path': 'source-derivation.json', 'bytes': 604017, 'sha256': 'e0bda907571363b1e1c7f58920af91b6a75a66072db0fc081ff7041e05b6db46'}, {'path': 'normal-close-ms4-fulfillment.py', 'bytes': 47220, 'sha256': 'cba367e05932836c7667e5fd10faa6f5c7c06a85dceceb27eb7b228b754bd361'}, {'path': 'verify-zero-ms4-fulfillment.py', 'bytes': 296098, 'sha256': 'ee5a9f65d87d063100f834c8456364cb8024fb3414467827e6c1ec92aa03b54f'}]
CURRENT28DC_H5_CORE2 = [{'path': 'help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php', 'bytes': 165967, 'sha256': '9f47fff3886192aaed5347eb747978e1c54c152e8bc2f77563b406644f4f75bd'}, {'path': 'help/audit-regressions/isolated/ms4-customer-h5.php', 'bytes': 112678, 'sha256': 'c78fbf4b4c05b0db98ad51779db314264f2c4ff96c34db9552258a8b07191533'}]

def current28dc_source_contract(base, repo, head):
    base.expect(head == CURRENT28DC_SOURCE, 'H5_CURRENT28DC_EXACT_ACTUAL_COMMITTED_HEAD')
    base.expect(base.VOLUMES == ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets'], 'H5_CURRENT28DC_EXACT_EIGHT_BASE_VOLUMES')
    for row in CURRENT28DC_NORMAL9:
        base.expect(base.source_row(PREP, row['path']) == row, 'H5_CURRENT28DC_COMPLETE_NORMAL9_SOURCE_BYTES')
    for row in CURRENT28DC_H5_CORE2:
        base.expect(base.source_row(repo, row['path']) == row, 'H5_CURRENT28DC_ACTUAL_COMMITTED_CORE2_BYTES')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'PRESERVED_FAILURE_OR_ORIGINAL_HANDLE_OBSERVATION_REQUIRED',
            'class': type(error).__name__, 'resources_not_automatically_deleted': True,
            'attempt_context': ATTEMPT_CONTEXT, 'result_extraction_scope': 'ONLY_FIXED_PUBLIC_CONTROL_FILES',
            'serve_browser_acceptance': 'NOT RUN'}))
        raise SystemExit(1)
