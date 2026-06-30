<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeLearningToMaestroBridge;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeLearningToMaestroBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainOutcomeLearningToMaestroBridge
    {
        return new AtlasExternalBrainOutcomeLearningToMaestroBridge();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'task_family'     => 'external_brain',
            'worker_tier'     => 'scaffolded_small_model',
            'success_rate'    => 0.50,
            'give_back_rate'  => 0.10,
            'quarantine_rate' => 0.05,
            'sample_count'    => 10,
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->bridge()->bridge([]);
        $this->assertSame(AtlasExternalBrainOutcomeLearningToMaestroBridge::SCHEMA, $result['schema']);
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_rows_yields_empty_outputs(): void
    {
        $result = $this->bridge()->bridge([]);

        $this->assertSame([], $result['worker_affinity_updates']);
        $this->assertSame([], $result['task_family_supply_adjustments']);
        $this->assertSame([], $result['poison_family_blocks']);
        $this->assertSame([], $result['replenisher_feedback']);
    }

    // ── min_samples filter ────────────────────────────────────────────────────

    public function test_rows_below_min_samples_are_skipped(): void
    {
        $result = $this->bridge()->bridge([$this->row(['sample_count' => 2])]);

        $this->assertSame([], $result['poison_family_blocks']);
        $this->assertSame([], $result['replenisher_feedback']);
    }

    // ── poison block ──────────────────────────────────────────────────────────

    public function test_poison_block_on_high_give_back_rate(): void
    {
        $result = $this->bridge()->bridge([$this->row(['give_back_rate' => 0.75])]);

        $this->assertCount(1, $result['poison_family_blocks']);
        $this->assertSame('external_brain', $result['poison_family_blocks'][0]['task_family']);
    }

    public function test_poison_block_on_high_quarantine_rate(): void
    {
        $result = $this->bridge()->bridge([$this->row(['quarantine_rate' => 0.55])]);

        $this->assertCount(1, $result['poison_family_blocks']);
    }

    public function test_poison_block_emits_supply_adjustment_block(): void
    {
        $result = $this->bridge()->bridge([$this->row(['give_back_rate' => 0.80])]);

        $adjustments = $result['task_family_supply_adjustments'];
        $this->assertCount(1, $adjustments);
        $this->assertSame('block', $adjustments[0]['adjustment']);
        $this->assertSame(1.0, $adjustments[0]['magnitude']);
    }

    public function test_poison_block_emits_reduce_supply_replenisher_feedback(): void
    {
        $result = $this->bridge()->bridge([$this->row(['give_back_rate' => 0.80])]);

        $feedback = $result['replenisher_feedback'];
        $this->assertCount(1, $feedback);
        $this->assertSame('reduce_supply', $feedback[0]['action']);
    }

    public function test_poison_block_recommends_cheaper_tier(): void
    {
        $result = $this->bridge()->bridge([$this->row([
            'worker_tier'    => 'frontier_model',
            'give_back_rate' => 0.80,
        ])]);

        $affinity = $result['worker_affinity_updates'];
        $this->assertCount(1, $affinity);
        $this->assertSame('scaffolded_small_model', $affinity[0]['recommended_tier']);
    }

    // ── supply decrease ───────────────────────────────────────────────────────

    public function test_supply_decrease_on_elevated_give_back(): void
    {
        $result = $this->bridge()->bridge([$this->row(['give_back_rate' => 0.45])]);

        $adjustments = $result['task_family_supply_adjustments'];
        $this->assertCount(1, $adjustments);
        $this->assertSame('decrease', $adjustments[0]['adjustment']);
    }

    public function test_supply_decrease_replenisher_action_is_reduce_supply(): void
    {
        $result = $this->bridge()->bridge([$this->row(['give_back_rate' => 0.45])]);

        $this->assertSame('reduce_supply', $result['replenisher_feedback'][0]['action']);
    }

    // ── supply increase ───────────────────────────────────────────────────────

    public function test_supply_increase_on_high_success_rate(): void
    {
        $result = $this->bridge()->bridge([$this->row(['success_rate' => 0.90])]);

        $adjustments = $result['task_family_supply_adjustments'];
        $this->assertCount(1, $adjustments);
        $this->assertSame('increase', $adjustments[0]['adjustment']);
    }

    public function test_supply_increase_replenisher_action_is_boost_supply(): void
    {
        $result = $this->bridge()->bridge([$this->row(['success_rate' => 0.85])]);

        $this->assertSame('boost_supply', $result['replenisher_feedback'][0]['action']);
    }

    // ── worker affinity ───────────────────────────────────────────────────────

    public function test_affinity_recommends_current_tier_on_high_success(): void
    {
        $result = $this->bridge()->bridge([$this->row([
            'worker_tier'  => 'scaffolded_small_model',
            'success_rate' => 0.90,
        ])]);

        $affinity = $result['worker_affinity_updates'];
        $this->assertCount(1, $affinity);
        $this->assertSame('scaffolded_small_model', $affinity[0]['recommended_tier']);
    }

    public function test_affinity_recommends_cheaper_tier_on_elevated_give_back(): void
    {
        $result = $this->bridge()->bridge([$this->row([
            'worker_tier'   => 'scaffolded_small_model',
            'give_back_rate' => 0.45,
        ])]);

        $affinity = $result['worker_affinity_updates'];
        $this->assertCount(1, $affinity);
        $this->assertSame('small_model', $affinity[0]['recommended_tier']);
    }

    // ── hold signal ───────────────────────────────────────────────────────────

    public function test_hold_replenisher_when_no_dominant_signal(): void
    {
        $result = $this->bridge()->bridge([$this->row([
            'success_rate'  => 0.50,
            'give_back_rate' => 0.10,
        ])]);

        $this->assertSame('hold', $result['replenisher_feedback'][0]['action']);
    }

    // ── multi-row ─────────────────────────────────────────────────────────────

    public function test_multiple_rows_produce_independent_entries(): void
    {
        $result = $this->bridge()->bridge([
            $this->row(['task_family' => 'family_a', 'success_rate' => 0.90]),
            $this->row(['task_family' => 'family_b', 'give_back_rate' => 0.75]),
        ]);

        $this->assertCount(1, $result['poison_family_blocks']);
        $this->assertSame('family_b', $result['poison_family_blocks'][0]['task_family']);
        $this->assertCount(2, $result['replenisher_feedback']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $rows = [$this->row(), $this->row(['task_family' => 'other', 'give_back_rate' => 0.80])];
        $this->assertSame(
            $this->bridge()->bridge($rows),
            $this->bridge()->bridge($rows),
        );
    }
}
