<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateCertificationService;
use App\Services\Ai\SelfConstruction\AgentValidationGatePlanBuilder;
use Tests\TestCase;

final class AgentValidationGateCertificationServiceTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_certification.v1', AgentValidationGateCertificationService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_certification', AgentValidationGateCertificationService::MODE);
    }

    public function test_empty_inputs_no_synthetic_yields_blocked(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify();
        $this->assertSame('blocked', $out['status']);
        $this->assertFalse($out['execution_allowed']);
        $this->assertFalse($out['dispatch_allowed']);
        $this->assertFalse($out['provider_call_allowed']);
        $this->assertFalse($out['token_spend_allowed']);
        $this->assertFalse($out['self_programming_allowed']);
        $this->assertFalse($out['ledger_write_allowed']);
        $this->assertFalse($out['runtime_write_allowed']);
        $this->assertFalse($out['completion_allowed']);
        $this->assertTrue($out['invariants_all_true']);
        $this->assertSame(0, $out['violation_count']);
        $this->assertMatchesRegularExpression('/^cert-[a-f0-9]{16}$/', $out['certification_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $out['certification_hash']);
    }

    public function test_all_pass_yields_available(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify([], $this->fullPassInputs());
        $this->assertSame('available', $out['status']);
        $this->assertSame('passed', $out['summary']['overall_evaluation']);
        $this->assertSame(0, $out['summary']['failure_count']);
        $this->assertSame(0, $out['summary']['human_required_count']);
        $this->assertFalse($out['summary']['evaluation_aborted']);
        $this->assertNull($out['summary']['aborted_at_gate']);
        $this->assertSame('present_evidence_and_request_human_acknowledgement', $out['next_action']);
    }

    public function test_warn_yields_available_with_warnings(): void
    {
        $inputs = $this->fullPassInputs();
        $inputs['docs_health'] = ['status' => 'warn', 'evidence_artifact' => 'minor'];
        $out = (new AgentValidationGateCertificationService)->certify([], $inputs);
        $this->assertSame('available_with_warnings', $out['status']);
        $this->assertSame('passed_with_warnings', $out['summary']['overall_evaluation']);
        $this->assertSame('observe_warnings_and_request_human_acknowledgement', $out['next_action']);
    }

    public function test_scope_check_fail_yields_blocked_with_human_review(): void
    {
        $inputs = $this->fullPassInputs();
        $inputs['scope_check'] = ['status' => 'fail', 'evidence_artifact' => 'forbidden_file_touched'];
        $out = (new AgentValidationGateCertificationService)->certify(['allowed_files' => ['a.php']], $inputs);
        $this->assertSame('blocked', $out['status']);
        $this->assertSame('failed', $out['summary']['overall_evaluation']);
        $this->assertGreaterThan(0, $out['summary']['failure_count']);
        $this->assertGreaterThan(0, $out['summary']['human_required_count']);
        $this->assertSame('escalate_human_review_then_retry_inside_scope', $out['next_action']);
    }

    public function test_unit_test_fail_yields_blocked_no_human(): void
    {
        $inputs = $this->fullPassInputs();
        $inputs['unit_tests'] = ['status' => 'fail', 'evidence_artifact' => 'one_red'];
        $out = (new AgentValidationGateCertificationService)->certify([], $inputs);
        $this->assertSame('blocked', $out['status']);
        $this->assertGreaterThan(0, $out['summary']['failure_count']);
        $this->assertContains($out['next_action'], [
            'apply_repair_recommendations_inside_scope_and_retry',
            'escalate_human_review_then_retry_inside_scope',
        ]);
    }

    public function test_invariants_all_true_on_full_pass(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify([], $this->fullPassInputs());
        $this->assertTrue($out['invariants_all_true']);
        $this->assertSame(0, $out['violation_count']);
        $names = array_map(static fn ($i) => $i['name'], $out['invariants']);
        $this->assertContains('catalog_has_ten_gates', $names);
        $this->assertContains('plan_runtime_safety_all_false', $names);
        $this->assertContains('evaluation_runtime_safety_all_false', $names);
        $this->assertContains('classification_runtime_safety_all_false', $names);
        $this->assertContains('recommendation_runtime_safety_all_false', $names);
        $this->assertContains('no_real_command_executed', $names);
        $this->assertContains('no_provider_call', $names);
        $this->assertContains('no_token_spend', $names);
        $this->assertContains('no_dispatch_runtime', $names);
        $this->assertContains('no_ledger_write', $names);
        $this->assertContains('no_self_programming', $names);
        $this->assertContains('no_pointer_mutation', $names);
        $this->assertContains('completion_blocked_until_human_ack', $names);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify();
        $rs = $out['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
        $this->assertFalse($rs['completion_allowed']);
    }

    public function test_certification_hash_stable_across_runs(): void
    {
        $svc = new AgentValidationGateCertificationService;
        $a = $svc->certify([], $this->fullPassInputs());
        $b = $svc->certify([], $this->fullPassInputs());
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertSame($a['catalog_hash'], $b['catalog_hash']);
        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertSame($a['evaluation_hash'], $b['evaluation_hash']);
        $this->assertSame($a['classification_hash'], $b['classification_hash']);
        $this->assertSame($a['recommendation_hash'], $b['recommendation_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['certification_hash']);
    }

    public function test_certification_hash_changes_with_inputs(): void
    {
        $svc = new AgentValidationGateCertificationService;
        $a = $svc->certify([], $this->fullPassInputs());
        $altered = $this->fullPassInputs();
        $altered['unit_tests'] = ['status' => 'warn', 'evidence_artifact' => 'flaky'];
        $b = $svc->certify([], $altered);
        $this->assertNotSame($a['certification_hash'], $b['certification_hash']);
        $this->assertNotSame($a['evaluation_hash'], $b['evaluation_hash']);
    }

    public function test_scope_violation_context_surfaces_in_summary(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify([
            'allowed_files' => ['a.php'],
            'forbidden_files' => ['secret.env'],
            'changed_files' => ['a.php', 'secret.env'],
        ], $this->fullPassInputs());
        $this->assertTrue($out['summary']['scope_violation_detected']);
    }

    public function test_inconclusive_input_yields_inconclusive_or_blocked(): void
    {
        $inputs = $this->fullPassInputs();
        $inputs['unit_tests'] = ['status' => 'banana', 'evidence_artifact' => 'unsupported'];
        $out = (new AgentValidationGateCertificationService)->certify([], $inputs);
        $this->assertContains($out['status'], ['inconclusive', 'blocked']);
    }

    public function test_summary_carries_gate_id_lists(): void
    {
        $inputs = $this->fullPassInputs();
        $inputs['php_lint'] = ['status' => 'fail', 'evidence_artifact' => 'syntax'];
        $out = (new AgentValidationGateCertificationService)->certify([], $inputs);
        $this->assertContains('php_lint', $out['summary']['failed_gate_ids']);
        $this->assertIsArray($out['summary']['warn_gate_ids']);
        $this->assertIsArray($out['summary']['unknown_gate_ids']);
    }

    public function test_subset_requested_gates_certifies_subset(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['unit_tests', 'docs_health']]);
        $inputs = [];
        foreach ($plan['ordered_runs'] as $run) {
            $inputs[$run['gate_id']] = ['status' => 'pass', 'evidence_artifact' => $run['expected_artifact']];
        }
        $out = (new AgentValidationGateCertificationService)->certify(['requested_gates' => ['unit_tests', 'docs_health']], $inputs);
        $this->assertSame('available', $out['status']);
        $this->assertSame(10, $out['summary']['gate_count']);
        $this->assertSame('plan_ready', $out['summary']['plan_status']);
    }

    public function test_certification_invariants_runtime_safety_blocks_present(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify();
        foreach ($out['invariants'] as $inv) {
            $this->assertArrayHasKey('name', $inv);
            $this->assertArrayHasKey('ok', $inv);
            $this->assertIsBool($inv['ok']);
        }
    }

    public function test_empty_plan_yields_empty_status(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify(['requested_gates' => []]);
        $this->assertSame('empty', $out['status']);
        $this->assertSame('empty', $out['summary']['overall_evaluation']);
    }

    public function test_no_provider_call_invariant_true(): void
    {
        $out = (new AgentValidationGateCertificationService)->certify();
        $byName = [];
        foreach ($out['invariants'] as $i) {
            $byName[$i['name']] = $i['ok'];
        }
        $this->assertTrue($byName['no_provider_call']);
        $this->assertTrue($byName['no_token_spend']);
        $this->assertTrue($byName['no_dispatch_runtime']);
        $this->assertTrue($byName['no_self_programming']);
        $this->assertTrue($byName['no_ledger_write']);
        $this->assertTrue($byName['no_pointer_mutation']);
    }

    /** @return array<string, array<string, mixed>> */
    private function fullPassInputs(): array
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $out = [];
        foreach ($plan['ordered_runs'] as $run) {
            $out[$run['gate_id']] = ['status' => 'pass', 'evidence_artifact' => $run['expected_artifact']];
        }

        return $out;
    }
}
