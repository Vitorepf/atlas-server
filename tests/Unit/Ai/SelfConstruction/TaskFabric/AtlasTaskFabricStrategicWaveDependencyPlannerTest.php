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
}
