<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricStrategicWaveDependencyPlanner;
use Tests\TestCase;

final class AtlasTaskFabricStrategicWaveDependencyPlannerTest extends TestCase
{
    private function svc(): AtlasTaskFabricStrategicWaveDependencyPlanner
    {
        return new AtlasTaskFabricStrategicWaveDependencyPlanner;
    }

    private function task(string $id, string $type, array $dependsOn = [], bool $blockedScope = false): array
    {
        return ['task_id' => $id, 'type' => $type, 'depends_on' => $dependsOn, 'blocked_scope' => $blockedScope];
    }

    private function plan(array $tasks, array $available = []): array
    {
        return $this->svc()->plan(['tasks' => $tasks, 'available_capabilities' => $available]);
    }

    private function waveNumbers(array $result): array
    {
        return array_column($result['waves'], 'wave');
    }

    private function taskIdsInWave(array $result, int $wave): array
    {
        foreach ($result['waves'] as $w) {
            if ($w['wave'] === $wave) {
                return array_column($w['tasks'], 'task_id');
            }
        }

        return [];
    }

    // ── wave assignment by type ───────────────────────────────────────────────

    public function test_foundation_task_goes_to_wave_0(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation')]);

        $this->assertContains(0, $this->waveNumbers($r));
        $this->assertContains('T1', $this->taskIdsInWave($r, 0));
    }

    public function test_integration_task_goes_to_wave_1(): void
    {
        $r = $this->plan([$this->task('T2', 'integration')]);

        $this->assertContains('T2', $this->taskIdsInWave($r, 1));
    }

    public function test_verification_task_goes_to_wave_2(): void
    {
        $r = $this->plan([$this->task('T3', 'verification')]);

        $this->assertContains('T3', $this->taskIdsInWave($r, 2));
    }

    public function test_simplification_task_goes_to_wave_3(): void
    {
        $r = $this->plan([$this->task('T4', 'simplification')]);

        $this->assertContains('T4', $this->taskIdsInWave($r, 3));
    }

    public function test_waves_sorted_ascending(): void
    {
        $r = $this->plan([
            $this->task('T-s', 'simplification'),
            $this->task('T-f', 'foundation'),
            $this->task('T-v', 'verification'),
            $this->task('T-i', 'integration'),
        ]);

        $nums = $this->waveNumbers($r);
        $sorted = $nums;
        sort($sorted);
        $this->assertSame($sorted, $nums);
    }

    // ── blocked edges ─────────────────────────────────────────────────────────

    public function test_available_capability_does_not_create_blocked_edge(): void
    {
        $r = $this->plan(
            [$this->task('T1', 'integration', ['cap-foundation'])],
            ['cap-foundation'],
        );

        $this->assertSame([], $r['blocked_edges']);
    }

    public function test_missing_capability_creates_blocked_edge(): void
    {
        $r = $this->plan([$this->task('T1', 'integration', ['cap-missing'])]);

        $this->assertNotEmpty($r['blocked_edges']);
        $this->assertSame('T1', $r['blocked_edges'][0]['task_id']);
        $this->assertSame('cap-missing', $r['blocked_edges'][0]['missing_capability']);
        $this->assertSame('capability_not_implemented_or_queued', $r['blocked_edges'][0]['reason']);
    }

    public function test_blocked_scope_creates_blocked_edge_with_null_capability(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation', [], true)]);

        $reasons = array_column($r['blocked_edges'], 'reason');
        $this->assertContains('forbidden_scope_blocked', $reasons);
        $nullCap = array_filter($r['blocked_edges'], static fn ($e) => $e['reason'] === 'forbidden_scope_blocked');
        $this->assertSame(null, array_values($nullCap)[0]['missing_capability']);
    }

    public function test_task_with_blocked_scope_marked_blocked_in_wave(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation', [], true)]);

        $wave0Tasks = $this->taskIdsInWave($r, 0);
        $this->assertContains('T1', $wave0Tasks);

        // Find the task entry and verify blocked=true
        $taskEntry = null;
        foreach ($r['waves'][0]['tasks'] as $t) {
            if ($t['task_id'] === 'T1') {
                $taskEntry = $t;
            }
        }
        $this->assertTrue($taskEntry['blocked']);
    }

    // ── recommended reorderings ───────────────────────────────────────────────

    public function test_missing_capability_creates_reordering_recommendation(): void
    {
        $r = $this->plan([$this->task('T1', 'integration', ['cap-missing'])]);

        $this->assertNotEmpty($r['recommended_reorderings']);
        $rec = $r['recommended_reorderings'][0];
        $this->assertSame('T1', $rec['task_id']);
        $this->assertSame(1, $rec['current_wave']);
        $this->assertSame('defer_until_missing_capabilities_available', $rec['recommended_action']);
        $this->assertContains('cap-missing', $rec['missing_capabilities']);
    }

    public function test_task_with_all_capabilities_available_has_no_reordering(): void
    {
        $r = $this->plan(
            [$this->task('T1', 'integration', ['cap-a', 'cap-b'])],
            ['cap-a', 'cap-b'],
        );

        $this->assertSame([], $r['recommended_reorderings']);
    }

    // ── depends_on_edges ──────────────────────────────────────────────────────

    public function test_depends_on_edges_recorded_for_each_dependency(): void
    {
        $r = $this->plan([$this->task('T1', 'integration', ['cap-a', 'cap-b'])], ['cap-a', 'cap-b']);

        $this->assertCount(2, $r['depends_on_edges']);
        $froms = array_column($r['depends_on_edges'], 'from');
        $this->assertContains('T1', $froms);
    }

    // ── schema + empty ────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->plan([]);

        $this->assertSame(AtlasTaskFabricStrategicWaveDependencyPlanner::SCHEMA, $r['schema_version']);
    }

    public function test_empty_input_returns_empty_waves(): void
    {
        $r = $this->svc()->plan([]);

        $this->assertSame([], $r['waves']);
        $this->assertSame([], $r['blocked_edges']);
        $this->assertSame([], $r['recommended_reorderings']);
    }

    // ── AC2: new task types ──

    public function test_foundation_repair_goes_to_wave_0(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation_repair')]);

        $this->assertContains('T1', $this->taskIdsInWave($r, 0));
    }

    public function test_proof_gate_goes_to_wave_2(): void
    {
        $r = $this->plan([$this->task('T1', 'proof_gate')]);

        $this->assertContains('T1', $this->taskIdsInWave($r, 2));
    }

    public function test_consolidation_goes_to_wave_3(): void
    {
        $r = $this->plan([$this->task('T1', 'consolidation')]);

        $this->assertContains('T1', $this->taskIdsInWave($r, 3));
    }

    public function test_feature_expansion_goes_to_wave_4(): void
    {
        $r = $this->plan([$this->task('T1', 'feature_expansion')]);

        $this->assertContains('T1', $this->taskIdsInWave($r, 4));
    }

    public function test_all_new_types_ordered_correctly(): void
    {
        $r = $this->plan([
            $this->task('feature', 'feature_expansion'),
            $this->task('repair', 'foundation_repair'),
            $this->task('gate', 'proof_gate'),
            $this->task('consolidate', 'consolidation'),
        ]);

        $nums = $this->waveNumbers($r);
        // foundation_repair(0), proof_gate(2), consolidation(3), feature_expansion(4)
        $this->assertSame([0, 2, 3, 4], $nums);
        $this->assertContains('repair', $this->taskIdsInWave($r, 0));
        $this->assertContains('gate', $this->taskIdsInWave($r, 2));
        $this->assertContains('consolidate', $this->taskIdsInWave($r, 3));
        $this->assertContains('feature', $this->taskIdsInWave($r, 4));
    }

    // ── AC3: impossible ordering flagged when downstream lacks implementable prerequisite ──

    public function test_missing_capability_reported_as_blocked_edge(): void
    {
        $r = $this->plan([$this->task('downstream', 'feature_expansion', ['required-cap'])]);

        $this->assertNotEmpty($r['blocked_edges']);
        $edge = $r['blocked_edges'][0];
        $this->assertSame('downstream', $edge['task_id']);
        $this->assertSame('required-cap', $edge['missing_capability']);
        $this->assertSame('capability_not_implemented_or_queued', $edge['reason']);
    }

    // ── AC4: output field aliases ──

    public function test_output_has_ordered_waves(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation')]);

        $this->assertArrayHasKey('ordered_waves', $r);
        $this->assertSame($r['waves'], $r['ordered_waves']);
    }

    public function test_output_has_dependency_edges(): void
    {
        $r = $this->plan([$this->task('T1', 'integration', ['cap-a'])], ['cap-a']);

        $this->assertArrayHasKey('dependency_edges', $r);
        $this->assertSame($r['depends_on_edges'], $r['dependency_edges']);
    }

    public function test_output_has_unlock_rationale(): void
    {
        $r = $this->plan([$this->task('T1', 'foundation')]);

        $this->assertArrayHasKey('unlock_rationale', $r);
        $this->assertNotEmpty($r['unlock_rationale']);
        $this->assertStringContainsString('foundation_repair_before_feature_expansion', $r['unlock_rationale']);
    }

    public function test_unlock_rationale_mentions_blocked_edges_when_present(): void
    {
        $r = $this->plan([$this->task('T1', 'integration', ['missing-cap'])]);

        $this->assertStringContainsString('impossible_ordering_flagged', $r['unlock_rationale']);
    }
}
