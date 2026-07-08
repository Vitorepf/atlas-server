<?php

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerScopeConflictAnalyzer;
use Tests\TestCase;

/**
 * Multiple muscles can run in parallel without stealing hot scopes: exact allowed_files/write_set
 * overlap rejects conflict, same-directory hot scope serializes, disjoint scopes are parallel_safe,
 * missing scope_lock is reported via has_scope_lock=false, active lease conflicts are included,
 * hot_scope_directories are deterministically sorted, and runtimeFlags remain read-only.
 */
final class AgentDispatchPlannerScopeConflictAnalyzerTest extends TestCase
{
    private function task(string $id, array $writeSet): array
    {
        return ['task_packet_id' => $id, 'scope_lock' => ['write_set' => $writeSet, 'read_set' => []]];
    }

    public function test_exact_write_set_overlap_recommends_reject_conflict(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze(
            [$this->task('tp', ['app/Services/FileA.php'])],
            ['use_live_ledger' => false, 'active_leases' => [
                ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Services/FileA.php']]],
            ]],
        );

        self::assertSame(AgentDispatchPlannerScopeConflictAnalyzer::RECOMMENDATION_REJECT_CONFLICT, $result['analyses'][0]['recommendation']);
        self::assertSame('conflict', $result['analyses'][0]['conflict_status']);
        self::assertNotEmpty($result['analyses'][0]['recommendation_reasons']);
    }

    public function test_same_directory_hot_scope_recommends_serialize(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze(
            [$this->task('tp', ['app/Services/FileA.php'])],
            ['use_live_ledger' => false, 'active_leases' => [
                ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Services/FileB.php']]],
            ]],
        );

        self::assertSame(AgentDispatchPlannerScopeConflictAnalyzer::RECOMMENDATION_SERIALIZE, $result['analyses'][0]['recommendation']);
        self::assertContains('app/Services', $result['analyses'][0]['hot_scope_directories']);
    }

    public function test_disjoint_scopes_are_parallel_safe(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze(
            [$this->task('tp', ['app/Other/FileA.php'])],
            ['use_live_ledger' => false, 'active_leases' => [
                ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['app/Different/FileB.php']]],
            ]],
        );

        self::assertSame(AgentDispatchPlannerScopeConflictAnalyzer::RECOMMENDATION_PARALLEL_SAFE, $result['analyses'][0]['recommendation']);
        self::assertSame([], $result['analyses'][0]['hot_scope_directories']);
    }

    public function test_missing_scope_lock_is_reported(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze([
            ['task_packet_id' => 'tp', 'scope_lock' => []],
        ]);

        self::assertFalse($result['analyses'][0]['has_scope_lock']);
    }

    public function test_active_lease_conflicts_are_included(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze(
            [$this->task('tp', ['fileA.php', 'fileB.php'])],
            ['use_live_ledger' => false, 'active_leases' => [
                ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['fileA.php']]],
            ]],
        );

        self::assertSame(1, $result['analyses'][0]['conflict_count']);
        self::assertSame('lease-1', $result['analyses'][0]['conflicts'][0]['lease_id']);
    }

    public function test_hot_scope_directories_are_deterministically_sorted(): void
    {
        $result = (new AgentDispatchPlannerScopeConflictAnalyzer)->analyze(
            [$this->task('tp', ['z/Foo.php', 'a/Bar.php'])],
            ['use_live_ledger' => false, 'active_leases' => [
                ['lease_id' => 'lease-1', 'scope_lock' => ['write_set' => ['z/Other.php', 'a/Other.php']]],
            ]],
        );

        self::assertSame(['a', 'z'], $result['analyses'][0]['hot_scope_directories']);
    }

    public function test_analysis_hash_is_stable_for_equivalent_facts(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $a = $svc->analyze([$this->task('tp', ['fileA.php'])]);
        $b = $svc->analyze([$this->task('tp', ['fileA.php'])]);

        self::assertSame($a['analysis_hash'], $b['analysis_hash']);
    }

    public function test_runtime_flags_remain_read_only(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        foreach ($svc->runtimeFlags() as $key => $value) {
            self::assertFalse($value, "flag {$key} must be false");
        }
    }
}
