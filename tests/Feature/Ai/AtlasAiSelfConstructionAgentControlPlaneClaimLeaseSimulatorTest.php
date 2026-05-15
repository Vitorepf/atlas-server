<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneClaimLeaseSimulatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_simulation.v1', AgentControlPlaneClaimLeaseSimulator::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_claim_lease_simulation', AgentControlPlaneClaimLeaseSimulator::MODE);
    }

    public function test_single_claim_granted(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertSame('simulated_granted', $result['lease_status']);
        $this->assertSame(0, $result['conflict_count']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_existing_lease_conflict(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet, [
            'existing_leases' => [
                [
                    'lease_id' => 'other-lease',
                    'owner' => 'other-agent',
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
                ],
            ],
        ]);
        $this->assertSame('simulated_conflict', $result['lease_status']);
        $this->assertGreaterThanOrEqual(1, $result['conflict_count']);
        $this->assertContains('conflict_overlap_detected', $result['blocking_reasons']);
    }

    public function test_blocked_when_packet_not_planned(): void
    {
        $packet = $this->packet();
        $packet['status'] = 'blocked';
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertSame('simulated_blocked', $result['lease_status']);
        $this->assertContains('task_packet_not_planned', $result['blocking_reasons']);
    }

    public function test_lease_ttl_default_and_override(): void
    {
        $packet = $this->packet();
        $defaultResult = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertSame(1800, (int) $defaultResult['lease_ttl_seconds']);

        $overrideResult = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet, ['lease_ttl_seconds' => 600]);
        $this->assertSame(600, (int) $overrideResult['lease_ttl_seconds']);
    }

    public function test_release_plan_present(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertArrayHasKey('release_plan', $result);
        $this->assertFalse($result['release_plan']['runtime_enabled']);
        $this->assertArrayHasKey('renewal_plan', $result);
        $this->assertArrayHasKey('expiration_plan', $result);
    }

    public function test_no_lock_write(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['reservation_persistence_allowed']);
        $this->assertContains('claim_lease_simulator_does_not_persist_lease', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_dispatch_work', $result['non_execution_guarantees']);
    }

    public function test_hashes_stable(): void
    {
        $packet = $this->packet();
        $svc = new AgentControlPlaneClaimLeaseSimulator;
        $a = $svc->simulate($packet);
        $b = $svc->simulate($packet);
        $this->assertSame($a['claim_hash'], $b['claim_hash']);
        $this->assertSame($a['lease_hash'], $b['lease_hash']);
        $this->assertSame($a['simulation_hash'], $b['simulation_hash']);
        $this->assertNotSame($a['claim_id'], $b['claim_id']);
    }

    public function test_forced_conflict_option(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet, ['force_conflict' => true]);
        $this->assertSame('simulated_conflict', $result['lease_status']);
        $this->assertContains('forced_conflict_option', $result['blocking_reasons']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-claim-lease-simulator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_claim_lease_simulator_status.v1', $payload['schema_version']);
        $this->assertSame('simulated_granted', data_get($payload, 'agent_control_plane_claim_lease_simulator_status.lease_status'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-claim-lease-simulator-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_claim_lease_simulator_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertTrue($result['runtime_disabled']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
    }

    public function test_full_status_payload_assertions(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        $this->assertSame(AgentControlPlaneClaimLeaseSimulator::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AgentControlPlaneClaimLeaseSimulator::MODE, $result['mode']);
        $this->assertSame('simulated_granted', $result['lease_status']);
        $this->assertIsString($result['claim_id']);
        $this->assertIsString($result['lease_id']);
        $this->assertGreaterThan(0, $result['lease_ttl_seconds']);
        $this->assertIsArray($result['conflict_set']);
        $this->assertIsArray($result['blocking_reasons']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['claim_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['lease_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['simulation_hash']);
        $this->assertTrue($result['renewal_plan']['auto_renew']);
        $this->assertGreaterThan(0, $result['renewal_plan']['max_renewals']);
        $this->assertGreaterThan(0, $result['renewal_plan']['next_renewal_in_seconds']);
        $this->assertFalse($result['renewal_plan']['runtime_enabled']);
        $this->assertGreaterThan(0, $result['expiration_plan']['expires_at_seconds_offset']);
        $this->assertFalse($result['expiration_plan']['runtime_enabled']);
        $this->assertFalse($result['release_plan']['runtime_enabled']);
        $this->assertStringContainsString('Claim/lease simulation', (string) $result['human_summary']);
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $result = (new AgentControlPlaneClaimLeaseSimulator)->simulate($packet);
        foreach ([
            'schema_version', 'mode', 'claim_id', 'lease_id', 'task_packet_id', 'generated_at',
            'lease_status', 'lease_owner', 'lease_ttl_seconds', 'conflict_set', 'conflict_count',
            'blocking_reasons', 'claim_hash', 'lease_hash', 'renewal_plan', 'expiration_plan',
            'release_plan', 'read_only', 'runtime_disabled', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'reservation_persistence_allowed', 'non_execution_guarantees',
            'human_summary', 'simulation_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        $this->assertTrue($result['renewal_plan']['auto_renew']);
        $this->assertFalse($result['renewal_plan']['runtime_enabled']);
        $this->assertSame('release_simulated_lease', $result['expiration_plan']['expiration_action']);
        $this->assertFalse($result['expiration_plan']['runtime_enabled']);
        $this->assertSame('simulated_release_on_completion_or_cancel', $result['release_plan']['release_strategy']);
        $this->assertContains('claim_lease_simulator_does_not_start_codex', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_persist_lease', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_call_provider', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_dispatch_work', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_spend_tokens', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_enable_self_programming', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_write_ledger', $result['non_execution_guarantees']);
        $this->assertContains('claim_lease_simulator_does_not_mutate_pointer', $result['non_execution_guarantees']);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'test claim/lease',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
    }
}
