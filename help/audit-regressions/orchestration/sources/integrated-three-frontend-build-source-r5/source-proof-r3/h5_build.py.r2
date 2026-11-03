"""Finite original two-pass HBuilderX H5 compiler route; no work at import."""
from pathlib import Path
import hashlib
import json
import re

from build_common import (ROOT, require, read, pin, fixed, create, put, native, tree,
                          identities, frontend_source, dist_tree)

DOCKER = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
IMAGE = 'sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293'
PLUGINS = Path('E:/HBuilderX/plugins')
STYLUS = Path('H:/天俪商城/artifacts/ms1-task7/uni-h5-build-prep/dependency-plugins/compile-stylus')
DEPENDENCIES = {name: PLUGINS / name for name in ['uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers']}
DEPENDENCIES['compile-stylus'] = STYLUS


def dependency_window(authority):
    require(set(authority) == set(DEPENDENCIES), 'EXACT_FIVE_ORIGINAL_H5_DEPENDENCY_ROOTS')
    snapshots = {}
    for name, root in DEPENDENCIES.items():
        row = authority[name]
        require(set(row) == {'root', 'files', 'directories'} and Path(row['root']).resolve() == root.resolve(), 'FIXED_H5_DEPENDENCY_ROOT')
        value = tree(root, True)
        require(value == {key: row[key] for key in ['files', 'directories']}, 'COMPLETE_FIXED_COMPILER_OR_STYLUS_TREE')
        snapshots[name] = {'tree': value, 'native9': identities(root, value['files'])}
    pkg = json.loads(read(STYLUS / 'node_modules/stylus/package.json'))
    require(pkg['name'] == 'stylus' and re.fullmatch(r'\d+\.\d+\.\d+', pkg['version']) is not None
            and tuple(map(int, pkg['version'].split('.'))) >= (0, 52, 4), 'ORIGINAL_STYLUS_PEER_REQUIREMENT')
    for leaf in ['lib/visitor/evaluator.js', 'lib/nodes/index.js', 'lib/utils.js']:
        read(STYLUS / 'node_modules/stylus' / leaf)
    original_stylus = json.loads(read(ROOT / 'h5-tools/stylus-inputs.json'))
    require(original_stylus['root'] == STYLUS.as_posix() and original_stylus['installed_packages'] == 28
            and len(original_stylus['files']) == 30, 'ORIGINAL_FIXED_STYLUS_INPUT_CONTRACT')
    for row in original_stylus['files']:
        raw = read(STYLUS / row['path'])
        require(pin(raw) == {key: row[key] for key in ['bytes', 'sha256']}, 'ORIGINAL_COMPLETE_STYLUS_SOURCE_LEAF')
        if row['kind'] == 'installed_package':
            require(json.loads(raw)['version'] == row['version'], 'ORIGINAL_FIXED_STYLUS_INSTALLED_VERSION')
    return snapshots


def adapter_receipt(path, guards, manifest):
    receipt = json.loads(read(path))
    passes, emitted = receipt['passes'], receipt['after_emit_inventories']
    generated, callbacks = receipt['generated_modules'], receipt['callback_observations']
    require(receipt['schema'] == 1 and receipt['status'] == 'SECOND_PASS_OBSERVED'
            and receipt['factory_count'] == 1 and receipt['callback_count'] == 1
            and receipt['first_callback_success'] is True and receipt['explicit_invalidations'] == 1
            and receipt['purged_module_count'] == 8 and len(passes) == 2
            and [x['pass'] for x in passes] == [1, 2]
            and all(x['has_errors'] is False and x['asset_count'] > 0 for x in passes)
            and receipt['after_emit_passes'] == [2] and len(emitted) == 1 and emitted[0]['pass'] == 2
            and len(generated) == 8 and all(type(x['bytes']) is int and x['bytes'] > 0
                and re.fullmatch('[a-f0-9]{64}', x['sha256']) is not None for x in generated)
            and receipt['source_guards'] == guards and receipt['phase_counts']['failed'] == 0
            and receipt['error_observations'] == [] and len(callbacks) == 1
            and callbacks[0]['index'] == 1 and callbacks[0]['error_present'] is False
            and receipt['failure_close_requested'] is False and receipt['native_failure_exit_requested'] is False,
            'ORIGINAL_FULL_TWO_PASS_CALLBACK_AND_EIGHT_COMPILER_GUARDS')
    by_path = {row['path']: row for row in manifest['files']}
    names = sorted(by_path, key=lambda value: value.encode('utf-16-be'))
    signature = ''.join(name + '\0' + str(by_path[name]['bytes']) + '\0' + by_path[name]['sha256'] + '\n' for name in names)
    digest = hashlib.sha256(signature.encode('utf-8')).hexdigest()
    require(emitted[0]['file_count'] == len(by_path)
            and emitted[0]['bytes'] == sum(x['bytes'] for x in by_path.values())
            and emitted[0]['manifest_sha256'] == digest and 'index.html' in by_path,
            'EXACT_ORDINAL_PATH_NUL_BYTES_NUL_SHA_LF_AFTER_EMIT_INVENTORY')
    return {'receipt_sha256': pin(read(path))['sha256'], 'dist_inventory_sha256': digest,
            'passes': 2, 'after_emit_passes': [2]}


def inspect(context, label, cid):
    template = '{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart_count":{{json .RestartCount}}}'
    raw, _ = native(context, label, [DOCKER, 'inspect', '--type', 'container', '--format', template, cid])
    facts = json.loads(raw)
    require(facts['id'] == cid and facts['image'] == IMAGE and facts['restart_count'] == 0, 'SAME_REAL_OWNED_COMPILER_ID_IMAGE_GENERATION')
    return facts


def run(context):
    q, out, evidence = context['qualification'], context['out'], context['evidence']
    authority = json.loads(fixed(q['dependency_authority']['h5']))
    dependencies_before = dependency_window(authority)
    sources = frontend_source(context, 'h5')
    exported = out / 'template/uni-app'
    runtime_input = out / 'input'
    source_states = identities(out, sources)
    require(not runtime_input.exists(), 'NEW_H5_RUNTIME_INPUT')
    for name, expected in sources.items():
        leaf = name.removeprefix('template/uni-app/')
        raw = read(out / name)
        require(pin(raw) == expected, 'FINAL_GIT_H5_SOURCE_BEFORE_RUNTIME_COPY')
        create(runtime_input / leaf, raw)
    exported_tree = tree(exported)
    for name in exported_tree['directories']:
        (runtime_input / name).mkdir(parents=True, exist_ok=True)
    input_tree = tree(runtime_input)
    require(input_tree == exported_tree
            and input_tree['files'] == {name.removeprefix('template/uni-app/'): row for name, row in sources.items()}, 'FULL_FINAL_H5_RUNTIME_NAMESPACE')
    input_states = identities(runtime_input, input_tree['files'])
    adapter = ROOT / 'h5-tools/explicit-pass-preload.cjs'
    adapter_before = pin(read(adapter))
    guards = json.loads(read(ROOT / 'h5-tools/compiler-source-guards.json'))
    require(type(guards) is list and len(guards) == 8, 'ORIGINAL_EIGHT_COMPILER_SOURCE_GUARDS')
    for row in guards:
        require(set(row) == {'path', 'sha256'} and pin(read(PLUGINS / 'uniapp-cli' / row['path']))['sha256'] == row['sha256'], 'ACTUAL_INSTALLED_COMPILER_SOURCE_GUARD')
    overlay = ROOT / 'h5-tools/compiler-overlays/vue-cli-plugin-uni-optimize'
    overlay_manifest = json.loads(read(ROOT / 'h5-tools/optimize-overlay-manifest.json'))
    overlay_expected = {row['path']: {key: row[key] for key in ['bytes', 'sha256']} for row in overlay_manifest['entries']}
    require(len(overlay_expected) == overlay_manifest['copied_file_count'] == 11
            and tree(overlay, True)['files'] == overlay_expected, 'ORIGINAL_COMPLETE_ELEVEN_COMPILER_OVERLAY_FILES')
    require((overlay / '.tmp').is_dir() and list((overlay / '.tmp').iterdir()) == [], 'EXACT_EMPTY_OPTIMIZE_CACHE_MOUNTPOINT')
    for name, expected in overlay_expected.items():
        require(pin(read(Path(overlay_manifest['source_package']) / name)) == expected, 'ORIGINAL_INSTALLED_OPTIMIZE_SOURCE_EQUALS_OVERLAY')
    output = out / 'output'
    output.mkdir()
    require(native(context, 'h5-image-inspect', [DOCKER, 'image', 'inspect', '--format', '{{.Id}}', IMAGE])[0].decode().strip() == IMAGE, 'EXISTING_EXACT_LOCAL_NODE_IMAGE')
    run_id = context['run_id']
    argv = [DOCKER, 'create', '--pull=never', '--name', 'tianli-integrated-h5-build-' + run_id,
            '--label', 'tianli.audit.purecheck=integrated-three-frontend-build', '--label', 'tianli.audit.build=' + run_id,
            '--label', 'tianli.audit.source=' + q['head'], '--network', 'none', '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--tmpfs', '/tmp:rw,nosuid,nodev,size=1073741824',
            '--tmpfs', '/.automator:rw,nosuid,nodev,size=1048576',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/.cache:rw,nosuid,nodev,size=268435456',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize/.tmp:rw,nosuid,nodev,size=16777216',
            '--workdir', '/hbx/plugins/uniapp-cli']
    for key, value in [('NODE_ENV', 'production'), ('UNI_PLATFORM', 'h5'), ('UNI_MINIMIZE', 'true'),
                       ('CHOKIDAR_USEPOLLING', 'true'), ('CHOKIDAR_INTERVAL', '250'),
                       ('UNI_INPUT_DIR', '/input'), ('UNI_OUTPUT_DIR', '/output'),
                       ('VUE_CLI_CONTEXT', '/hbx/plugins/uniapp-cli'), ('UNI_HBUILDERX_PLUGINS', '/hbx/plugins'),
                       ('HOME', '/tmp'), ('TMPDIR', '/tmp')]:
        argv += ['--env', key + '=' + value]
    for source, target, readonly in [(runtime_input, '/input', False), (output, '/output', False),
                                    (evidence, '/evidence', False), (adapter, '/tool/explicit-pass-preload.cjs', True)]:
        argv += ['--mount', 'type=bind,source=' + str(source) + ',target=' + target + (',readonly' if readonly else '')]
    for name in ['uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers']:
        argv += ['--mount', 'type=bind,source=' + str(PLUGINS / name) + ',target=/hbx/plugins/' + name + ',readonly']
    argv += ['--mount', 'type=bind,source=' + str(STYLUS) + ',target=/hbx/plugins/compile-stylus,readonly',
             '--mount', 'type=bind,source=' + str(overlay) + ',target=/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize,readonly',
             IMAGE, 'node', '--require', '/tool/explicit-pass-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js']
    cid = native(context, 'h5-container-create', argv)[0].decode().strip()
    require(re.fullmatch('[a-f0-9]{64}', cid) is not None, 'ACTUAL_NEW_COMPILER_CID')
    put(evidence / 'OWNED-COMPILER.json', {'container_id': cid, 'run_id': run_id, 'source_head': q['head'], 'image': IMAGE})
    _, attached = native(context, 'h5-compiler-attach', [DOCKER, 'start', '--attach', cid], allow_failure=True, allow_stderr=True)
    facts = inspect(context, 'h5-container-terminal', cid)
    require(attached['native_exit'] == attached['wait_returncode'] == 0
            and facts['state']['Running'] is False and facts['state']['Status'] == 'exited'
            and facts['state']['ExitCode'] == 0 and facts['state']['Pid'] == 0, 'ORIGINAL_COMPILER_NATURAL_ZERO_REQUIRED')
    manifest = dist_tree(output)
    observed = adapter_receipt(evidence / 'adapter-receipt.json', guards, manifest)
    require(tree(runtime_input) == input_tree and identities(runtime_input, input_tree['files']) == input_states
            and all(pin(read(out / name)) == expected for name, expected in sources.items())
            and identities(out, sources) == source_states, 'ALL_FINAL_GIT_AND_ACTUAL_RUNTIME_SOURCE_UNCHANGED')
    require(dependency_window(authority) == dependencies_before and pin(read(adapter)) == adapter_before,
            'ALL_ORIGINAL_HBUILDERX_STYLUS_AND_ADAPTER_INPUTS_UNCHANGED')
    before_rm = inspect(context, 'h5-owned-id-before-remove', cid)
    require(before_rm == facts, 'SAME_NATURAL_TERMINAL_BEFORE_NONFORCE_REMOVE')
    removed = native(context, 'h5-owned-normal-remove', [DOCKER, 'rm', cid])[0]
    require(removed == (cid + '\n').encode(), 'EXACT_OWNED_NONFORCE_REMOVE_ACK')
    put(out / 'NATIVE-INPUT-PRESERVATION.json', {'exported_source': source_states, 'runtime_source': input_states,
                                              'compiler_dependencies': dependencies_before, 'adapter': adapter_before})
    return {'frontend': 'h5', 'source_head': q['head'], 'source_assembly': 'ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_SNAPSHOT',
            'source_files': len(sources), 'dist_root': output.as_posix(), 'dist': manifest,
            'compiler': {'image': IMAGE, 'container_id': cid, 'natural_exit': 0, 'nonforce_removed': True, **observed},
            'HTTP_DOM_devices': 'NOT_RUN', 'installation_or_network_fallback': False}
