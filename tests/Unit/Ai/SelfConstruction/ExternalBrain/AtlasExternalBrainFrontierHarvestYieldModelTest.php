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
            'frontier_id'      => $id,
            'verified_yield'   => 10,
            'duplicate_rate'   => 0.10,
            'give_back_rate'   => 0.05,
            'tried_methods'    => ['grep'],
            'available_methods' => ['grep', 'semantic', 'ast'],
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
        foreach (['frontier_id', 'decision', 'next_harvest_method', 'evidence_counts', 'reasons'] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_evidence_counts_has_required_fields(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);

        $counts = $result['results'][0]['evidence_counts'];
        foreach (['verified_yield', 'tried_methods_count', 'untried_methods_count'] as $f) {
            $this->assertArrayHasKey($f, $counts);
        }
    }

    public function test_empty_frontiers_yields_empty_results(): void
    {
        $result = $this->model()->model(['frontiers' => []]);

        $this->assertSame([], $result['results']);
    }

    // ── harvest_more ──────────────────────────────────────────────────────────

    public function test_harvest_more_when_yield_high_and_rates_low(): void
    {
        $result = $this->model()->model(['frontiers' => [$this->goodFrontier()]]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_HARVEST_MORE, $result['results'][0]['decision']);
        $this->assertNull($result['results'][0]['next_harvest_method']);
    }

    public function test_harvest_more_at_exact_yield_threshold(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F',
                'verified_yield'   => 3,   // default threshold
                'duplicate_rate'   => 0.10,
                'give_back_rate'   => 0.05,
                'tried_methods'    => [],
                'available_methods' => [],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_HARVEST_MORE, $result['results'][0]['decision']);
    }

    // ── change_method ─────────────────────────────────────────────────────────

    public function test_change_method_when_yield_low_but_untried_methods_remain(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F2',
                'verified_yield'   => 0,
                'duplicate_rate'   => 0.70,
                'give_back_rate'   => 0.60,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep', 'semantic'],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CHANGE_METHOD, $result['results'][0]['decision']);
    }

    public function test_change_method_picks_first_untried_method(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F3',
                'verified_yield'   => 0,
                'duplicate_rate'   => 0.80,
                'give_back_rate'   => 0.50,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep', 'semantic', 'ast'],
            ]],
        ]);

        $this->assertSame('semantic', $result['results'][0]['next_harvest_method']);
    }

    public function test_frontier_not_retired_when_low_yield_but_untried_methods_exist(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F4',
                'verified_yield'   => 1,   // below threshold
                'duplicate_rate'   => 0.40,
                'give_back_rate'   => 0.30,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep', 'semantic'],
            ]],
        ]);

        $this->assertNotSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_RETIRE, $result['results'][0]['decision']);
        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_CHANGE_METHOD, $result['results'][0]['decision']);
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function test_retire_when_yield_low_no_untried_methods(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F5',
                'verified_yield'   => 0,
                'duplicate_rate'   => 0.90,
                'give_back_rate'   => 0.80,
                'tried_methods'    => ['grep', 'semantic'],
                'available_methods' => ['grep', 'semantic'],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_RETIRE, $result['results'][0]['decision']);
        $this->assertNull($result['results'][0]['next_harvest_method']);
    }

    public function test_retire_includes_reason_about_no_untried_methods(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F6',
                'verified_yield'   => 0,
                'duplicate_rate'   => 0.90,
                'give_back_rate'   => 0.80,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep'],
            ]],
        ]);

        $reasons = implode(' ', $result['results'][0]['reasons']);
        $this->assertStringContainsString('untried', $reasons);
    }

    // ── evidence_counts ───────────────────────────────────────────────────────

    public function test_untried_methods_count_is_correct(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F7',
                'verified_yield'   => 10,
                'duplicate_rate'   => 0.10,
                'give_back_rate'   => 0.05,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep', 'semantic', 'ast'],
            ]],
        ]);

        $this->assertSame(2, $result['results'][0]['evidence_counts']['untried_methods_count']);
        $this->assertSame(1, $result['results'][0]['evidence_counts']['tried_methods_count']);
        $this->assertSame(10, $result['results'][0]['evidence_counts']['verified_yield']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_yield_threshold(): void
    {
        $result = $this->model()->model([
            'frontiers' => [[
                'frontier_id'      => 'F8',
                'verified_yield'   => 5,
                'duplicate_rate'   => 0.10,
                'give_back_rate'   => 0.05,
                'tried_methods'    => ['grep'],
                'available_methods' => ['grep'],
            ]],
            'yield_threshold' => 10,   // 5 < 10 → not harvest_more
        ]);

        $this->assertNotSame(AtlasExternalBrainFrontierHarvestYieldModel::DECISION_HARVEST_MORE, $result['results'][0]['decision']);
    }

    // ── reasons non-empty ─────────────────────────────────────────────────────

    public function test_all_decisions_produce_non_empty_reasons(): void
    {
        $result = $this->model()->model([
            'frontiers' => [
                $this->goodFrontier('harvest'),
                [
                    'frontier_id' => 'change', 'verified_yield' => 0, 'duplicate_rate' => 0.8,
                    'give_back_rate' => 0.5, 'tried_methods' => ['a'], 'available_methods' => ['a', 'b'],
                ],
                [
                    'frontier_id' => 'retire', 'verified_yield' => 0, 'duplicate_rate' => 0.9,
                    'give_back_rate' => 0.9, 'tried_methods' => ['a'], 'available_methods' => ['a'],
                ],
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
