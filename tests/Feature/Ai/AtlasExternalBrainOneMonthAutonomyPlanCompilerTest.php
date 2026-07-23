<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOneMonthAutonomyPlanCompiler;
use Tests\TestCase;

final class AtlasExternalBrainOneMonthAutonomyPlanCompilerTest extends TestCase
{
    public function test_empty_input_returns_insufficient_input_with_no_waves(): void
    {
        $result = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([]);

        $this->assertSame(AtlasExternalBrainOneMonthAutonomyPlanCompiler::STATUS_INSUFFICIENT_INPUT, $result['status']);
        $this->assertSame([], $result['waves']);
        $this->assertSame(['no_autonomy_plan_input_signals'], $result['blockers']);
    }

    public function test_low_capability_scores_become_build_targets(): void
    {
        $result = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([
            'capability_scores' => [
                ['capability_id' => 'weak-cap', 'score' => 20],
            ],
        ]);

        $this->assertSame(1, $result['category_allocation'][AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD]);
    }

    public function test_high_give_back_and_simplification_debt_become_simplify_targets(): void
    {
        $result = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([
            'give_back_rates' => [['family' => 'wiring', 'rate' => 0.5]],
            'simplification_debt' => [['area' => 'legacy', 'debt_score' => 80]],
        ]);

        $this->assertSame(2, $result['category_allocation'][AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_SIMPLIFY]);
    }

    public function test_research_gaps_become_research_targets(): void
    {
        $result = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([
            'research_gaps' => [['topic' => 'foo', 'priority' => 5]],
        ]);

        $this->assertSame(1, $result['category_allocation'][AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_RESEARCH]);
    }

    public function test_items_are_interleaved_and_bucketed_into_exactly_four_bounded_waves_dampened_by_give_back_rate(): void
    {
        $capabilityScores = [];
        for ($i = 0; $i < 20; $i++) {
            $capabilityScores[] = ['capability_id' => "cap-$i", 'score' => 10];
        }

        $result = (new AtlasExternalBrainOneMonthAutonomyPlanCompiler)->compile([
            'capability_scores' => $capabilityScores,
            'give_back_rates' => [['family' => 'wiring', 'rate' => 0.5]],
            'research_gaps' => [['topic' => 'topic-a', 'priority' => 5]],
            'worker_capacity' => ['tasks_per_day' => 1],
            'queue_yield' => ['give_back_rate' => 0.5],
        ]);

        $this->assertSame(AtlasExternalBrainOneMonthAutonomyPlanCompiler::STATUS_READY, $result['status']);
        $this->assertCount(4, $result['waves']);
        $this->assertLessThanOrEqual(7, count($result['waves'][0]['items']));

        $categoriesSeen = [];
        foreach ($result['waves'] as $wave) {
            foreach ($wave['items'] as $item) {
                $categoriesSeen[] = $item['category'];
            }
        }
        $this->assertContains(AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD, $categoriesSeen);
        $this->assertContains(AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_SIMPLIFY, $categoriesSeen);
        $this->assertContains(AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_RESEARCH, $categoriesSeen);

        // build item precedes simplify item precedes research item in interleaved order.
        $this->assertSame(
            AtlasExternalBrainOneMonthAutonomyPlanCompiler::CATEGORY_BUILD,
            $categoriesSeen[0],
        );
    }
}
