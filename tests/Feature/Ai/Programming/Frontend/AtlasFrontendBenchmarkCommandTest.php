<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendBenchmarkCommandTest extends TestCase
{
    public function test_benchmark_command_emits_competitive_matrix(): void
    {
        $exitCode = Artisan::call('atlas:frontend:benchmark', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.benchmark_runtime.v1', $output);
        $this->assertStringContainsString('atlas_more_complete_than_impeccable_on_governed_delivery_contract', $output);
        $this->assertStringContainsString('atlas_world_best_frontend_system', $output);
    }

    public function test_benchmark_command_accepts_rival_evidence_directory(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-benchmark-command-evidence-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        $exitCode = Artisan::call('atlas:frontend:benchmark', [
            '--rival-evidence' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('rival_evidence_directory_supplied', $output);
        $this->assertStringContainsString(hash('sha256', $dir), $output);
        $this->assertStringContainsString('ready_for_replay', $output);
    }
}
