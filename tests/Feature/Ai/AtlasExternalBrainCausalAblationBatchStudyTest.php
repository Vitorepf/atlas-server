<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCausalAblationBatchStudy;
use Tests\TestCase;

final class AtlasExternalBrainCausalAblationBatchStudyTest extends TestCase
{
    private function linearBatches(int $n, callable $dimFactory): array
    {
        $batches = [];
        for ($i = 1; $i <= $n; $i++) {
            $f = round($i / $n, 4);
            $batches[] = [
                'outcome_dimensions' => [
                    'value_proof_rate' => $f,
                    'commit_success_rate' => $f,
                    'compounding_impact' => $f,
                    'give_back_rate' => round(1.0 - $f, 4),
                    'poison_rate' => round(1.0 - $f, 4),
                    'implementation_cost' => round(1.0 - $f, 4),
                ],
                'dimensions' => $dimFactory($i),
            ];
        }

        return $batches;
    }

    public function test_composite_score_rewards_good_outcomes_and_penalizes_bad_outcomes(): void
    {
        $svc = new AtlasExternalBrainCausalAblationBatchStudy;
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $result = $svc->study(['batches' => $batches, 'min_sample_size' => 10]);

        $dims = array_column($result['likely_positive_causes'], 'dimension');
        $this->assertContains('scope_size', $dims);
        $this->assertEmpty($result['likely_negative_causes']);
    }

    public function test_confounded_dimensions_above_threshold_are_excluded_from_causal_lists(): void
    {
        $svc = new AtlasExternalBrainCausalAblationBatchStudy;
        $batches = $this->linearBatches(12, static fn ($i) => ['dim_a' => $i, 'dim_b' => $i]);
        $result = $svc->study(['batches' => $batches, 'min_sample_size' => 10]);

        $confDims = array_column($result['confounders'], 'dimension');
        $this->assertContains('dim_a', $confDims);
        $this->assertContains('dim_b', $confDims);

        $causalDims = array_merge(
            array_column($result['likely_positive_causes'], 'dimension'),
            array_column($result['likely_negative_causes'], 'dimension'),
        );
        $this->assertNotContains('dim_a', $causalDims);
        $this->assertNotContains('dim_b', $causalDims);
    }

    public function test_confidence_is_weak_for_small_sample(): void
    {
        $svc = new AtlasExternalBrainCausalAblationBatchStudy;
        $batches = $this->linearBatches(3, static fn ($i) => ['scope_size' => $i]);
        $result = $svc->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('weak', $result['confidence']);
    }

    public function test_confidence_is_medium_with_some_confounders(): void
    {
        $svc = new AtlasExternalBrainCausalAblationBatchStudy;
        $n = 12;
        $batches = $this->linearBatches($n, static fn ($i) => [
            'dim_a' => $i,
            'dim_b' => $i,
            // three independent positive causes uncorrelated with dim_a/dim_b and each other,
            // so causal_count (3) exceeds confounder_count (2) -> medium confidence.
            'dim_c' => ($i % 2 === 0) ? $i : 0,
            'dim_d' => ($i % 3 === 0) ? $i : 0,
            'dim_e' => ($i % 5 === 0) ? $i : 0,
        ]);
        $result = $svc->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('medium', $result['confidence']);
    }

    public function test_confidence_is_high_for_adequate_sample_with_no_confounders(): void
    {
        $svc = new AtlasExternalBrainCausalAblationBatchStudy;
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $result = $svc->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('high', $result['confidence']);
    }
}
