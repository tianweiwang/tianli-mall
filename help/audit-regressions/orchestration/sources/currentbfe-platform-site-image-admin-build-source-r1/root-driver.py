"""Build the exact local platform source with existing offline dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import time

REPO = Path('H:/天俪商城/worktrees/product-typed-media-normal')
HEAD = 'bfe311cbb727af093fe73fb4ce22ead58e8f3345'
OLD = Path('H:/天俪商城/artifacts/ms0b-task8/frontend/admin')
OUT = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbfe-platform-site-image-admin-build-actual-r1')
NODE_ROOT = Path('H:/天俪商城/artifacts/ms3-build-tools/source477732-node22/node-v22.23.3-win-x64')
NODE = NODE_ROOT / 'node.exe'
NPM = NODE_ROOT / 'node_modules/npm/bin/npm-cli.js'
OVERLAY = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbfe-owned-video-ui-source-r2')
OVERLAY_PINS = {'bytes': 29498, 'sha256': '48f94713647519355423a1bb552faa8d56cdc355699e1aeda1c799d9af0f853b'}
OVERLAY_FILES = ('template/admin/src/api/product-media.js', 'template/admin/src/components/uploadVideo2/index.vue', 'template/admin/src/components/uploadVideo2/model.js', 'template/admin/src/components/uploadVideo2/videoModal.vue', 'template/admin/src/components/wangEditor/editor.js', 'template/admin/src/components/wangEditor/html.js', 'template/admin/src/components/wangEditor/index.vue', 'template/admin/src/pages/product/productAdd/index.vue')
SOURCE_ROOT = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbfe-platform-site-image-admin-build-source-r1')
UI_OVERLAY = SOURCE_ROOT / 'ui-source'
UI_FILES = ('template/admin/src/api/product-media.js', 'template/admin/src/components/uploadPictures/index.vue')


def require(value, code):
    if not value:
        raise RuntimeError(code)


def regular(path):
    info = path.lstat()
    require(not path.is_symlink() and not (getattr(info, 'st_file_attributes', 0)
        & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 1024)), 'ORDINARY_FILE_OR_DIRECTORY')


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def native9(path):
    info = Path(path).lstat()
    return [info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_uid, info.st_gid,
        info.st_size, info.st_mtime_ns, info.st_ctime_ns]


def identities(root, names):
    return {name: native9(root / name) for name in names}


def create(path, raw):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'COMPLETE_NEW_WRITE')
    require(path.read_bytes() == raw, 'NEW_WRITE_READBACK')


def native(label, argv, cwd=REPO, env=None):
    print(json.dumps({'stage': label, 'state': 'starting'}), flush=True)
    started_ns = time.time_ns()
    with (OUT / (label + '.stdout')).open('xb') as stdout, (OUT / (label + '.stderr')).open('xb') as stderr:
        process = subprocess.Popen(argv, cwd=cwd, env=env, stdout=stdout, stderr=stderr)
        create(OUT / (label + '.created.json'), (json.dumps({'pid': process.pid, 'argv': argv,
            'cwd': str(cwd), 'started_ns': started_ns}) + '\n').encode())
        wait_returncode = process.wait()
        code = process.returncode
    require(type(code) is int and code == wait_returncode, 'SAME_HANDLE_NATIVE_EQUALS_WAIT')
    streams = {}
    for name in ['stdout', 'stderr']:
        path = OUT / (label + '.' + name)
        before = native9(path)
        raw = path.read_bytes()
        require(native9(path) == before, 'COMPLETE_TERMINAL_STREAM_IDENTITY')
        streams[name] = {'path': path.as_posix(), **pin(raw), 'native9': before}
    create(OUT / (label + '.native.json'), (json.dumps({'pid': process.pid, 'native_exit': code,
        'wait_returncode': wait_returncode, 'started_ns': started_ns, 'finished_ns': time.time_ns(),
        **streams}) + '\n').encode())
    print(json.dumps({'stage': label, 'native_exit': code}), flush=True)
    require(code == 0, 'NATIVE_STAGE_' + label)
    return (OUT / (label + '.stdout')).read_bytes()


def git(label, args):
    raw = native(label, ['git', '-c', 'safe.directory=' + REPO.as_posix(), '-C', str(REPO), *args],
        env=dict(os.environ, GIT_OPTIONAL_LOCKS='0'))
    require((OUT / (label + '.stderr')).read_bytes() == b'', 'GIT_STDERR_EMPTY')
    return raw


def tree(root, exclude_cache=False):
    regular(root)
    rows = {}
    for directory, directories, files in os.walk(root, followlinks=False):
        regular(Path(directory))
        for name in directories:
            regular(Path(directory) / name)
        if exclude_cache:
            directories[:] = [name for name in directories if name not in ['.cache', '.tmp', '.git']]
        directories.sort()
        for name in sorted(files):
            path = Path(directory) / name
            regular(path)
            rows[path.relative_to(root).as_posix()] = pin(path.read_bytes())
    return dict(sorted(rows.items()))


class Resources(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        self.urls += [value for key, value in attrs
            if (tag == 'script' and key == 'src') or (tag == 'link' and key == 'href')]


def main():
    parser = argparse.ArgumentParser(description='One original offline full admin build with fixed current-source and two UI overlays.')
    parser.add_argument('--pins-sha256', required=True)
    args = parser.parse_args()
    require(Path(__file__).resolve().parent == SOURCE_ROOT, 'EXACT_SOURCE_PACKAGE')
    source_pins_raw = (SOURCE_ROOT / 'PINS.json').read_bytes()
    require(hashlib.sha256(source_pins_raw).hexdigest() == args.pins_sha256, 'ROOT_QUALIFIED_EXACT_SOURCE_PINS')
    source_pins = json.loads(source_pins_raw)
    source_windows = {str(SOURCE_ROOT / 'PINS.json'): {**pin(source_pins_raw), 'native9': native9(SOURCE_ROOT / 'PINS.json')}}
    for name, expected in source_pins['files'].items():
        path = SOURCE_ROOT / name
        regular(path)
        first = native9(path)
        require(pin(path.read_bytes()) == expected and native9(path) == first, 'COMPLETE_FIXED_SOURCE_MEMBER')
        source_windows[str(path)] = {**expected, 'native9': first}
    require({p.relative_to(SOURCE_ROOT).as_posix() for p in SOURCE_ROOT.rglob('*') if p.is_file()}
        == set(source_pins['files']) | {'PINS.json'}, 'EXACT_FIXED_SOURCE_NAMESPACE')
    bindings = json.loads((SOURCE_ROOT / 'BINDINGS.json').read_bytes())
    require(bindings['source_identity']['base_head'] == HEAD and len(bindings['controlled_sources']) == 27,
        'EXACT_CURRENT27_SOURCE_IDENTITY')
    controlled_native9 = identities(REPO, bindings['controlled_sources'])
    require(all(pin((REPO / name).read_bytes()) == expected for name, expected in bindings['controlled_sources'].items()),
        'ALL27_CURRENT_CONTROLLED_SOURCE_BYTES')
    require(not OUT.exists() and not OUT.is_symlink(), 'NEW_OUTPUT_ONLY')
    OUT.mkdir()
    create(OUT / 'root-driver.py', Path(__file__).read_bytes())
    require(git('head-before', ['rev-parse', 'HEAD']).decode().strip() == HEAD, 'EXACT_HEAD')
    require(git('status-before', ['status', '--porcelain=v1', '--untracked-files=all']) == bindings['expected_status_porcelain_v1'].encode('utf-8'), 'EXACT_CONTROLLED27_MAIN')
    helper = 'help/audit-regressions/frontend-source-check.cjs'
    names = git('source-files', ['ls-files', '-z', '--', 'template/admin', helper]).decode().rstrip('\0').split('\0')
    platform_names = [name for name in names if name.startswith('template/admin/')]
    require(len(names) == len(set(names)) and len(platform_names) > 0
        and set(names) == set(platform_names) | {helper} and set(names) == set(bindings['current_sources']), 'ACTUAL_COMPLETE_CURRENT_PLATFORM_AND_PREBUILD_HELPER')
    require(all(not (Path(name).name.startswith('.env') and Path(name).name.endswith('.local'))
        for name in names), 'NO_LOCAL_ENV_SOURCE')
    sources = {}
    for relative in names:
        path = REPO / relative
        regular(path)
        raw = path.read_bytes()
        require(pin(raw) == bindings['current_sources'][relative] and raw == (SOURCE_ROOT / 'source' / relative).read_bytes(), 'FIXED_COMPLETE_CURRENT_SOURCE')
        sources[relative] = pin(raw)
        create(OUT / relative, raw)
    create(OUT / 'SOURCE-INPUTS.json', (json.dumps(sources, sort_keys=True, indent=2) + '\n').encode())
    original_source_native9 = identities(REPO, sources)
    regular(OVERLAY / 'PINS.json')
    overlay_pins_native9 = native9(OVERLAY / 'PINS.json')
    overlay_pins_raw = (OVERLAY / 'PINS.json').read_bytes()
    require(pin(overlay_pins_raw) == OVERLAY_PINS and native9(OVERLAY / 'PINS.json') == overlay_pins_native9,
        'FIXED_UI_R2_PINS')
    overlay_document = json.loads(overlay_pins_raw)
    overlay_inputs = {str(OVERLAY / 'PINS.json'): {**OVERLAY_PINS, 'native9': overlay_pins_native9}}
    assembled_sources = dict(sources)
    for index, relative in enumerate(OVERLAY_FILES, 1):
        require(relative in sources and relative in platform_names, 'OVERLAY_TRACKED_IN_EXACT_BASE')
        inputs = {}
        for kind in ['before', 'candidate']:
            path = OVERLAY / kind / relative
            regular(path)
            before_native9 = native9(path)
            raw = path.read_bytes()
            require(native9(path) == before_native9, 'OVERLAY_COMPLETE_SOURCE_WINDOW')
            expected = overlay_document['files'][kind + '/' + relative]
            require(expected == {'path': path.as_posix(), **pin(raw)}, 'EXACT_OVERLAY_SOURCE_POINTER')
            overlay_inputs[str(path)] = {**pin(raw), 'native9': before_native9}
            require(raw == (SOURCE_ROOT / 'legacy-ui' / kind / relative).read_bytes(), 'FROZEN_ORIGINAL_EIGHT_UI_SOURCE_WITNESS')
            inputs[kind] = raw
        require(git('overlay-before-' + str(index), ['show', HEAD + ':' + relative]) == inputs['before']
            and (REPO / relative).read_bytes() == inputs['candidate']
            and (OUT / relative).read_bytes() == inputs['candidate'], 'EXACT_ORIGINAL_GIT_BASE_AND_CURRENT_ADOPTED_EIGHT_UI')
        target = OUT / relative
        target.unlink()  # Only the newly exported OUT copy; never REPO or the fixed overlay.
        create(target, inputs['candidate'])
        assembled_sources[relative] = pin(inputs['candidate'])
    ui_pins_raw = (UI_OVERLAY / 'PINS.json').read_bytes()
    require(pin(ui_pins_raw) == bindings['UI_PINS'], 'FIXED_TWO_UI_CANDIDATE_PINS')
    ui_document = {row['path']: {key: row[key] for key in ['bytes', 'sha256']}
        for row in json.loads(ui_pins_raw)['files']}
    overlay_inputs[str(UI_OVERLAY / 'PINS.json')] = {**pin(ui_pins_raw), 'native9': native9(UI_OVERLAY / 'PINS.json')}
    for relative in UI_FILES:
        require(relative in sources and relative in platform_names, 'NEW_UI_OVERLAY_IS_COMPLETE_TRACKED_SOURCE')
        values = {}
        for kind in ['before', 'candidate']:
            path = UI_OVERLAY / kind / relative
            regular(path)
            first = native9(path)
            raw = path.read_bytes()
            require(pin(raw) == ui_document[kind + '/' + relative] and native9(path) == first, 'EXACT_NEW_UI_OVERLAY_BYTES')
            overlay_inputs[str(path)] = {**pin(raw), 'native9': first}
            values[kind] = raw
        require((REPO / relative).read_bytes() == values['before'] and (OUT / relative).read_bytes() == values['before'],
            'TWO_CANDIDATES_OVERLAY_ONLY_CURRENT27_WORKTREE')
        target = OUT / relative
        target.unlink()  # Only newly exported OUT copy, never worktree or fixed Source.
        create(target, values['candidate'])
        assembled_sources[relative] = pin(values['candidate'])
    require(assembled_sources == bindings['assembled_sources'], 'EXACT_COMPLETE_CURRENT_PLUS_TWO_ASSEMBLY')
    create(OUT / 'OVERLAY-INPUTS.json', (json.dumps(overlay_inputs, sort_keys=True, indent=2) + '\n').encode())
    create(OUT / 'ASSEMBLED-SOURCE-INPUTS.json', (json.dumps(assembled_sources, sort_keys=True, indent=2) + '\n').encode())
    copied_source_native9 = identities(OUT, assembled_sources)
    runtime_native9 = {str(path): native9(path) for path in [NODE, NPM]}
    require(pin(NODE.read_bytes()) == {key: bindings['runtime']['node'][key] for key in ['bytes', 'sha256']}
        and pin(NPM.read_bytes()) == {key: bindings['runtime']['npm'][key] for key in ['bytes', 'sha256']}, 'SAME_FIXED_NODE22_NPM_RUNTIME')
    for name in ['package.json', 'package-lock.json']:
        require((REPO / 'template/admin' / name).read_bytes() == (OLD / name).read_bytes(), 'SAME_OFFLINE_PACKAGE_AND_LOCK')
    package_versions = {}
    for name in ['vue', 'vue-template-compiler', '@vue/cli-service', 'webpack', 'axios', 'uglifyjs-webpack-plugin']:
        package_versions[name] = json.loads((OLD / 'node_modules' / name / 'package.json').read_bytes())['version']
    require(package_versions == {'vue': '2.7.16', 'vue-template-compiler': '2.7.16', '@vue/cli-service': '3.12.1',
        'webpack': '4.47.0', 'axios': '0.18.1', 'uglifyjs-webpack-plugin': '2.2.0'}, 'ACTUAL_OFFLINE_VERSIONS')
    print(json.dumps({'stage': 'offline-dependency-inventory', 'state': 'starting'}), flush=True)
    modules = tree(OLD / 'node_modules', True)
    dependency_manifest_raw = (SOURCE_ROOT / 'authority/DEPENDENCY-INPUTS.json').read_bytes()
    require(pin(dependency_manifest_raw) == bindings['dependency_manifest'] and modules == json.loads(dependency_manifest_raw), 'SAME_COMPLETE_ORIGINAL_OFFLINE_DEPENDENCIES')
    create(OUT / 'DEPENDENCY-INPUTS.json', (json.dumps(modules, sort_keys=True, indent=2) + '\n').encode())
    original_dependency_native9 = identities(OLD / 'node_modules', modules)
    destination = OUT / 'template/admin/node_modules'
    print(json.dumps({'stage': 'physical-dependency-copy', 'files': len(modules)}), flush=True)
    for relative, expected in modules.items():
        source = OLD / 'node_modules' / relative
        require(pin(source.read_bytes()) == expected, 'ORIGINAL_DEPENDENCY_BEFORE_COPY')
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        require(not target.exists(), 'NEW_PHYSICAL_COPY')
        shutil.copyfile(source, target)
        require(target.stat().st_nlink == 1 and pin(target.read_bytes()) == expected, 'ACTUAL_DEPENDENCY_COPY_BYTES')
    require(tree(destination, True) == modules, 'COMPLETE_COPY')
    copied_dependency_native9 = identities(destination, modules)
    create(OUT / 'empty-user.npmrc', b'')
    create(OUT / 'empty-global.npmrc', b'')
    env = {key: value for key, value in os.environ.items() if key.upper() in
        ['SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'PROCESSOR_ARCHITECTURE', 'NUMBER_OF_PROCESSORS']}
    for directory in ['tmp', 'empty-home', 'empty-home/AppData/Roaming', 'empty-home/AppData/Local']:
        (OUT / directory).mkdir(parents=True, exist_ok=True)
    env.update(PATH=str(NODE_ROOT) + os.pathsep + os.environ.get('SYSTEMROOT', 'C:/Windows') + '/System32',
        NPM_CONFIG_OFFLINE='true', NPM_CONFIG_USERCONFIG=str(OUT / 'empty-user.npmrc'),
        NPM_CONFIG_GLOBALCONFIG=str(OUT / 'empty-global.npmrc'), NPM_CONFIG_CACHE=str(OUT / 'npm-cache'),
        NPM_CONFIG_UPDATE_NOTIFIER='false', NPM_CONFIG_AUDIT='false', NPM_CONFIG_FUND='false',
        NODE_OPTIONS='--max-old-space-size=8192', TEMP=str(OUT / 'tmp'), TMP=str(OUT / 'tmp'),
        USERPROFILE=str(OUT / 'empty-home'), HOME=str(OUT / 'empty-home'),
        APPDATA=str(OUT / 'empty-home/AppData/Roaming'), LOCALAPPDATA=str(OUT / 'empty-home/AppData/Local'))
    require(native('node-version', [str(NODE), '--version'], OUT, env).decode().strip() == 'v22.23.3', 'NODE_VERSION')
    require(native('npm-version', [str(NODE), str(NPM), '--version'], OUT, env).decode().strip() == '10.9.9', 'NPM_VERSION')
    native('npm-build', [str(NODE), str(NPM), 'run', 'build'], OUT / 'template/admin', env)
    log = (OUT / 'npm-build.stdout').read_text(encoding='utf8', errors='replace')
    require('prebuild' in log and 'frontend-source-check.cjs' in log, 'ACTUAL_PREBUILD_HOOK')
    dist = OUT / 'template/admin/dist'
    inventory = tree(dist)
    require(len(inventory) > 1 and 'index.html' in inventory, 'ACTUAL_DIST')
    html = (dist / 'index.html').read_text(encoding='utf8')
    require('天俪' not in html, 'NO_TIANLI_CUSTOM_BRAND_IN_ENTRY')
    parser = Resources()
    parser.feed(html)
    require(parser.urls, 'HTML_REFERENCES')
    resources = []
    for url in parser.urls:
        parsed = urlsplit(url)
        require(not parsed.scheme and not parsed.netloc and parsed.path.startswith('/admin/'), 'LOCAL_ADMIN_RESOURCE')
        relative = unquote(parsed.path[len('/admin/'):])
        require(relative in inventory and '..' not in relative.split('/'), 'ACTUAL_LOCAL_RESOURCE')
        resources.append({'url': url, **inventory[relative]})
    require(all(pin((REPO / relative).read_bytes()) == expected
        and pin((OUT / relative).read_bytes()) == assembled_sources[relative]
        for relative, expected in sources.items()), 'ORIGINAL_AND_ASSEMBLED_SOURCES_UNCHANGED')
    require(all(pin(Path(path).read_bytes()) == {key: row[key] for key in ['bytes', 'sha256']}
        and native9(Path(path)) == row['native9'] for path, row in overlay_inputs.items()), 'FIXED_OVERLAY_INPUTS_UNCHANGED')
    require(tree(OLD / 'node_modules', True) == modules and tree(destination, True) == modules, 'ORIGINAL_AND_COPY_DEPENDENCIES_UNCHANGED')
    require(identities(REPO, sources) == original_source_native9 and identities(OUT, sources) == copied_source_native9,
        'ORIGINAL_AND_COPY_SOURCE_NATIVE9_UNCHANGED')
    require(identities(OLD / 'node_modules', modules) == original_dependency_native9
        and identities(destination, modules) == copied_dependency_native9, 'ORIGINAL_AND_COPY_DEPENDENCY_NATIVE9_UNCHANGED')
    require({str(path): native9(path) for path in [NODE, NPM]} == runtime_native9, 'ACTUAL_RUNTIME_NATIVE9_UNCHANGED')
    create(OUT / 'NATIVE-INPUT-PRESERVATION.json', (json.dumps({'original_sources': original_source_native9,
        'copied_sources': copied_source_native9, 'fixed_overlay_inputs': overlay_inputs, 'original_dependencies': original_dependency_native9,
        'copied_dependencies': copied_dependency_native9, 'runtime': runtime_native9,
        'all_complete_original_and_copy_first_final_native9_equal': True}, sort_keys=True, indent=2) + '\n').encode())
    require(all(pin((REPO / name).read_bytes()) == expected for name, expected in bindings['controlled_sources'].items())
        and identities(REPO, bindings['controlled_sources']) == controlled_native9, 'ALL27_SOURCE_BYTES_AND_NATIVE9_UNCHANGED')
    require(all(pin(Path(path).read_bytes()) == {key: row[key] for key in ['bytes', 'sha256']}
        and native9(Path(path)) == row['native9'] for path, row in source_windows.items()), 'COMPLETE_FIXED_SOURCE_WINDOWS_UNCHANGED')
    require({p.relative_to(SOURCE_ROOT).as_posix() for p in SOURCE_ROOT.rglob('*') if p.is_file()}
        == set(source_pins['files']) | {'PINS.json'}, 'FIXED_SOURCE_NAMESPACE_UNCHANGED')
    create(OUT / 'FIXED-SOURCE-PRESERVATION.json', (json.dumps(source_windows, sort_keys=True, indent=2) + '\n').encode())
    require(git('head-after', ['rev-parse', 'HEAD']).decode().strip() == HEAD, 'HEAD_UNCHANGED')
    require(git('status-after', ['status', '--porcelain=v1', '--untracked-files=all']) == bindings['expected_status_porcelain_v1'].encode('utf-8'), 'MAIN_STILL_EXACT_CONTROLLED27')
    create(OUT / 'DIST-MANIFEST.json', (json.dumps(inventory, sort_keys=True, indent=2) + '\n').encode())
    report = {'scope': 'PLATFORM_OFFLINE_BUILD_AND_STATIC_RESOURCES_ONLY', 'status': 'PASS', 'source_head': HEAD,
        'platform_sources': len(platform_names), 'prebuild_helper_sources': 1, 'fixed_ui_overlay_files': len(OVERLAY_FILES),
        'source_assembly': 'EXACT_BFE_PLUS_CURRENT27_AND_TWO_UNADOPTED_PLATFORM_SITE_IMAGE_UI_OVERLAYS',
        'source_identity': bindings['source_identity'], 'contains_uncommitted_changes': True,
        'previous_adopted_UI_files_preserved': 8, 'new_unadopted_UI_overlay_files': 2, 'worktree_adopted': False,
        'dependency_files': len(modules), 'versions': package_versions,
        'dist_files': len(inventory), 'dist_bytes': sum(row['bytes'] for row in inventory.values()), 'resources': resources,
        'source_and_dependencies_unchanged': True, 'installation_or_network_fallback': False,
        'HTTP_DOM_business': 'NOT RUN', 'deployment': False}
    create(OUT / 'BUILD-REPORT.json', (json.dumps(report, indent=2) + '\n').encode())
    print(json.dumps({key: report[key] for key in ['status', 'source_head', 'platform_sources', 'dependency_files',
        'dist_files', 'dist_bytes', 'HTTP_DOM_business']}), flush=True)


if __name__ == '__main__':
    main()
