<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerScopeConflictAnalyzer;
use Tests\TestCase;

/**
 * Unit tests for the hardened AgentDispatchPlannerScopeConflictAnalyzer:
 * file conflicts, parent-directory conflicts, shared capability conflicts,
 * dependency-order conflicts, and safe parallel groups.
 */
final class AgentDispatchPlannerScopeConflictAnalyzerTest extends TestCase
{
    private function analyzer(): AgentDispatchPlannerScopeConflictAnalyzer
    {
        return new AgentDispatchPlannerScopeConflictAnalyzer;
    }

    private function task(string $id, array $writeSet = [], array $capabilities = [], array $dependsOn = []): array
    {
        return [
            'task_packet_id' => $id,
            'scope_lock' => [
                'write_set' => $writeSet,
                'read_set' => [],
            ],
            'capabilities' => $capabilities,
            'depends_on' => $dependsOn,
        ];
    }

    // ── AC: exact same file conflicts ───────────────────────────────────────

    public function test_detects_exact_same_file_conflict(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php']),
            $this->task('task-b', ['app/Foo.php']),
        ];

        $activeLeases = [
            ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Foo.php']]],
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false, 'active_leases' => $activeLeases]);
        $this->assertGreaterThanOrEqual(1, $result['conflicting_task_count']);
        $this->assertNotEmpty($result['blocked_task_ids']);
    }

    // ── AC: parent-directory conflicts ──────────────────────────────────────

    public function test_detects_parent_directory_conflict(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Services/Foo.php']),
        ];

        $activeLeases = [
            ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Services/Bar.php']]],
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false, 'active_leases' => $activeLeases]);

        // Same directory but different file → hot scope directory.
        $taskAnalysis = null;
        foreach ($result['analyses'] as $a) {
            if ($a['task_packet_id'] === 'task-a') {
                $taskAnalysis = $a;
            }
        }
        $this->assertNotNull($taskAnalysis);
        $this->assertNotEmpty($taskAnalysis['hot_scope_directories']);
        $this->assertContains('app/Services', $taskAnalysis['hot_scope_directories']);
    }

    public function test_disjoint_allowed_files_are_parallel_safe(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php']),
            $this->task('task-b', ['app/Bar.php']),
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false]);
        $this->assertSame(0, $result['conflicting_task_count']);
        $this->assertNotEmpty($result['safe_parallel_groups']);
    }

    // ── AC: shared capability conflicts ─────────────────────────────────────

    public function test_detects_shared_capability_conflict(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php'], ['code_edit']),
            $this->task('task-b', ['app/Bar.php'], ['code_edit']),
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false]);
        $this->assertSame(2, $result['conflicting_task_count']);

        $hasCapabilityConflict = false;
        foreach ($result['analyses'] as $a) {
            if ($a['capability_conflicts'] !== []) {
                $hasCapabilityConflict = true;
            }
        }
        $this->assertTrue($hasCapabilityConflict);
    }

    // ── AC: dependency-order conflicts ──────────────────────────────────────

    public function test_detects_missing_dependency_conflict(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php'], [], ['task-missing']),
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false]);
        $this->assertSame(1, $result['conflicting_task_count']);
        $this->assertContains('task-a', $result['blocked_task_ids']);
    }

    public function test_allows_disjoint_tasks_with_no_dependency_to_run_in_parallel(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php']),
            $this->task('task-b', ['app/Bar.php']),
            $this->task('task-c', ['app/Baz.php']),
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false]);
        $this->assertSame(0, $result['conflicting_task_count']);

        $parallelGroups = $result['safe_parallel_groups'];
        $this->assertNotEmpty($parallelGroups);
        $this->assertCount(1, $parallelGroups); // one group with all 3
        $this->assertCount(3, $parallelGroups[0]);
    }

    // ── AC: output fields ───────────────────────────────────────────────────

    public function test_result_includes_conflict_groups(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Foo.php']),
            $this->task('task-b', ['app/Foo.php']),
        ];

        $activeLeases = [
            ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Foo.php']]],
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false, 'active_leases' => $activeLeases]);
        $this->assertArrayHasKey('conflict_groups', $result);
    }

    public function test_result_includes_safe_parallel_groups(): void
    {
        $result = $this->analyzer()->analyze([], ['use_live_ledger' => false]);
        $this->assertArrayHasKey('safe_parallel_groups', $result);
        $this->assertIsArray($result['safe_parallel_groups']);
    }

    public function test_result_includes_blocked_task_ids(): void
    {
        $tasks = [
            $this->task('task-a', ['app/Conflict.php']),
        ];

        $activeLeases = [
            ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Conflict.php']]],
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false, 'active_leases' => $activeLeases]);
        $this->assertArrayHasKey('blocked_task_ids', $result);
        $this->assertNotEmpty($result['blocked_task_ids']);
    }

    public function test_result_includes_recommended_sequencing(): void
    {
        $result = $this->analyzer()->analyze([], ['use_live_ledger' => false]);
        $this->assertArrayHasKey('recommended_sequencing', $result);
        $this->assertIsArray($result['recommended_sequencing']);
    }

    public function test_recommended_sequencing_respects_dependency_order(): void
    {
        $tasks = [
            $this->task('task-b', ['app/Bar.php'], [], ['task-a']),
            $this->task('task-a', ['app/Foo.php']),
        ];

        $result = $this->analyzer()->analyze($tasks, ['use_live_ledger' => false]);
        $seq = $result['recommended_sequencing'];

        // task-a must appear before task-b.
        $posA = array_search('task-a', $seq, true);
        $posB = array_search('task-b', $seq, true);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    // ── runtime safety ──────────────────────────────────────────────────────

    public function test_runtime_flags_remain_read_only(): void
    {
        $result = $this->analyzer()->analyze([], ['use_live_ledger' => false]);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }
}
