<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerScopeConflictAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerScopeConflictAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_scope_conflict.v1', AgentDispatchPlannerScopeConflictAnalyzer::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_dispatch_planner_scope_conflict', AgentDispatchPlannerScopeConflictAnalyzer::MODE);
    }

    public function test_clear_when_no_active_leases(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([$this->task('tp', ['fileA.php'])]);
        $this->assertSame(1, $result['clear_task_count']);
        $this->assertSame(0, $result['conflicting_task_count']);
        $this->assertSame('clear', $result['analyses'][0]['conflict_status']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_overlap_when_provided_lease_overlaps(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze(
            [$this->task('tp', ['fileA.php', 'fileB.php'])],
            [
                'use_live_ledger' => false,
                'active_leases' => [[
                    'lease_id' => 'lease-1',
                    'scope_lock' => ['write_set' => ['fileA.php']],
                ]],
            ],
        );
        $this->assertSame('conflict', $result['analyses'][0]['conflict_status']);
        $this->assertSame(1, $result['analyses'][0]['conflict_count']);
        $this->assertSame(['fileA.php'], $result['analyses'][0]['conflicts'][0]['overlap']);
    }

    public function test_no_overlap_when_disjoint_write_sets(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze(
            [$this->task('tp', ['fileX.php'])],
            [
                'use_live_ledger' => false,
                'active_leases' => [[
                    'lease_id' => 'lease-1',
                    'scope_lock' => ['write_set' => ['fileA.php']],
                ]],
            ],
        );
        $this->assertSame('clear', $result['analyses'][0]['conflict_status']);
        $this->assertSame(0, $result['analyses'][0]['conflict_count']);
    }

    public function test_normalizes_write_set(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([
            ['task_packet_id' => 'tp', 'scope_lock' => ['write_set' => [' fileA.php ', 'fileA.php', '']]],
        ]);
        $this->assertSame(['fileA.php'], $result['analyses'][0]['write_set']);
    }

    public function test_read_set_present(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([
            ['task_packet_id' => 'tp', 'scope_lock' => ['write_set' => ['x'], 'read_set' => ['y']]],
        ]);
        $this->assertSame(['x'], $result['analyses'][0]['write_set']);
        $this->assertSame(['y'], $result['analyses'][0]['read_set']);
        $this->assertTrue($result['analyses'][0]['has_scope_lock']);
    }

    public function test_analysis_hash_stable(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $a = $svc->analyze([$this->task('tp', ['fileA.php'])]);
        $b = $svc->analyze([$this->task('tp', ['fileA.php'])]);
        $this->assertSame($a['analysis_hash'], $b['analysis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['analysis_hash']);
    }

    public function test_multiple_tasks_handled(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze(
            [
                $this->task('tp-1', ['fileA.php']),
                $this->task('tp-2', ['fileB.php']),
            ],
            [
                'use_live_ledger' => false,
                'active_leases' => [[
                    'lease_id' => 'lease-1',
                    'scope_lock' => ['write_set' => ['fileA.php']],
                ]],
            ],
        );
        $this->assertSame(1, $result['clear_task_count']);
        $this->assertSame(1, $result['conflicting_task_count']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_runtime_safety_in_envelope(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([$this->task('tp', ['x'])]);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_empty_tasks_returns_empty_analyses(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([]);
        $this->assertSame([], $result['analyses']);
        $this->assertSame(0, $result['clear_task_count']);
        $this->assertSame(0, $result['conflicting_task_count']);
    }

    public function test_tasks_without_id_skipped(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([
            ['task_packet_id' => '', 'scope_lock' => ['write_set' => ['x']]],
            $this->task('tp-1', ['y']),
        ]);
        $this->assertSame(1, count($result['analyses']));
    }

    public function test_live_ledger_default_true(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([$this->task('tp', ['x'])]);
        $this->assertTrue($result['use_live_ledger']);
    }

    public function test_has_scope_lock_false_when_empty(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze([['task_packet_id' => 'tp', 'scope_lock' => []]]);
        $this->assertFalse($result['analyses'][0]['has_scope_lock']);
    }

    public function test_partial_overlap_detected(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze(
            [$this->task('tp', ['fileA.php', 'fileB.php', 'fileC.php'])],
            [
                'use_live_ledger' => false,
                'active_leases' => [[
                    'lease_id' => 'lease-1',
                    'scope_lock' => ['write_set' => ['fileA.php', 'fileC.php']],
                ]],
            ],
        );
        $this->assertSame('conflict', $result['analyses'][0]['conflict_status']);
        $this->assertSame(2, count($result['analyses'][0]['conflicts'][0]['overlap']));
    }

    public function test_use_live_ledger_false_uses_provided(): void
    {
        $svc = new AgentDispatchPlannerScopeConflictAnalyzer;
        $result = $svc->analyze(
            [$this->task('tp', ['fileA.php'])],
            ['use_live_ledger' => false, 'active_leases' => []],
        );
        $this->assertFalse($result['use_live_ledger']);
        $this->assertSame(0, $result['active_lease_count']);
    }

    /**
     * @param  array<int, string>  $writeSet
     * @return array<string, mixed>
     */
    private function task(string $id, array $writeSet): array
    {
        return [
            'task_packet_id' => $id,
            'scope_lock' => ['write_set' => $writeSet, 'read_set' => []],
        ];
    }
}
