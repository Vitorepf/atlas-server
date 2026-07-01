<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogAgingValueMonitor;
use Tests\TestCase;

final class AtlasExternalBrainBacklogAgingValueMonitorTest extends TestCase
{
    private const NOW = '2026-06-30T00:00:00Z';

    private function monitor(): AtlasExternalBrainBacklogAgingValueMonitor
    {
        return new AtlasExternalBrainBacklogAgingValueMonitor;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'enqueued_at' => '2026-06-29T00:00:00Z',
            'theme' => 'theme-a',
            'target' => 'app/Foo.php',
            'priority' => 'medium',
            'dependency_status' => 'ready',
            'implementation_status' => 'not_started',
            'superseded_by' => null,
        ], $overrides);
    }

    public function test_evaluates_all_required_facts(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [$this->task()]]);

        foreach (['value_decay_risk', 'stale_count', 'revalidate_candidates', 'consolidate_candidates', 'retire_candidates'] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_recent_high_priority_task_stays_fresh(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'fresh-1', 'enqueued_at' => '2026-06-29T00:00:00Z', 'priority' => 'high']),
        ]]);

        $this->assertSame(0, $result['stale_count']);
        $this->assertSame([], $result['revalidate_candidates']);
        $this->assertSame([], $result['consolidate_candidates']);
        $this->assertSame([], $result['retire_candidates']);
    }

    public function test_old_task_is_flagged_stale_and_needs_revalidation(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'old-1', 'enqueued_at' => '2026-05-01T00:00:00Z', 'theme' => 'theme-unique', 'target' => 'app/Unique.php']),
        ]]);

        $this->assertSame(1, $result['stale_count']);
        $revalidateIds = array_column($result['revalidate_candidates'], 'task_id');
        $this->assertContains('old-1', $revalidateIds);
    }

    public function test_superseded_task_is_retired(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'old-superseded', 'enqueued_at' => '2026-05-01T00:00:00Z', 'superseded_by' => 'new-task-id']),
        ]]);

        $retired = array_column($result['retire_candidates'], 'task_id');
        $this->assertContains('old-superseded', $retired);
        $reasons = array_column($result['retire_candidates'], 'reason');
        $this->assertContains('superseded_by_newer_task', $reasons);
    }

    public function test_already_implemented_task_is_retired(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'done-1', 'enqueued_at' => '2026-05-01T00:00:00Z', 'implementation_status' => 'done']),
        ]]);

        $retired = array_column($result['retire_candidates'], 'task_id');
        $this->assertContains('done-1', $retired);
        $reasons = array_column($result['retire_candidates'], 'reason');
        $this->assertContains('already_implemented', $reasons);
    }

    public function test_duplicate_stale_same_theme_target_consolidates(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'dup-1', 'enqueued_at' => '2026-05-01T00:00:00Z', 'theme' => 'shared-theme', 'target' => 'app/Shared.php']),
            $this->task(['task_id' => 'dup-2', 'enqueued_at' => '2026-05-02T00:00:00Z', 'theme' => 'shared-theme', 'target' => 'app/Shared.php']),
        ]]);

        $consolidateIds = array_column($result['consolidate_candidates'], 'task_id');
        $this->assertContains('dup-1', $consolidateIds);
        $this->assertContains('dup-2', $consolidateIds);
        $this->assertSame([], $result['revalidate_candidates']);
    }

    public function test_old_high_priority_task_still_flags_stale(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task(['task_id' => 'old-high', 'enqueued_at' => '2026-04-01T00:00:00Z', 'priority' => 'critical', 'theme' => 'unique-h', 'target' => 'app/H.php']),
        ]]);

        $this->assertSame(1, $result['stale_count']);
        $row = $result['task_rows'][0];
        $this->assertTrue($row['is_high_priority']);
        $this->assertTrue($row['is_stale']);
    }

    // ── new AC: old proven task ranked ahead of younger low-value task ──────

    public function test_old_task_with_downstream_unlocks_and_fresh_proof_ranks_above_younger_low_value_task(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task([
                'task_id' => 'old-proven',
                'enqueued_at' => '2026-05-01T00:00:00Z',
                'theme' => 'proven-theme',
                'target' => 'app/Proven.php',
                'downstream_unlock_count' => 2,
                'value_proof_fresh' => true,
            ]),
            $this->task([
                'task_id' => 'young-low-value',
                'enqueued_at' => '2026-06-28T00:00:00Z',
                'theme' => 'lowvalue-theme',
                'target' => 'app/LowValue.php',
            ]),
        ]]);

        $rankPositions = array_flip($result['ranked_tasks']);
        $this->assertLessThan($rankPositions['young-low-value'], $rankPositions['old-proven']);

        $provenRow = $this->rowFor($result, 'old-proven');
        $this->assertSame('keep', $provenRow['aging_action']);
        $this->assertSame('downstream_unlock_with_fresh_value_proof', $provenRow['reason']);
    }

    // ── new AC: stale acceptance evidence + no value proof routes to refresh/retire ──

    public function test_stale_acceptance_evidence_with_no_value_proof_routes_to_refresh(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task([
                'task_id' => 'stale-unproven',
                'enqueued_at' => '2026-06-01T00:00:00Z',
                'theme' => 'unique-stale',
                'target' => 'app/StaleUnproven.php',
                'acceptance_evidence_stale' => true,
            ]),
        ]]);

        $row = $this->rowFor($result, 'stale-unproven');
        $this->assertContains($row['aging_action'], ['refresh', 'retire']);
        $this->assertNotSame('keep', $row['aging_action']);
        $this->assertNotEmpty($row['evidence_needed']);
    }

    public function test_very_stale_acceptance_evidence_with_no_value_proof_routes_to_retire(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [
            $this->task([
                'task_id' => 'ancient-unproven',
                'enqueued_at' => '2026-04-01T00:00:00Z',
                'theme' => 'unique-ancient',
                'target' => 'app/Ancient.php',
                'acceptance_evidence_stale' => true,
            ]),
        ]]);

        $row = $this->rowFor($result, 'ancient-unproven');
        $this->assertSame('retire', $row['aging_action']);
    }

    // ── new AC: result includes aging_action, evidence_needed, reason per task ──

    public function test_every_task_row_includes_aging_action_evidence_needed_and_reason(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [$this->task()]]);

        $row = $result['task_rows'][0];
        foreach (['aging_action', 'evidence_needed', 'reason'] as $field) {
            $this->assertArrayHasKey($field, $row, "missing field: {$field}");
        }
    }

    // ── new AC: no queue mutation ─────────────────────────────────────────────

    public function test_no_queue_mutation_flag_remains_false(): void
    {
        $result = $this->monitor()->evaluate(['now' => self::NOW, 'tasks' => [$this->task()]]);

        $this->assertFalse($result['mutates_queue']);
    }

    private function rowFor(array $result, string $taskId): array
    {
        foreach ($result['task_rows'] as $row) {
            if ($row['task_id'] === $taskId) {
                return $row;
            }
        }

        $this->fail("Row not found for task_id: {$taskId}");
    }
}
