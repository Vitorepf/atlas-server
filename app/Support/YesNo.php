<?php

declare(strict_types=1);

namespace App\Support;

/** Format truthy values as yes/no or true/false report strings. */
final class YesNo
{
    public static function format(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    public static function trueFalse(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }
}
