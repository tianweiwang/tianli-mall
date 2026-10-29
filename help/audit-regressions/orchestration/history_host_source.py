"""Finite byte transformation of the archived nonempty-history Host R3.

This internal author module has no CLI, filesystem reads or Host execution.
The candidate still refuses prepare and runtime dispatch.  A later independent
review supplies real external source bindings; this module supplies none.
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


def fixed_seams() -> tuple[tuple[bytes, bytes], ...]:
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
    changed = {"freeze", "verify_freeze", "legacy_handoff_source", "main"}
    additions = {"history_source_bytes", "history_source_json", "history_source_pointer",
                 "history_source_equal", "history_source_projection", "history_source_review", "current_reader_authority"}
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
