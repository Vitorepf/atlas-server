<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskPacketBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet.v1', AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_task_packet_builder', AgentControlPlaneTaskPacketBuilder::MODE);
    }

    public function test_valid_packet_planned(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->validInput());
        $this->assertSame('planned', $packet['status']);
        $this->assertSame(AgentControlPlaneTaskPacketBuilder::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertNotEmpty($packet['task_packet_id']);
        $this->assertNotEmpty($packet['task_packet_hash']);
        $this->assertNotEmpty($packet['scope_hash']);
        $this->assertNotEmpty($packet['acceptance_hash']);
        $this->assertSame([], $packet['blocking_reasons']);
    }

    public function test_missing_objective_blocks(): void
    {
        $input = $this->validInput();
        unset($input['objective']);
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('objective_missing', $packet['blocking_reasons']);
    }

    public function test_empty_scope_blocks(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = [];
        $input['scope_in'] = [];
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('scope_empty', $packet['blocking_reasons']);
    }

    public function test_forbidden_overlaps_allowed_blocks(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['app/Services/Ai/SelfConstruction/Foo.php', 'app/Services/Ai/SelfConstruction/Bar.php'];
        $input['forbidden_files'] = ['app/Services/Ai/SelfConstruction/Foo.php'];
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('forbidden_files_inside_allowed_files', $packet['blocking_reasons']);
        $this->assertContains('app/Services/Ai/SelfConstruction/Foo.php', $packet['normalized_scope']['forbidden_in_allowed']);
    }

    public function test_forbidden_axis_blocked(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['app/Services/Ai/SelfImprovement/Foo.php'];
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('forbidden_axis_in_allowed_files', $packet['blocking_reasons']);
        $this->assertNotEmpty($packet['normalized_scope']['forbidden_axis_hits']);
    }

    public function test_deterministic_hash_stable(): void
    {
        $svc = new AgentControlPlaneTaskPacketBuilder;
        $a = $svc->build($this->validInput());
        $b = $svc->build($this->validInput());
        $this->assertSame($a['task_packet_hash'], $b['task_packet_hash']);
        $this->assertSame($a['scope_hash'], $b['scope_hash']);
        $this->assertSame($a['acceptance_hash'], $b['acceptance_hash']);
        $this->assertNotSame($a['task_packet_id'], $b['task_packet_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['task_packet_hash']);
    }

    public function test_runtime_flags_false(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->validInput());
        $this->assertTrue($packet['read_only']);
        $this->assertTrue($packet['runtime_disabled']);
        $this->assertFalse($packet['dispatch_allowed']);
        $this->assertFalse($packet['provider_call_allowed']);
        $this->assertFalse($packet['token_spend_allowed']);
        $this->assertFalse($packet['self_programming_allowed']);
        $this->assertFalse($packet['ledger_write_allowed']);
        $this->assertFalse($packet['completion_claim_allowed']);
    }

    public function test_paths_normalized_and_sorted(): void
    {
        $input = $this->validInput();
        $input['allowed_files'] = ['/b.php', 'a.php', 'a.php'];
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame(['a.php', 'b.php'], $packet['normalized_scope']['allowed_files']);
    }

    public function test_default_required_evidence(): void
    {
        $input = $this->validInput();
        unset($input['required_evidence']);
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertGreaterThanOrEqual(7, count($packet['evidence_requirements']['required']));
        $this->assertContains('task_packet_created', $packet['evidence_requirements']['required']);
        $this->assertContains('runtime_pilot_completed', $packet['evidence_requirements']['required']);
    }

    public function test_unknown_risk_level_defaults(): void
    {
        $input = $this->validInput();
        $input['risk_level'] = 'phantom';
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame('low', $packet['risk_classification']['risk_level']);
        $this->assertContains('risk_level_unknown_defaulting_low', $packet['warnings']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->validInput());
        $this->assertContains('task_packet_builder_does_not_start_codex', $packet['non_execution_guarantees']);
        $this->assertContains('task_packet_builder_does_not_dispatch_work', $packet['non_execution_guarantees']);
        $this->assertContains('task_packet_builder_does_not_write_ledger', $packet['non_execution_guarantees']);
        $this->assertContains('task_packet_builder_does_not_enable_self_programming', $packet['non_execution_guarantees']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-packet-builder-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_task_packet_builder_status.v1', $payload['schema_version']);
        $this->assertSame('planned', data_get($payload, 'agent_control_plane_task_packet_builder_status.status'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_task_packet_builder_status.task_packet_hash'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-packet-builder-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_task_packet_builder_{$stageKey}.v1",
                $payload['schema_version'],
            );
            $this->assertFalse((bool) $payload['execution_allowed']);
        }
    }

    public function test_acceptance_hash_changes_with_acceptance(): void
    {
        $svc = new AgentControlPlaneTaskPacketBuilder;
        $a = $svc->build($this->validInput());
        $input = $this->validInput();
        $input['acceptance_criteria'] = ['different'];
        $b = $svc->build($input);
        $this->assertNotSame($a['acceptance_hash'], $b['acceptance_hash']);
    }

    public function test_non_execution_guarantees_comprehensive(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->validInput());
        $guarantees = $packet['non_execution_guarantees'];
        foreach ([
            'task_packet_builder_does_not_start_codex',
            'task_packet_builder_does_not_call_codex_cli_or_app',
            'task_packet_builder_does_not_spawn_subprocess',
            'task_packet_builder_does_not_invoke_adapter',
            'task_packet_builder_does_not_call_provider',
            'task_packet_builder_does_not_dispatch_work',
            'task_packet_builder_does_not_spend_tokens',
            'task_packet_builder_does_not_enable_self_programming',
            'task_packet_builder_does_not_write_ledger',
            'task_packet_builder_does_not_mutate_pointer',
            'task_packet_builder_does_not_promote_completion_claim',
        ] as $expected) {
            $this->assertContains($expected, $guarantees, "Missing guarantee: $expected");
        }
    }

    public function test_packet_payload_fully_shaped(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->validInput());
        foreach ([
            'schema_version', 'mode', 'task_packet_id', 'generated_at', 'status',
            'objective', 'source', 'operator_id', 'parent_run_id', 'normalized_scope',
            'scope_hash', 'acceptance_criteria', 'acceptance_hash', 'evidence_requirements',
            'risk_classification', 'workspace_policy', 'simplicity_contract', 'lease_requirements', 'claim_requirements',
            'rollback_requirements', 'kill_switch_requirements', 'continuation_requirements',
            'cost_budget_requirements', 'continuation_context', 'blocking_reasons', 'warnings',
            'read_only', 'runtime_disabled', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'completion_claim_allowed', 'non_execution_guarantees', 'human_summary', 'task_packet_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $packet, "Missing key: $key");
        }
        $this->assertFalse($packet['lease_requirements']['persistence_allowed']);
        $this->assertFalse($packet['claim_requirements']['claim_persistence_allowed']);
        $this->assertFalse($packet['rollback_requirements']['rollback_persistence_allowed']);
        $this->assertFalse($packet['rollback_requirements']['rollback_runtime_enabled']);
        $this->assertFalse($packet['kill_switch_requirements']['kill_switch_arming_runtime_enabled']);
        $this->assertTrue($packet['kill_switch_requirements']['kill_switch_simulated_only']);
        $this->assertTrue($packet['continuation_requirements']['continuation_required']);
        $this->assertFalse($packet['continuation_requirements']['continuation_runtime_enabled']);
        $this->assertFalse($packet['cost_budget_requirements']['token_spend_allowed']);
        $this->assertFalse($packet['cost_budget_requirements']['budget_runtime_enabled']);
        $this->assertContains('routes/api.php', AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES);
        $this->assertContains('app/Services/Ai/SelfImprovement/', AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES);
        $this->assertContains('app/Services/Ai/Programming/', AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES);
        $this->assertSame('FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001', $packet['workspace_policy']['workspace_id']);
        $this->assertSame('shared_local_main_with_scope_lock', $packet['workspace_policy']['isolation']);
        $this->assertFalse($packet['workspace_policy']['auto_apply']);
        $this->assertSame('shared_local_main_with_allowed_files', $packet['simplicity_contract']['default_execution_topology']);
        $this->assertFalse($packet['simplicity_contract']['default_worktree_or_sandbox']);
        $this->assertFalse($packet['simplicity_contract']['human_or_external_provider_dependency_allowed']);
        $this->assertFalse($packet['simplicity_contract']['operator_dependency_allowed']);
        $this->assertFalse($packet['simplicity_contract']['human_dependency_allowed']);
        $this->assertFalse($packet['simplicity_contract']['external_provider_dependency_allowed']);
        $this->assertSame('atlas_native', $packet['simplicity_contract']['final_runtime_owner']);
        $this->assertSame('atlas_server', $packet['simplicity_contract']['steady_state_runtime_owner']);
        $this->assertFalse($packet['simplicity_contract']['steady_state_requires_operator']);
        $this->assertFalse($packet['simplicity_contract']['steady_state_requires_human']);
        $this->assertFalse($packet['simplicity_contract']['steady_state_requires_external_provider']);
        $this->assertSame('bootstrap_or_replaceable_muscle_only', $packet['simplicity_contract']['external_worker_role']);
    }

    public function test_continuation_context_keys_preserved(): void
    {
        $input = $this->validInput();
        $input['continuation_context'] = ['origin' => 'x', 'parent_hash' => 'abc'];
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertSame(['origin', 'parent_hash'], $packet['continuation_requirements']['continuation_context_keys']);
    }

    public function test_legacy_worktree_policy_is_normalized_to_shared_main(): void
    {
        $input = $this->validInput();
        $input['workspace_policy'] = ['isolation' => 'simulated_worktree'];

        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);

        $this->assertSame('shared_local_main_with_scope_lock', $packet['workspace_policy']['isolation']);
        $this->assertSame('simulated_worktree', $packet['workspace_policy']['legacy_isolation_normalized_from']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(): array
    {
        return [
            'objective' => 'Test pilot packet',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'forbidden_files' => ['routes/api.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
            'max_runtime_seconds' => 1800,
            'max_token_budget' => 0,
        ];
    }
}
