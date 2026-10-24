<?php
declare(strict_types=1);
namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\model\system\attachment\SystemAttachment;
use app\model\system\attachment\SystemAttachmentCategory;
use app\services\marketplace\attachment\MerchantAttachmentInput as Input;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use PDO;
use think\db\Query;
use think\facade\Db;

final class MerchantAttachmentDao
{
    public const FIELDS = ['att_id','mer_id','name','real_name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','type'];
    public const MEDIA_FIELDS=\app\dao\marketplace\product\PlatformProductMediaDao::ATTACHMENT_FIELDS;
    private const VIDEO_MIMES=['video/mp4','application/mp4'];
    private const AUDIO_MIMES=['audio/mpeg','audio/mp3','audio/x-mp3','audio/wav','audio/wave','audio/x-wav','audio/vnd.wave',
        'audio/amr','audio/amr-wb','audio/x-ms-wma','audio/x-ms-asf','video/x-ms-asf','application/vnd.ms-asf'];
    public const MAX_CATEGORIES = 10000;
    public const MAX_DEPTH = 64;
    private static function tx(): PDO
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new AuthUnavailableException();
        return $pdo;
    }
    private function attachments(MerchantContext $ctx, array $ids = []): Query
    {
        $q = (new SystemAttachment())->db()->where('module_type', 1)->where('type', 0);
        if ($ids) $q->whereIn('att_id', $ids);
        return MerchantScope::apply($q, $ctx);
    }
    private function categoryQuery(MerchantContext $ctx): Query
    {
        return MerchantScope::apply((new SystemAttachmentCategory())->db()->where('type', 0), $ctx);
    }
    public function category(MerchantContext $ctx, int $id, bool $lock = false): array
    {
        if ($lock) self::tx();
        $q = (new SystemAttachmentCategory())->db()->where('id', $id)->where('type', 0);
        $row = MerchantScope::apply($q, $ctx)->field(['id','mer_id','pid','name','type'])->lock($lock)->find();
        if ($row === null) throw new MerchantNotFoundException();
        $row = $row->getData();
        MerchantScope::assertOwned($row, $ctx);
        return $row;
    }
    public function categories(MerchantContext $ctx, bool $lock = false): array
    {
        if ($lock) self::tx();
        $rows = $this->categoryQuery($ctx)->field(['id','mer_id','pid','name','type'])->order('id')->limit(self::MAX_CATEGORIES+1)->lock($lock)->select()->toArray();
        if (count($rows) > self::MAX_CATEGORIES) throw new AuthUnavailableException();
        foreach ($rows as $row) MerchantScope::assertOwned($row, $ctx);
        return $rows;
    }
    /** One tree contract for reads and the complete proposed write state, including descendants. */
    public static function assertCategoryTree(array $rows, ?array $pending = null): void
    {
        $map=[];
        foreach ($rows as $row) {
            $id=Input::id($row['id']);$pid=Input::id($row['pid'],true);
            if (isset($map[$id])) throw new AuthUnavailableException();
            $map[$id]=$pid;
        }
        if ($pending!==null) {
            $id=Input::id($pending['id'],true);$pid=Input::id($pending['pid'],true);
            if (($id && !isset($map[$id])) || ($pid && !isset($map[$pid]))) throw new MerchantNotFoundException();
            // -1 is an internal not-yet-inserted node only; never an owner, persisted ID or request parent.
            $map[$id?:-1]=$pid;
        }
        $invalid=static function()use($pending):never {
            if ($pending!==null) Input::invalid();
            throw new AuthUnavailableException();
        };
        if (count($map)>self::MAX_CATEGORIES) $invalid();
        foreach ($map as $id=>$pid) {
            $seen=[];
            for ($node=$id;$node!==0;$node=$map[$node]) {
                if (!isset($map[$node]) || isset($seen[$node]) || count($seen)>=self::MAX_DEPTH) $invalid();
                $seen[$node]=true;
            }
        }
    }
    public function listing(MerchantContext $ctx, int $categoryId, int $page, int $limit): array
    {
        if ($categoryId) $this->category($ctx, $categoryId);
        $q = (new SystemAttachment())->db()->where('module_type', 1)->where('type', 0);
        // Keep the original image/local-MP4 page, excluding only new typed audio.
        $q->whereRaw('(`att_type` IS NULL OR BINARY `att_type` NOT IN ('.implode(',',array_fill(0,count(self::AUDIO_MIMES),'?')).'))',self::AUDIO_MIMES);
        if ($categoryId) $q->where('pid', $categoryId);
        $q = MerchantScope::apply($q, $ctx);
        $count = (clone $q)->count();
        $rows = (clone $q)->field(self::FIELDS)->order('att_id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($rows as $row) MerchantScope::assertOwned($row, $ctx);
        return ['list'=>$rows,'count'=>$count];
    }
    public function ownedBatch(MerchantContext $ctx, array $ids): array
    {
        self::tx();
        $rows = $this->attachments($ctx, $ids)->field(self::FIELDS)->order('att_id')->lock(true)->select()->toArray();
        if (count($rows) !== count($ids) || array_map('intval', array_column($rows, 'att_id')) !== $ids) throw new MerchantNotFoundException();
        foreach ($rows as $row) MerchantScope::assertOwned($row, $ctx);
        return $rows;
    }
    public function add(MerchantContext $ctx, array $data): array
    {
        self::tx();
        $data['mer_id']=$ctx->merId;
        $id = (new SystemAttachment())->db()->insertGetId($data);
        $id = Input::id($id);
        return $this->ownedBatch($ctx, [$id])[0];
    }
    public function remove(MerchantContext $ctx, array $ids): void
    {
        self::tx();
        if ($this->attachments($ctx, $ids)->delete() !== count($ids)) throw new AuthUnavailableException();
    }
    public function saveCategory(MerchantContext $ctx, int $id, string $name, int $pid): array
    {
        self::tx();
        $all = $this->categories($ctx, true);
        $map = [];
        foreach ($all as $row) $map[(int)$row['id']] = $row;
        if ($id && !isset($map[$id])) throw new MerchantNotFoundException();
        if ($pid && !isset($map[$pid])) throw new MerchantNotFoundException();
        self::assertCategoryTree($all,['id'=>$id,'pid'=>$pid]);
        $sameName = (new SystemAttachmentCategory())->db()->where('name', $name)->where('type', 0);
        if ($id) $sameName->where('id', '<>', $id);
        if (MerchantScope::apply($sameName, $ctx)->lock(true)->find() !== null) Input::invalid();
        $changed = $id === 0 || $map[$id]['name'] !== $name || (int)$map[$id]['pid'] !== $pid;
        if (!$id) {
            $id = Input::id((new SystemAttachmentCategory())->db()->insertGetId(['mer_id'=>$ctx->merId,'name'=>$name,'pid'=>$pid,'type'=>0,'enname'=>'']));
        } elseif ($changed) {
            $q = (new SystemAttachmentCategory())->db()->where('id', $id)->where('type', 0);
            if (MerchantScope::apply($q, $ctx)->update(['name'=>$name,'pid'=>$pid]) !== 1) throw new AuthUnavailableException();
        }
        return ['row'=>$this->category($ctx, $id, true),'changed'=>$changed];
    }
    /** No row values leave this method. Probe actual persistent string references, including snapshots. */
    public function assertUnreferenced(array $ids, array $aliases): void
    {
        $pdo = self::tx();
        $prefix = Db::connect()->getConfig('prefix');
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || !$aliases || count($aliases) > 500) Input::unavailable();
        $stmt = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) > 300) Input::unavailable();
        $checked = 0;
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            if (!str_starts_with($name, $prefix)) continue;
            if ($table['TABLE_TYPE'] !== 'BASE TABLE') Input::unavailable();
            self::identifier($name);
            $stmt = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $stmt->execute([$name]);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $terms = []; $bindings = [];
            foreach ($columns as $column) {
                if (!in_array($column['DATA_TYPE'], ['char','varchar','tinytext','text','mediumtext','longtext','json','tinyblob','blob','mediumblob','longblob','binary','varbinary'], true)) continue;
                self::identifier($column['COLUMN_NAME']);
                foreach ($aliases as $alias) {
                    $terms[] = 'LOCATE(?,CAST(`'.$column['COLUMN_NAME'].'` AS CHAR))>0';
                    $bindings[] = $alias;
                }
            }
            if (!$terms) continue;
            if (count($terms) > 20000) Input::unavailable();
            $sql = 'SELECT 1 FROM `'.$name.'` WHERE ('.implode(' OR ', $terms).')';
            if ($name === $prefix.'system_attachment') {
                $sql .= ' AND `att_id` NOT IN ('.implode(',', array_fill(0, count($ids), '?')).')';
                $bindings = array_merge($bindings, $ids);
            }
            $stmt = $pdo->prepare($sql.' LIMIT 1');
            if (!$stmt->execute($bindings)) Input::unavailable();
            if ($stmt->fetchColumn() !== false) throw new \think\exception\HttpException(422, '素材仍被引用，未删除');
            ++$checked;
        }
        if ($checked === 0) Input::unavailable();
    }
    private static function identifier(string $value): void
    {
        if (strlen($value) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/D', $value) !== 1) Input::unavailable();
    }

    /** The real merchant product writer, never the legacy/platform writer. */
    private function mediaContext(MerchantContext $ctx,int $productIdOrZero): array
    {
        Input::id($productIdOrZero,true);$connection=Db::connect();$pdo=self::tx();
        if($connection->getPdo()!==$pdo||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))Input::unavailable();
        if($productIdOrZero>0){
            $product=app()->make(\app\dao\marketplace\product\MerchantProductDao::class)->find($ctx,$productIdOrZero,true);
            if((int)$product['is_del']!==0)Input::invalid();
        }
        if(Db::connect()!==$connection||$connection->getPdo()!==$pdo||!$pdo->inTransaction())Input::unavailable();
        return [$connection,$pdo,$ctx->merId];
    }

    private function mediaTable(): string
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'system_attachment')>64)Input::unavailable();
        return '`'.$prefix.'system_attachment`';
    }

    private function mediaCategory(MerchantContext $ctx,int $id,?int $nativeType,bool $lock): array
    {
        Input::id($id);if($nativeType!==null)\app\services\marketplace\MerchantManagementInput::integer($nativeType,0,1);
        $connection=Db::connect();$q=(new SystemAttachmentCategory())->db()->where('id',$id);
        if($nativeType===null)$q->whereIn('type',[0,1]);else $q->where('type',$nativeType);
        if($q->getConnection()!==$connection||($lock&&$q->getConnection()->getPdo()!==self::tx()))Input::unavailable();
        $model=MerchantScope::apply($q,$ctx)->lock($lock)->find();
        if($model===null)throw new MerchantNotFoundException();$row=$model->getData();MerchantScope::assertOwned($row,$ctx);
        if(array_keys($row)!==['id','pid','name','enname','type','mer_id']
            ||Input::id($row['id'])!==$id||\app\services\marketplace\MerchantManagementInput::integer($row['mer_id'],1,4294967295,true)!==$ctx->merId
            ||($nativeType!==null&&\app\services\marketplace\MerchantManagementInput::integer($row['type'],0,1,true)!==$nativeType))Input::unavailable();
        \app\services\marketplace\MerchantManagementInput::integer($row['type'],0,1,true);
        Input::id($row['pid'],true);
        foreach(['name','enname']as$field)if(!is_string($row[$field])||str_contains($row[$field],"\0")
            ||preg_match('//u',$row[$field])!==1||mb_strlen($row[$field],'UTF-8')>50)Input::unavailable();
        return $row;
    }

    public function categoryForMedia(MerchantContext $ctx,int $productIdOrZero,int $categoryId,int $nativeType): array
    {
        $state=$this->mediaContext($ctx,$productIdOrZero);Input::id($categoryId,true);
        if($categoryId===0)return [];
        $row=$this->mediaCategory($ctx,$categoryId,$nativeType,true);
        if($this->mediaContext($ctx,$productIdOrZero)!==$state)Input::unavailable();return $row;
    }

    private static function assertTypedMedia(array $row,MerchantContext $ctx,string $kind,bool $native,bool $legacyLocalVideo=false): void
    {
        $mimes=match($kind){'image'=>\app\dao\marketplace\product\PlatformProductMediaDao::IMAGE_MIMES,
            'video'=>self::VIDEO_MIMES,'audio'=>self::AUDIO_MIMES,default=>[]};
        if($mimes===[])Input::invalid();
        if($native){if(array_keys($row)!==self::MEDIA_FIELDS)Input::unavailable();Input::id($row['att_id']);}
        $owner=\app\services\marketplace\MerchantManagementInput::integer($row['mer_id'],1,4294967295,true);
        $type=\app\services\marketplace\MerchantManagementInput::integer($row['type'],0,1,true);
        $storage=\app\services\marketplace\MerchantManagementInput::integer($row['image_type'],1,7,true);
        $legacy=$legacyLocalVideo&&$kind==='video'&&$storage===1&&$type===0&&($row['att_type']??null)==='video/mp4';
        if($owner!==$ctx->merId||\app\services\marketplace\MerchantManagementInput::integer($row['module_type'],0,255,true)!==1
            ||($type!==($kind==='video'?1:0)&&!$legacy))Input::unavailable();
        Input::id($row['pid'],true);\app\services\marketplace\MerchantManagementInput::integer($row['time'],1,2147483647,true);
        foreach(['name'=>100,'att_dir'=>200,'satt_dir'=>200,'att_size'=>30,'att_type'=>30,'real_name'=>255,'scan_token'=>32]as$field=>$limit){
            if(!is_string($row[$field])||str_contains($row[$field],"\0")||preg_match('//u',$row[$field])!==1
                ||mb_strlen($row[$field],'UTF-8')>$limit)Input::unavailable();
        }
        \app\services\marketplace\MerchantManagementInput::integer($row['att_size'],1,PHP_INT_MAX,true);
        if($row['name']===''||$row['real_name']===''||$row['att_dir']===''||$row['satt_dir']===''
            ||$row['scan_token']!==''||!in_array($row['att_type'],$mimes,true))Input::unavailable();
    }

    /** INSERT thirteen actual columns; reselect the complete fourteen-column row. */
    public function addTyped(MerchantContext $ctx,int $productIdOrZero,array $data,string $kind): array
    {
        $state=$this->mediaContext($ctx,$productIdOrZero);[$connection,$pdo,$owner]=$state;$data['mer_id']=$owner;
        $fields=array_slice(self::MEDIA_FIELDS,1);$keys=array_keys($data);sort($keys);$expected=$fields;sort($expected);
        if($keys!==$expected)Input::unavailable();self::assertTypedMedia($data,$ctx,$kind,false);
        if($data['pid'])$this->categoryForMedia($ctx,$productIdOrZero,Input::id($data['pid']),$kind==='video'?1:0);
        $values=[];foreach($fields as$field)$values[]=$data[$field];
        if($this->mediaContext($ctx,$productIdOrZero)!==$state)Input::unavailable();
        $table=$this->mediaTable();$statement=$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        if($statement===false)Input::unavailable();
        try{
            if($statement->execute($values)!==true||$statement->rowCount()!==1)Input::unavailable();$id=Input::id($pdo->lastInsertId());
        }finally{if($statement->closeCursor()!==true)Input::unavailable();}
        if($this->mediaContext($ctx,$productIdOrZero)!==$state)Input::unavailable();
        $statement=$pdo->prepare('SELECT * FROM '.$table.' WHERE att_id=? AND mer_id=? AND module_type=1 AND type=? LIMIT 2 FOR UPDATE');
        if($statement===false)Input::unavailable();
        try{
            if($statement->execute([$id,$owner,$kind==='video'?1:0])!==true)Input::unavailable();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        }finally{if($statement->closeCursor()!==true)Input::unavailable();}
        if(!is_array($rows)||count($rows)!==1)Input::unavailable();$row=$rows[0];self::assertTypedMedia($row,$ctx,$kind,true);
        if(Input::id($row['att_id'])!==$id)Input::unavailable();
        foreach($data as$field=>$value)if(is_int($value)?(string)$row[$field]!== (string)$value:$row[$field]!==$value)Input::unavailable();
        if($this->mediaContext($ctx,$productIdOrZero)!==$state)Input::unavailable();return $row;
    }

    /** Metadata selection; actual use still goes through complete typed bytes qualification. */
    public function listingMedia(MerchantContext $ctx,string $kind,int $categoryId,int $page,int $limit): array
    {
        if(!in_array($kind,['video','audio'],true))Input::invalid();
        $categoryType=null;
        if($categoryId){$category=$this->mediaCategory($ctx,$categoryId,$kind==='video'?null:0,false);$categoryType=(int)$category['type'];}
        $q=MerchantScope::apply((new SystemAttachment())->db()->where('module_type',1),$ctx);
        if($kind==='video'){
            $q->whereRaw('((`type`=? AND BINARY `att_type` IN (?,?)) OR (`type`=? AND `image_type`=? AND BINARY `att_type`=?))',
                [1,'video/mp4','application/mp4',0,1,'video/mp4']);
        }else{
            $q->where('type',0)->whereRaw('BINARY `att_type` IN ('.implode(',',array_fill(0,count(self::AUDIO_MIMES),'?')).')',self::AUDIO_MIMES);
        }
        // The old local type0 MP4 retains its real type0 category. New video
        // remains type1 with type1 categories; category0 can show both forms.
        if($categoryType!==null)$q->where('type',$categoryType);
        if($categoryId)$q->where('pid',$categoryId);
        $count=(clone $q)->count();$rows=(clone $q)->field(self::MEDIA_FIELDS)->order('att_id','desc')->page($page,$limit)->select()->toArray();
        foreach($rows as$row){MerchantScope::assertOwned($row,$ctx);self::assertTypedMedia($row,$ctx,$kind,true,true);}
        return ['list'=>$rows,'count'=>$count];
    }
}
