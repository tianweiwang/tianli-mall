<?php
declare(strict_types=1);
namespace app\services\marketplace\publiccatalog;

use crmeb\exceptions\AuthUnavailableException;
use think\db\Query;
use think\facade\Config;
use think\facade\Db;

/** Public read filters only. Existing admin/order raw queries are not global scopes. */
final class PublicCatalogQuery
{
    private const OWNED_ROOTS=['store_product','system_attachment','system_attachment_category','shipping_templates','store_product_rule'];
    public static function positive(mixed $value,int $max=4294967295):int
    {
        if(is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<1||$value>$max)throw new AuthUnavailableException();
        return $value;
    }
    private static function configuration():array
    {
        $enabled=Config::get('marketplace.enabled');
        if(!is_bool($enabled))throw new AuthUnavailableException();
        $self=self::positive(Config::get('marketplace.self_operated_mer_id'));
        // Configured business self is an explicit deployment input, never a row-ID guess.
        if(!Db::name('marketplace_merchant')->where('mer_id',$self)->value('mer_id'))throw new AuthUnavailableException();
        if($enabled){
            $merchantTable=Db::name('marketplace_merchant')->getTable();
            foreach(self::OWNED_ROOTS as $table){
                // NULL owners are counted explicitly; a NULL value lookup is ambiguous.
                if(Db::name($table)->where(static function(Query $query):void{$query->whereNull('mer_id')->whereOr('mer_id','<=',0);})->count()>0)throw new AuthUnavailableException();
                $orphan=Db::name($table)->alias('ownership_root')->where('ownership_root.mer_id','>',0)
                    ->whereNotExists(static function(Query $query)use($merchantTable):void{$query->table($merchantTable)->alias('ownership_merchant')->field('mer_id')->whereColumn('ownership_merchant.mer_id','ownership_root.mer_id');})->value('ownership_root.mer_id');
                if($orphan!==null)throw new AuthUnavailableException();
            }
        }
        return ['enabled'=>$enabled,'self'=>$self];
    }
    private static function alias(string $alias):string
    {
        if(preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D',$alias)!==1)throw new AuthUnavailableException();
        return $alias;
    }
    private static function product(Query $query,string $alias,array $configuration):void
    {
        $query->where($alias.'.mer_id','>',0)->where($alias.'.review_status',2)->where($alias.'.is_show',1)->where($alias.'.is_del',0);
        if(!$configuration['enabled'])$query->where($alias.'.mer_id',$configuration['self']);
    }
    private static function merchant(Query $query,string $alias,bool $purchase=false):void
    {
        $query->where($alias.'.review_status',1)->where($alias.'.security_locked',0)->whereNull($alias.'.closed_at');
        if($purchase)$query->where($alias.'.accept_orders',1);
    }
    /** An undeleted native activity reserves the ordinary channel regardless of status/time. */
    private static function ordinary(Query $query,string $rootAlias):void
    {
        $nativeTable=Db::name('store_advance')->getTable();
        $query->whereNotExists(static function(Query $native)use($nativeTable,$rootAlias):void{
            $native->table($nativeTable)->alias('ordinary_native_advance')->field('id')
                ->whereColumn('ordinary_native_advance.product_id',$rootAlias.'.id')->where('ordinary_native_advance.is_del',0);
        });
    }
    /** Ordinary default: qualify before count/find/page/limit; do not append outer OR afterward. */
    public function constrain(Query $query,string $rootAlias,?int $merchantFilter=null):Query
    {
        try{
            $query=$this->constrainPublishedRoot($query,$rootAlias,$merchantFilter);
            self::ordinary($query,self::alias($rootAlias));return $query;
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    /** Strict publication only; actual native lineage/status/deletion remains the caller's job. */
    public function constrainPublishedRoot(Query $query,string $rootAlias,?int $merchantFilter=null):Query
    {
        try{
            $rootAlias=self::alias($rootAlias);$where=$query->getOptions('where');if(is_array($where)&&!empty($where['OR']))throw new AuthUnavailableException();$configuration=self::configuration();self::product($query,$rootAlias,$configuration);
            if($merchantFilter!==null)$query->where($rootAlias.'.mer_id',self::positive($merchantFilter));
            $merchantTable=Db::name('marketplace_merchant')->getTable();
            $query->whereExists(static function(Query $subquery)use($merchantTable,$rootAlias):void{
                $subquery->table($merchantTable)->alias('catalog_merchant')->field('mer_id')->whereColumn('catalog_merchant.mer_id',$rootAlias.'.mer_id');self::merchant($subquery,'catalog_merchant');
            });
            return $query;
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    /** Public shop read uses the same actual configuration and merchant publication policy. */
    public function constrainStore(Query $query,string $storeAlias):Query
    {
        try{
            $storeAlias=self::alias($storeAlias);$where=$query->getOptions('where');
            if(is_array($where)&&!empty($where['OR']))throw new AuthUnavailableException();
            $configuration=self::configuration();self::merchant($query,$storeAlias);
            if(!$configuration['enabled'])$query->where($storeAlias.'.mer_id',$configuration['self']);
            return $query;
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    /** Ordinary fresh root: includes the native reservation exclusion before find. */
    public function detail(int $productId):?array
    {
        return $this->readRoot($productId,true);
    }
    /** Native parent fresh root: strict P(base), without the ordinary-channel exclusion. */
    public function publishedRoot(int $productId):?array
    {
        return $this->readRoot($productId,false);
    }
    /** Current new-sale base proof; native lineage is validated by its caller. */
    public function purchasableRoot(int $productId):?array
    {
        return $this->readRoot($productId,false,true);
    }
    /** Shared original root/store join; channel and purchase mode are server entrypoints. */
    private function readRoot(int $productId,bool $ordinary,bool $purchase=false):?array
    {
        try{
            $productId=self::positive($productId,8388607);$configuration=self::configuration();$merchantTable=Db::name('marketplace_merchant')->getTable();
            $query=Db::name('store_product')->alias('catalog_product')->join([$merchantTable=>'catalog_store'],'catalog_store.mer_id=catalog_product.mer_id')
                ->field('catalog_product.*')->field(['catalog_store.mer_id'=>'_public_store_mer_id','catalog_store.name'=>'_public_store_name','catalog_store.logo'=>'_public_store_logo','catalog_store.after_sale_contact'=>'_public_store_after_sale_contact','catalog_store.shipping_note'=>'_public_store_shipping_note'])->where('catalog_product.id',$productId);
            self::product($query,'catalog_product',$configuration);self::merchant($query,'catalog_store',$purchase);if($ordinary)self::ordinary($query,'catalog_product');$row=$query->find();
            if($row!==null&&!is_array($row))throw new AuthUnavailableException();return $row;
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
}
