#!/usr/bin/env python3
"""Read-only source archive verification; never execute archived programs."""

import ast
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys


class ArchiveError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise ArchiveError(message)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate JSON key')
        result[key] = value
    return result


def load_json(raw):
    return json.loads(raw.decode('utf-8'), object_pairs_hook=unique_object)


def verify_public_donors(root, manifest):
    """Check only the fixed public author inputs; historical Run evidence is absent."""
    core_package = 'integrated-normal-and-b-source-tree-source-r3'
    normal_package = 'currentb54-next-normal-preparation-r3'
    origin = 'H:/天俪商城/artifacts/project-organization-20261023'
    require(manifest['public_donor_origins'] == {
        core_package: origin, normal_package: origin}, 'Fixed public donor origins')
    core_pin = 'sources/' + core_package + '/PINS.json'
    normal_pin = 'sources/' + normal_package + '/normal-source/PINS.json'
    core_raw = (root / core_pin).read_bytes()
    normal_raw = (root / normal_pin).read_bytes()
    require((len(core_raw), hashlib.sha256(core_raw).hexdigest()) ==
            (93140, '67c9baf29f98230cd7a1e0bdd70157f2b3d1ac30b48adab6e241a84f51995b62'),
            'Exact original public Core/B source PINS')
    require((len(normal_raw), hashlib.sha256(normal_raw).hexdigest()) ==
            (14595, '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'),
            'Exact original public normal18 source PINS')
    core, normal = load_json(core_raw), load_json(normal_raw)
    core_names = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php',
                  'seed-source-inverse.json', 'interface-v1.json', 'source-derivation.json',
                  'normal-close-ms4-fulfillment.py', 'verify-zero-ms4-fulfillment.py']
    b_names = [name for name in core['members'] if name.startswith('b/') and (
        name.startswith('b/candidate/') or name.startswith('b/browser-v1/')
        or name.startswith('b/root-source/') or name == 'b/current-core-aux/CORE-AUX-AUTHORITY.json')]
    require(len(b_names) == 31 and len(normal['source_members18']) == 18
            and len(set(normal['source_members18'])) == 18, 'Fixed public B31/normal18 sets')
    groups = {
        'core9': [core_pin] + ['sources/' + core_package + '/core/actual-profile/' + name for name in core_names],
        'b31': ['sources/' + core_package + '/' + name for name in b_names],
        'normal18': [normal_pin] + ['sources/' + normal_package + '/normal-source/' + name
                                  for name in normal['source_members18']],
    }
    require(manifest['public_donor_required'] == groups, 'Exact public donor allowlist')
    expected = {name for names in groups.values() for name in names}
    require(len(expected) == 60, 'Fixed public donor sixty files')
    indexed = {row['path']: row for row in manifest['files']}
    actual = {row['path'] for row in manifest['files'] if 'origin_root' in row}
    require(actual == expected, 'Public donor origin fields only on fixed sources')
    for name in expected:
        row = indexed[name]
        require(row['origin_root'] == origin
                and row['package'] in [core_package, normal_package], 'Public donor logical origin')
        if name in [core_pin, normal_pin]:
            continue
        if row['package'] == core_package:
            relative = name[len('sources/' + core_package + '/'):]
            wanted = core['members'][relative]
        else:
            relative = name[len('sources/' + normal_package + '/normal-source/'):]
            wanted = normal['files'][relative]
            require(wanted['path'] == row['origin_root'] + '/' + row['origin_relative_path'],
                    'Original normal18 logical source path')
        require({key: row[key] for key in ['bytes', 'sha256']} ==
                {key: wanted[key] for key in ['bytes', 'sha256']},
                'Public donor full bytes match original source PINS')
    return len(expected)


def main():
    root = Path(__file__).resolve().parent
    manifest = load_json((root / 'source-manifest.json').read_bytes())
    require(manifest['schema'] == 2, 'Unsupported manifest schema')
    require(manifest['status'] == 'SOURCE_ARCHIVE_RUNTIME_NOT_READY', 'Unexpected archive status')
    rows = manifest['files']
    require(type(rows) is list and len(rows) == manifest['file_count'], 'File count mismatch')
    expected = set()
    total_bytes = 0
    python_count = 0
    json_count = 0
    packages = set()
    for row in rows:
        rel = row['path']
        require(type(rel) is str and '\\' not in rel, 'Invalid archive path')
        pure = PurePosixPath(rel)
        require(not pure.is_absolute() and pure.parts[0] == 'sources', 'Path outside sources')
        require('..' not in pure.parts and str(pure) == rel, 'Noncanonical archive path')
        require(rel not in expected, 'Duplicate manifest path')
        expected.add(rel)
        require(type(row['bytes']) is int and row['bytes'] >= 0, 'Invalid file length')
        require(re.fullmatch(r'[0-9a-f]{64}', row['sha256']) is not None, 'Invalid hash')
        require(row['origin_relative_path'] == '/'.join(pure.parts[1:]), 'Origin path mismatch')
        require(row['package'] == pure.parts[1], 'Source package mismatch')
        path = root
        for part in pure.parts:
            path = path / part
            require(not path.is_symlink(), 'Archive symlink: ' + rel)
        require(path.is_file(), 'Missing source: ' + rel)
        raw = path.read_bytes()
        require(len(raw) == row['bytes'], 'Length mismatch: ' + rel)
        require(hashlib.sha256(raw).hexdigest() == row['sha256'], 'Hash mismatch: ' + rel)
        if path.suffix == '.py' and '.fragment.' not in path.name:
            ast.parse(raw, filename=rel)
            python_count += 1
        elif path.suffix == '.json':
            load_json(raw)
            json_count += 1
        total_bytes += len(raw)
        packages.add(row['package'])
    actual = set()
    for path in (root / 'sources').rglob('*'):
        require(not path.is_symlink(), 'Unexpected source symlink')
        if path.is_file():
            actual.add(path.relative_to(root).as_posix())
    require(actual == expected, 'Unexpected or missing archive member')
    require(total_bytes == manifest['total_bytes'], 'Total byte count mismatch')
    for rel in manifest['historical_full_a_required']:
        require('sources/' + rel in expected, 'Historical pipeline source missing: ' + rel)
    current = manifest['current_full_a_required']
    require(len(current) == len(set(current)) == 18, 'Current pipeline must contain all 18 members')
    for rel in current:
        require('sources/' + rel in expected, 'Current pipeline source missing: ' + rel)
    current_packages = {PurePosixPath(rel).parts[0] for rel in current}
    require(len(current_packages) == 1, 'Current pipeline spans multiple packages')
    current_package = next(iter(current_packages))
    for entry in ['materializer/prepare.py', 'validation/run.py', 'launcher/launch.py']:
        require(current_package + '/' + entry in current, 'Current orchestration entry missing: ' + entry)
    for rel in manifest['superseded_profile_only_missing_programs']:
        require('sources/' + rel not in expected, 'Draft status must be updated before adding: ' + rel)
    for rel in manifest['required_design_clarifications']:
        require('sources/' + rel in expected, 'Design clarification missing: ' + rel)
    public_donors = verify_public_donors(root, manifest)
    dependencies = load_json((root / 'runtime-dependencies.json').read_bytes())
    require(dependencies['runtime_ready'] is False, 'Runtime readiness cannot be inferred from source verification')
    print(json.dumps({
        'status': 'PASS_SOURCE_ARCHIVE_ONLY',
        'files': len(rows),
        'bytes': total_bytes,
        'packages': len(packages),
        'python_ast_parsed': python_count,
        'json_parsed': json_count,
        'public_donors_verified': public_donors,
        'archived_programs_executed': False,
        'runtime_ready': False,
        'runtime_dependencies': 'runtime-dependencies.json',
    }, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ArchiveError, KeyError, OSError, ValueError, SyntaxError) as error:
        print(json.dumps({'status': 'FAIL_SOURCE_ARCHIVE', 'error': str(error)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(1)
