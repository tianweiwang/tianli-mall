<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\Request;
use app\dao\marketplace\order\MarketplaceAftersaleFileDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\attachment\MarketplaceAftersaleFileStorage;
use app\services\marketplace\attachment\MarketplaceAftersaleReadTickets;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\PlatformAccessServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use stdClass;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;
use think\file\UploadedFile;
use think\Response;
use Throwable;

/** Private evidence only. No route registration, public attachment fallback, financial permission or cleanup is implied. */
final class MarketplaceAftersaleFileServices
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const MAX_BYTES=5242880;
    private const TICKET_PERMISSION='marketplace-aftersale-files-ticket';
    private const READ_PERMISSION='marketplace-aftersale-files-read';

    public function __construct(private MarketplaceAftersaleFileDao $files,private EmployeeAuthServices $employees,
        private PlatformAccessServices $platform,private AuditLogServices $audit,private MarketplaceAftersaleReadTickets $tickets,
        private MarketplaceOrderFulfillmentDao $structure){}

    public function uploadForCustomer(Request $request,int $actualUid,mixed $rootId): array
    {
        $identity=$this->customer($request,$actualUid); $id=self::id($rootId); $this->queryEmpty($request);
        if($request->method(true)!=='POST'||strtolower($request->contentType())!=='multipart/form-data') self::unavailable();
        $fields=$request->post(false); if(!is_array($fields)) self::unavailable(); self::keys($fields,['schema','upload_key']);
        if(!in_array($fields['schema'],[1,'1'],true)) self::unavailable(); self::uploadKey($fields['upload_key']);
        $uploads=$request->file(); if(!is_array($uploads)) self::unavailable(); self::keys($uploads,['file']);
        if(!$uploads['file'] instanceof UploadedFile||!$uploads['file']->isValid()) self::unavailable();
        [$connection,$pdo]=$this->defaultConnection();
        try{
            $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$identity,$actualUid,$id,$fields,$uploads):array{
                $this->inside($actual,$connection,$pdo); $this->sameIdentity($identity,$this->customer($request,$actualUid));
                $this->lockCustomer($connection,$pdo,$actualUid); $root=$this->lockRoot($connection,$pdo,$id);
                if(self::integer($root['uid']??null,1,self::INT_MAX)!==$actualUid) self::unavailable();
                $refunds=$this->lockRefunds($connection,$pdo,$root);
                // Only a real authenticated owner root reaches private storage or even uploaded-byte inspection.
                [$bytes,$name]=$this->uploadedBytes($uploads['file']); $disk=$this->disk(); $inspection=$disk->inspectUpload($bytes,$name);
                $row=$this->files->findUploadForRoot($connection,$pdo,$root,$actualUid,$fields['upload_key']);
                if($row!==null){
                    $this->availableForCustomer($row);
                    if($row['state']==='CLAIMED') $this->boundFile($connection,$pdo,$root,$refunds,$row);
                    $this->sameObject($row,$inspection);
                    if($disk->readObject($row)!==$bytes) self::unavailable();
                }else{
                    $stored=$disk->writeNew($id,$bytes,$name);
                    $row=$this->files->insertStaged($connection,$pdo,$root,$actualUid,$fields['upload_key'],$stored);
                    if($disk->readObject($row)!==$bytes) self::unavailable();
                }
                $fresh=$this->files->findUploadForRoot($connection,$pdo,$root,$actualUid,$fields['upload_key']);
                if($fresh!==$row) self::unavailable();
                $this->sameIdentity($identity,$this->customer($request,$actualUid)); $this->inside($actual,$connection,$pdo);
                return self::fileView($row);
            });
            $this->outside($connection,$pdo); $this->sameIdentity($identity,$this->customer($request,$actualUid)); return $result;
        }catch(Throwable $error){
            // No filesystem delete: rollback/commit observation may be uncertain, and the real key must remain retryable.
            self::unavailable();
        }
    }

    public function ticketForCustomer(Request $request,int $actualUid,string $fileId): array
    {
        $identity=$this->customer($request,$actualUid); $this->ticketRequest($request);
        return $this->publishTicket($request,$identity,$fileId,null,null);
    }
    public function contentForCustomer(Request $request,int $actualUid,string $fileId): Response
    {
        $identity=$this->customer($request,$actualUid); $this->contentRequest($request);
        return $this->publishContent($request,$identity,$fileId,null,null);
    }
    public function ticketForMerchant(Request $request,MerchantContext $context,string $fileId): array
    {
        $identity=$this->merchant($request,$context); $this->ticketRequest($request);
        return $this->publishTicket($request,$identity,$fileId,$context,null);
    }
    public function contentForMerchant(Request $request,MerchantContext $context,string $fileId): Response
    {
        $identity=$this->merchant($request,$context); $this->contentRequest($request);
        return $this->publishContent($request,$identity,$fileId,$context,null);
    }
    public function ticketForPlatform(Request $request,PlatformScope $scope,string $fileId): array
    {
        $identity=$this->administrator($request,$scope,self::TICKET_PERMISSION); $this->ticketRequest($request);
        return $this->publishTicket($request,$identity,$fileId,null,$scope);
    }
    public function contentForPlatform(Request $request,PlatformScope $scope,string $fileId): Response
    {
        $identity=$this->administrator($request,$scope,self::READ_PERMISSION); $this->contentRequest($request);
        return $this->publishContent($request,$identity,$fileId,null,$scope);
    }

    /** @internal After the real core payment/root/family/actions locks; never begins or commits a second transaction. */
    public function lockForApply(ConnectionInterface $connection,PDO $pdo,array $fullRoot,int $actualUid,array $images): array
    {
        $request=app()->request; if(!$request instanceof Request) self::unavailable(); $this->customer($request,$actualUid);
        $this->writer($connection,$pdo); if(self::integer($fullRoot['uid']??null,1,self::INT_MAX)!==$actualUid) self::unavailable();
        $rows=$this->files->lockStagedForClaim($connection,$pdo,$fullRoot,$actualUid,$images); $this->verifyObjects($rows);
        $this->writer($connection,$pdo); return $rows;
    }
    /** @internal Actual refund/action/mirror already belong to this same still-owned core transaction. */
    public function claimForApply(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $fullRefund,array $lockedFiles): array
    {
        $uid=self::integer($fullRoot['uid']??null,1,self::INT_MAX); $request=app()->request;
        if(!$request instanceof Request) self::unavailable(); $this->customer($request,$uid); $this->writer($connection,$pdo);
        $fresh=$this->files->lockStagedForClaim($connection,$pdo,$fullRoot,$uid,self::refundImages($fullRefund));
        if($fresh!==$lockedFiles) self::unavailable(); $this->verifyObjects($fresh);
        $rows=$this->files->claimForRefund($connection,$pdo,$fullRoot,$fullRefund,$lockedFiles);
        $this->verifyObjects($rows); $this->writer($connection,$pdo); return $rows;
    }
    /** @internal Saved apply replay: current STAGED deadline never invalidates an already CLAIMED obligation. */
    public function readClaimedForReplay(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $fullRefund): array
    {
        $this->writer($connection,$pdo); $rows=$this->files->readClaimedForRefund($connection,$pdo,$fullRoot,$fullRefund);
        $this->verifyObjects($rows); $this->writer($connection,$pdo); return $rows;
    }
    /** @internal Final fresh core readback after its actual authenticated action, not a permission result. */
    public function readFinalClaimed(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $fullRefund): array
    {
        $this->writer($connection,$pdo); $rows=$this->files->readClaimedForRefund($connection,$pdo,$fullRoot,$fullRefund);
        $this->verifyObjects($rows); $this->writer($connection,$pdo); return $rows;
    }
    /** @internal Already authorized owned RR read. Metadata only, including the complete empty binding set. */
    public function readClaimedForRefunds(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $fullRefunds): array
    {
        $this->reader($connection,$pdo,true); $rows=$this->files->readClaimedForRefunds($connection,$pdo,$fullRoot,$fullRefunds);
        $this->reader($connection,$pdo,true); return $rows;
    }

    private function publishTicket(Request $request,array $identity,string $fileId,?MerchantContext $merchant,?PlatformScope $scope): array
    {
        $read=$this->authorizedFile($request,$identity,$fileId,$merchant,$scope,false);
        $this->sameIdentity($identity,$this->identityAgain($request,$identity,$merchant,$scope,false));
        if($read['file']['state']==='STAGED') $this->availableForCustomer($read['file']);
        $issued=$this->tickets->issue($read['binding']);
        return ['schema'=>1,'scope'=>'marketplace_aftersale_read_ticket_v1','file'=>self::fileView($read['file']),
            'refund_id'=>$read['binding']['refund_id'],'read_ticket'=>$issued['read_ticket'],'issued_at'=>$issued['issued_at'],'expires_at'=>$issued['expires_at']];
    }
    private function publishContent(Request $request,array $identity,string $fileId,?MerchantContext $merchant,?PlatformScope $scope): Response
    {
        $read=$this->authorizedFile($request,$identity,$fileId,$merchant,$scope,true);
        $this->sameIdentity($identity,$this->identityAgain($request,$identity,$merchant,$scope,true));
        if($read['file']['state']==='STAGED') $this->availableForCustomer($read['file']);
        $this->tickets->consume($read['ticket'],$read['binding'],$read['receipt']);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; $file=$read['file'];
        $response=Response::create($read['bytes'],'html',200)->allowCache(false)->header([
            'Content-Type'=>$file['media_type'],'Content-Length'=>(string)strlen($read['bytes']),
            'Content-Disposition'=>'inline; filename="aftersale-'.self::big($file['id']).'.'.$extensions[$file['media_type']].'"',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($response->getContent()!==$read['bytes']||$response->getCode()!==200||$response->isAllowCache()!==false) self::unavailable();
        return $response;
    }

    /** Fixed ticket/content branch. Locator completion precedes a separate owned write transaction; no external callback is accepted. */
    private function authorizedFile(Request $request,array $identity,string $fileId,?MerchantContext $merchant,?PlatformScope $scope,bool $content): array
    {
        self::wireBig($fileId); $located=$this->locate($fileId); [$connection,$pdo]=$this->defaultConnection();
        try{
            $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$identity,$fileId,$merchant,$scope,$content,$located):array{
                $this->inside($actual,$connection,$pdo);
                $this->sameIdentity($identity,$this->identityAgain($request,$identity,$merchant,$scope,$content));
                if($identity['app']==='api') $this->lockCustomer($connection,$pdo,$identity['actor_id']);
                $root=$this->lockRoot($connection,$pdo,self::integer($located['root_order_id'],1,self::INT_MAX));
                $uid=self::integer($root['uid'],1,self::INT_MAX); $mer=self::integer($root['mer_id'],1,self::UINT_MAX);
                if($identity['app']==='api'){
                    if($uid!==$identity['actor_id']) self::unavailable();
                }elseif($merchant!==null){
                    if($merchant->merId!==$mer) self::unavailable();
                    $this->employees->authorizeAction($merchant,'order.read',true);
                }elseif($scope!==null){
                    $this->platform->authorizeScope($scope,$content?self::READ_PERMISSION:self::TICKET_PERMISSION);
                }else self::unavailable();
                $this->inside($actual,$connection,$pdo); $refunds=$this->lockRefunds($connection,$pdo,$root);
                $file=$this->files->lockFileForRoot($connection,$pdo,$root,$fileId);
                foreach(['id','uid','root_order_id','mer_id','object_key','media_type','byte_size','width','height','content_sha256',
                    'upload_key','created_at','claim_expires_at'] as $key) if($file[$key]!==$located[$key]) self::unavailable();
                if($file['state']==='STAGED'){
                    if($identity['app']!=='api') self::unavailable(); $this->availableForCustomer($file);
                }elseif($file['state']==='CLAIMED') $this->boundFile($connection,$pdo,$root,$refunds,$file);
                else self::unavailable();
                $binding=['app'=>$identity['app'],'actor_type'=>$identity['actor_type'],'actor_id'=>$identity['actor_id'],
                    'session_sha256'=>$identity['session_sha256'],'uid'=>$uid,'mer_id'=>$mer,'root_order_id'=>self::integer($root['id'],1,self::INT_MAX),
                    'refund_id'=>$file['refund_id']===null?null:self::integer($file['refund_id'],1,self::INT_MAX),
                    'file_id'=>self::big($file['id']),'content_sha256'=>bin2hex($file['content_sha256'])];
                $read=['file'=>$file,'binding'=>$binding];
                if($content){
                    $ticket=$request->header('X-Aftersale-Read-Ticket',''); if(!is_string($ticket)) self::unavailable();
                    $receipt=$this->tickets->peek($ticket,$binding);
                    // No file open before current authorization and matching, still-live app/actor/session/file ticket.
                    $bytes=$this->disk()->readObject($file);
                    $read+=['ticket'=>$ticket,'receipt'=>$receipt,'bytes'=>$bytes];
                }
                if($scope!==null) $this->audit->record('admin',$scope->adminId,$mer,
                    $content?self::READ_PERMISSION:self::TICKET_PERMISSION,'aftersale-file',$fileId,'success');
                $fresh=$this->files->lockFileForRoot($connection,$pdo,$root,$fileId); if($fresh!==$file) self::unavailable();
                if($file['state']==='CLAIMED') $this->boundFile($connection,$pdo,$root,$refunds,$fresh);
                $this->sameIdentity($identity,$this->identityAgain($request,$identity,$merchant,$scope,$content));
                $this->inside($actual,$connection,$pdo); return $read;
            });
            $this->outside($connection,$pdo); return $result;
        }catch(Throwable $error){self::unavailable();}
    }

    /** Explicit native RR READ ONLY/depth0 locator; returned metadata is never authority. */
    private function locate(string $fileId): array
    {
        [$connection,$pdo]=$this->defaultConnection(); $attempted=false; $began=false; $committing=false;
        try{
            $this->reader($connection,$pdo,false); $attempted=true;
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false) self::unavailable();
            $this->reader($connection,$pdo,false);
            if($pdo->beginTransaction()!==true) self::unavailable(); $began=true;
            $this->reader($connection,$pdo,true); $row=$this->files->readLocatedFile($connection,$pdo,$fileId);
            $this->reader($connection,$pdo,true); $committing=true;
            if($pdo->commit()!==true) self::unavailable(); $began=false; $this->reader($connection,$pdo,false); return $row;
        }catch(Throwable $error){
            if($began&&!$committing&&$pdo->inTransaction()){try{$pdo->rollBack();}catch(Throwable $ignored){}}
            // A failed SET/commit boundary must not leave a one-shot READ ONLY characteristic on the shared connection.
            if($attempted){try{$connection->close();}catch(Throwable $ignored){}}
            self::unavailable();
        }
    }

    private function lockCustomer(ConnectionInterface $connection,PDO $pdo,int $uid): void
    {
        $rows=$this->rows($connection,$pdo,'SELECT uid,status,is_del FROM '.$this->table($connection,'user').' WHERE uid=? FOR UPDATE',[$uid]);
        if(count($rows)!==1||self::integer($rows[0]['uid']??null,1,self::INT_MAX)!==$uid
            ||self::integer($rows[0]['status']??null,1,1)!==1||self::integer($rows[0]['is_del']??null,0,0)!==0) self::unavailable();
    }
    private function lockRoot(ConnectionInterface $connection,PDO $pdo,int $id): array
    {
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? FOR UPDATE',[$id]);
        if(count($rows)!==1) self::unavailable(); $row=$rows[0];
        if(self::integer($row['id']??null,1,self::INT_MAX)!==$id||self::integer($row['pid']??null,0,0)!==0
            ||self::integer($row['marketplace_trade_version']??null,1,1)!==1) self::unavailable();
        self::integer($row['uid']??null,1,self::INT_MAX); self::integer($row['mer_id']??null,1,self::UINT_MAX); return $row;
    }
    private function lockRefunds(ConnectionInterface $connection,PDO $pdo,array $root): array
    {
        $id=self::integer($root['id'],1,self::INT_MAX); $orders=$this->table($connection,'store_order');
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_refund').
            ' WHERE marketplace_root_order_id=? OR store_order_id=? OR store_order_id IN (SELECT id FROM '.$orders.' WHERE pid=?) ORDER BY id FOR UPDATE',[$id,$id,$id]);
        $last=0;
        foreach($rows as $row){
            $refund=self::integer($row['id']??null,1,self::INT_MAX); if($refund<=$last) self::unavailable(); $last=$refund;
            if(self::integer($row['uid']??null,1,self::INT_MAX)!==self::integer($root['uid'],1,self::INT_MAX)) self::unavailable();
            if(!array_key_exists('marketplace_root_order_id',$row)) self::unavailable();
            if($row['marketplace_root_order_id']!==null&&(self::integer($row['marketplace_root_order_id'],1,self::INT_MAX)!==$id
                ||self::integer($row['store_order_id']??null,1,self::INT_MAX)!==$id
                ||self::integer($row['mer_id']??null,1,self::UINT_MAX)!==self::integer($root['mer_id'],1,self::UINT_MAX))) self::unavailable();
        }
        return $rows;
    }
    private function boundFile(ConnectionInterface $connection,PDO $pdo,array $root,array $refunds,array $file): void
    {
        if($file['state']!=='CLAIMED') self::unavailable(); $id=self::integer($file['refund_id'],1,self::INT_MAX); $target=null;
        foreach($refunds as $refund) if(self::integer($refund['id'],1,self::INT_MAX)===$id){if($target!==null) self::unavailable(); $target=$refund;}
        if($target===null||self::integer($target['store_order_id']??null,1,self::INT_MAX)!==self::integer($root['id'],1,self::INT_MAX)) self::unavailable();
        $bound=$this->files->readClaimedForRefund($connection,$pdo,$root,$target); $found=0;
        foreach($bound as $row) if(self::big($row['id'])===self::big($file['id'])){if($row!==$file) self::unavailable(); ++$found;}
        if($found!==1) self::unavailable();
    }

    private function identityAgain(Request $request,array $identity,?MerchantContext $merchant,?PlatformScope $scope,bool $content): array
    {
        if($identity['app']==='api') return $this->customer($request,$identity['actor_id']);
        if($identity['app']==='merchantapi'&&$merchant!==null) return $this->merchant($request,$merchant);
        if($identity['app']==='adminapi'&&$scope!==null) return $this->administrator($request,$scope,$content?self::READ_PERMISSION:self::TICKET_PERMISSION);
        self::unavailable();
    }
    private function current(Request $request,string $application): string
    {
        if(app()->request!==$request||app()->http->getName()!==$application) self::unavailable();
        $fingerprint=$request->verifiedTokenFingerprint();
        if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1) self::unavailable(); return $fingerprint;
    }
    private function customer(Request $request,int $actualUid): array
    {
        $fingerprint=$this->current($request,'api'); self::integer($actualUid,1,self::INT_MAX);
        if($request->isLogin()!==true||self::integer($request->uid(),1,self::INT_MAX)!==$actualUid) self::unavailable();
        return ['app'=>'api','actor_type'=>'customer','actor_id'=>$actualUid,'session_sha256'=>$fingerprint];
    }
    private function merchant(Request $request,MerchantContext $context): array
    {
        $fingerprint=$this->current($request,'merchantapi');
        if($request->merchantContext()!==$context) self::unavailable();
        self::integer($context->employeeId,1,self::UINT_MAX); self::integer($context->merId,1,self::UINT_MAX);
        return ['app'=>'merchantapi','actor_type'=>'employee','actor_id'=>$context->employeeId,'session_sha256'=>$fingerprint];
    }
    private function administrator(Request $request,PlatformScope $scope,string $permission): array
    {
        $fingerprint=$this->current($request,'adminapi');
        if($request->platformScope()!==$scope||$scope->permission!==$permission||$scope->targetMerId!==null
            ||$request->isAdminLogin()!==true||self::integer($request->adminId(),1,self::UINT_MAX)!==$scope->adminId) self::unavailable();
        return ['app'=>'adminapi','actor_type'=>'admin','actor_id'=>$scope->adminId,'session_sha256'=>$fingerprint];
    }
    private function sameIdentity(array $initial,array $current): void {if($initial!==$current) self::unavailable();}

    private function ticketRequest(Request $request): void
    {
        $this->queryEmpty($request);
        if($request->method(true)!=='POST'||strtolower($request->contentType())!=='application/json') self::unavailable();
        $raw=$request->getContent(); if($raw===''||strlen($raw)>65536||preg_match('//u',$raw)!==1) self::unavailable();
        try{$value=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
        if(!$value instanceof stdClass) self::unavailable(); $value=get_object_vars($value); self::keys($value,['schema']);
        if($value['schema']!==1) self::unavailable();
    }
    private function contentRequest(Request $request): void
    {
        $this->queryEmpty($request); if($request->method(true)!=='GET'||$request->getContent()!=='') self::unavailable();
    }
    private function queryEmpty(Request $request): void
    {
        if($request->get(false)!==[]||$request->server('QUERY_STRING','')!=='') self::unavailable();
    }
    private function disk(): MarketplaceAftersaleFileStorage
    {
        $root=Config::get('marketplace.aftersale.private_root'); if(!is_string($root)||$root==='') self::unavailable();
        return new MarketplaceAftersaleFileStorage($root);
    }
    private function verifyObjects(array $rows): void
    {
        if(!array_is_list($rows)||count($rows)>9) self::unavailable();
        foreach($rows as $row){if(!is_array($row)) self::unavailable(); $this->disk()->readObject($row);}
    }
    private function availableForCustomer(array $row): void
    {
        if($row['state']==='CLAIMED') return;
        $now=self::now();
        if($row['state']!=='STAGED'||self::integer($row['created_at'],1,self::UINT_MAX)>$now
            ||self::integer($row['claim_expires_at'],1,self::UINT_MAX)<$now) self::unavailable();
    }
    private function sameObject(array $row,array $inspection): void
    {
        foreach(['media_type','content_sha256'] as $key) if($row[$key]!==$inspection[$key]) self::unavailable();
        foreach(['byte_size','width','height'] as $key) if(self::integer($row[$key],1,$key==='byte_size'?self::MAX_BYTES:4096)!==$inspection[$key]) self::unavailable();
    }
    private function uploadedBytes(UploadedFile $file): array
    {
        $path=$file->getPathname(); if(!$file->isValid()||!is_uploaded_file($path)) self::unavailable();
        clearstatcache(true,$path); $before=@lstat($path);
        if(!is_array($before)||($before['mode']&0170000)!==0100000||$before['nlink']!==1
            ||!is_int($before['size'])||$before['size']<1||$before['size']>self::MAX_BYTES) self::unavailable();
        $handle=@fopen($path,'rb'); if(!is_resource($handle)) self::unavailable();
        try{
            $opened=fstat($handle); foreach(['dev','ino','size','mtime','ctime','mode','nlink'] as $key) if(($opened[$key]??null)!==$before[$key]) self::unavailable();
            $bytes='';
            while(strlen($bytes)<$before['size']){
                $part=@fread($handle,min(65536,$before['size']-strlen($bytes))); if(!is_string($part)||$part==='') self::unavailable(); $bytes.=$part;
            }
            if(@fread($handle,1)!==''||!feof($handle)) self::unavailable();
            $after=fstat($handle); clearstatcache(true,$path); $atPath=@lstat($path);
            foreach(['dev','ino','size','mtime','ctime','mode','nlink'] as $key)
                if(($after[$key]??null)!==$before[$key]||($atPath[$key]??null)!==$before[$key]) self::unavailable();
            return [$bytes,$file->getOriginalName()];
        }finally{if(!@fclose($handle)) self::unavailable();}
    }
    private static function fileView(array $row): array
    {
        if(!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32
            ||!in_array($row['media_type'],['image/jpeg','image/png','image/webp'],true)) self::unavailable();
        return ['schema'=>1,'scope'=>'marketplace_aftersale_file_v1','id'=>self::big($row['id']),
            'root_order_id'=>self::integer($row['root_order_id'],1,self::INT_MAX),'media_type'=>$row['media_type'],
            'byte_size'=>self::integer($row['byte_size'],1,self::MAX_BYTES),'width'=>self::integer($row['width'],1,4096),
            'height'=>self::integer($row['height'],1,4096),'sha256'=>bin2hex($row['content_sha256']),
            'created_at'=>self::integer($row['created_at'],1,self::UINT_MAX),'claim_expires_at'=>self::integer($row['claim_expires_at'],1,self::UINT_MAX)];
    }
    private static function refundImages(array $refund): array
    {
        $raw=$refund['refund_img']??null; if(!is_string($raw)) self::unavailable();
        try{$ids=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>9) self::unavailable(); $seen=[];
        foreach($ids as $id){self::wireBig($id); if(isset($seen['id:'.$id])) self::unavailable(); $seen['id:'.$id]=true;} return $ids;
    }
    private function defaultConnection(): array
    {
        $connection=Db::connect(); if(!$connection instanceof Mysql||$connection->transactionDepth()!==0) self::unavailable();
        $pdo=$connection->getPdo(); if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO) self::unavailable(); $this->outside($connection,$pdo);
        $this->structure->assertAftersaleStructure($pdo); $this->outside($connection,$pdo); return [$connection,$pdo];
    }
    private function connection(ConnectionInterface $connection,PDO $pdo): void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)) self::unavailable();
        $this->table($connection,'store_order');
    }
    private function outside(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->connection($connection,$pdo); if($connection->transactionDepth()!==0||$pdo->inTransaction()) self::unavailable();
    }
    private function inside(ConnectionInterface $actual,ConnectionInterface $connection,PDO $pdo): void
    {
        if($actual!==$connection) self::unavailable(); $this->writer($connection,$pdo);
    }
    private function writer(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->connection($connection,$pdo); if($connection->transactionDepth()!==1||!$pdo->inTransaction()) self::unavailable();
    }
    private function reader(ConnectionInterface $connection,PDO $pdo,bool $active): void
    {
        $this->connection($connection,$pdo); if($connection->transactionDepth()!==0||$pdo->inTransaction()!==$active) self::unavailable();
    }
    private function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters): array
    {
        $this->writer($connection,$pdo); $statement=$pdo->prepare($sql); if(!$statement instanceof PDOStatement) self::unavailable();
        try{
            foreach($parameters as $offset=>$value){
                if(!is_string($value)&&!is_int($value)) self::unavailable();
                if(!$statement->bindValue($offset+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)) self::unavailable();
            }
            if(!$statement->execute()) self::unavailable(); $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows)) self::unavailable();
            foreach($rows as $row){if(!is_array($row)) self::unavailable(); foreach($row as $key=>$value)
                if(!is_string($key)||($value!==null&&!is_string($value)&&!is_int($value))) self::unavailable();}
        }finally{if(!$statement->closeCursor()) self::unavailable();}
        $this->writer($connection,$pdo); return $rows;
    }
    private function table(ConnectionInterface $connection,string $name): string
    {
        if(!in_array($name,['user','store_order','store_order_refund'],true)) self::unavailable(); $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1) self::unavailable();
        $name=$prefix.$name; if(strlen($name)>64) self::unavailable(); return chr(96).$name.chr(96);
    }
    private static function uploadKey($value): void
    {
        if(!is_string($value)||preg_match('/\A[\x20-\x7e]{1,128}\z/D',$value)!==1||$value[0]===' '||substr($value,-1)===' ') self::unavailable();
    }
    private static function id(mixed $value): int {return self::integer($value,1,self::INT_MAX);}
    private static function integer($value,int $minimum,int $maximum): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=10&&(string)(int)$value===$value) $value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum) self::unavailable(); return $value;
    }
    private static function wireBig($value): void {if(!is_string($value)) self::unavailable(); self::big($value);}
    private static function big($value): string
    {
        if(is_int($value)&&$value>0) $value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||strlen($value)>20||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0)) self::unavailable(); return $value;
    }
    private static function keys(array $value,array $expected): void
    {
        $keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected) self::unavailable();
    }
    private static function now(): int {return self::integer(time(),1,self::UINT_MAX);}
    private static function unavailable(): never {throw new AuthUnavailableException();}
}
