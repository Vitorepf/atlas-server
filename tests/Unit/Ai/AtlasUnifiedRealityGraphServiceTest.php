<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — AURG Phase 1 tests.
 */
class AtlasUnifiedRealityGraphServiceTest extends TestCase
{
    public function test_build_snapshot_returns_canonical_envelope(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;

        $snapshot = $s->buildSnapshot(
            [
                ['id' => 'ws-1', 'kind' => 'workspace', 'label' => 'atlas-server'],
                ['id' => 'm-1', 'kind' => 'mission', 'label' => 'do thing'],
            ],
            [
                ['from_id' => 'm-1', 'to_id' => 'ws-1', 'kind' => 'belongs_to'],
            ]
        );

        $this->assertSame('atlas.aurg.reality_snapshot.v1', $snapshot['schema_version']);
        $this->assertSame(2, $snapshot['stats']['node_count']);
        $this->assertSame(1, $snapshot['stats']['edge_count']);
        $this->assertSame(1, $snapshot['stats']['kinds']['workspace']);
        $this->assertSame(1, $snapshot['stats']['kinds']['mission']);
        $this->assertSame(['belongs_to' => 1], $snapshot['stats']['edge_kinds']);
        $this->assertNotEmpty($snapshot['snapshot_hash']);
        $this->assertStringStartsWith('aurg_', $snapshot['snapshot_id']);
    }

    public function test_invalid_node_kind_is_dropped(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $snapshot = $s->buildSnapshot([
            ['id' => 'x-1', 'kind' => 'unknown_kind'],
            ['id' => 'm-1', 'kind' => 'mission'],
        ], []);

        $this->assertSame(1, $snapshot['stats']['node_count']);
    }

    public function test_invalid_edge_kind_is_dropped(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $snapshot = $s->buildSnapshot(
            [
                ['id' => 'a', 'kind' => 'doc'],
                ['id' => 'b', 'kind' => 'doc'],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'random_relation'],
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'references'],
            ]
        );

        $this->assertSame(1, $snapshot['stats']['edge_count']);
    }

    public function test_edge_referencing_missing_node_is_dropped(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $snapshot = $s->buildSnapshot(
            [['id' => 'real', 'kind' => 'doc']],
            [['from_id' => 'real', 'to_id' => 'ghost', 'kind' => 'references']]
        );

        $this->assertSame(0, $snapshot['stats']['edge_count']);
    }

    public function test_self_loop_is_dropped(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $snapshot = $s->buildSnapshot(
            [['id' => 'x', 'kind' => 'doc']],
            [['from_id' => 'x', 'to_id' => 'x', 'kind' => 'references']]
        );
        $this->assertSame(0, $snapshot['stats']['edge_count']);
    }

    public function test_snapshot_hash_is_deterministic(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $nodes = [
            ['id' => 'b', 'kind' => 'doc'],
            ['id' => 'a', 'kind' => 'doc'],
        ];
        $edges = [];

        $snap1 = $s->buildSnapshot($nodes, $edges);
        $snap2 = $s->buildSnapshot(array_reverse($nodes), $edges);

        $this->assertSame($snap1['snapshot_hash'], $snap2['snapshot_hash'], 'snapshot_hash deve ser deterministico independente da ordem de entrada.');
    }

    public function test_snapshot_hash_changes_when_content_changes(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $h1 = $s->buildSnapshot([['id' => 'a', 'kind' => 'doc']], [])['snapshot_hash'];
        $h2 = $s->buildSnapshot([['id' => 'b', 'kind' => 'doc']], [])['snapshot_hash'];

        $this->assertNotSame($h1, $h2);
    }

    public function test_provider_safe_stats(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;
        $snap = $s->buildSnapshot([
            ['id' => 'safe-1', 'kind' => 'doc', 'provider_safe' => true],
            ['id' => 'safe-2', 'kind' => 'doc', 'provider_safe' => true],
            ['id' => 'block-1', 'kind' => 'evidence', 'provider_safe' => false],
        ], []);

        $this->assertSame(2, $snap['stats']['provider_safe_nodes']);
        $this->assertSame(1, $snap['stats']['blocked_nodes']);
    }

    public function test_traverse_explores_bounded_depth(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;

        // ws-1 -> m-1 -> wo-1 -> evidence-1
        $snap = $s->buildSnapshot(
            [
                ['id' => 'ws-1', 'kind' => 'workspace'],
                ['id' => 'm-1', 'kind' => 'mission'],
                ['id' => 'wo-1', 'kind' => 'work_order'],
                ['id' => 'ev-1', 'kind' => 'evidence'],
            ],
            [
                ['from_id' => 'm-1', 'to_id' => 'ws-1', 'kind' => 'belongs_to'],
                ['from_id' => 'wo-1', 'to_id' => 'm-1', 'kind' => 'belongs_to'],
                ['from_id' => 'ev-1', 'to_id' => 'wo-1', 'kind' => 'proves'],
            ]
        );

        $traverse = $s->traverse($snap, 'ev-1', maxDepth: 2);

        $this->assertContains('ev-1', $traverse['visited']);
        $this->assertContains('wo-1', $traverse['visited']);
        $this->assertContains('m-1', $traverse['visited']);
        $this->assertNotContains('ws-1', $traverse['visited'], 'depth=2 nao alcanca ws-1 (precisa depth=3).');
    }

    public function test_traverse_truncates_at_max_nodes(): void
    {
        $s = new AtlasRealityGraphSnapshotBuilderService;

        $nodes = [];
        $edges = [];
        for ($i = 0; $i < 10; $i++) {
            $nodes[] = ['id' => "n-$i", 'kind' => 'doc'];
            if ($i > 0) {
                $edges[] = ['from_id' => "n-$i", 'to_id' => 'n-'.($i - 1), 'kind' => 'depends_on'];
            }
        }

        $snap = $s->buildSnapshot($nodes, $edges);
        $traverse = $s->traverse($snap, 'n-9', maxDepth: 100, maxNodes: 3);

        $this->assertTrue($traverse['truncated']);
        $this->assertCount(3, $traverse['visited']);
    }

    public function test_static_validators(): void
    {
        $this->assertTrue(AtlasRealityGraphSnapshotBuilderService::isValidNodeKind('workspace'));
        $this->assertFalse(AtlasRealityGraphSnapshotBuilderService::isValidNodeKind('elephant'));
        $this->assertTrue(AtlasRealityGraphSnapshotBuilderService::isValidEdgeKind('proves'));
        $this->assertFalse(AtlasRealityGraphSnapshotBuilderService::isValidEdgeKind('points_at'));
    }
}
