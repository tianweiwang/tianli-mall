        nhe_expect($worker->onWorkerStop === null && \Workerman\Worker::$onMasterStop === null,
            'NHE_CHANNEL_ORIGINAL_STOP_CALLBACKS_EMPTY_BEFORE_FIXED_TEST_METADATA');
        $masterPid = getmypid(); $runId = $state['context']['run_id']; $metadataWritten = false;
        nhe_expect(is_int($masterPid) && $masterPid > 1
            && ($state['child_process']['native']['pid'] ?? null) === $masterPid
            && is_string($runId) && ($state['child_process']['native']['pgid'] ?? null) === $masterPid
            && ($state['child_process']['native']['sid'] ?? null) === $masterPid,
            'NHE_CHANNEL_ORIGINAL_REAL_MASTER_IDENTITY_BEFORE_FORK');
        // Worker::stop invokes this once before unlisten or graceful destruction.
        // Capture no connection/worker object and never dispatch another signal here.
        $onStop = static function () use ($masterPid, $runId, &$metadataWritten): void {
            nhe_expect($metadataWritten === false, 'NHE_CHANNEL_SINGLE_PREDESTRUCT_NATIVE_METADATA');
            $metadataWritten = true;
            $workerPid = getmypid(); $count = \Workerman\Connection\ConnectionInterface::$statistics['connection_count'];
            nhe_expect(is_int($workerPid) && $workerPid > 1 && $workerPid !== $masterPid
                && posix_getppid() === $masterPid && posix_getpgrp() === $masterPid && posix_getsid(0) === $masterPid
                && is_int($count) && $count >= 0 && \Workerman\Worker::getGracefulStop() === true
                && pcntl_async_signals() === false && date_default_timezone_get() === 'UTC'
                && \Workerman\Worker::$eventLoopClass === '\\Workerman\\Events\\Select'
                && \Workerman\Worker::$onMasterStop === null,
                'NHE_CHANNEL_ACTUAL_PREDESTRUCT_NATIVE_COUNT_GRACEFUL_LOOP_SIGNAL_AND_TIME_BOUNDARY');
            $metadata = ['schema' => 1, 'scope' => 'channel_original_normal_stop_native_before_destruct',
                'run_id' => $runId, 'master_pid' => $masterPid, 'worker_pid' => $workerPid,
                'connection_count' => $count, 'graceful_stop' => true, 'utc_second' => time(), 'timezone' => 'UTC'];
            $line = 'NHE_CHANNEL_NORMAL_CLOSE_META ' . json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            nhe_expect(fwrite(STDERR, $line) === strlen($line) && fflush(STDERR),
                'NHE_CHANNEL_COMPLETE_PRIVATE_NATIVE_METADATA_BEFORE_VENDOR_DESTRUCTORS');
        };
        $worker->onWorkerStop = $onStop;
        nhe_expect($worker->onWorkerStop === $onStop && \Workerman\Worker::$onMasterStop === null
            && \Workerman\Worker::$eventLoopClass === '\\Workerman\\Events\\Select'
            && pcntl_async_signals() === false && date_default_timezone_get() === 'UTC',
            'NHE_CHANNEL_FIXED_SINGLE_CALLBACK_AND_ACTUAL_NORMAL_LOOP_READBACK');

