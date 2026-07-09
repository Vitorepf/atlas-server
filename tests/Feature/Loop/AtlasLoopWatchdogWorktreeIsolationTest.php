<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Tests\TestCase;

/**
 * Obra 1: ACDE campaign watchdogs are retired stubs. They must not hardcode a checkout
 * path and must point operators at Autônomos (brain/task/agents).
 */
final class AtlasLoopWatchdogWorktreeIsolationTest extends TestCase
{
    private function script(): string
    {
        $path = base_path('bin/atlas-loop-watchdog.sh');
        $this->assertFileExists($path, 'watchdog script must exist');

        return (string) file_get_contents($path);
    }

    public function test_watchdog_is_retired_stub_without_hardcoded_checkout(): void
    {
        $src = $this->script();

        $this->assertStringContainsString('LEGACY STUB', $src);
        $this->assertStringContainsString('atlas:agents:on', $src);
        $this->assertSame(
            0,
            preg_match('~^\s*cd\s+/Users/[^\n]*?/(atlas-server|atlas-loop-run|atlas-[a-z0-9-]+)\b~m', $src),
            'retired stub must not hardcode a checkout path',
        );
        $this->assertStringNotContainsString('atlas:loop:campaign', $src);
    }

    public function test_supervised_and_babysit_stubs_are_retired(): void
    {
        foreach (['atlas-loop-watchdog-supervised.sh', 'atlas-loop-babysit-watchdog.sh', 'atlas-loop-report-recorder.sh'] as $name) {
            $path = base_path('bin/'.$name);
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('LEGACY STUB', $src, $name);
            $this->assertStringNotContainsString('atlas:loop:campaign', $src, $name);
        }
    }
}
