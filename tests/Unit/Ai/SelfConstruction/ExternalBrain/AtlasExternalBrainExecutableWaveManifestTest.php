<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainExecutableWaveManifest;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainExecutableWaveManifestTest extends TestCase
{
    private function manifest(): AtlasExternalBrainExecutableWaveManifest
    {
        return new AtlasExternalBrainExecutableWaveManifest;
    }

    private function task(string $id, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $id,
            'priority' => 0.5,
            'on_critical_path' => true,
            'novelty_score' => 1.0,
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_new_required_keys(): void
    {
        $r = $this->manifest()->build(['tasks' => []]);

        foreach (['prerequisites', 'proof_gates', 'expected_capability_delta', 'rollback_notes', 'worker_ready', 'not_worker_ready_reasons'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertTrue($r['worker_ready']);
        $this->assertSame([], $r['not_worker_ready_reasons']);
    }

    // ── AC2: ordered tasks, prerequisites, proof gates, expected_capability_delta ──

    public function test_wave_task_includes_prerequisites_proof_gate_and_capability_delta(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', [
                'prerequisite_task_ids' => ['p1', 'p2'],
                'prerequisites_proven' => true,
                'proof_gate' => 'php artisan test tests/Unit/FooTest.php',
                'expected_capability_delta' => 'FooService now validates input at the boundary',
            ]),
        ]]);

        $this->assertSame(['a'], $r['ordered_task_ids']);
        $this->assertSame(['p1', 'p2'], $r['prerequisites']['a']);
        $this->assertSame(['php artisan test tests/Unit/FooTest.php'], $r['proof_gates']['a']);
        $this->assertSame('FooService now validates input at the boundary', $r['expected_capability_delta']['a']);
    }

    public function test_wave_task_without_proof_gate_falls_back_to_default(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a')]]);

        $this->assertSame(['tests_or_gates_result'], $r['proof_gates']['a']);
    }

    // ── AC3: missing prerequisite proof marks the wave not_worker_ready ────────

    public function test_unproven_prerequisite_marks_wave_not_worker_ready(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', [
                'prerequisite_task_ids' => ['p1'],
                'prerequisites_proven' => false,
            ]),
        ]]);

        $this->assertFalse($r['worker_ready']);
        $this->assertContains('missing_prerequisite_proof:a', $r['not_worker_ready_reasons']);
    }

    public function test_proven_prerequisite_keeps_wave_worker_ready(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', [
                'prerequisite_task_ids' => ['p1'],
                'prerequisites_proven' => true,
            ]),
        ]]);

        $this->assertTrue($r['worker_ready']);
    }

    public function test_no_prerequisites_declared_does_not_block_worker_ready(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a')]]);

        $this->assertTrue($r['worker_ready']);
        $this->assertSame([], $r['prerequisites']['a']);
    }

    // ── AC4: rollback notes required when runtime/queue behavior is touched ───

    public function test_touching_runtime_without_rollback_notes_marks_not_worker_ready(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', ['touches_runtime_or_queue' => true]),
        ]]);

        $this->assertFalse($r['worker_ready']);
        $this->assertContains('missing_rollback_notes:a', $r['not_worker_ready_reasons']);
    }

    public function test_touching_runtime_with_rollback_notes_is_worker_ready(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', [
                'touches_runtime_or_queue' => true,
                'rollback_notes' => 'git revert the merge commit; no queue schema change',
            ]),
        ]]);

        $this->assertTrue($r['worker_ready']);
        $this->assertSame('git revert the merge commit; no queue schema change', $r['rollback_notes']['a']);
    }

    public function test_not_touching_runtime_never_requires_rollback_notes(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a')]]);

        $this->assertTrue($r['worker_ready']);
        $this->assertSame('', $r['rollback_notes']['a']);
    }

    public function test_multiple_wave_tasks_each_contribute_their_own_not_worker_ready_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', ['prerequisite_task_ids' => ['p1'], 'prerequisites_proven' => false]),
            $this->task('b', ['touches_runtime_or_queue' => true]),
        ]]);

        $this->assertContains('missing_prerequisite_proof:a', $r['not_worker_ready_reasons']);
        $this->assertContains('missing_rollback_notes:b', $r['not_worker_ready_reasons']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['tasks' => [
            $this->task('a', ['prerequisite_task_ids' => ['p1'], 'prerequisites_proven' => true]),
            $this->task('b', ['touches_runtime_or_queue' => true, 'rollback_notes' => 'revert commit']),
        ]];

        $this->assertSame($this->manifest()->build($input), $this->manifest()->build($input));
    }
}
