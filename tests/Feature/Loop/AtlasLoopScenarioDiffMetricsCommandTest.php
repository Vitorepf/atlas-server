<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves the scenario diff-metrics calculator is live at the operator surface and emits deterministic facts:
 * a fresh workspace with one untracked 3-line file scores {files:1, lines:3}; a missing --workspace is a
 * usage error. Read-only — it only counts the git diff.
 */
final class AtlasLoopScenarioDiffMetricsCommandTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    public function test_requires_workspace(): void
    {
        $exit = Artisan::call('atlas:loop:scenario-diff-metrics', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_counts_untracked_diff_in_a_workspace(): void
    {
        $this->workspace = sys_get_temp_dir().'/scenario_diff_'.bin2hex(random_bytes(6));
        mkdir($this->workspace);
        (new Process(['git', 'init', '-q'], $this->workspace))->mustRun();
        file_put_contents($this->workspace.'/foo.txt', "a\nb\nc\n"); // 3 lines, untracked

        $exit = Artisan::call('atlas:loop:scenario-diff-metrics', ['--workspace' => $this->workspace, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.scenario_diff_metrics.v1', $decoded['schema']);
        $this->assertSame(1, $decoded['files']);
        $this->assertSame(3, $decoded['lines']);
    }
}
