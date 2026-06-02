<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorFalseLearningGateEvaluator;
use PHPUnit\Framework\TestCase;

final class AemorFalseLearningGateEvaluatorTest extends TestCase
{
    private function evaluator(): AemorFalseLearningGateEvaluator
    {
        return new AemorFalseLearningGateEvaluator;
    }

    public function test_clean_success_with_evidence_and_tests_passes(): void
    {
        $result = $this->evaluator()->evaluate(true, 'succeeded', true, 0, false);

        $this->assertSame('pass', $result['status']);
        $this->assertTrue($result['learning_allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_success_without_passing_tests_is_blocked(): void
    {
        $result = $this->evaluator()->evaluate(true, 'succeeded', null, 0, false);

        $this->assertContains('success_without_test_or_gate_evidence', $result['blockers']);
        $this->assertFalse($result['learning_allowed']);
    }

    public function test_missing_evidence_and_unreviewed_alternatives_yield_exactly_two_blockers(): void
    {
        $result = $this->evaluator()->evaluate(false, 'failed', false, 2, false);

        $this->assertContains('missing_evidence_refs', $result['blockers']);
        $this->assertContains('alternative_explanations_not_reviewed', $result['blockers']);
        $this->assertCount(2, $result['blockers']);
        $this->assertSame('blocked_for_learning', $result['status']);
    }

    public function test_failed_outcome_does_not_require_success_evidence(): void
    {
        $result = $this->evaluator()->evaluate(true, 'failed', false, 0, true);

        $this->assertNotContains('success_without_test_or_gate_evidence', $result['blockers']);
        $this->assertSame('pass', $result['status']);
    }

    public function test_schema_version_is_the_canonical_literal(): void
    {
        $result = $this->evaluator()->evaluate(true, 'succeeded', true, 0, false);

        $this->assertSame('atlas.aemor.false_learning_gate.v1', $result['schema_version']);
    }

    // --- Generalisation guards (inputs beyond the enumerated rows) ---

    public function test_missing_evidence_alone_blocks_a_failed_outcome(): void
    {
        // R1 only: no evidence; failure means R2 inert; no alternatives means R3 inert.
        $result = $this->evaluator()->evaluate(false, 'failed', true, 0, false);

        $this->assertSame(['missing_evidence_refs'], $result['blockers']);
        $this->assertSame('blocked_for_learning', $result['status']);
        $this->assertFalse($result['learning_allowed']);
    }

    public function test_reviewed_attribution_clears_alternative_explanation_blocker(): void
    {
        // R3 must NOT fire when attribution was reviewed, even with alternatives present.
        $result = $this->evaluator()->evaluate(true, 'failed', false, 5, true);

        $this->assertNotContains('alternative_explanations_not_reviewed', $result['blockers']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('pass', $result['status']);
        $this->assertTrue($result['learning_allowed']);
    }

    public function test_unreviewed_alternatives_block_when_count_positive(): void
    {
        // R3 fires on count > 0 with unreviewed attribution; evidence present, failed outcome.
        $result = $this->evaluator()->evaluate(true, 'failed', null, 1, false);

        $this->assertSame(['alternative_explanations_not_reviewed'], $result['blockers']);
        $this->assertSame('blocked_for_learning', $result['status']);
    }

    public function test_succeeded_with_false_tests_blocks_on_success_evidence(): void
    {
        // R2 fires when status succeeded and testsPassed is false (not only null).
        $result = $this->evaluator()->evaluate(true, 'succeeded', false, 0, false);

        $this->assertSame(['success_without_test_or_gate_evidence'], $result['blockers']);
        $this->assertFalse($result['learning_allowed']);
    }

    public function test_all_three_rules_can_fire_together(): void
    {
        // No evidence (R1) + succeeded without passing tests (R2) + unreviewed alternatives (R3).
        $result = $this->evaluator()->evaluate(false, 'succeeded', null, 3, false);

        $this->assertSame(
            [
                'missing_evidence_refs',
                'success_without_test_or_gate_evidence',
                'alternative_explanations_not_reviewed',
            ],
            $result['blockers']
        );
        $this->assertCount(3, $result['blockers']);
        $this->assertSame('blocked_for_learning', $result['status']);
        $this->assertFalse($result['learning_allowed']);
    }

    public function test_zero_alternative_count_never_triggers_review_blocker(): void
    {
        // Boundary: alternativeExplanationCount == 0 must keep R3 inert regardless of review flag.
        $result = $this->evaluator()->evaluate(true, 'failed', true, 0, false);

        $this->assertNotContains('alternative_explanations_not_reviewed', $result['blockers']);
        $this->assertSame('pass', $result['status']);
    }

    public function test_blockers_field_is_a_list_of_strings(): void
    {
        $result = $this->evaluator()->evaluate(false, 'succeeded', null, 2, false);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
    }
}
