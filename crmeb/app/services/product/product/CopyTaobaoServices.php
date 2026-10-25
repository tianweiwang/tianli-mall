<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\services\product\product;

use app\services\BaseServices;
use app\services\serve\ServeServices;
use app\services\system\attachment\SystemAttachmentCategoryServices;
use app\services\system\attachment\SystemAttachmentServices;
use crmeb\exceptions\AdminException;
use app\services\other\UploadService;
use think\facade\Config;

/**
 *
 * Class CopyTaobaoServices
 * @package app\services\product\product
 */
class CopyTaobaoServices extends BaseServices
{
    /**
     * @var bool
     */
    protected $errorInfo = true;

    /**
     * @var string
     */
    public $AttachmentCategoryName = '远程下载';

    /**
     * @var string[]
     */
    protected $host = ['taobao', 'tmall', 'jd', 'pinduoduo', 'suning', 'yangkeduo', '1688'];

    /**
     * @param $type
     * @param $id
     * @param $shopid
     * @param $url
     * @return array
     */
    public function copyProduct($type, $id, $shopid, $url)
    {
        $result = [];
        switch ((int)sys_config('system_product_copy_type')) {
            case 1://平台
                /** @var ServeServices $services */
                $services = app()->make(ServeServices::class);
                $resultData = $services->copy('copy')->goods($url);
                if (isset($resultData['description_image']) && is_string($resultData['description_image'])) {
                    $resultData['description_image'] = json_decode($resultData['description_image'], true);
                }
                if (isset($resultData['slider_image']) && is_string($resultData['slider_image'])) {
                    $resultData['slider_image'] = json_decode($resultData['slider_image'], true);
                }
                $result['status'] = 200;
                $result['data'] = $resultData;
                break;
            case 2://99API
                $apikey = sys_config('copy_product_apikey');
                if (!$apikey) throw new AdminException('请先配置接口密钥');
                /** @var ServeServices $services */
                $services = app()->make(ServeServices::class);
                $result = $services->copy('copy99api')->goods($url, [
                    'apikey' => $apikey,
                ]);
                break;
        }
        if (isset($result['status']) && $result['status']) {

            /** @var StoreProductServices $ProductServices */
            $ProductServices = app()->make(StoreProductServices::class);
            /** @var StoreCategoryServices $storeCatecoryService */
            $storeCatecoryService = app()->make(StoreCategoryServices::class);
            $data = [];
            $productInfo = $result['data'];
            if (count($productInfo['slider_image'])) {
                $productInfo['slider_image'] = array_map(function ($item) {
                    $item = str_replace('\\', '/', $item);
                    return $item;
                }, $productInfo['slider_image']);
            }
            $data['tempList'] = $ProductServices->getTemp();
            $menus = [];
            foreach ($storeCatecoryService->getTierList(1) as $menu) {
                $menus[] = ['value' => $menu['id'], 'label' => $menu['html'] . $menu['cate_name'], 'disabled' => $menu['pid'] == 0 ? 0 : 1];//,'disabled'=>$menu['pid']== 0];
            }
            $data['cateList'] = $menus;
            $productInfo['attrs'] = $result['data']['info']['value'];
            foreach ($productInfo['attrs'] as $attrs_k => $attrs_v) {
                $productInfo['attrs'][$attrs_k]['attr_arr'] = array_values($attrs_v['detail']);
                $productInfo['attrs'][$attrs_k]['is_show'] = 1;
            }
            $productInfo['activity'] = ['默认', '秒杀', '砍价', '拼团'];
            $productInfo['bar_code'] = '';
            $productInfo['browse'] = 0;
            $productInfo['cate_id'] = [];
            $productInfo['code_path'] = '';
            $productInfo['command_word'] = '';
            $productInfo['coupons'] = [];
            $productInfo['is_bargain'] = '';
            $productInfo['is_benefit'] = 0;
            $productInfo['is_best'] = 0;
            $productInfo['is_del'] = 0;
            $productInfo['is_good'] = 0;
            $productInfo['is_hot'] = 0;
            $productInfo['is_new'] = 0;
            $productInfo['is_postage'] = 0;
            $productInfo['is_seckill'] = 0;
            $productInfo['is_show'] = 0;
            $productInfo['is_sub'] = [];
            $productInfo['is_vip'] = 0;
            $productInfo['label_id'] = [];
            $productInfo['mer_id'] = 0;
            $productInfo['mer_use'] = 0;
            $productInfo['recommend_image'] = '';
            $productInfo['sales'] = '';
            $productInfo['sort'] = 0;
            $productInfo['spec_type'] = 1;
            $productInfo['is_virtual'] = 0;
            $productInfo['virtual_type'] = 0;
            $productInfo['spu'] = '';
            $productInfo['id'] = 0;
            $productInfo['temp_id'] = 1;
            $productInfo['freight'] = 3;
            $productInfo['recommend'] = [];
            $productInfo['logistics'] = ['1', '2'];
            $productInfo['params_list'] = [];
            $productInfo['label_list'] = [];
            $productInfo['protection_list'] = [];
            foreach ($productInfo['items'] as &$items) {
                $details = [];
                foreach ($items['detail'] as $detail) {
                    $details[] = [
                        'value' => $detail,
                        'pic' => '',
                    ];
                }
                $items['detail'] = $details;
                $items['add_pic'] = 0;
            }
            $data['productInfo'] = $productInfo;
            return $data;
        } else {
            throw new AdminException($result['msg']);
        }
    }

    /** The old crawl payload uses the real standard-save defaults, not invented owners. */
    public function save(array $data)
    {
        $defaults=['virtual_type'=>0,'cate_id'=>[],'store_name'=>'','keyword'=>'','unit_name'=>'件','store_info'=>'',
            'slider_image'=>[],'video_open'=>0,'video_link'=>'','spec_type'=>0,'items'=>[],'attrs'=>[],
            'description'=>'','description_images'=>[],'logistics'=>[],'freight'=>1,'postage'=>0,'temp_id'=>0,
            'give_integral'=>0,'presale'=>0,'presale_time'=>0,'presale_day'=>0,'vip_product'=>0,'vip_product_type'=>0,
            'is_sub'=>[],'recommend'=>[],'activity'=>[],'recommend_list'=>[],'coupon_ids'=>[],'label_id'=>[],
            'command_word'=>'','is_show'=>0,'ficti'=>0,'sort'=>0,'recommend_image'=>'','sales'=>0,'custom_form'=>[],
            'is_copy'=>0,'is_limit'=>0,'limit_type'=>0,'limit_num'=>0,'min_qty'=>1,'params_list'=>[],
            'label_list'=>[],'protection_list'=>[],'is_gift'=>0,'gift_price'=>0];
        $data=array_replace($defaults,$data);
        if(is_string($data['cate_id']))$data['cate_id']=$data['cate_id']===''?[]:explode(',',$data['cate_id']);
        elseif(is_int($data['cate_id']))$data['cate_id']=[$data['cate_id']];
        $data['type']=-1;
        return app()->make(StoreProductServices::class)->save(0,$data);
    }

    /** Prefetch has current real identity, but grants no merchant ownership or write authority. */
    public function prepareCopyPlan(array $data,?\app\dao\marketplace\scope\PlatformScope $scope): array
    {
        $writer=app()->make(\app\services\marketplace\product\LegacyProductWriteServices::class);
        if(!\app\services\marketplace\product\LegacyProductWriteServices::enabled()
            ||$writer->authorizeCurrentRequest()!==$scope)throw new \crmeb\exceptions\AuthUnavailableException();
        if($scope!==null&&!in_array($scope->permission,['legacy-product-save','legacy-product-copy-save'],true))throw new \crmeb\exceptions\AuthUnavailableException();
        $plan=['request'=>app()->request,'scope'=>$scope,'images'=>[]];$seen=[];
        try {
            $references=app()->make(\app\services\marketplace\product\ProductMediaReferenceServices::class)->legacyReferences($data);
            foreach($references as$reference){
                if(!in_array($reference['kind'],['image','video','audio','unresolved'],true))throw new \crmeb\exceptions\AuthUnavailableException();
                $key=$reference['kind']."\0".$reference['path'];if(isset($seen[$key]))continue;$seen[$key]=true;
                $source=$reference['path'];$url=self::copyUrl($source);
                $fetched=(new \app\services\marketplace\product\ProductMediaRemoteImages())->fetchOneForTypedProductCopy($url);
                if($fetched['url_sha256']!==hash('sha256',$url))throw new \crmeb\exceptions\AuthUnavailableException();
                $facts=$this->qualifyCopyMediaBytes($fetched['bytes'],$fetched['url_sha256'],$reference['kind']);
                $entry=self::spoolCopyBytes($fetched['bytes']);
                $entry['source']=$source;$entry['source_kind']=$reference['kind'];
                $entry['resolved_kind']=$facts['resolved_kind'];$entry['display_name']=$facts['display_name'];
                $plan['images'][]=$entry;unset($fetched,$facts,$entry);
            }
            return $plan;
        } catch(\Throwable $error) {
            if(!self::releaseCopyPlan($plan))throw new \app\services\marketplace\attachment\MerchantAttachmentOperationException([
                'complete'=>false,'phase'=>'copy_prefetch_cleanup','physical_changed'=>true,'physical_complete'=>false,
                'database_committed'=>false,'cleanup_complete'=>false]);
            throw $error;
        }
    }

    /** Current new-upload policy; original field intent remains separate from resolved content. */
    private function qualifyCopyMediaBytes(string $bytes,string $urlSha256,string $sourceKind): array
    {
        if(!in_array($sourceKind,['image','video','audio','unresolved'],true)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$urlSha256)!==1)throw new \crmeb\exceptions\AuthUnavailableException();
        $content=\app\services\marketplace\attachment\MerchantAttachmentReferenceFormat::qualify($bytes);
        if($sourceKind!=='unresolved'&&$content['resolved_kind']!==$sourceKind)
            \app\services\marketplace\attachment\MerchantAttachmentInput::invalid();
        $possible=match($content['format']['format']){
            'jpg'=>['jpg','jpeg'],'png'=>['png'],'gif'=>['gif'],'webp'=>['webp'],'ico'=>['ico'],
            'mp4'=>['mp4'],'mpeg-audio'=>['mp3'],'asf-wma'=>['wma'],'wave'=>['wav'],'amr','amr-wb'=>['amr'],
            default=>[],
        };
        $extensions=Config::get('upload.fileExt',[]);if(!is_array($extensions))throw new \crmeb\exceptions\AuthUnavailableException();
        $extension=null;foreach($possible as$value)if(in_array($value,$extensions,true)){$extension=$value;break;}
        if($extension===null)\app\services\marketplace\attachment\MerchantAttachmentInput::invalid();
        $displayName='copy_'.$urlSha256.'.'.$extension;
        $qualified=app()->make(\app\services\marketplace\attachment\MerchantAttachmentStorage::class)
            ->qualifyTypedUploadBytes($bytes,$displayName,$content['resolved_kind']);
        if($qualified['size']!==strlen($bytes)||$qualified['sha256']!==hash('sha256',$bytes)
            ||$qualified['resolved_kind']!==$content['resolved_kind']||$qualified['mime']!==$content['mime']
            ||$qualified['format']!==$content['format'])throw new \crmeb\exceptions\AuthUnavailableException();
        return $qualified+['display_name'=>$displayName];
    }

    private static function copyUrl(string $source): string
    {
        // Collector paths are already DOM-decoded; plain URL slots are literal.
        $url=$source;
        if(preg_match('~\Ahttps?://~iD',$url)===1)return $url;
        if(str_starts_with($url,'/uploads/')){
            $site=sys_config('site_url');if(!is_string($site)||$site==='')throw new \crmeb\exceptions\AuthUnavailableException();
            return rtrim($site,'/').$url;
        }
        return 'http://'.ltrim($url,'\\/');
    }

    private static function spoolStat($handle): array
    {
        if(!is_resource($handle)||get_resource_type($handle)!=='stream')throw new \crmeb\exceptions\AuthUnavailableException();
        $stat=fstat($handle);$out=[];
        foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key){
            if(!is_array($stat)||!array_key_exists($key,$stat)||!is_int($stat[$key]))throw new \crmeb\exceptions\AuthUnavailableException();
            $out[$key]=$stat[$key];
        }
        if(($out['mode']&0170000)!==0100000||($out['mode']&0777)!==0600||$out['ino']<1||!in_array($out['nlink'],[0,1],true)||$out['size']<0)throw new \crmeb\exceptions\AuthUnavailableException();
        return $out;
    }

    private static function spoolCopyBytes(string $bytes): array
    {
        $handle=tmpfile();if($handle===false)throw new \crmeb\exceptions\AuthUnavailableException();
        $entry=['spool'=>$handle,'pin'=>['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)],'stat'=>[],
            'close_attempted'=>false,'cleanup_complete'=>null];
        try {
            $initial=self::spoolStat($handle);if($initial['size']!==0)throw new \crmeb\exceptions\AuthUnavailableException();
            $offset=0;$length=strlen($bytes);
            while($offset<$length){$written=fwrite($handle,substr($bytes,$offset,min(262144,$length-$offset)));
                if(!is_int($written)||$written<1)throw new \crmeb\exceptions\AuthUnavailableException();$offset+=$written;}
            if(fflush($handle)!==true||rewind($handle)!==true)throw new \crmeb\exceptions\AuthUnavailableException();
            $after=self::spoolStat($handle);
            foreach(['dev','ino','mode','nlink','uid','gid']as$key)if($after[$key]!==$initial[$key])throw new \crmeb\exceptions\AuthUnavailableException();
            if($after['size']!==$length)throw new \crmeb\exceptions\AuthUnavailableException();$entry['stat']=$after;
            self::observeCopySpool($entry,false);if(rewind($handle)!==true)throw new \crmeb\exceptions\AuthUnavailableException();
            return $entry;
        } catch(\Throwable $error) {
            $entry['close_attempted']=true;$entry['cleanup_complete']=fclose($handle)===true;
            if(!$entry['cleanup_complete'])throw new \app\services\marketplace\attachment\MerchantAttachmentOperationException([
                'complete'=>false,'phase'=>'copy_spool_cleanup','physical_changed'=>true,'physical_complete'=>false,
                'database_committed'=>false,'cleanup_complete'=>false]);
            throw $error;
        }
    }

    private static function observeCopySpool(array $entry,bool $collect=true): string
    {
        $handle=$entry['spool'];if(self::spoolStat($handle)!==$entry['stat']||rewind($handle)!==true)throw new \crmeb\exceptions\AuthUnavailableException();
        $hash=hash_init('sha256');$chunks=[];$total=0;
        if($collect)self::copyReadMemory($entry['pin']['bytes']);
        while(!feof($handle)){
            $chunk=fread($handle,262144);if(!is_string($chunk)||($chunk===''&&!feof($handle)))throw new \crmeb\exceptions\AuthUnavailableException();
            $total+=strlen($chunk);if($total>$entry['pin']['bytes'])throw new \crmeb\exceptions\AuthUnavailableException();
            hash_update($hash,$chunk);if($collect)$chunks[]=$chunk;
        }
        if(fread($handle,1)!==''||$total!==$entry['pin']['bytes']||hash_final($hash)!==$entry['pin']['sha256']
            ||self::spoolStat($handle)!==$entry['stat'])throw new \crmeb\exceptions\AuthUnavailableException();
        return implode('',$chunks);
    }

    private static function copyReadMemory(int $bytes): void
    {
        $configured=ini_get('memory_limit');if(!is_string($configured))throw new \crmeb\exceptions\AuthUnavailableException();
        $value=trim($configured);if($value==='-1')return;
        if(preg_match('/\A([0-9]+)([KMG]?)\z/iD',$value,$match)!==1)throw new \crmeb\exceptions\AuthUnavailableException();
        $limit=(int)$match[1];$power=match(strtoupper($match[2])){'K'=>1,'M'=>2,'G'=>3,default=>0};
        for($i=0;$i<$power;$i++){if($limit>intdiv(PHP_INT_MAX,1024))throw new \crmeb\exceptions\AuthUnavailableException();$limit*=1024;}
        $used=memory_get_usage(true);if($bytes<0||$bytes>intdiv(PHP_INT_MAX-1048576,2)||$used>$limit
            ||2*$bytes+1048576>$limit-$used)throw new \crmeb\exceptions\AuthUnavailableException();
    }

    /** A publisher consumes only this request's original spool resource, then closes it once. */
    public static function readCopySpool(array &$entry): string
    {
        try{return self::observeCopySpool($entry);}
        finally{
            $entry['close_attempted']=true;$entry['cleanup_complete']=fclose($entry['spool'])===true;
            if($entry['cleanup_complete'])$entry['spool']=null;
            if(!$entry['cleanup_complete'])throw new \crmeb\exceptions\AuthUnavailableException();
        }
    }

    public static function releaseCopyPlan(array &$plan): bool
    {
        $complete=true;if(!isset($plan['images']))return true;
        foreach($plan['images']as&$entry){
            if(($entry['cleanup_complete']??null)===true&&$entry['spool']===null)continue;
            if(($entry['close_attempted']??false)===true){$complete=false;continue;}
            $entry['close_attempted']=true;$entry['cleanup_complete']=is_resource($entry['spool'])&&fclose($entry['spool'])===true;
            if($entry['cleanup_complete'])$entry['spool']=null;else$complete=false;
        }
        unset($entry);return $complete;
    }

    /** Rewrites known slots once; array order, duplicates and unrelated HTML bytes stay intact. */
    public function applyCopyPlan(array $data,array $plan,array $rows): array
    {
        if(count($rows)!==count($plan['images']))throw new \crmeb\exceptions\AuthUnavailableException();$maps=[];
        foreach($plan['images']as$index=>$image){$row=$rows[$index];
            if(!is_array($row)||!is_string($row['att_dir']??null)||$row['att_dir']==='')throw new \crmeb\exceptions\AuthUnavailableException();
            $maps[$image['source_kind']][$image['source']]=$row['att_dir'];}
        $imageMap=$maps['image']??[];
        foreach($data['slider_image']as&$path)if(isset($imageMap[$path]))$path=$imageMap[$path];unset($path);
        foreach($data['attrs']as&$sku)if(isset($sku['pic'],$imageMap[$sku['pic']]))$sku['pic']=$imageMap[$sku['pic']];unset($sku);
        foreach($data['items']as&$item)if(isset($item['detail'])&&is_array($item['detail'])){foreach($item['detail']as&$option)if(is_array($option)&&isset($option['pic'],$imageMap[$option['pic']]))$option['pic']=$imageMap[$option['pic']];unset($option);}unset($item);
        foreach(['image','recommend_image']as$field)if(isset($data[$field],$imageMap[$data[$field]]))$data[$field]=$imageMap[$data[$field]];
        $videoMap=$maps['video']??[];if(isset($data['video_link'],$videoMap[$data['video_link']]))$data['video_link']=$videoMap[$data['video_link']];
        if(is_string($data['description']??null))$data['description']=self::rewriteCopyHtml($data['description'],$maps);
        if(is_array($data['description_images']??null))foreach($data['description_images']as&$path)if(is_string($path)&&isset($imageMap[$path]))$path=$imageMap[$path];unset($path);
        return $data;
    }

    public function replaceCopyImageInHtml(string $html,string $source,string $destination): string
    {
        // The original description queue captures the raw double-quoted img.src.
        // Recover its decoded URL only from that actual attribute in this HTML.
        $slots=array_values(array_filter(self::copyHtmlSlots($html),static fn(array $slot):bool=>
            $slot['tag']==='img'&&$slot['attribute']==='src'&&$slot['raw']===$source));$maps=[];
        foreach($slots as$slot){
            $maps['image'][$slot['path']]=$destination;
        }
        return self::rewriteCopyHtml($html,$maps,$slots);
    }

    private static function rewriteCopyHtml(string $html,array $maps,?array $slots=null): string
    {
        $slots??=self::copyHtmlSlots($html);$changes=[];
        foreach($slots as$slot){
            if(!isset($maps[$slot['kind']][$slot['path']]))continue;
            $value=htmlspecialchars($maps[$slot['kind']][$slot['path']],ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
            if($slot['quote']===''&&preg_match('/[\s"\'=<>`]/',$value)===1)$value='"'.$value.'"';
            $changes[]=['start'=>$slot['start'],'length'=>$slot['length'],'value'=>$value];
        }
        // All offsets belong to the original source; reverse application cannot
        // consume a replacement or alter any unrelated HTML text/attribute.
        usort($changes,static fn(array $a,array $b):int=>$b['start']<=>$a['start']);
        foreach($changes as$change)$html=substr_replace($html,$change['value'],$change['start'],$change['length']);
        return $html;
    }

    /** Byte offsets only; the existing DOM parser still supplies every URL/kind. */
    private static function copyHtmlSlots(string $html): array
    {
        $input=\app\services\marketplace\product\MerchantProductInput::class;
        $media=$input::typedHtmlMedia($html);$slots=[];$cursor=0;$length=strlen($html);$next=0;
        $space=" \t\r\n\f";
        while($cursor<$length){
            $start=strpos($html,'<',$cursor);if($start===false)break;
            if(substr($html,$start,4)==='<!--'){
                $end=strpos($html,'-->',$start+4);$bang=strpos($html,'--!>',$start+4);
                if($bang!==false&&($end===false||$bang<$end)){$end=$bang;$close=4;}else$close=3;
                $cursor=$end===false?$length:$end+$close;continue;
            }
            $at=$start+1;$closing=($html[$at]??'')==='/';if($closing)$at++;
            if(!isset($html[$at])||preg_match('/[a-z]/iD',$html[$at])!==1){
                // Declarations/processing instructions are not element content.
                if(in_array($html[$start+1]??'',['!','?'],true)){
                    $end=strpos($html,'>',$start+2);$cursor=$end===false?$length:$end+1;
                }else$cursor=$start+1;
                continue;
            }
            $nameStart=$at;
            while($at<$length&&!str_contains($space.'/>',$html[$at]))$at++;
            $name=strtolower(substr($html,$nameStart,$at-$nameStart));$attributes=[];
            while($at<$length){
                while($at<$length&&str_contains($space,$html[$at]))$at++;
                if($at===$length)break;
                if($html[$at]==='>'){$at++;break;}
                if($html[$at]==='/'){$at++;continue;}
                $attributeStart=$at;
                while($at<$length&&!str_contains($space.'=/>',$html[$at]))$at++;
                if($at===$attributeStart){$at++;continue;}
                $attribute=strtolower(substr($html,$attributeStart,$at-$attributeStart));
                while($at<$length&&str_contains($space,$html[$at]))$at++;
                $value=null;
                if($at<$length&&$html[$at]==='='){
                    $at++;while($at<$length&&str_contains($space,$html[$at]))$at++;
                    $quote=in_array($html[$at]??'',['"',"'"],true)?$html[$at++]:'';$valueStart=$at;
                    if($quote!==''){
                        $end=strpos($html,$quote,$at);$at=$end===false?$length:$end;
                    }else while($at<$length&&!str_contains($space.'>',$html[$at]))$at++;
                    $value=['start'=>$valueStart,'length'=>$at-$valueStart,'quote'=>$quote,
                        'raw'=>substr($html,$valueStart,$at-$valueStart)];
                    if($quote!==''&&$at<$length)$at++;
                }
                // HTML keeps the first occurrence, including an empty/boolean one.
                if(!array_key_exists($attribute,$attributes))$attributes[$attribute]=$value;
            }
            $cursor=$at;
            if(!$closing&&in_array($name,['img','video','audio','source'],true)){
                // Decode the actual raw tag using exactly the full document's
                // existing libxml rules, never ENT_HTML5 or a second URL decode.
                $tagRows=$input::typedHtmlMedia(substr($html,$start,$at-$start));
                foreach($tagRows as$row){
                    $field=$row['attribute'];$slot=$attributes[$field]??null;$whole=$media[$next]??null;
                    if(!is_array($slot)||$row['tag']!==$name||!is_array($whole)
                        ||$whole['tag']!==$name||$whole['attribute']!==$field||$whole['path']!==$row['path'])
                        throw new \crmeb\exceptions\AuthUnavailableException();
                    $slots[]=$slot+['path'=>$whole['path'],'kind'=>$whole['kind'],'tag'=>$name,'attribute'=>$field];$next++;
                }
            }
            if(!$closing&&in_array($name,['title','textarea','script','style','xmp','iframe','noembed','noframes','plaintext'],true)){
                // RCDATA/raw-text may contain '<img>' literally. Only its real
                // end tag resumes element scanning; plaintext consumes the rest.
                if($name==='plaintext'){$cursor=$length;continue;}
                $matched=preg_match('~</'.preg_quote($name,'~').'(?=[\x20\t\r\n\f/>])~i',$html,$endTag,PREG_OFFSET_CAPTURE,$cursor);
                if($matched===false)throw new \crmeb\exceptions\AuthUnavailableException();
                $cursor=$matched===1?$endTag[0][1]:$length;
            }
        }
        if($next!==count($media))throw new \crmeb\exceptions\AuthUnavailableException();
        return $slots;
    }

    /** The legacy description producer supplies one raw double-quoted attribute. */
    private static function copyDescriptionSource(string $source): string
    {
        if(str_contains($source,'"'))throw new \crmeb\exceptions\AuthUnavailableException();
        $rows=\app\services\marketplace\product\MerchantProductInput::typedHtmlMedia('<img src="'.$source.'">');
        if(count($rows)!==1||$rows[0]['tag']!=='img'||$rows[0]['attribute']!=='src')throw new \crmeb\exceptions\AuthUnavailableException();
        return $rows[0]['path'];
    }

    /** Existing selfWorker jobs publish owned objects; they do not borrow an admin Request. */
    public function downloadCopyImageForProduct(int $productId,string $source,array &$receipt,array &$commitSnapshot,bool $htmlAttribute=false): string
    {
        $receipt=['complete'=>false,'phase'=>'copy_download','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::enabled()||!$guard::isSelfWorker())throw new \crmeb\exceptions\AuthUnavailableException();
        $guard::assertParent($productId);$pdo=$guard::transaction();$owner=$guard::mediaOwner($productId);
        if($htmlAttribute)$source=self::copyDescriptionSource($source);
        $url=self::copyUrl($source);
        $fetched=(new \app\services\marketplace\product\ProductMediaRemoteImages())->fetchOneForProductCopy($url);
        if($fetched['url_sha256']!==hash('sha256',$url))throw new \crmeb\exceptions\AuthUnavailableException();
        $facts=$this->qualifyCopyMediaBytes($fetched['bytes'],$fetched['url_sha256'],'image');
        if($guard::transaction()!==$pdo||$guard::mediaOwner($productId)!==$owner)throw new \crmeb\exceptions\AuthUnavailableException();
        $row=app()->make(\app\services\marketplace\product\PlatformProductMediaServices::class)->publishCopyImageInCurrentWriter($productId,$fetched['bytes'],$facts['display_name'],0,$receipt);
        $commitSnapshot=app()->make(\app\services\marketplace\product\PlatformProductMediaServices::class)->copyCommitSnapshot([$row]);
        unset($fetched,$facts);
        app()->make(\app\services\marketplace\product\ProductMediaReferenceServices::class)->assertForProduct($productId,[['kind'=>'image','path'=>$row['att_dir']]]);
        return $row['att_dir'];
    }

    /**
     * 下载商品详情图片
     * @param int $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function uploadDescriptionImage(int $id)
    {
        //查询附件分类
        /** @var SystemAttachmentCategoryServices $systemAttachmentCategoryService */
        $systemAttachmentCategoryService = app()->make(SystemAttachmentCategoryServices::class);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        $AttachmentCategory = $systemAttachmentCategoryService->getOne(['name' => $this->AttachmentCategoryName]);
        //不存在则创建
        if (!$AttachmentCategory) $AttachmentCategory = $systemAttachmentCategoryService->save(['pid' => '0', 'name' => $this->AttachmentCategoryName, 'enname' => '']);
        //生成附件目录
        try {
            if (make_path('attach', 3, true) === '')
                throw new AdminException('无法创建文件夹，请检查您的上传目录权限');
        } catch (\Exception $e) {
            throw new AdminException('无法创建文件夹，请检查您的上传目录权限');
        }
        $description = $storeDescriptionServices->getDescription(['product_id ' => $id, 'type' => 0]);
        if (!$description) throw new AdminException('商品参数错误');
        //替换并下载详情里面的图片默认下载全部图片
        $description = preg_replace('#<style>.*?</style>#is', '', $description);
        $description = $this->uploadImage([], $description, 1, $AttachmentCategory['id']);
        $storeDescriptionServices->saveDescription((int)$id, $description);
        return true;
    }

    /**
     * 上传图片处理
     * @param array $images
     * @param string $html
     * @param int $uploadType
     * @param int $AttachmentCategoryId
     * @return array|bool|string|string[]|null
     */
    public function uploadImage(array $images = [], $html = '', $uploadType = 0, $AttachmentCategoryId = 0)
    {
        /** @var SystemAttachmentServices $systemAttachmentService */
        $systemAttachmentService = app()->make(SystemAttachmentServices::class);
        $uploadImage = [];
        $siteUrl = sys_config('site_url');
        switch ($uploadType) {
            case 0:
                foreach ($images as $item) {
                    //下载图片文件
                    if ($item['w'] && $item['h'])
                        $uploadValue = $this->downloadImage($item['line'], '', 0, 30, $item['w'], $item['h']);
                    else
                        $uploadValue = $this->downloadImage($item['line']);
                    //下载成功更新数据库
                    if (is_array($uploadValue)) {
                        //TODO 拼接图片地址
                        if ($uploadValue['image_type'] == 1) $imagePath = $siteUrl . $uploadValue['path'];
                        else $imagePath = $uploadValue['path'];
                        //写入数据库
                        if (!$uploadValue['is_exists'] && $AttachmentCategoryId) {
                            $systemAttachmentService->save([
                                'name' => $uploadValue['name'],
                                'real_name' => $uploadValue['name'],
                                'att_dir' => $imagePath,
                                'satt_dir' => $imagePath,
                                'att_size' => $uploadValue['size'],
                                'att_type' => $uploadValue['mime'],
                                'image_type' => $uploadValue['image_type'],
                                'module_type' => 1,
                                'time' => time(),
                                'pid' => $AttachmentCategoryId
                            ]);
                        }
                        //组装数组
                        if (isset($item['isTwoArray']) && $item['isTwoArray'])
                            $uploadImage[$item['valuename']][] = $imagePath;
                        else
                            $uploadImage[$item['valuename']] = $imagePath;
                    }
                }
                break;
            case 1:
                preg_match_all('#<img.*?src="([^"]*)"[^>]*>#i', $html, $match);
                if (isset($match[1])) {
                    foreach ($match[1] as $item) {
                        if (is_int(strpos($item, 'http')))
                            $arcurl = $item;
                        else
                            $arcurl = 'http://' . ltrim($item, '\//');
                        $uploadValue = $this->downloadImage($arcurl);
                        //下载成功更新数据库
                        if (is_array($uploadValue)) {
                            //TODO 拼接图片地址
                            if ($uploadValue['image_type'] == 1) $imagePath = $siteUrl . $uploadValue['path'];
                            else $imagePath = $uploadValue['path'];
                            //写入数据库
                            if (!$uploadValue['is_exists'] && $AttachmentCategoryId) {
                                $systemAttachmentService->save([
                                    'name' => $uploadValue['name'],
                                    'real_name' => $uploadValue['name'],
                                    'att_dir' => $imagePath,
                                    'satt_dir' => $imagePath,
                                    'att_size' => $uploadValue['size'],
                                    'att_type' => $uploadValue['mime'],
                                    'image_type' => $uploadValue['image_type'],
                                    'module_type' => 1,
                                    'time' => time(),
                                    'pid' => $AttachmentCategoryId
                                ]);
                            }
                            //替换图片
                            $html = str_replace($item, $imagePath, $html);
                        } else {
                            //替换掉没有下载下来的图片
                            $html = preg_replace('#<img.*?src="' . $item . '"*>#i', '', $html);
                        }
                    }
                }
                return $html;
                break;
            default:
                throw new AdminException('上传方式错误');
                break;
        }
        return $uploadImage;
    }

    /**
     * @param string $url
     * @param string $name
     * @param int $type
     * @param int $timeout
     * @param int $w
     * @param int $h
     * @return array|string
     */
    public function downloadImage($url = '', $name = '', $type = 0, $timeout = 30, $w = 0, $h = 0)
    {
        if (!strlen(trim($url))) return '';

        // 自动判断当前图片链接是否为需要使用curl下载的平台（淘宝、京东、天猫、1688）
        if ($type == 0 && strlen(trim($url))) {
            $antiHotlinkingPlatforms = ['alicdn.com', 'taobao.com', 'tmall.com', 'jd.com', 'jdstatic.com', '1688.com'];
            foreach ($antiHotlinkingPlatforms as $platform) {
                if (stripos($url, $platform) !== false) {
                    $type = 1;
                    break;
                }
            }
        }

        if (!strlen(trim($name))) {
            //TODO 获取要下载的文件名称
            $downloadImageInfo = $this->getImageExtname($url);
            $ext = $downloadImageInfo['ext_name'];
            $name = $downloadImageInfo['file_name'];
            if (!strlen(trim($name))) return '';
        } else {
            $ext = $this->getImageExtname($name)['ext_name'];
        }
        if (!in_array($ext, Config::get('upload.fileExt'))) {
            throw new AdminException('格式错误');
        }
        //TODO 获取远程文件所采用的方法
        if ($type) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //TODO 跳过证书检查
            if (stripos($url, "https://") !== FALSE) curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);  //TODO 从证书中检查SSL加密算法是否存在
            // 根据URL识别平台并获取对应的防盗链headers
            $headers = $this->getAntiHotlinkingHeaders($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);//TODO 是否采集301、302之后的页面
            $content = curl_exec($ch);
            curl_close($ch);
        } else {
            try {
                ob_start();
                if (substr($url, 0, 2) == '//') {
                    $url = "https:" . $url;
                }
                @readfile($url);
                $content = ob_get_contents();
                ob_end_clean();
            } catch (\Exception $e) {
                throw new AdminException($e->getMessage());
            }
        }
        $size = strlen(trim($content));
        if (!$content || $size <= 2) throw new AdminException('图片流获取失败');
        $date_dir = date('Y') . '/' . date('m') . '/' . date('d');
        $upload_type = sys_config('upload_type', 1);
        $upload = UploadService::init($upload_type);
        if ($upload->to('attach/' . $date_dir)->validate()->setAuthThumb(false)->stream($content, $name) === false) {
            throw new AdminException($upload->getError());
        }
        $imageInfo = $upload->getUploadInfo();
        $date['path'] = $imageInfo['dir'];
        $date['name'] = $imageInfo['name'];
        $date['size'] = $imageInfo['size'];
        $date['mime'] = $imageInfo['type'];
        $date['image_type'] = $upload_type;
        $date['is_exists'] = false;
        return $date;
    }

    /**
     * 获取即将要下载的图片扩展名
     * @param string $url
     * @param string $ex
     * @return array|string[]
     */
    public function getImageExtname($url = '', $ex = 'jpg')
    {
        $_empty = ['file_name' => '', 'ext_name' => $ex];
        if (!$url) return $_empty;
        if (strpos($url, '?')) {
            $_tarr = explode('?', $url);
            $url = trim($_tarr[0]);
        }
        $arr = explode('.', $url);
        if (!is_array($arr) || count($arr) <= 1) return $_empty;
        $ext_name = trim($arr[count($arr) - 1]);
        $ext_name = !$ext_name ? $ex : $ext_name;
        return ['file_name' => md5($url) . '.' . $ext_name, 'ext_name' => $ext_name];
    }

    /**
     * SPU
     * @return string
     */
    public function createSpu()
    {
        return substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8) . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 下载远程图片并上传
     * @param $image
     * @return false|mixed|string
     * @throws \Exception
     */
    public function downloadCopyImage($image)
    {
        //查询附件分类
        /** @var SystemAttachmentCategoryServices $systemAttachmentCategoryService */
        $systemAttachmentCategoryService = app()->make(SystemAttachmentCategoryServices::class);
        $AttachmentCategory = $systemAttachmentCategoryService->getOne(['name' => '远程下载']);
        //不存在则创建
        if (!$AttachmentCategory) {
            $AttachmentCategory = $systemAttachmentCategoryService->save(['pid' => '0', 'name' => '远程下载', 'enname' => '']);
        }

        //生成附件目录
        if (make_path('attach', 3, true) === '') {
            throw new AdminException('无法创建文件夹，请检查您的上传目录权限');
        }

        //上传图片
        /** @var SystemAttachmentServices $systemAttachmentService */
        $systemAttachmentService = app()->make(SystemAttachmentServices::class);
        $siteUrl = sys_config('site_url');
        $uploadValue = $this->downloadImage($image);
        if (is_array($uploadValue)) {
            //TODO 拼接图片地址
            if ($uploadValue['image_type'] == 1) {
                $imagePath = $siteUrl . $uploadValue['path'];
            } else {
                $imagePath = $uploadValue['path'];
            }
            //写入数据库
            if (!$uploadValue['is_exists'] && $AttachmentCategory['id']) {
                $systemAttachmentService->save([
                    'name' => $uploadValue['name'],
                    'real_name' => $uploadValue['name'],
                    'att_dir' => $imagePath,
                    'satt_dir' => $imagePath,
                    'att_size' => $uploadValue['size'],
                    'att_type' => $uploadValue['mime'],
                    'image_type' => $uploadValue['image_type'],
                    'module_type' => 1,
                    'time' => time(),
                    'pid' => $AttachmentCategory['id']
                ]);
            }
            return $imagePath;
        }
        return false;
    }

    /**
     * 根据URL识别平台并获取对应的防盗链headers
     * @param string $url 图片URL
     * @return array 返回对应的HTTP headers
     */
    public function getAntiHotlinkingHeaders(string $url): array
    {
        // 基础headers
        $baseHeaders = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ];

        // 根据URL识别平台
        if (stripos($url, 'alicdn.com') !== false || stripos($url, 'taobao.com') !== false) {
            // 淘宝/天猫/1688的图片 (通常在alicdn.com域名下)
            return array_merge($baseHeaders, [
                'Referer: https://buyer.taobao.com/',  // 淘宝买家中心
            ]);
        } elseif (stripos($url, 'tmall.com') !== false) {
            // 天猫的图片
            return array_merge($baseHeaders, [
                'Referer: https://www.tmall.com/',  // 天猫首页
            ]);
        } elseif (stripos($url, 'jd.com') !== false || stripos($url, 'jdstatic.com') !== false) {
            // 京东的图片
            return array_merge($baseHeaders, [
                'Referer: https://www.jd.com/',  // 京东首页
            ]);
        } elseif (stripos($url, '1688.com') !== false) {
            // 1688的图片
            return array_merge($baseHeaders, [
                'Referer: https://www.1688.com/',  // 1688首页
            ]);
        } elseif (stripos($url, 'baidu.com') !== false) {
            // 百度图片
            return array_merge($baseHeaders, [
                'Referer: https://image.baidu.com/',  // 百度图片
            ]);
        } elseif (stripos($url, 'sinaimg.cn') !== false) {
            // 新浪图片
            return array_merge($baseHeaders, [
                'Referer: https://weibo.com/',  // 新浪微博
            ]);
        } else {
            // 默认headers，不设置Referer
            return $baseHeaders;
        }
    }
}
