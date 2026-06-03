<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;

/**
 * The BREADTH driver — invokes the configured provider CLI DIRECTLY against the
 * isolated scenario workspace to satisfy the task, governed solely by the loop's own
 * brain (the frozen judge), NOT by Atlas Dev's clarity/risk routing gate.
 *
 * WHY this exists: the default {@see SeniorLoopExecutionDriver} routes through the
 * Atlas Dev senior-loop, whose RoutingDecisionEngine sends ambiguous / hypothesis /
 * real-logic-change writes to PLAN-ONLY (so Dev does not burn tokens on unclear
 * tasks). That gate is correct for the interactive Dev product — but it blocks exactly
 * the VALUABLE autonomous work (numeric guards, edge-case logic), returning
 * `routing_not_executable`. The Loop is a distinct product with its OWN governance —
 * the frozen acceptance test, allowed_files scope, propose-only, never-merge, and a
 * disposable cp -R temp workspace — so it can use the provider muscle directly.
 *
 * PROVIDER-AGNOSTIC: the provider is resolved from the `provider_choice` surface hint
 * (or the loop default); execution flows through the canonical
 * {@see AtlasForgeProviderInvocationDriverRouter} (codex/cursor/hermes/gemini/minimax),
 * which is fail-closed (`isConfigured`), command-allowlisted, and attributes changed
 * files via a SEC-003 before/after delta. No provider is named here. It reuses the
 * proven Forge provider drivers — it does NOT fork a new execution stack.
 *
 * SAFETY: the provider can only touch the throwaway scenario workspace; any out-of-scope
 * or frozen-path edit is REJECTED by the frozen judge; nothing is ever merged.
 */
final class WorkspaceProviderLoopExecutionDriver implements LoopExecutionDriver
{
    public function __construct(
        private readonly AtlasForgeProviderInvocationDriverRouter $router,
    ) {}

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        $provider = trim((string) ($surfaceHints['provider_choice'] ?? '')) ?: trim((string) config('atlas.loop.default_provider', ''));
        if ($provider === '' || ! $this->router->isConfigured($provider)) {
            return ['status' => 'blocked', 'reason' => 'provider_not_configured:'.($provider ?: 'none'), 'provider_invoked' => false];
        }

        $allowedFiles = $this->parseConstraint($userConstraints, 'allowed_files');
        $validationCommands = $this->parseConstraint($userConstraints, 'validation_command');
        $prompt = $this->buildPrompt($intent, $allowedFiles, $validationCommands);
        $model = $this->resolveModel($provider);
        $timeout = max(60, (int) config('atlas.loop.campaign.attempt_hard_seconds', 900));

        $result = $this->router->invoke($provider, $model, $prompt, [
            'cwd' => $workspace,
            'timeout_seconds' => $timeout,
            'max_output_chars' => 16000,
        ]);

        $called = (bool) ($result['provider_called'] ?? false);

        return [
            'status' => $called ? 'completed' : 'blocked',
            'provider' => $provider,
            'provider_invoked' => $called,
            'changed_files' => $result['changed_files'] ?? [],
            'exit_code' => $result['exit_code'] ?? null,
            'reason' => $called ? null : (string) ($result['note'] ?? 'provider_not_called'),
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function buildPrompt(string $intent, array $allowedFiles, array $validationCommands): array
    {
        $lines = [
            'You are autonomously improving code in an ISOLATED throwaway workspace. Make the change directly by editing files in place.',
            '',
            'OBJECTIVE: '.$intent,
            '',
        ];
        if ($allowedFiles !== []) {
            $lines[] = 'Edit ONLY these files: '.implode(', ', $allowedFiles).'.';
        }
        $lines[] = 'Do NOT modify anything under tests/ or composer.json — those are the frozen acceptance and must stay untouched.';
        if ($validationCommands !== []) {
            $lines[] = 'Your change is correct only when this passes: '.implode(' && ', $validationCommands).'.';
        }
        $lines[] = 'Preserve all existing behavior; make the smallest change that satisfies the objective.';

        $text = implode("\n", $lines);

        return ['text' => $text, 'instruction' => $text, 'messages' => [['role' => 'user', 'content' => $text]]];
    }

    private function resolveModel(string $provider): ?string
    {
        $model = config('atlas.ai.providers.'.$provider.'.model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function parseConstraint(array $constraints, string $key): array
    {
        $out = [];
        foreach ($constraints as $c) {
            if (is_string($c) && str_starts_with($c, $key.'=')) {
                foreach (explode(',', substr($c, strlen($key) + 1)) as $v) {
                    $v = trim($v);
                    if ($v !== '') {
                        $out[] = $v;
                    }
                }
            }
        }

        return $out;
    }
}
