<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the worker-result normalizer is live at the operator surface and emits deterministic facts: an
 * in-scope result with passing gates and the required evidence is verified_pass; a result touching files
 * outside the allowed scope is blocked. A missing --task/--result is a usage error.
 */
final class AtlasLoopWorkerResultNormalizeCommandTest extends TestCase
{
    public function test_requires_task_and_result(): void
    {
        $exit = Artisan::call('atlas:loop:worker-result-normalize', ['--result' => '{}', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_in_scope_passing_result_is_verified_pass(): void
    {
        $decoded = $this->normalize(
            [
                'task_id' => 't1',
                'lease_id' => 'lease_1',
                'allowed_files' => ['app/Foo.php'],
                'required_evidence_kinds' => ['tests_or_gates_result'],
            ],
            [
                'claimed_outcome' => 'success',
                'changed_files' => ['app/Foo.php'],
                'gate_outputs' => ['phpunit' => ['passed' => true]],
                'evidence_refs' => ['tests_or_gates_result:abc123'],
            ],
        );

        $this->assertSame('atlas.worker_swarm.result_normalizer.v1', $decoded['schema_version']);
        $this->assertSame('verified_pass', $decoded['court_status']);
        $this->assertSame([], $decoded['blockers']);
        $this->assertSame([], $decoded['out_of_scope_files']);
    }

    public function test_out_of_scope_result_is_blocked(): void
    {
        $decoded = $this->normalize(
            ['task_id' => 't2', 'allowed_files' => ['app/Foo.php'], 'required_evidence_kinds' => []],
            ['claimed_outcome' => 'success', 'changed_files' => ['app/Foo.php', 'app/Sneaky.php']],
        );

        $this->assertSame('blocked', $decoded['court_status']);
        $this->assertContains('app/Sneaky.php', $decoded['out_of_scope_files']);
        $this->assertNotEmpty($decoded['blockers']);
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function normalize(array $task, array $result): array
    {
        $exit = Artisan::call('atlas:loop:worker-result-normalize', [
            '--task' => json_encode($task),
            '--result' => json_encode($result),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
