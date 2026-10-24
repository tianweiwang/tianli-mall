<?php
declare(strict_types=1);
namespace app\dao\marketplace\product;

use app\services\marketplace\product\LegacyProductWriteServices as Writer;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\exception\HttpException;
use think\facade\Db;

/** Full native commercial rows on the actual product writer PDO; no employee context or owner input. */
final class PlatformProductMediaDao
{
    public const ATTACHMENT_FIELDS=['att_id','name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','real_name','scan_token','type','mer_id'];
    private const CATEGORY_FIELDS=['id','pid','name','enname','type','mer_id'];
    public const IMAGE_MIMES=['image/jpg','image/jpeg','image/gif','image/png','image/x-icon','image/vnd.microsoft.icon','image/webp'];

    private function context(int $productIdOrZero): array
    {
        $pdo=Writer::transaction();$owner=Writer::mediaOwner($productIdOrZero);$connection=Db::connect();
        if($connection->getPdo()!==$pdo||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
        return [$pdo,$owner];
    }
    private function table(string $suffix): string
    {
        $prefix=Db::connect()->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||!in_array($suffix,['system_attachment','system_attachment_category','marketplace_customer_asset','marketplace_platform_asset','marketplace_platform_asset_category','marketplace_audit_log'],true)||strlen($prefix.$suffix)>64)self::fail();
        return '`'.$prefix.$suffix.'`';
    }
    private function rows(int $productIdOrZero,PDO $pdo,int $owner,string $sql,array $parameters): array
    {
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();
        $statement=$pdo->prepare($sql);if(!$statement)self::fail();
        try{
            if($statement->execute($parameters)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))self::fail();
        }finally{if($statement->closeCursor()!==true)self::fail();}
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();return $rows;
    }
    public function category(int $productIdOrZero,int $categoryId): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);self::integer($categoryId,1,2147483647);
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category').' WHERE id=? AND mer_id=? AND type=0 LIMIT 2 FOR UPDATE',[$categoryId,$owner]);
        if($rows===[])throw new HttpException(404,'资源不存在');if(count($rows)!==1)self::fail();$row=$rows[0];
        if(array_keys($row)!==self::CATEGORY_FIELDS||self::integer($row['id'],1,2147483647)!==$categoryId
            ||self::integer($row['mer_id'],1,4294967295)!==$owner||self::integer($row['type'],0,1)!==0)self::fail();
        self::integer($row['pid'],0,2147483647);self::text($row['name'],50);self::text($row['enname'],50);return $row;
    }
    public function listing(int $productIdOrZero,int $categoryId,string $name,int $page,int $limit): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);self::integer($categoryId,0,2147483647);
        self::integer($page,1,2147483647);self::integer($limit,1,100);self::text($name,255);
        if($categoryId)$this->category($productIdOrZero,$categoryId);
        $where=' WHERE mer_id=? AND module_type=1 AND type=0 AND BINARY att_type IN ('.implode(',',array_fill(0,count(self::IMAGE_MIMES),'?')).')';$parameters=array_merge([$owner],self::IMAGE_MIMES);
        if($categoryId){$where.=' AND pid=?';$parameters[]=$categoryId;}
        if($name!==''){$where.=' AND real_name LIKE ?';$parameters[]='%'.$name.'%';}
        $counts=$this->rows($productIdOrZero,$pdo,$owner,'SELECT COUNT(*) AS total FROM '.$this->table('system_attachment').$where,$parameters);
        if(count($counts)!==1||array_keys($counts[0])!==['total'])self::fail();$count=self::integer($counts[0]['total'],0,PHP_INT_MAX);
        $offset=($page-1)*$limit;
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').$where.' ORDER BY att_id DESC LIMIT '.$limit.' OFFSET '.$offset,$parameters);
        foreach($rows as$row)$this->attachment($row,$owner);
        return ['list'=>$rows,'count'=>$count];
    }
    public function add(int $productIdOrZero,array $data): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);$data['mer_id']=$owner;
        $fields=array_slice(self::ATTACHMENT_FIELDS,1);$keys=array_keys($data);sort($keys);$expected=$fields;sort($expected);
        if($keys!==$expected)self::fail();
        if($data['pid'])$this->category($productIdOrZero,self::integer($data['pid'],1,2147483647));
        $values=[];foreach($fields as$field)$values[]=$data[$field];
        // Validate all stored fields before a DML attempt without fabricating a persisted identifier.
        $this->attachment($data,$owner,false);
        if($data['scan_token']!==''||self::integer($data['time'],1,2147483647)<1)self::fail();
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();
        $statement=$pdo->prepare('INSERT INTO '.$this->table('system_attachment').' (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        if(!$statement)self::fail();
        try{if($statement->execute($values)!==true||$statement->rowCount()!==1)self::fail();$id=self::integer($pdo->lastInsertId(),1,2147483647);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').' WHERE att_id=? AND mer_id=? AND module_type=1 AND type=0 LIMIT 2 FOR UPDATE',[$id,$owner]);
        if(count($rows)!==1)self::fail();$row=$rows[0];$this->attachment($row,$owner);
        if(self::integer($row['att_id'],1,2147483647)!==$id)self::fail();
        foreach($data as$field=>$value)if((is_int($value)?(string)$row[$field]!== (string)$value:$row[$field]!==$value))self::fail();
        return $row;
    }
    /** The ordinary add method above retains its empty scan_token hard gate in full. */
    public function addScanClaim(int $productIdOrZero,array $data,string $claimId): array
    {
        $this->scanClaimWriter($claimId);
        [$pdo,$owner]=$this->context($productIdOrZero);$data['mer_id']=$owner;
        $fields=array_slice(self::ATTACHMENT_FIELDS,1);$keys=array_keys($data);sort($keys);$expected=$fields;sort($expected);
        if($keys!==$expected)self::fail();
        if($data['pid'])$this->category($productIdOrZero,self::integer($data['pid'],1,2147483647));
        $values=[];foreach($fields as$field)$values[]=$data[$field];
        // Validate all stored fields before a DML attempt without fabricating a persisted identifier.
        $this->attachment($data,$owner,false);
        if($data['scan_token']!==$claimId||self::integer($data['time'],1,2147483647)<1)self::fail();
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();
        $statement=$pdo->prepare('INSERT INTO '.$this->table('system_attachment').' (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        if(!$statement)self::fail();
        try{if($statement->execute($values)!==true||$statement->rowCount()!==1)self::fail();$id=self::integer($pdo->lastInsertId(),1,2147483647);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').' WHERE att_id=? AND mer_id=? AND module_type=1 AND type=0 LIMIT 2 FOR UPDATE',[$id,$owner]);
        if(count($rows)!==1)self::fail();$row=$rows[0];$this->attachment($row,$owner);
        if(self::integer($row['att_id'],1,2147483647)!==$id)self::fail();
        foreach($data as$field=>$value)if((is_int($value)?(string)$row[$field]!== (string)$value:$row[$field]!==$value))self::fail();
        return $row;
    }
    private function scanClaimWriter(string $claimId): void
    {
        self::scanId($claimId);$request=app()->request;
        if(!$request instanceof \app\Request||!\app\services\marketplace\product\LegacyProductAccessServices::isWriter($request)
            ||$request->rule()->getOption('legacy_product_write')!=='legacy-product-media-scan-claim')self::fail();
        $scope=$request->platformScope();
        if($scope!==Writer::rawScope()||$scope->permission!=='legacy-product-media-scan-claim')self::fail();
        app()->make(\app\services\marketplace\product\LegacyProductAccessServices::class)->authorizeScope($scope);
        Writer::transaction();
    }
    private static function scanId(string $id): void
    { if(preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1)self::fail(); }

    /** Read a sealed claim without entering writer.finish (which may append an audit). */
    public function scanClaimState(\app\Request $request,array $issuer,int $productIdOrZero,int $owner,int $initialCategory,string $claimId): array
    {
        self::scanId($claimId);self::integer($owner,1,4294967295);
        $auth=app()->make(\app\services\marketplace\product\ProductMediaScanIssuer::class);
        $auth->forRequest($request,$issuer,'claim',$productIdOrZero,$owner,$initialCategory);
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(Writer::active()||!$pdo instanceof PDO||$pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
        $read=function()use($pdo,$owner,$claimId,$request):array{
            $rows=$this->scanRead($pdo,'SELECT * FROM '.$this->table('system_attachment').' WHERE BINARY scan_token=? AND mer_id=? AND module_type=1 AND type=0 ORDER BY att_id LIMIT 51',[$claimId,$owner]);
            if(count($rows)>50)self::fail();$ids=[];
            foreach($rows as$row){$this->attachment($row,$owner);
                if($row['scan_token']!==$claimId||!in_array($row['att_type'],self::IMAGE_MIMES,true))self::fail();
                $ids[]=(string)self::integer($row['att_id'],1,2147483647);}
            $audits=[];
            if($ids!==[]){
                $actor=$request->platformScope()->adminId;
                $sql='SELECT * FROM '.$this->table('marketplace_audit_log').' WHERE mer_id=? AND BINARY actor_type=? AND actor_id=? AND BINARY action=? AND BINARY resource_type=? AND BINARY resource_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id LIMIT 51';
                $audits=$this->scanRead($pdo,$sql,array_merge([$owner,'admin',$actor,'legacy-product-media-scan-claim','attachment'],$ids));
                if(count($audits)>50)self::fail();$seen=[];
                foreach($audits as$row){
                    if(array_keys($row)!==['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time']
                        ||self::integer($row['mer_id'],1,4294967295)!==$owner||self::integer($row['actor_id'],1,4294967295)!==$actor
                        ||$row['actor_type']!=='admin'||$row['action']!=='legacy-product-media-scan-claim'||$row['resource_type']!=='attachment'
                        ||$row['result']!=='success'||!is_string($row['resource_id'])||!in_array($row['resource_id'],$ids,true)
                        ||isset($seen[$row['resource_id']]))self::fail();
                    self::integer($row['id'],1,4294967295);self::integer($row['add_time'],1,4294967295);
                    if(!is_string($row['detail']))self::fail();
                    try{$detail=json_decode($row['detail'],true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
                    if($detail!==['changed_fields'=>['name','att_dir','satt_dir','pid']])self::fail();
                    $seen[$row['resource_id']]=true;
                }
            }
            return ['rows'=>$rows,'audits'=>$audits];
        };
        $first=$read();$auth->forRequest($request,$issuer,'claim',$productIdOrZero,$owner,$initialCategory);
        if(Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())self::fail();
        $second=$read();$auth->forRequest($request,$issuer,'claim',$productIdOrZero,$owner,$initialCategory);
        if($first!==$second||Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())self::fail();
        return $second;
    }
    private function scanRead(PDO $pdo,string $sql,array $parameters): array
    {
        if(Db::connect()->getPdo()!==$pdo||$pdo->inTransaction())self::fail();
        $statement=$pdo->prepare($sql);if(!$statement)self::fail();
        try{if($statement->execute($parameters)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if(!is_array($rows)||!array_is_list($rows))self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        if(Db::connect()->getPdo()!==$pdo||$pdo->inTransaction())self::fail();return $rows;
    }

    private function attachment(array $row,int $owner,bool $native=true): void
    {
        if($native){if(array_keys($row)!==self::ATTACHMENT_FIELDS)self::fail();self::integer($row['att_id'],1,2147483647);}
        self::integer($row['pid'],0,2147483647);self::integer($row['time'],0,2147483647);
        if(self::integer($row['mer_id'],1,4294967295)!==$owner||self::integer($row['module_type'],0,255)!==1
            ||self::integer($row['type'],0,1)!==0)self::fail();self::integer($row['image_type'],1,7);
        foreach(['name'=>100,'att_dir'=>200,'satt_dir'=>200,'att_size'=>30,'att_type'=>30,'real_name'=>255,'scan_token'=>32]as$field=>$max)self::text($row[$field],$max);
    }
    private static function integer(mixed $value,int $min,int $max): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=19
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function text(mixed $value,int $max): void
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)self::fail();
    }
    private static function fail(): never { throw new AuthUnavailableException(); }

    /** Full image-category tree, captured on the actual writer; no model field projection. */
    public function categories(int $productIdOrZero): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category')
            .' WHERE mer_id=? AND type=0 ORDER BY id LIMIT 10001 FOR UPDATE',[$owner]);
        if(count($rows)>10000)self::fail();foreach($rows as$row)$this->categoryNative($row,$owner);
        \app\dao\marketplace\MerchantAttachmentDao::assertCategoryTree($rows);
        return $rows;
    }
    private function categoryNative(array $row,int $owner): void
    {
        if(array_keys($row)!==self::CATEGORY_FIELDS||self::integer($row['mer_id'],1,4294967295)!==$owner
            ||self::integer($row['type'],0,1)!==0)self::fail();
        self::integer($row['id'],1,2147483647);self::integer($row['pid'],0,2147483647);
        self::text($row['name'],50);self::text($row['enname'],50);
    }
    public function categoryListing(int $productIdOrZero,int $pid,string $name,bool $all): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);$complete=$this->categories($productIdOrZero);
        self::integer($pid,0,2147483647);self::text($name,50);
        if($pid&&$name===''&&!$all)$this->category($productIdOrZero,$pid);
        $where=' WHERE mer_id=? AND type=0';$parameters=[$owner];
        if($name!==''){$where.=' AND name LIKE ?';$parameters[]='%'.$name.'%';}
        elseif(!$all){$where.=' AND pid=?';$parameters[]=$pid;}
        $selected=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category').$where.' ORDER BY id FOR UPDATE',$parameters);
        $map=[];foreach($complete as$row)$map[(string)$row['id']]=$row;
        foreach($selected as$row){$this->categoryNative($row,$owner);if(($map[(string)$row['id']]??null)!==$row)self::fail();}
        return ['rows'=>$selected,'complete'=>$complete];
    }
    /** Do not hide incompatible children by applying an owner/type predicate. */
    private function anyChild(int $productIdOrZero,PDO $pdo,int $owner,int $id): bool
    {
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category').' WHERE pid=? ORDER BY id LIMIT 1 FOR UPDATE',[$id]);
        foreach($rows as$row)if(array_keys($row)!==self::CATEGORY_FIELDS)self::fail();
        return $rows!==[];
    }
    private function mutate(int $productIdOrZero,PDO $pdo,int $owner,string $sql,array $parameters,int $expected): void
    {
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();$statement=$pdo->prepare($sql);if(!$statement)self::fail();
        try{if($statement->execute($parameters)!==true||$statement->rowCount()!==$expected)self::fail();}
        finally{if($statement->closeCursor()!==true)self::fail();}
        if($this->context($productIdOrZero)!==[$pdo,$owner])self::fail();
    }
    private static function changedRow(array $before,array $patch): array
    {
        foreach($patch as$field=>$value){
            if(!array_key_exists($field,$before))self::fail();
            $before[$field]=is_string($before[$field])&&is_int($value)?(string)$value:$value;
        }
        return $before;
    }
    public function saveCategory(int $productIdOrZero,int $id,string $name,int $pid): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);self::integer($id,0,2147483647);self::integer($pid,0,2147483647);self::text($name,50);
        if($name==='')self::fail();$before=$this->categories($productIdOrZero);$map=[];
        foreach($before as$position=>$row)$map[(string)$row['id']]=['position'=>$position,'row'=>$row];
        if($id&&!isset($map[(string)$id]))throw new HttpException(404,'资源不存在');
        if($pid)$this->category($productIdOrZero,$pid);
        if($id&&self::integer($map[(string)$id]['row']['pid'],0,2147483647)!==$pid&&$this->anyChild($productIdOrZero,$pdo,$owner,$id))
            throw new HttpException(422,'该分类有下级分类，无法修改上级');
        \app\dao\marketplace\MerchantAttachmentDao::assertCategoryTree($before,['id'=>$id,'pid'=>$pid]);
        $duplicates=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category')
            .' WHERE mer_id=? AND type=0 AND name=? AND id<>? ORDER BY id LIMIT 1 FOR UPDATE',[$owner,$name,$id]);
        if($duplicates!==[])throw new HttpException(422,'该分类已存在');
        $changed=$id===0||$map[(string)$id]['row']['name']!==$name||self::integer($map[(string)$id]['row']['pid'],0,2147483647)!==$pid;
        if(!$changed)return ['row'=>$map[(string)$id]['row'],'changed'=>false];
        if($id===0){
            $statement=$pdo->prepare('INSERT INTO '.$this->table('system_attachment_category').' (pid,name,enname,type,mer_id) VALUES (?,?,?,0,?)');if(!$statement)self::fail();
            try{if($statement->execute([$pid,$name,'',$owner])!==true||$statement->rowCount()!==1)self::fail();$id=self::integer($pdo->lastInsertId(),1,2147483647);}
            finally{if($statement->closeCursor()!==true)self::fail();}
            $row=$this->category($productIdOrZero,$id);
            if((string)$row['pid']!==(string)$pid||$row['name']!==$name||$row['enname']!=='')self::fail();
            $expected=$before;$expected[]=$row;
            usort($expected,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
        }else{
            $old=$map[(string)$id]['row'];$row=self::changedRow($old,['name'=>$name,'pid'=>$pid]);
            $this->mutate($productIdOrZero,$pdo,$owner,'UPDATE '.$this->table('system_attachment_category').' SET name=?,pid=? WHERE id=? AND mer_id=? AND type=0',[$name,$pid,$id,$owner],1);
            if($this->category($productIdOrZero,$id)!==$row)self::fail();$expected=$before;$expected[$map[(string)$id]['position']]=$row;
        }
        if($this->categories($productIdOrZero)!==$expected)self::fail();return ['row'=>$row,'changed'=>true];
    }
    private function imageAttachment(array $row,int $owner): void
    {
        $this->attachment($row,$owner);
        if(!in_array($row['att_type'],self::IMAGE_MIMES,true))throw new HttpException(422,'素材类型不支持本图片操作');
    }
    /** Select the complete set before any mutation; MIME is checked per row, never used to skip a requested ID. */
    public function ownedImages(int $productIdOrZero,array $ids): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);self::selection($ids);
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment')
            .' WHERE att_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND mer_id=? AND module_type=1 AND type=0 ORDER BY att_id FOR UPDATE',array_merge($ids,[$owner]));
        if(count($rows)!==count($ids))throw new HttpException(404,'资源不存在');$actual=[];
        foreach($rows as$row){$this->imageAttachment($row,$owner);$actual[]=self::integer($row['att_id'],1,2147483647);}
        if($actual!==$ids)self::fail();return $rows;
    }
    private static function selection(array $ids): void
    {
        if(!array_is_list($ids)||$ids===[]||count($ids)>50)self::fail();$previous=0;
        foreach($ids as$id){self::integer($id,1,2147483647);if(!is_int($id)||$id<=$previous)self::fail();$previous=$id;}
    }
    private function patchImage(int $productIdOrZero,array $before,array $patch): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);$this->imageAttachment($before,$owner);
        if(!in_array(array_keys($patch),[['pid'],['real_name']],true))self::fail();$expected=self::changedRow($before,$patch);
        if($expected===$before)return ['row'=>$before,'changed'=>false];$id=self::integer($before['att_id'],1,2147483647);$field=array_key_first($patch);
        if($field==='pid')self::integer($patch[$field],0,2147483647);else self::text($patch[$field],255);
        $this->mutate($productIdOrZero,$pdo,$owner,'UPDATE '.$this->table('system_attachment').' SET `'.$field.'`=? WHERE att_id=? AND mer_id=? AND module_type=1 AND type=0',[$patch[$field],$id,$owner],1);
        if($this->ownedImages($productIdOrZero,[$id])[0]!==$expected)self::fail();return ['row'=>$expected,'changed'=>true];
    }
    public function rename(int $productIdOrZero,int $id,string $name): array
    { return $this->patchImage($productIdOrZero,$this->ownedImages($productIdOrZero,[$id])[0],['real_name'=>$name]); }

    public function move(int $productIdOrZero,array $ids,int $pid): array
    {
        self::integer($pid,0,2147483647);if($pid)$this->category($productIdOrZero,$pid);$rows=$this->ownedImages($productIdOrZero,$ids);$changed=[];$expected=[];
        foreach($rows as$row){$result=$this->patchImage($productIdOrZero,$row,['pid'=>$pid]);$expected[]=$result['row'];if($result['changed'])$changed[]=(int)$row['att_id'];}
        if($this->ownedImages($productIdOrZero,$ids)!==$expected)self::fail();return $changed;
    }
    /** This local existence/column probe is not the complete T3 schema/trigger authority gate. */
    private function mappingColumns(int $productIdOrZero,PDO $pdo,int $owner): void
    {
        foreach(['marketplace_customer_asset'=>'legacy_att_id','marketplace_platform_asset'=>'legacy_att_id','marketplace_platform_asset_category'=>'legacy_category_id']as$table=>$column){
            $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT `'.$column.'` FROM '.$this->table($table).' WHERE 1=0',[]);
            if($rows!==[])self::fail();
        }
    }
    public function assertAttachmentMappingsAbsent(int $productIdOrZero,array $ids): void
    {
        [$pdo,$owner]=$this->context($productIdOrZero);self::selection($ids);$this->mappingColumns($productIdOrZero,$pdo,$owner);
        foreach(['marketplace_customer_asset','marketplace_platform_asset']as$table){
            $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT legacy_att_id FROM '.$this->table($table)
                .' WHERE legacy_att_id IN ('.implode(',',array_fill(0,count($ids),'?')).') LIMIT 1 FOR UPDATE',$ids);
            if($rows!==[])throw new HttpException(422,'素材仍被引用，未删除');
        }
    }
    private function assertCategoryMappingAbsent(int $productIdOrZero,PDO $pdo,int $owner,int $id): void
    {
        $this->mappingColumns($productIdOrZero,$pdo,$owner);
        $rows=$this->rows($productIdOrZero,$pdo,$owner,'SELECT legacy_category_id FROM '.$this->table('marketplace_platform_asset_category').' WHERE legacy_category_id=? LIMIT 1 FOR UPDATE',[$id]);
        if($rows!==[])throw new HttpException(422,'分类仍被引用，未删除');
    }
    public function deleteCategory(int $productIdOrZero,int $id): array
    {
        [$pdo,$owner]=$this->context($productIdOrZero);$before=$this->categories($productIdOrZero);$row=$this->category($productIdOrZero,$id);
        if($this->anyChild($productIdOrZero,$pdo,$owner,$id))throw new HttpException(422,'请先删除子分类');
        $pid=self::integer($row['pid'],0,2147483647);if($pid)$this->category($productIdOrZero,$pid);
        $references=$this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').' WHERE pid=? ORDER BY att_id FOR UPDATE',[$id]);
        foreach($references as$attachment)$this->imageAttachment($attachment,$owner);
        $this->assertCategoryMappingAbsent($productIdOrZero,$pdo,$owner,$id);$moved=[];
        foreach($references as$attachment){$result=$this->patchImage($productIdOrZero,$attachment,['pid'=>$pid]);
            if(!$result['changed'])self::fail();$moved[]=(int)$attachment['att_id'];}
        if($this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').' WHERE pid=? LIMIT 1 FOR UPDATE',[$id])!==[])self::fail();
        $this->mutate($productIdOrZero,$pdo,$owner,'DELETE FROM '.$this->table('system_attachment_category').' WHERE id=? AND mer_id=? AND type=0',[$id,$owner],1);
        if($this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category').' WHERE id=? LIMIT 1',[$id])!==[])self::fail();
        $expected=array_values(array_filter($before,static fn(array $item):bool=>(int)$item['id']!==$id));
        if($this->categories($productIdOrZero)!==$expected)self::fail();return ['category'=>$row,'moved_ids'=>$moved];
    }
    public function removeImages(int $productIdOrZero,array $ids): void
    {
        [$pdo,$owner]=$this->context($productIdOrZero);$this->ownedImages($productIdOrZero,$ids);
        $this->mutate($productIdOrZero,$pdo,$owner,'DELETE FROM '.$this->table('system_attachment')
            .' WHERE att_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND mer_id=? AND module_type=1 AND type=0',array_merge($ids,[$owner]),count($ids));
        if($this->rows($productIdOrZero,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment')
            .' WHERE att_id IN ('.implode(',',array_fill(0,count($ids),'?')).') LIMIT 1',$ids)!==[])self::fail();
    }

    /** Typed producers retain the original complete native category on the actual writer. */
    public function categoryForMedia(int $productId,int $categoryId,int $nativeType): array
    {
        [$pdo,$owner]=$this->context($productId);self::integer($categoryId,1,2147483647);self::integer($nativeType,0,1);
        $rows=$this->rows($productId,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment_category').' WHERE id=? AND mer_id=? AND type=? LIMIT 2 FOR UPDATE',[$categoryId,$owner,$nativeType]);
        if($rows===[])throw new HttpException(404,'资源不存在');if(count($rows)!==1)self::fail();$row=$rows[0];
        if(array_keys($row)!==self::CATEGORY_FIELDS||self::integer($row['id'],1,2147483647)!==$categoryId
            ||self::integer($row['mer_id'],1,4294967295)!==$owner||self::integer($row['type'],0,1)!==$nativeType)self::fail();
        self::integer($row['pid'],0,2147483647);self::text($row['name'],50);self::text($row['enname'],50);return $row;
    }

    /** No image-list/scan fallback: this receives only a completed typed producer's fields. */
    public function addTyped(int $productId,array $data,string $resolvedKind): array
    {
        [$pdo,$owner]=$this->context($productId);$data['mer_id']=$owner;
        $fields=array_slice(self::ATTACHMENT_FIELDS,1);$keys=array_keys($data);sort($keys);$expected=$fields;sort($expected);
        if($keys!==$expected)self::fail();
        $this->typedAttachment($data,$owner,$resolvedKind,false);
        if($data['pid'])$this->categoryForMedia($productId,self::integer($data['pid'],1,2147483647),$resolvedKind==='video'?1:0);
        $values=[];foreach($fields as$field)$values[]=$data[$field];
        if($this->context($productId)!==[$pdo,$owner])self::fail();
        $statement=$pdo->prepare('INSERT INTO '.$this->table('system_attachment').' (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        if(!$statement)self::fail();
        try{if($statement->execute($values)!==true||$statement->rowCount()!==1)self::fail();$id=self::integer($pdo->lastInsertId(),1,2147483647);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        $rows=$this->rows($productId,$pdo,$owner,'SELECT * FROM '.$this->table('system_attachment').' WHERE att_id=? AND mer_id=? AND module_type=1 AND type=? LIMIT 2 FOR UPDATE',[$id,$owner,$resolvedKind==='video'?1:0]);
        if(count($rows)!==1)self::fail();$row=$rows[0];$this->typedAttachment($row,$owner,$resolvedKind);
        if(self::integer($row['att_id'],1,2147483647)!==$id)self::fail();
        foreach($data as$field=>$value)if((is_int($value)?(string)$row[$field]!== (string)$value:$row[$field]!==$value))self::fail();
        return $row;
    }

    /** Metadata classification only. Actual complete content was qualified by Storage before INSERT. */
    private function typedAttachment(array $row,int $owner,string $resolvedKind,bool $native=true): void
    {
        $mimes=match($resolvedKind){
            'image'=>self::IMAGE_MIMES,
            'video'=>['video/mp4','application/mp4'],
            'audio'=>['audio/mpeg','audio/mp3','audio/x-mp3','audio/wav','audio/wave','audio/x-wav','audio/vnd.wave',
                'audio/amr','audio/amr-wb','audio/x-ms-wma','audio/x-ms-asf','video/x-ms-asf','application/vnd.ms-asf'],
            default=>[],
        };
        if($mimes===[])self::fail();
        if($native){if(array_keys($row)!==self::ATTACHMENT_FIELDS)self::fail();self::integer($row['att_id'],1,2147483647);}
        self::integer($row['pid'],0,2147483647);self::integer($row['time'],1,2147483647);
        if(self::integer($row['mer_id'],1,4294967295)!==$owner||self::integer($row['module_type'],0,255)!==1
            ||self::integer($row['type'],0,1)!==($resolvedKind==='video'?1:0))self::fail();self::integer($row['image_type'],1,7);
        foreach(['name'=>100,'att_dir'=>200,'satt_dir'=>200,'att_size'=>30,'att_type'=>30,'real_name'=>255,'scan_token'=>32]as$field=>$max)self::text($row[$field],$max);
        self::integer($row['att_size'],1,PHP_INT_MAX);
        if($row['name']===''||$row['real_name']===''||$row['att_dir']===''||$row['satt_dir']===''||$row['scan_token']!==''||!in_array($row['att_type'],$mimes,true))self::fail();
    }
}
