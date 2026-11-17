<?php

namespace App\Domain\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/** Transitional selectors remain deployable before the additive tombstone migration. */
final class CatalogAvailability
{
    private static array $available = [];

    public static function filter(Builder $query, string $table, ?string $alias = null): Builder
    {
        if ((self::$available[$table] ?? false) || Schema::hasColumn($table, 'deleted_at')) {
            self::$available[$table] = true;
            $query->whereNull(($alias ?? $table).'.deleted_at');
        }

        return $query;
    }

    public static function assertWritable(?object $row, string $reason = 'CATALOG_DELETED'): void
    {
        if ($row && ($row->deleted_at ?? null) !== null) {
            throw new Conflict($reason, 'Mục này đã được xóa khỏi danh mục. Hãy tải lại danh sách.');
        }
    }
}
