<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\InspectorSnapshot;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the AAEL pre-step state inspector is live at the operator surface: feeding a run id, a step (with kind +
 * allowed files) and an env emits a snapshot carrying the run id, the step index, and the supplied step facts; an
 * unknown step kind is refused.
 */
final class AtlasLoopAaelCaptureCommandTest extends TestCase
{
    private function capture(string $runId, int $stepIndex, array $step, array $env): array
    {
        $exit = Artisan::call('atlas:loop:aael-capture', [
            '--run-id' => $runId,
            '--step-index' => (string) $stepIndex,
            '--step' => (string) json_encode($step),
            '--env' => (string) json_encode($env),
            '--files' => '[]',
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_capture_emits_snapshot_with_run_id_step_index_and_step_facts(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->capture('run-abc', 2, [
            'kind' => 'write',
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
        ], [
            'cwd' => '/repo',
            'git_head' => 'deadbeef',
            'dirty' => false,
        ]);

        $this->assertSame(0, $exit, (string) json_encode($d));
        $this->assertSame(InspectorSnapshot::SCHEMA, $d['schema']);
        $this->assertSame('run-abc', $d['run_id']);
        $this->assertSame(2, $d['body']['step_index']);
        $this->assertSame('write', $d['body']['step_kind']);
        $this->assertSame(['app/Bar.php', 'app/Foo.php'], $d['body']['allowed_files']); // sorted by the service
        $this->assertSame('deadbeef', $d['body']['env']['git_head']);
        $this->assertSame('/repo', $d['body']['env']['cwd']);
    }

    public function test_unknown_step_kind_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->capture('run-x', 0, ['kind' => 'not_a_kind'], []);

        $this->assertNotSame(0, $exit);
        $this->assertSame('capture_refused', $d['reason']);
        $this->assertStringContainsString('inspector_unknown_step_kind', $d['message']);
    }

    public function test_missing_run_id_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:aael-capture', ['--step' => '{"kind":"read"}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
