<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

/**
 * Pure compiler that turns outcome learning into dispatch hints that steer
 * muscles toward repair-first, consolidation-first or expansion-first packets
 * based on recent success and give_back patterns.
 *
 * Hint mapping:
 *   - High success rate + low give_back → expansion_first
 *   - High give_back rate → repair_first
 *   - High duplicate work rate → consolidation_first
 *   - Default → balanced
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroOutcomeLearningDispatchHintCompiler
{
    public const SCHEMA = 'atlas.maestro.outcome_learning_dispatch_hint_compiler.v1';

    public const HINT_EXPANSION_FIRST = 'expansion_first';
    public const HINT_REPAIR_FIRST = 'repair_first';
    public const HINT_CONSOLIDATION_FIRST = 'consolidation_first';
    public const HINT_BALANCED = 'balanced';

    private const SUCCESS_RATE_THRESHOLD = 0.7;
    private const GIVE_BACK_RATE_THRESHOLD = 0.3;
    private const DUPLICATE_RATE_THRESHOLD = 0.2;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compile(array $input): array
    {
        $totalOutcomes = max(1, (int) ($input['total_outcomes'] ?? 0));
        $successCount = (int) ($input['success_count'] ?? 0);
        $giveBackCount = (int) ($input['give_back_count'] ?? 0);
        $duplicateCount = (int) ($input['duplicate_count'] ?? 0);

        $successRate = $successCount / $totalOutcomes;
        $giveBackRate = $giveBackCount / $totalOutcomes;
        $duplicateRate = $totalOutcomes > 0 ? $duplicateCount / $totalOutcomes : 0.0;

        $hint = match (true) {
            $giveBackRate >= self::GIVE_BACK_RATE_THRESHOLD => self::HINT_REPAIR_FIRST,
            $duplicateRate >= self::DUPLICATE_RATE_THRESHOLD => self::HINT_CONSOLIDATION_FIRST,
            $successRate >= self::SUCCESS_RATE_THRESHOLD && $giveBackRate < self::GIVE_BACK_RATE_THRESHOLD => self::HINT_EXPANSION_FIRST,
            default => self::HINT_BALANCED,
        };

        $reasons = [];
        if ($hint === self::HINT_REPAIR_FIRST) {
            $reasons[] = 'high_give_back_rate:'.round($giveBackRate, 2);
        }
        if ($hint === self::HINT_CONSOLIDATION_FIRST) {
            $reasons[] = 'high_duplicate_rate:'.round($duplicateRate, 2);
        }
        if ($hint === self::HINT_EXPANSION_FIRST) {
            $reasons[] = 'high_success_rate:'.round($successRate, 2);
            $reasons[] = 'low_give_back_rate:'.round($giveBackRate, 2);
        }
        if ($hint === self::HINT_BALANCED) {
            $reasons[] = 'no_dominant_pattern';
        }

        return [
            'schema_version' => self::SCHEMA,
            'dispatch_hint' => $hint,
            'reasons' => $reasons,
            'success_rate' => round($successRate, 3),
            'give_back_rate' => round($giveBackRate, 3),
            'duplicate_rate' => round($duplicateRate, 3),
            'total_outcomes' => $totalOutcomes,
            'success_count' => $successCount,
            'give_back_count' => $giveBackCount,
            'duplicate_count' => $duplicateCount,
        ];
    }
}
