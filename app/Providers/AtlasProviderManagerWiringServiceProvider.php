<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use Illuminate\Support\ServiceProvider;

/**
 * AiProviderManager ADML/cache/compression/coverage wiring (full-pass ASP peel).
 */
final class AtlasProviderManagerWiringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Patamar 4 · AiProviderManager consults ADML before provider resolution.
        // Opt-in setter pattern: when consultation service is wired, callers
        // can request a learned route via getRecommended(). Existing get()
        // callers are untouched — zero break.
        $this->app->resolving(AiProviderManager::class, function ($svc, $app) {
            if ($svc instanceof AiProviderManager) {
                try {
                    $svc->setGatewayConsultation($app->make(AtlasDecideGatewayConsultationService::class));
                } catch (\Throwable $e) {
                    // Defensive: consultation service may not be resolvable
                    // in some test envs; manager stays in default mode.
                }

                // H1 (response cache) + H4 (per-operation cost guard) wiring.
                // Opt-in, config-gated (atlas.ai.cache.enabled, default false).
                // When deps don't resolve, or the flag is off, the manager
                // returns providers undecorated — zero break on existing
                // callers and tests.
                try {
                    $svc->setCacheDecoration(
                        $app->make(AiCallCostGuard::class),
                        $app->make(AtlasRuntimeEfficiencyGovernorService::class),
                        $app->make(AiCostEstimator::class),
                        $app->make(AtlasTokenEconomyBudgetPolicyService::class),
                    );
                } catch (\Throwable $e) {
                    // Defensive: any unresolved cache dep leaves the manager in
                    // its default, undecorated mode.
                }

                // AP-813 · compression layer decorator wiring. Opt-in, config-gated
                // (atlas.compression_layer.enabled, default false) and FAIL-OPEN.
                // When unresolved or off, the manager returns providers undecorated.
                try {
                    $svc->setCompressionPipeline($app->make(CompressionPipeline::class));
                } catch (\Throwable $e) {
                    // Defensive: compression stays unwired on any resolution failure.
                }

                // SLICE 1 — governance-coverage meter. Records the COVERED half
                // (a resolution through this governed manager) so the muscle
                // bypass rate is a real number. Best-effort; on any failure the
                // manager stays unmetered (byte-identical).
                try {
                    $svc->setCoverageLedger($app->make(ProviderGovernanceCoverageLedger::class));
                } catch (\Throwable $e) {
                    // Coverage measurement is best-effort; never a gate.
                }
            }
        });
    }
}
