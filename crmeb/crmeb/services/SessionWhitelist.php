<?php
declare(strict_types=1);

namespace crmeb\services;

class SessionWhitelist
{
    public static function read(string $token): mixed
    {
        return CacheService::get(md5($token));
    }

    public static function matches(mixed $record, string $type, int|string $id, string $token): bool
    {
        return is_array($record)
            && isset($record['type'], $record['uid'], $record['token'])
            && $record['type'] === $type
            && (is_int($record['uid']) || is_string($record['uid']))
            && (string)$record['uid'] === (string)$id
            && is_string($record['token'])
            && hash_equals($record['token'], $token);
    }

    public static function forget(string $token): void
    {
        CacheService::delete(md5($token));
    }
}
