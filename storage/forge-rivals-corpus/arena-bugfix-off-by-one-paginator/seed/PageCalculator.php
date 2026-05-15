<?php

declare(strict_types=1);

namespace App\Services\Pagination;

/**
 * Initial state with the bug present.
 */
final class PageCalculator
{
    public static function totalPages(int $count, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        // Bug: floor instead of ceil — drops a page when count % limit == 0.
        return (int) floor($count / $limit);
    }
}
