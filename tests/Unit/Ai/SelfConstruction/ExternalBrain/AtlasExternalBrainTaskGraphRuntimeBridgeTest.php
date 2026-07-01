<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphRuntimeBridge;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphRuntimeBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainTaskGraphRuntimeBridge
    {
        return new AtlasExternalBrainTaskGraphRuntimeBridge;
    }

    private function nodeById(array $result, string $id): array
    {
        foreach ($result['nodes'] as $node) {
            if ($node['task_packet_id'] === $id) {
                return $node;
            }
        }
        $this->fail("node {$id} not found");
    }

    // ── AC: graph nodes map to live statuses ────────────────────────────────────

    public function test_node_with_no_prerequisites_and_no_queue_status_is_claimable(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'prerequisites' => []],
        ]]);

        $node = $this->nodeById($result, 'a');
        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_CLAIMABLE, $node['live_status']);
    }

    public function test_node_with_queue_status_claimed_is_claimed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'queue_status' => 'claimed'],
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_CLAIMED, $this->nodeById($result, 'a')['live_status']);
    }

    public function test_node_with_queue_status_completed_is_completed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'queue_status' => 'completed'],
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_COMPLETED, $this->nodeById($result, 'a')['live_status']);
    }

    public function test_node_with_dependency_stale_true_is_stale(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'dependency_stale' => true],
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_STALE, $this->nodeById($result, 'a')['live_status']);
    }

    public function test_node_with_unmet_prerequisite_is_blocked(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'parent', 'queue_status' => 'claimed'],
            ['task_packet_id' => 'child', 'prerequisites' => ['parent']],
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_BLOCKED, $this->nodeById($result, 'child')['live_status']);
    }

    public function test_stale_takes_priority_over_claimed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'queue_status' => 'claimed', 'dependency_stale' => true],
        ]]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_STALE, $this->nodeById($result, 'a')['live_status']);
    }

    // ── AC: dependent nodes are not release-ready until prerequisites are live-complete ──

    public function test_dependent_node_not_release_ready_until_prerequisite_is_completed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'parent', 'queue_status' => 'claimed'],
            ['task_packet_id' => 'child', 'prerequisites' => ['parent']],
        ]]);

        $child = $this->nodeById($result, 'child');
        $this->assertFalse($child['release_ready']);
        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_BLOCKED, $child['live_status']);
    }

    public function test_dependent_node_release_ready_once_prerequisite_completed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'parent', 'queue_status' => 'completed'],
            ['task_packet_id' => 'child', 'prerequisites' => ['parent']],
        ]]);

        $child = $this->nodeById($result, 'child');
        $this->assertTrue($child['release_ready']);
        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_CLAIMABLE, $child['live_status']);
    }

    public function test_dependent_node_blocked_when_only_some_prerequisites_completed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'p1', 'queue_status' => 'completed'],
            ['task_packet_id' => 'p2', 'queue_status' => 'claimed'],
            ['task_packet_id' => 'child', 'prerequisites' => ['p1', 'p2']],
        ]]);

        $child = $this->nodeById($result, 'child');
        $this->assertFalse($child['release_ready']);
        $this->assertStringContainsString('p2', $child['hold_reason']);
        $this->assertStringNotContainsString('p1,', $child['hold_reason']);
    }

    public function test_unknown_prerequisite_id_blocks_fail_closed(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'child', 'prerequisites' => ['ghost']],
        ]]);

        $child = $this->nodeById($result, 'child');
        $this->assertFalse($child['release_ready']);
        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::STATUS_BLOCKED, $child['live_status']);
    }

    // ── AC: bridge output includes release_ready and hold_reason for each node ──

    public function test_every_node_has_release_ready_and_hold_reason_keys(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a'],
            ['task_packet_id' => 'b', 'queue_status' => 'claimed'],
        ]]);

        foreach ($result['nodes'] as $node) {
            $this->assertArrayHasKey('release_ready', $node);
            $this->assertArrayHasKey('hold_reason', $node);
        }
    }

    public function test_release_ready_node_has_null_hold_reason(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a'],
        ]]);

        $node = $this->nodeById($result, 'a');
        $this->assertTrue($node['release_ready']);
        $this->assertNull($node['hold_reason']);
    }

    public function test_claimed_node_has_already_claimed_hold_reason(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'queue_status' => 'claimed'],
        ]]);

        $this->assertSame('already_claimed', $this->nodeById($result, 'a')['hold_reason']);
    }

    public function test_completed_node_has_already_completed_hold_reason(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'queue_status' => 'completed'],
        ]]);

        $this->assertSame('already_completed', $this->nodeById($result, 'a')['hold_reason']);
    }

    public function test_stale_node_has_dependency_stale_hold_reason(): void
    {
        $result = $this->bridge()->bridgeNodes(['nodes' => [
            ['task_packet_id' => 'a', 'dependency_stale' => true],
        ]]);

        $this->assertSame('dependency_stale', $this->nodeById($result, 'a')['hold_reason']);
    }

    public function test_schema_present(): void
    {
        $result = $this->bridge()->bridgeNodes([]);

        $this->assertSame(AtlasExternalBrainTaskGraphRuntimeBridge::SCHEMA, $result['schema']);
        $this->assertSame([], $result['nodes']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = ['nodes' => [
            ['task_packet_id' => 'p1', 'queue_status' => 'completed'],
            ['task_packet_id' => 'child', 'prerequisites' => ['p1']],
        ]];

        $this->assertSame($this->bridge()->bridgeNodes($facts), $this->bridge()->bridgeNodes($facts));
    }
}
