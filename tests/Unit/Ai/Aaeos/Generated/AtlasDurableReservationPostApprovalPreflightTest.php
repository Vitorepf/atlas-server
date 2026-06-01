<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationPostApprovalPreflightService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Post-Approval Preflight contract:
 * the nine Required Checks, the six Blockers, the decision-value gate, the
 * implementation limits / rollback echo and the non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md
 */
class AtlasDurableReservationPostApprovalPreflightTest extends TestCase
{
    private function service(): AtlasDurableReservationPostApprovalPreflightService
    {
        return new AtlasDurableReservationPostApprovalPreflightService();
    }

    /**
     * A fully-clean signed state: every check signal proven, every hash matching,
     * all evidence present, scope within approved, dispatch disabled, rollback
     * present. Used as the baseline that individual tests then break.
     *
     * @return array<string,mixed>
     */
    private function cleanState(): array
    {
        return [
            // Required-check signals (all proven true).
            'all_required_roles_signed' => true,
            'decision_value_approved' => true,
            'approval_request_hash_matches' => true,
            'ap_candidate_hash_matches' => true,
            'durable_ledger_plan_hash_matches' => true,
            'multi_session_readiness_gate_hash_matches' => true,
            'docs_health_and_architecture_validate_passing' => true,
            'no_hot_voice_kernel_scopes_in_allowed_files' => true,
            'dispatch_disabled' => true,
            // Blocker-clearing signals not already covered above.
            'scope_within_approved' => true,
            'rollback_present' => true,
            'rollback_strategy' => 'restore_prior_migration_and_purge_reservation_rows',
            'implementation_limits' => [
                'allowed_paths' => ['app/Services/Reservation'],
                'max_new_tables' => 1,
            ],
            // Evidence sub-array: every documented item present and non-empty.
            'evidence' => [
                'signed_approval_decision' => 'sig:abc',
                'approval_request_hash' => 'h1',
                'ap_candidate_hash' => 'h2',
                'durable_ledger_plan_hash' => 'h3',
                'multi_session_readiness_gate_hash' => 'h4',
                'docs_health_output' => 'ok',
                'architecture_validate_output' => 'ok',
                'rollback_strategy' => 'present',
            ],
        ];
    }

    /**
     * Doc "Required Checks" + "Blockers": with nothing proven, the safe default
     * fails ALL NINE checks, fires ALL SIX blockers, status is `blocked`, and no
     * clearance is granted. Fail-closed by construction.
     */
    public function test_safe_default_fails_all_checks_and_fires_all_blockers(): void
    {
        $result = $this->service()->preflight([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['clearance_granted']);
        $this->assertFalse($result['checks_all_passed']);

        // Nine documented checks, all failing.
        $this->assertCount(9, $result['checks']);
        $this->assertNotContains(true, array_values($result['checks']));

        // Six documented blockers, all fired.
        $this->assertCount(6, $result['active_blockers']);
        $this->assertSame([
            'signer_slot_missing',
            'hash_drifted',
            'required_evidence_absent',
            'migration_or_storage_scope_broader_than_approved',
            'dispatch_would_be_enabled',
            'rollback_strategy_missing',
        ], $result['active_blockers']);
    }

    /**
     * Doc "Required Checks" + "Completion Criteria": a fully-clean signed state
     * passes all nine checks, fires zero blockers, clears, and echoes the
     * approved implementation limits + rollback strategy.
     */
    public function test_fully_clean_state_clears_preflight_and_echoes_limits(): void
    {
        $result = $this->service()->preflight($this->cleanState());

        $this->assertSame('preflight_cleared', $result['status']);
        $this->assertTrue($result['clearance_granted']);
        $this->assertTrue($result['checks_all_passed']);
        $this->assertSame([], $result['active_blockers']);

        // Implementation limits and rollback are echoed for the operator.
        $this->assertSame(['app/Services/Reservation'], $result['implementation_limits']['allowed_paths']);
        $this->assertSame('restore_prior_migration_and_purge_reservation_rows', $result['rollback_strategy']);

        // Even cleared, the non-execution guarantee holds.
        $this->assertFalse($result['implementation_allowed']);
        $this->assertFalse($result['is_execution']);
    }

    /**
     * Doc "Required Checks": the signed decision value MUST be
     * `approved_for_scoped_implementation`. If that single gate signal is not
     * proven, the decision-value check fails and preflight does not clear, even
     * when everything else is clean.
     */
    public function test_wrong_decision_value_blocks_even_when_everything_else_is_clean(): void
    {
        $state = $this->cleanState();
        // Simulate a decision value that is NOT approved_for_scoped_implementation.
        $state['decision_value_approved'] = false;

        $result = $this->service()->preflight($state);

        $this->assertSame('approved_for_scoped_implementation', $result['required_decision_value']);
        $this->assertFalse($result['checks']['decision_value_is_approved_for_scoped_implementation']);
        $this->assertFalse($result['clearance_granted']);
        $this->assertSame('blocked', $result['status']);
    }

    /**
     * Doc "Blockers": if ANY one of the four hashes drifted, the `hash_drifted`
     * blocker fires and the corresponding hash-match check fails. Here only the
     * AP candidate hash drifts; the preflight still blocks.
     */
    public function test_single_hash_drift_fires_hash_drifted_blocker(): void
    {
        $state = $this->cleanState();
        $state['ap_candidate_hash_matches'] = false; // one hash drifted

        $result = $this->service()->preflight($state);

        $this->assertFalse($result['checks']['ap_candidate_hash_matches']);
        $this->assertContains('hash_drifted', $result['active_blockers']);
        $this->assertFalse($result['clearance_granted']);
    }

    /**
     * Doc "Blockers": migration/storage scope broader than approved, and a
     * missing rollback strategy, each fire their own blocker independently.
     */
    public function test_scope_and_rollback_blockers_fire_independently(): void
    {
        // Broaden scope: remove the scope-within-approved clearance only.
        $broadScope = $this->cleanState();
        $broadScope['scope_within_approved'] = false;
        $scopeResult = $this->service()->preflight($broadScope);
        $this->assertContains('migration_or_storage_scope_broader_than_approved', $scopeResult['active_blockers']);
        $this->assertNotContains('rollback_strategy_missing', $scopeResult['active_blockers']);

        // Remove rollback: drop both the clearance signal and the strategy string.
        $noRollback = $this->cleanState();
        $noRollback['rollback_present'] = false;
        unset($noRollback['rollback_strategy']);
        $rollbackResult = $this->service()->preflight($noRollback);
        $this->assertContains('rollback_strategy_missing', $rollbackResult['active_blockers']);
        $this->assertNull($rollbackResult['rollback_strategy']);
        $this->assertNotContains('migration_or_storage_scope_broader_than_approved', $rollbackResult['active_blockers']);
    }

    /**
     * Doc "Blockers": missing required evidence fires `required_evidence_absent`.
     * Dropping a single evidence item (the AP candidate hash) is enough.
     */
    public function test_missing_single_evidence_item_fires_evidence_blocker(): void
    {
        $state = $this->cleanState();
        unset($state['evidence']['ap_candidate_hash']);

        $result = $this->service()->preflight($state);

        $this->assertContains('required_evidence_absent', $result['active_blockers']);
        $this->assertFalse($result['clearance_granted']);
    }

    /**
     * Doc: "Preflight generation is read-only and cannot create migrations,
     * storage, claims or dispatch." The non-execution guarantee holds on BOTH
     * the blocked default and the fully-cleared path — emitting clearance is not
     * execution. assertGuaranteeHeld() finds zero violations.
     */
    public function test_non_execution_guarantee_holds_on_blocked_and_cleared(): void
    {
        $service = $this->service();

        $blocked = $service->evaluate([]);
        $cleared = $service->evaluate($this->cleanState());

        $this->assertTrue($blocked['guarantee_held']);
        $this->assertSame([], $blocked['guarantee_violations']);
        $this->assertTrue($cleared['guarantee_held']);
        $this->assertSame([], $cleared['guarantee_violations']);

        // All four guarantee keys are present and false on the cleared packet.
        foreach (['migrations_created', 'storage_writes_performed', 'claims_created', 'dispatch_enabled'] as $key) {
            $this->assertFalse($cleared['preflight']['guarantee'][$key]);
        }
    }
}
