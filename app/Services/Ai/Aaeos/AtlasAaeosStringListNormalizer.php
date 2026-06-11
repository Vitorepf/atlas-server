<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosStringListNormalizer
{
    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function uniqueSortedStrings(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }
}
