<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\dao\marketplace\scope\PublicCatalogScope;
use app\model\marketplace\Merchant;
use app\services\marketplace\context\MerchantContext;
use think\Model;

/** No inherited unscoped business API is exposed. */
final class MerchantDao
{
    public function findForContext(MerchantContext $ctx): ?array
    {
        $query = MerchantScope::apply((new Merchant())->db(), $ctx);
        $row = $query->field(PublicCatalogScope::fields('merchant'))->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        return is_array($data) && $data !== [] ? $data : null;
    }
}
