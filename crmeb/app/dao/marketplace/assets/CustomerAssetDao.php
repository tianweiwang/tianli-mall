<?php
declare(strict_types=1);

namespace app\dao\marketplace\assets;

use app\services\marketplace\order\MarketplaceOrderReviewInput;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** The fixed customer-review asset domain on the caller's original primary writer. */
final class CustomerAssetDao
{
    private const UINT64 = '18446744073709551615';
    private const FIELDS = ['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
    private const REVIEW = ['id','uid','oid','unique','product_id','reply_type','product_score','service_score','comment',
        'pics','add_time','merchant_reply_content','merchant_reply_time','is_del','is_reply','nickname','avatar','suk','status','mer_id'];
    private const ATTACHMENT = ['att_id','name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','real_name','scan_token','type','mer_id'];
    private const IMAGE_MIMES = ['image/jpg','image/jpeg','image/png','image/gif','image/webp'];
    private const LEGACY_MIMES = ['image/jpg','image/jpeg','image/png','image/gif','image/webp','image/x-icon','image/vnd.microsoft.icon'];
    private const NULL_FIELDS = ['expires_at','retired_at','refund_id','confirmation_ref','form_product_id',
        'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
        'source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id'];

    public function lockUser(ConnectionInterface $connection, PDO $pdo, int $uid): array
    {
        $this->writer($connection,$pdo); self::integer($uid,1,4294967295);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2 FOR UPDATE',[$uid]);
        if(count($rows)!==1)self::fail();$row=$rows[0];
        if(self::integer($row['uid']??null,1,4294967295)!==$uid
            ||self::integer($row['status']??null,0,1)!==1||self::integer($row['is_del']??null,0,1)!==0)self::fail();
        $this->writer($connection,$pdo);return $row;
    }

    /** DML-account metadata observation only; full AssetSchema/TRIGGER qualification remains a deployment prerequisite. */
    public function assertStructure(ConnectionInterface $connection, PDO $pdo): void
    {
        $this->native($connection,$pdo);$database=$this->rows($connection,$pdo,'SELECT DATABASE() AS database_name',[]);
        if(count($database)!==1||array_keys($database[0])!==['database_name']||!is_string($database[0]['database_name'])
            ||$database[0]['database_name']===''||strlen($database[0]['database_name'])>64||str_contains($database[0]['database_name'],"\0"))self::fail();
        $arguments=[$database[0]['database_name'],$this->physical($connection,'marketplace_customer_asset')];
        $tables=$this->rows($connection,$pdo,'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES'
            .' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?',$arguments);
        if($tables!==[['TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_bin','TABLE_COMMENT'=>'']])self::fail();
        $columns=$this->rows($connection,$pdo,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,'
            .'EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS'
            .' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION',$arguments);
        $definition=self::columns();if(count($columns)!==34||array_column($columns,'COLUMN_NAME')!==self::FIELDS)self::fail();
        foreach($columns as $position=>$column){
            self::keys($column,['COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','CHARACTER_SET_NAME','COLLATION_NAME','EXTRA','GENERATION_EXPRESSION','COLUMN_COMMENT','ORDINAL_POSITION']);
            $type=$column['COLUMN_TYPE'];
            if(is_string($type)&&preg_match('/\A(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)( unsigned)?\z/D',$type,$match)===1)$type=$match[1].($match[2]??'');
            if([$type,$column['IS_NULLABLE'],$column['COLUMN_DEFAULT'],$column['CHARACTER_SET_NAME'],$column['COLLATION_NAME'],$column['EXTRA']]!==$definition[$column['COLUMN_NAME']]
                ||$column['GENERATION_EXPRESSION']!==''||$column['COLUMN_COMMENT']!==''
                ||self::integer($column['ORDINAL_POSITION'],1,34)!==$position+1)self::fail();
        }
        $rows=$this->rows($connection,$pdo,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT'
            .' FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX',$arguments);
        $wanted=['PRIMARY'=>[true,['id']],'uq_storage_object'=>[true,['storage_type','object_key']],
            'uq_legacy_att'=>[true,['legacy_att_id']],'ix_user_purpose'=>[false,['uid','purpose','state','id']],
            'ix_order_item'=>[false,['root_order_id','order_item_id','id']],'ix_form'=>[false,['confirmation_ref','form_product_id','form_field_index','id']]];
        ksort($wanted,SORT_STRING);$expected=[];
        foreach($wanted as $name=>[$unique,$fields])foreach($fields as $position=>$field)$expected[]=[$name,$unique?0:1,$position+1,$field];
        if(count($rows)!==count($expected))self::fail();
        foreach($rows as $position=>$row){
            self::keys($row,['INDEX_NAME','NON_UNIQUE','SEQ_IN_INDEX','COLUMN_NAME','SUB_PART','INDEX_TYPE','IS_VISIBLE','EXPRESSION','COLLATION','INDEX_COMMENT']);
            if([$row['INDEX_NAME'],self::integer($row['NON_UNIQUE'],0,1),self::integer($row['SEQ_IN_INDEX'],1,4),$row['COLUMN_NAME']]!==$expected[$position]
                ||$row['SUB_PART']!==null||$row['INDEX_TYPE']!=='BTREE'||$row['IS_VISIBLE']!=='YES'
                ||$row['EXPRESSION']!==null||$row['COLLATION']!=='A'||$row['INDEX_COMMENT']!=='')self::fail();
        }
        $this->native($connection,$pdo);
    }

    public function insertForReview(array $context, array $physicalRow, array $qualified): array
    {
        [$connection,$pdo,$root,$item,$uid]=$this->uploadContext($context);$this->assertStructure($connection,$pdo);
        self::keys($physicalRow,['name','real_name','att_dir','satt_dir','att_size','att_type','image_type']);
        self::keys($qualified,['extension','mime','size','width','height','sha256']);
        if(!in_array($qualified['extension'],['jpg','jpeg','png','gif','webp'],true)
            ||!in_array($qualified['mime'],self::IMAGE_MIMES,true)||$physicalRow['att_type']!==$qualified['mime']
            ||!is_string($qualified['sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$qualified['sha256'])!==1)self::fail();
        $size=self::integer($qualified['size'],1,PHP_INT_MAX);$width=self::integer($qualified['width'],1,4294967295);$height=self::integer($qualified['height'],1,4294967295);
        if(self::decimal($physicalRow['att_size'])!==(string)$size||$physicalRow['satt_dir']!==$physicalRow['att_dir'])self::fail();
        $reference=self::reference($physicalRow['att_dir']);self::text($physicalRow['name'],1,100);self::text($physicalRow['real_name'],1,255);
        if(strtolower(pathinfo($physicalRow['name'],PATHINFO_EXTENSION))!==$qualified['extension'])self::fail();
        $storage=self::integer($physicalRow['image_type'],1,7);$now=self::integer(time(),1,4294967295);
        $data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['uid'=>$uid,'purpose'=>'review_public_image','state'=>'STAGED','storage_type'=>$storage,
            'object_key'=>$reference,'thumbnail_key'=>$reference,'name'=>$physicalRow['name'],'real_name'=>$physicalRow['real_name'],
            'media_type'=>$qualified['mime'],'byte_size'=>(string)$size,'width'=>$width,'height'=>$height,'content_sha256'=>hex2bin($qualified['sha256']),
            'created_at'=>$now,'root_order_id'=>self::integer($root['id'],1,4294967295),'order_item_id'=>self::integer($item['id'],1,2147483647),'created_by_uid'=>$uid]);
        foreach(['uid','storage_type','byte_size','width','height','created_at','root_order_id','order_item_id','created_by_uid']as $field)$data[$field]=$this->nativeNumber($pdo,$data[$field]);
        // These 33 values and their PDO-native types are fixed before INSERT, never inferred from the resulting row.
        $this->uploadContext($context);$columns=array_keys($data);
        $statement=$pdo->prepare('INSERT INTO '.$this->table($connection,'marketplace_customer_asset').' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute(array_values($data))!==true||$statement->rowCount()!==1)self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $id=self::decimal($pdo->lastInsertId());$expected=['id'=>$this->nativeNumber($pdo,$id)]+$data;
        $this->asset($pdo,$root,$item,$uid,$expected,'STAGED');$this->uploadContext($context);
        $rows=$this->byId($connection,$pdo,$id);if(count($rows)!==1||$rows[0]!==$expected)self::fail();
        $this->assertUploaded($context,$expected);return $expected;
    }

    public function assertUploaded(array $context, array $row): void
    {
        [$connection,$pdo,$root,$item,$uid]=$this->uploadContext($context);$this->asset($pdo,$root,$item,$uid,$row,'STAGED');
        $rows=$this->byId($connection,$pdo,self::decimal($row['id']));if(count($rows)!==1||$rows[0]!==$row)self::fail();
        $this->uploadContext($context);
    }

    /** Positive primary/autocommit observation after the owning service's transaction has returned. */
    public function assertCommitted(ConnectionInterface $connection, PDO $pdo, array $row): void
    {
        $this->outside($connection,$pdo);
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='review_public_image'||$row['state']!=='STAGED')self::fail();
        $id=self::decimal($row['id']);
        $autocommit=$this->rows($connection,$pdo,'SELECT @@session.autocommit AS autocommit',[]);
        if(count($autocommit)!==1||array_keys($autocommit[0])!==['autocommit']||!in_array($autocommit[0]['autocommit'],[1,'1'],true))self::fail();
        $this->outside($connection,$pdo);
        $actual=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset').' WHERE id=? LIMIT 2',[$id]);
        if($actual!==[$row])self::fail();$this->outside($connection,$pdo);
    }

    public function findForReview(ConnectionInterface $connection, PDO $pdo, array $root, array $item, int $uid, string $reference): array
    {
        $this->target($connection,$pdo,$root,$item,$uid);$this->assertStructure($connection,$pdo);self::reference($reference);
        $rows=$this->byReference($connection,$pdo,$reference);if(count($rows)>1)self::fail();
        foreach($rows as $row){$this->asset($pdo,$root,$item,$uid,$row,'STAGED');if($row['object_key']!==$reference)self::fail();}
        $this->target($connection,$pdo,$root,$item,$uid);return $rows;
    }

    /** Expected BOUND rows are constructed for every unique actual id before any UPDATE. */
    public function bindActualReview(ConnectionInterface $connection, PDO $pdo, array $root, array $item, int $uid, array $review, array $wrappers): array
    {
        $this->target($connection,$pdo,$root,$item,$uid);$this->assertStructure($connection,$pdo);
        $references=$this->review($connection,$pdo,$root,$item,$uid,$review);self::list($wrappers);
        if(count($wrappers)!==count($references))self::fail();$before=[];
        foreach($wrappers as $position=>$wrapper){
            self::keys($wrapper,['domain','rows']);self::list($wrapper['rows']);$reference=$references[$position];
            if($wrapper['domain']==='legacy_public'){
                if($wrapper['rows']===[])self::fail();foreach($wrapper['rows']as $row)$this->legacy($row,$reference);
                if($this->byReference($connection,$pdo,$reference)!==[])self::fail();
            }elseif($wrapper['domain']==='customer_review'){
                if(count($wrapper['rows'])!==1)self::fail();$row=$wrapper['rows'][0];$this->asset($pdo,$root,$item,$uid,$row,'STAGED');
                if($row['object_key']!==$reference||$this->byReference($connection,$pdo,$reference)!==[$row])self::fail();
                $key=self::decimal($row['id']);if(isset($before[$key])&&$before[$key]!==$row)self::fail();$before[$key]=$row;
            }else self::fail();
        }
        $expected=[];$boundAt=$this->nativeNumber($pdo,self::integer(time(),1,4294967295));$reviewId=$this->nativeNumber($pdo,self::integer($review['id'],1,2147483647));
        foreach($before as $key=>$row){$expected[$key]=$row;$expected[$key]['state']='BOUND';$expected[$key]['review_id']=$reviewId;$expected[$key]['bound_at']=$boundAt;
            $this->asset($pdo,$root,$item,$uid,$expected[$key],'BOUND');}
        foreach($before as $key=>$row){
            $this->target($connection,$pdo,$root,$item,$uid);if($this->byId($connection,$pdo,self::decimal($row['id']))!==[$row])self::fail();
            $statement=$pdo->prepare('UPDATE '.$this->table($connection,'marketplace_customer_asset').' SET state=?,review_id=?,bound_at=?'
                .' WHERE id=? AND uid=? AND purpose=? AND state=? AND root_order_id=? AND order_item_id=?'
                .' AND review_id IS NULL AND bound_at IS NULL AND retired_at IS NULL AND expires_at IS NULL');
            if(!$statement instanceof PDOStatement)self::fail();
            try{if($statement->execute(['BOUND',$reviewId,$boundAt,self::decimal($row['id']),$uid,'review_public_image','STAGED',$root['id'],$item['id']])!==true||$statement->rowCount()!==1)self::fail();}
            finally{if($statement->closeCursor()!==true)self::fail();}
            $this->writer($connection,$pdo);if($this->byId($connection,$pdo,self::decimal($row['id']))!==[$expected[$key]])self::fail();
        }
        $this->assertBound($connection,$pdo,$root,$item,$uid,$review,$expected);return $expected;
    }

    public function assertBound(ConnectionInterface $connection, PDO $pdo, array $root, array $item, int $uid, array $review, array $expected): void
    {
        $this->target($connection,$pdo,$root,$item,$uid);$references=$this->review($connection,$pdo,$root,$item,$uid,$review);$observed=[];
        if(count($expected)>8)self::fail();
        foreach($expected as $key=>$row){
            $this->asset($pdo,$root,$item,$uid,$row,'BOUND');$id=self::decimal($row['id']);
            if(self::decimal($key)!==$id||self::integer($row['review_id'],1,2147483647)!==self::integer($review['id'],1,2147483647)
                ||$this->byId($connection,$pdo,$id)!==[$row])self::fail();
        }
        foreach($references as $reference){
            $rows=$this->byReference($connection,$pdo,$reference);if(count($rows)>1)self::fail();
            foreach($rows as $row){$this->asset($pdo,$root,$item,$uid,$row,'BOUND');$id=self::decimal($row['id']);
                if(!isset($expected[$id])||$expected[$id]!==$row||self::integer($row['review_id'],1,2147483647)!==self::integer($review['id'],1,2147483647))self::fail();$observed[$id]=true;}
        }
        if(count($observed)!==count($expected))self::fail();
        $this->review($connection,$pdo,$root,$item,$uid,$review);$this->target($connection,$pdo,$root,$item,$uid);
    }

    private function uploadContext(array $context): array
    {
        self::keys($context,['request','actual_uid','root','item','user','connection','pdo','session_sha256']);
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
        if(!$context['connection'] instanceof ConnectionInterface||!$context['pdo'] instanceof PDO||!is_array($context['root'])
            ||!is_array($context['item'])||!is_array($context['user']))self::fail();
        $uid=self::integer($context['actual_uid'],1,4294967295);$connection=$context['connection'];$pdo=$context['pdo'];
        $user=$this->target($connection,$pdo,$context['root'],$context['item'],$uid);if($user!==$context['user'])self::fail();
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
        return [$connection,$pdo,$context['root'],$context['item'],$uid];
    }

    /** Compare complete native parents, never merely supplied ids or current catalog ownership. */
    private function target(ConnectionInterface $connection, PDO $pdo, array $root, array $item, int $uid): array
    {
        $this->writer($connection,$pdo);self::integer($uid,1,4294967295);
        $rootId=self::integer($root['id']??null,1,4294967295);$itemId=self::integer($item['id']??null,1,2147483647);
        if(self::integer($root['uid']??null,1,4294967295)!==$uid||self::integer($root['pid']??null,0,2147483647)!==0
            ||self::integer($root['marketplace_trade_version']??null,1,1)!==1||self::integer($root['mer_id']??null,1,4294967295)<1
            ||self::integer($item['oid']??null,1,4294967295)!==$rootId||self::integer($item['uid']??null,1,4294967295)!==$uid
            ||self::integer($item['mer_id']??null,1,4294967295)!==self::integer($root['mer_id'],1,4294967295)
            ||self::integer($item['product_id']??null,1,8388607)<1||!is_string($item['unique']??null)
            ||preg_match('/\A[a-f0-9]{32}\z/D',$item['unique'])!==1)self::fail();
        if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? LIMIT 2 FOR UPDATE',[$rootId])!==[$root]
            ||$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order_cart_info').' WHERE id=? AND oid=? LIMIT 2 FOR UPDATE',[$itemId,$rootId])!==[$item])self::fail();
        $user=$this->lockUser($connection,$pdo,$uid);$this->writer($connection,$pdo);return $user;
    }

    private function review(ConnectionInterface $connection, PDO $pdo, array $root, array $item, int $uid, array $review): array
    {
        self::keys($review,self::REVIEW);$id=self::integer($review['id'],1,2147483647);
        foreach(['uid'=>$uid,'oid'=>self::integer($root['id'],1,4294967295),'product_id'=>self::integer($item['product_id'],1,8388607),
            'mer_id'=>self::integer($root['mer_id'],1,4294967295)]as $field=>$value)if(self::integer($review[$field],1,4294967295)!==$value)self::fail();
        if($review['unique']!==$item['unique']||$review['reply_type']!=='product')self::fail();
        foreach(['product_score','service_score']as $field)self::integer($review[$field],1,5);
        foreach(['is_del'=>1,'is_reply'=>1,'status'=>2]as $field=>$max)self::integer($review[$field],0,$max);
        foreach(['add_time','merchant_reply_time']as $field)self::integer($review[$field],0,2147483647);
        foreach(['comment'=>512,'merchant_reply_content'=>300,'nickname'=>64,'avatar'=>255,'suk'=>255]as $field=>$max)self::text($review[$field],0,$max);
        if(!is_string($review['pics'])||strlen($review['pics'])>65535)self::fail();
        try{$pictures=json_decode($review['pics'],false,16,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        $references=MarketplaceOrderReviewInput::pictures($pictures);
        if($this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE id=? LIMIT 2 FOR UPDATE',[$id])!==[$review])self::fail();
        return $references;
    }

    private function asset(PDO $pdo, array $root, array $item, int $uid, array $row, string $state): void
    {
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='review_public_image'||$row['state']!==$state||!in_array($state,['STAGED','BOUND'],true))self::fail();
        foreach(['id'=>self::UINT64,'uid'=>'4294967295','storage_type'=>'7','byte_size'=>self::UINT64,'width'=>'4294967295','height'=>'4294967295',
            'created_at'=>'4294967295','root_order_id'=>'4294967295','order_item_id'=>'2147483647','created_by_uid'=>'4294967295']as $field=>$max){
            $decimal=self::decimal($row[$field],$max);if($row[$field]!==$this->nativeNumber($pdo,$decimal))self::fail();}
        if(self::integer($row['uid'],1,4294967295)!==$uid||self::integer($row['created_by_uid'],1,4294967295)!==$uid
            ||self::integer($row['root_order_id'],1,4294967295)!==self::integer($root['id'],1,4294967295)
            ||self::integer($row['order_item_id'],1,2147483647)!==self::integer($item['id'],1,2147483647))self::fail();
        self::reference($row['object_key']);if($row['thumbnail_key']!==$row['object_key'])self::fail();
        self::text($row['name'],1,100);self::text($row['real_name'],1,255);
        if(!in_array($row['media_type'],self::IMAGE_MIMES,true)||!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32)self::fail();
        foreach(self::NULL_FIELDS as $field)if($row[$field]!==null)self::fail();
        if($state==='STAGED'){if($row['review_id']!==null||$row['bound_at']!==null)self::fail();}
        else foreach(['review_id'=>'2147483647','bound_at'=>'4294967295']as $field=>$max){$decimal=self::decimal($row[$field],$max);if($row[$field]!==$this->nativeNumber($pdo,$decimal))self::fail();}
    }

    private function legacy(array $row, string $reference): void
    {
        self::keys($row,self::ATTACHMENT);self::integer($row['att_id'],1,2147483647);
        if($row['att_dir']!==$reference||self::integer($row['module_type'],3,3)!==3||self::integer($row['type'],0,0)!==0
            ||!in_array($row['att_type'],self::LEGACY_MIMES,true))self::fail();self::integer($row['image_type'],1,7);
    }

    private function byReference(ConnectionInterface $connection, PDO $pdo, string $reference): array
    {
        $this->writer($connection,$pdo);return $this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset')
            .' WHERE BINARY object_key=BINARY ? ORDER BY id LIMIT 2 FOR UPDATE',[$reference]);
    }
    private function byId(ConnectionInterface $connection, PDO $pdo, string $id): array
    {
        $this->writer($connection,$pdo);self::decimal($id);return $this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_customer_asset').' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
    }
    private function rows(ConnectionInterface $connection, PDO $pdo, string $sql, array $arguments): array
    {
        $this->native($connection,$pdo);$statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute($arguments)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $this->native($connection,$pdo);return $rows;
    }
    private function native(ConnectionInterface $connection, PDO $pdo): void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,false,0,1],true))self::fail();
        $depth=$connection->transactionDepth();if(!in_array($depth,[0,1],true)||($depth===1&&!$pdo->inTransaction()))self::fail();
    }
    private function writer(ConnectionInterface $connection, PDO $pdo): void
    {
        $this->native($connection,$pdo);if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::fail();
    }
    private function outside(ConnectionInterface $connection, PDO $pdo): void
    {
        $this->native($connection,$pdo);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::fail();
    }
    private function table(ConnectionInterface $connection, string $suffix): string {return '`'.$this->physical($connection,$suffix).'`';}
    private function physical(ConnectionInterface $connection, string $suffix): string
    {
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($suffix,['marketplace_customer_asset','store_order','store_order_cart_info','store_product_reply','user'],true)
            ||strlen($prefix.$suffix)>64)self::fail();return $prefix.$suffix;
    }
    private function nativeNumber(PDO $pdo, mixed $value): int|string
    {
        $text=self::decimal($value);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();
        return in_array($strings,[true,1],true)||self::greater($text,(string)PHP_INT_MAX)?$text:(int)$text;
    }
    private static function decimal(mixed $value, string $maximum=self::UINT64): string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]*\z/D',$value)!==1||self::greater($value,$maximum))self::fail();return $value;
    }
    private static function greater(string $value, string $maximum): bool {return strlen($value)>strlen($maximum)||(strlen($value)===strlen($maximum)&&strcmp($value,$maximum)>0);}
    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&!self::greater($value,(string)$maximum))$value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::fail();return $value;
    }
    private static function reference(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>200||MarketplaceOrderReviewInput::pictures([$value])!==[$value])self::fail();return $value;
    }
    private static function text(mixed $value, int $minimum, int $maximum): void
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')<$minimum||mb_strlen($value,'UTF-8')>$maximum)self::fail();
    }
    private static function list(array $value): void {if(!array_is_list($value))self::fail();}
    private static function keys(array $value, array $wanted): void {$actual=array_keys($value);sort($actual,SORT_STRING);sort($wanted,SORT_STRING);if($actual!==$wanted)self::fail();}
    private static function fail(): never {throw new AuthUnavailableException();}

    private static function columns(): array
    {
        return [
            'id'=>['bigint unsigned','NO',null,null,null,'auto_increment'],
            'uid'=>['int unsigned','NO',null,null,null,''],
            'purpose'=>["enum('avatar_public','public_upload_compat','review_public_image','user_promotion_image','user_promotion_qr','legacy_refund_evidence','refund_shipping_proof','order_form_image','withdrawal_qr','agent_licence','order_verify_code','gift_receive_code')",'NO',null,'ascii','ascii_bin',''],
            'state'=>["enum('STAGED','BOUND','RETIRED')",'NO','STAGED','ascii','ascii_bin',''],
            'storage_type'=>['tinyint unsigned','NO',null,null,null,''],
            'object_key'=>['varchar(512)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'thumbnail_key'=>['varchar(512)','NO','','utf8mb4','utf8mb4_bin',''],
            'name'=>['varchar(100)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'real_name'=>['varchar(255)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'media_type'=>['varchar(127)','NO',null,'ascii','ascii_bin',''],
            'byte_size'=>['bigint unsigned','NO',null,null,null,''],
            'width'=>['int unsigned','NO','0',null,null,''],
            'height'=>['int unsigned','NO','0',null,null,''],
            'content_sha256'=>['binary(32)','NO',null,null,null,''],
            'created_at'=>['int unsigned','NO',null,null,null,''],
            'expires_at'=>['int unsigned','YES',null,null,null,''],
            'bound_at'=>['int unsigned','YES',null,null,null,''],
            'retired_at'=>['int unsigned','YES',null,null,null,''],
            'root_order_id'=>['int unsigned','YES',null,null,null,''],
            'order_item_id'=>['int','YES',null,null,null,''],
            'refund_id'=>['int','YES',null,null,null,''],
            'review_id'=>['int','YES',null,null,null,''],
            'confirmation_ref'=>['binary(32)','YES',null,null,null,''],
            'form_product_id'=>['mediumint','YES',null,null,null,''],
            'form_definition_sha256'=>['binary(32)','YES',null,null,null,''],
            'form_field_index'=>['smallint unsigned','YES',null,null,null,''],
            'extract_id'=>['int unsigned','YES',null,null,null,''],
            'agent_apply_id'=>['int unsigned','YES',null,null,null,''],
            'source_product_id'=>['mediumint','YES',null,null,null,''],
            'source_qrcode_id'=>['int unsigned','YES',null,null,null,''],
            'created_by_uid'=>['int unsigned','YES',null,null,null,''],
            'created_by_admin_id'=>['smallint unsigned','YES',null,null,null,''],
            'created_by_job'=>['varchar(32)','YES',null,'ascii','ascii_bin',''],
            'legacy_att_id'=>['int','YES',null,null,null,''],
        ];
    }
}
