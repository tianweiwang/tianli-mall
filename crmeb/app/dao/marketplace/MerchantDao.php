<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\dao\marketplace\scope\PublicCatalogScope;
use app\model\marketplace\Merchant;
use app\services\marketplace\context\MerchantContext;
use think\Model;
use think\db\Query;
use PDO;
use LogicException;
use InvalidArgumentException;

/** No inherited unscoped business API is exposed. */
final class MerchantDao
{
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
