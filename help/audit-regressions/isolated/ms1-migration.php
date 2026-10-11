<?php
declare(strict_types=1);
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');

function m1m_snapshot(): array
{
    $db = audit_db(); $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $spec = ['tables' => [], 'redis' => ['*']]; $ddl = []; $checksums = [];
    foreach ($names as $name) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $name) === 1, 'snapshot table name differs');
        $spec['tables'][$name] = null;
        $create = $db->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM);
        audit_expect(is_array($create) && isset($create[1]), 'actual table DDL unavailable');
        $ddl[$name] = ['sha256' => hash('sha256', $create[1])];
        if ($name === 'eb_system_menus') {
            $ddl[$name]['without_counter_sha256'] = hash('sha256', preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+ /', '$1 ', $create[1]));
        }
        $checksum = $db->query('CHECKSUM TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
        audit_expect(is_array($checksum) && array_key_exists('Checksum', $checksum), 'native table checksum unavailable');
        $checksums[$name] = $checksum['Checksum'] === null ? null : (string)$checksum['Checksum'];
    }
    $raw = audit_snapshot($spec); $keys = array_keys($raw['redis']['*']); $redis = [];
    $deadlines = $keys ? audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r", $keys, count($keys)) : [];
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'cache deadlines unavailable');
    foreach ($keys as $i => $key) $redis[hash('sha256', $key)] = ['sha256' => $raw['redis']['*'][$key], 'deadline' => $deadlines[$i]];
    ksort($redis); $menus = [];
    foreach ($db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll() as $row) $menus[(string)$row['id']] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    return ['names' => $names, 'rows' => $raw['tables'], 'ddl' => $ddl, 'checksums' => $checksums, 'menus' => $menus, 'redis' => $redis];
}

function m1m_assert_old(array $before, array $after, bool $menuAdditions): array
{
    foreach ($before['names'] as $name) {
        audit_expect(isset($after['rows'][$name], $after['ddl'][$name]), 'original table disappeared');
        if ($name === 'eb_system_menus' && $menuAdditions) {
            audit_expect($before['ddl'][$name]['without_counter_sha256'] === $after['ddl'][$name]['without_counter_sha256'], 'original menus structure changed');
            foreach ($before['menus'] as $id => $hash) audit_expect(($after['menus'][$id] ?? null) === $hash, 'original menu row changed');
        } else {
            audit_expect($before['rows'][$name] === $after['rows'][$name] && $before['ddl'][$name] === $after['ddl'][$name]
                && $before['checksums'][$name] === $after['checksums'][$name], 'original table structure, rows or checksum changed');
        }
    }
    audit_expect(array_keys($before['redis']) === array_keys($after['redis']), 'cache key set changed');
    foreach ($before['redis'] as $key => $value) {
        $current = $after['redis'][$key];
        audit_expect($value['sha256'] === $current['sha256'], 'cache value changed');
        audit_expect(($value['deadline'] < 0 || $current['deadline'] < 0)
            ? $value['deadline'] === $current['deadline'] : abs($value['deadline'] - $current['deadline']) <= 1, 'cache expiry changed');
    }
    return ['original_tables' => count($before['names']), 'original_menu_rows' => count($before['menus']),
        'non_menu_checksums_available' => count(array_filter($before['checksums'], static fn($v, $k) => $k !== 'eb_system_menus' && $v !== null, ARRAY_FILTER_USE_BOTH)),
        'old_structure_and_rows_unchanged' => true, 'cache_values_and_expiry_unchanged' => true];
}

function m1m_cli(string $mode, string $configFile): array
{
    $argv = [PHP_BINARY, audit_repo_dir() . '/help/migrations/marketplace-ms1.php', $mode, '--config-file=' . $configFile];
    $process = proc_open($argv, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, audit_repo_dir());
    audit_expect(is_resource($process), 'native migration CLI could not start');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $native = proc_close($process); $json = is_string($stdout) ? json_decode($stdout, true) : null;
    return ['mode' => $mode, 'native_exit' => $native, 'stderr_bytes' => strlen((string)$stderr),
        'stdout_sha256' => hash('sha256', (string)$stdout), 'stderr_sha256' => hash('sha256', (string)$stderr), 'result' => $json];
}

/** Compare fixed contract clauses without dropping operator grouping or string case. */
function m1m_check_clause(string $clause): string
{
    $value = preg_replace_callback("/'(?:[^']|'')*'|[^']+/", static function (array $match): string {
        if (str_starts_with($match[0], "'")) return $match[0];
        return preg_replace('/\s+/', '', str_replace(['`', '_utf8mb4'], '', strtolower($match[0])));
    }, $clause);
    while (str_starts_with($value, '(') && str_ends_with($value, ')')) {
        $depth = 0; $quoted = false; $whole = true;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            if ($value[$i] === "'") { if ($quoted && ($value[$i + 1] ?? '') === "'") { $i++; continue; } $quoted = !$quoted; }
            if ($quoted) continue;
            if ($value[$i] === '(') $depth++;
            elseif ($value[$i] === ')') $depth--;
            if ($depth === 0 && $i < $length - 1) { $whole = false; break; }
        }
        if (!$whole || $depth !== 0 || $quoted) break;
        $value = substr($value, 1, -1);
    }
    return $value;
}

/** Independently derive column contracts from the frozen requirement DDL. */
function m1m_columns(PDO $db): array
{
    $design = file_get_contents(audit_repo_dir() . '/help/migrations/marketplace-ms1.md');
    preg_match_all('/CREATE TABLE `(eb_marketplace_[a-z_]+)` \((.*?)\) ENGINE=InnoDB/s', $design, $tables, PREG_SET_ORDER);
    audit_expect(count($tables) === 5, 'five frozen DDL definitions required'); $metadata = []; $indexMetadata = [];
    foreach ($tables as $definition) {
        preg_match_all('/^  `([a-z_]+)` ([A-Z]+(?:\([0-9]+\))?(?: UNSIGNED)?)([^\n]*)$/m', $definition[2], $columns, PREG_SET_ORDER);
        $statement = $db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$definition[1]]); $actual = $statement->fetchAll();
        audit_expect(array_column($actual, 'COLUMN_NAME') === array_column($columns, 1), 'new columns differ from frozen DDL');
        foreach ($columns as $i => $column) {
            $type = strtolower($column[2]); $attributes = $column[3]; $default = null;
            if (preg_match("/DEFAULT '([^']*)'/", $attributes, $match)) $default = $match[1];
            elseif (preg_match('/DEFAULT ([0-9]+)/', $attributes, $match)) $default = $match[1];
            $row = $actual[$i]; $actualType = preg_replace('/\b(tinyint|smallint|int|bigint)\([0-9]+\)/', '$1', strtolower($row['COLUMN_TYPE']));
            audit_expect($actualType === $type && $row['IS_NULLABLE'] === (str_contains($attributes, 'NOT NULL') ? 'NO' : 'YES')
                && ($row['COLUMN_DEFAULT'] === null ? null : (string)$row['COLUMN_DEFAULT']) === $default
                && $row['EXTRA'] === (str_contains($attributes, 'AUTO_INCREMENT') ? 'auto_increment' : ''), 'new column type/default/nullability differs');
            if (str_starts_with($type, 'varchar')) audit_expect($row['CHARACTER_SET_NAME'] === 'utf8mb4' && $row['COLLATION_NAME'] === 'utf8mb4_bin', 'new string collation differs');
            if ($column[1] === 'mer_id') audit_expect($row['COLUMN_DEFAULT'] === null && $row['IS_NULLABLE'] === 'NO' && $actualType === 'int unsigned', 'required mer_id contract differs');
        }
        audit_expect((int)$db->query('SELECT COUNT(*) FROM `' . $definition[1] . '`')->fetchColumn() === 0, 'migration must not seed identity data');
        $metadata[$definition[1]] = $actual;
        $expectedIndexes = [];
        preg_match_all('/^  (PRIMARY KEY|UNIQUE KEY `[a-z_]+`|KEY `[a-z_]+`) \(([^\n]+)\),?$/m', $definition[2], $keys, PREG_SET_ORDER);
        foreach ($keys as $key) {
            preg_match_all('/`([a-z_]+)`/', $key[2], $parts);
            $name = $key[1] === 'PRIMARY KEY' ? 'PRIMARY' : explode('`', $key[1])[1];
            $expectedIndexes[$name] = ['unique' => !str_starts_with($key[1], 'KEY '), 'columns' => $parts[1]];
        }
        $statement = $db->prepare('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$definition[1]]); $actualIndexes = []; $indexes = $statement->fetchAll();
        foreach ($indexes as $index) {
            $name = $index['INDEX_NAME']; $actualIndexes[$name] ??= ['unique'=>(int)$index['NON_UNIQUE'] === 0, 'columns'=>[]];
            audit_expect((int)$index['SEQ_IN_INDEX'] === count($actualIndexes[$name]['columns']) + 1 && $index['SUB_PART'] === null
                && $index['EXPRESSION'] === null && $index['INDEX_TYPE'] === 'BTREE' && $index['IS_VISIBLE'] === 'YES' && $index['COLLATION'] === 'A', 'actual index shape differs');
            $actualIndexes[$name]['columns'][] = $index['COLUMN_NAME'];
        }
        ksort($expectedIndexes); ksort($actualIndexes);
        audit_expect($actualIndexes === $expectedIndexes, 'primary, unique or ordinary indexes differ from frozen DDL');
        $indexMetadata[$definition[1]] = $indexes;
    }
    $constraints = $db->query("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME IN ('eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_audit_log','eb_marketplace_password_setup') AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME")->fetchAll();
    preg_match_all('/CONSTRAINT `(eb_mm_[a-z_]+)` CHECK/', $design, $checkNames); $expectedNames = $checkNames[1]; sort($expectedNames, SORT_STRING);
    $actualNames = array_column($constraints, 'CONSTRAINT_NAME'); sort($actualNames, SORT_STRING);
    audit_expect(count($constraints) === 10 && $actualNames === $expectedNames, 'actual CHECK names differ');
    $expectedClauses = ['eb_mm_mer_review'=>'review_status in (0,1,2)',
        'eb_mm_mer_flags'=>'(accept_orders in (0,1)) and (fulfillment_enabled in (0,1)) and (security_locked in (0,1))',
        'eb_mm_role_positive'=>'mer_id > 0', 'eb_mm_role_permissions'=>"json_type(permissions) = 'ARRAY'",
        'eb_mm_emp_positive'=>'(mer_id > 0) and (role_id > 0)', 'eb_mm_emp_flags'=>'(status in (0,1)) and (is_del in (0,1))',
        'eb_mm_audit_positive'=>'(mer_id > 0) and (actor_id > 0)', 'eb_mm_audit_detail'=>"json_type(detail) = 'OBJECT'",
        'eb_mm_setup_positive'=>'(mer_id > 0) and (employee_id > 0)', 'eb_mm_setup_expiry'=>'expires_at = (add_time + 86400)'];
    foreach ($constraints as $constraint) audit_expect($constraint['ENFORCED'] === 'YES'
        && m1m_check_clause($constraint['CHECK_CLAUSE']) === m1m_check_clause($expectedClauses[$constraint['CONSTRAINT_NAME']]), 'actual CHECK predicate or enforcement differs');
    $fk = $db->query("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME IN ('eb_marketplace_role','eb_marketplace_employee','eb_marketplace_audit_log','eb_marketplace_password_setup') AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll();
    audit_expect(count($fk) === 6, 'four exact foreign keys required');
    $expectedFk = ['eb_marketplace_role' => ['eb_marketplace_merchant', ['mer_id'], ['mer_id']],
        'eb_marketplace_employee' => ['eb_marketplace_role', ['mer_id', 'role_id'], ['mer_id', 'id']],
        'eb_marketplace_audit_log' => ['eb_marketplace_merchant', ['mer_id'], ['mer_id']],
        'eb_marketplace_password_setup' => ['eb_marketplace_employee', ['mer_id', 'employee_id'], ['mer_id', 'id']]];
    $fkNames = ['eb_marketplace_role'=>'eb_mm_role_merchant_fk','eb_marketplace_employee'=>'eb_mm_emp_role_fk',
        'eb_marketplace_audit_log'=>'eb_mm_audit_merchant_fk','eb_marketplace_password_setup'=>'eb_mm_setup_employee_fk'];
    foreach ($expectedFk as $table => [$target, $sourceColumns, $targetColumns]) {
        $rows = array_values(array_filter($fk, static fn(array $row): bool => $row['TABLE_NAME'] === $table));
        audit_expect(array_column($rows, 'COLUMN_NAME') === $sourceColumns && array_column($rows, 'REFERENCED_COLUMN_NAME') === $targetColumns, 'same-merchant foreign key columns differ');
        foreach ($rows as $i => $row) audit_expect($row['CONSTRAINT_NAME'] === $fkNames[$table] && (int)$row['ORDINAL_POSITION'] === $i + 1
            && $row['REFERENCED_TABLE_SCHEMA'] === 'audit_auth' && $row['REFERENCED_TABLE_NAME'] === $target
            && $row['UPDATE_RULE'] === 'RESTRICT' && $row['DELETE_RULE'] === 'RESTRICT', 'same-merchant foreign key identity, order, target or actions differ');
    }
    return ['columns' => $metadata, 'indexes' => $indexMetadata, 'checks' => $constraints, 'foreign_keys' => $fk];
}

function m1m_new_menus(PDO $db, array $before): array
{
    $rows = $db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll();
    $new = array_values(array_filter($rows, static fn(array $row): bool => !array_key_exists((string)$row['id'], $before['menus'])));
    audit_expect(count($new) === 7, 'exactly seven new interface registrations required');
    $common = ['pid'=>'0','icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>'1','is_show'=>'1','is_show_path'=>'1','access'=>'1','menu_path'=>'','path'=>'','auth_type'=>'2','header'=>'','is_header'=>'0','is_del'=>'0','mark'=>'marketplace-ms1-v1'];
    $specific = [
        ['商家列表','GET','marketplace/merchants','marketplace-merchants-list'],
        ['新建商家','POST','marketplace/merchants','marketplace-merchants-create'],
        ['商家详情','GET','marketplace/merchants/<id>','marketplace-merchants-read'],
        ['修改商家','PUT','marketplace/merchants/<id>','marketplace-merchants-update'],
        ['审核商家','PUT','marketplace/merchants/<id>/review','marketplace-merchants-review'],
        ['商家开关','PUT','marketplace/merchants/<id>/flags','marketplace-merchants-flags'],
        ['商家管理员','POST','marketplace/merchants/<id>/admin-employee','marketplace-merchants-admin-employee']];
    $ids = []; $seen = [];
    foreach ($new as $row) {
        $expected = null;
        foreach ($specific as [$name,$method,$url,$auth]) if ($row['unique_auth'] === $auth) $expected = $common + ['menu_name'=>$name,'methods'=>$method,'api_url'=>$url,'unique_auth'=>$auth];
        audit_expect(is_array($expected) && !isset($seen[$row['unique_auth']]) && (int)$row['id'] > 0, 'actual interface registration identity differs');
        $seen[$row['unique_auth']] = true; $ids[] = (int)$row['id']; unset($row['id']);
        $row = array_map('strval', $row); ksort($row); ksort($expected);
        audit_expect($row === $expected, 'full interface registration fields differ');
    }
    sort($ids, SORT_NUMERIC); return $ids;
}

audit_case('T2M01', 'normal_check_apply_repeat', 'INTEGRATION', static function (): array {
    $evidence = ['run_id' => getenv('AUDIT_RUN_ID'), 'steps' => [], 'negative_cases_run' => false];
    $configFile = '/run/audit-bootstrap/ms1-migration-cli-config-' . getenv('AUDIT_RUN_ID') . '.json'; $createdConfig = false; $failure = null;
    try {
        $before = m1m_snapshot(); $evidence['before'] = $before;
        audit_expect(count($before['names']) === 158, 'fresh installed base with no marketplace tables required');
        audit_expect(is_file(audit_repo_dir() . '/help/migrations/marketplace-ms1.php'), 'MS1 migration implementation unavailable');
        audit_expect(is_file('/run/audit-bootstrap/migrator.json') && !file_exists($configFile), 'dedicated bootstrap migrator required');
        $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 32, JSON_THROW_ON_ERROR);
        audit_expect(($migrator['username'] ?? '') === 'audit_migrator', 'dedicated migrator identity differs');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        audit_expect(count($grants) === 2 && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO '))) === 1, 'migrator must retain only its existing dedicated schema privileges');
        audit_write_json($configFile, ['hostname'=>'audit-mysql','hostport'=>'3306','database'=>'audit_auth','username'=>$migrator['username'],'password'=>$migrator['password'],'prefix'=>'eb_']);
        $createdConfig = true;
        $step = m1m_cli('check', $configFile); $evidence['steps'][] = $step;
        audit_expect($step['native_exit'] === 0 && $step['stderr_bytes'] === 0 && ($step['result']['state'] ?? '') === 'applicable' && ($step['result']['changed'] ?? null) === false, 'first check must be native0 applicable and read-only');
        $checked = m1m_snapshot(); m1m_assert_old($before, $checked, false); audit_expect($before['names'] === $checked['names'], 'read-only check created tables');
        $step = m1m_cli('apply', $configFile); $evidence['steps'][] = $step;
        audit_expect($step['native_exit'] === 0 && $step['stderr_bytes'] === 0 && ($step['result']['state'] ?? '') === 'completed' && ($step['result']['changed'] ?? null) === true, 'first apply must return native0 and complete its exact additions');
        $applied = m1m_snapshot(); $newTables = array_values(array_diff($applied['names'], $before['names'])); sort($newTables, SORT_STRING);
        $wanted = ['eb_marketplace_audit_log','eb_marketplace_employee','eb_marketplace_merchant','eb_marketplace_password_setup','eb_marketplace_role'];
        audit_expect($newTables === $wanted && count($applied['names']) === 163, 'first apply must add exactly five tables');
        $evidence['preservation'] = m1m_assert_old($before, $applied, true); $evidence['new_menu_ids'] = m1m_new_menus($db, $before);
        $evidence['metadata'] = m1m_columns($db); $firstIds = $step['result']['menu_ids'] ?? []; sort($firstIds, SORT_NUMERIC);
        audit_expect($firstIds === $evidence['new_menu_ids'], 'CLI menu IDs must be actual new rows');
        foreach (['check','apply'] as $mode) {
            $step = m1m_cli($mode, $configFile); $evidence['steps'][] = $step;
            audit_expect($step['native_exit'] === 0 && $step['stderr_bytes'] === 0 && ($step['result']['state'] ?? '') === 'completed' && ($step['result']['changed'] ?? null) === false, 'repeated check/apply must be native0 read-only completed');
            $ids = $step['result']['menu_ids'] ?? []; sort($ids, SORT_NUMERIC);
            audit_expect($ids === $evidence['new_menu_ids'], 'repeated registration IDs differ');
            $again = m1m_snapshot(); m1m_assert_old($applied, $again, false); audit_expect($again['names'] === $applied['names'], 'repeated apply added objects');
        }
        audit_guard_assert(); $evidence['after'] = $again;
    } catch (Throwable $error) {
        $failure = ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]; $evidence['diagnostic'] = $failure;
        if (isset($before)) { $evidence['actual_after_failure'] = m1m_snapshot(); }
    } finally {
        if ($createdConfig && is_file($configFile)) { audit_expect(unlink($configFile), 'private CLI config cleanup failed'); }
    }
    $ref = 'evidence/ms1-migration-' . (getenv('AUDIT_PHASE') ?: 'after') . '.json'; audit_write_json(audit_run_dir() . '/' . $ref, $evidence);
    return ['status'=>$failure === null ? 'PASS' : 'FAIL', 'actual'=>$failure === null ? 'real CLI check/apply/check/apply native0; exactly five empty tables and seven interfaces; original rows/schema/cache preserved' : $failure['class'].'@'.$failure['file'].':'.$failure['line'],
        'expected'=>'normal installed-base migration and idempotent repeat only','evidence_ref'=>$ref,'notes'=>'dedicated bootstrap migrator; new objects retained until whole RUN teardown; no rollback or negative scenario'];
});
audit_case('T2M02', 'heterogeneous_existing_schema', 'INTEGRATION', static fn(): array => ['status'=>'NOT RUN','actual'=>'skipped at user request; incompatible structures, invalid input and faults were not created','expected'=>'original incompatible-schema acceptance remains unverified']);
audit_finish();
