<?php
declare(strict_types=1);
namespace app\dao\marketplace;

use app\dao\marketplace\scope\PublicCatalogScope;
use app\services\marketplace\publiccatalog\PublicCatalogQuery;
use crmeb\exceptions\AuthUnavailableException;
use think\facade\Db;

/** Only the fixed public shop columns; no merchant employee or registration read. */
final class PublicStoreDao
{
    public function __construct(private PublicCatalogQuery $catalog) {}

    public function read(int $merId):?array
    {
        PublicCatalogQuery::positive($merId);
        $query=Db::name('marketplace_merchant')->alias('public_store')
            ->field(PublicCatalogScope::fields('merchant'))->where('public_store.mer_id',$merId);
        $row=$this->catalog->constrainStore($query,'public_store')->find();
        if($row!==null&&!is_array($row))throw new AuthUnavailableException();
        return $row;
    }
}
