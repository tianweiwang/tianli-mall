<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use stdClass;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Native persistence only. The one owned service transaction supplies full verified facts and real authorization. */
final class MarketplaceOrderRefundDao
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const ROOT_MAX=8388607;
    private const ROOT_STOCK_MAX=16777215;
    private const TABLES=['store_order','store_order_refund','marketplace_aftersale_action',
        'store_product','store_product_attr_value','marketplace_stock_movement','marketplace_fulfillment_operation','store_order_status','user'];
    private const ACTION_FIELDS=['id','contract_version','root_order_id','uid','mer_id','refund_id','payment_attempt_id',
        'action','actor_type','actor_id','actor_ref','idem_key','request_digest','request_payload','result_payload','state',
        'previous_refund_action_id','fulfillment_cut_id','apply_refund_id','created_at','committed_at'];
    private const REFUND_FIELDS=['id','store_order_id','store_id','order_id','uid','refund_type','refund_num','refund_price',
        'refunded_price','refund_phone','refund_express','refund_express_name','refund_explain','refund_img','refund_reason',
        'refuse_reason','remark','refunded_time','cart_info','is_cancel','is_pink_cancel','is_del','add_time','is_system_del',
        'mer_id','marketplace_refund_mode','marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
        'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'];
    private const REFUND_ADDITIONS=['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
        'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'];
    private const MOVEMENT_FIELDS=['id','mer_id','order_id','product_id','sku_id','sku_type','sku_unique','qty','kind',
        'idem_key','parent_reserve_id','order_item_id','refund_id','actor_type','actor_id','request_digest','sku_stock_before',
        'sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before','root_stock_after','root_sales_before','root_sales_after','add_time'];
    private const STATE_FIELDS=['business_state','physical_state','finance_state','requested_amount','confirmed_amount',
        'held_amount','refund_type','is_cancel','return_delivery'];
    private const PLAN_FIELDS=['source_item_id','reserve_id','product_id','sku_id','sku_unique','original_quantity','quantity','segments'];
    private const PRODUCT_FIELDS=['id','mer_id','stock','sales'];
    private const SKU_FIELDS=['id','product_id','type','unique','stock','sales'];
    private string $prefix;

    public function __construct()
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        foreach(self::TABLES as$name)self::quote($prefix.$name);
        $this->prefix=$prefix;
    }

    /** Full actual root candidate only. Authorization and current payment/deletion eligibility belong to the owned service. */
    public function locateRoot(ConnectionInterface $connection,PDO $pdo,int $rootId):?array
    {
        $this->outside($connection,$pdo);self::integer($rootId,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').' WHERE id=? LIMIT 2',[$rootId]);
        $this->outside($connection,$pdo);if(count($rows)>1)self::unavailable();if($rows===[])return null;
        $row=$rows[0];self::rootIdentity($row);self::stored($row,['id'=>$rootId]);return $row;
    }

    /** All committed S1/S2 operations, with the actual 25 columns and raw JSON/BINARY values preserved. */
    public function lockOperationsForRoot(ConnectionInterface $connection,PDO $pdo,array $fullRoot):array
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_fulfillment_operation').
            ' WHERE root_order_id=? ORDER BY id FOR UPDATE',[$root['id']]);
        $last='0';foreach($rows as$row){
            self::operationRow($row);self::actionOwner($row,$root);$id=self::u64($row['id']);
            if(bccomp($id,$last,0)<=0)self::unavailable();$last=$id;
        }
        $this->write($connection,$pdo);return $rows;
    }

    /** Native id ordering retains the complete related log multiset, including identical four-column rows. */
    public function lockStatusLogsForRoot(ConnectionInterface $connection,PDO $pdo,array $fullRoot):array
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);
        $rows=$this->rows($connection,$pdo,'SELECT oid,change_type,change_message,change_time FROM '.$this->table('store_order_status').
            ' WHERE oid=? OR oid IN (SELECT id FROM '.$this->table('store_order').' WHERE pid=?) ORDER BY oid ASC,BINARY change_type ASC,BINARY change_message ASC,change_time ASC FOR UPDATE',[$root['id'],$root['id']]);
        foreach($rows as$row){
            self::keys($row,['oid','change_type','change_message','change_time']);self::integer($row['oid'],1,self::INT_MAX);
            self::characters($row['change_type'],32);self::characters($row['change_message'],256);self::integer($row['change_time'],0,self::UINT_MAX);
        }
        $this->write($connection,$pdo);return $rows;
    }

    /** Only the actual writer's root status change calls this four-column log; the native table has no PK. */
    public function writeAftersaleStatusLog(ConnectionInterface $connection,PDO $pdo,array $fullRoot,int $createdAt):void
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);self::integer($createdAt,1,self::UINT_MAX);
        $data=['oid'=>$root['id'],'change_type'=>'marketplace_aftersale','change_message'=>'多商户售后状态更新','change_time'=>$createdAt];
        self::characters($data['change_type'],32,true);self::characters($data['change_message'],256,true);
        $sql='SELECT oid,change_type,change_message,change_time FROM '.$this->table('store_order_status').
            ' WHERE oid=? AND BINARY change_type=? AND BINARY change_message=? AND change_time=?'.
            ' ORDER BY oid ASC,BINARY change_type ASC,BINARY change_message ASC,change_time ASC FOR UPDATE';
        $before=$this->rows($connection,$pdo,$sql,array_values($data));
        foreach($before as$row){self::keys($row,array_keys($data));self::stored($row,$data);}
        // No lastInsertId: all four native values identify this log multiset, not an invented row identity.
        $this->change($connection,$pdo,'INSERT INTO '.$this->table('store_order_status').
            ' (oid,change_type,change_message,change_time) VALUES (?,?,?,?)',array_values($data));
        $after=$this->rows($connection,$pdo,$sql,array_values($data));
        if(count($after)!==count($before)+1)self::unavailable();
        foreach($after as$row){self::keys($row,array_keys($data));self::stored($row,$data);}
        $this->write($connection,$pdo);
    }

    /** Structural/native contract only; source/math, complete ranges and historical cuts are proved later from full Context2. */
    private static function operationRow(array $row):void
    {
        self::keys($row,['id','contract_version','root_order_id','child_order_id','uid','mer_id','action','idem_key',
            'request_digest','source_snapshot_digest','allocation_digest','request_payload','result_payload','state',
            'ship_child_order_id','target_operation_id','target_action','actor_type','actor_id','created_at','committed_at',
            'shipped_at','receipt_due_at','received_at','aftersale_cut_id']);self::nativeRow($row);
        self::u64($row['id']);$version=self::integer($row['contract_version'],1,2);
        $root=self::integer($row['root_order_id'],1,self::INT_MAX);$child=self::integer($row['child_order_id'],1,self::INT_MAX);
        $uid=self::integer($row['uid'],1,self::INT_MAX);self::integer($row['mer_id'],1,self::UINT_MAX);if($root===$child)self::unavailable();
        if($version===1){if($row['aftersale_cut_id']!==null)self::unavailable();}else self::u64($row['aftersale_cut_id']);
        self::key($row['idem_key']);$created=self::integer($row['created_at'],1,self::UINT_MAX);
        self::integer($row['state'],1,1);self::integer($row['committed_at'],$created,self::UINT_MAX);
        foreach(['request_digest','source_snapshot_digest','allocation_digest'] as$key)
            if(!is_string($row[$key])||strlen($row[$key])!==32)self::unavailable();
        self::decodeObject($row['request_payload'],65536);self::decodeObject($row['result_payload'],1048576);
        if($row['action']==='ship'){
            if(self::integer($row['ship_child_order_id'],1,self::INT_MAX)!==$child||$row['target_operation_id']!==null
                ||$row['target_action']!==null||$row['received_at']!==null||$row['actor_type']!=='employee')self::unavailable();
            $shipped=self::integer($row['shipped_at'],1,self::UINT_MAX);$due=self::integer($row['receipt_due_at'],0,self::UINT_MAX);
            if($due!==0&&$due<$shipped)self::unavailable();self::integer($row['actor_id'],1,self::UINT_MAX);
        }elseif($row['action']==='receive'){
            if($row['ship_child_order_id']!==null||$row['target_action']!=='ship'||$row['shipped_at']!==null||$row['receipt_due_at']!==null)self::unavailable();
            self::u64($row['target_operation_id']);self::integer($row['received_at'],1,self::UINT_MAX);
            if($row['actor_type']==='customer'){if(self::integer($row['actor_id'],1,self::INT_MAX)!==$uid)self::unavailable();}
            elseif($row['actor_type']==='system'){if($row['actor_id']!==null)self::unavailable();}else self::unavailable();
        }else self::unavailable();
    }

    /** Internal locator only; no endpoint may publish this row before its real resource authorization. */
    public function locateRefund(ConnectionInterface $connection,PDO $pdo,int $refundId):?array
    {
        $this->outside($connection,$pdo);self::integer($refundId,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_refund').' WHERE id=? LIMIT 2',[$refundId]);
        $this->outside($connection,$pdo);
        if(count($rows)>1)self::unavailable();return $rows===[]?null:self::refundRow($rows[0]);
    }

    public function locateCustomerRefund(ConnectionInterface $connection,PDO $pdo,int $actualUid,string $refundNo):?array
    {
        $this->outside($connection,$pdo);self::integer($actualUid,1,self::INT_MAX);self::text($refundNo,50,true);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_refund').
            ' WHERE uid=? AND BINARY order_id=? LIMIT 2',[$actualUid,$refundNo]);
        $this->outside($connection,$pdo);
        if(count($rows)>1)self::unavailable();if($rows===[])return null;
        $row=self::refundRow($rows[0]);self::stored($row,['uid'=>$actualUid,'order_id'=>$refundNo]);return $row;
    }

    /** Called after the original complete family/refund/log locks, never as a substitute for them. */
    public function lockActionsForRoot(ConnectionInterface $connection,PDO $pdo,array $fullRoot):array
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_aftersale_action').
            ' WHERE root_order_id=? ORDER BY id FOR UPDATE',[$root['id']]);
        $last='0';foreach($rows as$row){self::actionRow($row);self::committed($row);self::actionOwner($row,$root);
            $id=self::u64($row['id']);if(bccomp($id,$last,0)<=0)self::unavailable();$last=$id;}
        $this->write($connection,$pdo);return $rows;
    }

    /**
     * Inspect the actual FOR UPDATE complete Context2.actions for same-root replay BEFORE this lookup.
     * This global nonlocking consistent-view helper only supplements an observed immutable binding.
     * NULL does not prove global key absence; the unique index resolves competition. No foreign root lock.
     */
    public function findCommittedActionByActorKey(ConnectionInterface $connection,PDO $pdo,string $actorRef,string $idemKey):?array
    {
        $this->write($connection,$pdo);self::actorRef($actorRef);self::key($idemKey);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_aftersale_action').
            ' WHERE BINARY actor_ref=? AND BINARY idem_key=? LIMIT 2',[$actorRef,$idemKey]);
        $this->write($connection,$pdo);if(count($rows)>1)self::unavailable();if($rows===[])return null;
        $row=self::actionRow($rows[0]);self::committed($row);self::stored($row,['actor_ref'=>$actorRef,'idem_key'=>$idemKey]);return $row;
    }

    /** All native log types for the actual root and its real children; four columns, no invented log PK. */
    public function readStatusLogsForRoots(ConnectionInterface $connection,PDO $pdo,array $fullRoots):array
    {
        $this->snapshot($connection,$pdo);$roots=self::roots($fullRoots);$out=[];$owners=[];
        foreach($roots as$id=>$root){$out[$id]=[];$owners[$id]=$id;}
        if($roots!==[]){
            $ids=array_keys($roots);$marks=implode(',',array_fill(0,count($ids),'?'));
            $children=$this->rows($connection,$pdo,'SELECT id,pid,uid,mer_id,marketplace_trade_version FROM '.$this->table('store_order').
                ' WHERE pid IN ('.$marks.') ORDER BY pid,id',$ids);
            foreach($children as$child){
                self::keys($child,['id','pid','uid','mer_id','marketplace_trade_version']);
                $id=self::integer($child['id'],1,self::INT_MAX);$pid=self::integer($child['pid'],1,self::INT_MAX);
                if(!isset($roots[$pid])||isset($owners[$id]))self::unavailable();
                if(self::integer($child['uid'],1,self::INT_MAX)!==$roots[$pid]['uid']
                    ||self::integer($child['mer_id'],1,self::UINT_MAX)!==$roots[$pid]['mer_id']
                    ||self::integer($child['marketplace_trade_version'],1,1)!==1)self::unavailable();
                $owners[$id]=$pid;
            }
            $rows=$this->rows($connection,$pdo,'SELECT oid,change_type,change_message,change_time FROM '.$this->table('store_order_status').
                ' WHERE oid IN ('.$marks.') OR oid IN (SELECT id FROM '.$this->table('store_order').' WHERE pid IN ('.$marks.'))'.
                ' ORDER BY oid ASC,BINARY change_type ASC,BINARY change_message ASC,change_time ASC',array_merge($ids,$ids));
            foreach($rows as$row){
                self::keys($row,['oid','change_type','change_message','change_time']);$oid=self::integer($row['oid'],1,self::INT_MAX);
                if(!isset($owners[$oid]))self::unavailable();self::characters($row['change_type'],32);self::characters($row['change_message'],256);
                self::integer($row['change_time'],0,self::UINT_MAX);$out[$owners[$oid]][]=$row;
            }
        }
        $this->snapshot($connection,$pdo);return $out;
    }

    public function readCustomerRefundPage(ConnectionInterface $connection,PDO $pdo,int $uid,array $normalized,int $page,int $limit):array
    {
        self::integer($uid,1,self::INT_MAX);self::keys($normalized,['contract','page','limit']);
        if($normalized['contract']!==2)self::unavailable();
        return $this->refundReadPage($connection,$pdo,'customer',$uid,null,$normalized,$page,$limit);
    }

    public function readMerchantRefundPage(ConnectionInterface $connection,PDO $pdo,int $merId,array $normalized,int $page,int $limit):array
    {
        self::integer($merId,1,self::UINT_MAX);self::keys($normalized,['page','limit','business_state']);
        return $this->refundReadPage($connection,$pdo,'merchant',null,$merId,$normalized,$page,$limit);
    }

    public function readPlatformRefundPage(ConnectionInterface $connection,PDO $pdo,array $normalized,int $page,int $limit):array
    {
        self::keys($normalized,['page','limit','business_state','mer_id']);
        if($normalized['mer_id']!==null&&!is_int($normalized['mer_id']))self::unavailable();
        if($normalized['mer_id']!==null)self::integer($normalized['mer_id'],1,self::UINT_MAX);
        return $this->refundReadPage($connection,$pdo,'platform',null,$normalized['mer_id'],$normalized,$page,$limit);
    }

    /** Nonlocking details only. No deleted/cancelled-state predicate may hide a saved withdrawn action or replay. */
    public function readCustomerRefundByNo(ConnectionInterface $connection,PDO $pdo,int $uid,string $refundNo):?array
    {
        self::integer($uid,1,self::INT_MAX);self::text($refundNo,50,true);
        return $this->refundReadDetail($connection,$pdo,'customer',$uid,null,null,$refundNo);
    }

    public function readMerchantRefundById(ConnectionInterface $connection,PDO $pdo,int $merId,int $refundId):?array
    {
        self::integer($merId,1,self::UINT_MAX);self::integer($refundId,1,self::INT_MAX);
        return $this->refundReadDetail($connection,$pdo,'merchant',null,$merId,$refundId,null);
    }

    public function readPlatformRefundById(ConnectionInterface $connection,PDO $pdo,int $refundId):?array
    {
        self::integer($refundId,1,self::INT_MAX);
        return $this->refundReadDetail($connection,$pdo,'platform',null,null,$refundId,null);
    }

    /** Full native saved roots for the actual selected refund rows, preserving first-root appearance order. */
    public function readRootsForRefunds(ConnectionInterface $connection,PDO $pdo,array $fullRefunds):array
    {
        $this->snapshot($connection,$pdo);if(!array_is_list($fullRefunds)||count($fullRefunds)>100)self::unavailable();
        $ids=[];$seen=[];
        foreach($fullRefunds as$row){
            if(!is_array($row))self::unavailable();self::refundRow($row);$id=self::integer($row['id'],1,self::INT_MAX);
            if(isset($seen[$id]))self::unavailable();$seen[$id]=true;$root=self::integer($row['store_order_id'],1,self::INT_MAX);
            if(self::integer($row['marketplace_root_order_id'],1,self::INT_MAX)!==$root)self::unavailable();$ids[$root]=$root;
        }
        if($ids===[]){$this->snapshot($connection,$pdo);return [];}
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').
            ' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',array_values($ids));$byId=[];
        foreach($rows as$row){$root=self::rootIdentity($row);$id=$root['id'];
            if(!isset($ids[$id])||isset($byId[$id]))self::unavailable();$byId[$id]=$row;}
        if(count($byId)!==count($ids))self::unavailable();
        foreach($fullRefunds as$row)self::refundOwner($row,self::rootIdentity($byId[self::integer($row['store_order_id'],1,self::INT_MAX)]));
        $out=[];foreach($ids as$id)$out[$id]=$byId[$id];$this->snapshot($connection,$pdo);return $out;
    }

    /** Quote locator only, including a real saved child; the owned service resolves and validates its actual parent. */
    public function readCustomerQuoteOrder(ConnectionInterface $connection,PDO $pdo,int $uid,int $orderId):?array
    {
        $this->snapshot($connection,$pdo);self::integer($uid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order').' WHERE id=? AND uid=? LIMIT 2',[$orderId,$uid]);
        $this->snapshot($connection,$pdo);if(count($rows)>1)self::unavailable();if($rows===[])return null;$row=$rows[0];
        self::stored($row,['id'=>$orderId,'uid'=>$uid]);self::integer($row['marketplace_trade_version']??null,1,1);
        self::integer($row['pid']??null,0,self::INT_MAX);self::integer($row['mer_id']??null,1,self::UINT_MAX);
        self::text($row['order_id']??null,32,true);return $row;
    }

    public function readCustomerStatus(ConnectionInterface $connection,PDO $pdo,int $uid):array
    {
        $this->snapshot($connection,$pdo);self::integer($uid,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT uid,status,is_del FROM '.$this->table('user').' WHERE uid=? LIMIT 2',[$uid]);
        $this->snapshot($connection,$pdo);if(count($rows)!==1)self::unavailable();$row=$rows[0];
        self::keys($row,['uid','status','is_del']);self::stored($row,['uid'=>$uid]);
        self::integer($row['status'],1,1);self::integer($row['is_del'],0,0);return $row;
    }

    private function refundReadPage(ConnectionInterface $connection,PDO $pdo,string $kind,?int $uid,?int $merId,array $normalized,int $page,int $limit):array
    {
        $this->snapshot($connection,$pdo);self::integer($page,1,self::INT_MAX);self::integer($limit,1,100);
        if($normalized['page']!==$page||$normalized['limit']!==$limit||$page-1>intdiv(PHP_INT_MAX,$limit))self::unavailable();
        [$predicate,$parameters]=$this->refundReadPredicate($kind,$uid,$merId);$state=$normalized['business_state']??null;
        if($state!==null){
            if(!in_array($state,['REQUESTED','APPROVED','COMPLETED','WITHDRAWN','REJECTED'],true))self::unavailable();
            $predicate.=' AND rf.marketplace_state=?';$parameters[]=$state;
        }
        $counts=$this->rows($connection,$pdo,'SELECT COUNT(*) AS aggregate'.$predicate,$parameters);
        if(count($counts)!==1)self::unavailable();self::keys($counts[0],['aggregate']);$count=self::integer($counts[0]['aggregate'],0,PHP_INT_MAX);
        $offset=($page-1)*$limit;
        $rows=$this->rows($connection,$pdo,'SELECT rf.*'.$predicate.' ORDER BY rf.id DESC LIMIT ? OFFSET ?',array_merge($parameters,[$limit,$offset]));
        if(count($rows)!==($offset>=$count?0:min($limit,$count-$offset)))self::unavailable();$last=null;
        foreach($rows as$row){
            self::refundRow($row);$id=self::integer($row['id'],1,self::INT_MAX);if($last!==null&&$id>=$last)self::unavailable();$last=$id;
            if($uid!==null)self::stored($row,['uid'=>$uid]);if($merId!==null)self::stored($row,['mer_id'=>$merId]);
            if($state!==null)self::stored($row,['marketplace_state'=>$state]);
        }
        $this->snapshot($connection,$pdo);return ['count'=>$count,'list'=>$rows];
    }

    private function refundReadDetail(ConnectionInterface $connection,PDO $pdo,string $kind,?int $uid,?int $merId,?int $id,?string $number):?array
    {
        $this->snapshot($connection,$pdo);[$predicate,$parameters]=$this->refundReadPredicate($kind,$uid,$merId);
        if($kind==='customer'){if($id!==null||$number===null)self::unavailable();$predicate.=' AND BINARY rf.order_id=?';$parameters[]=$number;}
        else{if($id===null||$number!==null)self::unavailable();$predicate.=' AND rf.id=?';$parameters[]=$id;}
        $rows=$this->rows($connection,$pdo,'SELECT rf.*'.$predicate.' LIMIT 2',$parameters);$this->snapshot($connection,$pdo);
        if(count($rows)>1)self::unavailable();if($rows===[])return null;$row=self::refundRow($rows[0]);
        if($uid!==null)self::stored($row,['uid'=>$uid]);if($merId!==null)self::stored($row,['mer_id'=>$merId]);
        if($id!==null)self::stored($row,['id'=>$id]);if($number!==null)self::stored($row,['order_id'=>$number]);return $row;
    }

    /** One fixed relation/predicate source for each native COUNT and window. No authorization is inferred from it. */
    private function refundReadPredicate(string $kind,?int $uid,?int $merId):array
    {
        $predicate=' FROM '.$this->table('store_order_refund').' rf INNER JOIN '.$this->table('store_order').
            ' ro ON ro.id=rf.store_order_id WHERE ro.marketplace_trade_version=1 AND ro.pid=0';$parameters=[];
        if($kind==='customer'){
            if($uid===null||$merId!==null)self::unavailable();self::integer($uid,1,self::INT_MAX);
            $predicate.=' AND rf.uid=? AND ro.uid=?';$parameters=[$uid,$uid];
        }elseif($kind==='merchant'){
            if($uid!==null||$merId===null)self::unavailable();self::integer($merId,1,self::UINT_MAX);
            $predicate.=' AND rf.mer_id=? AND ro.mer_id=?';$parameters=[$merId,$merId];
        }elseif($kind==='platform'){
            if($uid!==null)self::unavailable();
            if($merId!==null){self::integer($merId,1,self::UINT_MAX);$predicate.=' AND rf.mer_id=? AND ro.mer_id=?';$parameters=[$merId,$merId];}
        }else self::unavailable();return [$predicate,$parameters];
    }

    /** Complete native actions from the caller's existing RR READ ONLY snapshot; no FOR UPDATE or pages. */
    public function readActionsForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $this->snapshot($connection,$pdo);$roots=self::roots($fullAuthorizedRoots);$out=[];
        foreach($roots as$id=>$root)$out[$id]=[];
        if($roots!==[]){
            $ids=array_keys($roots);$marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_aftersale_action').
                ' WHERE root_order_id IN ('.$marks.') ORDER BY root_order_id,id',$ids);
            $last=[];$seen=[];
            foreach($rows as$row){
                self::actionRow($row);self::committed($row);$rid=self::integer($row['root_order_id'],1,self::INT_MAX);
                if(!isset($roots[$rid]))self::unavailable();self::actionOwner($row,$roots[$rid]);$id=self::u64($row['id']);
                if(isset($seen['a:'.$id])||bccomp($id,$last[$rid]??'0',0)<=0)self::unavailable();
                $last[$rid]=$id;$seen['a:'.$id]=true;$out[$rid][]=$row;
            }
        }
        $this->snapshot($connection,$pdo);return $out;
    }

    /** All refunds reachable by either stored root binding or actual parent/child order, not only apparently valid rows. */
    public function readRefundsForRoots(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoots):array
    {
        $this->snapshot($connection,$pdo);$roots=self::roots($fullAuthorizedRoots);$out=[];
        foreach($roots as$id=>$root)$out[$id]=[];
        if($roots!==[]){
            $ids=array_keys($roots);$marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=$this->rows($connection,$pdo,'SELECT rf.* FROM '.$this->table('store_order_refund').
                ' rf WHERE rf.marketplace_root_order_id IN ('.$marks.') OR rf.store_order_id IN ('.$marks.')'.
                ' OR rf.store_order_id IN (SELECT id FROM '.$this->table('store_order').' WHERE pid IN ('.$marks.')) ORDER BY rf.id',
                array_merge($ids,$ids,$ids));
            $last=0;
            foreach($rows as$row){
                self::refundRow($row);$id=self::integer($row['id'],1,self::INT_MAX);$rid=self::integer($row['marketplace_root_order_id'],1,self::INT_MAX);
                if($id<=$last||!isset($roots[$rid]))self::unavailable();$last=$id;self::refundOwner($row,$roots[$rid]);$out[$rid][]=$row;
            }
        }
        $this->snapshot($connection,$pdo);return $out;
    }

    public function readRefund(ConnectionInterface $connection,PDO $pdo,array $fullAuthorizedRoot,int $refundId):?array
    {
        $this->snapshot($connection,$pdo);$root=self::rootIdentity($fullAuthorizedRoot);self::integer($refundId,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_refund').' WHERE id=? LIMIT 2',[$refundId]);
        $this->snapshot($connection,$pdo);if(count($rows)>1)self::unavailable();if($rows===[])return null;
        $row=self::refundRow($rows[0]);self::refundOwner($row,$root);return $row;
    }

    public function lockRefundsForRoot(ConnectionInterface $connection,PDO $pdo,array $fullRoot):array
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);$id=$root['id'];
        $rows=$this->rows($connection,$pdo,'SELECT rf.* FROM '.$this->table('store_order_refund').
            ' rf WHERE rf.marketplace_root_order_id=? OR rf.store_order_id=? OR rf.store_order_id IN'.
            ' (SELECT id FROM '.$this->table('store_order').' WHERE pid=?) ORDER BY rf.id FOR UPDATE',[$id,$id,$id]);
        $last=0;foreach($rows as$row){self::refundRow($row);self::refundOwner($row,$root);
            $current=self::integer($row['id'],1,self::INT_MAX);if($current<=$last)self::unavailable();$last=$current;}
        $this->write($connection,$pdo);return $rows;
    }

    /** Every non-PK column is explicit. The eight NULLs exist only inside this uncommitted apply transaction. */
    public function insertRefund(ConnectionInterface $connection,PDO $pdo,array $data):array
    {
        $this->write($connection,$pdo);self::keys($data,array_values(array_diff(self::REFUND_FIELDS,['id'])));self::refundValues($data);
        foreach(self::REFUND_ADDITIONS as$key)if($data[$key]!==null)self::unavailable();
        self::stored($data,['refunded_price'=>'0.00','refunded_time'=>0,'is_cancel'=>0,'refuse_reason'=>'',
            'refund_express'=>'','refund_express_name'=>'','refund_type'=>$data['marketplace_refund_mode']==='return_goods'?2:1]);
        $id=self::integer($this->insertNative($connection,$pdo,'store_order_refund',$data),1,self::INT_MAX);
        $row=$this->refundById($connection,$pdo,$id);self::stored($row,['id'=>$id]+$data);$this->write($connection,$pdo);return $row;
    }

    /** data20.request_payload is the already normalized server request object; no actor/price plan is taken from HTTP here. */
    public function insertAction(ConnectionInterface $connection,PDO $pdo,array $data):array
    {
        $this->write($connection,$pdo);self::keys($data,array_values(array_diff(self::ACTION_FIELDS,['id'])));
        if(!is_array($data['request_payload']))self::unavailable();
        $data['request_payload']=self::encodeObject($data['request_payload'],65536);self::actionValues($data);
        self::stored($data,['state'=>0,'result_payload'=>null,'committed_at'=>null]);
        if($data['action']==='finance_observe')self::unavailable();
        self::integer($data['created_at'],1,self::INT_MAX);
        $refund=$this->refundById($connection,$pdo,self::integer($data['refund_id'],1,self::INT_MAX));
        self::stored($refund,['store_order_id'=>self::integer($data['root_order_id'],1,self::INT_MAX),
            'uid'=>self::integer($data['uid'],1,self::INT_MAX),'mer_id'=>self::integer($data['mer_id'],1,self::UINT_MAX)]);
        if($data['action']==='apply'){
            foreach(self::REFUND_ADDITIONS as$key)if($refund[$key]!==null)self::unavailable();
            if(self::integer($refund['add_time'],1,self::INT_MAX)!==self::integer($data['created_at'],1,self::INT_MAX))self::unavailable();
        }else{
            self::refundRow($refund);
            if(self::u64($refund['marketplace_last_action_id'])!==self::u64($data['previous_refund_action_id'])
                ||self::u64($refund['marketplace_payment_attempt_id'])!==self::u64($data['payment_attempt_id']))self::unavailable();
        }
        $id=$this->insertNative($connection,$pdo,'marketplace_aftersale_action',$data);
        $row=$this->actionById($connection,$pdo,$id);self::stored($row,['id'=>$id]+$data,['request_payload','result_payload']);
        $this->write($connection,$pdo);return $row;
    }

    /** Full mirror write, serialized by the original root and the exact previous saved action. */
    public function updateRefundMirror(ConnectionInterface $connection,PDO $pdo,array $before,array $after):array
    {
        $this->write($connection,$pdo);self::refundShape($before);self::refundRow($after);
        $id=self::integer($before['id'],1,self::INT_MAX);self::stored($after,['id'=>$id]);
        $current=$this->refundById($connection,$pdo,$id);self::stored($current,$before);
        $newId=self::u64($after['marketplace_last_action_id']);$pending=$this->actionById($connection,$pdo,$newId);
        self::pending($pending);
        self::stored($pending,['root_order_id'=>self::integer($after['store_order_id'],1,self::INT_MAX),
            'uid'=>self::integer($after['uid'],1,self::INT_MAX),'mer_id'=>self::integer($after['mer_id'],1,self::UINT_MAX),'refund_id'=>$id]);
        if(self::u64($after['marketplace_payment_attempt_id'])!==self::u64($pending['payment_attempt_id']))self::unavailable();
        $unbound=$before['marketplace_last_action_id']===null;
        if($unbound){
            foreach(self::REFUND_ADDITIONS as$key)if($before[$key]!==null)self::unavailable();
            if($pending['action']!=='apply'||$pending['previous_refund_action_id']!==null
                ||self::u64($after['marketplace_apply_action_id'])!==$newId)self::unavailable();
            $mutable=self::REFUND_ADDITIONS;
        }else{
            self::refundRow($before);$previous=self::u64($before['marketplace_last_action_id']);
            if($pending['action']==='apply'||$pending['previous_refund_action_id']===null
                ||self::u64($pending['previous_refund_action_id'])!==$previous||bccomp($newId,$previous,0)<=0)self::unavailable();
            $mutable=['refund_type','refunded_price','refund_express','refund_express_name','refuse_reason','refunded_time','is_cancel',
                'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_last_action_id'];
        }
        foreach(self::REFUND_FIELDS as$key)if(!in_array($key,$mutable,true))self::stored($after,[$key=>$before[$key]]);
        $processed=$pending['action']==='apply'?0:self::integer($pending['created_at'],1,self::INT_MAX);
        self::stored($after,['refunded_time'=>$processed]);$request=self::decodeObject($pending['request_payload'],65536);
        if($pending['action']==='reject')self::stored($after,['refuse_reason'=>self::characters($request['public_body']['reason']??null,255,true)]);
        elseif(!$unbound)self::stored($after,['refuse_reason'=>$before['refuse_reason']]);
        if($pending['action']==='express'){
            $delivery=$request['public_body']['return_delivery']??null;
            if(!is_array($delivery))self::unavailable();self::keys($delivery,['carrier_name','tracking_no']);
            self::stored($after,['refund_express'=>self::characters($delivery['tracking_no'],100,true),
                'refund_express_name'=>self::characters($delivery['carrier_name'],255,true)]);
        }elseif(!$unbound)self::stored($after,['refund_express'=>$before['refund_express'],'refund_express_name'=>$before['refund_express_name']]);
        $data=$after;unset($data['id']);$set=[];foreach(array_keys($data) as$key)$set[]=self::quote($key).'=?';
        $values=array_values($data);$values[]=$id;$values[]=$before['marketplace_last_action_id'];
        $values[]=self::integer($before['store_order_id'],1,self::INT_MAX);$values[]=self::integer($before['uid'],1,self::INT_MAX);
        $values[]=self::integer($before['mer_id'],1,self::UINT_MAX);
        $this->change($connection,$pdo,'UPDATE '.$this->table('store_order_refund').' SET '.implode(',',$set).
            ' WHERE id=? AND marketplace_last_action_id <=> ? AND store_order_id=? AND uid=? AND mer_id=?',$values);
        $row=$this->refundById($connection,$pdo,$id);self::refundRow($row);self::stored($row,$after);
        $this->write($connection,$pdo);return $row;
    }

    /** Only the inserted state0 row may complete. Caller still must reread and validate the final whole Context2 before commit. */
    public function finishAction(ConnectionInterface $connection,PDO $pdo,array $pending,array $result,int $committedAt):array
    {
        $this->write($connection,$pdo);self::actionRow($pending);self::pending($pending);
        $id=self::u64($pending['id']);$current=$this->actionById($connection,$pdo,$id);
        self::stored($current,$pending,['request_payload','result_payload']);
        self::integer($committedAt,self::integer($pending['created_at'],1,self::UINT_MAX),self::UINT_MAX);
        self::result($result);$after=$result['after'];$public=$result['public_result'];
        self::keys($public,['schema','scope','action_id','action','refund','committed_at','acceptance_ids']);
        if($public['schema']!==2||$public['scope']!=='marketplace_aftersale_action_v1'||!is_string($public['action_id'])
            ||$public['action_id']!==$id||$public['action']!==$pending['action']||$public['committed_at']!==$committedAt
            ||!is_array($public['refund'])||!is_array($public['acceptance_ids'])||!array_is_list($public['acceptance_ids']))self::unavailable();
        $refund=$this->refundById($connection,$pdo,self::integer($pending['refund_id'],1,self::INT_MAX));self::refundRow($refund);
        self::stored($refund,['marketplace_last_action_id'=>$id,'store_order_id'=>self::integer($pending['root_order_id'],1,self::INT_MAX),
            'uid'=>self::integer($pending['uid'],1,self::INT_MAX),'mer_id'=>self::integer($pending['mer_id'],1,self::UINT_MAX),
            'marketplace_payment_attempt_id'=>self::u64($pending['payment_attempt_id']),
            'marketplace_state'=>$after['business_state'],'marketplace_physical_state'=>$after['physical_state'],
            'marketplace_finance_state'=>$after['finance_state'],'refund_price'=>$after['requested_amount'],
            'refunded_price'=>$after['confirmed_amount'],'refund_type'=>$after['refund_type'],'is_cancel'=>$after['is_cancel']]);
        if(($public['refund']['id']??null)!==self::integer($pending['refund_id'],1,self::INT_MAX)
            ||($public['refund']['root_order_id']??null)!==self::integer($pending['root_order_id'],1,self::INT_MAX)
            ||($public['refund']['refund_no']??null)!==$refund['order_id'])self::unavailable();
        $delivery=$after['return_delivery'];
        self::stored($refund,['refund_express'=>$delivery===null?'':$delivery['tracking_no'],
            'refund_express_name'=>$delivery===null?'':$delivery['carrier_name']]);
        $this->resultMovements($connection,$pdo,$pending,$result['movements'],$public['acceptance_ids']);
        $json=self::encodeObject($result,1048576);
        $this->change($connection,$pdo,'UPDATE '.$this->table('marketplace_aftersale_action').
            ' SET state=1,result_payload=?,committed_at=? WHERE id=? AND state=0 AND result_payload IS NULL AND committed_at IS NULL',
            [$json,$committedAt,$id]);
        $expected=$pending;$expected['state']=1;$expected['result_payload']=$json;$expected['committed_at']=$committedAt;
        $row=$this->actionById($connection,$pdo,$id);self::stored($row,$expected,['request_payload','result_payload']);self::committed($row);
        $this->write($connection,$pdo);return $row;
    }

    /** Must run before action0 or any other business INSERT/UPDATE. Receipt contains actual native inventory, not authorization. */
    public function lockCreditInventory(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $plan):array
    {
        $this->write($connection,$pdo);$root=self::rootIdentity($fullRoot);$entries=self::plan($plan);
        $products=[];$skus=[];$productQuantity=[];$skuQuantity=[];
        foreach($entries as$entry){
            $pk='p:'.$entry['product_id'];$sk='s:'.$entry['sku_id'];
            if(!isset($products[$pk]))$products[$pk]=$this->product($connection,$pdo,$entry['product_id'],$root['mer_id']);
            if(!isset($skus[$sk]))$skus[$sk]=$this->sku($connection,$pdo,$entry['sku_id']);
            self::stored($skus[$sk],['product_id'=>$entry['product_id'],'type'=>0,'unique'=>$entry['sku_unique']]);
            $productQuantity[$pk]=($productQuantity[$pk]??0)+$entry['quantity'];
            $skuQuantity[$sk]=($skuQuantity[$sk]??0)+$entry['quantity'];
            self::integer($productQuantity[$pk],1,self::UINT_MAX);self::integer($skuQuantity[$sk],1,self::UINT_MAX);
        }
        foreach($products as$key=>$row)self::creditCapacity($row,$productQuantity[$key],self::ROOT_STOCK_MAX);
        foreach($skus as$key=>$row)self::creditCapacity($row,$skuQuantity[$key],self::UINT_MAX);
        $this->write($connection,$pdo);return ['root'=>$fullRoot,'entries'=>$entries,'products'=>$products,'skus'=>$skus];
    }

    /** Returns all actual 25-column rows, one per source item. Only two fixed Task7 physical events can call this writer. */
    public function writeCredits(ConnectionInterface $connection,PDO $pdo,array $pending,array $inventory):array
    {
        $this->write($connection,$pdo);self::actionRow($pending);self::pending($pending);
        self::keys($inventory,['root','entries','products','skus']);
        foreach($inventory as$value)if(!is_array($value))self::unavailable();
        $root=self::rootIdentity($inventory['root']);self::actionOwner($pending,$root);
        if(!in_array($pending['actor_type'],['employee','admin'],true)||!in_array($pending['action'],['approve','accept_return'],true))self::unavailable();
        $action=$this->actionById($connection,$pdo,self::u64($pending['id']));self::stored($action,$pending,['request_payload','result_payload']);
        $refund=$this->refundById($connection,$pdo,self::integer($pending['refund_id'],1,self::INT_MAX));self::refundRow($refund);self::refundOwner($refund,$root);
        if($pending['previous_refund_action_id']===null
            ||self::u64($pending['previous_refund_action_id'])!==self::u64($refund['marketplace_last_action_id'])
            ||self::u64($pending['payment_attempt_id'])!==self::u64($refund['marketplace_payment_attempt_id']))self::unavailable();
        if($pending['action']==='approve'){
            if($refund['marketplace_kind']!=='cancel_unshipped'||$refund['marketplace_state']!=='REQUESTED')self::unavailable();
            $contract='release_v2';$kind='release';
        }else{
            if($refund['marketplace_kind']!=='return_goods'||$refund['marketplace_state']!=='APPROVED')self::unavailable();
            $contract='return_in_v1';$kind='return_in';
        }
        // These are already-held rows from lockCreditInventory, reobserved before the first inventory write.
        $fresh=$this->lockCreditInventory($connection,$pdo,$inventory['root'],$inventory['entries']);
        if($fresh['entries']!==$inventory['entries'])self::unavailable();
        foreach(['products','skus'] as$key){
            self::keys($inventory[$key],array_keys($fresh[$key]));
            foreach($fresh[$key] as$id=>$row){if(!is_array($inventory[$key][$id]))self::unavailable();self::stored($row,$inventory[$key][$id]);}
        }
        $products=$fresh['products'];$skus=$fresh['skus'];$out=[];$actionId=self::u64($pending['id']);
        $refundId=self::integer($pending['refund_id'],1,self::INT_MAX);$actor=self::integer($pending['actor_id'],1,self::UINT_MAX);
        $at=self::integer($pending['created_at'],1,self::UINT_MAX);
        foreach($fresh['entries'] as$entry){
            $pk='p:'.$entry['product_id'];$sk='s:'.$entry['sku_id'];$qty=$entry['quantity'];
            $product=$this->product($connection,$pdo,$entry['product_id'],$root['mer_id']);$sku=$this->sku($connection,$pdo,$entry['sku_id']);
            self::stored($product,$products[$pk]);self::stored($sku,$skus[$sk]);
            self::creditCapacity($product,$qty,self::ROOT_STOCK_MAX);self::creditCapacity($sku,$qty,self::UINT_MAX);
            $rs=self::integer($product['stock'],0,self::ROOT_STOCK_MAX);$ra=self::integer($product['sales'],0,self::ROOT_STOCK_MAX);
            $ss=self::integer($sku['stock'],0,self::UINT_MAX);$sa=self::integer($sku['sales'],0,self::UINT_MAX);
            $this->change($connection,$pdo,'UPDATE '.$this->table('store_product_attr_value').
                ' SET stock=?,sales=? WHERE id=? AND product_id=? AND type=0 AND BINARY `unique`=? AND stock=? AND sales=?',
                [$ss+$qty,$sa-$qty,$entry['sku_id'],$entry['product_id'],$entry['sku_unique'],$ss,$sa]);
            $this->change($connection,$pdo,'UPDATE '.$this->table('store_product').
                ' SET stock=?,sales=? WHERE id=? AND mer_id=? AND stock=? AND sales=?',
                [$rs+$qty,$ra-$qty,$entry['product_id'],$root['mer_id'],$rs,$ra]);
            $nextSku=$this->sku($connection,$pdo,$entry['sku_id']);$nextProduct=$this->product($connection,$pdo,$entry['product_id'],$root['mer_id']);
            $expectedSku=$sku;$expectedSku['stock']=$ss+$qty;$expectedSku['sales']=$sa-$qty;
            $expectedProduct=$product;$expectedProduct['stock']=$rs+$qty;$expectedProduct['sales']=$ra-$qty;
            self::stored($nextSku,$expectedSku);self::stored($nextProduct,$expectedProduct);
            $digest=['schema'=>1,'credit_contract'=>$contract,'action_id'=>$actionId,'refund_id'=>$refundId,
                'root_order_id'=>$root['id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],'source_item_id'=>$entry['source_item_id'],
                'parent_reserve_id'=>$entry['reserve_id'],'product_id'=>$entry['product_id'],'sku_id'=>$entry['sku_id'],'sku_type'=>0,
                'sku_unique'=>$entry['sku_unique'],'kind'=>$kind,'quantity'=>$qty,'segments'=>$entry['segments'],
                'actor_type'=>$pending['actor_type'],'actor_id'=>$actor];
            $data=['mer_id'=>$root['mer_id'],'order_id'=>$root['id'],'product_id'=>$entry['product_id'],'sku_id'=>$entry['sku_id'],
                'sku_type'=>0,'sku_unique'=>$entry['sku_unique'],'qty'=>$qty,'kind'=>$kind,'idem_key'=>$contract.':'.$actionId.':'.$entry['source_item_id'],
                'parent_reserve_id'=>$entry['reserve_id'],'order_item_id'=>$entry['source_item_id'],'refund_id'=>$refundId,
                'actor_type'=>$pending['actor_type'],'actor_id'=>$actor,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($digest),true),
                'sku_stock_before'=>$ss,'sku_stock_after'=>$ss+$qty,'sku_sales_before'=>$sa,'sku_sales_after'=>$sa-$qty,
                'root_stock_before'=>$rs,'root_stock_after'=>$rs+$qty,'root_sales_before'=>$ra,'root_sales_after'=>$ra-$qty,'add_time'=>$at];
            self::keys($data,array_values(array_diff(self::MOVEMENT_FIELDS,['id'])));
            $id=$this->insertNative($connection,$pdo,'marketplace_stock_movement',$data);
            $row=$this->movementById($connection,$pdo,$id);self::stored($row,['id'=>$id]+$data);
            $out[]=$row;$products[$pk]=$nextProduct;$skus[$sk]=$nextSku;
        }
        $this->write($connection,$pdo);return $out;
    }

    /** Complete action-local movement linkage; full original-Q eligibility and whole-root budgets remain the final Facts responsibility. */
    private function resultMovements(ConnectionInterface $connection,PDO $pdo,array $pending,array $references,array $acceptanceIds):void
    {
        if(!array_is_list($references)||!array_is_list($acceptanceIds))self::unavailable();$actionId=self::u64($pending['id']);
        $release='release_v2:'.$actionId.':';$returned='return_in_v1:'.$actionId.':';
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_stock_movement').
            ' WHERE order_id=? AND (LEFT(BINARY idem_key,?)=? OR LEFT(BINARY idem_key,?)=?) ORDER BY id FOR UPDATE',
            [self::integer($pending['root_order_id'],1,self::INT_MAX),strlen($release),$release,strlen($returned),$returned]);
        $byId=[];$parts=[];$last='0';
        foreach($rows as$row){self::movementRow($row);$id=self::u64($row['id']);
            if(bccomp($id,$last,0)<=0)self::unavailable();$last=$id;$byId['m:'.$id]=$row;}
        foreach($references as$ref){
            if(!is_array($ref))self::unavailable();self::keys($ref,['movement_id','kind','source_item_id','reserve_id','unit_start','unit_end','quantity']);
            if(!is_string($ref['movement_id'])||!is_string($ref['reserve_id']))self::unavailable();
            $key='m:'.self::u64($ref['movement_id']);$row=$byId[$key]??null;if($row===null)self::unavailable();
            $first=self::integer($ref['unit_start'],0,self::INT_MAX-1);$end=self::integer($ref['unit_end'],$first+1,self::INT_MAX);
            if(!is_int($ref['unit_start'])||!is_int($ref['unit_end'])||!is_int($ref['quantity'])||$ref['quantity']!==$end-$first)self::unavailable();
            self::stored($row,['order_id'=>self::integer($pending['root_order_id'],1,self::INT_MAX),'mer_id'=>self::integer($pending['mer_id'],1,self::UINT_MAX),
                'refund_id'=>self::integer($pending['refund_id'],1,self::INT_MAX),'order_item_id'=>self::integer($ref['source_item_id'],1,self::INT_MAX),
                'kind'=>$ref['kind'],'parent_reserve_id'=>self::u64($ref['reserve_id']),'actor_type'=>$pending['actor_type'],
                'actor_id'=>self::integer($pending['actor_id'],1,self::UINT_MAX)]);
            $parts[$key][]=['unit_start'=>$first,'unit_end'=>$end];
        }
        if(count($parts)!==count($byId))self::unavailable();$actualAccept=[];
        foreach($byId as$key=>$row){
            $segments=$parts[$key]??null;if($segments===null)self::unavailable();
            usort($segments,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);$end=0;$quantity=0;
            foreach($segments as$part){if($part['unit_start']<$end)self::unavailable();$end=$part['unit_end'];$quantity+=$end-$part['unit_start'];}
            self::integer($quantity,1,self::INT_MAX);self::stored($row,['qty'=>$quantity]);
            if($pending['action']==='approve'&&$row['kind']==='release')$contract='release_v2';
            elseif($pending['action']==='accept_return'&&$row['kind']==='return_in'){$contract='return_in_v1';$actualAccept['m:'.self::u64($row['id'])]=true;}
            else self::unavailable();
            $source=self::integer($row['order_item_id'],1,self::INT_MAX);
            self::stored($row,['idem_key'=>$contract.':'.$actionId.':'.$source]);
            $object=['schema'=>1,'credit_contract'=>$contract,'action_id'=>$actionId,'refund_id'=>self::integer($pending['refund_id'],1,self::INT_MAX),
                'root_order_id'=>self::integer($pending['root_order_id'],1,self::INT_MAX),'uid'=>self::integer($pending['uid'],1,self::INT_MAX),
                'mer_id'=>self::integer($pending['mer_id'],1,self::UINT_MAX),'source_item_id'=>$source,'parent_reserve_id'=>self::u64($row['parent_reserve_id']),
                'product_id'=>self::integer($row['product_id'],1,self::ROOT_MAX),'sku_id'=>self::integer($row['sku_id'],1,self::INT_MAX),
                'sku_type'=>0,'sku_unique'=>$row['sku_unique'],'kind'=>$row['kind'],'quantity'=>$quantity,'segments'=>$segments,
                'actor_type'=>$pending['actor_type'],'actor_id'=>self::integer($pending['actor_id'],1,self::UINT_MAX)];
            if(!hash_equals($row['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($object),true)))self::unavailable();
        }
        $seen=[];foreach($acceptanceIds as$id){if(!is_string($id))self::unavailable();$key='m:'.self::u64($id);
            if(isset($seen[$key])||!isset($actualAccept[$key]))self::unavailable();$seen[$key]=true;}
        if(count($seen)!==count($actualAccept))self::unavailable();
    }

    private static function actionRow(array $row):array
    {
        self::keys($row,self::ACTION_FIELDS);self::nativeRow($row);self::u64($row['id']);$data=$row;unset($data['id']);
        self::actionValues($data);return $row;
    }
    private static function actionValues(array $data):void
    {
        self::keys($data,array_values(array_diff(self::ACTION_FIELDS,['id'])));self::nativeRow($data);
        self::integer($data['contract_version'],1,1);$root=self::integer($data['root_order_id'],1,self::INT_MAX);
        $uid=self::integer($data['uid'],1,self::INT_MAX);self::integer($data['mer_id'],1,self::UINT_MAX);
        $refund=self::integer($data['refund_id'],1,self::INT_MAX);$payment=self::u64($data['payment_attempt_id']);
        if(!in_array($data['action'],['apply','withdraw','approve','reject','express','accept_return','finance_observe'],true))self::unavailable();
        self::key($data['idem_key']);self::actorRef($data['actor_ref']);
        if($data['actor_type']==='payment'){
            if($data['actor_id']!==null||$data['action']!=='finance_observe'||$data['actor_ref']!=='payment:'.$payment)self::unavailable();
        }else{
            if(!in_array($data['actor_type'],['customer','employee','admin'],true)||$data['action']==='finance_observe')self::unavailable();
            $actor=self::integer($data['actor_id'],1,self::UINT_MAX);
            if($data['actor_ref']!==$data['actor_type'].':'.$actor||($data['actor_type']==='customer'&&$actor!==$uid))self::unavailable();
        }
        if($data['previous_refund_action_id']!==null)self::u64($data['previous_refund_action_id']);
        if($data['fulfillment_cut_id']!==null)self::u64($data['fulfillment_cut_id']);
        if($data['action']==='apply'){
            if($data['previous_refund_action_id']!==null||self::integer($data['apply_refund_id'],1,self::INT_MAX)!==$refund)self::unavailable();
        }elseif($data['previous_refund_action_id']===null||$data['apply_refund_id']!==null)self::unavailable();
        $created=self::integer($data['created_at'],1,self::UINT_MAX);$state=self::integer($data['state'],0,1);
        $request=self::decodeObject($data['request_payload'],65536);self::keys($request,['schema','action','root_order_id','refund_id','public_body']);
        if($request['schema']!==1||$request['action']!==$data['action']||$request['root_order_id']!==$root||$request['refund_id']!==$refund
            ||!is_array($request['public_body'])||array_is_list($request['public_body']))self::unavailable();
        if($data['action']!=='finance_observe'&&($request['public_body']['idem_key']??null)!==$data['idem_key'])self::unavailable();
        if(!is_string($data['request_digest'])||strlen($data['request_digest'])!==32
            ||!hash_equals($data['request_digest'],hash('sha256',MarketplaceOrderInput::canonical(['actor_ref'=>$data['actor_ref'],'request_payload'=>$request]),true)))self::unavailable();
        if($state===0){if($data['result_payload']!==null||$data['committed_at']!==null)self::unavailable();}
        else{
            self::integer($data['committed_at'],$created,self::UINT_MAX);$result=self::decodeObject($data['result_payload'],1048576);self::result($result);
        }
    }
    private static function pending(array $row):void
    {
        self::stored($row,['state'=>0,'result_payload'=>null,'committed_at'=>null]);
        if($row['action']==='finance_observe')self::unavailable();
    }
    private static function committed(array $row):void {self::stored($row,['state'=>1]);}
    private static function result(array $value):void
    {
        self::keys($value,['schema','before','after','allocations','movements','public_result']);
        if($value['schema']!==1||!is_array($value['after'])||!is_array($value['allocations'])||!array_is_list($value['allocations'])
            ||$value['allocations']===[]||!is_array($value['movements'])||!array_is_list($value['movements'])
            ||!is_array($value['public_result'])||array_is_list($value['public_result']))self::unavailable();
        if($value['before']!==null){if(!is_array($value['before']))self::unavailable();self::state($value['before']);}
        self::state($value['after']);
    }
    private static function state(array $state):void
    {
        self::keys($state,self::STATE_FIELDS);
        if(!in_array($state['business_state'],['REQUESTED','APPROVED','COMPLETED','WITHDRAWN','REJECTED'],true)
            ||!in_array($state['physical_state'],['NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'],true)
            ||!in_array($state['finance_state'],['NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED'],true))self::unavailable();
        self::money($state['requested_amount'],true);self::money($state['confirmed_amount']);self::money($state['held_amount']);
        if(!is_int($state['refund_type'])||!is_int($state['is_cancel']))self::unavailable();
        self::integer($state['refund_type'],1,6);self::integer($state['is_cancel'],0,1);
        if($state['return_delivery']!==null){
            if(!is_array($state['return_delivery']))self::unavailable();self::keys($state['return_delivery'],['carrier_name','tracking_no']);
            self::characters($state['return_delivery']['carrier_name'],255,true);self::characters($state['return_delivery']['tracking_no'],100,true);
        }
    }

    private static function refundRow(array $row):array
    {
        self::refundShape($row);foreach(self::REFUND_ADDITIONS as$key)if($row[$key]===null)self::unavailable();return $row;
    }
    private static function refundShape(array $row):void
    {
        self::keys($row,self::REFUND_FIELDS);self::nativeRow($row);self::integer($row['id'],1,self::INT_MAX);
        $data=$row;unset($data['id']);self::refundValues($data);
    }
    private static function refundValues(array $data):void
    {
        self::keys($data,array_values(array_diff(self::REFUND_FIELDS,['id'])));self::nativeRow($data);
        $root=self::integer($data['store_order_id'],1,self::INT_MAX);self::integer($data['uid'],1,self::INT_MAX);
        self::integer($data['mer_id'],1,self::UINT_MAX);self::text($data['order_id'],50,true);
        self::integer($data['refund_type'],1,6);$quantity=self::integer($data['refund_num'],1,self::INT_MAX);
        self::money($data['refund_price'],true);self::money($data['refunded_price']);
        if(bccomp($data['refunded_price'],$data['refund_price'],2)>0)self::unavailable();
        self::stored($data,['store_id'=>0,'refund_phone'=>'','refund_explain'=>'','remark'=>'','is_pink_cancel'=>0,'is_del'=>0,'is_system_del'=>0]);
        self::integer($data['is_cancel'],0,1);self::integer($data['add_time'],1,self::INT_MAX);self::integer($data['refunded_time'],0,self::INT_MAX);
        self::characters($data['refund_reason'],255,true);self::characters($data['refuse_reason'],255);
        self::characters($data['refund_express'],100);self::characters($data['refund_express_name'],255);
        if(!in_array($data['marketplace_refund_mode'],['money_only','return_goods'],true))self::unavailable();
        $images=self::decodeList($data['refund_img'],65535);if(count($images)>9)self::unavailable();$seen=[];
        foreach($images as$image){if(!is_string($image))self::unavailable();$id=self::u64($image);if(isset($seen['i:'.$id]))self::unavailable();$seen['i:'.$id]=true;}
        if(MarketplaceOrderInput::canonical($images)!==$data['refund_img'])self::unavailable();
        $items=self::decodeList($data['cart_info'],1048576);if($items===[])self::unavailable();$last=0;$sum=0;
        foreach($items as$item){
            if(!is_array($item))self::unavailable();self::keys($item,['id','product_id','product_name','sku_name','image','quantity','unit_price','line_price','postage_price']);
            if(!is_int($item['id'])||!is_int($item['product_id'])||!is_int($item['quantity']))self::unavailable();
            $id=self::integer($item['id'],1,self::INT_MAX);if($id<=$last)self::unavailable();$last=$id;
            self::integer($item['product_id'],1,self::ROOT_MAX);$sum+=self::integer($item['quantity'],1,self::INT_MAX);self::integer($sum,1,self::INT_MAX);
            foreach(['product_name','sku_name','image'] as$key)self::text($item[$key],1048576);
            foreach(['unit_price','line_price','postage_price'] as$key)self::money($item[$key]);
        }
        if($sum!==$quantity||MarketplaceOrderInput::canonical($items)!==$data['cart_info'])self::unavailable();
        $nulls=0;foreach(self::REFUND_ADDITIONS as$key)if($data[$key]===null)$nulls++;
        if($nulls===count(self::REFUND_ADDITIONS))return;if($nulls!==0)self::unavailable();
        if(self::integer($data['marketplace_root_order_id'],1,self::INT_MAX)!==$root)self::unavailable();
        self::u64($data['marketplace_payment_attempt_id']);$apply=self::u64($data['marketplace_apply_action_id']);$last=self::u64($data['marketplace_last_action_id']);
        if(bccomp($last,$apply,0)<0||!in_array($data['marketplace_kind'],['money_only','cancel_unshipped','return_goods'],true)
            ||$data['marketplace_refund_mode']!==($data['marketplace_kind']==='return_goods'?'return_goods':'money_only')
            ||!in_array($data['marketplace_state'],['REQUESTED','APPROVED','COMPLETED','WITHDRAWN','REJECTED'],true)
            ||!in_array($data['marketplace_physical_state'],['NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'],true)
            ||!in_array($data['marketplace_finance_state'],['NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED'],true))self::unavailable();
    }

    private static function movementRow(array $row):void
    {
        self::keys($row,self::MOVEMENT_FIELDS);self::nativeRow($row);self::u64($row['id']);self::u64($row['parent_reserve_id']);
        self::integer($row['mer_id'],1,self::UINT_MAX);self::integer($row['order_id'],1,self::INT_MAX);
        self::integer($row['product_id'],1,self::ROOT_MAX);self::integer($row['sku_id'],1,self::INT_MAX);self::integer($row['sku_type'],0,0);
        self::unique($row['sku_unique']);$quantity=self::integer($row['qty'],1,self::UINT_MAX);
        self::integer($row['order_item_id'],1,self::INT_MAX);self::integer($row['refund_id'],1,self::INT_MAX);
        if(!in_array($row['kind'],['release','return_in'],true)||!in_array($row['actor_type'],['employee','admin'],true))self::unavailable();
        self::integer($row['actor_id'],1,self::UINT_MAX);self::key($row['idem_key']);self::integer($row['add_time'],1,self::UINT_MAX);
        if(!is_string($row['request_digest'])||strlen($row['request_digest'])!==32)self::unavailable();
        foreach(['sku'=>self::UINT_MAX,'root'=>self::ROOT_STOCK_MAX] as$part=>$max){
            $stock=self::integer($row[$part.'_stock_before'],0,$max);$sales=self::integer($row[$part.'_sales_before'],0,$max);
            if($quantity>$max-$stock||$quantity>$sales)self::unavailable();
            self::stored($row,[$part.'_stock_after'=>$stock+$quantity,$part.'_sales_after'=>$sales-$quantity]);
        }
    }
    private static function plan(array $plan):array
    {
        if(!array_is_list($plan)||$plan===[]||count($plan)>500)self::unavailable();$out=[];$seen=[];
        foreach($plan as$entry){
            if(!is_array($entry))self::unavailable();self::keys($entry,self::PLAN_FIELDS);
            foreach(['source_item_id','product_id','sku_id','original_quantity','quantity'] as$key)if(!is_int($entry[$key]))self::unavailable();
            $id=self::integer($entry['source_item_id'],1,self::INT_MAX);if(isset($seen[$id]))self::unavailable();$seen[$id]=true;
            if(!is_string($entry['reserve_id']))self::unavailable();$reserve=self::u64($entry['reserve_id']);
            $product=self::integer($entry['product_id'],1,self::ROOT_MAX);$sku=self::integer($entry['sku_id'],1,self::INT_MAX);self::unique($entry['sku_unique']);
            $q=self::integer($entry['original_quantity'],1,self::INT_MAX);$quantity=self::integer($entry['quantity'],1,$q);
            if(!is_array($entry['segments'])||!array_is_list($entry['segments'])||$entry['segments']===[])self::unavailable();$end=0;$sum=0;
            foreach($entry['segments'] as$segment){
                if(!is_array($segment))self::unavailable();self::keys($segment,['unit_start','unit_end']);
                if(!is_int($segment['unit_start'])||!is_int($segment['unit_end']))self::unavailable();
                $first=self::integer($segment['unit_start'],0,$q-1);$last=self::integer($segment['unit_end'],$first+1,$q);
                if($first<$end)self::unavailable();$sum+=$last-$first;$end=$last;
            }
            if($sum!==$quantity)self::unavailable();
            $out[]=['source_item_id'=>$id,'reserve_id'=>$reserve,'product_id'=>$product,'sku_id'=>$sku,'sku_unique'=>$entry['sku_unique'],
                'original_quantity'=>$q,'quantity'=>$quantity,'segments'=>$entry['segments']];
        }
        usort($out,static fn(array $a,array $b):int=>($a['sku_id']<=>$b['sku_id'])?:($a['source_item_id']<=>$b['source_item_id']));return $out;
    }
    private static function creditCapacity(array $row,int $quantity,int $maximum):void
    {
        $stock=self::integer($row['stock']??null,0,$maximum);$sales=self::integer($row['sales']??null,0,$maximum);
        if($quantity<1||$quantity>$maximum-$stock||$quantity>$sales)self::unavailable();
    }

    private static function rootIdentity(array $root):array
    {
        self::nativeRow($root);self::integer($root['pid']??null,0,0);self::integer($root['marketplace_trade_version']??null,1,1);
        self::text($root['order_id']??null,32,true);
        return ['id'=>self::integer($root['id']??null,1,self::INT_MAX),'uid'=>self::integer($root['uid']??null,1,self::INT_MAX),
            'mer_id'=>self::integer($root['mer_id']??null,1,self::UINT_MAX)];
    }
    private static function roots(array $fullRoots):array
    {
        if(!array_is_list($fullRoots)||count($fullRoots)>100)self::unavailable();$out=[];
        foreach($fullRoots as$root){if(!is_array($root))self::unavailable();$id=self::rootIdentity($root);
            if(isset($out[$id['id']]))self::unavailable();$out[$id['id']]=$id;}
        return $out;
    }
    private static function actionOwner(array $row,array $root):void
    {
        self::stored($row,['root_order_id'=>$root['id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id']]);
    }
    private static function refundOwner(array $row,array $root):void
    {
        self::stored($row,['store_order_id'=>$root['id'],'marketplace_root_order_id'=>$root['id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id']]);
    }

    private function actionById(ConnectionInterface $connection,PDO $pdo,string $id):array
    {
        $this->write($connection,$pdo);$id=self::u64($id);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_aftersale_action').' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
        if(count($rows)!==1)self::unavailable();$row=self::actionRow($rows[0]);self::stored($row,['id'=>$id]);return $row;
    }
    private function refundById(ConnectionInterface $connection,PDO $pdo,int $id):array
    {
        $this->write($connection,$pdo);self::integer($id,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('store_order_refund').' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];self::refundShape($row);self::stored($row,['id'=>$id]);return $row;
    }
    private function movementById(ConnectionInterface $connection,PDO $pdo,string $id):array
    {
        $this->write($connection,$pdo);$id=self::u64($id);
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table('marketplace_stock_movement').' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];self::movementRow($row);self::stored($row,['id'=>$id]);return $row;
    }
    private function product(ConnectionInterface $connection,PDO $pdo,int $id,int $merId):array
    {
        $this->write($connection,$pdo);self::integer($id,1,self::ROOT_MAX);self::integer($merId,1,self::UINT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT id,mer_id,stock,sales FROM '.$this->table('store_product').
            ' WHERE id=? AND mer_id=? LIMIT 2 FOR UPDATE',[$id,$merId]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];self::keys($row,self::PRODUCT_FIELDS);
        self::stored($row,['id'=>$id,'mer_id'=>$merId]);self::integer($row['stock'],0,self::ROOT_STOCK_MAX);self::integer($row['sales'],0,self::ROOT_STOCK_MAX);return $row;
    }
    private function sku(ConnectionInterface $connection,PDO $pdo,int $id):array
    {
        $this->write($connection,$pdo);self::integer($id,1,self::INT_MAX);
        $rows=$this->rows($connection,$pdo,'SELECT id,product_id,type,`unique`,stock,sales FROM '.$this->table('store_product_attr_value').
            ' WHERE id=? LIMIT 2 FOR UPDATE',[$id]);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];self::keys($row,self::SKU_FIELDS);self::stored($row,['id'=>$id,'type'=>0]);
        self::integer($row['product_id'],1,self::ROOT_MAX);self::unique($row['unique']);
        self::integer($row['stock'],0,self::UINT_MAX);self::integer($row['sales'],0,self::UINT_MAX);return $row;
    }

    private function connection(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('prefix')!==$this->prefix||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }
    private function outside(ConnectionInterface $connection,PDO $pdo):void
    {
        $this->connection($connection,$pdo);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::unavailable();
    }
    private function write(ConnectionInterface $connection,PDO $pdo):void
    {
        $this->connection($connection,$pdo);if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();
    }
    private function snapshot(ConnectionInterface $connection,PDO $pdo):void
    {
        $this->connection($connection,$pdo);if($connection->transactionDepth()!==0||!$pdo->inTransaction())self::unavailable();
    }
    private function query(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):PDOStatement
    {
        $this->connection($connection,$pdo);if(!array_is_list($parameters))self::unavailable();
        $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
        try{
            foreach($parameters as$i=>$value){
                if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
                if(!$statement->bindValue($i+1,$value,$value===null?PDO::PARAM_NULL:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)))self::unavailable();
            }
            if(!$statement->execute())self::unavailable();
        }catch(Throwable $failure){try{$statement->closeCursor();}catch(Throwable $ignored){}throw $failure;}
        $this->connection($connection,$pdo);return $statement;
    }
    private function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):array
    {
        $statement=$this->query($connection,$pdo,$sql,$parameters);$rows=null;$failure=null;$closed=false;
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->connection($connection,$pdo);if($failure!==null)throw $failure;
        if($closed!==true||!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach($rows as$row){if(!is_array($row))self::unavailable();self::nativeRow($row);}return $rows;
    }
    private function change(ConnectionInterface $connection,PDO $pdo,string $sql,array $parameters):void
    {
        $this->write($connection,$pdo);$statement=$this->query($connection,$pdo,$sql,$parameters);$count=null;$failure=null;$closed=false;
        try{$count=$statement->rowCount();}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->write($connection,$pdo);if($failure!==null)throw $failure;if($closed!==true||$count!==1)self::unavailable();
    }
    private function insertNative(ConnectionInterface $connection,PDO $pdo,string $table,array $data):string
    {
        $this->write($connection,$pdo);if($data===[])self::unavailable();
        $columns=implode(',',array_map([self::class,'quote'],array_keys($data)));
        $statement=$this->query($connection,$pdo,'INSERT INTO '.$this->table($table).' ('.$columns.') VALUES ('.
            implode(',',array_fill(0,count($data),'?')).')',array_values($data));
        $id=null;$count=null;$failure=null;$closed=false;
        // Read the native connection's generated ID before any subsequent SQL.
        try{$id=$pdo->lastInsertId();$count=$statement->rowCount();}catch(Throwable $error){$failure=$error;}
        try{$closed=$statement->closeCursor();}catch(Throwable $error){if($failure===null)$failure=$error;}
        unset($statement);$this->write($connection,$pdo);if($failure!==null)throw $failure;
        if($closed!==true||$count!==1)self::unavailable();return self::u64($id);
    }
    private function table(string $name):string
    {
        if(!in_array($name,self::TABLES,true))self::unavailable();return self::quote($this->prefix.$name);
    }
    private static function quote(string $name):string
    {
        if($name===''||strlen($name)>64||preg_match('/\A[A-Za-z0-9_]+\z/D',$name)!==1)self::unavailable();return '`'.$name.'`';
    }
    private static function nativeRow(array $row):void
    {
        foreach($row as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
    }
    private static function keys(array $row,array $expected):void
    {
        if(count($row)!==count($expected)||array_diff(array_keys($row),$expected)!==[]||array_diff($expected,array_keys($row))!==[])self::unavailable();
    }
    private static function stored(array $actual,array $expected,array $jsonColumns=[]):void
    {
        $big=['id','parent_reserve_id','payment_attempt_id','previous_refund_action_id','fulfillment_cut_id',
            'marketplace_payment_attempt_id','marketplace_apply_action_id','marketplace_last_action_id'];
        $integers=['oid','change_time','contract_version','root_order_id','uid','mer_id','refund_id','actor_id','state','apply_refund_id','created_at','committed_at',
            'store_order_id','store_id','refund_type','refund_num','refunded_time','is_cancel','is_pink_cancel','is_del','add_time','is_system_del',
            'marketplace_root_order_id','product_id','sku_id','sku_type','qty','order_id','order_item_id','type','stock','sales',
            'sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before','root_stock_after','root_sales_before','root_sales_after'];
        foreach($expected as$key=>$value){
            if(!array_key_exists($key,$actual))self::unavailable();$saved=$actual[$key];
            if($value===null){if($saved!==null)self::unavailable();}
            elseif(in_array($key,$jsonColumns,true)){
                if(MarketplaceOrderInput::canonical(self::decodeObject($saved,$key==='request_payload'?65536:1048576))
                    !==MarketplaceOrderInput::canonical(self::decodeObject($value,$key==='request_payload'?65536:1048576)))self::unavailable();
            }elseif(in_array($key,$big,true)){
                if(self::u64($saved)!==self::u64($value))self::unavailable();
            }elseif(in_array($key,$integers,true)&&($key!=='order_id'||is_int($value))){
                if(self::integer($saved,0,self::UINT_MAX)!==self::integer($value,0,self::UINT_MAX))self::unavailable();
            }elseif($saved!==$value)self::unavailable();
        }
    }
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if(is_int($value))$n=$value;
        elseif(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1){
            $bound=(string)$maximum;if(strlen($value)>strlen($bound)||(strlen($value)===strlen($bound)&&strcmp($value,$bound)>0))self::unavailable();$n=(int)$value;
        }else self::unavailable();
        if($n<$minimum||$n>$maximum)self::unavailable();return $n;
    }
    private static function u64(mixed $value):string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0))self::unavailable();return $value;
    }
    private static function money(mixed $value,bool $positive=false):void
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1
            ||($positive&&$value==='0.00'))self::unavailable();
    }
    private static function unique(mixed $value):void
    {
        if(!is_string($value)||preg_match('/\A[A-Za-z0-9]{8}\z/D',$value)!==1)self::unavailable();
    }
    private static function key(mixed $value):void
    {
        if(!is_string($value)||preg_match('/\A[\x20-\x7e]{1,128}\z/D',$value)!==1||trim($value,' ')!==$value)self::unavailable();
    }
    private static function actorRef(mixed $value):void
    {
        if(!is_string($value)||strlen($value)>64||preg_match('/\A(customer|employee|admin|payment):([1-9][0-9]*)\z/D',$value,$parts)!==1)self::unavailable();
        if($parts[1]==='payment')self::u64($parts[2]);else self::integer($parts[2],1,self::UINT_MAX);
    }
    private static function text(mixed $value,int $maximum,bool $required=false):string
    {
        if(!is_string($value)||strlen($value)>$maximum||($required&&$value==='')||str_contains($value,"\0")||preg_match('//u',$value)!==1)self::unavailable();return $value;
    }
    private static function characters(mixed $value,int $maximum,bool $required=false):string
    {
        if(!is_string($value)||($required&&$value==='')||str_contains($value,"\0")||preg_match('//u',$value)!==1
            ||mb_strlen($value,'UTF-8')>$maximum)self::unavailable();return $value;
    }
    private static function encodeObject(array $value,int $maximum):string
    {
        if(array_is_list($value))self::unavailable();$json=MarketplaceOrderInput::canonical($value);
        if(strlen($json)>$maximum)self::unavailable();return $json;
    }
    private static function decodeObject(mixed $raw,int $maximum):array
    {
        $value=self::decodeJson($raw,$maximum);if(!$value instanceof stdClass)self::unavailable();
        $out=self::jsonTree($value,0);if(!is_array($out)||array_is_list($out))self::unavailable();return $out;
    }
    private static function decodeList(mixed $raw,int $maximum):array
    {
        $value=self::decodeJson($raw,$maximum);if(!is_array($value)||!array_is_list($value))self::unavailable();return self::jsonTree($value,0);
    }
    private static function decodeJson(mixed $raw,int $maximum):mixed
    {
        self::text($raw,$maximum,true);
        try{return json_decode($raw,false,64,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
    }
    /** Preserve JSON lists versus nonempty objects and reject float/big-number coercion. All fixed contract objects are nonempty. */
    private static function jsonTree(mixed $value,int $depth):mixed
    {
        if($depth>24)self::unavailable();
        if($value instanceof stdClass){
            $properties=get_object_vars($value);if($properties===[])self::unavailable();$out=[];
            foreach($properties as$key=>$item){if(!is_string($key))self::unavailable();$out[$key]=self::jsonTree($item,$depth+1);}return $out;
        }
        if(is_array($value)){
            if(!array_is_list($value))self::unavailable();$out=[];foreach($value as$item)$out[]=self::jsonTree($item,$depth+1);return $out;
        }
        if(is_string($value))return self::text($value,1048576);
        if(is_int($value)||is_bool($value)||$value===null)return $value;self::unavailable();
    }
    private static function unavailable():never {throw new AuthUnavailableException();}
}
