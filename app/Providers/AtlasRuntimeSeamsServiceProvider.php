<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\AtlasAaelExecutionRollbackCommand;
use App\Services\Ai\AgentGovernance\FleetDriver;
use App\Services\Ai\AgentGovernance\SystemFleetDriver;
use App\Services\Ai\AgenticWorkcell\Contracts\WorkcellAdapter;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAdversarialVerifierPool;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRefusalCriticPanel;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Hermes\Acp\HermesAcpSessionPool;
use App\Services\Ai\Hermes\Kanban\HermesKanbanCli;
use App\Services\Ai\Hermes\Kanban\HermesKanbanProcessCli;
use App\Services\Ai\Hermes\Mesh\HermesWorkcellAdapter;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTierMismatchLedger;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBroaderRegressionGate;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime seams: Maestro fleet/tier, serving, Hermes, workcell, fleet driver (full-pass ASP peel).
 */
final class AtlasRuntimeSeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        $this->app->singleton(AtlasLoopRefusalCriticPanel::class);

        $this->app->singleton(AtlasMaestroWorkerTierRegistry::class, static function (): AtlasMaestroWorkerTierRegistry {
            return new AtlasMaestroWorkerTierRegistry(storage_path('atlas/maestro/worker-tier-registry.json'));
        });
        $this->app->singleton(AtlasMaestroTierMismatchLedger::class, static function (): AtlasMaestroTierMismatchLedger {
            return new AtlasMaestroTierMismatchLedger(storage_path('atlas/maestro/tier-mismatch-ledger.jsonl'));
        });

        $this->app->singleton(SkillBundleStore::class);

        \class_exists(AtlasAaelExecutionRollbackCommand::class);
        $this->app->singleton(AtlasLoopAdversarialVerifierPool::class);

        $this->app->bind(
            AtlasTaskServingService::class,
            fn () => AtlasTaskServingStack::servingService(),
        );

        $this->app->bind(FleetDriver::class, SystemFleetDriver::class);

        $this->app->bind(
            BroaderRegressionGateContract::class,
            AtlasLoopBroaderRegressionGate::class,
        );

        $this->app->singleton(
            HermesAcpSessionPool::class,
            fn () => new HermesAcpSessionPool(
                (int) config('atlas.ai.providers.hermes_cli.acp_warm_pool_max_prompts', 50),
            ),
        );
        $this->app->bind(HermesKanbanCli::class, HermesKanbanProcessCli::class);
        $this->app->bind(WorkcellAdapter::class, HermesWorkcellAdapter::class);
        $this->app->bind(SemanticRetrievalRuntime::class, SemanticRagRuntimeClient::class);
    }
}
