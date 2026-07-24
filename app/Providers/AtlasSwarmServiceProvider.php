<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiWorker;
use App\Services\Ai\AtlasDecide\AtlasSwarmAutoFailoverService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmParallelDispatchService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Caching\AtlasProviderCostSentinel;
use Illuminate\Support\ServiceProvider;

/**
 * Patamar 4 Swarm DI (full-pass ASP peel).
 *
 * Production resolver (flag-gated circuit breaker), parallel dispatch
 * commandBuilder, executor resolver wiring, and AiWorker auto-failover.
 */
final class AtlasSwarmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A4 · Swarm Auto-Failover wired into AiWorker.
        $this->app->resolving(AiWorker::class, function ($svc, $app): void {
            if (! $svc instanceof AiWorker) {
                return;
            }
            try {
                $svc->setSwarmAutoFailover($app->make(AtlasSwarmAutoFailoverService::class));
            } catch (\Throwable $e) {
                // Defensive: missing failover never breaks worker.
            }
        });

        // Patamar 4 · F2 Swarm Production Resolver — opt-in via flag. When
        // enabled the executor's resolver becomes a real AiProviderManager
        // bridge with per-provider circuit breaker. Default OFF so tests
        // and stubbed environments keep behaving as before.
        $this->app->singleton(AtlasSwarmProductionResolverService::class, function ($app) {
            $threshold = (int) (config('atlas.patamar4.swarm_circuit_threshold', AtlasSwarmProductionResolverService::DEFAULT_CIRCUIT_THRESHOLD));
            $cooldown = (int) (config('atlas.patamar4.swarm_circuit_cooldown_seconds', AtlasSwarmProductionResolverService::DEFAULT_CIRCUIT_COOLDOWN_SECONDS));

            $resolver = new AtlasSwarmProductionResolverService(
                $app->make(AiProviderManager::class),
                $threshold,
                $cooldown,
            );

            // Step 1 staged rollout: spread the cost sentinel to the spend boundary
            // in observe (telemetry) — opt-in, default OFF so prod/tests are unchanged.
            // Enforcement only bites when the operator configures a positive ceiling.
            if ((bool) config('atlas.ai.cost_sentinel.enabled', false)) {
                $resolver->setCostSentinel(
                    $app->make(AtlasProviderCostSentinel::class),
                    storage_path('app/atlas-cost-telemetry.jsonl'),
                );
            }

            return $resolver;
        });

        // A5 · Default commandBuilder for AtlasSwarmParallelDispatchService.
        // Each arm spawns `php artisan atlas:swarm:execute-arm` carrying its
        // JSON payload; the subprocess delegates to AtlasSwarmProductionResolverService.
        $this->app->resolving(AtlasSwarmParallelDispatchService::class, function ($svc, $app): void {
            if (! $svc instanceof AtlasSwarmParallelDispatchService) {
                return;
            }
            $svc->setCommandBuilder(function (array $arm, array $context): array {
                $php = trim((string) shell_exec('which php')) ?: PHP_BINARY;
                $artisan = base_path('artisan');

                return [
                    $php,
                    $artisan,
                    'atlas:swarm:execute-arm',
                    '--arm-json='.json_encode($arm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    '--context-json='.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            });
        });

        $this->app->resolving(AtlasSwarmExecutorService::class, function ($svc, $app): void {
            if (! $svc instanceof AtlasSwarmExecutorService) {
                return;
            }
            if (! (bool) config('atlas.patamar4.swarm_production_resolver_enabled', false)) {
                return; // flag OFF — keep stub behaviour.
            }
            try {
                $resolver = $app->make(AtlasSwarmProductionResolverService::class);
                $svc->setResolver($resolver->asClosure());
            } catch (\Throwable $e) {
                // Defensive: failure to wire never breaks executor unit tests.
            }
        });
    }
}
