<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationLeaseLifecycleContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Lease Lifecycle CONTRACT: the seven
 * Lifecycle States, the NINE Required Transitions (reclaim is NOT a distinct
 * action here — it is an event-backed `claim`), the Timing Rules and the
 * Required Tests, plus the read-only non-execution guarantee
 * ("lifecycle command does not persist claims or write storage").
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
 */
class AtlasDurableReservationLeaseLifecycleContractTest extends TestCase
{
    private function service(): AtlasDurableReservationLeaseLifecycleContractService
    {
        return new AtlasDurableReservationLeaseLifecycleContractService();
    }

    /**
     * Doc "Lifecycle States" + "Required Transitions": exactly the seven states,
     * and the transition table holds the nine documented transitions (with the
     * any -> blocked synthetic row), and crucially NO standalone `reclaim`
     * action exists — reclaim is a `claim` from released/expired.
     */
    public function test_contract_declares_seven_states_and_nine_transitions_without_reclaim_action(): void
    {
        $contract = $this->service()->contract();

        $this->assertSame(
            ['preview', 'claimed', 'renewed', 'released', 'expired', 'completed', 'blocked'],
            $contract['states'],
        );
        $this->assertSame(['released', 'expired'], $contract['reclaimable_states']);
        $this->assertSame('green', $contract['completion_gate_required']);
        // "Default lease duration must be explicit in config or policy."
        $this->assertSame(['default_lease_duration'], $contract['required_policy_keys']);

        // Pin the exact documented from->action->to transitions. The doc's nine
        // "Required Transitions" are these eight concrete owner/system rows plus
        // the any -> blocked guard row. The two released/expired --claim-->
        // claimed rows ARE the event-backed reclaim (no separate reclaim action).
        $tuples = array_map(
            static fn (array $t): string => $t['from'].'.'.$t['action'].'.'.$t['to'],
            $contract['transitions'],
        );
        $expected = [
            'preview.claim.claimed',
            'claimed.renew.renewed',
            'claimed.release.released',
            'claimed.expire.expired',
            'claimed.complete.completed',
            'renewed.renew.renewed',
            'renewed.release.released',
            'renewed.expire.expired',
            'renewed.complete.completed',
            'released.claim.claimed',
            'expired.claim.claimed',
            '*.block.blocked',
        ];
        sort($tuples);
        sort($expected);
        $this->assertSame($expected, $tuples);

        $actions = array_values(array_unique(array_column($contract['transitions'], 'action')));
        sort($actions);
        $this->assertSame(['block', 'claim', 'complete', 'expire', 'release', 'renew'], $actions);
        // No `reclaim` action exists in this contract (unlike the blueprint sibling).
        $this->assertNotContains('reclaim', $actions);
    }

    /** Doc "Required Transitions": owner can claim from preview, then renew an active lease. */
    public function test_owner_can_claim_then_renew_active_lease(): void
    {
        $service = $this->service();

        $claim = $service->decide(['from_state' => 'preview', 'action' => 'claim']);
        $this->assertSame('allow', $claim['verdict']);
        $this->assertSame('claimed', $claim['to_state']);
        $this->assertSame('lease_claimed', $claim['appended_event']);
        $this->assertFalse($claim['is_reclaim']);

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
     * Doc Required Test: "non-owner cannot renew or release." A different actor
     * session is fail-closed to `blocked` with owner_mismatch (the documented
     * any -> blocked transition).
     */
    public function test_non_owner_cannot_renew_or_release(): void
    {
        $service = $this->service();

        foreach (['renew', 'release'] as $action) {
            $result = $service->decide([
                'from_state' => 'claimed',
                'action' => $action,
                'current_owner_session_id' => 'session-owner',
                'actor_session_id' => 'session-intruder',
                'lease_expired' => false,
            ]);

            $this->assertSame('block', $result['verdict'], "action {$action} by non-owner must block");
            $this->assertSame('blocked', $result['to_state']);
            $this->assertContains('owner_mismatch', $result['reasons']);
        }
    }

    /**
     * Doc Required Test: "expired lease cannot complete." With the lease_expired
     * flag the lease is not active, so completion blocks even with matching
     * owner and green gate.
     */
    public function test_expired_lease_cannot_complete(): void
    {
        $service = $this->service();

        $result = $service->decide([
            'from_state' => 'claimed',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'lease_expired' => true,
            'completion_gate_status' => 'green',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertContains('lease_not_active', $result['reasons']);
        $this->assertFalse($service->canComplete('claimed', 'session-owner', 'session-owner', true, 'green'));
    }

    /**
     * Doc Required Test: "released lease cannot complete." `released` exposes no
     * complete transition, so it is rejected as transition_not_allowed before
     * the timing rules even run.
     */
    public function test_released_lease_cannot_complete(): void
    {
        $service = $this->service();

        $result = $service->decide([
            'from_state' => 'released',
            'action' => 'complete',
            'current_owner_session_id' => 'session-owner',
            'actor_session_id' => 'session-owner',
            'completion_gate_status' => 'green',
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertContains('transition_not_allowed', $result['reasons']);
        $this->assertFalse($service->canComplete('released', 'session-owner', 'session-owner', false, 'green'));
    }

    /**
     * Doc Required Test: "completion requires packet completion gate pass." A
     * non-green gate blocks even with owner + active lease; a green gate from
     * renewed completes and appends lease_completed.
     */
    public function test_completion_requires_packet_completion_gate_pass(): void
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
     * Doc Required Test: "expired packet can be reclaimed after expiry event" —
     * and the frontmatter rule "allow safe reclaim ONLY through event-backed
     * state transitions." A fresh claim from expired/released is admitted ONLY
     * when (a) the matching prior event was appended AND (b) a NEW owner takes
     * it. No prior event, or the prior owner silently re-claiming, blocks.
     */
    public function test_expired_packet_reclaimed_only_after_event_and_by_new_owner(): void
    {
        $service = $this->service();

        // expired + matching expiry event + new owner => allowed reclaim.
        $reclaim = $service->decide([
            'from_state' => 'expired',
            'action' => 'claim',
            'current_owner_session_id' => 'session-prior',
            'actor_session_id' => 'session-new',
            'prior_event' => 'lease_expired',
        ]);
        $this->assertSame('allow', $reclaim['verdict']);
        $this->assertSame('claimed', $reclaim['to_state']);
        $this->assertTrue($reclaim['is_reclaim']);
        $this->assertSame('lease_reclaimed', $reclaim['appended_event']);
        $this->assertTrue($service->canReclaim('expired', 'session-prior', 'session-new', 'lease_expired'));

        // No prior event => reclaim is not event-backed => blocked.
        $noEvent = $service->decide([
            'from_state' => 'expired',
            'action' => 'claim',
            'current_owner_session_id' => 'session-prior',
            'actor_session_id' => 'session-new',
        ]);
        $this->assertSame('block', $noEvent['verdict']);
        $this->assertContains('reclaim_requires_prior_event', $noEvent['reasons']);

        // Prior owner silently re-claiming => blocked even with the event.
        $sameOwner = $service->decide([
            'from_state' => 'released',
            'action' => 'claim',
            'current_owner_session_id' => 'session-prior',
            'actor_session_id' => 'session-prior',
            'prior_event' => 'lease_released',
        ]);
        $this->assertSame('block', $sameOwner['verdict']);
        $this->assertContains('reclaim_requires_new_owner', $sameOwner['reasons']);
        $this->assertFalse($service->canReclaim('released', 'session-prior', 'session-prior', 'lease_released'));
    }

    /**
     * Doc Timing Rule: "Expire may be system-driven and must append an event."
     * A system-driven expiry with no actor owner is allowed and appends
     * lease_expired.
     */
    public function test_expire_may_be_system_driven_and_appends_event(): void
    {
        $result = $this->service()->decide([
            'from_state' => 'claimed',
            'action' => 'expire',
            'system_driven' => true,
        ]);

        $this->assertSame('allow', $result['verdict']);
        $this->assertSame('expired', $result['to_state']);
        $this->assertSame('lease_expired', $result['appended_event']);
    }

    /**
     * Doc Required Test: "lifecycle command does not persist claims or write
     * storage." Every decision path (allow, structural block, rule block) and
     * the contract itself keep all non-execution guarantee keys false.
     */
    public function test_lifecycle_command_does_not_persist_claims_or_write_storage(): void
    {
        $service = $this->service();

        $allow = $service->decide(['from_state' => 'preview', 'action' => 'claim']);
        $structural = $service->decide(['from_state' => 'completed', 'action' => 'renew']);
        $ruleBlock = $service->decide([
            'from_state' => 'claimed',
            'action' => 'complete',
            'current_owner_session_id' => 'a',
            'actor_session_id' => 'b',
            'completion_gate_status' => 'green',
        ]);
        $contract = $service->contract();

        foreach ([$allow, $structural, $ruleBlock, $contract] as $packet) {
            $this->assertFalse($packet['claim_persisted']);
            $this->assertFalse($packet['is_execution']);
            $this->assertSame([
                'claims_persisted' => false,
                'storage_writes_performed' => false,
                'migrations_created' => false,
                'work_dispatched' => false,
            ], $packet['guarantee']);
        }

        $this->assertSame([], $service->assertGuaranteeHeld([$allow, $structural, $ruleBlock, $contract]));
    }
}
