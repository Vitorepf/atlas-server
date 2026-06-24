<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * PART 2 · axis 10 — the COORDINATION HEALTH panel (the operator's "painel de saúde da coordenação"). A
 * read-only, deterministic cross-cut of the serving state: the queue's status distribution, the lease
 * registry, what recovery would reclaim, the serve success-rate, and the derived health FLAGS that make the
 * coordination self-aware (and self-heal-actionable). Read-only by construction: it never claims, releases,
 * recovers, dispatches, or merges — it only OBSERVES. Facts, never a score.
 *
 * The single capability nothing else surfaced: LEASE-LEAK detection. Every active lease must correspond 1:1 to
 * a queue record in `claimed`; a mismatch means a lease outlived (or lost) its task — exactly the strand the
 * reaper exists to prevent. The panel makes it visible instead of silent.
 */
final class AtlasTaskCoordinationHealthService
{
    public const SCHEMA = 'atlas.task_serving.coordination_health.v1';

    /** Every queue status, so the distribution is exhaustive (a missing status reads as 0, never absent). */
    private const QUEUE_STATUSES = [
        'queued', 'claimable', 'claimed', 'lease_expired', 'released', 'completed_dry_run', 'blocked', 'cancelled',
    ];

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AgentControlPlaneClaimLeaseRepository $leases = null,
        private readonly ?AgentControlPlaneTaskLeaseRecoveryService $recovery = null,
        private readonly ?AtlasTaskServingSentinel $sentinel = null,
        private readonly ?AgentControlPlaneTaskQueueOrchestrator $orchestrator = null,
    ) {}

    /**
     * A point-in-time health snapshot. Pure read; same state ⇒ same facts.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queue = $this->queueRepo();
        $queueDiskMismatch = $this->queueDiskName($queue) !== AtlasTaskServingStack::disk();

        $distribution = [];
        foreach (self::QUEUE_STATUSES as $status) {
            $distribution[$status] = count($queue->list(['status' => $status]));
        }
        $claimable = $distribution['claimable'];
        $claimed = $distribution['claimed'];
        $quarantined = $distribution['blocked'];

        $activeLeases = count($this->leaseRepo()->activeLeases());

        // LEASE-LEAK: active leases must match claimed records 1:1. A mismatch is a strand the reaper should
        // reclaim — surfaced here, never silent.
        $leaseLeak = $activeLeases !== $claimed;

        $recoverability = $this->recoveryService()->inspectRecoverability();
        $recoverableTotal = (int) ($recoverability['recoverable_count'] ?? 0);
        $byClassification = (array) ($recoverability['totals_by_classification'] ?? []);

        $serving = $this->sentinelObject()->status();

        // SERVABILITY — the claimable depth is RAW; it counts dependency-gated and probe records that a worker
        // can NOT actually pull. The breakdown comes from the orchestrator's OWN claim predicate, so health,
        // the orchestrator, the service and the CLI agree on `servable_now` by construction (no reimplementation).
        $servable = $this->orchestratorObject()->servabilityBreakdown();
        $servableNow = (int) ($servable['servable_now'] ?? 0);
        $waitingInflight = (int) ($servable['waiting_on_inflight_deps'] ?? 0);

        $flags = [
            'dry_queue' => $claimable === 0,
            // JAMMED: claimable tasks exist but NONE are servable and NONE are advancing (gated only by dead
            // prereqs / probes / non-executable) AND there is no recoverable backlog. The recoverable clause is
            // load-bearing: `claimNext` runs reapExpiredBeforeListing FIRST, re-admitting released/expired-lease/
            // orphan work to claimable — exactly what `recoverableTotal` counts — so that work is NOT a jam (the
            // next claim serves it). Without this clause a dead worker's released task read as a false DEGRADED.
            'serving_jammed' => $claimable > 0 && $servableNow === 0 && $waitingInflight === 0 && $recoverableTotal === 0,
            'has_quarantined_packets' => $quarantined > 0,
            'lease_leak_detected' => $leaseLeak,
            'r2_breach' => (bool) ($serving['r2_breach'] ?? false),
            'recoverable_backlog' => $recoverableTotal > 0,
            'queue_disk_mismatch_detected' => $queueDiskMismatch,
        ];

        // HEALTHY = no integrity breach. A dry queue, a recoverable backlog, or an advancing-ladder wait are
        // operational states, not breaches; a lease leak, an R2 breach, or a true serving JAM are failures.
        $healthy = ! $flags['lease_leak_detected']
            && ! $flags['r2_breach']
            && ! $flags['serving_jammed']
            && ! $flags['queue_disk_mismatch_detected'];

        return [
            'schema' => self::SCHEMA,
            'healthy' => $healthy,
            'claimable_depth' => $claimable,
            'servable_now' => $servableNow,
            'servability' => $servable,
            'quarantined_count' => $quarantined,
            'queue_status_distribution' => $distribution,
            'active_leases' => $activeLeases,
            'claimed_records' => $claimed,
            'leases_match_claimed' => ! $leaseLeak,
            'recoverable' => [
                'total' => $recoverableTotal,
                'by_classification' => $byClassification,
            ],
            'serving' => [
                'serve_total' => (int) ($serving['serve_total'] ?? 0),
                'serve_success_rate' => $serving['serve_success_rate'] ?? 1.0,
                'r2_breach' => (bool) ($serving['r2_breach'] ?? false),
                'last_claimable_depth' => $serving['last_claimable_depth'] ?? null,
            ],
            'health_flags' => $flags,
        ];
    }

    private function orchestratorObject(): AgentControlPlaneTaskQueueOrchestrator
    {
        // Default: build an orchestrator over the SAME repos this panel reads, so `servable_now` is computed
        // against the exact queue health is reporting on (no disk/instance divergence).
        return $this->orchestrator ?? new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $this->queueRepo(),
            $this->leaseRepo(),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository(AtlasTaskServingStack::disk());
    }

    private function queueDiskName(AgentControlPlaneTaskPacketQueueRepository $queue): string
    {
        $property = new \ReflectionProperty($queue, 'disk');
        $property->setAccessible(true);
        $disk = $property->getValue($queue);

        return is_string($disk) && $disk !== '' ? $disk : AgentControlPlaneTaskPacketQueueRepository::DEFAULT_DISK;
    }

    private function leaseRepo(): AgentControlPlaneClaimLeaseRepository
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository(AtlasTaskServingStack::disk());
    }

    private function recoveryService(): AgentControlPlaneTaskLeaseRecoveryService
    {
        return $this->recovery ?? new AgentControlPlaneTaskLeaseRecoveryService($this->queueRepo(), $this->leaseRepo());
    }

    private function sentinelObject(): AtlasTaskServingSentinel
    {
        return $this->sentinel ?? new AtlasTaskServingSentinel;
    }
}
