<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms1.php';

/** Additive draft storage and two ungranted permission leaves; never activates funds. */
final class MarketplaceFinanceSettingsMigration
{
    public const FIELDS = ['contracting_entity','payment_provider','payment_product','account_model',
        'platform_fee_enabled','aftersale_hold','platform_fee_payer','channel_fee_payer','platform_fee_basis',
        'platform_fee_rate','settlement_trigger','settlement_cycle','settlement_delay_days','refund_platform_fee'];
    private const MARKER = 'marketplace-finance-settings-v1';
    private const SETTINGS = 'marketplace_finance_settings';
    private const HISTORY = 'marketplace_finance_settings_history';
    private string $database;
    private array $progress = [];

    public function __construct(private PDO $pdo, private string $prefix = 'eb_')
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_PERSISTENT) !== false
            || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) throw new RuntimeException('Configuration unavailable');
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) throw new RuntimeException('Configuration unavailable');
        $this->database = $database;
        foreach ([self::SETTINGS,self::HISTORY,'system_menus'] as $suffix) $this->table($suffix);
        $this->reset();
    }

    private function reset(): void
    {
        $this->progress = ['phase'=>'inspection','write_attempted'=>false,'ddl_attempted'=>[],
            'ddl_completed'=>[],'ddl_outcome_unknown'=>false,'dml_commit_attempted'=>false,
            'dml_commit_observed'=>false,'rollback_observed'=>null,'lock_acquired'=>false,
            'lock_release_observed'=>null];
    }

    public function check(): array
    {
        $this->reset();
        try { return $this->receipt('check', $this->inspect(), false); }
        catch (Throwable $error) { return $this->failed('check', $error); }
    }

    public function apply(): array
    {
        $this->reset(); $view = null; $failure = null; $ownsTransaction = false; $requestLock = false;
        $connection = null; $changed = false;
        $lock = 'finance-settings:' . substr(hash('sha256', $this->database . "\0" . $this->prefix), 0, 40);
        try {
            $view = $this->inspect();
            if ($view['issues'] !== [] || $view['pending'] === []) return $this->receipt('apply', $view, false);
            $this->progress['phase'] = 'lock';
            $connection = (string)$this->scalar('SELECT CONNECTION_ID()');
            if ((string)$this->scalar('SELECT IS_USED_LOCK(?)', [$lock]) === $connection)
                throw new RuntimeException('Caller already holds migration lock');
            $requestLock = true;
            if ((string)$this->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== '1') throw new RuntimeException('Lock unavailable');
            $this->progress['lock_acquired'] = true;
            $this->progress['phase'] = 'locked_precheck'; $view = $this->inspect();
            if ($view['issues'] !== []) throw new RuntimeException('Concurrent schema conflict');
            foreach ($this->definitions() as $suffix=>$definition) {
                if ($view['tables'][$suffix] !== 'missing') continue;
                $this->progress['phase'] = 'create.' . $suffix;
                $this->progress['write_attempted'] = true;
                $this->progress['ddl_attempted'][] = $suffix;
                $this->progress['ddl_outcome_unknown'] = true;
                if ($this->pdo->exec($this->createSql($suffix, $definition)) === false) throw new RuntimeException('DDL unavailable');
                $this->progress['ddl_completed'][] = $suffix;
                $this->progress['ddl_outcome_unknown'] = false; $changed = true;
                $this->progress['phase'] = 'readback.' . $suffix; $view = $this->inspect();
                if ($view['issues'] !== [] || $view['tables'][$suffix] !== 'compatible') throw new RuntimeException('DDL readback unavailable');
            }
            $this->progress['phase'] = 'begin_transaction';
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transaction unavailable');
            $ownsTransaction = true; $before = $this->inspect(true);
            if ($before['issues'] !== []) throw new RuntimeException('DML precheck conflict');
            $created = [];
            if ($before['settings'] === []) {
                $this->progress['phase'] = 'insert.singleton'; $this->progress['write_attempted'] = true;
                $this->execute('INSERT INTO ' . $this->quoteTable(self::SETTINGS)
                    . ' (id,revision,payload,updated_by,updated_at) VALUES (1,0,?,NULL,NULL)', [self::initialJson()]);
                $created[] = 'singleton';
            }
            foreach (self::menuRows() as $menu) {
                if (isset($before['menu_ids'][$menu['unique_auth']])) continue;
                $this->progress['phase'] = 'insert.' . $menu['unique_auth']; $this->progress['write_attempted'] = true;
                $this->execute('INSERT INTO ' . $this->quoteTable('system_menus') . ' (`'
                    . implode('`,`', array_keys($menu)) . '`) VALUES (' . implode(',', array_fill(0, count($menu), '?')) . ')', array_values($menu));
                $created[] = $menu['unique_auth'];
            }
            $this->progress['phase'] = 'transaction_readback'; $after = $this->inspect(true);
            if ($after['issues'] !== [] || $after['pending'] !== []) throw new RuntimeException('DML readback unavailable');
            $this->preserve($before, $after, $created);
            $this->progress['phase'] = 'commit'; $this->progress['dml_commit_attempted'] = true;
            if (!$this->pdo->commit()) throw new RuntimeException('Commit unavailable');
            $ownsTransaction = false; $this->progress['dml_commit_observed'] = true; $changed = $changed || $created !== [];
            $this->progress['phase'] = 'postcheck'; $view = $this->inspect();
            if ($view['issues'] !== [] || $view['pending'] !== []) throw new RuntimeException('Postcheck unavailable');
            $this->preserve($before, $view, $created);
            $this->progress['phase'] = 'completed';
        } catch (Throwable $error) {
            $failure = $error;
            if ($ownsTransaction) {
                try { $this->progress['rollback_observed'] = $this->pdo->inTransaction() ? $this->pdo->rollBack() : false; }
                catch (Throwable $rollbackError) { $this->progress['rollback_observed'] = false; }
            }
        } finally {
            if ($requestLock) {
                try {
                    if ((string)$this->scalar('SELECT CONNECTION_ID()') !== $connection) throw new RuntimeException('Lock session changed');
                    if ((string)$this->scalar('SELECT IS_USED_LOCK(?)', [$lock]) === $connection) {
                        $this->progress['lock_release_observed'] = (string)$this->scalar('SELECT RELEASE_LOCK(?)', [$lock]) === '1';
                        if (!$this->progress['lock_release_observed']) throw new RuntimeException('Release unavailable');
                    } else $this->progress['lock_release_observed'] = !$this->progress['lock_acquired'];
                    if (!$this->progress['lock_release_observed']) throw new RuntimeException('Lock ownership changed');
                } catch (Throwable $releaseError) {
                    $this->progress['lock_release_observed'] = false; $failure ??= $releaseError;
                }
            }
        }
        return $failure !== null ? $this->failed('apply', $failure) : $this->receipt('apply', $view, $changed);
    }

    private static function initialJson(): string
    { return json_encode(array_fill_keys(self::FIELDS, null), JSON_THROW_ON_ERROR); }

    private function definitions(): array
    {
        return [self::SETTINGS=>[
            ['id','tinyint unsigned',false],['revision','int unsigned',false],['payload','json',false],
            ['updated_by','int unsigned',true],['updated_at','int unsigned',true]],
            self::HISTORY=>[['revision','int unsigned',false],['payload','json',false],['changed_fields','json',false],
                ['actor_id','int unsigned',false],['created_at','int unsigned',false]]];
    }

    private function createSql(string $suffix, array $columns): string
    {
        $parts=[]; foreach ($columns as [$name,$type,$nullable]) $parts[]='`'.$name.'` '.$type.($nullable?' NULL':' NOT NULL');
        $parts[]='PRIMARY KEY (`'.$columns[0][0].'`)';
        return 'CREATE TABLE '.$this->quoteTable($suffix).' ('.implode(',', $parts).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    }

    private function inspect(bool $lock = false): array
    {
        if ($lock !== $this->pdo->inTransaction()) throw new RuntimeException('Unexpected transaction state');
        $tables=[]; $issues=[]; $pending=[]; $settings=[]; $history=[];
        foreach ($this->definitions() as $suffix=>$columns) {
            $meta=$this->tableMetadata($suffix);
            $tables[$suffix]=$meta===[]?'missing':($this->compatibleTable($suffix,$meta,$columns)?'compatible':'incompatible');
            if ($tables[$suffix]==='missing') $pending[]='table.'.$suffix;
            elseif ($tables[$suffix]==='incompatible') $issues[]='schema.'.$suffix;
        }
        if (!$this->menusCompatible()) $issues[]='schema.system_menus';
        if ($issues!==[]) return compact('tables','issues','pending','settings','history')+['menus'=>[],'menu_ids'=>[]];
        $suffix=$lock?' FOR UPDATE':'';
        if ($tables[self::SETTINGS]==='compatible') $settings=$this->rows('SELECT * FROM '.$this->quoteTable(self::SETTINGS).' ORDER BY id'.$suffix);
        if ($tables[self::HISTORY]==='compatible') $history=$this->rows('SELECT * FROM '.$this->quoteTable(self::HISTORY).' ORDER BY revision'.$suffix);
        if ($settings===[]) {
            if ($history!==[]) $issues[]='history_without_singleton';
            else $pending[]='singleton';
        } elseif (count($settings)!==1 || (string)$settings[0]['id']!=='1') $issues[]='singleton_identity';
        else {
            $row=$settings[0]; $revision=self::unsigned($row['revision'],true);
            if ($revision===null || !$this->payload($row['payload'])) $issues[]='singleton_payload';
            elseif ($revision===0) {
                $draft=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR); ksort($draft);
                $initial=array_fill_keys(self::FIELDS,null); ksort($initial);
                if ($draft!==$initial || $row['updated_by']!==null || $row['updated_at']!==null || $history!==[]) $issues[]='initial_draft_conflict';
            } else {
                if (self::unsigned($row['updated_by'])===null || self::unsigned($row['updated_at'])===null || count($history)!==$revision) $issues[]='history_revision_conflict';
                foreach ($history as $index=>$version) {
                    $fields=json_decode($version['changed_fields'],true,32,JSON_THROW_ON_ERROR);
                    if (self::unsigned($version['revision'])!==$index+1 || !$this->payload($version['payload'])
                        || self::unsigned($version['actor_id'])===null || self::unsigned($version['created_at'])===null
                        || !is_array($fields) || !array_is_list($fields) || $fields===[]
                        || count(array_filter($fields,'is_string'))!==count($fields)
                        || count($fields)!==count(array_unique($fields,SORT_STRING)) || array_diff($fields,self::FIELDS)!==[]) $issues[]='history_payload_conflict';
                }
                $last=$history===[]?null:$history[array_key_last($history)];
                if ($last===null || (string)$last['revision']!==(string)$row['revision'] || $last['payload']!==$row['payload']
                    || (string)$last['actor_id']!==(string)$row['updated_by'] || (string)$last['created_at']!==(string)$row['updated_at']) $issues[]='current_history_conflict';
            }
        }
        $menus=$this->rows('SELECT * FROM '.$this->quoteTable('system_menus').' ORDER BY id'.$suffix); $menuIds=[];
        foreach (self::menuRows() as $expected) {
            $matches=[];
            // Let the installed column collation find permission collisions before exact field comparison.
            foreach ($this->rows('SELECT * FROM '.$this->quoteTable('system_menus').' WHERE unique_auth=? ORDER BY id'.$suffix,
                [$expected['unique_auth']]) as $row) $matches[(string)$row['id']]=$row;
            foreach ($menus as $row) if ((string)$row['auth_type']==='2' && trim(strtolower((string)$row['methods']))===strtolower($expected['methods'])
                && trim(strtolower(str_replace(' ','',(string)$row['api_url'])))===$expected['api_url']) $matches[(string)$row['id']]=$row;
            $matches=array_values($matches);
            if ($matches===[]) $pending[]='menu.'.$expected['unique_auth'];
            elseif (count($matches)!==1 || !$this->sameMenu($matches[0],$expected)) $issues[]='menu_conflict.'.$expected['unique_auth'];
            else $menuIds[$expected['unique_auth']]=(int)$matches[0]['id'];
        }
        foreach ($this->rows('SELECT * FROM '.$this->quoteTable('system_menus').' WHERE mark=? ORDER BY id'.$suffix,[self::MARKER]) as $row)
            if (!in_array($row['unique_auth'],array_column(self::menuRows(),'unique_auth'),true)) $issues[]='menu_marker_conflict';
        $issues=array_values(array_unique($issues));
        return compact('tables','issues','pending','settings','history','menus')+['menu_ids'=>$menuIds];
    }

    private function payload(mixed $raw): bool
    {
        if (!is_string($raw)) return false;
        $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) return false;
        $keys=array_keys($value);sort($keys,SORT_STRING);$expected=self::FIELDS;sort($expected,SORT_STRING);
        return $keys===$expected;
    }

    private static function unsigned(mixed $value, bool $zero = false): ?int
    {
        if ((!is_int($value)&&!is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)!==1
            || (int)$value>4294967295 || (!$zero&&(string)$value==='0')) return null;
        return (int)$value;
    }

    private function preserve(array $before, array $after, array $created): void
    {
        if ($before['history']!==$after['history'] || ($before['settings']!==[] && $before['settings']!==$after['settings']))
            throw new RuntimeException('Existing draft or history changed');
        $old=array_column($before['menus'],null,'id');$now=array_column($after['menus'],null,'id');
        foreach ($old as $id=>$row) if (($now[$id]??null)!==$row) throw new RuntimeException('Existing menu changed');
        $new=array_diff_key($now,$old);$expected=array_values(array_diff($created,['singleton']));
        if (count($new)!==count($expected)) throw new RuntimeException('Unexpected menu insert');
        foreach ($new as $row) if (!in_array($row['unique_auth'],$expected,true)) throw new RuntimeException('Unexpected permission');
    }

    private static function menuRows(): array
    {
        $common=['pid'=>0,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>self::MARKER,'api_url'=>'marketplace/finance-settings'];
        return [$common+['menu_name'=>'查看资金设置','methods'=>'GET','unique_auth'=>'marketplace-finance-settings-read'],
            $common+['menu_name'=>'保存资金设置','methods'=>'PUT','unique_auth'=>'marketplace-finance-settings-write']];
    }

    private function sameMenu(array $actual, array $expected): bool
    {
        $id=self::unsigned($actual['id']??null); if ($id===null || $id>65535 || count($actual)!==22) return false;
        foreach ($expected as $key=>$value) if (!array_key_exists($key,$actual) || $actual[$key]===null || (string)$actual[$key]!==(string)$value) return false;
        return true;
    }

    private function tableMetadata(string $suffix): array
    { return $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$this->database,$this->table($suffix)]); }

    private function compatibleTable(string $suffix, array $meta, array $expected): bool
    {
        if (count($meta)!==1 || $meta[0]!==['TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_bin','TABLE_COMMENT'=>'']) return false;
        $args=[$this->database,$this->table($suffix)];
        $columns=$this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',$args);
        if (count($columns)!==count($expected)) return false;
        foreach ($expected as $i=>[$name,$type,$nullable]) {
            $row=$columns[$i];$row['COLUMN_TYPE']=preg_replace('/\b(tinyint|int)\([0-9]+\)/','$1',strtolower($row['COLUMN_TYPE']));
            if ($row!==['COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$type,'IS_NULLABLE'=>$nullable?'YES':'NO','COLUMN_DEFAULT'=>null,
                'CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null,'EXTRA'=>'','GENERATION_EXPRESSION'=>'','COLUMN_COMMENT'=>'']) return false;
        }
        $indexes=$this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',$args);
        if (count($indexes)!==1) return false; $index=$indexes[0];
        if ($index['INDEX_NAME']!=='PRIMARY' || (string)$index['NON_UNIQUE']!=='0' || (string)$index['SEQ_IN_INDEX']!=='1'
            || $index['COLUMN_NAME']!==$expected[0][0] || $index['SUB_PART']!==null || $index['INDEX_TYPE']!=='BTREE'
            || $index['IS_VISIBLE']!=='YES' || $index['EXPRESSION']!==null || $index['COLLATION']!=='A') return false;
        $constraints=$this->rows('SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',$args);
        return $constraints===[['CONSTRAINT_NAME'=>'PRIMARY','CONSTRAINT_TYPE'=>'PRIMARY KEY']]
            && (string)$this->scalar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?',$args)==='0';
    }

    // The finite installed menu compatibility checker is retained from the existing menu migrations.
    private function menusCompatible(): bool
    {
        $table=$this->table('system_menus');$args=[$this->database,$table];$tables=$this->tableMetadata('system_menus');
        if (count($tables)!==1 || $tables[0]['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB')!==0
            || !is_string($tables[0]['TABLE_COLLATION']) || !str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_')) return false;
        $columns=$this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',$args);
        $expected=[['id','smallint unsigned',null,true],['pid','smallint unsigned','0'],['icon','varchar(16)',''],['menu_name','varchar(32)',''],
            ['module','varchar(32)','',false,'utf8mb3','utf8mb3_bin'],['controller','varchar(64)','',false,'utf8mb3','utf8mb3_bin'],
            ['action','varchar(32)','',false,'utf8mb3','utf8mb3_bin'],['api_url','varchar(100)',''],['methods','varchar(10)',''],
            ['params','varchar(128)','[]',false,'utf8mb3','utf8mb3_bin'],['sort','tinyint','1'],['is_show','tinyint unsigned','1'],
            ['is_show_path','tinyint','0'],['access','tinyint unsigned','1'],['menu_path','varchar(128)',''],['path','varchar(255)',''],
            ['auth_type','tinyint','0'],['header','varchar(50)',''],['is_header','tinyint','0'],['unique_auth','varchar(150)',''],['is_del','tinyint','0'],['mark','varchar(500)','']];
        if (count($columns)!==count($expected)) return false;
        foreach ($expected as $i=>$column) {
            $row=$columns[$i];$text=str_starts_with($column[1],'varchar(');
            $charset=$text?($column[4]??'utf8mb4'):null;$collation=$text?($column[5]??$tables[0]['TABLE_COLLATION']):null;
            $actualCharset=$row['CHARACTER_SET_NAME']==='utf8'?'utf8mb3':$row['CHARACTER_SET_NAME'];
            $actualCollation=$row['COLLATION_NAME']==='utf8_bin'?'utf8mb3_bin':$row['COLLATION_NAME'];
            $type=preg_replace('/\b(tinyint|smallint|int)\([0-9]+\)/','$1',strtolower((string)$row['COLUMN_TYPE']));
            $default=$row['COLUMN_DEFAULT']===null?null:(string)$row['COLUMN_DEFAULT'];
            if ($row['COLUMN_NAME']!==$column[0] || $type!==$column[1] || $row['IS_NULLABLE']!=='NO' || $default!==$column[2]
                || $actualCharset!==$charset || $actualCollation!==$collation || $row['EXTRA']!==(($column[3]??false)?'auto_increment':'')
                || $row['GENERATION_EXPRESSION']!=='') return false;
        }
        $indexes=$this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',$args);$actual=[];
        foreach ($indexes as $row) {
            if ($row['SUB_PART']!==null || $row['INDEX_TYPE']!=='BTREE' || $row['IS_VISIBLE']!=='YES' || $row['EXPRESSION']!==null
                || $row['COLLATION']!=='A' || !in_array((string)$row['NON_UNIQUE'],['0','1'],true)) return false;
            $name=$row['INDEX_NAME'];$unique=(string)$row['NON_UNIQUE']==='0';$actual[$name]??=[$unique,[]];
            if ($actual[$name][0]!==$unique || (int)$row['SEQ_IN_INDEX']!==count($actual[$name][1])+1) return false;
            $actual[$name][1][]=$row['COLUMN_NAME'];
        }
        $wanted=['PRIMARY'=>[true,['id']],'pid'=>[false,['pid']],'is_show'=>[false,['is_show']],'access'=>[false,['access']]];ksort($actual);ksort($wanted);
        if ($actual!==$wanted) return false;
        foreach (['SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'",
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?'] as $sql)
            if ((string)$this->scalar($sql,$args)!=='0') return false;
        return true;
    }

    private function rows(string $sql, array $values = []): array
    { $statement=$this->pdo->prepare($sql);if (!$statement || !$statement->execute($values)) throw new RuntimeException('Inspection unavailable');return $statement->fetchAll(PDO::FETCH_ASSOC); }
    private function scalar(string $sql, array $values = []): mixed
    { $rows=$this->rows($sql,$values);if (count($rows)!==1 || count($rows[0])!==1) throw new RuntimeException('Scalar unavailable');return array_values($rows[0])[0]; }
    private function execute(string $sql, array $values): void
    { $statement=$this->pdo->prepare($sql);if (!$statement || !$statement->execute($values) || $statement->rowCount()!==1) throw new RuntimeException('Insert unavailable'); }
    private function table(string $suffix): string
    { $name=$this->prefix.$suffix;if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name)!==1) throw new RuntimeException('Configuration unavailable');return $name; }
    private function quoteTable(string $suffix): string { return '`'.$this->table($suffix).'`'; }

    private function receipt(string $mode, array $view, bool $changed): array
    {
        return ['mode'=>$mode,'state'=>$view['issues']!==[]?'incompatible':($view['pending']===[]?'completed':'applicable'),
            'changed'=>$changed,'tables'=>$view['tables'],'pending'=>$view['pending'],'menu_ids'=>$view['menu_ids'],
            'issues'=>$view['issues'],'revision'=>$view['settings']===[]?null:(int)$view['settings'][0]['revision'],
            'history_count'=>count($view['history']),'roles_modified'=>false,'real_funds_enabled'=>false,
            'progress'=>$this->progress,'source_sha256'=>hash_file('sha256',__FILE__)];
    }

    private function failed(string $mode, Throwable $error): array
    {
        $state=null;$vendor=null;if ($error instanceof PDOException && is_array($error->errorInfo)) {
            if (is_string($error->errorInfo[0]??null) && preg_match('/\A[0-9A-Z]{5}\z/D',$error->errorInfo[0])===1) $state=$error->errorInfo[0];
            if (is_int($error->errorInfo[1]??null)) $vendor=$error->errorInfo[1];
        }
        return ['mode'=>$mode,'state'=>'failed','changed'=>$this->progress['write_attempted']?null:false,
            'tables'=>[],'pending'=>[],'menu_ids'=>[],'issues'=>['migration_failed'],'roles_modified'=>false,'real_funds_enabled'=>false,
            'progress'=>$this->progress,'diagnostic'=>['class'=>$error instanceof PDOException?'PDOException':get_debug_type($error),
                'sqlstate'=>$state,'vendor_code'=>$vendor],'source_sha256'=>hash_file('sha256',__FILE__)];
    }
}

function marketplaceFinanceSettingsMain(array $arguments): int
{
    if ($arguments===['help'] || $arguments===['--help']) {
        echo "Usage: php help/migrations/marketplace-finance-settings.php [check|apply] --config-file=PRIVATE_JSON\nDefault check is read-only. Apply only adds absent draft tables, initial singleton and two permission leaves; roles and real funds are unchanged.\n";return 0;
    }
    $mode='check';$selected=false;$file=null;$migration=null;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument,['check','apply'],true) && !$selected) {$mode=$argument;$selected=true;}
            elseif (str_starts_with($argument,'--config-file=') && $file===null && strlen($argument)>14) $file=substr($argument,14);
            else throw new RuntimeException('Configuration unavailable');
        }
        if ($file===null) throw new RuntimeException('Explicit private configuration required');
        $config=\Audit\Migration\marketplaceMs1Configuration($file);
        $pdo=new PDO('mysql:host='.$config['hostname'].';port='.$config['hostport'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
        $migration=new MarketplaceFinanceSettingsMigration($pdo,$config['prefix']);$result=$mode==='apply'?$migration->apply():$migration->check();
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        return in_array($result['state'],['completed','applicable'],true)?0:1;
    } catch (Throwable $error) {
        echo json_encode(['mode'=>$mode,'state'=>'failed','changed'=>$migration instanceof MarketplaceFinanceSettingsMigration?null:false,
            'issues'=>[$migration instanceof MarketplaceFinanceSettingsMigration?'receipt_unavailable':'configuration_or_connection_failed']],JSON_THROW_ON_ERROR)."\n";return 1;
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) exit(marketplaceFinanceSettingsMain(array_slice($argv,1)));
