<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsOpenQuestionsService;
use Tests\TestCase;

/**
 * Pins the human-only close rule ("Regras para IA") and the OQ-1 + OQ-4 + OQ-5 +
 * OQ-7 promotion-hold quorum (Closing Note) from the Open Questions v1 doc.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
 */
class AtlasSelfConstructionOsOpenQuestionsTest extends TestCase
{
    private function service(): AtlasSelfConstructionOsOpenQuestionsService
    {
        return new AtlasSelfConstructionOsOpenQuestionsService;
    }

    /** A full operator submission that closes a question. */
    private function operatorClose(string ...$evidence): array
    {
        return [
            'answer' => 'Operator decision recorded.',
            'author_role' => 'operator',
            'evidence' => $evidence === [] ? ['receipt-001'] : $evidence,
        ];
    }

    public function test_default_snapshot_leaves_all_ten_questions_open_and_promotion_on_hold(): void
    {
        $snapshot = $this->service()->snapshot();

        // Doc lists exactly 10 questions; none closeable without operator input.
        $this->assertSame(10, $snapshot['question_count']);
        $this->assertSame(0, $snapshot['closed_count']);
        $this->assertSame(10, $snapshot['open_count']);
        $this->assertTrue($snapshot['all_open']);
        $this->assertTrue($snapshot['promotion_on_hold']);

        // Every related runtime is reported disabled while open.
        $this->assertCount(10, $snapshot['disabled_runtimes']);
        foreach ($snapshot['questions'] as $q) {
            $this->assertFalse($q['closed'], "Question {$q['id']} must be open by default");
            $this->assertTrue($q['runtime_disabled']);
        }
    }

    public function test_ai_authored_answer_with_evidence_cannot_close_a_question(): void
    {
        // "Regras para IA": IA pode propor evidencia, nao pode encerrar a questao.
        $result = $this->service()->evaluateQuestion('OQ-4', [
            'answer' => 'Here is the kill switch design.',
            'author_role' => 'ai',
            'evidence' => ['kill-switch-test-log'],
            'proposed_evidence' => ['kill-switch-test-log'],
        ]);

        $this->assertFalse($result['closed']);
        $this->assertFalse($result['authored_by_operator']);
        $this->assertTrue($result['runtime_disabled']);
        $this->assertSame('answer_not_authored_by_operator_ai_cannot_close', $result['blocking_reason']);
    }

    public function test_operator_answer_without_evidence_does_not_close(): void
    {
        $result = $this->service()->evaluateQuestion('OQ-5', [
            'answer' => 'Use per-session worktrees.',
            'author_role' => 'operator',
            'evidence' => [],
        ]);

        $this->assertFalse($result['closed']);
        $this->assertTrue($result['authored_by_operator']);
        $this->assertSame('answer_present_but_no_operator_evidence_attached', $result['blocking_reason']);
    }

    public function test_operator_answer_with_evidence_closes_question_and_enables_related_runtime(): void
    {
        $result = $this->service()->evaluateQuestion('OQ-3', $this->operatorClose('budget-receipt-2026-001'));

        $this->assertTrue($result['closed']);
        $this->assertFalse($result['runtime_disabled']);
        $this->assertSame('budget_guard', $result['related_runtime']);
        $this->assertSame('SC-OS-R-004', $result['related_risk']);
        $this->assertSame('closed_by_operator_with_evidence', $result['blocking_reason']);
    }

    public function test_promotion_stays_on_hold_until_full_quorum_oq1_oq4_oq5_oq7_closed(): void
    {
        $svc = $this->service();

        // Close three of the four mandatory questions — hold must persist on OQ-7.
        $submissions = [
            'OQ-1' => $this->operatorClose('promotion-record', 'dossier-hash', 'kill-switch-test'),
            'OQ-4' => $this->operatorClose('kill-switch-cmd-test'),
            'OQ-5' => $this->operatorClose('parallel-write-test'),
        ];

        $partial = $svc->evaluatePromotionHold($submissions);
        $this->assertTrue($partial['promotion_on_hold']);
        $this->assertFalse($partial['quorum_satisfied']);
        $this->assertSame(['OQ-7'], $partial['quorum_open']);
        $this->assertSame('mandatory_quorum_incomplete_promotion_stays_on_hold', $partial['reason']);

        // Now close OQ-7 too -> hold lifts.
        $submissions['OQ-7'] = $this->operatorClose('panel-design', 'desktop-smoke-test');
        $full = $svc->evaluatePromotionHold($submissions);
        $this->assertFalse($full['promotion_on_hold']);
        $this->assertTrue($full['quorum_satisfied']);
        $this->assertSame([], $full['quorum_open']);
    }

    public function test_closing_non_quorum_questions_does_not_lift_promotion_hold(): void
    {
        $svc = $this->service();

        // Close all SIX non-quorum questions; the four mandatory ones stay open.
        $submissions = [];
        foreach (['OQ-2', 'OQ-3', 'OQ-6', 'OQ-8', 'OQ-9', 'OQ-10'] as $qid) {
            $submissions[$qid] = $this->operatorClose('evidence-'.$qid);
        }

        $hold = $svc->evaluatePromotionHold($submissions);
        $this->assertTrue($hold['promotion_on_hold']);
        $this->assertSame(['OQ-1', 'OQ-4', 'OQ-5', 'OQ-7'], $hold['quorum_open']);

        // And the overall snapshot agrees: 6 closed, promotion still on hold.
        $snapshot = $svc->snapshot($submissions);
        $this->assertSame(6, $snapshot['closed_count']);
        $this->assertTrue($snapshot['promotion_on_hold']);
    }

    public function test_id_normalization_accepts_loose_forms(): void
    {
        $svc = $this->service();

        // "1", "oq-1", "OQ1" all resolve to the same OQ-1 question.
        $this->assertSame('OQ-1', $svc->evaluateQuestion('1')['id']);
        $this->assertSame('OQ-1', $svc->evaluateQuestion('oq-1')['id']);
        $this->assertSame('OQ-1', $svc->evaluateQuestion('OQ1')['id']);

        // Unknown question id is reported and its runtime stays disabled.
        $unknown = $svc->evaluateQuestion('OQ-99');
        $this->assertFalse($unknown['closed']);
        $this->assertTrue($unknown['runtime_disabled']);
        $this->assertSame('unknown_question_not_in_decision_inbox', $unknown['blocking_reason']);
    }
}
