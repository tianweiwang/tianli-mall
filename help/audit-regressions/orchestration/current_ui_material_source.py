"""Finite current-report successor of the original five-material producer; no builds."""
from __future__ import annotations

import argparse
import importlib.util
from pathlib import Path
import re

BASE = Path("H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/ui-five-material-producer-implementation-source-r3/candidate/ms4-ui-materials.py")
BASE_PIN = {"path": BASE.as_posix(), "bytes": 38049,
            "sha256": "5a298f2a5cbab10a5d7bb2833d9a5f153217a9aa90a539716c37f34510a655a2"}
BUILD_BASE = Path("H:/天俪商城/artifacts/project-organization-20261023/integrated-three-frontend-build-source-r5")
BUILD_PINS = {"path": (BUILD_BASE / "PINS.json").as_posix(), "bytes": 9207,
              "sha256": "c2add24a609ff5185a9f4f971d0455a5a783cf9b55b4eedb16e599a6efec462d"}
FRONTEND_AUTHOR_PIN = {"path": Path(__file__).with_name("current_frontend_build_source.py").as_posix(),
                       "bytes": 21883, "sha256": "4b186bc90efc16b96f68731af1f05b7a90ef37c1e20e4d2e3a3942ed49c23710"}
H5_LX_SOURCE = {"path": "H:/天俪商城/artifacts/multi-merchant-current-normal-source-20261028-r61/frontend-source-ms6-b-copy4-lx/h5_build.py",
                "bytes": 19341, "sha256": "ab6dafd5f1beccc70a512887a9a3449a250008be0045aac84b4476460e210350"}


def helpers():
    spec = importlib.util.spec_from_file_location("ui_source_helpers", Path(__file__).with_name("current_normal_source.py"))
    if spec is None or spec.loader is None:
        raise RuntimeError("SOURCE_HELPERS_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


RUNTIME = r'''
def current_pointer(reader, descriptor):
    need(type(descriptor) is dict and set(descriptor) == {'path','bytes','sha256'}
        and type(descriptor['path']) is str and Path(descriptor['path']).is_absolute(), 'CURRENT_FIXED_POINTER')
    path = Path(descriptor['path']); need(path.resolve(strict=True) == path, 'CURRENT_CANONICAL_POINTER')
    raw = reader.file(path, True)[1]
    need(fp(raw) == {k:descriptor[k] for k in ['bytes','sha256']}, 'CURRENT_COMPLETE_POINTER_BYTES')
    return loads(raw)


def current_literal(reader, packet, filename, name, path_value=False):
    tree = ast.parse(reader.file(packet/filename, True)[1])
    nodes = [node.value for node in tree.body if isinstance(node, ast.Assign)
        and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    need(len(nodes) == 1, 'CURRENT_ONE_BOUND_CONSTANT')
    node = nodes[0]
    if path_value:
        need(isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == 'Path'
            and len(node.args) == 1 and not node.keywords, 'CURRENT_LITERAL_PATH_ONLY')
        return Path(ast.literal_eval(node.args[0]))
    return ast.literal_eval(node)


def current_tree(reader, root, excluded=()):
    value = reader.tree(root, excluded)
    return {'files':files(value), 'directories':value['directories']}


def current_output_disjoint(reader, output):
    roots = [Path(name) for name in reader.files] + [Path(spec[0]) for spec in reader.trees]
    roots += reader._current_build_roots + [reader._current_stylus[0]]
    for path in roots:
        need(not output.is_relative_to(path) and not path.is_relative_to(output), 'ALL_ACTUAL_CURRENT_INPUTS_OUTPUT_DISJOINT')


def current_input_projection(rows, directories, frontend):
    prefix = 'template/'+('uni-app' if frontend == 'h5' else frontend)
    names = [name for name in rows if name.startswith(prefix+'/')]
    if frontend == 'admin':
        names.append('help/audit-regressions/frontend-source-check.cjs')
    need(names and len(names) == len(set(names)), 'COMPLETE_FRONTEND_SOURCE_RULES')
    return {'files':[{key:rows[name][key] for key in ['path','bytes','sha256','git_blob1','git_mode']}
        for name in sorted(names,key=order)],
        'directories':sorted([name for name in directories if name == prefix or name.startswith(prefix+'/')],key=order)}


def current_identities(reader, root, expected):
    need(type(expected) is dict, 'CURRENT_NATIVE_IDENTITY_MAP')
    for name, wanted in expected.items():
        path = at(root, name)
        reader.file(path)
        actual = reader.files[str(path)]['observations']['path_before']['stat']
        need(type(wanted) is list and len(wanted) == 9 and all(type(x) is int for x in wanted)
            and list(actual) == wanted, 'CURRENT_COMPLETE_NAMED_NATIVE9')


def current_proofs(reader, path, sha, paths, output):
    need(type(sha) is str and re.fullmatch('[a-f0-9]{64}', sha), 'EXPLICIT_CURRENT_BUILD_PROOFS_SHA')
    need(path.is_absolute() and fp(reader.file(path, True)[1])['sha256'] == sha, 'FIXED_CURRENT_BUILD_PROOFS')
    need(not output.is_relative_to(path) and not path.is_relative_to(output), 'BUILD_PROOFS_OUTPUT_DISJOINT')
    value = reader.json(path)
    need(set(value) == {'schema','scope','source_sha','builds'} and type(value['schema']) is int
        and value['schema'] == 1 and value['scope'] == 'CURRENT_THREE_FRONTEND_BUILD_PROOFS'
        and value['source_sha'] == CURRENT_SOURCE_SHA and set(value['builds']) == set(NAMES), 'EXACT_THREE_CURRENT_BUILD_PROOFS')
    authority = current_pointer(reader, CURRENT_SOURCE_AUTHORITY)
    need(authority['status'] == 'INDEPENDENTLY_REVIEWED_SOURCE_ONLY' and authority['source_sha'] == CURRENT_SOURCE_SHA
        and Path(authority['candidate_root']) == paths['source'], 'FIXED_REVIEWED_CURRENT_APPLICATION_SOURCE')
    review = current_pointer(reader, authority['independent_review'])
    need(review['status'] == 'PASS_CURRENT_SOURCE_TECHNICAL_REVIEW' and review['source_sha'] == CURRENT_SOURCE_SHA
        and review['capture'] == authority['capture'] and review['all_tracked_and_delta_reviewed'] is True
        and review['active_normal_sources_reviewed'] is True,
        'INDEPENDENT_SOURCE_REVIEW_NOT_CAPTURE_SELF_APPROVAL')
    capture = current_pointer(reader,authority['capture'])
    need(capture['source_sha'] == CURRENT_SOURCE_SHA, 'CURRENT_COMPLETE_CAPTURE_SOURCE')
    inventory = capture['files']['SOURCE-INVENTORY.jsonl']; check_pin(reader,Path(inventory['path']),inventory)
    captured = [loads(line) for line in reader.file(Path(inventory['path']),True)[1].splitlines()]
    rows = {row['path']:{'path':row['path'],'bytes':row['bytes'],'sha256':row['sha256'],
        'git_blob1':row['git_blob'],'git_mode':row['git_mode']} for row in captured}
    need(len(rows) == len(captured) == capture['tracked_file_count'], 'COMPLETE_CURRENT_CAPTURED_GIT_ROWS')
    directories = {parent.as_posix() for name in rows for parent in PurePosixPath(name).parents if parent.as_posix() != '.'}
    for frontend, row in value['builds'].items():
        need(type(row) is dict and set(row) == {'packet','pins','run','outer'}, 'CURRENT_PER_FRONTEND_SOURCE_AND_RUN')
        for key in ['packet','run','outer']:
            p = Path(row[key]); ordinary(p,'dir')
            need(p.is_absolute() and p.resolve(strict=True) == p and not output.is_relative_to(p)
                and not p.is_relative_to(output), 'CURRENT_CANONICAL_DISJOINT_BUILD_INPUT')
        need(Path(row['run']) == paths[frontend+'_build'] and Path(row['pins']['path']) == Path(row['packet'])/'PINS.json'
            and Path(row['outer']) == Path(row['run']+'-launch'), 'ACTUAL_FRONTEND_RUN_OUTER_AND_PACKET')
        outer = reader.json(Path(row['outer'])/'RESULT.json')
        report = reader.json(Path(row['run'])/'BUILD-REPORT.json')
        need(outer['status'] == 'ACTUAL_ORDINARY_BUILD_VERIFIED' and report['status'] == 'VERIFIED_ORDINARY_OFFLINE_BUILD_NOT_UI_OR_DEPLOYMENT'
            and outer['frontend'] == report['frontend'] == frontend and outer['source_head'] == report['source_head']
            and outer['run_id'] == report['run_id'], 'ALL_THREE_REAL_COMPLETE_BUILDS_BEFORE_OUTPUT')
    value['_current_inputs'] = {name:current_input_projection(rows,directories,name) for name in NAMES}
    prefix = 'crmeb/public/'
    public = {'files':[{'path':name[len(prefix):],**{key:row[key] for key in ['bytes','sha256']}}
        for name,row in sorted(rows.items(),key=lambda pair:order(pair[0])) if name.startswith(prefix)],
        'directories':sorted([name[len(prefix):] for name in directories if name.startswith(prefix)],key=order)}
    need('install' in public['directories'] and not any(row['path'] == 'install' for row in public['files']),
        'CAPTURED_ROOT_INSTALL_DIRECTORY_ONLY')
    value['_current_public'] = {'files':[row for row in public['files'] if not row['path'].startswith('install/')],
        'directories':[name for name in public['directories'] if name != 'install' and not name.startswith('install/')]}
    value['_current_public_install'] = {'files':[{'path':row['path'][8:],**{key:row[key] for key in ['bytes','sha256']}}
        for row in public['files'] if row['path'].startswith('install/')],
        'directories':[name[8:] for name in public['directories'] if name.startswith('install/')]}
    need(value['_current_public_install']['files'] and len(value['_current_public']['files'])
        + len(value['_current_public_install']['files']) == len(public['files'])
        and len(value['_current_public']['directories']) + len(value['_current_public_install']['directories'])
        + 1 == len(public['directories']), 'COMPLETE_PUBLIC_AND_EXCLUDED_INSTALL_CAPTURE_PARTITION')
    value['_current_public_source_binding'] = {'capture':authority['capture'], 'source_inventory':inventory}
    return value


def current_packet(reader, spec, paths):
    packet = Path(spec['packet']); pins = current_pointer(reader, spec['pins'])
    need(set(pins) == {'schema','scope','files','directories'} and pins['schema'] == 1
        and pins['scope'] == 'SOURCE_ONLY_NOT_BUILD_OR_RUNTIME_SEAL', 'REAL_CURRENT_BUILDER_PACKET_SCHEMA')
    observed = reader.tree(packet)
    need(set(files(observed)) == set(pins['files']) | {'PINS.json'}
        and observed['directories'] == pins['directories'], 'COMPLETE_INDEPENDENT_BUILDER_PACKET')
    for name, wanted in pins['files'].items():
        check_pin(reader, at(packet,name), wanted)
    q = current_pointer(reader, current_literal(reader,packet,'build_common.py','QUALIFIED_INPUTS_PIN'))
    need(q['schema'] == 1 and q['scope'] == 'ROOT_SAME_CLEAN_HEAD_THREE_BUILD_QUALIFICATION' and q['sealed'] is True
        and Path(q['source_repo']) != Path(q['validation_repo']), 'ACTUAL_ORIGINAL_BUILD_QUALIFIED_ROOTS')
    authority = current_pointer(reader,q['source_authority'])
    review = current_pointer(reader,authority['independent_review'])
    need(authority['status'] == 'INDEPENDENTLY_REVIEWED_SOURCE_ONLY' and authority['source_sha'] == q['head']
        and authority['candidate_root'] == q['source_repo'] and authority['validation_root'] == q['validation_repo']
        and review['status'] == 'PASS_CURRENT_SOURCE_TECHNICAL_REVIEW' and review['source_sha'] == q['head']
        and review['capture'] == authority['capture'] and review['all_tracked_and_delta_reviewed'] is True,
        'EACH_BUILD_RETAINS_ITS_REAL_ORIGINAL_SOURCE_AUTHORITY')
    reader._current_build_roots = getattr(reader,'_current_build_roots',[]) + [Path(q['source_repo']),Path(q['validation_repo'])]
    tree = current_pointer(reader,q['full_tree'])
    need(set(tree) == {'head','files','directories'} and tree['head'] == q['head'], 'CURRENT_FULL_GIT_TREE')
    rows = {row['path']:row for row in tree['files']}
    need(len(rows) == len(tree['files']) and all(set(row) == {'path','bytes','sha256','git_blob1','git_mode'}
        and row['git_mode'] in ['100644','100755'] for row in rows.values()), 'CURRENT_UNIQUE_REGULAR_GIT_ROWS')
    return packet,q,rows,tree['directories']


def current_git_evidence(reader, run, q, rows, operations):
    for phase in ['before','after']:
        gitdirs = []
        for role in ['source_repo','validation_repo']:
            tag = role.replace('_','-'); prefix = phase+'-'+tag+'-'; root = Path(q[role])
            for label, tail, expected in [('head',['rev-parse','HEAD'],q['head']+'\n'),
                ('parent',['rev-parse','HEAD^'],q['parent']+'\n'),
                ('status',['status','--porcelain=v1','--untracked-files=all'],'')]:
                raw,err,created,terminal = operations[prefix+label]
                need(created['argv'][-len(tail):] == tail and raw == expected.encode() and err == b'', 'REAL_CURRENT_GIT_HEAD_PARENT_CLEAN')
            for label in ['head','parent','status','gitdir','ls-tree']:
                argv = operations[prefix+label][2]['argv']
                tail = {'head':['rev-parse','HEAD'],'parent':['rev-parse','HEAD^'],
                    'status':['status','--porcelain=v1','--untracked-files=all'],
                    'gitdir':['rev-parse','--absolute-git-dir'],'ls-tree':['ls-tree','-rz','--full-tree',q['head']]}[label]
                need(argv == ['git','--no-optional-locks','-c','safe.directory='+root.as_posix(),'-C',str(root),*tail]
                    and operations[prefix+label][2]['cwd'] is None,
                    'EXACT_CURRENT_GIT_REPOSITORY_ARGV')
            raw,err,created,terminal = operations[prefix+'gitdir']
            need(created['argv'][-2:] == ['rev-parse','--absolute-git-dir'] and err == b'', 'REAL_CURRENT_SEPARATE_GIT_DIRECTORY')
            gitdirs.append(Path(raw.decode().strip()))
            raw,err,created,terminal = operations[prefix+'ls-tree']
            need(created['argv'][-4:] == ['ls-tree','-rz','--full-tree',q['head']] and err == b'' and raw.endswith(b'\0'), 'ACTUAL_COMPLETE_NUL_GIT_TREE')
            observed = {}
            for item in raw[:-1].split(b'\0'):
                info,name = item.split(b'\t',1); mode,kind,blob = info.decode().split(' ')
                name = name.decode('utf-8'); need(kind == 'blob' and name not in observed, 'CURRENT_RAW_GIT_BLOB_ROWS')
                observed[name] = (mode,blob)
            need(observed == {name:(row['git_mode'],row['git_blob1']) for name,row in rows.items()}, 'COMPLETE_CURRENT_BLOB_NAMESPACE')
        need(gitdirs[0] != gitdirs[1], 'ACTUAL_SOURCE_VALIDATION_GIT_DIRECTORIES_DISTINCT')
        raw,err,created,terminal = operations[phase+'-val-detached']
        validation = Path(q['validation_repo'])
        need(created['argv'] == ['git','--no-optional-locks','-c','safe.directory='+validation.as_posix(),'-C',str(validation),
            'rev-parse','--abbrev-ref','HEAD'] and created['cwd'] is None and raw == b'HEAD\n' and err == b'',
            'REAL_CURRENT_DETACHED_VALIDATION')


def current_resources(reader, root, inventory, prefix):
    class IndexResources(HTMLParser):
        def __init__(self):
            super().__init__(); self.urls = []
        def handle_starttag(self, tag, attrs):
            self.urls += [value for key,value in attrs if (tag == 'script' and key == 'src') or (tag == 'link' and key == 'href')]
    parser = IndexResources(); parser.feed(reader.file(root/'index.html',True)[1].decode('utf-8')); result = []
    for url in parser.urls:
        parsed = urlsplit(url); need(not parsed.scheme and not parsed.netloc and parsed.path.startswith(prefix), 'ACTUAL_LOCAL_INDEX_RESOURCE')
        name = unquote(parsed.path[len(prefix):]); need(name in inventory, 'REAL_INDEX_COMPLETE_RESOURCE')
        result.append({'url':url,'path':name,**inventory[name]})
    resources(result,inventory,prefix)
    return result


def current_windows(reader, packet, q, run, report, preservation, operations):
    frontend = report['frontend']; work = run/'template'/frontend; modules = work/'node_modules'
    need(set(preservation) == {'source','original_modules','copied_modules','runtime','npm_runtime'}, 'EXACT_WINDOWS_PRESERVATION_GROUPS')
    authority = current_pointer(reader,q['dependency_authority'][frontend]); dep = reader.json(run/'DEPENDENCY-INPUTS.json')
    need(set(dep) == {'files','directories'} and dep == {key:authority[key] for key in ['files','directories']}
        and len(dep['files']) == report['dependency_files'], 'COMPLETE_CURRENT_DEPENDENCY_AND_DIRECTORY_AUTHORITY')
    baseline = current_literal(reader,packet,'windows_build.py','BASELINES')[frontend]
    need(dep['files'] == current_pointer(reader,baseline), 'ORIGINAL_FIXED_MATURE_DEPENDENCY_BASELINE')
    original = Path(authority['root'])
    need(current_tree(reader,original,('.cache','.tmp','.git')) == dep
        and current_tree(reader,modules,('.cache','.tmp','.git')) == dep, 'FULL_ORIGINAL_PHYSICAL_COPIED_MODULES')
    current_identities(reader,original,preservation['original_modules'])
    current_identities(reader,modules,preservation['copied_modules'])
    need(set(preservation['original_modules']) == set(preservation['copied_modules']) == set(dep['files']), 'COMPLETE_BOTH_MODULE_NATIVE_MAPS')
    for leaf in ['package.json','package-lock.json']:
        need(reader.file(original.parent/leaf) == reader.file(work/leaf), 'CURRENT_EXACT_ORIGINAL_PACKAGE_AND_LOCK')
    for root in [original,modules]:
        for name,version in report['versions'].items():
            need(reader.json(at(root,name+'/package.json'))['version'] == version, 'REAL_CURRENT_INSTALLED_VERSION')
        if frontend == 'merchant':
            merchant_dependencies(reader,work,root,{'direct_versions':report['versions'],'installed_hidden_lock_entries':1327,
                'installed_hidden_lock':dep['files']['.package-lock.json']})
            hidden = reader.json(root/'.package-lock.json')
            for name,row in hidden['packages'].items():
                need(name.startswith('node_modules/') and reader.json(at(root.parent,name+'/package.json'))['version'] == row['version'],
                    'EVERY_CURRENT_MERCHANT_INSTALLED_PACKAGE_VERSION')
    if frontend == 'admin':
        need(report['versions'] == {'vue':'2.7.16','vue-template-compiler':'2.7.16','@vue/cli-service':'3.12.1',
            'webpack':'4.47.0','axios':'0.18.1','uglifyjs-webpack-plugin':'2.2.0'}, 'ORIGINAL_ADMIN_OFFLINE_VERSIONS')
    node_root = current_literal(reader,packet,'build_common.py','NODE_ROOT',True)
    node,npm = node_root/'node.exe',node_root/'node_modules/npm/bin/npm-cli.js'
    check_pin(reader,node,current_literal(reader,packet,'build_common.py','NODE_PIN'))
    check_pin(reader,npm,current_literal(reader,packet,'build_common.py','NPM_PIN'))
    for path in [node,npm]:
        need(list(ordinary(path,'file')) == preservation['runtime'][str(path)], 'CURRENT_RUNTIME_NATIVE9')
    need(set(preservation['runtime']) == {str(node),str(npm)}, 'COMPLETE_TWO_RUNTIME_NATIVE_IDENTITIES')
    npm_authority = current_pointer(reader,q['npm_runtime_authority']); npm_root = node_root/'node_modules/npm'
    need(Path(npm_authority['root']) == npm_root and current_tree(reader,npm_root,('.cache','.tmp','.git'))
        == {key:npm_authority[key] for key in ['files','directories']}, 'COMPLETE_CURRENT_NPM_ENTRY_AND_RUNTIME')
    current_identities(reader,npm_root,preservation['npm_runtime'])
    need(set(preservation['npm_runtime']) == set(npm_authority['files']), 'COMPLETE_ALL_NPM_RUNTIME_NATIVE_IDENTITIES')
    for label,args,expected in [('node-version',[str(node),'--version'],'v22.23.3'),
        ('npm-version',[str(node),str(npm),'--version'],'10.9.9'),('npm-build',[str(node),str(npm),'run','build'],None)]:
        raw,err,created,terminal = operations[label]
        need(created['argv'] == args and Path(created['cwd']) == (work if label == 'npm-build' else run), 'CURRENT_REAL_NODE_NPM_COMMAND_AND_CWD')
        if expected is not None:
            need(raw.decode('utf-8').strip() == expected and err == b'', 'CURRENT_REAL_NODE_NPM_VERSION')
        else:
            need(b'prebuild' in raw and (b'frontend-source-check.cjs' if frontend == 'admin' else b'scripts/source-check.cjs') in raw,
                'CURRENT_ACTUAL_PREBUILD_STREAM')
            if frontend == 'merchant':
                need(b'"source_checks":"PASS"' in raw, 'CURRENT_REAL_MERCHANT_PREBUILD_PASS')


def current_build(reader, paths, frontend):
    spec = paths['_current_build_proofs']['builds'][frontend]; run = Path(spec['run']); outer_root = Path(spec['outer'])
    packet,q,rows,directories = current_packet(reader,spec,paths); report = reader.json(run/'BUILD-REPORT.json')
    projection = current_input_projection(rows,directories,frontend)
    need(projection == paths['_current_build_proofs']['_current_inputs'][frontend],
        'CURRENT_AND_ORIGINAL_COMPLETE_BUILD_INPUTS_DIRS_GIT_MODE_BLOB_BYTES_SHA_IDENTICAL')
    admission = reader.json(run/'ADMISSION.json'); outer = reader.json(outer_root/'RESULT.json')
    need(report['schema'] == 1 and report['status'] == 'VERIFIED_ORDINARY_OFFLINE_BUILD_NOT_UI_OR_DEPLOYMENT'
        and report['frontend'] == frontend and report['source_head'] == q['head'] and report['full_source_and_val_preserved'] is True
        and report['installation_or_network_fallback'] is False and report['commit_push_deploy'] is False
        and report['dist_manifest'] == 'DIST-TREE.json' and report['final_public_copy_four_manifests'] == 'NOT_CREATED_BY_THIS_DRIVER'
        and report['business_UI_devices'] == 'NOT_RUN', 'REAL_CURRENT_BUILD_REPORT_NOT_UI_ACCEPTANCE')
    need(report['source_assembly'] == ('ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_SNAPSHOT' if frontend == 'h5'
        else 'ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_OVERLAY'), 'EXACT_CURRENT_SOURCE_ASSEMBLY_ROUTE')
    need(admission == {'schema':1,'scope':'ROOT_ORDINARY_BUILD_ADMITTED_NOT_COMPLETE','frontend':frontend,'run_id':report['run_id'],
        'head':q['head'],'source_repo':q['source_repo'],'validation_repo':q['validation_repo'],'source_authority':q['source_authority'],
        'full_tree':q['full_tree']}, 'REAL_CURRENT_BUILD_ADMISSION')
    raw,err,created,terminal = native_pair(reader,outer_root,'wrapper')
    python = current_literal(reader,packet,'build_common.py','PYTHON')
    need(created['argv'] == [python,'-I','-B','-X','utf8',str(packet/'root-build.py'),'--frontend',frontend,
        '--pins-sha256',spec['pins']['sha256'],'--run-id',report['run_id']] and Path(created['cwd']) == packet
        and outer['wrapper'] == terminal and outer['status'] == 'ACTUAL_ORDINARY_BUILD_VERIFIED'
        and outer['run_id'] == report['run_id'] and outer['frontend'] == frontend and outer['source_head'] == q['head']
        and Path(outer['build_report']) == run/'BUILD-REPORT.json' and err == b'', 'ACTUAL_PER_FRONTEND_BUILDER_PINS_AND_OUTER_NATIVE_ZERO')
    emitted = loads(raw)
    need(emitted == {'status':report['status'],'frontend':frontend,'run_id':report['run_id'],'source_head':q['head']}, 'ACTUAL_BUILD_ROOT_RESULT_STREAM')
    expected = row_map(reader.json(run/'SOURCE-INPUTS.json')); prefix = 'template/'+('uni-app' if frontend == 'h5' else frontend)
    wanted = {name:{k:row[k] for k in ['bytes','sha256']} for name,row in rows.items() if name.startswith(prefix+'/')}
    helper = 'help/audit-regressions/frontend-source-check.cjs' if frontend == 'admin' else None
    if helper:
        wanted[helper] = {k:rows[helper][k] for k in ['bytes','sha256']}
    need(expected == wanted and len(expected) == report['source_files'], 'COMPLETE_CURRENT_FRONTEND_GIT_SOURCE_SET')
    selected_sources(reader,paths['source'],prefix,expected,helper)
    expected_directories = [name[len(prefix)+1:] for name in projection['directories'] if name != prefix]
    source_tree = reader.trees[(str(paths['source']/prefix),(),False,False)][0]
    exported_tree = reader.tree(run/prefix,('node_modules','dist'),True)
    need(source_tree['directories'] == expected_directories and exported_tree['directories'] == expected_directories
        and files(exported_tree) == {name[len(prefix)+1:]:pin for name,pin in expected.items() if name.startswith(prefix+'/')},
        'COMPLETE_CURRENT_EXPORTED_SOURCE_FILES_AND_DIRECTORIES')
    preservation = reader.json(run/'NATIVE-INPUT-PRESERVATION.json')
    source_identities = preservation['exported_source'] if frontend == 'h5' else preservation['source']
    need(set(source_identities) == set(expected), 'COMPLETE_EXPORTED_SOURCE_NATIVE_MAP')
    current_identities(reader,run,source_identities)
    for name,pin in expected.items():
        need(reader.file(at(run,name)) == pin and reader.files[str(at(run,name))]['git_blob'] == rows[name]['git_blob1'], 'CURRENT_COMPLETE_EXPORTED_GIT_BLOB_BYTES')
    operations = {}
    for item in report['native_operations']:
        label = Path(item['stdout']['path']).name.removesuffix('.stdout')
        need(label not in operations, 'UNIQUE_CURRENT_NATIVE_LABEL')
        operation = native_pair(reader,run/'evidence',label)
        need(exact(operation[3],item), 'FULL_CURRENT_REPORTED_NATIVE_FOUR_FILE_JOIN')
        operations[label] = operation
    gitlabels = {phase+'-'+role+'-'+leaf for phase in ['before','after'] for role in ['source-repo','validation-repo']
        for leaf in ['head','parent','status','gitdir','ls-tree']} | {'before-val-detached','after-val-detached'}
    specific = {'h5-image-inspect','h5-container-create','h5-compiler-attach','h5-container-terminal','h5-owned-id-before-remove','h5-owned-normal-remove'}
    specific |= {'h5-stylus-'+phase+'-alias-'+name for phase in ['before','after'] for name in ['atob','mkdirp','semver','stylus']}
    need(set(operations) == gitlabels | (specific if frontend == 'h5' else {'node-version','npm-version','npm-build'}), 'ALL_CURRENT_NATIVE_COMMANDS_NO_MISSING_STAGES')
    current_git_evidence(reader,run,q,rows,operations)
    dist_root = run/('output' if frontend == 'h5' else 'template/'+frontend+'/dist')
    need(Path(report['dist_root']) == dist_root, 'ACTUAL_FRONTEND_DIST_ROOT_LAYOUT')
    tree = reader.tree(dist_root,public=True); recorded = reader.json(run/'DIST-TREE.json')
    need(tree == recorded, 'COMPLETE_CURRENT_DIST_FILES_DIRECTORIES_AND_BYTES')
    native = reader.json(run/'DIST-NATIVE-IDENTITIES.json')
    need(set(native) == {'root','files','directories'} and list(ordinary(dist_root,'dir')) == native['root']
        and set(native['files']) == set(files(tree)) and set(native['directories']) == set(tree['directories']), 'COMPLETE_CURRENT_DIST_NATIVE_NAMESPACE')
    current_identities(reader,dist_root,native['files'])
    for name,wanted in native['directories'].items():
        need(list(ordinary(at(dist_root,name),'dir')) == wanted, 'CURRENT_DIST_DIRECTORY_NATIVE9')
    if frontend == 'h5':
        current_h5(reader,packet,q,run,report,preservation,operations,tree)
    else:
        current_windows(reader,packet,q,run,report,preservation,operations)
    actual_resources = current_resources(reader,dist_root,files(tree),'/' if frontend == 'h5' else '/'+frontend+'/')
    if frontend != 'h5':
        need(report['resources'] == actual_resources, 'CURRENT_REAL_INDEX_RESOURCES_NOT_SYNTHETIC_REPORT')
    return tree, {'original_build_revision':report['source_head'],'build_report':reader.file(run/'BUILD-REPORT.json'),
        'current_source_revision':CURRENT_SOURCE_SHA,'reuse_basis':'COMPLETE_BUILD_INPUT_EQUIVALENCE_ORIGINAL_HEAD_RETAINED',
        'builder_pins':spec['pins'],'run_id':report['run_id'],'outer_result':reader.file(outer_root/'RESULT.json'),'dist_root':dist_root.as_posix()}
'''


NATIVE = '''def native_pair(reader, build, label, merchant=False):
    need(merchant is False and re.fullmatch('[a-z0-9][a-z0-9-]*',label), 'CURRENT_EXPLICIT_NATIVE_LABEL')
    raw = reader.file(build/(label+'.stdout'),True)[1]; err = reader.file(build/(label+'.stderr'),True)[1]
    created = reader.json(build/(label+'.created.json')); terminal = reader.json(build/(label+'.terminal.json'))
    need(set(created) == {'pid','argv','cwd','started_ns'} and set(terminal) ==
        {'pid','argv','native_exit','wait_returncode','started_ns','finished_ns','stdout','stderr'}
        and type(created['pid']) is int and created['pid'] > 0 and created['argv'] == terminal['argv']
        and created['pid'] == terminal['pid'] and type(created['started_ns']) is int and created['started_ns'] > 0
        and created['started_ns'] == terminal['started_ns']
        and type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
        and terminal['native_exit'] == terminal['wait_returncode'] == 0
        and type(terminal['finished_ns']) is int and terminal['finished_ns'] >= terminal['started_ns'], 'CURRENT_SAME_PID_ARGV_NATIVE_WAIT_ZERO')
    for channel,body in [('stdout',raw),('stderr',err)]:
        descriptor = terminal[channel]
        need(set(descriptor) == {'path','bytes','sha256'} and Path(descriptor['path']) == build/(label+'.'+channel)
            and fp(body) == {k:descriptor[k] for k in ['bytes','sha256']}, 'CURRENT_FULL_RAW_STREAM_DESCRIPTOR')
    need(type(created['argv']) is list and created['argv'] and all(type(x) is str and '\\0' not in x for x in created['argv'])
        and (created['cwd'] is None or type(created['cwd']) is str), 'ORIGINAL_NATIVE_ARGV')
    return raw,err,created,terminal'''


H5_RUNTIME = r'''
def current_h5(reader, packet, q, run, report, preservation, operations, manifest):
    need(set(preservation) == {'exported_source','runtime_source','compiler_dependencies','adapter'}, 'EXACT_H5_PRESERVATION_GROUPS')
    need(reader.file(packet/'h5_build.py') == CURRENT_H5_SOURCE, 'ORIGINAL_CURRENT_H5_ALGORITHM_BYTES')
    authority = current_pointer(reader,q['dependency_authority']['h5'])
    ROOT = packet
    PLUGINS = current_literal(reader,packet,'h5_build.py','PLUGINS',True)
    STYLUS = current_literal(reader,packet,'h5_build.py','STYLUS',True)
    IMAGE = current_literal(reader,packet,'h5_build.py','IMAGE')
    DOCKER = current_literal(reader,packet,'h5_build.py','DOCKER')
    DEPENDENCIES = {name:Path(row['root']) for name,row in authority.items()}
    need(set(DEPENDENCIES) == {'uniapp-cli','about','compile-dart-sass','uni_helpers','compile-stylus'}
        and all(DEPENDENCIES[name] == PLUGINS/name for name in DEPENDENCIES if name != 'compile-stylus')
        and DEPENDENCIES['compile-stylus'] == STYLUS, 'FIVE_REAL_ORIGINAL_H5_ROOTS')
    require = need; pin = fp; relative = rel
    def read(path):
        return reader.file(path,True)[1]
    def fixed(pointer):
        raw = read(Path(pointer['path']))
        need(fp(raw) == {key:pointer[key] for key in ['bytes','sha256']}, 'H5_FIXED_ORIGINAL_INPUT')
        return raw
    def native9(path):
        value = Path(path).lstat()
        return [getattr(value,key) for key in STATE_KEYS]
    def regular(path, directory=False):
        return ordinary(path,'dir' if directory else 'file',full=True)
    def identities(root, names):
        return {name:native9(at(root,name)) for name in names}
    def tree(root, exclude_cache=False):
        return current_tree(reader,root,('.cache','.tmp','.git') if exclude_cache else ())
    def native(context, label, argv, cwd=None, **options):
        raw,err,created,terminal = operations[label]
        need(created['argv'] == argv and created['cwd'] == (None if cwd is None else str(cwd))
            and (options.get('allow_stderr',False) or err == b''), 'H5_RECORDED_NATIVE_ONLY_NEVER_SUBPROCESS')
        return raw,terminal
    alias_names = {'node_modules/.bin/'+name for name in ['atob','mkdirp','semver','stylus']}
    sealed_stylus = preservation['compiler_dependencies']['compile-stylus']
    need(os.name == 'nt' and set(sealed_stylus['alias_identities']) == alias_names
        and set(sealed_stylus['tree']['links']) == alias_names, 'EXACT_FOUR_WINDOWS_H5_ALIAS_RECEIPTS')
    def alias_receipt(value, phase):
        need(type(value) is dict and set(value) == {'schema','scope','root','phase','aliases'}
            and type(value['schema']) is int and value['schema'] == 1
            and value['scope'] == 'FOUR_FIXED_WINDOWS_LX_ALIAS_RAW_ATTRIBUTES'
            and value['root'] == STYLUS.as_posix() and value['phase'] == phase
            and phase in ['before','after'] and type(value['aliases']) is dict
            and set(value['aliases']) == alias_names, 'EXACT_RECORDED_H5_ALIAS_RECEIPT_SCHEMA')
        stable = {}
        for name,row in value['aliases'].items():
            state = sealed_stylus['alias_identities'][name]
            need(type(row) is dict and set(row) == {'before','after','native9','birthtime_ns','reparse_tag','reparse_query'}
                and type(row['before']) is int and type(row['after']) is int
                and row['before'] in (1056,263200) and row['after'] in (1056,263200)
                and type(row['native9']) is list and len(row['native9']) == 9
                and all(type(x) is int for x in row['native9'])
                and row['native9'] == state['native9'] and type(row['birthtime_ns']) is int
                and row['birthtime_ns'] == state['birthtime_ns']
                and row['reparse_tag'] == state['reparse_tag'] == 0xA000001D
                and state['file_attributes'] == 1056
                and row['reparse_query'] == state['reparse_query'] == sealed_stylus['tree']['links'][name]['reparse_query'],
                'RECORDED_H5_ALIAS_RAW_VALUES_AND_COMPLETE_STABLE_IDENTITY')
            label = 'h5-stylus-'+phase+'-alias-'+Path(name).name
            raw,err,created,terminal = operations[label]
            need(created['argv'] == ['C:/Windows/System32/fsutil.exe','reparsepoint','query',str(at(STYLUS,name))]
                and created['cwd'] is None and terminal['native_exit'] == terminal['wait_returncode'] == 0
                and err == b'' and fp(raw) == row['reparse_query'], 'RECORDED_H5_ALIAS_EXACT_NATIVE_QUERY_JOIN')
            stable[name] = {key:row[key] for key in ['native9','birthtime_ns','reparse_tag','reparse_query']}
        return stable
    historical_aliases = {}
    for phase in ['before','after']:
        path = run/'evidence'/('h5-stylus-'+phase+'-alias-attributes.json')
        historical_aliases[phase] = alias_receipt(reader.json(path),phase)
    replayed_alias_phases = []
    def put(path, value):
        # The copied function emits observations. Replay validates the existing
        # historical receipt and compares stable fields; it never rewrites it.
        phase = value.get('phase') if type(value) is dict else None
        need(phase in historical_aliases and phase not in replayed_alias_phases
            and Path(path) == run/'evidence'/('h5-stylus-'+phase+'-alias-attributes.json'),
            'H5_ALIAS_RECEIPT_REPLAY_READ_ONLY_EXACT_PATH')
        current = alias_receipt(value,phase)
        need(current == historical_aliases[phase], 'H5_CURRENT_ALIAS_STABLE_FIELDS_EQUAL_ORIGINAL_RECEIPT')
        replayed_alias_phases.append(phase)

__H5_READONLY_FUNCTIONS__

    dependencies = dependency_window({'evidence':run/'evidence'},'after',authority)
    need(replayed_alias_phases == ['after'], 'ONLY_ORIGINAL_AFTER_ALIAS_PHASE_REPLAYED_READ_ONLY')
    need(dependencies == preservation['compiler_dependencies'], 'FULL_REAL_H5_TOOL_BYTES_NAMES_IDENTITIES_AND_FOUR_ALIASES')
    for name in ['atob','mkdirp','semver','stylus']:
        before = operations['h5-stylus-before-alias-'+name]; after = operations['h5-stylus-after-alias-'+name]
        need(before[0] == after[0] and before[1] == after[1] == b'' and before[2]['argv'] == after[2]['argv'],
            'BOTH_REAL_H5_ALIAS_QUERY_STREAMS_AND_ARGV')
    exported = run/'template/uni-app'; runtime_input = run/'input'
    need(tree(exported) == tree(runtime_input), 'CURRENT_COMPLETE_H5_EXPORTED_RUNTIME_COPY')
    current_identities(reader,runtime_input,preservation['runtime_source'])
    need(set(preservation['runtime_source']) == set(tree(runtime_input)['files']), 'COMPLETE_H5_RUNTIME_NATIVE_MAP')
    adapter = packet/'h5-tools/explicit-pass-preload.cjs'
    need(reader.file(adapter) == preservation['adapter'], 'ORIGINAL_H5_ADAPTER_BYTES_PRESERVED')
    guards = reader.json(packet/'h5-tools/compiler-source-guards.json')
    need(type(guards) is list and len(guards) == 8, 'EIGHT_ORIGINAL_H5_COMPILER_GUARDS')
    for row in guards:
        need(set(row) == {'path','sha256'} and reader.file(PLUGINS/'uniapp-cli'/row['path'])['sha256'] == row['sha256'], 'ACTUAL_H5_COMPILER_SOURCE_GUARD')
    overlay = packet/'h5-tools/compiler-overlays/vue-cli-plugin-uni-optimize'
    overlay_manifest = reader.json(packet/'h5-tools/optimize-overlay-manifest.json')
    overlay_expected = {row['path']:{key:row[key] for key in ['bytes','sha256']} for row in overlay_manifest['entries']}
    need(len(overlay_expected) == overlay_manifest['copied_file_count'] == 11
        and tree(overlay,True)['files'] == overlay_expected and list((overlay/'.tmp').iterdir()) == [], 'COMPLETE_ELEVEN_H5_OVERLAYS_AND_EMPTY_CACHE')
    for name,wanted in overlay_expected.items():
        need(reader.file(Path(overlay_manifest['source_package'])/name) == wanted, 'ORIGINAL_INSTALLED_OPTIMIZE_INPUTS')
    observed = adapter_receipt(run/'evidence/adapter-receipt.json',guards,manifest)
    compiler = report['compiler']; cid = compiler['container_id']
    need(all(created['cwd'] is None for label,(raw,err,created,terminal) in operations.items() if label.startswith('h5-')),
        'H5_ALL_RECORDED_NATIVE_CWD_EXACT')
    owned = reader.json(run/'evidence/OWNED-COMPILER.json')
    need(owned == {'container_id':cid,'run_id':report['run_id'],'source_head':q['head'],'image':IMAGE}
        and compiler == {'image':IMAGE,'container_id':cid,'natural_exit':0,'nonforce_removed':True,**observed}
        and re.fullmatch('[a-f0-9]{64}',cid), 'REAL_CURRENT_H5_OWNED_COMPILER_AND_TWO_PASSES')
    expected_create = [DOCKER,'create','--pull=never','--name','tianli-integrated-h5-build-'+report['run_id'],
        '--label','tianli.audit.purecheck=integrated-three-frontend-build','--label','tianli.audit.build='+report['run_id'],
        '--label','tianli.audit.source='+q['head'],'--network','none','--read-only','--cap-drop','ALL',
        '--security-opt','no-new-privileges','--tmpfs','/tmp:rw,nosuid,nodev,size=1073741824',
        '--tmpfs','/.automator:rw,nosuid,nodev,size=1048576',
        '--tmpfs','/hbx/plugins/uniapp-cli/node_modules/.cache:rw,nosuid,nodev,size=268435456',
        '--tmpfs','/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize/.tmp:rw,nosuid,nodev,size=16777216',
        '--workdir','/hbx/plugins/uniapp-cli']
    for key,value in [('NODE_ENV','production'),('UNI_PLATFORM','h5'),('UNI_MINIMIZE','true'),('CHOKIDAR_USEPOLLING','true'),
        ('CHOKIDAR_INTERVAL','250'),('UNI_INPUT_DIR','/input'),('UNI_OUTPUT_DIR','/output'),
        ('VUE_CLI_CONTEXT','/hbx/plugins/uniapp-cli'),('UNI_HBUILDERX_PLUGINS','/hbx/plugins'),('HOME','/tmp'),('TMPDIR','/tmp')]:
        expected_create += ['--env',key+'='+value]
    for source,target,readonly in [(runtime_input,'/input',False),(run/'output','/output',False),
        (run/'evidence','/evidence',False),(adapter,'/tool/explicit-pass-preload.cjs',True)]:
        expected_create += ['--mount','type=bind,source='+str(source)+',target='+target+(',readonly' if readonly else '')]
    for name in ['uniapp-cli','about','compile-dart-sass','uni_helpers']:
        expected_create += ['--mount','type=bind,source='+str(PLUGINS/name)+',target=/hbx/plugins/'+name+',readonly']
    expected_create += ['--mount','type=bind,source='+str(STYLUS)+',target=/hbx/plugins/compile-stylus,readonly',
        '--mount','type=bind,source='+str(overlay)+',target=/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize,readonly',
        IMAGE,'node','--require','/tool/explicit-pass-preload.cjs','/hbx/plugins/uniapp-cli/bin/uniapp-cli.js']
    need(operations['h5-image-inspect'][2]['argv'] == [DOCKER,'image','inspect','--format','{{.Id}}',IMAGE]
        and operations['h5-image-inspect'][0] == (IMAGE+'\n').encode()
        and operations['h5-container-create'][2]['argv'] == expected_create
        and operations['h5-container-create'][0] == (cid+'\n').encode()
        and operations['h5-compiler-attach'][2]['argv'] == [DOCKER,'start','--attach',cid], 'EXACT_H5_IMAGE_CREATE_ATTACH_ARGV_AND_SOURCE_MOUNTS')
    facts = loads(operations['h5-container-terminal'][0]); before_rm = loads(operations['h5-owned-id-before-remove'][0])
    inspect_template = '{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart_count":{{json .RestartCount}}}'
    for label in ['h5-container-terminal','h5-owned-id-before-remove']:
        need(operations[label][2]['argv'] == [DOCKER,'inspect','--type','container','--format',inspect_template,cid], 'EXACT_H5_TERMINAL_INSPECT')
    need(facts == before_rm and facts['id'] == cid and facts['image'] == IMAGE and facts['restart_count'] == 0
        and facts['state']['Running'] is False and facts['state']['Status'] == 'exited'
        and facts['state']['ExitCode'] == facts['state']['Pid'] == 0
        and operations['h5-owned-normal-remove'][2]['argv'] == [DOCKER,'rm',cid]
        and operations['h5-owned-normal-remove'][0] == (cid+'\n').encode(), 'NATURAL_H5_ZERO_EXACT_OWNED_NONFORCE_REMOVAL')
    stylus = dependencies['compile-stylus']
    reader._current_stylus = (STYLUS,stylus,{name:sorted(os.listdir(STYLUS if name == '.' else at(STYLUS,name)),key=order)
        for name in stylus['directory_native9']})
    current_alias_finish(reader)


def current_alias_finish(reader):
    root,snapshot,listings = reader._current_stylus
    need(os.name == 'nt' and set(snapshot['alias_identities']) == {'node_modules/.bin/'+name
        for name in ['atob','mkdirp','semver','stylus']}, 'ONLY_FOUR_FIXED_WINDOWS_ALIAS_FINISH')
    for name,native in snapshot['directory_native9'].items():
        path = root if name == '.' else at(root,name)
        need(list(ordinary(path,'dir')) == native and sorted(os.listdir(path),key=order) == listings[name], 'H5_COMPLETE_ALIAS_PARENT_DIRECTORY_PRESERVATION')
    for name,state in snapshot['alias_identities'].items():
        info = at(root,name).lstat()
        need([getattr(info,key) for key in STATE_KEYS] == state['native9']
            and getattr(info,'st_birthtime_ns',None) == state['birthtime_ns']
            and getattr(info,'st_reparse_tag',None) == state['reparse_tag']
            and state['file_attributes'] == 1056 and getattr(info,'st_file_attributes',None) in (1056,263200),
            'ALL_FOUR_ORIGINAL_ALIAS_METADATA_STILL_EXACT')
'''


def derive_source(output: str | Path, authority_pointer: dict):
    """Derive bytes and their inverse only; never call the produced materializer."""
    h = helpers()
    authority, _ = h.reviewed_authority(authority_pointer)
    source_sha = authority['source_sha']
    h.require(type(source_sha) is str and re.fullmatch('[a-f0-9]{40}',source_sha), "FIXED_CURRENT_UI_APPLICATION_SOURCE")
    before = h.read_pointer(BASE_PIN)
    build_pins = h.decode(h.read_pointer(BUILD_PINS))
    original_h5_pin = {'path': (BUILD_BASE / 'h5_build.py').as_posix(), **build_pins['files']['h5_build.py']}
    original_h5_source = h.read_pointer(original_h5_pin)
    frontend_source = h.read_pointer(FRONTEND_AUTHOR_PIN)
    finite = {}
    exec(compile(h.functions(frontend_source)['windows_lx_alias_attributes'],
                 FRONTEND_AUTHOR_PIN['path'], 'exec'), finite)
    h5_source = finite['windows_lx_alias_attributes'](original_h5_source,h)
    h.require(h5_source == h.read_pointer(H5_LX_SOURCE), 'EXACT_REVIEWED_FINITE_H5_LX_ALGORITHM')
    h5_pin = {key:H5_LX_SOURCE[key] for key in ['bytes','sha256']}
    functions = h.functions(h5_source)
    reused = []
    for name in ['stylus_tree', 'dependency_window', 'adapter_receipt']:
        raw = functions[name]
        if name == 'stylus_tree':
            raw = h.once(raw, b"    from build_common import native9, regular, relative, put\n", b'')
        reused.append(b'\n'.join(b'    '+line if line else b'' for line in raw.split(b'\n')).decode('utf-8'))
    h5_runtime = H5_RUNTIME.replace('__H5_READONLY_FUNCTIONS__', '\n\n'.join(reused)).encode('utf-8')
    runtime = ('\nCURRENT_SOURCE_SHA = '+repr(source_sha)+'\nCURRENT_SOURCE_AUTHORITY = '+repr(authority_pointer)
               +'\nCURRENT_H5_SOURCE = '+repr(h5_pin)+'\n\n'+RUNTIME+'\n'+h5_runtime.decode('utf-8')+'\n').encode('utf-8')
    contexts = []
    after = before
    def replace(label, original, replacement):
        nonlocal after
        after = h.once(after, original, replacement)
        contexts.append({'label':label, 'before':original.decode('utf-8'), 'after':replacement.decode('utf-8')})
    replace('stdlib parsing only', b'import argparse\n', b'import argparse\nimport ast\nfrom html.parser import HTMLParser\n')
    replace('canonical tracked directory projection only', b'from pathlib import Path\n', b'from pathlib import Path, PurePosixPath\n')
    old_paths = before[before.index(b'PATH_KEYS = '):before.index(b'STATE_KEYS = ')]
    replace('only current actual paths; original metadata remains fixture provenance', old_paths,
            b"PATH_KEYS = ['metadata','source','public','h5_build','merchant_build','admin_build']\n")
    replace('explicit Windows executable mode normalization; each native observation stays raw',
            b"def cross_api_identity(path_observation, fd_observation):\n", b"def cross_api_identity(path, path_observation, fd_observation):\n")
    replace('only regular Windows executable names normalize the comparison copy of FD mode',
            b"    if os.name == 'nt':\n        birth_path = path_observation['st_birthtime_ns']\n",
            b"    if os.name == 'nt':\n        right = list(right)\n        if Path(path).suffix.lower() in ('.exe','.bat','.cmd','.com') and stat.S_ISREG(left[2]) and stat.S_ISREG(right[2]):\n            right[2] |= 0o111\n        birth_path = path_observation['st_birthtime_ns']\n")
    replace('path participates in the original open-side identity bridge', b'cross_api_identity(path_before, fd_open)', b'cross_api_identity(path, path_before, fd_open)')
    replace('path participates in the original final identity bridge', b'cross_api_identity(path_after, fd_after)', b'cross_api_identity(path, path_after, fd_after)')
    replace('current proof validators and exact reused H5 readonly algorithms', b'def native_pair(reader, build, label, merchant=False):', runtime+b'def native_pair(reader, build, label, merchant=False):')
    old_functions = h.functions(before)
    finish_before = (b"    def finish(self):\n"
                     b"        for path in list(self.files):\n"
                     b"            self.file(Path(path))\n"
                     b"        for root, excluded, top, public in list(self.trees):\n"
                     b"            self.tree(Path(root), excluded, top, public)\n")
    finish_after = (b"    def finish(self):\n"
                    b"        covered = {\n"
                    b"            str(Path(spec[0]) / row['path'])\n"
                    b"            for spec, snapshot in list(self.trees.items())\n"
                    b"            for row in snapshot[0]['files']\n"
                    b"        }\n"
                    b"        for path in list(self.files):\n"
                    b"            if path not in covered:\n"
                    b"                self.file(Path(path))\n"
                    b"        for root, excluded, top, public in list(self.trees):\n"
                    b"            self.tree(Path(root), excluded, top, public)\n")
    replace('omit only precise prior tree file overlap within this finish; final full trees remain', finish_before, finish_after)
    replace('actual four-file native schema, without subprocess', old_functions['native_pair'], NATIVE.encode('utf-8'))
    for name in ['h5','merchant','admin']:
        replace('current '+name+' report adapter', old_functions[name],
                ("def "+name+"(reader, paths, meta):\n    return current_build(reader,paths,"+repr(name)+")").encode('utf-8'))
    replace('explicit fixed complete build proofs before any output', b'def materialize(paths_file, output):',
            b'def materialize(paths_file, output, proofs_file, proofs_sha256):')
    replace('bind all three actual sources and completed runs before original public read',
            b"    meta = metadata(reader,paths['metadata'])\n", b"    meta = metadata(reader,paths['metadata'])\n    paths['_current_build_proofs'] = current_proofs(reader,proofs_file,proofs_sha256,paths,output)\n")
    replace('actual separately verified dist roots rather than old work layouts',
            b"    roots = [paths['h5_build']/'dist',paths['merchant_build']/'work/dist',paths['admin_build']/'template/admin/dist']\n",
            b"    roots = [Path(evidence[name]['dist_root']) for name in NAMES]\n")
    replace('public bytes belong to this generation independently of build reuse',
            b"    original = reader.tree(paths['public'],public=True)\n",
            b"    original = reader.tree(paths['public'],excluded=('install',),top_only=True,public=True)\n"
            b"    need(original == paths['_current_build_proofs']['_current_public'], 'COMPLETE_CURRENT_DEPLOYABLE_PUBLIC_SOURCE')\n"
            b"    excluded_install = reader.tree(paths['public']/'install')\n"
            b"    need(excluded_install == paths['_current_build_proofs']['_current_public_install'], 'COMPLETE_CURRENT_EXCLUDED_INSTALL_SOURCE')\n"
            b"    public_source_scope = {'schema':1, 'source_sha':CURRENT_SOURCE_SHA, 'technical_authority':CURRENT_SOURCE_AUTHORITY,\n"
            b"        **paths['_current_build_proofs']['_current_public_source_binding'], 'public_root':paths['public'].as_posix(),\n"
            b"        'excluded_root_directories':['install'], 'nested_same_name_directories_excluded':False,\n"
            b"        'rule':'Exclude only the root install directory; fully retain its source bytes and namespace as input evidence',\n"
            b"        'included':original, 'excluded':{'install':excluded_install},\n"
            b"        'included_summary':{'file_count':len(original['files']), 'directory_count':len(original['directories']), **fp(encode(original))},\n"
            b"        'excluded_summary':{'install':{'file_count':len(excluded_install['files']), 'directory_count':len(excluded_install['directories']),\n"
            b"            **fp(encode(excluded_install))}}, 'excluded_source_fully_read_and_rechecked':True}\n")
    replace('public scope evidence outside the unchanged five materials and ten-key profile',
            b"        'materials':emitted,'builds':evidence,'input_inventory':inventory_pin,\n",
            b"        'materials':emitted,'builds':evidence,'input_inventory':inventory_pin,\n        'public_source_scope':public_source_scope,\n")
    original_materialize = h.functions(after)['materialize']
    h.require(original_materialize.count(b'    reader.finish()') == 3, 'THREE_ORIGINAL_COMPLETE_INPUT_RECHECKS')
    replace('retain all three finish calls and repeat the four exceptional alias observations', original_materialize,
            original_materialize.replace(b'    reader.finish()', b'    reader.finish(); current_alias_finish(reader)'))
    replace('all actual source proof and tool roots remain outside the output before its first write',
            b'    # Every true source/proof/tool/output was verified before the first output write.\n',
            b'    current_output_disjoint(reader,output)\n    # Every true source/proof/tool/output was verified before the first output write.\n')
    replace('mandatory current proof CLI; missing real builds cannot fall back to old metadata',
            b"    parser.add_argument('--output',required=True)\n", b"    parser.add_argument('--output',required=True)\n    parser.add_argument('--current-build-proofs',required=True)\n    parser.add_argument('--current-build-proofs-sha256',required=True)\n")
    replace('pass explicit fixed proof identity to materialize',
            b"    result = materialize(Path(args.paths_json),Path(args.output))\n",
            b"    result = materialize(Path(args.paths_json),Path(args.output),Path(args.current_build_proofs),args.current_build_proofs_sha256)\n")
    recovered = after
    for change in reversed(contexts):
        recovered = h.once(recovered, change['after'].encode('utf-8'), change['before'].encode('utf-8'))
    h.require(recovered == before, 'ENTIRE_ORIGINAL_R3_PRODUCER_EXACT_INVERSE')
    new_functions = h.functions(after)
    changed = {name for name in old_functions if old_functions[name] != new_functions[name]}
    h.require(changed == {'cross_api_identity','Inputs','native_pair','h5','merchant','admin','materialize','main'}, 'FINITE_CURRENT_REPORT_AND_APPROVED_IDENTITY_CHANGES_ONLY')
    for name in ['png','collision_check','write_new','metadata','ordinary','native_observation','resources']:
        h.require(old_functions[name] == new_functions[name], 'ORIGINAL_'+name.upper()+'_BODY_EXACT')
    compile(after, 'candidate/ms4-ui-materials.py', 'exec')
    out = Path(output)
    h.require(out.is_absolute() and not out.exists() and not out.is_symlink(), 'NEW_UI_SOURCE_ONLY_OUTPUT')
    out = out.resolve()
    h.ordinary(out, missing_leaf=True)
    for protected in (BASE.parent.parent, BUILD_BASE, Path(authority['candidate_root']), Path(authority['validation_root']), Path(__file__).resolve().parents[3]):
        h.require(not out.is_relative_to(protected), 'SOURCE_OUTPUT_OUTSIDE_ALL_ORIGINAL_INPUTS')
    one = ('ms4-ui-materials.py',)
    forward = h.make_patch({'ms4-ui-materials.py':before}, {'ms4-ui-materials.py':after}, one)
    reverse = h.make_patch({'ms4-ui-materials.py':after}, {'ms4-ui-materials.py':before}, one)
    h.require(h.apply_patch({'ms4-ui-materials.py':before},forward,one)['ms4-ui-materials.py'] == after
              and h.apply_patch({'ms4-ui-materials.py':after},reverse,one)['ms4-ui-materials.py'] == before,
              'STANDARD_WHOLE_FILE_FORWARD_AND_REVERSE')
    metadata = {'schema':1, 'scope':'CURRENT_UI_MATERIAL_PRODUCER_SOURCE_ONLY', 'status':'SOURCE_CANDIDATE_NOT_MATERIALS_OR_UI',
                'source_sha':source_sha, 'technical_authority':authority_pointer, 'baseline':BASE_PIN,
                'reused_h5_source':H5_LX_SOURCE, 'original_h5_basis':original_h5_pin,
                'finite_h5_source_author':FRONTEND_AUTHOR_PIN, 'finite_h5_whole_file_inverse_exact':True,
                'historical_h5_alias_receipts':'Both actual before/after receipts are read only; exact native9/birth/tag/query bind the preserved snapshot and original native streams. Present raw values never replace historical observations.',
                'h5_alias_raw_attributes':[1056,263200], 'h5_alias_stable_snapshot_attributes':1056,
                'reused_h5_readonly_functions':['stylus_tree','dependency_window','adapter_receipt'],
                'changed_original_functions':sorted(changed), 'whole_file_inverse_exact':True,
                'changed_original_methods':{'Inputs':['file: two bridge call arguments only', 'finish: omit precise tree-covered keys from the duplicate file pass; final full tree rereads unchanged']},
                'native_identity_scope':'Only regular Windows exe/bat/cmd/com comparison FD mode OR0111; all other mode bits/common fields/birth and both raw nine-field observations retained',
                'original_preserved_bodies':['ordinary','native_observation','png','collision_check','write_new','metadata','resources'],
                'all_undeclared_original_function_bodies_exact':True, 'producer_build_Docker_Node_materials_UI':'NOT RUN',
                'required_current_build_proofs':'Explicit file plus fixed SHA; all three actual outer/Run/source packets required before output',
                'build_reuse':'Each original build head/authority remains real; complete frontend_source files/directories/Git mode/blob/bytes/SHA must equal this independently reviewed generation. Public is checked against the current capture separately.',
                 'public_source_scope':'Only the root install directory is excluded from deployable public. Both complete included and excluded namespaces and bytes are independently checked against the same current capture and rechecked by the original Inputs.finish; nested install directories are included.',
                'contexts':contexts}
    files = {'before/ms4-ui-materials.py':before, 'candidate/ms4-ui-materials.py':after,
             'FORWARD.patch':forward, 'REVERSE.patch':reverse, 'SOURCE-DERIVATION.json':h.encode(metadata)}
    files['PINS.json'] = h.encode({'schema':1,'scope':metadata['scope'],'members':{name:h.pin(raw) for name,raw in files.items()}})
    return h,out,files


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True)
    parser.add_argument('--authority-file', required=True)
    parser.add_argument('--authority-sha256', required=True)
    args = parser.parse_args()
    h = helpers(); path = Path(args.authority_file); raw = h.stable_read(path)
    h.require(re.fullmatch('[a-f0-9]{64}',args.authority_sha256) and h.pin(raw)['sha256'] == args.authority_sha256, 'FIXED_AUTHORITY_BYTES')
    pointer = {'path':path.as_posix(),'bytes':len(raw),'sha256':args.authority_sha256}
    h,out,files = derive_source(args.output,pointer)
    out.parent.mkdir(parents=True,exist_ok=True); h.ordinary(out.parent); out.mkdir(); h.write_set(out,files)
    print(h.encode({'status':'SOURCE_CANDIDATE_NOT_MATERIALS_OR_UI','output':out.as_posix(),'pins':h.pin(files['PINS.json'])}).decode(),end='')


if __name__ == '__main__':
    main()
