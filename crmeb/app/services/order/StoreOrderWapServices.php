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

namespace app\services\order;


use app\dao\order\StoreOrderDao;
use app\services\BaseServices;
use app\services\marketplace\order\MarketplaceOrderSnapshotProjection as SavedOrder;
use app\services\marketplace\order\MarketplaceTradePolicy;

/**
 * Class StoreOrderWapServices
 * @package app\services\order
 * @method getOne(array $where, ?string $field = '*', ?array $with = []) 获取一条数据
 */
class StoreOrderWapServices extends BaseServices
{
    /**
     * StoreOrderWapServices constructor.
     * @param StoreOrderDao $dao
     */
    public function __construct(StoreOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取 今日 昨日 本月 订单金额
     * @return mixed
     */
    public function getOrderTimeData()
    {
        $where = ['timeKey' => 'add_time', 'paid' => 1, 'refund_status' => 0, 'pid' => 0];
        //今日成交额
        $data['todayPrice'] = $this->dao->commercialTogether($where + ['time' => 'today'], 'pay_price');
        //今日订单数
        $data['todayCount'] = $this->dao->commercialCount($where + ['time' => 'today']);
        //昨日成交额
        $data['proPrice'] = $this->dao->commercialTogether($where + ['time' => 'yesterday'], 'pay_price');
        //昨日订单数
        $data['proCount'] = $this->dao->commercialCount($where + ['time' => 'yesterday']);
        //本月成交额
        $data['monthPrice'] = $this->dao->commercialTogether($where + ['time' => 'month'], 'pay_price');
        //本月订单数
        $data['monthCount'] = $this->dao->commercialCount($where + ['time' => 'month']);
        return $data;
    }
    

    /**
     * 订单每月统计数据
     * @param array $where
     * @param int $store_id
     * @return array
     */
    public function getOrderDataPriceCount(array $where = [], int $store_id = 0)
    {
        [$page, $limit] = $this->getPageValue();
        return $this->dao->getOrderDataPriceCount($where + ['pid' => 0, 'is_del' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_system_del' => 0, 'store_id' => $store_id], ['sum(pay_price) as price', 'count(id) as count', 'FROM_UNIXTIME(add_time, \'%m-%d\') as time'], $page, $limit);
    }

    /**
     * 获取手机端订单管理
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getWapAdminOrderList(array $where, string $marketplaceScope = '', int $viewerUid = 0)
    {
        $viewer=SavedOrder::listViewer($marketplaceScope,$viewerUid);
        if($marketplaceScope==='mobile'){
            [$page,$limit]=$this->getPageValue();
            return app()->make(StoreOrderServices::class)->readMobileOrderPage($viewerUid,$where,$page,$limit);
        }
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getOrderList($where, ['*'], $page, $limit, ['pink'], 'add_time DESC,id DESC', $viewer);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $list = $orderServices->tidyOrderList($list);
        foreach ($list as &$item) {
            if($marketplaceScope!=='' && !array_key_exists('marketplace_trade_version',$item))throw new \crmeb\exceptions\AuthUnavailableException();
            $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
            $cart_num = 0;
            foreach ($item['_info'] as $items) {
                $cart_num += $items['cart_info']['cart_num'];
            }
            $item['is_all_refund'] = $refund_num == $cart_num ? true : false;
            if($marketplaceScope!=='' && !MarketplaceTradePolicy::savedOrderIsLegacy($item)){
                if($viewer!==null && (string)$item['uid']!==(string)$viewer)throw new \crmeb\exceptions\AuthUnavailableException();
                $item=SavedOrder::orderListRow($item,$marketplaceScope);
            }
        }
        return $list;
    }

}
