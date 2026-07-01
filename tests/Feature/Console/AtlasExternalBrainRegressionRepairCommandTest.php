<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainRegressionRepairCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-regression-repair-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:regression-repair', $args);

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

    public function test_empty_sections_produce_clean_no_regression_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('no_regression', $decoded['gate_regression']['verdict']);
        $this->assertFalse($decoded['blocked_origination']);
        $this->assertFalse($decoded['has_poison_packets']);
        $this->assertFalse($decoded['has_runnable_repair_specs']);
    }

    public function test_gate_regression_holes_block_origination(): void
    {
        $this->writeInput([
            'audit' => [
                'attacks_tried' => 5,
                'holes' => [
                    [
                        'attack' => 'contradictory_acceptance',
                        'expected' => 'gate_must_reject',
                        'deficiencies' => ['hidden_poison:contradictory_acceptance'],
                        'severity' => 'critical',
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('repair_first', $decoded['gate_regression']['verdict']);
        $this->assertTrue($decoded['blocked_origination']);
    }

    public function test_repeated_give_backs_produce_poison_packets_and_repair_ranking(): void
    {
        $this->writeInput([
            'give_backs' => [
                [
                    'task_packet_id' => 'poisoned-task-1',
                    'task_id' => 'poisoned-task-1',
                    'give_back_class' => 'duplicate_capability',
                    'give_back_count' => 3,
                    'root_cause' => 'duplicate_capability',
                    'duplicate_capability' => true,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertContains('poisoned-task-1', $decoded['give_back_root_causes']['poison_packets']);
        $this->assertTrue($decoded['has_poison_packets']);
        $this->assertNotEmpty($decoded['queue_repair_ranking']['ranked_repair_candidates']);
        $this->assertSame(
            'cancel_duplicate',
            $decoded['queue_repair_ranking']['ranked_repair_candidates'][0]['repair_plan'],
        );
    }

    public function test_promoted_diagnostics_produce_runnable_repair_specs(): void
    {
        $this->writeInput([
            'diagnostics' => [
                [
                    'diagnostic_id' => 'd1',
                    'gate_name' => 'phpunit',
                    'target_path' => 'app/Services/Ai/SelfConstruction/ExternalBrain/SomeService.php',
                    'runnable_proof_command' => 'php artisan test tests/Feature/Ai/SomeServiceTest.php',
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['has_runnable_repair_specs']);
        $this->assertNotEmpty($decoded['repair_synthesis']['repair_specs']);
    }
}
