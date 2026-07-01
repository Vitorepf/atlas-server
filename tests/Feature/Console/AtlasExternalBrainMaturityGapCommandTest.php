<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainMaturityGapCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-maturity-gap-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:maturity-gap', $args);

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

    public function test_combines_maturity_gaps_and_drift_findings(): void
    {
        $this->writeInput([
            'rubric' => [
                [
                    'dimension' => 'task_fabric',
                    'leverage' => 0.9,
                    'required_evidence_signals' => ['test_gate_pass', 'runtime_evidence'],
                    'task_family' => 'task_fabric_hardening',
                ],
            ],
            'control_plane_snapshot' => [
                'proven_evidence' => [],
            ],
            'map_entries' => [
                [
                    'area_id' => 'maestro',
                    'state' => 'integrated',
                    'has_completion_evidence' => false,
                    'owner' => 'atlas',
                    'maturity_band' => 'advanced',
                    'next_leverage' => 'x',
                ],
            ],
            'queued_areas' => ['maestro'],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertNotEmpty($decoded['blockers']);
        $this->assertSame('task_fabric', $decoded['blockers'][0]['dimension']);
        $this->assertTrue($decoded['has_drift']);
        $this->assertNotEmpty($decoded['domain_map_drift']);
        $this->assertSame('maestro', $decoded['domain_map_drift'][0]['area_id']);
    }

    public function test_empty_sections_produce_no_gaps_and_no_drift(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['blockers']);
        $this->assertFalse($decoded['has_drift']);
    }
}
