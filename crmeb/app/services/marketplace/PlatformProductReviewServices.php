<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\dao\marketplace\PlatformProductDao;
use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;
use think\exception\ValidateException;

final class PlatformProductReviewServices
{
    private const LIST = 'marketplace-products-list';
    private const REVIEW = 'marketplace-products-review';
    private const OFF_SHELF = 'marketplace-products-off-shelf';

    public function __construct(private PlatformProductDao $products, private PlatformAccessServices $access,
        private AuditLogServices $audit) {}

    public static function productId(mixed $value): int
    { return MerchantManagementInput::integer($value, 1, 8388607, true); }

    public function listing(PlatformScope $scope, array $query): array
    {
        $this->authorize($scope, self::LIST);
        MerchantManagementInput::body($query, ['review_status','mer_id','page','limit']);
        $review = MerchantManagementInput::integer($query['review_status'] ?? 1, 0, 3, true);
        $merId = array_key_exists('mer_id', $query) ? MerchantManagementInput::integer($query['mer_id'], 1, 4294967295, true) : null;
        $page = MerchantManagementInput::integer($query['page'] ?? 1, 1, 2147483647, true);
        $limit = MerchantManagementInput::integer($query['limit'] ?? 20, 1, 100, true);
        return $this->transaction($scope, self::LIST, function () use ($scope, $page, $limit, $review, $merId): array {
            $result = $this->products->listing($scope, $page, $limit, $review, $merId);
            $result['list'] = array_map([self::class, 'dto'], $result['list']);
            $result['count'] = self::storedInteger($result['count'], 0, 4294967295);
            $this->audit->record('admin', $scope->adminId, null, self::LIST, 'product', 'global', 'success', []);
            return $result;
        });
    }

    public function review(PlatformScope $scope, int $id, array $body): array
    {
        $this->authorize($scope, self::REVIEW);
        self::productId($id);
        MerchantManagementInput::body($body, ['action','reason'], ['action']);
        if (!is_string($body['action']) || !in_array($body['action'], ['approve','reject'], true)) self::invalid();
        $action = $body['action'];
        $reason = MerchantManagementInput::text($body['reason'] ?? '', 512, $action === 'reject', true);
        if ($action === 'approve' && $reason !== '') self::invalid();
        return $this->transaction($scope, self::REVIEW, function () use ($scope, $id, $action, $reason): array {
            $before = $this->products->lockProduct($scope, $id);
            $root = self::dto($before);
            if ($root['is_del'] !== 0) throw new HttpException(404, '资源不存在');
            $merId = self::storedInteger($before['mer_id'], 1, 4294967295);
            $categories = $this->products->categories($scope, $id, $merId);
            $changes = [];
            if ($action === 'approve') {
                if ($root['review_status'] === 1) $changes = $this->reviewStamp($scope, 2, '', $root);
                elseif ($root['review_status'] !== 2) self::invalid();
            } else {
                if ($root['review_status'] === 1 || ($root['review_status'] === 3 && $root['review_reason'] !== $reason)) {
                    $changes = $this->reviewStamp($scope, 3, $reason, $root);
                } elseif ($root['review_status'] !== 3) self::invalid();
            }
            $after = $this->products->updateStatus($scope, $id, $merId, $before, $changes);
            $fields = array_keys($changes);
            // Repeated approval must preserve a later, properly authorized merchant show.
            if ($changes !== [] && $this->products->hideCategories($scope, $id, $merId, $categories)) $fields[] = 'categories';
            $this->audit->record('admin', $scope->adminId, $merId, self::REVIEW, 'product', (string)$id,
                'success', ['changed_fields'=>$fields]);
            return self::stateDto($after);
        });
    }

    public function offShelf(PlatformScope $scope, int $id, array $body): array
    {
        $this->authorize($scope, self::OFF_SHELF);
        self::productId($id);
        MerchantManagementInput::body($body, []);
        return $this->transaction($scope, self::OFF_SHELF, function () use ($scope, $id): array {
            $before = $this->products->lockProduct($scope, $id);
            $root = self::dto($before);
            if ($root['is_del'] !== 0) throw new HttpException(404, '资源不存在');
            $merId = self::storedInteger($before['mer_id'], 1, 4294967295);
            $categories = $this->products->categories($scope, $id, $merId);
            $changes = $root['is_show'] === 0 ? [] : ['is_show'=>0];
            $after = $this->products->updateStatus($scope, $id, $merId, $before, $changes);
            $fields = array_keys($changes);
            if ($this->products->hideCategories($scope, $id, $merId, $categories)) $fields[] = 'categories';
            $this->audit->record('admin', $scope->adminId, $merId, self::OFF_SHELF, 'product', (string)$id,
                'success', ['changed_fields'=>$fields]);
            return self::stateDto($after);
        });
    }

    private function reviewStamp(PlatformScope $scope, int $status, string $reason, array $before): array
    {
        if ($scope->adminId <= 0 || $scope->adminId > 65535) self::invalid();
        $now = time();
        if ($now <= 0 || $now > 4294967295) throw new AuthUnavailableException();
        $proposed = ['review_status'=>$status, 'review_reason'=>$reason, 'review_admin_id'=>$scope->adminId,
            'review_time'=>$now, 'is_show'=>0];
        $changes = [];
        foreach ($proposed as $key=>$value) if ($before[$key] !== $value) $changes[$key] = $value;
        return $changes;
    }

    private function authorize(PlatformScope $scope, string $permission): void
    {
        $this->access->authorizeScope($scope, $permission);
        if ($scope->targetMerId !== null) throw new AuthUnavailableException();
    }

    private function transaction(PlatformScope $scope, string $permission, callable $operation): array
    {
        $controlled = null;
        try {
            return AccountChangeGuard::runMany('adminapi', [], function () use ($scope, $permission, $operation, &$controlled): array {
                try {
                    $this->authorize($scope, $permission);
                    return MerchantManagementInput::write($operation);
                } catch (HttpException $error) {
                    $status = $error->getStatusCode();
                    if (!in_array($status, [401,403,404,422], true)) throw $error;
                    $controlled = $status;
                    throw new ValidateException('平台商品请求未完成');
                }
            });
        } catch (ValidateException $error) {
            if ($controlled !== null) {
                $message = match ($controlled) { 401=>'请登录', 403=>'没有权限访问', 404=>'资源不存在', 422=>'商品请求参数或状态不正确' };
                throw new HttpException($controlled, $message);
            }
            throw new AuthUnavailableException();
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private static function dto(array $row): array
    {
        $out = [];
        foreach (PlatformProductDao::FIELDS as $key) {
            if (!array_key_exists($key, $row)) throw new AuthUnavailableException();
            $out[$key] = $row[$key];
        }
        foreach (['id'=>[1,8388607], 'stock'=>[0,16777215], 'is_show'=>[0,1], 'is_del'=>[0,1],
            'review_status'=>[0,3], 'add_time'=>[0,4294967295]] as $key=>[$min,$max]) $out[$key] = self::storedInteger($row[$key], $min, $max);
        $out['mer_id'] = $row['mer_id'] === null ? null : self::storedInteger($row['mer_id'], 0, 4294967295);
        $out['review_admin_id'] = $row['review_admin_id'] === null ? null : self::storedInteger($row['review_admin_id'], 0, 65535);
        $out['review_time'] = $row['review_time'] === null ? null : self::storedInteger($row['review_time'], 0, 4294967295);
        foreach (['store_name','image','review_reason'] as $key) if (!is_string($row[$key]) || preg_match('//u', $row[$key]) !== 1
            || str_contains($row[$key], "\0")) throw new AuthUnavailableException();
        if (!is_string($row['price']) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $row['price']) !== 1) throw new AuthUnavailableException();
        if (!is_string($row['cate_id']) || strlen($row['cate_id']) > 64) throw new AuthUnavailableException();
        $out['cate_id'] = [];
        if ($row['cate_id'] !== '') foreach (explode(',', $row['cate_id']) as $category) $out['cate_id'][] = self::storedInteger($category, 1, 2147483647);
        return $out;
    }

    private static function stateDto(array $row): array
    {
        return ['id'=>self::storedInteger($row['id'], 1, 8388607), 'mer_id'=>self::storedInteger($row['mer_id'], 1, 4294967295),
            'review_status'=>self::storedInteger($row['review_status'], 0, 3), 'is_show'=>self::storedInteger($row['is_show'], 0, 1)];
    }

    private static function storedInteger(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && strlen($value) <= 10 && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < $min || $value > $max) throw new AuthUnavailableException();
        return $value;
    }

    private static function invalid(): never { throw new HttpException(422, '商品请求参数或状态不正确'); }
}
