<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Support;

final class AtlasAaeosValueNormalizer
{
    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
