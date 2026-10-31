<?php
declare(strict_types=1);

namespace crmeb\services\marketplace;

use crmeb\exceptions\MarketplaceSchemaException as Failure;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/exceptions/MarketplaceSchemaException.php';

/** Exact refund structure and ungranted operation leaf. SELECT only, with no App or transaction control. */
final class RefundSchemaReadiness
{
    private const TABLE='marketplace_refund_attempt';
    private const MARKER='marketplace-ms5-refund-structure-v1';
    private const MENU_MARKER='marketplace-ms5-refund-test-menu-v1';
    private string $database;
    private string $menuTable;

    private function __construct(private PDO $pdo, private string $prefix)
    {
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || strlen($prefix . self::TABLE) > 64
            || PHP_INT_SIZE < 8 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) {
            throw new Failure(Failure::UNAVAILABLE);
        }
        $database=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1) {
            throw new Failure(Failure::UNAVAILABLE);
        }
        $this->database=$database;
        $this->menuTable=$prefix . 'system_menus';
    }

    /** Operation-local table check: absent is false; an existing incompatible table is rejected. */
    public static function hasStructure(PDO $pdo, string $prefix): bool
    {
        try { return (new self($pdo, $prefix))->refundStructure(); }
        catch (Failure $error) { throw $error; }
        catch (Throwable $error) { throw new Failure(Failure::UNAVAILABLE); }
    }

    /** Required release prerequisite; does not create structure or grant an existing role. */
    public static function assertReady(PDO $pdo, string $prefix): void
    {
        try {
            $reader=new self($pdo, $prefix);
            if (!$reader->refundStructure() || !$reader->menusReady()) throw new Failure(Failure::INCOMPATIBLE);
        } catch (Failure $error) { throw $error; }
        catch (Throwable $error) { throw new Failure(Failure::UNAVAILABLE); }
    }

    private function refundStructure(): bool
    {
        $rows=$this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [$this->database, $this->prefix . self::TABLE]);
        if ($rows === []) return false;
        if (count($rows) !== 1) throw new Failure(Failure::INCOMPATIBLE);
        $metadata=$this->metadata(self::TABLE);
        if ($metadata === null || !$this->sameNewTable($metadata, self::newTables()[self::TABLE])) {
            throw new Failure(Failure::INCOMPATIBLE);
        }
        return true;
    }

    private function menusReady(): bool
    {
        if (!$this->compatibleMenus()) return false;
        $anchors=$this->anchors('');
        if ($anchors['issues'] !== []) return false;
        $expectedRows=self::menuRows($anchors['parent_id']);
        $registered=$this->rows('SELECT * FROM ' . self::quote($this->menuTable) . ' WHERE auth_type = 2 ORDER BY id', []);
        $menuIds=[];
        $expectedPermissions=array_column($expectedRows, 'unique_auth');
        foreach ($expectedRows as $expected) {
            $matches=[];
            foreach ($this->rows('SELECT * FROM ' . self::quote($this->menuTable) . ' WHERE unique_auth = ? ORDER BY id',
                [$expected['unique_auth']]) as $row) $matches[(string)$row['id']]=$row;
            foreach ($registered as $row) if (trim(strtolower((string)$row['methods'])) === strtolower($expected['methods'])
                && trim(strtolower(str_replace(' ', '', (string)$row['api_url']))) === $expected['api_url']) {
                $matches[(string)$row['id']]=$row;
            }
            if (count($matches) !== 1) return false;
            $row=array_values($matches)[0];
            if (!$this->sameMenu($row, $expected)) return false;
            $menuIds[$expected['unique_auth']]=(int)$row['id'];
        }
        foreach ($this->rows('SELECT * FROM ' . self::quote($this->menuTable) . ' WHERE mark = ? ORDER BY id',
            [self::MENU_MARKER]) as $row) if (!in_array($row['unique_auth'], $expectedPermissions, true)) return false;
        return count($menuIds) === 1;
    }

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

    private function anchors(string $suffix): array
    {
        $definitions = [
            'order_root' => ['unique_auth'=>'admin-order', 'auth_type'=>'1', 'menu_path'=>'/order', 'api_url'=>'', 'methods'=>'', 'pid'=>'0'],
            'refund_page' => ['unique_auth'=>'admin-order-refund', 'auth_type'=>'1', 'menu_path'=>'/order/refund', 'api_url'=>'', 'methods'=>''],
        ];
        $selected = [];
        foreach ($definitions as $name=>$expected) {
            $rows = $this->rows('SELECT * FROM ' . self::quote($this->menuTable) . ' WHERE unique_auth = ? ORDER BY id' . $suffix, [$expected['unique_auth']]);
            if (count($rows) !== 1) return ['issues'=>[$name . '_missing_or_ambiguous']];
            $row = $rows[0]; $id = (string)($row['id'] ?? '');
            if (count($row) !== 22 || preg_match('/\A[1-9][0-9]{0,4}\z/D', $id) !== 1 || (int)$id > 65535)
                return ['issues'=>[$name . '_identity_conflict']];
            foreach ($expected + ['is_show'=>'1', 'is_show_path'=>'1', 'access'=>'1', 'is_del'=>'0'] as $field=>$value) {
                if (!array_key_exists($field, $row) || $row[$field] === null || (string)$row[$field] !== $value)
                    return ['issues'=>[$name . '_identity_conflict']];
            }
            $selected[$name] = $row;
        }
        if ((string)$selected['refund_page']['pid'] !== (string)$selected['order_root']['id'])
            return ['issues'=>['refund_anchor_chain_conflict']];
        return ['issues'=>[], 'parent_id'=>(int)$selected['refund_page']['id']];
    }

    public static function menuRows(int $parent): array
    {
        if ($parent < 1 || $parent > 65535) throw new RuntimeException('Native identity unavailable');
        $common = ['pid'=>$parent,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>self::MENU_MARKER];
        return [$common + ['menu_name'=>'执行测试退款','methods'=>'POST',
            'api_url'=>'marketplace/aftersales/<id>/test-refund','unique_auth'=>'marketplace-aftersales-test-refund']];
    }

    private function sameMenu(array $actual, array $expected): bool
    {
        $id = (string)($actual['id'] ?? '');
        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $id) !== 1 || (int)$id > 65535 || count($actual) !== 22) return false;
        foreach ($expected as $key=>$value) if (!array_key_exists($key, $actual) || $actual[$key] === null
            || (string)$actual[$key] !== (string)$value) return false;
        return true;
    }

    private function compatibleMenus(): bool
    {
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database,$this->menuTable]);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB') !== 0
            || !is_string($tables[0]['TABLE_COLLATION']) || !str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_')) return false;
        $columns = $this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database,$this->menuTable]);
        $expected = [
            ['id','smallint unsigned',null,true], ['pid','smallint unsigned','0'], ['icon','varchar(16)',''], ['menu_name','varchar(32)',''],
            ['module','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['controller','varchar(64)','',false,'utf8mb3','utf8mb3_bin'],
            ['action','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['api_url','varchar(100)',''], ['methods','varchar(10)',''],
            ['params','varchar(128)','[]',false,'utf8mb3','utf8mb3_bin'], ['sort','tinyint','1'], ['is_show','tinyint unsigned','1'],
            ['is_show_path','tinyint','0'], ['access','tinyint unsigned','1'], ['menu_path','varchar(128)',''], ['path','varchar(255)',''],
            ['auth_type','tinyint','0'], ['header','varchar(50)',''], ['is_header','tinyint','0'], ['unique_auth','varchar(150)',''],
            ['is_del','tinyint','0'], ['mark','varchar(500)',''],
        ];
        if (count($columns) !== count($expected)) return false;
        foreach ($expected as $index=>$column) {
            $actual = $columns[$index]; $text = str_starts_with($column[1], 'varchar(');
            $charset = $text ? ($column[4] ?? 'utf8mb4') : null;
            $collation = $text ? ($column[5] ?? $tables[0]['TABLE_COLLATION']) : null;
            $actualCharset = $actual['CHARACTER_SET_NAME'] === 'utf8' ? 'utf8mb3' : $actual['CHARACTER_SET_NAME'];
            $actualCollation = $actual['COLLATION_NAME'] === 'utf8_bin' ? 'utf8mb3_bin' : $actual['COLLATION_NAME'];
            $type = preg_replace('/\b(tinyint|smallint|int)\([0-9]+\)/', '$1', strtolower((string)$actual['COLUMN_TYPE']));
            $default = $actual['COLUMN_DEFAULT'] === null ? null : (string)$actual['COLUMN_DEFAULT'];
            if ($actual['COLUMN_NAME'] !== $column[0] || $type !== $column[1] || $actual['IS_NULLABLE'] !== 'NO'
                || $default !== $column[2] || $actualCharset !== $charset || $actualCollation !== $collation
                || $actual['EXTRA'] !== (($column[3] ?? false) ? 'auto_increment' : '') || $actual['GENERATION_EXPRESSION'] !== '') return false;
        }
        $indexes = $this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database,$this->menuTable]);
        $actualIndexes = [];
        foreach ($indexes as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
                || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A' || !in_array((string)$row['NON_UNIQUE'], ['0','1'], true)) return false;
            $name = $row['INDEX_NAME']; $unique = (string)$row['NON_UNIQUE'] === '0';
            $actualIndexes[$name] ??= [$unique, []];
            if ($actualIndexes[$name][0] !== $unique || (int)$row['SEQ_IN_INDEX'] !== count($actualIndexes[$name][1]) + 1) return false;
            $actualIndexes[$name][1][] = $row['COLUMN_NAME'];
        }
        $expectedIndexes = ['PRIMARY'=>[true,['id']], 'pid'=>[false,['pid']], 'is_show'=>[false,['is_show']], 'access'=>[false,['access']]];
        ksort($actualIndexes); ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) return false;
        foreach ([['SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->database,$this->menuTable]],
            ["SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'", [$this->database,$this->menuTable]],
            ['SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?', [$this->database,$this->menuTable]]] as [$sql,$arguments]) {
            $rows = $this->rows($sql,$arguments);
            if (count($rows) !== 1 || (string)$rows[0]['n'] !== '0') return false;
        }
        return true;
    }

    private function execute(PDO $pdo,string $sql,array $args):PDOStatement
    {
        $s=$pdo->prepare($sql);if(!$s instanceof PDOStatement)throw new RuntimeException('Metadata unavailable');
        foreach(array_values($args) as $i=>$v){
            if($v!==null&&!is_int($v)&&!is_string($v))throw new RuntimeException('Metadata unavailable');
            if(!$s->bindValue($i+1,$v===null?null:(string)$v,$v===null?PDO::PARAM_NULL:PDO::PARAM_STR))throw new RuntimeException('Metadata unavailable');
        }
        if(!$s->execute())throw new RuntimeException('Metadata unavailable');return $s;
    }

    private function rows(string $sql, array $arguments): array
    { return $this->execute($this->pdo, $sql, $arguments)->fetchAll(PDO::FETCH_ASSOC); }

    private static function quote(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) throw new Failure(Failure::UNAVAILABLE);
        return '`' . $name . '`';
    }
}
