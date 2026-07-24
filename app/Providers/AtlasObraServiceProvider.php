<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\ServiceProvider;

/**
 * AObra decomposer/delivery + Dev runtime DI (full-pass ASP peel).
 */
final class AtlasObraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ObraDecomposer::class, DeterministicObraDecomposer::class);
        $this->app->bind(ObraNodeDelivery::class, ProviderObraNodeDelivery::class);

        $this->app->singleton(AtlasDevRuntimeService::class, function ($app) {
            return new AtlasDevRuntimeService(
                $app->make(AtlasWorkspaceIntelligenceExecutionGateService::class),
            );
        });
    }
}
