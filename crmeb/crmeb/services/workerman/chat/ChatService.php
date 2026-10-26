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


use app\services\kefu\service\StoreServiceRecordServices;
use app\services\kefu\service\StoreServiceServices;
use Channel\Client;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\services\workerman\ChannelService;
use crmeb\services\workerman\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class ChatService
{
    private const CLIENT_METHODS = ['kefu_login', 'login', 'guest_login', 'to_chat', 'chat', 'online'];
    /**
     * @var Worker
     */
    protected $worker;

    /**
     * @var TcpConnection[]
     */
    protected $connections = [];

    /**
     * @var TcpConnection[]
     */
    protected $user = [];

    /**
     * 在线客服
     * @var TcpConnection[]
     */
    protected $kefuUser = [];

    /**
     * @var ChatHandle
     */
    protected $handle;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var int
     */
    protected $timer;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
        $this->handle = new ChatHandle($this);
        $this->response = new Response();
    }

    public function setUser(TcpConnection $connection)
    {
        $identity = $this->connectionIdentity($connection);
        if ($identity === null || !in_array($identity->kind, ['api', 'guest'], true)) throw new AuthSessionException();
        $this->user[$identity->uid] = $connection;
    }

    /**
     * 获得当前在线客服
     * @return TcpConnection[]
     */
    public function kefuUser()
    {
        return $this->kefuUser;
    }

    /**
     * 设置当前在线客服
     * @param TcpConnection $connection
     */
    public function setKefuUser(TcpConnection $connection, bool $isUser = true)
    {
        $identity = $this->connectionIdentity($connection);
        if ($identity === null || $identity->kind !== 'kefu'
            || (int)($connection->kefuUser->uid ?? 0) !== $identity->uid) throw new AuthSessionException();
        // Keep the existing argument for callers; staff never overwrite customer connections.
        $this->kefuUser[$identity->uid] = $connection;
    }

    public function user($key = null)
    {
        return $key ? ($this->user[$key] ?? false) : $this->user;
    }

    public function onConnect(TcpConnection $connection)
    {
        var_dump('chatConnect');
        $this->connections[$connection->id] = $connection;
        $connection->lastMessageTime = time();
    }

    public function onMessage(TcpConnection $connection, $res)
    {
        $connection->lastMessageTime = time();
        $res = json_decode($res, true);
        if (!is_array($res) || !isset($res['type']) || !$res['type'] || $res['type'] == 'ping') {
            return $this->response->connection($connection)->success('ping', ['now' => time(), 'datetime' => date('Y-m-d H:i:s')]);
        }
        if (!is_string($res['type']) || !in_array($res['type'], self::CLIENT_METHODS, true)) {
            return $this->response->connection($connection)->close(['msg' => '消息类型不可用']);
        }
        try {
            if (!in_array($res['type'], ['kefu_login', 'login', 'guest_login'], true)
                && $this->connectionIdentity($connection) === null) throw new AuthSessionException();
            $this->handle->{$res['type']}($connection, $res + ['data' => []], $this->response->connection($connection));
        } catch (\Throwable $e) {
            return $this->response->connection($connection)->close(['msg' => '聊天服务暂不可用']);
        }
    }


    public function onWorkerStart(Worker $worker)
    {
        var_dump('chatWorkerStart');

        ChannelService::connet();

        Client::on('crmeb_chat', function ($eventData) {
            if (is_array($eventData)) $this->handleChannelEvent($eventData);
        });

        $this->timer = Timer::add(15, function () use (&$worker) {
            $time_now = time();
            foreach ($worker->connections as $connection) {
                if ($time_now - $connection->lastMessageTime > 120) {
                    $identity = $this->connectionIdentity($connection);
                    $ownsEntry = $identity !== null && $this->ownsIdentity($connection, $identity);
                    //定时器判断当前用户是否下线
                    if ($ownsEntry && $identity->kind !== 'guest') {
                        /** @var StoreServiceRecordServices $service */
                        $service = app()->make(StoreServiceRecordServices::class);
                        $where = ['to_uid' => $identity->uid];
                        if ($identity->kind === 'api') $where['is_tourist'] = 0;
                        $service->updateRecord($where, ['online' => 0]);
                    }
                    $this->response->connection($connection)->close('timeout');
                    //广播给客服谁下线了
                    foreach ($this->kefuUser as $uid => &$conn) {
                        if ($ownsEntry && $identity->kind === 'api' && $identity->uid != $uid) {
                            if (isset($conn->onlineUids) && ($key = array_search($identity->uid, $conn->onlineUids)) !== false) {
                                unset($conn->onlineUids[$key]);
                            }
                            $this->response->connection($conn)->send('user_online', ['to_uid' => $identity->uid, 'online' => 0]);
                        }
                    }
                }
            }
        });

        Timer::add(2, function () use (&$worker) {
            $uids = [];
            foreach ([$this->user, $this->kefuUser] as $connections) {
                foreach ($connections as $connection) {
                    $identity = $this->connectionIdentity($connection);
                    if ($identity !== null && $identity->kind !== 'guest' && $this->ownsIdentity($connection, $identity)) {
                        $uids[$identity->uid] = $identity->uid;
                    }
                }
            }
            if ($uids) {
                //除了当前在线的其他全部都下线
                /** @var StoreServiceRecordServices $service */
                $service = app()->make(StoreServiceRecordServices::class);
                $service->updateOnline(['notUid' => array_values($uids)], ['online' => 0]);
            }
            $kefuUid = array_keys($this->kefuUser());
            if ($kefuUid) {
                /** @var StoreServiceServices $kefuService */
                $kefuService = app()->make(StoreServiceServices::class);
                $kefuService->updateOnline(['notUid' => $kefuUid], ['online' => 0]);
            }
        });
    }

    /** Read only server-established metadata; no payload can install an identity. */
    private function connectionIdentity(TcpConnection $connection): ?ChatIdentity
    {
        $identity = $connection->chatIdentity ?? null;
        return $identity instanceof ChatIdentity && (int)($connection->user->uid ?? 0) === $identity->uid
            ? $identity : null;
    }

    private function ownsIdentity(TcpConnection $connection, ChatIdentity $identity): bool
    {
        return ($identity->kind === 'kefu' ? ($this->kefuUser[$identity->uid] ?? null)
            : ($this->user[$identity->uid] ?? null)) === $connection;
    }

    /** Only the four actual server producers can select this delivery path. */
    private function handleChannelEvent(array $eventData): void
    {
        $type = $eventData['type'] ?? null;
        $data = $eventData['data'] ?? null;
        if (!is_string($type) || !is_array($data)) return;
        if (in_array($type, ['transfer', 'mssage_num', 'reply'], true)) {
            $expectedKind = 'kefu';
            $connections = $this->kefuUser;
        } elseif ($type === 'to_transfer') {
            if (!array_key_exists('is_tourist', $data) || !in_array($data['is_tourist'], [0, 1], true)) return;
            $expectedKind = $data['is_tourist'] === 1 ? 'guest' : 'api';
            $connections = $this->user;
        } else {
            return;
        }
        $ids = $eventData['ids'] ?? array_keys($connections);
        if (!is_array($ids)) return;
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && preg_match('/^-?[1-9][0-9]{0,9}$/D', $id) === 1)) continue;
            $id = (int)$id;
            $connection = $connections[$id] ?? null;
            if (!$connection instanceof TcpConnection) continue;
            $identity = $this->connectionIdentity($connection);
            if ($identity === null || $identity->uid !== $id || $identity->kind !== $expectedKind
                || !$this->ownsIdentity($connection, $identity)) continue;
            try {
                $this->handle->assertCurrentConnection($connection);
                if ($type === 'transfer') {
                    $this->handle->transfer($connection, ['type' => 'transfer', 'data' => $data], $this->response->connection($connection));
                } else {
                    $this->response->connection($connection)->success($type, $data);
                }
            } catch (AuthException $error) {
                if (in_array($error->getCode(), [401, 403], true)) continue;
                $this->response->connection($connection)->close(['msg' => '聊天服务暂不可用']);
            } catch (\Throwable $error) {
                $this->response->connection($connection)->close(['msg' => '聊天服务暂不可用']);
            }
        }
    }


    /** Remove only entries that still belong to this connection. */
    public function detachIdentity(TcpConnection $connection): void
    {
        $uid = $connection->user->uid ?? null;
        if ($uid !== null && ($this->user[$uid] ?? null) === $connection) unset($this->user[$uid]);
        $kefuUid = $connection->kefuUser->uid ?? null;
        if ($kefuUid !== null && ($this->kefuUser[$kefuUid] ?? null) === $connection) unset($this->kefuUser[$kefuUid]);
        unset($connection->chatIdentity, $connection->chatToUid, $connection->chatRoomTourist,
            $connection->isTourist, $connection->kefuUser, $connection->user, $connection->tokenData);
    }

    public function onClose(TcpConnection $connection)
    {
        try {
            var_dump('chatClose');
            unset($this->connections[$connection->id]);
            $this->detachIdentity($connection);
        } finally {
            $this->response->releaseConnection($connection);
        }
    }
}
