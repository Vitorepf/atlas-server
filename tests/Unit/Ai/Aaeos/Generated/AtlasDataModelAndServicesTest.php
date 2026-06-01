<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDataModelAndServicesService;
use Tests\TestCase;

/**
 * Pins the three concrete contracts of the Data Model & Services doc:
 * Required Questions answerability, the five Prohibitions, and the canonical
 * Service Boundaries pipeline shape.
 *
 * Pure logic — no database, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md
 */
final class AtlasDataModelAndServicesTest extends TestCase
{
    private AtlasDataModelAndServicesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDataModelAndServicesService;
    }

    /**
     * @return array<string,mixed>
     */
    private function fullTrace(array $overrides = []): array
    {
        return array_merge([
            'operation_id' => 'op_1',
            'requirement_id' => 'REQ-1',
            'file_path' => 'app/Services/Foo.php',
            'acceptance_criteria_id' => 'AC-1',
            'test_path' => 'tests/Unit/FooTest.php',
            'decision_receipt_id' => 'rcpt_1',
            'evidence_event_id' => 'evt_1',
            'assumption_ids' => ['ASM-1'],
            'drift_status' => 'clean',
        ], $overrides);
    }

    /** A fully-linked operation answers all seven Required Questions => auditable. */
    public function test_full_traceability_answers_all_seven_questions(): void
    {
        $r = $this->service->auditTraceability($this->fullTrace());

        $this->assertTrue($r['auditable']);
        $this->assertSame('auditable', $r['verdict']);
        $this->assertSame(7, $r['answered_count']);
        $this->assertSame(7, $r['total_questions']);
        $this->assertSame([], $r['unanswered']);
    }

    /** Missing the Decision Receipt link makes "which receipt authorized the action" unanswerable. */
    public function test_missing_receipt_link_makes_operation_not_auditable(): void
    {
        $trace = $this->fullTrace();
        unset($trace['decision_receipt_id']);

        $r = $this->service->auditTraceability($trace);

        $this->assertFalse($r['auditable']);
        $this->assertSame('not_auditable', $r['verdict']);
        $this->assertContains('which_receipt', $r['unanswered']);
        $this->assertContains('decision_receipt_id', $r['detail']['which_receipt']['missing_fields']);
    }

    /** "unknown" drift does not answer whether code/tests/spec drifted — the question stays unanswered. */
    public function test_unknown_drift_status_is_not_an_answer(): void
    {
        $r = $this->service->auditTraceability($this->fullTrace(['drift_status' => 'unknown']));

        $this->assertFalse($r['auditable']);
        $this->assertContains('drift_status', $r['unanswered']);
        $this->assertFalse($r['detail']['drift_status']['answerable']);
    }

    /** Prohibition P1: writing code with no Decision Receipt present is blocked. */
    public function test_execution_without_receipt_violates_bypass_prohibition(): void
    {
        $r = $this->service->checkProhibitions([
            'wrote_code' => true,
            'has_decision_receipt' => false,
        ]);

        $this->assertFalse($r['allowed']);
        $this->assertSame('blocked', $r['verdict']);
        $this->assertContains('receipt_bypassed', $r['violation_ids']);
    }

    /** Prohibitions P2 + P4: out-of-scope writes and a failed gate hidden by a post-hoc spec edit are both flagged. */
    public function test_out_of_scope_write_and_hidden_failed_gate_are_both_flagged(): void
    {
        $r = $this->service->checkProhibitions([
            'wrote_code' => true,
            'has_decision_receipt' => true,
            'files_outside_scope' => ['.env'],
            'gate_failed' => true,
            'spec_mutated_after_execution' => true,
        ]);

        $this->assertFalse($r['allowed']);
        $this->assertContains('write_outside_receipt_scope', $r['violation_ids']);
        $this->assertContains('failed_gate_hidden_by_spec_mutation', $r['violation_ids']);
        // A receipt was present, so P1 must NOT fire.
        $this->assertNotContains('receipt_bypassed', $r['violation_ids']);
    }

    /** A clean, receipt-bounded, reviewed execution violates none of the five prohibitions. */
    public function test_clean_execution_violates_no_prohibition(): void
    {
        $r = $this->service->checkProhibitions([
            'has_decision_receipt' => true,
            'wrote_code' => true,
            'files_outside_scope' => [],
            'markdown_authoritative' => true,
            'has_structured_record' => true,
            'gate_failed' => false,
            'spec_mutated_after_execution' => false,
            'learning_mutates_core_policy' => true,
            'learning_reviewed' => true,
        ]);

        $this->assertTrue($r['allowed']);
        $this->assertSame([], $r['violation_ids']);
    }

    /** The canonical Service Boundaries catalog has the eleven documented stages in order and is well-formed. */
    public function test_canonical_pipeline_shape_is_complete_and_ordered(): void
    {
        $boundaries = $this->service->serviceBoundaries();

        $this->assertArrayHasKey('intent_router', $boundaries);
        $this->assertArrayHasKey('decision_engine', $boundaries);
        $this->assertArrayHasKey('learning_signals', $boundaries);
        $this->assertSame('Execute only inside receipt boundaries.', $boundaries['runtime_executor']);

        $shape = $this->service->validatePipelineShape(array_keys($boundaries));
        $this->assertTrue($shape['complete']);
        $this->assertTrue($shape['ordered']);
        $this->assertSame([], $shape['missing']);
        $this->assertSame([], $shape['unknown']);
    }

    /** A pipeline that skips the Decision Engine and injects an unknown stage out of order is rejected. */
    public function test_pipeline_missing_decision_engine_with_unknown_stage_is_blocked(): void
    {
        $shape = $this->service->validatePipelineShape([
            'spec_compiler',
            'intent_router',      // out of canonical order
            'runtime_executor',   // decision_engine skipped
            'ship_it',            // unknown injected stage
        ]);

        $this->assertFalse($shape['complete']);
        $this->assertFalse($shape['ordered']);
        $this->assertContains('decision_engine', $shape['missing']);
        $this->assertContains('ship_it', $shape['unknown']);
    }
}
