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

    // ── AC2: classifies stale items using age, leverage, evidence, give_back_risk ──

    public function test_high_leverage_old_unblocker_is_promoted(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'unblocker', 'enqueued_at' => '2026-05-01T00:00:00Z', 'priority' => 'high',
             'leverage_score' => 0.9, 'evidence_strength' => 0.7],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_PROMOTE, $row['aging_action']);
    }

    public function test_very_stale_low_evidence_low_leverage_is_retired(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'dead-weight', 'enqueued_at' => '2026-04-01T00:00:00Z', 'priority' => 'low',
             'leverage_score' => 0.1, 'evidence_strength' => 0.1, 'give_back_risk' => 0.6],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_RETIRE, $row['aging_action']);
    }

    public function test_stale_low_evidence_low_leverage_high_impl_risk_is_respec(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'risky', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'medium',
             'leverage_score' => 0.1, 'evidence_strength' => 0.1, 'implementation_risk' => 0.8],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_RESPEC, $row['aging_action']);
    }

    public function test_stale_with_value_proof_is_kept(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'proven', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high',
             'has_value_proof' => true],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_KEEP, $row['aging_action']);
    }

    // ── AC3: high-age low-evidence low-leverage → retire/respec; old high-leverage → promote ──

    public function test_old_high_leverage_promoted_not_discarded(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'old-unblocker', 'enqueued_at' => '2026-04-01T00:00:00Z', 'priority' => 'medium',
             'leverage_score' => 0.8, 'evidence_strength' => 0.6],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_PROMOTE, $row['aging_action']);
    }

    public function test_old_low_leverage_low_evidence_not_promoted(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'old-low-value', 'enqueued_at' => '2026-04-01T00:00:00Z', 'priority' => 'low',
             'leverage_score' => 0.1, 'evidence_strength' => 0.1, 'give_back_risk' => 0.3],
        ]));

        $row = $result['task_rows'][0];
        $this->assertNotSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_PROMOTE, $row['aging_action']);
        $this->assertSame(AtlasExternalBrainBacklogAgingValueMonitor::ACTION_RETIRE, $row['aging_action']);
    }

    // ── AC4: deterministic and never recommends action from age alone ──────────

    public function test_monitor_is_deterministic(): void
    {
        $tasks = [
            ['task_id' => 't1', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high',
             'leverage_score' => 0.5, 'evidence_strength' => 0.5],
            ['task_id' => 't2', 'enqueued_at' => '2026-05-01T00:00:00Z', 'priority' => 'low',
             'leverage_score' => 0.1, 'evidence_strength' => 0.1, 'give_back_risk' => 0.6],
        ];

        $a = $this->monitor()->evaluate($this->facts($tasks));
        $b = $this->monitor()->evaluate($this->facts($tasks));

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_never_recommends_action_from_age_alone(): void
    {
        // Two tasks with same age but different leverage/evidence → different actions.
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 'high-leverage', 'enqueued_at' => '2026-05-01T00:00:00Z', 'priority' => 'medium',
             'leverage_score' => 0.9, 'evidence_strength' => 0.7],
            ['task_id' => 'low-leverage', 'enqueued_at' => '2026-05-01T00:00:00Z', 'priority' => 'low',
             'leverage_score' => 0.1, 'evidence_strength' => 0.1, 'give_back_risk' => 0.6],
        ]));

        $actions = array_column($result['task_rows'], 'aging_action');
        $this->assertNotSame($actions[0], $actions[1], 'Tasks with same age but different value signals must get different actions');
    }

    public function test_task_rows_include_leverage_evidence_giveback_fields(): void
    {
        $result = $this->monitor()->evaluate($this->facts([
            ['task_id' => 't1', 'enqueued_at' => '2026-06-01T00:00:00Z', 'priority' => 'high',
             'leverage_score' => 0.7, 'evidence_strength' => 0.6, 'give_back_risk' => 0.2, 'implementation_risk' => 0.3],
        ]));

        $row = $result['task_rows'][0];
        $this->assertSame(0.7, $row['leverage_score']);
        $this->assertSame(0.6, $row['evidence_strength']);
        $this->assertSame(0.2, $row['give_back_risk']);
        $this->assertSame(0.3, $row['implementation_risk']);
    }
}
