<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphWaveCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-task-graph-wave-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:task-graph-wave', $args);

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

    public function test_composes_all_four_stages_into_one_manifest(): void
    {
        $this->writeInput([
            'roi_scheduler' => [
                'tasks' => [
                    ['task_id' => 'a', 'expected_impact' => 0.8, 'unlock_value' => 0.9, 'cost_risk' => 0.1],
                    ['task_id' => 'b', 'depends_on' => ['a'], 'expected_impact' => 0.5, 'unlock_value' => 0.0, 'cost_risk' => 0.2],
                ],
            ],
            'prerequisite_detector' => [
                'tasks' => [
                    ['task_id' => 'a', 'unlocks' => ['b']],
                    ['task_id' => 'b', 'depends_on' => ['a']],
                ],
            ],
            'wave_manifest' => [
                'tasks' => [
                    ['task_id' => 'a', 'priority' => 0.9, 'on_critical_path' => true],
                ],
            ],
            'release_gate' => [
                'candidates' => [
                    ['task_id' => 'a', 'on_critical_path' => true],
                ],
                'critical_path_task_ids' => [],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertNotEmpty($decoded['waves']);
        $this->assertSame(['a', 'b'], $decoded['critical_path']);
        $this->assertNotEmpty($decoded['prerequisite_candidates']);
        $this->assertSame('a', $decoded['prerequisite_candidates'][0]['task_id']);
        $this->assertSame(['a'], $decoded['ordered_task_ids']);
        $this->assertTrue($decoded['release_allowed']);
        $this->assertSame([], $decoded['blocked_task_ids']);
    }

    public function test_missing_sections_produce_empty_stage_output(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['waves']);
        $this->assertSame([], $decoded['prerequisite_candidates']);
        $this->assertSame([], $decoded['ordered_task_ids']);
        $this->assertTrue($decoded['release_allowed']);
    }
}
