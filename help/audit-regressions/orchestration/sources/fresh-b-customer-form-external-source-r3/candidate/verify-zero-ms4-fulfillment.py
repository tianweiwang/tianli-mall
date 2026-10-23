"""SOURCE CANDIDATE. Independent aftersale/review six-query normal-close readback.

No Host/close import, App, PHP, SQL, Redis, HTTP or private material is used.
Current resources are four CIDs, one internal network and eight native volumes;
actual historical Task6/early identities and original full consumers stay separate.
"""

UI17_SOURCE_SEAL = 'UNSEALED_PENDING_JOINT_FULL17_SOURCE_QUALIFICATION'
if UI17_SOURCE_SEAL != 'SEALED_BY_ROOT_AFTER_FULL17_SOURCE_QUALIFICATION':
    raise RuntimeError('UI17_SOURCE_UNSEALED')

import argparse
import base64
import itertools
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess

ARTIFACTS = Path(__file__).resolve().parent
HOST = ARTIFACTS / 'ms4-host.py'
HOST_SHA256 = '9c2090c7313076418ef348829de4b91ac1a2f42c9e377b2625429e119b31b266'
CLOSE = ARTIFACTS / 'normal-close-ms4-fulfillment.py'
CLOSE_SHA256 = '0a35ff07b38afc010e230e009a148fd65f7931daff0ec7e8ff09f6596cbb75e4'
INTERFACE = ARTIFACTS / 'interface-v1.json'
INTERFACE_BYTES = 118487
INTERFACE_SHA256 = 'd0780d80bcd1f317d7ef13706fa90c0dccbeafa75cfb5e19163e68d48728e9d3'


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def require(value, code):
    if not value:
        raise RuntimeError(code)


def regular(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink(), 'REGULAR_BOUND_PUBLIC_SOURCE_OR_RECEIPT')
    return path.read_bytes()


def load(path):
    return json.loads(regular(path))


def new(path, value):
    require(path.parent.is_dir() and not path.exists() and not path.is_symlink(), 'CREATE_ONLY_INDEPENDENT_ZERO_RECEIPT')
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode()
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'COMPLETE_INDEPENDENT_ZERO_WRITE')
    require(regular(path) == raw, 'COMPLETE_INDEPENDENT_ZERO_READBACK')


def same_facts(left, right):
    require(type(left) is dict and type(right) is dict and set(left) == set(right), 'SAME_ALL_TERMINAL_FIELDS')
    canonical = lambda value: json.dumps(value, sort_keys=True, separators=(',', ':'))
    require(type(left['mounts']) is list and type(right['mounts']) is list
        and sorted(canonical(row) for row in left['mounts']) == sorted(canonical(row) for row in right['mounts'])
        and canonical({key: value for key, value in left.items() if key != 'mounts'})
        == canonical({key: value for key, value in right.items() if key != 'mounts'}), 'ALL_TERMINAL_FACTS_EXACT_MOUNT_MULTISET')


def normal(facts, cid, context, role):
    require(facts['id'] == cid and facts['status'] == 'exited' and type(facts['pid']) is int and facts['pid'] == 0
        and type(facts['exit_code']) is int and facts['exit_code'] == 0
        and type(facts['restart_count']) is int and facts['restart_count'] == 0
        and facts['labels']['tianli.audit.run'] == context['run_id']
        and facts['labels']['com.docker.compose.project'] == context['project']
        and facts['labels']['com.docker.compose.service'] == role, 'SAME_NORMAL_NATIVE_TERMINAL_IDENTITY')


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
SOURCE_EXTRA = ['crmeb/composer.json', 'crmeb/composer.lock', 'crmeb/public/index.php', 'crmeb/public/router.php', 'crmeb/think', 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php', 'help/audit-regressions/isolated/ms4-customer-http-normal.php', 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php']


def fixed_sources(context):
    proof_ini_zero(context)
    require(CUSTOMER_FORM_SOURCE_BINDING_STATUS == 'BOUND_FROZEN_PHP_SOURCE', 'ZERO_FROZEN_CU_FORM_PHP_SOURCE_REQUIRED')
    task2_observer_zero(context)
    require(sha(regular(HOST)) == HOST_SHA256 and sha(regular(CLOSE)) == CLOSE_SHA256, 'EXACT_REVIEWED_PAID_HOST_CLOSE_SOURCES')
    interface_raw = regular(INTERFACE)
    require(len(interface_raw) == INTERFACE_BYTES and sha(interface_raw) == INTERFACE_SHA256, 'EXACT_ADOPTED_COMPLETE_INTERFACE_SOURCE_BYTES')
    frozen_raw = regular(Path(context['run_dir']) / 'preparation-source-binding.json')
    require(sha(frozen_raw) == context['freeze_sha256'], 'EXACT_ORIGINAL_SOURCE_FREEZE')
    frozen = json.loads(frozen_raw)
    require(type(frozen['schema']) is int and frozen['schema'] == 1 and frozen['status'] == 'CLEAN_SOURCE_FROZEN_ONLY'
            and frozen['runtime_SQL_App'] == 'NOT RUN' and frozen['source_sha'] == context['source_sha']
            and frozen['requested_repo_source'] == context['repo_dir'], 'ORIGINAL_FROZEN_SOURCE_IDENTITY')
    require(frozen['customer_source_roots'] == SOURCE_ROOTS and frozen['paid_source_roots'] == SOURCE_ROOTS
            and frozen['customer_source_extra'] == sorted(SOURCE_EXTRA)
            and frozen['paid_source_extra'] == sorted(SOURCE_EXTRA + [PAID_ENTRY]), 'EXACT_CUSTOMER_NINE_AND_PAID_TEN_EXTRA')
    require([row['path'] for row in frozen['driver_source_files']] == PROFILE_DRIVER
            and [row['path'] for row in frozen['preparation_source_references']] == PROFILE_REFERENCES
            and [row['path'] for row in frozen['artifact_files']] == PROFILE_ARTIFACTS, 'EXACT_TEN_DRIVER_FOUR_REFERENCES_NINE_ARTIFACTS')
    for row in CUSTOMER_FORM_SOURCE_FILES:
        require(source_row(context['repo_dir'], row['path']) == row, 'ZERO_SEVEN_FROZEN_CU_FORM_PHP_SOURCE_BYTES')
    paths = set(SOURCE_EXTRA)
    for relative in SOURCE_ROOTS:
        folder = Path(context['repo_dir']) / relative
        require(folder.is_dir() and not folder.is_symlink(), 'ORIGINAL_COMPLETE_SOURCE_ROOT')
        for path in folder.rglob('*'):
            require(not path.is_symlink(), 'ORIGINAL_SOURCE_SET_NO_SYMLINK')
            if path.is_file():
                paths.add(path.relative_to(context['repo_dir']).as_posix())
    require(sorted(paths) == [row['path'] for row in frozen['customer_source_files']]
            and sorted(paths | {PAID_ENTRY}) == [row['path'] for row in frozen['paid_source_files']]
            and [row for row in frozen['paid_source_files'] if row['path'] != PAID_ENTRY] == frozen['customer_source_files'],
            'COMPLETE_ORIGINAL_CUSTOMER_AND_DISTINCT_PAID_SOURCE_SETS')
    require(next(row for row in frozen['paid_source_files'] if row['path'] == PAID_ENTRY)
            == {'path': PAID_ENTRY, 'bytes': PAID_ENTRY_BYTES, 'sha256': PAID_ENTRY_SHA256}, 'EXACT_ADOPTED_PAID_ENTRY_SOURCE_BYTES')
    require(frozen['fulfillment_source_roots'] == SOURCE_ROOTS and frozen['fulfillment_source_extra'] == fulfillment_extra()
        and [row['path'] for row in frozen['fulfillment_source_files']] == sorted(paths | set(fulfillment_extra()))
        and next(row for row in frozen['fulfillment_source_files'] if row['path'] == FULFILLMENT_ENTRY)
            == {'path': FULFILLMENT_ENTRY, 'bytes': FULFILLMENT_ENTRY_BYTES, 'sha256': FULFILLMENT_ENTRY_SHA256}, 'COMPLETE_FULFILLMENT_SOURCE_CLOSURE_AND_REAL_ENTRY')
    require(frozen['aftersale_review_source_roots'] == SOURCE_ROOTS
        and frozen['aftersale_review_source_extra'] == aftersale_review_extra()
        and [row['path'] for row in frozen['aftersale_review_source_files']] == aftersale_review_paths(context['repo_dir'])
        and [row for row in frozen['aftersale_review_source_files'] if row['path'] in {item['path'] for item in frozen['fulfillment_source_files']}]
            == frozen['fulfillment_source_files'], 'COMPLETE_ORIGINAL_FOUR_ROOTS_AND_EXACT_NEW_TWENTY_EIGHT_SOURCE_EXTRAS')
    require(next(row for row in frozen['aftersale_review_source_files'] if row['path'] == AFTERSALE_REVIEW_ENTRY)
        == {'path': AFTERSALE_REVIEW_ENTRY, 'bytes': AFTERSALE_REVIEW_ENTRY_BYTES, 'sha256': AFTERSALE_REVIEW_ENTRY_SHA256},
        'EXACT_ACTUAL_ADOPTED_NEW_NORMAL_COMPLETE_SOURCE_BYTES')
    for adopted in THREE_READ_SOURCE_FILES:
        require(next(row for row in frozen['fulfillment_source_files'] if row['path'] == adopted['path']) == adopted,
                'EXACT_ADOPTED_THREE_READ_HELPER_FULL_SOURCE_BYTES_THROUGH_ZERO')
    for group, root in [('driver_source_files', context['repo_dir']), ('customer_source_files', context['repo_dir']),
        ('paid_source_files', context['repo_dir']), ('fulfillment_source_files', context['repo_dir']),
        ('aftersale_review_source_files', context['repo_dir']), ('preparation_source_references', context['repo_dir']), ('artifact_files', context['prep_dir'])]:
        for row in frozen[group]:
            require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
                    and type(row['bytes']) is int and row['bytes'] >= 0
                    and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None,
                    'CANONICAL_COMPLETE_SOURCE_DESCRIPTOR')
            raw = regular(Path(root) / row['path'])
            require(len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'FULL_ORIGINAL_SOURCE_BYTES_THROUGH_ZERO')
    for args, wanted in [(['rev-parse', 'HEAD'], context['source_sha']),
                         (['status', '--porcelain=v1', '--untracked-files=all'], ''), (['ls-files', '--', PAID_ENTRY], PAID_ENTRY),
                         (['ls-files', '--', FULFILLMENT_ENTRY], FULFILLMENT_ENTRY),
                         (['ls-files', '--', AFTERSALE_REVIEW_ENTRY], AFTERSALE_REVIEW_ENTRY)] + [
                         (['ls-files', '--', path], path) for path in EARLY_SCRIPTS.values()] + [
                         (['ls-files', '--', row['path']], row['path']) for row in THREE_READ_SOURCE_FILES]:
        process = subprocess.run(['git', '-c', 'safe.directory=' + Path(context['repo_dir']).as_posix(), '-C', context['repo_dir'], *args], capture_output=True)
        require(type(process.returncode) is int and process.returncode == 0 and process.stdout.decode().rstrip('\r\n') == wanted,
                'EXACT_CLEAN_CURRENT_SOURCE_AND_TRACKED_PAID_ENTRY_THROUGH_ZERO')


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
PAID_ENTRY_BYTES = 108005
PAID_ENTRY_SHA256 = 'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7'
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
CUSTOMER_FORM_SOURCE_BINDING_STATUS = 'BOUND_FROZEN_PHP_SOURCE'
CUSTOMER_FORM_SOURCE_FILES = [{'path': 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php', 'bytes': 66236, 'sha256': '1d06fe3ed2cbe4bdc020e03849f128c3b447b7ea5365c54e59edee96762b5043'}, {'path': 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'bytes': 65119, 'sha256': '925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d'}, {'path': 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php', 'bytes': 239863, 'sha256': '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'}, {'path': 'help/audit-regressions/isolated/ms4-customer-http-normal.php', 'bytes': 115616, 'sha256': '84186f10092685ae1428f1c70533023109c2160bc672a311d89e5e97c26c8494'}, {'path': 'help/audit-regressions/isolated/ms4-fulfillment-normal.php', 'bytes': 128280, 'sha256': 'b877330ac652d1d0bc63fd72d84fb34174cac83144637f58c090c0f7c13034ce'}, {'path': 'help/audit-regressions/isolated/ms4-worker-normal.php', 'bytes': 127354, 'sha256': '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8'}, {'path': 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php', 'bytes': 108005, 'sha256': 'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7'}]
CUSTOMER_FORM_PRIVATE_ROOT = '/run/audit-customer-form'
CUSTOMER_FORM_ENV = 'PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT'

ENV_FIXED = {'APP_ENV': 'testing', 'PHP_MARKETPLACE_ENABLED': 'true',
    'PHP_MARKETPLACE_TRADE_ENABLED': 'true', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED': 'true', 'PHP_CACHE_DRIVER': 'redis'}
ENV_NAMES = ['APP_ENV', 'PHP_MARKETPLACE_ENABLED', 'PHP_MARKETPLACE_TRADE_ENABLED', 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED',
    'PHP_MARKETPLACE_SELF_OPERATED_MER_ID', 'PHP_CACHE_DRIVER']
CUSTOMER_FORM_ENV_NAMES = sorted(ENV_NAMES + [CUSTOMER_FORM_ENV])
PREREQUISITES = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json',
    'initializer-result.json', 'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
    'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']
FILES_PREREQUISITES = ['files-bootstrap-created.json', 'files-bootstrap-terminal.json',
    'files-bootstrap-result.json', 'files-bootstrap-shutdown.json']
PREPARE_PREREQUISITES = ['customer-prepare-created.json', 'customer-prepare-terminal.json', 'ms4-customer-prepare.json']

def canonical_host(value):
    value = str(value).replace('\\', '/').rstrip('/')
    match = re.fullmatch(r'(?:/run/desktop/mnt/host|/host_mnt)/([a-zA-Z])/(.*)', value)
    if match:
        value = match[1] + ':/' + match[2]
    return value.casefold()

def same_created(before, fresh):
    expect(all(key in before and key in fresh and before[key] == fresh[key] for key in IMMUTABLE_FIELDS if key != 'mounts'),
           'SAME_COMPLETE_CREATED_IMMUTABLE_FIELDS')
    expect(isinstance(before['mounts'], list) and isinstance(fresh['mounts'], list), 'NATIVE_COMPLETE_MOUNT_ARRAYS')
    expect(sorted(json.dumps(entry, sort_keys=True) for entry in before['mounts'])
           == sorted(json.dumps(entry, sort_keys=True) for entry in fresh['mounts']), 'SAME_COMPLETE_CREATED_MOUNTS')
    expect(before['restart_count'] == fresh['restart_count'] == 0 and set(before['networks']) == set(fresh['networks']),
           'SAME_CREATED_NETWORK_RESTART')

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
        expected[CUSTOMER_FORM_PRIVATE_ROOT] = ('volume', context['project'] + '_customer-form-evidence', service == AFTERSALE_REVIEW['role'])
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

def customer_form_environment(context):
    return public_environment(context) | {CUSTOMER_FORM_ENV: CUSTOMER_FORM_PRIVATE_ROOT}


def customer_form_environment_bytes(context):
    values = customer_form_environment(context)
    return ('\n'.join(json.dumps(name + '=' + values[name], ensure_ascii=False, separators=(',', ':'))
        for name in CUSTOMER_FORM_ENV_NAMES) + '\n\n').encode('utf-8')


def public_environment(context):
    return ENV_FIXED | {'PHP_MARKETPLACE_SELF_OPERATED_MER_ID': str(actual_self(context))}

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
           and actual['container_id'] == cid and actual['environment'] == (customer_form_environment(context) if mode == 'verify' else public_environment(context)), 'EXACT_MODE_ENVIRONMENT_RECEIPT')
    receipt = load(evidence / (spec['proof'] + '-environment-native.json'))
    expect(receipt['stage'] == 'customer-public-environment-inspect' and type(receipt['native_exit']) is int
           and receipt['native_exit'] == 0 and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
           and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] > 0
           and receipt['stdout_sha256'] == actual['native_projection_sha256'] and receipt['private_environment_logs_omitted'] is True
           and environment_projection_matches(context, receipt, mode == 'verify'),
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


def verify_paid_binding(context, cid, native_required=False, formal=None):
    require(native_required is False, 'PAID_ZERO_OFFLINE_BINDING_ONLY')
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


def verify_paid_result(context, native_required=False, formal=None):
    require(native_required is False, 'PAID_ZERO_OFFLINE_COMPLETE_RESULT_ONLY')
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


# Finite successor constants. Original customer9/paid10/driver10 closures remain intact.
FULFILLMENT_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-normal.php'
FULFILLMENT_ENTRY_BYTES = 128280
FULFILLMENT_ENTRY_SHA256 = 'b877330ac652d1d0bc63fd72d84fb34174cac83144637f58c090c0f7c13034ce'
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


AFTERSALE_ENV_NAMES = sorted(ENV_NAMES + ['PHP_MARKETPLACE_AFTERSALE_ENABLED', 'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT', CUSTOMER_FORM_ENV])


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


def aftersale_review_extra():
    paths = sorted(set(fulfillment_extra() + list(EARLY_SCRIPTS.values()) + [AFTERSALE_REVIEW_ENTRY]))
    expect(len(paths) == 30, 'ONLY_ORIGINAL_FULFILLMENT_WITH_ASSETS_PLUS_THREE_CLI_AND_NEW_ENTRY')
    return paths


def aftersale_review_paths(repo):
    paths = sorted(set(customer_paths(repo) + aftersale_review_extra()))
    expect(all((Path(repo) / path).is_file() and not (Path(repo) / path).is_symlink() for path in paths),
        'COMPLETE_FOUR_SOURCE_ROOTS_AND_TWENTY_EIGHT_EXTRAS')
    return paths

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


def aftersale_environment(context):
    return public_environment(context) | {'PHP_MARKETPLACE_AFTERSALE_ENABLED': 'true',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT': AFTERSALE_PRIVATE_ROOT, CUSTOMER_FORM_ENV: CUSTOMER_FORM_PRIVATE_ROOT}


def aftersale_environment_bytes(context):
    value = aftersale_environment(context)
    return ('\n'.join(json.dumps(name + '=' + value[name], ensure_ascii=False, separators=(',', ':'))
        for name in AFTERSALE_ENV_NAMES) + '\n').encode('utf-8')


def verify_aftersale_environment(context, cid, native_required=False):
    require(native_required is False, 'ZERO_OFFLINE_EIGHT_ENV_PUBLIC_RECEIPTS_ONLY')
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
    return value

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


def verify_aftersale_review_binding(context, cid, native_required=False):
    require(native_required is False, 'ZERO_OFFLINE_NEW_NORMAL_COMPLETE_PUBLIC_CONSUMER_ONLY')
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
    require(native_required is False, 'ZERO_OFFLINE_NEW_NORMAL_COMPLETE_PUBLIC_CONSUMER_ONLY')
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


def source_row(root, relative):
    path = Path(root) / relative
    expect(path.is_file() and not path.is_symlink(), 'BOUND_REGULAR_SOURCE')
    raw = path.read_bytes()
    return {'path': relative, 'bytes': len(raw), 'sha256': digest(raw)}



def fulfillment_extra():
    values = sorted(set(CUSTOMER_EXTRA + DRIVER + [PAID_ENTRY, FULFILLMENT_ENTRY, FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI, ASSET_CLI]
        + [row['path'] for row in THREE_READ_SOURCE_FILES]))
    expect(len(values) == 26, 'EXACT_FULFILLMENT_TWENTY_SIX_EXTRAS')
    return values



def fulfillment_migration_paths():
    return sorted(set(DRIVER) | {FULFILLMENT_MIGRATION_ENTRY, FULFILLMENT_CLI, ASSET_SCHEMA, *EARLY_SCRIPTS.values()})


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
    expect(native_required is False, 'INDEPENDENT_ZERO_OFFLINE_ENVIRONMENT_ONLY')
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
    require(native_required is False, 'ZERO_OFFLINE_COMPLETE_TASK6_WITH_REAL_SAVED_FORMAL_ONLY')
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


def fulfillment_public_hashes(context):
    evidence = Path(context['run_dir']) / 'evidence'
    values = {}
    for name in fulfillment_public_names():
        path = evidence / name
        expect(path.is_file() and not path.is_symlink(), 'FULFILLMENT_PUBLIC_ORIGINAL_REGULAR_FILE')
        values[name] = sha(path.read_bytes())
    return values


def historical_created_ids(context):
    evidence = Path(context['run_dir']) / 'evidence'
    historical = set()
    for name in aftersale_review_public_names():
        if not name.endswith('-created.json') or name in ['paid-producer-created.json', 'aftersale-review-normal-created.json']:
            continue
        value = load(evidence / name)
        expect(type(value) is dict and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
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
            value = bound_created(context, proof, role)
            if proof != AFTERSALE_REVIEW['proof']:
                historical.add(value['id'])
    return historical


def aftersale_review_lifecycle_receipts(context, expected):
    evidence = Path(context['run_dir']) / 'evidence'
    verify_fulfillment_history(context)
    verify_fulfillment_removed(context)
    verify_aftersale_review_result(context, False)
    verify_aftersale_review_helper(context, 'authorize-aftersale-review-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    paid = normal_terminal(context, PAID['proof'], PAID['role'], False)
    newnormal = normal_terminal(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'], False)
    expect(set(expected) == {paid['id'], newnormal['id'], *context['initial_service_ids'].values()}, 'EXACT_FOUR_CURRENT_AFTERSALE_REVIEW_CIDS')
    historical = historical_created_ids(context)
    expect(not historical.intersection(expected), 'HISTORICAL_CIDS_DISTINCT_FROM_FOUR_CURRENT_IDS')
    values = aftersale_review_public_hashes(context)
    formal = load(evidence / 'paid-producer-formal-manifest.json')
    return {'paid_prerequisites_sha256': paid_prerequisite_hashes(context, formal),
        'fulfillment_prerequisites_sha256': fulfillment_prerequisite_hashes(context), 'paid': paid, 'aftersale_review': newnormal,
        'paid_result_sha256': values['paid-producer-result.json'], 'fulfillment_result_sha256': values['ms4-fulfillment-normal.json'],
        'aftersale_review_result_sha256': values[AFTERSALE_REVIEW['result']],
        'authorize_created_sha256': values['authorize-aftersale-review-cleanup-created.json'],
        'authorize_terminal_sha256': values['authorize-aftersale-review-cleanup-terminal.json'],
        'authorize_result_sha256': values['authorize-aftersale-review-cleanup-result.json'], 'public_originals_sha256': values,
        'historical_absent_container_ids': sorted(historical),
        'manifest_sha256': sha((Path(context['run_dir']) / 'manifest.json').read_bytes())}


def global_no_original_consumers(context):
    allowed = set()
    operations = []
    def query(args):
        result = subprocess.run(['docker', *args], capture_output=True)
        operations.append({'argv': args, 'native_exit': result.returncode, 'stdout_bytes': len(result.stdout),
            'stdout_sha256': sha(result.stdout), 'stderr_bytes': len(result.stderr), 'stderr_sha256': sha(result.stderr)})
        require(type(result.returncode) is int and result.returncode == 0 and result.stderr == b'',
                      'PAID_ZERO_INDEPENDENT_GLOBAL_NATIVE_ZERO')
        return result.stdout
    ids = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    require(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in ids),
                  'PAID_ZERO_GLOBAL_COMPLETE_ACTUAL_CIDS')
    network_id = context['network_id']
    require(type(network_id) is str and re.fullmatch('[a-f0-9]{64}', network_id)
                  and context['initial_network']['Id'] == network_id, 'PAID_ZERO_ORIGINAL_FULL_NETWORK_ID')
    network_names = {network_id, context['initial_network']['Name']}
    volumes = {row['name'] for row in context['initial_volumes']}
    original = bound_created(context, 'paid-producer', 'audit-ms5-paid-producer')
    newnormal = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
    targets = set(context['initial_service_ids'].values()) | {original['id'], newnormal['id']}
    historical = historical_created_ids(context)
    require(not historical.intersection(targets) and not (targets | historical).intersection(ids), 'ZERO_ALL_CURRENT_AND_HISTORICAL_CIDS_ACTUALLY_ABSENT')
    require(len(targets) == 4 and len(volumes) == 8 and set(allowed) <= targets,
                  'AFTERSALE_REVIEW_ZERO_CURRENT_FOUR_CID_EIGHT_VOLUME_TARGETS')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    permitted = []
    for cid in ids:
        value = json.loads(query(['inspect', '--format', template, cid]))
        require(type(value) is dict and set(value) == {'id', 'mounts', 'network_mode', 'networks'}
                      and value['id'] == cid and type(value['mounts']) is list
                      and all(type(row) is dict for row in value['mounts'])
                      and type(value['networks']) is dict and all(type(row) is dict for row in value['networks'].values())
                      and type(value['network_mode']) is str, 'PAID_ZERO_COMPLETE_GLOBAL_NATIVE_FIELDS')
        mode = value['network_mode']
        consumes = (any(row.get('Name') in volumes for row in value['mounts'])
            or mode in network_names or bool(set(value['networks']).intersection(network_names))
            or any(row.get('NetworkID') == network_id for row in value['networks'].values())
            or (mode.startswith('container:') and mode[len('container:'):] in targets | historical))
        if consumes:
            require(cid in allowed, 'PAID_ZERO_NO_UNKNOWN_ORIGINAL_RESOURCE_DEPENDENCY')
            permitted.append(cid)
    final = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    require(len(final) == len(set(final)) and set(final) == set(ids), 'PAID_ZERO_SAME_GLOBAL_CID_SNAPSHOT')
    return {'inspected_container_count': len(ids), 'operations': operations,
            'original_container_ids': sorted(targets), 'allowed_original_consumers': sorted(permitted),
            'unexpected_original_resource_consumers': 0}


def paid_inventory(value, context, containers, network_present=True, volumes_present=True):
    require(type(value) is dict and set(value) == {'container', 'network', 'volume'}, 'PAID_CLOSE_ARCHIVED_THREE_CLASSES')
    expected = {'container': set(containers), 'network': {context['network_id']} if network_present else set(),
                'volume': {row['name'] for row in context['initial_volumes']} if volumes_present else set()}
    for kind in expected:
        require(type(value[kind]) is dict and set(value[kind]) == {'project', 'run'}, 'PAID_CLOSE_TWO_LABEL_ARCHIVE')
        for rows in value[kind].values():
            require(type(rows) is list and len(rows) == len(set(rows)) and set(rows) == expected[kind],
                    'PAID_CLOSE_ARCHIVED_EXACT_ORIGINAL_RESOURCES')


def paid_global_archive(value, expected, allowed):
    require(type(value) is dict and set(value) == {'inspected_container_count', 'operations', 'original_container_ids',
            'allowed_original_consumers', 'unexpected_original_resource_consumers'}
            and type(value['inspected_container_count']) is int and value['inspected_container_count'] >= 0
            and value['original_container_ids'] == sorted(expected)
            and type(value['allowed_original_consumers']) is list
            and len(value['allowed_original_consumers']) == len(set(value['allowed_original_consumers']))
            and set(value['allowed_original_consumers']) <= set(allowed)
            and type(value['unexpected_original_resource_consumers']) is int and value['unexpected_original_resource_consumers'] == 0,
            'PAID_CLOSE_COMPLETE_GLOBAL_CONSUMER_ARCHIVE')
    operations = value['operations']
    require(type(operations) is list and len(operations) == value['inspected_container_count'] + 2,
            'PAID_CLOSE_ALL_GLOBAL_NATIVE_OPERATIONS')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    seen = []
    for index, row in enumerate(operations):
        require(type(row) is dict and set(row) == {'argv', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256'}
                and type(row['native_exit']) is int and row['native_exit'] == 0
                and type(row['stdout_bytes']) is int and row['stdout_bytes'] >= 0 and paid_hash(row['stdout_sha256'])
                and type(row['stderr_bytes']) is int and row['stderr_bytes'] == 0 and row['stderr_sha256'] == sha(b''),
                'PAID_CLOSE_COMPLETE_NATIVE_GLOBAL_HASHES')
        if index in [0, len(operations) - 1]:
            require(row['argv'] == ['ps', '-aq', '--no-trunc'], 'PAID_CLOSE_GLOBAL_ENUMERATION_BOUNDARIES')
        else:
            require(type(row['argv']) is list and len(row['argv']) == 4
                    and row['argv'][:3] == ['inspect', '--format', template]
                    and type(row['argv'][3]) is str and re.fullmatch('[a-f0-9]{64}', row['argv'][3])
                    and row['stdout_bytes'] > 0, 'PAID_CLOSE_EXACT_GLOBAL_INSPECT')
            seen.append(row['argv'][3])
    require(len(seen) == len(set(seen)) == value['inspected_container_count'], 'PAID_CLOSE_COMPLETE_UNIQUE_GLOBAL_INSPECTS')


def paid_resource_archive(value, context, expected, empty_endpoints=False):
    require(type(value) is dict and set(value) == {'inventory', 'network', 'volumes', 'global_consumers'},
            'PAID_CLOSE_RESOURCE_ARCHIVE_KEYS')
    paid_inventory(value['inventory'], context, expected)
    network = value['network']
    require(type(network) is dict and set(network) == {'Id', 'Name', 'Internal', 'Labels', 'Containers'}
            and paid_equal({key: network[key] for key in ['Id', 'Name', 'Internal', 'Labels']}, context['initial_network'])
            and network['Internal'] is True and type(network['Containers']) is dict
            and set(network['Containers']) <= set(expected), 'PAID_CLOSE_ORIGINAL_NETWORK_ARCHIVE')
    if empty_endpoints:
        require(network['Containers'] == {}, 'PAID_CLOSE_REMOVED_CIDS_LEFT_NO_ENDPOINTS')
    require(paid_equal(value['volumes'], context['initial_volumes']) and len(value['volumes']) == 8,
            'PAID_CLOSE_ORIGINAL_EIGHT_VOLUME_IDENTITIES')
    paid_global_archive(value['global_consumers'], expected=original_paid_ids(context), allowed=set(expected))


def original_paid_ids(context):
    created = bound_created(context, PAID['proof'], PAID['role'])
    require(set(context['initial_service_ids']) == {'audit-mysql', 'audit-redis'}
            and len(set(context['initial_service_ids'].values())) == 2, 'PAID_ORIGINAL_TWO_DATABASE_CIDS')
    newnormal = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
    targets = set(context['initial_service_ids'].values()) | {created['id'], newnormal['id']}
    require(len(targets) == 4 and all(type(cid) is str and re.fullmatch('[a-f0-9]{64}', cid) for cid in targets),
            'AFTERSALE_REVIEW_CURRENT_FOUR_DISTINCT_CONTAINER_IDS')
    return targets


def main(context_file, close_result_sha256):
    require(type(close_result_sha256) is str and re.fullmatch('[a-f0-9]{64}', close_result_sha256) is not None,
            'ROOT_ACTUAL_NATIVE_ZERO_PAID_CLOSE_RESULT_SHA_REQUIRED')
    context_raw = regular(context_file); context = json.loads(context_raw)
    root = Path(context['run_dir']); evidence = root / 'evidence'
    require(Path(context_file).resolve() == (root / 'actual-run-context.json').resolve()
            and re.fullmatch('[0-9]{14}[a-f0-9]{6}', context['run_id']) is not None
            and context['project'] == 'tianli-audit-' + context['run_id'] and context['prep_dir'] == ARTIFACTS.as_posix(),
            'SINGLE_ACTUAL_PAID_CONTEXT')
    require(sorted(row['name'] for row in context['initial_volumes']) == sorted(context['project'] + '_' + suffix
        for suffix in ['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets', 'aftersale-evidence', 'customer-form-evidence']),
        'ZERO_EXACT_ORIGINAL_EIGHT_NATIVE_VOLUME_NAMES')
    fixed_sources(context)
    result_raw = regular(evidence / 'ms4-aftersale-review-normal-close-result.json'); result = json.loads(result_raw)
    require(sha(result_raw) == close_result_sha256 and type(result) is dict
            and set(result) == {'status', 'run_id', 'source_sha', 'close_source_sha256', 'native_exit', 'final_inventory',
                'global_consumers', 'schema', 'scope', 'freeze_sha256', 'context_sha256', 'reviewed_host_sha256',
                'exact_nonforce_removed', 'ordinary_stop_and_exact_nonforce_remove', 'paid_producer',
                'customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'fulfillment_writer', 'customer_fulfillment_API_DOM',
                'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM', 'worker', 'historical_task6_task7', 'full_acceptance', 'stopped_samples', 'aftersale_review'}
            and type(result['schema']) is int and result['schema'] == 1
            and result['scope'] == 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ONLY'
            and result['status'] == 'VERIFIED_MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ZERO_RESIDUAL'
            and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and result['context_sha256'] == sha(context_raw) and result['close_source_sha256'] == CLOSE_SHA256
            and result['reviewed_host_sha256'] == HOST_SHA256 and type(result['native_exit']) is int and result['native_exit'] == 0
            and paid_equal(result['exact_nonforce_removed'], {'containers': 4, 'networks': 1, 'volumes': 8})
            and result['ordinary_stop_and_exact_nonforce_remove'] is True
            and result['paid_producer'] == 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND'
            and result['fulfillment_writer'] == 'INTERNAL_ONLY_SOURCE_BOUND'
            and result['aftersale_review'] == AFTERSALE_REVIEW['status']
            and all(result[key] == 'NOT RUN' for key in ['customer_fulfillment_API_DOM', 'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM', 'worker', 'historical_task6_task7'])
            and all(result[key] == 'NOT RUN' for key in ['customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'stopped_samples'])
            and result['full_acceptance'] == 'INCOMPLETE'
            and paid_equal(result['final_inventory'], {kind: {'project': [], 'run': []} for kind in ['container', 'network', 'volume']}),
            'ACTUAL_PAID_CLOSE_ZERO_RESULT')
    paid_created = bound_created(context, PAID['proof'], PAID['role'])
    newnormal_created = bound_created(context, AFTERSALE_REVIEW['proof'], AFTERSALE_REVIEW['role'])
    expected = {paid_created['id']: PAID['role'], newnormal_created['id']: AFTERSALE_REVIEW['role'], **{cid: role for role, cid in context['initial_service_ids'].items()}}
    require(set(expected) == original_paid_ids(context), 'ACTUAL_EXACT_ORIGINAL_THREE_PAID_ROLES')
    attempt = load(evidence / 'ms4-aftersale-review-normal-close-attempt.json')
    require(set(attempt) == {'scope', 'run_id', 'source_sha', 'close_source_path', 'close_source_bytes', 'close_source_sha256',
                'reviewed_host_sha256', 'context_sha256', 'freeze_sha256', 'original_expected', 'operations_started_at_receipt_time'}
            and attempt['original_expected'] == expected and attempt['scope'] == 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_ONLY'
            and all(attempt[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and attempt['reviewed_host_sha256'] == HOST_SHA256 and attempt['close_source_sha256'] == CLOSE_SHA256
            and attempt['close_source_path'] == CLOSE.resolve().as_posix()
            and type(attempt['close_source_bytes']) is int and attempt['close_source_bytes'] == len(regular(CLOSE))
            and attempt['context_sha256'] == sha(context_raw) and attempt['operations_started_at_receipt_time'] is False,
            'ACTUAL_EXACT_PAID_CLOSE_ATTEMPT')
    independent_receipts = aftersale_review_lifecycle_receipts(context, expected)
    stopped = load(evidence / 'ms4-aftersale-review-normal-close-all-stopped.json')
    require(set(stopped) == {'containers', 'resources'} and set(stopped['containers']) == set(expected),
            'EXACT_THREE_ARCHIVED_NORMAL_PAID_TERMINALS')
    same_facts(stopped['containers'][paid_created['id']], load(evidence / 'paid-producer-terminal.json'))
    same_facts(independent_receipts['paid'], stopped['containers'][paid_created['id']])
    same_facts(stopped['containers'][newnormal_created['id']], load(evidence / 'aftersale-review-normal-terminal.json'))
    same_facts(independent_receipts['aftersale_review'], stopped['containers'][newnormal_created['id']])
    for cid, role in expected.items():
        normal(stopped['containers'][cid], cid, context, role)
        before = paid_created if role == PAID['role'] else newnormal_created if role == AFTERSALE_REVIEW['role'] else context['initial_service_created'][role]
        same_created(before, stopped['containers'][cid])
        if role in [PAID['role'], AFTERSALE_REVIEW['role']]:
            continue
        created = load(evidence / ('ms4-aftersale-review-normal-close-' + role + '-stop-created.json'))
        native = load(evidence / ('ms4-aftersale-review-normal-close-' + role + '-stop-terminal.json'))
        after = load(evidence / ('ms4-aftersale-review-normal-close-' + role + '-after-stop.json'))
        same_facts(stopped['containers'][cid], after)
        same_facts(after, load(evidence / ('ms4-aftersale-review-normal-close-' + role + '-terminal.json')))
        stdout_forms = [(cid + '\n').encode(), ('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()]
        require(set(created) == {'pid', 'container_id', 'role', 'time', 'no_timed_force'}
                and set(native) == {'pid', 'container_id', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'wait_returncode'}
                and type(created['pid']) is int and created['pid'] > 0 and type(native['pid']) is int and native['pid'] == created['pid']
                and created['container_id'] == native['container_id'] == cid and created['role'] == role
                and type(created['time']) is int and created['time'] == -1 and created['no_timed_force'] is True
                and type(native['native_exit']) is int and native['native_exit'] == 0
                and type(native['wait_returncode']) is int and native['wait_returncode'] == 0
                and type(native['stderr_bytes']) is int and native['stderr_bytes'] == 0 and native['stderr_sha256'] == sha(b'')
                and type(native['stdout_bytes']) is int
                and any(native['stdout_bytes'] == len(raw) and native['stdout_sha256'] == sha(raw) for raw in stdout_forms),
                'ACTUAL_SAME_DB_STOP_PID_NATIVE_ZERO_EXACT_STDOUT')
    before = load(evidence / 'ms4-aftersale-review-normal-close-before.json')
    require(set(before) == {'inventory', 'network', 'volumes', 'global_consumers', 'verified_receipts'}
            and type(before['verified_receipts']) is dict
            and set(before['verified_receipts']) == set(independent_receipts)
            and paid_equal({key: value for key, value in before['verified_receipts'].items() if key not in ['paid', 'aftersale_review']},
                           {key: value for key, value in independent_receipts.items() if key not in ['paid', 'aftersale_review']}),
            'ALL_INDEPENDENT_PAID_PUBLIC_RECEIPTS_RETAINED')
    same_facts(before['verified_receipts']['paid'], independent_receipts['paid'])
    same_facts(before['verified_receipts']['aftersale_review'], independent_receipts['aftersale_review'])
    paid_resource_archive({key: value for key, value in before.items() if key != 'verified_receipts'}, context, expected)
    paid_resource_archive(stopped['resources'], context, expected)
    paid_resource_archive(load(evidence / 'ms4-aftersale-review-normal-close-before-network-volumes-rm.json'), context, set(), True)
    volumes = load(evidence / 'ms4-aftersale-review-normal-close-before-eight-volumes-rm.json')
    require(set(volumes) == {'inventory', 'volumes', 'global_consumers'}
            and paid_equal(volumes['volumes'], context['initial_volumes']), 'FINAL_ORIGINAL_EIGHT_VOLUMES_ARCHIVE')
    paid_inventory(volumes['inventory'], context, set(), False, True)
    paid_global_archive(volumes['global_consumers'], expected, set())
    paid_global_archive(result['global_consumers'], expected, set())
    global_before = global_no_original_consumers(context)
    queries = []
    for kind, argv in [('container', ['ps', '-aq', '--no-trunc']), ('network', ['network', 'ls', '-q']), ('volume', ['volume', 'ls', '-q'])]:
        for label, value in [('project', 'com.docker.compose.project=' + context['project']), ('run', 'tianli.audit.run=' + context['run_id'])]:
            process = subprocess.run(['docker', *argv, '--filter', 'label=' + value], capture_output=True)
            row = {'kind': kind, 'label': label, 'native_exit': process.returncode, 'stdout_bytes': len(process.stdout),
                'stdout_sha256': sha(process.stdout), 'stderr_bytes': len(process.stderr), 'stderr_sha256': sha(process.stderr)}
            queries.append(row)
            require(type(process.returncode) is int and process.returncode == 0 and process.stdout == b'' and process.stderr == b'',
                    'INDEPENDENT_PAID_QUERY_NATIVE_ZERO_EMPTY_RAW_STREAMS')
    require(len(queries) == 6, 'EXACT_SIX_INDEPENDENT_PAID_ZERO_QUERIES')
    global_after = global_no_original_consumers(context)
    fixed_sources(context)
    require(paid_equal(aftersale_review_lifecycle_receipts(context, expected), independent_receipts),
            'ALL_ORIGINAL_PAID_PREDECESSORS_AND_RESULTS_UNCHANGED_THROUGH_ZERO')
    output = {'schema': 1, 'scope': 'MS4_AFTERSALE_REVIEW_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY',
        'status': 'VERIFIED_MS4_AFTERSALE_REVIEW_INDEPENDENT_SIX_QUERY_ZERO',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': sha(context_raw), 'close_result_sha256': close_result_sha256,
        'close_source_sha256': CLOSE_SHA256, 'host_source_sha256': HOST_SHA256,
        'zero_source_sha256': sha(regular(Path(__file__))), 'queries': queries,
        'global_consumers': {'before': global_before, 'after': global_after}, 'native_exit': 0,
        'paid_producer': 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND',
        'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
        'fulfillment_writer': 'INTERNAL_ONLY_SOURCE_BOUND', 'customer_fulfillment_API_DOM': 'NOT RUN',
        'merchant_fulfillment_API_DOM': 'NOT RUN', 'platform_fulfillment_API_DOM': 'NOT RUN', 'worker': 'NOT RUN', 'historical_task6_task7': 'NOT RUN',
        'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    new(evidence / 'ms4-aftersale-review-independent-zero.json', output)
    print(json.dumps(output, ensure_ascii=False))



WORKER_ENTRY = 'help/audit-regressions/isolated/ms4-worker-normal.php'
WORKER_ENTRY_BYTES = 127354
WORKER_ENTRY_SHA256 = '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8'
WORKER = {'proof': 'worker-normal', 'role': 'audit-ms4-worker-normal',
    'binding': 'ms4-worker-normal-source-binding.json', 'scope': 'MS4_WORKER_NORMAL_SOURCE',
    'result': 'ms4-worker-normal.json', 'result_scope': 'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
    'status': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'environment_scope': 'MS4_WORKER_NORMAL_REAL_ENVIRONMENT'}


def worker_extra():
    paths = aftersale_review_extra() + [WORKER_ENTRY]
    require(len(paths) == len(set(paths)) == 31, 'WORKER_EXACT_ORIGINAL_THIRTY_PLUS_ONE_SOURCE_EXTRA')
    return sorted(paths)


def worker_fixed_sources(context):
    fixed_sources(context)
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    rows = frozen['worker_source_files']
    require(frozen['worker_source_roots'] == SOURCE_ROOTS and frozen['worker_source_extra'] == worker_extra()
        and type(rows) is list and [row['path'] for row in rows] == sorted(set(aftersale_review_paths(context['repo_dir'])) | {WORKER_ENTRY})
        and [row for row in rows if row['path'] != WORKER_ENTRY] == frozen['aftersale_review_source_files'],
        'WORKER_COMPLETE_ACTUAL_FOUR_ROOTS_THIRTY_EXTRAS_AND_ORIGINAL_SUBSET')
    require(next(row for row in rows if row['path'] == WORKER_ENTRY)
        == {'path': WORKER_ENTRY, 'bytes': WORKER_ENTRY_BYTES, 'sha256': WORKER_ENTRY_SHA256}, 'WORKER_ACTUAL_FIXED_ENTRY_SOURCE_BYTES')
    for row in rows:
        require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and type(row['path']) is str
            and type(row['bytes']) is int and row['bytes'] >= 0 and paid_hash(row['sha256']), 'WORKER_COMPLETE_SOURCE_DESCRIPTOR')
        require(source_row(context['repo_dir'], row['path']) == row, 'WORKER_ALL_ACTUAL_SOURCE_BYTES_THROUGH_ZERO')
    process = subprocess.run(['git', '-c', 'safe.directory=' + Path(context['repo_dir']).as_posix(), '-C', context['repo_dir'],
        'ls-files', '--', WORKER_ENTRY], capture_output=True)
    require(type(process.returncode) is int and process.returncode == 0 and process.stdout.decode().rstrip('\r\n') == WORKER_ENTRY
        and process.stderr == b'', 'WORKER_ACTUAL_TRACKED_FULL_ENTRY_THROUGH_ZERO')


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


def verify_worker_environment(context, cid, native_required=False):
    require(native_required is False, 'INDEPENDENT_ZERO_COMPLETE_WORKER_PUBLIC_CONSUMER_ONLY')
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


def verify_worker_binding(context, cid, native_required=False):
    require(native_required is False, 'INDEPENDENT_ZERO_COMPLETE_WORKER_PUBLIC_CONSUMER_ONLY')
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
    require(native_required is False, 'INDEPENDENT_ZERO_COMPLETE_WORKER_PUBLIC_CONSUMER_ONLY')
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


def worker_historical_created_ids(context):
    evidence = Path(context['run_dir']) / 'evidence'
    historical = set()
    for name in aftersale_review_public_names():
        if not name.endswith('-created.json') or name == 'paid-producer-created.json':
            continue
        value = load(evidence / name)
        expect(type(value) is dict and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
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
            value = bound_created(context, proof, role)
            historical.add(value['id'])
    return historical


def worker_global_no_original_consumers(context):
    allowed = set()
    operations = []
    def query(args):
        result = subprocess.run(['docker', *args], capture_output=True)
        operations.append({'argv': args, 'native_exit': result.returncode, 'stdout_bytes': len(result.stdout),
            'stdout_sha256': sha(result.stdout), 'stderr_bytes': len(result.stderr), 'stderr_sha256': sha(result.stderr)})
        require(type(result.returncode) is int and result.returncode == 0 and result.stderr == b'',
                      'PAID_ZERO_INDEPENDENT_GLOBAL_NATIVE_ZERO')
        return result.stdout
    ids = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    require(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', cid) for cid in ids),
                  'PAID_ZERO_GLOBAL_COMPLETE_ACTUAL_CIDS')
    network_id = context['network_id']
    require(type(network_id) is str and re.fullmatch('[a-f0-9]{64}', network_id)
                  and context['initial_network']['Id'] == network_id, 'PAID_ZERO_ORIGINAL_FULL_NETWORK_ID')
    network_names = {network_id, context['initial_network']['Name']}
    volumes = {row['name'] for row in context['initial_volumes']}
    original = bound_created(context, 'paid-producer', 'audit-ms5-paid-producer')
    newnormal = bound_created(context, WORKER['proof'], WORKER['role'])
    targets = set(context['initial_service_ids'].values()) | {original['id'], newnormal['id']}
    historical = worker_historical_created_ids(context)
    require(not historical.intersection(targets) and not (targets | historical).intersection(ids), 'ZERO_ALL_CURRENT_AND_HISTORICAL_CIDS_ACTUALLY_ABSENT')
    require(len(targets) == 4 and len(volumes) == 8 and set(allowed) <= targets,
                  'WORKER_ZERO_CURRENT_FOUR_CID_EIGHT_VOLUME_TARGETS')
    template = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
    permitted = []
    for cid in ids:
        value = json.loads(query(['inspect', '--format', template, cid]))
        require(type(value) is dict and set(value) == {'id', 'mounts', 'network_mode', 'networks'}
                      and value['id'] == cid and type(value['mounts']) is list
                      and all(type(row) is dict for row in value['mounts'])
                      and type(value['networks']) is dict and all(type(row) is dict for row in value['networks'].values())
                      and type(value['network_mode']) is str, 'PAID_ZERO_COMPLETE_GLOBAL_NATIVE_FIELDS')
        mode = value['network_mode']
        consumes = (any(row.get('Name') in volumes for row in value['mounts'])
            or mode in network_names or bool(set(value['networks']).intersection(network_names))
            or any(row.get('NetworkID') == network_id for row in value['networks'].values())
            or (mode.startswith('container:') and mode[len('container:'):] in targets | historical))
        if consumes:
            require(cid in allowed, 'PAID_ZERO_NO_UNKNOWN_ORIGINAL_RESOURCE_DEPENDENCY')
            permitted.append(cid)
    final = query(['ps', '-aq', '--no-trunc']).decode().splitlines()
    require(len(final) == len(set(final)) and set(final) == set(ids), 'PAID_ZERO_SAME_GLOBAL_CID_SNAPSHOT')
    return {'inspected_container_count': len(ids), 'operations': operations,
            'original_container_ids': sorted(targets), 'allowed_original_consumers': sorted(permitted),
            'unexpected_original_resource_consumers': 0}


def worker_resource_archive(value, context, expected, empty_endpoints=False):
    require(type(value) is dict and set(value) == {'inventory', 'network', 'volumes', 'global_consumers'},
            'PAID_CLOSE_RESOURCE_ARCHIVE_KEYS')
    paid_inventory(value['inventory'], context, expected)
    network = value['network']
    require(type(network) is dict and set(network) == {'Id', 'Name', 'Internal', 'Labels', 'Containers'}
            and paid_equal({key: network[key] for key in ['Id', 'Name', 'Internal', 'Labels']}, context['initial_network'])
            and network['Internal'] is True and type(network['Containers']) is dict
            and set(network['Containers']) <= set(expected), 'PAID_CLOSE_ORIGINAL_NETWORK_ARCHIVE')
    if empty_endpoints:
        require(network['Containers'] == {}, 'PAID_CLOSE_REMOVED_CIDS_LEFT_NO_ENDPOINTS')
    require(paid_equal(value['volumes'], context['initial_volumes']) and len(value['volumes']) == 8,
            'PAID_CLOSE_ORIGINAL_EIGHT_VOLUME_IDENTITIES')
    paid_global_archive(value['global_consumers'], expected=worker_original_ids(context), allowed=set(expected))


def worker_original_ids(context):
    created = bound_created(context, PAID['proof'], PAID['role'])
    require(set(context['initial_service_ids']) == {'audit-mysql', 'audit-redis'}
            and len(set(context['initial_service_ids'].values())) == 2, 'PAID_ORIGINAL_TWO_DATABASE_CIDS')
    newnormal = bound_created(context, WORKER['proof'], WORKER['role'])
    targets = set(context['initial_service_ids'].values()) | {created['id'], newnormal['id']}
    require(len(targets) == 4 and all(type(cid) is str and re.fullmatch('[a-f0-9]{64}', cid) for cid in targets),
            'WORKER_CURRENT_FOUR_DISTINCT_CONTAINER_IDS')
    return targets


def worker_lifecycle_receipts(context, expected):
    evidence = Path(context['run_dir']) / 'evidence'
    verify_fulfillment_history(context)
    verify_fulfillment_removed(context)
    verify_aftersale_review_history(context)
    verify_aftersale_review_removed(context)
    verify_worker_result(context, False)
    verify_worker_helper(context, 'authorize-worker-cleanup', 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY')
    paid = normal_terminal(context, PAID['proof'], PAID['role'], False)
    worker = normal_terminal(context, WORKER['proof'], WORKER['role'], False)
    expect(set(expected) == {paid['id'], worker['id'], *context['initial_service_ids'].values()}, 'EXACT_FOUR_CURRENT_WORKER_CIDS')
    historical = worker_historical_created_ids(context)
    expect(not historical.intersection(expected),
        'ALL_HISTORICAL_NORMAL_EARLY_TASK6_AND_HELPER_CIDS_ACTUALLY_ABSENT')
    values = worker_public_hashes(context)
    formal = load(evidence / 'paid-producer-formal-manifest.json')
    return {'paid_prerequisites_sha256': paid_prerequisite_hashes(context, formal),
        'fulfillment_prerequisites_sha256': fulfillment_prerequisite_hashes(context),
        'worker_prerequisites_sha256': worker_prerequisite_hashes(context), 'paid': paid, 'worker': worker,
        'paid_result_sha256': values['paid-producer-result.json'], 'fulfillment_result_sha256': values['ms4-fulfillment-normal.json'],
        'aftersale_review_result_sha256': values[AFTERSALE_REVIEW['result']], 'worker_result_sha256': values[WORKER['result']],
        'authorize_created_sha256': values['authorize-worker-cleanup-created.json'],
        'authorize_terminal_sha256': values['authorize-worker-cleanup-terminal.json'],
        'authorize_result_sha256': values['authorize-worker-cleanup-result.json'], 'public_originals_sha256': values,
        'historical_absent_container_ids': sorted(historical),
        'manifest_sha256': sha((Path(context['run_dir']) / 'manifest.json').read_bytes())}


def worker_main(context_file, close_result_sha256):
    require(type(close_result_sha256) is str and re.fullmatch('[a-f0-9]{64}', close_result_sha256) is not None,
            'ROOT_ACTUAL_NATIVE_ZERO_PAID_CLOSE_RESULT_SHA_REQUIRED')
    context_raw = regular(context_file); context = json.loads(context_raw)
    root = Path(context['run_dir']); evidence = root / 'evidence'
    require(Path(context_file).resolve() == (root / 'actual-run-context.json').resolve()
            and re.fullmatch('[0-9]{14}[a-f0-9]{6}', context['run_id']) is not None
            and context['project'] == 'tianli-audit-' + context['run_id'] and context['prep_dir'] == ARTIFACTS.as_posix(),
            'SINGLE_ACTUAL_PAID_CONTEXT')
    require(sorted(row['name'] for row in context['initial_volumes']) == sorted(context['project'] + '_' + suffix
        for suffix in ['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets', 'aftersale-evidence', 'customer-form-evidence']),
        'ZERO_EXACT_ORIGINAL_EIGHT_NATIVE_VOLUME_NAMES')
    worker_fixed_sources(context)
    result_raw = regular(evidence / 'ms4-worker-normal-close-result.json'); result = json.loads(result_raw)
    require(sha(result_raw) == close_result_sha256 and type(result) is dict
            and set(result) == {'status', 'run_id', 'source_sha', 'close_source_sha256', 'native_exit', 'final_inventory',
                'global_consumers', 'schema', 'scope', 'freeze_sha256', 'context_sha256', 'reviewed_host_sha256',
                'exact_nonforce_removed', 'ordinary_stop_and_exact_nonforce_remove', 'paid_producer',
                'customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'fulfillment_writer', 'customer_fulfillment_API_DOM',
                'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM', 'worker', 'historical_task6_task7', 'full_acceptance', 'stopped_samples', 'aftersale_review'}
            and type(result['schema']) is int and result['schema'] == 1
            and result['scope'] == 'MS4_WORKER_NORMAL_CLOSE_ONLY'
            and result['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL'
            and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and result['context_sha256'] == sha(context_raw) and result['close_source_sha256'] == CLOSE_SHA256
            and result['reviewed_host_sha256'] == HOST_SHA256 and type(result['native_exit']) is int and result['native_exit'] == 0
            and paid_equal(result['exact_nonforce_removed'], {'containers': 4, 'networks': 1, 'volumes': 8})
            and result['ordinary_stop_and_exact_nonforce_remove'] is True
            and result['paid_producer'] == 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND'
            and result['fulfillment_writer'] == 'INTERNAL_ONLY_SOURCE_BOUND'
            and result['aftersale_review'] == AFTERSALE_REVIEW['status'] and result['worker'] == WORKER['status']
            and all(result[key] == 'NOT RUN' for key in ['customer_fulfillment_API_DOM', 'merchant_fulfillment_API_DOM', 'platform_fulfillment_API_DOM', 'historical_task6_task7'])
            and all(result[key] == 'NOT RUN' for key in ['customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'stopped_samples'])
            and result['full_acceptance'] == 'INCOMPLETE'
            and paid_equal(result['final_inventory'], {kind: {'project': [], 'run': []} for kind in ['container', 'network', 'volume']}),
            'ACTUAL_PAID_CLOSE_ZERO_RESULT')
    paid_created = bound_created(context, PAID['proof'], PAID['role'])
    worker_created = bound_created(context, WORKER['proof'], WORKER['role'])
    expected = {paid_created['id']: PAID['role'], worker_created['id']: WORKER['role'], **{cid: role for role, cid in context['initial_service_ids'].items()}}
    require(set(expected) == worker_original_ids(context), 'ACTUAL_EXACT_ORIGINAL_THREE_PAID_ROLES')
    attempt = load(evidence / 'ms4-worker-normal-close-attempt.json')
    require(set(attempt) == {'scope', 'run_id', 'source_sha', 'close_source_path', 'close_source_bytes', 'close_source_sha256',
                'reviewed_host_sha256', 'context_sha256', 'freeze_sha256', 'original_expected', 'operations_started_at_receipt_time'}
            and attempt['original_expected'] == expected and attempt['scope'] == 'MS4_WORKER_NORMAL_CLOSE_ONLY'
            and all(attempt[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
            and attempt['reviewed_host_sha256'] == HOST_SHA256 and attempt['close_source_sha256'] == CLOSE_SHA256
            and attempt['close_source_path'] == CLOSE.resolve().as_posix()
            and type(attempt['close_source_bytes']) is int and attempt['close_source_bytes'] == len(regular(CLOSE))
            and attempt['context_sha256'] == sha(context_raw) and attempt['operations_started_at_receipt_time'] is False,
            'ACTUAL_EXACT_PAID_CLOSE_ATTEMPT')
    independent_receipts = worker_lifecycle_receipts(context, expected)
    stopped = load(evidence / 'ms4-worker-normal-close-all-stopped.json')
    require(set(stopped) == {'containers', 'resources'} and set(stopped['containers']) == set(expected),
            'EXACT_THREE_ARCHIVED_NORMAL_PAID_TERMINALS')
    same_facts(stopped['containers'][paid_created['id']], load(evidence / 'paid-producer-terminal.json'))
    same_facts(independent_receipts['paid'], stopped['containers'][paid_created['id']])
    same_facts(stopped['containers'][worker_created['id']], load(evidence / 'worker-normal-terminal.json'))
    same_facts(independent_receipts['worker'], stopped['containers'][worker_created['id']])
    for cid, role in expected.items():
        normal(stopped['containers'][cid], cid, context, role)
        before = paid_created if role == PAID['role'] else worker_created if role == WORKER['role'] else context['initial_service_created'][role]
        same_created(before, stopped['containers'][cid])
        if role in [PAID['role'], WORKER['role']]:
            continue
        created = load(evidence / ('ms4-worker-normal-close-' + role + '-stop-created.json'))
        native = load(evidence / ('ms4-worker-normal-close-' + role + '-stop-terminal.json'))
        after = load(evidence / ('ms4-worker-normal-close-' + role + '-after-stop.json'))
        same_facts(stopped['containers'][cid], after)
        same_facts(after, load(evidence / ('ms4-worker-normal-close-' + role + '-terminal.json')))
        stdout_forms = [(cid + '\n').encode(), ('Flag --time has been deprecated, use --timeout instead\n' + cid + '\n').encode()]
        require(set(created) == {'pid', 'container_id', 'role', 'time', 'no_timed_force'}
                and set(native) == {'pid', 'container_id', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'wait_returncode'}
                and type(created['pid']) is int and created['pid'] > 0 and type(native['pid']) is int and native['pid'] == created['pid']
                and created['container_id'] == native['container_id'] == cid and created['role'] == role
                and type(created['time']) is int and created['time'] == -1 and created['no_timed_force'] is True
                and type(native['native_exit']) is int and native['native_exit'] == 0
                and type(native['wait_returncode']) is int and native['wait_returncode'] == 0
                and type(native['stderr_bytes']) is int and native['stderr_bytes'] == 0 and native['stderr_sha256'] == sha(b'')
                and type(native['stdout_bytes']) is int
                and any(native['stdout_bytes'] == len(raw) and native['stdout_sha256'] == sha(raw) for raw in stdout_forms),
                'ACTUAL_SAME_DB_STOP_PID_NATIVE_ZERO_EXACT_STDOUT')
    before = load(evidence / 'ms4-worker-normal-close-before.json')
    require(set(before) == {'inventory', 'network', 'volumes', 'global_consumers', 'verified_receipts'}
            and type(before['verified_receipts']) is dict
            and set(before['verified_receipts']) == set(independent_receipts)
            and paid_equal({key: value for key, value in before['verified_receipts'].items() if key not in ['paid', 'worker']},
                           {key: value for key, value in independent_receipts.items() if key not in ['paid', 'worker']}),
            'ALL_INDEPENDENT_PAID_PUBLIC_RECEIPTS_RETAINED')
    same_facts(before['verified_receipts']['paid'], independent_receipts['paid'])
    same_facts(before['verified_receipts']['worker'], independent_receipts['worker'])
    worker_resource_archive({key: value for key, value in before.items() if key != 'verified_receipts'}, context, expected)
    worker_resource_archive(stopped['resources'], context, expected)
    worker_resource_archive(load(evidence / 'ms4-worker-normal-close-before-network-volumes-rm.json'), context, set(), True)
    volumes = load(evidence / 'ms4-worker-normal-close-before-eight-volumes-rm.json')
    require(set(volumes) == {'inventory', 'volumes', 'global_consumers'}
            and paid_equal(volumes['volumes'], context['initial_volumes']), 'FINAL_ORIGINAL_EIGHT_VOLUMES_ARCHIVE')
    paid_inventory(volumes['inventory'], context, set(), False, True)
    paid_global_archive(volumes['global_consumers'], expected, set())
    paid_global_archive(result['global_consumers'], expected, set())
    global_before = worker_global_no_original_consumers(context)
    queries = []
    for kind, argv in [('container', ['ps', '-aq', '--no-trunc']), ('network', ['network', 'ls', '-q']), ('volume', ['volume', 'ls', '-q'])]:
        for label, value in [('project', 'com.docker.compose.project=' + context['project']), ('run', 'tianli.audit.run=' + context['run_id'])]:
            process = subprocess.run(['docker', *argv, '--filter', 'label=' + value], capture_output=True)
            row = {'kind': kind, 'label': label, 'native_exit': process.returncode, 'stdout_bytes': len(process.stdout),
                'stdout_sha256': sha(process.stdout), 'stderr_bytes': len(process.stderr), 'stderr_sha256': sha(process.stderr)}
            queries.append(row)
            require(type(process.returncode) is int and process.returncode == 0 and process.stdout == b'' and process.stderr == b'',
                    'INDEPENDENT_PAID_QUERY_NATIVE_ZERO_EMPTY_RAW_STREAMS')
    require(len(queries) == 6, 'EXACT_SIX_INDEPENDENT_PAID_ZERO_QUERIES')
    global_after = worker_global_no_original_consumers(context)
    worker_fixed_sources(context)
    require(paid_equal(worker_lifecycle_receipts(context, expected), independent_receipts),
            'ALL_ORIGINAL_PAID_PREDECESSORS_AND_RESULTS_UNCHANGED_THROUGH_ZERO')
    output = {'schema': 1, 'scope': 'MS4_WORKER_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY',
        'status': 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO',
        'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'context_sha256': sha(context_raw), 'close_result_sha256': close_result_sha256,
        'close_source_sha256': CLOSE_SHA256, 'host_source_sha256': HOST_SHA256,
        'zero_source_sha256': sha(regular(Path(__file__))), 'queries': queries,
        'global_consumers': {'before': global_before, 'after': global_after}, 'native_exit': 0,
        'paid_producer': 'INTERNAL_ONLY_WITH_MS5_SOURCE_BOUND',
        'customer_paid_API_DOM': 'NOT RUN', 'merchant_paid_API_DOM': 'NOT RUN', 'platform_paid_API_DOM': 'NOT RUN',
        'fulfillment_writer': 'INTERNAL_ONLY_SOURCE_BOUND', 'customer_fulfillment_API_DOM': 'NOT RUN',
        'merchant_fulfillment_API_DOM': 'NOT RUN', 'platform_fulfillment_API_DOM': 'NOT RUN', 'worker': WORKER['status'], 'aftersale_review': AFTERSALE_REVIEW['status'], 'historical_task6_task7': 'NOT RUN',
        'full_acceptance': 'INCOMPLETE', 'stopped_samples': 'NOT RUN'}
    new(evidence / 'ms4-worker-normal-independent-zero.json', output)
    print(json.dumps(output, ensure_ascii=False))


UI_HOST_SHA256 = '9c2090c7313076418ef348829de4b91ac1a2f42c9e377b2625429e119b31b266'
UI_CLOSE_SHA256 = '0a35ff07b38afc010e230e009a148fd65f7931daff0ec7e8ff09f6596cbb75e4'
UI_INTERFACE_BYTES = 118487
UI_INTERFACE_SHA256 = 'd0780d80bcd1f317d7ef13706fa90c0dccbeafa75cfb5e19163e68d48728e9d3'


def ui_zero_file(path):
    path = Path(path)
    for part in [path,*path.parents]:
        require(not part.is_symlink() and not getattr(part,'is_junction',lambda:False)(), 'UI_ZERO_PUBLIC_FILE_NO_LINKS_OR_REPARSE')
    before = path.lstat()
    require(path.is_file() and before.st_nlink == 1, 'UI_ZERO_PUBLIC_ORDINARY_SINGLE_FILE')
    raw = path.read_bytes()
    after = path.lstat()
    fields = ['st_dev','st_ino','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns']
    require(all(getattr(before,key) == getattr(after,key) for key in fields) and len(raw) == before.st_size,
        'UI_ZERO_PUBLIC_COMPLETE_STABLE_READ')
    return raw

# Explicit independent UI normal-close verification. No Host/close import.
# UI_HOST_SHA256 and UI_CLOSE_SHA256 are bound by the final source assembler.
import os
PREP = ARTIFACTS


VOLUMES = ['aftersale-evidence', 'app-secrets', 'bootstrap-secrets', 'customer-form-evidence', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets']


INSPECT = '{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"user":{{json .Config.User}},"stop_signal":{{json (index .Config "StopSignal")}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}}}'


UI_PREPARE_ENTRY = 'help/audit-regressions/isolated/ms4-ui-prepare.php'
UI_PREPARE = {'proof': 'ui-prepare', 'role': 'audit-ms4-ui-prepare',
    'binding': 'ms4-ui-prepare-source-binding.json', 'scope': 'MS4_UI_PREPARE_SOURCE',
    'result': 'ms4-ui-prepare.json', 'result_scope': 'NORMAL_MS4_UI_PREPARATION_ONLY',
    'status': 'VERIFIED_NORMAL_MS4_UI_PREPARATION_ONLY', 'environment_scope': 'MS4_UI_PREPARE_REAL_ENVIRONMENT'}
UI_PREPARE_FOUR = [UI_PREPARE_ENTRY, 'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
    'help/audit-regressions/isolated/lib/ms4-ui-credentials.php', 'help/migrations/marketplace-ms4-order-read-menus.php']
UI_BACKEND_THREE = ['help/audit-regressions/isolated/ms4-ui-backend.php', 'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php', 'help/audit-regressions/isolated/ms4-ui-credential-meta.php']
UI_CURRENT_SOURCE_PINS = {'help/audit-regressions/isolated/ms4-aftersale-review-normal.php': {'path': 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php', 'bytes': 239863, 'sha256': '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'}, 'help/audit-regressions/isolated/ms4-ui-prepare.php': {'path': 'help/audit-regressions/isolated/ms4-ui-prepare.php', 'bytes': 42883, 'sha256': '5ef93cfc14531d5fd5f84406eec6ca23fed525187b3e526997331316e0ee3f74'}, 'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php': {'path': 'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php', 'bytes': 242783, 'sha256': 'b1495850acd84f3c05b1486b364a0ecc2bd5aa35419bde7a5ebeace472a3ce45'}, 'help/audit-regressions/isolated/lib/ms4-ui-credentials.php': {'path': 'help/audit-regressions/isolated/lib/ms4-ui-credentials.php', 'bytes': 8554, 'sha256': '5a7064e8b3d56b72d96fe46df6afa545f21a20b1e2d8838c38377dcb1221a5f6'}, 'help/migrations/marketplace-ms4-order-read-menus.php': {'path': 'help/migrations/marketplace-ms4-order-read-menus.php', 'bytes': 23217, 'sha256': 'ba3cac05d865312221e4d1cc957e4ebdc7e50e57b2e367135beda10114a4ce36'}, 'help/audit-regressions/isolated/ms4-ui-backend.php': {'path': 'help/audit-regressions/isolated/ms4-ui-backend.php', 'bytes': 3034, 'sha256': 'ae4ebd8dcd8db0369276bf219250ca2f018147c3424bbbc9e21534b536c8bec1'}, 'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php': {'path': 'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php', 'bytes': 127620, 'sha256': '5d6f3496def5a4e7ee4e6f719335256edf712d286fddf739e0df23176df3f6bd'}, 'help/audit-regressions/isolated/ms4-ui-credential-meta.php': {'path': 'help/audit-regressions/isolated/ms4-ui-credential-meta.php', 'bytes': 5514, 'sha256': 'd98f1886b599064337cb3aed7abde2b2df885e0d986ce9006d5fe8cff24fc59f'}}
UI_PREPARE_RESULT_KEYS = ['schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'business', 'child', 'diagnostic', 'three_end_login_DOM', 'N1_fulfillment_review_business', 'full_acceptance', 'stopped_samples', 'assertions']
UI_PREPARE_BUSINESS_KEYS = ['prepared_only', 'paid_order_id', 'paid_order_no', 'merchant_id', 'customer_id', 'item_quantities', 'source_fingerprint', 'actor_ids', 'writer', 'menus', 'window_before', 'window_final', 'independent_full_window_verified', 'post_child_close_verified', 'observer']


UI_COPY = {'proof': 'ui-public-copy', 'role': 'audit-ms4-ui-public-copy', 'entry': '/run/ms4-prep/ms4-ui-copy.php', 'binding': 'ms4-ui-public-copy-source-binding.json'}
UI_COPY_ARTIFACTS = ['compose.yml', 'interface-v1.json', 'ms4-host.py', 'ms4-initialize.php', 'normal-close-ms4-fulfillment.py', 'README.md', 'seed-source-inverse.json', 'source-derivation.json', 'verify-zero-ms4-fulfillment.py', 'ms4-ui-nginx.conf', 'ms4-ui-public-copy.php', 'ms4-ui-copy.php', 'ms4-order.png', 'manifests/ui-h5-dist.json', 'manifests/ui-merchant-dist.json', 'manifests/ui-admin-dist.json', 'manifests/ui-public-copy-profile.json']
UI_COPY_BINDING_KEYS = ['schema', 'scope', 'run_id', 'source_sha', 'container_id', 'native_created_sha256', 'freeze_sha256', 'requested_repo_source', 'requested_run_source', 'requested_prep_source', 'entry_sha256', 'source_files', 'prerequisites_sha256', 'manifest', 'history', 'volumes_native', 'copy_profile', 'dist_sources']
UI_COPY_RESULT_KEYS = ['schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'native_created_sha256', 'source_binding_sha256', 'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'materials_candidate', 'diagnostic', 'App_HTTP_DOM', 'business_acceptance', 'full_acceptance', 'stopped_samples', 'assertions']
UI_COPY_SHUTDOWN_KEYS = ['schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase', 'completed', 'result_published', 'fatal', 'termination_requested', 'primary_failure', 'cleanup_failure', 'native_exit', 'native_exit_observation']
UI_COPY_WEB_VOLUMES = ['ui-public', 'ui-comments', 'ui-control', 'ui-customer-assets']


UI_WEB_NATIVE_KEYS = ['id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid', 'exit_code', 'networks', 'ports', 'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at']
UI_WEB_BINDING_KEYS = ['schema', 'scope', 'run_id', 'source_sha', 'container_id', 'native_created_sha256', 'freeze_sha256', 'requested_repo_source', 'requested_run_source', 'requested_prep_source', 'entry_sha256', 'source_files', 'prerequisites_sha256', 'manifest', 'environment', 'environment_native', 'history', 'public_materials', 'volumes_native']
UI_WEB_CURRENT_KEYS = ['schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'native_created_sha256', 'source_binding_sha256', 'projection', 'native']
UI_WEB_NATIVE_RECEIPT_KEYS = ['label', 'argv', 'pid', 'native_exit', 'wait_returncode', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256']
UI_WEB_RESULT_KEYS = ['schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'prepare_history_sha256', 'ready_sha256', 'phase', 'entry_return_code', 'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'child', 'stop_kind', 'diagnostic', 'three_end_login_DOM', 'business_acceptance', 'full_acceptance', 'stopped_samples', 'assertions']
UI_WEB_SHUTDOWN_KEYS = ['schema', 'scope', 'run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase', 'completed', 'result_published', 'fatal', 'termination_requested', 'primary', 'cleanup', 'child', 'source_unchanged']
UI_WEB_WRITER_KEYS = ['path', 'bytes', 'sha256', 'mode', 'uid', 'gid', 'dev', 'ino', 'nlink', 'directory_mode']
UI_WEB_BACKEND = {'proof':'ui-backend','role':'audit-ms4-ui-backend','entry':'help/audit-regressions/isolated/ms4-ui-backend.php','binding':'ms4-ui-backend-source-binding.json'}
UI_WEB_GATEWAY = {'proof':'ui-gateway','role':'audit-ms4-ui-gateway'}
UI_WEB_REGISTER = {'proof':'register-ui-web','role':'audit-ms4-ui-web-register'}
UI_WEB_NGINX = 'sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236'
UI_WEB_CONTROL_NAMES = ['ui-backend-ready.json','ui-backend-stop-request.json','ms4-ui-backend.json','ui-backend-shutdown.json']
UI_WEB_NETWORK_FORMAT = '{"Id":{{json .Id}},"Name":{{json .Name}},"Driver":{{json .Driver}},"Scope":{{json .Scope}},"Internal":{{json .Internal}},"EnableIPv6":{{json .EnableIPv6}},"Options":{{json .Options}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}'


def ui_prepare_extra():
    values = sorted(set(aftersale_review_extra() + UI_PREPARE_FOUR))
    expect(len(values) == 34, 'UI_PREPARE_EXACT_ORIGINAL30_PLUS4_EXTRAS')
    return values


def ui_backend_extra():
    values = sorted(set(ui_prepare_extra() + UI_BACKEND_THREE))
    expect(len(values) == 37, 'UI_BACKEND_EXACT_PREPARE34_PLUS3_EXTRAS')
    return values


def ui_source_paths(repo, extras):
    root = Path(repo)
    paths = list(extras)
    for relative in CUSTOMER_ROOTS:
        directory = root / relative
        expect(directory.is_dir() and not directory.is_symlink()
            and not getattr(directory, 'is_junction', lambda: False)(), 'UI_REAL_SOURCE_ROOT')
        for path in directory.rglob('*'):
            expect(not path.is_symlink() and not getattr(path, 'is_junction', lambda: False)()
                and (path.is_file() or path.is_dir()), 'UI_SOURCE_ONLY_REGULAR_FILES_AND_DIRS')
            if path.is_file():
                paths.append(path.relative_to(root).as_posix())
    paths.sort()
    expect(len(paths) == len(set(paths)) and all((root / p).is_file()
        and not (root / p).is_symlink() for p in paths), 'UI_COMPLETE_SORTED_UNIQUE_SOURCE_MEMBERS')
    return paths


def ui_prepare_groups_present(binding):
    names = [group + '_source_' + leaf for group in ['ui_prepare', 'ui_backend'] for leaf in ['roots', 'extra', 'files']]
    present = [name in binding for name in names]
    expect(not any(present) or all(present), 'UI_BOTH_COMPLETE_SOURCE_GROUPS_FROM_INITIAL_FREEZE')
    return all(present)


def verify_ui_prepare_freeze(binding, repo):
    expect(ui_prepare_groups_present(binding), 'UI_EXPLICIT_INITIAL_SOURCE_GROUPS_REQUIRED')
    for group, extras in [('ui_prepare', ui_prepare_extra()), ('ui_backend', ui_backend_extra())]:
        rows = binding[group + '_source_files']
        expect(binding[group + '_source_roots'] == CUSTOMER_ROOTS and binding[group + '_source_extra'] == extras
            and type(rows) is list and [row['path'] for row in rows] == ui_source_paths(repo, extras),
            'UI_COMPLETE_ACTUAL_FOUR_ROOTS_AND_FIXED_EXTRA_SET')
        for row in rows:
            expect(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
                and type(row['bytes']) is int and row['bytes'] >= 0 and paid_hash(row['sha256'])
                and source_row(repo, row['path']) == row, 'UI_EVERY_ACTUAL_SOURCE_BYTE')
    prepare_rows = binding['ui_prepare_source_files']
    backend_rows = binding['ui_backend_source_files']
    expect([row for row in prepare_rows if row['path'] not in UI_PREPARE_FOUR]
        == binding['aftersale_review_source_files'], 'UI_ORIGINAL_COMPLETE_AR30_SOURCE_RETAINED')
    expect([row for row in backend_rows if row['path'] not in UI_BACKEND_THREE] == prepare_rows,
        'UI_LATER_BACKEND37_RETAINS_THIS_SAME_INITIAL_PREPARE34')
    for relative, row in UI_CURRENT_SOURCE_PINS.items():
        expect(next(item for item in backend_rows if item['path'] == relative) == row
            and source_row(repo, relative) == row, 'UI_CURRENT_REVIEWED_PINS_NOT_OLD_DEFAULTS')


def ui_prepare_owned(context, facts):
    native_keys = {'id', 'image', 'hostname', 'user', 'stop_signal', 'labels', 'mounts', 'tmpfs', 'read_only',
        'privileged', 'network_mode', 'status', 'command', 'entrypoint', 'workdir', 'restart_count', 'pid',
        'exit_code', 'networks', 'ports', 'port_bindings', 'cap_drop', 'security_options', 'started_at', 'finished_at', 'created_at'}
    expect(set(facts) in [native_keys, native_keys | {'run_id', 'source_sha', 'proof_name'}]
        and facts['command'] == [UI_PREPARE_ENTRY, 'prepare'] and facts['user'] == '0:0'
        and facts['stop_signal'] == 'SIGTERM' and type(facts['pid']) is int
        and type(facts['exit_code']) is int and type(facts['restart_count']) is int and facts['restart_count'] == 0,
        'UI_EXACT_NATIVE26_OR_CREATED29_ENTRY_USER_SIGNAL')
    if 'proof_name' in facts:
        expect(facts['run_id'] == context['run_id'] and facts['source_sha'] == context['source_sha']
            and facts['proof_name'] == UI_PREPARE['proof'], 'UI_OWN_CREATED_IDENTITY')
    expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp', '/workspace/crmeb/runtime', '/workspace/crmeb/public'}
        and all(type(v) is str and 'rw' in v.split(',') for v in facts['tmpfs'].values()), 'UI_ONLY_ORIGINAL_THREE_RW_TMPFS')
    expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
        '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
        '/workspace/crmeb/public/install/crmeb.sql': ('bind', context['repo_dir'] + '/crmeb/public/install/crmeb.sql', False),
        '/run/audit-app': ('volume', context['project'] + '_app-secrets', False),
        '/run/audit-fixtures': ('volume', context['project'] + '_fixture-secrets', True),
        AFTERSALE_PRIVATE_ROOT: ('volume', context['project'] + '_aftersale-evidence', True),
        CUSTOMER_FORM_PRIVATE_ROOT: ('volume', context['project'] + '_customer-form-evidence', False)}
    mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    expect(len(mounts) == len(expected) and {row['Destination'] for row in mounts} == set(expected)
        and all(row['Destination'] in facts['tmpfs'] and row['RW'] is True
            for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'UI_CLOSED_NINE_NATIVE_MOUNTS')
    for row in mounts:
        kind, source, rw = expected[row['Destination']]
        expect(row['Type'] == kind and row['RW'] is rw
            and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source),
            'UI_ACTUAL_SOURCE_AND_PRIVATE_MOUNT_IDENTITY_ACCESS')
    aliases = facts['networks'][context['project'] + '_isolation'].get('Aliases') or []
    expect(aliases.count('audit-app') == 1, 'UI_OWN_SHORT_PREPARATION_HTTP_ALIAS')


def ui_prepare_prerequisite_names():
    values = sorted(set(paid_history_names() + FULFILLMENT_MIGRATION_FOUR
        + ['paid-producer-formal-manifest.json', 'paid-producer-predecessor-history.json']))
    expect(len(values) == 39, 'UI_EXACT_SORTED39_REAL_PREDECESSORS')
    return values


def ui_prepare_binding(context, cid):
    evidence = Path(context['run_dir']) / 'evidence'
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    verify_ui_prepare_freeze(frozen, context['repo_dir'])
    return {'schema': 1, 'scope': UI_PREPARE['scope'], 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'container_id': cid, 'native_created_sha256': digest((evidence / 'ui-prepare-created.json').read_bytes()),
        'freeze_sha256': context['freeze_sha256'], 'requested_repo_source': context['repo_dir'],
        'requested_run_source': context['run_dir'], 'requested_prep_source': context['prep_dir'],
        'entry_sha256': UI_CURRENT_SOURCE_PINS[UI_PREPARE_ENTRY]['sha256'],
        'source_files': frozen['ui_prepare_source_files'], 'prerequisites_sha256': ui_prepare_prerequisite_hashes(context)}


def verify_ui_prepare_binding(context, cid, native_required=False):
    actual = load(Path(context['run_dir']) / 'evidence' / UI_PREPARE['binding'])
    expect(paid_equal(actual, ui_prepare_binding(context, cid)) and len(actual) == 13, 'UI_EXACT13_REAL_SOURCE_BINDING')
    verify_ui_prepare_environment(context, cid, native_required)
    return actual


def ui_prepare_environment(context):
    return public_environment(context) | {'PHP_MARKETPLACE_AFTERSALE_ENABLED': 'true',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT': AFTERSALE_PRIVATE_ROOT, CUSTOMER_FORM_ENV: CUSTOMER_FORM_PRIVATE_ROOT}


def ui_prepare_environment_bytes(context):
    value = ui_prepare_environment(context)
    return ('\n'.join(json.dumps(name + '=' + value[name], ensure_ascii=False, separators=(',', ':'))
        for name in AFTERSALE_ENV_NAMES) + '\n').encode('utf-8')


def verify_ui_prepare_helper(context, proof, wanted_status):
    expect(proof == 'register-ui-prepare' and wanted_status == 'REGISTERED_NATIVE_RESOURCES_ONLY',
        'ONLY_OWN_EXPLICIT_UI_PREPARATION_REGISTER_HELPER')
    phase = 'register'
    evidence = Path(context['run_dir']) / 'evidence'
    created_facts = bound_created(context, proof, 'audit-bootstrap')
    normal_terminal(context, proof, 'audit-bootstrap', False)
    expect(created_facts['command'] == ['/run/ms4-prep/ms4-initialize.php', phase], 'NEW_LIFECYCLE_ACTUAL_ORIGINAL_INITIALIZER_COMMAND')
    result = load(evidence / (proof + '-result.json'))
    expect(type(result) is dict and set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'children', 'private_cleanup',
        'private_cleanup_verified', 'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_UI_PREPARE_LIFECYCLE_ONLY'
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


def ui_prepare_int(value, minimum=1, maximum=4294967295):
    return type(value) is int and minimum <= value <= maximum


def verify_ui_prepare_menus(menus, frozen):
    expect(type(menus) is dict and set(menus) == {'check', 'apply', 'postcheck', 'reapply'}, 'UI_FOUR_REAL_MENU_RESULTS')
    base_keys = {'mode', 'state', 'changed', 'tables', 'menu_ids', 'issues', 'anchors', 'source_binding',
        'authorization_cache_producer', 'roles_modified'}
    anchors = menus['check']['anchors']
    expect(type(anchors) is dict and set(anchors) == {'order_root', 'order_page', 'order_info', 'order_info_group'}
        and all(type(row) is dict and set(row) == {'id', 'sha256'} and ui_prepare_int(row['id'], 1, 65535)
            and paid_hash(row['sha256']) for row in anchors.values())
        and len({row['id'] for row in anchors.values()}) == 4, 'UI_ORIGINAL_FOUR_NATIVE_ANCHOR_DESCRIPTORS')
    source_hashes = {row['path']: row['sha256'] for row in frozen['ui_prepare_source_files']}
    native_ids = menus['apply']['menu_ids']
    expect(type(native_ids) is dict and set(native_ids) == {'order-list-read', 'order-chart-read'}
        and all(ui_prepare_int(value, 1, 65535) for value in native_ids.values())
        and len(set(native_ids.values())) == 2
        and not set(native_ids.values()).intersection(row['id'] for row in anchors.values()), 'UI_TWO_REAL_DISJOINT_CREATED_LEAVES')
    for name in ['check', 'apply', 'postcheck', 'reapply']:
        row = menus[name]
        expect(type(row) is dict and set(row) == base_keys | ({'created_menu_ids'} if name in ['apply', 'reapply'] else set())
            and row['mode'] == ('check' if name in ['check', 'postcheck'] else 'apply')
            and row['state'] == ('applicable' if name == 'check' else 'completed')
            and row['changed'] is (name == 'apply') and row['tables'] == [] and row['issues'] == []
            and paid_equal(row['anchors'], anchors) and row['roles_modified'] is False
            and row['authorization_cache_producer'] == 'fresh_scope_for_request_exact_all_auth_and_current_roles'
            and paid_equal(row['source_binding'], {
                'entry_sha256': source_hashes['help/migrations/marketplace-ms4-order-read-menus.php'],
                'ms1_sha256': source_hashes['help/migrations/marketplace-ms1.php']}),
            'UI_EACH_WHOLE_REAL_MENU_RESULT_AND_SOURCE_BINDING')
        expect(paid_equal(row['menu_ids'], [] if name == 'check' else native_ids), 'UI_SAME_ACTUAL_NATIVE_MENU_IDENTITIES')
        if name in ['apply', 'reapply']:
            expect(paid_equal(row['created_menu_ids'], native_ids if name == 'apply' else []), 'UI_REAPPLY_IS_TRUE_NATIVE_NOOP')


def task2_prepare_group(context):
    root = Path(context['run_dir']); raw = t2z_read(root / 'preparation-source-binding.json')
    require(sha(raw) == context['freeze_sha256'], 'TASK2_HISTORY_SAME_COMPLETE_INITIAL_FREEZE')
    frozen = json.loads(raw); task2_observer_zero(context)
    return frozen.get('task2_observer_source')


def task2_verify_paid_sequence(sequence):
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
            and 0 <= expiry - int(samples[0]['time_ms']) <= 1000 and paid_hash(target['initial_value_sha256'])
            and target['natural_expiry_observed'] is True and int(target['first_absence_time_ms']) >= expiry, 'PAID_SEQUENCE_REAL_OBSERVED_NATURAL_EXPIRY')


def task2_prepare_window(value):
    expect(type(value) is dict and set(value) == {'sha256', 'database_sha256', 'redis_sha256', 'files_sha256', 'table_count'}
        and all(paid_hash(value[k]) for k in ['sha256', 'database_sha256', 'redis_sha256', 'files_sha256'])
        and ui_prepare_int(value['table_count']), 'TASK2_COMPLETE_NATIVE_WINDOW5')


def task2_prepare_join(left, right):
    task2_prepare_window(left); task2_prepare_window(right)
    expect(all(left[k] == right[k] for k in ['database_sha256', 'redis_sha256', 'files_sha256', 'table_count']),
        'TASK2_FULL_COMPONENT_WINDOW_CONTINUITY')


def task2_prepare_creation(value, target):
    expect(type(value) is dict and set(value) == {'target', 'source_fingerprint', 'cart_reference_sha256', 'request_sha256',
        'before', 'cart', 'after', 'verified_after', 'payment_generation', 'payment_attempt_count', 'sequence'}
        and paid_equal(value['target'], target) and type(value['payment_generation']) is int and value['payment_generation'] == 0
        and type(value['payment_attempt_count']) is int and value['payment_attempt_count'] == 0
        and all(paid_hash(value[k]) for k in ['source_fingerprint', 'cart_reference_sha256', 'request_sha256']), 'TASK2_REAL_CREATION11')
    for key in ['before', 'cart', 'after', 'verified_after']:
        task2_prepare_window(value[key])
    task2_prepare_join(value['after'], value['verified_after'])
    sequence = value['sequence']; task2_verify_paid_sequence(sequence)
    number = target['order_no']; key = str(1640966400000 + int(number[2:]) // 4194304)
    expect(sequence['targets'][0]['key_sha256'] == sha(key.encode())
        and sequence['targets'][0]['sources'] == [{'source_kind': 'persistent_order', 'identifier_sha256': sha(number.encode())}],
        'TASK2_EXACT_CREATED_ORDER_REAL_PRODUCER_SEQUENCE')


def verify_task2_prepare(context, result):
    group = task2_prepare_group(context)
    if group is None:
        return None
    root = Path(context['run_dir']); evidence = root / 'evidence'; business = result['business']
    p = load(evidence / 'ms5-task2-target-preparation.json')
    expect(type(p) is dict and set(p) == {'schema', 'scope', 'status', 'binding', 'observer_source', 'actors', 'paid',
        'unpaid', 'cancelled', 'window', 'preservation', 'diagnostic'} and type(p['schema']) is int and p['schema'] == 1
        and p['scope'] == 'MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY'
        and p['status'] == 'VERIFIED_MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY' and p['diagnostic'] is None
        and paid_equal(p['observer_source'], group), 'TASK2_REAL_COMPLETE_PREPARATION12')
    binding = {k: context[k] for k in ['run_id', 'source_sha', 'freeze_sha256']}
    binding.update({'prepare_container_id': result['container_id'], 'native_created_sha256': result['native_created_sha256'],
        'source_binding_sha256': result['source_binding_sha256'], 'context_sha256': sha((root / 'actual-run-context.json').read_bytes())})
    expect(type(p['binding']) is dict and set(p['binding']) == set(binding) and paid_equal(p['binding'], binding), 'TASK2_ACTUAL_SAME_PREPARE_BINDING7')
    actors = p['actors']
    expect(type(actors) is dict and set(actors) == {'customer', 'merchant', 'platform'}, 'TASK2_ORIGINAL_ACTOR_ROLES')
    for role, keys in [('customer', {'id'}), ('merchant', {'id', 'role_id', 'mer_id'}), ('platform', {'id', 'role_id'})]:
        expect(type(actors[role]) is dict and set(actors[role]) == keys and all(ui_prepare_int(v) for v in actors[role].values())
            and actors[role]['id'] == business['actor_ids'][role], 'TASK2_SAME_REAL_NATIVE_ACTOR')
    uid = business['customer_id']; mer = business['merchant_id']
    expect(actors['merchant']['mer_id'] == mer, 'TASK2_SAME_MERCHANT_OWNER')
    ids = []; numbers = []
    for state, keys in [('paid', {'target', 'source_fingerprint'}), ('unpaid', {'target', 'creation'}), ('cancelled', {'target', 'creation', 'cancellation'})]:
        value = p[state]
        expect(type(value) is dict and set(value) == keys and type(value['target']) is dict
            and set(value['target']) == {'id', 'order_no', 'uid', 'mer_id'}, 'TASK2_REAL_TARGET_SHAPE')
        target = value['target']; number = target['order_no']
        expect(ui_prepare_int(target['id']) and type(target['uid']) is int and target['uid'] == uid
            and type(target['mer_id']) is int and target['mer_id'] == mer and type(number) is str
            and re.fullmatch('cp[1-9][0-9]{0,18}', number) is not None, 'TASK2_SAME_OWNER_REAL_SAVED_TARGET4')
        ids.append(target['id']); numbers.append(number)
    expect(len(set(ids)) == len(set(numbers)) == 3 and p['paid']['target']['id'] == business['paid_order_id']
        and p['paid']['target']['order_no'] == business['paid_order_no']
        and p['paid']['source_fingerprint'] == business['source_fingerprint'], 'TASK2_DISTINCT_THREE_TARGETS_ORIGINAL_PAID_ANCHOR')
    for state in ['unpaid', 'cancelled']:
        task2_prepare_creation(p[state]['creation'], p[state]['target'])
    expect(p['unpaid']['creation']['cart_reference_sha256'] != p['cancelled']['creation']['cart_reference_sha256'], 'TASK2_TWO_REAL_DISTINCT_CART_REFERENCES')
    cancel = p['cancelled']['cancellation']
    expect(type(cancel) is dict and set(cancel) == {'before', 'after', 'verified_after', 'native_release_sha256', 'native_cancel_status_sha256',
        'unchanged_saved_item_sha256', 'payment_generation', 'payment_attempt_count', 'cancel_call_count'}
        and all(paid_hash(cancel[k]) for k in ['native_release_sha256', 'native_cancel_status_sha256', 'unchanged_saved_item_sha256'])
        and type(cancel['payment_generation']) is int and cancel['payment_generation'] == 0
        and type(cancel['payment_attempt_count']) is int and cancel['payment_attempt_count'] == 0
        and type(cancel['cancel_call_count']) is int and cancel['cancel_call_count'] == 1, 'TASK2_ONCE_REAL_BORN_G0_CANCEL9')
    task2_prepare_window(cancel['before']); task2_prepare_join(cancel['after'], cancel['verified_after'])
    window = p['window']
    expect(type(window) is dict and set(window) == {'original_before', 'original_after', 'increment_before', 'after', 'verified_after'}, 'TASK2_W0_W1_W2_WINDOW5')
    for value in window.values():
        task2_prepare_window(value)
    expect(paid_equal(window['original_before'], business['window_before']) and paid_equal(window['after'], business['window_final'])
        and paid_equal(window['increment_before'], p['unpaid']['creation']['before'])
        and paid_equal(p['unpaid']['creation']['after'], p['cancelled']['creation']['before'])
        and paid_equal(p['cancelled']['creation']['after'], cancel['before']) and paid_equal(cancel['after'], window['after']),
        'TASK2_TRUE_W0_W1_TWO_CREATES_CANCEL_W2_EXACT_CHAIN')
    task2_prepare_join(window['original_after'], window['increment_before']); task2_prepare_join(window['after'], window['verified_after'])
    preservation = p['preservation']
    expect(type(preservation) is dict and set(preservation) == {'database_tables', 'old_paid_source_fingerprint', 'original_files_sha256', 'final_files_sha256',
        'all_non_targets_verified', 'source_unchanged', 'single_private_handoff', 'no_new_child_or_listener', 'observer_copy_sha256'}
        and type(preservation['database_tables']) is int and preservation['database_tables'] == window['after']['table_count']
        and preservation['old_paid_source_fingerprint'] == business['source_fingerprint']
        and preservation['original_files_sha256'] == window['increment_before']['files_sha256']
        and preservation['final_files_sha256'] == window['after']['files_sha256']
        and preservation['original_files_sha256'] == preservation['final_files_sha256']
        and all(preservation[k] is True for k in ['all_non_targets_verified', 'source_unchanged', 'single_private_handoff', 'no_new_child_or_listener'])
        and preservation['observer_copy_sha256'] == context['task2_observer_copy']['sha256'], 'TASK2_WHOLE_ORIGINAL_PRESERVATION9_AND_COPY')
    return p


def verify_ui_prepare_business(context, business):
    expect(type(business) is dict and set(business) == set(UI_PREPARE_BUSINESS_KEYS)
        and business['prepared_only'] is True and business['independent_full_window_verified'] is True
        and business['post_child_close_verified'] is True, 'UI_EXACT15_GENUINE_POST_CHILD_WINDOW')
    expect(all(ui_prepare_int(business[key]) for key in ['paid_order_id', 'merchant_id', 'customer_id'])
        and type(business['paid_order_no']) is str and 0 < len(business['paid_order_no']) <= 64
        and paid_hash(business['source_fingerprint']), 'UI_REAL_SOURCE_OWNER_IDENTIFIERS')
    actors = business['actor_ids']
    expect(type(actors) is dict and set(actors) == {'customer', 'merchant', 'platform'}
        and all(ui_prepare_int(value) for value in actors.values()) and actors['customer'] == business['customer_id'],
        'UI_FLAT_ACTUAL_THREE_NATIVE_ACTOR_IDS_NOT_TRANSPORT_MAP')
    items = business['item_quantities']
    expect(type(items) is list and len(items) == 2 and all(type(row) is dict and set(row) == {'item_id', 'quantity'}
        and ui_prepare_int(row['item_id']) and type(row['quantity']) is int for row in items)
        and [row['quantity'] for row in items] == [2, 1] and items[0]['item_id'] != items[1]['item_id'],
        'UI_ACTUAL_SOURCE_ORACLE_TWO_SAVED_ITEMS')
    writer = business['writer']
    expect(type(writer) is dict and set(writer) == {'path', 'bytes', 'sha256', 'mode', 'uid', 'gid', 'dev', 'ino', 'nlink', 'directory_mode'}
        and writer['path'] == '/run/audit-fixtures/ms4-ui/credentials.json'
        and ui_prepare_int(writer['bytes'], 1, 16384) and paid_hash(writer['sha256'])
        and writer['mode'] == '0600' and writer['directory_mode'] == '0700'
        and type(writer['uid']) is int and writer['uid'] == 0 and type(writer['gid']) is int and writer['gid'] == 0
        and ui_prepare_int(writer['dev'], 0, 9223372036854775807) and ui_prepare_int(writer['ino'], 1, 9223372036854775807)
        and type(writer['nlink']) is int and writer['nlink'] == 1, 'UI_ACTUAL_PRIVATE_WRITER_DESCRIPTOR_WITHOUT_CONTENT')
    for name in ['window_before', 'window_final']:
        window = business[name]
        expect(type(window) is dict and set(window) == {'sha256', 'database_sha256', 'redis_sha256', 'files_sha256', 'table_count'}
            and all(paid_hash(window[key]) for key in ['sha256', 'database_sha256', 'redis_sha256', 'files_sha256'])
            and ui_prepare_int(window['table_count']), 'UI_COMPLETE_INDEPENDENT_NATIVE_WINDOW_DESCRIPTOR')
    expect(business['window_before']['table_count'] == business['window_final']['table_count'], 'UI_ORIGINAL_SCHEMA_TABLE_SET_COUNT_PRESERVED')
    verify_ui_prepare_menus(business['menus'], load(Path(context['run_dir']) / 'preparation-source-binding.json'))
    verify_fulfillment_observer({'metadata_observer': business['observer']})


def ui_prepare_public_names(context=None):
    names = ['ui-prepare-created.json', UI_PREPARE['binding'], 'ui-prepare-environment.json',
        'ui-prepare-environment-native.json', UI_PREPARE['result'], 'ui-prepare-shutdown.json', 'ui-prepare-terminal.json']
    if context is not None and task2_prepare_group(context) is not None:
        names.append('ms5-task2-target-preparation.json')
    return names


def ui_prepare_descriptor(context, name):
    expect(type(name) is str and Path(name).name == name, 'UI_FIXED_PUBLIC_DESCRIPTOR_NAME')
    path = Path(context['run_dir']) / 'evidence' / name
    expect(path.is_file() and not path.is_symlink(), 'UI_REAL_PUBLIC_DESCRIPTOR_FILE')
    raw = path.read_bytes()
    return {'path': '/run/audit/evidence/' + name, 'bytes': len(raw), 'sha256': digest(raw)}


def ui_prepare_seal_value(context, formal):
    cid = bound_created(context, UI_PREPARE['proof'], UI_PREPARE['role'])['id']
    return {'schema': 1, 'scope': 'MS4_UI_PREPARE_PREDECESSOR_HISTORY', 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'prepare_container_id': cid,
        'prepare_context': ui_prepare_descriptor(context, 'ui-prepare-context.json'),
        'fixture_volume': ui_prepare_fixture_identity(context, formal),
        'original_formal_manifest': ui_prepare_descriptor(context, 'ui-prepare-formal-manifest.json'),
        'prepare_public_files': [ui_prepare_descriptor(context, name) for name in ui_prepare_public_names(context)],
        'paid_predecessor_files': [ui_prepare_descriptor(context, name) for name in ui_prepare_prerequisite_names()]}


def verify_ui_prepare_history(context, native_required=False):
    root = Path(context['run_dir']); evidence = root / 'evidence'
    value = load(evidence / 'ui-prepare-predecessor-history.json')
    formal = load(evidence / 'ui-prepare-formal-manifest.json')
    expect(type(value) is dict and len(value) == 11 and paid_equal(value, ui_prepare_seal_value(context, formal)),
        'UI_EXACT11_COMPLETE_ACTUAL_PREPARE_HISTORY')
    expect((root / 'actual-run-context.json').read_bytes() == (evidence / 'ui-prepare-context.json').read_bytes(),
        'UI_ORIGINAL_CONTEXT_BYTES_NOT_REWRITTEN')
    if native_required:
        expect((root / 'manifest.json').read_bytes() == (evidence / 'ui-prepare-formal-manifest.json').read_bytes(),
            'UI_SAME_ORIGINAL_LIVE_FOUR_ROLE_FORMAL_BYTES')
    result = verify_ui_prepare_result(context, native_required, formal)
    expect(paid_equal(value, ui_prepare_seal_value(context, formal)), 'UI_SAME_WHOLE_SEAL_AND_ORIGINALS_AFTER_VERIFICATION')
    return result


def ui_copy_descriptor(path, public_path):
    path = Path(path)
    expect(path.is_file() and not path.is_symlink() and not getattr(path, 'is_junction', lambda: False)(), 'COPY_REGULAR_DESCRIPTOR')
    raw = path.read_bytes()
    return {'path': public_path, 'bytes': len(raw), 'sha256': digest(raw)}


def ui_copy_tree(folder):
    folder = Path(folder)
    expect(folder.is_dir() and not folder.is_symlink() and not getattr(folder, 'is_junction', lambda: False)(), 'COPY_REAL_CANONICAL_TREE')
    files = []; directories = []
    for path in sorted(folder.rglob('*'), key=lambda p: p.relative_to(folder).as_posix()):
        expect(not path.is_symlink() and not getattr(path, 'is_junction', lambda: False)()
            and (path.is_file() or path.is_dir()), 'COPY_COMPLETE_TREE_NO_LINKS')
        relative = path.relative_to(folder).as_posix()
        expect(not re.search(r'(^/|\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)', relative), 'COPY_CANONICAL_TREE_MEMBER')
        if path.is_file():
            files.append(source_row(folder, relative))
        else:
            directories.append(relative)
    return {'files': files, 'directories': directories}


def ui_copy_freeze_values(repo, sources):
    expect(type(sources) is dict and set(sources) == {'h5', 'merchant', 'admin'}, 'COPY_THREE_EXPLICIT_DIST_INPUTS')
    paths = {}
    trees = {}
    for name in ['h5', 'merchant', 'admin']:
        folder = Path(sources[name]).resolve(strict=True)
        expect(folder.is_dir() and not Path(sources[name]).is_symlink(), 'COPY_EXISTING_DIST_ROOT')
        paths[name] = folder.as_posix()
        trees[name] = ui_copy_tree(folder)
        index = [row for row in trees[name]['files'] if row['path'] == 'index.html']
        expect(len(index) == 1 and index[0]['bytes'] > 0, 'COPY_REAL_NONEMPTY_DIST_INDEX')
    expect(len(set(paths.values())) == 3, 'COPY_THREE_DIST_ROOTS_DISTINCT')
    actual = []
    for path in PREP.rglob('*'):
        expect(not path.is_symlink() and not getattr(path, 'is_junction', lambda: False)(), 'COPY_PREP_NO_LINKS')
        if path.is_file():
            actual.append(path.relative_to(PREP).as_posix())
        else:
            expect(path.is_dir() and path.relative_to(PREP).as_posix() == 'manifests', 'COPY_ONLY_FIXED_MANIFEST_DIRECTORY')
    expect(sorted(actual) == sorted(UI_COPY_ARTIFACTS), 'COPY_EXACT_REAL_FULL17_MEMBERS')
    expect(source_row(PREP, 'ms4-ui-copy.php') == {'path': 'ms4-ui-copy.php', 'bytes': 48958,
        'sha256': 'f853b34ea7d5dc10dacb47b97cf0639ac45d65acb438f4dc9269ac7e0c6efbe5'}
        and source_row(PREP, 'ms4-ui-public-copy.php') == {'path': 'ms4-ui-public-copy.php', 'bytes': 25847,
        'sha256': '0f2a7944b25db42cbf935816b4b9366a599049a28ac77e32bab167b08fec47d0'}, 'COPY_SEALED_ENTRY_LIBRARY_BYTES')
    expect(source_row(PREP, 'ms4-ui-nginx.conf') == {'path':'ms4-ui-nginx.conf','bytes':4105,
        'sha256':'033ae09499501ec9ef9a2ab87d5da53687491be06f20615781f2d5b01c4cbca5'}, 'COPY_CURRENT_BACKEND_NGINX_SOURCE_PIN')
    profile = load(PREP / 'manifests/ui-public-copy-profile.json')
    keys = ['public_source_files', 'public_source_directories', 'synthetic_png', 'builds']
    keys += [name + tail for name in ['h5', 'merchant', 'admin'] for tail in ['_dist_files', '_dist_directories']]
    expect(type(profile) is dict and set(profile) == set(keys), 'COPY_REAL_PROFILE_EXACT_TEN_KEYS')
    public = ui_copy_tree(Path(repo) / 'crmeb/public')
    expect(profile['public_source_files'] == public['files'] and profile['public_source_directories'] == public['directories'], 'COPY_WHOLE_ORIGINAL_PUBLIC_DESCRIPTOR')
    expect(set(profile['builds']) == {'h5', 'merchant', 'admin'}, 'COPY_REAL_THREE_BUILD_DESCRIPTORS')
    for name in ['h5', 'merchant', 'admin']:
        tree = trees[name]
        manifest = PREP / ('manifests/ui-' + name + '-dist.json')
        expect(load(manifest) == tree and profile[name + '_dist_files'] == tree['files']
            and profile[name + '_dist_directories'] == tree['directories']
            and profile['builds'][name] == ui_copy_descriptor(manifest, '/run/ms4-prep/manifests/ui-' + name + '-dist.json'),
            'COPY_ACTUAL_FULL_BUILD_TREE_MANIFEST')
    png = PREP / 'ms4-order.png'
    expect(profile['synthetic_png'] == ui_copy_descriptor(png, '/run/ms4-prep/ms4-order.png')
        and png.read_bytes().startswith(b'\x89PNG\r\n\x1a\n'), 'COPY_REAL_FIXED_PNG_DESCRIPTOR')
    return {'ui_copy_dist_sources': paths, 'ui_copy_dist_inventory': trees}


def verify_ui_copy_freeze(binding, repo):
    expect('ui_copy_dist_sources' in binding and 'ui_copy_dist_inventory' in binding, 'COPY_INITIAL_IMMUTABLE_DIST_FREEZE_REQUIRED')
    verify_ui_prepare_freeze(binding, repo)
    values = ui_copy_freeze_values(repo, binding['ui_copy_dist_sources'])
    expect(paid_equal(values['ui_copy_dist_sources'], binding['ui_copy_dist_sources'])
        and paid_equal(values['ui_copy_dist_inventory'], binding['ui_copy_dist_inventory'])
        and [row['path'] for row in binding['artifact_files']] == UI_COPY_ARTIFACTS,
        'COPY_ALL_REAL_DIST_BYTES_AND_ORDERED17_UNCHANGED')


def ui_copy_owned(context, facts):
    expect(set(facts) in [set(['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged','network_mode','status','command','entrypoint','workdir','restart_count','pid','exit_code','networks','ports','port_bindings','cap_drop','security_options','started_at','finished_at','created_at']),
        set(['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged','network_mode','status','command','entrypoint','workdir','restart_count','pid','exit_code','networks','ports','port_bindings','cap_drop','security_options','started_at','finished_at','created_at','run_id','source_sha','proof_name'])],
        'COPY_NATIVE26_OR_CREATED29_FIELDS')
    expect(facts['command'] == [UI_COPY['entry'], 'copy'] and facts['user'] == '0:0' and facts['stop_signal'] == 'SIGTERM'
        and type(facts['pid']) is int and type(facts['exit_code']) is int and type(facts['restart_count']) is int
        and facts['restart_count'] == 0 and type(facts['tmpfs']) is dict and set(facts['tmpfs']) == {'/tmp'}
        and set(facts['tmpfs']['/tmp'].split(',')) == {'rw','mode=0700'}, 'COPY_EXACT_COMMAND_USER_TMPFS')
    if 'proof_name' in facts:
        expect(facts['proof_name'] == UI_COPY['proof'] and facts['run_id'] == context['run_id']
            and facts['source_sha'] == context['source_sha'], 'COPY_REAL_CREATED_IDENTITY')
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    expected = {'/workspace': ('bind', context['repo_dir'], False), '/source': ('bind', context['repo_dir'], False),
        '/run/ms4-prep': ('bind', context['prep_dir'], False), '/run/audit': ('bind', context['run_dir'], True),
        '/workspace/crmeb/public': ('volume', context['project'] + '_ui-public', True),
        '/workspace/crmeb/public/uploads/store/comment': ('volume', context['project'] + '_ui-comments', True),
        '/workspace/crmeb/public/uploads/customer': ('volume', context['project'] + '_ui-customer-assets', True)}
    expected.update({'/run/ms4-ui-dist/' + name: ('bind', path, False) for name, path in frozen['ui_copy_dist_sources'].items()})
    mounts = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    expect(len(mounts) == 10 and len({row['Destination'] for row in mounts}) == 10
        and {row['Destination'] for row in mounts} == set(expected), 'COPY_EXACT_SEVEN_BINDS_THREE_VOLUMES')
    expect(all(row['Destination'] == '/tmp' and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'COPY_ONLY_DECLARED_PRIVATE_TMPFS')
    for row in mounts:
        kind, source, rw = expected[row['Destination']]
        expect(row['Type'] == kind and row['RW'] is rw
            and (canonical_host(row['Source']) == canonical_host(source) if kind == 'bind' else row['Name'] == source), 'COPY_EXACT_ALL_MOUNT_IDENTITIES')
    net = facts['networks'][context['initial_network']['Name']]
    expect(net.get('NetworkID') in ['', context['network_id']], 'COPY_ORIGINAL_NETWORK_ID')
    if facts['status'] == 'running':
        expect(net.get('NetworkID') == context['network_id'], 'COPY_ACTUAL_RUNNING_NETWORK_ID')
    expect(not set(net.get('Aliases') or []).intersection({'audit-app', 'audit-ms4-ui-backend', 'audit-ms4-ui-gateway', 'audit-mysql', 'audit-redis'}), 'COPY_NO_PRIVATE_OR_BACKEND_ALIASES')


def ui_copy_expected_materials(context):
    profile = load(PREP / 'manifests/ui-public-copy-profile.json'); files = {}; directories = set()
    def add_file(path, row):
        expect(path not in files and path not in directories, 'COPY_FULL_MATERIAL_UNION_NO_COLLISION')
        files[path] = {'path':path,'bytes':row['bytes'],'sha256':row['sha256']}
    def add_dir(path):
        expect(path not in files, 'COPY_DIRECTORY_FILE_DISJOINT')
        directories.add(path)
    for row in profile['public_source_files']:
        expect(row['path'] not in ['install.lock','.env'] and not row['path'].startswith(('__ms4_assets/','__ms4_fixture__/')), 'COPY_NO_RESERVED_OR_MARKER_SOURCE')
        add_file(row['path'], row)
    for path in profile['public_source_directories']:
        add_dir(path)
    for name in ['h5','merchant','admin']:
        add_dir('__ms4_assets/' + name)
        for path in profile[name + '_dist_directories']:
            add_dir('__ms4_assets/' + name + '/' + path)
        for row in profile[name + '_dist_files']:
            add_file('__ms4_assets/' + name + '/' + row['path'], row)
    add_file('__ms4_fixture__/product.png', profile['synthetic_png'])
    marker = ('# AUDIT-RUN ' + context['run_id'] + '\n').encode()
    add_file('install.lock', {'bytes':len(marker),'sha256':digest(marker)})
    for path in ['uploads','uploads/customer','uploads/store','uploads/store/comment']:
        add_dir(path)
    for path in list(files) + list(directories):
        parent = Path(path).parent.as_posix()
        while parent != '.':
            add_dir(parent); parent = Path(parent).parent.as_posix()
    return {'schema':1,'scope':'MS4_UI_PUBLIC_MATERIALS_COMPLETE','run_id':context['run_id'],'source_sha':context['source_sha'],
        'freeze_sha256':context['freeze_sha256'],'files':[files[key] for key in sorted(files)],
        'directories':sorted(directories),'builds':profile['builds']}


def ui_web_port_argv(port):
    expect(type(port) is int and 1024 <= port <= 65535, 'WEB_EXPLICIT_REAL_PORT_RANGE')
    script = "$ErrorActionPreference='Stop'; $rows=@(Get-NetTCPConnection -State Listen | Where-Object { $_.LocalPort -eq " + str(port) + " } | Select-Object LocalAddress,LocalPort,OwningProcess); ConvertTo-Json -InputObject $rows -Compress"
    return ['powershell.exe','-NoProfile','-NonInteractive','-Command',script]


def ui_web_port_rows(raw, port):
    rows = json.loads(raw.decode('utf-8-sig'))
    expect(type(rows) is list and all(type(row) is dict and set(row) == {'LocalAddress','LocalPort','OwningProcess'}
        and type(row['LocalAddress']) is str and type(row['LocalPort']) is int and row['LocalPort'] == port
        and type(row['OwningProcess']) is int and row['OwningProcess'] > 0 for row in rows), 'WEB_ACTUAL_OS_PORT_ROWS')
    return rows


def verify_ui_web_freeze(frozen, repo):
    verify_ui_copy_freeze(frozen, repo)
    port = frozen.get('ui_web_port'); argv = ui_web_port_argv(port)
    expect(frozen.get('ui_web_origin') == 'http://127.0.0.1:' + str(port), 'WEB_SAME_SINGLE_FROZEN_ORIGIN')
    observation = frozen.get('ui_web_initial_port_observation')
    expect(type(observation) is dict and set(observation) == {'native','stdout_base64'}, 'WEB_ORIGINAL_PORT_OBSERVATION_SHAPE')
    receipt = observation['native']; raw = base64.b64decode(observation['stdout_base64'], validate=True)
    expect(set(receipt) == set(UI_WEB_NATIVE_RECEIPT_KEYS) and receipt['label'] == 'ui-web-initial-port'
        and receipt['argv'] == argv and type(receipt['pid']) is int and receipt['pid'] > 0
        and type(receipt['native_exit']) is int and receipt['native_exit'] == receipt['wait_returncode'] == 0
        and receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == digest(raw)
        and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == digest(b'')
        and ui_web_port_rows(raw,port) == [], 'WEB_FROZEN_ORIGINAL_REAL_FREE_PORT_RECEIPT')
    return port


def ui_web_created(context, proof):
    spec = UI_WEB_BACKEND if proof == UI_WEB_BACKEND['proof'] else UI_WEB_GATEWAY if proof == UI_WEB_GATEWAY['proof'] else UI_WEB_REGISTER
    expect(proof == spec['proof'],'WEB_FINITE_PROOF')
    value = load(Path(context['run_dir']) / 'evidence' / (proof + '-created.json'))
    expect(set(value) == set(UI_WEB_NATIVE_KEYS) | {'run_id','source_sha','proof_name'}
        and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
        and value['proof_name'] == proof and value['labels']['com.docker.compose.service'] == spec['role'], 'WEB_BOUND_CREATED_IDENTITY')
    return value


def ui_web_mounts(context, facts, role):
    expected = {}
    if role != UI_WEB_GATEWAY['role']:
        expected = {'/workspace':('bind',context['repo_dir'],False),'/source':('bind',context['repo_dir'],False),
            '/run/ms4-prep':('bind',context['prep_dir'],False),'/run/audit':('bind',context['run_dir'],role == UI_WEB_REGISTER['role'])}
    if role == UI_WEB_BACKEND['role']:
        frozen_raw = t2z_read(Path(context['run_dir']) / 'preparation-source-binding.json')
        require(sha(frozen_raw) == context['freeze_sha256'], 'T2Z_WEB_ORIGINAL_FREEZE_BYTES')
        frozen = json.loads(frozen_raw)
        task2_observer_zero(context)
        if task2_browser_group(frozen) is not None:
            expected[T2Z_BROWSER_TARGET] = ('bind', (ARTIFACTS.parent / 'browser-v1').as_posix(), False)
        expected['/workspace/crmeb/public/install/crmeb.sql'] = ('bind',context['repo_dir'] + '/crmeb/public/install/crmeb.sql',False)
        for suffix,path,rw in [('app-secrets','/run/audit-app',False),('fixture-secrets','/run/audit-fixtures',False),
            ('customer-form-evidence',CUSTOMER_FORM_PRIVATE_ROOT,True),
            ('aftersale-evidence','/var/lib/crmeb-aftersale-evidence',True),('ui-public','/workspace/crmeb/public',False),
            ('ui-comments','/workspace/crmeb/public/uploads/store/comment',True),
            ('ui-customer-assets','/workspace/crmeb/public/uploads/customer',True),('ui-control','/run/audit-ui-control',True)]:
            expected[path] = ('volume',context['project'] + '_' + suffix,rw)
    elif role == UI_WEB_GATEWAY['role']:
        expected = {'/srv/public':('volume',context['project'] + '_ui-public',False),
            '/srv/public/uploads/store/comment':('volume',context['project'] + '_ui-comments',False),
            '/srv/public/uploads/customer':('volume',context['project'] + '_ui-customer-assets',False),
            '/etc/nginx/nginx.conf':('bind',context['prep_dir'] + '/ms4-ui-nginx.conf',False)}
    else:
        expect(role == UI_WEB_REGISTER['role'],'WEB_FINITE_HELPER_MOUNT_ROLE')
        for suffix,path,rw in [('bootstrap-secrets','/run/audit-bootstrap',False),('app-secrets','/run/audit-app',False),('ui-control','/run/audit-ui-control',True)]:
            expected[path] = ('volume',context['project'] + '_' + suffix,rw)
    actual = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    expect(len(actual) == len(expected) and {row['Destination'] for row in actual} == set(expected), 'WEB_EXACT_NATIVE_MOUNT_MULTISET')
    for row in actual:
        kind,source,rw = expected[row['Destination']]
        expect(row['Type'] == kind and row['RW'] is rw and (canonical_host(row['Source']) == canonical_host(source)
            if kind == 'bind' else row['Name'] == source),'WEB_REAL_SOURCE_VOLUME_AND_ACCESS')
    paths = {'/tmp','/workspace/crmeb/runtime'} if role == UI_WEB_BACKEND['role'] else {'/tmp'}
    expect(type(facts['tmpfs']) is dict and set(facts['tmpfs']) == paths
        and all(type(v) is str and set(v.split(',')) == {'rw','mode=0700'} for v in facts['tmpfs'].values())
        and all(row['Destination'] in paths and row['RW'] is True for row in facts['mounts'] if row['Type'] == 'tmpfs'), 'WEB_ONLY_PRIVATE0700_TMPFS')


def ui_web_owned(context, facts, role, gateway_dual=True):
    expect(set(facts) in [set(UI_WEB_NATIVE_KEYS),set(UI_WEB_NATIVE_KEYS) | {'run_id','source_sha','proof_name'}]
        and role in [UI_WEB_BACKEND['role'],UI_WEB_GATEWAY['role'],UI_WEB_REGISTER['role']]
        and type(facts['pid']) is int and type(facts['exit_code']) is int and type(facts['restart_count']) is int
        and facts['restart_count'] == 0 and facts['read_only'] is True and facts['privileged'] is False
        and facts['cap_drop'] == ['ALL'] and facts['security_options'] in [['no-new-privileges'],['no-new-privileges:true']], 'WEB_FINITE_NATIVE_ROLE_PRIVILEGES')
    expect(facts['labels'].get('tianli.audit') == '1' and facts['labels'].get('tianli.audit.run') == context['run_id']
        and facts['labels'].get('com.docker.compose.project') == context['project']
        and facts['labels'].get('com.docker.compose.service') == role,'WEB_EXACT_NATIVE_OWN_LABELS')
    internal = context['initial_network']['Name']; frontend = context['project'] + '_frontend'
    expect(facts['network_mode'] == internal and set(facts['networks']) == ({internal,frontend}
        if role == UI_WEB_GATEWAY['role'] and gateway_dual else {internal}), 'WEB_EXACT_NATIVE_NETWORK_NAMES_AND_MODE')
    net = facts['networks'][internal]
    expect(net.get('NetworkID') in ['',context['network_id']], 'WEB_CREATED_INTERNAL_ID')
    if facts['status'] == 'running':
        expect(net.get('NetworkID') == context['network_id'],'WEB_RUNNING_REAL_INTERNAL_ID')
    if role == UI_WEB_GATEWAY['role']:
        expect(facts['image'] == UI_WEB_NGINX and facts['user'] == '101:101' and facts['entrypoint'] == ['nginx']
            and facts['command'] == ['-g','daemon off;','-c','/etc/nginx/nginx.conf'] and facts['workdir'] == '/', 'WEB_REAL_NGINX_SOURCE_COMMAND')
        frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json'); port = verify_ui_web_freeze(frozen,context['repo_dir'])
        binding = {'8080/tcp':[{'HostIp':'127.0.0.1','HostPort':str(port)}]}
        expect(facts['port_bindings'] == binding and (facts['ports'] is None or type(facts['ports']) is dict
            and set(facts['ports']) <= {'80/tcp','8080/tcp'} and facts['ports'].get('80/tcp') is None
            and facts['ports'].get('8080/tcp') in [None,binding['8080/tcp']]),'WEB_ONLY_FROZEN_LOOPBACK_PORT')
        if facts['status'] == 'running':
            expect(facts['ports'].get('8080/tcp') == binding['8080/tcp'],'WEB_REAL_RUNNING_PUBLISHED_PORT')
    else:
        spec = UI_WEB_BACKEND if role == UI_WEB_BACKEND['role'] else UI_WEB_REGISTER
        command = [spec['entry'],'serve'] if role == UI_WEB_BACKEND['role'] else ['/run/ms4-prep/ms4-initialize.php','register']
        expect(facts['image'] == PHP and facts['user'] == '0:0' and facts['entrypoint'] == ['php']
            and facts['command'] == command and facts['workdir'] == '/workspace' and facts['stop_signal'] == 'SIGTERM'
            and facts['port_bindings'] in [None,{}] and all(value in [None,[]] for value in (facts['ports'] or {}).values()),'WEB_PHP_SOURCE_ENTRY_NO_HOST_PORT')
        aliases = net.get('Aliases') or []
        expect('audit-app' not in aliases and (aliases.count(UI_WEB_BACKEND['role']) == 1 if role == UI_WEB_BACKEND['role'] else UI_WEB_BACKEND['role'] not in aliases),'WEB_ONLY_OWN_BACKEND_ALIAS')
    ui_web_mounts(context,facts,role)


def ui_web_group(value):
    expect(type(value)is dict and set(value)=={'leader_pid','pgid','sid','leader_starttime'}
        and type(value['leader_pid'])is int and value['leader_pid']>1 and value['pgid']==value['sid']==value['leader_pid']
        and type(value['leader_starttime'])is str and value['leader_starttime'].isdigit(),'WEB_REAL_REGISTERED_CHILD_GROUP')


def ui_web_child(child):
    expect(type(child)is dict and set(child)=={'stopped','group','remaining_members','listener_open','stop_signal','term_attempted','native_child','native_proc_close','normal_only','first_terminal','waits','streams'}
        and child['stopped']is True and child['remaining_members']==[] and child['listener_open']is False
        and type(child['stop_signal'])is int and child['stop_signal']==15 and child['term_attempted']is True
        and child['normal_only']is True and type(child['native_proc_close'])is int and child['native_proc_close']in[-1,0],'WEB_SAME_CHILD_ACTUAL12_NORMAL_TERM_ONLY')
    ui_web_group(child['group']);leader=child['group']['leader_pid']
    def terminal(status):
        expect(type(status)is dict and set(status)=={'pid','running','signaled','stopped','exitcode','termsig','stopsig'}
            and status['pid']==leader and status['running']is False and status['stopped']is False and status['stopsig']==0
            and type(status['exitcode'])is int and status['exitcode']in[-1,0] and type(status['termsig'])is int and status['termsig']in[0,15]
            and type(status['signaled'])is bool,'WEB_REAL_DIRECT_CHILD_TERMINAL')
    terminal(child['native_child'])
    if child['first_terminal']is not None:terminal(child['first_terminal'])
    expect(type(child['waits'])is list,'WEB_ACTUAL_GROUP_WAITS_LIST')
    for wait in child['waits']:
        expect(type(wait)is dict and set(wait)=={'pid','raw_status','exited','exit_status','signaled','term_signal'}
            and type(wait['pid'])is int and wait['pid']>1 and type(wait['raw_status'])is int
            and ((wait['exited']is True and wait['exit_status']==0 and wait['signaled']is False)
                or(wait['signaled']is True and type(wait['term_signal'])is int and wait['term_signal']==15)),'WEB_REAL_NORMAL_GROUP_WAIT')
    expect(type(child['streams'])is list and len(child['streams'])==2,'WEB_TWO_PRIVATE_STREAM_DESCRIPTORS')
    for stream,path in zip(child['streams'],['/workspace/crmeb/runtime/ms4-ui-backend.stdout.log','/workspace/crmeb/runtime/ms4-ui-backend.stderr.log']):
        expect(set(stream)=={'path','bytes','sha256'} and stream['path']==path and type(stream['bytes'])is int and stream['bytes']>=0
            and re.fullmatch('[a-f0-9]{64}',stream['sha256']),'WEB_REAL_PRIVATE_STREAM_HASH_ONLY_NO_LOG_CONTENT')


def ui_web_control_shape(context,name,value):
    e=Path(context['run_dir'])/'evidence';original=ui_web_created(context,UI_WEB_BACKEND['proof']);binding=load(e/UI_WEB_BACKEND['binding'])
    expect(value.get('schema')==1 and type(value.get('schema'))is int and all(value.get(k)==context[k]for k in ['run_id','source_sha','freeze_sha256'])
        and value.get('container_id')==original['id'],'WEB_REAL_CONTROL_RUN_CID_FREEZE')
    if name=='ui-backend-ready.json':
        expect(set(value)=={'schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','source_binding_sha256','prepare_history_sha256','generation','supervisor','group','members','listener_open','actor_ids','writer','full_source_verified'}
            and value['scope']=='MS4_UI_BACKEND_READY' and value['native_created_sha256']==binding['native_created_sha256']
            and value['source_binding_sha256']==digest((e/UI_WEB_BACKEND['binding']).read_bytes())
            and value['prepare_history_sha256']==binding['history']['sha256'] and re.fullmatch('[a-f0-9]{48}',value['generation'])
            and value['listener_open']is True and value['full_source_verified']is True,'WEB_ACTUAL_READY17_COMPLETE_BINDING')
        ui_web_group(value['group']);supervisor=value['supervisor']
        expect(type(supervisor)is dict and set(supervisor)=={'pid','ppid','pgid','sid','starttime','state'}
            and supervisor['pid']==1 and type(supervisor['ppid'])is int and type(supervisor['pgid'])is int and type(supervisor['sid'])is int
            and type(supervisor['starttime'])is str and supervisor['starttime'].isdigit() and type(supervisor['state'])is str,'WEB_REAL_SUPERVISOR_PROC_STAT')
        members=value['members'];expect(type(members)is list and members!=[],'WEB_ACTUAL_LIVE_REGISTERED_MEMBERS')
        for member in members:
            expect(type(member)is dict and set(member)=={'pid','ppid','pgid','sid','starttime','state','redis_sockets'}
                and type(member['pid'])is int and member['pid']>1 and member['pgid']==value['group']['pgid'] and member['sid']==value['group']['sid']
                and type(member['starttime'])is str and member['starttime'].isdigit() and member['state']not in['Z','X','x']
                and type(member['redis_sockets'])is list,'WEB_REAL_READY_GROUP_MEMBER')
            for socket in member['redis_sockets']:
                expect(set(socket)=={'local_port','inode','remote_port'}and socket['remote_port']==6379
                    and type(socket['local_port'])is int and type(socket['inode'])is str and socket['inode'].isdigit(),'WEB_REAL_MEMBER_SOCKET_METADATA_ONLY')
        prepared=load(e/'ms4-ui-prepare.json')['business']
        expect(value['actor_ids']==prepared['actor_ids'] and value['writer']==prepared['writer'] and set(value['writer'])==set(UI_WEB_WRITER_KEYS),'WEB_READY_MATCHES_REAL_PREPARE_ACTORS_WRITER')
    elif name=='ui-backend-stop-request.json':
        ready=load(e/'ui-web-control-ui-backend-ready.json')
        expect(set(value)=={'schema','scope','run_id','source_sha','freeze_sha256','container_id','ready_sha256','generation','action'}
            and value['scope']=='MS4_UI_BACKEND_NORMAL_STOP_REQUEST' and value['action']=='normal-stop'
            and value['generation']==ready['generation'] and value['ready_sha256']==digest((e/'ui-web-control-ui-backend-ready.json').read_bytes()),'WEB_SAME_READY_NORMAL_STOP9')
    elif name=='ms4-ui-backend.json':
        expect(set(value)==set(UI_WEB_RESULT_KEYS) and value['scope']=='NORMAL_MS4_UI_BACKEND_LIFECYCLE_ONLY'
            and value['status']=='VERIFIED_NORMAL_MS4_UI_BACKEND_CLOSED_ONLY' and value['phase']=='final_source'
            and type(value['entry_return_code'])is int and value['entry_return_code']==0 and value['termination']=='NORMAL_RETURN'
            and value['native_created_sha256']==binding['native_created_sha256'] and value['source_binding_sha256']==digest((e/UI_WEB_BACKEND['binding']).read_bytes())
            and value['environment_sha256']==binding['environment']['sha256'] and value['environment_native_sha256']==binding['environment_native']['sha256']
            and value['prepare_history_sha256']==binding['history']['sha256'] and value['ready_sha256']==digest((e/'ui-web-control-ui-backend-ready.json').read_bytes())
            and value['native_exit']is None and value['native_exit_observation']=='HOST_NATIVE_REQUIRED' and value['full_source_unchanged']is True
            and value['diagnostic']is None and value['stop_kind']=='normal-stop-request' and type(value['assertions'])is int and value['assertions']>0
            and value['full_acceptance']=='INCOMPLETE' and all(value[k]=='NOT RUN'for k in['three_end_login_DOM','business_acceptance','stopped_samples']),'WEB_COMPLETE_RESULT27_RAW_NATIVE_NOT_REWRITTEN')
        ui_web_child(value['child'])
    else:
        expect(name=='ui-backend-shutdown.json' and set(value)==set(UI_WEB_SHUTDOWN_KEYS) and value['scope']=='MS4_UI_BACKEND_SHUTDOWN'
            and value['phase']=='final_source' and value['completed']is True and value['result_published']is True
            and value['fatal']is False and value['termination_requested']is False and value['primary']is None and value['cleanup']is None
            and value['source_unchanged']is True,'WEB_COMPLETE_SHUTDOWN15_NO_FATAL')
        ui_web_child(value['child'])
        expect(value['child']==load(e/'ui-web-control-ms4-ui-backend.json')['child'],'WEB_SHUTDOWN_SAME_ORIGINAL_CLOSED_CHILD')


def ui_web_gateway_transition(context,before,after):
    expect(all(before[k]==after[k]for k in IMMUTABLE_FIELDS if k!='mounts')
        and sorted(json.dumps(v,sort_keys=True)for v in before['mounts'])==sorted(json.dumps(v,sort_keys=True)for v in after['mounts'])
        and before['restart_count']==after['restart_count']==0,'WEB_SAME_GATEWAY_ALL_IMMUTABLE_AND_MOUNTS')
    internal=context['initial_network']['Name'];frontend=context['project']+'_frontend'
    expect(set(before['networks'])=={internal}and set(after['networks'])=={internal,frontend}
        and before['networks'][internal]==after['networks'][internal],'WEB_ONLY_ADDED_REAL_FRONTEND_ORIGINAL_INTERNAL_OBJECT_UNCHANGED')


def ui_web_public_audit(context):
    return {'AUDIT_ISOLATED':'1','AUDIT_RUN_ID':context['run_id'],'AUDIT_SOURCE_SHA':context['source_sha'],
        'AUDIT_PROJECT':context['project'],'AUDIT_REPO_DIR':'/workspace','AUDIT_RUN_DIR':'/run/audit',
        'AUDIT_GUARD_MODE':'read-only','MS4_UI_BACKEND_PROOF_NAME':'ui-backend'}


def verify_ui_prepare_result(context, native_required=False, saved_formal=None):
    require(native_required is False, 'UI_ZERO_PREPARE_OFFLINE_ONLY')
    evidence = Path(context['run_dir']) / 'evidence'
    before = ui_prepare_prerequisite_hashes(context, native_required)
    facts = normal_terminal(context, UI_PREPARE['proof'], UI_PREPARE['role'], native_required)
    ui_prepare_owned(context, facts)
    binding = verify_ui_prepare_binding(context, facts['id'], native_required)
    verify_ui_prepare_helper(context, 'register-ui-prepare', 'REGISTERED_NATIVE_RESOURCES_ONLY')
    formal = load(Path(context['run_dir']) / 'manifest.json') if saved_formal is None else saved_formal
    fulfillment_expected_formal(context, formal, UI_PREPARE['role'], UI_PREPARE['proof'])
    result = load(evidence / UI_PREPARE['result'])
    expect(type(result) is dict and set(result) == set(UI_PREPARE_RESULT_KEYS)
        and type(result['schema']) is int and result['schema'] == 1
        and result['scope'] == UI_PREPARE['result_scope'] and result['status'] == UI_PREPARE['status']
        and all(result[key] == context[key] for key in ['run_id', 'source_sha', 'freeze_sha256'])
        and result['container_id'] == facts['id'] and result['phase'] == 'final_source'
        and type(result['entry_return_code']) is int and result['entry_return_code'] == 0 and result['termination'] == 'NORMAL_RETURN'
        and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and result['diagnostic'] is None
        and type(result['assertions']) is int and result['assertions'] > 0 and result['full_acceptance'] == 'INCOMPLETE'
        and all(result[key] == 'NOT RUN' for key in ['three_end_login_DOM', 'N1_fulfillment_review_business', 'stopped_samples'])
        and result['native_created_sha256'] == digest((evidence / 'ui-prepare-created.json').read_bytes())
        and result['source_binding_sha256'] == digest((evidence / UI_PREPARE['binding']).read_bytes())
        and result['environment_sha256'] == digest((evidence / 'ui-prepare-environment.json').read_bytes())
        and result['environment_native_sha256'] == digest((evidence / 'ui-prepare-environment-native.json').read_bytes()),
        'UI_REAL25_RESULT_DISTINCT_FROM_OUTER_NATIVE0_AND_BUSINESS_ACCEPTANCE')
    verify_ui_prepare_business(context, result['business'])
    verify_task2_prepare(context, result)
    child = result['child']; verify_aftersale_review_child(child)
    expect(type(child['term_attempted']) is bool
        and (child['stop_signal'] is None or type(child['stop_signal']) is int and child['stop_signal'] == 15)
        and (child['stop_signal'] is None or child['term_attempted'] is True)
        and all(type(child['first_terminal'][key]) is int for key in ['exitcode', 'termsig', 'stopsig'])
        and child['first_terminal']['termsig'] != 9 and type(child['proc_close_exit']) is int,
        'UI_SAME_CHILD_NORMAL_TERM_COMPLETE_REAL_TERMINAL_STREAMS')
    shutdown = load(evidence / 'ui-prepare-shutdown.json')
    expect(type(shutdown) is dict and set(shutdown) == {'schema', 'scope', 'run_id', 'source_sha', 'container_id',
        'freeze_sha256', 'phase', 'completed', 'result_published', 'fatal', 'termination_requested', 'primary_failure',
        'cleanup_failure', 'native_exit', 'native_exit_observation'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['scope'] == 'MS4_UI_PREPARE_SHUTDOWN'
        and all(shutdown[key] == result[key] for key in ['run_id', 'source_sha', 'container_id', 'freeze_sha256', 'phase'])
        and shutdown['completed'] is True and shutdown['result_published'] is True and shutdown['fatal'] is False
        and shutdown['termination_requested'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and shutdown['native_exit'] is None and shutdown['native_exit_observation'] == 'HOST_NATIVE_REQUIRED',
        'UI_EXACT15_REAL_NORMAL_SHUTDOWN')
    expect(paid_equal(before, ui_prepare_prerequisite_hashes(context, native_required))
        and paid_equal(before, binding['prerequisites_sha256']), 'UI_WHOLE39_ORIGINAL_BYTES_BEFORE_AFTER_RESULT')
    return result


UIZ_STOP_PHP_SOURCE = '$fail=static function(bool $ok):void{if(!$ok)throw new RuntimeException(\'UI_STOP_CONTRACT\');};\n$fail(PHP_SAPI===\'cli\'&&PHP_INT_SIZE>=8&&posix_geteuid()===0&&gethostname()===substr($argv[2]??\'\',0,12));\n$d=\'/run/audit-ui-control\';$s=lstat($d);$fail(is_array($s)&&!is_link($d)&&realpath($d)===$d&&is_dir($d)&&($s[\'mode\']&07777)===0700&&$s[\'uid\']===0);\n$raw=base64_decode($argv[1]??\'\',true);$fail(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=16384);$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);\n$keys=array_keys($v);sort($keys,SORT_STRING);$want=[\'schema\',\'scope\',\'run_id\',\'source_sha\',\'freeze_sha256\',\'container_id\',\'ready_sha256\',\'generation\',\'action\'];sort($want,SORT_STRING);\n$fail($keys===$want&&$v[\'schema\']===1&&$v[\'scope\']===\'MS4_UI_BACKEND_NORMAL_STOP_REQUEST\'&&$v[\'action\']===\'normal-stop\'&&$v[\'container_id\']===$argv[2]&&$v[\'run_id\']===getenv(\'AUDIT_RUN_ID\')&&$v[\'source_sha\']===getenv(\'AUDIT_SOURCE_SHA\'));\n$r=$d.\'/ui-backend-ready.json\';$rs=lstat($r);$fail(is_array($rs)&&is_file($r)&&!is_link($r)&&($rs[\'mode\']&07777)===0600&&$rs[\'nlink\']===1&&$rs[\'uid\']===0&&hash_file(\'sha256\',$r)===$v[\'ready_sha256\']);\n$ready=json_decode(file_get_contents($r),true,32,JSON_THROW_ON_ERROR);$fail($ready[\'generation\']===$v[\'generation\']&&$ready[\'container_id\']===$v[\'container_id\']&&$ready[\'freeze_sha256\']===$v[\'freeze_sha256\']);\n$parent=static function()use($ready,$fail):void{$one=file_get_contents(\'/proc/1/stat\');$end=is_string($one)?strrpos($one,\')\'):false;$fail(is_string($one)&&str_starts_with($one,\'1 (\')&&$end!==false);$fields=preg_split(\'/\\s+/\',trim(substr($one,$end+1)));$fail(is_array($fields)&&count($fields)>=20&&$fields[19]===$ready[\'supervisor\'][\'starttime\']&&(int)$fields[2]===$ready[\'supervisor\'][\'pgid\']&&(int)$fields[3]===$ready[\'supervisor\'][\'sid\']);};$parent();\n$p=$d.\'/ui-backend-stop-request.json\';$pending=$d.\'/ui-backend-stop-request.pending\';$fail(!file_exists($p)&&!is_link($p)&&!file_exists($pending)&&!is_link($pending));umask(0077);$f=fopen($pending,\'xb\');$fail(is_resource($f));\ntry{$fail(fwrite($f,$raw)===strlen($raw)&&fflush($f)&&fsync($f));$fs=fstat($f);$ps=lstat($pending);$fail(is_array($fs)&&is_array($ps)&&$fs[\'dev\']===$ps[\'dev\']&&$fs[\'ino\']===$ps[\'ino\']&&($fs[\'mode\']&07777)===0600&&$fs[\'nlink\']===1&&$fs[\'uid\']===0);}finally{$fail(fclose($f));}\n$fail(file_get_contents($pending)===$raw);$parent();clearstatcache(true,$d);$ds=lstat($d);$fail(is_array($ds)&&!is_link($d)&&realpath($d)===$d&&$ds[\'dev\']===$s[\'dev\']&&$ds[\'ino\']===$s[\'ino\']&&$ds[\'uid\']===0&&($ds[\'mode\']&07777)===0700);\n$fail(!file_exists($p)&&!is_link($p));$fail(rename($pending,$p));clearstatcache(true,$p);$final=lstat($p);$fail(is_array($final)&&!is_link($p)&&$final[\'dev\']===$fs[\'dev\']&&$final[\'ino\']===$fs[\'ino\']&&($final[\'mode\']&07777)===0600&&$final[\'nlink\']===1&&$final[\'uid\']===0&&!file_exists($pending)&&file_get_contents($p)===$raw);echo "STOP_REQUEST_PUBLISHED\\n";'


def ui_fixed_sources(context):
    proof_ini_zero(context)
    task2_observer_zero(context)
    require(sha(ui_zero_file(HOST)) == UI_HOST_SHA256 and sha(ui_zero_file(CLOSE)) == UI_CLOSE_SHA256, 'EXACT_REVIEWED_PAID_HOST_CLOSE_SOURCES')
    interface_raw = ui_zero_file(INTERFACE)
    require(len(interface_raw) == UI_INTERFACE_BYTES and sha(interface_raw) == UI_INTERFACE_SHA256, 'EXACT_ADOPTED_COMPLETE_INTERFACE_SOURCE_BYTES')
    frozen_raw = ui_zero_file(Path(context['run_dir']) / 'preparation-source-binding.json')
    require(sha(frozen_raw) == context['freeze_sha256'], 'EXACT_ORIGINAL_SOURCE_FREEZE')
    frozen = json.loads(frozen_raw)
    require(type(frozen['schema']) is int and frozen['schema'] == 1 and frozen['status'] == 'CLEAN_SOURCE_FROZEN_ONLY'
            and frozen['runtime_SQL_App'] == 'NOT RUN' and frozen['source_sha'] == context['source_sha']
            and frozen['requested_repo_source'] == context['repo_dir'], 'ORIGINAL_FROZEN_SOURCE_IDENTITY')
    require(frozen['customer_source_roots'] == SOURCE_ROOTS and frozen['paid_source_roots'] == SOURCE_ROOTS
            and frozen['customer_source_extra'] == sorted(SOURCE_EXTRA)
            and frozen['paid_source_extra'] == sorted(SOURCE_EXTRA + [PAID_ENTRY]), 'EXACT_CUSTOMER_NINE_AND_PAID_TEN_EXTRA')
    require([row['path'] for row in frozen['driver_source_files']] == PROFILE_DRIVER
            and [row['path'] for row in frozen['preparation_source_references']] == PROFILE_REFERENCES
            and [row['path'] for row in frozen['artifact_files']] == UI_COPY_ARTIFACTS, 'UI_EXACT_TEN_DRIVER_FOUR_REFERENCES_SEVENTEEN_ARTIFACTS')
    require(CUSTOMER_FORM_SOURCE_BINDING_STATUS == 'BOUND_FROZEN_PHP_SOURCE', 'ZERO_FROZEN_CU_FORM_PHP_SOURCE_REQUIRED')
    for row in CUSTOMER_FORM_SOURCE_FILES:
        require(source_row(context['repo_dir'], row['path']) == row, 'ZERO_SEVEN_FROZEN_CU_FORM_PHP_SOURCE_BYTES')
    paths = set(SOURCE_EXTRA)
    for relative in SOURCE_ROOTS:
        folder = Path(context['repo_dir']) / relative
        require(folder.is_dir() and not folder.is_symlink(), 'ORIGINAL_COMPLETE_SOURCE_ROOT')
        for path in folder.rglob('*'):
            require(not path.is_symlink(), 'ORIGINAL_SOURCE_SET_NO_SYMLINK')
            if path.is_file():
                paths.add(path.relative_to(context['repo_dir']).as_posix())
    require(sorted(paths) == [row['path'] for row in frozen['customer_source_files']]
            and sorted(paths | {PAID_ENTRY}) == [row['path'] for row in frozen['paid_source_files']]
            and [row for row in frozen['paid_source_files'] if row['path'] != PAID_ENTRY] == frozen['customer_source_files'],
            'COMPLETE_ORIGINAL_CUSTOMER_AND_DISTINCT_PAID_SOURCE_SETS')
    require(next(row for row in frozen['paid_source_files'] if row['path'] == PAID_ENTRY)
            == {'path': PAID_ENTRY, 'bytes': PAID_ENTRY_BYTES, 'sha256': PAID_ENTRY_SHA256}, 'EXACT_ADOPTED_PAID_ENTRY_SOURCE_BYTES')
    require(frozen['fulfillment_source_roots'] == SOURCE_ROOTS and frozen['fulfillment_source_extra'] == fulfillment_extra()
        and [row['path'] for row in frozen['fulfillment_source_files']] == sorted(paths | set(fulfillment_extra()))
        and next(row for row in frozen['fulfillment_source_files'] if row['path'] == FULFILLMENT_ENTRY)
            == {'path': FULFILLMENT_ENTRY, 'bytes': FULFILLMENT_ENTRY_BYTES, 'sha256': FULFILLMENT_ENTRY_SHA256}, 'COMPLETE_FULFILLMENT_SOURCE_CLOSURE_AND_REAL_ENTRY')
    require(frozen['aftersale_review_source_roots'] == SOURCE_ROOTS
        and frozen['aftersale_review_source_extra'] == aftersale_review_extra()
        and [row['path'] for row in frozen['aftersale_review_source_files']] == aftersale_review_paths(context['repo_dir'])
        and [row for row in frozen['aftersale_review_source_files'] if row['path'] in {item['path'] for item in frozen['fulfillment_source_files']}]
            == frozen['fulfillment_source_files'], 'COMPLETE_ORIGINAL_FOUR_ROOTS_AND_EXACT_NEW_TWENTY_EIGHT_SOURCE_EXTRAS')
    require(next(row for row in frozen['aftersale_review_source_files'] if row['path'] == AFTERSALE_REVIEW_ENTRY)
        == {'path': AFTERSALE_REVIEW_ENTRY, 'bytes': AFTERSALE_REVIEW_ENTRY_BYTES, 'sha256': AFTERSALE_REVIEW_ENTRY_SHA256},
        'EXACT_ACTUAL_ADOPTED_NEW_NORMAL_COMPLETE_SOURCE_BYTES')
    for adopted in THREE_READ_SOURCE_FILES:
        require(next(row for row in frozen['fulfillment_source_files'] if row['path'] == adopted['path']) == adopted,
                'EXACT_ADOPTED_THREE_READ_HELPER_FULL_SOURCE_BYTES_THROUGH_ZERO')
    for group, root in [('driver_source_files', context['repo_dir']), ('customer_source_files', context['repo_dir']),
        ('paid_source_files', context['repo_dir']), ('fulfillment_source_files', context['repo_dir']),
        ('aftersale_review_source_files', context['repo_dir']), ('preparation_source_references', context['repo_dir']), ('artifact_files', context['prep_dir'])]:
        for row in frozen[group]:
            require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
                    and type(row['bytes']) is int and row['bytes'] >= 0
                    and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None,
                    'CANONICAL_COMPLETE_SOURCE_DESCRIPTOR')
            raw = ui_zero_file(Path(root) / row['path'])
            require(len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'FULL_ORIGINAL_SOURCE_BYTES_THROUGH_ZERO')
    for args, wanted in [(['rev-parse', 'HEAD'], context['source_sha']),
                         (['status', '--porcelain=v1', '--untracked-files=all'], ''), (['ls-files', '--', PAID_ENTRY], PAID_ENTRY),
                         (['ls-files', '--', FULFILLMENT_ENTRY], FULFILLMENT_ENTRY),
                         (['ls-files', '--', AFTERSALE_REVIEW_ENTRY], AFTERSALE_REVIEW_ENTRY)] + [
                         (['ls-files', '--', path], path) for path in EARLY_SCRIPTS.values()] + [
                         (['ls-files', '--', row['path']], row['path']) for row in THREE_READ_SOURCE_FILES]:
        raw, _, _ = ui_zero_native(context, 'ui-zero-current-source',
            ['git', '-c', 'safe.directory=' + Path(context['repo_dir']).as_posix(), '-C', context['repo_dir'], *args])
        require(raw.decode().rstrip('\r\n') == wanted, 'UI_CURRENT_SOURCE_AND_TRACKED_ENTRY_THROUGH_ZERO')
    rows = frozen['worker_source_files']
    require(frozen['worker_source_roots'] == SOURCE_ROOTS and frozen['worker_source_extra'] == worker_extra()
        and type(rows) is list and [r['path'] for r in rows] == sorted(set(aftersale_review_paths(context['repo_dir'])) | {WORKER_ENTRY})
        and [r for r in rows if r['path'] != WORKER_ENTRY] == frozen['aftersale_review_source_files'], 'UI_COMPLETE_WORKER29_SOURCE_SUBSET')
    require(next(r for r in rows if r['path'] == WORKER_ENTRY)
        == {'path':WORKER_ENTRY,'bytes':WORKER_ENTRY_BYTES,'sha256':WORKER_ENTRY_SHA256}, 'UI_EXACT_WORKER_SOURCE')
    for row in rows:
        require(type(row) is dict and set(row) == {'path','bytes','sha256'} and type(row['bytes']) is int
            and row['bytes'] >= 0 and paid_hash(row['sha256']) and source_row(context['repo_dir'],row['path']) == row,
            'UI_ALL_WORKER_SOURCE_BYTES')
    require(next(r for r in frozen['customer_source_files'] if r['path'] == 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php')
        == {'path': 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'bytes': 65119, 'sha256': '925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d'}, 'UI_CURRENT_CUSTOMER_IN_ORIGINAL_COMPLETE_CLOSURE')
    require(context['prep_dir'] == ARTIFACTS.as_posix(), 'UI_SAME_PREP_DIRECTORY')
    verify_ui_prepare_freeze(frozen, context['repo_dir'])
    verify_ui_copy_freeze(frozen, context['repo_dir'])
    verify_ui_web_freeze(frozen, context['repo_dir'])
    raw, _, _ = ui_zero_native(context, 'ui-zero-worker-tracked',
        ['git','-c','safe.directory=' + Path(context['repo_dir']).as_posix(),'-C',context['repo_dir'],'ls-files','--',WORKER_ENTRY])
    require(raw.decode().rstrip('\r\n') == WORKER_ENTRY, 'UI_WORKER_SOURCE_TRACKED')
    ui_zero_whole_sources(context, frozen)
    return frozen



def ui_zero_write(path, raw):
    path = Path(path)
    require(path.parent.is_dir() and not path.parent.is_symlink()
        and not getattr(path.parent,'is_junction',lambda:False)()
        and not path.exists() and not path.is_symlink(), 'UI_ZERO_FRESH_REGULAR_PUBLIC_OUTPUT')
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'UI_ZERO_COMPLETE_WRITE')
        stream.flush()
        os.fsync(stream.fileno())
    require(ui_zero_file(path) == raw, 'UI_ZERO_COMPLETE_READBACK')


def ui_zero_native(context, label, argv):
    e = Path(context['run_dir']) / 'evidence'
    index = 1
    while (e / ('ui-zero-native-%04d.created.json' % index)).exists():
        index += 1
    stem = 'ui-zero-native-%04d' % index
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    created = {'label':label,'argv':argv,'pid':process.pid}
    ui_zero_write(e / (stem+'.created.json'), (json.dumps(created)+'\n').encode())
    stdout, stderr = process.communicate()
    waited = process.wait()
    ui_zero_write(e / (stem+'.stdout'),stdout)
    ui_zero_write(e / (stem+'.stderr'),stderr)
    receipt = {'label':label,'argv':argv,'pid':process.pid,'native_exit':process.returncode,'wait_returncode':waited,
        'stdout_bytes':len(stdout),'stdout_sha256':sha(stdout),'stderr_bytes':len(stderr),'stderr_sha256':sha(stderr)}
    ui_zero_write(e / (stem+'.terminal.json'),(json.dumps(receipt)+'\n').encode())
    require(type(process.pid) is int and process.pid > 0 and type(process.returncode) is int
        and type(waited) is int and process.returncode == waited == 0 and stderr == b'', 'UI_ZERO_SAME_PID_NATIVE_ZERO')
    return stdout, receipt, ui_copy_descriptor(e/(stem+'.stdout'),'/run/audit/evidence/'+stem+'.stdout')


def ui_zero_native_shape(receipt, argv):
    require(type(receipt) is dict and set(receipt) == set(UI_WEB_NATIVE_RECEIPT_KEYS)
        and receipt['argv'] == argv and type(receipt['label']) is str and receipt['label']
        and type(receipt['pid']) is int and receipt['pid'] > 0
        and type(receipt['native_exit']) is int and type(receipt['wait_returncode']) is int
        and receipt['native_exit'] == receipt['wait_returncode'] == 0
        and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] >= 0 and paid_hash(receipt['stdout_sha256'])
        and type(receipt['stderr_bytes']) is int and receipt['stderr_bytes'] == 0
        and receipt['stderr_sha256'] == sha(b''), 'UI_ZERO_COMPLETE_NATIVE_RECEIPT9')


def ui_zero_full_native(context, receipt, argv):
    ui_zero_native_shape(receipt,argv)
    e = Path(context['run_dir'])/'evidence'
    hits = [p for prefix in ['copy-native-','ui-zero-native-']
        for p in e.glob(prefix+'*.terminal.json') if load(p) == receipt]
    require(len(hits) == 1, 'UI_ZERO_UNIQUE_ACTUAL_NATIVE_HANDLE')
    stem = hits[0].name[:-len('.terminal.json')]
    raw = ui_zero_file(e/(stem+'.stdout'))
    errors = ui_zero_file(e/(stem+'.stderr'))
    require(load(e/(stem+'.created.json')) == {'label':receipt['label'],'argv':argv,'pid':receipt['pid']}
        and receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == sha(raw)
        and errors == b'', 'UI_ZERO_NATIVE_COMPLETE_CREATED_STREAMS_WAIT')
    return raw


def ui_zero_operation(context, operation, argv):
    require(type(operation) is dict and set(operation) == {'label','native','projection'}
        and operation['label'] == operation['native']['label'], 'UI_ZERO_OPERATION3')
    raw = ui_zero_full_native(context,operation['native'],argv)
    projection = operation['projection']
    require(type(projection) is dict and set(projection) == {'path','bytes','sha256'}
        and projection['path'].startswith('/run/audit/evidence/')
        and Path(projection['path']).name == projection['path'][len('/run/audit/evidence/'):]
        and projection == ui_copy_descriptor(Path(context['run_dir'])/'evidence'/Path(projection['path']).name,projection['path'])
        and projection['bytes'] == len(raw) and projection['sha256'] == sha(raw), 'UI_ZERO_FULL_OPERATION_RAW_DESCRIPTOR')
    return raw


def ui_prepare_prerequisite_hashes(context, native_required=False):
    require(native_required is False, 'UI_ZERO_ORIGINAL_PREDECESSORS_OFFLINE_ONLY')
    verify_paid_history(context,False)
    verify_fulfillment_migration(context,False)
    return public_hashes(context,ui_prepare_prerequisite_names())


def verify_ui_prepare_environment(context, cid, native_required=False):
    require(native_required is False, 'UI_ZERO_ENVIRONMENT_PUBLIC_ARCHIVE_ONLY')
    e = Path(context['run_dir'])/'evidence'
    raw = ui_prepare_environment_bytes(context)
    receipt = load(e/'ui-prepare-environment-native.json')
    require(type(receipt) is dict and set(receipt) == {'stage','native_exit','stdout_bytes','stdout_sha256',
        'stderr_bytes','stderr_sha256','private_environment_logs_omitted'}
        and receipt['stage'] == 'ui-prepare-public-environment-inspect'
        and type(receipt['native_exit']) is int and receipt['native_exit'] == 0
        and type(receipt['stdout_bytes']) is int and receipt['stdout_bytes'] == len(raw)
        and receipt['stdout_sha256'] == sha(raw) and type(receipt['stderr_bytes']) is int
        and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == sha(b'')
        and receipt['private_environment_logs_omitted'] is True, 'UI_ZERO_EXACT_NINE_ENV_ARCHIVE')
    value = load(e/'ui-prepare-environment.json')
    require(paid_equal(value,{'schema':1,'scope':UI_PREPARE['environment_scope'],'run_id':context['run_id'],
        'source_sha':context['source_sha'],'container_id':cid,'freeze_sha256':context['freeze_sha256'],
        'environment':ui_prepare_environment(context),'native_projection_sha256':receipt['stdout_sha256']}),
        'UI_ZERO_PREPARE_ENVIRONMENT_WRAPPER')
    return value


def ui_prepare_fixture_identity(context, formal):
    original = context['initial_volumes']
    names = sorted(context['project']+'_'+suffix for suffix in VOLUMES)
    require(type(original) is list and len(original) == 8
        and sorted(row['name'] for row in original) == names
        and all(type(row) is dict and set(row) == {'name','created_at','labels'} for row in original)
        and paid_equal(formal['volume_identities'],{row['name']:row['created_at'] for row in original})
        and sorted(formal['volumes']) == names, 'UI_ZERO_ORIGINAL_EIGHT_FORMAL_IDENTITIES')
    values = ui_zero_volumes(context)
    expected = next(row for row in original if row['name'] == context['project']+'_fixture-secrets')
    require(next(row for row in values if row['name'] == expected['name']) == expected, 'UI_ZERO_FIXTURE_ARCHIVED_NATIVE_IDENTITY')
    return expected


def ui_zero_prerequisites(context):
    root = Path(context['run_dir'])
    e = root/'evidence'
    verify_ui_prepare_history(context,False)
    before = bound_created(context,UI_PREPARE['proof'],UI_PREPARE['role'])
    terminal = normal_terminal(context,UI_PREPARE['proof'],UI_PREPARE['role'],False)
    ui_prepare_owned(context,before)
    ui_prepare_owned(context,terminal)
    retired = load(e/'ui-prepare-removed.json')
    require(type(retired) is dict and set(retired) == {'schema','scope','run_id','source_sha','container_id','created_sha256',
        'terminal_sha256','history_sha256','native_remove','absent_after'} and retired['schema'] == 1
        and retired['scope'] == 'MS4_UI_PREPARE_EXACT_NORMAL_REMOVAL'
        and all(retired[k] == context[k] for k in ['run_id','source_sha'])
        and retired['container_id'] == before['id'] and retired['absent_after'] is True
        and retired['created_sha256'] == sha(ui_zero_file(e/'ui-prepare-created.json'))
        and retired['terminal_sha256'] == sha(ui_zero_file(e/'ui-prepare-terminal.json'))
        and retired['history_sha256'] == sha(ui_zero_file(e/'ui-prepare-predecessor-history.json')), 'UI_ZERO_PREPARE_NORMAL_RETIRE_BINDING')
    receipt = retired['native_remove']
    handle = receipt['same_handle_terminal']
    raw = ui_zero_file(e/'ui-prepare-remove.stdout')
    require(receipt['argv'] == ['docker','rm',before['id']] and type(handle['pid']) is int and handle['pid'] > 0
        and type(receipt['native_exit']) is int and type(handle['returncode']) is int and type(handle['wait_returncode']) is int
        and receipt['native_exit'] == handle['returncode'] == handle['wait_returncode'] == 0
        and raw == (before['id']+'\n').encode() and ui_zero_file(e/'ui-prepare-remove.stderr') == b''
        and receipt['stdout_bytes'] == len(raw) and receipt['stdout_sha256'] == sha(raw)
        and receipt['stderr_bytes'] == 0 and receipt['stderr_sha256'] == sha(b'')
        and load(e/'ui-prepare-remove-native.json') == {'schema':1,'native':receipt}, 'UI_ZERO_PREPARE_FULL_NORMAL_REMOVE_NATIVE')
    names = ui_prepare_prerequisite_names()+ui_prepare_public_names(context)+[
        'ui-prepare-context.json','ui-prepare-formal-manifest.json','ui-prepare-predecessor-history.json']
    expected_count = 50 if task2_prepare_group(context) is not None else 49
    require(len(names) == len(set(names)) == expected_count, 'UI_ZERO_EXACT_ORIGINAL49_OR_TASK2_50_NAMES')
    return public_hashes(context,names)


def ui_zero_volumes(context):
    e = Path(context['run_dir'])/'evidence'
    proof = load(e/'ui-volume-native.json')
    require(type(proof) is dict and set(proof) == {'schema','scope','run_id','source_sha','freeze_sha256','volumes'}
        and type(proof['schema']) is int and proof['schema'] == 1 and proof['scope'] == 'MS4_UI_WEB_VOLUME_IDENTITIES_NATIVE'
        and all(proof[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and type(proof['volumes']) is list and len(proof['volumes']) == 12, 'UI_ZERO_FULL_TWELVE_VOLUME_ARCHIVE')
    expected = [context['project']+'_'+suffix for suffix in sorted(VOLUMES+UI_COPY_WEB_VOLUMES)]
    identities = []
    fmt = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}'
    for row,name in zip(proof['volumes'],expected):
        require(type(row) is dict and set(row) == {'identity','projection','native'}, 'UI_ZERO_VOLUME_NATIVE_ROW3')
        identity = row['identity']
        require(type(identity) is dict and set(identity) == {'name','created_at','labels'}
            and identity['name'] == name and type(identity['created_at']) is str and identity['created_at']
            and identity['labels'].get('tianli.audit') == '1' and identity['labels'].get('tianli.audit.run') == context['run_id']
            and identity['labels'].get('com.docker.compose.project') == context['project'], 'UI_ZERO_COMPLETE_VOLUME_IDENTITY')
        raw = ui_zero_operation(context,{'label':row['native']['label'],'native':row['native'],'projection':row['projection']},
            ['docker','volume','inspect','--format',fmt,name])
        require(json.loads(raw) == identity, 'UI_ZERO_VOLUME_FULL_RAW_NATIVE')
        if name in {r['name'] for r in context['initial_volumes']}:
            require(identity == next(r for r in context['initial_volumes'] if r['name'] == name), 'UI_ZERO_ORIGINAL_EIGHT_VOLUMES_PRESERVED')
        identities.append(identity)
    require(len({r['name'] for r in identities}) == 12, 'UI_ZERO_TWELVE_DISTINCT_VOLUME_NAMES')
    return identities


def ui_zero_network_identity(context):
    e = Path(context['run_dir'])/'evidence'
    saved = load(e/'ui-web-frontend-network.json')
    require(type(saved) is dict and set(saved) == {'schema','scope','run_id','source_sha','freeze_sha256','network','native','projection'}
        and type(saved['schema']) is int and saved['schema'] == 1 and saved['scope'] == 'MS4_UI_WEB_FRONTEND_NATIVE'
        and all(saved[k] == context[k] for k in ['run_id','source_sha','freeze_sha256']), 'UI_ZERO_ORIGINAL_FRONTEND_WRAPPER')
    value = saved['network']
    fields = {'Id','Name','Driver','Scope','Internal','EnableIPv6','Options','Labels','Containers'}
    require(type(value) is dict and set(value) == fields and re.fullmatch('[a-f0-9]{64}',value['Id'])
        and value['Id'] != context['network_id'] and value['Name'] == context['project']+'_frontend'
        and value['Driver'] == 'bridge' and value['Scope'] == 'local' and value['Internal'] is False
        and value['EnableIPv6'] is False and value['Containers'] == {}
        and value['Options'] == {'com.docker.network.enable_ipv4':'true','com.docker.network.bridge.enable_ip_masquerade':'false'}
        and value['Labels'] == {'tianli.audit':'1','tianli.audit.run':context['run_id'],
            'com.docker.compose.project':context['project'],'com.docker.compose.network':'frontend'}, 'UI_ZERO_EXACT_CREATED_FRONTEND9')
    raw = ui_zero_operation(context,{'label':saved['native']['label'],'native':saved['native'],'projection':saved['projection']},
        ['docker','network','inspect','--format',UI_WEB_NETWORK_FORMAT,value['Id']])
    require(json.loads(raw) == value and load(Path(context['run_dir'])/'network.json') == context['initial_network']
        and context['initial_network']['Id'] == context['network_id'], 'UI_ZERO_BOTH_ORIGINAL_NETWORK_IDENTITIES')
    return value


def ui_zero_targets(context):
    e = Path(context['run_dir'])/'evidence'
    require(set(context['initial_service_ids']) == {'audit-mysql','audit-redis'}
        and len(set(context['initial_service_ids'].values())) == 2, 'UI_ZERO_TWO_ORIGINAL_DATABASE_IDS')
    paid = bound_created(context,PAID['proof'],PAID['role'])
    normal_terminal(context,PAID['proof'],PAID['role'],False)
    backend = ui_web_created(context,'ui-backend')
    gateway = ui_web_created(context,'ui-gateway')
    internal = load(e/'ui-gateway-internal-created.json')
    ui_web_owned(context,backend,UI_WEB_BACKEND['role'])
    ui_web_owned(context,gateway,UI_WEB_GATEWAY['role'])
    ui_web_owned(context,internal,UI_WEB_GATEWAY['role'],False)
    ui_web_gateway_transition(context,internal,gateway)
    require(backend['status'] == gateway['status'] == internal['status'] == 'created'
        and backend['pid'] == gateway['pid'] == internal['pid'] == 0, 'UI_ZERO_ORIGINAL_UNSTARTED_UI_CREATED_RECORDS')
    created = {facts['id']:facts for facts in [paid,backend,gateway]}
    for role,cid in context['initial_service_ids'].items():
        value = context['initial_service_created'][role]
        require(value['id'] == cid and value['status'] == 'created' and value['pid'] == 0, 'UI_ZERO_ORIGINAL_DATABASE_CREATED_RECORD')
        owned(context,value,role)
        created[cid] = value
    expected = {cid:value['labels']['com.docker.compose.service'] for cid,value in created.items()}
    require(len(expected) == 5 and set(expected.values()) == {PAID['role'],UI_WEB_BACKEND['role'],UI_WEB_GATEWAY['role'],
        'audit-mysql','audit-redis'} and all(re.fullmatch('[a-f0-9]{64}',cid) for cid in expected), 'UI_ZERO_FIVE_DISTINCT_ORIGINAL_TARGETS')
    return expected,created


def ui_zero_historical(context, targets):
    e = Path(context['run_dir'])/'evidence'
    seen = set()
    for path in sorted(e.glob('*-created.json')):
        value = load(path)
        require(type(value) is dict and set(value) == set(UI_WEB_NATIVE_KEYS)|{'run_id','source_sha','proof_name'}
            and value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
            and value['status'] == 'created' and type(value['pid']) is int and value['pid'] == 0
            and type(value['id']) is str and re.fullmatch('[a-f0-9]{64}',value['id'])
            and value['labels'].get('tianli.audit') == '1'
            and value['labels'].get('tianli.audit.run') == context['run_id']
            and value['labels'].get('com.docker.compose.project') == context['project'], 'UI_ZERO_ALL_ACTUAL_HISTORICAL_NATIVE_CREATED_CIDS')
        seen.add(value['id'])
    require(set(targets) <= seen|set(context['initial_service_ids'].values()), 'UI_ZERO_TARGETS_IN_ACTUAL_CREATED_SOURCE')
    return sorted(seen-set(targets))


def ui_zero_preservation(context, preservation):
    require(type(preservation) is dict and preservation, 'UI_ZERO_COMPLETE_PUBLIC_PRESERVATION_MAP')
    root = Path(context['run_dir'])
    for relative,row in preservation.items():
        require(type(relative) is str and relative and not re.search(r'(^/|\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)',relative)
            and type(row) is dict and set(row) == {'bytes','sha256'} and type(row['bytes']) is int
            and row['bytes'] >= 0 and paid_hash(row['sha256']), 'UI_ZERO_CANONICAL_PUBLIC_PRESERVATION_ROW')
        path = root/relative
        for parent in [path]+list(path.parents):
            if parent == root.parent:
                break
            require(not parent.is_symlink() and not getattr(parent,'is_junction',lambda:False)(), 'UI_ZERO_PRESERVED_PATH_NO_LINKS')
        raw = ui_zero_file(path)
        require(len(raw) == row['bytes'] and sha(raw) == row['sha256'], 'UI_ZERO_EVERY_ORIGINAL_PUBLIC_BYTE')
    require('actual-run-context.json' in preservation and 'preparation-source-binding.json' in preservation
        and 'evidence/ui-web-predecessor-keep.json' in preservation, 'UI_ZERO_PRESERVATION_INCLUDES_ORIGINAL_CONTEXT_SOURCE_KEEP')


def ui_zero_predecessor_keep(context):
    root = Path(context['run_dir'])
    value = load(root/'evidence/ui-web-predecessor-keep.json')
    require(type(value) is dict and set(value) == {'schema','scope','run_id','source_sha','freeze_sha256','files'}
        and type(value['schema']) is int and value['schema'] == 1 and value['scope'] == 'MS4_UI_WEB_ORIGINAL_PUBLIC_BYTES'
        and all(value[k] == context[k] for k in ['run_id','source_sha','freeze_sha256']), 'UI_ZERO_ORIGINAL_WEB_KEEP_IDENTITY')
    for relative,row in value['files'].items():
        require(relative not in ['manifest.json','containers.jsonl','volumes.txt','volumes.jsonl']
            and not re.search(r'(^/|\\|(^|/)\.\.?(/|$)|:)',relative)
            and ui_copy_descriptor(root/relative,'/run/audit/'+relative) == row, 'UI_ZERO_COMPLETE_WEB_PREDECESSOR_BYTES')
    current = ui_zero_prerequisites(context)
    binding = load(root/'evidence/ms4-ui-public-copy-source-binding.json')
    require(binding['prerequisites_sha256'] == current, 'UI_ZERO_SAME_ORIGINAL49_COPY_BINDING')
    return value


def ui_zero_copy_history(context):
    root = Path(context['run_dir'])
    e = root/'evidence'
    old = load(e/'ui-public-copy-created.json')
    terminal = load(e/'ui-public-copy-terminal.json')
    owned(context,old,UI_COPY['role'])
    owned(context,terminal,UI_COPY['role'])
    ui_copy_owned(context,old)
    ui_copy_owned(context,terminal)
    same_created(old,terminal)
    normal(terminal,old['id'],context,UI_COPY['role'])
    binding = load(e/UI_COPY['binding'])
    frozen = load(root/'preparation-source-binding.json')
    require(set(binding) == set(UI_COPY_BINDING_KEYS) and binding['schema'] == 1 and binding['scope'] == 'MS4_UI_PUBLIC_COPY_SOURCE'
        and all(binding[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and binding['container_id'] == old['id'] and binding['native_created_sha256'] == sha(ui_zero_file(e/'ui-public-copy-created.json'))
        and binding['entry_sha256'] == sha(ui_zero_file(ARTIFACTS/'ms4-ui-copy.php'))
        and binding['source_files'] == frozen['ui_backend_source_files']
        and binding['requested_repo_source'] == context['repo_dir'] and binding['requested_run_source'] == context['run_dir']
        and binding['requested_prep_source'] == context['prep_dir']
        and binding['prerequisites_sha256'] == ui_zero_prerequisites(context)
        and binding['dist_sources'] == frozen['ui_copy_dist_sources'], 'UI_ZERO_COMPLETE_COPY_BINDING18')
    for field,path in [('manifest',e/'ui-web-original-manifest.json'),('history',e/'ui-prepare-predecessor-history.json'),
        ('volumes_native',e/'ui-volume-native.json'),('copy_profile',ARTIFACTS/'manifests/ui-public-copy-profile.json')]:
        original_path = '/run/ms4-prep/manifests/ui-public-copy-profile.json' if field == 'copy_profile' else (
            '/run/audit/manifest.json' if field == 'manifest' else '/run/audit/evidence/'+path.name)
        require(binding[field] == ui_copy_descriptor(path,original_path), 'UI_ZERO_COPY_BOUND_ORIGINAL_DESCRIPTOR')
    result = load(e/'ui-public-copy-result.json')
    shutdown = load(e/'ui-public-copy-shutdown.json')
    require(type(result) is dict and set(result) == set(UI_COPY_RESULT_KEYS) and result['schema'] == 1
        and result['scope'] == 'NORMAL_MS4_UI_PUBLIC_COPY_ONLY' and result['status'] == 'VERIFIED_NORMAL_MS4_UI_PUBLIC_COPY_ONLY'
        and all(result[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and result['container_id'] == old['id'] and result['native_created_sha256'] == sha(ui_zero_file(e/'ui-public-copy-created.json'))
        and result['source_binding_sha256'] == sha(ui_zero_file(e/UI_COPY['binding'])) and result['phase'] == 'final_source'
        and type(result['entry_return_code']) is int and result['entry_return_code'] == 0 and result['termination'] == 'NORMAL_RETURN'
        and result['native_exit'] is None and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
        and result['full_source_unchanged'] is True and result['diagnostic'] is None and type(result['assertions']) is int
        and result['assertions'] > 0 and result['full_acceptance'] == 'INCOMPLETE'
        and all(result[k] == 'NOT RUN' for k in ['App_HTTP_DOM','business_acceptance','stopped_samples']), 'UI_ZERO_COMPLETE_COPY_RESULT22')
    require(type(shutdown) is dict and set(shutdown) == set(UI_COPY_SHUTDOWN_KEYS) and shutdown['schema'] == 1
        and shutdown['scope'] == 'MS4_UI_PUBLIC_COPY_SHUTDOWN'
        and all(shutdown[k] == result[k] for k in ['run_id','source_sha','container_id','freeze_sha256','phase'])
        and shutdown['completed'] is True and shutdown['result_published'] is True and shutdown['fatal'] is False
        and shutdown['termination_requested'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and shutdown['native_exit'] is None and shutdown['native_exit_observation'] == 'HOST_NATIVE_REQUIRED', 'UI_ZERO_COPY_SHUTDOWN15')
    produced = e/'ui-public-materials-produced.json'
    raw = ui_zero_file(produced)
    require(result['materials_candidate'] == ui_copy_descriptor(produced,'/run/audit/evidence/'+produced.name)
        and ui_zero_file(e/'ui-public-materials.json') == raw and paid_equal(json.loads(raw),ui_copy_expected_materials(context)),
        'UI_ZERO_COMPLETE_ACTUAL_PUBLIC_MATERIAL_UNION')
    removed = load(e/'ui-public-copy-removed.json')
    require(type(removed) is dict and set(removed) == {'schema','scope','run_id','source_sha','container_id','created_sha256',
        'terminal_sha256','materials_sha256','native_remove','absent_after'} and removed['schema'] == 1
        and removed['scope'] == 'MS4_UI_PUBLIC_COPY_EXACT_NORMAL_REMOVAL'
        and all(removed[k] == context[k] for k in ['run_id','source_sha']) and removed['container_id'] == old['id']
        and removed['created_sha256'] == result['native_created_sha256']
        and removed['terminal_sha256'] == sha(ui_zero_file(e/'ui-public-copy-terminal.json'))
        and removed['materials_sha256'] == sha(raw) and removed['absent_after'] is True, 'UI_ZERO_COPY_NORMAL_RETIRE_BINDING')
    require(ui_zero_full_native(context,removed['native_remove'],['docker','rm',old['id']]) == (old['id']+'\n').encode(),
        'UI_ZERO_COPY_ORIGINAL_FULL_REMOVE_RECEIPT')
    current = load(e/'ui-public-copy-current.json')
    require(type(current) is dict and set(current) == set(UI_WEB_CURRENT_KEYS) and current['schema'] == 1
        and current['scope'] == 'MS4_UI_PUBLIC_COPY_CURRENT_NATIVE'
        and all(current[k] == result[k] for k in ['run_id','source_sha','freeze_sha256','container_id','native_created_sha256','source_binding_sha256']),
        'UI_ZERO_COPY_ORIGINAL_CURRENT_BINDING')
    running = json.loads(ui_zero_operation(context,{'label':current['native']['label'],'native':current['native'],'projection':current['projection']},
        ['docker','inspect','--type','container','--format',INSPECT,old['id']]))
    owned(context,running,UI_COPY['role'])
    ui_copy_owned(context,running)
    same_created(old,running)
    require(running['status'] == 'running' and running['pid'] > 0, 'UI_ZERO_COPY_REAL_RUNNING_TO_NATURAL_ZERO')
    return result


def ui_zero_backend_environment(context, cid):
    names = AFTERSALE_ENV_NAMES
    expected = ui_prepare_environment(context)
    def starts(prefix):
        return '(and (ge (len .) %d) (eq (slice . 0 %d) %s))' % (len(prefix),len(prefix),json.dumps(prefix))
    audit = ui_web_public_audit(context)
    known = ' '.join(starts(name+'=') for name in names+list(audit))
    selected = '\n'.join('{{range .Config.Env}}{{if '+starts(name+'=')+'}}{{json .}}{{end}}{{end}}' for name in names)
    forbidden = ' '.join(starts(prefix) for prefix in ['AUDIT_','MS4_','MS5_','APP_ENV=','PHP_MARKETPLACE_','PHP_CACHE_DRIVER='])
    selected += '{{range .Config.Env}}{{if and (or '+forbidden+') (not (or '+known+'))}}{{json "UNEXPECTED_WEB_ENVIRONMENT"}}{{end}}{{end}}'
    for name,value in audit.items():
        selected += '{{$seen := ""}}{{range .Config.Env}}{{if '+starts(name+'=')+'}}{{$seen = printf "%s1" $seen}}{{if ne . '+json.dumps(name+'='+value)+'}}{{json "UNEXPECTED_WEB_ENVIRONMENT"}}{{end}}{{end}}{{end}}{{if ne $seen "1"}}{{json "UNEXPECTED_WEB_ENVIRONMENT"}}{{end}}'
    wanted = ui_prepare_environment_bytes(context)
    e = Path(context['run_dir'])/'evidence'
    matches = [load(p) for p in e.glob('copy-native-*.terminal.json')
        if load(p).get('label') == 'ui-backend-public-environment-inspect']
    require(matches, 'UI_ZERO_REAL_BACKEND_PUBLIC_ENVIRONMENT_NATIVE_EXISTS')
    for receipt in matches:
        require(ui_zero_full_native(context,receipt,['docker','inspect','--type','container','--format',selected,cid]) == wanted,
            'UI_ZERO_EXACT_NINE_BACKEND_ENV_RAW')
    value = {'schema':1,'scope':'MS4_UI_BACKEND_REAL_ENVIRONMENT','run_id':context['run_id'],'source_sha':context['source_sha'],
        'container_id':cid,'freeze_sha256':context['freeze_sha256'],'environment':expected,'native_projection_sha256':sha(wanted)}
    native = {'stage':'ui-backend-public-environment-inspect','native_exit':0,'stdout_bytes':len(wanted),
        'stdout_sha256':sha(wanted),'stderr_bytes':0,'stderr_sha256':sha(b''),'private_environment_logs_omitted':True}
    require(load(e/'ui-backend-environment.json') == value and load(e/'ui-backend-environment-native.json') == native,
        'UI_ZERO_ORIGINAL_NINE_BACKEND_ENV_WRAPPER')
    return value


def ui_zero_backend_binding(context, created):
    root = Path(context['run_dir'])
    e = root/'evidence'
    frozen = load(root/'preparation-source-binding.json')
    cid = created['id']
    ui_zero_backend_environment(context,cid)
    value = {'schema':1,'scope':'MS4_UI_BACKEND_SOURCE','run_id':context['run_id'],'source_sha':context['source_sha'],
        'container_id':cid,'native_created_sha256':sha(ui_zero_file(e/'ui-backend-created.json')),'freeze_sha256':context['freeze_sha256'],
        'requested_repo_source':context['repo_dir'],'requested_run_source':context['run_dir'],'requested_prep_source':context['prep_dir'],
        'entry_sha256':next(r['sha256'] for r in frozen['ui_backend_source_files'] if r['path'] == UI_WEB_BACKEND['entry']),
        'source_files':frozen['ui_backend_source_files'],'prerequisites_sha256':ui_zero_prerequisites(context),
        'manifest':ui_copy_descriptor(root/'manifest.json','/run/audit/manifest.json'),
        'environment':ui_prepare_descriptor(context,'ui-backend-environment.json'),
        'environment_native':ui_prepare_descriptor(context,'ui-backend-environment-native.json'),
        'history':ui_prepare_descriptor(context,'ui-prepare-predecessor-history.json'),
        'public_materials':ui_prepare_descriptor(context,'ui-public-materials.json'),'volumes_native':ui_prepare_descriptor(context,'ui-volume-native.json')}
    require(set(value) == set(UI_WEB_BINDING_KEYS) and paid_equal(load(e/UI_WEB_BACKEND['binding']),value), 'UI_ZERO_COMPLETE_BACKEND_SOURCE_BINDING19')
    return value


def ui_zero_controls(context):
    e = Path(context['run_dir'])/'evidence'
    original = ui_web_created(context,'ui-backend')
    values = {}
    for name in UI_WEB_CONTROL_NAMES:
        path = e/('ui-web-control-'+name)
        raw = ui_zero_file(path)
        value = json.loads(raw)
        ui_web_control_shape(context,name,value)
        values[name] = value
        wrappers = [p for p in e.glob('ui-web-control-*.json')
            if re.fullmatch(r'ui-web-control-[0-9]{4}\.json',p.name) and load(p).get('name') == name]
        require(wrappers, 'UI_ZERO_SAFE_CONTROL_HAS_ORIGINAL_RAM_TRANSFER_ARCHIVE')
        for path in wrappers:
            wrapper = load(path)
            require(type(wrapper) is dict and set(wrapper) == {'schema','scope','run_id','source_sha','freeze_sha256','container_id',
                'name','native','tar','content','archive_storage'} and type(wrapper['schema']) is int and wrapper['schema'] == 1
                and wrapper['scope'] == 'MS4_UI_WEB_FIXED_CONTROL_NATIVE'
                and all(wrapper[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
                and wrapper['container_id'] == original['id'] and wrapper['name'] == name
                and wrapper['archive_storage'] == 'RAM_ONLY_VALIDATED_SAFE_JSON_ARCHIVED'
                and wrapper['content'] == ui_copy_descriptor(e/('ui-web-control-'+name),'/run/audit/evidence/ui-web-control-'+name),
                'UI_ZERO_CONTROL_WRAPPER_SAFE_CONTENT_DESCRIPTOR')
            native = wrapper['native']
            argv = ['docker','cp',original['id']+':/run/audit-ui-control/'+name,'-']
            ui_zero_native_shape(native,argv)
            stem = path.name[:-len('.json')]
            require(native['label'] == 'web-fixed-control-ram-cp' and load(e/(stem+'.native.json')) == native
                and load(e/(stem+'.created.json')) == {'label':native['label'],'argv':argv,'pid':native['pid']},
                'UI_ZERO_CONTROL_SAME_CP_CREATED_NATIVE_HANDLE')
            tar = wrapper['tar']
            require(type(tar) is dict and set(tar) == {'member','type','mode','uid','gid','bytes','sha256','archive_bytes','archive_sha256',
                'member_count','ordinary_ustar_checksum'} and tar['member'] == name and tar['type'] == 'regular' and tar['mode'] == '0600'
                and type(tar['uid']) is int and tar['uid'] == 0 and type(tar['gid']) is int and tar['gid'] == 0
                and type(tar['member_count']) is int and tar['member_count'] == 1
                and type(tar['bytes']) is int and 0 < tar['bytes'] <= 1048576 and tar['bytes'] == len(raw) and tar['sha256'] == sha(raw)
                and type(tar['archive_bytes']) is int and 1536 <= tar['archive_bytes'] <= 2097152 and tar['archive_bytes'] % 512 == 0
                and tar['archive_bytes'] == native['stdout_bytes'] and tar['archive_sha256'] == native['stdout_sha256']
                and tar['archive_bytes'] >= 512+((len(raw)+511)//512)*512+1024
                and type(tar['ordinary_ustar_checksum']) is int and tar['ordinary_ustar_checksum'] > 0,
                'UI_ZERO_ORIGINAL_RAM_TAR_PROOF_METADATA_NOT_TAR_REREAD')
    ready = values['ui-backend-ready.json']
    require(values['ms4-ui-backend.json']['child']['group'] == ready['group']
        and values['ui-backend-shutdown.json']['child'] == values['ms4-ui-backend.json']['child'], 'UI_ZERO_SAME_READY_TO_TERMINAL_ORIGINAL_GROUP')
    events = [p for p in e.glob('ui-web-ready-event-*.json') if re.fullmatch(r'ui-web-ready-event-[0-9]{4}\.json',p.name)]
    require(events, 'UI_ZERO_EXISTING_PARENT_READY_EVENT_REQUIRED')
    for path in events:
        event = load(path)
        require(type(event) is dict and set(event) == {'schema','scope','run_id','source_sha','freeze_sha256','container_id','native',
            'event','uncontrolled_stdout_storage','private_child_logs'} and event['schema'] == 1
            and event['scope'] == 'MS4_UI_WEB_EXISTING_READY_EVENT_ONLY'
            and all(event[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
            and event['container_id'] == original['id'] and event['uncontrolled_stdout_storage'] == 'RAM_ONLY_NOT_PUBLIC'
            and event['private_child_logs'] == 'NOT READ', 'UI_ZERO_SAFE_EXISTING_PARENT_READY_EVENT')
        native = event['native']
        ui_zero_native_shape(native,['docker','logs',original['id']])
        require(native['label'] == 'web-existing-parent-ready-event' and 0 < native['stdout_bytes'] <= 1048576
            and load(e/(path.name[:-len('.json')]+'.native.json')) == native
            and event['event'] == {'scope':'MS4_UI_BACKEND_READY','run_id':context['run_id'],'source_sha':context['source_sha'],
                'container_id':original['id'],'ready_sha256':sha(ui_zero_file(e/'ui-web-control-ui-backend-ready.json'))},
                'UI_ZERO_READY_EVENT_BOUND_TO_COMPLETE_SAFE_READY_BYTES')
    request_raw = ui_zero_file(e/'ui-web-control-ui-backend-stop-request.json')
    attempt = load(e/'ui-web-backend-stop-attempt.json')
    require(type(attempt) is dict and set(attempt) == {'schema','scope','run_id','source_sha','freeze_sha256','container_id',
        'host_pid','ready','request_bytes','request_sha256'} and attempt['schema'] == 1
        and attempt['scope'] == 'MS4_UI_BACKEND_SINGLE_ROOT_STOP_ATTEMPT'
        and all(attempt[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and attempt['container_id'] == original['id'] and type(attempt['host_pid']) is int and attempt['host_pid'] > 0
        and attempt['ready'] == ui_copy_descriptor(e/'ui-web-control-ui-backend-ready.json','/run/audit/evidence/ui-web-control-ui-backend-ready.json')
        and attempt['request_bytes'] == len(request_raw) and attempt['request_sha256'] == sha(request_raw), 'UI_ZERO_SINGLE_SAME_READY_STOP_ATTEMPT')
    hits = [load(p) for p in e.glob('copy-native-*.terminal.json') if load(p).get('label') == 'web-create-only-normal-stop9']
    require(len(hits) == 1 and ui_zero_full_native(context,hits[0],['docker','exec',original['id'],'php','-r',UIZ_STOP_PHP_SOURCE,
        base64.b64encode(request_raw).decode('ascii'),original['id']]) == b'STOP_REQUEST_PUBLISHED\n', 'UI_ZERO_EXACT_SINGLE_STOP9_REAL_NATIVE_SOURCE')
    return values


def ui_zero_parent(context, parent):
    e = Path(context['run_dir'])/'evidence'
    original = ui_web_created(context,'ui-backend')
    controls = ui_zero_controls(context)
    require(type(parent) is dict and set(parent) == {'schema','scope','run_id','source_sha','freeze_sha256','container_id','terminal',
        'native','projection','result','shutdown','stop_request','full_acceptance','three_end_DOM_business_normal_close_independent_zero'}
        and parent == load(e/'ui-web-backend-parent-native.json') and parent['schema'] == 1
        and parent['scope'] == 'MS4_UI_BACKEND_PARENT_NATIVE_ZERO_ONLY'
        and all(parent[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and parent['container_id'] == original['id'] and parent['full_acceptance'] == 'INCOMPLETE'
        and parent['three_end_DOM_business_normal_close_independent_zero'] == 'NOT PROVEN_BY_THIS_PRIMITIVE',
        'UI_ZERO_COMPLETE_RAW_PARENT_NATIVE_WRAPPER')
    for field,name in [('terminal','ui-backend-terminal.json'),('result','ui-web-control-ms4-ui-backend.json'),
        ('shutdown','ui-web-control-ui-backend-shutdown.json'),('stop_request','ui-web-control-ui-backend-stop-request.json')]:
        require(parent[field] == ui_prepare_descriptor(context,name), 'UI_ZERO_PARENT_BOUND_SAFE_PUBLIC_DESCRIPTOR')
    terminal = load(e/'ui-backend-terminal.json')
    ui_web_owned(context,terminal,UI_WEB_BACKEND['role'])
    same_created(original,terminal)
    normal(terminal,original['id'],context,UI_WEB_BACKEND['role'])
    require(ui_zero_operation(context,{'label':parent['native']['label'],'native':parent['native'],'projection':parent['projection']},
        ['docker','wait',original['id']]) == b'0\n', 'UI_ZERO_PARENT_SAME_CID_ACTUAL_DOCKER_WAIT_ZERO')
    return controls


def ui_zero_resource_networks(context, value, expected, empty=False):
    require(type(value) is dict and set(value) == {'internal','frontend'}, 'UI_ZERO_BOTH_FULL_NETWORK_ARCHIVES')
    frontend = ui_zero_network_identity(context)
    fields = {'Id','Name','Driver','Scope','Internal','EnableIPv6','Options','Labels','Containers'}
    for name,identity in [('internal',context['initial_network']),('frontend',frontend)]:
        row = value[name]
        require(type(row) is dict and set(row) == fields
            and all(row[k] == identity[k] for k in identity if k != 'Containers')
            and row['Driver'] == 'bridge' and row['Scope'] == 'local' and row['EnableIPv6'] is False
            and type(row['Containers']) is dict and set(row['Containers']) <= set(expected), 'UI_ZERO_UNCHANGED_NETWORK_FULL_IDENTITY')
        if name == 'internal':
            require(row['Internal'] is True, 'UI_ZERO_ORIGINAL_INTERNAL_NETWORK')
        else:
            require(row['Internal'] is False and set(row['Containers']) <= {cid for cid,role in expected.items() if role == UI_WEB_GATEWAY['role']},
                'UI_ZERO_ONLY_ORIGINAL_GATEWAY_FRONTEND_ENDPOINT')
        if empty:
            require(row['Containers'] == {}, 'UI_ZERO_BOTH_NETWORK_ENDPOINTS_EMPTY')


def ui_zero_inventory(context, value, expected, networks=True, volumes=True):
    require(type(value) is dict and set(value) == {'container','network','volume'}, 'UI_ZERO_EXACT_THREE_RESOURCE_CLASSES')
    wanted = {'container':set(expected),'network':{context['network_id'],ui_zero_network_identity(context)['Id']} if networks else set(),
        'volume':{row['name'] for row in ui_zero_volumes(context)} if volumes else set()}
    for kind in wanted:
        require(type(value[kind]) is dict and set(value[kind]) == {'project','run'}, 'UI_ZERO_TWO_LABEL_RESOURCE_CLASSES')
        for rows in value[kind].values():
            require(type(rows) is list and len(rows) == len(set(rows)) and set(rows) == wanted[kind], 'UI_ZERO_EXACT_RESOURCE_IDENTITY_LIST')


def ui_zero_resources(context, value, expected, historical, empty=False):
    require(type(value) is dict and set(value) == {'expected','networks','volumes','historical_absent'}
        and value['expected'] == expected and value['historical_absent'] == historical
        and paid_equal(value['volumes'],ui_zero_volumes(context)), 'UI_ZERO_EXACT_PRESERVED_UI_RESOURCE_ARCHIVE')
    ui_zero_resource_networks(context,value['networks'],expected,empty)


def ui_zero_global_archive(context, value, targets, allowed, historical):
    require(type(value) is dict and set(value) == {'ids','historical_absent','targets','allowed','networks','volumes','operations','unexpected_consumers'}
        and type(value['ids']) is list and value['ids'] == sorted(set(value['ids']))
        and all(re.fullmatch('[a-f0-9]{64}',cid) for cid in value['ids'])
        and value['historical_absent'] == historical and value['targets'] == sorted(targets) and value['allowed'] == sorted(allowed)
        and value['networks'] == {'ids':sorted([context['network_id'],ui_zero_network_identity(context)['Id']]),
            'names':sorted([context['initial_network']['Name'],context['project']+'_frontend'])}
        and value['volumes'] == sorted(row['name'] for row in ui_zero_volumes(context)) and value['unexpected_consumers'] == []
        and not (set(historical)|(set(targets)-set(allowed))).intersection(value['ids']), 'UI_ZERO_COMPLETE_GLOBAL8_ARCHIVE')
    ids = value['ids']
    operations = value['operations']
    require(type(operations) is list and len(operations) == (3 if ids else 2), 'UI_ZERO_ALL_GLOBAL_NATIVE_OPERATIONS')
    for operation in [operations[0],operations[-1]]:
        raw = ui_zero_operation(context,operation,['docker','ps','-aq','--no-trunc'])
        rows = raw.decode().splitlines()
        require(len(rows) == len(set(rows)) and sorted(rows) == ids, 'UI_ZERO_STABLE_FULL_GLOBAL_CID_ENUMERATION')
    if ids:
        fmt = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
        first_raw = ui_zero_operation(context,operations[0],['docker','ps','-aq','--no-trunc'])
        actual_order = first_raw.decode().splitlines()
        require(sorted(actual_order) == ids and len(actual_order) == len(set(actual_order)), 'UI_ZERO_GLOBAL_EXACT_ORIGINAL_ENUMERATION_ORDER')
        raw = ui_zero_operation(context,operations[1],['docker','inspect','--format',fmt,*actual_order])
        rows = [json.loads(line) for line in raw.decode().splitlines()]
        require(len(rows) == len(ids) and {row['id'] for row in rows} == set(ids), 'UI_ZERO_FULL_GLOBAL_NATIVE_PROJECTIONS')
        names = set(value['networks']['names'])|set(value['networks']['ids'])
        netids = set(value['networks']['ids'])
        volume_names = set(value['volumes'])
        for row in rows:
            require(type(row) is dict and set(row) == {'id','mounts','network_mode','networks'}
                and type(row['mounts']) is list and all(type(r) is dict for r in row['mounts'])
                and type(row['network_mode']) is str and type(row['networks']) is dict
                and all(type(r) is dict for r in row['networks'].values()), 'UI_ZERO_COMPLETE_GLOBAL_NATIVE_FIELDS')
            mode = row['network_mode']
            consumes = (any(r.get('Name') in volume_names for r in row['mounts'])
                or mode in names or bool(set(row['networks'])&names)
                or any(r.get('NetworkID') in netids for r in row['networks'].values())
                or mode.startswith('container:') and mode[10:] in set(targets)|set(historical))
            require(not consumes or row['id'] in allowed, 'UI_ZERO_NO_FOREIGN_TWO_NETWORK_TWELVE_VOLUME_NAMESPACE_CONSUMER')


def ui_zero_global(context, targets, historical):
    raw,native,projection = ui_zero_native(context,'ui-zero-global-enumerate',['docker','ps','-aq','--no-trunc'])
    ids = raw.decode().splitlines()
    require(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}',cid) for cid in ids), 'UI_ZERO_FRESH_COMPLETE_GLOBAL_IDS')
    operations = [{'label':native['label'],'native':native,'projection':projection}]
    if ids:
        fmt = '{"id":{{json .Id}},"mounts":{{json .Mounts}},"network_mode":{{json .HostConfig.NetworkMode}},"networks":{{json .NetworkSettings.Networks}}}'
        raw,native,projection = ui_zero_native(context,'ui-zero-global-inspect',['docker','inspect','--format',fmt,*ids])
        operations.append({'label':native['label'],'native':native,'projection':projection})
    raw,native,projection = ui_zero_native(context,'ui-zero-global-enumerate-after',['docker','ps','-aq','--no-trunc'])
    operations.append({'label':native['label'],'native':native,'projection':projection})
    value = {'ids':sorted(ids),'historical_absent':historical,'targets':sorted(targets),'allowed':[],
        'networks':{'ids':sorted([context['network_id'],ui_zero_network_identity(context)['Id']]),
            'names':sorted([context['initial_network']['Name'],context['project']+'_frontend'])},
        'volumes':sorted(row['name'] for row in ui_zero_volumes(context)),'operations':operations,'unexpected_consumers':[]}
    ui_zero_global_archive(context,value,targets,set(),historical)
    return value


def ui_zero_port_archive(context, value):
    frozen = load(Path(context['run_dir'])/'preparation-source-binding.json')
    port = verify_ui_web_freeze(frozen,context['repo_dir'])
    require(type(value) is dict and set(value) == {'port','origin','rows','native','projection'}
        and value['port'] == port and value['origin'] == frozen['ui_web_origin'] and value['rows'] == [], 'UI_ZERO_SAME_FROZEN_OS_PORT_ARCHIVE')
    raw = ui_zero_operation(context,{'label':value['native']['label'],'native':value['native'],'projection':value['projection']},
        ui_web_port_argv(port))
    require(ui_web_port_rows(raw,port) == [], 'UI_ZERO_FROZEN_OS_TCP_PORT_HAS_NO_LISTENER')


def ui_zero_port(context):
    frozen = load(Path(context['run_dir'])/'preparation-source-binding.json')
    port = verify_ui_web_freeze(frozen,context['repo_dir'])
    raw,native,projection = ui_zero_native(context,'ui-zero-current-os-port',ui_web_port_argv(port))
    value = {'port':port,'origin':frozen['ui_web_origin'],'rows':ui_web_port_rows(raw,port),'native':native,'projection':projection}
    ui_zero_port_archive(context,value)
    return value


# Independent UI business consumer. Exact closed auxiliary8 Source authority; runtime not attested.
UI_AUX_SOURCE_PIN = {'bytes': 1222, 'sha256': '83b9267b2759b9bf8b120e734f2c4f595ce01f08743c96ed93ada98e403bda7d'}
UIZB_FIRST_READ_SET = None
UIZB_DATA_PIN = {'bytes': 379822, 'sha256': 'f1e779f084c695b077a47de5705bd7413a597614984f9c6f9ca0fab9df0c3f33'}
UIZB_RELATION_IDS = ['SAME_BINDING9', 'PREPARED_ROOT_ACTOR_JOIN', 'VERIFIED_PREVIOUS_DTO_JOIN', 'Q2_Q1_EXACT', 'ORIGINAL_SOURCE_FINGERPRINT', 'PRIMARY_REQUEST_UNIQUE', 'CASE20_ORIGINAL_IMAGE_WIRE', 'FULL_ORIGINAL_PUBLIC_HASH', 'PRIVATE_TICKET_CONTENT_PAIR', 'NATIVE_LOGIN_TOKEN_JOIN', 'SOURCE_BOUND_ORACLE_ACK', 'SAME_NODE_NATIVE42_EOF', 'EXACT_ASSOCIATED_SET', 'PUBLIC_LIST_CONFIG_DISTINCT', 'DOM_CURRENT_OWNED_TAB_WINDOW', 'SCREENSHOT_ORIGINAL_PNG', 'FULL_PRESERVATION', 'CUSTOMER_LOGIN_REQUIRED_STARTUP', 'REQUIRED_POST_ACTION_READBACK', 'ORDINARY_LOGOUT_ORDER', 'PUBLIC_LOGOUT_REQUIRED_HOME', 'PRIVATE_PAIR_COUNT_ORDER', 'SOURCE_LABEL_COMPOSITION', 'GENUINE_DUAL_HOST_GATE_NATIVE', 'HANDOFF_CURRENT_PUBLIC_READBACK', 'READY_NATIVE_PARENT_CHILD_SOURCE_JOIN', 'REAL_PROCESS_GROUP_JOIN', 'WRITER_PRIVATE_DESCRIPTOR_ONLY', 'SOURCE_BOUND_PREPARED_NATIVE', 'PREPARED_MENUS_NATIVE_SOURCE_JOIN', 'PREPARED_OBSERVER_ONLY_SESSION_EXPIRY_ZERO', 'PREPARED_CHILD_NATIVE_TERMINAL_JOIN', 'FROZEN_ORIGIN_CANONICAL', 'BACKEND_GATEWAY_DISTINCT', 'AUTH_PRESENT_SUBJECT_EQUIVALENCE', 'ALL_THREE_LOGIN_TOKEN_REQUIRED', 'CURRENT_THREE_SOURCE', 'EXACT_ORDERED17', 'EXACT_STEP_FACT_SCHEMA', 'FINITE_POSITIVE_IN_VIEWPORT', 'FULL_NATIVE_ROOT_SET', 'FULL_NATIVE_TABLE_SET', 'FULL_ORIGINAL_DTO_RETAINED', 'FULFILLMENT_FIXED20_STATE', 'CHILD_LOCAL_ITEM_AND_RANGE_JOIN', 'NATIVE_ATTACHMENT_SAME_ORIGIN_FULL_PNG', 'VERIFIED_PUBLIC_ATTACHMENT_REFERENCE', 'NATIVE_SEQUENCE_BARRIER', 'NATIVE_U64_BOUND', 'NONEMPTY_TRIMMED_TEXT', 'PRIVATE_STDIN_RAM_ONLY', 'PUBLIC_SECRET_FIELDS_ABSENT', 'REAL_PUBLIC_RELATIVE_TIME_INTERVAL', 'REQUEST_INSIDE_WINDOW', 'SEQUENTIAL_REQUEST_INDEX', 'CASE20_RELATIVE_HOST_SCREENSHOT_JOIN', 'CASE20_VIEWPORT_ORDER']
UIZB_STEPS = ['login-customer','login-merchant','login-platform','n1-initial-read',
    'n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read','n1-merchant-approve',
    'ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1','receive-q1-all-eligible',
    'n2-public-image-upload','n2-review-submit','n2-pending-public-read','n2-platform-moderate',
    'n2-merchant-reply','n2-public-review-read','n2-public-image-read']
UIZB_ROLES = ['customer','merchant','platform','customer','customer','customer','customer','merchant',
    'merchant','customer','customer','merchant','customer','customer','customer','public','platform','merchant','public','public']
UIZB_AUX_ROLES = {'reader':'reader/ms4-ui-private-reader.mjs','caller':'ms4-ui-private-caller.mjs',
    'browser_close':'ms4-ui-browser-close.mjs','business_producer':'ms4-ui-business-producer.mjs',
    'observer_bridge':'ms4-ui-window-native-bridge.mjs','network_capture':'ms4-ui-network-capture.mjs',
    'dom_producer':'ms4-ui-dom-producer.mjs'}
UIZB_PHP_PATHS = ['help/audit-regressions/isolated/ms4-ui-backend.php',
    'help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php',
    'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php']


def uizb_keys(value,names):
    require(type(value) is dict and set(value)==set(names),'UIZB_EXACT_TYPED_KEYS')


def uizb_int(value,minimum=0,maximum=9007199254740991):
    require(type(value) is int and minimum<=value<=maximum,'UIZB_EXACT_SAFE_INTEGER')
    return value


def uizb_hex(value,length=64):
    require(type(value) is str and re.fullmatch('[a-f0-9]{%d}'%length,value) is not None,'UIZB_EXACT_HEX')
    return value


def uizb_number(value,minimum=None):
    import math
    require(type(value) in [int,float] and math.isfinite(value) and (minimum is None or value>=minimum),'UIZB_FINITE_NUMBER')
    return value


def uizb_same(a,b):
    if type(a) in [int,float] and type(b) in [int,float]:
        return a==b
    if type(a) is not type(b):
        return False
    if type(a) is dict:
        return set(a)==set(b) and all(uizb_same(a[k],b[k]) for k in a)
    if type(a) is list:
        return len(a)==len(b) and all(uizb_same(x,y) for x,y in zip(a,b))
    return a==b


def uizb_json(raw):
    require(type(raw) is bytes and b'\x00' not in raw,'UIZB_COMPLETE_UTF8_JSON')
    def pairs(rows):
        result={}
        for key,value in rows:
            require(key not in result,'UIZB_NO_DUPLICATE_JSON_KEY')
            result[key]=value
        return result
    def invalid(_):
        raise RuntimeError('UIZB_NO_NONFINITE_JSON_LITERAL')
    value=json.loads(raw.decode('utf-8','strict'),object_pairs_hook=pairs,parse_constant=invalid)
    uizb_json_value(value)
    return value


def uizb_json_value(value,depth=0):
    require(depth<=128,'UIZB_BOUNDED_JSON_DEPTH')
    if value is None or type(value) is bool:
        return
    if type(value) is str:
        value.encode('utf-8','strict')
        return
    if type(value) in [int,float]:
        uizb_number(value)
        return
    require(type(value) in [dict,list],'UIZB_JSON_ONLY')
    if type(value) is dict:
        require(all(type(k) is str for k in value),'UIZB_STRING_JSON_KEYS')
    for item in (value.values() if type(value) is dict else value):
        uizb_json_value(item,depth+1)


def uizb_canonical(value):
    if type(value) is dict:
        return '{'+','.join(uizb_canonical(k)+':'+uizb_canonical(value[k]) for k in sorted(value,key=lambda s:s.encode('utf-8')))+'}'
    if type(value) is list:
        return '['+','.join(uizb_canonical(v) for v in value)+']'
    if type(value) is float:
        from decimal import Decimal
        uizb_number(value)
        if value==0:
            return '0'
        text=repr(value).lower()
        if 1e-6<=abs(value)<1e21:
            result=format(Decimal(text),'f')
            return result.rstrip('0').rstrip('.') if '.' in result else result
        if 'e' not in text:
            text=format(Decimal(text).normalize(),'e')
        mantissa,exponent=text.split('e')
        mantissa=mantissa.rstrip('0').rstrip('.') if '.' in mantissa else mantissa
        number=int(exponent)
        return mantissa+'e'+('+' if number>=0 else '-')+str(abs(number))
    return json.dumps(value,ensure_ascii=False,separators=(',',':'),allow_nan=False).replace('\u2028','\\u2028').replace('\u2029','\\u2029')


def uizb_hash(value):
    return sha(uizb_canonical(value).encode('utf-8'))


def uizb_pin(value,empty=False):
    uizb_keys(value,['bytes','sha256']);uizb_int(value['bytes'],0 if empty else 1,268435456);uizb_hex(value['sha256'])


def uizb_safe(value):
    if type(value) is dict:
        for key,item in value.items():
            require(re.fullmatch(r'(token|access_token|refresh_token|read_ticket|request_ticket|password|passwd|pwd|authorization|cookie|set-cookie|credential_stamp|sid|session_version)',key,re.I) is None,'UIZB_NO_PRIVATE_PUBLIC_FIELD')
            uizb_safe(item)
    elif type(value) is list:
        for item in value:
            uizb_safe(item)


def uizb_relative(value,empty=False):
    require(type(value) is str and (value!='' or empty) and not re.search(r'(^/|\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)',value),'UIZB_CANONICAL_RELATIVE_MEMBER')
    return value


def uizb_public_leaf(leaf):
    fixed={'ui-business-window.json','ui-business-dom.json','ui-browser-normal-close.json','ui-browser-normal-close-attempt.json',
        'ui-window-baseline.json','ui-window-final.json','ui-window-observer-source.json','ui-window-source-preserved.json',
        'ui-window-predecessors-preserved.json','ui-window-private-inputs-preserved.json','ui-window-public-materials-preserved.json',
        'ui-window-observer-created.json','ui-window-observer-terminal.json','ui-window-observer.stdout','ui-window-observer.stderr'}
    for i in range(1,21):
        n='%02d'%i
        fixed.update({'ui-window-'+n+'-'+part+'.json' for part in ['before','after','verified','expected']})
        fixed.update({'ui-browser-step-'+n+'.json','ui-business-step-'+n+'.json'})
    if leaf in fixed:
        return True
    match=re.fullmatch(r'ui-dom-(measure|observation|shot)-([0-9]{3})\.(json|png)',leaf)
    return bool(match and 1<=int(match[2])<=128 and ((match[1]=='shot')==(match[3]=='png')))


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


class UiZeroBusinessConsumer:
    def __init__(self,context_file,expected):
        self.seen={}
        self.context_file=Path(context_file)
        context_raw=self.read(self.context_file,33554432)
        self.context=uizb_json(context_raw);self.root=Path(self.context['run_dir']);self.e=self.root/'evidence'
        require(self.context_file.absolute()==(self.root/'actual-run-context.json').absolute()
            and self.root.name==self.context['run_id'] and Path(self.context['prep_dir'])==ARTIFACTS,'UIZB_SAME_CANONICAL_CONTEXT')
        frozen_raw=self.read(self.root/'preparation-source-binding.json',33554432);self.frozen=uizb_json(frozen_raw)
        require(sha(frozen_raw)==self.context['freeze_sha256'] and self.frozen['source_sha']==self.context['source_sha']
            and self.frozen['status']=='CLEAN_SOURCE_FROZEN_ONLY' and [r['path'] for r in self.frozen['artifact_files']]==UI_COPY_ARTIFACTS,'UIZB_ORIGINAL_COMPLETE_P17_FREEZE')
        self.artifacts={}
        for row in self.frozen['artifact_files']:
            uizb_keys(row,['path','bytes','sha256']);uizb_pin({'bytes':row['bytes'],'sha256':row['sha256']})
            raw=self.read(ARTIFACTS/row['path'],268435456)
            require(self.pin(raw)=={'bytes':row['bytes'],'sha256':row['sha256']},'UIZB_ACTUAL_FROZEN_ARTIFACT_BYTES')
            self.artifacts[row['path']]=self.pin(raw)
        self.fixture=self.read(ARTIFACTS/'ms4-order.png',33554432)
        require(self.pin(self.fixture)=={'bytes':234,'sha256':'1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8'},'UIZB_ORIGINAL_COMPLETE_SOURCE_PNG')
        ready_raw=self.read(self.e/'ui-web-control-ui-backend-ready.json',33554432);ready=uizb_json(ready_raw)
        ui_web_control_shape(self.context,'ui-backend-ready.json',ready)
        gateway=uizb_json(self.read(self.e/'ui-gateway-created.json',33554432));backend=uizb_json(self.read(self.e/'ui-backend-created.json',33554432))
        self.binding={'run_id':self.context['run_id'],'source_sha':self.context['source_sha'],
            'freeze_sha256':self.context['freeze_sha256'],'context_sha256':sha(context_raw),
            'backend_cid':backend['id'],'gateway_cid':gateway['id'],'ready_sha256':sha(ready_raw),'origin':self.frozen['ui_web_origin']}
        require(uizb_same(self.binding,expected) and backend['id']!=gateway['id'],'UIZB_RECONSTRUCTED_ORIGINAL_BINDING8')
        for key in ['backend_cid','gateway_cid','freeze_sha256','context_sha256','ready_sha256']:
            uizb_hex(self.binding[key])
        uizb_hex(self.binding['source_sha'],40)
        require(re.fullmatch(r'[0-9]{14}[a-f0-9]{6}',self.binding['run_id']) is not None
            and re.fullmatch(r'http://127\.0\.0\.1:[1-9][0-9]{3,4}',self.binding['origin']) is not None
            and self.binding['origin']=='http://127.0.0.1:'+str(uizb_int(self.frozen['ui_web_port'],1024,65535)),'UIZB_FIXED_ORIGIN_AND_RUN')
        self.ready=ready;self.backend=backend;self.gateway=gateway
        self.auxiliary()
        self.binding['source_closure']={'context':self.pin(context_raw),'freeze':self.pin(frozen_raw),'artifacts':self.artifacts,
            **{role:self.aux['members'][UIZB_AUX_ROLES[role]] for role in ['reader','caller','browser_close']}}
        handoff=uizb_json(self.read(self.e/'ui-private-caller-handoff.json',33554432))
        uizb_keys(handoff,['schema','scope','run_id','source_sha','freeze_sha256','container_id','source_closure','reader_inputs','ready',
            'host_gates','private_reader_returned_three_roles','form_login_business_DOM','full_acceptance'])
        closure5={k:v for k,v in self.binding['source_closure'].items() if k!='browser_close'}
        require(type(handoff['schema']) is int and handoff['schema']==1 and handoff['scope']=='MS4_UI_PRIVATE_CALLER_HANDOFF_ONLY'
            and handoff['container_id']==self.binding['backend_cid'] and handoff['source_closure']==closure5
            and all(handoff[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256'])
            and handoff['private_reader_returned_three_roles'] is True and handoff['form_login_business_DOM']=='NOT RUN'
            and handoff['full_acceptance']=='INCOMPLETE','UIZB_SAME_CALLER_SOURCE_HANDOFF')
        for key,name in [('reader_inputs','ui-web-private-inputs.json'),('ready','ui-web-control-ui-backend-ready.json')]:
            d=handoff[key];uizb_keys(d,['path','bytes','sha256'])
            require(d['path']=='/run/audit/evidence/'+name and self.pin(self.read(self.e/name,33554432))=={k:d[k] for k in ['bytes','sha256']},'UIZB_HANDOFF_ORIGINAL_PUBLIC_INPUT_DESCRIPTOR')
        self.handoff=handoff;self.windows=[];self.events=[];self.classified={};self.binding_stack=set()
        self.state={'actors':{},'merchant':None,'file_id':None,'approval':None,'available':None,'review_id':None,'image':None,'witnesses':[]}

    @staticmethod
    def pin(raw):
        return {'bytes':len(raw),'sha256':sha(raw)}

    def read(self,path,maximum,empty=False):
        path=Path(path)
        for part in [path,*path.parents]:
            require(not(getattr(part.lstat(),'st_file_attributes',0)&1024),'UIZB_NO_REPARSE_ANCESTOR')
        before=path.lstat();raw=ui_zero_file(path);after=path.lstat()
        identity=tuple(getattr(before,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns'])
        require(identity==tuple(getattr(after,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns'])
            and (0 if empty else 1)<=len(raw)<=maximum,'UIZB_FULL_STABLE_BOUNDED_READ')
        observed={'pin':self.pin(raw),'identity':identity};name=path.absolute().as_posix()
        require(name not in self.seen or self.seen[name]==observed,'UIZB_SAME_ORIGINAL_BYTES_AND_IDENTITY')
        self.seen[name]=observed
        return raw

    def public(self,descriptor,leaf,canonical=False,empty=False):
        require(uizb_public_leaf(leaf),'UIZB_FIXED_PUBLIC_RESOLVER_ONLY');uizb_keys(descriptor,['path','bytes','sha256'])
        require(descriptor['path']=='/run/audit/evidence/'+leaf,'UIZB_EXACT_PUBLIC_LOGICAL_PATH')
        uizb_pin({k:descriptor[k] for k in ['bytes','sha256']},empty);raw=self.read(self.e/leaf,268435456,empty)
        require(self.pin(raw)=={k:descriptor[k] for k in ['bytes','sha256']},'UIZB_COMPLETE_ORIGINAL_DESCRIPTOR')
        if canonical:
            value=uizb_json(raw);uizb_safe(value)
            require(raw==(uizb_canonical(value)+'\n').encode('utf-8'),'UIZB_CANONICAL_PAYLOAD_NOT_NATIVE_STDOUT')
            return value
        return raw

    def aggregate(self,leaf):
        require(leaf in ['ui-business-window.json','ui-business-dom.json','ui-browser-normal-close.json'],'UIZB_FIXED_AGGREGATE')
        raw=self.read(self.e/leaf,268435456);value=uizb_json(raw);uizb_safe(value)
        return value,{'path':'/run/audit/evidence/'+leaf,**self.pin(raw)}

    def auxiliary(self):
        require(type(UI_AUX_SOURCE_PIN) is dict,'UIZB_FINAL_AUXILIARY_MANIFEST_NOT_ADOPTED');uizb_pin(UI_AUX_SOURCE_PIN)
        self.auxroot=ARTIFACTS.parent/'browser-v1';raw=self.read(self.auxroot/'PINS.json',33554432)
        require(self.pin(raw)==UI_AUX_SOURCE_PIN,'UIZB_EXACT_AUXILIARY_SOURCE_ANCHOR')
        self.aux=uizb_json(raw);uizb_keys(self.aux,['members']);names=set(UIZB_AUX_ROLES.values())|{'ui-business-consumer-contract.json'}
        uizb_keys(self.aux['members'],names)
        for directory in ['','reader']:
            folder=self.auxroot/directory
            for part in [folder,*folder.parents]:
                require(not part.is_symlink() and not getattr(part,'is_junction',lambda:False)()
                    and not(getattr(part.lstat(),'st_file_attributes',0)&1024),'UIZB_AUX_NO_LINK_OR_REPARSE')
            expected={x[len(directory)+1:] if directory else x for x in names if (x.startswith(directory+'/') if directory else True)}
            expected={x.split('/')[0] for x in expected}
            if not directory:
                expected.add('PINS.json')
            require({p.name for p in folder.iterdir()}==expected,'UIZB_EXACT_EIGHT_AUX_MEMBERS')
        for name,row in self.aux['members'].items():
            uizb_pin(row);source=self.read(self.auxroot/name,33554432)
            require(self.pin(source)==row,'UIZB_FULL_AUXILIARY_SOURCE_BYTES')
            if name.endswith('.mjs'):
                self.local_imports(name,source.decode('utf-8','strict'),names)
        self.aux_descriptor={'path':'browser-v1/PINS.json',**self.pin(raw)}
        data_raw=self.read(self.auxroot/'ui-business-consumer-contract.json',33554432)
        require(self.pin(data_raw)==UIZB_DATA_PIN,'UIZB_EXACT_FINAL_ROOT_DATA_BYTES')
        self.data=uizb_json(data_raw)
        uizb_keys(self.data,['schema','scope','version','php_sources','auxiliary_roles','steps','wire_rules','dom_rules','viewports','schemas']+(['task2'] if 'task2' in self.data else []))
        require('task2_observer_source' not in self.frozen or ('task2' in self.data and 'task2_browser_source' in self.frozen),'UIZB_INITIAL_TASK2_REQUIRES_FULL_REGISTRY_AND_BROWSER_GROUP')
        require(type(self.data['schema']) is int and self.data['schema']==1 and type(self.data['version']) is int
            and self.data['version']==1 and self.data['scope']=='MS4_UI_BUSINESS_CONSUMER_CONTRACT'
            and self.data['auxiliary_roles']==UIZB_AUX_ROLES and self.data['viewports']==[320,390,1440]
            and type(self.data['php_sources']) is dict and set(self.data['php_sources'])==set(UIZB_PHP_PATHS),'UIZB_EXACT_ROOT_DATA_V1_AND_SELECTED_PHP_SOURCE')
        self.php=self.data['php_sources']
        sources={r['path']:r for r in self.frozen['ui_backend_source_files']}
        for path in UIZB_PHP_PATHS:
            row=self.php[path];uizb_pin(row)
            require(sources.get(path)=={'path':path,**row}
                and self.pin(self.read(Path(self.context['repo_dir'])/path,33554432))==row,'UIZB_PHP_SOURCE_FULL_FROZEN_CLOSURE')

    def local_imports(self,name,text,names):
        from posixpath import dirname,normpath,join
        imports=re.findall(r"(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s*)[\"']([^\"']+)[\"']",text)
        for item in imports:
            if item.startswith('node:'):
                require(item in {'node:fs','node:path','node:url','node:crypto','node:util','node:zlib','node:child_process'},'UIZB_FINITE_NODE_BUILTINS')
            else:
                require(item.startswith('./') or item.startswith('../'),'UIZB_NO_IMPLICIT_EXTERNAL_IMPORT')
                require(normpath(join(dirname(name),item)) in names,'UIZB_COMPLETE_AUX_LOCAL_IMPORT_MEMBERSHIP')

    def projection(self,value):
        uizb_keys(value,['encoding','rows','sha256']);uizb_int(value['rows']);uizb_hex(value['sha256'])
        require(value['encoding']=='php-serialize-row-sha256-sorted-multiset-v1'
            and (value['rows']!=0 or value['sha256']==sha(b'')),'UIZB_COMPLETE_NATIVE_PROJECTION')

    def tree(self,value):
        if value is None:
            return
        require(type(value) is dict and '' in value,'UIZB_COMPLETE_NATIVE_TREE_ROOT')
        for name,row in value.items():
            uizb_relative(name,True)
            base=['kind','dev','ino','mode','uid','gid','nlink']
            require(type(row) is dict and row.get('kind') in ['file','directory'],'UIZB_NATIVE_TREE_KIND')
            uizb_keys(row,base+(['bytes','sha256','mtime','ctime'] if row['kind']=='file' else []))
            for key in ['dev','ino','uid','gid']:
                uizb_int(row[key])
            uizb_int(row['mode'],0,4095);uizb_int(row['nlink'],1)
            if name:
                parent=name.rsplit('/',1)[0] if '/' in name else ''
                require(parent in value and value[parent]['kind']=='directory','UIZB_COMPLETE_TREE_PARENT_CHAIN')
            if row['kind']=='file':
                require(row['nlink']==1,'UIZB_NATIVE_SINGLE_LINK_FILE');uizb_int(row['bytes']);uizb_hex(row['sha256'])
                uizb_int(row['mtime'],-9007199254740991);uizb_int(row['ctime'],-9007199254740991)

    def ledger(self,value,index,point):
        uizb_keys(value,['schema','scope','binding','index','point','database','redis','files'])
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='MS4_UI_COMPLETE_NATIVE_WINDOW_LEDGER'
            and value['binding']==self.binding and type(value['index']) is int and value['index']==index and value['point']==point,'UIZB_EXACT_COMPLETE_LEDGER')
        db=value['database'];uizb_keys(db,['names','namespace_sha256','schema_modes_sha256','tables'])
        require(type(db['names']) is list and all(type(n) is str and re.fullmatch('[A-Za-z0-9_]+',n) for n in db['names'])
            and db['names']==sorted(set(db['names'])) and type(db['tables']) is dict and set(db['tables'])==set(db['names']),'UIZB_WHOLE_DATABASE_NAMESPACE')
        uizb_hex(db['namespace_sha256']);uizb_hex(db['schema_modes_sha256'])
        native='a:%d:{'%len(db['names'])
        for i,name in enumerate(db['names']):
            native+='i:%d;s:%d:"%s";'%(i,len(name.encode('utf-8')),name)
        native+='}'
        require(sha(native.encode('utf-8'))==db['namespace_sha256'],'UIZB_NATIVE_PHP_NAMESPACE_SERIALIZATION')
        for row in db['tables'].values():
            uizb_keys(row,['metadata_sha256','ddl_sha256','projected_columns_sha256','projection'])
            for key in ['metadata_sha256','ddl_sha256','projected_columns_sha256']:
                uizb_hex(row[key])
            self.projection(row['projection'])
        redis=value['redis'];uizb_keys(redis,['time_ms','keys'])
        require(type(redis['time_ms']) is str and re.fullmatch(r'(0|[1-9][0-9]*)',redis['time_ms']) is not None
            and type(redis['keys']) is dict,'UIZB_FULL_REDIS_OBSERVATION')
        for key,row in redis['keys'].items():
            uizb_hex(key);uizb_keys(row,['type','sha256','expires','dump_sha256'])
            require(row['type'] in ['string','hash','set','list','zset','stream'],'UIZB_NATIVE_REDIS_TYPE')
            uizb_hex(row['sha256']);uizb_hex(row['dump_sha256']);uizb_int(row['expires'],-1)
            require(row['expires']==-1 or row['expires']>0,'UIZB_ORIGINAL_ABSOLUTE_EXPIRY')
        uizb_keys(value['files'],['roots'])
        roots=['/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence','/workspace/crmeb/public','/workspace/crmeb/runtime',
            '/tmp/ms4-fulfillment-normal-'+self.binding['run_id'],'/run/audit-ui-control','/run/audit-app/config.json','/tmp']
        uizb_keys(value['files']['roots'],roots)
        for tree in value['files']['roots'].values():
            self.tree(tree)

    def ledger_equal(self,left,right):
        require(left['database']==right['database'] and left['redis']['keys']==right['redis']['keys']
            and left['files']==right['files'] and int(right['redis']['time_ms'])>=int(left['redis']['time_ms']),
            'UIZB_FULL_LEDGER_EQUAL_EXCEPT_POINT_INDEX_NATIVE_TIME')

    def table_delta(self,before,after,expected):
        uizb_keys(expected,['schema','scope','name','tables','independent','all_other_database_redis_files_verified',
            'transient_ticket_native_observation','full_twenty_case_acceptance'])
        require(type(expected['schema']) is int and expected['schema']==2 and expected['scope']=='MS4_UI_INDEPENDENT_NORMAL_CASE_EXPECTATION'
            and expected['all_other_database_redis_files_verified'] is True and expected['transient_ticket_native_observation']=='NOT_CLAIMED'
            and expected['full_twenty_case_acceptance'] is False and type(expected['tables']) is dict,'UIZB_TYPED_EXPECTED2_SOURCE_ORACLE')
        a=before['database'];z=after['database']
        require(a['names']==z['names'] and a['namespace_sha256']==z['namespace_sha256']
            and set(expected['tables'])<=set(a['names']),'UIZB_NO_TABLE_NAMESPACE_CHANGE')
        inserted=0
        for name,row in z['tables'].items():
            old=a['tables'][name]
            if name not in expected['tables']:
                require(row==old,'UIZB_ALL_OTHER_TABLE_FIELDS_AND_ROWS_UNCHANGED')
            else:
                e=expected['tables'][name];uizb_keys(e,['projection','natural_insert_count']);self.projection(e['projection'])
                count=uizb_int(e['natural_insert_count']);inserted+=count
                require(row['projection']==e['projection'] and row['projected_columns_sha256']==old['projected_columns_sha256']
                    and row['projection']['rows']==old['projection']['rows']+count,'UIZB_COMPLETE_EXPECTED_TABLE_PROJECTION_AND_NATURAL_INSERT')
                if count==0:
                    require(row['metadata_sha256']==old['metadata_sha256'] and row['ddl_sha256']==old['ddl_sha256'],'UIZB_NO_UNEXPECTED_TABLE_METADATA_WRITE')
        if inserted==0:
            require(a['schema_modes_sha256']==z['schema_modes_sha256'],'UIZB_NO_UNEXPECTED_NATIVE_SCHEMA_OR_INCREMENT_CHANGE')
        require(int(after['redis']['time_ms'])>=int(before['redis']['time_ms']),'UIZB_NATIVE_TIME_ORDER')
        # Full Redis/DUMP/absolute-expiry and file/log deltas are verified by the pinned native
        # independent oracle, joined to all complete actual ledgers/frames, not this flag alone.

    def observer_native(self,value):
        uizb_keys(value,['created','terminal','stdout','stderr'])
        created=uizb_json(self.public(value['created'],'ui-window-observer-created.json'))
        terminal=uizb_json(self.public(value['terminal'],'ui-window-observer-terminal.json'))
        stdout=self.public(value['stdout'],'ui-window-observer.stdout');stderr=self.public(value['stderr'],'ui-window-observer.stderr',empty=True)
        uizb_keys(created,['label','executable','argv','pid'])
        uizb_keys(terminal,['schema','scope','executable','argv','pid','close_code','close_signal','stdout','stderr'])
        argv=['exec','-i',self.binding['backend_cid'],'php','/workspace/help/audit-regressions/isolated/ms4-ui-backend.php','observe']
        uizb_int(created['pid'],1)
        require(created['label']=='ui-window-observer' and created['executable']=='docker' and created['argv']==argv
            and type(terminal['schema']) is int and terminal['schema']==1 and terminal['scope']=='MS4_UI_OBSERVER_NODE_NATIVE'
            and terminal['executable']==created['executable'] and terminal['argv']==argv and type(terminal['pid']) is int
            and terminal['pid']==created['pid'] and type(terminal['close_code']) is int and terminal['close_code']==0
            and terminal['close_signal'] is None and stderr==b'','UIZB_SAME_SPAWN_NODE_CLOSE_ZERO_NO_PYTHON_WAIT')
        for key,raw in [('stdout',stdout),('stderr',stderr)]:
            stream=terminal[key];uizb_keys(stream,['bytes','sha256','stored_bytes','stored_sha256','complete'])
            uizb_int(stream['bytes']);uizb_int(stream['stored_bytes']);uizb_hex(stream['sha256']);uizb_hex(stream['stored_sha256'])
            require(stream['bytes']==stream['stored_bytes']==len(raw) and stream['sha256']==stream['stored_sha256']==sha(raw)
                and stream['complete'] is True,'UIZB_COMPLETE_ORIGINAL_NODE_STREAM')
        require(0<len(stdout)<=268435456 and stdout.endswith(b'\n'),'UIZB_COMPLETE_STDOUT_WITH_REAL_FINAL_LF')
        lines=stdout.splitlines(keepends=True);require(len(lines)==42,'UIZB_EXACT42_ORIGINAL_FRAMES')
        frames=[];slices=[];offset=0
        for i,line in enumerate(lines):
            require(1<len(line)<=16777216 and line.endswith(b'\n') and not line.endswith(b'\r\n'),'UIZB_WHOLE_NATIVE_LF_FRAME')
            frame=uizb_json(line[:-1]);uizb_safe(frame)
            uizb_keys(frame,['schema','scope','binding','sequence','command','step','receipt'])
            command='BEGIN' if i==0 else 'FINISH' if i==41 else 'STEP_BEGIN' if i%2 else 'STEP_VERIFY'
            index=0 if i==0 else 21 if i==41 else (i+1)//2
            require(type(frame['schema']) is int and frame['schema']==1 and frame['scope']=='MS4_UI_OBSERVER_SAFE_FRAME'
                and frame['binding']==self.binding and type(frame['sequence']) is int and frame['sequence']==i
                and frame['command']==command and type(frame['step']) is int and frame['step']==index,'UIZB_ACTUAL_ORDERED_FRAME_SCHEDULE')
            uizb_keys(frame['receipt'],{'BEGIN':['baseline'],'STEP_BEGIN':['before'],
                'STEP_VERIFY':['after','verified_after','expected_delta','oracle'],'FINISH':['final','preservation']}[command])
            frames.append(frame);slices.append({'start':offset,'end':offset+len(line),'sha256':sha(line)});offset+=len(line)
        require(offset==len(stdout),'UIZB_NO_PREFIX_SUFFIX_PARTIAL_OR_OMITTED_STDOUT')
        self.native=value;self.pid=created['pid'];self.frames=frames;self.slices=slices

    def native_id(self,value):
        require(type(value) is str and re.fullmatch('[1-9][0-9]{0,19}',value) is not None
            and int(value)<=18446744073709551615,'UIZB_NATIVE_UNSIGNED_ID_STRING')
        return value

    def decimal(self,value):
        require(type(value) is str and re.fullmatch(r'(0|[1-9][0-9]*)\.[0-9]{2}',value) is not None,'UIZB_EXACT_DECIMAL_AMOUNT')

    def wire(self,value,role=None):
        uizb_keys(value,['actor_role','method','path','body_public','body_public_sha256','response_status','response_public','response_public_sha256'])
        require(value['actor_role'] in ['customer','merchant','platform','public'] and (role is None or value['actor_role']==role)
            and value['method'] in ['GET','POST','PUT'] and type(value['path']) is str and value['path'].startswith('/')
            and len(value['path'])<=2048 and not re.search(r'(:\/\/|\.\.|#|\\|[\x00-\x1f\x7f])',value['path'])
            and type(value['response_status']) is int and value['response_status']==200
            and uizb_hash(value['body_public'])==value['body_public_sha256']
            and uizb_hash(value['response_public'])==value['response_public_sha256'],'UIZB_COMPLETE_SAFE_WIRE8')
        uizb_safe(value)

    @staticmethod
    def image_wire(request):
        return {'actor_role':'public','method':request['method'],'path':request['path'],'body_public':request['request_public'],
            'body_public_sha256':request['request_public_sha256'],'response_status':request['http_status'],
            'response_public':request['response_public'],'response_public_sha256':request['response_public_sha256']}

    def prepare_identity(self):
        result=uizb_json(self.read(self.e/UI_PREPARE['result'],33554432))
        uizb_keys(result,UI_PREPARE_RESULT_KEYS)
        require(result['scope']==UI_PREPARE['result_scope'] and result['status']==UI_PREPARE['status']
            and all(result[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256']),'UIZB_ORIGINAL_PREPARE_ACTOR_SOURCE')
        p=result['business'];uizb_keys(p,UI_PREPARE_BUSINESS_KEYS);uizb_keys(p['actor_ids'],['customer','merchant','platform'])
        for role,value in p['actor_ids'].items():
            uizb_int(value,1)
        uizb_int(p['merchant_id'],1);uizb_int(p['paid_order_id'],1)
        require(p['customer_id']==p['actor_ids']['customer'],'UIZB_PREPARED_REAL_CUSTOMER_IDENTITY')
        self.prepare_result=result;self.prepared=p

    def review_parents(self):
        prepared = self.prepared
        customer = uizb_int(prepared['customer_id'],1,2147483647)
        root = uizb_int(prepared['paid_order_id'],1,4294967295)
        uizb_int(prepared['merchant_id'],1,4294967295)
        require(type(prepared['actor_ids']) is dict and prepared['actor_ids']['customer'] == customer
            and type(prepared['actor_ids']['customer']) is int, 'N2_ORIGINAL_PREPARED_NATIVE_CUSTOMER')
        items = prepared['item_quantities']
        require(type(items) is list and len(items) == 2, 'N2_ORIGINAL_PREPARED_TWO_SOURCE_ITEMS')
        for row, quantity in zip(items,(2,1)):
            uizb_keys(row, ('item_id','quantity')); uizb_int(row['item_id'],1,4294967295)
            require(type(row['quantity']) is int and row['quantity'] == quantity, 'N2_ORIGINAL_PREPARED_Q2_Q1')
        require(items[0]['item_id'] != items[1]['item_id'], 'N2_ORIGINAL_DISTINCT_SOURCE_ITEMS')
        return customer, root, items[0]['item_id']

    def event(self,event,index,wire):
        uizb_keys(event,['schema','scope','binding','index','name','role','tab_id_sha256','started_at_ms','finished_at_ms',
            'actions','requests','identity','DOM','diagnostic'])
        require(type(event['schema']) is int and event['schema']==1 and event['scope']=='MS4_UI_NORMAL_BROWSER_STEP'
            and event['binding']==self.binding and type(event['index']) is int and event['index']==index
            and event['name']==UIZB_STEPS[index-1] and event['role']==UIZB_ROLES[index-1]
            and event['diagnostic'] is None,'UIZB_EXACT_BROWSER_EVENT14')
        self.wire(wire,event['role']);uizb_hex(event['tab_id_sha256']);uizb_int(event['started_at_ms'],1);uizb_int(event['finished_at_ms'],event['started_at_ms'])
        require(type(event['actions']) is list and 1<=len(event['actions'])<=256,'UIZB_ACTUAL_NORMAL_ACTIONS')
        for action in event['actions']:
            uizb_keys(action,['kind','target_public','outcome'])
            require(action['kind'] in ['navigate','click','fill','select-files','scroll','viewport','DOM-observe']
                and type(action['target_public']) is str and 0<len(action['target_public'])<=256
                and not re.search(r'[\x00-\x1f\x7f]',action['target_public']) and action['outcome']=='API_RETURNED','UIZB_SAFE_REAL_ACTION_METADATA_NOT_AUTH_PROOF')
        identity=event['identity'];uizb_keys(identity,['application','subject_id','merchant_id','verified_by'])
        role=event['role']
        if role=='public':
            require(identity=={'application':'none','subject_id':None,'merchant_id':None,'verified_by':'actual-anonymous-normal-response'},'UIZB_ACTUAL_ANONYMOUS_EVENT')
        else:
            require(identity['application']=={'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role]
                and type(identity['subject_id']) is int and identity['subject_id']==self.prepared['actor_ids'][role]
                and (type(identity['merchant_id']) is int and identity['merchant_id']==self.prepared['merchant_id'] if role=='merchant' else identity['merchant_id'] is None)
                and identity['verified_by']=='native-session-and-normal-UI','UIZB_EVENT_REAL_PREPARED_ROLE')
        requests=event['requests'];require(type(requests) is list and (index==20 or len(requests)>0),'UIZB_ALL_ACTUAL_ASSOCIATED_REQUESTS')
        self.primary_rule(wire,index)
        matches=[]
        if index==14:
            customer,paid_root,q2=self.review_parents()
            require(role=='customer' and identity['subject_id']==customer and wire['method']=='POST'
                and wire['path']=='/api/order/reviews/'+str(paid_root)+'/'+str(q2)+'/files',
                'UIZB_N2_ORIGINAL_PREPARED_CUSTOMER_ROOT_Q2_UPLOAD')
            uizb_keys(wire['body_public'],['file']);file=wire['body_public']['file']
            uizb_keys(file,['bytes','sha256','mime_type','original_name']);original_name=file['original_name']
            require({k:file[k] for k in ['bytes','sha256']}==self.artifacts['ms4-order.png'] and file['mime_type']=='image/png'
                and type(original_name) is str and 1<=len(original_name)<=255
                and not any(ch in original_name for ch in ['/','\\','\0']) and original_name.lower().endswith('.png'),
                'UIZB_N2_EXACT_FILE_ONLY_PUBLIC_REQUEST_ACTUAL_RAW_UTF8_NAME')
            original_name.encode('utf-8','strict')
            response=wire['response_public'];uizb_keys(response,['name','url','attachment_ref']);reference=response['attachment_ref']
            require(type(reference) is str and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',reference) is not None
                and response['url']==self.binding['origin']+reference and response['name']==reference.rsplit('/',1)[1],
                'UIZB_ACTUAL_PREPARED_CUSTOMER_PUBLIC_UPLOAD_RESPONSE')
            self.state['image']={'reference':reference,'url':response['url'],'image':self.artifacts['ms4-order.png']}
        for j,r in enumerate(requests):
            uizb_keys(r,['sequence','method','path','started_at_ms','finished_at_ms','http_status','app_status','request_public',
                'response_public','request_public_sha256','response_public_sha256','authentication'])
            require(type(r['sequence']) is int and r['sequence']==j,'UIZB_ASSOCIATED_REQUEST_SEQUENCE')
            self.wire({'actor_role':role,'method':r['method'],'path':r['path'],'body_public':r['request_public'],
                'body_public_sha256':r['request_public_sha256'],'response_status':r['http_status'],
                'response_public':r['response_public'],'response_public_sha256':r['response_public_sha256']})
            uizb_int(r['started_at_ms'],event['started_at_ms']);uizb_int(r['finished_at_ms'],r['started_at_ms'])
            require(r['finished_at_ms']<=event['finished_at_ms'],'UIZB_REAL_REQUEST_INSIDE_STEP')
            auth=r['authentication'];uizb_keys(auth,['present','kind','subject_id'])
            if auth['present'] is False:
                require(auth['kind']=='none' and auth['subject_id'] is None,'UIZB_GENUINE_ANONYMOUS_METADATA')
            else:
                require(auth['present'] is True and auth['kind']=='Bearer','UIZB_EXACT_BEARER_KIND');uizb_int(auth['subject_id'],1)
                prefix=next((k for k in ['adminapi','merchantapi','api'] if r['path'].startswith('/'+k+'/')),None)
                mapped={'api':'customer','merchantapi':'merchant','adminapi':'platform'}.get(prefix)
                require(mapped is not None and auth['subject_id']==self.prepared['actor_ids'][mapped],'UIZB_SAME_REAL_REQUEST_ROLE_ID')
            if r['app_status'] is None:
                require(r['method']=='GET' and type(r['request_public']) is dict and r['request_public']=={},'UIZB_RAW_OR_BINARY_GET_ONLY')
                if r['path']=='/api/get_script':
                    require(r['response_public']=='','UIZB_EFFECTIVE_EMPTY_SCRIPT_RESPONSE')
                else:
                    uizb_keys(r['response_public'],['bytes','sha256','mime_type'])
                    require(r['response_public']=={**self.artifacts['ms4-order.png'],'mime_type':'image/png'},'UIZB_ORIGINAL_PINNED_BINARY3')
                    image=self.state['image'];private=re.fullmatch(r'/(api/order/refund/files|merchantapi/aftersale-files)/[1-9][0-9]*/content',r['path'])
                    require(private is not None or image is not None and r['path']==image['reference'],'UIZB_NO_UNKNOWN_BINARY_PROJECTION')
            else:
                require(type(r['app_status']) is int and r['app_status']==200,'UIZB_ACTUAL_SUCCESSFUL_JSON_DTO')
            if r['method']==wire['method'] and r['path']==wire['path'] and r['request_public_sha256']==wire['body_public_sha256'] and r['response_public_sha256']==wire['response_public_sha256']:
                require(uizb_same(r['request_public'],wire['body_public']) and uizb_same(r['response_public'],wire['response_public']),'UIZB_PRIMARY_FULL_VALUE_JOIN')
                matches.append(j)
            self.request_rule(r,index)
        require(len(matches)==1 or index==20 and len(matches)==0,'UIZB_UNIQUE_CURRENT_PRIMARY_OR_CASE20_HISTORY')
        if index<=3:
            require(type(wire['body_public']) is dict and wire['body_public']=={} and wire['method']=='POST'
                and wire['path']==['/api/login','/merchantapi/login','/adminapi/login'][index-1]
                and wire['response_public']=={'subject_id':identity['subject_id']} and requests[matches[0]]['authentication']['present'] is False,'UIZB_ACTUAL_LOGIN_PUBLIC_PROJECTION')
            if index<3:
                path='/api/user' if index==1 else '/merchantapi/me'
                require(any(r['sequence']>matches[0] and r['method']=='GET' and r['path']==path
                    and uizb_same(r['authentication'],{'present':True,'kind':'Bearer','subject_id':identity['subject_id']})
                    and r['started_at_ms']>=requests[matches[0]]['finished_at_ms'] for r in requests),'UIZB_ACTUAL_SAME_STEP_FOLLOWING_IDENTITY_READ')
        self.primary_rule(wire,index)
        return matches[0] if matches else None

    def sequence_fact(self,value,index,wire):
        uizb_keys(value,['schema','scope','number_sha256','key_sha256','initial_present','samples','natural_final_absent','elapsed_ns'])
        answer=wire['response_public'];number=answer['refund']['refund_no'] if index==6 else answer['child_order_no']
        require(type(number) is str and re.fullmatch(r'(cp|cf)[1-9][0-9]{0,18}',number) is not None,'UIZB_REAL_NORMAL_GENERATED_NUMBER')
        key=str(1640966400000+int(number[2:])//4194304)
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='PASSIVE_REAL_NORMAL_SEQUENCE_COUNTER_ONLY'
            and value['number_sha256']==sha(number.encode()) and value['key_sha256']==sha(key.encode())
            and type(value['initial_present']) is bool and value['natural_final_absent'] is True
            and type(value['samples']) is list and value['samples'],'UIZB_REAL_SEQUENCE_BARRIER_TYPED_FACT')
        uizb_int(value['elapsed_ns'],0,10000000000);previous=-1;other=None
        for j,sample in enumerate(value['samples']):
            uizb_keys(sample,['time_ms','present','other_native_sha256'])
            require(type(sample['time_ms']) is str and re.fullmatch('[1-9][0-9]*',sample['time_ms']) is not None
                and int(sample['time_ms'])>=previous and sample['present'] is (j!=len(value['samples'])-1),'UIZB_NATURAL_SEQUENCE_SAMPLES')
            uizb_hex(sample['other_native_sha256'])
            require(other is None or other==sample['other_native_sha256'],'UIZB_ALL_OTHER_NATIVE_REDIS_DURING_BARRIER')
            previous=int(sample['time_ms']);other=sample['other_native_sha256']
        require(value['samples'][0]['present'] is value['initial_present'],'UIZB_REAL_INITIAL_SEQUENCE_OBSERVATION')

    def expected_facts(self,value,index,event,wire,primary):
        per=[['login'],['login'],['login'],[],['private_file_id','private_pairs'],['N1','sequence_barrier','private_pairs'],
            ['private_pairs'],['N1','private_pairs'],['fulfillment','sequence_barrier'],['fulfillment'],['fulfillment'],
            ['fulfillment','sequence_barrier'],['fulfillment'],['public_image'],['review_id'],[],['review_id','changed_fields'],
            ['review_id','changed_fields'],[],['public_image_read']]
        require(value['name']==UIZB_STEPS[index-1],'UIZB_EXPECTED_CASE_NAME')
        facts=value['independent']
        if not per[index-1]:
            require(type(facts) is list and facts==[],'UIZB_ORIGINAL_EMPTY_FACTS_ARRAY_ENCODING')
        else:
            uizb_keys(facts,per[index-1])
        if index<=3:
            login=facts['login'];uizb_keys(login,['application','subject_id','native_session_verified','signed_claims_verified','browser_token_join'])
            require(login['application']==event['identity']['application'] and type(login['subject_id']) is int and login['subject_id']==event['identity']['subject_id']
                and login['native_session_verified'] is True and login['signed_claims_verified'] is True and login['browser_token_join'] is True,
                'UIZB_ALL_THREE_REAL_SIGNED_NATIVE_TOKEN_JOINS')
            self.state['actors'][event['role']]=login['subject_id']
            if index==2:
                self.state['merchant']=event['identity']['merchant_id']
        if index==5:
            self.native_id(facts['private_file_id']);require(facts['private_file_id']==wire['response_public']['id'],'UIZB_NATIVE_PRIVATE_UPLOAD_ID')
            self.state['file_id']=facts['private_file_id']
        if index in [5,6,7,8]:
            pairs=facts['private_pairs'];require(type(pairs) is list and 0<len(pairs)<=16,'UIZB_REAL_PAIRED_PRIVATE_READS')
            seen=set()
            for p in pairs:
                uizb_keys(p,['issued_sequence','read_sequence','file_id','full_response_bytes_verified','net_ticket_absent','native_intermediate_observed'])
                a=uizb_int(p['issued_sequence']);z=uizb_int(p['read_sequence'],a+1,len(event['requests'])-1)
                require(a not in seen and z not in seen and p['file_id']==self.state['file_id'] and p['full_response_bytes_verified'] is True
                    and p['net_ticket_absent'] is True and p['native_intermediate_observed'] is False,'UIZB_PRIVATE_PAIR_FULL_SOURCE_FACTS')
                seen.update([a,z]);issue=event['requests'][a];read=event['requests'][z]
                prefix='/merchantapi/aftersale-files/' if event['role']=='merchant' else '/api/order/refund/files/'
                require(issue['method']=='POST' and issue['path']==prefix+p['file_id']+'/read-ticket' and issue['request_public']=={'schema':1}
                    and read['method']=='GET' and read['path']==prefix+p['file_id']+'/content' and issue['finished_at_ms']<=read['started_at_ms']
                    and read['app_status'] is None and read['response_public']=={**self.artifacts['ms4-order.png'],'mime_type':'image/png'},'UIZB_ORIGINAL_TICKET_CONTENT_REQUEST_JOIN')
        if index in [6,8]:
            expected={'business_state':'REQUESTED' if index==6 else 'APPROVED','physical_state':'NONE',
                'finance_state':'NOT_SUBMITTED' if index==6 else 'UNSUPPORTED','held_amount':'0.01','confirmed_amount':'0.00'}
            require(facts['N1']==expected and all(wire['response_public']['refund'][k]==v for k,v in expected.items()),'UIZB_N1_REAL_MONEY_ONLY_STATES')
        if index in [6,9,12]:
            self.sequence_fact(facts['sequence_barrier'],index,wire)
        if index==8:
            self.state['approval']=self.native_id(wire['response_public']['action_id'])
        if 9<=index<=13:
            f=facts['fulfillment'];uizb_keys(f,['summary','aftersale','child_count']);n=index-9;shipped=[2,2,2,3,3][n];received=[0,1,2,2,3][n];children=1 if n<3 else 2
            expected={'shipping_state':'partial' if shipped==2 else 'shipped','receipt_state':'received' if received==shipped else 'pending' if received==0 else 'partial',
                'closed':index==13,'original':3,'shipped':shipped,'received':received,'cancelled_unshipped':0,'returned_accepted':0,
                'to_ship':3-shipped,'to_receive':shipped-received,'child_count':children}
            uizb_keys(f['summary'],list(expected));require(type(f['summary']['closed']) is bool,'UIZB_TYPED_FULFILLMENT_CLOSED_FLAG')
            for key in ['original','shipped','received','cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count']:
                uizb_int(f['summary'][key])
            require(uizb_same(f['summary'],expected) and uizb_same(f['summary'],wire['response_public']['fulfillment_summary'])
                and type(f['child_count']) is int and f['child_count']==children,'UIZB_TWO_CHILDREN_THREE_UNITS_FIVE_OPERATIONS')
            a=f['aftersale'];uizb_keys(a,['schema','revision','confirmed_amount','held_amount','available_amount','pending_cancel_quantity','pending_return_quantity','returned_after_receipt','fulfillment_completed_at'])
            require(type(a['schema']) is int and a['schema']==1 and a['revision']==self.state['approval'] and a['confirmed_amount']=='0.00' and a['held_amount']=='0.01'
                and all(type(a[k]) is int and a[k]==0 for k in ['pending_cancel_quantity','pending_return_quantity','returned_after_receipt'])
                and a==wire['response_public']['aftersale'],'UIZB_FINANCE_NOT_SUBMITTED_OR_CONFIRMED')
            self.decimal(a['available_amount'])
            if index==13:
                uizb_int(a['fulfillment_completed_at'],1);require(a['fulfillment_completed_at']==wire['response_public']['created_at'],'UIZB_ACTUAL_COMPLETION_TIME')
            else:
                require(a['fulfillment_completed_at'] is None,'UIZB_NOT_PREMATURELY_COMPLETED')
            require(self.state['available'] is None or a['available_amount']==self.state['available'],'UIZB_ORIGINAL_AVAILABLE_AMOUNT')
            self.state['available']=a['available_amount']
        if index==14:
            require(facts['public_image']==self.artifacts['ms4-order.png'],'UIZB_PUBLIC_UPLOAD_PINNED_IMAGE')
        if index in [15,17,18]:
            uizb_int(facts['review_id'],1)
            require(index==15 or facts['review_id']==self.state['review_id'],'UIZB_SAME_REAL_NATIVE_REVIEW_ID')
            self.state['review_id']=facts['review_id']
        if index==17:
            require(facts['changed_fields']==['status'],'UIZB_ONLY_REAL_REVIEW_MODERATION_COLUMN')
        if index==18:
            require(type(facts['changed_fields']) is list and len(facts['changed_fields'])==3
                and set(facts['changed_fields'])=={'is_reply','merchant_reply_content','merchant_reply_time'},'UIZB_ONLY_REAL_MERCHANT_REPLY_COLUMNS')
        if index==20:
            self.image_fact(facts['public_image_read'],event,wire,primary)
        image=self.state['image']
        if image is not None:
            for r in event['requests']:
                if r['method']=='GET' and r['path']==image['reference']:
                    require(uizb_same(r['authentication'],{'present':False,'kind':'none','subject_id':None})
                        and r['app_status'] is None and r['response_public']=={**image['image'],'mime_type':'image/png'},'UIZB_ANONYMOUS_VALIDATED_PUBLIC_IMAGE_WITNESS')
                    self.state['witnesses'].append({'step':index,'name':UIZB_STEPS[index-1],'sequence':r['sequence'],
                        'wire':self.image_wire(r),'image':image['image'],'reference':image['reference'],'origin':self.binding['origin']})

    def image_fact(self,fact,event,wire,primary):
        uizb_keys(fact,['mode','original_step','original_name','original_sequence','original_wire_sha256','image_sha256'])
        uizb_int(fact['original_step'],14,20);uizb_int(fact['original_sequence'])
        image=self.state['image'];require(image is not None and wire['actor_role']=='public' and wire['method']=='GET'
            and wire['path']==image['reference'] and type(wire['body_public']) is dict and wire['body_public']=={}
            and fact['image_sha256']==image['image']['sha256'] and fact['original_wire_sha256']==uizb_hash(wire),'UIZB_CASE20_EXACT_IMAGE_WIRE')
        if primary is not None:
            require(fact['mode']=='fresh-normal-response' and fact['original_step']==20 and fact['original_name']==UIZB_STEPS[19]
                and type(fact['original_sequence']) is int and fact['original_sequence']==primary
                and uizb_same(event['requests'][primary]['authentication'],{'present':False,'kind':'none','subject_id':None}),'UIZB_FRESH_ACTUAL_ANONYMOUS_IMAGE')
        else:
            uizb_int(fact['original_step'],14,19);uizb_int(fact['original_sequence'])
            require(fact['mode']=='historical-normal-response-reused' and fact['original_name']==UIZB_STEPS[fact['original_step']-1]
                and not any(r['path']==image['reference'] for r in event['requests'])
                and any(w['step']==fact['original_step'] and w['name']==fact['original_name'] and w['sequence']==fact['original_sequence']
                    and uizb_same(w['wire'],wire) and w['image']==image['image'] and w['reference']==image['reference']
                    and w['origin']==self.binding['origin'] for w in self.state['witnesses']),'UIZB_PRIOR_ACKED_REAL_IMAGE_NOT_SYNTHETIC_GET')

    def preservation(self,value,final_ledger):
        uizb_keys(value,['source','predecessors','private_inputs','public_materials'])
        scopes={'source':'MS4_UI_WINDOW_SOURCE_PRESERVED','predecessors':'MS4_UI_WINDOW_PREDECESSORS_PRESERVED',
            'private_inputs':'MS4_UI_WINDOW_PRIVATE_INPUTS_PRESERVED','public_materials':'MS4_UI_WINDOW_PUBLIC_MATERIALS_PRESERVED'}
        binding=uizb_json(self.read(self.e/UI_WEB_BACKEND['binding'],33554432))
        require(binding['source_files']==self.frozen['ui_backend_source_files'] and binding['container_id']==self.binding['backend_cid']
            and binding['freeze_sha256']==self.binding['freeze_sha256'],'UIZB_PRESERVATION_ORIGINAL_BACKEND_BINDING')
        for name,scope in scopes.items():
            row=value[name];uizb_keys(row,['schema','scope','binding','files'])
            require(type(row['schema']) is int and row['schema']==1 and row['scope']==scope and row['binding']==self.binding,'UIZB_TYPED_PRESERVATION_RECORD')
        expected=[{'path':'/workspace/'+r['path'],'bytes':r['bytes'],'sha256':r['sha256']} for r in self.frozen['ui_backend_source_files']]
        require(value['source']['files']==expected,'UIZB_COMPLETE_BACKEND_SOURCE_INVENTORY_NOT_AUXILIARY_CLAIM')
        for row,source in zip(expected,self.frozen['ui_backend_source_files']):
            require(self.pin(self.read(Path(self.context['repo_dir'])/source['path'],268435456))=={k:row[k] for k in ['bytes','sha256']},'UIZB_EVERY_ORIGINAL_BACKEND_SOURCE_BYTE')
        names=ui_prepare_prerequisite_names()+ui_prepare_public_names(self.context)+['ui-prepare-context.json','ui-prepare-formal-manifest.json','ui-prepare-predecessor-history.json']
        expected_count=50 if task2_prepare_group(self.context) is not None else 49
        require(len(names)==len(set(names))==expected_count and type(binding['prerequisites_sha256']) is dict
            and set(binding['prerequisites_sha256'])==set(names),'UIZB_ORIGINAL_FIXED49_PREDECESSOR_SET')
        old=value['predecessors']['files'];require(type(old) is list and len(old)==expected_count,'UIZB_COMPLETE_PREDECESSOR_INVENTORY')
        expected=[]
        for name,hash_value in binding['prerequisites_sha256'].items():
            require(re.fullmatch(r'[a-z0-9][a-z0-9.-]*\.json',name) is not None,'UIZB_FIXED_PREDECESSOR_JSON_NAME')
            raw=self.read(self.e/name,268435456);require(sha(raw)==hash_value,'UIZB_ORIGINAL_PREDECESSOR_HASH')
            expected.append({'path':'/run/audit/evidence/'+name,**self.pin(raw)})
        require(old==expected,'UIZB_FULL_ORDERED_ORIGINAL_PREDECESSOR_DESCRIPTORS')
        private=value['private_inputs']['files'];uizb_keys(private,['/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence'])
        for name,tree in private.items():
            self.tree(tree)
            require(tree==final_ledger['files']['roots'][name],'UIZB_PRIVATE_TREE_METADATA_FROM_NATIVE_FINAL_ONLY')
        require(private['/run/audit-fixtures']==self.baseline['files']['roots']['/run/audit-fixtures'],'UIZB_ORIGINAL_PRIVATE_FIXTURE_METADATA_PRESERVED')
        materials=value['public_materials']['files'];materials_raw=self.read(self.e/'ui-public-materials.json',268435456)
        require(binding['public_materials']['path']=='/run/audit/evidence/ui-public-materials.json'
            and self.pin(materials_raw)=={k:binding['public_materials'][k] for k in ['bytes','sha256']}
            and uizb_same(materials,uizb_json(materials_raw)),'UIZB_INLINE_FULL_ORIGINAL_PUBLIC_MATERIALS_JSON')
        self.public_materials_join(materials,final_ledger)
        keep=uizb_json(self.read(self.e/'ui-web-predecessor-keep.json',268435456))
        uizb_keys(keep,['schema','scope','run_id','source_sha','freeze_sha256','files'])
        require(type(keep['schema']) is int and keep['schema']==1 and keep['scope']=='MS4_UI_WEB_ORIGINAL_PUBLIC_BYTES'
            and all(keep[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256']) and type(keep['files']) is dict,'UIZB_ORIGINAL_KEPT_PUBLIC_INVENTORY')
        for name,row in keep['files'].items():
            uizb_relative(name);uizb_keys(row,['path','bytes','sha256'])
            require(row['path']=='/run/audit/'+name and self.pin(self.read(self.root/name,268435456))=={k:row[k] for k in ['bytes','sha256']},'UIZB_EVERY_KEPT_ORIGINAL_PUBLIC_BYTE')

    def window(self):
        value,descriptor=self.aggregate('ui-business-window.json');self.window_descriptor=descriptor
        uizb_keys(value,['schema','scope','status','binding','baseline','steps','final','observer_source','observer_native','preservation','diagnostic','full_acceptance'])
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='MS4_UI_NORMAL_BUSINESS_WINDOW_ONLY'
            and value['status']=='VERIFIED_MS4_UI_NORMAL_BUSINESS_WINDOW' and value['binding']==self.binding
            and value['diagnostic'] is None and value['full_acceptance']=='INCOMPLETE','UIZB_TYPED_WINDOW12')
        self.prepare_identity();self.observer_native(value['observer_native'])
        baseline=self.public(value['baseline'],'ui-window-baseline.json',True);self.ledger(baseline,0,'baseline')
        require(uizb_same(baseline,self.frames[0]['receipt']['baseline']),'UIZB_BASELINE_FROM_ORIGINAL_BEGIN_FRAME')
        self.baseline=baseline
        source=self.public(value['observer_source'],'ui-window-observer-source.json',True)
        uizb_keys(source,['schema','scope','binding','files'])
        expected_source=[{'path':'/workspace/'+name,**row} for name in UIZB_PHP_PATHS for row in [self.php[name]]]
        require(type(source['schema']) is int and source['schema']==1 and source['scope']=='MS4_UI_WINDOW_OBSERVER_SOURCE'
            and source['binding']==self.binding and source['files']==expected_source,'UIZB_REAL_COMPLETE_OBSERVER_SOURCE3')
        require(type(value['steps']) is list and len(value['steps'])==20,'UIZB_ALL20_REAL_STEPS')
        previous=value['baseline']['sha256'];previous_ledger=baseline
        for index,d in enumerate(value['steps'],1):
            n='%02d'%index;step=self.public(d,'ui-business-step-'+n+'.json',True)
            uizb_keys(step,['schema','scope','binding','index','name','previous_step_sha256','wire','before','after','verified_after','expected_delta','oracle','observer_event','browser_event','diagnostic'])
            require(type(step['schema']) is int and step['schema']==1 and step['scope']=='MS4_UI_NORMAL_BUSINESS_STEP'
                and step['binding']==self.binding and type(step['index']) is int and step['index']==index
                and step['name']==UIZB_STEPS[index-1] and step['previous_step_sha256']==previous and step['diagnostic'] is None,'UIZB_ORDERED_FULL_BUSINESS_STEP15')
            before=self.public(step['before'],'ui-window-'+n+'-before.json',True)
            after=self.public(step['after'],'ui-window-'+n+'-after.json',True)
            verified=self.public(step['verified_after'],'ui-window-'+n+'-verified.json',True)
            expected=self.public(step['expected_delta'],'ui-window-'+n+'-expected.json',True)
            event=self.public(step['browser_event'],'ui-browser-step-'+n+'.json',True)
            self.ledger(before,index,'before');self.ledger(after,index,'after');self.ledger(verified,index,'verified')
            begin=self.frames[2*index-1];verify=self.frames[2*index];actual=verify['receipt']
            require(uizb_same(before,begin['receipt']['before']) and uizb_same(after,actual['after'])
                and uizb_same(verified,actual['verified_after']) and uizb_same(expected,actual['expected_delta']),'UIZB_MATERIALIZED_PAYLOADS_DEEP_JOIN_ORIGINAL_FRAMES')
            self.ledger_equal(previous_ledger,before);self.ledger_equal(after,verified);self.table_delta(before,after,expected)
            oracle=actual['oracle'];uizb_keys(oracle,['source','case_name','expected','actual','readback'])
            require(oracle['source']=={'path':'/workspace/help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
                    **self.php['help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php']}
                and oracle['case_name']==UIZB_STEPS[index-1] and uizb_same(oracle['expected'],expected)
                and uizb_same(oracle['actual'],after) and uizb_same(oracle['readback'],verified),'UIZB_SOURCE_BOUND_COMPLETE_NATIVE_ORACLE_NOT_BOOL')
            require(step['oracle']=={'source':oracle['source'],'case_name':oracle['case_name'],'expected':step['expected_delta'],
                'actual':step['after'],'readback':step['verified_after']},'UIZB_ORACLE_DESCRIPTOR_AND_INLINE_VALUE_CHAIN')
            meta=step['observer_event'];uizb_keys(meta,['observer_pid','request_index','request_sha256','response_index','response_sha256','stdout_start','stdout_end','terminal_required'])
            actual_slice=self.slices[2*index]
            safe_request={'schema':1,'binding':self.binding,'sequence':2*index,'command':'STEP_VERIFY','step':index,
                'wire_public':{'wire':step['wire'],'browser_event':event}}
            require(type(meta['observer_pid']) is int and meta['observer_pid']==self.pid
                and type(meta['request_index']) is int and type(meta['response_index']) is int and meta['request_index']==meta['response_index']==2*index
                and meta['request_sha256']==uizb_hash(safe_request) and meta['response_sha256']==actual_slice['sha256']
                and type(meta['stdout_start']) is int and type(meta['stdout_end']) is int
                and meta['stdout_start']==actual_slice['start'] and meta['stdout_end']==actual_slice['end']
                and meta['terminal_required']=='SAME_PROCESS_NATIVE_ZERO_AFTER_FINAL_FRAME','UIZB_REAL_PID_PUBLIC_REQUEST_HASH_AND_ORIGINAL_STDOUT_SLICE')
            self.current_step_object=step;self.current_event=event;self.current_wire=step['wire'];self.current_expected=expected
            primary=self.event(event,index,step['wire'])
            require(event['started_at_ms']>=int(before['redis']['time_ms']) and event['finished_at_ms']<=int(after['redis']['time_ms']),
                'UIZB_BROWSER_EVENT_INSIDE_REAL_NATIVE_OBSERVATION_WINDOW')
            self.expected_facts(expected,index,event,step['wire'],primary)
            self.finish_request_rules(event,index,primary)
            self.typed_response_joins(event,index,primary)
            self.windows.append(step);self.events.append(event);previous=d['sha256'];previous_ledger=verified
        final=self.public(value['final'],'ui-window-final.json',True);self.ledger(final,21,'final')
        require(uizb_same(final,self.frames[41]['receipt']['final']),'UIZB_FINAL_FROM_ORIGINAL_FINISH_FRAME');self.ledger_equal(previous_ledger,final)
        require(type(value['preservation']) is dict and set(value['preservation'])=={'source','predecessors','private_inputs','public_materials'},'UIZB_ALL_FOUR_PRESERVATION_PAYLOADS')
        preservation={}
        for key in ['source','predecessors','private_inputs','public_materials']:
            preservation[key]=self.public(value['preservation'][key],'ui-window-'+key.replace('_','-')+'-preserved.json',True)
        require(uizb_same(preservation,self.frames[41]['receipt']['preservation']),'UIZB_PRESERVATION_FROM_SAME_FINISH_FRAME')
        self.preservation(preservation,final)
        self.final=final;self.window_value=value
        require(set(self.state['actors'])=={'customer','merchant','platform'} and self.state['actors']==self.prepared['actor_ids'],
            'UIZB_ALL_THREE_ACTUAL_SIGNED_NATIVE_LOGINS_COMPLETED')
        # Exactly128 producer payloads plus4 original native leaves are resolved above, all from fixed names.
        names={Path(path).name for path in self.seen if Path(path).parent==self.e}
        required={'ui-business-window.json','ui-window-baseline.json','ui-window-final.json','ui-window-observer-source.json'}
        required.update({'ui-window-'+key.replace('_','-')+'-preserved.json' for key in ['source','predecessors','private_inputs','public_materials']})
        for i in range(1,21):
            n='%02d'%i;required.update({'ui-window-'+n+'-'+part+'.json' for part in ['before','after','verified','expected']})
            required.update({'ui-browser-step-'+n+'.json','ui-business-step-'+n+'.json'})
        required.update({'ui-window-observer-created.json','ui-window-observer-terminal.json','ui-window-observer.stdout','ui-window-observer.stderr'})
        require(len(required)==132 and required<=names,'UIZB_ALL128_CANONICAL_PLUS4_ORIGINAL_NATIVE_LEAVES_READ')
        return value

    def png(self,raw):
        import binascii
        import struct
        import zlib
        require(45<len(raw)<=33554432 and raw[:8]==b'\x89PNG\r\n\x1a\n','UIZB_ORIGINAL_PNG_SIGNATURE')
        at=8;header=None;palette=None;seen_data=False;data_ended=False;ended=False;compressed=[]
        while at<len(raw):
            require(at+12<=len(raw),'UIZB_COMPLETE_PNG_CHUNK_HEADER')
            size=struct.unpack('>I',raw[at:at+4])[0];end=at+12+size
            require(end<=len(raw),'UIZB_COMPLETE_PNG_CHUNK_BYTES')
            kind=raw[at+4:at+8];body=raw[at+8:at+8+size]
            require(re.fullmatch(b'[A-Za-z]{4}',kind) is not None and 65<=kind[2]<=90
                and binascii.crc32(kind+body)&0xffffffff==struct.unpack('>I',raw[at+8+size:end])[0],'UIZB_EVERY_CHUNK_CRC_AND_RESERVED_BIT')
            if header is None:
                require(kind==b'IHDR' and size==13 and at==8,'UIZB_FIRST_UNIQUE_IHDR')
            if kind==b'IHDR':
                require(header is None and size==13 and at==8,'UIZB_UNIQUE_IHDR')
                header=body
            elif kind==b'PLTE':
                require(palette is None and not seen_data and 0<size<=768 and size%3==0,'UIZB_VALID_ORIGINAL_PALETTE')
                palette=body
            elif kind==b'IDAT':
                require(not data_ended and size<=33554432,'UIZB_CONTIGUOUS_ORIGINAL_IDAT')
                seen_data=True;compressed.append(body)
            elif kind==b'IEND':
                require(size==0 and seen_data and end==len(raw),'UIZB_EXACT_IEND_AND_EOF')
                ended=True;at=end;break
            else:
                require(97<=kind[0]<=122 and kind not in [b'acTL',b'fcTL',b'fdAT'],'UIZB_NO_UNKNOWN_CRITICAL_OR_ANIMATED_IMAGE')
                if seen_data:
                    data_ended=True
            at=end
        require(ended and at==len(raw) and header is not None,'UIZB_FULL_PNG_NOT_HEADER_ONLY')
        width,height,depth,color,compression,filter_method,interlace=struct.unpack('>IIBBBBB',header)
        allowed={0:[1,2,4,8,16],2:[8,16],3:[1,2,4,8],4:[8,16],6:[8,16]};channels={0:1,2:3,3:1,4:2,6:4}
        require(width>0 and height>0 and width*height<=16777216 and color in allowed and depth in allowed[color]
            and compression==0 and filter_method==0 and interlace in [0,1]
            and (color!=3 or palette is not None) and (color not in [0,4] or palette is None),'UIZB_EXACT_SUPPORTED_IHDR')
        if color==3:
            require(len(palette)//3<=2**depth,'UIZB_PALETTE_FITS_BIT_DEPTH')
        passes=[(0,0,1,1)] if interlace==0 else [(0,0,8,8),(4,0,8,8),(0,4,4,8),(2,0,4,4),(0,2,2,4),(1,0,2,2),(0,1,1,2)]
        shapes=[];total=0
        for x,y,dx,dy in passes:
            w=0 if width<=x else (width-x+dx-1)//dx;h=0 if height<=y else (height-y+dy-1)//dy
            if w and h:
                stride=(w*channels[color]*depth+7)//8;shapes.append((w,h,stride));total+=h*(stride+1)
        require(0<total<=134217728,'UIZB_BOUNDED_ACTUAL_PIXEL_RASTER')
        inflater=zlib.decompressobj();decoded=inflater.decompress(b''.join(compressed),total+1)
        require(len(decoded)==total and inflater.eof and inflater.unused_data==b'' and inflater.unconsumed_tail==b'',
            'UIZB_COMPLETE_SINGLE_ZLIB_STREAM_AND_ALL_PIXEL_ROWS')
        position=0;bpp=max(1,(channels[color]*depth+7)//8)
        def paeth(a,b,c):
            p=a+b-c;pa=abs(p-a);pb=abs(p-b);pc=abs(p-c)
            return a if pa<=pb and pa<=pc else b if pb<=pc else c
        for w,h,stride in shapes:
            previous=bytearray(stride)
            for _ in range(h):
                kind=decoded[position];position+=1
                require(kind<=4 and position+stride<=len(decoded),'UIZB_ALL_ACTUAL_PNG_FILTERS')
                row=bytearray(decoded[position:position+stride]);position+=stride
                for j in range(stride):
                    left=row[j-bpp] if j>=bpp else 0;up=previous[j];upper_left=previous[j-bpp] if j>=bpp else 0
                    predictor=0 if kind==0 else left if kind==1 else up if kind==2 else (left+up)//2 if kind==3 else paeth(left,up,upper_left)
                    row[j]=(row[j]+predictor)&255
                if color==3:
                    for j in range(w):
                        bit=j*depth;sample=(row[bit//8]>>(8-depth-bit%8))&((1<<depth)-1)
                        require(sample<len(palette)//3,'UIZB_ALL_INDEXED_PIXELS_HAVE_PALETTE_ENTRY')
                previous=row
        require(position==len(decoded),'UIZB_COMPLETE_PIXEL_RASTER_NO_EXTRA_BYTES')
        return {'width':width,'height':height}

    def producer_source(self,value,role):
        uizb_keys(value,['path','bytes','sha256']);leaf=UIZB_AUX_ROLES[role]
        require(value=={'path':'browser-v1/'+leaf,**self.aux['members'][leaf]},'UIZB_FULL_ACTUAL_PRODUCER_SOURCE_ORIGINAL')

    def browser(self):
        value,descriptor=self.aggregate('ui-browser-normal-close.json');self.browser_descriptor=descriptor
        uizb_keys(value,['schema','scope','status','binding','attempt','baseline','owned','observations','viewport','references',
            'producer_source','authentication','business_DOM','full_acceptance'])
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='MS4_UI_BROWSER_NORMAL_CLOSE_ONLY'
            and value['status']=='VERIFIED_MS4_UI_OWNED_TABS_CLOSED_DEFAULT_VIEWPORT' and value['binding']==self.binding
            and value['authentication']=='NOT_PROVEN' and value['business_DOM']=='NOT_VERIFIED_BY_BROWSER_CLOSE'
            and value['full_acceptance']=='INCOMPLETE','UIZB_BROWSER_CLOSE14_NOT_AUTH_OR_BUSINESS_PROOF')
        self.producer_source(value['producer_source'],'browser_close')
        roles=['customer','merchant','platform'];owned=value['owned']
        require(type(owned) is list and len(owned)==3 and {r['role'] for r in owned}==set(roles),'UIZB_THREE_OWNED_ROLES')
        role_ids={}
        for row in owned:
            uizb_keys(row,['role','id_sha256']);uizb_hex(row['id_sha256']);role_ids[row['role']]=row['id_sha256']
        require(len(set(role_ids.values()))==3,'UIZB_THREE_DIFFERENT_OWNED_TABS')
        def rows(values):
            require(type(values) is list,'UIZB_ACTUAL_TABS_LIST')
            result={}
            for row in values:
                uizb_keys(row,['id_sha256','url_kind','url_sha256']);uizb_hex(row['id_sha256'])
                require(row['id_sha256'] not in result,'UIZB_UNIQUE_LISTED_TAB')
                if row['url_kind']=='undefined':
                    require(row['url_sha256'] is None,'UIZB_REAL_UNDEFINED_URL')
                else:
                    require(row['url_kind']=='string','UIZB_ACTUAL_URL_KIND');uizb_hex(row['url_sha256'])
                result[row['id_sha256']]=row
            require(list(result)==sorted(result),'UIZB_CANONICAL_TAB_LIST_ORDER')
            return result
        baseline=rows(value['baseline']);require(not set(role_ids.values())&set(baseline),'UIZB_NEVER_ADOPT_PREEXISTING_TAB')
        observations=value['observations'];require(type(observations) is list and len(observations)==12,'UIZB_EXACT12_REAL_BROWSER_OBSERVATIONS')
        wanted={'baseline','before_close','after_viewport_reset'}|{prefix+role for role in roles for prefix in ['before_new_','after_new_','after_close_']}
        by_event={}
        for index,o in enumerate(observations):
            uizb_keys(o,['index','event','tabs'])
            require(type(o['index']) is int and o['index']==index and o['event'] in wanted and o['event'] not in by_event,'UIZB_UNIQUE_ORDERED_BROWSER_EVENT')
            tabs=rows(o['tabs'])
            require(all(tabs.get(key)==row for key,row in baseline.items()),'UIZB_EVERY_PREEXISTING_ID_AND_URL_PRESERVED')
            by_event[o['event']]=(index,tabs)
        require(observations[0]=={'index':0,'event':'baseline','tabs':value['baseline']},'UIZB_ORIGINAL_BASELINE_OBSERVATION')
        available=set(baseline)
        for i in [1,3,5]:
            before=observations[i];after=observations[i+1]
            require(before['event'].startswith('before_new_'),'UIZB_BEFORE_REAL_OWNED_CREATION')
            role=before['event'][len('before_new_'):]
            require(role==roles[(i-1)//2] and after['event']=='after_new_'+role and set(rows(before['tabs']))==available
                and role_ids[role] not in available,'UIZB_ONLY_THIS_ROLE_NEW_TAB_RESULT')
            available.add(role_ids[role]);require(set(rows(after['tabs']))==available,'UIZB_NO_EXTRA_TAB_DURING_CREATION')
        require(by_event['before_close'][0]==7 and set(by_event['before_close'][1])==set(baseline)|set(role_ids.values()),'UIZB_COMPLETE_SINGLE_CLOSE_ATTEMPT')
        for index,role in enumerate(reversed(roles),8):
            del_role=role_ids[role];available.remove(del_role)
            require(by_event['after_close_'+role][0]==index and set(by_event['after_close_'+role][1])==available,'UIZB_ONLY_OWNED_REVERSE_NORMAL_CLOSE_AND_REAL_ABSENCE')
        require(by_event['after_viewport_reset'][0]==11 and by_event['after_viewport_reset'][1]==baseline,'UIZB_FINAL_BASELINE_ONLY')
        viewport=value['viewport'];uizb_keys(viewport,['sets','reset']);require(type(viewport['sets']) is list,'UIZB_REAL_VIEWPORT_SET_HISTORY')
        for row in viewport['sets']:
            uizb_keys(row,['width','height','api_returned']);uizb_int(row['width']);uizb_int(row['height'],1,16384)
            require(row['width'] in [320,390,1440] and row['api_returned'] is True,'UIZB_REAL_ALLOWED_VIEWPORT_RETURN')
        require({r['width'] for r in viewport['sets']}=={320,390,1440},'UIZB_ALL_THREE_VIEWPORT_SET_WIDTHS')
        require(uizb_same(viewport['reset'],{'attempted':True,'api_returned':True,'restoration':'DEFAULT_ONLY_PREVIOUS_OVERRIDE_UNKNOWN'})
            and type(viewport['reset'].get('attempted')) is bool and type(viewport['reset'].get('api_returned')) is bool,'UIZB_SINGLE_NORMAL_DEFAULT_RESET_NOT_UNKNOWN_OLD_OVERRIDE')
        refs=value['references'];uizb_keys(refs,['caller_forget','companion_references_released','immutable_JS_strings_securely_erased','external_references'])
        forget=refs['caller_forget'];uizb_keys(forget,['private_references_released','immutable_JS_strings_securely_erased','browser_close','business_DOM'])
        require(forget['private_references_released'] is True and forget['immutable_JS_strings_securely_erased'] is False
            and forget['browser_close']=='NOT PROVEN' and forget['business_DOM']=='NOT RUN'
            and refs['companion_references_released'] is True and refs['immutable_JS_strings_securely_erased'] is False
            and refs['external_references']=='NOT_ATTESTED','UIZB_ACTUAL_MANAGED_REFERENCE_RELEASE_SCOPE')
        attempt=uizb_json(self.public(value['attempt'],'ui-browser-normal-close-attempt.json'))
        uizb_keys(attempt,['schema','scope','binding','baseline','owned','before_close','producer_source'])
        require(type(attempt['schema']) is int and attempt['schema']==1 and attempt['scope']=='MS4_UI_BROWSER_SINGLE_NORMAL_CLOSE_ATTEMPT'
            and attempt['binding']==self.binding and attempt['baseline']==value['baseline'] and attempt['owned']==owned
            and attempt['before_close']==observations[7] and attempt['producer_source']==value['producer_source'],'UIZB_ORIGINAL_ATTEMPT_FULL_RECEIPT_JOIN')
        self.owned=role_ids;self.browser_value=value
        return value

    def bounds(self,value):
        uizb_keys(value,['x','y','width','height'])
        for number in value.values():
            uizb_number(number)
        require(value['width']>0 and value['height']>0,'UIZB_POSITIVE_ACTUAL_CSS_BOUNDS')
        return value

    def viewport(self,value):
        uizb_keys(value,['width','height']);uizb_int(value['width']);uizb_int(value['height'],1,16384)
        require(value['width'] in [320,390,1440],'UIZB_ACTUAL_ALLOWED_CSS_VIEWPORT')

    def inside(self,bounds,outer):
        self.bounds(bounds);self.bounds(outer)
        require(bounds['x']>=outer['x'] and bounds['y']>=outer['y']
            and bounds['x']+bounds['width']<=outer['x']+outer['width']
            and bounds['y']+bounds['height']<=outer['y']+outer['height'],'UIZB_REAL_BOUNDS_INSIDE_ACTUAL_PARENT')

    def DOM_request(self,value,event):
        if value is None:
            return
        uizb_keys(value,['sequence','method','path_sha256','started_at_ms','finished_at_ms','http_status','app_status',
            'request_public_sha256','response_public_sha256','authentication'])
        n=uizb_int(value['sequence'],0,len(event['requests'])-1);r=event['requests'][n]
        wanted={k:r[k] for k in ['sequence','method','started_at_ms','finished_at_ms','http_status','app_status',
            'request_public_sha256','response_public_sha256','authentication']};wanted['path_sha256']=sha(r['path'].encode('utf-8'))
        require(uizb_same(value,wanted),'UIZB_DOM_JOIN_TO_FULL_NATIVE_VALIDATED_REQUEST12')

    def DOM(self):
        value,descriptor=self.aggregate('ui-business-dom.json');self.DOM_descriptor=descriptor
        uizb_keys(value,['schema','scope','status','binding','window','observations','producer_source','preservation','diagnostic','full_acceptance'])
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='MS4_UI_NORMAL_DOM_ONLY'
            and value['status']=='VERIFIED_MS4_UI_NORMAL_DOM' and value['binding']==self.binding
            and value['window']==self.window_descriptor and value['preservation']==self.window_value['preservation']
            and value['diagnostic'] is None and value['full_acceptance']=='INCOMPLETE','UIZB_TYPED_DOM10_JOIN_COMPLETE_WINDOW')
        self.producer_source(value['producer_source'],'dom_producer')
        require(type(value['observations']) is list and 1<=len(value['observations'])<=128,'UIZB_COMPLETE_BOUNDED_REAL_DOM_OBSERVATIONS')
        allowed_guards={'PRIVATE_VISIBLE_MARKER','VISIBLE_SCAN_LIMIT','EDITABLE_VISIBLE','OPAQUE_MEDIA_VISIBLE','AUTH_CONTROL_VISIBLE',
            'INPUT_EMPTY_NOT_OBSERVED','AUTH_ACTION_VISIBLE','UNAPPROVED_IMAGE_VISIBLE','UNAPPROVED_BACKGROUND_VISIBLE',
            'AUTHENTICATION_POLICY','PRIVATE_PREVIEW_POLICY','SCREENSHOT_OMITTED','VISIBLE_STATE_CHANGED_DURING_SCREENSHOT'}
        self.DOM_rows=[];grid=set();image20=[]
        for counter,descriptor in enumerate(value['observations'],1):
            number='%03d'%counter;observation=uizb_json(self.public(descriptor,'ui-dom-observation-'+number+'.json'))
            uizb_safe(observation)
            uizb_keys(observation,['schema','scope','binding','role','tab_id_sha256','url_sha256','viewport','state','identity',
                'request','DOM','screenshot','image','window_step'])
            index=uizb_int(observation['window_step'],1,20);event=self.events[index-1]
            require(type(observation['schema']) is int and observation['schema']==1 and observation['scope']=='MS4_UI_NORMAL_DOM_OBSERVATION'
                and observation['binding']==self.binding and observation['role'] in ['customer','merchant','platform','public'],'UIZB_TYPED_REAL_DOM_OBSERVATION14')
            role=observation['role'];owned_role='customer' if role=='public' else role
            require(observation['tab_id_sha256']==self.owned[owned_role],'UIZB_SAME_ACTUAL_OWNED_ROLE_TAB')
            uizb_hex(observation['url_sha256']);self.viewport(observation['viewport'])
            identity=observation['identity'];uizb_keys(identity,['application','subject_id','merchant_id','verified_by'])
            if role=='public':
                require(identity=={'application':'none','subject_id':None,'merchant_id':None,'verified_by':'actual-anonymous-normal-response'},'UIZB_PUBLIC_DOM_IDENTITY')
            else:
                require(type(identity['subject_id']) is int and identity['subject_id']==self.prepared['actor_ids'][role]
                    and identity['application']=={'customer':'api','merchant':'merchantapi','platform':'adminapi'}[role]
                    and identity['verified_by']=='native-session-and-normal-UI'
                    and (type(identity['merchant_id']) is int and identity['merchant_id']==self.prepared['merchant_id'] if role=='merchant' else identity['merchant_id'] is None),
                    'UIZB_DOM_PREPARED_NATIVE_ROLE_IDENTITY')
            self.DOM_request(observation['request'],event)
            state=observation['state'];uizb_keys(state,['page','screenshot_policy','screenshot_guard'])
            require(type(state['page']) is str and re.fullmatch('[a-z][a-z0-9-]{0,63}',state['page']) is not None
                and state['screenshot_policy'] in ['omit','capture-if-safe'] and type(state['screenshot_guard']) is list
                and all(type(x) is str and x in allowed_guards for x in state['screenshot_guard'])
                and state['screenshot_guard']==sorted(set(state['screenshot_guard'])),'UIZB_FINITE_SAFE_DOM_STATE')
            measurement=uizb_json(self.public(observation['DOM'],'ui-dom-measure-'+number+'.json'))
            uizb_keys(measurement,['schema','scope','binding','role','tab_id_sha256','window_step','started_at_ms','finished_at_ms',
                'viewport','image_bounds','controls','screenshot_call'])
            require(type(measurement['schema']) is int and measurement['schema']==1 and measurement['scope']=='MS4_UI_NORMAL_VISIBLE_MEASUREMENT'
                and all(uizb_same(measurement[k],observation[k]) for k in ['binding','role','tab_id_sha256','window_step','viewport']),
                'UIZB_ORIGINAL_MEASUREMENT12_SAME_OBSERVATION')
            uizb_int(measurement['started_at_ms'],event['started_at_ms']);uizb_int(measurement['finished_at_ms'],measurement['started_at_ms'])
            require(measurement['finished_at_ms']<=event['finished_at_ms'] and event['finished_at_ms']<=int(self.final['redis']['time_ms']),
                'UIZB_ALL_REAL_DOM_CALLS_INSIDE_NATIVE_STEP_BEFORE_FINISH')
            controls=measurement['controls'];require(type(controls) is list and 1<=len(controls)<=64,'UIZB_FINITE_VISIBLE_CONTROLS')
            names=set()
            for control in controls:
                uizb_keys(control,['key','kind','label','visible','enabled','bounds','parent_bounds'])
                require(type(control['key']) is str and re.fullmatch('[a-z][a-z0-9-]{0,63}',control['key']) is not None
                    and control['key'] not in names and control['kind'] in ['button','input','status','link','image','text']
                    and control['visible'] is True and type(control['enabled']) is bool,'UIZB_ACTUAL_TYPED_VISIBLE_CONTROL')
                names.add(control['key'])
                if control['kind'] in ['input','image']:
                    require(control['label'] is None,'UIZB_NEVER_EXPORT_INPUT_OR_IMAGE_VALUE')
                else:
                    require(type(control['label']) is str and 0<len(control['label'])<=1024
                        and control['label']==' '.join(control['label'].split()),'UIZB_NORMALIZED_SAFE_VISIBLE_LABEL')
                self.inside(control['bounds'],control['parent_bounds'])
            image=observation['image']
            if image is None:
                require(measurement['image_bounds'] is None,'UIZB_NO_UNOBSERVED_IMAGE_BOUNDS')
            else:
                uizb_keys(image,['kind','url_sha256','reference','bytes','sha256','media_type','complete','natural_width',
                    'natural_height','visible','bounds','content_verification'])
                require(image['kind'] in ['public','private'] and image['bytes']==self.artifacts['ms4-order.png']['bytes']
                    and image['sha256']==self.artifacts['ms4-order.png']['sha256'] and image['media_type']=='image/png'
                    and image['complete'] is True and type(image['natural_width']) is int and type(image['natural_height']) is int
                    and image['natural_width']==image['natural_height']==96 and image['visible'] is True
                    and image['bounds']==measurement['image_bounds']
                    and image['content_verification']=='FIXTURE_REFERENCE_REQUIRES_NATIVE_HTTP_JOIN','UIZB_REAL_DOM_IMAGE12_NATIVE_HTTP_JOIN_REQUIRED')
                uizb_hex(image['url_sha256']);self.bounds(image['bounds'])
                if image['kind']=='public':
                    require(self.state['image'] is not None and image['reference']==self.state['image']['url']
                        and image['url_sha256']==sha(image['reference'].encode('utf-8')),'UIZB_SAME_NATIVE_PUBLIC_IMAGE_URL')
                else:
                    require(image['reference'] is None and observation['screenshot'] is None
                        and 'PRIVATE_PREVIEW_POLICY' in state['screenshot_guard'] and index in [5,6,7,8]
                        and any(re.fullmatch(r'/(api/order/refund/files|merchantapi/aftersale-files)/'+self.state['file_id']+'/content',r['path'])
                            and r['method']=='GET' and r['app_status'] is None for r in event['requests']),
                        'UIZB_OWNED_PRIVATE_PREVIEW_NO_SCREENSHOT_OR_PRIVATE_REFERENCE')
            screenshot=observation['screenshot'];call=measurement['screenshot_call']
            if screenshot is None:
                require(call is None and state['screenshot_guard'],'UIZB_HONEST_SAFE_SCREENSHOT_OMISSION')
            else:
                require(state['screenshot_policy']=='capture-if-safe' and state['screenshot_guard']==[],'UIZB_NO_CAPTURE_OF_FILLED_AUTH_PRIVATE_OR_BLOCKED_STATE')
                uizb_keys(call,['started_at_ms','finished_at_ms','descriptor','pixel_width','pixel_height','scale'])
                require(call['descriptor']==screenshot and call['scale'] is None,'UIZB_ACTUAL_SUPPORTED_SCREENSHOT_METADATA_NO_INVENTED_SCALE')
                uizb_int(call['started_at_ms'],measurement['started_at_ms']);uizb_int(call['finished_at_ms'],call['started_at_ms'])
                require(call['finished_at_ms']<=measurement['finished_at_ms'],'UIZB_REAL_SCREENSHOT_CALL_INSIDE_MEASUREMENT')
                pixels=self.png(self.public(screenshot,'ui-dom-shot-'+number+'.png'))
                require(type(call['pixel_width']) is int and type(call['pixel_height']) is int
                    and pixels=={'width':call['pixel_width'],'height':call['pixel_height']},'UIZB_ACTUAL_ORIGINAL_PNG_IHDR_AND_FULL_PIXEL_DECODE')
                # Physical PNG pixels are separate from actual CSS viewport. No CSS=DPR assertion is invented.
                if role in ['customer','merchant','platform']:
                    grid.add((role,observation['viewport']['width']))
            self.dom_rule(observation,measurement,index)
            if index==20 and role=='public' and image is not None and image['kind']=='public':
                require(screenshot is not None,'UIZB_CASE20_ORIGINAL_SAFE_SCREENSHOT_REQUIRED')
                self.inside(image['bounds'],{'x':0,'y':0,'width':observation['viewport']['width'],'height':observation['viewport']['height']})
                image20.append((observation,measurement))
            self.DOM_rows.append((observation,measurement))
        require(grid=={(role,width) for role in ['customer','merchant','platform'] for width in [320,390,1440]},'UIZB_REAL_THREE_ROLES_THREE_WIDTHS')
        require(len(image20)==3 and [o['viewport']['width'] for o,_ in image20]==[320,390,1440],'UIZB_CASE20_THREE_CURRENT_IMAGE_WIDTHS')
        actual=self.events[19]['DOM'];uizb_keys(actual,['schema','scope','image_url','complete','natural_width','natural_height','visible','viewports'])
        require(type(actual['schema']) is int and actual['schema']==1 and actual['scope']=='MS4_UI_NORMAL_PUBLIC_IMAGE_DOM'
            and actual['image_url']==self.state['image']['url'] and actual['complete'] is True and actual['visible'] is True
            and type(actual['natural_width']) is int and type(actual['natural_height']) is int
            and actual['natural_width']==actual['natural_height']==96 and type(actual['viewports']) is list and len(actual['viewports'])==3,'UIZB_CASE20_NATIVE_FRAME_CURRENT_DOM8')
        for row,(o,m) in zip(actual['viewports'],image20):
            uizb_keys(row,['viewport_width','viewport_height','image_url','complete','natural_width','natural_height','visible','bounds','screenshot'])
            image=o['image'];d=o['screenshot'];relative=d['path'][len('/run/audit/evidence/'):]
            require(re.fullmatch(r'ui-dom-shot-[0-9]{3}\.png',relative) is not None
                and d['path']=='/run/audit/evidence/'+relative and uizb_same(row,{'viewport_width':o['viewport']['width'],
                    'viewport_height':o['viewport']['height'],'image_url':image['reference'],'complete':True,'natural_width':96,
                    'natural_height':96,'visible':True,'bounds':image['bounds'],'screenshot':{'path':relative,'bytes':d['bytes'],'sha256':d['sha256']}}),
                'UIZB_RELATIVE_NATIVE_SCREENSHOT_TO_SAME_ACTUAL_HOST_ORIGINAL_MAPPING')
        self.DOM_value=value
        return value

    def reread(self):
        original=dict(self.seen)
        for name,row in original.items():
            raw=self.read(Path(name),268435456,row['pin']['bytes']==0)
            require(self.pin(raw)==row['pin'],'UIZB_ALL_ORIGINAL_EVIDENCE_AND_SOURCE_REREAD')
        require(self.seen==original,'UIZB_FULL_STABLE_READ_CLOSURE_UNCHANGED')

    def data_pointer(self,value,pointer):
        require(type(pointer) is str and len(pointer)<=2048 and (pointer=='' or pointer.startswith('/')),
            'UIZB_LOCAL_JSON_POINTER_ONLY')
        for token in ([] if pointer=='' else pointer[1:].split('/')):
            require(re.search(r'~(?![01])',token) is None,'UIZB_JSON_POINTER_ESCAPE')
            token=token.replace('~1','/').replace('~0','~')
            if type(value) is dict:
                require(token in value,'UIZB_ACTUAL_BOUND_OBJECT_FIELD');value=value[token]
            else:
                require(type(value) is list and re.fullmatch(r'0|[1-9][0-9]*',token) is not None,
                    'UIZB_ACTUAL_BOUND_ARRAY_INDEX')
                number=int(token);require(number<len(value),'UIZB_ACTUAL_BOUND_ARRAY_MEMBER');value=value[number]
        return value

    def schema_shape(self,node,depth=0):
        require(depth<=128 and type(node) is dict,'UIZB_FINITE_SCHEMA_OBJECT')
        allowed={'type','properties','required','additionalProperties','items','minItems','maxItems','uniqueItems',
            'minimum','maximum','exclusiveMinimum','minLength','maxLength','pattern','oneOf','propertyNames',
            'const','enum','$ref','x-relations'}
        require(set(node)<=allowed,'UIZB_ONLY_ROOT_ENUMERATED_SCHEMA_KEYWORDS')
        if '$ref' in node:
            reference=node['$ref'];require(type(reference) is str and re.fullmatch(r'#/schemas/[A-Za-z0-9_-]+',reference) is not None
                and reference[len('#/schemas/'):] in self.data['schemas'],'UIZB_EXISTING_LOCAL_SCHEMA_REFERENCE')
        if 'type' in node:
            types=node['type'] if type(node['type']) is list else [node['type']]
            require(types and all(type(t) is str and t in ['null','boolean','integer','number','string','array','object'] for t in types)
                and len(types)==len(set(types)),'UIZB_CLOSED_JSON_TYPES')
        if 'properties' in node:
            require(type(node['properties']) is dict,'UIZB_SCHEMA_NAMED_PROPERTIES')
            for field,child in node['properties'].items():
                require(type(field) is str,'UIZB_SCHEMA_STRING_PROPERTY');self.schema_shape(child,depth+1)
        if 'required' in node:
            required=node['required'];require(type(required) is list and all(type(k) is str for k in required)
                and len(required)==len(set(required)),'UIZB_SCHEMA_UNIQUE_REQUIRED_FIELDS')
        if 'additionalProperties' in node:
            additional=node['additionalProperties']
            require(type(additional) in [bool,dict],'UIZB_SCHEMA_ADDITIONAL_PROPERTIES_DATA_ONLY')
            if type(additional) is dict:
                self.schema_shape(additional,depth+1)
        for field in ['items','propertyNames']:
            if field in node:
                self.schema_shape(node[field],depth+1)
        for field in ['minItems','maxItems','minLength','maxLength']:
            if field in node:
                uizb_int(node[field],0,268435456)
        for field in ['minimum','maximum','exclusiveMinimum']:
            if field in node:
                uizb_number(node[field])
        if 'uniqueItems' in node:
            require(type(node['uniqueItems']) is bool,'UIZB_SCHEMA_UNIQUE_ITEMS_BOOL')
        if 'pattern' in node:
            require(type(node['pattern']) is str and len(node['pattern'])<=4096,'UIZB_PINNED_FINITE_SCHEMA_PATTERN')
        if 'oneOf' in node:
            require(type(node['oneOf']) is list and 1<=len(node['oneOf'])<=128,'UIZB_FINITE_SCHEMA_ALTERNATIVES')
            for child in node['oneOf']:
                self.schema_shape(child,depth+1)
        if 'enum' in node:
            require(type(node['enum']) is list and 1<=len(node['enum'])<=4096,'UIZB_FINITE_SCHEMA_ENUM')
            for item in node['enum']:
                uizb_json_value(item)
        if 'x-relations' in node:
            self.relation_shape(node['x-relations'])

    def schema_match(self,value,node,depth=0):
        require(depth<=128,'UIZB_BOUNDED_LOCAL_SCHEMA_DEPTH')
        if '$ref' in node:
            self.schema_match(value,self.data['schemas'][node['$ref'][len('#/schemas/'):]],depth+1)
        if 'type' in node:
            wanted=node['type'] if type(node['type']) is list else [node['type']]
            actual='null' if value is None else {bool:'boolean',int:'integer',float:'number',str:'string',list:'array',dict:'object'}.get(type(value))
            require(actual in wanted or actual=='integer' and 'number' in wanted,'UIZB_ACTUAL_SCHEMA_TYPE')
        if 'const' in node:
            require(uizb_same(value,node['const']),'UIZB_SCHEMA_EXACT_CONSTANT')
        if 'enum' in node:
            require(any(uizb_same(value,item) for item in node['enum']),'UIZB_SCHEMA_CLOSED_VALUE')
        if 'oneOf' in node:
            matched=0
            for child in node['oneOf']:
                try:
                    self.schema_match(value,child,depth+1);matched+=1
                except RuntimeError:
                    pass
            require(matched==1,'UIZB_EXACT_ONE_TYPED_SCHEMA_ALTERNATIVE')
        if type(value) is dict:
            properties=node.get('properties',{})
            require(set(node.get('required',[]))<=set(value),'UIZB_ALL_REQUIRED_DTO_FIELDS')
            for key,item in value.items():
                if key in properties:
                    self.schema_match(item,properties[key],depth+1)
                else:
                    additional=node.get('additionalProperties',True)
                    require(additional is not False,'UIZB_NO_UNEXPECTED_DTO_FIELD')
                    if type(additional) is dict:
                        self.schema_match(item,additional,depth+1)
                if 'propertyNames' in node:
                    self.schema_match(key,node['propertyNames'],depth+1)
        if type(value) is list:
            require(len(value)>=node.get('minItems',0) and len(value)<=node.get('maxItems',268435456),'UIZB_ACTUAL_ARRAY_MULTIPLICITY')
            if node.get('uniqueItems',False):
                require(len(value)<=4096 and len({uizb_canonical(item) for item in value})==len(value),'UIZB_ACTUAL_UNIQUE_ARRAY_VALUES')
            if 'items' in node:
                for item in value:
                    self.schema_match(item,node['items'],depth+1)
        if type(value) is str:
            require(len(value)>=node.get('minLength',0) and len(value)<=node.get('maxLength',268435456),'UIZB_ACTUAL_STRING_LENGTH')
            if 'pattern' in node:
                require(re.search(node['pattern'],value) is not None,'UIZB_ACTUAL_PINNED_DTO_PATTERN')
        if type(value) in [int,float]:
            uizb_number(value)
            if 'minimum' in node:
                require(value>=node['minimum'],'UIZB_ACTUAL_MINIMUM')
            if 'maximum' in node:
                require(value<=node['maximum'],'UIZB_ACTUAL_MAXIMUM')
            if 'exclusiveMinimum' in node:
                require(value>node['exclusiveMinimum'],'UIZB_ACTUAL_EXCLUSIVE_MINIMUM')
        for relation in node.get('x-relations',[]):
            self.schema_relation_value(value,relation)

    def relation_shape(self,value):
        allowed=set(UIZB_RELATION_IDS)
        require(type(value) is list and all(type(item) is str and item in allowed for item in value)
            and len(value)==len(set(value)),'UIZB_ONLY_ROOT_FINITE_RELATION_IDENTIFIERS')

    def binding_shape(self,row):
        uizb_keys(row,['schema','source','step','pointer','selector','requires'])
        require(row['source'] in ['prepared','verified-step','verified-associated','source-label']
            and (row['step'] is None or type(row['step']) is int and 1<=row['step']<=20),'UIZB_FINITE_NATIVE_OR_VERIFIED_BINDING_SOURCE')
        self.schema_shape(row['schema']);self.relation_shape(row['requires'])
        require(type(row['pointer']) is str and (row['pointer']=='' or row['pointer'].startswith('/'))
            and re.search(r'~(?![01])',row['pointer']) is None,'UIZB_BINDING_JSON_POINTER_NOT_EXPRESSION')
        selector=row['selector']
        if selector is not None:
            uizb_keys(selector,['class','array_pointer','match_pointer','equals_binding','value_pointer','unique'])
            require((selector['class'] is None or type(selector['class']) is str) and type(selector['equals_binding']) is str
                and selector['unique'] is True,'UIZB_UNIQUE_ACTUAL_ASSOCIATED_SELECTOR')
            for field in ['array_pointer','match_pointer','value_pointer']:
                require(type(selector[field]) is str and (selector[field]=='' or selector[field].startswith('/'))
                    and re.search(r'~(?![01])',selector[field]) is None,'UIZB_SELECTOR_ONLY_JSON_POINTER')

    def contract_rules(self):
        self.rule_seen=set()
        require(self.pin(self.read(self.auxroot/'ui-business-consumer-contract.json',33554432))==UIZB_DATA_PIN,
            'UIZB_FINAL_DATA_ACTUAL_BYTE_AUTHORITY')
        require(set(self.data['wire_rules']['relations'])==set(UIZB_RELATION_IDS)
            and len(self.data['schemas'])==174 and len(self.data['wire_rules']['bindings'])==24
            and len(self.data['wire_rules']['associated_classes'])==26,'UIZB_FINITE_SELECTED_DATA_REGISTRY')
        for row in self.data['wire_rules']['relations'].values():
            uizb_keys(row,['kind','operands','rule','source'])
        for node in self.data['schemas'].values():
            self.schema_shape(node)
        for node in self.data['wire_rules']['bindings'].values():
            self.binding_shape(node)
        require(type(self.data['steps']) is list and len(self.data['steps'])==20,'UIZB_ALL20_FINAL_CASES')
        for index,row in enumerate(self.data['steps'],1):
            uizb_keys(row,['index','name','role','primary','bindings','associated','facts','page','probes','screenshot_policy'])
            require(type(row['index']) is int and row['index']==index and row['name']==UIZB_STEPS[index-1]
                and row['role']==UIZB_ROLES[index-1],'UIZB_FINITE_CASE_ORDER')
            self.schema_shape(row['facts']);self.relation_shape(row['associated']['relations'])
        self.prepare_identity()
        self.schema_match(self.prepare_result,{'$ref':'#/schemas/prepared'})
        self.schema_match(self.ready,{'$ref':'#/schemas/ready'})
        self.schema_match(self.handoff,{'$ref':'#/schemas/handoff'})
        self.schema_match(self.binding,{'$ref':'#/schemas/binding'})
        self.verify_prepared_ready()
        self.verify_host_gates()
        self.rule_seen=set();self.current_step_object=None;self.current_event=None;self.current_expected=None

    def verify_prepared_ready(self):
        p=self.prepared;r=self.ready
        require(uizb_same(p['actor_ids'],r['actor_ids']) and uizb_same(p['writer'],r['writer'])
            and p['actor_ids']['customer']==p['customer_id'] and self.prepare_result['phase']=='final_source',
            'UIZB_FULL_PREPARED_NATIVE_ACTOR_WRITER_JOIN')
        require([row['quantity'] for row in p['item_quantities']]==[2,1]
            and len({row['item_id'] for row in p['item_quantities']})==2,'UIZB_ORIGINAL_Q2_Q1_ORDER')
        uizb_hex(p['source_fingerprint'])
        require(all(r[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256'])
            and r['container_id']==self.binding['backend_cid']
            and r['native_created_sha256']==sha(self.read(self.e/'ui-backend-created.json',33554432))
            and r['source_binding_sha256']==sha(self.read(self.e/UI_WEB_BACKEND['binding'],33554432))
            and r['prepare_history_sha256']==sha(self.read(self.e/'ui-prepare-predecessor-history.json',33554432)),
            'UIZB_READY_ORIGINAL_CREATED_BINDING_HISTORY')
        group=r['group'];require(group['leader_pid']==group['pgid']==group['sid']>1,'UIZB_REAL_BACKEND_PROCESS_GROUP')
        require(r['supervisor']['pid']==1 and len({m['pid'] for m in r['members']})==len(r['members']),
            'UIZB_REAL_PID1_SUPERVISOR_AND_UNIQUE_MEMBERS')
        leaders=[m for m in r['members'] if m['pid']==group['leader_pid']]
        require(len(leaders)==1 and leaders[0]['starttime']==group['leader_starttime']
            and all(m['pgid']==group['pgid'] and m['sid']==group['sid'] and m['state'] not in ['Z','X','x'] for m in r['members']),
            'UIZB_ACTUAL_MEMBER_STARTTIME_GROUP_AND_STATE')
        menus=p['menus'];anchors=menus['check']['anchors'];ids=menus['apply']['menu_ids']
        require(len(set(ids.values()))==2 and len({v['id'] for v in anchors.values()})==4
            and not set(ids.values())&{v['id'] for v in anchors.values()} and menus['apply']['created_menu_ids']==ids,
            'UIZB_NATIVE_TWO_NEW_MENU_IDS_DISJOINT_FROM_FOUR_ANCHORS')
        for name in ['apply','postcheck','reapply']:
            require(uizb_same(menus[name]['anchors'],anchors) and uizb_same(menus[name]['menu_ids'],ids)
                and uizb_same(menus[name]['source_binding'],menus['check']['source_binding']),
                'UIZB_FOUR_REAL_MENU_RESULTS_SAME_ANCHORS_SOURCE_AND_NATIVE_IDS')
        for field,path in [('entry_sha256','help/migrations/marketplace-ms4-order-read-menus.php'),
                ('ms1_sha256','help/migrations/marketplace-ms1.php')]:
            matches=[x for x in self.frozen['ui_backend_source_files'] if x['path']==path]
            require(len(matches)==1 and menus['check']['source_binding'][field]==matches[0]['sha256'],
                'UIZB_ORIGINAL_MENU_MIGRATION_SOURCE')
        old=p['observer']['before'][0];new=p['observer']['configured'][0]
        require(type(new['session_stats_expiry']) in [int,str] and new['session_stats_expiry'] in [0,'0']
            and all(uizb_same(old[k],new[k]) for k in old if k!='session_stats_expiry'),
            'UIZB_ONLY_INDEPENDENT_OBSERVER_SESSION_EXPIRY_CONFIGURED_ZERO')
        for row in [old,new]:
            for key in ['session_stats_expiry','global_stats_expiry']:
                value=row[key];require(type(value) in [int,str] and str(value).isdigit()
                    and 0<=int(value)<=4294967295,'UIZB_ORIGINAL_SESSION_EXPIRY_UINT32')
        child=self.prepare_result['child'];g=child['group']
        require(g['leader_pid']==g['pgid']==g['sid'] and child['first_terminal']['pid']==g['leader_pid'],
            'UIZB_PREPARED_CHILD_ORIGINAL_NORMAL_TERMINAL')
        require(child['first_terminal']['termsig']!=9
            and (child['stop_signal'] is None or child['stop_signal']==15 and child['term_attempted'] is True),
            'UIZB_ORIGINAL_NORMAL_CHILD_NO_FORCED_OR_FABRICATED_TERMINAL')
        for stream in child['streams']:
            require(stream['bytes']!=0 or stream['sha256']==sha(b''),'UIZB_ORIGINAL_EMPTY_CHILD_STREAM_SHA')
        # The existing independent zero full prepare/history/native checks remain mandatory;
        # they precede this consumer in ui_main and are repeated by the original lifecycle body.
        # Their complete49 original public files are additionally re-read in preservation().

    def verify_host_gates(self):
        previous=None
        for stage in ['before','after']:
            gate=self.handoff['host_gates'][stage]
            require(gate['stage']==stage and gate['argv'][:3]==['-B','-X','utf8']
                and gate['argv'][4:6]==['ui-web-private-inputs','--context']
                and Path(gate['argv'][3]).absolute()==(ARTIFACTS/'ms4-host.py').absolute()
                and Path(gate['argv'][6]).absolute()==self.context_file.absolute(),'UIZB_EXACT_GENUINE_HOST_GATE_ARGV7')
            stem='ui-private-caller-gate-'+stage
            native=uizb_json(self.read(self.e/(stem+'.native.json'),33554432))
            require(uizb_same(native,gate),'UIZB_ORIGINAL_HOST_GATE_NATIVE_PUBLIC_READBACK')
            streams={}
            for stream in ['stdout','stderr']:
                raw=self.read(self.e/(stem+'.'+stream),33554432,stream=='stderr');row=gate[stream]
                require(row['bytes']==row['stored_bytes']==len(raw) and row['sha256']==row['stored_sha256']==sha(raw)
                    and row['complete'] is True,'UIZB_HOST_GATE_SAME_PID_FULL_ORIGINAL_DUAL_STREAMS')
                streams[stream]=raw
            require(streams['stderr']==b'','UIZB_HOST_GATE_REAL_EMPTY_STDERR')
            out=uizb_json(streams['stdout'])
            uizb_keys(out,['status','descriptor','ready','reader_module','private_caller_execution','form_login_business_DOM'])
            require(out['status']=='UI_BACKEND_REAL_PRIVATE_READER_INPUTS_ONLY'
                and out['private_caller_execution']=='NOT IMPLEMENTED_OR_PROVEN_BY_HOST' and out['form_login_business_DOM']=='NOT RUN'
                and uizb_same(out['descriptor'],self.handoff['reader_inputs']) and uizb_same(out['ready'],self.handoff['ready'])
                and uizb_same(out['reader_module'],{'path':'browser-v1/ms4-ui-private-reader.mjs',
                    **self.aux['members'][UIZB_AUX_ROLES['reader']]}),'UIZB_ORIGINAL_HOST6_PUBLIC_SOURCE_AND_READER_BINDING')
            require(previous is None or uizb_same(previous,out),'UIZB_BOTH_HOST_GATES_IDENTICAL_COMPLETE_PUBLIC_OUTPUT')
            previous=out
        inputs=uizb_json(self.read(self.e/'ui-web-private-inputs.json',33554432))
        uizb_keys(inputs,['run_id','source_sha','freeze_sha256','container_id','native_created','fixture_volume','writer','actors'])
        require(all(inputs[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256'])
            and inputs['container_id']==self.binding['backend_cid'] and uizb_same(inputs['writer'],self.prepared['writer'])
            and inputs['actors']=={'customer':{'uid':self.prepared['actor_ids']['customer']},
                'merchant':{'id':self.prepared['actor_ids']['merchant'],'mer_id':self.prepared['merchant_id']},
                'platform':{'id':self.prepared['actor_ids']['platform']}},'UIZB_ORIGINAL_PRIVATE_INPUTS_PUBLIC_ACTOR_MAP')

    def available_binding(self,name,index,allow_current=False):
        row=self.data['wire_rules']['bindings'][name]
        return row['source']=='prepared' or row['source']=='source-label' and row['step']<=index or row['source'] not in ['prepared','source-label'] and (row['step']<index or allow_current and row['step']==index)

    def resolve_binding(self,name,index,allow_current=False):
        require(name in self.data['wire_rules']['bindings'] and self.available_binding(name,index,allow_current)
            and name not in self.binding_stack,'UIZB_ONLY_AVAILABLE_ORIGINAL_BOUND_INPUT_NO_CYCLE')
        self.binding_stack.add(name)
        try:
            row=self.data['wire_rules']['bindings'][name];source=row['source'];step=row['step']
            if source=='prepared':
                require(step is None,'UIZB_PREPARED_BINDING_NO_ALTERNATE_ROOT');root=self.prepare_result
            elif source=='source-label':
                template=self.data_pointer(self.data['wire_rules']['source_label_values'],row['pointer'])
                value=self.source_label(template,index);self.schema_match(value,row['schema']);return value
            elif source=='verified-step':
                root=self.windows[step-1] if step<=len(self.windows) else self.current_step_object
                require(step<=len(self.windows) or step==index,'UIZB_BOUND_OUTPUT_ACTUAL_AVAILABLE_ACK')
            else:
                require(source=='verified-associated' and step<index,'UIZB_ASSOCIATED_BINDING_FROM_COMPLETED_PRIOR_STEP')
                selector=row['selector'];event=self.events[step-1];wire=self.windows[step-1]['wire']
                primary=[r for r in event['requests'] if r['method']==wire['method'] and r['path']==wire['path']
                    and r['request_public_sha256']==wire['body_public_sha256'] and r['response_public_sha256']==wire['response_public_sha256']]
                require(len(primary)==1,'UIZB_PRIOR_STEP_REAL_MUTATION_PRIMARY')
                matches=[r for c,r in self.classified[step] if c==selector['class'] and r['sequence']>primary[0]['sequence']
                    and r['started_at_ms']>=primary[0]['finished_at_ms']]
                require(matches,'UIZB_ACTUAL_PRIOR_POST_ACTION_MATCHING_CLASS_READBACK')
                request=max(matches,key=lambda r:r['sequence'])
                base=self.data_pointer(request,row['pointer']);value=self.select_bound(base,selector,index)
                self.schema_match(value,row['schema']);return value
            value=self.data_pointer(root,row['pointer'])
            if row['selector'] is not None:
                value=self.select_bound(value,row['selector'],index)
            self.schema_match(value,row['schema']);return value
        finally:
            self.binding_stack.remove(name)

    def select_bound(self,root,selector,index):
        require(selector['unique'] is True,'UIZB_UNIQUE_NATIVE_SAVED_ITEM_SELECTOR')
        array=self.data_pointer(root,selector['array_pointer']);require(type(array) is list,'UIZB_REAL_NATIVE_ARRAY_SELECTOR')
        wanted=self.resolve_binding(selector['equals_binding'],index)
        matches=[r for r in array if uizb_same(self.data_pointer(r,selector['match_pointer']),wanted)]
        require(len(matches)==1,'UIZB_UNIQUE_ORIGINAL_CHILD_OR_ROOT_ITEM')
        if selector['class']=='merchant_packages':
            items=matches[0]['items'];require(len(items)==1 and items[0]['quantity']==2
                and items[0]['product_id']==self.resolve_binding('q2_product_id',index),
                'UIZB_Q2_CHILD_LOCAL_ITEM_FULL_NATIVE_LINEAGE')
        return self.data_pointer(matches[0],selector['value_pointer'])

    def template_path(self,template,index,allow_current=False,optional=False):
        from urllib.parse import quote
        names=re.findall(r'\{([A-Za-z0-9_]+)\}',template)
        require(template.count('{')==len(names) and template.count('}')==len(names),'UIZB_ONLY_CLOSED_NAMED_PATH_TEMPLATE')
        result=template
        for name in names:
            if name=='visible_home_query':
                return None
            require(name in self.data['wire_rules']['bindings'],'UIZB_NO_UNDECLARED_PATH_BINDING')
            if not self.available_binding(name,index,allow_current):
                require(optional,'UIZB_NO_FUTURE_CHILD_PATH');return None
            value=self.resolve_binding(name,index,allow_current)
            require(type(value) in [int,str] and type(value) is not bool,'UIZB_PATH_COMPONENT_NATIVE_VALUE')
            text=str(value)
            if name=='public_attachment_ref':
                customer,paid_root,q2=self.review_parents()
                require(template=='{public_attachment_ref}' and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',text) is not None,
                    'UIZB_EXACT_KNOWN_PREPARED_CUSTOMER_ATTACHMENT_PATH');encoded=text
            else:
                require(re.fullmatch(r'[A-Za-z0-9_.-]+',text) is not None,'UIZB_SOURCE_BOUNDED_FRONTEND_PATH_COMPONENT')
                encoded=quote(text,safe="~()*!.'-_")
            result=result.replace('{'+name+'}',encoded)
        return result

    def primary_rule(self,wire,index):
        rule=self.data['steps'][index-1]['primary']
        require(wire['method']==rule['method'] and wire['path']==self.template_path(rule['path_template'],index),
            'UIZB_ACTUAL_PRIMARY_COMPLETE_PATH_SOURCE_BINDINGS')
        self.schema_match(wire['body_public'],rule['request_schema'])
        self.schema_match(wire['response_public'],rule['response_schema'])
        wrapper={'wire':wire}
        for row in self.data['wire_rules']['binding_uses'].get(str(index),[]):
            uizb_keys(row,['pointer','binding','mode','template']);value=self.data_pointer(wrapper,row['pointer'])
            if row['mode']=='deep-equal':
                require(row['template'] is None and uizb_same(value,self.resolve_binding(row['binding'],index)),
                    'UIZB_CURRENT_INPUT_ORIGINAL_BINDING_DEEP_EQUAL')
            else:
                require(row['mode']=='path-template' and row['template']==rule['path_template']
                    and value==self.template_path(row['template'],index),'UIZB_ENTIRE_PRIMARY_PATH_NOT_STRING_CHILD_POINTER')
        if index in [9,12]:
            require(wire['body_public']['items'][0]['quantity']==(2 if index==9 else 1),'UIZB_EXACT_Q2_THEN_Q1_SHIPMENT')
        if index in [10,11,13]:
            require(wire['response_public']['root_order_id']==self.prepared['paid_order_id']
                and wire['response_public']['root_order_no']==self.prepared['paid_order_no'],'UIZB_RECEIPT_ORIGINAL_ROOT')
        if index in [15,17,18]:
            reply=wire['response_public'];require(reply['root_order_id']==self.prepared['paid_order_id']
                and reply['source_item_id']==self.prepared['item_quantities'][0]['item_id']
                and reply['product_id']==self.resolve_binding('q2_product_id',index)
                and len(reply['pics'])==1 and reply['pics'][0] in [self.resolve_binding('public_attachment_ref',index),self.resolve_binding('public_attachment_url',index)],'UIZB_REVIEW_ORIGINAL_PAID_ITEM_AND_NATIVE_PUBLIC_IMAGE')

    def request_rule(self,request,index):
        self.schema_match(request,{'$ref':'#/schemas/request'})
        allowed=self.data['steps'][index-1]['associated']['classes'];classes=self.data['wire_rules']['associated_classes']
        found=[]
        for name in allowed:
            rule=classes[name]
            if request['method']!=rule['method']:
                continue
            paths=[self.template_path(t,index,True,True) for t in rule['path_templates']]
            matched=request['path'] in paths
            if name=='startup_products' and request['path'].startswith('/api/products?'):
                from urllib.parse import parse_qsl
                raw=request['path'][len('/api/products?'):];pairs=parse_qsl(raw,keep_blank_values=True,strict_parsing=True)
                require(len(pairs)==len(dict(pairs)) and all(k in ['ids','priceOrder','salesOrder','cate_id','store_label_id','limit']
                    and '[' not in k and ']' not in k and '\x00' not in k+v for k,v in pairs),'UIZB_FINITE_ACTUAL_HOME_PRODUCTS_QUERY')
                matched=True
            if matched:
                found.append(name)
        primary=self.current_wire
        isprimary=request['method']==primary['method'] and request['path']==primary['path']\
            and request['request_public_sha256']==primary['body_public_sha256'] and request['response_public_sha256']==primary['response_public_sha256']\
            and uizb_same(request['request_public'],primary['body_public']) and uizb_same(request['response_public'],primary['response_public'])
        require(len(found)==1 or len(found)==0 and isprimary,'UIZB_EVERY_REAL_REQUEST_ONE_FINITE_CLASS')
        name=found[0] if found else 'primary'
        if name!='primary':
            rule=classes[name]
            for template in rule['path_templates']:
                if self.template_path(template,index,True,True)==request['path']:
                    outputs=[self.data['wire_rules']['bindings'][key] for key in re.findall(r'\{([A-Za-z0-9_]+)\}',template)
                        if key in self.data['wire_rules']['bindings']]
                    if any(r['source']=='verified-step' and r['step']==index for r in outputs):
                        originals=[r for r in self.current_event['requests'] if r['method']==self.current_wire['method']
                            and r['path']==self.current_wire['path'] and r['request_public_sha256']==self.current_wire['body_public_sha256']
                            and r['response_public_sha256']==self.current_wire['response_public_sha256']]
                        require(len(originals)==1 and request['sequence']>originals[0]['sequence']
                            and request['started_at_ms']>=originals[0]['finished_at_ms'],
                            'UIZB_CURRENT_OUTPUT_ONLY_USED_AFTER_ACTUAL_PRIMARY_RESPONSE')
            self.schema_match(request['request_public'],rule['request_schema'])
            self.schema_match(request['response_public'],rule['response_schema']);auth=rule['authentication']
            if auth=='anonymous':
                require(uizb_same(request['authentication'],{'present':False,'kind':'none','subject_id':None}),
                    'UIZB_ACTUAL_PUBLIC_CLASS_ANONYMOUS_IN_ANY_ROLE_STAGE')
            elif auth=='customer-or-source-optional-startup':
                require(request['authentication']['present'] is False or request['authentication']['subject_id']==self.prepared['customer_id'],
                    'UIZB_SOURCE_OPTIONAL_CUSTOMER_STARTUP_AUTH')
            else:
                role='merchant' if auth.startswith('merchant') else 'platform' if auth=='platform' else 'customer'
                require(uizb_same(request['authentication'],{'present':True,'kind':'Bearer','subject_id':self.prepared['actor_ids'][role]}),
                    'UIZB_REAL_CLASS_TOKEN_ROLE_SUBJECT')
            if name=='customer_identity':
                require(request['response_public']['uid']==self.prepared['customer_id'],'UIZB_SAME_NATIVE_CUSTOMER_RESPONSE')
            if name=='merchant_identity':
                r=request['response_public'];require(r['employee_id']==self.prepared['actor_ids']['merchant']
                    and r['merchant_id']==self.prepared['merchant_id'],'UIZB_SAME_NATIVE_MERCHANT_RESPONSE')
            if name in ['public_review_list','public_review_counts']:
                schema=('public_review_list_' if name=='public_review_list' else 'public_review_counts_')+('pending' if index<=16 else 'approved')
                self.schema_match(request['response_public'],{'$ref':'#/schemas/'+schema})
        self.classified.setdefault(index,[]).append((name,request))

    def finish_request_rules(self,event,index,primary):
        rows=self.classified.get(index,[]);require(len(rows)==len(event['requests']),'UIZB_COMPLETE_FINITE_CLASSIFICATION_NO_OMISSION')
        rule=self.data['steps'][index-1]['associated'];required=rule['required']
        for name,limits in required.items():
            found=[r for c,r in rows if c==name]
            if limits['position']!='any':
                require(primary is not None,'UIZB_REAL_PRIMARY_REQUIRED_FOR_RELATIVE_ORDER')
                found=[r for r in found if (r['sequence']<primary if limits['position']=='before-primary' else r['sequence']>primary)]
            require(len(found)>=limits['minimum'] and (limits['maximum'] is None or len(found)<=limits['maximum']),
                'UIZB_SOURCE_CLASS_MINIMUM_MAXIMUM_ACTUAL_COUNT')
        paths=[r['path'] for _,r in rows]
        if index==1:
            required_paths=['/api/basic_config','/api/theme_info/theme','/api/lang_version','/api/get_script','/api/wechat/get_logo',
                '/api/user','/api/share','/api/theme_info/home','/api/cart/count?numType=0','/api/copyright']
            require(all(path in paths for path in required_paths) and any(path in paths for path in ['/api/v2/get_today_coupon','/api/v2/new_coupon']),
                'UIZB_REAL_CUSTOMER_LOGIN_ALL_SOURCE_REQUIRED_STARTUP')
        if index==16:
            require(all(path in paths for path in ['/api/logout','/api/share','/api/theme_info/home','/api/copyright']),
                'UIZB_ORDINARY_LOGOUT_ALL_SOURCE_REQUIRED_HOME_READS')
            logout=[r for c,r in rows if c=='logout'];require(len(logout)==1,'UIZB_EXACT_ORDINARY_UI_LOGOUT')
            require(all(r['authentication']['present'] is False for _,r in rows if r['sequence']>logout[0]['sequence']),
                'UIZB_REAL_NO_AUTH_HEADER_AFTER_ORDINARY_LOGOUT')
        if index in [5,6,7,8]:
            pairs=self.current_expected['independent']['private_pairs'];tickets=[r for c,r in rows if c.endswith('_private_ticket')]
            content=[r for c,r in rows if c.endswith('_private_content')]
            require(len(pairs)==len(tickets)==len(content) and {p['issued_sequence'] for p in pairs}=={r['sequence'] for r in tickets}
                and {p['read_sequence'] for p in pairs}=={r['sequence'] for r in content},'UIZB_ALL_PRIVATE_TICKETS_AND_CONTENT_PAIRED_ONCE')
        for name in self.data['steps'][index-1]['bindings']:
            self.resolve_binding(name,index,True)
        self.schema_match(self.current_expected['independent'],self.data['steps'][index-1]['facts'])
        if index in [16,19]:
            counts=[r for c,r in rows if c=='public_review_counts'];lists=[r for c,r in rows if c=='public_review_list']
            require(len(counts)==len(lists)==1 and lists[0]['sequence']==primary,'UIZB_DISTINCT_EXACT_ONE_ANONYMOUS_LIST_AND_CONFIG')

    def language_label(self,key,index):
        maps=[]
        for step,rows in self.classified.items():
            if step<=index:
                for name,row in rows:
                    if name=='startup_language_json':
                        value=row['response_public'];require(type(value) is dict and len(value)==1,'UIZB_ACTUAL_ORIGINAL_LANGUAGE_RANGE')
                        table=next(iter(value.values()));require(type(table) is dict,'UIZB_ACTUAL_ORIGINAL_LANGUAGE_TABLE')
                        if key in table:
                            text=table[key];require(type(text) is str,'UIZB_ACTUAL_NATIVE_LANGUAGE_STRING');maps.append(text)
        require(maps and all(text==maps[-1] for text in maps),'UIZB_KNOWN_SAME_RUN_ORIGINAL_LANGUAGE_LABEL')
        return maps[-1]

    def source_label(self,template,index):
        uizb_keys(template,['kind','parts','source']);require(template['kind'] in ['template','enum-template'],'UIZB_ONLY_FINITE_SOURCE_LABEL_TEMPLATE')
        text=[]
        for part in template['parts']:
            if set(part)=={'literal'}:
                text.append(part['literal'])
            elif set(part)=={'system_language_key'}:
                text.append(self.language_label(part['system_language_key'],index))
            elif set(part)=={'binding'}:
                text.append(self.resolve_binding(part['binding'],index,True))
            else:
                uizb_keys(part,['pointer','enum']);ctx={**self.windows[index-1],
                    'expected_delta':self.public(self.windows[index-1]['expected_delta'],'ui-window-%02d-expected.json'%index,True)}
                value=self.data_pointer(ctx,part['pointer']);require(value in part['enum'],'UIZB_SOURCE_ENUM_LABEL_ACTUAL_NATIVE_FACT')
                text.append(part['enum'][value])
        require(all(type(v) is str for v in text),'UIZB_SOURCE_LABEL_PARTS_TEXT_ONLY')
        return ' '.join(''.join(text).split())

    def dom_rule(self,observation,measurement,index):
        rule=self.data['steps'][index-1];page=rule['page'];variants=page['variants']
        matches=[v for v in variants if v['name']==observation['state']['page']]
        if matches:
            require(len(matches)==1,'UIZB_UNIQUE_ACTUAL_PAGE_VARIANT');selected=matches[0]
            allowed=set(selected['applicable_probe_keys']);kind=selected['kind'];policy=selected['screenshot_policy']
        else:
            require(observation['state']['page']==page['name'],'UIZB_ACTUAL_FINITE_CASE_PAGE_NAME')
            allowed={r['key'] for r in rule['probes']};kind=page['kind'];policy=rule['screenshot_policy']
        require(observation['state']['screenshot_policy']==policy,'UIZB_ACTUAL_PAGE_SPECIFIC_SCREENSHOT_POLICY')
        guards=observation['state']['screenshot_guard']
        if kind in ['authentication','private-preview']:
            require(policy=='omit' and observation['screenshot'] is None
                and ('AUTHENTICATION_POLICY' if kind=='authentication' else 'PRIVATE_PREVIEW_POLICY') in guards,
                'UIZB_NO_AUTH_OR_PRIVATE_SCREENSHOT')
        controls=measurement['controls'];probes={r['key']:r for r in rule['probes']}
        require(controls and all(c['key'] in allowed for c in controls),'UIZB_CURRENT_APPLICABLE_VISIBLE_PROBE_SUBSET_NOT_DISAPPEARED_CONTROLS')
        for actual in controls:
            probe=probes[actual['key']];require(actual['kind']==probe['kind'],'UIZB_SOURCE_KIND_AND_ACTUAL_VISIBLE_ELEMENT')
            label=probe['label']
            if label is None:
                require(actual['label'] is None,'UIZB_INPUT_IMAGE_NO_EXPORTED_VALUE')
            elif label['kind']=='literal':
                require(actual['label']==' '.join(label['value'].split()),'UIZB_EXACT_SAFE_SOURCE_LITERAL_LABEL')
            elif label['kind']=='system-language':
                require(actual['label']==' '.join(self.language_label(label['key'],index).split()),'UIZB_ACTUAL_ORIGINAL_LANGUAGE_LABEL')
            else:
                require(label['kind']=='native-bound' and actual['label']==' '.join(str(self.resolve_binding(label['binding'],index,True)).split()),
                    'UIZB_ACTUAL_NATIVE_OR_PRIOR_VERIFIED_VISIBLE_LABEL')
            self.inside(actual['bounds'],{'x':0,'y':0,'width':observation['viewport']['width'],'height':observation['viewport']['height']})
        if kind!='normal':
            require(observation['screenshot'] is None,'UIZB_NONNORMAL_OMISSION_NOT_ROLE_WIDTH_COVERAGE')

    def public_materials_join(self,value,final_ledger):
        uizb_keys(value,['schema','scope','run_id','source_sha','freeze_sha256','files','directories','builds'])
        require(type(value['schema']) is int and value['schema']==1 and value['scope']=='MS4_UI_PUBLIC_MATERIALS_COMPLETE'
            and all(value[k]==self.binding[k] for k in ['run_id','source_sha','freeze_sha256']),'UIZB_TYPED_FULL_PUBLIC_MATERIALS8')
        uizb_keys(value['builds'],['h5','merchant','admin'])
        for role in ['h5','merchant','admin']:
            leaf='manifests/ui-'+role+'-dist.json';descriptor=value['builds'][role]
            require(uizb_same(descriptor,{'path':'/run/ms4-prep/'+leaf,**self.artifacts[leaf]}),
                'UIZB_ALL_THREE_ORIGINAL_FULL_DIST_MANIFEST_DESCRIPTORS')
        require(type(value['files']) is list and type(value['directories']) is list,'UIZB_COMPLETE_ORIGINAL_PUBLIC_TREE_LISTS')
        files={}
        for row in value['files']:
            uizb_keys(row,['path','bytes','sha256']);uizb_relative(row['path']);uizb_pin({k:row[k] for k in ['bytes','sha256']},True)
            require(row['path'] not in files,'UIZB_UNIQUE_COMPLETE_ORIGINAL_PUBLIC_FILE');files[row['path']]=row
        directories=set()
        for path in value['directories']:
            uizb_relative(path);require(path not in directories,'UIZB_UNIQUE_ORIGINAL_PUBLIC_DIRECTORY');directories.add(path)
        baseline=self.baseline['files']['roots']['/workspace/crmeb/public'];final=final_ledger['files']['roots']['/workspace/crmeb/public']
        require({k for k,v in baseline.items() if v['kind']=='file'}==set(files)
            and {k for k,v in baseline.items() if v['kind']=='directory'}==directories|{''},
            'UIZB_BASELINE_WHOLE_PUBLIC_NAMESPACE_EQUALS_FULL_ORIGINAL_MANIFEST')
        for path,row in files.items():
            require(baseline[path]['bytes']==row['bytes'] and baseline[path]['sha256']==row['sha256']
                and path in final and uizb_same(baseline[path],final[path]),'UIZB_EVERY_ORIGINAL_PUBLIC_FILE_FULL_NATIVE_STAT_BYTES_HASH_UNCHANGED')
        require(self.state['image'] is not None,'UIZB_ORIGINAL_NORMAL_PUBLIC_UPLOAD_PRESENT')
        customer,paid_root,q2=self.review_parents();reference=self.state['image']['reference']
        require(type(reference) is str and re.fullmatch(r'/uploads/customer/'+str(customer)+r'/[a-f0-9]{32}/[a-f0-9]{32}\.png',reference) is not None
            and self.state['image']['url']==self.binding['origin']+reference
            and self.state['image']['image']==self.artifacts['ms4-order.png'],'UIZB_EXACT_PREPARED_CUSTOMER_SELECTED_PUBLIC_PNG')
        image=reference[1:];parents=set();current=image
        while '/' in current:
            current=current.rsplit('/',1)[0];parents.add(current)
        extra_files={k for k,v in final.items() if v['kind']=='file'}-set(files)
        extra_dirs={k for k,v in final.items() if v['kind']=='directory'}-(directories|{''})
        require(image not in baseline and extra_files=={image} and extra_dirs==parents-(directories|{''})
            and all(parent in final and final[parent]['kind']=='directory' for parent in parents),
            'UIZB_ONLY_SELECTED_CUSTOMER_PNG_AND_EXACT_MISSING_NATIVE_PARENT_CHAIN')
        for path in directories|{''}:
            require(path in final and final[path]['kind']=='directory','UIZB_EVERY_ORIGINAL_EMPTY_PUBLIC_DIRECTORY_RETAINED')
            if path in parents:
                added=sum(1 for child in extra_dirs if (child.rsplit('/',1)[0] if '/' in child else '')==path)
                require(all(baseline[path][field]==final[path][field] for field in ['kind','dev','ino','mode','uid','gid'])
                    and final[path]['nlink']==baseline[path]['nlink']+added,'UIZB_EXACT_SELECTED_PARENT_ONLY_LINK_INCREMENT')
            else:
                require(uizb_same(baseline[path],final[path]),'UIZB_EVERY_OTHER_PUBLIC_DIRECTORY_NATIVE_FIELDS_UNCHANGED')
        require(final[image]['bytes']==len(self.fixture) and final[image]['sha256']==sha(self.fixture),
            'UIZB_ONLY_ACTUAL_NORMAL_SELECTED_PUBLIC_PNG_FULL_BYTES')

    def schema_relation_value(self,value,name):
        require(name in UIZB_RELATION_IDS,'UIZB_NO_UNIMPLEMENTED_RELATION_ID')
        self.rule_seen.add(name)
        if name=='NATIVE_U64_BOUND':
            self.native_id(value)
        elif name=='PUBLIC_SECRET_FIELDS_ABSENT':
            uizb_safe(value)
        elif name=='NONEMPTY_TRIMMED_TEXT':
            if type(value) is str:
                require(value.strip()!='','UIZB_ACTUAL_NONEMPTY_TEXT')
            elif type(value) is dict:
                for key in ['reason','comment','content','carrier_code','carrier_name','tracking_no']:
                    if key in value:
                        require(type(value[key]) is str and value[key].strip()!='','UIZB_ORIGINAL_SOURCE_NONEMPTY_TEXT_FIELD')
                if 'delivery' in value:
                    for key in ['carrier_code','carrier_name','tracking_no']:
                        require(value['delivery'][key].strip()!='','UIZB_ACTUAL_DELIVERY_TEXT')
        elif name=='AUTH_PRESENT_SUBJECT_EQUIVALENCE':
            require(value['present'] is True and value['kind']=='Bearer' and type(value['subject_id']) is int and value['subject_id']>0
                or value['present'] is False and value['kind']=='none' and value['subject_id'] is None,'UIZB_AUTH_FIELDS_EXACT_EQUIVALENCE')
        elif name in ['FROZEN_ORIGIN_CANONICAL','BACKEND_GATEWAY_DISTINCT']:
            require(value['origin']==self.binding['origin'] and value['backend_cid']!=value['gateway_cid'],
                'UIZB_ORIGINAL_CANONICAL_ORIGIN_DISTINCT_NATIVE_IDS')
        # Other IDs have whole-record and cross-step operands; they are checked by the
        # explicit finite methods below and the complete original native/source joins.
        # Descriptive kind/rule/operand strings are never executed or used as expressions.

    def quote_join(self,value):
        require(value['root']['id']==self.prepared['paid_order_id'] and value['root']['order_no']==self.prepared['paid_order_no'],
            'UIZB_QUOTE_SAME_ORIGINAL_PAID_ROOT')
        items={r['item']['id']:r['item'] for r in value['root_items']}
        require(len(items)==2 and set(items)=={r['item_id'] for r in self.prepared['item_quantities']}
            and all(items[r['item_id']]['quantity']==r['quantity'] for r in self.prepared['item_quantities']),
            'UIZB_QUOTE_FULL_ORIGINAL_TWO_ITEM_QUANTITIES')
        if len(self.windows)>=4:
            original={r['item']['id']:r['item'] for r in self.windows[3]['wire']['response_public']['root_items']}
            require(uizb_same(items,original),'UIZB_QUOTE_SAVED_ORIGINAL_ITEM_SNAPSHOTS_PRESERVED')

    def package_join(self,package,index):
        require(package['root_order_id']==self.prepared['paid_order_id'] and package['root_order_no']==self.prepared['paid_order_no']
            and package['payment_owner_order_id']==self.prepared['paid_order_id'] and len(package['items'])==1,
            'UIZB_CHILD_SAME_ORIGINAL_ROOT_SINGLE_SAVED_ITEM')
        known=[]
        for number in [9,12]:
            if number<=len(self.windows):
                known.append(self.windows[number-1]['wire']['response_public'])
            elif number==index:
                known.append(self.current_wire['response_public'])
        matches=[r for r in known if r['child_order_id']==package['id'] and r['child_order_no']==package['order_id']]
        require(len(matches)==1,'UIZB_ONLY_ACTUAL_CREATED_CHILD_FROM_ORIGINAL_ACK')
        row=matches[0];source=self.prepared['item_quantities'][0 if row['quantity']==2 else 1]['item_id']
        original=next(r['item'] for r in self.windows[3]['wire']['response_public']['root_items'] if r['item']['id']==source)
        item=package['items'][0]
        require(all(uizb_same(item[k],v) for k,v in original.items() if k!='id')
            and item['id']!=source,'UIZB_CHILD_LOCAL_PK_AND_FULL_SAVED_PRODUCT_SKU_PRICE_SNAPSHOT')

    def review_join(self,value,index):
        require(value['root_order_id']==self.prepared['paid_order_id'] and value['source_item_id']==self.prepared['item_quantities'][0]['item_id']
            and value['product_id']==self.resolve_binding('q2_product_id',index)
            and len(value['pics'])==1 and value['pics'][0] in [self.resolve_binding('public_attachment_ref',index),self.resolve_binding('public_attachment_url',index)],'UIZB_REVIEW_SAME_ORIGINAL_ROOT_ITEM_PRODUCT_PUBLIC_REFERENCE')
        if len(self.windows)>=15:
            old=self.windows[14]['wire']['response_public']
            changed={'status'} if index==17 else {'status','is_reply','merchant_reply_content','merchant_reply_time'} if index>=18 else set()
            require(all(uizb_same(value[k],v) for k,v in old.items() if k not in changed),'UIZB_REVIEW_ALL_ORIGINAL_COLUMNS_PRESERVED')
        require(value['status']==(1 if index>=17 else 0) and value['is_reply']==(1 if index>=18 else 0),
            'UIZB_ACTUAL_MODERATION_AND_REPLY_ORDER')

    def review_read_join(self,value,index,sequence,primary):
        # self.windows contains only complete earlier native-ACKed and independently checked steps.
        # Select the actual previous review mutation, never a guessed index-minus-one state.
        uizb_int(primary,0,len(self.current_event['requests'])-1)
        uizb_int(sequence,0,len(self.current_event['requests'])-1)
        require(sequence!=primary and self.current_step_object['name'] in ['n2-platform-moderate','n2-merchant-reply'],
            'UIZB_REVIEW_DETAIL_ACTUAL_DISTINCT_PRIMARY_REQUEST_SEQUENCE')
        history=[step for step in self.windows if step['name'] in ['n2-review-submit','n2-platform-moderate','n2-merchant-reply']]
        require(history,'UIZB_REVIEW_DETAIL_REQUIRES_ACTUAL_PRIOR_VERIFIED_REVIEW')
        previous=history[-1];number=uizb_int(previous['index'],1,index-1)
        source={'path':'/workspace/help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
            **self.php['help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php']}
        prior_ack=self.frames[2*number]['receipt'];current_ack=self.frames[2*index]['receipt']
        require(previous['binding']==self.binding and previous['name']==UIZB_STEPS[number-1]
            and previous['oracle']['source']==source and prior_ack['oracle']['source']==source
            and prior_ack['oracle']['case_name']==previous['name']
            and uizb_same(prior_ack['oracle']['expected'],prior_ack['expected_delta'])
            and uizb_same(prior_ack['oracle']['actual'],prior_ack['after'])
            and uizb_same(prior_ack['oracle']['readback'],prior_ack['verified_after'])
            and type(prior_ack['expected_delta']['independent']['review_id']) is int
            and prior_ack['expected_delta']['independent']['review_id']==previous['wire']['response_public']['id'],
            'UIZB_PREVIOUS_FULL_REVIEW_DTO_SAME_ORIGINAL_NATIVE_ACK_AND_SOURCE')
        require(current_ack['oracle']['source']==source and current_ack['oracle']['case_name']==self.current_step_object['name']
            and uizb_same(current_ack['expected_delta'],self.current_expected)
            and uizb_same(current_ack['oracle']['expected'],self.current_expected)
            and uizb_same(current_ack['oracle']['actual'],current_ack['after'])
            and uizb_same(current_ack['oracle']['readback'],current_ack['verified_after'])
            and type(self.current_expected['independent']['review_id']) is int
            and self.current_expected['independent']['review_id']==self.current_wire['response_public']['id'],
            'UIZB_CURRENT_REVIEW_MUTATION_COMPLETE_ORIGINAL_NATIVE_ACK_AND_SOURCE')
        self.review_join(self.current_wire['response_public'],index)
        expected=previous['wire']['response_public'] if sequence<primary else self.current_wire['response_public']
        require(uizb_same(value,expected),'UIZB_REVIEW_DETAIL_FULL_OLD_OR_NEW_NATIVE_DTO_BY_ACTUAL_PRIMARY_SEQUENCE')

    def typed_response_joins(self,event,index,primary):
        self.schema_match(self.current_step_object,{'$ref':'#/schemas/business_step'})
        self.schema_match(self.current_expected,{'$ref':'#/schemas/expected_delta'})
        self.schema_match(event,{'$ref':'#/schemas/browser_event'})
        self.schema_match(self.current_wire,{'$ref':'#/schemas/wire'})
        for name,row in self.classified.get(index,[]):
            value=row['response_public']
            if name=='aftersale_quote':
                self.quote_join(value)
            elif name=='merchant_packages':
                for package in value['packages']['list']:
                    self.package_join(package,index)
                require(value['packages']['count']==len(value['packages']['list']),'UIZB_REAL_COMPLETE_KNOWN_PACKAGE_SET')
            elif name=='merchant_package_detail':
                self.package_join(value['package'],index)
            elif name in ['merchant_review_detail','platform_review_detail']:
                self.review_read_join(value,index,row['sequence'],primary)
            elif name in ['customer_private_ticket','merchant_private_ticket']:
                require(value['file']['id']==self.resolve_binding('private_file_id',index,True)
                    and value['expires_at']==value['issued_at']+300,'UIZB_ORIGINAL_PRIVATE_TICKET_FILE_AND_SOURCE_300_SECOND_WINDOW')
        if index==4:
            self.quote_join(self.current_wire['response_public'])
        if index in [15,17,18]:
            self.review_join(self.current_wire['response_public'],index)
            if index==15:
                require(self.current_wire['response_public']['comment']==self.current_wire['body_public']['comment'],
                    'UIZB_REAL_REVIEW_TEXT_SOURCE_INPUT_AND_NATIVE_DTO')
            if index==18:
                require(self.current_wire['response_public']['merchant_reply_content']==self.current_wire['body_public']['content'],
                    'UIZB_REAL_REPLY_INPUT_AND_NATIVE_DTO')
        if index==19:
            row=self.current_wire['response_public'][0];saved=self.windows[17]['wire']['response_public']
            require(row['id']==saved['id'] and row['product_id']==saved['product_id'] and row['comment']==saved['comment']
                and row['avatar']==saved['avatar'] and row['nickname']==saved['nickname'] and row['suk']==saved['suk']
                and row['merchant_reply_content']==saved['merchant_reply_content']
                and uizb_same(row['pics'],saved['pics']),'UIZB_PUBLIC_ROW_VERIFIED_PRIOR_NATIVE_REVIEW_AND_REPLY')
            from datetime import datetime,timezone,timedelta
            formatted=datetime.fromtimestamp(saved['merchant_reply_time'],timezone(timedelta(hours=8))).strftime('%Y-%m-%d %H:%M')
            require(row['merchant_reply_time']==formatted,'UIZB_NATIVE_REPLY_TIME_ACTUAL_SHANGHAI_FORMAT')
            for _,request in self.classified[index]:
                if request['path'].startswith('/api/reply/list/'):
                    first=request['started_at_ms']//1000;last=request['finished_at_ms']//1000
                    def relative(observed):
                        age=observed-saved['add_time']
                        for seconds,label in [(31536000,'年'),(2592000,'个月'),(604800,'星期'),(86400,'天'),(3600,'小时'),(60,'分钟'),(1,'秒')]:
                            number=age//seconds
                            if number!=0:
                                return str(number)+label+'前'
                        return None
                    candidates={first,last,saved['add_time']}
                    if type(row['add_time']) is str:
                        match=re.fullmatch(r'(-?[1-9][0-9]*)(年|个月|星期|天|小时|分钟|秒)前',row['add_time'])
                        require(match is not None,'UIZB_ORIGINAL_RELATIVE_TIME_LITERAL')
                        seconds={'年':31536000,'个月':2592000,'星期':604800,'天':86400,'小时':3600,'分钟':60,'秒':1}[match[2]]
                        number=int(match[1]);candidates.update([saved['add_time']+number*seconds,saved['add_time']+(number+1)*seconds-1])
                    require(any(first<=t<=last and row['add_time']==relative(t) for t in candidates),
                        'UIZB_REAL_PUBLIC_RELATIVE_TIME_IN_ACTUAL_REQUEST_INTERVAL')

    def final_relations(self):
        # Exact finite relation IDs are rooted by UIZB_DATA_PIN. No kind/rule prose is evaluated.
        # The following groups name actual typed/readback implementations, not received passed flags.
        implemented={
            'binding/source':{'SAME_BINDING9','PREPARED_ROOT_ACTOR_JOIN','VERIFIED_PREVIOUS_DTO_JOIN','Q2_Q1_EXACT',
                'ORIGINAL_SOURCE_FINGERPRINT','SOURCE_BOUND_ORACLE_ACK','CURRENT_THREE_SOURCE','EXACT_ORDERED17',
                'FROZEN_ORIGIN_CANONICAL','BACKEND_GATEWAY_DISTINCT'},
            'native/prepare':{'GENUINE_DUAL_HOST_GATE_NATIVE','HANDOFF_CURRENT_PUBLIC_READBACK','READY_NATIVE_PARENT_CHILD_SOURCE_JOIN',
                'REAL_PROCESS_GROUP_JOIN','WRITER_PRIVATE_DESCRIPTOR_ONLY','SOURCE_BOUND_PREPARED_NATIVE','PREPARED_MENUS_NATIVE_SOURCE_JOIN',
                'PREPARED_OBSERVER_ONLY_SESSION_EXPIRY_ZERO','PREPARED_CHILD_NATIVE_TERMINAL_JOIN','SAME_NODE_NATIVE42_EOF'},
            'typed/window':{'FULL_ORIGINAL_PUBLIC_HASH','FULL_NATIVE_ROOT_SET','FULL_NATIVE_TABLE_SET','FULL_ORIGINAL_DTO_RETAINED',
                'FULL_PRESERVATION','EXACT_STEP_FACT_SCHEMA','PRIVATE_STDIN_RAM_ONLY','PUBLIC_SECRET_FIELDS_ABSENT','NATIVE_U64_BOUND',
                'NONEMPTY_TRIMMED_TEXT','REQUEST_INSIDE_WINDOW','SEQUENTIAL_REQUEST_INDEX'},
            'requests/cases':{'PRIMARY_REQUEST_UNIQUE','CASE20_ORIGINAL_IMAGE_WIRE','PRIVATE_TICKET_CONTENT_PAIR','NATIVE_LOGIN_TOKEN_JOIN',
                'ALL_THREE_LOGIN_TOKEN_REQUIRED','EXACT_ASSOCIATED_SET','PUBLIC_LIST_CONFIG_DISTINCT','CUSTOMER_LOGIN_REQUIRED_STARTUP',
                'REQUIRED_POST_ACTION_READBACK','ORDINARY_LOGOUT_ORDER','PUBLIC_LOGOUT_REQUIRED_HOME','PRIVATE_PAIR_COUNT_ORDER',
                'AUTH_PRESENT_SUBJECT_EQUIVALENCE','FULFILLMENT_FIXED20_STATE','CHILD_LOCAL_ITEM_AND_RANGE_JOIN',
                'NATIVE_ATTACHMENT_SAME_ORIGIN_FULL_PNG','VERIFIED_PUBLIC_ATTACHMENT_REFERENCE','NATIVE_SEQUENCE_BARRIER',
                'REAL_PUBLIC_RELATIVE_TIME_INTERVAL'},
            'DOM':{'DOM_CURRENT_OWNED_TAB_WINDOW','SCREENSHOT_ORIGINAL_PNG','SOURCE_LABEL_COMPOSITION',
                'FINITE_POSITIVE_IN_VIEWPORT','CASE20_RELATIVE_HOST_SCREENSHOT_JOIN','CASE20_VIEWPORT_ORDER'}}
        require(set().union(*implemented.values())==set(UIZB_RELATION_IDS),'UIZB_EVERY_FINAL_RELATION_ID_HAS_FINITE_TYPED_IMPLEMENTATION')
        require(len(self.windows)==len(self.events)==20 and len(self.frames)==42 and self.native is not None
            and len(self.DOM_rows)>0 and self.DOM_value['window']==self.window_descriptor
            and self.browser_descriptor['bytes']>0,'UIZB_ACTUAL_COMPLETE_NATIVE_WINDOW_DOM_BROWSER_CHAIN')
        # Validate final aggregate shapes after all cross-record/source/native facts are independently joined.
        self.schema_match(self.window_value,{'$ref':'#/schemas/business_window'})
        self.schema_match(self.DOM_value,{'$ref':'#/schemas/DOM_final'})
        for frame in self.frames:
            self.schema_match(frame,{'$ref':'#/schemas/observer_stdout'})
        require(self.rule_seen<=set(UIZB_RELATION_IDS),'UIZB_NO_UNKNOWN_SCHEMA_SIBLING_RELATION')


def ui_business_precondition(context_file,expected_binding):
    global UIZB_FIRST_READ_SET
    require(type(UI_AUX_SOURCE_PIN) is dict,'UIZB_FINAL_AUXILIARY_MANIFEST_NOT_ADOPTED')
    consumer=UiZeroBusinessConsumer(context_file,expected_binding)
    consumer.contract_rules()
    require(consumer.png(consumer.fixture)=={'width':96,'height':96},'UIZB_SOURCE_PNG_ALL_CRC_PIXELS_AND_IHDR')
    consumer.browser()
    consumer.window()
    if 'task2_observer_source' in consumer.frozen:
        Task2PublicConsumer(consumer.context,consumer.binding,consumer.data,
            lambda path,maximum:consumer.read(path,maximum,True),
            lambda value:uizb_canonical(value).encode('utf8')).consume(consumer.baseline,consumer.owned)
    for event in consumer.events:
        role='customer' if event['role']=='public' else event['role']
        require(event['tab_id_sha256']==consumer.owned[role],'UIZB_ALL20_PHASES_ACTUAL_OWNED_ROLE_TAB')
    consumer.DOM()
    consumer.final_relations()
    consumer.reread()
    if UIZB_FIRST_READ_SET is None:
        UIZB_FIRST_READ_SET=dict(consumer.seen)
    else:
        require(UIZB_FIRST_READ_SET==consumer.seen,'UIZB_SECOND_PASS_COMPLETE_SOURCE_AND_EVIDENCE_CLOSURE_IDENTICAL')
    return {'binding':consumer.binding,'window':consumer.window_descriptor,'DOM':consumer.DOM_descriptor,
        'browser_close':consumer.browser_descriptor,'auxiliary_sources':consumer.aux_descriptor,'observer_native':consumer.native}


def ui_main(context_file, close_result_sha256):
    require(type(close_result_sha256) is str and re.fullmatch('[a-f0-9]{64}',close_result_sha256), 'UI_ZERO_ROOT_ACTUAL_CLOSE_RESULT_SHA_REQUIRED')
    context_raw = ui_zero_file(context_file)
    context = json.loads(context_raw)
    root = Path(context['run_dir'])
    e = root/'evidence'
    require(Path(context_file).resolve() == (root/'actual-run-context.json').resolve()
        and re.fullmatch('[0-9]{14}[a-f0-9]{6}',context['run_id'])
        and context['project'] == 'tianli-audit-'+context['run_id'] and context['prep_dir'] == ARTIFACTS.as_posix(),
        'UI_ZERO_SINGLE_ORIGINAL_CONTEXT')
    ui_fixed_sources(context)
    expected,created = ui_zero_targets(context)
    historical = ui_zero_historical(context,expected)
    ui_zero_predecessor_keep(context)
    ui_zero_copy_history(context)
    backend_cid = next(cid for cid,role in expected.items() if role == UI_WEB_BACKEND['role'])
    gateway_cid = next(cid for cid,role in expected.items() if role == UI_WEB_GATEWAY['role'])
    ui_zero_backend_binding(context,created[backend_cid])
    ui_zero_web_history(context,expected)
    ready = load(e/'ui-web-control-ui-backend-ready.json')
    ui_web_control_shape(context,'ui-backend-ready.json',ready)
    frozen = load(root/'preparation-source-binding.json')
    binding_subset = {'run_id':context['run_id'],'source_sha':context['source_sha'],'freeze_sha256':context['freeze_sha256'],
        'context_sha256':sha(context_raw),'backend_cid':backend_cid,'gateway_cid':gateway_cid,
        'ready_sha256':sha(ui_zero_file(e/'ui-web-control-ui-backend-ready.json')),'origin':frozen['ui_web_origin']}
    business = ui_business_precondition(context_file,binding_subset)
    # The explicit gate above currently raises. No accepted business value or result is manufactured.
    result_raw = ui_zero_file(e/'ui-normal-close-result.json')
    result = json.loads(result_raw)
    result_keys = {'schema','scope','status','run_id','source_sha','freeze_sha256','context_sha256','host_source_sha256',
        'close_source_sha256','original_expected','resources','operations','preservation','business','parent','final_inventory',
        'final_global','final_port','full_acceptance','stopped_samples'}
    require(sha(result_raw) == close_result_sha256 and type(result) is dict and set(result) == result_keys
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == 'MS4_UI_NORMAL_CLOSE_ONLY'
        and result['status'] == 'VERIFIED_MS4_UI_NORMAL_CLOSE_ZERO_RESIDUAL'
        and all(result[k] == context[k] for k in ['run_id','source_sha','freeze_sha256']) and result['context_sha256'] == sha(context_raw)
        and result['host_source_sha256'] == UI_HOST_SHA256 and result['close_source_sha256'] == UI_CLOSE_SHA256
        and result['original_expected'] == expected and result['resources'] == {'containers':5,'networks':2,'volumes':10}
        and result['business'] == business and result['full_acceptance'] == 'INCOMPLETE' and result['stopped_samples'] == 'NOT RUN',
        'UI_ZERO_COMPLETE_SOURCE_BOUND_CLOSE_RESULT20')
    attempt = load(e/'ui-normal-close-attempt.json')
    require(type(attempt) is dict and set(attempt) == {'schema','scope','run_id','source_sha','freeze_sha256','context_sha256',
        'host_source_sha256','close_source_sha256','original_expected','operations_started_at_receipt_time'}
        and attempt['schema'] == 1 and attempt['scope'] == result['scope']
        and all(attempt[k] == result[k] for k in ['run_id','source_sha','freeze_sha256','context_sha256','host_source_sha256',
            'close_source_sha256','original_expected']) and attempt['operations_started_at_receipt_time'] is False,
        'UI_ZERO_ORIGINAL_CREATE_ONLY_CLOSE_ATTEMPT')
    before = load(e/'ui-normal-close-before.json')
    require(type(before) is dict and set(before) == {'expected','created','networks','volumes','historical_absent','preservation','business'}
        and before['expected'] == expected and paid_equal(before['created'],created)
        and before['historical_absent'] == historical and before['preservation'] == result['preservation']
        and before['business'] == business and paid_equal(before['volumes'],ui_zero_volumes(context)), 'UI_ZERO_COMPLETE_CLOSE_BEFORE_SNAPSHOT')
    ui_zero_resource_networks(context,before['networks'],expected)
    ui_zero_preservation(context,result['preservation'])
    ui_zero_parent(context,result['parent'])
    stopped = load(e/'ui-normal-close-all-stopped.json')
    require(type(stopped) is dict and set(stopped) == {'containers','resources'} and set(stopped['containers']) == set(expected),
        'UI_ZERO_ALL_FIVE_ORIGINAL_NATIVE_TERMINALS')
    for cid,role in expected.items():
        facts = stopped['containers'][cid]
        require(set(facts) == set(UI_WEB_NATIVE_KEYS), 'UI_ZERO_COMPLETE_NATIVE_TERMINAL26')
        if role in [UI_WEB_BACKEND['role'],UI_WEB_GATEWAY['role']]:
            ui_web_owned(context,facts,role)
        else:
            owned(context,facts,role)
        same_created(created[cid],facts)
        normal(facts,cid,context,role)
        if role in [PAID['role'],UI_WEB_BACKEND['role']]:
            same_facts(facts,load(e/('paid-producer-terminal.json' if role == PAID['role'] else 'ui-backend-terminal.json')))
    ui_zero_resources(context,stopped['resources'],expected,historical)
    ui_zero_resources(context,load(e/'ui-normal-close-before-network-volume-rm.json'),{},historical,True)
    before_volumes = load(e/'ui-normal-close-before-volume-rm.json')
    require(type(before_volumes) is dict and set(before_volumes) == {'inventory','volumes','global'}
        and paid_equal(before_volumes['volumes'],ui_zero_volumes(context)), 'UI_ZERO_EXACT_ELEVEN_BEFORE_VOLUME_REMOVAL')
    ui_zero_inventory(context,before_volumes['inventory'],{},False,True)
    ui_zero_global_archive(context,before_volumes['global'],expected,set(),historical)
    labels = ['ui-close-backend-wait','ui-close-stop-gateway','ui-close-stop-mysql','ui-close-stop-redis',
        'ui-close-rm-containers','ui-close-rm-internal','ui-close-rm-frontend','ui-close-rm-volumes']
    operations = result['operations']
    require(type(operations) is list and [op['label'] for op in operations] == labels, 'UI_ZERO_EXACT_ORDERED_EIGHT_NORMAL_OPERATIONS')
    frontend = ui_zero_network_identity(context)
    stop_roles = [UI_WEB_GATEWAY['role'],'audit-mysql','audit-redis']
    argv = [['docker','wait',backend_cid]]+[
        ['docker','stop','--timeout','-1',next(cid for cid,role in expected.items() if role == wanted)] for wanted in stop_roles]+[
        ['docker','rm',*sorted(expected)],['docker','network','rm',context['network_id']],
        ['docker','network','rm',frontend['Id']],['docker','volume','rm',*sorted(r['name'] for r in ui_zero_volumes(context))]]
    for index,(op,command) in enumerate(zip(operations,argv)):
        raw = ui_zero_operation(context,op,command)
        if index == 0:
            require(raw == b'0\n', 'UI_ZERO_BACKEND_ACTUAL_NATIVE_WAIT_ZERO')
        elif 1 <= index <= 3:
            cid = command[-1]
            require(raw == (cid+'\n').encode(),
                'UI_ZERO_ORDINARY_SAME_CID_STOP_RAW')
        else:
            names = command[2:] if index == 4 else command[3:]
            require(raw == ('\n'.join(names)+'\n').encode(), 'UI_ZERO_EXACT_NONFORCE_REMOVAL_RAW')
    ui_zero_inventory(context,result['final_inventory'],{},False,False)
    ui_zero_global_archive(context,result['final_global'],expected,set(),historical)
    ui_zero_port_archive(context,result['final_port'])
    global_before = ui_zero_global(context,expected,historical)
    queries = []
    for kind,args in [('container',['ps','-aq','--no-trunc']),('network',['network','ls','-q']),('volume',['volume','ls','-q'])]:
        for label,value in [('project','com.docker.compose.project='+context['project']),('run','tianli.audit.run='+context['run_id'])]:
            command = ['docker',*args,'--filter','label='+value]
            raw,native,projection = ui_zero_native(context,'ui-zero-'+kind+'-'+label,command)
            require(raw == b'', 'UI_ZERO_FRESH_QUERY_NATIVE_ZERO_EMPTY_RAW')
            queries.append({'kind':kind,'label':label,'native':native,'projection':projection})
    require(len(queries) == 6, 'UI_ZERO_SIX_FRESH_PROJECT_RUN_QUERIES')
    global_after = ui_zero_global(context,expected,historical)
    port = ui_zero_port(context)
    ui_fixed_sources(context)
    ui_zero_predecessor_keep(context)
    ui_zero_copy_history(context)
    ui_zero_preservation(context,result['preservation'])
    require(ui_zero_file(e/'ui-normal-close-result.json') == result_raw
        and ui_business_precondition(context_file,binding_subset) == business
        and ui_zero_historical(context,expected) == historical, 'UI_ZERO_COMPLETE_ORIGINAL_PROOFS_UNCHANGED_AFTER_QUERIES')
    output = {'schema':1,'scope':'MS4_UI_NORMAL_CLOSE_INDEPENDENT_ZERO_ONLY','status':'VERIFIED_MS4_UI_INDEPENDENT_SIX_QUERY_ZERO',
        'run_id':context['run_id'],'source_sha':context['source_sha'],'freeze_sha256':context['freeze_sha256'],
        'context_sha256':sha(context_raw),'close_result_sha256':close_result_sha256,'host_source_sha256':UI_HOST_SHA256,
        'close_source_sha256':UI_CLOSE_SHA256,'zero_source_sha256':sha(ui_zero_file(Path(__file__))),'queries':queries,
        'global':{'before':global_before,'after':global_after},'port':port,'business':business,
        'native_exit':0,'full_acceptance':'INCOMPLETE','stopped_samples':'NOT RUN'}
    new(e/'ui-independent-zero.json',output)
    print(json.dumps(output,ensure_ascii=False))



def ui_zero_tree(folder):
    folder = Path(folder)
    require(folder.is_dir() and not folder.is_symlink() and not getattr(folder,'is_junction',lambda:False)(), 'UI_ZERO_WHOLE_TREE_ROOT')
    files = []
    directories = []
    def walk(directory):
        before = directory.lstat()
        entries = sorted(directory.iterdir(),key=lambda p:p.name)
        names = [entry.name for entry in entries]
        require(len(names) == len(set(names)), 'UI_ZERO_COMPLETE_UNIQUE_DIRECTORY_ENUMERATION')
        for entry in entries:
            require(not entry.is_symlink() and not getattr(entry,'is_junction',lambda:False)(), 'UI_ZERO_TREE_NO_LINK_OR_REPARSE')
            relative = entry.relative_to(folder).as_posix()
            require(not re.search(r'(^/|\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)',relative), 'UI_ZERO_TREE_CANONICAL_MEMBER')
            if entry.is_file():
                raw = ui_zero_file(entry)
                files.append({'path':relative,'bytes':len(raw),'sha256':sha(raw)})
            elif entry.is_dir():
                directories.append(relative)
                walk(entry)
            else:
                raise RuntimeError('UI_ZERO_TREE_ONLY_ORDINARY_FILES_DIRECTORIES')
        after_names = sorted(entry.name for entry in directory.iterdir())
        after = directory.lstat()
        require(after_names == names and all(getattr(before,key) == getattr(after,key)
            for key in ['st_dev','st_ino','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns']),
            'UI_ZERO_DIRECTORY_COMPLETE_STABLE_INVENTORY')
    walk(folder)
    return {'files':sorted(files,key=lambda r:r['path']),'directories':sorted(directories)}


def ui_zero_whole_sources(context, frozen):
    repo = Path(context['repo_dir'])
    paths = set()
    for relative in SOURCE_ROOTS:
        for row in ui_zero_tree(repo/relative)['files']:
            paths.add(relative+'/'+row['path'])
    group_extras = [('customer',sorted(SOURCE_EXTRA)),('paid',sorted(SOURCE_EXTRA+[PAID_ENTRY])),
        ('fulfillment',fulfillment_extra()),('aftersale_review',aftersale_review_extra()),('worker',worker_extra()),
        ('ui_prepare',ui_prepare_extra()),('ui_backend',ui_backend_extra())]
    for group,extras in group_extras:
        rows = frozen[group+'_source_files']
        require(frozen[group+'_source_roots'] == SOURCE_ROOTS and frozen[group+'_source_extra'] == extras
            and [row['path'] for row in rows] == sorted(paths|set(extras)), 'UI_ZERO_COMPLETE_STRICT_SOURCE_GROUP_MEMBERSHIP')
        for row in rows:
            raw = ui_zero_file(repo/row['path'])
            require(type(row) is dict and set(row) == {'path','bytes','sha256'}
                and type(row['bytes']) is int and len(raw) == row['bytes'] and sha(raw) == row['sha256'],
                'UI_ZERO_STRICT_SOURCE_GROUP_BYTES')
    artifact_tree = ui_zero_tree(ARTIFACTS)
    require([row['path'] for row in artifact_tree['files']] == sorted(UI_COPY_ARTIFACTS)
        and artifact_tree['directories'] == ['manifests'], 'UI_ZERO_STRICT_COMPLETE_REAL_SEVENTEEN_ARTIFACT_TREE')
    profile = load(ARTIFACTS/'manifests/ui-public-copy-profile.json')
    public = ui_zero_tree(repo/'crmeb/public')
    require(public == {'files':profile['public_source_files'],'directories':profile['public_source_directories']},
        'UI_ZERO_COMPLETE_ORIGINAL_PUBLIC_AND_EMPTY_DIRECTORIES')
    for name in ['h5','merchant','admin']:
        tree = ui_zero_tree(Path(frozen['ui_copy_dist_sources'][name]))
        require(tree == frozen['ui_copy_dist_inventory'][name]
            and tree == load(ARTIFACTS/('manifests/ui-'+name+'-dist.json'))
            and tree == {'files':profile[name+'_dist_files'],'directories':profile[name+'_dist_directories']},
            'UI_ZERO_COMPLETE_STRICT_REAL_BUILD_BYTES_AND_EMPTY_DIRECTORIES')


def ui_zero_registration(context, proof, role, scope):
    e = Path(context['run_dir'])/'evidence'
    created = ui_web_created(context,proof) if role == UI_WEB_REGISTER['role'] else bound_created(context,proof,role)
    terminal = load(e/(proof+'-terminal.json'))
    if role == UI_WEB_REGISTER['role']:
        ui_web_owned(context,created,role,False)
        ui_web_owned(context,terminal,role,False)
    else:
        owned(context,terminal,role)
    same_created(created,terminal)
    normal(terminal,created['id'],context,role)
    require(created['command'] == ['/run/ms4-prep/ms4-initialize.php','register'], 'UI_ZERO_ORIGINAL_REGISTER_SOURCE_COMMAND')
    result = load(e/(proof+'-result.json'))
    require(type(result) is dict and set(result) == {'schema','scope','status','run_id','source_sha','children','private_cleanup',
        'private_cleanup_verified','App_HTTP_backfill_enforce_payment','MS4_migration','acceptance','binding','native_exit'}
        and type(result['schema']) is int and result['schema'] == 1 and result['scope'] == scope
        and result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY'
        and all(result[k] == context[k] for k in ['run_id','source_sha']) and type(result['native_exit']) is int
        and result['native_exit'] == 0 and result['children'] == [] and result['private_cleanup'] == []
        and result['private_cleanup_verified'] is False
        and all(result[k] == 'NOT RUN' for k in ['App_HTTP_backfill_enforce_payment','MS4_migration','acceptance']),
        'UI_ZERO_ORIGINAL_REGISTER_RESULT13')
    binding = result['binding']
    require(type(binding) is dict and set(binding) == {'container_id','entry_namespace_pid','source_sha','created_sha256','freeze_sha256'}
        and binding['container_id'] == created['id'] and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
        and all(binding[k] == context[k] for k in ['source_sha','freeze_sha256'])
        and binding['created_sha256'] == sha(ui_zero_file(e/(proof+'-created.json'))), 'UI_ZERO_REGISTER_EXACT_BINDING5')
    shutdown = load(e/(proof+'-shutdown.json'))
    require(type(shutdown) is dict and set(shutdown) == {'schema','phase','fatal','type','file','line','primary_failure','cleanup_failure','memory_limit','peak_bytes'}
        and type(shutdown['schema']) is int and shutdown['schema'] == 1 and shutdown['phase'] == 'register'
        and shutdown['fatal'] is False and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None
        and type(shutdown['type']) is int and type(shutdown['line']) is int and type(shutdown['file']) is str
        and type(shutdown['memory_limit']) is str and type(shutdown['peak_bytes']) is int and shutdown['peak_bytes'] > 0,
        'UI_ZERO_REGISTER_COMPLETE_NONFATAL_SHUTDOWN10')
    if role == UI_WEB_REGISTER['role']:
        removed = load(e/'ui-web-register-removed.json')
        require(type(removed) is dict and set(removed) == {'schema','scope','run_id','source_sha','container_id','created_sha256',
            'terminal_sha256','native','projection'} and removed['schema'] == 1 and removed['scope'] == 'MS4_UI_WEB_REGISTER_NORMAL_REMOVAL'
            and all(removed[k] == context[k] for k in ['run_id','source_sha']) and removed['container_id'] == created['id']
            and removed['created_sha256'] == binding['created_sha256']
            and removed['terminal_sha256'] == sha(ui_zero_file(e/(proof+'-terminal.json'))), 'UI_ZERO_REGISTER_WEB_RETIRE_BINDING')
        raw = ui_zero_operation(context,{'label':removed['native']['label'],'native':removed['native'],'projection':removed['projection']},
            ['docker','rm',created['id']])
    else:
        receipts = [load(path) for path in e.glob('copy-native-*.terminal.json')
            if load(path).get('label') == 'copy-register-exact-nonforce-remove']
        require(len(receipts) == 1, 'UI_ZERO_ONE_COPY_REGISTER_NORMAL_REMOVE')
        raw = ui_zero_full_native(context,receipts[0],['docker','rm',created['id']])
    require(raw == (created['id']+'\n').encode(), 'UI_ZERO_REGISTER_EXACT_NONFORCE_REMOVE_RAW')
    return result


def ui_zero_web_history(context, expected):
    e = Path(context['run_dir'])/'evidence'
    ui_zero_registration(context,'register-ui-public-copy','audit-bootstrap','MS4_UI_PUBLIC_COPY_LIFECYCLE_ONLY')
    ui_zero_registration(context,UI_WEB_REGISTER['proof'],UI_WEB_REGISTER['role'],'MS4_UI_WEB_LIFECYCLE_ONLY')
    frozen = load(Path(context['run_dir'])/'preparation-source-binding.json')
    formal = load(Path(context['run_dir'])/'manifest.json')
    prior = load(e/'ui-prepare-formal-manifest.json')
    require(set(formal['services']) == set(expected.values())
        and all(formal[k] == prior[k] for k in ['run_id','commit_sha','project','isolation','guard_mode','network_internal','network'])
        and all(formal['services'][role] == prior['services'][role] for role in [PAID['role'],'audit-mysql','audit-redis']),
        'UI_ZERO_CURRENT_FORMAL_RETAINS_ORIGINAL_PAID_AND_DATABASES')
    for cid,role in expected.items():
        require(formal['services'][role]['id'] == cid and formal['services'][role]['image_id']
            == (UI_WEB_NGINX if role == UI_WEB_GATEWAY['role'] else MYSQL if role == 'audit-mysql' else REDIS if role == 'audit-redis' else PHP),
            'UI_ZERO_FORMAL_EXACT_FIVE_ROLE_ID_IMAGE')
    volumes = ui_zero_volumes(context)
    require(sorted(formal['volumes']) == sorted(row['name'] for row in volumes)
        and formal['volume_identities'] == {row['name']:row['created_at'] for row in volumes}, 'UI_ZERO_FORMAL_COMPLETE_TWELVE_VOLUME_IDENTITIES')
    backend = ui_web_created(context,'ui-backend')
    current = load(e/'ui-backend-current.json')
    require(type(current) is dict and set(current) == set(UI_WEB_CURRENT_KEYS) and current['schema'] == 1
        and current['scope'] == 'MS4_UI_BACKEND_CURRENT_NATIVE'
        and all(current[k] == context[k] for k in ['run_id','source_sha','freeze_sha256']) and current['container_id'] == backend['id']
        and current['native_created_sha256'] == sha(ui_zero_file(e/'ui-backend-created.json'))
        and current['source_binding_sha256'] == sha(ui_zero_file(e/UI_WEB_BACKEND['binding'])), 'UI_ZERO_BACKEND_ORIGINAL_CURRENT10')
    raw = ui_zero_operation(context,{'label':current['native']['label'],'native':current['native'],'projection':current['projection']},
        ['docker','inspect','--type','container','--format',INSPECT,backend['id']])
    value = json.loads(raw)
    ui_web_owned(context,value,UI_WEB_BACKEND['role'])
    same_created(backend,value)
    require(value['status'] == 'running' and value['pid'] > 0, 'UI_ZERO_ORIGINAL_BACKEND_ACTUAL_RUNNING_PROOF')
    gateway = ui_web_created(context,'ui-gateway')
    current = load(e/'ui-gateway-current.json')
    require(type(current) is dict and set(current) == {'schema','scope','run_id','source_sha','freeze_sha256','container_id',
        'native_created_sha256','projection','native','port'} and current['schema'] == 1
        and current['scope'] == 'MS4_UI_GATEWAY_CURRENT_NATIVE'
        and all(current[k] == context[k] for k in ['run_id','source_sha','freeze_sha256'])
        and current['container_id'] == gateway['id'] and current['native_created_sha256'] == sha(ui_zero_file(e/'ui-gateway-created.json')),
        'UI_ZERO_GATEWAY_ORIGINAL_CURRENT10')
    raw = ui_zero_operation(context,{'label':current['native']['label'],'native':current['native'],'projection':current['projection']},
        ['docker','inspect','--type','container','--format',INSPECT,gateway['id']])
    value = json.loads(raw)
    ui_web_owned(context,value,UI_WEB_GATEWAY['role'])
    same_created(gateway,value)
    require(value['status'] == 'running' and value['pid'] > 0
        and value['networks'][context['project']+'_frontend']['NetworkID'] == ui_zero_network_identity(context)['Id'],
        'UI_ZERO_REAL_GATEWAY_RUNNING_BOTH_ORIGINAL_NETWORKS')
    port = current['port']
    require(type(port) is dict and set(port) == {'port','origin','rows','native','projection'}
        and port['port'] == frozen['ui_web_port'] and port['origin'] == frozen['ui_web_origin']
        and type(port['rows']) is list and port['rows'] and all(row['LocalAddress'] == '127.0.0.1' for row in port['rows']),
        'UI_ZERO_ORIGINAL_GATEWAY_LOOPBACK_PORT_PROOF')
    raw = ui_zero_operation(context,{'label':port['native']['label'],'native':port['native'],'projection':port['projection']},
        ui_web_port_argv(frozen['ui_web_port']))
    require(ui_web_port_rows(raw,frozen['ui_web_port']) == port['rows'], 'UI_ZERO_ORIGINAL_OS_PORT_FULL_RAW_ROWS')

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


# Independent Task2 Source/copy readback only: no Host import and no live exec.
T2Z_NAMES = ['entry.php', 'expected.php', 'gate.php']
T2Z_COPY = 'evidence/task2-observer-copy.json'


def t2z_stat(value):
    keys = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    result = {key: getattr(value, key) for key in keys}
    require(all(type(item) is int for item in result.values()), 'T2Z_FULL_NATIVE_STAT9')
    return result


def t2z_path(path, folder=False):
    p = Path(path); require(p.is_absolute(), 'T2Z_ABSOLUTE_SOURCE_OR_COPY_PATH')
    for part in [p, *p.parents]:
        require(not part.is_symlink() and not (getattr(part.lstat(), 'st_file_attributes', 0) & 1024), 'T2Z_NO_LINKS_OR_REPARSE')
    st = p.lstat()
    require((st.st_mode & 0o170000) == (0o040000 if folder else 0o100000)
        and (folder or st.st_nlink == 1), 'T2Z_ORDINARY_DIRECTORY_OR_SINGLE_LINK_FILE')
    return t2z_stat(st)


def t2z_read(path):
    p = Path(path); before = t2z_path(p)
    with p.open('rb') as f:
        require(t2z_stat(os.fstat(f.fileno())) == before, 'T2Z_SAME_OPEN_PATH_HANDLE')
        raw = f.read()
        require(len(raw) == before['st_size'] and f.read(1) == b''
            and t2z_stat(os.fstat(f.fileno())) == before, 'T2Z_COMPLETE_EOF_AND_HANDLE_STAT')
    require(t2z_path(p) == before, 'T2Z_PATH_UNCHANGED_AFTER_CLOSE')
    return raw


def t2z_directory(path):
    p = Path(path); before = t2z_path(p, True)
    require(sorted(item.name for item in p.iterdir()) == T2Z_NAMES, 'T2Z_COMPLETE_THREE_FILE_NAMESPACE')
    data = {name: t2z_read(p / name) for name in T2Z_NAMES}
    require(t2z_path(p, True) == before and sorted(item.name for item in p.iterdir()) == T2Z_NAMES,
        'T2Z_SOURCE_DIRECTORY_STAT_AND_NAMESPACE_STABLE')
    return data


def t2z_canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode('utf-8')


TASK2_BROWSER_NAMES = ('reader/ms4-ui-private-reader.mjs', 'ms4-ui-private-caller.mjs',
    'ms4-ui-browser-close.mjs', 'ms4-ui-business-producer.mjs', 'ms4-ui-window-native-bridge.mjs',
    'ms4-ui-network-capture.mjs', 'ms4-ui-dom-producer.mjs', 'ui-business-consumer-contract.json')
TASK2_BROWSER_MANIFEST_PIN = {'bytes': 1222, 'sha256': '83b9267b2759b9bf8b120e734f2c4f595ce01f08743c96ed93ada98e403bda7d'}


def task2_browser_actual():
    root = ARTIFACTS.parent / 'browser-v1'; start = t2z_path(root, True)
    expect(root.resolve(strict=True) == root, 'TASK2_BROWSER_CANONICAL_SOURCE_ROOT')
    def names():
        files = []; directories = []
        for path in root.rglob('*'):
            expect(not path.is_symlink() and not getattr(path, 'is_junction', lambda: False)()
                and (path.is_file() or path.is_dir()), 'TASK2_BROWSER_COMPLETE_REGULAR_NAMESPACE')
            relative = path.relative_to(root).as_posix()
            if path.is_dir():
                t2z_path(path, True); directories.append(relative)
            else:
                t2z_path(path); files.append(relative)
        expect(sorted(files) == sorted([*TASK2_BROWSER_NAMES, 'PINS.json']) and directories == ['reader'],
            'TASK2_BROWSER_ONLY_ORIGINAL_EIGHT_PLUS_MANIFEST_AND_READER_DIRECTORY')
        return sorted(files), sorted(directories)
    before = names(); raw = t2z_read(root / 'PINS.json')
    expect({'bytes': len(raw), 'sha256': sha(raw)} == TASK2_BROWSER_MANIFEST_PIN, 'TASK2_BROWSER_REAL_WHOLE_MANIFEST_PIN')
    manifest = json.loads(raw)
    expect(type(manifest) is dict and set(manifest) == {'members'} and type(manifest['members']) is dict
        and tuple(manifest['members']) == TASK2_BROWSER_NAMES, 'TASK2_BROWSER_EXACT_EIGHT_EXISTING_ORDER')
    members = []
    for name in TASK2_BROWSER_NAMES:
        row = manifest['members'][name]; value = t2z_read(root / name)
        expect(type(row) is dict and set(row) == {'bytes', 'sha256'} and type(row['bytes']) is int and row['bytes'] > 0
            and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None
            and row == {'bytes': len(value), 'sha256': sha(value)}, 'TASK2_BROWSER_EVERY_REAL_COMPLETE_SOURCE_MEMBER')
        members.append({'path': name, 'bytes': row['bytes'], 'sha256': row['sha256']})
    expect(names() == before and t2z_path(root, True) == start
        and t2z_read(root / 'PINS.json') == raw, 'TASK2_BROWSER_FULL_NAMESPACE_MANIFEST_AND_ROOT_STAT_PRESERVED')
    return members


def task2_browser_group(binding):
    if 'task2_observer_source' not in binding:
        expect('task2_browser_source' not in binding, 'TASK2_NO_UNFROZEN_BROWSER_GROUP')
        return None
    value = binding.get('task2_browser_source')
    expect(type(value) is dict and set(value) == {'schema', 'scope', 'members'} and type(value['schema']) is int
        and value['schema'] == 1 and value['scope'] == 'MS5_TASK2_BROWSER_SOURCE_ONLY'
        and type(value['members']) is list and len(value['members']) == 8
        and paid_equal(value['members'], task2_browser_actual()), 'TASK2_COMPLETE_INITIAL_BROWSER_SOURCE3')
    return value



T2Z_BROWSER_OVERLAY = 'evidence/task2-browser-compose.json'
T2Z_BROWSER_TARGET = '/run/ms4-browser-source'


def task2_browser_overlay_zero(context, binding):
    value = task2_browser_group(binding); path = Path(context['run_dir']) / T2Z_BROWSER_OVERLAY
    if value is None:
        require(not path.exists() and not path.is_symlink(), 'T2Z_NO_UNFROZEN_BROWSER_OVERLAY')
        return None
    root = ARTIFACTS.parent / 'browser-v1'
    require(root.resolve(strict=True) == root, 'T2Z_ACTUAL_CANONICAL_SIBLING_BROWSER_SOURCE')
    expected = {'services': {'audit-ms4-ui-backend': {'volumes': [
        {'type': 'bind', 'source': root.as_posix(), 'target': T2Z_BROWSER_TARGET,
         'read_only': True, 'bind': {'create_host_path': False}}]}}}
    raw = t2z_canonical(expected) + b'\n'
    require(t2z_read(path) == raw, 'T2Z_COMPLETE_FROZEN_SOURCE_DERIVED_BROWSER_OVERLAY')
    return path



def task2_observer_zero(context):
    root = Path(context['run_dir']); frozen_raw = t2z_read(root / 'preparation-source-binding.json')
    require(sha(frozen_raw) == context['freeze_sha256'], 'T2Z_ORIGINAL_COMPLETE_FREEZE_BYTES')
    frozen = json.loads(frozen_raw)
    task2_browser_group(frozen)
    task2_browser_overlay_zero(context, frozen)
    if 'task2_observer_source' not in frozen:
        require('task2_observer_copy' not in context, 'T2Z_NO_UNFROZEN_COPY_POINTER')
        return
    group = frozen['task2_observer_source']
    require(type(group) is dict and set(group) == {'schema', 'scope', 'core_source_sha', 'host_source_root', 'container_root', 'members'}
        and type(group['schema']) is int and group['schema'] == 1 and group['scope'] == 'MS5_TASK2_COMPOSITE_OBSERVER_SOURCE_ONLY'
        and type(group['core_source_sha']) is str and re.fullmatch('[a-f0-9]{40}', group['core_source_sha']) is not None
        and group['core_source_sha'] == frozen['source_sha'] == context['source_sha'] and type(group['host_source_root']) is str
        and group['container_root'] == '/run/audit/task2-observer' and type(group['members']) is list and len(group['members']) == 3,
        'T2Z_FULL_TYPED_INITIAL_SOURCE6')
    original_root = Path(group['host_source_root'])
    require(original_root.is_absolute() and original_root.as_posix() == group['host_source_root']
        and not original_root.is_relative_to(Path(frozen['requested_repo_source'])), 'T2Z_EXTERNAL_CANONICAL_SOURCE_ROOT')
    originals = t2z_directory(original_root)
    require(original_root.resolve(strict=True) == original_root, 'T2Z_REAL_SOURCE_PATH')
    copies = t2z_directory(root / 'task2-observer')
    require(copies == originals, 'T2Z_FULL_HOST_ORIGINALS_AND_RUN_COPIES')
    for name, row in zip(T2Z_NAMES, group['members']):
        require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and row['path'] == name
            and type(row['bytes']) is int and row['bytes'] > 0 and type(row['sha256']) is str
            and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None
            and len(originals[name]) == row['bytes'] and sha(originals[name]) == row['sha256'], 'T2Z_EVERY_FROZEN_FULL_TOOL_BYTE')
    descriptor = context.get('task2_observer_copy')
    require(type(descriptor) is dict and set(descriptor) == {'path', 'bytes', 'sha256'} and descriptor['path'] == T2Z_COPY
        and type(descriptor['bytes']) is int and descriptor['bytes'] > 0 and type(descriptor['sha256']) is str
        and re.fullmatch('[a-f0-9]{64}', descriptor['sha256']) is not None, 'T2Z_EXACT_CONTEXT_COPY3')
    raw = t2z_read(root / T2Z_COPY)
    require(descriptor == {'path': T2Z_COPY, 'bytes': len(raw), 'sha256': sha(raw)}, 'T2Z_REAL_ORIGINAL_COPY_RECEIPT_FULL_BYTES')
    actual = json.loads(raw)
    expected = {'schema': 1, 'scope': 'MS5_TASK2_OBSERVER_SOURCE_COPY', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'freeze_sha256': context['freeze_sha256'], 'observer_source_sha256': sha(t2z_canonical(group)),
        'originals': [{'path': (original_root / row['path']).as_posix(), 'bytes': row['bytes'], 'sha256': row['sha256']} for row in group['members']],
        'copies': [{'path': 'task2-observer/' + row['path'], 'bytes': row['bytes'], 'sha256': row['sha256']} for row in group['members']]}
    require(t2z_canonical(actual) == t2z_canonical(expected), 'T2Z_TYPED_COPY8_SAME_RUN_SOURCE_FREEZE_NO_CYCLE')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--context', required=True)
    parser.add_argument('--close-result-sha256', required=True)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--worker-normal', action='store_true', help='Explicit complete non-due Worker independent zero; default remains original normal zero')
    modes.add_argument('--ui-web', action='store_true', help='Explicit independent UI zero; requires the actual complete business/browser Source verifier')
    args = parser.parse_args()
    try:
        if args.ui_web:
            ui_main(args.context, args.close_result_sha256)
        else:
            (worker_main if args.worker_normal else main)(args.context, args.close_result_sha256)
    except Exception as error:
        if args.ui_web:
            value = {'status': 'MS4_UI_INDEPENDENT_ZERO_INCOMPLETE_STATE_PRESERVED', 'class': type(error).__name__,
                'native_exit': 1, 'no_mutation_or_retry': True}
            if str(error) == 'UI_BUSINESS_SOURCE_CONTRACT_NOT_CONNECTED':
                value['missing_source_contract'] = 'UI_BUSINESS_SOURCE_CONTRACT_NOT_CONNECTED'
            print(json.dumps(value))
        else:
            print(json.dumps({'status': 'MS4_WORKER_NORMAL_INDEPENDENT_ZERO_INCOMPLETE_STATE_PRESERVED' if args.worker_normal else 'MS4_AFTERSALE_REVIEW_INDEPENDENT_ZERO_INCOMPLETE_STATE_PRESERVED', 'class': type(error).__name__,
                'native_exit': 1, 'no_mutation_or_retry': True}))
        raise SystemExit(1)
