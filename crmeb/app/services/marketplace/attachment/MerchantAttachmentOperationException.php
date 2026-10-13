<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

/** Public finite observation only; never carry a driver exception or private locator. */
final class MerchantAttachmentOperationException extends \RuntimeException
{
    public function __construct(public readonly array $receipt)
    {
        parent::__construct('素材操作未完成');
    }
}
