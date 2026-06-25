<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexCadenceScheduleTest extends TestCase
{
    private string $tempRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/atlas-cadence-'.bin2hex(random_bytes(6));
        @mkdir($this->tempRoot, 0o755, true);
        app()->instance(
            AtlasLoopComprehensionCadenceService::class,
            new AtlasLoopComprehensionCadenceService(
                snapshotRoot: $this->tempRoot.'/comp',
                logPath: $this->tempRoot.'/cortex-comprehension-build.log',
                nowIso: fn () => '2026-06-25T05:00:00Z',
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempRoot);
        parent::tearDown();
    }

    private function rrm(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $f) {
            $abs = (string) $f;
            is_dir($abs) ? $this->rrm($abs) : @unlink($abs);
        }
        @rmdir($dir);
    }

    public function test_cli_with_force_rebuilds_snapshot_and_emits_canonical_envelope(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:cadence', ['--json' => true, '--force' => true]);
        $this->assertSame(0, $exit);

        $envelope = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($envelope);
        $this->assertSame(AtlasLoopComprehensionCadenceService::SCHEMA, $envelope['schema']);
        $this->assertTrue($envelope['ok']);
        $this->assertTrue($envelope['stale_before']);
        $this->assertFileExists($envelope['snapshot_path']);
    }

    public function test_subsequent_rebuild_is_not_stale_anymore(): void
    {
        Artisan::call('atlas:loop:cortex:cadence', ['--json' => true, '--force' => true]);
        // Same clock, second invocation — snapshot is still fresh.
        Artisan::call('atlas:loop:cortex:cadence', ['--json' => true, '--force' => true]);
        $envelope = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($envelope['stale_before']);
    }

    public function test_master_switch_off_short_circuits_without_writing_snapshot(): void
    {
        config()->set('atlas.loop.master_switch_enabled_override', false);
        // Force=false ⇒ master switch decides. Use the actual master-switch path: ATLAS_LOOP_MASTER_ENABLED.
        $_ENV['ATLAS_LOOP_MASTER_ENABLED'] = 'false';
        putenv('ATLAS_LOOP_MASTER_ENABLED=false');

        $exit = Artisan::call('atlas:loop:cortex:cadence', ['--json' => true]);
        $this->assertSame(0, $exit);
        $envelope = json_decode(trim(Artisan::output()), true);
        $this->assertSame('loop_master_off', $envelope['reason'] ?? '');
        $this->assertFileDoesNotExist($envelope['snapshot_path']);
    }

    public function test_routes_console_php_contains_scheduled_cadence_entry(): void
    {
        $routes = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('atlas:loop:cortex:cadence', $routes);
        $this->assertStringContainsString("dailyAt('03:00')", $routes);
        $this->assertStringContainsString('withoutOverlapping()', $routes);
        $this->assertStringContainsString('cortex-comprehension-build.log', $routes);
        $this->assertStringContainsString('AtlasLoopMasterSwitch::enabled()', $routes);
    }
}
