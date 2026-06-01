<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApprovalRequestService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Approval Request contract: required
 * signers, decisions, evidence, the six blocking conditions and the
 * non-execution guarantee.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
 */
class AtlasDurableReservationApprovalRequestTest extends TestCase
{
    private function service(): AtlasDurableReservationApprovalRequestService
    {
        return new AtlasDurableReservationApprovalRequestService();
    }

    /**
     * Doc "Blocking Conditions": with no clearing signal proven, the safe default
     * fires ALL SIX blockers, status is `blocked` and the request is not ready
     * for review. Fail-closed by construction.
     */
    public function test_safe_default_fires_all_six_blockers_and_is_not_ready(): void
    {
        $r = $this->service()->requestPacket([]);

        $this->assertSame('blocked', $r['status']);
        $this->assertFalse($r['approval_request_ready']);
        $this->assertCount(6, $r['active_blockers']);
        $this->assertSame([
            'candidate_hash_changed_after_review',
            'plan_hash_changed_after_review',
            'docs_or_architecture_validation_failed',
            'hot_scopes_in_allowed_files',
            'dispatch_enabled_in_same_ap',
            'rollback_missing',
        ], $r['active_blockers']);
    }

    /**
     * Doc "Blocking Conditions": when EVERY clearing signal is proven with an
     * exact boolean true, no blocker fires, status flips to ready-for-review and
     * the request is ready — yet still not "approved".
     */
    public function test_fully_clean_review_is_ready_for_review_but_never_approved(): void
    {
        $clean = [
            'candidate_hash_stable' => true,
            'plan_hash_stable' => true,
            'docs_and_architecture_validation_passed' => true,
            'no_hot_scopes_in_allowed_files' => true,
            'dispatch_disabled_in_same_ap' => true,
            'rollback_present' => true,
            'rollback_strategy' => 'snapshot_and_revert_migration',
        ];

        $r = $this->service()->requestPacket($clean);

        $this->assertSame('approval_request_ready_for_review', $r['status']);
        $this->assertSame([], $r['active_blockers']);
        $this->assertTrue($r['approval_request_ready']);
        $this->assertSame('snapshot_and_revert_migration', $r['rollback_strategy']);
        // Ready for review is NOT approval (doc "Approval Is Not Execution").
        $this->assertFalse($r['approval_granted']);
        $this->assertFalse($r['is_execution']);
    }

    /**
     * Doc "Blocking Conditions" is fail-closed: a single missing clearing signal
     * (here: rollback) keeps the matching blocker active and the whole request
     * blocked, even when all other conditions are clear.
     */
    public function test_one_missing_signal_keeps_request_blocked(): void
    {
        $almost = [
            'candidate_hash_stable' => true,
            'plan_hash_stable' => true,
            'docs_and_architecture_validation_passed' => true,
            'no_hot_scopes_in_allowed_files' => true,
            'dispatch_disabled_in_same_ap' => true,
            // rollback_present intentionally omitted.
        ];

        $r = $this->service()->requestPacket($almost);

        $this->assertSame('blocked', $r['status']);
        $this->assertFalse($r['approval_request_ready']);
        $this->assertSame(['rollback_missing'], $r['active_blockers']);
    }

    /**
     * Fail-closed: a non-strict-true clearing signal (e.g. the string "true" or
     * 1) must NOT clear a blocker — only an exact boolean true does.
     */
    public function test_clearing_signals_are_fail_closed_against_non_boolean_true(): void
    {
        foreach (['true', 1, 'yes', [], null] as $loose) {
            $r = $this->service()->evaluateBlockers(['rollback_present' => $loose]);
            $this->assertContains('rollback_missing', $r, 'loose value must not clear the blocker');
        }
    }

    /**
     * Doc "Required Signers" / "Approval Must Decide" / "Required Evidence": the
     * packet always names the four signers, five decisions and seven evidence
     * items in the exact documented order, regardless of blocker state.
     */
    public function test_packet_lists_signers_decisions_and_evidence_verbatim(): void
    {
        $r = $this->service()->requestPacket([]);

        $this->assertSame([
            'product_governor',
            'architecture_governor',
            'safety_governance_reviewer',
            'implementation_operator',
        ], $r['required_signers']);

        $this->assertSame([
            'migrations_or_storage_allowed',
            'ap_candidate_accepted_as_scoped',
            'dispatch_remains_disabled_after_ledger_activation',
            'rollback_evidence_sufficient',
            'hot_voice_kernel_scopes_remain_forbidden',
        ], $r['required_decisions']);

        $this->assertSame([
            'ap_candidate_hash',
            'durable_ledger_plan_hash',
            'multi_session_readiness_gate_hash',
            'docs_health_output',
            'architecture_validate_output',
            'rollback_strategy',
            'explicit_forbidden_scopes',
        ], $r['required_evidence']);
    }

    /**
     * Doc "Approval Is Not Execution" / "Completion Criteria": the non-execution
     * guarantee stays all-false on every result — even a fully clean, ready
     * packet — and the assertion is non-vacuous (a flipped key is caught).
     */
    public function test_non_execution_guarantee_holds_and_assertion_catches_a_flip(): void
    {
        $svc = $this->service();

        $clean = [
            'candidate_hash_stable' => true,
            'plan_hash_stable' => true,
            'docs_and_architecture_validation_passed' => true,
            'no_hot_scopes_in_allowed_files' => true,
            'dispatch_disabled_in_same_ap' => true,
            'rollback_present' => true,
        ];

        $r = $svc->evaluate($clean);
        $this->assertTrue($r['guarantee_held']);
        $this->assertSame([], $r['guarantee_violations']);
        foreach (AtlasDurableReservationApprovalRequestService::GUARANTEE_KEYS as $key) {
            $this->assertFalse($r['approval_request']['guarantee'][$key], "guarantee $key must be false");
        }

        // Guard: a flipped guarantee key must be detected (proves non-vacuous).
        $tampered = [
            'surface' => 'tampered',
            'guarantee' => ['approval_granted' => true] + $svc->guarantee(),
        ];
        $violations = $svc->assertGuaranteeHeld([$tampered]);
        $this->assertContains('tampered.approval_granted', $violations);
    }
}
