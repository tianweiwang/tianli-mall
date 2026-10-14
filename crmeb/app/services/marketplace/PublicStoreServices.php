<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\dao\marketplace\PublicStoreDao;
use app\dao\marketplace\scope\PublicCatalogScope;
use app\services\marketplace\publiccatalog\PublicCatalogQuery;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;

/** Read-only public identity, using an actual qualified merchant row. */
final class PublicStoreServices
{
    public function __construct(private PublicStoreDao $stores) {}

    public function read(mixed $id):array
    {
        $merId=PublicCatalogQuery::positive($id);
        $row=$this->stores->read($merId);
        if($row===null)throw new ApiException('店铺暂不可访问',[],404);
        $out=[];
        foreach(PublicCatalogScope::fields('merchant') as $field){
            if(!array_key_exists($field,$row))throw new AuthUnavailableException();
            $out[$field]=$row[$field];
        }
        $out['mer_id']=PublicCatalogQuery::positive($out['mer_id']);
        if($out['mer_id']!==$merId)throw new AuthUnavailableException();
        foreach(['name','logo','after_sale_contact','shipping_note'] as $field){
            if(!is_string($out[$field])||preg_match('//u',$out[$field])!==1||str_contains($out[$field],"\0"))throw new AuthUnavailableException();
        }
        return $out;
    }
}
