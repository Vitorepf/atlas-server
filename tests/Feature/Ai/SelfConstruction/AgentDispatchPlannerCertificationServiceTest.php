<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCertificationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_certification.v1', AgentDispatchPlannerCertificationService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_dispatch_planner_certification', AgentDispatchPlannerCertificationService::MODE);
    }

    public function test_status_available_with_empty_state(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        $result = $svc->certify();
        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertGreaterThan(15, count($result['invariants']));
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        $result = $svc->certify();
        $rs = $result['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['runtime_execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['claim_real_allowed']);
    }

    public function test_invariant_selection_respects_statuses(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('selection_respects_claimable_statuses', $names);
    }

    public function test_invariant_eligibility_human_approval(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('eligibility_flags_human_approval_for_high_risk', $names);
    }

    public function test_invariant_scope_overlap(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('scope_analyzer_detects_overlap', $names);
    }

    public function test_invariant_governance_kill_switch(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('governance_blocks_when_kill_switch_tripped', $names);
    }

    public function test_invariant_receipt_hash_stable(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('receipt_hash_stable', $names);
    }

    public function test_invariant_batch_planner_shape(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('batch_planner_produces_plan_shape', $names);
    }

    public function test_invariant_batch_hash_stable(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('batch_hash_stable', $names);
    }

    public function test_runtime_safety_invariants_present(): void
    {
        $names = $this->invariantNames();
        $this->assertContains('runtime_safety:no_provider_call', $names);
        $this->assertContains('runtime_safety:no_token_spend', $names);
        $this->assertContains('runtime_safety:no_dispatch_real', $names);
        $this->assertContains('runtime_safety:no_self_programming', $names);
        $this->assertContains('runtime_safety:claim_real_allowed_false', $names);
    }

    public function test_certification_hash_stable(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        $a = $svc->certify();
        $b = $svc->certify();
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['certification_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_all_invariants_ok(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        $result = $svc->certify();
        foreach ($result['invariants'] as $invariant) {
            $this->assertTrue($invariant['ok'], 'invariant '.$invariant['name'].' must hold');
        }
        $this->assertStringContainsString('keep_dispatch_planner_dry_run_until_runtime_pilot_promotes', $result['next_action']);
    }

    public function test_runtime_safety_in_envelope(): void
    {
        $svc = new AgentDispatchPlannerCertificationService;
        $result = $svc->certify();
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    /**
     * @return list<string>
     */
    private function invariantNames(): array
    {
        $svc = new AgentDispatchPlannerCertificationService;

        return array_map(
            static fn (array $i): string => (string) $i['name'],
            (array) $svc->certify()['invariants'],
        );
    }
}
