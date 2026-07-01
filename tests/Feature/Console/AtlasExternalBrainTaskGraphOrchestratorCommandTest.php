<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphOrchestratorCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-task-graph-orchestrator-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:task-graph-orchestrator', $args);

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
            'critical_path_planner' => [
                'tasks' => [
                    ['task_id' => 'a', 'leverage_score' => 0.8],
                    ['task_id' => 'b', 'depends_on' => ['a'], 'leverage_score' => 0.5],
                ],
            ],
            'staleness_auditor' => [
                'edges' => [['task_id' => 'b', 'depends_on_task_id' => 'ghost']],
            ],
            'prerequisite_detector' => [
                'tasks' => [
                    ['task_id' => 'a', 'unlocks' => ['b']],
                    ['task_id' => 'b', 'depends_on' => ['a']],
                ],
            ],
            'release_gate' => [
                'candidates' => [
                    ['task_id' => 'a'],
                    ['task_id' => 'off-path'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(['a', 'b'], $decoded['critical_path_task_ids']);
        $this->assertNotEmpty($decoded['stale_edges']);
        $this->assertNotEmpty($decoded['prerequisite_candidates']);
    }

    public function test_release_gate_defaults_to_planner_critical_path_and_blocks_off_path_work(): void
    {
        $this->writeInput([
            'critical_path_planner' => [
                'tasks' => [
                    ['task_id' => 'a', 'leverage_score' => 0.9],
                ],
            ],
            'release_gate' => [
                'candidates' => [
                    ['task_id' => 'off-path-leaf', 'novelty_score' => 1.0],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['release_allowed']);
        $this->assertContains('off-path-leaf', $decoded['blocked_task_ids']);
    }

    public function test_explicit_critical_path_override_is_not_replaced_by_planner(): void
    {
        $this->writeInput([
            'critical_path_planner' => [
                'tasks' => [
                    ['task_id' => 'a', 'leverage_score' => 0.9],
                ],
            ],
            'release_gate' => [
                'candidates' => [
                    ['task_id' => 'off-path-leaf'],
                ],
                'critical_path_task_ids' => [],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame(['a'], $decoded['critical_path_task_ids'], 'planner output is still reported');
        $this->assertTrue($decoded['release_allowed'], 'gate used the explicit empty override, not the planner path');
    }

    public function test_missing_sections_produce_empty_stage_output(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['critical_path_task_ids']);
        $this->assertSame([], $decoded['stale_edges']);
        $this->assertSame([], $decoded['prerequisite_candidates']);
        $this->assertTrue($decoded['release_allowed']);
    }
}
