<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\PlatformChatAssetDao as Assets;
use app\services\kefu\LoginServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\SessionWhitelist;
use crmeb\services\workerman\chat\ChatIdentity;
use crmeb\services\workerman\chat\GuestChatSession;
use crmeb\utils\JwtAuth;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Config;

/** Finite current platform participants, not a merchant/admin or generic ownership context. */
final class PlatformChatRoomAccessServices
{
    public function __construct(private Assets $assets){}

    public function http(Request $request,string $kind):array
    {
        if(!in_array($kind,['api','kefu','guest'],true)||app()->http->getName()!==($kind==='api'?'api':'kefuapi')
            ||$request->get('token','')!==''||$request->server('QUERY_STRING','')!=='')self::fail();
        if($kind==='guest'){
            if($request->header('Authorization','')!==''||$request->header('Authori-zation','')!=='')self::fail();
            $token=$request->header('X-Platform-Chat-Guest-Token','');if(!is_string($token)||$token==='')self::fail();
            $guest=app()->make(GuestChatSession::class)->verify($token);
            return $this->ws(new ChatIdentity('guest',$guest['uid'],$token,$guest['expires_at'],$guest['avatar']));
        }
        if($request->header('X-Platform-Chat-Guest-Token','')!=='')self::fail();
        $name=Config::get('cookie.token_name','Authori-zation');if(!is_string($name)||$name==='')self::fail();
        $header=$request->header($name,'');$standard=$request->header('Authorization','');
        if($header!==''&&$standard!==''&&$header!==$standard)self::fail();$header=$header!==''?$header:$standard;
        if(!is_string($header)||preg_match('/\ABearer ([^\s]+)\z/D',$header,$m)!==1)self::fail();
        $actor=$this->credential($kind,$m[1]);
        if($kind==='api'&&($request->isLogin()!==true||(int)$request->uid()!==$actor['uid']
            ||$request->verifiedTokenFingerprint()!==$actor['session_sha256']))self::fail();
        if($kind==='kefu'&&((int)$request->kefuId()!==$actor['service_id']||(int)$request->kefuInfo()['uid']!==$actor['uid']))self::fail();
        return $actor;
    }
    public function ws(ChatIdentity $identity):array
    {
        if($identity->kind==='guest')app()->make(GuestChatSession::class)->assertCurrent($identity);
        $actor=$this->credential($identity->kind,$identity->credential());
        if($actor['uid']!==$identity->uid)self::fail();return $actor;
    }
    private function credential(string $kind,string $token):array
    {
        if($token===''||strlen($token)>8192)self::fail();$serviceId=null;
        if($kind==='api'){
            AuthConfigGuard::assertRedisSessionStore();$verified=app()->make(JwtAuth::class)->decodeVerified($token);
            if(($verified['type']??null)!=='api')self::fail();$uid=Assets::integer($verified['id']??null,1,4294967295);
            if((string)$verified['id']!==(string)$uid||!SessionWhitelist::matches(SessionWhitelist::read($token),'api',$verified['id'],$token))self::fail();
        }elseif($kind==='kefu'){
            $auth=app()->make(LoginServices::class)->parseToken($token);$uid=Assets::integer($auth['uid']??null,1,4294967295);
            $serviceId=Assets::integer($auth['id']??null,1,4294967295);
        }elseif($kind==='guest'){
            $guest=app()->make(GuestChatSession::class)->verify($token);$uid=Assets::integer($guest['uid'],-2147483647,-1);
        }else self::fail();
        return ['kind'=>$kind,'uid'=>$uid,'service_id'=>$serviceId,'session_sha256'=>hash('sha256',$token)];
    }
    public function current(ConnectionInterface $c,PDO $p,array $actor,int $depth):array
    {
        $this->assets->native($c,$p,$depth);
        if(array_keys($actor)!==['kind','uid','service_id','session_sha256']||!in_array($actor['kind'],['api','kefu','guest'],true)
            ||!is_string($actor['session_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$actor['session_sha256'])!==1)self::fail();
        if($actor['kind']==='guest'){
            if($actor['service_id']!==null||$actor['uid']>=0)self::fail();
            $current=app()->make(GuestChatSession::class)->findCurrent($actor['uid']);if($current===null)self::fail();return ['guest_uid'=>$actor['uid']];
        }
        $user=$this->assets->one($c,$p,'user',['uid'=>$actor['uid']],$depth);
        if(Assets::integer($user['status']??null,0,1)!==1||Assets::integer($user['is_del']??null,0,1)!==0)self::fail();
        if($actor['kind']==='kefu')$this->service($c,$p,$actor['uid'],$actor['service_id'],$depth,true);
        elseif($actor['service_id']!==null)self::fail();return $user;
    }
    public function service(ConnectionInterface $c,PDO $p,int $uid,?int $id,int $depth,bool $active):array
    {
        Assets::integer($uid,1,4294967295);$service=$this->assets->one($c,$p,'store_service',['uid'=>$uid],$depth);
        $actual=Assets::integer($service['id']??null,1,4294967295);
        if(Assets::integer($service['uid']??null,1,4294967295)!==$uid||($id!==null&&$id!==$actual)
            ||($active&&Assets::integer($service['status']??null,0,1)!==1))self::fail();return $service;
    }
    /** Upload/message target locators never supply the final participant or tourist classification. */
    public function room(ConnectionInterface $c,PDO $p,array $actor,int $target,?int $touristHint,int $depth):array
    {
        $this->current($c,$p,$actor,$depth);Assets::integer($target,-2147483647,4294967295);
        if($target===0||$target===$actor['uid'])self::fail();
        if($actor['kind']!=='kefu'){
            $service=$this->service($c,$p,$target,null,$depth,true);$user=$this->assets->one($c,$p,'user',['uid'=>$target],$depth);
            if(Assets::integer($user['status']??null,0,1)!==1||Assets::integer($user['is_del']??null,0,1)!==0)self::fail();
            $tourist=$actor['kind']==='guest'?1:0;$customer=$actor['uid'];
        }else{
            $service=$this->service($c,$p,$actor['uid'],$actor['service_id'],$depth,true);$customer=$target;
            $legacy=$this->assets->find($c,$p,'store_service_record',['user_id'=>$actor['uid'],'to_uid'=>$target,'is_tourist'=>1],$depth);
            if($target<0&&app()->make(GuestChatSession::class)->findCurrent($target)!==null)$tourist=1;
            elseif(count($legacy)===1)$tourist=1;
            else{
                if(count($legacy)>1||$target<1)self::fail();$user=$this->assets->one($c,$p,'user',['uid'=>$target],$depth);
                if(Assets::integer($user['status']??null,0,1)!==1||Assets::integer($user['is_del']??null,0,1)!==0
                    ||count($this->assets->find($c,$p,'store_service_record',['user_id'=>$actor['uid'],'to_uid'=>$target,'is_tourist'=>0],$depth))!==1)self::fail();$tourist=0;
            }
        }
        if($touristHint!==null&&$touristHint!==$tourist)self::fail();
        $room=['customer_uid'=>$customer,'is_tourist'=>$tourist,'service_id'=>Assets::integer($service['id'],1,4294967295),'service_uid'=>Assets::integer($service['uid'],1,4294967295)];
        Assets::room($room);return $room;
    }
    public function reader(ConnectionInterface $c,PDO $p,array $actor,array $room,int $depth):void
    {
        Assets::room($room);$this->current($c,$p,$actor,$depth);$this->service($c,$p,$room['service_uid'],$room['service_id'],$depth,false);
        if($actor['kind']==='guest'){
            if($room['is_tourist']!==1||$room['customer_uid']!==$actor['uid'])self::fail();return;
        }
        if($actor['kind']==='kefu'){
            if($actor['uid']!==$room['service_uid']||$actor['service_id']!==$room['service_id'])self::fail();return;
        }
        if($room['is_tourist']===0&&$actor['uid']===$room['customer_uid'])return;
        // The original v1 UserAuth service-history participant remains read-only.
        if($actor['uid']===$room['service_uid']){$this->service($c,$p,$actor['uid'],$room['service_id'],$depth,true);return;}self::fail();
    }
    public function creator(array $actor):array
    {
        return match($actor['kind']){'api'=>['uid'=>$actor['uid'],'service_id'=>null],
            'kefu'=>['uid'=>null,'service_id'=>$actor['service_id']],default=>self::fail()};
    }
    public function preview(array $actor,array $asset):void
    {
        if($actor['kind']==='guest'||$asset['state']!=='STAGED'||Assets::integer($asset['expires_at'],1,4294967295)<=time())self::fail();
        if($actor['kind']==='api'){
            if($asset['created_by_service_id']!==null||Assets::integer($asset['created_by_uid'],1,4294967295)!==$actor['uid'])self::fail();
        }elseif($asset['created_by_uid']!==null||Assets::integer($asset['created_by_service_id'],1,4294967295)!==$actor['service_id'])self::fail();
    }
    public function sender(array $actor,array $asset):void
    {
        if($actor['kind']==='guest')self::fail();
        if($asset['state']==='STAGED')$this->preview($actor,$asset);
        elseif($asset['state']!=='BOUND')self::fail();
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
