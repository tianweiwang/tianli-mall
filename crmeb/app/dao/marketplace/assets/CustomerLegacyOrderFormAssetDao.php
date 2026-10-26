<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use app\services\marketplace\order\CustomerOrderFormConfirmationReader;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Legacy flat-form correspondence only; the named caller owns the real actor and private bytes. */
final class CustomerLegacyOrderFormAssetDao
{
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    public function __construct(private CustomerAssetDao $schema,private CustomerOrderFormConfirmationReader $confirmations){}

    /** The no-reference caller retains the entire original create path. Unknown namespace versions also enter validation. */
    public static function hasReferences(array $form):bool
    {
        foreach($form as$field){
            if(!is_array($field))continue;$values=$field['value']??null;
            if(is_string($values))$values=[$values];if(!is_array($values))continue;
            foreach($values as$value)if(is_string($value)&&str_starts_with($value,'legacy-form:'))return true;
        }
        return false;
    }

    /** Match the original four fields without rewriting submitted text, labels, status or value. */
    public static function bindings(array $form,array $frame):array
    {
        if(!array_is_list($form)||!is_array($frame['fields']??null)||count($form)!==count($frame['fields']))self::fail();
        $bindings=[];$seen=[];
        foreach($frame['fields']as$index=>$definition){
            $field=$form[$index]??null;if(!is_array($field))self::fail();
            $keys=array_keys($field);sort($keys);if($keys!==['label','status','title','value'])self::fail();
            foreach(['title','label']as$key)if($field[$key]!==$definition[$key])self::fail();
            // Original postMore/filterArrayValues preserves bool or filters it to exactly '1' / ''.
            // Keep the writer's actual representation; do not broaden to general PHP truthiness.
            if(!in_array($field['status'],$definition['status']?[true,'1']:[false,''],true))self::fail();
            $value=$field['value'];
            if($definition['label']!=='img'){
                if(!is_string($value)||str_contains($value,'legacy-form:'))self::fail();continue;
            }
            if($value==='')continue;
            if(!is_array($value)||!array_is_list($value)||count($value)>8)self::fail();$fieldSeen=[];
            foreach($value as$reference){
                if(!is_string($reference)||$reference===''||isset($fieldSeen[$reference]))self::fail();$fieldSeen[$reference]=true;
                if(!str_starts_with($reference,'legacy-form:'))continue;
                if(preg_match('/\Alegacy-form:v1:([1-9][0-9]{0,19})\z/D',$reference,$match)!==1)self::fail();
                $id=self::assetId($match[1]);if(isset($seen[$id]))self::fail();$seen[$id]=true;
                $bindings[]=['asset_id'=>$id,'field_index'=>$index];
            }
        }
        usort($bindings,static fn(array $a,array $b):int=>strlen($a['asset_id'])<=>strlen($b['asset_id'])?:strcmp($a['asset_id'],$b['asset_id']));
        return $bindings;
    }

    public function assertUncreated(ConnectionInterface $c,PDO $p,int $uid,string $key):void
    {
        $this->boundary($c,$p,'write');MarketplaceOrderInput::confirmationKey($key);
        if($this->rows($c,$p,'SELECT id FROM '.$this->table($c,'store_order').' WHERE uid=? AND BINARY `unique`=BINARY ? LIMIT 2 FOR UPDATE',[$uid,$key],'write')!==[])self::fail();
    }

    public function lockSelected(ConnectionInterface $c,PDO $p,int $uid,string $key,array $observed,array $form):array
    {
        $this->boundary($c,$p,'write');$this->schema->assertStructure($c,$p);
        $current=$this->confirmations->assertLegacySame($uid,$key,$observed);$out=[];
        foreach(self::bindings($form,$current['frame'])as$binding){
            $row=$this->assetRow($c,$p,$binding['asset_id'],'write');
            $this->asset($p,$row,$uid,'STAGED',$key,$current['frame'],$binding['field_index'],$current['deadline_ms']);
            if(self::integer($row['expires_at'])*1000<=$current['redis_time_ms'])self::fail();
            $out[]=['row'=>$row,'field_index'=>$binding['field_index']];
        }
        return $out;
    }

    /** Caller has the captured primary PDO in its own depth-zero RR READ ONLY transaction. */
    public function readForConfirmation(ConnectionInterface $c,PDO $p,int $uid,string $key,array $observed,string $assetId):array
    {
        $this->boundary($c,$p,'read');$this->schema->assertStructure($c,$p);
        $current=$this->confirmations->assertLegacySame($uid,$key,$observed);
        $row=$this->assetRow($c,$p,$assetId,'read');$field=self::integer($row['form_field_index']??null,0,9);
        $this->asset($p,$row,$uid,'STAGED',$key,$current['frame'],$field,$current['deadline_ms']);
        if(self::integer($row['expires_at'])*1000<=$current['redis_time_ms'])self::fail();return $row;
    }

    /** No actor inference: caller separately authorizes selected uid/gift_uid or the original staff permission. */
    public function readForSavedOrder(ConnectionInterface $c,PDO $p,int $selectedOrderId,string $assetId):array
    {
        $this->boundary($c,$p,'read');$this->schema->assertStructure($c,$p);
        $saved=$this->saved($c,$p,$selectedOrderId,'read');$row=$this->assetRow($c,$p,$assetId,'read');
        $this->savedAsset($p,$saved,$row,$assetId);
        return ['order'=>$saved['order'],'root'=>$saved['root'],'family'=>$saved['family'],'asset'=>$row];
    }

    /** The original order/items callback has completed on this same transaction, not yet committed. */
    public function bindCreated(ConnectionInterface $c,PDO $p,int $uid,string $key,int $orderId,array $observed,array $form,array $selected,int $boundAt):array
    {
        $saved=$this->saved($c,$p,$orderId,'write');$root=$saved['root'];
        if(count($saved['family'])!==1||self::integer($root['pid'],0)!==0||self::integer($root['uid'])!==$uid
            ||self::integer($root['is_del'],0)!==0||self::integer($root['is_system_del'],0)!==0
            ||$root['unique']!==$key||$saved['frame']!==$observed['frame']||$saved['form']!==$form)self::fail();
        if($this->lockSelected($c,$p,$uid,$key,$observed,$form)!==$selected)self::fail();$out=[];
        foreach($selected as$wrapper){
            $row=$wrapper['row'];$id=self::assetId($row['id']);$expected=$row;
            $expected['state']='BOUND';$expected['root_order_id']=$this->number($p,$orderId);$expected['bound_at']=$this->number($p,$boundAt);
            $statement=$p->prepare('UPDATE '.$this->table($c,'marketplace_customer_asset')
                .' SET state=?,root_order_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND state=?'
                .' AND root_order_id IS NULL AND bound_at IS NULL AND retired_at IS NULL'
                .' AND BINARY confirmation_ref=BINARY ? AND form_product_id=? AND BINARY form_definition_sha256=BINARY ? AND form_field_index=?');
            if(!$statement instanceof PDOStatement)self::fail();
            try{if($statement->execute(['BOUND',$orderId,$boundAt,$id,$uid,'order_form_image','STAGED',$row['confirmation_ref'],
                $row['form_product_id'],$row['form_definition_sha256'],$row['form_field_index']])!==true||$statement->rowCount()!==1)self::fail();}
            finally{if($statement->closeCursor()!==true)self::fail();}
            $actual=$this->assetRow($c,$p,$id,'write');if($actual!==$expected)self::fail();$this->savedAsset($p,$saved,$actual,$id);$out[]=$actual;
        }
        if($this->saved($c,$p,$orderId,'write')!==$saved)self::fail();return $out;
    }

    public function assertRows(ConnectionInterface $c,PDO $p,array $rows,bool $committed=false):void
    {
        $mode=$committed?'outside':'write';$this->boundary($c,$p,$mode);
        foreach($rows as$row)if($this->assetRow($c,$p,self::assetId($row['id']),$mode)!==$row)self::fail();
    }

    private function saved(ConnectionInterface $c,PDO $p,int $orderId,string $mode):array
    {
        self::integer($orderId);$id=$orderId;$family=[];$visited=[];$lock=$mode==='write'?' FOR UPDATE':'';
        do{
            if(isset($visited[$id]))self::fail();$visited[$id]=true;
            $found=$this->rows($c,$p,'SELECT * FROM '.$this->table($c,'store_order').' WHERE id=? LIMIT 2'.$lock,[$id],$mode);
            if(count($found)!==1)self::fail();$row=$found[0];MarketplaceTradePolicy::assertLegacyOrder($row);
            if(self::integer($row['id'])!==$id)self::fail();
            $family[]=$row;$parent=in_array($row['pid'],[-1,'-1'],true)?-1:self::integer($row['pid'],0);$id=$parent;
        }while($parent>0);
        $root=$row;$uid=self::integer($root['uid']);self::integer($root['mer_id']??null,0);$order=$family[0];
        foreach($family as$member)if(self::integer($member['uid'])!==$uid||($member['mer_id']??null)!==($root['mer_id']??null))self::fail();
        $refs=self::decoded($root['cart_id']);if(!array_is_list($refs)||$refs===[])self::fail();$first=(string)$refs[0];
        $items=$this->rows($c,$p,'SELECT * FROM '.$this->table($c,'store_order_cart_info').' WHERE oid=? AND BINARY cart_id=BINARY ? LIMIT 2'.$lock,[$root['id'],$first],$mode);
        if(count($items)!==1||self::integer($items[0]['uid'])!==$uid)self::fail();
        $cart=self::decoded($items[0]['cart_info']);$frame=CustomerOrderFormConfirmationReader::legacyDefinition($cart,$uid);
        if($frame['first_cart_ref']!==$first)self::fail();$form=self::decoded($root['custom_form']);self::bindings($form,$frame);
        $selectedForm=self::decoded($order['custom_form']);if($selectedForm!==$form)self::fail();
        return ['order'=>$order,'root'=>$root,'family'=>$family,'frame'=>$frame,'form'=>$form];
    }

    private function savedAsset(PDO $p,array $saved,array $row,string $id):void
    {
        $root=$saved['root'];$uid=self::integer($root['uid']);$key=MarketplaceOrderInput::confirmationKey($root['unique']);
        if(self::integer($row['root_order_id']??null)!==self::integer($root['id']))self::fail();
        foreach(self::bindings($saved['form'],$saved['frame'])as$binding)if($binding['asset_id']===$id){
            $this->asset($p,$row,$uid,'BOUND',$key,$saved['frame'],$binding['field_index'],null);return;
        }
        self::fail();
    }

    private function asset(PDO $p,array $row,int $uid,string $state,string $key,array $frame,int $index,?int $deadline):void
    {
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='order_form_image'||$row['state']!==$state
            ||self::integer($row['uid'])!==$uid||self::integer($row['created_by_uid'])!==$uid
            ||self::integer($row['storage_type'],1,1)!==1||$row['thumbnail_key']!==''
            ||$row['confirmation_ref']!==hash('sha256',$key,true)||self::integer($row['form_product_id'])!==$frame['product_id']
            ||$row['form_definition_sha256']!==hex2bin($frame['definition_sha256'])||self::integer($row['form_field_index'],0,9)!==$index
            ||($frame['fields'][$index]['label']??null)!=='img')self::fail();
        foreach(['retired_at','order_item_id','refund_id','review_id','extract_id','agent_apply_id','source_product_id',
            'source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id']as$field)if($row[$field]!==null)self::fail();
        foreach(['id','uid','storage_type','byte_size','width','height','created_at','expires_at','form_product_id','form_field_index','created_by_uid']as$field)
            if($row[$field]!==$this->number($p,$row[$field]))self::fail();
        self::assetId($row['id']);self::integer($row['created_at']);self::integer($row['byte_size'],1,52428800);
        self::integer($row['width']);self::integer($row['height']);
        foreach(['content_sha256','confirmation_ref','form_definition_sha256']as$field)if(!is_string($row[$field])||strlen($row[$field])!==32)self::fail();
        $object=$row['object_key'];
        if(!is_string($object)||preg_match('/\Aform\/v1\/([1-9][0-9]*)\/([a-f0-9]{64})\/([1-9][0-9]*)\/[a-f0-9]{64}\/content\.(jpg|jpeg|png|gif|webp)\z/D',$object,$parts)!==1
            ||strlen($object)>512||$parts[1]!== (string)$uid||$parts[2]!==bin2hex($row['confirmation_ref']))self::fail();
        $actualDeadline=self::integer($parts[3],1000,4294967295999);
        if(($deadline!==null&&$deadline!==$actualDeadline)||self::integer($row['expires_at'])!==intdiv($actualDeadline,1000))self::fail();
        $mimes=['jpg'=>['image/jpeg','image/jpg'],'jpeg'=>['image/jpeg','image/jpg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp']];
        if(!in_array($row['media_type'],$mimes[$parts[4]],true)||$row['name']!==basename($object)
            ||!is_string($row['real_name'])||$row['real_name']===''||str_contains($row['real_name'],"\0")||mb_strlen($row['real_name'])>255)self::fail();
        if($state==='STAGED'){if($row['root_order_id']!==null||$row['bound_at']!==null)self::fail();}
        else foreach(['root_order_id','bound_at']as$field)if(self::integer($row[$field])<1||$row[$field]!==$this->number($p,$row[$field]))self::fail();
    }

    private function assetRow(ConnectionInterface $c,PDO $p,string $id,string $mode):array
    {
        self::assetId($id);$rows=$this->rows($c,$p,'SELECT * FROM '.$this->table($c,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2'.($mode==='write'?' FOR UPDATE':''),[$id],$mode);
        if(count($rows)!==1)self::fail();return $rows[0];
    }
    private function rows(ConnectionInterface $c,PDO $p,string $sql,array $args,string $mode):array
    {
        $this->boundary($c,$p,$mode);$s=$p->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($args)!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($s->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||!array_is_list($rows))self::fail();$this->boundary($c,$p,$mode);return $rows;
    }
    private function boundary(ConnectionInterface $c,PDO $p,string $mode):void
    {
        if(!$c instanceof Mysql||Db::connect()!==$c||$c->getPdo()!==$p||$c->getConfig('deploy')!==0||$c->getConfig('rw_separate')!==false
            ||$p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($p->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[false,true,0,1],true)
            ||!in_array($mode,['read','write','outside'],true)||$c->transactionDepth()!==($mode==='write'?1:0)
            ||$p->inTransaction()!==($mode!=='outside'))self::fail();
    }
    private function table(ConnectionInterface $c,string $suffix):string
    {
        $prefix=$c->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($suffix,['store_order','store_order_cart_info','marketplace_customer_asset'],true)||strlen($prefix.$suffix)>64)self::fail();
        return '`'.$prefix.$suffix.'`';
    }
    private static function decoded(mixed $value):array
    {
        if(!is_string($value))self::fail();try{$value=json_decode($value,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){self::fail();}
        if(!is_array($value))self::fail();return $value;
    }
    public static function assetId(mixed $id):string
    {
        if(is_int($id)&&$id>0)$id=(string)$id;
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::fail();return $id;
    }
    private function number(PDO $p,mixed $value):int|string
    {
        if($value===0||$value==='0')return in_array($p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,1],true)?'0':0;
        $text=self::assetId($value);return in_array($p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,1],true)
            ||strlen($text)>strlen((string)PHP_INT_MAX)||(strlen($text)===strlen((string)PHP_INT_MAX)&&strcmp($text,(string)PHP_INT_MAX)>0)?$text:(int)$text;
    }
    private static function integer(mixed $v,int $minimum=1,int $maximum=4294967295):int
    {
        if(is_int($v)&&$v>=$minimum&&$v<=$maximum)return $v;
        if(!is_string($v)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1||strlen($v)>strlen((string)$maximum)
            ||(strlen($v)===strlen((string)$maximum)&&strcmp($v,(string)$maximum)>0)||(int)$v<$minimum)self::fail();return (int)$v;
    }
    private static function fail():never{throw new AuthUnavailableException();}
}
