<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * The execution abstraction the evolution loop runs on.
 *
 * The loop NEVER depends on a concrete engine or a named provider. It depends on
 * THIS interface: "given an intent and a workspace, attempt the change in place
 * and return a provider-safe summary". The default implementation
 * ({@see SeniorLoopExecutionDriver}) routes through the Atlas Dev senior-loop +
 * AiProviderManager (provider resolved from the `provider_choice` surface hint,
 * config-driven default, per-task overridable). Swap this binding to swap the
 * whole engine; remove any single provider and the loop still runs.
 */
interface LoopExecutionDriver
{
    /**
     * Attempt to satisfy $intent by mutating $workspace in place. The caller's
     * frozen judge scores the resulting workspace independently — this summary is
     * advisory only, never the source of truth on success.
     *
     * @param  list<string>  $userConstraints  e.g. ['allowed_files=src/Foo.php', 'validation_command=...']
     * @param  array<string,mixed>  $surfaceHints  e.g. ['provider_choice' => '...'] (empty = engine default)
     * @return array<string,mixed>  a provider-safe summary (must include a 'status' string)
     */
    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array;
}
