<?php
declare(strict_types=1);

/** Shared-method examples only. No App::initialize, database, HTTP or identity actors. */
use app\services\order\StoreOrderComputedServices;
use crmeb\services\CacheService;
use crmeb\services\SystemConfigService;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
umask(0077);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    $GLOBALS['m4op_warning'] = ['severity'=>$severity,'file'=>basename($file),'line'=>$line];
    throw new RuntimeException('PHP_RUNTIME_WARNING');
});
$assertions = 0;
function m4op_expect(bool $ok, string $code): void
{
    ++$GLOBALS['assertions'];
    if (!$ok) throw new RuntimeException($code);
}

try {
    $root = dirname(__DIR__, 3).'/crmeb/';
    require_once $root.'vendor/autoload.php';
    $app = new \think\App($root);
    require_once $root.'vendor/topthink/framework/src/helper.php';
    m4op_expect(app() === $app && !$app->initialized(), 'REAL_COMPONENT_APP');
    $runtime = '/tmp/ms4-offline-postage/';
    m4op_expect(!file_exists($runtime) && mkdir($runtime, 0700), 'OWN_TMPFS_RUNTIME');
    $app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false, 'CACHE_DRIVER'=>'file']);
    $app->register(\app\AppService::class);
    $app->bootService($app->getService(\app\AppService::class));
    $app->bind(require $root.'app/provider.php');
    foreach (['app','cache','log','marketplace','database'] as $name) {
        $app->config->set(require $root.'config/'.$name.'.php', $name);
    }
    require_once $root.'app/common.php';
    $app->register(\think\service\ModelService::class);
    $app->bootService($app->getService(\think\service\ModelService::class));
    m4op_expect($app->cache->store() instanceof \think\cache\driver\File
        && $app->config->get('marketplace.enabled') === false
        && $app->config->get('marketplace.trade.enabled') === false, 'REAL_FILE_CACHE_CLOSED_GATES');
    $service = $app->make(StoreOrderComputedServices::class);
    // Shape examples are not records or authorization evidence; freight policy is disabled.
    $cart = [['cart_num'=>1]];
    $address = ['id'=>'ordinary-calculation-shape'];
    $postage = ['storeFreePostage'=>'1000.00', 'storePostage'=>'5.00',
        'storePostageDiscount'=>'0.00', 'isStoreFreePostage'=>false, 'marketplace_postage_weights'=>[]];
    $call = static function (bool $v1, array $other, string $payType='offline', int $shipping=1) use ($service,$cart,$address,$postage): array {
        return $service->computedPayPostage($shipping,$payType,$cart,$address,'20.00',$postage,$other,[],0,$v1);
    };
    m4op_expect(\think\facade\Cache::tag('crmeb')->set('system_config_offline_postage', 1), 'NATIVE_OLD_CACHE_ONE_WRITE');
    // Native File Driver serializes numeric scalars as text and returns that exact text.
    m4op_expect(SystemConfigService::get('offline_postage') === '1', 'ACTUAL_OLD_CACHE_ONE_TEXT');
    $charged = $call(true,['offlinePostage'=>0]);
    m4op_expect($charged[0] === '25.00' && $charged[1] === '5.00', 'CURRENT_ZERO_CHARGES_ORIGINAL_POSTAGE');
    $legacy = $call(false,['offlinePostage'=>0]);
    m4op_expect($legacy[0] === '20.00' && $legacy[1] === 0, 'LEGACY_CACHE_COMPATIBILITY');
    $missing = $call(true,[]);
    m4op_expect($missing[0] === '20.00' && $missing[1] === '0.00', 'ABSENT_CURRENT_VALUE_COMPATIBILITY');
    m4op_expect(CacheService::set('system_config_offline_postage', 0), 'NATIVE_OLD_CACHE_ZERO_WRITE');
    m4op_expect(SystemConfigService::get('offline_postage') === '0', 'ACTUAL_OLD_CACHE_ZERO_TEXT');
    $free = $call(true,['offlinePostage'=>1]);
    m4op_expect($free[0] === '20.00' && $free[1] === '0.00', 'CURRENT_ONE_FREE_POSTAGE');
    $online = $call(true,['offlinePostage'=>1],'weixin');
    m4op_expect($online[0] === '25.00' && $online[1] === '5.00', 'ONLINE_KEEPS_ORIGINAL_CHARGE');
    m4op_expect(CacheService::set('system_config_store_self_mention', 1), 'NATIVE_PICKUP_CONFIG_WRITE');
    $pickup = $call(true,['offlinePostage'=>0],'offline',2);
    m4op_expect($pickup[0] === '20.00' && $pickup[1] === '0.00', 'PICKUP_COMPATIBLE_BRANCH');
    m4op_expect(!$app->initialized() && $app->config->get('marketplace.trade.enabled') === false, 'FINAL_CLOSED_GATE');
    echo json_encode(['status'=>'ORDINARY_SHARED_METHOD_ONLY','assertions'=>$assertions,
        'app_initialized'=>false,'sql_http_identity_acceptance'=>'NOT RUN'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['status'=>'FAIL','diagnostic'=>['class'=>get_class($error),
        'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'assertion'=>$error instanceof RuntimeException ? $error->getMessage() : null,
        'warning'=>$GLOBALS['m4op_warning'] ?? null]], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
