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
    if size == 34:
        require('current_0c13_paid_producer_failure_retirement' in keys
                and values[0]['CURRENT_M_RESULT_KEYS33'] == values[1]['CURRENT_M_RESULT_KEYS33']
                == values[2]['CURRENT_M_RESULT_KEYS33']
                == [name for name in keys if name != 'current_0c13_paid_producer_failure_retirement'],
                'Current34 must preserve the complete historical33 schema')

    if size == 35:
        require('current_28dc_paid_producer_failure_retirement' in keys
                and values[0]['CURRENT_M_RESULT_KEYS34'] == values[1]['CURRENT_M_RESULT_KEYS34']
                == values[2]['CURRENT_M_RESULT_KEYS34']
                == [name for name in keys if name != 'current_28dc_paid_producer_failure_retirement'],
                'Current35 must preserve the complete historical34 schema')


    if size == 36:
        require('current_175f_paid_producer_failure_retirement' in keys
                and values[0]['CURRENT_M_RESULT_KEYS35'] == values[1]['CURRENT_M_RESULT_KEYS35']
                == values[2]['CURRENT_M_RESULT_KEYS35']
                == [name for name in keys if name != 'current_175f_paid_producer_failure_retirement'],
                'Current36 must preserve the complete historical35 schema')


def check_bafb_archive(root, manifest, expected):
    """Inspect current complete source bytes/literals, never original runtime paths."""
    current = manifest['current_full_a_required']
    package = manifest['current_source_alias']['package']
    require(len(current) == len(set(current)) == 18
            and all(PurePosixPath(rel).parts[0] == package and 'sources/' + rel in expected for rel in current),
            'Current bafb complete18 source members missing')
    folder = root / 'sources' / package
    pins = load_json((folder / 'PINS.json').read_bytes())
    require(type(pins['files']) is dict and len(pins['files']) == 29, 'Current bafb original Source29 contract')
    require({path.relative_to(folder).as_posix() for path in folder.rglob('*') if path.is_file()}
            == set(pins['files']) | {'PINS.json'}, 'Current Source29 plus PINS namespace mismatch')
    for rel, descriptor in pins['files'].items():
        pure = PurePosixPath(rel)
        require(not pure.is_absolute() and '..' not in pure.parts and str(pure) == rel,
                'Noncanonical current source contract member')
        require(set(descriptor) == {'path', 'bytes', 'sha256'} and type(descriptor['path']) is str,
                'Original current Source row must retain true three-field schema')
        raw = (folder / rel).read_bytes()
        require({'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
                == {key: descriptor[key] for key in ('bytes', 'sha256')}, 'Current original Source contract bytes mismatch')
    # descriptor.path/native9/birth are historical Source data, never file-system targets here.
    for entry in ('materializer/prepare.py', 'validation/run.py'):
        check_declared_authority_globals(folder / entry)
    check_pipeline_schema(root, package, manifest['current_orchestration_source_commit'], 37, 24)
    for entry in ('materializer/prepare.py', 'validation/run.py', 'launcher/launch.py'):
        values = literal_values(ast.parse((folder / entry).read_bytes()))
        require(values['CURRENT_M_RESULT_KEYS36'] == [name for name in values['CURRENT_M_RESULT_KEYS37']
                if name != 'current_6e3850_paid_producer_failure_retirement'],
                'Current37 must preserve complete historical36 field contract')
        if entry != 'launcher/launch.py':
            require(len(values['SOURCE_REQUIREMENT_PATHS']) == 316
                    and len(values['COMMITTED_PATHS']) == 311 and len(values['NEW_TRACKED_PATHS']) == 264,
                    'Current complete source path/lifecycle coverage changed')
    controls = manifest['current_author_code_required']
    require(len(controls) == len(set(controls)) == 10 and all('sources/' + rel in expected for rel in controls),
            'Selected current source-control ten-member set incomplete')
    selected = manifest['current_source_delivery']
    require(selected['runtime_ready'] is False and selected['actual_runtime_receipts_archived'] is False
            and selected['complete_original_authoring_replay_inputs_archived'] is False,
            'Current source-only boundaries changed')
    paths = selected['selected_paths']
    require(paths and len(paths) == len(set(paths))
            and all(path in expected for path in paths), 'Selected source delivery membership mismatch')
    require(selected['selected_files'] == len(paths)
            and selected['selected_bytes'] == sum(row['bytes'] for row in manifest['files'] if row['path'] in paths),
            'Selected source delivery count/bytes mismatch')
    outer = selected['native_outer_sources']
    roles = {'materialize_root_outer', 'success_S_readers_root_outer'}
    require(set(outer['required_roles']) == roles and type(outer['sources']) is dict,
            'Two native outer roles must be recorded')
    if outer['source_complete']:
        require(set(outer['sources']) == roles and selected['source_delivery_complete'] is True,
                'Complete source delivery lacks actual native outer entries')
        for role, descriptor in outer['sources'].items():
            require(descriptor['path'] in paths, 'Native outer not part of exact selected manifest')
            raw = (root / descriptor['path']).read_bytes()
            require({'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
                    == {key: descriptor[key] for key in ('bytes', 'sha256')}, 'Native outer source bytes mismatch')
    else:
        require(outer['sources'] == {} and set(outer['pending_roles']) == roles
                and selected['source_delivery_complete'] is False,
                'Pending native outer sources must not be promoted to complete')
    return {'package': package, 'selected_source_files': len(paths),
            'source_delivery_complete': selected['source_delivery_complete'],
            'native_outer_sources_complete': outer['source_complete'],
            'current_normal_Source_members': len(pins['files']),
            'current_author_code_members': len(controls)}



def check_successor_archive(root, manifest, dependencies, expected):
    """Finite r9 source selection only; never resolve original machine paths."""
    selection = manifest['successor_source_delivery']
    rows = manifest['files']
    require(selection['preserved_files'] == 585 and selection['preserved_total_bytes'] == 171739425,
            'Preserved585 baseline declaration changed')
    old_rows_hash = hashlib.sha256(json.dumps(rows[:585], ensure_ascii=False,
                                               separators=(',', ':')).encode('utf-8')).hexdigest()
    require(old_rows_hash == selection['preserved_manifest_rows_sha256']
            == 'd73c16f0be9a61a881037cccebdbf1f562dd34e9d86210c30414669b903bc4f3',
            'Original585 manifest entries changed')
    paths = selection['selected_paths']
    require(len(paths) == len(set(paths)) == selection['selected_files'] == 109
            and paths == [row['path'] for row in rows[585:]]
            and selection['selected_bytes'] == sum(row['bytes'] for row in rows[585:]) == 92609830,
            'Exact successor selection/count/bytes mismatch')
    require(selection['archive_files'] == len(rows) == 694
            and selection['archive_bytes'] == sum(row['bytes'] for row in rows) == 264349255
            and selection['archive_packages'] == len({row['package'] for row in rows}) == 72,
            'Successor archive totals mismatch')
    require(selection['source_delivery_complete'] is True and selection['runtime_ready'] is False
            and selection['archived_programs_executed'] is False
            and selection['original_runtime_receipts_or_private_inputs_delivered'] is False
            and selection['complete_original_author_replay_inputs_delivered'] is False,
            'Selected script text cannot become runtime or full author replay admission')
    require(dependencies['successor_source_delivery'] == selection,
            'Successor dependency/source contract mismatch')
    require(manifest['additional_source_aliases'] == {
        'currentb54-normal-r3': {'origin_root': 'project-organization-20261023',
                               'origin_relative_root': 'currentb54-next-normal-preparation-r3/normal-source'},
        'legacy-refund-normal-r3-memory-failed-retire-source-r1': {
            'origin_root': 'project-organization-20261023',
            'origin_relative_file': 'legacy-refund-normal-r3-memory-failed-retire-source-r1.py',
            'archive_leaf': 'retire.py'}},
        'Only the fixed b54 root and historical retirement flat-source aliases may be added')
    retirement = selection['historical_retirement_source']
    require(retirement == {
        'archive_path': 'sources/legacy-refund-normal-r3-memory-failed-retire-source-r1/retire.py',
        'origin_relative_file': 'legacy-refund-normal-r3-memory-failed-retire-source-r1.py',
        'bytes': 33716,
        'sha256': '58bcb4dfdb7161c2cf648de14e38f4c30169866802f1fab797fae330c80d3fda',
        'source_only': True, 'runnable': False, 'runtime_ready': False,
        'runtime_receipts_delivered': False},
        'Historical retirement text cannot become runnable or runtime-result admission')
    retirement_row = next((row for row in rows if row['path'] == retirement['archive_path']), None)
    require(retirement['archive_path'] in paths and retirement_row is not None
            and retirement_row['origin_root'] == 'project-organization-20261023'
            and retirement_row['origin_relative_path'] == retirement['origin_relative_file']
            and retirement_row['bytes'] == retirement['bytes']
            and retirement_row['sha256'] == retirement['sha256'],
            'Exact historical retirement original source descriptor mismatch')
    for rel in paths:
        parts = PurePosixPath(rel).parts
        leaf = parts[-1].lower()
        require(not set(parts).intersection({'materialize-freeze', 'source-input', 'private', 'actors', 'cookies'})
                and not leaf.endswith(('.stdout', '.stderr', '.stdout.bin', '.stderr.bin', '.tar', '.tar.gz',
                                       '.created.json', '.terminal.json', '.running.json'))
                and leaf not in {'result.json', 'source-tree.json', '.env'},
                'Generated/runtime/private artifact cannot enter successor source selection')
    for contract in selection['source_contracts']:
        package = contract['package']; prefix = 'sources/' + package + '/'
        pins_name = 'SOURCE-PINS.json' if package == 'currentb54-materialization-readers-source-r2' else 'PINS.json'
        require(contract['pins_path'] == prefix + pins_name and contract['pins_path'] in paths
                and contract['runtime_ready'] is False, 'Source PINS contract path or boundary changed')
        declared = load_json((root / contract['pins_path']).read_bytes())[contract['member_field']]
        members = contract['selected_members']; omitted = contract['excluded_contract_members']
        actual_members = sorted(rel[len(prefix):] for rel in paths
                                if rel.startswith(prefix) and rel != contract['pins_path'])
        require(type(declared) is dict and len(declared) == contract['declared_members']
                and actual_members == members and sorted(set(declared) - set(members)) == omitted,
                'Selected Source contract coverage mismatch')
        for name in members:
            require(prefix + name in expected, 'Required authored contract member missing')
            raw = (root / prefix / name).read_bytes()
            require({'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
                    == {key: declared[name][key] for key in ('bytes', 'sha256')},
                    'Original authored Source PINS member changed')
        for name in omitted:
            require(prefix + name not in expected, 'Deliberately excluded prerequisite was copied')
    core = selection['core']; package = core['package']
    require(core['source_commit'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
            and core['required_program_members'] == 18 and core['static_source_selection_members'] == 30
            and core['original_contract_members'] == 33 and core['original_contract_inputs_not_delivered'] == 4
            and core['M_result_fields'] == 37 and core['source_steps'] == 25,
            'b54 Core source contract mismatch')
    for name in ('materializer/prepare.py', 'validation/run.py'):
        check_declared_authority_globals(root / 'sources' / package / name)
    check_pipeline_schema(root, package, core['source_commit'], 37, 25)
    allowed_local_modules = {'source_reader', 'author_support', 'namespace_static'}
    for rel in paths:
        path = root / rel
        if path.suffix != '.py':
            continue
        for node in ast.walk(ast.parse(path.read_bytes(), filename=rel)):
            modules = []
            if isinstance(node, ast.Import):
                modules = [alias.name.split('.')[0] for alias in node.names]
            elif isinstance(node, ast.ImportFrom):
                require(node.level == 0 and node.module is not None, 'Unexpected relative successor import')
                modules = [node.module.split('.')[0]]
            require(all(module in sys.stdlib_module_names or module in allowed_local_modules for module in modules),
                    'Unarchived successor module dependency: ' + rel)
    for item in selection['required_local_code_dependencies']:
        require(item['caller'] in paths, 'Caller missing from finite dependency closure')
        targets = item.get('archive_dependencies', [item.get('archive_dependency')])
        require(targets and all(target in expected for target in targets), 'Necessary local source dependency missing')
    for item in selection['existing_archive_code_dependencies']:
        require(item['existing_archive_path'] in {row['path'] for row in rows[:585]}
                and item['runtime_original_path_or_identity_recovered'] is False,
                'Historical reader/helper must reuse an existing source-only member')
        raw = (root / item['existing_archive_path']).read_bytes()
        require({'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
                == {key: item[key] for key in ('bytes', 'sha256')}, 'Existing reader/helper source changed')
    refs = selection['external_product_source_references']
    require(len(refs) == 1 and refs[0]['commit'] == '057f769e37bc79c31d96b3ad05d56e8590d26768'
            and refs[0]['path'] == 'help/audit-regressions/isolated/ms2-migration-host.py'
            and refs[0]['blob'] == 'c2bbb623fec9b9af6066d010abd149492967ed54'
            and refs[0]['bytes'] == 49769
            and refs[0]['sha256'] == 'ba6b40f1863413f263e5c18b5163a1c8b06cd6eda5327a236364895fc0e1ec44'
            and refs[0]['archive_copy'] is False and refs[0]['runtime_or_business_accepted_by_reference'] is False,
            'MS2 repo-native Host must remain an exact product Git reference only')
    return {'selected_source_files': len(paths), 'source_contracts': len(selection['source_contracts']),
            'core_source_steps': core['source_steps'], 'latest_S_separate_from_original_core': True}


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
        alias = manifest['current_source_alias']
        successor_alias = manifest.get('additional_source_aliases', {}).get(row['package'])
        if row['package'] == 'legacy-refund-normal-r3-memory-failed-retire-source-r1':
            require(successor_alias == {
                'origin_root': 'project-organization-20261023',
                'origin_relative_file': 'legacy-refund-normal-r3-memory-failed-retire-source-r1.py',
                'archive_leaf': 'retire.py'}
                and pure.parts == ('sources', 'legacy-refund-normal-r3-memory-failed-retire-source-r1', 'retire.py')
                and row['origin_root'] == 'project-organization-20261023'
                and row['origin_relative_path'] == 'legacy-refund-normal-r3-memory-failed-retire-source-r1.py',
                'Fixed historical retirement flat-source alias origin mismatch')
        elif successor_alias is not None:
            require(row['origin_root'] == successor_alias['origin_root']
                    and row['origin_relative_path'] == successor_alias['origin_relative_root'] + '/' + '/'.join(pure.parts[2:]),
                    'Successor source snapshot alias origin mismatch')
        elif row['package'] == alias['package']:
            require(row['origin_root'] == alias['origin_root']
                    and row['origin_relative_path'] == alias['origin_relative_root'] + '/' + '/'.join(pure.parts[2:]),
                    'Current source snapshot alias origin mismatch')
        else:
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
    current = manifest['preserved_6e_full_a_required']
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
    historical_package = 'full-a-881-form-prefix-normal-next-source-r3'
    for entry in ['materializer/prepare.py', 'validation/run.py']:
        check_declared_authority_globals(root / 'sources' / historical_package / entry)
    check_pipeline_schema(root, historical_package, '8819524a60028090c55bdcd3a9da05e50eaf19d0', 32, 16)
    earlier_package = 'full-a-normal-http-diagnostic-next-source-r1'
    for entry in ['materializer/prepare.py', 'validation/run.py']:
        check_declared_authority_globals(root / 'sources' / earlier_package / entry)
    check_pipeline_schema(root, earlier_package, '0c13c57ba1ec4bf15ea9240601a5d6361e986ce8', 33, 17)
    preserved_28dc_package = 'full-a-upload-root-current28dc-normal-next-source-r1'
    for entry in ['materializer/prepare.py', 'validation/run.py']:
        check_declared_authority_globals(root / 'sources' / preserved_28dc_package / entry)
    check_pipeline_schema(root, preserved_28dc_package, '28dcab632da3e62f7c8b8d926b6ab9406534d0d3', 34, 18)
    check_pipeline_schema(root, previous_package, '175f3cd6183894185656a2974579e1be39eed7a7', 35, 19)
    check_pipeline_schema(root, current_package, manifest['preserved_6e_orchestration_source_commit'], 36, 20)
    authors = manifest['preserved_6e_author_code_required']
    author_names = {
        'assemble.py', 'source_reader.py', 'author_support.py', 'authority-current.py.fragment',
        'prepare-inputs.py', 'profile-author.py', 'retirement-author.py', 'DESIGN.md',
    }
    previous_author_names = author_names | {
        'bind-initializer-syntax.py', 'initializer-syntax-checks.py.fragment', 'retirement-checks.py.fragment',
    }
    current_author_names = {'prepare-inputs.py', 'author_support.py', 'namespace_static.py', 'bind-initializer-syntax.py', 'source_reader.py', 'repair-author/build.py', 'authority-current.py.fragment', 'initializer-syntax-checks.py.fragment', 'assemble.py', 'retirement-checks.py.fragment', 'authority-current.tail.fragment'}
    require(len(authors) == len(set(authors)) == 11, 'Current author source must contain all 11 members')
    require(set(authors) == {current_package + '/' + name for name in current_author_names},
            'Current author source paths do not match the complete author package')
    require(not set(authors).intersection(current), 'Author code cannot replace pipeline members')
    for rel in authors:
        require('sources/' + rel in expected, 'Current author source missing: ' + rel)
    local_modules = {'source_reader', 'author_support'}
    previous_authors = manifest['previous_current_author_code_required']
    require(len(previous_authors) == len(set(previous_authors)) == 11
            and set(previous_authors) == {previous_package + '/' + name for name in previous_author_names}
            and all('sources/' + rel in expected for rel in previous_authors), 'Previous author package must remain complete')
    preserved_28dc_authors = manifest['preserved_28dc_author_code_required']
    require(len(preserved_28dc_authors) == len(set(preserved_28dc_authors)) == 11
            and set(preserved_28dc_authors) == {preserved_28dc_package + '/' + name for name in previous_author_names}
            and all('sources/' + rel in expected for rel in preserved_28dc_authors),
            'Preserved28dc author package must remain complete')
    require(all('sources/' + earlier_package + '/' + name in expected for name in author_names),
            'Earlier complete eight-member author package must remain present')
    for package, names in [(current_package, current_author_names), (previous_package, previous_author_names),
                           (preserved_28dc_package, previous_author_names),
                           (earlier_package, author_names), (historical_package, author_names)]:
        local_modules = {'source_reader', 'author_support'} | ({'namespace_static'} if package == current_package else set())
        for name in names:
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
    for package, members in manifest['supplemental_source_packages'].items():
        require(members and len(members) == len(set(members))
                and all(rel in expected and PurePosixPath(rel).parts[1] == package for rel in members),
                'Supplemental source package member set mismatch')
    for package, pin_name, required_count in [
        ('current175f-complete-normal-S-readbacks-source-r1', 'PINS.json', 19),
        ('current175f-complete-normal-S-reader-outers-source-r1', 'PINS.json', 13),
        ('h5-current175f-complete-source-r1', 'SOURCE-PINS.json', 31),
    ]:
        pin_map = load_json((root / 'sources' / package / pin_name).read_bytes())['files']
        require(len(pin_map) == required_count, 'Complete prepared source contract count mismatch')
        for rel, pin in pin_map.items():
            target = 'sources/' + package + '/' + rel
            require(target in expected, 'Complete prepared source contract member missing: ' + target)
            raw = (root / target).read_bytes()
            require({'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()} == pin,
                    'Prepared source contract member changed: ' + target)
    boundaries = manifest['source_only_supplement']
    require(boundaries['runtime_ready'] is False
            and boundaries['prepared_programs_executed'] is False
            and boundaries['actual_M_S_or_context_or_private_data_delivered'] is False
            and boundaries['complete_original_qualification_packets_delivered'] is False,
            'Source selection cannot be promoted to runtime qualification')
    dependencies = load_json((root / 'runtime-dependencies.json').read_bytes())
    require(dependencies['runtime_ready'] is False, 'Runtime readiness cannot be inferred from source verification')
    require(dependencies['preserved_e9df_current_source']['package'] == current_package, 'Preserved6e package metadata mismatch')
    require(dependencies['preserved_e9df_current_source']['source_commit'] == manifest['preserved_6e_orchestration_source_commit'],
            'Preserved6e business source metadata mismatch')
    new_source = check_bafb_archive(root, manifest, expected)
    require(dependencies['current_source']['package'] == new_source['package']
            and dependencies['current_source']['source_commit'] == manifest['current_orchestration_source_commit']
            and dependencies['current_source']['runtime_ready'] is False
            and dependencies['current_source']['source_delivery_complete'] == new_source['source_delivery_complete'],
            'Current bafb source-only metadata mismatch')
    successor = check_successor_archive(root, manifest, dependencies, expected)
    print(json.dumps({
        'status': 'PASS_SOURCE_ARCHIVE_ONLY',
        'files': len(rows),
        'bytes': total_bytes,
        'packages': len(packages),
        'python_ast_parsed': python_count,
        'json_parsed': json_count,
        'current_source_authority_global_checks': 2,
        'preserved_previous_authority_global_checks': 2,
        'strict_current_M_result_fields': 37,
        'strict_preserved_6e_M_result_fields': 36,
        'current_source_steps': 24,
        'current_normal_Source_members': new_source['current_normal_Source_members'],
        'selected_current_source_files': new_source['selected_source_files'],
        'source_delivery_complete': new_source['source_delivery_complete'],
        'native_outer_sources_complete': new_source['native_outer_sources_complete'],
        'strict_previous_M_result_fields': 35,
        'strict_preserved_28dc_M_result_fields': 34,
        'strict_preserved_historical_M_result_fields': 33,
        'strict_preserved_older_M_result_fields': 32,
        'preserved_earlier_authority_global_checks': 2,
        'supplemental_source_packages': len(manifest['supplemental_source_packages']),
        'preserved_historical_authority_global_checks': 2,
        'previous_author_code_members': len(previous_authors),
        'preserved_6e_author_code_members': len(authors),
        'current_selected_source_control_members': new_source['current_author_code_members'],
        'successor_selected_source_files': successor['selected_source_files'],
        'successor_source_contracts': successor['source_contracts'],
        'successor_core_source_steps': successor['core_source_steps'],
        'latest_S_separate_from_original_core': successor['latest_S_separate_from_original_core'],
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
