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

namespace crmeb\services\workerman;


use Workerman\Connection\TcpConnection;

class Response
{
    /**
     * @var TcpConnection
     */
    protected $connection;

    /**
     * 设置用户
     *
     * @param TcpConnection $connection
     * @return $this
     */
    public function connection(TcpConnection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /** Release only this exact response target after its original close. */
    public function releaseConnection(TcpConnection $connection): void
    {
        if ($this->connection === $connection) {
            $this->connection = null;
        }
    }

    /**
     * 发送请求
     *
     * @param string $type
     * @param array|null $data
     * @param bool $close
     * @param array $other
     * @return bool|null
     */
    public function send(string $type, ?array $data = null, bool $close = false, array $other = [])
    {
        if (!$this->connection instanceof TcpConnection) return false;
        $this->connection->lastMessageTime = time();
        $res = compact('type');

        if (!is_null($data)) $res['data'] = $data;
        $data = array_merge($res, $other);

        if ($close)
            $data['close'] = true;

        $json = json_encode($data);

        return $close
            ? ($this->connection->close($json))
            : $this->connection->send($json);
    }

    /**
     * 成功
     *
     * @param string $message
     * @param array|null $data
     * @return bool|null
     */
    public function success($type = 'success', ?array $data = null)
    {
        if (is_array($type)) {
            $data = $type;
            $type = 'success';
        }
        return $this->send($type, $data);
    }

    /**
     * 失败
     *
     * @param string $message
     * @param array|null $data
     * @return bool|null
     */
    public function fail($type = 'error', ?array $data = null)
    {
        if (is_array($type)) {
            $data = $type;
            $type = 'error';
        }
        return $this->send($type, $data);
    }

    /**
     * 关闭连接
     *
     * @param string $type
     * @param array|null $data
     * @return bool|null
     */
    public function close($type = 'error', ?array $data = null)
    {
        if (is_array($type)) {
            $data = $type;
            $type = 'error';
        }
        return $this->send($type, $data, true);
    }
}
