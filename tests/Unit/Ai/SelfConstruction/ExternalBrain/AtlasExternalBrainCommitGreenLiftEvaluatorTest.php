<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitGreenLiftEvaluator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCommitGreenLiftEvaluatorTest extends TestCase
{
    private AtlasExternalBrainCommitGreenLiftEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AtlasExternalBrainCommitGreenLiftEvaluator;
    }

    private function green(float $weight = 0.8): array
    {
        return ['status' => 'green_commit', 'impact_weight' => $weight];
    }

    private function giveBack(float $weight = 0.5): array
    {
        return ['status' => 'give_back', 'impact_weight' => $weight];
    }

    private function failed(float $weight = 0.5): array
    {
        return ['status' => 'failed', 'impact_weight' => $weight];
    }

    // ── Schema / output shape ──────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->evaluator->evaluate(['before' => [], 'after' => []]);

        foreach (['schema', 'green_commit_rate_delta', 'give_back_rate_delta', 'impact_weighted_lift', 'verdict'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::SCHEMA, $result['schema']);
    }

    // ── green_commit_rate_delta ───────────────────────────────────────────────

    public function test_green_commit_rate_delta_positive_when_more_greens_after(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->failed(), $this->failed()],
            'after'  => [$this->green(), $this->failed()],
        ]);

        $this->assertGreaterThan(0.0, $result['green_commit_rate_delta']);
    }

    public function test_green_commit_rate_delta_negative_when_fewer_greens_after(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(), $this->green()],
            'after'  => [$this->failed(), $this->failed()],
        ]);

        $this->assertLessThan(0.0, $result['green_commit_rate_delta']);
    }

    public function test_green_commit_rate_delta_zero_when_same_ratio(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(), $this->failed()],
            'after'  => [$this->green(), $this->failed()],
        ]);

        $this->assertSame(0.0, $result['green_commit_rate_delta']);
    }

    // ── give_back_rate_delta ──────────────────────────────────────────────────

    public function test_give_back_rate_delta_positive_when_more_give_backs_after(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->failed()],
            'after'  => [$this->giveBack()],
        ]);

        $this->assertGreaterThan(0.0, $result['give_back_rate_delta']);
    }

    public function test_give_back_rate_delta_zero_when_same(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->green()],
            'after'  => [$this->green()],
        ]);

        $this->assertSame(0.0, $result['give_back_rate_delta']);
    }

    // ── impact_weighted_lift ─────────────────────────────────────────────────

    public function test_impact_weighted_lift_positive_with_high_impact_greens(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->failed(), $this->failed()],
            'after'  => [$this->green(0.9), $this->failed()],
        ]);

        $this->assertGreaterThan(0.0, $result['impact_weighted_lift']);
    }

    public function test_impact_weighted_lift_accounts_for_weight(): void
    {
        // High-weight green in before, low-weight green in after
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(0.9), $this->failed(), $this->failed()],
            'after'  => [$this->green(0.1), $this->green(0.1), $this->failed()],
        ]);

        // before: weighted_green = 0.9/3 = 0.30; after: 0.2/3 = 0.067
        $this->assertLessThan(0.0, $result['impact_weighted_lift']);
    }

    // ── verdict: regression (a) low-impact-only lift ─────────────────────────

    public function test_verdict_regression_when_green_rises_via_low_impact_only(): void
    {
        // before: 1 high-impact green, 2 failed → weighted=0.9/3=0.30
        // after:  2 low-impact greens, 1 failed → weighted=0.2/3=0.067
        // green_delta > 0 but impact_weighted_lift < 0 → regression
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(0.9), $this->failed(), $this->failed()],
            'after'  => [$this->green(0.1), $this->green(0.1), $this->failed()],
        ]);

        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_REGRESSION, $result['verdict']);
        $this->assertGreaterThan(0.0, $result['green_commit_rate_delta']);
        $this->assertLessThan(0.0, $result['impact_weighted_lift']);
    }

    public function test_verdict_regression_when_green_rises_with_zero_impact_lift(): void
    {
        // before: 1 green(0.5), 1 failed → weighted=0.5/2=0.25
        // after:  2 green(0.25), 0 failed → weighted=0.5/2=0.25
        // impact_lift == 0 and green_delta > 0 → regression
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(0.5), $this->failed()],
            'after'  => [$this->green(0.25), $this->green(0.25)],
        ]);

        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_REGRESSION, $result['verdict']);
        $this->assertSame(0.0, $result['impact_weighted_lift']);
    }

    // ── verdict: regression (b) give_back worsened materially ────────────────

    public function test_verdict_regression_when_give_back_rate_worsens_materially(): void
    {
        // before: 1 green, 5 failed → give_back=0
        // after:  1 green, 1 give_back, 1 give_back, 1 give_back, 1 failed → give_back=3/5=0.6
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(), $this->failed(), $this->failed(), $this->failed(), $this->failed(), $this->failed()],
            'after'  => [$this->green(), $this->giveBack(), $this->giveBack(), $this->giveBack(), $this->failed()],
        ]);

        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_REGRESSION, $result['verdict']);
        $this->assertGreaterThan(0.05, $result['give_back_rate_delta']);
    }

    // ── verdict: improvement ─────────────────────────────────────────────────

    public function test_verdict_improvement_when_green_rate_and_impact_lift_both_rise(): void
    {
        // before: 0 greens
        // after:  2 high-impact greens out of 3
        $result = $this->evaluator->evaluate([
            'before' => [$this->failed(), $this->failed(), $this->failed()],
            'after'  => [$this->green(0.8), $this->green(0.7), $this->failed()],
        ]);

        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_IMPROVEMENT, $result['verdict']);
        $this->assertGreaterThan(0.0, $result['green_commit_rate_delta']);
        $this->assertGreaterThan(0.0, $result['impact_weighted_lift']);
    }

    // ── verdict: neutral ─────────────────────────────────────────────────────

    public function test_verdict_neutral_when_no_change(): void
    {
        $result = $this->evaluator->evaluate([
            'before' => [$this->green(0.5), $this->failed()],
            'after'  => [$this->green(0.5), $this->failed()],
        ]);

        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_NEUTRAL, $result['verdict']);
        $this->assertSame(0.0, $result['green_commit_rate_delta']);
    }

    public function test_verdict_neutral_when_both_empty(): void
    {
        $result = $this->evaluator->evaluate(['before' => [], 'after' => []]);
        $this->assertSame(AtlasExternalBrainCommitGreenLiftEvaluator::VERDICT_NEUTRAL, $result['verdict']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = [
            'before' => [$this->green(0.8), $this->failed()],
            'after'  => [$this->green(0.9), $this->green(0.7), $this->failed()],
        ];

        $a = $this->evaluator->evaluate($input);
        $b = $this->evaluator->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
