<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateFailureClassifier;
use Tests\TestCase;

final class AgentValidationGateFailureClassifierTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_failure_classification.v1', AgentValidationGateFailureClassifier::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_failure_classification', AgentValidationGateFailureClassifier::MODE);
        $this->assertContains('scope_violation', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('evidence_missing', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('rollback_missing', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('test_failure', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('non_failure', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('unknown', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
    }

    public function test_pass_status_is_non_failure(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('unit_tests', 'pass'));
        $this->assertSame('non_failure', $c['category']);
        $this->assertSame('no_repair_required', $c['recovery_class']);
        $this->assertFalse($c['is_failure_classification']);
        $this->assertFalse($c['requires_human_review']);
    }

    public function test_skip_status_is_non_failure(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('unit_tests', 'skip'));
        $this->assertSame('non_failure', $c['category']);
        $this->assertSame('gate_skipped_by_condition', $c['signal']);
    }

    public function test_unknown_status_classified_inconclusive(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('unit_tests', 'unknown'));
        $this->assertSame('inconclusive_signal', $c['category']);
        $this->assertSame('investigate', $c['recovery_class']);
        $this->assertTrue($c['is_failure_classification']);
    }

    public function test_failure_categories_for_each_gate(): void
    {
        $matrix = [
            ['php_lint', 'lint_error', 'autofix_then_revalidate', false],
            ['unit_tests', 'test_failure', 'repair_test_or_implementation_inside_scope', false],
            ['focused_tests', 'test_failure', 'repair_test_or_implementation_inside_scope', false],
            ['docs_health', 'docs_drift', 'repair_docs_inside_scope', false],
            ['architecture_validate', 'architecture_violation', 'restore_invariant_inside_layer', true],
            ['diff_check', 'whitespace_or_merge_marker', 'autoclean_whitespace_or_resolve_marker', false],
            ['scope_check', 'scope_violation', 'revert_change_outside_scope', true],
            ['evidence_check', 'evidence_missing', 'attach_evidence_to_receipt', true],
            ['continuation_summary_check', 'continuation_missing', 'rebuild_continuation_summary', false],
            ['rollback_plan_check', 'rollback_missing', 'attach_rollback_plan_to_receipt', true],
        ];
        $classifier = new AgentValidationGateFailureClassifier;
        foreach ($matrix as [$gateId, $expectedCategory, $expectedRecovery, $expectedHuman]) {
            $c = $classifier->classify($this->fixture($gateId, 'fail'));
            $this->assertSame($expectedCategory, $c['category'], "category mismatch for {$gateId}");
            $this->assertSame($expectedRecovery, $c['recovery_class'], "recovery mismatch for {$gateId}");
            $this->assertSame($expectedHuman, $c['requires_human_review'], "human-review mismatch for {$gateId}");
            $this->assertTrue($c['is_failure_classification'], "failure flag mismatch for {$gateId}");
        }
    }

    public function test_unknown_gate_id_classified_unknown(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('mystery_gate', 'fail'));
        $this->assertSame('unknown', $c['category']);
        $this->assertSame('manual_review', $c['recovery_class']);
        $this->assertTrue($c['is_terminal']);
    }

    public function test_warn_status_becomes_observe_and_proceed(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('docs_health', 'warn'));
        $this->assertSame('docs_drift', $c['category']);
        $this->assertSame('observe_and_proceed', $c['recovery_class']);
        $this->assertFalse($c['requires_human_review']);
    }

    public function test_signal_format(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('scope_check', 'fail'));
        $this->assertSame('fail:scope_violation', $c['signal']);
    }

    public function test_classify_many_aggregates(): void
    {
        $results = [
            $this->fixture('php_lint', 'fail'),
            $this->fixture('unit_tests', 'pass'),
            $this->fixture('docs_health', 'warn'),
            $this->fixture('scope_check', 'fail'),
            $this->fixture('continuation_summary_check', 'fail'),
        ];
        $out = (new AgentValidationGateFailureClassifier)->classifyMany($results);
        $this->assertSame('classified', $out['status']);
        $this->assertSame(5, $out['total_count']);
        $this->assertSame(4, $out['failure_count']);
        $this->assertArrayHasKey('lint_error', $out['category_counts']);
        $this->assertArrayHasKey('scope_violation', $out['category_counts']);
        $this->assertArrayHasKey('continuation_missing', $out['category_counts']);
        $this->assertArrayHasKey('docs_drift', $out['category_counts']);
        $this->assertArrayHasKey('non_failure', $out['category_counts']);
        $this->assertSame(5, count($out['classifications']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $out['classification_hash']);
    }

    public function test_classify_many_empty(): void
    {
        $out = (new AgentValidationGateFailureClassifier)->classifyMany([]);
        $this->assertSame(0, $out['total_count']);
        $this->assertSame(0, $out['failure_count']);
        $this->assertSame([], $out['classifications']);
        $this->assertSame([], $out['category_counts']);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $out = (new AgentValidationGateFailureClassifier)->classifyMany([]);
        $rs = $out['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
    }

    public function test_single_classification_carries_runtime_safety_flag(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify($this->fixture('scope_check', 'fail'));
        $this->assertTrue($c['runtime_safety_all_false']);
    }

    public function test_inconclusive_signal_blocking_severity_bumps(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify(
            $this->fixture('continuation_summary_check', 'unknown', 'medium', false)
        );
        $this->assertSame('inconclusive_signal', $c['category']);
        $this->assertSame('high', $c['severity']);
    }

    public function test_inconclusive_signal_low_severity_bumps(): void
    {
        $c = (new AgentValidationGateFailureClassifier)->classify(
            $this->fixture('php_lint', 'unknown', 'low', true)
        );
        $this->assertSame('medium', $c['severity']);
    }

    public function test_classification_hash_changes_with_input(): void
    {
        $c = new AgentValidationGateFailureClassifier;
        $a = $c->classifyMany([$this->fixture('php_lint', 'fail')]);
        $b = $c->classifyMany([$this->fixture('php_lint', 'fail')]);
        $this->assertSame($a['classification_hash'], $b['classification_hash']);
        $c2 = $c->classifyMany([$this->fixture('scope_check', 'fail')]);
        $this->assertNotSame($a['classification_hash'], $c2['classification_hash']);
    }

    public function test_pass_does_not_count_as_failure(): void
    {
        $out = (new AgentValidationGateFailureClassifier)->classifyMany([
            $this->fixture('php_lint', 'pass'),
            $this->fixture('docs_health', 'pass'),
        ]);
        $this->assertSame(0, $out['failure_count']);
        $this->assertSame(2, $out['total_count']);
    }

    /** @return array<string,mixed> */
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
