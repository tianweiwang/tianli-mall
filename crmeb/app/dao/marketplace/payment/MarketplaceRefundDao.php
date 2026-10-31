<?php
declare(strict_types=1);
namespace app\dao\marketplace\payment;

use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use RuntimeException;
use think\facade\Db;

/** One saved financial intent per business refund. The service owns the default native transaction. */
final class MarketplaceRefundDao
{
    private const TABLE='marketplace_refund_attempt';
    private const FIELDS=['id','contract_version','refund_id','root_order_id','uid','mer_id','payment_attempt_id','approval_action_id',
        'idem_key','request_digest','binding_digest','account_domain_digest','account_snapshot','amount','currency',
        'attempt_ref','applied_refund_no','evidence','created_at','updated_at'];
    private string $prefix;
    private string $database;
    private PDO $pdo;
    private const MARKER='marketplace-ms5-refund-structure-v1';
    public function __construct()
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.self::TABLE)>64)self::fail();
        $this->prefix=$prefix;
    }
    /** Missing is distinct from an existing table whose structure cannot be trusted. */
    public function structure(PDO $pdo):bool
    {
        $this->native($pdo,false);$database=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if(!is_string($database)||$database==='')self::fail();
        $s=$this->execute($pdo,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [$database,$this->prefix.self::TABLE]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return false;if(count($rows)!==1)self::fail();
        $this->database=$database;$this->pdo=$pdo;
        $metadata=$this->metadata(self::TABLE);
        if($metadata===null||!$this->sameNewTable($metadata,self::newTables()[self::TABLE]))self::fail();return true;
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
    // Exact additive migration contract; application images do not contain help/migrations.
    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    public static function newTables(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        return [
            'marketplace_refund_attempt' => [
                'columns' => [
                    'id' => $c('bigint unsigned', '原生测试退款意图主键', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', '独立测试退款合同版本'),
                    'refund_id' => $c('int', '唯一原生售后申请主键'),
                    'root_order_id' => $c('int unsigned', '原商业根订单主键'),
                    'uid' => $c('int unsigned', '原订单顾客编号'),
                    'mer_id' => $c('int unsigned', '原订单商家编号'),
                    'payment_attempt_id' => $c('bigint unsigned', '已验证原付款成功尝试主键'),
                    'approval_action_id' => $c('bigint unsigned', '已提交批准售后动作主键'),
                    'idem_key' => $c('varchar(128)', '原测试退款请求幂等键', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)', '完整规范测试退款请求SHA256原始摘要'),
                    'binding_digest' => $c('binary(32)', '原退款驱动绑定SHA256原始摘要'),
                    'account_domain_digest' => $c('binary(32)', '原测试账户域SHA256原始摘要'),
                    'account_snapshot' => $c('json', '原付款测试账户完整不可变快照'),
                    'amount' => $c('decimal(12,2) unsigned', '本申请剩余全额精确退款金额元'),
                    'currency' => $c('char(3)', '原付款币种完整快照', false, null, 'ascii'),
                    'attempt_ref' => $c('varchar(45)', '实际驱动返回的退款尝试引用', true, null, 'ascii'),
                    'applied_refund_no' => $c('varchar(45)', '仅已应用成功的原测试退款引用', true, null, 'ascii'),
                    'evidence' => $c('json', '完整真实create及query观察应用事实'),
                    'created_at' => $c('int unsigned', '原测试退款意图本地登记秒'),
                    'updated_at' => $c('int unsigned', '最后真实退款观察本地登记秒'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_ms5_refund_refund' => [true, ['refund_id']],
                    'idx_ms5_refund_root' => [false, ['root_order_id', 'id']],
                    'idx_ms5_refund_payment' => [false, ['payment_attempt_id', 'id']],
                    'uq_ms5_refund_account_attempt' => [true, ['account_domain_digest', 'attempt_ref']],
                    'uq_ms5_refund_account_number' => [true, ['account_domain_digest', 'applied_refund_no']],
                ],
            ],
        ];
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
            || array_keys($meta['columns']) !== array_keys($contract['columns'])
            || $meta['foreign'] !== [] || $meta['checks'] !== []) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : null;
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
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
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', $args);
        if ($tables === []) return null;
        if (count($tables) !== 1) throw new RuntimeException('Metadata unavailable');
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string) $column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
            'foreign' => $this->rows('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION', $args),
            'checks' => $this->rows("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK' ORDER BY CONSTRAINT_NAME", $args),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
    }

    private function rows(string $sql,array $arguments):array
    {return $this->execute($this->pdo,$sql,$arguments)->fetchAll(PDO::FETCH_ASSOC);}

}
