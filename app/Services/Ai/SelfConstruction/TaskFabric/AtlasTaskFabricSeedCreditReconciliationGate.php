<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure gate that reconciles requested seed counts against enqueue results and
 * post-round gates so only runnable, non-colliding, non-malformed task packets
 * count toward the 500-seed goal.
 *
 * Credit is granted ONLY when ALL of these pass:
 *   - enqueue_result = 'enqueued'
 *   - post_round_check = 'passed'
 *   - no target collision
 *   - no malformed spec
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskFabricSeedCreditReconciliationGate
{
    public const SCHEMA = 'atlas.task_fabric.seed_credit_reconciliation_gate.v1';

    public const SEED_GOAL = 500;

    public const VERDICT_CREDITED = 'credited';
    public const VERDICT_DENIED = 'denied';

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function reconcile(array $record): array
    {
        $enqueueResult = (string) ($record['enqueue_result'] ?? '');
        $postRoundCheck = (string) ($record['post_round_check'] ?? '');
        $targetCollision = (bool) ($record['target_collision'] ?? false);
        $malformedSpec = (bool) ($record['malformed_spec'] ?? false);
        $requestedSeeds = (int) ($record['requested_seeds'] ?? 0);
        $originatorId = (string) ($record['originator_id'] ?? '');
        $roundId = (string) ($record['round_id'] ?? '');

        $denialReasons = [];

        if ($enqueueResult !== 'enqueued') {
            $denialReasons[] = 'enqueue_result_not_enqueued:'.$enqueueResult;
        }

        if ($postRoundCheck !== 'passed') {
            $denialReasons[] = 'post_round_check_not_passed:'.$postRoundCheck;
        }

        if ($targetCollision) {
            $denialReasons[] = 'target_collision_detected';
        }

        if ($malformedSpec) {
            $denialReasons[] = 'malformed_spec_detected';
        }

        $credited = $denialReasons === [];
        $creditedSeeds = $credited ? $requestedSeeds : 0;

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'verdict' => $credited ? self::VERDICT_CREDITED : self::VERDICT_DENIED,
            'requested_seeds' => $requestedSeeds,
            'credited_seeds' => $creditedSeeds,
            'denial_reasons' => $denialReasons,
            'seed_goal' => self::SEED_GOAL,
        ];
    }

    /**
     * Reconcile a batch of seed records and report progress toward the 500-seed goal.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function reconcileBatch(array $records): array
    {
        $results = [];
        $totalCredited = 0;
        $totalRequested = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $result = $this->reconcile($record);
            $results[] = $result;
            $totalCredited += $result['credited_seeds'];
            $totalRequested += $result['requested_seeds'];
        }

        return [
            'schema_version' => self::SCHEMA,
            'results' => $results,
            'total_requested_seeds' => $totalRequested,
            'total_credited_seeds' => $totalCredited,
            'seed_goal' => self::SEED_GOAL,
            'progress_pct' => $totalCredited > 0 ? round(($totalCredited / self::SEED_GOAL) * 100, 1) : 0.0,
            'goal_reached' => $totalCredited >= self::SEED_GOAL,
        ];
    }
}
