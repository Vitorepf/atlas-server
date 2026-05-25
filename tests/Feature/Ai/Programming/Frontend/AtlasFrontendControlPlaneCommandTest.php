<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendControlPlaneCommandTest extends TestCase
{
    public function test_control_plane_command_emits_claim_policy(): void
    {
        $exitCode = Artisan::call('atlas:frontend:control-plane', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.control_plane.v1', $output);
        $this->assertStringContainsString('world_best_claim_allowed', $output);
        $this->assertStringContainsString('external_rival_replay_not_completed', $output);
    }

    public function test_control_plane_strict_fails_when_market_proof_is_not_ready(): void
    {
        $exitCode = Artisan::call('atlas:frontend:control-plane', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('world_best_claim_not_allowed', Artisan::output());
    }

    public function test_control_plane_command_accepts_frontend_app_scope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-control-plane-command-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $exitCode = Artisan::call('atlas:frontend:control-plane', [
            '--task' => 'Ajustar checkout web',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('subscope_selected', $output);
        $this->assertStringNotContainsString($workspace.'/apps/web', $output);
    }
}
