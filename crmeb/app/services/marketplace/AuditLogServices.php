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
        $productRead = $action === 'marketplace-products-list' && $resourceType === 'product' && $resourceId === 'global';
        $aftersaleRead = $action === 'marketplace-aftersales-list' && $resourceType === 'aftersale' && $resourceId === 'global';
        $reviewRead = $action === 'marketplace-reviews-list' && $resourceType === 'review' && $resourceId === 'global';
        $merchantRead = $resourceType === 'merchant'
            && in_array($action, ['marketplace-merchants-list', 'marketplace-merchants-read'], true);
        if (!$request instanceof Request || app('http')->getName() !== 'adminapi'
            || $actorType !== 'admin' || (!$merchantRead && !$productRead && !$aftersaleRead && !$reviewRead)
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
            || (($productRead || $aftersaleRead || $reviewRead) && $scope->targetMerId !== null)) {
            throw new AuthUnavailableException();
        }

        if ($productRead || $aftersaleRead || $reviewRead) app()->make(PlatformAccessServices::class)->authorizeScope($scope, $action);

        $admin = $request->adminInfo();
        if (!is_array($admin) || !isset($admin['id'], $admin['account'])
            || (!is_int($admin['id']) && !is_string($admin['id']))
            || (string) $admin['id'] !== (string) $actorId
            || !is_string($admin['account']) || $admin['account'] === ''
            || str_contains($admin['account'], "\0") || preg_match('//u', $admin['account']) !== 1) {
            throw new AuthUnavailableException();
        }

        $pdo = Db::connect()->getPdo();
        if ($productRead || $aftersaleRead || $reviewRead) {
            $logConnection = (new SystemLog())->db()->getConnection();
            if ($logConnection !== Db::connect() || $logConnection->getPdo() !== $pdo) throw new AuthUnavailableException();
        }
        if ($this->platformLog->recordAdminLog($actorId, $admin['account'], 'system') !== true
            || (($productRead || $aftersaleRead || $reviewRead) && Db::connect()->getPdo() !== $pdo)) {
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
