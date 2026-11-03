"""Only the two existing offline Vue builds; no install, overlays or import work."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit, unquote
import json
import os

from build_common import (NODE, NPM, NODE_ROOT, NODE_PIN, NPM_PIN, require, read,
                          pin, fixed, create, put, native, tree, identities, native9,
                          frontend_source, dist_tree)

CACHES = {
    'admin': Path('H:/天俪商城/artifacts/ms0b-task8/frontend/admin'),
    'merchant': Path('H:/天俪商城/artifacts/ms3-merchant-console-build/source477732-generic-r2/clean-ci'),
}
BASELINES = {
    'admin': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbfe-platform-site-image-admin-build-actual-r1/DEPENDENCY-INPUTS.json',
              'bytes': 10615358, 'sha256': '4b0490bd49161ff2150110f9d03098e69708524f536d552fd73a2ba4e85cb72c'},
    'merchant': {'path': 'H:/天俪商城/artifacts/currentbfe-merchant-video-audio-ui-build-r2/DEPENDENCY-INPUTS.json',
                 'bytes': 3937705, 'sha256': 'cbd27ca705452deb96332ec00f880780a3cdae61ca0fc3264f4b4c2c83005e72'},
}


class Resources(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        self.urls += [value for key, value in attrs
                      if (tag == 'script' and key == 'src') or (tag == 'link' and key == 'href')]


def installed_versions(frontend, cache, package_raw, lock_raw):
    package, lock = json.loads(package_raw), json.loads(lock_raw)
    modules = cache / 'node_modules'
    if frontend == 'admin':
        versions = {name: json.loads(read(modules / name / 'package.json'))['version']
                    for name in ['vue', 'vue-template-compiler', '@vue/cli-service', 'webpack', 'axios', 'uglifyjs-webpack-plugin']}
        require(versions == {'vue': '2.7.16', 'vue-template-compiler': '2.7.16', '@vue/cli-service': '3.12.1',
                             'webpack': '4.47.0', 'axios': '0.18.1', 'uglifyjs-webpack-plugin': '2.2.0'}, 'ORIGINAL_ADMIN_OFFLINE_VERSIONS')
    else:
        require(package['name'] == lock['packages']['']['name'] == 'marketplace-merchant-console', 'MERCHANT_PACKAGE_IDENTITY')
        versions = {}
        for group in ['dependencies', 'devDependencies']:
            require(package[group] == lock['packages'][''][group], 'EXACT_DECLARED_LOCK_DEPENDENCIES')
            for name, version in package[group].items():
                require(json.loads(read(modules / name / 'package.json'))['version'] == version, 'ACTUAL_INSTALLED_DIRECT_VERSION')
                versions[name] = version
        require(len(versions) == 9 and package['engines']['node'] == '>14.0.0 <23.0.0', 'ORIGINAL_NINE_DIRECT_AND_NODE_ENGINE')
        hidden = json.loads(read(modules / '.package-lock.json'))
        require(len(hidden['packages']) == 1327, 'ORIGINAL_INSTALLED_HIDDEN_LOCK_ENTRIES')
        for name, row in hidden['packages'].items():
            require(name.startswith('node_modules/') and '..' not in name.split('/') and name in lock['packages'], 'INSTALLED_LOCK_NAMESPACE')
            require(all(row.get(key) == lock['packages'][name].get(key) for key in ['version', 'resolved', 'integrity']), 'FULL_INSTALLED_LOCK_METADATA')
            require(json.loads(read(cache / name / 'package.json'))['version'] == row['version'], 'ALL_INSTALLED_PACKAGE_VERSIONS')
        require(json.loads(read(modules / 'webpack/package.json'))['version'] == '4.47.0', 'ACTUAL_WEBPACK_VERSION')
    return versions


def run(context, frontend):
    require(frontend in ('admin', 'merchant'), 'TWO_FIXED_WINDOWS_BUILDS_ONLY')
    q, out = context['qualification'], context['out']
    cache = CACHES[frontend]
    sources = frontend_source(context, frontend)
    work = out / 'template' / frontend
    source_states = identities(out, sources)
    package_raw, lock_raw = read(work / 'package.json'), read(work / 'package-lock.json')
    require(package_raw == read(cache / 'package.json') and lock_raw == read(cache / 'package-lock.json'), 'EXACT_EXISTING_OFFLINE_PACKAGE_AND_LOCK')
    if frontend == 'merchant':
        require(read(work / '.env.production') == b'VUE_APP_API_BASE=/merchantapi\n', 'ONE_PUBLIC_RELATIVE_MERCHANT_API_SETTING')
        require(all(not Path(name).name.startswith('.env') or name == 'template/merchant/.env.production' for name in sources), 'NO_OTHER_MERCHANT_ENV')
    require(all(Path(name).name != '.npmrc' and not Path(name).name.endswith('.local')
                and 'node_modules' not in Path(name).parts and 'dist' not in Path(name).parts for name in sources), 'NO_PRIVATE_OR_GENERATED_FRONTEND_INPUT')
    versions = installed_versions(frontend, cache, package_raw, lock_raw)
    authority = json.loads(fixed(q['dependency_authority'][frontend]))
    require(set(authority) == {'root', 'files', 'directories'} and Path(authority['root']).resolve() == (cache / 'node_modules').resolve(), 'FIXED_OFFLINE_MODULE_ROOT')
    require(authority['files'] == json.loads(fixed(BASELINES[frontend])), 'SAME_COMPLETE_MATURE_OFFLINE_MODULE_BASELINE')
    before = tree(cache / 'node_modules', True)
    require(before == {key: authority[key] for key in ['files', 'directories']}, 'FULL_FIXED_OFFLINE_MODULE_TREE')
    original_states = identities(cache / 'node_modules', before['files'])
    destination = work / 'node_modules'
    require(not destination.exists(), 'NEW_PHYSICAL_MODULE_COPY_ONLY')
    for name, expected in before['files'].items():
        raw = read(cache / 'node_modules' / name)
        require(pin(raw) == expected, 'EVERY_ORIGINAL_MODULE_BEFORE_COPY')
        create(destination / name, raw)
        require((destination / name).stat().st_nlink == 1, 'NO_LINKED_MODULE_REUSE')
    for name in before['directories']:
        (destination / name).mkdir(parents=True, exist_ok=True)
    require(tree(destination, True) == before, 'COMPLETE_PROTECTED_MODULE_COPY')
    copied_states = identities(destination, before['files'])
    npm_authority = json.loads(fixed(q['npm_runtime_authority']))
    npm_root = NODE_ROOT / 'node_modules/npm'
    require(set(npm_authority) == {'root', 'files', 'directories'}
            and Path(npm_authority['root']).resolve() == npm_root.resolve(), 'FIXED_COMPLETE_NPM_RUNTIME_AUTHORITY')
    npm_tree = tree(npm_root, True)
    require(npm_tree == {key: npm_authority[key] for key in ['files', 'directories']}, 'WHOLE_NPM_ENTRY_LIB_AND_TRANSITIVE_RUNTIME_TREE')
    npm_states = identities(npm_root, npm_tree['files'])
    require(pin(read(NODE)) == NODE_PIN and pin(read(NPM)) == NPM_PIN, 'ROOT_PREFLIGHT_NODE_NPM_BODIES')
    runtime_states = {str(path): native9(path) for path in (NODE, NPM)}
    create(out / 'empty-user.npmrc', b'')
    create(out / 'empty-global.npmrc', b'')
    env = {key: value for key, value in os.environ.items() if key.upper() in
           ['SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'PROCESSOR_ARCHITECTURE', 'NUMBER_OF_PROCESSORS']}
    for directory in ['tmp', 'empty-home', 'empty-home/AppData/Roaming', 'empty-home/AppData/Local']:
        (out / directory).mkdir(parents=True, exist_ok=True)
    env.update(PATH=str(NODE_ROOT) + os.pathsep + os.environ.get('SYSTEMROOT', 'C:/Windows') + '/System32',
               NPM_CONFIG_OFFLINE='true', NPM_CONFIG_USERCONFIG=str(out / 'empty-user.npmrc'),
               NPM_CONFIG_GLOBALCONFIG=str(out / 'empty-global.npmrc'), NPM_CONFIG_CACHE=str(out / 'npm-cache'),
               NPM_CONFIG_UPDATE_NOTIFIER='false', NPM_CONFIG_AUDIT='false', NPM_CONFIG_FUND='false',
               NODE_OPTIONS='--max-old-space-size=8192', TEMP=str(out / 'tmp'), TMP=str(out / 'tmp'),
               USERPROFILE=str(out / 'empty-home'), HOME=str(out / 'empty-home'),
               APPDATA=str(out / 'empty-home/AppData/Roaming'), LOCALAPPDATA=str(out / 'empty-home/AppData/Local'))
    require(native(context, 'node-version', [str(NODE), '--version'], out, env)[0].decode().strip() == 'v22.23.3', 'ACTUAL_NODE_VERSION')
    require(native(context, 'npm-version', [str(NODE), str(NPM), '--version'], out, env)[0].decode().strip() == '10.9.9', 'ACTUAL_NPM_VERSION')
    log, _ = native(context, 'npm-build', [str(NODE), str(NPM), 'run', 'build'], work, env, allow_stderr=True)
    require(b'prebuild' in log and (b'frontend-source-check.cjs' if frontend == 'admin' else b'scripts/source-check.cjs') in log, 'ORIGINAL_REAL_PREBUILD_EXECUTED')
    if frontend == 'merchant':
        require(b'"source_checks":"PASS"' in log, 'ORIGINAL_MERCHANT_SOURCE_CHECK_PASS')
    dist = work / 'dist'
    inventory = dist_tree(dist)
    html = read(dist / 'index.html').decode('utf-8')
    require('天俪' not in html, 'NO_TIANLI_CUSTOM_BRAND_IN_ENTRY')
    parser = Resources()
    parser.feed(html)
    require(parser.urls, 'REAL_HTML_RESOURCES')
    files = {row['path']: row for row in inventory['files']}
    resources = []
    for url in parser.urls:
        parsed = urlsplit(url)
        prefix = '/' + frontend + '/'
        require(not parsed.scheme and not parsed.netloc and parsed.path.startswith(prefix), 'ORIGINAL_LOCAL_FRONTEND_RESOURCE')
        name = unquote(parsed.path[len(prefix):])
        require(name in files and '..' not in name.split('/'), 'COMPLETE_REAL_LOCAL_RESOURCE')
        resources.append({'url': url, **files[name]})
    require(all(pin(read(out / name)) == expected for name, expected in sources.items())
            and identities(out, sources) == source_states, 'ALL_ASSEMBLED_TRACKED_INPUTS_UNCHANGED')
    require(tree(cache / 'node_modules', True) == before and tree(destination, True) == before
            and identities(cache / 'node_modules', before['files']) == original_states
            and identities(destination, before['files']) == copied_states, 'ALL_ORIGINAL_AND_COPIED_PROTECTED_MODULES_UNCHANGED')
    require({str(path): native9(path) for path in (NODE, NPM)} == runtime_states
            and pin(read(NODE)) == NODE_PIN and pin(read(NPM)) == NPM_PIN, 'FIXED_RUNTIME_UNCHANGED')
    require(tree(npm_root, True) == npm_tree and identities(npm_root, npm_tree['files']) == npm_states,
            'ALL_NPM_RUNTIME_LIB_AND_INTERNAL_DEPENDENCIES_UNCHANGED')
    put(out / 'DEPENDENCY-INPUTS.json', before)
    put(out / 'NATIVE-INPUT-PRESERVATION.json', {'source': source_states, 'original_modules': original_states,
                                              'copied_modules': copied_states, 'runtime': runtime_states,
                                              'npm_runtime': npm_states})
    return {'frontend': frontend, 'source_head': q['head'], 'source_assembly': 'ONLY_FINAL_CLEAN_TRACKED_GIT_NO_OLD_OVERLAY',
            'versions': versions, 'source_files': len(sources), 'dependency_files': len(before['files']),
            'dist_root': dist.as_posix(), 'dist': inventory, 'resources': resources,
            'installation_or_network_fallback': False, 'HTTP_DOM_devices': 'NOT_RUN'}
