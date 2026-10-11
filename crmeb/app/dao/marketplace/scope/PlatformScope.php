<?php
declare(strict_types=1);

namespace app\dao\marketplace\scope;

use InvalidArgumentException;
use think\db\Query;
use think\Model;

/** Stores an authorization result; the caller must authorize and audit first. */
final readonly class PlatformScope
{
    public function __construct(public int $adminId, public string $permission, public ?int $targetMerId)
    {
        if ($adminId <= 0 || trim($permission) === '' || ($targetMerId !== null && $targetMerId <= 0)) {
            throw new InvalidArgumentException('Invalid platform scope');
        }
    }

    public function apply(Query|Model $query, string $column = 'mer_id'): Query
    {
        // The permission string is not evidence that authorization was performed.
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $column) !== 1) {
            throw new InvalidArgumentException('Invalid platform scope column');
        }

        $scoped = $query instanceof Model ? $query->db() : clone $query;
        if ($scoped->getOptions('union')) {
            throw new InvalidArgumentException('Platform scope requires a single query');
        }
        $previous = clone $scoped;
        $scoped->removeOption('where');

        if ($previous->getOptions('where')) {
            $scoped->where(static function (Query $group) use ($previous): void {
                $group->where($previous);
            });
        }

        if ($this->targetMerId !== null) {
            return $scoped->where($column, '=', $this->targetMerId);
        }

        // Explicit null represents the caller's authorized, audited global scope.
        return $scoped;
    }
}
