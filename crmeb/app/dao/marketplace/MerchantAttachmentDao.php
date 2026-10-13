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
}
