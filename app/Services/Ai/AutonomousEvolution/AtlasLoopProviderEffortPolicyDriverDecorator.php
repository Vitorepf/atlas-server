<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Decorates any {@see LoopExecutionDriver} with the provider effort policy. It
 * sits BELOW provider routing: the router has already chosen the provider/tier,
 * this decorator only sets the Hermes `reasoning_effort` hint on the surface
 * hints handed to the inner driver.
 *
 * Flag OFF (default) preserves the configured default effort — byte-identical
 * behaviour to the inner driver beyond the advisory hint. Flag ON lets the task
 * class lower/raise effort deterministically via {@see AtlasLoopProviderEffortPolicy}.
 *
 * Names no provider and composes with any inner driver.
 */
final class AtlasLoopProviderEffortPolicyDriverDecorator implements LoopExecutionDriver
{
    public function __construct(
        private readonly LoopExecutionDriver $inner,
        private readonly ?AtlasLoopProviderEffortPolicy $policy = null,
    ) {
    }

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        $policy = $this->policy ?? new AtlasLoopProviderEffortPolicy;
        if (! (bool) config('atlas.loop.provider_effort_policy_enabled', false)) {
            $surfaceHints['reasoning_effort'] = $policy->defaultEffort();
            $surfaceHints['provider_effort_policy'] = [
                'schema' => AtlasLoopProviderEffortPolicy::SCHEMA,
                'enabled' => false,
                'effort' => $surfaceHints['reasoning_effort'],
            ];

            return $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
        }

        $decision = $policy->resolve([
            'objective_kind' => (string) ($surfaceHints['objective_kind'] ?? $this->inferObjectiveKind($intent)),
            'target_kind' => (string) ($surfaceHints['target_kind'] ?? ''),
            'attempt_index' => (int) ($surfaceHints['attempt_index'] ?? 0),
            'prior_failures' => (int) ($surfaceHints['prior_failures'] ?? 0),
            'routed_provider_tier' => (string) ($surfaceHints['routed_provider_tier'] ?? 'unknown'),
        ]);
        $surfaceHints['reasoning_effort'] = $decision['effort'];
        $surfaceHints['provider_effort_policy'] = $decision;

        return $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
    }

    private function inferObjectiveKind(string $intent): string
    {
        $lower = strtolower($intent);
        foreach (['verification', 'characterization', 'refactor', 'architect', 'architecture', 'merge_readiness'] as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle;
            }
        }

        return 'unknown';
    }
}
