<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Pure task-serving health LENS. Turns an {@see \App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService}
 * snapshot (from `atlas:task:health --json`) into one operator-safe verdict, so the brain and Maestro stop
 * treating every `healthy=false` the same way. In particular it distinguishes a true accounting mismatch
 * (active leases outnumber claimed records but the queue is still serving fine — an observe-and-reconcile
 * situation) from an actual serving jam (nothing is servable and the queue is dry or stuck) — never blaming
 * task quality for a coordination-layer artifact. Read-only: never claims, releases, recovers, or mutates.
 */
final class AtlasTaskLeaseMismatchExplainer
{
    public const SCHEMA = 'atlas.self_construction.task_lease_mismatch_explainer.v1';

    public const VERDICT_HEALTHY = 'healthy';

    public const VERDICT_RECOVERABLE_BACKLOG = 'recoverable_backlog';

    public const VERDICT_LEASE_ACCOUNTING_MISMATCH = 'lease_accounting_mismatch';

    public const VERDICT_SERVING_BLOCKED = 'serving_blocked';

    public const VERDICT_DEGRADED = 'degraded';

    private const BLOCKING_FLAGS = ['dry_queue', 'serving_jammed'];

    /**
     * @param  array<string, mixed>  $snapshot  AtlasTaskCoordinationHealthService::snapshot() output (or
     *                                          any payload carrying the same field names).
     * @return array<string, mixed>
     */
    public function explain(array $snapshot): array
    {
        $healthy = (bool) ($snapshot['healthy'] ?? false);
        $leasesMatchClaimed = (bool) ($snapshot['leases_match_claimed'] ?? true);
        $activeLeases = (int) ($snapshot['active_leases'] ?? 0);
        $claimedRecords = (int) ($snapshot['claimed_records'] ?? 0);
        $recoverableTotal = (int) data_get($snapshot, 'recoverable.total', 0);
        $servableNow = (int) ($snapshot['servable_now'] ?? 0);
        $flags = (array) ($snapshot['health_flags'] ?? []);

        // Recoverable backlog always wins first: reaping resolves both the backlog AND any lease
        // accounting drift in one shot, so it must never be masked by a "looks healthy" shortcut.
        if ($recoverableTotal > 0) {
            return $this->verdict(self::VERDICT_RECOVERABLE_BACKLOG, false, 'reap_leases', null);
        }

        if ($healthy && $leasesMatchClaimed) {
            return $this->verdict(self::VERDICT_HEALTHY, false, 'no_action', null);
        }

        // active_leases > claimed_records with nothing recoverable and the queue still servable means
        // the mismatch is cosmetic accounting drift, not a serving failure — never quarantine for this.
        if ($activeLeases > $claimedRecords && $recoverableTotal === 0 && $servableNow > 0) {
            return $this->verdict(self::VERDICT_LEASE_ACCOUNTING_MISMATCH, false, 'observe_or_reconcile_accounting', null);
        }

        if ($servableNow === 0) {
            $blockingFlag = $this->firstBlockingFlag($flags);
            if ($blockingFlag !== null) {
                return $this->verdict(self::VERDICT_SERVING_BLOCKED, true, 'investigate_'.$blockingFlag, $blockingFlag);
            }
        }

        return $this->verdict(self::VERDICT_DEGRADED, true, 'investigate_health_flags', null);
    }

    /**
     * @param  array<string, mixed>  $flags
     */
    private function firstBlockingFlag(array $flags): ?string
    {
        foreach (self::BLOCKING_FLAGS as $name) {
            if ((bool) ($flags[$name] ?? false)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(string $verdict, bool $servingImpact, string $recommendation, ?string $blockingFlag): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'serving_impact' => $servingImpact,
            'recommendation' => $recommendation,
            'blocking_flag' => $blockingFlag,
        ];
    }
}
