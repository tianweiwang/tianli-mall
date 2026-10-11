<?php
declare(strict_types=1);

namespace app\dao\marketplace\scope;

use InvalidArgumentException;

/** Public projections only; publishing and merchant state are service filters. */
final class PublicCatalogScope
{
    private const FIELDS = [
        'merchant' => ['mer_id', 'name', 'logo', 'after_sale_contact', 'shipping_note'],
        'product' => [
            'id', 'mer_id', 'store_name', 'store_info', 'image', 'slider_image',
            'price', 'ot_price', 'vip_price', 'unit_name', 'sales', 'stock',
            'spec_type', 'is_postage', 'postage', 'is_vip', 'video_link',
        ],
    ];

    public static function fields(string $resource): array
    {
        if (!array_key_exists($resource, self::FIELDS)) {
            throw new InvalidArgumentException('Unknown public catalog resource');
        }

        return self::FIELDS[$resource];
    }
}
