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

namespace crmeb\services\workerman\chat;

use app\services\kefu\LoginServices;
use app\services\kefu\service\StoreServiceLogServices;
use app\services\kefu\service\StoreServiceRecordServices;
use app\services\kefu\service\StoreServiceServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserServices;
use app\services\wechat\WechatKeyServices;
use app\services\wechat\WechatReplyServices;
use app\services\wechat\WechatUserServices;
use app\services\user\UserAuthServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\services\app\WechatService;
use crmeb\services\workerman\Response;
use crmeb\utils\Arr;
use think\facade\Log;
use Workerman\Connection\TcpConnection;

/**
 * Class ChatHandle
 * @package crmeb\services\workerman\chat
 */
class ChatHandle
{
    /**
     * @var ChatService
     */
    protected $service;

    /**
     * ChatHandle constructor.
     * @param ChatService $service
     */
    public function __construct(ChatService &$service)
    {
        $this->service = &$service;
    }

    /**
     * 客服登录
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     * @return bool|null
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function kefu_login(TcpConnection &$connection, array $res, Response $response)
    {
        $this->service->detachIdentity($connection);
        if (!isset($res['data']) || !$token = $res['data']) {
            return $response->close([
                'msg' => '授权失败!'
            ]);
        }
        try {
            /** @var LoginServices $services */
            $services = app()->make(LoginServices::class);
            $kefuInfo = $services->parseToken($token);
        } catch (AuthException $e) {
            return $response->close([
                'msg' => $e->getMessage()
            ]);
        }

        $connection->kefuUser = $kefuInfo;
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $connection->user = $userService->get($kefuInfo['uid'], ['uid', 'nickname']);
        if (!isset($connection->user->uid)) {
            return $response->close([
                'msg' => '您登录的客服用户不存在'
            ]);
        }
        /** @var StoreServiceRecordServices $service */
        $service = app()->make(StoreServiceRecordServices::class);
        $service->updateRecord(['to_uid' => $connection->user->uid], ['online' => 1]);
        /** @var StoreServiceServices $service */
        $service = app()->make(StoreServiceServices::class);
        $service->update(['uid' => $connection->user->uid], ['online' => 1]);
        $connection->chatIdentity = new ChatIdentity('kefu', (int)$connection->user->uid, $token);
        $this->service->setKefuUser($connection);

        return $response->success(['kind' => 'kefu', 'uid' => (int)$connection->user->uid]);
    }

    /**
     * 用户登录
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     * @return bool|null
     */
    public function login(TcpConnection &$connection, array $res, Response $response)
    {
        $this->service->detachIdentity($connection);
        if (!isset($res['data']) || !$token = $res['data']) {
            return $response->close([
                'msg' => '授权失败!'
            ]);
        }

        try {
            /** @var UserAuthServices $services */
            $services = app()->make(UserAuthServices::class);
            $authInfo = $services->parseToken($token);
        } catch (AuthException $e) {
            return $response->close([
                'msg' => $e->getMessage()
            ]);
        }

        $connection->user = $authInfo['user'];
        $connection->tokenData = $authInfo['tokenData'];
        $connection->chatIdentity = new ChatIdentity('api', (int)$connection->user->uid, $token);
        $this->service->setUser($connection);

        /** @var StoreServiceRecordServices $service */
        $service = app()->make(StoreServiceRecordServices::class);
        $service->updateRecord(['to_uid' => $connection->user->uid], ['online' => 1, 'type' => $res['form_type'] ?? 1]);
        $connections = $this->service->kefuUser();
        foreach ($connections as &$conn) {
            if (!isset($conn->onlineUids) || !in_array($connection->user->uid, $conn->onlineUids ?? [])) {
                $response->connection($conn)->send('user_online', ['to_uid' => $connection->user->uid, 'online' => 1]);
            }
            if (!isset($conn->onlineUids)) {
                $conn->onlineUids = [];
            }
            array_push($conn->onlineUids, $connection->user->uid);
            $this->service->setKefuUser($conn, false);
        }

        return $response->connection($connection)->success(['kind' => 'api', 'uid' => (int)$connection->user->uid]);
    }

    /**
     *
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     */
    public function to_chat(TcpConnection &$connection, array $res, Response $response)
    {
        $identity = $this->currentIdentity($connection);
        $target = $this->roomId($res['data']['id'] ?? null, true);
        if ($target === 0) {
            unset($connection->chatToUid, $connection->chatRoomTourist);
            return $response->send('mssage_num', ['uid' => 0, 'num' => 0, 'recored' => (object)[]]);
        }
        $tourist = $this->roomTourist($identity, $target);
        $connection->chatToUid = $target;
        $connection->chatRoomTourist = $tourist;
        if ($identity->kind !== 'guest') {
            $records = app()->make(StoreServiceRecordServices::class);
            $records->update(['user_id' => $identity->uid, 'to_uid' => $target, 'is_tourist' => (int)$tourist], ['mssage_num' => 0]);
            $logs = app()->make(StoreServiceLogServices::class);
            $logs->update(['uid' => $target, 'to_uid' => $identity->uid, 'is_tourist' => (int)$tourist], ['type' => 1]);
        }
        return $response->send('mssage_num', ['uid' => $target, 'num' => 0, 'recored' => (object)[]]);
    }

    public function guest_login(TcpConnection &$connection, array $res, Response $response)
    {
        $this->service->detachIdentity($connection);
        $token = $res['data'] ?? null;
        if (!is_string($token)) throw new AuthSessionException();
        $guest = app()->make(GuestChatSession::class)->verify($token);
        $identity = new ChatIdentity('guest', $guest['uid'], $token, $guest['expires_at'], $guest['avatar']);
        $connection->chatIdentity = $identity;
        $connection->isTourist = true;
        $connection->user = (object)['uid' => $identity->uid];
        $this->service->setUser($connection);
        return $response->success(['kind' => 'guest', 'uid' => $identity->uid, 'expires_at' => $identity->expiresAt]);
    }

    /** Server Channel consumer shares the actual credential reader; client dispatch stays finite. */
    public function assertCurrentConnection(TcpConnection $connection): ChatIdentity
    {
        return $this->currentIdentity($connection);
    }

    private function currentIdentity(TcpConnection $connection): ChatIdentity
    {
        $identity = $connection->chatIdentity ?? null;
        if (!$identity instanceof ChatIdentity) throw new AuthSessionException();
        if ($identity->kind === 'guest') {
            app()->make(GuestChatSession::class)->assertCurrent($identity);
        } elseif ($identity->kind === 'kefu') {
            $current = app()->make(LoginServices::class)->parseToken($identity->credential());
            if ((int)$current['uid'] !== $identity->uid) throw new AuthSessionException();
        } else {
            $current = app()->make(UserAuthServices::class)->parseToken($identity->credential());
            if ((int)$current['user']['uid'] !== $identity->uid) throw new AuthSessionException();
        }
        if ((int)($connection->user->uid ?? 0) !== $identity->uid) throw new AuthSessionException();
        return $identity;
    }

    /** Zero is a normal leave-room operation; negative IDs belong to guests. */
    private function roomId($value, bool $allowZero = false): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^(0|-?[1-9][0-9]{0,9})$/D', $value) === 1)) throw new AuthSessionException();
        $value = (int)$value;
        if ($value < -2147483647 || $value > 4294967295 || (!$allowZero && $value === 0)) throw new AuthSessionException();
        return $value;
    }

    /** Establish the conversation classification from current server records. */
    private function recipientConnection(ChatIdentity $sender, int $target, bool $tourist): ?TcpConnection
    {
        $expected = $sender->kind !== 'kefu' ? 'kefu' : ($tourist ? 'guest' : 'api');
        $recipient = $expected === 'kefu' ? ($this->service->kefuUser()[$target] ?? null) : $this->service->user($target);
        if (!$recipient instanceof TcpConnection) return null;
        $identity = $recipient->chatIdentity ?? null;
        if (!$identity instanceof ChatIdentity || $identity->uid !== $target || $identity->kind !== $expected) return null;
        try {
            $this->currentIdentity($recipient);
        } catch (AuthException $error) {
            if (in_array((int)$error->getCode(), [401, 403], true)) return null;
            throw $error;
        }
        return $recipient;
    }

    /** Establish the conversation classification from current server records. */
    private function roomTourist(ChatIdentity $identity, int $target): bool
    {
        if ($identity->uid === $target) throw new AuthSessionException();
        if ($identity->kind !== 'kefu') {
            if ($target <= 0 || !app()->make(StoreServiceServices::class)->get(['uid' => $target, 'status' => 1], ['id'])
                || !app()->make(UserServices::class)->get(['uid' => $target, 'status' => 1, 'is_del' => 0], ['uid'])) throw new AuthSessionException();
            return $identity->kind === 'guest';
        }
        if ($target < 0 && app()->make(GuestChatSession::class)->findCurrent($target) !== null) return true;
        $touristRecord = \think\facade\Db::name('store_service_record')->where('user_id', $identity->uid)
            ->where('to_uid', $target)->where('is_tourist', 1)->value('id');
        if ($touristRecord) return true;
        if ($target > 0 && app()->make(UserServices::class)->get(['uid' => $target, 'status' => 1, 'is_del' => 0], ['uid'])) {
            $current = $this->recipientConnection($identity, $target, false);
            $record = \think\facade\Db::name('store_service_record')->where('user_id', $identity->uid)
                ->where('to_uid', $target)->where('is_tourist', 0)->value('id');
            if ($current !== null || $record) return false;
        }
        throw new AuthSessionException();
    }

    /**
     * 用户向客服发送消息
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     * @return bool|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function chat(TcpConnection &$connection, array $res, Response $response)
    {
        $identity = $this->currentIdentity($connection);
        $to_uid = $this->roomId($res['data']['to_uid'] ?? null);
        if (($connection->chatToUid ?? null) !== $to_uid) throw new AuthSessionException();
        $isTourist = $this->roomTourist($identity, $to_uid);
        $senderIsGuest = $identity->kind === 'guest';
        $msn_type = $res['data']['type'] ?? 0;
        $msn = $res['data']['msn'] ?? '';
        $formType = $res['form_type'] ?? 0;
        $tourist_avatar = $identity->avatar;
        $uid = $identity->uid;
        if (!$to_uid) {
            return $response->send('err_tip', ['msg' => '用户不存在']);
        }
        if ($to_uid == $uid) {
            return $response->send('err_tip', ['msg' => '不能和自己聊天']);
        }
        /** @var StoreServiceLogServices $logServices */
        $logServices = app()->make(StoreServiceLogServices::class);
        if (!in_array($msn_type, $logServices::MSN_TYPE)) {
            return $response->send('err_tip', ['msg' => '格式错误']);
        }
        $msn = trim(strip_tags(str_replace(["\n", "\t", "\r", "&nbsp;"], '', htmlspecialchars_decode($msn))));
        $data = compact('to_uid', 'msn_type', 'msn', 'uid');
        $data['add_time'] = time();
        $data['is_tourist'] = (int)$isTourist;
        $recipient = $this->recipientConnection($identity, $to_uid, $isTourist);
        $online = $recipient !== null && isset($recipient->chatToUid) && $recipient->chatToUid == $uid;
        $data['type'] = $online ? 1 : 0;
        $data = $logServices->save($data);
        $data = $data->toArray();
        $data['_add_time'] = $data['add_time'];
        $data['add_time'] = strtotime($data['add_time']);
        if (!$senderIsGuest) {
            if ($identity->kind === 'kefu') {
                /** @var StoreServiceServices $userService */
                $userService = app()->make(StoreServiceServices::class);
                $_userInfo = $userService->get(['uid' => $data['uid']], ['nickname', 'avatar']);
            } else {
                /** @var UserServices $userService */
                $userService = app()->make(UserServices::class);
                $_userInfo = $userService->getUserInfo($data['uid'], 'nickname,avatar');
            }
            $data['nickname'] = $_userInfo['nickname'];
            $data['avatar'] = set_file_url($_userInfo['avatar']);
        } else {
            $_userInfo['avatar'] = $identity->avatar;
            $_userInfo['nickname'] = '游客' . abs($uid);
            $data['nickname'] = $_userInfo['nickname'];
            $data['avatar'] = set_file_url($_userInfo['avatar']);
        }

        //商品消息类型
        $data['productInfo'] = [];
        if ($msn_type == StoreServiceLogServices::MSN_TYPE_GOODS && $msn) {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $productInfo = $productServices->getProductInfo((int)$msn, 'store_name,IFNULL(sales,0) + IFNULL(ficti,0) as sales,image,slider_image,price,vip_price,ot_price,stock,id');
            $data['productInfo'] = $productInfo ? $productInfo->toArray() : [];
        }
        //订单消息类型
        $data['orderInfo'] = [];
        if (!$isTourist && $msn_type == StoreServiceLogServices::MSN_TYPE_ORDER && $msn) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->getUserOrderDetail($msn, $uid);
            if ($order) {
                $order = $orderServices->tidyOrder($order->toArray(), true, true);
                $order['add_time_y'] = date('Y-m-d', $order['add_time']);
                $order['add_time_h'] = date('H:i:s', $order['add_time']);
                $data['orderInfo'] = $order;
            }
        }
        //给自己回复消息
        $response->send('chat', $data);

        //用户向客服发送消息，判断当前客服是否在登录中
        /** @var StoreServiceRecordServices $serviceRecored */
        $serviceRecored = app()->make(StoreServiceRecordServices::class);
        $unMessagesCount = $logServices->getMessageNum(['uid' => $uid, 'to_uid' => $to_uid, 'type' => 0, 'is_tourist' => $isTourist ? 1 : 0]);
        //记录当前用户和他人聊天记录
        $data['recored'] = $serviceRecored->saveRecord($uid, $to_uid, $msn, $formType ?? 0, $msn_type, $unMessagesCount, $isTourist, $data['nickname'], $data['avatar']);
        //是否在线
        if ($online) {
            $response->connection($recipient)->send('reply', $data);
        } else {
            //用户在线，可是没有和当前用户进行聊天，给当前用户发送未读条数
            if ($recipient !== null) {
                $data['recored']['nickname'] = $_userInfo['nickname'];
                $data['recored']['avatar'] = set_file_url($_userInfo['avatar']);
                $response->connection($recipient)->send('mssage_num', [
                    'uid' => $uid,
                    'num' => $unMessagesCount,
                    'recored' => $data['recored']
                ]);
            }
            if ($isTourist) {
                return true;
            }
            //用户不在线
            /** @var WechatUserServices $wechatUserServices */
            $wechatUserServices = app()->make(WechatUserServices::class);
            $userInfo = $wechatUserServices->getOne(['uid' => $to_uid, 'user_type' => 'wechat'], 'nickname,subscribe,openid,headimgurl');
            if ($userInfo && $userInfo['subscribe'] && $userInfo['openid']) {
                $description = '您有新的消息，请注意查收！';
                if ($formType) {
                    $head = '客服接待消息提醒';
                    $url = sys_config('site_url') . '/kefu/mobile_chat?toUid=' . $uid . '&nickname=' . $_userInfo['nickname'];
                } else {
                    $head = '客服回复消息提醒';
                    $url = sys_config('site_url') . '/pages/extension/customer_list/chat?uid=' . $uid;
                }
                $message = WechatService::newsMessage($head, $description, $url, $_userInfo['avatar']);
                $userInfo = $userInfo->toArray();
                try {
                    WechatService::staffService()->message($message)->to($userInfo['openid'])->send();
                } catch (\Exception $e) {

                    Log::error($userInfo['nickname'] . '发送失败' . $e->getMessage());
                }
            }
        }
        if ($identity->kind !== 'kefu') {
            //判断是否有自动回复
            $wechatKeyServices = app()->make(WechatKeyServices::class);
            $replyId = $wechatKeyServices->value(['keys' => $msn, 'key_type' => 1], 'reply_id');
            if(!$replyId) $replyId = $wechatKeyServices->value(['keys_like' => $msn, 'key_type' => 1], 'reply_id');
            if ($replyId) {
                //查询回复内容
                $autoReplyData = app()->make(WechatReplyServices::class)->get($replyId)->toArray();
                $msgData = json_decode($autoReplyData['data'], true);
                $autoReplyMsn = $autoReplyData['type'] == 'text' ? $msgData['content'] : $msgData['src'];
                $autoReply['to_uid'] = $uid;
                $autoReply['msn_type'] = $autoReplyData['type'] == 'text' ? 1 : 3;
                $autoReply['msn'] = $autoReplyMsn;
                $autoReply['uid'] = $to_uid;
                $autoReply['add_time'] = time();
                $autoReply['is_tourist'] = (int)$isTourist;
                $autoReply['type'] = 1;
                $autoReply = $logServices->save($autoReply);
                $autoReply = $autoReply->toArray();
                $autoReply['_add_time'] = $autoReply['add_time'];
                $autoReply['add_time'] = strtotime($autoReply['add_time']);
                $kefuInfo = app()->make(StoreServiceServices::class)->get(['uid' => $to_uid], ['nickname', 'avatar']);
                $replyMessagesCount = $logServices->getMessageNum(['uid' => $to_uid, 'to_uid' => $uid, 'type' => 0, 'is_tourist' => $isTourist ? 1 : 0]);
                $autoReply['recored'] = $serviceRecored->saveRecord($to_uid, $uid, $autoReplyMsn, $formType ?? 0, $autoReplyData['type'] == 'text' ? 1 : 3, $replyMessagesCount, $isTourist, $kefuInfo['nickname'], $kefuInfo['avatar']);
                $response->connection($connection)->send('reply', $autoReply);
            }
        }
    }

    /**
     * 上下线
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     */
    public function online(TcpConnection &$connection, array $res, Response $response)
    {
        $identity = $this->currentIdentity($connection);
        if ($identity->kind !== 'kefu') throw new AuthSessionException();
        $online = $res['data']['online'] ?? 0;
        $connections = $this->service->user();
        if (isset($connection->user->uid)) {
            $uids = $connection->user->uid;
            /** @var StoreServiceServices $service */
            $service = app()->make(StoreServiceServices::class);
            $service->update(['uid' => $uids], ['online' => $online]);
            //广播给正在和自己聊天的用户
            foreach ($connections as $uid => $conn) {
                if ($uid !== $uids && $uids == ($conn->chatToUid ?? 0)) {
                    $response->connection($conn)->send('online', ['online' => $online, 'uid' => $uids]);
                }
            }
        }
    }

    /**
     * 客服转接
     * @param TcpConnection $connection
     * @param array $res
     * @param Response $response
     */
    public function transfer(TcpConnection &$connection, array $res, Response $response)
    {
        $identity = $this->currentIdentity($connection);
        if ($identity->kind !== 'kefu') throw new AuthSessionException();
        $data = $res['data'] ?? [];
        if ((int)($data['recored']['user_id'] ?? 0) !== $identity->uid) throw new AuthSessionException();
        $uid = $this->roomId($data['recored']['to_uid'] ?? null);
        $tourist = (int)($data['recored']['is_tourist'] ?? 0) === 1;
        $data['recored']['online'] = $this->recipientConnection($identity, $uid, $tourist) !== null ? 1 : 0;
        $response->send('transfer', $data);
    }
}
