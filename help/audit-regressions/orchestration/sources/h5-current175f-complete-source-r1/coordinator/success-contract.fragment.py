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

