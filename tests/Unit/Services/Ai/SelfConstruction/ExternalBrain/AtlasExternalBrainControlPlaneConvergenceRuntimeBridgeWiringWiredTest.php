<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneConvergenceRuntimeBridgeWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-cp-convergence-runtime-bridge-'.bin2hex(random_bytes(6));
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

    public function test_command_exposes_runtime_bridge_simplification_pressure_for_high_blocked_ratio(): void
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
        $this->assertSame('high', $decoded['simplification_pressure']);
        $this->assertEquals(1.0, $decoded['blocked_organ_ratio']);
    }

    public function test_command_exposes_runtime_bridge_low_pressure_for_full_coverage(): void
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
        $this->assertSame('low', $decoded['simplification_pressure']);
        $this->assertEquals(0.0, $decoded['blocked_organ_ratio']);
    }
}
