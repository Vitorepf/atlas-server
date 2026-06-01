<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLeaseLifecycleBlueprintContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Lease Lifecycle blueprint: the seven
 * Required States, the ten Required Transitions, the Timing Rules (same-owner +
 * active-lease for renew/release/complete, green completion gate, expired/
 * released cannot complete, reclaim hands to a new owner) and the read-only
 * non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md
 */
class AtlasDurableReservationLeaseLifecycleBlueprintContractTest extends TestCase
{
    private function service(): AtlasDurableReservationLeaseLifecycleBlueprintContractService
    {
        return new AtlasDurableReservationLeaseLifecycleBlueprintContractService();
    }

    /** Doc "Required States": exactly the seven canonical states, in order. */
    public function test_blueprint_declares_the_seven_required_states(): void
    {
        $blueprint = $this->service()->blueprint();

        $this->assertSame(
            ['preview', 'claimed', 'renewed', 'released', 'expired', 'completed', 'blocked'],
            $blueprint['states'],
        );
        // released/expired are the only reclaimable states.
        $this->assertSame(['released', 'expired'], $blueprint['reclaimable_states']);
        $this->assertSame('green', $blueprint['completion_gate_required']);
    }

    /** Doc "Required Transitions": the owner can claim, then renew an active lease. */
    public function test_owner_can_claim_then_renew_active_lease(): void
    {
        $service = $this->service();

        $claim = $service->decide([
            'from_state' => 'preview',
            'action' => 'claim',
        ]);
        $this->assertSame('allow', $claim['verdict']);
        $this->assertSame('claimed', $claim['to_state']);
        $this->assertSame('lease_claimed', $claim['appended_event']);

        $renew = $service->decide([
            'from_state' => 'claimed',
            'action' => 'renew',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'lease_expired' => false,
        ]);
        $this->assertSame('allow', $renew['verdict']);
        $this->assertSame('renewed', $renew['to_state']);
        $this->assertSame('lease_renewed', $renew['appended_event']);
    }

    /**
     * Doc Timing Rule: "non-owner cannot renew, release or complete" — a
     * different actor session is fail-closed to `blocked` with owner_mismatch
     * (the documented any_to_blocked_when_guard_rejects_action transition).
     */
    public function test_non_owner_cannot_renew_release_or_complete(): void
    {
        $service = $this->service();

        foreach (['renew', 'release', 'complete'] as $action) {
            $result = $service->decide([
                'from_state' => 'claimed',
                'action' => $action,
                'current_owner_session_id' => 'session-owner',
                'actor_session_id' => 'session-intruder',
                'lease_expired' => false,
                'completion_gate_status' => 'green',
            ]);

            $this->assertSame('block', $result['verdict'], "action {$action} by non-owner must block");
            $this->assertSame('blocked', $result['to_state']);
            $this->assertContains('owner_mismatch', $result['reasons']);
        }
    }

    /**
     * Doc Timing Rule: "expired or released leases cannot complete." An expired
     * lease (lease_expired flag) is not active, so completion blocks even with a
     * matching owner and green gate.
     */
    public function test_expired_lease_cannot_complete(): void
    {
        $result = $this->service()->decide([
            'from_state' => 'claimed',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'lease_expired' => true,
            'completion_gate_status' => 'green',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertContains('lease_not_active', $result['reasons']);

        // And the canComplete helper agrees.
        $this->assertFalse(
            $this->service()->canComplete('claimed', 'session-owner', 'session-owner', true, 'green'),
        );
    }

    /**
     * Doc "Required Transitions": a `released` lease is terminal for the owner —
     * `released` exposes no complete transition, so completing it is rejected as
     * transition_not_allowed (it never even reaches the timing rules).
     */
    public function test_released_lease_cannot_complete(): void
    {
        $result = $this->service()->decide([
            'from_state' => 'released',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'completion_gate_status' => 'green',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertContains('transition_not_allowed', $result['reasons']);
        $this->assertFalse(
            $this->service()->canComplete('released', 'session-owner', 'session-owner', false, 'green'),
        );
    }

    /**
     * Doc Timing Rule: "completion requires same owner, active lease and passing
     * completion gate." A non-green gate blocks even when owner + active lease
     * are satisfied.
     */
    public function test_completion_requires_green_gate(): void
    {
        $service = $this->service();

        $blocked = $service->decide([
            'from_state' => 'renewed',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'lease_expired' => false,
            'completion_gate_status' => 'pending',
        ]);
        $this->assertSame('block', $blocked['verdict']);
        $this->assertContains('completion_gate_not_green', $blocked['reasons']);

        // With a green gate, the same request completes from renewed.
        $allowed = $service->decide([
            'from_state' => 'renewed',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'lease_expired' => false,
            'completion_gate_status' => 'green',
        ]);
        $this->assertSame('allow', $allowed['verdict']);
        $this->assertSame('completed', $allowed['to_state']);
        $this->assertSame('lease_completed', $allowed['appended_event']);
    }

    /**
     * Doc "Reclaim semantics" + released_or_expired_to_claimed_by_new_owner: an
     * expired/released packet is reclaimable by a NEW owner (owner handover),
     * but the prior owner silently resuming is rejected.
     */
    public function test_expired_or_released_packet_reclaimed_by_new_owner_only(): void
    {
        $service = $this->service();

        foreach (['expired', 'released'] as $state) {
            // New owner reclaims => allowed, lands in claimed, owner handover.
            $reclaim = $service->decide([
                'from_state' => $state,
                'action' => 'reclaim',
                'current_owner_session_id' => 'session-prior',
                'actor_session_id' => 'session-new',
            ]);
            $this->assertSame('allow', $reclaim['verdict'], "{$state} must be reclaimable");
            $this->assertSame('claimed', $reclaim['to_state']);
            $this->assertTrue($reclaim['owner_handover']);
            $this->assertSame('lease_reclaimed', $reclaim['appended_event']);
            $this->assertTrue($service->canReclaim($state, 'session-prior', 'session-new'));

            // Prior owner attempting to silently resume => blocked.
            $resume = $service->decide([
                'from_state' => $state,
                'action' => 'reclaim',
                'current_owner_session_id' => 'session-prior',
                'actor_session_id' => 'session-prior',
            ]);
            $this->assertSame('block', $resume['verdict']);
            $this->assertContains('reclaim_requires_new_owner', $resume['reasons']);
            $this->assertFalse($service->canReclaim($state, 'session-prior', 'session-prior'));
        }
    }

    /**
     * Doc transition any_to_blocked_when_guard_rejects_action: a `block` action
     * is admissible from any state and lands in blocked; and the non-execution
     * guarantee holds on every decision path (allow, structural block, rule
     * block) plus on the blueprint itself.
     */
    public function test_block_from_any_state_and_guarantee_holds_on_every_path(): void
    {
        $service = $this->service();

        $block = $service->decide(['from_state' => 'renewed', 'action' => 'block']);
        $this->assertSame('block', $block['verdict']);
        $this->assertSame('blocked', $block['to_state']);

        $allow = $service->decide(['from_state' => 'preview', 'action' => 'claim']);
        $structural = $service->decide(['from_state' => 'completed', 'action' => 'renew']);
        $blueprint = $service->blueprint();

        foreach ([$block, $allow, $structural, $blueprint] as $packet) {
            $this->assertFalse($packet['claim_persisted']);
            $this->assertFalse($packet['is_execution']);
            $this->assertSame([
                'runtime_files_created' => false,
                'storage_writes_performed' => false,
                'claims_persisted' => false,
                'work_dispatched' => false,
            ], $packet['guarantee']);
        }

        $this->assertSame([], $service->assertGuaranteeHeld([$block, $allow, $structural, $blueprint]));
    }
}
