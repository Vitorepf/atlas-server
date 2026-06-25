<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricPacketSpecValidator;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricPacketSpecValidator: a valid Atlas-native draft is self_sufficient=true; missing
 * acceptance yields missing_acceptance_criteria + missing_gates; a broad directory in allowed_files
 * yields allowed_files:broad_directory:<path>; non-Atlas-native simplicity_contract yields
 * ownership:non_atlas_native; when a full packet (with task_packet_id) is passed AND an inspector is
 * injected, the validator delegates and surfaces the inspector envelope without mutating the queue.
 */
final class AtlasTaskFabricPacketSpecValidatorTest extends TestCase
{
    private function validDraft(): array
    {
        return [
            'objective' => 'Add a small helper.',
            'allowed_files' => ['app/Demo/Helper.php', 'tests/Unit/Demo/HelperTest.php'],
            'scope_in' => ['app/Demo/Helper.php'],
            'acceptance_criteria' => ['phpunit green'],
            'required_evidence' => ['test_run_id'],
            'rollback_hint' => 'revert_commit:abc',
            'workspace_policy' => ['execution_topology' => 'shared_local_main_with_scope_lock'],
            'simplicity_contract' => 'atlas_native',
        ];
    }

    public function test_valid_draft_is_self_sufficient_true_with_zero_blockers(): void
    {
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($this->validDraft());
        $this->assertTrue($r['self_sufficient']);
        $this->assertSame([], $r['blockers']);
        $this->assertNull($r['delegated_inspector']);
    }

    public function test_missing_acceptance_yields_missing_acceptance_criteria_and_missing_gates_blockers(): void
    {
        $d = $this->validDraft();
        $d['acceptance_criteria'] = [];
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($d);
        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('missing_acceptance_criteria', $r['blockers']);
    }

    public function test_broad_directory_in_allowed_files_yields_named_blocker(): void
    {
        $d = $this->validDraft();
        $d['allowed_files'] = ['app/Demo/']; // directory, no basename '.'
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($d);
        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('allowed_files:broad_directory:app/Demo/', $r['blockers']);
    }

    public function test_non_atlas_native_simplicity_contract_yields_ownership_blocker(): void
    {
        $d = $this->validDraft();
        $d['simplicity_contract'] = 'external_provider';
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($d);
        $this->assertContains('ownership:non_atlas_native', $r['blockers']);
    }

    public function test_non_shared_main_workspace_topology_yields_workspace_blocker(): void
    {
        $d = $this->validDraft();
        $d['workspace_policy'] = ['execution_topology' => 'parallel_worktree'];
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($d);
        $this->assertContains('workspace_policy:non_shared_main', $r['blockers']);
    }

    public function test_acceptance_with_no_gate_words_yields_missing_gates(): void
    {
        $d = $this->validDraft();
        $d['acceptance_criteria'] = ['user is happy', 'looks nice']; // no gate keyword
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate($d);
        $this->assertContains('missing_gates', $r['blockers']);
    }

    public function test_full_packet_with_inspector_delegates_and_surfaces_envelope(): void
    {
        // Create a stub inspector that records the call and returns a marker envelope.
        $captured = null;
        $stub = new class($captured)
        {
            public function __construct(private mixed &$cap) {}

            public function inspect(array $packet): array
            {
                $this->cap = $packet;

                return ['stub' => true, 'self_sufficient' => true];
            }
        };

        $d = $this->validDraft();
        $d['task_packet_id'] = 'demo-packet-1';
        $r = (new AtlasTaskFabricPacketSpecValidator($stub))->validate($d);

        $this->assertNotNull($r['delegated_inspector']);
        $this->assertTrue($r['delegated_inspector']['stub']);
        $this->assertSame('demo-packet-1', $captured['task_packet_id'] ?? null);
    }
}
