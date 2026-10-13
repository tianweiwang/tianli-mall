<?php
declare(strict_types=1);
namespace app\services\marketplace\freight;

/** Fixed public observation only; no previous exception, rows, headers, arguments or driver messages are emitted. */
final class MerchantFreightException extends \RuntimeException
{
    public function __construct(int $status=503)
    {
        if ($status!==422) $status=503;
        parent::__construct($status===422?'商品运费模板或配送规则不可用':'运费配置或资料暂无法确认',$status);
    }
}
