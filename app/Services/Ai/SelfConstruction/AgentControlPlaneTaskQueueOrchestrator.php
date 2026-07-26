<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * ASDD D10 rehome: canonical class lives under ControlPlane\\TaskQueue.
 * This stub preserves the historical FQCN for consumers/tests during the
 * alias window. Do not add behavior here.
 */
class_alias(
    \App\Services\Ai\SelfConstruction\ControlPlane\TaskQueue\AgentControlPlaneTaskQueueOrchestrator::class,
    AgentControlPlaneTaskQueueOrchestrator::class
);
