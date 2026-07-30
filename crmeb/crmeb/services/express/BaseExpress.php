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

namespace crmeb\services\express;

use crmeb\basic\BaseStorage;
use crmeb\services\AccessTokenServeService;

/**
 * 物流查询
 * Class BaseExpress
 * @package crmeb\basic
 */
abstract class BaseExpress extends BaseStorage
{

    /**
     * access_token
     * @var null
     */
    protected $accessToken = NULL;


    public function __construct(string $name, AccessTokenServeService $accessTokenServeService, string $configFile)
    {
        parent::__construct($name, [], $configFile);
        $this->accessToken = $accessTokenServeService;
    }

    /**
     * 初始化
     * @param array $config
     * @return mixed|void
     */
    protected function initialize(array $config = [])
    {
//        parent::initialize($config);
    }


    /**
     * 开通服务
     * @return mixed
     */
    abstract public function open();

    /**物流追踪
     * @return mixed
     */
    abstract public function query(string $num, string $com = '');

    /**电子面单
     * @return mixed
     */
    abstract public function dump($data);

    /**快递公司
     * @return mixed
     */
    //abstract public function express($type, $page, $limit);

    /**面单模板
     * @return mixed
     */
    abstract public function temp(string $com);
}
