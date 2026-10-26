<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use app\services\marketplace\assets\CustomerLegacyReviewAssetServices;
use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Original legacy item and actual review author, independent of the V1 customer target. */
final class CustomerLegacyReviewAssetDao
{
    private const FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    public function __construct(private CustomerAssetDao $customers){}

    /** Reuses only primary/user/schema qualification, not an avatar permission frame. */
    public function connection(int $uid):array {return $this->customers->avatarConnection($uid);}

    public function target(ConnectionInterface $connection,PDO $pdo,int $uid,string $unique):array
    {
        self::unique($unique);$this->native($connection,$pdo,1);$user=$this->customers->lockUser($connection,$pdo,$uid);
        $items=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_cart_info')
            .' WHERE BINARY `unique`=BINARY ? LIMIT 2 FOR UPDATE',[$unique],1);
        if(count($items)!==1)self::fail();$item=$items[0];$orderId=self::integer($item['oid']??null);
        self::integer($item['id']??null);$id=$orderId;$family=[];
        do{
            if(isset($family[$id]))self::fail();
            $found=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? LIMIT 2 FOR UPDATE',[$id],1);
            if(count($found)!==1)self::fail();$row=$found[0];MarketplaceTradePolicy::assertLegacyOrder($row);$family[$id]=$row;
            if(self::integer($row['id']??null)!==$id)self::fail();
            $parent=in_array($row['pid']??null,[-1,'-1'],true)?-1:self::integer($row['pid']??null,0);
            if($parent>0)$id=$parent;
        }while($parent>0);
        $order=$family[$orderId];$root=$row;$owner=self::integer($order['uid']??null);
        if($uid!==$owner&&$uid!==self::integer($order['gift_uid']??null,0))self::fail();
        if(self::integer($item['uid']??null)!==$owner||in_array($order['pid'],[-1,'-1'],true)
            ||self::integer($order['paid']??null,0)!==1||self::integer($order['status']??null,0)!==2)self::fail();
        foreach(['is_del','is_system_del','refund_status']as$key)if(self::integer($order[$key]??null,0)!==0)self::fail();
        foreach($family as$parentRow)if(self::integer($parentRow['uid']??null)!==$owner)self::fail();
        if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply')
            .' WHERE oid=? AND BINARY `unique`=BINARY ? LIMIT 2 FOR UPDATE',[$orderId,$unique],1)!==[])self::fail();
        if(!is_string($item['cart_info']??null))self::fail();
        try{$cart=json_decode($item['cart_info'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        if(!is_array($cart))self::fail();
        $activity=!empty($cart['combination_id'])||!empty($cart['seckill_id'])||!empty($cart['bargain_id']);
        $productId=self::integer($activity?($cart['product_id']??null):($item['product_id']??null));
        $suk=$cart['productInfo']['attrInfo']['suk']??null;if(!is_string($suk))self::fail();
        return ['user'=>$user,'item'=>$item,'order'=>$order,'root'=>$root,'family'=>$family,'product_id'=>$productId,'suk'=>$suk];
    }

    public function assertTarget(array $context):void
    {
        CustomerLegacyReviewAssetServices::assertContext($context,$context['mode']);
        $connection=$context['connection'];$pdo=$context['pdo'];$target=$context['target'];
        if($this->customers->lockUser($connection,$pdo,$context['actual_uid'])!==$target['user'])self::fail();
        foreach($target['family']as$id=>$row)if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order')
            .' WHERE id=? LIMIT 2 FOR UPDATE',[$id],1)!==[$row])self::fail();
        if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_cart_info')
            .' WHERE id=? LIMIT 2 FOR UPDATE',[$target['item']['id']],1)!==[$target['item']])self::fail();
    }

    public function insert(array $context,array $physical,array $qualified):array
    {
        CustomerLegacyReviewAssetServices::assertContext($context,'upload');$this->assertTarget($context);
        $connection=$context['connection'];$pdo=$context['pdo'];$uid=$context['actual_uid'];$target=$context['target'];
        $this->customers->assertStructure($connection,$pdo);
        if(array_keys($physical)!==['name','real_name','att_dir','satt_dir','att_size','att_type','image_type']
            ||array_keys($qualified)!==['extension','mime','size','width','height','sha256']
            ||$physical['att_dir']!==$physical['satt_dir']||$physical['att_type']!==$qualified['mime']
            ||(string)$physical['att_size']!==(string)$qualified['size']
            ||!is_string($qualified['sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$qualified['sha256'])!==1)self::fail();
        $data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>'review_public_image','state'=>'STAGED',
            'storage_type'=>self::integer($physical['image_type'],1,7),'object_key'=>$physical['att_dir'],
            'thumbnail_key'=>$physical['satt_dir'],'name'=>$physical['name'],'real_name'=>$physical['real_name'],
            'media_type'=>$qualified['mime'],'byte_size'=>self::integer($qualified['size'],1,52428800),
            'width'=>self::integer($qualified['width'],1,4294967295),'height'=>self::integer($qualified['height'],1,4294967295),
            'content_sha256'=>hex2bin($qualified['sha256']),'created_at'=>time(),
            'root_order_id'=>self::integer($target['root']['id']),'order_item_id'=>self::integer($target['item']['id']),'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','root_order_id','order_item_id','created_by_uid']as$key)
            $data[$key]=$this->number($pdo,$data[$key]);
        $this->execute($connection,$pdo,'INSERT INTO '.$this->table($connection,'marketplace_customer_asset')
            .' (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
        $expected=['id'=>$this->number($pdo,self::assetId($pdo->lastInsertId()))]+$data;
        $this->asset($context,$expected,'STAGED');$this->assertAsset($context,$expected);return $expected;
    }

    public function select(array $context,array $ids):array
    {
        CustomerLegacyReviewAssetServices::assertContext($context,'save');$out=[];
        foreach(self::assetIds($ids)as$id){
            $rows=$this->rows($context['connection'],$context['pdo'],'SELECT * FROM '.$this->table($context['connection'],'marketplace_customer_asset')
                .' WHERE id=? LIMIT 2 FOR UPDATE',[$id],1);
            if(count($rows)!==1)self::fail();$this->asset($context,$rows[0],'STAGED');$out[]=$rows[0];
        }
        $this->assertTarget($context);return $out;
    }

    public function prepared(array $context,array $group):void
    {
        $fields=['comment','pics','product_score','service_score','nickname','avatar','uid','oid','unique','product_id','add_time','reply_type','suk','status'];
        $actual=array_keys($group);sort($actual);sort($fields);if($actual!==$fields)self::fail();$target=$context['target'];
        foreach(['uid'=>$context['actual_uid'],'oid'=>self::integer($target['order']['id']),'product_id'=>$target['product_id']]as$key=>$value)
            if(self::integer($group[$key])!==$value)self::fail();
        if($group['unique']!==$context['unique']||$group['reply_type']!=='product'||$group['suk']!==$target['suk'])self::fail();
        foreach(['product_score','service_score']as$key)self::integer($group[$key],1,5);
        self::integer($group['add_time']);self::integer($group['status'],0,1);
        foreach(['comment'=>512,'nickname'=>64,'avatar'=>255,'suk'=>255]as$key=>$max)self::text($group[$key],$max);
        if(!is_string($group['pics']))self::fail();
    }

    public function bind(array $context,array $assets,array $group,mixed $model):array
    {
        CustomerLegacyReviewAssetServices::assertContext($context,'save');$this->prepared($context,$group);
        if(!$model instanceof \think\Model)self::fail();$reviewId=self::integer($model->getData('id'));
        $connection=$context['connection'];$pdo=$context['pdo'];
        $reviews=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE id=? LIMIT 2 FOR UPDATE',[$reviewId],1);
        if(count($reviews)!==1)self::fail();$review=$reviews[0];
        foreach($group as$key=>$value){
            if(in_array($key,['uid','oid','product_id','add_time','product_score','service_score','status'],true)){
                if((string)$review[$key]!==(string)$value)self::fail();
            }elseif($review[$key]!==$value)self::fail();
        }
        if(($review['mer_id']??null)!==null)self::fail();$bound=[];$now=time();
        foreach($assets as$asset){
            $this->assertAsset($context,$asset);$this->asset($context,$asset,'STAGED');
            $this->execute($connection,$pdo,'UPDATE '.$this->table($connection,'marketplace_customer_asset')
                .' SET state=?,review_id=?,bound_at=? WHERE id=? AND uid=? AND purpose=? AND state=?'
                .' AND root_order_id=? AND order_item_id=? AND review_id IS NULL AND bound_at IS NULL',
                ['BOUND',$reviewId,$now,$asset['id'],$context['actual_uid'],'review_public_image','STAGED',$asset['root_order_id'],$asset['order_item_id']]);
            $asset['state']='BOUND';$asset['review_id']=$this->number($pdo,$reviewId);$asset['bound_at']=$this->number($pdo,$now);
            $this->asset($context,$asset,'BOUND');$this->assertAsset($context,$asset);$bound[]=$asset;
        }
        $this->assertTarget($context);return ['assets'=>$bound,'review'=>$review];
    }

    public function assertAsset(array $context,array $asset):void
    {
        CustomerLegacyReviewAssetServices::assertContext($context,$context['mode']);
        if($this->rows($context['connection'],$context['pdo'],'SELECT * FROM '.$this->table($context['connection'],'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2 FOR UPDATE',[self::assetId((string)$asset['id'])],1)!==[$asset])self::fail();
    }
    public function committed(ConnectionInterface $connection,PDO $pdo,array $assets,?array $review=null):void
    {
        $this->native($connection,$pdo,0);
        foreach($assets as$asset)if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2',[$asset['id']],0)!==[$asset])self::fail();
        if($review!==null&&$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply')
            .' WHERE id=? LIMIT 2',[$review['id']],0)!==[$review])self::fail();
    }

    private function asset(array $context,array $asset,string $state):void
    {
        if(array_keys($asset)!==self::FIELDS||$asset['purpose']!=='review_public_image'||$asset['state']!==$state
            ||$asset['object_key']!==$asset['thumbnail_key'])self::fail();
        foreach(['uid'=>$context['actual_uid'],'created_by_uid'=>$context['actual_uid'],
            'root_order_id'=>self::integer($context['target']['root']['id']),'order_item_id'=>self::integer($context['target']['item']['id'])]as$key=>$value)
            if(self::integer($asset[$key])!==$value)self::fail();
        foreach(['id','uid','storage_type','byte_size','width','height','created_at','root_order_id','order_item_id','created_by_uid']as$key)
            if($this->number($context['pdo'],$asset[$key])!==$asset[$key])self::fail();
        self::integer($asset['storage_type'],1,7);self::integer($asset['byte_size'],1,52428800);
        foreach(['expires_at','retired_at','refund_id','confirmation_ref','form_product_id','form_definition_sha256','form_field_index',
            'extract_id','agent_apply_id','source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id']as$key)
            if($asset[$key]!==null)self::fail();
        if($state==='STAGED'){if($asset['review_id']!==null||$asset['bound_at']!==null)self::fail();}
        else foreach(['review_id','bound_at']as$key)if($this->number($context['pdo'],$asset[$key])!==$asset[$key])self::fail();
        foreach(['object_key'=>200,'name'=>100,'real_name'=>255]as$key=>$max){self::text($asset[$key],$max);if($asset[$key]==='')self::fail();}
        if(!is_string($asset['content_sha256'])||strlen($asset['content_sha256'])!==32
            ||!in_array($asset['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true))self::fail();
        $uid=$context['actual_uid'];$leaf='[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)';
        if((int)$asset['storage_type']===1){
            if(preg_match('/\A\/uploads\/customer\/lr\/'.$uid.'\/[a-f0-9]{32}\/('.$leaf.')\z/D',$asset['object_key'],$m)!==1||$asset['name']!==$m[1])self::fail();
        }elseif(preg_match('/\Auploads\/customer\/lr\/'.$uid.'\/[a-f0-9]{32}\/'.$leaf.'\z/D',$asset['name'])!==1)self::fail();
    }
    public static function assetIds(array $ids):array
    {
        if(!array_is_list($ids)||count($ids)<1||count($ids)>8)self::fail();$seen=[];
        foreach($ids as$id){self::assetId($id);if(isset($seen[$id]))self::fail();$seen[$id]=true;}return $ids;
    }
    public static function assetId(mixed $id):string
    {
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::fail();return $id;
    }
    public static function unique(string $unique):void {if(preg_match('/\A[a-f0-9]{32}\z/D',$unique)!==1)self::fail();}
    public static function integer(mixed $value,int $min=1,int $max=4294967295):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    public function native(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||!in_array($depth,[0,1],true)||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    public function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $args,int $depth):array
    {
        $this->native($connection,$pdo,$depth);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($args)!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($s->closeCursor()!==true)self::fail();}$this->native($connection,$pdo,$depth);return $rows;
    }
    private function execute(ConnectionInterface $connection,PDO $pdo,string $sql,array $args):void
    {
        $this->native($connection,$pdo,1);$s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($args)!==true||$s->rowCount()!==1)self::fail();}finally{if($s->closeCursor()!==true)self::fail();}
        $this->native($connection,$pdo,1);
    }
    private function number(PDO $pdo,mixed $value):int|string
    {
        $text=self::assetId((string)$value);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();
        return in_array($strings,[true,1],true)||strlen($text)>strlen((string)PHP_INT_MAX)
            ||(strlen($text)===strlen((string)PHP_INT_MAX)&&strcmp($text,(string)PHP_INT_MAX)>0)?$text:(int)$text;
    }
    private function table(ConnectionInterface $connection,string $name):string
    {
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($name,['marketplace_customer_asset','store_order','store_order_cart_info','store_product_reply'],true)
            ||strlen($prefix.$name)>64)self::fail();return '`'.$prefix.$name.'`';
    }
    private static function text(mixed $value,int $max):void
    {if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)self::fail();}
    private static function fail():never {throw new AuthUnavailableException();}
}
