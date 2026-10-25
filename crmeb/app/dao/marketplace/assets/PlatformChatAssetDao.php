<?php
declare(strict_types=1);
namespace app\dao\marketplace\assets;

use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Only real platform-chat rows on the original primary writer, never a platform/admin scope. */
final class PlatformChatAssetDao
{
    public const FIELDS=['id','purpose','category_id','state','storage_type','object_key','thumbnail_key','name','real_name',
        'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
        'created_by_admin_id','created_by_service_id','created_by_uid','created_by_job','chat_customer_uid',
        'chat_is_tourist','chat_service_id','chat_service_uid','channel_qrcode_id','routine_version','legacy_att_id'];
    public const LOG=['id','mer_id','msn','uid','to_uid','is_tourist','time_node','add_time','type','remind','msn_type'];
    public const RECORD=['id','user_id','to_uid','nickname','avatar','is_tourist','online','type','add_time','update_time','mssage_num','message','message_type'];
    public const AUX=['id','binding_id','relation_id','type','other','update_time','add_time'];

    public function primary():array
    {
        $connection=Db::connect();if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $connection->query('SELECT 1 AS platform_chat_primary',[],true);$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO)self::fail();$this->native($connection,$pdo,0);return [$connection,$pdo];
    }
    public function native(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        if(!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||!in_array($depth,[0,1],true)||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    public function structure(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        $this->native($connection,$pdo,$depth);$database=$connection->getConfig('database');$prefix=$connection->getConfig('prefix');
        if(!is_string($database)||$database===''||!is_string($prefix))self::fail();$this->table($connection,'marketplace_platform_asset');
        $args=[$database,$prefix.'marketplace_platform_asset'];
        $table=$this->rows($connection,$pdo,'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES'
            .' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?',$args,$depth);
        if($table!==[['TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_bin','TABLE_COMMENT'=>'']])self::fail();
        $columns=$this->rows($connection,$pdo,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,'
            .'EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS'
            .' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION',$args,$depth);
        $definition=self::columns();if(count($columns)!==29||array_column($columns,'COLUMN_NAME')!==self::FIELDS)self::fail();
        foreach($columns as$i=>$row){
            if(array_keys($row)!==['COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','CHARACTER_SET_NAME','COLLATION_NAME','EXTRA','GENERATION_EXPRESSION','COLUMN_COMMENT','ORDINAL_POSITION'])self::fail();
            $type=$row['COLUMN_TYPE'];if(is_string($type)&&preg_match('/\A(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)( unsigned)?\z/D',$type,$m)===1)$type=$m[1].($m[2]??'');
            if([$type,$row['IS_NULLABLE'],$row['COLUMN_DEFAULT'],$row['CHARACTER_SET_NAME'],$row['COLLATION_NAME'],$row['EXTRA']]!==$definition[$row['COLUMN_NAME']]
                ||$row['GENERATION_EXPRESSION']!==''||$row['COLUMN_COMMENT']!==''||self::integer($row['ORDINAL_POSITION'],1,29)!==$i+1)self::fail();
        }
        $indexes=$this->rows($connection,$pdo,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT'
            .' FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX',$args,$depth);
        $wanted=['PRIMARY'=>[true,['id']],'uq_storage_object'=>[true,['storage_type','object_key']],'uq_legacy_att'=>[true,['legacy_att_id']],
            'ix_category'=>[false,['category_id','id']],'ix_purpose'=>[false,['purpose','state','id']],
            'ix_chat'=>[false,['chat_customer_uid','chat_service_uid','chat_is_tourist','id']]];
        ksort($wanted,SORT_STRING);$expected=[];foreach($wanted as$name=>[$unique,$fields])foreach($fields as$i=>$field)$expected[]=[$name,$unique?0:1,$i+1,$field];
        if(count($indexes)!==count($expected))self::fail();foreach($indexes as$i=>$row){
            if(array_keys($row)!==['INDEX_NAME','NON_UNIQUE','SEQ_IN_INDEX','COLUMN_NAME','SUB_PART','INDEX_TYPE','IS_VISIBLE','EXPRESSION','COLLATION','INDEX_COMMENT']
                ||[$row['INDEX_NAME'],self::integer($row['NON_UNIQUE'],0,1),self::integer($row['SEQ_IN_INDEX'],1,4),$row['COLUMN_NAME']]!==$expected[$i]
                ||$row['SUB_PART']!==null||$row['INDEX_TYPE']!=='BTREE'||$row['IS_VISIBLE']!=='YES'||$row['EXPRESSION']!==null||$row['COLLATION']!=='A'||$row['INDEX_COMMENT']!=='')self::fail();
        }
        $this->native($connection,$pdo,$depth);
    }
    public function session(ConnectionInterface $connection,PDO $pdo,int $depth):array
    {
        $rows=$this->rows($connection,$pdo,'SELECT DATABASE() AS db,CURRENT_USER() AS account,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit',[],$depth);
        if(count($rows)!==1||array_keys($rows[0])!==['db','account','connection_id','autocommit']
            ||$rows[0]['db']!==$connection->getConfig('database')||!is_string($rows[0]['account'])||$rows[0]['account']===''
            ||self::integer($rows[0]['autocommit'],0,1)!==1)self::fail();self::integer($rows[0]['connection_id'],1,PHP_INT_MAX);return $rows[0];
    }
    /** Fixed table/column allowlist, with full native rows and no caller supplied SQL. */
    public function find(ConnectionInterface $connection,PDO $pdo,string $table,array $where,int $depth):array
    {
        $allowed=['user'=>['uid'],'store_service'=>['id','uid'],'store_service_log'=>['id'],
            'store_service_record'=>['user_id','to_uid','is_tourist'],'auxiliary'=>['binding_id','relation_id','type']];
        if(!isset($allowed[$table])||$where===[]||array_diff(array_keys($where),$allowed[$table])!==[])self::fail();
        $terms=[];foreach($where as$key=>$value){self::integer($value,-2147483647,4294967295);$terms[]='`'.$key.'`=?';}
        $rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,$table).' WHERE '.implode(' AND ',$terms)
            .' LIMIT 2'.($depth===1?' FOR UPDATE':''),array_values($where),$depth);
        foreach($rows as$row){$fields=match($table){'store_service_log'=>self::LOG,'store_service_record'=>self::RECORD,'auxiliary'=>self::AUX,default=>null};
            if($fields!==null&&array_keys($row)!==$fields)self::fail();}return $rows;
    }
    public function one(ConnectionInterface $connection,PDO $pdo,string $table,array $where,int $depth):array
    { $rows=$this->find($connection,$pdo,$table,$where,$depth);if(count($rows)!==1)self::fail();return $rows[0]; }
    public function asset(ConnectionInterface $connection,PDO $pdo,string $id,int $depth):array
    {
        self::assetId($id);$rows=$this->rows($connection,$pdo,'SELECT * FROM '.$this->table($connection,'marketplace_platform_asset')
            .' WHERE id=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[$id],$depth);
        if(count($rows)!==1)self::fail();$this->shape($pdo,$rows[0]);return $rows[0];
    }
    public function insertStaged(ConnectionInterface $connection,PDO $pdo,array $room,array $creator,array $physical,string $name):array
    {
        $this->structure($connection,$pdo,1);self::room($room);self::creator($creator);
        if(array_keys($physical)!==['room_key','object_key','media_type','byte_size','width','height','content_sha256']
            ||$physical['room_key']!==self::roomKey($room)||$name===''||str_contains($name,"\0")||preg_match('//u',$name)!==1||mb_strlen($name,'UTF-8')>255)self::fail();
        $now=time();self::integer($now,1,4294963695);$data=array_fill_keys(array_slice(self::FIELDS,1),null);
        $data=array_replace($data,['purpose'=>'platform_chat_image','category_id'=>0,'state'=>'STAGED','storage_type'=>1,
            'object_key'=>$physical['object_key'],'thumbnail_key'=>'','name'=>basename($physical['object_key']),'real_name'=>$name,
            'media_type'=>$physical['media_type'],'byte_size'=>$physical['byte_size'],'width'=>$physical['width'],'height'=>$physical['height'],
            'content_sha256'=>$physical['content_sha256'],'created_at'=>$now,'expires_at'=>$now+3600,
            'created_by_service_id'=>$creator['service_id'],'created_by_uid'=>$creator['uid'],'chat_customer_uid'=>$room['customer_uid'],
            'chat_is_tourist'=>$room['is_tourist'],'chat_service_id'=>$room['service_id'],'chat_service_uid'=>$room['service_uid']]);
        foreach(['category_id','storage_type','byte_size','width','height','created_at','expires_at','created_by_service_id','created_by_uid',
            'chat_customer_uid','chat_is_tourist','chat_service_id','chat_service_uid']as$key)if($data[$key]!==null)$data[$key]=$this->number($pdo,$data[$key]);
        $this->execute($connection,$pdo,'INSERT INTO '.$this->table($connection,'marketplace_platform_asset').' (`'.implode('`,`',array_keys($data))
            .'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
        $id=self::assetId($pdo->lastInsertId());$expected=['id'=>$this->number($pdo,$id)]+$data;
        if($this->asset($connection,$pdo,$id,1)!==$expected)self::fail();return $expected;
    }
    public function bind(ConnectionInterface $connection,PDO $pdo,array $selected,array $room,array $log):array
    {
        self::room($room);$id=self::assetId((string)$selected['id']);$logId=self::integer($log['id']??null,1,2147483647);
        if($this->asset($connection,$pdo,$id,1)!==$selected||$this->one($connection,$pdo,'store_service_log',['id'=>$logId],1)!==$log)self::fail();
        $this->message($selected,$room,$log);$expected=$selected;
        if($selected['state']==='STAGED'){
            if(self::integer($selected['expires_at'],1,4294967295)<=time())self::fail();$expected['state']='BOUND';$expected['bound_at']=$this->number($pdo,time());
            $this->execute($connection,$pdo,'UPDATE '.$this->table($connection,'marketplace_platform_asset')
                .' SET state=?,bound_at=? WHERE id=? AND purpose=? AND state=? AND bound_at IS NULL AND retired_at IS NULL',
                ['BOUND',$expected['bound_at'],$id,'platform_chat_image','STAGED']);
        }
        if($this->asset($connection,$pdo,$id,1)!==$expected)self::fail();return $expected;
    }
    /** Real original ORM model/PK and independent original defaults, before any asset binding. */
    public function savedLog(ConnectionInterface $c,PDO $p,\app\model\service\StoreServiceLog $model,array $input):array
    {
        $this->native($c,$p,1);if($model->db()->getConnection()!==$c)self::fail();
        $id=self::integer($model->getData('id'),1,2147483647);$expected=['id'=>$this->number($p,$id),'mer_id'=>$this->number($p,0),
            'msn'=>null,'uid'=>null,'to_uid'=>null,'is_tourist'=>$this->number($p,0),'time_node'=>$this->number($p,0),
            'add_time'=>null,'type'=>$this->number($p,0),'remind'=>$this->number($p,0),'msn_type'=>$this->number($p,1)];
        if(array_diff(array_keys($input),array_slice(self::LOG,1))!==[])self::fail();
        foreach($input as$key=>$value)$expected[$key]=$key==='msn'?$value:$this->number($p,$value);
        if(!is_string($expected['msn'])||$expected['uid']===null||$expected['to_uid']===null||$expected['add_time']===null)self::fail();
        $row=$this->one($c,$p,'store_service_log',['id'=>$id],1);if($row!==$expected)self::fail();return $row;
    }
    public function message(array $asset,array $room,array $log):void
    {
        if(array_keys($log)!==self::LOG||$this->assetRoom($asset)!==$room||$log['msn']!==self::reference((string)$asset['id'])
            ||self::integer($log['msn_type'],1,6)!==3||self::integer($log['is_tourist'],0,1)!==$room['is_tourist'])self::fail();
        $from=self::integer($log['uid'],-2147483647,4294967295);$to=self::integer($log['to_uid'],-2147483647,4294967295);
        if(!in_array([$from,$to],[[$room['customer_uid'],$room['service_uid']],[$room['service_uid'],$room['customer_uid']]],true))self::fail();
        self::integer($log['type'],0,1);self::integer($log['id'],1,2147483647);
    }
    public function assetRoom(array $row):array
    { return ['customer_uid'=>self::integer($row['chat_customer_uid'],-2147483647,4294967295),'is_tourist'=>self::integer($row['chat_is_tourist'],0,1),
        'service_id'=>self::integer($row['chat_service_id'],1,4294967295),'service_uid'=>self::integer($row['chat_service_uid'],1,4294967295)]; }
    public static function room(array $room):void
    {
        if(array_keys($room)!==['customer_uid','is_tourist','service_id','service_uid'])self::fail();
        self::integer($room['customer_uid'],-2147483647,4294967295);self::integer($room['is_tourist'],0,1);
        self::integer($room['service_id'],1,4294967295);self::integer($room['service_uid'],1,4294967295);
        if($room['customer_uid']===0||$room['customer_uid']===$room['service_uid']||($room['is_tourist']===0&&$room['customer_uid']<1))self::fail();
    }
    public static function roomKey(array $room):string {self::room($room);return hash('sha256',json_encode($room,JSON_THROW_ON_ERROR));}
    public static function isPrivate(mixed $value):bool {return is_string($value)&&str_starts_with($value,'platform-chat:');}
    public static function reference(string $id):string {return 'platform-chat:1:'.self::assetId($id);}
    public static function referenceId(string $ref):string
    {if(!str_starts_with($ref,'platform-chat:1:'))self::fail();return self::assetId(substr($ref,16));}
    public static function assetId(mixed $value):string
    {
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0))self::fail();return $value;
    }
    public static function integer(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$value)===1
            &&strlen(ltrim($value,'-'))<=strlen((string)PHP_INT_MAX)){
            $n=filter_var($value,FILTER_VALIDATE_INT);if($n!==false)$value=$n;}
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function creator(array $creator):void
    {
        if(array_keys($creator)!==['uid','service_id']||($creator['uid']===null)===($creator['service_id']===null))self::fail();
        foreach($creator as$value)if($value!==null)self::integer($value,1,4294967295);
    }
    private function shape(PDO $pdo,array $row):void
    {
        if(array_keys($row)!==self::FIELDS||$row['purpose']!=='platform_chat_image'||$row['category_id']!==$this->number($pdo,0)
            ||$row['storage_type']!==$this->number($pdo,1)||!in_array($row['state'],['STAGED','BOUND'],true)||$row['thumbnail_key']!=='')self::fail();
        self::assetId((string)$row['id']);$room=$this->assetRoom($row);self::room($room);
        self::creator(['uid'=>$row['created_by_uid'],'service_id'=>$row['created_by_service_id']]);
        foreach(['created_by_admin_id','created_by_job','retired_at','channel_qrcode_id','routine_version','legacy_att_id']as$key)if($row[$key]!==null)self::fail();
        if(!is_string($row['object_key'])||preg_match('/\Aplatform-chat\/v1\/([a-f0-9]{64})\/[a-f0-9]{64}\/content\.(jpg|jpeg|png|gif|webp)\z/D',$row['object_key'],$m)!==1
            ||$m[1]!==self::roomKey($room)||$row['name']!==basename($row['object_key'])||!is_string($row['real_name'])||$row['real_name']===''
            ||str_contains($row['real_name'],"\0")||preg_match('//u',$row['real_name'])!==1||mb_strlen($row['real_name'],'UTF-8')>255
            ||!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32
            ||!in_array($row['media_type'],['image/jpeg','image/jpg','image/png','image/gif','image/webp'],true))self::fail();
        foreach(['byte_size'=>52428800,'width'=>4294967295,'height'=>4294967295,'created_at'=>4294967295,'expires_at'=>4294967295]as$key=>$max)self::integer($row[$key],1,$max);
        if($row['state']==='STAGED'){if($row['bound_at']!==null)self::fail();}else self::integer($row['bound_at'],1,4294967295);
        foreach(['id','category_id','storage_type','byte_size','width','height','created_at','expires_at','bound_at','created_by_service_id',
            'created_by_uid','chat_customer_uid','chat_is_tourist','chat_service_id','chat_service_uid']as$key)
            if($row[$key]!==null&&$this->number($pdo,$row[$key])!==$row[$key])self::fail();
    }
    private function number(PDO $pdo,mixed $n):int|string
    {
        $text=(string)$n;if(preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$text)!==1)self::fail();$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();$int=filter_var($text,FILTER_VALIDATE_INT);
        return in_array($strings,[true,1],true)||$int===false?$text:$int;
    }
    private function rows(ConnectionInterface $c,PDO $p,string $sql,array $values,int $depth):array
    {
        $this->native($c,$p,$depth);$s=$p->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($s->closeCursor()!==true)self::fail();}$this->native($c,$p,$depth);return $rows;
    }
    private function execute(ConnectionInterface $c,PDO $p,string $sql,array $values):void
    {
        $this->native($c,$p,1);$s=$p->prepare($sql);if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute($values)!==true||$s->rowCount()!==1)self::fail();}finally{if($s->closeCursor()!==true)self::fail();}$this->native($c,$p,1);
    }
    private function table(ConnectionInterface $c,string $suffix):string
    {
        $prefix=$c->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.$suffix)>64
            ||!in_array($suffix,['marketplace_platform_asset','user','store_service','store_service_log','store_service_record','auxiliary'],true))self::fail();return '`'.$prefix.$suffix.'`';
    }
    /** Literal existing AssetSchema platform29; runtime DML visibility, not migrator grant inspection. */
    private static function columns():array
    {
        return [
            'id'=>['bigint unsigned','NO',null,null,null,'auto_increment'],
            'purpose'=>["enum('site_image','site_file','channel_qr','routine_download_qr','platform_chat_image')",'NO',null,'ascii','ascii_bin',''],
            'category_id'=>['int','NO','0',null,null,''],
            'state'=>["enum('STAGED','BOUND','RETIRED')",'NO','STAGED','ascii','ascii_bin',''],
            'storage_type'=>['tinyint unsigned','NO',null,null,null,''],
            'object_key'=>['varchar(512)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'thumbnail_key'=>['varchar(512)','NO','','utf8mb4','utf8mb4_bin',''],
            'name'=>['varchar(100)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'real_name'=>['varchar(255)','NO',null,'utf8mb4','utf8mb4_bin',''],
            'media_type'=>['varchar(127)','NO',null,'ascii','ascii_bin',''],
            'byte_size'=>['bigint unsigned','NO',null,null,null,''],
            'width'=>['int unsigned','NO','0',null,null,''],
            'height'=>['int unsigned','NO','0',null,null,''],
            'content_sha256'=>['binary(32)','NO',null,null,null,''],
            'created_at'=>['int unsigned','NO',null,null,null,''],
            'expires_at'=>['int unsigned','YES',null,null,null,''],
            'bound_at'=>['int unsigned','YES',null,null,null,''],
            'retired_at'=>['int unsigned','YES',null,null,null,''],
            'created_by_admin_id'=>['smallint unsigned','YES',null,null,null,''],
            'created_by_service_id'=>['int unsigned','YES',null,null,null,''],
            'created_by_uid'=>['int unsigned','YES',null,null,null,''],
            'created_by_job'=>['varchar(32)','YES',null,'ascii','ascii_bin',''],
            'chat_customer_uid'=>['bigint','YES',null,null,null,''],
            'chat_is_tourist'=>['tinyint unsigned','YES',null,null,null,''],
            'chat_service_id'=>['int unsigned','YES',null,null,null,''],
            'chat_service_uid'=>['int unsigned','YES',null,null,null,''],
            'channel_qrcode_id'=>['int unsigned','YES',null,null,null,''],
            'routine_version'=>['varchar(64)','YES',null,'ascii','ascii_bin',''],
            'legacy_att_id'=>['int','YES',null,null,null,''],
        ];
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
