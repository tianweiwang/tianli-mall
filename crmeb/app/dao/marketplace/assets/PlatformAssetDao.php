<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use app\Request;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\assets\PlatformAssetServices;
use app\services\marketplace\MerchantManagementInput as Input;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;

/** Native platform rows stay distinct from commercial attachment rows. */
final class PlatformAssetDao
{
    public const FIELDS=['id','purpose','category_id','state','storage_type','object_key','thumbnail_key','name','real_name','media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at','created_by_admin_id','created_by_service_id','created_by_uid','created_by_job','chat_customer_uid','chat_is_tourist','chat_service_id','chat_service_uid','channel_qrcode_id','routine_version','legacy_att_id'];
    public const CATEGORY_FIELDS=['id','pid','name','enname','type','created_by_admin_id','updated_by_admin_id','created_at','updated_at','legacy_category_id'];
    private function table(string $suffix): string
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($suffix,['marketplace_platform_asset','marketplace_platform_asset_category'],true)||strlen($prefix.$suffix)>64)self::fail();
        return '`'.$prefix.$suffix.'`';
    }
    public static function id(mixed $value): string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||strlen($value)===20&&strcmp($value,'18446744073709551615')>0)self::fail();
        return $value;
    }
    public static function integer(mixed $value,int $minimum,int $maximum): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1){
            $bound=(string)$maximum;if(strlen($value)>strlen($bound)||strlen($value)===strlen($bound)&&strcmp($value,$bound)>0)self::fail();$value=(int)$value;
        }
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::fail();return $value;
    }
    public static function asset(array $row): void
    {
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='site_image'||!in_array($row['state'],['STAGED','BOUND'],true))self::fail();
        self::id($row['id']);self::integer($row['category_id'],0,2147483647);self::integer($row['storage_type'],1,7);
        foreach(['object_key'=>512,'thumbnail_key'=>512,'name'=>100,'real_name'=>255,'media_type'=>127]as$key=>$length)
            Input::text($row[$key],$length,$key!=='thumbnail_key');
        if($row['thumbnail_key']!==''||!in_array($row['media_type'],['image/jpeg','image/jpg','image/png','image/gif','image/webp','image/x-icon','image/vnd.microsoft.icon'],true)
            ||!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32)self::fail();
        self::integer($row['byte_size'],1,PHP_INT_MAX);self::integer($row['width'],1,4294967295);self::integer($row['height'],1,4294967295);
        $created=self::integer($row['created_at'],1,4294967295);self::integer($row['created_by_admin_id'],1,65535);
        foreach(['created_by_service_id','created_by_uid','created_by_job','chat_customer_uid','chat_is_tourist','chat_service_id','chat_service_uid','channel_qrcode_id','routine_version','legacy_att_id','retired_at']as$key)
            if($row[$key]!==null)self::fail();
        if($row['expires_at']!==null&&self::integer($row['expires_at'],1,4294967295)<$created)self::fail();
        if($row['state']==='STAGED'){
            if($row['bound_at']!==null)self::fail();
            if($row['expires_at']!==null&&self::integer($row['expires_at'],1,4294967295)<=time())throw new HttpException(410,'素材已过期');
        }elseif($row['bound_at']===null||self::integer($row['bound_at'],1,4294967295)<$created)self::fail();
    }
    private static function categoryRow(array $row): void
    {
        if(array_keys($row)!==self::CATEGORY_FIELDS)self::fail();
        self::integer($row['id'],1,2147483647);self::integer($row['pid'],0,2147483647);self::integer($row['type'],0,1);
        Input::text($row['name'],50);Input::text($row['enname'],50);
        foreach(['created_by_admin_id','updated_by_admin_id']as$key)self::integer($row[$key],1,65535);
        $created=self::integer($row['created_at'],1,4294967295);
        if(self::integer($row['updated_at'],1,4294967295)<$created)self::fail();
        if($row['legacy_category_id']!==null)self::integer($row['legacy_category_id'],1,2147483647);
    }
    private function rows(Request $request,PlatformScope $scope,PDO $pdo,string $sql,array $parameters,bool $inside=true): array
    {
        PlatformAssetServices::assertContext($request,$scope,$pdo,$inside);$statement=$pdo->prepare($sql);if($statement===false)self::fail();
        try{if($statement->execute($parameters)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        PlatformAssetServices::assertContext($request,$scope,$pdo,$inside);return $rows;
    }
    public function categories(Request $request,PlatformScope $scope,PDO $pdo): array
    {
        $rows=$this->rows($request,$scope,$pdo,'SELECT * FROM '.$this->table('marketplace_platform_asset_category').' ORDER BY id FOR UPDATE',[]);
        foreach($rows as$row)self::categoryRow($row);
        \app\dao\marketplace\MerchantAttachmentDao::assertCategoryTree($rows);return $rows;
    }
    public function categoryNameIds(Request $request,PlatformScope $scope,PDO $pdo,string $name): array
    {
        $rows=$this->rows($request,$scope,$pdo,'SELECT id FROM '.$this->table('marketplace_platform_asset_category').' WHERE name LIKE ? ORDER BY id FOR UPDATE',['%'.$name.'%']);$ids=[];
        foreach($rows as$row){if(array_keys($row)!==['id'])self::fail();$ids[]=self::integer($row['id'],1,2147483647);}return $ids;
    }
    public function category(Request $request,PlatformScope $scope,PDO $pdo,int $id): array
    {
        self::integer($id,1,2147483647);$found=null;
        foreach($this->categories($request,$scope,$pdo)as$row)if((string)$row['id']===(string)$id)$found=$row;
        if($found===null)throw new HttpException(404,'分类不存在');
        if(self::integer($found['type'],0,1)!==0)throw new HttpException(422,'请选择图片分类');return $found;
    }
    public function find(Request $request,PlatformScope $scope,PDO $pdo,string $id,bool $inside=true): array
    {
        $id=self::id($id);$rows=$this->rows($request,$scope,$pdo,'SELECT * FROM '.$this->table('marketplace_platform_asset').' WHERE id=? LIMIT 2'.($inside?' FOR UPDATE':''),[$id],$inside);
        if($rows===[])throw new HttpException(404,'素材不存在');if(count($rows)!==1)self::fail();self::asset($rows[0]);
        if(self::id($rows[0]['id'])!==$id)self::fail();
        if($inside&&self::integer($rows[0]['category_id'],0,2147483647)>0)$this->category($request,$scope,$pdo,(int)$rows[0]['category_id']);
        return $rows[0];
    }
    public function add(Request $request,PlatformScope $scope,PDO $pdo,array $fields): array
    {
        PlatformAssetServices::assertContext($request,$scope,$pdo);
        if($scope->permission!==PlatformAssetServices::UPLOAD||array_keys($fields)!==array_slice(self::FIELDS,1))self::fail();
        $expected=['id'=>'1']+$fields;self::asset($expected);
        if((string)$fields['created_by_admin_id']!==(string)$scope->adminId||$fields['state']!=='STAGED'||$fields['expires_at']!==null
            ||$fields['bound_at']!==null||$fields['retired_at']!==null)self::fail();
        $columns=array_keys($fields);$statement=$pdo->prepare('INSERT INTO '.$this->table('marketplace_platform_asset').' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        if($statement===false)self::fail();
        try{if($statement->execute(array_values($fields))!==true||$statement->rowCount()!==1)self::fail();$id=self::id($pdo->lastInsertId());}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $actual=$this->find($request,$scope,$pdo,$id);
        foreach($fields as$key=>$value){
            if(is_int($value)){if((string)$actual[$key]!== (string)$value)self::fail();}
            elseif($actual[$key]!==$value)self::fail();
        }
        return $actual;
    }
    public function listing(Request $request,PlatformScope $scope,PDO $pdo,int $category,string $name,int $page,int $limit): array
    {
        if($category)$this->category($request,$scope,$pdo,$category);
        $where=" WHERE purpose='site_image' AND state IN ('STAGED','BOUND') AND (state='BOUND' OR expires_at IS NULL OR expires_at>?)";$parameters=[time()];
        if($category){$where.=' AND category_id=?';$parameters[]=$category;}
        if($name!==''){$where.=' AND real_name LIKE ?';$parameters[]='%'.$name.'%';}
        $count=$this->rows($request,$scope,$pdo,'SELECT COUNT(*) AS total FROM '.$this->table('marketplace_platform_asset').$where,$parameters);
        if(count($count)!==1||array_keys($count[0])!==['total'])self::fail();$total=self::integer($count[0]['total'],0,PHP_INT_MAX);
        $offset=($page-1)*$limit;if(!is_int($offset)||$offset<0)self::fail();
        $rows=$this->rows($request,$scope,$pdo,'SELECT * FROM '.$this->table('marketplace_platform_asset').$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset.' FOR UPDATE',$parameters);
        foreach($rows as$row){self::asset($row);if((int)$row['category_id'])$this->category($request,$scope,$pdo,(int)$row['category_id']);}
        return ['list'=>$rows,'count'=>$total];
    }
    private static function fail(): never { throw new AuthUnavailableException(); }
}
