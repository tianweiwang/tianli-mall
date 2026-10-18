<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\compat\think\orm\db\connector\Mysql as ReadMysql;
use crmeb\exceptions\AuthUnavailableException;
use think\db\ConnectionInterface;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use think\facade\Db;

/** Native Task6/Task7 persistence and exact structure. Transaction ownership remains with the service. */
final class MarketplaceOrderFulfillmentDao
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const MARKER='marketplace-ms4-fulfillment-v1';
    private const AFTERSALE_MARKER='marketplace-ms4-aftersale-v1';
    private const AFTERSALE_FILE_MARKER='marketplace-ms4-aftersale-file-v1';
    private const TABLES=['user','store_order','store_order_cart_info','store_order_refund','store_order_status',
        'marketplace_fulfillment_operation','marketplace_fulfillment_range','marketplace_merchant','marketplace_stock_movement',
        'store_product','store_bargain','store_combination','store_seckill',
        'store_order_invoice','store_order_economize','store_product_reply','system_store',
        'marketplace_aftersale_action','marketplace_aftersale_file'];
    private string $prefix;
    private string $database='';
    private ?PDO $schemaPdo=null;

    public function __construct()
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        foreach(self::TABLES as$name)self::quote($prefix.$name);
        $this->prefix=$prefix;
    }

    /** Exact accepted two-object metadata, once at each entry's outside boundary; never DDL. */
    public function assertStructure(PDO $pdo):void
    {
        $this->native($pdo,false);$this->schemaPdo=$pdo;
        try{
            $this->structureEnvironment($pdo);
            foreach(self::newTables() as$name=>$definition){
                $meta=$this->metadata($name);
                if($meta===null||!$this->sameNewTable($meta,$definition))self::unavailable();
            }
            $this->native($pdo,false);
        }finally{$this->schemaPdo=null;}
    }

    /**
     * Internal structure observation inside the caller-owned native RR/READ ONLY snapshot.
     * Does not prove authorization, family emptiness, payment or the transaction characteristics.
     * Caller reobserves before publication and keeps complete same-snapshot family/history gates.
     */
    public function readStructureReadiness(ConnectionInterface $connection,PDO $pdo):array
    {
        $this->readStructureConnection($connection,$pdo);
        if($this->schemaPdo!==null)self::unavailable();
        $previousDatabase=$this->database;$this->schemaPdo=$pdo;
        try{
            $this->structureEnvironment($pdo);$database=$this->database;
            $definitions=self::newTables();
            if(array_keys($definitions)!==['marketplace_fulfillment_operation','marketplace_fulfillment_range'])self::unavailable();
            $present=[];
            foreach($definitions as$name=>$definition){
                $meta=$this->metadata($name);
                if($meta!==null&&!$this->sameNewTable($meta,$definition))self::unavailable();
                $this->assertStructureConstraintNames($name,$definition,$meta);
                $present[$name]=$meta!==null;
            }
            $operation=$present['marketplace_fulfillment_operation'];$range=$present['marketplace_fulfillment_range'];
            if(!$operation&&$range)self::unavailable();
            $result=['state'=>$operation&&$range?'READY':'SCHEMA_PENDING',
                'operation_present'=>$operation,'range_present'=>$range];
            $this->readStructureConnection($connection,$pdo);
            if($this->query($pdo,'SELECT DATABASE()')->fetchColumn()!==$database)self::unavailable();
            $this->readStructureConnection($connection,$pdo);
            return $result;
        }finally{$this->schemaPdo=null;$this->database=$previousDatabase;}
    }

    /** Final Task7 structure only, including empty tables; never DDL or application eligibility. */
    public function assertAftersaleStructure(PDO $pdo):void
    {
        $this->native($pdo,false);if($this->schemaPdo!==null)self::unavailable();
        $previous=$this->database;$this->schemaPdo=$pdo;
        try{$this->structureEnvironment($pdo);$this->assertAftersaleMetadata();$this->native($pdo,false);}
        finally{$this->schemaPdo=null;$this->database=$previous;}
    }

    /** The actual caller owns native RR READ ONLY; this checks the captured connection, not that ownership. */
    public function readAftersaleStructureReadiness(ConnectionInterface $connection,PDO $pdo):array
    {
        $this->readStructureConnection($connection,$pdo);if($this->schemaPdo!==null)self::unavailable();
        $previous=$this->database;$this->schemaPdo=$pdo;
        try{
            $this->structureEnvironment($pdo);$database=$this->database;$this->assertAftersaleMetadata();
            $this->readStructureConnection($connection,$pdo);
            if($this->query($pdo,'SELECT DATABASE()')->fetchColumn()!==$database)self::unavailable();
            $this->readStructureConnection($connection,$pdo);return ['schema'=>2,'state'=>'READY'];
        }finally{$this->schemaPdo=null;$this->database=$previous;}
    }

    private function assertAftersaleMetadata():void
    {
        $definitions=self::aftersaleTables();
        if(array_keys($definitions)!==['marketplace_aftersale_action','store_order_refund',
            'marketplace_fulfillment_operation','marketplace_fulfillment_range','marketplace_aftersale_file'])self::unavailable();
        foreach($definitions as$name=>$definition){
            $meta=$this->metadata($name);
            if($meta===null||!$this->sameAftersaleTable($meta,$definition))self::unavailable();
            $this->assertStructureConstraintNames($name,$definition,$meta);
        }
    }

    /** Complete native family core; all business/permission and committed-prefix proofs remain with the owned caller. */
    public function readFulfillmentFamiliesForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $roots=$this->aftersaleSnapshotRoots($connection,$pdo,$fullAuthorizedRoots);$out=[];
        foreach($roots as$id=>$root)$out[$id]=['children'=>[],'child_items'=>[],'operations'=>[],'ranges'=>[]];
        if($roots===[]){$this->readStructureConnection($connection,$pdo);return [];}
        $ids=array_keys($roots);sort($ids,SORT_NUMERIC);$marks=implode(',',array_fill(0,count($ids),'?'));
        $children=[];$items=[];$operations=[];$seen=[];
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').
            ' WHERE pid IN ('.$marks.') ORDER BY pid,id',$ids);
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$rid=self::integer($row['pid']??null,1,self::INT_MAX);
            if(!isset($roots[$rid])||isset($children[$id])||isset($roots[$id]))self::unavailable();
            self::stored($row,['uid'=>self::integer($roots[$rid]['uid'],1,self::INT_MAX),
                'mer_id'=>self::integer($roots[$rid]['mer_id'],1,self::UINT_MAX),'marketplace_trade_version'=>1]);
            if($out[$rid]['children']!==[]&&$id<=self::integer($out[$rid]['children'][array_key_last($out[$rid]['children'])]['id'],1,self::INT_MAX))self::unavailable();
            $children[$id]=$rid;$out[$rid]['children'][]=$row;
        }
        $childQuery='SELECT id FROM '.$this->table('store_order').' WHERE pid IN ('.$marks.')';
        $fields=['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num','split_status','cart_info','unique','old_cart_id'];
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_cart_info').
            ' WHERE oid IN ('.$childQuery.') ORDER BY oid,id',$ids);
        $lastItems=[];
        foreach($rows as$row){
            self::keys($row,$fields);$id=self::integer($row['id'],1,self::INT_MAX);$oid=self::integer($row['oid'],1,self::INT_MAX);
            if(!isset($children[$oid])||isset($items[$id])||$id<=($lastItems[$oid]??0))self::unavailable();$rid=$children[$oid];
            self::stored($row,['uid'=>self::integer($roots[$rid]['uid'],1,self::INT_MAX),'mer_id'=>self::integer($roots[$rid]['mer_id'],1,self::UINT_MAX)]);
            $items[$id]=['root'=>$rid,'child'=>$oid];$lastItems[$oid]=$id;$out[$rid]['child_items'][]=$row;
        }
        $tables=self::newTables();$lastOps=[];
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_fulfillment_operation').
            ' WHERE root_order_id IN ('.$marks.') ORDER BY root_order_id,id',$ids);
        foreach($rows as$row){
            self::keys($row,array_keys($tables['marketplace_fulfillment_operation']['columns']));
            $id=self::u64($row['id']);$rid=self::integer($row['root_order_id'],1,self::INT_MAX);$child=self::integer($row['child_order_id'],1,self::INT_MAX);
            if(!isset($roots[$rid])||!isset($children[$child])||$children[$child]!==$rid||isset($operations['o:'.$id])
                ||bccomp($id,$lastOps[$rid]??'0',0)<=0)self::unavailable();
            self::stored($row,['uid'=>self::integer($roots[$rid]['uid'],1,self::INT_MAX),'mer_id'=>self::integer($roots[$rid]['mer_id'],1,self::UINT_MAX)]);
            $operations['o:'.$id]=['root'=>$rid,'child'=>$child,'action'=>$row['action']];$lastOps[$rid]=$id;$out[$rid]['operations'][]=$row;
        }
        $lastRanges=[];
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_fulfillment_range').
            ' WHERE root_order_id IN ('.$marks.') ORDER BY root_order_id,id',$ids);
        foreach($rows as$row){
            self::keys($row,array_keys($tables['marketplace_fulfillment_range']['columns']));
            $id=self::u64($row['id']);$rid=self::integer($row['root_order_id'],1,self::INT_MAX);
            $child=self::integer($row['child_order_id'],1,self::INT_MAX);$item=self::integer($row['child_item_id'],1,self::INT_MAX);
            $op=$operations['o:'.self::u64($row['operation_id'])]??null;
            if(!isset($roots[$rid])||isset($seen['r:'.$id])||bccomp($id,$lastRanges[$rid]??'0',0)<=0
                ||!isset($items[$item])||$items[$item]!==['root'=>$rid,'child'=>$child]
                ||$op!==['root'=>$rid,'child'=>$child,'action'=>$row['operation_action']])self::unavailable();
            self::stored($row,['uid'=>self::integer($roots[$rid]['uid'],1,self::INT_MAX),'mer_id'=>self::integer($roots[$rid]['mer_id'],1,self::UINT_MAX)]);
            $seen['r:'.$id]=true;$lastRanges[$rid]=$id;$out[$rid]['ranges'][]=$row;
        }
        $this->readStructureConnection($connection,$pdo);return $out;
    }

    /** Raw compatibility capture: no business refundRow/owner gate and no removal of eight-NULL legacy mirrors. */
    public function readRefundRowsForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $roots=$this->aftersaleSnapshotRoots($connection,$pdo,$fullAuthorizedRoots);$out=[];
        foreach($roots as$id=>$root)$out[$id]=[];
        if($roots===[]){$this->readStructureConnection($connection,$pdo);return [];}
        $ids=array_keys($roots);sort($ids,SORT_NUMERIC);$marks=implode(',',array_fill(0,count($ids),'?'));$children=[];
        foreach($this->familySnapshotRows($connection,$pdo,'SELECT id,pid FROM '.$this->table('store_order').
            ' WHERE pid IN ('.$marks.') ORDER BY pid,id',$ids) as$row){
            self::keys($row,['id','pid']);$id=self::integer($row['id'],1,self::INT_MAX);$pid=self::integer($row['pid'],1,self::INT_MAX);
            if(!isset($roots[$pid])||isset($children[$id]))self::unavailable();$children[$id]=$pid;
        }
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT rf.* FROM '.$this->table('store_order_refund').
            ' rf WHERE rf.marketplace_root_order_id IN ('.$marks.') OR rf.store_order_id IN ('.$marks.')'.
            ' OR rf.store_order_id IN (SELECT id FROM '.$this->table('store_order').' WHERE pid IN ('.$marks.')) ORDER BY rf.id',
            array_merge($ids,$ids,$ids));
        $fields=array_keys(self::aftersaleTables()['store_order_refund']['columns']);$last=0;
        foreach($rows as$row){
            self::keys($row,$fields);$id=self::integer($row['id'],1,self::INT_MAX);if($id<=$last)self::unavailable();$last=$id;$matched=false;
            foreach($roots as$rid=>$root){
                $oid=$row['store_order_id'];$stored=$row['marketplace_root_order_id'];
                $childRoot=(is_int($oid)||is_string($oid))?($children[(string)$oid]??null):null;
                if(($stored!==null&&(string)$stored===(string)$rid)||(string)$oid===(string)$rid||$childRoot===$rid){
                    $out[$rid][]=$row;$matched=true;
                }
            }
            if(!$matched)self::unavailable();
        }
        $this->readStructureConnection($connection,$pdo);return $out;
    }

    private function aftersaleSnapshotRoots(ConnectionInterface $connection,PDO $pdo,array $rows):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($rows)||count($rows)>100)self::unavailable();$roots=[];
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();foreach($row as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
            $id=self::integer($row['id']??null,1,self::INT_MAX);self::integer($row['uid']??null,1,self::INT_MAX);self::integer($row['mer_id']??null,1,self::UINT_MAX);
            self::stored($row,['marketplace_trade_version'=>1,'pid'=>0]);self::text($row['order_id']??null,32,true);
            if(isset($roots[$id]))self::unavailable();$roots[$id]=$row;
        }
        return $roots;
    }
    private function readStructureConnection(ConnectionInterface $connection,PDO $pdo):void
    {
        if(!$connection instanceof ReadMysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->transactionDepth()!==0||$connection->getConfig('deploy')!==0
            ||$connection->getConfig('rw_separate')!==false||$connection->getConfig('prefix')!==$this->prefix)self::unavailable();
        $this->native($pdo,true);
    }

    private function structureEnvironment(PDO $pdo):void
    {
        $database=$this->query($pdo,'SELECT DATABASE()')->fetchColumn();
        if(!is_string($database)||$database==='')self::unavailable();self::quote($database);$this->database=$database;
        $version=$this->query($pdo,'SELECT VERSION()')->fetchColumn();
        if(!is_string($version)||preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D',$version,$m)!==1
            ||stripos($version,'mariadb')!==false||version_compare($m[1],'8.0.16','<'))self::unavailable();
        $mode=$this->query($pdo,'SELECT @@SESSION.sql_mode')->fetchColumn();
        if(!is_string($mode)||array_intersect(['STRICT_TRANS_TABLES','STRICT_ALL_TABLES'],explode(',',strtoupper($mode)))===[])self::unavailable();
    }

    private function assertStructureConstraintNames(string $name,array $definition,?array $meta):void
    {
        foreach (['foreign' => 'FOREIGN KEY', 'checks' => 'CHECK'] as $kind => $type) {
            foreach (array_keys($definition[$kind]) as $constraint) {
                $occupied = $this->schemaRows('SELECT TABLE_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=?', [$this->database, $this->prefix . $constraint]);
                if (count($occupied) > 1 || ($occupied !== [] && ($occupied[0]['TABLE_NAME'] !== $this->prefix . $name
                    || $occupied[0]['CONSTRAINT_TYPE'] !== $type || $meta === null))) {
                    self::unavailable();
                }
            }
        }
    }

    /**
     * Complete page-local family facts inside the caller-owned native read snapshot.
     * Saved owners are data; authorization and RR READ ONLY ownership remain with the caller.
     */
    public function readFamiliesForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($fullAuthorizedRoots)||count($fullAuthorizedRoots)>100)self::unavailable();
        $roots=[];$families=[];$hasPaid=false;
        foreach($fullAuthorizedRoots as$root){
            if(!is_array($root))self::unavailable();
            foreach($root as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
            $id=self::integer($root['id']??null,1,self::INT_MAX);
            $uid=self::integer($root['uid']??null,1,self::INT_MAX);
            $mer=self::integer($root['mer_id']??null,1,self::UINT_MAX);
            self::text($root['order_id']??null,32,true);
            self::integer($root['marketplace_trade_version']??null,1,1);self::integer($root['pid']??null,0,0);
            $paid=self::integer($root['paid']??null,0,1);
            if(isset($roots[$id]))self::unavailable();
            $roots[$id]=['uid'=>$uid,'mer_id'=>$mer,'paid'=>$paid];
            $families[$id]=['children'=>[],'child_items'=>[],'refunds'=>[],'logs'=>[],'operations'=>null,'ranges'=>null];
            if($paid===1)$hasPaid=true;
        }
        if($roots===[]){$this->readStructureConnection($connection,$pdo);return ['readiness'=>null,'roots'=>[]];}
        $ids=array_keys($roots);sort($ids,SORT_NUMERIC);$marks=implode(',',array_fill(0,count($ids),'?'));
        $readiness=$hasPaid?$this->readStructureReadiness($connection,$pdo):null;
        foreach($families as&$family){
            if($readiness!==null&&$readiness['operation_present'])$family['operations']=[];
            if($readiness!==null&&$readiness['range_present'])$family['ranges']=[];
        }unset($family);
        $orders=$this->table('store_order');$childMap=[];$lastPid=0;$lastChild=0;
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$orders.
            ' WHERE pid IN ('.$marks.') ORDER BY pid,id',$ids);
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$pid=self::integer($row['pid']??null,1,self::INT_MAX);
            if(!isset($roots[$pid])||isset($childMap[$id])||isset($roots[$id])
                ||$pid<$lastPid||($pid===$lastPid&&$id<=$lastChild))self::unavailable();
            self::stored($row,['uid'=>$roots[$pid]['uid'],'mer_id'=>$roots[$pid]['mer_id'],'marketplace_trade_version'=>1]);
            self::text($row['order_id']??null,32,true);$lastPid=$pid;$lastChild=$id;
            $childMap[$id]=$pid;$families[$pid]['children'][]=$row;
        }
        $childQuery='SELECT child.id FROM '.$orders.' child WHERE child.pid IN ('.$marks.')';
        $itemMap=[];$lastOid=0;$lastItem=0;
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT ci.* FROM '.$this->table('store_order_cart_info').
            ' ci WHERE ci.oid IN ('.$childQuery.') ORDER BY ci.oid,ci.id',$ids);
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$oid=self::integer($row['oid']??null,1,self::INT_MAX);
            if(!isset($childMap[$oid])||isset($itemMap[$id])||$oid<$lastOid||($oid===$lastOid&&$id<=$lastItem))self::unavailable();
            $rid=$childMap[$oid];self::stored($row,['uid'=>$roots[$rid]['uid'],'mer_id'=>$roots[$rid]['mer_id']]);
            $lastOid=$oid;$lastItem=$id;$itemMap[$id]=['root'=>$rid,'child'=>$oid];$families[$rid]['child_items'][]=$row;
        }
        $both=array_merge($ids,$ids);$refundIds=[];$lastOid=0;$lastRefund=0;
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT rf.* FROM '.$this->table('store_order_refund').
            ' rf WHERE (rf.store_order_id IN ('.$marks.') OR rf.store_order_id IN ('.$childQuery.')) ORDER BY rf.store_order_id,rf.id',$both);
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$oid=self::integer($row['store_order_id']??null,1,self::INT_MAX);
            $rid=isset($roots[$oid])?$oid:($childMap[$oid]??null);
            if($rid===null||isset($refundIds[$id])||$oid<$lastOid||($oid===$lastOid&&$id<=$lastRefund))self::unavailable();
            self::stored($row,['uid'=>$roots[$rid]['uid'],'mer_id'=>$roots[$rid]['mer_id']]);
            if(!array_key_exists('marketplace_refund_mode',$row))self::unavailable();
            if($row['marketplace_refund_mode']!==null)self::text($row['marketplace_refund_mode'],16);
            $refundIds[$id]=true;$lastOid=$oid;$lastRefund=$id;
            $families[$rid]['refunds'][]=$row;
        }
        $types=['marketplace_fulfillment_ship','marketplace_fulfillment_receive'];
        $rows=$this->familySnapshotRows($connection,$pdo,'SELECT st.oid,st.change_type,st.change_message,st.change_time FROM '.
            $this->table('store_order_status').' st WHERE (st.oid IN ('.$marks.') OR st.oid IN ('.$childQuery.
            ')) AND st.change_type IN (?,?)',array_merge($both,$types));
        foreach($rows as$row){
            self::keys($row,['oid','change_type','change_message','change_time']);
            $rid=self::integer($row['oid'],1,self::INT_MAX);
            if(!isset($roots[$rid])||!in_array(self::text($row['change_type'],32,true),$types,true))self::unavailable();
            self::text($row['change_message'],256);self::integer($row['change_time'],1,self::UINT_MAX);
            $families[$rid]['logs'][]=$row;
        }
        $operationMap=[];$lastOperation=[];$lastRange=[];$rangeIds=[];
        foreach($ids as$id){$lastOperation[$id]='0';$lastRange[$id]='0';}
        if($readiness!==null&&$readiness['operation_present']){
            $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_fulfillment_operation').
                ' WHERE root_order_id IN ('.$marks.') ORDER BY root_order_id,id',$ids);
            foreach($rows as$row){
                $row=self::operation($row);$id=self::u64($row['id']);$rid=self::integer($row['root_order_id'],1,self::INT_MAX);
                $child=self::integer($row['child_order_id'],1,self::INT_MAX);
                if(!isset($roots[$rid])||$roots[$rid]['paid']!==1||$readiness['state']!=='READY'
                    ||isset($operationMap[$id])||bccomp($id,$lastOperation[$rid],0)<=0
                    ||!isset($childMap[$child])||$childMap[$child]!==$rid
                    ||$row['uid']!==$roots[$rid]['uid']||$row['mer_id']!==$roots[$rid]['mer_id'])self::unavailable();
                $lastOperation[$rid]=$id;$operationMap[$id]=$row;$families[$rid]['operations'][]=$row;
            }
        }
        if($readiness!==null&&$readiness['range_present']){
            $rows=$this->familySnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_fulfillment_range').
                ' WHERE root_order_id IN ('.$marks.') ORDER BY root_order_id,id',$ids);
            foreach($rows as$row){
                $row=self::rangeRow($row);$id=self::u64($row['id']);$rid=self::integer($row['root_order_id'],1,self::INT_MAX);
                $child=self::integer($row['child_order_id'],1,self::INT_MAX);$item=self::integer($row['child_item_id'],1,self::INT_MAX);
                $operation=$operationMap[self::u64($row['operation_id'])]??null;
                if(!isset($roots[$rid])||$roots[$rid]['paid']!==1||isset($rangeIds[$id])||bccomp($id,$lastRange[$rid],0)<=0
                    ||!isset($childMap[$child])||$childMap[$child]!==$rid||!isset($itemMap[$item])
                    ||$itemMap[$item]!==['root'=>$rid,'child'=>$child]||$operation===null
                    ||$operation['root_order_id']!==$rid||$operation['child_order_id']!==$child
                    ||$operation['action']!==$row['operation_action']
                    ||$row['uid']!==$roots[$rid]['uid']||$row['mer_id']!==$roots[$rid]['mer_id'])self::unavailable();
                $lastRange[$rid]=$id;$rangeIds[$id]=true;$families[$rid]['ranges'][]=$row;
            }
        }
        foreach($families as$id=>$family){
            $hasHistory=$family['children']!==[]||$family['child_items']!==[]||$family['logs']!==[]
                ||($family['operations']!==null&&$family['operations']!==[])||($family['ranges']!==null&&$family['ranges']!==[]);
            if($hasHistory&&($roots[$id]['paid']===0||($readiness!==null&&$readiness['state']==='SCHEMA_PENDING')))self::unavailable();
        }
        if($readiness!==null&&$this->readStructureReadiness($connection,$pdo)!==$readiness)self::unavailable();
        $this->readStructureConnection($connection,$pdo);
        return ['readiness'=>$readiness,'roots'=>$families];
    }

    /** Only the six fixed full-family SELECT sites above call this helper. */
    private function familySnapshotRows(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($parameters)||count($parameters)>300)self::unavailable();
        $statement=$this->query($pdo,$sql,$parameters);$rows=null;$failure=null;$closed=false;
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->readStructureConnection($connection,$pdo);
        if($failure!==null)throw $failure;
        if($closed!==true||!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();
            foreach($row as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
        }
        return $rows;
    }


    /** Fixed first-root reads. Actual route authorization and transaction ownership belong to the caller. */
    public function readCustomerRootCount(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $filter):int
    {
        self::integer($actualUid,1,self::UINT_MAX);
        return $this->rootReadCount($connection,$pdo,$filter,$actualUid,true);
    }

    public function readCustomerRootWindow(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $filter,array $page,?array $after=null):array
    {
        self::integer($actualUid,1,self::UINT_MAX);
        return $this->rootReadWindow($connection,$pdo,$filter,$page,$after,$actualUid,true);
    }

    public function readPlatformRootCount(ConnectionInterface $connection,PDO $pdo,array $filter):int
    {
        return $this->rootReadCount($connection,$pdo,$filter,null,false);
    }

    public function readPlatformRootWindow(ConnectionInterface $connection,PDO $pdo,array $filter,array $page,?array $after=null):array
    {
        return $this->rootReadWindow($connection,$pdo,$filter,$page,$after,null,false);
    }

    public function readMobileRootCount(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $filter):int
    {
        self::integer($actualUid,1,self::UINT_MAX);
        return $this->rootReadCount($connection,$pdo,$filter,$actualUid,false);
    }

    public function readMobileRootWindow(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $filter,array $page,?array $after=null):array
    {
        self::integer($actualUid,1,self::UINT_MAX);
        return $this->rootReadWindow($connection,$pdo,$filter,$page,$after,$actualUid,false);
    }

    /** Preserve uid|gift_uid and the missing-versus-gift-only error order; no raw row is a DTO or authorization. */
    public function readCustomerRoot(ConnectionInterface $connection,PDO $pdo,int $actualUid,string $locator):?array
    {
        self::integer($actualUid,1,self::UINT_MAX);$this->rootReadLocatorText($locator);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT o.* FROM '.$this->table('store_order').' o WHERE '.
            '(o.order_id=? OR o.'.self::quote('unique').' = ?) AND o.is_del=0 AND (o.uid=? OR o.gift_uid=?) LIMIT 1',
            [$locator,$locator,$actualUid,$actualUid]);
        if($rows===[])return null;
        if(count($rows)!==1)self::unavailable();$this->rootReadHeader($rows[0],false,null);return $rows[0];
    }

    /** The original PK lookup has no list deletion filter. Admin authorization is never inferred here. */
    public function readPlatformRoot(ConnectionInterface $connection,PDO $pdo,int|string $actualPk):?array
    {
        if(is_string($actualPk))$this->rootReadLocatorText($actualPk);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT o.* FROM '.$this->table('store_order').' o WHERE o.id=? LIMIT 1',[(int)$actualPk]);
        if($rows===[])return null;
        if(count($rows)!==1)self::unavailable();$this->rootReadHeader($rows[0],false,null);return $rows[0];
    }

    /** Two original fixed native selections, including their different deletion/owner predicates. */
    public function readMobileRoot(ConnectionInterface $connection,PDO $pdo,int $actualUid,string $locator):array
    {
        self::integer($actualUid,1,self::UINT_MAX);$this->rootReadLocatorText($locator);
        $base=' FROM '.$this->table('store_order').' o WHERE (o.order_id=? OR o.'.self::quote('unique').' = ?)';
        $probeRows=$this->rootSnapshotRows($connection,$pdo,'SELECT o.id,o.marketplace_trade_version'.$base.' LIMIT 1',[$locator,$locator]);
        if(count($probeRows)>1)self::unavailable();$probe=$probeRows[0]??null;$lookupUid=0;
        if($probe!==null){
            self::keys($probe,['id','marketplace_trade_version']);self::integer($probe['id'],1,self::UINT_MAX);
            if(!$this->rootReadLegacy($probe))$lookupUid=$actualUid;
        }
        $sql='SELECT o.*'.$base.' AND o.is_del=0';$parameters=[$locator,$locator];
        if($lookupUid>0){$sql.=' AND (o.uid=? OR o.gift_uid=?)';$parameters[]=$lookupUid;$parameters[]=$lookupUid;}
        $rows=$this->rootSnapshotRows($connection,$pdo,$sql.' LIMIT 1',$parameters);
        if(count($rows)>1)self::unavailable();$root=$rows[0]??null;
        if($root!==null)$this->rootReadHeader($root,false,null);
        return ['probe'=>$probe,'lookup_uid'=>$lookupUid,'root'=>$root];
    }

    private function rootReadCount(ConnectionInterface $connection,PDO $pdo,array $filter,?int $uid,bool $customer):int
    {
        [$where,$parameters]=$this->rootReadPredicate($filter,$uid,$customer);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT COUNT(*) AS aggregate FROM '.$this->table('store_order').' o WHERE '.$where,$parameters);
        if(count($rows)!==1)self::unavailable();self::keys($rows[0],['aggregate']);
        return self::integer($rows[0]['aggregate'],0,self::UINT_MAX);
    }

    private function rootReadWindow(ConnectionInterface $connection,PDO $pdo,array $filter,array $page,?array $after,?int $uid,bool $customer):array
    {
        self::keys($page,['mode','offset','limit']);
        if(!in_array($page['mode'],['all','page'],true)||!is_int($page['offset'])||$page['offset']<0
            ||!is_int($page['limit'])||$page['limit']<1||$page['limit']>100)self::unavailable();
        if($page['mode']==='all'&&($page['offset']!==0||$page['limit']!==100))self::unavailable();
        [$where,$parameters]=$this->rootReadPredicate($filter,$uid,$customer);$previous=null;
        if($after!==null){
            if($page['mode']!=='all')self::unavailable();self::keys($after,['add_time','id']);
            $time=self::integer($after['add_time'],0,self::UINT_MAX);$id=self::integer($after['id'],1,self::UINT_MAX);
            $where.=' AND (o.add_time < ? OR (o.add_time = ? AND o.id < ?))';
            array_push($parameters,$time,$time,$id);$previous=[$time,$id];
        }
        $parameters[]=$page['limit'];$parameters[]=$page['offset'];
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT o.* FROM '.$this->table('store_order').' o WHERE '.$where.
            ' ORDER BY o.add_time DESC,o.id DESC LIMIT ? OFFSET ?',$parameters);
        if(count($rows)>$page['limit'])self::unavailable();$seen=[];
        foreach($rows as$row){
            $id=$this->rootReadHeader($row,true,$uid);$time=self::integer($row['add_time'],0,self::UINT_MAX);
            if(isset($seen[$id])||($previous!==null&&($time>$previous[0]||($time===$previous[0]&&$id>=$previous[1]))))self::unavailable();
            $seen[$id]=true;$previous=[$time,$id];
        }
        return $rows;
    }

    /** Only the eight closed value slots above reach these literal predicates; no identifier or operator input. */
    /** Fixed read-only root predicate shared by native window/count and the existing commercial count boundary. */
    public function commercialStatusPredicate(int $code):string
    {
        $legacy=[
            0=>'o.paid=0 AND o.status=0 AND o.refund_status=0 AND o.is_del=0 AND o.is_cancel=0',
            1=>'o.paid=1 AND o.status=0 AND o.refund_status IN (0,3) AND o.is_del=0',
            7=>'o.paid=1 AND o.status=4 AND o.refund_status IN (0,3) AND o.is_del=0',
            2=>'o.paid=1 AND o.status=1 AND o.refund_status IN (0,3) AND o.is_del=0',
            3=>'o.paid=1 AND o.status=2 AND o.refund_status IN (0,3) AND o.is_del=0',
            4=>'o.paid=1 AND o.status=3 AND o.refund_status IN (0,3) AND o.is_del=0',
            5=>'o.paid=1 AND o.status=0 AND o.refund_status=0 AND o.shipping_type=2 AND o.is_del=0',
            6=>'o.paid=1 AND o.status IN (2,3) AND o.refund_status=0 AND o.shipping_type=2 AND o.is_del=0',
            -1=>'o.paid=1 AND o.refund_status IN (1,4) AND o.is_del=0',
            -2=>'o.paid=1 AND o.refund_status=2 AND o.is_del=0',
            -3=>'o.paid=1 AND o.refund_status IN (1,2,4) AND o.is_del=0',
            -4=>'o.is_del=1',9=>'o.refund_status IN (0,3) AND o.is_del=0'];
        if(!array_key_exists($code,$legacy))self::unavailable();
        $items=$this->table('store_order_cart_info');$movements=$this->table('marketplace_stock_movement');
        $ranges=$this->table('marketplace_fulfillment_range');$operations=$this->table('marketplace_fulfillment_operation');
        $replies=$this->table('store_product_reply');
        $q='(SELECT COALESCE(SUM(qi.cart_num),0) FROM '.$items.' qi WHERE qi.oid=o.id)';
        $c="(SELECT COALESCE(SUM(cm.qty),0) FROM ".$movements." cm WHERE cm.order_id=o.id AND cm.kind='release' AND cm.order_item_id IS NOT NULL)";
        $ship="(SELECT COALESCE(SUM(sr.quantity),0) FROM ".$ranges.' sr JOIN '.$operations.
            " so ON so.id=sr.operation_id AND so.root_order_id=sr.root_order_id AND so.action='ship' AND so.state=1".
            " WHERE sr.root_order_id=o.id AND sr.operation_action='ship')";
        $remaining='('.$q.'-'.$ship.'-'.$c.')';
        $itemCancel="(SELECT COALESCE(SUM(ic.qty),0) FROM ".$movements." ic WHERE ic.order_id=o.id AND ic.order_item_id=ci.id AND ic.kind='release')";
        $itemReceived="(SELECT COALESCE(SUM(rr.quantity),0) FROM ".$ranges.' rr JOIN '.$operations.
            " ro ON ro.id=rr.operation_id AND ro.root_order_id=rr.root_order_id AND ro.action='receive' AND ro.state=1".
            " WHERE rr.root_order_id=o.id AND rr.source_item_id=ci.id AND rr.operation_action='receive')";
        $n='(ci.cart_num-'.$itemCancel.')';
        // Historical receipt remains R after a return. Review moderation/deletion never releases the one-item slot.
        $eligible='EXISTS (SELECT 1 FROM '.$items.' ci WHERE ci.oid=o.id AND '.$n.'>0 AND '.$itemReceived.'='.$n.
            ' AND NOT EXISTS (SELECT 1 FROM '.$replies.' rp WHERE rp.oid=o.id AND BINARY rp.`unique`=BINARY ci.`unique`'.
            ' AND rp.uid=o.uid AND rp.mer_id=o.mer_id AND rp.product_id=ci.product_id))';
        $paid='o.paid=1 AND o.is_cancel=0 AND o.is_del=0';
        $v1=match($code){
            0=>$legacy[0],1=>$paid.' AND '.$remaining.'>0',
            2=>$paid.' AND '.$remaining.'=0 AND o.status=1',
            3=>$paid.' AND '.$eligible,
            4=>$paid.' AND '.$remaining.'=0 AND (('.$ship.'=0 AND o.status=0) OR ('.$ship.'>0 AND o.status=2)) AND NOT '.$eligible,
            -4=>$legacy[-4],9=>$legacy[9],default=>'0=1',
        };
        return '(((o.marketplace_trade_version IS NULL OR o.marketplace_trade_version=0) AND ('.$legacy[$code].'))'.
            ' OR (o.marketplace_trade_version=1 AND o.pid=0 AND ('.$v1.')))';
    }
    private function rootReadPredicate(array $filter,?int $uid,bool $customer):array
    {
        MarketplaceOrderInput::assertReadFilter($filter);
        $where='(o.marketplace_trade_version IS NULL OR o.marketplace_trade_version=0 OR '.
            '(o.marketplace_trade_version=1 AND o.pid=0)) AND o.is_system_del=0';$parameters=[];
        if($uid!==null){
            self::integer($uid,1,self::UINT_MAX);
            $where.=' AND (o.marketplace_trade_version IS NULL OR o.marketplace_trade_version=0 OR '.
                '(o.marketplace_trade_version=1 AND o.uid=?))';$parameters[]=$uid;
            if($customer){$where.=' AND (o.uid=? OR o.gift_uid=?)';$parameters[]=$uid;$parameters[]=$uid;}
        }elseif($customer)self::unavailable();
        if($filter['legacy_pid']==='nonnegative')$where.=' AND o.pid>=0';
        elseif($filter['legacy_pid']==='not_minus_one')$where.=' AND o.pid<>-1';
        if($filter['deleted']['enabled']){$where.=' AND o.is_del=?';$parameters[]=(int)$filter['deleted']['value'];}
        $code=$filter['status_case']['code'];if($code!=='none')$where.=' AND '.$this->commercialStatusPredicate($code);
        if($filter['status_case']['shipping_only'])$where.=' AND o.shipping_type=1';
        $activity=[1=>'o.combination_id=0 AND o.seckill_id=0 AND o.bargain_id=0 AND o.advance_id=0',
            2=>'(o.pink_id>0 OR o.combination_id>0)',3=>'o.seckill_id>0',4=>'o.bargain_id>0',5=>'o.advance_id>0',
            6=>'(o.one_brokerage>0 OR o.two_brokerage>0)'];
        if($filter['activity']!=='none')$where.=' AND ('.$activity[$filter['activity']].')';
        $time=$filter['time'];
        if($time['kind']==='between'){$where.=' AND o.add_time BETWEEN ? AND ?';array_push($parameters,$time['start'],$time['end']);}
        elseif($time['kind']==='before'){$where.=' AND o.add_time < ?';$parameters[]=$time['end'];}
        elseif($time['kind']==='from'){$where.=' AND o.add_time >= ?';$parameters[]=$time['start'];}
        foreach($filter['payment']['equalities']as$value){
            if($value===null)$where.=' AND o.pay_type IS NULL';
            else{$where.=' AND o.pay_type=?';$parameters[]=$value;}
        }
        $values=$filter['payment']['in_values'];
        if($values!==null){
            if($values===[])$values=[''];
            $where.=' AND o.pay_type IN ('.implode(',',array_fill(0,count($values),'?')).')';
            foreach($values as$value)$parameters[]=$value;
        }
        $refund=[1=>'o.refund_type IN (1,2)',2=>'o.refund_type=4',3=>'o.refund_type=5',4=>'o.refund_type=6'];
        if($filter['refund_types']!=='none')$where.=' AND ((o.marketplace_trade_version IS NULL OR o.marketplace_trade_version=0) AND ('.$refund[$filter['refund_types']].'))';
        [$keyword,$keywordParameters]=$this->rootReadKeyword($filter['keyword']);
        if($keyword!==''){$where.=' AND ('.$keyword.')';array_push($parameters,...$keywordParameters);}
        return [$where,$parameters];
    }

    private function rootReadKeyword(array $keyword):array
    {
        $mode=$keyword['mode'];$value=$keyword['value'];if($mode==='none')return ['',[]];
        switch($mode){
            case 'uid':return ['o.uid=?',[(int)$value]];
            case 'order_id':return ['o.order_id=?',[$value]];
            case 'real_name':return ['o.real_name=?',[$value]];
            case 'user_phone':return ['o.user_phone=?',[$value]];
        }
        $like='%'.$value.'%';
        $product='o.id IN (SELECT ci.oid FROM '.$this->table('store_order_cart_info').' ci WHERE '.
            'ci.product_id IN (SELECT p.id FROM '.$this->table('store_product').' p WHERE (p.store_name LIKE ? OR p.keyword LIKE ?)) OR '.
            'ci.product_id IN (SELECT b.id FROM '.$this->table('store_bargain').' b WHERE (b.title LIKE ? OR b.info LIKE ?)) OR '.
            'ci.product_id IN (SELECT c.id FROM '.$this->table('store_combination').' c WHERE (c.title LIKE ? OR c.info LIKE ?)) OR '.
            'ci.product_id IN (SELECT s.id FROM '.$this->table('store_seckill').' s WHERE (s.title LIKE ? OR s.info LIKE ?)))';
        $parameters=array_fill(0,8,$like);
        if($mode==='title')return [$product,$parameters];
        if($mode!=='fuzzy')self::unavailable();
        return ['o.order_id LIKE ? OR o.real_name LIKE ? OR o.user_phone LIKE ? OR '.
            'o.uid IN (SELECT u.uid FROM '.$this->table('user').' u WHERE (u.nickname LIKE ? OR u.uid LIKE ? OR u.phone LIKE ?)) OR '.$product,
            array_merge(array_fill(0,6,$like),$parameters)];
    }

    private function rootReadLegacy(array $row):bool
    {
        if(!array_key_exists('marketplace_trade_version',$row))self::unavailable();$version=$row['marketplace_trade_version'];
        if($version===null||$version===0||$version==='0')return true;
        if($version===1||$version==='1')return false;self::unavailable();
    }

    /** UINT raw legacy headers remain raw; only real V1 rows use the adopted INT contract. */
    private function rootReadHeader(array $row,bool $listing,?int $uid):int
    {
        foreach($row as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        foreach(['id','uid','pid','add_time']as$field)if(!array_key_exists($field,$row))self::unavailable();
        $id=self::integer($row['id'],1,self::UINT_MAX);self::integer($row['uid'],0,self::UINT_MAX);
        $pid=$row['pid'];
        if((!is_int($pid)&&(!is_string($pid)||preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',$pid)!==1))
            ||(int)$pid< -2147483648||(int)$pid>self::INT_MAX)self::unavailable();
        self::integer($row['add_time'],0,self::UINT_MAX);
        if(!$this->rootReadLegacy($row)){
            self::integer($row['id'],1,self::INT_MAX);self::integer($row['uid'],1,self::INT_MAX);
            if($listing){
                self::stored($row,['pid'=>0,'is_system_del'=>0]);
                if($uid!==null)self::stored($row,['uid'=>$uid]);
            }
        }
        return $id;
    }

    private function rootReadLocatorText(string $locator):void
    {
        if(preg_match('//u',$locator)!==1||str_contains($locator,"\0"))self::unavailable();
    }

    /** Only fixed root selectors and display auxiliaries call this native reader; no lifecycle or ORM. */
    private function rootSnapshotRows(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($parameters)||count($parameters)>65535)self::unavailable();
        $statement=$this->query($pdo,$sql,$parameters);$rows=null;$failure=null;$closed=false;
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->readStructureConnection($connection,$pdo);
        if($failure!==null)throw $failure;
        if($closed!==true||!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();
            foreach($row as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
        }
        return $rows;
    }

    /** Full saved child, in the caller's owned snapshot and actual merchant context. */
    public function readMerchantFulfillmentChild(ConnectionInterface $connection,PDO $pdo,int $merId,int $childId):array
    {
        $this->readStructureConnection($connection,$pdo);self::integer($merId,1,self::UINT_MAX);self::integer($childId,1,self::INT_MAX);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').' WHERE id=? AND mer_id=? LIMIT 2',[$childId,$merId]);
        if(count($rows)!==1)throw new \crmeb\exceptions\MerchantNotFoundException();$row=$rows[0];$this->rootReadHeader($row,false,null);
        self::stored($row,['id'=>$childId,'mer_id'=>$merId,'marketplace_trade_version'=>1]);
        if(self::integer($row['pid']??null,1,self::INT_MAX)===$childId)self::unavailable();
        $this->readStructureConnection($connection,$pdo);return $row;
    }

    public function readCustomerFulfillmentChild(ConnectionInterface $connection,PDO $pdo,int $actualUid,string $childNo):array
    {
        $this->readStructureConnection($connection,$pdo);self::integer($actualUid,1,self::INT_MAX);self::text($childNo,32,true);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').
            ' WHERE uid=? AND order_id=? AND marketplace_trade_version=1 AND pid>0 LIMIT 2',[$actualUid,$childNo]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];$this->rootReadHeader($row,false,null);
        self::stored($row,['uid'=>$actualUid,'marketplace_trade_version'=>1]);
        if(self::integer($row['pid']??null,1,self::INT_MAX)===self::integer($row['id']??null,1,self::INT_MAX))self::unavailable();
        $this->readStructureConnection($connection,$pdo);return $row;
    }

    /** The real current account admission used by lockCustomer, without locking or ORM. */
    public function readReceiptCustomerForChild(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $fullChild):array
    {
        $this->readStructureConnection($connection,$pdo);self::integer($actualUid,1,self::INT_MAX);$this->rootReadHeader($fullChild,false,null);
        self::stored($fullChild,['uid'=>$actualUid,'marketplace_trade_version'=>1]);self::integer($fullChild['pid']??null,1,self::INT_MAX);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT uid,status,is_del FROM '.$this->table('user').' WHERE uid=?',[$actualUid]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];self::keys($row,['uid','status','is_del']);
        self::stored($row,['uid'=>$actualUid,'status'=>1,'is_del'=>0]);$this->readStructureConnection($connection,$pdo);return $row;
    }

    /** Fixed V1 display facts only. Authorization, complete item correspondence and lifecycle stay with the caller. */
    public function readDisplayAuxiliariesForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots,string $view):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!in_array($view,['customer_list','platform_list','mobile_list','customer_detail','platform_detail','mobile_detail'],true))self::unavailable();
        $roots=$this->displayAuxRoots($fullAuthorizedRoots);
        $out=['users'=>null,'invoices'=>null,'economize'=>null,'reply_counts'=>null,'stores'=>null];
        if(in_array($view,['platform_list','customer_detail','platform_detail','mobile_detail'],true))
            $out['users']=$this->displayAuxUsers($connection,$pdo,$roots,$view);
        if(in_array($view,['customer_detail','platform_detail','mobile_detail'],true))
            $out['invoices']=$this->displayAuxInvoices($connection,$pdo,$roots);
        if(in_array($view,['customer_detail','mobile_detail'],true))
            $out['economize']=$this->displayAuxEconomize($connection,$pdo,$roots);
        if(in_array($view,['customer_list','customer_detail','platform_detail','mobile_detail'],true))
            $out['reply_counts']=$this->displayAuxReplyCounts($connection,$pdo,$roots);
        if(in_array($view,['customer_detail','mobile_detail'],true))
            $out['stores']=$this->displayAuxStores($connection,$pdo,$roots);
        $this->readStructureConnection($connection,$pdo);return $out;
    }

    private function displayAuxRoots(array $fullAuthorizedRoots):array
    {
        if(!array_is_list($fullAuthorizedRoots)||count($fullAuthorizedRoots)>100)self::unavailable();$roots=[];
        foreach($fullAuthorizedRoots as$root){
            if(!is_array($root))self::unavailable();
            foreach($root as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
            $id=self::integer($root['id']??null,1,self::INT_MAX);
            $uid=self::integer($root['uid']??null,1,self::INT_MAX);$mer=self::integer($root['mer_id']??null,1,self::UINT_MAX);
            self::stored($root,['marketplace_trade_version'=>1,'pid'=>0]);
            $number=self::text($root['order_id']??null,32,true);$signed=[];
            foreach(['pay_uid','store_id']as$field){
                $value=$root[$field]??null;
                if(!is_int($value)&&(!is_string($value)||preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',$value)!==1))self::unavailable();
                $n=(int)$value;if($n< -2147483648||$n>self::INT_MAX)self::unavailable();$signed[$field]=$n;
            }
            if(isset($roots[$id]))self::unavailable();
            $roots[$id]=['uid'=>$uid,'mer_id'=>$mer,'order_id'=>$number,'pay_uid'=>$signed['pay_uid'],'store_id'=>$signed['store_id']];
        }
        ksort($roots,SORT_NUMERIC);return $roots;
    }

    private function displayAuxUsers(ConnectionInterface $connection,PDO $pdo,array $roots,string $view):array
    {
        if($view==='platform_list'){$columns='uid,nickname';$lengths=['nickname'=>240];}
        elseif(in_array($view,['customer_detail','mobile_detail'],true)){$columns='uid,nickname,avatar';$lengths=['nickname'=>240,'avatar'=>1024];}
        elseif($view==='platform_detail'){$columns='uid,real_name';$lengths=['real_name'=>100];}
        else self::unavailable();
        $wanted=[];
        foreach($roots as$root){
            $wanted[$root['uid']]=true;
            if(in_array($view,['customer_detail','mobile_detail'],true)&&$root['pay_uid']!==$root['uid'])$wanted[$root['pay_uid']]=true;
        }
        if($wanted===[])return [];$ids=array_keys($wanted);sort($ids,SORT_NUMERIC);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT '.$columns.' FROM '.$this->table('user').
            ' WHERE uid IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY uid',$ids);
        $last=0;
        foreach($rows as$row){
            self::keys($row,explode(',',$columns));$id=self::integer($row['uid'],1,self::UINT_MAX);
            if(!isset($wanted[$id])||$id<=$last)self::unavailable();$last=$id;
            foreach($lengths as$field=>$max)self::text($row[$field],$max);
        }
        // Absence stays observable; the platform detail caller preserves its original missing-user response.
        return $rows;
    }

    private function displayAuxInvoices(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        if($roots===[])return [];$ids=array_keys($roots);
        $columns='id,uid,order_id,invoice_id,category,header_type,type,name,duty_number,drawer_phone,email,tell,address,bank,card_number,is_pay,is_invoice,remark,invoice_number,invoice_time,add_time';
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT '.$columns.' FROM '.$this->table('store_order_invoice').
            ' WHERE order_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY order_id,id',$ids);
        $seen=[];$lastRoot=0;
        foreach($rows as$row){
            self::keys($row,explode(',',$columns));$id=self::integer($row['id'],1,self::INT_MAX);
            $oid=self::integer($row['order_id'],1,self::INT_MAX);
            if(!isset($roots[$oid])||isset($seen[$id])||$oid<=$lastRoot)self::unavailable();
            self::stored($row,['uid'=>$roots[$oid]['uid']]);self::integer($row['invoice_id'],0,self::INT_MAX);
            foreach(['header_type'=>127,'type'=>127,'is_pay'=>127,'is_invoice'=>127,'invoice_time'=>self::INT_MAX,'add_time'=>self::INT_MAX]as$field=>$max){
                $value=$row[$field];
                if(!is_int($value)&&(!is_string($value)||preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',$value)!==1))self::unavailable();
                $n=(int)$value;if($n< -$max-1||$n>$max)self::unavailable();
            }
            foreach(['category'=>40,'name'=>400,'duty_number'=>200,'drawer_phone'=>120,'email'=>400,'tell'=>120,
                'address'=>1020,'bank'=>200,'card_number'=>200,'remark'=>1020,'invoice_number'=>200]as$field=>$max)self::text($row[$field],$max);
            $seen[$id]=true;$lastRoot=$oid;
        }
        // Raw add_time remains untouched; the pure DTO retains the original model accessor's type-sensitive rule.
        return $rows;
    }

    private function displayAuxEconomize(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        if($roots===[])return [];$numbers=[];$owners=[];
        foreach($roots as$root){
            $key='n:'.$root['order_id'];if(isset($owners[$key]))self::unavailable();
            $numbers[]=$root['order_id'];$owners[$key]=$root['uid'];
        }
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT id,order_id,uid,postage_price,member_price FROM '.$this->table('store_order_economize').
            ' WHERE order_id IN ('.implode(',',array_fill(0,count($numbers),'?')).') ORDER BY order_id,uid,id',$numbers);
        $seenIds=[];$seenNumbers=[];
        foreach($rows as$row){
            self::keys($row,['id','order_id','uid','postage_price','member_price']);
            $id=self::integer($row['id'],1,self::UINT_MAX);$number=self::text($row['order_id'],32,true);$key='n:'.$number;
            if(!isset($owners[$key])||isset($seenIds[$id])||isset($seenNumbers[$key]))self::unavailable();
            self::stored($row,['uid'=>$owners[$key]]);
            foreach(['postage_price','member_price']as$field)
                if(!is_string($row[$field])||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$row[$field])!==1)self::unavailable();
            $seenIds[$id]=true;$seenNumbers[$key]=true;
        }
        return $rows;
    }

    private function displayAuxReplyCounts(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        if($roots===[])return [];$ids=array_keys($roots);
        // The saved source item owns one root/unique slot; moderation or deletion never releases it.
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT ci.id AS item_id,ci.oid AS root_order_id,ci.uid,ci.mer_id,ci.`unique`,COUNT(r.id) AS reply_count,'.
            'SUM(CASE WHEN r.id IS NOT NULL AND (r.uid<>ci.uid OR r.mer_id IS NULL OR r.mer_id<>ci.mer_id OR r.product_id<>ci.product_id) THEN 1 ELSE 0 END) AS invalid_reply_count FROM '.
            '(SELECT id,oid,uid,mer_id,product_id,`unique` FROM '.$this->table('store_order_cart_info').' WHERE oid IN ('.
            implode(',',array_fill(0,count($ids),'?')).')) ci LEFT JOIN '.$this->table('store_product_reply').' r ON r.oid=ci.oid AND BINARY r.`unique`=BINARY ci.`unique`'.
            ' GROUP BY ci.id,ci.oid,ci.uid,ci.mer_id,ci.product_id,ci.`unique` ORDER BY ci.oid,ci.id',$ids);
        $seen=[];$unique=[];$counts=[];$lastRoot=0;$lastItem=0;
        foreach($roots as$id=>$root){$counts[$id]=0;$unique[$id]=[];}
        foreach($rows as&$row){
            self::keys($row,['item_id','root_order_id','uid','mer_id','unique','reply_count','invalid_reply_count']);
            self::integer($row['invalid_reply_count'],0,0);unset($row['invalid_reply_count']);
            $id=self::integer($row['item_id'],1,self::INT_MAX);$oid=self::integer($row['root_order_id'],1,self::INT_MAX);
            if(!isset($roots[$oid])||isset($seen[$id])||$oid<$lastRoot||($oid===$lastRoot&&$id<=$lastItem))self::unavailable();
            self::stored($row,['uid'=>$roots[$oid]['uid'],'mer_id'=>$roots[$oid]['mer_id']]);
            $key='u:'.self::text($row['unique'],128);self::integer($row['reply_count'],0,PHP_INT_MAX);
            if(isset($unique[$oid][$key])||++$counts[$oid]>500)self::unavailable();
            $unique[$oid][$key]=true;$seen[$id]=true;$lastRoot=$oid;$lastItem=$id;
        }
        unset($row);foreach($counts as$count)if($count===0)self::unavailable();
        // No full saved item set is supplied here. The service must compare both complete sets before publication.
        return $rows;
    }

    private function displayAuxStores(ConnectionInterface $connection,PDO $pdo,array $roots):array
    {
        $wanted=[];foreach($roots as$root)if($root['store_id']!==0)$wanted[$root['store_id']]=true;
        if($wanted===[])return [];$ids=array_keys($wanted);sort($ids,SORT_NUMERIC);
        $columns='id,name,phone,address,detailed_address,image,latitude,longitude,day_time';
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT '.$columns.' FROM '.$this->table('system_store').
            ' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',$ids);
        $last=0;
        foreach($rows as$row){
            self::keys($row,explode(',',$columns));$id=self::integer($row['id'],1,self::UINT_MAX);
            if(!isset($wanted[$id])||$id<=$last)self::unavailable();$last=$id;
            foreach(['name'=>400,'phone'=>100,'address'=>1020,'detailed_address'=>1020,'image'=>1020,
                'latitude'=>100,'longitude'=>100,'day_time'=>400]as$field=>$max)self::text($row[$field],$max);
        }
        return $rows;
    }

    /** Complete cross-merchant root items; actual authorization and the owned snapshot belong to the caller. */
    public function readOriginalRootItemsForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($fullAuthorizedRoots)||count($fullAuthorizedRoots)>100)self::unavailable();$items=[];$owners=[];
        foreach($fullAuthorizedRoots as$root){
            if(!is_array($root))self::unavailable();
            foreach($root as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
            $id=self::integer($root['id']??null,1,self::INT_MAX);$uid=self::integer($root['uid']??null,1,self::INT_MAX);
            $mer=self::integer($root['mer_id']??null,1,self::UINT_MAX);
            self::stored($root,['marketplace_trade_version'=>1,'pid'=>0]);self::text($root['order_id']??null,32,true);
            if(isset($items[$id]))self::unavailable();$items[$id]=[];$owners[$id]=['uid'=>$uid,'mer_id'=>$mer];
        }
        if($items===[]){$this->readStructureConnection($connection,$pdo);return [];}
        $ids=array_keys($items);sort($ids,SORT_NUMERIC);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_cart_info').
            ' WHERE oid IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY oid,id',$ids);
        $seen=[];$lastRoot=0;$lastItem=0;
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$oid=self::integer($row['oid']??null,1,self::INT_MAX);
            if(!isset($items[$oid])||isset($seen[$id])||$oid<$lastRoot||($oid===$lastRoot&&$id<=$lastItem))self::unavailable();
            self::stored($row,$owners[$oid]);$items[$oid][]=$row;
            if(count($items[$oid])>500)self::unavailable();$seen[$id]=true;$lastRoot=$oid;$lastItem=$id;
        }
        foreach($items as$list)if($list===[])self::unavailable();
        $this->readStructureConnection($connection,$pdo);return $items;
    }

    /** Preserve every observed display log and its native sequence; no type filter, synthetic ID or ordering. */
    public function readRootDisplayStatusLogs(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($fullAuthorizedRoots)||count($fullAuthorizedRoots)>100)self::unavailable();$roots=[];
        foreach($fullAuthorizedRoots as$root){
            if(!is_array($root))self::unavailable();
            foreach($root as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
            $id=self::integer($root['id']??null,1,self::INT_MAX);self::integer($root['uid']??null,1,self::INT_MAX);
            self::integer($root['mer_id']??null,1,self::UINT_MAX);
            self::stored($root,['marketplace_trade_version'=>1,'pid'=>0]);self::text($root['order_id']??null,32,true);
            if(isset($roots[$id]))self::unavailable();$roots[$id]=true;
        }
        if($roots===[]){$this->readStructureConnection($connection,$pdo);return [];}
        $ids=array_keys($roots);sort($ids,SORT_NUMERIC);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT oid,change_type,change_message,change_time FROM '.$this->table('store_order_status').
            ' WHERE oid IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        foreach($rows as$row){
            self::keys($row,['oid','change_type','change_message','change_time']);$oid=self::integer($row['oid'],1,self::INT_MAX);
            if(!isset($roots[$oid]))self::unavailable();
            self::text($row['change_type'],128);self::text($row['change_message'],1024);self::integer($row['change_time'],0,self::UINT_MAX);
        }
        // The service cross-checks the fulfillment-log multiset against its complete family; repeated logs stay here.
        $this->readStructureConnection($connection,$pdo);return $rows;
    }

    /** Raw committed ship operations only; the caller first validates the whole family/history, then maps this page. */
    public function readFulfillmentChildrenPage(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoot,int $page,int $limit):array
    {
        $this->readStructureConnection($connection,$pdo);
        foreach($fullAuthorizedRoot as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        $id=self::integer($fullAuthorizedRoot['id']??null,1,self::INT_MAX);
        $uid=self::integer($fullAuthorizedRoot['uid']??null,1,self::INT_MAX);$mer=self::integer($fullAuthorizedRoot['mer_id']??null,1,self::UINT_MAX);
        self::stored($fullAuthorizedRoot,['marketplace_trade_version'=>1,'pid'=>0]);self::text($fullAuthorizedRoot['order_id']??null,32,true);
        self::integer($page,1,PHP_INT_MAX);self::integer($limit,1,100);
        if($page-1>intdiv(PHP_INT_MAX,$limit))self::unavailable();$offset=($page-1)*$limit;
        $predicate=' FROM '.$this->table('marketplace_fulfillment_operation').' WHERE root_order_id=? AND action=? AND state=?';
        $parameters=[$id,'ship',1];
        $counts=$this->rootSnapshotRows($connection,$pdo,'SELECT COUNT(*) AS aggregate'.$predicate,$parameters);
        if(count($counts)!==1)self::unavailable();self::keys($counts[0],['aggregate']);
        $count=self::integer($counts[0]['aggregate'],0,PHP_INT_MAX);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT *'.$predicate.' ORDER BY id DESC LIMIT ? OFFSET ?',array_merge($parameters,[$limit,$offset]));
        if(count($rows)!==($offset>=$count?0:min($limit,$count-$offset)))self::unavailable();
        $last=null;$children=[];
        foreach($rows as$row){
            self::keys($row,array_keys(self::newTables()['marketplace_fulfillment_operation']['columns']));$operationId=self::u64($row['id']);
            if($last!==null&&bccomp($operationId,$last,0)>=0)self::unavailable();
            $child=self::integer($row['child_order_id'],1,self::INT_MAX);
            if($child===$id||isset($children[$child]))self::unavailable();
            self::stored($row,['root_order_id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'action'=>'ship','state'=>1]);
            $version=self::integer($row['contract_version'],1,2);
            if(($version===1&&$row['aftersale_cut_id']!==null)||($version===2&&$row['aftersale_cut_id']===null))self::unavailable();
            if($row['aftersale_cut_id']!==null)self::u64($row['aftersale_cut_id']);
            $last=$operationId;$children[$child]=true;
        }
        // JSON/BINARY and native U64 values are not decoded or rewritten. Count/list correspondence to history is the service's job.
        $this->readStructureConnection($connection,$pdo);return ['count'=>$count,'list'=>$rows];
    }

    /** Resolve the actual saved child pid without reselecting that child or adding list deletion predicates. */
    public function readFulfillmentParentForChild(ConnectionInterface $connection,PDO $pdo,array $fullLocatedChild):array
    {
        $this->readStructureConnection($connection,$pdo);
        foreach($fullLocatedChild as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        $child=self::integer($fullLocatedChild['id']??null,1,self::INT_MAX);$parent=self::integer($fullLocatedChild['pid']??null,1,self::INT_MAX);
        $uid=self::integer($fullLocatedChild['uid']??null,1,self::INT_MAX);$mer=self::integer($fullLocatedChild['mer_id']??null,1,self::UINT_MAX);
        self::stored($fullLocatedChild,['marketplace_trade_version'=>1]);self::text($fullLocatedChild['order_id']??null,32,true);
        if($child===$parent)self::unavailable();
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').' WHERE id=?',[$parent]);
        if(count($rows)!==1)self::unavailable();$root=$rows[0];
        self::stored($root,['id'=>$parent,'uid'=>$uid,'mer_id'=>$mer,'marketplace_trade_version'=>1,'pid'=>0]);
        self::text($root['order_id']??null,32,true);
        // Actual child membership in a committed ship is proved later from the full parent facts/history by the caller.
        $this->readStructureConnection($connection,$pdo);return $root;
    }

    /** Platform detail's first buyer observation precedes items/history and children-page normalization. */
    public function readPlatformDetailBuyerForHeader(ConnectionInterface $connection,PDO $pdo,array $fullSelectedV1Header):?array
    {
        $this->readStructureConnection($connection,$pdo);
        foreach($fullSelectedV1Header as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        self::integer($fullSelectedV1Header['id']??null,1,self::INT_MAX);$uid=self::integer($fullSelectedV1Header['uid']??null,1,self::INT_MAX);
        self::integer($fullSelectedV1Header['mer_id']??null,1,self::UINT_MAX);self::integer($fullSelectedV1Header['pid']??null,0,self::INT_MAX);
        self::stored($fullSelectedV1Header,['marketplace_trade_version'=>1]);
        $rows=$this->rootSnapshotRows($connection,$pdo,'SELECT uid,real_name FROM '.$this->table('user').' WHERE uid=?',[$uid]);
        if(count($rows)>1)self::unavailable();
        if($rows===[]){$this->readStructureConnection($connection,$pdo);return null;}
        self::keys($rows[0],['uid','real_name']);self::stored($rows[0],['uid'=>$uid]);self::text($rows[0]['real_name'],100);
        $this->readStructureConnection($connection,$pdo);return $rows[0];
    }

    /** Original merchant predicates, count and saved headers in the same caller-owned native snapshot. */
    public function readMerchantRootPage(ConnectionInterface $connection,PDO $pdo,int $merId,array $filter,int $page,int $limit):array
    {
        $this->readStructureConnection($connection,$pdo);self::integer($merId,1,self::UINT_MAX);
        $input=\app\services\marketplace\order\MerchantOrderInput::listing($filter+['page'=>$page,'limit'=>$limit]);
        if($input['filter']!==$filter||$input['page']!==$page||$input['limit']!==$limit)self::unavailable();
        $where='`marketplace_trade_version`=1 AND `pid`=0 AND `is_system_del`=0 AND `mer_id`=?';$parameters=[$merId];
        foreach($filter as$key=>$value){
            if(!in_array($key,['order_id','paid','is_cancel'],true))self::unavailable();
            $where.=' AND '.self::quote($key).'=?';$parameters[]=$value;
        }
        $rows=$this->merchantSnapshotRows($connection,$pdo,'SELECT COUNT(*) AS aggregate FROM '.$this->table('store_order').' WHERE '.$where,$parameters);
        if(count($rows)!==1)self::unavailable();self::keys($rows[0],['aggregate']);$count=self::integer($rows[0]['aggregate'],0,self::UINT_MAX);
        $rows=$this->merchantSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').' WHERE '.$where.
            ' ORDER BY `id` DESC LIMIT ? OFFSET ?',array_merge($parameters,[$limit,($page-1)*$limit]));
        $seen=[];$last=null;
        foreach($rows as$row){
            $id=$this->merchantReadRoot($row,$merId);
            if(isset($seen[$id])||($last!==null&&$id>=$last))self::unavailable();$seen[$id]=true;$last=$id;
        }
        if(count($rows)>$limit||count($rows)>$count)self::unavailable();
        $this->readStructureConnection($connection,$pdo);return ['list'=>$rows,'count'=>$count];
    }

    public function readMerchantRoot(ConnectionInterface $connection,PDO $pdo,int $merId,int $rootId):array
    {
        self::integer($merId,1,self::UINT_MAX);self::integer($rootId,1,self::INT_MAX);
        $rows=$this->merchantSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').
            ' WHERE `id`=? AND `marketplace_trade_version`=1 AND `pid`=0 AND `is_system_del`=0 AND `mer_id`=? LIMIT 2',[$rootId,$merId]);
        if($rows===[])throw new \crmeb\exceptions\MerchantNotFoundException();
        if(count($rows)!==1||$this->merchantReadRoot($rows[0],$merId)!==$rootId)self::unavailable();return $rows[0];
    }

    /** All original items for the verified root page; do not filter out a broken saved owner. */
    public function readMerchantRootItems(ConnectionInterface $connection,PDO $pdo,int $merId,array $roots):array
    {
        $this->readStructureConnection($connection,$pdo);self::integer($merId,1,self::UINT_MAX);
        if(!array_is_list($roots)||count($roots)>100)self::unavailable();$items=[];$owners=[];
        foreach($roots as$root){
            if(!is_array($root))self::unavailable();$id=$this->merchantReadRoot($root,$merId);
            if(isset($items[$id]))self::unavailable();$items[$id]=[];$owners[$id]=self::integer($root['uid'],1,self::INT_MAX);
        }
        if($items===[]){$this->readStructureConnection($connection,$pdo);return [];}
        $ids=array_keys($items);sort($ids,SORT_NUMERIC);
        $rows=$this->merchantSnapshotRows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_cart_info').
            ' WHERE `oid` IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY `oid`,`id`',$ids);
        $seen=[];$lastOid=0;$lastItem=0;
        foreach($rows as$row){
            $id=self::integer($row['id']??null,1,self::INT_MAX);$oid=self::integer($row['oid']??null,1,self::INT_MAX);
            if(!isset($items[$oid])||isset($seen[$id])||$oid<$lastOid||($oid===$lastOid&&$id<=$lastItem))self::unavailable();
            self::stored($row,['uid'=>$owners[$oid],'mer_id'=>$merId]);$items[$oid][]=$row;
            if(count($items[$oid])>500)self::unavailable();$seen[$id]=true;$lastOid=$oid;$lastItem=$id;
        }
        foreach($items as$rows)if($rows===[])self::unavailable();
        $this->readStructureConnection($connection,$pdo);return $items;
    }

    private function merchantReadRoot(array $root,int $merId):int
    {
        foreach($root as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        self::stored($root,['mer_id'=>$merId,'marketplace_trade_version'=>1,'pid'=>0,'is_system_del'=>0]);
        self::integer($root['uid']??null,1,self::INT_MAX);self::text($root['order_id']??null,32,true);
        return self::integer($root['id']??null,1,self::INT_MAX);
    }

    private function merchantSnapshotRows(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):array
    {
        $this->readStructureConnection($connection,$pdo);
        if(!array_is_list($parameters)||count($parameters)>100)self::unavailable();
        $statement=$this->query($pdo,$sql,$parameters);$rows=null;$failure=null;$closed=false;
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->readStructureConnection($connection,$pdo);
        if($failure!==null)throw $failure;
        if($closed!==true||!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();foreach($row as$value)if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
        }
        return $rows;
    }

    public function merchantCandidate(PDO $pdo,int $id,int $merId):array
    {
        $this->native($pdo,false);
        $rows=$this->select($pdo,'store_order','`id`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version`,`pid`',
            '`id`=? AND `mer_id`=? AND `marketplace_trade_version`=1 AND `pid`=0',[$id,$merId],' LIMIT 2');
        if(count($rows)!==1)self::unavailable();return $this->locator($rows[0]);
    }

    public function customerChildCandidate(PDO $pdo,int $uid,string $number):array
    {
        $this->native($pdo,false);
        $rows=$this->select($pdo,'store_order','`id`,`pid`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version`',
            '`uid`=? AND `order_id`=? AND `marketplace_trade_version`=1 AND `pid`>0',[$uid,$number],' LIMIT 2');
        if(count($rows)!==1)self::unavailable();return $this->childLocator($pdo,$rows[0]);
    }

    public function internalChildCandidate(PDO $pdo,int $id):array
    {
        $this->native($pdo,false);
        $rows=$this->select($pdo,'store_order','`id`,`pid`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version`',
            '`id`=? AND `marketplace_trade_version`=1 AND `pid`>0',[$id],' LIMIT 2');
        if(count($rows)!==1)self::unavailable();return $this->childLocator($pdo,$rows[0]);
    }

    private function childLocator(PDO $pdo,array $row):array
    {
        self::keys($row,['id','pid','uid','mer_id','order_id','marketplace_trade_version']);
        $child=self::integer($row['id'],1,self::INT_MAX);$root=self::integer($row['pid'],1,self::INT_MAX);
        $uid=self::integer($row['uid'],1,self::INT_MAX);$mer=self::integer($row['mer_id'],1,self::UINT_MAX);
        if(self::integer($row['marketplace_trade_version'],1,1)!==1||$child===$root)self::unavailable();
        $parents=$this->select($pdo,'store_order','`id`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version`,`pid`',
            '`id`=? AND `uid`=? AND `mer_id`=? AND `marketplace_trade_version`=1 AND `pid`=0',[$root,$uid,$mer],' LIMIT 2');
        if(count($parents)!==1)self::unavailable();
        return $this->locator($parents[0])+['child_order_id'=>$child,'child_order_no'=>self::text($row['order_id'],32,true)];
    }

    private function locator(array $row):array
    {
        self::keys($row,['id','uid','mer_id','order_id','marketplace_trade_version','pid']);
        if(self::integer($row['marketplace_trade_version'],1,1)!==1||self::integer($row['pid'],0,0)!==0)self::unavailable();
        return ['root_order_id'=>self::integer($row['id'],1,self::INT_MAX),'root_order_no'=>self::text($row['order_id'],32,true),
            'uid'=>self::integer($row['uid'],1,self::INT_MAX),'mer_id'=>self::integer($row['mer_id'],1,self::UINT_MAX)];
    }

    public function lockRoot(PDO $pdo,array $candidate):array
    {
        $this->native($pdo,true);
        $root=$this->child($pdo,$candidate['root_order_id']);
        if(self::integer($root['id']??null,1,self::INT_MAX)!==$candidate['root_order_id']
            ||self::integer($root['uid']??null,1,self::INT_MAX)!==$candidate['uid']
            ||self::integer($root['mer_id']??null,1,self::UINT_MAX)!==$candidate['mer_id']
            ||($root['order_id']??null)!==$candidate['root_order_no']
            ||self::integer($root['pid']??null,0,0)!==0||self::integer($root['marketplace_trade_version']??null,1,1)!==1)self::unavailable();
        return $root;
    }

    public function lockCustomer(PDO $pdo,int $uid):void
    {
        $this->native($pdo,true);
        $rows=$this->select($pdo,'user','`uid`,`status`,`is_del`','`uid`=?',[$uid],' FOR UPDATE');
        if(count($rows)!==1||self::integer($rows[0]['uid'],1,self::INT_MAX)!==$uid
            ||self::integer($rows[0]['status'],1,1)!==1||self::integer($rows[0]['is_del'],0,0)!==0)self::unavailable();
    }

    public function lockOperations(PDO $pdo,int $rootId):array
    {
        $this->native($pdo,true);
        return array_map([self::class,'operation'],$this->select($pdo,'marketplace_fulfillment_operation','*',
            '`root_order_id`=?',[$rootId],' ORDER BY `id` FOR UPDATE'));
    }

    /** Complete children/items/ranges/refunds in the adopted order, without pages. */
    public function lockFamily(PDO $pdo,array $root):array
    {
        $this->native($pdo,true);$id=self::integer($root['id'],1,self::INT_MAX);
        $children=$this->select($pdo,'store_order','*','`pid`=?',[$id],' ORDER BY `id` FOR UPDATE');
        $ids=[$id];foreach($children as$row)$ids[]=self::integer($row['id']??null,1,self::INT_MAX);
        $childItems=count($ids)===1?[]:$this->select($pdo,'store_order_cart_info','*',
            '`oid` IN ('.implode(',',array_fill(0,count($ids)-1,'?')).')',array_slice($ids,1),' ORDER BY `oid`,`id` FOR UPDATE');
        $ranges=array_map([self::class,'rangeRow'],$this->select($pdo,'marketplace_fulfillment_range','*',
            '`root_order_id`=?',[$id],' ORDER BY `id` FOR UPDATE'));
        // Raw compatibility set: the pure Context decides legacy NULLs versus complete Task7 mirrors.
        $refunds=$this->select($pdo,'store_order_refund','*','(`store_order_id` IN ('.implode(',',array_fill(0,count($ids),'?')).
            ') OR `marketplace_root_order_id`=?)',array_merge($ids,[$id]),' ORDER BY `id` FOR UPDATE');
        return ['children'=>$children,'items'=>$childItems,'ranges'=>$ranges,'refunds'=>$refunds];
    }
    public function lockStatusLogs(PDO $pdo,int $rootId):array
    {
        $this->native($pdo,true);
        return $this->select($pdo,'store_order_status','*',
            "`oid`=? AND `change_type` IN ('marketplace_fulfillment_ship','marketplace_fulfillment_receive')",
            [$rootId],' FOR UPDATE');
    }

    public function insertChild(PDO $pdo,array $data):int
    {
        $this->native($pdo,true);return self::integer($this->insert($pdo,'store_order',$data),1,self::INT_MAX);
    }
    public function insertChildItem(PDO $pdo,array $data):int
    {
        $this->native($pdo,true);return self::integer($this->insert($pdo,'store_order_cart_info',$data),1,self::INT_MAX);
    }
    public function insertOperation(PDO $pdo,array $data):string
    {
        $this->native($pdo,true);return self::u64($this->insert($pdo,'marketplace_fulfillment_operation',$data));
    }
    public function insertRange(PDO $pdo,array $data):string
    {
        $this->native($pdo,true);return self::u64($this->insert($pdo,'marketplace_fulfillment_range',$data));
    }

    public function finishOperation(PDO $pdo,string $id,array $result,int $now):void
    {
        $this->native($pdo,true);$json=self::json($result,1048576);
        if($this->query($pdo,'UPDATE '.$this->table('marketplace_fulfillment_operation').
            ' SET `state`=1,`result_payload`=?,`committed_at`=? WHERE `id`=? AND `state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL',
            [$json,$now,self::u64($id)])->rowCount()!==1)self::unavailable();
    }

    public function child(PDO $pdo,int $id):array
    {
        $this->native($pdo,true);$rows=$this->select($pdo,'store_order','*','`id`=?',[$id],' FOR UPDATE');
        if(count($rows)!==1)self::unavailable();return $rows[0];
    }

    public function status(PDO $pdo,array $before,int $status):array
    {
        $this->native($pdo,true);$old=self::integer($before['status']??null,0,2);
        if(!in_array($status,[0,1,2],true))self::unavailable();$id=self::integer($before['id'],1,self::INT_MAX);
        if($status!==$old&&$this->query($pdo,'UPDATE '.$this->table('store_order').
            ' SET `status`=? WHERE `id`=? AND `uid`=? AND `mer_id`=? AND `marketplace_trade_version`=1 AND `pid`=? AND `status`=?',
            [$status,$id,self::integer($before['uid'],1,self::INT_MAX),self::integer($before['mer_id'],1,self::UINT_MAX),
                self::integer($before['pid'],0,self::INT_MAX),$old])->rowCount()!==1)self::unavailable();
        $after=$this->child($pdo,$id);$expected=$before;
        $expected['status']=is_int($before['status'])?$status:(string)$status;
        if($after!==$expected)self::unavailable();return $after;
    }

    public function statusLog(PDO $pdo,int $rootId,string $action,int $at):void
    {
        $this->native($pdo,true);
        $type=$action==='ship'?'marketplace_fulfillment_ship':($action==='receive'?'marketplace_fulfillment_receive':'');
        if($type==='')self::unavailable();
        $message=$action==='ship'?'多商户履约发货状态更新':'多商户履约收货状态更新';
        $data=['oid'=>$rootId,'change_type'=>$type,'change_message'=>$message,'change_time'=>$at];
        $where='`oid`=? AND `change_type`=? AND `change_message`=? AND `change_time`=?';
        $before=$this->select($pdo,'store_order_status','*',$where,array_values($data),' FOR UPDATE');
        $this->insertWithoutId($pdo,'store_order_status',$data);
        $after=$this->select($pdo,'store_order_status','*',$where,array_values($data),' FOR UPDATE');
        if(count($after)!==count($before)+1)self::unavailable();
        foreach($after as$row)self::stored($row,$data);
    }

    public function dueCandidates(PDO $pdo,int $dueCursor,string $shipCursor,int $now,int $batch):array
    {
        $this->native($pdo,false);self::integer($dueCursor,0,self::UINT_MAX);self::u64($shipCursor,true);
        self::integer($now,1,self::UINT_MAX);if($batch<1||$batch>100)self::unavailable();
        $rows=$this->query($pdo,'SELECT fo.`id`,fo.`root_order_id`,fo.`child_order_id`,fo.`uid`,fo.`mer_id`,fo.`receipt_due_at` FROM '.
            $this->table('marketplace_fulfillment_operation').' fo FORCE INDEX (`idx_fop_due`)'.
            " WHERE fo.`action`='ship' AND fo.`state`=1 AND fo.`receipt_due_at`>0 AND fo.`receipt_due_at`<=?".
            ' AND EXISTS (SELECT 1 FROM '.$this->table('store_order').' c WHERE c.id=fo.child_order_id AND c.status=1)'.
            ' AND (fo.`receipt_due_at`>? OR (fo.`receipt_due_at`=? AND fo.`id`>?)) ORDER BY fo.`receipt_due_at`,fo.`id` LIMIT '.$batch,
            [$now,$dueCursor,$dueCursor,$shipCursor])->fetchAll(PDO::FETCH_ASSOC);
        $out=[];foreach($rows as$row){
            self::keys($row,['id','root_order_id','child_order_id','uid','mer_id','receipt_due_at']);
            $out[]=['ship_operation_id'=>self::u64($row['id']),'root_order_id'=>self::integer($row['root_order_id'],1,self::INT_MAX),
                'child_order_id'=>self::integer($row['child_order_id'],1,self::INT_MAX),'uid'=>self::integer($row['uid'],1,self::INT_MAX),
                'mer_id'=>self::integer($row['mer_id'],1,self::UINT_MAX),'due_at'=>self::integer($row['receipt_due_at'],1,$now)];
        }return $out;
    }

    public static function operation(array $row):array
    {
        self::keys($row,array_keys(self::newTables()['marketplace_fulfillment_operation']['columns']));
        foreach(['id','target_operation_id','aftersale_cut_id'] as$key)$row[$key]=$row[$key]===null?null:self::u64($row[$key]);
        foreach(['contract_version','root_order_id','child_order_id','uid','mer_id','state','ship_child_order_id','actor_id',
            'created_at','committed_at','shipped_at','receipt_due_at','received_at'] as$key)
            $row[$key]=$row[$key]===null?null:self::integer($row[$key],0,in_array($key,['root_order_id','child_order_id','uid','ship_child_order_id'],true)?self::INT_MAX:self::UINT_MAX);
        if(!in_array($row['contract_version'],[1,2],true)
            ||($row['contract_version']===1&&$row['aftersale_cut_id']!==null)
            ||($row['contract_version']===2&&$row['aftersale_cut_id']===null))self::unavailable();
        foreach(['request_digest','source_snapshot_digest','allocation_digest'] as$key)
            if(!is_string($row[$key])||strlen($row[$key])!==32)self::unavailable();
        $row['request_payload']=self::decode($row['request_payload'],65536);
        $row['result_payload']=$row['result_payload']===null?null:self::decode($row['result_payload'],1048576);
        return $row;
    }

    public static function rangeRow(array $row):array
    {
        self::keys($row,array_keys(self::newTables()['marketplace_fulfillment_range']['columns']));
        foreach(['id','operation_id','reserve_id','source_ship_range_id'] as$key)$row[$key]=$row[$key]===null?null:self::u64($row[$key]);
        foreach(['root_order_id','child_order_id','uid','mer_id','source_item_id','child_item_id','product_id','sku_id','sku_type',
            'original_quantity','unit_start','unit_end','quantity'] as$key)
            $row[$key]=self::integer($row[$key],0,$key==='mer_id'?self::UINT_MAX:self::INT_MAX);
        return $row;
    }

    public static function integer(mixed $value,int $min=0,int $max=self::INT_MAX):int
    {
        if(is_int($value))$n=$value;
        elseif(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$max,0)<=0)$n=(int)$value;
        else self::unavailable();
        if($n<$min||$n>$max)self::unavailable();return $n;
    }

    public static function u64(mixed $value,bool $zero=false):string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1
            ||bccomp($value,'18446744073709551615',0)>0||(!$zero&&$value==='0'))self::unavailable();return $value;
    }

    public static function text(mixed $value,int $max,bool $required=false):string
    {
        if(!is_string($value)||strlen($value)>$max||($required&&$value==='')||str_contains($value,"\0")||preg_match('//u',$value)!==1)self::unavailable();return $value;
    }

    public static function keys(array $value,array $keys):void
    {
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);if($actual!==$keys)self::unavailable();
    }

    public static function json(array $value,int $max):string
    {
        $json=MarketplaceOrderInput::canonical($value);if(strlen($json)>$max)self::unavailable();return $json;
    }

    public static function decode(mixed $raw,int $max):array
    {
        if(!is_string($raw)||strlen($raw)>$max)self::unavailable();
        try{$value=json_decode($raw,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $error){self::unavailable();}
        if(!is_array($value))self::unavailable();self::json($value,$max);return $value;
    }

    public static function stored(array $actual,array $expected):void
    {
        foreach($expected as$key=>$value){
            if(!array_key_exists($key,$actual))self::unavailable();
            if(is_int($value)){if(self::integer($actual[$key],0,self::UINT_MAX)!==$value)self::unavailable();}
            elseif($actual[$key]!==$value)self::unavailable();
        }
    }

    private function native(PDO $pdo,bool $transaction):void
    {
        if(PHP_INT_SIZE<8||Db::connect()->getPdo()!==$pdo||Db::connect()->getConfig('prefix')!==$this->prefix||$pdo->inTransaction()!==$transaction
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }

    private function select(PDO $pdo,string $table,string $columns,string $where,array $parameters,string $tail=''):array
    {
        return $this->query($pdo,'SELECT '.$columns.' FROM '.$this->table($table).' WHERE '.$where.$tail,$parameters)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function query(PDO $pdo,string $sql,array $parameters=[]):PDOStatement
    {
        $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
        foreach(array_values($parameters) as$i=>$value){
            if(!is_int($value)&&!is_string($value)&&$value!==null)self::unavailable();
            if(!$statement->bindValue($i+1,$value,$value===null?PDO::PARAM_NULL:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)))self::unavailable();
        }
        if(!$statement->execute())self::unavailable();return $statement;
    }

    private function insert(PDO $pdo,string $table,array $data):string
    {
        $this->insertWithoutId($pdo,$table,$data);return self::u64($pdo->lastInsertId());
    }

    private function insertWithoutId(PDO $pdo,string $table,array $data):void
    {
        if($data===[])self::unavailable();
        if($this->query($pdo,'INSERT INTO '.$this->table($table).' ('.implode(',',array_map([self::class,'quote'],array_keys($data))).
            ') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data))->rowCount()!==1)self::unavailable();
    }

    private function table(string $name):string
    {
        if(!in_array($name,self::TABLES,true))self::unavailable();return self::quote($this->prefix.$name);
    }
    public static function quote(string $name):string
    {
        if($name===''||strlen($name)>64||preg_match('/\A[A-Za-z0-9_]+\z/D',$name)!==1)self::unavailable();return '`'.$name.'`';
    }
    private function schemaRows(string $sql,array $parameters=[]):array
    {
        if(!$this->schemaPdo instanceof PDO)self::unavailable();return $this->query($this->schemaPdo,$sql,$parameters)->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function unavailable():never{throw new AuthUnavailableException();}

    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** Final Task7 operation25/range30; all original constraints plus the one exact adopted cut upgrade. */
    public static function newTables(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        return [
            'marketplace_fulfillment_operation' => [
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', false, '1'),
                    'root_order_id' => $c('int unsigned'),
                    'child_order_id' => $c('int unsigned'),
                    'uid' => $c('int unsigned'),
                    'mer_id' => $c('int unsigned'),
                    'action' => $c('varchar(16)', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)'),
                    'source_snapshot_digest' => $c('binary(32)'),
                    'allocation_digest' => $c('binary(32)'),
                    'request_payload' => $c('json'),
                    'result_payload' => $c('json', true),
                    'state' => $c('tinyint unsigned', false, '0'),
                    'ship_child_order_id' => $c('int unsigned', true),
                    'target_operation_id' => $c('bigint unsigned', true),
                    'target_action' => $c('varchar(16)', true, null, 'ascii'),
                    'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', true),
                    'created_at' => $c('int unsigned'),
                    'committed_at' => $c('int unsigned', true),
                    'shipped_at' => $c('int unsigned', true),
                    'receipt_due_at' => $c('int unsigned', true),
                    'received_at' => $c('int unsigned', true),
                    'aftersale_cut_id' => $c('bigint unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_fop_request' => [true, ['root_order_id', 'action', 'idem_key']],
                    'uq_fop_ship_child' => [true, ['ship_child_order_id']],
                    'uq_fop_identity' => [true, ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_fop_root' => [false, ['root_order_id', 'id']],
                    'idx_fop_child' => [false, ['child_order_id', 'action', 'id']],
                    'idx_fop_target' => [false, ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_fop_owner' => [false, ['uid', 'root_order_id', 'id']],
                    'idx_fop_merchant' => [false, ['mer_id', 'root_order_id', 'id']],
                    'idx_fop_due' => [false, ['action', 'state', 'receipt_due_at', 'id']],
                    'idx_fop_aftersale_cut' => [false, ['aftersale_cut_id']],
                ],
                'checks' => [
                    'mm4f_op_ids' => self::truth('`contract_version` IN (1,2) AND `root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `root_order_id`<>`child_order_id`'),
                    'mm4a_op_cut' => self::truth('(`contract_version`=1 AND `aftersale_cut_id` IS NULL) OR (`contract_version`=2 AND `aftersale_cut_id` IS NOT NULL AND `aftersale_cut_id`>0)'),
                    'mm4f_op_state' => '(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)',
                    'mm4f_op_json' => "JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR JSON_TYPE(`result_payload`)='OBJECT')",
                    'mm4f_op_action' => "`created_at`>0 AND ((`action`='ship' AND `ship_child_order_id` IS NOT NULL AND `ship_child_order_id`=`child_order_id` AND `target_operation_id` IS NULL AND `target_action` IS NULL AND `shipped_at` IS NOT NULL AND `shipped_at`>0 AND `receipt_due_at` IS NOT NULL AND (`receipt_due_at`=0 OR `receipt_due_at`>=`shipped_at`) AND `received_at` IS NULL) OR (`action`='receive' AND `ship_child_order_id` IS NULL AND `target_operation_id` IS NOT NULL AND `target_operation_id`>0 AND `target_action` IS NOT NULL AND `target_action`='ship' AND `shipped_at` IS NULL AND `receipt_due_at` IS NULL AND `received_at` IS NOT NULL AND `received_at`>0))",
                    'mm4f_op_actor' => "(`action`='ship' AND `actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0) OR (`action`='receive' AND ((`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid`) OR (`actor_type`='system' AND `actor_id` IS NULL)))",
                ],
                'foreign' => [
                    'mm4f_op_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4f_op_child_fk' => ['store_order', ['child_order_id'], ['id'], 'PRIMARY'],
                    'mm4f_op_user_fk' => ['user', ['uid'], ['uid'], 'PRIMARY'],
                    'mm4f_op_mer_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id'], 'PRIMARY'],
                    'mm4a_fop_cut_fk' => ['marketplace_aftersale_action', ['aftersale_cut_id'], ['id'], 'PRIMARY'],
                    'mm4f_op_target_fk' => ['marketplace_fulfillment_operation', ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                ],
            ],
            'marketplace_fulfillment_range' => [
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'operation_id' => $c('bigint unsigned'),
                    'operation_action' => $c('varchar(16)', false, null, 'ascii'),
                    'root_order_id' => $c('int unsigned'),
                    'child_order_id' => $c('int unsigned'),
                    'uid' => $c('int unsigned'),
                    'mer_id' => $c('int unsigned'),
                    'source_item_id' => $c('int'),
                    'child_item_id' => $c('int'),
                    'reserve_id' => $c('bigint unsigned'),
                    'source_ship_range_id' => $c('bigint unsigned', true),
                    'source_range_action' => $c('varchar(16)', true, null, 'ascii'),
                    'product_id' => $c('mediumint'),
                    'sku_id' => $c('int'),
                    'sku_type' => $c('tinyint unsigned', false, '0'),
                    'sku_unique' => $c('char(8)', false, null, 'ascii'),
                    'cart_ref' => $c('varchar(50)', false, null, 'ascii'),
                    'original_quantity' => $c('int unsigned'),
                    'unit_start' => $c('int unsigned'),
                    'unit_end' => $c('int unsigned'),
                    'quantity' => $c('int unsigned'),
                    'unit_price' => $c('decimal(12,2) unsigned'),
                    'price_type' => $c('varchar(16)', false, null, 'ascii'),
                    'goods' => $c('decimal(12,2) unsigned'),
                    'postage' => $c('decimal(12,2) unsigned'),
                    'origin_postage' => $c('decimal(12,2) unsigned'),
                    'payable' => $c('decimal(12,2) unsigned'),
                    'cost' => $c('decimal(12,2) unsigned'),
                    'base_goods' => $c('decimal(22,2) unsigned'),
                    'discount' => $c('decimal(22,2) unsigned'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_frange_operation_start' => [true, ['operation_id', 'source_item_id', 'unit_start']],
                    'uq_frange_identity' => [true, ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                    'idx_frange_operation' => [false, ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_frange_occupied' => [false, ['root_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                    'idx_frange_child' => [false, ['child_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                    'idx_frange_source_item' => [false, ['source_item_id', 'id']],
                    'idx_frange_child_item' => [false, ['child_item_id', 'id']],
                    'idx_frange_reserve' => [false, ['reserve_id', 'id']],
                    'idx_frange_ship_parent' => [false, ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                ],
                'checks' => [
                    'mm4f_range_ids' => '`root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `source_item_id`>0 AND `child_item_id`>0 AND `reserve_id`>0 AND `product_id`>0 AND `sku_id`>0 AND `sku_type`=0',
                    'mm4f_range_units' => '`original_quantity` BETWEEN 1 AND 2147483647 AND `unit_start`<`unit_end` AND `unit_end`<=`original_quantity` AND `quantity`=`unit_end`-`unit_start`',
                    'mm4f_range_action' => "(`operation_action`='ship' AND `source_ship_range_id` IS NULL AND `source_range_action` IS NULL) OR (`operation_action`='receive' AND `source_ship_range_id` IS NOT NULL AND `source_ship_range_id`>0 AND `source_range_action` IS NOT NULL AND `source_range_action`='ship')",
                    'mm4f_range_money' => "`price_type` IN ('level','member') AND `payable`=`goods`+`postage` AND `origin_postage`=`postage`",
                ],
                'foreign' => [
                    'mm4f_range_operation_fk' => ['marketplace_fulfillment_operation', ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                    'mm4f_range_source_fk' => ['store_order_cart_info', ['source_item_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_child_fk' => ['store_order_cart_info', ['child_item_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_reserve_fk' => ['marketplace_stock_movement', ['reserve_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_parent_fk' => ['marketplace_fulfillment_range', ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], 'uq_frange_identity'],
                ],
            ],
        ];
    }

    /** Literal final stage20 of the adopted migration; no CLI load or DDL. */
    public static function aftersaleTables():array
    {
        $new=self::aftersaleNewTables();$tables=self::newTables();
        foreach($tables as&$table){$table['table_collation']='utf8mb4_bin';$table['table_comment']=self::MARKER;}unset($table);
        $refund=self::aftersaleRefundBase();$add=self::aftersaleRefundAdditions();
        foreach(['columns','indexes','foreign','checks'] as$kind)foreach($add[$kind] as$name=>$definition)$refund[$kind][$name]=$definition;
        return ['marketplace_aftersale_action'=>$new['marketplace_aftersale_action'],'store_order_refund'=>$refund]
            +$tables+['marketplace_aftersale_file'=>$new['marketplace_aftersale_file']];
    }

    private static function truth(string $predicate):string{return 'COALESCE(('.$predicate.'),0)=1';}
    private static function aftersaleNewTables(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $actor = "(`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid` AND `actor_ref`=CONCAT('customer:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('employee:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='admin' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('admin:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='payment' AND `actor_id` IS NULL AND `actor_ref`=CONCAT('payment:',`payment_attempt_id`) AND `action`='finance_observe')";
        $unbound = '`refund_id` IS NULL AND `image_position` IS NULL AND `claimed_at` IS NULL';
        return [
            'marketplace_aftersale_action' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::AFTERSALE_MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', false, '1'),
                    'root_order_id' => $c('int unsigned'), 'uid' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int'), 'payment_attempt_id' => $c('bigint unsigned'),
                    'action' => $c('varchar(24)', false, null, 'ascii'), 'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', true), 'actor_ref' => $c('varchar(64)', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', false, null, 'ascii'), 'request_digest' => $c('binary(32)'),
                    'request_payload' => $c('json'), 'result_payload' => $c('json', true), 'state' => $c('tinyint unsigned', false, '0'),
                    'previous_refund_action_id' => $c('bigint unsigned', true), 'fulfillment_cut_id' => $c('bigint unsigned', true),
                    'apply_refund_id' => $c('int', true), 'created_at' => $c('int unsigned'), 'committed_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4a_actor_key' => [true, ['actor_ref', 'idem_key']],
                    'uq_ms4a_apply_refund' => [true, ['apply_refund_id']], 'uq_ms4a_previous' => [true, ['previous_refund_action_id']],
                    'idx_ms4a_root' => [false, ['root_order_id', 'id']], 'idx_ms4a_refund' => [false, ['refund_id', 'id']],
                    'idx_ms4a_payment' => [false, ['payment_attempt_id']], 'idx_ms4a_fcut' => [false, ['fulfillment_cut_id']],
                ],
                'foreign' => [
                    'mm4a_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4a_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                    'mm4a_payment_fk' => ['marketplace_payment_attempt', ['payment_attempt_id'], ['id'], 'PRIMARY'],
                    'mm4a_previous_fk' => ['marketplace_aftersale_action', ['previous_refund_action_id'], ['id'], 'PRIMARY'],
                    'mm4a_fcut_fk' => ['marketplace_fulfillment_operation', ['fulfillment_cut_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4a_ids' => self::truth('`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `refund_id`>0 AND `payment_attempt_id`>0 AND `created_at`>0 AND (`previous_refund_action_id` IS NULL OR (`previous_refund_action_id` IS NOT NULL AND `previous_refund_action_id`>0)) AND (`fulfillment_cut_id` IS NULL OR (`fulfillment_cut_id` IS NOT NULL AND `fulfillment_cut_id`>0))'),
                    'mm4a_actor' => self::truth($actor),
                    'mm4a_action' => self::truth("`action` IN ('apply','withdraw','approve','reject','express','accept_return','finance_observe') AND ((`action`='apply' AND `apply_refund_id` IS NOT NULL AND `apply_refund_id`=`refund_id` AND `previous_refund_action_id` IS NULL) OR (`action`<>'apply' AND `apply_refund_id` IS NULL AND `previous_refund_action_id` IS NOT NULL))"),
                    'mm4a_state' => self::truth('(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)'),
                    'mm4a_json' => self::truth("JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR (JSON_TYPE(`result_payload`)='OBJECT'))"),
                ],
            ],
            'marketplace_aftersale_file' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::AFTERSALE_FILE_MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'uid' => $c('int unsigned'), 'root_order_id' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int', true), 'image_position' => $c('tinyint unsigned', true),
                    'object_key' => $c('char(64)', false, null, 'ascii'), 'media_type' => $c('varchar(32)', false, null, 'ascii'),
                    'byte_size' => $c('int unsigned'), 'width' => $c('smallint unsigned'), 'height' => $c('smallint unsigned'),
                    'content_sha256' => $c('binary(32)'), 'upload_key' => $c('varchar(128)', false, null, 'ascii'),
                    'state' => $c('varchar(16)', false, null, 'ascii'), 'created_at' => $c('int unsigned'),
                    'claim_expires_at' => $c('int unsigned'), 'claimed_at' => $c('int unsigned', true), 'purged_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4af_object_key' => [true, ['object_key']],
                    'uq_ms4af_upload' => [true, ['uid', 'upload_key']], 'uq_ms4af_refund_position' => [true, ['refund_id', 'image_position']],
                    'idx_ms4af_root' => [false, ['root_order_id', 'id']], 'idx_ms4af_expiry' => [false, ['state', 'claim_expires_at', 'id']],
                ],
                'foreign' => [
                    'mm4af_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4af_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4af_values' => self::truth("`uid` BETWEEN 1 AND 2147483647 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `byte_size` BETWEEN 1 AND 5242880 AND `width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096 AND `media_type` IN ('image/jpeg','image/png','image/webp') AND OCTET_LENGTH(`content_sha256`)=32 AND OCTET_LENGTH(`object_key`)=64 AND REGEXP_LIKE(`object_key`,'[^0-9a-f]','c')=0 AND OCTET_LENGTH(`upload_key`) BETWEEN 1 AND 128 AND REGEXP_LIKE(`upload_key`,'^[!-~]([ -~]{0,126}[!-~])?$','c')=1 AND REGEXP_LIKE(`upload_key`,'[^ -~]','c')=0 AND `created_at` BETWEEN 1 AND 4294880895 AND `claim_expires_at`=`created_at`+86400"),
                    'mm4af_state' => self::truth("(`state`='STAGED' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='CLAIMED' AND `refund_id` IS NOT NULL AND `refund_id`>0 AND `image_position` IS NOT NULL AND `image_position` BETWEEN 0 AND 8 AND `claimed_at` IS NOT NULL AND `claimed_at`>=`created_at` AND `claimed_at`<=`claim_expires_at` AND `purged_at` IS NULL) OR (`state`='DELETE_PENDING' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='DELETED' AND " . $unbound . " AND `purged_at` IS NOT NULL AND `purged_at`>=`created_at`)"),
                ],
            ],
        ];
    }

    private static function aftersaleRefundAdditions(): array
    {
        $c = static fn(string $type, ?string $charset = null): array => self::column($type, '', true, null, $charset);
        $legacy = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'] as $name) $legacy[] = self::quote($name) . ' IS NULL';
        $nonnull = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id','mer_id','marketplace_refund_mode'] as $name) $nonnull[] = self::quote($name) . ' IS NOT NULL';
        $valid = implode(' AND ', $nonnull) . ' AND `marketplace_root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `marketplace_payment_attempt_id`>0 AND `marketplace_apply_action_id`>0 AND `marketplace_last_action_id`>=`marketplace_apply_action_id` AND `mer_id`>0 AND `refund_price`>0 AND `refunded_price`>=0 AND `refunded_price`<=`refund_price`'
            . " AND ((`marketplace_kind`='money_only' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='return_goods' AND `marketplace_refund_mode`='return_goods'))"
            . " AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED')"
            . " AND ((`marketplace_state`='REQUESTED' AND `refunded_price`=0 AND `marketplace_finance_state`='NOT_SUBMITTED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCEL_PENDING') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_PENDING')))"
            . " OR (`marketplace_state`='APPROVED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state` IN ('RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'))))"
            . " OR (`marketplace_state` IN ('WITHDRAWN','REJECTED') AND `refunded_price`=0 AND `marketplace_physical_state`='NONE' AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED'))"
            . " OR (`marketplace_state`='COMPLETED' AND `refunded_price`=`refund_price` AND `marketplace_finance_state`='SUCCEEDED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_ACCEPTED'))))";
        return [
            'columns' => [
                'marketplace_root_order_id' => $c('int unsigned'), 'marketplace_payment_attempt_id' => $c('bigint unsigned'),
                'marketplace_kind' => $c('varchar(24)', 'ascii'), 'marketplace_state' => $c('varchar(16)', 'ascii'),
                'marketplace_physical_state' => $c('varchar(24)', 'ascii'), 'marketplace_finance_state' => $c('varchar(16)', 'ascii'),
                'marketplace_apply_action_id' => $c('bigint unsigned'), 'marketplace_last_action_id' => $c('bigint unsigned'),
            ],
            'indexes' => ['idx_ms4a_refund_root' => [false, ['marketplace_root_order_id','id']],
                'uq_ms4a_refund_apply' => [true, ['marketplace_apply_action_id']], 'idx_ms4a_refund_last' => [false, ['marketplace_last_action_id']]],
            'foreign' => ['mm4a_refund_apply_fk' => ['marketplace_aftersale_action', ['marketplace_apply_action_id'], ['id'], 'PRIMARY'],
                'mm4a_refund_last_fk' => ['marketplace_aftersale_action', ['marketplace_last_action_id'], ['id'], 'PRIMARY']],
            'checks' => ['mm4a_refund_v1' => self::truth('(' . implode(' AND ', $legacy) . ') OR (' . $valid . ')')],
        ];
    }

    private static function aftersaleRefundBase(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        $old = ['columns'=>[
            'mer_id'=>self::column('int unsigned','随原订单的经营商家编号',true),
            'marketplace_refund_mode'=>self::column('varchar(16)','申请时固定的money_only或return_goods模式',true,null,'ascii')],
            'indexes'=>['idx_ms4_refund_owner'=>[false,['mer_id','store_order_id','id']]]];
        return ['table_collation' => null, 'table_comment' => '退款订单表',
            'columns' => [
                'id' => $c('int', '自增ID', false, null, null, true), 'store_order_id' => $c('int', '订单表ID', false, '0'),
                'store_id' => $c('int', '门店ID', false, '0'), 'order_id' => $c('varchar(50)', '订单号', false, '', 'utf8mb4'),
                'uid' => $c('int', '用户UID', false, '0'), 'refund_type' => $c('tinyint', '退款申请类型', false, '0'),
                'refund_num' => $c('int', '退款件数', false, '0'), 'refund_price' => $c('decimal(12,2)', '退款金额', false, '0.00'),
                'refunded_price' => $c('decimal(12,2)', '已退款金额', false, '0.00'), 'refund_phone' => $c('varchar(32)', '退款电话', false, '', 'utf8mb4'),
                'refund_express' => $c('varchar(100)', '退货快递单号', false, '', 'utf8mb4'), 'refund_express_name' => $c('varchar(255)', '退货快递名称', false, '', 'utf8mb4'),
                'refund_explain' => $c('varchar(255)', '退款用户说明', false, '', 'utf8mb4'), 'refund_img' => $c('text', '退款图片', true, null, 'utf8mb4'),
                'refund_reason' => $c('varchar(255)', '不退款的理由', false, '', 'utf8mb4'), 'refuse_reason' => $c('varchar(255)', '拒绝原因', false, '', 'utf8mb4'),
                'remark' => $c('varchar(255)', '备注', false, '', 'utf8mb4'), 'refunded_time' => $c('int', '处理时间', false, '0'),
                'cart_info' => $c('longtext', '退款商品信息', true, null, 'utf8mb4'), 'is_cancel' => $c('tinyint', '用户是否取消', false, '0'),
                'is_pink_cancel' => $c('tinyint', '是否拼团自动取消', false, '0'), 'is_del' => $c('tinyint', '取消申请', true, '0'),
                'add_time' => $c('int', '申请退款时间', false, '0'), 'is_system_del' => $c('tinyint', '系统删除', true, '0'),
            ] + $old['columns'], 'indexes' => ['PRIMARY' => [true, ['id']]] + $old['indexes'], 'foreign' => [], 'checks' => []];
    }

    private function sameAftersaleTable(array $meta, array $contract): bool
    {
        if (!$this->baseTable($meta) || ($contract['table_collation'] !== null && $meta['table']['TABLE_COLLATION'] !== $contract['table_collation'])
            || $meta['table']['TABLE_COMMENT'] !== $contract['table_comment']
            || array_keys($meta['columns']) !== array_keys($contract['columns'])) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : ($expected['charset'] === 'utf8mb4' ? $meta['table']['TABLE_COLLATION'] : null);
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
        if (!$this->sameForeign($meta['foreign'], $contract['foreign'])) return false;
        $checks = [];
        foreach ($meta['checks'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $tree = self::checkMetadataExpression($row['CHECK_CLAUSE']);
            if (isset($checks[$name]) || $row['ENFORCED'] !== 'YES' || $tree === null) return false;
            $checks[$name] = $tree;
        }
        $wanted = [];
        foreach ($contract['checks'] as $name => $expression) {
            $tree = self::checkExpression($expression);
            if ($tree === null) throw new RuntimeException('Declared check unavailable');
            $wanted[$this->prefix . $name] = $tree;
        }
        ksort($checks, SORT_STRING); ksort($wanted, SORT_STRING);
        if ($checks !== $wanted) return false;
        $wantedConstraints = [];
        foreach ($contract['indexes'] as $name => [$unique]) if ($unique) $wantedConstraints[$name] = $name === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE';
        foreach ($contract['foreign'] as $name => $_) $wantedConstraints[$this->prefix . $name] = 'FOREIGN KEY';
        foreach ($contract['checks'] as $name => $_) $wantedConstraints[$this->prefix . $name] = 'CHECK';
        $actualConstraints = [];
        foreach ($meta['constraints'] as $row) {
            if (isset($actualConstraints[$row['CONSTRAINT_NAME']])) return false;
            $actualConstraints[$row['CONSTRAINT_NAME']] = $row['CONSTRAINT_TYPE'];
        }
        ksort($actualConstraints, SORT_STRING); ksort($wantedConstraints, SORT_STRING);
        return $actualConstraints === $wantedConstraints;
    }

    private function baseTable(?array $meta): bool
    {
        return $meta !== null && $meta['table']['TABLE_TYPE'] === 'BASE TABLE'
            && strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') === 0
            && preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) === 1
            && $meta['triggers'] === [];
    }

    private function sameNewTable(array $meta, array $contract): bool
    {
        if (!$this->baseTable($meta) || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin'
            || $meta['table']['TABLE_COMMENT'] !== self::MARKER
            || array_keys($meta['columns']) !== array_keys($contract['columns'])) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : null;
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
        if (!$this->sameForeign($meta['foreign'], $contract['foreign'])) return false;
        $checks = [];
        foreach ($meta['checks'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $tree = self::checkMetadataExpression($row['CHECK_CLAUSE']);
            if (isset($checks[$name]) || $row['ENFORCED'] !== 'YES' || $tree === null) return false;
            $checks[$name] = $tree;
        }
        $wanted = [];
        foreach ($contract['checks'] as $name => $expression) {
            $tree = self::checkExpression($expression);
            if ($tree === null) throw new RuntimeException('Declared check unavailable');
            $wanted[$this->prefix . $name] = $tree;
        }
        ksort($checks, SORT_STRING); ksort($wanted, SORT_STRING);
        return $checks === $wanted;
    }

    private function sameForeign(array $rows, array $expected): bool
    {
        $actual = [];
        foreach ($rows as $row) $actual[$row['CONSTRAINT_NAME']][] = $row;
        $wanted = [];
        foreach ($expected as $name => $contract) $wanted[$this->prefix . $name] = $contract;
        ksort($actual, SORT_STRING); ksort($wanted, SORT_STRING);
        if (array_keys($actual) !== array_keys($wanted)) return false;
        foreach ($wanted as $name => [$target, $columns, $references, $unique]) {
            if (count($actual[$name]) !== count($columns)) return false;
            foreach ($actual[$name] as $offset => $row) {
                if ((string)$row['ORDINAL_POSITION'] !== (string)($offset + 1)
                    || (string)$row['POSITION_IN_UNIQUE_CONSTRAINT'] !== (string)($offset + 1)
                    || $row['COLUMN_NAME'] !== $columns[$offset] || $row['REFERENCED_COLUMN_NAME'] !== $references[$offset]
                    || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database || $row['REFERENCED_TABLE_NAME'] !== $this->prefix . $target
                    || $row['UNIQUE_CONSTRAINT_SCHEMA'] !== $this->database || $row['UNIQUE_CONSTRAINT_NAME'] !== $unique
                    || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT'
                    || $row['MATCH_OPTION'] !== 'NONE') return false;
            }
        }
        return true;
    }

    private function sameColumn(array $actual, array $expected, ?string $collation, bool $comment = false): bool
    {
        return $actual['COLUMN_TYPE'] === $expected['type']
            && $actual['IS_NULLABLE'] === ($expected['nullable'] ? 'YES' : 'NO')
            && $actual['COLUMN_DEFAULT'] === $expected['default']
            && $actual['CHARACTER_SET_NAME'] === $expected['charset']
            && $actual['COLLATION_NAME'] === $collation
            && $actual['EXTRA'] === ($expected['auto'] ? 'auto_increment' : '')
            && (string) $actual['GENERATION_EXPRESSION'] === ''
            && (!$comment || $actual['COLUMN_COMMENT'] === $expected['comment']);
    }

    private function sameIndex(array $actual, array $expected): bool
    {
        [$unique, $columns] = $expected;
        if (count($actual) !== count($columns)) return false;
        foreach ($actual as $i => $row) if ((int) $row['SEQ_IN_INDEX'] !== $i + 1
            || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$i]
            || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
            || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') return false;
        return true;
    }

    private function metadata(string $name): ?array
    {
        $args = [$this->database, $this->prefix . $name];
        $tables = $this->schemaRows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', $args);
        if ($tables === []) return null;
        if (count($tables) !== 1) throw new RuntimeException('Metadata unavailable');
        $columns = [];
        foreach ($this->schemaRows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            if ((string)$column['ORDINAL_POSITION'] !== (string)(count($columns) + 1) || isset($columns[$column['COLUMN_NAME']])) {
                throw new RuntimeException('Column metadata unavailable');
            }
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string)$column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->schemaRows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
            'constraints' => $this->schemaRows('SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY BINARY CONSTRAINT_NAME', $args),
            'foreign' => $this->schemaRows('SELECT rc.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE,rc.MATCH_OPTION,rc.UNIQUE_CONSTRAINT_SCHEMA,rc.UNIQUE_CONSTRAINT_NAME,kcu.ORDINAL_POSITION,kcu.POSITION_IN_UNIQUE_CONSTRAINT,kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_SCHEMA,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME=rc.TABLE_NAME AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA=? AND rc.TABLE_NAME=? ORDER BY BINARY rc.CONSTRAINT_NAME,kcu.ORDINAL_POSITION', $args),
            'checks' => $this->schemaRows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY BINARY tc.CONSTRAINT_NAME", $args),
            'triggers' => $this->schemaRows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY BINARY TRIGGER_NAME', $args)];
    }

    /** Exact literals used by the original checks and these finite Task7 checks. */
    private const CHECK_LITERALS = ['OBJECT','ship','receive','employee','customer','system','level','member',
        'admin','payment','customer:','employee:','admin:','payment:','finance_observe','apply','withdraw','approve','reject','express','accept_return',
        'money_only','cancel_unshipped','return_goods','NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED',
        'REQUESTED','APPROVED','WITHDRAWN','REJECTED','COMPLETED','NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED',
        'image/jpeg','image/png','image/webp','[^0-9a-f]','^[!-~]([ -~]{0,126}[!-~])?$','[^ -~]','c','STAGED','CLAIMED','DELETE_PENDING','DELETED'];

    /** Only the original observed escaped-literal presentation is decoded, for the finite literals above. */
    private static function checkMetadataExpression(string $expression): ?array
    {
        foreach (self::CHECK_LITERALS as $literal) foreach (['_utf8mb4','_ascii'] as $charset)
            $expression = str_replace($charset . "\\'" . $literal . "\\'", $charset . "'" . $literal . "'", $expression);
        return self::checkExpression($expression);
    }

    /** Original finite grammar with only the fixed Task7 functions/arity; never evaluated as SQL/PHP. */
    private static function checkExpression(string $expression): ?array
    {
        if (strlen($expression) > 16384) return null;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|(<>|<=|>=|[(),+\\-=<>]))/A", $expression, $match, 0, $offset)) return null;
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') continue;
            if (($match[1] ?? '') !== '') $tokens[] = ['id', strtolower($match[1])];
            elseif (($match[2] ?? '') !== '') $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))];
            elseif (($match[3] ?? '') !== '') $tokens[] = ['word', strtolower($match[3])];
            elseif (($match[4] ?? '') !== '') $tokens[] = ['number', $match[4]];
            else $tokens[] = ['symbol', $match[5]];
            if (count($tokens) > 4096) return null;
        }
        $position = 0; $depth = 0;
        $peek = static function (string $value) use (&$tokens, &$position): bool {
            return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true);
        };
        $take = static function (string $value) use (&$position, $peek): bool {
            if (!$peek($value)) return false;
            ++$position; return true;
        };
        $or = $and = $comparison = $addition = $primary = null;
        $primary = static function () use (&$tokens, &$position, &$depth, &$or, $take): array {
            if (++$depth > 128) throw new RuntimeException('Check grammar');
            try {
                if ($take('(')) {
                    $node = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    return $node;
                }
                $token = $tokens[$position++] ?? null;
                if ($token === null) throw new RuntimeException('Check grammar');
                if (in_array($token, [['word', '_utf8mb4'], ['word', '_ascii']], true)) {
                    $token = $tokens[$position++] ?? null;
                    if (($token[0] ?? '') !== 'string') throw new RuntimeException('Check grammar');
                }
                if ($token[0] === 'string') {
                    if (!in_array($token[1], self::CHECK_LITERALS, true)) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token[0] === 'number') {
                    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $token[1]) !== 1) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token === ['word','true'] || $token === ['word','false']) return ['number', $token[1] === 'true' ? '1' : '0'];
                if ($token[0] !== 'id' && $token[0] !== 'word') throw new RuntimeException('Check grammar');
                if ($take('(')) {
                    $arity = ['json_type'=>1,'coalesce'=>2,'concat'=>2,'octet_length'=>1,'regexp_like'=>3][$token[1]] ?? null;
                    if ($arity === null) throw new RuntimeException('Check grammar');
                    $arguments = [$or()];
                    while ($take(',')) $arguments[] = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    if (count($arguments) !== $arity) throw new RuntimeException('Check grammar');
                    // Keep the original single-argument JSON tree byte contract.
                    return $token[1] === 'json_type' ? ['function','json_type',$arguments[0]] : ['function',$token[1],$arguments];
                }
                return ['id', $token[1]];
            } finally { --$depth; }
        };
        $addition = static function () use (&$primary, $peek, $take): array {
            $node = $primary();
            while ($peek('+') || $peek('-')) {
                $operator = $take('+') ? '+' : '-';
                if ($operator === '-' && !$take('-')) throw new RuntimeException('Check grammar');
                $node = [$operator, $node, $primary()];
            }
            return $node;
        };
        $comparison = static function () use (&$addition, $take): array {
            $node = $addition();
            foreach (['=', '<>', '<=', '>=', '<', '>'] as $operator) if ($take($operator)) return [$operator, $node, $addition()];
            if ($take('is')) {
                $negative = $take('not');
                if (!$take('null')) throw new RuntimeException('Check grammar');
                return [$negative ? 'is_not_null' : 'is_null', $node];
            }
            if ($take('between')) {
                $lower = $addition();
                if (!$take('and')) throw new RuntimeException('Check grammar');
                return ['between', $node, $lower, $addition()];
            }
            if ($take('in')) {
                if (!$take('(')) throw new RuntimeException('Check grammar');
                $values = [$addition()];
                while ($take(',')) $values[] = $addition();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return ['in', $node, $values];
            }
            return $node;
        };
        $flatten = static function (string $operator, array $nodes): array {
            if (count($nodes) === 1) return $nodes[0];
            $flat = [];
            foreach ($nodes as $node) foreach ($node[0] === $operator ? $node[1] : [$node] as $part) $flat[] = $part;
            return [$operator, $flat];
        };
        $and = static function () use (&$comparison, $take, $flatten): array {
            $nodes = [$comparison()];
            while ($take('and')) $nodes[] = $comparison();
            return $flatten('and', $nodes);
        };
        $or = static function () use (&$and, $take, $flatten): array {
            $nodes = [$and()];
            while ($take('or')) $nodes[] = $and();
            return $flatten('or', $nodes);
        };
        try {
            $tree = $or();
            return $position === count($tokens) ? $tree : null;
        } catch (Throwable $error) { return null; }
    }


}
