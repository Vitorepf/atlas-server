<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldVariantBandit;
use Tests\TestCase;

final class AtlasExternalBrainScaffoldVariantBanditTest extends TestCase
{
    private function svc(): AtlasExternalBrainScaffoldVariantBandit
    {
        return new AtlasExternalBrainScaffoldVariantBandit;
    }

    private function variant(string $id, int $runs, int $successes, int $giveBacks, float $avgValue): array
    {
        return [
            'variant_id' => $id,
            'total_runs' => $runs,
            'successes' => $successes,
            'give_backs' => $giveBacks,
            'avg_value' => $avgValue,
        ];
    }

    private function select(array $variants, string $tier = 'small', string $class = 'refactor'): array
    {
        return $this->svc()->select([
            'model_tier' => $tier,
            'task_class' => $class,
            'variant_outcomes' => $variants,
        ]);
    }

    // ── selection ─────────────────────────────────────────────────────────────

    public function test_highest_ucb_variant_is_selected(): void
    {
        $r = $this->select([
            $this->variant('v1', 10, 9, 0, 9.0),   // 9/10 successes, high value → strong winner
            $this->variant('v2', 10, 3, 5, 4.0),   // poor performer
        ]);

        $this->assertSame('v1', $r['selected_variant']);
    }

    public function test_unsampled_variant_wins_over_weak_sampled(): void
    {
        // Unsampled gets bonus = EXPLORATION_FACTOR*2 = 0.6, weak sampled gets ~0.15+bonus
        $r = $this->select([
            $this->variant('tested', 10, 2, 6, 2.0),  // poor: low success + give_backs + low value
            $this->variant('fresh', 0, 0, 0, 0.0),    // unsampled: gets max exploration
        ]);

        $this->assertSame('fresh', $r['selected_variant']);
    }

    // ── exploration variants ──────────────────────────────────────────────────

    public function test_under_sampled_variants_appear_in_exploration(): void
    {
        $r = $this->select([
            $this->variant('well_tested', 10, 8, 1, 8.0),
            // poor performance but only 2 runs → under-sampled; UCB still below well_tested
            $this->variant('barely_tested', 2, 1, 1, 3.0),
        ]);

        $this->assertSame('well_tested', $r['selected_variant']);
        $this->assertContains('barely_tested', $r['exploration_variants']);
    }

    public function test_selected_variant_not_duplicated_in_exploration(): void
    {
        // selected may itself be under-sampled — it must not appear in exploration list
        $r = $this->select([
            $this->variant('only_one', 1, 1, 0, 9.0),
        ]);

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
            $this->variant('good', 10, 9, 0, 9.0),
            $this->variant('bad', 10, 1, 7, 2.0),   // weighted ~ 0.1*0.6 + 0.2*0.25 + 0.3*0.15 = 0.155 < 0.30
        ]);

        $this->assertContains('bad', $r['rejected_variants']);
        $this->assertNotContains('good', $r['rejected_variants']);
    }

    public function test_under_sampled_variant_not_rejected_regardless_of_score(): void
    {
        // Under-sampled (runs < MIN_EVIDENCE) cannot be rejected — not enough evidence
        $r = $this->select([
            $this->variant('new', 2, 0, 1, 1.0),    // poor score but only 2 runs
        ]);

        $this->assertNotContains('new', $r['rejected_variants']);
    }

    // ── model_tier + task_class passthrough ───────────────────────────────────

    public function test_model_tier_and_task_class_reflected_in_output(): void
    {
        $r = $this->svc()->select([
            'model_tier' => 'frontier',
            'task_class' => 'feature',
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
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->select([]);

        $this->assertSame(AtlasExternalBrainScaffoldVariantBandit::SCHEMA, $r['schema_version']);
    }
}
