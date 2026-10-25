<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\PlatformAssetDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\PlatformAccessServices;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\product\LegacyProductAccessServices;
use app\services\marketplace\product\LegacyProductWriteServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\services\auth\AccountChangeGuard;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;
use think\exception\ValidateException;

/** Only current global administrator site_image operations; no supplied owner/purpose. */
final class PlatformAssetServices
{
    public const LIST='platform-assets-list';
    public const UPLOAD='platform-assets-upload';
    public const CATEGORIES='platform-asset-categories-list';
    public const IMPORT='marketplace-products-import-platform-image';
    public function __construct(private PlatformAssetDao $dao,private PlatformAssetStorage $storage,private AuditLogServices $audit) {}
    public static function assertContext(Request $request,PlatformScope $scope,PDO $pdo,bool $inside=true): void
    {
        if(app()->request!==$request||app('http')->getName()!=='adminapi'||$request->isAdminLogin()!==true
            ||$request->platformScope()!==$scope||$scope->targetMerId!==null||!in_array($scope->permission,[self::LIST,self::UPLOAD,self::CATEGORIES,self::IMPORT],true)
            ||(string)$request->adminId()!==(string)$scope->adminId)self::fail();
        app()->make(PlatformAccessServices::class)->authorizeScope($scope,$scope->permission);
        if($scope->permission===self::IMPORT){
            app()->make(LegacyProductAccessServices::class)->authorizeScope($scope);
            app()->make(PlatformAccessServices::class)->additionalPlatformAssetReadGrant($scope);
            if($inside){if(LegacyProductWriteServices::rawScope()!==$scope||LegacyProductWriteServices::transaction()!==$pdo)self::fail();}
            elseif(LegacyProductWriteServices::active())self::fail();
        }
        $connection=Db::connect();
        if($connection->getPdo()!==$pdo||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)||$pdo->inTransaction()!==$inside)self::fail();
        if(!$inside){$state=$pdo->query('SELECT @@session.autocommit');if($state===false)self::fail();
            try{if(!in_array($state->fetchColumn(),[1,'1'],true))self::fail();}finally{if($state->closeCursor()!==true)self::fail();}}
        if(Db::connect()!==$connection||$connection->getPdo()!==$pdo)self::fail();
    }
    private static function request(Request $request,string $action): PlatformScope
    {
        if(app()->request!==$request)self::fail();$scope=$request->platformScope();
        if($scope->permission!==$action||$scope->targetMerId!==null)self::fail();
        app()->make(PlatformAccessServices::class)->authorizeScope($scope,$action);return $scope;
    }
    private function within(Request $request,string $action,callable $operation): mixed
    {
        $scope=self::request($request,$action);$connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO||$pdo->inTransaction())self::fail();$controlled=null;
        try{
            $result=AccountChangeGuard::runMany('adminapi',[],function()use($request,$scope,$pdo,$connection,$operation,&$controlled):mixed{
                try{if(Db::connect()!==$connection)self::fail();self::assertContext($request,$scope,$pdo);return $operation($pdo,$scope);}
                catch(HttpException $error){if(!in_array($error->getStatusCode(),[401,403,404,410,422],true))throw $error;
                    $controlled=$error->getStatusCode();throw new ValidateException('平台图片请求未完成');}
            });
            if(Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())self::fail();return $result;
        }catch(ValidateException $error){
            if($controlled!==null)throw new HttpException($controlled,match($controlled){401=>'请登录',403=>'没有权限访问',404=>'资源不存在',410=>'素材已过期',422=>'素材参数或状态不正确'});throw $error;
        }
    }
    public function listing(Request $request,array $query): array
    {
        self::request($request,self::LIST);$query=Input::body($query,['page','limit','category_id','real_name']);
        $category=Input::integer($query['category_id']??0,0,2147483647,true);$name=Input::text($query['real_name']??'',255);
        $page=Input::integer($query['page']??1,1,2147483647,true);$limit=Input::integer($query['limit']??18,1,100,true);
        return $this->within($request,self::LIST,function(PDO $pdo,PlatformScope $scope)use($request,$category,$name,$page,$limit):array{
            $result=$this->dao->listing($request,$scope,$pdo,$category,$name,$page,$limit);
            $result['list']=array_map(fn(array $row):array=>$this->storage->presentation($row),$result['list']);
            $this->audit->record('admin',$scope->adminId,null,self::LIST,'platform-asset','global','success',[]);self::assertContext($request,$scope,$pdo);return $result;
        });
    }
    public function categories(Request $request,array $query): array
    {
        self::request($request,self::CATEGORIES);$query=Input::body($query,['pid','all','name']);
        $pid=Input::integer($query['pid']??0,0,2147483647,true);$all=Input::integer($query['all']??0,0,1,true)===1;$name=Input::text($query['name']??'',50);
        return $this->within($request,self::CATEGORIES,function(PDO $pdo,PlatformScope $scope)use($request,$pid,$all,$name):array{
            $complete=$this->dao->categories($request,$scope,$pdo);$by=[];$children=[];$selected=[];
            foreach($complete as$row){$id=(int)$row['id'];$by[$id]=$row;$children[(int)$row['pid']][]=$id;}
            if($pid&&!isset($by[$pid]))throw new HttpException(404,'分类不存在');
            $nameIds=$name===''?null:$this->dao->categoryNameIds($request,$scope,$pdo,$name);
            foreach($nameIds??[]as$id)if(!isset($by[$id]))self::fail();
            foreach($complete as$row){
                if($nameIds!==null&&!in_array((int)$row['id'],$nameIds,true))continue;
                if(!$all&&$name===''&&(int)$row['pid']!==$pid)continue;$selected[(int)$row['id']]=$row;
            }
            if($all&&$name!=='')foreach(array_keys($selected)as$id){for($parent=(int)$by[$id]['pid'];$parent!==0;$parent=(int)$by[$parent]['pid'])$selected[$parent]=$by[$parent];}
            $rows=[];foreach($complete as$row)if(isset($selected[(int)$row['id']])){
                $item=['id'=>(int)$row['id'],'pid'=>(int)$row['pid'],'name'=>$row['name'],'enname'=>$row['enname'],'type'=>(int)$row['type'],'title'=>$row['name']];
                if(!$all&&$name===''&&isset($children[$item['id']])){$item['loading']=false;$item['children']=[];}$rows[]=$item;
            }
            $count=count($rows);
            if($all){$group=[];foreach($rows as$row)$group[$row['pid']][]=$row;
                $build=function(int $parent)use(&$build,$group):array{$result=[];foreach($group[$parent]??[]as$row){$nested=$build($row['id']);if($nested!==[]){$row['children']=$nested;$row['expand']=true;}$result[]=$row;}return $result;};$rows=$build(0);}
            $this->audit->record('admin',$scope->adminId,null,self::CATEGORIES,'platform-asset-category','global','success',[]);
            self::assertContext($request,$scope,$pdo);return ['list'=>$rows,'count'=>$count];
        });
    }
    public function upload(Request $request,array $body): array
    {
        $scope=self::request($request,self::UPLOAD);$body=Input::body($body,['category_id']);$category=Input::integer($body['category_id']??0,0,2147483647,true);
        PlatformAssetDao::integer($scope->adminId,1,65535);$receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];
        $object=[];$snapshot=null;$pdo=null;
        try{
            $result=$this->within($request,self::UPLOAD,function(PDO $writer,PlatformScope $actual)use($request,$category,&$pdo,&$object,&$receipt,&$snapshot):array{
                $pdo=$writer;if($category)$this->dao->category($request,$actual,$pdo,$category);
                $uploaded=$this->storage->intake($request);$qualified=$this->storage->qualify($uploaded['bytes'],$uploaded['display_name']);
                try{$physical=$this->storage->upload($request,$actual,$pdo,$uploaded['bytes'],$uploaded['display_name'],$object,$receipt);}
                finally{unset($uploaded);}
                if($receipt['physical_complete']!==true||$receipt['cleanup_complete']===false)self::fail();
                $fields=array_fill_keys(array_slice(PlatformAssetDao::FIELDS,1),null);$now=time();PlatformAssetDao::integer($now,1,4294967295);
                $key=$this->storage->objectKey($object,$physical);
                foreach(['purpose'=>'site_image','category_id'=>$category,'state'=>'STAGED','storage_type'=>$object['type'],'object_key'=>$key,'thumbnail_key'=>'','name'=>basename($key),
                    'real_name'=>$physical['real_name'],'media_type'=>$qualified['mime'],'byte_size'=>$qualified['size'],'width'=>$qualified['format']['width'],
                    'height'=>$qualified['format']['height'],'content_sha256'=>hex2bin($qualified['sha256']),'created_at'=>$now,'created_by_admin_id'=>$actual->adminId]as$key=>$value)$fields[$key]=$value;
                self::assertContext($request,$actual,$pdo);$receipt['phase']='database_insert';$receipt['database_committed']=null;
                $row=$this->dao->add($request,$actual,$pdo,$fields);$read=$this->storage->read($request,$actual,$pdo,$row);
                try{if($read['qualified']['sha256']!==$qualified['sha256']||$this->storage->identity($read['object'])!==$this->storage->identity($object))self::fail();}
                finally{unset($read['bytes']);}
                $receipt['phase']='audit';$audit=$this->audit->recordPlatformAssetUpload($request,$actual,$row);
                $snapshot=['connection'=>Db::connect(),'pdo'=>$pdo,'scope'=>$actual,'row'=>$row,'audit'=>$audit,'object'=>PlatformAssetStorage::proof($read['object'])];
                self::assertContext($request,$actual,$pdo);$receipt['phase']='commit_observation';return $this->storage->presentation($row);
            });
            $receipt['database_committed']=true;
            if(!is_array($snapshot)||!$pdo instanceof PDO||Db::connect()!==$snapshot['connection'])self::fail();
            self::assertContext($request,$scope,$pdo,false);$row=$this->dao->find($request,$scope,$pdo,PlatformAssetDao::id($snapshot['row']['id']),false);
            if($row!==$snapshot['row'])self::fail();$read=$this->storage->read($request,$scope,$pdo,$row,false);
            try{if(PlatformAssetStorage::proof($read['object'])!==$snapshot['object'])self::fail();}finally{unset($read['bytes']);}
            $this->audit->observePlatformAssetUpload($request,$scope,$pdo,$snapshot['audit']);self::assertContext($request,$scope,$pdo,false);
            $receipt['complete']=true;$receipt['phase']='complete';return ['platform_asset_id'=>$result['platform_asset_id'],'src'=>$result['src']];
        }catch(\Throwable $error){
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false&&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,410,422],true))
                ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
            if($receipt['database_committed']===false&&$object!==[]&&$pdo instanceof PDO){
                try{$this->storage->cleanup($request,$scope,$pdo,$object,$receipt);}catch(\Throwable $ignored){$receipt['cleanup_complete']=false;}
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }
    private static function fail(): never { throw new AuthUnavailableException(); }
}
