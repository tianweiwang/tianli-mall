"""UNEXECUTED late-bound public history producer; no targets or author calls.

The completed source module additionally contains the exact old pure history
helpers and both complete pre_a_history/post_a_history definitions. New actual
receipt interfaces are supplied only after their reviewed Source is fixed.
"""
import argparse
import hashlib
import json
import os
import re
import stat
from pathlib import Path


def need(value, code):
    if not value:
        raise RuntimeError(code)


def enc(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def pointer(path, raw):
    return {'path': Path(path).as_posix(), **pin(raw)}


def native9(path):
    st = Path(path).stat(follow_symlinks=False)
    return [st.st_dev, st.st_ino, st.st_mode, st.st_nlink, st.st_uid, st.st_gid, st.st_size, st.st_mtime_ns, st.st_ctime_ns]


class PublicWindows:
    """Full opaque byte hashes and native9, with complete explicit namespaces."""
    def __init__(self):
        self.files = {}
        self.namespaces = {}

    def file(self, path, row=None):
        path = ordinary(path)
        need(path.is_absolute(), 'ABSOLUTE_PUBLIC_INPUT')
        before = native9(path)
        raw = path.read_bytes()
        need(native9(path) == before, 'PUBLIC_FILE_UNCHANGED_DURING_FULL_RAM_READ')
        observed = {'path': path.as_posix(), **pin(raw), 'native9': before}
        if row is not None:
            need(type(row) is dict and type(row.get('bytes')) is int and row['bytes'] >= 0
                and re.fullmatch('[a-f0-9]{64}', row.get('sha256', '')) is not None, 'TYPED_PUBLIC_POINTER')
            need(pin(raw) == {k: row[k] for k in ['bytes', 'sha256']}, 'EXACT_FULL_PUBLIC_BYTES')
            if 'path' in row:
                need(row['path'] == path.as_posix(), 'EXACT_PUBLIC_POINTER_PATH')
        if path.as_posix() in self.files:
            need(self.files[path.as_posix()] == observed, 'REPEATED_PUBLIC_READ_SAME_FULL_BYTES_AND_NATIVE9')
        self.files[path.as_posix()] = observed
        return raw

    def tree(self, root, state):
        root = ordinary(root, True)
        observed = {'files': sorted(state['files']), 'directories': state['directories'],
            'directory_native9': {name: native9(root / name) for name in [''] + state['directories']}}
        if root.as_posix() in self.namespaces:
            need(self.namespaces[root.as_posix()] == observed, 'REPEATED_COMPLETE_NAMESPACE_SAME')
        self.namespaces[root.as_posix()] = observed
        for name, row in state['files'].items():
            self.file(root / name, row)

    def end(self):
        # Re-read whole opaque bytes without parsing any private/ENV/body content.
        for path, row in list(self.files.items()):
            self.file(Path(path), row)
        for path, expected in self.namespaces.items():
            root = ordinary(Path(path), True)
            state = ORIGINAL_TREE(root)
            need(sorted(state['files']) == expected['files'] and state['directories'] == expected['directories'], 'END_COMPLETE_PUBLIC_NAMESPACE_IDENTICAL')
            need({name: native9(root / name) for name in [''] + state['directories']} == expected['directory_native9'], 'END_ORIGINAL_DIRECTORY_IDENTITIES')
            for name, row in state['files'].items():
                need(self.files[(root / name).as_posix()]['bytes'] == row['bytes']
                    and self.files[(root / name).as_posix()]['sha256'] == row['sha256'], 'END_NAMESPACE_FULL_FILE_BYTES')
        return {'files': [self.files[p] for p in sorted(self.files)], 'namespaces': self.namespaces,
            'all_whole_bytes_native9_namespaces_ended': True, 'opaque_content': 'HASHED_IN_RAM_NEVER_PARSED_OR_PRINTED'}


def exact_pointer(row):
    need(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and Path(row['path']).is_absolute(), 'EXACT_PUBLIC_THREE_FIELD_POINTER')
    return WINDOWS.file(Path(row['path']), row)


def outcome(value, expected_argv=None, expected_pid=None):
    need(type(value) is dict and set(value) == {'created', 'terminal', 'stdout', 'stderr', 'created_object', 'terminal_object'}, 'ACTUAL_SIX_POINTERS_AND_OBJECTS')
    created = json.loads(exact_pointer(value['created']))
    terminal = json.loads(exact_pointer(value['terminal']))
    need(created == value['created_object'] and terminal == value['terminal_object'], 'ACTUAL_ORIGINAL_RECEIPT_OBJECTS')
    need(set(created) == {'pid', 'argv', 'started_ns'} and set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}, 'EXACT_REAL_LAUNCHER_NATIVE_SCHEMAS')
    need(type(created['pid']) is int and created['pid'] > 0 and created['pid'] == terminal['pid'], 'ACTUAL_SAME_POSITIVE_PID')
    need(type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int and terminal['native_exit'] == terminal['wait_returncode'] == 0, 'ACTUAL_NATIVE_EXIT_AND_WAIT_ZERO')
    need(type(created['started_ns']) is int and type(terminal['finished_ns']) is int and 0 < created['started_ns'] < terminal['finished_ns'], 'ACTUAL_MONOTONIC_NATIVE_WINDOW')
    if expected_pid is not None:
        need(created['pid'] == expected_pid, 'EXACT_FIXED_ORIGINAL_NATIVE_PID')
    if expected_argv is not None:
        need(created['argv'] == expected_argv, 'EXACT_REVIEWED_ORIGINAL_NATIVE_ARGV')
    for stream in ['stdout', 'stderr']:
        need(terminal[stream] == value[stream], 'EXACT_ORIGINAL_TYPED_STREAM_POINTER')
        raw = exact_pointer(value[stream])
        if stream == 'stderr':
            need(raw == b'', 'ACTUAL_FULL_EMPTY_STDERR')
    return created, terminal


def original_outcome(stem):
    """Build real typed six-field outcome from existing original launcher files."""
    created_path, terminal_path = stem.with_suffix('.created.json'), stem.with_suffix('.terminal.json')
    created_raw, terminal_raw = WINDOWS.file(created_path), WINDOWS.file(terminal_path)
    created, terminal = json.loads(created_raw), json.loads(terminal_raw)
    need(set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}, 'ORIGINAL_LAUNCHER_TERMINAL_SIX')
    return {'created': pointer(created_path, created_raw), 'terminal': pointer(terminal_path, terminal_raw),
        'stdout': terminal['stdout'], 'stderr': terminal['stderr'], 'created_object': created, 'terminal_object': terminal}


def source_inputs():
    # Exact old Source metadata, not current Run/private content or a new schema.
    value = json.loads(WINDOWS.file(Path(ORIGINAL_INPUTS['path']), ORIGINAL_INPUTS))
    need(value['history'] == HISTORY_AUTHORITY, 'EXACT_ORIGINAL_COMPLETE_PRE_A_AUTHORITY')
    return value


def union_row(rows, origins, row, origin):
    need(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and Path(row['path']).is_absolute(), 'FULL_PUBLIC_DESCRIPTOR_THREE')
    need(type(row['bytes']) is int and row['bytes'] >= 0 and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None, 'FULL_PUBLIC_DESCRIPTOR_TYPES')
    if row['path'] in rows:
        need(rows[row['path']] == row, 'STRICT_SAME_PATH_SAME_COMPLETE_DESCRIPTOR')
    rows[row['path']] = row
    origins.setdefault(row['path'], [])
    if origin not in origins[row['path']]:
        origins[row['path']].append(origin)


def complete_history_rows(retained):
    """Exact producer of the unchanged original post_a_history expected map."""
    prior, extras = pre_a_history()
    rows, origins = {}, {}
    for row in prior.values():
        union_row(rows, origins, row, 'original_fixed_R33_inventory238900')
    for row in extras:
        union_row(rows, origins, row, 'original_R33_authority_four_or_preparation_failure_extras')
    for name in ['DESIGN-TASKS.md', 'run.py', 'PINS.json', 'PROFILE-PINS.json', 'PROFILE-PUBLICATION.json']:
        path = NORMAL_SOURCE / name
        union_row(rows, origins, pointer(path, WINDOWS.file(path)), 'current_normal_five')
    roots = [OLD_ROOT/'evidence', OLD_ARCHIVE, NORMAL_SOURCE/'actual-profile',
        NORMAL_SOURCE/'materialize-freeze-assets-r1', NORMAL_SOURCE/'native-normal-start-assets-r1']
    roots += [NORMAL_SOURCE.parent/'materializer', PREPARED/'r40-normal-materialize-source-r1',
        PREPARED/'r39-ar-failed-normal-close-r1/close-output', PREPARED/'r39-ar-failed-normal-close-r1/independent-zero-output']
    for root in roots:
        state = tree(root)
        for name, row in state['files'].items():
            union_row(rows, origins, {'path': (root/name).as_posix(), **row}, 'complete_explicit_public_tree:' + root.as_posix())
    for name in PUBLIC_ROOT_FILES + ['proof-php-ini/99-ms4-proof-memory.ini']:
        path = OLD_ROOT / name
        union_row(rows, origins, pointer(path, WINDOWS.file(path)), 'original_PUBLIC_ROOT_FILES_and_proofINI')
    need(type(retained) is list and retained and [r['path'] for r in retained] == sorted({r['path'] for r in retained}), 'COMPLETE_EXPLICIT_SORTED_RETAINED_PUBLIC_LIST')
    for row in retained:
        exact_pointer(row)
        union_row(rows, origins, row, 'explicit_original_Root_retained_public_input')
    return sorted(rows.values(), key=lambda r: r['path']), origins


def require_reader_report(report, peer):
    status = 'PASS_INDEPENDENT_ACTUAL_CURRENT28DC_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY' if peer else 'PASS_ORIGINAL_CURRENT28DC_COMPLETE_NORMAL_A_RECEIPT_READBACK_ONLY'
    need(report['status'] == status and report['run_id'] == RUN_ID and report['source_sha'] == HEAD and report['freeze_sha256'] == FREEZE['sha256'], 'GENUINE_COMPLETE_READER_IDENTITY')
    need(report['original_outer_pid'] == OUTER_PID and report['original_prepare_pid'] == PREPARE_PID and report['original_outer_native_exit_wait'] == 0, 'GENUINE_ORIGINAL_READER_NATIVE_IDENTITIES')
    need(report['initial_prepare_native_zero'] is True and report['all_original_stream_pins_match'] is True
        and report['all_windows_closed'] is True and report['independent_zero_from_original_S'] is True
        and report['extra_close_or_restart_performed'] is False, 'GENUINE_COMPLETE_READER_WINDOWS_NO_RERUN')
    proofs = report['all9_original_container_proofs']
    need(set(proofs) == {'initializer', 'driver', 'fulfillment-migration', 'files-bootstrap', 'customer-prepare', 'paid-producer', 'fulfillment-normal', 'aftersale-review-normal', 'worker-normal'}, 'ALL_NINE_ORIGINAL_PROOFS')
    need(all(r == {'native_exit': 0, 'same_original_id': True, 'no_restart': True} for r in proofs.values()), 'ALL_NINE_ORIGINAL_PROOFS_ZERO_NO_RESTART')
    if peer:
        need(report['reader_source'] == PEER_READER and report['independently_reads_originals'] is True
            and report['target_or_source_module_imported'] is False and report['actual_Run_freeze_equals_complete_frozen_Source'] is True, 'GENUINE_INDEPENDENT_FULL_ORIGINAL_READER')


def write_new(path, raw):
    need(not path.exists(), 'NEW_CAPTURE_FILE_ONLY')
    with path.open('xb') as stream:
        need(stream.write(raw) == len(raw), 'COMPLETE_NEW_CAPTURE_FILE')
    need(path.read_bytes() == raw, 'NEW_CAPTURE_EXACT_WHOLE_BYTES')


def produce(args):
    global WINDOWS, A_QUALIFICATION, POST_A_HISTORY_CONTRACT
    WINDOWS = PublicWindows()
    output = Path(args.output)
    need(output.is_absolute() and output.parent.resolve() == ORG.resolve() and not output.exists(), 'NEW_DIRECT_OWN_ORG_CAPTURE_OUTSIDE_BASE_AND_ALL_B_RUNTIME')
    need(not output.is_relative_to(PREPARED) and not output.is_relative_to(OLD_ROOT), 'CAPTURE_NOT_IN_ANY_OLD_RUN_OR_B_SOURCE_RUNTIME')
    admission_raw = WINDOWS.file(Path(args.actual_admission))
    admission = json.loads(admission_raw)
    need(type(admission) is dict and set(admission) == {'schema', 'run_id', 'source_sha', 'outer_session', 'readers', 'author_Source_review', 'initializer_syntax', 'retained_public_files'}, 'EXPLICIT_NEW_PRODUCER_ACTUAL_ADMISSION_SCHEMA_NOT_EXISTING_RECEIPT')
    need(admission['schema'] == 1 and admission['run_id'] == RUN_ID and admission['source_sha'] == HEAD and admission['outer_session'] == OUTER_SESSION, 'FIXED_ORIGINAL_CURRENT28DC_SCOPE')
    # Refuse prior to any Run read if the original S has no terminal or failed.
    s_outcome = original_outcome(NORMAL_SOURCE.parent/'launcher/normal-start')
    outcome(s_outcome, expected_pid=OUTER_PID)
    need(s_outcome['created_object']['argv'] == [PYTHON, '-B', '-X', 'utf8', (NORMAL_SOURCE/'run.py').as_posix(), '--root-source-pins-sha256', ROOT_SOURCE_PINS['sha256'], '--setup-sha256', M_RESULT['sha256']], 'EXACT_CURRENT_S_ORIGINAL_ARGV')
    # The exact launcher/qualification adapters are appended only from the
    # actual reviewed Source interface supplied by Root, never guessed here.
    validate_reader_native_admission(admission['readers'])
    syntax = validate_initializer_syntax_admission(admission['initializer_syntax'])
    review = validate_author_Source_review(admission['author_Source_review'])
    exact_pointer(A_SOURCE_PINS)
    m_raw = exact_pointer(M_RESULT)
    m = json.loads(m_raw)
    need(m['source_sha'] == HEAD and m['parent'] == PARENT and len(m) == 34 and len(m['commit_chain']) == 18 and len(m['native_operations']) == 1078, 'ACTUAL_COMPLETED_SAME_SOURCE_M34_CHAIN18_LEDGER1078')
    need({'path': m['freeze'], 'bytes': m['freeze_bytes'], 'sha256': m['freeze_sha256']} == FREEZE, 'ACTUAL_COMPLETED_SAME_SOURCE_FREEZE')
    exact_pointer(FREEZE)
    m_outcome = original_outcome(NORMAL_SOURCE.parent/'launcher/materialize')
    outcome(m_outcome)
    exact_pointer(M_ROOT_READBACK)
    context_path = OLD_ROOT/'actual-run-context.json'
    context_raw = WINDOWS.file(context_path)
    context = json.loads(context_raw)
    need(context['run_id'] == RUN_ID and context['source_sha'] == HEAD and context['freeze_sha256'] == FREEZE['sha256'], 'ORIGINAL_CURRENT_CONTEXT_IDENTITY')
    final_raw = WINDOWS.file(OLD_RESULT)
    need(json.loads(final_raw) == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO', 'run_id': RUN_ID, 'source_sha': HEAD, 'context': context_path.as_posix(), 'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE', 'DOM_nonempty_history_due_finance_release': 'NOT RUN'}, 'ORIGINAL_COMPLETE_NORMAL_A_RESULT')
    close_path = OLD_ROOT/'evidence/ms4-worker-normal-close-result.json'
    zero_path = OLD_ROOT/'evidence/ms4-worker-normal-independent-zero.json'
    close_raw, zero_raw = WINDOWS.file(close_path), WINDOWS.file(zero_path)
    closed, zero = json.loads(close_raw), json.loads(zero_raw)
    need(closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL' and zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO', 'ORIGINAL_SUCCESS_CLOSE_ZERO_ONLY')
    for obj in [closed, zero]:
        need(obj['run_id'] == RUN_ID and obj['source_sha'] == HEAD and obj['freeze_sha256'] == FREEZE['sha256'] and obj['native_exit'] == 0, 'ORIGINAL_CLOSE_ZERO_SAME_IDENTITY')
    need(zero['close_result_sha256'] == pin(close_raw)['sha256'], 'ORIGINAL_CLOSE_ZERO_FULL_PIN_JOIN')
    need([(q['kind'], q['label']) for q in zero['queries']] == [(k, l) for k in ['container', 'network', 'volume'] for l in ['project', 'run']], 'ORIGINAL_SIX_FRESH_ZERO_QUERIES')
    empty = pin(b'')
    need(all(q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0 and q['stdout_sha256'] == q['stderr_sha256'] == empty['sha256'] for q in zero['queries']), 'ORIGINAL_SIX_FULL_EMPTY_STREAMS')
    rows, origins = complete_history_rows(admission['retained_public_files'])
    need(all(not Path(r['path']).is_relative_to(output) for r in rows), 'HISTORY_CANNOT_INCLUDE_OUTPUT_OR_ITS_FUTURE_FILES')
    before_output_windows = WINDOWS.end()
    header = {'schema': 1, 'scope': 'CURRENT28DC_REAL_COMPLETE_NORMAL_A_POST_SUCCESS_PUBLIC_HISTORY', 'run_id': RUN_ID, 'source_sha': HEAD, 'freeze_sha256': FREEZE['sha256'], 'outer_session': OUTER_SESSION, 'outer_pid': OUTER_PID, 'prepare_pid': PREPARE_PID, 'pre_A_public_inventory_count': 238900, 'full_public_file_count': len(rows), 'all_full_public_bytes_native9_namespaces_unchanged': True, 'private_ENV_child_body_content': 'OPAQUE_RAM_HASH_ONLY_NOT_PARSED_OR_PRINTED', 'full_acceptance': 'INCOMPLETE'}
    history_raw = enc({**header, 'files': rows})
    output.mkdir()
    history_path = output/'POST-A-PUBLIC-HISTORY.json'
    write_new(history_path, history_raw)
    POST_A_HISTORY_CONTRACT = {'header': header, 'files_key': 'files', 'retained_public_files': admission['retained_public_files']}
    A_QUALIFICATION = {'outer_native_exit': 0, 'normal_root_result': pointer(OLD_RESULT, final_raw), 'worker_close': pointer(close_path, close_raw), 'independent_zero': pointer(zero_path, zero_raw), 'post_a_history': pointer(history_path, history_raw)}
    # Consume the new history with both exact original full bodies, without
    # importing or calling any Root/Host/target/author or performing cleanup.
    reader_args = argparse.Namespace(public_history_inventory=history_path.as_posix(), public_history_inventory_sha256=pin(history_raw)['sha256'])
    consumed, _ = post_a_history(reader_args, context, final_raw, close_raw, zero_raw)
    need(consumed == rows + [pointer(history_path, history_raw)], 'ORIGINAL_WHOLE_POST_A_CONSUMER_ACCEPTS_COMPLETE_NEW_PRODUCER')
    ended = WINDOWS.end()
    actual_inputs = {'schema': 1, 'source_sha': HEAD, 'M_result': M_RESULT, 'M_outcome': m_outcome, 'M_root_readback': M_ROOT_READBACK, 'A_outcome': s_outcome, 'A_outer_session': OUTER_SESSION, 'A_qualification': A_QUALIFICATION, 'A_context': pointer(context_path, context_raw), 'post_A_history_contract': POST_A_HISTORY_CONTRACT, 'Source_review_authority': review, 'initializer_leaf': INITIALIZER_LEAF}
    need(set(actual_inputs) == {'schema', 'source_sha', 'M_result', 'M_outcome', 'M_root_readback', 'A_outcome', 'A_outer_session', 'A_qualification', 'A_context', 'post_A_history_contract', 'Source_review_authority', 'initializer_leaf'}, 'ORIGINAL_EXACT_TWELVE_AUTHOR_KEYS')
    write_new(output/'POST-A-CONTRACT.json', enc(POST_A_HISTORY_CONTRACT))
    write_new(output/'PUBLIC-HISTORY-PROVENANCE.json', enc({'files': origins, 'source': 'Exact complete original pre/post_A contract union; duplicates keep complete descriptors'}))
    write_new(output/'PUBLIC-INPUT-WINDOWS.json', enc(ended))
    write_new(output/'ACTUAL-AUTHOR-INPUTS.json', enc(actual_inputs))
    result = {'status': 'ACTUAL_CURRENT28DC_A0_POSTA_AND_TWELVE_AUTHOR_INPUTS_CAPTURED_ONLY', 'run_id': RUN_ID, 'source_sha': HEAD, 'public_files': len(rows), 'original_full_consumer_accepted': True, 'all_windows_ended': True, 'original_close_zero_consumed_without_rerun': True, 'initializer_syntax_qualification': syntax, 'author_invoked': False, 'B_generated': False, 'full_acceptance': 'INCOMPLETE', 'history': pointer(history_path, history_raw), 'actual_inputs': pointer(output/'ACTUAL-AUTHOR-INPUTS.json', enc(actual_inputs))}
    write_new(output/'RESULT.json', enc(result))
    print(json.dumps({'status': result['status'], 'public_files': len(rows), 'result': pointer(output/'RESULT.json', enc(result))}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--actual-admission', required=True, help='real reviewed native reader/Source-review/initializer-syntax authorities and full retained public inputs; placeholders are rejected')
    parser.add_argument('--output', required=True, help='new direct ORG actual capture, outside BASE/all B runtimes/current Run; no output is included in history')
    produce(parser.parse_args())
