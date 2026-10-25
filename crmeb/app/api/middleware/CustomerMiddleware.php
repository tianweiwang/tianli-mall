<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
namespace app\api\middleware;

use app\services\kefu\service\StoreServiceServices;
use app\services\order\DeliveryServiceServices;
use app\services\system\store\SystemStoreStaffServices;
use crmeb\interfaces\MiddlewareInterface;
use app\Request;

class CustomerMiddleware implements MiddlewareInterface
{
    /** Keep the three original management roles, including delivery personnel. */
    public function hasManagementAccess(Request $request): bool
    {
        $uid=$request->uid();
        $services=app()->make(StoreServiceServices::class);
        $storeServices=app()->make(SystemStoreStaffServices::class);
        $deliveryService=app()->make(DeliveryServiceServices::class);
        $isDelivery=$deliveryService->checkoutIsService($uid);
        return $services->checkoutIsService(['uid'=>$uid,'status'=>1,'customer'=>1])
            ||$storeServices->verifyStatus($uid)||$isDelivery;
    }

    public function handle(Request $request, \Closure $next)
    {
        $rule=trim(strtolower($request->rule()->getRule()));
        $withRule=['/api/order/order_verific',"/api/admin/order/detail/<orderId>"];
        if(!$this->hasManagementAccess($request)&&!in_array($rule,$withRule))return app('json')->fail('权限不足');
        return $next($request);
    }

    /** Fixed mobile image action; neither an owner grant nor a reusable actor context. */
    public function assertMobileProductImage(Request $request): int
    {
        try {
            $rule=$request->rule();
            if(app()->request!==$request||app()->http->getName()!=='api'||$request->method(true)!=='POST'
                ||$rule===null||$rule->getOption('mobile_product_media')!=='image-upload'
                ||$rule->getRoute()!=='v1.admin.StoreManageController/uploadProductImage'||$request->isLogin()!==true)
                throw new \crmeb\exceptions\AuthUnavailableException();
            $uid=\app\services\marketplace\product\LegacyProductWriteServices::id($request->uid(),4294967295);
            $tokenData=$request->tokenData();$fingerprint=$request->verifiedTokenFingerprint();
            if(!is_array($tokenData)||!is_string($tokenData['token']??null)||$tokenData['token']===''
                ||preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1
                ||!hash_equals($fingerprint,hash('sha256',$tokenData['token']))
                ||!\crmeb\services\SessionWhitelist::matches($tokenData,'api',$uid,$tokenData['token'])
                ||!\crmeb\services\SessionWhitelist::matches(\crmeb\services\SessionWhitelist::read($tokenData['token']),'api',$uid,$tokenData['token']))
                throw new \crmeb\exceptions\AuthUnavailableException();
            $connection=\think\facade\Db::connect();$pdo=$connection->getPdo();
            $inside=\app\services\marketplace\product\LegacyProductWriteServices::active();
            if(!$pdo instanceof \PDO||($inside&&\app\services\marketplace\product\LegacyProductWriteServices::transaction()!==$pdo))
                throw new \crmeb\exceptions\AuthUnavailableException();
            $query=\think\facade\Db::name('user')->where('uid',$uid)->where('status',1)->where('is_del',0)->field(['uid','status','is_del'])->lock($inside);
            if($query->getConnection()!==$connection||$query->getConnection()->getPdo()!==$pdo)throw new \crmeb\exceptions\AuthUnavailableException();
            $user=$query->find();
            $allowed=$inside?$this->hasCurrentMobileManagementAccess($request):$this->hasManagementAccess($request);
            if(!is_array($user)||(string)$user['uid']!==(string)$uid||(string)$user['status']!=='1'||(string)$user['is_del']!=='0'
                ||!$allowed)throw new \think\exception\HttpException(403,'权限不足');
            if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo
                ||($inside&&\app\services\marketplace\product\LegacyProductWriteServices::transaction()!==$pdo)
                ||$request->uid()!==$uid||$request->verifiedTokenFingerprint()!==$fingerprint)
                throw new \crmeb\exceptions\AuthUnavailableException();
            return $uid;
        } catch(\think\exception\HttpException $error) { throw $error; }
        catch(\Throwable $error) { throw new \crmeb\exceptions\AuthUnavailableException(); }
    }

    /** The new writer uses current locking reads, not an earlier repeatable-read snapshot. */
    private function hasCurrentMobileManagementAccess(Request $request): bool
    {
        $pdo=\app\services\marketplace\product\LegacyProductWriteServices::transaction();
        $connection=\think\facade\Db::connect();$uid=$request->uid();$allowed=false;
        $roles=[['store_service',['uid'=>$uid,'status'=>1,'customer'=>1]],
            ['system_store_staff',['uid'=>$uid,'status'=>1,'verify_status'=>1]],
            ['delivery_service',['uid'=>$uid,'status'=>1]]];
        foreach($roles as[$table,$where]){
            $query=\think\facade\Db::name($table)->where($where)->field(['uid'])->lock(true);
            if($query->getConnection()!==$connection||$query->getConnection()->getPdo()!==$pdo)throw new \crmeb\exceptions\AuthUnavailableException();
            $row=$query->find();
            if($row!==null){
                if(!is_array($row)||array_keys($row)!==['uid']||(string)$row['uid']!==(string)$uid)throw new \crmeb\exceptions\AuthUnavailableException();
                $allowed=true;
            }
        }
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||\app\services\marketplace\product\LegacyProductWriteServices::transaction()!==$pdo)throw new \crmeb\exceptions\AuthUnavailableException();
        return $allowed;
    }

}
