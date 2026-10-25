<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Only withdrawal QR metadata on the original primary PDO; no financial writer or merchant owner. */
final class CustomerWithdrawalQrAssetDao
{
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    private const PREFIX='mp-withdrawal:v1:';
    public function __construct(private CustomerAssetDao $customers){}

    public function primary(int $uid):array
    {
        self::integer($uid,1,4294967295);$connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $connection->query('SELECT * FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2',[$uid],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->user($connection,$pdo,$uid,0);
        return [$connection,$pdo];
    }
    public function platformPrimary(int $id):array
    {
        self::integer($id,1,4294967295);$connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $connection->query('SELECT id FROM '.$this->table($connection,'user_extract').' WHERE id=? LIMIT 2',[$id],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->native($connection,$pdo,0);return [$connection,$pdo];
    }
    public function user(ConnectionInterface $connection,PDO $pdo,int $uid,int $depth):array
    {
        self::integer($uid,1,4294967295);$rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'user')
            .' WHERE uid=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[$uid],$depth);
        if(count($rows)!==1||self::integer($rows[0]['uid']??null,1,4294967295)!==$uid
            ||self::integer($rows[0]['status']??null,0,1)!==1||self::integer($rows[0]['is_del']??null,0,1)!==0)self::fail();return $rows[0];
    }
    public function extract(ConnectionInterface $connection,PDO $pdo,int $id,?int $uid,bool $lock):array
    {
        self::integer($id,1,4294967295);if($uid!==null)self::integer($uid,1,4294967295);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'user_extract')
            .' WHERE id=?'.($uid===null?'':' AND uid=?').' LIMIT 2'.($lock?' FOR UPDATE':''),$uid===null?[$id]:[$id,$uid],$lock?1:0);
        if(count($rows)!==1||array_keys($rows[0])!==self::extractFields())self::fail();$row=$rows[0];
        if(self::integer($row['id'],1,4294967295)!==$id||($uid!==null&&self::integer($row['uid'],1,4294967295)!==$uid)
            ||!in_array($row['extract_type'],['weixin','alipay'],true)||!is_string($row['qrcode_url'])
            ||strlen($row['qrcode_url'])>255)self::fail();self::integer($row['uid'],1,4294967295);return $row;
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
        if($name===''||str_contains($name,"\0")||preg_match('//u',$name)!==1||mb_strlen($name,'UTF-8')>255)self::fail();
        $data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>'withdrawal_qr','state'=>'STAGED','storage_type'=>1,
            'object_key'=>$physical['object_key'],'thumbnail_key'=>'','name'=>basename($physical['object_key']),'real_name'=>$name,
            'media_type'=>$physical['media_type'],'byte_size'=>$physical['byte_size'],'width'=>$physical['width'],
            'height'=>$physical['height'],'content_sha256'=>$physical['content_sha256'],'created_at'=>time(),'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','created_by_uid']as$key)$data[$key]=$this->number($pdo,$data[$key]);
        $columns=array_keys($data);$this->execute($connection,$pdo,'INSERT INTO '.$this->table($connection,'marketplace_customer_asset')
            .' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
        $id=self::assetId($pdo->lastInsertId());$expected=['id'=>$this->number($pdo,$id)]+$data;
        if($this->asset($connection,$pdo,$id,$uid,true)!==$expected)self::fail();return $expected;
    }
    public function selected(ConnectionInterface $connection,PDO $pdo,int $uid,string $reference):array
    {
        $this->native($connection,$pdo,1);$this->customers->assertStructure($connection,$pdo);
        $row=$this->asset($connection,$pdo,self::referenceId($reference),$uid,true);
        if($row['state']==='BOUND')$this->boundExtract($connection,$pdo,$row,$uid,true);return $row;
    }
    public function bind(ConnectionInterface $connection,PDO $pdo,int $uid,array $extract,array $selected):array
    {
        $id=self::integer($extract['id']??null,1,4294967295);$assetId=self::assetId((string)$selected['id']);
        if($this->extract($connection,$pdo,$id,$uid,true)!==$extract||$extract['qrcode_url']!==self::reference($assetId)
            ||$this->asset($connection,$pdo,$assetId,$uid,true)!==$selected)self::fail();$expected=$selected;
        if($selected['state']==='STAGED'){
            $expected['state']='BOUND';$expected['extract_id']=$this->number($pdo,$id);$expected['bound_at']=$this->number($pdo,time());
            $this->execute($connection,$pdo,'UPDATE '.$this->table($connection,'marketplace_customer_asset')
                .' SET state=?,extract_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND state=?'
                .' AND extract_id IS NULL AND bound_at IS NULL AND retired_at IS NULL AND expires_at IS NULL',
                ['BOUND',$id,$expected['bound_at'],$assetId,$uid,'withdrawal_qr','STAGED']);
        }else $this->boundExtract($connection,$pdo,$selected,$uid,true);
        if($this->asset($connection,$pdo,$assetId,$uid,true)!==$expected)self::fail();return $expected;
    }
    public function boundExtract(ConnectionInterface $connection,PDO $pdo,array $asset,int $uid,bool $lock):array
    {
        $this->assetShape($pdo,$asset,$uid);if($asset['state']!=='BOUND')self::fail();
        $row=$this->extract($connection,$pdo,self::integer($asset['extract_id'],1,4294967295),$uid,$lock);
        if($row['qrcode_url']!==self::reference((string)$asset['id']))self::fail();return $row;
    }
    public function committed(ConnectionInterface $connection,PDO $pdo,int $uid,array $asset,?array $extract):void
    {
        $this->native($connection,$pdo,0);$auto=$this->rows($connection,$pdo,'SELECT @@session.autocommit AS autocommit',[],0);
        if(count($auto)!==1||array_keys($auto[0])!==['autocommit']||!in_array($auto[0]['autocommit'],[1,'1'],true))self::fail();
        if($this->asset($connection,$pdo,(string)$asset['id'],$uid,false)!==$asset)self::fail();
        if($extract!==null&&$this->extract($connection,$pdo,self::integer($extract['id'],1,4294967295),$uid,false)!==$extract)self::fail();
        if($asset['state']==='BOUND')$this->boundExtract($connection,$pdo,$asset,$uid,false);
    }
    public static function isPrivate(mixed $ref):bool {return is_string($ref)&&str_starts_with($ref,'mp-withdrawal:');}
    public static function reference(string $id):string {return self::PREFIX.self::assetId($id);}
    public static function referenceId(string $ref):string
    {if(!str_starts_with($ref,self::PREFIX))self::fail();return self::assetId(substr($ref,strlen(self::PREFIX)));}
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
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='withdrawal_qr'||!in_array($row['state'],['STAGED','BOUND'],true)
            ||self::integer($row['uid'],1,4294967295)!==$uid||self::integer($row['created_by_uid'],1,4294967295)!==$uid
            ||self::integer($row['storage_type'],1,1)!==1||$row['thumbnail_key']!=='')self::fail();
        self::assetId((string)$row['id']);
        if(!is_string($row['object_key'])||preg_match('/\Awithdrawal-qr\/v1\/([1-9][0-9]*)\/[a-f0-9]{64}\/content\.(jpg|jpeg|png|gif|webp)\z/D',$row['object_key'],$m)!==1
            ||$m[1]!==(string)$uid)self::fail();
        foreach(['root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id','form_definition_sha256',
            'form_field_index','agent_apply_id','source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id','expires_at','retired_at']as$key)
            if($row[$key]!==null)self::fail();
        foreach(['byte_size'=>52428800,'width'=>4294967295,'height'=>4294967295,'created_at'=>4294967295]as$key=>$max)self::integer($row[$key],1,$max);
        foreach(['id','uid','storage_type','byte_size','width','height','created_at','created_by_uid']as$key)if($this->number($pdo,$row[$key])!==$row[$key])self::fail();
        if(!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32
            ||!in_array($row['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||$row['name']!==basename($row['object_key'])||!is_string($row['real_name'])||$row['real_name']===''
            ||str_contains($row['real_name'],"\0")||preg_match('//u',$row['real_name'])!==1||mb_strlen($row['real_name'],'UTF-8')>255)self::fail();
        if($row['state']==='STAGED'){if($row['bound_at']!==null||$row['extract_id']!==null)self::fail();}
        else{self::integer($row['bound_at'],1,4294967295);self::integer($row['extract_id'],1,4294967295);
            foreach(['bound_at','extract_id']as$key)if($this->number($pdo,$row[$key])!==$row[$key])self::fail();}
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
        try{if($s->execute($values)!==true||$s->rowCount()!==1)self::fail();}finally{if($s->closeCursor()!==true)self::fail();}$this->native($connection,$pdo,1);
    }
    private function table(ConnectionInterface $connection,string $name):string
    {
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($name,['marketplace_customer_asset','user','user_extract'],true)||strlen($prefix.$name)>64)self::fail();return '`'.$prefix.$name.'`';
    }
    private static function extractFields():array
    {
        return ['id','wechat_order_id','uid','real_name','user_name','extract_type','bank_code','bank_address','alipay_code',
            'extract_price','extract_fee','mark','balance','fail_msg','fail_time','add_time','status','wechat','qrcode_url',
            'channel_type','out_bill_no','transfer_bill_no','state','package_info','fail_reason'];
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
