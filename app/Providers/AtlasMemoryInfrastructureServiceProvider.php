<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AgenticEngineeringOs\Support\AeosGeneratedContractGate;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Memory\MemoryPairwiseCosineScorer;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateDumpRunner;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillRunner;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreProofRunner;
use App\Services\Ai\Memory\Substrate\PgDumpAtlasMemorySubstrateDumpRunner;
use App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreDrillRunner;
use App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreProofRunner;
use App\Services\Ai\Memory\VectorMemoryPairwiseCosineScorer;
use Illuminate\Support\ServiceProvider;

/**
 * Memory substrate + context runtime DI (full-pass ASP peel).
 */
final class AtlasMemoryInfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AtlasMemorySubstrateDumpRunner::class, PgDumpAtlasMemorySubstrateDumpRunner::class);
        $this->app->bind(AtlasMemorySubstrateRestoreProofRunner::class, PgsqlAtlasMemorySubstrateRestoreProofRunner::class);
        $this->app->bind(AtlasMemorySubstrateRestoreDrillRunner::class, PgsqlAtlasMemorySubstrateRestoreDrillRunner::class);
        $this->app->bind(MemoryPairwiseCosineScorer::class, VectorMemoryPairwiseCosineScorer::class);

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
    }
}
