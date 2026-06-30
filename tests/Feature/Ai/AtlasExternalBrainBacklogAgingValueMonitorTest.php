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
}
