<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use think\facade\Config;
use think\file\UploadedFile;
use Throwable;

/** The V1 review producer only. Avatar, default compatibility and private uses are not published here. */
final class CustomerAssetServices
{
    public function __construct(private MarketplaceOrderReviewServices $reviews,private CustomerAssetDao $assets,private CustomerAssetStorage $storage){}

    public function uploadForReview(Request $request,int $actualUid,int $rootId,int $itemId):array
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];$object=[];
        try{
            if($actualUid<1||$actualUid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
                ||$request->isLogin()!==true||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::fail();
            $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();
            $out=$this->reviews->withCustomerUpload($request,$rootId,$itemId,function(array $context)use($request,$actualUid,&$receipt,&$object):array{
                MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                if($context['request']!==$request||$context['actual_uid']!==$actualUid)self::fail();
                try{
                    // This runs only after actual paid/received item, full user and review eligibility have been locked.
                    $key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();
                    $file=$request->file('file');if(!$file instanceof UploadedFile)self::fail();
                    [$bytes,$name]=$this->uploadedBytes($file);$qualified=$this->storage->qualify($bytes,$name);
                    MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                    $physical=$this->storage->upload($context,$bytes,$name,$object,$receipt);
                    if($receipt['physical_complete']!==true||!in_array($receipt['physical_changed'],[true,null],true))self::fail();
                    $this->storage->assertStored($context,$physical,$qualified,$bytes,$object);
                    $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                    $row=$this->assets->insertForReview($context,$physical,$qualified);
                    $this->storage->assertStored($context,$physical,$qualified,$bytes,$object);
                    $this->assets->assertUploaded($context,$row);MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                    $url=path_to_url($row['object_key']);if(!is_string($url)||$url==='')self::fail();
                    if(strpos($url,'http')===false)$url=$request->domain().$url;
                    $dto=['name'=>$row['name'],'url'=>$url,'attachment_ref'=>$row['object_key']];
                    $receipt['phase']='commit_outcome_unknown';return ['asset'=>$row,'dto'=>$dto];
                }catch(Throwable $error){
                    // Only an actual new physical object and positively no attempted DML permit cleanup.
                    if($receipt['database_committed']===false&&$object!==[]){
                        try{$this->storage->cleanup($context,$object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
                    }
                    throw $error;
                }
            });
            // withCustomerUpload has observed the actual outer transaction return, outside PDO and current identity.
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            if(app()->request!==$request||app()->http->getName()!=='api'||$request->isLogin()!==true
                ||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::fail();
            $key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||CacheService::get($key,null)!==$count)self::fail();
            if(app()->request!==$request||app()->http->getName()!=='api'||$request->isLogin()!==true
                ||!in_array($request->uid(),[$actualUid,(string)$actualUid],true)||$request->verifiedTokenFingerprint()!==$fingerprint)self::fail();
            $receipt['complete']=true;$receipt['phase']='complete';return $out['dto'];
        }catch(Throwable $error){throw new MerchantAttachmentOperationException($receipt);}
    }

    private function uploadedBytes(UploadedFile $file):array
    {
        $path=$file->getPathname();$maximum=Config::get('upload.filesize');
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
    private static function counter(mixed $value):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=3)$value=(int)$value;
        if(!is_int($value)||$value<0||$value>100)self::fail();return $value;
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
