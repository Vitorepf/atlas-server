<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Tests\TestCase;

class AtlasFrontendAntiSlopDetectCommandTest extends TestCase
{
    public function test_detect_command_passes_clean_frontend_file(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-detect-clean-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/clean.html', '<main><h1>Invoice approvals</h1><button aria-label="Save invoice">Save</button></main>');

        $this->artisan('atlas:frontend:detect', [
            '--path' => $dir,
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_detect_command_fails_strict_on_high_findings(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-detect-bad-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/bad.html', '<img src="placeholder.png"><div class="absolute top-0 left-0"></div>');

        $this->artisan('atlas:frontend:detect', [
            '--path' => $dir,
            '--strict' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"repair_projection"')
            ->assertExitCode(1);
    }
}
