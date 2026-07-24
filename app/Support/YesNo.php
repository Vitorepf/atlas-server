<?php

declare(strict_types=1);

namespace App\Support;

/** Format truthy values as yes/no. */
final class YesNo
{
    public static function format(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
