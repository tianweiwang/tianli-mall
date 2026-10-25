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

namespace app\services\product\product;

use app\dao\product\product\StoreProductDao;
use app\jobs\ProductStockJob;
use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use crmeb\exceptions\AdminException;

/**
 * Class OutStoreProductServices
 * @package app\services\product\product
 * @method getOne(array $where) 获取一条数据
 * @method update(int $id, array $data) 获取一条数据
 * @method delete($id, ?string $key = null) 删除数据
 * @method value($where, ?string $field = null) 获取字段
 * @method incStockDecSales(array $where, int $num, string $stock = 'stock', string $sales = 'sales') 加库存减销量
 * @method count(array $where) 获取指定条件下的数量
 * @method getColumn(array $where, string $field, string $key = '') 获取某个字段数组
 * @method getSearchList(array $where, int $page = 0, int $limit = 0, ?array $field = ['*']) 获取列表
 * @method get(int $id, array $field) 获取一条数据
 * @method getCid(int $page, int $limit) 获取一级分类ID
 * @method downAdvance() 预售商品自动到期下架
 */
class OutStoreProductServices extends BaseServices
{
    protected $productType = ['普通商品', '卡密商品', '优惠券', '虚拟商品'];

    public function __construct(StoreProductDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 新增编辑商品
     * @param int $id
     * @param array $data
     * @param int $validate
     */
    public function save(int $id, array $data)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        $guard::assertRawBody($data);
        return app()->make($guard)->run($id?[$id]:[],function()use($id,$data,$guard){
            $references=$data;$references['freight']=2;$references['temp_id']=0;$references['image']=$data['slider_image'][0]??'';
            $guard::saveReferences($id,$references);return $this->legacySave($id,$data);
        },$id===0,true);
    }
    private function legacySave(int $id, array $data)
    {
        if (count($data['cate_id']) < 1) throw new AdminException('请选择商品分类');
        if (!$data['store_name']) throw new AdminException('请输入商品名称');

        if (count($data['slider_image']) < 1) {
            $data['is_show'] = 0;
        }

        $data['brand_id'] = 0;
        $data['logistics'] = 1; // 物流方式

        $detail = $data['attrs'];
        $attr = $data['items'];
        $cate_id = $data['cate_id'];
        $description = $data['description'];

        $data['is_limit'] = intval($data['is_limit']);
        if (!$data['is_limit']) {
            $data['limit_type'] = 0;
            $data['limit_num'] = 0;
        } else {
            if (!in_array($data['limit_type'], [1, 2])) throw new AdminException('请选择限购类型');
            if ($data['limit_num'] <= 0) throw new AdminException('限购数量不能小于1');
        }

        // 固定邮费 0为包邮
        $data['freight'] = 2;
        $data['postage'] = \app\services\marketplace\product\LegacyProductWriteServices::money($data['postage']);
        if (bccomp($data['postage'], '0.00', 2) < 0) {
            throw new AdminException('运费格式错误');
        }

        if (count($data['activity']) == 4) {
            foreach ($data['activity'] as $k => $v) {
                if ($v == '秒杀') {
                    $data['activity'][$k] = 1;
                } elseif ($v == '砍价') {
                    $data['activity'][$k] = 2;
                } elseif ($v == '拼团') {
                    $data['activity'][$k] = 3;
                } else {
                    $data['activity'][$k] = 0;
                }
            }
            $data['activity'] = implode(',', $data['activity']);
        } else {
            $data['activity'] = '0,1,2,3';
        }

        $data['is_hot'] = $data['is_benefit'] = $data['is_new'] = $data['is_good'] = $data['is_best'] = 0;
        foreach ($data['recommend'] as $item) {
            if (isset($data[$item])) {
                $data[$item] = 1;
            }
        }

        $data['is_vip'] = intval(in_array($data['is_vip'], [0, 1]) ? $data['is_vip'] : 0);
        $data['is_sub'] = intval(in_array($data['is_sub'], [0, 1]) ? $data['is_sub'] : 0);
        $data['vip_product'] = intval($data['vip_product']);

        $data['presale'] = intval($data['presale']);
        $data['presale_start_time'] = $data['presale'] ? strtotime($data['presale_time'][0]) : 0;
        $data['presale_end_time'] = $data['presale'] ? strtotime($data['presale_time'][1]) : 0;

        foreach ($detail as &$item) {
            if (isset($item['stock'])) {
                unset($item['stock']);
            }

            if (empty($item['pic'])) {
                $data['is_show'] = 0;
            }

            $item['cost'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['cost'] ?? '0.00');
            $item['price'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['price'] ?? '0.00');
            $item['ot_price'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['ot_price'] ?? '0.00');
            $item['weight'] = (string)$item['weight'];
            $item['volume'] = (string)$item['volume'];
            $item['pic'] = $item['pic'] ?? '';

            if ($data['is_vip'] == 0) {
                $item['vip_price'] = '0.00';
            } else {
                $item['vip_price'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['vip_price'] ?? '0.00');
            }

            if ($data['is_sub'] == 0) {
                $item['brokerage'] = '0.00';
                $item['brokerage_two'] = '0.00';
            } else {
                $item['brokerage'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['brokerage'] ?? '0.00');
                $item['brokerage_two'] = \app\services\marketplace\product\LegacyProductWriteServices::money($item['brokerage'] ?? '0.00');
                if (bccomp(bcadd($item['brokerage'], $item['brokerage_two']), $item['price']) == 1) {
                    throw new AdminException('一二级返佣相加不能大于商品售价');
                }
            }
        }

        $data['price'] = \app\services\marketplace\product\LegacyProductWriteServices::minimum(array_column($detail, 'price'));
        $data['ot_price'] = \app\services\marketplace\product\LegacyProductWriteServices::minimum(array_column($detail, 'ot_price'));
        $data['cost'] = \app\services\marketplace\product\LegacyProductWriteServices::minimum(array_column($detail, 'cost'));
        $data['cate_id'] = implode(',', $data['cate_id']);
        $data['image'] = $data['slider_image'][0] ?? '';
        $data['slider_image'] = json_encode($data['slider_image']);
        $data['give_integral'] = (int)$data['give_integral'];
        unset($data['description'], $data['coupon_ids'], $data['items'], $data['attrs']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductCateServices $storeProductCateServices */
        $storeProductCateServices = app()->make(StoreProductCateServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        /** @var StoreCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreCategoryServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        return $this->transaction(function () use ($id, $data, $description, $cate_id, $storeDescriptionServices, $storeProductCateServices, $storeProductAttrServices, $storeCategoryServices, $detail, $attr, $productServices) {
            if ($data['spec_type'] == 0) {
                $attr = [
                    [
                        'value' => '规格',
                        'detailValue' => '',
                        'attrHidden' => '',
                        'detail' => ['默认']
                    ]
                ];
                $detail[0]['value1'] = '规格';
                $detail[0]['detail'] = ['规格' => '默认'];
            }
            if ($id) {
                $this->dao->update($id, $data);
                $storeDescriptionServices->saveDescription($id, $description);
                $cateData = [];
                $time = time();
                $currentParents = \app\services\marketplace\product\LegacyProductWriteServices::categories($cate_id);
                foreach ($cate_id as $cid) {
                    if ($cid && isset($currentParents[$cid])) {
                        $cateData[] = ['product_id' => $id, 'cate_id' => $cid, 'cate_pid' => $currentParents[$cid], 'status' => $data['is_show'], 'add_time' => $time];
                    }
                }
                $storeProductCateServices->change($id, $cateData);
                $skuList = $productServices->validateProductAttr($attr, $detail, $id, 0, 0);
                $attrRes = $storeProductAttrServices->saveProductAttr($skuList, $id, 0, 0, 0);
                if (!$attrRes) throw new AdminException('添加失败');
            } else {
                $data['add_time'] = time();
                $data['code_path'] = '';
                $data['spu'] = $productServices->createSpu();
                $res = $this->dao->save($data);
                $storeDescriptionServices->saveDescription($res->id, $description);
                $cateData = [];
                $time = time();
                $currentParents = \app\services\marketplace\product\LegacyProductWriteServices::categories($cate_id);
                foreach ($cate_id as $cid) {
                    if ($cid && isset($currentParents[$cid])) {
                        $cateData[] = ['product_id' => $res->id, 'cate_id' => $cid, 'cate_pid' => $currentParents[$cid], 'status' => $data['is_show'], 'add_time' => $time];
                    }
                }
                $storeProductCateServices->change($res->id, $cateData);
                $skuList = $productServices->validateProductAttr($attr, $detail, $res->id, 0, 0, true);
                $attrRes = $storeProductAttrServices->saveProductAttr($skuList, $res->id, 0, 0, 0);
                if (!$attrRes) throw new AdminException('添加失败');
                $id = (int)$res->id;
            }
            return $id;
        });
    }

    /**
     * 设置商品上下架
     * @param int $id
     * @param int $is_show
     */
    public function setShow(int $id, int $is_show)
    {
        return app()->make(\app\services\marketplace\product\LegacyProductWriteServices::class)->run([$id],fn()=>$this->legacySetShow($id,$is_show));
    }
    private function legacySetShow(int $id, int $is_show)
    {
        if (empty($id)) throw new AdminException('参数错误');

        if ($is_show) {
            // 检查商品是否可以上架
            $this->checkShelves($id);
        }

        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        $cartService->changeStatus($id, $is_show);
        $this->dao->update($id, ['is_show' => $is_show]);

        /** @var StoreProductCateServices $storeProductCateServices */
        $storeProductCateServices = app()->make(StoreProductCateServices::class);
        $storeProductCateServices->update($id, ['status' => $is_show], 'product_id');
        return true;
    }

    public function checkActivity(int $id, bool $return=false)
    { return app()->make(StoreProductServices::class)->checkActivity($id,$return); }
    public function del(int $id)
    { return app()->make(StoreProductServices::class)->del($id); }

    /**
     * 获取商品详情
     * @param int $id
     * @return array|\think\Model|null
     */
    public function getInfo(int $id)
    {
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
        $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $productInfo = $this->dao->get($id, ['id', 'image', 'slider_image', 'store_name', 'store_info', 'keyword', 'bar_code',
            'cate_id', 'price', 'ot_price', 'postage', 'unit_name', 'sort', 'is_show', 'cost', 'spec_type', 'spu', 'freight',
            'is_vip', 'vip_price', 'is_limit', 'limit_type', 'limit_num', 'give_integral']);
        if ($productInfo) {
            $productInfo = $productInfo->toArray();
        } else {
            throw new AdminException('商品不存在');
        }

        $productInfo['cate_id'] = $productInfo['cate_id'] ? array_map('intval', explode(',', $productInfo['cate_id'])) : [];
        $productInfo['cate_name'] = '';
        if ($productInfo['cate_id']) {
            /** @var StoreCategoryServices $storeCategoryServices */
            $storeCategoryServices = app()->make(StoreCategoryServices::class);
            $cateList = $storeCategoryServices->getCateArray(implode(',', $productInfo['cate_id']));
            $productInfo['cate_name'] = implode(',', array_column($cateList, 'cate_name'));
        }

        $productInfo['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 0]);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $temporaryDefault=null;$temporaryDefinition=null;
        // Missing definition is rendered locally; a GET never creates persistent SKU rows.
        if (!$storeProductAttrResultServices->getResult(['product_id' => $id, 'type' => 0])) {
            $attr = [
                [
                    'value' => '规格',
                    'detailValue' => '',
                    'attrHidden' => '',
                    'detail' => ['默认']
                ]
            ];
            $detail[0] = [
                'value1' => '默认',
                'detail' => ['规格' => '默认'],
                'pic' => $productInfo['image'],
                'price' => $productInfo['price'],
                'cost' => $productInfo['cost'],
                'ot_price' => $productInfo['ot_price'],
                'stock' => $productInfo['stock'] ?? 0,
                'bar_code' => '',
                'bar_code_number' => '',
                'weight' => '0.00',
                'volume' => '0.00'
            ];
            $temporaryDefault=$detail[0]+['image'=>$productInfo['image'],'brokerage'=>'0.00','brokerage_two'=>'0.00','vip_price'=>'0.00'];
            $currentRows=\think\facade\Db::name('store_product_attr_value')->where('product_id',$id)->where('type',0)->order('id','asc')->select()->toArray();
            if(count($currentRows)===1)$temporaryDefault=$currentRows[0]+$temporaryDefault;
            $productInfo['spec_type']=0;
            if(count($currentRows)>1){
                $temporaryDefinition=['attr'=>[['value'=>'规格','detail'=>array_values(array_unique(array_column($currentRows,'suk')))]],'value'=>[]];
                foreach($currentRows as$sku){$entry=$sku;$entry['detail']=['规格'=>$sku['suk']];$entry['attr_arr']=[$sku['suk']];$entry['pic']=$sku['image'];$temporaryDefinition['value'][]=$entry;}
                $productInfo['spec_type']=1;
            }
        }
        if ($productInfo['spec_type'] == 1) {
            $result = $temporaryDefinition ?? $storeProductAttrResultServices->getResult(['product_id' => $id, 'type' => 0]);
            foreach ($result['value'] as $k => $v) {
                $num = 1;
                foreach ($v['detail'] as $dv) {
                    $result['value'][$k]['value' . $num] = $dv;
                    $num++;
                }
                $result['value'][$k]['price'] = sprintf("%.2f", $v['price'] ?? '0.00');
                $result['value'][$k]['cost'] = sprintf("%.2f", $v['cost'] ?? '0.00');
                $result['value'][$k]['ot_price'] = sprintf("%.2f", $v['ot_price'] ?? '0.00');
                $result['value'][$k]['vip_price'] = sprintf("%.2f", $v['vip_price'] ?? '0.00');
                $result['value'][$k]['weight'] = sprintf("%.2f", $v['weight'] ?? '0.00');
                $result['value'][$k]['volume'] = sprintf("%.2f", $v['volume'] ?? '0.00');
                $result['value'][$k]['brokerage'] = sprintf("%.2f", $v['brokerage'] ?? '0.00');
                $result['value'][$k]['brokerage_two'] = sprintf("%.2f", $v['brokerage_two'] ?? '0.00');
                $result['value'][$k]['vip_price'] = sprintf("%.2f", $v['vip_price'] ?? '0.00');

                unset($result['value'][$k]['is_virtual'], $result['value'][$k]['stock']);
            }
            $productInfo['items'] = $result['attr'];
            $productInfo['attrs'] = $result['value'];
        } else {
            $result = $temporaryDefault ?? $storeProductAttrValueServices->getOne(['product_id' => $id, 'type' => 0]);
            $productInfo['items'] = [];
            $productInfo['attrs'] = [];
            $productInfo['attrs'][] = [
                'pic' => $result['image'] ?? '',
                'price' => $result['price'] ?? '0.00',
                'cost' => $result['cost'] ?? '0.00',
                'ot_price' => $result['ot_price'] ?? '0.00',
                'bar_code' => $result['bar_code'] ?? '',
                'bar_code_number' => $result['bar_code_number'] ?? '',
                'weight' => $result['weight'] ?? '0.00',
                'volume' => $result['volume'] ?? '0.00',
                'brokerage' => $result['brokerage'] ?? '0.00',
                'brokerage_two' => $result['brokerage_two'] ?? '0.00',
                'vip_price' => $result['vip_price'] ?? '0.00',
            ];
        }
        return $productInfo;
    }

    /**
     * 获取选择的商品列表
     * @param array $where
     * @return array
     */
    public function searchList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $field = ['id', 'image', 'slider_image', 'store_name', 'store_info', 'keyword', 'bar_code', 'cate_id', 'price',
            'is_vip', 'vip_price', 'ot_price', 'postage', 'unit_name', 'sort', 'is_show', 'cost', 'spec_type', 'spu', 'freight'];
        $list = $this->dao->getSearchList($where, $page, $limit, $field, ['description']);

        $cateIds = implode(',', array_column($list, 'cate_id'));
        /** @var StoreCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreCategoryServices::class);
        $cateList = $storeCategoryServices->getCateArray($cateIds);

        foreach ($list as &$item) {
            $item['cate_id'] = $item['cate_id'] ? array_map('intval', explode(',', $item['cate_id'])) : [];
            $cateName = array_filter($cateList, function ($val) use ($item) {
                if (in_array($val['id'], $item['cate_id'])) {
                    return $val;
                }
            });

            $item['cate_name'] = implode(',', array_column($cateName, 'cate_name'));
        }
        $count = $this->dao->getCount($where);
        return compact('count', 'list');
    }

    /**
     * 上架检测
     * @param int $id
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function checkShelves(int $id)
    {
        $productInfo = $this->dao->get($id, ['id', 'image', 'slider_image', 'spec_type', 'unit_name', 'cate_id'], []);
        if ($productInfo) {
            $productInfo = $productInfo->toArray();
        } else {
            throw new AdminException('商品不存在');
        }

        if (!$productInfo['image'] || !$productInfo['slider_image']) {
            throw new AdminException('请选择商品轮播图');
        }

        if (!$productInfo['unit_name']) {
            throw new AdminException('请填写单位');
        }

        if (!$productInfo['cate_id']) {
            throw new AdminException('请选择商品分类');
        }

        if ($productInfo['spec_type'] == 1) {
            /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
            $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
            $result = $storeProductAttrResultServices->getResult(['product_id' => $id, 'type' => 0]);
            foreach ($result['value'] as $v) {
                foreach ($v['detail'] as $dv) {
                    if (!$dv['pic']) {
                        throw new AdminException('请上传商品图片');
                    }
                }
            }
        } else {
            /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
            $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            $result = $storeProductAttrValueServices->getOne(['product_id' => $id, 'type' => 0]);
            if (!$result['image']) {
                throw new AdminException('请上传商品图片');
            }
        }
    }

    /**
     * 同步库存
     * @param array $items
     * @return void
     */
    public function syncStock(array $items)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        $self=$guard::selfId();$barcodes=[];
        foreach($items as$item){if(!is_string($item['bar_code']??null))throw new AdminException('请求参数不正确');$barcodes[]=$item['bar_code'];}
        $possible=\think\facade\Db::name('store_product_attr_value')->where('type',0)->whereIn('bar_code',$barcodes)->column('product_id');
        $ids=\think\facade\Db::name('store_product')->where('mer_id',$self)->whereIn('id',$possible)->column('id');$ids=$guard::ids($ids);
        return app()->make($guard)->run($ids,function()use($items,$ids,$guard){
            $goods=[];
            foreach($items as$item){
                $query=\think\facade\Db::name('store_product_attr_value')->where('type',0)->whereIn('product_id',$ids)->where('bar_code',$item['bar_code'])->lock(true);
                $guard::query($query);$rows=$query->select()->toArray();if($rows===[])continue;
                if(count($rows)!==1)throw new AdminException('商品条码不唯一');$sku=$rows[0];$id=$guard::id($sku['product_id']);$guard::assertParent($id);
                $quantity=$item['qty']??null;if(is_string($quantity)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$quantity)===1)$quantity=(int)$quantity;
                if(!is_int($quantity)||$quantity<0||$quantity>4294967295)throw new AdminException('库存数量不正确');
                if($guard::preserveSkus($id)&&(string)$sku['stock']!==(string)$quantity)throw new AdminException('商品已有持久库存流水，请使用独立库存入口');
                $where=\think\facade\Db::name('store_product_attr_value')->where('id',$sku['id'])->where('product_id',$id)->where('type',0)->where('unique',$sku['unique']);
                $guard::query($where);$changed=$where->update(['stock'=>$quantity]);if(!is_int($changed)||$changed<0||$changed>1||(string)$where->value('stock')!==(string)$quantity)throw new \crmeb\exceptions\AuthUnavailableException();
                $goods[$id]=1;
            }
            // Root/result are reconciled before this transaction commits, rather than waiting for a queue.
            foreach(array_keys($goods)as$id)$this->reconcileStock((int)$id);
            if($goods)$guard::afterCommit(static fn()=>ProductStockJob::dispatch('distribute',[$goods]));
            return true;
        });
    }

    /** The actual queue owns a self-only transaction, with no fabricated admin/employee identity. */
    public function calcStockByAttrValue(int $id)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if($guard::active())return $this->reconcileStock($id);
        return app()->make($guard)->selfWorker([$id],fn()=>$this->reconcileStock($id));
    }
    private function reconcileStock(int $id): bool
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;$guard::assertParent($id);
        $skuQuery=\think\facade\Db::name('store_product_attr_value')->where('product_id',$id)->where('type',0)->order('id','asc')->lock(true);
        $guard::query($skuQuery);$skus=$skuQuery->select()->toArray();$stock=0;$byUnique=[];
        foreach($skus as$sku){$number=$sku['stock'];if(is_string($number)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$number)===1)$number=(int)$number;
            if(!is_int($number)||$number<0||$number>4294967295)throw new \crmeb\exceptions\AuthUnavailableException();$stock+=$number;
            if($stock>16777215||isset($byUnique[$sku['unique']]))throw new \crmeb\exceptions\AuthUnavailableException();$byUnique[$sku['unique']]=$number;}
        $this->dao->update($id,['stock'=>$stock]);if((string)$guard::assertParent($id)['stock']!==(string)$stock)throw new \crmeb\exceptions\AuthUnavailableException();
        $resultQuery=\think\facade\Db::name('store_product_attr_result')->where('product_id',$id)->where('type',0)->lock(true);$guard::query($resultQuery);
        $rows=$resultQuery->select()->toArray();if($rows===[])return true;if(count($rows)!==1)throw new \crmeb\exceptions\AuthUnavailableException();
        $result=json_decode($rows[0]['result'],true,512,JSON_THROW_ON_ERROR);if(!is_array($result)||!is_array($result['value']??null))throw new \crmeb\exceptions\AuthUnavailableException();
        foreach($result['value']as$key=>$value){$unique=$value['unique']??null;if(!is_string($unique)||!array_key_exists($unique,$byUnique))throw new \crmeb\exceptions\AuthUnavailableException();$result['value'][$key]['stock']=$byUnique[$unique];}
        $resultQuery->where('id',$rows[0]['id'])->update(['result'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        if(json_decode($resultQuery->value('result'),true,512,JSON_THROW_ON_ERROR)!==$result)throw new \crmeb\exceptions\AuthUnavailableException();return true;
    }
}
