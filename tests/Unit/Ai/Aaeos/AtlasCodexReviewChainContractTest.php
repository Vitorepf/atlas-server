<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

/**
 * Pins the hard invariants of the Codex Review Chain Contract against the
 * existing service that already implements it
 * ({@see AtlasSelfConstructionReadinessService}). The review chain is the
 * non-executing review/signature/merge-action chain that must never approve,
 * sign, dispatch or merge.
 *
 * Pure and DB-free: the seven codex review-chain methods are deterministic and
 * do not touch the database when invoked with empty options (verified). No
 * RefreshDatabase, no Storage seeding.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
 */
class AtlasCodexReviewChainContractTest extends TestCase
{
    private function service(): AtlasSelfConstructionReadinessService
    {
        return app(AtlasSelfConstructionReadinessService::class);
    }

    /**
     * Contract decision: "Review commands may package evidence and templates,
     * but must not approve code." The final review packet's principal-integrator
     * decision slot must default to the conservative request_changes, never
     * approval, and the packet must grant no merge/approval/dispatch/execution.
     */
    public function test_final_review_packet_defaults_to_request_changes_and_grants_nothing(): void
    {
        $packet = $this->service()->codexFinalReviewPacket([]);

        $this->assertSame('request_changes', data_get($packet, 'packet.decision_slots.0.default'));
        $this->assertFalse($packet['approval_granted']);
        $this->assertFalse($packet['merge_allowed']);
        $this->assertFalse($packet['dispatch_allowed']);
        $this->assertFalse($packet['execution_allowed']);

        // Conservative scope/evidence slots default to the unsafe verdict so a
        // missing input can never read as "clean".
        $this->assertSame('scope_violation_found', data_get($packet, 'packet.decision_slots.1.default'));
        $this->assertSame('evidence_missing_or_inconsistent', data_get($packet, 'packet.decision_slots.2.default'));
    }

    /**
     * Doc: the decision template "may include approve_for_merge as an allowed
     * human decision value", but must keep decision_recording_allowed=false and
     * grant no approval/merge/dispatch, with safe defaults (missing precondition
     * => request_changes, hot scope touched => reject).
     */
    public function test_decision_template_allows_approval_value_but_records_and_grants_nothing(): void
    {
        $tmpl = $this->service()->codexReviewDecisionTemplate([]);

        $this->assertContains('approve_for_merge', data_get($tmpl, 'template.allowed_decisions'));
        $this->assertFalse(data_get($tmpl, 'template.decision_recording_allowed'));
        $this->assertSame('request_changes', data_get($tmpl, 'template.default_decision'));
        $this->assertFalse($tmpl['approval_granted']);
        $this->assertFalse($tmpl['merge_allowed']);

        // Documented safe-default policy.
        $this->assertSame('request_changes', data_get($tmpl, 'template.default_safe_decision_policy.when_any_precondition_is_missing'));
        $this->assertSame('request_changes', data_get($tmpl, 'template.default_safe_decision_policy.when_evidence_is_missing'));
        $this->assertSame('reject', data_get($tmpl, 'template.default_safe_decision_policy.when_hot_scope_is_touched'));
    }

    /**
     * Doc: the receipt draft must keep signature_required=true,
     * signature_valid=false, decision_recorded=false, approval_granted=false,
     * merge_allowed=false. It prepares audit structure only.
     */
    public function test_receipt_draft_is_unsigned_and_non_authorizing(): void
    {
        $draft = $this->service()->codexReviewReceiptDraft([]);

        $this->assertTrue(data_get($draft, 'receipt.signature_required'));
        $this->assertFalse(data_get($draft, 'receipt.signature_valid'));
        $this->assertFalse(data_get($draft, 'receipt.decision_recorded'));
        $this->assertFalse(data_get($draft, 'receipt.approval_granted'));
        $this->assertFalse(data_get($draft, 'receipt.merge_allowed'));
        $this->assertSame('request_changes', data_get($draft, 'receipt.default_decision'));
    }

    /**
     * Doc: "The signature request is not a signature." It must keep
     * signature_present=false, signature_valid=false, decision_recorded=false,
     * approval_granted=false, merge_allowed=false — even though it carries a
     * hash-bound signable payload.
     */
    public function test_signature_request_presents_no_signature_and_authorizes_no_merge(): void
    {
        $req = $this->service()->codexReviewSignatureRequest([]);

        $this->assertFalse(data_get($req, 'signature_request.signature_present'));
        $this->assertFalse(data_get($req, 'signature_request.signature_valid'));
        $this->assertFalse(data_get($req, 'signature_request.decision_recorded'));
        $this->assertFalse(data_get($req, 'signature_request.approval_granted'));
        $this->assertFalse(data_get($req, 'signature_request.merge_allowed'));

        // The signable payload must still forbid auto-merge after a future
        // signature (the chain hands off to a separate explicit merge action).
        $this->assertContains(
            'auto_merge_without_explicit_human_merge_action',
            data_get($req, 'signable_payload.still_forbidden_after_signature')
        );
        // A non-empty payload hash proves the request is structurally formed.
        $this->assertNotEmpty(data_get($req, 'signable_payload_hash'));
    }

    /**
     * Doc: the post-signature runbook "is not a validator and not an executor".
     * It must keep signature_valid=false, approval_granted=false,
     * merge_allowed=false, auto_merge_allowed=false; and the merge action
     * template "is not the merge action" — execution_allowed=false,
     * default_decision=request_changes.
     */
    public function test_runbook_and_merge_action_template_remain_non_executing(): void
    {
        $svc = $this->service();

        $runbook = $svc->codexReviewPostSignatureRunbook([]);
        $this->assertFalse($runbook['signature_valid']);
        $this->assertFalse(data_get($runbook, 'runbook.approval_granted'));
        $this->assertFalse(data_get($runbook, 'runbook.merge_allowed'));
        $this->assertFalse(data_get($runbook, 'runbook.auto_merge_allowed'));

        $tmpl = $svc->codexReviewMergeActionTemplate([]);
        $this->assertFalse(data_get($tmpl, 'template.signature_validated_by_this_template'));
        $this->assertFalse(data_get($tmpl, 'template.approval_granted'));
        $this->assertFalse(data_get($tmpl, 'template.merge_allowed'));
        $this->assertFalse(data_get($tmpl, 'template.auto_merge_allowed'));
        $this->assertFalse(data_get($tmpl, 'template.execution_allowed'));
        $this->assertSame('request_changes', data_get($tmpl, 'template.default_decision'));
    }

    /**
     * Completion criterion: every review-chain command keeps approval,
     * signature validation, dispatch and merge disabled. We assert the whole
     * chain holds that boundary simultaneously, plus determinism (same input =>
     * same hash) for the merge preflight aggregator.
     */
    public function test_whole_chain_holds_non_authorizing_boundary_and_is_deterministic(): void
    {
        $svc = $this->service();

        $stages = [
            $svc->codexFinalReviewPacket([]),
            $svc->codexReviewDecisionTemplate([]),
            $svc->codexReviewReceiptDraft([]),
            $svc->codexReviewSignatureRequest([]),
            $svc->codexReviewPostSignatureRunbook([]),
            $svc->codexReviewMergeActionTemplate([]),
            $svc->codexReviewMergePreflight([]),
        ];

        foreach ($stages as $i => $stage) {
            $this->assertFalse($stage['approval_granted'], "stage {$i} approval_granted");
            $this->assertFalse($stage['merge_allowed'], "stage {$i} merge_allowed");
            $this->assertFalse($stage['dispatch_allowed'], "stage {$i} dispatch_allowed");
            $this->assertFalse($stage['execution_allowed'], "stage {$i} execution_allowed");
        }

        // Merge preflight is the final aggregator and must never grant merge,
        // and must be deterministic across refreshes.
        $preflightA = $svc->codexReviewMergePreflight([]);
        $preflightB = $svc->codexReviewMergePreflight([]);
        $this->assertFalse($preflightA['merge_allowed']);
        $this->assertFalse($preflightA['dispatch_allowed']);
        $this->assertSame($preflightA['preflight_hash'] ?? null, $preflightB['preflight_hash'] ?? null);
    }
}
