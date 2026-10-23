"""SOURCE ONLY. Complete normal H5 close; no partial acceptance or automatic retry.

Install with the reviewed twenty-artifact successor profile. Root supplies the
original context. Each Docker stop has one native handle and --time -1. Private
control tar/JSON stays in memory; public evidence contains derived projections.
"""
import argparse
import base64
import hashlib
import importlib.util
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile

PREP = Path(__file__).resolve().parent
P4 = ['run_id', 'source_sha', 'container_id', 'freeze_sha256']
I8 = [*P4, 'epoch', 'step_nonce', 'not_before', 'deadline']
SCENES = ['login-empty', 'store-a', 'store-b', 'product-a', 'product-b', 'product-c',
          'cart-three-lines', 'confirm-filled', 'order-detail-unpaid']
H5_ARTIFACTS = ['H5-README.md', 'compose.h5.yml', 'h5-derivation.json', 'ms4-h5-host.py',
    'ms4-h5-materials.py', 'ms4-h5-bootstrap.php', 'ms4-h5-public-copy.php', 'ms4-h5-nginx.conf',
    'ms4-order.png', 'inputs/language.json', 'inputs/theme.json', 'manifests/public-source.json',
    'manifests/h5-dist.json', 'manifests/inputs.json', 'h5-materials-result.json',
    'browser-v1/customer-network-observer.mjs', 'browser-v1/ms4-customer-private-bridge.mjs',
    'browser-v1/ms4-customer-browser.mjs', 'normal-close-customer-h5.py', 'verify-zero-customer-h5.py']
VOLUMES = ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets', 'http-runtime', 'http-control', 'http-browser-secrets', 'http-public']
ROLES = ['audit-ui', 'audit-ms4-customer-h5', 'audit-mysql', 'audit-redis']
PAID_ROLE = 'audit-ms5-paid-producer'
FINAL_ROLES = [*ROLES, PAID_ROLE]
PROOFS = {'audit-ui': 'customer-h5-ui', 'audit-ms4-customer-h5': 'customer-h5-serve'}
CONTROL = '/run/audit-http-control/'
SCOPE_LIMIT = 'OBSERVED_27_BUSINESS_PLUS_7_AUXILIARY_TABLES_ONLY'
STATE = {'phase': 'source_preflight', 'context': None, 'host': None, 'base': None, 'waiting_native': None}


def require(value, code):
    if not value:
        raise RuntimeError(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def regular(path, maximum=None):
    path = Path(path); before = path.lstat()
    for parent in path.parents:
        stat = parent.lstat()
        require(parent.is_dir() and not parent.is_symlink() and not getattr(stat,'st_file_attributes',0) & 0x400,
                'H5_CLOSE_PARENT_NO_REPARSE')
    require(path.is_file() and not path.is_symlink() and not getattr(before, 'st_file_attributes', 0) & 0x400,
            'H5_CLOSE_REGULAR_NO_REPARSE')
    require(maximum is None or 0 < before.st_size <= maximum, 'H5_CLOSE_BOUNDED_PUBLIC_FILE')
    raw = path.read_bytes(); after = path.lstat()
    require(len(raw) == before.st_size and all(getattr(before, key) == getattr(after, key)
        for key in ['st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']),
        'H5_CLOSE_UNCHANGED_PUBLIC_FILE')
    return raw


def load(path):
    return json.loads(regular(path).decode('utf-8-sig'))


def keys(value, names):
    require(type(value) is dict and set(value) == set(names), 'H5_CLOSE_EXACT_KEYS')


def new(path, value):
    path = Path(path); require(path.parent.is_dir() and not path.exists() and not path.is_symlink(), 'H5_CLOSE_CREATE_ONLY')
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'H5_CLOSE_COMPLETE_WRITE'); stream.flush()
    require(regular(path) == raw, 'H5_CLOSE_COMPLETE_READBACK')


def announce(phase, **fields):
    STATE['phase'] = phase
    print(json.dumps({'phase': phase, **fields}, ensure_ascii=False), flush=True)


def descriptor(path, wire_path=None):
    raw = regular(path)
    return {'path': wire_path if wire_path is not None else Path(path).as_posix(), 'bytes': len(raw), 'sha256': sha(raw)}


def source_preflight(context_file):
    context_raw = regular(context_file); context = json.loads(context_raw.decode('utf-8-sig'))
    require(Path(context_file).resolve() == (Path(context['run_dir']) / 'actual-run-context.json').resolve()
        and context['prep_dir'] == PREP.as_posix() and re.fullmatch('[0-9]{14}[a-f0-9]{6}', context['run_id'])
        and re.fullmatch('[a-f0-9]{40}', context['source_sha']) and context['project'] == 'tianli-audit-' + context['run_id'],
        'H5_CLOSE_SINGLE_ORIGINAL_CONTEXT')
    freeze_raw = regular(Path(context['run_dir']) / 'preparation-source-binding.json'); frozen = json.loads(freeze_raw)
    require(sha(freeze_raw) == context['freeze_sha256'] and frozen['source_sha'] == context['source_sha']
        and frozen['requested_repo_source'] == context['repo_dir']
        and frozen['h5_preparation_scope'] == 'SOURCE_FROZEN_NOT_RUNTIME'
        and frozen['h5_runtime_source_status'] == 'SOURCE_IMPLEMENTED_NOT_RUNTIME_VERIFIED'
        and frozen['h5_serve_browser_acceptance'] == 'NOT RUN'
        and [row['path'] for row in frozen['h5_artifact_files']] == H5_ARTIFACTS, 'H5_CLOSE_ACTUAL_TWENTY_FILE_FREEZE')
    for group, root in [('artifact_files', PREP), ('h5_artifact_files', PREP),
                        ('h5_source_files', Path(context['repo_dir']))]:
        for row in frozen[group]:
            keys(row, ['path', 'bytes', 'sha256']); rel = PurePosixPath(row['path'])
            require(not rel.is_absolute() and '..' not in rel.parts and str(rel) == row['path'], 'H5_CLOSE_CANONICAL_SOURCE_PATH')
            raw = regular(root / row['path'])
            require(type(row['bytes']) is int and len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'H5_CLOSE_FROZEN_SOURCE_BYTES')
    require(Path(__file__).resolve() == PREP / 'normal-close-customer-h5.py', 'H5_CLOSE_ACTUAL_FROZEN_ENTRY')
    def imported(name, path):
        spec = importlib.util.spec_from_file_location(name, path)
        instance = importlib.util.module_from_spec(spec); spec.loader.exec_module(instance); return instance
    host = imported('ms4_h5_close_host', PREP / 'ms4-h5-host.py')
    base, materials = host.dependencies()
    require(base.current(context_file) == context, 'H5_CLOSE_SAME_ORIGINAL_BASE_CONTEXT')
    host.final_h5_source(base, materials, context, frozen)
    base.proof_ini_audit(context, True)
    STATE.update(context=context, host=host, base=base)
    return host, base, materials, context, frozen, sha(context_raw)


def inventory(base, context):
    actual = {}
    for kind in ['container', 'network', 'volume']:
        actual[kind] = {}
        for label, value in [('project', 'com.docker.compose.project=' + context['project']),
                             ('run', 'tianli.audit.run=' + context['run_id'])]:
            values = base.ids(context, kind, value)
            if kind == 'network':
                values = [base.native(context, ['network', 'inspect', '--format', '{{.Id}}', item], 'h5-close-full-network-id') for item in values]
            require(len(values) == len(set(values)), 'H5_CLOSE_UNIQUE_NATIVE_LABEL_INVENTORY')
            actual[kind][label] = sorted(values)
    return actual


def original_resources(host, base, context, frozen, expected, proofs):
    actual = inventory(base, context)
    require(actual['container']['project'] == actual['container']['run'] == sorted(expected), 'H5_CLOSE_EXACT_REMAINING_CIDS')
    networks = host.final_h5_networks(base, context, expected)
    volumes = host.h5_volumes(base, context)
    require(set(base.VOLUMES).isdisjoint(host.H5_VOLUMES)
        and VOLUMES == [*base.VOLUMES, *host.H5_VOLUMES]
        and [row['name'] for row in volumes] == sorted(context['project'] + '_' + name for name in VOLUMES),
        'H5_CLOSE_EXACT_SEVEN_PLUS_FOUR_NATIVE_VOLUME_IDENTITIES')
    facts = {}
    for cid, role in expected.items():
        row = base.inspect(context, cid, 'h5-close-original-native-facts')
        host.final_h5_owned(base, context, frozen, row, role, proofs.get(cid))
        before = (context['initial_service_created'][role] if role in ['audit-mysql', 'audit-redis']
            else base.bound_created(context, base.PAID['proof'], PAID_ROLE) if role == PAID_ROLE
            else host.bound_final_h5(base, context, frozen, proofs[cid]))
        base.same_created(before, row)
        if role == PAID_ROLE:
            canonical_paid(base, context, row)
        else:
            require(row['restart_count'] == 0 and row['status'] in ['running', 'exited'], 'H5_CLOSE_NO_RESTART_OR_UNSTARTED_RUNNING_ROLE')
        facts[cid] = row
    host.final_h5_global_consumers(base, context, set(expected))
    return {'inventory': actual, 'networks': networks, 'volumes': volumes, 'containers': facts}


def canonical_paid(base, context, facts):
    original = base.bound_created(context, base.PAID['proof'], PAID_ROLE)
    native = {key: value for key, value in original.items() if key not in ['run_id', 'source_sha', 'proof_name']}
    require(base.unstarted_paid_native_equal(facts, native) and len(native) == 26
        and facts['status'] == 'created' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['restart_count']) is int and facts['restart_count'] == 0
        and all(type(facts[key]) is str and re.fullmatch(r'0001-01-01T00:00:00(?:\.0{1,9})?Z', facts[key])
            for key in ['started_at', 'finished_at']), 'H5_CLOSE_SAME_COMPLETE_CANONICAL_PAID_NEVER_STARTED')
    return facts


def private_directory_meta(cid, relative):
    require(relative in ['ui-captures','ui-screenshots','ui-reviews'], 'H5_CLOSE_FINITE_METADATA_DIRECTORY')
    code = """$p=$argv[1];clearstatcache(true,$p);$s=lstat($p);
if(!is_array($s)||!is_dir($p)||is_link($p)||realpath($p)!==$p)exit(2);
echo json_encode(array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid','mtime','ctime'])),JSON_THROW_ON_ERROR);"""
    process = subprocess.run(['docker','exec',cid,'php','-r',code,CONTROL + relative],capture_output=True)
    require(process.returncode == 0 and process.stderr == b'' and 0 < len(process.stdout) <= 32768,
            'H5_CLOSE_REAL_PRIVATE_DIRECTORY_METADATA_ZERO')
    value = json.loads(process.stdout); keys(value,['dev','ino','mode','uid','gid','mtime','ctime'])
    require(value['mode'] == 0o40700 and value['uid'] == 0, 'H5_CLOSE_PRIVATE_DIRECTORY_ROOT_0700')
    return value


def memory_tar(context, cid, relative, directory=False, maximum=2097152):
    """One actual cp process, bounded retained bytes, no private host file/stdout."""
    fixed = {'runtime.json', 'source-ready.json', 'active-supervisor.json', 'finish-browser.json',
        'browser-closed.json', 'sql-audit.json', 'signal-stop.json', *('stage-' + stage + '.json' for stage in ['B0','B1','B2','B3','B4'])}
    require(relative in fixed or re.fullmatch('supervisor-(requests|responses)/[a-f0-9]{48}\\.json', relative)
        or directory and relative in ['ui-captures', 'ui-screenshots', 'ui-reviews'], 'H5_CLOSE_FINITE_PRIVATE_CONTROL_PATH')
    source = CONTROL + relative + ('/.' if directory else '')
    directory_before = private_directory_meta(cid,relative) if directory else None
    process = subprocess.Popen(['docker', 'cp', cid + ':' + source, '-'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    require(process.pid > 0, 'H5_CLOSE_ACTUAL_PRIVATE_CP_PID')
    retained = bytearray(); overflow = False; archive_limit = maximum + 1048576
    try:
        while True:
            chunk = process.stdout.read(65536)
            if not chunk:
                break
            if len(retained) + len(chunk) <= archive_limit:
                retained.extend(chunk)
            else:
                overflow = True
        _, stderr = process.communicate()
        require(process.returncode == 0 and stderr == b'' and not overflow, 'H5_CLOSE_ACTUAL_BOUNDED_PRIVATE_CP_ZERO')
        output = {}; total = 0
        with tarfile.open(fileobj=io.BytesIO(retained), mode='r:') as archive:
            for member in archive:
                name = member.name.removeprefix('./'); rel = PurePosixPath(name)
                require(not rel.is_absolute() and '..' not in rel.parts and not member.issym() and not member.islnk(), 'H5_CLOSE_PRIVATE_TAR_NO_LINK_OR_ESCAPE')
                if member.isdir():
                    require(directory and name.rstrip('/') in ['', '.', relative] and member.mode == 0o700 and member.uid == 0,
                            'H5_CLOSE_PRIVATE_REAL_DIRECTORY_OWNER_MODE')
                    continue
                require(member.isfile() and member.mode == 0o600 and member.uid == 0
                    and 0 < member.size <= 2097152, 'H5_CLOSE_PRIVATE_REAL_FILE_OWNER_MODE')
                leaf = rel.name
                require((directory and (len(rel.parts) == 1 or len(rel.parts) == 2 and rel.parts[0] == relative)
                    and re.fullmatch('[a-f0-9]{48}\\.json', leaf))
                    or (not directory and leaf == PurePosixPath(relative).name and len(rel.parts) == 1), 'H5_CLOSE_PRIVATE_FINITE_TAR_MEMBER')
                require(leaf not in output, 'H5_CLOSE_PRIVATE_UNIQUE_TAR_MEMBER')
                total += member.size; require(total <= maximum, 'H5_CLOSE_PRIVATE_AGGREGATE_BOUND')
                stream = archive.extractfile(member); raw = bytearray(stream.read(member.size + 1))
                require(len(raw) == member.size, 'H5_CLOSE_COMPLETE_PRIVATE_TAR_READ')
                try:
                    value = json.loads(raw); require(type(value) is dict, 'H5_CLOSE_PRIVATE_JSON_OBJECT')
                    output[leaf] = {'value': value, 'bytes': len(raw), 'sha256': sha(raw)}
                finally:
                    raw[:] = b'\0' * len(raw)
        require(directory or len(output) == 1, 'H5_CLOSE_EXACT_PRIVATE_FILE')
        if directory:
            require(private_directory_meta(cid,relative) == directory_before, 'H5_CLOSE_PRIVATE_DIRECTORY_IDENTITY_UNCHANGED')
        return output if directory else next(iter(output.values()))
    finally:
        retained[:] = b'\0' * len(retained)


def identity(value, context, cid, runtime=None):
    require(value.get('schema') == 1 and all(value.get(key) == (cid if key == 'container_id' else context[key]) for key in P4),
            'H5_CLOSE_PRIVATE_FULL_SOURCE_IDENTITY')
    require(type(value.get('epoch')) is str and re.fullmatch('[a-f0-9]{64}', value['epoch'])
        and type(value.get('step_nonce')) is str and re.fullmatch('[a-f0-9]{48}', value['step_nonce'])
        and type(value.get('not_before')) is int and type(value.get('deadline')) is int
        and value['not_before'] <= value['deadline'], 'H5_CLOSE_PRIVATE_FULL_FRAME')
    if runtime is not None:
        require(value['epoch'] == runtime['epoch'] and value['deadline'] == runtime['deadline']
            and value['not_before'] >= runtime['not_before'], 'H5_CLOSE_SAME_PRIVATE_RUNTIME')


def same_frame(left, right):
    require(all(left[key] == right[key] for key in I8), 'H5_CLOSE_COMPLETE_ORIGINAL_PRIVATE_FRAME')


def public_identity(value):
    return {'schema': 1, **{key: value[key] for key in P4}, 'epoch_prefix': value['epoch'][:12],
        'step_nonce_prefix': value['step_nonce'][:12], 'not_before': value['not_before'], 'deadline': value['deadline']}


def supervisor_projection(value):
    return {**public_identity(value), 'scope': value['scope'], **{key: value[key] for key in
        ['action','status','supervisor','group','members','remaining_members','listener_open','stop_signal',
         'term_attempted','native_child','native_proc_close','normal_only','code']},
        'generation_prefix': value['generation'][:12], 'public_projection_only': True}


def jpeg_pixels(raw):
    require(len(raw) >= 12 and raw[:2] == b'\xff\xd8' and raw[-2:] == b'\xff\xd9', 'H5_CLOSE_REAL_JPEG_HEADER')
    position = 2; sof = {0xc0,0xc1,0xc2,0xc3,0xc5,0xc6,0xc7,0xc9,0xca,0xcb,0xcd,0xce,0xcf}
    while position + 3 < len(raw):
        require(raw[position] == 255, 'H5_CLOSE_JPEG_MARKER'); position += 1
        while position < len(raw) and raw[position] == 255:
            position += 1
        marker = raw[position]; position += 1
        require(marker not in [0,0xda,0xd9], 'H5_CLOSE_JPEG_DIMENSIONS_BEFORE_SCAN')
        if marker == 1 or 0xd0 <= marker <= 0xd7:
            continue
        length = int.from_bytes(raw[position:position+2], 'big')
        require(length >= 2 and position + length <= len(raw), 'H5_CLOSE_JPEG_REAL_SEGMENT')
        if marker in sof:
            require(length >= 8, 'H5_CLOSE_JPEG_REAL_FRAME')
            value = {'width': int.from_bytes(raw[position+5:position+7], 'big'), 'height': int.from_bytes(raw[position+3:position+5], 'big')}
            require(all(0 < number <= 10000 for number in value.values()), 'H5_CLOSE_JPEG_REAL_PIXELS'); return value
        position += length
    raise RuntimeError('H5_CLOSE_JPEG_DIMENSIONS_REQUIRED')


def private_normal_proof(context, cid, binding):
    evidence = Path(context['run_dir']) / 'evidence'
    read = lambda relative, **kw: memory_tar(context, cid, relative, **kw)
    runtime_item = read('runtime.json'); runtime = runtime_item['value']
    keys(runtime, ['schema','scope',*I8,'origin']); identity(runtime, context, cid)
    require(runtime['scope'] == 'MS4_CUSTOMER_H5_HTTP_RUNTIME' and runtime['deadline'] - runtime['not_before'] == 3600
        and runtime['origin'] == binding['h5_materials']['runtime_origin'], 'H5_CLOSE_REAL_ORIGINAL_RUNTIME')
    ready_item = read('source-ready.json'); ready = ready_item['value']
    keys(ready, ['schema','scope',*I8,'source_binding_sha256','native_created_sha256','public_receipt_sha256',
                 'runtime_sha256','supervisor','full_source_verified']); identity(ready, context, cid, runtime); same_frame(ready, runtime)
    require(ready['scope'] == 'MS4_CUSTOMER_H5_HTTP_SOURCE_READY' and ready['full_source_verified'] is True
        and ready['source_binding_sha256'] == sha(regular(evidence / 'ms4-customer-h5-serve-source-binding.json'))
        and ready['native_created_sha256'] == binding['native_created_sha256']
        and ready['public_receipt_sha256'] == binding['public_copy']['sha256']
        and ready['runtime_sha256'] == runtime_item['sha256'], 'H5_CLOSE_FULL_PRIVATE_READY_BINDING')
    keys(ready['supervisor'], ['pid','ppid','pgid','sid','starttime','state'])
    expected_ready = {**public_identity(ready), **{key: ready[key] for key in ['scope','source_binding_sha256',
        'native_created_sha256','public_receipt_sha256','supervisor','full_source_verified']},
        'runtime_sha256_prefix': runtime_item['sha256'][:12], 'private_ready_sha256_prefix': ready_item['sha256'][:12],
        'private_ready_bytes': ready_item['bytes'], 'public_projection_only': True}
    require(load(evidence / 'http-source-ready.json') == expected_ready, 'H5_CLOSE_DERIVED_ORIGINAL_READY_PUBLIC_PROJECTION')
    active = read('active-supervisor.json')['value']; identity(active, context, cid, runtime)
    keys(active, ['schema','scope',*I8,'payload_meta']); require(active['scope'] == 'MS4_CUSTOMER_H5_HTTP_ACTIVE', 'H5_CLOSE_ORIGINAL_ACTIVE_STOP')
    stop_request_item = read('supervisor-requests/' + active['step_nonce'] + '.json'); stop_request = stop_request_item['value']
    keys(stop_request, ['schema','scope',*I8,'action']); identity(stop_request, context, cid, runtime); same_frame(stop_request, active)
    require(stop_request['scope'] == 'MS4_CUSTOMER_H5_HTTP_CONTROL' and stop_request['action'] == 'stop'
        and active['payload_meta']['bytes'] == stop_request_item['bytes']
        and active['payload_meta']['sha256'] == stop_request_item['sha256'], 'H5_CLOSE_FULL_ACTIVE_STOP_BYTES')
    status_keys = ['schema','scope',*I8,'action','status','generation','supervisor','group','members','remaining_members',
                   'listener_open','stop_signal','term_attempted','native_child','native_proc_close','normal_only','code']
    stop_item = read('supervisor-responses/' + active['step_nonce'] + '.json'); stop = stop_item['value']
    keys(stop, status_keys); identity(stop, context, cid, runtime); same_frame(stop, stop_request)
    require(stop['scope'] == 'MS4_CUSTOMER_H5_HTTP_STATUS' and stop['action'] == stop['status'] == 'stop'
        and stop['members'] == stop['remaining_members'] == [] and stop['listener_open'] is False
        and stop['stop_signal'] == 15 and stop['term_attempted'] is True and stop['normal_only'] is True
        and type(stop['native_child']) is dict and stop['native_child']['running'] is False
        and stop['native_child']['termsig'] != 9 and type(stop['native_proc_close']) is int
        and re.fullmatch('[a-f0-9]{48}', stop['generation']), 'H5_CLOSE_REAL_SINGLE_TERM_CHILD_STOP')
    start_request = read('supervisor-requests/' + stop['generation'] + '.json')['value']
    start = read('supervisor-responses/' + stop['generation'] + '.json')['value']
    keys(start_request, ['schema','scope',*I8,'action']); keys(start, status_keys)
    identity(start_request, context, cid, runtime); identity(start, context, cid, runtime); same_frame(start_request, start)
    require(start_request['action'] == start['action'] == start['status'] == 'start'
        and start['generation'] == start['step_nonce'] == stop['generation'] and start['members']
        and start['listener_open'] is True and start['group'] == stop['group']
        and start['supervisor'] == stop['supervisor'] == ready['supervisor'], 'H5_CLOSE_SAME_ORIGINAL_STARTED_GROUP_PARENT')
    keys(stop['group'], ['leader_pid','pgid','sid','leader_starttime'])
    group = stop['group']; require(group['leader_pid'] > 1 and group['pgid'] == group['sid'] == group['leader_pid']
        and group['leader_starttime'] >= ready['supervisor']['starttime']
        and group['pgid'] not in [ready['supervisor']['pid'],ready['supervisor']['pgid'],ready['supervisor']['sid']],
        'H5_CLOSE_REAL_INDEPENDENT_CHILD_GROUP')
    for action, value in [('start', start), ('stop', stop)]:
        require(load(evidence / ('http-supervisor-' + action + '.json')) == supervisor_projection(value), 'H5_CLOSE_DERIVED_FULL_PRIVATE_SUPERVISOR_PROJECTION')
    finish = read('finish-browser.json')['value']; closed = read('browser-closed.json')['value']; sql_item = read('sql-audit.json'); sql = sql_item['value']
    keys(finish, ['schema','scope',*I8,'action']); keys(closed, ['schema','scope',*I8,'closed_owned_tab_ids','viewport_reset'])
    keys(sql, ['schema','scope',*I8,'verified','business_table_count','auxiliary_table_count','private_snapshot_sha256_prefix','full_source_unchanged','scope_limit'])
    for value in [finish,closed,sql]:
        identity(value, context, cid, runtime); same_frame(value, finish)
    require(finish['scope'] == 'MS4_CUSTOMER_H5_BROWSER_FINISH' and finish['action'] == 'close-owned-tabs'
        and closed['scope'] == 'MS4_CUSTOMER_H5_BROWSER_CLOSED' and closed['viewport_reset'] is True
        and type(closed['closed_owned_tab_ids']) is list and len(closed['closed_owned_tab_ids']) == 1
        and type(closed['closed_owned_tab_ids'][0]) is str and 0 < len(closed['closed_owned_tab_ids'][0]) <= 256
        and sql['scope'] == 'MS4_CUSTOMER_H5_SQL_AUDIT' and sql['verified'] is True and sql['full_source_unchanged'] is True
        and sql['business_table_count'] == 27 and sql['auxiliary_table_count'] == 7 and sql['scope_limit'] == SCOPE_LIMIT,
        'H5_CLOSE_ORIGINAL_FINISH_CLOSED_AND_NATIVE_SQL_AUDIT')
    stages = {}; network = {}
    for stage in ['B0','B1','B2','B3','B4']:
        value = read('stage-' + stage + '.json')['value']; identity(value, context, cid, runtime)
        keys(value, ['schema','scope',*I8,'stage','network','captures','snapshot_base64','snapshot_sha256','business'])
        require(value['scope'] == 'MS4_CUSTOMER_H5_PRIVATE_STAGE' and value['stage'] == stage, 'H5_CLOSE_ORIGINAL_FIVE_STAGES')
        snapshot = bytearray(base64.b64decode(value['snapshot_base64'], validate=True))
        try:
            require(sha(snapshot) == value['snapshot_sha256'], 'H5_CLOSE_FULL_NATIVE_SERIALIZED_STAGE_HASH')
        finally:
            snapshot[:] = b'\0' * len(snapshot)
        stages[stage] = value
        for row in value['network']:
            require(row['request_id'] not in network, 'H5_CLOSE_UNIQUE_ORIGINAL_STAGE_NETWORK'); network[row['request_id']] = row['projection']
    require(stages['B4']['business']['saved_order_verified'] is True
        and stages['B4']['business']['child_stop_sha256'] == stop_item['sha256']
        and sql['private_snapshot_sha256_prefix'] == stages['B4']['snapshot_sha256'][:12], 'H5_CLOSE_FULL_B4_STOP_AND_NATIVE_SNAPSHOT_BINDING')
    captures = read('ui-captures', directory=True, maximum=33554432)
    shots = read('ui-screenshots', directory=True, maximum=33554432)
    reviews = read('ui-reviews', directory=True, maximum=33554432)
    require(set(captures) == set(shots) == set(reviews) and 27 <= len(captures) <= 9999, 'H5_CLOSE_COMPLETE_ACTUAL_UI_METADATA_CLOSED_SET')
    coverage = set(); public_shots = {}; expected_paths = set()
    for name, item in captures.items():
        capture = item['value']; shot = shots[name]['value']; review = reviews[name]['value']
        keys(capture, ['schema','scope',*I8,'stage','scene','viewport','actions','dom','network'])
        keys(shot, ['schema',*I8,'scene','path','bytes','sha256','viewport','pixels','dom'])
        keys(review, ['schema','scope',*I8,'scene','sha256','reviewed','issues','review_method'])
        for value in [capture,shot,review]:
            identity(value, context, cid, runtime); same_frame(value, capture)
            require(value['step_nonce'] + '.json' == name, 'H5_CLOSE_UI_FILENAME_FULL_NONCE')
        scene = capture['scene']; viewport = capture['viewport']; keys(viewport, ['width','height'])
        require(capture['scope'] == 'MS4_CUSTOMER_H5_UI_CAPTURE' and scene in SCENES
            and viewport['width'] in [320,390,1440] and type(viewport['height']) is int and 600 <= viewport['height'] <= 1200
            and capture['stage'] == ('B1' if scene == 'login-empty' else 'B3' if scene == 'confirm-filled' else 'B4' if scene == 'order-detail-unpaid' else 'B2'),
            'H5_CLOSE_ALL_ACTUAL_SCENES_WIDTHS')
        require(shot['scene'] == review['scene'] == scene and shot['viewport'] == viewport and shot['dom'] == capture['dom']
            and review['scope'] == 'MS4_CUSTOMER_H5_UI_VISUAL_REVIEW' and review['reviewed'] is True and review['issues'] == []
            and review['review_method'] == 'actual_image_inspection' and review['sha256'] == shot['sha256'], 'H5_CLOSE_ORIGINAL_JPEG_AND_ACTUAL_REVIEW_BINDING')
        relative = shot['path']; require(re.fullmatch('evidence/browser-screenshots/[0-9]{4}-' + re.escape(scene) + '\\.jpg', relative), 'H5_CLOSE_FINITE_PUBLIC_JPEG_PATH')
        require(relative not in expected_paths, 'H5_CLOSE_UNIQUE_PUBLIC_JPEG'); expected_paths.add(relative)
        raw = regular(Path(context['run_dir']) / relative, 16777216)
        require(len(raw) == shot['bytes'] and sha(raw) == shot['sha256'] and jpeg_pixels(raw) == shot['pixels'], 'H5_CLOSE_ACTUAL_JPEG_FULL_BYTES_AND_PIXELS')
        public_shots[relative] = {'bytes': len(raw), 'sha256': sha(raw), 'pixels': shot['pixels'], 'scene': scene, 'viewport': viewport}
        coverage.add((scene, viewport['width']))
        for row in capture['network']:
            require(network.get(row['request_id']) == row['projection'], 'H5_CLOSE_UI_NETWORK_LINKED_TO_REAL_STAGE')
    require(coverage == {(scene,width) for scene in SCENES for width in [320,390,1440]}, 'H5_CLOSE_NINE_SCENES_THREE_WIDTHS')
    parent = evidence / 'browser-screenshots'; require(parent.is_dir() and not parent.is_symlink(), 'H5_CLOSE_REAL_SCREENSHOT_DIRECTORY')
    require({path.relative_to(Path(context['run_dir'])).as_posix() for path in parent.iterdir()} == expected_paths
        and {int(PurePosixPath(path).name[:4]) for path in expected_paths} == set(range(1,len(captures)+1)), 'H5_CLOSE_PUBLIC_JPEG_EXACT_CLOSED_SEQUENCE')
    audit = {**public_identity(sql), 'scope': 'MS4_CUSTOMER_H5_HTTP_DOM_AUDIT', 'status': 'VERIFIED_NORMAL_CUSTOMER_H5_HTTP_DOM_ONLY',
        'source_binding_sha256': ready['source_binding_sha256'], 'native_created_sha256': binding['native_created_sha256'],
        'public_receipt_sha256': binding['public_copy']['sha256'], 'sql_audit_sha256_prefix': sql_item['sha256'][:12],
        'ui_capture_count': len(captures), 'screenshot_count': len(shots), 'manual_review_count': len(reviews),
        'business_table_count':27,'auxiliary_table_count':7,'full_source_unchanged':True,'browser_owned_tabs_closed':1,
        'viewport_reset':True,'scope_limit':SCOPE_LIMIT,'stopped_samples':'NOT RUN','external_payment_shipping_aftersale':'NOT RUN',
        'statistics_acceptance':'NOT RUN','again_paid_UI':'NOT RUN','public_projection_only':True}
    require(load(evidence / 'customer-h5-browser-audit.json') == audit, 'H5_CLOSE_DERIVED_ACTUAL_BROWSER_AUDIT_PROJECTION')
    forgotten = {**public_identity(runtime), 'scope':'MS4_CUSTOMER_H5_PRIVATE_FORGOTTEN',
                 'private_actors_forgotten':True,'public_projection_only':True}
    require(load(evidence / 'customer-h5-private-forgotten.json') == forgotten, 'H5_CLOSE_ORIGINAL_SAFE_FORGOTTEN_PROJECTION')
    return {'runtime':runtime,'ready':ready,'stop':stop,'public':{'scope_limit':SCOPE_LIMIT,
        'ui_capture_count':len(captures),'screenshot_count':len(shots),'manual_review_count':len(reviews),
        'screenshots':public_shots,'all_original_private_frames_bound':True,'safe_projections_recomputed':True}}


def verify_public_prerequisites(host, base, context, frozen, expected):
    evidence = Path(context['run_dir']) / 'evidence'
    host.final_h5_preconditions(base, context, frozen)
    manifest, inputs = host.final_h5_manifest(base, context, frozen)
    helper = host.bound_final_h5(base, context, frozen, 'register-customer-h5-serve')
    terminal = load(evidence / 'register-customer-h5-serve-terminal.json'); base.same_created(helper, terminal)
    require(terminal['status'] == 'exited' and terminal['pid'] == terminal['exit_code'] == terminal['restart_count'] == 0
        and helper['id'] not in expected, 'H5_CLOSE_REAL_REMOVED_REGISTER_TERMINAL')
    result = load(evidence / 'register-customer-h5-serve-result.json'); removed = load(evidence / 'register-customer-h5-serve-removed.json')
    require(result['scope'] == 'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY' and result['status'] == 'REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY'
        and result['phase'] == 'register-final' and result['native_exit'] == 0 and result['full_source_unchanged'] is True
        and result['container_id'] == removed['container_id'] == helper['id']
        and all(result[key] == removed[key] == context[key] for key in ['run_id','source_sha','freeze_sha256'])
        and result['native_created_sha256'] == sha(regular(evidence / 'register-customer-h5-serve-created.json'))
        and result['source_binding_sha256'] == sha(regular(evidence / 'register-customer-h5-serve-source-binding.json'))
        and result['business'] == {'registered_roles':sorted(expected.values()),'input_inventory_excludes_transient_helper':True,
            'manifest_sha256':sha(regular(Path(context['run_dir']) / 'manifest.json'))}
        and removed['terminal_sha256'] == sha(regular(evidence / 'register-customer-h5-serve-terminal.json'))
        and removed['result_sha256'] == sha(regular(evidence / 'register-customer-h5-serve-result.json')), 'H5_CLOSE_ORIGINAL_FINAL_REGISTRATION')
    binding = load(evidence / 'ms4-customer-h5-serve-source-binding.json')
    current, native, environment = host.final_h5_binding(base, context, frozen, 'customer-h5-serve')
    require(binding == current and load(evidence / 'customer-h5-serve-environment-native.json') == native
        and load(evidence / 'customer-h5-serve-environment.json')['environment'] == environment, 'H5_CLOSE_REAL_BOUND_SERVE_ENVIRONMENT')
    handoff = load(evidence / 'customer-h5-private-handoff.json')
    producer = host.bound_h5(base, context, frozen, 'customer-h5-prepare')
    expected_handoff = {'schema':1,'scope':'MS4_CUSTOMER_H5_PRIVATE_HANDOFF','status':'VERIFIED_PRIVATE_ACTOR_COPY_ONLY',
        **{key: binding[key] for key in P4},'bootstrap_container_id':producer['id'],
        'prepare_receipt_sha256':binding['prepare_result']['sha256'],
        'source_binding_sha256':sha(regular(evidence / 'ms4-customer-h5-prepare-source-binding.json')),
        'native_created_sha256':sha(regular(evidence / 'customer-h5-prepare-created.json')),
        'files_receipt_sha256':binding['files_result']['sha256'],'public_receipt_sha256':binding['public_copy']['sha256'],
        'bridge_source_sha256':sha(regular(PREP / 'browser-v1/ms4-customer-private-bridge.mjs')),
        'browser_source_sha256':sha(regular(PREP / 'browser-v1/ms4-customer-browser.mjs')),
        'private_actor_count':1,'private_actor_file_retained_on_host':False,'actor_values_emitted':False,
        'docker_cp_exit':0,'tar_list_exit':0,'tar_extract_exit':0,'bootstrap_native_exit':0,'bootstrap_terminal_observed':True,'HTTP_DOM_acceptance':'NOT RUN'}
    require(handoff == expected_handoff, 'H5_CLOSE_ORIGINAL_REAL_PRIVATE_HANDOFF_PUBLIC_RECEIPT')
    # Hash all finite public prerequisites retained by the normal close. No K files are included.
    names = ['ms4-customer-h5-serve-source-binding.json','register-customer-h5-serve-created.json',
        'register-customer-h5-serve-terminal.json','register-customer-h5-serve-result.json','register-customer-h5-serve-removed.json',
        'register-customer-h5-serve-source-binding.json','customer-h5-serve-environment.json','customer-h5-serve-environment-native.json',
        'http-backend-network-created.json','http-frontend-network-created.json','http-app-stop-signal.json',
        'customer-h5-additional-volume-identities.json',
        'customer-h5-prepare-result.json','customer-h5-files-result.json','customer-h5-public-copy.json',
        'customer-h5-private-handoff.json','customer-h5-private-forgotten.json','customer-h5-browser-audit.json',
        'http-source-ready.json','http-supervisor-start.json','http-supervisor-stop.json']
    for proof in ['prepare','files-bootstrap','customer-prepare',
        'register-initializer','register-driver','register-customer-prepare',
        'customer-h5-public-copy','register-customer-h5-prepare','customer-h5-prepare',
        'register-customer-h5-files','customer-h5-files']:
        for suffix in ['-created.json','-terminal.json']:
            names.append(proof + suffix)
    names += ['initializer-result.json','ms5-payment-migration-normal.json','ms5-payment-migration-source-binding.json',
        'files-bootstrap-result.json','files-bootstrap-shutdown.json','ms4-customer-prepare.json',
        'ms4-customer-prepare-source-binding.json','customer-h5-public-copy-removed.json',
        'customer-h5-prepare-removed.json','customer-h5-files-removed.json']
    names += list(base.PREREQUISITES) + list(base.FILES_PREREQUISITES) + list(base.PREPARE_PREREQUISITES)
    names += list(base.FULFILLMENT_MIGRATION_FOUR) + ['ms4-fulfillment-migration-formal-manifest.json',
        'files-bootstrap-paid-unstarted.json', 'customer-prepare-paid-unstarted.json', 'paid-producer-created.json']
    for proof in ['register-paid-producer', 'register-fulfillment-migration', 'fulfillment-migration']:
        names += [proof + suffix for suffix in ['-created.json', '-terminal.json']]
    for proof in ['register-paid-producer', 'register-fulfillment-migration']:
        names += [proof + suffix for suffix in ['-result.json', '-shutdown.json']]
    for proof in ['customer-h5-public-copy', 'register-customer-h5-prepare', 'customer-h5-prepare',
        'register-customer-h5-files', 'customer-h5-files', 'register-customer-h5-serve', 'customer-h5-serve']:
        phase = load(evidence / binding_name_for_retirement(proof))
        row = phase['canonical_paid']
        require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
            and row['path'] == '/run/audit/evidence/' + proof + '-paid-unstarted.json'
            and row == descriptor(evidence / (proof + '-paid-unstarted.json'), row['path']),
            'H5_CLOSE_EACH_REAL_PHASE_BOUND_CANONICAL_PAID_DESCRIPTOR')
        canonical_paid(base, context, load(evidence / (proof + '-paid-unstarted.json')))
        names += [proof + '-paid-unstarted.json', binding_name_for_retirement(proof)]
    names = list(dict.fromkeys(names))
    base.proof_ini_audit(context, True)
    return binding, {name:descriptor(evidence / name, 'evidence/' + name) for name in names} | {
        'manifest.json':descriptor(Path(context['run_dir']) / 'manifest.json','manifest.json')}


def binding_name_for_retirement(proof):
    if proof in ['customer-h5-prepare', 'customer-h5-serve']:
        return 'ms4-' + proof + '-source-binding.json'
    return proof + '-source-binding.json'


def live_parent_and_empty_group(context, cid, private):
    # Fixed source-bound harness definitions only. No App/config/SQL/bootstrap call.
    code = """require '/workspace/help/audit-regressions/isolated/lib/harness.php';
$i=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$parent=audit_app_proc_stat($i['parent_pid']);$members=[];$files=glob('/proc/[0-9]*/stat');
if(!is_array($files)||$files===[])exit(2);
foreach($files as$file){$row=audit_app_proc_stat((int)basename(dirname($file)));
if($row!==null&&$row['pgid']===$i['pgid'])$members[]=$row;}
echo json_encode(['parent'=>$parent,'members'=>$members,'listener_open'=>audit_app_listener_open()],JSON_THROW_ON_ERROR);
"""
    payload = json.dumps({'parent_pid':private['ready']['supervisor']['pid'],'pgid':private['stop']['group']['pgid']}).encode()
    process = subprocess.run(['docker','exec','-i',cid,'php','-r',code],input=payload,capture_output=True)
    require(process.returncode == 0 and process.stderr == b'' and 0 < len(process.stdout) <= 32768,
            'H5_CLOSE_ACTUAL_LIVE_PARENT_NUMERIC_OBSERVER_ZERO')
    facts = json.loads(process.stdout); keys(facts,['parent','members','listener_open'])
    require(type(facts['parent']) is dict and all(facts['parent'][key] == private['ready']['supervisor'][key]
        for key in ['pid','pgid','sid','starttime']) and facts['members'] == [] and facts['listener_open'] is False,
        'H5_CLOSE_SAME_LIVE_PARENT_ORIGINAL_GROUP_ACTUALLY_EMPTY')
    new(Path(context['run_dir']) / 'evidence/customer-h5-close-process-observation.json',
        {'schema':1,'scope':'MS4_CUSTOMER_H5_CLOSE_NUMERIC_PROCESS_OBSERVATION_ONLY',
         **{key:context[key] for key in ['run_id','source_sha','freeze_sha256']},'container_id':cid,
         'native_exit':process.returncode,'stdout_bytes':len(process.stdout),'stdout_sha256':sha(process.stdout),
         'stderr_bytes':0,'stderr_sha256':sha(b''),'facts':facts})


def normal_stop(host, base, context, frozen, expected, proofs, role):
    evidence = Path(context['run_dir']) / 'evidence'; cid = next(cid for cid,value in expected.items() if value == role)
    before = original_resources(host, base, context, frozen, expected, proofs)['containers'][cid]
    require(before['status'] == 'running' and before['pid'] > 0, 'H5_CLOSE_STOP_ONLY_ORIGINAL_RUNNING_CID')
    process = subprocess.Popen(['docker','stop','--time','-1',cid], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    require(process.pid > 0, 'H5_CLOSE_REAL_SINGLE_NATIVE_STOP_PID')
    STATE['waiting_native'] = {'native_pid':process.pid,'container_id':cid,'role':role}
    new(evidence / ('normal-close-' + role + '-stop-created.json'),
        {'pid':process.pid,'container_id':cid,'role':role,'time':-1,'no_timed_force':True})
    announce('waiting_same_normal_stop', role=role, native_pid=process.pid)
    stdout, stderr = process.communicate(); STATE['waiting_native'] = None
    new(evidence / ('normal-close-' + role + '-stop-terminal.json'), {'pid':process.pid,'container_id':cid,
        'native_exit':process.returncode,'stdout_bytes':len(stdout),'stdout_sha256':sha(stdout),
        'stderr_bytes':len(stderr),'stderr_sha256':sha(stderr)})
    forms = [(cid + '\n').encode(), ('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()]
    require(process.returncode == 0 and stderr == b'' and stdout in forms, 'H5_CLOSE_SAME_NATIVE_STOP_ZERO_EXACT_STDOUT')
    after = base.inspect(context, cid, 'h5-close-real-normal-terminal'); host.final_h5_owned(base, context, frozen, after, role, proofs.get(cid))
    base.same_created(before, after)
    require(after['status'] == 'exited' and after['pid'] == after['exit_code'] == after['restart_count'] == 0, 'H5_CLOSE_ACTUAL_NATIVE_NORMAL_TERMINAL_ZERO')
    new(evidence / ('normal-close-' + role + '-after-stop.json'), after)
    base.persist_terminal(context, PROOFS.get(role, 'normal-close-' + role), after)
    return after


def parent_signal(context, cid, private):
    item = memory_tar(context, cid, 'signal-stop.json'); value = item['value']; runtime = private['runtime']; stop = private['stop']
    keys(value, ['schema','scope',*I8,'normal_only','child','full_source_unchanged']); identity(value, context, cid, runtime); same_frame(value, runtime)
    child = {'stopped':True, **{key:stop[key] for key in ['group','remaining_members','listener_open','stop_signal',
        'term_attempted','native_child','native_proc_close','normal_only']}}
    require(value['scope'] == 'MS4_CUSTOMER_H5_HTTP_SIGNAL_STOP' and value['normal_only'] is True
        and value['full_source_unchanged'] is True and value['child'] == child, 'H5_CLOSE_ACTUAL_PARENT_SAME_GROUP_NORMAL_SIGNAL_STOP')
    projection = {**public_identity(value),'scope':'MS4_CUSTOMER_H5_PARENT_SIGNAL_STOP_PROJECTION',
        'normal_only':True,'child':child,'full_source_unchanged':True,'public_projection_only':True}
    new(Path(context['run_dir']) / 'evidence/customer-h5-parent-signal-stop.json', projection)


def close(context_file):
    host,base,materials,context,frozen,context_hash = source_preflight(context_file)
    evidence = Path(context['run_dir']) / 'evidence'; expected,proofs = host.final_h5_expected(base,context,frozen)
    require(len(expected) == len(FINAL_ROLES) and set(expected.values()) == set(FINAL_ROLES),
        'H5_CLOSE_EXACT_ORIGINAL_DB_CANONICAL_PAID_AND_TWO_HTTP_TARGETS')
    source_hashes = {row['path']:row['sha256'] for row in frozen['h5_artifact_files']}
    new(evidence / 'normal-close-attempt.json', {'schema':1,'scope':'CUSTOMER_H5_NORMAL_CLOSE_ONLY',
        **{key:context[key] for key in ['run_id','source_sha','freeze_sha256']},'context_sha256':context_hash,
        'close_source_sha256':source_hashes['normal-close-customer-h5.py'],'host_source_sha256':source_hashes['ms4-h5-host.py'],
        'zero_source_sha256':source_hashes['verify-zero-customer-h5.py'],'original_expected':expected,
        'stop_order':ROLES,'never_started_remove_only':[PAID_ROLE],'operations_started_at_receipt_time':False})
    announce('normal_h5_close_preflight', run_id=context['run_id'])
    host.collect_final_h5(base,context,frozen,expected,proofs,publish=False)
    binding,receipts = verify_public_prerequisites(host,base,context,frozen,expected)
    cid = binding['container_id']; private = private_normal_proof(context,cid,binding)
    live_parent_and_empty_group(context,cid,private)
    receipts['customer-h5-close-process-observation.json'] = descriptor(evidence / 'customer-h5-close-process-observation.json',
        'evidence/customer-h5-close-process-observation.json')
    host.final_h5_source(base,materials,context,frozen)
    before = original_resources(host,base,context,frozen,expected,proofs)
    require(all(row['status'] == 'running' and row['pid'] > 0 for original_id, row in before['containers'].items()
        if expected[original_id] in ROLES), 'H5_CLOSE_ALL_FOUR_ORIGINAL_RUNNING_ROLES_STILL_LIVE')
    paid_id = next(original_id for original_id, role in expected.items() if role == PAID_ROLE)
    canonical_paid(base, context, before['containers'][paid_id])
    new(evidence / 'normal-close-before.json', before | {'verified_receipts':receipts,'verified_browser':private['public']})
    stopped = {}
    for role in ROLES:
        row = normal_stop(host,base,context,frozen,expected,proofs,role); stopped[row['id']] = row
        if role == 'audit-ms4-customer-h5':
            parent_signal(context,cid,private)
            private.clear()
    resources = original_resources(host,base,context,frozen,expected,proofs)
    require(set(stopped) == {original_id for original_id, role in expected.items() if role in ROLES},
        'H5_CLOSE_ONLY_FOUR_ORIGINAL_NORMAL_STOP_TERMINALS')
    canonical = canonical_paid(base, context, resources['containers'][paid_id])
    require(base.unstarted_paid_native_equal(canonical, before['containers'][paid_id]), 'H5_CLOSE_CANONICAL_UNCHANGED_THROUGH_ALL_NORMAL_STOPS')
    new(evidence / 'normal-close-all-stopped.json', {'containers':stopped, 'never_started':{paid_id:canonical}, 'resources':resources})
    host.final_h5_source(base,materials,context,frozen)
    base.native(context,['rm',*sorted(expected)],'h5-close-exact-five-nonforce-rm')
    empty = original_resources(host,base,context,frozen,{}, {})
    require(all(value['Containers'] == {} for value in empty['networks'].values()), 'H5_CLOSE_BOTH_ORIGINAL_NETWORKS_EMPTY')
    new(evidence / 'normal-close-before-network-volumes-rm.json',empty)
    for kind in ['frontend','backend']:
        original = empty['networks'][kind]
        fresh = json.loads(base.native(context,['network','inspect','--format',host.H5_NETWORK_FULL,original['Id']], 'h5-close-original-empty-' + kind))
        require(fresh['Containers'] == {} and host.final_h5_network_identity(base,fresh) == host.final_h5_network_identity(base,original), 'H5_CLOSE_SAME_EMPTY_NETWORK_IMMEDIATELY_BEFORE_REMOVE')
        base.native(context,['network','rm',original['Id']],'h5-close-exact-' + kind + '-network-rm')
    host.final_h5_global_consumers(base,context,set())
    remaining = inventory(base,context); volumes = host.h5_volumes(base,context); names = sorted(row['name'] for row in volumes)
    require(remaining['container'] == remaining['network'] == {'project':[],'run':[]}
        and remaining['volume']['project'] == remaining['volume']['run'] == names, 'H5_CLOSE_EXACT_ELEVEN_NAMED_VOLUMES_AFTER_NETWORK_REMOVE')
    new(evidence / 'normal-close-before-eleven-volumes-rm.json',{'inventory':remaining,'volumes':volumes})
    base.native(context,['volume','rm',*names],'h5-close-exact-eleven-nonforce-volume-rm')
    final = inventory(base,context); require(all(value == [] for labels in final.values() for value in labels.values()), 'H5_CLOSE_SIX_LABEL_INVENTORIES_ZERO')
    host.final_h5_global_consumers(base,context,set()); host.final_h5_source(base,materials,context,frozen)
    base.proof_ini_audit(context, True)
    for name, row in receipts.items():
        path = Path(context['run_dir']) / row['path']
        require(descriptor(path, row['path']) == row, 'H5_CLOSE_ALL_ORIGINAL_PUBLIC_PREREQUISITE_BYTES_PRESERVED_THROUGH_RETIREMENT')
    result = {'schema':1,'scope':'CUSTOMER_H5_NORMAL_CLOSE_ONLY','status':'VERIFIED_NORMAL_CLOSE_ZERO_RESIDUAL',
        **{key:context[key] for key in ['run_id','source_sha','freeze_sha256']},'context_sha256':context_hash,
        'close_source_sha256':source_hashes['normal-close-customer-h5.py'],'host_source_sha256':source_hashes['ms4-h5-host.py'],
        'zero_source_sha256':source_hashes['verify-zero-customer-h5.py'],'native_exit':0,'final_inventory':final,
        'close_evidence':{name:descriptor(evidence / name,'evidence/' + name) for name in [
            'normal-close-attempt.json','normal-close-before.json','normal-close-all-stopped.json',
            'normal-close-before-network-volumes-rm.json','normal-close-before-eleven-volumes-rm.json',
            'customer-h5-close-process-observation.json','customer-h5-parent-signal-stop.json']},
        'exact_nonforce_removed':{'containers':len(FINAL_ROLES),'networks':2,'volumes':len(VOLUMES)},'ordinary_stop_and_exact_nonforce_remove':True,
        'browser_audit_sha256':receipts['customer-h5-browser-audit.json']['sha256'],'scope_limit':SCOPE_LIMIT,
        'customer_HTTP_DOM':'NORMAL_SOURCE_BOUND_OBSERVED_ONLY','stopped_samples':'NOT RUN',
        'external_payment_shipping_aftersale':'NOT RUN','statistics_acceptance':'NOT RUN','again_paid_UI':'NOT RUN','full_marketplace_acceptance':'INCOMPLETE'}
    new(evidence / 'normal-close-result.json',result)
    announce('normal_h5_close_zero_residual',run_id=context['run_id'],native_exit=0)
    return result


def failure(error):
    value = {'status':'INCOMPLETE_H5_NORMAL_CLOSE_STATE_PRESERVED','phase':STATE['phase'],'class':type(error).__name__,
             'native_exit':1,'no_force_prune_down_restart_or_retry':True}
    if STATE['waiting_native'] is not None:
        value['waiting_same_native_process'] = STATE['waiting_native']
    base,context = STATE['base'],STATE['context']
    if base is not None and context is not None:
        try:
            value['actual_inventory'] = inventory(base,context)
        except Exception as observation:
            value['observation_unavailable_class'] = type(observation).__name__
        try:
            new(Path(context['run_dir']) / 'evidence/normal-close-failure.json',value)
        except Exception as receipt:
            value['failure_receipt_unavailable_class'] = type(receipt).__name__
    print(json.dumps(value,ensure_ascii=False),flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--context',required=True)
    arguments = parser.parse_args()
    try:
        close(arguments.context)
    except Exception as error:
        failure(error)
        raise SystemExit(1)
