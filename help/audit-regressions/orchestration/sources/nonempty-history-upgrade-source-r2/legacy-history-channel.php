<?php
declare(strict_types=1);

/**
 * Source component only: PHP 8.2 lint and native execution NOT RUN.
 * One genuine old-477 Channel server; no application, business publisher,
 * subscriber, WebSocket worker, resource registration or shutdown authority.
 * The parent owns same-handle/group/socket observations, complete private
 * native streams, real business delivery and its sole normal TERM/retirement.
 * nhe_child_qualify() is supplied by the shared, frozen legacy-app.php; the
 * overall producer remains refused until its complete wiring is qualified.
 */
function nhe_channel_main(): int
{
    try {
        ini_set('display_errors', '0'); ini_set('log_errors', '0');
        ini_set('zend.exception_ignore_args', '1'); umask(0077);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new RuntimeException('NHE_CHANNEL_PHP_RUNTIME_WARNING');
        });
        if (PHP_SAPI !== 'cli' || PHP_INT_SIZE < 8 || PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80300
            || __FILE__ !== '/run/nonempty/legacy-history-channel.php'
            || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__
            || getcwd() !== '/legacy-workspace/crmeb') {
            throw new RuntimeException('NHE_CHANNEL_REAL_DIRECT_OLD_ROOT_ENTRY');
        }
        global $argv; $arguments = $_SERVER['argv'] ?? null;
        if (!is_array($arguments) || $argv !== $arguments || !array_is_list($arguments) || count($arguments) !== 4
            || $arguments[0] !== __FILE__ || $arguments[1] !== 'start'
            || preg_match('/\A--freeze-bytes=([1-9][0-9]{0,9})\z/D', $arguments[2], $bytes) !== 1
            || preg_match('/\A--freeze-sha256=([a-f0-9]{64})\z/D', $arguments[3], $sha) !== 1) {
            throw new RuntimeException('NHE_CHANNEL_EXACT_REAL_START_AND_FREEZE_ARGUMENTS');
        }
        // No old or current application/vendor may already be present. The
        // shared qualification below loads only its closed pure control files.
        if (spl_autoload_functions() !== [] || function_exists('app')) {
            throw new RuntimeException('NHE_CHANNEL_NO_PRELOADED_VENDOR');
        }
        foreach (get_declared_classes() as $class) {
            if (preg_match('/\A(?:app|think|crmeb|Channel|Workerman|Composer)\\\\/i', $class)
                || str_starts_with($class, 'ComposerAutoloaderInit')) {
                throw new RuntimeException('NHE_CHANNEL_NO_PRELOADED_APPLICATION_CLASS');
            }
        }
        require_once __DIR__ . '/legacy-app.php';
        nhe_expect(function_exists('nhe_child_qualify'), 'NHE_CHANNEL_SHARED_CHILD_QUALIFIER_REQUIRED');
        $freezePin = ['path' => '/run/audit/preparation-source-binding.json',
            'bytes' => (int)$bytes[1], 'sha256' => $sha[1]];
        $state = nhe_child_qualify($freezePin, 'channel');
        nhe_expect(is_array($state) && is_array($state['authority'] ?? null)
            && ($state['authority']['legacy_source_sha'] ?? null) === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
            && is_array($state['authority']['legacy_source_files'] ?? null), 'NHE_CHANNEL_REAL_QUALIFIED_STATE');
        nhe_expect(spl_autoload_functions() === [] && !function_exists('app'), 'NHE_CHANNEL_QUALIFIER_DID_NOT_LOAD_APP');
        foreach (get_declared_classes() as $class) nhe_expect(
            preg_match('/\A(?:app|think|crmeb|Channel|Workerman|Composer)\\\\/i', $class) !== 1
            && !str_starts_with($class, 'ComposerAutoloaderInit'), 'NHE_CHANNEL_QUALIFIER_PURE_CONTROL_ONLY');

        // This child has no need to read a private environment or any secret.
        // Its only Composer is the qualified complete old source namespace.
        require_once '/legacy-workspace/crmeb/vendor/autoload.php';
        $files = array_column($state['authority']['legacy_source_files'], null, 'path');
        $classes = [
            'Channel\\Server' => 'crmeb/vendor/workerman/channel/src/Server.php',
            'Workerman\\Worker' => 'crmeb/vendor/workerman/workerman/Worker.php',
            'Workerman\\Protocols\\Frame' => 'crmeb/vendor/workerman/workerman/Protocols/Frame.php',
            'Workerman\\Events\\Select' => 'crmeb/crmeb/compat/workerman/Events/Select.php',
        ];
        foreach ($classes as $class => $relative) {
            nhe_expect(isset($files[$relative]), 'NHE_CHANNEL_CLASS_IN_COMPLETE_OLD_FREEZE');
            $path = '/legacy-workspace/' . $relative; $reflection = new ReflectionClass($class);
            nhe_expect($reflection->getFileName() === $path && realpath($path) === $path && !is_link($path),
                'NHE_CHANNEL_ACTUAL_OLD_CLASS_SOURCE');
            nhe_file($path, array_replace($files[$relative], ['path' => $path]));
        }
        nhe_expect(\Workerman\Worker::getAllWorkers() === [], 'NHE_CHANNEL_NO_PREEXISTING_OTHER_WORKER');
        nhe_includes($state);

        $runtime = '/legacy-runtime'; clearstatcache(true, $runtime); $root = lstat($runtime);
        nhe_expect(is_array($root) && is_dir($runtime) && !is_link($runtime) && realpath($runtime) === $runtime
            && $root['uid'] === posix_geteuid(), 'NHE_CHANNEL_OWN_QUALIFIED_RUNTIME_TMPFS');
        $directory = $runtime . '/channel';
        nhe_expect(!file_exists($directory) && !is_link($directory) && mkdir($directory, 0700),
            'NHE_CHANNEL_NEW_OWN_RUNTIME_DIRECTORY');
        clearstatcache(true, $directory); $stat = lstat($directory);
        nhe_expect(is_array($stat) && is_dir($directory) && !is_link($directory) && realpath($directory) === $directory
            && ($stat['mode'] & 0777) === 0700 && $stat['uid'] === posix_geteuid(), 'NHE_CHANNEL_PRIVATE_RUNTIME_IDENTITY');
        \Workerman\Worker::$daemonize = false;
        \Workerman\Worker::$pidFile = $directory . '/workerman.pid';
        \Workerman\Worker::$logFile = $directory . '/workerman.log';
        \Workerman\Worker::$stdoutFile = $directory . '/workerman.stdout';
        $server = new \Channel\Server('127.0.0.1', 40003);
        $workers = \Workerman\Worker::getAllWorkers();
        nhe_expect(count($workers) === 1, 'NHE_CHANNEL_ONLY_REAL_SERVER_WORKER');
        $worker = reset($workers);
        nhe_expect($worker instanceof \Workerman\Worker && $worker->name === 'ChannelServer' && $worker->count === 1
            && \Workerman\Worker::$daemonize === false, 'NHE_CHANNEL_ORIGINAL_SERVER_ONE_WORKER');
        nhe_includes($state);
        // Keep the original four arguments. Workerman reads argv[1] as start;
        // the real freeze flag in argv[2] is neither -d nor another command.
        // runAll owns the genuine master/worker loop and normal signal handling.
        // Native vendor output is retained privately by the parent, never used
        // here as a PASS, ready acknowledgement or event-delivery substitute.
        \Workerman\Worker::runAll();
        return 0;
    } catch (Throwable $error) {
        $class = get_class($error);
        if (preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\z/D', $class) !== 1) $class = 'Throwable';
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'code' => 'NHE_CHANNEL_ENTRY_FAILED', 'class' => $class],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(nhe_channel_main());
