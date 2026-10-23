"""Collect one normal paid-failure log projection; raw streams remain in RAM."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

DOCKER = 'C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
HTTP_FIELDS = {'kind', 'method', 'http', 'json_error', 'response_bytes', 'response_sha256'}
LOCATION_FIELDS = {'scope', 'class', 'file', 'line', 'phase'}
HTTP_KINDS = {'login', 'user', 'computed', 'upload', 'ticket', 'content', 'view'}
HTTP_FIELD = 'normal_http_response_diagnostic'


class CollectionFailure(Exception):
    pass


def require(condition, code):
    if not condition:
        raise CollectionFailure(code)


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def pairs(items):
    result = {}
    for key, value in items:
        require(key not in result, 'JSON_DUPLICATE_KEY')
        result[key] = value
    return result


def json_value(raw):
    def constant(_value):
        raise CollectionFailure('JSON_NONFINITE_NUMBER')
    return json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_constant=constant)


def stamp(value):
    return [int(getattr(value, name)) for name in ['st_dev', 'st_ino', 'st_mode', 'st_nlink',
            'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']]


def ordinary(path, directory=False):
    value = path.lstat()
    require(not path.is_symlink() and not (getattr(value, 'st_file_attributes', 0) & 0x400)
            and (stat.S_ISDIR(value.st_mode) if directory else stat.S_ISREG(value.st_mode)),
            'ORDINARY_PUBLIC_PATH_REQUIRED')


def read_public(path):
    for parent in reversed(path.parents):
        ordinary(parent, True)
    ordinary(path)
    before = stamp(path.stat())
    with path.open('rb') as handle:
        opened = stamp(os.fstat(handle.fileno()))
        raw = handle.read()
        require(handle.read(1) == b'', 'PUBLIC_INPUT_EOF_REQUIRED')
        ended = stamp(os.fstat(handle.fileno()))
    after = stamp(path.stat())
    require(before == after and opened == ended and len(raw) == opened[6], 'PUBLIC_INPUT_CHANGED')
    return raw, {'named_before': before, 'named_after': after, 'fd_open': opened,
                 'fd_after': ended, 'EOF': True, 'closed': True, **pin(raw)}


def http_diagnostic(value):
    if value is None:
        return None
    require(type(value) is dict and set(value) == HTTP_FIELDS, 'HTTP_DIAGNOSTIC_FIELDS')
    require(type(value['kind']) is str and value['kind'] in HTTP_KINDS
            and type(value['method']) is str and value['method'] in {'GET', 'POST'}
            and type(value['http']) is int and 0 <= value['http'] <= 599
            and (value['json_error'] is None or (type(value['json_error']) is int and 0 <= value['json_error'] <= 11))
            and type(value['response_bytes']) is int and 0 <= value['response_bytes'] <= 52428800
            and type(value['response_sha256']) is str and re.fullmatch('[a-f0-9]{64}', value['response_sha256']) is not None,
            'HTTP_DIAGNOSTIC_VALUES')
    return {name: value[name] for name in ['kind', 'method', 'http', 'json_error', 'response_bytes', 'response_sha256']}


def throwable_location(value):
    require(type(value) is dict and set(value) in [LOCATION_FIELDS, LOCATION_FIELDS | {HTTP_FIELD}],
            'THROWABLE_PROJECTION_FIELDS')
    require(value['scope'] == 'MS5_PAID_PRODUCER_THROWABLE_DIAGNOSTIC'
            and (value['class'] is None or (type(value['class']) is str
                 and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,191}', value['class'].replace('\\', '_')) is not None))
            and (value['file'] is None or (type(value['file']) is str
                 and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,95}', value['file']) is not None))
            and (value['line'] is None or (type(value['line']) is int and value['line'] > 0))
            and (value['phase'] is None or (type(value['phase']) is str
                 and re.fullmatch(r'[a-z][a-z0-9_]{0,95}', value['phase']) is not None)),
            'THROWABLE_PROJECTION_VALUES')
    return {name: value[name] for name in ['scope', 'class', 'file', 'line', 'phase']}


def validate_projections(stdout, stderr, paid_result):
    require(type(stdout) is dict and set(stdout) == {'status', 'failed_assertion', 'native_exit_observation'},
            'STDOUT_PROJECTION_FIELDS')
    require(stdout == {'status': 'INCOMPLETE_MS5_PAID_PRODUCER_INTERNAL_ONLY',
            'failed_assertion': paid_result['failed_assertion'], 'native_exit_observation': 'HOST_NATIVE_REQUIRED'},
            'STDOUT_PAID_RESULT_CODE_MISMATCH')
    location = throwable_location(stderr)
    require(location['phase'] is None or location['phase'] == paid_result['phase'], 'THROWABLE_PAID_RESULT_PHASE_MISMATCH')
    present = HTTP_FIELD in stderr
    require(present == (HTTP_FIELD in paid_result), 'HTTP_DIAGNOSTIC_PRESENCE_MISMATCH')
    diagnostic = http_diagnostic(stderr[HTTP_FIELD]) if present else None
    if present:
        require(diagnostic == http_diagnostic(paid_result[HTTP_FIELD]), 'HTTP_DIAGNOSTIC_PAID_RESULT_MISMATCH')
    return {'first_throwable_location': location, 'normal_http_response_diagnostic_present': present,
            'normal_http_response_diagnostic': diagnostic,
            'safe_records': [{'stream': 'stdout', 'projection': {name: stdout[name] for name in
                ['status', 'failed_assertion', 'native_exit_observation']}}, {'stream': 'stderr', 'projection': location}]}


def single_projection(raw):
    require(len(raw) <= 65536, 'LOG_PROJECTION_SIZE_LIMIT')
    lines = [line for line in raw.splitlines() if line != b'']
    require(len(lines) == 1, 'LOG_PROJECTION_NOT_UNIQUE')
    return json_value(lines[0])


def save(path, value):
    raw = (json.dumps(value, ensure_ascii=True, indent=2, allow_nan=False) + '\n').encode('utf-8')
    with path.open('xb') as handle:
        require(handle.write(raw) == len(raw), 'OUTPUT_WRITE_INCOMPLETE')
    return {'file': path.name, **pin(raw)}


def main():
    out = None
    made_pin = ended_pin = None
    phase = 'CLI_ARGUMENTS'
    try:
        parser = argparse.ArgumentParser(add_help=False, exit_on_error=False)
        def parser_error(_message):
            raise CollectionFailure('CLI_ARGUMENTS')
        parser.error = parser_error
        for flag in ['--context', '--expected-source', '--expected-freeze', '--out']:
            parser.add_argument(flag, required=True)
        args = parser.parse_args()
        require(re.fullmatch('[a-f0-9]{40}', args.expected_source) is not None
                and re.fullmatch('[a-f0-9]{64}', args.expected_freeze) is not None, 'EXPECTED_SOURCE_FREEZE_FORMAT')
        context_path = Path(args.context)
        requested_out = Path(args.out)
        require(context_path.is_absolute() and requested_out.is_absolute()
                and '..' not in context_path.parts and '..' not in requested_out.parts
                and context_path.name == 'actual-run-context.json', 'EXACT_PUBLIC_CONTEXT_PATH')
        run = context_path.parent
        ordinary(run, True)
        require(not requested_out.exists() and requested_out != run
                and not requested_out.is_relative_to(run) and not run.is_relative_to(requested_out), 'FRESH_OUTPUT_OUTSIDE_RUN')
        for parent in reversed(requested_out.parents):
            ordinary(parent, True)
        paths = {'context': context_path, 'manifest': run / 'manifest.json',
                 'paid_created': run / 'evidence/paid-producer-created.json',
                 'paid_terminal': run / 'evidence/paid-producer-terminal.json',
                 'paid_result': run / 'evidence/paid-producer-result.json', 'collector_source': Path(__file__).absolute()}
        phase = 'PUBLIC_INPUT_VALIDATION'
        raw_inputs, input_rows, objects = {}, {}, {}
        for role, path in paths.items():
            raw_inputs[role], input_rows[role] = read_public(path)
            if role != 'collector_source':
                objects[role] = json_value(raw_inputs[role])
                require(type(objects[role]) is dict, 'PUBLIC_INPUT_OBJECT_REQUIRED')
        context, manifest, created, terminal, result = [objects[name] for name in
            ['context', 'manifest', 'paid_created', 'paid_terminal', 'paid_result']]
        run_id = context['run_id']
        require(type(run_id) is str and re.fullmatch('[0-9]{14}[a-f0-9]{6}', run_id) is not None
                and run.name == run_id and Path(context['run_dir']) == run
                and context['schema'] == 1 and context['source_sha'] == args.expected_source
                and context['freeze_sha256'] == args.expected_freeze, 'CONTEXT_EXPECTED_RUN_SOURCE_FREEZE')
        project = 'tianli-audit-' + run_id
        require(context['project'] == manifest['project'] == project and manifest['run_id'] == run_id
                and manifest['commit_sha'] == args.expected_source, 'MANIFEST_SAME_RUN_SOURCE')
        cid = manifest['services']['audit-ms5-paid-producer']['id']
        require(type(cid) is str and re.fullmatch('[a-f0-9]{64}', cid) is not None
                and created['id'] == terminal['id'] == cid
                and created['run_id'] == run_id and created['source_sha'] == args.expected_source
                and created['proof_name'] == 'paid-producer', 'SAME_REGISTERED_PAID_CONTAINER')
        require(created['status'] == 'created' and type(created['pid']) is int and created['pid'] == 0
                and type(created['exit_code']) is int and created['exit_code'] == 0
                and type(created['restart_count']) is int and created['restart_count'] == 0
                and terminal['status'] == 'exited' and type(terminal['pid']) is int and terminal['pid'] == 0
                and type(terminal['exit_code']) is int and terminal['exit_code'] == 1
                and type(terminal['restart_count']) is int and terminal['restart_count'] == 0,
                'ACTUAL_CREATED_AND_EXITED_ONE_PID_ZERO')
        require(created['labels'] == terminal['labels'] and created['image'] == terminal['image']
                and created['created_at'] == terminal['created_at']
                and created['labels']['com.docker.compose.project'] == project
                and created['labels']['com.docker.compose.service'] == 'audit-ms5-paid-producer'
                and created['labels']['tianli.audit.run'] == run_id, 'ORIGINAL_PAID_IDENTITY_LABELS')
        require(result['schema'] == 1 and result['scope'] == 'MS5_PAID_PRODUCER_INTERNAL_ONLY'
                and result['status'] == 'INCOMPLETE_MS5_PAID_PRODUCER_INTERNAL_ONLY'
                and result['run_id'] == run_id and result['source_sha'] == args.expected_source
                and result['freeze_sha256'] == args.expected_freeze and result['container_id'] == cid
                and result['native_created_sha256'] == pin(raw_inputs['paid_created'])['sha256']
                and type(result['entry_return_code']) is int and result['entry_return_code'] == 1
                and result['termination'] == 'EXCEPTION' and result['native_exit'] is None
                and result['native_exit_observation'] == 'HOST_NATIVE_REQUIRED'
                and result['full_acceptance'] == 'INCOMPLETE' and result['stopped_samples'] == 'NOT RUN'
                and all(result[field] == 'NOT RUN' for field in
                    ['customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM']),
                'PAID_RESULT_SAME_FAILURE_SOURCE_FREEZE')
        require(type(result['failed_assertion']) is str
                and re.fullmatch('[A-Z0-9_]{1,192}', result['failed_assertion']) is not None
                and type(result['phase']) is str and re.fullmatch('[a-z][a-z0-9_]{0,95}', result['phase']) is not None,
                'PAID_RESULT_FIXED_CODE_AND_PHASE')
        requested_out.mkdir()
        out = requested_out
        phase = 'ONE_NATIVE_DOCKER_LOGS'
        argv = [DOCKER, 'logs', cid]
        started = time.time_ns()
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        made = {'pid': process.pid, 'argv': argv, 'started_ns': started}
        made_pin = save(out / 'created.json', made)
        stdout, stderr = process.communicate()
        waited = process.wait()
        ended = {**made, 'native_exit': process.returncode, 'wait_returncode': waited, 'finished_ns': time.time_ns(),
                 'stdout_bytes': len(stdout), 'stdout_sha256': pin(stdout)['sha256'],
                 'stderr_bytes': len(stderr), 'stderr_sha256': pin(stderr)['sha256'], 'raw_streams_retained': False}
        ended_pin = save(out / 'terminal.json', ended)
        require(process.returncode == waited == 0, 'DOCKER_LOGS_NATIVE_NOT_ZERO')
        phase = 'SAFE_PROJECTION_VALIDATION'
        projection = validate_projections(single_projection(stdout), single_projection(stderr), result)
        del stdout, stderr
        phase = 'PUBLIC_INPUT_FINAL_READBACK'
        for role, path in paths.items():
            final_raw, final_row = read_public(path)
            require(final_raw == raw_inputs[role] and final_row['named_after'] == input_rows[role]['named_after'],
                    'ORIGINAL_PUBLIC_INPUT_CHANGED')
            input_rows[role]['final_full_read'] = final_row
        report = {'schema': 2, 'status': 'READONLY_ACTUAL_PAID_FAILURE_SAFE_LOG_PROJECTION_ONLY',
                  'run_id': run_id, 'source_sha': args.expected_source, 'freeze_sha256': args.expected_freeze,
                  'container_id': cid, 'native_exit': process.returncode, 'wait_returncode': waited,
                  'business_terminal_exit': 1, 'business_acceptance': 'INCOMPLETE',
                  'collection_zero_is_not_business_acceptance': True, 'raw_streams_retained': False,
                  'unprojected_line_count': 0, 'business_result_file_exists': True,
                  **projection, 'actual_native_created': made_pin, 'actual_native_terminal': ended_pin,
                  'public_input_pins': input_rows, 'all_public_input_windows_ended': True}
        save(out / 'REPORT.json', report)
        print(json.dumps({'status': report['status'], 'business_acceptance': 'INCOMPLETE', 'raw_streams_retained': False}))
        return 0
    except BaseException as error:
        code = error.args[0] if isinstance(error, CollectionFailure) and len(error.args) == 1 else 'COLLECTOR_INTERNAL_FAILURE'
        if type(code) is not str or re.fullmatch('[A-Z][A-Z0-9_]{0,95}', code) is None:
            code = 'COLLECTOR_INTERNAL_FAILURE'
        failure = {'schema': 2, 'status': 'PAID_FAILURE_COLLECTION_FAILED', 'code': code, 'phase': phase,
                   'raw_streams_retained': False, 'business_acceptance': 'INCOMPLETE',
                   'actual_native_created': made_pin, 'actual_native_terminal': ended_pin}
        if out is not None:
            try:
                save(out / 'REPORT.json', failure)
            except BaseException:
                pass
        print(json.dumps({'status': 'PAID_FAILURE_COLLECTION_FAILED', 'code': code, 'business_acceptance': 'INCOMPLETE'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
