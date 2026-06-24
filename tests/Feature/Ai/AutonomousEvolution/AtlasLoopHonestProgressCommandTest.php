<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the `atlas:loop:honest-progress` CLI prints the FOUR honest loop metrics side by side — each its own
 * section + schema — and NEVER folds them into a composite super-score (the operator's anti-Goodhart rule).
 *
 * Focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension); the loop tables
 * exist so the metrics run for real (and the e2e section emits no stderr WARN).
 */
final class AtlasLoopHonestProgressCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php',
                '2026_06_16_000200_create_atlas_loop_delivery_contracts_table.php',
                '2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    /** Recursively collect every array key (at any depth). */
    private function allKeys(mixed $node, array &$keys): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $this->allKeys($value, $keys);
        }
    }

    // (1) --json ⇒ valid JSON, correct schema, EXACTLY the 4 metric keys + schema + computed_at.
    public function test_json_has_schema_and_exactly_four_metric_keys(): void
    {
        $code = Artisan::call('atlas:loop:honest-progress', ['--json' => true]);
        $this->assertSame(0, $code);

        $out = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($out, 'output must be valid JSON');
        $this->assertSame('atlas.loop.honest_progress.v1', $out['schema']);

        $this->assertSame(
            ['computed_at', 'e2e_cycle_liveness', 'primitive_armed_ratio', 'schema', 'supply_lane_genuine_yield', 'thrash_loss_rate'],
            collect(array_keys($out))->sort()->values()->all(),
            'exactly the 4 metric keys + schema + computed_at at the top level',
        );
        $this->assertIsArray($out['e2e_cycle_liveness'], 'e2e is a per-campaign list');
    }

    // (2) human output ⇒ the four named sections.
    public function test_human_output_has_four_named_sections(): void
    {
        Artisan::call('atlas:loop:honest-progress', []);
        $out = Artisan::output();

        $this->assertStringContainsString('PRIMITIVES ARMED', $out);
        $this->assertStringContainsString('E2E CYCLE LIVENESS', $out);
        $this->assertStringContainsString('SUPPLY LANE YIELD', $out);
        $this->assertStringContainsString('THRASH LOSS RATE', $out);
    }

    // (3) anti-aggregation ⇒ NO composite/super-score key anywhere (recursive).
    public function test_no_composite_super_score_key_anywhere(): void
    {
        Artisan::call('atlas:loop:honest-progress', ['--json' => true]);
        $out = json_decode(trim(Artisan::output()), true);

        $keys = [];
        $this->allKeys($out, $keys);

        foreach ($keys as $key) {
            foreach (['health_score', 'overall_grade', 'composite', 'weighted_average'] as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $key, "no aggregate key may appear (found '{$key}')");
            }
        }
    }
}
