<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure router — maps an {@see \App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService}
 * snapshot into ONE clear autonomous next action, so the brain/muscles never waste tokens reacting to
 * the wrong signal — e.g. `healthy=false` from a lease-count mismatch while `servable_now` is still high
 * is harmless pressure, not a true jam.
 *
 * INPUT (health snapshot):
 *   { healthy:bool, servable_now:int, recoverable:{total:int}, leases_match_claimed:bool,
 *     health_flags:{dry_queue?:bool, serving_jammed?:bool, recoverable_backlog?:bool,
 *       lease_leak_detected?:bool, malformed_risk?:bool, ...}, worker_drain_forecast:array }
 *
 * DECISION TABLE (first match wins — deterministic priority):
 *   1. dry_queue OR serving_jammed                          → replenish_or_repair
 *   2. recoverable_backlog                                  → reap_leases
 *   3. malformed_risk                                       → sweep_malformed
 *   4. lease_leak_detected AND servable_now > 0              → inspect_lease_parity (harmless pressure)
 *   5. otherwise (clean, servable)                          → continue_work
 *
 * OUTPUT: { schema, primary_action, secondary_actions:list<string>, human_readable_reason }
 *
 * Pure: no Artisan calls, no queue mutation, no worker spawn, no provider call, no git access.
 */
final class AtlasTaskServingHealthFlagActionRouter
{
    public const SCHEMA = 'atlas.task_serving.health_flag_action_router.v1';

    public const ACTION_REPLENISH_OR_REPAIR = 'replenish_or_repair';

    public const ACTION_REAP_LEASES = 'reap_leases';

    public const ACTION_SWEEP_MALFORMED = 'sweep_malformed';

    public const ACTION_INSPECT_LEASE_PARITY = 'inspect_lease_parity';

    /** Ghost lease leak: parity mismatch with zero recoverable leases and servable work available — nothing to reap, nothing to repair. */
    public const ACTION_OBSERVE_NOOP = 'observe_noop';

    public const ACTION_TOP_UP_QUEUE_BEFORE_STARVATION = 'top_up_queue_before_starvation';

    public const ACTION_CONTINUE_WORK = 'continue_work';

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{schema:string, primary_action:string, secondary_actions:list<string>, human_readable_reason:string}
     */
    public function route(array $snapshot): array
    {
        $servableNow = (int) ($snapshot['servable_now'] ?? 0);
        $flags = is_array($snapshot['health_flags'] ?? null) ? $snapshot['health_flags'] : [];
        $recoverableTotal = (int) (is_array($snapshot['recoverable'] ?? null) ? ($snapshot['recoverable']['total'] ?? 0) : 0);
        $leasesMatchClaimed = (bool) ($snapshot['leases_match_claimed'] ?? true);

        $dryQueue = (bool) ($flags['dry_queue'] ?? false);
        $servingJammed = (bool) ($flags['serving_jammed'] ?? false);
        $recoverableBacklog = (bool) ($flags['recoverable_backlog'] ?? false) || $recoverableTotal > 0;
        $malformedRisk = (bool) ($flags['malformed_risk'] ?? false);
        $leaseLeak = (bool) ($flags['lease_leak_detected'] ?? false) || ! $leasesMatchClaimed;
        $queuePressure = (string) ($snapshot['queue_pressure'] ?? '');
        $replenishRecommendation = (string) ($snapshot['replenish_recommendation'] ?? '');
        $forecast = is_array($snapshot['worker_drain_forecast'] ?? null) ? $snapshot['worker_drain_forecast'] : [];
        $forecastReplenishRecommendation = (string) ($forecast['replenish_recommendation'] ?? '');
        $activeLeases = (int) ($snapshot['active_leases'] ?? ($forecast['active_leases'] ?? 0));
        $claimablePerActiveWorker = isset($forecast['claimable_per_active_worker'])
            ? (float) $forecast['claimable_per_active_worker']
            : (isset($snapshot['claimable_per_active_worker']) ? (float) $snapshot['claimable_per_active_worker'] : null);
        $nearWorkerFloor = $activeLeases > 0 && $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= 2.0;
        $workerFloorPressure = $queuePressure === 'high'
            || $replenishRecommendation === 'replenish_soon'
            || $forecastReplenishRecommendation === 'replenish_soon'
            || $nearWorkerFloor;
        $effectiveReplenishRecommendation = $replenishRecommendation !== '' ? $replenishRecommendation : $forecastReplenishRecommendation;

        // Candidate actions in deterministic priority order — first true wins as primary.
        // Lease leak, recoverable backlog, and malformed sweep keep precedence over worker-floor
        // top-up guidance: those describe queue CORRUPTION/leakage, worse than impending starvation.
        // Ghost leak: parity mismatch, servable work exists, but ZERO recoverable leases — there is
        // nothing to reap and nothing to repair, so this is pure observation, not an inspection task.
        $leaseLeakGhost = $leaseLeak && $servableNow > 0 && $recoverableTotal === 0;
        // Non-ghost leak (recoverableTotal > 0) is unreachable in practice — recoverableBacklog already
        // wins at higher priority whenever recoverableTotal > 0 — but kept for defensive completeness.
        $leaseLeakReal = $leaseLeak && $servableNow > 0 && $recoverableTotal > 0;

        $candidates = [
            self::ACTION_REPLENISH_OR_REPAIR => $dryQueue || $servingJammed,
            self::ACTION_REAP_LEASES => $recoverableBacklog,
            self::ACTION_SWEEP_MALFORMED => $malformedRisk,
            // Harmless pressure: a lease-count mismatch while the queue still has servable work is not
            // a true jam — investigate parity, don't panic-replenish.
            self::ACTION_INSPECT_LEASE_PARITY => $leaseLeakReal,
            self::ACTION_OBSERVE_NOOP => $leaseLeakGhost,
            self::ACTION_TOP_UP_QUEUE_BEFORE_STARVATION => $workerFloorPressure,
        ];

        $primaryAction = self::ACTION_CONTINUE_WORK;
        $secondaryActions = [];
        foreach ($candidates as $action => $triggered) {
            if (! $triggered) {
                continue;
            }
            if ($primaryAction === self::ACTION_CONTINUE_WORK) {
                $primaryAction = $action;
            } else {
                $secondaryActions[] = $action;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'primary_action' => $primaryAction,
            'secondary_actions' => $secondaryActions,
            'human_readable_reason' => $this->reason($primaryAction, $dryQueue, $servingJammed, $recoverableTotal, $malformedRisk, $leaseLeak, $servableNow, $queuePressure, $effectiveReplenishRecommendation, $nearWorkerFloor || $forecastReplenishRecommendation === 'replenish_soon', $activeLeases, $claimablePerActiveWorker),
        ];
    }

    private function reason(
        string $primaryAction,
        bool $dryQueue,
        bool $servingJammed,
        int $recoverableTotal,
        bool $malformedRisk,
        bool $leaseLeak,
        int $servableNow,
        string $queuePressure,
        string $replenishRecommendation,
        bool $nearWorkerFloor,
        int $activeLeases,
        ?float $claimablePerActiveWorker,
    ): string {
        return match ($primaryAction) {
            self::ACTION_REPLENISH_OR_REPAIR => $dryQueue
                ? 'queue is dry — claimable depth is zero, replenish or repair the spec pipeline'
                : 'serving is jammed — claimable tasks exist but none are servable or advancing',
            self::ACTION_REAP_LEASES => sprintf('recoverable backlog of %d lease(s) — reap before claiming more', $recoverableTotal),
            self::ACTION_SWEEP_MALFORMED => 'malformed-packet risk detected — sweep before workers claim poisoned packets',
            self::ACTION_INSPECT_LEASE_PARITY => sprintf('lease count mismatch with servable_now=%d — harmless pressure, inspect parity, do not panic-replenish', $servableNow),
            self::ACTION_OBSERVE_NOOP => sprintf('ghost lease leak: lease parity mismatch with zero recoverable leases and servable_now=%d — nothing to reap, observe only', $servableNow),
            self::ACTION_TOP_UP_QUEUE_BEFORE_STARVATION => $nearWorkerFloor
                ? sprintf(
                    'worker_floor: active_leases=%d, claimable_per_active_worker=%s <= 2 — top up the queue before active muscles starve',
                    $activeLeases,
                    $claimablePerActiveWorker !== null ? (string) $claimablePerActiveWorker : 'unknown',
                )
                : sprintf(
                    'queue_pressure=%s, replenish_recommendation=%s — top up the queue before workers starve',
                    $queuePressure !== '' ? $queuePressure : 'unknown',
                    $replenishRecommendation !== '' ? $replenishRecommendation : 'unknown',
                ),
            default => 'queue is clean and servable — continue normal work',
        };
    }
}
