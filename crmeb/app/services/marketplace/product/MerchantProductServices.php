<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\dao\marketplace\product\MerchantProductDao;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AccountChangeGuard;

/** Ordinary physical catalog operations; ctx is never retained in service state. */
final class MerchantProductServices
{
    public function __construct(private EmployeeAuthServices $authentication,private MerchantProductDao $products,private AuditLogServices $audit) {}
    private function authorize(MerchantContext $ctx,string $permission): void { $ctx->require($permission);$this->authentication->authorizeAction($ctx,$permission); }
    private function write(MerchantContext $ctx,string $permission,callable $operation): mixed
    {
        $this->authorize($ctx,$permission);
        return AccountChangeGuard::runMany('merchantapi',[],function()use($ctx,$permission,$operation):mixed{$this->authentication->authorizeAction($ctx,$permission,true);return MerchantManagementInput::write($operation);});
    }
    private function record(MerchantContext $ctx,int $id,string $action,array $fields): void
    {
        $this->audit->record('merchant',$ctx->employeeId,$ctx->merId,$action,'product',(string)$id,'success',['changed_fields'=>$fields,'employee_id'=>$ctx->employeeId]);
    }
    public function list(MerchantContext $ctx,array $filter,int $page,int $limit): array
    {
        $this->authorize($ctx,'product.read');$filter=MerchantProductInput::listing($filter,$page,$limit);$data=$this->products->listing($ctx,$filter,$page,$limit);$data['list']=array_map([self::class,'rootDto'],$data['list']);return $data;
    }
    private static function rootDto(array $root): array
    {
        $data=[];foreach(MerchantProductDao::ROOT_FIELDS as$key)if(!in_array($key,['is_virtual','virtual_type'],true))$data[$key]=$root[$key];
        $data['slider_image']=json_decode($root['slider_image'],true,32,JSON_THROW_ON_ERROR);$data['cate_id']=$root['cate_id']===''?[]:array_map('intval',explode(',',$root['cate_id']));
        foreach(['id','mer_id','stock','spec_type','freight','temp_id','is_show','is_del','review_status']as$key)$data[$key]=(int)$data[$key];if($data['review_time']!==null)$data['review_time']=(int)$data['review_time'];
        foreach(['price','cost','ot_price','vip_price','postage']as$key)$data[$key]=MerchantProductInput::money($root[$key]);return $data;
    }
    public function detail(MerchantContext $ctx,int $productId): array
    {
        $this->authorize($ctx,'product.read');return $this->detailOwned($ctx,$productId);
    }
    private function detailOwned(MerchantContext $ctx,int $id): array
    {
        $root=$this->products->find($ctx,$id);$result=$this->products->currentResult($ctx,$id);$skus=$this->products->skus($ctx,$id);$active=[];$seen=[];$usedOptions=[];
        $names=array_column($result['attr'],'value');if(!$names||count(array_unique($names))!==count($names))throw new AuthUnavailableException();
        foreach($result['value']as$definition){if(!is_array($definition))throw new AuthUnavailableException();$detail=$definition['detail']??null;
            if(!is_array($detail)||count($detail)!==count($names)||array_diff(array_keys($detail),$names))throw new AuthUnavailableException();$ordered=[];foreach($names as$name){$ordered[$name]=MerchantProductInput::text($detail[$name],64,true);} $suk=implode(',',$ordered);
            // Persisted server identity wins; retained inactive rows may share an old combination.
            $hasId=array_key_exists('id',$definition);$hasUnique=is_string($definition['unique']??null)&&$definition['unique']!=='';
            if($hasId){
                $storedId=$definition['id'];if((!is_int($storedId)&&(!is_string($storedId)||preg_match('/\A[1-9][0-9]{0,9}\z/D',$storedId)!==1))||(int)$storedId<1||(int)$storedId>2147483647||!$hasUnique)throw new AuthUnavailableException();
                $matches=array_values(array_filter($skus,static fn(array$r):bool=>(string)$r['id']===(string)$storedId&&$r['unique']===$definition['unique']));
            }else{
                // Only legacy definitions lacking the complete persisted identity use unique-combination fallback.
                $matches=array_values(array_filter($skus,static fn(array$r):bool=>$r['suk']===$suk));
            }
            if(count($matches)!==1)throw new AuthUnavailableException();$row=$matches[0];$skuId=(int)$row['id'];if($row['suk']!==$suk||(isset($definition['suk'])&&$definition['suk']!==$suk))throw new AuthUnavailableException();
            if((isset($definition['id'])&&(string)$definition['id']!== (string)$skuId)||(isset($definition['unique'])&&$definition['unique']!==''&&$definition['unique']!==$row['unique'])||(isset($definition['product_id'])&&(string)$definition['product_id']!== (string)$id)||(isset($definition['type'])&&(string)$definition['type']!=='0')||isset($seen[$skuId]))throw new AuthUnavailableException();
            $seen[$skuId]=true;if((int)$row['is_show']!==1)continue;$entry=['id'=>$skuId,'product_id'=>$id,'type'=>0,'unique'=>$row['unique'],'suk'=>$row['suk'],'detail'=>$ordered,'attr_arr'=>array_values($ordered),'pic'=>$row['image'],'stock'=>(int)$row['stock'],'bar_code'=>$row['bar_code'],'bar_code_number'=>$row['bar_code_number'],'is_show'=>1,'is_default_select'=>(int)$row['is_default_select']];
            foreach(['price','cost','ot_price','vip_price','weight','volume']as$key)$entry[$key]=MerchantProductInput::money($row[$key]);$active[]=$entry;foreach($ordered as$name=>$value)$usedOptions[$name][$value]=true;
        }
        $items=[];foreach($result['attr']as$item){$entries=[];foreach($item['detail']??[]as$option){$value=is_string($option)?$option:($option['value']??null);if(!is_string($value))throw new AuthUnavailableException();if(isset($usedOptions[$item['value']][$value]))$entries[]=['value'=>$value,'pic'=>is_array($option)?($option['pic']??''):''];}$items[]=['value'=>$item['value'],'detail'=>$entries];}
        if(!$active)throw new AuthUnavailableException();return ['product'=>self::rootDto($root),'items'=>$items,'attrs'=>$active,'description'=>$this->products->description($ctx,$id)];
    }
    public function save(MerchantContext $ctx,int $productId,array $data): int
    {
        $this->authorize($ctx,'product.write');MerchantProductInput::integer($productId,0,MerchantProductInput::ROOT_MAX);
        $protected=array_values(array_intersect(MerchantProductInput::PROTECTED,array_keys($data)));
        if($protected){$this->write($ctx,'product.write',function()use($ctx,$productId,$protected):void{$this->audit->record('merchant',$ctx->employeeId,$ctx->merId,'product.input.denied','product',$productId===0?'new':(string)$productId,'denied',['changed_fields'=>$protected]);});MerchantProductInput::invalid();}
        $input=MerchantProductInput::product($data);
        return $this->write($ctx,'product.write',function()use($ctx,$productId,$input):int{
            $before=$productId?$this->products->find($ctx,$productId,true):null;if($before!==null&&(int)$before['is_del']!==0)MerchantProductInput::invalid();
            $old=$productId?$this->products->skus($ctx,$productId,true):[];$stock=$this->products->physicalStock($old);if($before!==null&&$stock!==(int)$before['stock'])throw new AuthUnavailableException();
            $definitions=MerchantProductInput::definitions($input,$this->products->preset($ctx,$input['rule_id']));$categoryParents=$this->products->references($ctx,$input,$definitions);
            $root=$input['root'];$values=array_column($definitions['skus'],'row');$default=array_values(array_filter($values,static fn(array$r):bool=>$r['is_default_select']===1));
            foreach(['price','cost','ot_price']as$key)$root[$key]=$default?$default[0][$key]:MerchantProductInput::minimum(array_column($values,$key));$root['vip_price']=MerchantProductInput::minimum(array_column($values,'vip_price'));$root['stock']=$stock;$root['default_sku']=$default?$default[0]['suk']:'';
            if($productId===0){$id=$this->products->create($ctx,$root);$result=$this->products->saveSkus($ctx,$id,$definitions,[]);$this->products->replaceDefinition($ctx,$id,$result,$input,$categoryParents);$fields=array_merge(array_keys($root),['categories']);$action='product.create';}
            else{$id=$productId;$oldResult=$this->products->currentResult($ctx,$id,true);$oldDescription=$this->products->description($ctx,$id,true);
                // Predict existing identities before any SKU write; a truly repeated body is a no-op.
                $predicted=[];$allExisting=true;$used=[];$skuChanges=false;
                foreach($definitions['skus']as$spec){$selector=$spec['selector'];$matches=[];foreach($old as$r){if($selector['id']!==null?(int)$r['id']===$selector['id']:($selector['unique']!==''?$r['unique']===$selector['unique']:$r['suk']===$spec['row']['suk']))$matches[]=$r;}
                    if(count($matches)>1)throw new AuthUnavailableException();$r=$matches[0]??null;if($r===null){if($selector['id']!==null||$selector['unique']!==''||($selector['stock']!==null&&$selector['stock']!==0))MerchantProductInput::invalid();$allExisting=false;continue;}
                    if(isset($used[(int)$r['id']])||($selector['unique']!==''&&$selector['unique']!==$r['unique'])||($selector['stock']!==null&&$selector['stock']!==(int)$r['stock']))MerchantProductInput::invalid();$used[(int)$r['id']]=true;foreach($spec['row']as$key=>$value)if((string)$r[$key]!== (string)$value)$skuChanges=true;
                    $entry=$spec['row']+['id'=>(int)$r['id'],'product_id'=>$id,'type'=>0,'unique'=>$r['unique'],'detail'=>$spec['detail'],'attr_arr'=>array_values($spec['detail'])];$entry['pic']=$entry['image'];unset($entry['image']);$predicted[]=$entry;
                }
                $fields=[];foreach($root as$key=>$value)if((string)$before[$key]!== (string)$value)$fields[]=$key;
                $expectedCategories=[];foreach($categoryParents as$category=>$parent)$expectedCategories[]=['cate_id'=>$category,'cate_pid'=>$parent];
                $categoriesChanged=$this->products->currentCategoryBindings($ctx,$id)!==$expectedCategories;
                $definitionChanged=!$allExisting||$skuChanges||$oldResult!==['attr'=>$definitions['items'],'value'=>$predicted];$descriptionChanged=$oldDescription!==$input['description'];$same=!$definitionChanged&&!$descriptionChanged&&!$categoriesChanged&&$fields===[];
                foreach($old as$r)if(!isset($used[(int)$r['id']])&&((int)$r['is_show']!==0||(int)$r['is_default_select']!==0))$same=false;
                if($same)return $id;
                $result=$this->products->saveSkus($ctx,$id,$definitions,$old);$root+=['review_status'=>1,'review_reason'=>'','review_admin_id'=>null,'review_time'=>null,'is_show'=>0];$this->products->updateRoot($ctx,$id,$root);$this->products->replaceDefinition($ctx,$id,$result,$input,$categoryParents);if($definitionChanged)$fields[]='specification';if($descriptionChanged)$fields[]='description';if($categoriesChanged)$fields[]='categories';$fields=array_values(array_unique(array_merge($fields,['review_status','is_show'])));$action='product.update';
            }
            $after=$this->products->skus($ctx,$id,true);if($this->products->physicalStock($after)!==$stock)throw new AuthUnavailableException();
            foreach($old as$r){$match=array_values(array_filter($after,static fn(array$v):bool=>(int)$v['id']===(int)$r['id']));if(count($match)!==1)throw new AuthUnavailableException();foreach(['id','unique','product_id','type','stock']as$key)if((string)$match[0][$key]!== (string)$r[$key])throw new AuthUnavailableException();}
            $this->detailOwned($ctx,$id);$this->record($ctx,$id,$action,$fields);return $id;
        });
    }
    public function submitReview(MerchantContext $ctx,int $productId): void
    {
        $this->write($ctx,'product.submit_review',function()use($ctx,$productId):void{$row=$this->products->find($ctx,$productId,true);if((int)$row['is_del']!==0)MerchantProductInput::invalid();if((int)$row['review_status']===1)return;if(!in_array((int)$row['review_status'],[0,3],true))MerchantProductInput::invalid();$this->detailOwned($ctx,$productId);$this->products->updateRoot($ctx,$productId,['review_status'=>1,'review_reason'=>'','review_admin_id'=>null,'review_time'=>null,'is_show'=>0]);$this->products->categoryStatus($ctx,$productId,0);$this->record($ctx,$productId,'product.submit_review',['review_status']);});
    }
    public function setShow(MerchantContext $ctx,int $productId,bool $show): void { $this->batchSetShow($ctx,[$productId],$show); }
    public function batchSetShow(MerchantContext $ctx,array $productIds,bool $show): void
    {
        $ids=MerchantProductInput::ids($productIds);$this->write($ctx,'product.write',function()use($ctx,$ids,$show):void{
            $changed=[];foreach($ids as$id){$row=$this->products->find($ctx,$id,true);if((int)$row['is_del']!==0||($show&&(int)$row['review_status']!==2))MerchantProductInput::invalid();if((int)$row['is_show']!== (int)$show)$changed[]=$id;}
            if(!$changed)return;$this->products->batchShow($ctx,$ids,$show);foreach($changed as$id){$this->products->categoryStatus($ctx,$id,(int)$show);$this->record($ctx,$id,'product.show',['is_show']);}
        });
    }
    public function delete(MerchantContext $ctx,int $productId): void
    {
        $this->write($ctx,'product.write',function()use($ctx,$productId):void{$row=$this->products->find($ctx,$productId,true);if((int)$row['is_del']===1)return;$this->products->updateRoot($ctx,$productId,['is_del'=>1,'is_show'=>0]);$this->products->categoryStatus($ctx,$productId,0);$this->record($ctx,$productId,'product.delete',['is_del','is_show']);});
    }
}
