<?php
declare(strict_types=1);

namespace app\services\marketplace;

use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use InvalidArgumentException;
use think\exception\HttpException;
use Throwable;

/** Finite input and driver classification shared by the two management services. */
final class MerchantManagementInput
{
    public static function body(array $input, array $allowed, array $required = []): array
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) self::invalid();
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $input)) self::invalid();
        }
        return $input;
    }

    public static function integer(mixed $value, int $min, int $max, bool $allowString = false): int
    {
        if ($allowString && is_string($value) && strlen($value) <= 10
            && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < $min || $value > $max) self::invalid();
        return $value;
    }

    public static function text(mixed $value, int $max, bool $nonempty = false, bool $trim = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) self::invalid();
        if ($trim) $value = trim($value);
        if (($nonempty && $value === '') || mb_strlen($value, 'UTF-8') > $max) self::invalid();
        return $value;
    }

    public static function permissions(mixed $value): array
    {
        if (!is_array($value)) self::invalid();
        try {
            return MerchantPermissions::validate($value);
        } catch (InvalidArgumentException $error) {
            self::invalid();
        }
    }

    /** Call inside the guard's database callback, before driver details are discarded. */
    public static function write(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpException|AuthException $error) {
            throw $error;
        } catch (Throwable $error) {
            $state = null; $code = null;
            if ($error instanceof \PDOException) {
                $state = $error->errorInfo[0] ?? null;
                $code = $error->errorInfo[1] ?? null;
            } elseif ($error instanceof \think\db\exception\PDOException) {
                $info = $error->getData()['PDO Error Info'] ?? [];
                $state = $info['SQLSTATE'] ?? null;
                $code = $info['Driver Error Code'] ?? null;
            }
            if ($state === '23000' && (is_int($code) || is_string($code)) && (string)$code === '1062') {
                throw new HttpException(422, '数据已存在');
            }
            throw new AuthUnavailableException();
        }
    }

    private static function invalid(): never
    {
        throw new HttpException(422, '请求参数不正确');
    }
}
