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

    private const SEVERITY_NONE = 'none';

    private const SEVERITY_LOW = 'low';

    private const SEVERITY_MEDIUM = 'medium';

    private const SEVERITY_HIGH = 'high';

    /** Verdicts that resolve without deep investigation — recoverable by definition. */
    private const RECOVERABLE_VERDICTS = [self::VERDICT_HEALTHY, self::VERDICT_RECOVERABLE_BACKLOG, self::VERDICT_LEASE_ACCOUNTING_MISMATCH];

    /** @var array<string,string> */
    private const SEVERITY_BY_VERDICT = [
        self::VERDICT_HEALTHY => self::SEVERITY_NONE,
        self::VERDICT_RECOVERABLE_BACKLOG => self::SEVERITY_LOW,
        self::VERDICT_LEASE_ACCOUNTING_MISMATCH => self::SEVERITY_LOW,
        self::VERDICT_DEGRADED => self::SEVERITY_MEDIUM,
        self::VERDICT_SERVING_BLOCKED => self::SEVERITY_HIGH,
    ];

    /** claimable_depth at or above this is considered high queue supply. */
    private const HIGH_SUPPLY_CLAIMABLE_DEPTH = 5;

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
        $claimableDepth = (int) ($snapshot['claimable_depth'] ?? 0);
        $flags = (array) ($snapshot['health_flags'] ?? []);

        $context = ['active_leases' => $activeLeases, 'claimed_records' => $claimedRecords, 'claimable_depth' => $claimableDepth];

        // Recoverable backlog always wins first: reaping resolves both the backlog AND any lease
        // accounting drift in one shot, so it must never be masked by a "looks healthy" shortcut.
        if ($recoverableTotal > 0) {
            return $this->verdict(self::VERDICT_RECOVERABLE_BACKLOG, false, 'reap_leases', null, $context, 'recoverable_backlog', 'expired_or_released_lease_not_yet_reaped', 'run atlas:task:reap-leases and re-check the snapshot');
        }

        if ($healthy && $leasesMatchClaimed) {
            return $this->verdict(self::VERDICT_HEALTHY, false, 'no_action', null, $context, 'none', 'none', 'no diagnostic needed');
        }

        // active_leases > claimed_records with nothing recoverable and the queue still servable means
        // the mismatch is cosmetic accounting drift, not a serving failure — never quarantine for this.
        if ($activeLeases > $claimedRecords && $recoverableTotal === 0 && $servableNow > 0) {
            return $this->verdict(self::VERDICT_LEASE_ACCOUNTING_MISMATCH, false, 'observe_or_reconcile_accounting', null, $context, 'accounting_drift', 'concurrent_worker_race_or_stale_registry_snapshot', 'diff active lease ids against claimed queue record ids to confirm drift');
        }

        if ($servableNow === 0) {
            $blockingFlag = $this->firstBlockingFlag($flags);
            if ($blockingFlag !== null) {
                return $this->verdict(self::VERDICT_SERVING_BLOCKED, true, 'investigate_'.$blockingFlag, $blockingFlag, $context, 'serving_blocked', $blockingFlag, 'run atlas:task:health --json and inspect health_flags.'.$blockingFlag);
            }
        }

        return $this->verdict(self::VERDICT_DEGRADED, true, 'investigate_health_flags', null, $context, 'unclassified_degradation', 'unknown', 'run atlas:task:health --json and review every health_flag');
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
     * @param  array{active_leases:int, claimed_records:int, claimable_depth:int}  $context
     * @return array<string, mixed>
     */
    private function verdict(
        string $verdict,
        bool $servingImpact,
        string $recommendation,
        ?string $blockingFlag,
        array $context,
        string $mismatchClass,
        string $likelySource,
        string $nextDiagnosticStep,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'serving_impact' => $servingImpact,
            'recommendation' => $recommendation,
            'recommended_action' => $recommendation,
            'blocking_flag' => $blockingFlag,
            'active_leases' => $context['active_leases'],
            'claimed_records' => $context['claimed_records'],
            'mismatch_class' => $mismatchClass,
            'likely_source' => $likelySource,
            'cause' => $likelySource,
            'next_diagnostic_step' => $nextDiagnosticStep,
            'severity' => self::SEVERITY_BY_VERDICT[$verdict] ?? self::SEVERITY_MEDIUM,
            'recoverable' => in_array($verdict, self::RECOVERABLE_VERDICTS, true),
            'summary' => sprintf('%s: %s (%s)', $verdict, $recommendation, $mismatchClass),
            // Worker-safe: never distinguish "high supply" via 0 vs positive, so a single claimable
            // task doesn't misleadingly read as sufficient supply while the coordination layer is unhealthy.
            'queue_supply_ok' => $context['claimable_depth'] >= self::HIGH_SUPPLY_CLAIMABLE_DEPTH,
        ];
    }
}
