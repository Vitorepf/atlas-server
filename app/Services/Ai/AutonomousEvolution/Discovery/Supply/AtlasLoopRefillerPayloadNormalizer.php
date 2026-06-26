<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

/**
 * Pure, stateless payload-normalization helpers for the Atlas loop queue
 * refiller.
 *
 * Extracted from AtlasLoopQueueRefiller to reduce the god-class.
 */
final class AtlasLoopRefillerPayloadNormalizer
{
    public static function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** @return list<array<string,mixed>> */
    public static function arrayList(mixed $values): array
    {
        return array_values(array_filter((array) $values, 'is_array'));
    }

    /** @return list<string> */
    public static function stringList(mixed $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), (array) $values), static fn (string $v): bool => $v !== ''));
    }

    /** @return array<string,mixed> */
    public static function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
