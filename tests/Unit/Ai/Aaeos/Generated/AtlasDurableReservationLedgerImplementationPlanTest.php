<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLedgerImplementationPlanService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Ledger IMPLEMENTATION PLAN: the three
 * required storage tables, the seven required states, the eight ORDERED
 * atomic-claim steps (fail at the earliest violated gate), the lease rules, the
 * ten required evidence fields and the seven-invariant promotion gate.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
 */
class AtlasDurableReservationLedgerImplementationPlanTest extends TestCase
{
    private function service(): AtlasDurableReservationLedgerImplementationPlanService
    {
        return new AtlasDurableReservationLedgerImplementationPlanService();
    }

    /** Doc "Required Storage" + "Required States": the closed sets are exactly as documented. */
    public function test_required_storage_and_states_are_the_documented_closed_sets(): void
    {
        $svc = $this->service();

        $this->assertSame([
            'atlas_self_construction_reservations',
            'atlas_self_construction_reservation_events',
            'atlas_self_construction_packet_snapshots',
        ], $svc->requiredStorage());

        $this->assertSame([
            'available', 'claimed', 'renewed', 'released', 'expired', 'completed', 'blocked',
        ], AtlasDurableReservationLedgerImplementationPlanService::STATES);

        // Evidence Rules: exactly ten required fields.
        $this->assertCount(10, $svc->evidenceFields());
    }

    /** Doc "Atomic Claim Rules": a clean candidate passes all gates and yields `claimed`. */
    public function test_clean_candidate_is_allowed_and_yields_claimed_state(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-clean',
            'allowed_files' => ['app/Services/Foo/Bar.php'],
            'packet_hash' => 'h-1',
            'assigned_packet_hash' => 'h-1',
            'split_hash' => 's-1',
            'assigned_split_hash' => 's-1',
            'ledger' => [
                ['packet_id' => 'pkt-other', 'state' => 'claimed', 'allowed_files' => ['app/Services/Other/Baz.php']],
            ],
        ]);

        $this->assertSame('allow_claim', $result['decision']);
        $this->assertSame('claimed', $result['resulting_state']);
        $this->assertNull($result['failed_step']);
        $this->assertSame([], $result['overlapping_files']);
    }

    /**
     * Doc "Atomic Claim Rules" ORDER: when BOTH a stale hash (step 2) and a file
     * overlap (step 4) are present, the transaction aborts at the EARLIER step —
     * stale hash wins. This pins the documented ordering, not just "blocked".
     */
    public function test_stale_hash_is_caught_before_file_overlap(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-x',
            'allowed_files' => ['app/Services/Shared/Conflict.php'],
            'packet_hash' => 'h-NEW',
            'assigned_packet_hash' => 'h-OLD', // stale
            'ledger' => [
                // An active OTHER reservation that also overlaps the same file.
                ['packet_id' => 'pkt-other', 'state' => 'claimed', 'allowed_files' => ['app/Services/Shared/Conflict.php']],
            ],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('reject_stale_hashes', $result['failed_step']);
        $this->assertSame('packet_hash_stale', $result['blocking_reason']);
    }

    /** Doc "Atomic Claim Rules" step 5: a hot forbidden scope in allowed files blocks the claim. */
    public function test_hot_forbidden_scope_blocks_claim(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-hot',
            'allowed_files' => ['app/Services/Ai/Voice/RealtimeSession.php'],
            'packet_hash' => 'h', 'assigned_packet_hash' => 'h',
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('reject_hot_scope', $result['failed_step']);
        $this->assertSame(['app/Services/Ai/Voice/RealtimeSession.php'], $result['hot_scope_matches']);
    }

    /**
     * Doc "Required States" + "Atomic Claim Rules" step 3: two sessions cannot
     * claim the same packet — an active claim on the candidate's own packet
     * blocks a second claim.
     */
    public function test_two_sessions_cannot_claim_same_packet(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'pkt-dup',
            'allowed_files' => ['app/Services/Foo/A.php'],
            'ledger' => [
                ['packet_id' => 'pkt-dup', 'state' => 'claimed', 'allowed_files' => ['app/Services/Foo/A.php']],
            ],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('reject_active_reservation', $result['failed_step']);
        $this->assertSame('packet_already_claimed', $result['blocking_reason']);
    }

    /** Doc "Lease Rules": an expired lease can NEVER be completed. */
    public function test_expired_lease_cannot_complete(): void
    {
        // A claimed row whose lease passed is demoted to `expired`, so completion
        // is refused with reason `lease_expired`.
        $row = [
            'state' => 'claimed',
            'owner_id' => 'session-A',
            'lease_expires_at' => '2000-01-01T00:00:00+00:00',
        ];

        $result = $this->service()->completion($row, 'session-A');

        $this->assertFalse($result['accepted']);
        $this->assertSame('expired', $result['from_state']);
        $this->assertSame('lease_expired', $result['reason']);
    }

    /** Doc "Lease Rules": completion requires the COMPLETING session to own an active claim. */
    public function test_completion_requires_owning_session(): void
    {
        $future = date('c', time() + 3600);
        $row = ['state' => 'claimed', 'owner_id' => 'session-A', 'lease_expires_at' => $future];
        $svc = $this->service();

        // Wrong owner => rejected.
        $wrong = $svc->completion($row, 'session-B');
        $this->assertFalse($wrong['accepted']);
        $this->assertSame('owner_mismatch', $wrong['reason']);

        // Right owner, active lease => accepted, lands in `completed`.
        $right = $svc->completion($row, 'session-A');
        $this->assertTrue($right['accepted']);
        $this->assertSame('completed', $right['to_state']);
    }

    /** Doc "Lease Rules": renewal must prove packet hash AND allowed files did not change. */
    public function test_renewal_rejects_changed_hash_or_files(): void
    {
        $future = date('c', time() + 3600);
        $svc = $this->service();
        $row = [
            'state' => 'claimed',
            'lease_expires_at' => $future,
            'packet_hash' => 'h-1',
            'allowed_files' => ['app/A.php'],
        ];

        $changedHash = $svc->renewal($row, ['packet_hash' => 'h-2', 'allowed_files' => ['app/A.php']]);
        $this->assertFalse($changedHash['accepted']);
        $this->assertSame('packet_hash_changed', $changedHash['reason']);

        $ok = $svc->renewal($row, ['packet_hash' => 'h-1', 'allowed_files' => ['app/A.php']]);
        $this->assertTrue($ok['accepted']);
        $this->assertSame('renewed', $ok['to_state']);
    }

    /** Doc "Required States": released/expired return to `available`, completed can never be re-claimed. */
    public function test_transition_rules_enforce_state_machine(): void
    {
        $svc = $this->service();

        // A released row returns to the pool.
        $this->assertTrue($svc->transitionAllowed('released', 'available'));
        // A claim originates only from `available`.
        $this->assertTrue($svc->transitionAllowed('available', 'claimed'));
        // A completed packet can never be re-claimed.
        $this->assertFalse($svc->transitionAllowed('completed', 'claimed'));
        // An expired lease can never be completed.
        $this->assertFalse($svc->transitionAllowed('expired', 'completed'));
    }

    /** Doc "Evidence Rules": an event missing any of the ten fields fails closed. */
    public function test_evidence_event_fails_closed_when_field_missing(): void
    {
        $svc = $this->service();

        $full = [
            'packet_id' => 'p', 'owner_id' => 'o', 'packet_hash' => 'ph', 'split_hash' => 'sh',
            'allowed_files_hash' => 'afh', 'forbidden_files_hash' => 'ffh', 'scope_validator_hash' => 'svh',
            'gate_hash' => 'gh', 'prior_event_hash' => 'peh', 'created_at' => '2026-06-01T00:00:00+00:00',
        ];
        $this->assertTrue($svc->eventIsComplete($full)['complete']);

        unset($full['prior_event_hash']);
        $partial = $svc->eventIsComplete($full);
        $this->assertFalse($partial['complete']);
        $this->assertSame(['prior_event_hash'], $partial['missing']);
    }

    /** Doc "Promotion Gate": not ready until ALL SEVEN invariants are proven. */
    public function test_promotion_gate_requires_all_seven_invariants(): void
    {
        $svc = $this->service();

        // Empty => not_ready, all seven missing.
        $empty = $svc->promotionGate([]);
        $this->assertFalse($empty['ready']);
        $this->assertSame('not_ready', $empty['status']);
        $this->assertCount(7, $empty['missing_invariants']);

        // Six of seven proven => still not_ready, exactly one missing.
        $almost = $svc->promotionGate([
            'two_sessions_cannot_claim_same_packet' => true,
            'overlapping_allowed_files_blocked' => true,
            'hot_scopes_blocked' => true,
            'stale_packet_hashes_blocked' => true,
            'expired_claims_cannot_complete' => true,
            'released_claims_become_available' => true,
            // 'append_only_events_cannot_be_rewritten' missing
        ]);
        $this->assertFalse($almost['ready']);
        $this->assertSame(['append_only_events_cannot_be_rewritten'], $almost['missing_invariants']);

        // All seven proven => ready.
        $all = $svc->promotionGate(array_fill_keys(
            AtlasDurableReservationLedgerImplementationPlanService::PROMOTION_INVARIANTS,
            true
        ));
        $this->assertTrue($all['ready']);
        $this->assertSame('ready', $all['status']);
    }

    /** Doc "Non Goals": every decision keeps the read-only guarantee — nothing executed. */
    public function test_non_goal_guarantee_holds_across_decisions(): void
    {
        $svc = $this->service();
        $claim = $svc->evaluateClaim([]);
        $completion = $svc->completion(['state' => 'available'], 'x');
        $promotion = $svc->promotionGate([]);

        // No decision may report it executed or flip any Non Goal flag to true.
        $this->assertSame([], $svc->assertGuaranteeHeld([$claim, $promotion, $completion]));

        // The per-candidate decisions carry the explicit is_execution=false flag.
        $this->assertFalse($claim['is_execution']);
        $this->assertFalse($completion['is_execution']);

        // Every surface forces all six Non Goal flags false.
        foreach ([$claim, $completion, $promotion] as $r) {
            $this->assertSame(
                array_fill_keys(AtlasDurableReservationLedgerImplementationPlanService::NON_GOAL_KEYS, false),
                $r['non_goals']
            );
        }
    }
}
