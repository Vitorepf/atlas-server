<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasVetoPropagationWatchdogWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $eventsFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-department-status-veto-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->eventsFile = $this->tempBase.'/events.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    public function test_department_status_command_replays_veto_events_through_watchdog(): void
    {
        file_put_contents($this->eventsFile, json_encode([
            ['department' => 'security'],
        ]));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', [
            '--veto-events' => $this->eventsFile,
            '--json' => true,
        ]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);

        $this->assertArrayHasKey('veto_propagation', $decoded);
        $this->assertSame(
            ['dev', 'forge', 'delivery'],
            $decoded['veto_propagation']['paused_departments'],
        );
        $this->assertFalse($decoded['veto_propagation']['final_override_active']);
        $this->assertCount(1, $decoded['veto_propagation']['veto_receipts']);
    }

    public function test_department_status_command_omits_veto_propagation_without_option(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', ['--json' => true]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('veto_propagation', $decoded);
    }
}
