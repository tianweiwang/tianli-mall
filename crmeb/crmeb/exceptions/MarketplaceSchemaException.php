<?php
declare(strict_types=1);

namespace crmeb\exceptions;

/** No driver message, previous exception, configuration or request context is retained. */
final class MarketplaceSchemaException extends \RuntimeException
{
    public const UNAVAILABLE = 'SCHEMA_UNAVAILABLE';
    public const INCOMPATIBLE = 'SCHEMA_INCOMPATIBLE';
    public const IDENTITY = 'RELEASE_IDENTITY_INVALID';

    public function __construct(string $reason)
    {
        if (!in_array($reason, [self::UNAVAILABLE, self::INCOMPATIBLE, self::IDENTITY], true)) {
            $reason = self::UNAVAILABLE;
        }
        parent::__construct($reason, 503);
    }
}
