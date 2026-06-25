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
}
