<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApCandidateService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation AP Candidate contract: the five
 * ordered implementation phases, the allowed/forbidden scope, the seven required
 * tests and evidence items, the completeness gates (rollback + evidence +
 * dispatch-disabled) and the non-execution guarantee.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
 */
class AtlasDurableReservationApCandidateTest extends TestCase
{
    private function service(): AtlasDurableReservationApCandidateService
    {
        return new AtlasDurableReservationApCandidateService();
    }

    /**
     * Doc "Implementation Packets": the packet always carries the FIVE phases in
     * the exact documented order, regardless of caller signals.
     */
    public function test_packet_carries_five_phases_in_documented_order(): void
    {
        $r = $this->service()->candidatePacket([]);

        $this->assertSame([
            'storage_ap',
            'repository_ap',
            'collision_ap',
            'lease_ap',
            'readiness_ap',
        ], $r['phase_order']);
        $this->assertSame(
            'overlap and hot-scope rejection',
            $r['implementation_packets']['collision_ap'],
        );
    }

    /**
     * Doc "Scope" + "Required Tests": the forbidden scope always bars
     * auto-dispatch and Voice/Kernel/route work, and all seven required tests are
     * present and ordered (including dispatch-stays-disabled last).
     */
    public function test_forbidden_scope_and_required_tests_are_pinned(): void
    {
        $r = $this->service()->candidatePacket([]);

        $this->assertContains('do_not_auto_dispatch_packets', $r['forbidden_scope']);
        $this->assertContains('do_not_touch_voice_kernel_routes_or_config', $r['forbidden_scope']);

        $this->assertCount(7, $r['required_tests']);
        $this->assertSame('duplicate_packet_claim_is_blocked', $r['required_tests'][0]);
        $this->assertSame('dispatch_remains_disabled_after_ledger_activation', $r['required_tests'][6]);
    }

    /**
     * Frontmatter decision "must include rollback and evidence before any
     * migration": the safe default (no signals) is incomplete and flags rollback,
     * every evidence item and dispatch-not-disabled as missing — fail-closed.
     */
    public function test_safe_default_is_incomplete_and_fails_closed(): void
    {
        $r = $this->service()->candidatePacket([]);

        $this->assertSame('incomplete', $r['status']);
        $this->assertFalse($r['candidate_complete']);
        $this->assertContains('rollback_missing', $r['missing_conditions']);
        $this->assertContains('dispatch_not_disabled', $r['missing_conditions']);
        // rollback + 7 evidence + dispatch = 9 missing conditions.
        $this->assertCount(9, $r['missing_conditions']);
    }

    /**
     * Frontmatter decision is fail-closed: with rollback present, dispatch
     * disabled and SIX of seven evidence items proven, the one missing evidence
     * item (rollback_notes) keeps the candidate incomplete.
     */
    public function test_one_missing_evidence_item_keeps_candidate_incomplete(): void
    {
        $almost = [
            'rollback_present' => true,
            'rollback_strategy' => 'snapshot_db_and_revert_migration',
            'dispatch_disabled' => true,
            'evidence_migration_diff' => true,
            'evidence_repository_tests' => true,
            'evidence_gate_output' => true,
            'evidence_scope_validator_output' => true,
            'evidence_architecture_validation' => true,
            'evidence_docs_health' => true,
            // evidence_rollback_notes intentionally omitted.
        ];

        $r = $this->service()->candidatePacket($almost);

        $this->assertSame('incomplete', $r['status']);
        $this->assertFalse($r['candidate_complete']);
        $this->assertSame(['evidence_missing_rollback_notes'], $r['missing_conditions']);
    }

    /**
     * Doc "Completion Criteria": with rollback present, dispatch disabled and ALL
     * seven evidence items proven, the candidate is complete — yet emitting it is
     * still NOT execution (the guarantee block holds all-false).
     */
    public function test_fully_satisfied_candidate_is_complete_but_never_executes(): void
    {
        $complete = [
            'rollback_present' => true,
            'rollback_strategy' => 'snapshot_db_and_revert_migration',
            'dispatch_disabled' => true,
            'evidence_migration_diff' => true,
            'evidence_repository_tests' => true,
            'evidence_gate_output' => true,
            'evidence_scope_validator_output' => true,
            'evidence_architecture_validation' => true,
            'evidence_docs_health' => true,
            'evidence_rollback_notes' => true,
        ];

        $r = $this->service()->candidatePacket($complete);

        $this->assertSame('candidate_complete', $r['status']);
        $this->assertTrue($r['candidate_complete']);
        $this->assertSame([], $r['missing_conditions']);
        $this->assertSame('snapshot_db_and_revert_migration', $r['rollback_strategy']);

        // Completion is NOT execution (doc "Forbidden work" + dispatch-disabled).
        $this->assertFalse($r['is_execution']);
        $this->assertSame([
            'ai_session_started' => false,
            'packet_dispatched' => false,
            'migration_created' => false,
            'storage_write_performed' => false,
        ], $r['guarantee']);
        $this->assertTrue($this->service()->assertGuaranteeHeld($r));
    }

    /**
     * Fail-closed truthiness: a non-strict-true clearing signal (string "true")
     * does NOT clear a condition — the matching blocker stays active.
     */
    public function test_non_strict_true_signal_does_not_clear_condition(): void
    {
        $r = $this->service()->candidatePacket(['dispatch_disabled' => 'true']);

        $this->assertContains('dispatch_not_disabled', $r['missing_conditions']);
        $this->assertFalse($r['candidate_complete']);
    }
}
