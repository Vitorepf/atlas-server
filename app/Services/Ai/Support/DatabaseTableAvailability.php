<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class DatabaseTableAvailability
{
    public static function has(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    public static function hasColumn(string $table, string $column): bool
    {
        try {
            return self::has($table) && Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $tables
     */
    public static function all(array $tables): bool
    {
        return self::missing($tables) === [];
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    public static function missing(array $tables): array
    {
        $missing = [];
        foreach ($tables as $table) {
            if (! self::has($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }
}
