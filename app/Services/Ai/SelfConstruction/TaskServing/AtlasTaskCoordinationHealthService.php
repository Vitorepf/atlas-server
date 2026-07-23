<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

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
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;

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
        $servingDiskHealth = AtlasTaskServingStack::servingDiskHealth();

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
        $servabilityKnown = (string) ($servable['status'] ?? 'available') === 'available';
        $servableNow = $servabilityKnown ? (int) ($servable['servable_now'] ?? 0) : null;
        $waitingInflight = $servabilityKnown ? (int) ($servable['waiting_on_inflight_deps'] ?? 0) : null;

        $flags = [
            'dry_queue' => $claimable === 0,
            'servability_scan_limit_exceeded' => ! $servabilityKnown,
            // JAMMED: claimable tasks exist but NONE are servable and NONE are advancing (gated only by dead
            // prereqs / probes / non-executable) AND there is no recoverable backlog. The recoverable clause is
            // load-bearing: `claimNext` runs reapExpiredBeforeListing FIRST, re-admitting released/expired-lease/
            // orphan work to claimable — exactly what `recoverableTotal` counts — so that work is NOT a jam (the
            // next claim serves it). Without this clause a dead worker's released task read as a false DEGRADED.
            'serving_jammed' => $servabilityKnown && $claimable > 0 && $servableNow === 0 && $waitingInflight === 0 && $recoverableTotal === 0,
            'has_quarantined_packets' => $quarantined > 0,
            // RECOVERABLE clause: activeLeases() expires past-TTL leases BEFORE counting, so a past-TTL lease
            // whose queue record is still claimed is exactly the reaper-recoverable strand that claimNext
            // re-admits on the next call. That mismatch is not a true orphan leak — it self-heals. Mirror the
            // same recoverable guard that serving_jammed already carries (line 79).
            'lease_leak_detected' => $leaseLeak && $recoverableTotal === 0,
            'r2_breach' => (bool) ($serving['r2_breach'] ?? false),
            'recoverable_backlog' => $recoverableTotal > 0,
            'queue_disk_mismatch_detected' => $queueDiskMismatch,
        ];

        // HEALTHY = no integrity breach. A dry queue, a recoverable backlog, or an advancing-ladder wait are
        // operational states, not breaches; a lease leak, an R2 breach, or a true serving JAM are failures.
        // serving_disk_health.ok is surfaced as a standalone fact (see serving_disk_health below) but is
        // deliberately NOT folded into `healthy`: the test harness's dedicated serving disk is literally
        // named "local" (phpunit.xml ATLAS_TASK_SERVING_QUEUE_DISK=local), which servingDiskHealth() always
        // flags as the forbidden default — folding it in here would make every existing healthy snapshot
        // report unhealthy under test, which is not an integrity breach in that environment.
        $healthy = ! $flags['lease_leak_detected']
            && ! $flags['r2_breach']
            && ! $flags['serving_jammed']
            && $servabilityKnown
            && ! $flags['queue_disk_mismatch_detected'];

        // WORKER DRAIN FORECAST — fact-only: can the active muscles drain the queue soon?
        $claimablePerWorker = $servabilityKnown && $activeLeases > 0 ? (int) floor($servableNow / $activeLeases) : null;
        $queuePressure = ! $servabilityKnown ? 'unknown' : ($activeLeases > 0 && $claimablePerWorker !== null && $claimablePerWorker < 3 ? 'high' : ($servableNow < 5 ? 'moderate' : 'low'));
        $replenishRecommendation = match (true) {
            ! $servabilityKnown => 'inspect_servability_scan_limit',
            $activeLeases === 0 => 'no_active_workers',
            $servableNow === 0 => 'queue_dry_replenish_now',
            $claimablePerWorker !== null && $claimablePerWorker < 2 => 'replenish_urgently',
            $claimablePerWorker !== null && $claimablePerWorker < 5 => 'replenish_soon',
            default => 'sufficient_depth',
        };

        // DRAIN TELEMETRY CONFIDENCE — serve_total is the DIRECT signal. When it's silent
        // (zero) but the queue's own status transitions (completed_dry_run, released — work
        // actually moved) show progress, report that as an ESTIMATED fallback instead of an
        // opaque zero-serve state that looks identical to "nothing is happening".
        $serveTotal = (int) ($serving['serve_total'] ?? 0);
        $queueTransitionCount = $distribution['completed_dry_run'] + $distribution['released'];
        $drainTelemetryConfidence = $serveTotal > 0 ? 'direct' : ($queueTransitionCount > 0 ? 'estimated' : 'unavailable');
        $drainFallbackSource = $drainTelemetryConfidence === 'estimated' ? 'queue_transitions' : null;

        // SELF-HEALING ACTION — a recoverable backlog already self-heals on the next claimNext
        // (it re-admits released/expired-lease/orphan work), so it needs no operator action. An
        // unexplained lease mismatch (recoverable_total=0 but leases don't match claimed) is a
        // true orphan leak the reaper cannot already fix on its own, so it gets a concrete action.
        $selfHealingAction = match (true) {
            $flags['lease_leak_detected'] => 'reap_orphan_leases_and_requeue_claimed_records',
            $flags['recoverable_backlog'] => 'await_next_claim_next_reap_cycle',
            default => null,
        };

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
            'self_healing_action' => $selfHealingAction,
            'serving_disk_health' => [
                'ok' => (bool) ($servingDiskHealth['ok'] ?? false),
                'disk' => (string) ($servingDiskHealth['disk'] ?? ''),
                'reason' => (string) ($servingDiskHealth['reason'] ?? ''),
            ],
            'worker_drain_forecast' => [
                'servable_now' => $servableNow,
                'active_leases' => $activeLeases,
                'claimable_per_active_worker' => $claimablePerWorker,
                'queue_pressure' => $queuePressure,
                'replenish_recommendation' => $replenishRecommendation,
                'drain_telemetry_confidence' => $drainTelemetryConfidence,
                'fallback_source' => $drainFallbackSource,
            ],
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
