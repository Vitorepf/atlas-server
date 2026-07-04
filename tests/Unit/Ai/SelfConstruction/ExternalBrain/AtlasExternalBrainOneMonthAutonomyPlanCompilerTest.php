<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOneMonthAutonomyPlanCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOneMonthAutonomyPlanCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainOneMonthAutonomyPlanCompiler
    {
        return new AtlasExternalBrainOneMonthAutonomyPlanCompiler;
    }

    public function test_empty_facts_yield_insufficient_input(): void
    {
        $plan = $this->compiler()->compile([]);

        $this->assertSame(AtlasExternalBrainOneMonthAutonomyPlanCompiler::STATUS_INSUFFICIENT_INPUT, $plan['status']);
        $this->assertSame([], $plan['waves']);
        $this->assertContains('no_autonomy_plan_input_signals', $plan['blockers']);
    }

    public function test_low_score_capability_becomes_build_item(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [
                ['capability_id' => 'weak_organ', 'score' => 40.0],
                ['capability_id' => 'strong_organ', 'score' => 95.0],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOneMonthAutonomyPlanCompiler::STATUS_READY, $plan['status']);
        $this->assertSame(1, $plan['category_allocation'][AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD]);

        $allItems = array_merge(...array_column($plan['waves'], 'items'));
        $targets = array_column($allItems, 'target');
        $this->assertContains('weak_organ', $targets);
        $this->assertNotContains('strong_organ', $targets);
    }

    public function test_high_give_back_rate_family_becomes_simplify_item(): void
    {
        $plan = $this->compiler()->compile([
            'give_back_rates' => [
                ['family' => 'brain_forbidden', 'rate' => 0.8],
                ['family' => 'clean_family', 'rate' => 0.05],
            ],
        ]);

        $this->assertSame(1, $plan['category_allocation'][AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_SIMPLIFY]);
        $allItems = array_merge(...array_column($plan['waves'], 'items'));
        $targets = array_column($allItems, 'target');
        $this->assertContains('brain_forbidden', $targets);
        $this->assertNotContains('clean_family', $targets);
    }

    public function test_high_simplification_debt_area_becomes_simplify_item(): void
    {
        $plan = $this->compiler()->compile([
            'simplification_debt' => [
                ['area' => 'legacy_router', 'debt_score' => 90.0],
                ['area' => 'tidy_module', 'debt_score' => 5.0],
            ],
        ]);

        $allItems = array_merge(...array_column($plan['waves'], 'items'));
        $targets = array_column($allItems, 'target');
        $this->assertContains('legacy_router', $targets);
        $this->assertNotContains('tidy_module', $targets);
    }

    public function test_research_gaps_sorted_by_priority_descending(): void
    {
        $plan = $this->compiler()->compile([
            'research_gaps' => [
                ['topic' => 'low_prio', 'priority' => 1],
                ['topic' => 'high_prio', 'priority' => 9],
            ],
        ]);

        $allItems = array_merge(...array_column($plan['waves'], 'items'));
        $researchItems = array_values(array_filter($allItems, static fn (array $i): bool => $i['category'] === AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_RESEARCH));
        $this->assertSame('high_prio', $researchItems[0]['target']);
        $this->assertSame('low_prio', $researchItems[1]['target']);
    }

    public function test_waves_are_bounded_by_worker_capacity_and_give_back_dampening(): void
    {
        $capabilityScores = [];
        for ($i = 0; $i < 20; $i++) {
            $capabilityScores[] = ['capability_id' => 'cap_'.$i, 'score' => 10.0];
        }

        $plan = $this->compiler()->compile([
            'capability_scores' => $capabilityScores,
            'worker_capacity' => ['tasks_per_day' => 1],
            'queue_yield' => ['give_back_rate' => 0.5],
        ]);

        // effective_per_day = round(1 * 0.5) = max(1, 1) = 1 -> per_wave_capacity = 1 * 7 = 7
        foreach ($plan['waves'] as $wave) {
            $this->assertLessThanOrEqual(7, count($wave['items']));
        }
    }

    public function test_waves_have_sequential_day_ranges(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'x', 'score' => 10.0]],
        ]);

        $this->assertSame(1, $plan['waves'][0]['wave']);
        $this->assertSame(1, $plan['waves'][0]['day_start']);
        $this->assertSame(7, $plan['waves'][0]['day_end']);
    }

    public function test_items_interleave_across_categories_when_multiple_present(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'build_a', 'score' => 10.0]],
            'give_back_rates' => [['family' => 'simplify_a', 'rate' => 0.9]],
            'research_gaps' => [['topic' => 'research_a', 'priority' => 5]],
        ]);

        $allItems = array_merge(...array_column($plan['waves'], 'items'));
        $categories = array_column($allItems, 'category');
        $this->assertSame([
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD,
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_SIMPLIFY,
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_RESEARCH,
        ], $categories);
    }

    public function test_schema_version_present(): void
    {
        $plan = $this->compiler()->compile(['capability_scores' => [['capability_id' => 'x', 'score' => 1.0]]]);

        $this->assertSame(AtlasExternalBrainOneMonthAutonomyPlanCompiler::SCHEMA, $plan['schema_version']);
    }

    public function test_identical_input_yields_identical_output(): void
    {
        $facts = [
            'capability_scores' => [['capability_id' => 'x', 'score' => 10.0], ['capability_id' => 'y', 'score' => 20.0]],
            'give_back_rates' => [['family' => 'f', 'rate' => 0.5]],
            'research_gaps' => [['topic' => 't', 'priority' => 2]],
        ];

        $this->assertSame($this->compiler()->compile($facts), $this->compiler()->compile($facts));
    }

    public function test_no_quality_score_field_in_output(): void
    {
        $plan = $this->compiler()->compile(['capability_scores' => [['capability_id' => 'x', 'score' => 1.0]]]);

        $json = (string) json_encode($plan);
        foreach (['"score":', '"rank":', '"rating":', '"quality":'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    // ── AC2: steady-state priorities and critical-path categories ─────────────

    public function test_steady_state_priorities_are_internal_execution_learning_then_proof_loops(): void
    {
        $plan = $this->compiler()->compile(['capability_scores' => [['capability_id' => 'x', 'score' => 1.0]]]);

        $this->assertSame([
            'internal_atlas_execution',
            'internal_atlas_learning',
            'native_proof_loops',
        ], $plan['steady_state_priorities']);
    }

    public function test_critical_path_categories_prioritize_build_before_simplify_before_research(): void
    {
        $plan = $this->compiler()->compile(['capability_scores' => [['capability_id' => 'x', 'score' => 1.0]]]);

        $this->assertSame([
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD,
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_SIMPLIFY,
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_RESEARCH,
        ], $plan['critical_path_categories']);
    }

    public function test_insufficient_input_still_reports_steady_state_priorities(): void
    {
        $plan = $this->compiler()->compile([]);

        $this->assertNotEmpty($plan['steady_state_priorities']);
    }

    // ── AC1: milestone, proof_gates, risk_burndown per wave ────────────────────

    public function test_wave_has_milestone_proof_gates_and_risk_burndown(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'weak_organ', 'score' => 10.0]],
        ]);
        $wave = $plan['waves'][0];

        $this->assertNotEmpty($wave['milestone']);
        $this->assertContains('tests_or_gates_result', $wave['proof_gates']);
        $this->assertContains('capability_lift_evidence', $wave['proof_gates']);
        $this->assertArrayHasKey('remaining_items', $wave['risk_burndown']);
        $this->assertArrayHasKey('risk_level', $wave['risk_burndown']);
    }

    public function test_simplify_only_wave_requires_no_behavior_change_proof_gate(): void
    {
        $plan = $this->compiler()->compile([
            'give_back_rates' => [['family' => 'brain_forbidden', 'rate' => 0.8]],
        ]);
        $wave = $plan['waves'][0];

        $this->assertContains('no_behavior_change_proof', $wave['proof_gates']);
    }

    public function test_research_only_wave_requires_research_findings_documented_gate(): void
    {
        $plan = $this->compiler()->compile([
            'research_gaps' => [['topic' => 'topic_a', 'priority' => 3]],
        ]);
        $wave = $plan['waves'][0];

        $this->assertContains('research_findings_documented', $wave['proof_gates']);
    }

    public function test_last_wave_has_zero_remaining_items_and_no_risk(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'weak_organ', 'score' => 10.0]],
        ]);
        $lastWave = end($plan['waves']);

        $this->assertSame(0, $lastWave['risk_burndown']['remaining_items']);
        $this->assertSame('none', $lastWave['risk_burndown']['risk_level']);
    }

    public function test_risk_burndown_declines_across_waves_when_backlog_is_large(): void
    {
        $capabilityScores = [];
        for ($i = 0; $i < 40; $i++) {
            $capabilityScores[] = ['capability_id' => 'cap_'.$i, 'score' => 10.0];
        }
        $plan = $this->compiler()->compile([
            'capability_scores' => $capabilityScores,
            'worker_capacity' => ['tasks_per_day' => 1],
        ]);

        $remaining = array_column(array_column($plan['waves'], 'risk_burndown'), 'remaining_items');
        for ($i = 1; $i < count($remaining); $i++) {
            $this->assertLessThanOrEqual($remaining[$i - 1], $remaining[$i]);
        }
    }

    // ── AC3: stop/go checks per wave ───────────────────────────────────────────

    public function test_wave_with_build_item_and_healthy_queue_is_go(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'weak_organ', 'score' => 10.0]],
            'queue_yield' => ['give_back_rate' => 0.1],
        ]);

        $this->assertSame('go', $plan['waves'][0]['stop_go']['decision']);
        $this->assertSame([], $plan['waves'][0]['stop_go']['reasons']);
    }

    public function test_wave_with_high_give_back_rate_is_hold(): void
    {
        $plan = $this->compiler()->compile([
            'capability_scores' => [['capability_id' => 'weak_organ', 'score' => 10.0]],
            'queue_yield' => ['give_back_rate' => 0.6],
        ]);

        $this->assertSame('hold', $plan['waves'][0]['stop_go']['decision']);
        $this->assertNotEmpty($plan['waves'][0]['stop_go']['reasons']);
    }

    public function test_wave_without_capability_lift_item_is_hold(): void
    {
        $plan = $this->compiler()->compile([
            'give_back_rates' => [['family' => 'brain_forbidden', 'rate' => 0.8]],
            'queue_yield' => ['give_back_rate' => 0.0],
        ]);

        $this->assertSame('hold', $plan['waves'][0]['stop_go']['decision']);
        $this->assertContains('no_capability_lift_item_in_wave', $plan['waves'][0]['stop_go']['reasons']);
    }

    // ── capacity_assumptions ──

    public function test_capacity_assumptions_present_in_ready_plan(): void
    {
        $plan = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([
            'capability_scores' => [['capability_id' => 'auth', 'score' => 0.3]],
            'worker_capacity' => ['tasks_per_day' => 8],
            'queue_yield' => ['give_back_rate' => 0.2],
        ]);
        $this->assertArrayHasKey('capacity_assumptions', $plan);
        $this->assertSame(8, $plan['capacity_assumptions']['tasks_per_day']);
        $this->assertSame(0.2, $plan['capacity_assumptions']['give_back_rate']);
        $this->assertSame(6, $plan['capacity_assumptions']['effective_tasks_per_day']);
    }

    public function test_capacity_assumptions_present_in_insufficient_input(): void
    {
        $plan = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([]);
        $this->assertArrayHasKey('capacity_assumptions', $plan);
        $this->assertSame(5, $plan['capacity_assumptions']['tasks_per_day']);
        $this->assertSame(0.0, $plan['capacity_assumptions']['give_back_rate']);
    }
}
