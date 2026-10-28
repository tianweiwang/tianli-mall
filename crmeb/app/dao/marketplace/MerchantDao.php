<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\dao\marketplace\scope\PlatformScope;
use app\dao\marketplace\scope\PublicCatalogScope;
use app\model\marketplace\Merchant;
use app\services\marketplace\context\MerchantContext;
use think\Model;
use think\db\Query;
use PDO;
use LogicException;
use InvalidArgumentException;
use UnexpectedValueException;
use think\facade\Db;

/** No inherited unscoped business API is exposed. */
final class MerchantDao
{
    private const PLATFORM_FIELDS = ['mer_id', 'name', 'logo', 'after_sale_contact', 'shipping_note',
        'review_status', 'accept_orders', 'fulfillment_enabled', 'security_locked', 'closed_at', 'add_time', 'update_time'];

    public function listForPlatform(PlatformScope $scope, int $page, int $limit, ?int $reviewStatus = null): array
    {
        if ($scope->targetMerId !== null || $scope->permission !== 'marketplace-merchants-list'
            || $page <= 0 || $page > 4294967295 || $limit <= 0 || $limit > 100
            || ($reviewStatus !== null && !in_array($reviewStatus, [0, 1, 2], true))) {
            throw new InvalidArgumentException('Invalid platform merchant listing');
        }
        $query = (new Merchant())->db();
        if ($reviewStatus !== null) {
            $query->where('review_status', '=', $reviewStatus);
        }
        $query = $scope->apply($query);
        $count = (clone $query)->count();
        $rows = $query->field(self::PLATFORM_FIELDS)->order('mer_id', 'asc')->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $data = self::platformData($row);
            if ($data === null) {
                throw new UnexpectedValueException('Incomplete merchant storage');
            }
            $list[] = $data;
        }
        return ['list' => $list, 'count' => $count];
    }

    public function findForPlatform(PlatformScope $scope, bool $lock = false): ?array
    {
        self::assertTarget($scope);
        $query = $scope->apply((new Merchant())->db())->field(self::PLATFORM_FIELDS);
        if ($lock) {
            self::assertPlatformTransaction($query);
            $query->lock(true);
        }
        return self::platformData($query->find());
    }

    public function createForPlatform(PlatformScope $scope, array $fields, int $now): int
    {
        self::assertGlobalCreate($scope);
        self::assertPlatformTime($now);
        if (array_keys($fields) !== ['name', 'logo', 'after_sale_contact', 'shipping_note']
            || !is_string($fields['name']) || $fields['name'] === ''
            || !is_string($fields['logo']) || !is_string($fields['after_sale_contact'])
            || !is_string($fields['shipping_note'])) {
            throw new InvalidArgumentException('Invalid merchant creation fields');
        }
        $query = (new Merchant())->db();
        self::assertPlatformTransaction($query);
        $id = $query->insertGetId($fields + [
            'review_status' => 0, 'accept_orders' => 0, 'fulfillment_enabled' => 1,
            'security_locked' => 0, 'closed_at' => null, 'add_time' => $now, 'update_time' => $now,
        ]);
        if ((!is_int($id) && (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1))
            || (int)$id <= 0 || (int)$id > 4294967295) {
            throw new UnexpectedValueException('Invalid created merchant identity');
        }
        return (int)$id;
    }

    /** Only the global create caller's real insert ID is used for this new-row lock. */
    public function lockCreatedForPlatform(PlatformScope $scope, int $merId): ?array
    {
        self::assertGlobalCreate($scope);
        if ($merId <= 0 || $merId > 4294967295) {
            throw new InvalidArgumentException('Invalid created merchant identity');
        }
        $query = $scope->apply((new Merchant())->db()->where('mer_id', '=', $merId));
        self::assertPlatformTransaction($query);
        return self::platformData($query->field(self::PLATFORM_FIELDS)->lock(true)->find());
    }

    public function updateForPlatform(PlatformScope $scope, array $changes, int $now): int
    {
        self::assertTarget($scope);
        self::assertPlatformTime($now);
        if (!in_array($scope->permission, ['marketplace-merchants-update', 'marketplace-merchants-review',
            'marketplace-merchants-flags'], true)) {
            throw new InvalidArgumentException('Invalid platform merchant mutation');
        }
        $allowed = match ($scope->permission) {
            'marketplace-merchants-update' => ['name', 'logo', 'after_sale_contact', 'shipping_note'],
            'marketplace-merchants-review' => ['review_status'],
            'marketplace-merchants-flags' => ['accept_orders', 'fulfillment_enabled', 'security_locked'],
        };
        foreach ($changes as $field => $value) {
            if (!in_array($field, $allowed, true)
                || (in_array($field, ['name', 'logo', 'after_sale_contact', 'shipping_note'], true)
                    ? !is_string($value)
                    : (!is_int($value) || !in_array($value, $field === 'review_status' ? [0, 1, 2] : [0, 1], true)))) {
                throw new InvalidArgumentException('Invalid platform merchant field');
            }
        }
        $query = $scope->apply((new Merchant())->db());
        self::assertPlatformTransaction($query);
        if ($changes === []) {
            return 0;
        }
        $changes['update_time'] = $now;
        return $query->update($changes);
    }

    /** A terminal transition only; neither the request nor generic flags can supply closed_at. */
    public function closeForPlatform(PlatformScope $scope,int $now): int
    {
        self::assertTarget($scope);self::assertPlatformTime($now);
        if($scope->permission!=='marketplace-merchants-close')throw new InvalidArgumentException('Invalid merchant close scope');
        $query=$scope->apply((new Merchant())->db())->whereNull('closed_at');
        self::assertPlatformTransaction($query);
        return $query->update(['closed_at'=>$now,'accept_orders'=>0,'update_time'=>$now]);
    }

    private static function assertGlobalCreate(PlatformScope $scope): void
    {
        if ($scope->targetMerId !== null || $scope->permission !== 'marketplace-merchants-create') {
            throw new InvalidArgumentException('Invalid global merchant creation scope');
        }
    }

    private static function assertTarget(PlatformScope $scope): void
    {
        if ($scope->targetMerId === null || $scope->targetMerId > 4294967295) {
            throw new InvalidArgumentException('Platform merchant target is required');
        }
    }

    private static function assertPlatformTime(int $now): void
    {
        if ($now <= 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Invalid merchant timestamp');
        }
    }

    private static function assertPlatformTransaction(Query $query): void
    {
        $connection = $query->getConnection();
        $pdo = $connection->connect();
        if ($connection !== Db::connect() || !$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Platform merchant write requires the default transaction');
        }
    }

    private static function platformData(array|Model|null $row): ?array
    {
        $data = $row instanceof Model ? $row->getData() : $row;
        if ($data === null || $data === []) {
            return null;
        }
        $public = [];
        foreach (self::PLATFORM_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new UnexpectedValueException('Incomplete merchant storage');
            }
            $public[$field] = $data[$field];
        }
        foreach (['mer_id', 'review_status', 'accept_orders', 'fulfillment_enabled', 'security_locked',
            'add_time', 'update_time'] as $field) {
            if (!is_int($public[$field]) && (!is_string($public[$field])
                || preg_match('/\A(0|[1-9][0-9]*)\z/', $public[$field]) !== 1)) {
                throw new UnexpectedValueException('Invalid merchant numeric storage');
            }
            $public[$field] = (int)$public[$field];
        }
        if ($public['mer_id'] <= 0) {
            throw new UnexpectedValueException('Invalid merchant identity storage');
        }
        foreach (['name', 'logo', 'after_sale_contact', 'shipping_note'] as $field) {
            if (!is_string($public[$field])) {
                throw new UnexpectedValueException('Invalid merchant text storage');
            }
        }
        if ($public['closed_at'] !== null) {
            if (!is_int($public['closed_at']) && (!is_string($public['closed_at'])
                || preg_match('/\A(0|[1-9][0-9]*)\z/', $public['closed_at']) !== 1)) {
                throw new UnexpectedValueException('Invalid merchant closure storage');
            }
            $public['closed_at'] = (int)$public['closed_at'];
        }
        return $public;
    }

    /** All management writers use this row to coordinate same-merchant membership. */
    public function lockForManagement(MerchantContext $ctx): bool
    {
        $query = MerchantScope::apply((new Merchant())->db(), $ctx);
        return $this->lockRow($query);
    }

    /** The caller obtains this identity only from a persisted valid setup capability. */
    public function lockForPasswordSetup(int $merId): bool
    {
        if ($merId <= 0) throw new InvalidArgumentException('Invalid setup merchant');
        return $this->lockRow((new Merchant())->db()->where('mer_id', '=', $merId));
    }

    private function lockRow(Query $query): bool
    {
        $pdo = $query->getConnection()->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Merchant mutation requires an active transaction');
        }
        $row = $query->field(['mer_id'])->lock(true)->find();
        return $row instanceof Model || (is_array($row) && $row !== []);
    }

    public function findForContext(MerchantContext $ctx): ?array
    {
        $query = MerchantScope::apply((new Merchant())->db(), $ctx);
        $row = $query->field(PublicCatalogScope::fields('merchant'))->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        return is_array($data) && $data !== [] ? $data : null;
    }
}
