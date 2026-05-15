<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneDiffArtifactPreviewBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneExecutionWorkspaceCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneExecutionWorkspacePlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneRollbackPlanBuilder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneExecutionWorkspaceRuntimeTest extends TestCase
{
    public function test_workspace_planner_produces_read_only_plan(): void
    {
        $plan = (new AgentControlPlaneExecutionWorkspacePlanner)->plan($this->taskPacket());

        $this->assertSame('workspace_plan_ready', $plan['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['workspace_plan_hash']);
        $this->assertTrue($plan['checkout_lock_required']);
        $this->assertFalse($plan['worktree_create_allowed']);
        $this->assertFalse($plan['real_file_write_allowed']);
        $this->assertFalse($plan['dispatch_allowed']);
    }

    public function test_workspace_planner_blocks_empty_write_set(): void
    {
        $packet = $this->taskPacket();
        $packet['normalized_scope']['allowed_files'] = [];

        $plan = (new AgentControlPlaneExecutionWorkspacePlanner)->plan($packet);

        $this->assertSame('workspace_plan_blocked', $plan['status']);
        $this->assertContains('write_set_empty', $plan['blocking_reasons']);
        $this->assertFalse($plan['worktree_create_allowed']);
    }

    public function test_diff_preview_and_rollback_are_non_mutating(): void
    {
        $workspace = (new AgentControlPlaneExecutionWorkspacePlanner)->plan($this->taskPacket());
        $diff = (new AgentControlPlaneDiffArtifactPreviewBuilder)->preview($workspace);
        $rollback = (new AgentControlPlaneRollbackPlanBuilder)->build($workspace, $diff);

        $this->assertSame('diff_preview_ready', $diff['status']);
        $this->assertSame('rollback_plan_ready', $rollback['status']);
        $this->assertFalse($diff['patch_apply_allowed']);
        $this->assertFalse($rollback['automatic_rollback_allowed']);
        $this->assertFalse($rollback['real_file_write_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $diff['diff_preview_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rollback['rollback_plan_hash']);
    }

    public function test_certification_available_and_runtime_safe(): void
    {
        $cert = (new AgentControlPlaneExecutionWorkspaceCertificationService)->certify();

        $this->assertSame('available', $cert['status']);
        $this->assertTrue($cert['invariants_all_true']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertFalse($cert['runtime_safety']['worktree_create_allowed']);
        $this->assertFalse($cert['runtime_safety']['patch_apply_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_command_exposes_execution_workspace_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-execution-workspace-runtime-contract' => 'atlas.self_construction_agent_control_plane_execution_workspace_runtime_contract.v1',
            '--agent-control-plane-execution-workspace-runtime-preflight' => 'atlas.self_construction_agent_control_plane_execution_workspace_runtime_preflight.v1',
            '--agent-control-plane-execution-workspace-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_execution_workspace_runtime_implementation_packet.v1',
            '--agent-control-plane-execution-workspace-runtime-status' => 'atlas.self_construction_agent_control_plane_execution_workspace_runtime_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    /** @return array<string, mixed> */
    private function taskPacket(): array
    {
        return [
            'task_packet_id' => 'workspace-task-1',
            'status' => 'planned',
            'workspace_policy' => 'isolated_worktree_required',
            'normalized_scope' => [
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/WorkspaceSlice.php',
                    'tests/Feature/Ai/SelfConstruction/WorkspaceSliceTest.php',
                ],
                'scope_in' => ['app/Services/Ai/SelfConstruction'],
            ],
        ];
    }
}
