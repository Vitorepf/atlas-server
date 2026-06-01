<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDependencyUnlockPlanContractService;
use Tests\TestCase;

/**
 * Pins the documented Dependency Unlock Plan contract.
 *
 * Pure, no DB. Each test maps to one rule in the doc's Purpose / Unlock Rule /
 * Non Goals / Completion Criteria sections.
 *
 * @see docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
 */
class AtlasDependencyUnlockPlanContractTest extends TestCase
{
    private function service(): AtlasDependencyUnlockPlanContractService
    {
        return new AtlasDependencyUnlockPlanContractService();
    }

    /**
     * Unlock Rule: a packet whose every (cold) dependency is durable-available
     * becomes at most PREVIEW unlockable in the preview phase, and is reported as
     * an unlock candidate. A packet that still has a pending dependency stays
     * blocked and is NOT a candidate. Purpose: deps split into available/pending.
     */
    public function test_fully_satisfied_cold_packet_is_preview_candidate_partial_stays_blocked(): void
    {
        $out = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => [
                ['id' => 'AIP-SPLIT-0002', 'dependencies' => ['AIP-SPLIT-0001']],
                ['id' => 'AIP-SPLIT-0003', 'dependencies' => ['AIP-SPLIT-0001', 'AIP-SPLIT-0002']],
            ],
        ]);

        $byId = [];
        foreach ($out['blocked_packets'] as $p) {
            $byId[$p['id']] = $p;
        }

        // Fully satisfied -> preview_unlockable + candidate.
        $this->assertSame(
            AtlasDependencyUnlockPlanContractService::UNLOCK_PREVIEW_UNLOCKABLE,
            $byId['AIP-SPLIT-0002']['unlock_status'],
        );
        $this->assertSame(['AIP-SPLIT-0001'], $byId['AIP-SPLIT-0002']['available']);
        $this->assertSame([], $byId['AIP-SPLIT-0002']['pending']);
        $this->assertContains('AIP-SPLIT-0002', $out['unlock_candidates']);

        // One pending dep -> stays blocked, not a candidate, dep correctly split.
        $this->assertSame(
            AtlasDependencyUnlockPlanContractService::UNLOCK_BLOCKED,
            $byId['AIP-SPLIT-0003']['unlock_status'],
        );
        $this->assertSame(['AIP-SPLIT-0001'], $byId['AIP-SPLIT-0003']['available']);
        $this->assertSame(['AIP-SPLIT-0002'], $byId['AIP-SPLIT-0003']['pending']);
        $this->assertNotContains('AIP-SPLIT-0003', $out['unlock_candidates']);
    }

    /**
     * Non Goal "Do not unlock hot external work" + decision "Hot withheld work
     * must not be unlocked by Self-Construction cold-lane packets." A hot-lane
     * packet whose every cold dep is available is STILL withheld (never a
     * candidate) and appears in withheld_work.
     */
    public function test_hot_lane_packet_is_withheld_even_when_dependencies_available(): void
    {
        $out = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => [
                ['id' => 'AIP-HOT-0009', 'lane' => 'hot', 'dependencies' => ['AIP-SPLIT-0001']],
            ],
        ]);

        $packet = $out['blocked_packets'][0];
        $this->assertSame(
            AtlasDependencyUnlockPlanContractService::UNLOCK_WITHHELD_HOT,
            $packet['unlock_status'],
        );
        $this->assertNotContains('AIP-HOT-0009', $out['unlock_candidates']);
        $this->assertSame(
            [['id' => 'AIP-HOT-0009', 'reason' => 'hot_lane_owned_by_external_front']],
            $out['withheld_work'],
        );
    }

    /**
     * Non Goal "Do not unlock hot external work": even a COLD-lane packet is
     * forced to withheld_hot when one of its dependencies is a hot external token
     * (owned by another front). The hot blocker is surfaced and the cold lane
     * never gets it as a candidate.
     */
    public function test_cold_packet_with_hot_external_dependency_is_withheld(): void
    {
        $out = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => [
                [
                    'id' => 'AIP-SPLIT-0007',
                    'lane' => 'cold',
                    'dependencies' => ['AIP-SPLIT-0001', 'hot:runtimes/python/voice_realtime/server.py'],
                ],
            ],
        ]);

        $packet = $out['blocked_packets'][0];
        $this->assertSame(
            AtlasDependencyUnlockPlanContractService::UNLOCK_WITHHELD_HOT,
            $packet['unlock_status'],
        );
        $this->assertSame(
            ['hot:runtimes/python/voice_realtime/server.py'],
            $packet['hot_blockers'],
        );
        $this->assertNotContains('AIP-SPLIT-0007', $out['unlock_candidates']);
        $this->assertSame('AIP-SPLIT-0007', $out['withheld_work'][0]['id']);
    }

    /**
     * Unlock Rule: "In the current phase, Atlas may only preview the unlock
     * relationship." The durable phase is reserved for a future AP, so even a
     * fully-satisfied packet produces NO candidate when phase != preview (the
     * read-only planner must not optimistically pre-assign durable work).
     */
    public function test_durable_phase_yields_no_candidates(): void
    {
        $out = $this->service()->plan([
            'phase' => 'durable',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => [
                ['id' => 'AIP-SPLIT-0002', 'dependencies' => ['AIP-SPLIT-0001']],
            ],
        ]);

        $this->assertSame(AtlasDependencyUnlockPlanContractService::PHASE_DURABLE, $out['phase']);
        // Relationship is still computed (preview_unlockable) but NOT promoted to a candidate.
        $this->assertSame(
            AtlasDependencyUnlockPlanContractService::UNLOCK_PREVIEW_UNLOCKABLE,
            $out['blocked_packets'][0]['unlock_status'],
        );
        $this->assertSame([], $out['unlock_candidates']);
    }

    /**
     * Non Goals (read-only invariants): the plan never mutates queue state,
     * persists completion or dispatches unlocked work — in EVERY phase.
     */
    public function test_plan_is_read_only_in_all_paths(): void
    {
        $out = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => [
                ['id' => 'AIP-SPLIT-0002', 'dependencies' => ['AIP-SPLIT-0001']],
                ['id' => 'AIP-HOT-0009', 'lane' => 'hot', 'dependencies' => []],
            ],
        ]);

        $this->assertFalse($out['queue_mutated']);
        $this->assertFalse($out['durable_completion_written']);
        $this->assertFalse($out['dispatched']);
        $this->assertSame(AtlasDependencyUnlockPlanContractService::SCHEMA, $out['schema_version']);
    }

    /**
     * Completion Criteria: a DETERMINISTIC read-only plan. Identical snapshots in
     * different packet ORDER yield the same plan_hash (and the same plan_id), so
     * the plan is reproducible/auditable.
     */
    public function test_plan_hash_is_deterministic_regardless_of_packet_order(): void
    {
        $packetsA = [
            ['id' => 'AIP-SPLIT-0002', 'dependencies' => ['AIP-SPLIT-0001']],
            ['id' => 'AIP-SPLIT-0003', 'dependencies' => ['AIP-SPLIT-0002']],
        ];
        $packetsB = array_reverse($packetsA);

        $a = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => $packetsA,
        ]);
        $b = $this->service()->plan([
            'phase' => 'preview',
            'available' => ['AIP-SPLIT-0001'],
            'packets' => $packetsB,
        ]);

        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertSame($a['plan_id'], $b['plan_id']);
        $this->assertStringStartsWith('sha256:', $a['plan_hash']);
        $this->assertStringStartsWith('UNLOCK-PLAN-', $a['plan_id']);
    }
}
