<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

final class SelfConstructionImplementationPriorityScorer
{
    private const SCHEMA_VERSION = 'atlas.self_construction.implementation_priority.v1';

    private const FACTOR_FLOOR = 0;

    private const FACTOR_CEILING = 10;

    private const P0_FLOOR = 40;

    private const P1_FLOOR = 26;

    private const P2_FLOOR = 12;

    /**
     * @var list<string>
     */
    private const ADDITIVE_FACTORS = [
        'strategic_leverage',
        'dependency_unlocks',
        'quality_improvement',
        'autonomy_enablement',
        'user_value',
        'evidence_confidence',
    ];

    /**
     * @var list<string>
     */
    private const SUBTRACTIVE_FACTORS = [
        'risk',
        'implementation_size',
        'uncertainty',
        'maintenance_burden',
    ];

    /**
     * @param array<string, mixed> $factors
     *
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     score: int,
     *     p_level: string,
     *     dominant_factor: string,
     *     weakest_factor: string,
     *     clamped_factors: array<string, int>,
     *     additive_total: int,
     *     subtractive_total: int
     * }
     */
    public function score(array $factors): array
    {
        $clamped = [];

        foreach (self::ADDITIVE_FACTORS as $key) {
            $clamped[$key] = $this->clampFactor($factors[$key] ?? 0);
        }

        foreach (self::SUBTRACTIVE_FACTORS as $key) {
            $clamped[$key] = $this->clampFactor($factors[$key] ?? 0);
        }

        $additiveTotal = 0;
        foreach (self::ADDITIVE_FACTORS as $key) {
            $additiveTotal += $clamped[$key];
        }

        $subtractiveTotal = 0;
        foreach (self::SUBTRACTIVE_FACTORS as $key) {
            $subtractiveTotal += $clamped[$key];
        }

        $score = $additiveTotal - $subtractiveTotal;

        $dominantFactor = $this->dominantFactor($clamped);
        $weakestFactor = $this->weakestAdditiveFactor($clamped, $dominantFactor);
        $pLevel = $this->priorityLevel($score);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->mode($pLevel),
            'score' => $score,
            'p_level' => $pLevel,
            'dominant_factor' => $dominantFactor,
            'weakest_factor' => $weakestFactor,
            'clamped_factors' => $clamped,
            'additive_total' => $additiveTotal,
            'subtractive_total' => $subtractiveTotal,
        ];
    }

    private function clampFactor(mixed $value): int
    {
        $numeric = is_numeric($value) ? (int) $value : 0;

        if ($numeric < self::FACTOR_FLOOR) {
            return self::FACTOR_FLOOR;
        }

        if ($numeric > self::FACTOR_CEILING) {
            return self::FACTOR_CEILING;
        }

        return $numeric;
    }

    private function priorityLevel(int $score): string
    {
        if ($score >= self::P0_FLOOR) {
            return 'P0';
        }

        if ($score >= self::P1_FLOOR) {
            return 'P1';
        }

        if ($score >= self::P2_FLOOR) {
            return 'P2';
        }

        return 'P3';
    }

    private function mode(string $pLevel): string
    {
        return match ($pLevel) {
            'P0' => 'foundational',
            'P1' => 'high_leverage',
            'P2' => 'standard',
            default => 'deferred',
        };
    }

    /**
     * Highest-magnitude contributor across every factor (additive or subtractive).
     * Ties resolve to the earliest factor in canonical order.
     *
     * @param array<string, int> $clamped
     */
    private function dominantFactor(array $clamped): string
    {
        $dominant = null;
        $dominantValue = -1;

        foreach (self::ADDITIVE_FACTORS as $key) {
            if ($clamped[$key] > $dominantValue) {
                $dominant = $key;
                $dominantValue = $clamped[$key];
            }
        }

        foreach (self::SUBTRACTIVE_FACTORS as $key) {
            if ($clamped[$key] > $dominantValue) {
                $dominant = $key;
                $dominantValue = $clamped[$key];
            }
        }

        return (string) $dominant;
    }

    /**
     * Lowest-contributing additive factor, excluding the dominant factor so the
     * two named keys are never equal. Ties resolve to the earliest additive
     * factor in canonical order.
     *
     * @param array<string, int> $clamped
     */
    private function weakestAdditiveFactor(array $clamped, string $dominantFactor): string
    {
        $weakest = null;
        $weakestValue = self::FACTOR_CEILING + 1;

        foreach (self::ADDITIVE_FACTORS as $key) {
            if ($key === $dominantFactor) {
                continue;
            }

            if ($clamped[$key] < $weakestValue) {
                $weakest = $key;
                $weakestValue = $clamped[$key];
            }
        }

        return (string) $weakest;
    }
}
