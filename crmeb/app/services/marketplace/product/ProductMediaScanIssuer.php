<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\services\system\admin\AdminAuthServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use PDO;
use think\exception\HttpException;
use think\facade\Db;

/** Read-only issuer checks; an anonymous phone never becomes the PC administrator. */
final class ProductMediaScanIssuer
{
    public function __construct(private AdminAuthServices $auth,private LegacyProductAccessServices $access) {}

    public function capture(Request $request): array
    {
        $issuer=$this->requestIssuer($request,'issue');
        $this->auth->verifyProductMediaScanIssuer($issuer);
        return $issuer;
    }

    public function verify(array $issuer,int $productIdOrZero,int $ownerId,int $categoryId): void
    {
        $this->auth->verifyProductMediaScanIssuer($issuer);
        $this->access->authorizeProductScanIssuer((int)$issuer['id'],'issue');
        $this->assertLeaseTarget($productIdOrZero,$ownerId,$categoryId);
    }

    public function forRequest(Request $request,array $issuer,string $operation,int $productIdOrZero,int $ownerId,int $categoryId): void
    {
        $current=$this->requestIssuer($request,$operation);
        $this->verify($issuer,$productIdOrZero,$ownerId,$categoryId);
        foreach(['id','sid','version','fingerprint','epoch']as$field)
            if(!hash_equals($current[$field],$issuer[$field]))throw new HttpException(403,'没有权限访问');
    }

    private function requestIssuer(Request $request,string $operation): array
    {
        $action=LegacyProductAccessServices::PRODUCT_SCAN_ACTIONS[$operation]??null;
        if($action===null||app()->request!==$request||!LegacyProductAccessServices::isWriter($request)
            ||$request->rule()->getOption('legacy_product_write')!==$action||$request->isAdminLogin()!==true)
            throw new AuthUnavailableException();
        $scope=$request->platformScope();$this->access->authorizeScope($scope);
        $verified=$request->verifiedAdminSession();$admin=$request->adminInfo();$id=(string)$request->adminId();
        if(!is_array($admin)||(string)($admin['id']??'')!==$id||$verified['id']!==$id
            ||$scope->adminId!==(int)$id||!hash_equals($request->verifiedTokenFingerprint(),$verified['fingerprint']))
            throw new AuthSessionException();
        $config=AuthConfigGuard::authConfig();
        return $verified+['epoch'=>$config['session_epoch']];
    }

    /** Same identity predicates as the existing writer; this is not a writer transaction. */
    public function assertLeaseTarget(int $productIdOrZero,int $ownerId,int $categoryId): void
    {
        try{
            self::integer($productIdOrZero,0,8388607);self::integer($ownerId,1,4294967295);self::integer($categoryId,0,2147483647);
            $connection=Db::connect();$pdo=$connection->getPdo();
            if(!$pdo instanceof PDO||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new AuthUnavailableException();
            self::same($connection,$pdo);$prefix=$connection->getConfig('prefix');
            if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)throw new AuthUnavailableException();
            $product=self::table($prefix,'store_product');$merchant=self::table($prefix,'marketplace_merchant');
            $category=self::table($prefix,'system_attachment_category');
            if($productIdOrZero===0){
                if(LegacyProductWriteServices::selfId()!==$ownerId)throw new HttpException(404,'资源不存在');
                self::same($connection,$pdo);
            }else{
                $rows=$this->rows($connection,$pdo,'SELECT id,mer_id FROM '.$product.' WHERE id=? LIMIT 2',[$productIdOrZero]);
                if(count($rows)!==1||array_keys($rows[0])!==['id','mer_id']
                    ||self::integer($rows[0]['id'],1,8388607)!==$productIdOrZero
                    ||self::integer($rows[0]['mer_id'],1,4294967295)!==$ownerId)throw new HttpException(404,'资源不存在');
            }
            $merchants=$this->rows($connection,$pdo,'SELECT mer_id FROM '.$merchant.' WHERE mer_id=? LIMIT 2',[$ownerId]);
            if(count($merchants)!==1||array_keys($merchants[0])!==['mer_id']
                ||self::integer($merchants[0]['mer_id'],1,4294967295)!==$ownerId)throw new HttpException(404,'资源不存在');
            if($categoryId>0){
                $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$category.' WHERE id=? AND mer_id=? AND type=0 LIMIT 2',[$categoryId,$ownerId]);
                if(count($rows)!==1)throw new HttpException(404,'资源不存在');$row=$rows[0];
                if(array_keys($row)!==['id','pid','name','enname','type','mer_id']
                    ||self::integer($row['id'],1,2147483647)!==$categoryId
                    ||self::integer($row['mer_id'],1,4294967295)!==$ownerId||self::integer($row['type'],0,1)!==0)
                    throw new AuthUnavailableException();
                self::integer($row['pid'],0,2147483647);self::text($row['name'],50);self::text($row['enname'],50);
            }
            self::same($connection,$pdo);
        }catch(AuthException|HttpException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }

    private static function same(object $connection,PDO $pdo): void
    {
        if(Db::connect()!==$connection||$connection->getPdo()!==$pdo)throw new AuthUnavailableException();
    }

    private function rows(object $connection,PDO $pdo,string $sql,array $parameters): array
    {
        self::same($connection,$pdo);$statement=$pdo->prepare($sql);
        if(!$statement)throw new AuthUnavailableException();
        try{
            if($statement->execute($parameters)!==true)throw new AuthUnavailableException();
            $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))throw new AuthUnavailableException();
            foreach($rows as$row)if(!is_array($row))throw new AuthUnavailableException();
        }finally{if($statement->closeCursor()!==true)throw new AuthUnavailableException();}
        self::same($connection,$pdo);return $rows;
    }

    private static function table(string $prefix,string $suffix): string
    {
        $name=$prefix.$suffix;
        if(strlen($name)>64||!in_array($suffix,['store_product','marketplace_merchant','system_attachment_category'],true))
            throw new AuthUnavailableException();
        return chr(96).$name.chr(96);
    }

    private static function integer(mixed $value,int $min,int $max): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=19
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)throw new AuthUnavailableException();return $value;
    }

    private static function text(mixed $value,int $max): void
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)
            throw new AuthUnavailableException();
    }
}
