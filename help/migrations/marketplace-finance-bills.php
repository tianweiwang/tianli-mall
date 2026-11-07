<?php
declare(strict_types=1);
require_once __DIR__.'/marketplace-ms1.php';
require_once __DIR__.'/marketplace-ms2-task7-submit-menu.php';
require_once __DIR__.'/../../crmeb/app/services/marketplace/finance/FinanceBillStore.php';
require_once __DIR__.'/../../crmeb/app/services/marketplace/finance/FinanceStatementStore.php';

use app\services\marketplace\finance\FinanceBillStore;
use app\services\marketplace\finance\FinanceStatementStore;

/** Additive tables and ungranted permission leaves. Existing material and roles are never updated. */
final class MarketplaceFinanceBillsMigration
{
    private string $database;
    private MarketplaceMs2Task7SubmitMenu $menus;
    public function __construct(private PDO $pdo,private string $prefix='eb_')
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||!in_array($pdo->getAttribute(PDO::ATTR_PERSISTENT),[false,0],true)
            ||!$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)||!$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)
            ||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.FinanceBillStore::RESULT)>64)throw new RuntimeException('Configuration unavailable');
        $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        if(!is_string($db)||preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$db)!==1)throw new RuntimeException('Database unavailable');
        $this->database=$db;$this->menus=new MarketplaceMs2Task7SubmitMenu($pdo,$prefix);
    }
    public function check():array
    {
        $tables=[];$issues=[];$pending=[];
        foreach(FinanceBillStore::tables()+FinanceStatementStore::tables() as $name=>$definition){
            $meta=$this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$this->database,$this->prefix.$name]);
            $tables[$name]=$meta===[]?'missing':($this->matches($name,$definition,$meta)?'compatible':'incompatible');
            if($tables[$name]==='missing')$pending[]='table.'.$name;
            elseif($tables[$name]==='incompatible')$issues[]='table.'.$name;
        }
        $structure=$this->menus->check();$menuIds=[];
        if(!in_array($structure['state']??null,['completed','applicable'],true)||($structure['issues']??null)!==[])$issues[]='system_menus_structure';
        else{
            $rows=$this->rows('SELECT * FROM `'.$this->prefix.'system_menus` ORDER BY id');
            foreach(self::menuRows() as $expected){
                $matches=[];
                foreach($rows as $row){
                    $sameKey=strtolower(trim((string)$row['unique_auth']))===$expected['unique_auth'];
                    $sameRoute=strtoupper(trim((string)$row['methods']))===$expected['methods']
                        &&strtolower(str_replace(' ','',trim((string)$row['api_url'])))===$expected['api_url'];
                    if($sameKey||$sameRoute)$matches[]=$row;
                }
                if($matches===[])$pending[]='menu.'.$expected['unique_auth'];
                elseif(count($matches)!==1||!self::sameMenu($matches[0],$expected))$issues[]='menu.'.$expected['unique_auth'];
                else $menuIds[$expected['unique_auth']]=(int)$matches[0]['id'];
            }
            $known=array_column(self::menuRows(),'unique_auth');
            foreach($rows as $row)if($row['mark']===FinanceBillStore::MARKER&&!in_array($row['unique_auth'],$known,true))$issues[]='menu.marker_conflict';
        }
        return ['state'=>$issues!==[]?'incompatible':($pending===[]?'completed':'applicable'),'tables'=>$tables,
            'menu_ids'=>$menuIds,'pending'=>$pending,'issues'=>array_values(array_unique($issues))];
    }
    public function apply():array
    {
        if($this->pdo->inTransaction())return ['state'=>'failed','issues'=>['active_transaction'],'created_tables'=>[]];
        $lock='finance-bills:'.substr(hash('sha256',$this->database."\0".$this->prefix),0,40);
        $locked=false;$transaction=false;$created=[];$createdMenus=[];$result=null;
        try{
            $before=$this->check();if($before['state']==='incompatible')return $before+['created_tables'=>[],'created_menus'=>[]];
            $current=$this->rows('SELECT CONNECTION_ID() AS id,IS_USED_LOCK(?) AS owner',[$lock])[0];
            if((string)$current['id']===(string)$current['owner'])throw new RuntimeException('Migration lock already held');
            if((string)$this->rows('SELECT GET_LOCK(?,0) AS acquired',[$lock])[0]['acquired']!=='1')throw new RuntimeException('Migration busy');
            $locked=true;$before=$this->check();if($before['state']==='incompatible')throw new RuntimeException('Structure changed');
            foreach(FinanceBillStore::tables()+FinanceStatementStore::tables() as $name=>$definition)if($before['tables'][$name]==='missing'){
                if($this->pdo->exec($this->createSql($name,$definition))===false)throw new RuntimeException('Table creation unavailable');
                $created[]=$name;
            }
            if($this->check()['state']==='incompatible')throw new RuntimeException('Created structure unavailable');
            if(!$this->pdo->beginTransaction())throw new RuntimeException('Transaction unavailable');$transaction=true;
            // Serialize the finite menu catalogue before checking for an existing key or route.
            $this->rows('SELECT id FROM `'.$this->prefix.'system_menus` ORDER BY id FOR UPDATE');
            $state=$this->check();if($state['state']==='incompatible')throw new RuntimeException('Menu conflict');
            foreach(self::menuRows() as $menu)if(!isset($state['menu_ids'][$menu['unique_auth']])){
                $this->rows('INSERT INTO `'.$this->prefix.'system_menus` (`'.implode('`,`',array_keys($menu)).'`) VALUES ('
                    .implode(',',array_fill(0,count($menu),'?')).')',array_values($menu));$createdMenus[]=$menu['unique_auth'];
            }
            $result=$this->check();if($result['state']!=='completed')throw new RuntimeException('Migration readback unavailable');
            if(!$this->pdo->commit())throw new RuntimeException('Commit unavailable');$transaction=false;
            $result=$this->check();
        }catch(Throwable $error){
            $rolledBack=null;if($transaction){try{$rolledBack=$this->pdo->inTransaction()&&$this->pdo->rollBack();}catch(Throwable $ignored){$rolledBack=false;}}
            $result=['state'=>'failed','issues'=>['migration_failed'],'rollback_observed'=>$rolledBack];
        }finally{
            if($locked){try{$released=(string)$this->rows('SELECT RELEASE_LOCK(?) AS released',[$lock])[0]['released']==='1';}
                catch(Throwable $ignored){$released=false;}
                if(!$released)$result=['state'=>'failed','issues'=>['migration_lock_release_failed']];}
        }
        return $result+['created_tables'=>$created,'created_menus'=>$createdMenus];
    }
    public static function menuRows():array
    {
        $common=['pid'=>0,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>FinanceBillStore::MARKER];
        return [$common+['menu_name'=>'查看测试账单对账','methods'=>'GET','unique_auth'=>'marketplace-finance-bills-read','api_url'=>'marketplace/finance-bills'],
            $common+['menu_name'=>'导入测试账单','methods'=>'POST','unique_auth'=>'marketplace-finance-bills-import','api_url'=>'marketplace/finance-bills'],
            $common+['menu_name'=>'重新核对测试账单','methods'=>'POST','unique_auth'=>'marketplace-finance-bills-reconcile','api_url'=>'marketplace/finance-bills/reconcile'],
            $common+['menu_name'=>'查看测试结算试算单','methods'=>'GET','unique_auth'=>'marketplace-finance-statements-read','api_url'=>'marketplace/finance-statements'],
            $common+['menu_name'=>'生成测试结算试算单','methods'=>'POST','unique_auth'=>'marketplace-finance-statements-create','api_url'=>'marketplace/finance-statements']];
    }
    private static function sameMenu(array $actual,array $expected):bool
    {
        if(count($actual)!==22||(int)$actual['id']<1||(int)$actual['id']>65535)return false;
        foreach($expected as $key=>$value)if(!isset($actual[$key])||(string)$actual[$key]!==(string)$value)return false;
        return true;
    }
    private function createSql(string $name,array $definition):string
    {
        $parts=[];
        foreach($definition['columns'] as $column=>$spec){
            $charset=is_string($spec[1]??null)?$spec[1]:null;
            $parts[]='`'.$column.'` '.$spec[0].($charset?' CHARACTER SET '.$charset.' COLLATE '.$charset.'_bin':'')
                .' NOT NULL'.(($spec[1]??null)===true?' AUTO_INCREMENT':'');
        }
        foreach($definition['indexes'] as $key=>[$unique,$columns])$parts[]=($key==='PRIMARY'?'PRIMARY KEY':($unique?'UNIQUE KEY ':'KEY ').'`'.$key.'`')
            .' (`'.implode('`,`',$columns).'`)';
        return 'CREATE TABLE `'.$this->prefix.$name.'` ('.implode(',',$parts).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT=\''.FinanceBillStore::MARKER.'\'';
    }
    private function matches(string $name,array $definition,array $meta):bool
    {
        if($meta!==[['TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_bin','TABLE_COMMENT'=>FinanceBillStore::MARKER]])return false;
        $args=[$this->database,$this->prefix.$name];
        $columns=$this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',$args);
        if(count($columns)!==count($definition['columns']))return false;
        foreach($columns as $index=>$actual){
            $column=array_keys($definition['columns'])[$index];$spec=$definition['columns'][$column];$charset=is_string($spec[1]??null)?$spec[1]:null;
            $actual['COLUMN_TYPE']=preg_replace('/\b(tinyint|int|bigint)\([0-9]+\)/','$1',strtolower($actual['COLUMN_TYPE']));
            if($actual!==['COLUMN_NAME'=>$column,'COLUMN_TYPE'=>$spec[0],'IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>null,
                'CHARACTER_SET_NAME'=>$charset,'COLLATION_NAME'=>$charset?$charset.'_bin':null,
                'EXTRA'=>($spec[1]??null)===true?'auto_increment':'','GENERATION_EXPRESSION'=>'','COLUMN_COMMENT'=>''])return false;
        }
        $actualIndexes=[];
        foreach($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',$args) as $index){
            if($index['SUB_PART']!==null||$index['INDEX_TYPE']!=='BTREE'||$index['IS_VISIBLE']!=='YES'||$index['EXPRESSION']!==null||$index['COLLATION']!=='A')return false;
            $key=$index['INDEX_NAME'];$actualIndexes[$key]??=[(string)$index['NON_UNIQUE']==='0',[]];
            if((int)$index['SEQ_IN_INDEX']!==count($actualIndexes[$key][1])+1)return false;$actualIndexes[$key][1][]=$index['COLUMN_NAME'];
        }
        $expected=$definition['indexes'];ksort($actualIndexes);ksort($expected);
        return $actualIndexes===$expected
            &&(string)$this->rows('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?',$args)[0]['n']==='0'
            &&(string)$this->rows("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE IN ('FOREIGN KEY','CHECK')",$args)[0]['n']==='0';
    }
    private function rows(string $sql,array $args=[]):array
    {$s=$this->pdo->prepare($sql);try{if(!$s||!$s->execute($args))throw new RuntimeException('Query unavailable');return $s->columnCount()?$s->fetchAll(PDO::FETCH_ASSOC):[];}finally{if($s)$s->closeCursor();}}
}

function marketplaceFinanceBillsMain(array $arguments):int
{
    $mode='check';$file=null;$selected=false;
    try{
        foreach($arguments as $argument){
            if(in_array($argument,['check','apply'],true)&&!$selected){$mode=$argument;$selected=true;}
            elseif(str_starts_with($argument,'--config-file=')&&$file===null&&strlen($argument)>14)$file=substr($argument,14);
            else throw new RuntimeException('Configuration unavailable');
        }
        if($file===null)throw new RuntimeException('Explicit private configuration required');
        $config=\Audit\Migration\marketplaceMs1Configuration($file);
        $pdo=new PDO('mysql:host='.$config['hostname'].';port='.$config['hostport'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
        $migration=new MarketplaceFinanceBillsMigration($pdo,$config['prefix']);$result=$mode==='apply'?$migration->apply():$migration->check();
        echo json_encode(['mode'=>$mode]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        return in_array($result['state'],['completed','applicable'],true)?0:1;
    }catch(Throwable $error){echo json_encode(['mode'=>$mode,'state'=>'failed','issues'=>['configuration_or_connection_failed']],JSON_THROW_ON_ERROR)."\n";return 1;}
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(marketplaceFinanceBillsMain(array_slice($argv,1)));
