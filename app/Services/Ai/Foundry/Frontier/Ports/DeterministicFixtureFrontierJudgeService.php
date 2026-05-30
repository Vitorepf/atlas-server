<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

/**
 * Deterministic fixture judge — TEST-ONLY. Carries its own resolved
 * provider/model identity, asserts it differs from the generator on RESOLVED
 * identity (refuting with 'judge_equals_generator_blocked' when equal), then
 * applies a default-refute majority vote over the seats configured per
 * proposal_id. NEVER a real proposal/verdict source.
 *
 * Majority threshold = ceil(N/2) + 1 over the N seats. Any seat vote that is
 * not exactly 'accept' counts as a refutation (default-refute). The panel
 * survives only on an explicit accept majority.
 */
final class DeterministicFixtureFrontierJudgeService implements FrontierJudgePort
{
    /**
     * @param  array<string,list<string>>  $seatsByProposalId  proposal_id => list<'accept'|'refute'> (one per seat)
     */
    public function __construct(
        private readonly array $seatsByProposalId,
        private readonly string $judgeProviderResolved = 'fixture_judge_provider',
        private readonly string $judgeModelResolved = 'fixture-judge-model',
    ) {}

    public function adjudicate(array $proposal, array $context): array
    {
        $generatorProviderResolved = (string) ($context['generator_provider_resolved'] ?? '');
        $generatorModelResolved = (string) ($context['generator_model_resolved'] ?? '');

        // Prove the invariant on resolved identity, not labels.
        if ($this->judgeProviderResolved === $generatorProviderResolved
            || $this->judgeModelResolved === $generatorModelResolved) {
            return [
                'decision' => 'refute',
                'reason' => 'judge_equals_generator_blocked',
                'judge_provider_resolved' => $this->judgeProviderResolved,
                'judge_model_resolved' => $this->judgeModelResolved,
            ];
        }

        $proposalId = (string) ($proposal['proposal_id'] ?? '');
        $seats = $this->seatsByProposalId[$proposalId] ?? [];

        if ($seats === []) {
            return [
                'decision' => 'refute',
                'reason' => 'default_refute',
                'judge_provider_resolved' => $this->judgeProviderResolved,
                'judge_model_resolved' => $this->judgeModelResolved,
                'lenses' => [],
                'accept_count' => 0,
                'refute_count' => 0,
                'judge_count' => 0,
                'majority_threshold' => 1,
            ];
        }

        $lenses = [];
        $acceptCount = 0;
        $refuteCount = 0;
        $seat = 0;
        foreach ($seats as $vote) {
            $seat++;
            // Default-refute: anything not explicitly 'accept' is a refutation.
            $seatDecision = $vote === 'accept' ? 'accept' : 'refute';
            if ($seatDecision === 'accept') {
                $acceptCount++;
            } else {
                $refuteCount++;
            }
            $lenses[] = [
                'lens_id' => 'lens_'.$seat,
                'judge_label' => 'fixture:deterministic',
                'judge_provider' => $this->judgeProviderResolved,
                'judge_model' => $this->judgeModelResolved,
                'decision' => $seatDecision,
                'reason' => $seatDecision === 'accept' ? 'seat_accept' : 'default_refute',
            ];
        }

        $judgeCount = count($seats);
        $majorityThreshold = (int) ceil($judgeCount / 2) + 1;
        $survived = $acceptCount >= $majorityThreshold;

        return [
            'decision' => $survived ? 'accept' : 'refute',
            'reason' => $survived ? 'majority_accept' : 'default_refute',
            'judge_provider_resolved' => $this->judgeProviderResolved,
            'judge_model_resolved' => $this->judgeModelResolved,
            'lenses' => $lenses,
            'accept_count' => $acceptCount,
            'refute_count' => $refuteCount,
            'judge_count' => $judgeCount,
            'majority_threshold' => $majorityThreshold,
        ];
    }
}
