<?php
declare(strict_types=1);
namespace app\services\marketplace\freight;

use app\services\marketplace\MerchantManagementInput as Input;
use think\exception\HttpException;

final class MerchantFreightInput
{
    public const ID_MAX=2147483647;
    public const ROW_MAX=10000;
    public const GROUP_MAX=200;
    public static function invalid():never { throw new HttpException(422,'运费模板参数不正确'); }
    public static function unavailable():never { throw new HttpException(503,'运费模板或城市资料暂无法确认'); }
    public static function id(mixed $value,bool $zero=false):int { return Input::integer($value,$zero?0:1,self::ID_MAX,true); }
    public static function decimal(mixed $value,bool $positive=false,bool $whole=false):string
    {
        if (is_int($value)) $value=(string)$value;
        if (!is_string($value)||preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D',$value,$m)!==1) self::invalid();
        $result=$m[1].'.'.str_pad($m[2]??'',2,'0');
        if (($positive&&$result==='0.00')||($whole&&substr($result,-2)!=='00')) self::invalid();
        return $result;
    }
    public static function body(array $body,array $allowed,array $required=[]):array { return Input::body($body,$allowed,$required); }
    private static function collection(mixed $value):array
    {
        if (!is_array($value)||!array_is_list($value)||count($value)>self::GROUP_MAX) self::invalid();
        return $value;
    }
    private static function places(mixed $value,array $cities,bool $nationwide):array
    {
        if (!is_array($value)||!$value) self::invalid();
        if (!array_is_list($value)) {
            self::body($value,['city_id','name'],['city_id']);
            if (!$nationwide||self::id($value['city_id'],true)!==0) self::invalid();
            return [['province_id'=>0,'city_id'=>0]];
        }
        $pairs=[];
        foreach ($value as $province) {
            if (!is_array($province)) self::invalid();
            self::body($province,['city_id','name','children'],['city_id','children']);
            $pid=self::id($province['city_id']);
            if (!isset($cities[$pid])||$cities[$pid]['level']!==0||!$cities[$pid]['enabled']) self::invalid();
            $children=$province['children'];
            if (!is_array($children)||!array_is_list($children)||!$children||count($children)>self::ROW_MAX) self::invalid();
            foreach ($children as $city) {
                if (!is_array($city)) self::invalid();
                self::body($city,['city_id','name'],['city_id']);$id=self::id($city['city_id']);
                if (!isset($cities[$id])||!$cities[$id]['enabled']||$cities[$id]['level']!==1||$cities[$id]['parent_id']!==$pid||isset($pairs[$id])) self::invalid();
                $pairs[$id]=['province_id'=>$pid,'city_id'=>$id];
            }
        }
        if (!$pairs||count($pairs)>self::ROW_MAX) self::invalid();
        ksort($pairs,SORT_NUMERIC);return array_values($pairs);
    }
    public static function definition(array $body,array $cities):array
    {
        self::body($body,['name','type','appoint','no_delivery','sort','region_info','appoint_info','no_delivery_info'],['name','type','region_info']);
        $type=Input::integer($body['type'],1,3,true);
        $root=['name'=>Input::text($body['name'],255,true,true),'type'=>$type,'appoint'=>Input::integer($body['appoint']??0,0,1,true),
            'no_delivery'=>Input::integer($body['no_delivery']??0,0,1,true),'sort'=>Input::integer($body['sort']??0,-2147483648,2147483647)];
        $rules=['region'=>[],'free'=>[],'no_delivery'=>[]];$default=0;
        foreach (['region'=>'region_info','free'=>'appoint_info','no_delivery'=>'no_delivery_info'] as $kind=>$key) {
            $groups=self::collection($body[$key]??[]);$seen=[];
            foreach ($groups as $groupIndex=>$group) {
                if (!is_array($group)) self::invalid();
                $placeKey=$kind==='region'?'region':'place';
                $allowed=$kind==='region'?['region','first','price','continue','continue_price']:($kind==='free'?['place','a_num','a_price']:['place']);
                self::body($group,$allowed,$allowed);
                $pairs=self::places($group[$placeKey],$cities,$kind==='region');
                $values=$kind==='region'?['first'=>self::decimal($group['first'],true,$type===1),'first_price'=>self::decimal($group['price']),
                    'continue'=>self::decimal($group['continue'],true,$type===1),'continue_price'=>self::decimal($group['continue_price']),'type'=>$type]
                    :($kind==='free'?['number'=>self::decimal($group['a_num'],false,$type===1),'price'=>self::decimal($group['a_price']),'type'=>$type]:[]);
                foreach ($pairs as $pair) {
                    if (isset($seen[$pair['city_id']])) self::invalid();$seen[$pair['city_id']]=true;
                    if ($kind==='region'&&$pair['city_id']===0) ++$default;
                    $rules[$kind][]=$pair+$values+['group'=>$groupIndex];
                    if (count($rules[$kind])>self::ROW_MAX) self::invalid();
                }
            }
        }
        if ($default!==1||($root['appoint']===1)!==(bool)$rules['free']||($root['no_delivery']===1)!==(bool)$rules['no_delivery']) self::invalid();
        return ['root'=>$root,'rules'=>$rules];
    }
    public static function semantic(array $root,array $rules):array
    {
        $project=[];
        foreach (['name','type','appoint','no_delivery','sort'] as $key) $project[$key]=$root[$key];
        foreach (['region','free','no_delivery'] as $kind) {
            $project[$kind]=[];
            foreach ($rules[$kind] as $row) { unset($row['group'],$row['uniqid'],$row['id'],$row['temp_id']);$project[$kind][]=$row; }
            usort($project[$kind],static fn(array $a,array $b):int=>$a['city_id']<=>$b['city_id']);
        }
        return $project;
    }
    /** Rebuild only from actual rows selected under an already verified parent. */
    public static function fromRows(array $root,array $rules,array $cities):array
    {
        try {
            $type=Input::integer($root['type'],1,3,true);$body=['name'=>$root['name'],'type'=>$type,'appoint'=>Input::integer($root['appoint'],0,1,true),
                'no_delivery'=>Input::integer($root['no_delivery'],0,1,true),'sort'=>Input::integer(is_string($root['sort'])?(int)$root['sort']:$root['sort'],-2147483648,2147483647),
                'region_info'=>[],'appoint_info'=>[],'no_delivery_info'=>[]];
            $templateId=self::id($root['id']);
            foreach (['region'=>'region_info','free'=>'appoint_info','no_delivery'=>'no_delivery_info'] as $kind=>$key) {
                if (count($rules[$kind])>self::ROW_MAX) self::unavailable();$groups=[];$seenCities=[];
                if (($kind==='free'&&$body['appoint']===0)||($kind==='no_delivery'&&$body['no_delivery']===0)) continue;
                foreach ($rules[$kind] as $row) {
                    if (self::id($row['temp_id'])!==$templateId||!is_string($row['uniqid'])||$row['uniqid']===''||strlen($row['uniqid'])>32) self::unavailable();
                    $pid=self::id($row['province_id'],true);$city=self::id($row['city_id'],true);self::id($row['id']);
                    if (isset($seenCities[$city])) self::unavailable();$seenCities[$city]=true;
                    $values=$kind==='region'?['first'=>self::decimal($row['first'],true,$type===1),'price'=>self::decimal($row['first_price']),
                        'continue'=>self::decimal($row['continue'],true,$type===1),'continue_price'=>self::decimal($row['continue_price'])]
                        :($kind==='free'?['a_num'=>self::decimal($row['number'],false,$type===1),'a_price'=>self::decimal($row['price'])]:[]);
                    if ($kind!=='no_delivery'&&Input::integer($row['type'],1,3,true)!==$type) self::unavailable();
                    $group=$row['uniqid'];
                    if (isset($groups[$group])&&$groups[$group]['values']!==$values) self::unavailable();
                    $groups[$group]['values']=$values;
                    if ($pid===0||$city===0) {
                        if ($kind!=='region'||$pid!==0||$city!==0||isset($groups[$group]['places'])) self::unavailable();
                        $groups[$group]['nationwide']=true;
                    } else {
                        if (isset($groups[$group]['nationwide'])||!isset($cities[$pid],$cities[$city])||$cities[$pid]['level']!==0||$cities[$city]['level']!==1||$cities[$city]['parent_id']!==$pid) self::unavailable();
                        $groups[$group]['places'][$pid]['city_id']=$pid;$groups[$group]['places'][$pid]['name']=$cities[$pid]['name'];
                        $groups[$group]['places'][$pid]['children'][]=['city_id'=>$city,'name'=>$cities[$city]['name']];
                    }
                }
                foreach ($groups as $group) {
                    $places=isset($group['nationwide'])?['city_id'=>0,'name'=>'默认全国']:array_values($group['places']);
                    $placeKey=$kind==='region'?'region':'place';$body[$key][]=[$placeKey=>$places]+$group['values'];
                }
            }
            $defined=self::definition($body,$cities);
            return $defined+['body'=>$body];
        } catch (\Throwable $error) { self::unavailable(); }
    }
}
