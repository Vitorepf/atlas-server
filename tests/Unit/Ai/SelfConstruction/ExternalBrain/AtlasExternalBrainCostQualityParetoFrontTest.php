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

    private function option(string $id, float $quality, float $cost, string $justification = '', float $riskReduction = 0.0, float $expectedLift = 0.0): array
    {
        $o = ['option_id' => $id, 'quality' => $quality, 'cost' => $cost];
        if ($justification !== '') {
            $o['frontier_justification'] = $justification;
        }
        if ($riskReduction > 0.0) {
            $o['risk_reduction'] = $riskReduction;
        }
        if ($expectedLift > 0.0) {
            $o['expected_lift'] = $expectedLift;
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
                array_merge($this->option('non-small-frontier', 0.90, 5.0), ['expected_lift' => 0.15]),
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
                $this->option('expensive-frontier', 0.80, 5.0, 'reduces catastrophic failure risk by 3×', riskReduction: 0.30),
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
                $this->option('frontier', 0.85, 10.0, 'prevents merge governor bypass', expectedLift: 0.20),
            ],
        ]);

        $this->assertNotEmpty(array_filter($result['risk_notes'], static fn (string $n): bool => str_contains($n, 'frontier')));
    }

    public function test_frontier_justification_without_measurable_lift_or_risk_reduction_is_not_exempt(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('cheap-good', 0.90, 1.0),
                $this->option('expensive-frontier', 0.80, 5.0, 'sounds important but has no measurable backing'),
            ],
        ]);

        $paretoIds = array_column($result['pareto_options'], 'option_id');
        $this->assertNotContains('expensive-frontier', $paretoIds, 'unjustified frontier claims must not escape dominance');
        $this->assertContains('expensive-frontier', array_column($result['dominated_options'], 'option_id'));
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
            'options' => [array_merge($this->option('opt', 0.80, 1.0), ['expected_lift' => 0.15])],
        ]);

        $this->assertContains('benchmark_miss', $result['escalation_triggers']);
    }

    public function test_escalation_trigger_proxy_leakage(): void
    {
        $result = $this->front()->compute([
            'proxy_failed' => true,
            'options' => [array_merge($this->option('opt', 0.80, 1.0), ['expected_lift' => 0.15])],
        ]);

        $this->assertContains('proxy_leakage', $result['escalation_triggers']);
    }

    public function test_escalation_trigger_repair_loop_failure(): void
    {
        $result = $this->front()->compute([
            'repair_loop_failed' => true,
            'options' => [array_merge($this->option('opt', 0.80, 1.0), ['expected_lift' => 0.15])],
        ]);

        $this->assertContains('repair_loop_failure', $result['escalation_triggers']);
    }

    public function test_multiple_escalation_triggers_accumulated(): void
    {
        $result = $this->front()->compute([
            'benchmark_failed'   => true,
            'proxy_failed'       => true,
            'repair_loop_failed' => true,
            'options'            => [array_merge($this->option('opt', 0.80, 1.0), ['expected_lift' => 0.15])],
        ]);

        $this->assertCount(3, $result['escalation_triggers']);
    }

    public function test_no_escalation_when_no_option_clears_lift_or_risk_threshold(): void
    {
        $result = $this->front()->compute([
            'benchmark_failed' => true,
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertSame([], $result['escalation_triggers'],
            'escalation must not fire when no option carries a measurable expected_lift or risk_reduction clearing the threshold');
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

    // ── AC: quality_floor_failures ────────────────────────────────────────────

    public function test_quality_floor_failures_lists_option_id_and_failed_floors(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.90,
            'safety_floor' => 0.90,
            'autonomy_floor' => 0.90,
            'evidence_confidence_floor' => 0.90,
            'proxy_risk_ceiling' => 0.10,
            'options' => [
                array_merge($this->option('weak', 0.10, 1.0), [
                    'safety' => 0.10,
                    'autonomy' => 0.10,
                    'evidence_confidence' => 0.10,
                    'proxy_risk' => 0.90,
                ]),
            ],
        ]);

        $failure = $result['quality_floor_failures'][0];
        $this->assertSame('weak', $failure['option_id']);
        foreach (['quality', 'safety', 'autonomy', 'evidence_confidence', 'proxy_risk'] as $floor) {
            $this->assertContains($floor, $failure['failed_floors']);
        }
    }

    // ── AC: cheap scaffolded model blocked by evidence_confidence/proxy_risk ──

    public function test_cheap_scaffolded_model_not_recommended_when_evidence_confidence_below_floor(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.50,
            'evidence_confidence_floor' => 0.80,
            'options' => [
                array_merge(
                    $this->option('cheap-weak-evidence', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true, 'evidence_confidence' => 0.20],
                ),
                array_merge($this->option('expensive-strong', 0.85, 5.0), ['evidence_confidence' => 0.90]),
            ],
        ]);

        $this->assertNotSame('cheap-weak-evidence', $result['recommended_option']);
        $this->assertSame('expensive-strong', $result['recommended_option']);
    }

    public function test_cheap_scaffolded_model_not_recommended_when_proxy_risk_exceeds_ceiling(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.50,
            'proxy_risk_ceiling' => 0.20,
            'options' => [
                array_merge(
                    $this->option('cheap-risky', 0.80, 1.0),
                    ['is_scaffolded_small_model' => true, 'proxy_risk' => 0.90],
                ),
                array_merge($this->option('expensive-safe', 0.85, 5.0), ['proxy_risk' => 0.05]),
            ],
        ]);

        $this->assertNotSame('cheap-risky', $result['recommended_option']);
        $this->assertSame('expensive-safe', $result['recommended_option']);
    }

    // ── AC: high-cost recommended only when lift/risk_reduction clears threshold ──

    public function test_high_cost_option_recommended_only_when_expected_lift_clears_escalation_threshold(): void
    {
        $blocked = $this->front()->compute([
            'quality_floor' => 0.50,
            'options' => [
                array_merge($this->option('cheap', 0.60, 1.0), ['is_scaffolded_small_model' => true]),
                array_merge($this->option('expensive-low-lift', 0.65, 10.0), ['expected_lift' => 0.02]),
            ],
        ]);
        $this->assertSame('cheap', $blocked['recommended_option']);

        $allowed = $this->front()->compute([
            'quality_floor' => 0.50,
            'benchmark_failed' => true,
            'options' => [
                array_merge($this->option('cheap', 0.60, 1.0), ['is_scaffolded_small_model' => true]),
                array_merge($this->option('expensive-high-lift', 0.65, 10.0), ['expected_lift' => 0.20]),
            ],
        ]);
        $this->assertContains('benchmark_miss', $allowed['escalation_triggers']);
    }

    // ── AC: recommendation_reason ──────────────────────────────────────────────

    public function test_recommendation_reason_present_and_explains_floor_choice(): void
    {
        $result = $this->front()->compute([
            'quality_floor' => 0.50,
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertArrayHasKey('recommendation_reason', $result);
        $this->assertStringContainsString('opt', $result['recommendation_reason']);
    }

    public function test_recommendation_reason_present_for_ratio_based_choice(): void
    {
        $result = $this->front()->compute([
            'options' => [$this->option('opt', 0.80, 1.0)],
        ]);

        $this->assertArrayHasKey('recommendation_reason', $result);
        $this->assertNotEmpty($result['recommendation_reason']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    // ── AC2/AC3: routing_reason present per pareto option ─────────────────────

    public function test_routing_reason_present_on_every_pareto_option(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-cheap', 0.70, 1.0),
                $this->option('opt-good', 0.90, 3.0),
            ],
        ]);

        foreach ($result['pareto_options'] as $po) {
            $this->assertArrayHasKey('routing_reason', $po);
            $this->assertNotEmpty($po['routing_reason']);
        }
        $recommended = array_values(array_filter($result['pareto_options'], fn ($o) => $o['option_id'] === $result['recommended_option']))[0];
        $this->assertSame($result['recommendation_reason'], $recommended['routing_reason']);
    }

    // ── AC4: high retry risk excludes an option from recommendation ──────────

    public function test_high_retry_risk_option_is_never_recommended(): void
    {
        $result = $this->front()->compute([
            'options' => [
                array_merge($this->option('risky-best-ratio', 0.90, 1.0), ['retry_risk' => 0.80]),
                $this->option('safe-lower-ratio', 0.70, 2.0),
            ],
        ]);

        $this->assertNotSame('risky-best-ratio', $result['recommended_option']);
        $this->assertSame('safe-lower-ratio', $result['recommended_option']);
    }

    public function test_high_retry_risk_option_routing_reason_explains_exclusion(): void
    {
        $result = $this->front()->compute([
            'options' => [
                array_merge($this->option('risky', 0.90, 1.0), ['retry_risk' => 0.80]),
                $this->option('safe', 0.70, 2.0),
            ],
        ]);

        $risky = array_values(array_filter($result['pareto_options'], fn ($o) => $o['option_id'] === 'risky'))[0];
        $this->assertStringContainsString('retry_risk_exceeds_ceiling', $risky['routing_reason']);
    }

    // ── AC4: leverage-adjusted selection ──────────────────────────────────────

    public function test_leverage_adjusted_selection_prefers_higher_leverage_option(): void
    {
        // Equal quality/cost ratio (0.80/2.0 = 0.40), but 'high-leverage' carries measurable
        // expected leverage — it should win the routing score even though the raw ratio ties.
        $result = $this->front()->compute([
            'options' => [
                array_merge($this->option('plain', 0.80, 2.0), ['leverage' => 0.0]),
                array_merge($this->option('high-leverage', 0.80, 2.0), ['leverage' => 1.0]),
            ],
        ]);

        $this->assertSame('high-leverage', $result['recommended_option']);
    }

    public function test_zero_leverage_and_latency_preserve_existing_ratio_behavior(): void
    {
        $result = $this->front()->compute([
            'options' => [
                $this->option('opt-a', 0.80, 2.0),
                $this->option('opt-b', 0.70, 1.0),
                $this->option('opt-c', 0.90, 4.0),
            ],
        ]);

        $this->assertSame('opt-b', $result['recommended_option']);
    }

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
