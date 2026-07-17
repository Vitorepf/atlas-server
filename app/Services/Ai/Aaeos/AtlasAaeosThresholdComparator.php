<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasAaeosThresholdComparator
{
    private const EPSILON = 1e-9;

    public static function binarySatisfied(string $comparator, float $observed, float $threshold): bool
    {
        return match (self::normalizeComparator($comparator)) {
            '>=' => $observed + self::EPSILON >= $threshold,
            '<=' => $observed - self::EPSILON <= $threshold,
            default => false,
        };
    }

    public static function satisfied(string $comparator, float $observed, float $threshold): bool
    {
        return match (self::normalizeComparator($comparator)) {
            '>=' => $observed >= $threshold - self::EPSILON,
            '<=' => $observed <= $threshold + self::EPSILON,
            '>' => $observed > $threshold,
            '<' => $observed < $threshold,
            '==' => abs($observed - $threshold) <= self::EPSILON,
            default => false,
        };
    }

    private static function normalizeComparator(string $comparator): string
    {
        return AiValueNormalizer::trimmedStringOrNull($comparator) ?? '';
    }
}
