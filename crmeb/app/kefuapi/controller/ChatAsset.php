<?php
declare(strict_types=1);
namespace app\kefuapi\controller;

use app\Request;
use app\api\controller\v1\user\ChatAsset as ResponseAdapter;
use app\dao\marketplace\assets\PlatformChatAssetDao;
use app\services\marketplace\assets\PlatformChatAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use think\Response;

final class ChatAsset
{
    public function capabilities(Request $r,PlatformChatAssetServices $s):Response {return app('json')->success($s->capabilities($r,'kefu'));}
    public function guestCapabilities(Request $r,PlatformChatAssetServices $s):Response {return app('json')->success($s->capabilities($r,'guest'));}
    public function upload(Request $r,PlatformChatAssetServices $s):Response
    {
        try{return app('json')->success('图片上传成功',$s->upload($r,'kefu'));}
        catch(MerchantAttachmentOperationException $e){return ResponseAdapter::incomplete($e);}
    }
    public function ticket(Request $r,PlatformChatAssetServices $s):Response
    {return app('json')->success($s->readTicket($r,'kefu',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
    public function content(Request $r,PlatformChatAssetServices $s):Response
    {return ResponseAdapter::respond($s->content($r,'kefu',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
    public function guestTicket(Request $r,PlatformChatAssetServices $s):Response
    {return app('json')->success($s->readTicket($r,'guest',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
    public function guestContent(Request $r,PlatformChatAssetServices $s):Response
    {return ResponseAdapter::respond($s->content($r,'guest',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
}
