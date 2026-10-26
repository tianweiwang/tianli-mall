<?php
declare(strict_types=1);

namespace Audit\Migration;

use crmeb\exceptions\MarketplaceSchemaException as Failure;
use crmeb\services\marketplace\SchemaVersionGate as Gate;
use crmeb\services\marketplace\SchemaVersionRuntime as Runtime;
use PDO;
use Throwable;

require_once dirname(__DIR__, 2) . '/crmeb/crmeb/exceptions/MarketplaceSchemaException.php';
require_once dirname(__DIR__, 2) . '/crmeb/crmeb/services/marketplace/SchemaVersionGate.php';

/** Only this migration's singleton metadata is writable. No historical migration is invoked. */
final class MarketplaceMs6SchemaMigration
{
    private PDO $pdo;
    private string $prefix;
    private array $manifest;
    public bool $writeAttempted = false;

    public function __construct(PDO $pdo, string $prefix, array $manifest)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->manifest = Gate::descriptor($manifest);
        Gate::table($prefix);
        if ($manifest !== ['product' => Gate::PRODUCT, 'app_version' => '0.1.0', 'schema_min' => 1, 'schema_max' => 1]) {
            throw new Failure(Failure::IDENTITY);
        }
    }

    public function check(): array
    {
        $row = Gate::assertCompatible($this->pdo, $this->prefix, $this->manifest);
        return ['mode' => 'check', 'state' => 'compatible', 'changed' => false, 'code' => null, 'schema' => $row];
    }

    public function apply(): array
    {
        if ($this->pdo->inTransaction()) { throw new Failure(Failure::INCOMPATIBLE); }
        $locked = false;
        $failure = null;
        $result = null;
        $ownsTransaction = false;
        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') { throw new Failure(Failure::UNAVAILABLE); }
        $lock = 'marketplace-ms6:' . substr(hash('sha256', $database . "\0" . $this->prefix), 0, 40);
        try {
            if ((string) $this->scalar('SELECT GET_LOCK(?,0)', [$lock]) !== '1') { throw new Failure(Failure::UNAVAILABLE); }
            $locked = true;
            $current = Gate::inspect($this->pdo, $this->prefix);
            if ($current !== null) {
                if ($current['schema_version'] !== 1 || $current['min_app_version'] !== '0.1.0') {
                    throw new Failure(Failure::INCOMPATIBLE);
                }
                $result = ['mode' => 'apply', 'state' => 'unchanged', 'changed' => false, 'code' => null, 'schema' => $current];
            } else {
                // Expected values precede all DDL/DML, never copied from the resulting row.
                $now = time();
                if ($now < 1 || $now > 4294967295) { throw new Failure(Failure::UNAVAILABLE); }
                $expected = ['id' => 1, 'schema_version' => 1, 'min_app_version' => '0.1.0', 'updated_at' => $now];
                $this->writeAttempted = true;
                if ($this->pdo->exec(Gate::ddl($this->prefix)) === false) { throw new Failure(Failure::UNAVAILABLE); }
                // DDL above is not claimed to be transactional or automatically reversible.
                if (!$this->pdo->beginTransaction()) { throw new Failure(Failure::UNAVAILABLE); }
                $ownsTransaction = true;
                $statement = $this->pdo->prepare('INSERT INTO `' . Gate::table($this->prefix) . '` (id,schema_version,min_app_version,updated_at) VALUES (?,?,?,?)');
                if ($statement === false) { throw new Failure(Failure::UNAVAILABLE); }
                $statement->bindValue(1, 1, PDO::PARAM_INT);
                $statement->bindValue(2, 1, PDO::PARAM_INT);
                $statement->bindValue(3, '0.1.0', PDO::PARAM_STR);
                $statement->bindValue(4, $now, PDO::PARAM_INT);
                if (!$statement->execute() || $statement->rowCount() !== 1
                    || Gate::inspect($this->pdo, $this->prefix) !== $expected) {
                    throw new Failure(Failure::UNAVAILABLE);
                }
                if (!$this->pdo->commit()) { throw new Failure(Failure::UNAVAILABLE); }
                $ownsTransaction = false;
                if (Gate::assertCompatible($this->pdo, $this->prefix, $this->manifest) !== $expected) {
                    throw new Failure(Failure::UNAVAILABLE);
                }
                $result = ['mode' => 'apply', 'state' => 'created', 'changed' => true, 'code' => null, 'schema' => $expected];
            }
        } catch (Throwable $error) {
            $failure = $error instanceof Failure ? $error : new Failure(Failure::UNAVAILABLE);
            if ($ownsTransaction) {
                try { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
                catch (Throwable $ignored) { $failure = new Failure(Failure::UNAVAILABLE); }
            }
        } finally {
            if ($locked) {
                try {
                    if ((string) $this->scalar('SELECT RELEASE_LOCK(?)', [$lock]) !== '1' && $failure === null) {
                        $failure = new Failure(Failure::UNAVAILABLE);
                    }
                } catch (Throwable $ignored) {
                    if ($failure === null) { $failure = new Failure(Failure::UNAVAILABLE); }
                }
            }
        }
        if ($failure !== null) { throw $failure; }
        if (!is_array($result)) { throw new Failure(Failure::UNAVAILABLE); }
        return $result;
    }

    private function scalar(string $sql, array $parameters)
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) { throw new Failure(Failure::UNAVAILABLE); }
        return $statement->fetchColumn();
    }
}

/** A separate migration account is explicit; no credentials are printed or passed in argv. */
function marketplaceMs6PrivatePdo(string $file): array
{
    $stream = null;
    try {
        $meta = @lstat($file);
        $parent = @lstat(dirname($file));
        if (!is_array($meta) || !is_array($parent) || ($meta['mode'] & 0170000) !== 0100000
            || ($parent['mode'] & 0170000) !== 0040000 || ($meta['mode'] & 0777) !== 0600
            || ($parent['mode'] & 0777) !== 0700 || $meta['nlink'] !== 1 || !function_exists('posix_geteuid')
            || $meta['uid'] !== posix_geteuid() || $parent['uid'] !== posix_geteuid()
            || $meta['size'] < 1 || $meta['size'] > 65536 || realpath($file) !== $file) {
            throw new Failure(Failure::UNAVAILABLE);
        }
        $stream = @fopen($file, 'rb');
        if (!is_resource($stream)) { throw new Failure(Failure::UNAVAILABLE); }
        $opened = fstat($stream);
        $raw = stream_get_contents($stream, 65537);
        $after = fstat($stream);
        fclose($stream);
        $stream = null;
        clearstatcache(true, $file);
        $pathAfter = @lstat($file);
        if (!is_array($opened) || !is_array($after) || !is_array($pathAfter)
            || !is_string($raw) || strlen($raw) !== $meta['size']) {
            throw new Failure(Failure::UNAVAILABLE);
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if ($meta[$key] !== $opened[$key] || $opened[$key] !== $after[$key]
                || $meta[$key] !== $pathAfter[$key]) { throw new Failure(Failure::UNAVAILABLE); }
        }
        $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($config) || count($config) !== 6
            || array_diff(['hostname', 'hostport', 'database', 'username', 'password', 'prefix'], array_keys($config)) !== []) {
            throw new Failure(Failure::UNAVAILABLE);
        }
        foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) {
            if (!is_string($config[$key])) { throw new Failure(Failure::UNAVAILABLE); }
        }
        if (!preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname'])
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) || $config['username'] === ''
            || (!is_string($config['hostport']) && !is_int($config['hostport']))
            || !preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $config['hostport'])
            || (int) $config['hostport'] > 65535) { throw new Failure(Failure::UNAVAILABLE); }
        Gate::table($config['prefix']);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport']
            . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        return [$pdo, $config['prefix']];
    } catch (Throwable $error) {
        if (is_resource($stream)) { @fclose($stream); }
        throw new Failure(Failure::UNAVAILABLE);
    }
}

function marketplaceMs6Main(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms6-schema.php [check|apply] --repo-root=ABSOLUTE_PATH [--config-file=PRIVATE_JSON]\nDefault check is read-only. Apply only creates this migration's absent singleton table.\n";
        return 0;
    }
    $mode = 'check';
    $migration = null;
    try {
        $selected = false;
        $root = null;
        $privateFile = null;
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply'], true) && !$selected) { $mode = $argument; $selected = true; }
            elseif (strpos($argument, '--repo-root=') === 0 && $root === null) { $root = substr($argument, 12); }
            elseif (strpos($argument, '--config-file=') === 0 && $privateFile === null) { $privateFile = substr($argument, 14); }
            else { throw new Failure(Failure::IDENTITY); }
        }
        if (!is_string($root) || $root === '' || realpath($root) !== $root || !is_dir($root)) { throw new Failure(Failure::IDENTITY); }
        $manifest = Gate::manifest($root . '/crmeb/marketplace-release.json');
        if ($privateFile !== null) {
            [$pdo, $prefix] = marketplaceMs6PrivatePdo($privateFile);
        } else {
            require_once $root . '/crmeb/vendor/autoload.php';
            [$pdo, $prefix] = Runtime::standalone($root . '/crmeb');
        }
        $migration = new MarketplaceMs6SchemaMigration($pdo, $prefix, $manifest);
        $result = $mode === 'apply' ? $migration->apply() : $migration->check();
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    } catch (Throwable $error) {
        $code = $error instanceof Failure ? $error->getMessage() : Failure::UNAVAILABLE;
        $changed = $migration instanceof MarketplaceMs6SchemaMigration && $migration->writeAttempted ? null : false;
        echo json_encode(['mode' => $mode, 'state' => 'rejected', 'changed' => $changed, 'code' => $code, 'schema' => null], JSON_THROW_ON_ERROR) . "\n";
        return $code === Failure::UNAVAILABLE ? 1 : 2;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(marketplaceMs6Main(array_slice($argv, 1)));
}
