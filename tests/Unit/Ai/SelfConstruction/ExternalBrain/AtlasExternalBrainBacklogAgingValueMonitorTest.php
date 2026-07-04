<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogAgingValueMonitor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBacklogAgingValueMonitorTest extends TestCase
{
    private function monitor(): AtlasExternalBrainBacklogAgingValueMonitor
    {
        return new AtlasExternalBrainBacklogAgingValueMonitor();
    }

    private function facts(array $tasks, string $now = '2026-07-04T00:00:00Z'): array
    {
        return ['tasks' => $tasks, 'now' => $now];
    }

    // AC: separates stale_but_proven, stale_proxy_risk and stale_needs_research
    public function test_separates_stale_categories(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'proven', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high', 'has_value_proof' => true],
            ['task_id' => 'proxy', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high', 'is_proxy' => true],
            ['task_id' => 'research', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high'],
        ]));

        $actions = $result['stale_value_actions'];
        $this->assertCount(1, $actions['stale_but_proven']);
        $this->assertSame('proven', $actions['stale_but_proven'][0]['task_id']);
        $this->assertCount(1, $actions['stale_proxy_risk']);
        $this->assertSame('proxy', $actions['stale_proxy_risk'][0]['task_id']);
        $this->assertCount(1, $actions['stale_needs_research']);
        $this->assertSame('research', $actions['stale_needs_research'][0]['task_id']);
    }

    // AC: recommends retire for very stale unproven proxy tasks even when priority is high
    public function test_retire_very_stale_unproven_proxy_even_high_priority(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'old-proxy', 'enqueued_at' => '2026-04-01T00:00:00Z', 'priority' => 'critical', 'is_proxy' => true, 'acceptance_evidence_stale' => true],
        ]));

        $this->assertNotEmpty($result['retire_candidates']);
        $this->assertSame('old-proxy', $result['retire_candidates'][0]['task_id']);
    }

    // AC: output includes useful_depth_after_decay
    public function test_useful_depth_after_decay(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'fresh', 'enqueued_at' => '2026-07-01T00:00:00Z', 'priority' => 'high'],
            ['task_id' => 'stale', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high'],
        ]));

        $this->assertArrayHasKey('useful_depth_after_decay', $result);
        $this->assertSame(1, $result['useful_depth_after_decay']);
    }

    // AC: output includes stale_value_actions
    public function test_stale_value_actions_present(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'stale', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high'],
        ]));

        $this->assertArrayHasKey('stale_value_actions', $result);
        $this->assertArrayHasKey('stale_but_proven', $result['stale_value_actions']);
        $this->assertArrayHasKey('stale_proxy_risk', $result['stale_value_actions']);
        $this->assertArrayHasKey('stale_needs_research', $result['stale_value_actions']);
    }

    // AC: fresh tasks are not categorized as stale
    public function test_fresh_tasks_not_in_stale_categories(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'fresh', 'enqueued_at' => '2026-07-01T00:00:00Z', 'priority' => 'high'],
        ]));

        $actions = $result['stale_value_actions'];
        $this->assertEmpty($actions['stale_but_proven']);
        $this->assertEmpty($actions['stale_proxy_risk']);
        $this->assertEmpty($actions['stale_needs_research']);
        $this->assertSame(1, $result['useful_depth_after_decay']);
    }
}
