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

    public const PRESSURE_INCREASE_GENERATION = 'increase_generation';
    public const PRESSURE_DECREASE_GENERATION = 'decrease_generation';
    public const PRESSURE_NEUTRAL = 'neutral';

    private const STARVATION_OUTCOMES = ['no_claimable_task', 'no_self_sufficient_task'];

    public const ESCALATION_REPLENISH_OR_REPAIR = 'replenish_or_repair';
    public const ESCALATION_WAIT_OBSERVE = 'wait_observe';

    private const DEFAULT_CLAIMABLE_PER_WORKER_FLOOR = 2.0;

    /**
     * Worker starvation is a FIRST-CLASS escalation input — fresh evidence (a recent
     * no_claimable_task/no_self_sufficient_task outcome, or claimable_per_active_worker at/below
     * the floor) forces replenish_or_repair REGARDLESS of how good historical success metrics
     * look. A high historical success_rate must never mask an active starvation incident; stale
     * success can't certify that workers are fed right now.
     *
     * @param  array<string,mixed>  $facts
     *         recent_outcomes             : list<array{outcome:string}>
     *         claimable_per_active_worker : float
     *         claimable_per_worker_floor  : float  optional override (default 2.0)
     *         historical_success_rate     : float  0..1, informational only — never overrides starvation
     * @return array<string,mixed>
     */
    public function evaluateWorkerStarvationEscalation(array $facts): array
    {
        $recentOutcomes = is_array($facts['recent_outcomes'] ?? null) ? $facts['recent_outcomes'] : [];
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $facts)
            ? (float) $facts['claimable_per_active_worker']
            : null;
        $floor = (float) ($facts['claimable_per_worker_floor'] ?? self::DEFAULT_CLAIMABLE_PER_WORKER_FLOOR);

        $freshStarvationOutcome = false;
        foreach ($recentOutcomes as $entry) {
            $outcome = is_array($entry) ? (string) ($entry['outcome'] ?? '') : (string) $entry;
            if (in_array($outcome, self::STARVATION_OUTCOMES, true)) {
                $freshStarvationOutcome = true;
                break;
            }
        }

        $belowWorkerFloor = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= $floor;
        $starvationEvidenceFresh = $freshStarvationOutcome || $belowWorkerFloor;

        if ($starvationEvidenceFresh) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::ESCALATION_REPLENISH_OR_REPAIR,
                'reason' => 'worker_starvation_evidence_fresh',
                'fresh_starvation_outcome' => $freshStarvationOutcome,
                'below_worker_floor' => $belowWorkerFloor,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'decision' => self::ESCALATION_WAIT_OBSERVE,
            'reason' => 'worker_floor_healthy_no_fresh_starvation_evidence',
            'fresh_starvation_outcome' => false,
            'below_worker_floor' => false,
        ];
    }

    /**
     * Repeated no_claimable_task / no_self_sufficient_task outcomes are queue-starvation feedback,
     * NOT a neutral "nothing to do" signal — they mean the originator must generate MORE work, so
     * pressure increases. Real verification failures stay on the existing failure-pressure path
     * (the worker found something to do and it broke), never conflated with starvation.
     *
     * DECISION PRIORITY (first match wins):
     *   1. increase_generation — any recent starvation outcome (no_claimable_task / no_self_sufficient_task)
     *   2. decrease_generation — any recent verification_failed outcome
     *   3. neutral             — default
     *
     * @param  array<string,mixed>  $input
     *         recent_outcomes : list<array{outcome:string}>
     * @return array<string,mixed>
     */
    public function evaluateOutcomePressure(array $input): array
    {
        $recentOutcomes = is_array($input['recent_outcomes'] ?? null) ? $input['recent_outcomes'] : [];

        $starvationCount = 0;
        $verificationFailedCount = 0;
        foreach ($recentOutcomes as $entry) {
            $outcome = is_array($entry) ? (string) ($entry['outcome'] ?? '') : (string) $entry;
            if (in_array($outcome, self::STARVATION_OUTCOMES, true)) {
                $starvationCount++;
            } elseif ($outcome === 'verification_failed') {
                $verificationFailedCount++;
            }
        }

        [$pressure, $reason] = match (true) {
            $starvationCount > 0 => [self::PRESSURE_INCREASE_GENERATION, 'worker_starvation_feedback'],
            $verificationFailedCount > 0 => [self::PRESSURE_DECREASE_GENERATION, 'verification_failure_pressure'],
            default => [self::PRESSURE_NEUTRAL, 'no_pressure_signal'],
        };

        return [
            'schema' => self::SCHEMA,
            'pressure' => $pressure,
            'reason' => $reason,
            'starvation_count' => $starvationCount,
            'verification_failed_count' => $verificationFailedCount,
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
        $successCount   = $counts[self::OUTCOME_SUCCESS];
        $giveBackCount  = $counts[self::OUTCOME_GIVE_BACK];
        $successRate    = $successCount / $total;
        $giveBackRate   = $giveBackCount / $total;
        $poisonRate     = $counts[self::OUTCOME_POISON]     / $total;
        $quarantineCount = $counts[self::OUTCOME_QUARANTINE];
        $confidenceScore = round($successCount / $total, 4);

        // Wilson score lower bound (95% confidence) — gates promotion on statistical
        // certainty rather than raw success rate, so 1/1 (LB ≈ 0.21) and 3/3 (LB ≈ 0.44)
        // won't promote even though raw successRate is 1.0, while 30/33 (LB ≈ 0.78)
        // still promotes when the threshold is 0.75.
        $wilsonLower = $this->wilsonLowerBound($successCount, $total);

        $recommendation = match (true) {
            $poisonRate     > $retireThresh  => self::RECOMMENDATION_RETIRE,
            $quarantineCount > 0             => self::RECOMMENDATION_BLOCK,
            $giveBackRate   > $respecThresh  => self::RECOMMENDATION_RESPEC,
            $wilsonLower   >= $promoteThresh => self::RECOMMENDATION_PROMOTE,
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

    /**
     * Quality backpressure: distinguishes quality-driven pacing from idle waiting.
     * When valuable targets remain, recommends smaller or better batches instead of no work.
     * Escalates to repair when give_back, poison, or low-commit-yield signals exceed thresholds.
     *
     * @param  array<string,mixed>  $input
     * @return array{quality_backpressure:string, idle_waiting:bool, recommend_smaller_batch:bool, recommend_better_batch:bool, valuable_targets_remaining:bool, repair_escalation:bool, reason:string}
     */
    public function qualityBackpressure(array $input): array
    {
        $giveBackRate = max(0.0, min(1.0, (float) ($input['give_back_rate'] ?? 0.0)));
        $poisonRate = max(0.0, min(1.0, (float) ($input['poison_rate'] ?? 0.0)));
        $commitYield = max(0.0, min(1.0, (float) ($input['commit_yield'] ?? 1.0)));
        $valuableTargetsRemaining = (bool) ($input['valuable_targets_remaining'] ?? false);
        $queueDepth = max(0, (int) ($input['queue_depth'] ?? 0));
        $recentGiveBackCount = max(0, (int) ($input['recent_give_back_count'] ?? 0));
        $recentPoisonCount = max(0, (int) ($input['recent_poison_count'] ?? 0));

        // Repair escalation: recent give_back, poison, or low-commit-yield signals exceed safe thresholds
        $repairEscalation = $recentPoisonCount >= 2 || $recentGiveBackCount >= 3 || $commitYield < 0.3;

        // Idle waiting: no valuable targets and queue is sufficient
        $idleWaiting = ! $valuableTargetsRemaining && $queueDepth >= 3;

        // Quality backpressure: valuable targets remain but quality signals suggest pacing
        $qualityBackpressure = $valuableTargetsRemaining && ($giveBackRate > 0.3 || $poisonRate > 0.2 || $commitYield < 0.5);

        // Recommend smaller batch when quality signals are moderate
        $recommendSmallerBatch = $qualityBackpressure && $giveBackRate <= 0.5 && $poisonRate <= 0.3;

        // Recommend better batch when quality signals are poor but targets remain
        $recommendBetterBatch = $qualityBackpressure && ! $recommendSmallerBatch;

        $reason = match (true) {
            $repairEscalation => 'repair_escalation_triggered_by_quality_signals',
            $idleWaiting => 'no_valuable_targets_and_queue_sufficient',
            $recommendSmallerBatch => 'quality_backpressure_recommend_smaller_batches',
            $recommendBetterBatch => 'quality_backpressure_recommend_better_batches',
            $valuableTargetsRemaining => 'valuable_targets_remain_continue_normal_pace',
            default => 'no_clear_signal_continue_monitoring',
        };

        return [
            'quality_backpressure' => $qualityBackpressure ? 'active' : 'inactive',
            'idle_waiting' => $idleWaiting,
            'recommend_smaller_batch' => $recommendSmallerBatch,
            'recommend_better_batch' => $recommendBetterBatch,
            'valuable_targets_remaining' => $valuableTargetsRemaining,
            'repair_escalation' => $repairEscalation,
            'reason' => $reason,
        ];
    }

    /**
     * Compute the Wilson score interval lower bound at 95% confidence (z=1.96).
     * Returns 0 when total is 0 (unsampled). Used to gate promotion on statistical
     * certainty rather than raw proportion.
     */
    private function wilsonLowerBound(int $successCount, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $z = 1.96;
        $p = $successCount / $total;
        $denominator = 1.0 + $z * $z / $total;
        $center = ($p + $z * $z / (2.0 * $total)) / $denominator;
        $margin = $z * sqrt(($p * (1.0 - $p) + $z * $z / (4.0 * $total)) / $total) / $denominator;

        return round(max(0.0, $center - $margin), 4);
    }
}
