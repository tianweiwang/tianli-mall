<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerLegacyRefundAssetDao as Assets;
use app\services\kefu\service\StoreServiceServices;
use app\services\order\DeliveryServiceServices;
use app\services\system\store\SystemStoreStaffServices;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use stdClass;
use think\db\ConnectionInterface;
use Throwable;

/** Complete ordinary reads for the actual v0 refund consumers; tickets never grant business access. */
final class CustomerLegacyRefundReadServices
{
    public function __construct(private Assets $assets,private CustomerLegacyRefundReadTickets $tickets,
        private CustomerLegacyRefundPlatformAccessServices $platform){}

    public function customerApplication(Request $request,int $uid,int $orderId,string $assetId):array
    {return $this->read($request,'application_preview',$uid,$orderId,$assetId);}
    public function customerRefund(Request $request,int $uid,int $refundId,string $assetId):array
    {return $this->read($request,'customer_refund',$uid,$refundId,$assetId);}
    public function mobileRefund(Request $request,int $actor,int $refundId,string $assetId):array
    {return $this->read($request,'mobile_refund',$actor,$refundId,$assetId);}
    public function platformRefund(Request $request,int $refundId,string $assetId):array
    {
        $reader=$request->route('reader_kind',null,'');
        if(!is_string($reader)||!in_array($reader,['list','detail'],true))self::fail();
        return $this->read($request,'platform_'.$reader,null,$refundId,$assetId);
    }

    private function read(Request $request,string $kind,?int $actor,int $contextId,string $assetId):array
    {
        try{
            $input=$this->input($request,$kind,$contextId,$assetId);$identity=$this->identity($request,$kind,$actor,$contextId);
            [$connection,$pdo]=$this->assets->primary();$frame=$this->frame($connection,$pdo,$identity,$contextId,$assetId);
            $asset=$frame['asset'];$binding=$identity+['uid'=>Assets::integer($asset['uid'],1,2147483647),'asset_id'=>$assetId,
                'content_sha256'=>bin2hex($asset['content_sha256']),'root_order_id'=>Assets::integer($asset['root_order_id'],1,2147483647),
                'order_id'=>Assets::integer($frame['family']['order']['id'],1,2147483647),
                'refund_id'=>$frame['refund']===null?null:Assets::integer($frame['refund']['id'],1,2147483647),'purpose'=>$asset['purpose']];
            $peek=$input['content']?$this->tickets->peek($input['ticket'],$binding):null;
            $storage=app()->make(CustomerLegacyRefundPrivateStorage::class);
            $bytes=$storage->readObject(CustomerLegacyRefundAssetServices::physical($asset));
            $this->same($connection,$pdo,$request,$kind,$actor,$contextId,$assetId,$identity,$frame);
            if(!$input['content']){
                $ticket=$this->tickets->issue($binding);
                $this->same($connection,$pdo,$request,$kind,$actor,$contextId,$assetId,$identity,$frame);
                return $ticket;
            }
            $this->tickets->consume($input['ticket'],$binding,$peek);
            // Do not restore a consumed ticket or issue another one after a failed/uncertain publication.
            $this->same($connection,$pdo,$request,$kind,$actor,$contextId,$assetId,$identity,$frame);
            return ['bytes'=>$bytes,'media_type'=>$asset['media_type'],'byte_size'=>strlen($bytes),
                'file_name'=>'legacy-refund-'.$assetId.'.'.pathinfo($asset['object_key'],PATHINFO_EXTENSION)];
        }catch(Throwable $error){self::fail();}
    }

    private function frame(ConnectionInterface $connection,PDO $pdo,array $identity,int $id,string $assetId):array
    {
        $kind=$identity['reader_kind'];$actor=$identity['actor_id'];$owner=$actor;$refund=null;$user=null;
        if($kind==='application_preview'){
            $user=$this->assets->user($connection,$pdo,$actor,0);
            $context=$this->assets->application($connection,$pdo,$id,$actor,0);$family=$context['family'];
        }else{
            $scope=in_array($kind,['customer_refund'],true)?$actor:null;
            $context=$this->assets->refund($connection,$pdo,$id,$scope,0,false);$refund=$context['refund'];$family=$context['family'];
            $owner=Assets::integer($refund['uid'],1,2147483647);
            if($kind==='platform_list'&&(Assets::integer($refund['is_cancel'],0,1)!==0||Assets::integer($refund['is_system_del'],0,1)!==0))self::fail();
            if($kind==='customer_refund'||$kind==='mobile_refund')$user=$this->assets->user($connection,$pdo,$actor,0);
        }
        $asset=$this->assets->asset($connection,$pdo,$assetId,$owner,0);
        if(Assets::integer($asset['root_order_id'],1,2147483647)!==Assets::integer($family['root']['id'],1,2147483647))self::fail();
        $ref=Assets::ref($asset['purpose'],$assetId);
        if($refund===null){
            if($asset['purpose']!=='legacy_refund_evidence'||$asset['state']!=='STAGED'||$asset['refund_id']!==null)self::fail();
        }elseif($asset['state']==='BOUND'){
            if(Assets::integer($asset['refund_id'],1,2147483647)!==$id||!in_array($ref,Assets::images($refund['refund_img']),true))self::fail();
        }else{
            // Only the real owner's currently allowed return draft can expose an unbound shipping picture.
            if($kind!=='customer_refund'||$asset['purpose']!=='refund_shipping_proof'
                ||Assets::integer($asset['refund_id'],1,2147483647)!==$id||Assets::integer($refund['refund_type'],0,6)!==4)self::fail();
            if($this->assets->refund($connection,$pdo,$id,$actor,0,true)!==$context)self::fail();
        }
        return ['family'=>$family,'refund'=>$refund,'asset'=>$asset,'user'=>$user];
    }

    private function same(ConnectionInterface $connection,PDO $pdo,Request $request,string $kind,?int $actor,int $id,string $assetId,array $identity,array $frame):void
    {
        $this->assets->native($connection,$pdo,0);
        if($this->identity($request,$kind,$actor,$id)!==$identity||$this->frame($connection,$pdo,$identity,$id,$assetId)!==$frame)self::fail();
    }

    private function identity(Request $request,string $kind,?int $actor,int $id):array
    {
        if(str_starts_with($kind,'platform_')){
            $admin=$this->platform->authorizeRequest($request,$id);
            if($kind!=='platform_'.$admin['reader_kind'])self::fail();
            return ['reader_kind'=>$kind,'app'=>'adminapi','actor_type'=>'admin','actor_id'=>$admin['id'],
                'session_sha256'=>$request->verifiedTokenFingerprint()];
        }
        if($actor===null)self::fail();$fingerprint=CustomerLegacyRefundAssetServices::identity($request,$actor);
        if($kind==='mobile_refund'){
            // Re-read exactly the three original CustomerMiddleware qualifications; no withRule exception.
            $services=app()->make(StoreServiceServices::class);$staff=app()->make(SystemStoreStaffServices::class);
            $delivery=app()->make(DeliveryServiceServices::class);
            if(!$services->checkoutIsService(['uid'=>$actor,'status'=>1,'customer'=>1])&&!$staff->verifyStatus($actor)
                &&!$delivery->checkoutIsService($actor))self::fail();
        }elseif(!in_array($kind,['application_preview','customer_refund'],true))self::fail();
        return ['reader_kind'=>$kind,'app'=>'api','actor_type'=>'customer','actor_id'=>$actor,'session_sha256'=>$fingerprint];
    }

    private function input(Request $request,string $kind,int $id,string $assetId):array
    {
        Assets::assetId($assetId);Assets::integer($id,1,2147483647);$method=$request->method(true);
        switch($kind){
            case 'application_preview':$app='api';$base='order/refund/legacy/orders/<order_id>/materials/<asset_id>';$routeKey='order_id';$option='application';break;
            case 'customer_refund':$app='api';$base='order/refund/legacy/<refund_id>/materials/<asset_id>';$routeKey='refund_id';$option='customer';break;
            case 'mobile_refund':$app='api';$base='admin/refund_order/detail/<refund_id>/materials/<asset_id>';$routeKey='refund_id';$option='mobile';break;
            case 'platform_list':case 'platform_detail':$app='adminapi';$base='refund/<reader_kind>/<refund_id>/materials/<asset_id>';$routeKey='refund_id';$option='platform';break;
            default:self::fail();
        }
        if($request!==app()->request||app()->http->getName()!==$app||!in_array($method,['POST','GET'],true)
            ||trim(strtolower((string)$request->rule()->getRule()))!==$base.($method==='POST'?'/read-ticket':'/content')
            ||$request->rule()->getOption('legacy_refund_private_reader')!==$option||$request->route('asset_id',null,'')!==$assetId
            ||Assets::integer($request->route($routeKey,null,''),1,2147483647)!==$id||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::fail();
        $raw=$request->getContent();
        if($method==='POST'){
            if(strtolower($request->contentType())!=='application/json'||!is_string($raw)||$raw===''||strlen($raw)>65536||preg_match('//u',$raw)!==1)self::fail();
            try{$body=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
            if(!$body instanceof stdClass||get_object_vars($body)!==['schema'=>1])self::fail();return ['content'=>false,'ticket'=>null];
        }
        $ticket=$request->header('X-Legacy-Refund-Read-Ticket','');
        if($raw!==''||!is_string($ticket)||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)!==1)self::fail();
        return ['content'=>true,'ticket'=>$ticket];
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
