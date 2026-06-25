<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelStepScheduler;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\ParallelStepSchedulerDagCycleException;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\ParallelStepSchedulerUnknownDependencyException;
use Tests\TestCase;

class AtlasAaelParallelStepSchedulerTest extends TestCase
{
    public function test_disjoint_independents_grouped_first_then_dependent(): void
    {
        $verdict = (new AtlasAaelParallelStepScheduler)->plan([
            ['step_id' => 'A', 'allowed_files' => ['app/A.php'], 'depends_on' => []],
            ['step_id' => 'B', 'allowed_files' => ['app/B.php'], 'depends_on' => []],
            ['step_id' => 'C', 'allowed_files' => ['app/C.php'], 'depends_on' => ['A', 'B']],
        ]);

        self::assertCount(2, $verdict['groups']);
        self::assertSame(['A', 'B'], $verdict['groups'][0]);
        self::assertSame(['C'], $verdict['groups'][1]);
    }

    public function test_overlapping_write_sets_split_across_separate_waves(): void
    {
        $verdict = (new AtlasAaelParallelStepScheduler)->plan([
            ['step_id' => 'A', 'allowed_files' => ['app/Foo.php'], 'depends_on' => []],
            ['step_id' => 'B', 'allowed_files' => ['app/Foo.php'], 'depends_on' => []],
        ]);

        self::assertCount(2, $verdict['groups']);
        self::assertSame(['A'], $verdict['groups'][0]);
        self::assertSame(['B'], $verdict['groups'][1]);
        self::assertSame('write_set_overlap', $verdict['rationale']['B']['reason']);
        self::assertContains('app/Foo.php', $verdict['rationale']['B']['blocking_files']);
    }

    public function test_cycle_detection_throws_with_cycle_members(): void
    {
        $this->expectException(ParallelStepSchedulerDagCycleException::class);
        (new AtlasAaelParallelStepScheduler)->plan([
            ['step_id' => 'A', 'allowed_files' => ['x'], 'depends_on' => ['B']],
            ['step_id' => 'B', 'allowed_files' => ['y'], 'depends_on' => ['A']],
        ]);
    }

    public function test_empty_step_list_returns_byte_identical_noop(): void
    {
        $a = (new AtlasAaelParallelStepScheduler)->plan([]);
        $b = (new AtlasAaelParallelStepScheduler)->plan([]);

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame([], $a['groups']);
        self::assertSame([], $a['rationale']);
    }

    public function test_unknown_dependency_throws_named_exception(): void
    {
        $this->expectException(ParallelStepSchedulerUnknownDependencyException::class);
        (new AtlasAaelParallelStepScheduler)->plan([
            ['step_id' => 'A', 'allowed_files' => ['x'], 'depends_on' => ['NEVER']],
        ]);
    }
}
