<?php

namespace Tests\Feature\Ai\SelfConstruction;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneValidationGateRuntimeSurfaceTest extends TestCase
{
    public function test_command_exposes_validation_gate_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-validation-gate-runtime-contract' => 'atlas.self_construction_agent_control_plane_validation_gate_runtime_contract.v1',
            '--agent-control-plane-validation-gate-runtime-preflight' => 'atlas.self_construction_agent_control_plane_validation_gate_runtime_preflight.v1',
            '--agent-control-plane-validation-gate-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_validation_gate_runtime_implementation_packet.v1',
            '--agent-control-plane-validation-gate-runtime-status' => 'atlas.self_construction_agent_control_plane_validation_gate_runtime_status.v1',
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

    public function test_status_projection_uses_canonical_all_pass_certification(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-validation-gate-runtime-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = (array) data_get($payload, 'agent_control_plane_validation_gate_runtime_status');

        $this->assertSame(0, $exit);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('available', $status['status']);
        $this->assertSame('passed', $status['overall_evaluation']);
        $this->assertTrue((bool) $status['invariants_all_true']);
        $this->assertSame(0, (int) $status['violation_count']);
        $this->assertSame(0, (int) $status['failure_count']);
        $this->assertTrue((bool) $status['runtime_safety_all_false']);
    }

    public function test_status_batch_includes_validation_gate_runtime(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-status-batch-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = (array) data_get($payload, 'agent_control_plane_certification_status_batch.statuses', []);
        $row = collect($statuses)->firstWhere('key', 'validation_gate_runtime');

        $this->assertSame(0, $exit);
        $this->assertSame('available', data_get($row, 'status'));
        $this->assertFalse((bool) data_get($row, 'runtime_execution_allowed'));
        $this->assertFalse((bool) data_get($row, 'dispatch_allowed'));
    }
}
