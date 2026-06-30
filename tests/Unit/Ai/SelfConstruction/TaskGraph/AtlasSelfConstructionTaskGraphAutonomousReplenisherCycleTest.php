<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionTaskGraphAutonomousReplenisherCycleTest extends TestCase
{
    public function test_two_lane_cycle_is_excluded_with_cycle_blockers(): void
    {
        $replenisher = new AtlasSelfConstructionTaskGraphAutonomousReplenisher();

        $result = $replenisher->replenishFromGaps([
            ['lane' => 'alpha', 'depends_on_lanes' => ['beta']],
            ['lane' => 'beta', 'depends_on_lanes' => ['alpha']],
        ]);

        $this->assertFalse($result['no_op']);
        $this->assertSame([], $result['packet_drafts']);
        $this->assertSame(['alpha', 'beta'], $result['cycle_blockers']);
    }

    public function test_acyclic_fixture_still_returns_ordered_drafts_with_local_depends_on(): void
    {
        $replenisher = new AtlasSelfConstructionTaskGraphAutonomousReplenisher();

        $result = $replenisher->replenishFromGaps([
            ['lane' => 'beta', 'depends_on_lanes' => ['alpha']],
            ['lane' => 'alpha'],
        ]);

        $this->assertFalse($result['no_op']);
        $this->assertSame([], $result['cycle_blockers']);
        $this->assertCount(2, $result['packet_drafts']);

        $lanes = array_column($result['packet_drafts'], 'lane');
        $this->assertSame(['alpha', 'beta'], $lanes);

        $betaDraft = $result['packet_drafts'][1];
        $this->assertSame(['final-brain-gap-alpha'], $betaDraft['depends_on']);
    }

    public function test_partial_cycle_excludes_only_cyclic_lanes(): void
    {
        $replenisher = new AtlasSelfConstructionTaskGraphAutonomousReplenisher();

        $result = $replenisher->replenishFromGaps([
            ['lane' => 'gamma'],
            ['lane' => 'alpha', 'depends_on_lanes' => ['beta']],
            ['lane' => 'beta', 'depends_on_lanes' => ['alpha']],
        ]);

        $this->assertSame(['alpha', 'beta'], $result['cycle_blockers']);
        $lanes = array_column($result['packet_drafts'], 'lane');
        $this->assertSame(['gamma'], $lanes);
    }
}
