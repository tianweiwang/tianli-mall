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
namespace app\api\controller\v1\user;

use app\Request;
use app\services\user\UserExtractServices;
use think\facade\Config;
use app\dao\marketplace\assets\CustomerWithdrawalQrAssetDao as WithdrawalAssets;
use app\services\marketplace\assets\CustomerAssetServices;
use app\services\marketplace\assets\CustomerWithdrawalQrAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;

/**
 * 提现类
 * Class UserExtractController
 * @package app\api\controller\user
 */
class UserExtractController
{
    protected $services = NUll;

    /**
     * UserExtractController constructor.
     * @param UserExtractServices $services
     */
    public function __construct(UserExtractServices $services)
    {
        $this->services = $services;
    }

    /**
     * 提现银行
     * @param Request $request
     * @return mixed
     */
    public function bank(Request $request)
    {
        $uid = (int)$request->uid();
        return app('json')->success($this->services->bank($uid));
    }

    /**
     * 提现申请
     * @param Request $request
     * @return mixed
     */
    public function cash(Request $request)
    {
        $extractInfo = $request->postMore([
            ['alipay_code', ''],
            ['extract_type', ''],
            ['money', 0],
            ['user_name', ''],
            ['name', ''],
            ['bankname', ''],
            ['cardnum', ''],
            ['weixin', ''],
            ['qrcode_url', ''],
        ]);
        $extractInfo['channel_type'] = $request->getFromType();
        $extractType = Config::get('pay.extractType', []);
        if (!in_array($extractInfo['extract_type'], $extractType))
            return app('json')->fail('提现方式不存在');
        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', (float)$extractInfo['money'])) return app('json')->fail('提现金额输入有误');
        if (!$extractInfo['cardnum'] == '')
            if (!preg_match('/^([1-9]{1})(\d{15}|\d{16}|\d{18})$/', $extractInfo['cardnum']))
                return app('json')->fail('银行卡号输入有误');
        if ($extractInfo['extract_type'] == 'weixin') {
            if (trim($extractInfo['user_name']) == '') return app('json')->fail('请填写真实姓名');
        } elseif ($extractInfo['extract_type'] == 'alipay') {
            if (trim($extractInfo['alipay_code']) == '') return app('json')->fail('请输入支付宝账号');
            if (trim($extractInfo['user_name']) == '') return app('json')->fail('请填写真实姓名');
        } elseif ($extractInfo['extract_type'] == 'bank') {
            if (!$extractInfo['cardnum']) return app('json')->fail('请输入银行卡账号');
            if (!$extractInfo['bankname']) return app('json')->fail('请输入开户行信息');
        }
        $uid = (int)$request->uid();
        if (WithdrawalAssets::isPrivate($extractInfo['qrcode_url'])) {
            try {
                $ok=$this->services->cash($uid,$extractInfo,$request);
                return \think\Response::create(['status'=>$ok?200:400,'msg'=>$ok?'申请提现成功':'申请提现失败','data'=>[]],'json',200)
                    ->allowCache(false)->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
            } catch (MerchantAttachmentOperationException $error) {
                return \think\Response::create(['status'=>503,'msg'=>'提交结果尚未确认，请保留草稿，勿重复提交','data'=>$error->receipt],'json',503)
                    ->allowCache(false)->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
            }
        }
        if ($this->services->cash($uid, $extractInfo))
            return app('json')->success('申请提现成功');
        else
            return app('json')->fail('申请提现失败');
    }

    public function uploadQr(Request $request):\think\Response
    {
        $type=$request->route('extractType',null,'');
        if(!is_string($type))throw new \crmeb\exceptions\AuthUnavailableException();
        try{
            $data=app()->make(CustomerAssetServices::class)->uploadWithdrawalQr($request,(int)$request->uid(),$type);
            return \think\Response::create(['status'=>200,'msg'=>'图片上传成功','data'=>$data],'json',200)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }catch(MerchantAttachmentOperationException $error){
            return \think\Response::create(['status'=>503,'msg'=>'图片上传结果尚未确认，请保留草稿','data'=>$error->receipt],'json',503)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
    }
    public function qrMaterial(Request $request):\think\Response
    {
        return self::qrResponse($request,app()->make(CustomerWithdrawalQrAssetServices::class)->customerRead($request,
            (int)$request->uid(),WithdrawalAssets::assetId($request->route('asset_id',null,''))));
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
