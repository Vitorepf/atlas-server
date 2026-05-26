<?php

namespace App\Support;

use stdClass;

class Metadata
{
    public static function forStorage(mixed $value): array|stdClass
    {
        if ($value === null || $value === []) {
            return new stdClass;
        }

        return $value;
    }

    public static function forResponse(mixed $value): array|stdClass
    {
        if ($value === null || $value === []) {
            return new stdClass;
        }

        return $value;
    }

    public static function listForResponse(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }
}
