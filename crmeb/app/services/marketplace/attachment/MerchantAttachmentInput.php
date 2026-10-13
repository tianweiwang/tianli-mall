<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use app\services\marketplace\MerchantManagementInput as Input;
use think\exception\HttpException;

final class MerchantAttachmentInput
{
    public const ID_MAX = 2147483647;
    public static function id(mixed $value, bool $zero = false): int
    {
        return Input::integer($value, $zero ? 0 : 1, self::ID_MAX, true);
    }
    public static function body(array $body, array $allowed, array $required = []): array
    {
        return Input::body($body, $allowed, $required);
    }
    public static function ids(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || !$value || count($value) > 50) self::invalid();
        $ids = array_values(array_unique(array_map(static fn($id): int => self::id($id), $value)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
    public static function name(mixed $value): string { return Input::text($value, 50, true, true); }
    public static function invalid(): never { throw new HttpException(422, '请求参数不正确'); }
    public static function unavailable(): never { throw new HttpException(503, '素材来源或状态暂无法确认'); }
}
