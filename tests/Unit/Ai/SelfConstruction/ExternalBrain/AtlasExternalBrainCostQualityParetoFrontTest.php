<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCostQualityParetoFront;
use Tests\TestCase;

final class AtlasExternalBrainCostQualityParetoFrontTest extends TestCase
{
    private function front(): AtlasExternalBrainCostQualityParetoFront
    {
        return new AtlasExternalBrainCostQualityParetoFront();
    }

    private function option(string $id, float $quality, float $cost, string $justification = ''): array
    {
        $o = ['option_id' => $id, 'quality' => $quality, 'cost' => $cost];
        if ($justification !== '') {
            $o['frontier_justification'] = $justification;
        }

        return $o;
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->front()->compute([]);

        $this->assertSame(AtlasExternalBrainCostQualityParetoFront::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->front()->compute([]);

        foreach (['schema', 'pareto_options', 'dominated_options', 'recommended_option',
                  'floor_recommended_option', 'quality_cost_tradeoffs', 'risk_notes',
                  'escalation_triggers', 'dominated_frontier_dependency'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_benchmark_failure_excludes_scaffolded_small_model_from_floor_recommendation(): void
    {
        $result = $this->front()->compute([
            'quality_floor'    => 0.70,
            'benchmark_failed' => true,
            'options' => [
                array_merge(
                    $this->option('scaffolded-cheap', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true],
                ),
                $this->option('non-small-frontier', 0.90, 5.0),
            ],
        ]);

        $this->assertContains('benchmark_miss', $result['escalation_triggers']);
        $this->assertNotSame('scaffolded-cheap', $result['recommended_option']);
        $this->assertSame('non-small-frontier', $result['recommended_option']);
    }

    public function test_empty_options_yields_empty_result(): void
    {
        $result = $this->front()->compute(['options' => []]);

        $this->assertSame([], $result['pareto_options']);
        $this->assertSame([], $result['dominated_options']);
        $this->assertNull($result['recommended_option']);
    }

    // ── pareto_options (non-dominated) ────────────────────────────────────────

    public function test_single_option_is_on_pareto_front(): void
    {
        $result = $this->front()->compute([
            'options' => [$this->option('opt-a', 0.80, 2.0)],
        ]);

        $ids = array_column($result['pareto_options'], 'option_id');
        $this->assertContains('opt-a', $ids);
        $this->assertSame([], $result['dominated_options']);
    }

    public function test_two_options_where_one_dominates_other(): void
    {
        // opt-b: quality 0.85, cost 2.0 → dominates opt-a (quality 0.80, cost 2.0)
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-a', 0.80, 2.0),
                $this->option('opt-b', 0.85, 2.0),
            ],
        ]);

        $paretoIds   = array_column($result['pareto_options'], 'option_id');
        $dominatedIds = array_column($result['dominated_options'], 'option_id');

        $this->assertContains('opt-b', $paretoIds);
        $this->assertNotContains('opt-a', $paretoIds);
        $this->assertContains('opt-a', $dominatedIds);
    }

    public function test_both_options_on_front_when_tradeoff_exists(): void
    {
        // opt-cheap: quality 0.70, cost 1.0 — high ratio but lower quality
        // opt-good:  quality 0.90, cost 3.0 — better quality, higher cost
        // Neither dominates the other → both on front.
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-cheap', 0.70, 1.0),
                $this->option('opt-good', 0.90, 3.0),
            ],
        ]);

        $paretoIds = array_column($result['pareto_options'], 'option_id');
        $this->assertContains('opt-cheap', $paretoIds);
        $this->assertContains('opt-good', $paretoIds);
        $this->assertSame([], $result['dominated_options']);
    }

    // ── dominated_options ─────────────────────────────────────────────────────

    public function test_dominated_option_records_dominated_by(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('cheap-good', 0.90, 1.0),
                $this->option('expensive-worse', 0.80, 3.0),
            ],
        ]);

        $dominated = $result['dominated_options'];
        $this->assertCount(1, $dominated);
        $this->assertSame('expensive-worse', $dominated[0]['option_id']);
        $this->assertSame('cheap-good', $dominated[0]['dominated_by']);
    }

    public function test_dominated_option_has_reason(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('cheap-good', 0.90, 1.0),
                $this->option('expensive-worse', 0.80, 3.0),
            ],
        ]);

        $this->assertNotEmpty($result['dominated_options'][0]['reason']);
    }

    // ── frontier_justification ────────────────────────────────────────────────

    public function test_frontier_option_preserved_even_when_dominated_on_cost_quality(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('cheap-good', 0.90, 1.0),
                $this->option('expensive-frontier', 0.80, 5.0, 'reduces catastrophic failure risk by 3×'),
            ],
        ]);

        $paretoIds = array_column($result['pareto_options'], 'option_id');
        $this->assertContains('expensive-frontier', $paretoIds);
    }

    public function test_frontier_preservation_noted_in_risk_notes(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-a', 0.80, 1.0),
                $this->option('frontier', 0.85, 10.0, 'prevents merge governor bypass'),
            ],
        ]);

        $this->assertNotEmpty(array_filter($result['risk_notes'], static fn (string $n): bool => str_contains($n, 'frontier')));
    }

    // ── recommended_option ────────────────────────────────────────────────────

    public function test_recommended_is_highest_quality_per_cost_on_pareto_front(): void
    {
        // opt-a: 0.80/2.0 = 0.40 ratio
        // opt-b: 0.70/1.0 = 0.70 ratio  ← highest
        // opt-c: 0.90/4.0 = 0.225 ratio
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-a', 0.80, 2.0),
                $this->option('opt-b', 0.70, 1.0),
                $this->option('opt-c', 0.90, 4.0),
            ],
        ]);

        $this->assertSame('opt-b', $result['recommended_option']);
    }

    public function test_recommended_is_null_when_no_options(): void
    {
        $result = $this->front()->compute([]);

        $this->assertNull($result['recommended_option']);
    }

    // ── quality_cost_tradeoffs ────────────────────────────────────────────────

    public function test_tradeoffs_sorted_by_quality_per_cost_desc(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('low-ratio', 0.50, 5.0),   // 0.10
                $this->option('high-ratio', 0.90, 1.0),  // 0.90
                $this->option('mid-ratio', 0.60, 2.0),   // 0.30
            ],
        ]);

        $ids = array_column($result['quality_cost_tradeoffs'], 'option_id');
        $this->assertSame(['high-ratio', 'mid-ratio', 'low-ratio'], $ids);
    }

    public function test_tradeoffs_include_all_options(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('a', 0.80, 2.0),
                $this->option('b', 0.70, 1.0),
            ],
        ]);

        $this->assertCount(2, $result['quality_cost_tradeoffs']);
    }

    // ── escalation_triggers ───────────────────────────────────────────────────

    public function test_no_escalation_when_all_checks_pass(): void
    {
        $result = $this->front()->compute([
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertSame([], $result['escalation_triggers']);
    }

    public function test_escalation_trigger_benchmark_miss(): void
    {
        $result = $this->front()->compute([
            'benchmark_failed' => true,
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertContains('benchmark_miss', $result['escalation_triggers']);
    }

    public function test_escalation_trigger_proxy_leakage(): void
    {
        $result = $this->front()->compute([
            'proxy_failed' => true,
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertContains('proxy_leakage', $result['escalation_triggers']);
    }

    public function test_escalation_trigger_repair_loop_failure(): void
    {
        $result = $this->front()->compute([
            'repair_loop_failed' => true,
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertContains('repair_loop_failure', $result['escalation_triggers']);
    }

    public function test_multiple_escalation_triggers_accumulated(): void
    {
        $result = $this->front()->compute([
            'benchmark_failed'   => true,
            'proxy_failed'       => true,
            'repair_loop_failed' => true,
            'options'            => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertCount(3, $result['escalation_triggers']);
    }

    // ── floor-based recommendation (model-amplifier policy) ───────────────────

    public function test_cheapest_floor_meeting_recommended_over_frontier(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.70,
            'options' => [
                array_merge(
                    $this->option('scaffolded-cheap', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true],
                ),
                $this->option('frontier-expensive', 0.95, 10.0, 'strategic reserve'),
            ],
        ]);

        $this->assertSame('scaffolded-cheap', $result['recommended_option']);
    }

    public function test_floor_recommendation_picks_cheapest_among_qualifiers(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.70,
            'options' => [
                $this->option('mid',  0.80, 3.0),
                $this->option('cheap', 0.75, 1.0),
                $this->option('low',  0.60, 0.5), // below floor — excluded
            ],
        ]);

        $this->assertSame('cheap', $result['recommended_option']);
    }

    public function test_no_floor_falls_back_to_ratio_recommendation(): void
    {
        // No quality_floor set → existing ratio logic
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-a', 0.80, 2.0),  // ratio 0.40
                $this->option('opt-b', 0.70, 1.0),  // ratio 0.70 ← highest
                $this->option('opt-c', 0.90, 4.0),  // ratio 0.225
            ],
        ]);

        $this->assertSame('opt-b', $result['recommended_option']);
    }

    // ── dominated_frontier_dependency ─────────────────────────────────────────

    public function test_frontier_without_lift_flagged_when_scaffolded_meets_floor(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.70,
            'options' => [
                array_merge(
                    $this->option('scaffolded', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true],
                ),
                $this->option('frontier-no-lift', 0.90, 8.0, 'claimed but unquantified benefit'),
            ],
        ]);

        $this->assertContains('frontier-no-lift', $result['dominated_frontier_dependency']);
        $this->assertNotEmpty(array_filter($result['risk_notes'], fn ($n) => str_contains($n, 'frontier-no-lift')));
    }

    public function test_frontier_with_expected_lift_not_flagged_as_dominated(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.70,
            'options' => [
                array_merge(
                    $this->option('scaffolded', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true],
                ),
                array_merge(
                    $this->option('frontier-with-lift', 0.95, 8.0, 'reduces merge conflict rate'),
                    ['expected_lift' => 0.25],
                ),
            ],
        ]);

        $this->assertNotContains('frontier-with-lift', $result['dominated_frontier_dependency']);
    }

    public function test_frontier_with_risk_reduction_not_flagged_as_dominated(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.70,
            'options' => [
                array_merge(
                    $this->option('scaffolded', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true],
                ),
                array_merge(
                    $this->option('frontier-risk-reduction', 0.95, 8.0, 'prevents catastrophic failure'),
                    ['risk_reduction' => 0.40],
                ),
            ],
        ]);

        $this->assertNotContains('frontier-risk-reduction', $result['dominated_frontier_dependency']);
    }

    public function test_no_dominated_frontier_when_floors_not_active(): void
    {
        // No quality_floor → floors inactive → no dominated frontier check
        $result = $this->front()->compute([
            'options' => [
                $this->option('scaffolded-no-floor', 0.80, 1.0),
                $this->option('frontier-no-lift', 0.90, 8.0, 'some justification'),
            ],
        ]);

        $this->assertSame([], $result['dominated_frontier_dependency']);
    }

    // ── output keys present ───────────────────────────────────────────────────

    public function test_output_includes_new_amplifier_keys(): void
    {
        $result = $this->front()->compute([]);

        $this->assertArrayHasKey('escalation_triggers', $result);
        $this->assertArrayHasKey('dominated_frontier_dependency', $result);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'options' => [
                $this->option('a', 0.80, 2.0),
                $this->option('b', 0.90, 4.0),
                $this->option('c', 0.70, 1.0),
            ],
        ];

        $this->assertSame($this->front()->compute($input), $this->front()->compute($input));
    }
}
