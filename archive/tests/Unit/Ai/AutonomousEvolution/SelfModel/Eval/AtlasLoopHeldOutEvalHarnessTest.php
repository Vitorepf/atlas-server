<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Eval;

use App\Services\Ai\AutonomousEvolution\SelfModel\Eval\AtlasLoopHeldOutEvalHarness;
use PHPUnit\Framework\TestCase;

final class AtlasLoopHeldOutEvalHarnessTest extends TestCase
{
    public function test_all_correct_outputs_pass_with_full_rate(): void
    {
        $result = (new AtlasLoopHeldOutEvalHarness)->evaluate(
            [
                ['example_id' => 'a', 'expected' => 'ship'],
                ['example_id' => 'b', 'expected' => ['risk' => 'low']],
            ],
            [
                ['example_id' => 'a', 'produced' => 'ship'],
                ['example_id' => 'b', 'produced' => ['risk' => 'low']],
            ],
        );

        $this->assertSame('atlas.loop.self_model.held_out_eval.v1', $result['schema']);
        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(2, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1.0, $result['pass_rate']);
        $this->assertSame([], $result['regressions']);
    }

    public function test_single_mismatch_lowers_pass_rate_and_records_regression(): void
    {
        $result = (new AtlasLoopHeldOutEvalHarness)->evaluate(
            [
                ['example_id' => 'good', 'expected' => 'correct'],
                ['example_id' => 'bad', 'expected' => 'expected'],
            ],
            [
                ['example_id' => 'good', 'produced' => 'correct'],
                ['example_id' => 'bad', 'produced' => 'wrong'],
            ],
        );

        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0.5, $result['pass_rate']);
        $this->assertSame(['bad'], $result['regressions']);
    }

    public function test_missing_candidate_output_counts_as_fail(): void
    {
        $result = (new AtlasLoopHeldOutEvalHarness)->evaluate(
            [
                ['example_id' => 'seen', 'expected' => 'ok'],
                ['example_id' => 'missing', 'expected' => 'must-not-pass'],
            ],
            [
                ['example_id' => 'seen', 'produced' => 'ok'],
            ],
        );

        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0.5, $result['pass_rate']);
        $this->assertSame(['missing'], $result['regressions']);
    }

    public function test_empty_held_out_returns_zero_rate_without_error(): void
    {
        $result = (new AtlasLoopHeldOutEvalHarness)->evaluate(
            [],
            [
                ['example_id' => 'ignored', 'produced' => 'anything'],
            ],
        );

        $this->assertSame(0, $result['evaluated']);
        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0.0, $result['pass_rate']);
        $this->assertSame([], $result['regressions']);
    }

    public function test_evaluate_is_deterministic_across_two_runs(): void
    {
        $harness = new AtlasLoopHeldOutEvalHarness;
        $heldOut = [
            ['example_id' => 2, 'expected' => 'two'],
            ['example_id' => 1, 'expected' => 'one'],
        ];
        $candidateOutputs = [
            ['example_id' => 1, 'produced' => 'wrong'],
            ['example_id' => 2, 'produced' => 'two'],
        ];

        $first = $harness->evaluate($heldOut, $candidateOutputs);
        $second = $harness->evaluate($heldOut, $candidateOutputs);

        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame([1], $first['regressions']);
    }
}
