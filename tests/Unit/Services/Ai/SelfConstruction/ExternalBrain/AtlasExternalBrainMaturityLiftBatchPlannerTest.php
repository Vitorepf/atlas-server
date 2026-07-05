<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityLiftBatchPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMaturityLiftBatchPlannerTest extends TestCase
{
    private AtlasExternalBrainMaturityLiftBatchPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainMaturityLiftBatchPlanner;
    }

    public function test_bottleneck_gaps_are_ordered(): void
    {
        $result = $this->planner->plan([
            ['id' => 'low-gap', 'maturity_gap' => 0.2, 'is_bottleneck' => true],
            ['id' => 'high-gap', 'maturity_gap' => 0.8, 'is_bottleneck' => true],
            ['id' => 'mid-gap', 'maturity_gap' => 0.5, 'is_bottleneck' => true],
        ]);

        $this->assertSame(3, $result['batch_count']);
        $this->assertSame('high-gap', $result['batch'][0]['id']);
        $this->assertSame('mid-gap', $result['batch'][1]['id']);
        $this->assertSame('low-gap', $result['batch'][2]['id']);
    }

    public function test_dependent_tasks_are_sequenced(): void
    {
        $result = $this->planner->plan([
            ['id' => 'child', 'maturity_gap' => 0.8, 'is_bottleneck' => true, 'depends_on' => ['parent']],
            ['id' => 'parent', 'maturity_gap' => 0.5, 'is_bottleneck' => true],
        ]);

        $this->assertSame(2, $result['batch_count']);
        // Parent should come before child
        $ids = array_column($result['batch'], 'id');
        $this->assertTrue(array_search('parent', $ids) < array_search('child', $ids));
    }

    public function test_unrelated_padding_candidates_rejected(): void
    {
        $result = $this->planner->plan([
            ['id' => 'bottleneck', 'maturity_gap' => 0.8, 'is_bottleneck' => true],
            ['id' => 'padding', 'maturity_gap' => 0.0, 'is_bottleneck' => false, 'impact_score' => 0.1],
        ]);

        $this->assertSame(1, $result['batch_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame('padding', $result['rejected_padding'][0]['id']);
    }

    public function test_empty_candidates_returns_empty_batch(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(0, $result['batch_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(AtlasExternalBrainMaturityLiftBatchPlanner::SCHEMA, $result['schema']);
    }
}
