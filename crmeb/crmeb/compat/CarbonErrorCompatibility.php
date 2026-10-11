<?php
declare(strict_types=1);

namespace crmeb\compat;

/** Keep legacy Carbon notices from aborting PHP 8 queue class loading. */
final class CarbonErrorCompatibility
{
    public static function install(string $sourceDirectory): void
    {
        $sourcePrefix = rtrim(str_replace('\\', '/', $sourceDirectory), '/') . '/';
        $previous = null;
        $previous = set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use ($sourcePrefix, &$previous) {
            if ($type === E_DEPRECATED && str_starts_with(str_replace('\\', '/', $file), $sourcePrefix)) {
                return true;
            }

            return is_callable($previous) ? $previous($type, $message, $file, $line) : false;
        });
    }
}
