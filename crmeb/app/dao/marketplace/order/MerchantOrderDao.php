<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\dao\marketplace\scope\MerchantScope;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\order\MerchantOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use think\db\Query;
use think\facade\Db;

/** Saved roots and their saved items only; no current catalog or legacy order formatter. */
final class MerchantOrderDao
{
    private const HEADER = ['id','uid','mer_id','pid','is_system_del','order_id','marketplace_trade_version',
        'add_time','paid','status','is_cancel','refund_status','shipping_type','total_num','total_price','pay_price','pay_postage'];
    private const DETAIL = ['real_name','user_phone','user_address','mark','custom_form'];
    private const ITEM = ['id','oid','uid','mer_id','product_id','cart_num','cart_info'];

    private function orders(MerchantContext $ctx,array $filter,?int $id=null): Query
    {
        $query=Db::name('store_order')->where('marketplace_trade_version',1)->where('is_system_del',0)->where('pid',0);
        foreach($filter as $key=>$value){
            if(!in_array($key,['order_id','paid','is_cancel'],true))throw new AuthUnavailableException();
            $query->where($key,'=',$value);
        }
        if($id!==null)$query->where('id','=',MerchantOrderInput::id($id));
        // Complete all business predicates before the mandatory merchant scope.
        return MerchantScope::apply($query,$ctx);
    }

    public function listing(MerchantContext $ctx,array $filter,int $page,int $limit): array
    {
        MerchantOrderInput::integer($page,1,2147483647);MerchantOrderInput::integer($limit,1,100);
        $query=$this->orders($ctx,$filter);$count=(clone $query)->count();
        $rows=$query->field(self::HEADER)->order('id','desc')->page($page,$limit)->select()->toArray();
        return ['list'=>$this->withItems($ctx,$rows),'count'=>$count];
    }

    public function find(MerchantContext $ctx,int $id): array
    {
        $row=$this->orders($ctx,[],$id)->field(array_merge(self::HEADER,self::DETAIL))->find();
        MerchantScope::assertOwned($row,$ctx);
        return $this->withItems($ctx,[$row])[0];
    }

    /** One item query for the verified current page; oid and owner are checked again per row. */
    private function withItems(MerchantContext $ctx,array $orders): array
    {
        if(!$orders)return [];$index=[];
        foreach($orders as $offset=>$order){
            MerchantScope::assertOwned($order,$ctx);$id=self::identity($order['id']??null);
            if(isset($index[$id]))throw new AuthUnavailableException();
            $index[$id]=$offset;$orders[$offset]['items']=[];
        }
        $query=Db::name('store_order_cart_info')->whereIn('oid',array_keys($index));
        $query=MerchantScope::apply($query,$ctx);$seen=[];
        foreach($query->field(self::ITEM)->order('id','asc')->select()->toArray() as $item){
            MerchantScope::assertOwned($item,$ctx);$oid=self::identity($item['oid']??null);$id=self::identity($item['id']??null);
            if(!isset($index[$oid])||isset($seen[$id]))throw new AuthUnavailableException();
            $offset=$index[$oid];
            if(self::identity($item['uid']??null)!==self::identity($orders[$offset]['uid']??null))throw new AuthUnavailableException();
            $seen[$id]=true;$orders[$offset]['items'][]=$item;
        }
        return $orders;
    }

    private static function identity(mixed $value): int
    {
        if((!is_int($value)&&!is_string($value))||preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value)!==1
            ||(int)$value>2147483647)throw new AuthUnavailableException();
        return (int)$value;
    }
}
