"""Finite byte transformation of the archived nonempty-history Host R3.

This internal author module has no CLI, filesystem reads or Host execution.
Runtime admission requires the independently reviewed final publication and
real external source bindings.  This module supplies no review or runtime result.
"""
from __future__ import annotations

import ast
import hashlib


BASE_BYTES = 112398
BASE_SHA256 = "5b041cbb071353cf28186aad73d8fdeebd33b3919f467a3afb42569776af5066"

_ARTIFACTS = """ARTIFACTS = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php', 'seed-source-inverse.json',
             'legacy-app.php', 'legacy-files-bootstrap.php', 'legacy-normal-config.php',
             'legacy-actors.php', 'legacy-history-http.php', 'legacy-history-channel.php',
             'legacy-history-producer.php', 'legacy-history-router.php']"""

_HELPERS = r'''def history_source_bytes(path):
    path = Path(path)
    expect(path.is_absolute() and '..' not in path.parts, 'HISTORY_ABSOLUTE_SOURCE_POINTER')
    for item in (path, *path.parents):
        info = item.lstat()
        expect(not stat.S_ISLNK(info.st_mode)
               and not (getattr(info, 'st_file_attributes', 0) & 1024), 'HISTORY_NO_SOURCE_LINK_OR_REPARSE')
    expect(path.resolve(strict=True).as_posix() == path.as_posix(), 'HISTORY_CANONICAL_SOURCE_POINTER')
    before = path.stat()
    expect(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size > 0,
           'HISTORY_REGULAR_SINGLE_LINK_NONEMPTY_SOURCE')
    fields = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    raw = path.read_bytes(); after = path.stat()
    expect(all(getattr(before, key) == getattr(after, key) for key in fields)
           and len(raw) == before.st_size, 'HISTORY_SOURCE_FULL_READ_WINDOW_HELD')
    return raw, {'path': path.as_posix(), 'bytes': len(raw), 'sha256': digest(raw)}


def history_source_json(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            expect(key not in value, 'HISTORY_UNIQUE_JSON_KEYS')
            value[key] = item
        return value
    return json.loads(raw, object_pairs_hook=pairs)


def history_source_pointer(row):
    expect(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
           and type(row['path']) is str and Path(row['path']).is_absolute()
           and '..' not in Path(row['path']).parts and Path(row['path']).as_posix() == row['path']
           and type(row['bytes']) is int and row['bytes'] > 0
           and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None,
           'HISTORY_EXACT_ABSOLUTE_SOURCE_DESCRIPTOR')


def history_source_equal(left, right):
    """Strict JSON value types; object member order is not a new contract."""
    if type(left) is not type(right):
        return False
    if type(left) is dict:
        return set(left) == set(right) and all(history_source_equal(left[key], right[key]) for key in left)
    if type(left) is list:
        return len(left) == len(right) and all(history_source_equal(a, b) for a, b in zip(left, right))
    return left == right


def history_source_projection(artifacts):
    expect(len(PRODUCER_ARTIFACTS) == 13 and len(set(PRODUCER_ARTIFACTS)) == 13
           and sum(name.endswith('.php') for name in PRODUCER_ARTIFACTS) == 9
           and ARTIFACTS == PRODUCER_ARTIFACTS + ['legacy-current-reader.php'],
           'HISTORY_FIXED_ORIGINAL13_PLUS_ONE_READER')
    expect(type(artifacts) is list and len(artifacts) == 14
           and all(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}
                   and type(row['path']) is str and type(row['bytes']) is int and row['bytes'] > 0
                   and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None
                   for row in artifacts)
           and [row['path'] for row in artifacts] == ARTIFACTS, 'HISTORY_COMPLETE_ORDERED_ARTIFACT14')
    producer = [row for row in artifacts if row['path'] != 'legacy-current-reader.php']
    expect([row['path'] for row in producer] == PRODUCER_ARTIFACTS
           and artifacts == producer + [artifacts[-1]], 'HISTORY_EXACT_OLD13_PROJECTION_NOT_ARBITRARY_SUBSET')
    return producer


def history_source_review(repo, source, review_file, review_sha256, artifacts, controls):
    global LEGACY_HANDOFF_BINDINGS
    expect(type(source) is str and re.fullmatch('[a-f0-9]{40}', source) is not None
           and type(review_sha256) is str and re.fullmatch('[a-f0-9]{64}', review_sha256) is not None,
           'HISTORY_EXPLICIT_SOURCE_AND_REVIEW_SHA256')
    producer = history_source_projection(artifacts)
    review_raw, review_pin = history_source_bytes(review_file)
    expect(review_pin['sha256'] == review_sha256, 'HISTORY_FIXED_INDEPENDENT_REVIEW_BYTES')
    review = history_source_json(review_raw)
    expect(type(review) is dict and set(review) == {'schema', 'status', 'source_sha', 'publication', 'bindings'}
           and type(review['schema']) is int and review['schema'] == 1
           and review['status'] == 'PASS_CURRENT_HISTORY_SOURCE_REVIEW'
           and review['source_sha'] == source, 'HISTORY_EXACT_INDEPENDENT_REVIEW_CONTRACT')
    history_source_pointer(review['publication'])
    publication_raw, publication_pin = history_source_bytes(review['publication']['path'])
    expect(publication_pin == review['publication'], 'HISTORY_REVIEWED_PUBLICATION_FULL_BYTES')
    publication = history_source_json(publication_raw)
    expect(type(publication) is dict and set(publication) == {'schema', 'scope', 'source_sha', 'artifact_files'}
           and type(publication['schema']) is int and publication['schema'] == 1
           and publication['scope'] == 'CURRENT_HISTORY_SOURCE_PUBLICATION'
           and publication['source_sha'] == source and history_source_equal(publication['artifact_files'], artifacts),
           'HISTORY_PUBLICATION_CURRENT_SOURCE_AND_COMPLETE_ARTIFACT14')
    names = {'producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer'}
    bindings = review['bindings']
    expect(type(bindings) is dict and set(bindings) == names
           and all(value is not None for value in bindings.values()), 'HISTORY_SEVEN_REAL_EXTERNAL_BINDINGS')
    expect(type(controls) is list and len(controls) == len(LEGACY_CONTROL) == 11
           and history_source_equal(bindings['control_files'], controls), 'HISTORY_ORIGINAL_EXACT_CONTROL11_BINDING')
    expect(history_source_equal(bindings['artifacts'], [row for row in producer if row['path'] != 'ms4-host.py'])
           and len(bindings['artifacts']) == 12, 'HISTORY_EXACT_PRODUCER_NONSELF12_BINDING')
    indexed = {row['path']: row for row in artifacts}
    for name, relative in [('producer', 'legacy-history-producer.php'), ('library', 'legacy-app.php')]:
        expect(history_source_equal(bindings[name], indexed[relative]), 'HISTORY_FINAL_' + name.upper() + '_RELATIVE_SOURCE')
    for name, relative in [('startup', 'legacy-history-producer.php'),
                           ('reader', 'legacy-current-reader.php'), ('consumer', 'ms4-host.py')]:
        row = bindings[name]; history_source_pointer(row)
        expect(row == {**indexed[relative], 'path': (PREP / relative).as_posix()},
               'HISTORY_FIXED_' + name.upper() + '_IMPLEMENTATION_NOT_ARBITRARY_FILE')
        _, actual = history_source_bytes(row['path'])
        expect(actual == row, 'HISTORY_FINAL_' + name.upper() + '_FULL_BYTES')
    expect([source_row(PREP, name) for name in ARTIFACTS] == artifacts,
           'HISTORY_COMPLETE_ACTUAL_ARTIFACT_BYTES_HELD')
    current_controls = []
    for kind, relative, destination in LEGACY_CONTROL:
        current_controls.append({**source_row(repo if kind == 'current' else PREP, relative), 'path': destination})
    expect(history_source_equal(sorted(current_controls, key=lambda row: row['path']), controls),
           'HISTORY_COMPLETE_ACTUAL_CURRENT3_BUNDLE8_HELD')
    expect(history_source_bytes(review_pin['path']) == (review_raw, review_pin)
           and history_source_bytes(publication_pin['path']) == (publication_raw, publication_pin),
           'HISTORY_EXTERNAL_REVIEW_AND_PUBLICATION_WINDOW_HELD')
    LEGACY_HANDOFF_BINDINGS = bindings
    return {'review': review_pin, 'publication': publication_pin}


def current_reader_authority(repo, source, producer_controls):
    root = Path(repo).resolve(strict=True)
    clean(root, source)
    files, directories = legacy_namespace(root)
    expect(all(name in files for name in ['crmeb/vendor/autoload.php', 'crmeb/app/provider.php',
        'crmeb/app/api/route/v1.php', 'crmeb/app/services/marketplace/PublicCatalogServices.php',
        'crmeb/app/services/order/StoreOrderServices.php', 'crmeb/public/statics/system_images/spread_level_1.png']),
        'READER_COMPLETE_REQUIRED_CURRENT_APPLICATION')
    expect(type(producer_controls) is list and len(producer_controls) == 11
           and [row['path'] for row in producer_controls] == sorted(destination for _, _, destination in LEGACY_CONTROL),
           'READER_ORIGINAL_CONTROL11_PATHS')
    reader_path = '/run/nonempty/legacy-current-reader.php'
    reader = {**source_row(PREP, 'legacy-current-reader.php'), 'path': reader_path}
    controls = sorted(producer_controls + [reader], key=lambda row: row['path'])
    expect(len(controls) == 12 and len({row['path'] for row in controls}) == 12
           and [row for row in controls if row['path'] != reader_path] == producer_controls,
           'READER_EXACT_CONTROL12_ORIGINAL11_BYTES_AND_ORDER_HELD')
    value = {'schema': 1, 'source_sha': source, 'source_files': [source_row(root, name) for name in files],
             'source_directories': directories, 'control_files': controls}
    clean(root, source)
    return value


'''

_HANDOFF_OLD = '''    expected_names = {'producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer'}
    expect(type(LEGACY_HANDOFF_BINDINGS) is dict and set(LEGACY_HANDOFF_BINDINGS) == expected_names,
           'EXACT_COMPLETE_HANDOFF_SOURCE_MEMBERS')
    for name in ['producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer']:
        expect(LEGACY_HANDOFF_BINDINGS[name] is not None, 'UNBOUND_LEGACY_HANDOFF_' + name.upper())
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    verify_freeze(frozen)
    expect(len(ARTIFACTS) == 13 and sum(name.endswith('.php') for name in ARTIFACTS) == 9
           and len(LEGACY_CONTROL) == 11, 'ORIGINAL_ARTIFACT13_PHP9_CONTROL11')
    artifacts = [source_row(PREP, name) for name in ARTIFACTS]
    expect(frozen['artifact_files'] == artifacts and [row for row in artifacts if row['path'] != 'ms4-host.py'] == LEGACY_HANDOFF_BINDINGS['artifacts']
           and len(LEGACY_HANDOFF_BINDINGS['artifacts']) == 12,
           'FINAL_FULL_ARTIFACT13_AND_NONSELF12_NOT_A_PARTIAL_OVERLAY')
'''

_HANDOFF_NEW = '''    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    verify_freeze(frozen)
    expected_names = {'producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer'}
    expect(type(LEGACY_HANDOFF_BINDINGS) is dict and set(LEGACY_HANDOFF_BINDINGS) == expected_names,
           'EXACT_COMPLETE_HANDOFF_SOURCE_MEMBERS')
    for name in ['producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer']:
        expect(LEGACY_HANDOFF_BINDINGS[name] is not None, 'UNBOUND_LEGACY_HANDOFF_' + name.upper())
    artifacts = [source_row(PREP, name) for name in ARTIFACTS]
    producer = history_source_projection(artifacts)
    expect(len(LEGACY_CONTROL) == 11, 'ORIGINAL_ARTIFACT13_PHP9_CONTROL11')
    expect(frozen['artifact_files'] == artifacts and [row for row in producer if row['path'] != 'ms4-host.py'] == LEGACY_HANDOFF_BINDINGS['artifacts']
           and len(LEGACY_HANDOFF_BINDINGS['artifacts']) == 12,
           'FINAL_FULL_ARTIFACT14_ORIGINAL13_AND_NONSELF12_NOT_A_PARTIAL_OVERLAY')
'''


def projection_seams() -> tuple[tuple[bytes, bytes], ...]:
    """Return the complete ordered, unique literal edits, including their inverse."""
    values = (
        ("import secrets\n", "import secrets\nimport stat\n"),
        (_ARTIFACTS, _ARTIFACTS.replace("ARTIFACTS =", "PRODUCER_ARTIFACTS =", 1)
         + "\n" + _ARTIFACTS[:-1] + ", 'legacy-current-reader.php']"),
        ("def freeze(repo, source, output, legacy_repo):\n", _HELPERS
         + "def freeze(repo, source, output, legacy_repo, review_file, review_sha256):\n"),
        ("    seed_inverse(repo)\n    value = {'schema': 1, 'status': 'CLEAN_SOURCE_FROZEN_ONLY'",
         "    seed_inverse(repo)\n"
         "    legacy = legacy_authority(repo, legacy_repo)\n"
         "    artifacts = [source_row(PREP, p) for p in ARTIFACTS]\n"
         "    qualification = history_source_review(repo, source, review_file, review_sha256, artifacts, legacy['control_files'])\n"
         "    value = {'schema': 1, 'status': 'CLEAN_SOURCE_FROZEN_ONLY'"),
        ("             'artifact_files': [source_row(PREP, p) for p in ARTIFACTS],\n"
         "             'nonempty_history': legacy_authority(repo, legacy_repo),\n",
         "             'artifact_files': artifacts,\n"
         "             'nonempty_history': legacy,\n"
         "             'current_reader': current_reader_authority(repo, source, legacy['control_files']),\n"
         "             'history_source_review': qualification['review'],\n"
         "             'history_source_publication': qualification['publication'],\n"),
        ("    verify_legacy_authority(binding)\n\n\ndef native(context, args, label):",
         "    verify_legacy_authority(binding)\n"
         "    expect(history_source_equal(binding['current_reader'], current_reader_authority(repo, binding['source_sha'], binding['nonempty_history']['control_files'])),\n"
         "           'READER_COMPLETE_CURRENT_NAMESPACE_AND_CONTROL12_BYTES')\n"
         "    review = binding['history_source_review']; history_source_pointer(review)\n"
         "    history_source_pointer(binding['history_source_publication'])\n"
         "    qualification = history_source_review(repo, binding['source_sha'], review['path'], review['sha256'],\n"
         "                                          binding['artifact_files'], binding['nonempty_history']['control_files'])\n"
         "    expect(qualification == {'review': review, 'publication': binding['history_source_publication']},\n"
         "           'FROZEN_ORIGINAL_EXTERNAL_REVIEW_AND_PUBLICATION')\n\n\ndef native(context, args, label):"),
        (_HANDOFF_OLD, _HANDOFF_NEW),
        ("    parser.add_argument('--legacy-repo')\n",
         "    parser.add_argument('--legacy-repo')\n"
         "    parser.add_argument('--source-review-file'); parser.add_argument('--source-review-sha256')\n"),
        ("    args = parser.parse_args()\n    expect(0 <= args.seconds <= 30, 'BOUNDED_OBSERVATION')\n",
         "    args = parser.parse_args()\n"
         "    expect(args.stage == 'freeze' or (args.source_review_file is None and args.source_review_sha256 is None),\n"
         "           'HISTORY_SOURCE_REVIEW_ARGUMENTS_ONLY_FOR_FREEZE')\n"
         "    expect(0 <= args.seconds <= 30, 'BOUNDED_OBSERVATION')\n"),
        ("        expect(args.repo and args.source and args.output and args.legacy_repo, 'EXPLICIT_CLEAN_DUAL_SOURCE_FREEZE')\n"
         "        result = freeze(args.repo, args.source, args.output, args.legacy_repo)\n",
         "        expect(args.repo and args.source and args.output and args.legacy_repo and args.source_review_file\n"
         "               and args.source_review_sha256, 'EXPLICIT_REVIEWED_CLEAN_DUAL_SOURCE_FREEZE')\n"
         "        result = freeze(args.repo, args.source, args.output, args.legacy_repo, args.source_review_file, args.source_review_sha256)\n"),
    )
    return tuple((before.encode("utf-8"), after.encode("utf-8")) for before, after in values)



# Fixed donor provenance: legacy-current-reader-runtime-source-r2 Host 148439 bytes,
# SHA256 8b4751a46fa81bbcc89711373641d375856c0ea772554e738fa78ff1fff2300d.
_HISTORY_RUNTIME = r'''def history_run_source_aliases(context, binding):
    """Read only: retain both original external documents and their exact Run copies."""
    root = Path(context['run_dir'])
    expect(root.is_absolute() and root.resolve(strict=True).as_posix() == root.as_posix()
           and binding['source_sha'] == context['source_sha'] and binding['status'] == 'CLEAN_SOURCE_FROZEN_ONLY'
           and binding['runtime_SQL_App'] == 'NOT RUN'
           and binding['nonempty_history_runtime'] == 'SOURCE_ADOPTED_RUNTIME_NOT_RUN',
           'HISTORY_ALIAS_ORIGINAL_RUN_AND_REVIEWED_SOURCE_ONLY')
    rows = [('review', 'history_source_review', 'history-source-review.json'),
            ('publication', 'history_source_publication', 'history-source-publication.json')]
    originals = {}; aliases = {}; values = {}
    for name, field, leaf in rows:
        pointer = binding[field]; history_source_pointer(pointer)
        raw, actual = history_source_bytes(pointer['path'])
        expect(actual == pointer, 'HISTORY_ALIAS_ORIGINAL_FULL_POINTER')
        copied, alias = history_source_bytes(root / leaf)
        expect(copied == raw and alias['bytes'] == pointer['bytes'] and alias['sha256'] == pointer['sha256'],
               'HISTORY_ALIAS_EXACT_ORIGINAL_RAW_BYTES_SHA')
        originals[name] = (raw, actual); aliases[name] = (copied, alias)
        values[name] = history_source_json(raw)
    review, publication = values['review'], values['publication']
    expect(type(review) is dict and set(review) == {'schema', 'status', 'source_sha', 'publication', 'bindings'}
           and type(review['schema']) is int and review['schema'] == 1
           and review['status'] == 'PASS_CURRENT_HISTORY_SOURCE_REVIEW'
           and review['source_sha'] == context['source_sha']
           and review['publication'] == binding['history_source_publication'], 'HISTORY_ALIAS_REAL_REVIEW_JOIN')
    expect(type(publication) is dict and set(publication) == {'schema', 'scope', 'source_sha', 'artifact_files'}
           and type(publication['schema']) is int and publication['schema'] == 1
           and publication['scope'] == 'CURRENT_HISTORY_SOURCE_PUBLICATION'
           and publication['source_sha'] == context['source_sha']
           and history_source_equal(publication['artifact_files'], binding['artifact_files']),
           'HISTORY_ALIAS_REVIEWED_COMPLETE_PUBLICATION14')
    history_source_projection(publication['artifact_files'])
    for name, field, leaf in rows:
        expect(history_source_bytes(binding[field]['path']) == originals[name]
               and history_source_bytes(root / leaf) == aliases[name], 'HISTORY_ALIAS_FULL_ORIGINAL_AND_COPY_WINDOW_HELD')
    return {name: {'path': '/run/audit/' + leaf, 'bytes': aliases[name][1]['bytes'],
                   'sha256': aliases[name][1]['sha256']} for name, field, leaf in rows}


def history_copy_source_aliases(context, binding):
    """Copy already verified public source documents before the first native resource call."""
    root = Path(context['run_dir']); retained = {}
    rows = [('history_source_review', 'history-source-review.json'),
            ('history_source_publication', 'history-source-publication.json')]
    for field, leaf in rows:
        pointer = binding[field]; history_source_pointer(pointer)
        raw, actual = history_source_bytes(pointer['path'])
        expect(actual == pointer, 'HISTORY_COPY_ACTUAL_EXTERNAL_SOURCE_POINTER')
        destination = root / leaf
        expect(not destination.exists() and not destination.is_symlink(), 'HISTORY_SOURCE_ALIAS_CREATE_ONLY')
        with destination.open('xb') as stream:
            expect(stream.write(raw) == len(raw), 'HISTORY_SOURCE_ALIAS_FULL_WRITE')
            stream.flush()
        copied, alias = history_source_bytes(destination)
        expect(copied == raw and alias['bytes'] == actual['bytes'] and alias['sha256'] == actual['sha256'],
               'HISTORY_SOURCE_ALIAS_ACTUAL_FULL_READBACK')
        retained[field] = (raw, actual)
    for field, leaf in rows:
        expect(history_source_bytes(binding[field]['path']) == retained[field], 'HISTORY_COPY_ORIGINAL_SOURCE_BYTES_HELD')
    return history_run_source_aliases(context, binding)


def history_created_network_subnet(context, binding):
    """Verify the actual IPAM only when the reviewed Compose specifies a subnet."""
    import ipaddress
    rows = [row for row in binding['artifact_files'] if row['path'] == 'compose.yml']
    expect(len(rows) == 1, 'HISTORY_SINGLE_REVIEWED_COMPOSE')
    raw, actual = history_source_bytes(PREP / 'compose.yml')
    expect(rows[0] == {'path': 'compose.yml', 'bytes': actual['bytes'], 'sha256': actual['sha256']},
           'HISTORY_SUBNET_FROM_COMPLETE_PINNED_COMPOSE')
    text = raw.decode('utf-8')
    declarations = re.findall(r'(?m)^\s*(?:-\s*)?subnet\s*:', text)
    if not declarations:
        return
    matches = re.findall(r'(?m)^        - subnet: ([0-9.]+/[0-9]+)$', text)
    expect(len(declarations) == len(matches) == 1, 'HISTORY_ONE_LITERAL_EXPLICIT_SUBNET')
    section = re.search(r'(?ms)^networks:\n(?P<block>.*?)(?=^\S|\Z)', text)
    expect(section is not None and section.group('block').startswith('  isolation:\n')
           and len(re.findall(r'(?m)^  [a-zA-Z0-9_-]+:', section.group('block'))) == 1
           and re.findall(r'(?m)^        - subnet: ([0-9.]+/[0-9]+)$', section.group('block')) == matches,
           'HISTORY_SUBNET_IN_ONLY_ORIGINAL_ISOLATION_NETWORK')
    subnet = ipaddress.ip_network(matches[0], strict=True)
    expect(isinstance(subnet, ipaddress.IPv4Network) and str(subnet) == matches[0], 'HISTORY_CANONICAL_IPV4_SUBNET')
    config = json.loads(native(context, ['network', 'inspect', '--format', '{{json .IPAM.Config}}', context['network_id']],
                               'history-created-network-ipam'))
    expect(type(config) is list and len(config) == 1 and type(config[0]) is dict
           and config[0].get('Subnet') == matches[0], 'HISTORY_ACTUAL_CREATED_NETWORK_EXACT_SINGLE_SUBNET')
    expect(history_source_bytes(PREP / 'compose.yml') == (raw, actual), 'HISTORY_SUBNET_COMPOSE_BYTES_HELD')


def history_process(context, argv, stem, env=None):
    """One real native process, with its complete four public files; never serialize env."""
    evidence = Path(context['run_dir']) / 'evidence'
    paths = {key: evidence / (stem + '.' + suffix) for key, suffix in
             [('created', 'created.json'), ('terminal', 'terminal.json'), ('stdout', 'stdout'), ('stderr', 'stderr')]}
    expect(all(not path.exists() and not path.is_symlink() for path in paths.values()), 'HISTORY_NATIVE_CREATE_ONLY')
    started = datetime.now(timezone.utc).isoformat()
    process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
    made = {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
            'freeze_sha256': context['freeze_sha256'], 'argv': argv, 'pid': process.pid, 'started_at': started}
    new(paths['created'], made)
    stdout, stderr = process.communicate()
    waited = process.wait()
    streams = {}
    for key, raw in [('stdout', stdout), ('stderr', stderr)]:
        with paths[key].open('xb') as stream:
            expect(stream.write(raw) == len(raw), 'HISTORY_NATIVE_FULL_STREAM_WRITE')
        expect(paths[key].read_bytes() == raw, 'HISTORY_NATIVE_STREAM_READBACK')
        streams[key] = {'path': '/run/audit/evidence/' + paths[key].name, 'bytes': len(raw), 'sha256': digest(raw)}
    terminal = made | {'finished_at': datetime.now(timezone.utc).isoformat(),
                       'native_exit': process.returncode, 'wait_exit': waited, **streams}
    new(paths['terminal'], terminal)
    expect(type(process.pid) is int and process.pid > 0 and process.returncode == waited,
           'HISTORY_NATIVE_SAME_PROCESS_TERMINAL_WAIT')
    return subprocess.CompletedProcess(argv, waited, stdout, stderr)


def history_volume_roots(context):
    roots = {}
    template = '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},"mountpoint":{{json .Mountpoint}}}'
    for original in context['initial_volumes']:
        row = json.loads(native(context, ['volume', 'inspect', '--format', template, original['name']], 'history-physical-volume'))
        expect({key: row[key] for key in original} == original
               and type(row['mountpoint']) is str and row['mountpoint'].startswith('/'), 'HISTORY_ORIGINAL_VOLUME_PHYSICAL_IDENTITY')
        roots[row['name']] = row['mountpoint'].rstrip('/')
    expect(len(roots) == 6 and len(set(roots.values())) == 6, 'HISTORY_SIX_DISTINCT_PHYSICAL_VOLUME_ROOTS')
    return roots


def history_initializer_self(context):
    root = Path(context['run_dir']); archive = root / 'evidence/nonempty-history-phases/before-legacy-files'
    index_raw, index_pin = history_source_bytes(archive / 'INDEX.json')
    index = history_source_json(index_raw)
    expect(index['phase'] == 'before-legacy-files' and index['run_id'] == context['run_id']
           and index['source_sha'] == context['source_sha'] and index['legacy_source_sha'] == LEGACY_SOURCE,
           'HISTORY_SELF_ORIGINAL_INITIALIZER_ARCHIVE')
    values = {}; original_pins = {}
    for leaf in ['initializer-created.json', 'initializer-terminal.json', 'initializer-result.json']:
        relative = 'evidence/' + leaf
        raw, original_pin = history_source_bytes(root / relative)
        archived_raw, archived_pin = history_source_bytes(archive / relative)
        matches = [row for row in index['files'] if row['path'] == relative]
        expect(len(matches) == 1 and matches[0]['bytes'] == len(raw) and matches[0]['sha256'] == digest(raw)
               and archived_raw == raw and archived_pin['bytes'] == original_pin['bytes']
               and archived_pin['sha256'] == original_pin['sha256'], 'HISTORY_SELF_ORIGINAL_COMPLETE_THREE_PINS')
        values[leaf] = history_source_json(raw)
        original_pins[leaf] = original_pin
    made, terminal, result = [values[key] for key in ['initializer-created.json', 'initializer-terminal.json', 'initializer-result.json']]
    same_created(made, terminal); owned(context, terminal, 'audit-ms4-initialize')
    expect(terminal['status'] == 'exited' and terminal['pid'] == terminal['exit_code'] == 0
           and result['status'] == 'VERIFIED_MS4_PREREQUISITES_ONLY' and result['native_exit'] == 0
           and result['private_cleanup_verified'] is True and result['binding']['container_id'] == made['id']
           and result['binding']['freeze_sha256'] == context['freeze_sha256']
           and result['binding']['source_sha'] == context['source_sha']
           and result['binding']['created_sha256'] == original_pins['initializer-created.json']['sha256']
           and len(result['children']) == 6 and len({row['pid'] for row in result['children']}) == 6
           and all(type(row['pid']) is int and row['pid'] > 0 and row['native_exit'] == 0 and row['stderr_bytes'] == 0
                   for row in result['children'])
           and type(result['actual_self_mer_id']) is int and result['actual_self_mer_id'] > 0,
           'HISTORY_SELF_REAL_SIX_CHILD_INITIALIZER_AND_NORMAL_TERMINAL')
    return result['actual_self_mer_id']


def history_producer_normal(context, public):
    completed = legacy_producer_complete(context, True)
    read, pin, obj, held = public
    seal = obj('evidence/legacy-history-producer-result.json')
    expect(legacy_seal_equal(seal, completed['seal']), 'HISTORY_CURRENT_COMPLETE_R3_THIRTEEN_KEY_SEAL')
    return {'container_id': completed['container_id'], 'parent': seal['producer']['native_parent'],
            'identities': seal['identities'], 'self_operated_mer_id': history_initializer_self(context), 'seal': seal}


def history_driver_normal(context, producer, public):
    read, pin, obj, held = public; result = obj('evidence/ms4-migration-normal.json')
    made = obj('evidence/driver-created.json'); terminal = obj('evidence/driver-terminal.json')
    expect(made == bound_created(context, 'driver', 'audit-ms4-migration'), 'HISTORY_DRIVER_ORIGINAL_CREATED')
    owned(context, terminal, 'audit-ms4-migration'); same_created(made, terminal)
    expect(terminal['status'] == 'exited' and terminal['exit_code'] == terminal['pid'] == 0
           and result['status'] == 'VERIFIED_NORMAL_STRUCTURE_ONLY' and result['native_exit'] == 0
           and result['private_cleanup_verified'] is True, 'HISTORY_DRIVER_REAL_NORMAL_TERMINAL')
    completed = legacy_four_cli_handoff(context, result)
    expect(legacy_seal_equal(completed['seal'], producer['seal']), 'HISTORY_DRIVER_ORIGINAL_EXPECTED_M4M_SEAL_JOIN')
    return result


def history_upgrade_normal(context, public):
    read, pin, obj, held = public
    producer = history_producer_normal(context, public)
    history_retired_receipt(context, 'legacy-history-producer', public)
    history_driver_normal(context, producer, public); history_retired_receipt(context, 'driver', public)
    proof = 'current-structure-upgrade'; made = obj('evidence/' + proof + '-created.json')
    terminal = obj('evidence/' + proof + '-terminal.json'); result = obj('evidence/' + proof + '-result.json')
    expect(made == bound_created(context, proof, 'audit-bootstrap'), 'HISTORY_UPGRADE_EXACT_CREATED')
    owned(context, terminal, 'audit-bootstrap'); same_created(made, terminal)
    history_upgrade_mounts(context, made)
    expect(terminal['status'] == 'exited' and terminal['pid'] == terminal['exit_code'] == 0
           and result['scope'] == 'CURRENT_READER_STRUCTURE_UPGRADE_ONLY'
           and result['status'] == 'VERIFIED_CURRENT_STRUCTURE_UPGRADE_ONLY' and result['native_exit'] == 0
           and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
           and result['private_cleanup_verified'] is True
           and result['binding']['container_id'] == made['id']
           and result['binding']['freeze_sha256'] == context['freeze_sha256']
           and result['binding']['source_sha'] == context['source_sha']
           and result['binding']['created_sha256'] == pin('evidence/' + proof + '-created.json')['sha256'],
           'HISTORY_UPGRADE_REAL_HELPER_NORMAL_SAME_SOURCE')
    change = result['current_structure_upgrade']
    expect(set(change) == {'schema', 'expected_before_table_count', 'expected_after_table_count', 'source_files', 'snapshots',
           'original_four_cli_unchanged', 'original_four_cli', 'before_table_count', 'refund_null_counts',
           'schema_create_window', 'schema_row', 'after_table_count'}
           and change['schema'] == 1 and change['expected_before_table_count'] == change['before_table_count'] == 166
           and change['expected_after_table_count'] == change['after_table_count'] == 173
           and change['original_four_cli_unchanged'] is True
           and change['original_four_cli'] == pin('evidence/ms4-migration-normal.json')
           and set(change['snapshots']) == {'before', 'after', *('cli_' + str(i) for i in range(1, 17))},
           'HISTORY_UPGRADE_DISTINCT_FULL166_TO173_ENVELOPE')
    source_paths = ['help/audit-regressions/isolated/ms4-migration.php', 'help/migrations/marketplace-ms1.php',
        'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms5-payment.php',
        'help/migrations/marketplace-ms4-fulfillment.php', 'help/migrations/marketplace-ms4-aftersale.php',
        'help/migrations/marketplace-ms6-schema.php', 'crmeb/crmeb/exceptions/MarketplaceSchemaException.php',
        'crmeb/crmeb/services/marketplace/SchemaVersionGate.php', 'crmeb/marketplace-release.json',
        'crmeb/public/install/crmeb.sql']
    frozen_sources = {row['path']: row for row in obj('preparation-source-binding.json')['current_reader']['source_files']}
    expect(type(change['source_files']) is list and [row['path'] for row in change['source_files']] == source_paths,
           'HISTORY_UPGRADE_FIXED_ELEVEN_SOURCE_PATHS')
    for row in change['source_files']:
        raw, source_pin = history_source_bytes(Path(context['repo_dir']) / row['path'])
        expect(row == {'path': row['path'], 'bytes': len(raw), 'sha256': digest(raw)}
               and source_pin['bytes'] == row['bytes'] and source_pin['sha256'] == row['sha256']
               and all(frozen_sources[row['path']][key] == row[key] for key in ['bytes', 'sha256']),
               'HISTORY_UPGRADE_CURRENT_FULL_SOURCE_JOIN')
    plan = [(script, mode) for script in ['marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php']
            for mode in ['check', 'apply-structure', 'check', 'apply-structure']]
    plan += [('marketplace-ms6-schema.php', mode) for mode in ['apply', 'check', 'apply', 'check']]
    expect(type(result['children']) is list and len(result['children']) == 16, 'HISTORY_UPGRADE_SIXTEEN_ORIGINAL_NATIVE_CHILDREN')
    pids = set()
    for number, (child, (script, mode)) in enumerate(zip(result['children'], plan), 1):
        stem = 'evidence/current-structure-upgrade-cli-%02d' % number
        created_ = obj(stem + '.created.json'); terminal_ = obj(stem + '.terminal.json')
        out = read(stem + '.stdout'); err = read(stem + '.stderr')
        expect(set(created_) == {'argv', 'pid', 'started_at'} and type(created_['pid']) is int and created_['pid'] > 0
               and created_['pid'] not in pids and all(terminal_[key] == value for key, value in created_.items())
               and terminal_['native_exit'] == 0 and terminal_['finished_at'] >= terminal_['started_at']
               and terminal_['stdout'] == pin(stem + '.stdout') and terminal_['stderr'] == pin(stem + '.stderr')
               and err == b'' and child == terminal_ | {'script': script, 'mode': mode,
                    'terminal': pin(stem + '.terminal.json'), 'result': json.loads(out)},
               'HISTORY_UPGRADE_COMPLETE_REAL_CHILD_FOUR_FILES')
        argv = created_['argv']
        expect(len(argv) == (5 if script == 'marketplace-ms6-schema.php' else 4)
               and argv[1:3] == ['/workspace/help/migrations/' + script, mode]
               and (script != 'marketplace-ms6-schema.php' or argv[3] == '--repo-root=/workspace')
               and argv[-1] == '--config-file=/tmp/current-structure-upgrade-' + context['run_id'] + '/config.json',
               'HISTORY_UPGRADE_ORIGINAL_FIXED_CLI_ARGV')
        pids.add(created_['pid'])
    shutdown = obj('evidence/' + proof + '-shutdown.json')
    expect(set(shutdown) == {'schema', 'phase', 'fatal', 'type', 'file', 'line', 'primary_failure', 'cleanup_failure', 'memory_limit', 'peak_bytes'}
           and shutdown['schema'] == 1 and shutdown['phase'] == proof and shutdown['fatal'] is False
           and shutdown['type'] == 0 and shutdown['file'] == '' and shutdown['line'] == 0
           and shutdown['primary_failure'] is None and shutdown['cleanup_failure'] is None, 'HISTORY_UPGRADE_REAL_NATURAL_SHUTDOWN')
    for relative in list(held): read(relative)
    return result


def history_retire_spec(public, proof):
    read, pin, obj, held = public
    specs = {
        'legacy-history-producer': ('audit-legacy-history-producer', 'evidence/audit-legacy-history-producer-source-binding.json',
                                    'evidence/legacy-history-producer-result.json', {}),
        'driver': ('audit-ms4-migration', 'evidence/ms4-migration-source-binding.json', 'evidence/ms4-migration-normal.json',
                   {'producer_retired': 'evidence/legacy-history-producer-retired.json'}),
        'current-structure-upgrade': ('audit-bootstrap', 'preparation-source-binding.json',
                   'evidence/current-structure-upgrade-result.json', {'producer_retired': 'evidence/legacy-history-producer-retired.json',
                   'driver_retired': 'evidence/driver-retired.json'})}
    expect(proof in specs, 'HISTORY_THREE_EXACT_RETIRE_ROLES')
    role, binding, result, prerequisites = specs[proof]
    return role, binding, result, {key: pin(leaf) for key, leaf in prerequisites.items()}


def history_retire_publish(context, proof):
    """Publish only after the existing single normal rm and fresh original collector."""
    public = history_public_reader(context); read, pin, obj, held = public
    role, binding, result, prerequisites = history_retire_spec(public, proof)
    root = Path(context['run_dir']); evidence = root / 'evidence'; made = obj('evidence/' + proof + '-created.json')
    for leaf in [proof + '-retired.json', proof + '-retired-resources.json']:
        expect(not (evidence / leaf).exists(), 'HISTORY_RETIRE_CREATE_ONCE')
    removals = ['evidence/' + path.name for path in sorted(evidence.glob('native-*.json'))
                if re.fullmatch(r'native-[0-9]{4,}[.]json', path.name)
                and obj('evidence/' + path.name).get('stage') == proof + '-exact-nonforce-remove']
    expect(len(removals) == 1, 'HISTORY_ONE_EXISTING_ORIGINAL_RM_NOT_SECOND_REMOVAL')
    snapshot = {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'freeze_sha256': context['freeze_sha256'], 'retired_container_id': made['id'],
        'public_raw': {leaf: read(leaf).decode('utf-8') for leaf in ['network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl']}}
    new(evidence / (proof + '-retired-resources.json'), snapshot)
    receipt = {'schema': 1, 'scope': 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED', 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'role': role, 'proof_name': proof,
        'container_id': made['id'], 'created': pin('evidence/' + proof + '-created.json'),
        'terminal': pin('evidence/' + proof + '-terminal.json'), 'binding': pin(binding), 'result': pin(result),
        'normal_remove': pin(removals[0]), 'resource_after': pin('evidence/' + proof + '-retired-resources.json'),
        'prerequisites': prerequisites}
    new(evidence / (proof + '-retired.json'), receipt)
    expect(history_exact(history_retired_receipt(context, proof, public), receipt), 'HISTORY_COMPLETE15_RETIRE_ACTUAL_READBACK')
    for relative in list(held): read(relative)
    return receipt


def retire_history_role(context, proof):
    expect(proof in ['driver', 'current-structure-upgrade'], 'HISTORY_PRODUCER_RETIRES_ONLY_IN_ORIGINAL_DRIVER_CREATE')
    public = history_public_reader(context); read, pin, obj, held = public
    if proof == 'driver':
        producer = history_producer_normal(context, public)
        history_retired_receipt(context, 'legacy-history-producer', public)
        history_driver_normal(context, producer, public)
    else:
        history_upgrade_normal(context, public)
    role, binding, result, prerequisites = history_retire_spec(public, proof)
    cid = obj('evidence/' + proof + '-created.json')['id']; evidence = Path(context['run_dir']) / 'evidence'
    expect(not (evidence / (proof + '-retired.json')).exists(), 'HISTORY_RETIRE_ONCE_NO_RETRY')
    for path in sorted(evidence.glob('native-*.json')):
        if re.fullmatch(r'native-[0-9]{4,}[.]json', path.name):
            expect(obj('evidence/' + path.name).get('stage') != proof + '-exact-nonforce-remove', 'HISTORY_NO_PREVIOUS_REMOVE')
    remove_finished(context, cid, role, proof, base_roles(context) | {cid: role})
    for relative in list(held): read(relative)
    receipt = history_retire_publish(context, proof)
    return {'status': receipt['scope'], 'container_id': cid, 'run_id': context['run_id']}


def history_final_close(context):
    """Only the completed reader chain authorizes ordinary, nonforce cleanup."""
    root = Path(context['run_dir']); evidence = root / 'evidence'
    expect(not (evidence / 'history-final-close.json').exists(), 'HISTORY_FINAL_CLOSE_ONCE')
    public = history_public_reader(context); current_reader_retired_receipt(context, public)
    base = base_roles(context); collect(context, base)
    # Every historical created CID must be absent except the two original services.
    historical = set(base)
    for path in sorted(evidence.glob('*-created.json')):
        value = load(path)
        if 'id' in value:
            expect(type(value['id']) is str and re.fullmatch('[a-f0-9]{64}', value['id']), 'HISTORY_CREATED_REAL_CID')
            expect(value['labels'].get('tianli.audit.run') == context['run_id']
                   and value['labels'].get('com.docker.compose.project') == context['project'], 'HISTORY_CREATED_SAME_RUN')
            historical.add(value['id'])
    all_cids = set(native(context, ['ps', '-aq', '--no-trunc'], 'history-final-global-before').splitlines())
    expect((historical - set(base)).isdisjoint(all_cids), 'HISTORY_ALL_ORIGINAL_HELPERS_ABSENT')
    physical = history_volume_roots(context)
    preserved_aliases = history_global_aliases(context, set(base), physical)
    new(evidence / 'history-final-close-before.json', {'schema': 1, 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
        'base': context['initial_service_created'], 'volumes': context['initial_volumes'],
        'physical_volumes': physical, 'network': context['initial_network'], 'historical_cids': sorted(historical)})
    for cid, role in base.items():
        facts = inspect(context, cid, 'history-base-before-stop'); owned(context, facts, role)
        same_created(context['initial_service_created'][role], facts)
        expect(facts['status'] == 'running' and facts['pid'] > 0, 'HISTORY_ORIGINAL_BASE_RUNNING_BEFORE_NORMAL_STOP')
    for cid, role in base.items():
        # Docker has no automatic SIGKILL fallback with --timeout=-1.
        native(context, ['stop', '--timeout=-1', cid], 'history-' + role + '-ordinary-stop')
        facts = inspect(context, cid, 'history-base-after-stop'); same_created(context['initial_service_created'][role], facts)
        expect(facts['status'] == 'exited' and facts['pid'] == 0 and facts['exit_code'] == 0,
               'HISTORY_BASE_NORMAL_ZERO_TERMINAL_NO_KILL')
    collect(context, base)
    for cid, role in base.items():
        history_global_aliases(context, set(base), physical, preserved_aliases)
        facts = inspect(context, cid, 'history-base-before-remove'); same_created(context['initial_service_created'][role], facts)
        expect(facts['status'] == 'exited' and facts['pid'] == facts['exit_code'] == 0, 'HISTORY_BASE_SAME_NORMAL_TERMINAL')
        expect(native(context, ['rm', cid], 'history-' + role + '-exact-nonforce-remove') == cid, 'HISTORY_BASE_NORMAL_REMOVE_CID')
    expect(not active(context), 'HISTORY_ALL_ORIGINAL_CONTAINERS_ABSENT')
    history_global_aliases(context, set(), physical, preserved_aliases)
    for original in context['initial_volumes']:
        name = original['name']
        row = json.loads(native(context, ['volume', 'inspect', '--format',
            '{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}},"mountpoint":{{json .Mountpoint}}}', name],
            'history-volume-final-identity'))
        expect({key: row[key] for key in original} == original and row['mountpoint'].rstrip('/') == physical[name],
               'HISTORY_FINAL_SAME_VOLUME_NATIVE_IDENTITY')
        expect(native(context, ['volume', 'rm', name], 'history-volume-nonforce-remove') == name, 'HISTORY_EXACT_NORMAL_VOLUME_REMOVE')
    network = context['initial_network']
    row = json.loads(native(context, ['network', 'inspect', '--format',
        '{"Id":{{json .Id}},"Name":{{json .Name}},"Internal":{{json .Internal}},"Labels":{{json .Labels}},"Containers":{{json .Containers}}}',
        network['Id']], 'history-network-final-identity'))
    expect({key: row[key] for key in network} == network and row['Containers'] == {}, 'HISTORY_EXACT_ORIGINAL_EMPTY_NETWORK')
    native(context, ['network', 'rm', network['Id']], 'history-network-nonforce-remove')
    # A new independent six-query window, never copied from earlier empty lists.
    zeros = {}
    for kind in ['container', 'network', 'volume']:
        for label, value in [('com.docker.compose.project', context['project']), ('tianli.audit.run', context['run_id'])]:
            rows = ids(context, kind, label + '=' + value)
            expect(rows == [], 'HISTORY_FRESH_SIX_NATIVE_ZERO')
            zeros[kind + ':' + label] = rows
    all_after = set(native(context, ['ps', '-aq', '--no-trunc'], 'history-final-global-after').splitlines())
    expect(historical.isdisjoint(all_after), 'HISTORY_ALL_HISTORICAL_CIDS_ABSENT_FINAL')
    # Do not refresh now-removed volumes; preserve and use the observed physical roots.
    history_global_aliases(context, set(), physical, preserved_aliases)
    receipt = {'schema': 1, 'scope': 'NONEMPTY_HISTORY_NORMAL_READER_CHAIN_CLOSED', 'run_id': context['run_id'],
               'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
               'reader_retired': public[1]('evidence/legacy-current-reader-retired.json'),
               'normal_base_stop': True, 'original_containers': 2, 'original_volumes': 6, 'original_networks': 1,
               'fresh_zero_queries': zeros, 'full_acceptance': 'INCOMPLETE'}
    for relative in list(public[3]): public[0](relative)
    new(evidence / 'history-final-close.json', receipt)
    return receipt


def history_global_aliases(context, allowed, physical, preserved_aliases=None):
    all_ids = native(context, ['ps', '-aq', '--no-trunc'], 'history-global-aliases').splitlines()
    if not all_ids:
        return set() if preserved_aliases is None else set(preserved_aliases)
    template = '{"id":{{json .Id}},"name":{{json .Name}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}},"network_mode":{{json .HostConfig.NetworkMode}}}'
    rows = [json.loads(line) for line in native(context, ['inspect', '--format', template, *all_ids], 'history-global-alias-facts').splitlines()]
    expect(len(rows) == len(all_ids) and {row['id'] for row in rows} == set(all_ids), 'HISTORY_COMPLETE_GLOBAL_CONTAINER_SET')
    originals = set(context.get('initial_service_ids', {}).values())
    for path in sorted((Path(context['run_dir']) / 'evidence').glob('*-created.json')):
        facts = load(path)
        if isinstance(facts.get('id'), str) and re.fullmatch('[a-f0-9]{64}', facts['id']):
            expect(facts['labels'].get('tianli.audit.run') == context['run_id'], 'HISTORY_ALIAS_ORIGINAL_CREATED_RUN')
            originals.add(facts['id'])
    aliases = originals | {row['name'].lstrip('/') for row in rows if row['id'] in originals} | set(preserved_aliases or [])
    for row in rows:
        touched = context['project'] + '_isolation' in row['networks']
        touched = touched or any(net.get('NetworkID') == context.get('network_id') for net in row['networks'].values())
        mode = row['network_mode']
        touched = touched or (mode.startswith('container:') and (mode.split(':', 1)[1] in aliases or any(len(mode.split(':', 1)[1]) >= 12 and cid.startswith(mode.split(':', 1)[1]) for cid in originals)))
        for mount in row['mounts']:
            source = mount.get('Source', '').rstrip('/')
            touched = touched or mount.get('Name') in physical or any(source == root or source.startswith(root + '/') for root in physical.values())
        if touched:
            expect(row['id'] in allowed, 'HISTORY_NO_FOREIGN_MOUNT_OR_NETWORK_NAMESPACE_ALIAS')
    return aliases


def history_extra_stage(name, context, seconds):
    root = Path(context['run_dir']); evidence = root / 'evidence'; base = base_roles(context)
    if name == 'driver-retire':
        return retire_history_role(context, 'driver')
    if name == 'current-structure-upgrade-retire':
        return retire_history_role(context, 'current-structure-upgrade')
    if name == 'current-structure-upgrade-create':
        public = history_public_reader(context); producer = history_producer_normal(context, public)
        history_retired_receipt(context, 'legacy-history-producer', public)
        history_driver_normal(context, producer, public); history_retired_receipt(context, 'driver', public)
        expect(not (evidence / 'current-structure-upgrade-created.json').exists(), 'HISTORY_UPGRADE_CREATE_ONCE')
        collect(context, base)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-bootstrap'],
                'current-structure-upgrade-create', 'current-structure-upgrade', 'current-structure-upgrade')
        cid = created(context, 'audit-bootstrap', 'current-structure-upgrade', 'current-structure-upgrade')
        history_upgrade_mounts(context, bound_created(context, 'current-structure-upgrade', 'audit-bootstrap'))
        collect(context, base | {cid: 'audit-bootstrap'})
        return {'status': 'CURRENT_STRUCTURE_UPGRADE_UNSTARTED_ONLY', 'container_id': cid}
    if name == 'current-structure-upgrade-start':
        cid = bound_created(context, 'current-structure-upgrade', 'audit-bootstrap')['id']
        collect(context, base | {cid: 'audit-bootstrap'})
        facts = inspect(context, cid, 'history-upgrade-start-precheck')
        same_created(bound_created(context, 'current-structure-upgrade', 'audit-bootstrap'), facts)
        history_upgrade_mounts(context, facts)
        expect(facts['status'] == 'created' and facts['pid'] == 0, 'HISTORY_UPGRADE_SAME_UNSTARTED_ONLY')
        native(context, ['start', cid], 'current-structure-upgrade-start')
        return {'status': 'CURRENT_STRUCTURE_UPGRADE_STARTED_ONLY', 'container_id': cid}
    if name == 'current-reader-retire':
        return retire_current_reader(context)
    if name == 'current-reader-create':
        expect(not (evidence / 'legacy-current-reader-created.json').exists(), 'READER_CREATE_ONCE_NO_RETRY')
        current_reader_prerequisites(context); collect(context, base)
        archive = current_reader_archive(context)
        compose(context, ['create', '--no-recreate', '--no-build', '--pull', 'never', 'audit-legacy-current-reader'],
                'current-reader-create', 'legacy-current-reader', 'normal')
        created(context, 'audit-legacy-current-reader', 'legacy-current-reader', 'normal')
        cid = bind_current_reader(context)
        result = helper(context, 'register-legacy-current-reader', 'register', base | {cid: 'audit-legacy-current-reader'})
        if result.get('status') != 'REGISTERED_NATIVE_RESOURCES_ONLY':
            return {'status': 'LIVE_CURRENT_READER_REGISTRATION_SAME_HANDLE_PRESERVED', 'container_id': cid}
        return {'status': 'CURRENT_READER_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': current_reader_bound(context), 'phase_archive': archive}
    if name == 'current-reader-bind':
        return {'status': 'CURRENT_READER_UNSTARTED_SOURCE_BOUND_ONLY', 'container_id': current_reader_bound(context)}
    if name == 'current-reader-start':
        cid = current_reader_bound(context); fresh = inspect(context, cid, 'current-reader-start-precheck')
        same_created(bound_created(context, 'legacy-current-reader', 'audit-legacy-current-reader'), fresh)
        expect(fresh['status'] == 'created' and fresh['pid'] == 0 and fresh['restart_count'] == 0, 'READER_START_ONLY_ONCE_ORIGINAL_CID')
        native(context, ['start', cid], 'legacy-current-reader-start')
        return {'status': 'CURRENT_READER_STARTED_ONLY', 'container_id': cid}
    if name == 'history-final-close':
        return history_final_close(context)
    raise RuntimeError('HISTORY_UNKNOWN_EXPLICIT_EXTRA_STAGE')


def history_upgrade_mounts(context, facts):
    expect(facts['command'] == ['/run/ms4-prep/ms4-initialize.php', 'current-structure-upgrade']
           and set(facts['tmpfs']) == {'/tmp'}, 'HISTORY_UPGRADE_FIXED_COMMAND_AND_ONLY_PRIVATE_TMP')
    wanted = [{'Type': 'bind', 'Source': host, 'Destination': destination, 'RW': destination == '/run/audit', 'Name': ''}
              for destination, host in {'/workspace': context['repo_dir'], '/source': context['repo_dir'],
                  '/run/ms4-prep': context['prep_dir'], '/run/audit': context['run_dir']}.items()]
    prepared = bound_created(context, 'prepare', 'audit-bootstrap')
    for leaf, destination in [('bootstrap-secrets', '/run/audit-bootstrap'), ('redis-secrets', '/run/audit-redis'),
                              ('app-secrets', '/run/audit-app'), ('fixture-secrets', '/run/audit-fixtures')]:
        original = [row for row in prepared['mounts'] if row['Destination'] == destination]
        expect(len(original) == 1 and original[0]['Name'] == context['project'] + '_' + leaf
               and original[0]['Type'] == 'volume', 'HISTORY_UPGRADE_ORIGINAL_PREPARE_VOLUME')
        wanted.append({'Type': 'volume', 'Name': original[0]['Name'], 'Source': original[0]['Source'],
                       'Destination': destination, 'RW': False})
    actual = [row for row in facts['mounts'] if row['Type'] != 'tmpfs']
    expect(history_mount_rows(actual) == history_mount_rows(wanted), 'HISTORY_UPGRADE_EXACT_EIGHT_MOUNTS_PRIVATE_RO')
'''
_READER_RUNTIME = r'''def history_exact(left, right):
    """The PHP oracle uses ===, including native types and ordered array keys."""
    if type(left) is not type(right):
        return False
    if type(left) is dict:
        return list(left) == list(right) and all(history_exact(left[key], right[key]) for key in left)
    if type(left) is list:
        return len(left) == len(right) and all(history_exact(a, b) for a, b in zip(left, right))
    return left == right


def history_public_reader(context):
    root = Path(context['run_dir']); held = {}
    expect(root.is_absolute() and root.is_dir() and not root.is_symlink() and not root.is_junction()
           and (root / 'evidence').is_dir() and not (root / 'evidence').is_symlink()
           and not (root / 'evidence').is_junction(), 'HISTORY_ORIGINAL_REGULAR_PUBLIC_ROOTS')
    fixed = {'preparation-source-binding.json', 'history-source-review.json', 'history-source-publication.json',
        'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
        'evidence/legacy-history-producer-created.json', 'evidence/legacy-history-producer-terminal.json',
        'evidence/audit-legacy-history-producer-container-created.json',
        'evidence/audit-legacy-history-producer-source-binding.json', 'evidence/legacy-history-producer-result.json',
        'evidence/driver-created.json', 'evidence/driver-terminal.json',
        'evidence/ms4-migration-container-created.json', 'evidence/ms4-migration-source-binding.json',
        'evidence/ms4-migration-normal.json', 'evidence/legacy-history-producer-retired.json',
        'evidence/legacy-history-producer-retired-resources.json', 'evidence/driver-retired.json',
        'evidence/driver-retired-resources.json',
        'evidence/legacy-current-reader-created.json', 'evidence/legacy-current-reader-terminal.json',
        'evidence/audit-legacy-current-reader-container-created.json',
        'evidence/audit-legacy-current-reader-source-binding.json', 'evidence/legacy-current-reader-result.json',
        'evidence/legacy-current-reader-retired.json', 'evidence/legacy-current-reader-retired-resources.json',
        'evidence/register-legacy-current-reader-created.json', 'evidence/register-legacy-current-reader-terminal.json',
        'evidence/register-legacy-current-reader-result.json'}
    fixed.update({'evidence/current-structure-upgrade-' + suffix for suffix in
        ['created.json', 'terminal.json', 'result.json', 'shutdown.json', 'retired.json', 'retired-resources.json']})
    fixed.update('evidence/current-structure-upgrade-cli-%02d.%s' % (number, suffix)
        for number in range(1, 17) for suffix in ['created.json', 'terminal.json', 'stdout', 'stderr'])
    stat_keys = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    def read(relative):
        expect(relative in fixed or re.fullmatch(r'evidence/native-[0-9]{4,}[.]json', relative), 'HISTORY_PUBLIC_FIXED_NONPRIVATE_INPUT')
        path = root / relative
        expect(path.is_file() and not path.is_symlink() and not path.is_junction(), 'HISTORY_PUBLIC_REGULAR_INPUT')
        before = path.lstat()
        expect(before.st_mode & 0o170000 == 0o100000 and before.st_nlink == 1
               and not (getattr(before, 'st_file_attributes', 0) & 1024), 'HISTORY_PUBLIC_NO_LINK_OR_SPECIAL')
        before9 = tuple(getattr(before, key) for key in stat_keys)
        before_birth = getattr(before, 'st_birthtime_ns', None)
        expect((before.st_size > 0 or relative.endswith(('.stdout', '.stderr'))) and 0 <= before.st_size <= 67108864, 'HISTORY_PUBLIC_FINITE_COMPLETE_INPUT')
        with path.open('rb') as stream:
            opened = os.fstat(stream.fileno())
            opened9 = tuple(getattr(opened, key) for key in stat_keys)
            opened_birth = getattr(opened, 'st_birthtime_ns', None)
            # Windows path ctime and handle ctime have different meanings.
            # Keep both complete nine-field windows; join the eight semantic
            # fields and birth time, as in the existing stable-read contract.
            expect(before9[:-1] == opened9[:-1] and before_birth == opened_birth,
                   'HISTORY_PUBLIC_PATH_HANDLE_EIGHT_AND_BIRTH_IDENTITY')
            raw = stream.read(67108865)
            expect(stream.read(1) == b'', 'HISTORY_PUBLIC_COMPLETE_HANDLE_EOF')
            final = os.fstat(stream.fileno())
            final9 = tuple(getattr(final, key) for key in stat_keys)
        after = path.lstat(); after9 = tuple(getattr(after, key) for key in stat_keys)
        expect(before9 == after9 and opened9 == final9
               and before_birth == getattr(after, 'st_birthtime_ns', None)
               and opened_birth == getattr(final, 'st_birthtime_ns', None)
               and len(raw) == before.st_size, 'HISTORY_PUBLIC_FULL_RAW_NATIVE_STAT9')
        observation = (raw, before9, opened9, before_birth, opened_birth)
        expect(relative not in held or held[relative] == observation, 'HISTORY_PUBLIC_ORIGINAL_INPUTS_UNCHANGED')
        held[relative] = observation
        return raw
    def descriptor(relative):
        raw = read(relative)
        return {'path': '/run/audit/' + relative, 'bytes': len(raw), 'sha256': digest(raw)}
    def object_(relative):
        def unique(pairs):
            value = {}
            for key, item in pairs:
                expect(key not in value, 'HISTORY_PUBLIC_NO_DUPLICATE_JSON_KEYS'); value[key] = item
            return value
        value = json.loads(read(relative), object_pairs_hook=unique,
            parse_constant=lambda value: (_ for _ in ()).throw(RuntimeError('HISTORY_PUBLIC_FINITE_JSON')))
        expect(type(value) is dict, 'HISTORY_PUBLIC_JSON_OBJECT')
        return value
    return read, descriptor, object_, held


def history_retired_receipt(context, proof, public):
    read, pin, obj, held = public
    role, binding, result, prerequisites = history_retire_spec(public, proof)
    receipt = obj('evidence/' + proof + '-retired.json')
    expect(set(receipt) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'role', 'proof_name', 'container_id',
        'created', 'terminal', 'binding', 'result', 'normal_remove', 'resource_after', 'prerequisites'}
        and type(receipt['schema']) is int and receipt['schema'] == 1 and receipt['scope'] == 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED'
        and receipt['run_id'] == context['run_id'] and receipt['source_sha'] == context['source_sha']
        and receipt['freeze_sha256'] == context['freeze_sha256'] and receipt['role'] == role and receipt['proof_name'] == proof
        and receipt['container_id'] == obj('evidence/' + proof + '-created.json')['id'], 'HISTORY_ACTUAL15_NORMAL_RETIRE_RECEIPT')
    for key, leaf in [('created', 'evidence/' + proof + '-created.json'), ('terminal', 'evidence/' + proof + '-terminal.json'),
        ('binding', binding), ('result', result), ('resource_after', 'evidence/' + proof + '-retired-resources.json')]:
        expect(history_exact(receipt[key], pin(leaf)), 'HISTORY_RETIRE_FULL_ORIGINAL_PUBLIC_PINS')
    removal = receipt['normal_remove']
    expect(type(removal) is dict and set(removal) == {'path', 'bytes', 'sha256'}
        and re.fullmatch(r'/run/audit/evidence/native-[0-9]{4,}[.]json', removal['path']), 'HISTORY_RETIRE_FIXED_PUBLIC_REMOVE_LEDGER')
    relative = removal['path'][len('/run/audit/'):]; rec = obj(relative)
    expect(removal == pin(relative) and set(rec) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256',
        'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'} and rec['stage'] == proof + '-exact-nonforce-remove'
        and type(rec['native_exit']) is int and rec['native_exit'] == 0 and type(rec['stdout_bytes']) is int and rec['stdout_bytes'] == 65
        and rec['stdout_sha256'] == digest((receipt['container_id'] + '\n').encode())
        and type(rec['stderr_bytes']) is int and rec['stderr_bytes'] == 0 and rec['stderr_sha256'] == digest(b'')
        and rec['private_environment_logs_omitted'] is True, 'HISTORY_REAL_EXACT_NORMAL_REMOVE_ZERO_FULL_STREAMS')
    resources = obj('evidence/' + proof + '-retired-resources.json')
    expect(set(resources) == {'schema', 'run_id', 'source_sha', 'freeze_sha256', 'retired_container_id', 'public_raw'}
        and resources['schema'] == 1 and resources['run_id'] == context['run_id'] and resources['source_sha'] == context['source_sha']
        and resources['freeze_sha256'] == context['freeze_sha256'] and resources['retired_container_id'] == receipt['container_id']
        and set(resources['public_raw']) == {'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl'}, 'HISTORY_SEALED_ORIGINAL_FOUR_RESOURCE_RAWS')
    raws = resources['public_raw']; network = json.loads(raws['network.json']); containers = [json.loads(row) for row in raws['containers.jsonl'].splitlines()]
    volumes = [json.loads(row) for row in raws['volumes.jsonl'].splitlines()]
    expect(history_exact(network, context['initial_network']) and history_exact(volumes, context['initial_volumes'])
        and raws['volumes.txt'] == '\n'.join(sorted(context['project'] + '_' + leaf for leaf in VOLUMES)) + '\n'
        and len(containers) == 2 and {row['id'] for row in containers} == set(base_roles(context)), 'HISTORY_RETIRED_ONLY_ORIGINAL_TWO_BASE_SIX_VOLUMES_NETWORK')
    for row in containers:
        role_ = base_roles(context)[row['id']]
        expect(row['labels']['com.docker.compose.service'] == role_ and row['labels']['com.docker.compose.project'] == context['project']
            and row['labels']['tianli.audit.run'] == context['run_id'] and set(row['networks']) == {context['project'] + '_isolation'}, 'HISTORY_RETIRED_BASE_ROLE_LABELS_NETWORK')
        original = context['initial_service_created'][role_]
        expect(set(row) == {'id', 'image_id', 'mounts', 'labels', 'networks', 'ports', 'read_only', 'privileged', 'network_mode'},
               'HISTORY_RETIRED_COMPLETE_BASE_PROJECTION')
        for key, source in [('id', 'id'), ('image_id', 'image'), ('labels', 'labels'), ('read_only', 'read_only'),
                            ('privileged', 'privileged'), ('network_mode', 'network_mode')]:
            expect(history_exact(row[key], original[source]), 'HISTORY_RETIRED_BASE_IMMUTABLE_FIELDS')
        expect(history_mount_rows(row['mounts']) == history_mount_rows(original['mounts'])
               and row['networks'][context['project'] + '_isolation']['NetworkID'] == context['network_id']
               and all(value is None for value in (row['ports'] or {}).values()) and not original['port_bindings'],
               'HISTORY_RETIRED_BASE_EXACT_MOUNTS_NETWORK_PORTS')
    expect(history_exact(receipt['prerequisites'], prerequisites), 'HISTORY_EXACT_ORDERED_RETIRE_PREREQUISITES')
    return receipt


def current_reader_archive(context):
    public, producer, migration = current_reader_prerequisites(context)
    read, pin, obj, held = public; root = Path(context['run_dir'])
    leaves = ['manifest.json', 'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
        'actual-run-context.json', 'preparation-source-binding.json',
        'evidence/legacy-history-producer-created.json', 'evidence/legacy-history-producer-terminal.json',
        'evidence/audit-legacy-history-producer-container-created.json',
        'evidence/audit-legacy-history-producer-source-binding.json', 'evidence/legacy-history-producer-result.json',
        'evidence/legacy-history-producer-retired.json', 'evidence/legacy-history-producer-retired-resources.json',
        'evidence/register-legacy-history-producer-created.json', 'evidence/register-legacy-history-producer-terminal.json',
        'evidence/register-legacy-history-producer-result.json',
        'evidence/driver-created.json', 'evidence/driver-terminal.json', 'evidence/ms4-migration-container-created.json',
        'evidence/ms4-migration-source-binding.json', 'evidence/ms4-migration-normal.json',
        'evidence/driver-retired.json', 'evidence/driver-retired-resources.json',
        'evidence/register-driver-created.json', 'evidence/register-driver-terminal.json', 'evidence/register-driver-result.json']
    leaves += ['evidence/current-structure-upgrade-' + suffix for suffix in
        ['created.json', 'terminal.json', 'result.json', 'shutdown.json', 'retired.json', 'retired-resources.json']]
    leaves += ['evidence/current-structure-upgrade-cli-%02d.%s' % (number, suffix)
               for number in range(1, 17) for suffix in ['created.json', 'terminal.json', 'stdout', 'stderr']]
    for proof in ['legacy-history-producer', 'driver', 'current-structure-upgrade']:
        receipt = obj('evidence/' + proof + '-retired.json')
        relative = receipt['normal_remove']['path'][len('/run/audit/'):]
        expect(re.fullmatch(r'evidence/native-[0-9]{4,}[.]json', relative), 'READER_ARCHIVE_ONLY_REAL_REMOVE_NATIVE_POINTER')
        leaves.append(relative)
    leaves += ['history-source-review.json', 'history-source-publication.json']
    expect(len(leaves) == len(set(leaves)), 'READER_ARCHIVE_UNIQUE_PUBLIC_INPUTS')
    parent = (root / 'evidence/nonempty-history-phases'); parent.mkdir(exist_ok=True)
    expect(parent.is_dir() and not parent.is_symlink() and not parent.is_junction(), 'READER_ARCHIVE_REGULAR_PARENT')
    output = parent / 'before-current-reader'
    expect(not output.exists() and not output.is_symlink(), 'READER_ARCHIVE_CREATE_ONLY')
    output.mkdir(); rows = []; saved = {}
    stat_keys = ('st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    for relative in leaves:
        path = root / relative
        expect(path.is_file() and not path.is_symlink() and not path.is_junction(), 'READER_ARCHIVE_REAL_ORIGINAL_PUBLIC_FILE')
        stat = path.stat(); before9 = tuple(getattr(stat, key) for key in stat_keys); raw = path.read_bytes()
        expect(len(raw) == stat.st_size and (len(raw) > 0 or relative.endswith(('.stdout', '.stderr'))) and len(raw) <= 67108864
            and before9 == tuple(getattr(path.stat(), key) for key in stat_keys), 'READER_ARCHIVE_COMPLETE_NATIVE_STAT9')
        destination = output / relative; destination.parent.mkdir(parents=True, exist_ok=True)
        with destination.open('xb') as stream:
            expect(stream.write(raw) == len(raw), 'READER_ARCHIVE_COMPLETE_COPY'); stream.flush()
        expect(destination.read_bytes() == raw, 'READER_ARCHIVE_COMPLETE_COPY_READBACK')
        saved[relative] = (raw, before9)
        rows.append({'path': relative, 'bytes': len(raw), 'sha256': digest(raw), 'original_stat': dict(zip(stat_keys, before9))})
    record = {'schema': 1, 'phase': 'before-current-reader', 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'legacy_source_sha': LEGACY_SOURCE, 'files': rows, 'business_acceptance': 'NOT RUN'}
    sha = new(output / 'INDEX.json', record)
    for relative, (raw, native9) in saved.items():
        path = root / relative
        expect(path.read_bytes() == raw and tuple(getattr(path.stat(), key) for key in stat_keys) == native9,
            'READER_ARCHIVE_ORIGINAL_PUBLIC_BYTES_STAT9_HELD')
    return {'path': (output / 'INDEX.json').as_posix(), 'bytes': (output / 'INDEX.json').stat().st_size, 'sha256': sha}


def current_reader_prerequisites(context):
    public = history_public_reader(context)
    producer = history_producer_normal(context, public)
    history_retired_receipt(context, 'legacy-history-producer', public)
    migration = history_driver_normal(context, producer, public)
    history_retired_receipt(context, 'driver', public)
    history_upgrade_normal(context, public)
    history_retired_receipt(context, 'current-structure-upgrade', public)
    read, pin, obj, held = public
    for relative in list(held):
        read(relative)
    return public, producer, migration


def current_reader_mounts(context, facts):
    prepared = bound_created(context, 'prepare', 'audit-bootstrap')
    original_fixture = [row for row in prepared['mounts'] if row['Destination'] == '/run/audit-fixtures']
    expect(len(original_fixture) == 1 and original_fixture[0]['Type'] == 'volume'
           and original_fixture[0]['Name'] == context['project'] + '_fixture-secrets', 'READER_ORIGINAL_PREPARE_FIXTURE')
    receipt = {'fixture_mount': original_fixture[0]}
    expect(facts['command'] == ['/run/nonempty/legacy-current-reader.php', 'normal',
        '--freeze-bytes=' + str(context['freeze_bytes']), '--freeze-sha256=' + context['freeze_sha256']], 'READER_FIXED_NORMAL_FREEZE_COMMAND')
    wanted = [{'Type': 'bind', 'Source': host, 'Destination': destination, 'RW': destination == '/run/audit', 'Name': ''}
        for destination, host in {'/workspace': context['repo_dir'], '/source': context['repo_dir'],
            '/run/nonempty': context['prep_dir'], '/run/audit': context['run_dir']}.items()]
    for leaf, destination in [('app-secrets', '/run/audit-app'), ('fixture-secrets', '/run/audit-fixtures')]:
        rows = [mount for mount in facts['mounts'] if mount['Destination'] == destination]
        expect(len(rows) == 1 and rows[0]['Type'] == 'volume' and rows[0]['RW'] is False
            and rows[0]['Name'] == context['project'] + '_' + leaf and isinstance(rows[0]['Source'], str)
            and rows[0]['Source'], 'READER_ONLY_ORIGINAL_TWO_PRIVATE_RO')
        if leaf == 'fixture-secrets':
            expect(rows[0]['Source'] == receipt['fixture_mount']['Source'], 'READER_SAME_PREPARE_FIXTURE_PHYSICAL_SOURCE')
        wanted.append({'Type': 'volume', 'Source': rows[0]['Source'], 'Destination': destination,
            'RW': False, 'Name': rows[0]['Name']})
    actual = []; destinations = set()
    for mount in facts['mounts']:
        expect(mount['Destination'] not in destinations, 'READER_UNIQUE_MOUNT_DESTINATIONS'); destinations.add(mount['Destination'])
        if mount['Type'] == 'tmpfs':
            expect(mount['Destination'] in ['/tmp', '/current-runtime'] and mount['RW'] is True, 'READER_ONLY_TWO_NATIVE_TMPFS')
            continue
        actual.append(mount)
    expect(history_mount_rows(actual) == history_mount_rows(wanted) and set(facts['tmpfs']) == {'/tmp', '/current-runtime'},
        'READER_FOUR_BINDS_TWO_RO_VOLUMES_NO_OLD_WORKSPACE_OR_MIGRATOR')
    modes = [item for item in facts['tmpfs']['/current-runtime'].split(',') if item.startswith('mode=')]
    expect(len(modes) == 1 and re.fullmatch('mode=0?700', modes[0]), 'READER_PRIVATE_RUNTIME_MODE0700')
    return history_mount_rows(wanted)


def bind_current_reader(context):
    public, producer, migration = current_reader_prerequisites(context)
    read, pin, obj, held = public; role = 'audit-legacy-current-reader'
    facts = bound_created(context, 'legacy-current-reader', role)
    fresh = inspect(context, facts['id'], 'current-reader-bind-precheck'); owned(context, fresh, role); same_created(facts, fresh)
    expect(fresh['status'] == 'created' and fresh['pid'] == 0, 'READER_ONLY_SAME_UNSTARTED_CREATED')
    mounts = current_reader_mounts(context, facts); freeze = obj('preparation-source-binding.json')
    evidence = Path(context['run_dir']) / 'evidence'
    new(evidence / (role + '-container-created.json'), facts)
    new(evidence / (role + '-source-binding.json'), {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'role': role, 'container_id': facts['id'],
        'current_authority_sha256': digest((json.dumps(freeze['current_reader'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'legacy_authority_sha256': digest((json.dumps(freeze['nonempty_history'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': mounts, 'prerequisites': {'producer_retired': pin('evidence/legacy-history-producer-retired.json'),
            'driver_retired': pin('evidence/driver-retired.json'), 'upgrade_retired': pin('evidence/current-structure-upgrade-retired.json')}})
    for relative in list(held):
        read(relative)
    return facts['id']


def current_reader_bound(context):
    public, producer, migration = current_reader_prerequisites(context)
    role = 'audit-legacy-current-reader'; evidence = Path(context['run_dir']) / 'evidence'
    facts = bound_created(context, 'legacy-current-reader', role); cid = facts['id']
    helper_made = bound_created(context, 'register-legacy-current-reader', 'audit-bootstrap')
    helper_terminal = load(evidence / 'register-legacy-current-reader-terminal.json')
    helper_result = load(evidence / 'register-legacy-current-reader-result.json')
    same_created(helper_made, helper_terminal)
    expect(helper_terminal['status'] == 'exited' and helper_terminal['pid'] == 0 and helper_terminal['exit_code'] == 0
        and helper_result['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY' and helper_result['native_exit'] == 0
        and helper_result['binding'] == {'container_id': helper_made['id'],
            'entry_namespace_pid': helper_result['binding']['entry_namespace_pid'], 'source_sha': context['source_sha'],
            'created_sha256': digest((evidence / 'register-legacy-current-reader-created.json').read_bytes()),
            'freeze_sha256': context['freeze_sha256']}
        and type(helper_result['binding']['entry_namespace_pid']) is int and helper_result['binding']['entry_namespace_pid'] > 0,
        'READER_ORIGINAL_REGISTRATION_HELPER_NATIVE_ZERO')
    removals = [load(path) for path in sorted(evidence.glob('native-*.json'))
        if load(path).get('stage') == 'register-legacy-current-reader-exact-nonforce-remove']
    expect(len(removals) == 1 and removals[0]['native_exit'] == 0 and removals[0]['stdout_bytes'] == 65
        and removals[0]['stdout_sha256'] == digest((helper_made['id'] + '\n').encode())
        and removals[0]['stderr_bytes'] == 0 and removals[0]['stderr_sha256'] == digest(b''), 'READER_REGISTER_HELPER_ONLY_NORMAL_REMOVED')
    collect(context, base_roles(context) | {cid: role})
    current_facts = inspect(context, cid, 'current-reader-bound-same-created'); owned(context, current_facts, role); same_created(facts, current_facts)
    expect(current_facts['status'] == 'created' and current_facts['pid'] == 0, 'READER_BOUND_ONLY_SAME_UNSTARTED_CONTAINER')
    mounts = current_reader_mounts(context, facts); manifest = load(Path(context['run_dir']) / 'manifest.json')
    expect(set(manifest['services']) == {role, 'audit-mysql', 'audit-redis'} and manifest['services'][role]['id'] == cid
        and set(manifest['nonempty_history']['stages']) == {'audit-legacy-files-bootstrap', 'audit-legacy-history-producer', role},
        'READER_MANIFEST_EXACT_THREE_CURRENT_ROLES_THREE_ORIGINAL_STAGES')
    stage_pins = manifest['nonempty_history']['stages'][role]
    for key, suffix in [('created', '-container-created.json'), ('binding', '-source-binding.json')]:
        raw = (evidence / (role + suffix)).read_bytes()
        expect(stage_pins[key] == {'path': '/run/audit/evidence/' + role + suffix, 'bytes': len(raw), 'sha256': digest(raw)},
            'READER_REGISTERED_FULL_ORIGINAL_STAGE_PINS')
    binding = load(evidence / (role + '-source-binding.json')); freeze = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    read, pin, obj, held = public
    expect(load(evidence / (role + '-container-created.json')) == facts and binding == {
        'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'], 'role': role, 'container_id': cid,
        'current_authority_sha256': digest((json.dumps(freeze['current_reader'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'legacy_authority_sha256': digest((json.dumps(freeze['nonempty_history'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': mounts, 'prerequisites': {'producer_retired': pin('evidence/legacy-history-producer-retired.json'),
            'driver_retired': pin('evidence/driver-retired.json'), 'upgrade_retired': pin('evidence/current-structure-upgrade-retired.json')}}, 'READER_BIND_COMPLETE_QUALIFIED_SOURCE_AND_NORMAL_HANDOFF')
    for relative in list(held):
        read(relative)
    return cid


def current_reader_normal(context, public):
    read, pin, obj, held = public
    producer = history_producer_normal(context, public)
    history_retired_receipt(context, 'legacy-history-producer', public)
    history_driver_normal(context, producer, public)
    history_retired_receipt(context, 'driver', public)
    history_upgrade_normal(context, public)
    history_retired_receipt(context, 'current-structure-upgrade', public)
    proof = 'legacy-current-reader'; role = 'audit-legacy-current-reader'
    made = obj('evidence/' + proof + '-created.json'); terminal = obj('evidence/' + proof + '-terminal.json')
    cid = made['id']; own = bound_created(context, proof, role)
    expect(history_exact(made, own), 'CURRENT_READER_EXACT_ORIGINAL_CREATED')
    owned(context, terminal, role); same_created(made, terminal)
    expect(terminal['status'] == 'exited' and type(terminal['exit_code']) is int and terminal['exit_code'] == 0
        and terminal['pid'] == 0 and terminal['restart_count'] == 0, 'CURRENT_READER_REAL_NATIVE_ZERO_TERMINAL')
    mounts = current_reader_mounts(context, made); freeze = obj('preparation-source-binding.json')
    binding = obj('evidence/audit-legacy-current-reader-source-binding.json')
    expect(history_exact(binding, {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'role': role, 'container_id': cid,
        'current_authority_sha256': digest((json.dumps(freeze['current_reader'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'legacy_authority_sha256': digest((json.dumps(freeze['nonempty_history'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': mounts, 'prerequisites': {'producer_retired': pin('evidence/legacy-history-producer-retired.json'),
            'driver_retired': pin('evidence/driver-retired.json'), 'upgrade_retired': pin('evidence/current-structure-upgrade-retired.json')}}), 'CURRENT_READER_FULL_SOURCE_BINDING_NORMAL_HANDOFF')
    result = obj('evidence/' + proof + '-result.json')
    expect(set(result) == {'schema', 'scope', 'status', 'run_id', 'source_sha', 'freeze_sha256', 'container_id',
        'entry_namespace_pid', 'legacy_parent', 'identities', 'requests', 'normal_http_close', 'feature_profile',
        'files', 'reader_terminal', 'full_acceptance'} and result['schema'] == 1
        and result['scope'] == 'CURRENT_SOURCE_SINGLE_SELF_LEGACY_READS_ONLY'
        and result['status'] == 'VERIFIED_CURRENT_LEGACY_HISTORY_READS_NORMAL_CLOSED'
        and result['run_id'] == context['run_id'] and result['source_sha'] == context['source_sha']
        and result['freeze_sha256'] == context['freeze_sha256'] and result['container_id'] == cid
        and type(result['entry_namespace_pid']) is int and result['entry_namespace_pid'] > 0
        and history_exact(result['legacy_parent'], producer['parent'])
        and history_exact(result['identities'], producer['identities'])
        and result['files'] == 'VERIFIED_CURRENT_SOURCE_NORMAL_CLOSE'
        and result['reader_terminal'] == 'REQUIRED_FROM_ORIGINAL_NATIVE_TERMINAL'
        and result['full_acceptance'] == 'INCOMPLETE', 'CURRENT_READER_SAFE16_RESULT_SOURCE_AND_ORIGINAL_IDENTITIES')
    profile = result['feature_profile']
    expect(history_exact(profile, {'enabled': False, 'self_operated_mer_id': producer['self_operated_mer_id'],
        'scope': 'STRUCTURE_ONLY_NOT_BACKFILLED'}), 'CURRENT_READER_EXPLICIT_SINGLE_SELF_PROFILE_NOT_FINAL_MM')
    steps = ['customer_login', 'current_products', 'current_product_detail', 'current_reply_list', 'current_reply_config',
        'current_refunded_root_detail', 'current_completed_root_detail', 'current_refund_detail', 'current_original_public_image']
    expect(type(result['requests']) is list and len(result['requests']) == 9, 'CURRENT_READER_ALL_NINE_REAL_REQUESTS')
    for sequence, (row, step) in enumerate(zip(result['requests'], steps), 1):
        expect(history_exact(row, {'sequence': sequence, 'step': step, 'http': 200, 'seven_parts_equal': True}),
            'CURRENT_READER_ORDERED_REAL_NORMAL_WHOLE_SEVEN')
    close = result['normal_http_close']
    expect(set(close) == {'stopped', 'group', 'remaining_members', 'listener_open', 'first_terminal', 'term_attempted',
        'stop_signal', 'proc_close_exit', 'streams', 'waits'} and close['stopped'] is True
        and close['remaining_members'] == [] and close['listener_open'] is False and close['term_attempted'] is True
        and close['stop_signal'] == 15 and type(close['proc_close_exit']) is int
        and type(close['first_terminal']) is dict and close['first_terminal']['running'] is False
        and close['first_terminal']['signaled'] is True and close['first_terminal']['termsig'] == 15
        and type(close['streams']) is dict and set(close['streams']) == {'stdout', 'stderr'},
        'CURRENT_READER_ORIGINAL_SINGLE_TERM_GROUP_EMPTY_LISTENER_ABSENT_NATIVE_TERMINAL')
    for value in close['streams'].values():
        expect(set(value) == {'bytes', 'sha256'} and type(value['bytes']) is int and value['bytes'] >= 0
            and re.fullmatch('[a-f0-9]{64}', value['sha256']), 'CURRENT_READER_COMPLETE_OWN_NATIVE_STREAM_PINS')
    for relative in list(held): read(relative)
    return result


def current_reader_retired_receipt(context, public):
    """Validate the original complete normal result and exact retirement/resource joins."""
    read, pin, obj, held = public
    result = current_reader_normal(context, public); proof = 'legacy-current-reader'; cid = result['container_id']
    receipt = obj('evidence/' + proof + '-retired.json')
    expect(set(receipt) == {'schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'role', 'proof_name', 'container_id',
        'created', 'terminal', 'binding', 'result', 'normal_remove', 'resource_after', 'prerequisites'}
        and type(receipt['schema']) is int and receipt['schema'] == 1
        and receipt['scope'] == 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED'
        and receipt['run_id'] == context['run_id'] and receipt['source_sha'] == context['source_sha']
        and receipt['freeze_sha256'] == context['freeze_sha256'] and receipt['role'] == 'audit-legacy-current-reader'
        and receipt['proof_name'] == proof and receipt['container_id'] == cid, 'CURRENT_READER_ORIGINAL15_RETIRE_IDENTITY')
    for key, leaf in [('created', proof + '-created.json'), ('terminal', proof + '-terminal.json'),
        ('binding', 'audit-legacy-current-reader-source-binding.json'), ('result', proof + '-result.json'),
        ('resource_after', proof + '-retired-resources.json')]:
        expect(history_exact(receipt[key], pin('evidence/' + leaf)), 'CURRENT_READER_RETIRE_COMPLETE_ORIGINAL_PINS')
    removal = receipt['normal_remove']
    expect(type(removal) is dict and set(removal) == {'path', 'bytes', 'sha256'}
        and re.fullmatch(r'/run/audit/evidence/native-[0-9]{4,}[.]json', removal['path']), 'CURRENT_READER_RETIRE_FIXED_NORMAL_REMOVE_PATH')
    relative = removal['path'][len('/run/audit/'):]; rec = obj(relative)
    expect(history_exact(removal, pin(relative)) and set(rec) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256',
        'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'}
        and rec['stage'] == proof + '-exact-nonforce-remove' and type(rec['native_exit']) is int and rec['native_exit'] == 0
        and type(rec['stdout_bytes']) is int and rec['stdout_bytes'] == 65 and rec['stdout_sha256'] == digest((cid + '\n').encode())
        and type(rec['stderr_bytes']) is int and rec['stderr_bytes'] == 0 and rec['stderr_sha256'] == digest(b'')
        and rec['private_environment_logs_omitted'] is True, 'CURRENT_READER_ACTUAL_NONFORCE_REMOVE_NATIVE_ZERO_FULL_STREAMS')
    resources = obj('evidence/' + proof + '-retired-resources.json')
    expect(set(resources) == {'schema', 'run_id', 'source_sha', 'freeze_sha256', 'retired_container_id', 'public_raw'}
        and type(resources['schema']) is int and resources['schema'] == 1 and resources['run_id'] == context['run_id']
        and resources['source_sha'] == context['source_sha'] and resources['freeze_sha256'] == context['freeze_sha256']
        and resources['retired_container_id'] == cid
        and set(resources['public_raw']) == {'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl'},
        'CURRENT_READER_COMPLETE6_RESOURCE_RECEIPT')
    raws = resources['public_raw']; network = json.loads(raws['network.json'])
    volumes = [json.loads(row) for row in raws['volumes.jsonl'].splitlines()]
    containers = [json.loads(row) for row in raws['containers.jsonl'].splitlines()]
    expect(history_exact(network, context['initial_network']) and history_exact(volumes, context['initial_volumes'])
        and raws['volumes.txt'] == '\n'.join(sorted(context['project'] + '_' + leaf for leaf in VOLUMES)) + '\n'
        and len(containers) == 2 and {row['id'] for row in containers} == set(base_roles(context)),
        'CURRENT_READER_RETIRED_ORIGINAL_BASE_TWO_SIX_VOLUMES_AND_NETWORK')
    for row in containers:
        base_role = base_roles(context)[row['id']]
        original = context['initial_service_created'][base_role]
        expect(type(row) is dict and set(row) == {'id', 'image_id', 'mounts', 'labels', 'networks', 'ports',
            'read_only', 'privileged', 'network_mode'}, 'CURRENT_READER_COMPLETE_NINE_BASE_RESOURCE_PROJECTION')
        for key, source in [('id', 'id'), ('image_id', 'image'), ('labels', 'labels'), ('read_only', 'read_only'),
                ('privileged', 'privileged'), ('network_mode', 'network_mode')]:
            expect(history_exact(row[key], original[source]), 'CURRENT_READER_ORIGINAL_IMMUTABLE_BASE_PROJECTION')
        expect(type(row['mounts']) is list and type(original['mounts']) is list
            and sorted(json.dumps(entry, sort_keys=True) for entry in row['mounts'])
                == sorted(json.dumps(entry, sort_keys=True) for entry in original['mounts']),
            'CURRENT_READER_ORIGINAL_COMPLETE_BASE_MOUNT_MULTISET')
        network_name = context['project'] + '_isolation'
        expect(type(row['networks']) is dict and set(row['networks']) == set(original['networks']) == {network_name}
            and type(row['networks'][network_name]) is dict
            and row['networks'][network_name].get('NetworkID') == context['initial_network']['Id']
            and type(row['ports']) is dict and all(value is None for value in row['ports'].values())
            and not original['port_bindings'], 'CURRENT_READER_ORIGINAL_NETWORK_ID_AND_NO_PUBLISHED_PORTS')
    expect(history_exact(receipt['prerequisites'], {'driver_retired': pin('evidence/driver-retired.json'), 'upgrade_retired': pin('evidence/current-structure-upgrade-retired.json')}),
        'CURRENT_READER_STRICT_ORIGINAL_DRIVER_RETIRE_CHAIN')
    for relative in list(held): read(relative)
    return receipt


def retire_current_reader(context):
    root = Path(context['run_dir']); evidence = root / 'evidence'; public = history_public_reader(context)
    read, pin, obj, held = public; proof = 'legacy-current-reader'; role = 'audit-legacy-current-reader'
    for leaf in [proof + '-retired.json', proof + '-retired-resources.json']:
        path = evidence / leaf
        expect(not path.exists() and not path.is_symlink(), 'CURRENT_READER_RETIRE_CREATE_ONLY_NO_RETRY')
    result = current_reader_normal(context, public); cid = result['container_id']; label = proof + '-exact-nonforce-remove'
    for path in sorted(evidence.glob('native-*.json')):
        if re.fullmatch(r'native-[0-9]{4,}[.]json', path.name):
            expect(obj('evidence/' + path.name).get('stage') != label, 'CURRENT_READER_NO_PRIOR_REMOVE_ATTEMPT')
    remove_finished(context, cid, role, proof, base_roles(context) | {cid: role})
    removals = []
    for path in sorted(evidence.glob('native-*.json')):
        if re.fullmatch(r'native-[0-9]{4,}[.]json', path.name) and obj('evidence/' + path.name).get('stage') == label:
            removals.append('evidence/' + path.name)
    expect(len(removals) == 1, 'CURRENT_READER_ONE_REAL_ORDINARY_REMOVE')
    for relative in list(held): read(relative)
    snapshot = {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'freeze_sha256': context['freeze_sha256'], 'retired_container_id': cid,
        'public_raw': {leaf: read(leaf).decode('utf-8') for leaf in ['network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl']}}
    raws = snapshot['public_raw']; network = json.loads(raws['network.json'])
    volumes = [json.loads(row) for row in raws['volumes.jsonl'].splitlines()]
    containers = [json.loads(row) for row in raws['containers.jsonl'].splitlines()]
    expect(history_exact(network, context['initial_network']) and history_exact(volumes, context['initial_volumes'])
        and raws['volumes.txt'] == '\n'.join(sorted(context['project'] + '_' + leaf for leaf in VOLUMES)) + '\n'
        and len(containers) == 2 and {row['id'] for row in containers} == set(base_roles(context)),
        'CURRENT_READER_RETIRED_ONLY_ORIGINAL_BASE_TWO_SIX_VOLUMES_NETWORK')
    for row in containers:
        base_role = base_roles(context)[row['id']]
        original = context['initial_service_created'][base_role]
        expect(type(row) is dict and set(row) == {'id', 'image_id', 'mounts', 'labels', 'networks', 'ports',
            'read_only', 'privileged', 'network_mode'}, 'CURRENT_READER_COMPLETE_NINE_BASE_RESOURCE_PROJECTION')
        for key, source in [('id', 'id'), ('image_id', 'image'), ('labels', 'labels'), ('read_only', 'read_only'),
                ('privileged', 'privileged'), ('network_mode', 'network_mode')]:
            expect(history_exact(row[key], original[source]), 'CURRENT_READER_ORIGINAL_IMMUTABLE_BASE_PROJECTION')
        expect(type(row['mounts']) is list and type(original['mounts']) is list
            and sorted(json.dumps(entry, sort_keys=True) for entry in row['mounts'])
                == sorted(json.dumps(entry, sort_keys=True) for entry in original['mounts']),
            'CURRENT_READER_ORIGINAL_COMPLETE_BASE_MOUNT_MULTISET')
        network_name = context['project'] + '_isolation'
        expect(type(row['networks']) is dict and set(row['networks']) == set(original['networks']) == {network_name}
            and type(row['networks'][network_name]) is dict
            and row['networks'][network_name].get('NetworkID') == context['initial_network']['Id']
            and type(row['ports']) is dict and all(value is None for value in row['ports'].values())
            and not original['port_bindings'], 'CURRENT_READER_ORIGINAL_NETWORK_ID_AND_NO_PUBLISHED_PORTS')
    new(evidence / (proof + '-retired-resources.json'), snapshot)
    receipt = {'schema': 1, 'scope': 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED', 'run_id': context['run_id'],
        'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'], 'role': role, 'proof_name': proof,
        'container_id': cid, 'created': pin('evidence/' + proof + '-created.json'), 'terminal': pin('evidence/' + proof + '-terminal.json'),
        'binding': pin('evidence/audit-legacy-current-reader-source-binding.json'), 'result': pin('evidence/' + proof + '-result.json'),
        'normal_remove': pin(removals[0]), 'resource_after': pin('evidence/' + proof + '-retired-resources.json'),
        'prerequisites': {'driver_retired': pin('evidence/driver-retired.json'), 'upgrade_retired': pin('evidence/current-structure-upgrade-retired.json')}}
    new(evidence / (proof + '-retired.json'), receipt)
    expect(history_exact(current_reader_retired_receipt(context, public), receipt)
        and history_exact(obj('evidence/' + proof + '-retired-resources.json'), snapshot), 'CURRENT_READER_REAL15_RETIRE_READBACK')
    for relative in list(held): read(relative)
    return {'status': 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED', 'run_id': context['run_id'],
        'container_id': cid, 'receipt': pin('evidence/' + proof + '-retired.json')}
'''
_LIFECYCLE_SEAMS = (('def native(context, args, label):\n'
  "    result = subprocess.run(['docker', *args], capture_output=True)\n"
  "    evidence = Path(context['run_dir']) / 'evidence'\n"
  '    sequence = 1\n'
  "    while (evidence / ('native-%04d.json' % sequence)).exists():\n"
  '        sequence += 1\n'
  "    new(evidence / ('native-%04d.json' % sequence), {'stage': label, 'native_exit': result.returncode,\n"
  "        'stdout_bytes': len(result.stdout), 'stdout_sha256': digest(result.stdout),\n"
  "        'stderr_bytes': len(result.stderr), 'stderr_sha256': digest(result.stderr), "
  "'private_environment_logs_omitted': True})\n"
  "    expect(result.returncode == 0, 'NATIVE_' + label)\n"
  "    return result.stdout.decode('utf-8').strip()\n",
  'def native(context, args, label):\n'
  "    evidence = Path(context['run_dir']) / 'evidence'\n"
  '    sequence = 1\n'
  "    while (evidence / ('native-%04d.json' % sequence)).exists():\n"
  '        sequence += 1\n'
  "    result = history_process(context, ['docker', *args], 'process-native-%04d' % sequence)\n"
  "    new(evidence / ('native-%04d.json' % sequence), {'stage': label, 'native_exit': result.returncode,\n"
  "        'stdout_bytes': len(result.stdout), 'stdout_sha256': digest(result.stdout),\n"
  "        'stderr_bytes': len(result.stderr), 'stderr_sha256': digest(result.stderr), "
  "'private_environment_logs_omitted': True})\n"
  "    expect(result.returncode == 0, 'NATIVE_' + label)\n"
  "    return result.stdout.decode('utf-8').strip()\n"),
 ('def global_consumers(context, allowed):\n'
  "    all_ids = native(context, ['ps', '-aq', '--no-trunc'], 'global-consumers').splitlines()\n"
  '    if not all_ids:\n'
  '        return\n'
  '    template = \'{"id":{{json .Id}},"mounts":{{json .Mounts}},"networks":{{json '
  ".NetworkSettings.Networks}}}'\n"
  "    lines = native(context, ['inspect', '--format', template, *all_ids], "
  "'global-consumer-facts').splitlines()\n"
  "    private = {context['project'] + '_' + name for name in VOLUMES}\n"
  '    for line in lines:\n'
  '        row = json.loads(line)\n'
  "        volumes = {m.get('Name') for m in row['mounts'] if m.get('Type') == 'volume'}\n"
  "        joined = context['project'] + '_isolation' in row['networks']\n"
  '        if volumes & private or joined:\n'
  "            expect(row['id'] in allowed, 'FOREIGN_RESOURCE_CONSUMER')\n",
  'def global_consumers(context, allowed):\n'
  '    history_global_aliases(context, allowed, history_volume_roots(context))\n'),
 ("    result = subprocess.run(['docker', 'compose', '--project-name', context['project'], '--file', "
  "str(PREP / 'compose.yml'), *args], capture_output=True, env=env)",
  "    result = history_process(context, ['docker', 'compose', '--project-name', context['project'], "
  "'--file', str(PREP / 'compose.yml'), *args], 'process-compose-' + label, env)"),
 ("        workdir = '/legacy-workspace/crmeb' if service in ['audit-legacy-files-bootstrap', "
  "'audit-legacy-history-producer'] else '/workspace'",
  "        workdir = '/legacy-workspace/crmeb' if service in ['audit-legacy-files-bootstrap', "
  "'audit-legacy-history-producer'] else ('/workspace/crmeb' if service == 'audit-legacy-current-reader' "
  "else '/workspace')"),
 ("        'register-legacy-history-producer': [('legacy-history-producer', "
  "'audit-legacy-history-producer'), ('register-legacy-history-producer', 'audit-bootstrap')],",
  "        'register-legacy-history-producer': [('legacy-history-producer', "
  "'audit-legacy-history-producer'), ('register-legacy-history-producer', 'audit-bootstrap')],\n"
  "        'current-structure-upgrade': [('current-structure-upgrade', 'audit-bootstrap')],\n"
  "        'legacy-current-reader': [('legacy-current-reader', 'audit-legacy-current-reader')],\n"
  "        'register-legacy-current-reader': [('legacy-current-reader', 'audit-legacy-current-reader'), "
  "('register-legacy-current-reader', 'audit-bootstrap')],"),
 ("    base = base_roles(context)\n    if name == 'observe':",
  '    base = base_roles(context)\n'
  "    if name in ['driver-retire', 'current-structure-upgrade-create', 'current-structure-upgrade-start', "
  "'current-structure-upgrade-retire', 'current-reader-create', 'current-reader-bind', "
  "'current-reader-start', 'current-reader-retire', 'history-final-close']:\n"
  '        return history_extra_stage(name, context, seconds)\n'
  "    if name == 'observe':"),
 ("                         'legacy-history-producer', 'register-legacy-history-producer'], "
  "'KNOWN_OBSERVATION_PROOF')",
  "                         'legacy-history-producer', 'register-legacy-history-producer', "
  "'current-structure-upgrade',\n"
  "                         'legacy-current-reader', 'register-legacy-current-reader'], "
  "'KNOWN_OBSERVATION_PROOF')"),
 ("                  'register-legacy-history-producer': 'REGISTERED_NATIVE_RESOURCES_ONLY'}",
  "                  'register-legacy-history-producer': 'REGISTERED_NATIVE_RESOURCES_ONLY',\n"
  "                  'register-legacy-current-reader': 'REGISTERED_NATIVE_RESOURCES_ONLY'}"),
 ("        remove_finished(context, cid, 'audit-legacy-history-producer', 'legacy-history-producer', base | "
  "{cid: 'audit-legacy-history-producer'})",
  "        expect(not (evidence / 'legacy-history-producer-retired.json').exists(), "
  "'HISTORY_PRODUCER_RETIRE_ONCE')\n"
  "        remove_finished(context, cid, 'audit-legacy-history-producer', 'legacy-history-producer', base | "
  "{cid: 'audit-legacy-history-producer'})\n"
  "        history_retire_publish(context, 'legacy-history-producer')"),
 ("        'legacy-producer-create', 'legacy-producer-bind', 'legacy-producer-start'])",
  "        'legacy-producer-create', 'legacy-producer-bind', 'legacy-producer-start', 'driver-retire', "
  "'current-structure-upgrade-create', 'current-structure-upgrade-start', "
  "'current-structure-upgrade-retire', 'current-reader-create', 'current-reader-bind', "
  "'current-reader-start', 'current-reader-retire', 'history-final-close'])"),
 ("    else:\n        raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')\n    print",
  '    else:\n'
  "        raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')\n"
  "        expect(args.context, 'EXPLICIT_ORIGINAL_HISTORY_CONTEXT')\n"
  '        result = stage(args.stage, args.context, args.proof, args.seconds)\n'
  '    print'))
_COMPOSE_READER = b'  audit-legacy-current-reader:\n    <<: *php\n    working_dir: /workspace/crmeb\n    tmpfs: [/tmp, "/current-runtime:mode=0700"]\n    command: [/run/nonempty/legacy-current-reader.php, normal, "--freeze-bytes=${NONEMPTY_FREEZE_BYTES:?required}", "--freeze-sha256=${NONEMPTY_FREEZE_SHA256:?required}"]\n    volumes:\n      - ${AUDIT_REPO_DIR:?required}:/workspace:ro\n      - ${AUDIT_REPO_DIR:?required}:/source:ro\n      - ${MS4_PREP_DIR:?required}:/run/nonempty:ro\n      - ${AUDIT_RUN_DIR:?required}:/run/audit\n      - app-secrets:/run/audit-app:ro\n      - fixture-secrets:/run/audit-fixtures:ro\n'

_ADOPTION_SEAMS = (
    ('"""Nonempty-history source preparation. Runtime wiring remains incomplete."""',
     '"""Adopt independently reviewed history sources; each runtime result requires its actual original evidence."""'),
    ("    expect(not Path(output).resolve().is_relative_to(repo), 'PROOF_OUTSIDE_REPO')\n",
     "    expect(not Path(output).resolve().is_relative_to(repo), 'PROOF_OUTSIDE_REPO')\n"
     "    expect(not Path(output).resolve().is_relative_to(PREP), 'PROOF_OUTSIDE_PREPARATION_SOURCE')\n"),
    ("'nonempty_history_runtime': 'NOT_READY_FOR_RUNTIME'", "'nonempty_history_runtime': 'SOURCE_ADOPTED_RUNTIME_NOT_RUN'"),
    ("    # This candidate currently qualifies source components only. Do not start\n"
     "    # the old empty-history runner before producer/handoff/close are wired.\n"
     "    raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')\n",
     '    """Verify the real final source and retain its public originals before native preparation."""\n'),
    ("    expect(not root.is_relative_to(Path(binding['requested_repo_source']).resolve()), 'RUN_OUTSIDE_SOURCE')\n",
     "    for source_root in [Path(binding['requested_repo_source']),\n"
     "                        Path(binding['nonempty_history']['requested_legacy_repo_source']), PREP]:\n"
     "        expect(not root.is_relative_to(source_root.resolve(strict=True)), 'RUN_OUTSIDE_BOTH_APPLICATIONS_AND_PREPARATION')\n"),
    ("    expect((directory / 'preparation-source-binding.json').read_bytes() == frozen, 'FREEZE_COPY_BYTES')\n",
     "    expect((directory / 'preparation-source-binding.json').read_bytes() == frozen, 'FREEZE_COPY_BYTES')\n"
     "    history_copy_source_aliases(context, binding)\n"),
    ("    collect(context, expected, initial_services={'audit-mysql': mysql, 'audit-redis': redis})\n",
     "    collect(context, expected, initial_services={'audit-mysql': mysql, 'audit-redis': redis})\n"
     "    history_created_network_subnet(context, binding)\n"),
    ("    binding = load(binding_file)\n    if source_required:\n",
     "    binding = load(binding_file)\n    history_run_source_aliases(context, binding)\n    if source_required:\n"),
    ("    else:\n        raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')\n"
     "        expect(args.context, 'EXPLICIT_ORIGINAL_HISTORY_CONTEXT')\n",
     "    else:\n        expect(args.context, 'EXPLICIT_ORIGINAL_HISTORY_CONTEXT')\n"),
)


def fixed_seams() -> tuple[tuple[bytes, bytes], ...]:
    extra = tuple((before.encode('utf-8'), after.encode('utf-8')) for before, after in _LIFECYCLE_SEAMS)
    insertion = (_HISTORY_RUNTIME + '\n\n' + _READER_RUNTIME + '\n\n').encode()
    adoption = tuple((before.encode('utf-8'), after.encode('utf-8')) for before, after in _ADOPTION_SEAMS)
    return projection_seams() + extra + ((b'def prepare(binding_file, run_root):',
        insertion + b'def prepare(binding_file, run_root):'),) + adoption


def transform_compose(raw: bytes) -> bytes:
    _require(type(raw) is bytes and len(raw) == 4884
             and hashlib.sha256(raw).hexdigest() == '63ecc33ea0b821cccc149dceff5de26ef54ad1fdcf2922b43fc55ad21f4e0ea9',
             'HISTORY_COMPOSE_EXACT_ORIGINAL_OVERLAY')
    anchor = b'  audit-mysql:\n'
    _require(raw.count(anchor) == 1, 'HISTORY_COMPOSE_UNIQUE_READER_INSERTION')
    result = raw.replace(anchor, _COMPOSE_READER + anchor, 1)
    _require(reverse_compose(result) == raw, 'HISTORY_COMPOSE_COMPLETE_REVERSE')
    return result


def reverse_compose(candidate: bytes) -> bytes:
    _require(type(candidate) is bytes and candidate.count(_COMPOSE_READER) == 1, 'HISTORY_COMPOSE_UNIQUE_REVERSE')
    raw = candidate.replace(_COMPOSE_READER, b'', 1)
    _require(len(raw) == 4884 and hashlib.sha256(raw).hexdigest() == '63ecc33ea0b821cccc149dceff5de26ef54ad1fdcf2922b43fc55ad21f4e0ea9',
             'HISTORY_COMPOSE_COMPLETE_ORIGINAL_PIN')
    return raw

def _require(value: bool, label: str) -> None:
    if not value:
        raise ValueError(label)


def _definitions(raw: bytes) -> dict[str, bytes]:
    lines = raw.splitlines(keepends=True)
    return {node.name: b"".join(lines[node.lineno - 1:node.end_lineno])
            for node in ast.parse(raw).body if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef))}


def transform_host(raw: bytes) -> bytes:
    """Transform only the exact archived R3; do not import or execute its code."""
    _require(type(raw) is bytes and len(raw) == BASE_BYTES
             and hashlib.sha256(raw).hexdigest() == BASE_SHA256, "HISTORY_HOST_EXACT_ARCHIVED_R3")
    candidate = raw
    for before, after in fixed_seams():
        _require(candidate.count(before) == 1, "HISTORY_HOST_UNIQUE_LITERAL_SEAM")
        candidate = candidate.replace(before, after, 1)
    original = _definitions(raw); updated = _definitions(candidate)
    changed = {"freeze", "verify_freeze", "legacy_handoff_source", "main", "native", "compose", "owned", "global_consumers", "proof_expected", "stage", "prepare", "current"}
    additions = {"history_source_bytes", "history_source_json", "history_source_pointer",
                 "history_source_equal", "history_source_projection", "history_source_review", "current_reader_authority"}
    additions |= set(_definitions((_HISTORY_RUNTIME + "\n\n" + _READER_RUNTIME).encode()))
    _require(b"NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED" not in candidate,
             "HISTORY_FINAL_ADMISSION_USES_REAL_REVIEW_AND_SOURCE_ALIASES")
    _require(set(updated) == set(original) | additions
             and all(updated[name] == body for name, body in original.items() if name not in changed),
             "HISTORY_HOST_ALL_OTHER_FUNCTIONS_WHOLE_BYTES_HELD")
    _require(reverse_host(candidate) == raw, "HISTORY_HOST_COMPLETE_REVERSE_TO_ARCHIVED_R3")
    return candidate


def reverse_host(candidate: bytes) -> bytes:
    """Recover and verify the complete original Host, with no source-file reads."""
    _require(type(candidate) is bytes, "HISTORY_HOST_REVERSE_BYTES")
    original = candidate
    for before, after in reversed(fixed_seams()):
        _require(original.count(after) == 1, "HISTORY_HOST_UNIQUE_REVERSE_SEAM")
        original = original.replace(after, before, 1)
    _require(len(original) == BASE_BYTES and hashlib.sha256(original).hexdigest() == BASE_SHA256,
             "HISTORY_HOST_REVERSE_COMPLETE_ORIGINAL_SHA256")
    return original
