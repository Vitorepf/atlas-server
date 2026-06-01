<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionReceiptsService;
use Tests\TestCase;

/**
 * Pins the documented Post-Execution Action Receipts contract: the eight-key
 * boundary, the do_not_merge default, the progressive gate chain
 * (template -> draft -> signature request -> runbook), the exact bound hashes /
 * decision fields / signable payload / external fields, and the ordered runbook
 * that must stop before signature acceptance.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md
 */
class AtlasCodexMergePostExecutionActionReceiptsTest extends TestCase
{
    private function service(): AtlasCodexMergePostExecutionActionReceiptsService
    {
        return new AtlasCodexMergePostExecutionActionReceiptsService();
    }

    /**
     * Doc "Action Receipt Draft": it must default to `do_not_merge`, must always
     * require a signature, and — fail-closed — binds NO hashes / decision fields
     * until the post-execution action template is proven ready.
     */
    public function test_draft_defaults_do_not_merge_and_is_blocked_until_template_ready(): void
    {
        $r = $this->service()->actionReceiptDraft([]);

        $this->assertSame('blocked_before_post_execution_action_template', $r['status']);
        $this->assertFalse($r['draft_ready']);
        $this->assertSame('do_not_merge', $r['selected_decision']);
        $this->assertTrue($r['signature_required']);
        // Blocked => nothing bound, no decision fields listed.
        $this->assertSame([], $r['bound_hashes']);
        $this->assertSame([], $r['required_decision_fields']);
    }

    /**
     * Doc "Action Receipt Draft" → "It must bind <9>" and "It must require
     * decision fields for <7>": once (and only once) the template is ready, the
     * draft emits exactly those nine bound-hash slots and seven decision-field
     * slots, in the documented order — while STILL defaulting to do_not_merge and
     * authorizing nothing.
     */
    public function test_draft_binds_nine_hashes_and_seven_decision_fields_when_ready(): void
    {
        $r = $this->service()->actionReceiptDraft(['post_execution_action_template_ready' => true]);

        $this->assertSame('action_receipt_draft_ready', $r['status']);
        $this->assertTrue($r['draft_ready']);
        $this->assertSame('do_not_merge', $r['selected_decision']);

        $this->assertSame([
            'post_execution_action_template_hash',
            'post_execution_preflight_hash',
            'execution_receipt_template_hash',
            'executor_contract_template_hash',
            'final_receipt_hash',
            'selected_decision',
            'merge_candidate_hash',
            'persisted_execution_receipt_hash',
            'human_post_execution_confirmation_hash',
        ], $r['bound_hashes']);
        $this->assertCount(9, $r['bound_hashes']);

        $this->assertSame([
            'selected_decision',
            'decision_rationale',
            'merge_candidate_hash',
            'persisted_execution_receipt_hash',
            'post_execution_gate_report_hash',
            'human_post_execution_confirmation_hash',
            'merge_operator_identity',
        ], $r['required_decision_fields']);
        $this->assertCount(7, $r['required_decision_fields']);
    }

    /**
     * Doc "Action Signature Request": it may become pending ONLY after the action
     * receipt draft is ready. Without that proof it is blocked and publishes no
     * signable payload; with it, it lists the nine-slot signable payload and the
     * four required EXTERNAL fields — accepting / validating none.
     */
    public function test_signature_request_gated_on_draft_then_lists_payload_and_external_fields(): void
    {
        $svc = $this->service();

        $blocked = $svc->actionSignatureRequest([]);
        $this->assertSame('blocked_before_action_receipt_draft', $blocked['status']);
        $this->assertFalse($blocked['signature_request_pending']);
        $this->assertSame([], $blocked['signable_payload']);
        $this->assertSame([], $blocked['required_external_fields']);

        $pending = $svc->actionSignatureRequest(['action_receipt_draft_ready' => true]);
        $this->assertSame('action_signature_request_pending', $pending['status']);
        $this->assertTrue($pending['signature_request_pending']);
        $this->assertCount(9, $pending['signable_payload']);
        $this->assertContains('action_receipt_hash', $pending['signable_payload']);
        $this->assertSame([
            'final_merge_action_signature_value',
            'signature_validator_identity',
            'signature_validation_timestamp',
            'append_only_signed_action_receipt_persistence_proof',
        ], $pending['required_external_fields']);
        // Listing a payload is never acceptance.
        $this->assertFalse($pending['accepts_or_validates_signature']);
        $this->assertFalse($pending['signature_valid']);
        $this->assertFalse($pending['receipt_signed']);
    }

    /**
     * Doc "Action Post-Signature Runbook": gated on a pending signature request;
     * once ready it sequences the SEVEN ordered steps and the LAST step is the
     * documented hard stop `stop_before_signature_acceptance_or_merge`.
     */
    public function test_runbook_sequences_seven_steps_and_stops_before_acceptance(): void
    {
        $svc = $this->service();

        $blocked = $svc->actionPostSignatureRunbook([]);
        $this->assertSame('blocked_before_action_signature_request', $blocked['status']);
        $this->assertSame([], $blocked['steps']);
        // Even an empty runbook trivially "stops before acceptance".
        $this->assertTrue($blocked['stops_before_acceptance']);

        $ready = $svc->actionPostSignatureRunbook(['action_signature_request_pending' => true]);
        $this->assertSame('action_post_signature_runbook_ready', $ready['status']);
        $this->assertTrue($ready['runbook_ready']);
        $this->assertSame([
            'collect_external_signature_evidence',
            'verify_signature_request_hash_matches_signable_payload',
            'verify_action_receipt_hash_matches_signed_payload',
            'verify_required_authority_inputs_are_present',
            'verify_required_action_validations_are_present',
            'prepare_signed_action_receipt_persistence_candidate',
            'stop_before_signature_acceptance_or_merge',
        ], $ready['steps']);
        $this->assertCount(7, $ready['steps']);
        // The terminal step is, by construction, the hard stop.
        $this->assertSame('stop_before_signature_acceptance_or_merge', $ready['steps'][6]);
        $this->assertTrue($ready['stops_before_acceptance']);
        $this->assertFalse($ready['decision_recorded']);
        $this->assertCount(4, $ready['required_external_evidence']);
    }

    /**
     * Fail-closed: a non-strict-true readiness signal (string "true", 1, "yes",
     * [], null) must NOT advance any stage — only an exact boolean true does.
     */
    public function test_all_gates_are_fail_closed_against_non_boolean_true(): void
    {
        $svc = $this->service();

        foreach (['true', 1, 'yes', [], null] as $loose) {
            $this->assertSame(
                'blocked_before_post_execution_action_template',
                $svc->actionReceiptDraft(['post_execution_action_template_ready' => $loose])['status'],
            );
            $this->assertSame(
                'blocked_before_action_receipt_draft',
                $svc->actionSignatureRequest(['action_receipt_draft_ready' => $loose])['status'],
            );
            $this->assertSame(
                'blocked_before_action_signature_request',
                $svc->actionPostSignatureRunbook(['action_signature_request_pending' => $loose])['status'],
            );
        }
    }

    /**
     * Doc "Boundary": every result keeps all eight keys false, even when every
     * stage is fully ready. The boundary check is real — a tampered key is caught.
     */
    public function test_boundary_stays_all_false_and_assertion_is_non_vacuous(): void
    {
        $svc = $this->service();

        $all = $svc->evaluate([
            'post_execution_action_template_ready' => true,
            'action_receipt_draft_ready' => true,
            'action_signature_request_pending' => true,
        ]);

        $this->assertTrue($all['boundary_held']);
        $this->assertSame([], $all['boundary_violations']);

        foreach ([$all['action_receipt_draft'], $all['action_signature_request'], $all['action_post_signature_runbook']] as $surface) {
            foreach (AtlasCodexMergePostExecutionActionReceiptsService::BOUNDARY_KEYS as $key) {
                $this->assertArrayHasKey($key, $surface['boundary']);
                $this->assertFalse($surface['boundary'][$key], "boundary $key must be false");
            }
        }

        // Guard: a flipped boundary key must actually be detected.
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['merge_allowed' => true] + $svc->boundary(),
        ];
        $this->assertContains('tampered.merge_allowed', $svc->assertBoundaryHeld([$tampered]));
    }
}
