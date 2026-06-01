<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPPayloadService;
use Tests\TestCase;

/**
 * Pins the documented Post-Execution Action Persistence PAYLOAD contract: the
 * eight-key boundary; the future event type and the fifteen required fields the
 * template lists with every value held null and never written; the readiness rule
 * (ready ONLY when the post-preflight persistence runbook is ready, and ready
 * still permits inspection only — never writing); and the before-write gate's six
 * conditions (fail-closed empty input leaves all unmet and a write may not
 * proceed; a fully proven input meets all six and may_write — yet the boundary
 * still holds and the write is not made legal).
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-payload.md
 */
class AtlasCodexMergePEAPPayloadTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPPayloadService
    {
        return new AtlasCodexMergePEAPPayloadService();
    }

    /**
     * A fully proven input that meets every one of the six documented before-write
     * conditions.
     *
     * @return array<string,mixed>
     */
    private function provenBeforeWriteInput(): array
    {
        return [
            'post_preflight_runbook_ready' => true,
            'all_runbook_steps_have_evidence' => true,
            'all_preflight_blockers_resolved' => true,
            'all_required_payload_fields_non_null' => true,
            'payload_hash_recomputed_by_writer' => true,
            'writer_surface_separately_authorized' => true,
        ];
    }

    /**
     * Doc "Boundary": every result keeps all EIGHT keys false, and the composite
     * proves the boundary held across every surface with empty (safe) defaults.
     */
    public function test_boundary_keeps_all_eight_keys_false_and_holds_across_surfaces(): void
    {
        $expected = [
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'receipt_signed' => false,
        ];

        $this->assertSame($expected, $this->service()->boundary());

        $result = $this->service()->evaluate([]);
        $this->assertTrue($result['boundary_held']);
        $this->assertSame([], $result['boundary_violations']);
    }

    /**
     * Doc "Event Type" + "Required Fields": the template pins the exact future
     * event type and lists EXACTLY the fifteen required fields, in order, with
     * every value null and the event NOT written.
     */
    public function test_payload_template_lists_fifteen_required_fields_all_null_and_writes_nothing(): void
    {
        $payload = $this->service()->payloadTemplate();

        $this->assertSame(
            'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED',
            $payload['future_event_type'],
        );

        $expectedFields = [
            'event_id',
            'event_type',
            'signed_action_receipt_id',
            'signed_action_receipt_hash',
            'source_persistence_post_preflight_runbook_hash',
            'source_persistence_preflight_hash',
            'source_persistence_receipt_draft_hash',
            'append_only_event_hash',
            'ledger_sequence_number',
            'persistence_actor_identity',
            'persistence_timestamp',
            'human_persistence_confirmation_hash',
            'source_hash_match_report_hash',
            'hot_scope_recheck_report_hash',
            'unreviewed_diff_absence_report_hash',
        ];

        // Exactly fifteen field names, in documented order.
        $this->assertSame($expectedFields, $payload['required_fields']);
        $this->assertCount(15, $payload['required_fields']);

        // "Fields that require future evidence must stay null in this read-only template."
        $this->assertSame($expectedFields, array_keys($payload['fields']));
        foreach ($payload['fields'] as $name => $value) {
            $this->assertNull($value, "field {$name} must be null in the read-only template");
        }
        $this->assertTrue($payload['all_fields_null']);

        // The template defines the shape but writes nothing, and is neither proof
        // of persistence nor a merge authorization.
        $this->assertFalse($payload['writes_event']);
        $this->assertFalse($payload['is_proof_of_persistence']);
        $this->assertFalse($payload['is_merge_authorization']);
        // Human Meaning: it does not answer "Can the payload be written now?".
        $this->assertFalse($payload['answers_can_payload_be_written_now']);
    }

    /**
     * Doc "Readiness Rule": ready ONLY when the post-preflight persistence runbook
     * is ready. Ready permits inspection but NEVER writing.
     */
    public function test_readiness_is_ready_only_when_runbook_ready_and_never_permits_write(): void
    {
        $blocked = $this->service()->readiness([]);
        $this->assertSame(
            'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked',
            $blocked['status'],
        );
        $this->assertFalse($blocked['template_ready']);
        $this->assertFalse($blocked['permits_inspection']);
        $this->assertFalse($blocked['permits_write']);

        $ready = $this->service()->readiness(['post_preflight_runbook_ready' => true]);
        $this->assertSame(
            'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready',
            $ready['status'],
        );
        $this->assertTrue($ready['template_ready']);
        // "Ready means the payload contract can be inspected."
        $this->assertTrue($ready['permits_inspection']);
        // "It does not permit writing." — even when ready.
        $this->assertFalse($ready['permits_write']);
    }

    /**
     * Doc "Before Write Conditions": empty input leaves all six unmet and a write
     * may not proceed (fail-closed); a fully proven input meets all six and
     * may_write — yet the boundary still holds and the write is not made legal.
     */
    public function test_before_write_gate_requires_all_six_conditions_and_stays_read_only(): void
    {
        $empty = $this->service()->beforeWriteGate([]);
        $this->assertFalse($empty['may_write']);
        $this->assertSame(6, $empty['unmet_count']);
        $this->assertSame([], $empty['met_conditions']);
        $this->assertFalse($empty['makes_write_legal']);
        $this->assertTrue($empty['read_only']);

        // Five of six met is still NOT enough — fail-closed on the last condition.
        $partial = $this->provenBeforeWriteInput();
        unset($partial['writer_surface_separately_authorized']);
        $partialResult = $this->service()->beforeWriteGate($partial);
        $this->assertFalse($partialResult['may_write']);
        $this->assertContains('writer_surface_separately_authorized', $partialResult['unmet_conditions']);

        // All six proven => may_write, but the boundary still holds and the gate
        // does not make the write legal by itself.
        $full = $this->service()->beforeWriteGate($this->provenBeforeWriteInput());
        $this->assertTrue($full['may_write']);
        $this->assertSame(0, $full['unmet_count']);
        $this->assertCount(6, $full['met_conditions']);
        $this->assertFalse($full['makes_write_legal']);
        $this->assertSame([], $this->service()->assertBoundaryHeld([$full]));
    }

    /**
     * A non-boolean truthy value must NOT satisfy a before-write condition or the
     * readiness flag (fail-closed, strict boolean true only).
     */
    public function test_non_boolean_truthy_values_are_rejected(): void
    {
        $readiness = $this->service()->readiness(['post_preflight_runbook_ready' => 1]);
        $this->assertFalse($readiness['template_ready']);

        $gate = $this->service()->beforeWriteGate([
            'post_preflight_runbook_ready' => 'yes',
            'all_runbook_steps_have_evidence' => 1,
            'all_preflight_blockers_resolved' => 'true',
            'all_required_payload_fields_non_null' => [],
            'payload_hash_recomputed_by_writer' => 'x',
            'writer_surface_separately_authorized' => 0,
        ]);
        $this->assertFalse($gate['may_write']);
        $this->assertSame(6, $gate['unmet_count']);
    }
}
