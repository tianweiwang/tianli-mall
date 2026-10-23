
/** Complete BEFORE plus only original pid unlink, fixed master messages and native-N-derived progress. */
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
    $master = $before7['native']['channel']['pid']; $proof = $beforePrivate['native_after']['channel'];
    $workers = array_values(array_filter($proof['members'], static fn(array $row): bool => $row['pid'] !== $master));
    nhep_expect(is_int($master) && $master > 1 && $proof['native'] === $before7['native']['channel']
        && count($proof['members']) === 2 && count($workers) === 1 && $workers[0]['ppid'] === $master
        && $workers[0]['pgid'] === $master && $workers[0]['sid'] === $master
        && is_int($workers[0]['pid']) && $workers[0]['pid'] > 1 && $workers[0]['pid'] !== $master
        && $closed['channel']['group'] === $proof['group'] && $proof['group']['leader_pid'] === $master
        && $closed['channel']['first_terminal']['pid'] === $master
        && $closed['channel']['stopped'] === true && $closed['http']['stopped'] === true,
        'NHE_CLOSE_SAME_ORIGINAL_CHANNEL_MASTER_WORKER_AND_BOTH_STOPPED_CHILDREN');
    $worker = $workers[0]['pid'];
    $channelPaths = array_values(array_filter(array_keys($before), static fn(string $path): bool =>
        $path === 'channel' || str_starts_with($path, 'channel/')));
    nhep_expect($channelPaths === ['channel', 'channel/workerman.log', 'channel/workerman.pid']
        && $before['channel']['kind'] === 'directory' && $before['channel']['stat']['mode'] === (0040000 | 0700)
        && $before['channel/workerman.log']['stat']['mode'] === (0100000 | 0622),
        'NHE_CLOSE_ORIGINAL_NONDAEMON_CHANNEL_RUNTIME_ONLY_LOG_AND_PID');
    $pid = $before['channel/workerman.pid']; $pidRaw = (string)$master;
    nhep_expect($pid['kind'] === 'file' && $pid['stat']['mode'] === (0100000 | 0600)
        && $pid['stat']['uid'] === $before['channel']['stat']['uid'] && $pid['stat']['gid'] === $before['channel']['stat']['gid']
        && $pid['bytes'] === strlen($pidRaw) && $pid['sha256'] === hash('sha256', $pidRaw),
        'NHE_CLOSE_ORIGINAL_COMPLETE_MASTER_PID_FILE_NO_NEWLINE');
    $wanted = array_values(array_filter(array_keys($before), static fn(string $path): bool => $path !== 'channel/workerman.pid'));
    nhep_expect(array_keys($actual) === $wanted, 'NHE_CLOSE_COMPLETE_RUNTIME_NAMESPACE_ONLY_ORIGINAL_PID_UNLINK');
    unset($expected['logs']['runtime']['channel/workerman.pid']);
    foreach (['stdout', 'stderr'] as $name)
        nhep_expect($afterStreams['http'][$name] === $beforePrivate['native_streams']['http'][$name],
            'NHE_CLOSE_HTTP_OUTPUT_COMPLETE_ORIGINAL_BYTES_AND_STAT9');
    foreach (['before' => $beforeLog, 'after' => $afterLog] as $phase => $log) {
        $tree = $phase === 'before' ? $before : $actual;
        nhe_keys($log, ['path', 'stat', 'bytes', 'sha256', 'raw'], 'NHE_CLOSE_PRIVATE_WORKERMAN_LOG_FULL_READBACK_KEYS');
        nhep_expect($log['path'] === '/legacy-runtime/channel/workerman.log' && is_string($log['raw'])
            && strlen($log['raw']) === $log['bytes'] && hash('sha256', $log['raw']) === $log['sha256']
            && $tree['channel/workerman.log'] === ['kind' => 'file', 'stat' => $log['stat'],
                'bytes' => $log['bytes'], 'sha256' => $log['sha256']],
            'NHE_CLOSE_PRIVATE_WORKERMAN_LOG_SAME_COMPLETE_TREE_AND_BODY');
    }

    // This fixed test-entry callback record precedes the first graceful destructor.
    // It is private BEFORE-effect evidence, never a count selected from progress lines.
    $oldError = $beforePrivate['native_streams']['channel']['stderr']['raw'];
    $newError = $afterStreams['channel']['stderr']['raw'];
    nhep_expect(str_starts_with($newError, $oldError), 'NHE_CLOSE_CHANNEL_STDERR_COMPLETE_ORIGINAL_PREFIX');
    $metaSuffix = substr($newError, strlen($oldError)); $prefix = 'NHE_CHANNEL_NORMAL_CLOSE_META ';
    nhep_expect(str_starts_with($metaSuffix, $prefix) && str_ends_with($metaSuffix, "\n")
        && substr_count($metaSuffix, "\n") === 1, 'NHE_CLOSE_EXACT_ONE_ORIGINAL_WORKER_PREDESTRUCT_METADATA_LINE');
    $meta = json_decode(substr($metaSuffix, strlen($prefix), -1), true, 16, JSON_THROW_ON_ERROR);
    nhep_expect(is_array($meta), 'NHE_CLOSE_PRIVATE_NORMAL_METADATA_OBJECT');
    nhe_keys($meta, ['schema', 'scope', 'run_id', 'master_pid', 'worker_pid', 'connection_count',
        'graceful_stop', 'utc_second', 'timezone'], 'NHE_CLOSE_PRIVATE_NORMAL_METADATA_CLOSED_KEYS');
    nhep_expect($meta['schema'] === 1 && $meta['scope'] === 'channel_original_normal_stop_native_before_destruct'
        && $meta['run_id'] === ($before7['rows']['audit_sentinel'][0]['run_id'] ?? null)
        && $meta['master_pid'] === $master && $meta['worker_pid'] === $worker
        && is_int($meta['connection_count']) && $meta['connection_count'] >= 0 && $meta['graceful_stop'] === true
        && is_int($meta['utc_second']) && $meta['utc_second'] >= $channelWindow[0]
        && $meta['utc_second'] <= $channelWindow[1] && $meta['timezone'] === 'UTC',
        'NHE_CLOSE_REAL_SINGLE_BEFORE_DESTRUCT_COUNT_NATIVE_IDENTITIES_RUN_GRACEFUL_AND_UTC_WINDOW');
    $metaExpected = $prefix . json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    nhep_expect($metaSuffix === $metaExpected, 'NHE_CLOSE_PRIVATE_METADATA_EXACT_CANONICAL_SOURCE_ENCODING');
    $stderrExpected = $oldError . $metaExpected;

    $progress = []; $count = $meta['connection_count'];
    if ($count > 0) {
        $mod = (int)ceil($count / 3);
        nhep_expect($mod > 0 && $mod <= $count, 'NHE_CLOSE_ORIGINAL_VENDOR_POSITIVE_MODULUS');
        $remaining = intdiv($count - 1, $mod) * $mod;
        do {
            $progress[] = 'worker[' . $worker . '] remains ' . $remaining . ' connection(s)';
            nhep_expect(count($progress) <= 3, 'NHE_CLOSE_VENDOR_COUNT_DERIVED_AT_MOST_THREE_PROGRESS_RECORDS');
            $remaining -= $mod;
        } while ($remaining >= 0);
    }
    $stopping = 'Workerman[legacy-history-channel.php] stopping ...';
    $stopped = 'Workerman[legacy-history-channel.php] has been stopped';
    $orders = [];
    for ($position = 0; $position <= count($progress); ++$position)
        $orders[] = array_merge(array_slice($progress, 0, $position), [$stopping],
            array_slice($progress, $position), [$stopped]);
    $oldOutput = $beforePrivate['native_streams']['channel']['stdout']['raw'];
    $newOutput = $afterStreams['channel']['stdout']['raw']; $stdoutPosition = null; $stdoutExpected = null;
    foreach ($orders as $position => $messages) {
        $candidate = $oldOutput . implode("\n", $messages) . "\n";
        if ($newOutput === $candidate) { $stdoutPosition = $position; $stdoutExpected = $candidate; }
    }
    nhep_expect(is_int($stdoutPosition) && is_string($stdoutExpected),
        'NHE_CLOSE_STDOUT_COMPLETE_PREFIX_EXACT_INDEPENDENT_MESSAGES_PER_PROCESS_ORDER_AND_STOPPED_LAST');
    nhep_expect(str_starts_with($afterLog['raw'], $beforeLog['raw']), 'NHE_CLOSE_WORKERMAN_LOG_ORIGINAL_COMPLETE_PREFIX');
    $suffix = substr($afterLog['raw'], strlen($beforeLog['raw'])); $logPosition = null; $logExpected = null;
    foreach ($orders as $position => $messages) {
        $pattern = '~\A';
        foreach ($messages as $message) {
            $messagePid = in_array($message, [$stopping, $stopped], true) ? $master : $worker;
            $pattern .= '([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) pid:'
                . $messagePid . ' ' . preg_quote($message, '~') . "\n";
        }
        if (preg_match($pattern . '\\z~D', $suffix, $matches) !== 1) continue;
        $lastTime = [$master => null, $worker => null]; $rebuilt = $beforeLog['raw'];
        foreach ($messages as $index => $message) {
            $stamp = $matches[$index + 1];
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, new DateTimeZone('UTC'));
            nhep_expect($parsed instanceof DateTimeImmutable && DateTimeImmutable::getLastErrors() === false,
                'NHE_CLOSE_EACH_ORIGINAL_LOG_NATIVE_UTC_DATE_VALID');
            $epoch = $parsed->getTimestamp(); $messagePid = in_array($message, [$stopping, $stopped], true) ? $master : $worker;
            nhep_expect($epoch >= $channelWindow[0] && $epoch <= $channelWindow[1]
                && gmdate('Y-m-d H:i:s', $epoch) === $stamp
                && ($lastTime[$messagePid] === null || $epoch >= $lastTime[$messagePid])
                && ($message === $stopping || $epoch >= $meta['utc_second']),
                'NHE_CLOSE_REAL_TERM_WINDOW_PER_PROCESS_TIME_ORDER_AND_WORKER_BEFORE_EFFECT_BOUNDARY');
            $lastTime[$messagePid] = $epoch;
            $rebuilt .= gmdate('Y-m-d H:i:s', $epoch) . ' pid:' . $messagePid . ' ' . $message . "\n";
        }
        $logPosition = $position; $logExpected = $rebuilt;
    }
    nhep_expect(is_int($logPosition) && is_string($logExpected) && $afterLog['raw'] === $logExpected
        && $logPosition >= max(0, $stdoutPosition - 1),
        'NHE_CLOSE_LOG_INDEPENDENT_COMPLETE_BYTES_AND_REAL_STDOUT_THEN_LOG_CROSS_FILE_PARTIAL_ORDER');
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
    $expected['logs']['runtime']['legacy-channel.stderr'] = $append($before['legacy-channel.stderr'], $actual['legacy-channel.stderr'], $stderrExpected);
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
