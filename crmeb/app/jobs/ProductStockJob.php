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

namespace app\jobs;

use app\services\product\product\OutStoreProductServices;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;
use think\facade\Log;
use app\services\marketplace\product\LegacyProductWriteServices;
use crmeb\exceptions\AuthUnavailableException;

class ProductStockJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 拆分计算
     * @param array $data
     * @return bool
     */
    public function distribute(array $data): bool
    {
        try {
            foreach ($data as $key => $item) {
                $id=LegacyProductWriteServices::id($key);
                app()->make(LegacyProductWriteServices::class)->selfWorker([$id],static function()use($id):bool{
                    LegacyProductWriteServices::afterCommit(static fn()=>ProductStockJob::dispatch('calcValueStock',[$id]));return true;
                });
            }
        } catch (\Throwable $e) {
            self::unavailable('distribute');
        }
        return true;
    }

    /**
     * 计算库存
     * @param int $id
     * @return bool
     */
    public function calcValueStock(int $id): bool
    {
        try {
            /** @var OutStoreProductServices $services */
            $services = app()->make(OutStoreProductServices::class);
            $services->calcStockByAttrValue($id);
        } catch (\Throwable $e) {
            self::unavailable('calculate');
        }
        return true;
    }
    private static function unavailable(string $phase): never
    {
        $record='{"event":"legacy_product_stock_unavailable","phase":"'.$phase.'"}';
        try{Log::error($record);}catch(\Throwable $ignored){error_log($record);}
        throw new AuthUnavailableException();
    }
}
