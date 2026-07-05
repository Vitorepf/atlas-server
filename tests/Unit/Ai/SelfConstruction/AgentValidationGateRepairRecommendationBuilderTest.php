<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateRepairRecommendationBuilder;
use Tests\TestCase;

/**
 * Unit tests for the hardened AgentValidationGateRepairRecommendationBuilder:
 * owner-specific repair actions, create_new_task flag, non-retryable failure
 * detection, and recommendMany() deduplication.
 */
final class AgentValidationGateRepairRecommendationBuilderTest extends TestCase
{
    private function builder(): AgentValidationGateRepairRecommendationBuilder
    {
        return new AgentValidationGateRepairRecommendationBuilder;
    }

    private function failureClassification(string $category, string $gateId = 'unit_tests', bool $requiresHuman = false): array
    {
        return [
            'gate_id' => $gateId,
            'category' => $category,
            'recovery_class' => 'retry',
            'severity' => 'high',
            'requires_human_review' => $requiresHuman,
            'is_failure_classification' => true,
        ];
    }

    // ── AC: recommend() maps each failure class to owner, evidence, create_new_task ─

    public function test_recommend_includes_owner_field(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('test_failure'),
            ['gate_id' => 'unit_tests'],
        );
        $this->assertArrayHasKey('owner', $r);
        $this->assertSame('worker', $r['owner']);
    }

    public function test_recommend_includes_create_new_task_field(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('test_failure'),
            ['gate_id' => 'unit_tests'],
        );
        $this->assertArrayHasKey('create_new_task', $r);
        $this->assertFalse($r['create_new_task']);
    }

    public function test_recommend_includes_retryable_field(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('test_failure'),
            ['gate_id' => 'unit_tests'],
        );
        $this->assertArrayHasKey('retryable', $r);
        $this->assertTrue($r['retryable']);
    }

    public function test_scope_violation_creates_new_task(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('scope_violation'),
            ['gate_id' => 'scope_check'],
        );
        $this->assertTrue($r['create_new_task']);
    }

    public function test_architecture_violation_creates_new_task_and_is_not_retryable(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('architecture_violation'),
            ['gate_id' => 'architecture_validate'],
        );
        $this->assertTrue($r['create_new_task']);
        $this->assertFalse($r['retryable']);
    }

    // ── AC: non-retryable failures ───────────────────────────────────────────

    public function test_forbidden_scope_is_not_retryable(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('forbidden_scope'),
            ['gate_id' => 'scope_check'],
        );
        $this->assertFalse($r['retryable']);
        $this->assertNotContains('retry_inside_same_session', [$r['escalation']]);
        $this->assertSame('no_retry_non_retryable_failure', $r['escalation']);
    }

    public function test_missing_implementation_target_is_not_retryable(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('missing_implementation_target'),
            ['gate_id' => 'unit_tests'],
        );
        $this->assertFalse($r['retryable']);
        $this->assertSame('no_retry_non_retryable_failure', $r['escalation']);
    }

    public function test_operator_only_evidence_is_not_retryable(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('operator_only_evidence'),
            ['gate_id' => 'evidence_check'],
        );
        $this->assertFalse($r['retryable']);
        $this->assertSame('no_retry_non_retryable_failure', $r['escalation']);
    }

    public function test_non_retryable_failures_do_not_produce_retry_steps(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('forbidden_scope'),
            ['gate_id' => 'scope_check'],
        );
        foreach ($r['steps'] as $step) {
            $this->assertStringNotContainsString('rerun', $step);
            $this->assertStringNotContainsString('retry', $step);
        }
    }

    public function test_non_retryable_forbidden_scope_steps_acknowledge_boundary(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('forbidden_scope'),
            ['gate_id' => 'scope_check'],
        );
        $this->assertContains('acknowledge_scope_boundary', $r['steps']);
        $this->assertContains('request_operator_to_redefine_allowed_files', $r['steps']);
    }

    // ── AC: recommendMany() deduplicates ────────────────────────────────────

    public function test_recommend_many_deduplicates_equivalent_repairs(): void
    {
        $classifications = [
            $this->failureClassification('test_failure', 'unit_tests'),
            $this->failureClassification('test_failure', 'focused_tests'),
        ];
        $gateResults = [
            ['gate_id' => 'unit_tests'],
            ['gate_id' => 'focused_tests'],
        ];

        $result = $this->builder()->recommendMany($classifications, $gateResults);

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(1, $result['unique_repair_count']);
    }

    public function test_recommend_many_preserves_different_repairs(): void
    {
        $classifications = [
            $this->failureClassification('test_failure', 'unit_tests'),
            $this->failureClassification('lint_error', 'php_lint'),
        ];
        $gateResults = [
            ['gate_id' => 'unit_tests'],
            ['gate_id' => 'php_lint'],
        ];

        $result = $this->builder()->recommendMany($classifications, $gateResults);

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(2, $result['unique_repair_count']);
    }

    public function test_recommend_many_includes_unique_repair_count(): void
    {
        $result = $this->builder()->recommendMany([], []);
        $this->assertArrayHasKey('unique_repair_count', $result);
        $this->assertSame(0, $result['unique_repair_count']);
    }

    // ── owner mapping ────────────────────────────────────────────────────────

    public function test_owner_for_inconclusive_signal_is_human(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('inconclusive_signal'),
            ['gate_id' => 'unit_tests'],
        );
        $this->assertSame('human', $r['owner']);
    }

    public function test_owner_for_lint_error_is_worker(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('lint_error'),
            ['gate_id' => 'php_lint'],
        );
        $this->assertSame('worker', $r['owner']);
    }

    public function test_owner_for_forbidden_scope_is_human(): void
    {
        $r = $this->builder()->recommend(
            $this->failureClassification('forbidden_scope'),
            ['gate_id' => 'scope_check'],
        );
        $this->assertSame('human', $r['owner']);
    }

    // ── no_repair_required still has new fields ──────────────────────────────

    public function test_no_repair_required_includes_owner_and_create_new_task(): void
    {
        $r = $this->builder()->recommend(
            ['gate_id' => 'unit_tests', 'category' => 'pass', 'is_failure_classification' => false],
            ['gate_id' => 'unit_tests'],
        );
        $this->assertSame('none', $r['owner']);
        $this->assertFalse($r['create_new_task']);
        $this->assertTrue($r['retryable']);
    }
}
