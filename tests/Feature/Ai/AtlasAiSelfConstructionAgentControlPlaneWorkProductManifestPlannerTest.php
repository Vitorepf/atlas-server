<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductManifestPlanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneWorkProductManifestPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_work_product_manifest_plan.v1', AgentControlPlaneWorkProductManifestPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_work_product_manifest_plan', AgentControlPlaneWorkProductManifestPlanner::MODE);
    }

    public function test_expected_outputs_built_from_write_set(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet, $scope);
        $this->assertSame('planned', $plan['status']);
        $this->assertGreaterThanOrEqual(1, $plan['expected_output_count']);
        $kinds = array_unique(array_column($plan['expected_outputs'], 'kind'));
        $this->assertContains('php_source', $kinds);
    }

    public function test_validation_commands_default(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet);
        $this->assertNotEmpty($plan['validation_commands']);
    }

    public function test_collection_disabled(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet);
        $this->assertFalse($plan['collection_allowed']);
        $this->assertFalse($plan['automatic_collection_runtime_enabled']);
    }

    public function test_artifact_hash_plan_placeholders(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet, $scope);
        foreach ($plan['artifact_hash_plan'] as $entry) {
            $this->assertSame('sha256', $entry['hash_algorithm']);
            $this->assertFalse($entry['hash_collection_runtime_enabled']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $entry['placeholder_hash']);
        }
    }

    public function test_hash_stable(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $svc = new AgentControlPlaneWorkProductManifestPlanner;
        $a = $svc->plan($packet, $scope);
        $b = $svc->plan($packet, $scope);
        $this->assertSame($a['work_product_manifest_hash'], $b['work_product_manifest_hash']);
        $this->assertNotSame($a['manifest_plan_id'], $b['manifest_plan_id']);
    }

    public function test_override_expected_outputs(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet, [], [
            'expected_outputs' => [['path' => 'docs/x.md', 'kind' => 'markdown_doc', 'optional' => true]],
        ]);
        $this->assertSame(1, $plan['expected_output_count']);
        $this->assertSame('docs/x.md', $plan['expected_outputs'][0]['path']);
    }

    public function test_blocked_when_packet_blocked(): void
    {
        $packet = $this->packet();
        $packet['status'] = 'blocked';
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet, []);
        $this->assertSame('planned_blocked', $plan['status']);
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['persistence_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-work-product-manifest-planner-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_work_product_manifest_planner_status.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'agent_control_plane_work_product_manifest_planner_status.expected_output_count'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-work-product-manifest-planner-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_work_product_manifest_planner_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_proof_requirements_present(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet);
        $this->assertNotEmpty($plan['proof_requirements']);
        $this->assertContains('test_suite_green', $plan['proof_requirements']);
    }

    public function test_full_guarantee_set(): void
    {
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($this->packet());
        foreach ([
            'work_product_manifest_planner_does_not_start_codex',
            'work_product_manifest_planner_does_not_call_codex_cli_or_app',
            'work_product_manifest_planner_does_not_spawn_subprocess',
            'work_product_manifest_planner_does_not_invoke_adapter',
            'work_product_manifest_planner_does_not_call_provider',
            'work_product_manifest_planner_does_not_dispatch_work',
            'work_product_manifest_planner_does_not_spend_tokens',
            'work_product_manifest_planner_does_not_enable_self_programming',
            'work_product_manifest_planner_does_not_write_ledger',
            'work_product_manifest_planner_does_not_collect_artifacts',
            'work_product_manifest_planner_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $plan['non_execution_guarantees']);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneWorkProductManifestPlanner)->plan($packet);
        foreach ([
            'schema_version', 'mode', 'manifest_plan_id', 'task_packet_id', 'generated_at',
            'status', 'expected_outputs', 'expected_output_count', 'output_paths',
            'validation_commands', 'proof_requirements', 'artifact_hash_plan', 'collection_allowed',
            'automatic_collection_runtime_enabled', 'blocking_reasons', 'read_only', 'runtime_disabled',
            'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'persistence_allowed', 'non_execution_guarantees', 'human_summary',
            'work_product_manifest_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $plan, "Missing $key");
        }
        $this->assertContains('php_syntax_check_clean', $plan['proof_requirements']);
        $this->assertContains('pint_format_clean', $plan['proof_requirements']);
        $this->assertContains('test_suite_green', $plan['proof_requirements']);
        $this->assertContains('evidence_dry_run_present', $plan['proof_requirements']);
        $this->assertContains('continuation_summary_present', $plan['proof_requirements']);
        $this->assertContains('work_product_manifest_planner_does_not_collect_artifacts', $plan['non_execution_guarantees']);
        $this->assertContains('work_product_manifest_planner_does_not_call_provider', $plan['non_execution_guarantees']);
        $this->assertContains('work_product_manifest_planner_does_not_dispatch_work', $plan['non_execution_guarantees']);
        $this->assertContains('work_product_manifest_planner_does_not_start_codex', $plan['non_execution_guarantees']);
        $this->assertContains('work_product_manifest_planner_does_not_mutate_pointer', $plan['non_execution_guarantees']);
        $this->assertContains('work_product_manifest_planner_does_not_write_ledger', $plan['non_execution_guarantees']);
        foreach ($plan['expected_outputs'] as $entry) {
            $this->assertArrayHasKey('path', $entry);
            $this->assertArrayHasKey('kind', $entry);
            $this->assertArrayHasKey('optional', $entry);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'manifest test',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
    }
}
