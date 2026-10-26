<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80300) {
    exit('您的PHP版本不受支持，本软件仅兼容PHP 8.2.x，谢谢！');
}

define('DS', DIRECTORY_SEPARATOR);

//检测是否已安装CRMEB系统
if(file_exists("./install/") && !file_exists("./install.lock")){
    if($_SERVER['PHP_SELF'] != '/index.php'){
        header("Content-type: text/html; charset=utf-8");
        exit("请在域名根目录下安装,如:<br/> www.xxx.com/index.php 正确 <br/>  www.xxx.com/www/index.php 错误,域名后面不能圈套目录, 但项目没有根目录存放限制,可以放在任意目录,apache虚拟主机配置一下即可");
    }
    header('Location:/install/index.php');
    exit();
}

require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$app = new App();
$http = $app->http;

$response = $http->run();

\crmeb\services\RuntimeLog::attach($app, $response);
$response->send();

$http->end($response);
