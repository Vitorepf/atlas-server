<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendPrivateBenchmarkProofPlanService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendPrivateBenchmarkProofPlanCommandTest extends TestCase
{
    public function test_private_benchmark_plan_command_emits_private_contract_and_fails_strict_until_ready(): void
    {
        $exitCode = Artisan::call('atlas:frontend:private-benchmark-plan', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendPrivateBenchmarkProofPlanService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('private_competitive_benchmark_proof_and_improvement_loop', $output);
        $this->assertStringContainsString('private_benchmark_for_internal_improvement_only', $output);
        $this->assertStringContainsString('public_superiority_claims_disabled', $output);
        $this->assertStringContainsString('world_best_claim_allowed', $output);
        $this->assertStringContainsString('private_improvement_queue', $output);
    }
}
