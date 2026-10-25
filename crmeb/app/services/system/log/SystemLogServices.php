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

namespace app\services\system\log;


use app\dao\system\log\SystemLogDao;
use app\services\BaseServices;
use app\services\system\admin\SystemAdminServices;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRouteServices;

/**
 * 系统日志
 * Class SystemLogServices
 * @package app\services\system\log
 * @method deleteLog() 定期删除日志
 */
class SystemLogServices extends BaseServices
{

    /**
     * 构造方法
     * SystemLogServices constructor.
     * @param SystemLogDao $dao
     */
    public function __construct(SystemLogDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 记录访问日志
     * @param int $adminId
     * @param string $adminName
     * @param string $type
     * @return bool
     */
    public function recordAdminLog(int $adminId, string $adminName, string $type)
    {
        $request = app()->request;
        $module = app('http')->getName();
        $rule = trim(strtolower($request->rule()->getRule()));

        /** @var SystemMenusServices $service */
        $service = app()->make(SystemMenusServices::class);
        $data = [
            'method' => $module,
            'admin_id' => $adminId,
            'add_time' => time(),
            'admin_name' => $adminName,
            'path' => $rule,
            'page' => $service->getVisitName($rule) ?: '未知',
            'ip' => $request->ip(),
            'type' => $type
        ];
        if ($this->dao->save($data)) {
            return true;
        } else {
            return false;
        }
    }

    /** This one platform upload retains the original log payload and returns its actual native10. */
    public function recordPlatformAssetUploadRow(\app\Request $request,\app\dao\marketplace\scope\PlatformScope $scope,\PDO $pdo): array
    {
        \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo);
        if($scope->permission!==\app\services\marketplace\assets\PlatformAssetServices::UPLOAD)throw new \crmeb\exceptions\AuthUnavailableException();
        $admin=$request->adminInfo();$connection=\think\facade\Db::connect();$query=(new \app\model\system\log\SystemLog())->db();
        if(!is_array($admin)||(string)($admin['id']??'')!==(string)$scope->adminId||!is_string($admin['account']??null)
            ||$query->getConnection()!==$connection||$connection->getPdo()!==$pdo)throw new \crmeb\exceptions\AuthUnavailableException();
        $rule=trim(strtolower($request->rule()->getRule()));
        $data=['method'=>'adminapi','admin_id'=>$scope->adminId,'add_time'=>time(),'admin_name'=>$admin['account'],'path'=>$rule,
            'page'=>app()->make(\app\services\system\SystemMenusServices::class)->getVisitName($rule)?:'未知','ip'=>$request->ip(),'type'=>'system'];
        foreach(['method'=>12,'admin_name'=>64,'path'=>128,'page'=>64,'ip'=>16,'type'=>32]as$key=>$maximum)
            \app\services\marketplace\MerchantManagementInput::text($data[$key],$maximum,true);
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'system_log')>64)throw new \crmeb\exceptions\AuthUnavailableException();
        $table='`'.$prefix.'system_log`';$columns=array_keys($data);
        $insert=$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        if($insert===false)throw new \crmeb\exceptions\AuthUnavailableException();
        try{if($insert->execute(array_values($data))!==true||$insert->rowCount()!==1)throw new \crmeb\exceptions\AuthUnavailableException();
            $id=\app\dao\marketplace\assets\PlatformAssetDao::integer($pdo->lastInsertId(),1,4294967295);}
        finally{if($insert->closeCursor()!==true)throw new \crmeb\exceptions\AuthUnavailableException();}
        $select=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=? LIMIT 2 FOR UPDATE');if($select===false)throw new \crmeb\exceptions\AuthUnavailableException();
        try{if($select->execute([$id])!==true)throw new \crmeb\exceptions\AuthUnavailableException();$rows=$select->fetchAll(\PDO::FETCH_ASSOC);}
        finally{if($select->closeCursor()!==true)throw new \crmeb\exceptions\AuthUnavailableException();}
        $fields=['id','admin_id','admin_name','path','page','method','ip','type','add_time','merchant_id'];
        if(count($rows)!==1||array_keys($rows[0])!==$fields||(string)$rows[0]['id']!==(string)$id||(string)$rows[0]['merchant_id']!=='0')throw new \crmeb\exceptions\AuthUnavailableException();
        foreach($data as$key=>$value)if(is_int($value)?(string)$rows[0][$key]!== (string)$value:$rows[0][$key]!==$value)throw new \crmeb\exceptions\AuthUnavailableException();
        \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo);return $rows[0];
    }

    /**
     * 获取系统日志列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getLogList(array $where, int $level)
    {
        [$page, $limit] = $this->getPageValue();
        if (!$where['admin_id']) {
            /** @var SystemAdminServices $service */
            $service = app()->make(SystemAdminServices::class);
            $where['admin_id'] = $service->getAdminIds($level);
        }
        $routeArr = app()->make(SystemRouteServices::class)->getNameList('adminapi');
        $list = $this->dao->getLogList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach($list as &$item){
            $item['path_name'] = $routeArr[$item['path']] ?? '未知';
        }
        return compact('list', 'count');
    }
}
