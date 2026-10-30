"""UNBOUND new B/UI SourceTree ordinary close; no force/retry/restart.

The only new-packet CLI is --ui-web. It preserves the original five CIDs,
two empty networks, and all thirteen original-generation named volumes.
Historical A/worker functions remain source-only and are not CLI choices here.
"""

B_SOURCE_TREE_SOURCE_SEAL = 'UNBOUND_PENDING_ROOT_B_SOURCE_TREE_QUALIFICATION'
if B_SOURCE_TREE_SOURCE_SEAL != 'SEALED_BY_ROOT_AFTER_B_SOURCE_TREE_QUALIFICATION':
    raise RuntimeError('B_SOURCE_TREE_SOURCE_UNBOUND')

import argparse
import hashlib
import importlib.util
import json
import re
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parent
HOST = ROOT / 'ms4-host.py'
HOST_SHA256 = '54af1df114c8f67d36c80956598a22d1844020e8e7e3c735018560682264f0d8'
STATE = {'module': None, 'context': None, 'phase': 'source_preflight', 'waiting_native': None}

def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def announce(phase, **fields):
    STATE['phase'] = phase
    print(json.dumps({'phase': phase, **fields}, ensure_ascii=False), flush=True)


def terminal(module, context, before, role, label):
    fresh = module.inspect(context, before['id'], label)
    module.owned(context, fresh, role)
    module.same_created(before, fresh)
    module.expect(fresh['status'] == 'exited' and fresh['pid'] == 0 and fresh['exit_code'] == 0
                  and fresh['restart_count'] == 0, 'NORMAL_SAME_TERMINAL_REQUIRED')
    return fresh


def inventory(module, context):
    actual = {}
    for kind in ['container', 'network', 'volume']:
        actual[kind] = {}
        for label, value in [('project', 'com.docker.compose.project=' + context['project']),
                             ('run', 'tianli.audit.run=' + context['run_id'])]:
            values = module.ids(context, kind, value)
            if kind == 'network':
                # network ls -q may abbreviate; inspect supplies the actual full ID.
                values = [module.native(context, ['network', 'inspect', '--format', '{{.Id}}', value], 'close-list-full-network-id')
                          for value in values]
            actual[kind][label] = values
    return actual


def historical_created_ids(module, context):
    evidence = Path(context['run_dir']) / 'evidence'
    historical = set()
    for name in module.aftersale_review_public_names():
        if not name.endswith('-created.json') or name in ['paid-producer-created.json', 'aftersale-review-normal-created.json']:
            continue
        value = module.load(evidence / name)
        module.expect(type(value) is dict and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
            and type(value['id']) is str and re.fullmatch('[a-f0-9]{64}', value['id'])
            and value['status'] == 'created' and type(value['pid']) is int and value['pid'] == 0,
            'COMPLETE_ACTUAL_HISTORICAL_CREATED_IDENTITY')
        historical.add(value['id'])
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
        ('register-aftersale-review-normal', 'audit-bootstrap'), ('authorize-aftersale-review-cleanup', 'audit-bootstrap')]
    for proof, role in historical_roles:
        path = evidence / (proof + '-created.json')
        if path.exists():
            value = module.bound_created(context, proof, role)
            if proof != module.AFTERSALE_REVIEW['proof']:
                historical.add(value['id'])
    return historical


def independent_global_consumers(module, context, allowed):
    operations = []
    def query(args):
        result = subprocess.run(['docker', *args], capture_output=True)
        operations.append({'argv': args, 'native_exit': result.returncode, 'stdout_bytes': len(result.stdout),
            'stdout_sha256': sha(result.stdout), 'stderr_bytes': len(result.stderr), 'stderr_sha256': sha(result.stderr)})
        module.expect(type(result.returncode) is int and result.returncode == 0 and result.stderr == b'',
                      'PAID_CLOSE_INDEPENDENT_GLOBAL_NATIVE_ZERO')
        return result.stdout
    ids = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in ids),
                  'PAID_CLOSE_GLOBAL_COMPLETE_ACTUAL_CIDS')
    network_id = context['network_id']
    module.expect(type(network_id) is str and re.fullmatch('[a-f0-9]{64}', network_id)
                  and context['initial_network']['Id'] == network_id, 'PAID_CLOSE_ORIGINAL_FULL_NETWORK_ID')
    network_names = {network_id, context['initial_network']['Name']}
    volumes = {row['name'] for row in context['initial_volumes']}
    original = module.bound_created(context, 'paid-producer', 'audit-ms5-paid-producer')
    newnormal = module.bound_created(context, module.AFTERSALE_REVIEW['proof'], module.AFTERSALE_REVIEW['role'])
    targets = set(context['initial_service_ids'].values()) | {original['id'], newnormal['id']}
    historical = historical_created_ids(module, context)
    module.expect(not historical.intersection(targets) and not historical.intersection(ids), 'CLOSE_ALL_HISTORICAL_CIDS_ACTUALLY_ABSENT')
    module.expect(not set(ids).intersection(targets - set(allowed)), 'CLOSE_REMOVED_CURRENT_CIDS_ACTUALLY_ABSENT')
    module.expect(len(targets) == 4 and len(volumes) == 8 and set(allowed) <= targets,
                  'AFTERSALE_REVIEW_CLOSE_FOUR_CID_EIGHT_VOLUME_TARGETS')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    permitted = []
    for cid in ids:
        value = json.loads(query(['inspect', '--format', template, cid]))
        module.expect(type(value) is dict and set(value) == {'id', 'mounts', 'network_mode', 'networks'}
                      and value['id'] == cid and type(value['mounts']) is list
                      and all(type(row) is dict for row in value['mounts'])
                      and type(value['networks']) is dict and all(type(row) is dict for row in value['networks'].values())
                      and type(value['network_mode']) is str, 'PAID_CLOSE_COMPLETE_GLOBAL_NATIVE_FIELDS')
        mode = value['network_mode']
        consumes = (any(row.get('Name') in volumes for row in value['mounts'])
            or mode in network_names or bool(set(value['networks']).intersection(network_names))
            or any(row.get('NetworkID') == network_id for row in value['networks'].values())
            or (mode.startswith('container:') and mode[len('container:'):] in targets | historical))
        if consumes:
            module.expect(cid in allowed, 'PAID_CLOSE_NO_UNKNOWN_ORIGINAL_RESOURCE_DEPENDENCY')
            permitted.append(cid)
    final = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(final) == len(set(final)) and set(final) == set(ids), 'PAID_CLOSE_SAME_GLOBAL_CID_SNAPSHOT')
    return {'inspected_container_count': len(ids), 'operations': operations,
            'original_container_ids': sorted(targets), 'allowed_original_consumers': sorted(permitted),
            'unexpected_original_resource_consumers': 0}


def original_resources(module, context, allow_containers):
    actual = inventory(module, context)
    module.expect(set(actual['container']['project']) == set(actual['container']['run']) == set(allow_containers),
                  'EXACT_REMAINING_KNOWN_CONTAINERS')
    module.expect(actual['network']['project'] == actual['network']['run'] == [context['network_id']],
                  'EXACT_ORIGINAL_NETWORK_ONLY')
    names = sorted(row['name'] for row in context['initial_volumes'])
    module.expect(names == sorted(context['project'] + '_' + suffix for suffix in ['app-secrets', 'bootstrap-secrets', 'fixture-secrets',
        'mysql-data', 'redis-data', 'redis-secrets', 'aftersale-evidence', 'customer-form-evidence']), 'EXACT_ORIGINAL_EIGHT_NAMED_VOLUMES')
    module.expect(sorted(actual['volume']['project']) == sorted(actual['volume']['run']) == names,
                  'EXACT_ORIGINAL_EIGHT_VOLUMES_ONLY')
    template = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'
    network = json.loads(module.native(context, ['network', 'inspect', '--format', template, context['network_id']], 'close-native-network'))
    module.expect({key: network[key] for key in ['Id', 'Name', 'Internal', 'Labels']} == context['initial_network'],
                  'SAME_ORIGINAL_NETWORK_IDENTITY')
    rows = []
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    for name in names:
        rows.append(json.loads(module.native(context, ['volume', 'inspect', '--format', template, name], 'close-native-volume')))
    module.expect(rows == context['initial_volumes'], 'SAME_ORIGINAL_EIGHT_VOLUME_IDENTITIES')
    module.global_consumers(context, set(allow_containers))
    return {'inventory': actual, 'network': network, 'volumes': rows,
            'global_consumers': independent_global_consumers(module, context, set(allow_containers))}


def host_module():
    if not HOST.is_file() or HOST.is_symlink() or sha(HOST.read_bytes()) != HOST_SHA256:
        raise RuntimeError('REVIEWED_CUSTOMER_HOST_BYTES')
    spec = importlib.util.spec_from_file_location('ms4_customer_close_host', HOST)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    STATE['module'] = module
    return module


def verify_receipts(module, context, expected):
    evidence = Path(context['run_dir']) / 'evidence'
    module.verify_fulfillment_history(context)
    module.verify_fulfillment_removed(context)
    module.verify_aftersale_review_result(context, True)
    module.verify_aftersale_review_helper(context, 'authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    paid = terminal(module, context, module.bound_created(context, 'paid-producer', module.PAID['role']), module.PAID['role'], 'close-paid-normal-required')
    newnormal = terminal(module, context, module.bound_created(context, module.AFTERSALE_REVIEW['proof'], module.AFTERSALE_REVIEW['role']),
        module.AFTERSALE_REVIEW['role'], 'close-aftersale-review-normal-required')
    module.expect(set(expected) == {paid['id'], newnormal['id'], *context['initial_service_ids'].values()}, 'EXACT_FOUR_CURRENT_AFTERSALE_REVIEW_CIDS')
    historical = historical_created_ids(module, context)
    module.expect(not historical.intersection(expected) and not historical.intersection(module.active(context)), 'ALL_HISTORICAL_EARLY_TASK6_AND_HELPER_CIDS_ABSENT')
    values = module.aftersale_review_public_hashes(context)
    formal = module.load(evidence / 'paid-producer-formal-manifest.json')
    return {'paid_prerequisites_sha256': module.paid_prerequisite_hashes(context, formal),
        'fulfillment_prerequisites_sha256': module.fulfillment_prerequisite_hashes(context), 'paid': paid, 'aftersale_review': newnormal,
        'paid_result_sha256': values['paid-producer-result.json'], 'fulfillment_result_sha256': values['ms4-fulfillment-normal.json'],
        'aftersale_review_result_sha256': values[module.AFTERSALE_REVIEW['result']],
        'authorize_created_sha256': values['authorize-aftersale-review-cleanup-created.json'],
        'authorize_terminal_sha256': values['authorize-aftersale-review-cleanup-terminal.json'],
        'authorize_result_sha256': values['authorize-aftersale-review-cleanup-result.json'], 'public_originals_sha256': values,
        'historical_absent_container_ids': sorted(historical),
        'manifest_sha256': sha((Path(context['run_dir']) / 'manifest.json').read_bytes())}


def preserved_receipts(module, context, context_file, receipts):
    module.task2_observer_current(context)
    module.expect(module.current(context_file) == context, 'SAME_FULL_AFTERSALE_REVIEW_SOURCE_AND_CONTEXT_THROUGH_CLOSE')
    module.verify_fulfillment_history(context)
    module.verify_fulfillment_removed(context)
    module.verify_aftersale_review_result(context, False)
    module.verify_aftersale_review_helper(context, 'authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    formal = module.load(Path(context['run_dir']) / 'evidence/paid-producer-formal-manifest.json')
    module.expect(module.paid_prerequisite_hashes(context, formal) == receipts['paid_prerequisites_sha256']
        and module.fulfillment_prerequisite_hashes(context) == receipts['fulfillment_prerequisites_sha256']
        and module.aftersale_review_public_hashes(context) == receipts['public_originals_sha256']
        and sorted(historical_created_ids(module, context)) == receipts['historical_absent_container_ids']
        and sha((Path(context['run_dir']) / 'manifest.json').read_bytes()) == receipts['manifest_sha256'], 'ALL_AFTERSALE_REVIEW_AND_REAL_TASK6_PUBLIC_ORIGINALS_RETAINED_EXACTLY')


def normal_stop(module, context, cid, role):
    evidence = Path(context['run_dir']) / 'evidence'
    module.collect(context, module.proof_expected(context, module.AFTERSALE_REVIEW['proof']), publish=False)
    before = context['initial_service_created'][role]
    fresh = module.inspect(context, cid, 'close-' + role + '-before-stop')
    module.owned(context, fresh, role)
    module.same_created(before, fresh)
    module.expect(fresh['status'] == 'running' and fresh['pid'] > 0, 'STOP_ONLY_ORIGINAL_RUNNING_SERVICE')
    announce('waiting_normal_stop_' + role, container_id=cid, no_timed_force=True)
    process = subprocess.Popen(['docker', 'stop', '--time', '-1', cid], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    module.expect(process.pid > 0, 'REAL_NATIVE_STOP_CLI_PID')
    STATE['waiting_native'] = {'native_pid': process.pid, 'container_id': cid, 'role': role}
    announce('native_stop_same_process_wait_' + role, native_pid=process.pid, container_id=cid, no_automatic_restart_or_retry=True)
    module.new(evidence / ('ms4-aftersale-review-normal-close-' + role + '-stop-created.json'),
               {'pid': process.pid, 'container_id': cid, 'role': role, 'time': -1, 'no_timed_force': True})
    stdout, stderr = process.communicate()
    returncode = process.returncode
    waited = process.wait()
    STATE['waiting_native'] = None
    announce('native_stop_cli_terminal_' + role, native_pid=process.pid, container_id=cid, native_exit=process.returncode)
    module.new(evidence / ('ms4-aftersale-review-normal-close-' + role + '-stop-terminal.json'),
               {'pid': process.pid, 'container_id': cid, 'native_exit': returncode, 'wait_returncode': waited,
                'stdout_bytes': len(stdout), 'stdout_sha256': sha(stdout),
                'stderr_bytes': len(stderr), 'stderr_sha256': sha(stderr)})
    module.expect(type(returncode) is int and returncode == 0 and type(waited) is int and waited == 0 and stderr == b'' and stdout in {
        (cid + '\n').encode(), ('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()},
        'NORMAL_STOP_NATIVE_ZERO_EMPTY_STDERR_ACTUAL_CID_STDOUT')
    after = terminal(module, context, before, role, 'close-' + role + '-after-stop')
    module.new(evidence / ('ms4-aftersale-review-normal-close-' + role + '-after-stop.json'), after)
    announce('normal_stop_verified_' + role, container_id=cid, native_exit=0, container_exit=0, pid=0)


def close(context_file):
    module = host_module()
    context = module.current(context_file)
    module.proof_ini_audit(context, coverage=True)
    module.task2_observer_current(context)
    STATE['context'] = context
    evidence = Path(context['run_dir']) / 'evidence'
    expected = module.proof_expected(context, module.AFTERSALE_REVIEW['proof'])
    base = module.base_roles(context)
    module.expect(len(expected) == 4 and set(expected.values()) == {module.AFTERSALE_REVIEW['role'], 'audit-ms5-paid-producer', 'audit-mysql', 'audit-redis'},
                  'ONLY_FOUR_CURRENT_AFTERSALE_REVIEW_FORMAL_ROLES')
    own = Path(__file__).read_bytes()
    module.new(evidence / 'ms4-aftersale-review-normal-close-attempt.json',
               {'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ONLY', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
                'close_source_path': Path(__file__).resolve().as_posix(), 'close_source_bytes': len(own), 'close_source_sha256': sha(own),
                'reviewed_host_sha256': HOST_SHA256, 'context_sha256': sha(Path(context_file).read_bytes()),
                 'freeze_sha256': context['freeze_sha256'],
                'original_expected': expected, 'operations_started_at_receipt_time': False})
    announce('normal_close_preflight', run_id=context['run_id'])
    module.collect(context, expected, publish=False)
    receipts = verify_receipts(module, context, expected)
    before = original_resources(module, context, expected)
    module.new(evidence / 'ms4-aftersale-review-normal-close-before.json', before | {'verified_receipts': receipts})
    announce('original_resources_and_cleanup_receipts_verified', containers=4, volumes=8, internal_network=1)
    for role in ['audit-mysql', 'audit-redis']:
        cid = next(cid for cid, actual_role in base.items() if actual_role == role)
        normal_stop(module, context, cid, role)
    module.collect(context, expected, publish=False)
    stopped = {cid: terminal(module, context,
                            context['initial_service_created'][role] if role in ['audit-mysql', 'audit-redis']
                            else module.bound_created(context, 'paid-producer' if role == module.PAID['role'] else module.AFTERSALE_REVIEW['proof'], role), role, 'close-all-normal-terminals')
               for cid, role in expected.items()}
    for cid, facts in stopped.items():
        role = expected[cid]
        module.persist_terminal(context, 'paid-producer' if role == module.PAID['role'] else module.AFTERSALE_REVIEW['proof'] if role == module.AFTERSALE_REVIEW['role'] else 'ms4-aftersale-review-normal-close-' + role, facts)
    module.new(evidence / 'ms4-aftersale-review-normal-close-all-stopped.json', {'containers': stopped, 'resources': original_resources(module, context, expected)})
    announce('removing_exact_four_normal_cids', containers=4, force=False)
    module.native(context, ['rm', *sorted(expected)], 'close-exact-four-nonforce-rm')
    empty = original_resources(module, context, set())
    module.expect(empty['network']['Containers'] == {}, 'ORIGINAL_NETWORK_NO_ENDPOINTS')
    module.new(evidence / 'ms4-aftersale-review-normal-close-before-network-volumes-rm.json', empty)
    announce('removing_original_empty_network', network_id=context['network_id'], force=False)
    module.native(context, ['network', 'rm', context['network_id']], 'close-exact-network-rm')
    module.global_consumers(context, set())
    global_before_volumes = independent_global_consumers(module, context, set())
    names = sorted(row['name'] for row in context['initial_volumes'])
    remaining = inventory(module, context)
    module.expect(remaining['container'] == {'project': [], 'run': []} and remaining['network'] == {'project': [], 'run': []}
                  and sorted(remaining['volume']['project']) == sorted(remaining['volume']['run']) == names,
                  'EXACT_EIGHT_VOLUMES_AFTER_NETWORK_REMOVE')
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    volume_rows = [json.loads(module.native(context, ['volume', 'inspect', '--format', template, name], 'close-final-native-volume'))
                   for name in names]
    module.expect(volume_rows == context['initial_volumes'], 'SAME_ORIGINAL_VOLUMES_IMMEDIATELY_BEFORE_REMOVE')
    module.new(evidence / 'ms4-aftersale-review-normal-close-before-eight-volumes-rm.json', {'inventory': remaining, 'volumes': volume_rows, 'global_consumers': global_before_volumes})
    announce('removing_exact_original_eight_volumes', volumes=8, force=False)
    module.native(context, ['volume', 'rm', *names], 'close-exact-eight-nonforce-volume-rm')
    final = inventory(module, context)
    module.expect(all(values == [] for labels in final.values() for values in labels.values()), 'PROJECT_RUN_THREE_CLASSES_ZERO')
    module.global_consumers(context, set())
    global_after_resources = independent_global_consumers(module, context, set())
    result = {'status': 'VERIFIED_MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ZERO_RESIDUAL', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
              'close_source_sha256': sha(own), 'native_exit': 0, 'final_inventory': final, 'global_consumers': global_after_resources,
              'schema': 1, 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ONLY', 'freeze_sha256': context['freeze_sha256'],
              'context_sha256': sha(Path(context_file).read_bytes()), 'reviewed_host_sha256': HOST_SHA256,
              'exact_nonforce_removed': {'containers': 4, 'networks': 1, 'volumes': 8},
              'ordinary_stop_and_exact_nonforce_remove': True, 'paid_producer': 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND',
              'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
              'fulfillment_writer': 'INTERNAL_ONLY_SOURCE_BOUND', 'aftersale_review': module.AFTERSALE_REVIEW['status'], 'customer_fulfillment_API_DOM': 'NOT RUN',
              'merchant_fulfillment_API_DOM': 'NOT RUN', 'platform_fulfillment_API_DOM': 'NOT RUN', 'worker': 'NOT RUN', 'historical_task6_task7': 'NOT RUN',
              'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    preserved_receipts(module, context, context_file, receipts)
    module.new(evidence / 'ms4-aftersale-review-normal-close-result.json', result)
    announce('aftersale_review_normal_close_verified_zero_residual', run_id=context['run_id'], native_exit=0, paid_producer='INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND', paid_API_DOM='NOT RUN')
    return result


def failure(error):
    value = {'status': 'INCOMPLETE_MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_STATE_PRESERVED', 'phase': STATE['phase'], 'class': type(error).__name__,
             'native_exit': 1, 'no_force_prune_down_restart_or_retry': True, 'business_acceptance': 'NOT RUN',
             'schema': 1, 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ONLY',
             'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
             'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    module, context = STATE['module'], STATE['context']
    if STATE['waiting_native'] is not None:
        value['waiting_same_native_process'] = STATE['waiting_native']
    if module is not None and context is not None:
        try:
            value['actual_inventory'] = inventory(module, context)
            original = module.proof_expected(context, module.AFTERSALE_REVIEW['proof'])
            present = set(value['actual_inventory']['container']['project']) | set(value['actual_inventory']['container']['run'])
            value['actual_original_container_states'] = {
                cid: module.inspect(context, cid, 'close-failure-original-state') for cid in original if cid in present}
        except Exception as observation:
            value['observation_unavailable_class'] = type(observation).__name__
        try:
            module.new(Path(context['run_dir']) / 'evidence' / 'ms4-aftersale-review-normal-close-failure.json', value)
        except Exception as receipt:
            value['failure_receipt_unavailable_class'] = type(receipt).__name__
    print(json.dumps(value, ensure_ascii=False), flush=True)



def worker_historical_created_ids(module, context):
    evidence = Path(context['run_dir']) / 'evidence'
    historical = set()
    for name in module.aftersale_review_public_names():
        if not name.endswith('-created.json') or name == 'paid-producer-created.json':
            continue
        value = module.load(evidence / name)
        module.expect(type(value) is dict and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
            and type(value['id']) is str and re.fullmatch('[a-f0-9]{64}', value['id'])
            and value['status'] == 'created' and type(value['pid']) is int and value['pid'] == 0,
            'COMPLETE_ACTUAL_HISTORICAL_CREATED_IDENTITY')
        historical.add(value['id'])
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
        ('register-worker-normal', 'audit-bootstrap'), ('authorize-worker-cleanup', 'audit-bootstrap')]
    for proof, role in historical_roles:
        path = evidence / (proof + '-created.json')
        if path.exists():
            value = module.bound_created(context, proof, role)
            historical.add(value['id'])
    return historical


def worker_independent_global_consumers(module, context, allowed):
    operations = []
    def query(args):
        result = subprocess.run(['docker', *args], capture_output=True)
        operations.append({'argv': args, 'native_exit': result.returncode, 'stdout_bytes': len(result.stdout),
            'stdout_sha256': sha(result.stdout), 'stderr_bytes': len(result.stderr), 'stderr_sha256': sha(result.stderr)})
        module.expect(type(result.returncode) is int and result.returncode == 0 and result.stderr == b'',
                      'PAID_CLOSE_INDEPENDENT_GLOBAL_NATIVE_ZERO')
        return result.stdout
    ids = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in ids),
                  'PAID_CLOSE_GLOBAL_COMPLETE_ACTUAL_CIDS')
    network_id = context['network_id']
    module.expect(type(network_id) is str and re.fullmatch('[a-f0-9]{64}', network_id)
                  and context['initial_network']['Id'] == network_id, 'PAID_CLOSE_ORIGINAL_FULL_NETWORK_ID')
    network_names = {network_id, context['initial_network']['Name']}
    volumes = {row['name'] for row in context['initial_volumes']}
    original = module.bound_created(context, 'paid-producer', 'audit-ms5-paid-producer')
    newnormal = module.bound_created(context, module.WORKER['proof'], module.WORKER['role'])
    targets = set(context['initial_service_ids'].values()) | {original['id'], newnormal['id']}
    historical = worker_historical_created_ids(module, context)
    module.expect(not historical.intersection(targets) and not historical.intersection(ids), 'CLOSE_ALL_HISTORICAL_CIDS_ACTUALLY_ABSENT')
    module.expect(not set(ids).intersection(targets - set(allowed)), 'CLOSE_REMOVED_CURRENT_CIDS_ACTUALLY_ABSENT')
    module.expect(len(targets) == 4 and len(volumes) == 8 and set(allowed) <= targets,
                  'WORKER_CLOSE_FOUR_CID_EIGHT_VOLUME_TARGETS')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    permitted = []
    for cid in ids:
        value = json.loads(query(['inspect', '--format', template, cid]))
        module.expect(type(value) is dict and set(value) == {'id', 'mounts', 'network_mode', 'networks'}
                      and value['id'] == cid and type(value['mounts']) is list
                      and all(type(row) is dict for row in value['mounts'])
                      and type(value['networks']) is dict and all(type(row) is dict for row in value['networks'].values())
                      and type(value['network_mode']) is str, 'PAID_CLOSE_COMPLETE_GLOBAL_NATIVE_FIELDS')
        mode = value['network_mode']
        consumes = (any(row.get('Name') in volumes for row in value['mounts'])
            or mode in network_names or bool(set(value['networks']).intersection(network_names))
            or any(row.get('NetworkID') == network_id for row in value['networks'].values())
            or (mode.startswith('container:') and mode[len('container:'):] in targets | historical))
        if consumes:
            module.expect(cid in allowed, 'PAID_CLOSE_NO_UNKNOWN_ORIGINAL_RESOURCE_DEPENDENCY')
            permitted.append(cid)
    final = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(final) == len(set(final)) and set(final) == set(ids), 'PAID_CLOSE_SAME_GLOBAL_CID_SNAPSHOT')
    return {'inspected_container_count': len(ids), 'operations': operations,
            'original_container_ids': sorted(targets), 'allowed_original_consumers': sorted(permitted),
            'unexpected_original_resource_consumers': 0}


def worker_original_resources(module, context, allow_containers):
    actual = inventory(module, context)
    module.expect(set(actual['container']['project']) == set(actual['container']['run']) == set(allow_containers),
                  'EXACT_REMAINING_KNOWN_CONTAINERS')
    module.expect(actual['network']['project'] == actual['network']['run'] == [context['network_id']],
                  'EXACT_ORIGINAL_NETWORK_ONLY')
    names = sorted(row['name'] for row in context['initial_volumes'])
    module.expect(names == sorted(context['project'] + '_' + suffix for suffix in ['app-secrets', 'bootstrap-secrets', 'fixture-secrets',
        'mysql-data', 'redis-data', 'redis-secrets', 'aftersale-evidence', 'customer-form-evidence']), 'EXACT_ORIGINAL_EIGHT_NAMED_VOLUMES')
    module.expect(sorted(actual['volume']['project']) == sorted(actual['volume']['run']) == names,
                  'EXACT_ORIGINAL_EIGHT_VOLUMES_ONLY')
    template = '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'
    network = json.loads(module.native(context, ['network', 'inspect', '--format', template, context['network_id']], 'close-native-network'))
    module.expect({key: network[key] for key in ['Id', 'Name', 'Internal', 'Labels']} == context['initial_network'],
                  'SAME_ORIGINAL_NETWORK_IDENTITY')
    rows = []
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    for name in names:
        rows.append(json.loads(module.native(context, ['volume', 'inspect', '--format', template, name], 'close-native-volume')))
    module.expect(rows == context['initial_volumes'], 'SAME_ORIGINAL_EIGHT_VOLUME_IDENTITIES')
    module.global_consumers(context, set(allow_containers))
    return {'inventory': actual, 'network': network, 'volumes': rows,
            'global_consumers': worker_independent_global_consumers(module, context, set(allow_containers))}


def worker_verify_receipts(module, context, expected):
    evidence = Path(context['run_dir']) / 'evidence'
    module.verify_fulfillment_history(context)
    module.verify_fulfillment_removed(context)
    module.verify_aftersale_review_history(context)
    module.verify_aftersale_review_removed(context)
    module.verify_worker_result(context, True)
    module.verify_worker_helper(context, 'authorize-worker-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    paid = terminal(module, context, module.bound_created(context, 'paid-producer', module.PAID['role']), module.PAID['role'], 'worker-close-paid-normal-required')
    worker = terminal(module, context, module.bound_created(context, module.WORKER['proof'], module.WORKER['role']),
        module.WORKER['role'], 'worker-close-worker-normal-required')
    module.expect(set(expected) == {paid['id'], worker['id'], *context['initial_service_ids'].values()}, 'EXACT_FOUR_CURRENT_WORKER_CIDS')
    historical = worker_historical_created_ids(module, context)
    module.expect(not historical.intersection(expected) and not historical.intersection(module.active(context)),
        'ALL_HISTORICAL_NORMAL_EARLY_TASK6_AND_HELPER_CIDS_ACTUALLY_ABSENT')
    values = module.worker_public_hashes(context)
    formal = module.load(evidence / 'paid-producer-formal-manifest.json')
    return {'paid_prerequisites_sha256': module.paid_prerequisite_hashes(context, formal),
        'fulfillment_prerequisites_sha256': module.fulfillment_prerequisite_hashes(context),
        'worker_prerequisites_sha256': module.worker_prerequisite_hashes(context), 'paid': paid, 'worker': worker,
        'paid_result_sha256': values['paid-producer-result.json'], 'fulfillment_result_sha256': values['ms4-fulfillment-normal.json'],
        'aftersale_review_result_sha256': values[module.AFTERSALE_REVIEW['result']], 'worker_result_sha256': values[module.WORKER['result']],
        'authorize_created_sha256': values['authorize-worker-cleanup-created.json'],
        'authorize_terminal_sha256': values['authorize-worker-cleanup-terminal.json'],
        'authorize_result_sha256': values['authorize-worker-cleanup-result.json'], 'public_originals_sha256': values,
        'historical_absent_container_ids': sorted(historical),
        'manifest_sha256': sha((Path(context['run_dir']) / 'manifest.json').read_bytes())}


def worker_preserved_receipts(module, context, context_file, receipts):
    module.task2_observer_current(context)
    module.expect(module.current(context_file) == context, 'SAME_FULL_WORKER_SOURCE_AND_CONTEXT_THROUGH_CLOSE')
    module.verify_fulfillment_history(context)
    module.verify_fulfillment_removed(context)
    module.verify_aftersale_review_history(context)
    module.verify_aftersale_review_removed(context)
    module.verify_worker_result(context, False)
    module.verify_worker_helper(context, 'authorize-worker-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    formal = module.load(Path(context['run_dir']) / 'evidence/paid-producer-formal-manifest.json')
    module.expect(module.paid_prerequisite_hashes(context, formal) == receipts['paid_prerequisites_sha256']
        and module.fulfillment_prerequisite_hashes(context) == receipts['fulfillment_prerequisites_sha256']
        and module.worker_prerequisite_hashes(context) == receipts['worker_prerequisites_sha256']
        and module.worker_public_hashes(context) == receipts['public_originals_sha256']
        and sorted(worker_historical_created_ids(module, context)) == receipts['historical_absent_container_ids']
        and sha((Path(context['run_dir']) / 'manifest.json').read_bytes()) == receipts['manifest_sha256'],
        'ALL_WORKER_AND_COMPLETE_OLD_NORMAL_TASK6_PAID_PUBLIC_ORIGINALS_RETAINED_EXACTLY')


def worker_normal_stop(module, context, cid, role):
    evidence = Path(context['run_dir']) / 'evidence'
    module.collect(context, module.proof_expected(context, module.WORKER['proof']), publish=False)
    before = context['initial_service_created'][role]
    fresh = module.inspect(context, cid, 'close-' + role + '-before-stop')
    module.owned(context, fresh, role)
    module.same_created(before, fresh)
    module.expect(fresh['status'] == 'running' and fresh['pid'] > 0, 'STOP_ONLY_ORIGINAL_RUNNING_SERVICE')
    announce('waiting_normal_stop_' + role, container_id=cid, no_timed_force=True)
    process = subprocess.Popen(['docker', 'stop', '--time', '-1', cid], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    module.expect(process.pid > 0, 'REAL_NATIVE_STOP_CLI_PID')
    STATE['waiting_native'] = {'native_pid': process.pid, 'container_id': cid, 'role': role}
    announce('native_stop_same_process_wait_' + role, native_pid=process.pid, container_id=cid, no_automatic_restart_or_retry=True)
    module.new(evidence / ('ms4-worker-normal-close-' + role + '-stop-created.json'),
               {'pid': process.pid, 'container_id': cid, 'role': role, 'time': -1, 'no_timed_force': True})
    stdout, stderr = process.communicate()
    returncode = process.returncode
    waited = process.wait()
    STATE['waiting_native'] = None
    announce('native_stop_cli_terminal_' + role, native_pid=process.pid, container_id=cid, native_exit=process.returncode)
    module.new(evidence / ('ms4-worker-normal-close-' + role + '-stop-terminal.json'),
               {'pid': process.pid, 'container_id': cid, 'native_exit': returncode, 'wait_returncode': waited,
                'stdout_bytes': len(stdout), 'stdout_sha256': sha(stdout),
                'stderr_bytes': len(stderr), 'stderr_sha256': sha(stderr)})
    module.expect(type(returncode) is int and returncode == 0 and type(waited) is int and waited == 0 and stderr == b'' and stdout in {
        (cid + '\n').encode(), ('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()},
        'NORMAL_STOP_NATIVE_ZERO_EMPTY_STDERR_ACTUAL_CID_STDOUT')
    after = terminal(module, context, before, role, 'close-' + role + '-after-stop')
    module.new(evidence / ('ms4-worker-normal-close-' + role + '-after-stop.json'), after)
    announce('normal_stop_verified_' + role, container_id=cid, native_exit=0, container_exit=0, pid=0)


def worker_close(context_file):
    module = host_module()
    context = module.current(context_file)
    module.proof_ini_audit(context, coverage=True)
    module.task2_observer_current(context)
    STATE['context'] = context
    evidence = Path(context['run_dir']) / 'evidence'
    expected = module.proof_expected(context, module.WORKER['proof'])
    base = module.base_roles(context)
    module.expect(len(expected) == 4 and set(expected.values()) == {module.WORKER['role'], 'audit-ms5-paid-producer', 'audit-mysql', 'audit-redis'},
                  'ONLY_FOUR_CURRENT_WORKER_FORMAL_ROLES')
    own = Path(__file__).read_bytes()
    module.new(evidence / 'ms4-worker-normal-close-attempt.json',
               {'scope': 'MS4_WORKER_NORMAL_CLOSE_ONLY', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
                'close_source_path': Path(__file__).resolve().as_posix(), 'close_source_bytes': len(own), 'close_source_sha256': sha(own),
                'reviewed_host_sha256': HOST_SHA256, 'context_sha256': sha(Path(context_file).read_bytes()),
                 'freeze_sha256': context['freeze_sha256'],
                'original_expected': expected, 'operations_started_at_receipt_time': False})
    announce('normal_close_preflight', run_id=context['run_id'])
    module.collect(context, expected, publish=False)
    receipts = worker_verify_receipts(module, context, expected)
    before = worker_original_resources(module, context, expected)
    module.new(evidence / 'ms4-worker-normal-close-before.json', before | {'verified_receipts': receipts})
    announce('original_resources_and_cleanup_receipts_verified', containers=4, volumes=8, internal_network=1)
    for role in ['audit-mysql', 'audit-redis']:
        cid = next(cid for cid, actual_role in base.items() if actual_role == role)
        worker_normal_stop(module, context, cid, role)
    module.collect(context, expected, publish=False)
    stopped = {cid: terminal(module, context,
                            context['initial_service_created'][role] if role in ['audit-mysql', 'audit-redis']
                            else module.bound_created(context, 'paid-producer' if role == module.PAID['role'] else module.WORKER['proof'], role), role, 'close-all-normal-terminals')
               for cid, role in expected.items()}
    for cid, facts in stopped.items():
        role = expected[cid]
        module.persist_terminal(context, 'paid-producer' if role == module.PAID['role'] else module.WORKER['proof'] if role == module.WORKER['role'] else 'ms4-worker-normal-close-' + role, facts)
    module.new(evidence / 'ms4-worker-normal-close-all-stopped.json', {'containers': stopped, 'resources': worker_original_resources(module, context, expected)})
    announce('removing_exact_four_normal_cids', containers=4, force=False)
    module.native(context, ['rm', *sorted(expected)], 'close-exact-four-nonforce-rm')
    empty = worker_original_resources(module, context, set())
    module.expect(empty['network']['Containers'] == {}, 'ORIGINAL_NETWORK_NO_ENDPOINTS')
    module.new(evidence / 'ms4-worker-normal-close-before-network-volumes-rm.json', empty)
    announce('removing_original_empty_network', network_id=context['network_id'], force=False)
    module.native(context, ['network', 'rm', context['network_id']], 'close-exact-network-rm')
    module.global_consumers(context, set())
    global_before_volumes = worker_independent_global_consumers(module, context, set())
    names = sorted(row['name'] for row in context['initial_volumes'])
    remaining = inventory(module, context)
    module.expect(remaining['container'] == {'project': [], 'run': []} and remaining['network'] == {'project': [], 'run': []}
                  and sorted(remaining['volume']['project']) == sorted(remaining['volume']['run']) == names,
                  'EXACT_EIGHT_VOLUMES_AFTER_NETWORK_REMOVE')
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    volume_rows = [json.loads(module.native(context, ['volume', 'inspect', '--format', template, name], 'close-final-native-volume'))
                   for name in names]
    module.expect(volume_rows == context['initial_volumes'], 'SAME_ORIGINAL_VOLUMES_IMMEDIATELY_BEFORE_REMOVE')
    module.new(evidence / 'ms4-worker-normal-close-before-eight-volumes-rm.json', {'inventory': remaining, 'volumes': volume_rows, 'global_consumers': global_before_volumes})
    announce('removing_exact_original_eight_volumes', volumes=8, force=False)
    module.native(context, ['volume', 'rm', *names], 'close-exact-eight-nonforce-volume-rm')
    final = inventory(module, context)
    module.expect(all(values == [] for labels in final.values() for values in labels.values()), 'PROJECT_RUN_THREE_CLASSES_ZERO')
    module.global_consumers(context, set())
    global_after_resources = worker_independent_global_consumers(module, context, set())
    result = {'status': 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
              'close_source_sha256': sha(own), 'native_exit': 0, 'final_inventory': final, 'global_consumers': global_after_resources,
              'schema': 1, 'scope': 'MS4_WORKER_NORMAL_CLOSE_ONLY', 'freeze_sha256': context['freeze_sha256'],
              'context_sha256': sha(Path(context_file).read_bytes()), 'reviewed_host_sha256': HOST_SHA256,
              'exact_nonforce_removed': {'containers': 4, 'networks': 1, 'volumes': 8},
              'ordinary_stop_and_exact_nonforce_remove': True, 'paid_producer': 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND',
              'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
              'fulfillment_writer': 'INTERNAL_ONLY_SOURCE_BOUND', 'aftersale_review': module.AFTERSALE_REVIEW['status'], 'customer_fulfillment_API_DOM': 'NOT RUN',
              'merchant_fulfillment_API_DOM': 'NOT RUN', 'platform_fulfillment_API_DOM': 'NOT RUN', 'worker': module.WORKER['status'], 'historical_task6_task7': 'NOT RUN',
              'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    worker_preserved_receipts(module, context, context_file, receipts)
    module.new(evidence / 'ms4-worker-normal-close-result.json', result)
    announce('worker_normal_close_verified_zero_residual', run_id=context['run_id'], native_exit=0, paid_producer='INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND', paid_API_DOM='NOT RUN')
    return result


def worker_failure(error):
    value = {'status': 'INCOMPLETE_MS4_WORKER_NORMAL_CLOSE_STATE_PRESERVED', 'phase': STATE['phase'], 'class': type(error).__name__,
             'native_exit': 1, 'no_force_prune_down_restart_or_retry': True, 'business_acceptance': 'NOT RUN',
             'schema': 1, 'scope': 'MS4_WORKER_NORMAL_CLOSE_ONLY',
             'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
             'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    module, context = STATE['module'], STATE['context']
    if STATE['waiting_native'] is not None:
        value['waiting_same_native_process'] = STATE['waiting_native']
    if module is not None and context is not None:
        try:
            value['actual_inventory'] = inventory(module, context)
            original = module.proof_expected(context, module.WORKER['proof'])
            present = set(value['actual_inventory']['container']['project']) | set(value['actual_inventory']['container']['run'])
            value['actual_original_container_states'] = {
                cid: module.inspect(context, cid, 'close-failure-original-state') for cid in original if cid in present}
        except Exception as observation:
            value['observation_unavailable_class'] = type(observation).__name__
        try:
            module.new(Path(context['run_dir']) / 'evidence' / 'ms4-worker-normal-close-failure.json', value)
        except Exception as receipt:
            value['failure_receipt_unavailable_class'] = type(receipt).__name__
    print(json.dumps(value, ensure_ascii=False), flush=True)



# Explicit UI branch. Original normal/Worker functions above remain unchanged.
UI_HOST_SHA256 = '54af1df114c8f67d36c80956598a22d1844020e8e7e3c735018560682264f0d8'
UI_CLOSE_SCOPE = 'MS4_UI_NORMAL_CLOSE_ONLY'


# Exact per-case schema/probe data is the separately sealed current final Source contract.
# Unknown mapping/relation/type rejects; actual eight-member auxiliary adoption remains gated.
# Independent close-only UI evidence consumer. No shared verifier or zero import.
# None is an intentional literal adoption gate, not a nullable success path.
UI_BUSINESS_AUX_PINS = {'bytes': 1258, 'sha256': '0b69df3a61dc36002462c0abcf87197e4c7de11f1635af4065c560f1837db8ad'}
UI_BUSINESS_STEPS = ('login-customer','login-merchant','login-platform','n1-initial-read',
    'n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read',
    'n1-merchant-approve','ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1',
    'receive-q1-all-eligible','n2-public-image-upload','n2-review-submit','n2-pending-public-read',
    'n2-platform-moderate','n2-merchant-reply','n2-public-review-read','n2-public-image-read')
UI_BUSINESS_ROLES = ('customer','merchant','platform','customer','customer','customer','customer',
    'merchant','merchant','customer','customer','merchant','customer','customer','customer','public',
    'platform','merchant','public','public')
UI_BUSINESS_ARTIFACTS = ('compose.yml','interface-v1.json','ms4-host.py','ms4-initialize.php',
    'normal-close-ms4-fulfillment.py','README.md','seed-source-inverse.json','source-derivation.json',
    'verify-zero-ms4-fulfillment.py','ms4-ui-nginx.conf','ms4-ui-public-copy.php','ms4-ui-copy.php',
    'ms4-order.png','manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json',
    'manifests/ui-admin-dist.json','manifests/ui-public-copy-profile.json')
UI_BUSINESS_AUX_MEMBERS = ('reader/ms4-ui-private-reader.mjs','ms4-ui-private-caller.mjs',
    'ms4-ui-browser-close.mjs','ms4-ui-business-producer.mjs','ms4-ui-window-native-bridge.mjs',
    'ms4-ui-network-capture.mjs','ms4-ui-dom-producer.mjs','ui-business-consumer-contract.json')
UI_BUSINESS_PHP = ('help/audit-regressions/isolated/ms4-ui-backend.php',
    'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php',
    'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php')


def _uib_need(ok, code):
    if not ok:
        raise RuntimeError('UI_BUSINESS_' + code)


def _uib_keys(value, names):
    _uib_need(type(value) is dict and set(value) == set(names), 'EXACT_KEYS')


def _uib_int(value, minimum=0, maximum=9007199254740991):
    _uib_need(type(value) is int and minimum <= value <= maximum, 'EXACT_INTEGER')
    return value


def _uib_hex(value, size=64):
    _uib_need(type(value) is str and re.fullmatch('[a-f0-9]{' + str(size) + '}', value) is not None, 'LOWERHEX')
    return value


def _uib_num(value, positive=False):
    import math
    _uib_need(type(value) in (int, float) and math.isfinite(value) and (not positive or value > 0), 'FINITE_NUMBER')
    return value


def _uib_canon(value):
    # JSON payloads here are generated by the selected native/caller sources.
    # Reject unsafe integers/nonfinite floats; never use repr or default=str on objects.
    import math
    from decimal import Decimal
    def emit(v, depth):
        _uib_need(depth <= 64, 'JSON_DEPTH')
        if v is None: return 'null'
        if type(v) is bool: return 'true' if v else 'false'
        if type(v) is str: return json.dumps(v, ensure_ascii=False, separators=(',', ':')).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
        if type(v) is int:
            _uib_need(abs(v) <= 9007199254740991, 'JSON_SAFE_INTEGER')
            return str(v)
        if type(v) is float:
            _uib_need(math.isfinite(v), 'JSON_FINITE')
            if v == 0: return '0'
            text = repr(v).lower()
            magnitude = abs(v)
            if 1e-6 <= magnitude < 1e21:
                text = format(Decimal(text), 'f')
                if '.' in text: text = text.rstrip('0').rstrip('.')
                return text
            mantissa, exponent = text.split('e') if 'e' in text else (text, '0')
            if mantissa.endswith('.0'): mantissa = mantissa[:-2]
            n = int(exponent)
            return mantissa + 'e' + ('+' if n >= 0 else '-') + str(abs(n))
        if type(v) is list: return '[' + ','.join(emit(x, depth+1) for x in v) + ']'
        _uib_need(type(v) is dict and all(type(k) is str for k in v), 'JSON_RECORD_ONLY')
        return '{' + ','.join(emit(k, depth+1)+':'+emit(v[k], depth+1) for k in sorted(v,key=lambda key:key.encode('utf-16-be','surrogatepass'))) + '}'
    return emit(value, 0).encode('utf8')


def _uib_same(a, b):
    return _uib_canon(a) == _uib_canon(b)


def _uib_json(raw):
    def pairs(rows):
        value = {}
        for k, v in rows:
            _uib_need(k not in value, 'DUPLICATE_JSON_KEY')
            value[k] = v
        return value
    def bad(value):
        raise RuntimeError('UI_BUSINESS_NONFINITE_JSON')
    value = json.loads(raw.decode('utf8', errors='strict'), object_pairs_hook=pairs, parse_constant=bad)
    _uib_canon(value)
    return value


def _uib_pin(raw):
    return {'bytes':len(raw), 'sha256':sha(raw)}


def _uib_desc(value, path=None, empty=False):
    _uib_keys(value, ('path','bytes','sha256'))
    _uib_need(type(value['path']) is str and (path is None or value['path'] == path), 'FIXED_DESCRIPTOR_PATH')
    _uib_int(value['bytes'], 0 if empty else 1, 268435456)
    _uib_hex(value['sha256'])
    return value


def _uib_stable(path, reads, maximum=268435456):
    import stat
    path = Path(path)
    _uib_need(path.is_absolute(), 'ABSOLUTE_CANONICAL_SOURCE')
    ancestors = [path, *path.parents]
    before = []
    for n, part in enumerate(ancestors):
        st = part.lstat()
        _uib_need(not part.is_symlink() and not getattr(part, 'is_junction', lambda:False)()
            and not (getattr(st, 'st_file_attributes', 0) & 0x400), 'NO_LINK_JUNCTION_REPARSE')
        _uib_need(stat.S_ISREG(st.st_mode) if n == 0 else stat.S_ISDIR(st.st_mode), 'ONLY_ORDINARY_PATH')
        before.append(st)
    _uib_need(before[0].st_nlink == 1 and 0 <= before[0].st_size <= maximum, 'BOUNDED_SINGLE_LINK')
    raw = ui_close_file(path)
    fields = ('st_dev','st_ino','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns','st_file_attributes')
    for part, old in zip(ancestors, before):
        fresh = part.lstat()
        _uib_need(all(getattr(old,k,None) == getattr(fresh,k,None) for k in fields), 'COMPLETE_PATH_READ_STABLE')
    key = str(path)
    signature = {'pin':_uib_pin(raw), 'stat':[getattr(before[0],k,None) for k in fields]}
    if key in reads: _uib_need(reads[key] == signature, 'REPEATED_ORIGINAL_IDENTICAL')
    reads[key] = signature
    return raw


def _uib_leaf(root, descriptor, name, reads, canonical=True, empty=False):
    _uib_need(re.fullmatch('[a-z0-9.-]+', name) is not None, 'FIXED_LOGICAL_LEAF')
    _uib_desc(descriptor, '/run/audit/evidence/'+name, empty)
    raw = _uib_stable(root/'evidence'/name, reads)
    _uib_need(_uib_pin(raw) == {'bytes':descriptor['bytes'],'sha256':descriptor['sha256']}, 'ORIGINAL_DESCRIPTOR_BYTES')
    if not canonical: return raw
    value = _uib_json(raw)
    _uib_need(raw == _uib_canon(value)+b'\n', 'CANONICAL_MATERIALIZED_PAYLOAD')
    return value


def _uib_tree(value):
    if value is None: return
    _uib_need(type(value) is dict and '' in value, 'COMPLETE_FILE_TREE')
    for path, row in value.items():
        _uib_need(type(path) is str and not any(t in path for t in ('\0','\\'))
            and not path.startswith('/') and all(p not in ('.','..') for p in path.split('/')), 'SAFE_NATIVE_RELATIVE_TREE')
        base = ['kind','dev','ino','mode','uid','gid','nlink']
        _uib_keys(row, base + (['bytes','sha256','mtime','ctime'] if row.get('kind') == 'file' else []))
        _uib_need(row['kind'] in ('directory','file'), 'TREE_ENTRY_KIND')
        for field in base[1:]: _uib_int(row[field])
        _uib_need(row['mode'] <= 4095 and row['nlink'] > 0, 'TREE_MODE_LINKS')
        if row['kind'] == 'file':
            _uib_need(row['nlink'] == 1, 'TREE_SINGLE_FILE')
            _uib_int(row['bytes']); _uib_hex(row['sha256'])
            _uib_int(row['mtime'], -9007199254740991); _uib_int(row['ctime'], -9007199254740991)
    _uib_need(value['']['kind'] in ('directory','file'), 'ACTUAL_NATIVE_FILE_OR_DIRECTORY_ROOT')


def _uib_projection(value):
    _uib_keys(value, ('encoding','rows','sha256'))
    _uib_need(value['encoding'] == 'php-serialize-row-sha256-sorted-multiset-v1', 'ROW_PROJECTION_ENCODING')
    _uib_int(value['rows']); _uib_hex(value['sha256'])
    if value['rows'] == 0: _uib_need(value['sha256'] == sha(b''), 'EMPTY_PROJECTION_REAL_HASH')


def _uib_ledger(value, binding, index, point):
    _uib_keys(value, ('schema','scope','binding','index','point','database','redis','files'))
    _uib_need(type(value['schema']) is int and value['schema'] == 1
        and value['scope'] == 'MS4_UI_COMPLETE_NATIVE_WINDOW_LEDGER' and _uib_same(value['binding'],binding)
        and type(value['index']) is int and value['index'] == index and value['point'] == point, 'LEDGER_BINDING_POINT')
    database = value['database']; _uib_keys(database, ('names','namespace_sha256','schema_modes_sha256','tables'))
    names = database['names']
    _uib_need(type(names) is list and all(type(n) is str and re.fullmatch('[A-Za-z0-9_]+',n) for n in names)
        and names == sorted(set(names)) and type(database['tables']) is dict
        and set(database['tables']) == set(names), 'FULL_NATIVE_TABLE_NAMESPACE')
    _uib_hex(database['namespace_sha256']); _uib_hex(database['schema_modes_sha256'])
    for table in database['tables'].values():
        _uib_keys(table, ('metadata_sha256','ddl_sha256','projected_columns_sha256','projection'))
        for k in ('metadata_sha256','ddl_sha256','projected_columns_sha256'): _uib_hex(table[k])
        _uib_projection(table['projection'])
    redis = value['redis']; _uib_keys(redis, ('time_ms','keys'))
    _uib_need(type(redis['time_ms']) is str and re.fullmatch('0|[1-9][0-9]*',redis['time_ms']) and type(redis['keys']) is dict, 'NATIVE_REDIS_TIME_KEYS')
    for key, row in redis['keys'].items():
        _uib_hex(key); _uib_keys(row, ('type','sha256','expires','dump_sha256'))
        _uib_need(row['type'] in ('string','hash','set','list','zset','stream'), 'NATIVE_REDIS_TYPE')
        _uib_hex(row['sha256']); _uib_hex(row['dump_sha256']); _uib_int(row['expires'], -1)
        _uib_need(row['expires'] == -1 or row['expires'] > 0, 'NATIVE_REDIS_ABSOLUTE_EXPIRY')
    _uib_keys(value['files'], ('roots',))
    roots = ('/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence','/workspace/crmeb/public',
        '/workspace/crmeb/runtime','/tmp/ms4-fulfillment-normal-'+binding['run_id'],
        '/run/audit-ui-control','/run/audit-app/config.json','/tmp')
    _uib_keys(value['files']['roots'], roots)
    for tree in value['files']['roots'].values(): _uib_tree(tree)


def _uib_same_state(a, b):
    # Native TIME is an observation, not a cache/value/type/expiry exception.
    return (_uib_same(a['database'],b['database']) and _uib_same(a['files'],b['files'])
        and _uib_same(a['redis']['keys'],b['redis']['keys'])
        and int(b['redis']['time_ms']) >= int(a['redis']['time_ms']))


def _uib_browser_close(value, attempt, binding):
    _uib_keys(value, ('schema','scope','status','binding','attempt','baseline','owned','observations','viewport',
        'references','producer_source','authentication','business_DOM','full_acceptance'))
    _uib_need(value['schema'] == 1 and type(value['schema']) is int
        and value['scope'] == 'MS4_UI_BROWSER_NORMAL_CLOSE_ONLY'
        and value['status'] == 'VERIFIED_MS4_UI_OWNED_TABS_CLOSED_DEFAULT_VIEWPORT'
        and _uib_same(value['binding'],binding) and value['authentication'] == 'NOT_PROVEN'
        and value['business_DOM'] == 'NOT_VERIFIED_BY_BROWSER_CLOSE' and value['full_acceptance'] == 'INCOMPLETE', 'BROWSER_CLOSE_NOT_AUTHENTICATION')
    roles = ('customer','merchant','platform')
    _uib_need(type(value['owned']) is list and len(value['owned']) == 3, 'THREE_OWNED_TABS')
    owned = {}
    for row in value['owned']:
        _uib_keys(row, ('role','id_sha256')); _uib_hex(row['id_sha256'])
        _uib_need(row['role'] in roles and row['role'] not in owned, 'OWNED_ROLES_UNIQUE')
        owned[row['role']] = row['id_sha256']
    _uib_need(len(set(owned.values())) == 3, 'OWNED_IDS_UNIQUE')
    def tabs(rows):
        _uib_need(type(rows) is list, 'ACTUAL_TAB_LIST')
        found = {}
        for row in rows:
            _uib_keys(row, ('id_sha256','url_kind','url_sha256')); _uib_hex(row['id_sha256'])
            _uib_need(row['url_kind'] in ('undefined','string'), 'TAB_URL_KIND')
            if row['url_kind'] == 'undefined': _uib_need(row['url_sha256'] is None, 'UNDEFINED_URL_NULL')
            else: _uib_hex(row['url_sha256'])
            _uib_need(row['id_sha256'] not in found, 'TAB_ID_SINGLE')
            found[row['id_sha256']] = row
        _uib_need(list(found) == sorted(found), 'SORTED_COMPLETE_TABS')
        return found
    baseline = tabs(value['baseline'])
    _uib_need(not set(baseline).intersection(owned.values()), 'ONLY_NEW_IDS_OWNED')
    events = value['observations']; _uib_need(type(events) is list and len(events) == 12, 'TWELVE_ACTUAL_BROWSER_OBSERVATIONS')
    maps = []
    for index, row in enumerate(events):
        _uib_keys(row, ('index','event','tabs')); _uib_need(type(row['index']) is int and row['index'] == index, 'BROWSER_EVENT_INDEX')
        current = tabs(row['tabs'])
        _uib_need(all(current.get(k) == r for k,r in baseline.items()), 'PREEXISTING_IDS_URLS_PRESERVED')
        maps.append(current)
    _uib_need(events[0] == {'index':0,'event':'baseline','tabs':value['baseline']}, 'ACTUAL_BASELINE')
    available = set(baseline); created_roles = set()
    for index in (1,3,5):
        left, right = events[index:index+2]
        _uib_need(type(left['event']) is str and left['event'].startswith('before_new_'), 'CREATION_EVENT_ORDER')
        role = left['event'][11:]
        _uib_need(role in roles and role not in created_roles and right['event'] == 'after_new_'+role, 'ONE_NEW_PER_ROLE')
        _uib_need(set(maps[index]) == available and owned[role] not in available, 'ONLY_NEW_ID_ADDED')
        available.add(owned[role]); created_roles.add(role)
        _uib_need(set(maps[index+1]) == available, 'EXACT_AFTER_NEW_TAB_SET')
    _uib_need(created_roles == set(roles) and events[7]['event'] == 'before_close' and set(maps[7]) == available, 'ACTUAL_BEFORE_CLOSE')
    for index, role in enumerate(reversed(roles), 8):
        _uib_need(events[index]['event'] == 'after_close_'+role, 'REVERSE_NORMAL_CLOSE_ORDER')
        available.remove(owned[role]); _uib_need(set(maps[index]) == available, 'ONLY_OWNED_ID_REMOVED')
    _uib_need(events[11]['event'] == 'after_viewport_reset' and maps[11] == baseline, 'ACTUAL_BASELINE_AFTER_RESET')
    _uib_keys(value['viewport'], ('sets','reset')); widths = set()
    _uib_need(type(value['viewport']['sets']) is list, 'VIEWPORT_CALLS')
    for row in value['viewport']['sets']:
        _uib_keys(row, ('width','height','api_returned')); _uib_int(row['width']); _uib_int(row['height'],1,16384)
        _uib_need(row['width'] in (320,390,1440) and row['api_returned'] is True, 'ACTUAL_SET_RETURN')
        widths.add(row['width'])
    _uib_need(widths == {320,390,1440}, 'THREE_ACTUAL_SET_WIDTHS')
    _uib_need(_uib_same(value['viewport']['reset'], {'attempted':True,'api_returned':True,'restoration':'DEFAULT_ONLY_PREVIOUS_OVERRIDE_UNKNOWN'}), 'ONE_DEFAULT_RESET_BOUNDARY')
    _uib_keys(value['references'], ('caller_forget','companion_references_released','immutable_JS_strings_securely_erased','external_references'))
    refs = value['references']
    _uib_need(_uib_same(refs['caller_forget'], {'private_references_released':True,'immutable_JS_strings_securely_erased':False,'browser_close':'NOT PROVEN','business_DOM':'NOT RUN'})
        and refs['companion_references_released'] is True and refs['immutable_JS_strings_securely_erased'] is False
        and refs['external_references'] == 'NOT_ATTESTED', 'REFERENCE_RELEASE_NOT_SECURE_ERASURE')
    _uib_desc(value['attempt'], '/run/audit/evidence/ui-browser-normal-close-attempt.json')
    _uib_desc(value['producer_source'], 'browser-v1/ms4-ui-browser-close.mjs')
    _uib_need({k:value['producer_source'][k] for k in ('bytes','sha256')} == binding['source_closure']['browser_close'], 'BROWSER_SOURCE_JOIN')
    _uib_keys(attempt, ('schema','scope','binding','baseline','owned','before_close','producer_source'))
    _uib_need(type(attempt['schema']) is int and attempt['schema'] == 1
        and attempt['scope'] == 'MS4_UI_BROWSER_SINGLE_NORMAL_CLOSE_ATTEMPT'
        and _uib_same(attempt['binding'],binding) and _uib_same(attempt['baseline'],value['baseline'])
        and _uib_same(attempt['owned'],value['owned']) and _uib_same(attempt['before_close'],events[7])
        and _uib_same(attempt['producer_source'],value['producer_source']), 'ORIGINAL_ATTEMPT_RECEIPT_JOIN')
    return owned


def _uib_native(root, descriptors, binding, reads):
    _uib_keys(descriptors, ('created','terminal','stdout','stderr'))
    names = {'created':'ui-window-observer-created.json','terminal':'ui-window-observer-terminal.json',
        'stdout':'ui-window-observer.stdout','stderr':'ui-window-observer.stderr'}
    raws = {k:_uib_leaf(root,descriptors[k],n,reads,False,k == 'stderr') for k,n in names.items()}
    created = _uib_json(raws['created']); terminal = _uib_json(raws['terminal'])
    _uib_keys(created, ('label','executable','argv','pid'))
    argv = ['exec','-i',binding['backend_cid'],'php','/workspace/help/audit-regressions/isolated/ms4-ui-backend.php','observe']
    _uib_need(created['label'] == 'ui-window-observer' and created['executable'] == 'docker'
        and created['argv'] == argv, 'NODE_ACTUAL_EXEC_ARGV')
    _uib_int(created['pid'],1)
    _uib_keys(terminal, ('schema','scope','executable','argv','pid','close_code','close_signal','stdout','stderr'))
    _uib_need(type(terminal['schema']) is int and terminal['schema'] == 1
        and terminal['scope'] == 'MS4_UI_OBSERVER_NODE_NATIVE' and terminal['executable'] == created['executable']
        and terminal['argv'] == argv and type(terminal['pid']) is int and terminal['pid'] == created['pid']
        and type(terminal['close_code']) is int and terminal['close_code'] == 0
        and terminal['close_signal'] is None, 'SAME_REAL_NODE_CLOSE_ZERO_NULL_NOT_PYTHON_WAIT')
    for key in ('stdout','stderr'):
        stream = terminal[key]; _uib_keys(stream, ('bytes','sha256','stored_bytes','stored_sha256','complete'))
        _uib_int(stream['bytes']); _uib_int(stream['stored_bytes'])
        _uib_need(stream['complete'] is True and stream['bytes'] == stream['stored_bytes'] == len(raws[key])
            and stream['sha256'] == stream['stored_sha256'] == sha(raws[key]), 'ORIGINAL_COMPLETE_NATIVE_EOF_STREAM')
    _uib_need(raws['stderr'] == b'' and 0 < len(raws['stdout']) <= 268435456, 'FULL_STDOUT_EMPTY_STDERR')
    raw = raws['stdout']; _uib_need(raw.endswith(b'\n') and b'\r' not in raw, 'COMPLETE_ORIGINAL_LF_FRAME_STREAM')
    lines = raw.splitlines(keepends=True); _uib_need(len(lines) == 42, 'EXACT_FORTY_TWO_FRAMES')
    frames = []; offset = 0
    for sequence, line in enumerate(lines):
        _uib_need(0 < len(line) <= 16777216 and line.endswith(b'\n'), 'WHOLE_BOUNDED_FRAME')
        frame = _uib_json(line); _uib_keys(frame, ('schema','scope','binding','sequence','command','step','receipt'))
        if sequence == 0: command, index, fields = 'BEGIN', 0, ('baseline',)
        elif sequence == 41: command, index, fields = 'FINISH', 21, ('final','preservation')
        elif sequence % 2: command, index, fields = 'STEP_BEGIN', (sequence+1)//2, ('before',)
        else: command, index, fields = 'STEP_VERIFY', sequence//2, ('after','verified_after','expected_delta','oracle')
        _uib_need(type(frame['schema']) is int and frame['schema'] == 1
            and frame['scope'] == 'MS4_UI_OBSERVER_SAFE_FRAME' and _uib_same(frame['binding'],binding)
            and type(frame['sequence']) is int and frame['sequence'] == sequence
            and type(frame['step']) is int and frame['step'] == index and frame['command'] == command,
            'ORIGINAL_FRAME_SCHEDULE_AND_BINDING')
        _uib_keys(frame['receipt'], fields)
        _uib_need(line == _uib_canon(frame)+b'\n', 'ORIGINAL_CANONICAL_FRAME_BYTES')
        frames.append({'value':frame,'raw':line,'start':offset,'end':offset+len(line),'sha256':sha(line)})
        offset += len(line)
    _uib_need(offset == len(raw), 'NO_PREFIX_SUFFIX_OR_PARTIAL_NATIVE_BYTES')
    return {'created':created,'terminal':terminal,'frames':frames,'descriptors':descriptors}


def _uib_authentication(value):
    _uib_keys(value, ('present','kind','subject_id'))
    _uib_need(type(value['present']) is bool, 'AUTHENTICATION_BOOLEAN')
    if value['present']:
        _uib_need(value['kind'] == 'Bearer', 'OBSERVED_BEARER'); _uib_int(value['subject_id'],1)
    else: _uib_need(value['kind'] == 'none' and value['subject_id'] is None, 'OBSERVED_ANONYMOUS')


def _uib_identity(value, role, actors, merchant):
    _uib_keys(value, ('application','subject_id','merchant_id','verified_by'))
    if role == 'public':
        _uib_need(_uib_same(value, {'application':'none','subject_id':None,'merchant_id':None,
            'verified_by':'actual-anonymous-normal-response'}), 'GENUINE_ANONYMOUS_IDENTITY')
    else:
        _uib_int(value['subject_id'],1)
        _uib_need(value['application'] == {'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role]
            and value['subject_id'] == actors[role] and value['merchant_id'] == (merchant if role == 'merchant' else None)
            and value['verified_by'] == 'native-session-and-normal-UI', 'PREPARED_NATIVE_ROLE_JOIN')


def _uib_no_secrets(value):
    if type(value) is list:
        for child in value: _uib_no_secrets(child)
    elif type(value) is dict:
        forbidden = {'password','pwd','login_token','read_ticket','request_ticket','body_base64','authorization','authori-zation','token','ticket','credentials','credential','private_key','access_token','refresh_token','raw_headers'}
        _uib_need(not any(k.lower() in forbidden for k in value), 'NO_PUBLIC_SECRET_FIELDS')
        for child in value.values(): _uib_no_secrets(child)


def _uib_shape(value, shape, unused_schemas):
    # Only the three approved scalar source-binding types, never an any-object DTO.
    if shape == 'positive': _uib_int(value,1)
    elif shape == 'native_id':
        _uib_need(type(value) is str and re.fullmatch('[1-9][0-9]{0,19}',value)
            and int(value) <= 18446744073709551615, 'SOURCE_NATIVE_ID')
    elif shape == 'string': _uib_need(type(value) is str, 'SOURCE_STRING_BINDING')
    elif shape == 'money': _uib_need(type(value) is str and re.fullmatch(r'(0|[1-9][0-9]*)\.[0-9]{2}',value), 'SOURCE_EXACT_MONEY')
    else: _uib_need(False, 'UNKNOWN_FINITE_BINDING_TYPE')


def _uib_wire(value):
    _uib_keys(value, ('actor_role','method','path','body_public','body_public_sha256','response_status','response_public','response_public_sha256'))
    _uib_need(value['actor_role'] in ('customer','merchant','platform','public') and value['method'] in ('GET','POST','PUT')
        and type(value['path']) is str and value['path'].startswith('/') and len(value['path']) <= 2048
        and not any(t in value['path'] for t in ('://','..','#','\\','\0'))
        and type(value['response_status']) is int and value['response_status'] == 200, 'ACTUAL_SUCCESSFUL_SAFE_WIRE')
    _uib_need(value['body_public_sha256'] == sha(_uib_canon(value['body_public']))
        and value['response_public_sha256'] == sha(_uib_canon(value['response_public'])), 'ORIGINAL_PUBLIC_WIRE_HASHES')
    _uib_no_secrets(value['body_public']); _uib_no_secrets(value['response_public'])


def _uib_products_query(request, history):
    from urllib.parse import unquote_plus
    path = request['path']; pairs = path.split('?',1); _uib_need(pairs[0] == '/api/products', 'EXACT_PRODUCTS_ENDPOINT')
    query = {}
    if len(pairs) == 2 and pairs[1] != '':
        for pair in pairs[1].split('&'):
            row = pair.split('=',1); _uib_need(len(row) == 2, 'COMPLETE_QUERY_KEY_VALUE')
            key, value = unquote_plus(row[0]), unquote_plus(row[1])
            _uib_need(key not in query and '[' not in key and not any(ord(ch) == 0 for ch in key+value), 'UNIQUE_SCALAR_ORDINARY_QUERY')
            query[key] = value
    homes = [r for item in history for r in item['event']['requests'] if r['method'] == 'GET'
        and r['path'] == '/api/theme_info/home' and r['finished_at_ms'] <= request['started_at_ms']]
    _uib_need(homes, 'ORIGINAL_NATIVE_BOUND_HOME_DTO_BEFORE_PRODUCTS')
    home = max(homes,key=lambda r:r['finished_at_ms'])['response_public']
    _uib_need(type(home) is dict and type(home.get('value')) in (list,dict), 'FULL_ACTUAL_HOME_COMPONENTS')
    components = home['value']
    if type(components) is dict:
        _uib_need(all(type(key) is str for key in components), 'ACTUAL_HOME_STRING_COMPONENT_KEYS')
        components = [components[key] for key in sorted(components,key=lambda key:key.encode('utf-16-be','surrogatepass'))]
    else:
        components = [components[index] for index in sorted(range(len(components)),key=lambda index:str(index))]
    def truthy(v): return not (v is None or v is False or (type(v) is str and v == '') or (type(v) in (int,float) and v == 0))
    def scalar(v):
        _uib_need(type(v) in (str,int,float) and type(v) is not bool, 'SOURCE_HOME_QUERY_SCALAR')
        return v if type(v) is str else _uib_canon(v).decode('utf8')
    def good(c):
        kind = int(c.get('typeConfig',{}).get('activeValue',0)); order = int(c.get('goodsSort',{}).get('tabVal',0))
        if kind == 1:
            ids = [scalar(r['id']) for r in c.get('goodsList',{}).get('list',[]) if truthy(r.get('id'))]
            return {'ids':','.join(ids)} if ids else None
        if kind in (3,4):
            field = 'cate_id' if kind == 3 else 'store_label_id'
            values = c.get('classList',{}).get('classVal',[]) if kind == 3 else c.get('goodsLabel',{}).get('activeValue',[])
            _uib_need(type(values) is list, 'SOURCE_HOME_QUERY_ARRAY')
            return {'priceOrder':'desc' if order == 2 else '', 'salesOrder':'desc' if order == 1 else '',
                field:','.join(scalar(v) for v in values),'limit':scalar(c['numberConfig']['val'])}
        return {}
    expected = []
    for c in components:
        if type(c) is not dict or truthy(c.get('isHide')): continue
        if c.get('name') in ('goodList','goodRecommend'): result = good(c)
        elif c.get('name') == 'promotionList':
            tabs = c['tabConfig']['list']; _uib_need(type(tabs) is list and tabs, 'REAL_FIRST_ACTIVE_PROMOTION_TAB'); t = tabs[0]
            result = good({'goodsList':t['goodsList'],'classList':{'classVal':t['selectConfig']['activeValue']},
                'goodsLabel':t['goodsLabel'],'typeConfig':{'activeValue':int(t['tabVal']) or 3},
                'goodsSort':{'tabVal':t['goodsSort']},'numberConfig':{'val':t['numConfig']['val']}})
        else: continue
        if result is not None: expected.append(result)
    _uib_need(any(_uib_same(query,v) for v in expected), 'EXACT_QUERY_FROM_NATIVE_BOUND_VISIBLE_HOME_COMPONENT')


def _uib_event(value, wire, index, binding, owned, prepared, data, history):
    _uib_keys(value, ('schema','scope','binding','index','name','role','tab_id_sha256','started_at_ms','finished_at_ms','actions','requests','identity','DOM','diagnostic'))
    role = UI_BUSINESS_ROLES[index-1]; context = data['_runtime']; case = data['steps'][index-1]
    _uib_need(type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_UI_NORMAL_BROWSER_STEP'
        and _uib_same(value['binding'],binding) and type(value['index']) is int and value['index'] == index
        and value['name'] == UI_BUSINESS_STEPS[index-1] and value['role'] == wire['actor_role'] == role
        and value['tab_id_sha256'] == owned['customer' if role == 'public' else role] and value['diagnostic'] is None, 'BROWSER_EVENT_ROLE_NATIVE_BINDING')
    start = _uib_int(value['started_at_ms'],1); end = _uib_int(value['finished_at_ms'],start)
    _uib_identity(value['identity'],role,prepared['actor_ids'],prepared['merchant_id'])
    _uib_schema_v1(value,{'$ref':'#/schemas/browser_event'},data['schemas'],relation_context=context)
    requests = value['requests']; _uib_need(type(requests) is list and len(requests) <= 2048 and (requests or index == 20), 'COMPLETE_ASSOCIATED_REQUESTS')
    primary = [r for r in requests if r['method'] == wire['method'] and r['path'] == wire['path']
        and r['request_public_sha256'] == wire['body_public_sha256'] and r['response_public_sha256'] == wire['response_public_sha256']]
    _uib_need(len(primary) == 1 or (index == 20 and not primary), 'UNIQUE_PRIMARY_OR_NAMED_HISTORY')
    primary_sequence = primary[0]['sequence'] if primary else None
    _uib_need(wire['method'] == case['primary']['method'] and wire['path'] == _uib_path(case['primary']['path_template'],data,history,index), 'EXACT_PRIMARY_NATIVE_BOUND_PATH')
    _uib_schema_v1(wire['body_public'],case['primary']['request_schema'],data['schemas'],relation_context=context)
    _uib_schema_v1(wire['response_public'],case['primary']['response_schema'],data['schemas'],relation_context=context)
    associated = case['associated']; _uib_keys(associated, ('classes','required','relations'))
    classes = data['wire_rules']['associated_classes']; allowed = associated['classes']; classified = {}; by_class = {k:[] for k in allowed}
    logout_seen = False
    for sequence, request in enumerate(requests):
        _uib_keys(request, ('sequence','method','path','started_at_ms','finished_at_ms','http_status','app_status','request_public','response_public','request_public_sha256','response_public_sha256','authentication'))
        _uib_need(type(request['sequence']) is int and request['sequence'] == sequence
            and request['method'] in ('GET','POST','PUT') and type(request['path']) is str and request['path'].startswith('/')
            and len(request['path']) <= 2048 and not any(ord(ch) == 0 or ch == chr(92) for ch in request['path'])
            and not any(v in request['path'] for v in ('://','..','#')) and type(request['http_status']) is int
            and request['http_status'] == 200, 'WHOLE_SUCCESSFUL_SEMANTIC_REQUEST')
        _uib_int(request['started_at_ms'],start,end); _uib_int(request['finished_at_ms'],request['started_at_ms'],end)
        _uib_authentication(request['authentication']); _uib_no_secrets(request['request_public']); _uib_no_secrets(request['response_public'])
        _uib_need(request['request_public_sha256'] == sha(_uib_canon(request['request_public']))
            and request['response_public_sha256'] == sha(_uib_canon(request['response_public'])), 'FULL_ASSOCIATED_ORIGINAL_CANONICAL_HASHES')
        matches = []
        for class_id in allowed:
            rule = classes[class_id]; _uib_keys(rule, ('method','path_templates','request_schema','response_schema','authentication','source'))
            if request['method'] != rule['method']: continue
            for template in rule['path_templates']:
                if class_id == 'startup_products':
                    if request['path'] == '/api/products' or request['path'].startswith('/api/products?'):
                        _uib_products_query(request,context['steps']); matches.append(class_id)
                    break
                names = re.findall(r'\{([a-z][a-z0-9_]*)\}',template)
                if any(data['wire_rules']['bindings'][n]['source'] in ('verified-step','verified-associated')
                    and data['wire_rules']['bindings'][n]['step'] > len(context['steps']) for n in names): continue
                resolved = _uib_path(template,data,context['steps'],index,True)
                if request['path'] == resolved: matches.append(class_id); break
        if len(matches) == 0 and sequence == primary_sequence: class_id = 'PRIMARY'
        else:
            _uib_need(len(matches) == 1, 'EXACTLY_ONE_FINITE_ASSOCIATED_CLASS_NO_PREFIX_EXEMPTION'); class_id = matches[0]
        classified[sequence] = class_id
        if class_id != 'PRIMARY':
            rule = classes[class_id]; by_class[class_id].append(sequence)
            _uib_schema_v1(request['request_public'],rule['request_schema'],data['schemas'],relation_context=context)
            _uib_schema_v1(request['response_public'],rule['response_schema'],data['schemas'],relation_context=context)
            auth = request['authentication']; mode = rule['authentication']
            if mode == 'anonymous': expected_role = None
            elif mode in ('customer','customer-plus-matching-private-ticket','same-real-customer-token-before-public'): expected_role = 'customer'
            elif mode in ('merchant','merchant-plus-matching-private-ticket'): expected_role = 'merchant'
            elif mode == 'platform': expected_role = 'platform'
            else:
                _uib_need(mode == 'customer-or-source-optional-startup', 'KNOWN_FINITE_AUTHENTICATION_MODE')
                expected_role = 'customer' if auth['present'] else None
            wanted = {'present':False,'kind':'none','subject_id':None} if expected_role is None else {'present':True,'kind':'Bearer','subject_id':prepared['actor_ids'][expected_role]}
            _uib_need(_uib_same(auth,wanted), 'ACTUAL_FINITE_CLASS_ROLE_AUTHENTICATION')
        if role == 'public' and (index != 16 or logout_seen):
            _uib_need(_uib_same(request['authentication'],{'present':False,'kind':'none','subject_id':None}), 'ALL_POST_LOGOUT_PUBLIC_REQUESTS_ANONYMOUS')
        if class_id == 'logout':
            _uib_need(index == 16 and not logout_seen and sequence < primary_sequence, 'ONE_REAL_GUI_LOGOUT_BEFORE_PUBLIC_LIST'); logout_seen = True
        if request['app_status'] is None:
            binary = request['response_public']; raw_script = request['method'] == 'GET' and request['path'] == '/api/get_script' and binary == ''
            is_binary = type(binary) is dict and set(binary) == {'bytes','sha256','mime_type'}
            if is_binary: _uib_need(_uib_same(binary,{**binding['source_closure']['artifacts']['ms4-order.png'],'mime_type':'image/png'}), 'WHOLE_FROZEN_PNG_BINARY_PROJECTION')
            _uib_need(raw_script or is_binary, 'NO_UNKNOWN_NULL_APP_STATUS')
        else: _uib_need(type(request['app_status']) is int and request['app_status'] == 200, 'ACTUAL_JSON_STATUS200')
    for class_id, requirement in associated['required'].items():
        _uib_keys(requirement, ('minimum','maximum','position')); _uib_int(requirement['minimum'])
        _uib_need(class_id in by_class, 'KNOWN_REQUIRED_CLASS')
        position = requirement['position']; _uib_need(position in ('any','before-primary','after-primary'), 'CLOSED_CLASS_POSITION')
        actual = by_class[class_id]
        if position != 'any':
            _uib_need(primary_sequence is not None, 'REAL_PRIMARY_FOR_REQUIRED_READBACK')
            actual = [v for v in actual if (v < primary_sequence if position == 'before-primary' else v > primary_sequence)]
        _uib_need(len(actual) >= requirement['minimum'], 'EACH_REQUIRED_POSITION_MINIMUM')
        if requirement['maximum'] is not None:
            _uib_int(requirement['maximum'],requirement['minimum']); _uib_need(len(actual) <= requirement['maximum'], 'EACH_SOURCE_POSITION_MAXIMUM')
    if index == 16: _uib_need(logout_seen, 'REAL_LOGOUT_MANDATORY')
    context['steps'][-1]['classes'] = classified
    for use in data['wire_rules']['binding_uses'].get(str(index),[]):
        _uib_keys(use, ('pointer','binding','mode','template')); actual = _uib_pointer(context['steps'][-1]['step'],use['pointer'])
        if use['mode'] == 'deep-equal':
            _uib_need(use['template'] is None, 'NO_TEMPLATE_ON_DEEP_EQUAL_BINDING')
            expected = _uib_binding(use['binding'],data,history,index)
        else:
            _uib_need(use['mode'] == 'path-template' and use['template'] == case['primary']['path_template'], 'ENTIRE_DECLARED_PATH_TEMPLATE_ONLY')
            expected = _uib_path(use['template'],data,history,index)
        _uib_need(_uib_same(actual,expected), 'ACTUAL_CURRENT_INPUT_JOINS_PRIOR_NATIVE_BINDING')
    _uib_relations(associated['relations'],value,context)
    return primary_sequence


def _uib_sequence(value, index, answer):
    _uib_keys(value, ('schema','scope','number_sha256','key_sha256','initial_present','samples','natural_final_absent','elapsed_ns'))
    number = answer['refund']['refund_no'] if index == 6 else answer['child_order_no']
    _uib_need(type(number) is str and re.fullmatch('(cp|cf)[1-9][0-9]{0,18}',number), 'TRUE_NATIVE_SEQUENCE_NUMBER')
    key = str(1640966400000 + int(number[2:])//4194304)
    _uib_need(type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'PASSIVE_REAL_NORMAL_SEQUENCE_COUNTER_ONLY'
        and value['number_sha256'] == sha(number.encode()) and value['key_sha256'] == sha(key.encode())
        and type(value['initial_present']) is bool and value['natural_final_absent'] is True, 'INDEPENDENT_SEQUENCE_DERIVATION')
    _uib_int(value['elapsed_ns'],0,10000000000); _uib_need(type(value['samples']) is list and value['samples'], 'REAL_SEQUENCE_SAMPLES')
    previous = 0; other = None
    for i, row in enumerate(value['samples']):
        _uib_keys(row, ('time_ms','present','other_native_sha256'))
        _uib_need(type(row['time_ms']) is str and re.fullmatch('[1-9][0-9]*',row['time_ms'])
            and int(row['time_ms']) >= previous and type(row['present']) is bool
            and row['present'] == (i != len(value['samples'])-1), 'NATURAL_SEQUENCE_ABSENCE_ORDER')
        _uib_hex(row['other_native_sha256'])
        if other is not None: _uib_need(other == row['other_native_sha256'], 'OTHER_NATIVE_STATE_UNCHANGED_DURING_BARRIER')
        previous = int(row['time_ms']); other = row['other_native_sha256']
    _uib_need(value['samples'][0]['present'] == value['initial_present'], 'TRUE_INITIAL_SEQUENCE_OBSERVATION')


def _uib_review_parents(prepared):
    customer = _uib_int(prepared['customer_id'],1,2147483647)
    root = _uib_int(prepared['paid_order_id'],1,4294967295)
    _uib_int(prepared['merchant_id'],1,4294967295)
    _uib_need(type(prepared['actor_ids']) is dict and prepared['actor_ids']['customer'] == customer
        and type(prepared['actor_ids']['customer']) is int, 'N2_ORIGINAL_PREPARED_NATIVE_CUSTOMER')
    items = prepared['item_quantities']
    _uib_need(type(items) is list and len(items) == 2, 'N2_ORIGINAL_PREPARED_TWO_SOURCE_ITEMS')
    for row, quantity in zip(items,(2,1)):
        _uib_keys(row, ('item_id','quantity')); _uib_int(row['item_id'],1,4294967295)
        _uib_need(type(row['quantity']) is int and row['quantity'] == quantity, 'N2_ORIGINAL_PREPARED_Q2_Q1')
    _uib_need(items[0]['item_id'] != items[1]['item_id'], 'N2_ORIGINAL_DISTINCT_SOURCE_ITEMS')
    return customer, root, items[0]['item_id']


def _uib_expected(value, index, wire, event, before, after, state, binding, prepared):
    _uib_keys(value, ('schema','scope','name','tables','independent','all_other_database_redis_files_verified','transient_ticket_native_observation','full_twenty_case_acceptance'))
    _uib_need(type(value['schema']) is int and value['schema'] == 2 and value['scope'] == 'MS4_UI_INDEPENDENT_NORMAL_CASE_EXPECTATION'
        and value['name'] == UI_BUSINESS_STEPS[index-1] and type(value['tables']) is dict
        and value['all_other_database_redis_files_verified'] is True and value['transient_ticket_native_observation'] == 'NOT_CLAIMED'
        and value['full_twenty_case_acceptance'] is False, 'EXACT_SOURCE_EXPECTATION_NOT_FULL_ACCEPTANCE_BOOL')
    _uib_need(before['database']['names'] == after['database']['names']
        and before['database']['namespace_sha256'] == after['database']['namespace_sha256']
        and before['database']['schema_modes_sha256'] == after['database']['schema_modes_sha256'], 'DATABASE_NAMESPACE_MODES_FIXED')
    for table, row in value['tables'].items():
        _uib_need(table in before['database']['tables'], 'EXPECTED_EXISTING_TABLE_ONLY')
        _uib_keys(row, ('projection','natural_insert_count')); _uib_projection(row['projection']); _uib_int(row['natural_insert_count'])
        _uib_need(_uib_same(row['projection'],after['database']['tables'][table]['projection'])
            and row['projection']['rows'] == before['database']['tables'][table]['projection']['rows']+row['natural_insert_count'], 'EXACT_NATIVE_FULL_TABLE_EXPECTED_PROJECTION_COUNT')
    for table, row in before['database']['tables'].items():
        actual = after['database']['tables'][table]
        _uib_need(row['projected_columns_sha256'] == actual['projected_columns_sha256'], 'EVERY_NATIVE_COLUMN_ORDER_PRESERVED')
        # Actual m4ar_database_delta334-352 proves full metadata/DDL counter substitution.
        # Ledger hashes complete native metadata and SHOW CREATE, without normalization.
        inserts = value['tables'].get(table, {}).get('natural_insert_count', 0)
        if inserts == 0:
            _uib_need(row['metadata_sha256'] == actual['metadata_sha256'] and row['ddl_sha256'] == actual['ddl_sha256'], 'NO_INSERT_FULL_METADATA_DDL_UNCHANGED')
        if table not in value['tables']: _uib_need(_uib_same(row,actual), 'EVERY_UNLISTED_TABLE_PRESERVED')
    independent = value['independent']
    fields = (('login',),('login',),('login',),(),('private_file_id','private_pairs'),('N1','sequence_barrier','private_pairs'),
        ('private_pairs',),('N1','private_pairs'),('fulfillment','sequence_barrier'),('fulfillment',),('fulfillment',),
        ('fulfillment','sequence_barrier'),('fulfillment',),('public_image',),('review_id',),(),
        ('review_id','changed_fields'),('review_id','changed_fields'),(),('public_image_read',))[index-1]
    if fields: _uib_keys(independent, fields)
    else: _uib_need(type(independent) is list and independent == [], 'NATIVE_EMPTY_FACT_ARRAY')
    answer = wire['response_public']
    if index <= 3:
        login = independent['login']; _uib_keys(login, ('application','subject_id','native_session_verified','signed_claims_verified','browser_token_join'))
        _uib_need(login['application'] == ('api','merchantapi','adminapi')[index-1]
            and login['subject_id'] == event['identity']['subject_id'] and type(login['subject_id']) is int
            and login['native_session_verified'] is True and login['signed_claims_verified'] is True
            and login['browser_token_join'] is True, 'ACTUAL_SIGNED_LOGIN_NATIVE_BROWSER_JOIN')
    if index == 5:
        _uib_shape(independent['private_file_id'],'native_id',{})
        _uib_need(independent['private_file_id'] == answer['id'], 'INDEPENDENT_PRIVATE_FILE_ID'); state['file'] = answer['id']
    if index in (5,6,7,8):
        pairs = independent['private_pairs']; _uib_need(type(pairs) is list and 1 <= len(pairs) <= 16, 'ACTUAL_NORMAL_PRIVATE_PAIRS')
        used = set()
        for pair in pairs:
            _uib_keys(pair, ('issued_sequence','read_sequence','file_id','full_response_bytes_verified','net_ticket_absent','native_intermediate_observed'))
            issued = _uib_int(pair['issued_sequence']); read = _uib_int(pair['read_sequence'],issued+1)
            _uib_need(read < len(event['requests']) and issued not in used and read not in used
                and pair['file_id'] == state['file'] and pair['full_response_bytes_verified'] is True
                and pair['net_ticket_absent'] is True and pair['native_intermediate_observed'] is False, 'PRIVATE_PAIR_WITHOUT_FALSE_INTERMEDIATE_CLAIM')
            used.update((issued,read)); q = event['requests'][issued]; r = event['requests'][read]
            prefix = '/merchantapi/aftersale-files/' if event['role'] == 'merchant' else '/api/order/refund/files/'
            _uib_need(q['method'] == 'POST' and q['path'] == prefix+state['file']+'/read-ticket'
                and _uib_same(q['request_public'], {'schema':1}) and r['method'] == 'GET' and r['path'] == prefix+state['file']+'/content'
                and q['finished_at_ms'] <= r['started_at_ms'], 'TRUE_TICKET_CONTENT_ASSOCIATED_PAIR')
    if index in (6,8):
        n1 = {'business_state':'REQUESTED' if index == 6 else 'APPROVED','physical_state':'NONE',
            'finance_state':'NOT_SUBMITTED' if index == 6 else 'UNSUPPORTED','held_amount':'0.01','confirmed_amount':'0.00'}
        _uib_need(_uib_same(independent['N1'],n1) and all(answer['refund'][k] == v for k,v in n1.items()), 'N1_REAL_HELD_ONLY_NO_FINANCE')
        if index == 8: _uib_shape(answer['action_id'],'native_id',{}); state['approval'] = answer['action_id']
    if index in (6,9,12): _uib_sequence(independent['sequence_barrier'],index,answer)
    if 9 <= index <= 13:
        fulfillment = independent['fulfillment']; _uib_keys(fulfillment, ('summary','aftersale','child_count'))
        shipped = (2,2,2,3,3)[index-9]; received = (0,1,2,2,3)[index-9]; children = 1 if index < 12 else 2
        summary = {'shipping_state':'partial' if shipped == 2 else 'shipped',
            'receipt_state':'received' if received == shipped else 'pending' if received == 0 else 'partial',
            'closed':index == 13,'original':3,'shipped':shipped,'received':received,'cancelled_unshipped':0,
            'returned_accepted':0,'to_ship':3-shipped,'to_receive':shipped-received,'child_count':children}
        _uib_need(_uib_same(fulfillment['summary'],summary) and type(fulfillment['child_count']) is int and fulfillment['child_count'] == children
            and _uib_same(summary,answer['fulfillment_summary']), 'TWO_CHILD_THREE_UNIT_FIVE_OPERATION_PROGRESS')
        a = fulfillment['aftersale']; _uib_keys(a, ('schema','revision','confirmed_amount','held_amount','available_amount',
            'pending_cancel_quantity','pending_return_quantity','returned_after_receipt','fulfillment_completed_at'))
        _uib_need(type(a['schema']) is int and a['schema'] == 1 and a['revision'] == state['approval'] and a['confirmed_amount'] == '0.00'
            and a['held_amount'] == '0.01' and a['pending_cancel_quantity'] == a['pending_return_quantity'] == a['returned_after_receipt'] == 0
            and _uib_same(a,answer['aftersale']), 'ORIGINAL_N1_APPROVED_HOLD_PRESERVED')
        for field in ('pending_cancel_quantity','pending_return_quantity','returned_after_receipt'):
            _uib_need(type(a[field]) is int and a[field] == 0, 'ACTUAL_INTEGER_ZERO_AFTERSALE_QUANTITIES')
        _uib_shape(a['available_amount'],'money',{})
        if index == 13: _uib_int(a['fulfillment_completed_at'],1); _uib_need(a['fulfillment_completed_at'] == answer['created_at'], 'REAL_COMPLETION_TIME')
        else: _uib_need(a['fulfillment_completed_at'] is None, 'NO_PREMATURE_COMPLETION')
        if 'available' in state: _uib_need(state['available'] == a['available_amount'], 'COMMERCE_AVAILABLE_AMOUNT_FIXED')
        state['available'] = a['available_amount']
    if index == 14:
        customer, paid_root, q2 = _uib_review_parents(prepared)
        image = binding['source_closure']['artifacts']['ms4-order.png']
        _uib_need(image == {'bytes':234,'sha256':'1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8'}
            and independent['public_image'] == image, 'PUBLIC_NATIVE_PNG_MATCHES_FROZEN_SOURCE')
        _uib_need(wire['actor_role'] == 'customer' and event['identity']['subject_id'] == customer
            and wire['method'] == 'POST' and wire['path'] == '/api/order/reviews/'+str(paid_root)+'/'+str(q2)+'/files',
            'N2_REAL_PREPARED_CUSTOMER_PAID_ROOT_Q2_UPLOAD')
        _uib_keys(wire['body_public'], ('file',)); file = wire['body_public']['file']
        _uib_keys(file, ('bytes','sha256','mime_type','original_name'))
        original_name = file['original_name']
        _uib_need({k:file[k] for k in ('bytes','sha256')} == image and file['mime_type'] == 'image/png'
            and type(original_name) is str and 1 <= len(original_name) <= 255
            and not any(ch in original_name for ch in ('/','\\','\0')) and original_name.lower().endswith('.png'),
            'N2_EXACT_FILE_ONLY_PUBLIC_REQUEST_ACTUAL_RAW_UTF8_NAME')
        original_name.encode('utf-8','strict')
        _uib_keys(answer, ('name','url','attachment_ref')); reference = answer['attachment_ref']
        _uib_need(type(reference) is str and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',reference)
            and answer['name'] == reference.rsplit('/',1)[1] and answer['url'] == binding['origin']+reference,
            'ACTUAL_PREPARED_CUSTOMER_PUBLIC_UPLOAD_REFERENCE')
        state['image'] = {'reference':reference,'url':answer['url'],'image':independent['public_image']}
    if index in (15,17,18):
        _uib_int(independent['review_id'],1)
        if index == 15: state['review'] = independent['review_id']
        else: _uib_need(independent['review_id'] == state['review'], 'SAME_NATIVE_REVIEW')
    if index == 17: _uib_need(independent['changed_fields'] == ['status'], 'ONLY_REVIEW_MODERATION_STATUS')
    if index == 18: _uib_need(type(independent['changed_fields']) is list
        and sorted(independent['changed_fields']) == ['is_reply','merchant_reply_content','merchant_reply_time'], 'ONLY_THREE_MERCHANT_REPLY_COLUMNS')
    if index == 20:
        fact = independent['public_image_read']; _uib_keys(fact, ('mode','original_step','original_name','original_sequence','original_wire_sha256','image_sha256'))
        _uib_int(fact['original_step'],1,20); _uib_int(fact['original_sequence'])
        _uib_need(type(fact['original_name']) is str and fact['original_name'] == UI_BUSINESS_STEPS[fact['original_step']-1], 'ACTUAL_IMAGE_FACT_STEP_NAME')
        _uib_need(fact['original_wire_sha256'] == sha(_uib_canon(wire)) and fact['image_sha256'] == state['image']['image']['sha256'], 'IMAGE_FACT_TRUE_WIRE_AND_FROZEN_BYTES')
        _uib_need(fact['mode'] in ('fresh-normal-response','historical-normal-response-reused'), 'IMAGE_FACT_MODE')
    return independent


def _uib_png(raw):
    import struct
    import zlib
    _uib_need(45 < len(raw) <= 33554432 and raw[:8] == b'\x89PNG\r\n\x1a\n', 'ORIGINAL_PNG_SIGNATURE_SIZE')
    offset = 8; header = None; palette = False; seen = False; ended_data = False; idat = []
    while offset < len(raw):
        _uib_need(offset+12 <= len(raw), 'PNG_COMPLETE_CHUNK_HEADER')
        size = int.from_bytes(raw[offset:offset+4], 'big'); end = offset+size+12
        _uib_need(end <= len(raw), 'PNG_COMPLETE_CHUNK_BYTES')
        kind = raw[offset+4:offset+8]; value = raw[offset+8:offset+8+size]
        _uib_need(re.fullmatch(b'[A-Za-z]{4}',kind) is not None
            and zlib.crc32(kind+value)&0xffffffff == int.from_bytes(raw[offset+8+size:end],'big'), 'EVERY_ORIGINAL_PNG_CRC')
        if header is None: _uib_need(kind == b'IHDR' and size == 13 and offset == 8, 'PNG_IHDR_FIRST')
        if kind == b'IHDR':
            _uib_need(header is None and size == 13 and offset == 8, 'ONE_PNG_IHDR'); header = value
        elif kind == b'PLTE':
            _uib_need(not palette and not seen and 0 < size <= 768 and size%3 == 0, 'PNG_PALETTE_ORDER'); palette = True
        elif kind == b'IDAT':
            _uib_need(not ended_data, 'PNG_IDAT_CONTIGUOUS'); seen = True; idat.append(value)
        elif kind == b'IEND':
            _uib_need(size == 0 and seen and end == len(raw), 'PNG_IEND_LAST_NO_TRAILING_BYTES')
            offset = end; break
        else:
            _uib_need(kind[0:1].islower(), 'NO_UNKNOWN_CRITICAL_PNG_CHUNK')
            if seen: ended_data = True
        offset = end
    _uib_need(offset == len(raw) and raw[-8:-4] == b'IEND' and header is not None, 'COMPLETE_PNG_END')
    width, height = struct.unpack('>II',header[:8]); depth, color, compression, filtering, interlace = header[8:13]
    allowed = {0:(1,2,4,8,16),2:(8,16),3:(1,2,4,8),4:(8,16),6:(8,16)}
    channels = {0:1,2:3,3:1,4:2,6:4}
    _uib_need(width > 0 and height > 0 and width*height <= 16777216 and color in allowed
        and depth in allowed[color] and compression == filtering == 0 and interlace in (0,1)
        and (color != 3 or palette), 'PNG_IHDR_REAL_PIXEL_FORMAT')
    passes = ((0,0,1,1),) if interlace == 0 else ((0,0,8,8),(4,0,8,8),(0,4,4,8),(2,0,4,4),(0,2,2,4),(1,0,2,2),(0,1,1,2))
    rows = []; expected = 0
    for x,y,dx,dy in passes:
        w = 0 if width <= x else (width-x+dx-1)//dx
        h = 0 if height <= y else (height-y+dy-1)//dy
        if not w or not h: continue
        count = (w*channels[color]*depth+7)//8; rows.append((h,count)); expected += h*(count+1)
    _uib_need(0 < expected <= 134217728, 'BOUNDED_COMPLETE_PNG_DECODE')
    decoder = zlib.decompressobj(); decoded = decoder.decompress(b''.join(idat),expected+1)
    _uib_need(len(decoded) == expected and decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail, 'EXACT_ORIGINAL_PNG_ZLIB_STREAM')
    cursor = 0
    for count, size in rows:
        for unused in range(count):
            _uib_need(decoded[cursor] <= 4, 'EVERY_PNG_SCANLINE_FILTER'); cursor += size+1
    _uib_need(cursor == expected, 'ALL_PNG_INTERLACE_ROWS_ACCOUNTED')
    return {'width':width,'height':height}


def _uib_bounds(value, outer=None):
    _uib_keys(value, ('x','y','width','height'))
    for k in value: _uib_num(value[k], k in ('width','height'))
    if outer is not None:
        _uib_need(value['x'] >= outer['x']-0.001 and value['y'] >= outer['y']-0.001
            and value['x']+value['width'] <= outer['x']+outer['width']+0.001
            and value['y']+value['height'] <= outer['y']+outer['height']+0.001, 'ACTUAL_BOUNDS_CONTAINED_NO_CLIPPING')
    return value


def _uib_request_join(request):
    return {'sequence':request['sequence'],'method':request['method'],'path_sha256':sha(request['path'].encode('utf8')),
        'started_at_ms':request['started_at_ms'],'finished_at_ms':request['finished_at_ms'],
        'http_status':request['http_status'],'app_status':request['app_status'],
        'request_public_sha256':request['request_public_sha256'],'response_public_sha256':request['response_public_sha256'],
        'authentication':request['authentication']}


# Exact finite label resolver follows the schema helpers.

def _uib_dom(root, descriptor, binding, window_descriptor, steps, owned, prepared, data, reads):
    value = _uib_leaf(root,descriptor,'ui-business-dom.json',reads,False); value = _uib_json(value)
    _uib_keys(value, ('schema','scope','status','binding','window','observations','producer_source','preservation','diagnostic','full_acceptance'))
    _uib_need(type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_UI_NORMAL_DOM_ONLY'
        and value['status'] == 'VERIFIED_MS4_UI_NORMAL_DOM' and _uib_same(value['binding'],binding)
        and value['window'] == window_descriptor and value['diagnostic'] is None and value['full_acceptance'] == 'INCOMPLETE', 'FINAL_DOM_SOURCE_NATIVE_WINDOW_JOIN')
    _uib_desc(value['producer_source'],'browser-v1/ms4-ui-dom-producer.mjs')
    _uib_need({k:value['producer_source'][k] for k in ('bytes','sha256')} == data['_aux']['ms4-ui-dom-producer.mjs'], 'ACTUAL_DOM_PRODUCER_SOURCE')
    _uib_need(type(value['observations']) is list and 12 <= len(value['observations']) <= 128, 'FINITE_ACTUAL_DOM_OBSERVATIONS')
    guards = {'PRIVATE_VISIBLE_MARKER','VISIBLE_SCAN_LIMIT','EDITABLE_VISIBLE','OPAQUE_MEDIA_VISIBLE',
        'AUTH_CONTROL_VISIBLE','INPUT_EMPTY_NOT_OBSERVED','AUTH_ACTION_VISIBLE','UNAPPROVED_IMAGE_VISIBLE',
        'UNAPPROVED_BACKGROUND_VISIBLE','AUTHENTICATION_POLICY','PRIVATE_PREVIEW_POLICY','SCREENSHOT_OMITTED',
        'VISIBLE_STATE_CHANGED_DURING_SCREENSHOT'}
    observations = []; grid = set(); image_rows = []
    for counter, reference in enumerate(value['observations'],1):
        suffix = str(counter).zfill(3)
        raw = _uib_leaf(root,reference,'ui-dom-observation-'+suffix+'.json',reads,False); observation = _uib_json(raw)
        _uib_keys(observation, ('schema','scope','binding','role','tab_id_sha256','url_sha256','viewport','state','identity','request','DOM','screenshot','image','window_step'))
        role = observation['role']; index = _uib_int(observation['window_step'],1,20); data['_runtime']['index'] = index
        _uib_need(type(observation['schema']) is int and observation['schema'] == 1 and observation['scope'] == 'MS4_UI_NORMAL_DOM_OBSERVATION'
            and _uib_same(observation['binding'],binding) and role in ('customer','merchant','platform','public')
            and observation['tab_id_sha256'] == owned['customer' if role == 'public' else role], 'OWNED_REAL_DOM_OBSERVATION')
        _uib_hex(observation['url_sha256']); _uib_identity(observation['identity'],role,prepared['actor_ids'],prepared['merchant_id'])
        _uib_keys(observation['viewport'], ('width','height')); width = _uib_int(observation['viewport']['width']); height = _uib_int(observation['viewport']['height'],1,16384)
        _uib_need(width in (320,390,1440), 'ACTUAL_RENDERED_CSS_WIDTH')
        event = steps[index-1]['event']
        _uib_need(observation['tab_id_sha256'] == event['tab_id_sha256'] and role == event['role'], 'DOM_SAME_PHASE_OWNED_TAB_ROLE')
        if observation['request'] is not None:
            _uib_keys(observation['request'], ('sequence','method','path_sha256','started_at_ms','finished_at_ms','http_status','app_status','request_public_sha256','response_public_sha256','authentication'))
            _uib_need(sum(_uib_same(observation['request'],_uib_request_join(r)) for r in event['requests']) == 1, 'EXACT_ACTUAL_DOM_REQUEST_JOIN')
        _uib_keys(observation['state'], ('page','screenshot_policy','screenshot_guard'))
        state = observation['state']; _uib_need(type(state['page']) is str and re.fullmatch('[a-z][a-z0-9-]{0,63}',state['page'])
            and state['screenshot_policy'] in ('capture-if-safe','omit') and type(state['screenshot_guard']) is list
            and state['screenshot_guard'] == sorted(set(state['screenshot_guard']))
            and all(g in guards for g in state['screenshot_guard']), 'FINITE_SCREENSHOT_POLICY_AND_ACTUAL_GUARDS')
        case = data['steps'][index-1]
        page = case['page']; variants = [p for p in page['variants'] if p['name'] == state['page']]
        choices = variants if variants else ([{'name':page['name'],'kind':page['kind'],'screenshot_policy':case['screenshot_policy'],'applicable_probe_keys':[p['key'] for p in case['probes']]}] if state['page'] == page['name'] else [])
        _uib_need(len(choices) == 1 and choices[0]['screenshot_policy'] == state['screenshot_policy'] and case['role'] == role, 'ACTUAL_PHASE_CATALOG_PAGE_POLICY')
        chosen = choices[0]; rule = {'kind':chosen['kind']}
        required = {p['key']:p for p in case['probes'] if p['key'] in chosen['applicable_probe_keys']}
        measurement = _uib_json(_uib_leaf(root,observation['DOM'],'ui-dom-measure-'+suffix+'.json',reads,False))
        _uib_keys(measurement, ('schema','scope','binding','role','tab_id_sha256','window_step','started_at_ms','finished_at_ms','viewport','image_bounds','controls','screenshot_call'))
        _uib_need(type(measurement['schema']) is int and measurement['schema'] == 1
            and measurement['scope'] == 'MS4_UI_NORMAL_VISIBLE_MEASUREMENT' and _uib_same(measurement['binding'],binding)
            and measurement['role'] == role and measurement['tab_id_sha256'] == observation['tab_id_sha256']
            and type(measurement['window_step']) is int and measurement['window_step'] == index
            and _uib_same(measurement['viewport'],observation['viewport']), 'ORIGINAL_VISIBLE_MEASUREMENT_JOIN')
        _uib_int(measurement['started_at_ms'],event['started_at_ms'],event['finished_at_ms'])
        _uib_int(measurement['finished_at_ms'],measurement['started_at_ms'],event['finished_at_ms'])
        controls = measurement['controls']; _uib_need(type(controls) is list and 1 <= len(controls) <= 64, 'ACTUAL_FINITE_VISIBLE_CONTROLS')
        _uib_need(1 <= len(controls) <= len(required), 'CURRENT_APPLICABLE_VISIBLE_PROBE_SUBSET')
        seen_controls = set()
        for control in controls:
            _uib_keys(control, ('key','kind','label','visible','enabled','bounds','parent_bounds'))
            _uib_need(control['key'] in required and control['key'] not in seen_controls, 'ONE_ORIGINAL_PROBE_NODE')
            seen_controls.add(control['key']); requirement = required[control['key']]
            _uib_need(control['kind'] == requirement['kind'] and control['visible'] is True
                and type(control['enabled']) is bool
                and control['label'] == _uib_label(requirement['label'],data,index,measurement['started_at_ms']), 'REAL_VISIBLE_KIND_LABEL_STATE')
            parent_bounds = _uib_bounds(control['parent_bounds']); bounds = _uib_bounds(control['bounds'],parent_bounds)
            if control['kind'] in ('button','input','status','link','image'):
                _uib_bounds(bounds,{'x':0,'y':0,'width':width,'height':height})
            # The original measurement has no inner glyph/icon rectangles; no invented alignment verdict.
        image = observation['image']
        if image is None: _uib_need(measurement['image_bounds'] is None, 'NO_INVENTED_IMAGE_BOUNDS')
        else:
            _uib_keys(image, ('kind','url_sha256','reference','bytes','sha256','media_type','complete','natural_width','natural_height','visible','bounds','content_verification'))
            _uib_hex(image['url_sha256'])
            fixture = binding['source_closure']['artifacts']['ms4-order.png']
            _uib_need(image['kind'] in ('public','private') and image['bytes'] == fixture['bytes'] and image['sha256'] == fixture['sha256']
                and image['media_type'] == 'image/png' and image['complete'] is True and image['visible'] is True
                and type(image['natural_width']) is int and type(image['natural_height']) is int
                and image['natural_width'] == image['natural_height'] == 96
                and image['content_verification'] == 'FIXTURE_REFERENCE_REQUIRES_NATIVE_HTTP_JOIN'
                and _uib_same(measurement['image_bounds'],image['bounds']), 'REAL_PINNED_IMAGE_DOM_NOT_HTTP_CLAIM')
            _uib_bounds(image['bounds'],{'x':0,'y':0,'width':width,'height':height})
            if image['kind'] == 'private':
                _uib_need(image['reference'] is None and rule['kind'] == 'private-preview'
                    and observation['screenshot'] is None and state['screenshot_policy'] == 'omit', 'NO_PRIVATE_IMAGE_OR_AUTH_SCREENSHOT')
            else:
                expected_image = steps[13]['state']['image']
                _uib_need(image['reference'] == expected_image['url'] and image['url_sha256'] == sha(expected_image['url'].encode()), 'ACTUAL_PUBLIC_NATIVE_UPLOAD_REFERENCE')
        shot = observation['screenshot']; call = measurement['screenshot_call']
        if rule['kind'] != 'normal' or state['screenshot_policy'] == 'omit' or state['screenshot_guard']:
            _uib_need(shot is None and call is None, 'ACTUAL_SUPPRESSION_NO_PUBLIC_PRIVATE_BYTES')
            if rule['kind'] == 'authentication': _uib_need('AUTHENTICATION_POLICY' in state['screenshot_guard'], 'AUTH_POLICY_GUARD')
            if rule['kind'] == 'private-preview': _uib_need('PRIVATE_PREVIEW_POLICY' in state['screenshot_guard'], 'PRIVATE_POLICY_GUARD')
        else:
            _uib_need(shot is not None and call is not None, 'ACTUAL_SAFE_NORMAL_SCREENSHOT_REQUIRED')
            _uib_desc(shot,'/run/audit/evidence/ui-dom-shot-'+suffix+'.png')
            pixels = _uib_png(_uib_leaf(root,shot,'ui-dom-shot-'+suffix+'.png',reads,False))
            _uib_keys(call, ('started_at_ms','finished_at_ms','descriptor','pixel_width','pixel_height','scale'))
            _uib_int(call['started_at_ms'],measurement['started_at_ms'],measurement['finished_at_ms'])
            _uib_int(call['finished_at_ms'],call['started_at_ms'],measurement['finished_at_ms'])
            _uib_need(call['descriptor'] == shot and type(call['pixel_width']) is int and type(call['pixel_height']) is int
                and call['pixel_width'] == pixels['width'] and call['pixel_height'] == pixels['height'] and call['scale'] is None,
                'ORIGINAL_SCREENSHOT_PIXELS_CSS_INDEPENDENT_NO_GUESSED_DPR')
            if role in ('customer','merchant','platform'): grid.add((role,width))
        data['_runtime']['index'] = index
        _uib_schema_v1(observation,{'$ref':'#/schemas/DOM_observation'},data['schemas'],relation_context=data['_runtime'])
        _uib_schema_v1(measurement,{'$ref':'#/schemas/DOM_measurement'},data['schemas'],relation_context=data['_runtime'])
        observations.append(observation)
        if index == 20 and role == 'public' and image is not None and image['kind'] == 'public': image_rows.append(observation)
    _uib_need(grid == {(role,width) for role in ('customer','merchant','platform') for width in (320,390,1440)}, 'THREE_ROLES_THREE_REAL_RENDERED_WIDTHS')
    _uib_need(len(image_rows) == 3 and [o['viewport']['width'] for o in image_rows] == [320,390,1440], 'CASE20_THREE_CURRENT_IMAGE_OBSERVATIONS')
    image_dom = steps[19]['event']['DOM']
    _uib_keys(image_dom, ('schema','scope','image_url','complete','natural_width','natural_height','visible','viewports'))
    _uib_need(type(image_dom['schema']) is int and image_dom['schema'] == 1
        and image_dom['scope'] == 'MS4_UI_NORMAL_PUBLIC_IMAGE_DOM' and image_dom['image_url'] == steps[13]['state']['image']['url']
        and image_dom['complete'] is True and image_dom['visible'] is True
        and type(image_dom['natural_width']) is int and type(image_dom['natural_height']) is int
        and image_dom['natural_width'] == image_dom['natural_height'] == 96
        and type(image_dom['viewports']) is list and len(image_dom['viewports']) == 3, 'CURRENT_CASE20_DOM8_NATIVE_PUBLIC_IMAGE')
    for row, observation in zip(image_dom['viewports'],image_rows):
        _uib_keys(row, ('viewport_width','viewport_height','image_url','complete','natural_width','natural_height','visible','bounds','screenshot'))
        _uib_int(row['viewport_width'],1,16384); _uib_int(row['viewport_height'],1,16384)
        _uib_need(row['viewport_width'] == observation['viewport']['width'] and row['viewport_height'] == observation['viewport']['height']
            and row['image_url'] == observation['image']['reference'] and row['complete'] is True and row['visible'] is True
            and row['natural_width'] == row['natural_height'] == 96 and _uib_same(row['bounds'],observation['image']['bounds']), 'CASE20_ACTUAL_OBSERVATION_PROJECTION')
        relative = 'ui-dom-shot-'+str(observations.index(observation)+1).zfill(3)+'.png'
        _uib_desc(row['screenshot'],relative)
        _uib_need(observation['screenshot'] is not None, 'CASE20_REAL_SAFE_CURRENT_SCREENSHOT')
        expected = dict(observation['screenshot']); expected['path'] = relative
        _uib_need(row['screenshot'] == expected, 'STRICT_CASE20_RELATIVE_LEAF_TO_SAME_RAW_HOST_PNG')
    return value


def _uib_data_json(raw):
    # Frozen schema bounds may exceed JS safe integers; wire/frame canonical remains strict.
    def pairs(rows):
        result = {}
        for k,v in rows:
            _uib_need(k not in result, 'DUPLICATE_SOURCE_DATA_KEY'); result[k] = v
        return result
    def bad(value): raise RuntimeError('UI_BUSINESS_NONFINITE_SOURCE_DATA')
    return json.loads(raw.decode('utf8',errors='strict'),object_pairs_hook=pairs,parse_constant=bad)


def _uib_data_same(a,b):
    clean = lambda v:{k:x for k,x in v.items() if not k.startswith('_')}
    return json.dumps(clean(a),sort_keys=True,ensure_ascii=False,allow_nan=False,separators=(',',':')) == json.dumps(clean(b),sort_keys=True,ensure_ascii=False,allow_nan=False,separators=(',',':'))


UI_BUSINESS_RELATIONS = frozenset(('SAME_BINDING9', 'PREPARED_ROOT_ACTOR_JOIN', 'VERIFIED_PREVIOUS_DTO_JOIN', 'Q2_Q1_EXACT', 'ORIGINAL_SOURCE_FINGERPRINT', 'PRIMARY_REQUEST_UNIQUE', 'CASE20_ORIGINAL_IMAGE_WIRE', 'FULL_ORIGINAL_PUBLIC_HASH', 'PRIVATE_TICKET_CONTENT_PAIR', 'NATIVE_LOGIN_TOKEN_JOIN', 'SOURCE_BOUND_ORACLE_ACK', 'SAME_NODE_NATIVE42_EOF', 'EXACT_ASSOCIATED_SET', 'PUBLIC_LIST_CONFIG_DISTINCT', 'DOM_CURRENT_OWNED_TAB_WINDOW', 'SCREENSHOT_ORIGINAL_PNG', 'FULL_PRESERVATION', 'CUSTOMER_LOGIN_REQUIRED_STARTUP', 'REQUIRED_POST_ACTION_READBACK', 'ORDINARY_LOGOUT_ORDER', 'PUBLIC_LOGOUT_REQUIRED_HOME', 'PRIVATE_PAIR_COUNT_ORDER', 'SOURCE_LABEL_COMPOSITION', 'GENUINE_DUAL_HOST_GATE_NATIVE', 'HANDOFF_CURRENT_PUBLIC_READBACK', 'READY_NATIVE_PARENT_CHILD_SOURCE_JOIN', 'REAL_PROCESS_GROUP_JOIN', 'WRITER_PRIVATE_DESCRIPTOR_ONLY', 'SOURCE_BOUND_PREPARED_NATIVE', 'PREPARED_MENUS_NATIVE_SOURCE_JOIN', 'PREPARED_OBSERVER_ONLY_SESSION_EXPIRY_ZERO', 'PREPARED_CHILD_NATIVE_TERMINAL_JOIN', 'FROZEN_ORIGIN_CANONICAL', 'BACKEND_GATEWAY_DISTINCT', 'AUTH_PRESENT_SUBJECT_EQUIVALENCE', 'ALL_THREE_LOGIN_TOKEN_REQUIRED', 'CURRENT_THREE_SOURCE', 'EXACT_ORDERED17', 'EXACT_STEP_FACT_SCHEMA', 'FINITE_POSITIVE_IN_VIEWPORT', 'FULL_NATIVE_ROOT_SET', 'FULL_NATIVE_TABLE_SET', 'FULL_ORIGINAL_DTO_RETAINED', 'FULFILLMENT_FIXED20_STATE', 'CHILD_LOCAL_ITEM_AND_RANGE_JOIN', 'NATIVE_ATTACHMENT_SAME_ORIGIN_FULL_PNG', 'VERIFIED_PUBLIC_ATTACHMENT_REFERENCE', 'NATIVE_SEQUENCE_BARRIER', 'NATIVE_U64_BOUND', 'NONEMPTY_TRIMMED_TEXT', 'PRIVATE_STDIN_RAM_ONLY', 'PUBLIC_SECRET_FIELDS_ABSENT', 'REAL_PUBLIC_RELATIVE_TIME_INTERVAL', 'REQUEST_INSIDE_WINDOW', 'SEQUENTIAL_REQUEST_INDEX', 'CASE20_RELATIVE_HOST_SCREENSHOT_JOIN', 'CASE20_VIEWPORT_ORDER'))


def _uib_text(value):
    _uib_need(type(value) is str, 'ACTUAL_SOURCE_LABEL_STRING')
    # Exact ECMAScript WhiteSpace/LineTerminator set used by DOM producer norm().
    return re.sub('[\u0009-\u000d\u0020\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff]+',' ',value).strip(' ')


def _uib_language(key, data, index, before_ms):
    _uib_need(type(key) is str and key != '', 'SOURCE_LANGUAGE_KEY')
    context = data['_runtime']; candidates = []
    for item in context['steps']:
        if item['step']['index'] > index: continue
        if item['event']['tab_id_sha256'] != context['owned']['customer']: continue
        for request in item['event']['requests']:
            if request['method'] == 'GET' and request['path'] == '/api/get_lang_json' and request['finished_at_ms'] <= before_ms:
                result = request['response_public']
                _uib_need(type(result) is dict and len(result) == 1, 'ONE_ACTUAL_STARTUP_LANGUAGE_RANGE')
                table = next(iter(result.values()))
                _uib_need(type(table) is dict, 'ORIGINAL_NATIVE_BOUND_LANGUAGE_TABLE')
                candidates.append((request['finished_at_ms'],item['step']['index'],request['sequence'],table))
    _uib_need(candidates, 'ACTUAL_STARTUP_LANGUAGE_MAP_REQUIRED')
    candidates.sort(key=lambda row:row[:3]); table = candidates[-1][3]
    _uib_need(key in table and type(table[key]) is str and table[key] != '', 'ACTUAL_TRANSLATION_NOT_SOURCE_KEY_FALLBACK')
    return _uib_text(table[key])


def _uib_binding(name, data, history, current, allow_current=False, chain=()):
    _uib_need(type(name) is str and name in data['wire_rules']['bindings'] and name not in chain
        and len(chain) < 24, 'CLOSED_ACYCLIC_NAMED_BINDING')
    rule = data['wire_rules']['bindings'][name]
    _uib_keys(rule, ('schema','source','step','pointer','selector','requires'))
    source, step, selector = rule['source'], rule['step'], rule['selector']
    if source == 'prepared':
        _uib_need(step is None and selector is None, 'ONE_COMPLETE_PREPARED_ROOT')
        root = data['_runtime']['prepared']
    elif source == 'source-label':
        _uib_int(step,1,20); _uib_need(selector is None and step <= current, 'FINITE_SOURCE_LABEL_AFTER_NATIVE_ACK')
        root = data['wire_rules']['source_label_values']
    else:
        _uib_need(source in ('verified-step','verified-associated'), 'NO_ALTERNATE_BINDING_SOURCE')
        _uib_int(step,1,20)
        _uib_need(step < current or (allow_current and step == current), 'CURRENT_OUTPUT_NOT_CURRENT_INPUT_EXPECTATION')
        _uib_need(step <= len(history), 'ORIGINAL_VERIFIED_STEP_AVAILABLE')
        item = history[step-1]
        _uib_need(item['step']['index'] == step, 'REAL_ORIGINAL_STEP_INDEX')
        if source == 'verified-step': root = item['step']
        else:
            _uib_need(type(selector) is dict and type(selector.get('class')) is str, 'ASSOCIATED_BINDING_REQUIRES_CLASS')
            rows = [r for r in item['event']['requests'] if item['classes'].get(r['sequence']) == selector['class']
                and r['sequence'] > item['primary_sequence']]
            _uib_need(rows, 'ORIGINAL_POST_PRIMARY_ASSOCIATED_SOURCE_ROW')
            root = rows[-1]
    value = _uib_pointer(root,rule['pointer'])
    if selector is not None:
        _uib_keys(selector, ('class','array_pointer','match_pointer','equals_binding','value_pointer','unique'))
        _uib_need(selector['unique'] is True and (selector['class'] is None or type(selector['class']) is str), 'ACTUAL_UNIQUE_SELECTOR_RULE')
        rows = _uib_pointer(value,selector['array_pointer']); _uib_need(type(rows) is list, 'ORIGINAL_SELECTOR_ARRAY')
        expected = _uib_binding(selector['equals_binding'],data,history,current,allow_current,(*chain,name))
        selected = [row for row in rows if _uib_same(_uib_pointer(row,selector['match_pointer']),expected)]
        _uib_need(len(selected) == 1, 'ONE_NATIVE_BOUND_SELECTED_ROW')
        value = _uib_pointer(selected[0],selector['value_pointer'])
        if name == 'q2_child_item_id':
            package = selected[0]; original = data['_runtime']['prepared']['business']['item_quantities'][0]
            _uib_need(type(package.get('items')) is list and len(package['items']) == 1
                and package['items'][0]['quantity'] == 2 and package['items'][0]['id'] != original['item_id'], 'ACTUAL_CHILD_LOCAL_Q2_ITEM_NOT_ROOT_PK')
            quote = history[3]['wire']['response_public']['root_items']
            origins = [row['item'] for row in quote if row['item']['id'] == original['item_id']]
            _uib_need(len(origins) == 1, 'ORIGINAL_COMPLETE_Q2_SAVED_ITEM')
            _uib_need(_uib_same({k:v for k,v in package['items'][0].items() if k != 'id'},
                {k:v for k,v in origins[0].items() if k != 'id'}), 'CHILD_NATIVE_PRODUCT_SNAPSHOT_QUANTITY_JOIN')
    if source == 'source-label':
        _uib_need(type(value) is dict and value.get('kind') in ('template','enum-template')
            and type(value.get('parts')) is list and step <= len(history), 'FINITE_SOURCE_TEMPLATE_ACTUAL_ACK')
        parts = []; material = dict(history[step-1]['step'])
        material['expected_delta'] = history[step-1]['expected']
        for part in value['parts']:
            if set(part) == {'literal'}:
                _uib_need(type(part['literal']) is str, 'SOURCE_LITERAL_PART'); parts.append(part['literal'])
            elif set(part) == {'binding'}: parts.append(str(_uib_binding(part['binding'],data,history,current,True,(*chain,name))))
            elif set(part) == {'system_language_key'}:
                parts.append(_uib_language(part['system_language_key'],data,current,data['_runtime']['label_time_ms']))
            else:
                _uib_keys(part, ('pointer','enum')); actual = _uib_pointer(material,part['pointer'])
                _uib_need(type(actual) is str and type(part['enum']) is dict and actual in part['enum']
                    and type(part['enum'][actual]) is str, 'ORIGINAL_FACT_ENUM_LABEL')
                parts.append(part['enum'][actual])
        value = _uib_text(''.join(parts))
    _uib_schema_v1(value,rule['schema'],data['schemas'],relation_context=data['_runtime'])
    _uib_relations(rule['requires'],value,data['_runtime'])
    return value


def _uib_path(template, data, history, current, allow_current=False):
    _uib_need(type(template) is str and 0 < len(template) <= 2048, 'FINITE_DECLARED_PATH_TEMPLATE')
    result = template
    for name in re.findall(r'\{([a-z][a-z0-9_]*)\}',template):
        value = _uib_binding(name,data,history,current,allow_current)
        if name == 'public_attachment_ref':
            customer, paid_root, q2 = _uib_review_parents(data['_runtime']['prepared']['business'])
            _uib_need(template == '{public_attachment_ref}' and type(value) is str
                and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',value),
                'SOURCE_VERIFIED_EXACT_CUSTOMER_PUBLIC_ATTACHMENT_PATH')
            text = value
        else:
            _uib_need(type(value) in (int,str) and re.fullmatch('[A-Za-z0-9_-]+',str(value)), 'EXACT_SAFE_COMPONENT_ENCODING')
            text = str(value)
        result = result.replace('{'+name+'}',text)
    _uib_need(result.startswith('/') and not any(x in result for x in ('{','}','://','..','#'))
        and not any(ord(ch) == 0 or ch == chr(92) for ch in result), 'COMPLETE_EXACT_RESOLVED_PATH')
    return result


def _uib_label(rule, data, index, before_ms):
    if rule is None: return None
    _uib_need(type(rule) is dict, 'CLOSED_VISIBLE_LABEL_FORM')
    if rule.get('kind') == 'literal':
        _uib_keys(rule, ('kind','value')); _uib_need(type(rule['value']) is str and rule['value'] != '', 'SOURCE_LITERAL_LABEL')
        value = rule['value']
    elif rule.get('kind') == 'system-language':
        _uib_keys(rule, ('kind','key')); value = _uib_language(rule['key'],data,index,before_ms)
    else:
        _uib_keys(rule, ('kind','binding')); _uib_need(rule['kind'] == 'native-bound', 'NO_FOURTH_LABEL_KIND')
        data['_runtime']['label_time_ms'] = before_ms
        value = _uib_binding(rule['binding'],data,data['_runtime']['steps'],index,True)
        _uib_need(type(value) is str and value != '', 'FINITE_NATIVE_BOUND_VISIBLE_TEXT')
    return _uib_text(value)


def _uib_pointer(value, pointer):
    _uib_need(type(pointer) is str and (pointer == '' or pointer.startswith('/'))
        and len(pointer) <= 4096, 'LOCAL_JSON_POINTER')
    if pointer == '': return value
    for encoded in pointer[1:].split('/'):
        _uib_need(re.search(r'~(?![01])',encoded) is None, 'CANONICAL_JSON_POINTER_ESCAPE')
        key = encoded.replace('~1','/').replace('~0','~')
        if type(value) is list:
            _uib_need(re.fullmatch('0|[1-9][0-9]*',key) is not None
                and int(key) < len(value), 'JSON_POINTER_ACTUAL_ARRAY_MEMBER')
            value = value[int(key)]
        else:
            _uib_need(type(value) is dict and key in value, 'JSON_POINTER_ACTUAL_OBJECT_MEMBER')
            value = value[key]
    return value


def _uib_schema_source(schema, definitions, seen=(), depth=0):
    # Validate the complete closed finite grammar before matching oneOf alternatives.
    # Unsupported schema cannot hide in a branch that happens not to be selected.
    _uib_need(type(schema) is dict and depth <= 64, 'CLOSED_SCHEMA_SOURCE')
    allowed = {'$ref','type','properties','required','additionalProperties','items','minItems','maxItems',
        'uniqueItems','minimum','maximum','exclusiveMinimum','minLength','maxLength','pattern','oneOf',
        'propertyNames','const','enum','x-relations'}
    _uib_need(set(schema) <= allowed, 'UNSUPPORTED_SCHEMA_REQUIRES_SOURCE_REVIEW')
    if 'x-relations' in schema:
        relations = schema['x-relations']
        _uib_need(type(relations) is list and all(type(r) is str and r in UI_BUSINESS_RELATIONS for r in relations)
            and len(relations) == len(set(relations)), 'CLOSED_FINITE_SCHEMA_RELATION_IDS')
    if '$ref' in schema:
        _uib_need(set(schema) <= {'$ref','x-relations'}, 'LOCAL_REFERENCE_WITH_RELATIONS_ONLY')
        ref = schema['$ref']
        _uib_need(type(ref) is str and re.fullmatch('#/schemas/[A-Za-z][A-Za-z0-9_-]*',ref)
            and type(definitions) is dict and ref[10:] in definitions, 'DEFINED_LOCAL_SCHEMA_REFERENCE')
        if ref not in seen: _uib_schema_source(definitions[ref[10:]],definitions,(*seen,ref),depth+1)
        return
    if 'type' in schema:
        kinds = schema['type'] if type(schema['type']) is list else [schema['type']]
        _uib_need(kinds and all(type(k) is str and k in ('object','array','string','integer','number','boolean','null') for k in kinds)
            and len(kinds) == len(set(kinds)), 'FINITE_DECLARED_SCHEMA_TYPE')
    _uib_need(any(k in schema for k in ('type','const','enum','oneOf')), 'NO_UNCONSTRAINED_SCHEMA')
    if 'enum' in schema:
        _uib_need(type(schema['enum']) is list and 1 <= len(schema['enum']) <= 256, 'FINITE_SCHEMA_ENUM')
        _uib_need(len({_uib_canon(x) for x in schema['enum']}) == len(schema['enum']), 'UNIQUE_TYPED_ENUM_MEMBERS')
    if 'oneOf' in schema:
        _uib_need(type(schema['oneOf']) is list and 1 <= len(schema['oneOf']) <= 32, 'FINITE_SCHEMA_ONE_OF')
        for child in schema['oneOf']: _uib_schema_source(child,definitions,seen,depth+1)
    if 'properties' in schema:
        _uib_need(type(schema['properties']) is dict and all(type(k) is str for k in schema['properties']), 'SOURCE_SCHEMA_PROPERTY_NAMES')
        for child in schema['properties'].values(): _uib_schema_source(child,definitions,seen,depth+1)
    if 'required' in schema:
        _uib_need(type(schema['required']) is list and all(type(k) is str for k in schema['required'])
            and len(schema['required']) == len(set(schema['required']))
            and set(schema['required']) <= set(schema.get('properties',{})), 'SOURCE_REQUIRED_PROPERTIES')
    if 'additionalProperties' in schema:
        _uib_need(schema['additionalProperties'] is False or type(schema['additionalProperties']) is dict, 'NO_UNTYPED_ADDITIONAL_PROPERTIES')
        if type(schema['additionalProperties']) is dict: _uib_schema_source(schema['additionalProperties'],definitions,seen,depth+1)
    if 'propertyNames' in schema: _uib_schema_source(schema['propertyNames'],definitions,seen,depth+1)
    if 'items' in schema: _uib_schema_source(schema['items'],definitions,seen,depth+1)
    for k in ('minItems','maxItems','minLength','maxLength'):
        if k in schema: _uib_int(schema[k])
    for k in ('minimum','maximum','exclusiveMinimum'):
        if k in schema: _uib_num(schema[k])
    if 'uniqueItems' in schema: _uib_need(type(schema['uniqueItems']) is bool, 'ACTUAL_BOOLEAN_SCHEMA_UNIQUE')
    if 'pattern' in schema: _uib_need(type(schema['pattern']) is str and len(schema['pattern']) <= 4096, 'FINITE_SCHEMA_PATTERN')
    if schema.get('type') == 'object':
        _uib_need('additionalProperties' in schema, 'CLOSED_OR_TYPED_OBJECT_MEMBERS')
    if schema.get('type') == 'array': _uib_need('items' in schema, 'TYPED_ARRAY_ITEMS')


def _uib_schema_v1(value, schema, definitions, depth=0, relation_context=None):
    _uib_need(type(schema) is dict and depth <= 64, 'FINITE_TYPED_VALUE_DEPTH')
    if depth == 0: _uib_schema_source(schema,definitions)
    if '$ref' in schema:
        _uib_schema_v1(value,definitions[schema['$ref'][10:]],definitions,depth+1,relation_context)
    else:
        if 'const' in schema: _uib_need(_uib_same(value,schema['const']), 'EXACT_TYPED_SCHEMA_CONST')
        if 'enum' in schema: _uib_need(any(_uib_same(value,x) for x in schema['enum']), 'EXACT_TYPED_SCHEMA_ENUM')
        if 'oneOf' in schema:
            matched = 0
            for child in schema['oneOf']:
                checkpoint = len(relation_context['relation_uses']) if relation_context is not None else 0
                try:
                    _uib_schema_v1(value,child,definitions,depth+1,relation_context)
                    matched += 1
                except RuntimeError as error:
                    if not str(error).startswith('UI_BUSINESS_'): raise
                    if relation_context is not None: del relation_context['relation_uses'][checkpoint:]
            _uib_need(matched == 1, 'EXACTLY_ONE_COMPLETE_TYPED_SCHEMA_ALTERNATIVE')
        kind = schema.get('type')
        if type(kind) is list:
            types = {'object':dict,'array':list,'string':str,'integer':int,'boolean':bool,'null':type(None)}
            matches = [k for k in kind if (type(value) in (int,float) if k == 'number' else type(value) is types[k])]
            _uib_need(matches, 'ACTUAL_DECLARED_TYPE_UNION'); kind = matches[0]
        if kind == 'object':
            _uib_need(type(value) is dict, 'ACTUAL_TYPED_OBJECT')
            properties = schema.get('properties',{}); required = schema.get('required',[])
            _uib_need(all(k in value for k in required), 'ALL_REQUIRED_TYPED_OBJECT_MEMBERS')
            extra = schema['additionalProperties']
            for key,child in value.items():
                _uib_need(type(key) is str, 'ACTUAL_JSON_OBJECT_KEY')
                if 'propertyNames' in schema: _uib_schema_v1(key,schema['propertyNames'],definitions,depth+1,relation_context)
                if key in properties: target = properties[key]
                else:
                    _uib_need(type(extra) is dict, 'NO_UNKNOWN_OBJECT_MEMBER'); target = extra
                _uib_schema_v1(child,target,definitions,depth+1,relation_context)
        elif kind == 'array':
            _uib_need(type(value) is list and schema.get('minItems',0) <= len(value) <= schema.get('maxItems',100000), 'ACTUAL_BOUNDED_TYPED_ARRAY')
            if schema.get('uniqueItems') is True: _uib_need(len({_uib_canon(x) for x in value}) == len(value), 'UNIQUE_TYPED_ARRAY_ITEMS')
            for child in value: _uib_schema_v1(child,schema['items'],definitions,depth+1,relation_context)
        elif kind == 'string':
            _uib_need(type(value) is str and schema.get('minLength',0) <= len(value) <= schema.get('maxLength',268435456), 'ACTUAL_BOUNDED_STRING')
            if 'pattern' in schema: _uib_need(re.search(schema['pattern'],value) is not None, 'SOURCE_STRING_PATTERN')
        elif kind == 'integer': _uib_int(value,-9007199254740991)
        elif kind == 'number': _uib_num(value)
        elif kind == 'boolean': _uib_need(type(value) is bool, 'ACTUAL_SCHEMA_BOOLEAN')
        elif kind == 'null': _uib_need(value is None, 'ACTUAL_SCHEMA_NULL')
        if 'minimum' in schema and type(value) in (int,float): _uib_need(value >= schema['minimum'], 'SOURCE_VALUE_MINIMUM')
        if 'maximum' in schema and type(value) in (int,float): _uib_need(value <= schema['maximum'], 'SOURCE_VALUE_MAXIMUM')
        if 'exclusiveMinimum' in schema and type(value) in (int,float): _uib_need(value > schema['exclusiveMinimum'], 'SOURCE_VALUE_EXCLUSIVE_MINIMUM')
    if schema.get('x-relations'):
        _uib_need(type(relation_context) is dict, 'ACTUAL_RELATION_CONTEXT_REQUIRED')
        _uib_relations(schema['x-relations'],value,relation_context)


UI_BUSINESS_RELATION_KINDS = frozenset(('URL-join', 'auth-fields', 'authenticated-join', 'canonical-hash', 'canonical-origin', 'complete-preservation', 'complete-set', 'decimal-bound', 'deep-equal', 'descriptor-join', 'descriptor-only', 'distinct', 'exact-count', 'finite-classification', 'finite-template', 'fixed-members', 'fixed-order', 'fixed-quantities', 'fixed-state', 'genuine-dom-join', 'host-byte-readback', 'host-native-join', 'join', 'list-order', 'minimum-reads', 'named-two-branch', 'native-file-join', 'native-frame-join', 'native-hash-join', 'native-process-join', 'native-source-join', 'natural-observation', 'numeric-bounds', 'one-field-change', 'paired-count', 'path-byte-join', 'private-boundary', 'private-join', 'process-fields', 'safe-projection', 'schema-by-index', 'secret-rejection', 'sequence-order', 'source-pins', 'step-private-phase', 'text', 'time-join', 'time-order', 'unique-current-request'))

def _uib_data_contract(value, auxiliary):
    _uib_keys(value, ('schema','scope','version','php_sources','auxiliary_roles','steps','wire_rules','dom_rules','viewports','schemas'))
    _uib_need(type(value['schema']) is int and value['schema'] == 1 and type(value['version']) is int and value['version'] == 1
        and value['scope'] == 'MS4_UI_BUSINESS_CONSUMER_CONTRACT' and _uib_same(value['viewports'],[320,390,1440]), 'ACTUAL_FINAL_SHARED_DATA_V1')
    _uib_keys(value['php_sources'],UI_BUSINESS_PHP)
    for descriptor in value['php_sources'].values():
        _uib_keys(descriptor, ('bytes','sha256')); _uib_int(descriptor['bytes'],1); _uib_hex(descriptor['sha256'])
    expected = {'reader':'reader/ms4-ui-private-reader.mjs','caller':'ms4-ui-private-caller.mjs',
        'browser_close':'ms4-ui-browser-close.mjs','business_producer':'ms4-ui-business-producer.mjs',
        'observer_bridge':'ms4-ui-window-native-bridge.mjs','network_capture':'ms4-ui-network-capture.mjs','dom_producer':'ms4-ui-dom-producer.mjs'}
    _uib_need(_uib_same(value['auxiliary_roles'],expected), 'EXACT_SEVEN_REAL_SOURCE_ROLES')
    _uib_need(type(value['schemas']) is dict and type(value['wire_rules']) is dict and type(value['dom_rules']) is dict
        and type(value['steps']) is list and len(value['steps']) == 20, 'COMPLETE_FINAL_SOURCE_REGISTRY')
    rules = value['wire_rules']; _uib_need(set(rules['relations']) == UI_BUSINESS_RELATIONS, 'EXACT_FIFTY_SEVEN_CLOSED_RELATION_IDS')
    for name,rule in rules['relations'].items():
        _uib_keys(rule, ('kind','operands','rule','source'))
        _uib_need(rule['kind'] in UI_BUSINESS_RELATION_KINDS and type(rule['operands']) is list
            and all(type(v) is str for v in rule['operands']) and type(rule['rule']) is str and type(rule['source']) is str, 'FINITE_SOURCE_RELATION_DATA_NOT_EXECUTABLE')
    for schema in value['schemas'].values(): _uib_schema_source(schema,value['schemas'])
    for name,rule in rules['bindings'].items():
        _uib_need(type(name) is str and re.fullmatch('[a-zA-Z][a-zA-Z0-9_]*',name), 'FINITE_BINDING_NAME')
        _uib_keys(rule, ('schema','source','step','pointer','selector','requires'))
        _uib_need(rule['source'] in ('prepared','verified-step','verified-associated','source-label') and type(rule['pointer']) is str
            and (rule['pointer'] == '' or rule['pointer'].startswith('/')) and type(rule['requires']) is list
            and all(v in UI_BUSINESS_RELATIONS for v in rule['requires']), 'EXACT_BINDING_SIX_SOURCE_INTERFACE')
        _uib_schema_source(rule['schema'],value['schemas'])
        if rule['selector'] is not None:
            _uib_keys(rule['selector'], ('class','array_pointer','match_pointer','equals_binding','value_pointer','unique'))
            _uib_need(rule['selector']['unique'] is True and rule['selector']['equals_binding'] in rules['bindings'], 'EXACT_UNIQUE_SOURCE_SELECTOR')
    for index,step in enumerate(value['steps'],1):
        _uib_keys(step, ('index','name','role','primary','bindings','associated','facts','page','probes','screenshot_policy'))
        _uib_need(type(step['index']) is int and step['index'] == index and step['name'] == UI_BUSINESS_STEPS[index-1]
            and step['role'] == UI_BUSINESS_ROLES[index-1], 'EXACT_ORIGINAL_TWENTY_CASES')
        _uib_keys(step['primary'], ('method','path_template','request_schema','response_schema'))
        _uib_need(step['primary']['method'] in ('GET','POST','PUT') and type(step['primary']['path_template']) is str
            and type(step['bindings']) is list and all(n in rules['bindings'] for n in step['bindings'])
            and type(step['probes']) is list and step['probes'] and step['screenshot_policy'] in ('omit','capture-if-safe'), 'CLOSED_CASE_SOURCE_CONTRACT')
        for schema in (step['facts'],step['primary']['request_schema'],step['primary']['response_schema']): _uib_schema_source(schema,value['schemas'])
        _uib_keys(step['associated'], ('classes','required','relations'))
        _uib_need(type(step['associated']['classes']) is list and all(n in rules['associated_classes'] for n in step['associated']['classes'])
            and type(step['associated']['required']) is dict and all(n in UI_BUSINESS_RELATIONS for n in step['associated']['relations']), 'FINITE_CASE_ASSOCIATED_SOURCE_SET')
        _uib_need(type(step['page']) is dict and type(step['page']['name']) is str
            and step['page']['kind'] in ('normal','authentication','private-preview'), 'ACTUAL_PHASE_PAGE_CATALOG')
        keys = set()
        for probe in step['probes']:
            _uib_keys(probe, ('key','kind','label','candidate','evidence','locator_policy'))
            _uib_need(type(probe['key']) is str and probe['key'] not in keys and probe['kind'] in ('button','input','status','link','image','text')
                and type(probe['candidate']) is dict and type(probe['evidence']) is list and probe['locator_policy'] == 'FRESH_DOM_REQUIRED', 'SOURCE_HINT_NOT_ACTUAL_CONTROL')
            keys.add(probe['key']); candidate = probe['candidate']
            _uib_keys(candidate, ('source_member','marker','actual_selector','actual_parent_selector','kind_requires_fresh_check'))
            _uib_need(candidate['actual_selector'] is None and candidate['actual_parent_selector'] is None
                and candidate['kind_requires_fresh_check'] is True, 'NO_SOURCE_SELECTOR_INJECTION')
            if probe['kind'] in ('input','image'): _uib_need(probe['label'] is None, 'NO_INPUT_VALUE_OR_PRIVATE_IMAGE_LABEL')
    result = _uib_data_json(json.dumps(value,ensure_ascii=False,allow_nan=False).encode('utf8')); result['_aux'] = auxiliary
    result['_runtime'] = {'data':result,'relation_uses':[],'index':0,'steps':[]}
    return result


def _uib_source(context_file, expected_binding, reads):
    _uib_keys(UI_BUSINESS_AUX_PINS, ('bytes','sha256'))
    _uib_int(UI_BUSINESS_AUX_PINS['bytes'],1); _uib_hex(UI_BUSINESS_AUX_PINS['sha256'])
    context_raw = _uib_stable(context_file,reads); context = _uib_json(context_raw)
    _uib_need(type(context) is dict and context['run_id'] == expected_binding['run_id']
        and context['source_sha'] == expected_binding['source_sha'] and context['freeze_sha256'] == expected_binding['freeze_sha256']
        and sha(context_raw) == expected_binding['context_sha256'], 'ORIGINAL_CONTEXT_IDENTITY')
    root = Path(context['run_dir']); prep = Path(context['prep_dir'])
    _uib_need(Path(context_file) == root/'actual-run-context.json' and root.name == context['run_id']
        and prep == ROOT and root.is_absolute() and prep.is_absolute(), 'ACTUAL_CONTEXT_CANONICAL_PROFILE')
    _uib_need(re.fullmatch('[0-9]{14}[a-f0-9]{6}',context['run_id']) is not None, 'ORIGINAL_RUN_ID')
    _uib_hex(context['source_sha'],40)
    for key in ('freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256'): _uib_hex(expected_binding[key])
    _uib_need(expected_binding['backend_cid'] != expected_binding['gateway_cid'], 'DISTINCT_ORIGINAL_BACKEND_GATEWAY')
    freeze_raw = _uib_stable(root/'preparation-source-binding.json',reads); freeze = _uib_json(freeze_raw)
    _uib_need(sha(freeze_raw) == expected_binding['freeze_sha256'] and freeze['status'] == 'CLEAN_SOURCE_FROZEN_ONLY'
        and freeze['source_sha'] == expected_binding['source_sha'] and type(freeze['artifact_files']) is list
        and [r['path'] for r in freeze['artifact_files']] == list(UI_BUSINESS_ARTIFACTS), 'ACTUAL_P17_FROZEN_SOURCE')
    _uib_int(freeze['ui_web_port'],1024,65535)
    _uib_need(expected_binding['origin'] == freeze['ui_web_origin'] == 'http://127.0.0.1:'+str(freeze['ui_web_port']), 'ACTUAL_FIXED_LOOPBACK_ORIGIN')
    artifacts = {}
    for row in freeze['artifact_files']:
        _uib_keys(row, ('path','bytes','sha256')); raw = _uib_stable(prep/row['path'],reads)
        _uib_need(_uib_pin(raw) == {'bytes':row['bytes'],'sha256':row['sha256']}, 'EVERY_FROZEN_P17_ORIGINAL_BYTE')
        artifacts[row['path']] = _uib_pin(raw)
    fixture = _uib_stable(prep/'ms4-order.png',reads); pixels = _uib_png(fixture)
    _uib_need(len(fixture) == 234 and pixels == {'width':96,'height':96}, 'AUTHORITATIVE_234_BYTE_96_PNG')
    auxiliary_root = prep.parent/'browser-v1'
    manifest_raw = _uib_stable(auxiliary_root/'PINS.json',reads)
    _uib_need(_uib_pin(manifest_raw) == UI_BUSINESS_AUX_PINS, 'REAL_LITERAL_AUXILIARY_PINS_REQUIRED')
    manifest = _uib_json(manifest_raw); _uib_keys(manifest, ('members',)); _uib_keys(manifest['members'],UI_BUSINESS_AUX_MEMBERS)
    auxiliary = manifest['members']
    for relative, descriptor in auxiliary.items():
        _uib_keys(descriptor, ('bytes','sha256')); _uib_int(descriptor['bytes'],1); _uib_hex(descriptor['sha256'])
        _uib_need(_uib_pin(_uib_stable(auxiliary_root/relative,reads)) == descriptor, 'ALL_EIGHT_ORIGINAL_AUX_SOURCE_BYTES')
    data_raw = _uib_stable(auxiliary_root/'ui-business-consumer-contract.json',reads)
    raw_data = _uib_data_json(data_raw)
    if 'task2' in raw_data:
        _uib_keys(raw_data, ('schema','scope','version','php_sources','auxiliary_roles','steps','wire_rules','dom_rules','viewports','schemas','task2'))
        data = _uib_data_contract({k:v for k,v in raw_data.items() if k != 'task2'},auxiliary)
        data['task2'] = raw_data['task2']
    else:
        data = _uib_data_contract(raw_data,auxiliary)
    data['_task2_enabled'] = 'task2_observer_source' in freeze
    _uib_need(not data['_task2_enabled'] or ('task2' in data and 'task2_browser_source' in freeze), 'INITIAL_TASK2_REQUIRES_FULL_REGISTRY_AND_BROWSER_GROUP')
    closure = {'context':_uib_pin(context_raw),'freeze':_uib_pin(freeze_raw),'artifacts':artifacts,
        'reader':auxiliary['reader/ms4-ui-private-reader.mjs'],'caller':auxiliary['ms4-ui-private-caller.mjs'],
        'browser_close':auxiliary['ms4-ui-browser-close.mjs']}
    binding = dict(expected_binding); binding['source_closure'] = closure
    _uib_keys(binding, ('run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin','source_closure'))
    _uib_need(type(freeze['ui_backend_source_files']) is list, 'FULL_BACKEND_SOURCE_INVENTORY')
    repository = Path(context['repo_dir']); backend_rows = {}
    for row in freeze['ui_backend_source_files']:
        _uib_keys(row, ('path','bytes','sha256')); relative = row['path']
        _uib_need(type(relative) is str and not relative.startswith('/') and not any(t in relative for t in ('\\','\0',':'))
            and all(p not in ('','..','.') for p in relative.split('/')) and relative not in backend_rows, 'EXACT_BACKEND_SOURCE_MEMBER')
        actual = _uib_pin(_uib_stable(repository/relative,reads))
        _uib_need(actual == {'bytes':row['bytes'],'sha256':row['sha256']}, 'COMPLETE_ACTUAL_BACKEND_SOURCE_READBACK')
        backend_rows[relative] = actual
    for relative in UI_BUSINESS_PHP: _uib_need(backend_rows.get(relative) == data['php_sources'][relative], 'CURRENT_THREE_PHP_SOURCE_NOT_FUTURE_HASH')
    runtime = data['_runtime']; runtime.update({'root':str(root),'reads':reads,'binding':binding,'context':context,'freeze':freeze,'backend_rows':backend_rows})
    ready_raw = _uib_stable(root/'evidence/ui-web-control-ui-backend-ready.json',reads); ready = _uib_json(ready_raw); runtime['ready'] = ready
    _uib_need(sha(ready_raw) == binding['ready_sha256'] and ready['run_id'] == binding['run_id']
        and ready['source_sha'] == binding['source_sha'] and ready['freeze_sha256'] == binding['freeze_sha256']
        and ready['container_id'] == binding['backend_cid'] and ready['scope'] == 'MS4_UI_BACKEND_READY', 'SAME_ORIGINAL_READY_GENERATION')
    _uib_need('ready' in data['schemas'], 'FULL_READY_TYPED_SCHEMA_MISSING')
    _uib_schema_v1(ready,{'$ref':'#/schemas/ready'},data['schemas'],relation_context=runtime)
    handoff_raw = _uib_stable(root/'evidence/ui-private-caller-handoff.json',reads); handoff = _uib_json(handoff_raw); runtime['handoff'] = handoff
    _uib_keys(handoff, ('schema','scope','run_id','source_sha','freeze_sha256','container_id','source_closure','reader_inputs','ready','host_gates','private_reader_returned_three_roles','form_login_business_DOM','full_acceptance'))
    _uib_need('handoff' in data['schemas'], 'FULL_HANDOFF_HOST_GATE_SCHEMA_MISSING')
    _uib_schema_v1(handoff,{'$ref':'#/schemas/handoff'},data['schemas'],relation_context=runtime)
    reduced = {k:v for k,v in closure.items() if k != 'browser_close'}
    _uib_need(type(handoff['schema']) is int and handoff['schema'] == 1 and handoff['scope'] == 'MS4_UI_PRIVATE_CALLER_HANDOFF_ONLY'
        and handoff['run_id'] == binding['run_id'] and handoff['source_sha'] == binding['source_sha']
        and handoff['freeze_sha256'] == binding['freeze_sha256'] and handoff['container_id'] == binding['backend_cid']
        and _uib_same(handoff['source_closure'],reduced) and handoff['private_reader_returned_three_roles'] is True
        and handoff['form_login_business_DOM'] == 'NOT RUN' and handoff['full_acceptance'] == 'INCOMPLETE', 'GENUINE_SAME_RUN_CALLER_SOURCE_HANDOFF')
    for field, name in (('reader_inputs','ui-web-private-inputs.json'),('ready','ui-web-control-ui-backend-ready.json')):
        _uib_leaf(root,handoff[field],name,reads,False)
    gateway = _uib_json(_uib_stable(root/'evidence/ui-gateway-created.json',reads))
    _uib_need(gateway['id'] == binding['gateway_cid'] and gateway['run_id'] == binding['run_id']
        and gateway['source_sha'] == binding['source_sha'] and gateway['proof_name'] == 'ui-gateway'
        and gateway['labels']['com.docker.compose.service'] == 'audit-ms4-ui-gateway', 'ORIGINAL_OWNED_GATEWAY_JOIN')
    prepared_raw = _uib_stable(root/'evidence/ms4-ui-prepare.json',reads); prepared = _uib_json(prepared_raw); runtime['prepared'] = prepared
    _uib_need(type(prepared['schema']) is int and prepared['schema'] == 1 and prepared['scope'] == 'NORMAL_MS4_UI_PREPARATION_ONLY'
        and prepared['status'] == 'VERIFIED_NORMAL_MS4_UI_PREPARATION_ONLY' and prepared['run_id'] == binding['run_id']
        and prepared['source_sha'] == binding['source_sha'] and prepared['freeze_sha256'] == binding['freeze_sha256']
        and type(prepared['entry_return_code']) is int and prepared['entry_return_code'] == 0 and prepared['termination'] == 'NORMAL_RETURN'
        and prepared['diagnostic'] is None and prepared['full_acceptance'] == 'INCOMPLETE', 'ORIGINAL_REAL_PREPARE_NOT_SYNTHETIC_EXPECTED_IDS')
    _uib_need('prepared' in data['schemas'], 'FULL_PREPARED_TYPED_SCHEMA_MISSING')
    _uib_schema_v1(prepared,{'$ref':'#/schemas/prepared'},data['schemas'],relation_context=runtime)
    business = prepared['business']; _uib_need(type(business) is dict and business['prepared_only'] is True, 'ACTUAL_PAID_PREPARATION_ONLY')
    for key in ('paid_order_id','merchant_id','customer_id'): _uib_int(business[key],1)
    _uib_keys(business['actor_ids'], ('customer','merchant','platform'))
    for actor in business['actor_ids'].values(): _uib_int(actor,1)
    _uib_need(business['actor_ids'] == ready['actor_ids'] and business['actor_ids']['customer'] == business['customer_id'], 'ALL_PREPARED_NATIVE_ROLE_IDS')
    _uib_hex(business['source_fingerprint']); items = business['item_quantities']
    _uib_need(type(items) is list and len(items) == 2 and [r['quantity'] for r in items] == [2,1], 'SAVED_Q2_Q1_NATIVE_QUANTITIES')
    for row in items: _uib_keys(row, ('item_id','quantity')); _uib_int(row['item_id'],1); _uib_int(row['quantity'],1)
    _uib_need(items[0]['item_id'] != items[1]['item_id'], 'TWO_DISTINCT_ORIGINAL_ITEMS')
    return context, root, binding, data, business, backend_rows


def _uib_preservation(root, records, binding, final, backend_rows, context, reads, data):
    _uib_keys(records, ('source','predecessors','private_inputs','public_materials'))
    names = {'source':'ui-window-source-preserved.json','predecessors':'ui-window-predecessors-preserved.json',
        'private_inputs':'ui-window-private-inputs-preserved.json','public_materials':'ui-window-public-materials-preserved.json'}
    scopes = {'source':'MS4_UI_WINDOW_SOURCE_PRESERVED','predecessors':'MS4_UI_WINDOW_PREDECESSORS_PRESERVED',
        'private_inputs':'MS4_UI_WINDOW_PRIVATE_INPUTS_PRESERVED','public_materials':'MS4_UI_WINDOW_PUBLIC_MATERIALS_PRESERVED'}
    for category in names:
        expected = final['receipt']['preservation'][category]
        actual = _uib_leaf(root,records[category],names[category],reads)
        _uib_need(_uib_same(actual,expected), 'PRESERVATION_ORIGINAL_FINISH_PAYLOAD')
        _uib_keys(actual, ('schema','scope','binding','files'))
        _uib_need(type(actual['schema']) is int and actual['schema'] == 1 and actual['scope'] == scopes[category]
            and _uib_same(actual['binding'],binding), 'PRESERVATION_NATIVE_SOURCE_IDENTITY')
        files = actual['files']
        if category == 'source':
            _uib_need(type(files) is list and len(files) == len(backend_rows), 'COMPLETE_NATIVE_SOURCE_PRESERVATION')
            found = {}
            for descriptor in files:
                _uib_desc(descriptor,empty=True); logical = descriptor['path']
                _uib_need(logical.startswith('/workspace/') and logical[len('/workspace/'):] in backend_rows and logical not in found, 'ONLY_FROZEN_BACKEND_SOURCE_PATH')
                relative = logical[len('/workspace/'):]
                _uib_need({k:descriptor[k] for k in ('bytes','sha256')} == backend_rows[relative]
                    and _uib_pin(_uib_stable(Path(context['repo_dir'])/relative,reads)) == backend_rows[relative], 'ORIGINAL_BACKEND_BYTES_STILL_PRESERVED')
                found[relative] = descriptor
            _uib_need(set(found) == set(backend_rows), 'ALL_SOURCE_MEMBERS_PRESERVED')
        elif category == 'predecessors':
            _uib_need(type(files) is list and files, 'ORIGINAL_KEPT_PREDECESSORS')
            source_binding = _uib_json(_uib_stable(root/'evidence/ms4-ui-backend-source-binding.json',reads))
            original = source_binding['prerequisites_sha256']; _uib_need(type(original) is dict, 'ORIGINAL_SOURCE_PREREQUISITES')
            found = {}
            for descriptor in files:
                _uib_desc(descriptor); prefix = '/run/audit/evidence/'
                _uib_need(descriptor['path'].startswith(prefix), 'PREDECESSOR_FIXED_PUBLIC_DIRECTORY')
                leaf = descriptor['path'][len(prefix):]
                _uib_need(leaf in original and leaf not in found and descriptor['sha256'] == original[leaf], 'KEPT_ORIGINAL_PREREQUISITE_IDENTITY')
                _uib_leaf(root,descriptor,leaf,reads,False); found[leaf] = descriptor
            _uib_need(set(found) == set(original), 'COMPLETE_ORIGINAL_PREDECESSORS')
        elif category == 'private_inputs':
            _uib_keys(files, ('/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence'))
            for path, tree in files.items():
                _uib_tree(tree); _uib_need(_uib_same(tree,final['receipt']['final']['files']['roots'][path]), 'PRIVATE_TREE_FULL_FINAL_NATIVE_READBACK_NO_PRIVATE_BODIES')
        else:
            raw = _uib_stable(root/'evidence/ui-public-materials.json',reads); material = _uib_json(raw)
            source_binding = _uib_json(_uib_stable(root/'evidence/ms4-ui-backend-source-binding.json',reads))
            _uib_desc(source_binding['public_materials'],'/run/audit/evidence/ui-public-materials.json')
            _uib_need(_uib_pin(raw) == {k:source_binding['public_materials'][k] for k in ('bytes','sha256')}
                and _uib_same(material,files), 'ORIGINAL_COMPLETE_PUBLIC_MATERIALS_INLINE_NOT_DESCRIPTOR')
            _uib_schema_v1(material,{'$ref':'#/schemas/public_materials_complete'},data['schemas'],relation_context=data['_runtime'])
            _uib_need(all(material[k] == binding[k] for k in ('run_id','source_sha','freeze_sha256')), 'SAME_ORIGINAL_PUBLIC_MATERIALS_BINDING')
            before = data['_runtime']['native']['frames'][0]['value']['receipt']['baseline']['files']['roots']['/workspace/crmeb/public']
            actual_files = {name:node for name,node in before.items() if node['kind'] == 'file'}
            actual_directories = {name for name,node in before.items() if name and node['kind'] == 'directory'}
            original = {}
            for descriptor in material['files']:
                _uib_desc(descriptor,empty=True); relative = descriptor['path']
                _uib_need(relative not in original and relative in actual_files
                    and all(actual_files[relative][k] == descriptor[k] for k in ('bytes','sha256')), 'EVERY_ORIGINAL_PUBLIC_FILE_NATIVE_BYTES')
                original[relative] = descriptor
            _uib_need(set(original) == set(actual_files) and set(material['directories']) == actual_directories,
                'COMPLETE_ORIGINAL_PUBLIC_FILE_DIRECTORY_SET')
            final_tree = final['receipt']['final']['files']['roots']['/workspace/crmeb/public']
            _uib_tree(before); _uib_tree(final_tree)
            selected = data['_runtime']['steps'][13]['state']['image']
            customer, paid_root, q2 = _uib_review_parents(data['_runtime']['prepared']['business'])
            reference = selected['reference']
            _uib_need(type(reference) is str and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',reference)
                and selected['url'] == binding['origin']+reference
                and selected['image'] == binding['source_closure']['artifacts']['ms4-order.png'], 'PRESERVED_SELECTED_REAL_CUSTOMER_PNG')
            image = reference[1:]; parents = set(); current = image
            while '/' in current:
                current = current.rsplit('/',1)[0]; parents.add(current)
            original_directories = actual_directories|{''}
            extra_files = {path for path,node in final_tree.items() if node['kind'] == 'file'}-set(actual_files)
            extra_directories = {path for path,node in final_tree.items() if node['kind'] == 'directory'}-original_directories
            _uib_need(image not in before and extra_files == {image} and extra_directories == parents-original_directories
                and all(parent in final_tree and final_tree[parent]['kind'] == 'directory' for parent in parents),
                'ONLY_SELECTED_PNG_AND_EXACT_MISSING_PARENT_CHAIN')
            for path,node in actual_files.items():
                _uib_need(path in final_tree and _uib_same(node,final_tree[path]), 'EVERY_PREEXISTING_PUBLIC_FILE_COMPLETE_NATIVE_IDENTITY_BYTES_UNCHANGED')
            for path in original_directories:
                _uib_need(path in final_tree and final_tree[path]['kind'] == 'directory', 'EVERY_PREEXISTING_EMPTY_PUBLIC_DIRECTORY_RETAINED')
                if path in parents:
                    added = sum(1 for child in extra_directories if (child.rsplit('/',1)[0] if '/' in child else '') == path)
                    _uib_need(all(before[path][field] == final_tree[path][field] for field in ('kind','dev','ino','mode','uid','gid'))
                        and final_tree[path]['nlink'] == before[path]['nlink']+added, 'EXACT_PARENT_ONLY_NATIVE_LINK_COUNT_INCREMENT')
                else:
                    _uib_need(_uib_same(before[path],final_tree[path]), 'EVERY_OTHER_PUBLIC_DIRECTORY_COMPLETE_NATIVE_IDENTITY_UNCHANGED')
            _uib_need(final_tree[image]['bytes'] == 234
                and final_tree[image]['sha256'] == '1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8', 'ONLY_ORIGINAL_FULL_SELECTED_PUBLIC_PNG_BYTES')
            for role in ('h5','merchant','admin'):
                relative = 'manifests/ui-'+role+'-dist.json'; descriptor = material['builds'][role]
                _uib_desc(descriptor,'/run/ms4-prep/'+relative)
                _uib_need({k:descriptor[k] for k in ('bytes','sha256')} == binding['source_closure']['artifacts'][relative]
                    and _uib_pin(_uib_stable(Path(context['prep_dir'])/relative,reads)) == binding['source_closure']['artifacts'][relative],
                    'ALL_THREE_ORIGINAL_FULL_BUILD_MANIFEST_BYTES')


def _uib_relations(ids, value, context):
    _uib_need(type(ids) is list and type(context) is dict and all(type(n) is str and n in UI_BUSINESS_RELATIONS for n in ids)
        and len(ids) == len(set(ids)), 'DEFINED_FINITE_RELATION_IDS')
    for name in ids: context['relation_uses'].append({'id':name,'value':value,'index':context.get('index',0)})


def _uib_original_ack(context, index=None):
    native = context['native']; frames = native['frames']; terminal = native['terminal']
    _uib_need(len(frames) == 42 and terminal['pid'] == native['created']['pid']
        and type(terminal['close_code']) is int and terminal['close_code'] == 0 and terminal['close_signal'] is None,
        'RELATION_ORIGINAL_NODE_PID_CLOSE')
    binding = context['binding']
    if index is None:
        raw = b''.join(row['raw'] for row in frames)
        _uib_need(terminal['stdout']['complete'] is True and terminal['stdout']['bytes'] == len(raw)
            and terminal['stdout']['sha256'] == sha(raw) and terminal['stderr']['bytes'] == 0
            and terminal['stderr']['sha256'] == sha(b''), 'RELATION_ORIGINAL_FULL_EOF')
        checked = frames
    else: checked = (frames[2*index-1],frames[2*index])
    for row in checked: _uib_need(_uib_same(row['value']['binding'],binding)
        and row['raw'] == _uib_canon(row['value'])+b'\n', 'RELATION_ORIGINAL_RAW_CANONICAL_FRAME')
    indexes = range(1,21) if not index else (index,)
    for number in indexes:
        item = context['steps'][number-1]; ack = frames[2*number]['value']['receipt']
        _uib_need(_uib_same(ack['after'],item['after']) and _uib_same(ack['verified_after'],item['verified'])
            and _uib_same(ack['expected_delta'],item['expected']) and _uib_same(item['before'],frames[2*number-1]['value']['receipt']['before']),
            'RELATION_COMPLETE_ORIGINAL_ACK_NOT_PASSED_BOOLEAN')
        source = {'path':'/workspace/'+UI_BUSINESS_PHP[2],**context['data']['php_sources'][UI_BUSINESS_PHP[2]]}
        _uib_need(_uib_same(ack['oracle'],{'source':source,'case_name':item['step']['name'],'expected':item['expected'],
            'actual':item['after'],'readback':item['verified']}), 'RELATION_ORIGINAL_SOURCE_BOUND_FULL_ORACLE')


def _uib_prepared_links(context):
    root, reads = Path(context['root']), context['reads']; ready, prepared = context['ready'],context['prepared']
    binding = context['binding']; business = prepared['business']
    for value in (ready,prepared):
        _uib_need(all(value[k] == binding[k] for k in ('run_id','source_sha','freeze_sha256')), 'RELATION_ORIGINAL_PREPARE_READY_BINDING')
    _uib_need(ready['container_id'] == binding['backend_cid'], 'RELATION_READY_ACTUAL_BACKEND_CID')
    _uib_need(_uib_same(ready['actor_ids'],business['actor_ids']) and _uib_same(ready['writer'],business['writer'])
        and business['actor_ids']['customer'] == business['customer_id'], 'RELATION_NATIVE_ACTORS_WRITER')
    source_raw = _uib_stable(root/'evidence/ms4-ui-backend-source-binding.json',reads); source = _uib_json(source_raw)
    created_raw = _uib_stable(root/'evidence/ui-backend-created.json',reads); created = _uib_json(created_raw)
    _uib_need(ready['source_binding_sha256'] == sha(source_raw) and ready['native_created_sha256'] == sha(created_raw)
        and source['native_created_sha256'] == sha(created_raw) and created['id'] == binding['backend_cid']
        and ready['prepare_history_sha256'] == source['history']['sha256'], 'RELATION_REAL_BACKEND_READY_SOURCE_CREATED_HISTORY')
    parent_events = sorted(p for p in (root/'evidence').glob('ui-web-ready-event-*.json')
        if re.fullmatch(r'ui-web-ready-event-[0-9]{4}\.json',p.name))
    _uib_need(parent_events, 'RELATION_ORIGINAL_PUBLIC_PARENT_READY_EVENT_REQUIRED')
    for path in parent_events:
        event = _uib_json(_uib_stable(path,reads))
        _uib_keys(event, ('schema','scope','run_id','source_sha','freeze_sha256','container_id','native','event','uncontrolled_stdout_storage','private_child_logs'))
        _uib_need(type(event['schema']) is int and event['schema'] == 1 and event['scope'] == 'MS4_UI_WEB_EXISTING_READY_EVENT_ONLY'
            and all(event[k] == binding[k] for k in ('run_id','source_sha','freeze_sha256')) and event['container_id'] == binding['backend_cid']
            and event['uncontrolled_stdout_storage'] == 'RAM_ONLY_NOT_PUBLIC' and event['private_child_logs'] == 'NOT READ', 'RELATION_SAME_ORIGINAL_PARENT_READY_WRAPPER')
        _uib_need(_uib_same(event['event'],{'scope':'MS4_UI_BACKEND_READY','run_id':binding['run_id'],'source_sha':binding['source_sha'],
            'container_id':binding['backend_cid'],'ready_sha256':binding['ready_sha256']}), 'RELATION_ORIGINAL_PARENT_READY_DESCRIPTOR_JOIN')
        native = event['native']; _uib_keys(native, ('label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'))
        _uib_need(native['label'] == 'web-existing-parent-ready-event' and native['argv'] == ['docker','logs',binding['backend_cid']]
            and type(native['pid']) is int and native['pid'] > 0
            and all(type(native[k]) is int and native[k] == 0 for k in ('native_exit','wait_returncode','stderr_bytes'))
            and native['stderr_sha256'] == sha(b'') and type(native['stdout_bytes']) is int and native['stdout_bytes'] > 0,
            'RELATION_ORIGINAL_PARENT_READY_NATIVE_OPERATION')
        _uib_hex(native['stdout_sha256'])
        saved = _uib_json(_uib_stable(path.with_name(path.stem+'.native.json'),reads))
        _uib_need(_uib_same(saved,native), 'RELATION_ORIGINAL_PARENT_READY_NATIVE_FILE')

    prepare_source_raw = _uib_stable(root/'evidence/ms4-ui-prepare-source-binding.json',reads); prepare_source = _uib_json(prepare_source_raw)
    prepare_created_raw = _uib_stable(root/'evidence/ui-prepare-created.json',reads); prepare_created = _uib_json(prepare_created_raw)
    terminal = _uib_json(_uib_stable(root/'evidence/ui-prepare-terminal.json',reads))
    _uib_need(prepared['source_binding_sha256'] == sha(prepare_source_raw) and prepared['native_created_sha256'] == sha(prepare_created_raw)
        and prepare_source['native_created_sha256'] == sha(prepare_created_raw)
        and terminal['id'] == prepare_created['id'] == prepared['container_id'], 'RELATION_ORIGINAL_PREPARE_NATIVE_IDENTITY')
    _uib_need(terminal['status'] == 'exited' and all(type(terminal[k]) is int and terminal[k] == 0 for k in ('pid','exit_code','restart_count')),
        'RELATION_ORIGINAL_PREPARE_NATURAL_TERMINAL_ZERO')
    _uib_need(prepared['environment_sha256'] == prepare_source['environment']['sha256']
        and prepared['environment_native_sha256'] == prepare_source['environment_native']['sha256']
        and prepared['native_exit'] is None and prepared['native_exit_observation'] == 'HOST_NATIVE_REQUIRED', 'RELATION_NO_FAKE_PREPARED_NATIVE_EXIT')
    for field in ('environment','environment_native'):
        descriptor = prepare_source[field]; _uib_leaf(root,descriptor,descriptor['path'].rsplit('/',1)[-1],reads,False)
    _uib_need(_uib_same(business['item_quantities'],[{'item_id':business['item_quantities'][0]['item_id'],'quantity':2},
        {'item_id':business['item_quantities'][1]['item_id'],'quantity':1}])
        and business['item_quantities'][0]['item_id'] != business['item_quantities'][1]['item_id'], 'RELATION_ORIGINAL_Q2_Q1')
    _uib_hex(business['source_fingerprint'])
    group = ready['group']; _uib_need(group['leader_pid'] == group['pgid'] == group['sid'] and ready['supervisor']['pid'] == 1, 'RELATION_ACTUAL_GROUP_SUPERVISOR')
    _uib_need(any(row['pid'] == group['leader_pid'] and row['starttime'] == group['leader_starttime'] for row in ready['members']), 'RELATION_ORIGINAL_GROUP_LEADER_MEMBER')
    for row in ready['members']:
        _uib_need(row['pgid'] == group['pgid'] and row['sid'] == group['sid'] and row['state'] not in ('Z','X','x'), 'RELATION_ACTUAL_MEMBER_GROUP')
    child = prepared['child']; first = child['first_terminal']; stopped_group = child['group']
    _uib_need(stopped_group['leader_pid'] == stopped_group['pgid'] == stopped_group['sid'] == first['pid']
        and child['stopped'] is True and child['remaining_members'] == [] and child['listener_open'] is False
        and type(child['term_attempted']) is bool and (child['stop_signal'] is None or type(child['stop_signal']) is int and child['stop_signal'] == 15)
        and (child['term_attempted'] is True or child['stop_signal'] is None)
        and type(child['proc_close_exit']) is int
        and first['running'] is False and first['termsig'] != 9, 'RELATION_ORIGINAL_PREPARE_CHILD_STOP')
    _uib_need(type(child['streams']) is list and len(child['streams']) == 2, 'RELATION_ORIGINAL_ORDERED_TWO_NATIVE_STREAMS')
    for stream in child['streams']:
        if stream['bytes'] == 0: _uib_need(stream['sha256'] == sha(b''), 'RELATION_EMPTY_NATIVE_PREPARE_STREAM')
    observer = business['observer']; old,new = observer['before'][0],observer['configured'][0]
    _uib_need(all(_uib_same(old[k],new[k]) for k in old if k != 'session_stats_expiry')
        and (type(new['session_stats_expiry']) is int and new['session_stats_expiry'] == 0 or type(new['session_stats_expiry']) is str and new['session_stats_expiry'] == '0'), 'RELATION_ONLY_SESSION_STATISTIC_EXPIRY_ZERO')
    for row in (old,new):
        for field in ('session_stats_expiry','global_stats_expiry'):
            number = row[field]; _uib_need(type(number) in (int,str) and re.fullmatch('0|[1-9][0-9]*',str(number))
                and int(number) <= 4294967295, 'RELATION_NATIVE_UINT32_STATISTICS')
    menus = business['menus']; stages = [menus[k] for k in ('check','apply','postcheck','reapply')]
    source_hashes = {row['path']:row['sha256'] for row in context['freeze']['ui_prepare_source_files']}
    for relative in ('help/migrations/marketplace-ms4-order-read-menus.php','help/migrations/marketplace-ms1.php'):
        raw = _uib_stable(Path(context['context']['repo_dir'])/relative,reads)
        _uib_need(sha(raw) == source_hashes[relative], 'RELATION_COMPLETE_ACTUAL_MENU_MIGRATION_SOURCE_BYTES')
    _uib_need(_uib_same(stages[0]['source_binding'],{'entry_sha256':source_hashes['help/migrations/marketplace-ms4-order-read-menus.php'],
        'ms1_sha256':source_hashes['help/migrations/marketplace-ms1.php']}), 'RELATION_EXACT_TWO_FROZEN_MENU_MIGRATION_SOURCES')
    anchors = stages[0]['anchors']; ids = stages[1]['menu_ids']
    _uib_need(len({r['id'] for r in anchors.values()}) == 4 and len(set(ids.values())) == 2
        and not set(ids.values()).intersection(r['id'] for r in anchors.values()), 'RELATION_REAL_DISTINCT_MENU_ANCHORS_IDS')
    for row in stages:
        _uib_need(_uib_same(row['anchors'],anchors) and _uib_same(row['source_binding'],stages[0]['source_binding'])
            and row['roles_modified'] is False, 'RELATION_SAME_MENU_SOURCE_ROLE_PRESERVATION')
        _uib_need(_uib_same(row['menu_ids'],[] if row is stages[0] else ids), 'RELATION_SOURCE_CHECK_THEN_EXACT_MENU_IDS')
    _uib_need(_uib_same(stages[2]['menu_ids'],ids) and _uib_same(stages[3]['menu_ids'],ids)
        and _uib_same(stages[1]['created_menu_ids'],ids)
        and stages[3]['created_menu_ids'] == [] and stages[3]['changed'] is False, 'RELATION_REAL_MENU_APPLY_REAPPLY')


def _uib_host_gate_links(context):
    handoff = context['handoff']; root,reads = Path(context['root']),context['reads']; gates = handoff['host_gates']
    executable = gates['before']['executable']; _uib_need(type(executable) is str and Path(executable).is_absolute()
        and gates['after']['executable'] == executable, 'RELATION_ONE_EXPLICIT_REAL_PYTHON_EXECUTABLE_PATH')
    for stage in ('before','after'):
        value = gates[stage]; native_raw = _uib_stable(root/'evidence'/('ui-private-caller-gate-'+stage+'.native.json'),reads)
        _uib_need(_uib_same(value,_uib_json(native_raw)) and value['stage'] == stage and type(value['pid']) is int and value['pid'] > 0
            and type(value['close_code']) is int and value['close_code'] == 0 and value['close_signal'] is None, 'RELATION_ORIGINAL_DUAL_HOST_NATIVE_FILE')
        _uib_need(value['argv'] == ['-B','-X','utf8',str(Path(context['context']['prep_dir'])/'ms4-host.py'),
            'ui-web-private-inputs','--context',str(root/'actual-run-context.json')], 'RELATION_ORIGINAL_HOST_ARGV7')
        for stream in ('stdout','stderr'):
            row = value[stream]; _uib_need(row['complete'] is True and row['bytes'] == row['stored_bytes']
                and row['sha256'] == row['stored_sha256'], 'RELATION_HOST_COMPLETE_STREAM_DESCRIPTOR')
        _uib_need(value['stderr']['bytes'] == 0 and value['stderr']['sha256'] == sha(b'') and value['stdout']['bytes'] > 0, 'RELATION_HOST_NATURAL_ZERO_EMPTY_STDERR')
        streams = {}
        for name in ('stdout','stderr'):
            raw = _uib_stable(root/'evidence'/('ui-private-caller-gate-'+stage+'.'+name),reads)
            _uib_need(len(raw) == value[name]['bytes'] and sha(raw) == value[name]['sha256'], 'RELATION_COMPLETE_ORIGINAL_HOST_STREAM_BYTES')
            streams[name] = raw
        _uib_need(streams['stderr'] == b'', 'RELATION_ORIGINAL_HOST_STDERR_EMPTY')
        output = _uib_json(streams['stdout'])
        _uib_keys(output, ('status','descriptor','ready','reader_module','private_caller_execution','form_login_business_DOM'))
        _uib_need(output['status'] == 'UI_BACKEND_REAL_PRIVATE_READER_INPUTS_ONLY'
            and output['private_caller_execution'] == 'NOT IMPLEMENTED_OR_PROVEN_BY_HOST'
            and output['form_login_business_DOM'] == 'NOT RUN' and _uib_same(output['descriptor'],handoff['reader_inputs'])
            and _uib_same(output['ready'],handoff['ready']), 'RELATION_REAL_HOST6_NOT_CALLER_BOOLEAN')
        _uib_need(_uib_same(output['reader_module'],{'path':'browser-v1/ms4-ui-private-reader.mjs',
            **context['binding']['source_closure']['reader']}), 'RELATION_ORIGINAL_HOST_READER_SOURCE_DESCRIPTOR')
    for field,name in (('reader_inputs','ui-web-private-inputs.json'),('ready','ui-web-control-ui-backend-ready.json')):
        _uib_leaf(root,handoff[field],name,reads,False)


def _uib_case_relations(context):
    data,steps = context['data'],context['steps']; business = context['prepared']['business']; ids = business['item_quantities']
    quote = steps[3]['wire']['response_public']; _uib_need(quote['root']['id'] == business['paid_order_id']
        and quote['root']['order_no'] == business['paid_order_no']
        and _uib_same([{'item_id':r['item']['id'],'quantity':r['item']['quantity']} for r in quote['root_items']],ids), 'RELATION_REAL_ROOT_QUOTE_NATIVE_Q2_Q1')
    _uib_need(_uib_same(steps[5]['wire']['body_public']['selection'],{'mode':'root_quantities','items':[{'item_id':ids[0]['item_id'],'quantity':1}]}), 'RELATION_N1_ROOT_QUANTITIES_ONE')
    for number,which in ((9,0),(12,1)):
        _uib_need(_uib_same(steps[number-1]['wire']['body_public']['items'],[ids[which]])
            and type(steps[number-1]['wire']['response_public']['quantity']) is int
            and steps[number-1]['wire']['response_public']['quantity'] == ids[which]['quantity'], 'RELATION_EXACT_Q2_Q1_SHIPMENT')
    local = _uib_binding('q2_child_item_id',data,steps,20,True)
    _uib_need(_uib_same(steps[9]['wire']['body_public']['selection'],{'mode':'items','items':[{'item_id':local,'segments':[{'first':1,'last':1}]}]}), 'RELATION_CHILD_LOCAL_EXACT_PARTIAL_RANGE')
    for number in (11,13): _uib_need(_uib_same(steps[number-1]['wire']['body_public']['selection'],{'mode':'all_eligible'}), 'RELATION_ALL_ELIGIBLE_RECEIPTS')
    for number in (16,19):
        item = steps[number-1]; classes = item['classes']; requests = item['event']['requests']
        list_rows = [q for q in requests if classes[q['sequence']] == 'public_review_list']; count_rows = [q for q in requests if classes[q['sequence']] == 'public_review_counts']
        _uib_need(len(list_rows) == len(count_rows) == 1 and list_rows[0]['path'] == item['wire']['path'], 'RELATION_EXACT_DISTINCT_PUBLIC_LIST_COUNTS')
        schema = 'public_review_counts_pending' if number == 16 else 'public_review_counts_approved'
        _uib_schema_v1(count_rows[0]['response_public'],{'$ref':'#/schemas/'+schema},data['schemas'],relation_context=context)
        if number == 16: _uib_need(_uib_same(list_rows[0]['response_public'],[]), 'RELATION_PENDING_PUBLIC_REVIEW_ABSENT')
        else:
            rows = list_rows[0]['response_public']; _uib_need(type(rows) is list and len(rows) == 1, 'RELATION_SINGLE_APPROVED_PUBLIC_REVIEW')
            row = rows[0]; saved = steps[14]['wire']['response_public']; reply = steps[17]['wire']['response_public']
            _uib_need(all(_uib_same(row[k],saved[k]) for k in ('id','product_id','nickname','suk','comment','pics'))
                and row['merchant_reply_content'] == reply['merchant_reply_content'], 'RELATION_ORIGINAL_REVIEW_AND_ACTUAL_MERCHANT_REPLY')
    for number in (1,16):
        requests = steps[number-1]['event']['requests']; paths = [r['path'] for r in requests]
        required = ('/api/basic_config','/api/theme_info/theme','/api/lang_version','/api/get_script','/api/wechat/get_logo','/api/user',
            '/api/share','/api/theme_info/home','/api/cart/count?numType=0','/api/copyright') if number == 1 else ('/api/logout','/api/share','/api/theme_info/home','/api/copyright')
        _uib_need(all(path in paths for path in required), 'RELATION_ALL_REQUIRED_NORMAL_STARTUP_READS')
        if number == 1: _uib_need(any(path in paths for path in ('/api/v2/get_today_coupon','/api/v2/new_coupon')), 'RELATION_ACTUAL_COUPON_STARTUP_READ')
    for number in (5,6,7,8):
        item = steps[number-1]; pairs = item['expected']['independent']['private_pairs']; used = [v for p in pairs for v in (p['issued_sequence'],p['read_sequence'])]
        private_rows = [r['sequence'] for r in item['event']['requests'] if item['classes'][r['sequence']] in ('customer_private_ticket','customer_private_content','merchant_private_ticket','merchant_private_content')]
        _uib_need(len(used) == len(set(used)) and set(used) == set(private_rows) and 1 <= len(pairs) <= 16, 'RELATION_EVERY_PRIVATE_TICKET_CONTENT_PAIR_NO_ORPHAN')
    upload = steps[13]['wire']['response_public']; _uib_need(steps[14]['wire']['body_public']['pics'] == [upload['attachment_ref']], 'RELATION_REVIEW_USES_VERIFIED_PUBLIC_UPLOAD')
    review = steps[14]['wire']['response_public']
    _uib_need(review['id'] == steps[14]['expected']['independent']['review_id']
        and review['root_order_id'] == business['paid_order_id'] and review['source_item_id'] == ids[0]['item_id'], 'RELATION_NATIVE_REVIEW_ROOT_ORIGINAL_Q2_ITEM')
    for number in (17,18): _uib_need(steps[number-1]['wire']['response_public']['id'] == review['id']
        and steps[number-1]['expected']['independent']['review_id'] == review['id'], 'RELATION_NATIVE_SAME_APPROVED_REPLIED_REVIEW')
    for item in steps:
        number = item['step']['index']; body = item['wire']['body_public']
        fields = ('reason',) if number == 6 else ('comment',) if number == 15 else ('content',) if number == 18 else ()
        for key in fields: _uib_need(type(body[key]) is str and body[key].strip() != '', 'RELATION_ACTUAL_NONEMPTY_TRIMMED_ORDINARY_TEXT')
        if number in (9,12):
            for key in ('carrier_code','carrier_name','tracking_no'): _uib_need(type(body['delivery'][key]) is str and body['delivery'][key].strip() != '', 'RELATION_NONEMPTY_DELIVERY_TEXT')


def _uib_public_relative_time(row, context, index):
    from datetime import datetime, timezone, timedelta
    item = context['steps'][index-1]; candidates = [q for q in item['event']['requests']
        if item['classes'][q['sequence']] == 'public_review_list']
    _uib_need(len(candidates) == 1, 'RELATION_REAL_PUBLIC_TIME_REQUEST'); request = candidates[0]
    saved = context['steps'][14]['wire']['response_public']; created = _uib_int(saved['add_time'],1)
    low = request['started_at_ms']//1000-created; high = request['finished_at_ms']//1000-created
    relative = row['add_time']
    if relative is None: _uib_need(low <= 0 <= high, 'RELATION_NULL_RELATIVE_TIME_ONLY_SAME_SECOND')
    else:
        _uib_need(type(relative) is str, 'RELATION_SOURCE_RELATIVE_TIME_STRING')
        units = ((31536000,'年'),(2592000,'个月'),(604800,'星期'),(86400,'天'),(3600,'小时'),(60,'分钟'),(1,'秒'))
        match = re.fullmatch(r'(-?[1-9][0-9]*)(年|个月|星期|天|小时|分钟|秒)前',relative)
        _uib_need(match is not None, 'RELATION_EXACT_PRODUCTION_RELATIVE_TIME_FORMAT'); count = int(match[1]); found = False
        for n,(unit,label) in enumerate(units):
            if label != match[2]: continue
            left,right = count*unit,(count+1)*unit-1
            if count > 0:
                left = max(left,unit)
                if n > 0: right = min(right,units[n-1][0]-1)
            elif n != 0: continue
            if max(low,left) <= min(high,right): found = True
        _uib_need(found, 'RELATION_ORIGINAL_CREATION_INSIDE_ACTUAL_PUBLIC_REQUEST_INTERVAL')
    reply_time = _uib_int(context['steps'][17]['wire']['response_public']['merchant_reply_time'],1)
    expected = datetime.fromtimestamp(reply_time,timezone(timedelta(hours=8))).strftime('%Y-%m-%d %H:%M')
    _uib_need(row['merchant_reply_time'] == expected, 'RELATION_REAL_ASIA_SHANGHAI_REPLY_TIME')


def _uib_validate_relations(context):
    # The pinned data supplies IDs/parameters, never executable kind/rule expressions.
    # Actual whole native/source/step/DOM joins above remain prerequisites, not booleans.
    _uib_original_ack(context); _uib_prepared_links(context); _uib_host_gate_links(context); _uib_case_relations(context)
    uses = list(context['relation_uses']); context['relation_uses'] = []
    source_ids = {'PREPARED_ROOT_ACTOR_JOIN','Q2_Q1_EXACT','ORIGINAL_SOURCE_FINGERPRINT','GENUINE_DUAL_HOST_GATE_NATIVE',
        'HANDOFF_CURRENT_PUBLIC_READBACK','READY_NATIVE_PARENT_CHILD_SOURCE_JOIN','REAL_PROCESS_GROUP_JOIN','WRITER_PRIVATE_DESCRIPTOR_ONLY',
        'SOURCE_BOUND_PREPARED_NATIVE','PREPARED_MENUS_NATIVE_SOURCE_JOIN','PREPARED_OBSERVER_ONLY_SESSION_EXPIRY_ZERO','PREPARED_CHILD_NATIVE_TERMINAL_JOIN'}
    native_ids = {'SOURCE_BOUND_ORACLE_ACK','SAME_NODE_NATIVE42_EOF','FULL_ORIGINAL_DTO_RETAINED','PRIVATE_TICKET_CONTENT_PAIR',
        'NATIVE_LOGIN_TOKEN_JOIN','ALL_THREE_LOGIN_TOKEN_REQUIRED','PRIVATE_STDIN_RAM_ONLY','FULL_PRESERVATION','FULL_NATIVE_TABLE_SET',
        'FULL_NATIVE_ROOT_SET','NATIVE_ATTACHMENT_SAME_ORIGIN_FULL_PNG','VERIFIED_PUBLIC_ATTACHMENT_REFERENCE','NATIVE_SEQUENCE_BARRIER'}
    case_ids = {'VERIFIED_PREVIOUS_DTO_JOIN','EXACT_ASSOCIATED_SET','PUBLIC_LIST_CONFIG_DISTINCT','CUSTOMER_LOGIN_REQUIRED_STARTUP',
        'REQUIRED_POST_ACTION_READBACK','ORDINARY_LOGOUT_ORDER','PUBLIC_LOGOUT_REQUIRED_HOME','PRIVATE_PAIR_COUNT_ORDER',
        'FULFILLMENT_FIXED20_STATE','CHILD_LOCAL_ITEM_AND_RANGE_JOIN','NONEMPTY_TRIMMED_TEXT'}
    dom_ids = {'DOM_CURRENT_OWNED_TAB_WINDOW','SCREENSHOT_ORIGINAL_PNG','FINITE_POSITIVE_IN_VIEWPORT','CASE20_RELATIVE_HOST_SCREENSHOT_JOIN','CASE20_VIEWPORT_ORDER','SOURCE_LABEL_COMPOSITION'}
    for use in uses:
        name,value,index = use['id'],use['value'],use['index']
        _uib_need(name in UI_BUSINESS_RELATIONS and name in context['data']['wire_rules']['relations'], 'NO_UNDEFINED_RELATION')
        if name in source_ids:
            _uib_need(_uib_same(context['ready']['actor_ids'],context['prepared']['business']['actor_ids'])
                and context['prepared']['native_exit'] is None and context['prepared']['native_exit_observation'] == 'HOST_NATIVE_REQUIRED', 'RELATION_ALREADY_READ_ORIGINAL_PREPARED_SOURCE_OBJECTS')
        elif name in native_ids: _uib_original_ack(context,index if 1 <= index <= 20 else None)
        elif name in case_ids:
            _uib_need(len(context['steps']) == 20 and all(len(row['classes']) == len(row['event']['requests']) for row in context['steps']), 'RELATION_ALL_REAL_TYPED_CASE_REQUESTS')
        elif name in dom_ids:
            _uib_need(context['DOM']['binding'] == context['binding'] and len(context['DOM']['observations']) >= 12, 'RELATION_ORIGINAL_COMPLETED_DOM_CLOSURE')
        elif name == 'SAME_BINDING9':
            if type(value) is dict and 'binding' in value: _uib_need(_uib_same(value['binding'],context['binding']), 'RELATION_SAME_REAL_BINDING9')
            elif type(value) is dict and set(value) == set(context['binding']): _uib_need(_uib_same(value,context['binding']), 'RELATION_FULL_BINDING9')
            else: _uib_need(all(value.get(k) == context['binding'][k] for k in ('run_id','source_sha','freeze_sha256'))
                if 'run_id' in value else _uib_same(value,context['handoff']['source_closure']), 'RELATION_SOURCE_HANDOFF_SAME_BINDING')
        elif name == 'FROZEN_ORIGIN_CANONICAL': _uib_need(value['origin'] == context['freeze']['ui_web_origin'], 'RELATION_REAL_FROZEN_ORIGIN')
        elif name == 'BACKEND_GATEWAY_DISTINCT': _uib_need(value['backend_cid'] != value['gateway_cid'], 'RELATION_DISTINCT_ACTUAL_CIDS')
        elif name == 'AUTH_PRESENT_SUBJECT_EQUIVALENCE': _uib_authentication(value)
        elif name == 'CURRENT_THREE_SOURCE':
            if value.get('scope') == 'MS4_UI_WINDOW_SOURCE_PRESERVED':
                rows = {r['path']:r for r in value['files']}
                _uib_need(len(rows) == len(value['files']) == len(context['backend_rows'])
                    and set(rows) == {'/workspace/'+path for path in context['backend_rows']}, 'RELATION_COMPLETE_ORIGINAL_SOURCE_PRESERVATION_FILES')
                for path in UI_BUSINESS_PHP:
                    _uib_need({k:rows['/workspace/'+path][k] for k in ('bytes','sha256')} == context['data']['php_sources'][path], 'RELATION_CURRENT_R6_SOURCE_THREE')
            else:
                _uib_need(value['source']['path'] == '/workspace/'+UI_BUSINESS_PHP[2]
                    and {k:value['source'][k] for k in ('bytes','sha256')} == context['data']['php_sources'][UI_BUSINESS_PHP[2]], 'RELATION_CURRENT_SOURCE_ORACLE')
        elif name == 'EXACT_ORDERED17': _uib_need(_uib_same(value['artifacts'],context['binding']['source_closure']['artifacts']), 'RELATION_EXACT_ORIGINAL_P17')
        elif name == 'EXACT_STEP_FACT_SCHEMA': _uib_need(index in range(1,21)
            and _uib_same(value['independent'],context['steps'][index-1]['expected']['independent']), 'RELATION_CORRESPONDING_NATIVE_FACTS_I')
        elif name == 'NATIVE_U64_BOUND': _uib_shape(value,'native_id',{})
        elif name == 'PUBLIC_SECRET_FIELDS_ABSENT': _uib_no_secrets(value)
        elif name == 'FULL_ORIGINAL_PUBLIC_HASH': _uib_canon(value)
        elif name == 'REAL_PUBLIC_RELATIVE_TIME_INTERVAL': _uib_public_relative_time(value,context,index)
        elif name in ('REQUEST_INSIDE_WINDOW','SEQUENTIAL_REQUEST_INDEX'):
            event = context['steps'][index-1]['event']; _uib_need(type(value['sequence']) is int
                and value['sequence'] < len(event['requests']) and _uib_same(value,event['requests'][value['sequence']])
                and event['started_at_ms'] <= value['started_at_ms'] <= value['finished_at_ms'] <= event['finished_at_ms'], 'RELATION_ORIGINAL_REQUEST_POSITION_TIME')
        elif name in ('PRIMARY_REQUEST_UNIQUE','CASE20_ORIGINAL_IMAGE_WIRE'):
            _uib_need(index in range(1,21), 'RELATION_NAMED_REAL_CASE'); _uib_original_ack(context,index)
        else: _uib_need(False, 'KNOWN_RELATION_WITHOUT_FINITE_SEMANTIC_HANDLER')
    _uib_need(not context['relation_uses'], 'NO_UNCONSUMED_LATE_RELATION')


class Task2PublicConsumer:
    """Finite three-scene public originals. Pinned native entry owns private DTO recomputation."""
    VIEWS=('customer_list','customer_detail','merchant_list','merchant_detail','platform_list','platform_detail')
    STEPS=('login-customer','login-merchant','login-platform','customer-list','customer-root-detail','merchant-list','merchant-root-packages','platform-list','platform-root-detail')
    ROLES=('customer','merchant','platform','customer','customer','merchant','merchant','platform','platform')
    INPUT=('sequence','method','path','query','capture_ref','request_public','request_public_sha256','authentication','language_headers')
    OUTPUT=('sequence','capture_ref','http_status','app_status','response_public','response_body','response_public_sha256')
    def __init__(self,context,binding,data,read_file,canonical):
        self.context=context;self.binding=binding;self.data=data;self.read_file=read_file;self.canon=canonical
        self.root=Path(context['run_dir']);self.e=self.root/'evidence';self.files={};self.captures=set();self.tabs={}
        raw=read_file(self.root/'preparation-source-binding.json',268435456);self.frozen=self.decode(raw)
        self.need(self.hash(raw)==binding['freeze_sha256'],'ORIGINAL_FREEZE')
        self.observer=self.frozen['task2_observer_source'];self.browser=self.frozen['task2_browser_source'];self.task=data['task2']
        self.need(type(self.task['schema']) is int and self.task['schema']==1 and self.task['scope']=='MS5_TASK2_FINITE_BROWSER_SOURCE_ONLY','SOURCE_REGISTRY')
        self.preparation=self.read('ms5-task2-target-preparation.json')[0]
        self.keys(self.preparation,('schema','scope','status','binding','observer_source','actors','paid','unpaid','cancelled','window','preservation','diagnostic'))
        self.need(type(self.preparation['schema']) is int and self.preparation['schema']==1 and self.preparation['scope']=='MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY'
            and self.preparation['status']=='VERIFIED_MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY' and self.preparation['diagnostic'] is None,'REAL_PREPARATION')
        for key in ('run_id','source_sha','freeze_sha256'):self.equal(self.preparation['binding'][key],binding[key])
        self.equal(self.preparation['observer_source'],self.observer)
        self.actors={role:row['id'] for role,row in self.preparation['actors'].items()};self.keys(self.actors,('customer','merchant','platform'))
        self.prepared=self.read('ms4-ui-prepare.json')[0]['business'];self.equal(self.actors,self.prepared['actor_ids'])
        self.equal(self.preparation['paid']['target'],{'id':self.prepared['paid_order_id'],'order_no':self.prepared['paid_order_no'],'uid':self.prepared['customer_id'],'mer_id':self.prepared['merchant_id']})
        for value in self.actors.values():self.integer(value,1)
    @staticmethod
    def need(ok,code):
        if not ok:raise RuntimeError('UI_BUSINESS_TASK2_'+code)
    @classmethod
    def keys(cls,value,names):cls.need(type(value) is dict and set(value)==set(names),'EXACT_OBJECT_MEMBERS')
    @classmethod
    def integer(cls,value,minimum=0,maximum=9007199254740991):
        cls.need(type(value) is int and minimum<=value<=maximum,'INTEGER_TYPE_RANGE');return value
    @classmethod
    def equal(cls,a,b):
        cls.need(type(a) is type(b),'JSON_NATIVE_TYPE')
        if type(a) is dict:
            cls.keys(a,b)
            for key in a:cls.equal(a[key],b[key])
        elif type(a) is list:
            cls.need(len(a)==len(b),'LIST_LENGTH')
            for x,y in zip(a,b):cls.equal(x,y)
        else:cls.need(a==b,'EXACT_NATIVE_VALUE')
    @staticmethod
    def hash(raw):return hashlib.sha256(raw).hexdigest()
    @classmethod
    def decode(cls,raw):
        def pairs(rows):
            result={}
            for key,value in rows:cls.need(key not in result,'NO_DUPLICATE_JSON_KEY');result[key]=value
            return result
        def reject(value):raise RuntimeError('UI_BUSINESS_TASK2_NONFINITE_JSON')
        return json.loads(raw.decode('utf8'),object_pairs_hook=pairs,parse_constant=reject)
    def read(self,name,descriptor=None,binary=False,empty=False):
        self.need(type(name) is str and re.fullmatch('[a-z0-9][a-z0-9.-]*',name) is not None,'FIXED_PUBLIC_LEAF')
        raw=self.read_file(self.e/name,268435456);self.need(empty or len(raw)>0,'COMPLETE_PUBLIC_ORIGINAL')
        actual={'path':'/run/audit/evidence/'+name,'bytes':len(raw),'sha256':self.hash(raw)}
        if descriptor is not None:self.equal(descriptor,actual)
        if name in self.files:self.equal(self.files[name],actual)
        self.files[name]=actual;return (raw if binary else self.decode(raw)),actual
    def shape(self,value,schema,depth=0):
        self.need(depth<=96 and type(schema) is dict,'FINITE_SCHEMA_DEPTH')
        if '$ref' in schema:
            ref=schema['$ref'];prefix='#/task2/schemas/' if ref.startswith('#/task2/schemas/') else '#/schemas/'
            self.need(ref.startswith(prefix),'CLOSED_SCHEMA_REF');source=self.task['schemas'] if prefix.startswith('#/task2/') else self.data['schemas']
            self.shape(value,source[ref[len(prefix):]],depth+1);return
        if 'const' in schema:self.equal(value,schema['const'])
        if 'enum' in schema:self.need(any(type(value) is type(x) and value==x for x in schema['enum']),'ENUM_TYPE_VALUE')
        if 'oneOf' in schema:
            matches=0
            for child in schema['oneOf']:
                try:self.shape(value,child,depth+1);matches+=1
                except RuntimeError as error:
                    if not str(error).startswith('UI_BUSINESS_TASK2_'):raise
            self.need(matches==1,'ONE_TYPED_ALTERNATIVE')
        kinds=schema.get('type');types={'object':dict,'array':list,'string':str,'integer':int,'boolean':bool,'null':type(None)}
        if kinds is not None:
            options=kinds if type(kinds) is list else [kinds]
            matches=[k for k in options if (type(value) in (int,float) if k=='number' else type(value) is types[k])]
            self.need(bool(matches),'ACTUAL_JSON_TYPE');kind=matches[0]
            if kind=='object':
                props=schema.get('properties',{});self.need(set(schema.get('required',[]))<=set(value) and len(value)>=schema.get('minProperties',0),'REQUIRED_OBJECT_MEMBERS')
                for key,child in value.items():
                    self.need(type(key) is str,'JSON_KEY_STRING')
                    if 'propertyNames' in schema:self.shape(key,schema['propertyNames'],depth+1)
                    target=props.get(key,schema.get('additionalProperties',True));self.need(target is not False,'NO_EXTRA_OBJECT_MEMBER')
                    if type(target) is dict:self.shape(child,target,depth+1)
            elif kind=='array':
                self.need(schema.get('minItems',0)<=len(value)<=schema.get('maxItems',100000),'ARRAY_LENGTH')
                if schema.get('uniqueItems'):self.need(len({self.canon(x) for x in value})==len(value),'UNIQUE_ARRAY_MEMBERS')
                for child in value:self.shape(child,schema['items'],depth+1)
            elif kind=='string':
                self.need(schema.get('minLength',0)<=len(value)<=schema.get('maxLength',268435456),'STRING_LENGTH')
                if 'pattern' in schema:self.need(re.search(schema['pattern'],value) is not None,'SOURCE_STRING_PATTERN')
            elif kind in ('integer','number'):
                import math
                self.need(math.isfinite(value) and (kind!='integer' or abs(value)<=9007199254740991),'SAFE_FINITE_NUMBER')
        for key,test in [('minimum',lambda x,y:x>=y),('maximum',lambda x,y:x<=y),('exclusiveMinimum',lambda x,y:x>y)]:
            if key in schema and type(value) in (int,float):self.need(test(value,schema[key]),'SOURCE_NUMBER_BOUND')
        # x-relations labels are documentation; the closed semantic joins below are mandatory.
    def typed(self,value,name):self.shape(value,{'$ref':'#/task2/schemas/'+name})
    def ledger(self,value,index,point):
        self.shape(value,{'$ref':'#/schemas/ledger'});self.equal(value['binding'],self.binding)
        self.need(type(value['index']) is int and value['index']==index and value['point']==point,'LEDGER_POINT')
        names=value['database']['names'];self.need(names==sorted(set(names)) and set(value['database']['tables'])==set(names),'ENTIRE_DATABASE_NAMESPACE')
        self.need(type(value['redis']['time_ms']) is str and re.fullmatch('0|[1-9][0-9]*',value['redis']['time_ms']) is not None,'ACTUAL_REDIS_TIME')
        for row in value['redis']['keys'].values():self.need(type(row['expires']) is int and (row['expires']==-1 or row['expires']>0),'ACTUAL_ABSOLUTE_EXPIRY_NOT_ZERO')
        self.keys(value['files']['roots'],('/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence','/workspace/crmeb/public','/workspace/crmeb/runtime',
            '/tmp/ms4-fulfillment-normal-'+self.binding['run_id'],'/run/audit-ui-control','/run/audit-app/config.json','/tmp'));return value
    def state(self,a,b):
        for key in ('database','files'):self.equal(a[key],b[key])
        self.equal(a['redis']['keys'],b['redis']['keys']);self.need(int(b['redis']['time_ms'])>=int(a['redis']['time_ms']),'OBSERVED_TIME_ONLY_MONOTONIC')
    def native(self,descriptors,kind):
        self.typed(descriptors,'native4');label='ui-task2'+('' if kind=='paid' else '-'+kind)+'-observer';raw={}
        for key,suffix in [('created','-created.json'),('terminal','-terminal.json'),('stdout','.stdout'),('stderr','.stderr')]:raw[key]=self.read(label+suffix,descriptors[key],True,key=='stderr')[0]
        created=self.decode(raw['created']);terminal=self.decode(raw['terminal'])
        self.keys(created,('label','executable','argv','pid'));self.keys(terminal,('schema','scope','executable','argv','pid','close_code','close_signal','stdout','stderr'))
        argv=['exec','-i',self.binding['backend_cid'],'php','/run/audit/task2-observer/entry.php','observe-'+kind+'-read']
        self.equal(created,{'label':label,'executable':'docker','argv':argv,'pid':self.integer(created['pid'],1)})
        self.need(type(terminal['schema']) is int and terminal['schema']==1 and terminal['scope']=='MS5_TASK2_'+('' if kind=='paid' else kind.upper()+'_')+'OBSERVER_NODE_NATIVE'
            and terminal['executable']=='docker' and terminal['argv']==argv and terminal['pid']==created['pid'] and type(terminal['close_code']) is int
            and terminal['close_code']==0 and terminal['close_signal'] is None,'SAME_ORIGINAL_SPAWN_NATURAL_ZERO')
        for key in ('stdout','stderr'):
            self.keys(terminal[key],('bytes','sha256','stored_bytes','stored_sha256','complete'));self.equal(terminal[key],{'bytes':len(raw[key]),'sha256':self.hash(raw[key]),'stored_bytes':len(raw[key]),'stored_sha256':self.hash(raw[key]),'complete':True})
        self.need(raw['stderr']==b'' and 0<len(raw['stdout'])<=268435456,'FULL_STDOUT_EMPTY_STDERR');lines=raw['stdout'].splitlines(keepends=True);self.need(len(lines)==20,'TWENTY_ORIGINAL_ACKS');frames=[];offset=0
        for sequence,line in enumerate(lines):
            self.need(0<len(line)<=16777216 and line.endswith(b'\n') and b'\r' not in line,'WHOLE_LF_FRAME');frame=self.decode(line);self.typed(frame,'ack');self.equal(frame['binding'],self.binding);self.equal(frame['observer_source'],self.observer)
            command,step,fields=('BEGIN',0,('baseline',)) if sequence==0 else ('FINISH',10,('final','preservation')) if sequence==19 else ('STEP_BEGIN',(sequence+1)//2,('before',)) if sequence%2 else ('STEP_VERIFY',sequence//2,('after','verified_after','expected_delta','oracle'))
            self.need(frame['sequence']==sequence and frame['command']==command and frame['step']==step,'ACK_EXACT_SCHEDULE');self.keys(frame['receipt'],fields)
            self.need(line==self.canon(frame)+b'\n','ORIGINAL_CANONICAL_LF_FRAME');frames.append({'value':frame,'start':offset,'end':offset+len(line),'sha256':self.hash(line)});offset+=len(line)
        self.need(offset==len(raw['stdout']),'FULL_EOF_NO_PREFIX_SUFFIX');return created,frames
    def paths(self,t):
        return {'customer_list':'/api/order/list','customer_detail':'/api/order/detail/'+t['order_no'],'merchant_list':'/merchantapi/orders',
            'merchant_detail':'/merchantapi/orders/'+str(t['id'])+'/packages','platform_list':'/adminapi/order/list','platform_detail':'/adminapi/order/info/'+str(t['id']),
            'customer_order_data':'/api/order/data','platform_order_chart':'/adminapi/order/chart'}
    def event(self,event,wire,index,kind,target):
        from urllib.parse import urlsplit,parse_qsl
        self.typed(event,'browser_event');self.typed(wire,'wire');self.equal(event['binding'],self.binding);role=self.ROLES[index-1];view='login' if index<=3 else self.VIEWS[index-4]
        self.need(event['scope']=='MS5_TASK2_'+kind.upper()+'_READ_BROWSER_STEP' and event['index']==index and event['name']==self.STEPS[index-1] and event['role']==role
            and wire['actor_role']==role and wire['view']==view and event['DOM'] is None,'NINE_PHASE_ROLE_VIEW');self.need(event['finished_at_ms']>=event['started_at_ms']>0,'REAL_ACTION_INTERVAL')
        tab=event['tab_id_sha256']
        if index<=3:
            self.need((role not in self.tabs or self.tabs[role]==tab) and tab not in [v for k,v in self.tabs.items() if k!=role],'SAME_THREE_DISTINCT_OWNED_TABS');self.tabs[role]=tab
        self.need(self.tabs[role]==tab,'PRIMARY_ROLE_TAB');self.equal(event['identity'],{'application':{'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role],
            'subject_id':self.actors[role],'merchant_id':self.preparation['actors']['merchant']['mer_id'] if role=='merchant' else None,'verified_by':'native-session-and-normal-UI'})
        for action in event['actions']:self.need(action['tab_id_sha256'] in (set(self.tabs.values()) if index==9 else {tab}),'REAL_GUI_ACTION_OWNED_TAB')
        requests=event['requests'];routes=[];primary=[]
        for number,q in enumerate(requests):
            self.need(q['sequence']==number and event['started_at_ms']<=q['started_at_ms']<=q['finished_at_ms']<=event['finished_at_ms'],'REAL_ALL_REQUEST_INTERVALS')
            u=urlsplit(q['path']);self.need(not u.scheme and not u.netloc and not u.fragment and u.path.startswith('/'),'ORIGINAL_PATHNAME_SEARCH');pairs=parse_qsl(u.query,keep_blank_values=True)
            self.need(len(dict(pairs))==len(pairs),'NO_DUPLICATE_QUERY_KEYS');self.equal(dict(pairs),q['query']);qr={'api':'customer','merchantapi':'merchant','adminapi':'platform'}.get(u.path.split('/')[1])
            self.need(qr in self.tabs and q['capture_ref']['tab_id_sha256']==self.tabs[qr],'REAL_REQUEST_APP_TAB');capture=(q['capture_ref']['tab_id_sha256'],q['capture_ref']['request_id_sha256'])
            self.need(capture not in self.captures,'NO_CAPTURE_REQUEST_REUSE');self.captures.add(capture)
            self.need(q['capture_ref']['last_event_sequence']>=q['capture_ref']['first_event_sequence'],'CAPTURE_CURSOR_ORDER')
            for key in ('request_public','response_public'):self.need(q[key+'_sha256']==self.hash(self.canon(q[key])),'FULL_PUBLIC_PAYLOAD_HASH')
            if q['authentication']['present']:self.need(q['authentication']['subject_id']==self.actors[qr],'REAL_AUTH_SUBJECT')
            else:self.equal(q['authentication'],{'present':False,'kind':'none','subject_id':None})
            routes.append((u.path,qr));route=self.paths(target).get(view) if view!='login' else {'customer':'/api/login','merchant':'/merchantapi/login','platform':'/adminapi/login'}[role]
            if u.path==route and (view!='login' or q['method']=='POST'):primary.append(number)
        self.need(primary and (view!='login' or len(primary)==1),'ALL_PRIMARY_DUPLICATE_PAGE_REQUESTS');self.equal(wire['primary_sequences'],primary)
        self.need(wire['request_set_sha256']==self.hash(self.canon([{k:requests[n][k] for k in self.INPUT} for n in primary]))
            and wire['response_set_sha256']==self.hash(self.canon([{k:requests[n][k] for k in self.OUTPUT} for n in primary])),'ORDERED_COMPLETE_WIRE_SET_HASHES');return routes
    def expected(self,e,index,kind,target,event,wire,routes,before,after):
        self.typed(e,'expectation');self.need(e['name']==self.STEPS[index-1],'NATIVE_CASE_NAME');facts=e['independent'];coverage=facts['state_coverage']
        def public(t):return {'root_id':t['id'],'order_no_sha256':self.hash(t['order_no'].encode('utf8')),'uid':t['uid'],'mer_id':t['mer_id']}
        self.equal(coverage,{'state':'test_paid' if kind=='paid' else kind,'scenario':'MS5_TASK2_'+kind.upper()+'_READ_V1','target':public(target),
            'original_paid_target':public(self.preparation['paid']['target']),'views_verified':list(self.VIEWS[:max(0,index-3)])})
        if index<=3:
            role=self.ROLES[index-1];self.equal(facts['login'],{'application':{'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role],
                'subject_id':self.actors[role],'native_session_verified':True,'signed_claims_verified':True,'browser_token_join':True})
        else:self.need(facts['login'] is None,'NONLOGIN_FACT_NULL')
        seen=set();main=[];logout={};paths=self.paths(target)
        for receipt in facts['reads']+facts['auxiliary_reads']:
            n=self.integer(receipt['sequence'],0,len(event['requests'])-1);q=event['requests'][n];route,role=routes[n]
            if receipt.get('kind')=='normal-logout':
                self.typed(receipt,'logout_receipt');self.need(index==9 and role not in logout,'THREE_LOGOUTS_ONCE')
                self.equal((q['method'],route),{'customer':('GET','/api/logout'),'merchant':('POST','/merchantapi/logout'),'platform':('GET','/adminapi/setting/admin/logout')}[role])
                self.need(receipt['application']=={'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role] and receipt['subject_id']==self.actors[role] and q['authentication']['present'] is True
                    and receipt['native_session_removed'] is True and receipt['issued_login_token_join'] is True and receipt['subsequent_requests_anonymous'] is True,'NATIVE_SESSION_REMOVAL');logout[role]=n;continue
            self.typed(receipt,'read_receipt');self.need(n not in seen and paths.get(receipt['view'])==route,'EACH_REAL_CONTENT_RESPONSE_ONCE');seen.add(n)
            self.need(q['method']=='GET' and q['request_public']=={} and q['response_public'] is None and q['app_status']==200 and q['response_body']['bytes']>0
                and q['response_body']['mime_type']=='application/json','FULL_PRIVATE_JSON_PUBLIC_NULL_BOUNDARY')
            for key in ('query','capture_ref','response_body'):self.equal(receipt[key],q[key])
            self.equal(receipt['identity'],q['authentication']);full=receipt['full_dto'];self.need(type(full['expected_bytes']) is int and full['expected_bytes']>0
                and full['actual_bytes']==full['expected_bytes'] and full['actual_sha256']==full['expected_sha256'],'NATIVE_STRICT_FULL_DTO_BYTES_HASH')
            projection=receipt['safe_projection'];self.need(projection['view']==receipt['view'],'PROJECTION_REAL_VIEW')
            if receipt['view'] in ('customer_order_data','platform_order_chart'):
                self.equal(projection['selection'],{'page':None,'limit':None,'count':1});self.need(len(projection['rows'])==1 and projection['private_display_digests']==[] and projection['target']['in_page'] is False,'WHOLE_STATS_POPULATION')
                self.typed(projection['rows'][0],'customer_statistics' if receipt['view']=='customer_order_data' else 'platform_statistics')
            else:
                ids=[]
                for row in projection['rows']:self.typed(row,'display_row');ids.append(row['root_id'])
                self.need(len(ids)==len(set(ids)) and projection['target']['root_id']==target['id'] and projection['target']['order_no_sha256']==self.hash(target['order_no'].encode('utf8'))
                    and projection['target']['in_page'] is (target['id'] in ids),'NATIVE_TARGET_ID_ORDER_HASH_IN_PAGE')
                if n in wire['primary_sequences']:main.append(n)
        self.equal(main,[] if index<=3 else wire['primary_sequences'])
        for n,(route,role) in enumerate(routes):
            if route in paths.values():self.need(n in seen,'NO_OMITTED_SIX_DTO_TWO_STATS')
        if index==9:self.need(set(logout)=={'customer','merchant','platform'},'ALL_THREE_NATIVE_LOGOUTS')
        wanted={};audit=sum(role=='platform' and event['requests'][n]['authentication']['present'] and route!='/adminapi/login' for n,(route,role) in enumerate(routes))
        if index==1 or any(role=='customer' and event['requests'][n]['authentication']['present'] for n,(route,role) in enumerate(routes)):wanted['eb_user']=0
        if index==3:wanted['eb_system_admin']=0
        if audit:wanted['eb_system_log']=audit
        self.keys(e['tables'],wanted)
        for key in ('names','namespace_sha256','schema_modes_sha256'):self.equal(before['database'][key],after['database'][key])
        for name,old in before['database']['tables'].items():
            new=after['database']['tables'][name]
            if name not in wanted:self.equal(old,new);continue
            self.equal(e['tables'][name],{'projection':new['projection'],'natural_insert_count':wanted[name]});self.equal(old['projected_columns_sha256'],new['projected_columns_sha256'])
            if wanted[name]==0:
                for key in ('metadata_sha256','ddl_sha256'):self.equal(old[key],new[key])
            else:self.need(new['projection']['rows']==old['projection']['rows']+wanted[name],'EXACT_SOURCE_AUDIT_INSERT_COUNT')
        self.equal({k:v for k,v in before['files']['roots'].items() if k!='/workspace/crmeb/runtime'},
            {k:v for k,v in after['files']['roots'].items() if k!='/workspace/crmeb/runtime'})
        return facts
    @staticmethod
    def text(value):return re.sub('[\u0009-\u000D\u0020\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF]+',' ',value).strip(' ')
    def image(self,value):
        from urllib.parse import urlsplit
        self.need(type(value) is str and '\\' not in value and '\x00' not in value and '#' not in value and not value.startswith('//') and not any(x in ('.','..') for x in value.split('/')),'NORMAL_IMAGE_REFERENCE')
        url=value if value.startswith(('http://','https://')) else self.binding['origin']+'/'+value.lstrip('/');a=urlsplit(url);b=urlsplit(self.binding['origin'])
        self.need((a.scheme,a.hostname,a.port)==(b.scheme,b.hostname,b.port) and a.username is None and a.password is None,'SAME_IMAGE_ORIGIN');return url
    def fields(self,p,view):
        result={}
        def add(key,value,kind='text',encoding='canonical-json-string',digest=None):
            self.need(key not in result,'UNIQUE_VISIBLE_FIELD');result[key]={'key':key,'kind':kind,'encoding':encoding,
                'expected_sha256':digest if digest is not None else self.hash(value.encode('utf8') if encoding=='utf8' else self.canon(value if kind=='image' else self.text(value)))}
        for row in p['rows']:
            r='r:'+str(row['root_id'])+':'
            if view!='customer_list':add(r+'order_no','',encoding='utf8',digest=row['order_no_sha256'])
            if view.endswith('_list') and row['marketplace_trade_version']==1:add(r+'details_action','详情' if view=='platform_list' else '查看详情','button')
            if view=='merchant_detail':add(r+'back_action','返回订单列表','button')
            amounts=['pay_price'] if view=='customer_list' else ['total_price','pay_price','pay_postage'] if view.startswith('merchant_') else (['pay_price'] if row['paid']==1 else []) if view=='platform_list' else ['pay_price','pay_postage']
            for key in amounts:
                if key in row['money'] and not(view=='customer_detail' and key=='pay_postage' and row['money'][key]=='0.00'):add(r+'money:'+key,row['money'][key])
            for item in row['items']:
                k=r+'i:'+str(item['id'])+':';add(k+'product_name',item['product_name']);add(k+'image',self.image(item['image']),'image')
                if view!='customer_list':add(k+'sku_name',('规格：' if view=='platform_detail' else '')+item['sku_name'])
                if view=='merchant_list':add(k+'unit_quantity','¥'+item['unit_price']+' × '+str(item['quantity']))
                else:add(k+'quantity',('x' if view=='customer_list' else 'x ' if view=='customer_detail' else '')+str(item['quantity']));add(k+'unit_price',item['unit_price'])
                if view=='merchant_detail':add(k+'line_price',item['line_price']);add(k+'postage_price',item['postage_price'])
        if not p['rows']:
            self.need(view.endswith('_list'),'EMPTY_ONLY_LIST');add('collection_state','暂无符合条件的订单' if view=='merchant_list' else '暂无数据' if view=='platform_list' else '暂无订单' if p['selection']['count']==0 else '没有更多内容啦~','status')
        for d in p['private_display_digests']:
            if d['visible_required']:add('private:'+str(d['index']),'','status' if re.match(r'(order_state|fulfillment_notice|payment_status)\.',d['kind']) else 'text',digest=d['value_sha256'])
        self.need(bool(result),'NONEMPTY_NATIVE_DOM_OBLIGATIONS');return result
    def box(self,value):
        import math
        self.keys(value,('x','y','width','height'));self.need(all(type(x) in (int,float) and math.isfinite(x) for x in value.values()) and value['width']>0 and value['height']>0,'ACTUAL_CSS_BOUNDS');return value
    def DOM(self,kind,prefix,window,wd,steps):
        d,dd=self.read(prefix+'-dom.json');self.typed(d,'dom_final');self.equal(d['binding'],self.binding);scenario='MS5_TASK2_'+kind.upper()+'_READ_V1'
        self.need(d['scenario']==scenario,'DOM_SCENARIO');self.equal(d['window'],wd);self.equal(d['observer_native'],window['observer_native']);self.equal(d['preservation'],window['preservation'])
        dom=next(x for x in self.browser['members'] if x['path']=='ms4-ui-dom-producer.mjs');self.equal(d['producer_source'],{**dom,'path':'/run/ms4-browser-source/ms4-ui-dom-producer.mjs'});ordinal=0
        for index in range(4,10):
            step,event,facts,step_d,frame=steps[index-1];view=self.VIEWS[index-4];role=self.ROLES[index-1];groups={}
            for receipt in facts['reads']:
                self.need(receipt['view']==view,'PRIMARY_NATIVE_VIEW');groups.setdefault(self.hash(self.canon(receipt['query'])),[]).append(receipt)
            self.need(bool(groups),'EVERY_PRIMARY_QUERY_NATIVE_DTO')
            for width in (320,390,1440):
                ordinal+=1;n=str(ordinal).zfill(3);o,od=self.read(prefix+'-dom-observation-'+n+'.json',d['observations'][ordinal-1]);self.typed(o,'dom_observation')
                for key,value in [('binding',self.binding),('scenario',scenario),('view',view),('role',role),('tab_id_sha256',event['tab_id_sha256']),('window_step',index),('width',width),('business_step',step_d)]:self.equal(o[key],value)
                self.equal(o['native_ack'],{'sequence':2*index,'sha256':frame['sha256'],'stdout_start':frame['start'],'stdout_end':frame['end']});self.equal(o['coverage'],{'query_sha256':list(groups),'all_expected_fields':True})
                m,md=self.read(prefix+'-dom-measure-'+n+'.json',o['measurement']);self.typed(m,'dom_measurement')
                for key in ('binding','scenario','view','role','tab_id_sha256','window_step','width'):self.equal(m[key],o[key])
                self.need(len(m['samples'])==len(groups),'ALL_DISTINCT_QUERY_SAMPLES')
                for sample,(query,receipts) in zip(m['samples'],groups.items()):
                    self.need(sample['query_sha256']==query and event['started_at_ms']<=sample['started_at_ms']<=sample['finished_at_ms']<=event['finished_at_ms'],'ACTUAL_QUERY_ACTION_WINDOW')
                    self.need(sample['viewport']['width']==width and sample['viewport']['height']>0,'ACTUAL_VIEWPORT');projection=receipts[0]['safe_projection']
                    for receipt in receipts:self.equal(receipt['safe_projection'],projection)
                    self.equal(sample['requests'],[{k:r[k] for k in ('sequence','capture_ref','response_body','full_dto')} for r in receipts]);expected=self.fields(projection,view)
                    self.need(len(sample['fields'])==len(expected),'ALL_VISIBLE_FIELDS_NO_OMISSION');seen=set()
                    for field in sample['fields']:
                        key=field['key'];self.need(key in expected and key not in seen,'EXACT_DOM_FIELD_SET');seen.add(key);want=expected[key]
                        for k in ('key','kind','encoding','expected_sha256'):self.equal(field[k],want[k])
                        self.need(field['actual_sha256']==want['expected_sha256'] and field['matched'] is True and field['visible'] is True and field['enabled'] is True,'ACTUAL_DOM_NATIVE_EXPECTED_HASH')
                        box=self.box(field['bounds']);parent=self.box(field['parent_bounds']);vp=sample['viewport']
                        self.need(box['x']>=0 and box['y']>=0 and box['x']+box['width']<=width+0.5 and box['y']+box['height']<=vp['height']+0.5
                            and box['x']>=parent['x']-0.5 and box['y']>=parent['y']-0.5 and box['x']+box['width']<=parent['x']+parent['width']+0.5 and box['y']+box['height']<=parent['y']+parent['height']+0.5,'NO_VIEWPORT_PARENT_OVERFLOW')
                        if field['kind']=='button':
                            c=self.box(field['content_bounds']);self.need(abs(c['x']+c['width']/2-box['x']-box['width']/2)<=4 and abs(c['y']+c['height']/2-box['y']-box['height']/2)<=4,'BUTTON_GROUP_CENTERED')
                        else:self.need(field['content_bounds'] is None,'NO_INVENTED_NONCONTROL_CONTENT_BOX')
        self.need(ordinal==18,'SIX_VIEWS_THREE_WIDTHS');return dd
    def logical(self,row):
        self.typed(row,'file_stat');d=row['descriptor'];path=d['path'];self.need(row['stat']['nlink']==1 and row['stat']['size']==d['bytes'],'NATIVE_FILE_STAT_COMPLETE_SIZE')
        if path.startswith('/run/audit/evidence/'):
            self.read(path[len('/run/audit/evidence/'):],d,True,d['bytes']==0);return
        fixed={'/run/audit/actual-run-context.json':self.root/'actual-run-context.json','/run/audit/preparation-source-binding.json':self.root/'preparation-source-binding.json',
            '/run/audit/network.json':self.root/'network.json','/run/audit-ui-control/ui-backend-ready.json':self.e/'ui-web-control-ui-backend-ready.json'}
        if path in fixed:actual=fixed[path]
        elif path.startswith('/run/ms4-prep/'):
            relative=path[len('/run/ms4-prep/'):];self.need(relative in {r['path'] for r in self.frozen['artifact_files']},'ONLY_FROZEN_ARTIFACT_PATH');actual=Path(self.context['prep_dir'])/relative
        elif path.startswith('/run/audit/task2-observer/'):
            relative=path[len('/run/audit/task2-observer/'):];self.need(relative in ('entry.php','expected.php','gate.php'),'ONLY_THREE_TOOL_FILES');actual=self.root/'task2-observer'/relative
        elif path.startswith('/run/ms4-browser-source/'):
            relative=path[len('/run/ms4-browser-source/'):];self.need(relative in {'PINS.json',*(r['path'] for r in self.browser['members'])},'ONLY_INITIAL_BROWSER_MEMBERS');actual=Path(self.context['prep_dir']).parent/'browser-v1'/relative
        else:self.need(False,'NO_PRIVATE_OR_UNDECLARED_LOGICAL_FILE')
        raw=self.read_file(actual,268435456);self.equal({'bytes':len(raw),'sha256':self.hash(raw)},{k:d[k] for k in ('bytes','sha256')})
    def preservation(self,p):
        self.typed(p,'preservation');self.equal(p['source']['artifact_files'],self.frozen['artifact_files'])
        core=self.frozen['ui_backend_source_files'];self.equal(p['source']['core_files'],{'count':len(core),'sha256':self.hash(self.canon(core))})
        fixed={'context':'/run/audit/actual-run-context.json','freeze':'/run/audit/preparation-source-binding.json','binding':'/run/audit/evidence/ms4-ui-backend-source-binding.json',
            'current':'/run/audit/evidence/ui-backend-current.json','network':'/run/audit/network.json','gateway_created':'/run/audit/evidence/ui-gateway-created.json',
            'gateway_current':'/run/audit/evidence/ui-gateway-current.json','handoff':'/run/audit/evidence/ui-private-caller-handoff.json','ready':'/run/audit-ui-control/ui-backend-ready.json'}
        self.keys(p['source']['proofs'],fixed)
        for key,row in p['source']['proofs'].items():self.need(row['descriptor']['path']==fixed[key],'EXACT_NATIVE_PROOF_PATH');self.logical(row)
        source=self.read('ms4-ui-backend-source-binding.json')[0];self.keys(p['predecessors'],source['prerequisites_sha256'])
        for name,row in p['predecessors'].items():
            self.need(row['descriptor']['path']=='/run/audit/evidence/'+name and row['descriptor']['sha256']==source['prerequisites_sha256'][name],'ENTIRE_ORIGINAL_NATIVE_HISTORY');self.logical(row)
        self.logical(p['public_materials']['materials'])
        for key,row in p['public_materials']['builds'].items():self.need(row['descriptor']['path']=='/run/ms4-prep/manifests/ui-'+key+'-dist.json','THREE_ACTUAL_BUILD_MANIFESTS');self.logical(row)
        tools=p['observer_tools'];self.equal(tools['observer_source'],self.observer);self.need(tools['tool_tree']['root']['path']=='/run/audit/task2-observer','ORIGINAL_TOOL_TREE')
        self.need([r['descriptor']['path'] for r in tools['tool_tree']['files']]==['/run/audit/task2-observer/'+r['path'] for r in self.observer['members']],'EXACT_THREE_TOOL_READBACK_ORDER')
        for row,member in zip(tools['tool_tree']['files'],self.observer['members']):
            self.logical(row);self.equal({k:row['descriptor'][k] for k in ('bytes','sha256')},{k:member[k] for k in ('bytes','sha256')})
        copy=tools['copy'];self.logical({'descriptor':copy['descriptor'],'stat':copy['stat']});receipt=self.read('task2-observer-copy.json',copy['descriptor'])[0];self.equal(copy['receipt'],receipt)
        expected={'schema':1,'scope':'MS5_TASK2_OBSERVER_SOURCE_COPY','run_id':self.binding['run_id'],'source_sha':self.binding['source_sha'],'freeze_sha256':self.binding['freeze_sha256'],
            'observer_source_sha256':self.hash(self.canon(self.observer)),
            'originals':[{**r,'path':self.observer['host_source_root']+'/'+r['path']} for r in self.observer['members']],
            'copies':[{**r,'path':'task2-observer/'+r['path']} for r in self.observer['members']]};self.equal(receipt,expected)
        browser=tools['browser_source'];self.equal(browser['source'],self.browser);self.need(browser['manifest']['descriptor']['path']=='/run/ms4-browser-source/PINS.json','NATIVE_ACTUAL_AUX_MANIFEST');self.logical(browser['manifest'])
        self.keys(browser['members'],[r['path'] for r in self.browser['members']])
        for member in self.browser['members']:
            row=browser['members'][member['path']];self.need(row['descriptor']['path']=='/run/ms4-browser-source/'+member['path'],'REAL_BROWSER_MOUNT_MEMBER');self.logical(row)
            self.equal({k:row['descriptor'][k] for k in ('bytes','sha256')},{k:member[k] for k in ('bytes','sha256')})
        # writer is the complete native public metadata; private credential bodies are never read on the Host.
        writer=p['private_inputs']['writer'];self.equal(writer,self.prepared['writer']);self.need(writer['mode']=='0600' and writer['directory_mode']=='0700' and writer['nlink']==1 and writer['bytes']>0,'NATIVE_PRIVATE_WRITER_0600_0700_SINGLE_LINK')
    def scene(self,kind):
        prefix='ui-task2'+('' if kind=='paid' else '-'+kind);window,wd=self.read('ui-task2-'+kind+'-read-window.json');self.typed(window,'window');self.equal(window['binding'],self.binding)
        self.need(window['scope']=='MS5_TASK2_'+kind.upper()+'_READ_WINDOW_ONLY' and window['status']=='VERIFIED_MS5_TASK2_'+kind.upper()+'_READ_NATIVE_WINDOW_ONLY','ACTUAL_SCENE_WINDOW')
        source,sd=self.read(prefix+'-observer-source.json',window['observer_source']);self.keys(source,('schema','scope','binding','files','observer_source','scenario'))
        core=[{'path':'/workspace/'+path,**self.data['php_sources'][path]} for path in self.data['php_sources']];tools=[{**r,'path':'/run/audit/task2-observer/'+r['path']} for r in self.observer['members']]
        self.equal(source,{'schema':1,'scope':'MS5_TASK2_FROZEN_PHP_SOURCE_BINDING','binding':self.binding,'files':core+tools,'observer_source':self.observer,'scenario':'MS5_TASK2_'+kind.upper()+'_READ_V1'})
        created,frames=self.native(window['observer_native'],kind);baseline,bd=self.read(prefix+'-baseline.json',window['baseline']);self.ledger(baseline,0,'baseline');self.equal(baseline,frames[0]['value']['receipt']['baseline'])
        previous=bd['sha256'];last=baseline;steps=[];target=self.preparation[kind]['target'];self.need(len(window['steps'])==9,'NINE_ORIGINAL_BUSINESS_STEPS')
        for index,descriptor in enumerate(window['steps'],1):
            n=str(index).zfill(2);step,step_d=self.read(prefix+'-business-step-'+n+'.json',descriptor)
            self.keys(step,('schema','scope','scenario','binding','observer_source','index','name','previous_step_sha256','wire','before','after','verified_after','expected_delta','oracle','observer_event','browser_event','diagnostic'))
            self.need(type(step['schema']) is int and step['schema']==1 and step['scope']=='MS5_TASK2_NORMAL_READ_BUSINESS_STEP' and step['scenario']==source['scenario'] and type(step['index']) is int
                and step['index']==index and step['name']==self.STEPS[index-1] and step['previous_step_sha256']==previous and step['diagnostic'] is None,'WHOLE_ORIGINAL_STEP_CHAIN')
            self.equal(step['binding'],self.binding);self.equal(step['observer_source'],self.observer)
            before,bb=self.read(prefix+'-'+n+'-before.json',step['before']);after,aa=self.read(prefix+'-'+n+'-after.json',step['after']);verified,vv=self.read(prefix+'-'+n+'-verified.json',step['verified_after'])
            expected,ee=self.read(prefix+'-'+n+'-expected.json',step['expected_delta']);event,ev=self.read(prefix+'-browser-step-'+n+'.json',step['browser_event'])
            for value,point in [(before,'before'),(after,'after'),(verified,'verified')]:self.ledger(value,index,point)
            self.state(last,before);self.state(after,verified);begin=frames[2*index-1]['value']['receipt'];ack=frames[2*index]['value']['receipt'];self.equal(before,begin['before'])
            for key,value in [('after',after),('verified_after',verified),('expected_delta',expected)]:self.equal(ack[key],value)
            routes=self.event(event,step['wire'],index,kind,target);facts=self.expected(expected,index,kind,target,event,step['wire'],routes,before,after)
            oracle_source={**next(x for x in self.observer['members'] if x['path']=='expected.php'),'path':'/run/audit/task2-observer/expected.php'}
            oracle={'source':oracle_source,'case_name':step['name'],'expected':expected,'actual':after,'readback':verified,'observer_source_sha256':self.hash(self.canon(self.observer))};self.equal(ack['oracle'],oracle)
            self.equal(step['oracle'],{**oracle,'expected':ee,'actual':aa,'readback':vv})
            request={'schema':1,'binding':self.binding,'sequence':2*index,'command':'STEP_VERIFY','step':index,'wire_public':{'wire':step['wire'],'browser_event':event},'observer_source':self.observer}
            frame=frames[2*index];self.equal(step['observer_event'],{'observer_pid':created['pid'],'request_index':2*index,'request_sha256':self.hash(self.canon(request)),
                'response_index':2*index,'response_sha256':frame['sha256'],'stdout_start':frame['start'],'stdout_end':frame['end'],'terminal_required':'SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME'})
            steps.append((step,event,facts,step_d,frame));previous=step_d['sha256'];last=verified
        final,fd=self.read(prefix+'-final.json',window['final']);self.ledger(final,10,'final');self.equal(final,frames[19]['value']['receipt']['final']);self.state(last,final)
        preserved=frames[19]['value']['receipt']['preservation'];self.preservation(preserved);self.keys(window['preservation'],preserved)
        for key in preserved:
            value,desc=self.read(prefix+'-'+key.replace('_','-')+'-preserved.json',window['preservation'][key]);self.equal(value,preserved[key])
        self.equal(window['coverage'],steps[-1][2]['state_coverage']);dom=self.DOM(kind,prefix,window,wd,steps)
        return {'state':kind,'window':wd,'dom':dom,'native':window['observer_native'],'coverage':window['coverage']},baseline,final,preserved
    def consume(self,ui20_baseline,owned):
        start=set(self.files);scenes=[];last=None;preserved=None
        for kind in ('paid','unpaid','cancelled'):
            before=set(self.files);record,baseline,final,current=self.scene(kind)
            new=set(self.files)-before;self.need(sum(name.startswith(('ui-task2',)) for name in new)==104,'EXACT_104_SCENE_ORIGINALS')
            if last is not None:self.state(last,baseline)
            if preserved is not None:self.equal(preserved,current)
            preserved=current;last=final;scenes.append(record)
        self.equal(self.tabs,owned);self.state(last,ui20_baseline)
        self.need(sum(name.startswith('ui-task2') for name in set(self.files)-start)==312,'ALL_312_NATIVE_DOM_ORIGINALS')
        originals=dict(self.files)
        for name,descriptor in originals.items():self.read(name,descriptor,True,name.endswith('.stderr'))
        self.equal(self.files,originals)
        return {'schema':1,'scope':'MS5_TASK2_THREE_SCENE_PUBLIC_ORIGINALS_CONSUMED_ONLY','binding':self.binding,'observer_source':self.observer,'scenes':scenes,'files':originals}


def ui_business_precondition(context_file, expected_binding):
    # This explicit gate remains until Root seals and adopts the actual eight-member manifest.
    _uib_need(UI_BUSINESS_AUX_PINS is not None, 'SOURCE_CONTRACT_NOT_CONNECTED')
    _uib_keys(expected_binding, ('run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin'))
    reads = {}; context, root, binding, data, prepared, backend_rows = _uib_source(context_file,expected_binding,reads)
    def top(name):
        raw = _uib_stable(root/'evidence'/name,reads)
        return _uib_json(raw), {'path':'/run/audit/evidence/'+name,**_uib_pin(raw)}
    window, window_descriptor = top('ui-business-window.json')
    window = _uib_leaf(root,window_descriptor,'ui-business-window.json',reads)
    _uib_keys(window, ('schema','scope','status','binding','baseline','steps','final','observer_source','observer_native','preservation','diagnostic','full_acceptance'))
    _uib_need(type(window['schema']) is int and window['schema'] == 1 and window['scope'] == 'MS4_UI_NORMAL_BUSINESS_WINDOW_ONLY'
        and window['status'] == 'VERIFIED_MS4_UI_NORMAL_BUSINESS_WINDOW' and _uib_same(window['binding'],binding)
        and window['diagnostic'] is None and window['full_acceptance'] == 'INCOMPLETE', 'ACTUAL_TYPED_WINDOW12')
    close, close_descriptor = top('ui-browser-normal-close.json')
    attempt = _uib_json(_uib_leaf(root,close['attempt'],'ui-browser-normal-close-attempt.json',reads,False))
    owned = _uib_browser_close(close,attempt,binding); runtime = data['_runtime']; runtime['owned'] = owned
    native = _uib_native(root,window['observer_native'],binding,reads); frames = native['frames']; runtime['native'] = native
    _uib_schema_v1(binding,{'$ref':'#/schemas/binding'},data['schemas'],relation_context=runtime)
    _uib_schema_v1(native['created'],{'$ref':'#/schemas/node_created'},data['schemas'],relation_context=runtime)
    _uib_schema_v1(native['terminal'],{'$ref':'#/schemas/node_native'},data['schemas'],relation_context=runtime)
    for frame in frames:
        runtime['index'] = frame['value']['step']
        _uib_schema_v1(frame['value'],{'$ref':'#/schemas/observer_stdout'},data['schemas'],relation_context=runtime)
    runtime['index'] = 0
    _uib_schema_v1(window,{'$ref':'#/schemas/business_window'},data['schemas'],relation_context=runtime)
    source = _uib_leaf(root,window['observer_source'],'ui-window-observer-source.json',reads)
    _uib_keys(source, ('schema','scope','binding','files'))
    expected_sources = [{'path':'/workspace/'+path,**data['php_sources'][path]} for path in UI_BUSINESS_PHP]
    _uib_need(type(source['schema']) is int and source['schema'] == 1 and source['scope'] == 'MS4_UI_WINDOW_OBSERVER_SOURCE'
        and _uib_same(source['binding'],binding) and source['files'] == expected_sources, 'ACTUAL_OBSERVER_SOURCE3_BACKEND_FROZEN_JOIN')
    baseline = _uib_leaf(root,window['baseline'],'ui-window-baseline.json',reads)
    _uib_need(_uib_same(baseline,frames[0]['value']['receipt']['baseline']), 'ORIGINAL_BEGIN_BASELINE_CANONICAL_MATERIALIZATION')
    _uib_ledger(baseline,binding,0,'baseline')
    if data['_task2_enabled']:
        Task2PublicConsumer(context,binding,data,lambda path,maximum:_uib_stable(path,reads,maximum),_uib_canon).consume(baseline,owned)
    _uib_need(type(window['steps']) is list and len(window['steps']) == 20, 'EXACT_TWENTY_ACTUAL_WINDOW_STEPS')
    previous = window['baseline']['sha256']; previous_state = baseline; selected = []; state = {}; image_witnesses = []
    for index, descriptor in enumerate(window['steps'],1):
        number = str(index).zfill(2); step = _uib_leaf(root,descriptor,'ui-business-step-'+number+'.json',reads)
        _uib_keys(step, ('schema','scope','binding','index','name','previous_step_sha256','wire','before','after','verified_after','expected_delta','oracle','observer_event','browser_event','diagnostic'))
        _uib_need(type(step['schema']) is int and step['schema'] == 1 and step['scope'] == 'MS4_UI_NORMAL_BUSINESS_STEP'
            and _uib_same(step['binding'],binding) and type(step['index']) is int and step['index'] == index
            and step['name'] == UI_BUSINESS_STEPS[index-1] and step['previous_step_sha256'] == previous
            and step['diagnostic'] is None, 'WHOLE_ORIGINAL_STEP_CHAIN')
        wire = step['wire']; _uib_wire(wire)
        before = _uib_leaf(root,step['before'],'ui-window-'+number+'-before.json',reads)
        after = _uib_leaf(root,step['after'],'ui-window-'+number+'-after.json',reads)
        verified = _uib_leaf(root,step['verified_after'],'ui-window-'+number+'-verified.json',reads)
        expected = _uib_leaf(root,step['expected_delta'],'ui-window-'+number+'-expected.json',reads)
        event = _uib_leaf(root,step['browser_event'],'ui-browser-step-'+number+'.json',reads)
        begin = frames[2*index-1]['value']['receipt']; ack = frames[2*index]['value']['receipt']
        _uib_need(_uib_same(before,begin['before']) and _uib_same(after,ack['after'])
            and _uib_same(verified,ack['verified_after']) and _uib_same(expected,ack['expected_delta']), 'MATERIALIZED_PAYLOADS_EQUAL_ORIGINAL_NATIVE_ACK')
        for ledger, point in ((before,'before'),(after,'after'),(verified,'verified')): _uib_ledger(ledger,binding,index,point)
        _uib_need(_uib_same_state(previous_state,before) and _uib_same_state(after,verified), 'ALL_NATIVE_STATE_CONTINUITY_ORACLE_READ_ZERO')
        current = {'step':step,'wire':wire,'event':event,'expected':expected,'before':before,'after':after,'verified':verified,'classes':{}}
        runtime['steps'] = selected+[current]; runtime['index'] = index
        _uib_schema_v1(step,{'$ref':'#/schemas/business_step'},data['schemas'],relation_context=runtime)
        _uib_schema_v1(wire,{'$ref':'#/schemas/wire'},data['schemas'],relation_context=runtime)
        primary = _uib_event(event,wire,index,binding,owned,prepared,data,selected); current['primary_sequence'] = primary
        independent = _uib_expected(expected,index,wire,event,before,after,state,binding,prepared)
        _uib_schema_v1(independent,data['steps'][index-1]['facts'],data['schemas'],relation_context=runtime)
        _uib_schema_v1(expected,{'$ref':'#/schemas/expected_delta'},data['schemas'],relation_context=runtime)
        for ledger in (before,after,verified): _uib_schema_v1(ledger,{'$ref':'#/schemas/ledger'},data['schemas'],relation_context=runtime)
        oracle = step['oracle']; _uib_keys(oracle, ('source','case_name','expected','actual','readback'))
        oracle_source = {'path':'/workspace/'+UI_BUSINESS_PHP[2],**data['php_sources'][UI_BUSINESS_PHP[2]]}
        _uib_need(oracle == {'source':oracle_source,'case_name':step['name'],'expected':step['expected_delta'],
            'actual':step['after'],'readback':step['verified_after']}, 'STEP_ORACLE_FULL_DESCRIPTOR_JOINS')
        _uib_need(_uib_same(ack['oracle'],{'source':oracle_source,'case_name':step['name'],
            'expected':expected,'actual':after,'readback':verified}), 'NATIVE_SOURCE_BOUND_FULL_ORACLE_NOT_BOOL')
        oe = step['observer_event']; _uib_keys(oe, ('observer_pid','request_index','request_sha256','response_index','response_sha256','stdout_start','stdout_end','terminal_required'))
        frame = frames[2*index]
        request = {'schema':1,'binding':binding,'sequence':2*index,'command':'STEP_VERIFY','step':index,
            'wire_public':{'wire':wire,'browser_event':event}}
        _uib_need(type(oe['observer_pid']) is int and oe['observer_pid'] == native['created']['pid']
            and type(oe['request_index']) is int and oe['request_index'] == 2*index
            and type(oe['response_index']) is int and oe['response_index'] == 2*index
            and oe['request_sha256'] == sha(_uib_canon(request)) and oe['response_sha256'] == frame['sha256']
            and type(oe['stdout_start']) is int and type(oe['stdout_end']) is int
            and oe['stdout_start'] == frame['start'] and oe['stdout_end'] == frame['end']
            and oe['terminal_required'] == 'SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME', 'TRUE_NATIVE_PID_REQUEST_HASH_ORIGINAL_HALF_OPEN_RESPONSE_SLICE')
        if 'image' in state:
            for r in event['requests']:
                if r['method'] == 'GET' and r['path'] == state['image']['reference']:
                    _uib_need(r['authentication'] == {'present':False,'kind':'none','subject_id':None}
                        and r['response_public'] == {**state['image']['image'],'mime_type':'image/png'}
                        and r['app_status'] is None, 'REAL_EARLIER_ANONYMOUS_COMPLETE_IMAGE_RESPONSE')
                    witness_wire = {'actor_role':'public','method':r['method'],'path':r['path'],'body_public':r['request_public'],
                        'body_public_sha256':r['request_public_sha256'],'response_status':r['http_status'],
                        'response_public':r['response_public'],'response_public_sha256':r['response_public_sha256']}
                    image_witnesses.append({'step':index,'name':step['name'],'sequence':r['sequence'],'wire':witness_wire})
        if index == 20:
            fact = independent['public_image_read']; matches = [w for w in image_witnesses
                if w['step'] == fact['original_step'] and w['name'] == fact['original_name']
                and w['sequence'] == fact['original_sequence'] and _uib_same(w['wire'],wire)]
            _uib_need(len(matches) == 1 and wire['method'] == 'GET' and wire['path'] == state['image']['reference'], 'TRUE_IMAGE_ORIGINAL_WIRE_WITNESS_JOIN')
            if fact['mode'] == 'fresh-normal-response':
                _uib_need(fact['original_step'] == 20 and fact['original_name'] == step['name'] and primary == fact['original_sequence'], 'FRESH_IMAGE_ACTUAL_CURRENT_HTTP')
            else:
                _uib_need(primary is None and 1 <= fact['original_step'] < 20
                    and not any(r['method'] == 'GET' and r['path'] == state['image']['reference'] for r in event['requests']), 'HISTORICAL_IMAGE_NO_FABRICATED_CURRENT_HTTP')
        current['independent'] = independent; current['state'] = _uib_json(_uib_canon(state)); selected.append(current); runtime['steps'] = selected
        previous = descriptor['sha256']; previous_state = verified
    final = _uib_leaf(root,window['final'],'ui-window-final.json',reads)
    _uib_ledger(final,binding,21,'final')
    _uib_need(_uib_same(final,frames[41]['value']['receipt']['final']) and _uib_same_state(previous_state,final), 'ORIGINAL_FINISH_ALLSTATE_CONTINUITY')
    _uib_preservation(root,window['preservation'],binding,frames[41]['value'],backend_rows,context,reads,data)
    DOM, dom_descriptor = top('ui-business-dom.json')
    DOM = _uib_dom(root,dom_descriptor,binding,window_descriptor,selected,owned,prepared,data,reads)
    _uib_need(DOM['preservation'] == window['preservation'], 'DOM_SAME_FOUR_PRESERVATION_ORIGINALS')
    runtime['DOM'] = DOM; _uib_schema_v1(DOM,{'$ref':'#/schemas/DOM_final'},data['schemas'],relation_context=runtime)
    _uib_relations(data['dom_rules']['relations'],DOM,runtime)
    _uib_validate_relations(runtime)
    for index, item in enumerate(selected,1):
        fragment = item['event']['DOM']
        if index != 20 and fragment is not None:
            _uib_desc(fragment)
            _uib_need(sum(fragment == d for d in DOM['observations']) == 1, 'BROWSER_EVENT_ACTUAL_DOM_FRAGMENT_IN_FINAL_CLOSURE')
            observation = _uib_json(_uib_stable(root/'evidence'/fragment['path'].split('/')[-1],reads))
            _uib_need(observation['window_step'] == index and observation['tab_id_sha256'] == item['event']['tab_id_sha256'], 'DOM_FRAGMENT_SAME_ACTUAL_STEP_TAB')
    canonical_names = {'ui-window-baseline.json','ui-window-final.json','ui-window-observer-source.json',
        'ui-window-source-preserved.json','ui-window-predecessors-preserved.json',
        'ui-window-private-inputs-preserved.json','ui-window-public-materials-preserved.json','ui-business-window.json'}
    for i in range(1,21):
        n = str(i).zfill(2)
        canonical_names.update(('ui-window-'+n+'-before.json','ui-window-'+n+'-after.json',
            'ui-window-'+n+'-verified.json','ui-window-'+n+'-expected.json','ui-browser-step-'+n+'.json','ui-business-step-'+n+'.json'))
    _uib_need(len(canonical_names) == 128 and all(str(root/'evidence'/name) in reads for name in canonical_names), 'ALL_128_CANONICAL_ORIGINALS_CONSUMED')
    # Full independent second pass over every consumed ordinary original, not an earlier pass boolean.
    first = dict(reads)
    for path in sorted(first): _uib_stable(Path(path),reads)
    _uib_need(reads == first, 'FULL_BEFORE_AFTER_SOURCE_AND_EVIDENCE_CLOSURE_IDENTICAL')
    second_context, second_root, second_binding, second_data, second_prepared, second_sources = _uib_source(context_file,expected_binding,reads)
    _uib_need(_uib_same(second_context,context) and second_root == root and _uib_same(second_binding,binding)
        and _uib_data_same(second_data,data) and _uib_same(second_prepared,prepared) and second_sources == backend_rows,
        'SECOND_COMPLETE_SOURCE_READY_HANDOFF_P17_AUX_CLOSURE')
    return {'binding':binding,'window':window_descriptor,'DOM':dom_descriptor,'browser_close':close_descriptor,
        'auxiliary_sources':{'path':'browser-v1/PINS.json',**UI_BUSINESS_AUX_PINS},'observer_native':window['observer_native']}



def ui_host_module():
    if not HOST.is_file() or HOST.is_symlink() or sha(HOST.read_bytes()) != UI_HOST_SHA256:
        raise RuntimeError('EXACT_REVIEWED_UI_HOST_SOURCE_REQUIRED')
    spec = importlib.util.spec_from_file_location('ms4_explicit_ui_close_host', HOST)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    STATE['module'] = module
    return module


def ui_close_file(path):
    path = Path(path)
    for part in [path, *path.parents]:
        if part.is_symlink() or getattr(part, 'is_junction', lambda: False)():
            raise RuntimeError('UI_PUBLIC_FILE_NO_LINK_OR_REPARSE')
    before = path.lstat()
    if not path.is_file() or before.st_nlink != 1:
        raise RuntimeError('UI_PUBLIC_ORDINARY_SINGLE_FILE')
    raw = path.read_bytes()
    after = path.lstat()
    fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    if any(getattr(before, key) != getattr(after, key) for key in fields) or len(raw) != before.st_size:
        raise RuntimeError('UI_PUBLIC_FULL_STABLE_READ')
    return raw


def ui_close_public(context):
    root = Path(context['run_dir'])
    result = {}
    pending = [root]
    fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_mtime_ns', 'st_ctime_ns']
    while pending:
        folder = pending.pop()
        if not folder.is_dir() or folder.is_symlink() or getattr(folder, 'is_junction', lambda: False)():
            raise RuntimeError('UI_ORIGINAL_PUBLIC_ORDINARY_DIRECTORY')
        before = folder.lstat()
        children = sorted(folder.iterdir(), key=lambda path: path.name)
        names = [path.name for path in children]
        for path in children:
            if path.is_symlink() or getattr(path, 'is_junction', lambda: False)():
                raise RuntimeError('UI_ORIGINAL_PUBLIC_NO_LINKS')
            if path.is_file():
                data = ui_close_file(path)
                result[path.relative_to(root).as_posix()] = {'bytes': len(data), 'sha256': sha(data)}
            elif path.is_dir():
                pending.append(path)
            else:
                raise RuntimeError('UI_ORIGINAL_PUBLIC_ONLY_FILES_AND_DIRECTORIES')
        after = folder.lstat()
        if names != sorted(path.name for path in folder.iterdir()) or any(getattr(before, key) != getattr(after, key) for key in fields):
            raise RuntimeError('UI_ORIGINAL_PUBLIC_COMPLETE_STABLE_DIRECTORY')
    return dict(sorted(result.items()))


def ui_close_preserved(module, context_file, context, preservation):
    module.task2_observer_current(context)
    module.expect(module.current(context_file) == context, 'UI_SAME_COMPLETE_SOURCE_CONTEXT')
    root = Path(context['run_dir'])
    module.verify_ui_web_freeze(module.load(root / 'preparation-source-binding.json'), context['repo_dir'])
    for relative, expected in preservation.items():
        module.expect(not re.search(r'(^/|\\|(^|/)\.\.?(/|$)|:)', relative), 'UI_KEEP_CANONICAL_PUBLIC_PATH')
        raw = ui_close_file(root / relative)
        module.expect(expected == {'bytes': len(raw), 'sha256': sha(raw)}, 'UI_ALL_ORIGINAL_PUBLIC_BYTES_PRESERVED')


def ui_close_created(module, context, expected):
    originals = {}
    for cid, role in expected.items():
        if role in context['initial_service_created']:
            value = context['initial_service_created'][role]
        elif role == module.PAID['role']:
            value = module.bound_created(context, module.PAID['proof'], role)
        else:
            proof = module.UI_WEB_BACKEND['proof'] if role == module.UI_WEB_BACKEND['role'] else module.UI_WEB_GATEWAY['proof']
            value = module.ui_web_created(context, proof)
        module.expect(value['id'] == cid and value['status'] == 'created' and value['pid'] == 0, 'UI_ACTUAL_ORIGINAL_CREATED_IDENTITY')
        originals[cid] = value
    module.expect(len(originals) == 5 and set(expected.values()) == {
        module.PAID['role'], module.UI_WEB_BACKEND['role'], module.UI_WEB_GATEWAY['role'], 'audit-mysql', 'audit-redis'},
        'UI_EXACT_FIVE_DISTINCT_ORIGINAL_ROLES')
    return originals


def ui_close_facts(module, context, original, role, label, terminal_required=False):
    facts = module.ui_copy_inspect(context, original['id'], label)
    if role in [module.UI_WEB_BACKEND['role'], module.UI_WEB_GATEWAY['role']]:
        module.ui_web_owned(context, facts, role)
    else:
        module.owned(context, facts, role)
    module.same_created(original, facts)
    if terminal_required:
        module.expect(facts['status'] == 'exited' and facts['pid'] == 0 and type(facts['exit_code']) is int
            and facts['exit_code'] == 0 and facts['restart_count'] == 0, 'UI_ORIGINAL_SAME_NORMAL_TERMINAL')
    return facts


def ui_close_native(module, context, label, argv, stdout_forms):
    announce(label, no_force_restart_retry=True)
    raw, native, projection = module.ui_copy_native(context, label, argv)
    module.expect(raw in stdout_forms, 'UI_EXACT_ORDINARY_NATIVE_STDOUT_' + label)
    return {'label': label, 'native': native, 'projection': projection}


def ui_close_inventory(module, context, require_raw_empty=False):
    values = {}
    for kind in ['container', 'network', 'volume']:
        values[kind] = {}
        for label, value in [('project', 'com.docker.compose.project=' + context['project']),
                             ('run', 'tianli.audit.run=' + context['run_id'])]:
            args = {'container':['ps','-aq','--no-trunc'], 'network':['network','ls','-q'], 'volume':['volume','ls','-q']}[kind]
            raw, native, projection = module.ui_copy_native(context, 'copy-list-' + kind,
                ['docker', *args, '--filter', 'label=' + value])
            if require_raw_empty:
                stderr = ui_close_file(Path(context['run_dir']) / 'evidence' / Path(projection['path']).with_suffix('.stderr').name)
                module.expect(raw == stderr == b'' and native['native_exit'] == native['wait_returncode'] == 0
                    and native['stdout_bytes'] == native['stderr_bytes'] == 0
                    and native['stdout_sha256'] == native['stderr_sha256'] == sha(b''), 'UI_FINAL_SIX_NATIVE_RAW_BOTH_EMPTY')
            rows = raw.decode().splitlines()
            module.expect(len(rows) == len(set(rows)), 'UI_UNIQUE_LABEL_RESOURCE_LIST')
            if kind == 'network':
                resolved = []
                for item in rows:
                    raw, _, _ = module.ui_copy_native(context, 'ui-close-network-full-id',
                        ['docker', 'network', 'inspect', '--format', '{{.Id}}', item])
                    identity = raw.decode().strip()
                    module.expect(re.fullmatch('[a-f0-9]{64}', identity), 'UI_NATIVE_FULL_NETWORK_ID')
                    resolved.append(identity)
                rows = resolved
            values[kind][label] = sorted(rows)
    return values


def ui_close_global(module, context, expected, allowed):
    evidence = Path(context['run_dir']) / 'evidence'
    historical = set()
    for path in evidence.glob('*-created.json'):
        row = module.load(path)
        module.expect(type(row) is dict and row['run_id'] == context['run_id'] and row['source_sha'] == context['source_sha']
            and type(row['id']) is str and re.fullmatch('[a-f0-9]{64}', row['id'])
            and row['status'] == 'created' and type(row['pid']) is int and row['pid'] == 0,
            'UI_ALL_ACTUAL_HISTORICAL_CREATED_IDENTITIES')
        historical.add(row['id'])
    targets = set(expected)
    historical -= targets
    module.expect(len(targets) == 5 and set(allowed) <= targets, 'UI_GLOBAL_EXACT_ORIGINAL_TARGETS')
    frontend = module.load(evidence / 'ui-web-frontend-network.json')['network']
    networks = {'ids': sorted([context['network_id'], frontend['Id']]),
                'names': sorted([context['initial_network']['Name'], frontend['Name']])}
    volumes = sorted(context['project'] + '_' + name for name in module.VOLUMES + module.UI_COPY_WEB_VOLUMES)
    module.expect(len(set(networks['ids'])) == 2 and len(set(volumes)) == 13, 'UI_GLOBAL_TWO_NETWORKS_THIRTEEN_VOLUMES')
    initializer = module.bound_created(context, 'initializer', 'audit-ms4-initialize')
    tree = [mount for mount in initializer['mounts'] if mount['Destination'] == module.SOURCE_TREE_WRITER_DESTINATION]
    module.expect(len(tree) == 1 and tree[0]['Type'] == 'volume' and tree[0]['RW'] is True
        and tree[0]['Name'] == context['source_tree']['volume_name'] and type(tree[0]['Source']) is str
        and tree[0]['Source'].startswith('/'), 'UI_CLOSE_SOURCE_TREE_ORIGINAL_PHYSICAL_WRITER_ROOT')
    tree_root = tree[0]['Source'].rstrip('/')
    operations = []
    def query(label, argv):
        raw, native, projection = module.ui_copy_native(context, label, argv)
        operations.append({'label': label, 'native': native, 'projection': projection})
        return raw
    ids = query('ui-close-global-enumerate', ['docker', 'ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in ids),
        'UI_GLOBAL_COMPLETE_UNIQUE_IDS')
    module.expect(not set(ids).intersection(historical | (targets - set(allowed))), 'UI_ALL_HISTORICAL_AND_REMOVED_CIDS_ABSENT')
    fmt = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    rows = []
    if ids:
        raw = query('ui-close-global-projections', ['docker', 'inspect', '--format', fmt, *ids])
        rows = [json.loads(line) for line in raw.decode().splitlines()]
    module.expect(len(rows) == len(ids) and {row['id'] for row in rows} == set(ids), 'UI_GLOBAL_FULL_BATCH_MEMBERSHIP')
    for row in rows:
        module.expect(set(row) == {'id', 'mounts', 'network_mode', 'networks'} and type(row['mounts']) is list
            and all(type(item) is dict for item in row['mounts']) and type(row['network_mode']) is str
            and type(row['networks']) is dict and all(type(item) is dict for item in row['networks'].values()),
            'UI_GLOBAL_ALL_NATIVE_CONSUMER_FIELDS')
        source_mounts = [mount for mount in row['mounts'] if
            mount.get('Name') == context['source_tree']['volume_name']
            or (type(mount.get('Source')) is str and (mount['Source'].rstrip('/') == tree_root
                or mount['Source'].startswith(tree_root + '/')))]
        module.expect(not source_mounts or (row['id'] in allowed and all(mount.get('RW') is False for mount in source_mounts)),
            'UI_CLOSE_SOURCE_TREE_NO_FOREIGN_OR_WRITABLE_ALIAS')
        mode = row['network_mode']
        uses = (any(item.get('Name') in volumes for item in row['mounts'])
            or mode in set(networks['ids'] + networks['names'])
            or bool(set(row['networks']).intersection(networks['ids'] + networks['names']))
            or any(item.get('NetworkID') in networks['ids'] for item in row['networks'].values())
            or (mode.startswith('container:') and mode[10:] in targets | historical))
        module.expect(not uses or row['id'] in allowed, 'UI_NO_FOREIGN_VOLUME_NETWORK_OR_NAMESPACE_CONSUMER')
    end = query('ui-close-global-enumerate-after', ['docker', 'ps', '-aq', '--no-trunc']).decode().splitlines()
    module.expect(len(end) == len(set(end)) and set(end) == set(ids), 'UI_GLOBAL_STABLE_COMPLETE_CID_SET')
    return {'ids': sorted(ids), 'historical_absent': sorted(historical), 'targets': sorted(targets),
        'allowed': sorted(allowed), 'networks': networks, 'volumes': volumes,
        'operations': operations, 'unexpected_consumers': []}


def ui_close_resources(module, context, expected, remaining):
    module.ui_web_collect(context, remaining, True)
    networks = module.ui_web_networks(context, remaining, True)
    volumes = module.ui_copy_volumes(context)
    observed = ui_close_global(module, context, expected, set(remaining))
    return {'expected': remaining, 'networks': networks, 'volumes': volumes,
            'historical_absent': observed['historical_absent']}


def ui_close(context_file):
    module = ui_host_module()
    context = module.current(context_file)
    module.proof_ini_audit(context, coverage=True)
    module.task2_observer_current(context)
    STATE['context'] = context
    root = Path(context['run_dir']); evidence = root / 'evidence'
    module.verify_ui_web_freeze(module.load(root / 'preparation-source-binding.json'), context['repo_dir'])
    module.ui_web_copy_join(context)
    module.ui_web_formal(context)
    expected = module.ui_web_targets(context)
    originals = ui_close_created(module, context, expected)
    for cid, role in expected.items():
        facts = ui_close_facts(module, context, originals[cid], role, 'ui-close-initial-identity',
                               terminal_required=role == module.PAID['role'])
        if role != module.PAID['role']:
            module.expect(facts['status'] == 'running' and facts['pid'] > 0, 'UI_ONLY_ORIGINAL_RUNNING_WEB_AND_DATABASES')
    resources = ui_close_resources(module, context, expected, expected)
    ready, ready_descriptor = module.ui_web_ready(context)
    module.expect(ready is not None, 'UI_SAME_ORIGINAL_BACKEND_READY_REQUIRED')
    port = module.ui_web_port_check(context, True)
    backend = next(cid for cid, role in expected.items() if role == module.UI_WEB_BACKEND['role'])
    gateway = next(cid for cid, role in expected.items() if role == module.UI_WEB_GATEWAY['role'])
    known_binding = {'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': sha(ui_close_file(context_file)), 'backend_cid': backend, 'gateway_cid': gateway,
        'ready_sha256': ready_descriptor['sha256'], 'origin': port['origin']}
    # This call must independently validate the real ninth source_closure and all nested proof.
    business = ui_business_precondition(context_file, known_binding)
    module.expect(not (evidence / 'ui-web-backend-stop-attempt.json').exists()
        and not (evidence / 'ui-web-backend-parent-native.json').exists(), 'UI_CLOSE_ONLY_ONE_FIRST_NORMAL_STOP')
    module.new(evidence / 'ui-normal-close-attempt.json', {'schema': 1, 'scope': UI_CLOSE_SCOPE,
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': known_binding['context_sha256'], 'host_source_sha256': UI_HOST_SHA256,
        'close_source_sha256': sha(ui_close_file(Path(__file__))), 'original_expected': expected,
        'operations_started_at_receipt_time': False})
    preservation = ui_close_public(context)
    module.new(evidence / 'ui-normal-close-before.json', {'expected': expected, 'created': originals,
        'networks': resources['networks'], 'volumes': resources['volumes'],
        'historical_absent': resources['historical_absent'], 'preservation': preservation, 'business': business})
    ui_close_preserved(module, context_file, context, preservation)
    module.ui_web_normal_stop(context)
    operations = [ui_close_native(module, context, 'ui-close-backend-wait',
        ['docker', 'wait', backend], {b'0\n'})]
    parent = module.ui_web_parent_result(context)
    for role, suffix in [(module.UI_WEB_GATEWAY['role'], 'gateway'), ('audit-mysql', 'mysql'), ('audit-redis', 'redis')]:
        ui_close_resources(module, context, expected, expected)
        cid = next(cid for cid, value in expected.items() if value == role)
        current = ui_close_facts(module, context, originals[cid], role, 'ui-close-before-stop-' + suffix)
        module.expect(current['status'] == 'running' and current['pid'] > 0, 'UI_STOP_ONLY_SAME_RUNNING_ORIGINAL')
        operations.append(ui_close_native(module, context, 'ui-close-stop-' + suffix,
            ['docker', 'stop', '--timeout', '-1', cid],
            {(cid + '\n').encode()}))
        after = ui_close_facts(module, context, originals[cid], role, 'ui-close-after-stop-' + suffix, True)
        module.persist_terminal(context, 'ui-normal-close-' + suffix, after)
        ui_close_preserved(module, context_file, context, preservation)
    stopped = {cid: ui_close_facts(module, context, originals[cid], role, 'ui-close-all-stopped', True)
               for cid, role in expected.items()}
    module.new(evidence / 'ui-normal-close-all-stopped.json',
        {'containers': stopped, 'resources': ui_close_resources(module, context, expected, expected)})
    operations.append(ui_close_native(module, context, 'ui-close-rm-containers',
        ['docker', 'rm', *sorted(expected)], {(''.join(cid + '\n' for cid in sorted(expected))).encode()}))
    empty = ui_close_resources(module, context, expected, {})
    module.expect(all(value['Containers'] == {} for value in empty['networks'].values()), 'UI_BOTH_ORIGINAL_NETWORKS_EMPTY')
    module.new(evidence / 'ui-normal-close-before-network-volume-rm.json', empty)
    for name in ['internal', 'frontend']:
        identity = empty['networks'][name]
        raw, _, _ = module.ui_copy_native(context, 'ui-close-empty-network-recheck',
            ['docker', 'network', 'inspect', '--format', module.UI_WEB_NETWORK_FORMAT, identity['Id']])
        module.expect(module.paid_equal(json.loads(raw), identity) and identity['Containers'] == {},
            'UI_EXACT_SAME_EMPTY_NETWORK_IMMEDIATELY_BEFORE_REMOVE')
        ui_close_global(module, context, expected, set())
        operations.append(ui_close_native(module, context, 'ui-close-rm-' + name,
            ['docker', 'network', 'rm', identity['Id']], {(identity['Id'] + '\n').encode()}))
    remaining = ui_close_inventory(module, context)
    names = sorted(row['name'] for row in empty['volumes'])
    module.expect(remaining['container'] == remaining['network'] == {'project': [], 'run': []}
        and remaining['volume'] == {'project': names, 'run': names}, 'UI_ONLY_THIRTEEN_ORIGINAL_VOLUMES_REMAIN')
    fmt = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    volumes = []
    for name in names:
        raw, _, _ = module.ui_copy_native(context, 'ui-close-final-volume-identity',
            ['docker', 'volume', 'inspect', '--format', fmt, name])
        volumes.append(json.loads(raw))
    module.expect(module.paid_equal(volumes, empty['volumes']), 'UI_ALL_THIRTEEN_FULL_IDENTITIES_IMMEDIATELY_BEFORE_REMOVE')
    global_before = ui_close_global(module, context, expected, set())
    module.new(evidence / 'ui-normal-close-before-volume-rm.json',
        {'inventory': remaining, 'volumes': volumes, 'global': global_before})
    ui_close_preserved(module, context_file, context, preservation)
    operations.append(ui_close_native(module, context, 'ui-close-rm-volumes',
        ['docker', 'volume', 'rm', *names], {(''.join(name + '\n' for name in names)).encode()}))
    final = ui_close_inventory(module, context, require_raw_empty=True)
    module.expect(all(not values for labels in final.values() for values in labels.values()), 'UI_FINAL_FRESH_SIX_RAW_LISTS_ZERO')
    final_global = ui_close_global(module, context, expected, set())
    final_port = module.ui_web_port_check(context, False)
    ui_close_preserved(module, context_file, context, preservation)
    # Re-run the real pure evidence verifier, never a cached success boolean.
    module.expect(module.paid_equal(ui_business_precondition(context_file, known_binding), business),
        'UI_REAL_BUSINESS_BROWSER_EVIDENCE_REMAINS_IDENTICAL')
    result = {'schema': 1, 'scope': UI_CLOSE_SCOPE, 'status': 'VERIFIED_MS4_UI_NORMAL_CLOSE_ZERO_RESIDUAL',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': known_binding['context_sha256'], 'host_source_sha256': UI_HOST_SHA256,
        'close_source_sha256': sha(ui_close_file(Path(__file__))), 'original_expected': expected,
        'resources': {'containers': 5, 'networks': 2, 'volumes': 13}, 'operations': operations,
        'preservation': preservation, 'business': business, 'parent': parent, 'final_inventory': final,
        'final_global': final_global, 'final_port': final_port, 'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    module.new(evidence / 'ui-normal-close-result.json', result)
    announce('ui_normal_close_verified_zero_residual', run_id=context['run_id'], full_acceptance='INCOMPLETE')
    return result


def ui_close_failure(error):
    value = {'schema': 1, 'scope': UI_CLOSE_SCOPE, 'status': 'INCOMPLETE_UI_NORMAL_CLOSE_STATE_PRESERVED',
        'phase': STATE['phase'], 'class': type(error).__name__, 'no_force_restart_retry': True,
        'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    if str(error) == 'UI_BUSINESS_SOURCE_CONTRACT_NOT_CONNECTED':
        value['missing_source_contract'] = 'UI_BUSINESS_SOURCE_CONTRACT_NOT_CONNECTED'
    module, context = STATE['module'], STATE['context']
    if module is not None and context is not None:
        try:
            module.new(Path(context['run_dir']) / 'evidence' / 'ui-normal-close-failure.json', value)
        except Exception as receipt:
            value['receipt_unavailable_class'] = type(receipt).__name__
    print(json.dumps(value, ensure_ascii=False), flush=True)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--context', required=True, help='The single original actual-run-context.json input')
    parser.add_argument('--ui-web', action='store_true', required=True,
        help='This new B-only Source requires the complete original UI lifecycle and business/browser verifier')
    arguments = parser.parse_args()
    try:
        ui_close(arguments.context)
    except Exception as error:
        ui_close_failure(error)
        raise SystemExit(1)
