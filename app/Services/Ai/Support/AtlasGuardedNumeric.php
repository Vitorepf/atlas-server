<?php

namespace App\Services\Ai\Support;

final class AtlasGuardedNumeric
{
    public static function finiteFloat(mixed $v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }

        $float = (float) $v;

        return is_finite($float) ? $float : null;
    }

    public static function saturatingInt(mixed $v, int $min, int $max): int
    {
        $value = self::finiteFloat($v);

        if ($value === null) {
            return $min;
        }

        $asInt = (int) $value;

        if ($asInt < $min) {
            return $min;
        }

        if ($asInt > $max) {
            return $max;
        }

        return $asInt;
    }

    public static function safeString(mixed $v): string
    {
        if (is_scalar($v)) {
            return (string) $v;
        }

        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }

        return '';
    }

    public static function sortedStringList(array $v): array
    {
        $strings = [];

        foreach ($v as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        sort($strings, SORT_STRING);

        return $strings;
    }
}