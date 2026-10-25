<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\AuditLogDao;
use app\dao\marketplace\scope\PlatformScope;
use app\model\system\log\SystemLog;
use app\Request;
use app\services\system\log\SystemLogServices;
use app\services\marketplace\product\LegacyProductWriteServices;
use app\services\marketplace\product\PlatformProductStockServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;
use Throwable;

/** Audit only server-generated metadata inside the caller's default transaction. */
final class AuditLogServices
{
    public function __construct(
        private readonly AuditLogDao $dao,
        private readonly SystemLogServices $platformLog
    ) {
    }

    public function record(
        string $actorType,
        int $actorId,
        ?int $merId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $result,
        array $detail = []
    ): void {
        try {
            if (!in_array($actorType, ['admin', 'merchant'], true)
                || $actorId <= 0 || $actorId > 4294967295
                || ($merId !== null && ($merId <= 0 || $merId > 4294967295))) {
                throw new AuthUnavailableException();
            }
            self::identifier($action, 100);
            self::identifier($resourceType, 64);
            self::identifier($resourceId, 64);
            self::identifier($result, 16);
            self::validateDetail($detail);
            self::assertTransaction();

            if ($merId === null) {
                $this->recordPlatformRead($actorType, $actorId, $action, $resourceType, $resourceId, $result, $detail);
                return;
            }

            $this->dao->insertValidated($merId, $actorType, $actorId, $action, $resourceType, $resourceId, $result, $detail, time());
        } catch (Throwable $error) {
            // A failed audit must fail the caller's transaction, never silently pass.
            throw new AuthUnavailableException();
        }
    }

    /** Only this fixed real API upload may produce api_user; generic record stays closed. */
    public function recordMobileProductImage(Request $request,int $productIdOrZero,array $native14): array
    {
        try {
            if(LegacyProductWriteServices::routeId($request->route('product_id',null,null),true)!==$productIdOrZero)throw new AuthUnavailableException();
            $uid=app()->make(\app\api\middleware\CustomerMiddleware::class)->assertMobileProductImage($request);
            $pdo=LegacyProductWriteServices::transaction();$connection=Db::connect();
            $owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
            if($connection->getPdo()!==$pdo||LegacyProductWriteServices::hasPlatformScope()||LegacyProductWriteServices::isSelfWorker()
                ||$owner!==LegacyProductWriteServices::selfId()||array_keys($native14)!==\app\dao\marketplace\product\PlatformProductMediaDao::ATTACHMENT_FIELDS)
                throw new AuthUnavailableException();
            if($productIdOrZero>0){$root=LegacyProductWriteServices::assertParent($productIdOrZero);
                if((string)$root['is_del']!=='0'||(string)$root['virtual_type']!=='0')throw new AuthUnavailableException();}
            $id=LegacyProductWriteServices::id($native14['att_id'],2147483647);
            if((string)$native14['mer_id']!==(string)$owner||(string)$native14['module_type']!=='1'||(string)$native14['type']!=='0'
                ||(string)$native14['pid']!=='0'||$native14['scan_token']!==''
                ||!in_array($native14['att_type'],\app\dao\marketplace\product\PlatformProductMediaDao::IMAGE_MIMES,true))throw new AuthUnavailableException();
            $prefix=$connection->getConfig('prefix');
            if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
                ||strlen($prefix.'system_attachment')>64||strlen($prefix.'marketplace_audit_log')>64)throw new AuthUnavailableException();
            $read=$pdo->prepare('SELECT * FROM `'.$prefix.'system_attachment` WHERE att_id=? AND mer_id=? LIMIT 2 FOR UPDATE');
            if($read===false)throw new AuthUnavailableException();
            try{if($read->execute([$id,$owner])!==true||$read->fetchAll(PDO::FETCH_ASSOC)!==[$native14])throw new AuthUnavailableException();}
            finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
            $detail=['changed_fields'=>['name','att_dir','satt_dir','pid']];self::validateDetail($detail);self::assertTransaction();
            $now=time();$auditId=$this->dao->insertValidated($owner,'api_user',$uid,'mobile-product-media-image-upload','attachment',(string)$id,'success',$detail,$now);
            $read=$pdo->prepare('SELECT * FROM `'.$prefix.'marketplace_audit_log` WHERE id=? AND mer_id=? LIMIT 2 FOR UPDATE');
            if($read===false)throw new AuthUnavailableException();
            try{if($read->execute([$auditId,$owner])!==true)throw new AuthUnavailableException();$rows=$read->fetchAll(PDO::FETCH_ASSOC);}
            finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
            if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time'])throw new AuthUnavailableException();
            $row=$rows[0];$expected=['id'=>$auditId,'mer_id'=>$owner,'actor_type'=>'api_user','actor_id'=>$uid,
                'action'=>'mobile-product-media-image-upload','resource_type'=>'attachment','resource_id'=>(string)$id,'result'=>'success','add_time'=>$now];
            foreach($expected as$key=>$value)if(is_int($value)?(string)$row[$key]!== (string)$value:$row[$key]!==$value)throw new AuthUnavailableException();
            if(!is_string($row['detail']))throw new AuthUnavailableException();
            $decoded=json_decode($row['detail'],false,16,JSON_THROW_ON_ERROR);
            if(!$decoded instanceof \stdClass||get_object_vars($decoded)!==$detail)throw new AuthUnavailableException();
            if(app()->make(\app\api\middleware\CustomerMiddleware::class)->assertMobileProductImage($request)!==$uid
                ||Db::connect()!==$connection||$connection->getPdo()!==$pdo||LegacyProductWriteServices::transaction()!==$pdo
                ||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
            return $row;
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }

    /** Server-only receipt from the stock service's current audit phase; the generic detail whitelist stays unchanged. */
    public function recordPlatformStock(PlatformScope $scope): int
    {
        try {
            $state = PlatformProductStockServices::currentAudit($scope); self::assertTransaction();
            if ($scope->targetMerId !== null || $scope->adminId <= 0 || $scope->adminId > 4294967295) throw new AuthUnavailableException();
            $access = app()->make(PlatformAccessServices::class);
            if ($state['legacy']) {
                if (!LegacyProductWriteServices::afterContentPhase() || LegacyProductWriteServices::rawScope() !== $scope) throw new AuthUnavailableException();
                $access->additionalStockGrant($scope);
            } else $access->authorizeScope($scope,PlatformProductStockServices::ACTION);
            $detail = $state['receipt'];
            $keys = ['product_id','attr_value_id','sku_unique','stock_before','delta','stock_after','root_stock_before','root_stock_after'];
            if (!is_array($detail) || array_keys($detail) !== $keys) throw new AuthUnavailableException();
            if (!is_int($state['audit_time']) || $state['audit_time'] < 1 || $state['audit_time'] > 4294967295) throw new AuthUnavailableException();
            foreach (['product_id'=>8388607,'attr_value_id'=>2147483647] as $field=>$maximum)
                if (!is_int($detail[$field]) || $detail[$field] < 1 || $detail[$field] > $maximum) throw new AuthUnavailableException();
            foreach (['stock_before','stock_after'] as $field) if (!is_int($detail[$field]) || $detail[$field] < 0 || $detail[$field] > 4294967295) throw new AuthUnavailableException();
            foreach (['root_stock_before','root_stock_after'] as $field) if (!is_int($detail[$field]) || $detail[$field] < 0 || $detail[$field] > 16777215) throw new AuthUnavailableException();
            if (!is_int($detail['delta']) || $detail['delta'] < -4294967295 || $detail['delta'] > 4294967295
                || $detail['stock_before'] + $detail['delta'] !== $detail['stock_after']
                || !is_string($detail['sku_unique']) || preg_match('/\A[A-Za-z0-9]{8}\z/D',$detail['sku_unique']) !== 1
                || $state['product_id'] !== $detail['product_id'] || !is_int($state['owner']) || $state['owner'] <= 0 || $state['owner'] > 4294967295) throw new AuthUnavailableException();
            $query = Db::name('store_product')->where('id',$detail['product_id'])->where('mer_id',$state['owner']);
            if ($query->getConnection() !== $state['connection'] || $query->getConnection()->getPdo() !== $state['pdo']) throw new AuthUnavailableException();
            $root = $query->lock(true)->find();
            $query = Db::name('store_product_attr_value')->where('product_id',$detail['product_id'])->where('type',0);
            if ($query->getConnection() !== $state['connection'] || $query->getConnection()->getPdo() !== $state['pdo']) throw new AuthUnavailableException();
            $skus = $query->order('id')->lock(true)->select()->toArray(); $matches = []; $sum = 0;
            foreach ($skus as $sku) {
                if ((!is_int($sku['stock']) && (!is_string($sku['stock']) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$sku['stock']) !== 1))
                    || (int)$sku['stock'] < 0 || (int)$sku['stock'] > 4294967295) throw new AuthUnavailableException();
                $sum += (int)$sku['stock']; if ($sum > 16777215) throw new AuthUnavailableException();
                if ((string)$sku['id'] === (string)$detail['attr_value_id'] && $sku['unique'] === $detail['sku_unique']) $matches[] = $sku;
            }
            if (!is_array($root) || (string)$root['stock'] !== (string)$detail['root_stock_after'] || $sum !== $detail['root_stock_after']
                || count($matches) !== 1 || (string)$matches[0]['stock'] !== (string)$detail['stock_after']) throw new AuthUnavailableException();
            return $this->dao->insertValidated($state['owner'],'admin',$scope->adminId,PlatformProductStockServices::ACTION,
                'product-stock',(string)$detail['attr_value_id'],'success',$detail,$state['audit_time']);
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (Throwable $error) { throw new AuthUnavailableException(); }
    }

    /** No fake merchant or generic null-owner write: this fixed route owns the platform audit. */
    public function recordPlatformAssetUpload(Request $request,PlatformScope $scope,array $native29): array
    {
        try{
            $pdo=Db::connect()->getPdo();if(!$pdo instanceof PDO||$scope->permission!==\app\services\marketplace\assets\PlatformAssetServices::UPLOAD)throw new AuthUnavailableException();
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo);
            \app\dao\marketplace\assets\PlatformAssetDao::asset($native29);
            if((string)$native29['created_by_admin_id']!==(string)$scope->adminId)throw new AuthUnavailableException();
            $actual=app()->make(\app\dao\marketplace\assets\PlatformAssetDao::class)->find($request,$scope,$pdo,\app\dao\marketplace\assets\PlatformAssetDao::id($native29['id']));
            if($actual!==$native29)throw new AuthUnavailableException();
            return $this->platformLog->recordPlatformAssetUploadRow($request,$scope,$pdo);
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    public function observePlatformAssetUpload(Request $request,PlatformScope $scope,PDO $pdo,array $native10): void
    {
        try{
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo,false);
            $fields=['id','admin_id','admin_name','path','page','method','ip','type','add_time','merchant_id'];
            if($scope->permission!==\app\services\marketplace\assets\PlatformAssetServices::UPLOAD||array_keys($native10)!==$fields
                ||(string)$native10['admin_id']!==(string)$scope->adminId||$native10['path']!=='marketplace/platform-assets'
                ||$native10['method']!=='adminapi'||$native10['type']!=='system'||(string)$native10['merchant_id']!=='0')throw new AuthUnavailableException();
            $prefix=Db::connect()->getConfig('prefix');
            if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'system_log')>64)throw new AuthUnavailableException();
            $id=\app\dao\marketplace\assets\PlatformAssetDao::integer($native10['id'],1,4294967295);
            $read=$pdo->prepare('SELECT * FROM `'.$prefix.'system_log` WHERE id=? LIMIT 2');if($read===false)throw new AuthUnavailableException();
            try{if($read->execute([$id])!==true||$read->fetchAll(PDO::FETCH_ASSOC)!==[$native10])throw new AuthUnavailableException();}
            finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo,false);
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }

    /** One real source-copy import records one actual target-owner audit; generic record stays unchanged. */
    public function recordPlatformProductImageImport(Request $request,int $productIdOrZero,array $native14): array
    {
        [$pdo,$scope,$owner]=$this->platformImageImportAuditContext($request,$productIdOrZero,$native14,true);
        $detail=['changed_fields'=>['name','att_dir','satt_dir','pid']];$now=time();self::validateDetail($detail);self::assertTransaction();
        $id=$this->dao->insertValidated($owner,'admin',$scope->adminId,\app\services\marketplace\assets\PlatformAssetServices::IMPORT,
            'attachment',(string)$native14['att_id'],'success',$detail,$now);
        $row=$this->platformImageImportAuditRow($pdo,$id,$owner,true);
        $expected=['id'=>$id,'mer_id'=>$owner,'actor_type'=>'admin','actor_id'=>$scope->adminId,
            'action'=>\app\services\marketplace\assets\PlatformAssetServices::IMPORT,'resource_type'=>'attachment','resource_id'=>(string)$native14['att_id'],'result'=>'success','add_time'=>$now];
        foreach($expected as$key=>$value)if(is_int($value)?(string)$row[$key]!== (string)$value:$row[$key]!==$value)throw new AuthUnavailableException();
        $decoded=json_decode($row['detail'],false,16,JSON_THROW_ON_ERROR);
        if(!$decoded instanceof \stdClass||get_object_vars($decoded)!==$detail)throw new AuthUnavailableException();
        $this->platformImageImportAuditContext($request,$productIdOrZero,$native14,true);return $row;
    }
    public function observePlatformProductImageImport(Request $request,int $productIdOrZero,array $native14,array $native10): void
    {
        [$pdo,$scope,$owner]=$this->platformImageImportAuditContext($request,$productIdOrZero,$native14,false);
        if(array_keys($native10)!==['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time']
            ||(string)$native10['mer_id']!==(string)$owner||$native10['actor_type']!=='admin'||(string)$native10['actor_id']!==(string)$scope->adminId
            ||$native10['action']!==\app\services\marketplace\assets\PlatformAssetServices::IMPORT||$native10['resource_type']!=='attachment'
            ||$native10['resource_id']!==(string)$native14['att_id']||$native10['result']!=='success')throw new AuthUnavailableException();
        $id=\app\dao\marketplace\assets\PlatformAssetDao::integer($native10['id'],1,4294967295);
        if($this->platformImageImportAuditRow($pdo,$id,$owner,false)!==$native10)throw new AuthUnavailableException();
        $this->platformImageImportAuditContext($request,$productIdOrZero,$native14,false);
    }
    private function platformImageImportAuditContext(Request $request,int $productIdOrZero,array $native14,bool $inside): array
    {
        $scope=$request->platformScope();$pdo=Db::connect()->getPdo();
        if(!$pdo instanceof PDO||$scope->permission!==\app\services\marketplace\assets\PlatformAssetServices::IMPORT
            ||LegacyProductWriteServices::routeId($request->route('id',null,null),true)!==$productIdOrZero
            ||array_keys($native14)!==\app\dao\marketplace\product\PlatformProductMediaDao::ATTACHMENT_FIELDS)throw new AuthUnavailableException();
        \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo,$inside);
        $owner=\app\dao\marketplace\assets\PlatformAssetDao::integer($native14['mer_id'],1,4294967295);
        $id=\app\dao\marketplace\assets\PlatformAssetDao::integer($native14['att_id'],1,2147483647);
        if($inside&&LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
        if((string)$native14['module_type']!=='1'||(string)$native14['type']!=='0'||$native14['scan_token']!==''
            ||!in_array($native14['att_type'],\app\dao\marketplace\product\PlatformProductMediaDao::IMAGE_MIMES,true))throw new AuthUnavailableException();
        $prefix=Db::connect()->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'system_attachment')>64)throw new AuthUnavailableException();
        $read=$pdo->prepare('SELECT * FROM `'.$prefix.'system_attachment` WHERE att_id=? AND mer_id=? LIMIT 2'.($inside?' FOR UPDATE':''));
        if($read===false)throw new AuthUnavailableException();
        try{if($read->execute([$id,$owner])!==true||$read->fetchAll(PDO::FETCH_ASSOC)!==[$native14])throw new AuthUnavailableException();}
        finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
        return [$pdo,$scope,$owner];
    }
    private function platformImageImportAuditRow(PDO $pdo,int $id,int $owner,bool $inside): array
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'marketplace_audit_log')>64||Db::connect()->getPdo()!==$pdo||$pdo->inTransaction()!==$inside)throw new AuthUnavailableException();
        $read=$pdo->prepare('SELECT * FROM `'.$prefix.'marketplace_audit_log` WHERE id=? AND mer_id=? LIMIT 2'.($inside?' FOR UPDATE':''));if($read===false)throw new AuthUnavailableException();
        try{if($read->execute([$id,$owner])!==true)throw new AuthUnavailableException();$rows=$read->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
        if(count($rows)!==1||array_keys($rows[0])!==['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time']
            ||!is_string($rows[0]['detail']))throw new AuthUnavailableException();return $rows[0];
    }

    private function recordPlatformRead(
        string $actorType,
        int $actorId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $result,
        array $detail
    ): void {
        $request = app()->request;
        $assetRead = ($action === 'platform-assets-list' && $resourceType === 'platform-asset' && $resourceId === 'global')
            || ($action === 'platform-asset-categories-list' && $resourceType === 'platform-asset-category' && $resourceId === 'global');
        $productRead = $action === 'marketplace-products-list' && $resourceType === 'product' && $resourceId === 'global';
        $aftersaleRead = $action === 'marketplace-aftersales-list' && $resourceType === 'aftersale' && $resourceId === 'global';
        $reviewRead = $action === 'marketplace-reviews-list' && $resourceType === 'review' && $resourceId === 'global';
        $merchantRead = $resourceType === 'merchant'
            && in_array($action, ['marketplace-merchants-list', 'marketplace-merchants-read'], true);
        if (!$request instanceof Request || app('http')->getName() !== 'adminapi'
            || $actorType !== 'admin' || (!$merchantRead && !$productRead && !$aftersaleRead && !$reviewRead && !$assetRead)
            || $result !== 'success' || $detail !== [] || $request->method() !== 'GET'
        ) {
            throw new AuthUnavailableException();
        }
        $rule = $request->rule();
        if ($rule === null || $rule->getOption('marketplace_api') !== true
            || $rule->getOption('marketplace_permission') !== $action
            || $request->isAdminLogin() !== true) {
            throw new AuthUnavailableException();
        }

        $scope = $request->platformScope();
        $requestAdminId = $request->adminId();
        if ($scope->adminId !== $actorId || $scope->permission !== $action
            || (!is_int($requestAdminId) && !is_string($requestAdminId))
            || (string) $requestAdminId !== (string) $actorId) {
            throw new AuthUnavailableException();
        }
        if (($action === 'marketplace-merchants-list' && ($scope->targetMerId !== null || $resourceId !== 'global'))
            || ($action === 'marketplace-merchants-read'
                && ($scope->targetMerId === null || $resourceId !== (string) $scope->targetMerId))
            || (($productRead || $aftersaleRead || $reviewRead || $assetRead) && $scope->targetMerId !== null)) {
            throw new AuthUnavailableException();
        }

        if ($productRead || $aftersaleRead || $reviewRead || $assetRead) app()->make(PlatformAccessServices::class)->authorizeScope($scope, $action);

        $admin = $request->adminInfo();
        if (!is_array($admin) || !isset($admin['id'], $admin['account'])
            || (!is_int($admin['id']) && !is_string($admin['id']))
            || (string) $admin['id'] !== (string) $actorId
            || !is_string($admin['account']) || $admin['account'] === ''
            || str_contains($admin['account'], "\0") || preg_match('//u', $admin['account']) !== 1) {
            throw new AuthUnavailableException();
        }

        $pdo = Db::connect()->getPdo();
        if ($productRead || $aftersaleRead || $reviewRead || $assetRead) {
            $logConnection = (new SystemLog())->db()->getConnection();
            if ($logConnection !== Db::connect() || $logConnection->getPdo() !== $pdo) throw new AuthUnavailableException();
        }
        if ($this->platformLog->recordAdminLog($actorId, $admin['account'], 'system') !== true
            || (($productRead || $aftersaleRead || $reviewRead || $assetRead) && Db::connect()->getPdo() !== $pdo)) {
            throw new AuthUnavailableException();
        }
        self::assertTransaction();
    }

    private static function assertTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new AuthUnavailableException();
        }
    }

    private static function identifier(string $value, int $max): void
    {
        if ($value === '' || strlen($value) > $max
            || preg_match('/\A[a-z0-9]+(?:[._:-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new AuthUnavailableException();
        }
    }

    private static function validateDetail(array $detail): void
    {
        foreach ($detail as $key => $value) {
            if ($key === 'changed_fields') {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new AuthUnavailableException();
                }
                foreach ($value as $field) {
                    if (!is_string($field) || strlen($field) > 64
                        || preg_match('/\A[a-z][a-z0-9_]*\z/', $field) !== 1) {
                        throw new AuthUnavailableException();
                    }
                }
            } elseif ($key === 'employee_id' || $key === 'role_id') {
                if (!is_int($value) || $value <= 0 || $value > 4294967295) {
                    throw new AuthUnavailableException();
                }
            } elseif ($key === 'protected_employee_count') {
                if (!is_int($value) || $value < 0 || $value > 4294967295) {
                    throw new AuthUnavailableException();
                }
            } else {
                throw new AuthUnavailableException();
            }
        }
    }
}
