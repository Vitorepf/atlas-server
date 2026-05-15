<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneDispatchPlannerRuntimeSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_certification_surface_available_and_runtime_safe(): void
    {
        $cert = (new AgentDispatchPlannerCertificationService)->certify();

        $this->assertSame('available', $cert['status']);
        $this->assertTrue($cert['invariants_all_true']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertFalse($cert['runtime_safety']['dispatch_allowed']);
        $this->assertFalse($cert['runtime_safety']['claim_real_allowed']);
        $this->assertFalse($cert['runtime_safety']['provider_call_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_command_exposes_dispatch_planner_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-dispatch-planner-runtime-contract' => 'atlas.self_construction_agent_control_plane_dispatch_planner_runtime_contract.v1',
            '--agent-control-plane-dispatch-planner-runtime-preflight' => 'atlas.self_construction_agent_control_plane_dispatch_planner_runtime_preflight.v1',
            '--agent-control-plane-dispatch-planner-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_dispatch_planner_runtime_implementation_packet.v1',
            '--agent-control-plane-dispatch-planner-runtime-status' => 'atlas.self_construction_agent_control_plane_dispatch_planner_runtime_status.v1',
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

    public function test_status_batch_includes_dispatch_planner_runtime(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-status-batch-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = (array) data_get($payload, 'agent_control_plane_certification_status_batch.statuses', []);
        $keys = array_column($statuses, 'key');

        $this->assertSame(0, $exit);
        $this->assertContains('dispatch_planner_runtime', $keys);
        $row = collect($statuses)->firstWhere('key', 'dispatch_planner_runtime');

        $this->assertSame('available', data_get($row, 'status'));
        $this->assertFalse((bool) data_get($row, 'runtime_execution_allowed'));
        $this->assertFalse((bool) data_get($row, 'dispatch_allowed'));
    }
}
