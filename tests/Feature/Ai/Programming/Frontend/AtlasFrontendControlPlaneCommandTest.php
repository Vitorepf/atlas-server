<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
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
}
