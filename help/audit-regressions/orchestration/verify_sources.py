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


def literal_values(tree):
    result = {}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            try:
                result[node.targets[0].id] = ast.literal_eval(node.value)
            except (TypeError, ValueError):
                pass
    return result


def check_pipeline_schema(root, package, source_commit, size, steps):
    folder = root / 'sources' / package
    paths = [folder / 'materializer/prepare.py', folder / 'validation/run.py', folder / 'launcher/launch.py']
    trees = [ast.parse(path.read_bytes(), filename=str(path)) for path in paths]
    values = [literal_values(tree) for tree in trees]
    key_name = 'CURRENT_M_RESULT_KEYS' + str(size)
    keys = values[0][key_name]
    require(type(keys) is list and len(keys) == len(set(keys)) == size
            and keys == values[1][key_name] == values[2][key_name], 'Strict pipeline result schema mismatch')
    require(values[0]['head'] == values[1]['SOURCE'] == source_commit
            and values[0]['COMMIT_CHAIN'] == values[1]['COMMIT_CHAIN']
            and len(values[0]['COMMIT_CHAIN']) == steps
            and values[0]['COMMIT_CHAIN'][-1]['source_sha'] == source_commit, 'Pipeline source or lineage mismatch')
    result_nodes = [node for node in ast.walk(trees[0]) if isinstance(node, ast.Dict)
                    and any(isinstance(key, ast.Constant) and key.value == 'materialize_source_inputs' for key in node.keys)]
    require(len(result_nodes) == 1 and [key.value for key in result_nodes[0].keys] == keys,
            'Materializer must emit the exact declared result fields')
    main = [node for node in trees[1].body if isinstance(node, ast.FunctionDef) and node.name == 'main']
    require(len(main) == 1, 'Driver main missing or duplicated')
    expected = ast.dump(ast.parse('set(actual_setup) == set(' + key_name + ') and len(actual_setup) == ' + str(size), mode='eval').body)
    require(any(isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == 'require'
                and node.args and ast.dump(node.args[0]) == expected for node in ast.walk(main[0])),
            'Driver lacks its exact current result admission guard')
    expected = ast.dump(ast.parse('set(value) == set(' + key_name + ') and len(value) == ' + str(size), mode='eval').body)
    require(any(isinstance(node, ast.Assert) and ast.dump(node.test) == expected for node in ast.walk(trees[2])),
            'Launcher lacks its exact current result admission guard')
    publication = load_json((folder / 'validation/PROFILE-PUBLICATION.json').read_bytes())
    profile_pins = load_json((folder / 'validation/PROFILE-PINS.json').read_bytes())
    driver_pins = load_json((folder / 'validation/PINS.json').read_bytes())
    require(publication['source_sha'] == source_commit and publication['finite_contexts']['commit_chain'] == values[0]['COMMIT_CHAIN']
            and len(publication['selected9']) == 9, 'Publication source or complete profile mismatch')
    for row in publication['selected9']:
        raw = (folder / 'validation/actual-profile' / row['path']).read_bytes()
        require(profile_pins['members'][row['path']] == {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
                == {field: row[field] for field in ['bytes', 'sha256']}, 'Profile source pin mismatch')
    for name, pin in driver_pins['members'].items():
        raw = (folder / 'validation' / name).read_bytes()
        require(pin == {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}, 'Driver source pin mismatch')
    if size == 33:
        require('current_881_paid_producer_failure_retirement' in keys
                and 'current_2e4_paid_producer_failure_retirement' in keys
                and values[0]['CURRENT_M_RESULT_KEYS32'] == values[1]['CURRENT_M_RESULT_KEYS32']
                == [name for name in keys if name != 'current_881_paid_producer_failure_retirement'],
                'Current33 must preserve the complete historical32 schema')


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
    for package, members in manifest['required_pipeline_members'].items():
        require(len(members) == len(set(members)) == 18 and all(PurePosixPath(rel).parts[0] == package for rel in members),
                'Incomplete historical pipeline member set')
        for rel in members:
            require('sources/' + rel in expected, 'Preserved complete pipeline source missing: ' + rel)
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
    previous = manifest['previous_current_full_a_required']
    require(len(previous) == len(set(previous)) == 18, 'Previous complete pipeline must remain present')
    previous_packages = {PurePosixPath(rel).parts[0] for rel in previous}
    require(len(previous_packages) == 1 and all('sources/' + rel in expected for rel in previous),
            'Previous complete pipeline namespace mismatch')
    previous_package = next(iter(previous_packages))
    for entry in ['materializer/prepare.py', 'validation/run.py']:
        check_declared_authority_globals(root / 'sources' / previous_package / entry)
    check_pipeline_schema(root, previous_package, '8819524a60028090c55bdcd3a9da05e50eaf19d0', 32, 16)
    check_pipeline_schema(root, current_package, manifest['current_orchestration_source_commit'], 33, 17)
    authors = manifest['current_author_code_required']
    author_names = {
        'assemble.py', 'source_reader.py', 'author_support.py', 'authority-current.py.fragment',
        'prepare-inputs.py', 'profile-author.py', 'retirement-author.py', 'DESIGN.md',
    }
    require(len(authors) == len(set(authors)) == 8, 'Current author source must contain all 8 members')
    require(set(authors) == {current_package + '/' + name for name in author_names},
            'Current author source paths do not match the complete author package')
    require(not set(authors).intersection(current), 'Author code cannot replace pipeline members')
    for rel in authors:
        require('sources/' + rel in expected, 'Current author source missing: ' + rel)
    local_modules = {'source_reader', 'author_support'}
    previous_authors = manifest['previous_current_author_code_required']
    require(len(previous_authors) == len(set(previous_authors)) == 8
            and set(previous_authors) == {previous_package + '/' + name for name in author_names}
            and all('sources/' + rel in expected for rel in previous_authors), 'Previous author package must remain complete')
    for package in [current_package, previous_package]:
        for name in author_names:
            if not name.endswith('.py'):
                continue
            tree = ast.parse((root / 'sources' / package / name).read_bytes(), filename=name)
            for node in ast.walk(tree):
                modules = []
                if isinstance(node, ast.Import):
                    modules = [alias.name.split('.')[0] for alias in node.names]
                elif isinstance(node, ast.ImportFrom):
                    require(node.level == 0 and node.module is not None, 'Unexpected relative author import')
                    modules = [node.module.split('.')[0]]
                require(all(module in sys.stdlib_module_names or module in local_modules for module in modules),
                        'Unarchived author module dependency: ' + name)
    for rel in manifest['superseded_profile_only_missing_programs']:
        require('sources/' + rel not in expected, 'Draft status must be updated before adding: ' + rel)
    for rel in manifest['required_design_clarifications']:
        require('sources/' + rel in expected, 'Design clarification missing: ' + rel)
    dependencies = load_json((root / 'runtime-dependencies.json').read_bytes())
    require(dependencies['runtime_ready'] is False, 'Runtime readiness cannot be inferred from source verification')
    require(dependencies['current_source']['package'] == current_package, 'Current package metadata mismatch')
    require(dependencies['current_source']['source_commit'] == manifest['current_orchestration_source_commit'],
            'Current business source metadata mismatch')
    print(json.dumps({
        'status': 'PASS_SOURCE_ARCHIVE_ONLY',
        'files': len(rows),
        'bytes': total_bytes,
        'packages': len(packages),
        'python_ast_parsed': python_count,
        'json_parsed': json_count,
        'current_source_authority_global_checks': 2,
        'preserved_previous_authority_global_checks': 2,
        'strict_current_M_result_fields': 33,
        'strict_previous_M_result_fields': 32,
        'current_author_code_members': len(authors),
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
