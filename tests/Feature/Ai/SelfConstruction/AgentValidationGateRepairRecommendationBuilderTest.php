<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateFailureClassifier;
use App\Services\Ai\SelfConstruction\AgentValidationGateRepairRecommendationBuilder;
use Tests\TestCase;

final class AgentValidationGateRepairRecommendationBuilderTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_repair_recommendation.v1', AgentValidationGateRepairRecommendationBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_repair_recommendation', AgentValidationGateRepairRecommendationBuilder::MODE);
    }

    public function test_pass_classification_returns_no_repair_required(): void
    {
        $classification = (new AgentValidationGateFailureClassifier)->classify($this->fixture('unit_tests', 'pass'));
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $this->fixture('unit_tests', 'pass'));
        $this->assertSame('no_repair_required', $r['status']);
        $this->assertSame(['no_repair_required'], $r['steps']);
        $this->assertSame(['none'], $r['evidence_needed']);
        $this->assertSame('none', $r['escalation']);
        $this->assertFalse($r['requires_human_review']);
    }

    public function test_categories_produce_ordered_repair_steps(): void
    {
        $matrix = [
            'php_lint' => 'lint_error',
            'unit_tests' => 'test_failure',
            'focused_tests' => 'test_failure',
            'docs_health' => 'docs_drift',
            'architecture_validate' => 'architecture_violation',
            'diff_check' => 'whitespace_or_merge_marker',
            'scope_check' => 'scope_violation',
            'evidence_check' => 'evidence_missing',
            'continuation_summary_check' => 'continuation_missing',
            'rollback_plan_check' => 'rollback_missing',
        ];
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        foreach ($matrix as $gateId => $expectedCategory) {
            $gateResult = $this->fixture($gateId, 'fail');
            $classification = $classifier->classify($gateResult);
            $r = $builder->recommend($classification, $gateResult);
            $this->assertSame('recommendation_ready', $r['status'], "status for {$gateId}");
            $this->assertSame($expectedCategory, $r['category'], "category for {$gateId}");
            $this->assertNotSame([], $r['steps'], "steps for {$gateId}");
            $this->assertNotSame([], $r['forbidden_ops'], "forbidden_ops for {$gateId}");
            $this->assertNotSame([], $r['evidence_needed'], "evidence_needed for {$gateId}");
            $this->assertNotSame('', $r['escalation'], "escalation for {$gateId}");
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['recommendation_hash']);
        }
    }

    public function test_scope_violation_adds_specific_forbidden_ops(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $gateResult = $this->fixture('scope_check', 'fail');
        $classification = $classifier->classify($gateResult);
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $gateResult);
        $this->assertContains('edit_outside_allowed_files', $r['forbidden_ops']);
        $this->assertContains('edit_inside_forbidden_files', $r['forbidden_ops']);
        $this->assertTrue($r['requires_human_review']);
        $this->assertSame('human_review_before_retry', $r['escalation']);
    }

    public function test_evidence_missing_adds_receipt_hash_evidence(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $gateResult = $this->fixture('evidence_check', 'fail');
        $classification = $classifier->classify($gateResult);
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $gateResult);
        $this->assertContains('attach_receipt_hash_for_evidence_chain', $r['evidence_needed']);
        $this->assertTrue($r['requires_human_review']);
    }

    public function test_rollback_missing_adds_receipt_hash_evidence(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $gateResult = $this->fixture('rollback_plan_check', 'fail');
        $classification = $classifier->classify($gateResult);
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $gateResult);
        $this->assertContains('attach_receipt_hash_for_evidence_chain', $r['evidence_needed']);
    }

    public function test_inconclusive_signal_yields_investigation_steps(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $gateResult = $this->fixture('unit_tests', 'unknown');
        $classification = $classifier->classify($gateResult);
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $gateResult);
        $this->assertSame('inconclusive_signal', $r['category']);
        $this->assertContains('investigate_why_synthetic_input_missing_or_unsupported', $r['steps']);
    }

    public function test_unknown_category_yields_manual_review(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $gateResult = $this->fixture('mystery_gate', 'fail');
        $classification = $classifier->classify($gateResult);
        $r = (new AgentValidationGateRepairRecommendationBuilder)->recommend($classification, $gateResult);
        $this->assertSame('unknown', $r['category']);
        $this->assertSame(['manual_review'], $r['steps']);
    }

    public function test_recommend_many_aggregates(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResults = [
            $this->fixture('php_lint', 'fail'),
            $this->fixture('unit_tests', 'pass'),
            $this->fixture('scope_check', 'fail'),
            $this->fixture('rollback_plan_check', 'fail'),
        ];
        $classifications = array_map(static fn ($g) => $classifier->classify($g), $gateResults);
        $out = $builder->recommendMany($classifications, $gateResults, [
            'allowed_files' => ['a.php'],
            'forbidden_files' => ['secret.env'],
        ]);
        $this->assertSame('recommendations_built', $out['status']);
        $this->assertSame(4, $out['total_count']);
        $this->assertSame(3, $out['repair_count']);
        $this->assertSame(2, $out['human_required_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $out['recommendation_hash']);
        $this->assertSame(4, count($out['recommendations']));
        foreach ($out['recommendations'] as $r) {
            $this->assertSame(['a.php'], $r['allowed_files']);
        }
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $rs = $builder->recommendMany([], [])['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
    }

    public function test_each_recommendation_has_runtime_safety(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('php_lint', 'fail');
        $r = $builder->recommend($classifier->classify($gateResult), $gateResult);
        $rs = $r['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
    }

    public function test_baseline_forbidden_ops_present(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('docs_health', 'fail');
        $r = $builder->recommend($classifier->classify($gateResult), $gateResult);
        foreach ([
            'no_real_command_execution',
            'no_provider_call',
            'no_token_spend',
            'no_dispatch_runtime',
            'no_ledger_write',
            'no_self_programming',
            'no_pointer_mutation',
        ] as $op) {
            $this->assertContains($op, $r['forbidden_ops']);
        }
    }

    public function test_recommendation_hash_changes_with_category(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $a = $builder->recommend($classifier->classify($this->fixture('php_lint', 'fail')), $this->fixture('php_lint', 'fail'));
        $b = $builder->recommend($classifier->classify($this->fixture('scope_check', 'fail')), $this->fixture('scope_check', 'fail'));
        $this->assertNotSame($a['recommendation_hash'], $b['recommendation_hash']);
    }

    public function test_context_allowed_files_propagate(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('docs_health', 'fail');
        $r = $builder->recommend(
            $classifier->classify($gateResult),
            $gateResult,
            ['allowed_files' => ['x.md', 'y.md', 'x.md']]
        );
        $this->assertSame(['x.md', 'y.md'], $r['allowed_files']);
    }

    public function test_garbage_context_files_normalized_to_empty(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('docs_health', 'fail');
        $r = $builder->recommend(
            $classifier->classify($gateResult),
            $gateResult,
            ['allowed_files' => 'oops']
        );
        $this->assertSame([], $r['allowed_files']);
    }

    public function test_skip_classification_is_non_failure_recommendation(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('unit_tests', 'skip');
        $r = $builder->recommend($classifier->classify($gateResult), $gateResult);
        $this->assertSame('no_repair_required', $r['status']);
    }

    public function test_warn_classification_yields_recommendation_ready_with_observe_recovery(): void
    {
        $classifier = new AgentValidationGateFailureClassifier;
        $builder = new AgentValidationGateRepairRecommendationBuilder;
        $gateResult = $this->fixture('docs_health', 'warn');
        $classification = $classifier->classify($gateResult);
        $r = $builder->recommend($classification, $gateResult);
        $this->assertSame('recommendation_ready', $r['status']);
        $this->assertSame('observe_and_proceed', $r['recovery_class']);
    }

    /** @return array<string, mixed> */
    private function fixture(string $gateId, string $status, string $severity = 'high', bool $blocking = true): array
    {
        return [
            'gate_id' => $gateId,
            'gate_type' => 'test',
            'severity' => $severity,
            'blocking' => $blocking,
            'expected_artifact' => 'expected_'.$gateId,
            'observed_status' => $status,
            'observed_reason' => 'synthetic',
            'observed_evidence_artifact' => 'synthetic_'.$gateId,
            'detail' => [],
            'is_failure' => $status === 'fail',
            'is_skip' => $status === 'skip',
            'is_unknown' => $status === 'unknown',
            'is_warn' => $status === 'warn',
            'is_pass' => $status === 'pass',
        ];
    }
}
