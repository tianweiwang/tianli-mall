"""Local-only isolated preview lifecycle; never operates on an acceptance Run."""
from __future__ import annotations

import argparse
import csv
from datetime import datetime, timezone
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

REPO = Path(__file__).resolve().parents[2]
COMPOSE = Path(__file__).with_name('compose.yml')
IMAGE_COMPOSE = Path(__file__).with_name('compose.images.yml')
RELEASE_COMPOSE = Path(__file__).with_name('compose.release.yml')
OPTIMIZED_COMPOSE = REPO / 'help/docker/docker-compose.optimized.yml'
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


def validate_network_subnets(mapping):
    """Validate only the supplied pair; never inspect Docker or host routes."""
    if not isinstance(mapping, dict) or set(mapping) != {'preview', 'ingress'}:
        raise RuntimeError('Image runtime requires preview and ingress subnets')
    private_ranges = [ipaddress.IPv4Network(value) for value in
                      ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']]
    networks = {}
    for name, value in mapping.items():
        if not isinstance(value, str) or '/' not in value:
            raise RuntimeError('Image runtime subnets must be explicit IPv4 CIDRs')
        try:
            network = ipaddress.IPv4Network(value, strict=True)
        except (ipaddress.AddressValueError, ipaddress.NetmaskValueError, ValueError) as error:
            raise RuntimeError('Invalid image runtime subnet: ' + name) from error
        if str(network) != value or not any(network.subnet_of(block) for block in private_ranges):
            raise RuntimeError('Image runtime subnets must be canonical RFC1918 IPv4 CIDRs')
        networks[name] = network
    if networks['preview'].overlaps(networks['ingress']):
        raise RuntimeError('Preview and ingress subnets overlap')
    return {name: str(networks[name]) for name in ['preview', 'ingress']}


def check_network_subnets_available(mapping):
    """Prepare-time Docker-only check; the operator checks host routes separately."""
    subnets = validate_network_subnets(mapping)
    wanted = [ipaddress.IPv4Network(value) for value in subnets.values()]
    ids = native([DOCKER, 'network', 'ls', '-q']).split()
    existing = json.loads(native([DOCKER, 'network', 'inspect', *ids])) if ids else []
    for item in existing:
        for config in (item.get('IPAM', {}).get('Config') or []):
            value = config.get('Subnet')
            if not value:
                continue
            network = ipaddress.ip_network(value, strict=False)
            if network.version == 4 and any(candidate.overlaps(network) for candidate in wanted):
                raise RuntimeError('Image runtime subnet overlaps Docker network: ' + item['Name'])
    return subnets


def network_override_content(mapping):
    subnets = validate_network_subnets(mapping)
    return {'networks': {name: {'ipam': {'config': [{'subnet': subnets[name]}]}}
                         for name in ['preview', 'ingress']}}


def load(root):
    state = json.loads((root / 'state.json').read_text(encoding='utf-8'))
    if not state['project'].startswith('mall-preview-') or Path(state['repo']).resolve() != REPO:
        raise RuntimeError('Preview ownership mismatch')
    if not isinstance(state.get('full_runtime', False), bool):
        raise RuntimeError('Invalid preview runtime mode')
    if not isinstance(state.get('image_runtime', False), bool) or (
            state.get('image_runtime', False) and not state.get('full_runtime', False)):
        raise RuntimeError('Invalid preview image mode')
    if state.get('full_runtime') and state.get('legacy_review_fixture', False):
        raise RuntimeError('Full runtime cannot use the legacy fixture')
    if state.get('image_runtime', False):
        validate_network_subnets(state.get('network_subnets'))
        if 'network_override' in state:
            raise RuntimeError('Image runtime does not use a source network override')
    elif 'network_subnets' in state or 'network_override' in state:
        expected = root / 'networks.compose.json'
        override = state.get('network_override')
        if (not isinstance(override, str) or override != expected.as_posix()
                or expected.is_symlink() or not expected.is_file() or expected.resolve() != expected):
            raise RuntimeError('Network override must be the regular file in its owned preview root')
        content = json.loads(expected.read_text(encoding='utf-8'))
        if content != network_override_content(state.get('network_subnets')):
            raise RuntimeError('Network override differs from the recorded preview subnets')
    if not isinstance(state.get('release_runtime', False), bool) or (
            state.get('release_runtime', False) and not state.get('image_runtime', False)):
        raise RuntimeError('Invalid preview release mode')
    if state.get('release_runtime', False):
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', state.get('checker_image', '')):
            raise RuntimeError('Release runtime requires an explicit immutable checker image')
        if Path(state.get('release_env_file', '')).resolve() != root / 'empty.env':
            raise RuntimeError('Release runtime environment path differs from its owned root')
    return state


def environment(state, root):
    legacy_fixture = state.get('legacy_review_fixture', False)
    full_runtime = state.get('full_runtime', False)
    image_runtime = state.get('image_runtime', False)
    queue_autostart = state.get('marketplace_queue_autostart', False)
    if (not isinstance(legacy_fixture, bool) or not isinstance(full_runtime, bool)
            or not isinstance(image_runtime, bool) or (legacy_fixture and full_runtime)
            or (image_runtime and not full_runtime) or not isinstance(queue_autostart, bool)
            or (queue_autostart and not full_runtime)):
        raise RuntimeError('Invalid preview fixture mode')
    env = dict(os.environ)
    env.update(PREVIEW_PROJECT=state['project'], PREVIEW_RUN_ID=state['run_id'],
               PREVIEW_REPO=REPO.as_posix(), PREVIEW_PORT=str(state['port']),
               PREVIEW_LEGACY_REVIEW_FIXTURE='1' if legacy_fixture else '0',
               PREVIEW_FULL_RUNTIME='1' if full_runtime else '0',
               PREVIEW_IMAGE_RUNTIME='1' if image_runtime else '0',
               PREVIEW_MARKETPLACE_QUEUE_AUTOSTART='true' if queue_autostart else 'false',
               PREVIEW_NGINX_CONFIG=(COMPOSE.parent / ('nginx-runtime.conf' if full_runtime else 'nginx.conf')).as_posix(),
               PREVIEW_ADMIN_DIST=(root / 'build/admin/dist').as_posix(),
               PREVIEW_MERCHANT_DIST=(root / 'build/merchant/dist').as_posix(),
               PREVIEW_H5_DIST=(root / 'build/h5/dist').as_posix())
    if state.get('release_runtime', False):
        # Bootstrap is enabled only by an explicit `run bootstrap`, never by apply/up.
        env.pop('COMPOSE_PROFILES', None)
        env['PREVIEW_RELEASE_RUNTIME'] = '1'
        values = release_environment(state, root)
        # These placeholders are used only to resolve Compose before bootstrap prepare.
        # compose() rejects service startup until the private values have been saved.
        env.update(values or {'MYSQL_ROOT_PASSWORD': 'bootstrap-not-ready',
                             'MYSQL_PASSWORD': 'bootstrap-not-ready',
                             'MYSQL_USER': 'preview_app', 'MYSQL_DATABASE': 'mall_preview',
                             'REDIS_PASSWORD': 'bootstrap-not-ready'})
        env['MARKETPLACE_QUEUE_AUTOSTART'] = 'true' if queue_autostart else 'false'
    for key, value in state['images'].items():
        env[f'PREVIEW_{key}_IMAGE'] = value
    if 'network_subnets' in state:
        subnets = validate_network_subnets(state.get('network_subnets'))
        env.update(PREVIEW_NETWORK_SUBNET=subnets['preview'], PREVIEW_INGRESS_SUBNET=subnets['ingress'])
    return env


def compose_command(state, *arguments):
    if state.get('release_runtime', False):
        return [DOCKER, 'compose', '--project-name', state['project'],
                '--env-file', state['release_env_file'], '--file', str(OPTIMIZED_COMPOSE),
                '--file', str(RELEASE_COMPOSE), *arguments]
    argv = [DOCKER, 'compose', '--project-name', state['project'], '--file', str(COMPOSE)]
    if state.get('image_runtime', False):
        argv += ['--file', str(IMAGE_COMPOSE)]
    elif 'network_override' in state:
        argv += ['--file', state['network_override']]
    return [*argv, *arguments]


def compose(state, root, *arguments, expected_exits=(0,)):
    if state.get('release_runtime', False) and release_environment(state, root) is None:
        permitted = [('config', '--quiet'),
                     ('run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/bootstrap.php', 'prepare')]
        if arguments not in permitted:
            raise RuntimeError('Release bootstrap secrets are not ready; service startup refused')
    env = environment(state, root)
    argv = compose_command(state, *arguments)
    result = subprocess.run(argv, env=env, text=True, encoding='utf-8', errors='replace', capture_output=True)
    return record_command(root, arguments, result, expected_exits)


def record_command(root, arguments, result, expected_exits=(0,)):
    sequence = len(list((root / 'commands').glob('*.json'))) + 1
    prefix = root / 'commands' / f'{sequence:03d}'
    prefix.with_suffix('.stdout.txt').write_text(result.stdout, encoding='utf-8', newline='\n')
    prefix.with_suffix('.stderr.txt').write_text(result.stderr, encoding='utf-8', newline='\n')
    prefix.with_suffix('.json').write_text(json.dumps({'command': arguments, 'exit': result.returncode},
                                         ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    if result.returncode not in expected_exits:
        raise RuntimeError(f'Preview command {sequence} failed ({result.returncode}); original output retained at {prefix}\n'
                           + result.stdout[-3000:] + result.stderr[-3000:])
    print(f'OK {sequence:03d}: ' + ' '.join(arguments), flush=True)
    return result.stdout.strip()


def ensure_runtime_queue(state, root):
    command = ('exec', '-T', runtime_service(state), 'supervisorctl',
               '-c', '/etc/supervisor/supervisord.conf')

    def status(expected_exits=(0,)):
        output = compose(state, root, *command, 'status', 'marketplace-queue',
                         expected_exits=expected_exits)
        lines = output.splitlines()
        fields = lines[0].split() if len(lines) == 1 else []
        if len(fields) < 2 or fields[0] != 'marketplace-queue':
            raise RuntimeError('Unexpected marketplace Supervisor status; original output retained')
        if fields[1] == 'RUNNING':
            if len(fields) < 4 or fields[2] != 'pid' or not fields[3].endswith(','):
                raise RuntimeError('Marketplace Supervisor PID unavailable; original output retained')
            pid = fields[3][:-1]
            if not pid.isascii() or not pid.isdecimal() or int(pid) <= 0:
                raise RuntimeError('Invalid marketplace Supervisor PID; original output retained')
            return fields[1], pid
        return fields[1], None

    before, original_pid = status(expected_exits=(0, 3))
    if before == 'STOPPED':
        compose(state, root, *command, 'start', 'marketplace-queue')
    elif before != 'RUNNING':
        raise RuntimeError('Marketplace Supervisor program is not STOPPED or RUNNING; original output retained')
    after, current_pid = status()
    if after != 'RUNNING' or (original_pid is not None and current_pid != original_pid):
        raise RuntimeError('Marketplace Supervisor program did not remain RUNNING; original output retained')


def assert_resources(state):
    project = state['project']
    subnets = validate_network_subnets(state.get('network_subnets')) if 'network_subnets' in state else None
    network = json.loads(native([DOCKER, 'network', 'inspect', project + '_preview']))[0]
    if network['Internal'] is not True or network['Labels'].get('marketplace.preview') != project:
        raise RuntimeError('Preview network isolation mismatch')
    if subnets and [row.get('Subnet') for row in network['IPAM'].get('Config') or []] != [subnets['preview']]:
        raise RuntimeError('Preview network subnet differs from the recorded preview instance')
    ids = native([DOCKER, 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project]).split()
    if not ids:
        raise RuntimeError('No owned preview containers')
    for item in json.loads(native([DOCKER, 'inspect', *ids])):
        if item['Config']['Labels'].get('marketplace.preview') != project:
            raise RuntimeError('Foreign container rejected')
        attached = set(item['NetworkSettings']['Networks'])
        service = item['Config']['Labels'].get('com.docker.compose.service')
        if state.get('full_runtime', False):
            if service in ['preview-app', 'preview-events', 'preview-timer', 'preview-marketplace-queue']:
                raise RuntimeError('Split application services cannot share a full runtime preview')
        elif service == 'preview-runtime':
            raise RuntimeError('Full runtime cannot share a standard preview')
        if state.get('release_runtime', False) and service not in ['mysql', 'redis', 'phpfpm', 'nginx', 'bootstrap']:
            raise RuntimeError('Unexpected service in the isolated release stack')
        web = service == web_service(state)
        permitted = [{project + '_preview'}]
        if web:
            permitted.append({project + '_preview', project + '_ingress'})
        if attached not in permitted:
            raise RuntimeError('Unexpected preview network attachment')
        if project + '_ingress' in attached:
            ingress = json.loads(native([DOCKER, 'network', 'inspect', project + '_ingress']))[0]
            if ingress['Labels'].get('marketplace.preview') != project:
                raise RuntimeError('Foreign preview ingress rejected')
            if subnets and [row.get('Subnet') for row in ingress['IPAM'].get('Config') or []] != [subnets['ingress']]:
                raise RuntimeError('Ingress subnet differs from the recorded preview instance')
        if item['HostConfig']['Privileged']:
            raise RuntimeError('Privileged preview container rejected')
        web_port = '80/tcp' if state.get('image_runtime', False) else '8080/tcp'
        for port, bindings in (item['HostConfig'].get('PortBindings') or {}).items():
            if bindings and not web:
                raise RuntimeError('Only the web preview may publish a port')
            if bindings and port != web_port:
                raise RuntimeError('Unexpected preview web container port')
            for binding in bindings or []:
                if binding['HostIp'] != '127.0.0.1' or binding['HostPort'] != str(state['port']):
                    raise RuntimeError('Non-local preview exposure rejected')
        for mount in item['Mounts']:
            if mount['Type'] == 'volume' and not mount['Name'].startswith(project + '_'):
                raise RuntimeError('Shared preview volume rejected')


def reload_web(state, root):
    # Re-resolve upstream addresses after Compose recreates a backend container.
    config = [] if state.get('image_runtime', False) else ['-c', '/etc/nginx/preview.conf']
    compose(state, root, 'exec', '-T', web_service(state), 'nginx', '-t', *config)
    compose(state, root, 'exec', '-T', web_service(state), 'nginx', '-s', 'reload', *config)


def runtime_service(state):
    return 'phpfpm' if state.get('release_runtime', False) else 'preview-runtime'


def web_service(state):
    return 'nginx' if state.get('release_runtime', False) else 'preview-web'


def database_services(state):
    return ['mysql', 'redis'] if state.get('release_runtime', False) else ['preview-mysql', 'preview-redis']


def wait_web(state):
    path = '/adminapi/login/info' if state.get('full_runtime', False) else '/preview-health'
    url = f'http://127.0.0.1:{state["port"]}{path}'
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


def private_directory(root):
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
    return directory


def release_environment(state, root):
    path = root / 'private/release-environment.json'
    if not path.exists():
        return None
    value = json.loads(path.read_text(encoding='utf-8'))
    variables = value.get('variables')
    if (value.get('project') != state['project'] or value.get('run_id') != state['run_id']
            or not isinstance(variables, dict)
            or set(variables) != {'MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_USER', 'MYSQL_DATABASE', 'REDIS_PASSWORD'}
            or variables['MYSQL_USER'] != 'preview_app' or variables['MYSQL_DATABASE'] != 'mall_preview'
            or any(not isinstance(variables[key], str) or not re.fullmatch(r'[0-9a-f]{48}', variables[key])
                   for key in ['MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD', 'REDIS_PASSWORD'])):
        raise RuntimeError('Private release environment identity or shape differs')
    return variables


def export_release_environment(state, root):
    directory = private_directory(root)
    path = directory / 'release-environment.json'
    if path.exists():
        raise RuntimeError('Release bootstrap environment already exists; original file retained')
    code = ("require 'help/preview/common.php'; $c=preview_config(); "
            "$p=file_get_contents('/run/preview-bootstrap/root-password'); "
            "echo json_encode(['project'=>$c['project'],'run_id'=>$c['run_id'],'variables'=>["
            "'MYSQL_ROOT_PASSWORD'=>$p,'MYSQL_PASSWORD'=>$c['database']['app']['password'],"
            "'MYSQL_USER'=>$c['database']['app']['username'],'MYSQL_DATABASE'=>$c['database']['name'],"
            "'REDIS_PASSWORD'=>$c['redis_password']]],JSON_THROW_ON_ERROR);")
    # Secret output is never sent to native(), record_command(), or an exception.
    result = subprocess.run(compose_command(state, 'run', '--rm', '--no-deps', '-T', 'bootstrap', '-r', code),
                            env=environment(state, root), text=True, encoding='utf-8', errors='replace',
                            capture_output=True)
    if result.returncode:
        raise RuntimeError('Private release environment export failed; raw output withheld')
    try:
        value = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise RuntimeError('Private release environment export was not valid JSON') from None
    with path.open('x', encoding='utf-8', newline='\n') as output:
        json.dump(value, output, ensure_ascii=False, indent=2)
        output.write('\n')
    if os.name != 'nt':
        path.chmod(0o600)
    release_environment(state, root)


def apply_release(state, root):
    if release_environment(state, root) is None:
        raise RuntimeError('Release bootstrap secrets are not ready')
    arguments = [sys.executable, '-X', 'utf8', str(REPO / 'help/docker/deploy-marketplace.py'),
                 '--compose-file', str(OPTIMIZED_COMPOSE), '--compose-file', str(RELEASE_COMPOSE),
                 '--env-file', state['release_env_file'], '--project-name', state['project'],
                 '--checker-image', state['checker_image'], '--docker', DOCKER, '--apply']
    result = subprocess.run(arguments, env=environment(state, root), text=True,
                            encoding='utf-8', errors='replace', capture_output=True)
    output = record_command(root, ['deploy-marketplace.py', '--apply'], result)
    receipt = json.loads(output)
    if (receipt.get('status') != 'APPLIED' or receipt.get('php_image') != state['images']['PHP']
            or receipt.get('nginx_image') != state['images']['NGINX']
            or receipt.get('checker_image') != state['checker_image']):
        raise RuntimeError('Release apply did not confirm the recorded immutable images')


def export_accounts(state, root):
    directory = private_directory(root)
    path = directory / 'preview-accounts.json'
    if path.exists():
        print(f'Existing private account file: {path}')
        return
    # Deliberately bypass the public command logger: credential stdout stays in memory.
    code = "require 'help/preview/common.php'; preview_assert_isolated(); if(!is_file('/run/preview-app/initialized.json'))exit(1); $c=preview_config(); echo json_encode(['project'=>$c['project'],'synthetic_only'=>true,'accounts'=>$c['credentials']],JSON_THROW_ON_ERROR);"
    raw = native(compose_command(state, 'run', '--rm', '--no-deps', '-T', 'bootstrap', '-r', code),
                 environment=environment(state, root))
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
    parser.add_argument('action', choices=['prepare', 'initialize', 'resume-initialize', 'storefront', 'serve-api', 'serve', 'runtime', 'video', 'timers', 'timers-restore', 'queue', 'smoke', 'accounts-file', 'status', 'stop'])
    parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    parser.add_argument('--port', type=int, default=18180)
    parser.add_argument('--legacy-review-fixture', action='store_true',
                        help='prepare only: separate legacy review fixture with synthetic balance')
    parser.add_argument('--full-runtime', action='store_true',
                        help='prepare only: new isolated root for the original FPM/Supervisor runtime')
    parser.add_argument('--release-runtime', action='store_true',
                        help='prepare only: original release Compose with full runtime and both images')
    parser.add_argument('--checker-image', help='release prepare only: independently approved immutable sha256 image ID')
    parser.add_argument('--marketplace-queue-autostart', action='store_true',
                        help='prepare only, with full runtime: persist marketplace Supervisor autostart')
    parser.add_argument('--php-image', help='prepare only: existing PHP image, paired with --nginx-image and --full-runtime')
    parser.add_argument('--nginx-image', help='prepare only: existing Nginx image, paired with --php-image and --full-runtime')
    parser.add_argument('--preview-subnet', help='new prepare only: RFC1918 IPv4 CIDR, paired with ingress; check host routes separately')
    parser.add_argument('--ingress-subnet', help='new prepare only: separate RFC1918 IPv4 CIDR, paired with preview; check host routes separately')
    parser.add_argument('--hours', default='0.5', help='New unpaid order deadline in hours; timers only, 0 disables new deadlines')
    args = parser.parse_args()
    root = args.root.resolve()
    if args.marketplace_queue_autostart and (args.action != 'prepare' or not args.full_runtime):
        raise RuntimeError('Queue autostart is configured only for a new full-runtime preview')
    image_runtime = args.php_image is not None or args.nginx_image is not None
    if args.release_runtime and (args.action != 'prepare' or not args.full_runtime or not image_runtime
                                 or not args.checker_image or root.exists()):
        raise RuntimeError('Release runtime requires a new full/image prepare and an explicit checker image')
    if args.checker_image is not None and (not args.release_runtime
            or not re.fullmatch(r'sha256:[0-9a-f]{64}', args.checker_image)):
        raise RuntimeError('Checker image is only accepted as a full immutable ID for release prepare')
    if image_runtime and (not args.php_image or not args.nginx_image or args.action != 'prepare'
                          or not args.full_runtime or root.exists()):
        raise RuntimeError('Image runtime requires both local images, full runtime and a new prepare root')
    network_subnets = None
    if image_runtime:
        network_subnets = validate_network_subnets({'preview': args.preview_subnet, 'ingress': args.ingress_subnet})
    elif args.preview_subnet is not None or args.ingress_subnet is not None:
        if args.action != 'prepare' or root.exists():
            raise RuntimeError('Explicit preview subnets require prepare with a new root')
        network_subnets = validate_network_subnets({'preview': args.preview_subnet, 'ingress': args.ingress_subnet})
    if args.legacy_review_fixture and (args.action != 'prepare' or root == DEFAULT_ROOT.resolve() or args.port == 18180):
        raise RuntimeError('Legacy fixture requires prepare, its own root and a separate local port')
    if args.full_runtime and (args.action != 'prepare' or args.legacy_review_fixture or root.exists()):
        raise RuntimeError('Full runtime requires prepare with a new root and no legacy fixture')
    if args.action == 'prepare':
        if (root / 'state.json').exists():
            raise RuntimeError('Preview already recorded; use status, never reinitialize it')
        if not 1024 <= args.port <= 65535:
            raise RuntimeError('Invalid local preview port')
        if network_subnets is not None:
            network_subnets = check_network_subnets_available(network_subnets)
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', args.port))
        run = datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S') + secrets.token_hex(3)
        project = 'mall-preview-' + run
        image_references = dict(IMAGES)
        if image_runtime:
            image_references.update(PHP=args.php_image, NGINX=args.nginx_image)
        images = {key: native([DOCKER, 'image', 'inspect', '--format', '{{.Id}}', image])
                  for key, image in image_references.items()}
        if args.release_runtime and native([DOCKER, 'image', 'inspect', '--format', '{{.Id}}', args.checker_image]) != args.checker_image:
            raise RuntimeError('Approved checker image ID differs from the local image')
        head = native([GIT, '-c', f'safe.directory={REPO.as_posix()}', '-C', str(REPO), 'rev-parse', 'HEAD'])
        for kind in ['container', 'network', 'volume']:
            listing = ['ps', '-aq'] if kind == 'container' else [kind, 'ls', '-q']
            if native([DOCKER, *listing, '--filter', 'label=com.docker.compose.project=' + project]):
                raise RuntimeError('Preview identity already in use')
        root.mkdir(parents=True, exist_ok=image_runtime or network_subnets is None)
        (root / 'commands').mkdir(exist_ok=False)
        state = {'project': project, 'run_id': run, 'repo': REPO.as_posix(), 'base_head': head,
                 'port': args.port, 'images': images, 'scope': 'local_synthetic_preview_not_stage_acceptance',
                 'legacy_review_fixture': args.legacy_review_fixture, 'full_runtime': args.full_runtime,
                 'image_runtime': image_runtime, 'marketplace_queue_autostart': args.marketplace_queue_autostart,
                 'release_runtime': args.release_runtime}
        if args.release_runtime:
            state['checker_image'] = args.checker_image
            state['release_env_file'] = (root / 'empty.env').as_posix()
            (root / 'empty.env').write_text('', encoding='utf-8')
        if network_subnets is not None:
            state['network_subnets'] = network_subnets
            if not image_runtime:
                override = root / 'networks.compose.json'
                state['network_override'] = override.as_posix()
                with override.open('x', encoding='utf-8', newline='\n') as output:
                    json.dump(network_override_content(network_subnets), output, ensure_ascii=False, indent=2)
                    output.write('\n')
        with (root / 'state.json').open('x', encoding='utf-8', newline='\n') as output:
            json.dump(state, output, ensure_ascii=False, indent=2)
            output.write('\n')
        compose(state, root, 'config', '--quiet')
        compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap', 'help/preview/bootstrap.php', 'prepare')
        if args.release_runtime:
            export_release_environment(state, root)
        compose(state, root, 'up', '-d', '--wait', '--wait-timeout', '120', *database_services(state))
        assert_resources(state)
        print('Isolated database services ready; application initialization is still pending.')
    else:
        state = load(root)
        if state.get('full_runtime', False) and args.action in ['serve', 'serve-api', 'video', 'timers', 'timers-restore']:
            raise RuntimeError('Full runtime previews use runtime; split application actions are unavailable')
        if args.action == 'runtime' and (not state.get('full_runtime', False) or not state.get('initialized')):
            raise RuntimeError('Runtime requires an initialized full-runtime preview created with a new root')
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
        elif args.action == 'runtime':
            if not state.get('image_runtime', False):
                for app in ['admin', 'merchant', 'h5']:
                    if not (root / f'build/{app}/dist/index.html').is_file():
                        raise RuntimeError(f'Current {app} frontend build is not ready')
            compose(state, root, 'up', '-d', '--wait', '--wait-timeout', '120', *database_services(state))
            assert_resources(state)
            print(compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap',
                          'help/preview/bootstrap.php', 'runtime'))
            if state.get('release_runtime', False):
                apply_release(state, root)
            else:
                compose(state, root, 'up', '-d', '--no-deps', '--wait', '--wait-timeout', '180', 'preview-runtime')
            assert_resources(state)
            if state.get('queue_configured') is True:
                ensure_runtime_queue(state, root)
            if not state.get('release_runtime', False):
                compose(state, root, 'up', '-d', '--no-deps', 'preview-web')
                assert_resources(state)
                reload_web(state, root)
            wait_web(state)
            print(f'Full runtime listening at http://127.0.0.1:{state["port"]}/ ; browser/business verification remains required.')
        elif args.action == 'serve-api':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before starting the application')
            services = ['preview-events', 'preview-app']
            if state.get('timer_configured') or state.get('video_configured'):
                services.append('preview-timer')
            if state.get('queue_configured') is True:
                services.append('preview-marketplace-queue')
            compose(state, root, 'up', '-d', *services)
            assert_resources(state)
            if native([DOCKER, 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + state['project'],
                       '--filter', 'label=com.docker.compose.service=preview-web', '--filter', 'status=running']):
                reload_web(state, root)
        elif args.action == 'video':
            if not state.get('initialized') or state.get('legacy_review_fixture', False):
                raise RuntimeError('Video chunks require an initialized standard preview')
            # Reload only the two consumers of these settings, using ordinary unbounded stop.
            compose(state, root, 'stop', '--timeout', '-1', 'preview-app', 'preview-timer')
            print(compose(state, root, 'run', '--rm', '--no-deps', '-T', 'bootstrap',
                          'help/preview/bootstrap.php', 'video'))
            state['video_configured'] = True
            (root / 'state.json').write_text(json.dumps(state, ensure_ascii=False, indent=2) + '\n',
                                           encoding='utf-8', newline='\n')
            compose(state, root, 'up', '-d', '--no-deps', 'preview-app', 'preview-timer')
            assert_resources(state)
            if native([DOCKER, 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + state['project'],
                       '--filter', 'label=com.docker.compose.service=preview-web', '--filter', 'status=running']):
                reload_web(state, root)
                wait_web(state)
            print('Video chunks configured; verify browser upload and natural timer cleanup separately.')
        elif args.action == 'queue':
            if not state.get('initialized'):
                raise RuntimeError('Complete preview initialization before configuring the marketplace queue')
            if state.get('full_runtime', False):
                ensure_runtime_queue(state, root)
            else:
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
            if state.get('timer_configured') or state.get('video_configured'):
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
