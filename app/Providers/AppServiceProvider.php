<?php

namespace App\Providers;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\Execution\VoxClaudeCliExecutor;
use App\Services\Ai\Vox\Execution\VoxCodexCliExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutorRouter;
use App\Services\Ai\Vox\Execution\VoxFilesystemEditExecutor;
use App\Services\Ai\Vox\Execution\VoxNoteCaptureExecutor;
use App\Services\Ai\Vox\Execution\VoxTerminalProposeExecutor;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SkillBundleStore::class);

        // Vox V3 confirmation cache: pin the default cache repository so the
        // service stays on the same store across the (intent → execute)
        // round-trip. Laravel does not auto-resolve CacheRepository
        // otherwise, and a per-injection `new Repository()` would lose the
        // confirmation token between requests.
        $this->app->singleton(VoxConfirmationService::class, function ($app) {
            return new VoxConfirmationService($app->make(CacheRepository::class));
        });
        $this->app->bind(CacheRepository::class, fn () => Cache::store());

        // Vox readiness probe · the constructor declares `hardening` as
        // nullable with a `null` default for testability (so unit tests
        // can instantiate it without an audit service). Laravel's
        // container honors the default and would inject `null` in
        // production, leaving the doctor / readiness reporting
        // "unknown" for the hardening audit forever. Bind explicitly so
        // the production resolution always carries the audit service.
        $this->app->singleton(VoxReadinessService::class, function ($app) {
            return new VoxReadinessService(
                $app->make(VoxMetricsService::class),
                $app->make(VoxV3PromotionGateService::class),
                $app->make(VoxV3HardeningAuditService::class),
            );
        });

        // Atlas Dev runtime keeps a nullable constructor for isolated unit
        // tests, but the production/container-resolved runtime must carry
        // AWIS enforcement so mutative Dev execution is workspace-gated.
        $this->app->singleton(AtlasDevRuntimeService::class, function ($app) {
            return new AtlasDevRuntimeService(
                $app->make(AtlasWorkspaceIntelligenceExecutionGateService::class),
            );
        });

        // Patamar 4 · TEOS-I3 × AURG-4D auto-chain.
        // TEOS-I3's `setAurgForChaining()` is an opt-in seam so unit tests can
        // create branches without writing temporal ticks. Production resolution
        // MUST wire the chain so every counterfactual branch emits an AURG-4D
        // tick — closing the Patamar 4 hook between TEOS and Reality Graph.
        $this->app->resolving(AtlasTeosI3CounterfactualService::class, function ($svc, $app) {
            if ($svc instanceof AtlasTeosI3CounterfactualService) {
                $svc->setAurgForChaining($app->make(AtlasUnifiedRealityGraphTemporalService::class));
            }
        });

        // Patamar 4 · Autonomy Admission consults Human Trust Ledger.
        // High operator trust track-record lifts the autonomy cap one tier;
        // low trust lowers it. Trust ledger requires DB — wiring is in the
        // resolving callback so unit tests that bypass the container don't pay
        // the DB cost.
        $this->app->resolving(\App\Services\Ai\Governance\AtlasAutonomyAdmissionService::class, function ($svc, $app) {
            if ($svc instanceof \App\Services\Ai\Governance\AtlasAutonomyAdmissionService) {
                try {
                    $svc->setTrustLedger($app->make(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::class));
                } catch (\Throwable $e) {
                    // Defensive: trust ledger may not be available in some
                    // environments; service stays in 'unknown' band gracefully.
                }
            }
        });

        // Patamar 4 · Reconciliation meta-cognition via TEOS-I3.
        // Reconciliation projects expected outcome before firing ASCB.propose().
        // Sub-threshold projections are suppressed (recorded honestly).
        $this->app->resolving(\App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService::class, function ($svc, $app) {
            if ($svc instanceof \App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService) {
                $svc->setTeosI3ForMetaProjection($app->make(AtlasTeosI3CounterfactualService::class));
            }
        });

        // Vox V3 governed executors. Order is irrelevant — the router keys
        // them by `id()`. Each executor self-reports availability so the
        // controller can advertise an honest health state.
        $this->app->singleton(VoxExecutorRouter::class, function ($app) {
            /** @var list<VoxExecutor> $executors */
            $executors = [
                $app->make(VoxTerminalProposeExecutor::class),
                $app->make(VoxNoteCaptureExecutor::class),
                $app->make(VoxCodexCliExecutor::class),
                $app->make(VoxClaudeCliExecutor::class),
                $app->make(VoxFilesystemEditExecutor::class),
            ];

            return new VoxExecutorRouter(
                executors: $executors,
                outcomes: $app->make(VoxActionOutcomeService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
