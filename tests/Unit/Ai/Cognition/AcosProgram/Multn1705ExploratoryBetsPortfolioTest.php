<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\ExploratoryBetsPortfolio;
use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;
use Tests\TestCase;

final class Multn1705ExploratoryBetsPortfolioTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/atlas-multn1705-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_positive_causal_effect_doubles_path_weight_with_receipt(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'path.proven', 9, 1);
        $this->seedRuns($ledger, 'baseline', 1, 9);

        $out = ExploratoryBetsPortfolio::evaluate([
            ['id' => 'a', 'path' => 'path.proven', 'rung' => 'task', 'path_yield' => null, 'weight' => 1.0],
        ], $this->gate($ledger), ['enabled' => true, 'k' => 1, 'window_id' => 'w-2026-07-12']);

        $this->assertSame('ok', $out['status']);
        $this->assertSame('double_down', $out['decisions'][0]['action']);
        $this->assertSame(2.0, $out['decisions'][0]['path_weight_multiplier']);
        $this->assertSame('admitted', $out['decisions'][0]['causal_effect']['reason']);
        $this->assertSame('w-2026-07-12', $out['receipts'][0]['window_id']);
        $this->assertSame(1, $out['receipts'][0]['k']);
        $this->assertSame('path.proven', $out['receipts'][0]['path']);
        $this->assertTrue($out['source']['uses_atlas_brain_causal_effect_gate']);
        $this->assertFalse($out['source']['writes_class_allocation_weights']);
    }

    public function test_negative_proven_effect_suspends_pending_evidence(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'path.bad', 1, 9);
        $this->seedRuns($ledger, 'baseline', 9, 1);

        $out = ExploratoryBetsPortfolio::evaluate([
            ['id' => 'a', 'path' => 'path.bad', 'rung' => 'task', 'path_yield' => null],
        ], $this->gate($ledger), ['enabled' => true, 'k' => 1, 'window_id' => 'w-neg']);

        $this->assertSame('suspend', $out['decisions'][0]['action']);
        $this->assertSame(PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE, $out['decisions'][0]['state']);
        $this->assertSame(PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE, $out['suspension_updates'][0]['to_state']);
        $this->assertLessThan(0.0, $out['decisions'][0]['causal_effect']['ci_high']);
        $this->assertSame('proven_negative_effect', $out['receipts'][0]['basis']);
    }

    public function test_suspended_path_reenters_when_evidence_turns_positive(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'path.recovered', 9, 1);
        $this->seedRuns($ledger, 'baseline', 1, 9);

        $out = ExploratoryBetsPortfolio::evaluate([
            ['id' => 'a', 'path' => 'path.recovered', 'rung' => 'task', 'path_yield' => null],
        ], $this->gate($ledger), [
            'enabled' => true,
            'k' => 1,
            'window_id' => 'w-recovery',
            'suspended_paths' => ['path.recovered' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE],
        ]);

        $this->assertSame('resume_and_double_down', $out['decisions'][0]['action']);
        $this->assertSame('active', $out['decisions'][0]['state']);
        $this->assertSame(PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE, $out['suspension_updates'][0]['from_state']);
        $this->assertSame('active', $out['suspension_updates'][0]['to_state']);
        $this->assertSame('evidence_turned_positive', $out['receipts'][0]['basis']);
    }

    public function test_insufficient_n_keeps_exploring_and_never_suspends(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'path.thin', 2, 1);
        $this->seedRuns($ledger, 'baseline', 9, 1);

        $out = ExploratoryBetsPortfolio::evaluate([
            ['id' => 'a', 'path' => 'path.thin', 'rung' => 'task', 'path_yield' => null],
        ], $this->gate($ledger), ['enabled' => true, 'k' => 1, 'window_id' => 'w-thin']);

        $this->assertSame('continue_exploring', $out['decisions'][0]['action']);
        $this->assertSame('exploring', $out['decisions'][0]['state']);
        $this->assertSame('insufficient_n', $out['decisions'][0]['causal_effect']['reason']);
        $this->assertSame([], $out['suspension_updates']);
        $this->assertSame(1.0, $out['decisions'][0]['path_weight_multiplier']);
    }

    public function test_flag_off_keeps_originated_slice_byte_identical(): void
    {
        $candidates = [
            ['id' => 'a', 'path' => 'path.a', 'rung' => 'task', 'path_yield' => null],
            ['id' => 'b', 'path' => 'path.b', 'rung' => 'task', 'path_yield' => null],
        ];

        $out = ExploratoryBetsPortfolio::evaluate($candidates, $this->gate($this->ledger()), [
            'enabled' => false,
            'k' => 1,
        ]);

        $this->assertSame('flag_disabled', $out['status']);
        $this->assertSame($candidates, $out['originated_candidates']);
        $this->assertSame([], $out['decisions']);
        $this->assertSame([], $out['receipts']);
    }

    public function test_only_k_unknown_yield_task_rung_bets_are_evaluated_inside_originated_slice(): void
    {
        $ledger = $this->ledger();
        $this->seedRuns($ledger, 'path.first', 9, 1);
        $this->seedRuns($ledger, 'path.second', 9, 1);
        $this->seedRuns($ledger, 'baseline', 1, 9);

        $out = ExploratoryBetsPortfolio::evaluate([
            ['id' => 'slice', 'path' => 'path.slice', 'rung' => 'slice', 'path_yield' => null],
            ['id' => 'known', 'path' => 'path.known', 'rung' => 'task', 'path_yield' => 0.5],
            ['id' => 'first', 'path' => 'path.first', 'rung' => 'task', 'path_yield' => null],
            ['id' => 'second', 'path' => 'path.second', 'rung' => 'task', 'path_yield' => null],
        ], $this->gate($ledger), ['enabled' => true, 'k' => 1, 'window_id' => 'w-k']);

        $this->assertSame(['path.first'], array_column($out['evaluated_bets'], 'path'));
        $this->assertSame('originated_slice_sub_policy', $out['source']['allocation_boundary']);
        $this->assertFalse($out['source']['recomputes_multk_06_allocation']);
    }

    private function ledger(): AtlasLoopPatternLearningLedger
    {
        return new AtlasLoopPatternLearningLedger($this->path);
    }

    private function gate(AtlasLoopPatternLearningLedger $ledger): AtlasBrainCausalEffectGate
    {
        return new AtlasBrainCausalEffectGate($ledger);
    }

    private function seedRuns(AtlasLoopPatternLearningLedger $ledger, string $patternId, int $successes, int $failures): void
    {
        for ($i = 0; $i < $successes; $i++) {
            $ledger->record([
                'pattern_id' => $patternId,
                'pattern_version' => '1.0.0',
                'objective_class' => 'originated_exploration',
                'result' => AtlasLoopPatternLearningLedger::RESULT_SUCCESS,
            ]);
        }

        for ($i = 0; $i < $failures; $i++) {
            $ledger->record([
                'pattern_id' => $patternId,
                'pattern_version' => '1.0.0',
                'objective_class' => 'originated_exploration',
                'result' => AtlasLoopPatternLearningLedger::RESULT_BLOCKED,
            ]);
        }
    }
}
