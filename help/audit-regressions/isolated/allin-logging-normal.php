<?php
declare(strict_types=1);

// UNIT-STUB only. Run in a network-none, read-only PHP container; no App::initialize(), database or provider calls.
// php allin-logging-normal.php [--app-root=/var/www]; default is this repository's crmeb directory.
namespace crmeb\services {
    /** HTTP transport substitute: responses and calls stay in this process. */
    final class HttpService
    {
        public static string $response = '';
        public static array $calls = [];

        public static function request($url, $method = 'get', $data = [], $header = false, $timeout = 15)
        {
            self::$calls[] = compact('url', 'method', 'data', 'header', 'timeout');
            return self::$response;
        }
    }
}

namespace {
    use crmeb\services\HttpService;
    use crmeb\services\pay\extend\allinpay\AllinPay;
    use crmeb\services\pay\extend\allinpay\Client;

    $phase = 'bootstrap';
    ob_start();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException('Normal unit check failed', 0, $severity, $file, $line);
    });
    function normal_check(bool $condition): void
    {
        if (!$condition) throw new \RuntimeException('Normal unit check failed');
    }
    try {
        normal_check(PHP_SAPI === 'cli' && count($argv) <= 2);
        $appRoot = dirname(__FILE__, 4) . '/crmeb';
        if (isset($argv[1])) {
            normal_check(str_starts_with($argv[1], '--app-root=') && strlen($argv[1]) > 11);
            $appRoot = substr($argv[1], 11);
        }
        $appRoot = realpath($appRoot);
        normal_check(is_string($appRoot) && is_file($appRoot . '/vendor/autoload.php'));
        require $appRoot . '/vendor/autoload.php';
        require_once $appRoot . '/vendor/topthink/framework/src/helper.php';

        final class AllinNormalMemoryLog implements \think\contract\LogHandlerInterface
        {
            public static array $records = [];
            public function __construct(array $config = []) {}
            public function save(array $log): bool
            {
                foreach ($log as $level => $messages) foreach ($messages as $message) {
                    self::$records[] = ['level' => $level, 'message' => $message];
                }
                return true;
            }
        }
        $app = new \think\App($appRoot);
        $app->config->set(['default' => 'unit', 'level' => ['debug', 'info', 'notice', 'warning', 'error',
            'critical', 'alert', 'emergency', 'sql'], 'type_channel' => [],
            'channels' => ['unit' => ['type' => '\\' . AllinNormalMemoryLog::class, 'realtime_write' => true]]], 'log');
        // Prove debug is observable, then isolate the actual production-event observations.
        $app->log->debug('normal_unit_debug_capture');
        normal_check(AllinNormalMemoryLog::$records === [['level' => 'debug', 'message' => 'normal_unit_debug_capture']]);
        AllinNormalMemoryLog::$records = [];
        $sourceHashes = [];
        foreach ([Client::class => 'crmeb/services/pay/extend/allinpay/Client.php',
            AllinPay::class => 'crmeb/services/pay/extend/allinpay/AllinPay.php',
            \crmeb\services\RuntimeLog::class => 'crmeb/services/RuntimeLog.php'] as $class => $relative) {
            normal_check((new \ReflectionClass($class))->getFileName() === realpath($appRoot . '/' . $relative));
            $sourceHashes[$relative] = hash_file('sha256', $appRoot . '/' . $relative);
        }
        $markers = [];
        foreach (['key', 'app', 'merchant', 'request', 'header', 'response', 'order', 'body', 'transaction', 'callback'] as $name) {
            $markers[$name] = 'unit_' . $name . '_' . bin2hex(random_bytes(16));
        }
        $config = ['appid' => $markers['app'], 'cusid' => $markers['merchant'],
            'privateKey' => $markers['key'], 'publicKey' => '', 'isBeta' => true];
        $client = new Client($config);
        $phase = 'json_success';
        $big = '18446744073709551615';
        $decoded = ['retcode' => 'SUCCESS', 'large_integer' => $big, 'unit_marker' => $markers['response']];
        $json = json_encode($decoded, JSON_THROW_ON_ERROR);
        HttpService::$response = str_replace('"' . $big . '"', $big, $json, $replaced);
        normal_check($replaced === 1);
        $data = ['unit_marker' => $markers['request']];
        $headers = ['X-Unit-Marker' => $markers['header']];
        $result = $client->request('unitorder/pay', $data, 'post', $headers, 7);
        normal_check($result === $decoded && is_string($result['large_integer'])
            && HttpService::$calls === [['url' => Client::BETA_API_URL . 'unitorder/pay', 'method' => 'post',
                'data' => $data, 'header' => ['X-Unit-Marker:' . $markers['header']], 'timeout' => 7]]);

        $phase = 'signed_send';
        $response = ['retcode' => 'SUCCESS', 'trxstatus' => '0000', 'trxid' => $markers['transaction'],
            'unit_marker' => $markers['response']];
        $response['sign'] = $client->sign($response);
        HttpService::$response = json_encode($response, JSON_THROW_ON_ERROR);
        $sendData = ['reqsn' => $markers['order'], 'trxamt' => 1234, 'body' => $markers['body']];
        normal_check($client->send('unitorder/pay', ['data' => $sendData, 'header' => $headers]) === $response);
        normal_check(count(HttpService::$calls) === 2);
        $call = HttpService::$calls[1];
        normal_check($call['url'] === Client::BETA_API_URL . 'unitorder/pay' && $call['method'] === 'post'
            && $call['header'] === ['X-Unit-Marker:' . $markers['header']] && $call['timeout'] === 10);
        $sent = $call['data'];
        normal_check(array_intersect_key($sent, $sendData) === $sendData && count($sent) === count($sendData) + 6
            && $sent['appid'] === $markers['app'] && $sent['cusid'] === $markers['merchant']
            && $sent['version'] === '11' && $sent['signtype'] === 'MD5'
            && is_string($sent['randomstr']) && preg_match('/\A[a-f0-9]{13}\z/D', $sent['randomstr']) === 1
            && $client->validSign($sent) === true);

        $phase = 'signed_callback';
        $allin = new AllinPay($config);
        $params = ['appid' => $markers['app'], 'cusid' => $markers['merchant'], 'trxstatus' => '0000',
            'cusorderid' => $markers['order'], 'trxid' => $markers['transaction'], 'unit_marker' => $markers['callback']];
        $params['sign'] = $allin->sign($params);
        $app->instance('request', (new \app\Request())->withPost($params));
        normal_check(request() instanceof \app\Request && request()->post() === $params);
        $callbackCount = 0;
        $result = $allin->handleNotify(static function (array $actual) use ($params, &$callbackCount): bool {
            ++$callbackCount;
            normal_check($actual === $params);
            return true;
        });
        normal_check($result === 'success' && $callbackCount === 1 && count(HttpService::$calls) === 2);

        $phase = 'finite_logs';
        $expected = ['payment.allin_response_parsed', 'payment.allin_response_parsed',
            'payment.allin_callback_received', 'payment.allin_callback_verified'];
        normal_check(count(AllinNormalMemoryLog::$records) === count($expected));
        $ids = [];
        foreach (AllinNormalMemoryLog::$records as $index => $record) {
            normal_check($record['level'] === 'info' && is_string($record['message']));
            $event = json_decode($record['message'], true, 8, JSON_THROW_ON_ERROR);
            $keys = array_keys($event); sort($keys);
            normal_check($keys === ['diagnostic_id', 'event', 'request_id', 'source_kind']
                && $event['event'] === $expected[$index] && $event['source_kind'] === 'console' && $event['request_id'] === null
                && is_string($event['diagnostic_id']) && preg_match('/\A[a-f0-9]{32}\z/D', $event['diagnostic_id']) === 1
                && !isset($ids[$event['diagnostic_id']]));
            $ids[$event['diagnostic_id']] = true;
        }
        $rawLogs = json_encode(AllinNormalMemoryLog::$records, JSON_THROW_ON_ERROR);
        $needles = array_unique([...array_values($markers), $big, $response['sign'], $params['sign'], $sent['sign'], $sent['randomstr']]);
        foreach ($needles as $needle) normal_check(!str_contains($rawLogs, $needle));
        normal_check(ob_get_contents() === '');
        ob_end_clean(); restore_error_handler();
        echo json_encode(['status' => 'PASS', 'scope' => 'UNIT-STUB: real upper classes; synthetic transport and memory log writer',
            'cases' => 3, 'transport_calls' => 2, 'callback_calls' => 1, 'bigint_preserved_as_string' => true,
            'debug_capture_verified' => true, 'events' => array_count_values($expected), 'closed_log_records' => 4,
            'secret_scan' => ['candidates' => count($needles), 'matches' => 0, 'log_sha256' => hash('sha256', $rawLogs)],
            'source_sha256' => $sourceHashes, 'probe_sha256' => hash_file('sha256', __FILE__), 'real_provider_calls' => 0,
            'not_covered' => ['curl/TLS', 'RSA', 'real provider callback', 'business payment persistence', 'fault/retry samples']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (\Throwable $error) {
        while (ob_get_level() > 0) ob_end_clean();
        restore_error_handler();
        echo json_encode(['status' => 'FAIL', 'scope' => 'UNIT-STUB', 'phase' => $phase,
            'category' => 'NORMAL_UNIT_CHECK_FAILED', 'error_class' => get_class($error), 'line' => $error->getLine()],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(1);
    }
}
