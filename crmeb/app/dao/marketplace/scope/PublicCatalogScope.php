<?php
declare(strict_types=1);

namespace app\dao\marketplace\scope;

use app\services\marketplace\publiccatalog\PublicCatalogProjection;

/** Public projections only; publishing and merchant state are service filters. */
final class PublicCatalogScope
{
    public static function fields(string $resource): array
    {
        return PublicCatalogProjection::fields($resource);
    }
}
