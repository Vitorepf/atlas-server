<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasCliHelpCommandTest extends TestCase
{
    public function test_cli_help_outputs_command_map_as_json(): void
    {
        $exitCode = Artisan::call('atlas:cli:help', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"Atlas CLI"', $output);
        $this->assertStringContainsString('atlas bootstrap', $output);
        $this->assertStringContainsString('atlas bootstrap --provider-projection=status', $output);
        $this->assertStringContainsString('atlas dev', $output);
        $this->assertStringContainsString('atlas doctor', $output);
        $this->assertStringContainsString('atlas install', $output);
        $this->assertStringContainsString('atlas quality', $output);
        $this->assertStringContainsString('atlas benchmark', $output);
        $this->assertStringContainsString('atlas benchmark seed', $output);
        $this->assertStringContainsString('atlas benchmark calibrate', $output);
        $this->assertStringContainsString('atlas benchmark cleanup', $output);
        $this->assertStringContainsString('atlas engineering run', $output);
        $this->assertStringContainsString('--quality-scan=auto', $output);
        $this->assertStringContainsString('atlas engineering replay', $output);
        $this->assertStringContainsString('atlas memory projection', $output);
        $this->assertStringContainsString('atlas engineering harnessability calibrate', $output);
        $this->assertStringContainsString('atlas engineering quality-scan', $output);
        $this->assertStringContainsString('atlas tools authority --json', $output);
        $this->assertStringContainsString('--sandbox-mode=worktree', $output);
        $this->assertStringContainsString('--tool-env=KEY=VALUE', $output);
        $this->assertStringContainsString('--requires-provider-safe', $output);
        $this->assertStringContainsString('atlas engineering visual-smoke', $output);
        $this->assertStringContainsString('atlas engineering visual-driver install', $output);
        $this->assertStringContainsString('atlas engineering visual-baseline', $output);
        $this->assertStringContainsString('atlas dogfood', $output);
        $this->assertStringContainsString('atlas release', $output);
    }
}
