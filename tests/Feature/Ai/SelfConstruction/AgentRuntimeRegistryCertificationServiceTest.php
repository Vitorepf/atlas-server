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

    // ── certifyWorkerReadiness ──────────────────────────────────────────────

    private function workerFacts(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'worker-1',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'task_families' => [
                ['family' => 'service_layer', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['age_days' => 1, 'self_declared' => false],
            'recent_outcomes' => [],
            'known_failure_modes' => [],
        ], $overrides);
    }

    public function test_worker_readiness_certified_when_capability_and_evidence_are_clean(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts());

        $this->assertSame('certified', $result['readiness_status']);
        $this->assertContains('service_layer', $result['allowed_task_families']);
        $this->assertSame([], $result['blocked_task_families']);
    }

    public function test_worker_readiness_blocked_when_evidence_self_declared(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness(
            $this->workerFacts(['evidence' => ['age_days' => 1, 'self_declared' => true]]),
        );

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertSame([], $result['allowed_task_families']);
        $this->assertTrue($result['global_evidence_failure']);
        $blocker = $result['blocked_task_families'][0];
        $this->assertContains('self_declared_evidence_not_verified', $blocker['reasons']);
    }

    public function test_worker_readiness_blocked_when_evidence_stale(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness(
            $this->workerFacts(['evidence' => ['age_days' => 30, 'self_declared' => false], 'max_evidence_age_days' => 14]),
        );

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertTrue($result['evidence_freshness']['is_stale']);
    }

    public function test_worker_readiness_blocked_for_family_missing_required_capability(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts([
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'doc_writer', 'required_capabilities' => ['doc_generation']],
            ],
        ]));

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertContains('missing_capability:doc_generation', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_worker_readiness_blocked_for_family_with_high_give_back_rate(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts([
            'recent_outcomes' => [
                ['family' => 'service_layer', 'outcome' => 'give_back'],
                ['family' => 'service_layer', 'outcome' => 'give_back'],
                ['family' => 'service_layer', 'outcome' => 'success'],
            ],
        ]));

        $this->assertContains('high_give_back_rate', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_worker_readiness_not_blocked_by_give_back_rate_below_sample_floor(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts([
            'recent_outcomes' => [
                ['family' => 'service_layer', 'outcome' => 'give_back'],
                ['family' => 'service_layer', 'outcome' => 'give_back'],
            ],
        ]));

        $this->assertSame('certified', $result['readiness_status']);
    }

    public function test_worker_readiness_blocked_for_known_failure_mode_family(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts([
            'known_failure_modes' => ['service_layer'],
        ]));

        $this->assertContains('known_failure_mode', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_worker_readiness_partial_when_some_families_allowed_and_some_blocked(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts([
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'service_layer', 'required_capabilities' => ['code_edit']],
                ['family' => 'doc_writer', 'required_capabilities' => ['doc_generation']],
            ],
        ]));

        $this->assertSame('partial', $result['readiness_status']);
        $this->assertContains('service_layer', $result['allowed_task_families']);
        $this->assertSame('doc_writer', $result['blocked_task_families'][0]['family']);
    }

    public function test_worker_readiness_runtime_flags_all_false(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness($this->workerFacts());

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed'] as $flag) {
            $this->assertFalse($result[$flag]);
        }
    }
}
