<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricCollisionAwareBatchPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricCollisionAwareBatchPlannerTest extends TestCase
{
    private AtlasTaskFabricCollisionAwareBatchPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasTaskFabricCollisionAwareBatchPlanner;
    }

    public function test_collision_summaries_remove_exact_targets(): void
    {
        $result = $this->planner->plan(
            [
                ['id' => 't1', 'allowed_files' => ['app/Foo.php', 'tests/FooTest.php']],
                ['id' => 't2', 'allowed_files' => ['app/Bar.php', 'tests/BarTest.php']],
            ],
            ['app/Foo.php']
        );

        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame('t2', $result['admitted'][0]['id']);
    }

    public function test_unrelated_candidates_preserved(): void
    {
        $result = $this->planner->plan(
            [
                ['id' => 't1', 'allowed_files' => ['app/A.php']],
                ['id' => 't2', 'allowed_files' => ['app/B.php']],
                ['id' => 't3', 'allowed_files' => ['app/C.php']],
            ],
            ['app/B.php']
        );

        $this->assertSame(2, $result['admitted_count']);
        $this->assertSame(1, $result['skipped_count']);
    }

    public function test_pivot_reasons_per_skipped_candidate(): void
    {
        $result = $this->planner->plan(
            [
                ['id' => 't1', 'allowed_files' => ['app/Foo.php']],
                ['id' => 't2', 'allowed_files' => ['app/Bar.php']],
            ],
            ['app/Foo.php', 'app/Bar.php']
        );

        $this->assertSame(0, $result['admitted_count']);
        $this->assertSame(2, $result['skipped_count']);
        foreach ($result['skipped'] as $skipped) {
            $this->assertSame('target_collision', $skipped['pivot_reason']);
            $this->assertNotEmpty($skipped['conflicting_targets']);
        }
    }

    public function test_empty_collisions_admits_all(): void
    {
        $result = $this->planner->plan(
            [['id' => 't1', 'allowed_files' => ['app/Foo.php']]]
        );

        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(0, $result['skipped_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(AtlasTaskFabricCollisionAwareBatchPlanner::SCHEMA, $result['schema']);
    }
}
