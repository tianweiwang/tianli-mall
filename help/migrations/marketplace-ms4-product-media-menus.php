<?php
declare(strict_types=1);

require_once __DIR__.'/marketplace-ms1.php';

/** Ten finite leaf grants and additive per-role FILE-grant copies; no application bootstrap or DDL. */
final class MarketplaceMs4ProductMediaMenus
{
    private const MARKER='marketplace-ms4-product-media-menus-v1';
    private string $database;
    private string $menus;
    private string $roles;
    private array $lockScope=[];

    public function __construct(private PDO $pdo,string $prefix)
    {
        if(!$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)
            ||!$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC)
            ||!$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||!in_array($pdo->getAttribute(PDO::ATTR_PERSISTENT),[false,0],true))throw new RuntimeException('Configuration unavailable');
        $database=$this->scalar('SELECT DATABASE() AS value');
        if(!is_string($database)||preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$database)!==1
            ||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)throw new RuntimeException('Configuration unavailable');
        $this->database=$database;$this->menus=self::identifier($prefix.'system_menus');$this->roles=self::identifier($prefix.'system_role');
    }

    private function reset(): void
    {
        $this->lockScope=['request_attempted'=>false,'execute_attempted'=>false,'preexisting_same_session'=>null,
            'acquisition'=>'NOT_ATTEMPTED','request_cursor_closed'=>null,'confirmation_failed'=>false,
            'release_attempted'=>false,'release_execute_attempted'=>false,'release_result'=>'NOT_ATTEMPTED',
            'release_cursor_closed'=>null,'ownership'=>'NOT_CHECKED','resolved'=>null];
    }

    public function check(): array
    {
        $this->reset();
        if($this->pdo->inTransaction())return $this->receipt('check',null,['active_transaction']);
        return $this->receipt('check',$this->inspect(false),[]);
    }

    public function apply(): array
    {
        $this->reset();$session=null;$failure=null;$phase='precheck';$releaseFailed=false;
        $attempted=false;$commitAttempted=false;$committed=false;$rollback=null;$created=[];$changedRoles=[];$view=null;
        $name='ms4-product-media:'.substr(hash('sha256',$this->database."\0".$this->menus."\0".self::MARKER),0,44);
        try{
            if($this->pdo->inTransaction())return $this->receipt('apply',null,['active_transaction']);
            $view=$this->inspect(false);
            if($view['issues']!==[])return $this->receipt('apply',$view,[]);
            $phase='named_lock';[$current,$owner]=$this->lockOwnership($name);
            $this->lockScope['preexisting_same_session']=$owner===$current;
            $this->lockScope['ownership']=$owner===$current?'CALLER_OWNED':'NOT_OWNED';$this->lockScope['resolved']=true;
            if($owner===$current)throw new RuntimeException('Migration lock already held by caller');
            $session=$current;$this->requestLock($name);
            $phase='begin_transaction';if(!$this->pdo->beginTransaction())throw new RuntimeException('Transaction unavailable');
            $phase='locked_recheck';$before=$this->inspect(true);$view=$before;
            if($before['issues']!==[])throw new RuntimeException('Locked inspection unavailable');
            $expectedMenus=$before['menus'];$expectedRoles=$before['roles'];
            $phase='insert_missing_leaves';
            foreach(self::menuRows()as$leaf)if(!isset($before['menu_ids'][$leaf['unique_auth']])){
                $columns=array_keys($leaf);$statement=$this->pdo->prepare('INSERT INTO '.self::quote($this->menus)
                    .' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
                if(!$statement)throw new RuntimeException('Insert unavailable');
                try{
                    $attempted=true;
                    if($statement->execute(array_values($leaf))!==true||$statement->rowCount()!==1)throw new RuntimeException('Insert unavailable');
                    $id=self::integer($this->pdo->lastInsertId(),1,65535);$created[$leaf['unique_auth']]=$id;
                }finally{if($statement->closeCursor()!==true)throw new RuntimeException('Insert cursor unavailable');}
            }
            $phase='leaf_readback';$withMenus=$this->inspect(true);
            if($withMenus['issues']!==[]||count($withMenus['menu_ids'])!==count(self::menuRows()))throw new RuntimeException('Leaf readback unavailable');
            foreach(self::menuRows()as$leaf){
                $id=$withMenus['menu_ids'][$leaf['unique_auth']];
                if(isset($created[$leaf['unique_auth']])){
                    if($id!==$created[$leaf['unique_auth']])throw new RuntimeException('Native identity unavailable');
                    $actual=$withMenus['menus'][(string)$id]??null;
                    if(!is_array($actual)||!$this->sameMenu($actual,$leaf))throw new RuntimeException('Leaf row unavailable');
                    $expectedMenus[(string)$id]=$actual;
                }elseif(($before['menu_ids'][$leaf['unique_auth']]??null)!==$id)throw new RuntimeException('Leaf identity changed');
            }
            ksort($expectedMenus,SORT_NUMERIC);
            if($withMenus['menus']!==$expectedMenus||$withMenus['roles']!==$expectedRoles)throw new RuntimeException('Unexpected table change');
            $phase='copy_role_grants';
            foreach($expectedRoles as$key=>$row){
                $wanted=$this->copiedRules($row['rules'],$withMenus['old_file_ids'],$withMenus['menu_ids']);
                if($wanted===$row['rules'])continue;
                $statement=$this->pdo->prepare('UPDATE '.self::quote($this->roles).' SET rules=? WHERE id=? AND BINARY rules <=> BINARY ?');
                if(!$statement)throw new RuntimeException('Role update unavailable');
                try{
                    $attempted=true;
                    if($statement->execute([$wanted,$row['id'],$row['rules']])!==true||$statement->rowCount()!==1)throw new RuntimeException('Role update unavailable');
                }finally{if($statement->closeCursor()!==true)throw new RuntimeException('Role cursor unavailable');}
                $expectedRoles[$key]['rules']=$wanted;$changedRoles[]=self::integer($row['id'],1,4294967295);
            }
            $phase='postcheck';$after=$this->inspect(true);$view=$after;
            if($after['issues']!==[]||$after['state']!=='completed'||$after['menus']!==$expectedMenus
                ||$after['roles']!==$expectedRoles||$after['menu_ids']!==$withMenus['menu_ids'])throw new RuntimeException('Postcheck unavailable');
            $phase='commit';$commitAttempted=true;
            if(!$this->pdo->commit()||$this->pdo->inTransaction())throw new RuntimeException('Commit unavailable');$committed=true;
        }catch(Throwable $error){
            $failure=$phase;
            try{if($this->pdo->inTransaction())$rollback=$this->pdo->rollBack();}catch(Throwable $ignored){$rollback=false;}
        }finally{
            if($session!==null&&$this->lockScope['execute_attempted']&&!in_array($this->lockScope['acquisition'],['ZERO','NULL'],true)){
                $releaseFailed=$this->settleLock($name,$session);if($releaseFailed)$failure??='lock_release';
            }
        }
        $changed=$committed?($created!==[]||$changedRoles!==[]):(!$attempted||$rollback===true?false:null);
        return $this->receipt('apply',$view,$failure===null?[]:[$failure.'_failed'],[
            'changed'=>$changed,'roles_modified'=>$committed?$changedRoles!==[]:(!$attempted||$rollback===true?false:null),
            'write_attempted'=>$attempted,'commit_attempted'=>$commitAttempted,'commit_observed'=>$committed,'rollback_observed'=>$rollback,
            'created_menu_ids'=>$committed?$created:[],'changed_role_ids'=>$committed?$changedRoles:[],
            'lock_release_failed'=>$releaseFailed,'failure_phase'=>$failure]);
    }

    /** Complete, qualified private observation; raw menu/role rows never enter public receipts. */
    private function inspect(bool $lock): array
    {
        if($lock&&!$this->pdo->inTransaction())throw new RuntimeException('Transaction unavailable');
        $authority=$this->metadataAuthority();
        if(!$this->compatible())return ['state'=>'incompatible','issues'=>['menu_or_role_schema'],'menu_ids'=>[],
            'menus'=>[],'roles'=>[],'old_file_ids'=>[]];
        $suffix=$lock?' FOR UPDATE':'';$menus=[];$roles=[];
        foreach($this->rows('SELECT * FROM '.self::quote($this->menus).' ORDER BY id'.$suffix,[])as$row){
            if(count($row)!==22)throw new RuntimeException('Menu row unavailable');$id=self::integer($row['id']??null,1,65535);
            if(isset($menus[(string)$id]))throw new RuntimeException('Menu identity unavailable');$menus[(string)$id]=$row;
        }
        foreach($this->rows('SELECT * FROM '.self::quote($this->roles).' ORDER BY id'.$suffix,[])as$row){
            if(array_keys($row)!==['id','role_name','rules','level','status'])throw new RuntimeException('Role row unavailable');
            // Installed role has five columns: id, role_name, rules, level, status; exact source schema is preserved.
            $id=self::integer($row['id'],1,4294967295);self::integer($row['level'],0,255);self::integer($row['status'],0,255);
            if(!is_string($row['role_name'])||preg_match('//u',$row['role_name'])!==1||str_contains($row['role_name'],"\0")
                ||mb_strlen($row['role_name'],'UTF-8')>32)throw new RuntimeException('Role row unavailable');
            self::ruleIds($row['rules']);if(isset($roles[(string)$id]))throw new RuntimeException('Role identity unavailable');$roles[(string)$id]=$row;
        }
        $ids=[];$issues=[];
        foreach(self::menuRows()as$expected){
            $matches=[];
            foreach($menus as$key=>$row)if($row['unique_auth']===$expected['unique_auth']
                ||((string)$row['auth_type']==='2'&&strtoupper(trim($row['methods']))===$expected['methods']
                    &&self::rule($row['api_url'])===$expected['api_url']))$matches[$key]=$row;
            if(count($matches)>1)$issues[]='permission_or_route_collision';
            elseif($matches!==[]){$row=array_values($matches)[0];
                if(!$this->sameMenu($row,$expected))$issues[]='menu_fields_conflict';else$ids[$expected['unique_auth']]=self::integer($row['id'],1,65535);}
        }
        foreach($menus as$row)if($row['mark']===self::MARKER&&!in_array($row['unique_auth'],array_column(self::menuRows(),'unique_auth'),true))$issues[]='marker_conflict';
        $old=array_fill_keys(array_keys(self::oldFileGrants()),[]);
        foreach($menus as$row)if((string)$row['auth_type']==='2'&&(string)$row['is_del']==='0'){
            $method=strtoupper(trim($row['methods']));$rule=self::rule($row['api_url']);$id=self::integer($row['id'],1,65535);
            foreach(self::oldFileGrants()as$action=>[$wantedMethod,$wantedRule])
                if($method===$wantedMethod&&$rule===$wantedRule)$old[$action][]=$id;
        }
        foreach($old as$values)if($values===[])$issues[]='old_file_grant_missing';
        $pending=count($ids)!==count(self::menuRows());
        if(!$pending)foreach($roles as$row)if($this->copiedRules($row['rules'],$old,$ids)!==$row['rules'])$pending=true;
        if($this->metadataAuthority()!==$authority)throw new RuntimeException('Metadata authority changed');
        return ['state'=>$issues!==[]?'incompatible':($pending?'applicable':'completed'),'issues'=>array_values(array_unique($issues)),
            'menu_ids'=>$ids,'menus'=>$menus,'roles'=>$roles,'old_file_ids'=>$old];
    }

    private function copiedRules(mixed $raw,array $old,array $ids): mixed
    {
        $grants=self::ruleIds($raw);$out=$raw;
        foreach(array_keys(self::oldFileGrants())as$action)
            if(array_intersect($grants,$old[$action])!==[]&&!in_array($ids[$action],$grants,true)){
                if(!is_string($out)||$out==='')throw new RuntimeException('Role rules unavailable');
                $out.=','.($ids[$action]);$grants[]=$ids[$action];
            }
        return $out;
    }
    private static function ruleIds(mixed $raw): array
    {
        if($raw===null||$raw==='')return [];if(!is_string($raw))throw new RuntimeException('Role rules unavailable');$out=[];
        foreach(explode(',',$raw)as$value){if($value===''||$value==='0')continue;$out[]=self::integer($value,1,4294967295);}
        return array_values(array_unique($out));
    }
    private static function rule(string $value): string
    {
        $value=trim(strtolower(str_replace(' ','',$value)));
        return preg_replace('/(?<=\/):([a-z_][a-z0-9_]*)/','<$1>',$value)??'';
    }
    private static function oldFileGrants(): array
    {
        return [
            'legacy-product-media-list'=>['GET','file/file'],
            'legacy-product-media-upload'=>['POST','file/upload/<upload_type?>'],
            'legacy-product-media-categories-list'=>['GET','file/category'],
            'legacy-product-media-categories-create'=>['POST','file/category'],
            'legacy-product-media-categories-update'=>['PUT','file/category/<id>'],
            'legacy-product-media-categories-delete'=>['DELETE','file/category/<id>'],
            'legacy-product-media-rename'=>['PUT','file/file/update/<id>'],
            'legacy-product-media-move'=>['PUT','file/file/do_move'],
            'legacy-product-media-delete'=>['POST','file/file/delete'],
            'legacy-product-media-online-upload'=>['POST','file/online_upload'],
            'legacy-product-media-scan-issue'=>['GET','file/scan_upload/qrcode'],
            'legacy-product-media-scan-poll'=>['GET','file/scan_upload/image/<scan_token>'],
            'legacy-product-media-scan-preview'=>['GET','file/scan_upload/image/<scan_token>'],
            'legacy-product-media-scan-claim'=>['PUT','file/file/do_move'],
            'legacy-product-media-scan-close'=>['DELETE','file/scan_upload/qrcode'],
        ];
    }

    private static function menuRows(): array
    {
        $common=['pid'=>0,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>self::MARKER];
        return array_map(static fn(array $leaf):array=>$common+['menu_name'=>$leaf[0],'methods'=>$leaf[1],
            'api_url'=>$leaf[2],'unique_auth'=>$leaf[3]],[
            ['商品图片列表','GET','product/product/<product_id>/media','legacy-product-media-list'],
            ['商品图片上传','POST','product/product/<product_id>/media','legacy-product-media-upload'],
            ['商品图片分类','GET','product/product/<product_id>/media/categories','legacy-product-media-categories-list'],
            ['商品图片分类新增','POST','product/product/<product_id>/media/categories','legacy-product-media-categories-create'],
            ['商品图片分类修改','PUT','product/product/<product_id>/media/categories/<category_id>','legacy-product-media-categories-update'],
            ['商品图片分类删除','DELETE','product/product/<product_id>/media/categories/<category_id>','legacy-product-media-categories-delete'],
            ['商品图片改名','PUT','product/product/<product_id>/media/<att_id>/name','legacy-product-media-rename'],
            ['商品图片移动','PUT','product/product/<product_id>/media/move','legacy-product-media-move'],
            ['商品图片删除','POST','product/product/<product_id>/media/delete','legacy-product-media-delete'],
            ['商品在线图片上传','POST','product/product/<product_id>/media/online_upload','legacy-product-media-online-upload'],
            ['商品扫码发行','POST','product/product/<product_id>/media/scan','legacy-product-media-scan-issue'],
            ['商品扫码列表','GET','product/product/<product_id>/media/scan/<lease_id>','legacy-product-media-scan-poll'],
            ['商品扫码预览','GET','product/product/<product_id>/media/scan/<lease_id>/items/<stage_id>/content','legacy-product-media-scan-preview'],
            ['商品扫码认领','POST','product/product/<product_id>/media/scan/<lease_id>/claim','legacy-product-media-scan-claim'],
            ['商品扫码关闭','DELETE','product/product/<product_id>/media/scan/<lease_id>','legacy-product-media-scan-close'],
        ]);
    }
    private function sameMenu(array $actual,array $expected): bool
    {
        try{self::integer($actual['id']??null,1,65535);}catch(Throwable $error){return false;}
        if(count($actual)!==22)return false;
        foreach($expected as$key=>$value)if(!array_key_exists($key,$actual)||$actual[$key]===null||(string)$actual[$key]!== (string)$value)return false;
        return true;
    }

    private function compatible(): bool
    {
        $menus = [
            ['id','smallint unsigned',null,true], ['pid','smallint unsigned','0'], ['icon','varchar(16)',''], ['menu_name','varchar(32)',''],
            ['module','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['controller','varchar(64)','',false,'utf8mb3','utf8mb3_bin'],
            ['action','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['api_url','varchar(100)',''], ['methods','varchar(10)',''],
            ['params','varchar(128)','[]',false,'utf8mb3','utf8mb3_bin'], ['sort','tinyint','1'], ['is_show','tinyint unsigned','1'],
            ['is_show_path','tinyint','0'], ['access','tinyint unsigned','1'], ['menu_path','varchar(128)',''], ['path','varchar(255)',''],
            ['auth_type','tinyint','0'], ['header','varchar(50)',''], ['is_header','tinyint','0'], ['unique_auth','varchar(150)',''],
            ['is_del','tinyint','0'], ['mark','varchar(500)',''],
        ];
        $roles=[['id','int unsigned',null,true],['role_name','varchar(32)',''],
            ['rules','text',null,false,'utf8mb4',null,'YES'],['level','tinyint unsigned','0'],['status','tinyint unsigned','1']];
        return $this->compatibleTable($this->menus,$menus,['PRIMARY'=>[true,['id']],'pid'=>[false,['pid']],
                'is_show'=>[false,['is_show']],'access'=>[false,['access']]])
            &&$this->compatibleTable($this->roles,$roles,['PRIMARY'=>[true,['id']],'status'=>[false,['status']]]);
    }

    private function compatibleTable(string $table,array $expected,array $expectedIndexes): bool
    {
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?', [$this->database,$table]);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB') !== 0
            || !is_string($tables[0]['TABLE_COLLATION']) || !str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_')) return false;
        $columns = $this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION', [$this->database,$table]);
        if (count($columns) !== count($expected)) return false;
        foreach ($expected as $index=>$column) {
            $actual = $columns[$index]; $text = preg_match('/\A(?:char|varchar|text)/',$column[1]) === 1;
            $charset = $text ? ($column[4] ?? 'utf8mb4') : null;
            $collation = $text ? ($column[5] ?? $tables[0]['TABLE_COLLATION']) : null;
            $actualCharset = $actual['CHARACTER_SET_NAME'] === 'utf8' ? 'utf8mb3' : $actual['CHARACTER_SET_NAME'];
            $actualCollation = $actual['COLLATION_NAME'] === 'utf8_bin' ? 'utf8mb3_bin' : $actual['COLLATION_NAME'];
            $type = preg_replace('/\b(tinyint|smallint|int)\([0-9]+\)/', '$1', strtolower((string)$actual['COLUMN_TYPE']));
            $default = $actual['COLUMN_DEFAULT'] === null ? null : (string)$actual['COLUMN_DEFAULT'];
            if ($actual['COLUMN_NAME'] !== $column[0] || $type !== $column[1] || $actual['IS_NULLABLE'] !== ($column[6] ?? 'NO')
                || $default !== $column[2] || $actualCharset !== $charset || $actualCollation !== $collation
                || $actual['EXTRA'] !== (($column[3] ?? false) ? 'auto_increment' : '') || $actual['GENERATION_EXPRESSION'] !== '') return false;
        }
        $indexes = $this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database,$table]);
        $actualIndexes = [];
        foreach ($indexes as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
                || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A' || !in_array((string)$row['NON_UNIQUE'], ['0','1'], true)) return false;
            $name = $row['INDEX_NAME']; $unique = (string)$row['NON_UNIQUE'] === '0';
            $actualIndexes[$name] ??= [$unique, []];
            if ($actualIndexes[$name][0] !== $unique || (int)$row['SEQ_IN_INDEX'] !== count($actualIndexes[$name][1]) + 1) return false;
            $actualIndexes[$name][1][] = $row['COLUMN_NAME'];
        }
        ksort($actualIndexes); ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) return false;
        foreach ([['SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->database,$table]],
            ["SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? AND CONSTRAINT_TYPE='CHECK'", [$this->database,$table]],
            ['SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE BINARY EVENT_OBJECT_SCHEMA=BINARY ? AND BINARY EVENT_OBJECT_TABLE=BINARY ?', [$this->database,$table]]] as [$sql,$arguments]) {
            $rows = $this->rows($sql,$arguments);
            if (count($rows) !== 1 || (string)$rows[0]['n'] !== '0') return false;
        }
        return true;
    }

    private function metadataAuthority(): array
    {
        $account=$this->scalar('SELECT CURRENT_USER() AS value');
        if(!is_string($account)||preg_match('/\A([A-Za-z0-9_.$-]{1,32})@([A-Za-z0-9_.:%-]{1,255})\z/D',$account,$match)!==1)
            throw new RuntimeException('Metadata authority unavailable');
        $grantee="'".$match[1]."'@'".$match[2]."'";
        $rows=$this->rows('SELECT GRANTEE,TABLE_CATALOG,TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES'
            .' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY GRANTEE=BINARY ?'
            ." AND PRIVILEGE_TYPE IN ('SELECT','TRIGGER') ORDER BY BINARY PRIVILEGE_TYPE",[$this->database,$grantee]);
        if(count($rows)!==2)throw new RuntimeException('Metadata authority unavailable');
        foreach(['SELECT','TRIGGER']as$offset=>$privilege){$row=$rows[$offset];
            if(array_keys($row)!==['GRANTEE','TABLE_CATALOG','TABLE_SCHEMA','PRIVILEGE_TYPE','IS_GRANTABLE']
                ||$row['GRANTEE']!==$grantee||$row['TABLE_CATALOG']!=='def'||$row['TABLE_SCHEMA']!==$this->database
                ||$row['PRIVILEGE_TYPE']!==$privilege||!in_array($row['IS_GRANTABLE'],['YES','NO'],true))throw new RuntimeException('Metadata authority unavailable');}
        if($this->scalar('SELECT CURRENT_USER() AS value')!==$account)throw new RuntimeException('Metadata authority changed');
        return [$account,$grantee,$rows];
    }
    private function rows(string $sql,array $parameters): array
    {
        $statement=$this->pdo->prepare($sql);if(!$statement)throw new RuntimeException('Inspection unavailable');
        try{if($statement->execute($parameters)!==true)throw new RuntimeException('Inspection unavailable');$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Inspection unavailable');return $rows;
        }finally{if($statement->closeCursor()!==true)throw new RuntimeException('Inspection cursor unavailable');}
    }
    private function scalar(string $sql): mixed
    {
        $rows=$this->rows($sql,[]);if(count($rows)!==1||array_keys($rows[0])!==['value'])throw new RuntimeException('Inspection unavailable');return $rows[0]['value'];
    }
    private function receipt(string $mode,?array $view,array $issues,array $extra=[]): array
    {
        $issues=array_values(array_unique(array_merge($view['issues']??[],$issues)));
        return $extra+['mode'=>$mode,'state'=>$issues!==[]?'incompatible':($view['state']??'incompatible'),'changed'=>false,
            'tables'=>[],'menu_ids'=>$view['menu_ids']??[],'issues'=>array_map(static fn(string $kind):array=>['kind'=>$kind,'path'=>'product_media_menus'],$issues),
            'source_binding'=>['entry_sha256'=>hash_file('sha256',__FILE__),'ms1_sha256'=>hash_file('sha256',__DIR__.'/marketplace-ms1.php')],
            'authorization_cache_producer'=>'fresh_scope_for_request_exact_all_auth_and_current_roles','roles_modified'=>false,'lock_scope'=>$this->lockScope];
    }
    private static function integer(mixed $value,int $min,int $max): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=10
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)throw new RuntimeException('Native identity unavailable');return $value;
    }
    private static function identifier(string $name): string
    {
        if(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name)!==1)throw new RuntimeException('Configuration unavailable');return $name;
    }
    private static function quote(string $name): string { return '`'.self::identifier($name).'`'; }

    /** Session identifiers remain private strings; no observer connection or numeric coercion. */
    private function lockOwnership(string $name): array
    {
        $statement = $this->pdo->prepare('SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,CAST(IS_USED_LOCK(?) AS CHAR) AS owner_id');
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Product media lock ownership unavailable');
        try {
            if ($statement->execute([$name]) !== true) throw new RuntimeException('Product media lock ownership unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'owner_id']
                || !is_string($rows[0]['session_id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['session_id']) !== 1
                || ($rows[0]['owner_id'] !== null && (!is_string($rows[0]['owner_id'])
                    || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['owner_id']) !== 1))) {
                throw new RuntimeException('Product media lock ownership unavailable');
            }
            return [$rows[0]['session_id'], $rows[0]['owner_id']];
        } finally {
            if ($statement->closeCursor() !== true) throw new RuntimeException('Product media ownership cursor unavailable');
        }
    }

    /** Mark possible acquisition before execute, independently of fetch and cursor closure. */
    private function requestLock(string $name): void
    {
        $this->lockScope['request_attempted'] = true;
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?,10) AS lock_result');
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Product media lock request unavailable');
        try {
            $this->lockScope['execute_attempted'] = true;
            $this->lockScope['acquisition'] = 'UNKNOWN';
            $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
            if ($statement->execute([$name]) !== true) throw new RuntimeException('Product media lock request unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['lock_result']) throw new RuntimeException('Product media lock result unavailable');
            $value = $rows[0]['lock_result'];
            if ($value === 1 || $value === '1') $result = 'ONE';
            elseif ($value === 0 || $value === '0') $result = 'ZERO';
            elseif ($value === null) $result = 'NULL';
            else throw new RuntimeException('Product media lock result unavailable');
            $this->lockScope['acquisition'] = $result;
            $this->lockScope['ownership'] = $result === 'ONE' ? 'OWNED' : 'NOT_OWNED';
            $this->lockScope['resolved'] = $result !== 'ONE';
            if ($result !== 'ONE') throw new RuntimeException('Product media migration lock unavailable');
        } finally {
            $this->lockScope['request_cursor_closed'] = false;
            if ($statement->closeCursor() !== true) throw new RuntimeException('Product media request cursor unavailable');
            $this->lockScope['request_cursor_closed'] = true;
        }
    }

    /** At most one release query. An inherited same-session lock never reaches this method. */
    private function settleLock(string $name, string $originalSession): bool
    {
        try {
            [$session, $owner] = $this->lockOwnership($name);
            if ($session !== $originalSession) {
                $this->lockScope['ownership'] = 'SESSION_CHANGED'; $this->lockScope['resolved'] = null;
                return true;
            }
            $this->lockScope['ownership'] = $owner === $session ? 'OWNED' : 'NOT_OWNED';
            $this->lockScope['resolved'] = $owner !== $session;
            if ($owner !== $session) return false;
        } catch (Throwable $error) {
            $this->lockScope['confirmation_failed'] = true;
            $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
        }
        $failed = false; $statement = null;
        $this->lockScope['release_attempted'] = true;
        try {
            // Even when the ownership read failed, never release a replacement session's existing lock.
            $statement = $this->pdo->prepare('SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,'
                . 'CASE WHEN BINARY CAST(CONNECTION_ID() AS CHAR)=BINARY ? THEN RELEASE_LOCK(?) ELSE NULL END AS lock_result');
            if (!$statement instanceof PDOStatement) throw new RuntimeException('Product media lock release unavailable');
            $this->lockScope['release_execute_attempted'] = true;
            $this->lockScope['release_result'] = 'UNKNOWN';
            if ($statement->execute([$originalSession, $name]) !== true) throw new RuntimeException('Product media lock release unavailable');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'lock_result']
                || !is_string($rows[0]['session_id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['session_id']) !== 1) {
                throw new RuntimeException('Product media release result unavailable');
            }
            if ($rows[0]['session_id'] !== $originalSession) {
                $this->lockScope['release_result'] = 'SESSION_CHANGED';
                $this->lockScope['ownership'] = 'SESSION_CHANGED'; $this->lockScope['resolved'] = null;
                throw new RuntimeException('Product media lock session changed');
            }
            $value = $rows[0]['lock_result'];
            if ($value === 1 || $value === '1') $result = 'ONE';
            elseif ($value === 0 || $value === '0') $result = 'ZERO';
            elseif ($value === null) $result = 'NULL';
            else throw new RuntimeException('Product media release result unavailable');
            $this->lockScope['release_result'] = $result;
            $this->lockScope['ownership'] = 'NOT_OWNED'; $this->lockScope['resolved'] = true;
            if ($result !== 'ONE') throw new RuntimeException('Product media lock release not acknowledged');
        } catch (Throwable $error) {
            $failed = true;
        } finally {
            if ($statement instanceof PDOStatement) {
                $this->lockScope['release_cursor_closed'] = false;
                try {
                    if ($statement->closeCursor() !== true) throw new RuntimeException('Product media release cursor unavailable');
                    $this->lockScope['release_cursor_closed'] = true;
                } catch (Throwable $error) { $failed = true; }
            }
        }
        if ($this->lockScope['resolved'] !== true) {
            try {
                [$session, $owner] = $this->lockOwnership($name);
                $this->lockScope['ownership'] = $session !== $originalSession ? 'SESSION_CHANGED' : ($owner === $session ? 'OWNED' : 'NOT_OWNED');
                $this->lockScope['resolved'] = $session === $originalSession ? $owner !== $session : null;
            } catch (Throwable $error) {
                $this->lockScope['confirmation_failed'] = true;
                $this->lockScope['ownership'] = 'UNKNOWN'; $this->lockScope['resolved'] = null;
            }
        }
        return $failed || $this->lockScope['resolved'] !== true;
    }
}

function marketplaceMs4ProductMediaMenusMain(array $arguments): int
{
    $mode='check';$file=null;$selected=false;
    try{
        foreach($arguments as$argument){
            if(in_array($argument,['--help','-h'],true)){echo "Usage: php marketplace-ms4-product-media-menus.php [check|apply] --config-file=<private-config>\n";return 0;}
            if(in_array($argument,['check','apply','--check','--apply'],true)&&!$selected){$mode=ltrim($argument,'-');$selected=true;}
            elseif(str_starts_with($argument,'--config-file=')&&$file===null&&strlen($argument)>14)$file=substr($argument,14);
            else throw new RuntimeException('Configuration unavailable');
        }
        $config=\Audit\Migration\marketplaceMs1Configuration($file);
        // The caller must finish/stabilize grants before this new nonpersistent connection selects its database.
        $pdo=new PDO('mysql:host='.$config['hostname'].';port='.$config['hostport'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_PERSISTENT=>false,PDO::ATTR_TIMEOUT=>3]);
        $migration=new MarketplaceMs4ProductMediaMenus($pdo,$config['prefix']);$receipt=$mode==='apply'?$migration->apply():$migration->check();
        echo json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        return in_array($receipt['state'],['completed','applicable'],true)?0:1;
    }catch(Throwable $error){
        echo json_encode(['mode'=>$mode,'state'=>'incompatible','changed'=>null,'tables'=>[],'menu_ids'=>[],
            'issues'=>[['kind'=>'configuration_or_inspection_failed','path'=>'migration']]],JSON_THROW_ON_ERROR)."\n";return 1;
    }
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(marketplaceMs4ProductMediaMenusMain(array_slice($argv,1)));
