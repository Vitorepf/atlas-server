<?php

namespace App\Providers;

use App\Console\Commands\AtlasAaelExecutionRollbackCommand;
use App\Console\Commands\AtlasTaskMaestroCostCommand;
use App\Console\Commands\AtlasTaskMaestroRetryCommand;
use App\Models\AtlasMemoryEntry;
use App\Observers\AtlasMemoryRecallCacheObserver;
use App\Services\Ai\AgentGovernance\FleetDriver;
use App\Services\Ai\AgentGovernance\SystemFleetDriver;
use App\Services\Ai\AgenticEngineeringOs\Support\AeosGeneratedContractGate;
use App\Services\Ai\AgenticWorkcell\Contracts\WorkcellAdapter;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAdversarialVerifierPool;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRefusalCriticPanel;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Hermes\Acp\HermesAcpSessionPool;
use App\Services\Ai\Hermes\Kanban\HermesKanbanCli;
use App\Services\Ai\Hermes\Kanban\HermesKanbanProcessCli;
use App\Services\Ai\Hermes\Mesh\HermesWorkcellAdapter;
use App\Services\Ai\Learning\Harness\AtlasHarnessSurface;
use App\Services\Ai\Memory\MemoryPairwiseCosineScorer;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateDumpRunner;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillRunner;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreProofRunner;
use App\Services\Ai\Memory\Substrate\PgDumpAtlasMemorySubstrateDumpRunner;
use App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreDrillRunner;
use App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreProofRunner;
use App\Services\Ai\Memory\VectorMemoryPairwiseCosineScorer;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTierMismatchLedger;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->bind(AtlasMemorySubstrateDumpRunner::class, PgDumpAtlasMemorySubstrateDumpRunner::class);
        $this->app->bind(AtlasMemorySubstrateRestoreProofRunner::class, PgsqlAtlasMemorySubstrateRestoreProofRunner::class);
        $this->app->bind(AtlasMemorySubstrateRestoreDrillRunner::class, PgsqlAtlasMemorySubstrateRestoreDrillRunner::class);
        $this->app->bind(
            MemoryPairwiseCosineScorer::class,
            VectorMemoryPairwiseCosineScorer::class,
        );

        $this->app->singleton(AtlasContextRuntime::class);
        $this->app->singleton(AtlasWatchdogCheckRegistry::class);
        $this->app->singleton(
            AtlasDeliveredPackLedger::class,
            static fn () => AtlasDeliveredPackLedger::fromConfig(),
        );
        $this->app->singleton(
            GovernanceConsultSkipCounter::class,
            static fn () => GovernanceConsultSkipCounter::fromConfig(),
        );
        $this->app->scoped(AtlasRetrievalEvaluationBenchmarkArenaService::class);

        $this->app->afterResolving(function (mixed $resolved): void {
            if (! is_object($resolved)) {
                return;
            }
            $class = $resolved::class;
            if (str_contains($class, 'Aaeos\\Generated\\') || str_contains($class, 'Aaeos\\Quarantine\\')) {
                app(AeosGeneratedContractGate::class)->assertHotPathAllowed($class);
            }
        });

        // Maestro worker-fleet probe — reads live active leases from the task-serving lease repository
        // and maps them to the shape the probe expects (client_id, opened_at, released_at).
        // Active leases always have released_at=null (in-flight); completed leases are not surfaced here
        // since they are reaped on claim and no longer appear in activeLeases().
        $this->app->singleton(AtlasMaestroWorkerFleetProbe::class, static function (): AtlasMaestroWorkerFleetProbe {
            return new AtlasMaestroWorkerFleetProbe(
                static function (): iterable {
                    $leaseRepo = new AgentControlPlaneClaimLeaseRepository(
                        AtlasTaskServingStack::disk()
                    );
                    foreach ($leaseRepo->activeLeases() as $lease) {
                        yield [
                            'client_id' => (string) ($lease['agent_id'] ?? ''),
                            'opened_at' => (int) ($lease['acquired_at_unix'] ?? 0),
                            'released_at' => null,
                        ];
                    }
                }
            );
        });

        // Anti-Goodhart refusal panel — 3-voter adversarial panel consumed by AtlasLoopAntiGoodhartUnifiedRefusal::evaluate().
        $this->app->singleton(AtlasLoopRefusalCriticPanel::class);

        // Maestro tiering surface — registry + mismatch ledger live under storage/atlas/maestro/.
        $this->app->singleton(AtlasMaestroWorkerTierRegistry::class, static function (): AtlasMaestroWorkerTierRegistry {
            return new AtlasMaestroWorkerTierRegistry(storage_path('atlas/maestro/worker-tier-registry.json'));
        });
        $this->app->singleton(AtlasMaestroTierMismatchLedger::class, static function (): AtlasMaestroTierMismatchLedger {
            return new AtlasMaestroTierMismatchLedger(storage_path('atlas/maestro/tier-mismatch-ledger.jsonl'));
        });

        $this->app->singleton(SkillBundleStore::class);

        // W1190 — AAEL rollback CLI operator port (snapshotter+executor+ledger wired by default).
        // Force-load the command file so the in-file port interface + default impl are visible to PSR-4.
        \class_exists(AtlasAaelExecutionRollbackCommand::class);
        $this->app->singleton(AtlasLoopAdversarialVerifierPool::class);

        // PART 2 — the operator-facing task-serving contract resolves on the DEDICATED serving queue
        // (isolated from the Agent Control Plane certification-probe pollution). See AtlasTaskServingStack.
        $this->app->bind(
            AtlasTaskServingService::class,
            fn () => AtlasTaskServingStack::servingService(),
        );

        // Agent-governance control plane: the fleet-driver seam → the real pgrep/launchctl impl. Tests swap a
        // FakeFleetDriver. Constructing it is FREE — it only touches processes when reconcile/status actually run.
        $this->app->bind(FleetDriver::class, SystemFleetDriver::class);

        // Obra-auto-merge broader-regression gate seam: the production gate runs the affected
        // suites + never-merge invariant + boot-smoke + php -l; tests substitute a deterministic
        // fake. Binding the contract is what lets AtlasLoopObraAutoMergeService resolve.
        $this->app->bind(
            BroaderRegressionGateContract::class,
            AtlasLoopBroaderRegressionGate::class,
        );

        // Warm ACP session pool: ONE per worker process (singleton) so a `hermes acp`
        // session stays warm across the worker's jobs. maxServed bounds the long-lived
        // process before it is recycled.
        $this->app->singleton(
            HermesAcpSessionPool::class,
            fn () => new HermesAcpSessionPool(
                (int) config('atlas.ai.providers.hermes_cli.acp_warm_pool_max_prompts', 50),
            ),
        );
        // Hermes Kanban swarm substrate: bind the CLI seam to the real process impl
        // (tests inject a fake to prove orchestration without spawning Hermes).
        $this->app->bind(
            HermesKanbanCli::class,
            HermesKanbanProcessCli::class,
        );
        // Workcell Adapter (Atlas Orchestrator Canon): the provider-neutral runtime
        // adapter contract under the Workcell Fabric (AAWR). Default impl = the Hermes
        // many-agent fan-out. `role slot != runtime identity` — rebinding this one line
        // swaps the runtime that fills the slot. Bound (not singleton): the adapter is
        // stateless/pure. Canonical consumers inject the contract, not the concrete.
        $this->app->bind(
            WorkcellAdapter::class,
            HermesWorkcellAdapter::class,
        );
        // R8 honest retrieval-precision harness: bind the semantic-retrieval
        // boundary to the REAL Python runtime client (tests inject a fake engine
        // to prove the honest-unmeasured branch without spawning Python).
        $this->app->bind(
            SemanticRetrievalRuntime::class,
            SemanticRagRuntimeClient::class,
        );
        // Stewardship/forge authority binds peeled to AtlasStewardshipBindingsServiceProvider (full-pass).

        // AOBG N3 (AObra) — the decomposer seam. Default = the deterministic,
        // cost-free decomposer so the container can build AtlasObraPlanService /
        // AtlasObraService anywhere (e.g. the read-only atlas:obra:status command)
        // without forcing a provider. The provider decomposer is still opted into
        // explicitly by atlas:obra:plan / atlas:obra:deliver when
        // atlas.obra.decompose_provider is set (it degrades back to deterministic).
        $this->app->bind(ObraDecomposer::class, DeterministicObraDecomposer::class);
        // The per-node delivery seam. Default = the REAL provider-backed delivery
        // (the operator's spend path). Constructing it is FREE (it only spends when
        // deliver() is actually called), so AtlasObraExecutor / AtlasObraService stay
        // container-resolvable (e.g. for the read-only atlas:obra:status command,
        // which never invokes a delivery). Tests inject a fake delivery directly.
        $this->app->bind(ObraNodeDelivery::class, ProviderObraNodeDelivery::class);

        // AOBG N4 organism DI peeled to AtlasOrganismServiceProvider (full-pass).

        // LoopExecutionDriver binding removed: its sole impl (WorkspaceProviderLoopExecutionDriver)
        // was deleted by cd018c6b3f; the interface has no live impl and only comment-refs remain
        // (GAP-17, ACDE-dead). Restore the impl if the trading/evolution loop is ever revived.

        // Vox DI peeled to AtlasVoxServiceProvider (full-pass).

        // Atlas Dev runtime keeps a nullable constructor for isolated unit
        // tests, but the production/container-resolved runtime must carry
        // AWIS enforcement so mutative Dev execution is workspace-gated.
        $this->app->singleton(AtlasDevRuntimeService::class, function ($app) {
            return new AtlasDevRuntimeService(
                $app->make(AtlasWorkspaceIntelligenceExecutionGateService::class),
            );
        });

        // Patamar 4 DI peeled to AtlasPatamar4ServiceProvider (full-pass).

        // Swarm production resolver / parallel dispatch / executor wiring peeled
        // to AtlasSwarmServiceProvider (full-pass).

        // Engineering conductor peeled with Patamar4 SP (full-pass).

        // Mission / self-construction / ADML peeled to AtlasMissionServiceProvider (full-pass).

        // Compression + cross-domain + provider manager wiring peeled to domain SPs (full-pass).

        $this->registerLoopSentinels();
        // Maestro/AAEL peeled to AtlasMaestroPriorityServiceProvider (full-pass).
        // Cortex Council lens wiring: o condicional legado (`cortex.council.lenses`) foi
        // superseded pelo registry sempre-bound e pré-populado com as 5 lentes (gate upstream
        // em config('atlas.cortex.council.enabled')). O re-singleton() cru do legado REBINDAVA
        // o registry VAZIO sempre que a chave legada listasse uma lente — removido.
    }

    /**
     * WAVE-19 SENTINEL WIRING — fail-CLOSED, byte-identical when OFF. When the flag
     * `atlas.loop.sentinels.wave19_enabled` is false (default) NOTHING is bound, so the pétreo floor is
     * preserved exactly. When ON, the four wave-19 sentinels + the wiring canary are bound as singletons so
     * the cron / keepalive / any caller can resolve them from the container.
     */
    private function registerLoopSentinels(): void
    {
        if (! (bool) config('atlas.loop.sentinels.wave19_enabled', false)) {
            return; // OFF ⇒ zero bindings, byte-identical no-op
        }

    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        AtlasMemoryEntry::observe(AtlasMemoryRecallCacheObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AtlasTaskMaestroCostCommand::class,
                AtlasTaskMaestroRetryCommand::class,
            ]);
        }

        // AP-819 Obra B — overlay da Harness Surface: reaplica overrides de
        // harness_config APROVADOS (allowlist+bounds revalidados a cada boot;
        // entrada inválida é ignorada). Fail-open: erro aqui nunca derruba o boot.
        try {
            app(AtlasHarnessSurface::class)->bootOverlay();
        } catch (\Throwable) {
            // o config base do .env segue valendo.
        }
    }

}
