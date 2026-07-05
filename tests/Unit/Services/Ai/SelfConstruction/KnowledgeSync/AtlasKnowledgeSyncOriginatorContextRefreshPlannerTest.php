<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncOriginatorContextRefreshPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasKnowledgeSyncOriginatorContextRefreshPlannerTest extends TestCase
{
    private AtlasKnowledgeSyncOriginatorContextRefreshPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasKnowledgeSyncOriginatorContextRefreshPlanner;
    }

    public function test_missing_docs_sync_produces_one_refresh_action(): void
    {
        $result = $this->planner->plan(['docs_synced' => false]);

        $this->assertSame(1, $result['action_count']);
        $this->assertSame('sync_docs', $result['actions'][0]['action']);
    }

    public function test_stale_code_index_produces_one_refresh_action(): void
    {
        $result = $this->planner->plan(['code_index_fresh' => false]);

        $this->assertSame(1, $result['action_count']);
        $this->assertSame('index_code', $result['actions'][0]['action']);
    }

    public function test_stale_task_outcomes_produce_one_refresh_action(): void
    {
        $result = $this->planner->plan(['task_outcomes_fresh' => false]);

        $this->assertSame(1, $result['action_count']);
        $this->assertSame('sync_task_outcomes', $result['actions'][0]['action']);
    }

    public function test_all_fresh_produces_no_actions(): void
    {
        $result = $this->planner->plan([]);

        $this->assertSame(0, $result['action_count']);
        $this->assertTrue($result['ready']);
    }

    public function test_schema_present(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(AtlasKnowledgeSyncOriginatorContextRefreshPlanner::SCHEMA, $result['schema']);
    }
}
