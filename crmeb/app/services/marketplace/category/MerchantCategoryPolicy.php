<?php
declare(strict_types=1);
namespace app\services\marketplace\category;

use crmeb\exceptions\AuthUnavailableException;

/** Pure platform-category eligibility; callers own authorization and SQL locks. */
final class MerchantCategoryPolicy
{
    public const ROW_MAX=10000;
    private const ID_MAX=2147483647;
    private static function integer(mixed $value,int $min,int $max):?int
    {
        if (is_string($value)&&strlen($value)<=11&&preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',$value)===1) $value=(int)$value;
        return is_int($value)&&$value>=$min&&$value<=$max?$value:null;
    }
    /** The actual parents to lock, without promoting missing or malformed parents. */
    public static function parentIds(array $rows):array
    {
        $ids=[];foreach ($rows as $row) {
            $parent=self::integer($row['pid']??null,1,self::ID_MAX);
            if ($parent!==null) $ids[$parent]=$parent;
        }
        sort($ids,SORT_NUMERIC);return array_values($ids);
    }
    /** Return eligible roots and direct children keyed by their original IDs. */
    public static function eligible(array $rows):array
    {
        if (count($rows)>self::ROW_MAX) throw new AuthUnavailableException();
        $map=[];
        foreach ($rows as $row) {
            $id=self::integer($row['id']??null,1,self::ID_MAX);
            $pid=self::integer($row['pid']??null,0,self::ID_MAX);
            $show=self::integer($row['is_show']??null,0,1);
            $sort=self::integer($row['sort']??null,-2147483648,2147483647);
            $name=$row['cate_name']??null;
            if ($id===null||$pid===null||$show===null||$sort===null||!is_string($name)
                ||preg_match('//u',$name)!==1||str_contains($name,"\0")||mb_strlen($name,'UTF-8')>100) continue;
            if (isset($map[$id])) throw new AuthUnavailableException();
            $map[$id]=['id'=>$id,'pid'=>$pid,'is_show'=>$show,'sort'=>$sort,'cate_name'=>$name];
        }
        $eligible=[];
        foreach ($map as $id=>$row) {
            if ($row['is_show']!==1) continue;
            if ($row['pid']===0||isset($map[$row['pid']])&&$map[$row['pid']]['pid']===0&&$map[$row['pid']]['is_show']===1) $eligible[$id]=$row;
        }
        return $eligible;
    }
    public static function tree(array $rows):array
    {
        $eligible=self::eligible($rows);$rows=array_values($eligible);
        usort($rows,static fn(array $a,array $b):int=>($b['sort']<=>$a['sort'])?:($b['id']<=>$a['id']));
        $children=[];$roots=[];
        foreach ($rows as $row) {
            $node=['id'=>$row['id'],'pid'=>$row['pid'],'cate_name'=>$row['cate_name'],'selectable'=>true,'children'=>[]];
            if ($row['pid']===0) $roots[]=$node;else $children[$row['pid']][]=$node;
        }
        foreach ($roots as &$root) $root['children']=$children[$root['id']]??[];
        unset($root);return ['list'=>$roots,'count'=>count($eligible)];
    }
}
