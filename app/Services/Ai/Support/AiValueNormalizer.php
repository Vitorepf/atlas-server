<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

final class AiValueNormalizer
{
    public static function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
