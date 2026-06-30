<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCausalAblationBatchStudy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCausalAblationBatchStudyTest extends TestCase
{
    private AtlasExternalBrainCausalAblationBatchStudy $svc;

    protected function setUp(): void
    {
        $this->svc = new AtlasExternalBrainCausalAblationBatchStudy;
    }

    /**
     * Build N batches. outcome_dimensions scale linearly with i/n for good metrics
     * and (1-i/n) for bad metrics, so a dim proportional to i is a perfect positive cause.
     */
    private function linearBatches(int $n, callable $dimFactory): array
    {
        $batches = [];
        for ($i = 1; $i <= $n; $i++) {
            $f = round($i / $n, 4);
            $batches[] = [
                'outcome_dimensions' => [
                    'value_proof_rate'    => $f,
                    'commit_success_rate' => $f,
                    'compounding_impact'  => $f,
                    'give_back_rate'      => round(1.0 - $f, 4),
                    'poison_rate'         => round(1.0 - $f, 4),
                    'implementation_cost' => round(1.0 - $f, 4),
                ],
                'dimensions' => $dimFactory($i),
            ];
        }

        return $batches;
    }

    private function study(array $facts): array
    {
        return $this->svc->study($facts);
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->study([]);

        $this->assertSame(AtlasExternalBrainCausalAblationBatchStudy::SCHEMA, $r['schema_version']);
        foreach (['likely_positive_causes', 'likely_negative_causes', 'confounders', 'confidence', 'confidence_reason', 'sample_size', 'studied_dimensions'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    // ── AC2: positive cause ───────────────────────────────────────────────────

    public function test_dim_correlated_with_all_good_outcomes_is_positive_cause(): void
    {
        // scope_size = i → correlates +1 with good, −1 with bad → high composite score
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $dims = array_column($r['likely_positive_causes'], 'dimension');
        $this->assertContains('scope_size', $dims);
        $this->assertEmpty($r['likely_negative_causes']);
    }

    // ── AC2: negative cause ───────────────────────────────────────────────────

    public function test_dim_inversely_correlated_is_negative_cause(): void
    {
        $n       = 12;
        // files_count = n+1−i (decreasing) while good outcomes increase → negative composite
        $batches = $this->linearBatches($n, fn ($i) => ['files_count' => $n + 1 - $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $dims = array_column($r['likely_negative_causes'], 'dimension');
        $this->assertContains('files_count', $dims);
        $this->assertEmpty($r['likely_positive_causes']);
    }

    // ── AC2: uncorrelated dim is neutral ──────────────────────────────────────

    public function test_constant_dim_is_neutral(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => ['acceptance_strength' => 0.5]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertEmpty($r['likely_positive_causes']);
        $this->assertEmpty($r['likely_negative_causes']);
    }

    // ── AC2: confounded dimensions ────────────────────────────────────────────

    public function test_correlated_dims_both_marked_as_confounders(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => [
            'scope_size'    => $i,
            'subtask_count' => $i, // perfectly correlated with scope_size
        ]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $confDims = array_column($r['confounders'], 'dimension');
        $this->assertContains('scope_size',    $confDims);
        $this->assertContains('subtask_count', $confDims);
    }

    public function test_confounders_not_in_causal_lists(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => ['dim_a' => $i, 'dim_b' => $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $causeDims = array_merge(
            array_column($r['likely_positive_causes'], 'dimension'),
            array_column($r['likely_negative_causes'], 'dimension'),
        );
        foreach (array_column($r['confounders'], 'dimension') as $conf) {
            $this->assertNotContains($conf, $causeDims);
        }
    }

    // ── AC3: weak sample ─────────────────────────────────────────────────────

    public function test_confidence_is_weak_when_sample_below_minimum(): void
    {
        $batches = $this->linearBatches(5, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('weak', $r['confidence']);
        $this->assertStringContainsString('below minimum', $r['confidence_reason']);
    }

    // ── AC3: high confidence ──────────────────────────────────────────────────

    public function test_confidence_is_high_when_adequate_sample_no_confounders(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('high', $r['confidence']);
    }

    // ── AC3: medium confidence ────────────────────────────────────────────────

    public function test_confidence_is_medium_when_some_confounders(): void
    {
        // Two confounded dims + one isolated dim
        $batches = $this->linearBatches(12, static fn ($i) => [
            'dim_a'      => $i,
            'dim_b'      => $i,     // confounder pair
            'scope_size' => $i,     // also correlated — creates more confounders
        ]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertNotEmpty($r['confounders']);
        $this->assertNotSame('high', $r['confidence']);
    }

    // ── AC3: weak when confounders dominate ───────────────────────────────────

    public function test_confidence_is_weak_when_confounders_dominate(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => [
            'a' => $i, 'b' => $i, 'c' => $i, 'd' => $i,
        ]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertSame('weak', $r['confidence']);
    }

    // ── AC2: multi-outcome scoring — outcome_correlations in candidate ────────

    public function test_positive_cause_entry_contains_outcome_correlations(): void
    {
        $batches = $this->linearBatches(12, static fn ($i) => ['scope_size' => $i]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $entry = $r['likely_positive_causes'][0];
        $this->assertArrayHasKey('outcome_correlations', $entry);
        $this->assertArrayHasKey('value_proof_rate',    $entry['outcome_correlations']);
        $this->assertArrayHasKey('give_back_rate',      $entry['outcome_correlations']);
        $this->assertArrayHasKey('composite_score',     $entry);
    }

    // ── Deterministic ordering ────────────────────────────────────────────────

    public function test_positive_causes_have_composite_score_descending_order(): void
    {
        // Use one clean dim + one anti-correlated dim (negative cause) to avoid confounders.
        $batches = $this->linearBatches(12, static fn ($i) => [
            'pos_dim' => $i,
            'neg_dim' => 13 - $i, // anti-correlated with pos_dim → no confounder pair
        ]);
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 10]);

        $this->assertNotEmpty($r['likely_positive_causes'], 'Expected at least one positive cause');
        // If multiple positive causes, verify ordering.
        $positives = $r['likely_positive_causes'];
        for ($i = 1; $i < count($positives); $i++) {
            $this->assertGreaterThanOrEqual(
                $positives[$i]['composite_score'],
                $positives[$i - 1]['composite_score'],
            );
        }
    }

    // ── Dimension absent from some batches is excluded ────────────────────────

    public function test_dimension_absent_from_some_batches_is_excluded(): void
    {
        $batches = [
            [
                'outcome_dimensions' => ['value_proof_rate' => 0.5, 'commit_success_rate' => 0.5, 'compounding_impact' => 0.5, 'give_back_rate' => 0.5, 'poison_rate' => 0.5, 'implementation_cost' => 0.5],
                'dimensions'         => ['scope_size' => 1, 'extra' => 10],
            ],
            [
                'outcome_dimensions' => ['value_proof_rate' => 0.8, 'commit_success_rate' => 0.8, 'compounding_impact' => 0.8, 'give_back_rate' => 0.2, 'poison_rate' => 0.2, 'implementation_cost' => 0.2],
                'dimensions'         => ['scope_size' => 2],
            ],
        ];
        $r = $this->study(['batches' => $batches, 'min_sample_size' => 1]);

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

        $this->assertSame(json_encode($this->study($facts)), json_encode($this->study($facts)));
    }

    // ── compare(): control vs treatment ──────────────────────────────────────

    private function makeSnapshot(float $greenRate, float $giveback = 0.15, float $proxy = 0.05, float $capDelta = 0.05, int $n = 20, float $cost = 100.0): array
    {
        return [
            'green_rate'       => $greenRate,
            'give_back_rate'   => $giveback,
            'proxy_rate'       => $proxy,
            'capability_delta' => $capDelta,
            'sample_count'     => $n,
            'cost_per_green'   => $cost,
        ];
    }

    public function test_compare_output_has_required_fields(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.60), $this->makeSnapshot(0.70));

        foreach (['schema_version', 'causal_lift', 'confidence', 'decision', 'decision_reason', 'metrics'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(AtlasExternalBrainCausalAblationBatchStudy::SCHEMA, $r['schema_version']);
    }

    public function test_compare_metrics_contain_per_field_deltas(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.60), $this->makeSnapshot(0.70));

        foreach (['green_rate', 'give_back_rate', 'proxy_rate', 'capability_delta', 'cost_per_green'] as $f) {
            $this->assertArrayHasKey($f, $r['metrics']);
            $this->assertArrayHasKey('delta', $r['metrics'][$f]);
        }
        $this->assertArrayHasKey('sample_count', $r['metrics']);
    }

    public function test_positive_lift_yields_keep_policy(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.60), $this->makeSnapshot(0.72));

        $this->assertSame('keep_policy', $r['decision']);
        $this->assertGreaterThan(0.0, $r['causal_lift']);
    }

    public function test_negative_lift_yields_rollback_policy(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.70), $this->makeSnapshot(0.58));

        $this->assertSame('rollback_policy', $r['decision']);
        $this->assertLessThan(0.0, $r['causal_lift']);
    }

    public function test_inconclusive_small_sample_yields_collect_more_evidence(): void
    {
        // Good lift but sample below threshold → collect
        $r = $this->svc->compare($this->makeSnapshot(0.60, n: 5), $this->makeSnapshot(0.72, n: 5));

        $this->assertSame('collect_more_evidence', $r['decision']);
        $this->assertSame('weak', $r['confidence']);
        $this->assertStringContainsString('below', $r['decision_reason']);
    }

    public function test_proxy_regression_yields_rollback_policy(): void
    {
        // proxy_rate jumps from 0.05 to 0.20 — delta 0.15 > limit 0.10
        $r = $this->svc->compare(
            $this->makeSnapshot(0.60, proxy: 0.05),
            $this->makeSnapshot(0.72, proxy: 0.20),
        );

        $this->assertSame('rollback_policy', $r['decision']);
        $this->assertStringContainsString('proxy_rate', $r['decision_reason']);
    }

    public function test_cost_regression_yields_rollback_policy(): void
    {
        // cost rises 50% (1.5x) — exceeds 1.20x limit
        $r = $this->svc->compare(
            $this->makeSnapshot(0.60, cost: 100.0),
            $this->makeSnapshot(0.72, cost: 150.0),
        );

        $this->assertSame('rollback_policy', $r['decision']);
        $this->assertStringContainsString('cost_per_green', $r['decision_reason']);
    }

    public function test_lift_within_threshold_yields_collect_more_evidence(): void
    {
        // lift = 0.02 < 0.05 threshold
        $r = $this->svc->compare($this->makeSnapshot(0.60), $this->makeSnapshot(0.62));

        $this->assertSame('collect_more_evidence', $r['decision']);
        $this->assertStringContainsString('inconclusive', $r['decision_reason']);
    }

    public function test_high_confidence_when_large_sample(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.60, n: 30), $this->makeSnapshot(0.72, n: 30));

        $this->assertSame('high', $r['confidence']);
    }

    public function test_medium_confidence_between_thresholds(): void
    {
        // sample_count = 15 — between 10 and 20
        $r = $this->svc->compare($this->makeSnapshot(0.60, n: 15), $this->makeSnapshot(0.72, n: 15));

        $this->assertSame('medium', $r['confidence']);
    }

    public function test_causal_lift_is_green_rate_delta(): void
    {
        $r = $this->svc->compare($this->makeSnapshot(0.60), $this->makeSnapshot(0.75));

        $this->assertEqualsWithDelta(0.15, $r['causal_lift'], 0.0001);
    }
}
