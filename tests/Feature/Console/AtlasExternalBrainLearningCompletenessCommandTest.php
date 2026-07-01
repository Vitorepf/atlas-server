<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainLearningCompletenessCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-learning-completeness-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:learning-completeness', $args);

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

    public function test_empty_sections_produce_complete_true_and_empty_lists(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['complete']);
        $this->assertSame([], $decoded['missing_links']);
        $this->assertSame([], $decoded['compounding_routes']);
    }

    public function test_incomplete_cycle_emits_missing_link_and_repair_hint(): void
    {
        $this->writeInput([
            'cycles' => [
                [
                    'cycle_id' => 'cycle-1',
                    'implementation_result' => ['commit_sha' => 'abc123'],
                    'runnable_evidence' => ['command' => 'phpunit', 'outcome' => 'pass'],
                    'learning_update' => ['pattern_family' => 'family-a'],
                    'next_batch_constraint' => ['promoted_rule' => 'x'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['complete']);
        $this->assertContains('no_origination_receipt', $decoded['missing_links']);
        $this->assertSame('emit_origination_receipt_for_cycle', $decoded['next_repair_task_hint']);
    }

    public function test_complete_cycle_reports_complete_true(): void
    {
        $this->writeInput([
            'cycles' => [
                [
                    'cycle_id' => 'cycle-2',
                    'origination_receipt' => ['task_packet_id' => 'tp-1'],
                    'implementation_result' => ['commit_sha' => 'abc123'],
                    'runnable_evidence' => ['command' => 'phpunit', 'outcome' => 'pass'],
                    'learning_update' => ['pattern_family' => 'family-a'],
                    'next_batch_constraint' => ['promoted_rule' => 'x'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['complete']);
        $this->assertSame([], $decoded['missing_links']);
    }

    public function test_worker_feedback_notes_are_normalized_into_facts(): void
    {
        $this->writeInput([
            'worker_notes' => [
                [
                    'task_id' => 'task-1',
                    'outcome_type' => 'success',
                    'note' => 'Completed the task with a runnable test.',
                    'evidence' => '/opt/homebrew/bin/php artisan test FooTest',
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame(1, $decoded['worker_feedback']['count']);
        $this->assertSame('verified', $decoded['worker_feedback']['facts'][0]['evidence_status']);
    }

    public function test_compounding_route_classifies_unlocks_new_capability(): void
    {
        $this->writeInput([
            'outcomes' => [
                [
                    'outcome_type' => 'new_capability',
                    'wired_callers_count' => 2,
                    'evidence_refs' => ['integration:proof'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('unlocks_new_capability', $decoded['compounding_routes'][0]['bucket']);
    }
}
