<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure text normalize helpers for operator learning signal detection (full-pass peel).
 */
final class OperatorLearningDetectSupport
{
    /**
     * @return list<string>
     */
    public static function sentences(string $input): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+|\n+/u', $input) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    public static function normalizeForMatch(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
