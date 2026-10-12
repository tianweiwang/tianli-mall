<?php
declare(strict_types=1);
/* Ordinary Task8: real valid production A then B, without stopped scenarios. */
require __DIR__ . '/lib/harness.php';
if ((audit_manifest()['guard_mode'] ?? null) !== 'read-only') {
    fwrite(STDERR, "BLOCKED ordinary Task8 requires read-only isolation checks\n"); exit(2);
}
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
require_once __DIR__ . '/lib/Ms1EmptyWorkerJob.php';

const M8W_TABLES = ['eb_marketplace_merchant' => 'mer_id', 'eb_marketplace_role' => 'id',
    'eb_marketplace_employee' => 'id', 'eb_marketplace_audit_log' => 'id'];
const M8W_QUEUES = ['queues:marketplace', 'queues:marketplace:delayed', 'queues:marketplace:reserved'];

function m8w_diag(Throwable $error): array
{ return ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]; }

function m8w_files(string $root): array
{
    audit_expect(is_dir($root) && !is_link($root), 'required ordinary runtime mount is present'); $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        audit_expect(!$item->isLink(), 'ordinary runtime contains no links');
        $name = substr($item->getPathname(), strlen(rtrim($root, '/') . '/'));
        if ($item->isFile()) $files[$name] = ['bytes' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
        elseif ($item->isDir()) $files[$name . '/'] = ['directory' => true];
    }
    ksort($files); return $files;
}

function m8w_scan(string $pattern): array
{
    $cursor = null; $keys = []; $redis = audit_redis();
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    $keys = array_values(array_unique($keys)); sort($keys, SORT_STRING); return $keys;
}

function m8w_cache_value(Redis $redis, string $key): array
{
    $type = $redis->type($key); audit_expect($type !== Redis::REDIS_NOT_FOUND, 'registered native cache key is present');
    if ($type === Redis::REDIS_SET) { $members = $redis->sMembers($key); sort($members, SORT_STRING); $value = "set\0" . serialize($members); }
    else { $value = $redis->dump($key); audit_expect(is_string($value), 'actual native cache value is available'); }
    $deadline = $redis->rawCommand('PEXPIRETIME', $key);
    audit_expect(is_int($deadline) && ($deadline === -1 || $deadline > 0), 'actual cache absolute deadline is available');
    return ['sha256' => hash('sha256', $value), 'deadline' => $deadline];
}

function m8w_snapshot(): array
{
    $pdo = audit_db(); $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'rows' => [], 'ddl' => [], 'checksums' => [], 'redis' => [], 'runtime' => []];
    foreach ($names as $name) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $name) === 1, 'ordinary snapshot table name is finite');
        $primary = $pdo->query('SHOW KEYS FROM `' . $name . '` WHERE Key_name=\'PRIMARY\'')->fetchAll();
        usort($primary, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $order = array_map(static fn(array $key): string => '`' . str_replace('`', '``', $key['Column_name']) . '`', $primary);
        $rows = $pdo->query('SELECT * FROM `' . $name . '`' . ($order ? ' ORDER BY ' . implode(',', $order) : ''))->fetchAll();
        $hashes = array_map(static fn(array $row): string => hash('sha256', serialize($row)), $rows);
        if (!$order) sort($hashes, SORT_STRING);
        $snapshot['rows'][$name] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $hashes))];
        $create = $pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
        $snapshot['ddl'][$name] = ['sha256' => hash('sha256', $create),
            'without_counter_sha256' => hash('sha256', preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+ /', '$1 ', $create))];
        $checksum = $pdo->query('CHECKSUM TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC)['Checksum'];
        $snapshot['checksums'][$name] = $checksum === null ? null : (string)$checksum;
    }
    $redis = audit_redis(); foreach (m8w_scan('*') as $key) $snapshot['redis'][$key] = m8w_cache_value($redis, $key);
    foreach (['/run/audit-app-runtime', '/run/audit-worker-runtime', audit_repo_dir() . '/crmeb/runtime'] as $root) $snapshot['runtime'][] = m8w_files($root);
    return $snapshot;
}

function m8w_public_snapshot(array $snapshot): array
{
    $keys = []; foreach ($snapshot['redis'] as $key => $value) $keys[hash('sha256', $key)] = $value;
    ksort($keys); $snapshot['redis'] = $keys; return $snapshot;
}

function m8w_same(array $before, array $after, array $changedTables = [], array $changedKeys = [], array $counterTables = []): void
{
    audit_expect($before['names'] === $after['names'], 'all original table names are unchanged');
    foreach ($before['names'] as $table) {
        if (!in_array($table, $changedTables, true)) audit_expect($before['rows'][$table] === $after['rows'][$table]
            && $before['checksums'][$table] === $after['checksums'][$table], 'every unrelated row and checksum is unchanged');
        $column = in_array($table, $counterTables, true) ? 'without_counter_sha256' : 'sha256';
        audit_expect($before['ddl'][$table][$column] === $after['ddl'][$table][$column], 'all schema changes are limited to registered natural counters');
    }
    foreach (array_unique(array_merge(array_keys($before['redis']), array_keys($after['redis']))) as $key) {
        if (in_array($key, $changedKeys, true)) {
            audit_expect(!isset($before['redis'][$key]), 'owned cache exception must have been initially absent'); continue;
        }
        audit_expect(isset($before['redis'][$key], $after['redis'][$key]) && $before['redis'][$key] === $after['redis'][$key], 'original native cache values and absolute deadlines are unchanged');
    }
    audit_expect($before['runtime'] === $after['runtime'], 'all three original runtime file inventories are unchanged');
}

function m8w_row_map(PDO $pdo, string $table, array $omit = []): array
{
    audit_expect(isset(M8W_TABLES[$table]), 'owned fixture table is finite'); $pk = M8W_TABLES[$table]; $out = [];
    foreach ($pdo->query('SELECT * FROM `' . $table . '` ORDER BY `' . $pk . '`')->fetchAll() as $row) {
        if (!in_array((int)$row[$pk], $omit, true)) $out[(string)$row[$pk]] = hash('sha256', serialize($row));
    }
    return $out;
}

function m8w_original_rows(PDO $pdo, array $state, array $auditIds = []): void
{
    foreach (M8W_TABLES as $table => $pk) {
        $omit = $table === 'eb_marketplace_audit_log' ? $auditIds : array_values(array_filter(array_map(
            static fn(array $f): int => (int)($f[match ($table) {
                'eb_marketplace_merchant' => 'mer_id', 'eb_marketplace_role' => 'role_id', 'eb_marketplace_employee' => 'employee_id'
            }] ?? 0), $state['fixtures'])));
        audit_expect(m8w_row_map($pdo, $table, $omit) === $state['old_rows'][$table], 'each original row of a fixture table is unchanged');
    }
}

function m8w_fixture_fields(string $run, string $label): array
{
    audit_expect(in_array($label, ['A', 'B'], true), 'fixture label is finite');
    $slug = 'ms1_worker_' . substr(hash('sha256', $run), 0, 12);
    return ['merchant_name' => $slug . '_' . $label, 'role_name' => 'Worker ' . $label,
        'account' => $slug . '_' . strtolower($label), 'name' => 'Worker ' . $label, 'avatar' => '/statics/ms1-worker-' . $label . '.png'];
}

function m8w_seed(PDO $pdo, array &$state, array &$credentials): void
{
    audit_expect(!$pdo->inTransaction(), 'synthetic fixture transaction is independently owned'); $pending = []; $pdo->beginTransaction();
    try {
        foreach (['A', 'B'] as $label) {
            $f = m8w_fixture_fields($state['run_id'], $label); $now = time();
            $password = 'M8w-' . bin2hex(random_bytes(20)) . '-x'; $digest = password_hash($password, PASSWORD_BCRYPT);
            audit_expect(is_string($digest) && password_verify($password, $digest), 'normal synthetic password is actually set');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM eb_marketplace_employee WHERE account=?'); $stmt->execute([$f['account']]);
            audit_expect((int)$stmt->fetchColumn() === 0, 'new synthetic employee account is absent');
            $stmt = $pdo->prepare('INSERT INTO eb_marketplace_merchant(name,review_status,accept_orders,fulfillment_enabled,security_locked,closed_at,add_time,update_time) VALUES(?,1,0,0,0,NULL,?,?)');
            $stmt->execute([$f['merchant_name'], $now, $now]); $merId = (int)$pdo->lastInsertId();
            $permissions = $label === 'A' ? \app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN : \app\services\marketplace\MerchantPermissions::BUILTIN_OPERATOR;
            $stmt = $pdo->prepare('INSERT INTO eb_marketplace_role(mer_id,name,permissions,add_time,update_time) VALUES(?,?,?,?,?)');
            $stmt->execute([$merId, $f['role_name'], json_encode($permissions, JSON_THROW_ON_ERROR), $now, $now]); $roleId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO eb_marketplace_employee(mer_id,role_id,account,pwd,name,avatar,status,is_del,add_time,update_time) VALUES(?,?,?,?,?,?,1,0,?,?)');
            $stmt->execute([$merId, $roleId, $f['account'], $digest, $f['name'], $f['avatar'], $now, $now]); $employeeId = (int)$pdo->lastInsertId();
            audit_expect($merId > 0 && $roleId > 0 && $employeeId > 0, 'real generated fixture identifiers are positive');
            $pending[$label] = ['label' => $label, 'mer_id' => $merId, 'role_id' => $roleId, 'employee_id' => $employeeId, 'row_hashes' => []];
            foreach (['merchant' => ['mer_id', $merId], 'role' => ['id', $roleId], 'employee' => ['id', $employeeId]] as $table => [$pk, $id]) {
                $stmt = $pdo->prepare('SELECT * FROM eb_marketplace_' . $table . ' WHERE `' . $pk . '`=?'); $stmt->execute([$id]); $row = $stmt->fetch();
                audit_expect(is_array($row), 'real synthetic fixture row is available in the owned transaction');
                $pending[$label]['row_hashes'][$table] = hash('sha256', serialize($row));
            }
            $credentials[$label] = ['account' => $f['account'], 'password' => $password];
        }
        $pdo->commit(); $state['fixtures'] = $pending;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function m8w_app(): \think\App
{
    \Audit\ms1_worker_environment(); $app = new \think\App(audit_repo_dir() . '/crmeb/'); $app->initialize();
    $database = $app->config->get('database');
    audit_expect(is_array($database) && ($database['default'] ?? '') === 'mysql' && is_array($database['connections']['mysql'] ?? null), 'actual producer default mysql is available');
    foreach (['debug' => false, 'trigger_sql' => false, 'charset' => 'utf8mb4', 'break_reconnect' => false] as $key => $value) $database['connections']['mysql'][$key] = $value;
    $app->config->set($database, 'database');
    audit_expect($app->cache->get('think:queue:restart') === null, 'new actual worker restart marker is absent'); return $app;
}

function m8w_claims(string $token, array $config): array
{
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token, $config['signing_key'], ['HS256']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
}

function m8w_session(array $session, array $f, array $config, array &$state): array
{
    $keys = array_keys($session); sort($keys);
    audit_expect($keys === ['exp', 'token'] && is_string($session['token']) && is_int($session['exp']), 'normal production login returns token and expiry');
    $claims = m8w_claims($session['token'], $config);
    audit_expect(($claims['auth_schema'] ?? null) === 2 && ($claims['session_epoch'] ?? null) === $config['auth']['session_epoch']
        && ($claims['iss'] ?? null) === $config['auth']['issuer'] && ($claims['aud'] ?? null) === 'tianli:merchantapi'
        && ($claims['jti']['id'] ?? null) === (string)$f['employee_id'] && ($claims['jti']['type'] ?? null) === 'merchant'
        && is_string($claims['sid'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims['sid']) === 1
        && is_string($claims['session_version'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims['session_version']) === 1
        && ($claims['exp'] ?? null) === $session['exp'], 'actual valid login carries the fixed merchant binding');
    $prefix = 'auth:v2:' . $config['auth']['session_epoch'] . ':merchantapi:';
    $accountKey = $prefix . 'account:' . $f['employee_id']; $sessionKey = $prefix . 'session:' . $claims['sid'];
    $redis = audit_redis();
    foreach ([$accountKey => 'account', $sessionKey => 'session'] as $key => $kind) {
        audit_expect(!isset($state['original']['redis'][$key]), 'new production account/session key is owned by this RUN');
        $state['owned_keys'][$key] = ['kind' => $kind, 'employee_id' => $f['employee_id']] + m8w_cache_value($redis, $key);
    }
    $actualAccount = $redis->hGetAll($accountKey); ksort($actualAccount);
    audit_expect($actualAccount === ['blocked' => '0', 'initialized' => '1', 'session_version' => $claims['session_version']]
        && $state['owned_keys'][$accountKey]['deadline'] === -1, 'normal login initializes a genuine persistent unprotected version');
    $actualSession = $redis->hGetAll($sessionKey);
    audit_expect(($actualSession['subject_id'] ?? null) === (string)$f['employee_id'] && ($actualSession['session_version'] ?? null) === $claims['session_version']
        && ($actualSession['type'] ?? null) === 'merchant' && ($actualSession['audience'] ?? null) === 'tianli:merchantapi'
        && ($actualSession['status'] ?? null) === 'active' && ($actualSession['token_digest'] ?? null) === hash('sha256', $session['token'])
        && $state['owned_keys'][$sessionKey]['deadline'] === ($claims['exp'] + 60) * 1000, 'actual native current session binding and expiry are exact');
    return $claims;
}

function m8w_payload(string $bytes, array $state, bool $requireRecorded = true): array
{
    $p = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); $keys = is_array($p) ? array_keys($p) : []; sort($keys);
    audit_expect($keys === ['attempts', 'data', 'id', 'job', 'maxTries', 'timeout'] && $p['job'] === \app\jobs\marketplace\AuditLogJob::class
        && $p['maxTries'] === null && $p['timeout'] === null && is_string($p['id']) && preg_match('/^[A-Za-z0-9]{32}$/D', $p['id']) === 1
        && is_int($p['attempts']) && in_array($p['attempts'], [0, 1], true), 'only exact real production queue payloads are owned');
    $data = \app\jobs\marketplace\AuditLogJob::verifySubmission($p['data']); $matched = null;
    foreach ($state['fixtures'] as $f) if ($data['employeeId'] === $f['employee_id'] && $data['resource'] === ['type' => 'merchant', 'id' => $f['mer_id']]) $matched = $f;
    audit_expect(is_array($matched), 'actual signed source belongs to an exact synthetic employee and merchant');
    if ($requireRecorded) {
        $found = false;
        foreach ($state['jobs'] as $job) if ($job['job_id_sha256'] === hash('sha256', $p['id']) && $job['employee_id'] === $data['employeeId']
            && $job['mer_id'] === $data['resource']['id'] && $job['request_id'] === $data['requestId']) $found = true;
        audit_expect($found, 'queued source and real job hash match the exact RUN registry');
    }
    return $p;
}

function m8w_discover_sessions(array &$state): void
{
    $config = audit_config(); $prefix = 'auth:v2:' . $config['auth']['session_epoch'] . ':merchantapi:'; $redis = audit_redis();
    foreach ($state['fixtures'] as $f) {
        $key = $prefix . 'account:' . $f['employee_id'];
        if ($redis->exists($key) === 1 && !isset($state['owned_keys'][$key])) {
            audit_expect(!isset($state['original']['redis'][$key]) && $redis->type($key) === Redis::REDIS_HASH, 'partial normal account belongs to the new employee');
            $state['owned_keys'][$key] = ['kind' => 'account', 'employee_id' => $f['employee_id']] + m8w_cache_value($redis, $key);
        }
    }
    foreach (m8w_scan($prefix . 'session:*') as $key) {
        if (isset($state['owned_keys'][$key])) continue;
        audit_expect(preg_match('/^' . preg_quote($prefix . 'session:', '/') . '[a-f0-9]{32}$/D', $key) === 1 && $redis->type($key) === Redis::REDIS_HASH, 'partial session key format is finite');
        $row = $redis->hGetAll($key); $employeeId = null;
        foreach ($state['fixtures'] as $f) if (($row['subject_id'] ?? null) === (string)$f['employee_id']) $employeeId = $f['employee_id'];
        if ($employeeId === null) continue;
        audit_expect(!isset($state['original']['redis'][$key]) && ($row['type'] ?? null) === 'merchant' && ($row['audience'] ?? null) === 'tianli:merchantapi', 'partial current session is linked to an exact new employee');
        $state['owned_keys'][$key] = ['kind' => 'session', 'employee_id' => $employeeId] + m8w_cache_value($redis, $key);
    }
}

function m8w_audits(PDO $pdo, array $state, bool $complete): array
{
    $out = [];
    foreach ($state['fixtures'] as $f) {
        $stmt = $pdo->prepare('SELECT *,JSON_TYPE(detail) AS detail_type,JSON_LENGTH(detail) AS detail_length FROM eb_marketplace_audit_log WHERE mer_id=? ORDER BY id');
        $stmt->execute([$f['mer_id']]); $rows = $stmt->fetchAll();
        audit_expect(count($rows) <= 1 && (!$complete || count($rows) === 1), 'each actual normal task creates exactly one audit for its own merchant');
        foreach ($rows as $row) {
            audit_expect((int)$row['id'] > 0 && (int)$row['mer_id'] === $f['mer_id'] && $row['actor_type'] === 'merchant'
                && (int)$row['actor_id'] === $f['employee_id'] && $row['action'] === 'merchant.profile.read'
                && $row['resource_type'] === 'merchant' && $row['resource_id'] === (string)$f['mer_id'] && $row['result'] === 'success'
                && $row['detail_type'] === 'OBJECT' && (int)$row['detail_length'] === 0 && (int)$row['add_time'] >= $state['started_at'], 'actual audit has the fixed current merchant employee and empty JSON object detail');
            unset($row['detail_type'], $row['detail_length']);
            $out[$f['label']] = ['id' => (int)$row['id'], 'mer_id' => $f['mer_id'], 'employee_id' => $f['employee_id'], 'row_hash' => hash('sha256', serialize($row))];
        }
    }
    return $out;
}

function m8w_cleanup(array &$state): array
{
    $pdo = audit_db(); $redis = audit_redis(); $removed = ['rows' => 0, 'keys' => 0, 'queue_items' => 0];
    // This happens after native worker termination (or before permission on a
    // prepare failure). Queue inspection is ownership/cleanup, never RPUSH ACK.
    foreach (M8W_QUEUES as $index => $key) {
        if ($redis->type($key) === Redis::REDIS_NOT_FOUND) continue;
        audit_expect(!isset($state['original']['redis'][$key]) && $redis->type($key) === ($index === 0 ? Redis::REDIS_LIST : Redis::REDIS_ZSET), 'residual queue was initially absent with the finite native type');
        $items = $index === 0 ? $redis->lRange($key, 0, -1) : $redis->zRange($key, 0, -1);
        foreach ($items as $bytes) {
            m8w_payload($bytes, $state, $state['status'] === 'prepared');
            $count = $index === 0 ? $redis->lRem($key, $bytes, 0) : $redis->zRem($key, $bytes);
            audit_expect(is_int($count) && $count === 1, 'exact owned residual removes one native item'); $removed['queue_items'] += $count;
        }
        audit_expect($redis->exists($key) === 0, 'owned finite queue is now absent without deleting a foreign key');
    }
    m8w_discover_sessions($state);
    $audits = m8w_audits($pdo, $state, false); $state['audits'] = $audits;
    m8w_original_rows($pdo, $state, array_column($audits, 'id'));
    audit_expect(!$pdo->inTransaction(), 'cleanup transaction is independently owned'); $pdo->beginTransaction();
    try {
        foreach (array_reverse($audits, true) as $row) {
            $stmt = $pdo->prepare('SELECT * FROM eb_marketplace_audit_log WHERE id=? AND mer_id=? AND actor_id=?');
            $stmt->execute([$row['id'], $row['mer_id'], $row['employee_id']]); $actual = $stmt->fetch();
            audit_expect(is_array($actual) && hash('sha256', serialize($actual)) === $row['row_hash'], 'exact owned audit row has not changed');
            $stmt = $pdo->prepare('DELETE FROM eb_marketplace_audit_log WHERE id=? AND mer_id=? AND actor_type=\'merchant\' AND actor_id=? AND action=\'merchant.profile.read\'');
            $stmt->execute([$row['id'], $row['mer_id'], $row['employee_id']]); audit_expect($stmt->rowCount() === 1, 'exact audit cleanup affects one owned row'); $removed['rows']++;
        }
        foreach (array_reverse($state['fixtures'], true) as $f) {
            $fields = m8w_fixture_fields($state['run_id'], $f['label']);
            foreach (['employee' => ['id', $f['employee_id']], 'role' => ['id', $f['role_id']], 'merchant' => ['mer_id', $f['mer_id']]] as $table => [$pk, $id]) {
                $stmt = $pdo->prepare('SELECT * FROM eb_marketplace_' . $table . ' WHERE `' . $pk . '`=?'); $stmt->execute([$id]); $actual = $stmt->fetch();
                audit_expect(is_array($actual) && hash('sha256', serialize($actual)) === ($f['row_hashes'][$table] ?? null), 'exact unchanged committed fixture is owned');
                $sql = match ($table) {
                    'employee' => 'DELETE FROM eb_marketplace_employee WHERE id=? AND mer_id=? AND account=?',
                    'role' => 'DELETE FROM eb_marketplace_role WHERE id=? AND mer_id=? AND name=?',
                    'merchant' => 'DELETE FROM eb_marketplace_merchant WHERE mer_id=? AND name=?',
                };
                $args = match ($table) { 'employee' => [$id, $f['mer_id'], $fields['account']], 'role' => [$id, $f['mer_id'], $fields['role_name']], 'merchant' => [$id, $fields['merchant_name']] };
                $stmt = $pdo->prepare($sql); $stmt->execute($args); audit_expect($stmt->rowCount() === 1, 'fixture cleanup affects exactly one captured owned row'); $removed['rows']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    foreach ($state['owned_keys'] as $key => $owned) {
        audit_expect(!isset($state['original']['redis'][$key]) && m8w_cache_value($redis, $key) === ['sha256' => $owned['sha256'], 'deadline' => $owned['deadline']], 'registered normal account/session binding remains unchanged before deletion');
        audit_expect($redis->del($key) === 1 && $redis->exists($key) === 0, 'cleanup deletes one exact registered own cache key'); $removed['keys']++;
    }
    return $removed;
}

function m8w_sources(array &$evidence): void
{
    $files = ['crmeb/app/jobs/marketplace/AuditLogJob.php','crmeb/app/services/marketplace/queue/MarketplaceRedis.php',
        'crmeb/crmeb/services/BoundedRedisConnection.php','crmeb/config/queue.php','docker/php/supervisord.conf',
        'crmeb/app/services/marketplace/context/MerchantJobContext.php','crmeb/crmeb/services/auth/AuthSessionStore.php',
        'crmeb/crmeb/services/auth/lua/read_account.lua','crmeb/app/services/marketplace/EmployeeAuthServices.php',
        'crmeb/app/dao/marketplace/EmployeeDao.php','crmeb/app/dao/marketplace/MerchantDao.php',
        'crmeb/app/services/marketplace/MerchantPermissions.php','crmeb/app/services/marketplace/context/MerchantContext.php',
        'crmeb/app/services/marketplace/AuditLogServices.php','crmeb/app/dao/marketplace/AuditLogDao.php',
        'crmeb/crmeb/services/auth/AuthTokenV2.php','crmeb/crmeb/services/AuthConfigGuard.php',
        'help/audit-regressions/isolated/ms1-worker.php','help/audit-regressions/isolated/lib/Ms1ContextWorkerObserver.php',
        'help/audit-regressions/isolated/lib/ms1-worker-entry.php','help/audit-regressions/isolated/lib/Ms1EmptyWorkerJob.php',
        'help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php','help/audit-regressions/isolated/compose.yml'];
    $hashes = [];
    foreach ($files as $file) {
        $path = audit_repo_dir() . '/' . $file;
        if (!is_file($path)) {
            $evidence['source_availability'] = ['missing_path' => $file, 'fixtures' => 0, 'queued_jobs' => 0, 'business_requests' => 0, 'worker_startup' => 0];
            throw new RuntimeException('ordinary Task8 production source is not yet available');
        }
        $hashes[$file] = hash_file('sha256', $path);
    }
    $evidence['source_inventory'] = ['actual_files_sha256' => $hashes, 'file_count' => count($hashes)];
    audit_expect(method_exists(\app\jobs\marketplace\AuditLogJob::class, 'dispatch') && method_exists(\app\jobs\marketplace\AuditLogJob::class, 'fire')
        && method_exists(\app\services\marketplace\context\MerchantJobContext::class, 'rebuild'), 'real Task8 producer and consumer APIs are present');
}

function m8w_counter_changes(array $before, array $after): array
{
    $out = []; foreach (M8W_TABLES as $table => $pk) if (($before['ddl'][$table]['sha256'] ?? null) !== ($after['ddl'][$table]['sha256'] ?? null))
        $out[$table] = ['before_ddl_sha256' => $before['ddl'][$table]['sha256'], 'after_ddl_sha256' => $after['ddl'][$table]['sha256'],
            'same_schema_without_counter' => $before['ddl'][$table]['without_counter_sha256'] === $after['ddl'][$table]['without_counter_sha256'], 'counter_restored' => false];
    return $out;
}

function m8w_prepare(): void
{
    $statePath = audit_run_dir() . '/ms1-context-worker-state.json'; $eventPath = audit_run_dir() . '/evidence/ms1-context-worker-events.jsonl';
    $evidence = ['run_id' => getenv('AUDIT_RUN_ID'), 'guard_mode' => 'read-only', 'ddl_denial_probe' => 'NOT RUN',
        'http_requests' => 0, 'business_requests' => 0, 'queued_jobs' => 0, 'worker_startup' => 0, 'normal_service_logins' => 0,
        'authenticated_contexts' => 0, 'negative_cases_run' => false, 'M05_exception_or_no_context' => 'NOT RUN', 'M09' => 'NOT RUN', 'M10' => 'NOT RUN'];
    $state = null; $credentials = []; $failure = null; $cleanupFailure = null;
    try {
        audit_expect(!file_exists($statePath) && !file_exists($eventPath) && !file_exists(audit_run_dir() . '/ms1-context-worker-start.json'), 'ordinary Task8 preparation is new');
        $initial = m8w_snapshot(); $evidence['before'] = m8w_public_snapshot($initial);
        m8w_sources($evidence); // A natural missing source stops before all fixtures, login, dispatch and startup.
        audit_expect(count($initial['names']) === 163, 'real MS1 migration has all 163 installed tables');
        foreach (M8W_QUEUES as $key) audit_expect(audit_redis()->type($key) === Redis::REDIS_NOT_FOUND, 'each dedicated queue structure is initially absent');
        $config = audit_config(); $v2 = 'auth:v2:' . $config['auth']['session_epoch'] . ':merchantapi:';
        audit_expect(m8w_scan($v2 . '*') === [] && m8w_scan('audit:ms1-worker:' . getenv('AUDIT_RUN_ID') . ':*') === [], 'new exact auth and worker cache namespaces are initially empty');
        $state = ['run_id' => (string)getenv('AUDIT_RUN_ID'), 'status' => 'preparing', 'started_at' => time(),
            'fixtures' => [], 'jobs' => [], 'owned_keys' => [], 'audits' => [], 'original' => $initial, 'old_rows' => [],
            'source_hashes' => $evidence['source_inventory']['actual_files_sha256']];
        $pdo = audit_db(); foreach (M8W_TABLES as $table => $pk) $state['old_rows'][$table] = m8w_row_map($pdo, $table);
        audit_write_json($statePath, $state); $stream = fopen($eventPath, 'xb'); audit_expect(is_resource($stream), 'new actual observer event stream is available'); fclose($stream); chmod($eventPath, 0600);
        m8w_seed($pdo, $state, $credentials); audit_write_json($statePath, $state);
        $app = m8w_app(); $auth = $app->make(\app\services\marketplace\EmployeeAuthServices::class);
        foreach (['A', 'B'] as $index => $label) {
            $f = $state['fixtures'][$label]; $session = $auth->login($credentials[$label]['account'], $credentials[$label]['password']); $evidence['normal_service_logins']++;
            $claims = m8w_session($session, $f, $config, $state); audit_write_json($statePath, $state);
            $ctx = $auth->authenticate($session['token']); $evidence['authenticated_contexts']++;
            audit_expect($ctx->merId === $f['mer_id'] && $ctx->employeeId === $f['employee_id'] && $ctx->sessionVersion === $claims['session_version']
                && $ctx->can('merchant.profile.read') && preg_match('/^[a-f0-9]{32}$/D', $ctx->requestId) === 1, 'genuine current session builds the normal producer context');
            $originalRequestId = $ctx->requestId;
            $id = \app\jobs\marketplace\AuditLogJob::dispatch($ctx, 'merchant.profile.read', ['type' => 'merchant', 'id' => $ctx->merId]); $evidence['queued_jobs']++;
            audit_expect(is_string($id) && preg_match('/^[A-Za-z0-9]{32}$/D', $id) === 1 && $ctx->requestId === $originalRequestId, 'actual acknowledged producer returns the real ID without modifying HTTP context');
            // Before any worker permission, inspect only the two known pending
            // owned jobs for protocol evidence, not for acknowledging RPUSH.
            $items = audit_redis()->lRange('queues:marketplace', 0, -1); audit_expect(count($items) === $index + 1, 'pending RUN protocol inventory matches submission order');
            $p = m8w_payload($items[$index], $state, false);
            audit_expect($p['id'] === $id && $p['attempts'] === 0 && $p['data']['employeeId'] === $f['employee_id']
                && $p['data']['sessionVersion'] === $ctx->sessionVersion && $p['data']['requestId'] !== $originalRequestId, 'actual server signed payload carries the real version and independent task request ID');
            $state['jobs'][] = ['sequence' => $index + 1, 'employee_id' => $f['employee_id'], 'mer_id' => $f['mer_id'],
                'request_id' => $p['data']['requestId'], 'job_id_sha256' => hash('sha256', $id)]; audit_write_json($statePath, $state);
            unset($ctx, $session, $claims, $p, $items, $id); unset($credentials[$label]);
        }
        audit_expect($state['jobs'][0]['request_id'] !== $state['jobs'][1]['request_id'] && $state['jobs'][0]['job_id_sha256'] !== $state['jobs'][1]['job_id_sha256'], 'two normal actual submissions have independent identifiers');
        $prepared = m8w_snapshot(); m8w_same($initial, $prepared, ['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee'],
            array_merge(array_keys($state['owned_keys']), M8W_QUEUES), ['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee']);
        m8w_original_rows($pdo, $state);
        audit_expect(audit_redis()->pTtl('queues:marketplace') === -1 && audit_redis()->zCard('queues:marketplace:delayed') === 0
            && audit_redis()->zCard('queues:marketplace:reserved') === 0, 'prepared native queue is persistent without pending reservations or delay');
        $state['prepared'] = $prepared; $state['prepared_at'] = time(); $state['status'] = 'prepared'; audit_write_json($statePath, $state);
        $evidence['fixtures'] = array_map(static fn(array $f): array => ['mer_id' => $f['mer_id'], 'role_id' => $f['role_id'], 'employee_id' => $f['employee_id']], $state['fixtures']);
        $evidence['jobs'] = $state['jobs']; $evidence['after_prepare'] = m8w_public_snapshot($prepared); $evidence['coordination_only'] = true; $evidence['prepare_creates_pass'] = false;
        audit_write_json(audit_run_dir() . '/evidence/ms1-context-worker-prepare.json', $evidence);
        echo "Task8 ordinary preparation completed; two real production jobs await registered worker permission\n"; return;
    } catch (Throwable $error) { $failure = m8w_diag($error); $evidence['diagnostic'] = $failure; }
    unset($credentials, $ctx, $session, $claims, $p, $items);
    if (is_array($state)) {
        try {
            $evidence['cleanup'] = m8w_cleanup($state); audit_guard_assert(); $final = m8w_snapshot();
            m8w_same($state['original'], $final, [], [], array_keys(M8W_TABLES));
            $evidence['after_cleanup'] = m8w_public_snapshot($final); $evidence['natural_counter_changes'] = m8w_counter_changes($state['original'], $final);
        } catch (Throwable $error) { $cleanupFailure = m8w_diag($error); $evidence['cleanup_diagnostic'] = $cleanupFailure; }
        $state['status'] = 'preparation_failed'; audit_write_json($statePath, $state);
    } elseif (isset($initial)) {
        try {
            $final = m8w_snapshot(); m8w_same($initial, $final);
            $evidence['after_cleanup'] = m8w_public_snapshot($final);
            $evidence['cleanup'] = ['rows' => 0, 'keys' => 0, 'queue_items' => 0];
        } catch (Throwable $error) { $evidence['cleanup_diagnostic'] = m8w_diag($error); }
    }
    $ref = 'evidence/ms1-context-worker-prepare.json'; audit_write_json(audit_run_dir() . '/' . $ref, $evidence);
    audit_case('T8W01', 'normal_production_merchant_context_worker', 'WORKER', static fn(): array => ['status' => 'FAIL',
        'actual' => $failure['class'] . '@' . $failure['file'] . ':' . $failure['line'], 'expected' => 'ordinary real A then B production queue context only',
        'evidence_ref' => $ref, 'notes' => 'natural preparation failure; no substitute business, injected failure, stopped case or manufactured worker baseline']);
    audit_finish();
}

function m8w_observe(): void
{
    $statePath = audit_run_dir() . '/ms1-context-worker-state.json'; $eventPath = audit_run_dir() . '/evidence/ms1-context-worker-events.jsonl';
    $state = audit_read_control_json($statePath);
    audit_expect(($state['run_id'] ?? null) === getenv('AUDIT_RUN_ID') && ($state['status'] ?? null) === 'prepared'
        && count($state['jobs'] ?? []) === 2, 'two real prepared normal production tasks are available');
    audit_case('T8W01', 'normal_production_merchant_context_worker', 'WORKER', static function () use (&$state, $statePath, $eventPath): array {
        $evidence = ['run_id' => $state['run_id'], 'guard_mode' => 'read-only', 'ddl_denial_probe' => 'NOT RUN', 'prepare_creates_pass' => false,
            'http_requests' => 0, 'business_requests' => 0, 'queued_jobs' => 2, 'sequential_worker_entry' => true, 'negative_cases_run' => false,
            'M05_exception_or_no_context' => 'NOT RUN', 'M09' => 'NOT RUN', 'M10' => 'NOT RUN'];
        $failure = null; $receiptVerified = false;
        try {
            m8w_sources($evidence);
            audit_expect($evidence['source_inventory']['actual_files_sha256'] === $state['source_hashes'], 'actual producer consumer and auxiliary source hashes match preparation');
            $receipt = audit_read_control_json(audit_run_dir() . '/evidence/ms1-context-worker-container.json');
            $manifest = audit_manifest(); $worker = $manifest['services']['audit-worker'] ?? [];
            audit_expect(($receipt['run_id'] ?? null) === $state['run_id'] && ($receipt['container_id'] ?? null) === ($worker['id'] ?? null)
                && ($receipt['image_id'] ?? null) === ($worker['image_id'] ?? null) && ($receipt['runtime_volume'] ?? null) === ($worker['runtime_volume'] ?? null)
                && is_string($worker['id'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $worker['id']) === 1
                && is_string($worker['image_id'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/D', $worker['image_id']) === 1
                && ($worker['runtime_volume'] ?? null) === $manifest['project'] . '_worker-runtime'
                && in_array($worker['runtime_volume'], $manifest['volumes'] ?? [], true) && isset($manifest['volume_identities'][$worker['runtime_volume']])
                && is_int($receipt['native_exit'] ?? null) && ($receipt['status'] ?? null) === 'exited', 'actual registered worker is terminated before observation or cleanup');
            $receiptVerified = true; $evidence['container'] = $receipt;
            audit_expect(($receipt['restart_count'] ?? null) === 0 && $receipt['native_exit'] === 0, 'real native worker exited normally without restart');
            $lines = file($eventPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); audit_expect(is_array($lines) && count($lines) === 4, 'two real processing and processed event pairs are present');
            $events = array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR), $lines); $completed = [];
            foreach ($events as $index => $event) {
                $sequence = intdiv($index, 2) + 1; $kind = $index % 2 === 0 ? 'processing' : 'processed'; $expected = $state['jobs'][$sequence - 1];
                audit_expect(($event['run_id'] ?? null) === $state['run_id'] && ($event['sequence'] ?? null) === $sequence && ($event['event'] ?? null) === $kind
                    && ($event['job_id_sha256'] ?? null) === $expected['job_id_sha256'] && ($event['name'] ?? null) === \app\jobs\marketplace\AuditLogJob::class
                    && ($event['connection'] ?? null) === 'marketplace' && ($event['queue'] ?? null) === 'marketplace' && ($event['actual_redis_job'] ?? null) === true
                    && is_int($event['pid'] ?? null) && $event['pid'] > 0 && is_string($event['start_ticks'] ?? null)
                    && preg_match('/^[1-9][0-9]*$/D', $event['start_ticks']) === 1 && ($event['hostname'] ?? null) === substr($worker['id'], 0, 12)
                    && !isset($event['diagnostic']), 'each normal observation belongs to the exact real native production job and process');
                audit_expect(($event['no_transaction'] ?? null) === true, 'ordinary real processing and completion leave no default transaction open');
                if ($kind === 'processing') audit_expect(($event['reservation_present'] ?? null) === true, 'actual native reservation exists before normal production fire');
                if ($kind === 'processed') {
                    audit_expect(($event['deleted'] ?? null) === true && ($event['released'] ?? null) === false && ($event['failed'] ?? null) === false
                        && ($event['reservation_absent'] ?? null) === true && ($event['requested_quit'] ?? null) === ($sequence === 2)
                        && is_int($event['handler_object_id'] ?? null) && $event['handler_object_id'] > 0 && is_int($event['audit_id'] ?? null) && $event['audit_id'] > 0
                        && ($event['audit_mer_id'] ?? null) === $expected['mer_id'] && ($event['audit_employee_id'] ?? null) === $expected['employee_id']
                        && ($event['audit_actor_type'] ?? null) === 'merchant' && ($event['audit_action'] ?? null) === 'merchant.profile.read'
                        && ($event['audit_resource_type'] ?? null) === 'merchant' && ($event['audit_resource_id'] ?? null) === (string)$expected['mer_id']
                        && ($event['audit_result'] ?? null) === 'success' && ($event['audit_detail_type'] ?? null) === 'OBJECT'
                        && ($event['audit_detail_length'] ?? null) === 0, 'actual producer handler completed its own audit and strict native deletion before observation');
                    $completed[] = $event;
                }
            }
            foreach ($events as $event) audit_expect($event['pid'] === $events[0]['pid'] && $event['start_ticks'] === $events[0]['start_ticks'], 'all real observations share the same PID and process start ticks');
            audit_expect($completed[0]['handler_object_id'] === $completed[1]['handler_object_id'], 'real production handler instance is reused by the container');
            $pdo = audit_db(); $audits = m8w_audits($pdo, $state, true);
            foreach (['A', 'B'] as $index => $label) audit_expect($audits[$label]['id'] === $completed[$index]['audit_id'], 'normal completion observes the actual persisted owned audit ID');
            m8w_original_rows($pdo, $state, array_column($audits, 'id'));
            $redis = audit_redis(); foreach (M8W_QUEUES as $key) audit_expect($redis->exists($key) === 0, 'all native finite queue structures are already absent before cleanup');
            foreach ($state['owned_keys'] as $key => $owned) audit_expect(m8w_cache_value($redis, $key) === ['sha256' => $owned['sha256'], 'deadline' => $owned['deadline']], 'consumption reads genuine account/session state without writes or expiry extension');
            $after = m8w_snapshot(); m8w_same($state['original'], $after, array_keys(M8W_TABLES), array_merge(array_keys($state['owned_keys']), M8W_QUEUES), array_keys(M8W_TABLES));
            $evidence['events'] = $events; $evidence['audits'] = $audits; $evidence['after_worker'] = m8w_public_snapshot($after);
            $evidence['checks'] = [['passed' => true, 'description' => 'actual A then B production tasks share PID/start ticks and handler; current merchant audits commit and native queue deletion completes']];
        } catch (Throwable $error) { $failure = m8w_diag($error); $evidence['diagnostic'] = $failure; }
        try {
            audit_expect($receiptVerified, 'cleanup only follows a verified terminated native worker');
            $evidence['cleanup'] = m8w_cleanup($state); audit_guard_assert(); $final = m8w_snapshot();
            m8w_same($state['original'], $final, [], [], array_keys(M8W_TABLES));
            $evidence['after_cleanup'] = m8w_public_snapshot($final); $evidence['natural_counter_changes'] = m8w_counter_changes($state['original'], $final);
            $evidence['registered_cache_keys'] = array_map(static fn(string $key): array => ['key_sha256' => hash('sha256', $key), 'kind' => $state['owned_keys'][$key]['kind']], array_keys($state['owned_keys']));
            $evidence['exact_fixture_cleanup'] = true; $evidence['snapshot_restore_used'] = false; $evidence['counter_restored'] = false;
        } catch (Throwable $error) { $failure ??= m8w_diag($error); $evidence['cleanup_diagnostic'] = m8w_diag($error); }
        $state['status'] = $failure === null ? 'observed' : 'observation_failed'; audit_write_json($statePath, $state);
        $ref = 'evidence/ms1-context-worker.json'; audit_write_json(audit_run_dir() . '/' . $ref, $evidence);
        return ['status' => $failure === null ? 'PASS' : 'FAIL', 'actual' => $failure === null
            ? 'real valid A then B production jobs rebuild current context in one PID and cached handler; exact audits/delete/normal exit and cleanup observed'
            : $failure['class'] . '@' . $failure['file'] . ':' . $failure['line'], 'expected' => 'ordinary same-PID production merchant context only',
            'evidence_ref' => $ref, 'notes' => 'real sequential worker; original M05 exception/no-context, M09/M10, security, fault, recovery and complete MS1 acceptance NOT RUN'];
    });
    audit_finish();
}

try {
    audit_expect(count($argv) === 2 && in_array($argv[1], ['--prepare', '--observe'], true), 'ordinary Task8 prepare or observe mode is explicit');
    if ($argv[1] === '--prepare') m8w_prepare(); else m8w_observe();
} catch (Throwable $error) { fwrite(STDERR, json_encode(m8w_diag($error), JSON_THROW_ON_ERROR) . "\n"); exit(2); }
