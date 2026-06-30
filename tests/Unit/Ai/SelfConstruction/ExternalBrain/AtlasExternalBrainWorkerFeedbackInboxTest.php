<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerFeedbackInbox;
use Tests\TestCase;

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

    // ── success with evidence ─────────────────────────────────────────────────

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

    // ── success with too-terse note ───────────────────────────────────────────

    public function test_success_with_terse_note_needs_review_even_with_evidence(): void
    {
        $result = $this->inbox()->normalize($this->note('success', ['note' => 'done']));

        $this->assertTrue($result['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_MEDIUM, $result['confidence']);
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
            $this->note('give_back', ['note' => 'Scope too wide, cannot fit in allowed_files.']),  // → requires_action
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
}
