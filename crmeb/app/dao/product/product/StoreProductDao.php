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

namespace app\dao\product\product;


use app\dao\BaseDao;
use app\model\product\product\StoreProduct;
use think\facade\Config;
use think\facade\Log;

/**
 * Class StoreProductDao
 * @package app\dao\product\product
 */
class StoreProductDao extends BaseDao
{
    /** Preserve legacy return contracts while narrowing each managed root DML to id AND current owner. */
    public function update($id, array $data, ?string $key = null)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::active())return parent::update($id,$data,$key);
        $guard::transaction();$where=is_array($id)?$id:[is_null($key)?$this->getPk():$key=>$id];$last=null;
        foreach($guard::targetIds()as$productId){$owner=$guard::owner($productId);
            $query=$this->getModel()->where($where)->where('id',$productId)->where('mer_id',$owner);
            $guard::query($query);
            if($query->count()===0)continue;$patch=$this->managedPatch($productId,$data);$root=$guard::assertParent($productId);$effective=$this->effectivePatch($root,$patch);
            if($effective===[]){$last=$this->getModel()->where('id',$productId)->where('mer_id',$owner)->find();if(!$last instanceof \think\Model)throw new \crmeb\exceptions\AuthUnavailableException();continue;}
            $last=$this->getModel()::update($effective,['id'=>$productId,'mer_id'=>$owner]);
            $after=$guard::assertParent($productId);foreach($patch as$field=>$value){
                if($value===null?$after[$field]!==null:(string)$after[$field]!== (string)$value)throw new \crmeb\exceptions\AuthUnavailableException();}
        }
        return $last;
    }
    public function batchUpdate(array $ids,array $data,?string $key=null)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::active())return parent::batchUpdate($ids,$data,$key);
        if($key!==null&&$key!=='id')throw new \crmeb\exceptions\AuthUnavailableException();$count=0;
        foreach($guard::ids($ids)as$id){$owner=$guard::owner($id);$patch=$this->managedPatch($id,$data);
            $query=$this->getModel()->where('id',$id)->where('mer_id',$owner);$guard::transaction();
            $guard::query($query);
            $effective=$this->effectivePatch($guard::assertParent($id),$patch);$changed=$effective===[]?0:$query->update($effective);if(!is_int($changed)||$changed<0||$changed>1)throw new \crmeb\exceptions\AuthUnavailableException();$count+=$changed;
            $after=$guard::assertParent($id);foreach($patch as$field=>$value)if($value===null?$after[$field]!==null:(string)$after[$field]!== (string)$value)throw new \crmeb\exceptions\AuthUnavailableException();}
        return $count;
    }
    public function save(array $data)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::active())return parent::save($data);$guard::transaction();
        $guard::query($this->getModel()->db());$data=$guard::createFields($data);foreach(['price','vip_price','ot_price','postage','cost','gift_price']as$field)
            if(array_key_exists($field,$data))$data[$field]=$guard::money($data[$field]);
        $result=parent::save($data);if(!$result instanceof \think\Model)throw new \crmeb\exceptions\AuthUnavailableException();$guard::created($result);return $result;
    }
    public function saveAll(array $data)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::active())return parent::saveAll($data);$models=[];
        foreach($data as$row){if(!is_array($row))throw new \crmeb\exceptions\AuthUnavailableException();
            if(isset($row['id'])){$id=$guard::id($row['id']);unset($row['id']);$model=$this->update($id,$row);}
            else{$model=$this->save($row);}if(!$model instanceof \think\Model)throw new \crmeb\exceptions\AuthUnavailableException();$models[]=$model;}
        return $this->getModel()->toCollection($models);
    }
    public function delete($id,?string $key=null)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if(!$guard::active())return parent::delete($id,$key);$guard::transaction();
        $where=is_array($id)?$id:[is_null($key)?$this->getPk():$key=>$id];$count=0;
        foreach($guard::targetIds()as$productId){$owner=$guard::owner($productId);
            $query=$this->getModel()->where($where)->where('id',$productId)->where('mer_id',$owner);$guard::query($query);$n=$query->delete();
            if(!is_int($n)||$n<0||$n>1)throw new \crmeb\exceptions\AuthUnavailableException();$count+=$n;}
        return $count;
    }
    private function managedPatch(int $id,array $data): array
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;$root=$guard::assertParent($id);
        if(isset($data['id'])&&(string)$data['id']!== (string)$id)throw new \crmeb\exceptions\AuthUnavailableException();unset($data['id']);
        if(isset($data['mer_id'])&&(string)$data['mer_id']!== (string)$root['mer_id'])throw new \crmeb\exceptions\AuthUnavailableException();unset($data['mer_id']);
        if(array_key_exists('virtual_type',$data)&&(string)$data['virtual_type']!==(string)$root['virtual_type'])throw new \crmeb\exceptions\AdminException('商品类型不能切换');
        foreach(['price','vip_price','ot_price','postage','cost','gift_price']as$field)if(array_key_exists($field,$data))$data[$field]=$guard::money($data[$field]);
        foreach(['slider_image','custom_form','params_list']as$field)if(isset($data[$field])&&is_string($data[$field])&&array_key_exists($field,$root)){
            $new=json_decode($data[$field],true);$newValid=json_last_error()===JSON_ERROR_NONE;
            $old=is_string($root[$field])?json_decode($root[$field],true):null;$oldValid=is_string($root[$field])&&json_last_error()===JSON_ERROR_NONE;
            if($newValid&&(($oldValid&&$new===$old)||(in_array($field,['custom_form','params_list'],true)&&$new===[]&&($root[$field]===null||$root[$field]===''))))$data[$field]=$root[$field];
        }
        if($guard::preserveSkus($id)&&array_key_exists('stock',$data))$data['stock']=$root['stock'];
        if(isset($data['is_show'])&&(int)$data['is_show']===1
            &&((int)$root['review_status']!==2||(int)$root['is_del']!==0))throw new \crmeb\exceptions\AdminException('商品尚未审核通过');
        // The existing Model discards non-column presentation fields, rather than rejecting valid legacy body families.
        return array_intersect_key($data,$root);
    }
    private function effectivePatch(array $root,array $patch): array
    {
        $effective=[];foreach($patch as$field=>$value)if($value===null?$root[$field]!==null:($root[$field]===null||(string)$root[$field]!== (string)$value))$effective[$field]=$value;return $effective;
    }
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProduct::class;
    }

    /**
     * 条件获取数量
     * @param array $where
     * @return int
     */
    public function getCount(array $where)
    {
        return $this->search($where, false)->when(isset($where['sid']) && $where['sid'], function ($query) use ($where) {
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->where('cate_id', is_array($where['sid']) ? 'in' : '=', $where['sid'])->field('product_id')->select();
            });
        })->when(isset($where['cid']) && $where['cid'], function ($query) use ($where) {
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->whereIn('cate_id', function ($query) use ($where) {
                    $query->name('store_category')->where('pid', is_array($where['cid']) ? 'in' : '=', $where['cid'])
                        ->whereOr('id', is_array($where['cid']) ? 'in' : '=', $where['cid'])->field('id')->select();
                })->field('product_id')->select();
            });
        })->when(!empty($where['coupon_category_id']), function ($query) use ($where) {
            $where['coupon_category_id'] = toIntArray($where['coupon_category_id']);
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->whereIn('cate_id', function ($query) use ($where) {
                    $query->name('store_category')->whereIn('pid', $where['coupon_category_id'])->field('id')->select();
                })->whereOr('cate_id', 'in', $where['coupon_category_id'])->field('product_id')->select();
            });
        })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
            if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                $query->whereIn('id', $where['ids']);
            } else {
                $query->whereIn('id', $where['ids'])->orderField('id', $where['ids'], 'asc');
            }
        })->when(isset($where['is_live']) && $where['is_live'] == 1, function ($query) use ($where) {
            $query->whereNotIn('id', function ($query) {
                $query->name('live_goods')->where('is_del', 0)->where('audit_status', '<>', 3)->field('product_id')->select();
            });
        })->count();
    }

    /**
     * 获取商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $order
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '')
    {
        $prefix = Config::get('database.connections.' . Config::get('database.default') . '.prefix');
        return $this->search($where, false)->order(($order ? $order . ' ,' : '') . 'sort desc,id desc')
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->field([
                '*',
                '(SELECT count(*) FROM `' . $prefix . 'store_product_relation` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = \'collect\') as collect',
                '(SELECT count(*) FROM `' . $prefix . 'store_product_relation` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = \'like\') as likes',
                '(SELECT SUM(stock) FROM `' . $prefix . 'store_product_attr_value` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `type` = 0) as stock',
                '(SELECT count(*) FROM `' . $prefix . 'store_visit` WHERE `product_id` = `' . $prefix . 'store_product`.`id` AND `product_type` = \'product\') as visitor',
            ])->select()->toArray();
    }

    /**
     * 获取商品详情
     * @param int $id
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getInfo(int $id)
    {
        return $this->search()->with('coupons')->find($id);
    }

    /**
     * 条件获取商品列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param array|string[] $field
     * @param array|string[] $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSearchList(array $where, int $page = 0, int $limit = 0, array $field = ['*'], array $with = [])
    {
        if (isset($where['star'])) $with[] = 'star';
        return $this->search($where, false)->with($with)->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->when(isset($where['sid']) && $where['sid'], function ($query) use ($where) {
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->where('cate_id', is_array($where['sid']) ? 'in' : '=', $where['sid'])->field('product_id')->select();
            });
        })->when(isset($where['cid']) && $where['cid'], function ($query) use ($where) {
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->whereIn('cate_id', function ($query) use ($where) {
                    $query->name('store_category')->where('pid', is_array($where['cid']) ? 'in' : '=', $where['cid'])
                        ->whereOr('id', is_array($where['cid']) ? 'in' : '=', $where['cid'])->field('id')->select();
                })->field('product_id')->select();
            });
        })->when(!empty($where['coupon_category_id']), function ($query) use ($where) {
            $where['coupon_category_id'] = toIntArray($where['coupon_category_id']);
            $query->whereIn('id', function ($query) use ($where) {
                $query->name('store_product_cate')->whereIn('cate_id', function ($query) use ($where) {
                    $query->name('store_category')->whereIn('pid', $where['coupon_category_id'])->field('id')->select();
                })->whereOr('cate_id', 'in', $where['coupon_category_id'])->field('product_id')->select();
            });
        })->when(isset($where['ids']) && $where['ids'], function ($query) use ($where) {
            if ((isset($where['priceOrder']) && $where['priceOrder'] != '') || (isset($where['salesOrder']) && $where['salesOrder'] != '')) {
                $query->whereIn('id', $where['ids']);
            } else {
                $query->whereIn('id', $where['ids'])->orderField('id', $where['ids'], 'asc');
            }
        })->when(isset($where['is_live']) && $where['is_live'] == 1, function ($query) use ($where) {
            $query->whereNotIn('id', function ($query) {
                $query->name('live_goods')->where('is_del', 0)->where('audit_status', '<>', 3)->field('product_id')->select();
            });
        })->when(isset($where['priceOrder']) && $where['priceOrder'] != '', function ($query) use ($where) {
            if ($where['priceOrder'] === 'desc') {
                $query->order("price desc");
            } else {
                $query->order("price asc");
            }
        })->when(isset($where['newsOrder']) && $where['newsOrder'] != '', function ($query) use ($where) {
            if ($where['newsOrder'] === 'news') {
                $query->order("id desc");
            }
        })->when(isset($where['salesOrder']) && $where['salesOrder'] != '', function ($query) use ($where) {
            if ($where['salesOrder'] === 'desc') {
                $query->order("sales desc");
            } else {
                $query->order("sales asc");
            }
        })->when(!isset($where['ids']), function ($query) use ($where) {
            if (isset($where['timeOrder']) && $where['timeOrder'] == 1) {
                $query->order('id desc');
            } else if (isset($where['is_best']) && $where['is_best'] == 1) {
                $query->order('sales desc,sort desc');
            } else {
                $query->order('sort desc,id desc');
            }
        })->when(!$page && $limit, function ($query) use ($limit) {
            $query->limit($limit);
        })->when(isset($where['store_label_id']), function ($query) use ($where) {
            $query->where(function ($query) use ($where) {
                foreach ($where['store_label_id'] as $value) {
                    $query->whereOr('FIND_IN_SET(:value, label_list)', ['value' => $value]);
                }
            });
        })->order('sort desc')->field($field)->select()->toArray();
    }

    /**商品列表
     * @param array $where
     * @param $limit
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductLimit(array $where, $limit, $field)
    {
        return $this->search($where)->field($field)->order('val', 'desc')->limit($limit)->select()->toArray();

    }

    /**
     * 根据id获取商品数据
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function idByProductList(array $ids, string $field)
    {
        return $this->getModel()->whereIn('id', $ids)->field($field)->select()->toArray();
    }

    /**
     * 获取推荐商品
     * @param string $field
     * @param int $num
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRecommendProduct(array $where, string $field, int $num = 0, int $page = 0, int $limit = 0)
    {
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        return $this->search($where)->with(['couponId', 'star'])
            ->field(['id', 'image', 'store_name', 'store_info', 'cate_id', 'price', 'ot_price', 'IFNULL(sales,0) + IFNULL(ficti,0) as sales', 'unit_name', 'sort', 'activity', 'stock', 'vip_price', 'is_vip'])
            ->when(in_array($field, ['is_best', 'is_new', 'is_benefit', 'is_hot', 'is_vip']), function ($query) use ($field) {
                if ($field != 'is_vip') {
                    $query->where($field, 1);
                } else {
                    $query->where('is_vip', 1)->where('vip_price', '>', 0);
                }
            })
            ->when($num, function ($query) use ($num) {
                $query->limit($num);
            })
            ->when($page, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->when($limit, function ($query) use ($limit) {
                $query->limit($limit);
            })
            ->order(in_array($field, ['is_hot', 'is_best']) ? 'sales DESC,sort DESC, id desc' : 'sort DESC, id desc')->select()->toArray();

    }

    /**
     * 获取加入购物车的商品
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductCartList(array $where, int $page, int $limit, array $field = ['*'])
    {
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        return $this->search($where)->when($page, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->field($field)->order('sort DESC,id DESC')->select()->toArray();
    }

    /**
     * 获取用户购买热销榜单
     * @param array $where
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserProductHotSale(array $where, int $page = 0, int $limit = 0)
    {
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        $where['is_hot'] = 1;
        return $this->search($where)->field(['IFNULL(sales,0) + IFNULL(ficti,0) as sales', 'store_name', 'image', 'id', 'price', 'ot_price', 'stock'])
            ->when($page && $limit, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })
            ->when($limit, function ($query) use ($limit) {
                $query->limit($limit);
            })->order('sales desc')->select()->toArray();
    }

    /**
     * 通过商品id获取商品分类
     * @param array $productIds
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productIdByCateId(array $productIds)
    {
        return $this->search(['id' => $productIds])->with('cateName')->field('id')->select()->toArray();
    }

    /**
     * @param array $where
     * @param $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductListByWhere(array $where, $field)
    {
        return $this->search($where)->field($field)->select()->toArray();
    }

    /**
     * 获取预售列表
     * @param $where
     * @param $page
     * @param $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAdvanceList($where, $page, $limit)
    {
        $model = $this->getModel()->where('presale', 1)->where('is_del', 0)->where('is_show', 1)->where(function ($query) use ($where) {
            switch ($where['time_type']) {
                case 1:
                    $query->where('presale_start_time', '>', time());
                    break;
                case 2:
                    $query->where('presale_start_time', '<=', time())->where('presale_end_time', '>=', time());
                    break;
                case 3:
                    $query->where('presale_end_time', '<', time());
                    break;
            }
        });
        $count = $model->count();
        $list = $model->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('add_time desc')->select()->toArray();
        return compact('list', 'count');
    }

    /**
     * 预售商品自动到期下架
     */
    public function downAdvance()
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;$self=$guard::selfId();$now=time();
        $ids=$this->getModel()->where('mer_id',$self)->where('presale',1)->where('presale_end_time','<',$now)->column('id');
        return app()->make($guard)->selfWorker($guard::ids($ids),function()use($ids,$now,$guard):void{
            foreach($ids as$id){$id=$guard::id($id);$root=$guard::assertParent($id);
                if((int)$root['presale']!==1||(int)$root['presale_end_time']>=$now)continue;
                $this->update($id,['is_show'=>0]);
                $query=\think\facade\Db::name('store_product_cate')->where('product_id',$id);$guard::query($query);$query->update(['status'=>0]);
                app()->make(\app\services\order\StoreCartServices::class)->changeStatus($id,0);
            }
        });
    }

    /**
     * 自定义组件-商品
     * @param $where
     * @param $order
     * @param $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2026/1/13
     */
    public function getThemeProduct($where, $order, $limit)
    {
        $model = $this->getModel()->with('cateName')->where('is_del', 0)->where('is_show', 1);
        if ($where['ids'] != '') {
            $ids = explode(',', $where['ids']);
            $list = $model->whereIn('id', $ids)->order($order)->limit($limit)->select()->toArray();
        } else {
            $list = $model->when($where['cate_ids'] != '', function ($query) use ($where) {
                $cate_ids = explode(',', $where['cate_ids']);
                $query->whereIn('id', function ($query) use ($cate_ids) {
                    $query->name('store_product_cate')->where('cate_id', 'in', $cate_ids)->whereOr('cate_pid', 'in', $cate_ids)->field('product_id')->select();
                });
            })->order($order)->limit($limit)->select()->toArray();
        }
        return $list;
    }
}
