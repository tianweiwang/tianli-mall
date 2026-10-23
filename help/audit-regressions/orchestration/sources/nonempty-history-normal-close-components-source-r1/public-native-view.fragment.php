
/** The complete original native receipts remain private; only this explicit Source contract is public. */
function nhep_normal_close_public_view(#[SensitiveParameter] array $qualified, array &$children,
    #[SensitiveParameter] array $close): array
{
    $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
    nhep_expect($previousIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHE_CLOSE_PUBLIC_VIEW_REAL_PRIVATE_EXCEPTION_ARGUMENT_BOUNDARY');
    nhep_observation_parent($qualified);
    nhep_expect(($close['scope'] ?? null) === 'original_full37_normal_writers_closed_independent_baseline_only',
        'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_COMPLETE_PRIVATE_SCOPE');
    $actualClosed = nhep_children_closed($children);
    nhep_expect($actualClosed === $close['closed_receipts'], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_NATIVE_RECEIPTS_STILL_COMPLETE');
    $groupView = static function (array $group): array {
        nhe_keys($group, ['leader_pid', 'pgid', 'sid', 'leader_starttime'], 'NHE_CLOSE_PUBLIC_GROUP_FIXED_FOUR_NATIVE_FIELDS');
        foreach (['leader_pid', 'pgid', 'sid'] as $key)
            nhep_expect(is_int($group[$key]) && $group[$key] > 1, 'NHE_CLOSE_PUBLIC_GROUP_NATIVE_PID');
        nhep_expect($group['leader_pid'] === $group['pgid'] && $group['pgid'] === $group['sid']
            && is_string($group['leader_starttime']) && ctype_digit($group['leader_starttime'])
            && $group['leader_starttime'] !== '', 'NHE_CLOSE_PUBLIC_ORIGINAL_SETSID_GROUP');
        return ['leader_pid' => $group['leader_pid'], 'pgid' => $group['pgid'], 'sid' => $group['sid'],
            'leader_starttime' => $group['leader_starttime']];
    };
    $terminalView = static function (array $terminal): array {
        nhe_keys($terminal, ['pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'],
            'NHE_CLOSE_PUBLIC_TERMINAL_FIXED_SEVEN_NATIVE_FIELDS');
        nhep_expect(is_int($terminal['pid']) && $terminal['pid'] > 1 && is_bool($terminal['running'])
            && is_bool($terminal['signaled']) && is_bool($terminal['stopped']) && is_int($terminal['exitcode'])
            && is_int($terminal['termsig']) && is_int($terminal['stopsig']), 'NHE_CLOSE_PUBLIC_TERMINAL_NATIVE_TYPES');
        return ['pid' => $terminal['pid'], 'running' => $terminal['running'], 'signaled' => $terminal['signaled'],
            'stopped' => $terminal['stopped'], 'exitcode' => $terminal['exitcode'],
            'termsig' => $terminal['termsig'], 'stopsig' => $terminal['stopsig']];
    };
    $views = [];
    foreach (['http', 'channel'] as $kind) {
        $receipt = $actualClosed[$kind]; $child = $children[$kind];
        nhe_keys($receipt, ['stopped', 'group', 'remaining_members', 'listener_open', 'first_terminal',
            'term_attempted', 'stop_signal', 'proc_close_exit', 'streams', 'waits'],
            'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_TEN_KEY_PRIVATE_RECEIPT');
        nhep_expect($receipt === $child['closed'] && $child['process'] === null && $receipt['stopped'] === true
            && $receipt['remaining_members'] === [] && $receipt['listener_open'] === false
            && is_bool($receipt['term_attempted']) && in_array($receipt['stop_signal'], [null, SIGTERM], true)
            && is_int($receipt['proc_close_exit']) && is_array($receipt['waits']) && array_is_list($receipt['waits']),
            'NHE_CLOSE_PUBLIC_VIEW_ACTUAL_COMPLETE_NATIVE_CLOSE_AND_UNALTERED_EXIT_FACTS');
        nhep_expect($receipt['first_terminal'] === $child['first_terminal']
            && $receipt['first_terminal']['running'] === false && $receipt['first_terminal']['termsig'] !== SIGKILL,
            'NHE_CLOSE_PUBLIC_VIEW_SAME_FIRST_NATIVE_TERMINAL_NO_ESCALATION');
        nhe_keys($receipt['streams'], ['stdout', 'stderr'], 'NHE_CLOSE_PUBLIC_VIEW_BOTH_ORIGINAL_PRIVATE_STREAMS');
        $streamViews = [];
        foreach (['stdout', 'stderr'] as $name) {
            $stream = $child['streams'][$name]; $raw = $close['private_native_streams'][$kind][$name];
            nhe_keys($receipt['streams'][$name], ['bytes', 'sha256'], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_PRIVATE_STREAM_DESCRIPTOR');
            nhe_keys($raw, ['path', 'stat', 'bytes', 'sha256', 'raw'], 'NHE_CLOSE_PUBLIC_VIEW_COMPLETE_PRIVATE_STREAM_READBACK');
            nhep_expect($stream['handle'] === null && $stream['complete'] === $receipt['streams'][$name]
                && is_string($raw['raw']) && $raw['bytes'] === strlen($raw['raw'])
                && $raw['sha256'] === hash('sha256', $raw['raw'])
                && $stream['complete'] === ['bytes' => $raw['bytes'], 'sha256' => $raw['sha256']]
                && ($close['files']['logs']['runtime']['legacy-' . $kind . '.' . $name] ?? null)
                    === ['kind' => 'file', 'stat' => $raw['stat'], 'bytes' => $raw['bytes'], 'sha256' => $raw['sha256']],
                'NHE_CLOSE_PUBLIC_VIEW_FULL_PRIVATE_BODY_AND_ORIGINAL_HANDLE_RECEIPT_VERIFIED_FIRST');
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
                nhep_expect($raw['stat'][$key] === $stream['created'][$key], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_CREATED_STREAM_IDENTITY');
            $streamViews[$name] = ['bytes' => $raw['bytes'], 'complete_verified' => true];
        }
        $waitViews = [];
        foreach ($receipt['waits'] as $wait) {
            nhe_keys($wait, ['pid', 'status'], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_WAIT_NATIVE_TWO_FIELDS');
            nhep_expect(is_int($wait['pid']) && $wait['pid'] > 1 && is_int($wait['status']),
                'NHE_CLOSE_PUBLIC_VIEW_ACTUAL_NATIVE_WAIT_TYPES');
            $waitViews[] = ['pid' => $wait['pid'], 'status' => $wait['status']];
        }
        $views[$kind] = ['stopped' => $receipt['stopped'], 'group' => $groupView($receipt['group']),
            'remaining_members' => $receipt['remaining_members'], 'listener_open' => $receipt['listener_open'],
            'first_terminal' => $terminalView($receipt['first_terminal']), 'term_attempted' => $receipt['term_attempted'],
            'stop_signal' => $receipt['stop_signal'], 'proc_close_exit' => $receipt['proc_close_exit'],
            'streams' => $streamViews, 'waits' => $waitViews];
    }
    $quiet = $close['no_extra_receipt'];
    nhe_keys($quiet, ['schema', 'scope', 'run_id', 'quiet_ns', 'started_ns', 'ended_ns', 'zero_returns',
        'http_closed', 'channel_before', 'channel_after', 'subscriber'], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_FULL_NO_EXTRA_KEYS');
    nhep_expect($quiet['schema'] === 1 && $quiet['scope'] === 'nonempty_history_original_channel_finite_no_extra'
        && $quiet['run_id'] === $qualified['context']['run_id'] && $quiet['quiet_ns'] === 10000000000
        && is_int($quiet['started_ns']) && is_int($quiet['ended_ns']) && $quiet['started_ns'] > 0
        && $quiet['ended_ns'] - $quiet['started_ns'] >= $quiet['quiet_ns']
        && is_int($quiet['zero_returns']) && $quiet['zero_returns'] > 0,
        'NHE_CLOSE_PUBLIC_VIEW_ACTUAL_ORIGINAL_FINITE_QUIET_WINDOW');
    $httpProof = $quiet['http_closed'];
    nhe_keys($httpProof, ['group', 'first_terminal', 'proc_close_exit', 'remaining_members', 'listener_open'],
        'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_NO_EXTRA_HTTP_PROOF_KEYS');
    nhep_expect($httpProof['group'] === $actualClosed['http']['group']
        && $httpProof['first_terminal'] === $actualClosed['http']['first_terminal']
        && $httpProof['proc_close_exit'] === $actualClosed['http']['proc_close_exit']
        && $httpProof['remaining_members'] === [] && $httpProof['listener_open'] === false,
        'NHE_CLOSE_PUBLIC_VIEW_SAME_ORIGINAL_HTTP_NATIVE_CLOSE_BEFORE_NO_EXTRA');
    $safeHttp = ['group' => $groupView($httpProof['group']), 'first_terminal' => $terminalView($httpProof['first_terminal']),
        'proc_close_exit' => $httpProof['proc_close_exit'], 'remaining_members' => [], 'listener_open' => false];
    $channelViews = [];
    foreach (['channel_before', 'channel_after'] as $key) {
        $proof = $quiet[$key];
        $members = [];
        foreach ($proof['members'] as $member) {
            $native = [];
            foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $field) {
                nhep_expect(array_key_exists($field, $member), 'NHE_CLOSE_PUBLIC_VIEW_REAL_NATIVE_MEMBER_FIELD');
                $native[$field] = $member[$field];
            }
            $members[] = $native;
        }
        $owners = array_values(array_unique(array_column($proof['listener']['owners'], 'pid'))); sort($owners, SORT_NUMERIC);
        nhep_expect($proof['group'] === $actualClosed['channel']['group'] && count($members) === 2
            && $proof['listener']['address'] === '127.0.0.1' && $proof['listener']['port'] === 40003,
            'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_CHANNEL_MASTER_WORKER_AND_LISTENER');
        $channelViews[$key] = ['native' => $terminalView($proof['native']), 'group' => $groupView($proof['group']),
            'members' => $members, 'listener' => ['address' => '127.0.0.1', 'port' => 40003,
                'inode' => $proof['listener']['inode'], 'owner_pids' => $owners]];
    }
    $subscriber = $quiet['subscriber'];
    nhe_keys($subscriber, ['socket_id', 'local_endpoint', 'remote_endpoint', 'metadata_before', 'metadata_after'],
        'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_NO_EXTRA_SUBSCRIBER_KEYS');
    $safeSubscriber = ['socket_id' => $subscriber['socket_id'], 'local_endpoint' => $subscriber['local_endpoint'],
        'remote_endpoint' => $subscriber['remote_endpoint']];
    foreach (['metadata_before', 'metadata_after'] as $key) {
        $meta = $subscriber[$key];
        nhe_keys($meta, ['unread_bytes', 'eof', 'blocked', 'timed_out'], 'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_SOCKET_METADATA_FOUR_FIELDS');
        nhep_expect($meta === ['unread_bytes' => 0, 'eof' => false, 'blocked' => false, 'timed_out' => false],
            'NHE_CLOSE_PUBLIC_VIEW_ORIGINAL_UNBUFFERED_NONBLOCKING_NONEOF_SOCKET');
        $safeSubscriber[$key] = $meta;
    }
    $safeQuiet = ['schema' => $quiet['schema'], 'scope' => $quiet['scope'], 'run_id' => $quiet['run_id'],
        'quiet_ns' => $quiet['quiet_ns'], 'started_ns' => $quiet['started_ns'], 'ended_ns' => $quiet['ended_ns'],
        'zero_returns' => $quiet['zero_returns'], 'http_closed' => $safeHttp,
        'channel_before' => $channelViews['channel_before'], 'channel_after' => $channelViews['channel_after'],
        'subscriber' => $safeSubscriber];
    nhep_observation_parent($qualified);
    return ['private_native_receipt_complete_verified' => true, 'children_views' => $views,
        'no_extra_view' => $safeQuiet, 'files_complete_verified' => true, 'only_parent_and_no_listeners_verified' => true];
}

