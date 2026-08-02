<?php
declare(strict_types=1);

namespace crmeb\compat\think\orm\db\connector;

use PDO;

class Mysql extends \think\db\connector\Mysql
{
    public function getBuilderClass(): string
    {
        return \think\db\builder\Mysql::class;
    }

    public function getRealSql(string $sql, array $bind = []): string
    {
        foreach ($bind as $key => $val) {
            $value = strval(is_array($val) ? $val[0] : $val);
            $type = is_array($val) ? $val[1] : PDO::PARAM_STR;

            if (self::PARAM_FLOAT == $type || PDO::PARAM_STR == $type) {
                $value = '\'' . addslashes($value) . '\'';
            } elseif (PDO::PARAM_INT == $type && '' === $value) {
                $value = '0';
            }

            $sql = is_numeric($key)
                ? substr_replace($sql, $value, strpos($sql, '?'), 1)
                : substr_replace($sql, $value, strpos($sql, ':' . $key), strlen(':' . $key));
        }

        return rtrim($sql);
    }
}
