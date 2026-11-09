"""Install or run one isolated multi-merchant development preview."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import preview
from assets import install_assets, verify_assets
from images import docker_platform, prepare_images


SCOPE = 'local_synthetic_portable_preview'
READY = 'portable-ready.json'


def read_config(path: Path) -> tuple[Path, int]:
    path = path.resolve(strict=True)
    config = json.loads(path.read_text(encoding='utf-8-sig'))
    if not isinstance(config, dict) or set(config) != {'root', 'port'}:
        raise ValueError('Configuration must contain exactly root and port')
    if not isinstance(config['root'], str) or not config['root'].strip():
        raise ValueError('root must be a path to a dedicated preview directory')
    root = (path.parent / config['root']).resolve()
    if root.is_relative_to(preview.REPO) or preview.REPO.is_relative_to(root):
        raise ValueError('Choose a preview directory outside the Git checkout')
    port = config['port']
    if type(port) is not int or not 1024 <= port <= 65535 or port in {8011, 18212, 18218}:
        raise ValueError('Choose an unused local port from 1024 to 65535, excluding 8011, 18212 and 18218')
    return root, port


def check_local_docker() -> str:
    if not shutil.which('docker') or not shutil.which('git'):
        raise RuntimeError('Git and Docker must be on PATH')
    context = preview.native([preview.DOCKER, 'context', 'show'])
    endpoint = preview.native([preview.DOCKER, 'context', 'inspect', context,
                               '--format', '{{.Endpoints.docker.Host}}'])
    hosts = [endpoint]
    if os.environ.get('DOCKER_HOST'):
        hosts.append(os.environ['DOCKER_HOST'])
    if any(not host.startswith(('unix:///', 'npipe:///')) for host in hosts):
        raise RuntimeError('This installer requires a local Docker context')
    platform = docker_platform(preview.DOCKER)
    preview.native([preview.DOCKER, 'compose', 'version', '--short'])
    return platform


def checkout_head() -> str:
    git = [preview.GIT, '--no-optional-locks', '-c', f'safe.directory={preview.REPO.as_posix()}',
           '-C', str(preview.REPO)]
    if preview.native([*git, 'status', '--porcelain=v1', '--', 'crmeb', 'docker', 'template', 'help/migrations', 'help/preview']):
        raise RuntimeError('Install from a clean checkout of the package branch')
    return preview.native([*git, 'rev-parse', 'HEAD'])


def run_preview(root: Path, platform: str, action: str, *args: str) -> None:
    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith(('COMPOSE_', 'PREVIEW_'))}
    environment['COMPOSE_DISABLE_ENV_FILE'] = 'true'
    environment['DOCKER_DEFAULT_PLATFORM'] = platform
    result = subprocess.run([sys.executable, '-B', str(preview.COMPOSE.parent / 'preview.py'),
                             action, '--root', str(root), *args], cwd=preview.REPO, env=environment)
    if result.returncode:
        raise RuntimeError(f'Preview {action} failed. See the original output and {root / "commands"}.')


def owned_state(root: Path, port: int) -> dict:
    state = preview.load(root)
    if (state.get('scope') != SCOPE or state.get('port') != port
            or state.get('full_runtime') is not True or state.get('image_runtime') is not False):
        raise RuntimeError('The directory is not the portable preview for this configuration')
    return state


def start(root: Path, port: int, platform: str) -> None:
    head = checkout_head()
    if root.exists():
        state = owned_state(root, port)
        marker = root / READY
        if not marker.is_file() or not state.get('initialized'):
            raise RuntimeError('This installation is incomplete. Keep its command logs and choose a new root.')
        expected = {'project': state['project'], 'source': head}
        if json.loads(marker.read_text(encoding='utf-8')) != expected or state['base_head'] != head:
            raise RuntimeError('Restart this instance from the same Git revision used for its installation')
        run_preview(root, platform, 'runtime')
    else:
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', port))
        verify_assets(preview.REPO)
        images = prepare_images(preview.REPO, preview.DOCKER, platform)
        source_args = [arg for role, image in sorted(images.items())
                       for arg in ('--source-image', f'{role}={image}')]
        run_preview(root, platform, 'prepare', '--full-runtime', '--marketplace-queue-autostart', '--combined-checkout',
                    '--port', str(port), *source_args)
        state = owned_state(root, port)
        run_preview(root, platform, 'initialize')
        install_assets(preview.REPO, root)
        run_preview(root, platform, 'storefront')
        run_preview(root, platform, 'runtime')
        run_preview(root, platform, 'smoke')
        run_preview(root, platform, 'accounts-file')
        with (root / READY).open('x', encoding='utf-8', newline='\n') as marker:
            json.dump({'project': state['project'], 'source': head}, marker)
            marker.write('\n')
    origin = f'http://127.0.0.1:{port}'
    print(f'顾客商城 {origin}/')
    print(f'平台后台 {origin}/admin/login')
    print(f'商家后台 {origin}/merchant/#/login')
    print(f'合成账号 {root / "private" / "preview-accounts.json"}')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['start', 'status', 'stop'])
    parser.add_argument('--config', type=Path, required=True)
    args = parser.parse_args()
    root, port = read_config(args.config)
    platform = check_local_docker()
    if args.action != 'start' and not root.is_dir():
        raise FileNotFoundError(f'Preview directory does not exist: {root}')
    root.parent.mkdir(parents=True, exist_ok=True)
    lock_path = root.with_name(root.name + '.portable.lock')
    with lock_path.open('x', encoding='utf-8') as lock:
        try:
            lock.write(f'{os.getpid()}\n')
            lock.flush()
            if args.action == 'start':
                start(root, port, platform)
            else:
                owned_state(root, port)
                run_preview(root, platform, args.action)
        finally:
            lock.close()
            lock_path.unlink()


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, KeyError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
