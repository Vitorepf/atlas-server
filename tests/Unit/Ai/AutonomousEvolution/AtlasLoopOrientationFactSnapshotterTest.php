<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrientationFactSnapshotter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * Proves the ORIENTAR fact snapshotter: byte-stable across two calls (deterministic, no time()), flag-OFF
 * no-op (null), and fail-closed refusal on an empty comprehension model — pure facts, no synthesized score.
 */
final class AtlasLoopOrientationFactSnapshotterTest extends TestCase
{
    private function snapshotter(): AtlasLoopOrientationFactSnapshotter
    {
        return new AtlasLoopOrientationFactSnapshotter(base_path());
    }

    private function model(array $inventory, array $orphans = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: $orphans,
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'snap-orient',
        );
    }

    /** @return array<string,mixed> */
    private function inventoryNode(string $relPath, string $fqcn): array
    {
        return ['rel_path' => $relPath, 'fqcn' => $fqcn, 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null];
    }

    private function populatedModel(): AtlasLoopScopeComprehensionModel
    {
        return $this->model(
            [
                // a real file so its mtime is a stable "as-of" across two calls
                $this->inventoryNode('app/Services/Ai/AutonomousEvolution/AtlasLoopOrientationFactSnapshotter.php', 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopOrientationFactSnapshotter'),
            ],
            ['App\\Services\\Ai\\AutonomousEvolution\\SomeOrphan'],
        );
    }

    public function test_byte_stable_across_two_calls(): void
    {
        config(['atlas.loop.orientation_snapshotter_enabled' => true]);

        $run1 = $this->snapshotter()->snapshot($this->populatedModel());
        $run2 = $this->snapshotter()->snapshot($this->populatedModel());

        $this->assertNotNull($run1);
        $this->assertSame('atlas.loop.orientation.v1', $run1['schema_version']);
        $this->assertTrue($run1['fact_snapshot']);
        $this->assertSame(1, $run1['inventory_size']);
        $this->assertSame(1, $run1['orphan_count']);
        $this->assertArrayNotHasKey('orientation_score', $run1, 'no synthesized scalar (anti-Goodhart)');
        $this->assertSame(json_encode($run1), json_encode($run2), 'deterministic, byte-identical across calls');
    }

    public function test_flag_off_is_null_no_op(): void
    {
        config(['atlas.loop.orientation_snapshotter_enabled' => false]);

        $this->assertNull($this->snapshotter()->snapshot($this->populatedModel()));
    }

    public function test_empty_comprehension_model_fails_closed_with_refusal(): void
    {
        config(['atlas.loop.orientation_snapshotter_enabled' => true]);

        $result = $this->snapshotter()->snapshot($this->model([]));

        $this->assertIsArray($result, 'a refusal envelope, never an exception');
        $this->assertFalse($result['fact_snapshot']);
        $this->assertSame('empty_comprehension_model', $result['reason']);
    }
}
