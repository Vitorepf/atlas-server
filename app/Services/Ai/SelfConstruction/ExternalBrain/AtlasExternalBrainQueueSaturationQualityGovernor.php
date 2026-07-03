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

    private const HIGH_COLLISION_RISK_THRESHOLD = 0.30;

    private const LEVERAGE_EVIDENCE_DENSITY_FLOOR = 0.40;

    private const MAX_NEW_TASKS_CAP = 10;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, reason:string, required_next_evidence:list<string>, max_new_tasks:int, queue_pressure:float, diversity_warning:bool, stop_go_decision:string}
     */
    public function decide(array $facts): array
    {
        $servableDepth = max(0, (int) ($facts['servable_depth'] ?? 0));
        $activeWorkers = max(0, (int) ($facts['active_workers'] ?? 0));
        $malformedRate = max(0.0, min(1.0, (float) ($facts['malformed_rate'] ?? 0.0)));
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['give_back_rate'] ?? 0.0)));
        $targetDiversity = max(0.0, min(1.0, (float) ($facts['target_diversity'] ?? 1.0)));
        $collisionRisk = max(0.0, min(1.0, (float) ($facts['collision_risk'] ?? 0.0)));
        $leverageDensity = max(0.0, min(1.0, (float) ($facts['leverage_evidence_density'] ?? 1.0)));
        // family_diversity defaults to target_diversity so callers that never supplied it keep
        // their exact prior behavior; when supplied, it is a distinct, stricter diversity signal.
        $familyDiversity = array_key_exists('family_diversity', $facts)
            ? max(0.0, min(1.0, (float) $facts['family_diversity']))
            : $targetDiversity;

        $depthPerWorker = $activeWorkers > 0
            ? $servableDepth / $activeWorkers
            : ($servableDepth > 0 ? (float) $servableDepth : 0.0);
        $queuePressure = round(min(1.0, $depthPerWorker / self::DEEP_QUEUE_DEPTH_PER_WORKER), 4);

        $highMalformed = $malformedRate >= self::HIGH_MALFORMED_THRESHOLD;
        $highGiveBack = $giveBackRate >= self::HIGH_GIVE_BACK_THRESHOLD;
        $highCollisionRisk = $collisionRisk >= self::HIGH_COLLISION_RISK_THRESHOLD;
        $isDeep = $depthPerWorker >= self::DEEP_QUEUE_DEPTH_PER_WORKER;
        $lowDiversity = $targetDiversity < self::LOW_DIVERSITY_THRESHOLD;
        $lowFamilyDiversity = $familyDiversity < self::LOW_DIVERSITY_THRESHOLD;
        $leverageInsufficient = $leverageDensity < self::LEVERAGE_EVIDENCE_DENSITY_FLOOR;

        if ($highMalformed || $highGiveBack || $highCollisionRisk) {
            $causes = [];
            if ($highMalformed) {
                $causes[] = sprintf('malformed_rate=%.2f above threshold', $malformedRate);
            }
            if ($highGiveBack) {
                $causes[] = sprintf('give_back_rate=%.2f above threshold', $giveBackRate);
            }
            if ($highCollisionRisk) {
                $causes[] = sprintf('collision_risk=%.2f above threshold', $collisionRisk);
            }

            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_REPAIR_SPECS_BEFORE_CREATION,
                'reason' => 'queue health is sick: '.implode(' and ', $causes).'; creating more work would compound the problem',
                'required_next_evidence' => ['malformed_rate_below_threshold', 'give_back_rate_below_threshold', 'collision_risk_below_threshold'],
                'max_new_tasks' => 0,
                'queue_pressure' => $queuePressure,
                'diversity_warning' => $lowDiversity,
                'stop_go_decision' => 'stop',
                'stop_reason' => 'queue_health_sick:'.implode(',', $causes),
                'value_exception_applied' => false,
            ];
        }

        // AC1: sufficient_depth is never a stop excuse on its own — a deep queue with strong
        // leverage evidence and healthy diversity still proceeds via the value exception.
        $valueExceptionApplied = $isDeep && ! $lowDiversity && ! $lowFamilyDiversity && ! $leverageInsufficient;

        if ($isDeep && ($lowDiversity || $lowFamilyDiversity)) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_QUALITY_REVIEW_OR_PAUSE,
                'reason' => sprintf(
                    'queue is deep (%.2f servable per active worker) with low target_diversity=%.2f or low family_diversity=%.2f; more redundant tasks is not leverage',
                    $depthPerWorker,
                    $targetDiversity,
                    $familyDiversity,
                ),
                'required_next_evidence' => ['target_diversity_above_threshold', 'family_diversity_above_threshold', 'servable_depth_per_worker_below_threshold'],
                'max_new_tasks' => 0,
                'queue_pressure' => $queuePressure,
                'diversity_warning' => true,
                'stop_go_decision' => 'review',
                'stop_reason' => $lowFamilyDiversity ? 'deep_queue_low_family_diversity' : 'deep_queue_low_diversity',
                'value_exception_applied' => false,
            ];
        }

        // AC3: even a healthy shallow queue only earns create_high_value_batch when there is
        // enough proven leverage evidence backing the new batch — otherwise pause for review.
        if ($leverageInsufficient) {
            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_QUALITY_REVIEW_OR_PAUSE,
                'reason' => sprintf(
                    'queue is shallow/healthy but leverage_evidence_density=%.2f is below floor=%.2f; creating more would not be evidence-backed',
                    $leverageDensity,
                    self::LEVERAGE_EVIDENCE_DENSITY_FLOOR,
                ),
                'required_next_evidence' => ['leverage_evidence_density_above_floor'],
                'max_new_tasks' => 0,
                'queue_pressure' => $queuePressure,
                'diversity_warning' => $lowDiversity,
                'stop_go_decision' => 'review',
                'stop_reason' => 'leverage_evidence_density_below_floor',
                'value_exception_applied' => false,
            ];
        }

        $remainingCapacity = ($activeWorkers * 2) - $servableDepth;
        $maxNewTasks = $activeWorkers === 0
            ? 0
            : max(1, min(self::MAX_NEW_TASKS_CAP, $remainingCapacity));

        return [
            'schema' => self::SCHEMA,
            'decision' => self::DECISION_CREATE_HIGH_VALUE_BATCH,
            'reason' => sprintf(
                'queue is shallow/healthy (%.2f servable per active worker, malformed_rate=%.2f, give_back_rate=%.2f, leverage_evidence_density=%.2f); room for new high-value work',
                $depthPerWorker,
                $malformedRate,
                $giveBackRate,
                $leverageDensity,
            ),
            'required_next_evidence' => ['leverage_evidence_per_new_task'],
            'max_new_tasks' => $maxNewTasks,
            'queue_pressure' => $queuePressure,
            'diversity_warning' => $lowDiversity,
            'stop_go_decision' => 'go',
            'stop_reason' => null,
            'value_exception_applied' => $valueExceptionApplied,
        ];
    }
}
