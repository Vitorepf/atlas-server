<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCostQualityParetoFront;
use Tests\TestCase;

final class AtlasExternalBrainCostQualityParetoFrontTest extends TestCase
{
    private function svc(): AtlasExternalBrainCostQualityParetoFront
    {
        return new AtlasExternalBrainCostQualityParetoFront;
    }

    private function compute(array $input): array
    {
        return $this->svc()->compute($input);
    }

    private function option(array $overrides = []): array
    {
        return array_merge([
            'option_id'                => 'opt_default',
            'quality'                  => 0.80,
            'cost'                     => 1.0,
            'safety'                   => 1.0,
            'autonomy'                 => 1.0,
            'expected_lift'            => 0.0,
            'risk_reduction'           => 0.0,
            'is_scaffolded_small_model' => false,
        ], $overrides);
    }

    // ── schema / structure ────────────────────────────────────────────────────

    public function test_schema_constant_present(): void
    {
        $r = $this->compute([]);
        $this->assertSame(AtlasExternalBrainCostQualityParetoFront::SCHEMA, $r['schema']);
    }

    // ── AC1: dominated options excluded — equal-or-better quality, lower cost, no higher risk ──

    public function test_ac1_dominated_option_excluded_when_cheaper_option_has_equal_quality(): void
    {
        // cheap dominates expensive: same quality (0.80), lower cost, same safety
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'expensive', 'quality' => 0.80, 'cost' => 5.0, 'safety' => 1.0]),
            $this->option(['option_id' => 'cheap',     'quality' => 0.80, 'cost' => 1.0, 'safety' => 1.0]),
        ]]);

        $dominated = array_column($r['dominated_options'], 'option_id');
        $this->assertContains('expensive', $dominated, 'expensive must be dominated by cheap (same quality, lower cost)');
        $this->assertNotContains('cheap', $dominated);
    }

    public function test_ac1_dominated_option_excluded_when_better_quality_at_same_cost(): void
    {
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'weak',   'quality' => 0.70, 'cost' => 1.0, 'safety' => 1.0]),
            $this->option(['option_id' => 'strong', 'quality' => 0.90, 'cost' => 1.0, 'safety' => 1.0]),
        ]]);

        $dominated = array_column($r['dominated_options'], 'option_id');
        $this->assertContains('weak', $dominated);
        $this->assertNotContains('strong', $dominated);
    }

    public function test_ac1_option_with_lower_safety_does_not_dominate_safer_option(): void
    {
        // unsafe has better quality+cost but lower safety → must NOT dominate safer option
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'unsafe', 'quality' => 0.90, 'cost' => 0.5, 'safety' => 0.50]),
            $this->option(['option_id' => 'safer',  'quality' => 0.80, 'cost' => 1.0, 'safety' => 1.00]),
        ]]);

        $dominated = array_column($r['dominated_options'], 'option_id');
        $this->assertNotContains('safer', $dominated, 'safer option must NOT be dominated by lower-safety option');
    }

    public function test_ac1_both_options_on_pareto_front_when_quality_cost_tradeoff_exists(): void
    {
        // cheap has lower quality; expensive has higher quality but higher cost
        // Neither dominates the other → both on Pareto front
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'cheap',     'quality' => 0.70, 'cost' => 1.0]),
            $this->option(['option_id' => 'expensive', 'quality' => 0.90, 'cost' => 5.0]),
        ]]);

        $paretoIds = array_column($r['pareto_options'], 'option_id');
        $this->assertContains('cheap',     $paretoIds);
        $this->assertContains('expensive', $paretoIds);
        $this->assertSame([], $r['dominated_options']);
    }

    // ── AC2: small_model_with_scaffold outranks frontier when quality is sufficient ──

    public function test_ac2_scaffolded_small_model_outranks_frontier_when_quality_sufficient(): void
    {
        // scaffold: quality 0.85, cost 1.0 → quality/cost ratio = 0.85
        // frontier: quality 0.90, cost 8.0 → quality/cost ratio = 0.1125
        // Both on Pareto front (scaffold cheaper, frontier higher quality).
        // Ratio-based: scaffold wins recommendation.
        $r = $this->compute(['options' => [
            $this->option([
                'option_id'                => 'scaffold',
                'quality'                  => 0.85,
                'cost'                     => 1.0,
                'is_scaffolded_small_model' => true,
            ]),
            $this->option([
                'option_id' => 'frontier',
                'quality'   => 0.90,
                'cost'      => 8.0,
            ]),
        ]]);

        $this->assertSame('scaffold', $r['recommended_option'],
            'scaffolded small model with higher quality/cost ratio must be recommended over expensive frontier');
    }

    public function test_ac2_scaffolded_model_recommended_via_floor_policy_when_meets_threshold(): void
    {
        $r = $this->compute([
            'quality_floor' => 0.80,
            'safety_floor'  => 0.90,
            'options'       => [
                $this->option([
                    'option_id'                => 'scaffold',
                    'quality'                  => 0.85,
                    'cost'                     => 1.0,
                    'safety'                   => 0.95,
                    'is_scaffolded_small_model' => true,
                ]),
                $this->option([
                    'option_id' => 'frontier',
                    'quality'   => 0.95,
                    'cost'      => 10.0,
                    'safety'    => 0.99,
                ]),
            ],
        ]);

        // Floor policy chooses cheapest meeting floors → scaffold
        $this->assertSame('scaffold', $r['recommended_option']);
    }

    // ── AC3: frontier escalation only when quality delta or risk reduction clears threshold ──

    public function test_ac3_escalation_not_triggered_when_frontier_lift_below_threshold(): void
    {
        // expected_lift = 0.05 < ESCALATION_QUALITY_DELTA_THRESHOLD (0.10) → no escalation
        $r = $this->compute([
            'benchmark_failed' => true,
            'proxy_failed'     => true,
            'options'          => [
                $this->option([
                    'option_id'     => 'frontier',
                    'quality'       => 0.85,
                    'cost'          => 5.0,
                    'expected_lift' => 0.05,
                ]),
            ],
        ]);

        $this->assertSame([], $r['escalation_triggers'],
            'escalation must not fire when frontier expected_lift is below the quality delta threshold');
    }

    public function test_ac3_escalation_triggered_when_frontier_lift_meets_threshold(): void
    {
        // expected_lift = 0.15 >= 0.10 → escalation permitted
        $r = $this->compute([
            'benchmark_failed' => true,
            'options'          => [
                $this->option([
                    'option_id'     => 'frontier',
                    'quality'       => 0.90,
                    'cost'          => 5.0,
                    'expected_lift' => 0.15,
                ]),
            ],
        ]);

        $this->assertContains('benchmark_miss', $r['escalation_triggers']);
    }

    public function test_ac3_escalation_triggered_when_risk_reduction_meets_threshold(): void
    {
        // risk_reduction = 0.20 >= 0.10 → escalation permitted even if lift is low
        $r = $this->compute([
            'repair_loop_failed' => true,
            'options'            => [
                $this->option([
                    'option_id'      => 'frontier',
                    'quality'        => 0.85,
                    'cost'           => 5.0,
                    'expected_lift'  => 0.05,
                    'risk_reduction' => 0.20,
                ]),
            ],
        ]);

        $this->assertContains('repair_loop_failure', $r['escalation_triggers']);
    }

    public function test_ac3_no_escalation_when_no_failure_flags_even_with_high_lift(): void
    {
        // No failure flags → no escalation regardless of lift
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'frontier', 'quality' => 0.95, 'expected_lift' => 0.30]),
        ]]);

        $this->assertSame([], $r['escalation_triggers']);
    }

    // ── AC4: deterministic, no provider names as quality proxy ────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $input = ['options' => [
            $this->option(['option_id' => 'A', 'quality' => 0.80, 'cost' => 1.0]),
            $this->option(['option_id' => 'B', 'quality' => 0.85, 'cost' => 3.0]),
        ]];

        $this->assertSame(
            json_encode($this->compute($input), JSON_UNESCAPED_SLASHES),
            json_encode($this->compute($input), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_option_ids_are_generic_not_provider_names(): void
    {
        // Prove the service works with arbitrary non-provider-name identifiers.
        $r = $this->compute(['options' => [
            $this->option(['option_id' => 'tier_a', 'quality' => 0.75, 'cost' => 1.0]),
            $this->option(['option_id' => 'tier_b', 'quality' => 0.90, 'cost' => 4.0]),
        ]]);

        // Both are on Pareto front (different quality/cost tradeoff)
        $paretoIds = array_column($r['pareto_options'], 'option_id');
        $this->assertContains('tier_a', $paretoIds);
        $this->assertContains('tier_b', $paretoIds);
        // Recommended is the one with better quality/cost ratio
        $this->assertSame('tier_a', $r['recommended_option']);
    }
}
