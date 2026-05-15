<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCostImportDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCostImportDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_cost_import_dry_run.v1', AgentControlPlaneCostImportDryRun::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_cost_import_dry_run', AgentControlPlaneCostImportDryRun::MODE);
    }

    public function test_budget_policy_present(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertTrue($plan['budget_policy']['enforce']);
        $this->assertFalse($plan['budget_policy']['token_spend_allowed']);
        $this->assertSame('block_and_report', $plan['budget_policy']['overflow_strategy']);
    }

    public function test_token_spend_false(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['provider_budget']['token_spend_allowed']);
    }

    public function test_import_disabled(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertFalse($plan['automatic_cost_import_runtime_enabled']);
    }

    public function test_cost_meter_plan_present(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertSame('token_and_wallclock', $plan['cost_meter_plan']['meter_kind']);
        $this->assertFalse($plan['cost_meter_plan']['persistence_runtime_enabled']);
        $this->assertFalse($plan['cost_meter_plan']['enforcement_runtime_enabled']);
    }

    public function test_default_import_sources(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        foreach (AgentControlPlaneCostImportDryRun::DEFAULT_IMPORT_SOURCES as $src) {
            $this->assertContains($src, $plan['import_sources']);
        }
    }

    public function test_custom_import_sources_merged_and_unique(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet, [
            'import_sources' => ['manual_cost_event_writer', 'custom_source'],
        ]);
        $this->assertContains('custom_source', $plan['import_sources']);
        $this->assertSame(count(array_unique($plan['import_sources'])), count($plan['import_sources']));
    }

    public function test_hash_stable(): void
    {
        $packet = $this->packet();
        $svc = new AgentControlPlaneCostImportDryRun;
        $a = $svc->plan($packet);
        $b = $svc->plan($packet);
        $this->assertSame($a['cost_import_plan_hash'], $b['cost_import_plan_hash']);
        $this->assertNotSame($a['cost_import_plan_id'], $b['cost_import_plan_id']);
    }

    public function test_blocked_when_packet_blocked(): void
    {
        $packet = $this->packet();
        $packet['status'] = 'blocked';
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertSame('planned_blocked', $plan['status']);
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['self_programming_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['persistence_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-cost-import-dry-run-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_cost_import_dry_run_status.v1', $payload['schema_version']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_cost_import_dry_run_status.cost_import_plan_hash'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-cost-import-dry-run-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_cost_import_dry_run_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_full_guarantee_set(): void
    {
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($this->packet());
        foreach ([
            'cost_import_dry_run_does_not_start_codex',
            'cost_import_dry_run_does_not_call_codex_cli_or_app',
            'cost_import_dry_run_does_not_spawn_subprocess',
            'cost_import_dry_run_does_not_invoke_adapter',
            'cost_import_dry_run_does_not_call_provider',
            'cost_import_dry_run_does_not_dispatch_work',
            'cost_import_dry_run_does_not_spend_tokens',
            'cost_import_dry_run_does_not_read_provider_billing_apis',
            'cost_import_dry_run_does_not_enable_self_programming',
            'cost_import_dry_run_does_not_write_cost_events',
            'cost_import_dry_run_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $plan['non_execution_guarantees']);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneCostImportDryRun)->plan($packet);
        foreach ([
            'schema_version', 'mode', 'cost_import_plan_id', 'task_packet_id', 'generated_at',
            'status', 'budget_policy', 'token_budget', 'provider_budget', 'cost_meter_plan',
            'import_sources', 'import_source_count', 'automatic_cost_import_runtime_enabled',
            'token_spend_allowed', 'blocking_reasons', 'read_only', 'runtime_disabled',
            'dispatch_allowed', 'provider_call_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'persistence_allowed', 'non_execution_guarantees', 'human_summary',
            'cost_import_plan_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $plan, "Missing $key");
        }
        $this->assertContains('cost_import_dry_run_does_not_read_provider_billing_apis', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_spend_tokens', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_call_provider', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_dispatch_work', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_start_codex', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_write_cost_events', $plan['non_execution_guarantees']);
        $this->assertContains('cost_import_dry_run_does_not_mutate_pointer', $plan['non_execution_guarantees']);
        $this->assertContains('manual_cost_event_writer', $plan['import_sources']);
        $this->assertContains('provider_token_usage_meter', $plan['import_sources']);
        $this->assertContains('workspace_cost_audit_log', $plan['import_sources']);
        $this->assertGreaterThan(0, $plan['cost_meter_plan']['sampling_interval_seconds']);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'cost test',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
            'max_token_budget' => 1000,
            'max_runtime_seconds' => 600,
        ]);
    }
}
