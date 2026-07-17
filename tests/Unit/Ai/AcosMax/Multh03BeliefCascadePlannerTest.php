<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\BeliefCascadeReverificationPlanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multh03BeliefCascadePlannerTest extends TestCase
{
    #[Test]
    public function descendants_are_marked_for_reverification(): void
    {
        $out = BeliefCascadeReverificationPlanner::plan('A', ['A' => ['B'], 'B' => ['C']], 3);

        $this->assertSame(['B', 'C'], array_column($out['marked'], 'id'));
        $this->assertSame('A', $out['marked'][0]['cascade_origin']);
    }

    #[Test]
    public function depth_cap_stops_propagation(): void
    {
        $out = BeliefCascadeReverificationPlanner::plan('A', ['A' => ['B'], 'B' => ['C']], 1);

        $this->assertSame(['B'], array_column($out['marked'], 'id'));
        $this->assertTrue($out['caps_hit']['depth']);
    }

    #[Test]
    public function cycles_terminate(): void
    {
        $out = BeliefCascadeReverificationPlanner::plan('A', ['A' => ['B'], 'B' => ['A']], 5);

        $this->assertSame(['B'], array_column($out['marked'], 'id'));
        $this->assertFalse($out['source']['deletes_descendants']);
    }

    #[Test]
    public function trims_origin_and_dedupes_graph_edges(): void
    {
        $out = BeliefCascadeReverificationPlanner::plan(
            '  A  ',
            ['  A  ' => [' B ', 'B', ''], 'B' => ['  C  ']],
            3,
        );

        $this->assertSame(['B', 'C'], array_column($out['marked'], 'id'));
        $this->assertSame('A', $out['marked'][0]['cascade_origin']);
    }
}
