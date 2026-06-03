<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;

/**
 * Default {@see LoopExecutionDriver} — routes the evolution loop through the
 * proven Atlas Dev senior-loop, which resolves the provider through the
 * AiProviderManager / providerLock abstraction (config-driven default, per-task
 * overridable). No provider is named here; the engine is fully swappable by
 * rebinding {@see LoopExecutionDriver}.
 */
final class SeniorLoopExecutionDriver implements LoopExecutionDriver
{
    public function __construct(
        private readonly SeniorEngineerLoopExecutor $executor,
    ) {}

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        return $this->executor->run(
            surfaceId: $surfaceId,
            workspace: $workspace,
            rawIntent: $intent,
            userConstraints: $userConstraints,
            surfaceHints: $surfaceHints,
        )->toCanonicalArray();
    }
}
