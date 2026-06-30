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
}
