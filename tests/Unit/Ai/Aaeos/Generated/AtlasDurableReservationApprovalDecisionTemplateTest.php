<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApprovalDecisionTemplateService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Approval Decision Template contract:
 * the four allowed decision values, the eight required bindings, the explicit
 * non-approval guarantee, the planning-only (no-dispatch) limit on a granted
 * approval, and the post-review hash-drift `expired` override.
 *
 * Pure, deterministic, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
 */
class AtlasDurableReservationApprovalDecisionTemplateTest extends TestCase
{
    private function service(): AtlasDurableReservationApprovalDecisionTemplateService
    {
        return new AtlasDurableReservationApprovalDecisionTemplateService();
    }

    /**
     * @return array<string,mixed> a fully-bound, signed, approved, non-stale
     *   decision input — the only combination that legitimately grants approval.
     */
    private function fullyBoundApproval(): array
    {
        return [
            'decision_value' => 'approved_for_scoped_implementation',
            'approval_request_hash' => 'req-hash',
            'ap_candidate_hash' => 'cand-hash',
            'durable_ledger_plan_hash' => 'plan-hash',
            'multi_session_readiness_gate_hash' => 'gate-hash',
            'signer_identities' => ['operator-vitor'],
            'approved_scopes' => ['durable_reservation_storage_after_approval'],
            'forbidden_scopes' => ['runtimes/python/voice_realtime/**'],
            'rollback_strategy' => 'revert-migration-and-drop-table',
        ];
    }

    /**
     * Doc "Decision Values": exactly four values are allowed, in the documented
     * order. Anything outside the closed set classifies as `invalid`.
     */
    public function test_only_four_documented_decision_values_are_allowed(): void
    {
        $svc = $this->service();

        $this->assertSame(
            [
                'approved_for_scoped_implementation',
                'rejected',
                'needs_revision',
                'expired',
            ],
            AtlasDurableReservationApprovalDecisionTemplateService::DECISION_VALUES,
        );

        $this->assertSame('rejected', $svc->classifyDecision('rejected'));
        $this->assertSame('invalid', $svc->classifyDecision('approved'));      // not the full token
        $this->assertSame('invalid', $svc->classifyDecision('yes'));
        $this->assertSame('invalid', $svc->classifyDecision(null));
        $this->assertSame('invalid', $svc->classifyDecision(true));
    }

    /**
     * Doc "Explicit Non Approval": the emitted template approves nothing. Status
     * is `template_not_signed`, every non-execution guarantee key is false, and
     * the default decision is `needs_revision` (never an approval).
     */
    public function test_emitted_template_approves_nothing(): void
    {
        $tpl = $this->service()->template(
            ['product_governor', 'architecture_governor'],
            ['runtimes/python/voice_realtime/**'],
        );

        $this->assertSame('template_not_signed', $tpl['status']);
        $this->assertSame('needs_revision', $tpl['default_decision']);
        $this->assertFalse($tpl['approval_granted']);
        $this->assertFalse($tpl['decision_signed']);
        $this->assertFalse($tpl['is_execution']);

        foreach (['approval_granted', 'decision_signed', 'migrations_allowed', 'storage_writes_allowed', 'dispatch_allowed'] as $key) {
            $this->assertFalse($tpl['guarantee'][$key], "guarantee[$key] must be false on an unsigned template");
        }

        // One signer slot per supplied role, all pending/unsigned.
        $this->assertCount(2, $tpl['signer_slots']);
        $this->assertSame('pending', $tpl['signer_slots'][0]['decision']);
        $this->assertNull($tpl['signer_slots'][0]['signer_id']);
    }

    /**
     * Doc "Required Bindings": all eight bindings must be present. The default
     * (empty) recording reports every one as missing and grants no approval.
     */
    public function test_empty_recording_misses_all_eight_bindings_and_grants_nothing(): void
    {
        $svc = $this->service();

        $missing = $svc->missingBindings([]);
        $this->assertCount(8, $missing);
        $this->assertContainsAll([
            'approval_request_hash',
            'ap_candidate_hash',
            'durable_ledger_plan_hash',
            'multi_session_readiness_gate_hash',
            'signer_identities',
            'approved_scopes',
            'forbidden_scopes',
            'rollback_strategy',
        ], $missing);

        $decision = $svc->recordDecision([]);
        $this->assertFalse($decision['approval_granted']);
        $this->assertFalse($decision['signed']);
        $this->assertSame('template_not_signed', $decision['decision_status']);
        $this->assertSame('nothing', $decision['authorizes']);
    }

    /**
     * Doc "Explicit Non Approval": a valid approval needs BOTH signer data AND
     * the accepted value. A correct value but NO signer is fail-closed (a single
     * missing binding — signer_identities — voids the grant).
     */
    public function test_approved_value_without_signer_is_not_granted(): void
    {
        $bindings = $this->fullyBoundApproval();
        unset($bindings['signer_identities']);

        $decision = $this->service()->recordDecision($bindings);

        $this->assertFalse($decision['signed']);
        $this->assertFalse($decision['approval_granted']);
        $this->assertSame(['signer_identities'], $decision['missing_bindings']);
    }

    /**
     * Doc + frontmatter decision: a fully-bound, signed approval is granted and
     * authorizes scoped implementation PLANNING ONLY — dispatch stays disabled.
     * migrations/storage follow the grant, but dispatch_allowed is NEVER true.
     */
    public function test_full_approval_grants_planning_only_and_never_dispatch(): void
    {
        $decision = $this->service()->recordDecision($this->fullyBoundApproval());

        $this->assertTrue($decision['approval_granted']);
        $this->assertTrue($decision['signed']);
        $this->assertTrue($decision['fully_bound']);
        $this->assertSame('approved_for_scoped_implementation', $decision['effective_value']);
        $this->assertSame('approved_for_scoped_implementation_planning', $decision['decision_status']);
        $this->assertSame(
            'scoped_implementation_planning_only_dispatch_requires_separate_future_ap',
            $decision['authorizes'],
        );

        $this->assertTrue($decision['guarantee']['approval_granted']);
        $this->assertTrue($decision['guarantee']['migrations_allowed']);
        $this->assertTrue($decision['guarantee']['storage_writes_allowed']);
        // The hard invariant: planning, not dispatch.
        $this->assertFalse($decision['guarantee']['dispatch_allowed']);
    }

    /**
     * Doc "expired": if a bound hash changed after review, the decision is forced
     * to `expired` and approval is voided — even when the operator submitted
     * `approved_for_scoped_implementation` with full signer + bindings.
     */
    public function test_post_review_hash_drift_forces_expired_and_voids_approval(): void
    {
        $svc = $this->service();
        $bindings = $this->fullyBoundApproval();
        // The plan hash moved after the human reviewed it.
        $bindings['durable_ledger_plan_hash_now'] = 'plan-hash-CHANGED';

        $decision = $svc->recordDecision($bindings);

        $this->assertContains('durable_ledger_plan_hash', $decision['stale_hashes']);
        $this->assertTrue($decision['forced_expired']);
        $this->assertSame('expired', $decision['effective_value']);
        $this->assertFalse($decision['approval_granted']);
        $this->assertSame('nothing', $decision['authorizes']);
    }

    /**
     * Doc "Completion Criteria": the composite evaluate() emits the template and
     * the recording while proving the non-execution guarantee held — and the
     * dispatch invariant holds even when the recording is a granted approval.
     */
    public function test_evaluate_holds_dispatch_invariant_even_on_granted_approval(): void
    {
        $svc = $this->service();

        $unsigned = $svc->evaluate();
        $this->assertTrue($unsigned['guarantee_held']);
        $this->assertSame([], $unsigned['guarantee_violations']);
        $this->assertFalse($unsigned['decision']['approval_granted']);

        $granted = $svc->evaluate($this->fullyBoundApproval());
        // A signed approval legitimately flips approval/storage on the decision,
        // but the dispatch invariant must still hold across both results.
        $this->assertTrue($granted['guarantee_held']);
        $this->assertSame([], $granted['guarantee_violations']);
        $this->assertTrue($granted['decision']['approval_granted']);
        $this->assertFalse($granted['decision']['guarantee']['dispatch_allowed']);
        $this->assertFalse($granted['template']['guarantee']['dispatch_allowed']);
    }

    /**
     * @param list<string> $needles
     * @param list<string> $haystack
     */
    private function assertContainsAll(array $needles, array $haystack): void
    {
        foreach ($needles as $needle) {
            $this->assertContains($needle, $haystack);
        }
    }
}
