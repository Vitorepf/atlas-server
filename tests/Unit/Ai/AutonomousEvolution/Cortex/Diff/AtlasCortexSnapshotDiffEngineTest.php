<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffEngine;
use Tests\TestCase;

final class AtlasCortexSnapshotDiffEngineTest extends TestCase
{
    private function inventoryItem(string $relPath, string $fqcn, array $overrides = []): array
    {
        return array_merge([
            'rel_path' => $relPath,
            'fqcn' => $fqcn,
            'public_methods' => ['handle'],
            'is_orphan' => false,
            'is_forbidden' => false,
            'clone_cluster_id' => null,
        ], $overrides);
    }

    private function model(array $inv, array $edges = [], array $orphans = [], array $clones = [], array $forbidden = [], array $docPurposes = [], array $docGaps = [], string $snapshotId = 's1'): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: array_values($inv),
            edges: $edges,
            orphans: array_values($orphans),
            cloneClusters: array_values($clones),
            forbidden: array_values($forbidden),
            docPurposes: $docPurposes,
            docStatedGaps: array_values($docGaps),
            snapshotId: $snapshotId,
        );
    }

    public function test_schema_constant_is_present(): void
    {
        $this->assertSame('atlas.cortex.snapshot_diff.v1', AtlasCortexSnapshotDiffEngine::SCHEMA);
    }

    public function test_identity_diff_returns_all_empty_lists(): void
    {
        $m = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo'),
            $this->inventoryItem('app/Bar.php', 'App\\Bar'),
        ]);
        $out = (new AtlasCortexSnapshotDiffEngine)->diff($m, $m);

        $this->assertSame(AtlasCortexSnapshotDiffEngine::SCHEMA, $out['schema_version']);
        $this->assertSame($m->snapshotId, $out['left']['snapshot_id']);
        $this->assertSame($m->snapshotId, $out['right']['snapshot_id']);
        $this->assertSame([], $out['inventory']['added']);
        $this->assertSame([], $out['inventory']['removed']);
        $this->assertSame([], $out['inventory']['shape_changed']);
        $this->assertSame([], $out['orphans']['appeared']);
        $this->assertSame([], $out['orphans']['resolved']);
        $this->assertSame([], $out['edges']['added']);
        $this->assertSame([], $out['edges']['removed']);
        $this->assertSame([], $out['clone_clusters']['appeared']);
        $this->assertSame([], $out['clone_clusters']['dissolved']);
        $this->assertSame([], $out['clone_clusters']['shape_changed']);
        $this->assertSame([], $out['forbidden']['added']);
        $this->assertSame([], $out['forbidden']['removed']);
        $this->assertSame([], $out['doc_stated_gaps']['opened']);
        $this->assertSame([], $out['doc_stated_gaps']['closed']);
        $this->assertSame([], $out['doc_purposes_prose']['changed']);
        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $out['doc_purposes_prose']['provenance']);
    }

    public function test_inventory_add_remove_orphan_resolve_and_shape_change(): void
    {
        $left = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => true]),
            $this->inventoryItem('app/Bar.php', 'App\\Bar'),
        ], [], ['App\\Foo']);
        $right = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => false]), // resolved
            $this->inventoryItem('app/Baz.php', 'App\\Baz'),                          // added
        ], [], []);

        $out = (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);

        $this->assertSame(['App\\Baz'], $out['inventory']['added']);
        $this->assertSame(['App\\Bar'], $out['inventory']['removed']);
        $this->assertSame(['App\\Foo'], $out['orphans']['resolved']);

        $shape = $out['inventory']['shape_changed'];
        $this->assertCount(1, $shape);
        $this->assertSame('App\\Foo', $shape[0]['fqcn']);
        $this->assertContains('is_orphan', $shape[0]['changed_fields']);

        // No scalar score anywhere — only named transitions.
        $this->assertArrayNotHasKey('score', $out);
        $this->assertArrayNotHasKey('total', $out);
    }

    public function test_doc_purposes_only_prose_change_does_not_leak_into_structural_categories(): void
    {
        $inv = [
            $this->inventoryItem('app/Foo.php', 'App\\Foo'),
        ];
        $left = $this->model($inv, docPurposes: ['App\\Foo' => 'Does X.']);
        $right = $this->model($inv, docPurposes: ['App\\Foo' => 'Does X (refined).']);

        $out = (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);

        // Every structural category must be empty.
        foreach (['inventory', 'orphans', 'edges', 'clone_clusters', 'forbidden', 'doc_stated_gaps'] as $cat) {
            foreach ($out[$cat] as $bucket) {
                $this->assertSame([], $bucket, "structural category {$cat} must be empty when only prose changed");
            }
        }

        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $out['doc_purposes_prose']['provenance']);
        $this->assertCount(1, $out['doc_purposes_prose']['changed']);
        $this->assertSame('App\\Foo', $out['doc_purposes_prose']['changed'][0]['fqcn']);
        $this->assertSame('Does X.', $out['doc_purposes_prose']['changed'][0]['was']);
        $this->assertSame('Does X (refined).', $out['doc_purposes_prose']['changed'][0]['now']);
    }

    public function test_diff_is_symmetric_inverse(): void
    {
        $a = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => true]),
        ], [], ['App\\Foo'], forbidden: ['app/Foo.php'], docGaps: ['ProvenanceX']);

        $b = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => false]),
            $this->inventoryItem('app/Bar.php', 'App\\Bar'),
        ], [], [], docGaps: ['ProvenanceY']);

        $engine = new AtlasCortexSnapshotDiffEngine;
        $ab = $engine->diff($a, $b);
        $ba = $engine->diff($b, $a);

        $this->assertSame($ab['inventory']['added'], $ba['inventory']['removed']);
        $this->assertSame($ab['inventory']['removed'], $ba['inventory']['added']);
        $this->assertSame($ab['orphans']['appeared'], $ba['orphans']['resolved']);
        $this->assertSame($ab['orphans']['resolved'], $ba['orphans']['appeared']);
        $this->assertSame($ab['forbidden']['added'], $ba['forbidden']['removed']);
        $this->assertSame($ab['forbidden']['removed'], $ba['forbidden']['added']);
        $this->assertSame($ab['doc_stated_gaps']['opened'], $ba['doc_stated_gaps']['closed']);
        $this->assertSame($ab['doc_stated_gaps']['closed'], $ba['doc_stated_gaps']['opened']);
    }

    public function test_clone_clusters_appeared_dissolved_and_shape_changed(): void
    {
        $clusterLeft = [
            'cluster_id' => 'c1',
            'clone_hash' => 'h1',
            'members' => [['path' => 'app/X.php', 'symbol' => 'X']],
        ];
        $clusterRight = [
            'cluster_id' => 'c1',
            'clone_hash' => 'h1',
            'members' => [
                ['path' => 'app/X.php', 'symbol' => 'X'],
                ['path' => 'app/Y.php', 'symbol' => 'Y'], // shape changed
            ],
        ];
        $appearedCluster = ['cluster_id' => 'c2', 'clone_hash' => 'h2', 'members' => [['path' => 'app/Z.php', 'symbol' => 'Z']]];

        $left = $this->model([$this->inventoryItem('app/X.php', 'App\\X')], clones: [$clusterLeft]);
        $right = $this->model([$this->inventoryItem('app/X.php', 'App\\X')], clones: [$clusterRight, $appearedCluster]);

        $out = (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);

        $this->assertSame(['c2'], $out['clone_clusters']['appeared']);
        $this->assertSame([], $out['clone_clusters']['dissolved']);
        $this->assertSame(['c1'], $out['clone_clusters']['shape_changed']);
    }

    public function test_edges_added_and_removed_per_rel_path(): void
    {
        $inv = [$this->inventoryItem('app/Foo.php', 'App\\Foo')];
        $left = $this->model($inv, edges: ['app/Foo.php' => ['app/Caller1.php']]);
        $right = $this->model($inv, edges: ['app/Foo.php' => ['app/Caller2.php']]);

        $out = (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);

        $this->assertSame(['app/Foo.php' => ['app/Caller2.php']], $out['edges']['added']);
        $this->assertSame(['app/Foo.php' => ['app/Caller1.php']], $out['edges']['removed']);
    }
}
