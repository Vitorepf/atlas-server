<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeLearningToMaestroBridge;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeLearningToMaestroBridgeTest extends TestCase
{
    private function svc(): AtlasExternalBrainOutcomeLearningToMaestroBridge
    {
        return new AtlasExternalBrainOutcomeLearningToMaestroBridge;
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'task_family'     => 'test_family',
            'worker_tier'     => 'scaffolded_small_model',
            'success_rate'    => 0.90,
            'give_back_rate'  => 0.05,
            'quarantine_rate' => 0.0,
            'sample_count'    => 10,
            'has_value_proof' => true,
            'learning_age_days' => 5,
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit — all tests exit 0) ─────────────────────

    public function test_ac1_empty_rows_returns_all_keys(): void
    {
        $r = $this->svc()->bridge([]);
        $this->assertArrayHasKey('worker_affinity_updates',        $r);
        $this->assertArrayHasKey('task_family_supply_adjustments', $r);
        $this->assertArrayHasKey('poison_family_blocks',           $r);
        $this->assertArrayHasKey('weak_green_quality_reviews',     $r);
        $this->assertArrayHasKey('replenisher_feedback',           $r);
        $this->assertArrayHasKey('dispatch_hints',                 $r);
    }

    // ── AC2: high success_rate but no value proof → weak_green, no boost ─────

    public function test_ac2_weak_green_creates_quality_review(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['has_value_proof' => false, 'success_rate' => 0.92]),
        ]);

        $this->assertCount(1, $r['weak_green_quality_reviews'],
            'weak-green row must produce one quality review');

        $review = $r['weak_green_quality_reviews'][0];
        $this->assertSame('test_family',        $review['task_family']);
        $this->assertSame('tighten_task_fabric', $review['action']);
    }

    public function test_ac2_weak_green_produces_no_boost_supply_adjustment(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['has_value_proof' => false, 'success_rate' => 0.95]),
        ]);

        foreach ($r['task_family_supply_adjustments'] as $adj) {
            if ($adj['task_family'] === 'test_family') {
                $this->assertNotSame('increase', $adj['adjustment'],
                    'weak-green must not produce a supply increase');
            }
        }

        foreach ($r['replenisher_feedback'] as $fb) {
            if ($fb['task_family'] === 'test_family') {
                $this->assertNotSame('boost_supply', $fb['action'],
                    'weak-green must not produce boost_supply replenisher feedback');
            }
        }
    }

    public function test_ac2_weak_green_dispatch_hint_does_not_confirm_tier(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['has_value_proof' => false, 'success_rate' => 0.91, 'learning_age_days' => 5]),
        ]);

        $hint = collect($r['dispatch_hints'])->firstWhere('task_family', 'test_family');
        $this->assertNotNull($hint);
        $this->assertStringContainsString('tighten_fabric', $hint['rationale'],
            'weak-green dispatch hint must say tighten_fabric, not confirm tier');
    }

    public function test_ac2_proven_green_still_boosts_supply(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['has_value_proof' => true, 'success_rate' => 0.90]),
        ]);

        $this->assertEmpty($r['weak_green_quality_reviews'],
            'proven green must not create weak-green review');

        $adj = collect($r['task_family_supply_adjustments'])->firstWhere('task_family', 'test_family');
        $this->assertNotNull($adj);
        $this->assertSame('increase', $adj['adjustment']);
    }

    // ── AC3: poison-prone families → blocks + reduce_supply + cheaper tier ───

    public function test_ac3_high_give_back_rate_triggers_poison_block(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['give_back_rate' => 0.75, 'task_family' => 'toxic']),
        ]);

        $this->assertCount(1, $r['poison_family_blocks']);
        $this->assertSame('toxic', $r['poison_family_blocks'][0]['task_family']);
    }

    public function test_ac3_quarantine_rate_triggers_poison_block(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['quarantine_rate' => 0.55, 'task_family' => 'quarantined']),
        ]);

        $this->assertCount(1, $r['poison_family_blocks']);
        $this->assertSame('quarantined', $r['poison_family_blocks'][0]['task_family']);
    }

    public function test_ac3_poison_family_emits_reduce_supply_replenisher_feedback(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['give_back_rate' => 0.80, 'task_family' => 'bad']),
        ]);

        $fb = collect($r['replenisher_feedback'])->firstWhere('task_family', 'bad');
        $this->assertNotNull($fb);
        $this->assertSame('reduce_supply', $fb['action']);
        $this->assertStringContainsString('poison_block', $fb['reason']);
    }

    public function test_ac3_poison_family_emits_supply_block_adjustment(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['give_back_rate' => 0.80, 'task_family' => 'bad']),
        ]);

        $adj = collect($r['task_family_supply_adjustments'])->firstWhere('task_family', 'bad');
        $this->assertNotNull($adj);
        $this->assertSame('block', $adj['adjustment']);
    }

    public function test_ac3_poison_family_dispatch_hint_recommends_cheaper_tier(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow([
                'give_back_rate'  => 0.80,
                'task_family'     => 'bad',
                'worker_tier'     => 'frontier_model',
                'learning_age_days' => 5,
            ]),
        ]);

        $hint = collect($r['dispatch_hints'])->firstWhere('task_family', 'bad');
        $this->assertNotNull($hint);
        $this->assertNotSame('frontier_model', $hint['prefer'],
            'poison family must route to a cheaper tier, not maintain frontier');
        $this->assertStringContainsString('poison_block', $hint['rationale']);
    }

    // ── AC4: fresh proven rows boost + confirm tier; stale rows excluded ──────

    public function test_ac4_fresh_proven_row_emits_boost_supply(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow([
                'success_rate'     => 0.90,
                'has_value_proof'  => true,
                'learning_age_days' => 10,
            ]),
        ]);

        $fb = collect($r['replenisher_feedback'])->firstWhere('task_family', 'test_family');
        $this->assertNotNull($fb);
        $this->assertSame('boost_supply', $fb['action']);
    }

    public function test_ac4_fresh_proven_row_confirms_worker_tier(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow([
                'success_rate'     => 0.92,
                'has_value_proof'  => true,
                'worker_tier'      => 'frontier_model',
                'learning_age_days' => 5,
            ]),
        ]);

        $affinity = collect($r['worker_affinity_updates'])->firstWhere('task_family', 'test_family');
        $this->assertNotNull($affinity);
        $this->assertSame('frontier_model', $affinity['recommended_tier'],
            'proven success must confirm the current tier, not downgrade it');
    }

    public function test_ac4_stale_row_produces_no_dispatch_hint(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow([
                'success_rate'     => 0.95,
                'has_value_proof'  => true,
                'learning_age_days' => 45,  // older than STALE_LEARNING_DAYS=30
            ]),
        ]);

        $hint = collect($r['dispatch_hints'])->firstWhere('task_family', 'test_family');
        $this->assertNull($hint, 'stale rows must not dominate dispatch hints');
    }

    public function test_ac4_deterministic_identical_input(): void
    {
        $rows = [
            $this->baseRow(['task_family' => 'alpha', 'has_value_proof' => true]),
            $this->baseRow(['task_family' => 'beta',  'has_value_proof' => false]),
            $this->baseRow(['task_family' => 'gamma', 'give_back_rate' => 0.75]),
        ];

        $this->assertSame(
            json_encode($this->svc()->bridge($rows), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->bridge($rows), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_row_below_min_samples_is_skipped(): void
    {
        $r = $this->svc()->bridge([
            $this->baseRow(['sample_count' => 2, 'task_family' => 'tiny']),
        ]);

        $this->assertEmpty($r['worker_affinity_updates']);
        $this->assertEmpty($r['task_family_supply_adjustments']);
    }
}
