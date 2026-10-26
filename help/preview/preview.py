"""Local-only isolated preview lifecycle; never operates on an acceptance Run."""
from __future__ import annotations

import argparse
import csv
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

REPO = Path(__file__).resolve().parents[2]
COMPOSE = Path(__file__).with_name('compose.yml')
DEFAULT_ROOT = REPO.parents[1] / 'artifacts' / 'multi-merchant-preview-20261026'
DOCKER = shutil.which('docker') or r'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
GIT = shutil.which('git') or r'C:/Program Files/Git/cmd/git.exe'
IMAGES = {'PHP': 'mall/php-media-webp:normal-candidate-r1',
          'MYSQL': 'ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45',
          'REDIS': 'ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1',
          'NGINX': 'nginx:1.28-alpine'}


def native(argv, *, environment=None):
    result = subprocess.run(argv, env=environment, text=True, encoding='utf-8',
                            errors='replace', capture_output=True)
    if result.returncode:
        raise RuntimeError(f'Command failed ({result.returncode}): {argv[0]} {argv[1:3]}\n'
                           + result.stdout[-5000:] + result.stderr[-5000:])
    return result.stdout.strip()


def load(root):
    state = json.loads((root / 'state.json').read_text(encoding='utf-8'))
    if not state['project'].startswith('mall-preview-') or Path(state['repo']).resolve() != REPO:
        raise RuntimeError('Preview ownership mismatch')
    return state


def environment(state, root):
    env = dict(os.environ)
    env.update(PREVIEW_PROJECT=state['project'], PREVIEW_RUN_ID=state['run_id'],
               PREVIEW_REPO=REPO.as_posix(), PREVIEW_PORT=str(state['port']),
               PREVIEW_ADMIN_DIST=(root / 'build/admin/dist').as_posix(),
               PREVIEW_MERCHANT_DIST=(root / 'build/merchant/dist').as_posix(),
               PREVIEW_H5_DIST=(root / 'build/h5/dist').as_posix())
    for key, value in state['images'].items():
        env[f'PREVIEW_{key}_IMAGE'] = value
    return env


def compose(state, root, *arguments):
    env = environment(state, root)
    argv = [DOCKER, 'compose', '--project-name', state['project'], '--file', str(COMPOSE), *arguments]
    result = subprocess.run(argv, env=env, text=True, encoding='utf-8', errors='replace', capture_output=True)
    sequence = len(list((root / 'commands').glob('*.json'))) + 1
    prefix = root / 'commands' / f'{sequence:03d}'
    prefix.with_suffix('.stdout.txt').write_text(result.stdout, encoding='utf-8', newline='\n')
    prefix.with_suffix('.stderr.txt').write_text(result.stderr, encoding='utf-8', newline='\n')
    prefix.with_suffix('.json').write_text(json.dumps({'command': arguments, 'exit': result.returncode},
                                         ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    if result.returncode:
        raise RuntimeError(f'Preview command {sequence} failed ({result.returncode}); original output retained at {prefix}\n'
                           + result.stdout[-3000:] + result.stderr[-3000:])
    print(f'OK {sequence:03d}: ' + ' '.join(arguments), flush=True)
    return result.stdout.strip()


def assert_resources(state):
    project = state['project']
    network = json.loads(native([DOCKER, 'network', 'inspect', project + '_preview']))[0]
    if network['Internal'] is not True or network['Labels'].get('marketplace.preview') != project:
        raise RuntimeError('Preview network isolation mismatch')
    ids = native([DOCKER, 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project]).split()
    if not ids:
        raise RuntimeError('No owned preview containers')
    for item in json.loads(native([DOCKER, 'inspect', *ids])):
        if item['Config']['Labels'].get('marketplace.preview') != project:
            raise RuntimeError('Foreign container rejected')
        attached = set(item['NetworkSettings']['Networks'])
        web = item['Config']['Labels'].get('com.docker.compose.service') == 'preview-web'
        permitted = [{project + '_preview'}]
        if web:
            permitted.append({project + '_preview', project + '_ingress'})
        if attached not in permitted:
            raise RuntimeError('Unexpected preview network attachment')
        if project + '_ingress' in attached:
            ingress = json.loads(native([DOCKER, 'network', 'inspect', project + '_ingress']))[0]
            if ingress['Labels'].get('marketplace.preview') != project:
                raise RuntimeError('Foreign preview ingress rejected')
        if item['HostConfig']['Privileged']:
            raise RuntimeError('Privileged preview container rejected')
        for bindings in (item['HostConfig'].get('PortBindings') or {}).values():
            if bindings and not web:
                raise RuntimeError('Only the web preview may publish a port')
            for binding in bindings or []:
                if binding['HostIp'] != '127.0.0.1' or binding['HostPort'] != str(state['port']):
                    raise RuntimeError('Non-local preview exposure rejected')
        for mount in item['Mounts']:
            if mount['Type'] == 'volume' and not mount['Name'].startswith(project + '_'):
                raise RuntimeError('Shared preview volume rejected')


def reload_web(state, root):
    # Re-resolve upstream addresses after Compose recreates a backend container.
    compose(state, root, 'exec', '-T', 'preview-web', 'nginx', '-t', '-c', '/etc/nginx/preview.conf')
    compose(state, root, 'exec', '-T', 'preview-web', 'nginx', '-s', 'reload', '-c', '/etc/nginx/preview.conf')


def wait_web(state):
    url = f'http://127.0.0.1:{state["port"]}/preview-health'
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with opener.open(url, timeout=2) as response:
                result = json.load(response)
                if response.status == 200 and result.get('status') == 200:
                    return
        except (OSError, ValueError):
            pass
        time.sleep(1)
    raise RuntimeError('Preview health did not become ready; inspect the owned service logs')


def export_accounts(state, root):
    directory = root / 'private'
    directory.mkdir(mode=0o700, exist_ok=True)
    if os.name == 'nt':
        identity = next(csv.reader([native(['whoami', '/user', '/fo', 'csv', '/nh'])]))
        sid = identity[1]
        if not sid.startswith('S-1-') or any(c not in 'S-0123456789' for c in sid):
            raise RuntimeError('Current Windows account unavailable')
        native(['icacls', str(directory), '/inheritance:r', '/grant:r', f'*{sid}:(OI)(CI)F'])
    else:
        directory.chmod(0o700)
    path = directory / 'preview-accounts.json'
    if path.exists():
        print(f'Existing private account file: {path}')
        return
    # Deliberately bypass the public command logger: credential stdout stays in memory.
    code = "require 'help/preview/common.php'; preview_assert_isolated(); if(!is_file('/run/preview-app/initialized.json'))exit(1); $c=preview_config(); echo json_encode(['project'=>$c['project'],'synthetic_only'=>true,'accounts'=>$c['credentials']],JSON_THROW_ON_ERROR);"
    raw = native([DOCKER, 'compose', '--project-name', state['project'], '--file', str(COMPOSE),
                  'run', '--rm', '--no-deps', '-T', 'bootstrap', '-r', code], environment=environment(state, root))
    value = json.loads(raw)
    if value.get('project') != state['project'] or set(value.get('accounts', {})) != {'admin', 'merchant', 'customer'}:
        raise RuntimeError('Private preview credentials do not match this instance')
    with path.open('x', encoding='utf-8', newline='\n') as output:
        json.dump(value, output, ensure_ascii=False, indent=2)
        output.write('\n')
    if os.name != 'nt':
        path.chmod(0o600)
    print(f'Private preview accounts saved: {path}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['prepare', 'initialize', 'resume-initialize', 'storefront', 'serve-api', 'serve', 'timers', 'timers-restore', 'queue', 'smoke', 'accounts-file', 'status', 'stop'])
    parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    parser.add_argument('--port', type=int, default=18180)
    parser.add_argument('--hours', default='0.5', help='New unpaid order deadline in hours; timers only, 0 disables new deadlines')
    args = parser.parse_args()
    root = args.root.resolve()
    if args.action == 'prepare':
        if (root / 'state.json').exists():
            raise RuntimeError('Preview already recorded; use status, never reinitialize it')
        if not 1024 <= args.port <= 65535:
            raise RuntimeError('Invalid local preview port')
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', args.port))
        run = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S') + secrets.token_hex(3)
        project = 'mall-preview-' + run
        images = {key: native([DOCKER, 'image', 'inspect', '--format', '{{.Id}}', image])
                  for key, image in IMAGES.items()}
        head = native([GIT, '-c', f'safe.directory={REPO.as_posix()}', '-C', str(REPO), 'rev-parse', 'HEAD'])
        for kind in ['container', 'network', 'volume']:
            listing = ['ps', '-aq'] if kind == 'container' else [kind, 'ls', '-q']
            if native([DOCKER, *listing, '--filter', 'label=com.docker.compose.project=' + project]):
                raise RuntimeError('Preview identity already in use')
        root.mkdir(parents=True, exist_ok=True)
        (root / 'commands').mkdir(exist_ok=False)
        state = {'project': project, 'run_id': run, 'repo': REPO.as_posix(), 'base_head': head,
                 'port': args.port, 'images': images, 'scope': 'local_synthetic_preview_not_stage_acceptance'}
        with (root / 'state.json').open('x', encoding='utf-8', newline='\n') as output:
            json.dump(state, output, ensure_ascii=False, indent=2)
            output.write('\n')
        compose(state, root, 'config', '--quiet')
        compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/bootstrap.php', 'prepare')
        compose(state, root, 'up', '-d', '--wait', '--wait-timeout', '120', 'preview-mysql', 'preview-redis')
        assert_resources(state)
        print('Isolated database services ready; application initialization is still pending.')
    else:
        state = load(root)
        assert_resources(state)
        if args.action in ['initialize', 'resume-initialize']:
            compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/bootstrap.php', args.action)
            state['initialized'] = True
            (root / 'state.json').write_text(json.dumps(state, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        elif args.action == 'storefront':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before creating the storefront')
            compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/storefront.php', 'initialize')
            assert_resources(state)
        elif args.action == 'serve-api':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before starting the application')
            services = ['preview-events', 'preview-app']
            if state.get('timer_configured'):
                services.append('preview-timer')
            if state.get('queue_configured') is True:
                services.append('preview-marketplace-queue')
            compose(state, root, 'up', '-d', *services)
            assert_resources(state)
            if native([DOCKER, 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + state['project'],
                       '--filter', 'label=com.docker.compose.service=preview-web', '--filter', 'status=running']):
                reload_web(state, root)
        elif args.action == 'queue':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before configuring the marketplace queue')
            compose(state, root, 'up', '-d', '--no-deps', 'preview-marketplace-queue')
            assert_resources(state)
            state['queue_configured'] = True
            (root / 'state.json').write_text(json.dumps(state, ensure_ascii=False, indent=2) + '\n',
                                           encoding='utf-8', newline='\n')
            print('Marketplace queue startup configured; verify normal task consumption separately.')
        elif args.action in ['timers', 'timers-restore']:
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization and serve-api before configuring timers')
            mode = 'restore' if args.action == 'timers-restore' else 'configure'
            print(compose(state, root, 'exec', '-T', 'preview-app', 'php',
                          'help/preview/timers.php', mode, args.hours))
            state['timer_configured'] = True
            (root / 'state.json').write_text(json.dumps(state, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
            compose(state, root, 'up', '-d', '--no-deps', 'preview-timer')
            assert_resources(state)
            print('Original Timer started; verify natural expiry through a new unpaid preview order.')
        elif args.action == 'smoke':
            compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/smoke.php')
        elif args.action == 'accounts-file':
            export_accounts(state, root)
        elif args.action == 'serve':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before serving the preview')
            for app in ['admin', 'merchant', 'h5']:
                if not (root / f'build/{app}/dist/index.html').is_file():
                    raise RuntimeError(f'Current {app} frontend build is not ready')
            services = ['preview-events', 'preview-app', 'preview-web']
            if state.get('timer_configured'):
                services.append('preview-timer')
            if state.get('queue_configured') is True:
                services.append('preview-marketplace-queue')
            compose(state, root, 'up', '-d', *services)
            assert_resources(state)
            reload_web(state, root)
            wait_web(state)
            print(f'Preview listening at http://127.0.0.1:{state["port"]}/ ; browser/business verification remains required.')
        elif args.action == 'status':
            print(compose(state, root, 'ps', '--format', 'json'))
        elif args.action == 'stop':
            # A deliberate ordinary stop preserves the preview data. Never force-remove resources.
            compose(state, root, 'stop', '--timeout', '-1')
            print('Owned preview services stopped normally; data volumes retained.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
