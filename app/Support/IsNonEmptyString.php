<?php

declare(strict_types=1);

namespace App\Support;

final class IsNonEmptyString
{
    public static function check(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
