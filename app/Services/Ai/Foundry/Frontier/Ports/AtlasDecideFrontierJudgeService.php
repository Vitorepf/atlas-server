<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

/**
 * Real Frontier judge — resolves the judge provider via AtlasDecide and
 * honestly blocks (default refute) because no real provider execution bridge
 * exists today. NEVER fabricates an accept.
 *
 * Model-difference is enforced on the RESOLVED provider AND model (the values
 * AtlasDecide actually selected), never on a free-form self-constructed label.
 * If either resolved pair equals the generator's, the judge refutes with
 * 'judge_equals_generator_blocked' BEFORE any dispatch. Otherwise, because
 * AtlasDecide execute() is prepare_only today, the judge defaults to refute
 * with 'judge_provider_real_execution_bridge_missing' — so I3 cannot pass with
 * the real port today, and proposals drop at I3 with a recorded reason.
 */
final class AtlasDecideFrontierJudgeService implements FrontierJudgePort
{
    public function __construct(
        private readonly \App\Services\Ai\AtlasDecideService $decide,
        private readonly string $judgeProvider = 'claude_codex',
    ) {}

    public function adjudicate(array $proposal, array $context): array
    {
        $decision = $this->decide->operationalDecision([
            'payload' => [
                'domain' => 'self_directed_evolution',
                'flow' => 'frontier_judging',
                'compute_effort' => 'premium',
                'operator_requested_provider' => $this->judgeProvider,
            ],
        ]);

        $decisionArray = $decision->toArray();
        $judgeProviderResolved = (string) data_get($decisionArray, 'provider_selection.selected_provider', '');
        $judgeModelResolved = (string) data_get($decisionArray, 'provider_selection.selected_model', '');

        $generatorProviderResolved = (string) ($context['generator_provider_resolved'] ?? '');
        $generatorModelResolved = (string) ($context['generator_model_resolved'] ?? '');

        // Model-difference rule on RESOLVED identity (never on a free-form label).
        if ($judgeProviderResolved === $generatorProviderResolved
            || $judgeModelResolved === $generatorModelResolved) {
            return [
                'decision' => 'refute',
                'reason' => 'judge_equals_generator_blocked',
                'judge_provider_resolved' => $judgeProviderResolved !== '' ? $judgeProviderResolved : null,
                'judge_model_resolved' => $judgeModelResolved !== '' ? $judgeModelResolved : null,
            ];
        }

        // Real-or-blocked: AtlasDecide execute() is prepare_only today; there is
        // no real provider execution bridge. We refuse to fabricate an accept.
        return [
            'decision' => 'refute',
            'reason' => 'judge_provider_real_execution_bridge_missing',
            'judge_provider_resolved' => $judgeProviderResolved !== '' ? $judgeProviderResolved : null,
            'judge_model_resolved' => $judgeModelResolved !== '' ? $judgeModelResolved : null,
        ];
    }
}
