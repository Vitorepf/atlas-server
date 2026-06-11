<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Support;

final class AtlasAaeosArrayFieldReader
{
    /**
     * @param  array<string,mixed>  $row
     */
    public static function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
