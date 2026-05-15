<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryCertificationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_certification.v1', AgentRuntimeRegistryCertificationService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_runtime_registry_certification', AgentRuntimeRegistryCertificationService::MODE);
    }

    public function test_status_available(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $result = $svc->certify();
        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertGreaterThan(15, count($result['invariants']));
    }

    public function test_runtime_safety_block_present_and_false(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $result = $svc->certify();
        $rs = $result['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['runtime_execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['handoff_execution_allowed']);
    }

    public function test_invariant_register_idempotent_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('register_idempotent', $names);
    }

    public function test_invariant_heartbeat_stale_detection_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('heartbeat_stale_detection', $names);
    }

    public function test_invariant_capability_match_detection_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('capability_match_detection', $names);
    }

    public function test_invariant_quarantine_blocks_availability_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('quarantine_blocks_availability', $names);
    }

    public function test_invariant_handoff_requires_continuation_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('handoff_requires_continuation', $names);
    }

    public function test_invariant_runtime_safety_no_provider_call_present(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $names = $this->invariantNames($svc->certify());
        $this->assertContains('runtime_safety:no_provider_call', $names);
        $this->assertContains('runtime_safety:no_token_spend', $names);
        $this->assertContains('runtime_safety:no_dispatch_real', $names);
        $this->assertContains('runtime_safety:no_self_programming', $names);
    }

    public function test_certification_hash_stable(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $a = $svc->certify();
        $b = $svc->certify();
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['certification_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_all_invariants_ok(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $result = $svc->certify();
        foreach ($result['invariants'] as $invariant) {
            $this->assertTrue($invariant['ok'], 'invariant '.$invariant['name'].' must hold');
        }
        $this->assertStringContainsString('keep_registry_layer_persistent_local_until_runtime_pilot_promotes', $result['next_action']);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function invariantNames(array $result): array
    {
        return array_map(static fn (array $i): string => (string) $i['name'], (array) $result['invariants']);
    }

    public function test_runtime_flags_in_envelope(): void
    {
        $svc = new AgentRuntimeRegistryCertificationService;
        $result = $svc->certify();
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }
}
