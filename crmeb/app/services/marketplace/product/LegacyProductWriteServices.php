<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use crmeb\services\auth\AccountChangeGuard;
use PDO;
use think\facade\Config;
use think\facade\Db;
use think\Model;
use think\exception\HttpException;
use think\exception\ValidateException;

/** Finite legacy product operation; its context exists only inside this actual PDO transaction. */
final class LegacyProductWriteServices
{
    private static ?array $operation = null;
    private const CHILDREN = [
        'store_product_attr'=>['type',0,'specification'],
        'store_product_attr_result'=>['type',0,'specification'],
        'store_product_attr_value'=>['type',0,'specification'],
        'store_product_description'=>['type',0,'description'],
        'store_product_cate'=>[null,null,'categories'],
        'store_product_coupon'=>[null,null,'coupons'],
        'store_product_reply'=>['reply_type','product','comments'],
        'store_product_virtual'=>[null,null,'virtual_cards'],
    ];

    public function __construct(private LegacyProductAccessServices $access,private AuditLogServices $audit) {}
    public static function active(): bool { return self::$operation!==null; }
    public static function transaction(): PDO
    {
        $context=self::$operation;$connection=Db::connect();$pdo=$connection->getPdo();
        if($context===null||$connection!==$context['connection']||!$pdo instanceof PDO
            ||$pdo!==$context['pdo']||!$pdo->inTransaction())throw new AuthUnavailableException();return $pdo;
    }
    public static function query(\think\db\Query $query): void
    {
        $pdo=self::transaction();if($query->getConnection()!==Db::connect()||$query->getConnection()->getPdo()!==$pdo)
            throw new AuthUnavailableException();
    }
    public static function selfId(): int
    {
        $enabled=Config::get('marketplace.enabled',false);
        if(!is_bool($enabled))throw new AuthUnavailableException();
        try{$id=self::id(Config::get('marketplace.self_operated_mer_id',null),4294967295);}catch(AdminException $error){throw new AuthUnavailableException();}
        $row=Db::name('marketplace_merchant')->where('mer_id',$id)->field('mer_id')->find();
        if(!is_array($row)||(string)$row['mer_id']!==(string)$id)throw new AuthUnavailableException();return $id;
    }
    public static function id(mixed $value,int $maximum=8388607): int
    {
        if(is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<1||$value>$maximum)throw new AdminException('请求参数不正确');return $value;
    }
    public static function ids(array $values): array
    { $ids=[];foreach($values as$value)$ids[]=self::id($value);$ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids; }
    public static function routeId(mixed $value,bool $allowZero=false): int
    { if($allowZero&&($value===0||$value==='0'))return 0;return self::id($value); }
    public static function money(mixed $value): string
    {
        // Preserve the finite decimal text of a legacy JSON number; no float arithmetic/truncation.
        if(is_float($value)){
            if(!is_finite($value))throw new AdminException('请求参数不正确');
            $value=json_encode($value,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        }
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D',$value,$parts)!==1)
            throw new AdminException('请求参数不正确');
        return $parts[1].'.'.str_pad($parts[2]??'',2,'0');
    }
    public static function minimum(array $values): string
    { if(!$values)throw new AdminException('请求参数不正确');$minimum=null;foreach($values as$value){$value=self::money($value);if($minimum===null||bccomp($value,$minimum,2)<0)$minimum=$value;}return $minimum; }
    public static function assertRawBody(array $data): void
    {
        if(array_intersect(array_keys($data),['mer_id','merchant_id','review_status','review_reason','review_admin_id','review_time']))
            throw new AdminException('请求参数不正确');
    }
    public static function assertParent(int $id): array
    {
        self::transaction();$context=self::$operation;
        if(!isset($context['owners'][$id]))throw new AuthUnavailableException();$owner=$context['owners'][$id];
        $row=Db::name('store_product')->where('id',$id)->where('mer_id',$owner)->lock(true)->find();
        if(!is_array($row)||(string)$row['id']!==(string)$id||(string)$row['mer_id']!==(string)$owner)throw new AuthUnavailableException();return $row;
    }
    public static function isEntrant(int $id): bool
    { $row=self::assertParent($id);return (int)$row['mer_id']!==self::$operation['self']; }
    public static function preserveSkus(int $id,int $type=0): bool
    {
        if(!self::active()||$type!==0)return false;self::assertParent($id);
        $root=self::assertParent($id);
        return self::$operation['scope'] instanceof PlatformScope||self::isEntrant($id)||(int)$root['virtual_type']!==0||self::hasPersistentStock($id);
    }
    public static function created(Model $model): void
    {
        self::transaction();if(!self::$operation['allow_create'])throw new AuthUnavailableException();
        $id=self::id($model->getData('id'));$owner=self::$operation['self'];
        if(isset(self::$operation['owners'][$id]))throw new AuthUnavailableException();
        self::$operation['owners'][$id]=$owner;self::$operation['before'][$id]=null;self::assertParent($id);
    }
    public static function createFields(array $data): array
    {
        self::transaction();if(!self::$operation['allow_create'])throw new AuthUnavailableException();
        $data['mer_id']=self::$operation['self'];$data['review_status']=0;$data['review_reason']='';$data['is_show']=0;
        if(self::$operation['scope'] instanceof PlatformScope||(int)($data['virtual_type']??0)!==0)$data['stock']=0;
        $data['review_admin_id']=null;$data['review_time']=null;return $data;
    }
    public static function owner(int $id): int { self::assertParent($id);return self::$operation['owners'][$id]; }
    public static function targetIds(): array { self::transaction();return array_keys(self::$operation['owners']); }
    public static function skip(int $id): void { self::assertParent($id);self::$operation['skipped'][$id]=true; }
    public static function afterCommit(callable $operation): void { self::transaction();self::$operation['after_commit'][]=$operation; }
    public static function rawScope(): PlatformScope
    { self::transaction();$scope=self::$operation['scope'];if(!$scope instanceof PlatformScope)throw new AuthUnavailableException();return $scope; }
    public static function hasPlatformScope(): bool
    { self::transaction();return self::$operation['scope'] instanceof PlatformScope; }
    public static function afterContent(int $productId,array $intents): void
    {
        self::assertParent($productId);self::rawScope();
        if(self::$operation['phase']!=='content'||isset(self::$operation['after_content'][$productId])||!array_is_list($intents)||$intents===[])throw new AuthUnavailableException();
        // This registry has exactly one audited inventory producer, never a caller-supplied callable.
        self::$operation['after_content'][$productId]=$intents;
    }
    public static function afterContentPhase(): bool
    { self::transaction();return self::$operation['phase']==='inventory'; }
    public static function contentSkuBefore(int $productId,int $skuId): ?array
    {
        self::assertParent($productId);self::rawScope();$matches=[];
        foreach(self::$operation['before'][$productId]['children']['store_product_attr_value']??[]as$row)
            if((string)$row['id']===(string)$skuId)$matches[]=$row;
        if(count($matches)>1)throw new AuthUnavailableException();return $matches[0]??null;
    }
    public static function categories(array $ids): array
    {
        self::transaction();$ids=array_values(array_unique(array_map(static fn($id)=>self::id($id,2147483647),$ids)));sort($ids,SORT_NUMERIC);if(!$ids)throw new AdminException('请选择商品分类');$map=[];
        foreach(Db::name('store_category')->whereIn('id',$ids)->order('id','asc')->lock(true)->select()as$row){
            if((int)$row['is_show']!==1)throw new AdminException('分类不可用');$map[(int)$row['id']]=(int)$row['pid'];}
        if(count($map)!==count($ids))throw new AdminException('分类不存在');
        $parents=array_values(array_unique(array_filter(array_values($map))));sort($parents,SORT_NUMERIC);
        $parentRows=[];if($parents)foreach(Db::name('store_category')->whereIn('id',$parents)->order('id','asc')->lock(true)->select()as$row)$parentRows[(int)$row['id']]=$row;
        foreach($map as$id=>$parent){if($parent!==0&&(!isset($parentRows[$parent])||(int)$parentRows[$parent]['is_show']!==1||(int)$parentRows[$parent]['pid']!==0))throw new AdminException('分类不可用');$map[$id]=$parent?:$id;}
        return $map;
    }
    public static function categoryStatus(int $id): int
    { $row=self::assertParent($id);return (int)$row['is_show']===1&&(int)$row['is_del']===0&&(int)$row['review_status']===2?1:0; }
    public static function coupons(int $id,array $values): void
    {
        self::assertParent($id);$wanted=[];
        foreach($values as$value){if($value===0||$value==='0'||$value==='')continue;$wanted[self::id($value,2147483647)]=true;}
        $query=Db::name('store_product_coupon')->where('product_id',$id)->order('id')->lock(true);self::query($query);
        $before=$query->select()->toArray();$expected=[];$seen=[];
        foreach($before as$row){$coupon=self::id($row['issue_coupon_id'],2147483647);if(isset($seen[$coupon]))throw new AuthUnavailableException();$seen[$coupon]=true;
            if(isset($wanted[$coupon])){$expected[(int)$row['id']]=$row;unset($wanted[$coupon]);continue;}
            $delete=Db::name('store_product_coupon')->where('product_id',$id)->where('id',self::id($row['id'],2147483647));self::query($delete);
            if($delete->delete()!==1)throw new AuthUnavailableException();
        }
        $now=time();if($now<1||$now>2147483647)throw new AuthUnavailableException();
        foreach(array_keys($wanted)as$coupon){$insert=['product_id'=>$id,'issue_coupon_id'=>$coupon,'add_time'=>$now];$new=Db::name('store_product_coupon');self::query($new);
            $bindingId=self::id($new->insertGetId($insert),2147483647);$read=Db::name('store_product_coupon')->where('product_id',$id)->where('id',$bindingId);self::query($read);$row=$read->lock(true)->find();
            if(!is_array($row))throw new AuthUnavailableException();foreach($insert as$field=>$value)if((string)$row[$field]!==(string)$value)throw new AuthUnavailableException();$expected[$bindingId]=$row;
        }
        $after=Db::name('store_product_coupon')->where('product_id',$id)->order('id')->lock(true);self::query($after);$rows=$after->select()->toArray();
        if(count($rows)!==count($expected))throw new AuthUnavailableException();
        foreach($rows as$row){$bindingId=self::id($row['id'],2147483647);if(!isset($expected[$bindingId])||$row!==$expected[$bindingId])throw new AuthUnavailableException();}
    }
    public static function freight(int $owner,mixed $mode,mixed $template): void
    {
        self::transaction();$mode=(int)$mode;if($mode!==3)return;$template=self::id($template,2147483647);
        $row=Db::name('shipping_templates')->where('id',$template)->where('mer_id',$owner)->lock(true)->find();
        if(!is_array($row))throw new AdminException('运费模板不可用');
    }
    public static function saveReferences(int $id,array $data): void
    {
        self::transaction();$owner=$id?self::owner($id):self::$operation['self'];
        self::categories($data['cate_id']);self::freight($owner,$data['freight'],$data['temp_id']);
        if($owner===self::$operation['self'])return;
        $pictures=$data['slider_image'];if(!is_array($pictures))throw new AdminException('请求参数不正确');
        foreach($data['attrs']as$sku)if(isset($sku['pic']))$pictures[]=$sku['pic'];
        foreach($data['items']as$item)foreach($item['detail']??[]as$option)if(is_array($option)&&($option['pic']??'')!=='')$pictures[]=$option['pic'];
        foreach(['recommend_image','video_link']as$field)if(($data[$field]??'')!=='')$pictures[]=$data[$field];
        if(is_string($data['description']??null))$pictures=array_merge($pictures,MerchantProductInput::htmlMedia($data['description']));
        foreach(array_unique($pictures)as$path){
            if(!is_string($path)||$path===''||str_contains($path,"\0"))throw new AdminException('素材不可用');
            self::mediaReference($owner,$path);
        }
    }
    private static function mediaReference(int $owner,string $path): void
    {
        self::transaction();$decoded=html_entity_decode($path,ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(preg_match('//u',$decoded)!==1||str_contains($decoded,"\0"))throw new AdminException('素材不可用');
        // Invert only the actual DTO producer, never an arbitrary URL basename/origin.
        $site=sys_config('site_url');if(!is_string($site))throw new AuthUnavailableException();$candidates=[$decoded];
        $origins=[$site];if(str_starts_with($site,'http:'))$origins[]='https:'.substr($site,5);
        foreach($origins as$origin)if($origin!==''&&str_starts_with($decoded,$origin.'/')){
            $relative=substr($decoded,strlen($origin));$display=set_file_url($relative,$site);
            if($display===$decoded||(str_starts_with($display,'http:')&&'https:'.substr($display,5)===$decoded))$candidates[]=$relative;
        }
        if(str_starts_with($decoded,'https:'))$candidates[]='http:'.substr($decoded,6);
        $candidates=array_values(array_unique($candidates));
        $query=Db::name('system_attachment')->where('mer_id',$owner)->where(function($query)use($candidates){$query->whereIn('att_dir',$candidates)->whereOr('satt_dir','in',$candidates);})->lock(true);self::query($query);
        $rows=$query->field(['att_id','mer_id','att_dir','satt_dir'])->select()->toArray();$matched=[];
        foreach($rows as$row){if((string)$row['mer_id']!==(string)$owner)throw new AuthUnavailableException();
            foreach(['att_dir','satt_dir']as$field){$canonical=$row[$field];if(!is_string($canonical))throw new AuthUnavailableException();
                $aliases=[$canonical,set_file_url($canonical,$site)];foreach($aliases as$alias)if(str_starts_with($alias,'http:'))$aliases[]='https:'.substr($alias,5);
                if(in_array($decoded,$aliases,true))$matched[(string)$row['att_id']]=true;}
        }
        if(count($matched)!==1)throw new AdminException('素材不可用');
    }
    public static function showEligibility(array $ids,bool $show): void
    {
        foreach(self::ids($ids)as$id){$row=self::assertParent($id);if($show&&((int)$row['is_del']!==0||(int)$row['review_status']!==2))throw new AdminException('商品尚未审核通过');}
    }
    public static function hardDeleteEligibility(array $ids): void
    {
        foreach(self::ids($ids)as$id){self::assertParent($id);
            // The ledger intentionally has no heterogeneous product/SKU FK; enforce the durable reference here.
            if(self::hasPersistentStock($id))throw new AdminException('商品已有库存流水，不能彻底删除');
            $paid=Db::name('store_cart')->where('product_id',$id)->where('type',0)->where('is_pay',1)->lock(true);self::query($paid);
            if($paid->find()!==null)throw new AdminException('商品已有订单引用，不能彻底删除');
            $orders=Db::name('store_order_cart_info')->where('product_id',$id)->order('id')->lock(true);self::query($orders);
            foreach($orders->select()->toArray()as$order){
                $cart=json_decode($order['cart_info'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($cart)||!array_key_exists('type',$cart))throw new AuthUnavailableException();
                $type=$cart['type'];if(is_string($type)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$type)===1)$type=(int)$type;
                // Actual pre-sale carts use activity type6 and an advance product ID, not a normal product reference.
                if(!is_int($type)||!in_array($type,[0,1,2,3,4,6],true))throw new AuthUnavailableException();
                if($type===0)throw new AdminException('商品已有订单引用，不能彻底删除');
            }
            foreach(Db::name('store_product_virtual')->where('product_id',$id)->lock(true)->select()as$card){
                if((string)$card['uid']!=='0'||(string)$card['order_id']!==''
                    ||Db::name('store_order')->where('virtual_info',$card['card_unique'])->count()>0)
                    throw new AdminException('商品已有发放卡密，不能彻底删除');
            }
        }
    }
    public static function replyParents(array $replyIds): array
    {
        $ids=[];foreach($replyIds as$replyId){$replyId=self::id($replyId,2147483647);
            $row=Db::name('store_product_reply')->where('id',$replyId)->where('reply_type','product')->field(['id','product_id'])->find();
            if(!is_array($row))throw new AdminException('评论不存在');$ids[]=self::id($row['product_id']);}
        return self::ids($ids);
    }
    public static function lockReplies(array $replyIds): array
    {
        self::transaction();$rows=[];foreach($replyIds as$replyId){$replyId=self::id($replyId,2147483647);
            $row=Db::name('store_product_reply')->where('id',$replyId)->where('reply_type','product')->lock(true)->find();
            if(!is_array($row))throw new AdminException('评论不存在');self::assertParent(self::id($row['product_id']));$rows[$replyId]=$row;}
        return $rows;
    }

    /** Called only by authenticated existing caller/controller; request identity is not fabricated. */
    public function run(array $ids,callable $operation,bool $allowCreate=false,bool $content=false): mixed
    {
        $ids=self::ids($ids);
        if(self::active()){
            self::transaction();if(array_diff($ids,self::targetIds())||($allowCreate&&!self::$operation['allow_create']))throw new AuthUnavailableException();
            return $operation();
        }
        $request=app()->request;$scope=null;
        if($request instanceof Request&&LegacyProductAccessServices::isWriter($request)){
            $scope=$request->platformScope();$this->access->authorizeScope($scope);
        }else{
            $module=app()->http->getName();
            if(!in_array($module,['api','outapi'],true))throw new AuthUnavailableException();
            // These callers retain their real API/OutAccount identity and have no cross-merchant grant.
            if($module==='api'&&(!$request instanceof Request||$request->isLogin()!==true))throw new AuthUnavailableException();
            if($module==='outapi'&&(!$request instanceof Request||(int)$request->outId()<1))throw new AuthUnavailableException();
        }
        $cacheKeys=[];$deferred=[];$controlled=null;
        // A self-only caller must own its outer commit too; an unknown surrounding transaction cannot flush side effects.
        $outerPdo=Db::connect()->connect();if(!$outerPdo instanceof PDO||$outerPdo->inTransaction())throw new AuthUnavailableException();
        $callback=function()use($scope,$ids,$operation,$allowCreate,$content,&$cacheKeys,&$deferred):mixed{
            $connection=Db::connect();$pdo=$connection->getPdo();if(!$pdo instanceof PDO||!$pdo->inTransaction())throw new AuthUnavailableException();
            if($scope!==null)$this->access->authorizeScope($scope);$self=self::selfId();$owners=[];
            foreach($ids as$id){$row=Db::name('store_product')->where('id',$id)->field(['id','mer_id'])->find();
                if(!is_array($row))throw new AdminException('商品不存在');$owner=self::id($row['mer_id'],4294967295);
                if($scope===null&&$owner!==$self)throw new AuthUnavailableException();$owners[$id]=$owner;}
            $merchants=array_values(array_unique(array_values($owners)));if($allowCreate)$merchants[]=$self;sort($merchants,SORT_NUMERIC);
            foreach(array_unique($merchants)as$owner){$row=Db::name('marketplace_merchant')->where('mer_id',$owner)->field('mer_id')->lock(true)->find();if(!is_array($row))throw new AuthUnavailableException();}
            self::$operation=['scope'=>$scope,'self'=>$self,'owners'=>$owners,'allow_create'=>$allowCreate,'connection'=>$connection,'pdo'=>$pdo,'before'=>[],'skipped'=>[],'after_commit'=>[],'after_content'=>[],'phase'=>'content'];
            try{
                foreach($ids as$id){self::assertParent($id);self::$operation['before'][$id]=$this->snapshot($id);}
                $result=$operation();$this->finish($content);$this->finishInventory();$cacheKeys=$this->stockCacheKeys();$deferred=self::$operation['after_commit'];return $result;
            }finally{self::$operation=null;}
        };
        $guarded=function()use($callback,&$controlled):mixed{
            try{return $callback();}catch(HttpException $error){$status=$error->getStatusCode();
                if(!in_array($status,[401,403,404,422],true))throw $error;
                $controlled=$status;throw new ValidateException('平台商品请求未完成');}
        };
        try{
            $result=$scope!==null?AccountChangeGuard::runMany('adminapi',[],$guarded):Db::connect()->transaction($guarded);
            if(Db::connect()->getPdo()->inTransaction())throw new AuthUnavailableException();
            $this->expireCommittedKeys($cacheKeys);foreach($deferred as$effect)$effect();return $result;
        }catch(ValidateException $error){
            if($controlled!==null)throw new HttpException($controlled,match($controlled){401=>'请登录',403=>'没有权限访问',404=>'资源不存在',422=>'商品请求参数或状态不正确'});
            throw $error;
        }catch(AdminException|ApiException|AuthException|HttpException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    public function selfWorker(array $ids,callable $operation): mixed
    {
        $ids=self::ids($ids);$outerPdo=Db::connect()->connect();if(self::active()||!$outerPdo instanceof PDO||$outerPdo->inTransaction())throw new AuthUnavailableException();
        $cacheKeys=[];$deferred=[];try{$result=Db::connect()->transaction(function()use($ids,$operation,&$cacheKeys,&$deferred):mixed{
            $connection=Db::connect();$pdo=$connection->getPdo();if(!$pdo instanceof PDO||!$pdo->inTransaction())throw new AuthUnavailableException();$self=self::selfId();
            if(Db::name('marketplace_merchant')->where('mer_id',$self)->lock(true)->find()===null)throw new AuthUnavailableException();
            self::$operation=['scope'=>null,'self'=>$self,'owners'=>array_fill_keys($ids,$self),'allow_create'=>false,'connection'=>$connection,'pdo'=>$pdo,'before'=>[],'skipped'=>[],'after_commit'=>[],'after_content'=>[],'phase'=>'content'];
            try{foreach($ids as$id){self::assertParent($id);self::$operation['before'][$id]=$this->snapshot($id);} $result=$operation();$this->finish(false);$cacheKeys=$this->stockCacheKeys();$deferred=self::$operation['after_commit'];return $result;}
            finally{self::$operation=null;}
        });if(Db::connect()->getPdo()->inTransaction())throw new AuthUnavailableException();$this->expireCommittedKeys($cacheKeys);foreach($deferred as$effect)$effect();return $result;}catch(AdminException|ApiException|AuthException $error){throw $error;}catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    private function stockCacheKeys(): array
    {
        self::transaction();$keys=[];
        foreach(self::$operation['owners']as$id=>$owner){
            $before=[];$after=[];
            foreach(self::$operation['before'][$id]['children']['store_product_attr_value']??[]as$row){$skuId=self::id($row['id'],2147483647);
                if(isset($before[$skuId]))throw new AuthUnavailableException();$before[$skuId]=$row;}
            $query=Db::name('store_product_attr_value')->where('product_id',$id)->where('type',0)->order('id')->lock(true);self::query($query);
            foreach($query->select()->toArray()as$row){$skuId=self::id($row['id'],2147483647);
                if(isset($after[$skuId]))throw new AuthUnavailableException();$after[$skuId]=$row;}
            foreach(array_unique(array_merge(array_keys($before),array_keys($after)))as$skuId){$old=$before[$skuId]??null;$new=$after[$skuId]??null;
                if($old!==null&&$new!==null&&$old['suk']===$new['suk']&&$old['unique']===$new['unique']&&(string)$old['stock']===(string)$new['stock'])continue;
                foreach([$old,$new]as$sku){if($sku===null)continue;foreach([[$sku['suk'],''],['',$sku['unique']],[$sku['suk'],$sku['unique']]]as[$suk,$unique])
                    $keys[md5('product_attr_stock_'.$id.'_'.$suk.'_'.$unique)]=true;}
            }
        }
        return array_keys($keys);
    }
    private function expireCommittedKeys(array $keys): void
    {
        try{foreach($keys as$key)CacheService::delete($key);}
        catch(\Throwable $error){
            // The database was already committed. This finite event never claims a rollback or retries a write.
            error_log('{"event":"legacy_product_committed_cache_unavailable"}');throw new AuthUnavailableException();
        }
    }
    private function snapshot(int $id): array
    {
        $root=self::assertParent($id);$children=[];
        foreach(self::CHILDREN as$table=>[$type,$value,$field]){
            $query=Db::name($table)->where('product_id',$id);if($type!==null)$query->where($type,$value);
            self::query($query);
            $rows=[];foreach($query->lock(true)->select()as$row)$rows[]=is_array($row)?$row:$row->getData();$children[$table]=$rows;
        }
        $ledger=Db::name('marketplace_stock_log')->where('product_id',$id)->order('id')->lock(true);self::query($ledger);
        return ['root'=>$root,'children'=>$children,'stock_ledger'=>$ledger->select()->toArray(),'stock_audit'=>$this->stockAuditRows($id,self::$operation['owners'][$id])];
    }
    private function finish(bool $content): void
    {
        self::transaction();$scope=self::$operation['scope'];if($scope!==null)$this->access->authorizeScope($scope);
        foreach(self::$operation['owners']as$id=>$owner){
            $before=self::$operation['before'][$id];$row=Db::name('store_product')->where('id',$id)->where('mer_id',$owner)->find();
            $after=is_array($row)?$this->snapshot($id):null;
            if($after===null&&$before===null)throw new AuthUnavailableException();
            $fields=$this->changes($before,$after);
            if($before!==null&&$after!==null&&($before['stock_ledger']!==$after['stock_ledger']||$before['stock_audit']!==$after['stock_audit']))throw new AuthUnavailableException();
            if(isset(self::$operation['skipped'][$id])){if($fields!==[])throw new AuthUnavailableException();continue;}
            if($before!==null&&$after!==null&&($scope instanceof PlatformScope||$owner!==self::$operation['self']||$this->hasLedger($id))){
                $old=$before['children']['store_product_attr_value'];$new=$after['children']['store_product_attr_value'];
                foreach($old as$sku){$matches=array_values(array_filter($new,static fn(array$r):bool=>(string)$r['id']===(string)$sku['id']));
                    if(count($matches)!==1)throw new AuthUnavailableException();foreach(['id','unique','product_id','type','stock','sales']as$key)
                        if((string)$matches[0][$key]!== (string)$sku[$key])throw new AuthUnavailableException();}
                if((string)$before['root']['stock']!==(string)$after['root']['stock'])throw new AuthUnavailableException();
            }
            if($after!==null&&$content&&$scope instanceof PlatformScope){$oldIds=array_fill_keys(array_column($before['children']['store_product_attr_value']??[],'id'),true);
                foreach($after['children']['store_product_attr_value']as$sku)if(!isset($oldIds[$sku['id']])&&((string)$sku['stock']!=='0'||(string)$sku['sales']!=='0'))throw new AuthUnavailableException();
                if($before===null&&(string)$after['root']['stock']!=='0')throw new AuthUnavailableException();}
            if($content&&$before!==null&&$after!==null&&$this->contentChanged($fields)){
                $patch=['review_status'=>1,'review_reason'=>'','review_admin_id'=>null,'review_time'=>null,'is_show'=>0];
                Db::name('store_product')->where('id',$id)->where('mer_id',$owner)->update($patch);
                Db::name('store_product_cate')->where('product_id',$id)->update(['status'=>0]);
                $after=$this->snapshot($id);foreach($patch as$key=>$value)if($value===null?$after['root'][$key]!==null:(string)$after['root'][$key]!== (string)$value)throw new AuthUnavailableException();
                $fields=$this->changes($before,$after);
            }
            if($scope!==null&&$owner!==self::$operation['self']){
                $this->audit->record('admin',$scope->adminId,$owner,$scope->permission,'product',(string)$id,'success',['changed_fields'=>$fields]);
            }
        }
    }
    private function hasLedger(int $id): bool { return self::hasPersistentStock($id); }
    private static function hasPersistentStock(int $id): bool
    {
        self::assertParent($id);
        $movement=Db::name('marketplace_stock_movement')->where('product_id',$id)->field(['id'])->order('id')->lock(true);
        self::query($movement);if($movement->find()!==null)return true;
        if(Db::name('marketplace_stock_log')->where('product_id',$id)->count()>0)return true;
        $rows=Db::name('marketplace_audit_log')->where('mer_id',self::$operation['owners'][$id])->where('actor_type','admin')
            ->where('action','marketplace-products-stock-adjust')->where('resource_type','product-stock')->where('result','success')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`detail`, '$.product_id')) = ?",[(string)$id])->field(['resource_id','detail'])->select()->toArray();
        foreach($rows as$row){$detail=json_decode($row['detail'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($detail)||!isset($detail['product_id'],$detail['attr_value_id'],$detail['sku_unique'])||(string)$detail['product_id']!==(string)$id)
                throw new AuthUnavailableException();
            $skuId=self::id($detail['attr_value_id'],2147483647);if((string)$row['resource_id']!==(string)$skuId||!is_string($detail['sku_unique'])||strlen($detail['sku_unique'])!==8)throw new AuthUnavailableException();
            $sku=Db::name('store_product_attr_value')->where('id',$skuId)->where('product_id',$id)->where('type',0)->where('unique',$detail['sku_unique'])->find();
            if(!is_array($sku))throw new AuthUnavailableException();
        }
        return $rows!==[];
    }
    /** Content preservation is already complete; only explicit server-generated inventory receipts permit stock changes. */
    private function finishInventory(): void
    {
        self::transaction();if(self::$operation['after_content']===[])return;
        $before=[];$ledger=[];$auditBefore=[];foreach(self::$operation['owners']as$id=>$owner){$before[$id]=$this->snapshot($id);
            $auditBefore[$id]=$this->stockAuditRows($id,$owner);
            $ledger[$id]=Db::name('marketplace_stock_log')->where('product_id',$id)->order('id')->lock(true)->select()->toArray();}
        self::$operation['phase']='inventory';$receipts=[];
        foreach(self::$operation['after_content']as$productId=>$intents){
            $result=app()->make(PlatformProductStockServices::class)->applyLegacyIntents(self::rawScope(),(int)$productId,$intents);
            if(!is_array($result)||!array_is_list($result))throw new AuthUnavailableException();
            foreach($result as$receipt){if(!is_array($receipt)||!isset($receipt['product_id'],$receipt['attr_value_id'],$receipt['sku_unique'],$receipt['stock_before'],$receipt['delta'],$receipt['stock_after'],$receipt['root_stock_before'],$receipt['root_stock_after']))throw new AuthUnavailableException();
                $keys=array_keys($receipt);sort($keys,SORT_STRING);$allowed=['product_id','attr_value_id','sku_unique','stock_before','delta','stock_after','root_stock_before','root_stock_after'];sort($allowed,SORT_STRING);
                if($keys!==$allowed)throw new AuthUnavailableException();
                foreach(['root_stock_before','root_stock_after']as$field)if(!is_int($receipt[$field])||$receipt[$field]<0||$receipt[$field]>16777215)throw new AuthUnavailableException();
                $id=self::id($receipt['product_id']);$skuId=self::id($receipt['attr_value_id'],2147483647);if($id!==(int)$productId||!isset($before[$id])||isset($receipts[$id][$skuId]))throw new AuthUnavailableException();
                $receipts[$id][$skuId]=$receipt;}
        }
        foreach($before as$id=>$state){$after=$this->snapshot($id);$rootBefore=$state['root'];$rootAfter=$after['root'];unset($rootBefore['stock'],$rootAfter['stock']);
            if($rootBefore!==$rootAfter||$ledger[$id]!==Db::name('marketplace_stock_log')->where('product_id',$id)->order('id')->select()->toArray())throw new AuthUnavailableException();
            $old=$state['children']['store_product_attr_value'];$new=$after['children']['store_product_attr_value'];if(count($old)!==count($new))throw new AuthUnavailableException();$sum=0;
            if(!isset($receipts[$id])&&(string)$state['root']['stock']!==(string)$after['root']['stock'])throw new AuthUnavailableException();
            if(isset($receipts[$id])&&array_diff(array_keys($receipts[$id]),array_map(static fn(array$r):int=>(int)$r['id'],$old))!==[])throw new AuthUnavailableException();
            foreach($old as$sku){$matches=array_values(array_filter($new,static fn(array$r):bool=>(string)$r['id']===(string)$sku['id']));if(count($matches)!==1)throw new AuthUnavailableException();$current=$matches[0];
                $oldWithoutStock=$sku;$newWithoutStock=$current;unset($oldWithoutStock['stock'],$newWithoutStock['stock']);if($oldWithoutStock!==$newWithoutStock)throw new AuthUnavailableException();
                $receipt=$receipts[$id][(int)$sku['id']]??null;
                if($receipt===null){if((string)$current['stock']!==(string)$sku['stock'])throw new AuthUnavailableException();}
                else{foreach(['stock_before','stock_after']as$field)if(!is_int($receipt[$field])||$receipt[$field]<0||$receipt[$field]>4294967295)throw new AuthUnavailableException();
                    if(!is_int($receipt['delta'])||$receipt['delta']< -4294967295||$receipt['delta']>4294967295||$receipt['stock_before']+$receipt['delta']!==$receipt['stock_after']||$receipt['sku_unique']!==$sku['unique']
                        ||(string)$receipt['stock_before']!==(string)$sku['stock']||(string)$receipt['stock_after']!==(string)$current['stock']
                        ||(string)$receipt['root_stock_before']!==(string)$state['root']['stock']||(string)$receipt['root_stock_after']!==(string)$after['root']['stock'])throw new AuthUnavailableException();}
                $sum+=(int)$current['stock'];if($sum>16777215)throw new AuthUnavailableException();
            }
            if(isset($receipts[$id])&&(string)$sum!==(string)$after['root']['stock'])throw new AuthUnavailableException();
            foreach(self::CHILDREN as$table=>$_){if($table==='store_product_attr_value'||$table==='store_product_virtual')continue;
                $a=$state['children'][$table];$b=$after['children'][$table];
                if($table==='store_product_attr_result'){foreach([&$a,&$b]as&$rows)foreach($rows as&$row){$definition=json_decode($row['result'],true,512,JSON_THROW_ON_ERROR);
                    foreach($definition['value']as&$value)unset($value['stock'],$value['virtual_list']);unset($value);$row['result']=$definition;}unset($row,$rows);}
                if($a!==$b)throw new AuthUnavailableException();
            }
            // A product with no inventory receipt cannot change its virtual rows either.
            if(!isset($receipts[$id])&&$state['children']['store_product_virtual']!==$after['children']['store_product_virtual'])throw new AuthUnavailableException();
            $audits=$this->stockAuditRows($id,self::$operation['owners'][$id]);$newAudits=[];
            foreach($auditBefore[$id]as$auditId=>$row)if(!isset($audits[$auditId])||$audits[$auditId]!==$row)throw new AuthUnavailableException();
            foreach($audits as$auditId=>$row)if(!isset($auditBefore[$id][$auditId]))$newAudits[$auditId]=$row;
            if(count($newAudits)!==count($receipts[$id]??[]))throw new AuthUnavailableException();$seen=[];
            foreach($newAudits as$row){$detail=json_decode($row['detail'],true,32,JSON_THROW_ON_ERROR);$skuId=self::id($detail['attr_value_id']??null,2147483647);
                $receipt=$receipts[$id][$skuId]??null;if($receipt===null||isset($seen[$skuId])||$row['actor_type']!=='admin'
                    ||(string)$row['actor_id']!==(string)self::rawScope()->adminId||(string)$row['mer_id']!==(string)self::$operation['owners'][$id]
                    ||$row['resource_type']!=='product-stock'||(string)$row['resource_id']!==(string)$skuId||$row['result']!=='success')throw new AuthUnavailableException();
                $expected=array_intersect_key($receipt,array_flip(['product_id','attr_value_id','sku_unique','stock_before','delta','stock_after','root_stock_before','root_stock_after']));
                ksort($expected);ksort($detail);if($detail!==$expected)throw new AuthUnavailableException();$seen[$skuId]=true;
            }
        }
        self::$operation['phase']='inventory_verified';
    }
    private function stockAuditRows(int $id,int $owner): array
    {
        self::assertParent($id);$query=Db::name('marketplace_audit_log')->where('mer_id',$owner)->where('action','marketplace-products-stock-adjust')
            ->where('resource_type','product-stock')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`detail`, '$.product_id')) = ?",[(string)$id])->order('id')->lock(true);self::query($query);
        $rows=[];foreach($query->select()->toArray()as$row){$auditId=self::id($row['id'],4294967295);if(isset($rows[$auditId]))throw new AuthUnavailableException();$rows[$auditId]=$row;}return $rows;
    }
    private function changes(?array $before,?array $after): array
    {
        if($before===null)return ['created'];if($after===null)return ['deleted'];$fields=[];
        foreach($before['root']as$key=>$value)if(array_key_exists($key,$after['root'])&&$value!==$after['root'][$key])$fields[]=$key;
        foreach(self::CHILDREN as$table=>[$type,$value,$field])if($this->logicalRows($before['children'][$table],$table)!==$this->logicalRows($after['children'][$table],$table))$fields[]=$field;
        return array_values(array_unique($fields));
    }
    private function logicalRows(array $rows,string $table): array
    {
        $out=[];foreach($rows as$row){unset($row['add_time'],$row['change_time']);
            if($table!=='store_product_attr_value')unset($row['id']);
            ksort($row);$out[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
        sort($out,SORT_STRING);return $out;
    }
    private function contentChanged(array $fields): bool
    {
        return array_diff($fields,['is_show','is_del','stock','sales','browse','review_status','review_reason','review_admin_id','review_time','comments','deleted','created'])!==[];
    }
}
