<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasAaeosGateSignalEvaluatorWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $phaseGatesFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-department-status-phase-gates-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->phaseGatesFile = $this->tempBase.'/phase_outputs.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    public function test_department_status_command_evaluates_phase_gates(): void
    {
        file_put_contents($this->phaseGatesFile, json_encode([
            'spec_pack' => [
                'acceptance_criteria' => ['criterion one', 'criterion two', 'criterion three'],
            ],
        ]));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', [
            '--phase-gates' => $this->phaseGatesFile,
            '--json' => true,
        ]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);

        $this->assertTrue($decoded['phase_gates']['all_passed']);
        $this->assertCount(1, $decoded['phase_gates']['gates']);
        $this->assertSame('spec_pack_acceptance_criteria_min_3', $decoded['phase_gates']['gates'][0]['gate']);
    }

    public function test_department_status_command_omits_phase_gates_without_option(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', ['--json' => true]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('phase_gates', $decoded);
    }
}
