<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffEngine;
use Tests\TestCase;

final class AtlasCortexSnapshotDiffEngineTest extends TestCase
{
    private function svc(): AtlasCortexSnapshotDiffEngine
    {
        return new AtlasCortexSnapshotDiffEngine;
    }

    private function model(
        array $inventory = [],
        array $edges = [],
        array $orphans = [],
        array $cloneClusters = [],
        array $forbidden = [],
        array $docPurposes = [],
        array $docStatedGaps = [],
        string $snapshotId = 'snap-a',
    ): AtlasLoopScopeComprehensionModel {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: $edges,
            orphans: $orphans,
            cloneClusters: $cloneClusters,
            forbidden: $forbidden,
            docPurposes: $docPurposes,
            docStatedGaps: $docStatedGaps,
            snapshotId: $snapshotId,
        );
    }

    private function invItem(string $fqcn, array $overrides = []): array
    {
        return array_merge([
            'fqcn'             => $fqcn,
            'public_methods'   => [],
            'is_orphan'        => false,
            'is_forbidden'     => false,
            'clone_cluster_id' => null,
        ], $overrides);
    }

    private function cluster(string $id, string $hash, array $members): array
    {
        return ['cluster_id' => $id, 'clone_hash' => $hash, 'members' => $members];
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_diff_returns_expected_top_level_keys(): void
    {
        $m = $this->model();
        $r = $this->svc()->diff($m, $m);

        $this->assertArrayHasKey('schema_version',    $r);
        $this->assertArrayHasKey('left',              $r);
        $this->assertArrayHasKey('right',             $r);
        $this->assertArrayHasKey('inventory',         $r);
        $this->assertArrayHasKey('orphans',           $r);
        $this->assertArrayHasKey('edges',             $r);
        $this->assertArrayHasKey('clone_clusters',    $r);
        $this->assertArrayHasKey('forbidden',         $r);
        $this->assertArrayHasKey('doc_stated_gaps',   $r);
        $this->assertArrayHasKey('doc_purposes_prose', $r);
    }

    // ── AC2: transitions in dedicated lists ───────────────────────────────────

    public function test_ac2_new_inventory_entry_appears_in_added(): void
    {
        $left  = $this->model(inventory: [$this->invItem('App\\Foo')]);
        $right = $this->model(inventory: [$this->invItem('App\\Foo'), $this->invItem('App\\Bar')]);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('App\\Bar', $r['inventory']['added']);
        $this->assertNotContains('App\\Bar', $r['inventory']['removed']);
    }

    public function test_ac2_removed_inventory_entry_appears_in_removed(): void
    {
        $left  = $this->model(inventory: [$this->invItem('App\\Foo'), $this->invItem('App\\Bar')]);
        $right = $this->model(inventory: [$this->invItem('App\\Foo')]);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('App\\Bar', $r['inventory']['removed']);
        $this->assertNotContains('App\\Bar', $r['inventory']['added']);
    }

    public function test_ac2_new_orphan_appears_in_appeared(): void
    {
        $left  = $this->model(orphans: []);
        $right = $this->model(orphans: ['App\\OrphanClass']);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('App\\OrphanClass', $r['orphans']['appeared']);
        $this->assertNotContains('App\\OrphanClass', $r['orphans']['resolved']);
    }

    public function test_ac2_resolved_orphan_appears_in_resolved(): void
    {
        $left  = $this->model(orphans: ['App\\OrphanClass']);
        $right = $this->model(orphans: []);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('App\\OrphanClass', $r['orphans']['resolved']);
        $this->assertNotContains('App\\OrphanClass', $r['orphans']['appeared']);
    }

    public function test_ac2_new_edge_appears_in_edges_added(): void
    {
        $left  = $this->model(edges: []);
        $right = $this->model(edges: ['app/Foo.php' => ['App\\Caller']]);

        $r = $this->svc()->diff($left, $right);

        $this->assertArrayHasKey('app/Foo.php', $r['edges']['added']);
        $this->assertContains('App\\Caller', $r['edges']['added']['app/Foo.php']);
    }

    public function test_ac2_removed_edge_appears_in_edges_removed(): void
    {
        $left  = $this->model(edges: ['app/Foo.php' => ['App\\Caller']]);
        $right = $this->model(edges: []);

        $r = $this->svc()->diff($left, $right);

        $this->assertArrayHasKey('app/Foo.php', $r['edges']['removed']);
    }

    public function test_ac2_new_clone_cluster_appears_in_appeared(): void
    {
        $left  = $this->model(cloneClusters: []);
        $right = $this->model(cloneClusters: [
            $this->cluster('cc1', 'abc123', [['path' => 'app/A.php', 'symbol' => 'A']]),
        ]);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('cc1', $r['clone_clusters']['appeared']);
        $this->assertNotContains('cc1', $r['clone_clusters']['dissolved']);
    }

    public function test_ac2_dissolved_clone_cluster_appears_in_dissolved(): void
    {
        $left  = $this->model(cloneClusters: [
            $this->cluster('cc1', 'abc123', [['path' => 'app/A.php', 'symbol' => 'A']]),
        ]);
        $right = $this->model(cloneClusters: []);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('cc1', $r['clone_clusters']['dissolved']);
        $this->assertNotContains('cc1', $r['clone_clusters']['appeared']);
    }

    public function test_ac2_new_forbidden_path_appears_in_forbidden_added(): void
    {
        $left  = $this->model(forbidden: []);
        $right = $this->model(forbidden: ['app/Sensitive.php']);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('app/Sensitive.php', $r['forbidden']['added']);
        $this->assertNotContains('app/Sensitive.php', $r['forbidden']['removed']);
    }

    public function test_ac2_removed_forbidden_path_appears_in_forbidden_removed(): void
    {
        $left  = $this->model(forbidden: ['app/Sensitive.php']);
        $right = $this->model(forbidden: []);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('app/Sensitive.php', $r['forbidden']['removed']);
    }

    public function test_ac2_new_doc_gap_appears_in_opened(): void
    {
        $left  = $this->model(docStatedGaps: []);
        $right = $this->model(docStatedGaps: ['missing_feature_x']);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('missing_feature_x', $r['doc_stated_gaps']['opened']);
        $this->assertNotContains('missing_feature_x', $r['doc_stated_gaps']['closed']);
    }

    public function test_ac2_resolved_doc_gap_appears_in_closed(): void
    {
        $left  = $this->model(docStatedGaps: ['missing_feature_x']);
        $right = $this->model(docStatedGaps: []);

        $r = $this->svc()->diff($left, $right);

        $this->assertContains('missing_feature_x', $r['doc_stated_gaps']['closed']);
    }

    // ── AC3: identity diff → all transition lists empty, no score/rank ────────

    public function test_ac3_identity_diff_has_all_empty_transition_lists(): void
    {
        $m = $this->model(
            inventory:     [$this->invItem('App\\Foo')],
            edges:         ['app/Foo.php' => ['App\\Bar']],
            orphans:       ['App\\Baz'],
            cloneClusters: [$this->cluster('cc1', 'hash1', [['path' => 'app/A.php', 'symbol' => 'A']])],
            forbidden:     ['app/Secret.php'],
            docStatedGaps: ['gap_x'],
            snapshotId:    'same',
        );

        $r = $this->svc()->diff($m, $m);

        $this->assertEmpty($r['inventory']['added'],            'identity: inventory.added must be empty');
        $this->assertEmpty($r['inventory']['removed'],          'identity: inventory.removed must be empty');
        $this->assertEmpty($r['inventory']['shape_changed'],    'identity: inventory.shape_changed must be empty');
        $this->assertEmpty($r['orphans']['appeared'],           'identity: orphans.appeared must be empty');
        $this->assertEmpty($r['orphans']['resolved'],           'identity: orphans.resolved must be empty');
        $this->assertEmpty($r['edges']['added'],                'identity: edges.added must be empty');
        $this->assertEmpty($r['edges']['removed'],              'identity: edges.removed must be empty');
        $this->assertEmpty($r['clone_clusters']['appeared'],    'identity: clone_clusters.appeared must be empty');
        $this->assertEmpty($r['clone_clusters']['dissolved'],   'identity: clone_clusters.dissolved must be empty');
        $this->assertEmpty($r['clone_clusters']['shape_changed'], 'identity: clone_clusters.shape_changed must be empty');
        $this->assertEmpty($r['forbidden']['added'],            'identity: forbidden.added must be empty');
        $this->assertEmpty($r['forbidden']['removed'],          'identity: forbidden.removed must be empty');
        $this->assertEmpty($r['doc_stated_gaps']['opened'],     'identity: doc_stated_gaps.opened must be empty');
        $this->assertEmpty($r['doc_stated_gaps']['closed'],     'identity: doc_stated_gaps.closed must be empty');
    }

    public function test_ac3_diff_output_never_has_aggregate_score_field(): void
    {
        $m = $this->model();
        $r = $this->svc()->diff($m, $m);

        $scoreForbidden = ['score', 'rank', 'quality_score', 'aggregate', 'importance'];
        foreach ($scoreForbidden as $key) {
            $this->assertArrayNotHasKey($key, $r,
                "diff must not emit aggregate scalar field '{$key}'");
        }
    }

    public function test_ac3_identity_prose_delta_is_empty(): void
    {
        $m = $this->model(docPurposes: ['App\\Foo' => 'Does something useful.']);
        $r = $this->svc()->diff($m, $m);

        $this->assertEmpty($r['doc_purposes_prose']['changed'],
            'identity: doc_purposes_prose.changed must be empty');
    }

    // ── AC4: doc purpose prose in separate section, tagged separately ─────────

    public function test_ac4_prose_change_appears_only_in_doc_purposes_prose(): void
    {
        $left  = $this->model(docPurposes: ['App\\Foo' => 'Old description.']);
        $right = $this->model(docPurposes: ['App\\Foo' => 'New description.']);

        $r = $this->svc()->diff($left, $right);

        // Prose delta must appear in doc_purposes_prose.changed
        $this->assertNotEmpty($r['doc_purposes_prose']['changed']);
        $changed = $r['doc_purposes_prose']['changed'];
        $this->assertSame('App\\Foo', $changed[0]['fqcn']);
        $this->assertSame('Old description.', $changed[0]['was']);
        $this->assertSame('New description.', $changed[0]['now']);

        // Structural transition lists must be unaffected by prose change
        $this->assertEmpty($r['inventory']['added']);
        $this->assertEmpty($r['inventory']['removed']);
        $this->assertEmpty($r['inventory']['shape_changed']);
    }

    public function test_ac4_doc_purposes_prose_is_tagged_with_provenance(): void
    {
        $m = $this->model();
        $r = $this->svc()->diff($m, $m);

        $this->assertArrayHasKey('provenance', $r['doc_purposes_prose']);
        $this->assertSame(
            AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
            $r['doc_purposes_prose']['provenance'],
        );
    }

    public function test_ac4_prose_change_does_not_pollute_structural_lists(): void
    {
        // Same inventory + edges + etc, only doc_purposes changes
        $invItem = $this->invItem('App\\Shared');
        $left  = $this->model(
            inventory:   [$invItem],
            docPurposes: ['App\\Shared' => 'Purpose A'],
        );
        $right = $this->model(
            inventory:   [$invItem],
            docPurposes: ['App\\Shared' => 'Purpose B'],
        );

        $r = $this->svc()->diff($left, $right);

        // All structural lists must be empty
        $this->assertEmpty($r['inventory']['added']);
        $this->assertEmpty($r['inventory']['removed']);
        $this->assertEmpty($r['inventory']['shape_changed']);
        $this->assertEmpty($r['orphans']['appeared']);
        $this->assertEmpty($r['forbidden']['added']);
        $this->assertEmpty($r['doc_stated_gaps']['opened']);

        // Prose section captures the change
        $this->assertNotEmpty($r['doc_purposes_prose']['changed']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_diff_output(): void
    {
        $left = $this->model(
            inventory:     [$this->invItem('App\\Alpha'), $this->invItem('App\\Beta')],
            orphans:       ['App\\Gamma'],
            forbidden:     ['app/Secret.php'],
            docStatedGaps: ['gap_one'],
        );
        $right = $this->model(
            inventory:     [$this->invItem('App\\Alpha'), $this->invItem('App\\Delta')],
            orphans:       [],
            forbidden:     [],
            docStatedGaps: ['gap_two'],
            snapshotId:    'snap-b',
        );

        $svc = $this->svc();
        $this->assertSame(
            json_encode($svc->diff($left, $right), JSON_UNESCAPED_SLASHES),
            json_encode($svc->diff($left, $right), JSON_UNESCAPED_SLASHES),
        );
    }
}
