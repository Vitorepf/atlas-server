<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Shared difficulty ladder L0–L5 for all elite executors.
 */
final class AaeosDifficultyLevel
{
    public const L0 = 0;

    public const L1 = 1;

    public const L2 = 2;

    public const L3 = 3;

    public const L4 = 4;

    public const L5 = 5;

    public static function isValid(int $level): bool
    {
        return $level >= self::L0 && $level <= self::L5;
    }

    public static function label(int $level): string
    {
        return match ($level) {
            self::L0 => 'L0_typo_rename',
            self::L1 => 'L1_local_bug',
            self::L2 => 'L2_bounded_feature',
            self::L3 => 'L3_cross_module',
            self::L4 => 'L4_multi_day_obra',
            self::L5 => 'L5_frontier',
            default => 'L_invalid',
        };
    }
}
