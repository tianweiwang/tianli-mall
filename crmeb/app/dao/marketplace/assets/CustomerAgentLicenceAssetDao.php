<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Named agent-application leaves on the original primary connection; no schema or owner synthesis. */
final class CustomerAgentLicenceAssetDao
{
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    private const APPLICATION=['id','uid','agent_name','name','phone','division_id','division_invite','images',
        'add_time','status','refusal_reason','is_del'];

    public function __construct(private CustomerAssetDao $customers){}

    public function primary(int $uid):array
    {
        self::integer($uid,1,2147483647);$connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $user=$connection->query('SELECT * FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2',[$uid],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->native($connection,$pdo,0);
        if(!is_array($user)||count($user)!==1||self::integer($user[0]['uid']??null,1,2147483647)!==$uid
            ||self::integer($user[0]['status']??null,0,1)!==1||self::integer($user[0]['is_del']??null,0,1)!==0)self::fail();
        return [$connection,$pdo];
    }

    public function platformPrimary(int $applyId):array
    {
        self::integer($applyId,1,2147483647);$connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $connection->query('SELECT id FROM '.$this->table($connection,'division_agent_apply').' WHERE id=? LIMIT 2',[$applyId],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->native($connection,$pdo,0);return [$connection,$pdo];
    }

    public function user(ConnectionInterface $connection,PDO $pdo,int $uid,int $depth):array
    {
        if($depth===1)return $this->customers->lockUser($connection,$pdo,$uid);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2',[$uid],0);
        if(count($rows)!==1||self::integer($rows[0]['uid']??null,1,2147483647)!==$uid
            ||self::integer($rows[0]['status']??null,0,1)!==1||self::integer($rows[0]['is_del']??null,0,1)!==0)self::fail();return $rows[0];
    }

    public function application(ConnectionInterface $connection,PDO $pdo,int $id,int $uid,bool $lock,bool $live=true):array
    {
        self::integer($id,1,2147483647);self::integer($uid,1,2147483647);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'division_agent_apply')
            .' WHERE id=? AND uid=? LIMIT 2'.($lock?' FOR UPDATE':''),[$id,$uid],$lock?1:0);
        if(count($rows)!==1||array_keys($rows[0])!==self::APPLICATION)self::fail();$row=$rows[0];
        if(self::integer($row['id'],1,2147483647)!==$id||self::integer($row['uid'],1,2147483647)!==$uid
            ||($live&&self::integer($row['is_del'],0,1)!==0))self::fail();
        self::integer($row['is_del'],0,1);self::integer($row['status'],0,2);
        self::integer($row['division_id'],0,2147483647);self::images($row['images']);return $row;
    }

    /** Platform identity/range is separately authorized by the named access service. */
    public function platformApplication(ConnectionInterface $connection,PDO $pdo,int $id,int $divisionId):array
    {
        $this->native($connection,$pdo,0);self::integer($id,1,2147483647);self::integer($divisionId,0,2147483647);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'division_agent_apply')
            .' WHERE id=? LIMIT 2',[$id],0);
        if(count($rows)!==1||array_keys($rows[0])!==self::APPLICATION)self::fail();$row=$rows[0];
        self::integer($row['uid'],1,2147483647);
        if(self::integer($row['is_del'],0,1)!==0||($divisionId!==0&&self::integer($row['division_id'],0,2147483647)!==$divisionId))self::fail();
        self::images($row['images']);return $row;
    }

    public function asset(ConnectionInterface $connection,PDO $pdo,string $id,int $uid,bool $lock):array
    {
        self::assetId($id);$rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2'.($lock?' FOR UPDATE':''),[$id],$lock?1:0);
        if(count($rows)!==1)self::fail();$this->assetShape($pdo,$rows[0],$uid);return $rows[0];
    }

    public function insertStaged(ConnectionInterface $connection,PDO $pdo,int $uid,array $physical,string $name):array
    {
        $this->native($connection,$pdo,1);$this->customers->assertStructure($connection,$pdo);
        if(array_keys($physical)!==['uid','object_key','media_type','byte_size','width','height','content_sha256']||$physical['uid']!==$uid)self::fail();
        if(!is_string($name)||$name===''||str_contains($name,"\0")||preg_match('//u',$name)!==1||mb_strlen($name,'UTF-8')>255)self::fail();
        $data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>'agent_licence','state'=>'STAGED','storage_type'=>1,
            'object_key'=>$physical['object_key'],'thumbnail_key'=>'','name'=>basename($physical['object_key']),
            'real_name'=>$name,'media_type'=>$physical['media_type'],'byte_size'=>$physical['byte_size'],
            'width'=>$physical['width'],'height'=>$physical['height'],'content_sha256'=>$physical['content_sha256'],
            'created_at'=>self::integer(time(),1,4294967295),'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','created_by_uid']as $key)$data[$key]=$this->number($pdo,$data[$key]);
        $columns=array_keys($data);$this->execute($connection,$pdo,'INSERT INTO '.$this->table($connection,'marketplace_customer_asset')
            .' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
        $id=self::assetId($pdo->lastInsertId());$expected=['id'=>$this->number($pdo,$id)]+$data;
        if($this->asset($connection,$pdo,$id,$uid,true)!==$expected)self::fail();return $expected;
    }

    public function selected(ConnectionInterface $connection,PDO $pdo,int $uid,int $contextId,array $references):array
    {
        $this->native($connection,$pdo,1);self::references($references);$out=[];
        if(!array_filter($references,fn(string $ref):bool=>str_starts_with($ref,'mp-licence:')))return [];
        $this->customers->assertStructure($connection,$pdo);
        foreach($references as $ref){
            if(!str_starts_with($ref,'mp-licence:'))continue;$id=self::referenceId($ref);
            $row=$this->asset($connection,$pdo,$id,$uid,true);
            if($row['state']==='STAGED'){
                $context=self::objectContext($row['object_key'],$uid);if($context!==$contextId)self::fail();
            }else $this->boundApplication($connection,$pdo,$row,$uid,true);
            $out[$id]=$row;
        }
        return $out;
    }

    public function bind(ConnectionInterface $connection,PDO $pdo,int $uid,array $application,array $selected):array
    {
        $id=self::integer($application['id']??null,1,2147483647);
        if($this->application($connection,$pdo,$id,$uid,true)!==$application)self::fail();$refs=self::images($application['images']);$out=[];
        foreach($selected as $key=>$row){
            $assetId=self::assetId((string)$key);$ref='mp-licence:v1:'.$assetId;
            if(!in_array($ref,$refs,true)||$this->asset($connection,$pdo,$assetId,$uid,true)!==$row)self::fail();
            $expected=$row;
            if($row['state']==='STAGED'){
                $expected['state']='BOUND';$expected['agent_apply_id']=$this->number($pdo,$id);$expected['bound_at']=$this->number($pdo,time());
                $this->execute($connection,$pdo,'UPDATE '.$this->table($connection,'marketplace_customer_asset')
                    .' SET state=?,agent_apply_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND state=?'
                    .' AND agent_apply_id IS NULL AND bound_at IS NULL AND retired_at IS NULL AND expires_at IS NULL',
                    ['BOUND',$id,$expected['bound_at'],$assetId,$uid,'agent_licence','STAGED']);
            }else $this->boundApplication($connection,$pdo,$row,$uid,true);
            if($this->asset($connection,$pdo,$assetId,$uid,true)!==$expected)self::fail();$out[$assetId]=$expected;
        }
        return $out;
    }

    public function boundApplication(ConnectionInterface $connection,PDO $pdo,array $asset,int $uid,bool $lock):array
    {
        $this->assetShape($pdo,$asset,$uid);if($asset['state']!=='BOUND')self::fail();
        // First binding remains intact when an actual later owned application reuses the same image.
        $row=$this->application($connection,$pdo,self::integer($asset['agent_apply_id'],1,2147483647),$uid,$lock,false);
        if(!in_array('mp-licence:v1:'.self::assetId((string)$asset['id']),self::images($row['images']),true))self::fail();return $row;
    }

    public function committed(ConnectionInterface $connection,PDO $pdo,int $uid,array $assets,?array $application):void
    {
        $this->native($connection,$pdo,0);
        $autocommit=$this->rows($connection,$pdo,'SELECT @@session.autocommit AS autocommit',[],0);
        if(count($autocommit)!==1||array_keys($autocommit[0])!==['autocommit']||!in_array($autocommit[0]['autocommit'],[1,'1'],true))self::fail();
        foreach($assets as $row)if($this->asset($connection,$pdo,self::assetId((string)$row['id']),$uid,false)!==$row)self::fail();
        if($application!==null&&$this->application($connection,$pdo,self::integer($application['id'],1,2147483647),$uid,false)!==$application)self::fail();
    }

    public static function references(array $images):array
    {
        if(!array_is_list($images)||count($images)>10)self::fail();
        foreach($images as $image){
            if(!is_string($image)||$image===''||strlen($image)>512||str_contains($image,"\0")||preg_match('//u',$image)!==1)self::fail();
            if(str_starts_with($image,'mp-licence:'))self::referenceId($image);
        }
        return $images;
    }
    public static function images(string $encoded):array
    {
        if($encoded==='')return [];
        try{$images=json_decode($encoded,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        if(!is_array($images))self::fail();return self::references($images);
    }
    public static function referenceId(string $reference):string
    {if(!str_starts_with($reference,'mp-licence:v1:'))self::fail();return self::assetId(substr($reference,14));}
    public static function assetId(mixed $value):string
    {
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0))self::fail();return $value;
    }
    public static function integer(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    public static function objectContext(string $key,int $uid):int
    {
        if(preg_match('/\Aagent-licence\/v1\/([1-9][0-9]*)\/(0|[1-9][0-9]{0,9})\/[a-f0-9]{64}\/content\.(jpg|jpeg|png|gif|webp)\z/D',$key,$m)!==1
            ||$m[1]!== (string)$uid)self::fail();return self::integer($m[2],0,2147483647);
    }
    public function native(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        if(!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||!in_array($depth,[0,1],true)||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    private function assetShape(PDO $pdo,array $row,int $uid):void
    {
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='agent_licence'||!in_array($row['state'],['STAGED','BOUND'],true)
            ||self::integer($row['uid'],1,2147483647)!==$uid||self::integer($row['created_by_uid'],1,2147483647)!==$uid
            ||self::integer($row['storage_type'],1,1)!==1||$row['thumbnail_key']!=='')self::fail();
        self::assetId((string)$row['id']);self::objectContext($row['object_key'],$uid);
        foreach(['root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id','form_definition_sha256',
            'form_field_index','extract_id','source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id','expires_at','retired_at']as $key)
            if($row[$key]!==null)self::fail();
        foreach(['byte_size'=>52428800,'width'=>4294967295,'height'=>4294967295,'created_at'=>4294967295]as$key=>$max)
            self::integer($row[$key],1,$max);
        foreach(['id','uid','storage_type','byte_size','width','height','created_at','created_by_uid']as$key)
            if($this->number($pdo,$row[$key])!==$row[$key])self::fail();
        if(!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32
            ||!in_array($row['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||$row['name']!==basename($row['object_key'])||!is_string($row['real_name'])||$row['real_name']===''
            ||str_contains($row['real_name'],"\0")||preg_match('//u',$row['real_name'])!==1||mb_strlen($row['real_name'],'UTF-8')>255)self::fail();
        if($row['state']==='STAGED'){if($row['bound_at']!==null||$row['agent_apply_id']!==null)self::fail();}
        else{self::integer($row['bound_at'],1,4294967295);self::integer($row['agent_apply_id'],1,2147483647);
            foreach(['bound_at','agent_apply_id']as$key)if($this->number($pdo,$row[$key])!==$row[$key])self::fail();}
    }
    private function number(PDO $pdo,mixed $number):int|string
    {
        $text=self::assetId((string)$number);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();
        return in_array($strings,[true,1],true)||strlen($text)>strlen((string)PHP_INT_MAX)
            ||(strlen($text)===strlen((string)PHP_INT_MAX)&&strcmp($text,(string)PHP_INT_MAX)>0)?$text:(int)$text;
    }
    private function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $values,int $depth):array
    {
        $this->native($connection,$pdo,$depth);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($s->closeCursor()!==true)self::fail();}$this->native($connection,$pdo,$depth);return $rows;
    }
    private function execute(ConnectionInterface $connection,PDO $pdo,string $sql,array $values):void
    {
        $this->native($connection,$pdo,1);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true||$s->rowCount()!==1)self::fail();}finally{if($s->closeCursor()!==true)self::fail();}
        $this->native($connection,$pdo,1);
    }
    private function table(ConnectionInterface $connection,string $name):string
    {
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($name,['marketplace_customer_asset','user','division_agent_apply'],true)||strlen($prefix.$name)>64)self::fail();
        return '`'.$prefix.$name.'`';
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
