"""SOURCE ONLY. Independent six-query readback after a complete normal H5 close.

Imports no host/close/App module. The actual Run freeze supplies source hashes;
Root must supply the observed native-zero close-result SHA. No private path is
read and no stop/removal/retry is performed by this independent verifier.
"""
import argparse
import base64
import itertools
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess

PREP = Path(__file__).resolve().parent
H5_ARTIFACTS = ['H5-README.md','compose.h5.yml','h5-derivation.json','ms4-h5-host.py','ms4-h5-materials.py',
    'ms4-h5-bootstrap.php','ms4-h5-public-copy.php','ms4-h5-nginx.conf','ms4-order.png','inputs/language.json',
    'inputs/theme.json','manifests/public-source.json','manifests/h5-dist.json','manifests/inputs.json','h5-materials-result.json',
    'browser-v1/customer-network-observer.mjs','browser-v1/ms4-customer-private-bridge.mjs',
    'browser-v1/ms4-customer-browser.mjs','normal-close-customer-h5.py','verify-zero-customer-h5.py']
BASE_ARTIFACTS = ['README.md','compose.yml','ms4-host.py','ms4-initialize.php','seed-source-inverse.json','interface-v1.json','source-derivation.json','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']
ROOTS = ['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config']
EXTRA = ['crmeb/composer.json','crmeb/composer.lock','crmeb/public/index.php','crmeb/public/router.php','crmeb/think',
    'help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php',
    'help/audit-regressions/isolated/ms4-customer-http-normal.php','help/audit-regressions/isolated/lib/ms4-customer-http-normal.php']
H5_SOURCES = ['help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php','help/audit-regressions/isolated/ms4-customer-h5.php']
VOLUMES = ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets', 'http-runtime', 'http-control', 'http-browser-secrets', 'http-public']
ROLES = ['audit-ui','audit-ms4-customer-h5','audit-mysql','audit-redis']
PAID_ROLE = 'audit-ms5-paid-producer'
FINAL_ROLES = [*ROLES, PAID_ROLE]
PROOFS = {'audit-ui':'customer-h5-ui','audit-ms4-customer-h5':'customer-h5-serve'}
IMMUTABLE = ['id','image','hostname','labels','tmpfs','read_only','privileged','network_mode','command','entrypoint',
    'workdir','port_bindings','cap_drop','security_options','created_at','user','stop_signal']
SCOPE_LIMIT = 'OBSERVED_27_BUSINESS_PLUS_7_AUXILIARY_TABLES_ONLY'


def require(value, code):
    if not value:
        raise RuntimeError(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def regular(path):
    path = Path(path); before = path.lstat()
    for parent in path.parents:
        stat = parent.lstat()
        require(parent.is_dir() and not parent.is_symlink() and not getattr(stat,'st_file_attributes',0) & 0x400,
                'H5_ZERO_PARENT_NO_REPARSE')
    require(path.is_file() and not path.is_symlink() and not getattr(before,'st_file_attributes',0) & 0x400,
            'H5_ZERO_REGULAR_NO_REPARSE')
    raw = path.read_bytes(); after = path.lstat()
    require(len(raw) == before.st_size and all(getattr(before,key) == getattr(after,key) for key in
        ['st_dev','st_ino','st_mode','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']), 'H5_ZERO_UNCHANGED_PUBLIC_BYTES')
    return raw


def load(path):
    return json.loads(regular(path).decode('utf-8-sig'))


def new(path, value):
    path = Path(path); require(path.parent.is_dir() and not path.exists() and not path.is_symlink(), 'H5_ZERO_CREATE_ONLY')
    raw = (json.dumps(value,ensure_ascii=False,indent=2) + '\n').encode()
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'H5_ZERO_COMPLETE_WRITE'); stream.flush()
    require(regular(path) == raw, 'H5_ZERO_COMPLETE_READBACK')


def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False)


def same_facts(left, right):
    require(type(left) is dict and type(right) is dict and set(left) == set(right), 'H5_ZERO_ALL_TERMINAL_FIELDS')
    require(type(left['mounts']) is list and type(right['mounts']) is list
        and sorted(canonical(row) for row in left['mounts']) == sorted(canonical(row) for row in right['mounts'])
        and canonical({key:value for key,value in left.items() if key != 'mounts'})
        == canonical({key:value for key,value in right.items() if key != 'mounts'}), 'H5_ZERO_ALL_TERMINAL_FACTS_MOUNT_MULTISET')


def same_created(created, facts):
    require(all(created[key] == facts[key] for key in IMMUTABLE)
        and sorted(canonical(row) for row in created['mounts']) == sorted(canonical(row) for row in facts['mounts'])
        and set(created['networks']) == set(facts['networks']) and created['restart_count'] == facts['restart_count'] == 0,
        'H5_ZERO_ALL_NATIVE_IMMUTABLE_AND_MOUNT_MULTISET')


def h5_normal(facts, created, cid, role, context):
    same_created(created,facts)
    require(facts['id'] == cid and facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['exit_code']) is int and facts['exit_code'] == 0 and type(facts['restart_count']) is int and facts['restart_count'] == 0
        and facts['labels']['tianli.audit'] == '1' and facts['labels']['tianli.audit.run'] == context['run_id']
        and facts['labels']['com.docker.compose.project'] == context['project']
        and facts['labels']['com.docker.compose.service'] == role, 'H5_ZERO_SAME_NORMAL_NATIVE_TERMINAL')


def file_rows(root, rows):
    require(type(rows) is list and len({row['path'] for row in rows}) == len(rows), 'H5_ZERO_UNIQUE_FROZEN_FILES')
    for row in rows:
        require(type(row) is dict and set(row) == {'path','bytes','sha256'}, 'H5_ZERO_EXACT_FILE_DESCRIPTOR')
        relative = PurePosixPath(row['path'])
        require(not relative.is_absolute() and '..' not in relative.parts and str(relative) == row['path'], 'H5_ZERO_CANONICAL_FROZEN_FILE')
        raw = regular(Path(root) / row['path'])
        require(type(row['bytes']) is int and len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'H5_ZERO_FULL_FROZEN_FILE_BYTES')


def tree(root):
    root = Path(root); stat = root.lstat()
    require(root.is_dir() and not root.is_symlink() and not getattr(stat,'st_file_attributes',0) & 0x400, 'H5_ZERO_REAL_SOURCE_TREE')
    files = []; directories = []
    for path in root.rglob('*'):
        require(not path.is_symlink() and not getattr(path.lstat(),'st_file_attributes',0) & 0x400, 'H5_ZERO_TREE_NO_REPARSE')
        relative = path.relative_to(root).as_posix()
        if path.is_dir():
            directories.append(relative)
        else:
            raw = regular(path); files.append({'path':relative,'bytes':len(raw),'sha256':sha(raw)})
    return {'schema':1,'files':sorted(files,key=lambda row:row['path']),'directories':sorted(directories)}


def fixed_sources(context):
    freeze_raw = regular(Path(context['run_dir']) / 'preparation-source-binding.json'); frozen = json.loads(freeze_raw)
    require(sha(freeze_raw) == context['freeze_sha256'] and frozen['source_sha'] == context['source_sha']
        and frozen['requested_repo_source'] == context['repo_dir']
        and frozen['h5_preparation_scope'] == 'SOURCE_FROZEN_NOT_RUNTIME'
        and frozen['h5_runtime_source_status'] == 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED'
        and frozen['h5_serve_browser_acceptance'] == 'NOT RUN'
        and [row['path'] for row in frozen['artifact_files']] == BASE_ARTIFACTS
        and [row['path'] for row in frozen['h5_artifact_files']] == H5_ARTIFACTS
        and [row['path'] for row in frozen['h5_source_files']] == H5_SOURCES
        and frozen['customer_source_roots'] == ROOTS and frozen['customer_source_extra'] == sorted(EXTRA), 'H5_ZERO_EXACT_ORIGINAL_AND_TWENTY_H5_FREEZE')
    proof_ini_zero(context)
    require([row['path'] for row in frozen['driver_source_files']] == PROFILE_DRIVER
        and [row['path'] for row in frozen['preparation_source_references']] == PROFILE_REFERENCES,
        'H5_ZERO_EXACT_CURRENT_TEN_DRIVER_AND_FOUR_REFERENCES')
    repo = Path(context['repo_dir']); paths = set(EXTRA)
    for relative in ROOTS:
        subtree = tree(repo / relative)
        paths.update(relative + '/' + row['path'] for row in subtree['files'])
    require(sorted(paths) == [row['path'] for row in frozen['customer_source_files']], 'H5_ZERO_COMPLETE_ORIGINAL_SOURCE_CLOSURE')
    for prefix, extras in [('paid', sorted(EXTRA + [PAID_ENTRY])), ('fulfillment', fulfillment_extra()),
        ('aftersale_review', aftersale_review_extra()), ('worker', worker_extra())]:
        require(frozen[prefix + '_source_roots'] == ROOTS and frozen[prefix + '_source_extra'] == extras
            and [row['path'] for row in frozen[prefix + '_source_files']] == sorted(paths | set(extras)),
            'H5_ZERO_COMPLETE_CURRENT_FOUR_ROOT_SOURCE_GROUP')
    for group,root in [('driver_source_files',repo),('customer_source_files',repo),
                       ('paid_source_files',repo),('fulfillment_source_files',repo),
                       ('aftersale_review_source_files',repo),('worker_source_files',repo),('preparation_source_references',repo),
                       ('h5_source_files',repo),('artifact_files',PREP),('h5_artifact_files',PREP)]:
        file_rows(root,frozen[group])
    for key,name in [('public_source','public-source.json'),('dist','h5-dist.json'),('inputs','inputs.json')]:
        raw = regular(PREP / 'manifests' / name); bound = frozen['h5_materials'][key]
        require(bound == {'path':'/run/ms4-prep/manifests/' + name,'bytes':len(raw),'sha256':sha(raw)}, 'H5_ZERO_REAL_COMPLETE_MATERIAL_MANIFEST')
    require(load(PREP / 'manifests/public-source.json') == tree(repo / 'crmeb/public')
        and load(PREP / 'manifests/h5-dist.json') == tree(frozen['h5_dist_source']), 'H5_ZERO_COMPLETE_PUBLIC_AND_DIST_SOURCE_BYTES')
    inputs = load(PREP / 'manifests/inputs.json')
    require(set(inputs) == {'schema','language','theme'} and inputs['schema'] == 1, 'H5_ZERO_EXACT_LANGUAGE_THEME_MANIFEST')
    for name in ['language','theme']:
        raw = regular(PREP / 'inputs' / (name + '.json'))
        require(inputs[name] == {'path':'/run/ms4-prep/inputs/' + name + '.json','bytes':len(raw),'sha256':sha(raw)}, 'H5_ZERO_REAL_INPUT_BYTES')
    provenance = frozen['h5_build_provenance']; file_rows(provenance['build_directory'],provenance['files'])
    for args,wanted in [(['rev-parse','HEAD'],context['source_sha']),(['status','--porcelain=v1','--untracked-files=all'],'')]:
        process = subprocess.run(['git','-c','safe.directory=' + repo.as_posix(),'-C',str(repo),*args],capture_output=True)
        require(process.returncode == 0 and process.stderr == b'' and process.stdout.decode().rstrip('\r\n') == wanted,
                'H5_ZERO_EXACT_CLEAN_SOURCE_HEAD')
    return frozen


def original_expected(context,evidence):
    expected = {cid:role for role,cid in context['initial_service_ids'].items()}; created = {}
    require(set(expected.values()) == {'audit-mysql','audit-redis'} and len(expected) == 2, 'H5_ZERO_ORIGINAL_TWO_DB_CIDS')
    for role,cid in context['initial_service_ids'].items():
        created[cid] = context['initial_service_created'][role]
    for role,proof in PROOFS.items():
        row = load(evidence / (proof + '-created.json'))
        require(row['proof_name'] == proof and row['run_id'] == context['run_id'] and row['source_sha'] == context['source_sha']
            and row['status'] == 'created' and row['pid'] == 0 and row['id'] not in expected, 'H5_ZERO_ORIGINAL_H5_CREATED_CID')
        expected[row['id']] = role; created[row['id']] = row
    paid = bound_created(context, PAID['proof'], PAID_ROLE)
    require(paid['id'] not in expected, 'H5_ZERO_DISTINCT_ORIGINAL_CANONICAL_PAID')
    expected[paid['id']] = PAID_ROLE; created[paid['id']] = paid
    require(len(expected) == len(FINAL_ROLES) and set(expected.values()) == set(FINAL_ROLES),
        'H5_ZERO_EXACT_ORIGINAL_FIVE_ROLE_MEMBER_UNION')
    return expected,created


def network_identity(value):
    require(set(value) == {'Id','Name','Driver','Scope','Internal','EnableIPv6','Options','Labels','Containers'}, 'H5_ZERO_NATIVE_NETWORK_FIELDS')
    return {key:item for key,item in value.items() if key != 'Containers'}


def main(context_file,close_result_sha256):
    require(re.fullmatch('[a-f0-9]{64}',close_result_sha256), 'H5_ZERO_ROOT_OBSERVED_CLOSE_HASH_REQUIRED')
    context_raw = regular(context_file); context = json.loads(context_raw.decode('utf-8-sig'))
    root = Path(context['run_dir']); evidence = root / 'evidence'
    require(Path(context_file).resolve() == (root / 'actual-run-context.json').resolve() and context['prep_dir'] == PREP.as_posix()
        and re.fullmatch('[0-9]{14}[a-f0-9]{6}',context['run_id']) and context['project'] == 'tianli-audit-' + context['run_id'], 'H5_ZERO_SINGLE_ORIGINAL_CONTEXT')
    frozen = fixed_sources(context); hashes = {row['path']:row['sha256'] for row in frozen['h5_artifact_files']}
    require(Path(__file__).resolve() == PREP / 'verify-zero-customer-h5.py', 'H5_ZERO_ACTUAL_FROZEN_ENTRY')
    raw = regular(evidence / 'normal-close-result.json'); result = json.loads(raw)
    require(sha(raw) == close_result_sha256 and result['schema'] == 1 and result['scope'] == 'CUSTOMER_H5_NORMAL_CLOSE_ONLY'
        and result['status'] == 'VERIFIED_NORMAL_CLOSE_ZERO_RESIDUAL' and result['native_exit'] == 0
        and all(result[key] == context[key] for key in ['run_id','source_sha','freeze_sha256'])
        and result['context_sha256'] == sha(context_raw)
        and all(result[key] == hashes[path] for key,path in [('close_source_sha256','normal-close-customer-h5.py'),
            ('host_source_sha256','ms4-h5-host.py'),('zero_source_sha256','verify-zero-customer-h5.py')])
        and result['exact_nonforce_removed'] == {'containers':len(FINAL_ROLES),'networks':2,'volumes':len(VOLUMES)}
        and result['ordinary_stop_and_exact_nonforce_remove'] is True and result['scope_limit'] == SCOPE_LIMIT
        and result['customer_HTTP_DOM'] == 'NORMAL_SOURCE_BOUND_OBSERVED_ONLY'
        and result['final_inventory'] == {kind:{'project':[],'run':[]} for kind in ['container','network','volume']}
        and all(result[key] == 'NOT RUN' for key in ['stopped_samples','external_payment_shipping_aftersale','statistics_acceptance','again_paid_UI'])
        and result['full_marketplace_acceptance'] == 'INCOMPLETE', 'H5_ZERO_COMPLETE_ACTUAL_NORMAL_CLOSE_RESULT')
    evidence_names = ['normal-close-attempt.json','normal-close-before.json','normal-close-all-stopped.json',
        'normal-close-before-network-volumes-rm.json','normal-close-before-eleven-volumes-rm.json',
        'customer-h5-close-process-observation.json','customer-h5-parent-signal-stop.json']
    require(set(result['close_evidence']) == set(evidence_names), 'H5_ZERO_CLOSE_RESULT_BINDS_ALL_PUBLIC_CLOSE_SNAPSHOTS')
    for name in evidence_names:
        value = regular(evidence / name)
        require(result['close_evidence'][name] == {'path':'evidence/' + name,'bytes':len(value),'sha256':sha(value)},
                'H5_ZERO_ORIGINAL_PUBLIC_CLOSE_SNAPSHOT_HASH_CHAIN')
    expected,created = original_expected(context,evidence); attempt = load(evidence / 'normal-close-attempt.json')
    require(attempt['schema'] == 1 and attempt['scope'] == 'CUSTOMER_H5_NORMAL_CLOSE_ONLY' and attempt['original_expected'] == expected
        and attempt['stop_order'] == ROLES and attempt['never_started_remove_only'] == [PAID_ROLE]
        and attempt['operations_started_at_receipt_time'] is False
        and all(attempt[key] == result[key] for key in ['run_id','source_sha','freeze_sha256','context_sha256',
            'close_source_sha256','host_source_sha256','zero_source_sha256']), 'H5_ZERO_ORIGINAL_FOUR_CLOSE_ATTEMPT')
    before = load(evidence / 'normal-close-before.json'); stopped = load(evidence / 'normal-close-all-stopped.json')
    running = {cid:role for cid,role in expected.items() if role in ROLES}
    paid_id = next(cid for cid,role in expected.items() if role == PAID_ROLE)
    require(set(before['containers']) == set(stopped['resources']['containers']) == set(expected)
        and set(stopped['containers']) == set(running) and set(stopped['never_started']) == {paid_id},
        'H5_ZERO_EXACT_RUNNING_TERMINALS_AND_SEPARATE_UNSTARTED_CANONICAL')
    for facts in [before['containers'][paid_id], stopped['never_started'][paid_id], stopped['resources']['containers'][paid_id]]:
        canonical_paid(context, facts, created[paid_id])
    current_prerequisites(context)
    phase_canonical_bindings(context, created[paid_id])
    for cid,role in running.items():
        same_created(created[cid],before['containers'][cid]); require(before['containers'][cid]['status'] == 'running' and before['containers'][cid]['pid'] > 0, 'H5_ZERO_PRE_CLOSE_ORIGINAL_RUNNING_ROLE')
        facts = stopped['containers'][cid]; h5_normal(facts,created[cid],cid,role,context)
        same_facts(facts,stopped['resources']['containers'][cid])
        same_facts(facts,load(evidence / (PROOFS.get(role,'normal-close-' + role) + '-terminal.json')))
        after = load(evidence / ('normal-close-' + role + '-after-stop.json')); same_facts(facts,after)
        stop_created = load(evidence / ('normal-close-' + role + '-stop-created.json'))
        stop_native = load(evidence / ('normal-close-' + role + '-stop-terminal.json'))
        forms = [(cid + '\n').encode(),('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()]
        require(type(stop_created['pid']) is int and stop_created['pid'] > 0 and stop_native['pid'] == stop_created['pid']
            and stop_created['container_id'] == stop_native['container_id'] == cid and stop_created['role'] == role
            and stop_created['time'] == -1 and stop_created['no_timed_force'] is True
            and stop_native['native_exit'] == 0 and stop_native['stderr_bytes'] == 0 and stop_native['stderr_sha256'] == sha(b'')
            and any(stop_native['stdout_bytes'] == len(value) and stop_native['stdout_sha256'] == sha(value) for value in forms), 'H5_ZERO_SAME_SINGLE_NATIVE_STOP_HANDLE_ZERO')
    volumes = before['volumes']; names = sorted(context['project'] + '_' + suffix for suffix in VOLUMES)
    additional = load(evidence / 'customer-h5-additional-volume-identities.json')
    require(len(volumes) == len(VOLUMES) and [row['name'] for row in volumes] == names
        and [row for row in volumes if row['name'] in {item['name'] for item in context['initial_volumes']}] == context['initial_volumes']
        and [row for row in volumes if row['name'] not in {item['name'] for item in context['initial_volumes']}] == additional['additional_volumes']
        and additional['original_context_sha256'] == sha(context_raw) and additional['all_volume_names'] == names
        and all(additional[key] == context[key] for key in ['run_id','source_sha','freeze_sha256']), 'H5_ZERO_ORIGINAL_SEVEN_PLUS_FOUR_VOLUME_IDENTITIES')
    require(all(set(row) == {'name','created_at','labels'} and type(row['created_at']) is str and row['created_at']
        and row['labels']['tianli.audit'] == '1' and row['labels']['tianli.audit.run'] == context['run_id']
        and row['labels']['com.docker.compose.project'] == context['project'] for row in volumes), 'H5_ZERO_ELEVEN_COMPLETE_NATIVE_VOLUME_LABELS')
    for snapshot in [before,stopped['resources'],load(evidence / 'normal-close-before-network-volumes-rm.json')]:
        require(snapshot['volumes'] == volumes, 'H5_ZERO_SAME_ELEVEN_VOLUME_IDENTITIES_THROUGH_REMOVE')
        for kind in ['backend','frontend']:
            receipt = load(evidence / ('http-' + kind + '-network-created.json'))
            require(network_identity(snapshot['networks'][kind]) == network_identity(receipt['network'])
                and receipt['schema'] == 1 and receipt['scope'] == 'MS4_HTTP_' + kind.upper() + '_NATIVE_IDENTITY_ONLY'
                and all(receipt[key] == context[key] for key in ['run_id','source_sha','freeze_sha256']), 'H5_ZERO_SAME_TWO_ORIGINAL_NETWORK_IDENTITIES')
            network = receipt['network']
            require(network['Driver'] == 'bridge' and network['Scope'] == 'local' and network['EnableIPv6'] is False,
                    'H5_ZERO_ORIGINAL_NATIVE_BRIDGE_BOUNDARY')
            if kind == 'backend':
                require({key:network[key] for key in ['Id','Name','Internal','Labels']} == context['initial_network']
                    and network['Id'] == context['network_id'] and network['Internal'] is True, 'H5_ZERO_ORIGINAL_INTERNAL_BACKEND')
            else:
                require(network['Name'] == context['project'] + '_frontend' and network['Internal'] is False
                    and network['Options'] == {'com.docker.network.enable_ipv4':'true','com.docker.network.bridge.enable_ip_masquerade':'false'}
                    and network['Labels'] == {'tianli.audit':'1','tianli.audit.run':context['run_id'],
                        'com.docker.compose.project':context['project'],'com.docker.compose.network':'frontend'}, 'H5_ZERO_ORIGINAL_NO_MASQUERADE_FRONTEND')
    empty = load(evidence / 'normal-close-before-network-volumes-rm.json')
    require(empty['containers'] == {} and all(row['Containers'] == {} for row in empty['networks'].values())
        and empty['inventory']['container'] == {'project':[],'run':[]}, 'H5_ZERO_TWO_EMPTY_NETWORKS_AFTER_EXACT_CID_REMOVE')
    final_volumes = load(evidence / 'normal-close-before-eleven-volumes-rm.json')
    require(final_volumes['volumes'] == volumes and final_volumes['inventory'] == {'container':{'project':[],'run':[]},
        'network':{'project':[],'run':[]},'volume':{'project':names,'run':names}}, 'H5_ZERO_EXACT_ELEVEN_LAST_VOLUMES')
    for name,row in before['verified_receipts'].items():
        require(type(row) is dict and set(row) == {'path','bytes','sha256'}
            and row['path'] == ('manifest.json' if name == 'manifest.json' else 'evidence/' + name)
            and '/' not in name and '\\' not in name and name not in ['.','..'], 'H5_ZERO_FINITE_PUBLIC_RECEIPT_PATH')
        path = root / row['path']; value = regular(path)
        require(path.is_relative_to(root) and len(value) == row['bytes'] and sha(value) == row['sha256'], 'H5_ZERO_ALL_ORIGINAL_PUBLIC_PREREQUISITE_BYTES')
    # The normal close bound all of these original native terminal receipts before
    # deleting anything. Recheck them independently; no live helper is recreated.
    prerequisite_roles = {'prepare':'audit-bootstrap',
        'files-bootstrap':'audit-bootstrap','customer-prepare':'audit-ms4-customer-prepare',
        'register-initializer':'audit-bootstrap','register-driver':'audit-bootstrap','register-customer-prepare':'audit-bootstrap',
        'customer-h5-public-copy':'audit-ms4-customer-h5-public-copy','register-customer-h5-prepare':'audit-ms4-customer-h5-register',
        'customer-h5-prepare':'audit-ms4-customer-h5-prepare','register-customer-h5-files':'audit-ms4-customer-h5-register',
        'customer-h5-files':'audit-ms4-customer-h5-files','register-customer-h5-serve':'audit-ms4-customer-h5-register'}
    for proof,role in prerequisite_roles.items():
        old = load(evidence / (proof + '-created.json')); terminal = load(evidence / (proof + '-terminal.json'))
        require(old['id'] not in expected and old['run_id'] == context['run_id'] and old['source_sha'] == context['source_sha']
            and old['proof_name'] == proof, 'H5_ZERO_ORIGINAL_PREPARATION_CREATED_IDENTITIES')
        h5_normal(terminal,old,old['id'],role,context)
    binding = load(evidence / 'ms4-customer-h5-serve-source-binding.json')
    require(len(binding) == 27 and binding['scope'] == 'MS4_CUSTOMER_H5_HTTP_MS5_SOURCE'
        and binding['container_id'] == next(cid for cid,role in expected.items() if role == 'audit-ms4-customer-h5')
        and all(binding[key] == context[key] for key in ['run_id','source_sha','freeze_sha256'])
        and binding['artifact_files'] == frozen['artifact_files'] and binding['h5_artifact_files'] == frozen['h5_artifact_files']
        and binding['source_files'] == frozen['customer_source_files'] and binding['h5_source_files'] == frozen['h5_source_files']
        and binding['h5_materials'] == frozen['h5_materials'], 'H5_ZERO_ORIGINAL_SERVE_FROZEN_BINDING')
    for key,relative in [('manifest','manifest.json'),('public_copy','evidence/customer-h5-public-copy.json'),
        ('prepare_result','evidence/customer-h5-prepare-result.json'),('files_result','evidence/customer-h5-files-result.json'),
        ('registration_result','evidence/register-customer-h5-serve-result.json')]:
        value = regular(root / relative)
        require(binding[key]['bytes'] == len(value) and binding[key]['sha256'] == sha(value), 'H5_ZERO_BOUND_ORIGINAL_PUBLIC_RESULT_BYTES')
    for key,filename in [('containers','containers.jsonl'),('volumes','volumes.txt'),('volume_identities','volumes.jsonl')]:
        value = regular(root / filename)
        require(binding['registration_inputs'][key] == {'path':'/run/audit/' + filename,'bytes':len(value),'sha256':sha(value)},
                'H5_ZERO_ORIGINAL_FOUR_TARGET_PRECOLLECTED_INPUT_BYTES')
    manifest = load(root / 'manifest.json'); helper = load(evidence / 'register-customer-h5-serve-result.json')
    require({role:row['id'] for role,row in manifest['services'].items()} == {role:cid for cid,role in expected.items()}
        and manifest['volumes'] == names and manifest['volume_identities'] == {row['name']:row['created_at'] for row in volumes}
        and helper['status'] == 'REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY' and helper['native_exit'] == 0
        and helper['business'] == {'registered_roles':sorted(FINAL_ROLES),'input_inventory_excludes_transient_helper':True,
            'manifest_sha256':sha(regular(root / 'manifest.json'))}, 'H5_ZERO_REAL_FOUR_TARGET_FINAL_REGISTRATION')
    audit = load(evidence / 'customer-h5-browser-audit.json'); browser = before['verified_browser']
    require(result['browser_audit_sha256'] == sha(regular(evidence / 'customer-h5-browser-audit.json'))
        and audit['status'] == 'VERIFIED_NORMAL_CUSTOMER_H5_HTTP_DOM_ONLY' and audit['scope_limit'] == SCOPE_LIMIT
        and audit['business_table_count'] == 27 and audit['auxiliary_table_count'] == 7
        and audit['public_projection_only'] is True and browser['all_original_private_frames_bound'] is True
        and browser['safe_projections_recomputed'] is True and browser['scope_limit'] == SCOPE_LIMIT
        and all(audit[key] == browser[key] for key in ['ui_capture_count','screenshot_count','manual_review_count'])
        and audit['ui_capture_count'] == audit['screenshot_count'] == audit['manual_review_count'] >= 27, 'H5_ZERO_NORMAL_BROWSER_SCOPE_AND_REAL_COUNTS')
    coverage = set()
    for relative,row in browser['screenshots'].items():
        require(re.fullmatch('evidence/browser-screenshots/[0-9]{4}-(?:login-empty|store-a|store-b|product-a|product-b|product-c|cart-three-lines|confirm-filled|order-detail-unpaid)\\.jpg',relative), 'H5_ZERO_FINITE_SAVED_JPEG')
        value = regular(root / relative); require(len(value) == row['bytes'] and sha(value) == row['sha256'], 'H5_ZERO_ORIGINAL_SAVED_JPEG_FULL_BYTES')
        coverage.add((row['scene'],row['viewport']['width']))
    scenes = ['login-empty','store-a','store-b','product-a','product-b','product-c','cart-three-lines','confirm-filled','order-detail-unpaid']
    require(coverage == {(scene,width) for scene in scenes for width in [320,390,1440]}, 'H5_ZERO_ALL_SCENES_AT_THREE_WIDTHS')
    screenshot_parent = evidence / 'browser-screenshots'
    require(screenshot_parent.is_dir() and not screenshot_parent.is_symlink()
        and {path.relative_to(root).as_posix() for path in screenshot_parent.iterdir()} == set(browser['screenshots'])
        and len(browser['screenshots']) == browser['screenshot_count'], 'H5_ZERO_SAVED_JPEG_DIRECTORY_CLOSED_SET')
    signal = load(evidence / 'customer-h5-parent-signal-stop.json')
    require(signal['scope'] == 'MS4_CUSTOMER_H5_PARENT_SIGNAL_STOP_PROJECTION' and signal['normal_only'] is True
        and signal['full_source_unchanged'] is True and signal['public_projection_only'] is True
        and signal['child']['stopped'] is True and signal['child']['stop_signal'] == 15 and signal['child']['term_attempted'] is True
        and signal['child']['remaining_members'] == [] and signal['child']['listener_open'] is False
        and signal['child']['native_child']['running'] is False and signal['child']['native_child']['termsig'] != 9
        and type(signal['child']['native_proc_close']) is int, 'H5_ZERO_ACTUAL_PARENT_NORMAL_SIGNAL_PROJECTION')
    ready = load(evidence / 'http-source-ready.json'); stop = load(evidence / 'http-supervisor-stop.json')
    require(all(signal[key] == ready[key] for key in ['run_id','source_sha','container_id','freeze_sha256','epoch_prefix',
        'step_nonce_prefix','not_before','deadline']) and all(signal['child'][key] == stop[key] for key in
        ['group','remaining_members','listener_open','stop_signal','term_attempted','native_child','native_proc_close','normal_only']),
        'H5_ZERO_SAME_ORIGINAL_PARENT_RUNTIME_AND_CHILD_STOP_PUBLIC_FACTS')
    observation = load(evidence / 'customer-h5-close-process-observation.json')
    require(observation['scope'] == 'MS4_CUSTOMER_H5_CLOSE_NUMERIC_PROCESS_OBSERVATION_ONLY'
        and observation['native_exit'] == 0 and observation['stderr_bytes'] == 0 and observation['stderr_sha256'] == sha(b'')
        and observation['container_id'] == binding['container_id'] and observation['facts']['members'] == []
        and observation['facts']['listener_open'] is False and all(observation['facts']['parent'][key] == ready['supervisor'][key]
            for key in ['pid','pgid','sid','starttime']), 'H5_ZERO_ORIGINAL_LIVE_PARENT_NUMERIC_READBACK')
    queries = []
    for kind,args in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for label,value in [('project','com.docker.compose.project=' + context['project']),('run','tianli.audit.run=' + context['run_id'])]:
            process = subprocess.run(['docker',*args,'--filter','label=' + value],capture_output=True)
            queries.append({'kind':kind,'label':label,'native_exit':process.returncode,'stdout_bytes':len(process.stdout),
                'stdout_sha256':sha(process.stdout),'stderr_bytes':len(process.stderr),'stderr_sha256':sha(process.stderr)})
            require(process.returncode == 0 and process.stdout == process.stderr == b'', 'H5_ZERO_INDEPENDENT_NATIVE_QUERY_ZERO_EMPTY')
    require(len(queries) == 6, 'H5_ZERO_EXACT_SIX_INDEPENDENT_QUERIES')
    global_queries = global_no_original_consumers(context, expected)
    fixed_sources(context)
    for name, row in before['verified_receipts'].items():
        value = regular(root / row['path'])
        require(len(value) == row['bytes'] and sha(value) == row['sha256'], 'H5_ZERO_ALL_PUBLIC_ORIGINALS_FINAL_COMPLETE_REREAD')
    output = {'schema':1,'scope':'CUSTOMER_H5_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY','status':'VERIFIED_INDEPENDENT_SIX_QUERY_ZERO',
        **{key:context[key] for key in ['run_id','source_sha','freeze_sha256']},'context_sha256':sha(context_raw),
        'close_result_sha256':close_result_sha256,'close_source_sha256':hashes['normal-close-customer-h5.py'],
        'host_source_sha256':hashes['ms4-h5-host.py'],'zero_source_sha256':hashes['verify-zero-customer-h5.py'],
        'queries':queries,'global_queries':global_queries,'native_exit':0,'customer_HTTP_DOM':'NORMAL_SOURCE_BOUND_OBSERVED_ONLY','scope_limit':SCOPE_LIMIT,
        'stopped_samples':'NOT RUN','external_payment_shipping_aftersale':'NOT RUN','statistics_acceptance':'NOT RUN',
        'again_paid_UI':'NOT RUN','full_marketplace_acceptance':'INCOMPLETE'}
    new(evidence / 'customer-h5-independent-zero.json',output)
    print(json.dumps(output,ensure_ascii=False))




# Independent current normal9 native-history and INI consumers; no Host/close import.
ARTIFACTS = PREP
PROFILE_ARTIFACTS = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php', 'seed-source-inverse.json',
                     'interface-v1.json', 'source-derivation.json', 'normal-close-ms4-fulfillment.py', 'verify-zero-ms4-fulfillment.py']
PROFILE_DRIVER = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
                  'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms5-payment-migration.php',
                  'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
                  'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php',
                  'help/migrations/marketplace-ms5-payment.md', 'help/migrations/marketplace-ms5-payment.php']
PROFILE_REFERENCES = ['help/audit-regressions/isolated/bootstrap.sh', 'help/audit-regressions/isolated/compose.yml',
                      'help/audit-regressions/isolated/compose.ms2-migration.yml', 'help/audit-regressions/isolated/ms2-migration.php']
SOURCE_ROOTS = ['crmeb/app', 'crmeb/crmeb', 'crmeb/vendor', 'crmeb/config']
SOURCE_EXTRA = ['crmeb/composer.json', 'crmeb/composer.lock', 'crmeb/public/index.php', 'crmeb/public/router.php', 'crmeb/think',
                'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php',
                'help/audit-regressions/isolated/ms4-customer-http-normal.php',
                'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php']
# Independent offline consumers: original artifacts remain exact bytes.
CUSTOMER_EXTRA = SOURCE_EXTRA
CUSTOMER_ROOTS = SOURCE_ROOTS
DRIVER = PROFILE_DRIVER
expect = require
digest = sha

PHP = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
MYSQL = 'sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4'
REDIS = 'sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e'
CUSTOMER_ENTRY = 'help/audit-regressions/isolated/ms4-customer-http-normal.php'
PAID_ENTRY = 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php'
PAID_ENTRY_BYTES = 103909
PAID_ENTRY_SHA256 = '1fce0111252e3719109cc9bc29759d9433fc7b5fc193a010976a0a3cfd24e223'
PAID = {'proof': 'paid-producer', 'role': 'audit-ms5-paid-producer',
    'binding': 'paid-producer-source-binding.json', 'scope': 'MS5_PAID_PRODUCER_SOURCE',
    'result': 'paid-producer-result.json', 'result_scope': 'MS5_PAID_PRODUCER_INTERNAL_ONLY',
    'status': 'VERIFIED_MS5_PAID_PRODUCER_INTERNAL_ONLY', 'environment_scope': 'MS5_PAID_PRODUCER_REAL_ENVIRONMENT'}
IMMUTABLE_FIELDS = ('id', 'image', 'hostname', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
                    'network_mode', 'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop',
                    'security_options', 'created_at', 'user', 'stop_signal')
MODES = {'prepare': {'proof': 'customer-prepare', 'role': 'audit-ms4-customer-prepare',
    'binding': 'ms4-customer-prepare-source-binding.json', 'scope': 'MS4_CUSTOMER_PREPARE_MS5_SOURCE',
    'result': 'ms4-customer-prepare.json', 'result_scope': 'CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
    'status': 'VERIFIED_CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARED_ONLY'},
    'verify': {'proof': 'customer', 'role': 'audit-ms4-customer-http',
    'binding': 'ms4-customer-http-source-binding.json', 'scope': 'MS4_CUSTOMER_HTTP_MS5_SOURCE',
    'result': 'ms4-customer-http.json', 'result_scope': 'NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY',
    'status': 'VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY'}}
ENV_FIXED = {'APP_ENV': 'testing', 'PHP_MARKETPLACE_ENABLED': 'true',
    'PHP_MARKETPLACE_TRADE_ENABLED': 'true', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED': 'true', 'PHP_CACHE_DRIVER': 'redis'}
ENV_NAMES = ['APP_ENV', 'PHP_MARKETPLACE_ENABLED', 'PHP_MARKETPLACE_TRADE_ENABLED', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED',
    'PHP_MARKETPLACE_SELF_OPERATED_MER_ID', 'PHP_CACHE_DRIVER']
PREREQUISITES = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json',
    'initializer-result.json', 'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
    'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']
FILES_PREREQUISITES = ['files-bootstrap-created.json', 'files-bootstrap-terminal.json',
    'files-bootstrap-result.json', 'files-bootstrap-shutdown.json']
PREPARE_PREREQUISITES = ['customer-prepare-created.json', 'customer-prepare-terminal.json', 'ms4-customer-prepare.json']
FULFILLMENT_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-normal.php'
FULFILLMENT_ENTRY_BYTES = 120307
FULFILLMENT_ENTRY_SHA256 = '8a126eed436141f722cb4a8351a5a6d592f143449beaa9aeeb1276bd36f08f32'
THREE_READ_RESULT = 'ms4-three-read-normal.json'
THREE_READ_SOURCE_FILES = [
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php', 'bytes': 53328, 'sha256': '9da29b5edf6bc50c8bb483eaf4a67adaaacc6cddc4660e6cac0d2dabcb81a584'},
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php', 'bytes': 51479, 'sha256': '7ad23450261f2771a46e8c04e7c2f8da0941d476d887f30064150188886ea941'},
    {'path': 'help/audit-regressions/isolated/lib/ms4-three-read-normal.php', 'bytes': 160780, 'sha256': '3d02bd062dcb75c27ace071723a0f3d482a12b362e0b618c008fb81bd23fac9d'}
]
FULFILLMENT_MIGRATION_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-migration.php'
FULFILLMENT_CLI = 'help/migrations/marketplace-ms4-fulfillment.php'
FULFILLMENT = {'proof': 'fulfillment-normal', 'role': 'audit-ms4-fulfillment-normal',
    'binding': 'ms4-fulfillment-normal-source-binding.json', 'scope': 'MS4_FULFILLMENT_NORMAL_SOURCE',
    'result': 'ms4-fulfillment-normal.json', 'result_scope': 'NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY',
    'status': 'VERIFIED_NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY', 'environment_scope': 'MS4_FULFILLMENT_NORMAL_REAL_ENVIRONMENT'}
FULFILLMENT_MIGRATION = {'proof': 'fulfillment-migration', 'role': 'audit-ms4-fulfillment-migration'}
FULFILLMENT_MIGRATION_FOUR = ['ms4-fulfillment-migration-container-created.json',
    'ms4-fulfillment-migration-source-binding.json', 'ms4-aftersale-early-migration-normal.json', 'ms4-fulfillment-migration-terminal.json']


AFTERSALE_REVIEW_ENTRY = 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php'
AFTERSALE_REVIEW_ENTRY_BYTES = 201659
AFTERSALE_REVIEW_ENTRY_SHA256 = '61697db47382a3e7253e44f17f18a746c4a663c6c5d577d61ee74888805e6a8a'
AFTERSALE_REVIEW = {'proof': 'aftersale-review-normal', 'role': 'audit-ms4-aftersale-review-normal',
    'binding': 'ms4-aftersale-review-normal-source-binding.json', 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_SOURCE',
    'result': 'ms4-aftersale-review-normal.json', 'result_scope': 'NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY',
    'status': 'VERIFIED_NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY', 'environment_scope': 'MS4_AFTERSALE_REVIEW_NORMAL_REAL_ENVIRONMENT'}
AFTERSALE_PRIVATE_ROOT = '/var/lib/crmeb-aftersale-evidence'
EARLY_SCRIPTS = {'aftersale': 'help/migrations/marketplace-ms4-aftersale.php',
    'aftersale_menus': 'help/migrations/marketplace-ms4-aftersale-menus.php',
    'review_menus': 'help/migrations/marketplace-ms4-review-menus.php'}
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
WORKER_ENTRY_BYTES = 123288
WORKER_ENTRY_SHA256 = '024d0b6817015a69386daeecc58950363726eaf44e6109c718b1151fe2d52885'
WORKER = {'proof': 'worker-normal', 'role': 'audit-ms4-worker-normal',
    'binding': 'ms4-worker-normal-source-binding.json', 'scope': 'MS4_WORKER_NORMAL_SOURCE',
    'result': 'ms4-worker-normal.json', 'result_scope': 'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
    'status': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'environment_scope': 'MS4_WORKER_NORMAL_REAL_ENVIRONMENT'}
# Independent data/raw validation only: no Host/close imports or additional runtime process.
ZI_LEAF = '99-ms4-proof-memory.ini'
ZI_PIN = {'bytes': 18, 'sha256': '986c7e4b2587348a8d5ba59312a2106d38bbefec35acda4e7e5c14761cfe9ff1'}
ZI_ROLES = ['audit-ms5-paid-producer', 'audit-ms4-fulfillment-normal', 'audit-ms4-aftersale-review-normal',
    'audit-ms4-worker-normal', 'audit-ms4-ui-prepare', 'audit-ms4-ui-backend']
ZI_SCAN = '/usr/local/etc/php/conf.d:/run/audit/proof-php-ini'
ZI_REFERENCE = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/r20-proof-memory-budget-public-readback-r1/RESULT.json',
    'bytes': 60911, 'sha256': 'e4ff0cd932806efb206b46dfdc5f5ef0230e0f2ad84679dbcb843adbdbec67f5'}
ZI_COPY = 'evidence/proof-php-ini-copy.json'
ZI_FOLDER = 'evidence/proof-php-ini-env'

def normal(facts, cid, context, role):
    require(facts['id'] == cid and facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['exit_code']) is int and facts['exit_code'] == 0
        and type(facts['restart_count']) is int and facts['restart_count'] == 0
        and facts['labels']['tianli.audit.run'] == context['run_id']
        and facts['labels']['com.docker.compose.project'] == context['project']
        and facts['labels']['com.docker.compose.service'] == role, 'SAME_NORMAL_NATIVE_TERMINAL_IDENTITY')


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
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == 7 and {row['Destination'] for row in mounts} == set(expected)
               and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'PAID_EXACT_NATIVE_SEVEN_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                   and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'PAID_EXACT_MOUNT_SOURCE_ACCESS')
        aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
        expect(aliases.count('audit-app') == 1, 'PAID_ONLY_REAL_SELF_HTTP_ALIAS')

    if service in [FULFILLMENT['role'], AFTERSALE_REVIEW['role'], WORKER['role']]:
        spec = FULFILLMENT if service == FULFILLMENT['role'] else AFTERSALE_REVIEW if service == AFTERSALE_REVIEW['role'] else WORKER
        native_keys = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged',
            'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid', 'exit_code', 'networks', 'ports',
            'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at'}
        expect(set(facts) in [native_keys, native_keys | {'run_id', 'source_sha', 'proof_name'}]
               and type(facts['exit_code']) is int, 'PAID_COMPLETE_NATIVE_TWENTY_SIX_OR_CREATED_TWENTY_NINE_KEYS')
        if 'proof_name' in facts:
            expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
                   and facts['proof_name'] == spec['proof'], 'PAID_REAL_CREATED_THREE_IDENTITY_KEYS')
        expect(facts['command'] == [FULFILLMENT_ENTRY if service == FULFILLMENT['role'] else AFTERSALE_REVIEW_ENTRY if service == AFTERSALE_REVIEW['role'] else WORKER_ENTRY, 'normal'] and facts['user'] == '0:0'
               and facts['stop_signal'] == 'SIGTERM' and type(facts['pid']) is int
               and type(facts['restart_count']) is int and facts['restart_count'] == 0, 'EXACT_PAID_ENTRY_USER_SIGNAL')
        expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}
               and all(type(value) is str and 'rw' in value.split(',') for value in facts['tmpfs'].values()), 'PAID_EXACT_RW_TMPFS')
        expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
            '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
            '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
            '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
            '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', True)}
        if service in [AFTERSALE_REVIEW['role'], WORKER['role']]:
            expected[AFTERSALE_PRIVATE_ROOT] = ('volume', context['project'] + '_aftersale-evidence', service == AFTERSALE_REVIEW['role'])
        mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
        expect(len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected)
               and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'PAID_EXACT_NATIVE_SEVEN_MOUNTS')
        for row in mounts:
            kind, source, rw = expected[row['Destination']]
            expect(row['Type'] == kind and row['RW'] is rw
                   and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'PAID_EXACT_MOUNT_SOURCE_ACCESS')
        aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
        expect(aliases.count('audit-app') == (0 if service in [FULFILLMENT['role'], WORKER['role']] else 1), 'PAID_ONLY_REAL_SELF_HTTP_ALIAS')

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


def bound_created(context, proof, role):
    facts = load(Path(context['run_dir']) / 'evidence' / (proof + '-created.json'))
    expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
           and facts['proof_name'] == proof and re.fullmatch('[a-f0-9]{64}', facts['id'])
           and facts['status'] == 'created' and facts['pid'] == 0, 'BOUND_ACTUAL_CREATED_RECEIPT')
    owned(context, facts, role)
    return facts


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


def public_environment(context):
    return ENV_FIXED | {'PHP_MARKETPLACE_SELF_OPERATED_MER_ID': str(actual_self(context))}


def environment_projection_matches(context, receipt):
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


def normal_terminal(context, proof, role, native_required=False):
    require(native_required is False, 'INDEPENDENT_ZERO_OFFLINE_PREDECESSOR_ONLY')
    before = bound_created(context, proof, role)
    facts = load(Path(context['run_dir']) / 'evidence' / (proof + '-terminal.json'))
    require(set(before) == set(facts) | {'run_id', 'source_sha', 'proof_name'}, 'FULL_CREATED_AND_NATIVE_TERMINAL_KEYS')
    owned(context, facts, role); same_created(before, facts)
    normal(facts, before['id'], context, role)
    return facts


def verify_mode_binding(context, mode, cid, native_required=True):
    spec = MODES[mode]; evidence = Path(context['run_dir']) / 'evidence'
    actual_binding = load(evidence / spec['binding']); wanted = mode_binding(context, mode, cid)
    expect(actual_binding == wanted and type(actual_binding['schema']) is int, 'COMPLETE_EXACT_MODE_SOURCE_BINDING')
    actual = load(evidence / (spec['proof'] + '-environment.json'))
    expect(set(actual) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'environment', 'native_projection_sha256'}
           and type(actual['schema']) is int and actual['schema'] == 1 and actual['scope'] == 'MS4_CUSTOMER_REAL_ENVIRONMENT'
           and all(actual[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
           and actual['container_id'] == cid and actual['environment'] == public_environment(context), 'EXACT_MODE_ENVIRONMENT_RECEIPT')
    receipt = load(evidence / (spec['proof'] + '-environment-native.json'))
    expect(receipt['stage'] == 'customer-public-environment-inspect' and type(receipt['native_exit']) is int
           and receipt['native_exit'] == 0 and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
           and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] > 0
           and receipt['stdout_sha256'] == actual['native_projection_sha256'] and receipt['private_environment_logs_omitted'] is True
           and environment_projection_matches(context, receipt),
           'ACTUAL_NATIVE_ENV_PROJECTION_BINDING')
    require(native_required is False, 'INDEPENDENT_ZERO_NO_NATIVE_REOBSERVATION_OF_REMOVED_CID')
    return actual_binding


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


def fulfillment_extra():
    values = sorted(set(CUSTOMER_EXTRA + DRIVER + [PAID_ENTRY, FULFILLMENT_ENTRY, FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI]
        + [row['path'] for row in THREE_READ_SOURCE_FILES]))
    expect(len(values) == 24, 'EXACT_FULFILLMENT_TWENTY_FOUR_EXTRAS')
    return values


def aftersale_review_extra():
    paths = sorted(set(fulfillment_extra() + list(EARLY_SCRIPTS.values()) + [AFTERSALE_REVIEW_ENTRY]))
    expect(len(paths) == 28, 'ONLY_ORIGINAL_TWENTY_FOUR_PLUS_THREE_CLI_AND_NEW_ENTRY')
    return paths


def worker_extra():
    paths = aftersale_review_extra() + [WORKER_ENTRY]
    require(len(paths) == len(set(paths)) == 29, 'WORKER_EXACT_ORIGINAL_TWENTY_EIGHT_PLUS_ONE_SOURCE_EXTRA')
    return sorted(paths)


def fulfillment_migration_paths():
    return sorted(set(DRIVER) | {FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI, *EARLY_SCRIPTS.values()})


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
    expect(len(rows) == 15 and paid_equal(binding, expected_binding) and load(created_path) == created_facts,
        'EARLY_EXACT_TEN_KEY_FIFTEEN_SOURCE_BINDING')
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
        and type(actual['source_files']) is int and actual['source_files'] == 15
        and actual['binding_sha256'] == digest(binding_path.read_bytes()) and actual['native_created_sha256'] == digest(created_path.read_bytes())
        and actual['entry_sha256'] == binding['entry_sha256'] and actual['cli_sha256'] == binding['cli_sha256']
        and actual['freeze_sha256'] == context['freeze_sha256']
        and paid_equal(actual['initialization_predecessor_sha256'], binding['initialization_predecessor_sha256'])
        and paid_equal(actual['prerequisite_source_sha256'], prerequisites)
        and paid_equal(actual['successor_cli_sha256'], binding['successor_cli_sha256']), 'EARLY_COMPLETE_THIRTEEN_KEY_RESULT_BINDING')
    counts = {'fulfillment': {'check': 2, 'apply-structure': 2}, 'aftersale': {'check': 2, 'apply-structure': 2},
              'aftersale_menus': {'check': 2, 'apply': 2}, 'review_menus': {'check': 2, 'apply': 2}}
    expect(paid_equal(result['actual_command_counts'], counts) and type(result['children']) is list and len(result['children']) == 4
        and type(result['successor_children']) is list and len(result['successor_children']) == 12, 'EARLY_REAL_FOUR_PLUS_TWELVE_COMMANDS')
    for index, mode in enumerate(['check', 'apply-structure', 'check', 'apply-structure']):
        early_child_receipt(result['children'][index], mode, index, FULFILLMENT_CLI, binding['cli_sha256'], prerequisites, 'fulfillment')
    offset = 0; menu_maps = {}
    aftersale_prerequisites = {path: hashes[path] for path in ['help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms5-payment.php', FULFILLMENT_CLI, 'crmeb/public/install/crmeb.sql']}
    for kind, script in EARLY_SCRIPTS.items():
        modes = ['check', 'apply-structure', 'check', 'apply-structure'] if kind == 'aftersale' else ['check', 'apply', 'check', 'apply']
        values = [early_child_receipt(result['successor_children'][offset + index], mode, index, script, hashes[script], aftersale_prerequisites, kind)
                  for index, mode in enumerate(modes)]
        offset += 4
        if kind != 'aftersale':
            actual_ids = values[1]['menu_ids']; expected_auth = EARLY_MENU_AUTHS[kind]
            expect(type(actual_ids) is dict and list(actual_ids) == expected_auth and len(actual_ids) == (7 if kind == 'aftersale_menus' else 3)
                and all(type(value) is int and 1 <= value <= 65535 for value in actual_ids.values())
                and len(set(actual_ids.values())) == len(actual_ids) and values[1]['created_menu_ids'] == actual_ids
                and values[2]['menu_ids'] == actual_ids and values[3]['menu_ids'] == actual_ids and values[3]['created_menu_ids'] == [],
                'EARLY_ACTUAL_SEVEN_AND_THREE_NATURAL_MENU_PK_MAPS')
            menu_maps[kind] = actual_ids
    expect(paid_equal(result['menu_leaves'], menu_maps), 'EARLY_PARENT_EXACT_OBSERVED_TEN_MENU_LEAVES')
    snapshots = result['snapshots']; stages = ['before', 'fulfillment', 'aftersale', 'aftersale_menus', 'review_menus']
    expect(type(snapshots) is dict and list(snapshots) == stages, 'EARLY_FIVE_ACTUAL_SNAPSHOT_STAGES')
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
    before, fulfillment, aftersale, menu1, final = (snapshots[name] for name in stages)
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
    for snapshot in [aftersale, menu1, final]:
        for name, width in {'eb_marketplace_aftersale_action': 21, 'eb_marketplace_aftersale_file': 18,
                            'eb_store_order_refund': 34, 'eb_marketplace_fulfillment_operation': 25, 'eb_marketplace_fulfillment_range': 30}.items():
            expect(len(snapshot['tables'][name]['projected_columns']) == width, 'EARLY_FINAL_STAGE20_COMPLETE_COLUMN_WIDTHS')
            if name != 'eb_store_order_refund':
                expect(snapshot['tables'][name]['projection']['rows'] == 0 and snapshot['tables'][name]['projection']['sha256'] == digest(b''), 'EARLY_FOUR_REAL_EMPTY_TABLES')
    for old, new_snapshot, count in [(aftersale, menu1, 7), (menu1, final, 3)]:
        expect(new_snapshot['names'] == old['names'] and all(new_snapshot['tables'][name] == table
            for name, table in old['tables'].items() if name != 'eb_system_menus')
            and new_snapshot['tables']['eb_system_menus']['projected_columns'] == old['tables']['eb_system_menus']['projected_columns']
            and len(new_snapshot['tables']['eb_system_menus']['projected_columns']) == 22
            and new_snapshot['tables']['eb_system_menus']['projection']['rows'] == old['tables']['eb_system_menus']['projection']['rows'] + count,
            'EARLY_ONLY_ACTUAL_SEVEN_THREE_MENU_INSERTS')
    expect(all(snapshot['modes'] == before['modes'] and snapshot['metadata_observer_modes'] == before['metadata_observer_modes']
        and snapshot['redis'] == before['redis'] for snapshot in snapshots.values()), 'EARLY_ALL_SIX_MODES_AND_ABSOLUTE_REDIS_UNCHANGED')
    comparisons = [('check_first', 'before'), ('check_repeat', 'fulfillment'), ('apply-structure_repeat', 'fulfillment'),
        ('aftersale_check_first', 'fulfillment'), ('aftersale_check_repeat', 'aftersale'), ('aftersale_apply-structure_repeat', 'aftersale'),
        ('aftersale_menus_check_first', 'aftersale'), ('aftersale_menus_check_repeat', 'aftersale_menus'), ('aftersale_menus_apply_repeat', 'aftersale_menus'),
        ('review_menus_check_first', 'aftersale_menus'), ('review_menus_check_repeat', 'review_menus'), ('review_menus_apply_repeat', 'review_menus')]
    expect(result['comparisons'] == [{'phase': phase, 'equals': stage, 'snapshot_sha256': snapshots[stage]['native_snapshot_sha256']}
        for phase, stage in comparisons], 'EARLY_ALL_TWELVE_ACTUAL_COMPLETE_REPEAT_COMPARISONS')


def verify_fulfillment_migration(context, native_required=False):
    require(native_required is False, 'ZERO_OFFLINE_ACTUAL_EARLY_HISTORY_ONLY')
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'])
    terminal = normal_terminal(context, 'fulfillment-migration', FULFILLMENT_MIGRATION['role'], native_required)
    expect(paid_equal(load(evidence / FULFILLMENT_MIGRATION_FOUR[0]), created_facts), 'EXACT_MIGRATION_PUBLIC_CREATED_COPY')
    same_facts(load(evidence / FULFILLMENT_MIGRATION_FOUR[3]), terminal)
    fulfillment_migration_result(context, created_facts, terminal, load(evidence / FULFILLMENT_MIGRATION_FOUR[2]))
    verify_fulfillment_helper(context, 'register-fulfillment-migration', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    early_expected_formal(context, load(evidence / 'ms4-fulfillment-migration-formal-manifest.json'))
    return terminal


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


def zi_read(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink() and not (getattr(path.lstat(), 'st_file_attributes', 0) & 1024), 'ZI_REGULAR_NO_REPARSE')
    data = path.read_bytes(); require(path.read_bytes() == data, 'ZI_COMPLETE_SECOND_READ')
    return data


def zi_one_directory(path):
    path = Path(path)
    require(path.is_dir() and not path.is_symlink() and not (getattr(path.lstat(), 'st_file_attributes', 0) & 1024), 'ZI_REAL_ONE_FILE_DIRECTORY')
    require(sorted(item.name for item in path.iterdir()) == [ZI_LEAF], 'ZI_COMPLETE_EXACT_RESOURCE_NAMESPACE')
    data = zi_read(path / ZI_LEAF)
    require(data == b'memory_limit=512M\n' and len(data) == ZI_PIN['bytes'] and sha(data) == ZI_PIN['sha256'], 'ZI_EXACT_ONLY_MEMORY_DIRECTIVE')
    return data


def zi_template():
    def pref(text):
        return '(and (ge (len .) %d) (eq (slice . 0 %d) %s))' % (len(text), len(text), json.dumps(text))
    return ('[{{range .Config.Env}}{{if ' + pref('PHP_INI_SCAN_DIR=') + '}}{{if eq . ' + json.dumps('PHP_INI_SCAN_DIR=' + ZI_SCAN)
        + '}}{{json "CANONICAL_PROOF_SCAN"}}{{else}}{{json "UNEXPECTED_PROOF_SCAN"}}{{end}}{{else if '
        + pref('PHPRC=') + '}}{{json "UNEXPECTED_PHPRC"}}{{end}}{{end}}]')


def proof_ini_zero(context):
    frozen_raw = zi_read(Path(context['run_dir']) / 'preparation-source-binding.json')
    require(sha(frozen_raw) == context['freeze_sha256'], 'ZI_CURRENT_FROZEN_BYTES')
    frozen = json.loads(frozen_raw)
    if 'proof_php_ini' not in frozen:
        require('proof_php_ini_copy' not in context, 'ZI_NO_UNFROZEN_RESOURCE')
        return
    g = frozen['proof_php_ini']
    require(type(g) is dict and set(g) == {'schema', 'scope', 'source_root', 'files', 'roles', 'scan_dir', 'config_reference'}
        and type(g['schema']) is int and g['schema'] == 1 and g['scope'] == 'MS4_PROOF_PHP_INI_RESOURCE_SOURCE'
        and type(g['source_root']) is str and Path(g['source_root']).is_absolute()
        and g['files'] == [{'path': ZI_LEAF, **ZI_PIN}] and g['roles'] == ZI_ROLES and g['scan_dir'] == ZI_SCAN
        and g['config_reference'] == ZI_REFERENCE, 'ZI_COMPLETE_TYPED_RESOURCE_SOURCE_GROUP')
    zi_one_directory(g['source_root'])
    reference = zi_read(ZI_REFERENCE['path'])
    require(len(reference) == ZI_REFERENCE['bytes'] and sha(reference) == ZI_REFERENCE['sha256'], 'ZI_EXACT_GENUINE_FIXED_IMAGE_REFERENCE')
    rv = json.loads(reference); cf = rv['configurations']
    require(type(rv['schema']) is int and rv['schema'] == 1 and rv['scope'] == 'FIXED_IMAGE_PROOF_INI_AND_FRESH_CHILD_CONFIGURATION_ONLY'
        and rv['status'] == 'VERIFIED_DEFAULT_128M_PROOF_512M_FRESH_CHILD_128M_NO_APP_NO_NETWORK'
        and rv['image'] == 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
        and cf['default']['memory_limit'] == cf['fresh_child']['memory_limit'] == '128M'
        and cf['proof']['memory_limit'] == '512M' and cf['proof']['scan_dir_env'] == ZI_SCAN
        and cf['default']['scan_dir_env'] is None and cf['fresh_child']['scan_dir_env'] is None
        and len(rv['native_operations']) == 10, 'ZI_CONFIG_INPUT_REFERENCE_NOT_RUNNING_PROCESS_INI_GET')
    for nr in rv['native_operations']:
        require(type(nr['pid']) is int and nr['pid'] > 0 and type(nr['native_exit']) is int
            and nr['native_exit'] == nr['wait_returncode'] == 0 and nr['stderr'] == {'bytes': 0, 'sha256': sha(b'')}, 'ZI_ACTUAL_IMAGE_PROTOTYPE_NATIVES')
    cp = context.get('proof_php_ini_copy')
    require(type(cp) is dict and set(cp) == {'path', 'bytes', 'sha256'} and cp['path'] == ZI_COPY
        and type(cp['bytes']) is int and cp['bytes'] > 0 and type(cp['sha256']) is str and re.fullmatch('[a-f0-9]{64}', cp['sha256']) is not None, 'ZI_EXACT_CONTEXT_RESOURCE_POINTER')
    raw = zi_read(Path(context['run_dir']) / ZI_COPY)
    require(cp == {'path': ZI_COPY, 'bytes': len(raw), 'sha256': sha(raw)}, 'ZI_ACTUAL_COPY_EVIDENCE_BYTES')
    copied = json.loads(raw)
    group_sha = sha(json.dumps(g, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode('utf-8'))
    expected = {'schema': 1, 'scope': 'MS4_PROOF_PHP_INI_COPY', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'freeze_sha256': context['freeze_sha256'], 'resource_sha256': group_sha,
        'original': {'path': (Path(g['source_root']) / ZI_LEAF).as_posix(), **ZI_PIN},
        'copy': {'path': (Path(context['run_dir']) / 'proof-php-ini' / ZI_LEAF).as_posix(), **ZI_PIN}}
    require(type(copied) is dict and type(copied.get('schema')) is int and copied == expected, 'ZI_EXACT_FULL_COPY_EVIDENCE_NO_HASH_CYCLE')
    require(zi_one_directory(Path(context['run_dir']) / 'proof-php-ini') == zi_one_directory(g['source_root']), 'ZI_FRESH_ORIGINAL_COPY_WHOLE_BYTES')
    identities = {cid: role for role, cid in context['initial_service_ids'].items()}
    for original in (Path(context['run_dir']) / 'evidence').glob('*-created.json'):
        row = json.loads(zi_read(original))
        if type(row) is dict and 'id' in row and 'labels' in row:
            cid = row['id']; role = row['labels']['com.docker.compose.service']
            require(cid not in identities or identities[cid] == role, 'ZI_SAME_ACTUAL_HISTORICAL_ROLE')
            identities[cid] = role
    directory = Path(context['run_dir']) / ZI_FOLDER
    require(directory.is_dir() and not directory.is_symlink() and not (getattr(directory.lstat(), 'st_file_attributes', 0) & 1024), 'ZI_PUBLIC_NATIVE_EVIDENCE_DIRECTORY')
    seen = set(); all_names = set()
    for number, item in enumerate(sorted(directory.glob('*.record.json')), 1):
        stem = '%06d' % number; require(item.name == stem + '.record.json', 'ZI_CONTIGUOUS_COMPLETE_RECORD_SEQUENCE')
        row = json.loads(zi_read(item)); all_names.add(item.name)
        require(type(row) is dict and set(row) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'role', 'copy_sha256',
            'classification', 'native_created', 'native_terminal', 'stdout', 'stderr'} and type(row['schema']) is int and row['schema'] == 1
            and row['scope'] == 'MS4_PROOF_PHP_INI_ENVIRONMENT_CLASSIFICATION'
            and all(row[k] == context[k] for k in ['run_id', 'source_sha', 'freeze_sha256'])
            and identities.get(row['container_id']) == row['role'] and row['copy_sha256'] == cp['sha256'], 'ZI_BOUND_SAFE_ENVIRONMENT_RECORD')
        streams = {}
        for key, suffix in [('native_created', '.created.json'), ('native_terminal', '.native.json'), ('stdout', '.stdout'), ('stderr', '.stderr')]:
            relative = ZI_FOLDER + '/' + stem + suffix; original = zi_read(Path(context['run_dir']) / relative)
            require(type(row[key]) is dict and set(row[key]) == {'path', 'bytes', 'sha256'} and type(row[key]['bytes']) is int
                and row[key] == {'path': relative, 'bytes': len(original), 'sha256': sha(original)}, 'ZI_ALL_ORIGINAL_SAFE_NATIVE_STREAMS')
            streams[key] = original; all_names.add(stem + suffix)
        nr = json.loads(streams['native_terminal']); born = json.loads(streams['native_created'])
        argv = ['docker', 'inspect', '--type', 'container', '--format', zi_template(), row['container_id']]
        output = b'["CANONICAL_PROOF_SCAN"]\n' if row['role'] in ZI_ROLES else b'[]\n'
        require(type(nr) is dict and set(nr) == {'argv', 'pid', 'native_exit', 'wait_returncode', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256'}
            and nr['argv'] == argv and type(nr['pid']) is int and nr['pid'] > 0
            and type(born) is dict and set(born) == {'argv', 'pid'} and type(born['pid']) is int
            and born == {'argv': argv, 'pid': nr['pid']} and type(nr['native_exit']) is int and type(nr['wait_returncode']) is int
            and nr['native_exit'] == nr['wait_returncode'] == 0 and streams['stdout'] == output and streams['stderr'] == b''
            and type(nr['stdout_bytes']) is int and type(nr['stderr_bytes']) is int
            and nr['stdout_bytes'] == len(output) and nr['stdout_sha256'] == sha(output)
            and nr['stderr_bytes'] == 0 and nr['stderr_sha256'] == sha(b'')
            and row['classification'] == ('CANONICAL_PROOF_SCAN' if row['role'] in ZI_ROLES else 'NO_SCAN_OR_PHPRC'), 'ZI_ACTUAL_POPEN_PID_WAIT_FULL_STREAM_AND_ROLE_CLASSIFICATION')
        seen.add(row['container_id'])
    require({x.name for x in directory.iterdir()} == all_names and seen == set(identities), 'ZI_COMPLETE_NATIVE_NAMESPACE_AND_EVERY_CREATED_ROLE')


def source_row(root, relative):
    raw = regular(Path(root) / relative)
    return {'path': relative, 'bytes': len(raw), 'sha256': sha(raw)}


def canonical_paid(context, facts, original):
    owned(context, facts, PAID_ROLE)
    wanted = {key:value for key,value in original.items() if key not in ['run_id','source_sha','proof_name']}
    require(len(wanted) == 26 and set(facts) == set(wanted), 'H5_ZERO_COMPLETE_ORIGINAL_NATIVE_TWENTY_SIX')
    same_facts(facts, wanted)
    require(facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['restart_count']) is int and facts['restart_count'] == 0
        and all(type(facts[key]) is str and re.fullmatch(r'0001-01-01T00:00:00(?:\.0{1,9})?Z',facts[key])
            for key in ['started_at','finished_at']), 'H5_ZERO_CANONICAL_PAID_NEVER_STARTED_ZERO_TIMES')


def binding_name_for_retirement(proof):
    if proof in ['customer-h5-prepare','customer-h5-serve']:
        return 'ms4-' + proof + '-source-binding.json'
    return proof + '-source-binding.json'


def phase_canonical_bindings(context, original):
    evidence = Path(context['run_dir']) / 'evidence'
    for proof in ['customer-h5-public-copy','register-customer-h5-prepare','customer-h5-prepare',
        'register-customer-h5-files','customer-h5-files','register-customer-h5-serve','customer-h5-serve']:
        binding = load(evidence / binding_name_for_retirement(proof)); row = binding['canonical_paid']
        verify_h5_phase_binding(context, proof, binding)
        raw = regular(evidence / (proof + '-paid-unstarted.json'))
        require(type(row) is dict and set(row) == {'path','bytes','sha256'}
            and type(row['bytes']) is int and row == {'path':'/run/audit/evidence/' + proof + '-paid-unstarted.json',
                'bytes':len(raw),'sha256':sha(raw)}, 'H5_ZERO_EACH_REAL_PHASE_CANONICAL_DESCRIPTOR')
        canonical_paid(context, json.loads(raw), original)
    for proof in ['files-bootstrap','customer-prepare']:
        canonical_paid(context, load(evidence / (proof + '-paid-unstarted.json')), original)


def h5_prerequisite_names(phase):
    counts = {'copy':16,'register-prepare':19,'prepare':19,'register-files':22,
        'files-bootstrap':22,'register-final':25,'serve':28}
    require(phase in counts, 'H5_ZERO_FINITE_REAL_MS5_PHASE')
    names = PREREQUISITES + FILES_PREREQUISITES + PREPARE_PREREQUISITES + ['customer-h5-additional-volume-identities.json']
    if phase != 'copy':
        names += ['customer-h5-public-copy-created.json','customer-h5-public-copy-terminal.json','customer-h5-public-copy.json']
    if phase in ['register-files','files-bootstrap','register-final','serve']:
        names += ['customer-h5-prepare-created.json','customer-h5-prepare-terminal.json','customer-h5-prepare-result.json']
    if phase in ['register-final','serve']:
        names += ['customer-h5-files-created.json','customer-h5-files-terminal.json','customer-h5-files-result.json']
    if phase == 'serve':
        names += ['register-customer-h5-serve-created.json','register-customer-h5-serve-terminal.json','register-customer-h5-serve-result.json']
    require(len(names) == len(set(names)) == counts[phase], 'H5_ZERO_EXACT_MS5_PHASE_PREFIX')
    return names


def verify_h5_phase_binding(context, proof, binding):
    phases = {'customer-h5-public-copy':'copy','register-customer-h5-prepare':'register-prepare',
        'customer-h5-prepare':'prepare','register-customer-h5-files':'register-files',
        'customer-h5-files':'files-bootstrap','register-customer-h5-serve':'register-final','customer-h5-serve':'serve'}
    require(proof in phases, 'H5_ZERO_EXACT_SEVEN_PHASE_BINDINGS')
    root = Path(context['run_dir']); evidence = root / 'evidence'
    frozen = load(root / 'preparation-source-binding.json'); created_raw = regular(evidence / (proof + '-created.json'))
    created = json.loads(created_raw)
    scope = ('MS4_CUSTOMER_H5_PREPARE_MS5_SOURCE' if proof == 'customer-h5-prepare'
        else 'MS4_CUSTOMER_H5_HTTP_MS5_SOURCE' if proof == 'customer-h5-serve'
        else 'MS4_CUSTOMER_H5_FINAL_REGISTRATION_MS5_SOURCE' if proof == 'register-customer-h5-serve'
        else 'MS4_CUSTOMER_H5_BOOTSTRAP_MS5_SOURCE')
    require(type(binding) is dict and type(binding.get('schema')) is int and binding['schema'] == 1
        and binding['scope'] == scope and all(binding[key] == context[key] for key in ['run_id','source_sha','freeze_sha256'])
        and binding['requested_repo_source'] == context['repo_dir'] and binding['requested_run_source'] == context['run_dir']
        and binding['requested_prep_source'] == context['prep_dir'] and binding['container_id'] == created['id']
        and created['run_id'] == context['run_id'] and created['source_sha'] == context['source_sha'] and created['proof_name'] == proof
        and binding['native_created_sha256'] == sha(created_raw) and binding['source_files'] == frozen['customer_source_files']
        and binding['h5_source_files'] == frozen['h5_source_files'], 'H5_ZERO_FULL_REAL_PHASE_SOURCE_IDENTITY')
    names = h5_prerequisite_names(phases[proof])
    require(binding['prerequisites_sha256'] == {name:sha(regular(evidence / name)) for name in names},
        'H5_ZERO_ALL_REAL_ORDERED_PREFIX_SOURCE_BYTES')
    if proof in ['customer-h5-prepare','customer-h5-serve']:
        require(binding['entry_sha256'] == next(row['sha256'] for row in frozen['h5_source_files']
            if row['path'] == 'help/audit-regressions/isolated/ms4-customer-h5.php'), 'H5_ZERO_REAL_H5_CORE_ENTRY')
    else:
        entry = 'ms4-h5-public-copy.php' if proof == 'customer-h5-public-copy' else 'ms4-h5-bootstrap.php'
        require(binding['entry_sha256'] == next(row['sha256'] for row in frozen['h5_artifact_files'] if row['path'] == entry)
            and binding['artifact_files'] == frozen['artifact_files'] and binding['h5_artifact_files'] == frozen['h5_artifact_files'],
            'H5_ZERO_ALL_NINE_AND_TWENTY_BOOTSTRAP_ARTIFACTS')
    native = load(evidence / (proof + '-environment-native.json'))
    require(native['stage'] == 'customer-public-environment-inspect' and type(native['native_exit']) is int and native['native_exit'] == 0
        and native['stderr_bytes'] == 0 and native['stderr_sha256'] == sha(b'') and native['private_environment_logs_omitted'] is True
        and environment_projection_matches(context, native), 'H5_ZERO_SAME_ACTUAL_SIX_ENV_NATIVE_PROJECTION')
    if proof in ['customer-h5-prepare','customer-h5-serve']:
        env = load(evidence / (proof + '-environment.json'))
        require(set(env) == {'schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256'}
            and type(env['schema']) is int and env['schema'] == 1 and env['scope'] == 'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT'
            and all(env[key] == binding[key] for key in ['run_id','source_sha','container_id','freeze_sha256'])
            and env['environment'] == public_environment(context) and env['native_projection_sha256'] == native['stdout_sha256'],
            'H5_ZERO_REAL_H5_ENVIRONMENT_BINDING')
    else:
        require(binding['environment'] == public_environment(context), 'H5_ZERO_BOOTSTRAP_EXACT_SIX_ENVIRONMENT_VALUES')


def current_prerequisites(context):
    migration_prerequisites(context)
    verify_fulfillment_migration(context, False)
    verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    verify_files_bootstrap(context, False)
    verify_mode_result(context, 'prepare', False)


def global_no_original_consumers(context, expected):
    receipts = []
    def native(argv):
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        stdout, stderr = process.communicate(); waited = process.wait()
        require(type(process.pid) is int and process.pid > 0 and type(process.returncode) is int
            and process.returncode == waited == 0 and stderr == b'', 'H5_ZERO_GLOBAL_SAME_NATIVE_HANDLE_ZERO')
        receipts.append({'argv':argv, 'pid':process.pid, 'native_exit':process.returncode, 'wait_returncode':waited,
            'stdout_bytes':len(stdout), 'stdout_sha256':sha(stdout), 'stderr_bytes':len(stderr), 'stderr_sha256':sha(stderr)})
        return stdout
    stdout = native(['docker','ps','-aq','--no-trunc'])
    ids = stdout.decode('ascii').splitlines()
    require(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}',cid) for cid in ids),
        'H5_ZERO_GLOBAL_FULL_UNIQUE_NATIVE_CIDS')
    historical = set(expected)
    for path in (Path(context['run_dir']) / 'evidence').glob('*-created.json'):
        row = load(path)
        if type(row) is dict and 'id' in row and 'labels' in row:
            require(row.get('run_id') == context['run_id'] and row.get('source_sha') == context['source_sha']
                and row['labels'].get('tianli.audit.run') == context['run_id']
                and row['labels'].get('com.docker.compose.project') == context['project'],
                'H5_ZERO_ALL_ORIGINAL_HISTORICAL_CID_IDENTITIES')
            historical.add(row['id'])
    require(not historical.intersection(ids), 'H5_ZERO_NO_ORIGINAL_OR_TRANSIENT_NATIVE_CID')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'
    if not ids:
        return receipts
    stdout = native(['docker','inspect','--format',template,*ids])
    rows = [json.loads(line) for line in stdout.decode('utf-8').splitlines()]
    require(len(rows) == len(ids) and {row['id'] for row in rows} == set(ids), 'H5_ZERO_COMPLETE_GLOBAL_NATIVE_ROWS')
    volumes = {context['project'] + '_' + name for name in VOLUMES}
    networks = {context['initial_network']['Name'],context['project'] + '_frontend'}
    for row in rows:
        require(type(row) is dict and set(row) == {'id','mounts','networks'}
            and type(row['mounts']) is list and type(row['networks']) is dict,
            'H5_ZERO_FULL_GLOBAL_NATIVE_CONSUMER_SHAPE')
        require(not {mount.get('Name') for mount in row['mounts'] if mount.get('Type') == 'volume'}.intersection(volumes)
            and not set(row['networks']).intersection(networks), 'H5_ZERO_NO_CONSUMER_OF_ELEVEN_VOLUMES_OR_EITHER_NETWORK')
    return receipts



# Current28dc normal9 reader remains independent of Host/close/App.
# The original copied reader functions above are preserved history, never an active fallback.
CURRENT28DC_H5_CORE2 = [{'path': 'help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php', 'bytes': 165967, 'sha256': '9f47fff3886192aaed5347eb747978e1c54c152e8bc2f77563b406644f4f75bd'}, {'path': 'help/audit-regressions/isolated/ms4-customer-h5.php', 'bytes': 112678, 'sha256': 'c78fbf4b4c05b0db98ad51779db314264f2c4ff96c34db9552258a8b07191533'}]
CURRENT28DC_NORMAL_READER = {'path': 'verify-zero-ms4-fulfillment.py', 'bytes': 296098, 'sha256': 'ee5a9f65d87d063100f834c8456364cb8024fb3414467827e6c1ec92aa03b54f'}

def current28dc_normal_reader(context):
    import importlib.util
    require(context['source_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7', 'H5_ZERO_CURRENT28DC_EXACT_SOURCE')
    freeze_raw = regular(Path(context['run_dir']) / 'preparation-source-binding.json')
    require(sha(freeze_raw) == context['freeze_sha256'], 'H5_ZERO_CURRENT_READER_ORIGINAL_FREEZE')
    frozen = json.loads(freeze_raw)
    row = CURRENT28DC_NORMAL_READER
    require(frozen['artifact_files'].count(row) == 1, 'H5_ZERO_CURRENT_INDEPENDENT_NORMAL_READER_FROZEN_ONCE')
    path = PREP / row['path']; body = regular(path)
    require(len(body) == row['bytes'] and sha(body) == row['sha256'], 'H5_ZERO_EXACT_COMPLETE_CURRENT_NORMAL_READER_BYTES')
    spec = importlib.util.spec_from_file_location('ms4_h5_current28dc_independent_normal_reader', path)
    reader = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(reader)
    return reader

def current28dc_fixed_sources(context):
    reader = current28dc_normal_reader(context)
    reader.fixed_sources(context)
    freeze_raw = regular(Path(context['run_dir']) / 'preparation-source-binding.json'); frozen = json.loads(freeze_raw)
    require(sha(freeze_raw) == context['freeze_sha256'] and frozen['source_sha'] == context['source_sha']
        and frozen['requested_repo_source'] == context['repo_dir']
        and frozen['h5_preparation_scope'] == 'SOURCE_FROZEN_NOT_RUNTIME'
        and frozen['h5_runtime_source_status'] == 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED'
        and frozen['h5_serve_browser_acceptance'] == 'NOT RUN'
        and [row['path'] for row in frozen['artifact_files']] == BASE_ARTIFACTS
        and [row['path'] for row in frozen['h5_artifact_files']] == H5_ARTIFACTS
        and [row['path'] for row in frozen['h5_source_files']] == H5_SOURCES
        and frozen['customer_source_roots'] == ROOTS and frozen['customer_source_extra'] == sorted(reader.SOURCE_EXTRA), 'H5_ZERO_EXACT_ORIGINAL_AND_TWENTY_H5_FREEZE')
    require(frozen['h5_source_files'] == CURRENT28DC_H5_CORE2, 'H5_ZERO_CURRENT28DC_EXACT_COMMITTED_CORE2')
    proof_ini_zero(context)
    require([row['path'] for row in frozen['driver_source_files']] == PROFILE_DRIVER
        and [row['path'] for row in frozen['preparation_source_references']] == PROFILE_REFERENCES,
        'H5_ZERO_EXACT_CURRENT_TEN_DRIVER_AND_FOUR_REFERENCES')
    repo = Path(context['repo_dir']); paths = set(reader.SOURCE_EXTRA)
    for relative in ROOTS:
        subtree = tree(repo / relative)
        paths.update(relative + '/' + row['path'] for row in subtree['files'])
    require(sorted(paths) == [row['path'] for row in frozen['customer_source_files']], 'H5_ZERO_COMPLETE_ORIGINAL_SOURCE_CLOSURE')
    for prefix, extras in [('paid', sorted(reader.SOURCE_EXTRA + [reader.PAID_ENTRY])), ('fulfillment', reader.fulfillment_extra()),
        ('aftersale_review', reader.aftersale_review_extra()), ('worker', reader.worker_extra())]:
        require(frozen[prefix + '_source_roots'] == ROOTS and frozen[prefix + '_source_extra'] == extras
            and [row['path'] for row in frozen[prefix + '_source_files']] == sorted(paths | set(extras)),
            'H5_ZERO_COMPLETE_CURRENT_FOUR_ROOT_SOURCE_GROUP')
    for group,root in [('driver_source_files',repo),('customer_source_files',repo),
                       ('paid_source_files',repo),('fulfillment_source_files',repo),
                       ('aftersale_review_source_files',repo),('worker_source_files',repo),('preparation_source_references',repo),
                       ('h5_source_files',repo),('artifact_files',PREP),('h5_artifact_files',PREP)]:
        file_rows(root,frozen[group])
    for key,name in [('public_source','public-source.json'),('dist','h5-dist.json'),('inputs','inputs.json')]:
        raw = regular(PREP / 'manifests' / name); bound = frozen['h5_materials'][key]
        require(bound == {'path':'/run/ms4-prep/manifests/' + name,'bytes':len(raw),'sha256':sha(raw)}, 'H5_ZERO_REAL_COMPLETE_MATERIAL_MANIFEST')
    require(load(PREP / 'manifests/public-source.json') == tree(repo / 'crmeb/public')
        and load(PREP / 'manifests/h5-dist.json') == tree(frozen['h5_dist_source']), 'H5_ZERO_COMPLETE_PUBLIC_AND_DIST_SOURCE_BYTES')
    inputs = load(PREP / 'manifests/inputs.json')
    require(set(inputs) == {'schema','language','theme'} and inputs['schema'] == 1, 'H5_ZERO_EXACT_LANGUAGE_THEME_MANIFEST')
    for name in ['language','theme']:
        raw = regular(PREP / 'inputs' / (name + '.json'))
        require(inputs[name] == {'path':'/run/ms4-prep/inputs/' + name + '.json','bytes':len(raw),'sha256':sha(raw)}, 'H5_ZERO_REAL_INPUT_BYTES')
    provenance = frozen['h5_build_provenance']; file_rows(provenance['build_directory'],provenance['files'])
    for args,wanted in [(['rev-parse','HEAD'],context['source_sha']),(['status','--porcelain=v1','--untracked-files=all'],'')]:
        process = subprocess.run(['git','-c','safe.directory=' + repo.as_posix(),'-C',str(repo),*args],capture_output=True)
        require(process.returncode == 0 and process.stderr == b'' and process.stdout.decode().rstrip('\r\n') == wanted,
                'H5_ZERO_EXACT_CLEAN_SOURCE_HEAD')
    return frozen


def current28dc_current_prerequisites(context):
    reader = current28dc_normal_reader(context)
    reader.migration_prerequisites(context)
    reader.verify_fulfillment_migration(context, False)
    reader.verify_paid_helper(context, 'register-paid-producer', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    reader.verify_files_bootstrap(context, False)
    reader.verify_mode_result(context, 'prepare', False)

def current28dc_environment_projection_matches(context, native):
    return current28dc_normal_reader(context).environment_projection_matches(context, native)

def current28dc_owned(context, facts, service):
    return current28dc_normal_reader(context).owned(context, facts, service)

def current28dc_prerequisite_result(context, created, terminal, result):
    return current28dc_normal_reader(context).prerequisite_result(context, created, terminal, result)

fixed_sources = current28dc_fixed_sources
current_prerequisites = current28dc_current_prerequisites
environment_projection_matches = current28dc_environment_projection_matches
owned = current28dc_owned
prerequisite_result = current28dc_prerequisite_result

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--context',required=True)
    parser.add_argument('--close-result-sha256',required=True)
    arguments = parser.parse_args()
    try:
        main(arguments.context,arguments.close_result_sha256)
    except Exception as error:
        print(json.dumps({'status':'INDEPENDENT_H5_ZERO_INCOMPLETE_STATE_PRESERVED','class':type(error).__name__,
                          'native_exit':1,'no_mutation_or_retry':True}))
        raise SystemExit(1)
