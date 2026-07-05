<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Summarizes whether self-construction can run unattended across
 * queue health, recoverable leases, malformed sweep, drain and
 * evidence freshness.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionTwentyFourSevenReadinessGauge
{
    public const SCHEMA = 'atlas.self_construction.twenty_four_seven_readiness_gauge.v1';

    public const STATE_READY = 'ready';
    public const STATE_REPAIR_FIRST = 'repair_first';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_REPLENISH = 'replenish';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $queueHealthy = (bool) ($input['queue_healthy'] ?? true);
        $claimableDepth = (int) ($input['claimable_depth'] ?? 10);
        $recoverableLeases = (int) ($input['recoverable_lease_count'] ?? 0);
        $malformedBlockers = (int) ($input['malformed_blocker_count'] ?? 0);
        $evidenceFresh = (bool) ($input['evidence_fresh'] ?? true);
        $drainPressure = (bool) ($input['drain_pressure'] ?? false);

        $blockers = [];
        $warnings = [];

        if ($malformedBlockers > 0) {
            $blockers[] = 'malformed_blockers:'.$malformedBlockers;
        }
        if (! $evidenceFresh) {
            $blockers[] = 'evidence_stale';
        }

        if ($blockers !== []) {
            $state = self::STATE_BLOCKED;
        } elseif ($recoverableLeases > 0) {
            $state = self::STATE_REPAIR_FIRST;
            $warnings[] = 'recoverable_leases:'.$recoverableLeases;
        } elseif ($claimableDepth === 0 || $drainPressure) {
            $state = self::STATE_REPLENISH;
        } elseif ($queueHealthy && $evidenceFresh) {
            $state = self::STATE_READY;
        } else {
            $state = self::STATE_BLOCKED;
            $blockers[] = 'queue_unhealthy';
        }

        return [
            'schema' => self::SCHEMA,
            'state' => $state,
            'ready' => $state === self::STATE_READY,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'queue_healthy' => $queueHealthy,
            'claimable_depth' => $claimableDepth,
            'recoverable_lease_count' => $recoverableLeases,
            'malformed_blocker_count' => $malformedBlockers,
            'evidence_fresh' => $evidenceFresh,
            'drain_pressure' => $drainPressure,
        ];
    }
}
