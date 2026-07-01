<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneConvergenceCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-cp-convergence-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:control-plane-convergence', $args);

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

    public function test_weak_integration_coverage_forces_run_consolidation_stop_go(): void
    {
        $this->writeInput([
            'integration_gate' => [
                'organs' => [
                    ['organ_id' => 'ornamental_helper', 'is_important' => true, 'is_read_only_helper' => true, 'control_plane_exposure' => false],
                    ['organ_id' => 'unwired_organ', 'is_important' => true],
                ],
            ],
            'stop_go_bridge' => [
                'queue_health' => 'healthy',
                'quality_trend' => 'high',
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertEquals(0.0, $decoded['integration_coverage_percent']);
        $this->assertContains('ornamental_helper', $decoded['blocked_organs']);
        $this->assertSame('run_consolidation', $decoded['stop_go_decision']);
        $this->assertSame('stop', $decoded['stop_go_signal']);
    }

    public function test_full_integration_coverage_allows_stop_go_to_proceed_normally(): void
    {
        $this->writeInput([
            'integration_gate' => [
                'organs' => [
                    ['organ_id' => 'wired_organ', 'is_important' => true, 'control_plane_exposure' => true],
                ],
            ],
            'stop_go_bridge' => [
                'queue_health' => 'healthy',
                'quality_trend' => 'high',
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertEquals(100.0, $decoded['integration_coverage_percent']);
        $this->assertSame('create_more_tasks', $decoded['stop_go_decision']);
        $this->assertSame('go', $decoded['stop_go_signal']);
    }

    public function test_explicit_stop_go_coverage_override_is_not_replaced_by_gate(): void
    {
        $this->writeInput([
            'integration_gate' => [
                'organs' => [
                    ['organ_id' => 'wired_organ', 'is_important' => true, 'control_plane_exposure' => true],
                ],
            ],
            'stop_go_bridge' => [
                'queue_health' => 'healthy',
                'quality_trend' => 'high',
                'integration_coverage_percent' => 10.0,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertEquals(100.0, $decoded['integration_coverage_percent'], 'gate audit figure is still reported');
        $this->assertSame('run_consolidation', $decoded['stop_go_decision'], 'bridge used the explicit override, not the gate value');
    }
}
