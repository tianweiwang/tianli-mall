<?php
declare(strict_types=1);

namespace crmeb\compat\think;

use think\Cache;
use think\Config;
use think\Event;
use think\Log;

/**
 * Keep the framework database setup without opening its cache backend at boot.
 */
class Db extends \think\Db
{
    /** Preserve the framework factory's dependency-injection signature. */
    public static function __make(Event $event, Config $config, Log $log, Cache $cache)
    {
        $db = new static();
        $db->setConfig($config);
        $db->setEvent($event);
        $db->setLog($log);

        $store = $db->getConfig('cache_store');
        $db->setCache(new DbCache($cache, $store));
        $db->triggerSql();

        return $db;
    }
}
