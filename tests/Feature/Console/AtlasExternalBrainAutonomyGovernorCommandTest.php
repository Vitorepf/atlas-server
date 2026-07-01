<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyGovernorCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-autonomy-governor-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->inputFile = $this->tempBase.'/input.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    private function writeInput(array $data): void
    {
        file_put_contents($this->inputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:autonomy-governor', $args);

        return [$exit, $kernel->output()];
    }

    public function test_missing_input_option_fails(): void
    {
        [$exit] = $this->runCmd([]);

        $this->assertSame(1, $exit);
    }

    public function test_nonexistent_input_path_fails(): void
    {
        [$exit] = $this->runCmd(['--input' => $this->tempBase.'/does-not-exist.json']);

        $this->assertSame(1, $exit);
    }

    public function test_invalid_json_fails(): void
    {
        file_put_contents($this->inputFile, 'not json');

        [$exit] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(1, $exit);
    }

    public function test_empty_sections_produce_report_with_governor_action(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('run_policy', $decoded);
        $this->assertArrayHasKey('run_evaluation', $decoded);
        $this->assertArrayHasKey('queue_saturation', $decoded);
        $this->assertArrayHasKey('family_yield', $decoded);
        $this->assertArrayHasKey('enqueue_throttle', $decoded);
        $this->assertNotEmpty($decoded['governor_action']);
    }

    public function test_saturated_queue_with_recoverable_backlog_triggers_self_heal(): void
    {
        $this->writeInput([
            'saturation' => [
                'claimable_depth' => 0,
                'servable_now' => 0,
                'recoverable_backlog' => 5,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('unblock_first', $decoded['queue_saturation']['decision']);
        $this->assertSame('self_heal', $decoded['governor_action']);
    }

    public function test_low_yield_families_trigger_pivot_when_no_other_action_wins(): void
    {
        $this->writeInput([
            'families' => [
                'families' => [
                    [
                        'family_id' => 'spec-heavy',
                        'accepted_specs' => 6,
                        'resolved_capability_deltas' => 0,
                    ],
                ],
            ],
            'throttle' => [
                'batch_value_score' => 0.9,
                'recommended_batch_size' => 3,
                'novelty_score' => 0.9,
                'impact_diversity_score' => 0.9,
                'worker_drain_confidence' => 0.9,
                'roadmap_coverage_score' => 0.9,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertNotEmpty($decoded['family_yield']['low_yield_families']);
        $lowYieldIds = array_column($decoded['family_yield']['low_yield_families'], 'family_id');
        $this->assertContains('spec-heavy', $lowYieldIds);
        $this->assertSame('pivot_families', $decoded['governor_action']);
    }

    public function test_blocked_throttle_without_trim_pauses(): void
    {
        $this->writeInput([
            'throttle' => [
                'batch_value_score' => 0.1,
                'recommended_batch_size' => 3,
                'novelty_score' => 0.9,
                'impact_diversity_score' => 0.9,
                'worker_drain_confidence' => 0.9,
                'roadmap_coverage_score' => 0.9,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['enqueue_throttle']['allow_enqueue']);
        $this->assertSame([], $decoded['enqueue_throttle']['trimmed_task_ids']);
        $this->assertSame('pause', $decoded['governor_action']);
    }
}
