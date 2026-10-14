<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\system\config\SystemConfigDao;
use InvalidArgumentException;

/** Decimal boundaries for the trusted version1 quote, without changing legacy math. */
final class MarketplaceDecimal
{
    public static function value(mixed $value, int $scale = 2): string
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Quote decimal source is unavailable.');
        }
        return bcadd($value, '0', $scale);
    }

    /** Ceil the existing BC2 quotient, rather than changing the shipping formula. */
    public static function ceil(string $quotient): string
    {
        $whole = bcadd($quotient, '0', 0);
        return bccomp($quotient, $whole, 2) > 0 ? bcadd($whole, '1', 0) : $whole;
    }

    public static function greatest(array $values): string
    {
        $max = '0.00';
        foreach ($values as $value) if (bccomp(self::value($value, 6), $max, 6) > 0) $max = self::value($value, 2);
        return $max;
    }

    public static function compare(string $left, string $right): int
    {
        $scale = max(strlen(explode('.', $left, 2)[1] ?? ''), strlen(explode('.', $right, 2)[1] ?? ''), 6);
        return bccomp($left, $right, $scale);
    }

    /** Read the same raw producer as getConfigValue, before its JSON number decoding. */
    public static function configuration(string $name): string
    {
        if ($name !== 'store_free_postage') throw new InvalidArgumentException('Unknown quote decimal configuration.');
        return self::jsonNumber(app()->make(SystemConfigDao::class)->getConfigValue($name));
    }

    public static function jsonNumber(mixed $raw): string
    {
        if ($raw === null || $raw === '' || $raw === 'null' || $raw === 'false' || $raw === '""') return '0.000000';
        if (!is_string($raw)) throw new InvalidArgumentException('Quote decimal configuration source is unavailable.');
        $text = trim($raw);
        if (str_starts_with($text, '"')) {
            $value = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
            if (!is_string($value)) throw new InvalidArgumentException('Quote decimal configuration is unavailable.');
            return self::value($value, max(2, strlen(explode('.', $value, 2)[1] ?? '')));
        }
        if (preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D', $text, $match) !== 1) {
            throw new InvalidArgumentException('Quote decimal configuration is unavailable.');
        }
        // Move the original JSON decimal point with text, never decode a PHP float.
        $exponent = $match[4] ?? '0';
        if (strlen(ltrim($exponent, '+-0')) > 2 || (int)$exponent < -64 || (int)$exponent > 64 || strlen($text) > 256) {
            throw new InvalidArgumentException('Quote decimal configuration exceeds its source bound.');
        }
        $digits = $match[2] . ($match[3] ?? '');
        $point = strlen($match[2]) + (int)$exponent;
        if ($point <= 0) $number = '0.' . str_repeat('0', -$point) . $digits;
        elseif ($point >= strlen($digits)) $number = $digits . str_repeat('0', $point - strlen($digits));
        else $number = substr($digits, 0, $point) . '.' . substr($digits, $point);
        return self::value($match[1] . $number, max(2, strlen(explode('.', $number, 2)[1] ?? '')));
    }
}
