<?php
declare(strict_types=1);

use app\services\marketplace\assets\AssetSchema;

require_once __DIR__ . '/../../crmeb/app/services/marketplace/assets/AssetSchema.php';

/** Three CREATE-only steps. Explicit private configuration never proves that the caller is isolated. */
final class MarketplaceMs4AssetMigration
{
    public const SCOPE = 'MS4_ASSET_DOMAINS_STRUCTURE_ONLY';
    private const SCHEMA_FILE = __DIR__ . '/../../crmeb/app/services/marketplace/assets/AssetSchema.php';
    private bool $writeAttempted = false;
    private bool $ddlOutcomeUncertain = false;
    private array $attempted = [];
    private array $applied = [];
    private array $sources;
    private array $lockScope = [];

    public function __construct(private PDO $pdo, private string $prefix, private string $database)
    {
        $this->sources = self::sourceHashes();
    }

    private static function sourceHashes(): array
    {
        $out = [];
        foreach (['migration' => __FILE__, 'schema' => self::SCHEMA_FILE] as $name => $file) {
            if (!is_file($file) || is_link($file) || !is_string($hash = hash_file('sha256', $file))) {
                throw new RuntimeException('Asset migration source unavailable');
            }
            $out[$name] = $hash;
        }
        return $out;
    }

    private function assertSources(): void
    {
        if (self::sourceHashes() !== $this->sources) throw new RuntimeException('Asset migration source changed');
    }

    private function inspect(): array
    {
        $this->assertSources();
        if ($this->pdo->inTransaction()) throw new RuntimeException('Asset migration active transaction');
        $view = AssetSchema::inspect($this->pdo, $this->prefix, $this->database);
        $this->assertSources();
        return $view;
    }

    private function reset(): void
    {
        $this->writeAttempted = false; $this->ddlOutcomeUncertain = false;
        $this->attempted = []; $this->applied = [];
        $this->lockScope = ['request_attempted' => false, 'execute_attempted' => false, 'preexisting_same_session' => null,
            'acquisition' => 'NOT_ATTEMPTED', 'request_cursor_closed' => null, 'confirmation_failed' => false,
            'release_attempted' => false, 'release_execute_attempted' => false, 'release_result' => 'NOT_ATTEMPTED',
            'release_cursor_closed' => null, 'ownership' => 'NOT_CHECKED', 'resolved' => null];
    }

    /** No advisory lock, DDL, DML, framework or environment-file loading. */
    public function check(): array
    {
        $this->reset();
        return $this->receipt('check', null, false);
    }

    public function applyStructure(): array
    {
        $this->reset();
        $lockSession = null; $releaseFailed = false; $failure = null; $phase = 'precheck_failed';
        $name = 'ms4-assets:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . AssetSchema::VERSION), 0, 48);
        try {
            $view = $this->inspect();
            if ($view['issues'] === []) {
                $phase = 'lock_failed';
                [$session, $owner] = $this->lockOwnership($name);
                $this->lockScope['preexisting_same_session'] = $owner === $session;
                $this->lockScope['ownership'] = $owner === $session ? 'CALLER_OWNED' : 'NOT_OWNED';
                $this->lockScope['resolved'] = true;
                if ($owner === $session) throw new RuntimeException('Asset migration lock already held by caller');
                $lockSession = $session;
                $this->requestLock($name);
                $phase = 'locked_recheck_failed'; $view = $this->inspect();
                if ($view['issues'] === []) {
                    foreach ($view['pending'] as $suffix) {
                        $expectedPending = array_values(array_filter($view['pending'], static fn(string $table): bool => $table !== $suffix));
                        $sql = AssetSchema::createSql($suffix, $this->prefix);
                        $code = 'create.' . $suffix;
                        $phase = 'structure_apply_failed'; $this->assertSources();
                        $this->attempted[] = $code; $this->writeAttempted = true; $this->ddlOutcomeUncertain = true;
                        if ($this->pdo->exec($sql) === false) throw new RuntimeException('Asset structure apply failed');
                        $this->applied[] = $code; $this->ddlOutcomeUncertain = false;
                        $phase = 'step_readback_failed'; $this->assertSources(); $view = $this->inspect();
                        if ($view['issues'] !== [] || $view['pending'] !== $expectedPending
                            || $view['tables'][$suffix] !== 'compatible') throw new RuntimeException('Asset step readback failed');
                    }
                    $phase = 'postcheck_failed'; $view = $this->inspect();
                    if ($view['issues'] !== [] || $view['pending'] !== []) throw new RuntimeException('Asset postcheck failed');
                }
            }
        } catch (Throwable $error) {
            $failure = $phase;
        } finally {
            if ($lockSession !== null && $this->lockScope['execute_attempted']
                && !in_array($this->lockScope['acquisition'], ['ZERO', 'NULL'], true)) {
                $releaseFailed = $this->settleLock($name, $lockSession);
                if ($releaseFailed) $failure ??= 'lock_release_failed';
            }
        }
        return $this->receipt('apply-structure', $failure, $releaseFailed);
    }

    /** DDL may commit before an exception. Observe again; never fabricate rollback or silently retry. */
    private function receipt(string $mode, ?string $phase, bool $releaseFailed): array
    {
        $view = ['issues' => [], 'pending' => [], 'tables' => []]; $known = false; $sourcesHeld = false;
        try { $this->assertSources(); $sourcesHeld = true; }
        catch (Throwable $error) {
            $phase ??= 'source_changed';
            $view['issues'][] = ['kind' => 'source_changed', 'target' => 'migration'];
        }
        if ($sourcesHeld) {
            try { $view = $this->inspect(); $known = true; }
            catch (Throwable $error) { $phase ??= 'inspection_failed'; }
        }
        if ($sourcesHeld) {
            try { $this->assertSources(); }
            catch (Throwable $error) {
                $sourcesHeld = false; $known = false; $phase ??= 'source_changed';
                $view = ['issues' => [['kind' => 'source_changed', 'target' => 'migration']], 'pending' => [], 'tables' => []];
            }
        }
        if ($phase !== null) $view['issues'][] = ['kind' => 'operation_failed', 'target' => 'migration'];
        if ($releaseFailed) $view['issues'][] = ['kind' => 'lock_release_failed', 'target' => 'migration'];
        $state = $view['issues'] !== [] ? 'incompatible' : ($view['pending'] === [] ? 'completed' : 'applicable');
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $phase !== null ? 1 : ($state === 'incompatible' ? 2 : 0),
            'changed' => $this->writeAttempted, 'change_observation' => $this->writeAttempted ? 'DDL_MAY_HAVE_COMMITTED' : 'DDL_NOT_ATTEMPTED',
            'attempted_steps' => $this->attempted, 'applied_steps' => $this->applied, 'ddl_outcome_uncertain' => $this->ddlOutcomeUncertain,
            'pending_steps' => array_map(static fn(string $suffix): string => 'create.' . $suffix, $view['pending']),
            'pending_steps_known' => $known, 'observed_tables' => $view['tables'], 'structure_step_count' => 3,
            'issues' => $view['issues'], 'failure_phase' => $phase, 'lock_release_failed' => $releaseFailed,
            'lock_scope' => $this->lockScope,
            'source_sha256' => $this->sources['migration'], 'schema_source_sha256' => $this->sources['schema'],
            'sources_held' => $sourcesHeld, 'schema_contract' => AssetSchema::VERSION, 'scope' => self::SCOPE,
            'history_mapping' => 'NOT RUN', 'writer' => 'NOT RUN', 'activation' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }

    /** Session identifiers remain private strings; no observer connection or numeric coercion. */
    private function lockOwnership(string $name): array
    {
        $statement = $this->pdo->prepare('SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,CAST(IS_USED_LOCK(?) AS CHAR) AS owner_id');
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Asset lock ownership unavailable');
        try {
            if ($statement->execute([$name]) !== true) throw new RuntimeException('Asset lock ownership unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'owner_id']
                || !is_string($rows[0]['session_id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['session_id']) !== 1
                || ($rows[0]['owner_id'] !== null && (!is_string($rows[0]['owner_id'])
                    || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['owner_id']) !== 1))) {
                throw new RuntimeException('Asset lock ownership unavailable');
            }
            return [$rows[0]['session_id'], $rows[0]['owner_id']];
        } finally {
            if ($statement->closeCursor() !== true) throw new RuntimeException('Asset ownership cursor unavailable');
        }
    }

    /** Mark possible acquisition before execute, independently of fetch and cursor closure. */
    private function requestLock(string $name): void
    {
        $this->lockScope['request_attempted'] = true;
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?,10) AS lock_result');
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Asset lock request unavailable');
        try {
            $this->lockScope['execute_attempted'] = true;
            $this->lockScope['acquisition'] = 'UNKNOWN';
            $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
            if ($statement->execute([$name]) !== true) throw new RuntimeException('Asset lock request unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['lock_result']) throw new RuntimeException('Asset lock result unavailable');
            $value = $rows[0]['lock_result'];
            if ($value === 1 || $value === '1') $result = 'ONE';
            elseif ($value === 0 || $value === '0') $result = 'ZERO';
            elseif ($value === null) $result = 'NULL';
            else throw new RuntimeException('Asset lock result unavailable');
            $this->lockScope['acquisition'] = $result;
            $this->lockScope['ownership'] = $result === 'ONE' ? 'OWNED' : 'NOT_OWNED';
            $this->lockScope['resolved'] = $result !== 'ONE';
            if ($result !== 'ONE') throw new RuntimeException('Asset migration lock unavailable');
        } finally {
            $this->lockScope['request_cursor_closed'] = false;
            if ($statement->closeCursor() !== true) throw new RuntimeException('Asset request cursor unavailable');
            $this->lockScope['request_cursor_closed'] = true;
        }
    }

    /** At most one release query. An inherited same-session lock never reaches this method. */
    private function settleLock(string $name, string $originalSession): bool
    {
        try {
            [$session, $owner] = $this->lockOwnership($name);
            if ($session !== $originalSession) {
                $this->lockScope['ownership'] = 'SESSION_CHANGED'; $this->lockScope['resolved'] = null;
                return true;
            }
            $this->lockScope['ownership'] = $owner === $session ? 'OWNED' : 'NOT_OWNED';
            $this->lockScope['resolved'] = $owner !== $session;
            if ($owner !== $session) return false;
        } catch (Throwable $error) {
            $this->lockScope['confirmation_failed'] = true;
            $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
        }
        $failed = false; $statement = null;
        $this->lockScope['release_attempted'] = true;
        try {
            // Even when the ownership read failed, never release a replacement session's existing lock.
            $statement = $this->pdo->prepare('SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,'
                . 'CASE WHEN BINARY CAST(CONNECTION_ID() AS CHAR)=BINARY ? THEN RELEASE_LOCK(?) ELSE NULL END AS lock_result');
            if (!$statement instanceof PDOStatement) throw new RuntimeException('Asset lock release unavailable');
            $this->lockScope['release_execute_attempted'] = true;
            $this->lockScope['release_result'] = 'UNKNOWN';
            if ($statement->execute([$originalSession, $name]) !== true) throw new RuntimeException('Asset lock release unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'lock_result']
                || !is_string($rows[0]['session_id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['session_id']) !== 1) {
                throw new RuntimeException('Asset release result unavailable');
            }
            if ($rows[0]['session_id'] !== $originalSession) {
                $this->lockScope['release_result'] = 'SESSION_CHANGED';
                $this->lockScope['ownership'] = 'SESSION_CHANGED'; $this->lockScope['resolved'] = null;
                throw new RuntimeException('Asset lock session changed');
            }
            $value = $rows[0]['lock_result'];
            if ($value === 1 || $value === '1') $result = 'ONE';
            elseif ($value === 0 || $value === '0') $result = 'ZERO';
            elseif ($value === null) $result = 'NULL';
            else throw new RuntimeException('Asset release result unavailable');
            $this->lockScope['release_result'] = $result;
            $this->lockScope['ownership'] = 'NOT_OWNED'; $this->lockScope['resolved'] = true;
            if ($result !== 'ONE') throw new RuntimeException('Asset lock release not acknowledged');
        } catch (Throwable $error) {
            $failed = true;
        } finally {
            if ($statement instanceof PDOStatement) {
                $this->lockScope['release_cursor_closed'] = false;
                try {
                    if ($statement->closeCursor() !== true) throw new RuntimeException('Asset release cursor unavailable');
                    $this->lockScope['release_cursor_closed'] = true;
                } catch (Throwable $error) { $failed = true; }
            }
        }
        if ($this->lockScope['resolved'] !== true) {
            try {
                [$session, $owner] = $this->lockOwnership($name);
                $this->lockScope['ownership'] = $session !== $originalSession ? 'SESSION_CHANGED' : ($owner === $session ? 'OWNED' : 'NOT_OWNED');
                $this->lockScope['resolved'] = $session === $originalSession ? $owner !== $session : null;
            } catch (Throwable $error) {
                $this->lockScope['confirmation_failed'] = true;
                $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
            }
        }
        return $failed || $this->lockScope['resolved'] !== true;
    }
}

/** Owner/mode/tmpfs and real isolated-environment admission remain the authorized caller's responsibility. */
function marketplaceMs4AssetConfiguration(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Asset configuration unavailable');
    try { $raw = stream_get_contents($handle, 65537); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('Asset configuration unavailable');
    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $keys = ['hostname', 'hostport', 'database', 'username', 'password', 'prefix'];
    if (!is_array($config) || array_is_list($config) || array_diff(array_keys($config), $keys) !== []) throw new RuntimeException('Asset configuration unavailable');
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) throw new RuntimeException('Asset configuration unavailable');
    }
    $port = $config['hostport'] ?? '3306';
    if ((!is_string($port) && !is_int($port)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string)$port) !== 1 || (int)$port > 65535
        || $config['username'] === '' || preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) !== 1
        || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) !== 1
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $config['prefix']) !== 1) throw new RuntimeException('Asset configuration unavailable');
    $config['hostport'] = (string)$port;
    return $config;
}

function marketplaceMs4AssetMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms4-assets.php [check|apply-structure] --config-file=PRIVATE_JSON\n"
            . "Default check is SELECT-only. Apply requires a separately established dedicated isolated environment; explicit JSON alone does not prove isolation.\n"
            . "Only the three fixed asset-domain CREATE TABLE statements are available. Existing incompatible objects block; no ALTER, DROP, DML, backfill, GRANT or activation.\n"
            . "DDL may partially commit. Preserve actual attempted/applied, unknown outcomes and freshly observed pending facts. No automatic rollback or retry.\n";
        return 0;
    }
    $mode = 'check'; $file = null; $selected = false;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply-structure'], true) && !$selected) { $mode = $argument; $selected = true; }
            elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) $file = substr($argument, 14);
            else throw new RuntimeException('Asset CLI configuration unavailable');
        }
        if ($file === null) throw new RuntimeException('Explicit private configuration required');
        $config = marketplaceMs4AssetConfiguration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs4AssetMigration($pdo, $config['prefix'], $config['database']);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
    } catch (Throwable $error) {
        // No driver error, SQL, connection string, private path or credential is emitted.
        $result = ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => false,
            'change_observation' => 'DDL_NOT_ATTEMPTED', 'attempted_steps' => [], 'applied_steps' => [], 'ddl_outcome_uncertain' => false,
            'pending_steps' => [], 'pending_steps_known' => false, 'observed_tables' => [], 'structure_step_count' => 3,
            'issues' => [['kind' => 'configuration_or_connection_failed', 'target' => 'migration']],
            'failure_phase' => 'configuration_or_connection_failed', 'lock_release_failed' => false,
            'lock_scope' => null,
            'source_sha256' => hash_file('sha256', __FILE__), 'schema_source_sha256' => hash_file('sha256', __DIR__ . '/../../crmeb/app/services/marketplace/assets/AssetSchema.php'),
            'sources_held' => false, 'schema_contract' => AssetSchema::VERSION, 'scope' => MarketplaceMs4AssetMigration::SCOPE,
            'history_mapping' => 'NOT RUN', 'writer' => 'NOT RUN', 'activation' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs4AssetMain(array_slice($argv, 1)));
