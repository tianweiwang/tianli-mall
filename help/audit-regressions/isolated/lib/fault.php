<?php
declare(strict_types=1);

// Test-only adapter: the underlying connection and every successful operation
// are real Redis. No production configuration loads this class.
class FaultyRedis extends Redis
{
    private array $calls = [];

    public function __construct(private Redis $delegate, private array $plan)
    {
        foreach ($plan as $fault) {
            if (!is_array($fault) || !is_string($fault['command'] ?? null)
                || !is_int($fault['occurrence'] ?? null) || $fault['occurrence'] < 1
                || !in_array($fault['when'] ?? null, ['before', 'after'], true)
                || !in_array($fault['action'] ?? null, ['throw', 'disconnect'], true)) {
                throw new InvalidArgumentException('Invalid isolated fault plan');
            }
        }
    }

    private function invoke(string $command, array $arguments): mixed
    {
        $name = strtolower($command);
        $occurrence = $this->calls[$name] = ($this->calls[$name] ?? 0) + 1;
        $this->inject($name, $occurrence, 'before');
        $result = $this->delegate->$command(...$arguments);
        $this->inject($name, $occurrence, 'after');
        return $result;
    }

    private function inject(string $command, int $occurrence, string $when): void
    {
        foreach ($this->plan as $fault) {
            if (strtolower($fault['command']) !== $command || $fault['occurrence'] !== $occurrence || $fault['when'] !== $when) {
                continue;
            }
            if ($fault['action'] === 'disconnect') { $this->delegate->close(); }
            // Deliberately omit keys, arguments, driver details and secrets.
            throw new RedisException('Injected isolated Redis failure');
        }
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->invoke('eval', [$script, $args, $num_keys]);
    }

    public function evalSha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        return $this->invoke('evalSha', [$sha1, $args, $num_keys]);
    }

    public function hGetAll(string $key): Redis|array|false { return $this->invoke('hGetAll', [$key]); }
    public function hGet(string $key, string $member): mixed { return $this->invoke('hGet', [$key, $member]); }
    public function get(string $key): mixed { return $this->invoke('get', [$key]); }
    public function set(string $key, mixed $value, mixed $options = null): Redis|string|bool { return $this->invoke('set', [$key, $value, $options]); }
    public function exists(mixed $key, mixed ...$other_keys): Redis|int|bool { return $this->invoke('exists', [$key, ...$other_keys]); }
    public function del(array|string $key, string ...$other_keys): Redis|int|false { return $this->invoke('del', [$key, ...$other_keys]); }
    public function ping(?string $message = null): Redis|string|bool { return $this->invoke('ping', [$message]); }
    public function getOption(int $option): mixed { return $this->delegate->getOption($option); }
    public function setOption(int $option, mixed $value): bool { return $this->delegate->setOption($option, $value); }
    public function isConnected(): bool { return $this->delegate->isConnected(); }
    public function close(): bool { return $this->delegate->close(); }
}

function audit_fault_redis(string $mode): void
{
    audit_expect(in_array($mode, ['pause', 'unpause', 'stop', 'start'], true), 'isolated Redis control mode rejected');
    $manifest = audit_manifest();
    $runId = getenv('AUDIT_RUN_ID');
    audit_expect(getenv('AUDIT_ISOLATED') === '1'
        && ($manifest['run_id'] ?? '') === $runId
        && ($manifest['project'] ?? '') === 'tianli-audit-' . $runId, 'isolated Redis control identity rejected');
    if ($mode === 'pause' || $mode === 'stop') {
        audit_guard_assert();
    } else {
        // Redis cannot answer while paused/stopped. Recovery still verifies the
        // manifest and database sentinel here, plus actual host identities before
        // the host acts. The complete guard is checked again after recovery.
        $config = audit_config();
        audit_expect(($config['run_id'] ?? '') === $runId && ($config['database']['username'] ?? '') === 'audit_app'
            && gethostbyname('audit-mysql') === $manifest['services']['audit-mysql']['address'], 'recovery configuration or database endpoint rejected');
        $database = audit_db();
        audit_expect($database->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $database->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [$runId]
            && audit_grants_are_restricted($database->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), 'recovery database sentinel or privileges rejected');
    }
    $request = audit_run_dir() . '/' . $mode . '-redis.request';
    $done = audit_run_dir() . '/' . $mode . '-redis.done';
    foreach ([$request, $done] as $file) { if (is_file($file)) { unlink($file); } }
    $temporary = $request . '.tmp-' . bin2hex(random_bytes(4));
    audit_expect(file_put_contents($temporary, $runId . "\n") !== false, 'control request write failed');
    chmod($temporary, 0600);
    audit_expect(rename($temporary, $request), 'control request publish failed');
    for ($attempt = 0; $attempt < 250; $attempt++) {
        $acknowledgement = @file_get_contents($done);
        if (is_string($acknowledgement) && trim($acknowledgement) === $runId) {
            if ($mode === 'unpause' || $mode === 'start') { audit_guard_assert(); }
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('isolated Redis control acknowledgement timed out');
}
