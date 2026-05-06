<?php

namespace App\Services\Ai\Programming;

final class ProgrammingIterationPolicy
{
    public const MIN_ITERATIONS = 1;

    public const MAX_ITERATIONS = 10;

    public const DEFAULT_DEV_ITERATIONS = 3;

    public const DEFAULT_SINGLE_PASS_ITERATIONS = 1;

    public const MIN_COMPLETE_ITERATIONS = 2;

    public const DEFAULT_FORGE_ITERATIONS = 5;

    public const MIN_FORGE_ITERATIONS = 5;

    public const MIN_REPAIR_ITERATIONS = 3;

    public static function normalize(
        mixed $value,
        int $default = self::DEFAULT_DEV_ITERATIONS,
        int $minimum = self::MIN_ITERATIONS,
    ): int {
        $requested = is_numeric($value) ? (int) $value : $default;

        return max($minimum, min(self::MAX_ITERATIONS, $requested));
    }

    public static function forProfile(mixed $value, string $profile): int
    {
        return $profile === 'forge'
            ? self::normalize($value, self::DEFAULT_FORGE_ITERATIONS, self::MIN_FORGE_ITERATIONS)
            : self::normalize($value, self::DEFAULT_DEV_ITERATIONS);
    }

    public static function forExecutionPolicy(mixed $value, bool $complete, bool $forge): int
    {
        if ($forge) {
            return self::normalize($value, self::DEFAULT_FORGE_ITERATIONS, self::MIN_FORGE_ITERATIONS);
        }

        if ($complete) {
            return self::normalize($value, self::DEFAULT_DEV_ITERATIONS, self::MIN_COMPLETE_ITERATIONS);
        }

        return self::normalize($value, self::DEFAULT_SINGLE_PASS_ITERATIONS);
    }

    public static function forRepairPolicy(mixed $value, int $fallback = self::DEFAULT_SINGLE_PASS_ITERATIONS): int
    {
        return self::normalize($value, $fallback);
    }
}
