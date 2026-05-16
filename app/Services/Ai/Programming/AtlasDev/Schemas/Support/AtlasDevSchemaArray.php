<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Support;

use InvalidArgumentException;

final class AtlasDevSchemaArray
{
    public static function string(array $payload, string $key): string
    {
        self::ensureKey($payload, $key);
        $value = $payload[$key];

        if (! is_string($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a string.");
        }

        return $value;
    }

    public static function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        $value = $payload[$key];

        if (! is_string($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a string or null.");
        }

        return $value;
    }

    public static function int(array $payload, string $key): int
    {
        self::ensureKey($payload, $key);
        $value = $payload[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an integer.");
        }

        return $value;
    }

    public static function nullableInt(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        $value = $payload[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an integer or null.");
        }

        return $value;
    }

    public static function bool(array $payload, string $key): bool
    {
        self::ensureKey($payload, $key);
        $value = $payload[$key];

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a boolean.");
        }

        return $value;
    }

    public static function nullableBool(array $payload, string $key): ?bool
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        $value = $payload[$key];

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a boolean or null.");
        }

        return $value;
    }

    public static function stringList(array $payload, string $key): array
    {
        self::ensureKey($payload, $key);
        $value = $payload[$key];

        if (! is_array($value) || (! array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("Field '{$key}' must be a list of strings.");
        }

        $items = [];
        foreach ($value as $i => $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Field '{$key}[{$i}]' must be a string.");
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function ensureKey(array $payload, string $key): void
    {
        if (! array_key_exists($key, $payload)) {
            throw new InvalidArgumentException("Field '{$key}' is required.");
        }
    }
}
