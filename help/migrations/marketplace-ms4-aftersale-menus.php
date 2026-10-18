<?php
declare(strict_types=1);

/** Finite Task7 aftersale permission-menu DML. Including the original MS1 file never invokes its CLI main. */
require_once __DIR__ . '/marketplace-ms1.php';

final class MarketplaceMs4AftersaleMenus
{
    private const MARKER = 'marketplace-ms4-aftersale-menus-v1';
    private string $database;
    private string $table;

    public function __construct(private PDO $pdo, string $prefix)
    {
        if (!$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) throw new RuntimeException('Configuration unavailable');
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) throw new RuntimeException('Configuration unavailable');
        $this->database = $database;
        $this->table = self::identifier($prefix . 'system_menus');
    }

    public function check(): array { return $this->inspect('check'); }

    public function apply(): array
    {
        $locked = false; $committed = false; $created = []; $phase = 'precheck';
        $result = null;
        $lockName = 'ms4-aftersale:' . substr(hash('sha256', $this->database . "\0" . $this->table . "\0" . self::MARKER), 0, 47);
        try {
            if ($this->pdo->inTransaction()) return $this->result('apply', 'incompatible', [], false, ['active_transaction']);
            $before = $this->inspect('apply');
            if ($before['state'] === 'incompatible') return $before;
            $phase = 'named_lock';
            $lock = $this->rows('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (count($lock) !== 1 || (string)$lock[0]['acquired'] !== '1') throw new RuntimeException('Lock unavailable');
            $locked = true;
            $phase = 'begin_transaction';
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transaction unavailable');
            $phase = 'locked_recheck';
            $current = $this->inspect('apply', true);
            if ($current['state'] === 'incompatible') {
                if (!$this->pdo->rollBack()) throw new RuntimeException('Rollback unavailable');
                $result = $current;
            } else {
                $columns = array_keys(self::menuRows()[0]);
                $sql = 'INSERT INTO ' . self::quote($this->table) . ' (' . implode(',', array_map([self::class, 'quote'], $columns))
                    . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $insert = $this->pdo->prepare($sql);
                $phase = 'insert_missing_menus';
                foreach (self::menuRows() as $row) if (!isset($current['menu_ids'][$row['unique_auth']])) {
                    if (!$insert->execute(array_values($row)) || $insert->rowCount() !== 1) throw new RuntimeException('Insert unavailable');
                    $native = $this->pdo->lastInsertId();
                    if (!is_string($native) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $native) !== 1
                        || (int)$native > 65535) throw new RuntimeException('Native identity unavailable');
                    $created[$row['unique_auth']] = (int)$native;
                }
                $phase = 'postcheck';
                $after = $this->inspect('apply', true);
                if ($after['state'] !== 'completed' || $after['menu_ids'] !== $current['menu_ids'] + $created) {
                    // Compare maps by their fixed permission keys rather than incidental insertion order.
                    $wanted = $current['menu_ids'] + $created;
                    $actual = $after['menu_ids']; ksort($wanted); ksort($actual);
                    if ($after['state'] !== 'completed' || $actual !== $wanted) throw new RuntimeException('Postcheck unavailable');
                }
                $phase = 'commit';
                if (!$this->pdo->commit() || $this->pdo->inTransaction()) throw new RuntimeException('Commit unavailable');
                $committed = true;
                $result = $this->result('apply', 'completed', $after['menu_ids'], $created !== [], []);
                $result['created_menu_ids'] = $created;
            }
        } catch (Throwable $error) {
            $rolledBack = false;
            try { if ($this->pdo->inTransaction()) $rolledBack = $this->pdo->rollBack(); } catch (Throwable $ignored) {}
            $result = $this->result('apply', 'failed', [], $committed && $created !== [], [$phase . '_failed']);
            $result['commit_observed'] = $committed;
            $result['rollback_observed'] = $rolledBack;
            $result['created_menu_ids'] = $committed ? $created : [];
        } finally {
            if ($locked) {
                try {
                    $released = $this->rows('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                    if (count($released) !== 1 || (string)$released[0]['released'] !== '1') throw new RuntimeException('Release unavailable');
                } catch (Throwable $ignored) {
                    $result = $this->result('apply', 'failed', $result['menu_ids'] ?? [], $committed && $created !== [], ['lock_release_failed']);
                    $result['commit_observed'] = $committed;
                    $result['created_menu_ids'] = $committed ? $created : [];
                }
            }
        }
        if (!is_array($result)) throw new RuntimeException('Result unavailable');
        return $result;
    }

    private function inspect(string $mode, bool $lock = false): array
    {
        if (!$this->compatible()) return $this->result($mode, 'incompatible', [], false, ['menu_schema']);
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Transaction unavailable');
        $suffix = $lock ? ' FOR UPDATE' : '';
        $registered = $this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE auth_type = 2 ORDER BY id' . $suffix, []);
        $menuIds = []; $issues = []; $expectedPermissions = array_column(self::menuRows(), 'unique_auth');
        foreach (self::menuRows() as $expected) {
            $matches = [];
            foreach ($this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE unique_auth = ? ORDER BY id' . $suffix,
                [$expected['unique_auth']]) as $row) $matches[(string)$row['id']] = $row;
            foreach ($registered as $row) if (trim(strtolower((string)$row['methods'])) === strtolower($expected['methods'])
                && trim(strtolower(str_replace(' ', '', (string)$row['api_url']))) === $expected['api_url']) $matches[(string)$row['id']] = $row;
            if (count($matches) > 1) $issues[] = 'permission_or_route_collision';
            elseif ($matches !== []) {
                $row = array_values($matches)[0];
                if (!$this->sameMenu($row, $expected)) $issues[] = 'menu_fields_conflict';
                else $menuIds[$expected['unique_auth']] = (int)$row['id'];
            }
        }
        foreach ($this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE mark = ? ORDER BY id' . $suffix,
            [self::MARKER]) as $row) if (!in_array($row['unique_auth'], $expectedPermissions, true)) $issues[] = 'marker_conflict';
        return $this->result($mode, $issues ? 'incompatible' : (count($menuIds) === 7 ? 'completed' : 'applicable'),
            $menuIds, false, array_values(array_unique($issues)));
    }

    private static function menuRows(): array
    {
        $common = ['pid'=>0,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>self::MARKER];
        return array_map(static fn(array $leaf): array => $common + ['menu_name'=>$leaf[0], 'methods'=>$leaf[1],
            'api_url'=>$leaf[2], 'unique_auth'=>$leaf[3]], [
            ['售后退款列表','GET','marketplace/aftersales','marketplace-aftersales-list'],
            ['售后退款详情','GET','marketplace/aftersales/<id>','marketplace-aftersales-read'],
            ['同意售后申请','POST','marketplace/aftersales/<id>/approve','marketplace-aftersales-approve'],
            ['拒绝售后申请','POST','marketplace/aftersales/<id>/reject','marketplace-aftersales-reject'],
            ['确认验收退件','POST','marketplace/aftersales/<id>/return-accept','marketplace-aftersales-return-accept'],
            ['申请售后凭证读票','POST','marketplace/aftersale-files/<file_id>/read-ticket','marketplace-aftersale-files-ticket'],
            ['读取售后私有凭证','GET','marketplace/aftersale-files/<file_id>/content','marketplace-aftersale-files-read'],
        ]);
    }

    private function sameMenu(array $actual, array $expected): bool
    {
        $id = (string)($actual['id'] ?? '');
        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $id) !== 1 || (int)$id > 65535 || count($actual) !== 22) return false;
        foreach ($expected as $key=>$value) if (!array_key_exists($key, $actual) || $actual[$key] === null
            || (string)$actual[$key] !== (string)$value) return false;
        return true;
    }

    /** Exact installed menu structure used by the original MS1 menu compatibility check. */
    private function compatible(): bool
    {
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database,$this->table]);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB') !== 0
            || !is_string($tables[0]['TABLE_COLLATION']) || !str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_')) return false;
        $columns = $this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database,$this->table]);
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
        $indexes = $this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database,$this->table]);
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
        foreach ([['SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->database,$this->table]],
            ["SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'", [$this->database,$this->table]],
            ['SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?', [$this->database,$this->table]]] as [$sql,$arguments]) {
            $rows = $this->rows($sql,$arguments);
            if (count($rows) !== 1 || (string)$rows[0]['n'] !== '0') return false;
        }
        return true;
    }

    private function rows(string $sql, array $arguments): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement->execute($arguments)) throw new RuntimeException('Inspection unavailable');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function result(string $mode, string $state, array $menuIds, bool $changed, array $issues): array
    {
        return ['mode'=>$mode, 'state'=>$state, 'changed'=>$changed, 'tables'=>[], 'menu_ids'=>$menuIds,
            'issues'=>array_map(fn(string $kind): array => ['kind'=>$kind,'path'=>$this->table], $issues),
            'source_binding'=>['entry_sha256'=>hash_file('sha256',__FILE__), 'ms1_sha256'=>hash_file('sha256',__DIR__.'/marketplace-ms1.php')],
            'authorization_cache_producer'=>'fresh_scope_for_request_exact_all_auth_and_current_roles', 'roles_modified'=>false];
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name) !== 1) throw new RuntimeException('Configuration unavailable');
        return $name;
    }
    private static function quote(string $name): string { return '`' . self::identifier($name) . '`'; }
}

/** Failure-only finite labels: never serialize messages, arguments, SQL or raw traces. */
function marketplaceMs4AftersaleMenusDiagnostic(Throwable $error, string $phase): array
{
    $phases=['arguments','configuration','connection','constructor','check','apply','serialization'];
    $exceptions=['RuntimeException','InvalidArgumentException','LogicException','UnexpectedValueException',
        'PDOException','JsonException','Exception','Error','TypeError','ArgumentCountError','ValueError'];
    $sources=['marketplace-ms2-task7-menus.php','marketplace-ms2-task7-stock-menu.php',
        'marketplace-ms4-aftersale-menus.php','marketplace-ms1.php'];
    $classes=['MarketplaceMs2Task7Menus','MarketplaceMs2Task7StockMenu','MarketplaceMs4AftersaleMenus','PDO','PDOStatement'];
    $functions=['marketplaceMs2Task7MenusMain','marketplaceMs2Task7StockMenuMain','marketplaceMs4AftersaleMenusMain',
        'marketplaceMs1Configuration','__construct','check','apply','inspect','compatible','rows','result','identifier','quote',
        'sameMenu','menuRows','setAttribute','query','prepare','execute','fetchColumn','fetchAll','json_decode','json_encode',
        'fopen','stream_get_contents','fclose','parse_ini_file'];
    $sourceLabel=static function($source) use($sources): string {
        if(!is_string($source))return 'other_source';
        $basename=basename(str_replace('\\','/',$source));
        return in_array($basename,$sources,true)?$basename:'other_source';
    };
    $lineLabel=static fn($line): ?int => is_int($line)&&$line>=1&&$line<=1000000?$line:null;
    $frames=[];$trace=$error->getTrace();
    for($index=0;$index<6&&isset($trace[$index]);$index++){
        $frame=$trace[$index];if(!is_array($frame))continue;
        $class=$frame['class']??null;$function=$frame['function']??null;
        $frames[]=['source'=>$sourceLabel($frame['file']??null),'line'=>$lineLabel($frame['line']??null),
            'function'=>is_string($function)&&in_array($function,$functions,true)?$function:'other_function',
            'class'=>$class===null?null:(is_string($class)&&in_array($class,$classes,true)?$class:'other_class')];
    }
    $sqlstate=null;$vendorCode=null;
    if($error instanceof PDOException){
        $info=$error->errorInfo;
        if(is_array($info)){
            $state=$info[0]??null;$code=$info[1]??null;
            if(is_string($state)&&preg_match('/\A[0-9A-Z]{5}\z/D',$state)===1)$sqlstate=$state;
            if(is_int($code)&&$code>=0&&$code<=2147483647)$vendorCode=$code;
            elseif(is_string($code)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$code)===1&&(int)$code<=2147483647)$vendorCode=(int)$code;
        }
    }
    $class=get_class($error);
    return ['phase'=>in_array($phase,$phases,true)?$phase:'other_phase',
        'class'=>in_array($class,$exceptions,true)?$class:'other_class',
        'source'=>$sourceLabel($error->getFile()),'line'=>$lineLabel($error->getLine()),
        'frames'=>$frames,'sqlstate'=>$sqlstate,'vendor_code'=>$vendorCode];
}

function marketplaceMs4AftersaleMenusMain(array $arguments): int
{
    if ($arguments === ['--help'] || $arguments === ['help']) {
        echo "Usage: php help/migrations/marketplace-ms4-aftersale-menus.php [check|apply|--check|--apply] [--config-file=PATH]\nDefault: read-only check; the original private six-field MS1 configuration parser is used.\n";
        return 0;
    }
    $mode = 'check'; $selected = false; $file = null; $phase = 'arguments';
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument,['check','apply','--check','--apply'],true) && !$selected) { $mode = ltrim($argument,'-'); $selected = true; }
            elseif (str_starts_with($argument,'--config-file=') && $file === null && strlen($argument) > 14) $file = substr($argument,14);
            else throw new RuntimeException('Configuration unavailable');
        }
        $phase = 'configuration';
        $config = \Audit\Migration\marketplaceMs1Configuration($file);
        $phase = 'connection';
        $pdo = new PDO('mysql:host='.$config['hostname'].';port='.$config['hostport'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
        $phase = 'constructor';
        $migration = new MarketplaceMs4AftersaleMenus($pdo,$config['prefix']);
        $phase = $mode === 'apply' ? 'apply' : 'check';
        $receipt = $mode === 'apply' ? $migration->apply() : $migration->check();
        $phase = 'serialization';
        echo json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
        return in_array($receipt['state'],['completed','applicable'],true) ? 0 : 1;
    } catch (Throwable $error) {
        echo json_encode(['mode'=>$mode,'state'=>'incompatible','changed'=>false,'tables'=>[],'menu_ids'=>[],
            'issues'=>[['kind'=>'configuration_or_inspection_failed','path'=>'migration']],
            'diagnostic'=>marketplaceMs4AftersaleMenusDiagnostic($error,$phase)],JSON_THROW_ON_ERROR) . "\n";
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(marketplaceMs4AftersaleMenusMain(array_slice($argv,1)));
}
