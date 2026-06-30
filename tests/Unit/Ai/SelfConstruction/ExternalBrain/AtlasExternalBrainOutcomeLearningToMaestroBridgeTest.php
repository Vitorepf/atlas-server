<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeLearningToMaestroBridge;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeLearningToMaestroBridgeTest extends TestCase
{
    private function svc(): AtlasExternalBrainOutcomeLearningToMaestroBridge
    {
        return new AtlasExternalBrainOutcomeLearningToMaestroBridge;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'task_family'     => 'test_family',
            'worker_tier'     => 'scaffolded_small_model',
            'success_rate'    => 0.50,
            'give_back_rate'  => 0.10,
            'quarantine_rate' => 0.05,
            'sample_count'    => 5,
            'has_value_proof' => false,
        ], $overrides);
    }

    private function bridge(array $rows, array $options = []): array
    {
        return $this->svc()->bridge($rows, $options);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->bridge([]);
        $this->assertSame(AtlasExternalBrainOutcomeLearningToMaestroBridge::SCHEMA, $r['schema']);
    }

    public function test_output_keys_always_present(): void
    {
        $r = $this->bridge([]);
        $this->assertArrayHasKey('worker_affinity_updates', $r);
        $this->assertArrayHasKey('task_family_supply_adjustments', $r);
        $this->assertArrayHasKey('poison_family_blocks', $r);
        $this->assertArrayHasKey('weak_green_quality_reviews', $r);
        $this->assertArrayHasKey('replenisher_feedback', $r);
    }

    // ── insufficient sample skip ──────────────────────────────────────────────

    public function test_row_with_insufficient_samples_is_skipped(): void
    {
        $r = $this->bridge([$this->row(['sample_count' => 2])]);

        $this->assertSame([], $r['replenisher_feedback']);
        $this->assertSame([], $r['poison_family_blocks']);
    }

    public function test_row_with_exact_min_samples_is_processed(): void
    {
        $r = $this->bridge([$this->row(['sample_count' => 3, 'give_back_rate' => 0.80])]);

        $this->assertNotEmpty($r['poison_family_blocks']);
    }

    public function test_row_with_empty_family_is_skipped(): void
    {
        $r = $this->bridge([$this->row(['task_family' => ''])]);

        $this->assertSame([], $r['replenisher_feedback']);
    }

    // ── poison block ──────────────────────────────────────────────────────────

    public function test_high_give_back_triggers_poison_block(): void
    {
        $r = $this->bridge([$this->row(['give_back_rate' => 0.75])]);

        $this->assertCount(1, $r['poison_family_blocks']);
        $this->assertSame('test_family', $r['poison_family_blocks'][0]['task_family']);
        $this->assertStringContainsString('give_back_rate', $r['poison_family_blocks'][0]['reason']);
    }

    public function test_high_quarantine_triggers_poison_block(): void
    {
        $r = $this->bridge([$this->row(['quarantine_rate' => 0.55])]);

        $this->assertCount(1, $r['poison_family_blocks']);
        $this->assertStringContainsString('quarantine_rate', $r['poison_family_blocks'][0]['reason']);
    }

    public function test_poison_block_emits_block_supply_adjustment(): void
    {
        $r = $this->bridge([$this->row(['give_back_rate' => 0.75])]);

        $adj = $r['task_family_supply_adjustments'][0];
        $this->assertSame('block', $adj['adjustment']);
        $this->assertSame(1.0, $adj['magnitude']);
    }

    public function test_poison_block_emits_reduce_supply_replenisher_feedback(): void
    {
        $r = $this->bridge([$this->row(['give_back_rate' => 0.75])]);

        $this->assertSame('reduce_supply', $r['replenisher_feedback'][0]['action']);
    }

    public function test_poison_block_downgrades_worker_affinity(): void
    {
        $r = $this->bridge([$this->row([
            'give_back_rate' => 0.75,
            'worker_tier'    => 'scaffolded_small_model',
        ])]);

        $this->assertSame('small_model', $r['worker_affinity_updates'][0]['recommended_tier']);
        $this->assertStringContainsString('poison', $r['worker_affinity_updates'][0]['reason']);
    }

    // ── supply decrease (non-poison give_back) ────────────────────────────────

    public function test_non_poison_give_back_emits_supply_decrease(): void
    {
        $r = $this->bridge([$this->row(['give_back_rate' => 0.50])]);

        $adj = $r['task_family_supply_adjustments'][0];
        $this->assertSame('decrease', $adj['adjustment']);
        $this->assertGreaterThan(0.0, $adj['magnitude']);
    }

    public function test_non_poison_give_back_emits_reduce_supply_feedback(): void
    {
        $r = $this->bridge([$this->row(['give_back_rate' => 0.50])]);

        $this->assertSame('reduce_supply', $r['replenisher_feedback'][0]['action']);
    }

    // ── supply increase (success + value_proof) ───────────────────────────────

    public function test_success_with_value_proof_emits_supply_increase(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.90,
            'has_value_proof' => true,
        ])]);

        $adj = $r['task_family_supply_adjustments'][0];
        $this->assertSame('increase', $adj['adjustment']);
        $this->assertSame(0.9, $adj['magnitude']);
    }

    public function test_success_with_value_proof_emits_boost_supply_feedback(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'has_value_proof' => true,
        ])]);

        $this->assertSame('boost_supply', $r['replenisher_feedback'][0]['action']);
    }

    public function test_success_with_value_proof_confirms_worker_tier(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'worker_tier'     => 'frontier_model',
            'has_value_proof' => true,
        ])]);

        $affinity = $r['worker_affinity_updates'][0];
        $this->assertSame('frontier_model', $affinity['recommended_tier']);
        $this->assertStringContainsString('value_proof', $affinity['reason']);
    }

    // ── weak-green quality review ─────────────────────────────────────────────

    public function test_success_without_value_proof_emits_weak_green_review(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'has_value_proof' => false,
        ])]);

        $this->assertCount(1, $r['weak_green_quality_reviews']);
        $review = $r['weak_green_quality_reviews'][0];
        $this->assertSame('test_family', $review['task_family']);
        $this->assertSame('tighten_task_fabric', $review['action']);
    }

    public function test_weak_green_does_not_produce_supply_boost(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'has_value_proof' => false,
        ])]);

        $types = array_column($r['task_family_supply_adjustments'], 'adjustment');
        $this->assertNotContains('increase', $types);
    }

    public function test_weak_green_replenisher_action_is_hold(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'has_value_proof' => false,
        ])]);

        $this->assertSame('hold', $r['replenisher_feedback'][0]['action']);
    }

    public function test_weak_green_review_includes_worker_tier(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'    => 0.85,
            'has_value_proof' => false,
            'worker_tier'     => 'small_model',
        ])]);

        $this->assertSame('small_model', $r['weak_green_quality_reviews'][0]['worker_tier']);
    }

    // ── model-tier routing: all three tiers ───────────────────────────────────

    public function test_small_model_poison_stays_at_small_model(): void
    {
        $r = $this->bridge([$this->row([
            'worker_tier'    => 'small_model',
            'give_back_rate' => 0.80,
        ])]);

        $this->assertSame('small_model', $r['worker_affinity_updates'][0]['recommended_tier']);
    }

    public function test_scaffolded_small_model_poison_downgrades_to_small_model(): void
    {
        $r = $this->bridge([$this->row([
            'worker_tier'    => 'scaffolded_small_model',
            'give_back_rate' => 0.80,
        ])]);

        $this->assertSame('small_model', $r['worker_affinity_updates'][0]['recommended_tier']);
    }

    public function test_frontier_model_poison_downgrades_to_scaffolded(): void
    {
        $r = $this->bridge([$this->row([
            'worker_tier'    => 'frontier_model',
            'give_back_rate' => 0.80,
        ])]);

        $this->assertSame('scaffolded_small_model', $r['worker_affinity_updates'][0]['recommended_tier']);
    }

    public function test_frontier_model_success_confirms_frontier(): void
    {
        $r = $this->bridge([$this->row([
            'worker_tier'     => 'frontier_model',
            'success_rate'    => 0.90,
            'has_value_proof' => true,
        ])]);

        $this->assertSame('frontier_model', $r['worker_affinity_updates'][0]['recommended_tier']);
    }

    // ── no dominant signal → hold ─────────────────────────────────────────────

    public function test_mid_range_row_emits_hold_feedback(): void
    {
        $r = $this->bridge([$this->row([
            'success_rate'   => 0.50,
            'give_back_rate' => 0.10,
        ])]);

        $this->assertSame('hold', $r['replenisher_feedback'][0]['action']);
        $this->assertSame([], $r['weak_green_quality_reviews']);
        $this->assertSame([], $r['poison_family_blocks']);
    }
}
