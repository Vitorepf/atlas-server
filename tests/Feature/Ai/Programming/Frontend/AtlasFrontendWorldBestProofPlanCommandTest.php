<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendWorldBestProofPlanCommandTest extends TestCase
{
    public function test_world_best_plan_command_is_legacy_alias_for_private_benchmark_plan(): void
    {
        $exitCode = Artisan::call('atlas:frontend:world-best-plan', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendPrivateBenchmarkProofPlanService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('legacy_compatibility_only_not_operator_default', $output);
        $this->assertStringContainsString('canonical_command', $output);
        $this->assertStringContainsString('atlas:frontend:private-benchmark-plan', $output);
        $this->assertStringContainsString('private_competitive_benchmark_proof_and_improvement_loop', $output);
        $this->assertStringContainsString('private_benchmark_for_internal_improvement_only', $output);
        $this->assertStringContainsString('public_superiority_claims_disabled', $output);
        $this->assertStringContainsString('external_rival_replay', $output);
        $this->assertStringContainsString('world_best_claim_allowed', $output);
        $this->assertStringContainsString('operator_packet_verification', $output);
        $this->assertStringContainsString('evidence_pack_readiness', $output);
    }
}
