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
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

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


def inputs(frontend):
    name = 'uni-app' if frontend == 'h5' else frontend
    origin = SOURCE / 'template' / name
    destination = OUTPUT / 'work' / 'template' / name
    if not origin.is_dir():
        raise RuntimeError('Missing current frontend: ' + str(origin))
    rows = []
    for directory, children, files in os.walk(origin):
        relative = Path(directory).relative_to(origin)
        children[:] = [child for child in children if child not in {'.git', 'node_modules', '.cache'}
                       and not (relative == Path('.') and child == 'dist')
                       and not (relative == Path('unpackage') and child in {'dist', 'cache'})]
        (destination / relative).mkdir(parents=True, exist_ok=True)
        for filename in files:
            if filename.startswith('.env') and filename not in {'.env.production', '.env.development'}:
                continue
            if filename == '.npmrc' or filename.endswith('.local'):
                continue
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


def limit_parallelism(work, workers):
    config = work / 'vue.config.js'
    # This is a disposable input copy, never a source or dependency edit.
    addition = '''\n// Isolated preview: bound build workers; application configuration stays above.
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
    config.write_text(config.read_text('utf-8') + addition, encoding='utf-8', newline='\n')


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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frontend', choices=['merchant', 'admin', 'h5', 'all'], default='merchant')
    parser.add_argument('--memory-mb', type=int, default=2048)
    parser.add_argument('--workers', type=int, choices=[1, 2], default=2)
    parser.add_argument('--allow-docker', action='store_true', help='Root only; only used by h5')
    args = parser.parse_args()
    if not 1024 <= args.memory_mb <= 4096:
        parser.error('memory-mb must be 1024..4096')
    OUTPUT.mkdir(parents=True, exist_ok=True)
    frontends = ['merchant', 'admin', 'h5'] if args.frontend == 'all' else [args.frontend]
    for frontend in frontends:
        result = build(frontend, args)
        if result['status'] == 'FAILED':
            return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
