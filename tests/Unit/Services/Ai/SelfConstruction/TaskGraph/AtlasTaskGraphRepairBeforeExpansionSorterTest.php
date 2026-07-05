<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphRepairBeforeExpansionSorter;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphRepairBeforeExpansionSorterTest extends TestCase
{
    private AtlasTaskGraphRepairBeforeExpansionSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new AtlasTaskGraphRepairBeforeExpansionSorter;
    }

    private function node(string $id, string $kind): array
    {
        return ['node_id' => $id, 'kind' => $kind];
    }

    // ── AC: degraded health moves repair nodes first ──

    public function test_degraded_health_moves_repair_first(): void
    {
        $result = $this->sorter->sort([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'healthy' => false,
                'malformed_count' => 2,
                'claimable_depth' => 5,
                'active_workers' => 2,
            ],
            'nodes' => [
                $this->node('exp-1', 'expansion'),
                $this->node('rep-1', 'repair'),
                $this->node('mal-1', 'malformed_prevention'),
            ],
        ]);

        $ids = array_column($result['sorted_nodes'], 'node_id');
        $this->assertContains('rep-1', array_slice($ids, 0, 2));
        $this->assertContains('mal-1', array_slice($ids, 0, 2));
        $this->assertSame('exp-1', $ids[2]);
        $this->assertTrue($result['repair_first']);
        $this->assertFalse($result['allow_expansion']);
    }

    // ── AC: healthy high-drain queues allow expansion nodes ──

    public function test_healthy_high_drain_allows_expansion(): void
    {
        $result = $this->sorter->sort([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'healthy' => true,
                'malformed_count' => 0,
                'claimable_depth' => 2,
                'active_workers' => 2,
            ],
            'nodes' => [
                $this->node('exp-1', 'expansion'),
                $this->node('rep-1', 'repair'),
                $this->node('learn-1', 'learning'),
            ],
        ]);

        $ids = array_column($result['sorted_nodes'], 'node_id');
        $this->assertSame('exp-1', $ids[0]);
        $this->assertTrue($result['allow_expansion']);
    }

    public function test_healthy_low_drain_still_prioritizes_repair_over_expansion(): void
    {
        $result = $this->sorter->sort([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'healthy' => true,
                'malformed_count' => 0,
                'claimable_depth' => 10,
                'active_workers' => 2,
            ],
            'nodes' => [
                $this->node('exp-1', 'expansion'),
                $this->node('rep-1', 'repair'),
            ],
        ]);

        $ids = array_column($result['sorted_nodes'], 'node_id');
        $this->assertContains('rep-1', array_slice($ids, 0, 2));
        $this->assertContains('exp-1', $ids);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->sorter->sort([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [],
            'nodes' => [],
        ]);

        $this->assertSame(AtlasTaskGraphRepairBeforeExpansionSorter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('sorted_nodes', $result);
        $this->assertArrayHasKey('repair_first', $result);
        $this->assertArrayHasKey('allow_expansion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => [
                'healthy' => false,
                'malformed_count' => 1,
            ],
            'nodes' => [
                $this->node('exp-1', 'expansion'),
                $this->node('rep-1', 'repair'),
            ],
        ];

        $a = $this->sorter->sort($input);
        $b = $this->sorter->sort($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
