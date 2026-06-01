<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasReservationLedgerContractService;
use Tests\TestCase;

/**
 * Pins the documented Reservation Ledger Contract: the six-state machine with
 * lease-expiry demotion, the SEVEN Collision Rules (including the distinct
 * packet_hash vs split_hash drift rules), the completion authority boundary and
 * the transition legality table.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
 */
class AtlasReservationLedgerContractTest extends TestCase
{
    private function service(): AtlasReservationLedgerContractService
    {
        return new AtlasReservationLedgerContractService();
    }

    /**
     * A `claimed` row whose lease has already passed resolves to `expired`, and
     * therefore no longer reads as active (doc State Rules: "expired means owner
     * timed out").
     */
    public function test_expired_lease_demotes_claimed_to_expired_and_not_active(): void
    {
        $svc = $this->service();
        $stale = ['state' => 'claimed', 'lease_expires_at' => '2000-01-01T00:00:00+00:00'];
        $fresh = ['state' => 'claimed', 'lease_expires_at' => '2999-01-01T00:00:00+00:00'];

        $this->assertSame('expired', $svc->effectiveState($stale));
        $this->assertFalse($svc->isActive($stale));

        $this->assertSame('claimed', $svc->effectiveState($fresh));
        $this->assertTrue($svc->isActive($fresh));

        // A row with no lease recorded fails closed to expired, never active.
        $this->assertSame('expired', $svc->effectiveState(['state' => 'claimed']));
    }

    /**
     * A clean candidate against an empty ledger is allow_claim with no blockers,
     * and the decision persists nothing.
     */
    public function test_clean_candidate_is_allow_claim(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-1',
            'allowed_files' => ['app/Services/Foo/Bar.php'],
            'completion_gate_status' => 'green',
            'ledger' => [],
        ]);

        $this->assertSame('allow_claim', $result['decision']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertFalse($result['claim_persisted']);
        $this->assertFalse($result['is_execution']);
    }

    /**
     * Collision Rule: a packet already claimed under an ACTIVE lease blocks a
     * re-claim, while the same packet under an EXPIRED lease does not (the lease
     * timed out, so it is reclaimable).
     */
    public function test_already_claimed_active_lease_blocks_but_expired_does_not(): void
    {
        $svc = $this->service();

        $activeLedger = [[
            'packet_id' => 'AIP-SPLIT-1',
            'state' => 'claimed',
            'lease_expires_at' => '2999-01-01T00:00:00+00:00',
            'allowed_files' => ['x.php'],
        ]];
        $blocked = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-1',
            'allowed_files' => ['x.php'],
            'ledger' => $activeLedger,
        ]);
        $this->assertSame('blocked', $blocked['decision']);
        $this->assertContains('packet_already_claimed', $blocked['blocking_reasons']);

        $expiredLedger = [[
            'packet_id' => 'AIP-SPLIT-1',
            'state' => 'claimed',
            'lease_expires_at' => '2000-01-01T00:00:00+00:00',
            'allowed_files' => ['x.php'],
        ]];
        $reclaim = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-1',
            'allowed_files' => ['x.php'],
            'ledger' => $expiredLedger,
        ]);
        $this->assertSame('allow_claim', $reclaim['decision']);
        $this->assertNotContains('packet_already_claimed', $reclaim['blocking_reasons']);
    }

    /** Collision Rule: a completed packet can never be reclaimed. */
    public function test_completed_packet_blocks_reclaim(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-9',
            'allowed_files' => ['a.php'],
            'ledger' => [[
                'packet_id' => 'AIP-SPLIT-9',
                'state' => 'completed',
                'allowed_files' => ['a.php'],
            ]],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('packet_already_completed', $result['blocking_reasons']);
    }

    /**
     * Collision Rule: allowed files overlapping ANOTHER active reservation are
     * blocked and the overlapping path is reported (disjoint write sets).
     */
    public function test_allowed_file_overlap_with_other_active_reservation_blocks(): void
    {
        $result = $this->service()->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-2',
            'allowed_files' => ['app/Shared/Conf.php', 'app/Only/Mine.php'],
            'ledger' => [[
                'packet_id' => 'AIP-SPLIT-OTHER',
                'state' => 'claimed',
                'lease_expires_at' => '2999-01-01T00:00:00+00:00',
                'allowed_files' => ['app/Shared/Conf.php'],
            ]],
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('allowed_files_overlap', $result['blocking_reasons']);
        $this->assertSame(['app/Shared/Conf.php'], $result['overlapping_files']);
    }

    /**
     * Collision Rules: packet_hash drift and split_hash drift are DISTINCT
     * blockers — the doc lists both "packet hash changed after assignment" and
     * "split hash changed after assignment".
     */
    public function test_packet_and_split_hash_drift_are_distinct_blockers(): void
    {
        $svc = $this->service();

        $packetDrift = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-3',
            'packet_hash' => 'h2',
            'assigned_packet_hash' => 'h1',
            'split_hash' => 's1',
            'assigned_split_hash' => 's1',
        ]);
        $this->assertContains('packet_hash_changed', $packetDrift['blocking_reasons']);
        $this->assertNotContains('split_hash_changed', $packetDrift['blocking_reasons']);

        $splitDrift = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-3',
            'packet_hash' => 'h1',
            'assigned_packet_hash' => 'h1',
            'split_hash' => 's2',
            'assigned_split_hash' => 's1',
        ]);
        $this->assertContains('split_hash_changed', $splitDrift['blocking_reasons']);
        $this->assertNotContains('packet_hash_changed', $splitDrift['blocking_reasons']);

        $this->assertSame('blocked', $packetDrift['decision']);
        $this->assertSame('blocked', $splitDrift['decision']);
    }

    /**
     * Collision Rules: a hot external scope path in allowed files blocks ("Do not
     * reserve hot external scopes"), and a non-green completion gate blocks.
     */
    public function test_hot_scope_and_completion_gate_block(): void
    {
        $svc = $this->service();

        $hot = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-4',
            'allowed_files' => ['app/Services/Ai/Voice/Realtime.php'],
        ]);
        $this->assertContains('hot_external_scope', $hot['blocking_reasons']);
        $this->assertSame(['app/Services/Ai/Voice/Realtime.php'], $hot['hot_scope_matches']);
        $this->assertSame('blocked', $hot['decision']);

        $gate = $svc->evaluateClaim([
            'candidate_packet_id' => 'AIP-SPLIT-4',
            'allowed_files' => ['app/Clean/File.php'],
            'completion_gate_status' => 'blocked',
        ]);
        $this->assertContains('completion_gate_blocked', $gate['blocking_reasons']);
        $this->assertSame('blocked', $gate['decision']);
    }

    /**
     * The `--complete-packet` boundary: completing an actively-claimed packet
     * moves it to `completed` but grants NO governance authority — it never
     * approves code, merges, dispatches, enables execution or marks the whole
     * Self-Construction OS complete. Completing a non-active row is rejected.
     */
    public function test_completion_persists_state_only_and_grants_no_authority(): void
    {
        $svc = $this->service();

        $accepted = $svc->completion(
            ['state' => 'claimed', 'lease_expires_at' => '2999-01-01T00:00:00+00:00'],
            'work done',
            'sha256:abc',
        );
        $this->assertTrue($accepted['accepted']);
        $this->assertSame('completed', $accepted['to_state']);
        $this->assertFalse($accepted['is_execution']);
        $this->assertSame([
            'approval_granted' => false,
            'changes_merged' => false,
            'work_dispatched' => false,
            'execution_enabled' => false,
            'self_construction_os_complete' => false,
        ], $accepted['authority']);

        // Cannot complete a packet you do not actively hold (expired lease).
        $rejected = $svc->completion(['state' => 'claimed', 'lease_expires_at' => '2000-01-01T00:00:00+00:00']);
        $this->assertFalse($rejected['accepted']);
        $this->assertSame('reservation_not_active', $rejected['reason']);
    }

    /**
     * Transition legality encodes the State Rules: a fresh/released/expired row
     * may be claimed; a completed row may never be reclaimed; release/complete
     * act only on an actively-claimed row.
     */
    public function test_transition_legality_table(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->transitionAllowed('preview', 'claimed'));
        $this->assertTrue($svc->transitionAllowed('expired', 'claimed'));
        $this->assertTrue($svc->transitionAllowed('released', 'claimed'));
        $this->assertFalse($svc->transitionAllowed('completed', 'claimed'));

        $this->assertTrue($svc->transitionAllowed('claimed', 'completed'));
        $this->assertTrue($svc->transitionAllowed('claimed', 'released'));
        $this->assertFalse($svc->transitionAllowed('preview', 'completed'));
        $this->assertFalse($svc->transitionAllowed('released', 'completed'));

        // Unknown states are rejected.
        $this->assertFalse($svc->transitionAllowed('claimed', 'frozen'));
    }
}
