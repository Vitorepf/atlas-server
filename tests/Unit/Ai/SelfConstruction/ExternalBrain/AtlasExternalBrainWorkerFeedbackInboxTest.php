<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerFeedbackInbox;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWorkerFeedbackInboxTest extends TestCase
{
    private function inbox(): AtlasExternalBrainWorkerFeedbackInbox
    {
        return new AtlasExternalBrainWorkerFeedbackInbox();
    }

    private function note(string $outcome, array $overrides = []): array
    {
        return array_merge([
            'task_id'      => 'task-001',
            'outcome_type' => $outcome,
            'note'         => 'All tests pass, implementation complete.',
            'evidence'     => 'vendor/bin/phpunit tests/Unit/Foo/BarTest.php GREEN',
            'task_family'  => 'external_brain',
            'worker_id'    => 'claude-muscle-3',
            'model_tier'   => 'small',
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_on_normalize(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::SCHEMA, $result['schema']);
    }

    public function test_schema_on_ingest(): void
    {
        $result = $this->inbox()->ingest([]);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::SCHEMA, $result['schema']);
    }

    // ── success with runnable evidence ────────────────────────────────────────

    public function test_success_with_evidence_is_verified_high_confidence(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_VERIFIED, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertFalse($result['needs_review']);
        $this->assertFalse($result['requires_action']);
    }

    // ── success without evidence ──────────────────────────────────────────────

    public function test_success_without_evidence_is_unverified_needs_review(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['evidence' => '']));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_UNVERIFIED, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_MEDIUM, $result['confidence']);
        $this->assertTrue($result['needs_review']);
        $this->assertFalse($result['requires_action']);
    }

    // ── AC2: shallow-success marked needs_review ──────────────────────────────

    public function test_success_with_terse_note_needs_review_even_with_evidence(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['note' => 'done']));

        $this->assertTrue($result['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_MEDIUM, $result['confidence']);
    }

    public function test_success_with_non_runnable_evidence_is_shallow_and_needs_review(): void
    {
        $result = $this->inbox()->normalize($this->note('success', [
            'evidence' => 'I ran the tests and they passed',
        ]));

        $this->assertTrue($result['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_UNVERIFIED, $result['evidence_status']);
    }

    // ── give_back ─────────────────────────────────────────────────────────────

    public function test_give_back_requires_action(): void
    {
        $result = $this->inbox()->normalize($this->note('give_back', [
            'note' => 'Dependency missing, cannot proceed.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_VERIFIED, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertTrue($result['requires_action']);
        $this->assertFalse($result['needs_review']);
    }

    public function test_give_back_terse_note_is_medium_confidence_needs_review(): void
    {
        $result = $this->inbox()->normalize($this->note('give_back', ['note' => 'nope']));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_MEDIUM, $result['confidence']);
        $this->assertTrue($result['needs_review']);
        $this->assertTrue($result['requires_action']);
    }

    public function test_give_back_normalized_reason_contains_note(): void
    {
        $result = $this->inbox()->normalize($this->note('give_back', [
            'note' => 'Dependency missing, cannot proceed.',
        ]));

        $this->assertStringContainsString('Dependency missing', $result['normalized_reason']);
    }

    // ── blocked ───────────────────────────────────────────────────────────────

    public function test_blocked_requires_action_high_confidence(): void
    {
        $result = $this->inbox()->normalize($this->note('blocked', [
            'note' => 'Database migration failed in CI environment.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_VERIFIED, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertTrue($result['requires_action']);
    }

    public function test_blocked_terse_note_needs_review(): void
    {
        $result = $this->inbox()->normalize($this->note('blocked', ['note' => 'fail']));

        $this->assertTrue($result['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_MEDIUM, $result['confidence']);
    }

    // ── ambiguous ─────────────────────────────────────────────────────────────

    public function test_ambiguous_is_needs_review_low_confidence(): void
    {
        $result = $this->inbox()->normalize($this->note('ambiguous', [
            'note' => 'Not sure if tests ran or if they passed correctly.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_NEEDS_REVIEW, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_LOW, $result['confidence']);
        $this->assertTrue($result['needs_review']);
        $this->assertFalse($result['requires_action']);
    }

    // ── unknown outcome type ──────────────────────────────────────────────────

    public function test_unknown_outcome_type_flagged_as_needs_review(): void
    {
        $result = $this->inbox()->normalize($this->note('unknown_type'));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_NEEDS_REVIEW, $result['evidence_status']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_LOW, $result['confidence']);
        $this->assertTrue($result['needs_review']);
    }

    // ── task_id preserved ─────────────────────────────────────────────────────

    public function test_task_id_preserved_in_fact(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['task_id' => 'task-xyz-42']));
        $this->assertSame('task-xyz-42', $result['task_id']);
    }

    // ── AC1: new pass-through routing fields ──────────────────────────────────

    public function test_task_family_worker_id_model_tier_passed_through(): void
    {
        $result = $this->inbox()->normalize($this->note('success', [
            'task_family' => 'gate_repair',
            'worker_id'   => 'codex-worker-7',
            'model_tier'  => 'frontier',
        ]));

        $this->assertSame('gate_repair',    $result['task_family']);
        $this->assertSame('codex-worker-7', $result['worker_id']);
        $this->assertSame('frontier',       $result['model_tier']);
    }

    public function test_output_has_evidence_strength_field(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));
        $this->assertArrayHasKey('evidence_strength', $result);
    }

    public function test_output_has_root_cause_hint_field(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));
        $this->assertArrayHasKey('root_cause_hint', $result);
    }

    public function test_output_has_routing_signal_field(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));
        $this->assertArrayHasKey('routing_signal', $result);
    }

    // ── AC1: evidence_strength values ─────────────────────────────────────────

    public function test_strong_evidence_when_runnable_and_non_terse(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::STRENGTH_STRONG, $result['evidence_strength']);
    }

    public function test_weak_evidence_when_runnable_but_terse_note(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['note' => 'ok']));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::STRENGTH_WEAK, $result['evidence_strength']);
    }

    public function test_none_evidence_when_no_evidence_provided(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['evidence' => '']));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::STRENGTH_NONE, $result['evidence_strength']);
    }

    public function test_weak_evidence_when_non_runnable_string(): void
    {
        $result = $this->inbox()->normalize($this->note('success', [
            'evidence' => 'I checked and it looked fine',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::STRENGTH_WEAK, $result['evidence_strength']);
    }

    // ── AC1: root_cause_hint ──────────────────────────────────────────────────

    public function test_root_cause_hint_null_for_verified_success(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));

        $this->assertNull($result['root_cause_hint']);
    }

    public function test_root_cause_hint_shallow_for_unverified_success(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['evidence' => '']));

        $this->assertSame('shallow_success_no_runnable_evidence', $result['root_cause_hint']);
    }

    public function test_root_cause_hint_scope_too_wide_for_scope_give_back(): void
    {
        $result = $this->inbox()->normalize($this->note('give_back', [
            'note' => 'Scope is too wide, cannot fit in allowed_files.',
        ]));

        $this->assertSame('scope_too_wide', $result['root_cause_hint']);
    }

    public function test_root_cause_hint_dependency_missing_for_blocked(): void
    {
        $result = $this->inbox()->normalize($this->note('blocked', [
            'note' => 'Required dependency is missing from composer.',
        ]));

        $this->assertSame('dependency_missing', $result['root_cause_hint']);
    }

    public function test_root_cause_hint_unclear_outcome_for_ambiguous(): void
    {
        $result = $this->inbox()->normalize($this->note('ambiguous', [
            'note' => 'Not sure what happened with the tests here.',
        ]));

        $this->assertSame('unclear_outcome', $result['root_cause_hint']);
    }

    // ── AC1: routing_signal ───────────────────────────────────────────────────

    public function test_routing_signal_compounding_for_verified_success(): void
    {
        $result = $this->inbox()->normalize($this->note('success'));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $result['routing_signal']);
    }

    public function test_routing_signal_review_queue_for_shallow_success(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['evidence' => '']));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_REVIEW_QUEUE, $result['routing_signal']);
    }

    public function test_routing_signal_give_back_repair_for_give_back(): void
    {
        $result = $this->inbox()->normalize($this->note('give_back', [
            'note' => 'Scope is too wide to complete.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_GIVE_BACK_REPAIR, $result['routing_signal']);
    }

    public function test_routing_signal_blocker_resolution_for_blocked(): void
    {
        $result = $this->inbox()->normalize($this->note('blocked', [
            'note' => 'CI environment is not available.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_BLOCKER_RESOLUTION, $result['routing_signal']);
    }

    public function test_routing_signal_triage_for_ambiguous(): void
    {
        $result = $this->inbox()->normalize($this->note('ambiguous', [
            'note' => 'Not sure what outcome to report here.',
        ]));

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_TRIAGE, $result['routing_signal']);
    }

    // ── ingest batch ─────────────────────────────────────────────────────────

    public function test_ingest_empty_batch(): void
    {
        $result = $this->inbox()->ingest([]);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['facts']);
        $this->assertSame(0, $result['needs_review']);
        $this->assertSame(0, $result['requires_action']);
    }

    public function test_ingest_counts_needs_review_and_requires_action(): void
    {
        $result = $this->inbox()->ingest([
            $this->note('success'),                          // verified → no needs_review, no action
            $this->note('success', ['evidence' => '']),      // unverified → needs_review
            $this->note('give_back', ['note' => 'Scope too wide, cannot fit in allowed_files.']),
            $this->note('ambiguous'),                        // → needs_review
        ]);

        $this->assertSame(4, $result['count']);
        $this->assertSame(2, $result['needs_review']);
        $this->assertSame(1, $result['requires_action']);
    }

    public function test_ingest_returns_all_facts(): void
    {
        $result = $this->inbox()->ingest([
            $this->note('success', ['task_id' => 't1']),
            $this->note('give_back', ['task_id' => 't2', 'note' => 'Scope too large for this task.']),
        ]);

        $ids = array_column($result['facts'], 'task_id');
        $this->assertSame(['t1', 't2'], $ids);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_note_yields_identical_fact(): void
    {
        $note = $this->note('success');
        $this->assertSame(
            $this->inbox()->normalize($note),
            $this->inbox()->normalize($note),
        );
    }

    // ── poison outcome ───────────────────────────────────────────────────────

    public function test_poison_requires_action(): void
    {
        $fact = $this->inbox()->normalize($this->note('poison', ['note' => 'Acceptance criteria are contradictory.']));

        $this->assertTrue($fact['requires_action']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_POISON_QUARANTINE, $fact['routing_signal']);
        $this->assertSame('contradictory_acceptance', $fact['root_cause_hint']);
    }

    public function test_poison_terse_note_needs_review(): void
    {
        $fact = $this->inbox()->normalize($this->note('poison', ['note' => 'bad']));

        $this->assertTrue($fact['needs_review']);
        $this->assertTrue($fact['requires_action']);
    }

    // ── weak_green outcome ───────────────────────────────────────────────────

    public function test_weak_green_requires_action_and_needs_review(): void
    {
        $fact = $this->inbox()->normalize($this->note('weak_green', ['note' => 'Tests pass but coverage is flaky.']));

        $this->assertTrue($fact['requires_action']);
        $this->assertTrue($fact['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_WEAK_GREEN_REVIEW, $fact['routing_signal']);
        $this->assertSame('flaky_or_partial_coverage', $fact['root_cause_hint']);
    }

    // ── ingest: counts by outcome_type and routing_signal ────────────────────

    public function test_ingest_includes_counts_by_outcome_type_and_routing_signal(): void
    {
        $result = $this->inbox()->ingest([
            $this->note('success'),
            $this->note('give_back', ['note' => 'Scope too wide for this worker.']),
            $this->note('poison', ['note' => 'Acceptance criteria are contradictory.']),
            $this->note('weak_green', ['note' => 'Tests pass but coverage is flaky.']),
        ]);

        $this->assertArrayHasKey('by_outcome_type', $result);
        $this->assertArrayHasKey('by_routing_signal', $result);
        $this->assertSame(1, $result['by_outcome_type']['success']);
        $this->assertSame(1, $result['by_outcome_type']['give_back']);
        $this->assertSame(1, $result['by_outcome_type']['poison']);
        $this->assertSame(1, $result['by_outcome_type']['weak_green']);
        $this->assertSame(1, $result['by_routing_signal'][AtlasExternalBrainWorkerFeedbackInbox::ROUTING_POISON_QUARANTINE]);
        $this->assertSame(1, $result['by_routing_signal'][AtlasExternalBrainWorkerFeedbackInbox::ROUTING_WEAK_GREEN_REVIEW]);
    }
}
