<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphChainAdmissionBinder;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphChainAdmissionBinderTest extends TestCase
{
    private function binder(): AtlasTaskGraphChainAdmissionBinder
    {
        return new AtlasTaskGraphChainAdmissionBinder;
    }

    /** Returns a step that passes the brutal value gate. */
    private function validStep(string $taskId, array $overrides = []): array
    {
        return array_merge([
            'task_id'        => $taskId,
            'objective'      => 'Implement a well-defined service that provides real value to the system',
            'allowed_files'  => ['app/Services/MyService.php', 'tests/MyServiceTest.php'],
            'compound_impact_score' => 0.60,
            'give_back_risk_score'  => 0.20,
            'depends_on'      => [],
        ], $overrides);
    }

    /** Returns a step that fails the gate (objective too short). */
    private function invalidStep(string $taskId): array
    {
        return [
            'task_id'        => $taskId,
            'objective'      => 'short',
            'allowed_files'  => ['app/Services/MyService.php', 'tests/MyServiceTest.php'],
            'compound_impact_score' => 0.60,
            'give_back_risk_score'  => 0.20,
            'depends_on'      => [],
        ];
    }

    // ── AC2: gate rejection ───────────────────────────────────────────────────

    public function test_step_failing_gate_rejects_chain(): void
    {
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch1',
            'steps'    => [$this->invalidStep('t1')],
        ]]]);

        $this->assertCount(0, $r['accepted_chains']);
        $this->assertCount(1, $r['rejected_chains']);
        $this->assertContains('gate_rejection', $r['rejected_chains'][0]['rejection_reasons']);
    }

    public function test_all_valid_steps_are_accepted(): void
    {
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch2',
            'steps'    => [
                $this->validStep('t1'),
                $this->validStep('t2', ['depends_on' => ['t1']]),
            ],
        ]]]);

        $this->assertCount(1, $r['accepted_chains']);
        $this->assertCount(0, $r['rejected_chains']);
        $this->assertSame(2, $r['accepted_chains'][0]['step_count']);
    }

    // ── AC3: depends_on order violations ─────────────────────────────────────

    public function test_depends_on_missing_task_produces_order_violation(): void
    {
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch3',
            'steps'    => [
                $this->validStep('t1', ['depends_on' => ['nonexistent']]),
            ],
        ]]]);

        $this->assertContains('depends_on_order_violation', $r['rejected_chains'][0]['rejection_reasons']);
    }

    public function test_depends_on_same_position_produces_order_violation(): void
    {
        // Both t1 and t2 at same position is impossible since steps is a list.
        // Test t2 depending on t3 (later position).
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch4',
            'steps'    => [
                $this->validStep('t1', ['depends_on' => ['t2']]), // t2 comes after
                $this->validStep('t2'),
            ],
        ]]]);

        $this->assertContains('depends_on_order_violation', $r['rejected_chains'][0]['rejection_reasons']);
    }

    public function test_depends_on_edges_emitted_for_all_chains(): void
    {
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch5',
            'steps'    => [
                $this->validStep('t1'),
                $this->validStep('t2', ['depends_on' => ['t1']]),
            ],
        ]]]);

        $edgeTargets = array_column($r['depends_on_edges'], 'to');
        $this->assertContains('t2', $edgeTargets);

        $edgeSources = array_column($r['depends_on_edges'], 'from');
        $this->assertContains('t1', $edgeSources);
    }

    public function test_valid_depends_on_order_does_not_produce_violation(): void
    {
        // Use distinct files per step to avoid write-set conflicts between non-directly-linked steps.
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch6',
            'steps'    => [
                $this->validStep('t1', ['allowed_files' => ['app/Services/FooService.php', 'tests/FooServiceTest.php']]),
                $this->validStep('t2', ['allowed_files' => ['app/Services/BarService.php', 'tests/BarServiceTest.php'], 'depends_on' => ['t1']]),
                $this->validStep('t3', ['allowed_files' => ['app/Services/BazService.php', 'tests/BazServiceTest.php'], 'depends_on' => ['t2']]),
            ],
        ]]]);

        $this->assertCount(1, $r['accepted_chains']);
        $this->assertSame([], $r['rejected_chains']);
    }

    // ── AC4: write-set conflicts ──────────────────────────────────────────────

    public function test_parallel_steps_sharing_file_produce_write_set_conflict(): void
    {
        $sharedFile = 'app/Services/SharedService.php';

        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch7',
            'steps'    => [
                $this->validStep('t1', ['allowed_files' => [$sharedFile, 'tests/MyServiceTest.php']]),
                $this->validStep('t2', ['allowed_files' => [$sharedFile, 'tests/OtherTest.php']]),
                // No depends_on between t1 and t2 → parallel
            ],
        ]]]);

        $this->assertContains('write_set_conflict', $r['rejected_chains'][0]['rejection_reasons']);
        $this->assertCount(1, $r['write_set_conflicts']);
        $this->assertContains($sharedFile, $r['write_set_conflicts'][0]['conflicting_files']);
    }

    public function test_steps_with_direct_dependency_do_not_conflict(): void
    {
        $sharedFile = 'app/Services/SharedService.php';

        // t2 depends on t1 → not parallel → no conflict
        $r = $this->binder()->bind(['chains' => [[
            'chain_id' => 'ch8',
            'steps'    => [
                $this->validStep('t1', ['allowed_files' => [$sharedFile, 'tests/MyServiceTest.php']]),
                $this->validStep('t2', [
                    'allowed_files' => [$sharedFile, 'tests/OtherTest.php'],
                    'depends_on'    => ['t1'],
                ]),
            ],
        ]]]);

        $this->assertCount(1, $r['accepted_chains']);
        $this->assertSame([], $r['write_set_conflicts']);
    }
}
