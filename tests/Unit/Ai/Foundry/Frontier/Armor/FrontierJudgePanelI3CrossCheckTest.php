<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierJudgePort;
use PHPUnit\Framework\TestCase;

/**
 * I3 defense-in-depth: a judge seat resolving to the SAME provider+model as the
 * generator is forced to refute (the adversary cannot collapse into the
 * generator), independent of the trust-only label. Preserved invariants
 * (default-refute, strict majority) must remain at least as strict.
 */
final class FrontierJudgePanelI3CrossCheckTest extends TestCase
{
    private function judge(array $raw): FrontierJudgePort
    {
        return new class($raw) implements FrontierJudgePort
        {
            public function __construct(private array $raw) {}

            public function adjudicate(array $proposal, array $context): array
            {
                return $this->raw;
            }
        };
    }

    private function context(): array
    {
        return [
            'generator_provider_resolved' => 'anthropic',
            'generator_model_resolved' => 'opus',
        ];
    }

    public function test_same_model_judge_returning_accept_is_forced_to_refute(): void
    {
        // A misconfigured seat: same provider+model as generator, yet returns accept.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'mirror',
                'judge_provider_resolved' => 'anthropic',
                'judge_model_resolved' => 'opus',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'real-a',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'real-b',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
        ]);

        $out = $gate->adjudicate(['proposal_id' => 'p1'], $this->context());

        $this->assertFalse($out['survived']);
        $this->assertSame(
            FrontierJudgePanelGate::DROP_JUDGE_EQUALS_GENERATOR,
            $out['drop_reason']
        );
        // The mirror seat was forced to refute.
        $seat1 = $out['verdict']['lenses'][0];
        $this->assertSame('refute', $seat1['decision']);
        $this->assertSame(
            FrontierJudgePanelGate::DROP_JUDGE_EQUALS_GENERATOR,
            $seat1['reason']
        );
    }

    public function test_distinct_model_judge_keeps_its_real_decision(): void
    {
        // All seats on distinct models; legitimate accept majority survives.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'a',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'b',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'c',
                'judge_provider_resolved' => 'meta',
                'judge_model_resolved' => 'llama',
            ]),
        ]);

        $out = $gate->adjudicate(['proposal_id' => 'p2'], $this->context());

        $this->assertTrue($out['survived']);
        $this->assertNull($out['drop_reason']);
        $this->assertSame(3, $out['verdict']['accept_count']);
        foreach ($out['verdict']['lenses'] as $lens) {
            $this->assertSame('accept', $lens['decision']);
        }
    }

    public function test_same_provider_different_model_is_not_forced(): void
    {
        // Same provider but different model must NOT trigger the forced refute.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'a',
                'judge_provider_resolved' => 'anthropic',
                'judge_model_resolved' => 'sonnet',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'b',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'c',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
        ]);

        $out = $gate->adjudicate(['proposal_id' => 'p3'], $this->context());

        $this->assertTrue($out['survived']);
        $this->assertNull($out['drop_reason']);
        $this->assertSame('accept', $out['verdict']['lenses'][0]['decision']);
    }

    public function test_empty_unresolved_judge_identity_does_not_trigger_forced_refute(): void
    {
        // Empty resolved identity must never match an empty generator-side miss.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'a',
                'judge_provider_resolved' => '',
                'judge_model_resolved' => '',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'b',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'c',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
        ]);

        // Generator identity also empty: an empty seat must NOT collapse.
        $out = $gate->adjudicate(['proposal_id' => 'p4'], [
            'generator_provider_resolved' => '',
            'generator_model_resolved' => '',
        ]);

        $this->assertTrue($out['survived']);
        $this->assertNull($out['drop_reason']);
    }

    public function test_default_refute_invariant_unchanged_for_legit_panel(): void
    {
        // No seat accepts, none gives an explicit reason -> default_refute.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'judge_label' => 'a',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'judge_label' => 'b',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
            $this->judge([
                'judge_label' => 'c',
                'judge_provider_resolved' => 'meta',
                'judge_model_resolved' => 'llama',
            ]),
        ]);

        $out = $gate->adjudicate(['proposal_id' => 'p5'], $this->context());

        $this->assertFalse($out['survived']);
        $this->assertSame(
            FrontierJudgePanelGate::DROP_DEFAULT_REFUTE,
            $out['drop_reason']
        );
    }

    public function test_majority_semantics_unchanged_two_accepts_one_refute(): void
    {
        // strict majority for 3 seats is ceil(3/2)+1 = 3, so 2 accepts refutes.
        $gate = new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'a',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'b',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
            $this->judge([
                'decision' => 'refute',
                'reason' => 'weak_evidence',
                'judge_label' => 'c',
                'judge_provider_resolved' => 'meta',
                'judge_model_resolved' => 'llama',
            ]),
        ]);

        $out = $gate->adjudicate(['proposal_id' => 'p6'], $this->context());

        $this->assertFalse($out['survived']);
        $this->assertSame(
            FrontierJudgePanelGate::DROP_MAJORITY_REFUTE,
            $out['drop_reason']
        );
        $this->assertSame(3, $out['verdict']['majority_threshold']);
    }

    public function test_verdict_hash_is_deterministic(): void
    {
        $build = fn () => new FrontierJudgePanelGate([
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'a',
                'judge_provider_resolved' => 'openai',
                'judge_model_resolved' => 'o3',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'b',
                'judge_provider_resolved' => 'google',
                'judge_model_resolved' => 'gemini',
            ]),
            $this->judge([
                'decision' => 'accept',
                'judge_label' => 'c',
                'judge_provider_resolved' => 'meta',
                'judge_model_resolved' => 'llama',
            ]),
        ]);

        $a = $build()->adjudicate(['proposal_id' => 'p7'], $this->context());
        $b = $build()->adjudicate(['proposal_id' => 'p7'], $this->context());

        $this->assertSame(
            $a['verdict']['verdict_hash'],
            $b['verdict']['verdict_hash']
        );
    }
}
