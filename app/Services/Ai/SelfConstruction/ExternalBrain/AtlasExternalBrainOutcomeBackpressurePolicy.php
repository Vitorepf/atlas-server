<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure backpressure policy: evaluates a task family's outcome history and
 * recommends whether to promote, continue, respec, block, or retire it.
 *
 * DECISION PRIORITY (first match wins):
 *   1. retire   — poison_rate > retire_threshold (default 0.30)
 *   2. block    — quarantine_count > 0
 *   3. respec   — give_back_rate > respec_threshold (default 0.30)
 *   4. promote  — success_rate >= promote_threshold (default 0.75)
 *   5. continue — default (not enough signal yet)
 *
 * confidence_score = success_count / max(1, total_count)
 * safe_to_promote  = recommendation ∈ {promote, continue}
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainOutcomeBackpressurePolicy
{
    public const SCHEMA = 'atlas.external_brain.outcome_backpressure_policy.v1';

    public const RECOMMENDATION_PROMOTE  = 'promote';
    public const RECOMMENDATION_CONTINUE = 'continue';
    public const RECOMMENDATION_RESPEC   = 'respec';
    public const RECOMMENDATION_BLOCK    = 'block';
    public const RECOMMENDATION_RETIRE   = 'retire';

    public const OUTCOME_SUCCESS    = 'success';
    public const OUTCOME_GIVE_BACK  = 'give_back';
    public const OUTCOME_QUARANTINE = 'quarantine';
    public const OUTCOME_POISON     = 'poison';

    private const DEFAULT_PROMOTE_THRESHOLD = 0.75;
    private const DEFAULT_RESPEC_THRESHOLD  = 0.30;
    private const DEFAULT_RETIRE_THRESHOLD  = 0.30;

    public const PACE_CONTINUE = 'continue';
    public const PACE_SLOW_DOWN = 'slow_down';
    public const PACE_SWITCH_LANE = 'switch_lane';
    public const PACE_CONSOLIDATE = 'consolidate';
    public const PACE_STOP_ENQUEUE = 'stop_enqueue';

    private const DEFAULT_GIVE_BACK_STOP_CEILING = 0.50;
    private const DEFAULT_POISON_CLASS_STOP_FLOOR = 2;
    private const DEFAULT_SATURATION_RATIO_CEILING = 5.0;
    private const DEFAULT_LOW_LIFT_CEILING = 0.40;
    private const DEFAULT_GIVE_BACK_SLOW_CEILING = 0.25;

    private const PACE_ENQUEUE_VOLUME_MULTIPLIER = [
        self::PACE_CONTINUE => 1.0,
        self::PACE_SLOW_DOWN => 0.5,
        self::PACE_SWITCH_LANE => 0.25,
        self::PACE_CONSOLIDATE => 0.1,
        self::PACE_STOP_ENQUEUE => 0.0,
    ];

    /**
     * Origination-pace backpressure: should the originator keep creating new
     * tasks at full volume, slow down, switch which lane it harvests, fold
     * back into consolidation, or stop enqueueing entirely?
     *
     * DECISION PRIORITY (first match wins; AC3 — quality deterioration NEVER
     * leaves enqueue_volume_multiplier at 1.0):
     *   1. stop_enqueue  — poison_class_count >= poison_class_stop_floor, OR
     *                      give_back_rate >= give_back_stop_ceiling
     *   2. consolidate   — queue_depth / max(1, active_muscle_capacity) >
     *                      saturation_ratio_ceiling (queue saturated relative
     *                      to what muscles can actually absorb)
     *   3. switch_lane   — low_lift_commit_rate >= low_lift_ceiling (commits
     *                      are landing but not delivering real value)
     *   4. slow_down     — give_back_rate >= give_back_slow_ceiling
     *   5. continue      — default, healthy
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluateOriginationPace(array $input): array
    {
        $queueDepth = max(0, (int) ($input['queue_depth'] ?? 0));
        $activeMuscleCapacity = max(0, (int) ($input['active_muscle_capacity'] ?? 0));
        $giveBackRate = (float) ($input['give_back_rate'] ?? 0.0);
        $lowLiftCommitRate = (float) ($input['low_lift_commit_rate'] ?? 0.0);
        $poisonClassCount = max(0, (int) ($input['poison_class_count'] ?? 0));

        $giveBackStopCeiling = (float) ($input['give_back_stop_ceiling'] ?? self::DEFAULT_GIVE_BACK_STOP_CEILING);
        $poisonClassStopFloor = (int) ($input['poison_class_stop_floor'] ?? self::DEFAULT_POISON_CLASS_STOP_FLOOR);
        $saturationRatioCeiling = (float) ($input['saturation_ratio_ceiling'] ?? self::DEFAULT_SATURATION_RATIO_CEILING);
        $lowLiftCeiling = (float) ($input['low_lift_ceiling'] ?? self::DEFAULT_LOW_LIFT_CEILING);
        $giveBackSlowCeiling = (float) ($input['give_back_slow_ceiling'] ?? self::DEFAULT_GIVE_BACK_SLOW_CEILING);

        $saturationRatio = round($queueDepth / max(1, $activeMuscleCapacity), 4);

        $evidence = [];

        $shouldStop = $poisonClassCount >= $poisonClassStopFloor || $giveBackRate >= $giveBackStopCeiling;
        if ($poisonClassCount >= $poisonClassStopFloor) {
            $evidence[] = sprintf('poison_class_count=%d >= floor=%d', $poisonClassCount, $poisonClassStopFloor);
        }
        if ($giveBackRate >= $giveBackStopCeiling) {
            $evidence[] = sprintf('give_back_rate=%.2f >= stop_ceiling=%.2f', $giveBackRate, $giveBackStopCeiling);
        }

        $shouldConsolidate = $saturationRatio > $saturationRatioCeiling;
        if ($shouldConsolidate) {
            $evidence[] = sprintf('saturation_ratio=%.2f (queue_depth=%d / capacity=%d) > ceiling=%.2f', $saturationRatio, $queueDepth, $activeMuscleCapacity, $saturationRatioCeiling);
        }

        $shouldSwitchLane = $lowLiftCommitRate >= $lowLiftCeiling;
        if ($shouldSwitchLane) {
            $evidence[] = sprintf('low_lift_commit_rate=%.2f >= ceiling=%.2f', $lowLiftCommitRate, $lowLiftCeiling);
        }

        $shouldSlowDown = $giveBackRate >= $giveBackSlowCeiling;
        if ($shouldSlowDown) {
            $evidence[] = sprintf('give_back_rate=%.2f >= slow_ceiling=%.2f', $giveBackRate, $giveBackSlowCeiling);
        }

        $decision = match (true) {
            $shouldStop => self::PACE_STOP_ENQUEUE,
            $shouldConsolidate => self::PACE_CONSOLIDATE,
            $shouldSwitchLane => self::PACE_SWITCH_LANE,
            $shouldSlowDown => self::PACE_SLOW_DOWN,
            default => self::PACE_CONTINUE,
        };

        if ($evidence === []) {
            $evidence[] = 'all signals within healthy bounds';
        }

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'enqueue_volume_multiplier' => self::PACE_ENQUEUE_VOLUME_MULTIPLIER[$decision],
            'evidence' => $evidence,
            'metrics' => [
                'queue_depth' => $queueDepth,
                'active_muscle_capacity' => $activeMuscleCapacity,
                'saturation_ratio' => $saturationRatio,
                'give_back_rate' => $giveBackRate,
                'low_lift_commit_rate' => $lowLiftCommitRate,
                'poison_class_count' => $poisonClassCount,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $taskFamily     = (string) ($input['task_family'] ?? 'unknown');
        $history        = is_array($input['outcome_history'] ?? null) ? $input['outcome_history'] : [];
        $promoteThresh  = (float) ($input['promote_threshold'] ?? self::DEFAULT_PROMOTE_THRESHOLD);
        $respecThresh   = (float) ($input['respec_threshold']  ?? self::DEFAULT_RESPEC_THRESHOLD);
        $retireThresh   = (float) ($input['retire_threshold']  ?? self::DEFAULT_RETIRE_THRESHOLD);

        // Tally outcomes.
        $counts = [
            self::OUTCOME_SUCCESS    => 0,
            self::OUTCOME_GIVE_BACK  => 0,
            self::OUTCOME_QUARANTINE => 0,
            self::OUTCOME_POISON     => 0,
        ];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $outcome = (string) ($entry['outcome'] ?? '');
            if (array_key_exists($outcome, $counts)) {
                $counts[$outcome]++;
            }
        }

        $total          = max(1, array_sum($counts));
        $successRate    = $counts[self::OUTCOME_SUCCESS]    / $total;
        $giveBackRate   = $counts[self::OUTCOME_GIVE_BACK]  / $total;
        $poisonRate     = $counts[self::OUTCOME_POISON]     / $total;
        $quarantineCount = $counts[self::OUTCOME_QUARANTINE];
        $confidenceScore = round($counts[self::OUTCOME_SUCCESS] / $total, 4);

        $recommendation = match (true) {
            $poisonRate     > $retireThresh  => self::RECOMMENDATION_RETIRE,
            $quarantineCount > 0             => self::RECOMMENDATION_BLOCK,
            $giveBackRate   > $respecThresh  => self::RECOMMENDATION_RESPEC,
            $successRate   >= $promoteThresh => self::RECOMMENDATION_PROMOTE,
            default                          => self::RECOMMENDATION_CONTINUE,
        };

        $safeToPromote = in_array($recommendation, [self::RECOMMENDATION_PROMOTE, self::RECOMMENDATION_CONTINUE], true);

        return [
            'schema'          => self::SCHEMA,
            'task_family'     => $taskFamily,
            'recommendation'  => $recommendation,
            'confidence_score' => $confidenceScore,
            'safe_to_promote' => $safeToPromote,
            'outcome_summary' => $counts,
        ];
    }
}
