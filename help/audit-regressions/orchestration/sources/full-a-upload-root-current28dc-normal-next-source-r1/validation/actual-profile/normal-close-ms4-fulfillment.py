"""SOURCE CANDIDATE: ordinary aftersale/review normal close; no force/retry/restart.

Root alone executes after source review. Newnormal and paid must naturally exit
zero; historical Task6, early and helpers must already be absent. Current closure
is exactly four CIDs, one empty internal network and eight native named volumes.
"""
import argparse
import hashlib
import importlib.util
import json
import re
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parent
HOST = ROOT / 'ms4-host.py'
HOST_SHA256 = '9843cc96ab5b88d998c104493c6f786a687c903e6e9604ffac925cb39edbbb6d'
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


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--context', required=True, help='The single original actual-run-context.json input')
    parser.add_argument('--worker-normal', action='store_true', help='Explicit complete non-due Worker close; default remains original normal close')
    arguments = parser.parse_args()
    try:
        (worker_close if arguments.worker_normal else close)(arguments.context)
    except Exception as error:
        (worker_failure if arguments.worker_normal else failure)(error)
        raise SystemExit(1)
