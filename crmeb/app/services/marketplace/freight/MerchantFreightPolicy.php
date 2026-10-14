<?php
declare(strict_types=1);
namespace app\services\marketplace\freight;

use app\dao\marketplace\MerchantFreightDao;
use app\model\product\product\StoreProduct;
use think\exception\HttpException;
use think\facade\Config;
use think\facade\Db;

/** Internal current-root/template preflight, not final-order locking or a new pricing algorithm. */
final class MerchantFreightPolicy
{
    public function __construct(private MerchantFreightDao $freight) {}
    public static function enabled():bool
    {
        $enabled=Config::get('marketplace.enabled',false);if (!is_bool($enabled)) throw new MerchantFreightException();return $enabled;
    }
    private static function positive(mixed $id,int $maximum):int
    {
        if (is_string($id)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)===1) $id=(int)$id;
        if (!is_int($id)||$id<1||$id>$maximum) MerchantFreightInput::unavailable();return $id;
    }
    private static function selfId():int
    {
        $id=self::positive(Config::get('marketplace.self_operated_mer_id',null),4294967295);
        $row=Db::name('marketplace_merchant')->where('mer_id',$id)->field('mer_id')->find();
        if ($row===null||(string)$row['mer_id']!==(string)$id) MerchantFreightInput::unavailable();
        // Existence is an integrity check only. Deployment must confirm this exact MS2 identity.
        return $id;
    }
    public function revalidate(array &$cartInfo,int $shippingType=1,int $isGift=0,?int $cityId=null,bool $allowBlocked=false):array
    {
        if (!self::enabled()||$isGift===1||!$cartInfo) return [];
        if ($shippingType===2&&!sys_config('store_self_mention')) $shippingType=1;
        if ($shippingType!==1) return [];
        try {
            if (count($cartInfo)>500) MerchantFreightInput::unavailable();self::selfId();$resolved=[];$cities=null;
            foreach ($cartInfo as $key=>&$item) {
                if (!is_array($item)||!is_array($item['productInfo']??null)) MerchantFreightInput::unavailable();
                $id=self::positive($item['product_id']??null,8388607);
                $model=(new StoreProduct())->db()->where('id',$id)->field(['id','mer_id','freight','temp_id','is_virtual'])->find();
                if ($model===null) MerchantFreightInput::unavailable();$root=$model->getData();$owner=self::positive($root['mer_id'],4294967295);
                // In enabled mode the existing private branch covers every known owner, including confirmed self.
                if (Db::name('marketplace_merchant')->where('mer_id',$owner)->field('mer_id')->find()===null) MerchantFreightInput::unavailable();
                $mode=\app\services\marketplace\MerchantManagementInput::integer($root['freight'],1,3,true);
                $virtual=\app\services\marketplace\MerchantManagementInput::integer($root['is_virtual'],0,1,true);
                foreach (['mer_id','freight','temp_id','is_virtual'] as $field) $item['productInfo'][$field]=$root[$field];
                $entry=['entrant'=>true,'skip_template'=>$mode!==3||$virtual===1,'root'=>$root,'template'=>null,'region'=>null,'free'=>[],'blocked'=>false];
                if (!$entry['skip_template']) {
                    $templateId=MerchantFreightInput::id($root['temp_id']);$data=$this->freight->forProduct($owner,$templateId);
                    $cities??=$this->freight->cityMap();
                    if ($cityId!==null&&$cityId!==0&&(!isset($cities[$cityId])||!$cities[$cityId]['enabled']||$cities[$cityId]['level']!==1)) MerchantFreightInput::unavailable();
                    $defined=MerchantFreightInput::fromRows($data['root'],$data['rules'],$cities);$default=null;$specific=null;
                    foreach ($defined['rules']['region'] as $row) { if ($row['city_id']===0) $default=$row;elseif ($cityId!==null&&$row['city_id']===$cityId) $specific=$row; }
                    $entry['region']=$specific??$default;if ($entry['region']===null) MerchantFreightInput::unavailable();
                    $entry['template']=$defined['root'];$entry['free']=$defined['rules']['free'];
                    foreach ($defined['rules']['no_delivery'] as $row) if ($cityId!==null&&$row['city_id']===$cityId) $entry['blocked']=true;
                    if ($entry['blocked']&&!$allowBlocked) throw new HttpException(422,'商品不支持配送到当前城市');
                }
                $resolved[$key]=$entry;
            }
            unset($item);return $resolved;
        } catch (HttpException $error) { throw new MerchantFreightException($error->getStatusCode()); }
        catch (\Throwable $error) { throw new MerchantFreightException(); }
    }
    public static function free(array $entry,int $cityId,mixed $number,mixed $price):bool
    {
        if (($entry['template']['appoint']??0)!==1) return false;
        try {
            foreach ($entry['free'] as $row) if ($row['city_id']===$cityId&&bccomp((string)$number,$row['number'],2)>=0&&bccomp((string)$price,$row['price'],2)>=0) return true;
            return false;
        } catch (\Throwable $error) { throw new MerchantFreightException(); }
    }
}
