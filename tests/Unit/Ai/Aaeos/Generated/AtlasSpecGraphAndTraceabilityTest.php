<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSpecGraphAndTraceabilityService;
use Tests\TestCase;

/**
 * Pins the four concrete contracts of the Spec Graph & Traceability doc:
 *   1. the 11-node Spec Graph chain in documented order (and chain validation
 *      rejects skipped / reordered links);
 *   2. the six Required Questions, answerable only with positive proof;
 *   3. the seven-field Minimum Traceability Row (incomplete row = not traceable);
 *   4. the Promotion Rule — a critical requirement without traceable evidence
 *      blocks promotion; a non-critical gap is only a warning.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
 */
class AtlasSpecGraphAndTraceabilityTest extends TestCase
{
    private function service(): AtlasSpecGraphAndTraceabilityService
    {
        return new AtlasSpecGraphAndTraceabilityService;
    }

    /** A complete, valid Minimum Traceability Row from the doc's example. */
    private function completeRow(): array
    {
        return [
            'spec_id' => 'SPEC-001',
            'requirement_id' => 'R1',
            'acceptance_criteria_id' => 'AC1',
            'task_id' => 'T2',
            'file_path' => 'ProfileForm.tsx',
            'test_path' => 'ProfileForm.test.tsx',
            'evidence_event_id' => 'EVT-001',
        ];
    }

    public function test_spec_graph_chain_is_the_documented_eleven_nodes_in_order(): void
    {
        $chain = $this->service()->graphChain();

        $this->assertSame([
            'user_intent',
            'interpreted_goal',
            'requirement',
            'acceptance_criteria',
            'plan',
            'task',
            'file',
            'test',
            'evidence_event',
            'decision',
            'learning_proposal',
        ], $chain);

        // Adjacency is the documented order.
        $this->assertSame('interpreted_goal', $this->service()->nextNode('user_intent'));
        $this->assertSame('learning_proposal', $this->service()->nextNode('decision'));
        $this->assertNull($this->service()->nextNode('learning_proposal')); // terminal
        $this->assertNull($this->service()->nextNode('not_a_node'));
    }

    public function test_validate_chain_accepts_ordered_path_and_rejects_skip_and_reorder(): void
    {
        $service = $this->service();

        $valid = $service->validateChain(['requirement', 'acceptance_criteria', 'plan', 'task']);
        $this->assertTrue($valid['valid']);
        $this->assertSame(AtlasSpecGraphAndTraceabilityService::VERDICT_VALID, $valid['verdict']);

        // Skipping a node (acceptance_criteria) is a break.
        $skip = $service->validateChain(['requirement', 'plan']);
        $this->assertFalse($skip['valid']);
        $this->assertSame('skipped_node', $skip['first_break']['reason']);
        $this->assertSame(['from' => 'requirement', 'to' => 'plan'], [
            'from' => $skip['first_break']['from'],
            'to' => $skip['first_break']['to'],
        ]);

        // Going backwards is a break.
        $back = $service->validateChain(['task', 'requirement']);
        $this->assertFalse($back['valid']);
        $this->assertSame('not_advancing', $back['first_break']['reason']);

        // Unknown node is rejected before order checks.
        $unknown = $service->validateChain(['requirement', 'mystery_node']);
        $this->assertFalse($unknown['valid']);
        $this->assertSame(['mystery_node'], $unknown['unknown_nodes']);
    }

    public function test_required_questions_are_the_six_documented_questions(): void
    {
        $questions = array_values($this->service()->requiredQuestions());

        $this->assertCount(6, $questions);
        $this->assertSame([
            'Which test proves this requirement?',
            'Which file implements this acceptance criterion?',
            'Which evidence proves the gate passed?',
            'Did the patch modify files outside receipt?',
            'Did code change without matching spec?',
            'Did spec change without test/evidence?',
        ], $questions);
    }

    public function test_all_questions_answerable_only_with_complete_row_and_proven_signals(): void
    {
        $service = $this->service();

        $proven = [
            'gate_passed' => true,
            'patch_within_receipt' => true,
            'code_matches_spec' => true,
            'spec_change_covered' => true,
        ];

        $full = $service->answerQuestions($this->completeRow(), $proven);
        $this->assertTrue($full['all_answerable']);
        $this->assertSame(6, $full['answered_count']);

        // Drop one integrity signal: that question alone becomes unanswerable.
        $missingSignal = $proven;
        $missingSignal['patch_within_receipt'] = false;
        $gated = $service->answerQuestions($this->completeRow(), $missingSignal);
        $this->assertFalse($gated['all_answerable']);
        $this->assertContains('patch_within_receipt', $gated['unanswered']);
        $this->assertContains('patch_within_receipt', $gated['detail']['patch_within_receipt']['unproven_signals']);

        // An absent signal (not supplied at all) is treated as not proven.
        $noSignals = $service->answerQuestions($this->completeRow(), []);
        $this->assertFalse($noSignals['all_answerable']);

        // Missing a row field also blocks the questions that need it.
        $row = $this->completeRow();
        unset($row['test_path']);
        $missingField = $service->answerQuestions($row, $proven);
        $this->assertContains('test_path', $missingField['detail']['which_test_proves_requirement']['missing_fields']);
        $this->assertFalse($missingField['all_answerable']);
    }

    public function test_minimum_traceability_row_requires_all_seven_fields(): void
    {
        $service = $this->service();

        $this->assertSame([
            'spec_id',
            'requirement_id',
            'acceptance_criteria_id',
            'task_id',
            'file_path',
            'test_path',
            'evidence_event_id',
        ], $service->rowFields());

        $good = $service->auditRow($this->completeRow());
        $this->assertTrue($good['traceable']);
        $this->assertSame(AtlasSpecGraphAndTraceabilityService::VERDICT_TRACEABLE, $good['verdict']);
        $this->assertSame([], $good['missing_fields']);

        // Missing evidence_event_id -> not traceable (the doc ties traceability to evidence).
        $row = $this->completeRow();
        unset($row['evidence_event_id']);
        $bad = $service->auditRow($row);
        $this->assertFalse($bad['traceable']);
        $this->assertSame(['evidence_event_id'], $bad['missing_fields']);

        // Whitespace-only field does not count as present.
        $blank = $this->completeRow();
        $blank['file_path'] = '   ';
        $blankAudit = $service->auditRow($blank);
        $this->assertContains('file_path', $blankAudit['missing_fields']);
        $this->assertFalse($blankAudit['traceable']);
    }

    public function test_promotion_blocked_when_critical_requirement_lacks_traceable_evidence(): void
    {
        $service = $this->service();
        $complete = $this->completeRow();

        // Two critical requirements, one fully traced, one missing evidence.
        $blocked = $service->evaluatePromotion([
            ['requirement_id' => 'R1', 'critical' => true, 'row' => $complete],
            ['requirement_id' => 'R2', 'critical' => true, 'row' => ['spec_id' => 'SPEC-001']],
        ]);

        $this->assertTrue($blocked['promotion_blocked']);
        $this->assertFalse($blocked['enterprise_complete']);
        $this->assertSame(AtlasSpecGraphAndTraceabilityService::VERDICT_BLOCKED, $blocked['verdict']);
        $this->assertSame(['R2'], $blocked['blocking_requirements']);
        $this->assertSame(2, $blocked['critical_total']);
        $this->assertSame(1, $blocked['critical_traced']);

        // A NON-critical requirement with the same gap is only a warning, never a blocker.
        $promotable = $service->evaluatePromotion([
            ['requirement_id' => 'R1', 'critical' => true, 'row' => $complete],
            ['requirement_id' => 'R3', 'critical' => false, 'row' => ['spec_id' => 'SPEC-001']],
        ]);

        $this->assertFalse($promotable['promotion_blocked']);
        $this->assertTrue($promotable['enterprise_complete']);
        $this->assertSame(AtlasSpecGraphAndTraceabilityService::VERDICT_PROMOTABLE, $promotable['verdict']);
        $this->assertSame([], $promotable['blocking_requirements']);
        $this->assertSame(['R3'], $promotable['warning_requirements']);
    }
}
