<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\Request;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use app\dao\marketplace\order\MarketplaceOrderReviewDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\PlatformAccessServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Db;
use think\facade\Log;

/** Existing-table purchased-item reviews. These entrypoints require the real original authentication middlewares. */
final class MarketplaceOrderReviewServices
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;

    public function __construct(private MarketplaceOrderReviewDao $reviews,private MarketplaceOrderDao $orders,
        private MarketplacePaymentDao $payments,private MarketplaceOrderFulfillmentDao $families,private MarketplaceOrderRefundDao $refunds,
        private MarketplaceOrderFulfillmentServices $fulfillment,private MarketplaceAftersaleFileServices $files,
        private EmployeeAuthServices $employees,private PlatformAccessServices $platform,private AuditLogServices $audit){}

    public function quoteForCustomer(Request $request,int $actualUid,mixed $rootId,mixed $itemId):array
    {
        $identity=$this->customer($request,$actualUid);$this->detailRequest($request);
        return $this->read($request,$identity,'quote',MarketplaceOrderReviewInput::id($rootId),MarketplaceOrderReviewInput::id($itemId),[],null,null);
    }
    public function createForCustomer(Request $request,int $actualUid,mixed $rootId,mixed $itemId,array $raw):array
    {
        $identity=$this->customer($request,$actualUid);$body=$this->wire($request,'create',$raw);
        $id=MarketplaceOrderReviewInput::id($rootId);$item=MarketplaceOrderReviewInput::id($itemId);
        [$connection,$pdo]=$this->defaultConnection();$root=$this->reviews->locateCustomerRoot($connection,$pdo,$actualUid,$id);
        if($root===null)self::fail();return $this->write($connection,$pdo,$request,$identity,'create',$root,$item,null,$body,null,null);
    }
    public function pageForMerchant(Request $request,MerchantContext $context,array $query):array
    {
        $identity=$this->merchant($request,$context,'product.read');$this->employees->authorizeAction($context,'product.read',false);
        return $this->read($request,$identity,'merchant_page',null,null,$this->query($request,false,$query),$context,null);
    }
    public function detailForMerchant(Request $request,MerchantContext $context,mixed $reviewId):array
    {
        $identity=$this->merchant($request,$context,'product.read');$this->employees->authorizeAction($context,'product.read',false);$this->detailRequest($request);
        return $this->read($request,$identity,'merchant_detail',MarketplaceOrderReviewInput::id($reviewId),null,[],$context,null);
    }
    public function replyForMerchant(Request $request,MerchantContext $context,mixed $reviewId,array $raw):array
    {
        $identity=$this->merchant($request,$context,'product.write');$this->employees->authorizeAction($context,'product.write',false);
        $body=$this->wire($request,'reply',$raw);$id=MarketplaceOrderReviewInput::id($reviewId);[$connection,$pdo]=$this->defaultConnection();
        $row=$this->reviews->locateReview($connection,$pdo,$id,$context->merId);if($row===null)self::fail();
        $roots=$this->reviews->rootsForReviews($connection,$pdo,[$row]);if(count($roots)!==1)self::fail();$root=reset($roots);
        return $this->write($connection,$pdo,$request,$identity,'reply',$root,null,$row,$body,$context,null);
    }
    public function pageForPlatform(Request $request,PlatformScope $scope,array $query):array
    {
        $identity=$this->administrator($request,$scope,'marketplace-reviews-list');$this->platform->authorizeScope($scope,'marketplace-reviews-list');
        return $this->read($request,$identity,'platform_page',null,null,$this->query($request,true,$query),null,$scope);
    }
    public function detailForPlatform(Request $request,PlatformScope $scope,mixed $reviewId):array
    {
        $identity=$this->administrator($request,$scope,'marketplace-reviews-read');$this->platform->authorizeScope($scope,'marketplace-reviews-read');$this->detailRequest($request);
        return $this->read($request,$identity,'platform_detail',MarketplaceOrderReviewInput::id($reviewId),null,[],null,$scope);
    }
    public function moderateForPlatform(Request $request,PlatformScope $scope,mixed $reviewId,array $raw):array
    {
        $identity=$this->administrator($request,$scope,'marketplace-reviews-moderate');$this->platform->authorizeScope($scope,'marketplace-reviews-moderate');
        $body=$this->wire($request,'moderate',$raw);$id=MarketplaceOrderReviewInput::id($reviewId);[$connection,$pdo]=$this->defaultConnection();
        $row=$this->reviews->locateReview($connection,$pdo,$id,null);if($row===null)self::fail();
        $roots=$this->reviews->rootsForReviews($connection,$pdo,[$row]);if(count($roots)!==1)self::fail();$root=reset($roots);
        return $this->write($connection,$pdo,$request,$identity,'moderate',$root,null,$row,$body,null,$scope);
    }

    private function read(Request $request,array $identity,string $kind,?int $locator,?int $itemId,array $query,
        ?MerchantContext $merchant,?PlatformScope $scope):array
    {
        [$connection,$pdo]=$this->defaultConnection();$set=false;$began=false;$committing=false;$auditResource=null;
        try{
            $this->outside($connection,$pdo);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);
            $set=true;if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false)self::fail();
            $this->outside($connection,$pdo);if($pdo->beginTransaction()!==true)self::fail();$began=true;$this->snapshot($connection,$pdo);
            $structure=$this->families->readAftersaleStructureReadiness($connection,$pdo);
            if($structure!==['schema'=>2,'state'=>'READY'])self::fail();
            $reviewStructure=$this->reviews->readStructureReadiness($connection,$pdo);$this->readAuthorization($request,$identity,$kind,$merchant,$scope);
            if($identity['actor_type']==='customer')$this->refunds->readCustomerStatus($connection,$pdo,$identity['actor_id']);
            $selected=[];$count=0;
            if($kind==='quote'){
                if($locator===null||$itemId===null)self::fail();$root=$this->reviews->locateCustomerRoot($connection,$pdo,$identity['actor_id'],$locator);
                if($root===null)self::fail();$roots=[$root];
            }elseif(str_ends_with($kind,'_page')){
                $native=$this->reviews->readPage($connection,$pdo,$merchant?->merId,$query);self::keys($native,['count','list']);
                $selected=$native['list'];$count=self::integer($native['count'],0,PHP_INT_MAX);$roots=array_values($this->reviews->rootsForReviews($connection,$pdo,$selected));
            }else{
                if($locator===null)self::fail();$row=$this->reviews->locateReview($connection,$pdo,$locator,$merchant?->merId);
                if($row===null)self::fail();$selected=[$row];$count=1;$roots=array_values($this->reviews->rootsForReviews($connection,$pdo,$selected));
            }
            $snapshots=$this->readSnapshots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
            if($kind==='quote'){
                $snapshot=$snapshots[$locator]??null;if(!is_array($snapshot)||$itemId===null)self::fail();
                $items=$snapshot['inspection']['source']['items'];$item=$items[$itemId]??null;if(!is_array($item))self::fail();
                $bound=self::bindReviews($snapshot);$saved=$bound['items'][$itemId]??null;
                $data=['schema'=>1,'scope'=>'marketplace_order_review_v1','root_order_id'=>$locator,'source_item_id'=>$itemId,
                    'item'=>self::publicItem($snapshot,$itemId),'review'=>$saved===null?null:self::view($saved,$itemId),
                    'allowed_actions'=>['submit'=>$saved===null&&self::eligible($snapshot,$itemId)]];
            }else{
                $views=[];
                foreach($selected as$row){$rootId=self::id($row['oid']);$snapshot=$snapshots[$rootId]??null;if(!is_array($snapshot))self::fail();
                    $bound=self::bindReviews($snapshot);$id=self::id($row['id']);$actual=$bound['ids'][$id]??null;
                    if(!is_array($actual)||$actual['row']!==$row)self::fail();$views[]=self::view($row,$actual['item_id']);}
                $data=str_ends_with($kind,'_page')?['page'=>$query['page'],'limit'=>$query['limit'],'count'=>$count,'list'=>$views]:($views[0]??self::fail());
                if($scope!==null)$auditResource=str_ends_with($kind,'_page')?['mer'=>null,'id'=>'global']:
                    ['mer'=>self::integer($selected[0]['mer_id'],1,self::UINT_MAX),'id'=>(string)self::id($selected[0]['id'])];
            }
            if($this->families->readAftersaleStructureReadiness($connection,$pdo)!==$structure
                ||$this->reviews->readStructureReadiness($connection,$pdo)!==$reviewStructure)self::fail();
            $this->readAuthorization($request,$identity,$kind,$merchant,$scope);$this->snapshot($connection,$pdo);$committing=true;
            if($pdo->commit()!==true)self::fail();$began=false;$this->outside($connection,$pdo);
        }catch(Throwable $error){
            if($set){$clean=false;$rolled=false;
                try{if($pdo->inTransaction()){$rolled=$pdo->rollBack()===true;if(!$rolled||$pdo->inTransaction())self::fail();}
                    if($began&&!$committing&&$rolled){$this->outside($connection,$pdo);$clean=true;}}catch(Throwable $ignored){}
                if(!$clean){try{$connection->close();}catch(Throwable $ignored){}}}
            self::fail();
        }
        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);
        if($scope!==null){if($auditResource===null)self::fail();$this->auditRead($connection,$pdo,$request,$identity,$kind,$scope,$auditResource);}
        $data=self::present($data,$kind);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);return $data;
    }

    private function readSnapshots(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        $this->snapshot($connection,$pdo);self::list($roots);$ids=[];
        foreach($roots as$root){$id=self::id($root['id']);if(isset($ids[$id]))self::fail();$ids[$id]=true;self::root($root);}
        $payments=$this->payments->readObservationsForRoots($pdo,$roots);$this->snapshot($connection,$pdo);
        $facts=$this->orders->readPaymentApplicationCaptureFactsBatch($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        $rawFamilies=$this->families->readFulfillmentFamiliesForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        $refunds=$this->refunds->readRefundsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        $actions=$this->refunds->readActionsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        $logs=$this->refunds->readStatusLogsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        $reviews=$this->reviews->readForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo);
        foreach([$payments,$facts,$rawFamilies,$refunds,$actions,$logs,$reviews]as$map)if(array_keys($map)!==array_keys($ids))self::fail();$out=[];
        foreach($roots as$root){$id=self::id($root['id']);$observed=$payments[$id];self::keys($observed,['locked','root_payment_consistent']);
            if($observed['root_payment_consistent']!==true||!is_array($observed['locked']))self::fail();$payment=$observed['locked'];self::paid($root,$payment);
            $capture=$this->orders->capturePaymentApplicationFactsFromFacts(self::id($root['uid']),$id,$facts[$id]);if($capture['root']!==$root)self::fail();
            $raw=$rawFamilies[$id];self::keys($raw,['children','child_items','operations','ranges']);
            $family=['children'=>$raw['children'],'items'=>$raw['child_items'],'ranges'=>$raw['ranges'],'refunds'=>$refunds[$id]];
            $context=self::context($actions[$id],$refunds[$id],$raw['operations'],$raw['ranges'],$logs[$id]);
            $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$payment);
            $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
            $files=$this->files->readClaimedForRefunds($connection,$pdo,$root,$refunds[$id]);self::fileCorrespondence($refunds[$id],$files);
            $snapshot=['payment'=>$payment,'root'=>$root,'capture'=>$capture,'family'=>$family,'operations'=>$raw['operations'],
                'logs'=>$logs[$id],'actions'=>$actions[$id],'context'=>$context,'inspection'=>$inspection,'files'=>$files,'reviews'=>$reviews[$id]];
            self::bindReviews($snapshot);$out[$id]=$snapshot;$this->snapshot($connection,$pdo);
        }
        return $out;
    }

    private function write(ConnectionInterface $connection,PDO $pdo,Request $request,array $identity,string $kind,array $locatedRoot,
        ?int $itemId,?array $locatedReview,array $body,?MerchantContext $merchant,?PlatformScope $scope):array
    {
        $candidate=self::candidate($locatedRoot);$phase='before_transaction';$reviewId=$locatedReview===null?null:self::id($locatedReview['id']);
        try{
            $this->outside($connection,$pdo);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);
            $data=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$identity,$kind,$candidate,
                $itemId,$locatedReview,$body,$merchant,$scope,&$phase,&$reviewId):array{
                if($actual!==$connection)self::fail();$this->writer($connection,$pdo);$phase='payment';
                $payment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);$this->writer($connection,$pdo);
                $root=$this->families->lockRoot($pdo,$candidate);$this->writer($connection,$pdo);
                $this->writeAuthorization($connection,$pdo,$request,$identity,$kind,$root,$merchant,$scope);
                $phase='complete_prefix';$before=$this->writeSnapshot($connection,$pdo,$candidate,$root,$payment,false);
                $bindings=self::bindReviews($before);$saved=null;
                if($kind==='create'){
                    if($itemId===null||!isset($before['inspection']['source']['items'][$itemId]))self::fail();$saved=$bindings['items'][$itemId]??null;
                    // The actual saved content wins before a new-write admission or any attachment re-validation.
                    if($saved!==null){if(!self::sameBody($saved,$body))self::fail();$reviewId=self::id($saved['id']);
                        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);$this->writer($connection,$pdo);$phase='commit_outcome_unknown';
                        return self::view($saved,$itemId);}
                }else{
                    if($locatedReview===null)self::fail();$reviewId=self::id($locatedReview['id']);$bound=$bindings['ids'][$reviewId]??null;
                    if(!is_array($bound))self::fail();$saved=$bound['row'];$itemId=$bound['item_id'];
                    foreach(['id','oid','uid','unique','product_id','mer_id']as$field)if($saved[$field]!==$locatedReview[$field])self::fail();
                }
                self::paid($root,$payment);$phase='new_write';$attachments=[];
                if($kind==='create'){
                    if(!self::eligible($before,$itemId))self::fail();$attachments=$this->reviews->publicPictures($connection,$pdo,$body['pics']);
                    $profile=$this->reviews->customerProfile($connection,$pdo,$identity['actor_id']);$item=$before['inspection']['source']['items'][$itemId];
                    $public=self::publicItem($before,$itemId);$now=self::integer(time(),1,self::INT_MAX);
                    $insert=['uid'=>$identity['actor_id'],'oid'=>$candidate['root_order_id'],'unique'=>$item['unique'],
                        'product_id'=>self::id($item['product_id']),'reply_type'=>'product','product_score'=>$body['product_score'],
                        'service_score'=>$body['service_score'],'comment'=>$body['comment'],'pics'=>self::json($body['pics']),'add_time'=>$now,
                        'merchant_reply_content'=>'','merchant_reply_time'=>0,'is_del'=>0,'is_reply'=>0,'nickname'=>$profile['nickname'],
                        'avatar'=>$profile['avatar'],'suk'=>self::characters($public['sku_name'],255),'status'=>0,'mer_id'=>$candidate['mer_id']];
                    $after=$this->reviews->insert($connection,$pdo,$insert);$reviewId=self::id($after['id']);
                }elseif($kind==='reply'){
                    if($saved===null||self::integer($saved['status'],1,1)!==1||self::integer($saved['is_del'],0,0)!==0)self::fail();
                    $after=$this->reviews->reply($connection,$pdo,$saved,$body['content'],self::integer(time(),1,self::INT_MAX));
                }elseif($kind==='moderate'){
                    if($saved===null)self::fail();$after=$this->reviews->moderate($connection,$pdo,$saved,$body['status']);
                }else self::fail();
                $this->writer($connection,$pdo);
                if($kind!=='create'){
                    $changed=[];foreach($saved as$field=>$value)if($after[$field]!==$value)$changed[]=$field;
                    $this->audit->record($merchant===null?'admin':'merchant',$identity['actor_id'],$candidate['mer_id'],
                        $merchant===null?'marketplace-reviews-moderate':'review.reply','review',(string)$reviewId,'success',['changed_fields'=>$changed]);
                    $this->writer($connection,$pdo);
                }
                // Every item below is re-selected natively, in the same original lock order. No proposed row is a final fact.
                $phase='final_payment';$finalPayment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);$this->writer($connection,$pdo);
                $finalRoot=$this->families->lockRoot($pdo,$candidate);$this->writer($connection,$pdo);
                $final=$this->writeSnapshot($connection,$pdo,$candidate,$finalRoot,$finalPayment,true);
                foreach(['payment','root','capture','family','operations','logs','actions','context','inspection','files']as$key)if($final[$key]!==$before[$key])self::fail();
                $expected=$before['reviews'];$found=false;
                foreach($expected as&$row)if(self::id($row['id'])===$reviewId){if($found||$kind==='create')self::fail();$row=$after;$found=true;}unset($row);
                if($kind==='create'){if($found)self::fail();$expected[]=$after;}elseif(!$found)self::fail();
                if($final['reviews']!==$expected)self::fail();$finalBindings=self::bindReviews($final);$actualRow=$finalBindings['ids'][$reviewId]??null;
                if(!is_array($actualRow)||$actualRow['row']!==$after||$actualRow['item_id']!==$itemId)self::fail();
                if($kind==='create'&&$this->reviews->publicPictures($connection,$pdo,$body['pics'])!==$attachments)self::fail();
                if($kind==='create'&&!self::sameBody($actualRow['row'],$body))self::fail();
                $this->writeAuthorization($connection,$pdo,$request,$identity,$kind,$finalRoot,$merchant,$scope);$this->writer($connection,$pdo);
                $phase='commit_outcome_unknown';return self::view($actualRow['row'],$itemId);
            });
            $phase='post_commit_boundary';$this->outside($connection,$pdo);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);
        }catch(Throwable $error){
            try{Log::error('MARKETPLACE_REVIEW_OUTCOME_UNPROVED',['phase'=>$phase,'root_order_id'=>$candidate['root_order_id'],'review_id'=>$reviewId]);}catch(Throwable $ignored){}
            self::fail();
        }
        $data=self::present($data,'review');$this->sameIdentity($request,$identity,$kind,$merchant,$scope);return $data;
    }

    /** Caller holds payment/root/current authorization. This is a fixed capture, not another transaction framework. */
    private function writeSnapshot(ConnectionInterface $connection,PDO $pdo,array $candidate,array $root,array $payment,bool $final):array
    {
        $operations=$this->refunds->lockOperationsForRoot($connection,$pdo,$root);$this->writer($connection,$pdo);
        $capture=$this->orders->capturePaymentApplicationFacts($pdo,$candidate['uid'],$candidate['root_order_id']);$this->writer($connection,$pdo);
        $family=$this->families->lockFamily($pdo,$root);$this->writer($connection,$pdo);
        $logs=$this->refunds->lockStatusLogsForRoot($connection,$pdo,$root);$this->writer($connection,$pdo);
        $actions=$this->refunds->lockActionsForRoot($connection,$pdo,$root);$this->writer($connection,$pdo);
        if($capture['root']!==$root)self::fail();$context=self::context($actions,$family['refunds'],$operations,$family['ranges'],$logs);
        $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$payment);
        $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);$this->writer($connection,$pdo);
        $reviews=$this->reviews->lockForRoot($connection,$pdo,$root);$this->writer($connection,$pdo);$files=[];
        foreach($family['refunds']as$refund){$id=self::id($refund['id']);if(isset($files[$id]))self::fail();
            $files[$id]=$final?$this->files->readFinalClaimed($connection,$pdo,$root,$refund):$this->files->readClaimedForReplay($connection,$pdo,$root,$refund);$this->writer($connection,$pdo);}
        self::fileCorrespondence($family['refunds'],$files);
        return ['payment'=>$payment,'root'=>$root,'capture'=>$capture,'family'=>$family,'operations'=>$operations,'logs'=>$logs,
            'actions'=>$actions,'context'=>$context,'inspection'=>$inspection,'files'=>$files,'reviews'=>$reviews];
    }

    private static function bindReviews(array $snapshot):array
    {
        $source=$snapshot['inspection']['source'];$root=$snapshot['root'];if($source['capture']!==$snapshot['capture']||$source['root']!==$root)self::fail();
        $items=$source['items'];$byUnique=[];
        foreach($items as$id=>$item){if(!is_int($id)||$id!==self::id($item['id']))self::fail();$unique=$item['unique'];
            if(!is_string($unique)||preg_match('/\A[a-f0-9]{32}\z/D',$unique)!==1||isset($byUnique[$unique]))self::fail();$byUnique[$unique]=$id;}
        $bound=['items'=>[],'ids'=>[]];self::list($snapshot['reviews']);
        foreach($snapshot['reviews']as$row){$unique=$row['unique'];$itemId=$byUnique[$unique]??null;if(!is_int($itemId))self::fail();$item=$items[$itemId];
            self::stored($row,['oid'=>self::id($root['id']),'uid'=>self::id($root['uid']),'mer_id'=>self::integer($root['mer_id'],1,self::UINT_MAX),
                'product_id'=>self::id($item['product_id'])]);if($row['reply_type']!=='product'||$unique!==$item['unique'])self::fail();
            $id=self::id($row['id']);if(isset($bound['items'][$itemId])||isset($bound['ids'][$id]))self::fail();self::savedPictures($row['pics']);
            $bound['items'][$itemId]=$row;$bound['ids'][$id]=['row'=>$row,'item_id'=>$itemId];
        }
        return $bound;
    }
    private static function eligible(array $snapshot,int $itemId):bool
    {
        $item=$snapshot['inspection']['source']['items'][$itemId]??null;if(!is_array($item))self::fail();$q=self::integer($item['cart_num'],1,self::INT_MAX);$totals=[];
        foreach(['cancel','received']as$key){$quantity=0;self::list($snapshot['inspection']['history'][$key]);
            foreach($snapshot['inspection']['history'][$key]as$part)if(self::id($part['source_item_id'])===$itemId){
                $from=self::integer($part['unit_start'],0,$q-1);$to=self::integer($part['unit_end'],$from+1,$q);$quantity+=$to-$from;
                if($quantity>$q)self::fail();}$totals[$key]=$quantity;}
        // Full Facts already established disjoint C/R and exact item universes; cardinality is equivalent to N subset R here.
        return $q-$totals['cancel']>0&&$totals['received']===$q-$totals['cancel'];
    }
    private static function publicItem(array $snapshot,int $itemId):array
    {
        $item=$snapshot['inspection']['source']['commercial']['public_items'][$itemId]??null;if(!is_array($item))self::fail();
        self::keys($item,['id','product_id','product_name','sku_name','image','quantity','unit_price','line_price','postage_price']);
        if($item['id']!==$itemId)self::fail();return $item;
    }
    private static function view(array $row,int $itemId):array
    {
        $nickname=$row['nickname'];$masked=mb_strlen($nickname,'UTF-8')===1?'*':anonymity($nickname);
        if(!is_string($masked))self::fail();
        return ['id'=>self::id($row['id']),'root_order_id'=>self::id($row['oid']),'source_item_id'=>$itemId,'product_id'=>self::id($row['product_id']),
            'product_score'=>self::integer($row['product_score'],1,5),'service_score'=>self::integer($row['service_score'],1,5),
            'comment'=>$row['comment'],'pics'=>self::savedPictures($row['pics']),'add_time'=>self::integer($row['add_time'],0,self::INT_MAX),
            'merchant_reply_content'=>$row['merchant_reply_content'],'merchant_reply_time'=>self::integer($row['merchant_reply_time'],0,self::INT_MAX),
            'is_reply'=>self::integer($row['is_reply'],0,1),'status'=>self::integer($row['status'],0,2),'nickname'=>$masked,'avatar'=>$row['avatar'],'suk'=>$row['suk']];
    }
    private static function sameBody(array $row,array $body):bool
    {
        return self::integer($row['product_score'],1,5)===$body['product_score']&&self::integer($row['service_score'],1,5)===$body['service_score']
            &&$row['comment']===$body['comment']&&self::savedPictures($row['pics'])===$body['pics'];
    }
    private static function savedPictures(mixed $raw):array
    {
        if(!is_string($raw)||strlen($raw)>65535)self::fail();try{$pics=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
        return MarketplaceOrderReviewInput::pictures($pics);
    }
    private static function present(array $data,string $kind):array
    {
        $site=sys_config('site_url');if(!is_string($site))self::fail();
        $show=static function(array $view)use($site):array{
            $pics=set_file_url($view['pics'],$site);$avatar=$view['avatar']===''?'':set_file_url($view['avatar'],$site);
            if(!is_array($pics)||!array_is_list($pics)||count($pics)!==count($view['pics'])||!is_string($avatar))self::fail();
            foreach($pics as$picture)if(!is_string($picture))self::fail();$view['pics']=$pics;$view['avatar']=$avatar;return $view;};
        if($kind==='quote'){
            $thumb=get_thumb_water(['image'=>$data['item']['image']]);if(!is_array($thumb)||!is_string($thumb['image']??null))self::fail();$data['item']['image']=$thumb['image'];
            if($data['review']!==null)$data['review']=$show($data['review']);
        }elseif(str_ends_with($kind,'_page')){foreach($data['list']as&$review)$review=$show($review);unset($review);}
        else $data=$show($data);return $data;
    }

    private function writeAuthorization(ConnectionInterface $connection,PDO $pdo,Request $request,array $identity,string $kind,array $root,
        ?MerchantContext $merchant,?PlatformScope $scope):void
    {
        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);$this->writer($connection,$pdo);
        if($kind==='create'){
            if($identity['actor_type']!=='customer'||$identity['actor_id']!==self::id($root['uid']))self::fail();$this->families->lockCustomer($pdo,$identity['actor_id']);
        }elseif($kind==='reply'){
            if($merchant===null||$merchant->merId!==self::integer($root['mer_id'],1,self::UINT_MAX))self::fail();$this->employees->authorizeAction($merchant,'product.write',true);
        }elseif($kind==='moderate'){
            if($scope===null)self::fail();$this->platform->authorizeScope($scope,'marketplace-reviews-moderate');
        }else self::fail();$this->writer($connection,$pdo);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);
    }
    private function readAuthorization(Request $request,array $identity,string $kind,?MerchantContext $merchant,?PlatformScope $scope):void
    {
        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);
        if($merchant!==null)$this->employees->authorizeAction($merchant,'product.read',false);
        elseif($scope!==null)$this->platform->authorizeScope($scope,self::permission($kind));
    }
    private function auditRead(ConnectionInterface $connection,PDO $pdo,Request $request,array $identity,string $kind,PlatformScope $scope,array $resource):void
    {
        try{$this->outside($connection,$pdo);
            $connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$identity,$kind,$scope,$resource):void{
                if($actual!==$connection)self::fail();$this->writer($connection,$pdo);$this->sameIdentity($request,$identity,$kind,null,$scope);
                $this->platform->authorizeScope($scope,self::permission($kind));$this->audit->record('admin',$scope->adminId,$resource['mer'],self::permission($kind),'review',$resource['id'],'success');
                $this->writer($connection,$pdo);$this->sameIdentity($request,$identity,$kind,null,$scope);});
            $this->outside($connection,$pdo);$this->sameIdentity($request,$identity,$kind,null,$scope);
        }catch(Throwable $error){self::fail();}
    }
    private function wire(Request $request,string $kind,array $raw):array
    {
        $method=$kind==='create'?'POST':'PUT';
        if($request->method(true)!==$method||strtolower($request->contentType())!=='application/json'||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::fail();
        $content=$request->getContent();if(!is_string($content))self::fail();$bytes=MarketplaceOrderReviewInput::decode($content);
        $normalize=static fn(array $body):array=>match($kind){'create'=>MarketplaceOrderReviewInput::create($body),'reply'=>MarketplaceOrderReviewInput::reply($body),
            'moderate'=>MarketplaceOrderReviewInput::moderate($body),default=>self::fail()};
        $actual=$normalize($bytes);if(self::json($actual)!==self::json($normalize($raw)))self::fail();return $actual;
    }
    private function query(Request $request,bool $platform,array $query):array
    {
        if($request->method(true)!=='GET'||$request->getContent()!=='')self::fail();$raw=$request->get(false);if(!is_array($raw))self::fail();
        $actual=$platform?MarketplaceOrderReviewInput::platformPage($raw):MarketplaceOrderReviewInput::merchantPage($raw);
        $provided=$platform?MarketplaceOrderReviewInput::platformPage($query):MarketplaceOrderReviewInput::merchantPage($query);
        if($actual!==$provided)self::fail();return $actual;
    }
    private function detailRequest(Request $request):void
    {
        if($request->method(true)!=='GET'||$request->getContent()!==''||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::fail();
    }
    private function current(Request $request,string $application):string
    {
        if(app()->request!==$request||app()->http->getName()!==$application)self::fail();$fp=$request->verifiedTokenFingerprint();
        if(preg_match('/\A[a-f0-9]{64}\z/D',$fp)!==1)self::fail();return $fp;
    }
    private function customer(Request $request,int $uid):array
    {
        $fp=$this->current($request,'api');self::id($uid);if($request->isLogin()!==true||self::id($request->uid())!==$uid)self::fail();
        return ['app'=>'api','actor_type'=>'customer','actor_id'=>$uid,'session_sha256'=>$fp];
    }
    private function merchant(Request $request,MerchantContext $context,string $permission):array
    {
        $fp=$this->current($request,'merchantapi');if($request->merchantContext()!==$context)self::fail();$context->require($permission);
        self::integer($context->employeeId,1,self::UINT_MAX);self::integer($context->merId,1,self::UINT_MAX);
        return ['app'=>'merchantapi','actor_type'=>'employee','actor_id'=>$context->employeeId,'session_sha256'=>$fp];
    }
    private function administrator(Request $request,PlatformScope $scope,string $permission):array
    {
        $fp=$this->current($request,'adminapi');if($request->platformScope()!==$scope||$scope->permission!==$permission||$scope->targetMerId!==null
            ||$request->isAdminLogin()!==true||self::integer($request->adminId(),1,self::UINT_MAX)!==$scope->adminId)self::fail();
        return ['app'=>'adminapi','actor_type'=>'admin','actor_id'=>$scope->adminId,'session_sha256'=>$fp];
    }
    private function sameIdentity(Request $request,array $identity,string $kind,?MerchantContext $merchant,?PlatformScope $scope):void
    {
        $actual=match($identity['app']){'api'=>$this->customer($request,$identity['actor_id']),
            'merchantapi'=>$merchant===null?self::fail():$this->merchant($request,$merchant,$kind==='reply'?'product.write':'product.read'),
            'adminapi'=>$scope===null?self::fail():$this->administrator($request,$scope,self::permission($kind)),default=>self::fail()};
        if($actual!==$identity)self::fail();
    }
    private static function permission(string $kind):string
    {
        return match($kind){'platform_page'=>'marketplace-reviews-list','platform_detail'=>'marketplace-reviews-read','moderate'=>'marketplace-reviews-moderate',default=>self::fail()};
    }
    private function defaultConnection():array
    {
        $connection=Db::connect();if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}if(!$pdo instanceof PDO)self::fail();
        $this->outside($connection,$pdo);$this->families->assertAftersaleStructure($pdo);$this->outside($connection,$pdo);
        $this->reviews->assertStructure($connection,$pdo);$this->outside($connection,$pdo);return [$connection,$pdo];
    }
    private function connection(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    private function outside(ConnectionInterface $connection,PDO $pdo):void {$this->connection($connection,$pdo);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::fail();}
    private function snapshot(ConnectionInterface $connection,PDO $pdo):void {$this->connection($connection,$pdo);if($connection->transactionDepth()!==0||!$pdo->inTransaction())self::fail();}
    private function writer(ConnectionInterface $connection,PDO $pdo):void {$this->connection($connection,$pdo);if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::fail();}
    private static function candidate(array $root):array
    {
        self::root($root);return ['root_order_id'=>self::id($root['id']),'root_order_no'=>self::characters($root['order_id'],32),
            'uid'=>self::id($root['uid']),'mer_id'=>self::integer($root['mer_id'],1,self::UINT_MAX)];
    }
    private static function root(array $root):void
    {
        self::id($root['id']??null);self::id($root['uid']??null);self::integer($root['mer_id']??null,1,self::UINT_MAX);
        self::stored($root,['marketplace_trade_version'=>1,'pid'=>0]);
    }
    private static function paid(array $root,array $payment):void
    {
        self::root($root);self::stored($root,['paid'=>1,'is_cancel'=>0]);if(($payment['root']['paid']??null)!==1)self::fail();
        foreach($payment['root']as$key=>$value)self::stored($root,[$key=>$value]);
    }
    private static function context(array $actions,array $refunds,array $operations,array $ranges,array $logs):array
    {
        foreach([$actions,$refunds,$operations,$ranges,$logs]as$rows)self::list($rows);
        return ['schema'=>2,'cut_id'=>$actions===[]?null:self::big($actions[count($actions)-1]['id']),
            'actions'=>$actions,'refunds'=>$refunds,'operations'=>$operations,'ranges'=>$ranges,'logs'=>$logs];
    }
    private static function fileCorrespondence(array $refunds,array $files):void
    {
        $ids=[];foreach($refunds as$row)$ids[]=self::id($row['id']);if(array_keys($files)!==$ids)self::fail();
        foreach($refunds as$row){$list=$files[self::id($row['id'])];self::list($list);
            try{$images=json_decode($row['refund_img'],false,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
            if(!is_array($images)||!array_is_list($images)||count($images)!==count($list)||count($images)>9)self::fail();
            foreach($list as$index=>$file){self::keys($file,['id','uid','root_order_id','mer_id','refund_id','image_position','object_key','media_type','byte_size',
                    'width','height','content_sha256','upload_key','state','created_at','claim_expires_at','claimed_at','purged_at']);
                if(!is_string($images[$index])||self::big($file['id'])!==$images[$index])self::fail();
                self::stored($file,['uid'=>self::id($row['uid']),'root_order_id'=>self::id($row['marketplace_root_order_id']),
                    'mer_id'=>self::integer($row['mer_id'],1,self::UINT_MAX),'refund_id'=>self::id($row['id']),'image_position'=>$index]);
                if($file['state']!=='CLAIMED'||$file['purged_at']!==null||!is_string($file['content_sha256'])||strlen($file['content_sha256'])!==32)self::fail();
            }
        }
    }
    private static function json(array $value):string
    {
        try{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
    }
    private static function stored(array $row,array $expected):void
    {
        foreach($expected as$key=>$value){if(!array_key_exists($key,$row))self::fail();
            if(is_int($value)){if(self::integer($row[$key],0,self::UINT_MAX)!==$value)self::fail();}elseif($row[$key]!==$value)self::fail();}
    }
    private static function integer(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$max,0)<=0)$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function id(mixed $value):int {return self::integer($value,1,self::INT_MAX);}
    private static function big(mixed $value):string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1||bccomp($value,'18446744073709551615',0)>0)self::fail();return $value;
    }
    private static function characters(mixed $value,int $max):string
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)self::fail();return $value;
    }
    private static function list(mixed $value):void {if(!is_array($value)||!array_is_list($value))self::fail();}
    private static function keys(array $value,array $expected):void {$keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected)self::fail();}
    private static function fail():never {throw new AuthUnavailableException();}
}
