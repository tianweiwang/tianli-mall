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

namespace app\services\activity\live;


use app\dao\activity\live\LiveRoomGoodsDao;
use app\services\BaseServices;

/**
 * Class LiveRoomGoodsServices
 * @package app\services\activity\live
 */
class LiveRoomGoodsServices extends BaseServices
{
    public function __construct(LiveRoomGoodsDao $dao)
    {
        $this->dao = $dao;
    }
}
