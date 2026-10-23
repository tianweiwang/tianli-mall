        // Test-entry lifecycle only: bind the already pinned compatible loop and
        // qualify synchronous signals before the vendor can release connections.
        nhe_expect(function_exists('pcntl_async_signals') && pcntl_async_signals() === false,
            'NHE_CHANNEL_ORIGINAL_SYNCHRONOUS_SIGNAL_DISPATCH_REQUIRED');
        nhe_expect(date_default_timezone_set('UTC') && date_default_timezone_get() === 'UTC'
            && sys_get_temp_dir() === '/tmp', 'NHE_CHANNEL_EXPLICIT_UTC_AND_ORIGINAL_STATISTICS_DIRECTORY');
        \Workerman\Worker::$eventLoopClass = '\\Workerman\\Events\\Select';
        nhe_expect(\Workerman\Worker::$eventLoopClass === '\\Workerman\\Events\\Select',
            'NHE_CHANNEL_REAL_PINNED_COMPAT_SELECT_EXPLICIT_READBACK');

