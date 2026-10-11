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

namespace crmeb\utils;


use crmeb\exceptions\AdminException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\CacheService;
use Firebase\JWT\JWT;

/**
 * Jwt
 * Class JwtAuth
 * @package crmeb\utils
 */
class JwtAuth
{

    /**
     * token
     * @var string
     */
    protected $token;

    /**
     * 获取token
     * @param int|string $id
     * @param string $type
     * @param array $params
     * @return array
     */
    public function getToken($id, string $type, array $params = []): array
    {
        $host = app()->request->host();
        $time = time();
        $exp_time = strtotime('+ 30day');
        $params += [
            'iss' => $host,
            'aud' => $host,
            'iat' => $time,
            'nbf' => $time,
            'exp' => $exp_time,
        ];
        $params['jti'] = compact('id', 'type');
        $token = JWT::encode($params, AuthConfigGuard::signingKey(), 'HS256');

        return compact('token', 'params');
    }

    /**
     * 解析token
     * @param string $jwt
     * @return array
     */
    public function parseToken(string $jwt): array
    {
        $this->token = $jwt;
        list($headb64, $bodyb64, $cryptob64) = explode('.', $this->token);
        $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
        return [$payload->jti->id, $payload->jti->type, $payload->pwd ?? ''];
    }

    /** Verify this JWT without using or changing the legacy token property. */
    public function decodeVerified(string $jwt): array
    {
        JWT::$leeway = 60;
        $payload = JWT::decode($jwt, AuthConfigGuard::signingKey(), ['HS256']);
        if (!isset($payload->jti) || !is_object($payload->jti)
            || !isset($payload->jti->id, $payload->jti->type)
            || (!is_int($payload->jti->id) && !is_string($payload->jti->id))
            || !is_scalar($payload->jti->type)
            || (isset($payload->pwd) && !is_scalar($payload->pwd))) {
            throw new \UnexpectedValueException('Invalid JWT identity claims');
        }
        return ['id' => $payload->jti->id, 'type' => (string)$payload->jti->type, 'pwd' => (string)($payload->pwd ?? '')];
    }

    /**
     * 验证token
     */
    public function verifyToken()
    {
        JWT::$leeway = 60;

        JWT::decode($this->token, AuthConfigGuard::signingKey(), array('HS256'));

        $this->token = null;
    }

    /**
     * 获取token并放入令牌桶
     * @param $id
     * @param string $type
     * @param array $params
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function createToken($id, string $type, array $params = [])
    {
        $tokenInfo = $this->getToken($id, $type, $params);
        $exp = $tokenInfo['params']['exp'] - $tokenInfo['params']['iat'] + 60;
        $res = CacheService::set(md5($tokenInfo['token']), ['uid' => $id, 'type' => $type, 'token' => $tokenInfo['token'], 'exp' => $exp], (int)$exp, $type);
        if (!$res) {
            throw new AdminException('保存token失败');
        }
        return $tokenInfo;
    }
}
