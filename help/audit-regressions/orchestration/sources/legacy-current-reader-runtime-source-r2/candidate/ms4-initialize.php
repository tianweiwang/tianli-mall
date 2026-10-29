<?php
declare(strict_types=1);

// Existing functions only; the original MS2 entry is not invoked by this filename.
require '/workspace/help/audit-regressions/isolated/ms2-migration.php';

function m4i_read(string $path): array
{
    m2f_assert(is_file($path) && !is_link($path), 'PUBLIC_BOUND_FILE');
    $value = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    m2f_assert(is_array($value) && !array_is_list($value), 'PUBLIC_BOUND_OBJECT'); return $value;
}
function m4i_gate(string $phase): array
{
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA'); $proof = getenv('MS4_PROOF_NAME');
    $roles = ['prepare' => ['prepare', 'audit-bootstrap'], 'register-initializer' => ['register', 'audit-bootstrap'],
        'register-driver' => ['register', 'audit-bootstrap'], 'authorize-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'register-legacy-files-bootstrap' => ['register', 'audit-bootstrap'],
        'register-legacy-history-producer' => ['register', 'audit-bootstrap'],
        'register-legacy-current-reader' => ['register', 'audit-bootstrap'],
        'initializer' => ['initialize', 'audit-ms4-initialize']];
    m2f_assert(isset($roles[$proof]) && $roles[$proof][0] === $phase && PHP_SAPI === 'cli'
        && audit_repo_dir() === '/workspace' && audit_run_dir() === '/run/audit'
        && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && getenv('AUDIT_ISOLATED') === '1'
        && getenv('AUDIT_GUARD_MODE') === 'read-only' && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', (string) $run) === 1
        && preg_match('/\A[a-f0-9]{40}\z/D', (string) $sha) === 1 && getenv('AUDIT_PROJECT') === 'tianli-audit-' . $run, 'ACTUAL_INITIALIZER_ENTRY');
    $context = m4i_read('/run/audit/actual-run-context.json');
    $binding = m4i_read('/run/audit/preparation-source-binding.json');
    $facts = m4i_read('/run/audit/evidence/' . $proof . '-created.json');
    m2f_assert($context['run_id'] === $run && $context['source_sha'] === $sha && $binding['source_sha'] === $sha
        && hash_file('sha256', '/run/audit/preparation-source-binding.json') === $context['freeze_sha256']
        && $facts['run_id'] === $run && $facts['source_sha'] === $sha && $facts['proof_name'] === $proof
        && preg_match('/\A[a-f0-9]{64}\z/D', $facts['id']) === 1 && gethostname() === substr($facts['id'], 0, 12)
        && $facts['hostname'] === gethostname() && $facts['image'] === M2F_IMAGE && $facts['status'] === 'created'
        && $facts['restart_count'] === 0 && $facts['read_only'] === true && $facts['privileged'] === false
        && in_array($facts['port_bindings'] ?? null, [null, []], true) && ($facts['cap_drop'] ?? null) === ['ALL']
        && in_array($facts['security_options'] ?? null, [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $facts['entrypoint'] === ['php'] && $facts['workdir'] === '/workspace'
        && $facts['labels']['tianli.audit'] === '1' && $facts['labels']['tianli.audit.run'] === $run
        && $facts['labels']['com.docker.compose.project'] === $context['project']
        && $facts['labels']['com.docker.compose.service'] === $roles[$proof][1], 'ACTUAL_CREATED_HELPER');
    $command = ['/run/ms4-prep/ms4-initialize.php'];
    if ($phase !== 'initialize') $command[] = $phase;
    m2f_assert($facts['command'] === $command, 'FINITE_HELPER_COMMAND');
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'], '/run/ms4-prep' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $source) {
        $mount = m2f_mount($facts, $destination);
        m2f_assert($mount['Type'] === 'bind' && $mount['RW'] === ($destination === '/run/audit')
            && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($source), 'EXACT_HELPER_SOURCE_RUN_MOUNTS');
    }
    $privateVolumes = ['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'];
    if ($phase !== 'initialize') {
        $privateVolumes['redis-secrets'] = '/run/audit-redis';
        $privateVolumes['fixture-secrets'] = '/run/audit-fixtures';
    }
    foreach ($privateVolumes as $name => $destination) {
        $mount = m2f_mount($facts, $destination);
        m2f_assert($mount['Type'] === 'volume' && $mount['Name'] === $context['project'] . '_' . $name
            && $mount['RW'] === ($phase === 'prepare'), 'PRIVATE_HELPER_MOUNT');
    }
    $destinations = []; $seen = [];
    foreach ($facts['mounts'] as $mount) {
        m2f_assert(!isset($seen[$mount['Destination']]), 'HELPER_ONE_MOUNT_PER_DESTINATION'); $seen[$mount['Destination']] = true;
        if ($mount['Type'] === 'tmpfs') {
            m2f_assert($mount['Destination'] === '/tmp' && $mount['RW'] === true, 'HELPER_ONLY_TMP_TMPFS');
        } else $destinations[] = $mount['Destination'];
    }
    $wantedDestinations = array_merge(['/workspace', '/source', '/run/ms4-prep', '/run/audit'], array_values($privateVolumes));
    sort($destinations, SORT_STRING); sort($wantedDestinations, SORT_STRING);
    m2f_assert($destinations === $wantedDestinations && array_keys($facts['tmpfs']) === ['/tmp'], 'HELPER_EXACT_ROLE_MOUNT_DESTINATIONS');
    m2f_assert(isset($facts['tmpfs']['/tmp']), 'PRIVATE_TMPFS_CONFIG');
    $tmp = false; foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
    }
    m2f_assert($tmp && !file_exists('/workspace/crmeb/.env') && !file_exists('/workspace/crmeb/public/install.lock'), 'REAL_TMPFS_NO_HOST_CONFIGURATION');
    foreach ($binding['driver_source_files'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    foreach ($binding['preparation_source_references'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    foreach ($binding['artifact_files'] as $item) m4i_sourceFile('/run/ms4-prep', $item);
    $network = m4i_read('/run/audit/network.json');
    m2f_assert($network['Id'] === $context['network_id'] && $network['Name'] === $context['project'] . '_isolation'
        && $facts['network_mode'] === $network['Name'] && $network['Internal'] === true
        && $network['Labels']['tianli.audit.run'] === $run && $network['Labels']['com.docker.compose.project'] === $context['project'], 'NATIVE_HELPER_INTERNAL_NETWORK');
    return ['container_id' => $facts['id'], 'entry_namespace_pid' => getmypid(), 'source_sha' => $sha,
        'created_sha256' => hash_file('sha256', '/run/audit/evidence/' . $proof . '-created.json'), 'freeze_sha256' => $context['freeze_sha256']];
}
function m4i_sourceFile(string $root, array $item): void
{
    $path = $item['path']; m2f_path($path); $file = $root . '/' . $path;
    m2f_assert(is_file($file) && !is_link($file) && filesize($file) === $item['bytes'] && hash_file('sha256', $file) === $item['sha256'], 'ACTUAL_INITIALIZER_SOURCE_BYTES');
}
function m4i_source(): array
{
    $binding = m4i_gate('initialize'); $manifest = audit_manifest(); $context = m4i_read('/run/audit/actual-run-context.json');
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === ['audit-ms4-initialize', 'audit-mysql', 'audit-redis'] && $manifest['run_id'] === $context['run_id']
        && $manifest['commit_sha'] === $context['source_sha'] && $manifest['guard_mode'] === 'read-only'
        && $manifest['network_internal'] === true && $manifest['network']['id'] === $context['network_id']
        && $manifest['services']['audit-ms4-initialize']['id'] === $binding['container_id'], 'REGISTERED_INITIALIZER_THREE_ROLES');
    foreach (['audit-mysql', 'audit-redis'] as $host) m2f_assert(gethostbyname($host) === $manifest['services'][$host]['address']
        && filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'NATIVE_INITIALIZER_ENDPOINT');
    return $binding;
}

function m4i_seed(array &$evidence):array {
 m4i_source();$manifest=audit_manifest();$rootMeta=m2f_file('/run/audit-bootstrap/root-password');$migratorMeta=m2f_file('/run/audit-bootstrap/migrator.json');$appMeta=m2f_file('/run/audit-app/config.json');
 $rootPassword=file_get_contents('/run/audit-bootstrap/root-password');$migrator=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,8,JSON_THROW_ON_ERROR);$config=audit_config();m2f_assert(($migrator['username']??null)==='audit_migrator'&&($config['database']['username']??null)==='audit_app'&&($config['run_id']??null)===$manifest['run_id'],'PREPARED_PRIVATE_ACCOUNTS');
 $root=new PDO('mysql:host=audit-mysql;port=3306;charset=utf8mb4','root',$rootPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>3]);$evidence['profile_sql_modes_before_any_DDL']=m2f_modeReceipt(m2f_profileModes($root));m2f_assert((int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='audit_auth'")->fetchColumn()===0&&(int)$root->query("SELECT COUNT(*) FROM mysql.user WHERE User IN ('audit_migrator','audit_app')")->fetchColumn()===0,'FRESH_SCHEMA_AND_ACCOUNTS');
 $raw=file_get_contents(audit_repo_dir().'/crmeb/public/install/crmeb.sql');m2f_assert(is_string($raw)&&hash('sha256',str_replace("\r",'',$raw))===$manifest['fixture_sha256'],'REAL_INSTALL_SOURCE');$matched=preg_match_all('/^CREATE TABLE IF NOT EXISTS `(eb_[a-z0-9_]+)` \(\r?\n.*?^\) ENGINE=[^\r\n]*;(?=\r?\n|\z)/ms',$raw,$blocks,PREG_SET_ORDER);m2f_assert($matched===157&&preg_match_all('/^CREATE TABLE IF NOT EXISTS /m',$raw)===157,'EXACT_157_CREATE_BLOCKS');$inventory=[];$names=[];foreach($blocks as $block){m2f_assert(!isset($names[$block[1]]),'CREATE_TABLE_UNIQUE');$names[$block[1]]=true;$inventory[]=['table'=>$block[1],'bytes'=>strlen($block[0]),'sha256'=>hash('sha256',$block[0])];}
 m4i_source();m2f_assert($root->exec('CREATE DATABASE audit_auth CHARACTER SET utf8mb4')!==false,'CREATE_DEDICATED_SCHEMA');m2f_assert((int)$root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='audit_auth'")->fetchColumn()===0,'FRESH_SCHEMA_EMPTY');
 foreach([$migrator,$config['database']] as $account){m4i_source();m2f_assert($root->exec('CREATE USER '.$root->quote($account['username'])."@'%' IDENTIFIED BY ".$root->quote($account['password']))!==false,'CREATE_DEDICATED_ACCOUNT');$permissions=$account['username']==='audit_migrator'?'ALL PRIVILEGES':'SELECT, INSERT, UPDATE, DELETE';m4i_source();m2f_assert($root->exec('GRANT '.$permissions.' ON audit_auth.* TO '.$root->quote($account['username'])."@'%'")!==false,'DEDICATED_SCHEMA_GRANT');}
 $root=null;unset($rootPassword);$db=audit_pdo($migrator);$original=m2f_profileModes($db);$originalInnodb=m2f_innodbModes($db);$compat=['NO_ENGINE_SUBSTITUTION'];$evidence['initializer_sql_modes']=['profile_expected_flags'=>m2f_modeSet('STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'),'actual_before'=>m2f_modeReceipt($original),'compat_mode_flags'=>$compat,'compat_scope'=>'157 original CREATE blocks only; original image installation mode','session_restored_exact'=>false,'global_unchanged'=>false,'source_installation_SET_claimed'=>false,'actual_innodb_strict_before'=>$originalInnodb,'innodb_strict_not_SET'=>true];$evidence['initializer_create_warnings']=[];
 $evidence['initializer_create_progress']=['phase'=>'compat_session_set','current_table'=>null,'block_sha256'=>null,'ordinal'=>null,'successful_create_count'=>0,'expected_create_count'=>157];
 try {m2f_assert($db->exec('SET SESSION sql_mode='.$db->quote(implode(',',$compat)))!==false,'CREATE_COMPAT_SESSION_SET');$evidence['initializer_create_progress']['phase']='compat_session_verify';$window=m2f_modes($db);m2f_assert(m2f_modeSet($window['session_mode'])===$compat&&$window['global_mode']===$original['global_mode']&&m2f_innodbModes($db)===$originalInnodb,'CREATE_COMPAT_ACTUAL_SESSION_ONLY');$evidence['initializer_sql_modes']['actual_create_window']=m2f_modeReceipt($window);foreach($blocks as $index=>$block){$evidence['initializer_create_progress']['current_table']=$block[1];$evidence['initializer_create_progress']['block_sha256']=hash('sha256',$block[0]);$evidence['initializer_create_progress']['ordinal']=$index+1;$evidence['initializer_create_progress']['phase']='create_source_guard';m4i_source();$evidence['initializer_create_progress']['phase']='create_mode_guard';$now=m2f_modes($db);m2f_assert(m2f_modeSet($now['session_mode'])===$compat&&$now['global_mode']===$original['global_mode'],'CREATE_WINDOW_MODES_PRESERVED');$evidence['initializer_create_progress']['phase']='create_ddl';m2f_assert($db->exec($block[0])!==false,'CREATE_INSTALLATION_TABLE');++$evidence['initializer_create_progress']['successful_create_count'];$evidence['initializer_create_progress']['phase']='create_warning_observe';$codes=m2f_createWarnings($db);$evidence['initializer_create_warnings'][]=['table'=>$block[1],'ordinal'=>$index+1,'source_block_sha256'=>hash('sha256',$block[0]),'native_warning_count'=>count($codes),'native_warning_codes'=>$codes,'message_SQL_and_values_omitted'=>true];if($block[1]==='eb_qrcode'){$evidence['initializer_create_progress']['phase']='create_native_qrcode_verify';$evidence['initializer_qrcode_native']=m2f_qrcode($db,$block[0]);}$evidence['initializer_create_progress']['phase']='create_succeeded';}}
 catch(Throwable $error){$evidence['initializer_create_failure']=['phase'=>$evidence['initializer_create_progress']['phase'],'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),'code'=>$error instanceof RuntimeException&&!$error instanceof PDOException&&preg_match('/\A[A-Z0-9_]+\z/D',$error->getMessage())===1?$error->getMessage():'CREATE_RUNTIME_FAILURE']+m2f_pdoCodes($error);throw $error;}
 finally {m2f_assert($db->exec('SET SESSION sql_mode='.$db->quote($original['session_mode']))!==false,'CREATE_SESSION_RESTORE');$restored=m2f_modes($db);$evidence['initializer_sql_modes']['actual_after_restore']=m2f_modeReceipt($restored);$evidence['initializer_sql_modes']['session_restored_exact']=$restored['session_mode']===$original['session_mode'];$evidence['initializer_sql_modes']['global_unchanged']=$restored['global_mode']===$original['global_mode'];m2f_assert($evidence['initializer_sql_modes']['session_restored_exact']&&$evidence['initializer_sql_modes']['global_unchanged'],'CREATE_MODES_RESTORED_EXACT');$innodbAfter=m2f_innodbModes($db);$evidence['initializer_sql_modes']['actual_innodb_strict_after']=$innodbAfter;$evidence['initializer_sql_modes']['innodb_strict_unchanged']=$innodbAfter===$originalInnodb;m2f_assert($evidence['initializer_sql_modes']['innodb_strict_unchanged'],'CREATE_INNODB_STRICT_UNCHANGED');}
 $actual=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($actual,SORT_STRING);$expected=array_keys($names);sort($expected,SORT_STRING);m2f_assert($actual===$expected&&count($actual)===157,'CREATE_REAL_TABLE_SET');foreach($actual as $table)m2f_assert((int)$db->query('SELECT COUNT(*) FROM '.m2f_quote($table))->fetchColumn()===0,'CREATE_ALL_TABLES_EMPTY');
 m2f_assert($db->exec('CREATE TABLE audit_sentinel (run_id varchar(64) PRIMARY KEY)')!==false,'CREATE_ORIGINAL_SENTINEL');$s=$db->prepare('INSERT INTO audit_sentinel VALUES (?)');m2f_assert($s->execute([$manifest['run_id']])&&$s->rowCount()===1,'ORIGINAL_SENTINEL_ROW');$redis=audit_redis();m2f_assert($redis->config('SET','appendonly','yes')!==false&&$redis->config('SET','appendfsync','always')!==false&&$redis->set('audit:sentinel',$manifest['run_id'])===true,'ORIGINAL_REDIS_SENTINEL');
 $grants=$db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);$all=false;foreach($grants as $grant){if(preg_match('/^GRANT USAGE ON \*\.\* TO /',$grant)===1)continue;m2f_assert(preg_match('/^GRANT ALL PRIVILEGES ON `audit_auth`\.\* TO /',$grant)===1&&!str_contains($grant,'GRANT OPTION'),'MIGRATOR_SCHEMA_ONLY');$all=true;}m2f_assert($all,'MIGRATOR_SCHEMA_ALL');
 m2f_assert(m2f_file('/run/audit-bootstrap/root-password')===$rootMeta&&m2f_file('/run/audit-bootstrap/migrator.json')===$migratorMeta&&m2f_file('/run/audit-app/config.json')===$appMeta,'PREPARED_PRIVATE_INPUTS_UNCHANGED');$manifest['fixture_table_count']=157;$manifest['create_only_initializer']=['source_sha256'=>hash('sha256',$raw),'create_blocks'=>157,'create_inventory_sha256'=>hash('sha256',m2f_json($inventory)),'installation_inserts_executed'=>0,'only_declared_new_fixture_may_be_inserted'=>true];audit_write_json(audit_run_dir().'/manifest.json',$manifest);audit_guard_assert();
 $appModes=m2f_profileModes(audit_db());$evidence['application_sql_modes_after_initializer']=m2f_modeReceipt($appModes);return ['scope'=>'fresh CREATE-only initializer','schema_was_absent'=>true,'accounts_were_absent'=>true,'schema_initial_tables'=>0,'installation_tables'=>157,'all_installation_rows'=>0,'sentinel_tables'=>1,'sentinel_rows'=>1,'create_inventory'=>$inventory,'create_inventory_sha256'=>hash('sha256',m2f_json($inventory)),'installation_source_sha256'=>hash('sha256',$raw),'original_read_only_guard_verified'=>true,'migrator_schema_only'=>true,'application_exact_DML_only'=>true,'no_installation_INSERT_other_SET_DROP_TRUNCATE_or_restore'=>true,'only_approved_initializer_SESSION_window'=>true,'framework_or_HTTP_started'=>false];
}

function m4i_child(string $script, string $mode, string $config, ?string $state, array &$evidence): array
{
    m4i_source(); audit_guard_assert();
    $argv = [PHP_BINARY, '/workspace/help/migrations/' . $script, $mode, '--config-file=' . $config];
    if ($state !== null) { $argv[] = '--repo-root=/workspace'; $argv[] = '--state-file=' . $state; }
    $process = proc_open($argv, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m2f_assert(is_resource($process), 'REAL_PREREQUISITE_CHILD'); $first = proc_get_status($process);
    try { $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); }
    finally { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); $close = proc_close($process); }
    $native = $close;
    if ($close === -1 && $first['running'] === false && $first['signaled'] === false && is_int($first['exitcode']) && $first['exitcode'] >= 0) $native = $first['exitcode'];
    $result = is_string($stdout) && strlen($stdout) <= 1048576 ? json_decode($stdout, true) : null;
    $evidence['children'][] = ['script' => $script, 'mode' => $mode, 'pid' => $first['pid'], 'native_exit' => $native,
        'stdout_sha256' => hash('sha256', (string) $stdout), 'stderr_bytes' => strlen((string) $stderr), 'result' => $result];
    m2f_assert($native === 0 && $stderr === '' && is_int($first['pid']) && $first['pid'] > 0 && is_array($result)
        && $result['mode'] === $mode && in_array($result['state'], ['applicable', 'completed'], true), 'ACTUAL_PREREQUISITE_NATIVE');
    return $result;
}
function m4i_initialize(array &$evidence, ?array &$primary, ?array &$cleanup): void
{
    $evidence['binding'] = m4i_source(); $evidence['initializer'] = m4i_seed($evidence);
    $directory = '/tmp/ms4-initialize-' . getenv('AUDIT_RUN_ID'); $directories = [$directory => m2f_dir($directory, true)]; $owned = [];
    $failure = null;
    try {
        $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 16, JSON_THROW_ON_ERROR);
        $config = ['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth', 'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_'];
        $ms1 = $directory . '/ms1-config.json'; m2f_new($ms1, m2f_json($config)); $owned[$ms1] = m2f_file($ms1);
        $ms2 = $directory . '/ms2-config.json'; m2f_new($ms2, m2f_json($config + ['marketplace' => ['enabled' => false]])); $owned[$ms2] = m2f_file($ms2);
        unset($migrator, $config); $state = $directory . '/ms2-state.json';
        $first = m4i_child('marketplace-ms1.php', 'check', $ms1, null, $evidence); m2f_assert($first['state'] === 'applicable' && $first['changed'] === false, 'MS1_FRESH_CHECK');
        $first = m4i_child('marketplace-ms1.php', 'apply', $ms1, null, $evidence); m2f_assert($first['state'] === 'completed' && $first['changed'] === true, 'MS1_ACTUAL_APPLY');
        $first = m4i_child('marketplace-ms1.php', 'check', $ms1, null, $evidence); m2f_assert($first['state'] === 'completed' && $first['changed'] === false, 'MS1_ACTUAL_COMPLETED_CHECK');
        $first = m4i_child('marketplace-ms2.php', 'check', $ms2, $state, $evidence); m2f_assert($first['state'] === 'applicable' && $first['changed'] === false, 'MS2_FRESH_CHECK');
        $first = m4i_child('marketplace-ms2.php', 'apply-structure', $ms2, $state, $evidence);
        if (is_file($state)) $owned[$state] = m2f_file($state);
        m2f_assert($first['state'] === 'completed' && $first['changed'] === true && is_int($first['self_mer_id']) && $first['self_mer_id'] > 0
            && $first['ownership_phase'] === 'structure' && $first['enabled'] === false, 'MS2_REAL_STRUCTURE_AND_SELF_PK');
        $evidence['actual_self_mer_id'] = $first['self_mer_id'];
        $first = m4i_child('marketplace-ms2.php', 'check', $ms2, $state, $evidence);
        m2f_assert($first['state'] === 'completed' && $first['changed'] === false && $first['self_mer_id'] === $evidence['actual_self_mer_id']
            && $first['ownership_phase'] === 'structure' && $first['enabled'] === false && $first['strict_sql_mode']['session'] === true
            && $first['strict_sql_mode']['global'] === true && m2f_file($state) === $owned[$state], 'MS2_ACTUAL_COMPLETED_READONLY_CHECK');
        $db = audit_db(); $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        m2f_assert(count($names) === 164 && !in_array('eb_marketplace_order_idempotency', $names, true) && !in_array('eb_marketplace_stock_movement', $names, true), 'PRE_MS4_EXACT_164_TABLES');
        foreach (['eb_user', 'eb_store_order', 'eb_store_order_cart_info', 'eb_store_order_refund', 'eb_store_product_reply'] as $table) m2f_assert((int) $db->query('SELECT COUNT(*) FROM ' . m2f_quote($table))->fetchColumn() === 0, 'NO_FAKE_USER_ORDER_OR_REFUND_FIXTURE');
        m2f_assert(count($evidence['children']) === 6 && count(array_unique(array_column($evidence['children'], 'pid'))) === 6, 'SIX_REAL_PREREQUISITE_CHILDREN');
        m4i_defaultShipping($evidence);
        m4i_source(); audit_guard_assert();
    } catch (Throwable $error) { $failure = $error; $primary = m2f_diagnostic($error, 'initialize'); }
    finally {
        try {
            if (isset($state) && is_file($state) && !isset($owned[$state])) $owned[$state] = m2f_file($state);
            m2f_cleanup($directory, $directories, $owned, $evidence);
        } catch (Throwable $error) {
            $cleanup = m2f_diagnostic($error, 'private_cleanup'); $evidence['cleanup_failure'] = $cleanup;
            if ($failure === null) $failure = $error;
        }
    }
    if ($failure !== null) throw $failure;
}
function m4i_defaultShipping(array &$evidence): void
{
    audit_guard_assert(); $db = audit_db();
    $source = str_replace("\r", '', file_get_contents('/source/crmeb/public/install/crmeb.sql'));
    $seed = "INSERT INTO `eb_shipping_templates` (`id`, `name`, `type`, `appoint`, `no_delivery`, `sort`, `add_time`) VALUES\n"
        . "(1, '默认模版', 1, 0, 0, 0, 1658394592);";
    m2f_assert(substr_count($source, $seed) === 1, 'REAL_CANONICAL_DEFAULT_SHIPPING_SEED');
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $tables = array_fill_keys($names, null); $spec = ['tables' => $tables, 'redis' => ['*']];
    $metadataQuery = 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT'
        . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME';
    $before = audit_snapshot($spec); $metadata = $db->query($metadataQuery)->fetchAll(PDO::FETCH_ASSOC);
    $ddl = $db->query('SHOW CREATE TABLE eb_shipping_templates')->fetch(PDO::FETCH_NUM);
    m2f_assert($before['tables']['eb_shipping_templates']['count'] === 0
        && $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_shipping_templates'")->fetchColumn() === 2
        && is_array($ddl) && is_string($ddl[1] ?? null), 'FRESH_ORIGINAL_DEFAULT_SHIPPING_TABLE');
    $row = ['id' => 1, 'name' => '默认模版', 'type' => 1, 'appoint' => 0, 'no_delivery' => 0, 'sort' => 0, 'add_time' => 1658394592];
    $expected = $before;
    $expected['tables']['eb_shipping_templates'] = ['count' => 1,
        'sha256' => hash('sha256', hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))];
    m2f_assert($db->beginTransaction(), 'DEFAULT_SHIPPING_SEED_TRANSACTION');
    try {
        m2f_assert($db->exec($seed) === 1
            && $db->query('SELECT id,name,type,appoint,no_delivery,sort,add_time FROM eb_shipping_templates')->fetchAll(PDO::FETCH_ASSOC) === [$row],
            'COMPLETE_REAL_INSTALL_DEFAULT_SHIPPING_ROW');
        m2f_assert($db->commit(), 'DEFAULT_SHIPPING_SEED_COMMIT');
        m2f_assert(audit_snapshot($spec) === $expected && $db->query($metadataQuery)->fetchAll(PDO::FETCH_ASSOC) === $metadata
            && $db->query('SHOW CREATE TABLE eb_shipping_templates')->fetch(PDO::FETCH_NUM) === $ddl,
            'DEFAULT_SHIPPING_ONLY_ORIGINAL_SEED_DELTA');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    $evidence['canonical_default_shipping_seed'] = ['rows' => 1, 'original_id' => 1,
        'counter_unchanged' => true, 'other_database_and_redis_unchanged' => true];
}

function m4i_legacyRegistration(): void
{
    $manifest = audit_manifest(); $context = m4i_read('/run/audit/actual-run-context.json');
    $freeze = m4i_read('/run/audit/preparation-source-binding.json'); $role = 'audit-legacy-files-bootstrap';
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === [$role, 'audit-mysql', 'audit-redis'] && !isset($manifest['nonempty_history'])
        && $manifest['run_id'] === $context['run_id'] && $manifest['commit_sha'] === $context['source_sha'],
        'REAL_FIRST_HISTORY_REGISTRATION');
    $base = '/run/audit/evidence/' . $role;
    $facts = m4i_read($base . '-container-created.json'); $binding = m4i_read($base . '-source-binding.json');
    $original = m4i_read('/run/audit/evidence/legacy-files-bootstrap-created.json');
    $keys = array_keys($binding); sort($keys, SORT_STRING);
    m2f_assert($keys === ['authority_sha256', 'container_id', 'legacy_source_sha', 'mounts', 'role', 'run_id', 'schema', 'source_sha']
        && $facts === $original && $facts['id'] === $manifest['services'][$role]['id']
        && $facts['run_id'] === $context['run_id'] && $facts['source_sha'] === $context['source_sha']
        && $facts['status'] === 'created' && $facts['pid'] === 0 && $facts['restart_count'] === 0
        && $binding['schema'] === 1 && $binding['run_id'] === $context['run_id']
        && $binding['source_sha'] === $context['source_sha'] && $binding['role'] === $role
        && $binding['container_id'] === $facts['id']
        && $binding['legacy_source_sha'] === $freeze['nonempty_history']['legacy_source_sha']
        && $binding['authority_sha256'] === hash('sha256', json_encode($freeze['nonempty_history'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"),
        'ACTUAL_HISTORY_CREATED_SOURCE_BINDING');
    $mounts = [];
    foreach ($facts['mounts'] as $mount) {
        if ($mount['Type'] === 'tmpfs') continue;
        $mounts[] = ['Type' => $mount['Type'], 'Source' => audit_canonical_host_bind_source($mount['Source']),
            'Destination' => $mount['Destination'], 'RW' => $mount['RW'], 'Name' => $mount['Name'] ?? ''];
    }
    usort($mounts, static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    m2f_assert($binding['mounts'] === $mounts, 'HISTORY_NATIVE_MOUNTS_NOT_DECLARED_ONLY');
    $pins = [];
    foreach (['created' => $base . '-container-created.json', 'binding' => $base . '-source-binding.json'] as $key => $path) {
        $raw = file_get_contents($path);
        m2f_assert(is_string($raw) && is_file($path) && !is_link($path), 'HISTORY_REGISTERED_REGULAR_PROOF');
        $pins[$key] = ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    }
    $manifest['nonempty_history'] = ['schema' => 1, 'stages' => [$role => $pins]];
    audit_write_json('/run/audit/manifest.json', $manifest);
    m2f_assert(audit_manifest() === $manifest, 'HISTORY_STAGE_REGISTRATION_READBACK');
}

function m4i_legacyProducerRegistration(): void
{
    $context = m4i_read('/run/audit/actual-run-context.json');
    $freeze = m4i_read('/run/audit/preparation-source-binding.json'); $authority = $freeze['nonempty_history'];
    $archive = '/run/audit/evidence/nonempty-history-phases/legacy-files-complete';
    $leaves = ['manifest.json', 'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
        'actual-run-context.json', 'preparation-source-binding.json',
        'evidence/legacy-files-bootstrap-created.json', 'evidence/legacy-files-bootstrap-terminal.json',
        'evidence/audit-legacy-files-bootstrap-container-created.json',
        'evidence/audit-legacy-files-bootstrap-source-binding.json', 'evidence/audit-legacy-files-bootstrap-result.json',
        'evidence/register-legacy-files-bootstrap-created.json', 'evidence/register-legacy-files-bootstrap-terminal.json',
        'evidence/register-legacy-files-bootstrap-result.json'];
    $saved = []; $raws = [];
    $read = static function (string $path) use (&$saved): string {
        clearstatcache(true, $path); $before = lstat($path);
        m2f_assert(is_array($before) && is_file($path) && !is_link($path), 'PRODUCER_REGISTER_REGULAR_ORIGINAL');
        $raw = file_get_contents($path); clearstatcache(true, $path); $after = lstat($path);
        m2f_assert(is_string($raw) && is_array($after), 'PRODUCER_REGISTER_FULL_PUBLIC_READ');
        $stat = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            m2f_assert($before[$key] === $after[$key], 'PRODUCER_REGISTER_ORIGINAL_STAT9'); $stat[$key] = $before[$key];
        }
        $saved[$path] = ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw), 'stat' => $stat];
        return $raw;
    };
    m4i_historyActorLibrary(); $actorProofs = [];
    $actorReceipt = m4lh_history_actors_public_receipt($context, $actorProofs);
    foreach (array_keys($actorProofs) as $path) $read($path);
    foreach ([dirname(dirname($archive)), dirname($archive), $archive, $archive . '/evidence'] as $directory)
        m2f_assert(is_dir($directory) && !is_link($directory), 'PRODUCER_REGISTER_ORIGINAL_ARCHIVE_DIRECTORIES');
    $rootNames = array_values(array_diff(scandir($archive), ['.', '..'])); sort($rootNames, SORT_STRING);
    $wantedRoot = array_merge(array_slice($leaves, 0, 7), ['INDEX.json', 'evidence']); sort($wantedRoot, SORT_STRING);
    $evidenceNames = array_values(array_diff(scandir($archive . '/evidence'), ['.', '..'])); sort($evidenceNames, SORT_STRING);
    $wantedEvidence = array_map('basename', array_slice($leaves, 7)); sort($wantedEvidence, SORT_STRING);
    m2f_assert($rootNames === $wantedRoot && $evidenceNames === $wantedEvidence, 'PRODUCER_REGISTER_EXACT_ORIGINAL_15_NAMESPACE');
    $index = json_decode($read($archive . '/INDEX.json'), true, 128, JSON_THROW_ON_ERROR);
    $keys = array_keys($index); sort($keys, SORT_STRING);
    m2f_assert($keys === ['business_acceptance', 'files', 'legacy_source_sha', 'phase', 'run_id', 'schema', 'source_sha']
        && $index['schema'] === 1 && $index['phase'] === 'legacy-files-complete'
        && $index['run_id'] === $context['run_id'] && $index['source_sha'] === $context['source_sha']
        && $index['legacy_source_sha'] === $authority['legacy_source_sha'] && $index['business_acceptance'] === 'NOT RUN'
        && array_is_list($index['files']) && array_column($index['files'], 'path') === $leaves,
        'PRODUCER_REGISTER_ORIGINAL_COMPLETE_INDEX');
    foreach ($index['files'] as $row) {
        $keys = array_keys($row); sort($keys, SORT_STRING);
        $statKeys = array_keys($row['original_stat'] ?? []); sort($statKeys, SORT_STRING);
        m2f_assert($keys === ['bytes', 'original_stat', 'path', 'sha256'] && is_int($row['bytes']) && $row['bytes'] > 0
            && is_string($row['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1
            && $statKeys === ['st_ctime_ns', 'st_dev', 'st_gid', 'st_ino', 'st_mode', 'st_mtime_ns', 'st_nlink', 'st_size', 'st_uid']
            && count(array_filter($row['original_stat'], 'is_int')) === 9 && $row['original_stat']['st_size'] === $row['bytes'],
            'PRODUCER_REGISTER_COMPLETE_ORIGINAL_PIN');
        $raw = $read($archive . '/' . $row['path']);
        m2f_assert(strlen($raw) === $row['bytes'] && hash('sha256', $raw) === $row['sha256'], 'PRODUCER_REGISTER_ARCHIVE_FULL_BYTES');
        $raws[$row['path']] = $raw;
        if (str_starts_with($row['path'], 'evidence/'))
            m2f_assert($read('/run/audit/' . $row['path']) === $raw, 'PRODUCER_REGISTER_IMMUTABLE_OLD_EVIDENCE');
    }
    m2f_assert($raws['actual-run-context.json'] === $read('/run/audit/actual-run-context.json')
        && json_decode($raws['actual-run-context.json'], true, 128, JSON_THROW_ON_ERROR) === $context
        && $raws['preparation-source-binding.json'] === $read('/run/audit/preparation-source-binding.json')
        && json_decode($raws['preparation-source-binding.json'], true, 512, JSON_THROW_ON_ERROR) === $freeze
        && strlen($raws['preparation-source-binding.json']) === $context['freeze_bytes']
        && hash('sha256', $raws['preparation-source-binding.json']) === $context['freeze_sha256']
        && $raws['manifest.json'] === $read('/run/audit/manifest.json'), 'PRODUCER_REGISTER_ORIGINAL_MANIFEST_CONTEXT_FREEZE');
    // manifest.json is the one deliberate current-view update; its immutable archived copy remains in $saved.
    unset($saved['/run/audit/manifest.json']);
    $old = json_decode($raws['manifest.json'], true, 128, JSON_THROW_ON_ERROR); $oldRole = 'audit-legacy-files-bootstrap';
    $names = array_keys($old['services']); sort($names, SORT_STRING);
    m2f_assert($names === [$oldRole, 'audit-mysql', 'audit-redis'] && $old['run_id'] === $context['run_id']
        && $old['commit_sha'] === $context['source_sha'] && $old['network_internal'] === true
        && $old['network'] === ['id' => $context['network_id'], 'name' => $context['project'] . '_isolation']
        && json_decode($raws['network.json'], true, 64, JSON_THROW_ON_ERROR) === $context['initial_network'],
        'PRODUCER_REGISTER_ORIGINAL_STAGE_RESOURCE_IDENTITY');
    $volumeRows = array_map(static fn(string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        explode("\n", rtrim($raws['volumes.jsonl'], "\n")));
    $volumeNames = array_column($context['initial_volumes'], 'name'); sort($volumeNames, SORT_STRING);
    m2f_assert($volumeRows === $context['initial_volumes'] && explode("\n", rtrim($raws['volumes.txt'], "\n")) === $volumeNames,
        'PRODUCER_REGISTER_ORIGINAL_ALL_VOLUME_IDENTITIES');
    foreach (['audit-mysql', 'audit-redis'] as $baseRole)
        m2f_assert($old['services'][$baseRole]['id'] === $context['initial_service_ids'][$baseRole], 'PRODUCER_REGISTER_ORIGINAL_DB_CIDS');
    $oldHistory = $old['nonempty_history']; $keys = array_keys($oldHistory); sort($keys, SORT_STRING);
    m2f_assert($keys === ['schema', 'stages'] && $oldHistory['schema'] === 1
        && array_keys($oldHistory['stages']) === [$oldRole]
        && array_keys($oldHistory['stages'][$oldRole]) === ['created', 'binding'], 'PRODUCER_REGISTER_ONLY_ORIGINAL_HISTORY_STAGE');
    foreach (['created' => '-container-created.json', 'binding' => '-source-binding.json'] as $key => $suffix) {
        $leaf = 'evidence/' . $oldRole . $suffix; $raw = $raws[$leaf];
        m2f_assert($oldHistory['stages'][$oldRole][$key] === ['path' => '/run/audit/' . $leaf,
            'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)], 'PRODUCER_REGISTER_OLD_TWO_ACTUAL_PINS');
    }
    $created = json_decode($raws['evidence/legacy-files-bootstrap-created.json'], true, 64, JSON_THROW_ON_ERROR);
    $terminal = json_decode($raws['evidence/legacy-files-bootstrap-terminal.json'], true, 64, JSON_THROW_ON_ERROR);
    $oldBinding = json_decode($raws['evidence/' . $oldRole . '-source-binding.json'], true, 64, JSON_THROW_ON_ERROR);
    $result = json_decode($raws['evidence/' . $oldRole . '-result.json'], true, 128, JSON_THROW_ON_ERROR);
    $keys = array_keys($oldBinding); sort($keys, SORT_STRING);
    m2f_assert($keys === ['authority_sha256', 'container_id', 'legacy_source_sha', 'mounts', 'role', 'run_id', 'schema', 'source_sha']
        && $oldBinding['schema'] === 1
        && $created === json_decode($raws['evidence/' . $oldRole . '-container-created.json'], true, 64, JSON_THROW_ON_ERROR)
        && $created['id'] === $old['services'][$oldRole]['id'] && $created['status'] === 'created' && $created['pid'] === 0
        && $created['run_id'] === $context['run_id'] && $created['source_sha'] === $context['source_sha']
        && $created['proof_name'] === 'legacy-files-bootstrap' && $terminal['id'] === $created['id']
        && $terminal['status'] === 'exited' && $terminal['pid'] === 0 && $terminal['exit_code'] === 0
        && $terminal['restart_count'] === $created['restart_count'] && $created['restart_count'] === 0
        && $oldBinding['container_id'] === $created['id'] && $oldBinding['role'] === $oldRole
        && $oldBinding['run_id'] === $context['run_id'] && $oldBinding['source_sha'] === $context['source_sha']
        && $oldBinding['legacy_source_sha'] === $authority['legacy_source_sha']
        && $oldBinding['authority_sha256'] === hash('sha256', json_encode($authority,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"), 'PRODUCER_REGISTER_OLD_CREATED_TERMINAL_BINDING');
    foreach (['image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command', 'entrypoint', 'workdir',
        'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        m2f_assert($created[$key] === $terminal[$key], 'PRODUCER_REGISTER_OLD_COMPLETE_CREATED_IDENTITY');
    $sortMounts = static function (array $mounts): array {
        $rows = array_map(static function (array $row): string {
            ksort($row, SORT_STRING);
            return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $mounts);
        sort($rows, SORT_STRING); return $rows;
    };
    m2f_assert($sortMounts($created['mounts']) === $sortMounts($terminal['mounts'])
        && $result['status'] === 'LEGACY_FILE_INITIALIZATION_ONLY' && $result['native_exit'] === 0
        && $result['run_id'] === $context['run_id'] && $result['source_sha'] === $context['source_sha']
        && $result['legacy_source_sha'] === $authority['legacy_source_sha'] && $result['freeze_sha256'] === $context['freeze_sha256']
        && $result['container_id'] === $created['id'] && is_int($result['checksum_rows']) && $result['checksum_rows'] > 0
        && $result['target_native_id_full_validation'] === true && $result['complete_target_rows_and_projections_verified'] === true
        && $result['old_version_marker_matches'] === true && $result['non_target_database_and_redis_unchanged'] === true
        && $result['business_acceptance'] === 'NOT RUN' && $result['migration'] === 'NOT RUN'
        && $result['producer'] === 'NOT IMPLEMENTED', 'PRODUCER_REGISTER_FULL_OLD_FILE_RESULT');
    $window = $result['marker_add_time_native_window'];
    m2f_assert(array_keys($window) === ['before', 'after'] && is_int($window['before']) && is_int($window['after'])
        && 0 < $window['before'] && $window['before'] <= $window['after'], 'PRODUCER_REGISTER_REAL_OLD_MARKER_WINDOW');
    $helper = json_decode($raws['evidence/register-legacy-files-bootstrap-created.json'], true, 64, JSON_THROW_ON_ERROR);
    $helperTerminal = json_decode($raws['evidence/register-legacy-files-bootstrap-terminal.json'], true, 64, JSON_THROW_ON_ERROR);
    $helperResult = json_decode($raws['evidence/register-legacy-files-bootstrap-result.json'], true, 128, JSON_THROW_ON_ERROR);
    m2f_assert($helper['run_id'] === $context['run_id'] && $helper['source_sha'] === $context['source_sha']
        && $helper['proof_name'] === 'register-legacy-files-bootstrap' && $helper['status'] === 'created' && $helper['pid'] === 0
        && $helperTerminal['id'] === $helper['id'] && $helperTerminal['status'] === 'exited' && $helperTerminal['pid'] === 0
        && $helperTerminal['exit_code'] === 0 && $helperTerminal['restart_count'] === 0
        && $helperResult['native_exit'] === 0 && $helperResult['status'] === 'REGISTERED_NATIVE_RESOURCES_ONLY'
        && $helperResult['run_id'] === $context['run_id'] && $helperResult['source_sha'] === $context['source_sha']
        && $helperResult['binding']['container_id'] === $helper['id'] && $helperResult['binding']['source_sha'] === $context['source_sha']
        && $helperResult['binding']['freeze_sha256'] === $context['freeze_sha256']
        && $helperResult['binding']['created_sha256'] === hash('sha256', $raws['evidence/register-legacy-files-bootstrap-created.json']),
        'PRODUCER_REGISTER_ORIGINAL_REGISTER_HELPER_ZERO');
    foreach (['image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command', 'entrypoint', 'workdir',
        'port_bindings', 'cap_drop', 'security_options', 'created_at', 'restart_count'] as $key)
        m2f_assert($helper[$key] === $helperTerminal[$key], 'PRODUCER_REGISTER_OLD_HELPER_CREATED_IDENTITY');
    m2f_assert($sortMounts($helper['mounts']) === $sortMounts($helperTerminal['mounts']), 'PRODUCER_REGISTER_OLD_HELPER_MOUNTS');
    $removals = [];
    foreach (glob('/run/audit/evidence/native-*.json') as $path) {
        $receipt = m4i_read($path);
        if (($receipt['stage'] ?? '') !== 'legacy-files-bootstrap-exact-nonforce-remove') continue;
        m2f_assert($receipt['native_exit'] === 0 && $receipt['stdout_bytes'] === 65
            && $receipt['stdout_sha256'] === hash('sha256', $created['id'] . "\n")
            && $receipt['stderr_bytes'] === 0 && $receipt['stderr_sha256'] === hash('sha256', ''),
            'PRODUCER_REGISTER_ORIGINAL_EXACT_NORMAL_REMOVE');
        $read($path); $removals[] = $path;
    }
    m2f_assert(count($removals) === 1, 'PRODUCER_REGISTER_ONE_ORIGINAL_NORMAL_REMOVE');
    // Only now replace the current services view from the original Host collector.
    audit_register_resources(); $manifest = audit_manifest(); $role = 'audit-legacy-history-producer';
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === [$role, 'audit-mysql', 'audit-redis'] && $manifest['nonempty_history'] === $oldHistory
        && $manifest['run_id'] === $old['run_id'] && $manifest['commit_sha'] === $old['commit_sha'], 'PRODUCER_REGISTER_CURRENT_THREE_ROLES');
    $expectedManifest = $old; $expectedManifest['services'] = $manifest['services'];
    m2f_assert($manifest === $expectedManifest, 'PRODUCER_REGISTER_ONLY_CURRENT_SERVICES_VIEW_CHANGED');
    foreach (['audit-mysql', 'audit-redis'] as $baseRole)
        m2f_assert($manifest['services'][$baseRole]['id'] === $old['services'][$baseRole]['id'], 'PRODUCER_REGISTER_SAME_DB_CIDS');
    $base = '/run/audit/evidence/' . $role; $facts = m4i_read($base . '-container-created.json');
    $binding = m4i_read($base . '-source-binding.json'); $original = m4i_read('/run/audit/evidence/legacy-history-producer-created.json');
    $keys = array_keys($binding); sort($keys, SORT_STRING);
    m2f_assert($keys === ['authority_sha256', 'container_id', 'legacy_source_sha', 'mounts', 'role', 'run_id', 'schema', 'source_sha']
        && $facts === $original && $facts['id'] === $manifest['services'][$role]['id']
        && $facts['id'] !== $created['id'] && $facts['run_id'] === $context['run_id'] && $facts['source_sha'] === $context['source_sha']
        && $facts['proof_name'] === 'legacy-history-producer' && $facts['status'] === 'created' && $facts['pid'] === 0
        && $facts['restart_count'] === 0 && $facts['image'] === M2F_IMAGE && $facts['read_only'] === true && $facts['privileged'] === false
        && $facts['entrypoint'] === ['php'] && $facts['workdir'] === '/legacy-workspace/crmeb'
        && $facts['command'] === ['/run/nonempty/legacy-history-producer.php', 'normal',
            '--freeze-bytes=' . $context['freeze_bytes'], '--freeze-sha256=' . $context['freeze_sha256']]
        && $facts['labels']['tianli.audit'] === '1' && $facts['labels']['tianli.audit.run'] === $context['run_id']
        && $facts['labels']['com.docker.compose.project'] === $context['project'] && $facts['labels']['com.docker.compose.service'] === $role
        && $facts['cap_drop'] === ['ALL'] && in_array($facts['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && in_array($facts['port_bindings'], [null, []], true) && array_keys($facts['networks']) === [$context['project'] . '_isolation']
        && $facts['network_mode'] === $context['project'] . '_isolation'
        && $binding['schema'] === 1 && $binding['run_id'] === $context['run_id'] && $binding['source_sha'] === $context['source_sha']
        && $binding['role'] === $role && $binding['container_id'] === $facts['id']
        && $binding['legacy_source_sha'] === $authority['legacy_source_sha']
        && $binding['authority_sha256'] === $oldBinding['authority_sha256'], 'PRODUCER_REGISTER_REAL_CREATED_SOURCE_BINDING');
    foreach ($facts['ports'] ?? [] as $ports) m2f_assert($ports === null, 'PRODUCER_REGISTER_NO_PUBLISHED_PORTS');
    $wanted = [];
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'],
        '/legacy-source' => $authority['requested_legacy_repo_source'], '/legacy-workspace' => $authority['requested_legacy_repo_source'],
        '/run/nonempty' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $source)
        $wanted[] = ['Type' => 'bind', 'Source' => audit_canonical_host_bind_source($source),
            'Destination' => $destination, 'RW' => $destination === '/run/audit', 'Name' => ''];
    $private = m2f_mount($facts, '/run/audit-app');
    m2f_assert($private['Type'] === 'volume' && $private['RW'] === false
        && $private['Name'] === $context['project'] . '_app-secrets', 'PRODUCER_REGISTER_ONLY_APP_PRIVATE_RO');
    $wanted[] = ['Type' => 'volume', 'Source' => $private['Source'], 'Destination' => '/run/audit-app', 'RW' => false, 'Name' => $private['Name']];
    $fixture = m2f_mount($facts, '/run/audit-fixtures');
    m2f_assert($fixture['Type'] === 'volume' && $fixture['RW'] === false
        && $fixture['Name'] === $context['project'] . '_fixture-secrets'
        && $fixture['Source'] === $actorReceipt['fixture_mount']['Source'], 'PRODUCER_REGISTER_SAME_ORIGINAL_FIXTURE_PRIVATE_RO');
    $wanted[] = ['Type' => 'volume', 'Source' => $fixture['Source'], 'Destination' => '/run/audit-fixtures', 'RW' => false, 'Name' => $fixture['Name']];
    $mounts = []; $seen = [];
    foreach ($facts['mounts'] as $mount) {
        m2f_assert(!isset($seen[$mount['Destination']]), 'PRODUCER_REGISTER_ONE_MOUNT_PER_DESTINATION'); $seen[$mount['Destination']] = true;
        if ($mount['Type'] === 'tmpfs') {
            m2f_assert(in_array($mount['Destination'], ['/tmp', '/legacy-runtime'], true) && $mount['RW'] === true, 'PRODUCER_REGISTER_FINITE_TMPFS');
            continue;
        }
        $mounts[] = ['Type' => $mount['Type'], 'Source' => audit_canonical_host_bind_source($mount['Source']),
            'Destination' => $mount['Destination'], 'RW' => $mount['RW'], 'Name' => $mount['Name'] ?? ''];
    }
    $sort = static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    usort($wanted, $sort); usort($mounts, $sort); $tmpfs = array_keys($facts['tmpfs']); sort($tmpfs, SORT_STRING);
    $modes = array_values(array_filter(explode(',', $facts['tmpfs']['/legacy-runtime'] ?? ''), static fn(string $option): bool => str_starts_with($option, 'mode=')));
    m2f_assert($mounts === $wanted && $binding['mounts'] === $wanted && $tmpfs === ['/legacy-runtime', '/tmp']
        && count($modes) === 1 && preg_match('/\Amode=0?700\z/D', $modes[0]) === 1, 'PRODUCER_REGISTER_DERIVED_FULL_MOUNTS_PRIVATE_0700');
    $pins = [];
    foreach (['created' => $base . '-container-created.json', 'binding' => $base . '-source-binding.json'] as $key => $path) {
        $raw = $read($path);
        m2f_assert(json_decode($raw, true, 128, JSON_THROW_ON_ERROR) === ($key === 'created' ? $facts : $binding),
            'PRODUCER_REGISTER_VALIDATED_SOURCE_RAW_IS_PINNED');
        $pins[$key] = ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    }
    m2f_assert(json_decode($read('/run/audit/evidence/legacy-history-producer-created.json'), true, 128, JSON_THROW_ON_ERROR) === $original,
        'PRODUCER_REGISTER_VALIDATED_CREATED_ORIGINAL_PRESERVED');
    $manifest['nonempty_history']['stages'][$role] = $pins;
    audit_write_json('/run/audit/manifest.json', $manifest);
    m2f_assert(audit_manifest() === $manifest && $manifest['nonempty_history']['stages'][$oldRole] === $oldHistory['stages'][$oldRole],
        'PRODUCER_REGISTER_READBACK_OLD_STAGE_NOT_OVERWRITTEN');
    foreach ($saved as $path => $expected) {
        $read($path); m2f_assert($saved[$path] === $expected, 'PRODUCER_REGISTER_ALL_ORIGINAL_BYTES_STAT9_PRESERVED');
    }
    m2f_assert(array_values(array_diff(scandir($archive), ['.', '..'])) === $rootNames
        && array_values(array_diff(scandir($archive . '/evidence'), ['.', '..'])) === $evidenceNames,
        'PRODUCER_REGISTER_ORIGINAL_ARCHIVE_NAMESPACE_PRESERVED');
}

function m4i_historyActorLibrary(): void
{
    $freeze = m4i_read('/run/audit/preparation-source-binding.json');
    $files = array_values(array_filter($freeze['artifact_files'], static fn(array $row): bool => $row['path'] === 'legacy-actors.php'));
    m2f_assert(count($files) === 1, 'HISTORY_ACTOR_ONE_FROZEN_EXISTING_LIBRARY');
    m4i_sourceFile('/run/ms4-prep', $files[0]);
    require_once '/run/ms4-prep/legacy-actors.php';
}

/** Register only the already created current reader after both original normal retires. */
function m4i_currentReaderRegistration(): void
{
    require_once '/run/ms4-prep/legacy-app.php';
    require_once '/run/ms4-prep/legacy-actors.php';
    require_once '/workspace/help/audit-regressions/isolated/ms4-migration.php';
    require_once '/run/ms4-prep/legacy-current-reader.php';
    $context = m4i_read('/run/audit/actual-run-context.json'); $freeze = m4i_read('/run/audit/preparation-source-binding.json');
    m2f_assert(count($freeze['artifact_files']) === 14 && count($freeze['nonempty_history']['control_files']) === 12,
        'CURRENT_READER_REAL_FOURTEEN_TWELVE_CLOSED_SOURCE');
    $oldRaw = file_get_contents('/run/audit/manifest.json'); $old = json_decode($oldRaw, true, 128, JSON_THROW_ON_ERROR);
    $oldHistory = $old['nonempty_history']; $oldStages = array_keys($oldHistory['stages']); sort($oldStages, SORT_STRING);
    m2f_assert($oldStages === ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'], 'CURRENT_READER_ONLY_TWO_COMPLETED_OLD_STAGES');
    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $actorProofs = [];
    $actorReceipt = m4lh_history_actors_public_receipt($context, $actorProofs, true);
    foreach (['/workspace', '/source'] as $root)
        nhe_tree($root, $freeze['current_reader']['source_files'], $freeze['current_reader']['source_directories']);
    $archiveRoot = '/run/audit/evidence/nonempty-history-phases/before-current-reader';
    $index = m4i_read($archiveRoot . '/INDEX.json');
    m2f_assert($index['schema'] === 1 && $index['phase'] === 'before-current-reader'
        && $index['run_id'] === $context['run_id'] && $index['source_sha'] === $context['source_sha']
        && $index['legacy_source_sha'] === $freeze['nonempty_history']['legacy_source_sha'], 'CURRENT_READER_REAL_BEFORE_REGISTER_ARCHIVE');
    $pins = array_column($index['files'], null, 'path'); $archiveRaw = [];
    foreach (['manifest.json', 'evidence/legacy-history-producer-retired.json', 'evidence/driver-retired.json',
        'evidence/legacy-history-producer-result.json', 'evidence/ms4-migration-normal.json'] as $relative) {
        m2f_assert(isset($pins[$relative]), 'CURRENT_READER_ARCHIVE_REQUIRED_ORIGINAL_PIN');
        $pin = ['path' => $archiveRoot . '/' . $relative, 'bytes' => $pins[$relative]['bytes'], 'sha256' => $pins[$relative]['sha256']];
        $archiveRaw[$relative] = nhe_file($pin['path'], $pin);
        m2f_assert($archiveRaw[$relative] === file_get_contents('/run/audit/' . $relative), 'CURRENT_READER_ARCHIVED_ORIGINAL_FULL_BYTES');
    }
    m2f_assert($archiveRaw['manifest.json'] === $oldRaw, 'CURRENT_READER_OLD_MANIFEST_SAVED_BEFORE_REGISTER');
    audit_register_resources(); $manifest = audit_manifest(); $role = 'audit-legacy-current-reader';
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === [$role, 'audit-mysql', 'audit-redis'] && $manifest['nonempty_history'] === $oldHistory,
        'CURRENT_READER_REGISTER_ONLY_CURRENT_THREE_ROLES_OLD_STAGES_HELD');
    $expected = $old; $expected['services'] = $manifest['services'];
    m2f_assert($manifest === $expected, 'CURRENT_READER_REGISTER_ONLY_SERVICES_VIEW_CHANGED');
    foreach (['audit-mysql', 'audit-redis'] as $base)
        m2f_assert($manifest['services'][$base]['id'] === $old['services'][$base]['id'], 'CURRENT_READER_ORIGINAL_BASE_CIDS');
    $base = '/run/audit/evidence/' . $role;
    $madeRaw = file_get_contents($base . '-container-created.json'); $bindingRaw = file_get_contents($base . '-source-binding.json');
    $made = json_decode($madeRaw, true, 128, JSON_THROW_ON_ERROR); $binding = json_decode($bindingRaw, true, 128, JSON_THROW_ON_ERROR);
    m2f_assert($made === m4i_read('/run/audit/evidence/legacy-current-reader-created.json'), 'CURRENT_READER_REAL_ORIGINAL_CREATED_ALIAS');
    nhecr_created_facts($context, $freeze, $made, $binding, $manifest, $actorReceipt);
    m2f_assert($binding['prerequisites'] === [
        'producer_retired' => $proofs['/run/audit/evidence/legacy-history-producer-retired.json']['pin'],
        'driver_retired' => $proofs['/run/audit/evidence/driver-retired.json']['pin']], 'CURRENT_READER_ORIGINAL_NORMAL_RETIRES_BOUND');
    $stagePins = ['created' => ['path' => $base . '-container-created.json', 'bytes' => strlen($madeRaw), 'sha256' => hash('sha256', $madeRaw)],
        'binding' => ['path' => $base . '-source-binding.json', 'bytes' => strlen($bindingRaw), 'sha256' => hash('sha256', $bindingRaw)]];
    foreach ($stagePins as $pin) nhe_file($pin['path'], $pin);
    $manifest['nonempty_history']['stages'][$role] = $stagePins;
    audit_write_json('/run/audit/manifest.json', $manifest);
    m2f_assert(audit_manifest() === $manifest
        && array_diff_key($manifest['nonempty_history']['stages'], [$role => true]) === $oldHistory['stages'],
        'CURRENT_READER_NEW_STAGE_ONLY_OLD_STAGES_COMPLETE_READBACK');
    foreach ($proofs as $path => $proof) m2f_assert(nhecr_public_read($path) === $proof, 'CURRENT_READER_ALL_HANDOFF_ORIGINAL_BYTES_STAT9_HELD');
    foreach ($archiveRaw as $relative => $raw) m2f_assert(file_get_contents($archiveRoot . '/' . $relative) === $raw,
        'CURRENT_READER_ORIGINAL_BEFORE_REGISTER_ARCHIVE_HELD');
}

function m4i_legacyEnv(): void
{
    $config = audit_config(); $run = getenv('AUDIT_RUN_ID');
    m2f_assert(($config['run_id'] ?? null) === $run && ($config['database']['username'] ?? null) === 'audit_app'
        && preg_match('/\A[a-f0-9]{48}\z/D', (string) ($config['database']['password'] ?? '')) === 1
        && preg_match('/\A[a-f0-9]{48}\z/D', (string) ($config['redis_password'] ?? '')) === 1
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($config['signing_key'] ?? '')) === 1
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($config['auth']['stamp_key'] ?? '')) === 1
        && ($config['auth']['session_epoch'] ?? null) === 'audit-http-' . $run
        && ($config['auth']['issuer'] ?? null) === 'tianli-mall'
        && !hash_equals($config['signing_key'], $config['auth']['stamp_key']), 'ACTUAL_PRIVATE_LEGACY_ENV_INPUTS');
    $values = [
        'APP_DEBUG' => 'false', 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => 'audit_app', 'DATABASE_PASSWORD' => $config['database']['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => 'false',
        'CACHE_DRIVER' => 'redis', 'CACHE_CACHE_PREFIX' => 'c:', 'CACHE_CACHE_TAG_PREFIX' => 'CRMEB:',
        'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379', 'REDIS_SELECT' => '0',
        'REDIS_REDIS_PASSWORD' => $config['redis_password'],
        'AUTH_SESSION_EPOCH' => $config['auth']['session_epoch'], 'AUTH_STAMP_KEY' => $config['auth']['stamp_key'],
        'AUTH_ISSUER' => $config['auth']['issuer'],
    ];
    $raw = '';
    foreach ($values as $key => $value) $raw .= $key . '=' . json_encode($value, JSON_THROW_ON_ERROR) . "\n";
    m2f_assert(parse_ini_string($raw, false, INI_SCANNER_RAW) === $values, 'LEGACY_ENV_INI_ROUNDTRIP');
    $path = '/run/audit-app/legacy.env'; m2f_new($path, $raw);
    $stat = lstat($path);
    m2f_assert(is_array($stat) && is_file($path) && !is_link($path) && ($stat['mode'] & 0777) === 0600
        && $stat['uid'] === posix_geteuid() && parse_ini_file($path, false, INI_SCANNER_RAW) === $values,
        'LEGACY_ENV_PRIVATE_NATIVE_READBACK');
    unset($config, $values, $value, $raw);
}

function m4i_main(): int
{
    $arguments = array_slice($_SERVER['argv'], 1);
    $phase = $arguments === [] ? 'initialize' : ($arguments[0] ?? 'invalid');
    $evidence = ['schema' => 1, 'scope' => 'MS4_PREREQUISITES_ONLY', 'status' => 'FAIL', 'run_id' => getenv('AUDIT_RUN_ID'),
        'source_sha' => getenv('AUDIT_SOURCE_SHA'), 'children' => [], 'private_cleanup' => [], 'private_cleanup_verified' => false,
        'App_HTTP_backfill_enforce_payment' => 'NOT RUN', 'MS4_migration' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    $primary = null; $cleanup = null; $reserve = str_repeat("\0", 512 * 1024);
    register_shutdown_function(static function () use (&$reserve, &$phase, &$primary, &$cleanup): void {
        $reserve = null;
        $last = error_get_last(); $type = is_array($last) ? ($last['type'] ?? 0) : 0;
        $fatal = in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        try { m2f_new('/run/audit/evidence/' . getenv('MS4_PROOF_NAME') . '-shutdown.json', m2f_json(['schema' => 1, 'phase' => $phase,
            'fatal' => $fatal, 'type' => $type, 'file' => basename($last['file'] ?? ''), 'line' => $last['line'] ?? 0,
            'primary_failure' => $primary, 'cleanup_failure' => $cleanup, 'memory_limit' => ini_get('memory_limit'), 'peak_bytes' => memory_get_peak_usage(true)])); }
        catch (Throwable $ignored) {}
    });
    try {
        ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1'); umask(0077);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new RuntimeException('PHP_RUNTIME_WARNING'); });
        m2f_assert($arguments === [] || (count($arguments) === 1 && in_array($phase, ['prepare', 'register', 'authorize-cleanup'], true)), 'FINITE_PHASE_ONLY');
        $evidence['binding'] = m4i_gate($phase);
        if ($phase === 'prepare') {
            audit_prepare(false); m4i_legacyEnv(); $evidence['legacy_private_environment_created'] = true;
            m4i_historyActorLibrary(); $receipt = nhep_history_actors_create($evidence['binding']);
            $path = '/run/audit/evidence/legacy-history-actors.json'; $raw = m2f_json($receipt); m2f_new($path, $raw);
            $evidence['legacy_actors_receipt'] = ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
            unset($receipt, $raw); $evidence['status'] = 'PREPARED_PRIVATE_CONFIG_ONLY';
        }
        elseif ($phase === 'register') {
            if (getenv('MS4_PROOF_NAME') === 'register-legacy-current-reader') m4i_currentReaderRegistration();
            elseif (getenv('MS4_PROOF_NAME') === 'register-legacy-history-producer') m4i_legacyProducerRegistration();
            else {
                audit_register_resources();
                if (getenv('MS4_PROOF_NAME') === 'register-legacy-files-bootstrap') m4i_legacyRegistration();
            }
            $evidence['status'] = 'REGISTERED_NATIVE_RESOURCES_ONLY';
        }
        elseif ($phase === 'authorize-cleanup') { audit_authorize_cleanup(); $evidence['status'] = 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY'; }
        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS4_PREREQUISITES_ONLY'; }
    } catch (Throwable $error) {
        if ($primary === null) $primary = m2f_diagnostic($error, $phase); $evidence['diagnostic'] = $primary;
    }
    $evidence['native_exit'] = $primary === null ? 0 : 1;
    try { m2f_new('/run/audit/evidence/' . getenv('MS4_PROOF_NAME') . '-result.json', m2f_json($evidence)); }
    catch (Throwable $error) { $cleanup = m2f_diagnostic($error, 'public_receipt'); echo m2f_json(['status' => 'FAIL', 'phase' => 'public_receipt', 'native_exit' => 1]); return 1; }
    echo m2f_json(['status' => $evidence['status'], 'native_exit' => $evidence['native_exit'], 'children' => count($evidence['children']),
        'private_cleanup_verified' => $evidence['private_cleanup_verified'], 'App_HTTP_business_acceptance' => 'NOT RUN']);
    return $evidence['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m4i_main());
