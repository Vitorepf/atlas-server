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
    ) {}

    /**
     * A point-in-time health snapshot. Pure read; same state ⇒ same facts.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queue = $this->queueRepo();

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

        $flags = [
            'dry_queue' => $claimable === 0,
            'has_quarantined_packets' => $quarantined > 0,
            'lease_leak_detected' => $leaseLeak,
            'r2_breach' => (bool) ($serving['r2_breach'] ?? false),
            'recoverable_backlog' => $recoverableTotal > 0,
        ];

        // HEALTHY = no integrity breach. A dry queue or a recoverable backlog are operational states, not
        // breaches; a lease leak or an R2 breach are integrity failures.
        $healthy = ! $flags['lease_leak_detected'] && ! $flags['r2_breach'];

        return [
            'schema' => self::SCHEMA,
            'healthy' => $healthy,
            'claimable_depth' => $claimable,
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

    private function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function leaseRepo(): AgentControlPlaneClaimLeaseRepository
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository;
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
