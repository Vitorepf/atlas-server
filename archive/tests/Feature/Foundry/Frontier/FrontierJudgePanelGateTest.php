<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate;
use App\Services\Ai\Foundry\Frontier\FrontierProposalAdversarialJudgeService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierJudgePort;
use Tests\TestCase;

/**
 * TEST-ONLY deterministic fixture judge. Self-labels 'fixture:' and is NEVER a
 * real adjudication source. Carries its own resolved provider/model and enforces
 * the I3 resolved-identity invariant: if its identity equals the generator's, it
 * refutes with judge_equals_generator_blocked.
 */
final class FixtureFrontierJudge implements FrontierJudgePort
{
    public function __construct(
        private readonly string $decision,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function adjudicate(array $proposal, array $context): array
    {
        $base = [
            'judge_label' => 'fixture:'.$this->provider.':'.$this->model,
            'judge_provider_resolved' => $this->provider,
            'judge_model_resolved' => $this->model,
        ];

        $genProvider = (string) ($context['generator_provider_resolved'] ?? '');
        $genModel = (string) ($context['generator_model_resolved'] ?? '');

        if ($this->provider === $genProvider && $this->model === $genModel) {
            return ['decision' => 'refute', 'reason' => 'judge_equals_generator_blocked'] + $base;
        }

        return ['decision' => $this->decision, 'reason' => $this->decision === 'accept' ? 'fixture_accept' : 'fixture_refute'] + $base;
    }
}

final class FrontierJudgePanelGateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function proposal(): array
    {
        return ['proposal_id' => 'prop_abc123', 'title' => 'Compose evidence harvester into frontier'];
    }

    /** @return array<string,mixed> */
    private function generatorContext(): array
    {
        return [
            'generator_provider_resolved' => 'gen_provider',
            'generator_model_resolved' => 'gen_model_premium',
        ];
    }

    public function test_strict_majority_of_accepts_survives_with_majority_confirmed_verdict(): void
    {
        // N=3, strict majority = ceil(3/2)+1 = 3 accepts.
        $gate = new FrontierJudgePanelGate([
            new FixtureFrontierJudge('accept', 'judge_p_a', 'judge_m_a'),
            new FixtureFrontierJudge('accept', 'judge_p_b', 'judge_m_b'),
            new FixtureFrontierJudge('accept', 'judge_p_c', 'judge_m_c'),
        ]);

        $out = $gate->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertTrue($out['survived']);
        $this->assertNull($out['drop_reason']);
        $this->assertSame('confirmed', $out['verdict']['verdict']);
        $this->assertTrue($out['verdict']['majority_confirmed']);
        $this->assertSame(3, $out['verdict']['majority_threshold']);
        $this->assertSame(FoundrySchemas::PROPOSAL_VERDICT, $out['verdict']['schema_version']);

        // proposal_verdict.v1 required keys present.
        $shape = FoundrySchemas::validateShape(FoundrySchemas::PROPOSAL_VERDICT, [
            'proposal_id' => $out['verdict']['proposal_id'],
            'verdict' => $out['verdict']['verdict'],
            'lenses' => $out['verdict']['lenses'],
            'majority_confirmed' => $out['verdict']['majority_confirmed'],
        ]);
        $this->assertTrue($shape['valid']);
    }

    public function test_below_majority_drops_with_majority_refute(): void
    {
        // 2 accepts < threshold 3 => majority_refute. The non-accept seat is an
        // explicit fixture refute (not a default), so this is majority, not default.
        $gate = new FrontierJudgePanelGate([
            new FixtureFrontierJudge('accept', 'judge_p_a', 'judge_m_a'),
            new FixtureFrontierJudge('accept', 'judge_p_b', 'judge_m_b'),
            new FixtureFrontierJudge('refute', 'judge_p_c', 'judge_m_c'),
        ]);

        $out = $gate->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertFalse($out['survived']);
        $this->assertSame(FrontierJudgePanelGate::DROP_MAJORITY_REFUTE, $out['drop_reason']);
        $this->assertSame('refuted', $out['verdict']['verdict']);
        $this->assertFalse($out['verdict']['majority_confirmed']);
        $this->assertSame(2, $out['verdict']['accept_count']);
    }

    public function test_seat_returning_non_accept_counts_as_refute_default_refute(): void
    {
        // A seat returning an unrecognized/empty decision counts as a refute
        // (default-refute). All-refute with no explicit decision => default_refute.
        $gate = new FrontierJudgePanelGate([
            new FixtureFrontierJudge('', 'judge_p_a', 'judge_m_a'),
            new FixtureFrontierJudge('', 'judge_p_b', 'judge_m_b'),
            new FixtureFrontierJudge('', 'judge_p_c', 'judge_m_c'),
        ]);

        $out = $gate->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertFalse($out['survived']);
        $this->assertSame(0, $out['verdict']['accept_count']);
        // Each lens normalized to refute.
        foreach ($out['verdict']['lenses'] as $lens) {
            $this->assertSame('refute', $lens['decision']);
        }
    }

    public function test_real_judge_with_no_execution_path_defaults_to_refute_honestly(): void
    {
        // Real port, no execution bridge => default refute => panel drops.
        $gate = new FrontierJudgePanelGate([
            new FrontierProposalAdversarialJudgeService('judge_p_a', 'judge_m_a'),
            new FrontierProposalAdversarialJudgeService('judge_p_b', 'judge_m_b'),
            new FrontierProposalAdversarialJudgeService('judge_p_c', 'judge_m_c'),
        ]);

        $out = $gate->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertFalse($out['survived']);
        $this->assertSame(FrontierJudgePanelGate::DROP_DEFAULT_REFUTE, $out['drop_reason']);
        foreach ($out['verdict']['lenses'] as $lens) {
            $this->assertSame('refute', $lens['decision']);
            $this->assertSame('judge_provider_real_execution_bridge_missing', $lens['reason']);
        }
    }

    public function test_judge_equal_to_generator_resolved_identity_refuses(): void
    {
        // A seat whose RESOLVED provider AND model equal the generator's =>
        // judge_equals_generator_blocked (asserted on resolved identity, not labels).
        $gate = new FrontierJudgePanelGate([
            new FixtureFrontierJudge('accept', 'judge_p_a', 'judge_m_a'),
            new FixtureFrontierJudge('accept', 'gen_provider', 'gen_model_premium'),
            new FixtureFrontierJudge('accept', 'judge_p_c', 'judge_m_c'),
        ]);

        $out = $gate->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertFalse($out['survived']);
        $this->assertSame(FrontierJudgePanelGate::DROP_JUDGE_EQUALS_GENERATOR, $out['drop_reason']);
        $this->assertFalse($out['verdict']['majority_confirmed']);
    }

    public function test_real_judge_equal_to_generator_refuses_before_dispatch(): void
    {
        $judge = new FrontierProposalAdversarialJudgeService('gen_provider', 'gen_model_premium');
        $verdict = $judge->adjudicate($this->proposal(), $this->generatorContext());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('judge_equals_generator_blocked', $verdict['reason']);
    }

    public function test_gate_performs_zero_canonical_or_code_write_and_is_deterministic(): void
    {
        $gate = new FrontierJudgePanelGate([
            new FixtureFrontierJudge('accept', 'judge_p_a', 'judge_m_a'),
            new FixtureFrontierJudge('accept', 'judge_p_b', 'judge_m_b'),
            new FixtureFrontierJudge('accept', 'judge_p_c', 'judge_m_c'),
        ]);

        $a = $gate->adjudicate($this->proposal(), $this->generatorContext());
        $b = $gate->adjudicate($this->proposal(), $this->generatorContext());

        // Deterministic verdict hash.
        $this->assertSame($a['verdict']['verdict_hash'], $b['verdict']['verdict_hash']);

        $policy = $a['verdict']['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['mutates_repo']);
        $this->assertFalse($policy['canonical_doc_write_allowed']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertFalse($policy['executed']);
    }
}
