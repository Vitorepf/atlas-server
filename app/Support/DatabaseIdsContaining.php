<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

/**
 * Find row ids where any of the given columns LIKE any needle.
 *
 * Full-pass reuse: de-duplicates private idsContaining() on Capture/Thread deletion services.
 *
 * @param  list<string>  $columns
 * @param  list<string>  $needles
 * @return list<string>
 */
final class DatabaseIdsContaining
{
    public static function query(string $table, array $columns, array $needles): array
    {
        if ($needles === [] || ! DatabaseTableAvailability::hasColumn($table, 'id')) {
            return [];
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, $needles, &$matched): void {
            foreach ($columns as $column) {
                if (! DatabaseTableAvailability::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($needles as $needle) {
                    $matched = true;
                    $where->orWhere($column, 'like', '%'.$needle.'%');
                }
            }
        });

        if (! $matched) {
            return [];
        }

        return $query
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }
}
