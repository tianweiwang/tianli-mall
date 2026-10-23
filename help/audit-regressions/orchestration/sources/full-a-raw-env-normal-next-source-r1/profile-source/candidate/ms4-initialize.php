<?php
declare(strict_types=1);
const M4I_CU_FORM_SOURCE_STATUS = 'BOUND_FROZEN_PHP_SOURCE';

// Existing functions only; the original MS2 entry is not invoked by this filename.
require '/workspace/help/audit-regressions/isolated/ms2-migration.php';

function m4i_read(string $path): array
{
    m2f_assert(is_file($path) && !is_link($path), 'PUBLIC_BOUND_FILE');
    if ($path === '/run/audit/preparation-source-binding.json') {
        return audit_read_immutable_source_freeze(null, static function(bool $ok, string $code): void {
            $label = match ($code) {
                'REGULAR_BOUND_JSON' => 'PUBLIC_BOUND_FILE',
                'BOUND_JSON_OBJECT' => 'PUBLIC_BOUND_OBJECT',
                default => 'PUBLIC_' . $code,
            };
            m2f_assert($ok, $label);
        });
    }
    $value = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    m2f_assert(is_array($value) && !array_is_list($value), 'PUBLIC_BOUND_OBJECT'); return $value;
}
function m4i_cu_form_source_files(): array
{
    return [
        ['path' => 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php', 'bytes' => 66236, 'sha256' => '1d06fe3ed2cbe4bdc020e03849f128c3b447b7ea5365c54e59edee96762b5043'],
        ['path' => 'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php', 'bytes' => 65119, 'sha256' => '925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d'],
        ['path' => 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php', 'bytes' => 239863, 'sha256' => '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'],
        ['path' => 'help/audit-regressions/isolated/ms4-customer-http-normal.php', 'bytes' => 115616, 'sha256' => '84186f10092685ae1428f1c70533023109c2160bc672a311d89e5e97c26c8494'],
        ['path' => 'help/audit-regressions/isolated/ms4-fulfillment-normal.php', 'bytes' => 128280, 'sha256' => 'b877330ac652d1d0bc63fd72d84fb34174cac83144637f58c090c0f7c13034ce'],
        ['path' => 'help/audit-regressions/isolated/ms4-worker-normal.php', 'bytes' => 127354, 'sha256' => '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8'],
        ['path' => 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php', 'bytes' => 108005, 'sha256' => 'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7'],
    ];
}

function m4i_gate(string $phase): array
{
    m2f_assert(M4I_CU_FORM_SOURCE_STATUS === 'BOUND_FROZEN_PHP_SOURCE', 'CU_FORM_PHP_SOURCE_BINDING_REQUIRED');
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA'); $proof = getenv('MS4_PROOF_NAME');
    $roles = ['prepare' => ['prepare', 'audit-bootstrap'], 'register-initializer' => ['register', 'audit-bootstrap'],
        'register-driver' => ['register', 'audit-bootstrap'], 'authorize-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'files-bootstrap' => ['files-bootstrap', 'audit-bootstrap'],
        'register-customer-prepare' => ['register', 'audit-bootstrap'], 'register-customer' => ['register', 'audit-bootstrap'],
        'authorize-customer-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'register-paid-producer' => ['register', 'audit-bootstrap'], 'register-paid-after-c3' => ['register', 'audit-bootstrap'],
        'authorize-paid-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'register-fulfillment-migration' => ['register', 'audit-bootstrap'], 'register-fulfillment-normal' => ['register', 'audit-bootstrap'],
        'register-aftersale-review-normal' => ['register', 'audit-bootstrap'],
        'authorize-aftersale-review-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'authorize-fulfillment-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
        'register-worker-normal' => ['register', 'audit-bootstrap'],
        'authorize-worker-cleanup' => ['authorize-cleanup', 'audit-bootstrap'],
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
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $name => $destination) {
        $mount = m2f_mount($facts, $destination);
        m2f_assert($mount['Type'] === 'volume' && $mount['Name'] === $context['project'] . '_' . $name
            && $mount['RW'] === ($phase === 'prepare'), 'PRIVATE_HELPER_MOUNT');
    }
    m2f_assert(isset($facts['tmpfs']['/tmp']), 'PRIVATE_TMPFS_CONFIG');
    $tmp = false; foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
    }
    m2f_assert($tmp && !file_exists('/workspace/crmeb/.env') && !file_exists('/workspace/crmeb/public/install.lock'), 'REAL_TMPFS_NO_HOST_CONFIGURATION');
    if ($proof === 'initializer') {
        $wanted = ['/workspace', '/source', '/run/ms4-prep', '/run/audit', '/run/audit-bootstrap', '/run/audit-app', '/run/audit-customer-form'];
        $actual = []; foreach ($facts['mounts'] as $row) {
            if ($row['Type'] === 'tmpfs') { m2f_assert($row['Destination'] === '/tmp' && $row['RW'] === true, 'CU_FORM_INITIALIZER_ONLY_TMPFS'); continue; }
            $actual[] = $row['Destination'];
        }
        sort($actual, SORT_STRING); sort($wanted, SORT_STRING);
        $form = m2f_mount($facts, '/run/audit-customer-form');
        m2f_assert($actual === $wanted && $facts['user'] === '0:0' && $form['Type'] === 'volume'
            && $form['Name'] === $context['project'] . '_customer-form-evidence' && $form['RW'] === true,
            'CU_FORM_INITIALIZER_COMPLETE_FIXED_MOUNTS');
    }
    foreach (m4i_cu_form_source_files() as $row) {
        $matched = array_values(array_filter($binding['worker_source_files'], static fn(array $item): bool => $item['path'] === $row['path']));
        m2f_assert(count($matched) === 1 && $matched[0] === $row, 'CU_FORM_SEVEN_ACTUAL_FROZEN_PHP_SOURCE_DESCRIPTORS');
        foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $row);
    }
    foreach ($binding['driver_source_files'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    if (in_array($proof, ['files-bootstrap', 'register-customer-prepare', 'register-customer', 'authorize-customer-cleanup'], true)) foreach ($binding['customer_source_files'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    if (in_array($proof, ['register-paid-producer', 'register-paid-after-c3', 'authorize-paid-cleanup', 'register-fulfillment-migration', 'register-fulfillment-normal', 'authorize-fulfillment-cleanup', 'register-aftersale-review-normal', 'authorize-aftersale-review-cleanup', 'register-worker-normal', 'authorize-worker-cleanup'], true)) {
        $paidEntry = 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php';
        $paidExtra = $binding['customer_source_extra']; $paidExtra[] = $paidEntry; sort($paidExtra, SORT_STRING);
        m2f_assert($binding['paid_source_roots'] === $binding['customer_source_roots']
            && count($binding['customer_source_extra']) === 10 && count($paidExtra) === 11
            && $binding['paid_source_extra'] === $paidExtra
            && array_values(array_filter($binding['paid_source_files'], static fn(array $row): bool => $row['path'] !== $paidEntry))
                === $binding['customer_source_files'], 'PAID_ORIGINAL_CUSTOMER_NINE_AND_EXPLICIT_TENTH_SOURCE');
        foreach ($binding['paid_source_files'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    }
    if (in_array($proof, ['register-fulfillment-migration', 'register-fulfillment-normal', 'authorize-fulfillment-cleanup', 'register-aftersale-review-normal', 'authorize-aftersale-review-cleanup', 'register-worker-normal', 'authorize-worker-cleanup'], true)) {
        $extra = array_values(array_unique(array_merge($binding['customer_source_extra'], [
            'crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
            'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms5-payment-migration.php',
            'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php', 'help/migrations/marketplace-ms4.md',
            'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms5-payment.md', 'help/migrations/marketplace-ms5-payment.php',
            'help/audit-regressions/isolated/ms5-paid-consumer-normal.php',
            'help/audit-regressions/isolated/ms4-fulfillment-normal.php',
            'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php',
            'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php',
            'help/audit-regressions/isolated/lib/ms4-three-read-normal.php',
            'help/audit-regressions/isolated/ms4-fulfillment-migration.php', 'help/migrations/marketplace-ms4-fulfillment.php',
            'help/migrations/marketplace-ms4-assets.php'
        ]), SORT_STRING)); sort($extra, SORT_STRING);
        m2f_assert($binding['fulfillment_source_roots'] === $binding['customer_source_roots']
            && count($extra) === 26 && $binding['fulfillment_source_extra'] === $extra,
            'FULFILLMENT_ORIGINAL_CUSTOMER_AND_FINITE_TWENTY_FIVE_EXTRAS');
        $customerPaths = [];
        foreach (array_column($binding['customer_source_files'], 'path') as $path) {
            m2f_assert(is_string($path), 'FULFILLMENT_CUSTOMER_SOURCE_PATH_STRING');
            $customerPaths['path:' . $path] = true;
        }
        m2f_assert(array_values(array_filter($binding['fulfillment_source_files'],
            static fn(array $row): bool => is_string($row['path']) && isset($customerPaths['path:' . $row['path']]))) === $binding['customer_source_files'],
            'FULFILLMENT_ORIGINAL_CUSTOMER_COMPLETE_SOURCE_RETAINED');
        foreach ($binding['fulfillment_source_files'] as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    }
    if (in_array($proof, ['register-fulfillment-migration', 'register-aftersale-review-normal', 'authorize-aftersale-review-cleanup', 'register-worker-normal', 'authorize-worker-cleanup'], true)) {
        $extra = array_values(array_unique(array_merge($binding['fulfillment_source_extra'], [
            'help/migrations/marketplace-ms4-aftersale.php', 'help/migrations/marketplace-ms4-aftersale-menus.php',
            'help/migrations/marketplace-ms4-review-menus.php', 'help/audit-regressions/isolated/ms4-aftersale-review-normal.php'
        ]), SORT_STRING)); sort($extra,SORT_STRING);
        m2f_assert($binding['aftersale_review_source_roots'] === $binding['fulfillment_source_roots']
            && count($extra) === 30 && $binding['aftersale_review_source_extra'] === $extra,
            'AFTERSALE_REVIEW_ORIGINAL_TWENTY_FIVE_AND_FIXED_FOUR_EXTRAS');
        $oldPaths = [];
        foreach (array_column($binding['fulfillment_source_files'], 'path') as $path) {
            m2f_assert(is_string($path), 'AFTERSALE_REVIEW_SOURCE_PATH_STRING');
            $oldPaths['path:' . $path] = true;
        }
        m2f_assert(array_values(array_filter($binding['aftersale_review_source_files'],
            static fn(array $row): bool => is_string($row['path']) && isset($oldPaths['path:' . $row['path']]))) === $binding['fulfillment_source_files'],
            'AFTERSALE_REVIEW_ORIGINAL_COMPLETE_SOURCE_RETAINED');
        foreach ($binding['aftersale_review_source_files'] as $item) foreach (['/workspace','/source'] as $root) m4i_sourceFile($root,$item);
    }
    if (in_array($proof, ['register-worker-normal', 'authorize-worker-cleanup'], true)) m4i_worker_sources($binding);
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
function m4i_worker_sources(array $binding): void
{
    $roots = ['crmeb/app', 'crmeb/crmeb', 'crmeb/vendor', 'crmeb/config'];
    $customer = ['crmeb/composer.json', 'crmeb/composer.lock', 'crmeb/public/index.php', 'crmeb/public/router.php',
        'crmeb/think', 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php',
        'help/audit-regressions/isolated/ms4-customer-http-normal.php',
        'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php',
        'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'];
    sort($customer, SORT_STRING);
    m2f_assert($binding['customer_source_roots'] === $roots && $binding['customer_source_extra'] === $customer
        && count($customer) === 10, 'WORKER_FIXED_ORIGINAL_FOUR_ROOTS_NINE_CUSTOMER_EXTRAS');
    $entry = 'help/audit-regressions/isolated/ms4-worker-normal.php';
    $extra = array_merge($binding['aftersale_review_source_extra'], [$entry]); sort($extra, SORT_STRING);
    m2f_assert(count($extra) === 31 && count(array_unique($extra, SORT_STRING)) === 31
        && $binding['worker_source_roots'] === $roots && $binding['worker_source_extra'] === $extra,
        'WORKER_ORIGINAL_TWENTY_NINE_PLUS_ONE_EXACT_SOURCE_EXTRAS');
    $groups = ['customer' => 10, 'paid' => 11, 'fulfillment' => 26, 'aftersale_review' => 30, 'worker' => 31];
    $workerRows = [];
    m2f_assert(array_is_list($binding['worker_source_files']), 'WORKER_COMPLETE_SOURCE_LIST');
    foreach ($binding['worker_source_files'] as $row) {
        m2f_assert(is_array($row) && m4i_ms5_keys($row, ['path', 'bytes', 'sha256'])
            && is_string($row['path']) && !isset($workerRows[$row['path']])
            && is_int($row['bytes']) && $row['bytes'] >= 0 && is_string($row['sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1, 'WORKER_CANONICAL_UNIQUE_SOURCE_DESCRIPTOR');
        m2f_path($row['path']); $workerRows[$row['path']] = $row;
    }
    foreach (['/workspace', '/source'] as $namespace) {
        $rootPaths = [];
        foreach ($roots as $relative) {
            $directory = $namespace . '/' . $relative;
            m2f_assert(is_dir($directory) && !is_link($directory) && realpath($directory) === $directory,
                'WORKER_ACTUAL_CANONICAL_SOURCE_ROOT');
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                m2f_assert(!$file->isLink() && realpath($path) === $path
                    && str_starts_with($path, $directory . '/'), 'WORKER_COMPLETE_SOURCE_ROOT_NO_LINKS');
                if ($file->isFile()) $rootPaths[] = substr($path, strlen($namespace) + 1);
                else m2f_assert($file->isDir(), 'WORKER_ONLY_SOURCE_REGULAR_FILES_AND_DIRECTORIES');
            }
        }
        foreach ($groups as $group => $count) {
            $extras = $binding[$group . '_source_extra']; $rows = $binding[$group . '_source_files'];
            m2f_assert($binding[$group . '_source_roots'] === $roots && array_is_list($extras)
                && count($extras) === $count && count(array_unique($extras, SORT_STRING)) === $count
                && array_is_list($rows), 'WORKER_ALL_ORIGINAL_AND_NEW_SOURCE_GROUP_SHAPES');
            $wanted = array_values(array_unique(array_merge($rootPaths, $extras), SORT_STRING)); sort($wanted, SORT_STRING);
            m2f_assert(array_column($rows, 'path') === $wanted, 'WORKER_ALL_FIVE_COMPLETE_ACTUAL_SOURCE_CLOSURES');
            foreach ($rows as $row) m2f_assert(is_array($row) && isset($workerRows[$row['path']])
                && $row === $workerRows[$row['path']], 'WORKER_ALL_ORIGINAL_SOURCE_DESCRIPTORS_RETAINED');
        }
        foreach ($workerRows as $row) {
            $path = $namespace . '/' . $row['path'];
            m2f_assert(realpath($path) === $path, 'WORKER_CANONICAL_DUAL_RO_SOURCE_MEMBER');
            m4i_sourceFile($namespace, $row);
        }
    }
    foreach ([
        'help/audit-regressions/isolated/ms5-paid-consumer-normal.php' =>
            [108005, 'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7'],
        'help/audit-regressions/isolated/ms4-aftersale-review-normal.php' =>
            [239863, '48734a574986d3c6eab37cbc0f1deeab2d03b2b94b931bcaed4719c72e62f723'],
        $entry => [127354, '2f52b676dfa5b3d715adaae0318802f941388ffc103b7f8c02cf2e9d5f09f4f8']
    ] as $path => $pin) m2f_assert(isset($workerRows[$path]) && $workerRows[$path]['bytes'] === $pin[0]
        && $workerRows[$path]['sha256'] === $pin[1], 'WORKER_ADOPTED_PAID_DONOR_AND_R3_ENTRY_SOURCE_BYTES');
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
    m2f_assert(in_array($script, ['marketplace-ms1.php', 'marketplace-ms2.php', 'marketplace-ms4.php'], true), 'FINITE_PREREQUISITE_SCRIPT');
    $scriptFile = '/workspace/help/migrations/' . $script;
    $scriptHash = hash_file('sha256', $scriptFile);
    $argv = [PHP_BINARY, $scriptFile, $mode, '--config-file=' . $config];
    if ($state !== null) { $argv[] = '--repo-root=/workspace'; $argv[] = '--state-file=' . $state; }
    $process = proc_open($argv, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace', null, ['bypass_shell' => true]);
    m2f_assert(is_resource($process), 'REAL_PREREQUISITE_CHILD');
    $first = proc_get_status($process); $terminal = null; $close = null; $captureError = null;
    $buffers = [1 => '', 2 => '']; $counts = [1 => 0, 2 => 0];
    $hashes = [1 => hash_init('sha256'), 2 => hash_init('sha256')];
    $observe = static function (mixed $status): ?array {
        if (!is_array($status) || ($status['running'] ?? null) !== false || ($status['signaled'] ?? null) !== false
            || ($status['stopped'] ?? null) !== false || !is_int($status['exitcode'] ?? null) || $status['exitcode'] < 0) return null;
        return ['running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => $status['exitcode']];
    };
    $terminal = $observe($first);
    try {
        m2f_assert(is_array($first) && is_int($first['pid']) && $first['pid'] > 0, 'REAL_PREREQUISITE_PID');
        foreach ($pipes as $pipe) m2f_assert(stream_set_blocking($pipe, false), 'PREREQUISITE_NONBLOCKING_PIPE');
        while (true) {
            $read = []; foreach ($pipes as $pipe) if (is_resource($pipe) && !feof($pipe)) $read[] = $pipe;
            if ($read === []) break;
            $write = null; $except = null;
            m2f_assert(stream_select($read, $write, $except, 0, 200000) !== false, 'PREREQUISITE_PIPE_SELECT');
            foreach ($read as $pipe) {
                $which = $pipe === $pipes[1] ? 1 : 2; $chunk = fread($pipe, 8192);
                m2f_assert(is_string($chunk), 'PREREQUISITE_PIPE_READ');
                $counts[$which] += strlen($chunk); hash_update($hashes[$which], $chunk);
                if ($counts[$which] <= 1048576) $buffers[$which] .= $chunk;
            }
            if ($terminal === null) $terminal = $observe(proc_get_status($process));
        }
        if ($terminal === null) $terminal = $observe(proc_get_status($process));
    } catch (Throwable $error) { $captureError = get_class($error); }
    finally { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); $close = proc_close($process); }
    $native = $close;
    if ($close === -1 && $terminal !== null) $native = $terminal['exitcode'];
    $result = null;
    if ($counts[1] <= 1048576 && $counts[2] <= 1048576) {
        try { $result = json_decode($buffers[1], true, 128, JSON_THROW_ON_ERROR); } catch (Throwable $error) { $captureError ??= get_class($error); }
    }
    $evidence['children'][] = ['script' => $script, 'script_sha256' => $scriptHash, 'mode' => $mode, 'pid' => $first['pid'] ?? null,
        'native_exit' => $native, 'proc_close_exit' => $close, 'same_handle_terminal' => $terminal, 'capture_error_class' => $captureError,
        'stdout_bytes' => $counts[1], 'stdout_sha256' => hash_final($hashes[1]), 'stderr_bytes' => $counts[2],
        'stderr_sha256' => hash_final($hashes[2]), 'result' => is_array($result) ? $result : null];
    m2f_assert($captureError === null && $native === 0 && ($terminal === null || $terminal['exitcode'] === $native)
        && $counts[1] > 0 && $counts[1] <= 1048576 && $counts[2] === 0 && is_array($result) && !array_is_list($result)
        && $result['mode'] === $mode && in_array($result['state'], ['applicable', 'completed'], true)
        && ($result['issues'] ?? null) === [] && hash_file('sha256', $scriptFile) === $scriptHash, 'ACTUAL_PREREQUISITE_NATIVE');
    return $result;
}
function m4i_customer_form_private_root(): array
{
    $root = '/run/audit-customer-form';
    $context = m4i_read('/run/audit/actual-run-context.json');
    $facts = m4i_read('/run/audit/evidence/initializer-created.json');
    $mount = m2f_mount($facts, $root);
    m2f_assert($facts['labels']['com.docker.compose.service'] === 'audit-ms4-initialize'
        && $facts['user'] === '0:0' && $mount['Type'] === 'volume' && $mount['RW'] === true
        && $mount['Name'] === $context['project'] . '_customer-form-evidence'
        && function_exists('posix_geteuid') && function_exists('posix_getegid')
        && posix_geteuid() === 0 && posix_getegid() === 0, 'CU_FORM_REAL_INITIALIZER_VOLUME_AND_PROCESS_OWNER');
    $volumeRows = array_values(array_filter($context['initial_volumes'],
        static fn(array $row): bool => $row['name'] === $mount['Name']));
    m2f_assert(count($volumeRows) === 1 && $volumeRows[0]['labels']['tianli.audit.run'] === $context['run_id']
        && $volumeRows[0]['labels']['com.docker.compose.project'] === $context['project'], 'CU_FORM_ACTUAL_NEW_VOLUME_IDENTITY');
    $realMounts = [];
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]);
        if (($left[4] ?? '') === $root) $realMounts[] = $left;
    }
    m2f_assert(count($realMounts) === 1 && in_array('rw', explode(',', $realMounts[0][5] ?? ''), true),
        'CU_FORM_REAL_FIXED_ROOT_MOUNTPOINT_RW');
    clearstatcache(true, $root); $before = lstat($root);
    m2f_assert(is_array($before) && is_dir($root) && !is_link($root) && realpath($root) === $root
        && ($before['mode'] & 0170000) === 0040000 && $before['uid'] === posix_geteuid() && $before['gid'] === posix_getegid()
        && array_values(array_diff(scandir($root), ['.', '..'])) === [], 'CU_FORM_EMPTY_NEW_CANONICAL_ROOT_BEFORE_APP');
    m2f_assert(chmod($root, 0700), 'CU_FORM_ONLY_DEDICATED_VOLUME_ROOT_0700');
    clearstatcache(true, $root); $after = lstat($root);
    m2f_assert(is_array($after) && !is_link($root) && realpath($root) === $root
        && ($after['mode'] & 07777) === 0700 && $after['uid'] === posix_geteuid() && $after['gid'] === posix_getegid()
        && $before['dev'] === $after['dev'] && $before['ino'] === $after['ino']
        && array_values(array_diff(scandir($root), ['.', '..'])) === [], 'CU_FORM_SAME_EMPTY_REAL_ROOT_OWNER_MODE_0700');
    return ['schema' => 1, 'scope' => 'CU_FORM_EMPTY_PRIVATE_ROOT_INITIALIZATION_BEFORE_APP',
        'volume' => $mount['Name'], 'created_at' => $volumeRows[0]['created_at'], 'actual_euid' => posix_geteuid(),
        'actual_egid' => posix_getegid(), 'dev' => $after['dev'], 'ino' => $after['ino'], 'mode' => $after['mode'],
        'uid' => $after['uid'], 'gid' => $after['gid'], 'nlink' => $after['nlink'], 'empty' => true, 'App_started' => false];
}

function m4i_initialize(array &$evidence, ?array &$primary, ?array &$cleanup): void
{
    $evidence['binding'] = m4i_source();
    $evidence['customer_form_private_root'] = m4i_customer_form_private_root();
    $evidence['initializer'] = m4i_seed($evidence);
    $directory = '/tmp/ms4-initialize-' . getenv('AUDIT_RUN_ID'); $directories = [$directory => m2f_dir($directory, true)]; $owned = [];
    $failure = null;
    try {
        $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 16, JSON_THROW_ON_ERROR);
        $config = ['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth', 'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_'];
        $ms1 = $directory . '/ms1-config.json'; m2f_new($ms1, m2f_json($config)); $owned[$ms1] = m2f_file($ms1);
        $ms4 = $directory . '/ms4-config.json'; m2f_new($ms4, m2f_json($config)); $owned[$ms4] = m2f_file($ms4);
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
        $beforeMs4 = $names; sort($beforeMs4, SORT_STRING);
        $ms4First = m4i_child('marketplace-ms4.php', 'check', $ms4, null, $evidence);
        m2f_assert($ms4First['state'] === 'applicable' && $ms4First['changed'] === false && $ms4First['applied_steps'] === []
            && $ms4First['pending_steps_known'] === true && count($ms4First['pending_steps']) > 0, 'MS4_FRESH_REAL_CHECK');
        $ms4Applied = m4i_child('marketplace-ms4.php', 'apply-structure', $ms4, null, $evidence);
        m2f_assert($ms4Applied['state'] === 'completed' && $ms4Applied['changed'] === true && $ms4Applied['pending_steps'] === []
            && $ms4Applied['applied_steps'] === $ms4First['pending_steps'], 'MS4_ACTUAL_SOURCE_STEPS_APPLIED');
        $ms4Last = m4i_child('marketplace-ms4.php', 'check', $ms4, null, $evidence);
        m2f_assert($ms4Last['state'] === 'completed' && $ms4Last['changed'] === false && $ms4Last['applied_steps'] === []
            && $ms4Last['pending_steps'] === [], 'MS4_ACTUAL_COMPLETED_CHECK');
        $ms4Hash = hash_file('sha256', '/workspace/help/migrations/marketplace-ms4.php');
        foreach ([$ms4First, $ms4Applied, $ms4Last] as $receipt) m2f_assert($receipt['native_exit'] === 0
            && $receipt['source_sha256'] === $ms4Hash && $receipt['marker'] === 'marketplace-ms4-structure-v1'
            && $receipt['pending_steps_known'] === true && $receipt['issues'] === [] && $receipt['failure_phase'] === null
            && $receipt['trade_enabled'] === false && $receipt['acceptance'] === 'NOT RUN', 'MS4_REAL_SOURCE_COMPLETED_CONTRACT');
        $afterMs4 = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($afterMs4, SORT_STRING);
        $added = ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'];
        $expected = array_merge($beforeMs4, $added); sort($expected, SORT_STRING);
        m2f_assert($afterMs4 === $expected && count($afterMs4) === 166
            && !in_array('eb_marketplace_payment_registry', $afterMs4, true)
            && !in_array('eb_marketplace_payment_attempt', $afterMs4, true), 'POST_MS4_EXACT_STRUCTURE_BEFORE_MS5');
        foreach ($added as $table) m2f_assert((int)$db->query('SELECT COUNT(*) FROM ' . m2f_quote($table))->fetchColumn() === 0, 'MS4_NEW_TABLES_EMPTY');
        foreach (['eb_user', 'eb_store_order', 'eb_store_order_cart_info', 'eb_store_order_refund', 'eb_store_product_reply'] as $table)
            m2f_assert((int)$db->query('SELECT COUNT(*) FROM ' . m2f_quote($table))->fetchColumn() === 0, 'NO_BUSINESS_FIXTURE_AFTER_MS4');
        $evidence['prerequisite_structure'] = ['pre_ms4_count' => count($beforeMs4), 'post_ms4_count' => count($afterMs4),
            'pre_ms4_names_sha256' => hash('sha256', m2f_json($beforeMs4)), 'post_ms4_names_sha256' => hash('sha256', m2f_json($afterMs4)),
            'added_tables' => $added, 'ms4_completed' => true, 'ms5_tables_absent' => true, 'ms4_source_sha256' => $ms4Hash];
        $evidence['MS4_migration'] = 'VERIFIED_STRUCTURE_ONLY';
        m2f_assert(count($evidence['children']) === 9 && count(array_unique(array_column($evidence['children'], 'pid'))) === 9, 'NINE_REAL_PREREQUISITE_CHILDREN');
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
function m6i_migrator(): array
{
    audit_guard_assert(); $config = audit_config();
    $migrator = m4i_read('/run/audit-bootstrap/migrator.json');
    m2f_assert($config['database']['username'] === 'audit_app' && $migrator['username'] === 'audit_migrator',
        'HTTP_DEDICATED_MIGRATOR_ONLY');
    require_once '/workspace/crmeb/vendor/autoload.php';
    $app = new \think\App('/workspace/crmeb/');
    $runtime = '/tmp/ms4-http-migrator-' . getenv('AUDIT_RUN_ID') . '/';
    m2f_assert(!file_exists($runtime) && mkdir($runtime, 0700), 'HTTP_MIGRATOR_DISTINCT_RUNTIME');
    $app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => $migrator['username'], 'DATABASE_PASSWORD' => $migrator['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
        'REDIS_REDIS_PASSWORD' => $config['redis_password'], 'REDIS_SELECT' => 0,
        'CACHE_CACHE_PREFIX' => 'audit:' . getenv('AUDIT_RUN_ID') . ':ms4-app:',
        'AUTH_SESSION_EPOCH' => $config['auth']['session_epoch'], 'AUTH_STAMP_KEY' => $config['auth']['stamp_key'],
        'AUTH_ISSUER' => $config['auth']['issuer']]);
    $app->initialize();
    $connection = $app->db->connect();
    $identityRows = $connection->query('SELECT CURRENT_USER() AS actual_user', [], true);
    $nativePdo = $connection->getPdo();
    m2f_assert($nativePdo instanceof \PDO && is_array($identityRows) && count($identityRows) === 1
        && ($identityRows[0]['actual_user'] ?? null) === 'audit_migrator@%'
        && $nativePdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_migrator@%',
        'HTTP_REAL_MIGRATOR_CONNECTION');
    m2f_assert($app->make(\app\services\system\log\SystemFileServices::class)->writeMd5() === true,
        'HTTP_REAL_WRITE_MD5');
    $count = (int) audit_db()->query('SELECT COUNT(*) FROM eb_system_file_md5')->fetchColumn();
    m2f_assert($count > 0 && $app->make(\app\services\system\log\SystemFileMd5Services::class)->checkFile() === [],
        'HTTP_REAL_FILE_CHECK_MATCHES');
    $cache = $app->make(\app\services\other\CacheServices::class);
    $cache->setDbCache('write_md5', get_crmeb_version_vode());
    m2f_assert($cache->checkDbCache('write_md5', get_crmeb_version_vode()), 'HTTP_REAL_VERSION_MARKER');
    return ['account' => 'audit_migrator', 'checksum_rows' => $count, 'source_matches' => true,
        'version_marker_matches' => true, 'application_account' => 'audit_app', 'application_dml_only' => true];
}

/** New consumer contract only; original register/cleanup and migrator functions remain intact. */
function m4i_ms5_keys(array $value, array $expected): bool
{
    $keys = array_keys($value); sort($keys, SORT_STRING); sort($expected, SORT_STRING); return $keys === $expected;
}
function m4i_ms5_same(mixed $actual, mixed $expected): void
{
    if (!is_array($expected)) { m2f_assert($actual === $expected, 'CUSTOMER_MS5_NATIVE_VALUE_AND_TYPE'); return; }
    m2f_assert(is_array($actual) && array_is_list($actual) === array_is_list($expected)
        && m4i_ms5_keys($actual, array_keys($expected)), 'CUSTOMER_MS5_NATIVE_ARRAY_SHAPE');
    foreach ($expected as $key => $value) m4i_ms5_same($actual[$key], $value);
}
function m4i_ms5_sources(array $rows): array
{
    $expected = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
        'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms5-payment-migration.php',
        'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
        'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms5-payment.md', 'help/migrations/marketplace-ms5-payment.php'];
    sort($expected, SORT_STRING); $hashes = [];
    m2f_assert(array_is_list($rows) && count($rows) === 10, 'CUSTOMER_EXACT_TEN_MIGRATION_SOURCES');
    foreach ($rows as $row) {
        m2f_assert(is_array($row) && m4i_ms5_keys($row, ['path', 'bytes', 'sha256']) && is_string($row['path'])
            && in_array($row['path'], $expected, true) && !isset($hashes[$row['path']])
            && is_int($row['bytes']) && $row['bytes'] >= 0 && is_string($row['sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1, 'CUSTOMER_EXACT_MIGRATION_SOURCE_DESCRIPTOR');
        foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $row);
        $hashes[$row['path']] = $row['sha256'];
    }
    m2f_assert(array_keys($hashes) === $expected, 'CUSTOMER_SORTED_COMPLETE_DUAL_RO_MIGRATION_SOURCES');
    return $hashes;
}
function m4i_ms5_manifest(array $context, array $manifest, string $role): void
{
    $roles = array_keys($manifest['services']); sort($roles, SORT_STRING);
    $expected = [$role, 'audit-mysql', 'audit-redis']; sort($expected, SORT_STRING);
    m2f_assert($manifest['run_id'] === $context['run_id'] && $manifest['commit_sha'] === $context['source_sha']
        && $manifest['project'] === $context['project'] && $manifest['isolation'] === 'ISO-FULL'
        && $manifest['guard_mode'] === 'read-only' && $manifest['network_internal'] === true && $roles === $expected
        && $manifest['network'] === ['id' => $context['network_id'], 'name' => $context['project'] . '_isolation'],
        'CUSTOMER_EXACT_PHASE_MANIFEST');
    $names = []; $identities = [];
    foreach ($context['initial_volumes'] as $row) { $names[] = $row['name']; $identities[$row['name']] = $row['created_at']; }
    sort($names, SORT_STRING); $actual = $manifest['volumes']; sort($actual, SORT_STRING);
    m2f_assert(count($names) === 8 && $actual === $names, 'CUSTOMER_CURRENT_EIGHT_VOLUMES');
    m4i_ms5_same($manifest['volume_identities'], $identities);
    foreach (['audit-mysql', 'audit-redis'] as $host) m2f_assert(
        $manifest['services'][$host]['id'] === $context['initial_service_ids'][$host]
        && $manifest['services'][$host]['image_id'] === $context['initial_service_created'][$host]['image']
        && filter_var($manifest['services'][$host]['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        'CUSTOMER_ORIGINAL_BASE_SERVICE_IDENTITIES');
}
function m4i_ms5_terminal(array $context, array $created, array $terminal, string $proof, string $role, array $command): void
{
    $keys = array_values(array_diff(array_keys($created), ['run_id', 'source_sha', 'proof_name']));
    m2f_assert(m4i_ms5_keys($terminal, $keys), 'CUSTOMER_ORIGINAL_RAW_TERMINAL_KEYS');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command',
        'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at', 'user', 'stop_signal'] as $key)
        m2f_assert(array_key_exists($key, $created) && array_key_exists($key, $terminal)
            && $created[$key] === $terminal[$key], 'CUSTOMER_ALL_ORIGINAL_IMMUTABLE_NATIVE_FIELDS');
    $mounts = static function (array $rows): array {
        $out = []; foreach ($rows as $row) { ksort($row, SORT_STRING); $out[] = serialize($row); }
        sort($out, SORT_STRING); return $out;
    };
    m2f_assert(is_array($created['mounts']) && is_array($terminal['mounts'])
        && $mounts($created['mounts']) === $mounts($terminal['mounts'])
        && array_keys($created['networks']) === array_keys($terminal['networks']), 'CUSTOMER_FULL_MOUNT_MULTISET_AND_NETWORKS');
    m2f_assert($created['run_id'] === $context['run_id'] && $created['source_sha'] === $context['source_sha']
        && $created['proof_name'] === $proof && is_string($created['id'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $created['id']) === 1 && $created['hostname'] === substr($created['id'], 0, 12)
        && $created['image'] === M2F_IMAGE && $created['status'] === 'created' && $created['pid'] === 0
        && $created['restart_count'] === 0 && $created['read_only'] === true && $created['privileged'] === false
        && $created['entrypoint'] === ['php'] && $created['command'] === $command && $created['workdir'] === '/workspace'
        && in_array($created['user'], ['', '0', '0:0', 'root'], true)
        && $created['labels']['tianli.audit'] === '1' && $created['labels']['tianli.audit.run'] === $context['run_id']
        && $created['labels']['com.docker.compose.project'] === $context['project']
        && $created['labels']['com.docker.compose.service'] === $role
        && $created['network_mode'] === $context['project'] . '_isolation'
        && array_keys($created['networks']) === [$context['project'] . '_isolation']
        && in_array($created['port_bindings'], [null, []], true) && $created['cap_drop'] === ['ALL']
        && in_array($created['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $terminal['status'] === 'exited' && $terminal['pid'] === 0 && $terminal['exit_code'] === 0
        && $terminal['restart_count'] === 0, 'CUSTOMER_ACTUAL_PREDECESSOR_NATIVE_ZERO');
    foreach ($created['ports'] as $published) m2f_assert($published === null, 'CUSTOMER_NO_PREDECESSOR_PUBLISHED_PORT');
    m2f_assert(is_string($terminal['started_at']) && is_string($terminal['finished_at'])
        && strtotime($terminal['started_at']) !== false && strtotime($terminal['finished_at']) !== false
        && strtotime($terminal['started_at']) > 0 && strtotime($terminal['finished_at']) >= strtotime($terminal['started_at']),
        'CUSTOMER_REAL_PREDECESSOR_START_FINISH');
    $expectedDestinations = ['/workspace', '/source', '/run/audit', '/run/audit-bootstrap', '/run/audit-app'];
    if ($role === 'audit-ms4-initialize') { $expectedDestinations[] = '/run/ms4-prep'; $expectedDestinations[] = '/run/audit-customer-form'; }
    $destinations = [];
    foreach ($created['mounts'] as $mount) {
        $destination = $mount['Destination'];
        m2f_assert(is_string($destination) && !isset($destinations[$destination])
            && in_array($destination, [...$expectedDestinations, '/tmp'], true), 'CUSTOMER_CLOSED_PREDECESSOR_MOUNTS');
        $destinations[$destination] = true;
        if ($destination === '/tmp') m2f_assert($mount['Type'] === 'tmpfs', 'CUSTOMER_ONLY_PRIVATE_TMPFS_MOUNT');
    }
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'], '/run/audit' => $context['run_dir']]
        + ($role === 'audit-ms4-initialize' ? ['/run/ms4-prep' => $context['prep_dir']] : []) as $destination => $source) {
        $mount = m2f_mount($created, $destination);
        m2f_assert($mount['Type'] === 'bind' && $mount['RW'] === ($destination === '/run/audit')
            && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($source),
            'CUSTOMER_EXACT_PREDECESSOR_SOURCE_RUN_BINDS');
    }
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $suffix => $destination) {
        $mount = m2f_mount($created, $destination);
        m2f_assert($mount['Type'] === 'volume' && $mount['Name'] === $context['project'] . '_' . $suffix && $mount['RW'] === false,
            'CUSTOMER_READ_ONLY_PREDECESSOR_CONFIG_VOLUMES');
    }
    if ($role === 'audit-ms4-initialize') {
        $form = m2f_mount($created, '/run/audit-customer-form');
        m2f_assert($form['Type'] === 'volume' && $form['Name'] === $context['project'] . '_customer-form-evidence'
            && $form['RW'] === true && $created['user'] === '0:0', 'CU_FORM_PREDECESSOR_INITIALIZER_REAL_RW_ROOT');
    }
    m2f_assert(array_key_exists('/tmp', $created['tmpfs']), 'CUSTOMER_ACTUAL_PREDECESSOR_TMPFS');
}
function m4i_ms5_child(array $child): void
{
    m2f_assert(is_int($child['pid']) && $child['pid'] > 0 && $child['native_exit'] === 0
        && $child['capture_error_class'] === null && is_int($child['stdout_bytes']) && $child['stdout_bytes'] > 0
        && $child['stdout_bytes'] <= 1048576 && $child['stderr_bytes'] === 0
        && $child['stderr_sha256'] === hash('sha256', '') && is_string($child['stdout_sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $child['stdout_sha256']) === 1
        && in_array($child['proc_close_exit'], [0, -1], true)
        && ($child['proc_close_exit'] === 0 || $child['same_handle_terminal'] !== null), 'CUSTOMER_REAL_SAME_HANDLE_CHILD_ZERO');
    if ($child['same_handle_terminal'] !== null) m4i_ms5_same($child['same_handle_terminal'],
        ['running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 0]);
}
function m4i_ms5_files_prerequisites(array $filesIdentity): array
{
    $base = '/run/audit/evidence/'; $context = m4i_read('/run/audit/actual-run-context.json');
    $freeze = m4i_read('/run/audit/preparation-source-binding.json'); $manifest = audit_manifest();
    // Current files/helper slots are a later phase. MS5 formal remains independent original history below.
    $formalPath = $base . 'ms5-payment-migration-formal-manifest.json';
    m2f_assert(is_file($formalPath) && !is_link($formalPath), 'CUSTOMER_ORIGINAL_MIGRATION_FORMAL_REGULAR_FILES');
    // collect refreshes native observation inputs; files-bootstrap does not register a new formal role.
    $filesCreated = m4i_read($base . 'files-bootstrap-created.json');
    m2f_assert(m4i_ms5_keys($filesIdentity, ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'])
        && getenv('MS4_PROOF_NAME') === 'files-bootstrap' && $filesCreated['proof_name'] === 'files-bootstrap'
        && $filesCreated['run_id'] === $context['run_id'] && $filesCreated['source_sha'] === $context['source_sha']
        && $filesCreated['labels']['com.docker.compose.service'] === 'audit-bootstrap'
        && $filesCreated['command'] === ['/run/ms4-prep/ms4-initialize.php', 'files-bootstrap']
        && $filesCreated['status'] === 'created' && $filesCreated['pid'] === 0
        && $filesCreated['hostname'] === gethostname() && $filesCreated['hostname'] === substr($filesCreated['id'], 0, 12)
        && $filesIdentity['container_id'] === $filesCreated['id'] && $filesIdentity['entry_namespace_pid'] === getmypid()
        && $filesIdentity['source_sha'] === $context['source_sha'] && $filesIdentity['freeze_sha256'] === $context['freeze_sha256']
        && $filesIdentity['created_sha256'] === hash_file('sha256', $base . 'files-bootstrap-created.json'),
        'CUSTOMER_REAL_FILES_HELPER_CREATED_AND_GATE_IDENTITY');
    $hashes = m4i_ms5_sources($freeze['driver_source_files']);
    $names = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json', 'initializer-result.json',
        'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
        'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']; $before = [];
    foreach ($names as $name) { m4i_read($base . $name); $before[$name] = hash_file('sha256', $base . $name); }
    $created = m4i_read($base . 'initializer-created.json'); $terminal = m4i_read($base . 'initializer-completed-terminal.json');
    $initializer = m4i_read($base . 'initializer-result.json');
    m4i_ms5_terminal($context, $created, $terminal, 'initializer', 'audit-ms4-initialize', ['/run/ms4-prep/ms4-initialize.php']);
    $binding = $initializer['binding'];
    m2f_assert(m4i_ms5_keys($binding, ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'])
        && $initializer['schema'] === 1 && $initializer['scope'] === 'MS5_PAYMENT_PREREQUISITES_ONLY'
        && $initializer['status'] === 'VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY' && $initializer['native_exit'] === 0
        && $initializer['run_id'] === $context['run_id'] && $initializer['source_sha'] === $context['source_sha']
        && $initializer['private_cleanup_verified'] === true && $initializer['MS4_migration'] === 'VERIFIED_STRUCTURE_ONLY'
        && $initializer['App_HTTP_backfill_enforce_payment'] === 'NOT RUN' && $initializer['acceptance'] === 'NOT RUN'
        && $binding['container_id'] === $created['id'] && is_int($binding['entry_namespace_pid']) && $binding['entry_namespace_pid'] > 0
        && $binding['source_sha'] === $context['source_sha'] && $binding['freeze_sha256'] === $context['freeze_sha256']
        && $binding['created_sha256'] === $before['initializer-created.json'], 'CUSTOMER_REAL_NEW_NINE_INITIALIZER_SOURCE');
    $sequence = [['marketplace-ms1.php', 'check'], ['marketplace-ms1.php', 'apply'], ['marketplace-ms1.php', 'check'],
        ['marketplace-ms2.php', 'check'], ['marketplace-ms2.php', 'apply-structure'], ['marketplace-ms2.php', 'check'],
        ['marketplace-ms4.php', 'check'], ['marketplace-ms4.php', 'apply-structure'], ['marketplace-ms4.php', 'check']];
    m2f_assert(is_array($initializer['children']) && array_is_list($initializer['children']) && count($initializer['children']) === 9,
        'CUSTOMER_EXACT_NINE_NATIVE_CHILDREN'); $pids = [];
    foreach ($sequence as $index => [$script, $mode]) {
        $child = $initializer['children'][$index]; m4i_ms5_child($child); $result = $child['result'];
        m2f_assert($child['script'] === $script && $child['script_sha256'] === $hashes['help/migrations/' . $script]
            && $child['mode'] === $mode && !isset($pids[$child['pid']]) && is_array($result)
            && $result['mode'] === $mode && $result['state'] === ($index % 3 === 0 ? 'applicable' : 'completed')
            && $result['changed'] === ($index % 3 === 1) && $result['issues'] === [], 'CUSTOMER_NINE_SOURCE_BOUND_NATIVE_RESULTS');
        $pids[$child['pid']] = true;
        if ($index >= 6) m2f_assert($result['native_exit'] === 0 && $result['source_sha256'] === $child['script_sha256']
            && $result['marker'] === 'marketplace-ms4-structure-v1' && $result['pending_steps_known'] === true
            && $result['failure_phase'] === null && $result['trade_enabled'] === false && $result['acceptance'] === 'NOT RUN',
            'CUSTOMER_ACTUAL_MS4_STRUCTURE_PREDECESSOR');
    }
    $children = $initializer['children'];
    m2f_assert($children[6]['result']['applied_steps'] === [] && is_array($children[6]['result']['pending_steps'])
        && array_is_list($children[6]['result']['pending_steps']) && count($children[6]['result']['pending_steps']) > 0
        && $children[7]['result']['applied_steps'] === $children[6]['result']['pending_steps']
        && $children[7]['result']['pending_steps'] === []
        && $children[8]['result']['applied_steps'] === [] && $children[8]['result']['pending_steps'] === [],
        'CUSTOMER_REAL_MS4_SOURCE_STEP_CHAIN');
    $self = $initializer['actual_self_mer_id'];
    m2f_assert(is_int($self) && $self > 0 && $self <= 4294967295, 'CUSTOMER_REAL_GENERATED_SELF_MERCHANT_ID');
    foreach ([4, 5] as $index) m2f_assert($children[$index]['result']['self_mer_id'] === $self
        && $children[$index]['result']['ownership_phase'] === 'structure' && $children[$index]['result']['enabled'] === false,
        'CUSTOMER_ORIGINAL_MS2_SELF_AND_DISABLED_STAGE');
    $structure = $initializer['prerequisite_structure'];
    m2f_assert(m4i_ms5_keys($structure, ['pre_ms4_count', 'post_ms4_count', 'pre_ms4_names_sha256', 'post_ms4_names_sha256',
        'added_tables', 'ms4_completed', 'ms5_tables_absent', 'ms4_source_sha256'])
        && $structure['pre_ms4_count'] === 164 && $structure['post_ms4_count'] === 166
        && $structure['added_tables'] === ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement']
        && $structure['ms4_completed'] === true && $structure['ms5_tables_absent'] === true
        && $structure['ms4_source_sha256'] === $hashes['help/migrations/marketplace-ms4.php']
        && is_string($structure['pre_ms4_names_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $structure['pre_ms4_names_sha256']) === 1
        && is_string($structure['post_ms4_names_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $structure['post_ms4_names_sha256']) === 1,
        'CUSTOMER_EXACT_166_TABLE_PREPAYMENT_STRUCTURE');
    $driver = m4i_read($base . 'driver-created.json');
    m4i_ms5_same(m4i_read($base . 'ms5-payment-migration-container-created.json'), $driver);
    $driverTerminal = m4i_read($base . 'driver-terminal.json');
    $migration = m4i_read($base . 'ms5-payment-migration-normal.json');
    $formal = m4i_read($base . 'ms5-payment-migration-formal-manifest.json');
    m4i_ms5_manifest($context, $formal, 'audit-ms5-payment-migration');
    foreach (['network', 'volumes', 'volume_identities'] as $key) m4i_ms5_same($formal[$key], $manifest[$key]);
    foreach (['audit-mysql', 'audit-redis'] as $host) m4i_ms5_same($formal['services'][$host], $manifest['services'][$host]);
    m4i_ms5_terminal($context, $driver, $driverTerminal, 'driver', 'audit-ms5-payment-migration',
        ['help/audit-regressions/isolated/ms5-payment-migration.php']);
    m2f_assert($formal['services']['audit-ms5-payment-migration']['id'] === $driver['id']
        && count(array_unique([$created['id'], $driver['id'], $filesCreated['id']], SORT_STRING)) === 3,
        'CUSTOMER_DISTINCT_REAL_PREDECESSOR_AND_FILES_CIDS');
    $migrationBinding = m4i_read($base . 'ms5-payment-migration-source-binding.json');
    m2f_assert(m4i_ms5_keys($migrationBinding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source',
        'entry_sha256', 'cli_sha256', 'source_files']) && $migrationBinding['schema'] === 1
        && $migrationBinding['run_id'] === $context['run_id'] && $migrationBinding['source_sha'] === $context['source_sha']
        && $migrationBinding['requested_repo_source'] === $context['repo_dir'] && $migrationBinding['requested_run_source'] === $context['run_dir']
        && $migrationBinding['entry_sha256'] === $hashes['help/audit-regressions/isolated/ms5-payment-migration.php']
        && $migrationBinding['cli_sha256'] === $hashes['help/migrations/marketplace-ms5-payment.php'],
        'CUSTOMER_EXACT_EIGHT_KEY_MS5_BINDING');
    m4i_ms5_same($migrationBinding['source_files'], $freeze['driver_source_files']);
    m4i_ms5_sources($migrationBinding['source_files']);
    $actual = $migration['binding'];
    m2f_assert(m4i_ms5_keys($actual, ['run_id', 'container_id', 'entry_namespace_pid', 'source_sha', 'entry_sha256',
        'cli_sha256', 'source_files', 'binding_sha256', 'native_created_sha256'])
        && $migration['schema'] === 1 && $migration['scope'] === 'MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        && $migration['status'] === 'VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        && $migration['termination'] === 'NORMAL_RETURN' && $migration['entry_return_code'] === 0
        && $migration['native_exit'] === null && $migration['native_exit_observation'] === 'HOST_NATIVE_REQUIRED'
        && $migration['private_cleanup_verified'] === true && $migration['private_cleanup_state'] === 'OWN_FILE_REMOVED'
        && $migration['original_table_count'] === 166 && $migration['independent_new_metadata_verified'] === true
        && $migration['actual_command_counts'] === ['check' => 2, 'apply-structure' => 2]
        && $migration['history_mapping'] === 'NOT RUN' && $migration['payment_producer'] === 'NOT IMPLEMENTED'
        && $migration['business_acceptance'] === 'NOT RUN'
        && $actual['run_id'] === $context['run_id'] && $actual['source_sha'] === $context['source_sha']
        && $actual['container_id'] === $driver['id'] && is_int($actual['entry_namespace_pid']) && $actual['entry_namespace_pid'] > 0
        && $actual['entry_sha256'] === $migrationBinding['entry_sha256'] && $actual['cli_sha256'] === $migrationBinding['cli_sha256']
        && $actual['source_files'] === 10 && $actual['binding_sha256'] === $before['ms5-payment-migration-source-binding.json']
        && $actual['native_created_sha256'] === $before['ms5-payment-migration-container-created.json'],
        'CUSTOMER_REAL_MS5_NORMAL_RETURN_AND_HOST_NATIVE_ZERO');
    m2f_assert(is_array($migration['children']) && array_is_list($migration['children']) && count($migration['children']) === 4,
        'CUSTOMER_EXACT_FOUR_MS5_CLI_CHILDREN'); $pids = []; $steps = ['marketplace_payment_registry', 'marketplace_payment_attempt'];
    foreach (['check', 'apply-structure', 'check', 'apply-structure'] as $index => $mode) {
        $child = $migration['children'][$index]; m4i_ms5_child($child); $result = $child['result'];
        m2f_assert($child['mode'] === $mode && !isset($pids[$child['pid']]) && is_array($result)
            && m4i_ms5_keys($result, ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known',
                'issues', 'failure_phase', 'marker', 'source_sha256', 'history_mapping', 'payment_producer', 'acceptance'])
            && $result['mode'] === $mode && $result['native_exit'] === 0 && $result['state'] === ($index === 0 ? 'applicable' : 'completed')
            && $result['changed'] === ($index === 1) && $result['pending_steps_known'] === true && $result['issues'] === []
            && $result['failure_phase'] === null && $result['marker'] === 'marketplace-ms5-payment-structure-v1'
            && $result['source_sha256'] === $migrationBinding['cli_sha256'] && $result['history_mapping'] === 'NOT RUN'
            && $result['payment_producer'] === 'NOT IMPLEMENTED' && $result['acceptance'] === 'NOT RUN'
            && $result['pending_steps'] === ($index === 0 ? $steps : []) && $result['applied_steps'] === ($index === 1 ? $steps : []),
            'CUSTOMER_FOUR_EXACT_REAL_MS5_RESULTS'); $pids[$child['pid']] = true;
    }
    // This existing finite customer entry loads function definitions only; its __FILE__ main gate is false here.
    require_once '/workspace/help/audit-regressions/isolated/ms4-customer-http-normal.php';
    $earlySource=['context'=>$context,'freeze'=>$freeze,'run_id'=>$context['run_id'],'source_sha'=>$context['source_sha'],
        'freeze_sha256'=>$context['freeze_sha256'],'requested_repo_source'=>$context['repo_dir'],'requested_run_source'=>$context['run_dir']];
    $earlyHistory=m4c_entry_early_history($earlySource);
    m4c_entry_early_paid_unstarted($earlySource,'files-bootstrap');
    m4c_entry_early_preserved($earlyHistory,'files-bootstrap');
    $db = audit_db(); m2f_assert(!$db->inTransaction(), 'CUSTOMER_INDEPENDENT_PRE_FILES_READ');
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables,SORT_STRING);
    m2f_assert($tables === $earlyHistory['result']['snapshots']['assets']['names']
        && in_array('eb_marketplace_payment_registry', $tables, true)
        && in_array('eb_marketplace_payment_attempt', $tables, true), 'CUSTOMER_ACTUAL_COMPLETED_EARLY_BEFORE_FILES_TABLES');
    foreach (['eb_user', 'eb_store_order', 'eb_store_order_cart_info', 'eb_store_product', 'eb_store_product_attr',
        'eb_store_product_attr_value', 'eb_store_cart', 'eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement',
        'eb_marketplace_payment_registry', 'eb_marketplace_payment_attempt'] as $table)
        m2f_assert((int)$db->query('SELECT COUNT(*) FROM ' . m2f_quote($table))->fetchColumn() === 0,
            'CUSTOMER_NO_SAME_DATABASE_BUSINESS_BEFORE_FILES');
    $statement = $db->prepare('SELECT `mer_id` FROM `eb_marketplace_merchant` WHERE `mer_id` = ?');
    m2f_assert($statement instanceof PDOStatement && $statement->execute([$self]), 'CUSTOMER_REAL_SELF_READ');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    m2f_assert(count($rows) === 1 && (string)$rows[0]['mer_id'] === (string)$self, 'CUSTOMER_ACTUAL_INITIALIZED_SELF_ROW');
    foreach ($before as $name => $digest) m2f_assert(hash_file('sha256', $base . $name) === $digest,
        'CUSTOMER_COMMON_EIGHT_RECEIPTS_UNCHANGED');
    return ['self_mer_id' => $self, 'hashes' => $before, 'early_source' => $earlySource, 'early_history' => $earlyHistory];
}

function m4c_files_bootstrap(array &$evidence): void
{
    $manifestPath = '/run/audit/manifest.json'; $currentManifest = m4i_read($manifestPath);
    $currentManifestHash = hash_file('sha256',$manifestPath);
    $prerequisites = m4i_ms5_files_prerequisites($evidence['binding']);
    audit_guard_assert();
    $evidence['installation'] = m6i_migrator();
    $currentEarly = m4c_entry_early_history($prerequisites['early_source']);
    m4c_entry_same($currentEarly['hashes'],$prerequisites['early_history']['hashes']);
    m4c_entry_early_preserved($currentEarly,'files-bootstrap-completed');
    $evidence['actual_self_mer_id'] = $prerequisites['self_mer_id'];
    $evidence['initializer_result_sha256'] = $prerequisites['hashes']['initializer-result.json'];
    $evidence['migration_result_sha256'] = $prerequisites['hashes']['ms5-payment-migration-normal.json'];
    m4i_gate('files-bootstrap'); audit_guard_assert();
    $formalPath = '/run/audit/evidence/ms5-payment-migration-formal-manifest.json';
    m2f_assert(is_file($manifestPath) && !is_link($manifestPath) && is_file($formalPath) && !is_link($formalPath)
        && hash_file('sha256', $manifestPath) === $currentManifestHash && m4i_read($manifestPath) === $currentManifest
        && hash_file('sha256', $formalPath) === $prerequisites['hashes']['ms5-payment-migration-formal-manifest.json'],
        'CUSTOMER_POST_FILES_CURRENT_PHASE_AND_ORIGINAL_FORMAL_INDEPENDENTLY_UNCHANGED');
    foreach ($prerequisites['hashes'] as $name => $digest) m2f_assert(
        hash_file('sha256', '/run/audit/evidence/' . $name) === $digest, 'CUSTOMER_POST_FILES_COMMON_EIGHT_UNCHANGED');
    $evidence['status'] = 'VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY';
}

function m4i_main(): int
{
    $arguments = array_slice($_SERVER['argv'], 1);
    $phase = $arguments === [] ? 'initialize' : ($arguments[0] ?? 'invalid');
    $scope = in_array(getenv('MS4_PROOF_NAME'), ['register-worker-normal', 'authorize-worker-cleanup'], true)
        ? 'MS4_WORKER_LIFECYCLE_ONLY'
        : (in_array(getenv('MS4_PROOF_NAME'), ['register-aftersale-review-normal', 'authorize-aftersale-review-cleanup'], true)
            ? 'MS4_AFTERSALE_REVIEW_LIFECYCLE_ONLY'
            : (in_array(getenv('MS4_PROOF_NAME'), ['register-fulfillment-migration', 'register-fulfillment-normal', 'authorize-fulfillment-cleanup'], true)
                ? 'MS4_FULFILLMENT_LIFECYCLE_ONLY' : 'MS5_PAYMENT_PREREQUISITES_ONLY'));
    $evidence = ['schema' => 1, 'scope' => $scope, 'status' => 'FAIL', 'run_id' => getenv('AUDIT_RUN_ID'),
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
        m2f_assert($arguments === [] || (count($arguments) === 1 && in_array($phase, ['prepare', 'register', 'authorize-cleanup', 'files-bootstrap'], true)), 'FINITE_PHASE_ONLY');
        $evidence['binding'] = m4i_gate($phase);
        m4i_ms5_sources(m4i_read('/run/audit/preparation-source-binding.json')['driver_source_files']);
        if ($phase === 'prepare') { audit_prepare(false); $evidence['status'] = 'PREPARED_PRIVATE_CONFIG_ONLY'; }
        elseif ($phase === 'register') { audit_register_resources(); $evidence['status'] = 'REGISTERED_NATIVE_RESOURCES_ONLY'; }
        elseif ($phase === 'authorize-cleanup') { audit_authorize_cleanup(); $evidence['status'] = 'ORIGINAL_CLEANUP_AUTHORIZED_ONLY'; }
        elseif ($phase === 'files-bootstrap') { m4c_files_bootstrap($evidence); }
        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY'; }
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
