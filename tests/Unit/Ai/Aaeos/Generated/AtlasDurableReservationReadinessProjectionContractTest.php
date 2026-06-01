<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationReadinessProjectionContractService as Projection;
use Tests\TestCase;

/**
 * Pins the documented Derived Queue State rules and Readiness Outputs of the
 * Durable Reservation Readiness Projection Contract:
 *   - active reservation removes a packet from the claimable queue (claimed);
 *   - completed dependency unlocks a dependent packet;
 *   - a hot scope blocks a packet before queue assignment;
 *   - a stale packet hash blocks the claim;
 *   - file overlap with another owner's active claim is blocked_by_collision;
 *   - the multi-session gate reports ready_for_multi_session_preview only with
 *     five available cold-lane packets AND a durable ledger;
 *   - the single-session fallback is always available;
 *   - the projection never persists claims or writes storage.
 * Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
 */
class AtlasDurableReservationReadinessProjectionContractTest extends TestCase
{
    private function service(): Projection
    {
        return new Projection;
    }

    /** queue_state for a packet id within a projection result. */
    private function stateOf(array $result, string $packetId): ?string
    {
        foreach ($result['projection'] as $entry) {
            if ($entry['packet_id'] === $packetId) {
                return $entry['queue_state'];
            }
        }

        return null;
    }

    /** N disjoint available cold-lane packets, each touching its own path. */
    private function disjointColdPackets(int $n): array
    {
        $packets = [];
        for ($i = 1; $i <= $n; $i++) {
            $packets[] = ['id' => "packet-{$i}", 'lane' => 'cold', 'allowed_files' => ["app/area-{$i}/File.php"]];
        }

        return $packets;
    }

    public function test_active_reservation_marks_packet_claimed_and_removes_it_from_claimable_queue(): void
    {
        $result = $this->service()->project(
            [['id' => 'packet-1', 'lane' => 'cold', 'allowed_files' => ['app/a/File.php']]],
            ['active_reservations' => [['packet_id' => 'packet-1', 'owner' => 'session-x', 'allowed_files' => ['app/a/File.php']]]]
        );

        $this->assertSame(Projection::STATE_CLAIMED, $this->stateOf($result, 'packet-1'));
        // Claimed packets are not claimable.
        $this->assertNotContains('packet-1', $result['claimable_packet_ids']);
        $this->assertSame(1, $result['queue_summary'][Projection::STATE_CLAIMED]);
        $this->assertSame(0, $result['queue_summary'][Projection::STATE_AVAILABLE]);
        // The active reservation owner is reported.
        $this->assertSame([['packet_id' => 'packet-1', 'owner' => 'session-x']], $result['active_reservation_owners']);
    }

    public function test_incomplete_dependency_blocks_and_completed_dependency_unlocks_the_dependent_packet(): void
    {
        $packets = [['id' => 'packet-child', 'lane' => 'cold', 'allowed_files' => ['app/a/File.php'], 'depends_on' => ['packet-parent']]];

        // Parent not done -> blocked_by_dependency with an unlock hint.
        $blocked = $this->service()->project($packets, ['completed_packets' => []]);
        $this->assertSame(Projection::STATE_BLOCKED_BY_DEPENDENCY, $this->stateOf($blocked, 'packet-child'));
        $this->assertNotContains('packet-child', $blocked['claimable_packet_ids']);
        $this->assertSame('packet-child', $blocked['dependency_unlock_hints'][0]['packet_id']);

        // Parent completed -> child becomes available (unlocked).
        $unlocked = $this->service()->project($packets, ['completed_packets' => ['packet-parent']]);
        $this->assertSame(Projection::STATE_AVAILABLE, $this->stateOf($unlocked, 'packet-child'));
        $this->assertContains('packet-child', $unlocked['claimable_packet_ids']);
    }

    public function test_hot_scope_blocks_packet_before_queue_assignment(): void
    {
        $result = $this->service()->project(
            [['id' => 'packet-1', 'lane' => 'cold', 'allowed_files' => ['app/Voice/Kernel.php', 'app/a/File.php']]],
            ['hot_forbidden_scopes' => ['app/Voice/Kernel.php']]
        );

        $this->assertSame(Projection::STATE_BLOCKED_BY_HOT_SCOPE, $this->stateOf($result, 'packet-1'));
        $this->assertNotContains('packet-1', $result['claimable_packet_ids']);
        $reasons = array_column($result['blocked_packets'], 'reason');
        $this->assertContains('packet_touches_forbidden_hot_scope', $reasons);
    }

    public function test_stale_packet_hash_blocks_claim_but_matching_hash_does_not(): void
    {
        // assigned != current -> stale.
        $stale = $this->service()->project(
            [['id' => 'packet-1', 'lane' => 'cold', 'allowed_files' => ['app/a/File.php'], 'assigned_hash' => 'abc', 'current_hash' => 'xyz']],
            []
        );
        $this->assertSame(Projection::STATE_BLOCKED_BY_STALE_HASH, $this->stateOf($stale, 'packet-1'));

        // assigned == current -> not stale, packet is available.
        $fresh = $this->service()->project(
            [['id' => 'packet-1', 'lane' => 'cold', 'allowed_files' => ['app/a/File.php'], 'assigned_hash' => 'abc', 'current_hash' => 'abc']],
            []
        );
        $this->assertSame(Projection::STATE_AVAILABLE, $this->stateOf($fresh, 'packet-1'));
    }

    public function test_file_overlap_with_another_owners_active_claim_is_blocked_by_collision(): void
    {
        $result = $this->service()->project(
            [['id' => 'packet-2', 'lane' => 'cold', 'allowed_files' => ['app/shared/Thing.php']]],
            ['active_reservations' => [['packet_id' => 'packet-1', 'owner' => 'session-a', 'allowed_files' => ['app/shared/Thing.php']]]]
        );

        $this->assertSame(Projection::STATE_BLOCKED_BY_COLLISION, $this->stateOf($result, 'packet-2'));
        $reasons = array_column($result['blocked_packets'], 'reason');
        $this->assertContains('allowed_files_overlap_active_claim_owned_by:session-a', $reasons);
    }

    public function test_five_available_cold_packets_with_durable_ledger_reports_ready_for_multi_session_preview(): void
    {
        $ready = $this->service()->project($this->disjointColdPackets(5), ['reservation_ledger_exists' => true]);
        $this->assertSame(Projection::MULTI_SESSION_READY, $ready['multi_session_decision']);
        $this->assertSame(5, $ready['inputs']['available_cold_lane_packet_count']);

        // Same five packets but NO durable ledger -> must fall back to single session.
        $noLedger = $this->service()->project($this->disjointColdPackets(5), ['reservation_ledger_exists' => false]);
        $this->assertSame(Projection::MULTI_SESSION_FALLBACK, $noLedger['multi_session_decision']);

        // Ledger exists but only four available cold packets -> fall back too.
        $tooFew = $this->service()->project($this->disjointColdPackets(4), ['reservation_ledger_exists' => true]);
        $this->assertSame(Projection::MULTI_SESSION_FALLBACK, $tooFew['multi_session_decision']);
    }

    public function test_single_session_fallback_is_always_available_and_projection_never_persists_or_writes(): void
    {
        // Even when parallel dispatch is blocked (only one packet, no ledger) the
        // single-session fallback remains available, and the projection is inert.
        $result = $this->service()->project($this->disjointColdPackets(1), ['reservation_ledger_exists' => false]);

        $this->assertSame(Projection::MULTI_SESSION_FALLBACK, $result['multi_session_decision']);
        $this->assertTrue($result['single_session_fallback_available']);
        $this->assertNotEmpty($result['safe_single_session_fallback_instruction']);

        // Read-only guarantees from the doc: no dispatch, no claim persistence, no storage write.
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['claim_persisted']);
        $this->assertFalse($result['storage_write_allowed']);
        $this->assertContains('dispatch_work', $result['non_goals']);
        $this->assertContains('write_storage', $result['non_goals']);
    }

    public function test_completed_durable_state_wins_over_an_otherwise_blocking_dependency(): void
    {
        // A packet that is both completed AND has an incomplete dependency must
        // project as completed: a durable completion is the strongest fact.
        $result = $this->service()->project(
            [['id' => 'packet-1', 'lane' => 'cold', 'allowed_files' => ['app/a/File.php'], 'depends_on' => ['packet-open']]],
            ['completed_packets' => ['packet-1']]
        );

        $this->assertSame(Projection::STATE_COMPLETED, $this->stateOf($result, 'packet-1'));
        $this->assertSame(1, $result['queue_summary'][Projection::STATE_COMPLETED]);
        // Every projected state is one of the seven documented queue states.
        $this->assertContains($this->stateOf($result, 'packet-1'), Projection::QUEUE_STATES);
        $this->assertCount(7, Projection::QUEUE_STATES);
    }
}
