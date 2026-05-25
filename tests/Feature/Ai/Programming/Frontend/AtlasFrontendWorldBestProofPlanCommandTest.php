<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendWorldBestProofPlanService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendWorldBestProofPlanCommandTest extends TestCase
{
    public function test_world_best_plan_command_emits_workstreams_and_fails_strict_until_proven(): void
    {
        $exitCode = Artisan::call('atlas:frontend:world-best-plan', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('external_rival_replay', $output);
        $this->assertStringContainsString('world_best_claim_allowed', $output);
        $this->assertStringContainsString('generate_rival_replay_runner_kit', $output);
        $this->assertStringContainsString('runner-kit', $output);
        $this->assertStringContainsString('complete_external_rival_replay_manifests', $output);
        $this->assertStringContainsString('evidence_pack_readiness', $output);
        $this->assertStringContainsString('fill_and_verify_rival_replay_evidence_packs', $output);
    }
}
