<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphRuntimeBridge;
use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphRuntimeBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainTaskGraphRuntimeBridge
    {
        return new AtlasExternalBrainTaskGraphRuntimeBridge;
    }

    private function replenisher(): AtlasSelfConstructionTaskGraphAutonomousReplenisher
    {
        return new AtlasSelfConstructionTaskGraphAutonomousReplenisher;
    }

    public function test_blocked_critical_path_produces_deterministic_guidance_with_named_blocker(): void
    {
        $result = $this->bridge()->bridge([
            'critical_path' => [
                ['task_packet_id' => 'pkt-a', 'blocked' => true, 'leverage' => 9],
            ],
        ]);

        $this->assertTrue($result['unresolved_leverage_present']);
        $this->assertTrue($result['block_off_path_low_novelty']);
        $this->assertContains('critical_path_blocked:pkt-a', $result['blockers']);
        $this->assertSame(['pkt-a'], $result['prioritized_task_packet_ids']);
    }

    public function test_stale_dependency_and_unlocked_prerequisite_are_captured(): void
    {
        $result = $this->bridge()->bridge([
            'broken_dependencies' => [
                ['task_packet_id' => 'pkt-stale', 'stale' => true],
            ],
            'prerequisite_unlocks' => [
                ['task_packet_id' => 'pkt-unlocked', 'unlocked' => true],
            ],
        ]);

        $this->assertContains('stale_dependency:pkt-stale', $result['blockers']);
        $this->assertContains('pkt-stale', $result['prioritized_task_packet_ids']);
        $this->assertContains('pkt-unlocked', $result['prioritized_task_packet_ids']);
    }

    public function test_release_gate_blocked_adds_named_blocker(): void
    {
        $result = $this->bridge()->bridge([
            'release_gate_findings' => [
                ['task_packet_id' => 'pkt-release', 'gate_status' => 'blocked'],
            ],
        ]);

        $this->assertContains('release_gate_blocked:pkt-release', $result['blockers']);
        $this->assertTrue($result['unresolved_leverage_present']);
    }

    public function test_no_findings_yields_no_blocking_guidance(): void
    {
        $result = $this->bridge()->bridge([]);

        $this->assertFalse($result['unresolved_leverage_present']);
        $this->assertFalse($result['block_off_path_low_novelty']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['prioritized_task_packet_ids']);
    }

    public function test_replenisher_blocks_off_path_low_novelty_inputs_while_leverage_unresolved(): void
    {
        $guidance = $this->bridge()->bridge([
            'critical_path' => [
                ['task_packet_id' => 'pkt-critical', 'blocked' => true, 'leverage' => 5],
            ],
        ]);

        $result = $this->replenisher()->applyRuntimeBridgeGuidance([
            ['task_packet_id' => 'pkt-critical'],
            ['task_packet_id' => 'pkt-off-path-speculative'],
        ], $guidance);

        $this->assertCount(1, $result['allowed']);
        $this->assertSame('pkt-critical', $result['allowed'][0]['task_packet_id']);
        $this->assertCount(1, $result['withheld']);
        $this->assertSame('pkt-off-path-speculative', $result['withheld'][0]['task_packet_id']);
        $this->assertSame('blocked_by_unresolved_critical_path_leverage', $result['withheld'][0]['reason']);
    }

    public function test_replenisher_allows_all_inputs_when_no_unresolved_leverage(): void
    {
        $guidance = $this->bridge()->bridge([]);

        $result = $this->replenisher()->applyRuntimeBridgeGuidance([
            ['task_packet_id' => 'pkt-a'],
            ['task_packet_id' => 'pkt-b'],
        ], $guidance);

        $this->assertCount(2, $result['allowed']);
        $this->assertSame([], $result['withheld']);
    }
}
