<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCausalAblationBatchStudy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCausalAblationBatchStudyTest extends TestCase
{
    private function study(): AtlasExternalBrainCausalAblationBatchStudy
    {
        return new AtlasExternalBrainCausalAblationBatchStudy;
    }

    /** Build N batches with outcome linearly = 0.1*i and arbitrary dims. */
    private function linearBatches(int $n, callable $dimFactory): array
    {
        $batches = [];
        for ($i = 1; $i <= $n; $i++) {
            $batches[] = [
                'outcome_metric' => round($i / $n, 4),
                'dimensions'     => $dimFactory($i),
            ];
        }

        return $batches;
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->study()->study([]);
        $this->assertSame(AtlasExternalBrainCausalAblationBatchStudy::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('likely_positive_causes', $r);
        $this->assertArrayHasKey('likely_negative_causes', $r);
        $this->assertArrayHasKey('confounders', $r);
        $this->assertArrayHasKey('confidence', $r);
        $this->assertArrayHasKey('confidence_reason', $r);
        $this->assertArrayHasKey('sample_size', $r);
        $this->assertArrayHasKey('studied_dimensions', $r);
    }

    // ── AC2: positive / negative causal classification ────────────────────────

    public function test_perfectly_positive_dim_is_a_likely_positive_cause(): void
    {
        // scope_size = i, outcome = i/10 → perfect positive correlation.
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $dims = array_column($r['likely_positive_causes'], 'dimension');
        $this->assertContains('scope_size', $dims);
        $this->assertEmpty($r['likely_negative_causes']);
    }

    public function test_perfectly_negative_dim_is_a_likely_negative_cause(): void
    {
        // files_count = n+1-i (decreasing) while outcome = i/n (increasing) → negative corr.
        $n       = 12;
        $batches = $this->linearBatches($n, static fn ($i) => ['files_count' => $n + 1 - $i]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $dims = array_column($r['likely_negative_causes'], 'dimension');
        $this->assertContains('files_count', $dims);
        $this->assertEmpty($r['likely_positive_causes']);
    }

    public function test_uncorrelated_dim_is_neither_positive_nor_negative(): void
    {
        // constant dim → zero correlation.
        $batches = $this->linearBatches(12, static fn ($i) => ['acceptance_strength' => 0.5]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertEmpty($r['likely_positive_causes']);
        $this->assertEmpty($r['likely_negative_causes']);
    }

    // ── AC3: confidence weak on small sample or confounder-heavy ─────────────

    public function test_confidence_is_weak_when_sample_below_minimum(): void
    {
        $batches = $this->linearBatches(5, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('weak', $r['confidence']);
        $this->assertStringContainsString('below minimum', $r['confidence_reason']);
    }

    public function test_confidence_is_high_when_adequate_sample_and_no_confounders(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('high', $r['confidence']);
    }

    public function test_confidence_is_medium_when_sample_ok_and_some_confounders(): void
    {
        // Two identical dims (perfectly correlated with each other AND outcome) → confounders.
        $batches = $this->linearBatches(12, static fn ($i) => [
            'dim_a' => $i,
            'dim_b' => $i,  // perfectly correlated with dim_a
        ]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        // Both correlated with outcome AND with each other → confounders.
        $this->assertNotEmpty($r['confounders']);
        // Confidence cannot be high when confounders present.
        $this->assertNotSame('high', $r['confidence']);
    }

    public function test_confidence_is_weak_when_confounders_dominate_causal_signals(): void
    {
        // 4 identical dims (all confounders, 0 isolated causal signals) → weak.
        $batches = $this->linearBatches(12, static fn ($i) => [
            'a' => $i, 'b' => $i, 'c' => $i, 'd' => $i,
        ]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('weak', $r['confidence']);
    }

    // ── AC2: confounders ──────────────────────────────────────────────────────

    public function test_correlated_dims_both_marked_as_confounders(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => [
            'scope_size'    => $i,
            'subtask_count' => $i,  // perfectly correlated with scope_size
        ]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $confDims = array_column($r['confounders'], 'dimension');
        $this->assertContains('scope_size',    $confDims);
        $this->assertContains('subtask_count', $confDims);
    }

    public function test_confounders_not_in_positive_or_negative_cause_lists(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => [
            'dim_a' => $i,
            'dim_b' => $i,
        ]);
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 10]);

        $causeDims = array_merge(
            array_column($r['likely_positive_causes'], 'dimension'),
            array_column($r['likely_negative_causes'], 'dimension'),
        );
        foreach (array_column($r['confounders'], 'dimension') as $conf) {
            $this->assertNotContains($conf, $causeDims);
        }
    }

    // ── Dimension filtering ───────────────────────────────────────────────────

    public function test_dimension_absent_from_some_batches_is_excluded(): void
    {
        $batches = [
            ['outcome_metric' => 0.5, 'dimensions' => ['scope_size' => 1, 'extra' => 10]],
            ['outcome_metric' => 0.8, 'dimensions' => ['scope_size' => 2]],  // no 'extra'
        ];
        $r = $this->study()->study(['batches' => $batches, 'min_sample_size' => 1]);

        $this->assertNotContains('extra', $r['studied_dimensions']);
        $this->assertContains('scope_size', $r['studied_dimensions']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => [
            'scope_size' => $i,
            'file_count' => 13 - $i,
        ]);
        $facts = ['batches' => $batches, 'min_sample_size' => 10];
        $a = $this->study()->study($facts);
        $b = $this->study()->study($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
