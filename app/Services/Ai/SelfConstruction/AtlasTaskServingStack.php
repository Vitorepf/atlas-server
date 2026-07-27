<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Context\AtlasContextRuntime;
/**
 * PART 2 — the single factory for the OPERATOR-FACING task-serving stack, on a DEDICATED, clean queue disk.
 *
 * WHY: the Agent Control Plane queue ({@see AgentControlPlaneTaskPacketQueueRepository}) is a shared dumping
 * ground — the certification machinery floods it with hundreds of thousands of `probe_*` records. If task
 * serving read from it, an AI pulling `atlas:task next` would get certification junk, not real brain work. So
 * the operator's serving (next/report/enqueue/replenish/health) runs on its OWN disk, isolated from that
 * pollution. Set `ATLAS_TASK_SERVING_QUEUE_DISK` to a dedicated disk name (default 'local' keeps the legacy
 * shared behaviour, so tests and the certification probes are untouched).
 */
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskBrainReplenisher;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Config;

final class AtlasTaskServingStack
{
    /** The configured serving queue disk, registering a dedicated local disk on first use when one is named. */
    public static function disk(): string
    {
        $disk = (string) config('atlas.task_serving.queue_disk', 'local');
        if ($disk !== 'local' && ! Config::get('filesystems.disks.'.$disk)) {
            Config::set('filesystems.disks.'.$disk, [
                'driver' => 'local',
                'root' => storage_path('app/atlas/task-serving/'.$disk),
                'throw' => false,
            ]);
        }

        return $disk;
    }

    /**
     * FAIL-LOUD guard: refuses to run when the serving queue disk is missing/empty or resolves
     * to the shared 'local' default (which silently mixes serving with certification spam — the
     * exact bug that made seeded packets invisible to workers). Throws RuntimeException with a
     * clear message naming the misconfigured disk; never narrows nor reformats the cause.
     */
    public static function assertServingDiskConfigured(): void
    {
        $health = self::servingDiskHealth();
        if (($health['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'atlas_task_serving_disk_misconfigured: '.(string) ($health['reason'] ?? 'unknown')
                .' (resolved="'.(string) ($health['disk'] ?? '').'"); set ATLAS_TASK_SERVING_QUEUE_DISK to a dedicated disk name.'
            );
        }
    }

    /**
     * NON-throwing mirror of assertServingDiskConfigured() for health surfaces.
     *
     * @return array{ok:bool,disk:string,reason:string}
     */
    public static function servingDiskHealth(): array
    {
        $raw = config('atlas.task_serving.queue_disk');
        $disk = is_string($raw) ? trim($raw) : '';
        if ($disk === '') {
            return ['ok' => false, 'disk' => '', 'reason' => 'serving_disk_unset'];
        }
        if ($disk === 'local') {
            return ['ok' => false, 'disk' => $disk, 'reason' => 'serving_disk_default_local_forbidden'];
        }
        // AC: a disk name must be a single safe path segment — no '/', '\\', or '..'
        // that could escape storage/app/atlas/task-serving/ into an unintended root.
        if (str_contains($disk, '/') || str_contains($disk, '\\') || str_contains($disk, '..')) {
            return ['ok' => false, 'disk' => $disk, 'reason' => 'serving_disk_name_unsafe'];
        }

        return ['ok' => true, 'disk' => $disk, 'reason' => 'dedicated_disk_configured'];
    }

    public static function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        $disk = self::disk();

        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository($disk),
            new AgentControlPlaneClaimLeaseRepository($disk),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    public static function queueRepo(): AgentControlPlaneTaskPacketQueueRepository
    {
        return new AgentControlPlaneTaskPacketQueueRepository(self::disk());
    }

    public static function leaseRepo(): AgentControlPlaneClaimLeaseRepository
    {
        return new AgentControlPlaneClaimLeaseRepository(self::disk());
    }

    /**
     * The serving service the contract front door + MCP resolve.
     * Obra 2 / AUT-05: inject EliteExecutorKernel + AtlasContextRuntime so Autônomos
     * honesty/context gates are live (not null seams).
     */
    public static function servingService(): AtlasTaskServingService
    {
        $kernel = null;
        $contextRuntime = null;
        try {
            $kernel = app(EliteExecutorKernel::class);
        } catch (\Throwable) {
            // fail-open: serving still works without elite kernel
        }
        try {
            $contextRuntime = app(AtlasContextRuntime::class);
        } catch (\Throwable) {
            // fail-open: serving still works without context runtime
        }

        return new AtlasTaskServingService(
            self::orchestrator(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $kernel,
            $contextRuntime,
        );
    }

    public static function replenisher(): AtlasTaskBrainReplenisher
    {
        return new AtlasTaskBrainReplenisher(self::orchestrator(), null, null, null, self::disk());
    }

    public static function coordinationHealth(): AtlasTaskCoordinationHealthService
    {
        return new AtlasTaskCoordinationHealthService(self::queueRepo(), self::leaseRepo());
    }
}
