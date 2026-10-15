<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;

/** Checks the actual private quote against rows already locked on the default PDO. */
final class MarketplaceOrderLockedQuote
{
    /**
     * The optional observer preserves the ordinary driver's assertion evidence;
     * it cannot disable this class's own checks. No prices or addresses are logged.
     * @return array{cartInfo:list<array>,address:array}
     */
    public static function verify(int $uid,int $merId,int $addressId,array $locked,array $group,array $computed,?callable $observer=null):array
    {
        self::expect(($locked['uid']??null)===$uid&&($locked['mer_id']??null)===$merId
            &&is_array($locked['selection']??null)&&is_array($group['cartInfo']??null)
            &&count($group['cartInfo'])===count($locked['selection']),'ACTUAL_QUOTE_SELECTION',$observer);
        $byRef=[];
        foreach($group['cartInfo'] as $cart){
            self::expect(is_array($cart),'ACTUAL_QUOTE_CART_ROW',$observer);
            $ref=MarketplaceOrderInput::reference($cart['id']??null,$locked['source_kind']);
            self::expect(!isset($byRef[$ref]),'ACTUAL_QUOTE_CART_UNIQUE',$observer);
            $byRef[$ref]=$cart;
        }
        $cartInfo=[];$goods=$cost=$postage='0.00';
        foreach($locked['selection'] as $line){
            $cart=$byRef[$line['cart_ref']]??null;
            self::expect(is_array($cart),'ACTUAL_QUOTE_CART_REF',$observer);
            $product=$cart['productInfo']??null;$sku=is_array($product)?($product['attrInfo']??null):null;
            $rawSku=$locked['skus'][$line['sku_id']]??null;$rawRoot=$locked['products'][$line['product_id']]??null;
            self::expect(is_array($product)&&is_array($sku)&&is_array($rawSku)&&is_array($rawRoot),'ACTUAL_QUOTE_PRODUCT_SKU',$observer);
            self::expect((int)($cart['cart_num']??0)===$line['qty']&&(int)($product['id']??0)===$line['product_id']
                &&(int)($product['mer_id']??0)===$merId&&(int)($sku['id']??0)===$line['sku_id']&&(int)($sku['type']??-1)===0
                &&($sku['unique']??null)===$line['unique']&&($cart['costPrice']??null)===$rawSku['cost']
                &&($cart['sum_price']??null)===$rawSku['price']
                &&(string)($product['freight']??'')===(string)$rawRoot['freight']
                &&(string)($product['temp_id']??'')===(string)$rawRoot['temp_id'],'LOCKED_QUOTE_IDENTITY_PRICE_FREIGHT',$observer);
            foreach(['product_id','price','cost','vip_price','weight','volume','suk'] as $field){
                self::expect(array_key_exists($field,$sku)&&array_key_exists($field,$rawSku)
                    &&(string)$sku[$field]===(string)$rawSku[$field],'ACTUAL_LOCKED_SKU_INPUT',$observer);
            }
            foreach(['postage','logistics','is_virtual','virtual_type','is_vip','vip_price','presale'] as $field){
                self::expect(array_key_exists($field,$product)&&array_key_exists($field,$rawRoot)
                    &&(string)$product[$field]===(string)$rawRoot[$field],'ACTUAL_LOCKED_PRODUCT_INPUT',$observer);
            }
            foreach(['truePrice','vip_truePrice','sum_price','costPrice','postage_price','origin_postage_price'] as $field){
                self::expect(self::money($cart[$field]??null),'ACTUAL_QUOTE_DECIMAL',$observer);
            }
            self::expect($cart['origin_postage_price']===$cart['postage_price'],'ACTUAL_CHARGED_LINE_POSTAGE',$observer);
            $goods=bcadd($goods,bcmul($cart['truePrice'],(string)$line['qty'],2),2);
            $cost=bcadd($cost,bcmul($cart['costPrice'],(string)$line['qty'],2),2);
            $postage=bcadd($postage,$cart['postage_price'],2);
            $cart['id']=$line['cart_ref'];$cartInfo[]=$cart;
        }
        $prices=$group['priceGroup']??[];
        self::expect(self::money($prices['totalPrice']??null)&&self::money($prices['costPrice']??null)
            &&self::money($computed['pay_price']??null)&&self::money($computed['pay_postage']??null)
            &&$goods===$prices['totalPrice']&&$cost===$prices['costPrice']&&$postage===$computed['pay_postage']
            &&bcadd($goods,$postage,2)===$computed['pay_price'],'ACTUAL_LOCKED_QUOTE_TOTALS',$observer);
        $address=$locked['address']??null;
        self::expect(is_array($address)&&array_key_exists('id',$address)&&(string)$address['id']===(string)$addressId
            &&array_key_exists('uid',$address)&&(string)$address['uid']===(string)$uid
            &&array_key_exists('is_del',$address)&&(string)$address['is_del']==='0','ACTUAL_LOCKED_ADDRESS_IDENTITY',$observer);
        self::expect(is_array($group['addr']??null),'ACTUAL_QUOTE_ADDRESS_OBJECT',$observer);
        $visibleAddress=array_diff_key($address,['add_time'=>true,'is_del'=>true]);
        $projection=self::addressProjection($address,$group['addr']);
        self::expect(!$projection['missing_fields']&&!$projection['extra_fields'],'ACTUAL_QUOTE_ADDRESS_KEYS',$observer);
        foreach($visibleAddress as $field=>$value){
            self::expect(array_key_exists($field,$group['addr'])&&(string)$group['addr'][$field]===(string)$value,'ACTUAL_LOCKED_QUOTE_ADDRESS',$observer);
        }
        return ['cartInfo'=>$cartInfo,'address'=>$address];
    }

    /** Field names only; the two existing Model hidden columns are not quote fields. */
    public static function addressProjection(array $raw,mixed $quoted):array
    {
        $visible=array_diff_key($raw,['add_time'=>true,'is_del'=>true]);$quoted=is_array($quoted)?$quoted:[];
        $expected=array_keys($visible);$actual=array_keys($quoted);sort($expected,SORT_STRING);sort($actual,SORT_STRING);$changed=[];
        foreach($visible as $field=>$value)if(array_key_exists($field,$quoted)&&(string)$quoted[$field]!== (string)$value)$changed[]=$field;
        return ['missing_fields'=>array_values(array_diff($expected,$actual)),
            'extra_fields'=>array_values(array_diff($actual,$expected)),'changed_fields'=>$changed];
    }

    private static function money(mixed $value):bool
    {
        return is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)===1;
    }

    private static function expect(bool $condition,string $code,?callable $observer):void
    {
        if($observer!==null)$observer($condition,$code);
        if(!$condition)throw new AuthUnavailableException();
    }
}
