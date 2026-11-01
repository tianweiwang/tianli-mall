<?php
declare(strict_types=1);
namespace app\dao\marketplace\payment;

use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use crmeb\exceptions\MarketplaceSchemaException;
use crmeb\services\marketplace\RefundSchemaReadiness;
use think\facade\Db;

/** One saved financial intent per business refund. The service owns the default native transaction. */
final class MarketplaceRefundDao
{
    private const TABLE='marketplace_refund_attempt';
    private const FIELDS=['id','contract_version','refund_id','root_order_id','uid','mer_id','payment_attempt_id','approval_action_id',
        'idem_key','request_digest','binding_digest','account_domain_digest','account_snapshot','amount','currency',
        'attempt_ref','applied_refund_no','evidence','created_at','updated_at'];
    private string $prefix;
    public function __construct()
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.self::TABLE)>64)self::fail();
        $this->prefix=$prefix;
    }
    /** Missing is distinct from an existing table whose structure cannot be trusted. */
    public function structure(PDO $pdo):bool
    {
        $this->native($pdo,false);
        try { return RefundSchemaReadiness::hasStructure($pdo,$this->prefix); }
        catch (MarketplaceSchemaException $error) { self::fail(); }
    }
    public function find(PDO $pdo,int $refundId,bool $lock=false):?array
    {
        $this->native($pdo,$lock);Facts::id($refundId);
        $s=$this->execute($pdo,'SELECT '.implode(',',array_map(self::sqlColumn(...),self::FIELDS)).' FROM '.$this->table().
            ' WHERE `refund_id`=? LIMIT 2'.($lock?' FOR UPDATE':''),[$refundId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1)self::fail();return $rows===[]?null:Facts::row($rows[0]);
    }
    public function insert(PDO $pdo,array $data):array
    {
        $this->native($pdo,true);Facts::keys($data,array_slice(self::FIELDS,1));
        // Validate every field before INSERT; the synthetic ID is used only for this pure shape check.
        $checked=Facts::row(['id'=>'1']+$data);unset($checked['id']);
        if($checked!==$data)self::fail();
        if($this->find($pdo,$data['refund_id'],true)!==null)self::fail();
        $values=[];foreach(array_slice(self::FIELDS,1) as $k)$values[]=in_array($k,['account_snapshot','evidence'],true)?Facts::canonical($data[$k]):$data[$k];
        $s=$this->execute($pdo,'INSERT INTO '.$this->table().' ('.implode(',',array_map(self::sqlColumn(...),array_slice(self::FIELDS,1))).
            ') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',$values);
        if($s->rowCount()!==1)self::fail();$id=Facts::u64($pdo->lastInsertId());$row=$this->find($pdo,$data['refund_id'],true);
        if($row===null||$row['id']!==$id||Facts::canonical($row['evidence'])!==Facts::canonical($data['evidence']))self::fail();
        foreach($data as $k=>$v)if(!in_array($k,['account_snapshot','evidence'],true)&&$row[$k]!==$v)self::fail();
        if(Facts::canonical($row['account_snapshot'])!==Facts::canonical($data['account_snapshot']))self::fail();return $row;
    }
    public function save(PDO $pdo,array $before,array $after):array
    {
        $this->native($pdo,true);$before=Facts::row($before);$after=Facts::row($after);
        if($this->find($pdo,$before['refund_id'],true)!==$before)self::fail();
        foreach(self::FIELDS as $k)if(!in_array($k,['attempt_ref','applied_refund_no','evidence','updated_at'],true)&&$after[$k]!==$before[$k])self::fail();
        if($before['attempt_ref']!==null&&$before['attempt_ref']!==$after['attempt_ref'])self::fail();
        if($before['applied_refund_no']!==null&&$before['applied_refund_no']!==$after['applied_refund_no'])self::fail();
        if(Facts::canonical(array_slice($after['evidence']['observations'],0,count($before['evidence']['observations'])))
            !==Facts::canonical($before['evidence']['observations']))self::fail();
        if($before['contract_version']===2&&Facts::canonical(array_slice($after['evidence']['applications'],0,count($before['evidence']['applications'])))
            !==Facts::canonical($before['evidence']['applications']))self::fail();
        foreach(['create','success','application'] as $key)if($before['evidence'][$key]!==null
            &&$after['evidence'][$key]!==$before['evidence'][$key])self::fail();
        if($after['updated_at']<$before['updated_at'])self::fail();if($after===$before)return $before;
        $s=$this->execute($pdo,'UPDATE '.$this->table().' SET `attempt_ref`=?,`applied_refund_no`=?,`evidence`=?,`updated_at`=?'.
            ' WHERE `id`=? AND `updated_at`=?',[$after['attempt_ref'],$after['applied_refund_no'],Facts::canonical($after['evidence']),
                $after['updated_at'],$before['id'],$before['updated_at']]);
        if($s->rowCount()!==1)self::fail();$row=$this->find($pdo,$before['refund_id'],true);
        if($row===null||Facts::canonical($row['evidence'])!==Facts::canonical($after['evidence']))self::fail();
        foreach(self::FIELDS as $k)if(!in_array($k,['evidence','account_snapshot'],true)&&$row[$k]!==$after[$k])self::fail();return $row;
    }
    private function native(PDO $pdo,bool $write):void
    {
        $connection=Db::connect();
        if($connection->getPdo()!==$pdo||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$connection->getConfig('prefix')!==$this->prefix
            ||($write&&(!$pdo->inTransaction()||$connection->transactionDepth()!==1)))self::fail();
    }
    private function execute(PDO $pdo,string $sql,array $args):PDOStatement
    {
        $s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        foreach(array_values($args) as $i=>$v){
            if($v!==null&&!is_int($v)&&!is_string($v))self::fail();
            if(!$s->bindValue($i+1,$v===null?null:(string)$v,$v===null?PDO::PARAM_NULL:PDO::PARAM_STR))self::fail();
        }
        if(!$s->execute())self::fail();return $s;
    }
    private function table():string {return '`'.$this->prefix.self::TABLE.'`';}
    private static function sqlColumn(string $name):string {return '`'.$name.'`';}
    private static function fail():never {throw new AuthUnavailableException();}
    /** Exact shared additive structure contract; the operation-local native gate remains above. */
    public static function newTables(): array
    { return RefundSchemaReadiness::newTables(); }
}
