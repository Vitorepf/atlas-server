<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierEndToEndTrial;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierEndToEndTrialTest extends TestCase
{
    private AtlasExternalBrainAmplifierEndToEndTrial $trial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trial = new AtlasExternalBrainAmplifierEndToEndTrial;
    }

    private function goodDims(): array
    {
        return [
            'correctness' => ['small_model_score' => 0.60, 'scaffolded_score' => 0.85, 'frontier_score' => 0.95],
            'completeness' => ['small_model_score' => 0.55, 'scaffolded_score' => 0.80, 'frontier_score' => 0.90],
        ];
    }

    private function goodQualityMetrics(array $overrides = []): array
    {
        return array_merge([
            'baseline' => [
                'valid_seed_rate'     => 0.70,
                'accepted_by_gate_rate' => 0.65,
                'later_green_rate'    => 0.60,
                'give_back_rate'      => 0.20,
                'proxy_rate'          => 0.15,
                'average_cost'        => 5.0,
            ],
            'scaffolded' => [
                'valid_seed_rate'     => 0.80,
                'accepted_by_gate_rate' => 0.75,
                'later_green_rate'    => 0.70,
                'give_back_rate'      => 0.15,
                'proxy_rate'          => 0.10,
                'average_cost'        => 3.0,
            ],
            'sample_count' => 20,
        ], $overrides);
    }

    // ── AC2: tier_scores, scaffold_lift, frontier_gain, recommendation present and deterministic

    public function test_ac2_output_has_required_fields(): void
    {
        $result = $this->trial->run(['benchmark_dimensions' => $this->goodDims()]);

        $this->assertArrayHasKey('tier_scores',       $result);
        $this->assertArrayHasKey('scaffold_lift',     $result);
        $this->assertArrayHasKey('frontier_gain',     $result);
        $this->assertArrayHasKey('recommendation',    $result);

        $ts = $result['tier_scores'];
        $this->assertArrayHasKey('small_model',             $ts);
        $this->assertArrayHasKey('scaffolded_small_model',  $ts);
        $this->assertArrayHasKey('frontier_model',          $ts);
    }

    public function test_ac2_tier_scores_are_averages_of_dimensions(): void
    {
        $result = $this->trial->run(['benchmark_dimensions' => $this->goodDims()]);

        $this->assertEqualsWithDelta(0.575, $result['tier_scores']['small_model'],            0.001);
        $this->assertEqualsWithDelta(0.825, $result['tier_scores']['scaffolded_small_model'], 0.001);
        $this->assertEqualsWithDelta(0.925, $result['tier_scores']['frontier_model'],         0.001);
    }

    public function test_ac2_scaffold_lift_and_frontier_gain_computed_correctly(): void
    {
        $result = $this->trial->run(['benchmark_dimensions' => $this->goodDims()]);

        $this->assertEqualsWithDelta(0.25, $result['scaffold_lift'],  0.001);
        $this->assertEqualsWithDelta(0.10, $result['frontier_gain'],  0.001);
    }

    public function test_ac2_same_input_produces_identical_output(): void
    {
        $facts = ['benchmark_dimensions' => $this->goodDims()];

        $this->assertSame($this->trial->run($facts), $this->trial->run($facts));
    }

    // ── AC3: low sample_count → verdict=low_sample, trial_passes=false

    public function test_ac3_low_sample_count_returns_low_sample_verdict(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics'      => $this->goodQualityMetrics(['sample_count' => 5]),
        ]);

        $bvs = $result['baseline_vs_scaffolded'];
        $this->assertSame('low_sample', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
    }

    public function test_ac3_sample_count_of_one_returns_low_sample(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics'      => $this->goodQualityMetrics(['sample_count' => 1]),
        ]);

        $this->assertSame('low_sample', $result['baseline_vs_scaffolded']['verdict']);
        $this->assertFalse($result['baseline_vs_scaffolded']['trial_passes']);
    }

    public function test_ac3_zero_sample_count_is_treated_as_no_data(): void
    {
        // sample_count=0 skips the low-sample check — baseline/scaffolded present → lift/no_lift
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics'      => $this->goodQualityMetrics(['sample_count' => 0]),
        ]);

        $this->assertNotSame('low_sample', $result['baseline_vs_scaffolded']['verdict']);
    }

    public function test_ac3_sufficient_sample_count_does_not_return_low_sample(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics'      => $this->goodQualityMetrics(['sample_count' => 20]),
        ]);

        $this->assertNotSame('low_sample', $result['baseline_vs_scaffolded']['verdict']);
        $this->assertTrue($result['baseline_vs_scaffolded']['trial_passes']);
    }

    // ── AC4: cheaper but worsens proxy_rate or give_back_rate → cheaper_but_worse, trial_passes=false

    public function test_ac4_cheaper_but_worse_proxy_rate_fails_trial(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics' => $this->goodQualityMetrics([
                'scaffolded' => [
                    'valid_seed_rate'      => 0.80,
                    'accepted_by_gate_rate' => 0.75,
                    'later_green_rate'     => 0.70,
                    'give_back_rate'       => 0.20,   // unchanged
                    'proxy_rate'           => 0.25,   // worsened by 0.10 > threshold
                    'average_cost'         => 3.0,    // cheaper
                ],
                'sample_count' => 20,
            ]),
        ]);

        $bvs = $result['baseline_vs_scaffolded'];
        $this->assertSame('cheaper_but_worse', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
    }

    public function test_ac4_cheaper_but_worse_give_back_rate_fails_trial(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics' => $this->goodQualityMetrics([
                'scaffolded' => [
                    'valid_seed_rate'      => 0.80,
                    'accepted_by_gate_rate' => 0.75,
                    'later_green_rate'     => 0.70,
                    'give_back_rate'       => 0.30,   // worsened by 0.10 > threshold
                    'proxy_rate'           => 0.15,   // unchanged
                    'average_cost'         => 3.0,    // cheaper
                ],
                'sample_count' => 20,
            ]),
        ]);

        $bvs = $result['baseline_vs_scaffolded'];
        $this->assertSame('cheaper_but_worse', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
    }

    public function test_ac4_cheaper_and_better_quality_passes_trial(): void
    {
        $result = $this->trial->run([
            'benchmark_dimensions' => $this->goodDims(),
            'quality_metrics'      => $this->goodQualityMetrics(['sample_count' => 20]),
        ]);

        $bvs = $result['baseline_vs_scaffolded'];
        $this->assertNotSame('cheaper_but_worse', $bvs['verdict']);
        $this->assertTrue($bvs['trial_passes']);
    }
}
