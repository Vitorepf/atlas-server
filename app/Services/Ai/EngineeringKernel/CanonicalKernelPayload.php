<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final class CanonicalKernelPayload
{
    /** @param array<mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    public static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$key}_required");
        }

        return $value;
    }

    /** @return array<mixed> */
    public static function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException("{$key}_required");
        }

        return $value;
    }

    public static function requireHash(array $data, string $key): string
    {
        $hash = self::requireString($data, $key);
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException("{$key}_invalid_hash");
        }

        return $hash;
    }

    /** @param list<string> $allowed */
    public static function requireEnum(array $data, string $key, array $allowed): string
    {
        $value = self::requireString($data, $key);
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("{$key}_invalid");
        }

        return $value;
    }
}
