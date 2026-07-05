<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Picks the next autonomy leverage point from queue, outcome,
 * learning and readiness facts.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasStrategyCouncilAutonomyLeveragePicker
{
    public const SCHEMA = 'atlas.self_construction.strategy_council_autonomy_leverage_picker.v1';

    public const LEVERAGE_READINESS_BLOCKER = 'readiness_blocker';
    public const LEVERAGE_OUTCOME_REGRESSION = 'outcome_regression';
    public const LEVERAGE_QUEUE_DAMAGE = 'queue_damage';
    public const LEVERAGE_LEARNING_GAP = 'learning_gap';
    public const LEVERAGE_STEADY_STATE = 'steady_state';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function pick(array $input): array
    {
        $readinessBlockers = (int) ($input['readiness_blocker_count'] ?? 0);
        $outcomeRegressions = (int) ($input['outcome_regression_count'] ?? 0);
        $queueDamage = (int) ($input['queue_damage_count'] ?? 0);
        $learningGaps = (int) ($input['learning_gap_count'] ?? 0);

        $candidates = [];

        if ($readinessBlockers > 0) {
            $candidates[] = [
                'leverage' => self::LEVERAGE_READINESS_BLOCKER,
                'priority' => 100,
                'reason' => 'readiness_blockers:'.$readinessBlockers,
            ];
        }
        if ($outcomeRegressions > 0) {
            $candidates[] = [
                'leverage' => self::LEVERAGE_OUTCOME_REGRESSION,
                'priority' => 90,
                'reason' => 'outcome_regressions:'.$outcomeRegressions,
            ];
        }
        if ($queueDamage > 0) {
            $candidates[] = [
                'leverage' => self::LEVERAGE_QUEUE_DAMAGE,
                'priority' => 80,
                'reason' => 'queue_damage:'.$queueDamage,
            ];
        }
        if ($learningGaps > 0) {
            $candidates[] = [
                'leverage' => self::LEVERAGE_LEARNING_GAP,
                'priority' => 50,
                'reason' => 'learning_gaps:'.$learningGaps,
            ];
        }

        if ($candidates === []) {
            $candidates[] = [
                'leverage' => self::LEVERAGE_STEADY_STATE,
                'priority' => 10,
                'reason' => 'no_blocking_leverage',
            ];
        }

        // Sort by priority descending
        usort($candidates, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return [
            'schema' => self::SCHEMA,
            'top_leverage' => $candidates[0]['leverage'],
            'top_reason' => $candidates[0]['reason'],
            'ranked_leverages' => $candidates,
            'candidate_count' => count($candidates),
        ];
    }
}
