<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\PlatformChatAssetDao as Assets;
use app\model\service\StoreServiceLog;
use app\services\kefu\service\StoreServiceLogServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use crmeb\services\workerman\chat\ChatIdentity;
use PDO;
use stdClass;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\file\UploadedFile;
use Throwable;

/** Platform29 only. The original message/transfer callers retain all ACK, record and notification duties. */
final class PlatformChatAssetServices
{
    public function __construct(private Assets $assets,private PlatformChatRoomAccessServices $access,private PlatformChatReadTickets $tickets){}

    /** Server route metadata only: no body, actor, storage, database or guest-session issuance. */
    public static function isPrivateHttpRequest(\think\Request $request):bool
    {
        try{
            $app=app()->http->getName();$route=$request->rule();if($route===null)return false;
            $kind=$route->getOption('platform_chat_files');$base=match([$app,$kind]){
                ['api','api']=>'user/service/files',['kefuapi','kefu']=>'service/files',['kefuapi','guest']=>'tourist/files',default=>null};
            if($base===null)return false;$method=$request->method(true);$rule=trim(strtolower((string)$route->getRule()));
            $leaves=[['GET',$base.'/capabilities'],['POST',$base.'/<asset_id>/read-ticket'],['GET',$base.'/<asset_id>/content']];
            if($kind!=='guest')$leaves[]=['POST',$base];return in_array([$method,$rule],$leaves,true);
        }catch(Throwable $error){return false;}
    }

    public function capabilities(Request $request,string $kind):array
    {
        $this->route($request,$kind,'capabilities');$actor=$this->access->http($request,$kind);
        [$c,$p]=$this->assets->primary();$this->access->current($c,$p,$actor,0);$ready=false;
        $root=Config::get('marketplace.platform_chat.private_root');
        if(is_string($root)&&$root!==''){try{$this->assets->structure($c,$p,0);$this->storage();$ready=true;}catch(Throwable $error){$ready=false;}}
        return ['protocol'=>1,'private_writes_enabled'=>$kind!=='guest'&&Config::get('marketplace.platform_chat.private_writes_enabled')===true&&$ready,
            'private_read_supported'=>$ready];
    }
    public function upload(Request $request,string $kind):array
    {
        $receipt=self::receipt();$object=[];$storage=null;
        try{
            $this->route($request,$kind,'upload');self::writes();if($kind==='guest')self::fail();$actor=$this->access->http($request,$kind);
            $post=$request->post(false);$keys=array_keys($post);sort($keys,SORT_STRING);$expected=$kind==='api'?['filename','service_uid']:['filename','is_tourist','peer_uid'];sort($expected,SORT_STRING);
            if($keys!==$expected||$post['filename']!=='file')self::fail();
            $target=Assets::integer($post[$kind==='api'?'service_uid':'peer_uid'],-2147483647,4294967295);
            $hint=$kind==='api'?0:Assets::integer($post['is_tourist'],0,1);
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['file']||!$files['file'] instanceof UploadedFile)self::fail();
            [$c,$p]=$this->assets->primary();$session=$this->assets->session($c,$p,0);$user=$this->access->current($c,$p,$actor,0);
            $room=$this->access->room($c,$p,$actor,$target,$hint,0);$quota='start_uploads_'.($kind==='kefu'?$actor['service_id']:$actor['uid']);
            $done=false;$out=$c->transaction(function(ConnectionInterface $actual)use($c,$p,$request,$kind,$actor,$room,$target,$hint,$session,$user,$quota,$files,&$receipt,&$object,&$storage,&$done):array{
                if($actual!==$c)self::fail();$this->sameHttp($c,$p,$request,$kind,$actor,$session,$user,1);
                if($this->access->room($c,$p,$actor,$target,$hint,1)!==$room||self::counter(CacheService::get($quota,0))>=100)self::fail();
                [$bytes,$name]=self::uploadedBytes($files['file']);$storage=$this->storage();$physical=$storage->writeNew(Assets::roomKey($room),$bytes,$name,$object,$receipt);
                if($storage->readObject($physical)!==$bytes)self::fail();$this->sameHttp($c,$p,$request,$kind,$actor,$session,$user,1);
                if($this->access->room($c,$p,$actor,$target,$hint,1)!==$room)self::fail();$receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->assets->insertStaged($c,$p,$room,$this->access->creator($actor),$physical,$name);
                if($storage->readObject($this->physical($asset))!==$bytes)self::fail();$done=true;return ['asset'=>$asset,'bytes'=>$bytes];
            });
            if(!$done||!$storage instanceof PlatformChatPrivateStorage)self::fail();$this->sameHttp($c,$p,$request,$kind,$actor,$session,$user,0);
            if($this->assets->asset($c,$p,(string)$out['asset']['id'],0)!==$out['asset']||$storage->readObject($this->physical($out['asset']))!==$out['bytes'])self::fail();
            $receipt['database_committed']=true;$receipt['phase']='quota_update';$count=self::counter(CacheService::get($quota,0));if($count>=100)self::fail();
            if(CacheService::set($quota,$count+1,86400)!==true||self::counter(CacheService::get($quota,null))!==$count+1)self::fail();
            $this->sameHttp($c,$p,$request,$kind,$actor,$session,$user,0);$receipt['complete']=true;$receipt['phase']='complete';
            $id=(string)$out['asset']['id'];return ['name'=>$out['asset']['name'],'url'=>($kind==='api'?'/api/user':'/kefuapi').'/service/files/'.$id.'/content','attachment_ref'=>Assets::reference($id)];
        }catch(Throwable $error){
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof PlatformChatPrivateStorage)
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            if($receipt['database_committed']===null)$receipt['phase']='commit_outcome_unknown';throw new MerchantAttachmentOperationException($receipt);
        }
    }
    /** This is the only new main-message save leaf; no global save/autoReply override. */
    public function saveMessage(ChatIdentity $identity,int $target,bool $tourist,array $input,StoreServiceLogServices $logs):StoreServiceLog
    {
        $receipt=self::receipt();$receipt['physical_complete']=true;
        try{
            self::writes();if($identity->kind==='guest'||!Assets::isPrivate($input['msn']??null)||Assets::integer($input['msn_type']??null,1,6)!==3)self::fail();
            $actor=$this->access->ws($identity);[$c,$p]=$this->assets->primary();$session=$this->assets->session($c,$p,0);
            $room=$this->access->room($c,$p,$actor,$target,(int)$tourist,0);$complete=false;
            $model=$c->transaction(function(ConnectionInterface $actual)use($c,$p,$identity,$actor,$room,$target,$tourist,$session,$input,$logs,&$receipt,&$complete):StoreServiceLog{
                if($actual!==$c||$this->access->ws($identity)!==$actor||$this->assets->session($c,$p,1)!==$session
                    ||$this->access->room($c,$p,$actor,$target,(int)$tourist,1)!==$room)self::fail();$this->assets->structure($c,$p,1);
                $asset=$this->assets->asset($c,$p,Assets::referenceId($input['msn']),1);
                if($this->assets->assetRoom($asset)!==$room)self::fail();$this->access->sender($actor,$asset);
                $storage=$this->storage();$storage->readObject($this->physical($asset));
                if($this->access->ws($identity)!==$actor||$this->access->room($c,$p,$actor,$target,(int)$tourist,1)!==$room)self::fail();
                $receipt['phase']='message_insert';$receipt['database_committed']=null;$saved=$logs->save($input);
                if(!$saved instanceof StoreServiceLog)self::fail();$row=$this->assets->savedLog($c,$p,$saved,$input);
                $bound=$this->assets->bind($c,$p,$asset,$room,$row);$storage->readObject($this->physical($bound));
                if($this->access->ws($identity)!==$actor||$this->assets->session($c,$p,1)!==$session
                    ||$this->access->room($c,$p,$actor,$target,(int)$tourist,1)!==$room)self::fail();$complete=true;return $saved;
            });
            // Return the original Model immediately. The original caller must run ACK/record/notifications next.
            if(!$complete||!$model instanceof StoreServiceLog)self::fail();return $model;
        }catch(Throwable $error){$receipt['phase']=$receipt['database_committed']===null?'commit_outcome_unknown':'preflight';throw new MerchantAttachmentOperationException($receipt);}
    }
    public function prepareTransfer(Request $request,int $sourceUid,int $customerUid,int $targetUid,int $tourist,array $originalList,array $copyData):array
    {
        $actor=$this->access->http($request,'kefu');if($actor['uid']!==$sourceUid)self::fail();[$c,$p]=$this->assets->primary();
        $room=$this->access->room($c,$p,$actor,$customerUid,$tourist,0);$target=$this->access->service($c,$p,$targetUid,null,0,true);
        if($targetUid===$sourceUid||$targetUid===$customerUid)self::fail();
        $targetUser=$this->assets->one($c,$p,'user',['uid'=>$targetUid],0);
        if(Assets::integer($targetUser['status'],0,1)!==1||Assets::integer($targetUser['is_del'],0,1)!==0)self::fail();
        $destination=['customer_uid'=>$customerUid,'is_tourist'=>$tourist,'service_id'=>Assets::integer($target['id'],1,4294967295),'service_uid'=>$targetUid];
        Assets::room($destination);if(array_keys($originalList)!==array_keys($copyData)||$originalList===[])self::fail();
        $record=$this->assets->one($c,$p,'store_service_record',['user_id'=>$sourceUid,'to_uid'=>$customerUid,'is_tourist'=>$tourist],0);
        $aux=$this->assets->find($c,$p,'auxiliary',['binding_id'=>$sourceUid,'relation_id'=>$customerUid,'type'=>0],0);if(count($aux)>1)self::fail();
        $targetRecords=$this->assets->find($c,$p,'store_service_record',['user_id'=>$targetUid,'to_uid'=>$customerUid,'is_tourist'=>$tourist],0);
        $targetReverse=$this->assets->find($c,$p,'store_service_record',['user_id'=>$customerUid,'to_uid'=>$targetUid,'is_tourist'=>$tourist],0);
        if(count($targetRecords)>1||count($targetReverse)>1)self::fail();
        return ['request'=>$request,'actor'=>$actor,'connection'=>$c,'pdo'=>$p,'session'=>$this->assets->session($c,$p,0),
            'room'=>$room,'destination'=>$destination,'target'=>$target,'target_user'=>$targetUser,'source_record'=>$record,'source_aux'=>$aux,
            'target_records'=>$targetRecords,'target_reverse'=>$targetReverse,
            'original_list'=>$originalList,'copy_data'=>$copyData,'clones'=>[],'logs'=>[],'storage'=>null,'callback_complete'=>false];
    }
    /** Enters the existing transfer owner's callback, before its original saveAll/record/aux writes. */
    public function startTransfer(array &$ctx,ConnectionInterface $actual,array &$data):void
    {
        $c=$ctx['connection'];$p=$ctx['pdo'];if($actual!==$c||$data!==$ctx['copy_data'])self::fail();$this->sameTransfer($ctx,1);
        $this->assets->structure($c,$p,1);$actor=$ctx['actor'];$room=$ctx['room'];
        if($this->access->room($c,$p,$actor,$room['customer_uid'],$room['is_tourist'],1)!==$room
            ||$this->assets->one($c,$p,'store_service_record',['user_id'=>$actor['uid'],'to_uid'=>$room['customer_uid'],'is_tourist'=>$room['is_tourist']],1)!==$ctx['source_record']
            ||$this->assets->find($c,$p,'auxiliary',['binding_id'=>$actor['uid'],'relation_id'=>$room['customer_uid'],'type'=>0],1)!==$ctx['source_aux']
            ||$this->assets->find($c,$p,'store_service_record',['user_id'=>$ctx['destination']['service_uid'],'to_uid'=>$room['customer_uid'],'is_tourist'=>$room['is_tourist']],1)!==$ctx['target_records']
            ||$this->assets->find($c,$p,'store_service_record',['user_id'=>$room['customer_uid'],'to_uid'=>$ctx['destination']['service_uid'],'is_tourist'=>$room['is_tourist']],1)!==$ctx['target_reverse'])self::fail();
        $cutoff=$ctx['source_aux'][0]['update_time']??null;$logs=app()->make(StoreServiceLogServices::class);
        $query=$logs->search(['chat'=>[$actor['uid'],$room['customer_uid']],'is_tourist'=>$room['is_tourist']]);
        if($query->getConnection()!==$c)self::fail();if($cutoff)$query->where('add_time','>',$cutoff);
        if($query->lock(true)->select()->toArray()!==$ctx['original_list'])self::fail();$ctx['storage']=$this->storage();
        foreach($ctx['original_list']as$key=>$original){
            $raw=$this->assets->one($c,$p,'store_service_log',['id'=>Assets::integer($original['id']??null,1,2147483647)],1);
            $projected=$raw;$projected['add_time']=$raw['add_time']?date('Y-m-d H:i:s',Assets::integer($raw['add_time'],1,4294967295)):'';
            if($projected!==$original)self::fail();
            if(Assets::integer($raw['msn_type'],1,6)!==3||!Assets::isPrivate($raw['msn']))continue;
            $id=Assets::referenceId($raw['msn']);$source=$this->assets->asset($c,$p,$id,1);
            if($source['state']!=='BOUND')self::fail();$this->assets->message($source,$room,$raw);
            if(!isset($ctx['clones'][$id])){
                $bytes=$ctx['storage']->readObject($this->physical($source));$this->sameTransfer($ctx,1);
                $ctx['clones'][$id]=['source'=>$source,'asset'=>null,'object'=>[],'receipt'=>self::receipt(),'bound'=>null];
                $clone=&$ctx['clones'][$id];$physical=$ctx['storage']->writeNew(Assets::roomKey($ctx['destination']),$bytes,$source['real_name'],$clone['object'],$clone['receipt']);
                if($ctx['storage']->readObject($physical)!==$bytes)self::fail();unset($bytes);$this->sameTransfer($ctx,1);
                $clone['receipt']['phase']='transfer_metadata_insert';$clone['receipt']['database_committed']=null;
                $clone['asset']=$this->assets->insertStaged($c,$p,$ctx['destination'],['uid'=>null,'service_id'=>$actor['service_id']],$physical,$source['real_name']);unset($clone);
            }
            if($ctx['clones'][$id]['source']!==$source)self::fail();$data[$key]['msn']=Assets::reference((string)$ctx['clones'][$id]['asset']['id']);
        }
        if($ctx['clones']===[])self::fail();$ctx['copy_data']=$data;$this->sameTransfer($ctx,1);
    }
    /** Uses original saveAll's actual keyed Models, never a MAX/new-pair query or guessed lineage. */
    public function bindTransfer(array &$ctx,mixed $models,array $data):void
    {
        $this->sameTransfer($ctx,1);if(!$models instanceof \think\Collection||$data!==$ctx['copy_data'])self::fail();
        $all=$models->all();if(array_keys($all)!==array_keys($data))self::fail();
        foreach($all as$key=>$model){
            if(!$model instanceof StoreServiceLog)self::fail();$row=$this->assets->savedLog($ctx['connection'],$ctx['pdo'],$model,$data[$key]);$ctx['logs'][$key]=$row;
            $old=$ctx['original_list'][$key];if(Assets::integer($old['msn_type'],1,6)!==3||!Assets::isPrivate($old['msn']))continue;
            $id=Assets::referenceId($old['msn']);$clone=&$ctx['clones'][$id];$selected=$clone['bound']??$clone['asset'];
            $clone['bound']=$this->assets->bind($ctx['connection'],$ctx['pdo'],$selected,$ctx['destination'],$row);
            $ctx['storage']->readObject($this->physical($clone['bound']));unset($clone);
        }
        foreach($ctx['clones']as$clone)if($clone['bound']===null)self::fail();$this->sameTransfer($ctx,1);
    }
    public function completeTransferCallback(array &$ctx):void
    {
        $this->sameTransfer($ctx,1);$room=$ctx['destination'];
        $ctx['record_after']=$this->assets->one($ctx['connection'],$ctx['pdo'],'store_service_record',
            ['user_id'=>$room['service_uid'],'to_uid'=>$room['customer_uid'],'is_tourist'=>$room['is_tourist']],1);
        $ctx['reverse_after']=$this->assets->find($ctx['connection'],$ctx['pdo'],'store_service_record',
            ['user_id'=>$room['customer_uid'],'to_uid'=>$room['service_uid'],'is_tourist'=>$room['is_tourist']],1);
        $ctx['aux_after']=$this->assets->find($ctx['connection'],$ctx['pdo'],'auxiliary',
            ['binding_id'=>$ctx['actor']['uid'],'relation_id'=>$room['customer_uid'],'type'=>0],1);
        if(count($ctx['aux_after'])!==1||$ctx['logs']===[])self::fail();$ctx['callback_complete']=true;
    }
    /** Must run only after both original Channel attempts; false write flag does not disable this duty. */
    public function transferCommitted(array $ctx):void
    {
        try{
            if($ctx['callback_complete']!==true)self::fail();$this->sameTransfer($ctx,0);$c=$ctx['connection'];$p=$ctx['pdo'];$r=$ctx['destination'];
            foreach($ctx['logs']as$log)if($this->assets->one($c,$p,'store_service_log',['id'=>Assets::integer($log['id'],1,2147483647)],0)!==$log)self::fail();
            foreach($ctx['clones']as$clone){
                if($this->assets->asset($c,$p,(string)$clone['source']['id'],0)!==$clone['source']
                    ||$this->assets->asset($c,$p,(string)$clone['bound']['id'],0)!==$clone['bound'])self::fail();
                $ctx['storage']->readObject($this->physical($clone['source']));$ctx['storage']->readObject($this->physical($clone['bound']));
            }
            if($this->assets->one($c,$p,'store_service_record',['user_id'=>$r['service_uid'],'to_uid'=>$r['customer_uid'],'is_tourist'=>$r['is_tourist']],0)!==$ctx['record_after']
                ||$this->assets->find($c,$p,'store_service_record',['user_id'=>$r['customer_uid'],'to_uid'=>$r['service_uid'],'is_tourist'=>$r['is_tourist']],0)!==$ctx['reverse_after']
                ||$this->assets->one($c,$p,'store_service_record',['user_id'=>$ctx['actor']['uid'],'to_uid'=>$r['customer_uid'],'is_tourist'=>$r['is_tourist']],0)!==$ctx['source_record']
                ||$this->assets->find($c,$p,'auxiliary',['binding_id'=>$ctx['actor']['uid'],'relation_id'=>$r['customer_uid'],'type'=>0],0)!==$ctx['aux_after'])self::fail();
            $this->sameTransfer($ctx,0);
        }catch(Throwable $error){throw $this->transferUnknown($ctx);}
    }
    public function transferUnknown(array $ctx):MerchantAttachmentOperationException
    {
        $out=self::receipt();$sqlAttempted=false;
        foreach($ctx['clones']as$clone)if($clone['receipt']['database_committed']!==false)$sqlAttempted=true;
        $out['database_committed']=$sqlAttempted?null:false;$out['phase']=$sqlAttempted?'commit_outcome_unknown':'preflight';
        if(!$sqlAttempted&&$ctx['storage'] instanceof PlatformChatPrivateStorage){
            foreach($ctx['clones']as&$clone)if($clone['object']!==[]&&$clone['receipt']['physical_complete']===true){
                try{$ctx['storage']->cleanupNew($clone['object'],$clone['receipt']);}
                catch(Throwable $ignored){$clone['receipt']['cleanup_complete']=false;}
            }unset($clone);
        }
        $out['physical_complete']=$ctx['clones']!==[];
        $allCleanup=$ctx['clones']!==[];
        foreach($ctx['clones']as$clone){$r=$clone['receipt'];
            if($r['physical_changed']===true)$out['physical_changed']=true;
            elseif($r['physical_changed']===null&&$out['physical_changed']!==true)$out['physical_changed']=null;
            if($r['physical_complete']!==true)$out['physical_complete']=false;
            if($r['cleanup_complete']===false)$out['cleanup_complete']=false;
            if($r['cleanup_complete']!==true)$allCleanup=false;
        }
        if($allCleanup&&$out['cleanup_complete']!==false)$out['cleanup_complete']=true;
        return new MerchantAttachmentOperationException($out);
    }
    private function sameTransfer(array $ctx,int $depth):void
    {
        $c=$ctx['connection'];$p=$ctx['pdo'];
        if($this->access->http($ctx['request'],'kefu')!==$ctx['actor']||$this->assets->session($c,$p,$depth)!==$ctx['session'])self::fail();
        $this->access->current($c,$p,$ctx['actor'],$depth);
        if($this->access->service($c,$p,$ctx['destination']['service_uid'],$ctx['destination']['service_id'],$depth,true)!==$ctx['target']
            ||$this->assets->one($c,$p,'user',['uid'=>$ctx['destination']['service_uid']],$depth)!==$ctx['target_user'])self::fail();
    }
    public function readTicket(Request $request,string $kind,string $id):array
    {
        $this->route($request,$kind,'ticket',$id);$actor=$this->access->http($request,$kind);$raw=$request->getContent();
        if(strtolower($request->contentType())!=='application/json'||!is_string($raw)||strlen($raw)>4096)self::fail();
        $decoded=json_decode($raw,false,8,JSON_THROW_ON_ERROR);if(!$decoded instanceof stdClass)self::fail();$body=get_object_vars($decoded);
        if($body===['mode'=>'preview'])$message=null;
        else{
            $keys=array_keys($body);sort($keys,SORT_STRING);if($keys!==['message_id','mode']||$body['mode']!=='message'||!is_string($body['message_id']))self::fail();
            $message=Assets::integer($body['message_id'],1,2147483647);if((string)$message!==$body['message_id'])self::fail();
        }
        [$c,$p]=$this->assets->primary();$session=$this->assets->session($c,$p,0);$frame=$this->frame($c,$p,$actor,$id,$message);
        $binding=$this->binding($actor,$frame,$message);$out=$this->tickets->issue($binding);
        if($this->access->http($request,$kind)!==$actor||$this->assets->session($c,$p,0)!==$session||$this->frame($c,$p,$actor,$id,$message)!==$frame)self::fail();
        return ['ticket'=>$out['read_ticket'],'expires_in_ms'=>$out['expires_at_ms']-$out['observed_at_ms']];
    }
    public function content(Request $request,string $kind,string $id):array
    {
        $this->route($request,$kind,'content',$id);if($request->getContent()!=='')self::fail();$actor=$this->access->http($request,$kind);
        $ticket=$request->header('X-Platform-Chat-Read-Ticket','');if(!is_string($ticket))self::fail();$stored=$this->tickets->stored($ticket);
        $message=$stored['binding']['message_id'];[$c,$p]=$this->assets->primary();$session=$this->assets->session($c,$p,0);
        $frame=$this->frame($c,$p,$actor,$id,$message);$binding=$this->binding($actor,$frame,$message);
        if($binding!==$stored['binding'])self::fail();$bytes=$this->storage()->readObject($this->physical($frame['asset']));
        if($this->access->http($request,$kind)!==$actor||$this->assets->session($c,$p,0)!==$session||$this->frame($c,$p,$actor,$id,$message)!==$frame)self::fail();
        $this->tickets->consume($ticket,$binding,$stored['receipt']);
        if($this->access->http($request,$kind)!==$actor||$this->frame($c,$p,$actor,$id,$message)!==$frame)self::fail();
        $asset=$frame['asset'];return ['bytes'=>$bytes,'media_type'=>$asset['media_type'],'byte_size'=>strlen($bytes),
            'file_name'=>'platform-chat-'.$id.'.'.pathinfo($asset['object_key'],PATHINFO_EXTENSION)];
    }
    private function frame(ConnectionInterface $c,PDO $p,array $actor,string $id,?int $message):array
    {
        $this->assets->structure($c,$p,0);$asset=$this->assets->asset($c,$p,$id,0);$room=$this->assets->assetRoom($asset);$this->access->reader($c,$p,$actor,$room,0);
        if($message===null){$this->access->preview($actor,$asset);$log=null;}
        else{if($asset['state']!=='BOUND')self::fail();$log=$this->assets->one($c,$p,'store_service_log',['id'=>$message],0);$this->assets->message($asset,$room,$log);}
        return ['asset'=>$asset,'room'=>$room,'log'=>$log];
    }
    private function binding(array $actor,array $frame,?int $message):array
    {
        $r=$frame['room'];return ['reader_kind'=>$message===null?'preview':'message','app'=>$actor['kind']==='api'?'api':'kefuapi','actor_type'=>$actor['kind'],
            'actor_id'=>$actor['kind']==='kefu'?$actor['service_id']:$actor['uid'],'session_sha256'=>$actor['session_sha256'],
            'customer_uid'=>$r['customer_uid'],'is_tourist'=>$r['is_tourist'],'service_id'=>$r['service_id'],'service_uid'=>$r['service_uid'],
            'asset_id'=>(string)$frame['asset']['id'],'content_sha256'=>bin2hex($frame['asset']['content_sha256']),'message_id'=>$message,'purpose'=>'platform_chat_image'];
    }
    private function sameHttp(ConnectionInterface $c,PDO $p,Request $r,string $kind,array $actor,array $session,array $user,int $depth):void
    {
        if($this->access->http($r,$kind)!==$actor||$this->assets->session($c,$p,$depth)!==$session||$this->access->current($c,$p,$actor,$depth)!==$user)self::fail();
    }
    private function physical(array $asset):array
    {
        return ['room_key'=>Assets::roomKey($this->assets->assetRoom($asset)),'object_key'=>$asset['object_key'],'media_type'=>$asset['media_type'],
            'byte_size'=>Assets::integer($asset['byte_size'],1,52428800),'width'=>Assets::integer($asset['width'],1,4294967295),
            'height'=>Assets::integer($asset['height'],1,4294967295),'content_sha256'=>$asset['content_sha256']];
    }
    private function storage():PlatformChatPrivateStorage {return app()->make(PlatformChatPrivateStorage::class);}
    private function route(Request $r,string $kind,string $leaf,?string $id=null):void
    {
        $base=$kind==='api'?'user/service/files':($kind==='kefu'?'service/files':'tourist/files');
        $rule=$base.match($leaf){'upload'=>'','capabilities'=>'/capabilities','ticket'=>'/<asset_id>/read-ticket','content'=>'/<asset_id>/content',default=>self::fail()};
        $method=in_array($leaf,['upload','ticket'],true)?'POST':'GET';
        if($r!==app()->request||$r->method(true)!==$method||$r->rule()->getOption('platform_chat_files')!==$kind
            ||trim(strtolower((string)$r->rule()->getRule()))!==$rule||$r->get(false)!==[]||$r->server('QUERY_STRING','')!=='')self::fail();
        if($id!==null){Assets::assetId($id);if($r->route('asset_id',null,'')!==$id)self::fail();}
        if($leaf==='upload'&&($kind==='guest'||strtolower($r->contentType())!=='multipart/form-data'))self::fail();
        if($leaf==='capabilities'&&$r->getContent()!=='')self::fail();
    }
    private static function uploadedBytes(UploadedFile $file):array
    {
        $path=$file->getPathname();$maximum=\think\facade\Config::get('upload.filesize');
        if(!is_int($maximum)||$maximum<1||!$file->isValid()||!is_uploaded_file($path))self::fail();
        $maximum=min($maximum,52428800);clearstatcache(true,$path);$before=lstat($path);
        if(!is_array($before)||is_link($path)||($before['mode']&0170000)!==0100000||$before['nlink']!==1
            ||!is_int($before['size'])||$before['size']<1||$before['size']>$maximum)self::fail();
        $handle=fopen($path,'rb');if(!is_resource($handle))self::fail();
        try{
            $opened=fstat($handle);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if(!is_array($opened)||$opened[$key]!==$before[$key])self::fail();
            $bytes='';while(strlen($bytes)<$before['size']){
                $part=fread($handle,min(65536,$before['size']-strlen($bytes)));if(!is_string($part)||$part==='')self::fail();$bytes.=$part;
            }
            if(fread($handle,1)!==''||!feof($handle))self::fail();$after=fstat($handle);clearstatcache(true,$path);$atPath=lstat($path);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
                if(!is_array($after)||!is_array($atPath)||is_link($path)||$after[$key]!==$before[$key]||$atPath[$key]!==$before[$key])self::fail();
            $name=$file->getOriginalName();if(!is_string($name)||$name==='')self::fail();return [$bytes,$name];
        }finally{if(fclose($handle)!==true)self::fail();}
    }
    private static function writes():void {if(Config::get('marketplace.platform_chat.private_writes_enabled')!==true)self::fail();}
    private static function counter(mixed $n):int {return Assets::integer($n,0,100);}
    public static function safeReceipt(array $v):array
    {
        $keys=array_keys($v);sort($keys,SORT_STRING);
        if($keys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||!is_bool($v['complete'])||!is_bool($v['physical_complete'])||!is_string($v['phase'])||preg_match('/\A[a-z_]{1,64}\z/D',$v['phase'])!==1
            ||!in_array($v['physical_changed'],[null,false,true],true)||!in_array($v['database_committed'],[null,false,true],true)
            ||!in_array($v['cleanup_complete'],[null,false,true],true))self::fail();return $v;
    }
    public static function receipt():array {return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];}
    private static function fail():never {throw new AuthUnavailableException();}
}
