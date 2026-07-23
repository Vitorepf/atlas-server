<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Ports\FrontierJudgePort;

/**
 * Foundry · Frontier real adversarial judge (AP-C, armor invariant I3).
 *
 * REAL-OR-BLOCKED. This is the production judge seat. It resolves its own
 * provider/model identity and, before any dispatch, enforces the I3
 * model-difference invariant on RESOLVED identity: if its resolved provider AND
 * model both equal the generator's resolved provider/model (carried in the
 * context), it refutes with 'judge_equals_generator_blocked'.
 *
 * Default behaviour is REFUTE. A proposal survives a seat ONLY on an explicit
 * accept returned by a real provider adjudication path. No such execution bridge
 * exists today (prepare-only ceiling), so this seat honestly returns
 * decision=refute, reason='judge_provider_real_execution_bridge_missing'. It
 * NEVER fabricates an accept.
 *
 * The deterministic fixture judge lives in the test suite and is labelled as a
 * fixture; it is never a real adjudication source.
 */
final class FrontierProposalAdversarialJudgeService implements FrontierJudgePort
{
    public function __construct(
        private readonly string $judgeProviderResolved,
        private readonly string $judgeModelResolved,
    ) {}

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $context
     * @return array{decision:string,reason:string,judge_label:string,judge_provider_resolved:string,judge_model_resolved:string}
     */
    public function adjudicate(array $proposal, array $context): array
    {
        $generatorProvider = trim((string) ($context['generator_provider_resolved'] ?? ''));
        $generatorModel = trim((string) ($context['generator_model_resolved'] ?? ''));

        $base = [
            'judge_label' => 'real:'.$this->judgeProviderResolved.':'.$this->judgeModelResolved,
            'judge_provider_resolved' => $this->judgeProviderResolved,
            'judge_model_resolved' => $this->judgeModelResolved,
        ];

        // I3 model-difference invariant on RESOLVED identity (provider AND model).
        if (
            $this->judgeProviderResolved === $generatorProvider
            && $this->judgeModelResolved === $generatorModel
        ) {
            return ['decision' => 'refute', 'reason' => 'judge_equals_generator_blocked'] + $base;
        }

        // Default-refute: no real provider execution path exists (prepare-only
        // ceiling), so the seat refutes honestly and never fabricates an accept.
        return ['decision' => 'refute', 'reason' => 'judge_provider_real_execution_bridge_missing'] + $base;
    }
}
