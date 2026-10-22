<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\services\marketplace\order\MarketplaceOrderReviewInput;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Native existing-table access on the caller's captured default PDO. No current-catalog ownership inference. */
final class MarketplaceOrderReviewDao
{
    private const FIELDS=['id','uid','oid','unique','product_id','reply_type','product_score','service_score','comment','pics','add_time',
        'merchant_reply_content','merchant_reply_time','is_del','is_reply','nickname','avatar','suk','status','mer_id'];
    private const ATTACHMENT=['att_id','name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','real_name','scan_token','type','mer_id'];
    private const MIMES=['image/jpg','image/jpeg','image/gif','image/png','image/x-icon','image/vnd.microsoft.icon','image/webp'];

    public function assertStructure(ConnectionInterface $connection,PDO $pdo):void
    {
        $this->outside($connection,$pdo);$this->structure($connection,$pdo);$this->outside($connection,$pdo);
    }
    public function readStructureReadiness(ConnectionInterface $connection,PDO $pdo):array
    {
        $this->active($connection,$pdo);$this->structure($connection,$pdo);$this->active($connection,$pdo);
        return ['schema'=>1,'state'=>'READY'];
    }
    public function locateCustomerRoot(ConnectionInterface $connection,PDO $pdo,int $uid,int $rootId):?array
    {
        $this->native($connection,$pdo);self::id($uid);self::id($rootId);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').
            ' WHERE id=? AND uid=? AND pid=0 AND marketplace_trade_version=1 LIMIT 2',[$rootId,$uid]);
        if(count($rows)>1)self::fail();if($rows===[])return null;$this->root($rows[0]);return $rows[0];
    }
    public function locateReview(ConnectionInterface $connection,PDO $pdo,int $id,?int $merId):?array
    {
        $this->native($connection,$pdo);self::id($id);$sql='SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE id=?';$args=[$id];
        if($merId!==null){self::integer($merId,1,4294967295);$sql.=' AND mer_id=?';$args[]=$merId;}
        $rows=$this->rows($connection,$pdo,$sql.' LIMIT 2',$args);if(count($rows)>1)self::fail();
        return $rows===[]?null:self::review($rows[0]);
    }
    /** Only real saved V1 roots. Rows with unknown or legacy provenance remain in the old APIs. */
    public function rootsForReviews(ConnectionInterface $connection,PDO $pdo,array $reviews):array
    {
        $this->native($connection,$pdo);self::list($reviews);$out=[];
        foreach($reviews as$row){self::review($row);$id=self::id($row['oid']);if(isset($out[$id]))continue;
            $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_order').' WHERE id=? LIMIT 2',[$id]);
            if(count($rows)!==1)self::fail();$root=$rows[0];$this->root($root);
            self::stored($row,['uid'=>self::id($root['uid']),'mer_id'=>self::integer($root['mer_id'],1,4294967295)]);$out[$id]=$root;
        }
        return $out;
    }
    public function readPage(ConnectionInterface $connection,PDO $pdo,?int $merId,array $query):array
    {
        $this->snapshot($connection,$pdo);$normal=$merId===null?MarketplaceOrderReviewInput::platformPage($query):MarketplaceOrderReviewInput::merchantPage($query);
        if($normal!==$query)self::fail();$args=[];
        $where='o.marketplace_trade_version=1 AND o.pid=0 AND r.mer_id>0 AND r.uid=o.uid AND r.mer_id=o.mer_id'.
            ' AND i.uid=o.uid AND i.mer_id=o.mer_id AND i.product_id=r.product_id AND r.reply_type=\'product\'';
        if($merId!==null){self::integer($merId,1,4294967295);$where.=' AND r.mer_id=?';$args[]=$merId;}
        foreach(['status','is_reply','product_id','mer_id']as$field)if(array_key_exists($field,$normal)){$where.=' AND r.'.$field.'=?';$args[]=$normal[$field];}
        $from=' FROM '.$this->table($connection,'store_product_reply').' r INNER JOIN '.$this->table($connection,'store_order').' o ON o.id=r.oid'.
            ' INNER JOIN '.$this->table($connection,'store_order_cart_info').' i ON i.oid=o.id AND BINARY i.`unique`=BINARY r.`unique` WHERE '.$where;
        $countRows=$this->rows($connection,$pdo,'SELECT COUNT(*) AS total'.$from,$args);
        if(count($countRows)!==1||array_keys($countRows[0])!==['total'])self::fail();$count=self::integer($countRows[0]['total'],0,PHP_INT_MAX);
        $offset=($normal['page']-1)*$normal['limit'];$list=$this->rows($connection,$pdo,'SELECT r.*'.$from.' ORDER BY r.id DESC LIMIT '.$normal['limit'].' OFFSET '.$offset,$args);
        foreach($list as$row)self::review($row);$this->snapshot($connection,$pdo);return ['count'=>$count,'list'=>$list];
    }
    /** Complete root collections, including deleted/rejected rows. A prior review never releases its item. */
    public function readForRoots(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        $this->snapshot($connection,$pdo);self::list($roots);$out=[];
        foreach($roots as$root){$this->root($root);$id=self::id($root['id']);if(isset($out[$id]))self::fail();
            $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE oid=? ORDER BY id',[$id]);
            foreach($rows as$row)self::review($row);$out[$id]=$rows;
        }
        $this->snapshot($connection,$pdo);return $out;
    }
    public function lockForRoot(ConnectionInterface $connection,PDO $pdo,array $root):array
    {
        $this->writer($connection,$pdo);$this->root($root);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE oid=? ORDER BY id FOR UPDATE',[self::id($root['id'])]);
        foreach($rows as$row)self::review($row);$this->writer($connection,$pdo);return $rows;
    }
    public function customerProfile(ConnectionInterface $connection,PDO $pdo,int $uid):array
    {
        $this->writer($connection,$pdo);self::id($uid);
        $rows=$this->rows($connection,$pdo,'SELECT uid,nickname,avatar,status,is_del FROM '.$this->table($connection,'user').' WHERE uid=? LIMIT 2 FOR UPDATE',[$uid]);
        if(count($rows)!==1)self::fail();$row=$rows[0];self::keys($row,['uid','nickname','avatar','status','is_del']);
        self::stored($row,['uid'=>$uid,'status'=>1,'is_del'=>0]);self::text($row['nickname'],64);self::text($row['avatar'],255);return $row;
    }
    /** Ordered qualifying native attachment observations, with repeats preserved. No imaginary uploader owner. */
    public function publicPictures(ConnectionInterface $connection,PDO $pdo,array $references):array
    {
        $this->writer($connection,$pdo);if(MarketplaceOrderReviewInput::pictures($references)!==$references)self::fail();$out=[];
        foreach($references as$reference){
            $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'system_attachment').
                ' WHERE BINARY att_dir=BINARY ? AND module_type=3 ORDER BY att_id',[$reference]);$qualified=[];
            foreach($rows as$row){self::keys($row,self::ATTACHMENT);self::id($row['att_id']);
                if($row['att_dir']!==$reference||self::integer($row['module_type'],3,3)!==3)self::fail();
                if(self::integer($row['type'],0,1)===0&&self::integer($row['image_type'],1,7)>0&&in_array($row['att_type'],self::MIMES,true))$qualified[]=$row;
            }
            if($qualified===[])self::fail();$out[]=$qualified;
        }
        $this->writer($connection,$pdo);return $out;
    }
    public function insert(ConnectionInterface $connection,PDO $pdo,array $data):array
    {
        $this->writer($connection,$pdo);$fields=self::FIELDS;array_shift($fields);self::keys($data,$fields);
        $columns=[];$values=[];foreach($fields as$field){$columns[]='`'.$field.'`';$values[]=$data[$field];}
        $statement=$pdo->prepare('INSERT INTO '.$this->table($connection,'store_product_reply').' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        if(!$statement||$statement->execute($values)!==true||$statement->rowCount()!==1||$statement->closeCursor()!==true)self::fail();
        $id=self::id($pdo->lastInsertId());$after=$this->lockedReview($connection,$pdo,$id);self::stored($after,$data);return $after;
    }
    public function reply(ConnectionInterface $connection,PDO $pdo,array $before,string $content,int $at):array
    {
        self::review($before);$normal=MarketplaceOrderReviewInput::reply(['schema'=>1,'content'=>$content]);self::integer($at,1,2147483647);
        return $this->patch($connection,$pdo,$before,['merchant_reply_content'=>$normal['content'],'merchant_reply_time'=>$at,'is_reply'=>1]);
    }
    public function moderate(ConnectionInterface $connection,PDO $pdo,array $before,int $status):array
    {
        self::review($before);MarketplaceOrderReviewInput::moderate(['schema'=>1,'status'=>$status]);return $this->patch($connection,$pdo,$before,['status'=>$status]);
    }
    private function patch(ConnectionInterface $connection,PDO $pdo,array $before,array $patch):array
    {
        $this->writer($connection,$pdo);$id=self::id($before['id']);if($this->lockedReview($connection,$pdo,$id)!==$before)self::fail();
        $assign=[];$args=[];foreach($patch as$key=>$value){$assign[]='`'.$key.'`=?';$args[]=$value;}$args[]=$id;$args[]=self::integer($before['mer_id'],1,4294967295);
        $statement=$pdo->prepare('UPDATE '.$this->table($connection,'store_product_reply').' SET '.implode(',',$assign).' WHERE id=? AND mer_id=?');
        if(!$statement||$statement->execute($args)!==true||!in_array($statement->rowCount(),[0,1],true)||$statement->closeCursor()!==true)self::fail();
        $after=$this->lockedReview($connection,$pdo,$id);$expected=$before;
        foreach($patch as$key=>$value)$expected[$key]=is_int($value)&&is_string($before[$key])?(string)$value:$value;
        if($after!==$expected)self::fail();$this->writer($connection,$pdo);return $after;
    }
    private function lockedReview(ConnectionInterface $connection,PDO $pdo,int $id):array
    {
        $this->writer($connection,$pdo);$rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'store_product_reply').' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
        if(count($rows)!==1)self::fail();return self::review($rows[0]);
    }
    private function structure(ConnectionInterface $connection,PDO $pdo):void
    {
        $review=['id'=>['int','NO',null,'auto_increment'],'uid'=>['int','NO','0',''],'oid'=>['int','NO','0',''],
            'unique'=>['char(32)','NO','',''],'product_id'=>['int','NO','0',''],'reply_type'=>['varchar(32)','NO','product',''],
            'product_score'=>['tinyint','NO','0',''],'service_score'=>['tinyint','NO','0',''],'comment'=>['varchar(512)','NO','',''],
            'pics'=>['text','YES',null,''],'add_time'=>['int','NO','0',''],'merchant_reply_content'=>['varchar(300)','NO','',''],
            'merchant_reply_time'=>['int','NO','0',''],'is_del'=>['tinyint unsigned','NO','0',''],'is_reply'=>['tinyint','NO','0',''],
            'nickname'=>['varchar(64)','NO','',''],'avatar'=>['varchar(255)','NO','',''],'suk'=>['varchar(255)','NO','',''],
            'status'=>['tinyint','NO','0',''],'mer_id'=>['int unsigned','YES',null,'']];
        $attachment=['att_id'=>['int','NO',null,'auto_increment'],'name'=>['varchar(100)','NO','',''],'att_dir'=>['varchar(200)','NO','',''],
            'satt_dir'=>['varchar(200)','NO','',''],'att_size'=>['char(30)','NO','',''],'att_type'=>['char(30)','NO','',''],
            'pid'=>['int','NO','0',''],'time'=>['int','NO','0',''],'image_type'=>['tinyint unsigned','NO','1',''],
            'module_type'=>['tinyint unsigned','NO','1',''],'real_name'=>['varchar(255)','NO','',''],'scan_token'=>['varchar(32)','NO','',''],'type'=>['tinyint','NO','0',''],'mer_id'=>['int unsigned','YES',null,'']];
        $this->columns($connection,$pdo,'store_product_reply',$review);$this->columns($connection,$pdo,'system_attachment',$attachment);
        $this->index($connection,$pdo,'store_product_reply',['id'],true,true);
        $this->index($connection,$pdo,'store_product_reply',['oid','unique'],true,false);
        $this->index($connection,$pdo,'store_product_reply',['mer_id','id'],false,false);
        $this->index($connection,$pdo,'store_order_cart_info',['oid','unique'],true,false);
        $this->index($connection,$pdo,'system_attachment',['att_id'],true,true);
    }
    private function columns(ConnectionInterface $connection,PDO $pdo,string $suffix,array $expected):void
    {
        $table=$this->physical($connection,$suffix);
        $tables=$this->rows($connection,$pdo,'SELECT ENGINE,TABLE_COLLATION,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ?',[$table]);
        if(count($tables)!==1||$tables[0]['ENGINE']!=='InnoDB'||$tables[0]['TABLE_TYPE']!=='BASE TABLE'
            ||!is_string($tables[0]['TABLE_COLLATION'])||!str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_'))self::fail();
        $rows=$this->rows($connection,$pdo,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME FROM information_schema.COLUMNS'.
            ' WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION',[$table]);
        if(array_column($rows,'COLUMN_NAME')!==array_keys($expected))self::fail();
        foreach($rows as$row){$type=strtolower($row['COLUMN_TYPE']);$type=preg_replace('/\b(tinyint|int)\([0-9]+\)/','$1',$type);
            $actual=[$type,$row['IS_NULLABLE'],$row['COLUMN_DEFAULT'],$row['EXTRA']];if($actual!==$expected[$row['COLUMN_NAME']]
                &&!($suffix==='system_attachment'&&$row['COLUMN_NAME']==='mer_id'&&$actual===['int unsigned','NO',null,'']))self::fail();
            if(preg_match('/\A(?:char|varchar|text)/',$type)===1&&$row['CHARACTER_SET_NAME']!=='utf8mb4')self::fail();
        }
    }
    private function index(ConnectionInterface $connection,PDO $pdo,string $suffix,array $columns,bool $unique,bool $primary):void
    {
        $rows=$this->rows($connection,$pdo,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS'.
            ' WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX',[$this->physical($connection,$suffix)]);$groups=[];
        foreach($rows as$row)$groups[$row['INDEX_NAME']][]=$row;$found=false;
        foreach($groups as$name=>$group){if($primary&&$name!=='PRIMARY')continue;$actual=[];$valid=true;
            foreach($group as$position=>$row){$actual[]=$row['COLUMN_NAME'];if(self::integer($row['SEQ_IN_INDEX'],1,PHP_INT_MAX)!==$position+1
                ||self::integer($row['NON_UNIQUE'],0,1)!==($unique?0:1)||$row['SUB_PART']!==null||$row['INDEX_TYPE']!=='BTREE')$valid=false;}
            if($valid&&$actual===$columns)$found=true;
        }
        if(!$found)self::fail();
    }
    private function root(array $root):void
    {
        self::id($root['id']??null);self::id($root['uid']??null);self::integer($root['mer_id']??null,1,4294967295);
        self::stored($root,['pid'=>0,'marketplace_trade_version'=>1]);
    }
    private static function review(array $row):array
    {
        self::keys($row,self::FIELDS);foreach(['id','uid','oid','product_id']as$key)self::id($row[$key]);self::integer($row['mer_id'],1,4294967295);
        if(!is_string($row['unique'])||preg_match('/\A[a-f0-9]{32}\z/D',$row['unique'])!==1||$row['reply_type']!=='product')self::fail();
        foreach(['product_score','service_score']as$key)self::integer($row[$key],1,5);
        foreach(['status'=>2,'is_del'=>1,'is_reply'=>1]as$key=>$max)self::integer($row[$key],0,$max);
        foreach(['add_time','merchant_reply_time']as$key)self::integer($row[$key],0,2147483647);
        foreach(['comment'=>512,'merchant_reply_content'=>300,'nickname'=>64,'avatar'=>255,'suk'=>255]as$key=>$max)self::text($row[$key],$max);
        if(!is_string($row['pics'])||strlen($row['pics'])>65535)self::fail();
        return $row;
    }
    private function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $args):array
    {
        $this->native($connection,$pdo);$statement=$pdo->prepare($sql);if(!$statement||$statement->execute($args)!==true)self::fail();
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows)||$statement->closeCursor()!==true)self::fail();
        $this->native($connection,$pdo);return $rows;
    }
    private function table(ConnectionInterface $connection,string $suffix):string {return '`'.$this->physical($connection,$suffix).'`';}
    private function physical(ConnectionInterface $connection,string $suffix):string
    {
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||preg_match('/\A[a-z_]+\z/D',$suffix)!==1)self::fail();return $prefix.$suffix;
    }
    private function native(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
        $depth=$connection->transactionDepth();if(!in_array($depth,[0,1],true)||($depth===1&&!$pdo->inTransaction()))self::fail();
    }
    private function outside(ConnectionInterface $connection,PDO $pdo):void {$this->native($connection,$pdo);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::fail();}
    private function snapshot(ConnectionInterface $connection,PDO $pdo):void {$this->native($connection,$pdo);if($connection->transactionDepth()!==0||!$pdo->inTransaction())self::fail();}
    private function writer(ConnectionInterface $connection,PDO $pdo):void {$this->native($connection,$pdo);if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::fail();}
    private function active(ConnectionInterface $connection,PDO $pdo):void {$this->native($connection,$pdo);if(!$pdo->inTransaction())self::fail();}
    private static function stored(array $row,array $expected):void
    {
        foreach($expected as$key=>$value){if(!array_key_exists($key,$row))self::fail();
            if(is_int($value)){if(self::integer($row[$key],0,4294967295)!==$value)self::fail();}elseif($row[$key]!==$value)self::fail();}
    }
    private static function integer(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$max,0)<=0)$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function id(mixed $value):int {return self::integer($value,1,2147483647);}
    private static function text(mixed $value,int $max):void {if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)self::fail();}
    private static function list(mixed $value):void {if(!is_array($value)||!array_is_list($value))self::fail();}
    private static function keys(array $value,array $expected):void {$keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected)self::fail();}
    private static function fail():never {throw new AuthUnavailableException();}
}
