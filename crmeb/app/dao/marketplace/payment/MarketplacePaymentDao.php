<?php
declare(strict_types=1);

namespace app\dao\marketplace\payment;

use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\payment\MarketplacePaymentEvidence;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOException;
use PDOStatement;
use think\facade\Db;

/** Native payment facts only. The caller owns every transaction on this same default PDO. */
final class MarketplacePaymentDao
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const TABLES=['user','marketplace_order_idempotency','store_order','marketplace_merchant',
        'marketplace_payment_registry','marketplace_payment_attempt'];
    private const LEDGER_FIELDS=['uid','mer_id','idem_key','request_digest','confirmation_ref','source_kind',
        'cart_refs','state','order_id','post_commit_done','cancel_at','add_time'];
    private const ROOT_FIELDS=['id','uid','mer_id','order_id','unique','marketplace_trade_version','pid','status',
        'pay_price','paid','is_cancel','is_del','is_system_del','pay_uid','add_time'];
    private const REGISTRY_FIELDS=['order_id','uid','mer_id','payment_contract_version','born_at','attempt_generation',
        'winner_attempt_id','fulfillment_hold','hold_reason','hold_attempt_id','hold_at'];
    private const PAYMENT_ROOT_FIELDS=['id','uid','mer_id','order_id','unique','marketplace_trade_version','pid','cart_id',
        'status','refund_status','pay_price','paid','is_cancel','is_del','is_system_del','pay_uid','add_time','pay_type','pay_time','trade_no'];
    private const PAYMENT_MERCHANT_FIELDS=['mer_id','review_status','accept_orders','security_locked','closed_at','fulfillment_enabled'];
    private const ATTEMPT_FIELDS=['id','order_id','uid','mer_id','payment_contract_version','admitted_generation','order_no',
        'idem_key','amount','currency','account_snapshot','request_digest','binding_digest','account_domain_digest',
        'created_at','attempt_ref','applied_trade_no','evidence'];
    private const ITEM_FIELDS=['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num','split_status','cart_info','unique'];
    private const MOVEMENT_FIELDS=['id','mer_id','order_id','product_id','sku_id','sku_type','sku_unique','qty','kind',
        'idem_key','parent_reserve_id','order_item_id','refund_id','actor_type','actor_id','request_digest',
        'sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before',
        'root_stock_after','root_sales_before','root_sales_after','add_time'];
    private const CHILD_ORDER_FIELDS=['id','uid','mer_id','pid','marketplace_trade_version','order_id','paid','status','refund_status','is_cancel'];
    private const HOLD_REASONS=['BINDING_MISMATCH','CLOSED_THEN_SUCCESS','SUCCESS_REF_CHANGED','OTHER_WINNER',
        'ROOT_CANCELLED','RESERVE_RELEASED','MERCHANT_UNAVAILABLE','FULFILLMENT_DISABLED','ROOT_PAYMENT_MISMATCH',
        'EVIDENCE_CAPACITY','FACT_KEY_COLLISION'];

    private string $prefix;

    public function __construct()
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        foreach(self::TABLES as $table)if(strlen($prefix.$table)>64)self::unavailable();
        $this->prefix=$prefix;
    }

    /** Only insertOrder's real fresh path calls this; an existing registry is never an upsert/replay opportunity. */
    public function birth(PDO $pdo,int $uid,string $creatingIdem,int $orderId,int $bornAt): array
    {
        $fresh=$this->fresh($pdo,$uid,$creatingIdem,$orderId);
        self::integer($bornAt,1,self::UINT_MAX);
        if($bornAt!==$fresh['root']['add_time'])self::unavailable();
        if($this->one($pdo,'marketplace_payment_registry',self::REGISTRY_FIELDS,'order_id = ?',[$orderId])!==null)self::unavailable();
        $this->assertNoAttempts($pdo,$orderId);
        $expected=self::initialRegistry($fresh);
        $columns=implode(',',array_map(self::column(...),self::REGISTRY_FIELDS));
        $statement=$this->execute($pdo,'INSERT INTO '.$this->table('marketplace_payment_registry').' ('.$columns.') VALUES ('.
            implode(',',array_fill(0,count(self::REGISTRY_FIELDS),'?')).')',array_values($expected));
        if($statement->rowCount()!==1)self::unavailable();
        // Its PK is the actual root PK. No lastInsertId, defaults, duplicate swallowing or later backfill.
        return $this->freshRegistry($pdo,$fresh);
    }

    /** Called again before cart consumption / ledger state1; missing birth is a failure, never a repair. */
    public function assertFreshBirth(PDO $pdo,int $uid,string $creatingIdem,int $orderId): array
    {
        return $this->freshRegistry($pdo,$this->fresh($pdo,$uid,$creatingIdem,$orderId));
    }

    /** Existing fresh creation already holds these exact customer/ledger/merchant rows in this transaction. */
    private function fresh(PDO $pdo,int $uid,string $creatingIdem,int $orderId): array
    {
        $this->transaction($pdo);self::integer($uid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        self::creatingIdem($creatingIdem);
        $customer=$this->one($pdo,'user',['uid'],'uid = ?',[$uid]);
        if($customer===null||self::integer($customer['uid'],1,self::INT_MAX)!==$uid)self::unavailable();
        $ledger=$this->one($pdo,'marketplace_order_idempotency',self::LEDGER_FIELDS,'uid = ? AND idem_key = ?',[$uid,$creatingIdem]);
        if($ledger===null||self::integer($ledger['uid'],1,self::INT_MAX)!==$uid||$ledger['idem_key']!==$creatingIdem
            ||self::integer($ledger['state'],0,1)!==0||$ledger['order_id']!==null
            ||self::integer($ledger['post_commit_done'],0,1)!==0)self::unavailable();
        $merId=self::integer($ledger['mer_id'],1,self::UINT_MAX);
        foreach(['request_digest','confirmation_ref'] as $field){
            if(!is_string($ledger[$field])||strlen($ledger[$field])!==32)self::unavailable();
        }
        if(!is_string($ledger['source_kind'])||!in_array($ledger['source_kind'],['persistent','temporary'],true))self::unavailable();
        self::cartRefs($ledger['cart_refs'],$ledger['source_kind']);
        $ledger['add_time']=self::integer($ledger['add_time'],1,self::UINT_MAX);
        self::integer($ledger['cancel_at'],0,self::UINT_MAX);
        $root=$this->one($pdo,'store_order',self::ROOT_FIELDS,'id = ? AND uid = ? AND mer_id = ?',[$orderId,$uid,$merId]);
        if($root===null)self::unavailable();
        foreach(['id'=>[$orderId,self::INT_MAX],'uid'=>[$uid,self::INT_MAX],'mer_id'=>[$merId,self::UINT_MAX],
            'marketplace_trade_version'=>[1,1],'pay_uid'=>[$uid,self::INT_MAX]] as $field=>[$expected,$maximum]){
            if(self::integer($root[$field],1,$maximum)!==$expected)self::unavailable();
            $root[$field]=$expected;
        }
        foreach(['pid','status','paid','is_cancel','is_del','is_system_del'] as $field){
            if(self::integer($root[$field],0,0)!==0)self::unavailable();$root[$field]=0;
        }
        self::rootText($root['order_id']);self::rootText($root['unique']);
        if(!is_string($root['pay_price'])||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$root['pay_price'])!==1)self::unavailable();
        $root['add_time']=self::integer($root['add_time'],1,self::UINT_MAX);
        if($root['add_time']!==$ledger['add_time']||!hash_equals($ledger['confirmation_ref'],hash('sha256',$root['unique'],true)))self::unavailable();
        $merchant=$this->one($pdo,'marketplace_merchant',['mer_id'],'mer_id = ?',[$merId]);
        if($merchant===null||self::integer($merchant['mer_id'],1,self::UINT_MAX)!==$merId)self::unavailable();
        return ['root'=>$root,'ledger'=>$ledger];
    }

    private static function initialRegistry(array $fresh): array
    {
        $root=$fresh['root'];
        return ['order_id'=>$root['id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],'payment_contract_version'=>1,
            'born_at'=>$root['add_time'],'attempt_generation'=>'0','winner_attempt_id'=>null,'fulfillment_hold'=>0,
            'hold_reason'=>null,'hold_attempt_id'=>null,'hold_at'=>null];
    }

    private function freshRegistry(PDO $pdo,array $fresh): array
    {
        $expected=self::initialRegistry($fresh);
        $row=$this->one($pdo,'marketplace_payment_registry',self::REGISTRY_FIELDS,'order_id = ?',[$expected['order_id']]);
        if($row===null)self::unavailable();
        foreach(['order_id'=>self::INT_MAX,'uid'=>self::INT_MAX,'mer_id'=>self::UINT_MAX,
            'payment_contract_version'=>1,'born_at'=>self::UINT_MAX] as $field=>$maximum){
            $row[$field]=self::integer($row[$field],1,$maximum);
        }
        $row['attempt_generation']=self::u64($row['attempt_generation']);
        $row['fulfillment_hold']=self::integer($row['fulfillment_hold'],0,1);
        if($row!==$expected)self::unavailable();
        $this->assertNoAttempts($pdo,$expected['order_id']);
        return $row;
    }

    private function assertNoAttempts(PDO $pdo,int $orderId): void
    {
        // This is an emptiness proof, not a truncated nonempty generation snapshot. Any actual row fails.
        if($this->rows($pdo,'marketplace_payment_attempt',['id'],'order_id = ?',[$orderId],'id ASC',1)!==[])self::unavailable();
    }

    /** Autocommit owner locator only; it grants neither a lock nor a fresh-create opportunity. */
    public function customerPaymentCandidate(PDO $pdo,int $uid,string $orderNo): array
    {
        self::integer($uid,1,self::INT_MAX);self::paymentText($orderNo,32,false);$this->outside($pdo);
        $statement=$this->prepared($pdo,'SELECT `id`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version` FROM '.
            $this->table('store_order').' WHERE `uid` = ? AND `order_id` = ? LIMIT 2',[$uid,$orderNo]);
        $rows=$this->fetched($statement);
        if(count($rows)!==1)self::unavailable();$row=$rows[0];
        if(array_keys($row)!==['id','uid','mer_id','order_id','marketplace_trade_version']
            ||self::integer($row['uid'],1,self::INT_MAX)!==$uid||$row['order_id']!==$orderNo
            ||self::integer($row['marketplace_trade_version'],1,1)!==1)self::unavailable();
        return ['uid'=>$uid,'order_id'=>self::integer($row['id'],1,self::INT_MAX),
            'mer_id'=>self::integer($row['mer_id'],1,self::UINT_MAX),'order_no'=>$orderNo];
    }

    /** UID -> completed creation ledger -> actual root -> merchant -> registry -> every native-id-ordered attempt. */
    public function lockPayment(PDO $pdo,int $uid,int $orderId,string $orderNo): array
    {
        $observed=$this->lockObservation($pdo,$uid,$orderId,$orderNo);
        if(!$observed['root_payment_consistent'])self::unavailable();return $observed['locked'];
    }

    /** The observation path tolerates only a mismatch of the root's four payment values, never broken intent history. */
    public function lockObservation(PDO $pdo,int $uid,int $orderId,string $orderNo): array
    {
        $this->transaction($pdo);
        if(Db::connect()->getConfig('deploy')!==0||Db::connect()->getConfig('rw_separate')!==false)self::unavailable();
        self::integer($uid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        self::paymentText($orderNo,32,false);
        $customer=$this->one($pdo,'user',['uid'],'uid = ?',[$uid]);
        if($customer===null||self::integer($customer['uid'],1,self::INT_MAX)!==$uid)self::unavailable();
        $ledger=$this->one($pdo,'marketplace_order_idempotency',self::LEDGER_FIELDS,'uid = ? AND order_id = ?',[$uid,$orderId]);
        if($ledger===null)self::unavailable();
        foreach(['uid'=>self::INT_MAX,'mer_id'=>self::UINT_MAX,'order_id'=>self::INT_MAX,'add_time'=>self::UINT_MAX] as $field=>$maximum)
            $ledger[$field]=self::integer($ledger[$field],1,$maximum);
        if($ledger['uid']!==$uid||$ledger['order_id']!==$orderId||self::integer($ledger['state'],1,1)!==1)self::unavailable();
        $ledger['state']=1;$ledger['post_commit_done']=self::integer($ledger['post_commit_done'],0,1);
        $ledger['cancel_at']=self::integer($ledger['cancel_at'],0,self::UINT_MAX);
        if(!is_string($ledger['idem_key']))self::unavailable();self::creatingIdem($ledger['idem_key']);
        foreach(['request_digest','confirmation_ref'] as $field)
            if(!is_string($ledger[$field])||strlen($ledger[$field])!==32)self::unavailable();
        if(!in_array($ledger['source_kind'],['persistent','temporary'],true))self::unavailable();
        self::cartRefs($ledger['cart_refs'],$ledger['source_kind']);$refs=self::paymentJson($ledger['cart_refs']);
        $root=$this->one($pdo,'store_order',self::PAYMENT_ROOT_FIELDS,'id = ? AND uid = ? AND mer_id = ?',[$orderId,$uid,$ledger['mer_id']]);
        if($root===null)self::unavailable();
        foreach(['id'=>self::INT_MAX,'uid'=>self::INT_MAX,'mer_id'=>self::UINT_MAX,'pay_uid'=>self::INT_MAX,'add_time'=>self::UINT_MAX] as $field=>$maximum)
            $root[$field]=self::integer($root[$field],1,$maximum);
        foreach(['paid','is_cancel','is_del','is_system_del'] as $field)$root[$field]=self::integer($root[$field],0,1);
        $root['marketplace_trade_version']=self::integer($root['marketplace_trade_version'],1,1);
        $root['pid']=self::integer($root['pid'],0,0);$root['refund_status']=self::integer($root['refund_status'],0,255);
        $root['status']=self::paymentStatus($root['status']);$root['pay_time']=self::integer($root['pay_time'],0,self::UINT_MAX);
        self::paymentText($root['order_id'],32,false);self::rootText($root['unique']);
        self::paymentText($root['pay_type'],32,true);self::rootTextAllowance($root['trade_no'],100);
        self::paymentAmount($root['pay_price'],false);
        if($root['id']!==$orderId||$root['uid']!==$uid||$root['mer_id']!==$ledger['mer_id']||$root['pay_uid']!==$uid
            ||$root['order_id']!==$orderNo||$root['add_time']!==$ledger['add_time']
            ||!hash_equals($ledger['confirmation_ref'],hash('sha256',$root['unique'],true))
            ||self::paymentJson($root['cart_id'])!==$refs)self::unavailable();
        $merchant=$this->one($pdo,'marketplace_merchant',self::PAYMENT_MERCHANT_FIELDS,'mer_id = ?',[$root['mer_id']]);
        if($merchant===null)self::unavailable();
        $merchant['mer_id']=self::integer($merchant['mer_id'],1,self::UINT_MAX);
        $merchant['review_status']=self::integer($merchant['review_status'],0,255);
        foreach(['accept_orders','security_locked','fulfillment_enabled'] as $field)$merchant[$field]=self::integer($merchant[$field],0,1);
        if($merchant['closed_at']!==null)$merchant['closed_at']=self::integer($merchant['closed_at'],1,self::UINT_MAX);
        if($merchant['mer_id']!==$root['mer_id'])self::unavailable();
        $registry=$this->one($pdo,'marketplace_payment_registry',self::REGISTRY_FIELDS,'order_id = ?',[$orderId]);
        if($registry===null)self::unavailable(); // Historical roots are never backfilled here.
        foreach(['order_id'=>self::INT_MAX,'uid'=>self::INT_MAX,'mer_id'=>self::UINT_MAX,'born_at'=>self::UINT_MAX] as $field=>$maximum)
            $registry[$field]=self::integer($registry[$field],1,$maximum);
        $registry['payment_contract_version']=self::integer($registry['payment_contract_version'],1,1);
        $registry['attempt_generation']=self::u64($registry['attempt_generation']);
        $registry['fulfillment_hold']=self::integer($registry['fulfillment_hold'],0,1);
        foreach(['winner_attempt_id','hold_attempt_id'] as $field)if($registry[$field]!==null)$registry[$field]=self::positiveU64($registry[$field]);
        if($registry['order_id']!==$orderId||$registry['uid']!==$uid||$registry['mer_id']!==$root['mer_id']
            ||$registry['born_at']!==$root['add_time'])self::unavailable();
        if($registry['fulfillment_hold']===0){
            foreach(['hold_reason','hold_attempt_id','hold_at'] as $field)if($registry[$field]!==null)self::unavailable();
        }else{
            if(!is_string($registry['hold_reason'])||!in_array($registry['hold_reason'],self::HOLD_REASONS,true))self::unavailable();
            $registry['hold_at']=self::integer($registry['hold_at'],1,self::UINT_MAX);
        }
        $attempts=$this->rows($pdo,'marketplace_payment_attempt',self::ATTEMPT_FIELDS,'order_id = ?',[$orderId],'id ASC');
        foreach($attempts as &$row){
            $row['id']=self::positiveU64($row['id']);$row['admitted_generation']=self::positiveU64($row['admitted_generation']);
            foreach(['order_id'=>self::INT_MAX,'uid'=>self::INT_MAX,'mer_id'=>self::UINT_MAX,'created_at'=>self::UINT_MAX] as $field=>$maximum)
                $row[$field]=self::integer($row[$field],1,$maximum);
            $row['payment_contract_version']=self::integer($row['payment_contract_version'],1,1);
            foreach(['account_snapshot','evidence'] as $field)$row[$field]=self::paymentJson($row[$field]);
            if($row['order_id']!==$orderId||$row['uid']!==$uid||$row['mer_id']!==$root['mer_id']
                ||$row['order_no']!==$orderNo||$row['amount']!==$root['pay_price']||$row['currency']!=='CNY')self::unavailable();
        }
        unset($row);
        $locked=['ledger'=>$ledger,'root'=>$root,'merchant'=>$merchant,'registry'=>$registry,'attempts'=>$attempts];
        $consistent=self::paymentCollection($locked);
        // Exact nested schemas/types are checked above. Canonical key order now makes native JSON readback comparisons stable.
        foreach($locked['attempts'] as &$row)
            foreach(['account_snapshot','evidence'] as $field)$row[$field]=self::canonicalValue($row[$field]);
        unset($row);
        return ['locked'=>$locked,'root_payment_consistent'=>$consistent];
    }

    /** A fresh intent is written only here; same-key reuse precedes every new-admission predicate and overflow check. */
    public function admit(PDO $pdo,int $uid,int $orderId,string $orderNo,string $idem,array $account): array
    {
        self::paymentIdem($idem);
        $locked=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        foreach($locked['attempts'] as $row)if($row['idem_key']===$idem)return ['new'=>false,'locked'=>$locked,'attempt_id'=>$row['id']];
        // The actual admission second is taken after all potentially waiting locks, never before the lock-read.
        $now=time();self::integer($now,1,self::UINT_MAX);
        $root=$locked['root'];$merchant=$locked['merchant'];$registry=$locked['registry'];
        foreach(['paid','is_cancel','is_del','is_system_del','status','refund_status'] as $field)if($root[$field]!==0)self::unavailable();
        if($registry['fulfillment_hold']!==0||$registry['winner_attempt_id']!==null
            ||$merchant['review_status']!==1||$merchant['accept_orders']!==1||$merchant['security_locked']!==0||$merchant['closed_at']!==null
            ||($locked['ledger']['cancel_at']!==0&&$now>=$locked['ledger']['cancel_at']))self::unavailable();
        self::paymentAmount($root['pay_price'],true);
        foreach($locked['attempts'] as $row){
            $evidence=$row['evidence'];
            if($evidence['first_closed']===null||$evidence['first_succeeded']!==null||$evidence['application']!==null)self::unavailable();
        }
        $binding=self::paymentBinding($root,$account);
        if($registry['attempt_generation']==='18446744073709551615')self::unavailable();
        $generation=bcadd($registry['attempt_generation'],'1',0);self::positiveU64($generation);
        $expected=['order_id'=>$orderId,'uid'=>$uid,'mer_id'=>$root['mer_id'],'payment_contract_version'=>1,
            'admitted_generation'=>$generation,'order_no'=>$root['order_id'],'idem_key'=>$idem,'amount'=>$root['pay_price'],
            'currency'=>'CNY','account_snapshot'=>self::canonicalValue($account),
            'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical(['binding'=>$binding,'idempotency_key'=>$idem]),true),
            'binding_digest'=>hash('sha256',MarketplaceOrderInput::canonical($binding),true),
            'account_domain_digest'=>hash('sha256',MarketplaceOrderInput::canonical(['source'=>$account['source'],
                'channel'=>$account['channel'],'account_ref'=>$account['account_ref']]),true),
            'created_at'=>$now,'attempt_ref'=>null,'applied_trade_no'=>null,'evidence'=>self::canonicalValue(MarketplacePaymentEvidence::initial())];
        $fields=array_slice(self::ATTEMPT_FIELDS,1);$values=[];
        foreach($fields as $field)$values[]=in_array($field,['account_snapshot','evidence'],true)
            ?MarketplaceOrderInput::canonical($expected[$field]):$expected[$field];
        $insert=$this->execute($pdo,'INSERT INTO '.$this->table('marketplace_payment_attempt').' ('.
            implode(',',array_map(self::column(...),$fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')',$values);
        if($insert->rowCount()!==1)self::unavailable();$attemptId=self::positiveU64($pdo->lastInsertId());
        $update=$this->execute($pdo,'UPDATE '.$this->table('marketplace_payment_registry').
            ' SET `attempt_generation` = ? WHERE `order_id` = ? AND `uid` = ? AND `mer_id` = ? AND `attempt_generation` = ?',
            [$generation,$orderId,$uid,$root['mer_id'],$registry['attempt_generation']]);
        if($update->rowCount()!==1)self::unavailable();
        $readback=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        $expectedRegistry=$registry;$expectedRegistry['attempt_generation']=$generation;
        $expectedAttempts=$locked['attempts'];$expectedAttempts[]=['id'=>$attemptId]+$expected;
        if($readback['registry']!==$expectedRegistry||$readback['attempts']!==$expectedAttempts
            ||$readback['root']!==$locked['root']||$readback['ledger']!==$locked['ledger']||$readback['merchant']!==$merchant)self::unavailable();
        return ['new'=>true,'locked'=>$readback,'attempt_id'=>$attemptId];
    }

    /** Only the service passes its actual create return. No query/application/winner/paid writes occur in this slice. */
    public function saveCreateResult(PDO $pdo,int $uid,int $orderId,string $orderNo,string $attemptId,array $original,array $result,int $now): array
    {
        self::integer($now,1,self::UINT_MAX);$attemptId=self::positiveU64($attemptId);
        $locked=$this->lockPayment($pdo,$uid,$orderId,$orderNo);$position=self::attemptPosition($locked,$attemptId);
        $row=$locked['attempts'][$position];self::sameIntent($row,$original);
        $savedCreate=self::createResult($row);
        if($savedCreate!==null){
            if(MarketplaceOrderInput::canonical($savedCreate)!==MarketplaceOrderInput::canonical($result))self::unavailable();
            return $locked;
        }
        // This validates the entire six-key result before any provider reference is considered for binding.
        MarketplacePaymentEvidence::observe(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId,'createPayment',$result,$now);
        $binding=self::paymentBinding($locked['root'],$row['account_snapshot']);
        $qualified=$result['state']!=='unknown'&&$result['binding_sha256']===bin2hex($row['binding_digest'])
            &&MarketplaceOrderInput::canonical($result['binding'])===MarketplaceOrderInput::canonical($binding)
            &&($row['attempt_ref']===null||$row['attempt_ref']===$result['attempt_ref']);
        if($qualified)$locked['attempts'][$position]['attempt_ref']=$result['attempt_ref'];
        $evidence=MarketplacePaymentEvidence::observe(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId,'createPayment',$result,$now);
        $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);$reason=null;
        if($result['state']!=='unknown'&&!$qualified){
            $fact=self::createFact($locked['attempts'][$position]);
            $evidence=MarketplacePaymentEvidence::exception(self::evidenceRegistry($locked['registry']),$locked['attempts'],
                $attemptId,'BINDING_MISMATCH',$fact['key'],null,$now);
            $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);$reason='BINDING_MISMATCH';
        }
        $target=$locked['attempts'][$position];
        $encoded=MarketplacePaymentEvidence::encode(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId);
        $update=$this->execute($pdo,'UPDATE '.$this->table('marketplace_payment_attempt').
            ' SET `attempt_ref` = ?, `evidence` = ? WHERE `id` = ? AND `order_id` = ? AND `uid` = ? AND `mer_id` = ?',
            [$target['attempt_ref'],$encoded,$attemptId,$orderId,$uid,$row['mer_id']]);
        if($update->rowCount()!==1)self::unavailable();
        if($reason!==null)$locked['registry']=$this->writeFirstHold($pdo,$locked,$reason,$attemptId,$now);
        $readback=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        if($readback!==$locked)self::unavailable();return $readback;
    }

    /** A bounded fallback only after the service has rolled back an unsavable observation on this same PDO. */
    public function holdEvidenceFailure(PDO $pdo,int $uid,int $orderId,string $orderNo,string $attemptId,array $original,string $reason,int $now): array
    {
        if(!in_array($reason,['EVIDENCE_CAPACITY','FACT_KEY_COLLISION'],true))self::unavailable();
        self::integer($now,1,self::UINT_MAX);$attemptId=self::positiveU64($attemptId);
        $locked=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        self::sameIntent($locked['attempts'][self::attemptPosition($locked,$attemptId)],$original);
        $locked['registry']=$this->writeFirstHold($pdo,$locked,$reason,$attemptId,$now);
        $readback=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        if($readback!==$locked)self::unavailable();return $readback;
    }

    /** Persist only the actual query/close return, with application facts read in this same locked transaction. */
    public function saveObservation(PDO $pdo,int $uid,int $orderId,string $orderNo,string $attemptId,array $original,
        string $op,array $result,int $now,?array $applicationFacts): array
    {
        if(!in_array($op,['queryPayment','closePayment'],true))self::unavailable();
        self::integer($now,1,self::UINT_MAX);$attemptId=self::positiveU64($attemptId);
        $observed=$this->lockObservation($pdo,$uid,$orderId,$orderNo);$locked=$observed['locked'];$before=$locked;
        $position=self::attemptPosition($locked,$attemptId);$row=$locked['attempts'][$position];self::sameIntent($row,$original);
        $create=self::createResult($row);
        if($row['attempt_ref']===null||$original['attempt_ref']!==$row['attempt_ref']||$create===null||!self::qualified($create,$row))self::unavailable();
        $evidence=MarketplacePaymentEvidence::observe(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId,$op,$result,$now);
        $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);
        $fact=self::observationFor($locked['attempts'][$position],$op,$result);$reason=null;
        $qualified=self::qualified($result,$row);
        if($result['state']!=='unknown'&&!$qualified){
            self::appendException($locked,$position,'BINDING_MISMATCH',$fact['key'],null,$now);$reason='BINDING_MISMATCH';
        }
        $firstKey=$locked['attempts'][$position]['evidence']['first_succeeded'];
        if($result['state']==='succeeded'&&$firstKey!==null){
            $first=self::observationKey($locked['attempts'][$position],$firstKey);
            if(self::sameResultBinding($result,$row)&&$result['provider_trade_no']!==$first['fact']['result']['provider_trade_no']){
                self::appendException($locked,$position,'SUCCESS_REF_CHANGED',$fact['key'],$firstKey,$now);
                $reason??='SUCCESS_REF_CHANGED';$qualified=false;
            }
        }
        if($qualified&&$result['state']==='succeeded'){
            if($applicationFacts===null)self::unavailable();$inventory=self::applicationState($applicationFacts,$locked);
            $evidence=$locked['attempts'][$position]['evidence'];$closedKey=$evidence['first_closed'];
            if($closedKey!==null&&self::factPosition($locked['attempts'][$position],$closedKey)<self::factPosition($locked['attempts'][$position],$fact['key'])){
                self::appendException($locked,$position,'CLOSED_THEN_SUCCESS',$fact['key'],$closedKey,$now);$reason??='CLOSED_THEN_SUCCESS';
            }
            $winnerId=$locked['registry']['winner_attempt_id'];$sameWinner=$winnerId===$attemptId;
            if(!$observed['root_payment_consistent']){
                self::appendException($locked,$position,'ROOT_PAYMENT_MISMATCH',$fact['key'],null,$now);$reason??='ROOT_PAYMENT_MISMATCH';
            }
            if($winnerId!==null&&!$sameWinner){
                $winner=$locked['attempts'][self::attemptPosition($locked,$winnerId)];
                self::appendException($locked,$position,'OTHER_WINNER',$fact['key'],$winner['evidence']['application']['fact'],$now);
                $reason??='OTHER_WINNER';
            }
            if(!$sameWinner){
                if($locked['root']['is_cancel']===1){
                    self::appendException($locked,$position,'ROOT_CANCELLED',$fact['key'],null,$now);$reason??='ROOT_CANCELLED';
                }
                if($inventory['released']){
                    self::appendException($locked,$position,'RESERVE_RELEASED',$fact['key'],null,$now);$reason??='RESERVE_RELEASED';
                }
                $normal=$locked['root']['paid']===0&&$locked['root']['is_cancel']===0&&$locked['root']['is_del']===0
                    &&$locked['root']['is_system_del']===0&&$locked['root']['status']===0&&$locked['root']['refund_status']===0
                    &&!$inventory['released']&&!$inventory['changed'];
                if($locked['root']['is_del']!==0||$locked['root']['is_system_del']!==0||$locked['root']['status']!==0
                    ||$locked['root']['refund_status']!==0||$inventory['changed']){
                    self::appendException($locked,$position,'ROOT_PAYMENT_MISMATCH',$fact['key'],null,$now);$reason??='ROOT_PAYMENT_MISMATCH';
                }
                $siblingsClosed=true;
                foreach($locked['attempts'] as $sibling)if($sibling['id']!==$attemptId
                    &&($sibling['evidence']['first_closed']===null||$sibling['evidence']['first_succeeded']!==null||$sibling['evidence']['application']!==null))$siblingsClosed=false;
                if(!$siblingsClosed&&!self::hasLateSuccess($locked))self::unavailable();
                if($op==='queryPayment'&&$winnerId===null&&$observed['root_payment_consistent']&&$normal&&$siblingsClosed){
                    $evidence=MarketplacePaymentEvidence::apply(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId,$fact['key'],$now);
                    $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);
                    $locked['attempts'][$position]['applied_trade_no']=$result['provider_trade_no'];
                    $locked['registry']['winner_attempt_id']=$attemptId;
                    foreach($evidence['application']['root_payment'] as $field=>$value)$locked['root'][$field]=$value;
                }
            }
            $merchant=$locked['merchant'];
            if($merchant['review_status']!==1||$merchant['security_locked']!==0||$merchant['closed_at']!==null){
                self::appendException($locked,$position,'MERCHANT_UNAVAILABLE',$fact['key'],null,$now);$reason??='MERCHANT_UNAVAILABLE';
            }
            if($merchant['fulfillment_enabled']!==1){
                self::appendException($locked,$position,'FULFILLMENT_DISABLED',$fact['key'],null,$now);$reason??='FULFILLMENT_DISABLED';
            }
        }elseif($qualified&&$result['state']==='closed'&&$firstKey!==null){
            // A known closed result cannot erase an earlier known success or its application.
            self::appendException($locked,$position,'ROOT_PAYMENT_MISMATCH',$firstKey,$fact['key'],$now);$reason??='ROOT_PAYMENT_MISMATCH';
        }
        $target=$locked['attempts'][$position];
        $encoded=MarketplacePaymentEvidence::encode(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId);
        $this->writeObservation($pdo,$row,$target,$encoded);
        if($locked['registry']['winner_attempt_id']!==$before['registry']['winner_attempt_id']){
            $update=$this->execute($pdo,'UPDATE '.$this->table('marketplace_payment_registry').
                ' SET `winner_attempt_id` = ? WHERE `order_id` = ? AND `uid` = ? AND `mer_id` = ?'.
                ' AND `attempt_generation` = ? AND `winner_attempt_id` IS NULL',
                [$attemptId,$orderId,$uid,$row['mer_id'],$locked['registry']['attempt_generation']]);
            if($update->rowCount()!==1)self::unavailable();
            $old=$before['root'];$payment=$locked['attempts'][$position]['evidence']['application']['root_payment'];
            $update=$this->execute($pdo,'UPDATE '.$this->table('store_order').
                ' SET `paid` = ?, `pay_time` = ?, `pay_type` = ?, `trade_no` = ?'.
                ' WHERE `id` = ? AND `uid` = ? AND `mer_id` = ? AND `marketplace_trade_version` = 1 AND `pid` = 0'.
                ' AND `paid` = 0 AND `is_cancel` = 0 AND `status` = 0 AND `refund_status` = 0 AND `is_del` = 0 AND `is_system_del` = 0'.
                ' AND `pay_time` = ? AND BINARY `pay_type` = ? AND BINARY `trade_no` = ? AND `pay_price` = ?',
                [$payment['paid'],$payment['pay_time'],$payment['pay_type'],$payment['trade_no'],$orderId,$uid,$row['mer_id'],
                    $old['pay_time'],$old['pay_type'],$old['trade_no'],$old['pay_price']]);
            if($update->rowCount()!==1)self::unavailable();
        }
        if($reason!==null)$locked['registry']=$this->writeFirstHold($pdo,$locked,$reason,$attemptId,$now);
        $readback=$this->lockObservation($pdo,$uid,$orderId,$orderNo);
        if($readback['locked']!==$locked)self::unavailable();return $readback;
    }

    /** Only the service's classified, rolled-back first application enters here; it never retries application. */
    public function saveAppliedTradeConflict(PDO $pdo,int $uid,int $orderId,string $orderNo,string $attemptId,array $original,
        array $result,int $now,?array $initialPayment,array $applicationFacts): array
    {
        self::integer($now,1,self::UINT_MAX);$attemptId=self::positiveU64($attemptId);
        $observed=$this->lockObservation($pdo,$uid,$orderId,$orderNo);$locked=$observed['locked'];
        if($initialPayment!==null)self::sameCancellationSnapshot($initialPayment,$locked);
        $position=self::attemptPosition($locked,$attemptId);$row=$locked['attempts'][$position];self::sameIntent($row,$original);
        $create=self::createResult($row);
        if($row['attempt_ref']===null||$original['attempt_ref']!==$row['attempt_ref']||$create===null||!self::qualified($create,$row))self::unavailable();
        $evidence=MarketplacePaymentEvidence::observe(self::evidenceRegistry($locked['registry']),$locked['attempts'],
            $attemptId,'queryPayment',$result,$now);
        if($result['state']!=='succeeded'||!self::qualified($result,$row))self::unavailable();
        $inventory=self::applicationState($applicationFacts,$locked);
        $this->assertAppliedTradeConflict($pdo,$row,$result['provider_trade_no']);
        $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);
        $fact=self::observationFor($locked['attempts'][$position],'queryPayment',$result);
        self::appendException($locked,$position,'ROOT_PAYMENT_MISMATCH',$fact['key'],null,$now);
        $closedKey=$locked['attempts'][$position]['evidence']['first_closed'];
        if($closedKey!==null&&self::factPosition($locked['attempts'][$position],$closedKey)<self::factPosition($locked['attempts'][$position],$fact['key']))
            self::appendException($locked,$position,'CLOSED_THEN_SUCCESS',$fact['key'],$closedKey,$now);
        $winnerId=$locked['registry']['winner_attempt_id'];
        if($winnerId!==null&&$winnerId!==$attemptId){
            $winner=$locked['attempts'][self::attemptPosition($locked,$winnerId)];
            self::appendException($locked,$position,'OTHER_WINNER',$fact['key'],$winner['evidence']['application']['fact'],$now);
        }
        if($locked['root']['is_cancel']===1)self::appendException($locked,$position,'ROOT_CANCELLED',$fact['key'],null,$now);
        if($inventory['released'])self::appendException($locked,$position,'RESERVE_RELEASED',$fact['key'],null,$now);
        $merchant=$locked['merchant'];
        if($merchant['review_status']!==1||$merchant['security_locked']!==0||$merchant['closed_at']!==null)
            self::appendException($locked,$position,'MERCHANT_UNAVAILABLE',$fact['key'],null,$now);
        if($merchant['fulfillment_enabled']!==1)self::appendException($locked,$position,'FULFILLMENT_DISABLED',$fact['key'],null,$now);
        $encoded=MarketplacePaymentEvidence::encode(self::evidenceRegistry($locked['registry']),$locked['attempts'],$attemptId);
        $this->writeObservation($pdo,$row,$locked['attempts'][$position],$encoded);
        $locked['registry']=$this->writeFirstHold($pdo,$locked,'ROOT_PAYMENT_MISMATCH',$attemptId,$now);
        $readback=$this->lockObservation($pdo,$uid,$orderId,$orderNo);
        if($readback['locked']!==$locked)self::unavailable();return $readback;
    }

    /** A primary consistent read only: no other root's lock, identity, SQL failure text or fact enters this root's evidence. */
    private function assertAppliedTradeConflict(PDO $pdo,array $row,string $trade): void
    {
        $this->transaction($pdo);
        $fields=['id','account_snapshot','account_domain_digest','applied_trade_no'];
        $statement=$this->prepared($pdo,'SELECT '.implode(',',array_map(self::column(...),$fields)).
            ' FROM '.$this->table('marketplace_payment_attempt').
            ' WHERE `account_domain_digest` = ? AND `applied_trade_no` = ? LIMIT 2',[$row['account_domain_digest'],$trade]);
        $rows=$this->fetched($statement);
        if(count($rows)!==1||!is_array($rows[0])||array_keys($rows[0])!==$fields)self::unavailable();$other=$rows[0];
        if(self::positiveU64($other['id'])===$row['id']||$other['applied_trade_no']!==$trade
            ||!is_string($other['account_domain_digest'])||!hash_equals($row['account_domain_digest'],$other['account_domain_digest']))self::unavailable();
        $account=self::paymentJson($other['account_snapshot']);self::paymentAccount($account);$domain=[];
        foreach(['source','channel','account_ref'] as $field){
            if($account[$field]!==$row['account_snapshot'][$field])self::unavailable();$domain[$field]=$account[$field];
        }
        if(!hash_equals($other['account_domain_digest'],hash('sha256',MarketplaceOrderInput::canonical($domain),true)))self::unavailable();
    }

    /** Only the first NULL-to-trade write can classify its native duplicate; all observation-only writes leave that slot untouched. */
    private function writeObservation(PDO $pdo,array $row,array $target,string $encoded): void
    {
        if($target['evidence']===$row['evidence']&&$target['applied_trade_no']===$row['applied_trade_no'])return;
        $sql='UPDATE '.$this->table('marketplace_payment_attempt');
        $identity=' WHERE `id` = ? AND `order_id` = ? AND `uid` = ? AND `mer_id` = ?';
        $values=[$row['id'],$row['order_id'],$row['uid'],$row['mer_id']];
        if($target['applied_trade_no']===$row['applied_trade_no']){
            $update=$this->execute($pdo,$sql.' SET `evidence` = ?'.$identity,[$encoded,...$values]);
        }else{
            if($row['applied_trade_no']!==null||!is_string($target['applied_trade_no']))self::unavailable();
            $this->transaction($pdo);
            try{
                $update=$this->statement($pdo,$sql.' SET `evidence` = ?, `applied_trade_no` = ?'.$identity.
                    ' AND `applied_trade_no` IS NULL',[$encoded,$target['applied_trade_no'],...$values]);
            }catch(PDOException $error){
                $native=$error->errorInfo;
                if(is_array($native)&&($native[0]??null)==='23000'&&in_array($native[1]??null,[1062,'1062'],true))
                    throw new \DomainException('APPLIED_TRADE_CONFLICT');
                self::unavailable();
            }
        }
        if($update->rowCount()!==1)self::unavailable();
    }

    /** Observation fallback deliberately uses the observation lock, so inconsistent root values remain visible and unchanged. */
    public function holdObservationFailure(PDO $pdo,int $uid,int $orderId,string $orderNo,string $attemptId,array $original,string $reason,int $now): array
    {
        if(!in_array($reason,['EVIDENCE_CAPACITY','FACT_KEY_COLLISION'],true))self::unavailable();
        self::integer($now,1,self::UINT_MAX);$attemptId=self::positiveU64($attemptId);
        $observed=$this->lockObservation($pdo,$uid,$orderId,$orderNo);$locked=$observed['locked'];
        self::sameIntent($locked['attempts'][self::attemptPosition($locked,$attemptId)],$original);
        $locked['registry']=$this->writeFirstHold($pdo,$locked,$reason,$attemptId,$now);
        $readback=$this->lockObservation($pdo,$uid,$orderId,$orderNo);
        if($readback['locked']!==$locked)self::unavailable();return $readback;
    }

    /** The initial full snapshot is compared on every close step; only retained actual triplet facts produce a proof. */
    public function closedCancellationProof(PDO $pdo,int $uid,int $orderId,string $orderNo,array $initialPayment): ?array
    {
        $observed=$this->lockObservation($pdo,$uid,$orderId,$orderNo);$locked=$observed['locked'];
        self::sameCancellationSnapshot($initialPayment,$locked);
        if(!$observed['root_payment_consistent']||!self::cancellationPaymentClear($locked))return null;
        $attempts=[];
        foreach($locked['attempts'] as $row){
            $triplet=self::closedTriplet($row);if($triplet===null)return null;
            $attempts[]=['intent'=>array_slice($row,0,15,true),'attempt_ref'=>$row['attempt_ref']]+$triplet;
        }
        return ['schema'=>1,'root'=>self::rootIdentity($locked['root']),
            'generation'=>$locked['registry']['attempt_generation'],'attempts'=>$attempts];
    }

    /** An internal proof is a set of complete saved references, never a bool or authorization to skip the current lock. */
    public function assertCancellationProof(PDO $pdo,int $uid,int $orderId,string $orderNo,array $proof): array
    {
        self::objectKeys($proof,['schema','root','generation','attempts']);
        $locked=$this->lockPayment($pdo,$uid,$orderId,$orderNo);
        if($proof['schema']!==1||!is_array($proof['root'])||!is_array($proof['attempts'])||!array_is_list($proof['attempts'])
            ||$proof['generation']!==$locked['registry']['attempt_generation']||!self::cancellationPaymentClear($locked))self::unavailable();
        self::objectKeys($proof['root'],['id','uid','mer_id','order_no']);
        foreach(self::rootIdentity($locked['root']) as $field=>$value)if($proof['root'][$field]!==$value)self::unavailable();
        if(count($proof['attempts'])!==count($locked['attempts']))self::unavailable();
        foreach($locked['attempts'] as $position=>$row){
            $entry=$proof['attempts'][$position];if(!is_array($entry))self::unavailable();
            self::objectKeys($entry,['intent','attempt_ref','query_before','close','query_after']);
            if(!is_array($entry['intent'])||$entry['attempt_ref']!==$row['attempt_ref']||$row['attempt_ref']===null)self::unavailable();
            self::sameIntentProjection($row,$entry['intent']);
            foreach(['query_before'=>['queryPayment',['pending','closed']],'close'=>['closePayment',['closed']],
                'query_after'=>['queryPayment',['closed']]] as $field=>[$op,$states]){
                if(!is_string($entry[$field]))self::unavailable();$fact=self::observationKey($row,$entry[$field]);
                if($fact['fact']['op']!==$op||!in_array($fact['fact']['result']['state'],$states,true)
                    ||!self::qualified($fact['fact']['result'],$row))self::unavailable();
            }
            if(self::closedTriplet($row)===null)self::unavailable();
        }
        return $locked;
    }

    private static function objectKeys(array $value,array $fields): void
    {
        $keys=array_keys($value);sort($keys,SORT_STRING);sort($fields,SORT_STRING);
        if($keys!==$fields)self::unavailable();
    }

    private static function rootIdentity(array $root): array
    {
        return ['id'=>$root['id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],'order_no'=>$root['order_id']];
    }

    private static function sameResultBinding(array $result,array $row): bool
    {
        if($result['state']==='unknown')return false;
        $account=$row['account_snapshot'];
        $binding=['order_pk'=>$row['order_id'],'order_no'=>$row['order_no'],'uid'=>$row['uid'],'mer_id'=>$row['mer_id'],
            'amount'=>$row['amount'],'currency'=>$row['currency'],'channel'=>$account['channel'],'account_source'=>$account['source'],
            'account_id'=>$account['account_id'],'account_ref'=>$account['account_ref'],'account_version'=>$account['version']];
        return $result['binding_sha256']===bin2hex($row['binding_digest'])
            &&MarketplaceOrderInput::canonical($result['binding'])===MarketplaceOrderInput::canonical($binding);
    }

    private static function qualified(array $result,array $row): bool
    {
        return $row['attempt_ref']!==null&&$result['attempt_ref']===$row['attempt_ref']&&self::sameResultBinding($result,$row);
    }

    private static function observationFor(array $row,string $op,array $result): array
    {
        $bytes=MarketplaceOrderInput::canonical($result);
        foreach($row['evidence']['observations'] as $fact)
            if($fact['fact']['op']===$op&&MarketplaceOrderInput::canonical($fact['fact']['result'])===$bytes)return $fact;
        self::unavailable();
    }

    private static function observationKey(array $row,string $key): array
    {
        foreach($row['evidence']['observations'] as $fact)if($fact['key']===$key)return $fact;
        self::unavailable();
    }

    private static function factPosition(array $row,string $key): int
    {
        foreach($row['evidence']['observations'] as $position=>$fact)if($fact['key']===$key)return $position;
        self::unavailable();
    }

    private static function appendException(array &$locked,int $position,string $code,string $fact,?string $other,int $now): void
    {
        $row=$locked['attempts'][$position];
        $evidence=MarketplacePaymentEvidence::exception(self::evidenceRegistry($locked['registry']),$locked['attempts'],
            $row['id'],$code,$fact,$other,$now);
        $locked['attempts'][$position]['evidence']=self::canonicalValue($evidence);
    }

    private static function hasLateSuccess(array $locked): bool
    {
        foreach($locked['attempts'] as $row){
            $evidence=$row['evidence'];$closed=$evidence['first_closed'];$success=$evidence['first_succeeded'];
            if($closed===null||$success===null||self::factPosition($row,$closed)>=self::factPosition($row,$success))continue;
            foreach($evidence['exceptions'] as $entry)
                if($entry['code']==='CLOSED_THEN_SUCCESS'&&$entry['other']===$closed)return true;
        }
        return false;
    }

    private static function cancellationPaymentClear(array $locked): bool
    {
        if($locked['registry']['winner_attempt_id']!==null||$locked['registry']['fulfillment_hold']!==0||$locked['root']['paid']!==0)return false;
        foreach($locked['attempts'] as $row)
            if($row['evidence']['first_succeeded']!==null||$row['evidence']['application']!==null||$row['applied_trade_no']!==null)return false;
        return true;
    }

    private static function closedTriplet(array $row): ?array
    {
        $create=self::createResult($row);
        if($row['attempt_ref']===null||$create===null||!self::qualified($create,$row)||$row['evidence']['first_closed']===null
            ||$row['evidence']['first_succeeded']!==null||$row['evidence']['application']!==null)return null;
        $before=null;$close=null;$after=null;
        foreach($row['evidence']['observations'] as $fact){
            $op=$fact['fact']['op'];$result=$fact['fact']['result'];if(!self::qualified($result,$row))continue;
            if($op==='queryPayment'&&in_array($result['state'],['pending','closed'],true))$before??=$fact['key'];
            if($op==='closePayment'&&$result['state']==='closed')$close??=$fact['key'];
            if($op==='queryPayment'&&$result['state']==='closed')$after??=$fact['key'];
        }
        return $before===null||$close===null||$after===null?null:['query_before'=>$before,'close'=>$close,'query_after'=>$after];
    }

    private static function sameIntentProjection(array $row,array $intent): void
    {
        $fields=array_slice(self::ATTEMPT_FIELDS,0,15);self::objectKeys($intent,$fields);
        foreach($fields as $field){
            if($field==='account_snapshot'){
                if(!is_array($intent[$field])||MarketplaceOrderInput::canonical($intent[$field])!==MarketplaceOrderInput::canonical($row[$field]))self::unavailable();
            }elseif($intent[$field]!==$row[$field])self::unavailable();
        }
    }

    private static function sameCancellationSnapshot(array $initial,array $current): void
    {
        self::objectKeys($initial,['ledger','root','merchant','registry','attempts']);
        foreach(['ledger'=>self::LEDGER_FIELDS,'root'=>self::PAYMENT_ROOT_FIELDS,'merchant'=>self::PAYMENT_MERCHANT_FIELDS,
            'registry'=>self::REGISTRY_FIELDS] as $field=>$fields){
            if(!is_array($initial[$field]))self::unavailable();self::objectKeys($initial[$field],$fields);
        }
        if(!is_array($initial['attempts'])||!array_is_list($initial['attempts'])
            ||count($initial['attempts'])!==count($current['attempts']))self::unavailable();
        foreach(['id','uid','mer_id','marketplace_trade_version','pid','status','refund_status','paid','is_cancel','is_del',
            'is_system_del','pay_uid','add_time','pay_time'] as $field)if(!is_int($initial['root'][$field]))self::unavailable();
        foreach(['uid','mer_id','state','order_id','post_commit_done','cancel_at','add_time'] as $field)
            if(!is_int($initial['ledger'][$field]))self::unavailable();
        foreach(['order_id','uid','mer_id','payment_contract_version','born_at','fulfillment_hold'] as $field)
            if(!is_int($initial['registry'][$field]))self::unavailable();
        if($initial['registry']['attempt_generation']!==$current['registry']['attempt_generation'])self::unavailable();
        foreach(['order_id','uid','mer_id','payment_contract_version','born_at'] as $field)
            if($initial['registry'][$field]!==$current['registry'][$field])self::unavailable();
        foreach(self::LEDGER_FIELDS as $field)if($field!=='post_commit_done'&&$initial['ledger'][$field]!==$current['ledger'][$field])self::unavailable();
        self::integer($initial['ledger']['post_commit_done'],0,1);
        foreach(['id','uid','mer_id','order_id','unique','marketplace_trade_version','pid','cart_id','pay_price','pay_uid','add_time'] as $field)
            if($initial['root'][$field]!==$current['root'][$field])self::unavailable();
        if($initial['merchant']['mer_id']!==$current['merchant']['mer_id'])self::unavailable();
        self::paymentCollection($initial);
        foreach($current['attempts'] as $position=>$row){
            $old=$initial['attempts'][$position];if(!is_array($old))self::unavailable();
            self::sameIntentProjection($row,array_slice($old,0,15,true));
            if($old['attempt_ref']!==$row['attempt_ref'])self::unavailable();
            $before=$old['evidence'];$after=$row['evidence'];
            foreach(['observations','exceptions'] as $field)
                if(array_slice($after[$field],0,count($before[$field]))!==$before[$field])self::unavailable();
            foreach(['first_closed','first_succeeded','application'] as $field)
                if($before[$field]!==null&&$before[$field]!==$after[$field])self::unavailable();
            if($old['applied_trade_no']!==null&&$old['applied_trade_no']!==$row['applied_trade_no'])self::unavailable();
        }
    }

    /** Full actual OrderDao facts are inspected; there is no caller-supplied can-apply flag. */
    private static function applicationState(array $facts,array $locked): array
    {
        self::objectKeys($facts,['root','items','selection','reserves','children','child_orders']);
        if(!is_array($facts['root']))self::unavailable();self::objectKeys($facts['root'],['id','uid','mer_id','order_no']);
        foreach(self::rootIdentity($locked['root']) as $field=>$value)if($facts['root'][$field]!==$value)self::unavailable();
        foreach(['items','selection','reserves','children','child_orders'] as $field)
            if(!is_array($facts[$field])||!array_is_list($facts[$field]))self::unavailable();
        $root=$locked['root'];$items=[];$previous=0;$changed=false;$groups=[];
        foreach($facts['items'] as $item){
            if(!is_array($item))self::unavailable();self::objectKeys($item,self::ITEM_FIELDS);
            $id=self::integer($item['id'],1,self::INT_MAX);
            if($id<=$previous||self::integer($item['oid'],1,self::INT_MAX)!==$root['id']
                ||self::integer($item['uid'],1,self::INT_MAX)!==$root['uid']||self::integer($item['mer_id'],1,self::UINT_MAX)!==$root['mer_id'])self::unavailable();
            $previous=$id;$qty=self::integer($item['cart_num'],1,self::INT_MAX);
            $refund=self::integer($item['refund_num'],0,self::INT_MAX);$surplus=self::integer($item['surplus_num'],0,self::INT_MAX);
            if($refund>$qty||$surplus>$qty)self::unavailable();
            if($refund!==0||$surplus!==$qty||self::integer($item['split_status'],0,2)!==0)$changed=true;
            self::integer($item['product_id'],1,8388607);
            foreach(['cart_id','cart_info','unique'] as $field)if(!is_string($item[$field]))self::unavailable();
            $items['i:'.$id]=$item;
        }
        $refs=self::paymentJson($locked['ledger']['cart_refs']);
        if(!$items||count($items)!==count($refs)||count($facts['selection'])!==count($refs))self::unavailable();$seen=[];
        foreach($facts['selection'] as $position=>$line){
            if(!is_array($line))self::unavailable();
            self::objectKeys($line,['item_id','cart_ref','product_id','sku_id','sku_type','unique','qty','cart_info']);
            $itemId=self::integer($line['item_id'],1,self::INT_MAX);$item=$items['i:'.$itemId]??null;
            if($item===null||isset($seen[$itemId])||!is_array($line['cart_info'])||$line['cart_ref']!==$refs[$position]
                ||$item['cart_id']!==$line['cart_ref']||self::integer($item['product_id'],1,8388607)!==$line['product_id']
                ||self::integer($item['cart_num'],1,self::INT_MAX)!==$line['qty']||$line['sku_type']!==0)self::unavailable();
            $seen[$itemId]=true;self::integer($line['product_id'],1,8388607);self::integer($line['sku_id'],1,self::INT_MAX);
            self::integer($line['qty'],1,self::INT_MAX);
            if(!is_string($line['unique'])||preg_match('/\A[A-Za-z0-9]{8}\z/D',$line['unique'])!==1)self::unavailable();
            try{$cart=json_decode($item['cart_info'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::unavailable();}
            if(!is_array($cart)||MarketplaceOrderInput::canonical($cart)!==MarketplaceOrderInput::canonical($line['cart_info']))self::unavailable();
            $lineage=$line['cart_info']['marketplace']??null;
            if(!is_array($lineage)||($lineage['cart_ref']??null)!==$line['cart_ref']||($lineage['sku_id']??null)!==$line['sku_id']
                ||($lineage['sku_type']??null)!==0||($lineage['sku_unique']??null)!==$line['unique'])self::unavailable();
            $reserve=self::positiveU64($lineage['reserve_id']??null);$key='r:'.$reserve;
            if(!isset($groups[$key]))$groups[$key]=['product_id'=>$line['product_id'],'sku_id'=>$line['sku_id'],'unique'=>$line['unique'],'qty'=>0,'cart_refs'=>[]];
            $group=&$groups[$key];
            if($group['product_id']!==$line['product_id']||$group['sku_id']!==$line['sku_id']||$group['unique']!==$line['unique'])self::unavailable();
            $group['qty']+=$line['qty'];self::integer($group['qty'],1,self::UINT_MAX);$group['cart_refs'][]=$line['cart_ref'];unset($group);
        }
        if(count($facts['reserves'])!==count($groups))self::unavailable();$parents=[];$previous=null;
        foreach($facts['reserves'] as $raw){
            $movement=self::factMovement($raw,$root);$id=$movement['id'];$group=$groups['r:'.$id]??null;
            if($group===null||($previous!==null&&bccomp($id,$previous,0)<=0)||$movement['kind']!=='reserve'
                ||$movement['parent_reserve_id']!==null||$movement['order_item_id']!==null||$movement['refund_id']!==null
                ||$movement['actor_type']!=='customer'||$movement['actor_id']!==$root['uid']
                ||$movement['idem_key']!=='reserve:'.$root['id'].':'.$group['sku_id'])self::unavailable();
            self::groupMovement($movement,$group);
            $action=['contract_version'=>1,'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],'order_id'=>$root['id'],
                'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],
                'qty'=>$group['qty'],'kind'=>'reserve','cart_refs'=>$group['cart_refs']];
            if(!hash_equals($movement['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($action),true)))self::unavailable();
            self::factArithmetic($movement,false);$parents['r:'.$id]=$movement;$previous=$id;
        }
        $credited=[];$previous=null;
        foreach($facts['children'] as $raw){
            $movement=self::factMovement($raw,$root);$id=$movement['id'];$parent=$movement['parent_reserve_id'];
            if($parent===null||!isset($parents['r:'.$parent])||($previous!==null&&bccomp($id,$previous,0)<=0)
                ||!in_array($movement['kind'],['release','return_in'],true))self::unavailable();
            $group=$groups['r:'.$parent];self::groupMovement($movement,$group,false);
            $credited[$parent]=($credited[$parent]??0)+$movement['qty'];if($credited[$parent]>$group['qty'])self::unavailable();
            if($movement['kind']==='release'){
                if($movement['qty']!==$group['qty']||$movement['order_item_id']!==null||$movement['refund_id']!==null
                    ||$movement['idem_key']!=='release:'.$root['id'].':'.$parent
                    ||!(($movement['actor_type']==='customer'&&$movement['actor_id']===$root['uid'])
                        ||($movement['actor_type']==='system'&&$movement['actor_id']===null)))self::unavailable();
                $action=['contract_version'=>1,'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],'order_id'=>$root['id'],
                    'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],
                    'qty'=>$group['qty'],'kind'=>'release','parent_reserve_id'=>$parent];
                if(!hash_equals($movement['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($action),true)))self::unavailable();
                self::factArithmetic($movement,true);
            }
            // return_in has no adopted action/actor/idem contract yet; its actual typed row and budget block first application.
            $previous=$id;
        }
        $previous=0;
        foreach($facts['child_orders'] as $child){
            if(!is_array($child))self::unavailable();self::objectKeys($child,self::CHILD_ORDER_FIELDS);
            $id=self::integer($child['id'],1,self::INT_MAX);
            if($id<=$previous||self::integer($child['pid'],1,self::INT_MAX)!==$root['id'])self::unavailable();$previous=$id;
            self::integer($child['uid'],0,self::INT_MAX);
            if($child['mer_id']!==null)self::integer($child['mer_id'],0,self::UINT_MAX);
            if($child['marketplace_trade_version']!==null)self::integer($child['marketplace_trade_version'],0,255);
            self::paymentText($child['order_id'],32,false);self::integer($child['paid'],0,1);
            self::paymentStatus($child['status']);self::integer($child['refund_status'],0,255);self::integer($child['is_cancel'],0,1);
        }
        return ['released'=>$facts['children']!==[],'changed'=>$changed||$facts['child_orders']!==[]];
    }

    private static function factMovement(mixed $raw,array $root): array
    {
        if(!is_array($raw))self::unavailable();self::objectKeys($raw,self::MOVEMENT_FIELDS);$row=$raw;
        if(!is_string($row['id']))self::unavailable();$row['id']=self::positiveU64($row['id']);
        if($row['parent_reserve_id']!==null){
            if(!is_string($row['parent_reserve_id']))self::unavailable();$row['parent_reserve_id']=self::positiveU64($row['parent_reserve_id']);
        }
        foreach(['order_item_id','refund_id'] as $field)if($row[$field]!==null)self::integer($row[$field],1,self::INT_MAX);
        foreach(['mer_id'=>self::UINT_MAX,'order_id'=>self::INT_MAX,'product_id'=>8388607,'sku_id'=>self::INT_MAX,
            'qty'=>self::UINT_MAX,'add_time'=>self::UINT_MAX] as $field=>$maximum)$row[$field]=self::integer($row[$field],1,$maximum);
        $row['sku_type']=self::integer($row['sku_type'],0,0);
        if($row['mer_id']!==$root['mer_id']||$row['order_id']!==$root['id'])self::unavailable();
        if($row['actor_id']!==null)$row['actor_id']=self::integer($row['actor_id'],1,self::UINT_MAX);
        if(!is_string($row['sku_unique'])||preg_match('/\A[A-Za-z0-9]{8}\z/D',$row['sku_unique'])!==1)self::unavailable();
        foreach(['idem_key'=>128,'actor_type'=>32] as $field=>$maximum){
            if(!is_string($row[$field])||$row[$field]===''||preg_match('//u',$row[$field])!==1
                ||str_contains($row[$field],"\0")||mb_strlen($row[$field],'UTF-8')>$maximum)self::unavailable();
        }
        if(!in_array($row['kind'],['reserve','release','return_in'],true))self::unavailable();
        if(!is_string($row['request_digest'])||strlen($row['request_digest'])!==32)self::unavailable();
        foreach(['sku'=>self::UINT_MAX,'root'=>16777215] as $part=>$maximum)
            foreach(['stock_before','stock_after','sales_before','sales_after'] as $suffix)$row[$part.'_'.$suffix]=self::integer($row[$part.'_'.$suffix],0,$maximum);
        return $row;
    }

    private static function groupMovement(array $movement,array $group,bool $whole=true): void
    {
        if($movement['product_id']!==$group['product_id']||$movement['sku_id']!==$group['sku_id']||$movement['sku_unique']!==$group['unique']
            ||($whole&&$movement['qty']!==$group['qty']))self::unavailable();
    }

    private static function factArithmetic(array $row,bool $credit): void
    {
        $qty=$row['qty'];
        foreach(['sku'=>self::UINT_MAX,'root'=>16777215] as $part=>$maximum){
            $stock=$row[$part.'_stock_before'];$sales=$row[$part.'_sales_before'];
            if($credit){if($qty>$maximum-$stock||$qty>$sales)self::unavailable();$stock+=$qty;$sales-=$qty;}
            else{if($qty>$stock||$qty>$maximum-$sales)self::unavailable();$stock-=$qty;$sales+=$qty;}
            if($row[$part.'_stock_after']!==$stock||$row[$part.'_sales_after']!==$sales)self::unavailable();
        }
    }

    private function writeFirstHold(PDO $pdo,array $locked,string $reason,string $attemptId,int $now): array
    {
        if(!in_array($reason,self::HOLD_REASONS,true))self::unavailable();self::attemptPosition($locked,$attemptId);
        $registry=$locked['registry'];if($registry['fulfillment_hold']===1)return $registry;
        $update=$this->execute($pdo,'UPDATE '.$this->table('marketplace_payment_registry').
            ' SET `fulfillment_hold` = 1, `hold_reason` = ?, `hold_attempt_id` = ?, `hold_at` = ?'.
            ' WHERE `order_id` = ? AND `uid` = ? AND `mer_id` = ? AND `fulfillment_hold` = 0'.
            ' AND `hold_reason` IS NULL AND `hold_attempt_id` IS NULL AND `hold_at` IS NULL',
            [$reason,$attemptId,$now,$registry['order_id'],$registry['uid'],$registry['mer_id']]);
        if($update->rowCount()!==1)self::unavailable();
        $registry['fulfillment_hold']=1;$registry['hold_reason']=$reason;$registry['hold_attempt_id']=$attemptId;$registry['hold_at']=$now;
        return $registry;
    }

    private static function paymentCollection(array $locked): bool
    {
        $registry=$locked['registry'];$rows=$locked['attempts'];$winner=null;$holdAttemptFound=$registry['hold_attempt_id']===null;
        if(!$rows){if($registry['attempt_generation']!=='0')self::unavailable();}
        else MarketplacePaymentEvidence::encode(self::evidenceRegistry($registry),$rows,$rows[0]['id']);
        foreach($rows as $row){
            if(bccomp($row['admitted_generation'],$registry['attempt_generation'],0)<0&&$row['evidence']['first_closed']===null)self::unavailable();
            if($registry['hold_attempt_id']===$row['id'])$holdAttemptFound=true;
            if($row['evidence']['application']!==null){
                if($registry['winner_attempt_id']!==$row['id']||$winner!==null)self::unavailable();$winner=$row;
            }
            if(($row['evidence']['exceptions']!==[]||($row['evidence']['first_closed']!==null&&$row['evidence']['first_succeeded']!==null)
                ||($row['evidence']['first_succeeded']!==null&&($locked['root']['is_cancel']===1
                    ||($registry['winner_attempt_id']!==null&&$registry['winner_attempt_id']!==$row['id']))))
                &&$registry['fulfillment_hold']!==1)self::unavailable();
            self::createResult($row);
        }
        if(!$holdAttemptFound||(($registry['winner_attempt_id']!==null)!==($winner!==null)))self::unavailable();
        $root=$locked['root'];
        if($winner===null)return $root['paid']===0&&$root['pay_time']===0&&$root['trade_no']==='';
        else{
            $payment=$winner['evidence']['application']['root_payment'];
            foreach(['paid','pay_time','pay_type','trade_no'] as $field)if($root[$field]!==$payment[$field])return false;
        }
        return true;
    }

    private static function evidenceRegistry(array $registry): array
    {
        return array_intersect_key($registry,array_flip(['order_id','uid','mer_id','payment_contract_version','attempt_generation']));
    }

    private static function attemptPosition(array $locked,string $attemptId): int
    {
        foreach($locked['attempts'] as $position=>$row)if($row['id']===$attemptId)return $position;
        self::unavailable();
    }

    private static function sameIntent(array $row,array $original): void
    {
        if(array_keys($original)!==self::ATTEMPT_FIELDS)self::unavailable();
        foreach(self::ATTEMPT_FIELDS as $field){
            if(in_array($field,['attempt_ref','applied_trade_no','evidence'],true))continue;
            if($row[$field]!==$original[$field])self::unavailable();
        }
        if($original['attempt_ref']!==null&&$row['attempt_ref']!==$original['attempt_ref'])self::unavailable();
    }

    private static function createFact(array $row): ?array
    {
        $found=null;
        foreach($row['evidence']['observations'] as $observation)if($observation['fact']['op']==='createPayment'){
            if($found!==null)self::unavailable();$found=$observation;
        }
        return $found;
    }

    private static function createResult(array $row): ?array
    {
        $fact=self::createFact($row);return $fact===null?null:$fact['fact']['result'];
    }

    private static function paymentBinding(array $root,array $account): array
    {
        self::paymentAccount($account);if($account['mer_id']!==$root['mer_id'])self::unavailable();
        return ['order_pk'=>$root['id'],'order_no'=>$root['order_id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],
            'amount'=>$root['pay_price'],'currency'=>'CNY','channel'=>'test','account_source'=>'test_config','account_id'=>null,
            'account_ref'=>$account['account_ref'],'account_version'=>$account['version']];
    }

    private static function paymentAccount(array $account): void
    {
        $fields=['source','account_id','account_ref','version','mer_id','channel','currency','verification_scope'];
        $keys=array_keys($account);sort($keys,SORT_STRING);sort($fields,SORT_STRING);
        if($keys!==$fields||$account['source']!=='test_config'||$account['account_id']!==null||$account['channel']!=='test'
            ||$account['currency']!=='CNY'||$account['verification_scope']!=='test_driver_only'
            ||!is_int($account['mer_id'])||$account['mer_id']<1||$account['mer_id']>self::UINT_MAX
            ||!is_int($account['version'])||$account['version']<1||$account['version']>self::INT_MAX
            ||!is_string($account['account_ref'])||strlen($account['account_ref'])>128
            ||preg_match('/\Atest:[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D',$account['account_ref'])!==1)self::unavailable();
    }

    private static function paymentJson(mixed $encoded): array
    {
        if(!is_string($encoded))self::unavailable();
        try{$value=self::jsonShape(json_decode($encoded,false,32,JSON_THROW_ON_ERROR));if(!is_array($value))self::unavailable();return $value;}
        catch(\Throwable $error){self::unavailable();}
    }

    /** Every object in these fixed schemas has named, nonempty fields; JSON lists must remain actual lists. */
    private static function jsonShape(mixed $value): mixed
    {
        if($value instanceof \stdClass){
            $value=get_object_vars($value);
            // Reject {} and numeric-key objects instead of turning them into [] / a list before the schema check.
            if(array_is_list($value))self::unavailable();
            foreach($value as $key=>$item){if(!is_string($key))self::unavailable();$value[$key]=self::jsonShape($item);}
            return $value;
        }
        if(is_array($value)){
            if(!array_is_list($value))self::unavailable();
            foreach($value as $key=>$item)$value[$key]=self::jsonShape($item);return $value;
        }
        if(is_string($value)||is_int($value)||is_bool($value)||$value===null)return $value;
        self::unavailable();
    }

    private static function canonicalValue(array $value): array
    {
        return json_decode(MarketplaceOrderInput::canonical($value),true,32,JSON_THROW_ON_ERROR);
    }

    private static function positiveU64(mixed $value): string
    {
        $value=self::u64($value);if($value==='0')self::unavailable();return $value;
    }

    private static function paymentAmount(mixed $value,bool $positive): void
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1
            ||($positive&&bccomp($value,'0.00',2)<=0))self::unavailable();
    }

    private static function paymentIdem(string $value): void
    {
        if(strlen($value)>128||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D',$value)!==1)self::unavailable();
    }

    private static function paymentText(mixed $value,int $maximum,bool $empty): void
    {
        if(!is_string($value)||(!$empty&&$value==='')||strlen($value)>$maximum||trim($value)!==$value
            ||preg_match('//u',$value)!==1||str_contains($value,"\0"))self::unavailable();
    }

    private static function rootTextAllowance(mixed $value,int $maximum): void
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||str_contains($value,"\0")||mb_strlen($value,'UTF-8')>$maximum)self::unavailable();
    }

    private static function paymentStatus(mixed $value): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=4)$value=(int)$value;
        if(!is_int($value)||$value< -128||$value>127)self::unavailable();return $value;
    }

    private function outside(PDO $pdo): void
    {
        $connection=Db::connect();
        if(PHP_INT_SIZE<8||$pdo->inTransaction()||$connection->getPdo()!==$pdo||$connection->getConfig('prefix')!==$this->prefix
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }

    /** The caller has already checked either this same PDO's outer transaction or autocommit boundary. */
    private function prepared(PDO $pdo,string $sql,array $values): PDOStatement
    {
        try{
            $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
            foreach(array_values($values) as $index=>$value){
                if(!is_int($value)&&!is_string($value))self::unavailable();
                if(!$statement->bindValue($index+1,(string)$value,PDO::PARAM_STR))self::unavailable();
            }
            if(!$statement->execute())self::unavailable();return $statement;
        }catch(PDOException $error){self::unavailable();}
    }

    private function fetched(PDOStatement $statement): array
    {
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $error){self::unavailable();}
        if(!is_array($rows))self::unavailable();return $rows;
    }

    private function transaction(PDO $pdo): void
    {
        $connection=Db::connect();
        if(PHP_INT_SIZE<8||!$pdo->inTransaction()||$connection->getPdo()!==$pdo||$connection->getConfig('prefix')!==$this->prefix
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }

    private function execute(PDO $pdo,string $sql,array $values): PDOStatement
    {
        $this->transaction($pdo);
        try{return $this->statement($pdo,$sql,$values);}catch(PDOException $error){self::unavailable();}
    }

    private function statement(PDO $pdo,string $sql,array $values): PDOStatement
    {
        $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
        foreach(array_values($values) as $index=>$value){
            if($value!==null&&!is_int($value)&&!is_string($value))self::unavailable();
            if(!$statement->bindValue($index+1,$value===null?null:(string)$value,$value===null?PDO::PARAM_NULL:PDO::PARAM_STR))self::unavailable();
        }
        if(!$statement->execute())self::unavailable();return $statement;
    }

    private function one(PDO $pdo,string $table,array $fields,string $where,array $values): ?array
    {
        $rows=$this->rows($pdo,$table,$fields,$where,$values,'',2);
        if(count($rows)>1)self::unavailable();return $rows[0]??null;
    }

    private function rows(PDO $pdo,string $table,array $fields,string $where,array $values,string $order='',?int $limit=null): array
    {
        $sql='SELECT '.implode(',',array_map(self::column(...),$fields)).' FROM '.$this->table($table).' WHERE '.$where;
        if($order!=='')$sql.=' ORDER BY '.$order;
        if($limit!==null)$sql.=' LIMIT '.self::integer($limit,1,2);
        $statement=$this->execute($pdo,$sql.' FOR UPDATE',$values);
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $error){self::unavailable();}
        if(!is_array($rows))self::unavailable();
        foreach($rows as $row)if(!is_array($row)||array_keys($row)!==$fields)self::unavailable();
        return $rows;
    }

    private function table(string $name): string
    {
        if(!in_array($name,self::TABLES,true))self::unavailable();
        return '`'.$this->prefix.$name.'`';
    }

    private static function column(string $name): string
    {
        if(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$name)!==1)self::unavailable();return '`'.$name.'`';
    }

    private static function integer(mixed $value,int $minimum,int $maximum): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1){
            $bound=(string)$maximum;
            if(strlen($value)>strlen($bound)||(strlen($value)===strlen($bound)&&strcmp($value,$bound)>0))self::unavailable();
            $value=(int)$value;
        }
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::unavailable();return $value;
    }

    private static function u64(mixed $value): string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        $maximum='18446744073709551615';
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1||strlen($value)>strlen($maximum)
            ||(strlen($value)===strlen($maximum)&&strcmp($value,$maximum)>0))self::unavailable();
        return $value;
    }

    private static function creatingIdem(string $value): void
    {
        // This is the existing creation ledger key, not TestPaymentDriver's narrower payment key.
        if(preg_match('/\A[\x20-\x7e]{1,128}\z/D',$value)!==1||$value[0]===' '||substr($value,-1)===' ')self::unavailable();
    }

    private static function rootText(mixed $value): void
    {
        if(!is_string($value)||$value===''||preg_match('//u',$value)!==1||str_contains($value,"\0")
            ||mb_strlen($value,'UTF-8')>32)self::unavailable();
    }

    private static function cartRefs(mixed $encoded,string $sourceKind): void
    {
        if(!is_string($encoded))self::unavailable();
        try{
            $refs=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($refs)||!array_is_list($refs)||!$refs)self::unavailable();
            foreach($refs as $ref)if(!is_string($ref))self::unavailable();
            if(MarketplaceOrderInput::references(implode(',',$refs),$sourceKind)!==$refs)self::unavailable();
        }catch(\Throwable $error){self::unavailable();}
    }

    private static function unavailable(): never
    {
        throw new AuthUnavailableException();
    }
}
