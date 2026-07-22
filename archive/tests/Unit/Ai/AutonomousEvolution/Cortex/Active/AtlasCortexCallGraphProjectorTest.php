<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCallGraphProjector;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasCortexCallGraphProjectorTest extends TestCase
{
    /**
     * A known caller chain — 4 deep — going BACKWARDS from L0:
     *   L0 (entry) <— L1 <— L2 <— L3 <— L4
     */
    private function fixtureIndex(): array
    {
        return [
            'callers' => [
                'L0' => ['L1'],
                'L1' => ['L2'],
                'L2' => ['L3'],
                'L3' => ['L4'],
                'L4' => [],
            ],
            'symbols' => [
                'L0' => ['file_line' => 'app/L0.php:10', 'role' => 'method'],
                'L1' => ['file_line' => 'app/L1.php:20', 'role' => 'method'],
                'L2' => ['file_line' => 'app/L2.php:30', 'role' => 'method'],
                'L3' => ['file_line' => 'app/L3.php:40', 'role' => 'method'],
                'L4' => ['file_line' => 'app/L4.php:50', 'role' => 'method'],
            ],
        ];
    }

    public function test_depth_3_returns_nodes_for_depths_1_to_3_and_truncates_at_depth_4_frontier(): void
    {
        $projector = new AtlasCortexCallGraphProjector(8);
        $record = $projector->project('L0', 3, true, $this->fixtureIndex());

        $this->assertSame('atlas.cortex.active.call_graph.v1', $record['schema']);
        $this->assertSame('L0', $record['entry']);
        $this->assertSame('callers', $record['direction']);
        $this->assertSame(3, $record['depth']);

        $depths = array_combine(
            array_map(static fn (array $n): string => $n['symbol_id'], $record['nodes']),
            array_map(static fn (array $n): int => $n['depth'], $record['nodes']),
        );
        $this->assertSame(['L0' => 0, 'L1' => 1, 'L2' => 2, 'L3' => 3], $depths);

        $this->assertSame(
            [
                ['from' => 'L0', 'to' => 'L1', 'edge_kind' => 'callers'],
                ['from' => 'L1', 'to' => 'L2', 'edge_kind' => 'callers'],
                ['from' => 'L2', 'to' => 'L3', 'edge_kind' => 'callers'],
            ],
            $record['edges'],
        );

        $this->assertCount(1, $record['truncated']);
        $this->assertSame('L4', $record['truncated'][0]['at_symbol']);
        $this->assertSame(4, $record['truncated'][0]['depth_reached']);
        $this->assertSame(AtlasCortexCallGraphProjector::DEPTH_TRUNCATED, $record['truncated'][0]['sentinel']);
    }

    public function test_projection_is_byte_identical_across_two_runs(): void
    {
        $projector = new AtlasCortexCallGraphProjector(8);
        $a = json_encode($projector->project('L0', 3, true, $this->fixtureIndex()), JSON_THROW_ON_ERROR);
        $b = json_encode($projector->project('L0', 3, true, $this->fixtureIndex()), JSON_THROW_ON_ERROR);

        $this->assertSame($a, $b);
    }

    public function test_hard_depth_cap_clips_a_request_above_the_cap(): void
    {
        $projector = new AtlasCortexCallGraphProjector(2);
        $record = $projector->project('L0', 99, true, $this->fixtureIndex());

        $this->assertSame(2, $record['depth'], 'effective depth is clipped at the cap');
        $reached = array_map(static fn (array $n): string => $n['symbol_id'], $record['nodes']);
        sort($reached);
        $this->assertSame(['L0', 'L1', 'L2'], $reached);
        $this->assertSame('L3', $record['truncated'][0]['at_symbol']);
    }

    public function test_negative_depth_is_rejected_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasCortexCallGraphProjector(8))->project('L0', -1, true, $this->fixtureIndex());
    }

    public function test_depth_zero_returns_only_the_entry_node(): void
    {
        $record = (new AtlasCortexCallGraphProjector(8))->project('L0', 0, true, $this->fixtureIndex());

        $this->assertSame([['symbol_id' => 'L0', 'file_line' => 'app/L0.php:10', 'role' => 'method', 'depth' => 0]], $record['nodes']);
        $this->assertSame([], $record['edges']);
        $this->assertSame([], $record['truncated']);
    }
}
