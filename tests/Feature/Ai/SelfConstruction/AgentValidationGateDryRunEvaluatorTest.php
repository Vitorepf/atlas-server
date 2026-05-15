<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateDryRunEvaluator;
use App\Services\Ai\SelfConstruction\AgentValidationGatePlanBuilder;
use Tests\TestCase;

final class AgentValidationGateDryRunEvaluatorTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_dry_run.v1', AgentValidationGateDryRunEvaluator::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_dry_run', AgentValidationGateDryRunEvaluator::MODE);
        $this->assertSame(['pass', 'fail', 'warn', 'skip'], AgentValidationGateDryRunEvaluator::ALLOWED_STATUSES);
    }

    public function test_empty_plan_yields_empty_overall(): void
    {
        $eval = new AgentValidationGateDryRunEvaluator;
        $result = $eval->evaluate([
            'plan_id' => 'plan-empty',
            'plan_hash' => str_repeat('0', 64),
            'ordered_runs' => [],
        ]);
        $this->assertSame('evaluated', $result['status']);
        $this->assertSame('empty', $result['overall_status']);
        $this->assertSame([], $result['evaluations']);
        $this->assertSame(0, $result['counts']['pass']);
        $this->assertSame(0, $result['counts']['fail']);
        $this->assertSame(0, $result['counts']['warn']);
        $this->assertSame(0, $result['counts']['skip']);
        $this->assertSame(0, $result['counts']['unknown']);
        $this->assertFalse($result['aborted']);
        $this->assertNull($result['aborted_at_gate']);
        $this->assertMatchesRegularExpression('/^result-[a-f0-9]{16}$/', $result['result_set_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evaluation_hash']);
    }

    public function test_all_pass_yields_passed_overall(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('passed', $result['overall_status']);
        $this->assertFalse($result['aborted']);
        $this->assertSame(10, $result['counts']['pass']);
        $this->assertSame(0, $result['counts']['fail']);
        $this->assertSame(0, $result['counts']['unknown']);
        $this->assertSame(10, count($result['evaluations']));
        $this->assertSame(10, count($result['pass_gate_ids']));
        $this->assertSame([], $result['failed_gate_ids']);
        $this->assertSame([], $result['warn_gate_ids']);
        $this->assertSame([], $result['skipped_gate_ids']);
        $this->assertSame([], $result['unknown_gate_ids']);
    }

    public function test_first_blocking_fail_aborts_remaining(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $inputs['scope_check'] = [
            'status' => 'fail',
            'evidence_artifact' => 'forbidden_files_touched',
            'detail' => ['files' => ['secret.env']],
        ];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('failed', $result['overall_status']);
        $this->assertTrue($result['aborted']);
        $this->assertSame('scope_check', $result['aborted_at_gate']);
        $this->assertSame(1, $result['counts']['fail']);
        $this->assertGreaterThan(0, $result['counts']['skip']);
        $this->assertContains('scope_check', $result['failed_gate_ids']);
        foreach ($result['evaluations'] as $r) {
            if ($r['gate_id'] !== 'scope_check') {
                if ($r['observed_status'] === 'skip') {
                    $this->assertSame('previous_blocking_gate_failed', $r['observed_reason']);
                    $this->assertStringStartsWith('aborted_after_scope_check', $r['observed_evidence_artifact']);
                }
            } else {
                $this->assertSame('fail', $r['observed_status']);
                $this->assertSame('forbidden_files_touched', $r['observed_evidence_artifact']);
                $this->assertSame('expected_artifact_missing_or_failed', $r['observed_reason']);
            }
        }
    }

    public function test_non_blocking_fail_does_not_abort(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $inputs['continuation_summary_check'] = [
            'status' => 'fail',
            'evidence_artifact' => 'summary_missing',
            'detail' => [],
        ];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('failed', $result['overall_status']);
        $this->assertFalse($result['aborted']);
        $this->assertNull($result['aborted_at_gate']);
        $this->assertContains('continuation_summary_check', $result['failed_gate_ids']);
    }

    public function test_warn_only_yields_passed_with_warnings(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $inputs['docs_health'] = ['status' => 'warn', 'evidence_artifact' => 'oversized_count_3', 'detail' => []];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('passed_with_warnings', $result['overall_status']);
        $this->assertContains('docs_health', $result['warn_gate_ids']);
        $this->assertSame(1, $result['counts']['warn']);
    }

    public function test_missing_synthetic_input_yields_unknown_and_aborts_when_blocking(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['scope_check', 'unit_tests']]);
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, []);
        $this->assertSame('failed', $result['overall_status']);
        $this->assertTrue($result['aborted']);
        $this->assertSame('scope_check', $result['aborted_at_gate']);
        $this->assertContains('scope_check', $result['unknown_gate_ids']);
        $this->assertContains('unit_tests', $result['skipped_gate_ids']);
    }

    public function test_unknown_status_value_treated_as_unknown(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['continuation_summary_check']]);
        $inputs = ['continuation_summary_check' => ['status' => 'banana', 'evidence_artifact' => 'x']];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('inconclusive', $result['overall_status']);
        $this->assertSame(1, $result['counts']['unknown']);
        $this->assertSame('unsupported_synthetic_status', $result['evaluations'][0]['observed_reason']);
    }

    public function test_each_result_carries_canonical_fields(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        foreach ($result['evaluations'] as $r) {
            $this->assertIsString($r['gate_id']);
            $this->assertIsString($r['gate_type']);
            $this->assertIsString($r['severity']);
            $this->assertIsBool($r['blocking']);
            $this->assertIsString($r['expected_artifact']);
            $this->assertIsString($r['observed_status']);
            $this->assertIsString($r['observed_reason']);
            $this->assertIsString($r['observed_evidence_artifact']);
            $this->assertIsArray($r['detail']);
            $this->assertIsBool($r['is_failure']);
            $this->assertIsBool($r['is_skip']);
            $this->assertIsBool($r['is_unknown']);
            $this->assertIsBool($r['is_warn']);
            $this->assertIsBool($r['is_pass']);
        }
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $eval = new AgentValidationGateDryRunEvaluator;
        $result = $eval->evaluate(['ordered_runs' => []]);
        $rs = $result['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['runtime_write_allowed']);
    }

    public function test_evaluation_hash_stable_and_changes_with_status(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $eval = new AgentValidationGateDryRunEvaluator;
        $a = $eval->evaluate($plan, $this->allPassInputs($plan));
        $b = $eval->evaluate($plan, $this->allPassInputs($plan));
        $this->assertSame($a['evaluation_hash'], $b['evaluation_hash']);
        $altered = $this->allPassInputs($plan);
        $altered['unit_tests'] = ['status' => 'warn', 'evidence_artifact' => 'flaky', 'detail' => []];
        $c = $eval->evaluate($plan, $altered);
        $this->assertNotSame($a['evaluation_hash'], $c['evaluation_hash']);
    }

    public function test_status_field_present_after_evaluation(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['continuation_summary_check']]);
        $inputs = ['continuation_summary_check' => ['status' => 'pass', 'evidence_artifact' => 'ok']];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('passed', $result['overall_status']);
        $this->assertSame('evaluated', $result['status']);
        $this->assertSame(1, $result['counts']['pass']);
    }

    public function test_all_skipped_yields_all_skipped_overall(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['continuation_summary_check']]);
        $inputs = ['continuation_summary_check' => ['status' => 'skip', 'evidence_artifact' => 'no_change']];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame('all_skipped', $result['overall_status']);
        $this->assertSame(1, $result['counts']['skip']);
    }

    public function test_fail_gate_marks_is_failure_true(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['unit_tests']]);
        $inputs = ['unit_tests' => ['status' => 'fail', 'evidence_artifact' => 'tests_red']];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertTrue($result['evaluations'][0]['is_failure']);
        $this->assertFalse($result['evaluations'][0]['is_pass']);
        $this->assertFalse($result['evaluations'][0]['is_skip']);
        $this->assertFalse($result['evaluations'][0]['is_warn']);
        $this->assertFalse($result['evaluations'][0]['is_unknown']);
    }

    public function test_detail_is_preserved(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan(['requested_gates' => ['scope_check']]);
        $inputs = ['scope_check' => [
            'status' => 'fail',
            'evidence_artifact' => 'forbidden_file',
            'detail' => ['files' => ['secret.env'], 'count' => 1],
        ]];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $this->assertSame(['files' => ['secret.env'], 'count' => 1], $result['evaluations'][0]['detail']);
    }

    public function test_counts_total_matches_evaluations(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $inputs = $this->allPassInputs($plan);
        $inputs['focused_tests'] = ['status' => 'warn', 'evidence_artifact' => 'flake'];
        $inputs['diff_check'] = ['status' => 'skip', 'evidence_artifact' => 'no_change'];
        $result = (new AgentValidationGateDryRunEvaluator)->evaluate($plan, $inputs);
        $total = array_sum($result['counts']);
        $this->assertSame(count($result['evaluations']), $total);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allPassInputs(array $plan): array
    {
        $out = [];
        foreach ($plan['ordered_runs'] as $run) {
            $out[$run['gate_id']] = [
                'status' => 'pass',
                'evidence_artifact' => $run['expected_artifact'],
                'detail' => [],
            ];
        }

        return $out;
    }
}
