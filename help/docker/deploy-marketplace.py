#!/usr/bin/env python3
"""Check a candidate against an initialized target; --apply explicitly starts it.

Use an operator-approved fixed checker image. Do not change configuration or run
migrations concurrently. Default check never starts candidate application code.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import tempfile
import sys
import time
import uuid

IMAGE_ID = re.compile(r'sha256:[0-9a-f]{64}\Z')
CONTAINER_ID = re.compile(r'[0-9a-f]{64}\Z')
SAFE_NAME = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]*\Z')
FAILURES = {'SCHEMA_UNAVAILABLE', 'SCHEMA_INCOMPATIBLE', 'RELEASE_IDENTITY_INVALID', 'RUNTIME_NOT_READY'}
DB_FIELDS = ('TYPE', 'DRIVER', 'HOSTNAME', 'DATABASE', 'USERNAME', 'PASSWORD', 'HOSTPORT', 'CHARSET', 'PREFIX', 'DEBUG')
ENV_KEYS = {'APP_ENV'} | {prefix + field for prefix in ('DATABASE_', 'PHP_DATABASE_') for field in DB_FIELDS}


class Rejected(Exception):
    def __init__(self, reason='RELEASE_IDENTITY_INVALID'):
        self.reason = reason if reason in FAILURES else 'SCHEMA_UNAVAILABLE'


def require(value, reason='RELEASE_IDENTITY_INVALID'):
    if not value:
        raise Rejected(reason)


def invoke(argv, *, environment=None, data=None, limit=8 * 1024 * 1024, timeout=300):
    """Capture bounded bytes in memory; never emit command/environment/stderr."""
    result, errors = bytearray(), bytearray()
    process = subprocess.Popen(argv, stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=environment, bufsize=0)
    streams = {process.stdout: (result, limit), process.stderr: (errors, 4096)}
    try:
        for stream in streams:
            os.set_blocking(stream.fileno(), False)
        if data is not None:
            try:
                process.stdin.write(data)
                process.stdin.close()
            except BrokenPipeError:
                pass
        deadline = time.monotonic() + timeout
        while streams or process.poll() is None:
            require(time.monotonic() < deadline, 'SCHEMA_UNAVAILABLE')
            progress = False
            for stream, (target, maximum) in list(streams.items()):
                try:
                    chunk = os.read(stream.fileno(), min(8192, maximum - len(target) + 1))
                except BlockingIOError:
                    continue
                progress = True
                if not chunk:
                    del streams[stream]
                    continue
                require(len(target) + len(chunk) <= maximum, 'SCHEMA_UNAVAILABLE')
                target.extend(chunk)
            if not progress:
                time.sleep(0.02)
        code = process.wait()
    finally:
        # Only terminate the local CLI on overflow/timeout; never stop a container.
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        for stream in (process.stdin, process.stdout, process.stderr):
            if stream is not None:
                stream.close()
    if code:
        reason = errors.decode('utf-8', 'replace').strip()
        raise Rejected(reason if reason in FAILURES else 'SCHEMA_UNAVAILABLE')
    return bytes(result)


def decode(raw):
    return json.loads(raw.decode('utf-8'))


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def inspect_image(docker, reference):
    require(isinstance(reference, str) and reference and not reference.startswith('-'))
    rows = decode(invoke([docker, 'image', 'inspect', reference]))
    require(isinstance(rows, list) and len(rows) == 1 and IMAGE_ID.fullmatch(rows[0].get('Id', '')))
    return rows[0]


def cleanup_container(docker, container, image_id, ownership):
    """Remove only this invocation's exact stopped helper; retain ambiguous state."""
    require(isinstance(container, str) and CONTAINER_ID.fullmatch(container))
    rows = decode(invoke([docker, 'container', 'inspect', container]))
    require(isinstance(rows, list) and len(rows) == 1)
    current = rows[0]
    state = current.get('State', {})
    require(current.get('Id') == container and current.get('Image') == image_id
            and current.get('Config', {}).get('Labels', {}).get('marketplace.pre-up.owner') == ownership
            and state.get('Status') in ('created', 'exited') and state.get('Running') is False
            and state.get('Paused') is False and state.get('Restarting') is False)
    invoke([docker, 'rm', '-v', container], limit=1024)


def compose_command(args):
    command = [args.docker, 'compose', '--env-file', str(args.env_file)]
    if args.project_name:
        command += ['--project-name', args.project_name]
    for path in args.compose_file:
        command += ['--file', str(path)]
    return command


def read_compose(args):
    value = decode(invoke(compose_command(args) + ['config', '--format', 'json']))
    require(isinstance(value, dict) and isinstance(value.get('services'), dict))
    require(args.php_service != args.nginx_service and args.php_service in value['services']
            and args.nginx_service in value['services'])
    return value


def target_inputs(args, compose):
    service = compose['services'][args.php_service]
    require(not any(service.get(key) for key in ('network_mode', 'configs', 'secrets', 'extra_hosts', 'dns', 'dns_search', 'dns_opt')))
    mounts = service.get('volumes', [])
    require(isinstance(mounts, list) and all(isinstance(item, dict) for item in mounts))
    candidates = [item for item in mounts if item.get('target') in
                  ('/run/application', '/run/application/.env', '/var/www/.env')]
    require(len(candidates) == 1)
    mount = candidates[0]
    # Application code/manifest must remain image-backed. Only known data paths
    # and the one selected configuration mount may overlap these two roots.
    code_root, config_root = PurePosixPath('/var/www'), PurePosixPath('/run/application')
    data_roots = [PurePosixPath('/var/www/runtime'), PurePosixPath('/var/www/public/uploads')]
    for item in mounts:
        name = item.get('target')
        require(isinstance(name, str) and name.startswith('/') and '\\' not in name
                and '..' not in name.split('/') and PurePosixPath(name).as_posix() == name)
        target = PurePosixPath(name)
        if item is mount:
            continue
        require(not (target.is_relative_to(config_root) or config_root.is_relative_to(target)))
        if target.is_relative_to(code_root) or code_root.is_relative_to(target):
            require(target == PurePosixPath('/var/www/.constant')
                    or any(target.is_relative_to(root) for root in data_roots))
    source, kind = mount.get('source'), mount.get('type')
    require(kind in ('bind', 'volume') and isinstance(source, str) and source)
    if kind == 'volume':
        definition = compose.get('volumes', {}).get(source)
        require(isinstance(definition, dict) and isinstance(definition.get('name'), str))
        source = definition['name']
        existing = decode(invoke([args.docker, 'volume', 'inspect', source]))
        require(len(existing) == 1 and existing[0].get('Name') == source)
        require(not mount.get('volume', {}).get('subpath'))
    else:
        require(Path(source).is_absolute())
        require(Path(source).is_dir() if mount['target'] == '/run/application' else Path(source).is_file())
    require(',' not in source and '\n' not in source and '\r' not in source)
    destination = '/run/application' if mount['target'] == '/run/application' else '/run/application/.env'
    config_mount = f'type={kind},source={source},target={destination},readonly'
    if kind == 'volume':
        config_mount += ',volume-nocopy'
    selected = service.get('networks')
    require(isinstance(selected, dict) and selected)
    networks = []
    for key in selected:
        definition = compose.get('networks', {}).get(key)
        require(isinstance(definition, dict) and isinstance(definition.get('name'), str))
        name = definition['name']
        rows = decode(invoke([args.docker, 'network', 'inspect', name]))
        require(len(rows) == 1 and rows[0].get('Name') == name and CONTAINER_ID.fullmatch(rows[0].get('Id', '')))
        networks.append(rows[0]['Id'])
    require(len(set(networks)) == len(networks))
    environment = service.get('environment', {})
    require(isinstance(environment, dict) and all(isinstance(key, str) and (value is None or isinstance(value, str))
                                               for key, value in environment.items()))
    return config_mount, networks, environment


def effective_environment(image, service_environment):
    values = {}
    for entry in image.get('Config', {}).get('Env', []) or []:
        require(isinstance(entry, str) and '=' in entry)
        key, value = entry.split('=', 1)
        values[key] = value
    for key, value in service_environment.items():
        if value is None:
            values.pop(key, None)
        else:
            values[key] = value
    # Think Env loads DATABASE_* from $_ENV and falls back to PHP_DATABASE_*.
    # Candidate PATH/LD_*/PHPRC/PHP_INI_SCAN_DIR never enter checker execution.
    return {key: value for key, value in values.items() if key in ENV_KEYS}


def manifest_from_image(docker, image_id):
    container = None
    ownership = 'manifest-' + uuid.uuid4().hex
    try:
        raw = invoke([docker, 'create', '--pull=never', '--network=none', '--name', 'marketplace-' + ownership,
                      '--label', 'marketplace.pre-up.owner=' + ownership,
                      '--entrypoint', '/bin/true', image_id], limit=1024)
        container = raw.decode().strip()
        require(CONTAINER_ID.fullmatch(container))
        archive = invoke([docker, 'cp', container + ':/var/www/marketplace-release.json', '-'], limit=65536)
        with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as tar:
            members = tar.getmembers()
            require(len(members) == 1)
            member = members[0]
            require(member.name == 'marketplace-release.json' and member.isreg() and not member.linkname
                    and 1 <= member.size <= 4096)
            stream = tar.extractfile(member)
            require(stream is not None)
            body = stream.read(4097)
            require(len(body) == member.size)
            return body
    finally:
        if container is not None and CONTAINER_ID.fullmatch(container):
            cleanup_container(docker, container, image_id, ownership)


def checked_result(raw):
    result = decode(raw)
    require(isinstance(result, dict) and set(result) == {'status', 'manifest', 'schema', 'database', 'prefix', 'config_sha256', 'source_sha256'})
    require(result['status'] == 'COMPATIBLE' and isinstance(result['manifest'], dict) and isinstance(result['schema'], dict))
    require(set(result['manifest']) == {'product', 'app_version', 'schema_min', 'schema_max'}
            and result['manifest']['product'] == 'generic-multi-merchant')
    require(set(result['schema']) == {'id', 'schema_version', 'min_app_version', 'updated_at'})
    for key in ('database', 'prefix'):
        require(isinstance(result[key], str) and re.fullmatch(r'[A-Za-z0-9_]{0,64}', result[key]) is not None)
    require(isinstance(result['config_sha256'], str) and re.fullmatch(r'[0-9a-f]{64}', result['config_sha256']) is not None)
    require(isinstance(result['source_sha256'], dict) and set(result['source_sha256']) == {'SchemaVersionGate', 'SchemaVersionRuntime'}
            and all(isinstance(value, str) and re.fullmatch(r'[0-9a-f]{64}', value) for value in result['source_sha256'].values()))
    return result


def check_target(args, manifest, inputs, environment):
    config_mount, networks, _ = inputs
    helper = Path(__file__).resolve().with_name('marketplace-pre-up.php')
    require(helper.is_file() and ',' not in str(helper))
    child_environment = os.environ.copy()
    # Docker -e KEY reads only these explicitly selected values; no values go in argv.
    child_environment.update(environment)
    child_environment['MARKETPLACE_PRE_UP_ENV_KEYS'] = json.dumps(sorted(environment))
    ownership = 'check-' + uuid.uuid4().hex
    command = [args.docker, 'create', '--pull=never', '--name', 'marketplace-pre-up-' + ownership,
               '--label', 'marketplace.pre-up.owner=' + ownership,
               '--interactive', '--user', '33:33', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges:true',
               '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=8m,mode=1777', '--network', networks[0],
               '--mount', config_mount, '--mount', f'type=bind,source={helper},target=/opt/marketplace-pre-up.php,readonly',
               '--entrypoint', '/usr/local/bin/php', '--workdir', '/var/www']
    for key in sorted(environment) + ['MARKETPLACE_PRE_UP_ENV_KEYS']:
        command += ['-e', key]
    command += [args.checker_image, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'open_basedir=', '-d', 'opcache.enable_cli=0',
                '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=0', '/opt/marketplace-pre-up.php']
    container = None
    try:
        container = invoke(command, environment=child_environment, limit=1024).decode().strip()
        require(CONTAINER_ID.fullmatch(container))
        for network in networks[1:]:
            invoke([args.docker, 'network', 'connect', network, container], limit=1024)
        return checked_result(invoke([args.docker, 'start', '--attach', '--interactive', container], data=manifest, limit=16384))
    finally:
        if container is not None and CONTAINER_ID.fullmatch(container):
            # No --force: a still-running helper is retained for explicit investigation.
            cleanup_container(args.docker, container, args.checker_image, ownership)


def wait_nginx_healthy(args, command, project, image_id, timeout=180):
    """Observe the refreshed container; do not restart or recreate on failure."""
    deadline = time.monotonic() + timeout
    raw = invoke(command + ['ps', '--all', '--quiet', args.nginx_service],
                 limit=1024, timeout=min(30, timeout)).decode().strip()
    require(CONTAINER_ID.fullmatch(raw), 'SCHEMA_UNAVAILABLE')
    while True:
        remaining = deadline - time.monotonic()
        require(remaining > 0, 'SCHEMA_UNAVAILABLE')
        rows = decode(invoke([args.docker, 'container', 'inspect', raw],
                             timeout=min(30, remaining)))
        require(isinstance(rows, list) and len(rows) == 1, 'SCHEMA_UNAVAILABLE')
        container = rows[0]
        labels = container.get('Config', {}).get('Labels', {})
        state = container.get('State', {})
        require(container.get('Id') == raw and container.get('Image') == image_id
                and labels.get('com.docker.compose.project') == project
                and labels.get('com.docker.compose.service') == args.nginx_service
                and state.get('Running') is True and state.get('Paused') is False
                and state.get('Restarting') is False, 'SCHEMA_UNAVAILABLE')
        health = state.get('Health', {}).get('Status')
        if health == 'healthy':
            return
        require(health == 'starting', 'SCHEMA_UNAVAILABLE')
        time.sleep(min(0.5, max(0, deadline - time.monotonic())))


def supervisor_program_states(raw, required):
    """Read only the named programs; never expose raw status or error text."""
    states = {}
    for line in raw.decode('ascii').splitlines():
        if not line.strip():
            continue
        match = re.fullmatch(r'([a-z-]+)\s+(RUNNING|STARTING)(?:\s+(.*))?', line.strip())
        require(match is not None, 'RUNTIME_NOT_READY')
        name, state, detail = match.groups()
        require(name in required and name not in states, 'RUNTIME_NOT_READY')
        if state == 'RUNNING':
            require(detail is not None and re.fullmatch(r'pid [1-9][0-9]{0,9}, uptime .+', detail) is not None,
                    'RUNTIME_NOT_READY')
        else:
            require(not detail, 'RUNTIME_NOT_READY')
        states[name] = state
    require(set(states) == set(required), 'RUNTIME_NOT_READY')
    return states


def wait_runtime_processes(args, command, project, image_id, timeout=180):
    """Observe this PHP container only; do not start, restart, or repair programs."""
    try:
        deadline = time.monotonic() + timeout
        container_id = invoke(command + ['ps', '--all', '--quiet', args.php_service],
                              limit=1024, timeout=min(30, timeout)).decode().strip()
        require(CONTAINER_ID.fullmatch(container_id), 'RUNTIME_NOT_READY')
        while True:
            remaining = deadline - time.monotonic()
            require(remaining > 0, 'RUNTIME_NOT_READY')
            rows = decode(invoke([args.docker, 'container', 'inspect', container_id],
                                 timeout=min(30, remaining)))
            require(isinstance(rows, list) and len(rows) == 1, 'RUNTIME_NOT_READY')
            container = rows[0]
            config, state = container.get('Config', {}), container.get('State', {})
            labels = config.get('Labels', {})
            require(container.get('Id') == container_id and container.get('Image') == image_id
                    and labels.get('com.docker.compose.project') == project
                    and labels.get('com.docker.compose.service') == args.php_service
                    and state.get('Running') is True and state.get('Paused') is False
                    and state.get('Restarting') is False, 'RUNTIME_NOT_READY')
            environment = config.get('Env') or []
            require(isinstance(environment, list) and all(isinstance(entry, str) for entry in environment),
                    'RUNTIME_NOT_READY')
            flags = [entry.split('=', 1)[1] for entry in environment
                     if entry.startswith('MARKETPLACE_QUEUE_AUTOSTART=')]
            require(len(flags) <= 1 and (not flags or flags[0] in ('', 'true', 'false')), 'RUNTIME_NOT_READY')
            queue_required = bool(flags and flags[0] == 'true')
            required = ['php-fpm', 'queue', 'timer', 'workerman']
            if queue_required:
                required.append('marketplace-queue')
            health = state.get('Health', {}).get('Status')
            require(health in ('starting', 'healthy'), 'RUNTIME_NOT_READY')
            if health == 'healthy':
                # The operator already controls Docker. Keep the root-only socket
                # and www-data health check unchanged; this command only reads status.
                remaining = deadline - time.monotonic()
                require(remaining > 0, 'RUNTIME_NOT_READY')
                raw = invoke([args.docker, 'exec', '--user', '0', container_id,
                              '/usr/bin/supervisorctl', '-c', '/etc/supervisor/supervisord.conf',
                              'status', *required], limit=4096, timeout=min(30, remaining))
                states = supervisor_program_states(raw, required)
                if all(value == 'RUNNING' for value in states.values()):
                    return {'status': 'READY', 'required_programs': states,
                            'marketplace_queue_required': queue_required}
            time.sleep(min(0.5, max(0, deadline - time.monotonic())))
    except Exception:
        raise Rejected('RUNTIME_NOT_READY') from None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compose-file', type=Path, action='append', required=True)
    parser.add_argument('--env-file', type=Path, required=True)
    parser.add_argument('--project-name')
    parser.add_argument('--php-service', default='phpfpm')
    parser.add_argument('--nginx-service', default='nginx')
    parser.add_argument('--checker-image', required=True, help='operator-approved immutable sha256 image ID')
    parser.add_argument('--docker', default=shutil.which('docker') or 'docker')
    parser.add_argument('--apply', action='store_true',
                        help='run up, refresh Nginx and require supervised programs ready; default is schema check only')
    args = parser.parse_args()
    if sys.version_info < ((3, 12) if sys.platform == 'win32' else (3, 10)):
        print(json.dumps({'status': 'REJECTED', 'reason': 'PYTHON_VERSION_UNSUPPORTED',
                          'requires': 'Windows Python 3.12+; other platforms Python 3.10+'}))
        return 2
    apply_started = False
    try:
        require(IMAGE_ID.fullmatch(args.checker_image))
        require(all(SAFE_NAME.fullmatch(name) for name in [args.php_service, args.nginx_service]))
        require(args.project_name is None or SAFE_NAME.fullmatch(args.project_name))
        args.compose_file = [path.resolve(strict=True) for path in args.compose_file]
        args.env_file = args.env_file.resolve(strict=True)
        require(args.env_file.is_file() and all(path.is_file() for path in args.compose_file))
        checker = inspect_image(args.docker, args.checker_image)
        require(checker['Id'] == args.checker_image)
        compose = read_compose(args)
        php = inspect_image(args.docker, compose['services'][args.php_service].get('image'))
        nginx = inspect_image(args.docker, compose['services'][args.nginx_service].get('image'))
        inputs = target_inputs(args, compose)
        environment = effective_environment(php, inputs[2])
        manifest = manifest_from_image(args.docker, php['Id'])
        result = check_target(args, manifest, inputs, environment)
        mode = 'CHECKED'
        runtime = None
        if args.apply:
            repeated = read_compose(args)
            require(digest(repeated) == digest(compose))
            repeated_inputs = target_inputs(args, repeated)
            require(repeated_inputs == inputs)
            require(check_target(args, manifest, repeated_inputs, environment) == result)
            # The override contains only pinned IDs, never expanded Compose secrets.
            with tempfile.TemporaryDirectory(prefix='marketplace-deploy-') as folder:
                override = Path(folder) / 'images.json'
                override.write_text(json.dumps({'services': {args.php_service: {'image': php['Id']},
                                                            args.nginx_service: {'image': nginx['Id']}}}), encoding='utf-8')
                command = compose_command(args) + ['--file', str(override)]
                apply_started = True
                invoke(command + ['up', '--no-build', '--pull', 'never', '-d', '--wait'])
                invoke(command + ['restart', args.nginx_service])
                wait_nginx_healthy(args, command, compose['name'], nginx['Id'])
                runtime = wait_runtime_processes(args, command, compose['name'], php['Id'])
                invoke(command + ['ps'])
            mode = 'APPLIED'
        receipt = {**result, 'status': mode, 'php_image': php['Id'], 'nginx_image': nginx['Id'],
                   'checker_image': args.checker_image, 'compose_sha256': digest(compose)}
        if runtime is not None:
            receipt['runtime'] = runtime
        print(json.dumps(receipt, ensure_ascii=False, separators=(',', ':')))
        return 0
    except Exception as error:
        reason = error.reason if isinstance(error, Rejected) else 'SCHEMA_UNAVAILABLE'
        print(json.dumps({'status': 'APPLY_FAILED' if apply_started else 'REJECTED', 'reason': reason,
                          'changes_may_have_occurred': apply_started}, separators=(',', ':')))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
