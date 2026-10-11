<?php
declare(strict_types=1);
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';

function m1c_snapshot(): array
{
    $pdo = audit_db(); $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $spec = ['tables'=>[], 'redis'=>['*']]; $ddl = []; $checksums = [];
    foreach ($names as $name) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $name) === 1, 'snapshot table name differs');
        $spec['tables'][$name] = null;
        $create = $pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
        $ddl[$name] = ['sha256'=>hash('sha256', $create)];
        if (in_array($name, ['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee'], true)) {
            $ddl[$name]['without_counter_sha256'] = hash('sha256', preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+ /', '$1 ', $create));
        }
        $checksum = $pdo->query('CHECKSUM TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC)['Checksum'];
        $checksums[$name] = $checksum === null ? null : (string)$checksum;
    }
    $raw = audit_snapshot($spec); $keys = array_keys($raw['redis']['*']); $redis = [];
    $deadlines = $keys ? audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r", $keys, count($keys)) : [];
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'cache deadlines unavailable');
    foreach ($keys as $i=>$key) $redis[hash('sha256', $key)] = ['sha256'=>$raw['redis']['*'][$key], 'deadline'=>$deadlines[$i]];
    ksort($redis);
    return ['names'=>$names,'rows'=>$raw['tables'],'ddl'=>$ddl,'checksums'=>$checksums,'redis'=>$redis];
}

function m1c_unchanged(array $before, array $after, bool $fixtures): array
{
    audit_expect($before['names'] === $after['names'], 'table inventory changed');
    foreach ($before['names'] as $name) {
        audit_expect($before['rows'][$name] === $after['rows'][$name] && $before['checksums'][$name] === $after['checksums'][$name], 'original table rows or checksum changed');
        $key = $fixtures && in_array($name, ['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee'], true) ? 'without_counter_sha256' : 'sha256';
        audit_expect($before['ddl'][$name][$key] === $after['ddl'][$name][$key], 'original table structure changed');
    }
    audit_expect(array_keys($before['redis']) === array_keys($after['redis']), 'cache inventory changed');
    foreach ($before['redis'] as $key=>$value) {
        $actual = $after['redis'][$key];
        audit_expect($value['sha256'] === $actual['sha256'], 'cache value changed');
        audit_expect(($value['deadline'] < 0 || $actual['deadline'] < 0) ? $value['deadline'] === $actual['deadline'] : abs($value['deadline']-$actual['deadline']) <= 1, 'cache expiry changed');
    }
    return ['tables'=>count($before['names']),'original_rows_schema_checksums_unchanged'=>true,'cache_values_expiry_unchanged'=>true,
        'counter_changes_allowed_only'=>$fixtures ? ['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee'] : []];
}

function m1c_fixtures(PDO $pdo, array &$fixtures): void
{
    $slug = 'ms1_context_' . substr(hash('sha256', (string)getenv('AUDIT_RUN_ID')), 0, 12);
    audit_expect(!$pdo->inTransaction(), 'fixture connection must own its transaction');
    $pdo->beginTransaction();
    try {
        foreach (['A'=>\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN, 'B'=>\app\services\marketplace\MerchantPermissions::BUILTIN_OPERATOR] as $label=>$permissions) {
            $fixture = ['label'=>$label, 'merchant_name'=>$slug.'_'.$label, 'role_name'=>'Context '.$label,
                'account'=>$slug.'_'.strtolower($label), 'permissions'=>$permissions];
            $fixtures[$label] = $fixture;
            $now = time();
            $statement = $pdo->prepare('INSERT INTO eb_marketplace_merchant(name,review_status,add_time,update_time) VALUES(?,1,?,?)');
            $statement->execute([$fixture['merchant_name'],$now,$now]);
            $fixtures[$label]['mer_id'] = (int)$pdo->lastInsertId();
            $statement = $pdo->prepare('INSERT INTO eb_marketplace_role(mer_id,name,permissions,add_time,update_time) VALUES(?,?,?,?,?)');
            $statement->execute([$fixtures[$label]['mer_id'],$fixture['role_name'],json_encode($permissions, JSON_THROW_ON_ERROR),$now,$now]);
            $fixtures[$label]['role_id'] = (int)$pdo->lastInsertId();
            $statement = $pdo->prepare('INSERT INTO eb_marketplace_employee(mer_id,role_id,account,name,add_time,update_time) VALUES(?,?,?,?,?,?)');
            $statement->execute([$fixtures[$label]['mer_id'],$fixtures[$label]['role_id'],$fixture['account'],'Context '.$label,$now,$now]);
            $fixtures[$label]['employee_id'] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function m1c_cleanup(PDO $pdo, array $fixtures): void
{
    foreach (array_reverse($fixtures, true) as $fixture) {
        if (isset($fixture['employee_id'])) {
            $statement = $pdo->prepare('DELETE FROM eb_marketplace_employee WHERE id=? AND mer_id=? AND account=?');
            $statement->execute([$fixture['employee_id'],$fixture['mer_id'],$fixture['account']]);
        }
        if (isset($fixture['role_id'])) {
            $statement = $pdo->prepare('DELETE FROM eb_marketplace_role WHERE id=? AND mer_id=? AND name=?');
            $statement->execute([$fixture['role_id'],$fixture['mer_id'],$fixture['role_name']]);
        }
        if (isset($fixture['mer_id'])) {
            $statement = $pdo->prepare('DELETE FROM eb_marketplace_merchant WHERE mer_id=? AND name=?');
            $statement->execute([$fixture['mer_id'],$fixture['merchant_name']]);
        }
    }
}

function m1c_orm(): \think\DbManager
{
    $config = audit_config()['database'];
    $db = new \think\DbManager();
    $db->setConfig(['default'=>'mysql','auto_timestamp'=>false,'connections'=>['mysql'=>[
        'type'=>\crmeb\compat\think\orm\db\connector\Mysql::class, 'hostname'=>'audit-mysql','hostport'=>'3306',
        'database'=>'audit_auth','username'=>'audit_app','password'=>$config['password'],'charset'=>'utf8mb4','prefix'=>'eb_',
        'debug'=>false,'fields_strict'=>true,'break_reconnect'=>false,
    ]]]);
    return $db;
}

audit_case('T3C01','normal_context_and_queries','INTEGRATION',static function(): array {
    $evidence = ['run_id'=>getenv('AUDIT_RUN_ID'),'negative_cases_run'=>false]; $fixtures = []; $failure = null; $cleanupFailure = null;
    try {
        $before = m1c_snapshot(); $evidence['before'] = $before;
        foreach (['app\\services\\marketplace\\MerchantPermissions','app\\services\\marketplace\\context\\MerchantContext',
            'app\\dao\\marketplace\\scope\\MerchantScope','app\\dao\\marketplace\\scope\\PlatformScope','app\\dao\\marketplace\\scope\\PublicCatalogScope',
            'crmeb\\exceptions\\MerchantForbiddenException','crmeb\\exceptions\\MerchantNotFoundException','crmeb\\exceptions\\MerchantUnauthenticatedException'] as $class) {
            audit_expect(class_exists($class), 'Task3 implementation unavailable');
        }
        audit_expect(count($before['names']) === 163, 'installed base and five identity tables required');
        foreach (['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_audit_log','eb_marketplace_password_setup'] as $table) {
            audit_expect($before['rows'][$table]['count'] === 0, 'fresh identity tables required');
        }
        $expectedPermissions = ['merchant.profile.read','merchant.employee.manage','merchant.role.manage','product.read','product.write','product.submit_review','stock.adjust','attachment.manage','freight.manage'];
        audit_expect(\app\services\marketplace\MerchantPermissions::ALL === $expectedPermissions, 'permission catalog differs from plan');
        audit_expect(\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN === $expectedPermissions, 'administrator catalog differs');
        audit_expect(\app\services\marketplace\MerchantPermissions::BUILTIN_OPERATOR === ['merchant.profile.read','product.read','product.write','product.submit_review','stock.adjust','attachment.manage','freight.manage'], 'operator catalog differs');
        $pdo = audit_db(); m1c_fixtures($pdo, $fixtures); $orm = m1c_orm(); $observations = []; $contexts = [];
        foreach ($fixtures as $label=>$fixture) {
            $statement = $pdo->prepare('SELECT e.*,r.permissions,m.review_status,m.security_locked,m.closed_at FROM eb_marketplace_employee e JOIN eb_marketplace_role r ON r.mer_id=e.mer_id AND r.id=e.role_id JOIN eb_marketplace_merchant m ON m.mer_id=e.mer_id WHERE e.id=?');
            $statement->execute([$fixture['employee_id']]); $row = $statement->fetch();
            audit_expect(is_array($row) && (int)$row['status'] === 1 && (int)$row['is_del'] === 0 && (int)$row['review_status'] === 1 && (int)$row['security_locked'] === 0 && $row['closed_at'] === null, 'normal identity fixture differs');
            $ctx = new \app\services\marketplace\context\MerchantContext((int)$row['mer_id'],(int)$row['id'],json_decode($row['permissions'],true,32,JSON_THROW_ON_ERROR),bin2hex(random_bytes(16)),bin2hex(random_bytes(16)));
            $contexts[$label] = $ctx;
            audit_expect($ctx->merId === $fixture['mer_id'] && $ctx->employeeId === $fixture['employee_id'] && $ctx->permissions === $fixture['permissions'] && $ctx->can('merchant.profile.read'), 'normal context differs');
            $ctx->require('merchant.profile.read');
            audit_expect((new ReflectionClass($ctx))->isReadOnly(), 'context must be readonly');
            \app\dao\marketplace\scope\MerchantScope::assertOwned($row,$ctx);
            $model = new class($row) extends \think\Model { protected $name='marketplace_employee'; protected $pk='id'; };
            \app\dao\marketplace\scope\MerchantScope::assertOwned($model,$ctx);
            $modelQuery = \app\dao\marketplace\scope\MerchantScope::apply($model,$ctx);
            audit_expect($modelQuery instanceof \think\db\Query && array_map('intval',$modelQuery->order('id')->column('id')) === [$fixture['employee_id']], 'normal Model scope differs');
            $observations[$label] = ['mer_id'=>$ctx->merId,'employee_id'=>$ctx->employeeId,'permissions'=>count($ctx->permissions),'owned_array_model'=>true,'model_query'=>true];
        }
        $template = $orm->name('marketplace_employee')->alias('e')->whereRaw('`e`.`status` = :normal_status',['normal_status'=>1])->whereOr('e.name','Context A');
        $templateOptions = $template->getOptions('where'); $templateBindings = $template->getBind(false);
        foreach ($contexts as $label=>$ctx) {
            $scoped = \app\dao\marketplace\scope\MerchantScope::apply($template,$ctx,'e.mer_id');
            $evidence['sql'][$label] = (clone $scoped)->field('e.id,e.mer_id')->fetchSql()->select();
            $literal = preg_quote((string)$ctx->merId,'/');
            $ownerPattern = '/\)\s+AND\s+`e`\.`mer_id`\s*=\s*(?:'.$literal.'|\x27'.$literal.'\x27)\s*\z/i';
            audit_expect(preg_match($ownerPattern,$evidence['sql'][$label]) === 1, 'existing filters must remain grouped before exact ownership');
            audit_expect(array_map('intval',$scoped->order('e.id')->column('e.id')) === [$fixtures[$label]['employee_id']], 'normal scoped list differs');
        }
        audit_expect($template->getOptions('where') === $templateOptions && $template->getBind(false) === $templateBindings, 'shared query template changed');
        $requestA = new \app\Request(); $requestB = new \app\Request();
        $requestA->setMerchantContext($contexts['A']); $requestB->setMerchantContext($contexts['B']);
        audit_expect($requestA->merchantContext() === $contexts['A'] && $requestB->merchantContext() === $contexts['B'], 'request instances must retain their own context');
        $requestA->clearMerchantContext(); $requestB->clearMerchantContext();
        $property = new ReflectionProperty(\app\Request::class,'merchantContextValue');
        audit_expect(!$property->isStatic() && $property->isPrivate() && $property->getValue($requestA) === null && $property->getValue($requestB) === null, 'normal request cleanup must release local context');
        $platformTarget = new \app\dao\marketplace\scope\PlatformScope(1,'marketplace-merchants-read',$fixtures['A']['mer_id']);
        $platformGlobal = new \app\dao\marketplace\scope\PlatformScope(1,'marketplace-merchants-list',null);
        audit_expect(array_map('intval',$platformTarget->apply($template,'e.mer_id')->order('e.id')->column('e.id')) === [$fixtures['A']['employee_id']], 'explicit normal platform target differs');
        audit_expect(array_map('intval',$platformGlobal->apply($template,'e.mer_id')->order('e.id')->column('e.id')) === array_column($fixtures,'employee_id'), 'explicit authorized global range differs');
        $merchantFields = ['mer_id','name','logo','after_sale_contact','shipping_note'];
        $productFields = ['id','mer_id','store_name','store_info','image','slider_image','price','ot_price','vip_price','unit_name','sales','stock','spec_type','is_postage','postage','is_vip','video_link'];
        audit_expect(\app\dao\marketplace\scope\PublicCatalogScope::fields('merchant') === $merchantFields && \app\dao\marketplace\scope\PublicCatalogScope::fields('product') === $productFields, 'public projection differs from frozen contract');
        $publicRows = $orm->name('marketplace_merchant')->field($merchantFields)->order('mer_id')->select()->toArray();
        audit_expect(count($publicRows) === 2, 'normal public merchant fixture count differs');
        foreach ($publicRows as $row) audit_expect(array_keys($row) === $merchantFields, 'actual public merchant projection differs');
        $evidence['public_product_sql'] = $orm->name('store_product')->field($productFields)->limit(1)->fetchSql()->select();
        audit_expect(!str_contains($evidence['public_product_sql'],'*') && !str_contains($evidence['public_product_sql'],'`cost`') && !str_contains($evidence['public_product_sql'],'`mer_use`'), 'public SQL must use its declared projection');
        $evidence['observations'] = $observations; $evidence['request_local_cleanup'] = true;
        $evidence['platform_ranges'] = ['target'=>$fixtures['A']['mer_id'],'global_explicit'=>true,'actual_authorization_pending_task6'=>true];
        $evidence['public_fields'] = ['merchant'=>$merchantFields,'product'=>$productFields];
    } catch (Throwable $error) {
        $failure = ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]; $evidence['diagnostic'] = $failure;
    } finally {
        if ($fixtures !== []) {
            try { m1c_cleanup($pdo,$fixtures); } catch (Throwable $error) { $cleanupFailure = ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]; $evidence['cleanup_diagnostic'] = $cleanupFailure; }
        }
    }
    if (isset($before)) {
        try { $after=m1c_snapshot(); $evidence['after']=$after; $evidence['preservation']=m1c_unchanged($before,$after,$fixtures !== []); audit_guard_assert(); }
        catch (Throwable $error) { $failure ??= ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]; $evidence['preservation_diagnostic'] = ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]; }
    }
    $evidence['fixtures_cleaned'] = $fixtures !== [] && $cleanupFailure === null; $evidence['synthetic_fixture_ids'] = array_map(static fn(array $f): array => array_intersect_key($f,array_flip(['mer_id','role_id','employee_id'])),$fixtures);
    $ref='evidence/ms1-context-'.(getenv('AUDIT_PHASE') ?: 'after').'.json'; audit_write_json(audit_run_dir().'/'.$ref,$evidence);
    $passed=$failure === null && $cleanupFailure === null;
    return ['status'=>$passed ? 'PASS':'FAIL','actual'=>$passed ? 'real ORM normal contexts, request-local values, owned queries, explicit platform ranges and public projections; fixture rows cleaned and original state preserved' : ($failure ?? $cleanupFailure)['class'].'@'.($failure ?? $cleanupFailure)['file'].':'.($failure ?? $cleanupFailure)['line'],
        'expected'=>'normal Task3 value and query contracts only','evidence_ref'=>$ref,'notes'=>'synthetic value-object versions; no token authentication or HTTP status acceptance; original rejection cases remain NOT RUN'];
});
audit_case('M04','invalid_or_missing_context','UNIT-STUB',static fn(): array => ['status'=>'NOT RUN','actual'=>'skipped at user request; no invalid IDs, missing-context calls or denied actions executed','expected'=>'original invalid/missing-context rejection acceptance remains unverified']);
audit_case('M08','foreign_object_or_inactive_merchant','INTEGRATION',static fn(): array => ['status'=>'NOT RUN','actual'=>'skipped at user request; no foreign-object refusal or inactive-merchant request executed','expected'=>'original refusal acceptance remains unverified']);
audit_finish();
