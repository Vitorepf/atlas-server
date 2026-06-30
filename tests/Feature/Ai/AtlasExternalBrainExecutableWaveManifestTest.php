<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainExecutableWaveManifest;
use Tests\TestCase;

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

    public function test_output_has_required_keys(): void
    {
        $r = $this->manifest()->build(['tasks' => []]);

        foreach (['schema', 'wave_id', 'ordered_task_ids', 'task_reasons', 'expected_unlocks', 'assigned_muscle_hints', 'out_of_wave_reasons'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertSame(AtlasExternalBrainExecutableWaveManifest::SCHEMA, $r['schema']);
    }

    public function test_eligible_tasks_ordered_by_priority_desc(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('low', ['priority' => 0.2]),
            $this->task('high', ['priority' => 0.9]),
        ]]);

        $this->assertSame(['high', 'low'], $r['ordered_task_ids']);
    }

    public function test_blocked_task_excluded_with_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', ['blocked' => true]),
        ]]);

        $this->assertSame([], $r['ordered_task_ids']);
        $this->assertSame('blocked', $r['out_of_wave_reasons']['a']);
    }

    public function test_stale_task_excluded_with_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a', ['stale' => true])]]);
        $this->assertSame('stale', $r['out_of_wave_reasons']['a']);
    }

    public function test_duplicate_task_excluded_with_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a', ['duplicate' => true])]]);
        $this->assertSame('duplicate', $r['out_of_wave_reasons']['a']);
    }

    public function test_low_novelty_task_excluded_with_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a', ['novelty_score' => 0.05])]]);
        $this->assertSame('low_novelty', $r['out_of_wave_reasons']['a']);
    }

    public function test_off_critical_path_task_excluded_with_reason(): void
    {
        $r = $this->manifest()->build(['tasks' => [$this->task('a', ['on_critical_path' => false])]]);
        $this->assertSame('off_critical_path', $r['out_of_wave_reasons']['a']);
    }

    public function test_repair_blocker_overrides_every_exclusion(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', [
                'blocked' => true,
                'stale' => true,
                'duplicate' => true,
                'novelty_score' => 0.0,
                'on_critical_path' => false,
                'is_repair_blocker' => true,
            ]),
        ]]);

        $this->assertSame(['a'], $r['ordered_task_ids']);
        $this->assertSame('repair_blocker_override', $r['task_reasons']['a']);
        $this->assertArrayNotHasKey('a', $r['out_of_wave_reasons']);
    }

    public function test_wave_size_cap_excludes_overflow_with_reason(): void
    {
        $r = $this->manifest()->build([
            'tasks' => [
                $this->task('a', ['priority' => 0.9]),
                $this->task('b', ['priority' => 0.8]),
                $this->task('c', ['priority' => 0.7]),
            ],
            'max_wave_size' => 2,
        ]);

        $this->assertSame(['a', 'b'], $r['ordered_task_ids']);
        $this->assertSame('wave_size_cap_exceeded', $r['out_of_wave_reasons']['c']);
    }

    public function test_expected_unlocks_carried_through_for_wave_tasks(): void
    {
        $r = $this->manifest()->build(['tasks' => [
            $this->task('a', ['unlocks' => ['b', 'c']]),
        ]]);

        $this->assertSame(['b', 'c'], $r['expected_unlocks']['a']);
    }

    public function test_muscle_routing_hints_applied_to_wave_tasks(): void
    {
        $r = $this->manifest()->build([
            'tasks' => [$this->task('a')],
            'muscle_routing_hints' => ['a' => 'worker-7'],
        ]);

        $this->assertSame('worker-7', $r['assigned_muscle_hints']['a']);
    }

    public function test_wave_id_is_deterministic_for_same_ordered_tasks(): void
    {
        $input = ['tasks' => [$this->task('a'), $this->task('b', ['priority' => 0.3])]];

        $first = $this->manifest()->build($input);
        $second = $this->manifest()->build($input);

        $this->assertSame($first['wave_id'], $second['wave_id']);
    }

    public function test_build_is_deterministic(): void
    {
        $input = ['tasks' => [$this->task('b'), $this->task('a', ['priority' => 0.9])]];

        $first = $this->manifest()->build($input);
        $second = $this->manifest()->build($input);

        $this->assertSame($first, $second);
    }
}
