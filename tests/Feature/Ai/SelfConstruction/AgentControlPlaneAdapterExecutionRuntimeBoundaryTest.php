<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneAdapterExecutionRuntimeBoundaryTest extends TestCase
{
    public function test_boundary_is_available_for_safe_adapter_descriptor(): void
    {
        $cert = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify();

        $this->assertSame('atlas.self_construction.agent_control_plane_adapter_execution_runtime_boundary.v1', $cert['schema_version']);
        $this->assertSame('available', $cert['status']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertSame('dry_run_contract_only', $cert['execution_envelope_dry_run']['execution_mode']);
        $this->assertTrue($cert['guardrail_matrix']['descriptor_valid']);
        $this->assertFalse($cert['adapter_execution_allowed']);
        $this->assertFalse($cert['provider_process_call_allowed']);
        $this->assertFalse($cert['token_spend_allowed']);
        $this->assertFalse($cert['dispatch_allowed']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_boundary_hash_is_deterministic(): void
    {
        $service = new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;

        $first = $service->certify(['provider' => 'codex', 'adapter' => 'codex']);
        $second = $service->certify(['provider' => 'codex', 'adapter' => 'codex']);

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
        $this->assertSame($first['execution_envelope_dry_run']['execution_envelope_hash'], $second['execution_envelope_dry_run']['execution_envelope_hash']);
    }

    public function test_boundary_blocks_live_execution(): void
    {
        $cert = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify(['execution_mode' => 'live']);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('live_execution_requested', array_column($cert['violations'], 'code'));
    }

    public function test_boundary_blocks_unknown_descriptor(): void
    {
        $cert = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify(['provider' => 'unknown', 'adapter' => 'unknown']);

        $this->assertSame('blocked', $cert['status']);
        $this->assertContains('adapter_descriptor_invalid', array_column($cert['violations'], 'code'));
    }

    public function test_boundary_blocks_runtime_enabling_flags(): void
    {
        $cert = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify([
            'adapter_execution_allowed' => true,
            'provider_process_call_allowed' => true,
            'token_spend_allowed' => true,
            'dispatch_allowed' => true,
        ]);

        $this->assertSame('blocked', $cert['status']);
        $this->assertGreaterThanOrEqual(4, count(array_filter($cert['violations'], static fn (array $v): bool => ($v['code'] ?? '') === 'runtime_flag_true')));
    }

    public function test_failure_taxonomy_contains_required_codes(): void
    {
        $cert = (new AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService)->certify();

        foreach (['adapter_descriptor_missing', 'adapter_descriptor_invalid', 'live_execution_requested', 'provider_call_requested', 'token_spend_requested', 'dispatch_requested', 'runtime_flag_true', 'approval_receipt_missing', 'evidence_bridge_missing'] as $code) {
            $this->assertContains($code, $cert['failure_taxonomy']);
        }
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['failure_taxonomy_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['guardrail_matrix_hash']);
    }

    public function test_command_exposes_adapter_execution_runtime_boundary_quartet(): void
    {
        foreach ([
            '--agent-control-plane-adapter-execution-runtime-boundary-contract' => 'atlas.self_construction_agent_control_plane_adapter_execution_runtime_boundary_contract.v1',
            '--agent-control-plane-adapter-execution-runtime-boundary-preflight' => 'atlas.self_construction_agent_control_plane_adapter_execution_runtime_boundary_preflight.v1',
            '--agent-control-plane-adapter-execution-runtime-boundary-implementation-packet' => 'atlas.self_construction_agent_control_plane_adapter_execution_runtime_boundary_implementation_packet.v1',
            '--agent-control-plane-adapter-execution-runtime-boundary-status' => 'atlas.self_construction_agent_control_plane_adapter_execution_runtime_boundary_status.v1',
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
}
