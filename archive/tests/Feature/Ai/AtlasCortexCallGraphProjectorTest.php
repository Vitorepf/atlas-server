<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCallGraphProjector;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasCortexCallGraphProjector.
 *
 * Verifies deterministic caller/callee projection, depth-capped traversal
 * with DEPTH_TRUNCATED sentinels, fail-closed on negative depth, and graceful
 * handling of empty/unknown symbols.
 */
final class AtlasCortexCallGraphProjectorTest extends TestCase
{
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
            'callees' => [
                'L0' => [],
                'L1' => ['L0'],
                'L2' => ['L1'],
                'L3' => ['L2'],
                'L4' => ['L3'],
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

    // ── Determinism: byte-identical sorted nodes and edges across runs ───────

    public function test_caller_projection_is_byte_identical_across_two_runs(): void
    {
        $projector = new AtlasCortexCallGraphProjector(8);

        $a = json_encode($projector->project('L0', 3, true, $this->fixtureIndex()), JSON_THROW_ON_ERROR);
        $b = json_encode($projector->project('L0', 3, true, $this->fixtureIndex()), JSON_THROW_ON_ERROR);

        $this->assertSame($a, $b);
    }

    public function test_callee_projection_is_byte_identical_across_two_runs(): void
    {
        $projector = new AtlasCortexCallGraphProjector(8);

        $a = json_encode($projector->project('L4', 3, false, $this->fixtureIndex()), JSON_THROW_ON_ERROR);
        $b = json_encode($projector->project('L4', 3, false, $this->fixtureIndex()), JSON_THROW_ON_ERROR);

        $this->assertSame($a, $b);
    }

    // ── Depth truncation emits DEPTH_TRUNCATED sentinels ─────────────────────

    public function test_depth_above_cap_clipped_and_emits_truncated_sentinel(): void
    {
        $record = (new AtlasCortexCallGraphProjector(2))->project('L0', 99, true, $this->fixtureIndex());

        $this->assertSame(2, $record['depth']);
        $this->assertCount(1, $record['truncated']);
        $this->assertSame('L3', $record['truncated'][0]['at_symbol']);
        $this->assertSame(AtlasCortexCallGraphProjector::DEPTH_TRUNCATED, $record['truncated'][0]['sentinel']);
    }

    // ── Negative depth fails closed ──────────────────────────────────────────

    public function test_negative_depth_throws_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasCortexCallGraphProjector(8))->project('L0', -1, true, $this->fixtureIndex());
    }

    // ── Empty or unknown symbols do not create malformed node records ───────

    public function test_unknown_entry_symbol_produces_single_node_no_edges(): void
    {
        $record = (new AtlasCortexCallGraphProjector(8))->project('UNKNOWN', 3, true, $this->fixtureIndex());

        $this->assertCount(1, $record['nodes']);
        $this->assertSame('UNKNOWN', $record['nodes'][0]['symbol_id']);
        $this->assertSame([], $record['edges']);
        $this->assertSame([], $record['truncated']);
    }

    public function test_empty_index_returns_only_entry_node(): void
    {
        $record = (new AtlasCortexCallGraphProjector(8))->project('L0', 5, true, []);

        $this->assertCount(1, $record['nodes']);
        $this->assertSame('L0', $record['nodes'][0]['symbol_id']);
        $this->assertSame('unknown', $record['nodes'][0]['role']);
    }
}
