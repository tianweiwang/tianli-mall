"""Build the isolated preview from current frontend inputs, without installs.

Outcome: three real dist directories at build/{merchant,admin,h5}/dist.
Only external work copies are changed. Existing dependencies stay in place;
Windows packages are junctions, while generated caches are local to build.
Merchant/admin run sequentially. H5 uses the existing Linux two-pass adapter
and runs only when Root explicitly supplies --allow-docker. A build is not
browser, business-flow, payment, or release acceptance (see active PROGRESS).
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import time
from urllib.parse import unquote, urlsplit

SOURCE = Path(__file__).resolve().parents[2]
OUTPUT = Path('H:/天俪商城/artifacts/multi-merchant-preview-20261026/build')
NODE_ROOT = Path('H:/天俪商城/artifacts/ms3-build-tools/source477732-node22/node-v22.23.3-win-x64')
NODE = NODE_ROOT / 'node.exe'
CACHES = {
    'merchant': Path('H:/天俪商城/artifacts/ms3-merchant-console-build/source477732-generic-r2/clean-ci'),
    'admin': Path('H:/天俪商城/artifacts/ms0b-task8/frontend/admin'),
}
H5_TOOLS = Path('H:/天俪商城/artifacts/project-organization-20261023/integrated-three-frontend-build-source-r5/h5-tools')
PLUGINS = Path('E:/HBuilderX/plugins')
STYLUS = Path('H:/天俪商城/artifacts/ms1-task7/uni-h5-build-prep/dependency-plugins/compile-stylus')
DOCKER = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
H5_IMAGE = 'sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293'


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat()


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary.replace(path)


def inside(path):
    # Used before moves; no recursive delete or writes through dependency links.
    absolute = path.absolute()
    if not absolute.is_relative_to(OUTPUT.absolute()):
        raise RuntimeError('Output escaped the preview build directory')
    return absolute


def copy_file(source, destination):
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)


def input_entries(origin):
    if not origin.is_dir():
        raise RuntimeError('Missing current frontend: ' + str(origin))
    for directory, children, files in os.walk(origin):
        relative = Path(directory).relative_to(origin)
        children[:] = [child for child in children if child not in {'.git', 'node_modules', '.cache'}
                       and not (relative == Path('.') and child == 'dist')
                       and not (relative == Path('unpackage') and child in {'dist', 'cache'})]
        files = [name for name in files
                 if (not name.startswith('.env') or name in {'.env.production', '.env.development'})
                 and name != '.npmrc' and not name.endswith('.local')]
        yield relative, Path(directory), files


def inputs(frontend):
    name = 'uni-app' if frontend == 'h5' else frontend
    origin = SOURCE / 'template' / name
    destination = OUTPUT / 'work' / 'template' / name
    rows = []
    for relative, directory, files in input_entries(origin):
        (destination / relative).mkdir(parents=True, exist_ok=True)
        for filename in files:
            source = Path(directory) / filename
            if source.is_symlink():
                raise RuntimeError('Unexpected linked frontend source: ' + str(source))
            raw = source.read_bytes()
            target = destination / relative / filename
            copy_file(source, target)
            rows.append((str(relative / filename).replace('\\', '/'), len(raw), hashlib.sha256(raw).hexdigest()))
    if frontend == 'admin':
        helper = Path('help/audit-regressions/frontend-source-check.cjs')
        copy_file(SOURCE / helper, OUTPUT / 'work' / helper)
    signature = ''.join(f'{path}\0{size}\0{sha}\n' for path, size, sha in sorted(rows))
    return destination, {'directory': str(origin), 'file_count': len(rows),
                         'bytes': sum(row[1] for row in rows),
                         'copied_input_sha256': hashlib.sha256(signature.encode()).hexdigest()}


def parallelism_addition(workers):
    return '''\n// Isolated preview: bound build workers; application configuration stays above.
const previewOriginalConfigure = module.exports.configureWebpack;
module.exports.parallel = false;
module.exports.configureWebpack = function (config) {
  const returned = typeof previewOriginalConfigure === 'function'
    ? previewOriginalConfigure.call(this, config) : previewOriginalConfigure;
  for (const plugin of [...(config.plugins || []), ...((config.optimization || {}).minimizer || [])]) {
    if (plugin && plugin.options && Object.prototype.hasOwnProperty.call(plugin.options, 'parallel'))
      plugin.options.parallel = PREVIEW_WORKERS;
  }
  return returned;
};\n'''.replace('PREVIEW_WORKERS', str(workers))


def limit_parallelism(work, workers):
    config = work / 'vue.config.js'
    # This is a disposable input copy, never a source or dependency edit.
    config.write_text(config.read_text('utf-8') + parallelism_addition(workers), encoding='utf-8', newline='\n')


def modules(frontend, work):
    original = CACHES[frontend] / 'node_modules'
    destination = work / 'node_modules'
    destination.mkdir(exist_ok=True)
    (destination / '.cache').mkdir(exist_ok=True)
    wanted = []
    for package in original.iterdir():
        if package.name in {'.cache', '.bin'}:
            continue
        if package.name.startswith('@') and package.is_dir():
            (destination / package.name).mkdir(exist_ok=True)
            entries = [(leaf, destination / package.name / leaf.name) for leaf in package.iterdir()]
        else:
            entries = [(package, destination / package.name)]
        for source, target in entries:
            if source.is_dir():
                if target.exists():
                    if not os.path.samefile(source, target):
                        raise RuntimeError('Existing module is not the selected cache: ' + str(target))
                else:
                    wanted.append({'source': str(source), 'target': str(target)})
            elif source.is_file() and source.name != '.package-lock.json':
                copy_file(source, target)
    if wanted:
        command = "$items=[Console]::In.ReadToEnd()|ConvertFrom-Json; foreach($item in $items){New-Item -ItemType Junction -Path $item.target -Target $item.source -ErrorAction Stop|Out-Null}"
        result = subprocess.run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', command],
                                input=json.dumps(wanted).encode('ascii'), stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        if result.returncode:
            raise RuntimeError('Dependency junction setup failed: ' + result.stderr.decode('utf-8', 'replace')[-1000:])
    return {'directory': str(original), 'new_package_junctions': len(wanted),
            'generated_cache': str(destination / '.cache'), 'installation': False}


def command(record, label, argv, cwd, env):
    folder = OUTPUT / record['frontend']
    number = len(record['commands']) + 1
    stdout = folder / f'{number:02d}-{label}.stdout.log'
    stderr = folder / f'{number:02d}-{label}.stderr.log'
    item = {'label': label, 'argv': list(map(str, argv)), 'cwd': str(cwd), 'started': now(),
            'stdout': str(stdout), 'stderr': str(stderr), 'exit': None}
    record['commands'].append(item)
    with stdout.open('wb') as out, stderr.open('wb') as err:
        process = subprocess.Popen(item['argv'], cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                                   stdout=out, stderr=err, shell=False,
                                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        item['pid'] = process.pid
        save(folder / 'BUILD.json', record)
        print(json.dumps({'frontend': record['frontend'], 'command': label, 'pid': process.pid}, ensure_ascii=False), flush=True)
        item['exit'] = process.wait()
    item['finished'] = now()
    save(folder / 'BUILD.json', record)
    if item['exit']:
        raise RuntimeError(f'{label} exited {item["exit"]}; see {stderr}')


def environment(frontend, memory):
    env = {key: value for key, value in os.environ.items() if key.upper() in
           {'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'PROCESSOR_ARCHITECTURE', 'NUMBER_OF_PROCESSORS'}}
    cache = OUTPUT / frontend / 'cache'
    cache.mkdir(parents=True, exist_ok=True)
    temporary = OUTPUT / frontend / 'tmp'
    temporary.mkdir(exist_ok=True)
    env.update(PATH=str(NODE_ROOT) + os.pathsep + 'C:/Windows/System32', NODE_ENV='production',
               NODE_OPTIONS=f'--max-old-space-size={memory}', TEMP=str(temporary), TMP=str(temporary),
               VUE_CLI_CONTEXT=str(OUTPUT / 'work' / 'template' / frontend),
               BABEL_CACHE_PATH=str(cache / 'babel.json'), SASS_CACHE_PATH=str(cache / 'sass'),
               WEBPACK_CACHE_DIR=str(cache / 'webpack'), CI='true', UV_THREADPOOL_SIZE='2')
    return env


def h5_command(work, dist, folder, memory):
    tools = folder / 'tools'
    tools.mkdir(exist_ok=True)
    adapter = tools / 'explicit-pass-preload.cjs'
    copy_file(H5_TOOLS / adapter.name, adapter)
    # fork() inherits --require through execArgv. Only the real CLI may own the
    # adapter receipt and its two-pass hooks; jest workers must skip that body.
    preload = tools / 'main-cli-preload.cjs'
    preload.write_text("'use strict';\n"
                       "if (process.argv[1] === '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js') {\n"
                       "  require('/tool/explicit-pass-preload.cjs');\n"
                       "}\n", encoding='utf-8')
    overlay = tools / 'optimize'
    if not overlay.exists():
        shutil.copytree(H5_TOOLS / 'compiler-overlays/vue-cli-plugin-uni-optimize', overlay)
    (overlay / '.tmp').mkdir(exist_ok=True)
    evidence = folder / 'compiler-evidence'
    evidence.mkdir(exist_ok=True)
    argv = [DOCKER, 'run', '--rm', '--pull=never', '--name', 'tianli-preview-h5-' + str(int(time.time())),
            '--label', 'tianli.preview.frontend=h5', '--network', 'none', '--read-only', '--cpus', '2',
            '--memory', '4g', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--tmpfs', '/tmp:rw,nosuid,nodev,size=1073741824', '--tmpfs', '/.automator:rw,nosuid,nodev,size=1048576',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/.cache:rw,nosuid,nodev,size=268435456',
            '--tmpfs', '/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize/.tmp:rw,nosuid,nodev,size=16777216',
            '--workdir', '/hbx/plugins/uniapp-cli']
    for key, value in {'NODE_ENV': 'production', 'UNI_PLATFORM': 'h5', 'UNI_MINIMIZE': 'true',
                       'UNI_INPUT_DIR': '/input', 'UNI_OUTPUT_DIR': '/output',
                       'VUE_CLI_CONTEXT': '/hbx/plugins/uniapp-cli', 'UNI_HBUILDERX_PLUGINS': '/hbx/plugins',
                       'CHOKIDAR_USEPOLLING': 'true', 'CHOKIDAR_INTERVAL': '250', 'HOME': '/tmp', 'TMPDIR': '/tmp',
                       'NODE_OPTIONS': f'--max-old-space-size={memory}'}.items():
        argv += ['--env', key + '=' + value]
    mounts = [(work, '/input', False), (dist, '/output', False), (evidence, '/evidence', False),
              (adapter, '/tool/explicit-pass-preload.cjs', True),
              (preload, '/tool/main-cli-preload.cjs', True)]
    mounts += [(PLUGINS / name, '/hbx/plugins/' + name, True)
               for name in ['uniapp-cli', 'about', 'compile-dart-sass', 'uni_helpers']]
    mounts += [(STYLUS, '/hbx/plugins/compile-stylus', True),
               (overlay, '/hbx/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni-optimize', True)]
    for source, target, readonly in mounts:
        argv += ['--mount', 'type=bind,source=' + str(source) + ',target=' + target + (',readonly' if readonly else '')]
    argv += [H5_IMAGE, 'node', '--require', '/tool/main-cli-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js']
    save(folder / 'H5-COMMAND.json', {'argv': argv, 'Root_only': True})
    return argv


def build(frontend, args):
    folder = OUTPUT / frontend
    folder.mkdir(parents=True, exist_ok=True)
    record = {'frontend': frontend, 'status': 'PREPARING', 'started': now(), 'commands': [],
              'source_directory': str(SOURCE), 'dist': str(folder / 'dist'),
              'preview_only_config': {'loader_parallel': False, 'minifier_workers': args.workers},
              'not_verified': ['browser', 'API/business flow', 'real payment', 'device', 'formal acceptance', 'release']}
    try:
        work, record['source_inputs'] = inputs(frontend)
        limit_parallelism(work, args.workers)
        dist = folder / 'dist'
        if frontend == 'h5' and not args.allow_docker:
            dist.mkdir(exist_ok=True)
            h5_command(work, dist, folder, args.memory_mb)
            record['status'] = 'PREPARED_ROOT_DOCKER_EXECUTION_PENDING'
            record['Root_command'] = [sys.executable, '-B', '-X', 'utf8', str(Path(__file__).resolve()), '--frontend', 'h5', '--allow-docker']
            return record
        if dist.exists() and any(dist.iterdir()):
            history = folder / ('dist-before-' + str(time.time_ns()))
            inside(dist); inside(history)
            dist.rename(history)
        if frontend == 'h5':
            dist.mkdir(exist_ok=True)
            argv = h5_command(work, dist, folder, args.memory_mb)
            command(record, 'h5-build', argv, folder, os.environ.copy())
            adapter = json.loads((folder / 'compiler-evidence/adapter-receipt.json').read_text('utf-8'))
            if adapter.get('status') != 'SECOND_PASS_OBSERVED':
                raise RuntimeError('H5 compiler did not finish its existing two-pass adapter')
            record['compiler_adapter_status'] = adapter['status']
        else:
            record['dependencies'] = modules(frontend, work)
            env = environment(frontend, args.memory_mb)
            check = (work / 'scripts/source-check.cjs' if frontend == 'merchant'
                     else OUTPUT / 'work/help/audit-regressions/frontend-source-check.cjs')
            command(record, 'prebuild', [NODE, check], work, env)
            cli = CACHES[frontend] / 'node_modules/@vue/cli-service/bin/vue-cli-service.js'
            command(record, 'build', [NODE, cli, 'build', '--mode', 'production', '--dest', dist], work, env)
        if not (dist / 'index.html').is_file():
            raise RuntimeError('Build returned without index.html')
        files = [path for path in dist.rglob('*') if path.is_file()]
        record.update(status='BUILT', finished=now(), dist_files=len(files), dist_bytes=sum(p.stat().st_size for p in files))
        return record
    except Exception as error:
        record.update(status='FAILED', finished=now(), error=str(error))
        return record
    finally:
        save(folder / 'BUILD.json', record)
        summary = {}
        for name in ['merchant', 'admin', 'h5']:
            path = OUTPUT / name / 'BUILD.json'
            if path.exists():
                summary[name] = json.loads(path.read_text('utf-8'))
        save(OUTPUT / 'BUILD.json', {'frontends': summary, 'browser_and_business_acceptance': 'PENDING'})
        print(json.dumps({'frontend': frontend, 'status': record['status'], 'dist': record['dist']}, ensure_ascii=False), flush=True)


def context_require(condition, message):
    if not condition:
        raise RuntimeError('Image context: ' + message)


def context_relative(name):
    context_require(isinstance(name, str) and name and '\\' not in name and ':' not in name
                    and all(ord(char) >= 32 for char in name)
                    and all(part not in {'', '.', '..'} for part in name.split('/')), 'invalid relative path')
    return name


def context_regular(path, directory=False):
    info = path.lstat()
    context_require(not path.is_symlink() and not (getattr(info, 'st_file_attributes', 0) & 1024)
                    and (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)),
                    'linked or non-regular input: ' + str(path))
    return info


def context_pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def context_read(path, observed):
    path = path.absolute()
    before = context_regular(path)
    raw = path.read_bytes()
    after = context_regular(path)
    fields = ('st_dev', 'st_ino', 'st_mode', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    context_require(len(raw) == before.st_size and all(getattr(before, key) == getattr(after, key) for key in fields),
                    'input changed while reading: ' + str(path))
    signature = context_pin(raw)
    context_require(path not in observed or observed[path] == signature, 'input changed: ' + str(path))
    observed[path] = signature
    return raw


def context_namespace(files, directories):
    names = [*files, *directories]
    for name in names:
        context_relative(name)
    context_require(len(names) == len({name.casefold() for name in names}), 'case or file/directory collision')


def context_tree(root, observed):
    context_regular(root, True)
    files, directories = {}, []
    for directory, children, names in os.walk(root, followlinks=False):
        context_regular(Path(directory), True)
        for name in children:
            path = Path(directory) / name
            context_regular(path, True)
            directories.append(path.relative_to(root).as_posix())
        for name in names:
            path = Path(directory) / name
            files[path.relative_to(root).as_posix()] = context_pin(context_read(path, observed))
    context_namespace(files, directories)
    return {'files': dict(sorted(files.items())), 'directories': sorted(directories)}


class ContextResources(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        self.urls += [value for key, value in attrs
                      if (tag == 'script' and key == 'src') or (tag == 'link' and key == 'href')]


def context_builds(build_root, observed):
    summary = json.loads(context_read(build_root / 'BUILD.json', observed))
    builds = {}
    for frontend in ['admin', 'merchant', 'h5']:
        folder = build_root / frontend
        record = json.loads(context_read(folder / 'BUILD.json', observed))
        context_require(summary['frontends'][frontend] == record and record['frontend'] == frontend
                        and record['status'] == 'BUILT' and Path(record['dist']).resolve() == folder / 'dist',
                        'original successful build record required: ' + frontend)
        name = 'uni-app' if frontend == 'h5' else frontend
        work = build_root / 'work/template' / name
        current, copied = {}, {}
        for origin, result in [(SOURCE / 'template' / name, current), (work, copied)]:
            for relative, directory, files in input_entries(origin):
                context_regular(directory, True)
                for leaf in files:
                    result[context_relative((relative / leaf).as_posix())] = context_pin(context_read(directory / leaf, observed))
        context_require(current.keys() == copied.keys(), 'build work has missing or extra source files: ' + frontend)
        workers = record['preview_only_config']['minifier_workers']
        context_require(type(workers) is int and workers in (1, 2)
                        and record['preview_only_config']['loader_parallel'] is False, 'unknown build configuration')
        for path, expected in current.items():
            if path == 'vue.config.js':
                raw = context_read(SOURCE / 'template' / name / path, observed).decode('utf-8')
                expected = context_pin((raw.replace('\r\n', '\n').replace('\r', '\n')
                                        + parallelism_addition(workers)).encode('utf-8'))
            context_require(copied[path] == expected, 'build work differs from declared source: ' + frontend + '/' + path)
        signature = ''.join(path + '\0' + str(row['bytes']) + '\0' + row['sha256'] + '\n'
                            for path, row in sorted(current.items()))
        source_inputs = {'file_count': len(current), 'bytes': sum(row['bytes'] for row in current.values()),
                         'copied_input_sha256': hashlib.sha256(signature.encode()).hexdigest()}
        context_require(all(record['source_inputs'][key] == value for key, value in source_inputs.items()),
                        'current source does not match the original build input signature: ' + frontend)
        if frontend == 'admin':
            helper = Path('help/audit-regressions/frontend-source-check.cjs')
            context_require(context_read(SOURCE / helper, observed) == context_read(build_root / 'work' / helper, observed),
                            'admin source-check helper differs')
        commands = record['commands']
        labels = ['h5-build'] if frontend == 'h5' else ['prebuild', 'build']
        context_require([item['label'] for item in commands] == labels, 'original build command sequence required')
        for index, item in enumerate(commands, 1):
            context_require(type(item['exit']) is int and item['exit'] == 0 and type(item['pid']) is int and item['pid'] > 0
                            and dt.datetime.fromisoformat(item['finished']) >= dt.datetime.fromisoformat(item['started'])
                            and Path(item['cwd']).resolve() == (folder if frontend == 'h5' else work),
                            'original normal build completion required')
            for stream in ['stdout', 'stderr']:
                log = folder / f'{index:02d}-{item["label"]}.{stream}.log'
                context_require(Path(item[stream]).resolve() == log, 'build log must belong to its original build')
                context_read(log, observed)
        if frontend != 'h5':
            check = work / 'scripts/source-check.cjs' if frontend == 'merchant' else build_root / 'work/help/audit-regressions/frontend-source-check.cjs'
            cli = CACHES[frontend] / 'node_modules/@vue/cli-service/bin/vue-cli-service.js'
            expected = [[str(NODE), str(check)], [str(NODE), str(cli), 'build', '--mode', 'production', '--dest', str(folder / 'dist')]]
            context_require([item['argv'] for item in commands] == expected, 'unexpected frontend build command')
        else:
            command_record = json.loads(context_read(folder / 'H5-COMMAND.json', observed))
            argv = commands[0]['argv']
            context_require(command_record['argv'] == argv and argv[:3] == [DOCKER, 'run', '--rm']
                            and argv[-5:] == [H5_IMAGE, 'node', '--require', '/tool/main-cli-preload.cjs', '/hbx/plugins/uniapp-cli/bin/uniapp-cli.js']
                            and argv[argv.index('--network') + 1] == 'none', 'unexpected H5 compiler command')
        dist = context_tree(folder / 'dist', observed)
        context_require(dist['files'].get('index.html', {}).get('bytes', 0) > 0
                        and record['dist_files'] == len(dist['files'])
                        and record['dist_bytes'] == sum(row['bytes'] for row in dist['files'].values()), 'dist inventory differs from build')
        parser = ContextResources()
        parser.feed(context_read(folder / 'dist/index.html', observed).decode('utf-8'))
        prefix = '/' if frontend == 'h5' else '/' + frontend + '/'
        context_require(parser.urls, 'frontend HTML has no local resources')
        for url in parser.urls:
            parsed = urlsplit(url)
            context_require(not parsed.scheme and not parsed.netloc and parsed.path.startswith(prefix), 'unexpected HTML resource base')
            resource = context_relative(unquote(parsed.path[len(prefix):]))
            context_require(resource in dist['files'], 'HTML resource absent from dist: ' + resource)
        output_scope = 'PACKAGING_TIME_OBSERVATION_NOT_ORIGINAL_BUILD_HASH'
        if frontend == 'h5':
            receipt = json.loads(context_read(folder / 'compiler-evidence/adapter-receipt.json', observed))
            inventory = ''.join(path + '\0' + str(dist['files'][path]['bytes']) + '\0' + dist['files'][path]['sha256'] + '\n'
                                for path in sorted(dist['files'], key=lambda value: value.encode('utf-16-be')))
            context_require(record['compiler_adapter_status'] == receipt['status'] == 'SECOND_PASS_OBSERVED'
                            and receipt['factory_count'] == receipt['callback_count'] == 1 and receipt['first_callback_success'] is True
                            and receipt['explicit_invalidations'] == 1 and receipt['purged_module_count'] == 8
                            and [item['pass'] for item in receipt['passes']] == [1, 2]
                            and all(item['has_errors'] is False and item['asset_count'] > 0 for item in receipt['passes'])
                            and receipt['after_emit_passes'] == [2] and receipt['phase_counts']['failed'] == 0
                            and receipt['error_observations'] == [] and receipt['failure_close_requested'] is False
                            and receipt['native_failure_exit_requested'] is False
                            and receipt['after_emit_inventories'] == [{'pass': 2, 'file_count': len(dist['files']),
                                'bytes': sum(row['bytes'] for row in dist['files'].values()),
                                'manifest_sha256': hashlib.sha256(inventory.encode()).hexdigest()}], 'original H5 afterEmit receipt mismatch')
            output_scope = 'ORIGINAL_H5_AFTER_EMIT_HASH_MATCHED_AT_PACKAGING'
        builds[frontend] = {'root': folder / 'dist', 'tree': dist, 'source_inputs': source_inputs, 'output_hash_scope': output_scope}
    return builds


def context_git(*args):
    git = shutil.which('git') or 'C:/Program Files/Git/cmd/git.exe'
    result = subprocess.run([git, '--no-optional-locks', '-c', 'safe.directory=' + SOURCE.as_posix(),
                             '-C', str(SOURCE), *args], capture_output=True)
    context_require(result.returncode == 0, 'read-only source inventory unavailable')
    return result.stdout


def context_excluded(name):
    parts = name.split('/')
    return (any(part.startswith(('.env', '.constant')) or part in {'.git', '.cache', '.tmp', '.secret', '.secrets',
                'private', 'private-assets', '__ms4_assets', '__ms4_fixture__'} for part in parts)
            or parts[-1] == 'install.lock' or parts[-1].endswith(('.log', '.tmp', '.local'))
            or any(name == base or name.startswith(base + '/') for base in
                   ['crmeb/vendor', 'crmeb/runtime', 'crmeb/public/uploads', 'crmeb/public/install']))


def image_context(output, build_root):
    output, build_root = output.absolute(), build_root.resolve()
    context_require(output.resolve() == output and not output.exists() and not output.is_symlink(), 'output must be a new canonical directory')
    context_regular(output.parent, True)
    for protected in [SOURCE, build_root]:
        context_require(not output.is_relative_to(protected) and not protected.is_relative_to(output), 'output overlaps source or build inputs')
    observed = {}
    builds = context_builds(build_root, observed)
    head = context_git('rev-parse', 'HEAD').decode().strip()
    tracked = context_git('ls-files', '--stage', '-z', '--', 'crmeb', 'docker', '.dockerignore')
    files, origins, directories = {}, {}, set()
    checked_directories = set()
    for entry in tracked.rstrip(b'\0').split(b'\0'):
        metadata, raw_name = entry.split(b'\t', 1)
        mode, _, stage = metadata.decode().split()
        name = context_relative(raw_name.decode('utf-8'))
        context_require(mode in ('100644', '100755') and stage == '0', 'non-regular or conflicted tracked source')
        if context_excluded(name):
            continue
        leaf = name.removeprefix('crmeb/public/') if name.startswith('crmeb/public/') else ''
        if leaf in ('index.html', 'admin', 'merchant', 'static') or leaf.startswith(('admin/', 'merchant/', 'static/')):
            continue
        for parent in (SOURCE / name).parents:
            if parent not in checked_directories:
                context_regular(parent, True)
                checked_directories.add(parent)
            if parent == SOURCE:
                break
        files[name] = context_pin(context_read(SOURCE / name, observed))
        origins[name] = (SOURCE / name).as_posix()
    for frontend, build in builds.items():
        prefix = 'crmeb/public/' + (frontend + '/' if frontend != 'h5' else '')
        for name, signature in build['tree']['files'].items():
            context_require(not context_excluded('crmeb/public/' + name), 'runtime state in frontend output')
            shared = frontend == 'h5' and name.startswith(('assets/', 'pages/'))
            context_require(frontend != 'h5' or name == 'index.html' or name.startswith('static/') or shared, 'unknown H5 output ownership')
            target = prefix + name
            if shared:
                context_require(files.get(target) == signature, 'H5 shared public asset must match original source: ' + name)
            else:
                context_require(target not in files, 'backend/frontend file collision: ' + target)
                files[target] = signature
                origins[target] = (build['root'] / name).as_posix()
        for name in build['tree']['directories']:
            context_require(frontend != 'h5' or name.split('/')[0] in ('static', 'assets', 'pages'), 'unknown H5 directory')
            directories.add(prefix + name)
    for name in files:
        directories.update(parent.as_posix() for parent in Path(name).parents if parent.as_posix() != '.')
    context_namespace(files, directories)
    context_require(all(name in files for name in ['.dockerignore', 'docker/php/Dockerfile', 'docker/nginx/Dockerfile',
                    'crmeb/composer.lock', 'crmeb/public/index.php', 'crmeb/public/index.html',
                    'crmeb/public/admin/index.html', 'crmeb/public/merchant/index.html']), 'required image source or entry absent')
    output.mkdir()
    destination = output / 'context'
    destination.mkdir()
    for name in sorted(directories, key=lambda value: (len(Path(value).parts), value)):
        (destination / name).mkdir()
    for name, expected in sorted(files.items()):
        raw = context_read(Path(origins[name]), observed)
        context_require(context_pin(raw) == expected, 'copy source differs from plan')
        with (destination / name).open('xb') as stream:
            context_require(stream.write(raw) == len(raw), 'incomplete new context write')
        context_require(context_pin(context_read(destination / name, {})) == expected, 'new context readback differs')
    expected_tree = {'files': dict(sorted(files.items())), 'directories': sorted(directories)}
    context_require(context_tree(destination, {}) == expected_tree, 'complete context tree differs from plan')
    for path in list(observed):
        context_read(path, observed)
    context_require(context_git('rev-parse', 'HEAD').decode().strip() == head
                    and context_git('ls-files', '--stage', '-z', '--', 'crmeb', 'docker', '.dockerignore') == tracked,
                    'controlled source inventory changed during packaging')
    # These are actual packaging observations, not backfilled old formal qualification or build receipts.
    manifest = {'schema': 1, 'status': 'IMAGE_CONTEXT_PREPARED_NOT_FORMAL_ACCEPTANCE', 'observed_at': now(),
                'source_directory': SOURCE.as_posix(), 'source_head': head, 'source_bytes': 'CURRENT_CONTROLLED_WORKTREE',
                'build_root': build_root.as_posix(), 'context': destination.as_posix(), **expected_tree,
                'origins': dict(sorted(origins.items())),
                'builds': {name: {key: value for key, value in data.items() if key != 'root'} for name, data in builds.items()},
                'observed_inputs': {path.as_posix(): value for path, value in sorted(observed.items())},
                'formal_copy_qualification': 'NOT_CREATED', 'image_build_runtime_release': 'NOT_VERIFIED'}
    raw = (json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    with (output / 'IMAGE-CONTEXT.json').open('xb') as stream:
        context_require(stream.write(raw) == len(raw), 'incomplete manifest write')
    context_require((output / 'IMAGE-CONTEXT.json').read_bytes() == raw, 'manifest readback differs')
    print(json.dumps({'status': manifest['status'], 'context': destination.as_posix(), 'files': len(files)}, ensure_ascii=False), flush=True)
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frontend', choices=['merchant', 'admin', 'h5', 'all'], default='merchant')
    parser.add_argument('--memory-mb', type=int, default=2048)
    parser.add_argument('--workers', type=int, choices=[1, 2], default=2)
    parser.add_argument('--allow-docker', action='store_true', help='Root only; only used by h5')
    parser.add_argument('--image-context', type=Path, help='prepare a new image context from existing builds; does not build')
    parser.add_argument('--build-root', type=Path, help='existing build root for --image-context; defaults to the preview build directory')
    args = parser.parse_args()
    if not 1024 <= args.memory_mb <= 4096:
        parser.error('memory-mb must be 1024..4096')
    if args.image_context is not None:
        if args.allow_docker or args.frontend != 'merchant':
            parser.error('image-context cannot be combined with frontend build actions')
        return image_context(args.image_context, args.build_root or OUTPUT)
    if args.build_root is not None:
        parser.error('build-root is only used by image-context')
    OUTPUT.mkdir(parents=True, exist_ok=True)
    frontends = ['merchant', 'admin', 'h5'] if args.frontend == 'all' else [args.frontend]
    for frontend in frontends:
        result = build(frontend, args)
        if result['status'] == 'FAILED':
            return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
