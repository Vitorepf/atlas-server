<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMultiSessionReadinessGateContractService;
use Tests\TestCase;

/**
 * Pins the documented decision values and the load-bearing rules of the
 * Multi-Session Readiness Gate Contract: provider-neutral contract requirement,
 * scope-collision blocking, hot-scope-owned hard block vs pending-hot warning,
 * the five-cold-lane-packet threshold for ready_for_multi_session_preview, and
 * the invariant that durable dispatch is never auto-granted. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
 */
class AtlasMultiSessionReadinessGateContractTest extends TestCase
{
    private function service(): AtlasMultiSessionReadinessGateContractService
    {
        return new AtlasMultiSessionReadinessGateContractService;
    }

    /** A contract-bearing, disjoint cold-lane packet builder. */
    private function coldPacket(string $id, array $scope): array
    {
        return [
            'id' => $id,
            'lane' => 'cold',
            'has_universal_contract' => true,
            'scope_paths' => $scope,
        ];
    }

    /** N disjoint cold-lane packets, each touching its own path. */
    private function disjointColdPackets(int $n): array
    {
        $packets = [];
        for ($i = 1; $i <= $n; $i++) {
            $packets[] = $this->coldPacket("packet-{$i}", ["app/area-{$i}/File.php"]);
        }

        return $packets;
    }

    public function test_no_packets_and_no_ledger_is_single_session_preview_with_no_durable_dispatch(): void
    {
        $result = $this->service()->evaluate([], []);

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_PREVIEW_ONLY_SINGLE,
            $result['decision']
        );
        // Non Goals: the gate never starts sessions and never grants durable dispatch.
        $this->assertFalse($result['durable_dispatch_enabled']);
        $this->assertFalse($result['starts_sessions']);
        $this->assertContains('start_sessions', $result['non_goals']);
    }

    public function test_five_disjoint_cold_packets_with_ledger_reaches_ready_for_multi_session_preview(): void
    {
        $result = $this->service()->evaluate(
            $this->disjointColdPackets(5),
            ['reservation_ledger_exists' => true]
        );

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_READY_MULTI_PREVIEW,
            $result['decision']
        );
        $this->assertSame(5, $result['inputs']['cold_lane_ready_packet_count']);
        // Even at the expected state, durable dispatch stays off and no session starts.
        $this->assertFalse($result['durable_dispatch_enabled']);
    }

    public function test_two_disjoint_cold_packets_without_ledger_is_parallel_preview_but_not_durable(): void
    {
        $result = $this->service()->evaluate(
            $this->disjointColdPackets(2),
            ['reservation_ledger_exists' => false]
        );

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_PARALLEL_PREVIEW_NOT_DURABLE,
            $result['decision']
        );
    }

    public function test_five_packets_with_ledger_but_no_durable_claims_does_not_promote_to_durable_dispatch(): void
    {
        // Reservation ledger exists but durable claims are off -> stays at preview.
        $result = $this->service()->evaluate(
            $this->disjointColdPackets(5),
            ['reservation_ledger_exists' => true, 'reservation_ledger_persists_rows' => true]
        );

        $this->assertNotSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_READY_DURABLE_DISPATCH,
            $result['decision']
        );
        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_READY_MULTI_PREVIEW,
            $result['decision']
        );
        $this->assertFalse($result['durable_dispatch_enabled']);
    }

    public function test_packet_missing_universal_contract_blocks_multi_session(): void
    {
        $packets = $this->disjointColdPackets(5);
        $packets[2]['has_universal_contract'] = false; // one packet lacks the contract

        $result = $this->service()->evaluate($packets, ['reservation_ledger_exists' => true]);

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_BLOCKED,
            $result['decision']
        );
        $reasons = array_column($result['blockers'], 'reason');
        $this->assertContains('packet_missing_universal_contract', $reasons);
    }

    public function test_overlapping_packet_scopes_block_multi_session(): void
    {
        $packets = [
            $this->coldPacket('packet-a', ['app/shared/Thing.php']),
            $this->coldPacket('packet-b', ['app/shared/Thing.php']), // collides with packet-a
        ];

        $result = $this->service()->evaluate($packets, ['reservation_ledger_exists' => true]);

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_BLOCKED,
            $result['decision']
        );
        $this->assertContains('packet_scope_collision', array_column($result['blockers'], 'reason'));
    }

    public function test_pending_hot_work_is_only_a_non_blocking_warning_when_cold_lane_is_disjoint(): void
    {
        $result = $this->service()->evaluate(
            $this->disjointColdPackets(5),
            ['reservation_ledger_exists' => true, 'hot_work_pending' => true]
        );

        // Hot work pending but unowned -> warning, not a blocker. Still ready.
        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_READY_MULTI_PREVIEW,
            $result['decision']
        );
        $this->assertSame([], $result['blockers']);
        $this->assertContains('hot_voice_kernel_work_withheld_and_visible', array_column($result['warnings'], 'reason'));
    }

    public function test_packet_that_owns_hot_scope_is_a_hard_blocker(): void
    {
        $packets = $this->disjointColdPackets(5);
        $packets[0]['owns_hot_scope'] = true; // a selected packet edits hot scope

        $result = $this->service()->evaluate(
            $packets,
            ['reservation_ledger_exists' => true, 'hot_work_pending' => true]
        );

        $this->assertSame(
            AtlasMultiSessionReadinessGateContractService::DECISION_BLOCKED,
            $result['decision']
        );
        $this->assertContains('packet_owns_or_edits_hot_scope', array_column($result['blockers'], 'reason'));
    }

    public function test_decision_is_always_one_of_the_five_documented_values(): void
    {
        $result = $this->service()->evaluate($this->disjointColdPackets(3), ['reservation_ledger_exists' => true]);

        $this->assertContains(
            $result['decision'],
            AtlasMultiSessionReadinessGateContractService::DECISION_VALUES
        );
        $this->assertCount(5, AtlasMultiSessionReadinessGateContractService::DECISION_VALUES);
    }
}
