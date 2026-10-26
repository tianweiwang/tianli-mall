<?php
declare(strict_types=1);
namespace app\dao\marketplace\product;

use app\dao\marketplace\scope\MerchantScope;
use app\model\product\product\StoreProduct;
use app\model\product\sku\StoreProductAttrValue;
use app\model\product\sku\StoreProductRule;
use app\model\shipping\ShippingTemplates;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\product\MerchantProductInput as Input;
use app\services\marketplace\product\ProductMediaReferenceServices;
use app\services\marketplace\category\MerchantCategoryPolicy;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use PDO;
use think\db\Query;
use think\facade\Db;
use think\Model;

/** Every typed child operation verifies and locks its owned parent first. */
final class MerchantProductDao
{
    public const ROOT_FIELDS = ['id','mer_id','store_name','store_info','keyword','unit_name','image','slider_image','video_link','price','cost','ot_price','vip_price','stock','spec_type','freight','postage','temp_id','cate_id','default_sku','custom_form','is_show','is_del','is_virtual','virtual_type','review_status','review_reason','review_time'];
    private const WRITE_FIELDS = ['store_name','store_info','keyword','unit_name','image','slider_image','video_link','price','cost','ot_price','vip_price','stock','spec_type','freight','postage','temp_id','cate_id','default_sku','custom_form','is_show','is_del','review_status','review_reason','review_admin_id','review_time'];
    public static function raw(array|Model|null $row): ?array { return $row instanceof Model ? $row->getData() : ($row ?: null); }
    private static function transaction(Query $query): void
    {
        $pdo = $query->getConnection()->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction() || Db::connect()->getPdo() !== $pdo) throw new AuthUnavailableException();
    }
    private function rootQuery(MerchantContext $ctx, int $id): Query
    {
        Input::integer($id,1,Input::ROOT_MAX); return MerchantScope::apply((new StoreProduct())->db()->where('id','=',$id),$ctx);
    }
    public function find(MerchantContext $ctx, int $id, bool $lock = false): array
    {
        $q = $this->rootQuery($ctx,$id)->field(self::ROOT_FIELDS);
        if ($lock) { self::transaction($q); $q->lock(true); }
        $row = self::raw($q->find()); MerchantScope::assertOwned($row,$ctx);
        if ((int)$row['is_virtual'] !== 0 || (int)$row['virtual_type'] !== 0) Input::invalid();
        return $row;
    }
    public function listing(MerchantContext $ctx, array $filter, int $page, int $limit): array
    {
        $q = (new StoreProduct())->db()->where('is_virtual',0)->where('virtual_type',0)->where('is_del',$filter['is_del']);
        if (isset($filter['keyword']) && $filter['keyword'] !== '') $q->whereLike('store_name','%'.$filter['keyword'].'%');
        if (isset($filter['review_status'])) $q->where('review_status',$filter['review_status']);
        $q = MerchantScope::apply($q,$ctx); $count=(clone $q)->count(); $list=[];
        foreach ($q->field(self::ROOT_FIELDS)->order('id','desc')->page($page,$limit)->select() as $row) $list[]=self::raw($row);
        return ['list'=>$list,'count'=>$count];
    }
    public function create(MerchantContext $ctx, array $root): int
    {
        $q=(new StoreProduct())->db(); self::transaction($q); foreach(array_keys($root) as $key) if(!in_array($key,self::WRITE_FIELDS,true)) throw new AuthUnavailableException();
        $row=$root+['mer_id'=>$ctx->merId,'is_virtual'=>0,'virtual_type'=>0,'custom_form'=>'[]','add_time'=>time(),'spu'=>Input::spu(),'review_status'=>0,'review_reason'=>'','review_admin_id'=>null,'review_time'=>null,'is_show'=>0,'is_del'=>0,'stock'=>0];
        $id=$q->insertGetId($row); $id=Input::integer($id,1,Input::ROOT_MAX,true); $this->find($ctx,$id,true); return $id;
    }
    public function updateRoot(MerchantContext $ctx, int $id, array $data): void
    {
        $this->find($ctx,$id,true); foreach(array_keys($data) as $key) if(!in_array($key,self::WRITE_FIELDS,true)) throw new AuthUnavailableException();
        if($data===[]) return; $q=$this->rootQuery($ctx,$id); self::transaction($q); $n=$q->update($data);
        if(!is_int($n) || $n<0 || $n>1) throw new AuthUnavailableException();
        $after=self::raw($this->rootQuery($ctx,$id)->find()); MerchantScope::assertOwned($after,$ctx);
        foreach($data as $key=>$value) if(($after[$key]??null)!==$value && (string)($after[$key]??'')!==(string)($value??'')) throw new AuthUnavailableException();
    }
    public function skus(MerchantContext $ctx,int $id,bool $lock=false): array
    {
        $this->find($ctx,$id,$lock); $q=(new StoreProductAttrValue())->db()->where('product_id',$id)->where('type',0)->order('id','asc');
        if($lock){self::transaction($q);$q->lock(true);} $rows=[];foreach($q->select() as $row)$rows[]=self::raw($row);return $rows;
    }
    public function physicalStock(array $rows): int
    {
        $sum=0;foreach($rows as $row){$stock=Input::integer($row['stock'],0,4294967295,true);$sum+=$stock;if($sum>Input::STOCK_MAX)throw new AuthUnavailableException();}return $sum;
    }
    public function currentResult(MerchantContext $ctx,int $id,bool $lock=false): array
    {
        $this->find($ctx,$id,$lock);$q=Db::name('store_product_attr_result')->where('product_id',$id)->where('type',0)->order('id','asc');if($lock){self::transaction($q);$q->lock(true);}
        $rows=$q->select()->toArray();if(count($rows)!==1)throw new AuthUnavailableException();$value=json_decode($rows[0]['result'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!is_array($value['attr']??null)||!is_array($value['value']??null))throw new AuthUnavailableException();return $value;
    }
    public function description(MerchantContext $ctx,int $id,bool $lock=false): string
    {
        $this->find($ctx,$id,$lock);$q=Db::name('store_product_description')->where('product_id',$id)->where('type',0);if($lock){self::transaction($q);$q->lock(true);} $rows=$q->select()->toArray();
        if(count($rows)>1)throw new AuthUnavailableException();return $rows?htmlspecialchars_decode($rows[0]['description']):'';
    }
    /** Complete actual bindings in the caller's product/category write transaction. */
    public function currentCategoryBindings(MerchantContext $ctx,int $id): array
    {
        $this->find($ctx,$id,true);
        $query=Db::name('store_product_cate')->where('product_id',$id)->field(['cate_id','cate_pid'])->order('cate_id');
        self::transaction($query);$rows=$query->lock(true)->select()->toArray();
        foreach($rows as &$row){
            $row['cate_id']=Input::integer($row['cate_id'],1,2147483647,true);
            // Platform root edits can persist 0; observe it as a difference, never a new binding.
            $row['cate_pid']=Input::integer($row['cate_pid'],0,2147483647,true);
        }unset($row);
        return $rows;
    }
    public function references(MerchantContext $ctx,int $productIdOrZero,array $input,array $definitions): array
    {
        // The GET limit also bounds this write preflight; current locks follow separately.
        $inventory=Db::name('store_category')->field('id')->order('id')->limit(MerchantCategoryPolicy::ROW_MAX+1);
        self::transaction($inventory);if(count($inventory->select())>MerchantCategoryPolicy::ROW_MAX)throw new AuthUnavailableException();
        $fields=['id','pid','cate_name','sort','is_show'];
        $categoryQuery=Db::name('store_category')->whereIn('id',$input['categories'])->field($fields)->order('id');
        self::transaction($categoryQuery);$categories=$categoryQuery->lock(true)->select()->toArray();
        if(count($categories)!==count($input['categories']))Input::invalid();
        $parentIds=array_values(array_diff(MerchantCategoryPolicy::parentIds($categories),$input['categories']));
        if($parentIds){
            $parentQuery=Db::name('store_category')->whereIn('id',$parentIds)->field($fields)->order('id');
            self::transaction($parentQuery);$categories=array_merge($categories,$parentQuery->lock(true)->select()->toArray());
        }
        $eligible=MerchantCategoryPolicy::eligible($categories);$categoryParents=[];
        foreach($input['categories'] as $category){
            if(!isset($eligible[$category]))Input::invalid();$row=$eligible[$category];
            $categoryParents[$category]=$row['pid']===0?$category:$row['pid'];
        }
        $temp=$input['root']['temp_id'];if($input['root']['freight']===3){$q=MerchantScope::apply((new ShippingTemplates())->db()->where('id',$temp),$ctx);self::transaction($q);if(self::raw($q->lock(true)->find())===null)Input::invalid();}
        $media=app()->make(ProductMediaReferenceServices::class);
        $media->assertForMerchant($ctx,$productIdOrZero,$media->merchantReferences($input,$definitions));
        return $categoryParents;
    }
    public function preset(MerchantContext $ctx,int $id): ?array
    {
        if($id===0)return null;$q=MerchantScope::apply((new StoreProductRule())->db()->where('id',$id),$ctx);self::transaction($q);$row=self::raw($q->lock(true)->find());if($row===null)Input::invalid();MerchantScope::assertOwned($row,$ctx);$items=json_decode($row['rule_value'],true,32,JSON_THROW_ON_ERROR);if(!is_array($items)||!array_is_list($items))throw new AuthUnavailableException();return $items;
    }
    /** In-place SKU updates; removal retires the row, never destroys its identity. */
    public function saveSkus(MerchantContext $ctx,int $id,array $definitions,array $old): array
    {
        $this->find($ctx,$id,true);$used=[];$active=[];$uniques=[];foreach($old as$row)$uniques[$row['unique']]=true;
        foreach($definitions['skus']as$spec){$selector=$spec['selector'];$matches=[];
            foreach($old as$row){if($selector['id']!==null?(int)$row['id']===$selector['id']:($selector['unique']!==''?$row['unique']===$selector['unique']:$row['suk']===$spec['row']['suk']))$matches[]=$row;}
            if(count($matches)>1)throw new AuthUnavailableException();$row=$matches[0]??null;
            if($row===null&&($selector['id']!==null||$selector['unique']!==''))Input::invalid();
            if($row!==null){$skuId=(int)$row['id'];if(isset($used[$skuId])||($selector['unique']!==''&&$selector['unique']!==$row['unique']))Input::invalid();if($selector['stock']!==null&&$selector['stock']!==(int)$row['stock'])Input::invalid();$patch=$spec['row'];$this->updateSku($ctx,$id,$skuId,$patch);$unique=$row['unique'];$stock=(int)$row['stock'];}
            else{if($selector['stock']!==null&&$selector['stock']!==0)Input::invalid();$unique='';for($n=0;$n<16;$n++){$candidate=bin2hex(random_bytes(4));if(!isset($uniques[$candidate])){$unique=$candidate;break;}}if($unique==='')throw new AuthUnavailableException();$uniques[$unique]=true;$q=(new StoreProductAttrValue())->db();self::transaction($q);
                // Query inserts do not run the Model unique mutator; server supplies the validated CHAR(8) explicitly.
                $skuId=Input::integer($q->insertGetId($spec['row']+['product_id'=>$id,'type'=>0,'unique'=>$unique,'stock'=>0]),1,2147483647,true);$stock=0;}
            $used[$skuId]=true;$entry=$spec['row']+['id'=>$skuId,'product_id'=>$id,'type'=>0,'unique'=>$unique,'detail'=>$spec['detail'],'attr_arr'=>array_values($spec['detail'])];$entry['pic']=$entry['image'];unset($entry['image']);$active[]=$entry;
        }
        foreach($old as$row)if(!isset($used[(int)$row['id']]))$this->updateSku($ctx,$id,(int)$row['id'],['is_show'=>0,'is_default_select'=>0]);
        return ['attr'=>$definitions['items'],'value'=>$active];
    }
    private function updateSku(MerchantContext $ctx,int $id,int $skuId,array $patch): void
    {
        $this->find($ctx,$id,true);foreach(array_keys($patch)as$key)if(!in_array($key,['suk','price','cost','ot_price','vip_price','image','bar_code','bar_code_number','weight','volume','is_show','is_default_select','stock'],true))throw new AuthUnavailableException();
        $q=(new StoreProductAttrValue())->db()->where('id',$skuId)->where('product_id',$id)->where('type',0);self::transaction($q);$n=$q->update($patch);if(!is_int($n)||$n<0||$n>1)throw new AuthUnavailableException();$after=self::raw((new StoreProductAttrValue())->db()->where('id',$skuId)->where('product_id',$id)->where('type',0)->find());if($after===null)throw new AuthUnavailableException();
        foreach($patch as$key=>$value)if((string)$after[$key]!== (string)$value)throw new AuthUnavailableException();
    }
    public function replaceDefinition(MerchantContext $ctx,int $id,array $result,array $input,array $categoryParents): void
    {
        if(array_keys($categoryParents)!==$input['categories'])throw new AuthUnavailableException();
        foreach($categoryParents as $parent)Input::integer($parent,1,2147483647);
        $this->find($ctx,$id,true);foreach(['store_product_attr','store_product_attr_result']as$table){$q=Db::name($table)->where('product_id',$id)->where('type',0);self::transaction($q);$q->delete();}
        foreach($result['attr']as$item)Db::name('store_product_attr')->insert(['product_id'=>$id,'type'=>0,'attr_name'=>$item['value'],'attr_values'=>implode(',',array_column($item['detail'],'value'))]);
        // TEXT JSON and HTML are encoded once, matching the inspected legacy mutators.
        Db::name('store_product_attr_result')->insert(['product_id'=>$id,'type'=>0,'result'=>Input::json($result),'change_time'=>time()]);
        $q=Db::name('store_product_description')->where('product_id',$id)->where('type',0);self::transaction($q);$q->delete();Db::name('store_product_description')->insert(['product_id'=>$id,'type'=>0,'description'=>htmlspecialchars($input['description'])]);
        $q=Db::name('store_product_cate')->where('product_id',$id);self::transaction($q);$q->delete();
        foreach($input['categories']as$category){
            if(Db::name('store_product_cate')->insert(['product_id'=>$id,'cate_id'=>$category,'cate_pid'=>$categoryParents[$category],'status'=>0,'add_time'=>time()])!==1)throw new AuthUnavailableException();
        }
        $bindings=Db::name('store_product_cate')->where('product_id',$id)->field(['cate_id','cate_pid'])->order('cate_id');self::transaction($bindings);
        $actual=$bindings->select()->toArray();$expected=[];
        foreach($categoryParents as $category=>$parent)$expected[]=['cate_id'=>$category,'cate_pid'=>$parent];
        foreach($actual as &$binding){$binding['cate_id']=Input::integer($binding['cate_id'],1,2147483647,true);$binding['cate_pid']=Input::integer($binding['cate_pid'],1,2147483647,true);}unset($binding);
        if($actual!==$expected)throw new AuthUnavailableException();
        if($this->currentResult($ctx,$id,true)!==$result||$this->description($ctx,$id,true)!==$input['description'])throw new AuthUnavailableException();
    }
    public function batchShow(MerchantContext $ctx,array $ids,bool $show): void
    {
        foreach($ids as$id)$this->find($ctx,$id,true);
        $q=MerchantScope::apply((new StoreProduct())->db()->whereIn('id',$ids),$ctx);self::transaction($q);
        if((clone $q)->count()!==count($ids))throw new AuthUnavailableException();$n=$q->update(['is_show'=>(int)$show]);
        if(!is_int($n)||$n<0||$n>count($ids))throw new AuthUnavailableException();
        foreach($ids as$id)if((int)$this->find($ctx,$id,true)['is_show']!== (int)$show)throw new AuthUnavailableException();
    }
    public function categoryStatus(MerchantContext $ctx,int $id,int $status): void
    {
        $this->find($ctx,$id,true);$q=Db::name('store_product_cate')->where('product_id',$id);self::transaction($q);$q->update(['status'=>$status]);if(Db::name('store_product_cate')->where('product_id',$id)->where('status','<>',$status)->count()!==0)throw new AuthUnavailableException();
    }
    public function adjust(MerchantContext $ctx,int $id,string $unique,int $delta,string $reason): void
    {
        $root=$this->find($ctx,$id,true);if((int)$root['is_del']!==0)Input::invalid();$old=$this->skus($ctx,$id,true);if($this->physicalStock($old)!==(int)$root['stock'])throw new AuthUnavailableException();$matches=array_values(array_filter($old,static fn(array$r):bool=>$r['unique']===$unique));if(count($matches)===0)throw new MerchantNotFoundException();if(count($matches)>1)throw new AuthUnavailableException();$sku=$matches[0];$before=(int)$sku['stock'];$after=$before+$delta;$rootAfter=(int)$root['stock']+$delta;
        if($after<0||$after>4294967295||$rootAfter<0||$rootAfter>Input::STOCK_MAX)Input::invalid();
        $this->updateSku($ctx,$id,(int)$sku['id'],['stock'=>$after]);$this->updateRoot($ctx,$id,['stock'=>$rootAfter]);
        $q=Db::name('marketplace_stock_log');self::transaction($q);$values=['mer_id'=>$ctx->merId,'actor_employee_id'=>$ctx->employeeId,'product_id'=>$id,'attr_value_id'=>(int)$sku['id'],'sku_unique'=>$unique,'action'=>'adjust','delta'=>$delta,'stock_before'=>$before,'stock_after'=>$after,'root_stock_before'=>(int)$root['stock'],'root_stock_after'=>$rootAfter,'reason'=>$reason,'request_id'=>$ctx->requestId,'add_time'=>time()];
        $logId=$q->insertGetId($values);if((!is_int($logId)&&!is_string($logId))||preg_match('/\A[1-9][0-9]*\z/D',(string)$logId)!==1)throw new AuthUnavailableException();$stored=Db::name('marketplace_stock_log')->where('id',$logId)->where('mer_id',$ctx->merId)->find();if(!is_array($stored))throw new AuthUnavailableException();foreach($values as$key=>$value)if((string)$stored[$key]!== (string)$value)throw new AuthUnavailableException();
        if($this->physicalStock($this->skus($ctx,$id,true))!==$rootAfter)throw new AuthUnavailableException();
    }
}
