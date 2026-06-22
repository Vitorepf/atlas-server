<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopSoakCommand;
use Tests\TestCase;

/**
 * REGRESSION (live soak, 2026-06-22): the soak launcher ran the campaign INLINE via `$this->call(...)`, so the
 * multi-hour supervisor was tethered to the launching shell/session. When that process was reaped (~35min) the
 * whole soak died mid-run and its in-flight grinds surfaced as `parallel_worker_timeout`. The fix: a real soak
 * is DETACHED (nohup, backgrounded), so it outlives the launching shell. These pin the detached launch shape.
 */
final class AtlasLoopSoakDetachTest extends TestCase
{
    public function test_confirm_launch_is_a_detached_nohup_background_command(): void
    {
        $cmd = (new AtlasLoopSoakCommand())->detachedCommandLine([
            '--no-shadow' => true,
            '--idle-on-starvation' => true,
            '--max-seconds' => 7200,
            '--max-usd-cents' => 500,
            '--goal' => 'self-evolution soak',
        ], '/tmp/soak-test.log');

        $this->assertStringStartsWith('nohup ', $cmd, 'a soak must be nohup-detached so it survives the shell');
        $this->assertStringEndsWith('&', trim($cmd), 'backgrounded');
        $this->assertStringContainsString('artisan', $cmd);
        $this->assertStringContainsString('atlas:loop:campaign', $cmd);
        $this->assertStringContainsString('-d memory_limit=4096M', $cmd, 'memory-bounded like every loop spawn');
        // the brake args survive the detach
        $this->assertStringContainsString('--no-shadow', $cmd);
        $this->assertStringContainsString("--max-seconds='7200'", $cmd);
        $this->assertStringContainsString("--max-usd-cents='500'", $cmd);
        // logs to the given file (not the dying shell's stdout)
        $this->assertStringContainsString('/tmp/soak-test.log', $cmd);
        $this->assertStringContainsString('>>', $cmd);
    }

    public function test_boolean_flags_carry_without_value_and_empty_dropped(): void
    {
        $cmd = (new AtlasLoopSoakCommand())->detachedCommandLine([
            '--no-shadow' => true,
            '--goal' => '',          // empty → dropped
            '--max-tasks' => false,  // false → dropped
        ], '/tmp/x.log');

        $this->assertStringContainsString('--no-shadow', $cmd);
        $this->assertStringNotContainsString('--goal', $cmd);
        $this->assertStringNotContainsString('--max-tasks', $cmd);
    }

    public function test_foreground_inline_path_is_opt_in_only(): void
    {
        // The ONLY inline (tethered) path is the explicit --foreground debug flag; default detaches.
        $this->assertTrue((new AtlasLoopSoakCommand())->getDefinition()->hasOption('foreground'));
    }
}
