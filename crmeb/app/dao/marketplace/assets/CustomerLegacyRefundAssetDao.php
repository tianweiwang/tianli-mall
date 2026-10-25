<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Fixed v0 refund/material leaves, using the original primary connection and real saved family. */
final class CustomerLegacyRefundAssetDao
{
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    public const PURPOSES=['legacy_refund_evidence','refund_shipping_proof'];

    public function __construct(private CustomerAssetDao $customers){}

    public function primary():array
    {
        $connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $connection->query('SELECT 1 AS legacy_refund_primary',[],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();
        $this->native($connection,$pdo,0);return [$connection,$pdo];
    }

    public function user(ConnectionInterface $connection,PDO $pdo,int $uid,int $depth):array
    {
        self::integer($uid,1,2147483647);
        if($depth===1)return $this->customers->lockUser($connection,$pdo,$uid);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2',[$uid],0);
        if(count($rows)!==1||self::integer($rows[0]['uid']??null,1,2147483647)!==$uid
            ||self::integer($rows[0]['status']??null,0,1)!==1||self::integer($rows[0]['is_del']??null,0,1)!==0)self::fail();
        return $rows[0];
    }

    /** Original equalSplit children point to their canonical root; root pid may change from 0 to -1. */
    public function family(ConnectionInterface $connection,PDO $pdo,int $orderId,int $uid,int $depth):array
    {
        self::integer($orderId,1,2147483647);self::integer($uid,1,2147483647);$id=$orderId;$seen=[];$rows=[];
        do{
            if(isset($seen[$id]))self::fail();$seen[$id]=true;
            $found=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order')
                .' WHERE id=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[$id],$depth);
            if(count($found)!==1)self::fail();$row=$found[0];
            if(self::integer($row['id']??null,1,2147483647)!==$id||self::integer($row['uid']??null,1,2147483647)!==$uid)self::fail();
            MarketplaceTradePolicy::assertLegacyOrder($row);$rows[$id]=$row;
            $pid=$row['pid']??null;
            if($pid===-1||$pid==='-1')$parent=-1;else $parent=self::integer($pid,0,2147483647);
            if($parent>0)$id=$parent;
        }while($parent>0);
        return ['order'=>$rows[$orderId],'root'=>$row,'rows'=>$rows];
    }

    public function refund(ConnectionInterface $connection,PDO $pdo,int $refundId,?int $uid,int $depth,bool $live=true):array
    {
        self::integer($refundId,1,2147483647);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_refund')
            .' WHERE id=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[$refundId],$depth);
        if(count($rows)!==1)self::fail();$refund=$rows[0];$owner=self::integer($refund['uid']??null,1,2147483647);
        if(self::integer($refund['id']??null,1,2147483647)!==$refundId||($uid!==null&&$owner!==$uid))self::fail();
        $family=$this->family($connection,$pdo,self::integer($refund['store_order_id']??null,1,2147483647),$owner,$depth);
        if($live)foreach(['is_cancel','is_del','is_pink_cancel','is_system_del']as$key)
            if(self::integer($refund[$key]??null,0,1)!==0)self::fail();
        self::integer($refund['refund_type']??null,0,6);self::images($refund['refund_img']??null);
        return ['refund'=>$refund,'family'=>$family];
    }

    /** Same original pending-refund and remaining-cart qualification, before touching multipart bytes. */
    public function application(ConnectionInterface $connection,PDO $pdo,int $orderId,int $uid,int $depth):array
    {
        $family=$this->family($connection,$pdo,$orderId,$uid,$depth);$order=$family['order'];
        if(in_array($order['pid'],[-1,'-1'],true))self::fail();
        foreach(['is_del','is_system_del']as$key)if(self::integer($order[$key]??null,0,1)!==0)self::fail();
        $pending=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_refund')
            .' WHERE store_order_id=? AND refund_type IN (1,2,4,5) AND is_cancel=0 AND is_del=0 AND is_pink_cancel=0'
            .($depth===1?' FOR UPDATE':''),[$orderId],$depth);
        if($pending!==[])self::fail();
        $cart=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_cart_info')
            .' WHERE oid=?'.($depth===1?' FOR UPDATE':''),[$orderId],$depth);$remaining=false;
        foreach($cart as$row){
            if(self::integer($row['uid']??null,1,2147483647)!==$uid)self::fail();
            $count=self::integer($row['cart_num']??null,1,2147483647);$used=self::integer($row['refund_num']??null,0,2147483647);
            if($used>$count)self::fail();if($used<$count)$remaining=true;
        }
        if(!$remaining)self::fail();return ['family'=>$family,'cart'=>$cart];
    }

    public function asset(ConnectionInterface $connection,PDO $pdo,string $id,int $uid,int $depth):array
    {
        self::assetId($id);$rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[$id],$depth);
        if(count($rows)!==1)self::fail();$this->shape($pdo,$rows[0],$uid);return $rows[0];
    }

    public function insert(ConnectionInterface $connection,PDO $pdo,int $uid,string $purpose,int $rootId,?int $refundId,array $physical,string $name):array
    {
        $this->native($connection,$pdo,1);$this->customers->assertStructure($connection,$pdo);self::purpose($purpose);
        if(array_keys($physical)!==['uid','object_key','media_type','byte_size','width','height','content_sha256']||$physical['uid']!==$uid)self::fail();
        self::text($name,1,255);$data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>$purpose,'state'=>'STAGED','storage_type'=>1,'object_key'=>$physical['object_key'],
            'thumbnail_key'=>'','name'=>basename($physical['object_key']),'real_name'=>$name,'media_type'=>$physical['media_type'],
            'byte_size'=>$physical['byte_size'],'width'=>$physical['width'],'height'=>$physical['height'],'content_sha256'=>$physical['content_sha256'],
            'created_at'=>time(),'root_order_id'=>$rootId,'refund_id'=>$refundId,'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','root_order_id','created_by_uid']as$key)$data[$key]=$this->number($pdo,$data[$key]);
        if($refundId!==null)$data['refund_id']=$this->number($pdo,$refundId);
        $columns=array_keys($data);$this->execute($connection,$pdo,'INSERT INTO '.$this->table($connection,'marketplace_customer_asset')
            .' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
        $id=self::assetId($pdo->lastInsertId());$expected=['id'=>$this->number($pdo,$id)]+$data;
        if($this->asset($connection,$pdo,$id,$uid,1)!==$expected)self::fail();return $expected;
    }

    public function selections(ConnectionInterface $connection,PDO $pdo,int $uid,int $rootId,?int $refundId,string $purpose,array $refs,bool $confirmation=false):array
    {
        $this->native($connection,$pdo,1);self::references($refs);self::purpose($purpose);$selected=[];
        foreach($refs as$ref){
            if(!self::isPrivate($ref))continue;[$kind,$id]=self::reference($ref);
            $asset=$this->asset($connection,$pdo,$id,$uid,1);
            if($kind!==$purpose||$asset['purpose']!==$purpose||self::integer($asset['root_order_id'],1,2147483647)!==$rootId)self::fail();
            if($asset['state']==='STAGED'){
                if($confirmation||($purpose==='legacy_refund_evidence'?$asset['refund_id']!==null
                    :self::integer($asset['refund_id'],1,2147483647)!==$refundId))self::fail();
            }elseif($refundId===null||self::integer($asset['refund_id'],1,2147483647)!==$refundId)self::fail();
            $selected[$id]=$asset;
        }
        return $selected;
    }

    public function bind(ConnectionInterface $connection,PDO $pdo,int $uid,array $refund,array $selected):array
    {
        $id=self::integer($refund['id']??null,1,2147483647);$saved=$this->refund($connection,$pdo,$id,$uid,1);
        if($saved['refund']!==$refund)self::fail();$rootId=self::integer($saved['family']['root']['id'],1,2147483647);
        $refs=self::images($refund['refund_img']);$bound=[];
        foreach($selected as$key=>$row){
            $assetId=self::assetId((string)$key);$ref=self::ref($row['purpose'],$assetId);
            if(!in_array($ref,$refs,true)||$this->asset($connection,$pdo,$assetId,$uid,1)!==$row
                ||self::integer($row['root_order_id'],1,2147483647)!==$rootId)self::fail();
            $expected=$row;
            if($row['state']==='STAGED'){
                if($row['purpose']==='legacy_refund_evidence'?$row['refund_id']!==null:self::integer($row['refund_id'],1,2147483647)!==$id)self::fail();
                $expected['state']='BOUND';$expected['refund_id']=$this->number($pdo,$id);$expected['bound_at']=$this->number($pdo,time());
                $this->execute($connection,$pdo,'UPDATE '.$this->table($connection,'marketplace_customer_asset')
                    .' SET state=?,refund_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND root_order_id=? AND state=?'
                    .' AND refund_id <=> ? AND bound_at IS NULL AND retired_at IS NULL AND expires_at IS NULL',
                    ['BOUND',$id,$expected['bound_at'],$assetId,$uid,$row['purpose'],$rootId,'STAGED',$row['refund_id']]);
            }elseif(self::integer($row['refund_id'],1,2147483647)!==$id)self::fail();
            if($this->asset($connection,$pdo,$assetId,$uid,1)!==$expected)self::fail();$bound[$assetId]=$expected;
        }
        return $bound;
    }

    public function note(ConnectionInterface $connection,PDO $pdo,array $refund,int $depth):string
    {
        $id=self::integer($refund['id']??null,1,2147483647);$prefix=self::notePrefix($id);$notes=[];
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_status')
            .' WHERE oid=? AND change_type=?'.($depth===1?' FOR UPDATE':''),[$refund['store_order_id'],'refund_shipping_note'],$depth);
        foreach($rows as$row){
            $message=$row['change_message']??null;self::text($message,1,256);
            if(!str_starts_with($message,$prefix))continue;$note=substr($message,strlen($prefix));self::text($note,1,100);$notes[]=$note;
        }
        if(count($notes)>1)self::fail();return $notes[0]??'';
    }

    public static function notePrefix(int $id):string {self::integer($id,1,2147483647);return '退款单#'.$id.' 寄回备注：';}
    public static function noteMessage(int $id,string $note):string {self::text($note,1,100);$message=self::notePrefix($id).$note;self::text($message,1,256);return $message;}
    public static function shippingNote(mixed $note):string {self::text($note,0,100);return $note;}
    public static function isPrivate(string $ref):bool {return str_starts_with($ref,'mp-refund:');}
    public static function ref(string $purpose,string $id):string {self::purpose($purpose);return 'mp-refund:v1:'.$purpose.':'.self::assetId($id);}
    public static function reference(string $ref):array
    {
        if(preg_match('/\Amp-refund:v1:(legacy_refund_evidence|refund_shipping_proof):([1-9][0-9]{0,19})\z/D',$ref,$m)!==1)self::fail();
        return [$m[1],self::assetId($m[2])];
    }
    public static function references(array $refs):array
    {
        if(!array_is_list($refs))self::fail();
        foreach($refs as$ref){self::text($ref,1,2048);if(self::isPrivate($ref))self::reference($ref);}
        return $refs;
    }
    public static function images(mixed $encoded):array
    {
        if($encoded===null||$encoded==='')return [];
        if(!is_string($encoded))self::fail();try{$value=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        if(!is_array($value))self::fail();return self::references($value);
    }
    public static function csv(mixed $value):array {if(!is_string($value))self::fail();return self::references($value===''?[]:explode(',',$value));}
    public static function merge(array $before,array $after):array
    {self::references($before);self::references($after);$out=$before;foreach($after as$ref)if(!in_array($ref,$out,true))$out[]=$ref;return $out;}
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
    public static function purpose(string $purpose):void {if(!in_array($purpose,self::PURPOSES,true))self::fail();}
    public static function objectContext(string $key,int $uid):array
    {
        if(preg_match('/\Alegacy-refund\/v1\/(legacy_refund_evidence|refund_shipping_proof)\/([1-9][0-9]*)\/([1-9][0-9]{0,9})\/(0|[1-9][0-9]{0,9})\/[a-f0-9]{64}\/content\.(jpg|jpeg|png|gif|webp)\z/D',$key,$m)!==1
            ||$m[2]!== (string)$uid)self::fail();
        return ['purpose'=>$m[1],'root_id'=>self::integer($m[3],1,2147483647),'refund_id'=>self::integer($m[4],0,2147483647)];
    }
    public function native(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        if(!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||!in_array($depth,[0,1],true)||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    public function assertStructure(ConnectionInterface $connection,PDO $pdo):void {$this->customers->assertStructure($connection,$pdo);}
    public function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $values,int $depth):array
    {
        $this->native($connection,$pdo,$depth);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($s->closeCursor()!==true)self::fail();}$this->native($connection,$pdo,$depth);return $rows;
    }
    private function shape(PDO $pdo,array $row,int $uid):void
    {
        if(array_keys($row)!==self::FIELDS||!in_array($row['state'],['STAGED','BOUND'],true))self::fail();self::purpose($row['purpose']);
        if(self::integer($row['uid'],1,2147483647)!==$uid||self::integer($row['created_by_uid'],1,2147483647)!==$uid
            ||self::integer($row['storage_type'],1,1)!==1||$row['thumbnail_key']!=='')self::fail();
        self::assetId((string)$row['id']);$context=self::objectContext($row['object_key'],$uid);
        if($context['purpose']!==$row['purpose']||$context['root_id']!==self::integer($row['root_order_id'],1,2147483647))self::fail();
        foreach(['order_item_id','review_id','confirmation_ref','form_product_id','form_definition_sha256','form_field_index','extract_id',
            'agent_apply_id','source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id','expires_at','retired_at']as$key)
            if($row[$key]!==null)self::fail();
        foreach(['byte_size'=>52428800,'width'=>4294967295,'height'=>4294967295,'created_at'=>4294967295]as$key=>$max)self::integer($row[$key],1,$max);
        foreach(['id','uid','storage_type','byte_size','width','height','created_at','root_order_id','created_by_uid']as$key)
            if($this->number($pdo,$row[$key])!==$row[$key])self::fail();
        if(!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32
            ||!in_array($row['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||$row['name']!==basename($row['object_key']))self::fail();self::text($row['real_name'],1,255);
        if($row['purpose']==='refund_shipping_proof'){
            if(self::integer($row['refund_id'],1,2147483647)!==$context['refund_id']||$this->number($pdo,$row['refund_id'])!==$row['refund_id'])self::fail();
        }elseif($context['refund_id']!==0)self::fail();
        if($row['state']==='STAGED'){
            if($row['bound_at']!==null||($row['purpose']==='legacy_refund_evidence'&&$row['refund_id']!==null))self::fail();
        }else{
            self::integer($row['refund_id'],1,2147483647);self::integer($row['bound_at'],1,4294967295);
            foreach(['bound_at','refund_id']as$key)if($this->number($pdo,$row[$key])!==$row[$key])self::fail();
        }
    }
    private function number(PDO $pdo,mixed $number):int|string
    {
        $text=self::assetId((string)$number);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();return in_array($strings,[true,1],true)
            ||strlen($text)>strlen((string)PHP_INT_MAX)||(strlen($text)===strlen((string)PHP_INT_MAX)&&strcmp($text,(string)PHP_INT_MAX)>0)?$text:(int)$text;
    }
    private function execute(ConnectionInterface $connection,PDO $pdo,string $sql,array $values):void
    {
        $this->native($connection,$pdo,1);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true||$s->rowCount()!==1)self::fail();}finally{if($s->closeCursor()!==true)self::fail();}
        $this->native($connection,$pdo,1);
    }
    public function table(ConnectionInterface $connection,string $name):string
    {
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($name,['marketplace_customer_asset','user','store_order','store_order_refund','store_order_cart_info','store_order_status'],true)
            ||strlen($prefix.$name)>64)self::fail();return '`'.$prefix.$name.'`';
    }
    private static function text(mixed $value,int $min,int $max):void
    {if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')<$min||mb_strlen($value,'UTF-8')>$max)self::fail();}
    private static function fail():never {throw new AuthUnavailableException();}
}
