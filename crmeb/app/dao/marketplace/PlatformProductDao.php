<?php
declare(strict_types=1);
namespace app\dao\marketplace;

use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\Query;
use think\exception\HttpException;
use think\facade\Db;

/** Fixed raw product reads and bounded status writes under the actual default transaction. */
final class PlatformProductDao
{
    public const FIELDS = ['id','mer_id','store_name','image','price','stock','cate_id','is_show','is_del',
        'review_status','review_reason','review_admin_id','review_time','add_time'];
    private const STATUS_FIELDS = ['review_status','review_reason','review_admin_id','review_time','is_show'];

    private static function transaction(Query $query): void
    {
        $connection = $query->getConnection();
        $pdo = $connection->connect();
        if ($connection !== Db::connect() || !$pdo instanceof PDO || !$pdo->inTransaction()
            || Db::connect()->getPdo() !== $pdo) throw new AuthUnavailableException();
    }

    private function root(PlatformScope $scope): Query
    {
        if ($scope->targetMerId !== null) throw new AuthUnavailableException();
        $query = $scope->apply(Db::name('store_product'));
        self::transaction($query);
        return $query;
    }

    public function listing(PlatformScope $scope, int $page, int $limit, int $review, ?int $merId): array
    {
        $query = $this->root($scope)->where('is_del', 0)->where('review_status', $review);
        if ($merId !== null) $query->where('mer_id', $merId);
        $count = (clone $query)->count();
        $list = (clone $query)->field(self::FIELDS)->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['list'=>$list, 'count'=>$count];
    }

    /** Resolve the current row's owner, lock that merchant, then lock that exact owned product. */
    public function lockProduct(PlatformScope $scope, int $id): array
    {
        $initial = $this->root($scope)->where('id', $id)->field(['id','mer_id'])->find();
        if ($initial === null) throw new HttpException(404, '资源不存在');
        $owner = $initial['mer_id'];
        if ((!is_int($owner) && (!is_string($owner) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $owner) !== 1))
            || (int)$owner <= 0 || (int)$owner > 4294967295) throw new HttpException(422, '商品归属暂无法确认');
        $merId = (int)$owner;
        $merchant = Db::name('marketplace_merchant')->where('mer_id', $merId)->field(['mer_id'])->lock(true);
        self::transaction($merchant);
        $merchantRow = $merchant->find();
        if ($merchantRow === null || (string)$merchantRow['mer_id'] !== (string)$merId) {
            throw new HttpException(422, '商品归属暂无法确认');
        }
        $row = $this->root($scope)->where('id', $id)->where('mer_id', $merId)->field(self::FIELDS)->lock(true)->find();
        if ($row === null) throw new HttpException(404, '资源不存在');
        if ((string)$row['id'] !== (string)$id || (string)$row['mer_id'] !== (string)$merId) throw new AuthUnavailableException();
        return $row;
    }

    public function categories(PlatformScope $scope, int $id, int $merId): array
    {
        $this->owned($scope, $id, $merId);
        $query = Db::name('store_product_cate')->where('product_id', $id)->order('id')->lock(true);
        self::transaction($query);
        return $query->select()->toArray();
    }

    public function updateStatus(PlatformScope $scope, int $id, int $merId, array $before, array $changes): array
    {
        if (array_diff(array_keys($changes), self::STATUS_FIELDS) !== []) throw new AuthUnavailableException();
        $query = $this->owned($scope, $id, $merId);
        if ($changes !== []) {
            $affected = (clone $query)->update($changes);
            if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
        }
        $after = (clone $query)->field(self::FIELDS)->lock(true)->find();
        if ($after === null) throw new AuthUnavailableException();
        $expected = array_replace($before, $changes);
        if (array_keys($after) !== array_keys($before)) throw new AuthUnavailableException();
        foreach ($expected as $key=>$value) {
            if (($value === null) !== ($after[$key] === null)
                || ($value !== null && (string)$after[$key] !== (string)$value)) throw new AuthUnavailableException();
        }
        return $after;
    }

    public function hideCategories(PlatformScope $scope, int $id, int $merId, array $before): bool
    {
        $this->owned($scope, $id, $merId);
        $changed = false;
        foreach ($before as $row) {
            if ((string)$row['product_id'] !== (string)$id) throw new AuthUnavailableException();
            if ((string)$row['status'] !== '0') $changed = true;
        }
        if ($changed) {
            $query = Db::name('store_product_cate')->where('product_id', $id)->where('status', '<>', 0);
            self::transaction($query);
            $affected = $query->update(['status'=>0]);
            $count = count(array_filter($before, static fn(array $row): bool => (string)$row['status'] !== '0'));
            if ($affected !== $count) throw new AuthUnavailableException();
        }
        $after = $this->categories($scope, $id, $merId);
        if (count($before) !== count($after)) throw new AuthUnavailableException();
        foreach ($before as $index=>$row) {
            $expected = array_replace($row, ['status'=>0]);
            if (array_keys($expected) !== array_keys($after[$index])) throw new AuthUnavailableException();
            foreach ($expected as $key=>$value) if (($value === null) !== ($after[$index][$key] === null)
                || ($value !== null && (string)$after[$index][$key] !== (string)$value)) throw new AuthUnavailableException();
        }
        return $changed;
    }

    private function owned(PlatformScope $scope, int $id, int $merId): Query
    {
        $query = $this->root($scope)->where('id', $id)->where('mer_id', $merId);
        $row = (clone $query)->field(['id','mer_id'])->lock(true)->find();
        if ($row === null || (string)$row['id'] !== (string)$id || (string)$row['mer_id'] !== (string)$merId) {
            throw new HttpException(404, '资源不存在');
        }
        return $query;
    }
}
