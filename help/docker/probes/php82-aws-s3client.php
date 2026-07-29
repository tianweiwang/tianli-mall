<?php
// PHP 8.2 diagnostic probe for the JDOSS lazy S3Client construction path.
//
// Task 3.5 requires proving how many PHP 8.2 diagnostics the real AWS SDK
// emits when crmeb\services\upload\storage\Jdoss::app() builds its S3Client,
// rather than testing only the CRMEB wrapper. Run with --network none; no
// request is ever sent because only the client object is constructed.
//
// Usage: php php82-aws-s3client.php [/path/to/vendor/autoload.php]

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$autoload = $argv[1] ?? '/var/www/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "autoload not found: {$autoload}\n");
    exit(2);
}

$diagnostics = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$diagnostics): bool {
    $names = [
        E_DEPRECATED      => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED',
        E_WARNING         => 'WARNING',
        E_USER_WARNING    => 'USER_WARNING',
        E_NOTICE          => 'NOTICE',
    ];
    $diagnostics[] = [
        'type' => $names[$no] ?? ('ERR' . $no),
        'message' => $str,
        'file' => $file,
        'line' => $line,
    ];
    return true;
});

require $autoload;

$installed = [];
$installedFile = dirname($autoload) . '/composer/installed.json';
if (is_file($installedFile)) {
    $raw = json_decode((string) file_get_contents($installedFile), true);
    $packages = $raw['packages'] ?? $raw;
    foreach ($packages as $package) {
        $installed[$package['name']] = $package['version'];
    }
}

$relevant = [
    'aws/aws-sdk-php',
    'aws/aws-crt-php',
    'guzzlehttp/guzzle',
    'guzzlehttp/psr7',
    'guzzlehttp/promises',
    'mtdowling/jmespath.php',
    'symfony/polyfill-mbstring',
];
echo "=== dependency versions ===\n";
foreach ($relevant as $name) {
    printf("  %-30s %s\n", $name, $installed[$name] ?? 'ABSENT');
}

// Build the client exactly the way the JDOSS driver does, through the driver
// itself, so the measured path is the production one.
$config = [
    'accessKey' => 'neutral-access-key',
    'secretKey' => 'neutral-secret-key',
    'uploadUrl' => 'storage.example.invalid',
    'storageName' => 'neutral-bucket',
    'storageRegion' => 'cn-north-1',
    'cdn' => '',
];

echo "=== constructing JDOSS lazy S3Client ===\n";
$isS3 = false;
$failure = null;
try {
    $driver = new crmeb\services\upload\storage\Jdoss('jdoss', $config, 'upload');
    $app = new ReflectionMethod($driver, 'app');
    $app->setAccessible(true);
    $client = $app->invoke($driver);
    $isS3 = $client instanceof Aws\S3\S3Client;
    printf("  client class: %s\n", get_class($client));
    // Exercise command/serializer construction without sending anything.
    $command = $client->getCommand('PutObject', [
        'Bucket' => 'neutral-bucket',
        'Key' => 'probe.txt',
        'Body' => 'probe',
    ]);
    printf("  command built: %s\n", $command->getName());
    $request = \Aws\Signature\SignatureProvider::resolve(
        \Aws\Signature\SignatureProvider::defaultProvider(),
        'v4',
        's3',
        'cn-north-1'
    );
    printf("  signer built: %s\n", get_class($request));
} catch (Throwable $e) {
    $failure = get_class($e) . ': ' . $e->getMessage();
    printf("  FAILED: %s\n", $failure);
}

restore_error_handler();

$unique = [];
foreach ($diagnostics as $d) {
    $key = $d['type'] . '|' . $d['message'] . '|' . $d['file'] . ':' . $d['line'];
    if (!isset($unique[$key])) {
        $unique[$key] = $d + ['count' => 0];
    }
    $unique[$key]['count']++;
}

echo "=== results ===\n";
printf("lazy_client_is_s3: %s\n", $isS3 ? 'yes' : 'no');
printf("construction_failure: %s\n", $failure ?? 'none');
printf("TOTAL_DIAGNOSTICS: %d\n", count($diagnostics));
printf("DISTINCT_DIAGNOSTICS: %d\n", count($unique));
foreach (array_values($unique) as $i => $d) {
    printf("  %2d. [%s x%d] %s\n      at %s:%d\n",
        $i + 1, $d['type'], $d['count'], $d['message'], $d['file'], $d['line']);
}

exit(count($diagnostics) === 0 && $isS3 && $failure === null ? 0 : 1);
