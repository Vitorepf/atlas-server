<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierHarvestYieldModel;
use Tests\TestCase;

final class AtlasExternalBrainFrontierHarvestYieldModelTest extends TestCase
{
    private function model(): AtlasExternalBrainFrontierHarvestYieldModel
    {
        return new AtlasExternalBrainFrontierHarvestYieldModel();
    }

    private function goodFrontier(string $id = 'F1'): array
    {
        return [
            'frontier_id' => $id,
            'raw_seed_count' => 100,
            'unique_high_value_count' => 30,
            'duplicate_count' => 10,
            'forbidden_wall_count' => 0,
            'compounding_impact_per_task' => 1.0,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->model()->model([]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::SCHEMA, $result['schema']);
    }

    public function test_output_has_results_key(): void
    {
        $result = $this->model()->model([]);

        $this->assertArrayHasKey('results', $result);
    }

    public function test_each_result_has_required_fields(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);

        $entry = $result['results'][0];
        foreach ([
            'frontier_id', 'decision', 'marginal_yield', 'duplicate_rate',
            'forbidden_wall_rate', 'expected_next_batch_value', 'evidence_counts', 'reasons',
        ] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_evidence_counts_has_required_fields(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);

        $counts = $result['results'][0]['evidence_counts'];
        foreach (['raw_seed_count', 'unique_high_value_count', 'duplicate_count', 'forbidden_wall_count'] as $f) {
            $this->assertArrayHasKey($f, $counts);
        }
    }

    public function test_empty_frontiers_yields_empty_results(): void
    {
        $result = $this->model()->model(['frontiers' => []]);

        $this->assertSame([], $result['results']);
    }

    // ── computed rates ────────────────────────────────────────────────────────

    public function test_marginal_yield_duplicate_rate_and_forbidden_wall_rate_are_computed(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);
        $r = $result['results'][0];

        $this->assertSame(0.3, $r['marginal_yield']);
        $this->assertSame(0.1, $r['duplicate_rate']);
        $this->assertSame(0.0, $r['forbidden_wall_rate']);
        $this->assertSame(30.0, $r['expected_next_batch_value']);
    }

    public function test_expected_next_batch_value_scales_with_compounding_impact(): void
    {
        $f = $this->goodFrontier();
        $f['compounding_impact_per_task'] = 2.5;

        $result = $this->model()->model(['frontiers' => [$f]]);

        $this->assertSame(75.0, $result['results'][0]['expected_next_batch_value']);
    }

    // ── continue ──────────────────────────────────────────────────────────────

    public function test_continue_when_yield_high_and_no_walls_or_duplication(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONTINUE, $result['results'][0]['decision']);
    }

    // ── change_strategy (forbidden wall) ─────────────────────────────────────

    public function test_change_strategy_when_forbidden_wall_rate_high(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id' => 'F2',
                'raw_seed_count' => 100,
                'unique_high_value_count' => 40,
                'duplicate_count' => 0,
                'forbidden_wall_count' => 40,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CHANGE_STRATEGY, $result['results'][0]['decision']);
    }

    public function test_forbidden_wall_takes_priority_over_high_yield(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id' => 'F2b',
                'raw_seed_count' => 100,
                'unique_high_value_count' => 60,
                'duplicate_count' => 0,
                'forbidden_wall_count' => 35,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CHANGE_STRATEGY, $result['results'][0]['decision']);
    }

    // ── consolidate (duplicate rate) ─────────────────────────────────────────

    public function test_consolidate_when_duplicate_rate_high(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id' => 'F3',
                'raw_seed_count' => 100,
                'unique_high_value_count' => 30,
                'duplicate_count' => 55,
                'forbidden_wall_count' => 0,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONSOLIDATE, $result['results'][0]['decision']);
    }

    // ── stop_frontier_harvest ─────────────────────────────────────────────────

    public function test_stop_frontier_harvest_when_marginal_yield_below_floor(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id' => 'F4',
                'raw_seed_count' => 100,
                'unique_high_value_count' => 5,
                'duplicate_count' => 10,
                'forbidden_wall_count' => 0,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_STOP_FRONTIER_HARVEST, $result['results'][0]['decision']);
    }

    // ── raw seed count alone is insufficient (AC4) ───────────────────────────

    public function test_large_raw_seed_count_with_zero_unique_high_value_stops_harvest(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id' => 'F5',
                'raw_seed_count' => 10000,
                'unique_high_value_count' => 0,
                'duplicate_count' => 0,
                'forbidden_wall_count' => 0,
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_STOP_FRONTIER_HARVEST, $result['results'][0]['decision']);
        $this->assertSame(0.0, $result['results'][0]['marginal_yield']);
        $reasons = implode(' ', $result['results'][0]['reasons']);
        $this->assertStringContainsString('alone is not evidence', $reasons);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_marginal_yield_floor(): void
    {
        $result = $this->model()->model([
            'frontiers' => [$this->goodFrontier()], // marginal_yield = 0.3
            'marginal_yield_floor' => 0.5,
        ]);

        $this->assertNotSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CONTINUE, $result['results'][0]['decision']);
    }

    public function test_custom_min_expected_batch_value_can_block_continue(): void
    {
        $result = $this->model()->model([
            'frontiers' => [$this->goodFrontier()], // expected_next_batch_value = 30
            'min_expected_batch_value' => 1000,
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_STOP_FRONTIER_HARVEST, $result['results'][0]['decision']);
    }

    // ── reasons non-empty ─────────────────────────────────────────────────────

    public function test_all_decisions_produce_non_empty_reasons(): void
    {
        $result = $this->model()->model([
            'frontiers' => [
                $this->goodFrontier('continue'),
                ['frontier_id' => 'wall', 'raw_seed_count' => 100, 'unique_high_value_count' => 50, 'duplicate_count' => 0, 'forbidden_wall_count' => 40],
                ['frontier_id' => 'dup', 'raw_seed_count' => 100, 'unique_high_value_count' => 30, 'duplicate_count' => 60, 'forbidden_wall_count' => 0],
                ['frontier_id' => 'stop', 'raw_seed_count' => 100, 'unique_high_value_count' => 0, 'duplicate_count' => 0, 'forbidden_wall_count' => 0],
            ],
        ]);

        foreach ($result['results'] as $r) {
            $this->assertNotEmpty($r['reasons'], "Expected non-empty reasons for decision: {$r['decision']}");
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'frontiers' => [$this->goodFrontier('A'), $this->goodFrontier('B')],
        ];

        $this->assertSame($this->model()->model($input), $this->model()->model($input));
    }
}
