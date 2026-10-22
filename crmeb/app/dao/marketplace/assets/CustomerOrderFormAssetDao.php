<?php
declare(strict_types=1);

namespace app\dao\marketplace\assets;

use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceOrderSnapshotProjection;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Form-only data leaves. The named caller owns authentication, parent locks and file verification. */
final class CustomerOrderFormAssetDao
{
    private const UINT64='18446744073709551615';
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    private const NULL_FIELDS=['retired_at','order_item_id','refund_id','review_id','extract_id','agent_apply_id',
        'source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id'];
    private const PHYSICAL=['uid','object_key','media_type','byte_size','width','height','content_sha256'];

    public function __construct(private CustomerAssetDao $schema){}

    /** All 33 non-ID values and their PDO representation are fixed before the INSERT. */
    public function insertStaged(ConnectionInterface $connection,PDO $pdo,int $uid,string $key,int $productId,
        string $definitionHex,int $fieldIndex,array $physical,string $originalName,int $createdAt,int $deadlineMs):array
    {
        $this->writer($connection,$pdo);$this->schema->assertStructure($connection,$pdo);
        self::integer($uid,1,2147483647);$key=MarketplaceOrderInput::confirmationKey($key);
        self::integer($productId,1,8388607);self::integer($fieldIndex,0,9);self::integer($createdAt,1,4294967295);
        self::hex($definitionHex);self::integer($deadlineMs,1000,4294967295999);self::keys($physical,self::PHYSICAL);
        if($physical['uid']!==$uid)self::fail();self::objectParts($physical['object_key']);self::text($originalName,1,255);
        $data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>'order_form_image','state'=>'STAGED','storage_type'=>1,
            'object_key'=>$physical['object_key'],'thumbnail_key'=>'','name'=>basename($physical['object_key']),
            'real_name'=>$originalName,'media_type'=>$physical['media_type'],'byte_size'=>$physical['byte_size'],
            'width'=>$physical['width'],'height'=>$physical['height'],'content_sha256'=>$physical['content_sha256'],
            'created_at'=>$createdAt,'expires_at'=>intdiv($deadlineMs,1000),'confirmation_ref'=>hash('sha256',$key,true),
            'form_product_id'=>$productId,'form_definition_sha256'=>hex2bin($definitionHex),
            'form_field_index'=>$fieldIndex,'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','expires_at','form_product_id',
            'form_field_index','created_by_uid'] as $field)$data[$field]=$this->number($pdo,$data[$field]);
        $this->formData($pdo,$data,$uid,'STAGED');
        $this->sameBinding($data,$key,$productId,$definitionHex,$fieldIndex,$deadlineMs);
        $this->writer($connection,$pdo);$columns=array_keys($data);
        $statement=$pdo->prepare('INSERT INTO '.$this->table($connection,'marketplace_customer_asset')
            .' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute(array_values($data))!==true||$statement->rowCount()!==1)self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $id=self::decimal($pdo->lastInsertId());$expected=['id'=>$this->number($pdo,$id)]+$data;
        $this->formRow($pdo,$expected,$uid,'STAGED');
        if($this->byId($connection,$pdo,$id,true)!==[$expected])self::fail();
        $this->writer($connection,$pdo);return $expected;
    }

    /** The caller has already validated the real frozen definitions and actual locked selection. */
    public function lockSelected(ConnectionInterface $connection,PDO $pdo,int $uid,string $key,array $snapshot,int $deadlineMs):array
    {
        $this->writer($connection,$pdo);self::integer($uid,1,2147483647);$key=MarketplaceOrderInput::confirmationKey($key);
        self::integer($deadlineMs,1000,4294967295999);$this->schema->assertStructure($connection,$pdo);
        $bindings=$this->references($snapshot);$out=[];
        foreach($bindings as $binding){
            $rows=$this->byId($connection,$pdo,$binding['asset_id'],true);if(count($rows)!==1)self::fail();$row=$rows[0];
            $this->formRow($pdo,$row,$uid,'STAGED');
            $this->sameBinding($row,$key,$binding['product_id'],$binding['definition_sha256'],$binding['field_index'],$deadlineMs);
            $out[]=['row'=>$row,'product_id'=>$binding['product_id'],'definition_sha256'=>$binding['definition_sha256'],
                'field_index'=>$binding['field_index']];
        }
        $this->writer($connection,$pdo);return $out;
    }

    /** Same owning transaction; only state/root_order_id/bound_at change on distinct selected rows. */
    public function bindCreated(ConnectionInterface $connection,PDO $pdo,int $uid,string $key,array $root,array $wrappers,int $boundAt):array
    {
        $this->writer($connection,$pdo);self::integer($uid,1,2147483647);$key=MarketplaceOrderInput::confirmationKey($key);
        self::integer($boundAt,1,4294967295);$this->savedRoot($connection,$pdo,$root,$uid,$key);
        if(!array_is_list($wrappers))self::fail();$bindings=$this->references($root['custom_form']);
        if(count($wrappers)!==count($bindings))self::fail();$before=[];$expected=[];
        foreach($bindings as $index=>$binding){
            $wrapper=$wrappers[$index];if(!is_array($wrapper))self::fail();
            self::keys($wrapper,['row','product_id','definition_sha256','field_index']);$row=$wrapper['row'];
            if(!is_array($row)||$wrapper['product_id']!==$binding['product_id']
                ||$wrapper['definition_sha256']!==$binding['definition_sha256']||$wrapper['field_index']!==$binding['field_index'])self::fail();
            $this->formRow($pdo,$row,$uid,'STAGED');
            if(self::decimal($row['id'])!==$binding['asset_id'])self::fail();
            $this->sameBinding($row,$key,$binding['product_id'],$binding['definition_sha256'],$binding['field_index'],self::objectParts($row['object_key'])['deadline_ms']);
            if($this->byId($connection,$pdo,$binding['asset_id'],true)!==[$row])self::fail();
            $before[]=$row;$bound=$row;$bound['state']='BOUND';$bound['root_order_id']=$this->number($pdo,$root['id']);
            $bound['bound_at']=$this->number($pdo,$boundAt);$this->formRow($pdo,$bound,$uid,'BOUND');$expected[]=$bound;
        }
        foreach($before as $index=>$row){
            $this->savedRoot($connection,$pdo,$root,$uid,$key);
            if($this->byId($connection,$pdo,self::decimal($row['id']),true)!==[$row])self::fail();
            $statement=$pdo->prepare('UPDATE '.$this->table($connection,'marketplace_customer_asset')
                .' SET state=?,root_order_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND state=?'
                .' AND root_order_id IS NULL AND bound_at IS NULL AND retired_at IS NULL'
                .' AND BINARY confirmation_ref=BINARY ? AND form_product_id=?'
                .' AND BINARY form_definition_sha256=BINARY ? AND form_field_index=? AND expires_at=?');
            if(!$statement instanceof PDOStatement)self::fail();
            try{if($statement->execute(['BOUND',$root['id'],$boundAt,self::decimal($row['id']),$uid,'order_form_image','STAGED',
                $row['confirmation_ref'],$row['form_product_id'],$row['form_definition_sha256'],$row['form_field_index'],$row['expires_at']])!==true
                ||$statement->rowCount()!==1)self::fail();}
            finally{if($statement->closeCursor()!==true)self::fail();}
            if($this->byId($connection,$pdo,self::decimal($row['id']),true)!==[$expected[$index]])self::fail();
        }
        $this->savedRoot($connection,$pdo,$root,$uid,$key);$this->writer($connection,$pdo);return $expected;
    }

    /** Upload success additionally observes the same exact committed row outside the owning transaction. */
    public function assertStagedCommitted(ConnectionInterface $connection,PDO $pdo,int $uid,array $row):void
    {
        $this->outside($connection,$pdo);$this->formRow($pdo,$row,$uid,'STAGED');
        $mode=$this->rows($connection,$pdo,'SELECT @@session.autocommit AS autocommit',[]);
        if(count($mode)!==1||array_keys($mode[0])!==['autocommit']||!in_array($mode[0]['autocommit'],[1,'1'],true))self::fail();
        if($this->byId($connection,$pdo,self::decimal($row['id']),false)!==[$row])self::fail();$this->outside($connection,$pdo);
    }

    /** The caller owns real user/parent locks, unchanged confirmation observation and private bytes. */
    public function lockForConfirmation(ConnectionInterface $connection,PDO $pdo,int $uid,string $key,string $assetId,array $realFrame,int $deadlineMs):array
    {
        $this->writer($connection,$pdo);self::integer($uid,1,2147483647);$key=MarketplaceOrderInput::confirmationKey($key);
        self::decimal($assetId);self::integer($deadlineMs,1000,4294967295999);
        $frame=\app\services\marketplace\order\MarketplaceCartSelection::frame($realFrame,$uid);
        $this->schema->assertStructure($connection,$pdo);$rows=$this->byId($connection,$pdo,$assetId,true);
        if(count($rows)!==1)self::fail();$row=$rows[0];$this->formRow($pdo,$row,$uid,'STAGED');
        $productId=self::integer($row['form_product_id'],1,8388607);$fieldIndex=self::integer($row['form_field_index'],0,9);
        foreach($frame['custom_form'] as $group){
            if($group['product_id']!==$productId)continue;$field=$group['fields'][$fieldIndex]??null;
            if(!is_array($field)||$field['field_index']!==$fieldIndex||$field['label']!=='img')self::fail();
            $this->sameBinding($row,$key,$productId,$group['definition_sha256'],$fieldIndex,$deadlineMs);
            $this->writer($connection,$pdo);return $row;
        }
        self::fail();
    }

    /** Data correspondence only; the named caller owns current grants, native RR READ ONLY and private bytes. */
    public function readForSavedRoot(ConnectionInterface $connection,PDO $pdo,array $actualFullRoot,string $assetId):array
    {
        $this->readNative($connection,$pdo);self::decimal($assetId);
        $rootId=self::integer($actualFullRoot['id']??null,1,4294967295);
        $uid=self::integer($actualFullRoot['uid']??null,1,2147483647);
        if(self::integer($actualFullRoot['pid']??null,0,0)!==0
            ||self::integer($actualFullRoot['marketplace_trade_version']??null,1,1)!==1
            ||!is_string($actualFullRoot['unique']??null)||!is_string($actualFullRoot['custom_form']??null))self::fail();
        $key=MarketplaceOrderInput::confirmationKey($actualFullRoot['unique']);
        $this->schema->assertStructure($connection,$pdo);
        if($this->readSavedRootRows($connection,$pdo,$rootId)!==[$actualFullRoot])self::fail();
        $rows=$this->readSavedAssetRows($connection,$pdo,$assetId);if(count($rows)!==1)self::fail();$row=$rows[0];
        $this->formRow($pdo,$row,$uid,'BOUND');
        if(self::decimal($row['root_order_id'],'4294967295')!==self::decimal($actualFullRoot['id'],'4294967295'))self::fail();
        foreach($this->references($actualFullRoot['custom_form']) as $binding){
            if($binding['asset_id']!==$assetId)continue;
            $this->sameBinding($row,$key,$binding['product_id'],$binding['definition_sha256'],$binding['field_index'],
                self::objectParts($row['object_key'])['deadline_ms']);
            $this->readNative($connection,$pdo);return $row;
        }
        self::fail();
    }

    /** These full-row checks do not establish caller authorization or read the private file. */
    private function formRow(PDO $pdo,array $row,int $uid,string $state):void
    {
        if(array_keys($row)!==self::FIELDS)self::fail();$id=self::decimal($row['id']);
        if($row['id']!==$this->number($pdo,$id))self::fail();unset($row['id']);$this->formData($pdo,$row,$uid,$state);
    }

    private function formData(PDO $pdo,array $row,int $uid,string $state):void
    {
        if(array_keys($row)!==array_slice(self::FIELDS,1)||$row['purpose']!=='order_form_image'||$row['state']!==$state
            ||!in_array($state,['STAGED','BOUND'],true))self::fail();
        foreach(['uid'=>'2147483647','storage_type'=>'1','byte_size'=>'52428800',
            'width'=>'4294967295','height'=>'4294967295','created_at'=>'4294967295','expires_at'=>'4294967295',
            'form_product_id'=>'8388607','created_by_uid'=>'2147483647'] as $field=>$maximum){
            $text=self::decimal($row[$field],$maximum);if($row[$field]!==$this->number($pdo,$text))self::fail();
        }
        if(self::integer($row['uid'],1,2147483647)!==$uid||self::integer($row['created_by_uid'],1,2147483647)!==$uid
            ||self::integer($row['storage_type'],1,1)!==1||$row['thumbnail_key']!=='')self::fail();
        $index=self::integer($row['form_field_index'],0,9);if($row['form_field_index']!==$this->number($pdo,$index))self::fail();
        foreach(['content_sha256','confirmation_ref','form_definition_sha256'] as $field)
            if(!is_string($row[$field])||strlen($row[$field])!==32)self::fail();
        self::text($row['name'],1,100);self::text($row['real_name'],1,255);$parts=self::objectParts($row['object_key']);
        if($parts['uid']!==$uid||$parts['confirmation_hex']!==bin2hex($row['confirmation_ref'])
            ||self::integer($row['expires_at'],1,4294967295)!==intdiv($parts['deadline_ms'],1000)
            ||$row['name']!==basename($row['object_key']))self::fail();
        $mimes=['jpg'=>['image/jpg','image/jpeg'],'jpeg'=>['image/jpg','image/jpeg'],'png'=>['image/png'],
            'gif'=>['image/gif'],'webp'=>['image/webp']];
        if(!is_string($row['media_type'])||!in_array($row['media_type'],$mimes[$parts['extension']],true))self::fail();
        foreach(self::NULL_FIELDS as $field)if($row[$field]!==null)self::fail();
        if($state==='STAGED'){if($row['bound_at']!==null||$row['root_order_id']!==null)self::fail();}
        else foreach(['bound_at','root_order_id'] as $field){
            self::decimal($row[$field],'4294967295');if($row[$field]!==$this->number($pdo,$row[$field]))self::fail();
        }
    }

    private function sameBinding(array $row,string $key,int $productId,string $definitionHex,int $fieldIndex,int $deadlineMs):void
    {
        self::hex($definitionHex);$parts=self::objectParts($row['object_key']);
        if($row['confirmation_ref']!==hash('sha256',$key,true)||self::integer($row['form_product_id'],1,8388607)!==$productId
            ||$row['form_definition_sha256']!==hex2bin($definitionHex)||self::integer($row['form_field_index'],0,9)!==$fieldIndex
            ||$parts['deadline_ms']!==$deadlineMs)self::fail();
    }

    /** Decimal sorting never casts an unsigned64 asset ID through a PHP/JavaScript number. */
    private function references(mixed $snapshot):array
    {
        $out=[];
        foreach(MarketplaceOrderSnapshotProjection::customForms($snapshot) as $group)foreach($group['fields'] as $field){
            if($field['label']!=='img')continue;
            foreach($field['value'] as $reference){
                if(!str_starts_with($reference,'mp-form:'))continue;
                if(preg_match('/\Amp-form:v1:([1-9][0-9]{0,19})\z/D',$reference,$match)!==1)self::fail();
                $id=self::decimal($match[1]);$binding=['asset_id'=>$id,'product_id'=>$group['product_id'],
                    'definition_sha256'=>$group['definition_sha256'],'field_index'=>$field['field_index']];$name='asset:'.$id;
                if(isset($out[$name])&&$out[$name]!==$binding)self::fail();$out[$name]=$binding;
            }
        }
        $out=array_values($out);usort($out,static fn(array $a,array $b):int=>strlen($a['asset_id'])<=>strlen($b['asset_id'])
            ?:strcmp($a['asset_id'],$b['asset_id']));return $out;
    }

    private function savedRoot(ConnectionInterface $connection,PDO $pdo,array $root,int $uid,string $key):void
    {
        $this->writer($connection,$pdo);$id=self::integer($root['id']??null,1,4294967295);
        if(self::integer($root['uid']??null,1,2147483647)!==$uid||self::integer($root['pid']??null,0,0)!==0
            ||self::integer($root['marketplace_trade_version']??null,1,1)!==1||($root['unique']??null)!==$key
            ||!is_string($root['custom_form']??null))self::fail();
        if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? LIMIT 2 FOR UPDATE',[$id])!==[$root])self::fail();
    }

    private static function objectParts(mixed $key):array
    {
        if(!is_string($key)||preg_match('/\Aform\/v1\/([1-9][0-9]*)\/([a-f0-9]{64})\/([1-9][0-9]*)\/([a-f0-9]{64})\/content\.(jpg|jpeg|png|gif|webp)\z/D',$key,$m)!==1
            ||strlen($key)>512)self::fail();
        return ['uid'=>self::integer($m[1],1,2147483647),'confirmation_hex'=>$m[2],
            'deadline_ms'=>self::integer($m[3],1000,4294967295999),'extension'=>$m[5]];
    }

    /** These two fixed full-row SELECTs only serve readForSavedRoot; no writer gate is relaxed. */
    private function readSavedRootRows(ConnectionInterface $connection,PDO $pdo,int $rootId):array
    {
        $this->readNative($connection,$pdo);$statement=$pdo->prepare('SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? LIMIT 2');
        if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute([$rootId])!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $this->readNative($connection,$pdo);return $rows;
    }
    private function readSavedAssetRows(ConnectionInterface $connection,PDO $pdo,string $assetId):array
    {
        $this->readNative($connection,$pdo);$statement=$pdo->prepare('SELECT * FROM '.$this->table($connection,'marketplace_customer_asset').' WHERE id=? LIMIT 2');
        if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute([$assetId])!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $this->readNative($connection,$pdo);return $rows;
    }
    /** RR/READ ONLY is started and owned by the caller; this leaf verifies the captured active native connection. */
    private function readNative(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,false,0,1],true)
            ||$connection->transactionDepth()!==0||!$pdo->inTransaction())self::fail();
    }

    private function byId(ConnectionInterface $connection,PDO $pdo,string $id,bool $lock):array
    {
        self::decimal($id);if($lock)$this->writer($connection,$pdo);else $this->outside($connection,$pdo);
        return $this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2'.($lock?' FOR UPDATE':''),[$id]);
    }
    private function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $arguments):array
    {
        $this->native($connection,$pdo);$statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute($arguments)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $this->native($connection,$pdo);return $rows;
    }
    private function native(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,false,0,1],true))self::fail();
        $depth=$connection->transactionDepth();if(!in_array($depth,[0,1],true)||($depth===1)!==$pdo->inTransaction())self::fail();
    }
    private function writer(ConnectionInterface $connection,PDO $pdo):void
    {$this->native($connection,$pdo);if($connection->transactionDepth()!==1)self::fail();}
    private function outside(ConnectionInterface $connection,PDO $pdo):void
    {$this->native($connection,$pdo);if($connection->transactionDepth()!==0)self::fail();}
    private function table(ConnectionInterface $connection,string $suffix):string
    {
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($suffix,['marketplace_customer_asset','store_order'],true)||strlen($prefix.$suffix)>64)self::fail();
        return '`'.$prefix.$suffix.'`';
    }
    private function number(PDO $pdo,mixed $value):int|string
    {
        $text=self::zeroDecimal($value);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();
        return in_array($strings,[true,1],true)||self::greater($text,(string)PHP_INT_MAX)?$text:(int)$text;
    }
    private static function decimal(mixed $value,string $maximum=self::UINT64):string
    {$text=self::zeroDecimal($value);if($text==='0'||self::greater($text,$maximum))self::fail();return $text;}
    private static function zeroDecimal(mixed $value):string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1||self::greater($value,self::UINT64))self::fail();return $value;
    }
    private static function greater(string $value,string $maximum):bool
    {return strlen($value)>strlen($maximum)||(strlen($value)===strlen($maximum)&&strcmp($value,$maximum)>0);}
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        $text=self::zeroDecimal($value);if(self::greater($text,(string)$maximum)||(int)$text<$minimum)self::fail();return (int)$text;
    }
    private static function hex(string $value):void
    {if(preg_match('/\A[a-f0-9]{64}\z/D',$value)!==1)self::fail();}
    private static function text(mixed $value,int $minimum,int $maximum):void
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1
            ||mb_strlen($value,'UTF-8')<$minimum||mb_strlen($value,'UTF-8')>$maximum)self::fail();
    }
    private static function keys(array $value,array $wanted):void
    {$keys=array_keys($value);sort($keys,SORT_STRING);sort($wanted,SORT_STRING);if($keys!==$wanted)self::fail();}
    private static function fail():never {throw new AuthUnavailableException();}
}
