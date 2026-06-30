<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure governor deciding whether the originator should slow down, keep creating, or switch from
 * new-task creation to quality repair. Prevents the brain from flooding a healthy queue with
 * redundant work just because it technically can.
 *
 * Input shape: {servable_depth:int, active_workers:int, malformed_rate:float, give_back_rate:float,
 *               target_diversity:float, collision_risk?:float}
 *
 * Decision priority (highest first):
 *   1. High malformed_rate OR give_back_rate ⇒ repair_specs_before_creation (the queue is sick;
 *      creating more work compounds the problem regardless of depth).
 *   2. Deep queue relative to active workers AND low target_diversity ⇒ quality_review_or_pause
 *      (plenty of servable work already; more of the same is redundant, not leverage).
 *   3. Otherwise ⇒ create_high_value_batch, sized to the remaining serving capacity.
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasExternalBrainQueueSaturationQualityGovernor
{
    public const SCHEMA = 'atlas.self_construction.external_brain.queue_saturation_quality_governor.v1';

    public const DECISION_CREATE_HIGH_VALUE_BATCH = 'create_high_value_batch';

    public const DECISION_QUALITY_REVIEW_OR_PAUSE = 'quality_review_or_pause';

    public const DECISION_REPAIR_SPECS_BEFORE_CREATION = 'repair_specs_before_creation';

    private const DEEP_QUEUE_DEPTH_PER_WORKER = 5.0;

    private const LOW_DIVERSITY_THRESHOLD = 0.3;

    private const HIGH_MALFORMED_THRESHOLD = 0.15;

    private const HIGH_GIVE_BACK_THRESHOLD = 0.25;

    private const MAX_NEW_TASKS_CAP = 10;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, reason:string, required_next_evidence:list<string>, max_new_tasks:int}
     */
    public function decide(array $facts): array
    {
        $servableDepth = max(0, (int) ($facts['servable_depth'] ?? 0));
        $activeWorkers = max(0, (int) ($facts['active_workers'] ?? 0));
        $malformedRate = max(0.0, min(1.0, (float) ($facts['malformed_rate'] ?? 0.0)));
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['give_back_rate'] ?? 0.0)));
        $targetDiversity = max(0.0, min(1.0, (float) ($facts['target_diversity'] ?? 1.0)));

        $depthPerWorker = $activeWorkers > 0
            ? $servableDepth / $activeWorkers
            : ($servableDepth > 0 ? (float) $servableDepth : 0.0);

        $highMalformed = $malformedRate >= self::HIGH_MALFORMED_THRESHOLD;
        $highGiveBack = $giveBackRate >= self::HIGH_GIVE_BACK_THRESHOLD;
        $isDeep = $depthPerWorker >= self::DEEP_QUEUE_DEPTH_PER_WORKER;
        $lowDiversity = $targetDiversity < self::LOW_DIVERSITY_THRESHOLD;

        if ($highMalformed || $highGiveBack) {
            $cause = $highMalformed && $highGiveBack
                ? sprintf('malformed_rate=%.2f and give_back_rate=%.2f both above threshold', $malformedRate, $giveBackRate)
                : ($highMalformed
                    ? sprintf('malformed_rate=%.2f above threshold', $malformedRate)
                    : sprintf('give_back_rate=%.2f above threshold', $giveBackRate));

            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_REPAIR_SPECS_BEFORE_CREATION,
                'reason' => 'queue health is sick: '.$cause.'; creating more work would compound the problem',
                'required_next_evidence' => ['malformed_rate_below_threshold', 'give_back_rate_below_threshold'],
                'max_new_tasks' => 0,
            ];
        }

        if ($isDeep && $lowDiversity) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_QUALITY_REVIEW_OR_PAUSE,
                'reason' => sprintf(
                    'queue is deep (%.2f servable per active worker) with low target_diversity=%.2f; more redundant tasks is not leverage',
                    $depthPerWorker,
                    $targetDiversity,
                ),
                'required_next_evidence' => ['target_diversity_above_threshold', 'servable_depth_per_worker_below_threshold'],
                'max_new_tasks' => 0,
            ];
        }

        $remainingCapacity = ($activeWorkers * 2) - $servableDepth;
        $maxNewTasks = max(1, min(self::MAX_NEW_TASKS_CAP, $remainingCapacity));

        return [
            'schema' => self::SCHEMA,
            'decision' => self::DECISION_CREATE_HIGH_VALUE_BATCH,
            'reason' => sprintf(
                'queue is shallow/healthy (%.2f servable per active worker, malformed_rate=%.2f, give_back_rate=%.2f); room for new high-value work',
                $depthPerWorker,
                $malformedRate,
                $giveBackRate,
            ),
            'required_next_evidence' => ['leverage_evidence_per_new_task'],
            'max_new_tasks' => $maxNewTasks,
        ];
    }
}
