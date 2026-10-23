
/** Full private bytes from only the original runtime leaves, bound to that same complete tree/stat9. */
function nhep_close_private_file(#[SensitiveParameter] array $runtime, string $leaf): array
{
    nhep_expect(in_array($leaf, ['legacy-http.stdout', 'legacy-http.stderr', 'legacy-channel.stdout',
        'legacy-channel.stderr', 'channel/workerman.log'], true), 'NHE_CLOSE_ONLY_ORIGINAL_NATIVE_PRIVATE_LEAVES');
    $row = $runtime[$leaf] ?? null; $path = '/legacy-runtime/' . $leaf;
    nhep_expect(is_array($row) && ($row['kind'] ?? null) === 'file' && is_array($row['stat'] ?? null),
        'NHE_CLOSE_COMPLETE_ORIGINAL_PRIVATE_FILE_ROW');
    nhep_auth_files_stat($row['stat']); clearstatcache(true, $path); $before = lstat($path);
    nhep_expect(is_array($before) && nhep_file_stat($before) === $row['stat'] && !is_link($path)
        && realpath($path) === $path && $row['stat']['nlink'] === 1,
        'NHE_CLOSE_SAME_NATIVE_PRIVATE_FILE_BEFORE_FULL_READ');
    $raw = nhe_file($path, ['path' => $path, 'bytes' => $row['bytes'], 'sha256' => $row['sha256']]);
    clearstatcache(true, $path); $after = lstat($path);
    nhep_expect(is_array($after) && !is_link($path) && realpath($path) === $path
        && nhep_file_stat($after) === $row['stat'], 'NHE_CLOSE_SAME_NATIVE_PRIVATE_FILE_AFTER_FULL_READ');
    return ['path' => $path, 'stat' => $row['stat'], 'bytes' => $row['bytes'], 'sha256' => $row['sha256'], 'raw' => $raw];
}

/** Complete BEFORE plus only the fixed vendor's normal pid unlink and exact two native log messages. */
function nhep_normal_close_files_expected(#[SensitiveParameter] array $before7,
    #[SensitiveParameter] array $beforePrivate, #[SensitiveParameter] array $beforeLog,
    #[SensitiveParameter] array $actualFiles, #[SensitiveParameter] array $afterStreams,
    #[SensitiveParameter] array $afterLog, #[SensitiveParameter] array $closed, array $channelWindow): array
{
    nheh_snapshot($before7);
    nhe_keys($actualFiles, ['private', 'public', 'logs'], 'NHE_CLOSE_COMPLETE_FIVE_TREE_OBSERVATION_KEYS');
    nhe_keys($closed, ['http', 'channel'], 'NHE_CLOSE_BOTH_ORIGINAL_NATIVE_RECEIPTS');
    nhep_expect(array_is_list($channelWindow) && count($channelWindow) === 2
        && is_int($channelWindow[0]) && is_int($channelWindow[1]) && $channelWindow[0] > 0
        && $channelWindow[0] <= $channelWindow[1] && $channelWindow[1] <= 4294967295,
        'NHE_CLOSE_ACTUAL_CHANNEL_TERM_TO_FINAL_WALL_WINDOW');
    $before = $before7['logs']['runtime']; $actual = $actualFiles['logs']['runtime'];
    nhep_expect(is_array($before) && is_array($actual), 'NHE_CLOSE_ORIGINAL_COMPLETE_RUNTIME_TREES');
    nhep_auth_files_tree($before); nhep_auth_files_tree($actual);
    nhep_auth_files_streams($beforePrivate, $before);
    nhep_auth_files_streams(['native_streams' => $afterStreams], $actual);
    $expected = ['private' => $before7['private'], 'public' => $before7['public'], 'logs' => $before7['logs']];
    nhep_expect($actualFiles['private'] === $expected['private'] && $actualFiles['public'] === $expected['public']
        && $actualFiles['logs']['legacy_root_runtime'] === $expected['logs']['legacy_root_runtime'],
        'NHE_CLOSE_BOTH_PRIVATE_PUBLIC_AND_OLD_ROOT_RUNTIME_COMPLETE_PRESERVATION');
    $master = $before7['native']['channel']['pid'];
    nhep_expect(is_int($master) && $master > 1 && $closed['channel']['group']['leader_pid'] === $master
        && $closed['channel']['first_terminal']['pid'] === $master
        && $closed['channel']['stopped'] === true && $closed['http']['stopped'] === true,
        'NHE_CLOSE_SAME_ORIGINAL_CHANNEL_MASTER_AND_BOTH_STOPPED_CHILDREN');
    $channelPaths = array_values(array_filter(array_keys($before), static fn(string $path): bool =>
        $path === 'channel' || str_starts_with($path, 'channel/')));
    nhep_expect($channelPaths === ['channel', 'channel/workerman.log', 'channel/workerman.pid']
        && $before['channel']['kind'] === 'directory' && $before['channel']['stat']['mode'] === (0040000 | 0700)
        && $before['channel/workerman.log']['stat']['mode'] === (0100000 | 0622),
        'NHE_CLOSE_ORIGINAL_NONDAMON_CHANNEL_RUNTIME_ONLY_LOG_AND_PID');
    $pid = $before['channel/workerman.pid']; $pidRaw = (string)$master;
    nhep_expect($pid['kind'] === 'file' && $pid['stat']['mode'] === (0100000 | 0600)
        && $pid['stat']['uid'] === $before['channel']['stat']['uid'] && $pid['stat']['gid'] === $before['channel']['stat']['gid']
        && $pid['bytes'] === strlen($pidRaw) && $pid['sha256'] === hash('sha256', $pidRaw),
        'NHE_CLOSE_ORIGINAL_COMPLETE_MASTER_PID_FILE_NO_NEWLINE');
    $wanted = array_values(array_filter(array_keys($before), static fn(string $path): bool => $path !== 'channel/workerman.pid'));
    nhep_expect(array_keys($actual) === $wanted, 'NHE_CLOSE_COMPLETE_RUNTIME_NAMESPACE_ONLY_ORIGINAL_PID_UNLINK');
    unset($expected['logs']['runtime']['channel/workerman.pid']);
    foreach (['http' => ['stdout', 'stderr'], 'channel' => ['stderr']] as $kind => $names)
        foreach ($names as $name) nhep_expect($afterStreams[$kind][$name] === $beforePrivate['native_streams'][$kind][$name],
            'NHE_CLOSE_HTTP_OUTPUT_AND_CHANNEL_STDERR_COMPLETE_ORIGINAL_BYTES_AND_STAT9');
    foreach (['before' => $beforeLog, 'after' => $afterLog] as $phase => $log) {
        $tree = $phase === 'before' ? $before : $actual;
        nhe_keys($log, ['path', 'stat', 'bytes', 'sha256', 'raw'], 'NHE_CLOSE_PRIVATE_WORKERMAN_LOG_FULL_READBACK_KEYS');
        nhep_expect($log['path'] === '/legacy-runtime/channel/workerman.log' && is_string($log['raw'])
            && strlen($log['raw']) === $log['bytes'] && hash('sha256', $log['raw']) === $log['sha256']
            && $tree['channel/workerman.log'] === ['kind' => 'file', 'stat' => $log['stat'],
                'bytes' => $log['bytes'], 'sha256' => $log['sha256']],
            'NHE_CLOSE_PRIVATE_WORKERMAN_LOG_SAME_COMPLETE_TREE_AND_BODY');
    }
    $stopping = 'Workerman[legacy-history-channel.php] stopping ...';
    $stopped = 'Workerman[legacy-history-channel.php] has been stopped';
    $stdoutExpected = $beforePrivate['native_streams']['channel']['stdout']['raw'] . $stopping . "\n" . $stopped . "\n";
    nhep_expect($afterStreams['channel']['stdout']['raw'] === $stdoutExpected,
        'NHE_CLOSE_CHANNEL_STDOUT_COMPLETE_PREFIX_AND_EXACT_TWO_ORIGINAL_MESSAGES');
    nhep_expect(str_starts_with($afterLog['raw'], $beforeLog['raw']), 'NHE_CLOSE_WORKERMAN_LOG_ORIGINAL_COMPLETE_PREFIX');
    $suffix = substr($afterLog['raw'], strlen($beforeLog['raw']));
    $date = '([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})';
    nhep_expect(preg_match('~\A' . $date . ' pid:' . $master . ' ' . preg_quote($stopping, '~') . "\n"
        . $date . ' pid:' . $master . ' ' . preg_quote($stopped, '~') . "\n\\z~D", $suffix, $matches) === 1,
        'NHE_CLOSE_WORKERMAN_LOG_EXACT_TWO_SAME_ORIGINAL_MASTER_LINES');
    $epochs = [];
    foreach ([$matches[1], $matches[2]] as $stamp) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, new DateTimeZone('UTC'));
        nhep_expect($parsed instanceof DateTimeImmutable && DateTimeImmutable::getLastErrors() === false,
            'NHE_CLOSE_ORIGINAL_CHANNEL_UTC_VALID_NATIVE_DATE');
        $epoch = $parsed->getTimestamp();
        nhep_expect($epoch >= $channelWindow[0] && $epoch <= $channelWindow[1]
            && gmdate('Y-m-d H:i:s', $epoch) === $stamp, 'NHE_CLOSE_EACH_CHANNEL_LOG_DATE_WITHIN_ACTUAL_TERM_WALL_WINDOW');
        $epochs[] = $epoch;
    }
    nhep_expect($epochs[0] <= $epochs[1], 'NHE_CLOSE_TWO_NORMAL_CHANNEL_MESSAGES_SOURCE_ORDER');
    $logExpected = $beforeLog['raw'] . gmdate('Y-m-d H:i:s', $epochs[0]) . ' pid:' . $master . ' ' . $stopping . "\n"
        . gmdate('Y-m-d H:i:s', $epochs[1]) . ' pid:' . $master . ' ' . $stopped . "\n";
    nhep_expect($afterLog['raw'] === $logExpected, 'NHE_CLOSE_INDEPENDENT_COMPLETE_LOG_EXPECTED_BYTES');
    $append = static function (array $old, array $observed, #[SensitiveParameter] string $expectedRaw)
        use ($channelWindow): array {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhep_expect($observed['stat'][$key] === $old['stat'][$key], 'NHE_CLOSE_APPEND_SAME_ORIGINAL_FILE_IDENTITY_MODE_OWNER_LINK');
        nhep_expect($observed['stat']['size'] === strlen($expectedRaw) && strlen($expectedRaw) > $old['bytes'],
            'NHE_CLOSE_APPEND_EXACT_SOURCE_EXPECTED_NATIVE_SIZE');
        foreach (['mtime', 'ctime'] as $key)
            nhep_expect($observed['stat'][$key] >= $old['stat'][$key] && $observed['stat'][$key] >= $channelWindow[0]
                && $observed['stat'][$key] <= $channelWindow[1], 'NHE_CLOSE_APPEND_NATIVE_TIMES_IN_ACTUAL_CHANNEL_TERM_WINDOW');
        $stat = $old['stat'];
        foreach (['size', 'mtime', 'ctime'] as $key) $stat[$key] = $observed['stat'][$key];
        return ['kind' => 'file', 'stat' => $stat, 'bytes' => strlen($expectedRaw), 'sha256' => hash('sha256', $expectedRaw)];
    };
    $expected['logs']['runtime']['legacy-channel.stdout'] = $append($before['legacy-channel.stdout'], $actual['legacy-channel.stdout'], $stdoutExpected);
    $expected['logs']['runtime']['channel/workerman.log'] = $append($before['channel/workerman.log'], $actual['channel/workerman.log'], $logExpected);
    $oldDirectory = $before['channel']['stat']; $newDirectory = $actual['channel']['stat'];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
        nhep_expect($newDirectory[$key] === $oldDirectory[$key], 'NHE_CLOSE_UNLINK_SAME_ORIGINAL_CHANNEL_DIRECTORY_AND_LINKS');
    nhep_expect($newDirectory['size'] <= $oldDirectory['size'], 'NHE_CLOSE_UNLINK_KERNEL_DIRECTORY_SIZE_CANNOT_GROW');
    foreach (['mtime', 'ctime'] as $key)
        nhep_expect($newDirectory[$key] >= $oldDirectory[$key] && $newDirectory[$key] >= $channelWindow[0]
            && $newDirectory[$key] <= $channelWindow[1], 'NHE_CLOSE_ONLY_PID_UNLINK_DIRECTORY_TIME_IN_ACTUAL_TERM_WINDOW');
    foreach (['size', 'mtime', 'ctime'] as $key) $expected['logs']['runtime']['channel']['stat'][$key] = $newDirectory[$key];
    nhep_expect($actualFiles === $expected, 'NHE_CLOSE_COMPLETE_FIVE_TREES_EQUAL_INDEPENDENT_NORMAL_CLOSE_MODEL');
    return $expected;
}

/** Actual original normal close order and the independent full stopped-writer baseline. Private RAM only. */
function nhep_normal_close_writers(#[SensitiveParameter] array $qualified, PDO $observer, Redis $redis,
    array &$children, #[SensitiveParameter] array $accepted37): array
{
    $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
    nhep_expect($previousIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHE_CLOSE_REAL_PRIVATE_EXCEPTION_ARGUMENT_BOUNDARY');
    nheh_snapshot($accepted37); nhep_observation_parent($qualified); $runId = $qualified['context']['run_id'];
    $beforePrivate = []; $before7 = nhep_observation($qualified, $observer, $redis, $children, $beforePrivate);
    $beforeExpected = $accepted37;
    $beforeExpected['redis'] = nhep_redis_expected($accepted37['redis'], [], $before7['redis']['time']);
    nhep_expect($before7 === $beforeExpected, 'NHE_CLOSE_FULL37_ACCEPTED_TO_LAST_LIVE_BEFORE_ONLY_NATURAL_EXPIRY_JOIN');
    $beforeLog = nhep_close_private_file($before7['logs']['runtime'], 'channel/workerman.log');
    $statistics = '/tmp/_run_nonempty_legacy-history-channel.php.status';
    clearstatcache(true, $statistics);
    nhep_expect(!file_exists($statistics) && !is_link($statistics), 'NHE_CLOSE_NO_UNMODELED_WORKERMAN_STATISTICS_FILE_BEFORE_TERM');
    $httpStarted = time(); $httpClosed = nhep_child_close($children, 'http'); $httpEnded = time();
    nhep_expect($httpEnded >= $httpStarted && ($httpClosed['stopped'] ?? null) === true,
        'NHE_CLOSE_ORIGINAL_HTTP_COMPLETE_BEFORE_CHANNEL_QUIET');
    $noExtra = nhep_channel_no_extra($children);
    $state =& $children['channel']['subscriber']['state']; nhep_channel_close($state); unset($state);
    $channelStarted = time(); $channelClosed = nhep_child_close($children, 'channel');
    nhep_expect(($channelClosed['stopped'] ?? null) === true, 'NHE_CLOSE_ORIGINAL_CHANNEL_NORMAL_GROUP_STOPPED');
    $closed = nhep_children_closed($children); $channelEnded = time();
    nhep_expect($channelEnded >= $channelStarted && $closed['http'] === $httpClosed && $closed['channel'] === $channelClosed,
        'NHE_CLOSE_SAME_ORIGINAL_BOTH_NATIVE_CLOSE_RECEIPTS_AND_REAL_WINDOW');
    clearstatcache(true, $statistics);
    nhep_expect(!file_exists($statistics) && !is_link($statistics), 'NHE_CLOSE_NO_UNMODELED_WORKERMAN_STATISTICS_FILE_AFTER_TERM');
    $actualFiles = ['private' => ['app' => nhep_file_tree('/run/audit-app'), 'fixtures' => nhep_file_tree('/run/audit-fixtures')],
        'public' => ['legacy_public' => nhep_file_tree('/legacy-workspace/crmeb/public')],
        'logs' => ['runtime' => nhep_file_tree('/legacy-runtime'),
            'legacy_root_runtime' => nhep_file_tree('/legacy-workspace/crmeb/runtime')]];
    $afterStreams = [];
    foreach (['http', 'channel'] as $kind) foreach (['stdout', 'stderr'] as $name) {
        $readback = nhep_close_private_file($actualFiles['logs']['runtime'], 'legacy-' . $kind . '.' . $name);
        $stream = $children[$kind]['streams'][$name];
        nhep_expect($stream['handle'] === null && $stream['complete'] === $closed[$kind]['streams'][$name]
            && $stream['complete'] === ['bytes' => $readback['bytes'], 'sha256' => $readback['sha256']],
            'NHE_CLOSE_FULL_PRIVATE_READBACK_EQUALS_ORIGINAL_OWNED_HANDLE_CLOSED_STREAM');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhep_expect($readback['stat'][$key] === $stream['created'][$key], 'NHE_CLOSE_FINAL_STREAM_SAME_ORIGINAL_CREATED_NATIVE_IDENTITY');
        $afterStreams[$kind][$name] = $readback;
    }
    $afterLog = nhep_close_private_file($actualFiles['logs']['runtime'], 'channel/workerman.log');
    $expectedFiles = nhep_normal_close_files_expected($before7, $beforePrivate, $beforeLog, $actualFiles,
        $afterStreams, $afterLog, $closed, [$channelStarted, $channelEnded]);
    $actualDB2 = nhep_database_capture($observer, $runId); $actualRedis = nhep_redis_capture($redis, $runId);
    $expectedDB2 = ['database' => $accepted37['database'], 'rows' => $accepted37['rows']];
    $expectedRedis = nhep_redis_expected($accepted37['redis'], [], $actualRedis['snapshot']['time']);
    nhep_expect($actualDB2 === $expectedDB2, 'NHE_CLOSE_STOPPED_WRITERS_FULL_164_DATABASE_EQUALS_ORIGINAL_ACCEPTED37');
    nhep_expect($actualRedis['snapshot'] === $expectedRedis, 'NHE_CLOSE_STOPPED_WRITERS_FULL_REDIS_ONLY_ORIGINAL_NATURAL_EXPIRY');
    $expectedM4m = nhep_migration_snapshot_expected($expectedDB2, $expectedRedis);
    nhep_children_closed($children); nhep_parent_qualified($qualified); nhep_observation_parent($qualified);
    // This return includes private body digests/raws and original receipts. It must never be serialized to Run.
    return ['scope' => 'original_full37_normal_writers_closed_independent_baseline_only',
        'closed_receipts' => $closed, 'no_extra_receipt' => $noExtra, 'files' => $expectedFiles,
        'private_native_streams' => $afterStreams, 'private_workerman_log' => $afterLog,
        'database' => $expectedDB2, 'redis' => $expectedRedis, 'expected_m4m' => $expectedM4m,
        'wall_windows' => ['http' => [$httpStarted, $httpEnded], 'channel' => [$channelStarted, $channelEnded]]];
}

