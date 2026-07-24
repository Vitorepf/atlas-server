<?php

declare(strict_types=1);

namespace App\Support;

/** JSON encode with Atlas-stable flags (throw + unescaped unicode/slashes). */
final class StableJson
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, self::FLAGS);
    }

    public static function encodeSoft(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
