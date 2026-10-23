"""Unexecuted complete independent H5 coordinator Source.

The original Host performs every finite phase; the original nine-scene browser
driver/private bridge supplies the actual browser proof. READY_NOT_GUI is a
handoff only. The original H5 close and independent zero remain the finish gate.
No actor, cookie, private payload, browser locator or successful receipt belongs
in this Source. The default public A inputs are explicitly UNBOUND.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent
PROFILE = ROOT / 'candidate'
HEAD = '175f3cd6183894185656a2974579e1be39eed7a7'
PARENT = '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
EMPTY = hashlib.sha256(b'').hexdigest()
ROOT_INITIALIZER_LEAF = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/h5-current175f-complete-source-r1/candidate/ms4-initialize.php', 'bytes': 78955, 'sha256': 'a5add955d43e4fd8cfdc50dd0ad06d5f6408e5cf743a5c2564b6e51af7db067b'}

def need(value, code):
    if not value:
        raise RuntimeError(code)


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def fixed(p, row):
    p = Path(p)
    need(p.is_absolute() and p.is_file() and not p.is_symlink(), 'EXPLICIT_REGULAR_SOURCE_OR_PUBLIC_INPUT')
    need(type(row) is dict and type(row.get('bytes')) is int and row['bytes'] >= 0 and type(row.get('sha256')) is str, 'TYPED_PIN')
    raw = p.read_bytes()
    need(pin(raw) == {k: row[k] for k in ('bytes', 'sha256')}, 'EXACT_INPUT_BYTES')
    return raw


def deref(row):
    need(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'}, 'COMPLETE_NONEMPTY_ARTIFACT_POINTER')
    return fixed(row['path'], row)


def receipt_outcome(value):
    need(type(value) is dict and set(value) == {'created', 'terminal', 'stdout', 'stderr', 'created_object', 'terminal_object'}, 'EXACT_OUTCOME_SIX')
    made = json.loads(deref(value['created']))
    ended = json.loads(deref(value['terminal']))
    need(made == value['created_object'] and ended == value['terminal_object'], 'REAL_OUTCOME_OBJECTS')
    need(set(made) == {'pid', 'argv', 'started_ns'}, 'ACTUAL_CREATED_THREE')
    need(set(ended) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}, 'ACTUAL_TERMINAL_SIX')
    need(type(made['pid']) is int and made['pid'] > 0 and made['pid'] == ended['pid'], 'ACTUAL_SAME_PID')
    need(type(ended['native_exit']) is int and type(ended['wait_returncode']) is int and ended['native_exit'] == ended['wait_returncode'] == 0, 'ACTUAL_OUTER_ZERO')
    need(0 < made['started_ns'] < ended['finished_ns'] and type(made['argv']) is list, 'ACTUAL_NATIVE_TIME_ARGV')
    for stream in ('stdout', 'stderr'):
        need(ended[stream] == value[stream], 'ACTUAL_NESTED_STREAM_POINTER')
        raw = deref(value[stream])
        if stream == 'stderr':
            need(raw == b'', 'ACTUAL_EMPTY_STDERR')
    return made, ended


def validate_success(inputs, mvars, apins):
    required = {'schema', 'source_sha', 'M_result', 'M_outcome', 'M_root_readback', 'A_outcome', 'A_outer_session',
                'A_qualification', 'A_context', 'post_A_history_contract', 'Source_review_authority', 'initializer_leaf'}
    need(type(inputs) is dict and set(inputs) == required and inputs['schema'] == 1 and inputs['source_sha'] == HEAD, 'EXACT_CURRENT175F_ORIGINAL_SUCCESS_INPUT_SCHEMA')
    need(all(inputs[k] is not None for k in required), 'WAIT_REAL_SAME_SOURCE_A_INPUTS_NO_PLACEHOLDERS')
    need(inputs['initializer_leaf'] == ROOT_INITIALIZER_LEAF, 'EXACT_ROOT_ALREADY_QUALIFIED_INITIALIZER_LEAF')
    m = json.loads(deref(inputs['M_result']))
    need(set(m) == set(mvars['CURRENT_M_RESULT_KEYS35']) and len(m) == 35, 'EXACT_CURRENT_M35')
    need(m['source_sha'] == HEAD and m['parent'] == PARENT and m['Run_created'] is False and m['App_DB_HTTP_business'] == 'NOT RUN', 'SAME_HEAD_M_ONLY')
    need(m['commit_chain'] == mvars['COMMIT_CHAIN'] and len(m['commit_chain']) == 19, 'FULL_NINETEEN_SOURCE_CHAIN')
    need([r['path'] for r in m['committed_files']] == mvars['COMMITTED_PATHS'], 'ALL307_COMMITTED_SOURCE_ROWS')
    derived = 6 + sum(2 + 2 * len(s['files']) for s in mvars['COMMIT_CHAIN']) + 1 + len(mvars['COMMITTED_PATHS']) + 2 + 3 + 1 + 4
    need(derived == len(m['native_operations']) == 1088, 'DERIVED_FULL_A_M_LEDGER_NOT_B_BUDGET')
    receipt_outcome(inputs['M_outcome'])
    receipt_outcome(inputs['A_outcome'])
    deref(inputs['M_root_readback'])
    deref(inputs['Source_review_authority'])
    aq = inputs['A_qualification']
    need(type(aq) is dict and set(aq) == {'outer_native_exit', 'normal_root_result', 'worker_close', 'independent_zero', 'post_a_history'}, 'REAL_A_SUCCESS_FIVE')
    need(type(aq['outer_native_exit']) is int and aq['outer_native_exit'] == 0, 'A_OUTER_SUCCESS_REQUIRED')
    need(type(inputs['A_outer_session']) is int and inputs['A_outer_session'] > 0, 'REAL_A_ORIGINAL_HANDLE_REQUIRED')
    result = json.loads(deref(aq['normal_root_result']))
    context = json.loads(deref(inputs['A_context']))
    need(result == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
        'run_id': context['run_id'], 'source_sha': HEAD, 'context': inputs['A_context']['path'],
        'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE',
        'DOM_nonempty_history_due_finance_release': 'NOT RUN'}, 'ORIGINAL_FULL_NORMAL_A_RESULT_NOT_PARTIAL_SUCCESS')
    need(context['source_sha'] == HEAD and context['freeze_sha256'] == m['freeze_sha256'], 'ACTUAL_SAME_A_CONTEXT_FREEZE')
    closed = json.loads(deref(aq['worker_close']))
    zero = json.loads(deref(aq['independent_zero']))
    need(closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL' and zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO', 'ORIGINAL_SUCCESSFUL_SUFFIX_ALREADY_CLOSED')
    for value in (closed, zero):
        need(value['run_id'] == context['run_id'] and value['source_sha'] == HEAD and value['freeze_sha256'] == m['freeze_sha256'] and value['native_exit'] == 0, 'SAME_A_ACTUAL_CLOSE_ZERO_IDENTITY')
    need(zero['close_result_sha256'] == aq['worker_close']['sha256'], 'EXACT_ORIGINAL_CLOSE_ZERO_JOIN')
    need([(r['kind'], r['label']) for r in zero['queries']] == [(k, l) for k in ['container', 'network', 'volume'] for l in ['project', 'run']], 'SIX_ORIGINAL_FRESH_ZERO_QUERIES')
    need(all(r['native_exit'] == 0 and r['stdout_bytes'] == r['stderr_bytes'] == 0 and r['stdout_sha256'] == r['stderr_sha256'] == EMPTY for r in zero['queries']), 'SIX_ORIGINAL_FULL_EMPTY_STREAMS')
    history = json.loads(deref(aq['post_a_history']))
    hc = inputs['post_A_history_contract']
    need(type(hc) is dict and set(hc) == {'header', 'files_key', 'retained_public_files'}, 'ACTUAL_POST_A_CLOSED_SCHEMA')
    need(set(history) == set(hc['header']) | {hc['files_key']} and {k: history[k] for k in hc['header']} == hc['header'], 'ACTUAL_POST_A_HEADER')
    rows = history[hc['files_key']]
    need(type(rows) is list and [r['path'] for r in rows] == sorted({r['path'] for r in rows}), 'COMPLETE_POST_A_PUBLIC_NAMESPACE')
    # Actual target consumers retain their full native/Source/history proof; this
    # author does not execute them, replay them or assert a future B result.
    need(apins['source_sha'] == HEAD, 'EXACT_FINAL_ELIGIBLE_A_SOURCE')
    return m, context


def save_new(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    body = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    with path.open('xb') as output:
        need(output.write(body) == len(body), 'COMPLETE_NEW_COORDINATOR_RECORD')
    return {'path': path.as_posix(), **pin(body)}


def source_packet(expected_sha256):
    path = ROOT / 'SOURCE-PINS.json'
    body = path.read_bytes()
    need(hashlib.sha256(body).hexdigest() == expected_sha256, 'ROOT_OBSERVED_COMPLETE_H5_SOURCE_PINS')
    packet = json.loads(body)
    need(packet['source_sha'] == HEAD and packet['runtime_ready'] is False,
         'SOURCE_PACKET_IS_NOT_A_RUNTIME_SUCCESS_RECEIPT')
    for relative, row in packet['files'].items():
        path = ROOT / relative
        need(not Path(relative).is_absolute() and '..' not in Path(relative).parts,
             'CANONICAL_SOURCE_PACKET_MEMBER')
        fixed(path, row)
    return {'path': (ROOT / 'SOURCE-PINS.json').as_posix(), **pin(body)}


def actual_A_inputs(path):
    body = path.read_bytes()
    inputs = json.loads(body)
    known = json.loads((ROOT / 'coordinator/CURRENT-A-SOURCE.json').read_bytes())
    need(known['source_sha'] == HEAD and known['parent'] == PARENT,
         'EXACT_CURRENT_KNOWN_A_SOURCE')
    apins = json.loads(deref(known['A_Source_PINS']))
    need(len(apins['source_members18']) == 18 and apins['source_sha'] == HEAD,
         'COMPLETE_CURRENT_A_SOURCE18')
    for name in apins['source_members18']:
        fixed(Path(known['A_Source_PINS']['path']).parent / name, apins['files'][name])
    need(inputs['M_result'] == known['M_result']
         and inputs['M_root_readback'] == known['M_root_readback'],
         'ACTUAL_COMPLETED_CURRENT_M_AND_ORIGINAL_FULL_READBACK')
    m, context = validate_success(inputs, known['M_Source_statics'], apins)
    need(inputs['M_outcome']['created_object']['pid'] == known['M_outer_pid'],
         'SAME_ACTUAL_CURRENT_M_OUTER_PID')
    report = json.loads(deref(known['M_root_readback']))
    need(report['actual_RESULT'] == inputs['M_result'] and report['source_sha'] == HEAD
         and report['status'] == 'PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY'
         and report['native_operations'] == 1088 and report['steps'] == 19,
         'ORIGINAL_COMPLETE_CURRENT_M_READBACK_JOIN')
    # Successful A close/zero is read and validated above, never invoked again.
    return {'path': path.as_posix(), **pin(body)}, inputs, m, context


class Coordinator:
    def __init__(self, archive, source_pins, inputs_pin, context=None):
        self.archive = archive
        self.source_pins = source_pins
        self.inputs_pin = inputs_pin
        self.context = context
        self.sequence = 0

    def native(self, program, arguments, label):
        self.sequence += 1
        stem = self.archive / ('%04d-' % self.sequence + label)
        argv = [sys.executable, '-B', str(program), *map(str, arguments)]
        process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        save_new(stem.with_suffix('.created.json'),
                 {'pid': process.pid, 'argv': argv, 'started_ns': time.time_ns()})
        stdout, stderr = process.communicate()
        for suffix, value in [('stdout', stdout), ('stderr', stderr)]:
            with stem.with_suffix('.' + suffix).open('xb') as output:
                need(output.write(value) == len(value), 'WHOLE_ORIGINAL_NATIVE_STREAM')
        save_new(stem.with_suffix('.terminal.json'), {
            'pid': process.pid, 'argv': argv, 'native_exit': process.returncode,
            'wait_returncode': process.returncode, 'finished_ns': time.time_ns(),
            'stdout': {'path': stem.with_suffix('.stdout').as_posix(), **pin(stdout)},
            'stderr': {'path': stem.with_suffix('.stderr').as_posix(), **pin(stderr)}})
        need(process.returncode == 0 and stderr == b'', 'ORIGINAL_H5_NATIVE_ZERO_REQUIRED')
        need(stdout.endswith(b'\n'), 'FULL_PUBLIC_JSON_NATIVE_STDOUT')
        value = json.loads(stdout.splitlines()[-1])
        need(type(value) is dict, 'ORIGINAL_PUBLIC_STAGE_RESULT_MAP')
        return value

    def stage(self, name, proof=None, extra=()):
        arguments = [name]
        if self.context is not None:
            arguments += ['--context', self.context]
        if proof is not None:
            arguments += ['--proof', proof]
        arguments += list(extra)
        return self.native(PROFILE / 'ms4-h5-host.py', arguments,
                           name + (('-' + proof) if proof else ''))

    def observe(self, proof, stage='observe'):
        # Observe only the original created handle; a timeout cannot create,
        # restart, replace or repeat a start. Every original response is saved.
        for _ in range(240):
            value = self.stage(stage, proof, ['--seconds', '30'])
            if value.get('status') == 'exited':
                need(value['exit_code'] == 0 and value['pid'] == 0,
                     'SAME_ORIGINAL_NORMAL_TERMINAL')
                return value
            need(value.get('status') in ['created', 'running'],
                 'ORIGINAL_HANDLE_LIVE_OBSERVATION_ONLY')
        raise RuntimeError('ORIGINAL_HANDLE_STILL_LIVE_NO_RETRY_OR_REPLACEMENT')

    def registration(self, result, proof, resume=None):
        if result.get('status', '').startswith('LIVE_'):
            self.observe(proof)
            self.stage('finish-helper', proof)
            if resume is not None:
                return self.stage(resume)
        return result

    def h5_phase(self, create, proof, register=False):
        self.stage(create)
        if register:
            registration = 'register-' + proof
            self.stage('h5-register-create', registration)
            self.stage('h5-start', registration)
            self.observe(registration, 'h5-observe')
            self.stage('h5-finish', registration)
            self.stage('h5-bind', proof)
        self.stage('h5-start', proof)
        self.observe(proof, 'h5-observe')
        self.stage('h5-finish', proof)

    def start(self, args):
        self.native(PROFILE / 'ms4-h5-materials.py',
                    ['--repo', args.repo, '--source', HEAD], 'complete-materials')
        freeze = self.archive / 'h5-freeze.json'
        self.stage('freeze', extra=['--repo', args.repo, '--source', HEAD,
                   '--port', str(args.port), '--output', freeze,
                   '--proof-ini-source', args.proof_ini_source])
        result = self.stage('prepare', extra=['--binding', freeze, '--run-root', args.run_root])
        self.context = result['context']
        save_new(self.archive / 'context-pointer.json', {'context': self.context})
        if result['status'] == 'LIVE_SAME_HANDLE_PRESERVED':
            self.observe('prepare')
            self.stage('finish-helper', 'prepare')
        else:
            need(result['status'] == 'PRIVATE_PREPARE_TERMINAL_ONLY', 'ORIGINAL_PRIVATE_PREPARE')
        self.stage('db-up')
        self.registration(self.stage('initializer-start'), 'register-initializer', 'initializer-resume')
        self.observe('initializer')
        self.registration(self.stage('driver-create'), 'register-driver', 'driver-bind')
        self.stage('driver-start')
        self.observe('driver')
        self.registration(self.stage('early-paid-create'), 'register-paid-producer')
        self.registration(self.stage('fulfillment-migration-create'),
                          'register-fulfillment-migration', 'fulfillment-migration-bind')
        self.stage('fulfillment-migration-start')
        self.observe('fulfillment-migration')
        self.stage('fulfillment-migration-retire')
        self.stage('files-bootstrap-create')
        self.stage('files-bootstrap-start')
        self.observe('files-bootstrap')
        self.registration(self.stage('customer-prepare-create'),
                          'register-customer-prepare', 'customer-prepare-bind')
        self.stage('customer-prepare-start')
        self.observe('customer-prepare')
        self.h5_phase('h5-copy-create', 'customer-h5-public-copy')
        self.h5_phase('h5-prepare-create', 'customer-h5-prepare', True)
        self.h5_phase('h5-files-create', 'customer-h5-files', True)
        self.stage('final-create')
        self.stage('final-register-create')
        self.stage('final-register-start')
        self.observe(None, 'final-register-observe')
        self.stage('final-register-finish')
        self.stage('final-bind')
        self.stage('final-serve-start')
        self.stage('final-ui-start')
        context = json.loads(Path(self.context).read_bytes())
        ready = {'schema': 1, 'scope': 'INDEPENDENT_H5_COMPLETE_COORDINATOR_PREPARATION_ONLY',
                 'status': 'READY_NOT_GUI', 'source_sha': HEAD,
                 'context': {'path': self.context, **pin(Path(self.context).read_bytes())},
                 'source_pins': self.source_pins, 'successful_A_inputs': self.inputs_pin,
                 'run_id': context['run_id'], 'freeze_sha256': context['freeze_sha256'],
                 'browser': {name: {'path': (PROFILE / 'browser-v1' / name).as_posix(),
                                   **pin((PROFILE / 'browser-v1' / name).read_bytes())}
                             for name in ['customer-network-observer.mjs',
                                          'ms4-customer-private-bridge.mjs', 'ms4-customer-browser.mjs']},
                 'required_browser_evidence': 'ORIGINAL_NINE_SCENES_27_WIDTH_CELLS_REAL_CLICKS_FULL_CATALOG_DTO_JPEG',
                 'browser_acceptance': 'NOT RUN', 'full_acceptance': 'INCOMPLETE'}
        descriptor = save_new(self.archive / 'READY.json', ready)
        print(json.dumps({'status': 'READY_NOT_GUI', 'ready': descriptor,
                          'context': self.context, 'browser_acceptance': 'NOT RUN'}))

    def finish(self, ready):
        self.context = ready['context']['path']
        fixed(Path(self.context), ready['context'])
        context = json.loads(Path(self.context).read_bytes())
        root = Path(context['run_dir'])
        need(context['source_sha'] == HEAD and context['run_id'] == ready['run_id']
             and context['freeze_sha256'] == ready['freeze_sha256']
             and context['prep_dir'] == PROFILE.as_posix()
             and Path(self.context).resolve() == (root / 'actual-run-context.json').resolve(),
             'SAME_ORIGINAL_READY_H5_CONTEXT_AND_FREEZE')
        need(not (root / 'evidence/normal-close-attempt.json').exists(),
             'ONE_ORIGINAL_H5_CLOSE_ATTEMPT_ONLY')
        # The original H5 close reads its complete private native/browser/JPEG
        # proof before touching resources. READY alone cannot pass that proof.
        self.native(PROFILE / 'normal-close-customer-h5.py',
                    ['--context', self.context], 'original-h5-close')
        close = root / 'evidence/normal-close-result.json'
        close_sha = hashlib.sha256(close.read_bytes()).hexdigest()
        result = self.native(PROFILE / 'verify-zero-customer-h5.py',
                             ['--context', self.context, '--close-result-sha256', close_sha],
                             'original-h5-independent-zero')
        save_new(self.archive / 'RESULT.json', {
            'status': 'ORIGINAL_H5_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
            'source_sha': HEAD, 'context': self.context, 'source_pins': self.source_pins,
            'successful_A_inputs': self.inputs_pin,
            'close_result': {'path': close.as_posix(), **pin(close.read_bytes())},
            'independent_zero': result, 'full_marketplace_acceptance': 'INCOMPLETE'})
        print(json.dumps({'status': 'ORIGINAL_H5_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
                          'context': self.context, 'full_marketplace_acceptance': 'INCOMPLETE'}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=['start', 'finish'])
    parser.add_argument('--source-pins-sha256', required=True)
    parser.add_argument('--actual-public-inputs', required=True)
    parser.add_argument('--archive', required=True)
    parser.add_argument('--repo')
    parser.add_argument('--run-root')
    parser.add_argument('--port', type=int)
    parser.add_argument('--proof-ini-source')
    parser.add_argument('--ready')
    parser.add_argument('--ready-sha256')
    args = parser.parse_args()
    packet = source_packet(args.source_pins_sha256)
    inputs_pin, _, _, _ = actual_A_inputs(Path(args.actual_public_inputs))
    archive = Path(args.archive)
    need(archive.is_absolute() and not archive.exists() and archive.parent.is_dir(),
         'ONE_NEW_EXPLICIT_COORDINATOR_ARCHIVE')
    if args.phase == 'start':
        need(args.repo and args.run_root and args.port is not None
             and 1024 <= args.port <= 65535 and args.proof_ini_source,
             'EXPLICIT_ORIGINAL_H5_RUNTIME_INPUTS')
        archive.mkdir()
        Coordinator(archive, packet, inputs_pin).start(args)
    else:
        need(args.ready and args.ready_sha256, 'ORIGINAL_ROOT_OBSERVED_READY_REQUIRED')
        body = Path(args.ready).read_bytes()
        need(hashlib.sha256(body).hexdigest() == args.ready_sha256, 'EXACT_ORIGINAL_READY_BYTES')
        ready = json.loads(body)
        need(ready['status'] == 'READY_NOT_GUI' and ready['source_sha'] == HEAD
             and ready['source_pins'] == packet and ready['successful_A_inputs'] == inputs_pin,
             'SAME_ORIGINAL_CURRENT_SOURCE_AND_A_QUALIFICATION')
        archive.mkdir()
        Coordinator(archive, packet, inputs_pin).finish(ready)


if __name__ == '__main__':
    main()
