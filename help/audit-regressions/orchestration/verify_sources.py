#!/usr/bin/env python3
"""Read-only source archive verification; never execute archived programs."""

import ast
import builtins
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys
import symtable


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


def check_declared_authority_globals(path):
    raw = path.read_bytes()
    tree = ast.parse(raw, filename=str(path))
    require(any(isinstance(node, ast.Import) and any(alias.name == 'ast' for alias in node.names)
                for node in tree.body), 'Current authority must explicitly import ast')
    table = symtable.symtable(raw.decode('utf-8'), str(path), 'exec')
    scopes = [child for child in table.get_children() if child.get_name() == 'source_authority']
    require(len(scopes) == 1, 'Current authority definition missing or duplicated')
    bound = {symbol.get_name() for symbol in table.get_symbols()
             if symbol.is_assigned() or symbol.is_imported() or symbol.is_namespace()}

    def references(scope):
        names = {symbol.get_name() for symbol in scope.get_symbols()
                 if symbol.is_global() and symbol.is_referenced()}
        for child in scope.get_children():
            names.update(references(child))
        return names

    missing = references(scopes[0]) - bound - set(dir(builtins))
    require(not missing, 'Undeclared current authority globals: ' + ','.join(sorted(missing)))


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
    for entry in ['materializer/prepare.py', 'validation/run.py']:
        check_declared_authority_globals(root / 'sources' / current_package / entry)
    for rel in manifest['superseded_profile_only_missing_programs']:
        require('sources/' + rel not in expected, 'Draft status must be updated before adding: ' + rel)
    for rel in manifest['required_design_clarifications']:
        require('sources/' + rel in expected, 'Design clarification missing: ' + rel)
    dependencies = load_json((root / 'runtime-dependencies.json').read_bytes())
    require(dependencies['runtime_ready'] is False, 'Runtime readiness cannot be inferred from source verification')
    print(json.dumps({
        'status': 'PASS_SOURCE_ARCHIVE_ONLY',
        'files': len(rows),
        'bytes': total_bytes,
        'packages': len(packages),
        'python_ast_parsed': python_count,
        'json_parsed': json_count,
        'current_source_authority_global_checks': 2,
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
