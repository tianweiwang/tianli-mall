<?php

$listeningPorts = [];

foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $socketTable) {
    $rows = @file($socketTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($rows, 1) as $row) {
        $fields = preg_split('/\s+/', trim($row));
        if (count($fields) < 4 || $fields[3] !== '0A') {
            continue;
        }

        $localAddress = $fields[1];
        $separator = strrpos($localAddress, ':');
        if ($separator !== false) {
            $listeningPorts[hexdec(substr($localAddress, $separator + 1))] = true;
        }
    }
}

$missingPorts = array_values(array_filter(
    [9000, 40001, 40002, 40003],
    static function (int $port) use ($listeningPorts): bool {
        return !isset($listeningPorts[$port]);
    }
));

$failures = [];
if ($missingPorts) {
    $failures[] = 'listeners ' . implode(',', $missingPorts);
}

try {
    @require_once '/usr/local/bin/marketplace-startup-check.php';
    $schemaFailure = marketplaceSchemaStartupFailure();
    if ($schemaFailure !== null) {
        $failures[] = $schemaFailure;
    }
} catch (Throwable $exception) {
    $failures[] = 'SCHEMA_UNAVAILABLE';
}

$config = @parse_ini_file('/var/www/.env', true, INI_SCANNER_RAW);
if (!is_array($config)) {
    $failures[] = 'application config';
} else {
    try {
        $database = $config['DATABASE'] ?? [];
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $database['HOSTNAME'] ?? '',
                (int) ($database['HOSTPORT'] ?? 3306),
                $database['DATABASE'] ?? '',
                $database['CHARSET'] ?? 'utf8mb4'
            ),
            $database['USERNAME'] ?? '',
            $database['PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
        );
        $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $exception) {
        $failures[] = 'MySQL';
    }

    try {
        $redisConfig = $config['REDIS'] ?? [];
        $redis = new Redis();
        if (!$redis->connect(
            $redisConfig['REDIS_HOSTNAME'] ?? '',
            (int) ($redisConfig['PORT'] ?? 6379),
            2
        )) {
            throw new RuntimeException('Redis connection failed');
        }

        $redisPassword = (string) ($redisConfig['REDIS_PASSWORD'] ?? '');
        if ($redisPassword !== '' && !$redis->auth($redisPassword)) {
            throw new RuntimeException('Redis authentication failed');
        }

        $redis->select((int) ($redisConfig['SELECT'] ?? 0));
        $redis->ping();
        $redis->close();
    } catch (Throwable $exception) {
        $failures[] = 'Redis';
    }
}

if ($failures) {
    fwrite(STDERR, 'Failed health checks: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
