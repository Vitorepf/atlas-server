<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldVariantBandit;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldVariantBanditTest extends TestCase
{
    private function svc(): AtlasExternalBrainScaffoldVariantBandit
    {
        return new AtlasExternalBrainScaffoldVariantBandit;
    }

    private function variant(
        string $id,
        int    $runs,
        int    $successes,
        int    $giveBacks,
        float  $avgValue,
        float  $heldout    = 0.5,
        float  $proxyLeak  = 0.0,
        float  $avgCost    = 5.0,
        float  $greenRate  = 0.5,
    ): array {
        return [
            'variant_id'        => $id,
            'total_runs'        => $runs,
            'successes'         => $successes,
            'give_backs'        => $giveBacks,
            'avg_value'         => $avgValue,
            'heldout_pass_rate' => $heldout,
            'proxy_leak_rate'   => $proxyLeak,
            'avg_cost'          => $avgCost,
            'green_commit_rate' => $greenRate,
        ];
    }

    private function select(array $variants, string $tier = 'small', string $class = 'refactor'): array
    {
        return $this->svc()->select([
            'model_tier'       => $tier,
            'task_class'       => $class,
            'variant_outcomes' => $variants,
        ]);
    }

    // ── selection ─────────────────────────────────────────────────────────────

    public function test_highest_ucb_variant_is_selected(): void
    {
        $r = $this->select([
            $this->variant('v1', 10, 9, 0, 9.0),
            $this->variant('v2', 10, 3, 5, 4.0),
        ]);

        $this->assertSame('v1', $r['selected_variant']);
    }

    public function test_unsampled_variant_wins_over_weak_sampled(): void
    {
        $r = $this->select([
            $this->variant('tested', 10, 2, 6, 2.0),
            $this->variant('fresh', 0, 0, 0, 0.0),
        ]);

        $this->assertSame('fresh', $r['selected_variant']);
    }

    // ── exploration variants ──────────────────────────────────────────────────

    public function test_under_sampled_variants_appear_in_exploration(): void
    {
        $r = $this->select([
            $this->variant('well_tested', 10, 8, 1, 8.0),
            $this->variant('barely_tested', 2, 1, 1, 3.0),
        ]);

        $this->assertSame('well_tested', $r['selected_variant']);
        $this->assertContains('barely_tested', $r['exploration_variants']);
    }

    public function test_selected_variant_not_duplicated_in_exploration(): void
    {
        $r = $this->select([$this->variant('only_one', 1, 1, 0, 9.0)]);

        $this->assertNotContains('only_one', $r['exploration_variants']);
    }

    // ── confidence ────────────────────────────────────────────────────────────

    public function test_high_confidence_when_runs_ge_min_evidence_times_two(): void
    {
        $r = $this->select([$this->variant('v1', 10, 8, 0, 8.0)]);

        $this->assertSame('high', $r['confidence']);
    }

    public function test_medium_confidence_when_runs_ge_min_evidence(): void
    {
        $r = $this->select([$this->variant('v1', 5, 4, 0, 8.0)]);

        $this->assertSame('medium', $r['confidence']);
    }

    public function test_low_confidence_when_runs_below_min_evidence(): void
    {
        $r = $this->select([$this->variant('v1', 2, 2, 0, 9.0)]);

        $this->assertSame('low', $r['confidence']);
    }

    // ── evidence counts ───────────────────────────────────────────────────────

    public function test_evidence_counts_includes_all_variants(): void
    {
        $r = $this->select([
            $this->variant('v1', 10, 8, 1, 8.0),
            $this->variant('v2', 3, 2, 0, 7.0),
        ]);

        $this->assertArrayHasKey('v1', $r['evidence_counts']);
        $this->assertArrayHasKey('v2', $r['evidence_counts']);
        $this->assertSame(10, $r['evidence_counts']['v1']);
        $this->assertSame(3, $r['evidence_counts']['v2']);
    }

    // ── rejected variants ─────────────────────────────────────────────────────

    public function test_poor_performer_with_sufficient_evidence_is_rejected(): void
    {
        $r = $this->select([
            $this->variant('good', 10, 9, 0, 9.0, 0.9, 0.0, 2.0, 0.9),
            $this->variant('bad',  10, 1, 7, 2.0, 0.1, 0.0, 9.0, 0.1),
        ]);

        $this->assertContains('bad', $r['rejected_variants']);
        $this->assertNotContains('good', $r['rejected_variants']);
    }

    public function test_under_sampled_variant_not_rejected_regardless_of_score(): void
    {
        $r = $this->select([$this->variant('new', 2, 0, 1, 1.0, 0.0, 0.0, 9.0, 0.0)]);

        $this->assertNotContains('new', $r['rejected_variants']);
    }

    // ── quarantined variants — AC2 ────────────────────────────────────────────

    public function test_high_proxy_leak_with_sufficient_evidence_quarantines_variant(): void
    {
        $r = $this->select([
            $this->variant('clean', 10, 9, 0, 9.0, 0.9, 0.0),
            $this->variant('leaky', 10, 9, 0, 9.0, 0.9, 0.50), // proxy_leak=0.50 > ceiling 0.25
        ]);

        $this->assertContains('leaky', $r['quarantined_variants']);
        $this->assertNotContains('leaky', $r['rejected_variants']);
    }

    public function test_quarantined_variant_is_not_selectable(): void
    {
        // leaky would win on UCB if not quarantined (high success rate) but must not be selected
        $r = $this->select([
            $this->variant('leaky',  10, 10, 0, 10.0, 1.0, 0.80), // high success, high proxy leak
            $this->variant('clean',  10,  6, 1,  7.0, 0.7, 0.00),
        ]);

        $this->assertSame('clean', $r['selected_variant']);
        $this->assertContains('leaky', $r['quarantined_variants']);
    }

    public function test_under_sampled_variant_not_quarantined_even_with_high_proxy_leak(): void
    {
        // Not enough evidence to quarantine
        $r = $this->select([
            $this->variant('v1', 10, 8, 1, 8.0, 0.9, 0.0),
            $this->variant('new',  2,  2, 0, 9.0, 0.9, 0.80),
        ]);

        $this->assertNotContains('new', $r['quarantined_variants']);
    }

    public function test_proxy_leak_at_or_below_ceiling_does_not_quarantine(): void
    {
        $r = $this->select([
            $this->variant('borderline', 10, 8, 0, 8.0, 0.8, 0.25), // exactly at ceiling
        ]);

        $this->assertNotContains('borderline', $r['quarantined_variants']);
        $this->assertSame('borderline', $r['selected_variant']);
    }

    // ── AC1: held-out quality affects selection ───────────────────────────────

    public function test_high_heldout_variant_beats_high_success_low_heldout(): void
    {
        // v1: success=0.9 but heldout=0.2 (high apparent, low real quality)
        // v2: success=0.7 but heldout=0.9 (lower apparent, genuine quality)
        $v1 = $this->variant('v1', 10, 9, 0, 8.0, 0.2, 0.0, 5.0, 0.5);
        $v2 = $this->variant('v2', 10, 7, 0, 8.0, 0.9, 0.0, 5.0, 0.5);

        // v1 weighted: 0.9*0.40 + 0.2*0.20 + 0.8*0.15 + 0.5*0.10 + 1.0*0.10 + 0.5*0.05 = 0.36+0.04+0.12+0.05+0.10+0.025 = 0.695
        // v2 weighted: 0.7*0.40 + 0.9*0.20 + 0.8*0.15 + 0.5*0.10 + 1.0*0.10 + 0.5*0.05 = 0.28+0.18+0.12+0.05+0.10+0.025 = 0.755
        $r = $this->select([$v1, $v2]);

        $this->assertSame('v2', $r['selected_variant']);
    }

    // ── AC1: cost-aware selection ─────────────────────────────────────────────

    public function test_lower_cost_variant_preferred_when_otherwise_equal(): void
    {
        // v1: avg_cost=1.0 (cheap), v2: avg_cost=9.0 (expensive), all else equal
        $v1 = $this->variant('cheap',     10, 8, 0, 8.0, 0.8, 0.0, 1.0, 0.8);
        $v2 = $this->variant('expensive', 10, 8, 0, 8.0, 0.8, 0.0, 9.0, 0.8);

        $r = $this->select([$v1, $v2]);

        $this->assertSame('cheap', $r['selected_variant']);
    }

    // ── AC1: green_commit_rate in scoring ────────────────────────────────────

    public function test_high_green_commit_rate_improves_score(): void
    {
        // v1: green=0.9, v2: green=0.1, otherwise same
        $v1 = $this->variant('green_good', 10, 7, 1, 7.0, 0.7, 0.0, 5.0, 0.9);
        $v2 = $this->variant('green_bad',  10, 7, 1, 7.0, 0.7, 0.0, 5.0, 0.1);

        $r = $this->select([$v1, $v2]);

        $this->assertSame('green_good', $r['selected_variant']);
    }

    // ── model_tier + task_class passthrough ───────────────────────────────────

    public function test_model_tier_and_task_class_reflected_in_output(): void
    {
        $r = $this->svc()->select([
            'model_tier'       => 'frontier',
            'task_class'       => 'feature',
            'variant_outcomes' => [$this->variant('v1', 10, 8, 1, 8.0)],
        ]);

        $this->assertSame('frontier', $r['model_tier']);
        $this->assertSame('feature', $r['task_class']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_variants_returns_null_selected(): void
    {
        $r = $this->svc()->select(['variant_outcomes' => []]);

        $this->assertNull($r['selected_variant']);
        $this->assertSame([], $r['exploration_variants']);
        $this->assertSame([], $r['evidence_counts']);
        $this->assertSame([], $r['rejected_variants']);
        $this->assertSame([], $r['quarantined_variants']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->select([]);

        $this->assertSame(AtlasExternalBrainScaffoldVariantBandit::SCHEMA, $r['schema_version']);
    }

    public function test_output_always_includes_quarantined_variants_key(): void
    {
        $r = $this->select([$this->variant('v1', 10, 8, 0, 8.0)]);

        $this->assertArrayHasKey('quarantined_variants', $r);
    }
}
