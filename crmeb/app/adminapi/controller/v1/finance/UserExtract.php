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

namespace app\adminapi\controller\v1\finance;

use app\adminapi\controller\AuthController;
use app\services\user\UserExtractServices;
use think\facade\App;
use think\Request;

/**
 * Class UserExtract
 * @package app\adminapi\controller\v1\finance
 */
class UserExtract extends AuthController
{
    /**
     * UserExtract constructor.
     * @param App $app
     * @param UserExtractServices $services
     */
    public function __construct(App $app, UserExtractServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['extract_type', ''],
            ['nireid', '', '', 'like'],
            ['data', '', '', 'time'],
        ]);
        if (isset($where['extract_type']) && $where['extract_type'] == 'wx') {
            $where['extract_type'] = 'weixin';
        }
        return app('json')->success($this->services->index($where));
    }

    /**
     * 显示编辑资源表单页
     * @param $id
     * @return mixed
     */
    public function edit($id)
    {
        if (!$id) return app('json')->fail('数据不存在');
        return app('json')->success($this->services->edit((int)$id));
    }

    /**
     * 保存更新的资源
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function update(Request $request, $id)
    {
        if (!$id) return app('json')->fail('参数错误');
        $id = (int)$id;
        $UserExtract = $this->services->getExtract($id);
        if (!$UserExtract) app('json')->fail('数据不存在');
        if ($UserExtract['extract_type'] == 'alipay') {
            $data = $this->request->postMore([
                'real_name',
                'mark',
                'extract_price',
                'alipay_code',
            ]);
            if (!$data['real_name']) return app('json')->fail('请输入姓名');
            if ($data['extract_price'] <= -1) return app('json')->fail('请输入提现金额');
            if (!$data['alipay_code']) return app('json')->fail('请输入支付宝账号');
        } else if ($UserExtract['extract_type'] == 'weixin') {
            $data = $this->request->postMore([
                'real_name',
                'mark',
                'extract_price',
                'wechat',
            ]);
            if ($data['extract_price'] <= -1) return app('json')->fail('请输入提现金额');
            if (!$data['wechat']) return app('json')->fail('请输入微信账号');
        } else {
            $data = $this->request->postMore([
                'real_name',
                'extract_price',
                'mark',
                'bank_code',
                'bank_address',
            ]);
            if (!$data['real_name']) return app('json')->fail('请输入姓名');
            if ($data['extract_price'] <= -1) return app('json')->fail('请输入提现金额');
            if (!$data['bank_code']) return app('json')->fail('请输入银行卡号');
            if (!$data['bank_address']) return app('json')->fail('请输入开户行');
        }
        return app('json')->success($this->services->update($id, $data) ? '修改成功' : '修改失败');
    }

    /**
     * 拒绝
     * @param $id
     * @return mixed
     */
    public function refuse($id)
    {
        if (!$id) app('json')->fail('参数错误');
        $data = $this->request->postMore([
            ['message', '']
        ]);
        if ($data['message'] == '') return app('json')->fail('拒绝理由不能为空');
        return app('json')->success($this->services->refuse((int)$id, $data['message']) ? '设置成功' : '设置失败');
    }

    /**
     * 通过
     * @param $id
     * @return mixed
     */
    public function adopt($id)
    {
        if (!$id) app('json')->fail('参数错误');
        $res = $this->services->adopt((int)$id);
        if ($res) {
            if ($res === 'v3_extract') {
                return app('json')->success('提现成功，等待用户确认收款');
            } else {
                return app('json')->success('提现成功');
            }
        } else {
            return app('json')->success('操作失败');
        }
    }

    /** The row id is the actual saved withdrawal shown by the original finance list. */
    public function qrMaterial(\app\Request $request):\think\Response
    {
        $id=\app\dao\marketplace\assets\CustomerWithdrawalQrAssetDao::integer($request->route('extract_id',null,''),1,4294967295);
        $asset=\app\dao\marketplace\assets\CustomerWithdrawalQrAssetDao::assetId($request->route('asset_id',null,''));
        return self::qrResponse($request,app()->make(\app\services\marketplace\assets\CustomerWithdrawalQrAssetServices::class)
            ->platformRead($request,$id,$asset));
    }
    private static function qrResponse(\app\Request $request,array $value):\think\Response
    {
        if($request->method(true)==='POST'){
            if(array_keys($value)!==['read_ticket','issued_at_ms','expires_at_ms'])throw new \crmeb\exceptions\AuthUnavailableException();
            return \think\Response::create(['status'=>200,'msg'=>'操作成功','data'=>$value],'json',200)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
        if($request->method(true)!=='GET'||array_keys($value)!==['bytes','media_type','byte_size','file_name']
            ||!is_string($value['bytes'])||$value['bytes']===''||!is_int($value['byte_size'])
            ||$value['byte_size']!==strlen($value['bytes'])||!is_string($value['media_type'])
            ||!in_array($value['media_type'],['image/jpeg','image/jpg','image/png','image/gif','image/webp'],true)
            ||!is_string($value['file_name'])||preg_match('/\Awithdrawal-qr-[1-9][0-9]{0,19}\.(?:jpg|jpeg|png|gif|webp)\z/D',$value['file_name'])!==1)throw new \crmeb\exceptions\AuthUnavailableException();
        $response=\think\Response::create($value['bytes'],'html',200)->allowCache(false)->header([
            'Content-Type'=>$value['media_type'],'Content-Length'=>(string)$value['byte_size'],
            'Content-Disposition'=>'inline; filename="'.$value['file_name'].'"',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($response->getContent()!==$value['bytes']||$response->getCode()!==200||$response->isAllowCache()!==false)throw new \crmeb\exceptions\AuthUnavailableException();
        return $response;
    }
}
