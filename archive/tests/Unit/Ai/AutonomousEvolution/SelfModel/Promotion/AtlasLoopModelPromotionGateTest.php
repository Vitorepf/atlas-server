<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Promotion;

use App\Services\Ai\AutonomousEvolution\SelfModel\Promotion\AtlasLoopModelPromotionGate;
use PHPUnit\Framework\TestCase;

final class AtlasLoopModelPromotionGateTest extends TestCase
{
    public function test_strictly_better_candidate_with_receipt_and_no_regressions_is_promoted(): void
    {
        $result = (new AtlasLoopModelPromotionGate)->decide(
            ['pass_rate' => 0.95, 'regressions' => []],
            ['pass_rate' => 0.90, 'regressions' => []],
            true,
        );

        $this->assertSame('atlas.loop.self_model.model_promotion_gate.v1', $result['schema']);
        $this->assertTrue($result['promote']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_better_candidate_without_receipt_is_blocked(): void
    {
        $result = (new AtlasLoopModelPromotionGate)->decide(
            ['pass_rate' => 0.95, 'regressions' => []],
            ['pass_rate' => 0.90, 'regressions' => []],
            false,
        );

        $this->assertFalse($result['promote']);
        $this->assertSame(['operator_receipt_required'], $result['blocking_reasons']);
    }

    public function test_guardrail_regression_blocks_even_when_candidate_is_better_and_receipted(): void
    {
        $result = (new AtlasLoopModelPromotionGate)->decide(
            ['pass_rate' => 0.99, 'regressions' => ['held-out-17']],
            ['pass_rate' => 0.10, 'regressions' => []],
            true,
        );

        $this->assertFalse($result['promote']);
        $this->assertContains('guardrail_regression', $result['blocking_reasons']);
    }

    public function test_missing_candidate_eval_fails_closed(): void
    {
        $result = (new AtlasLoopModelPromotionGate)->decide(
            [],
            ['pass_rate' => 0.90, 'regressions' => []],
            true,
        );

        $this->assertFalse($result['promote']);
        $this->assertContains('candidate_eval_missing', $result['blocking_reasons']);
    }

    public function test_no_input_without_operator_receipt_can_promote(): void
    {
        $gate = new AtlasLoopModelPromotionGate;
        $candidates = [
            ['pass_rate' => 1.0, 'regressions' => []],
            ['pass_rate' => 1.0, 'regressions' => ['regressed']],
            ['pass_rate' => 0.5, 'regressions' => []],
            [],
        ];

        foreach ($candidates as $candidate) {
            $result = $gate->decide($candidate, ['pass_rate' => 0.0, 'regressions' => []], false);

            $this->assertFalse($result['promote']);
            $this->assertContains('operator_receipt_required', $result['blocking_reasons']);
        }
    }

    public function test_equal_candidate_is_not_strictly_better(): void
    {
        $result = (new AtlasLoopModelPromotionGate)->decide(
            ['pass_rate' => 0.90, 'regressions' => []],
            ['pass_rate' => 0.90, 'regressions' => []],
            true,
        );

        $this->assertFalse($result['promote']);
        $this->assertSame(['candidate_not_better'], $result['blocking_reasons']);
    }
}
