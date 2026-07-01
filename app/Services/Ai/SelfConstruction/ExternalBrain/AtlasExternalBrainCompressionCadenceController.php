<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Autonomy gate for the simplification engine's cadence: decides whether to create_batch,
 * pause_for_muscles, consolidate_learning, or measure_regression from queue, outcome and
 * quality facts — never from queue depth alone.
 *
 * THE BUG THIS EXISTS TO KILL: a prior cadence controller waited whenever the queue "looked"
 * non-empty, even while real high-value distinct candidates sat unclaimed. create_batch's
 * condition below never references queue_depth at all — a deep-but-stale queue can never block
 * origination when claimable_high_value_distinct_count > 0.
 *
 * Priority (first match wins):
 *   1. measure_regression  — unmeasured landed commits at/above the safety floor: compressing
 *                            blind (without knowing whether the last batch regressed anything)
 *                            is never safe, regardless of how much value is waiting.
 *   2. pause_for_muscles    — worker pressure or recent give-back/regression rate is too high:
 *                            piling more compression work onto an overloaded/unreliable muscle
 *                            pool compounds failures instead of reducing sprawl.
 *   3. create_batch         — real high-value distinct candidates exist: originate, regardless
 *                            of queue_depth.
 *   4. consolidate_learning — outcome-learner deltas are waiting to be applied: fold in what was
 *                            already learned before creating more of what already failed.
 *   5. measure_regression   — safe default when nothing above applies: spend the cycle proving
 *                            past work instead of sitting idle.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionCadenceController
{
    public const SCHEMA = 'atlas.external_brain.compression_cadence_controller.v1';

    public const DECISION_CREATE_BATCH = 'create_batch';

    public const DECISION_PAUSE_FOR_MUSCLES = 'pause_for_muscles';

    public const DECISION_CONSOLIDATE_LEARNING = 'consolidate_learning';

    public const DECISION_MEASURE_REGRESSION = 'measure_regression';

    private const WORKER_PRESSURE_THRESHOLD = 0.80;

    private const GIVE_BACK_RATE_THRESHOLD = 0.40;

    private const UNMEASURED_COMMITS_THRESHOLD = 3;

    private const PENDING_LEARNING_DELTAS_THRESHOLD = 1;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, reasons:list<string>}
     */
    public function decide(array $facts): array
    {
        $queueDepth = max(0, (int) ($facts['queue_depth'] ?? 0));
        $highValueDistinctCount = max(0, (int) ($facts['claimable_high_value_distinct_count'] ?? 0));
        $workerPressure = max(0.0, min(1.0, (float) ($facts['worker_pressure'] ?? 0.0)));
        $giveBackRate = max(0.0, min(1.0, (float) ($facts['recent_give_back_rate'] ?? 0.0)));
        $unmeasuredLandedCommits = max(0, (int) ($facts['unmeasured_landed_commits'] ?? 0));
        $pendingLearningDeltas = max(0, (int) ($facts['pending_learning_deltas'] ?? 0));

        if ($unmeasuredLandedCommits >= self::UNMEASURED_COMMITS_THRESHOLD) {
            return $this->result(self::DECISION_MEASURE_REGRESSION, [
                sprintf('unmeasured_landed_commits:%d>=%d', $unmeasuredLandedCommits, self::UNMEASURED_COMMITS_THRESHOLD),
            ]);
        }

        if ($workerPressure >= self::WORKER_PRESSURE_THRESHOLD || $giveBackRate >= self::GIVE_BACK_RATE_THRESHOLD) {
            $reasons = [];
            if ($workerPressure >= self::WORKER_PRESSURE_THRESHOLD) {
                $reasons[] = sprintf('worker_pressure:%.4f>=%.2f', $workerPressure, self::WORKER_PRESSURE_THRESHOLD);
            }
            if ($giveBackRate >= self::GIVE_BACK_RATE_THRESHOLD) {
                $reasons[] = sprintf('recent_give_back_rate:%.4f>=%.2f', $giveBackRate, self::GIVE_BACK_RATE_THRESHOLD);
            }

            return $this->result(self::DECISION_PAUSE_FOR_MUSCLES, $reasons);
        }

        // Queue depth is NEVER a condition here — real distinct value always wins over a "looks
        // busy" queue snapshot.
        if ($highValueDistinctCount > 0) {
            return $this->result(self::DECISION_CREATE_BATCH, [
                sprintf('claimable_high_value_distinct_count:%d>0', $highValueDistinctCount),
                sprintf('queue_depth_irrelevant:%d', $queueDepth),
            ]);
        }

        if ($pendingLearningDeltas >= self::PENDING_LEARNING_DELTAS_THRESHOLD) {
            return $this->result(self::DECISION_CONSOLIDATE_LEARNING, [
                sprintf('pending_learning_deltas:%d>=%d', $pendingLearningDeltas, self::PENDING_LEARNING_DELTAS_THRESHOLD),
            ]);
        }

        return $this->result(self::DECISION_MEASURE_REGRESSION, ['no_actionable_signal_default_to_measurement']);
    }

    /** @param  list<string>  $reasons @return array{schema:string, decision:string, reasons:list<string>} */
    private function result(string $decision, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }
}
