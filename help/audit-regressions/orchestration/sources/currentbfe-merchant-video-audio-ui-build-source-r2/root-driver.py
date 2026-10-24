"""Build exact bfe merchant source plus five fixed UI overlays with existing offline dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import hashlib
import json
import os
import shutil
import stat
import subprocess
import time

REPO = Path('H:/天俪商城/worktrees/product-typed-media-normal')
HEAD = 'bfe311cbb727af093fe73fb4ce22ead58e8f3345'
OLD = Path('H:/天俪商城/artifacts/ms3-merchant-console-build/source477732-generic-r2/clean-ci')
OUT = Path('H:/天俪商城/artifacts/currentbfe-merchant-video-audio-ui-build-r2')
NODE_ROOT = Path('H:/天俪商城/artifacts/ms3-build-tools/source477732-node22/node-v22.23.3-win-x64')
NODE = NODE_ROOT / 'node.exe'
NPM = NODE_ROOT / 'node_modules/npm/bin/npm-cli.js'
OVERLAY = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbfe-merchant-video-audio-ui-source-r1')
OVERLAY_PINS = {'bytes': 2698, 'sha256': '39685365566d59c2649f284ecb4e6a088ed524c9344abcae94c902fb898d720e'}
OVERLAY_FILES = ('template/merchant/src/api/index.js', 'template/merchant/src/components/AttachmentLibrary.vue', 'template/merchant/src/components/AttachmentPicker.vue', 'template/merchant/src/pages/product/edit.vue', 'template/merchant/src/styles.css')


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
    require(not OUT.exists() and not OUT.is_symlink(), 'NEW_OUTPUT_ONLY')
    OUT.mkdir()
    create(OUT / 'root-driver.py', Path(__file__).read_bytes())
    require(git('head-before', ['rev-parse', 'HEAD']).decode().strip() == HEAD, 'EXACT_HEAD')
    require(git('status-before', ['status', '--porcelain=v1', '--untracked-files=all']) == b'', 'CLEAN_MAIN')
    helper = 'template/merchant/scripts/source-check.cjs'
    names = git('source-files', ['ls-files', '-z', '--', 'template/merchant', helper]).decode().rstrip('\0').split('\0')
    merchant_names = [name for name in names if name.startswith('template/merchant/')]
    require(len(names) == len(set(names)) and len(merchant_names) > 0
        and set(names) == set(merchant_names) | {helper}, 'ACTUAL_TRACKED_PLATFORM_AND_PREBUILD_HELPER')
    public_build_config = 'template/merchant/.env.production'
    require(public_build_config in names, 'TRACKED_EXACT_PUBLIC_FRONTEND_BUILD_CONFIG')
    public_config_path = REPO / public_build_config; regular(public_config_path)
    public_config_native9 = native9(public_config_path); public_config_raw = public_config_path.read_bytes()
    require(native9(public_config_path) == public_config_native9 and pin(public_config_raw) == {'bytes': 30, 'sha256': '349b73c0eddc5d8b4c7e03b801db370a494207061b3495f17e1732bdc1fe12d2'}
        and public_config_raw == b'VUE_APP_API_BASE=/merchantapi\n' and public_config_raw.decode('utf-8').splitlines() == ['VUE_APP_API_BASE=/merchantapi'], 'ONE_EXACT_PUBLIC_KEY_RELATIVE_MERCHANTAPI_CONFIG')
    require(all((not Path(name).name.startswith('.env') or name == public_build_config) and Path(name).name != '.npmrc' and 'node_modules' not in Path(name).parts and 'dist' not in Path(name).parts for name in names), 'NO_OTHER_ENV_NPMRC_GENERATED_SOURCE')
    sources = {}
    for relative in names:
        path = REPO / relative
        regular(path)
        raw = path.read_bytes()
        if relative == public_build_config:
            require(raw == public_config_raw and native9(path) == public_config_native9, 'EXACT_PUBLIC_CONFIG_SOURCE_COPY_WINDOW')
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
        require(relative in sources and relative in merchant_names, 'OVERLAY_TRACKED_IN_EXACT_BASE')
        inputs = {}
        for kind in ['before', 'candidate']:
            path = OVERLAY / kind / relative
            regular(path)
            before_native9 = native9(path)
            raw = path.read_bytes()
            require(native9(path) == before_native9, 'OVERLAY_COMPLETE_SOURCE_WINDOW')
            expected = overlay_document['files'][kind + '/' + relative]
            require(expected == pin(raw), 'EXACT_OVERLAY_SOURCE_POINTER')
            overlay_inputs[str(path)] = {**pin(raw), 'native9': before_native9}
            inputs[kind] = raw
        require(git('overlay-before-' + str(index), ['show', HEAD + ':' + relative]) == inputs['before']
            and (REPO / relative).read_bytes() == inputs['before']
            and (OUT / relative).read_bytes() == inputs['before'], 'EXACT_GIT_WORKTREE_AND_OVERLAY_BEFORE')
        target = OUT / relative
        target.unlink()  # Only the newly exported OUT copy; never REPO or the fixed overlay.
        create(target, inputs['candidate'])
        assembled_sources[relative] = pin(inputs['candidate'])
    create(OUT / 'OVERLAY-INPUTS.json', (json.dumps(overlay_inputs, sort_keys=True, indent=2) + '\n').encode())
    create(OUT / 'ASSEMBLED-SOURCE-INPUTS.json', (json.dumps(assembled_sources, sort_keys=True, indent=2) + '\n').encode())
    copied_source_native9 = identities(OUT, assembled_sources)
    runtime_native9 = {str(path): native9(path) for path in [NODE, NPM]}
    for name, expected in {'package.json': {'bytes': 704, 'sha256': 'e37193699349a7aa70d7654fa900cf6de6dc55ee911d72951ed330fd638f97f7'}, 'package-lock.json': {'bytes': 630978, 'sha256': '2567a1e22714e899f57e16c0fc1ebc03f16505a3116e707d58c0c3d8decb0158'}}.items():
        raw = (REPO / 'template/merchant' / name).read_bytes()
        require(raw == (OLD / name).read_bytes() and pin(raw) == expected, 'SAME_EXACT_OFFLINE_PACKAGE_AND_LOCK')
    package = json.loads((OLD / 'package.json').read_bytes()); lock = json.loads((OLD / 'package-lock.json').read_bytes())
    require(package['name'] == lock['packages']['']['name'] == 'marketplace-merchant-console', 'MERCHANT_PACKAGE_IDENTITY')
    package_versions = {}
    for group in ['dependencies', 'devDependencies']:
        require(package[group] == lock['packages'][''][group], 'EXACT_DECLARED_LOCK_DEPENDENCIES')
        for name, version in package[group].items():
            require(json.loads((OLD / 'node_modules' / name / 'package.json').read_bytes())['version'] == version, 'ACTUAL_INSTALLED_DIRECT_VERSION')
            package_versions[name] = version
    require(len(package_versions) == 9 and package['engines']['node'] == '>14.0.0 <23.0.0', 'ORIGINAL_NINE_DIRECT_AND_NODE_ENGINE')
    hidden = json.loads((OLD / 'node_modules/.package-lock.json').read_bytes())
    require(len(hidden['packages']) == 1327, 'ORIGINAL_1327_INSTALLED_ENTRIES')
    for name, row in hidden['packages'].items():
        require(name.startswith('node_modules/') and '..' not in name.split('/') and name in lock['packages'], 'INSTALLED_LOCK_NAMESPACE')
        require(all(row.get(key) == lock['packages'][name].get(key) for key in ['version', 'resolved', 'integrity']), 'FULL_HIDDEN_LOCK_METADATA_MATCH')
        require(json.loads((OLD / name / 'package.json').read_bytes())['version'] == row['version'], 'ACTUAL_ALL_INSTALLED_PACKAGE_VERSIONS')
    require(json.loads((OLD / 'node_modules/webpack/package.json').read_bytes())['version'] == '4.47.0', 'ACTUAL_WEBPACK_VERSION')
    print(json.dumps({'stage': 'offline-dependency-inventory', 'state': 'starting'}), flush=True)
    modules = tree(OLD / 'node_modules', True)
    create(OUT / 'DEPENDENCY-INPUTS.json', (json.dumps(modules, sort_keys=True, indent=2) + '\n').encode())
    original_dependency_native9 = identities(OLD / 'node_modules', modules)
    destination = OUT / 'template/merchant/node_modules'
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
    native('npm-build', [str(NODE), str(NPM), 'run', 'build'], OUT / 'template/merchant', env)
    log = (OUT / 'npm-build.stdout').read_text(encoding='utf8', errors='replace')
    require('> marketplace-merchant-console@0.1.0 prebuild' in log and '> node scripts/source-check.cjs' in log and '"source_checks":"PASS"' in log, 'ACTUAL_ORIGINAL_PREBUILD_SOURCE_CHECK_PASS')
    dist = OUT / 'template/merchant/dist'
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
        require(not parsed.scheme and not parsed.netloc and parsed.path.startswith('/merchant/'), 'LOCAL_ADMIN_RESOURCE')
        relative = unquote(parsed.path[len('/merchant/'):])
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
    require(git('head-after', ['rev-parse', 'HEAD']).decode().strip() == HEAD, 'HEAD_UNCHANGED')
    require(git('status-after', ['status', '--porcelain=v1', '--untracked-files=all']) == b'', 'MAIN_STILL_CLEAN')
    create(OUT / 'DIST-MANIFEST.json', (json.dumps(inventory, sort_keys=True, indent=2) + '\n').encode())
    report = {'scope': 'MERCHANT_OFFLINE_BUILD_AND_STATIC_RESOURCES_ONLY', 'status': 'PASS', 'source_head': HEAD,
        'merchant_sources': len(merchant_names), 'prebuild_helper_sources': 1, 'fixed_ui_overlay_files': len(OVERLAY_FILES),
        'source_assembly': 'EXACT_BFE_BASE_WITH_FIVE_FIXED_MERCHANT_UI_R1_OVERLAYS', 'installed_hidden_lock_entries': len(hidden['packages']), 'original_prebuild_script': helper, 'generated_dependency_caches_excluded': True,
        'dependency_files': len(modules), 'versions': package_versions,
        'dist_files': len(inventory), 'dist_bytes': sum(row['bytes'] for row in inventory.values()), 'resources': resources,
        'source_and_dependencies_unchanged': True, 'installation_or_network_fallback': False,
        'HTTP_DOM_business': 'NOT RUN', 'deployment': False}
    create(OUT / 'BUILD-REPORT.json', (json.dumps(report, indent=2) + '\n').encode())
    print(json.dumps({key: report[key] for key in ['status', 'source_head', 'merchant_sources', 'dependency_files',
        'dist_files', 'dist_bytes', 'HTTP_DOM_business']}), flush=True)


if __name__ == '__main__':
    main()
