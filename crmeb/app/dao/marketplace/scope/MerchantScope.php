<?php
declare(strict_types=1);

namespace app\dao\marketplace\scope;

use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\MerchantNotFoundException;
use InvalidArgumentException;
use think\db\Query;
use think\Model;

/** A repository must finish its business filters before applying this scope. */
final class MerchantScope
{
    public static function apply(Query|Model $query, MerchantContext $ctx, string $column = 'mer_id'): Query
    {
        self::assertColumn($column);
        $scoped = $query instanceof Model ? $query->db() : clone $query;
        if ($scoped->getOptions('union')) {
            throw new InvalidArgumentException('Merchant scope requires a single query');
        }
        $previous = clone $scoped;
        $scoped->removeOption('where');

        if ($previous->getOptions('where')) {
            $scoped->where(static function (Query $group) use ($previous): void {
                // where(Query) copies the original conditions and their bindings.
                $group->where($previous);
            });
        }

        // Do not append top-level OR, replace WHERE, or UNION after this call.
        return $scoped->where($column, '=', $ctx->merId);
    }

    public static function assertOwned(array|Model|null $row, MerchantContext $ctx, string $column = 'mer_id'): void
    {
        self::assertColumn($column);
        $data = $row instanceof Model ? $row->getData() : $row;
        // SQL aliases qualify columns, but hydrated rows expose their field name.
        $separator = strrpos($column, '.');
        $field = $separator === false ? $column : substr($column, $separator + 1);
        $owner = is_array($data) && array_key_exists($field, $data) ? $data[$field] : null;

        if ($owner === $ctx->merId || (is_string($owner) && $owner === (string) $ctx->merId)) {
            return;
        }

        throw new MerchantNotFoundException();
    }

    private static function assertColumn(string $column): void
    {
        // Callers supply a fixed identifier, never a request-selected expression.
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $column) !== 1) {
            throw new InvalidArgumentException('Invalid merchant scope column');
        }
    }
}
