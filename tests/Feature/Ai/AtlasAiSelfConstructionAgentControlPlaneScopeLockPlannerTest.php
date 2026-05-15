<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneScopeLockPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_scope_lock_plan.v1', AgentControlPlaneScopeLockPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_scope_lock_plan', AgentControlPlaneScopeLockPlanner::MODE);
    }

    public function test_allowed_files_normalized(): void
    {
        $packet = $this->packet(['app/Services/Ai/SelfConstruction/Foo.php', 'app/Services/Ai/SelfConstruction/Bar.php']);
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $this->assertSame('planned_safe', $plan['status']);
        $this->assertSame(['app/Services/Ai/SelfConstruction/Bar.php', 'app/Services/Ai/SelfConstruction/Foo.php'], $plan['write_set']);
    }

    public function test_cross_axis_blockers(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'cross axis',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
        // Patch write_set override to inject cross-axis path
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet, [], [
            'write_set' => ['app/Services/Ai/SelfImprovement/Bar.php'],
        ]);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertNotEmpty($plan['cross_axis_blockers']);
        $this->assertContains('cross_axis_blocker', $plan['blocking_reasons']);
    }

    public function test_forbidden_files_enforced(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'forbidden enforce',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'app/Services/Ai/SelfConstruction/Bar.php'],
            'forbidden_files' => ['app/Services/Ai/SelfConstruction/Bar.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $this->assertSame('planned_blocked', $plan['status']);
    }

    public function test_rollback_boundary_present(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $this->assertArrayHasKey('rollback_boundary', $plan);
        $this->assertFalse($plan['rollback_boundary']['rollback_runtime_enabled']);
        $this->assertSame('git_worktree_discard', $plan['rollback_boundary']['strategy']);
    }

    public function test_unsafe_path_blocker(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet, [], [
            'write_set' => ['vendor/bad.php'],
        ]);
        $this->assertNotEmpty($plan['unsafe_path_blockers']);
        $this->assertContains('unsafe_path_blocker', $plan['blocking_reasons']);
    }

    public function test_read_only_with_write_set_empty(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet, [], [
            'write_set' => [],
            'read_set' => [],
        ]);
        // write set defaults to allowed when override is empty
        $this->assertNotSame([], $plan['write_set']);
    }

    public function test_lease_not_granted_blocks(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet, ['lease_status' => 'simulated_conflict']);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertContains('lease_not_granted', $plan['blocking_reasons']);
    }

    public function test_hash_stable(): void
    {
        $packet = $this->packet();
        $svc = new AgentControlPlaneScopeLockPlanner;
        $a = $svc->plan($packet);
        $b = $svc->plan($packet);
        $this->assertSame($a['scope_lock_plan_hash'], $b['scope_lock_plan_hash']);
        $this->assertNotSame($a['scope_lock_plan_id'], $b['scope_lock_plan_id']);
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['persistence_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-scope-lock-planner-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_scope_lock_planner_status.v1', $payload['schema_version']);
        $this->assertSame('planned_safe', data_get($payload, 'agent_control_plane_scope_lock_planner_status.status'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-scope-lock-planner-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_scope_lock_planner_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_conflict_policy_present(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $this->assertSame('block', $plan['conflict_policy']['on_write_overlap']);
        $this->assertSame('allow', $plan['conflict_policy']['on_read_overlap']);
        $this->assertSame('block', $plan['conflict_policy']['on_axis_overlap']);
    }

    public function test_full_guarantee_set(): void
    {
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($this->packet());
        foreach ([
            'scope_lock_planner_does_not_start_codex',
            'scope_lock_planner_does_not_call_codex_cli_or_app',
            'scope_lock_planner_does_not_spawn_subprocess',
            'scope_lock_planner_does_not_invoke_adapter',
            'scope_lock_planner_does_not_call_provider',
            'scope_lock_planner_does_not_dispatch_work',
            'scope_lock_planner_does_not_spend_tokens',
            'scope_lock_planner_does_not_enable_self_programming',
            'scope_lock_planner_does_not_write_ledger',
            'scope_lock_planner_does_not_persist_lock',
            'scope_lock_planner_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $plan['non_execution_guarantees']);
        }
        foreach (array_keys(AgentControlPlaneScopeLockPlanner::CROSS_AXIS_BLOCKERS) as $axisKey) {
            $this->assertNotEmpty($axisKey);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        foreach ([
            'schema_version', 'mode', 'scope_lock_plan_id', 'task_packet_id', 'generated_at',
            'status', 'allowed_files', 'forbidden_files', 'scope_in', 'scope_out',
            'write_set', 'read_set', 'conflict_policy', 'rollback_boundary',
            'unsafe_path_blockers', 'cross_axis_blockers', 'forbidden_in_write_set',
            'blocking_reasons', 'read_only', 'runtime_disabled', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'persistence_allowed', 'non_execution_guarantees',
            'human_summary', 'scope_lock_plan_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $plan, "Missing $key");
        }
        foreach (AgentControlPlaneScopeLockPlanner::CROSS_AXIS_BLOCKERS as $axis => $prefix) {
            $this->assertIsString($axis);
            $this->assertIsString($prefix);
        }
        $this->assertContains('../', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('vendor/', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('node_modules/', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('.env', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('.git/', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('/etc/', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('storage/framework/', AgentControlPlaneScopeLockPlanner::UNSAFE_PATH_PREFIXES);
        $this->assertContains('scope_lock_planner_does_not_start_codex', $plan['non_execution_guarantees']);
        $this->assertContains('scope_lock_planner_does_not_persist_lock', $plan['non_execution_guarantees']);
        $this->assertContains('scope_lock_planner_does_not_dispatch_work', $plan['non_execution_guarantees']);
        $this->assertContains('scope_lock_planner_does_not_call_provider', $plan['non_execution_guarantees']);
        $this->assertContains('scope_lock_planner_does_not_write_ledger', $plan['non_execution_guarantees']);
        $this->assertContains('scope_lock_planner_does_not_mutate_pointer', $plan['non_execution_guarantees']);
        $this->assertSame('block', $plan['conflict_policy']['on_forbidden_overlap']);
    }

    /**
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>
     */
    private function packet(array $allowed = ['app/Services/Ai/SelfConstruction/Foo.php']): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'scope lock test',
            'operator_id' => 'tester',
            'allowed_files' => $allowed,
            'scope_in' => $allowed,
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
    }
}
