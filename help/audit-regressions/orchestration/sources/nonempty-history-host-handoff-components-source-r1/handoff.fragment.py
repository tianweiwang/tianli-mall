def legacy_handoff_source(context):
    """No new handoff action until the complete final Source is bound."""
    expected_names = {'producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer'}
    expect(type(LEGACY_HANDOFF_BINDINGS) is dict and set(LEGACY_HANDOFF_BINDINGS) == expected_names,
           'EXACT_COMPLETE_HANDOFF_SOURCE_MEMBERS')
    for name in ['producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer']:
        expect(LEGACY_HANDOFF_BINDINGS[name] is not None, 'UNBOUND_LEGACY_HANDOFF_' + name.upper())
    frozen = load(Path(context['run_dir']) / 'preparation-source-binding.json')
    verify_freeze(frozen)
    expect(len(ARTIFACTS) == 13 and sum(name.endswith('.php') for name in ARTIFACTS) == 9
           and len(LEGACY_CONTROL) == 11, 'ORIGINAL_ARTIFACT13_PHP9_CONTROL11')
    artifacts = [source_row(PREP, name) for name in ARTIFACTS]
    expect(frozen['artifact_files'] == artifacts and [row for row in artifacts if row['path'] != 'ms4-host.py'] == LEGACY_HANDOFF_BINDINGS['artifacts']
           and len(LEGACY_HANDOFF_BINDINGS['artifacts']) == 12,
           'FINAL_FULL_ARTIFACT13_AND_NONSELF12_NOT_A_PARTIAL_OVERLAY')
    controls = frozen['nonempty_history']['control_files']
    expect(controls == LEGACY_HANDOFF_BINDINGS['control_files'] and len(controls) == 11,
           'FINAL_COMPLETE_CURRENT3_BUNDLE8_CONTROL_BYTES')
    for name, relative in [('producer', 'legacy-history-producer.php'), ('library', 'legacy-app.php')]:
        expect(LEGACY_HANDOFF_BINDINGS[name] == source_row(PREP, relative), 'FINAL_' + name.upper() + '_FULL_SOURCE')
    for name in ['startup', 'reader', 'consumer']:
        row = LEGACY_HANDOFF_BINDINGS[name]
        expect(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and type(row['path']) is str
               and Path(row['path']).is_absolute() and type(row['bytes']) is int and row['bytes'] > 0
               and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None,
               'FINAL_' + name.upper() + '_SOURCE_DESCRIPTOR')
        raw = Path(row['path']).read_bytes()
        expect(len(raw) == row['bytes'] and digest(raw) == row['sha256'], 'FINAL_' + name.upper() + '_SOURCE_BYTES')
    return frozen


def legacy_seal_equal(left, right):
    """Equivalent to the original PHP strict equality, including object key order."""
    if type(left) is not type(right):
        return False
    if type(left) is dict:
        return list(left) == list(right) and all(legacy_seal_equal(left[key], right[key]) for key in left)
    if type(left) is list:
        return len(left) == len(right) and all(legacy_seal_equal(a, b) for a, b in zip(left, right))
    return left == right


def legacy_producer_public(context, archived):
    """Read only the exact live or archived producer public inputs, with native9."""
    expect(type(archived) is bool, 'EXPLICIT_PRODUCER_PUBLIC_PHASE')
    root = Path(context['run_dir']); folder = root / 'evidence/nonempty-history-phases/legacy-producer-complete'
    fields = ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']
    seen = {}
    def read(path):
        expect(path.is_file() and not path.is_symlink(), 'PRODUCER_REGULAR_PUBLIC_INPUT')
        before = path.stat(); raw = path.read_bytes(); after = path.stat()
        stamp = tuple(getattr(before, field) for field in fields)
        expect(stamp == tuple(getattr(after, field) for field in fields) and len(raw) == before.st_size,
               'PRODUCER_COMPLETE_PUBLIC_NATIVE9_READ')
        seen[path.as_posix()] = (raw, stamp)
        return raw
    if archived:
        expect(folder.is_dir() and not folder.is_symlink(), 'PRODUCER_ORIGINAL_ARCHIVE_EXISTS')
        index = json.loads(read(folder / 'INDEX.json'))
        expect(set(index) == {'schema', 'phase', 'run_id', 'source_sha', 'legacy_source_sha', 'files', 'business_acceptance'}
               and type(index['schema']) is int and index['schema'] == 1 and index['phase'] == 'legacy-producer-complete'
               and index['run_id'] == context['run_id'] and index['source_sha'] == context['source_sha']
               and index['legacy_source_sha'] == LEGACY_SOURCE and index['business_acceptance'] == 'NOT RUN'
               and type(index['files']) is list, 'PRODUCER_EXACT_ORIGINAL_ARCHIVE_INDEX')
        leaves = [row['path'] for row in index['files']]
        native_leaves = [name for name in leaves if re.fullmatch(r'evidence/native-[0-9]{4}\.json', name)]
        expect(leaves == LEGACY_PRODUCER_PUBLIC + native_leaves and native_leaves
               and native_leaves == ['evidence/native-%04d.json' % number for number in range(1, len(native_leaves) + 1)],
               'PRODUCER_COMPLETE_ORIGINAL_NATIVE_LEDGER_PREFIX')
        actual_files, actual_dirs = [], []
        def walk_error(error):
            raise error
        for parent, dirs, files in os.walk(folder, followlinks=False, onerror=walk_error):
            for name in dirs:
                path = Path(parent) / name
                expect(path.is_dir() and not path.is_symlink(), 'PRODUCER_ARCHIVE_NO_LINK_DIRECTORY')
                actual_dirs.append(path.relative_to(folder).as_posix())
            actual_files += [(Path(parent) / name).relative_to(folder).as_posix() for name in files]
        expect(sorted(actual_files) == sorted(leaves + ['INDEX.json']) and actual_dirs == ['evidence'],
               'PRODUCER_ARCHIVE_COMPLETE_CLOSED_NAMESPACE')
        raws = {}
        for row in index['files']:
            expect(type(row) is dict and set(row) == {'path', 'bytes', 'sha256', 'original_stat'}
                   and type(row['bytes']) is int and row['bytes'] > 0
                   and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256'])
                   and type(row['original_stat']) is dict and set(row['original_stat']) == set(fields)
                   and all(type(value) is int for value in row['original_stat'].values())
                   and row['original_stat']['st_size'] == row['bytes'], 'PRODUCER_ORIGINAL_ARCHIVE_COMPLETE_DESCRIPTOR')
            raw = read(folder / row['path'])
            expect(len(raw) == row['bytes'] and digest(raw) == row['sha256'], 'PRODUCER_ARCHIVE_EXACT_ORIGINAL_BYTES')
            raws[row['path']] = raw
            if row['path'].startswith('evidence/') or row['path'] in ['actual-run-context.json', 'preparation-source-binding.json']:
                expect(read(root / row['path']) == raw, 'PRODUCER_IMMUTABLE_ORIGINAL_PUBLIC_STILL_IDENTICAL')
    else:
        leaves = LEGACY_PRODUCER_PUBLIC + ['evidence/' + path.name for path in sorted((root / 'evidence').glob('native-*.json'))]
        raws = {name: read(root / name) for name in leaves}
    starts = []
    for name, raw in raws.items():
        if re.fullmatch(r'evidence/native-[0-9]{4}\.json', name):
            row = json.loads(raw)
            expect(set(row) == {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'}
                   and type(row['stage']) is str and type(row['native_exit']) is int and row['native_exit'] == 0
                   and row['private_environment_logs_omitted'] is True
                   and all(type(row[key + '_bytes']) is int and row[key + '_bytes'] >= 0
                       and type(row[key + '_sha256']) is str and re.fullmatch('[a-f0-9]{64}', row[key + '_sha256'])
                       for key in ['stdout', 'stderr']), 'ORIGINAL_HOST_NATIVE_SUMMARIES_PRESERVED_NOT_RAW_STREAMS')
            if row['stage'] == 'legacy-history-producer-start':
                starts.append(row)
    expect(len(starts) == 1, 'ONE_ORIGINAL_PRODUCER_NATIVE_START_NO_RETRY')
    cid = json.loads(raws['evidence/legacy-history-producer-created.json'])['id']
    expect(starts[0]['stdout_bytes'] == 65 and starts[0]['stdout_sha256'] == digest((cid + '\n').encode())
           and starts[0]['stderr_bytes'] == 0 and starts[0]['stderr_sha256'] == digest(b''), 'PRODUCER_START_RECEIPT_SAME_ORIGINAL_CID')
    for path, (raw, stamp) in list(seen.items()):
        expect(read(Path(path)) == raw and seen[path][1] == stamp, 'PRODUCER_PUBLIC_COMPLETE_SECOND_NATIVE9_READ')
    return raws


def legacy_producer_seal(context, raws):
    """Only the adopted exact13 public contract; all private proof stays in the parent."""
    def keys(value, wanted, code):
        expect(type(value) is dict and set(value) == set(wanted), code)
    def integer(value, minimum=0):
        return type(value) is int and value >= minimum
    def hexadecimal(value, length=64):
        return type(value) is str and re.fullmatch('[a-f0-9]{%d}' % length, value) is not None
    def descriptor(value, leaf):
        keys(value, ['path', 'bytes', 'sha256'], 'SEAL_EXACT_PUBLIC_DESCRIPTOR')
        raw = raws[leaf]
        expect(value == {'path': '/run/audit/' + leaf, 'bytes': len(raw), 'sha256': digest(raw)},
               'SEAL_ORIGINAL_COMPLETE_PUBLIC_DESCRIPTOR_JOIN')
    def group(value):
        keys(value, ['leader_pid', 'pgid', 'sid', 'leader_starttime'], 'SEAL_EXACT_NATIVE_GROUP')
        expect(integer(value['leader_pid'], 2) and value['leader_pid'] == value['pgid'] == value['sid']
               and type(value['leader_starttime']) is str and re.fullmatch('[0-9]+', value['leader_starttime']),
               'SEAL_ORIGINAL_SETSID_NATIVE_GROUP')
    def native(value, pid, live):
        keys(value, ['pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'], 'SEAL_EXACT_NATIVE_SEVEN')
        expect(value['pid'] == pid and integer(value['pid'], 2)
               and all(type(value[key]) is bool for key in ['running', 'signaled', 'stopped'])
               and all(type(value[key]) is int for key in ['exitcode', 'termsig', 'stopsig'])
               and value['running'] is live, 'SEAL_REAL_NATIVE_VALUES_NOT_FORCED_ZERO')
        if live:
            expect(value['signaled'] is False and value['stopped'] is False and value['exitcode'] == -1
                   and value['termsig'] == value['stopsig'] == 0, 'SEAL_ORIGINAL_LIVE_CHANNEL_AT_NO_EXTRA')
    def closed(value):
        keys(value, ['stopped', 'group', 'remaining_members', 'listener_open', 'first_terminal', 'term_attempted',
            'stop_signal', 'proc_close_exit', 'streams', 'waits'], 'SEAL_EXACT_SAFE_CLOSE_TEN')
        group(value['group']); native(value['first_terminal'], value['group']['leader_pid'], False)
        expect(value['stopped'] is True and value['remaining_members'] == [] and value['listener_open'] is False
               and type(value['term_attempted']) is bool and type(value['proc_close_exit']) is int
               and ((value['term_attempted'] is True and type(value['stop_signal']) is int and value['stop_signal'] == 15)
                    or (value['term_attempted'] is False and value['stop_signal'] is None)), 'SEAL_ORIGINAL_NORMAL_CLOSE_UNKNOWN_NATIVE_RETAINED')
        keys(value['streams'], ['stdout', 'stderr'], 'SEAL_ONLY_TWO_SAFE_STREAM_VIEWS')
        for view in value['streams'].values():
            keys(view, ['bytes', 'complete_verified'], 'SEAL_NO_PRIVATE_STREAM_HASH_OR_BODY')
            expect(integer(view['bytes']) and view['complete_verified'] is True, 'SEAL_COMPLETE_PRIVATE_STREAM_RAM_PROOF')
        expect(type(value['waits']) is list, 'SEAL_ORIGINAL_NATIVE_WAITS_LIST')
        for wait in value['waits']:
            keys(wait, ['pid', 'status'], 'SEAL_NATIVE_WAIT_ALLOWLIST')
            expect(integer(wait['pid'], 2) and integer(wait['status']), 'SEAL_ORIGINAL_NATIVE_WAIT_VALUES')
    def channel(value):
        keys(value, ['native', 'group', 'members', 'listener'], 'SEAL_EXACT_SAFE_CHANNEL_VIEW')
        group(value['group']); native(value['native'], value['group']['leader_pid'], True)
        expect(type(value['members']) is list and len(value['members']) == 2, 'SEAL_ORIGINAL_MASTER_AND_WORKER')
        pids = []
        for member in value['members']:
            keys(member, ['pid', 'ppid', 'pgid', 'sid', 'starttime'], 'SEAL_NO_PROC_PRIVATE_EXPANSION')
            expect(integer(member['pid'], 2) and integer(member['ppid'], 1)
                   and member['pgid'] == member['sid'] == value['group']['leader_pid']
                   and type(member['starttime']) is str and re.fullmatch('[0-9]+', member['starttime']), 'SEAL_ORIGINAL_CHANNEL_MEMBER_IDENTITIES')
            pids.append(member['pid'])
        expect(len(set(pids)) == 2 and value['group']['leader_pid'] in pids, 'SEAL_TWO_DISTINCT_CHANNEL_NATIVE_MEMBERS')
        listener = value['listener']; keys(listener, ['address', 'port', 'inode', 'owner_pids'], 'SEAL_SAFE_LISTENER_ALLOWLIST')
        expect(listener['address'] == '127.0.0.1' and type(listener['port']) is int and listener['port'] == 40003
               and type(listener['inode']) is str and re.fullmatch('[1-9][0-9]*', listener['inode'])
               and type(listener['owner_pids']) is list and listener['owner_pids'] == sorted(set(listener['owner_pids']))
               and listener['owner_pids'] and all(type(pid) is int and pid in pids for pid in listener['owner_pids']),
               'SEAL_REAL_LOOPBACK_LISTENER_WITH_ORIGINAL_OWNER_PIDS')
    seal = json.loads(raws['evidence/legacy-history-producer-result.json'])
    keys(seal, ['schema', 'status', 'run_id', 'source_sha', 'legacy_source_sha', 'freeze', 'producer', 'http',
        'identities', 'business_frames', 'normal_close', 'expected_m4m', 'seal_redis_time'], 'SEAL_ADOPTED_EXACT_THIRTEEN')
    expect(type(seal['schema']) is int and seal['schema'] == 1 and seal['status'] == 'LEGACY_NORMAL_HISTORY_COMPLETE_ONLY'
           and seal['run_id'] == context['run_id'] and seal['source_sha'] == context['source_sha']
           and seal['legacy_source_sha'] == LEGACY_SOURCE, 'SEAL_ORIGINAL_RUN_AND_DUAL_SOURCE')
    descriptor(seal['freeze'], 'preparation-source-binding.json')
    producer = seal['producer']; keys(producer, ['container_id', 'created', 'source_binding', 'control_files', 'native_parent'], 'SEAL_EXACT_PRODUCER_FIVE')
    created = json.loads(raws['evidence/legacy-history-producer-created.json'])
    expect(producer['container_id'] == created['id'], 'SEAL_ORIGINAL_PRODUCER_CID')
    descriptor(producer['created'], 'evidence/audit-legacy-history-producer-container-created.json')
    descriptor(producer['source_binding'], 'evidence/audit-legacy-history-producer-source-binding.json')
    frozen = json.loads(raws['preparation-source-binding.json'])
    expect(producer['control_files'] == frozen['nonempty_history']['control_files'] == LEGACY_HANDOFF_BINDINGS['control_files']
           and len(producer['control_files']) == 11, 'SEAL_COMPLETE_QUALIFIED_CONTROL11')
    parent = producer['native_parent']; keys(parent, ['pid', 'ppid', 'pgid', 'sid', 'starttime'], 'SEAL_SAFE_NATIVE_PARENT_FIVE')
    expect(integer(parent['pid'], 1) and integer(parent['ppid']) and integer(parent['pgid'], 1) and integer(parent['sid'], 1)
           and type(parent['starttime']) is str and re.fullmatch('[0-9]+', parent['starttime']), 'SEAL_ORIGINAL_NATIVE_PARENT')
    http = seal['http']; keys(http, ['schema', 'scope', 'run_id', 'legacy_source_sha', 'buyer_uid', 'admin_id', 'product_id', 'sku_id',
        'refund_family', 'review_family', 'refund', 'review', 'http_steps', 'business_acceptance', 'native_children_close_and_independent_zero'], 'SEAL_ORIGINAL_HTTP_SAFE_RESULT')
    expect(type(http['schema']) is int and http['schema'] == 1 and http['scope'] == 'legacy_http_ids_and_observed_states_only'
           and http['run_id'] == context['run_id'] and http['legacy_source_sha'] == LEGACY_SOURCE
           and all(integer(http[key], 1) for key in ['buyer_uid', 'admin_id', 'product_id', 'sku_id'])
           and http['business_acceptance'] == 'NOT SIGNED'
           and http['native_children_close_and_independent_zero'] == 'REQUIRED_BY_OUTER_PRODUCER', 'SEAL_ORIGINAL_HTTP_IDENTITY_AND_ACCEPTANCE_BOUNDARY')
    expect(type(http['http_steps']) is list and len(http['http_steps']) == 37, 'SEAL_COMPLETE_THIRTY_SEVEN_ORIGINAL_REQUESTS')
    for step, name in zip(http['http_steps'], LEGACY_HTTP_STEPS):
        keys(step, ['step', 'http'], 'SEAL_ONLY_ORIGINAL_HTTP_SAFE_STEP')
        expect(step['step'] == name and type(step['http']) is int and step['http'] == 200, 'SEAL_ORIGINAL_THIRTY_SEVEN_ORDER')
    identities = seal['identities']; keys(identities, ['refund_family', 'review_family', 'refund', 'review'], 'SEAL_INDEPENDENT_IDENTITIES_FOUR')
    for name in identities:
        expect(legacy_seal_equal(identities[name], http[name]), 'SEAL_EXPECTED_IDENTITY_CROSSCHECK_NOT_HTTP_ROW_SELECTION')
    for name, status, refund_status in [('refund_family', -2, 2), ('review_family', 3, 0)]:
        row = identities[name]; keys(row, ['id', 'order_no', 'order_unique', 'item_unique', 'cart_id', 'paid', 'status', 'refund_status'], 'SEAL_ROOT_ORIGINAL_EXACT_EIGHT')
        expect(integer(row['id'], 1) and integer(row['cart_id'], 1) and type(row['order_no']) is str and row['order_no']
               and hexadecimal(row['order_unique'], 32) and hexadecimal(row['item_unique'], 32)
               and type(row['paid']) is int and row['paid'] == 1 and type(row['status']) is int and row['status'] == status
               and type(row['refund_status']) is int and row['refund_status'] == refund_status, 'SEAL_ORIGINAL_ROOT_FINAL_NATIVE_IDENTITIES')
    refund, review = identities['refund'], identities['review']
    keys(refund, ['id', 'order_no', 'root_id', 'refund_type'], 'SEAL_REFUND_ORIGINAL_FOUR')
    keys(review, ['id', 'root_id', 'status', 'is_reply'], 'SEAL_REVIEW_ORIGINAL_FOUR')
    expect(integer(refund['id'], 1) and type(refund['order_no']) is str and refund['order_no']
           and refund['root_id'] == identities['refund_family']['id'] and type(refund['refund_type']) is int and refund['refund_type'] == 6
           and integer(review['id'], 1) and review['root_id'] == identities['review_family']['id']
           and type(review['status']) is int and review['status'] == 1 and type(review['is_reply']) is int and review['is_reply'] == 1,
           'SEAL_ORIGINAL_REFUND_AND_APPROVED_REPLIED_REVIEW')
    expect(identities['refund_family']['id'] != identities['review_family']['id']
           and type(seal['business_frames']) is list and len(seal['business_frames']) == 4, 'SEAL_TWO_FAMILIES_AND_FOUR_ORIGINAL_FRAMES')
    for frame, sequence in zip(seal['business_frames'], [13, 19, 22, 32]):
        keys(frame, ['sequence', 'step', 'complete_original_frame_verified'], 'SEAL_ORIGINAL_FRAME_FACT_ALLOWLIST')
        expect(type(frame['sequence']) is int and frame['sequence'] == sequence and frame['step'] == LEGACY_HTTP_STEPS[sequence - 1]
               and frame['complete_original_frame_verified'] is True, 'SEAL_ACTUAL_ORIGINAL_ACCEPTED_FRAME_FACT')
    close = seal['normal_close']; keys(close, ['private_native_receipt_complete_verified', 'children_views', 'no_extra_view', 'files_complete_verified', 'only_parent_and_no_listeners_verified'], 'SEAL_NORMAL_CLOSE_EXACT_FIVE')
    expect(all(close[key] is True for key in ['private_native_receipt_complete_verified', 'files_complete_verified', 'only_parent_and_no_listeners_verified']), 'SEAL_SOURCE_QUALIFIED_PRIVATE_COMPLETE_CLOSE_PROOF')
    keys(close['children_views'], ['http', 'channel'], 'SEAL_ONLY_TWO_ORIGINAL_CHILDREN')
    for value in close['children_views'].values():
        closed(value)
    expect(len({parent['pid'], close['children_views']['http']['group']['leader_pid'], close['children_views']['channel']['group']['leader_pid']}) == 3,
           'SEAL_ORIGINAL_THREE_DISTINCT_NATIVE_LEADERS')
    quiet = close['no_extra_view']; keys(quiet, ['schema', 'scope', 'run_id', 'quiet_ns', 'started_ns', 'ended_ns', 'zero_returns', 'http_closed', 'channel_before', 'channel_after', 'subscriber'], 'SEAL_ORIGINAL_NO_EXTRA_SAFE_ELEVEN')
    expect(type(quiet['schema']) is int and quiet['schema'] == 1 and quiet['scope'] == 'nonempty_history_original_channel_finite_no_extra'
           and quiet['run_id'] == context['run_id'] and type(quiet['quiet_ns']) is int and quiet['quiet_ns'] == 10000000000
           and integer(quiet['started_ns'], 1) and integer(quiet['ended_ns'], quiet['started_ns'] + quiet['quiet_ns'])
           and integer(quiet['zero_returns'], 1), 'SEAL_ORIGINAL_SAME_SOCKET_COMPLETE_PASSIVE_WINDOW')
    keys(quiet['http_closed'], ['group', 'first_terminal', 'proc_close_exit', 'remaining_members', 'listener_open'], 'SEAL_NO_EXTRA_ORIGINAL_HTTP_NATIVE_VIEW')
    for key in quiet['http_closed']:
        expect(legacy_seal_equal(quiet['http_closed'][key], close['children_views']['http'][key]), 'SEAL_NO_EXTRA_SAME_ALREADY_CLOSED_HTTP')
    channel(quiet['channel_before']); channel(quiet['channel_after'])
    expect(legacy_seal_equal(quiet['channel_before'], quiet['channel_after'])
           and legacy_seal_equal(quiet['channel_before']['group'], close['children_views']['channel']['group']), 'SEAL_NO_CHANNEL_RESTART_OR_REPLACEMENT')
    subscriber = quiet['subscriber']; keys(subscriber, ['socket_id', 'local_endpoint', 'remote_endpoint', 'metadata_before', 'metadata_after'], 'SEAL_ORIGINAL_SUBSCRIBER_FIVE')
    expect(integer(subscriber['socket_id'], 1) and type(subscriber['local_endpoint']) is str
           and re.fullmatch(r'127\.0\.0\.1:[1-9][0-9]{0,4}', subscriber['local_endpoint'])
           and subscriber['remote_endpoint'] == '127.0.0.1:40003', 'SEAL_ORIGINAL_LOOPBACK_SUBSCRIBER')
    for key in ['metadata_before', 'metadata_after']:
        value = subscriber[key]; keys(value, ['unread_bytes', 'eof', 'blocked', 'timed_out'], 'SEAL_ONLY_SAFE_STREAM_METADATA')
        expect(type(value['unread_bytes']) is int and value['unread_bytes'] == 0
               and all(value[field] is False for field in ['eof', 'blocked', 'timed_out']), 'SEAL_NO_BUFFERED_BYTES_EOF_OR_EXTRA_FRAME')
    snapshot = seal['expected_m4m']; keys(snapshot, ['names', 'tables', 'redis', 'modes'], 'SEAL_COMPLETE_ORIGINAL_M4M_SHAPE')
    names = snapshot['names']
    expect(type(names) is list and len(names) == 164 and all(type(name) is str and re.fullmatch(r'(?:eb_[a-z0-9_]+|audit_sentinel)', name) for name in names)
           and names == sorted(set(names)) and type(snapshot['tables']) is dict and list(snapshot['tables']) == names,
           'SEAL_COMPLETE_ORIGINAL_SORTED164_TABLES')
    for table in snapshot['tables'].values():
        keys(table, ['metadata', 'ddl_sha256', 'projection', 'projected_columns'], 'SEAL_M4M_FULL_TABLE_FOUR')
        keys(table['metadata'], ['table', 'columns', 'indexes', 'constraints', 'checks', 'foreign', 'triggers'], 'SEAL_ORIGINAL_COMPLETE_NATIVE_METADATA')
        keys(table['projection'], ['rows', 'sha256'], 'SEAL_ORIGINAL_FULL_ROW_PROJECTION')
        expect(hexadecimal(table['ddl_sha256']) and integer(table['projection']['rows']) and hexadecimal(table['projection']['sha256'])
               and type(table['projected_columns']) is list and table['projected_columns']
               and type(table['metadata']['columns']) is dict and list(table['metadata']['columns']) == table['projected_columns'],
               'SEAL_NATIVE_ROWS_DDL_AND_COMPLETE_COLUMN_ORDER')
    expect(type(snapshot['redis']) is dict and list(snapshot['redis']) == sorted(snapshot['redis']), 'SEAL_COMPLETE_HASHED_REDIS_NAMESPACE_ORDER')
    for key, value in snapshot['redis'].items():
        keys(value, ['sha256', 'expires'], 'SEAL_NO_RAW_REDIS_KEY_OR_VALUE')
        expect(hexadecimal(key) and hexadecimal(value['sha256']) and type(value['expires']) is int
               and (value['expires'] == -1 or value['expires'] > 0), 'SEAL_ORIGINAL_ABSOLUTE_REDIS_DEADLINES')
    expect(type(snapshot['modes']) is list and len(snapshot['modes']) == 1, 'SEAL_UNIQUE_NATIVE_SQL_MODE_ROW')
    keys(snapshot['modes'][0], ['session_mode', 'global_mode', 'session_innodb', 'global_innodb'], 'SEAL_COMPLETE_ORIGINAL_SQL_MODES')
    clock = seal['seal_redis_time']
    expect(type(clock) is list and len(clock) == 2 and all(type(value) is str and re.fullmatch('[0-9]+', value) for value in clock)
           and int(clock[0]) > 0 and 0 <= int(clock[1]) < 1000000, 'SEAL_ORIGINAL_ATOMIC_REDIS_TIME_NOT_A_NEW_TTL')
    return seal


def legacy_producer_complete(context, archived=False):
    frozen = legacy_handoff_source(context)
    raws = legacy_producer_public(context, archived); root = Path(context['run_dir']); evidence = root / 'evidence'
    expect(json.loads(raws['actual-run-context.json']) == context
           and raws['preparation-source-binding.json'] == (root / 'preparation-source-binding.json').read_bytes()
           and len(raws['preparation-source-binding.json']) == context['freeze_bytes']
           and digest(raws['preparation-source-binding.json']) == context['freeze_sha256'], 'PRODUCER_ORIGINAL_FULL_CONTEXT_FREEZE')
    role = 'audit-legacy-history-producer'; before = json.loads(raws['evidence/legacy-history-producer-created.json'])
    terminal = json.loads(raws['evidence/legacy-history-producer-terminal.json'])
    expect(before == json.loads(raws['evidence/' + role + '-container-created.json'])
           and before == bound_created(context, 'legacy-history-producer', role)
           and terminal['status'] == 'exited' and terminal['pid'] == 0 and terminal['exit_code'] == 0
           and terminal['id'] == before['id'], 'PRODUCER_SAME_ORIGINAL_NATIVE_TERMINAL_ZERO')
    same_created(before, terminal); mounts = legacy_producer_mounts(context, before)
    binding = json.loads(raws['evidence/' + role + '-source-binding.json'])
    expect(binding == {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'legacy_source_sha': LEGACY_SOURCE, 'role': role, 'container_id': before['id'],
        'authority_sha256': digest((json.dumps(frozen['nonempty_history'], ensure_ascii=False, separators=(',', ':')) + '\n').encode()),
        'mounts': mounts}, 'PRODUCER_ORIGINAL_COMPLETE_BINDING_AND_EIGHT_MOUNTS')
    manifest = json.loads(raws['manifest.json']); completed = legacy_files_complete(context)
    expect(set(manifest['services']) == {role, 'audit-mysql', 'audit-redis'}
           and manifest['run_id'] == context['run_id'] and manifest['commit_sha'] == context['source_sha']
           and manifest['services'][role]['id'] == before['id']
           and all(manifest['services'][name]['id'] == cid for name, cid in context['initial_service_ids'].items())
           and manifest['network'] == {'id': context['network_id'], 'name': context['project'] + '_isolation'}
           and manifest['network_internal'] is True and json.loads(raws['network.json']) == context['initial_network']
           and [json.loads(line) for line in raws['volumes.jsonl'].splitlines()] == context['initial_volumes']
           and raws['volumes.txt'].decode().splitlines() == sorted(row['name'] for row in context['initial_volumes']),
           'PRODUCER_ARCHIVED_ORIGINAL_THREE_ROLES_NETWORK_SIX_VOLUMES')
    collected = [json.loads(line) for line in raws['containers.jsonl'].splitlines()]
    expect(len(collected) == 3 and {row['id'] for row in collected} == {row['id'] for row in manifest['services'].values()}
           and len({row['id'] for row in collected}) == 3, 'PRODUCER_COMPLETE_ORIGINAL_THREE_NATIVE_RESOURCE_OBSERVATIONS')
    history = manifest['nonempty_history']; expect(set(history) == {'schema', 'stages'} and history['schema'] == 1
           and set(history['stages']) == {'audit-legacy-files-bootstrap', role}
           and history['stages']['audit-legacy-files-bootstrap'] == completed['stage_pins'], 'PRODUCER_ORIGINAL_FILES_STAGE_UNCHANGED')
    for key, suffix in [('created', '-container-created.json'), ('binding', '-source-binding.json')]:
        leaf = 'evidence/' + role + suffix; raw = raws[leaf]
        expect(history['stages'][role][key] == {'path': '/run/audit/' + leaf, 'bytes': len(raw), 'sha256': digest(raw)}, 'PRODUCER_ORIGINAL_REGISTERED_STAGE_FULL_PINS')
    helper_before = json.loads(raws['evidence/register-legacy-history-producer-created.json'])
    helper_terminal = json.loads(raws['evidence/register-legacy-history-producer-terminal.json'])
    registration = json.loads(raws['evidence/register-legacy-history-producer-result.json'])
    expect(helper_before == bound_created(context, 'register-legacy-history-producer', 'audit-bootstrap')
           and helper_terminal['status'] == 'exited' and helper_terminal['exit_code'] == helper_terminal['pid'] == 0
           and helper_terminal['id'] == helper_before['id'] and registration['status'] == 'REGISTERED_NATIVE_RESOURCES_ONLY'
           and registration['native_exit'] == 0 and registration['run_id'] == context['run_id']
           and registration['source_sha'] == context['source_sha']
           and registration['binding']['container_id'] == helper_before['id']
           and registration['binding']['freeze_sha256'] == context['freeze_sha256']
           and registration['binding']['created_sha256'] == digest(raws['evidence/register-legacy-history-producer-created.json']),
           'PRODUCER_ACTUAL_NORMAL_REGISTRATION_NOT_BUSINESS_PROOF')
    same_created(helper_before, helper_terminal)
    seal = legacy_producer_seal(context, raws)
    if archived:
        removals = [load(path) for path in sorted(evidence.glob('native-*.json'))
                    if load(path).get('stage') == 'legacy-history-producer-exact-nonforce-remove']
        expect(len(removals) == 1 and removals[0]['native_exit'] == 0 and removals[0]['stdout_bytes'] == 65
               and removals[0]['stdout_sha256'] == digest((before['id'] + '\n').encode())
               and removals[0]['stderr_bytes'] == 0 and removals[0]['stderr_sha256'] == digest(b''),
               'PRODUCER_ONE_ORIGINAL_ORDINARY_SAME_CID_REMOVE')
        expect(before['id'] not in active(context), 'PRODUCER_RETIRED_NOT_RECREATED')
    else:
        collect(context, base_roles(context) | {before['id']: role}, publish=False)
        fresh = inspect(context, before['id'], 'legacy-producer-complete-native-zero'); owned(context, fresh, role); same_created(before, fresh)
        expect(fresh['status'] == 'exited' and fresh['pid'] == fresh['exit_code'] == 0, 'PRODUCER_ORIGINAL_CURRENT_NATIVE_ZERO')
        expect(all(fresh[key] == terminal[key] for key in ['started_at', 'finished_at', 'status', 'pid', 'exit_code']), 'PRODUCER_SAME_ACTUAL_TERMINAL_NOT_REPLACEMENT')
    return {'container_id': before['id'], 'seal': seal}


def legacy_four_cli_handoff(context, result):
    completed = legacy_producer_complete(context, True); seal = completed['seal']
    root = Path(context['run_dir']); evidence = root / 'evidence'
    driver = bound_created(context, 'driver', 'audit-ms4-migration')
    original_binding = load(evidence / 'ms4-migration-source-binding.json')
    frozen = load(root / 'preparation-source-binding.json')
    expect(original_binding == {'schema': 1, 'run_id': context['run_id'], 'source_sha': context['source_sha'],
        'requested_repo_source': context['repo_dir'], 'requested_run_source': context['run_dir'],
        'source_files': frozen['driver_source_files'],
        'entry_sha256': next(row['sha256'] for row in frozen['driver_source_files'] if row['path'] == DRIVER[3]),
        'cli_sha256': next(row['sha256'] for row in frozen['driver_source_files'] if row['path'] == DRIVER[-1])}
        and load(evidence / 'ms4-migration-container-created.json') == driver, 'FOUR_CLI_ORIGINAL_FULL_EIGHT_SOURCE_BINDING')
    binding = result['binding']
    expect(binding['container_id'] == driver['id'] and binding['source_sha'] == context['source_sha']
           and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] > 0
           and binding['entry_sha256'] == original_binding['entry_sha256'] and binding['cli_sha256'] == original_binding['cli_sha256']
           and binding['binding_sha256'] == digest((evidence / 'ms4-migration-source-binding.json').read_bytes())
           and binding['native_created_sha256'] == digest((evidence / 'ms4-migration-container-created.json').read_bytes()), 'FOUR_CLI_ACTUAL_SAME_NATIVE_ENTRY_AND_SOURCE')
    expect(type(result['original_table_count']) is int and result['original_table_count'] == 164
           and set(result['snapshots']) == {'before', 'after'}
           and legacy_seal_equal(result['snapshots']['before'], seal['expected_m4m']), 'FOUR_CLI_BEFORE_STRICTLY_EQUALS_INDEPENDENT_ACCEPTED37_SEAL')
    before, after = result['snapshots']['before'], result['snapshots']['after']
    expect(after['names'] == sorted(before['names'] + ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'])
           and legacy_seal_equal(before['redis'], after['redis']) and legacy_seal_equal(before['modes'], after['modes']), 'FOUR_CLI_ONLY_TWO_NEW_TABLES_AND_NO_CACHE_MODE_CHANGE')
    children = result['children']
    expect(len(children) == 4 and [row['mode'] for row in children] == ['check', 'apply-structure', 'check', 'apply-structure']
           and len({row['pid'] for row in children}) == 4, 'FOUR_CLI_EXACT_ORIGINAL_ORDER_AND_DISTINCT_NATIVE_PIDS')
    for child in children:
        value = child['result']
        expect(type(child['pid']) is int and child['pid'] > 0 and type(child['native_exit']) is int and child['native_exit'] == 0
               and type(child['proc_close_exit']) is int and type(child['stdout_bytes']) is int and child['stdout_bytes'] > 0
               and re.fullmatch('[a-f0-9]{64}', child['stdout_sha256']) and child['stderr_bytes'] == 0
               and child['stderr_sha256'] == digest(b'') and value['mode'] == child['mode']
               and value['native_exit'] == 0 and value['source_sha256'] == original_binding['cli_sha256'], 'FOUR_CLI_COMPLETE_ORIGINAL_NATIVE_AND_STDOUT_JSON_FACTS')
    check, apply, repeat_check, repeat_apply = [row['result'] for row in children]
    expect(check['state'] == 'applicable' and check['changed'] is False and type(check['pending_steps']) is list and len(check['pending_steps']) == 13
           and apply['state'] == 'completed' and apply['changed'] is True and apply['applied_steps'] == check['pending_steps'] and apply['pending_steps'] == []
           and all(row['state'] == 'completed' and row['changed'] is False and row['applied_steps'] == row['pending_steps'] == [] for row in [repeat_check, repeat_apply]),
           'FOUR_CLI_ORIGINAL_THIRTEEN_THEN_IDEMPOTENT_ZERO_SEMANTICS')
    additions = {'eb_store_order': ['marketplace_trade_version', 'merchant_name_snapshot', 'merchant_after_sale_contact_snapshot'],
        'eb_store_order_cart_info': ['mer_id'], 'eb_store_order_refund': ['mer_id', 'marketplace_refund_mode'], 'eb_store_product_reply': ['mer_id']}
    expect(result['target_original_rows'] == {name: before['tables'][name]['projection']['rows'] for name in additions}
           and result['legacy_added_field_anomalies'] == {name + '.' + field: 0 for name, fields in additions.items() for field in fields}, 'FOUR_CLI_ALL_OLD_TARGET_ROWS_AND_SEVEN_ADDED_FIELDS_RETAINED')
    for name in before['names']:
        expect(legacy_seal_equal(before['tables'][name]['projection'], after['tables'][name]['projection'])
               and legacy_seal_equal(before['tables'][name]['projected_columns'], after['tables'][name]['projected_columns']), 'FOUR_CLI_COMPLETE_ORIGINAL_ROW_PROJECTION_AND_COLUMN_ORDER')
    return completed
