<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRivalReplayCommandTest extends TestCase
{
    public function test_replay_inspect_command_emits_harness_contract(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-missing-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'inspect',
            '--evidence' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_harness.v1', $output);
        $this->assertStringContainsString('external_rival_replay_artifacts_required_for_world_best_claim', $output);
    }

    public function test_replay_template_command_writes_pending_manifests(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_template.v1', $output);
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json'));
    }
}
