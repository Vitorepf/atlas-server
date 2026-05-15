<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCostEventNormalizer;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCostImportReceiptPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCostImportReconciliationDryRun;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneAutomaticCostImportRuntimeTest extends TestCase
{
    public function test_certification_is_available_for_safe_synthetic_events(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify();

        $this->assertSame('atlas.self_construction.agent_control_plane_automatic_cost_import_runtime.v1', $cert['schema_version']);
        $this->assertSame('available', $cert['status']);
        $this->assertTrue($cert['invariants_all_true']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertFalse($cert['import_allowed']);
        $this->assertFalse($cert['cost_events_write_allowed']);
        $this->assertFalse($cert['provider_billing_api_read_allowed']);
        $this->assertFalse($cert['token_spend_allowed']);
        $this->assertFalse($cert['dispatch_allowed']);
        $this->assertFalse($cert['adapter_execution_allowed']);
        $this->assertFalse($cert['self_programming_allowed']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_certification_hash_is_deterministic_for_same_input(): void
    {
        $service = new AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
        $options = ['cost_events' => $this->safeEvents(), 'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']]];

        $first = $service->certify($options);
        $second = $service->certify($options);

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
        $this->assertSame($first['cost_event_normalization']['normalized_cost_events_hash'], $second['cost_event_normalization']['normalized_cost_events_hash']);
    }

    public function test_normalizer_detects_duplicate_idempotency_key(): void
    {
        $events = [$this->safeEvents()[0], array_merge($this->safeEvents()[0], ['run_id' => 'run-b'])];
        $result = (new AgentControlPlaneCostEventNormalizer)->normalize($events);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertContains('duplicate_idempotency_key', array_column($result['violations'], 'code'));
    }

    public function test_certification_blocks_negative_cost(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => [array_merge($this->safeEvents()[0], ['amount_minor' => -1])],
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('negative_cost_amount', array_column($cert['violations'], 'code'));
    }

    public function test_certification_blocks_invalid_currency(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => [array_merge($this->safeEvents()[0], ['currency' => 'BTC'])],
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('invalid_currency', array_column($cert['violations'], 'code'));
    }

    public function test_certification_blocks_token_spend_claim(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => [array_merge($this->safeEvents()[0], ['token_spend_claimed' => true])],
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('token_spend_claimed', array_column($cert['violations'], 'code'));
    }

    public function test_certification_blocks_provider_billing_api_source(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => [array_merge($this->safeEvents()[0], ['provider_billing_api_source' => true])],
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('provider_billing_api_source_claimed', array_column($cert['violations'], 'code'));
    }

    public function test_certification_blocks_missing_required_identifiers(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => [array_merge($this->safeEvents()[0], ['task_packet_id' => '', 'run_id' => '', 'agent_id' => ''])],
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
        ]);

        $codes = array_column($cert['violations'], 'code');
        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('missing_task_packet_id', $codes);
        $this->assertContains('missing_run_id', $codes);
        $this->assertContains('missing_agent_id', $codes);
    }

    public function test_receipt_planner_is_non_persistent_and_hashes_receipts(): void
    {
        $normalized = (new AgentControlPlaneCostEventNormalizer)->normalize($this->safeEvents());
        $plan = (new AgentControlPlaneCostImportReceiptPlanner)->plan($normalized['normalized_cost_events']);

        $this->assertSame('cost_import_receipt_plan_ready', $plan['status']);
        $this->assertSame(1, $plan['receipt_count']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['cost_events_write_allowed']);
        $this->assertFalse($plan['receipt_persistence_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['cost_import_receipt_plan_hash']);
    }

    public function test_reconciliation_reports_missing_expected_refs_without_importing(): void
    {
        $normalized = (new AgentControlPlaneCostEventNormalizer)->normalize($this->safeEvents());
        $result = (new AgentControlPlaneCostImportReconciliationDryRun)->reconcile($normalized['normalized_cost_events'], [
            ['task_packet_id' => 'task-a', 'run_id' => 'run-a'],
            ['task_packet_id' => 'task-missing', 'run_id' => 'run-missing'],
        ]);

        $this->assertSame('reconciliation_dry_run_has_gaps', $result['status']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertFalse($result['import_allowed']);
        $this->assertFalse($result['cost_events_write_allowed']);
        $this->assertFalse($result['provider_billing_api_read_allowed']);
    }

    public function test_certification_rejects_runtime_enabling_flags(): void
    {
        $cert = (new AgentControlPlaneAutomaticCostImportRuntimeCertificationService)->certify([
            'cost_events' => $this->safeEvents(),
            'expected_cost_refs' => [['task_packet_id' => 'task-a', 'run_id' => 'run-a']],
            'import_allowed' => true,
            'token_spend_allowed' => true,
            'dispatch_allowed' => true,
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertGreaterThanOrEqual(3, count(array_filter($cert['violations'], static fn (array $v): bool => ($v['code'] ?? '') === 'runtime_flag_true')));
    }

    public function test_command_exposes_automatic_cost_import_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-automatic-cost-import-runtime-contract' => 'atlas.self_construction_agent_control_plane_automatic_cost_import_runtime_contract.v1',
            '--agent-control-plane-automatic-cost-import-runtime-preflight' => 'atlas.self_construction_agent_control_plane_automatic_cost_import_runtime_preflight.v1',
            '--agent-control-plane-automatic-cost-import-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_automatic_cost_import_runtime_implementation_packet.v1',
            '--agent-control-plane-automatic-cost-import-runtime-status' => 'atlas.self_construction_agent_control_plane_automatic_cost_import_runtime_status.v1',
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

    /** @return list<array<string, mixed>> */
    private function safeEvents(): array
    {
        return [
            [
                'idempotency_key' => 'cost-event-task-a-run-a',
                'task_packet_id' => 'task-a',
                'run_id' => 'run-a',
                'agent_id' => 'agent-a',
                'source' => 'manual_cost_event_writer',
                'currency' => 'USD',
                'amount_minor' => 0,
                'provider' => 'manual',
                'model' => 'none',
            ],
        ];
    }
}
