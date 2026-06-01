<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationRepositoryContractService as Repo;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Repository CONTRACT: the eight Required
 * Methods, the nine Required Errors (classified by the decider, not echoed), the
 * Transaction Rules (lock-before-claim, append-event-before-projection, reject on
 * broken hash chain, release/completion terminal, never dispatch) and the
 * hot-scope ban.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
 */
class AtlasDurableReservationRepositoryContractTest extends TestCase
{
    private function repo(): Repo
    {
        return new Repo();
    }

    /**
     * Doc "Required Methods" (8) + "Required Errors" (9): the contract enumerates
     * exactly the documented method names and error states, and separates the
     * five write methods from the three read methods.
     */
    public function test_contract_pins_eight_methods_and_nine_errors(): void
    {
        $contract = $this->repo()->contract();

        $this->assertSame(
            ['claim', 'renew', 'release', 'expire', 'complete', 'current', 'activeCollisions', 'rebuildProjection'],
            array_keys($contract['required_methods']),
        );
        $this->assertSame('claim(packet, actor, scope, lease)', $contract['required_methods']['claim']);

        $this->assertSame(
            ['claim', 'renew', 'release', 'expire', 'complete'],
            $contract['write_methods'],
        );

        // Exactly the nine documented Required Errors, in doc order.
        $this->assertSame([
            'packet_already_claimed',
            'packet_already_completed',
            'allowed_files_overlap_active_reservation',
            'hot_scope_forbidden',
            'packet_hash_stale',
            'lease_expired',
            'completion_gate_missing',
            'actor_not_owner',
            'event_chain_mismatch',
        ], $contract['required_errors']);
    }

    /**
     * Doc Required Errors on claim: a fresh packet with a free scope is accepted;
     * an active duplicate fires packet_already_claimed; a completed packet fires
     * packet_already_completed (NOT already_claimed). The decider classifies the
     * exact documented error.
     */
    public function test_claim_classifies_duplicate_and_completed_packets(): void
    {
        $repo = $this->repo();

        // Fresh packet, empty projection => accept, no errors.
        $fresh = $repo->decideClaim(['packet_id' => 'PKT-1', 'allowed_files' => ['app/A.php']], []);
        $this->assertSame('accept', $fresh['verdict']);
        $this->assertSame([], $fresh['errors']);

        // Active claim already holds PKT-1 => packet_already_claimed.
        $active = ['PKT-1' => ['packet_id' => 'PKT-1', 'state' => 'claimed', 'allowed_files' => ['app/A.php']]];
        $dup = $repo->decideClaim(['packet_id' => 'PKT-1', 'allowed_files' => ['app/A.php']], $active);
        $this->assertSame('reject', $dup['verdict']);
        $this->assertContains('packet_already_claimed', $dup['errors']);
        $this->assertNotContains('packet_already_completed', $dup['errors']);

        // Completed packet => packet_already_completed, never already_claimed.
        $done = ['PKT-1' => ['packet_id' => 'PKT-1', 'state' => 'completed', 'allowed_files' => ['app/A.php']]];
        $reclaim = $repo->decideClaim(['packet_id' => 'PKT-1', 'allowed_files' => ['app/A.php']], $done);
        $this->assertContains('packet_already_completed', $reclaim['errors']);
        $this->assertNotContains('packet_already_claimed', $reclaim['errors']);
    }

    /**
     * Doc Required Errors on claim: overlapping allowed files with ANOTHER active
     * reservation fires allowed_files_overlap_active_reservation; a stale packet
     * hash fires packet_hash_stale; a hot Voice/Kernel scope fires
     * hot_scope_forbidden.
     */
    public function test_claim_fires_overlap_stale_hash_and_hot_scope(): void
    {
        $repo = $this->repo();

        // Another active reservation (PKT-9) already holds app/Shared.php.
        $projection = ['PKT-9' => ['packet_id' => 'PKT-9', 'state' => 'renewed', 'allowed_files' => ['app/Shared.php']]];
        $overlap = $repo->decideClaim(['packet_id' => 'PKT-2', 'allowed_files' => ['app/Shared.php']], $projection);
        $this->assertContains('allowed_files_overlap_active_reservation', $overlap['errors']);

        // Expected hash differs from the packet's current hash => stale.
        $stale = $repo->decideClaim(
            ['packet_id' => 'PKT-3', 'allowed_files' => ['app/B.php'], 'packet_hash' => 'sha256:new'],
            [],
            'sha256:old',
        );
        $this->assertContains('packet_hash_stale', $stale['errors']);

        // Hot Voice scope (prefix) => hot_scope_forbidden.
        $hot = $repo->decideClaim(
            ['packet_id' => 'PKT-4', 'allowed_files' => ['app/Services/Ai/Voice/Foo.php']],
            [],
        );
        $this->assertContains('hot_scope_forbidden', $hot['errors']);
        $this->assertSame('reject', $hot['verdict']);
    }

    /**
     * Doc rules: "non-owner cannot release or complete" (actor_not_owner) and
     * "release and completion are terminal" (a released/completed reservation can
     * no longer be released => lease_expired). An owner releasing an active claim
     * is accepted.
     */
    public function test_release_enforces_owner_and_terminal_state(): void
    {
        $repo = $this->repo();

        $active = ['state' => 'claimed', 'actor' => 'session-a'];

        // Owner releases an active claim => accept.
        $ok = $repo->decideRelease($active, 'session-a');
        $this->assertSame('accept', $ok['verdict']);
        $this->assertSame([], $ok['errors']);

        // Different actor => actor_not_owner.
        $notOwner = $repo->decideRelease($active, 'session-b');
        $this->assertContains('actor_not_owner', $notOwner['errors']);

        // Already released (terminal) => cannot release again (lease_expired).
        $terminal = $repo->decideRelease(['state' => 'released', 'actor' => 'session-a'], 'session-a');
        $this->assertSame('reject', $terminal['verdict']);
        $this->assertContains('lease_expired', $terminal['errors']);
    }

    /**
     * Doc rules on complete: "expired lease cannot complete" (lease_expired) and
     * completion only "after gates have been run" (completion_gate_missing). An
     * owner completing an active claim with the gate passed is accepted.
     */
    public function test_complete_requires_owner_live_lease_and_gate(): void
    {
        $repo = $this->repo();

        $active = ['state' => 'claimed', 'actor' => 'session-a'];

        // Owner, live lease, gate passed => accept.
        $ok = $repo->decideComplete($active, 'session-a', completionGatePassed: true);
        $this->assertSame('accept', $ok['verdict']);

        // Gate not run => completion_gate_missing.
        $noGate = $repo->decideComplete($active, 'session-a', completionGatePassed: false);
        $this->assertContains('completion_gate_missing', $noGate['errors']);

        // Active row but lease flagged expired => lease_expired even with gate.
        $expired = $repo->decideComplete(
            ['state' => 'claimed', 'actor' => 'session-a', 'lease_expired' => true],
            'session-a',
            completionGatePassed: true,
        );
        $this->assertContains('lease_expired', $expired['errors']);
    }

    /**
     * Doc Transaction Rules: "Append event before projection update" and "Reject
     * projection update when event hash chain is broken." A projection update with
     * its event appended is accepted; a write whose event was not appended, and a
     * broken hash chain, both fire event_chain_mismatch (fail closed). Only claim
     * acquires the packet-scope lock, and no result enables dispatch.
     */
    public function test_event_ordering_chain_lock_and_no_dispatch(): void
    {
        $repo = $this->repo();

        // Projection update after the event was appended => accept.
        $ordered = $repo->decideProjectionUpdate('claim', eventAppended: true);
        $this->assertSame('accept', $ordered['verdict']);

        // Projection update before the event was appended => event_chain_mismatch.
        $before = $repo->decideProjectionUpdate('claim', eventAppended: false);
        $this->assertContains('event_chain_mismatch', $before['errors']);

        // Valid linked chain => accept, no break.
        $good = $repo->validateEventChain([
            ['event_hash' => 'h1', 'previous_event_hash' => null],
            ['event_hash' => 'h2', 'previous_event_hash' => 'h1'],
        ]);
        $this->assertSame('accept', $good['verdict']);
        $this->assertNull($good['broken_at']);

        // Broken link at index 1 => reject + broken_at = 1.
        $broken = $repo->validateEventChain([
            ['event_hash' => 'h1', 'previous_event_hash' => null],
            ['event_hash' => 'h2', 'previous_event_hash' => 'WRONG'],
        ]);
        $this->assertSame('reject', $broken['verdict']);
        $this->assertContains('event_chain_mismatch', $broken['errors']);
        $this->assertSame(1, $broken['broken_at']);

        // "Acquire packet and allowed-file-scope lock before claim": only claim.
        $this->assertTrue($repo->requiresPacketScopeLock('claim'));
        $this->assertFalse($repo->requiresPacketScopeLock('release'));

        // "Never enable dispatch from repository methods": every result holds.
        $this->assertSame([], $repo->assertNoDispatch([
            $repo->contract(), $ordered, $before, $good, $broken,
        ]));
    }
}
