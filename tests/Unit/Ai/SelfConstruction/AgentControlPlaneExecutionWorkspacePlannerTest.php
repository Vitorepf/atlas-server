<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneExecutionWorkspacePlanner;
use Tests\TestCase;

final class AgentControlPlaneExecutionWorkspacePlannerTest extends TestCase
{
    private AgentControlPlaneExecutionWorkspacePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AgentControlPlaneExecutionWorkspacePlanner;
    }

    private function validPacket(array $overrides = []): array
    {
        return array_replace([
            'task_packet_id' => 'tp-1',
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Services/Foo.php'],
            ],
            'workspace_policy' => 'isolated_worktree_required',
        ], $overrides);
    }

    // ── AC: plan returns workspace paths, boundary, temp artifact, cleanup, safety ──

    public function test_plan_returns_required_output_keys(): void
    {
        $result = $this->planner->plan($this->validPacket());

        foreach ([
            'schema_version', 'mode', 'status', 'workspace_plan_id', 'task_packet_id',
            'write_set', 'read_set', 'staging_root', 'branch_name_hint',
            'allowed_files_boundary', 'temp_artifact_policy', 'cleanup_policy',
            'shared_main_safety_notes', 'runtime_safety', 'workspace_plan_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_plan_workspace_plan_ready_for_valid_packet(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertSame('workspace_plan_ready', $result['status']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_plan_staging_root_is_under_storage(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertStringStartsWith('storage/app/atlas/self-construction/agent-control-plane/execution-workspaces/', $result['staging_root']);
    }

    public function test_plan_branch_hint_is_under_atlas_namespace(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertStringStartsWith('atlas/self-construction/', $result['branch_name_hint']);
    }

    public function test_plan_allowed_files_boundary_matches_write_set(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertSame($result['write_set'], $result['allowed_files_boundary']);
    }

    public function test_plan_temp_artifact_policy_isolated_from_source(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertTrue($result['temp_artifact_policy']['temp_artifacts_allowed']);
        $this->assertTrue($result['temp_artifact_policy']['temp_artifacts_isolated_from_source']);
        $this->assertFalse($result['temp_artifact_policy']['source_tree_write_allowed']);
    }

    public function test_plan_cleanup_policy_preserves_evidence(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertTrue($result['cleanup_policy']['cleanup_required_after_resolution']);
        $this->assertTrue($result['cleanup_policy']['cleanup_preserves_committed_evidence']);
        $this->assertTrue($result['cleanup_policy']['cleanup_never_resets_shared_main']);
    }

    public function test_plan_shared_main_safety_notes_present(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertNotEmpty($result['shared_main_safety_notes']);
        $this->assertContains('no_git_reset_on_shared_main', $result['shared_main_safety_notes']);
        $this->assertContains('no_cross_worker_file_theft_tolerated', $result['shared_main_safety_notes']);
    }

    // ── AC: plan rejects missing allowed_files ──────────────────────────────

    public function test_plan_blocks_when_write_set_empty(): void
    {
        $result = $this->planner->plan([
            'task_packet_id' => 'tp-1',
            'normalized_scope' => ['allowed_files' => [], 'scope_in' => []],
        ]);

        $this->assertSame('workspace_plan_blocked', $result['status']);
        $this->assertContains('write_set_empty', $result['blocking_reasons']);
    }

    // ── AC: plan rejects broad root writes ──────────────────────────────────

    public function test_plan_blocks_wildcard_root_write(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'normalized_scope' => ['allowed_files' => ['*'], 'scope_in' => []],
        ]));

        $this->assertSame('workspace_plan_blocked', $result['status']);
        $this->assertContains('broad_root_write_detected', $result['blocking_reasons']);
    }

    public function test_plan_blocks_top_level_directory_write(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'normalized_scope' => ['allowed_files' => ['app/'], 'scope_in' => []],
        ]));

        $this->assertContains('broad_root_write_detected', $result['blocking_reasons']);
    }

    public function test_plan_allows_specific_file_under_app(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'normalized_scope' => ['allowed_files' => ['app/Services/Foo.php'], 'scope_in' => []],
        ]));

        $this->assertSame('workspace_plan_ready', $result['status']);
        $this->assertNotContains('broad_root_write_detected', $result['blocking_reasons']);
    }

    // ── AC: plan rejects unsafe workspace mutations ─────────────────────────

    public function test_plan_blocks_force_push_main(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'workspace_mutation_requirements' => ['force_push_main'],
        ]));

        $this->assertContains('unsafe_workspace_mutation_force_push_main', $result['blocking_reasons']);
    }

    public function test_plan_blocks_reset_hard_shared_branch(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'workspace_mutation_requirements' => ['reset_hard_shared_branch'],
        ]));

        $this->assertContains('unsafe_workspace_mutation_reset_hard_shared_branch', $result['blocking_reasons']);
    }

    // ── AC: plan rejects disallowed workspace policy ────────────────────────

    public function test_plan_blocks_disallowed_workspace_policy(): void
    {
        $result = $this->planner->plan($this->validPacket([
            'workspace_policy' => 'direct_main_write',
        ]));

        $this->assertContains('workspace_policy_not_allowed', $result['blocking_reasons']);
    }

    // ── AC: runtimeSafety includes no git reset, no global checkout, scoped commit ──

    public function test_runtime_safety_allows_nothing(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertFalse($safety['worktree_create_allowed']);
        $this->assertFalse($safety['real_file_write_allowed']);
        $this->assertFalse($safety['provider_call_allowed']);
        $this->assertFalse($safety['dispatch_allowed']);
        $this->assertFalse($safety['token_spend_allowed']);
        $this->assertFalse($safety['ledger_write_allowed']);
        $this->assertFalse($safety['self_programming_allowed']);
        $this->assertFalse($safety['completion_claim_allowed']);
    }

    public function test_runtime_safety_includes_no_git_reset(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertTrue($safety['no_git_reset_on_shared_main']);
    }

    public function test_runtime_safety_includes_no_global_checkout(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertTrue($safety['no_global_checkout_operations']);
    }

    public function test_runtime_safety_includes_scoped_commit_report(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertTrue($safety['scoped_commit_report_only']);
    }

    public function test_runtime_safety_includes_no_cross_worker_theft(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertTrue($safety['no_cross_worker_file_theft']);
    }

    public function test_runtime_safety_includes_scope_lock_required(): void
    {
        $safety = $this->planner->runtimeSafety();

        $this->assertTrue($safety['scope_lock_required_for_writes']);
        $this->assertTrue($safety['rollback_plan_required_before_writes']);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_plan_hash_is_hex64(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['workspace_plan_hash']);
    }

    public function test_plan_deterministic_for_same_input(): void
    {
        $packet = $this->validPacket();
        $a = $this->planner->plan($packet);
        $b = $this->planner->plan($packet);

        $this->assertSame($a['workspace_plan_hash'], $b['workspace_plan_hash']);
    }

    public function test_plan_hash_differs_for_different_write_set(): void
    {
        $a = $this->planner->plan($this->validPacket());
        $b = $this->planner->plan($this->validPacket([
            'normalized_scope' => ['allowed_files' => ['app/Services/Bar.php'], 'scope_in' => []],
        ]));

        $this->assertNotSame($a['workspace_plan_hash'], $b['workspace_plan_hash']);
    }

    public function test_plan_never_allows_execution(): void
    {
        $result = $this->planner->plan($this->validPacket());

        $this->assertFalse($result['worktree_create_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    // ── options-based invocation ────────────────────────────────────────────

    public function test_plan_accepts_write_set_via_options(): void
    {
        $result = $this->planner->plan([], [
            'write_set' => ['app/Foo.php'],
            'workspace_policy' => 'sandbox_preview_only',
        ]);

        $this->assertSame(['app/Foo.php'], $result['write_set']);
        $this->assertSame('sandbox_preview_only', $result['workspace_policy']);
    }
}
