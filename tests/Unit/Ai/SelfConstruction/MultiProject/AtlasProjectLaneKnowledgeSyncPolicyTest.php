<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneKnowledgeSyncPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneKnowledgeSyncPolicy: Atlas-lane changes inside lane roots ⇒ conformant=true
 * with docs+code commands; external lane scoped to its own roots ⇒ produces its own commands; a
 * cross-project path yields cross_project_path:<path> blocker; no-op outcome with nothing touched ⇒
 * empty commands.
 */
final class AtlasProjectLaneKnowledgeSyncPolicyTest extends TestCase
{
    private function atlasLane(): array
    {
        return ['project_id' => 'atlas', 'allowed_scope_roots' => ['app/', 'docs/']];
    }

    public function test_atlas_lane_changes_inside_roots_yields_conformant_true(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => ['app/Demo/Foo.php', 'docs/x.md'],
        ]);
        $this->assertTrue($r['conformant']);
        $ids = array_column($r['required_commands'], 'id');
        $this->assertContains('lane-code-index:atlas', $ids);
        $this->assertContains('lane-docs-sync:atlas', $ids);
    }

    public function test_external_lane_produces_its_own_lane_scoped_commands(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => ['project_id' => 'demo-lane', 'allowed_scope_roots' => ['/repo/demo/app/']],
            'touched_paths' => ['/repo/demo/app/Bar.php'],
        ]);
        $this->assertTrue($r['conformant']);
        $ids = array_column($r['required_commands'], 'id');
        $this->assertContains('lane-code-index:demo-lane', $ids);
    }

    public function test_cross_project_path_yields_blocker_and_omits_command(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => ['project_id' => 'lane-a', 'allowed_scope_roots' => ['/repo/lane-a/app/']],
            'touched_paths' => ['/repo/lane-b/app/secret.php'],
        ]);
        $this->assertFalse($r['conformant']);
        $this->assertContains('cross_project_path:/repo/lane-b/app/secret.php', $r['blockers']);
    }

    public function test_no_op_outcome_with_nothing_touched_yields_empty_commands(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
        ]);
        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['required_commands']);
    }

    public function test_outcome_flags_inject_receipt_and_memory_export_commands(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => ['app/Foo.php'],
            'outcome_facts' => ['integrated_test_passed' => true, 'requires_release_notes' => true],
        ]);
        $ids = array_column($r['required_commands'], 'id');
        $this->assertContains('lane-receipts-export:atlas', $ids);
        $this->assertContains('lane-memory-export:atlas', $ids);
    }

    public function test_stale_freshness_facts_return_conformant_false_with_named_stale_artifact_blockers(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'   => $this->atlasLane(),
            'touched_paths'   => ['app/Foo.php'],
            'freshness_facts' => ['stale' => ['code_index', 'memory']],
        ]);

        $this->assertFalse($r['conformant']);
        $this->assertContains('stale_artifact:code_index', $r['blockers']);
        $this->assertContains('stale_artifact:memory', $r['blockers']);
    }

    public function test_cross_project_paths_yield_empty_commands_and_cross_project_path_blocker(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => ['project_id' => 'lane-a', 'allowed_scope_roots' => ['/repo/lane-a/']],
            'touched_paths' => ['/repo/lane-b/secret.php'],
        ]);

        $this->assertFalse($r['conformant']);
        $this->assertContains('cross_project_path:/repo/lane-b/secret.php', $r['blockers']);
        $this->assertSame([], $r['required_commands'], 'no commands must be emitted for cross-project paths');
        foreach ($r['required_commands'] as $cmd) {
            $this->assertStringNotContainsString('lane-b', $cmd['id'], 'cross-project path must not produce a command targeting the other lane');
        }
    }

    // ── knowledge surfaces ────────────────────────────────────────────────────

    public function test_output_includes_canonical_docs_code_index_freshness_memory_projection_status(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
        ]);
        $this->assertArrayHasKey('canonical_docs', $r);
        $this->assertArrayHasKey('code_index_freshness', $r);
        $this->assertArrayHasKey('memory_projection_status', $r);
    }

    public function test_canonical_docs_populated_from_lane_manifest(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => array_merge($this->atlasLane(), [
                'canonical_docs' => ['docs/engineering-knowledge-base/foo.md', 'docs/bar.md'],
            ]),
            'touched_paths' => [],
        ]);
        $this->assertSame(['docs/engineering-knowledge-base/foo.md', 'docs/bar.md'], $r['canonical_docs']);
    }

    public function test_stale_code_index_surface_blocks_and_emits_sync_command(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'      => $this->atlasLane(),
            'touched_paths'      => [],
            'knowledge_surfaces' => ['code_index' => 'stale'],
        ]);

        $this->assertFalse($r['conformant']);
        $this->assertContains('stale_knowledge_surface:code_index', $r['blockers']);
        $this->assertContains('lane-surface-sync:atlas:code_index', array_column($r['required_commands'], 'id'));
    }

    public function test_stale_memory_projection_surface_blocks_and_emits_sync_command(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'      => $this->atlasLane(),
            'touched_paths'      => [],
            'knowledge_surfaces' => ['memory_projection' => 'stale'],
        ]);

        $this->assertFalse($r['conformant']);
        $this->assertContains('stale_knowledge_surface:memory_projection', $r['blockers']);
        $this->assertContains('lane-surface-sync:atlas:memory_projection', array_column($r['required_commands'], 'id'));
    }

    public function test_missing_knowledge_surface_blocks_and_emits_sync_command(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'      => $this->atlasLane(),
            'touched_paths'      => [],
            'knowledge_surfaces' => ['canonical_docs' => 'missing'],
        ]);

        $this->assertFalse($r['conformant']);
        $this->assertContains('missing_knowledge_surface:canonical_docs', $r['blockers']);
        $this->assertContains('lane-surface-sync:atlas:canonical_docs', array_column($r['required_commands'], 'id'));
    }

    public function test_fresh_knowledge_surfaces_produce_no_blockers_or_commands(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'      => $this->atlasLane(),
            'touched_paths'      => [],
            'knowledge_surfaces' => [
                'code_index'        => 'fresh',
                'memory_projection' => 'fresh',
                'canonical_docs'    => 'fresh',
            ],
        ]);

        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['required_commands']);
    }

    // ── AC: required_docs / code_index_sync / memory_writeback / receipt_sync / post_merge_sync ──

    public function test_output_includes_new_sync_duty_facts(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
        ]);

        foreach (['required_docs', 'code_index_sync', 'memory_writeback', 'receipt_sync', 'post_merge_sync', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing {$key}");
        }
    }

    public function test_atlas_lane_with_code_change_sets_code_index_sync_true(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => ['app/Foo.php'],
        ]);

        $this->assertTrue($r['code_index_sync']);
    }

    public function test_external_lane_with_no_relevant_facts_has_all_sync_duties_false(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => ['project_id' => 'demo-lane', 'allowed_scope_roots' => ['/repo/demo/']],
            'touched_paths' => [],
        ]);

        $this->assertFalse($r['code_index_sync']);
        $this->assertFalse($r['memory_writeback']);
        $this->assertFalse($r['receipt_sync']);
        $this->assertFalse($r['post_merge_sync']);
    }

    public function test_missing_canonical_docs_yields_empty_required_docs(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
        ]);

        $this->assertSame([], $r['required_docs']);
    }

    public function test_disabled_memory_writeback_when_no_release_notes_flag_and_fresh_projection(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest'      => $this->atlasLane(),
            'touched_paths'      => [],
            'knowledge_surfaces' => ['memory_projection' => 'fresh'],
        ]);

        $this->assertFalse($r['memory_writeback']);
    }

    public function test_post_merge_sync_required_triggers_all_sync_duties_and_command(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
            'outcome_facts' => ['post_merge' => true],
        ]);

        $this->assertTrue($r['post_merge_sync']);
        $this->assertTrue($r['code_index_sync']);
        $this->assertTrue($r['memory_writeback']);
        $this->assertTrue($r['receipt_sync']);
        $this->assertContains('lane-post-merge-sync:atlas', array_column($r['required_commands'], 'id'));
    }

    public function test_safe_no_op_lane_has_no_sync_duties_and_stays_conformant(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => $this->atlasLane(),
            'touched_paths' => [],
        ]);

        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
        $this->assertFalse($r['code_index_sync']);
        $this->assertFalse($r['memory_writeback']);
        $this->assertFalse($r['receipt_sync']);
        $this->assertFalse($r['post_merge_sync']);
    }

    public function test_traversal_path_inside_root_is_blocked_as_cross_project(): void
    {
        $r = (new AtlasProjectLaneKnowledgeSyncPolicy)->plan([
            'lane_manifest' => ['project_id' => 'lane-a', 'allowed_scope_roots' => ['/repo/lane-a/app/']],
            'touched_paths' => ['/repo/lane-a/../lane-b/app/secret.php'],
        ]);
        $this->assertFalse($r['conformant'], 'traversal path must be non-conformant');
        $this->assertContains('cross_project_path:/repo/lane-a/../lane-b/app/secret.php', $r['blockers']);
    }
}
