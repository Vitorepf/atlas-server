<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateFailureClassifier;
use Tests\TestCase;

final class AgentValidationGateFailureClassifierTest extends TestCase
{
    private function c(): AgentValidationGateFailureClassifier
    {
        return new AgentValidationGateFailureClassifier;
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_constants_canonical(): void
    {
        $this->assertSame(
            AgentValidationGateFailureClassifier::SCHEMA_VERSION,
            (new AgentValidationGateFailureClassifier)->classify([])['schema_version'],
        );
        $this->assertContains('lint_error', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
        $this->assertContains('scope_violation', AgentValidationGateFailureClassifier::ALLOWED_CATEGORIES);
    }

    // ── Status-based classification ────────────────────────────────────

    public function test_pass_status_is_non_failure(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'pass']);

        $this->assertSame('non_failure', $r['category']);
        $this->assertFalse($r['is_failure_classification']);
    }

    public function test_skip_status_is_non_failure(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'skip']);

        $this->assertSame('non_failure', $r['category']);
        $this->assertFalse($r['is_failure_classification']);
    }

    public function test_unknown_status_classified_inconclusive(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'unknown']);

        $this->assertSame('inconclusive_signal', $r['category']);
        $this->assertTrue($r['is_failure_classification']);
    }

    // ── Gate-based classification ──────────────────────────────────────

    public function test_failure_categories_for_each_gate(): void
    {
        $fixtures = [
            ['gate_id' => 'php_lint', 'expected' => 'lint_error'],
            ['gate_id' => 'unit_tests', 'expected' => 'test_failure'],
            ['gate_id' => 'focused_tests', 'expected' => 'test_failure'],
            ['gate_id' => 'docs_health', 'expected' => 'docs_drift'],
            ['gate_id' => 'architecture_validate', 'expected' => 'architecture_violation'],
            ['gate_id' => 'diff_check', 'expected' => 'whitespace_or_merge_marker'],
            ['gate_id' => 'scope_check', 'expected' => 'scope_violation'],
            ['gate_id' => 'evidence_check', 'expected' => 'evidence_missing'],
            ['gate_id' => 'continuation_summary_check', 'expected' => 'continuation_missing'],
            ['gate_id' => 'rollback_plan_check', 'expected' => 'rollback_missing'],
        ];

        foreach ($fixtures as $f) {
            $r = $this->c()->classify(['gate_id' => $f['gate_id'], 'observed_status' => 'fail', 'blocking' => true]);
            $this->assertSame($f['expected'], $r['category'], "gate {$f['gate_id']} maps to {$f['expected']}");
            $this->assertTrue($r['is_failure_classification']);
        }
    }

    public function test_unknown_gate_id_classified_unknown(): void
    {
        $r = $this->c()->classify(['gate_id' => 'nonexistent_gate', 'observed_status' => 'fail', 'blocking' => true]);

        $this->assertSame('unknown', $r['category']);
        $this->assertTrue($r['is_terminal']);
    }

    public function test_warn_status_becomes_observe_and_proceed(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'warn']);

        $this->assertSame('lint_error', $r['category']);
        $this->assertSame('observe_and_proceed', $r['recovery_class']);
        $this->assertFalse($r['requires_human_review']);
    }

    // ── Signal format ──────────────────────────────────────────────────

    public function test_signal_format(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'fail']);

        $this->assertSame('fail:lint_error', $r['signal']);
    }

    // ── classifyMany ───────────────────────────────────────────────────

    public function test_classify_many_aggregates(): void
    {
        $r = $this->c()->classifyMany([
            ['gate_id' => 'php_lint', 'observed_status' => 'pass'],
            ['gate_id' => 'unit_tests', 'observed_status' => 'fail', 'blocking' => true],
            ['gate_id' => 'scope_check', 'observed_status' => 'fail', 'blocking' => true],
        ]);

        $this->assertSame('classified', $r['status']);
        $this->assertSame(3, $r['total_count']);
        $this->assertSame(2, $r['failure_count']);
        $this->assertArrayHasKey('classification_hash', $r);
    }

    public function test_classify_many_empty(): void
    {
        $r = $this->c()->classifyMany([]);

        $this->assertSame(0, $r['total_count']);
        $this->assertSame(0, $r['failure_count']);
        $this->assertSame([], $r['classifications']);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $r = $this->c()->classifyMany([]);

        $this->assertTrue($r['runtime_safety']['runtime_safety_all_false']);
        $this->assertFalse($r['runtime_safety']['execution_allowed']);
        $this->assertFalse($r['runtime_safety']['dispatch_allowed']);
    }

    public function test_single_classification_carries_runtime_safety_flag(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'pass']);

        $this->assertTrue($r['runtime_safety_all_false']);
    }

    // ── Severity and blocking ──────────────────────────────────────────

    public function test_inconclusive_signal_blocking_severity_bumps(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'unknown', 'severity' => 'medium', 'blocking' => true]);

        $this->assertSame('inconclusive_signal', $r['category']);
        $this->assertSame('high', $r['severity']);
    }

    public function test_inconclusive_signal_low_severity_bumps(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'unknown', 'severity' => 'low', 'blocking' => true]);

        $this->assertSame('medium', $r['severity']);
    }

    // ── Hash stability ─────────────────────────────────────────────────

    public function test_classification_hash_changes_with_input(): void
    {
        $a = $this->c()->classifyMany([['gate_id' => 'php_lint', 'observed_status' => 'fail', 'blocking' => true]]);
        $b = $this->c()->classifyMany([['gate_id' => 'unit_tests', 'observed_status' => 'fail', 'blocking' => true]]);

        $this->assertNotSame($a['classification_hash'], $b['classification_hash']);
    }

    // ── Non-failure ────────────────────────────────────────────────────

    public function test_pass_does_not_count_as_failure(): void
    {
        $r = $this->c()->classifyMany([
            ['gate_id' => 'php_lint', 'observed_status' => 'pass'],
            ['gate_id' => 'unit_tests', 'observed_status' => 'pass'],
        ]);

        $this->assertSame(0, $r['failure_count']);
    }

    // ── Supply impact ──────────────────────────────────────────────────

    public function test_supply_blocking_gate_id_labels_queue_supply_blocker(): void
    {
        $r = $this->c()->classify(['gate_id' => 'packet_admission_gate', 'observed_status' => 'fail', 'blocking' => true]);

        $this->assertSame('queue_supply_blocker', $r['supply_impact']);
    }

    public function test_ordinary_test_failure_labels_isolated_implementation_failure(): void
    {
        $r = $this->c()->classify(['gate_id' => 'unit_tests', 'observed_status' => 'fail', 'blocking' => true]);

        $this->assertSame('isolated_implementation_failure', $r['supply_impact']);
    }

    public function test_explicit_blocks_supply_flag_overrides_gate_id_lookup(): void
    {
        $r = $this->c()->classify(['gate_id' => 'unit_tests', 'observed_status' => 'fail', 'blocking' => true, 'blocks_supply' => true]);

        $this->assertSame('queue_supply_blocker', $r['supply_impact']);
    }

    public function test_supply_blocker_severity_is_escalated_above_medium(): void
    {
        $r = $this->c()->classify(['gate_id' => 'packet_admission_gate', 'observed_status' => 'fail', 'severity' => 'low', 'blocking' => true]);

        $this->assertSame('high', $r['severity'], 'supply blocker must escalate severity to at least high');
    }

    public function test_non_failure_status_has_not_applicable_supply_impact(): void
    {
        $r = $this->c()->classify(['gate_id' => 'php_lint', 'observed_status' => 'pass']);

        $this->assertSame('not_applicable', $r['supply_impact']);
    }
}
