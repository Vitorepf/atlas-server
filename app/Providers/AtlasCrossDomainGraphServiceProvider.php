<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Engineering\CodeGraph\CrossDomainGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainGraphTraversalService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\ServiceProvider;

/**
 * AP-814 cross-domain graph DI with mesh injection (full-pass ASP peel).
 */
final class AtlasCrossDomainGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AP-814 M-8 cross-domain graph: bind with the mesh EXPLICITLY injected. The
        // nullable `?AtlasCrossDomainMeshService` ctor param is not auto-resolved by the
        // container (it passes null), so app()-resolved instances would otherwise get a
        // mesh-less, edge-sparse graph (no allowed-crossing edges, no ARPTL veto).
        $this->app->bind(CrossDomainGraphIngestionService::class, function ($app) {
            $mesh = null;
            try {
                $mesh = $app->make(AtlasCrossDomainMeshService::class);
            } catch (\Throwable $e) {
                // fail-open: handoff/entity edges still build without the mesh.
            }

            return new CrossDomainGraphIngestionService(
                $app->make(CrossDomainTaxonomyMap::class),
                $mesh,
            );
        });
        $this->app->bind(CrossDomainGraphTraversalService::class, function ($app) {
            $mesh = null;
            try {
                $mesh = $app->make(AtlasCrossDomainMeshService::class);
            } catch (\Throwable $e) {
                // fail-open: traversal applies the conservative floor without the mesh.
            }

            return new CrossDomainGraphTraversalService(
                $app->make(CrossDomainGraphIngestionService::class),
                $app->make(CrossDomainTaxonomyMap::class),
                $mesh,
            );
        });
    }
}
