<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;

/**
 * Narrow port that wraps the root-namespace collaborators the Discovery refiller needs (the persistence
 * store + the loop-back service). The Consolidation port becomes the SINGLE referenceable shape for the
 * root collaborators — the Discovery refiller can resolve them through this seam rather than reaching
 * back into root concretes, which is what breaks the long-standing root↔Discovery import cycle.
 *
 * Bound in AppServiceProvider so the Refiller's port-driven path picks up the live root collaborators
 * at runtime; legacy refiller construction sites that still pass the concretes directly remain valid.
 */
final readonly class AtlasLoopRefillerRootCollaborators
{
    public function __construct(
        public AtlasLoopStore $store,
        public AtlasLoopBackService $loopBack,
    ) {}
}
