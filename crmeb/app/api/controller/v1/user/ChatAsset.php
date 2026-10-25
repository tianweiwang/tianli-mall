<?php
declare(strict_types=1);
namespace app\api\controller\v1\user;

use app\Request;
use app\dao\marketplace\assets\PlatformChatAssetDao;
use app\services\marketplace\assets\PlatformChatAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

final class ChatAsset
{
    public function capabilities(Request $r,PlatformChatAssetServices $s):Response {return app('json')->success($s->capabilities($r,'api'));}
    public function upload(Request $r,PlatformChatAssetServices $s):Response
    {
        try{return app('json')->success('图片上传成功',$s->upload($r,'api'));}
        catch(MerchantAttachmentOperationException $e){return self::incomplete($e);}
    }
    public function ticket(Request $r,PlatformChatAssetServices $s):Response
    {return app('json')->success($s->readTicket($r,'api',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
    public function content(Request $r,PlatformChatAssetServices $s):Response
    {return self::respond($s->content($r,'api',PlatformChatAssetDao::assetId($r->route('asset_id',null,null))));}
    public static function incomplete(MerchantAttachmentOperationException $e):Response
    {
        $v=$e->receipt;$keys=array_keys($v);sort($keys,SORT_STRING);
        if($keys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||!is_bool($v['complete'])||!is_bool($v['physical_complete'])||!is_string($v['phase'])
            ||preg_match('/\A[a-z_]{1,64}\z/D',$v['phase'])!==1
            ||!in_array($v['physical_changed'],[null,false,true],true)||!in_array($v['database_committed'],[null,false,true],true)
            ||!in_array($v['cleanup_complete'],[null,false,true],true))throw new AuthUnavailableException();
        return Response::create(['status'=>503,'msg'=>'图片操作结果尚未完整确认，请保留本次草稿','data'=>$v],'json',503)
            ->allowCache(false)->header(['Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff']);
    }
    public static function respond(array $v):Response
    {
        if(array_keys($v)!==['bytes','media_type','byte_size','file_name']||!is_string($v['bytes'])||$v['bytes']===''
            ||!is_int($v['byte_size'])||$v['byte_size']!==strlen($v['bytes'])
            ||!in_array($v['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||!is_string($v['file_name'])||preg_match('/\Aplatform-chat-[1-9][0-9]{0,19}\.(jpg|jpeg|png|gif|webp)\z/D',$v['file_name'])!==1)
            throw new AuthUnavailableException();
        $r=new class($v['bytes']) extends Response {
            public function __construct(string $bytes){$this->init($bytes,200);}
        };
        $r->allowCache(false)->header(['Content-Type'=>$v['media_type'],
            'Content-Length'=>(string)$v['byte_size'],'Content-Disposition'=>'inline; filename="'.$v['file_name'].'"',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($r->getContent()!==$v['bytes']||$r->getCode()!==200||$r->isAllowCache()!==false)throw new AuthUnavailableException();return $r;
    }
}
